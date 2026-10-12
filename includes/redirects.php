<?php
/**
 * URL redirects: GET, POST and DELETE /redirect.
 *
 * Why: a site restructure leaves old URLs answering 200 or 404 (a renamed
 * category, a retired page, a post type archive that moved), and neither SEO
 * plugin offers a REST route for a plain URL-to-URL rule.
 *
 * Engine (reported by /status as redirect_engine and in every response):
 *   rankmath  Rank Math's Redirections module is on. The rule is written through
 *             Rank Math's own Redirection class, so it shows in Rank Math >
 *             Redirections like a hand-made one, gets hit counts and is served
 *             by Rank Math. Nothing of ours runs on the front end. A rule whose
 *             exact source already exists is updated, never duplicated. DELETE
 *             moves it to Rank Math's trash.
 *   builtin   No Rank Math redirections (Yoast-only sites and the like): rules
 *             live in the option `bulkseme_redirects` and a template_redirect
 *             hook at priority 1 serves them. Exact path match, query string
 *             ignored.
 *
 * POST /redirect {from, to, type=301|302|307|410, engine=auto|rankmath|builtin, dry_run}
 *   from: a path on this site ("/old-page/", "old-page", or the absolute URL),
 *         stored without a leading or trailing slash. Never the site root,
 *         never off-site, never "..".
 *   to:   absolute http(s) URL or a site path; required unless type is 410.
 *   A rule whose destination is its own source is refused (loop).
 *   After a write the active page cache is asked to drop the old URL, best
 *   effort, reported as cache_purged. A cached 200 would otherwise keep
 *   serving until the cache expired.
 * GET /redirect            lists active rules for the engine in use.
 * DELETE /redirect {id|from}
 *
 * A redirect is a site-level change, so all three need manage_options. Rules
 * that go through Rank Math also need Rank Math's own Redirections capability,
 * so its Role Manager keeps the last word on who may touch them.
 */

if (!defined('ABSPATH')) exit;

/** Most rules the built-in engine keeps. They are read on every front-end request. */
function bulkseme_redirect_max_rules() {
    return 500;
}

/** Header codes a rule may carry. */
function bulkseme_redirect_codes() {
    return [301, 302, 307, 410];
}

/** 'rankmath' when Rank Math's Redirections module is switched on, else 'builtin'. */
function bulkseme_redirect_engine() {
    if (class_exists('\\RankMath\\Redirections\\Redirection')
        && class_exists('\\RankMath\\Redirections\\DB')
        && class_exists('\\RankMath\\Helper')
        && is_callable(['\\RankMath\\Helper', 'is_module_active'])
        && \RankMath\Helper::is_module_active('redirections', false)) {
        return 'rankmath';
    }
    return 'builtin';
}

/**
 * May the current user manage redirects through this engine?
 *
 * @param string $engine 'rankmath' or 'builtin'.
 */
function bulkseme_redirect_user_can($engine) {
    if (!current_user_can('manage_options')) {
        return false;
    }
    if ($engine === 'rankmath' && !current_user_can('rank_math_redirections')) {
        return false;
    }
    return true;
}

/** The answer for a caller the engine's own rules turn away. */
function bulkseme_redirect_forbidden() {
    return new WP_Error('forbidden', 'your account may not manage Rank Math redirections on this site', ['status' => 403]);
}

/** Site path with the install subdirectory (if any) removed, no leading or trailing slash. */
function bulkseme_redirect_site_path($path) {
    $path = explode('?', (string) $path, 2)[0];
    $path = explode('#', $path, 2)[0];
    $path = trim(rawurldecode($path), '/');
    $home = wp_parse_url(home_url('/'));
    $base = isset($home['path']) ? trim($home['path'], '/') : '';
    if ($base !== '' && ($path === $base || strpos($path, $base . '/') === 0)) {
        $path = trim(substr($path, strlen($base)), '/');
    }
    return $path;
}

/** Hostname without a leading www. */
function bulkseme_redirect_bare_host($host) {
    return preg_replace('/^www\./i', '', strtolower((string) $host));
}

/**
 * Source URL or path -> stored pattern ("old-page/sub"), or WP_Error.
 * Absolute URLs must be on this site; the root and anything with ".." are refused.
 */
function bulkseme_redirect_normalise_from($from) {
    if (!is_string($from)) {
        return new WP_Error('invalid_from', 'from must be a path on this site, for example /old-page/', ['status' => 400]);
    }
    $from = trim($from);
    if ($from === '' || strlen($from) > 500 || preg_match('/[\x00-\x1f\x7f<>"\'\\\\]/', $from)) {
        return new WP_Error('invalid_from', 'from must be a path on this site, for example /old-page/', ['status' => 400]);
    }
    $home_host = bulkseme_redirect_bare_host(wp_parse_url(home_url('/'), PHP_URL_HOST));
    if (preg_match('#^https?://#i', $from)) {
        $p = wp_parse_url($from);
        $from_host = bulkseme_redirect_bare_host(is_array($p) && isset($p['host']) ? $p['host'] : '');
        if ($from_host === '' || $from_host !== $home_host) {
            return new WP_Error('invalid_from', 'from must be on this site', ['status' => 400]);
        }
        $from = isset($p['path']) ? $p['path'] : '/';
        if (isset($p['query'])) {
            $from .= '?' . $p['query'];
        }
    } elseif (preg_match('#^[a-z][a-z0-9+.-]*:#i', $from) || strpos($from, '//') === 0) {
        return new WP_Error('invalid_from', 'from must be a path or an http(s) URL on this site', ['status' => 400]);
    }
    if (strpos($from, '?') !== false) {
        return new WP_Error('invalid_from', 'from cannot carry a query string; rules match the path only', ['status' => 400]);
    }
    $path = bulkseme_redirect_site_path($from);
    if ($path === '' || strpos($path, '..') !== false || preg_match('/[\x00-\x1f\x7f<>"\'\\\\]/', $path)) {
        return new WP_Error('invalid_from', 'from cannot be the site root or contain ..', ['status' => 400]);
    }
    return $path;
}

/** Destination -> absolute http(s) URL ('' for 410), or WP_Error. */
function bulkseme_redirect_normalise_to($to, $code) {
    if ((int) $code === 410) {
        return '';
    }
    if (!is_string($to)) {
        return new WP_Error('invalid_to', 'to must be an http(s) URL or a path starting with /', ['status' => 400]);
    }
    $to = trim($to);
    if ($to === '') {
        return new WP_Error('invalid_to', 'to is required for a ' . (int) $code . ' redirect', ['status' => 400]);
    }
    if (strlen($to) > 2000 || preg_match('/[\x00-\x1f\x7f]/', $to)) {
        return new WP_Error('invalid_to', 'to must be an http(s) URL or a path starting with /', ['status' => 400]);
    }
    if ($to[0] === '/' && (strlen($to) < 2 || ($to[1] !== '/' && $to[1] !== '\\'))) {
        $to = home_url($to);
    }
    $clean = esc_url_raw($to, ['http', 'https']);
    if ($clean === '' || !preg_match('#^https?://#i', $clean) || !bulkseme_redirect_plain_host($clean)) {
        return new WP_Error('invalid_to', 'to must be an http(s) URL or a path starting with /', ['status' => 400]);
    }
    return $clean;
}

/**
 * True when the URL has a plain host: one that wp_parse_url can read and no
 * user:pass@ part. Keeps destinations written as "this-site@other.example",
 * which read as one site and send to another, out of the rules.
 */
function bulkseme_redirect_plain_host($url) {
    $p = wp_parse_url($url);
    if (!is_array($p) || empty($p['host']) || isset($p['user']) || isset($p['pass'])) {
        return false;
    }
    return (bool) preg_match('/^[^\x00-\x20\x7f<>"\'\\\\@]+$/', $p['host']);
}

/** True when the destination is the source itself (same site, same path). */
function bulkseme_redirect_is_loop($from_path, $to) {
    if ($to === '') {
        return false;
    }
    $p = wp_parse_url($to);
    if (!is_array($p)) {
        return false;
    }
    $to_host   = bulkseme_redirect_bare_host(isset($p['host']) ? $p['host'] : '');
    $home_host = bulkseme_redirect_bare_host(wp_parse_url(home_url('/'), PHP_URL_HOST));
    if ($to_host !== $home_host) {
        return false;
    }
    return bulkseme_redirect_site_path(isset($p['path']) ? $p['path'] : '/') === $from_path;
}

/**
 * Ask the active page cache to drop the old URL. Best effort: a plugin that
 * only clears everything clears everything. Returns the plugins reached.
 */
function bulkseme_redirect_purge_page_cache($url) {
    $done = [];
    if (function_exists('wpfc_clear_all_cache')) {
        wpfc_clear_all_cache(true);
        $done[] = 'wp-fastest-cache';
    }
    if (function_exists('rocket_clean_files')) {
        rocket_clean_files([$url]);
        $done[] = 'wp-rocket';
    }
    if (has_action('litespeed_purge_url')) {
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- LiteSpeed Cache's documented purge action; we call it, we do not own it.
        do_action('litespeed_purge_url', $url);
        $done[] = 'litespeed';
    }
    if (function_exists('w3tc_flush_url')) {
        w3tc_flush_url($url);
        $done[] = 'w3-total-cache';
    }
    if (function_exists('wpsc_delete_url_cache')) {
        wpsc_delete_url_cache($url);
        $done[] = 'wp-super-cache';
    }
    if (function_exists('sg_cachepress_purge_cache')) {
        sg_cachepress_purge_cache($url);
        $done[] = 'siteground-optimizer';
    }
    return $done;
}

// ---- Built-in engine: option-backed rules served on template_redirect --------

/** Stored rules, with anything malformed left out. */
function bulkseme_redirect_rules() {
    $rules = get_option('bulkseme_redirects', []);
    if (!is_array($rules)) {
        return [];
    }
    $out = [];
    foreach ($rules as $r) {
        if (is_array($r) && isset($r['from']) && is_string($r['from']) && $r['from'] !== '') {
            $out[] = $r;
        }
    }
    return $out;
}

function bulkseme_redirect_save_rules(array $rules) {
    update_option('bulkseme_redirects', array_values($rules), true);
}

add_action('template_redirect', 'bulkseme_redirects_run', 1);

/** Serve a built-in rule for the page being requested, if one matches. */
function bulkseme_redirects_run() {
    if (is_admin() || wp_doing_ajax() || wp_doing_cron()
        || (defined('REST_REQUEST') && REST_REQUEST) || (defined('WP_CLI') && WP_CLI)) {
        return;
    }
    $rules = bulkseme_redirect_rules();
    if (!$rules) {
        return;
    }
    // The request path is only compared with the stored sources. It is never stored or printed.
    $uri = isset($_SERVER['REQUEST_URI']) ? esc_url_raw(wp_unslash($_SERVER['REQUEST_URI'])) : '';
    if ($uri === '') {
        return;
    }
    $path = bulkseme_redirect_site_path($uri);
    if ($path === '') {
        return;
    }
    $rule = null;
    foreach ($rules as $r) {
        if ($r['from'] === $path) {
            $rule = $r;
            break;
        }
    }
    if (!$rule) {
        return;
    }
    $code = isset($rule['code']) ? (int) $rule['code'] : 301;
    $to   = isset($rule['to']) && is_string($rule['to']) ? $rule['to'] : '';
    if ($code === 410 || $to === '') {
        nocache_headers();
        status_header(410);
        exit;
    }
    if (!in_array($code, [301, 302, 307], true) || bulkseme_redirect_is_loop($path, $to) || !bulkseme_redirect_plain_host($to)) {
        return;
    }
    // An administrator chose this destination, so its host is allowed for this
    // one call only. The site-wide list of safe redirect hosts is left alone.
    $host  = (string) wp_parse_url($to, PHP_URL_HOST);
    $allow = function ($hosts) use ($host) {
        $hosts   = (array) $hosts;
        $hosts[] = $host;
        return $hosts;
    };
    nocache_headers();
    add_filter('allowed_redirect_hosts', $allow);
    $sent = wp_safe_redirect($to, $code, 'Bulk AI CLI Connector');
    remove_filter('allowed_redirect_hosts', $allow);
    if ($sent) {
        exit;
    }
}

// ---- Rank Math engine -------------------------------------------------------

/**
 * Sources of one Rank Math rule as [['pattern' => ..., 'comparison' => ...]], read
 * through Rank Math's own lookup so its storage format stays its business.
 */
function bulkseme_redirect_rankmath_sources($id) {
    $rule = \RankMath\Redirections\DB::get_redirection_by_id((int) $id);
    $out  = [];
    if (!is_array($rule) || empty($rule['sources']) || !is_array($rule['sources'])) {
        return $out;
    }
    foreach ($rule['sources'] as $s) {
        if (is_array($s) && isset($s['pattern']) && is_string($s['pattern'])) {
            $out[] = ['pattern' => $s['pattern'], 'comparison' => isset($s['comparison']) && is_string($s['comparison']) ? $s['comparison'] : 'exact'];
        }
    }
    return $out;
}

/** Active Rank Math rule whose exact source equals $path: ['id' => n, 'url_to' => ..., 'header_code' => ...] or null. */
function bulkseme_redirect_rankmath_find($path) {
    $found = \RankMath\Redirections\DB::get_redirections(['search' => $path, 'status' => 'active', 'limit' => 50, 'orderby' => 'id', 'order' => 'DESC']);
    $rows  = is_array($found) && isset($found['redirections']) && is_array($found['redirections']) ? $found['redirections'] : [];
    foreach ($rows as $row) {
        if (!is_array($row) || empty($row['id'])) {
            continue;
        }
        foreach (bulkseme_redirect_rankmath_sources($row['id']) as $s) {
            if ($s['comparison'] === 'exact' && trim($s['pattern'], '/') === $path) {
                return [
                    'id'          => (int) $row['id'],
                    'url_to'      => isset($row['url_to']) ? (string) $row['url_to'] : '',
                    'header_code' => isset($row['header_code']) ? (int) $row['header_code'] : 301,
                ];
            }
        }
    }
    return null;
}

/** Active Rank Math rules, newest first. */
function bulkseme_redirect_rankmath_list($limit = 100) {
    $limit = max(1, min(200, (int) $limit));
    $found = \RankMath\Redirections\DB::get_redirections(['status' => 'active', 'limit' => $limit, 'orderby' => 'id', 'order' => 'DESC']);
    $rows  = is_array($found) && isset($found['redirections']) && is_array($found['redirections']) ? $found['redirections'] : [];
    $out   = [];
    foreach ($rows as $row) {
        if (!is_array($row) || empty($row['id'])) {
            continue;
        }
        $out[] = [
            'id'      => (int) $row['id'],
            'from'    => bulkseme_redirect_rankmath_sources($row['id']),
            'to'      => isset($row['url_to']) ? (string) $row['url_to'] : '',
            'type'    => isset($row['header_code']) ? (int) $row['header_code'] : 301,
            'hits'    => isset($row['hits']) ? (int) $row['hits'] : 0,
            'updated' => isset($row['updated']) ? (string) $row['updated'] : '',
        ];
    }
    return $out;
}

// ---- REST handlers -----------------------------------------------------------

function bulkseme_rest_redirect_list(WP_REST_Request $req) {
    $engine = bulkseme_redirect_engine();
    $own    = [];
    foreach (bulkseme_redirect_rules() as $r) {
        $own[] = [
            'from'    => $r['from'],
            'to'      => isset($r['to']) ? (string) $r['to'] : '',
            'type'    => isset($r['code']) ? (int) $r['code'] : 301,
            'created' => isset($r['created']) ? (string) $r['created'] : null,
        ];
    }
    if ($engine !== 'rankmath') {
        return ['engine' => $engine, 'count' => count($own), 'rules' => $own, 'builtin_rules' => count($own)];
    }
    if (!bulkseme_redirect_user_can('rankmath')) {
        return bulkseme_redirect_forbidden();
    }
    $limit = $req->get_param('limit') !== null ? absint($req->get_param('limit')) : 100;
    $rules = bulkseme_redirect_rankmath_list($limit);
    return ['engine' => $engine, 'count' => count($rules), 'rules' => $rules, 'builtin_rules' => count($own)];
}

function bulkseme_rest_redirect_save(WP_REST_Request $req) {
    $type = $req->get_param('type');
    if ($type !== null && !is_scalar($type)) {
        return new WP_Error('invalid_type', 'type must be one of 301, 302, 307, 410', ['status' => 400]);
    }
    $code = (int) ($type !== null ? $type : 301);
    if (!in_array($code, bulkseme_redirect_codes(), true)) {
        return new WP_Error('invalid_type', 'type must be one of 301, 302, 307, 410', ['status' => 400]);
    }
    $from = bulkseme_redirect_normalise_from($req->get_param('from'));
    if (is_wp_error($from)) {
        return $from;
    }
    $to = bulkseme_redirect_normalise_to($req->get_param('to'), $code);
    if (is_wp_error($to)) {
        return $to;
    }
    if (bulkseme_redirect_is_loop($from, $to)) {
        return new WP_Error('redirect_loop', 'to points back at from', ['status' => 400]);
    }
    $want = $req->get_param('engine');
    $want = is_string($want) && $want !== '' ? $want : 'auto';
    if (!in_array($want, ['auto', 'rankmath', 'builtin'], true)) {
        return new WP_Error('invalid_engine', 'engine must be auto, rankmath or builtin', ['status' => 400]);
    }
    $engine = bulkseme_redirect_engine();
    if ($want === 'rankmath' && $engine !== 'rankmath') {
        return new WP_Error('engine_unavailable', 'Rank Math Redirections is not active on this site', ['status' => 409]);
    }
    if ($want === 'builtin') {
        $engine = 'builtin';
    }
    if (!bulkseme_redirect_user_can($engine)) {
        return bulkseme_redirect_forbidden();
    }
    $dry = (bool) $req->get_param('dry_run');
    $out = ['engine' => $engine, 'from' => $from, 'to' => $to, 'type' => $code, 'dry_run' => $dry, 'source_url' => home_url('/' . $from . '/')];

    if ($engine === 'rankmath') {
        $existing      = bulkseme_redirect_rankmath_find($from);
        $out['action'] = $existing ? 'updated' : 'created';
        $out['id']     = $existing ? $existing['id'] : null;
        if ($dry) {
            return $out;
        }
        $data = ['sources' => [['pattern' => $from, 'comparison' => 'exact']], 'url_to' => $to, 'header_code' => (string) $code, 'status' => 'active'];
        if ($existing) {
            $data['id'] = $existing['id'];
        }
        $rule = \RankMath\Redirections\Redirection::from($data);
        if (!$rule->has_sources()) {
            return new WP_Error('rankmath_refused', 'Rank Math rejected the source pattern', ['status' => 400]);
        }
        if ($rule->is_infinite_loop()) {
            return new WP_Error('redirect_loop', 'Rank Math reports an infinite loop', ['status' => 400]);
        }
        $id = $rule->save();
        if (!$id) {
            return new WP_Error('rankmath_save_failed', 'Rank Math did not save the rule', ['status' => 500]);
        }
        $out['id'] = (int) $id;
    } else {
        $rules = bulkseme_redirect_rules();
        $found = false;
        foreach ($rules as $i => $r) {
            if ($r['from'] === $from) {
                $found = $i;
                break;
            }
        }
        $out['action'] = $found !== false ? 'updated' : 'created';
        if ($found === false && count($rules) >= bulkseme_redirect_max_rules()) {
            return new WP_Error('too_many', 'rule cap reached (' . bulkseme_redirect_max_rules() . ')', ['status' => 400]);
        }
        if ($dry) {
            return $out;
        }
        $new = [
            'from'    => $from,
            'to'      => $to,
            'code'    => $code,
            'created' => $found !== false && isset($rules[$found]['created']) ? $rules[$found]['created'] : current_time('mysql'),
            'updated' => current_time('mysql'),
        ];
        if ($found !== false) {
            $rules[$found] = $new;
        } else {
            $rules[] = $new;
        }
        bulkseme_redirect_save_rules($rules);
    }
    $out['cache_purged'] = bulkseme_redirect_purge_page_cache($out['source_url']);
    do_action('bulkseme_redirect_saved', $out, get_current_user_id());
    return $out;
}

function bulkseme_rest_redirect_delete(WP_REST_Request $req) {
    $engine = bulkseme_redirect_engine();
    $id     = absint($req->get_param('id'));
    $from   = $req->get_param('from') !== null ? bulkseme_redirect_normalise_from($req->get_param('from')) : null;
    if (is_wp_error($from)) {
        return $from;
    }
    if (!$id && $from === null) {
        return new WP_Error('missing_target', 'pass id or from', ['status' => 400]);
    }
    // Built-in rules go first: they are ours whatever the engine.
    if ($from !== null) {
        $kept = [];
        $hit  = false;
        foreach (bulkseme_redirect_rules() as $r) {
            if ($r['from'] === $from) {
                $hit = true;
                continue;
            }
            $kept[] = $r;
        }
        if ($hit) {
            bulkseme_redirect_save_rules($kept);
            do_action('bulkseme_redirect_deleted', ['engine' => 'builtin', 'from' => $from], get_current_user_id());
            return ['engine' => 'builtin', 'from' => $from, 'deleted' => true];
        }
    }
    if ($engine !== 'rankmath') {
        return new WP_Error('not_found', 'no rule for that source', ['status' => 404]);
    }
    if (!bulkseme_redirect_user_can('rankmath')) {
        return bulkseme_redirect_forbidden();
    }
    if (!$id && $from !== null) {
        $existing = bulkseme_redirect_rankmath_find($from);
        $id       = $existing ? $existing['id'] : 0;
    }
    if (!$id) {
        return new WP_Error('not_found', 'no active rule for that source', ['status' => 404]);
    }
    $rule = \RankMath\Redirections\Redirection::create($id);
    if ($rule->is_new()) {
        return new WP_Error('not_found', 'no Rank Math rule with that id', ['status' => 404]);
    }
    $rule->set_status('trashed');
    $rule->save();
    do_action('bulkseme_redirect_deleted', ['engine' => 'rankmath', 'id' => $id], get_current_user_id());
    return ['engine' => 'rankmath', 'id' => $id, 'deleted' => true, 'note' => 'moved to the Rank Math redirections trash'];
}

add_action('rest_api_init', function () {
    $admin = function () {
        return current_user_can('manage_options');
    };

    register_rest_route('seo-meta-bridge/v1', '/redirect', [
        [
            'methods'             => 'GET',
            'permission_callback' => $admin,
            'callback'            => 'bulkseme_rest_redirect_list',
        ],
        [
            'methods'             => 'POST',
            'permission_callback' => $admin,
            'callback'            => 'bulkseme_rest_redirect_save',
        ],
        [
            'methods'             => 'DELETE',
            'permission_callback' => $admin,
            'callback'            => 'bulkseme_rest_redirect_delete',
        ],
    ]);
});
