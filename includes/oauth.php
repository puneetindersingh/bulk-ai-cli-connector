<?php
/**
 * One-click sign-in (OAuth 2.1) for the MCP server, so claude.ai, the Claude
 * desktop app, ChatGPT and other apps that add a "custom connector" can connect
 * with nothing but the MCP address. The app sends the administrator here, the
 * administrator clicks Allow, and the app gets a token for the MCP endpoint.
 *
 * What it is:
 *   - Authorization code flow with PKCE (S256 only), public clients only.
 *   - Dynamic client registration (RFC 7591), discovery documents (RFC 8414,
 *     RFC 9728, and the OpenID form some apps ask for), resource indicators
 *     (RFC 8707) and the iss parameter on the redirect (RFC 9207).
 *
 * How it is kept safe:
 *   - A token signs in on the MCP endpoint and nowhere else, never on another
 *     REST route, wp-admin or the login screen. Through the MCP endpoint it can
 *     only run this plugin's own tools, as the account that approved it.
 *   - Only an account that can manage the site approves a connection (filter:
 *     bulkseme_oauth_can_approve), on a page that cannot be framed and that
 *     names the app by the address it returns to, not by the name it gave.
 *   - An app may only return to the exact callback address of Claude or
 *     ChatGPT, Cursor's own callback, or this computer (localhost). Filter:
 *     bulkseme_oauth_redirect_uris. Registration is open to anyone, as the
 *     standard requires, so without this a stranger could register an app
 *     that sends the approval to their server. Registration stores nothing:
 *     the client_id is signed (bulkseme_oauth_client_id).
 *   - The approval page needs a browser signed in with its login cookie, not
 *     an application password or another API sign-in.
 *   - Access tokens, refresh tokens and codes are random and stored only as
 *     SHA-256 hashes. Refresh tokens also carry their generation number and a
 *     short signature, so a genuine earlier one that comes back is recognised
 *     and ends the connection (a made-up one only gets an error). Access tokens last one hour, refresh tokens 30 days and
 *     change on every use, codes two minutes and work once. A password change
 *     ends every connection of that account.
 *   - Every connection is listed under Settings > AI CLI Connector with a
 *     Disconnect button, and the whole feature can be switched off there.
 *   - Needs HTTPS (or a local development site), like the API keys.
 */

if (!defined('ABSPATH')) exit;

/** Lifetimes, in seconds. */
const BULKSEME_OAUTH_ACCESS_TTL  = 3600;
const BULKSEME_OAUTH_REFRESH_TTL = 2592000;
const BULKSEME_OAUTH_CODE_TTL    = 120;

/** Most approved connections a site keeps. Only an administrator's Allow adds one. */
const BULKSEME_OAUTH_MAX_GRANTS  = 100;

/** Is one-click sign-in on? It is unless an administrator switched it off. */
function bulkseme_oauth_enabled() {
    $on = !get_option('bulkseme_oauth_off');
    return (bool) apply_filters('bulkseme_oauth_enabled', $on) && bulkseme_connection_is_secure();
}

/** May this user approve a connection? By default: users who can manage the site. */
function bulkseme_oauth_can_approve($user) {
    if (!($user instanceof WP_User) || !$user->exists()) {
        return false;
    }
    return (bool) apply_filters('bulkseme_oauth_can_approve', user_can($user, 'manage_options'), $user);
}

/* ------------------------------------------------------------------ */
/* Addresses                                                           */
/* ------------------------------------------------------------------ */

/** scheme://host[:port] of a URL, or ''. */
function bulkseme_oauth_origin($url) {
    $p = wp_parse_url((string) $url);
    if (!is_array($p) || empty($p['scheme']) || empty($p['host'])) {
        return '';
    }
    return strtolower($p['scheme']) . '://' . strtolower($p['host']) . (isset($p['port']) ? ':' . (int) $p['port'] : '');
}

/** The path of a URL without a trailing slash ('' for the root). */
function bulkseme_oauth_path($url) {
    $path = wp_parse_url((string) $url, PHP_URL_PATH);
    return is_string($path) ? untrailingslashit($path) : '';
}

/** Does the site use pretty permalinks, so that a REST address has no query string? */
function bulkseme_oauth_rest_is_path() {
    return strpos(rest_url('seo-meta-bridge/v1/oauth'), '?') === false;
}

/**
 * The issuer: the sign-in server's own name, which apps turn into the address
 * of its metadata. Under the REST API where possible, because a host that
 * blocks /.well-known/ at the domain root still lets REST addresses through.
 * With plain permalinks it is the site's home address.
 */
function bulkseme_oauth_issuer() {
    if (bulkseme_oauth_rest_is_path()) {
        return untrailingslashit(rest_url('seo-meta-bridge/v1/oauth'));
    }
    // Never the bare origin: the root /.well-known/ documents may belong to another plugin.
    return bulkseme_oauth_origin(home_url()) . bulkseme_oauth_path(home_url()) . '/bulkseme-oauth';
}

/** Where the administrator approves an app. */
function bulkseme_oauth_authorize_url() {
    if (get_option('permalink_structure')) {
        return home_url('/bulkseme-oauth/authorize');
    }
    return add_query_arg('bulkseme_oauth', 'authorize', home_url('/'));
}

/** Where the 401 answer of the MCP endpoint points apps to. */
function bulkseme_oauth_prm_url() {
    return rest_url('seo-meta-bridge/v1/oauth/protected-resource');
}

/** Does a resource indicator name this site's MCP endpoint? A trailing slash is tolerated. */
function bulkseme_oauth_resource_matches($resource) {
    return is_string($resource) && $resource !== ''
        && untrailingslashit($resource) === untrailingslashit(bulkseme_mcp_url());
}

/** RFC 9728: the MCP endpoint and who signs apps in for it. */
function bulkseme_oauth_prm_document() {
    return [
        'resource'                 => bulkseme_mcp_url(),
        'authorization_servers'    => [bulkseme_oauth_issuer()],
        'bearer_methods_supported' => ['header'],
        'scopes_supported'         => ['mcp'],
        'resource_name'            => 'Bulk AI CLI Connector',
    ];
}

/** RFC 8414 metadata, with the fields OpenID discovery parsers insist on (there are no ID tokens). */
function bulkseme_oauth_as_document() {
    return [
        'issuer'                                         => bulkseme_oauth_issuer(),
        'authorization_endpoint'                         => bulkseme_oauth_authorize_url(),
        'token_endpoint'                                 => rest_url('seo-meta-bridge/v1/oauth/token'),
        'registration_endpoint'                          => rest_url('seo-meta-bridge/v1/oauth/register'),
        'response_types_supported'                       => ['code'],
        'grant_types_supported'                          => ['authorization_code', 'refresh_token'],
        'code_challenge_methods_supported'               => ['S256'],
        'token_endpoint_auth_methods_supported'          => ['none'],
        'scopes_supported'                               => ['mcp'],
        'authorization_response_iss_parameter_supported' => true,
        'jwks_uri'                                       => rest_url('seo-meta-bridge/v1/oauth/jwks'),
        'subject_types_supported'                        => ['public'],
        'id_token_signing_alg_values_supported'          => ['RS256'],
    ];
}

/* ------------------------------------------------------------------ */
/* Apps (clients) and where they may send the browser back to          */
/* ------------------------------------------------------------------ */

/**
 * The web addresses an app may return to, exactly as written, with the name the
 * site shows for each. Exact strings only: a host-wide rule would trust every
 * page and redirector on that vendor's domains. Desktop apps' own callbacks are
 * here too. Filter: bulkseme_oauth_redirect_uris (address => name).
 */
function bulkseme_oauth_known_redirects() {
    $known = apply_filters('bulkseme_oauth_redirect_uris', [
        'https://claude.ai/api/mcp/auth_callback'               => 'Claude',
        'https://claude.com/api/mcp/auth_callback'              => 'Claude',
        'https://chatgpt.com/connector_platform_oauth_redirect' => 'ChatGPT',
        'cursor://anysphere.cursor-mcp/oauth/callback'          => 'Cursor',
    ]);
    $out = [];
    foreach ((array) $known as $uri => $label) {
        if (is_string($uri) && $uri !== '' && strlen($uri) <= 256 && !preg_match('/[\x00-\x20"\'<>\\\\`#]/', $uri)) {
            $out[$uri] = is_string($label) && $label !== '' ? $label : (string) wp_parse_url($uri, PHP_URL_HOST);
        }
    }
    return $out;
}

/** Is the host this computer? */
function bulkseme_oauth_is_loopback($host) {
    return in_array(strtolower(trim((string) $host, '[]')), ['localhost', '127.0.0.1', '::1'], true);
}

/**
 * May an app return to this address? One of the known addresses exactly, or
 * http(s) to this computer (desktop and terminal apps listen there). No
 * fragment, no user name or password, no spaces, quotes or backslashes.
 */
function bulkseme_oauth_redirect_allowed($uri) {
    if (!is_string($uri) || $uri === '' || strlen($uri) > 256) {
        return false;
    }
    if (isset(bulkseme_oauth_known_redirects()[$uri])) {
        return true;
    }
    if (preg_match('/[\x00-\x20"\'<>\\\\`%@]/', $uri)) {
        return false;
    }
    // The address as a browser reads it: http(s)://, then exactly localhost, 127.0.0.1 or [::1], an
    // optional numeric port, then a path or query or nothing.
    if (!preg_match('#^https?://(localhost|127\.0\.0\.1|\[::1\])(:[0-9]{1,5})?([/?][^\#]*)?$#Di', $uri)) {
        return false;
    }
    $p = wp_parse_url($uri);
    if (!is_array($p) || empty($p['scheme']) || empty($p['host']) || isset($p['fragment']) || isset($p['user']) || isset($p['pass'])) {
        return false;
    }
    return bulkseme_oauth_is_loopback($p['host']) && in_array(strtolower($p['scheme']), ['http', 'https'], true);
}

/**
 * Does the redirect address the app sent match one it registered? It must be
 * allowed in its own right, then match exactly or, for this computer, differ
 * only in the port (RFC 8252 7.3: desktop apps pick a port each time).
 */
function bulkseme_oauth_redirect_matches($uri, array $registered) {
    if (!bulkseme_oauth_redirect_allowed($uri)) {
        return false;
    }
    if (in_array($uri, $registered, true)) {
        return true;
    }
    $p = wp_parse_url($uri);
    if (!is_array($p) || empty($p['host']) || !bulkseme_oauth_is_loopback($p['host'])) {
        return false;
    }
    $strip = function ($q) {
        return strtolower(isset($q['scheme']) ? $q['scheme'] : '') . '://' . strtolower(trim(isset($q['host']) ? $q['host'] : '', '[]'))
            . (isset($q['path']) ? $q['path'] : '/') . (isset($q['query']) ? '?' . $q['query'] : '');
    };
    $want = $strip($p);
    foreach ($registered as $r) {
        $rp = is_string($r) ? wp_parse_url($r) : null;
        if (is_array($rp) && !empty($rp['host']) && bulkseme_oauth_is_loopback($rp['host']) && $strip($rp) === $want) {
            return true;
        }
    }
    return false;
}

/**
 * The app's name as this site shows it. Taken from where it returns to, which
 * is checked, never from the name it gave, which anyone can make up.
 */
function bulkseme_oauth_app_label($redirect_uri) {
    $known = bulkseme_oauth_known_redirects();
    if (is_string($redirect_uri) && isset($known[$redirect_uri])) {
        return $known[$redirect_uri];
    }
    return 'An app on your computer';
}

/** base64url without padding (RFC 4648 5), as PKCE and the client ids use it. */
function bulkseme_oauth_b64url($bin) {
    return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
}

/**
 * The secret that signs app registrations. Derived from this site's salts and
 * address, never stored, so a registration made elsewhere is not valid here.
 */
function bulkseme_oauth_client_key() {
    // The home address, not the issuer: changing permalinks must not make every app register again.
    return hash_hmac('sha256', 'bulkseme-oauth-client|' . home_url(), wp_salt('auth'));
}

/**
 * An app's registration is not stored: its client_id carries its name, its
 * return addresses and when it registered, signed with the site's secret.
 * Registration is open to anyone, so this way it writes nothing and nobody can
 * fill the database or crowd out the apps people really use.
 */
function bulkseme_oauth_client_id($name, array $uris, $time) {
    $payload = bulkseme_oauth_b64url((string) wp_json_encode(['n' => (string) $name, 'r' => array_values($uris), 't' => (int) $time], JSON_UNESCAPED_SLASHES));
    return 'bsmc_' . substr(hash_hmac('sha256', $payload, bulkseme_oauth_client_key()), 0, 32) . '_' . $payload;
}

/**
 * The app a client_id names, or null: ['name', 'redirect_uris', 'created'].
 * The signature must match, and each return address must still be allowed.
 */
function bulkseme_oauth_client($client_id) {
    if (!is_string($client_id) || strlen($client_id) > 1600 || !preg_match('/^bsmc_([0-9a-f]{32})_([A-Za-z0-9_-]{1,1560})$/D', $client_id, $m)) {
        return null;
    }
    if (!hash_equals(substr(hash_hmac('sha256', $m[2], bulkseme_oauth_client_key()), 0, 32), $m[1])) {
        return null;
    }
    $json = base64_decode(strtr($m[2], '-_', '+/'), true);
    $data = is_string($json) ? json_decode($json, true) : null;
    if (!is_array($data) || !isset($data['r']) || !is_array($data['r'])) {
        return null;
    }
    $uris = [];
    foreach ($data['r'] as $u) {
        if (bulkseme_oauth_redirect_allowed($u)) {
            $uris[] = $u;
        }
    }
    if (!$uris) {
        return null;
    }
    return [
        'name'          => isset($data['n']) && is_string($data['n']) ? $data['n'] : '',
        'redirect_uris' => $uris,
        'created'       => isset($data['t']) ? (int) $data['t'] : 0,
    ];
}

/* ------------------------------------------------------------------ */
/* Connections (grants) and their tokens                               */
/* ------------------------------------------------------------------ */

/** Is this a connection id: 12 hex characters? */
function bulkseme_oauth_is_grant_id($id) {
    return is_string($id) && (bool) preg_match('/^[0-9a-f]{12}$/D', $id);
}

/**
 * The index of approved connections, id => time created. Each connection's
 * data is its own option row (bulkseme_oauth_g_<id>), so writes to one never
 * overwrite another. A connection counts only while it is in this index, has
 * its row and has no end marker: if a race ever loses one of the three, the
 * connection stops working rather than working unseen.
 */
function bulkseme_oauth_grant_index() {
    $g = get_option('bulkseme_oauth_grants', []);
    return is_array($g) ? $g : [];
}

/**
 * One connection's data, or null. Only ids in the index are looked up, so a
 * stranger sending made-up ids makes the site look up nothing.
 */
function bulkseme_oauth_grant($id) {
    if (!bulkseme_oauth_is_grant_id($id) || !isset(bulkseme_oauth_grant_index()[$id])) {
        return null;
    }
    $g = get_option('bulkseme_oauth_g_' . $id, null);
    return is_array($g) ? $g : null;
}

/** Every listed connection's data, id => data. For the settings screen and clean-ups. */
function bulkseme_oauth_grants() {
    $out = [];
    foreach (array_keys(bulkseme_oauth_grant_index()) as $id) {
        $g = bulkseme_oauth_grant((string) $id);
        if ($g !== null) {
            $out[(string) $id] = $g;
        }
    }
    return $out;
}

/**
 * Has this connection been ended? Its own option row, read from the database
 * itself: through the options cache, a persistent object cache could hold a
 * stale "no such option" for the marker and let an ended connection work.
 *
 * @phpstan-impure The answer can change between two calls in one request.
 */
function bulkseme_oauth_is_revoked($id) {
    if (!bulkseme_oauth_is_grant_id($id)) {
        return false;
    }
    global $wpdb;
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- must bypass the options cache, see above.
    return null !== $wpdb->get_var($wpdb->prepare("SELECT option_id FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", 'bulkseme_oauth_rv_' . $id));
}

/**
 * Replace one connection's data only if it is still exactly what this request
 * read: two refreshes of the same token at once cannot both succeed. Returns
 * true when this request's write won.
 */
function bulkseme_oauth_swap_grant($id, $old_raw, array $new) {
    global $wpdb;
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- a compare-and-swap; WordPress has no option API for one. The cache is cleared just below.
    $n = $wpdb->query($wpdb->prepare("UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s", maybe_serialize($new), 'bulkseme_oauth_g_' . $id, $old_raw));
    wp_cache_delete('bulkseme_oauth_g_' . $id, 'options');
    return $n === 1;
}

/** One connection's stored value straight from the database: [raw string, data array] or null. */
function bulkseme_oauth_grant_raw($id) {
    if (!bulkseme_oauth_is_grant_id($id) || !isset(bulkseme_oauth_grant_index()[$id])) {
        return null;
    }
    global $wpdb;
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the exact stored value, for bulkseme_oauth_swap_grant.
    $raw  = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", 'bulkseme_oauth_g_' . $id));
    $data = is_string($raw) ? maybe_unserialize($raw) : null;
    return is_array($data) ? [$raw, $data] : null;
}

/** Ended connection ids, id => time: only so uninstall.php can remove their markers. */
function bulkseme_oauth_revoked() {
    $r = get_option('bulkseme_oauth_revoked', []);
    return is_array($r) ? $r : [];
}

/** When each connection last used the site, id => time. Kept apart from the connection's data. */
function bulkseme_oauth_last_used() {
    $u = get_option('bulkseme_oauth_used', []);
    return is_array($u) ? $u : [];
}

function bulkseme_oauth_hash($secret) {
    return hash('sha256', (string) $secret);
}

/** Save one connection's data, and list it in the index if it is new. True when it reads back. */
function bulkseme_oauth_save_grant($id, array $grant) {
    if (!bulkseme_oauth_is_grant_id($id) || bulkseme_oauth_is_revoked($id)) {
        return false;
    }
    update_option('bulkseme_oauth_g_' . $id, $grant, false);
    if (bulkseme_oauth_is_revoked($id)) {
        // Ended while this request was writing: take the row back out.
        delete_option('bulkseme_oauth_g_' . $id);
        return false;
    }
    wp_cache_delete('bulkseme_oauth_grants', 'options');
    $index = bulkseme_oauth_grant_index();
    if (!isset($index[$id])) {
        $index[$id] = isset($grant['created']) ? (int) $grant['created'] : time();
        update_option('bulkseme_oauth_grants', $index, false);
    }
    // A failed write (a locked database, a lost race on the index) must not hand out dead tokens.
    wp_cache_delete('bulkseme_oauth_grants', 'options');
    wp_cache_delete('bulkseme_oauth_g_' . $id, 'options');
    $back = bulkseme_oauth_grant($id);
    return is_array($back) && isset($back['access']) && $back['access'] === $grant['access'];
}

/** Remove connections whose refresh token has expired. They can never sign in again. */
function bulkseme_oauth_prune() {
    wp_cache_delete('bulkseme_oauth_grants', 'options');
    $index = bulkseme_oauth_grant_index();
    $keep  = [];
    foreach ($index as $id => $t) {
        $g = bulkseme_oauth_grant((string) $id);
        if ($g !== null && (int) (isset($g['refresh_exp']) ? $g['refresh_exp'] : 0) >= time()) {
            $keep[$id] = $t;
        } else {
            delete_option('bulkseme_oauth_g_' . $id);
        }
    }
    if (count($keep) !== count($index)) {
        update_option('bulkseme_oauth_grants', $keep, false);
    }
    // End markers older than the longest a refresh token lives are no longer needed: any row they
    // guard has expired by then.
    $revoked = bulkseme_oauth_revoked();
    $left    = [];
    foreach ($revoked as $rid => $t) {
        if ((int) $t < time() - BULKSEME_OAUTH_REFRESH_TTL - DAY_IN_SECONDS && bulkseme_oauth_is_grant_id((string) $rid)) {
            delete_option('bulkseme_oauth_rv_' . $rid);
            delete_option('bulkseme_oauth_g_' . $rid);
        } else {
            $left[$rid] = $t;
        }
    }
    if (count($left) !== count($revoked)) {
        update_option('bulkseme_oauth_revoked', $left, false);
    }
    return $keep;
}

/** End a connection. Its tokens stop working at once. */
function bulkseme_oauth_revoke($id) {
    if (!bulkseme_oauth_is_grant_id($id)) {
        return false;
    }
    $existed = isset(bulkseme_oauth_grant_index()[$id]);
    // The end marker first: from here on nothing signs in with this connection, whatever else happens.
    update_option('bulkseme_oauth_rv_' . $id, time(), false);
    delete_option('bulkseme_oauth_g_' . $id);
    wp_cache_delete('bulkseme_oauth_grants', 'options');
    $index = bulkseme_oauth_grant_index();
    unset($index[$id]);
    update_option('bulkseme_oauth_grants', $index, false);
    wp_cache_delete('bulkseme_oauth_revoked', 'options');
    $revoked      = bulkseme_oauth_revoked();
    $revoked[$id] = time();
    update_option('bulkseme_oauth_revoked', $revoked, false);
    $used = bulkseme_oauth_last_used();
    if (isset($used[$id])) {
        unset($used[$id]);
        update_option('bulkseme_oauth_used', $used, false);
    }
    return $existed;
}

/**
 * Is the connection still the one it was made for: listed, not ended, same
 * account with the same password, and that account may still approve? Returns
 * the user or null.
 */
function bulkseme_oauth_grant_user($id, $grant) {
    if (!is_array($grant) || !bulkseme_oauth_is_grant_id($id) || bulkseme_oauth_is_revoked($id) || !isset(bulkseme_oauth_grant_index()[$id])) {
        return null;
    }
    $user = empty($grant['user_id']) ? false : get_userdata((int) $grant['user_id']);
    if (!$user || !isset($grant['user_login'], $grant['user_registered'])
        || $grant['user_login'] !== $user->user_login || $grant['user_registered'] !== $user->user_registered
        || !isset($grant['pw']) || !is_string($grant['pw']) || !hash_equals(bulkseme_oauth_pw_mark($user), $grant['pw'])
        || !bulkseme_oauth_can_approve($user)) {
        return null;
    }
    return $user;
}

/**
 * A mark of the account's password hash: when the password changes, the
 * account's connections end. WordPress also rewrites the hash when it upgrades
 * the hashing (at the next login after a PHP or WordPress change); that ends
 * them too, which is the safe direction.
 */
function bulkseme_oauth_pw_mark(WP_User $user) {
    return substr(hash_hmac('sha256', (string) $user->user_pass, wp_salt('auth')), 0, 16);
}

/**
 * New access and refresh tokens for a connection. Returns [grant, response body].
 * Both are random and stored only as SHA-256 hashes, so a copy of the database,
 * even with the site's salts, cannot be turned into a working token. The
 * refresh token carries its generation number: an earlier generation that
 * comes back is recognised from the number alone.
 */
function bulkseme_oauth_mint(array $grant, $id) {
    $gen     = (isset($grant['gen']) ? (int) $grant['gen'] : 0) + 1;
    $access  = 'bsmo_' . $id . '_' . bin2hex(random_bytes(24));
    $random  = bin2hex(random_bytes(24));
    $refresh = 'bsmr_' . $id . '_' . sprintf('%08x', $gen) . '_' . $random . '_' . bulkseme_oauth_refresh_mac($id, $gen, $random);
    $now     = time();
    unset($grant['prev'], $grant['retried']);
    // The access token this replaces stays good for two more minutes if it has not run out yet: an
    // app that refreshes again a moment after another of its requests did must not cut that
    // request's fresh token off. An expired one (the usual reason to refresh) stays expired.
    $grant['access_prev']     = isset($grant['access']) && is_string($grant['access']) ? $grant['access'] : '';
    $grant['access_prev_exp'] = min((int) (isset($grant['access_exp']) ? $grant['access_exp'] : 0), $now + 120);
    $grant['gen']         = $gen;
    $grant['rotated']     = $now;
    $grant['access']      = bulkseme_oauth_hash(substr($access, 18));
    $grant['access_exp']  = $now + BULKSEME_OAUTH_ACCESS_TTL;
    $grant['refresh']     = bulkseme_oauth_hash($refresh);
    $grant['refresh_exp'] = $now + BULKSEME_OAUTH_REFRESH_TTL;
    return [$grant, [
        'access_token'  => $access,
        'token_type'    => 'Bearer',
        'expires_in'    => BULKSEME_OAUTH_ACCESS_TTL,
        'refresh_token' => $refresh,
        'scope'         => 'mcp',
    ]];
}

/**
 * A short signature over a refresh token's id, generation and random part,
 * with the site's salt. It does not sign anyone in (the stored hash does);
 * it only proves the site issued the token, so a made-up "earlier generation"
 * cannot end someone else's connection.
 */
function bulkseme_oauth_refresh_mac($id, $gen, $random) {
    return substr(hash_hmac('sha256', $id . '|' . (int) $gen . '|' . $random, wp_salt('auth')), 0, 16);
}

/** Split a refresh token into [connection id, generation, issued by this site], or null. */
function bulkseme_oauth_split_refresh($token) {
    if (!is_string($token) || !preg_match('/^bsmr_([0-9a-f]{12})_([0-9a-f]{8})_([0-9a-f]{48})_([0-9a-f]{16})$/D', $token, $m)) {
        return null;
    }
    $gen = (int) hexdec($m[2]);
    return [$m[1], $gen, hash_equals(bulkseme_oauth_refresh_mac($m[1], $gen, $m[3]), $m[4])];
}

/** Split an access token into [connection id, secret], or null. */
function bulkseme_oauth_split($token, $prefix) {
    if (!is_string($token) || !preg_match('/^' . $prefix . '_([0-9a-f]{12})_([0-9a-f]{48})$/D', $token, $m)) {
        return null;
    }
    return [$m[1], $m[2]];
}

/* ------------------------------------------------------------------ */
/* Signing in on the MCP endpoint                                       */
/* ------------------------------------------------------------------ */

/** The access token this request carries in Authorization: Bearer, or ''. */
function bulkseme_oauth_request_token() {
    foreach (['HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION'] as $name) {
        if (empty($_SERVER[$name]) || !is_string($_SERVER[$name])) {
            continue;
        }
        // Only a value with the exact shape of an access token is used; it is hashed and compared, never stored or printed.
        $value = trim(sanitize_text_field(wp_unslash($_SERVER[$name])));
        if (strncasecmp($value, 'Bearer ', 7) === 0) {
            $value = trim(substr($value, 7));
            if (bulkseme_oauth_split($value, 'bsmo')) {
                return $value;
            }
        }
    }
    return '';
}

/** Is this request for the MCP endpoint, and nothing else? */
function bulkseme_oauth_request_is_mcp() {
    global $wp;
    $route = (is_object($wp) && isset($wp->query_vars['rest_route']) && is_string($wp->query_vars['rest_route']))
        ? '/' . trim($wp->query_vars['rest_route'], '/')
        : '';
    return strtolower($route) === '/seo-meta-bridge/v1/mcp';
}

/** The connection that signed this request in, or ''. */
function bulkseme_oauth_auth_grant($set = null) {
    static $id = '';
    if ($set !== null) {
        $id = (string) $set;
    }
    return $id;
}

/**
 * Sign a request to the MCP endpoint in when it carries a valid access token.
 * Runs after cookies and the API keys, never replaces a user they found, and
 * signs in on the MCP endpoint only.
 */
function bulkseme_determine_user_by_oauth($user_id) {
    if (!empty($user_id) || !(defined('REST_REQUEST') && REST_REQUEST) || !bulkseme_oauth_enabled()) {
        return $user_id;
    }
    $token = bulkseme_oauth_request_token();
    if ($token === '' || !bulkseme_oauth_request_is_mcp()) {
        return $user_id;
    }
    // Checking the account below asks WordPress about capabilities, and plugins that filter
    // capabilities often ask who the current user is, which runs this filter again. Inside that
    // inner call the answer is "nobody yet"; the outer call finishes the sign-in.
    static $running = false;
    if ($running) {
        return $user_id;
    }
    $running = true;
    try {
        $signed_in = bulkseme_oauth_sign_in($token);
    } finally {
        $running = false;
    }
    return $signed_in ? $signed_in : $user_id;
}
add_filter('determine_current_user', 'bulkseme_determine_user_by_oauth', 16);

/** The user id an access token signs in as, or 0. */
function bulkseme_oauth_sign_in($token) {
    list($id, $secret) = bulkseme_oauth_split($token, 'bsmo');
    $grant = bulkseme_oauth_grant($id);
    if (!is_array($grant)) {
        return 0;
    }
    $hash    = bulkseme_oauth_hash($secret);
    $current = !empty($grant['access']) && is_string($grant['access']) && hash_equals($grant['access'], $hash)
        && (int) (isset($grant['access_exp']) ? $grant['access_exp'] : 0) >= time();
    $before  = !empty($grant['access_prev']) && is_string($grant['access_prev']) && hash_equals($grant['access_prev'], $hash)
        && (int) (isset($grant['access_prev_exp']) ? $grant['access_prev_exp'] : 0) >= time();
    if (!$current && !$before) {
        return 0;
    }
    $user = bulkseme_oauth_grant_user($id, $grant);
    if (!$user) {
        return 0;
    }
    $used = bulkseme_oauth_last_used();
    if ((time() - (int) (isset($used[$id]) ? $used[$id] : 0)) > 300) {
        $used[$id] = time();
        update_option('bulkseme_oauth_used', array_intersect_key($used, bulkseme_oauth_grant_index()), false);
    }
    bulkseme_key_auth_user($user->ID);
    bulkseme_oauth_auth_grant($id);
    return (int) $user->ID;
}

/**
 * A 401 from the MCP endpoint tells the app where to sign in (RFC 9728 5.1),
 * and says the token is no longer good when it sent one.
 */
add_filter('rest_post_dispatch', function ($response, $server, $request) {
    if (!($response instanceof WP_REST_Response) || $response->get_status() !== 401 || !bulkseme_oauth_enabled()
        || strtolower(untrailingslashit($request->get_route())) !== '/seo-meta-bridge/v1/mcp') {
        return $response;
    }
    $value = 'Bearer resource_metadata="' . bulkseme_oauth_prm_url() . '"';
    if (bulkseme_oauth_request_token() !== '') {
        $value .= ', error="invalid_token"';
    }
    $response->header('WWW-Authenticate', $value);
    return $response;
}, 10, 3);

/* ------------------------------------------------------------------ */
/* REST routes: discovery, registration, tokens                         */
/* ------------------------------------------------------------------ */

/** An OAuth error answer (RFC 6749 5.2). */
function bulkseme_oauth_error($code, $description, $status = 400) {
    $r = new WP_REST_Response(['error' => $code, 'error_description' => $description], $status);
    $r->header('Cache-Control', 'no-store');
    return $r;
}

function bulkseme_oauth_json($data, $status = 200) {
    $r = new WP_REST_Response($data, $status);
    $r->header('Cache-Control', 'no-store');
    return $r;
}

/** A request parameter as a string, or '' when it is missing or not a string. */
function bulkseme_oauth_param(WP_REST_Request $req, $name) {
    // From the body only: codes and refresh tokens must not be accepted in a URL, where logs keep them.
    $body = $req->get_body_params();
    if (!isset($body[$name])) {
        $json = $req->get_json_params();
        $body = is_array($json) ? $json : [];
    }
    return isset($body[$name]) && is_string($body[$name]) ? $body[$name] : '';
}

// Open to everyone on purpose: OAuth discovery, registration and the token endpoint must answer
// apps that are not signed in yet. None of them signs anyone in or reads site data; a token comes
// only from a code an administrator approved (see the header of this file).
add_action('rest_api_init', function () {
    $off  = function () {
        return bulkseme_oauth_enabled() ? null : bulkseme_oauth_error('temporarily_unavailable', 'One-click sign-in is switched off on this site.', 404);
    };
    register_rest_route('seo-meta-bridge/v1', '/oauth/protected-resource', [
        'methods' => 'GET', 'permission_callback' => '__return_true',
        'callback' => function () use ($off) {
            return $off() ?: bulkseme_oauth_json(bulkseme_oauth_prm_document());
        },
    ]);
    // Apps that cannot load the document the 401 names look under the endpoint's own address.
    register_rest_route('seo-meta-bridge/v1', '/mcp/.well-known/oauth-protected-resource', [
        'methods' => 'GET', 'permission_callback' => '__return_true',
        'callback' => function () use ($off) {
            return $off() ?: bulkseme_oauth_json(bulkseme_oauth_prm_document());
        },
    ]);
    $as = function () use ($off) {
        return $off() ?: bulkseme_oauth_json(bulkseme_oauth_as_document());
    };
    register_rest_route('seo-meta-bridge/v1', '/oauth/.well-known/oauth-authorization-server', [
        'methods' => 'GET', 'permission_callback' => '__return_true', 'callback' => $as,
    ]);
    register_rest_route('seo-meta-bridge/v1', '/oauth/.well-known/openid-configuration', [
        'methods' => 'GET', 'permission_callback' => '__return_true', 'callback' => $as,
    ]);
    register_rest_route('seo-meta-bridge/v1', '/oauth/jwks', [
        'methods' => 'GET', 'permission_callback' => '__return_true',
        'callback' => function () use ($off) {
            return $off() ?: bulkseme_oauth_json(['keys' => []]);
        },
    ]);
    register_rest_route('seo-meta-bridge/v1', '/oauth/register', [
        'methods' => 'POST', 'permission_callback' => '__return_true',
        'callback' => function (WP_REST_Request $req) use ($off) {
            return $off() ?: bulkseme_oauth_register($req);
        },
    ]);
    register_rest_route('seo-meta-bridge/v1', '/oauth/token', [
        'methods' => 'POST', 'permission_callback' => '__return_true',
        'callback' => function (WP_REST_Request $req) use ($off) {
            return $off() ?: bulkseme_oauth_token($req);
        },
    ]);
});

/** Dynamic client registration (RFC 7591). Open to anyone, so it stores nothing: see bulkseme_oauth_client_id(). */
function bulkseme_oauth_register(WP_REST_Request $req) {
    $body = $req->get_json_params();
    if (!is_array($body)) {
        return bulkseme_oauth_error('invalid_client_metadata', 'Send the registration as a JSON object.');
    }
    $uris = isset($body['redirect_uris']) ? $body['redirect_uris'] : null;
    if (!is_array($uris) || !$uris || count($uris) > 10) {
        return bulkseme_oauth_error('invalid_redirect_uri', 'redirect_uris must list one to ten addresses.');
    }
    // Keep the addresses that are allowed (some apps list several kinds and use only one), at most five, short ones only.
    $clean = [];
    foreach ($uris as $u) {
        if (bulkseme_oauth_redirect_allowed($u) && strlen($u) <= 256 && !in_array($u, $clean, true) && count($clean) < 5) {
            $clean[] = $u;
        }
    }
    if (!$clean) {
        return bulkseme_oauth_error('invalid_redirect_uri', 'None of the redirect_uris is allowed on this site. Allowed: the callback addresses of Claude, ChatGPT and Cursor, and localhost. A site owner can add addresses with the bulkseme_oauth_redirect_uris filter.');
    }
    $name = isset($body['client_name']) && is_string($body['client_name']) ? trim(sanitize_text_field(substr($body['client_name'], 0, 400))) : '';
    $name = function_exists('mb_substr') ? mb_substr($name, 0, 80) : substr($name, 0, 80);
    $now  = time();
    $id   = bulkseme_oauth_client_id($name, $clean, $now);
    if (!bulkseme_oauth_client($id)) {
        return bulkseme_oauth_error('invalid_client_metadata', 'The registration is too long. Send fewer or shorter redirect_uris or a shorter client_name.');
    }

    // Only public clients: the reply says token_endpoint_auth_method none and the app uses PKCE alone, as MCP apps do.
    return bulkseme_oauth_json([
        'client_id'                  => $id,
        'client_id_issued_at'        => $now,
        'client_name'                => $name,
        'redirect_uris'              => $clean,
        'grant_types'                => ['authorization_code', 'refresh_token'],
        'response_types'             => ['code'],
        'token_endpoint_auth_method' => 'none',
    ], 201);
}

/** The token endpoint: a code for tokens, or a refresh token for new ones. */
function bulkseme_oauth_token(WP_REST_Request $req) {
    $grant_type = bulkseme_oauth_param($req, 'grant_type');
    $client_id  = bulkseme_oauth_param($req, 'client_id');
    $client     = bulkseme_oauth_client($client_id);
    if ($grant_type !== 'authorization_code' && $grant_type !== 'refresh_token') {
        return bulkseme_oauth_error('unsupported_grant_type', 'grant_type must be authorization_code or refresh_token.');
    }
    if (!$client) {
        // Apps read invalid_client as "register again".
        return bulkseme_oauth_error('invalid_client', 'Unknown client_id. Register again.', 401);
    }
    $resource = bulkseme_oauth_param($req, 'resource');
    if ($resource !== '' && !bulkseme_oauth_resource_matches($resource)) {
        return bulkseme_oauth_error('invalid_target', 'resource must be this site\'s MCP address.');
    }
    return $grant_type === 'authorization_code'
        ? bulkseme_oauth_token_from_code($req, $client_id, $client)
        : bulkseme_oauth_token_from_refresh($req, $client_id);
}

function bulkseme_oauth_token_from_code(WP_REST_Request $req, $client_id, array $client) {
    $code = bulkseme_oauth_param($req, 'code');
    if (!preg_match('/^[0-9a-f]{48}$/D', $code)) {
        return bulkseme_oauth_error('invalid_grant', 'The code is not valid.');
    }
    $key = 'bulkseme_oac_' . bulkseme_oauth_hash($code);
    $ctx = get_transient($key);
    // Only the request that deletes the code may use it, so two at once cannot both get tokens.
    if (!is_array($ctx) || !delete_transient($key)) {
        $made = get_transient('bulkseme_oacu_' . bulkseme_oauth_hash($code));
        if (is_string($made) && bulkseme_oauth_is_grant_id($made)) {
            bulkseme_oauth_revoke($made);
        }
        return bulkseme_oauth_error('invalid_grant', 'The code is not valid, has expired or was already used.');
    }
    foreach (['client_id', 'redirect_uri', 'challenge', 'user_login', 'user_registered'] as $field) {
        if (!isset($ctx[$field]) || !is_string($ctx[$field])) {
            return bulkseme_oauth_error('invalid_grant', 'The code is not valid.');
        }
    }
    if ((int) (isset($ctx['exp']) ? $ctx['exp'] : 0) < time() || $ctx['client_id'] !== $client_id
        || $ctx['redirect_uri'] !== bulkseme_oauth_param($req, 'redirect_uri')) {
        return bulkseme_oauth_error('invalid_grant', 'The code does not match this client and redirect_uri.');
    }
    $verifier = bulkseme_oauth_param($req, 'code_verifier');
    if (!preg_match('/^[A-Za-z0-9._~-]{43,128}$/D', $verifier)
        || !hash_equals($ctx['challenge'], bulkseme_oauth_b64url(hash('sha256', $verifier, true)))) {
        return bulkseme_oauth_error('invalid_grant', 'PKCE check failed.');
    }
    $user = get_userdata((int) (isset($ctx['user_id']) ? $ctx['user_id'] : 0));
    if (!$user || $user->user_login !== $ctx['user_login'] || $user->user_registered !== $ctx['user_registered'] || !bulkseme_oauth_can_approve($user)) {
        return bulkseme_oauth_error('invalid_grant', 'The account that approved this can no longer do so.');
    }

    $grants = bulkseme_oauth_prune();
    if (count($grants) >= BULKSEME_OAUTH_MAX_GRANTS) {
        return bulkseme_oauth_error('temporarily_unavailable', 'This site holds the most connections it can. Disconnect one under Settings > AI CLI Connector first.', 503);
    }
    do {
        $id = bin2hex(random_bytes(6));
    } while (isset($grants[$id]) || bulkseme_oauth_is_revoked($id) || bulkseme_oauth_grant($id) !== null);
    // Remember which connection this code makes before making it: if the code is ever presented
    // again, someone else has it, and the connection ends (OAuth 2.1 4.1.3).
    set_transient('bulkseme_oacu_' . bulkseme_oauth_hash($code), $id, BULKSEME_OAUTH_CODE_TTL * 5);
    list($grant, $body) = bulkseme_oauth_mint([
        'client_id'       => $client_id,
        'app'             => bulkseme_oauth_app_label($ctx['redirect_uri']),
        'client_name'     => isset($client['name']) ? (string) $client['name'] : '',
        'user_id'         => (int) $user->ID,
        'user_login'      => $user->user_login,
        'user_registered' => $user->user_registered,
        'pw'              => bulkseme_oauth_pw_mark($user),
        'created'         => time(),
    ], $id);
    if (!bulkseme_oauth_save_grant($id, $grant)) {
        return bulkseme_oauth_error('temporarily_unavailable', 'The site could not save the connection. Try connecting again.', 503);
    }
    return bulkseme_oauth_json($body);
}

/**
 * The answer to a refresh, kept for 30 seconds under the hash of the refresh
 * token that was used, and encrypted with a key made from that token: only
 * whoever holds the token can read it, and a copy of the database cannot.
 * Apps often refresh several times at once when the access token runs out
 * (one refresh per waiting request), or retry when an answer is lost; within
 * the 30 seconds they all get this same answer instead of the connection ending.
 */
function bulkseme_oauth_remember_answer($token, array $body) {
    $key   = hash('sha256', 'bulkseme-oauth-answer|' . $token, true);
    $nonce = random_bytes(24);
    $box   = sodium_crypto_secretbox((string) wp_json_encode($body), $nonce, $key);
    // Parallel requests are waiting for this; a busy database can refuse one write, so try again.
    for ($i = 0; $i < 3; $i++) {
        if (set_transient('bulkseme_oar_' . bulkseme_oauth_hash($token), bin2hex($nonce . $box), 30)) {
            return;
        }
        usleep(50000);
    }
}

/** The remembered answer for this refresh token, or null. */
function bulkseme_oauth_recall_answer($token) {
    $hex = get_transient('bulkseme_oar_' . bulkseme_oauth_hash($token));
    if (!is_string($hex) || !preg_match('/^[0-9a-f]+$/D', $hex) || strlen($hex) < 2 * 40) {
        return null;
    }
    $raw   = hex2bin($hex);
    $key   = hash('sha256', 'bulkseme-oauth-answer|' . $token, true);
    $plain = sodium_crypto_secretbox_open(substr($raw, 24), substr($raw, 0, 24), $key);
    $body  = is_string($plain) ? json_decode($plain, true) : null;
    return is_array($body) && isset($body['access_token'], $body['refresh_token']) ? $body : null;
}

/** Wait up to two seconds for another request's remembered answer to this refresh token. */
function bulkseme_oauth_await_answer($token) {
    for ($i = 0; $i < 20; $i++) {
        $same = bulkseme_oauth_recall_answer($token);
        if ($same !== null) {
            return $same;
        }
        usleep(100000);
        if (!wp_using_ext_object_cache()) {
            // Transients live in the options table here: read it again, not this request's memory
            // of "no such option". (With Redis or Memcached the transient IS the cache entry, so
            // nothing may be deleted: that would delete the answer itself.)
            $name = 'bulkseme_oar_' . bulkseme_oauth_hash($token);
            wp_cache_delete('_transient_' . $name, 'options');
            wp_cache_delete('_transient_timeout_' . $name, 'options');
            wp_cache_delete('notoptions', 'options');
        }
    }
    return null;
}

function bulkseme_oauth_token_from_refresh(WP_REST_Request $req, $client_id) {
    $token = bulkseme_oauth_param($req, 'refresh_token');
    $parts = bulkseme_oauth_split_refresh($token);
    if (!$parts || !$parts[2]) {
        return bulkseme_oauth_error('invalid_grant', 'The refresh token is not valid.');
    }
    list($id, $gen) = $parts;
    $row   = bulkseme_oauth_grant_raw($id);
    $grant = $row ? $row[1] : null;
    $user  = bulkseme_oauth_grant_user($id, $grant);
    if (!$user || !isset($grant['client_id']) || $grant['client_id'] !== $client_id) {
        return bulkseme_oauth_error('invalid_grant', 'The refresh token is not valid.');
    }
    $hash    = bulkseme_oauth_hash($token);
    $current = isset($grant['gen']) ? (int) $grant['gen'] : 0;

    if ($gen === $current - 1 && time() - (int) (isset($grant['rotated']) ? $grant['rotated'] : 0) <= 30) {
        // Replaced within the last 30 seconds and its successor not used yet: a parallel or retried
        // request. It gets the same answer as the first one (which may still be being written). If
        // that answer is missing (the first request died before saving it), refuse without ending
        // the connection: nothing is handed out, so a thief gains nothing either.
        $same = bulkseme_oauth_await_answer($token);
        if ($same !== null) {
            return bulkseme_oauth_json($same);
        }
        return bulkseme_oauth_error('temporarily_unavailable', 'A refresh with this token is still being finished. Try again in a moment.', 503);
    }
    if ($gen < $current) {
        // A genuine earlier generation came back after its minute, or after its successor was used:
        // two parties hold this connection's refresh tokens. OAuth 2.1 4.3.1: end it.
        bulkseme_oauth_revoke($id);
        return bulkseme_oauth_error('invalid_grant', 'This refresh token was already used, so the connection was ended. Connect again.');
    }
    if ($gen !== $current || empty($grant['refresh']) || !is_string($grant['refresh']) || !hash_equals($grant['refresh'], $hash)) {
        return bulkseme_oauth_error('invalid_grant', 'The refresh token is not valid.');
    }
    if ((int) (isset($grant['refresh_exp']) ? $grant['refresh_exp'] : 0) < time()) {
        return bulkseme_oauth_error('invalid_grant', 'The refresh token has expired. Connect again.');
    }
    list($new, $body) = bulkseme_oauth_mint($grant, $id);
    if (!bulkseme_oauth_swap_grant($id, $row[0], $new)) {
        // Another request rotated with this same token a moment ago: wait briefly for its answer.
        $same = bulkseme_oauth_await_answer($token);
        if ($same !== null) {
            return bulkseme_oauth_json($same);
        }
        return bulkseme_oauth_error('temporarily_unavailable', 'Another refresh with this token is still running. Try again in a moment.', 503);
    }
    bulkseme_oauth_remember_answer($token, $body);
    if (bulkseme_oauth_is_revoked($id)) {
        return bulkseme_oauth_error('invalid_grant', 'The connection was ended.');
    }
    return bulkseme_oauth_json($body);
}

/* ------------------------------------------------------------------ */
/* Discovery documents at the domain root, and the approval page        */
/* ------------------------------------------------------------------ */

/** Send a JSON document and stop. */
function bulkseme_oauth_emit($data) {
    status_header(200);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('Access-Control-Allow-Origin: *');
    echo wp_json_encode($data, JSON_UNESCAPED_SLASHES); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON document, encoded.
    exit;
}

/**
 * Answer the addresses that cannot live under the REST API: the discovery
 * documents in the places apps look for them (RFC 8414 3, RFC 9728 3 and the
 * OpenID form), and the approval page. Matched on the exact path.
 */
function bulkseme_oauth_route_request() {
    $method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper(sanitize_text_field(wp_unslash($_SERVER['REQUEST_METHOD']))) : 'GET';
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- routing only; the approval page checks its own nonce.
    $query_flag = isset($_GET['bulkseme_oauth']) && $_GET['bulkseme_oauth'] === 'authorize';
    $uri  = isset($_SERVER['REQUEST_URI']) ? esc_url_raw(wp_unslash($_SERVER['REQUEST_URI'])) : '';
    $path = is_string($uri) ? wp_parse_url($uri, PHP_URL_PATH) : '';
    $path = is_string($path) ? untrailingslashit($path) : '';
    $home = bulkseme_oauth_path(home_url());
    // The ?bulkseme_oauth=authorize form (plain permalinks) only on the site's front page, never on
    // xmlrpc.php, wp-admin or another script that signs people in by other means.
    $query_flag = $query_flag && in_array($path, [$home, $home . '/index.php'], true)
        && !(defined('XMLRPC_REQUEST') && XMLRPC_REQUEST) && !is_admin();
    if ($path === '' && !$query_flag) {
        return;
    }
    if ($query_flag || $path === $home . '/bulkseme-oauth/authorize') {
        if (in_array($method, ['GET', 'POST'], true)) {
            bulkseme_oauth_authorize_page($method);
        }
        return;
    }
    if ($method !== 'GET' || strpos($path, '/.well-known/') === false || !bulkseme_oauth_enabled()) {
        return;
    }
    $issuer = bulkseme_oauth_path(bulkseme_oauth_issuer());
    $as     = [
        '/.well-known/oauth-authorization-server' . $issuer,
        '/.well-known/openid-configuration' . $issuer,
        $issuer . '/.well-known/openid-configuration',
        $issuer . '/.well-known/oauth-authorization-server',
    ];
    // Only the forms that carry this site's own path: the bare root documents may belong to another plugin.
    $mcp = bulkseme_oauth_path(bulkseme_mcp_url());
    $prm = $mcp === '' ? [] : [
        '/.well-known/oauth-protected-resource' . $mcp,
        $mcp . '/.well-known/oauth-protected-resource',
    ];
    if (in_array($path, $as, true)) {
        bulkseme_oauth_emit(bulkseme_oauth_as_document());
    }
    if (in_array($path, $prm, true)) {
        bulkseme_oauth_emit(bulkseme_oauth_prm_document());
    }
}
add_action('init', 'bulkseme_oauth_route_request', 1);

/** A request value as a string: '' when missing or not a plain string. */
function bulkseme_oauth_input(array $src, $name) {
    return (isset($src[$name]) && is_string($src[$name])) ? wp_unslash($src[$name]) : '';
}

/** Send the browser back to the app with these parameters, and stop. */
function bulkseme_oauth_back($redirect_uri, array $params) {
    $params['iss'] = bulkseme_oauth_issuer();
    $url = $redirect_uri . (strpos($redirect_uri, '?') === false ? '?' : '&') . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    // wp_redirect, not wp_safe_redirect: the app is on another host, and this address was checked against the app's registration.
    wp_redirect($url, 302, 'Bulk AI CLI Connector'); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- checked against the registered redirect_uri above.
    exit;
}

/** A page that says something went wrong, without sending the browser anywhere. */
function bulkseme_oauth_stop($message) {
    bulkseme_oauth_page_open(__('Could not connect', 'bulk-seo-meta-editor-for-ai-agents'), 400);
    echo '<h1>' . esc_html__('Could not connect', 'bulk-seo-meta-editor-for-ai-agents') . '</h1><p>' . esc_html($message) . '</p>';
    bulkseme_oauth_page_close();
    exit;
}

function bulkseme_oauth_page_open($title, $status = 200) {
    status_header((int) $status);
    nocache_headers();
    header('Content-Type: text/html; charset=utf-8');
    // This page hands out access on one click, so it can never be shown inside another site's frame.
    header('X-Frame-Options: DENY');
    header("Content-Security-Policy: frame-ancestors 'none'");
    header('Referrer-Policy: no-referrer');
    echo '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex">';
    echo '<title>' . esc_html($title) . '</title><style>'
        . 'body{font:15px/1.5 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;background:#f0f0f1;color:#1d2327;margin:0;padding:24px 16px}'
        . '.box{max-width:520px;margin:40px auto;background:#fff;border:1px solid #c3c4c7;border-radius:6px;padding:8px 28px 24px}'
        . 'h1{font-size:21px}ul{padding-left:20px}code{background:#f0f0f1;padding:1px 4px}.muted{color:#50575e;font-size:13px}'
        . '.btn{font-size:15px;padding:9px 20px;border-radius:4px;border:1px solid #2271b1;cursor:pointer;margin-right:8px}'
        . '.yes{background:#2271b1;color:#fff}.no{background:#fff;color:#2271b1}'
        . '</style></head><body><div class="box">';
}

function bulkseme_oauth_page_close() {
    echo '</div></body></html>';
}

/**
 * The approval page. Checks the app and where it returns to before anything
 * else, so a bad request never sends the browser to an address the app did not
 * register. Then the visitor must be signed in and allowed to approve. Allow
 * and Cancel post back here with a nonce bound to every request value.
 */
function bulkseme_oauth_authorize_page($method) {
    if (!bulkseme_oauth_enabled()) {
        bulkseme_oauth_stop(bulkseme_connection_is_secure()
            ? __('One-click sign-in is switched off on this site. An administrator can switch it on under Settings > AI CLI Connector.', 'bulk-seo-meta-editor-for-ai-agents')
            : __('One-click sign-in needs this site to be served over HTTPS.', 'bulk-seo-meta-editor-for-ai-agents'));
    }
    // phpcs:ignore WordPress.Security.NonceVerification -- the nonce is checked below before anything is approved.
    $src = $method === 'POST' ? $_POST : $_GET;

    $client_id = bulkseme_oauth_input($src, 'client_id');
    $client    = bulkseme_oauth_client($client_id);
    if (!$client) {
        bulkseme_oauth_stop(__('This site does not know the app that sent you here. Remove the connector in your AI app and add it again.', 'bulk-seo-meta-editor-for-ai-agents'));
    }
    $redirect = bulkseme_oauth_input($src, 'redirect_uri');
    if ($redirect === '' && count($client['redirect_uris']) === 1) {
        $redirect = $client['redirect_uris'][0];
    }
    if (!bulkseme_oauth_redirect_matches($redirect, $client['redirect_uris'])) {
        bulkseme_oauth_stop(__('The app asked to return to an address it did not register. Nothing was approved.', 'bulk-seo-meta-editor-for-ai-agents'));
    }

    $state = bulkseme_oauth_input($src, 'state');
    if (strlen($state) > 1024 || preg_match('/[\x00-\x1f\x7f]/', $state)) {
        bulkseme_oauth_stop(__('The request from the app was not valid. Nothing was approved.', 'bulk-seo-meta-editor-for-ai-agents'));
    }
    $back = $state !== '' ? ['state' => $state] : [];

    // Sign in before anything is sent back to the app, so this address never redirects a visitor
    // who has not signed in (RFC 9700 4.11.2).
    $here = $method === 'GET'
        // The site's own address, never the Host header the request came with.
        ? bulkseme_oauth_origin(home_url()) . (isset($_SERVER['REQUEST_URI']) ? esc_url_raw(wp_unslash($_SERVER['REQUEST_URI'])) : '/')
        : '';
    if (!is_user_logged_in()) {
        if ($method === 'GET') {
            // Back to this same request after signing in. wp_login_url keeps it on this site.
            wp_safe_redirect(wp_login_url($here));
            exit;
        }
        bulkseme_oauth_stop(__('You are signed out. Go back to your AI app and connect again.', 'bulk-seo-meta-editor-for-ai-agents'));
    }
    // A browser signed in with its login cookie, not an application password, a key or another
    // plugin's API sign-in: approving is a click, never something a script does with a credential.
    if ((defined('XMLRPC_REQUEST') && XMLRPC_REQUEST) || (int) wp_validate_auth_cookie('', 'logged_in') !== get_current_user_id()) {
        bulkseme_oauth_stop(__('Approving an app needs you signed in to this site in your browser. Sign in, then connect again from your AI app.', 'bulk-seo-meta-editor-for-ai-agents'));
    }
    $user = wp_get_current_user();
    if (!bulkseme_oauth_can_approve($user)) {
        bulkseme_oauth_back($redirect, ['error' => 'access_denied', 'error_description' => 'This account cannot approve connections'] + $back);
    }

    // From here on, problems with the request are reported back to the app.
    if (bulkseme_oauth_input($src, 'response_type') !== 'code') {
        bulkseme_oauth_back($redirect, ['error' => 'unsupported_response_type'] + $back);
    }
    $challenge = bulkseme_oauth_input($src, 'code_challenge');
    if (bulkseme_oauth_input($src, 'code_challenge_method') !== 'S256' || !preg_match('/^[A-Za-z0-9_-]{43}$/D', $challenge)) {
        bulkseme_oauth_back($redirect, ['error' => 'invalid_request', 'error_description' => 'PKCE with S256 is required'] + $back);
    }
    $resource = bulkseme_oauth_input($src, 'resource');
    if ($resource !== '' && !bulkseme_oauth_resource_matches($resource)) {
        bulkseme_oauth_back($redirect, ['error' => 'invalid_target'] + $back);
    }

    $nonce_action = 'bulkseme_oauth|' . md5(wp_json_encode([$client_id, $redirect, $challenge, $state, $resource]));
    if ($method === 'POST') {
        $nonce = bulkseme_oauth_input($src, '_bulkseme_oauth_nonce');
        if (!wp_verify_nonce($nonce, $nonce_action)) {
            bulkseme_oauth_stop(__('This approval page has expired. Go back to your AI app and connect again.', 'bulk-seo-meta-editor-for-ai-agents'));
        }
        if (bulkseme_oauth_input($src, 'decision') !== 'allow') {
            bulkseme_oauth_back($redirect, ['error' => 'access_denied'] + $back);
        }
        $code = bin2hex(random_bytes(24));
        set_transient('bulkseme_oac_' . bulkseme_oauth_hash($code), [
            'client_id'       => $client_id,
            'redirect_uri'    => $redirect,
            'challenge'       => $challenge,
            'user_id'         => (int) $user->ID,
            'user_login'      => $user->user_login,
            'user_registered' => $user->user_registered,
            'exp'             => time() + BULKSEME_OAUTH_CODE_TTL,
        ], BULKSEME_OAUTH_CODE_TTL);
        bulkseme_oauth_back($redirect, ['code' => $code] + $back);
    }

    // At the most connections a site keeps, say so here rather than after the Allow click.
    if (count(bulkseme_oauth_prune()) >= BULKSEME_OAUTH_MAX_GRANTS) {
        bulkseme_oauth_stop(__('This site already has the most connected apps it can keep. Disconnect one under Settings > AI CLI Connector, then connect again.', 'bulk-seo-meta-editor-for-ai-agents'));
    }

    // Without its anti-framing headers this page could be clicked through from inside another site's
    // frame. When something has already printed output, the headers can no longer be sent: refuse.
    if (headers_sent()) {
        bulkseme_oauth_stop(__('This page cannot be shown safely because something on the site printed output too early. Ask the site administrator to check for PHP errors or a stray blank line in a theme or plugin file.', 'bulk-seo-meta-editor-for-ai-agents'));
    }

    // The question. No sprintf over translated text: a language pack must never be able to stop this page.
    $app  = bulkseme_oauth_app_label($redirect);
    $site = (string) wp_parse_url(home_url(), PHP_URL_HOST);
    bulkseme_oauth_page_open(__('Connect an AI app', 'bulk-seo-meta-editor-for-ai-agents'));
    echo '<h1>' . esc_html__('Connect', 'bulk-seo-meta-editor-for-ai-agents') . ' ' . esc_html($app) . ' ' . esc_html__('to', 'bulk-seo-meta-editor-for-ai-agents') . ' ' . esc_html($site) . '?</h1>';
    echo '<p>' . esc_html__('It will work on this site as your account,', 'bulk-seo-meta-editor-for-ai-agents') . ' <strong>' . esc_html($user->user_login) . '</strong>, '
        . esc_html__('through this plugin\'s tools only. It will be able to:', 'bulk-seo-meta-editor-for-ai-agents') . '</p><ul>';
    echo '<li>' . esc_html__('read your pages, posts and their SEO fields', 'bulk-seo-meta-editor-for-ai-agents') . '</li>';
    echo '<li>' . esc_html__('change SEO titles, descriptions, schema and image alt text', 'bulk-seo-meta-editor-for-ai-agents') . '</li>';
    echo '<li>' . esc_html__('edit page text (every change is backed up and can be undone)', 'bulk-seo-meta-editor-for-ai-agents') . '</li>';
    echo '<li>' . esc_html__('copy pages, and add or remove redirects', 'bulk-seo-meta-editor-for-ai-agents') . '</li></ul>';
    echo '<p><strong>' . esc_html__('Only allow this if you started connecting this app yourself, just now.', 'bulk-seo-meta-editor-for-ai-agents') . '</strong></p>';
    echo '<p class="muted">' . esc_html__('After you click Allow you go back to:', 'bulk-seo-meta-editor-for-ai-agents') . ' <code>' . esc_html($redirect) . '</code>';
    if (!empty($client['name'])) {
        echo '<br>' . esc_html__('Name the app gave itself (not checked):', 'bulk-seo-meta-editor-for-ai-agents') . ' ' . esc_html($client['name']);
    }
    echo '</p>';
    echo '<form method="post" action="' . esc_url(bulkseme_oauth_authorize_url()) . '">';
    foreach (['client_id' => $client_id, 'redirect_uri' => $redirect, 'state' => $state, 'response_type' => 'code',
              'code_challenge' => $challenge, 'code_challenge_method' => 'S256', 'resource' => $resource] as $k => $v) {
        echo '<input type="hidden" name="' . esc_attr($k) . '" value="' . esc_attr($v) . '">';
    }
    echo '<input type="hidden" name="_bulkseme_oauth_nonce" value="' . esc_attr(wp_create_nonce($nonce_action)) . '">';
    echo '<p><button class="btn yes" type="submit" name="decision" value="allow">' . esc_html__('Allow', 'bulk-seo-meta-editor-for-ai-agents') . '</button>';
    echo '<button class="btn no" type="submit" name="decision" value="deny">' . esc_html__('Cancel', 'bulk-seo-meta-editor-for-ai-agents') . '</button></p>';
    echo '</form>';
    echo '<p class="muted">' . esc_html__('You can disconnect it at any time under Settings > AI CLI Connector.', 'bulk-seo-meta-editor-for-ai-agents') . '</p>';
    bulkseme_oauth_page_close();
    exit;
}

/** End every connection of an account that may no longer approve them. */
function bulkseme_oauth_end_if_not_approver($user_id) {
    $user = get_userdata((int) $user_id);
    if ($user && bulkseme_oauth_can_approve($user)) {
        return;
    }
    foreach (bulkseme_oauth_grants() as $id => $g) {
        if (is_array($g) && (int) (isset($g['user_id']) ? $g['user_id'] : 0) === (int) $user_id) {
            bulkseme_oauth_revoke((string) $id);
        }
    }
}
// A demoted account's connections end; promoting it again does not bring them back.
add_action('set_user_role', 'bulkseme_oauth_end_if_not_approver');
add_action('remove_user_role', 'bulkseme_oauth_end_if_not_approver');

// A deleted account's connections end with it.
add_action('deleted_user', function ($user_id) {
    foreach (bulkseme_oauth_grants() as $id => $g) {
        if (is_array($g) && (int) (isset($g['user_id']) ? $g['user_id'] : 0) === (int) $user_id) {
            bulkseme_oauth_revoke((string) $id);
        }
    }
});
