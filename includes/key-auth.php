<?php
/**
 * API keys: a second way to sign REST requests in.
 *
 * Application passwords are the default way to reach this plugin. Some security
 * plugins and hosts switch them off or block the endpoint that creates them, so
 * an administrator can make a key on Tools > Agent API Keys instead.
 *
 * What a key is:
 *   - Stored only as a SHA-256 hash. The key itself is shown once, when it is
 *     made, so a copy of the database holds nothing that can be replayed.
 *   - 192 random bits (random_bytes). Checked with hash_equals().
 *   - Made only by a signed-in administrator, through a form with a nonce or
 *     through POST /register-key from the same browser session.
 *   - Tied to the user who made it: it signs in as that user, with that user's
 *     capabilities as they are today, and stops working when the user is
 *     deleted. Every route keeps its own capability checks.
 *   - Scoped. By default a key signs in only on this plugin's own routes
 *     (seo-meta-bridge/v1). With the wider scope it signs in on every REST
 *     route, and is then as powerful as an application password of that user.
 *   - REST only. A key is read only once WordPress itself has decided the
 *     request is a REST API request, so wp-login.php, wp-admin, admin-ajax,
 *     XML-RPC and front-end pages never see it.
 *   - HTTPS only, so it cannot be read in transit.
 *   - Revoked one at a time on the same screen. Switch the whole feature off
 *     with: add_filter('bulkseme_api_key_auth_enabled', '__return_false');
 */

if (!defined('ABSPATH')) exit;

/**
 * Prefixes a key can start with. New keys start with bsme_. scli_ is the prefix
 * of keys made by a companion build; their hashes can be registered here and
 * the keys then keep working.
 */
function bulkseme_key_prefixes() {
    return ['bsme_', 'scli_'];
}

/** Most keys a site keeps. */
function bulkseme_key_limit() {
    return 50;
}

/** Stored key records, keyed by their 8-character id. */
function bulkseme_keys() {
    $keys = get_option('bulkseme_api_keys', []);
    return is_array($keys) ? $keys : [];
}

/**
 * When each key was last used: id => timestamp. Kept apart from the key
 * records so that signing in never rewrites them. A request that is still in
 * flight while a key is revoked therefore cannot write the revoked key back.
 */
function bulkseme_keys_last_used() {
    $used = get_option('bulkseme_api_keys_used', []);
    return is_array($used) ? $used : [];
}

/** Note that a key was used, at most once every five minutes per key. */
function bulkseme_key_touch($id) {
    $used = bulkseme_keys_last_used();
    if ((time() - (int) (isset($used[$id]) ? $used[$id] : 0)) > 300) {
        $used[$id] = time();
        update_option('bulkseme_api_keys_used', array_intersect_key($used, bulkseme_keys()), false);
    }
}

/** Key sign-in needs HTTPS, or a local development site. */
function bulkseme_connection_is_secure() {
    if (is_ssl()) {
        return true;
    }
    // X-Forwarded-Proto comes from the client and can be forged, so it only
    // counts when the site owner says the site sits behind a proxy that sets it.
    if (apply_filters('bulkseme_trust_forwarded_proto', false) && isset($_SERVER['HTTP_X_FORWARDED_PROTO'])
        && strtolower(sanitize_text_field(wp_unslash($_SERVER['HTTP_X_FORWARDED_PROTO']))) === 'https') {
        return true;
    }
    if (function_exists('wp_get_environment_type') && wp_get_environment_type() === 'local') {
        return true;
    }
    return (bool) apply_filters('bulkseme_allow_insecure_key_auth', false);
}

/** True when the string has the shape of a key: prefix, 8 hex characters, 48 hex characters. */
function bulkseme_looks_like_key($value) {
    return is_string($value) && (bool) preg_match('/^(?:bsme|scli)_[0-9a-f]{8}_[0-9a-f]{48}$/', $value);
}

/**
 * The key this request carries, or ''. Read from the X-Bulkseme-Key header
 * (X-SEOCLI-Key is accepted as a second name for it) or from the password of
 * HTTP Basic auth, which WordPress has already split out of the Authorization
 * header.
 */
function bulkseme_request_key() {
    foreach (['HTTP_X_BULKSEME_KEY', 'HTTP_X_SEOCLI_KEY', 'PHP_AUTH_PW'] as $name) {
        if (empty($_SERVER[$name]) || !is_string($_SERVER[$name])) {
            continue;
        }
        // Only a value with the exact shape of a key is used; it is hashed and compared, never stored or printed.
        $value = trim(sanitize_text_field(wp_unslash($_SERVER[$name])));
        if (bulkseme_looks_like_key($value)) {
            return $value;
        }
    }
    return '';
}

/** Check a key against the stored hashes. Returns its record, or false. Writes nothing. */
function bulkseme_validate_api_key($key) {
    if (!bulkseme_looks_like_key($key)) {
        return false;
    }
    $parts = explode('_', $key);
    $id    = $parts[1];
    $keys  = bulkseme_keys();
    if (empty($keys[$id]) || !is_array($keys[$id]) || empty($keys[$id]['hash']) || !is_string($keys[$id]['hash'])) {
        return false;
    }
    if (!hash_equals($keys[$id]['hash'], hash('sha256', $parts[2]))) {
        return false;
    }
    $rec       = $keys[$id];
    $rec['id'] = $id;
    return $rec;
}

/**
 * The user a key record signs in as, or null.
 *
 * The user must still exist and must still be the account the key was made
 * for: the login name and registration date stored with the key are compared,
 * so a key can never pass to a different account that later ends up with the
 * same user id.
 */
function bulkseme_key_user(array $rec) {
    $user = empty($rec['user_id']) ? false : get_userdata((int) $rec['user_id']);
    if (!$user) {
        return null;
    }
    if (isset($rec['user_login']) && $rec['user_login'] !== $user->user_login) {
        return null;
    }
    if (isset($rec['user_registered']) && $rec['user_registered'] !== $user->user_registered) {
        return null;
    }
    return $user;
}

/**
 * Does the key's scope cover the route being requested? A key with the default
 * scope signs in on this plugin's routes and nowhere else.
 */
function bulkseme_key_scope_allows(array $rec) {
    if (isset($rec['scope']) && $rec['scope'] === 'all') {
        return true;
    }
    global $wp;
    // The route WordPress itself parsed for this request, the same value it hands to the REST server.
    $route = (is_object($wp) && isset($wp->query_vars['rest_route']) && is_string($wp->query_vars['rest_route']))
        ? '/' . ltrim($wp->query_vars['rest_route'], '/')
        : '';
    return strcasecmp($route, '/seo-meta-bridge/v1') === 0 || stripos($route, '/seo-meta-bridge/v1/') === 0;
}

/**
 * The user this request was signed in as by a key, or 0. Set once by the
 * determine_current_user filter below.
 */
function bulkseme_key_auth_user($set = null) {
    static $user = 0;
    if ($set !== null) {
        $user = (int) $set;
    }
    return $user;
}

/**
 * Sign a REST request in when it carries a valid key.
 *
 * Does nothing until WordPress has marked the request as a REST API request
 * (REST_REQUEST), which is also the moment core itself starts honouring
 * application passwords. It runs after cookies (10) and never replaces a user
 * they found, and before application passwords (20), so a key sent as a Basic
 * auth password is not first tried, and logged as a failed sign-in, there.
 */
function bulkseme_determine_user_by_key($user_id) {
    if (!empty($user_id)) {
        return $user_id;
    }
    if (!(defined('REST_REQUEST') && REST_REQUEST)) {
        return $user_id;
    }
    if (!apply_filters('bulkseme_api_key_auth_enabled', true) || !bulkseme_connection_is_secure()) {
        return $user_id;
    }
    $key = bulkseme_request_key();
    if ($key === '') {
        return $user_id;
    }
    $rec = bulkseme_validate_api_key($key);
    if (!$rec) {
        return $user_id;
    }
    $user = bulkseme_key_user($rec);
    if (!$user || !bulkseme_key_scope_allows($rec)) {
        return $user_id;
    }
    bulkseme_key_touch($rec['id']);
    bulkseme_key_auth_user($user->ID);
    return $user->ID;
}
add_filter('determine_current_user', 'bulkseme_determine_user_by_key', 15);

/**
 * Tell the REST server that a key signed this request in.
 *
 * Asking for the current user first makes sure sign-in has run for this
 * request. True is returned only when the key itself set that user; any error
 * another sign-in method raised first is passed through unchanged.
 */
function bulkseme_rest_key_auth_result($result) {
    if (!empty($result)) {
        return $result;
    }
    $current = get_current_user_id();
    $by_key  = bulkseme_key_auth_user();
    return ($by_key && $by_key === $current) ? true : $result;
}
add_filter('rest_authentication_errors', 'bulkseme_rest_key_auth_result', 80);

/** Store one key record. Returns true, or a WP_Error. */
function bulkseme_store_key_record($id, $hash, $label, $user_id, $prefix, $scope = 'plugin') {
    if (!is_string($id) || !is_string($hash) || !preg_match('/^[0-9a-f]{8}$/', $id) || !preg_match('/^[0-9a-f]{64}$/', $hash)) {
        return new WP_Error('invalid_params', 'id must be 8 hex characters and hash 64 hex characters', ['status' => 400]);
    }
    $user = get_userdata((int) $user_id);
    if (!$user) {
        return new WP_Error('invalid_user', 'a key needs an existing user to sign in as', ['status' => 400]);
    }
    $keys = bulkseme_keys();
    if (isset($keys[$id])) {
        return new WP_Error('id_exists', 'a key with that id already exists', ['status' => 409]);
    }
    if (count($keys) >= bulkseme_key_limit()) {
        return new WP_Error('too_many_keys', 'this site already holds the most keys it can; revoke one first', ['status' => 409]);
    }
    $label     = trim(sanitize_text_field((string) $label));
    $label     = function_exists('mb_substr') ? mb_substr($label, 0, 80) : substr($label, 0, 80);
    $keys[$id] = [
        'id'              => $id,
        'prefix'          => in_array($prefix, bulkseme_key_prefixes(), true) ? $prefix : 'bsme_',
        'label'           => $label !== '' ? $label : 'API key',
        'scope'           => $scope === 'all' ? 'all' : 'plugin',
        'user_id'         => (int) $user->ID,
        'user_login'      => $user->user_login,
        'user_registered' => $user->user_registered,
        'hash'            => $hash,
        'created'         => time(),
    ];
    update_option('bulkseme_api_keys', $keys, false);
    return true;
}

/** Make a new key for a user. Returns the key (shown once) or a WP_Error. */
function bulkseme_generate_api_key($label, $user_id, $scope = 'plugin') {
    $keys = bulkseme_keys();
    try {
        do {
            $id = bin2hex(random_bytes(4));
        } while (isset($keys[$id]));
        $secret = bin2hex(random_bytes(24));
    } catch (\Exception $e) {
        return new WP_Error('no_random_source', 'this server has no secure random source, so no key was made', ['status' => 500]);
    }
    $stored = bulkseme_store_key_record($id, hash('sha256', $secret), $label, $user_id, 'bsme_', $scope);
    if (is_wp_error($stored)) {
        return $stored;
    }
    return 'bsme_' . $id . '_' . $secret;
}

/** Remove one key. Returns true when it existed. */
function bulkseme_revoke_api_key($id) {
    $keys = bulkseme_keys();
    if (!is_string($id) || !isset($keys[$id])) {
        return false;
    }
    unset($keys[$id]);
    update_option('bulkseme_api_keys', $keys, false);
    return true;
}

/** A user's keys go when the user does. */
add_action('deleted_user', function ($user_id) {
    $keys = bulkseme_keys();
    $kept = [];
    foreach ($keys as $id => $rec) {
        if (!is_array($rec) || !isset($rec['user_id']) || (int) $rec['user_id'] !== (int) $user_id) {
            $kept[$id] = $rec;
        }
    }
    if (count($kept) !== count($keys)) {
        update_option('bulkseme_api_keys', $kept, false);
    }
});

/**
 * True when the request comes from a signed-in browser session of the current
 * user: a valid login cookie and a valid REST nonce, and no key.
 */
function bulkseme_is_browser_session(WP_REST_Request $req) {
    if (bulkseme_key_auth_user()) {
        return false;
    }
    $user = get_current_user_id();
    if (!$user || (int) wp_validate_auth_cookie('', 'logged_in') !== $user) {
        return false;
    }
    $nonce = $req->get_header('X-WP-Nonce');
    return is_string($nonce) && $nonce !== '' && (bool) wp_verify_nonce(sanitize_text_field($nonce), 'wp_rest');
}

/*
 * POST /register-key {id, hash, label, scope}
 *
 * Lets an administrator's own browser session store the hash of a key that was
 * generated on their own machine, so the key itself never passes through the
 * browser or the page. Only the hash arrives here. It needs a login cookie and
 * a REST nonce: a request signed in with a key or an application password is
 * turned away. scope is "plugin" (the default: this plugin's routes only) or
 * "all" (every REST route).
 */
add_action('rest_api_init', function () {
    register_rest_route('seo-meta-bridge/v1', '/register-key', [
        'methods'             => 'POST',
        'permission_callback' => function (WP_REST_Request $req) {
            return current_user_can('manage_options') && bulkseme_is_browser_session($req);
        },
        'callback'            => function (WP_REST_Request $req) {
            $id     = $req->get_param('id');
            $hash   = $req->get_param('hash');
            $label  = $req->get_param('label');
            $prefix = $req->get_param('prefix');
            $scope  = $req->get_param('scope');
            $id     = is_string($id) ? strtolower($id) : '';
            $stored = bulkseme_store_key_record(
                $id,
                is_string($hash) ? strtolower($hash) : '',
                is_string($label) ? $label : '',
                get_current_user_id(),
                is_string($prefix) ? $prefix : 'bsme_',
                $scope === 'all' ? 'all' : 'plugin'
            );
            if (is_wp_error($stored)) {
                return $stored;
            }
            $user = wp_get_current_user();
            return ['ok' => true, 'id' => $id, 'scope' => $scope === 'all' ? 'all' : 'plugin', 'user' => $user->user_login];
        },
    ]);
});
