<?php
/**
 * Live SEO panel.
 *
 * Shows what a page really sends to search engines, where people work on it:
 *   - on the site itself, an "SEO" item in the admin bar opens a panel over the
 *     page (overview, quick edit, schema, change history);
 *   - in the block editor, a "Live on the site" panel in the document sidebar;
 *   - in the classic editor, the same panel as a side box.
 * Everything shown is read from the rendered page by js/live-panel.js, so it is
 * true whichever plugin printed it (Yoast SEO, Rank Math, the theme). Edits go
 * through bulkseme_apply_update() / bulkseme_apply_term_update(), the same path
 * and the same rules as POST /bulk, so a panel user can do exactly what that
 * user may do in wp-admin and no more.
 *
 * Change history: every change to an SEO field of a post (title, description,
 * keyword, canonical, robots, social fields, custom schema), from any source,
 * is kept on the post, newest first, so a site owner can see what an AI agent,
 * a script or a colleague changed.
 */

if (!defined('ABSPATH')) exit;

define('BULKSEME_HISTORY_MAX', 30);

/* =========================================================================
 * Change history
 * ========================================================================= */

/** Post meta keys whose changes are kept, as key => label. */
function bulkseme_history_watched() {
    static $map = null;
    if ($map !== null) {
        return $map;
    }
    $labels = [
        'title'       => __('SEO title', 'bulk-seo-meta-editor-for-ai-agents'),
        'description' => __('Meta description', 'bulk-seo-meta-editor-for-ai-agents'),
        'focus_kw'    => __('Focus keyword', 'bulk-seo-meta-editor-for-ai-agents'),
        'canonical'   => __('Canonical URL', 'bulk-seo-meta-editor-for-ai-agents'),
        'noindex'     => __('Noindex', 'bulk-seo-meta-editor-for-ai-agents'),
        'nofollow'    => __('Nofollow', 'bulk-seo-meta-editor-for-ai-agents'),
        'robots'      => __('Robots', 'bulk-seo-meta-editor-for-ai-agents'),
        'og_title'    => __('Social title', 'bulk-seo-meta-editor-for-ai-agents'),
        'og_desc'     => __('Social description', 'bulk-seo-meta-editor-for-ai-agents'),
        'og_image'    => __('Social image', 'bulk-seo-meta-editor-for-ai-agents'),
        'tw_title'    => __('X title', 'bulk-seo-meta-editor-for-ai-agents'),
        'tw_desc'     => __('X description', 'bulk-seo-meta-editor-for-ai-agents'),
        'tw_image'    => __('X image', 'bulk-seo-meta-editor-for-ai-agents'),
    ];
    $map    = [];
    $active = bulkseme_active_keys();
    foreach ((array) $active['keys'] as $alias => $key) {
        $map[$key] = isset($labels[$alias]) ? $labels[$alias] : (string) $alias;
    }
    $map['_bulkseme_schema_jsonld'] = __('Custom schema', 'bulk-seo-meta-editor-for-ai-agents');
    $map['_bulkseme_schema_mode']   = __('Custom schema mode', 'bulk-seo-meta-editor-for-ai-agents');
    return $map;
}

/** The REST route being served, captured when it is dispatched. */
function bulkseme_history_route($set = null) {
    static $route = '';
    if ($set !== null) {
        $route = (string) $set;
    }
    return $route;
}

add_filter('rest_pre_dispatch', function ($result, $server, $request) {
    bulkseme_history_route($request->get_route());
    return $result;
}, 1, 3);
// Cleared once the route's callback has run (this filter fires inside dispatch, also for
// internal rest_do_request calls), so a later write outside REST is not credited to it.
add_filter('rest_request_after_callbacks', function ($response) {
    bulkseme_history_route('');
    return $response;
}, 999);

/** Where the current write comes from, in words a site owner reads easily. */
function bulkseme_history_source() {
    // How the request signed in decides first, so an agent's write never reads as a person's.
    if (bulkseme_key_auth_user()) {
        return __('API key', 'bulk-seo-meta-editor-for-ai-agents');
    }
    if (function_exists('rest_get_authenticated_app_password') && rest_get_authenticated_app_password()) {
        return __('Application password', 'bulk-seo-meta-editor-for-ai-agents');
    }
    // WordPress matches routes without regard to case, so the comparison does the same.
    $route = strtolower(bulkseme_history_route());
    if ($route !== '' || (defined('REST_REQUEST') && REST_REQUEST)) {
        if ($route === '/seo-meta-bridge/v1/live') {
            return __('Live SEO panel', 'bulk-seo-meta-editor-for-ai-agents');
        }
        // The block editor (wp/v2) or Rank Math's editor sidebar, from a signed-in browser.
        $browser = get_current_user_id() && (int) wp_validate_auth_cookie('', 'logged_in') === get_current_user_id();
        if ($browser && (strpos($route, '/wp/v2/') === 0 || strpos($route, '/rankmath/v1/') === 0)) {
            return __('Editor', 'bulk-seo-meta-editor-for-ai-agents');
        }
        return __('REST API', 'bulk-seo-meta-editor-for-ai-agents');
    }
    if (defined('WP_CLI') && WP_CLI) {
        return 'WP-CLI';
    }
    return is_admin() ? __('Editor', 'bulk-seo-meta-editor-for-ai-agents') : __('Site', 'bulk-seo-meta-editor-for-ai-agents');
}

/** A stored value as one line of plain text, cut to 300 characters. */
function bulkseme_history_text($value) {
    // WordPress hands these hooks the value already unserialized.
    if (is_array($value)) {
        $value = implode(', ', array_map('strval', array_filter($value, 'is_scalar')));
    }
    if (!is_scalar($value)) {
        return '';
    }
    $value = wp_strip_all_tags((string) $value);
    return mb_strlen($value) > 300 ? mb_substr($value, 0, 297) . '...' : $value;
}

/** Posts being deleted right now: their meta goes, and no history row may be written back. */
function bulkseme_history_deleting($post_id, $set = null) {
    static $ids = [];
    if ($set !== null) {
        $ids[(int) $post_id] = (bool) $set;
    }
    return !empty($ids[(int) $post_id]);
}
add_action('before_delete_post', function ($post_id) {
    bulkseme_history_deleting($post_id, true);
});
// Attachments are deleted by wp_delete_attachment(), which fires neither hook above.
add_action('delete_attachment', function ($post_id) {
    bulkseme_history_deleting($post_id, true);
});
add_action('deleted_post', function ($post_id) {
    bulkseme_history_deleting($post_id, false);
});
add_action('after_delete_post', function ($post_id) {
    bulkseme_history_deleting($post_id, false);
});

function bulkseme_history_add($post_id, $key, $old, $new) {
    // A revision's meta is not the post's SEO; logging it would replace the post's own history.
    if (bulkseme_history_deleting($post_id) || wp_is_post_revision($post_id)) {
        return;
    }
    $old = bulkseme_history_text($old);
    $new = bulkseme_history_text($new);
    if ($old === $new) {
        return;
    }
    $user = wp_get_current_user();
    $log  = get_post_meta($post_id, '_bulkseme_seo_history', true);
    $log  = is_array($log) ? $log : [];
    // The user id, which nobody can change. The name is looked up when the history is shown,
    // as WordPress does on its revisions screen; the login is never stored or shown.
    array_unshift($log, [
        't'      => time(),
        'k'      => (string) $key,
        'field'  => bulkseme_history_watched()[$key],
        'old'    => $old,
        'new'    => $new,
        'uid'    => (int) $user->ID,
        'source' => bulkseme_history_source(),
    ]);
    // The history key is not in the watched list, so this write does not log itself.
    // update_post_meta() unslashes what it is given, so slash it or every backslash is lost.
    update_post_meta($post_id, '_bulkseme_seo_history', wp_slash(array_slice($log, 0, BULKSEME_HISTORY_MAX)));
}

// Before an update the old value is still stored.
add_action('update_post_meta', function ($meta_id, $post_id, $key, $value) {
    if (isset(bulkseme_history_watched()[$key])) {
        bulkseme_history_add((int) $post_id, $key, get_post_meta($post_id, $key, true), $value);
    }
}, 10, 4);
add_action('added_post_meta', function ($meta_id, $post_id, $key, $value) {
    if (isset(bulkseme_history_watched()[$key])) {
        bulkseme_history_add((int) $post_id, $key, '', $value);
    }
}, 10, 4);
add_action('delete_post_meta', function ($meta_ids, $post_id, $key, $value) {
    if ($post_id && isset(bulkseme_history_watched()[$key])) {
        bulkseme_history_add((int) $post_id, $key, get_post_meta($post_id, $key, true), '');
    }
}, 10, 4);

/**
 * The history of one post, for a user who may edit it. Entries for a group of
 * settings the SEO plugin keeps from this user (advanced, social, schema) are
 * left out, so the history never shows what the panel itself would hide.
 */
function bulkseme_history_for($post_id) {
    if (!current_user_can('edit_post', $post_id)) {
        return [];
    }
    $log = get_post_meta($post_id, '_bulkseme_seo_history', true);
    $out = [];
    foreach ((is_array($log) ? $log : []) as $e) {
        if (!is_array($e) || empty($e['k'])) {
            continue;
        }
        $group = strpos($e['k'], '_bulkseme_schema_') === 0 ? 'schema' : bulkseme_field_group($e['k']);
        if (!bulkseme_user_can_seo_group($group)) {
            continue;
        }
        $who = !empty($e['uid']) ? get_userdata((int) $e['uid']) : false;
        $e['who'] = $who ? $who->display_name : (!empty($e['uid']) ? __('a deleted user', 'bulk-seo-meta-editor-for-ai-agents') : '');
        unset($e['k'], $e['uid']);
        $out[] = $e;
        if (count($out) >= 15) {
            break;
        }
    }
    return $out;
}

/*
 * Rank Math's own REST route deletes any meta key it is sent with an empty
 * value, with no check on the key. Keep this plugin's keys (the history, the
 * stored schema) out of it, so they change only through this plugin's rules.
 */
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Rank Math's own public filter; we are a consumer of it, not the owner.
add_filter('rank_math/filter_metadata', function ($meta) {
    if (!is_array($meta)) {
        return $meta;
    }
    foreach ($meta as $key => $value) {
        // Rank Math unslashes the key before using it (delete_metadata runs wp_unslash),
        // so judge the key in that form. A deletion is also refused for any key that is
        // not plain ASCII: the database may collate such a key equal to one of ours.
        $real = wp_unslash((string) $key);
        // A key that starts with rank_math_ cannot collate to one of ours, whatever follows.
        $theirs = strpos($real, 'rank_math_') === 0;
        if (stripos(ltrim($real), '_bulkseme_') === 0 || (empty($value) && !$theirs && !preg_match('/^[A-Za-z0-9_\-]+$/', $real))) {
            unset($meta[$key]);
        }
    }
    return $meta;
}, 999);

/*
 * Core can also rename or overwrite a meta row by its database id
 * (update_metadata_by_mid), which skips every check on the key. Rank Math's
 * schema route does that with an id taken from the request. This plugin never
 * changes its own rows that way, so such a write to a _bulkseme_ row is
 * refused. Deletes by id are left alone: core uses them to clear an object's
 * meta when the object itself is deleted, and its interactive delete-by-id
 * paths already refuse protected keys.
 */
function bulkseme_refuse_own_row_by_mid($check, $meta_id, $type) {
    if ($check !== null) {
        return $check;
    }
    $row = get_metadata_by_mid($type, (int) $meta_id);
    if ($row && stripos((string) $row->meta_key, '_bulkseme_') === 0) {
        return false;
    }
    return $check;
}
foreach (['post', 'term'] as $bulkseme_meta_type) {
    add_filter("update_{$bulkseme_meta_type}_metadata_by_mid", function ($check, $meta_id) use ($bulkseme_meta_type) {
        return bulkseme_refuse_own_row_by_mid($check, $meta_id, $bulkseme_meta_type);
    }, 999, 2);
}
unset($bulkseme_meta_type);

/** True for a taxonomy WordPress gives people a screen or a REST route for. */
function bulkseme_live_taxonomy_ok($taxonomy) {
    $tax = get_taxonomy($taxonomy);
    return $tax && ($tax->show_ui || $tax->show_in_rest);
}

/* =========================================================================
 * What the panel is about
 * ========================================================================= */

/**
 * The object in view on the front end, or null when the panel should not show.
 * Posts and terms need the per-object edit right; archives, search and 404
 * pages are shown read-only to administrators.
 */
function bulkseme_live_target() {
    if (is_admin() || !is_user_logged_in() || !is_admin_bar_showing()) {
        return null;
    }
    $obj = get_queried_object();
    if ($obj instanceof WP_Post && (is_singular() || is_home()) && current_user_can('edit_post', $obj->ID)) {
        $pto = get_post_type_object($obj->post_type);
        return ['kind' => 'post', 'id' => (int) $obj->ID, 'label' => $pto ? $pto->labels->singular_name : 'Page', 'edit' => (string) get_edit_post_link($obj->ID, 'raw')];
    }
    if ($obj instanceof WP_Term && bulkseme_live_taxonomy_ok($obj->taxonomy) && current_user_can('edit_term', $obj->term_id)) {
        $tax = get_taxonomy($obj->taxonomy);
        return ['kind' => 'term', 'id' => (int) $obj->term_id, 'label' => $tax ? $tax->labels->singular_name : 'Term', 'edit' => (string) get_edit_term_link($obj->term_id, $obj->taxonomy)];
    }
    if (current_user_can('manage_options')) {
        return ['kind' => 'view', 'id' => 0, 'label' => 'Page', 'edit' => ''];
    }
    return null;
}

/** What the current user may change from the panel, and the stored values. */
function bulkseme_live_values(array $t) {
    $out = [
        'title' => '', 'description' => '', 'focus_kw' => '', 'schema' => '', 'schema_mode' => 'add',
        'can_fields' => false, 'can_schema' => false,
    ];
    if ($t['kind'] === 'post') {
        $active = bulkseme_active_keys();
        $out['can_fields'] = (bool) $active['plugin'] && bulkseme_user_can_seo_group('general');
        foreach ($out['can_fields'] ? ['title', 'description', 'focus_kw'] : [] as $alias) {
            if (isset($active['keys'][$alias])) {
                $out[$alias] = (string) get_post_meta($t['id'], $active['keys'][$alias], true);
            }
        }
        $raw = get_post_meta($t['id'], '_bulkseme_schema_jsonld', true);
        $out['schema_mode'] = bulkseme_schema_mode_for_post($t['id']);
    } elseif ($t['kind'] === 'term') {
        $term   = get_term($t['id']);
        $active = bulkseme_active_term_keys();
        $out['can_fields'] = (bool) $active['plugin'] && bulkseme_user_can_seo_group('general');
        foreach ($out['can_fields'] ? ['title', 'description', 'focus_kw'] : [] as $alias) {
            if (isset($active['keys'][$alias]) && $term && !is_wp_error($term)) {
                $v = bulkseme_term_get_value($t['id'], $term->taxonomy, $active['keys'][$alias], $active['plugin']);
                $out[$alias] = is_scalar($v) ? (string) $v : '';
            }
        }
        $raw = get_term_meta($t['id'], '_bulkseme_schema_jsonld', true);
        $out['schema_mode'] = bulkseme_schema_mode_for_term($t['id']);
    } else {
        return $out;
    }
    $out['can_schema'] = bulkseme_user_can_seo_group('schema');
    if ($out['can_schema'] && is_string($raw) && $raw !== '') {
        $decoded       = json_decode($raw, true);
        $out['schema'] = is_array($decoded) ? (string) wp_json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : '';
    }
    return $out;
}

/**
 * True only for a WooCommerce product. Elsewhere the panel does not ask for offers or
 * prices, because nothing is sold there and a price nobody gave would be made up.
 */
function bulkseme_live_is_product($post_id) {
    return function_exists('wc_get_product') && get_post_type($post_id) === 'product';
}

/** The name of the plugin printing the SEO tags, for the panel's wording. */
function bulkseme_live_engine() {
    $plugin = bulkseme_active_keys()['plugin'];
    if ($plugin === 'yoast') {
        return 'Yoast SEO';
    }
    if ($plugin === 'rankmath') {
        return 'Rank Math';
    }
    return __('the theme', 'bulk-seo-meta-editor-for-ai-agents');
}

/* =========================================================================
 * Front end: admin bar item and the panel
 * ========================================================================= */

add_action('admin_bar_menu', function ($bar) {
    if (!bulkseme_live_target()) {
        return;
    }
    $bar->add_node([
        'id'    => 'bulkseme-live',
        'title' => '<span class="ab-icon dashicons dashicons-search" aria-hidden="true"></span><span class="ab-label">SEO</span><span class="bulkseme-live-count" hidden></span>',
        'href'  => '#bulkseme-live',
        'meta'  => ['title' => __('What this page sends to search engines', 'bulk-seo-meta-editor-for-ai-agents')],
    ]);
}, 90);

add_action('wp_enqueue_scripts', function () {
    $t = bulkseme_live_target();
    if (!$t) {
        return;
    }
    wp_enqueue_script('bulkseme-live', plugins_url('js/live-panel.js', BULKSEME_FILE), [], BULKSEME_VERSION, true);
    wp_localize_script('bulkseme-live', 'bulksemeLive', [
        'mode'    => 'site',
        'rest'    => esc_url_raw(rest_url('seo-meta-bridge/v1/live')),
        // A read-only view has nothing to save, so it gets no nonce.
        'nonce'   => $t['kind'] === 'view' ? '' : wp_create_nonce('wp_rest'),
        'target'  => $t,
        'engine'  => bulkseme_live_engine(),
        'commerce' => $t['kind'] === 'post' && bulkseme_live_is_product($t['id']),
        'values'  => bulkseme_live_values($t),
        'history' => $t['kind'] === 'post' ? bulkseme_history_for($t['id']) : [],
        'site'    => get_bloginfo('name', 'display'),
    ]);
    // The admin bar badge. The panel itself styles a shadow root, so no stylesheet reaches the theme.
    wp_add_inline_style('admin-bar', '#wpadminbar #wp-admin-bar-bulkseme-live .ab-icon:before{top:2px}#wpadminbar .bulkseme-live-count{display:inline-block;margin-left:6px;min-width:18px;height:18px;padding:0 5px;border-radius:3px;background:#d92d20;color:#fff;font:600 11px/18px system-ui,sans-serif;text-align:center}#wpadminbar .bulkseme-live-count.warn{background:#f8c33c;color:#3a2800;border-radius:9px}#wpadminbar .bulkseme-live-count[hidden]{display:none}');
});

/* =========================================================================
 * Editors: the same live view beside the post
 * ========================================================================= */

add_action('add_meta_boxes', function ($post_type, $post) {
    if (!($post instanceof WP_Post) || !current_user_can('edit_post', $post->ID)) {
        return;
    }
    $pto = get_post_type_object($post_type);
    if (!$pto || !$pto->public || $post_type === 'attachment') {
        return;
    }
    // The block editor gets a sidebar panel from the script; the box serves the classic editor.
    $block = function_exists('use_block_editor_for_post') && use_block_editor_for_post($post);
    add_meta_box('bulkseme-live', __('Live on the site', 'bulk-seo-meta-editor-for-ai-agents'), function () {
        echo '<div id="bulkseme-live-box"><p>' . esc_html__('Reading the live page...', 'bulk-seo-meta-editor-for-ai-agents') . '</p></div>';
    }, $post_type, 'side', 'default', ['__back_compat_meta_box' => $block, '__block_editor_compatible_meta_box' => !$block]);
}, 10, 2);

add_action('admin_enqueue_scripts', function ($hook) {
    if (!in_array($hook, ['post.php', 'post-new.php'], true)) {
        return;
    }
    $post = get_post();
    if (!$post || !current_user_can('edit_post', $post->ID)) {
        return;
    }
    $pto = get_post_type_object($post->post_type);
    if (!$pto || !$pto->public || $post->post_type === 'attachment') {
        return;
    }
    $block = function_exists('use_block_editor_for_post') && use_block_editor_for_post($post);
    wp_enqueue_script('bulkseme-live', plugins_url('js/live-panel.js', BULKSEME_FILE), $block ? ['wp-plugins', 'wp-element', 'wp-data', 'wp-editor'] : [], BULKSEME_VERSION, true);
    $published = $post->post_status === 'publish';
    wp_localize_script('bulkseme-live', 'bulksemeLive', [
        'mode'      => $block ? 'block' : 'classic',
        'url'       => $published ? (string) get_permalink($post) : '',
        'published' => $published,
        'engine'    => bulkseme_live_engine(),
        'commerce'  => bulkseme_live_is_product($post->ID),
        'history'   => bulkseme_history_for($post->ID),
        'target'    => ['kind' => 'post', 'id' => (int) $post->ID, 'label' => $pto->labels->singular_name, 'edit' => ''],
    ]);
});

/* =========================================================================
 * POST /live: saves from the panel
 * ========================================================================= */

add_action('rest_api_init', function () {
    register_rest_route('seo-meta-bridge/v1', '/live', [
        'methods'             => 'POST',
        'permission_callback' => function (WP_REST_Request $req) {
            // A signed-in browser with a REST nonce only: keys and application
            // passwords have /bulk for this. Then the same route-level gate as
            // /bulk, and the per-object edit right.
            if (!bulkseme_is_browser_session($req) || !current_user_can('edit_posts')) {
                return false;
            }
            $id = bulkseme_param_id($req->get_param('id'));
            if (!$id) {
                return false;
            }
            if ($req->get_param('kind') === 'term') {
                // Terms of a taxonomy WordPress offers no screen or route for stay out of reach.
                $term = get_term($id);
                return $term && !is_wp_error($term) && bulkseme_live_taxonomy_ok($term->taxonomy) && current_user_can('edit_term', $id);
            }
            return current_user_can('edit_post', $id);
        },
        // No argument schema: WordPress checks one before the permission callback, so a missing
        // field would answer 400 to a caller who should get 401 or 403. Checked here instead.
        'callback'            => function (WP_REST_Request $req) {
            $id   = bulkseme_param_id($req->get_param('id'));
            $kind = $req->get_param('kind');
            $in   = $req->get_param('meta');
            if (!in_array($kind, ['post', 'term'], true) || !$id || !is_array($in)) {
                return new WP_Error('invalid_request', __('Send kind (post or term), id and meta.', 'bulk-seo-meta-editor-for-ai-agents'), ['status' => 400]);
            }
            $meta = [];
            // Only these fields; each one passes the same checks as in POST /bulk.
            foreach (['title', 'description', 'focus_kw', 'schema', 'schema_mode'] as $k) {
                if (array_key_exists($k, $in) && is_string($in[$k])) {
                    $meta[$k] = $in[$k];
                }
            }
            if (!$meta) {
                return new WP_Error('nothing_to_save', __('Nothing to save.', 'bulk-seo-meta-editor-for-ai-agents'), ['status' => 400]);
            }
            if ($kind === 'term') {
                $term = get_term($id);
                if (!$term || is_wp_error($term)) {
                    return new WP_Error('term_not_found', __('Term not found.', 'bulk-seo-meta-editor-for-ai-agents'), ['status' => 404]);
                }
                $res = bulkseme_apply_term_update($id, $term->taxonomy, $meta);
            } else {
                $res = bulkseme_apply_update($id, $meta);
            }
            return rest_ensure_response([
                'ok'     => !empty($res['ok']),
                'errors' => isset($res['errors']) ? array_values((array) $res['errors']) : [],
            ]);
        },
    ]);
});
