<?php
/**
 * Page content: read and edit the visible copy of a page or post.
 *
 *   GET  /content?id=N            which builder owns the page, and its text as addressable nodes
 *   POST /content                 change nodes ({el, setting, value}) or the post content
 *   POST /content/replace         literal find and replace across the page's text
 *   GET  /content/backups?id=N    what can be restored
 *   POST /content/revert          put a backup back
 *
 * Gutenberg, classic, Divi and WPBakery keep their content in post_content,
 * which core REST can already write. Elementor, Beaver Builder, SiteOrigin and
 * Advanced Custom Fields keep the copy in post meta, where core REST cannot
 * reach it, so each has its own reader and writer (includes/content-*.php).
 *
 * Rules every path follows:
 *   - The caller must be allowed to edit that post (edit_post), and, where the
 *     page builder has its own rule for who may use it, that rule too.
 *   - Every value is sanitised with the post content allowlist (wp_kses_post).
 *   - Every write first stores a backup of what was there (includes/backups.php).
 *   - Only the targeted text changes. Layout, styling and unknown widgets keep
 *     the values they were read with.
 *   - dry_run reports what would change and writes nothing. base_hash refuses
 *     the write when the page changed since it was read.
 */

if (!defined('ABSPATH')) exit;

/**
 * May this request store HTML exactly as it was sent?
 *
 * No, by default: every value goes through wp_kses_post, whoever sends it. A
 * site owner can allow it with the bulkseme_allow_unfiltered_html filter. Even
 * then the caller must hold WordPress's own unfiltered_html capability and the
 * request must ask for it with unsafe_html=1.
 */
function bulkseme_content_raw_html_allowed($requested) {
    if (!$requested || !current_user_can('unfiltered_html')) {
        return false;
    }
    return (bool) apply_filters('bulkseme_allow_unfiltered_html', false);
}

/**
 * The page builder's own rule for who may edit with it, on top of edit_post.
 *
 * Elementor decides per document (its excluded roles, the post types it is
 * switched on for, a capability per document type) and Beaver Builder has a
 * builder access setting. A user those keep out of the builder is kept out here
 * as well, on every route, so this API is never a way around them.
 */
function bulkseme_user_can_use_builder($builder, $post) {
    $allowed = true;
    if ($builder === 'elementor') {
        $allowed = bulkseme_elementor_user_can_edit($post);
    } elseif ($builder === 'beaver') {
        $allowed = bulkseme_beaver_user_can_edit($post);
    }
    /**
     * Filter: whether the current user may edit this post's content with this builder.
     *
     * @param bool    $allowed
     * @param string  $builder elementor, beaver, siteorigin, accordions, acf, gutenberg, classic, divi, wpbakery.
     * @param WP_Post $post
     */
    return (bool) apply_filters('bulkseme_user_can_edit_with_builder', $allowed, $builder, $post);
}

/** WP_Error for a caller the builder's own rule turns away, or true. */
function bulkseme_content_builder_gate($builder, $post) {
    if (bulkseme_user_can_use_builder($builder, $post)) {
        return true;
    }
    return new WP_Error('forbidden_builder', 'your account may not edit content built with ' . $builder . ' on this site', ['status' => 403]);
}

/** True when the Elementor plugin is loaded and its singletons are usable. */
function bulkseme_elementor_active() {
    return class_exists('\Elementor\Plugin') && did_action('elementor/loaded');
}

/**
 * Identify which builder owns a post's content and whether we can edit it.
 * Most-specific signals first; `active` distinguishes a running builder from
 * orphaned meta left behind by a deactivated one.
 */
function bulkseme_detect_builder($post) {
    $id = $post->ID;
    if ($post->post_type === 'elementor_library') {
        // Elementor (Pro) template: headers/footers/popups/global widgets.
        // Edits here can render site-wide, which the GET response flags.
        return ['builder' => 'elementor', 'active' => bulkseme_elementor_active(), 'editable' => true];
    }
    $el_data = get_post_meta($id, '_elementor_data', true);
    if (get_post_meta($id, '_elementor_edit_mode', true) === 'builder' && is_string($el_data) && $el_data !== '') {
        return ['builder' => 'elementor', 'active' => bulkseme_elementor_active(), 'editable' => true];
    }
    if ($post->post_type === 'accordions') {
        // "Accordions" plugin: FAQ items live in accordions_options, never in post_content.
        return ['builder' => 'accordions', 'active' => bulkseme_accordions_active(), 'editable' => is_array(get_post_meta($id, 'accordions_options', true))];
    }
    $so_data = get_post_meta($id, 'panels_data', true);
    if (is_array($so_data) && (!empty($so_data['widgets']) || !empty($so_data['grids']))) {
        // SiteOrigin Page Builder: post_content is only a rendered mirror of panels_data.
        return ['builder' => 'siteorigin', 'active' => bulkseme_siteorigin_active(), 'editable' => true];
    }
    $bb_data = get_post_meta($id, '_fl_builder_data', true);
    if (get_post_meta($id, '_fl_builder_enabled', true) || (is_array($bb_data) && $bb_data)) {
        // Beaver Builder: the layout lives in _fl_builder_data (published) and
        // _fl_builder_draft; post_content is its stripped mirror. Editable
        // through the node handlers below whenever a published layout exists.
        return ['builder' => 'beaver', 'active' => bulkseme_beaver_active(), 'editable' => is_array($bb_data) && (bool) $bb_data];
    }
    if (get_post_meta($id, '_bricks_page_content_2', true)) {
        return ['builder' => 'bricks', 'active' => defined('BRICKS_VERSION'), 'editable' => false];
    }
    if (get_post_meta($id, 'ct_builder_shortcodes', true) || get_post_meta($id, '_ct_builder_json', true)) {
        return ['builder' => 'oxygen', 'active' => defined('CT_VERSION'), 'editable' => false];
    }
    $content = (string) $post->post_content;
    if (trim($content) === '' && bulkseme_acf_active() && bulkseme_acf_has_text($id)) {
        // Theme keeps the page copy in ACF fields (flexible content, repeaters,
        // groups) and leaves post_content empty: edit the ACF text leaves.
        return ['builder' => 'acf', 'active' => true, 'editable' => true];
    }
    if (get_post_meta($id, '_et_pb_use_builder', true) === 'on' || strpos($content, '[et_pb_section') !== false) {
        return ['builder' => 'divi', 'active' => defined('ET_BUILDER_VERSION') || defined('ET_CORE_VERSION'), 'editable' => true];
    }
    if (get_post_meta($id, '_wpb_vc_js_status', true) === 'true' || strpos($content, '[vc_row') !== false) {
        return ['builder' => 'wpbakery', 'active' => defined('WPB_VC_VERSION'), 'editable' => true];
    }
    if (function_exists('has_blocks') && has_blocks($content)) {
        return ['builder' => 'gutenberg', 'active' => true, 'editable' => true];
    }
    return ['builder' => 'classic', 'active' => true, 'editable' => true];
}

/**
 * Which builders are running, for /status. Version numbers are only included
 * for callers who could read them on the Plugins screen anyway.
 */
function bulkseme_builders_status($with_versions = false) {
    $list = [
        'elementor'  => [bulkseme_elementor_active(), defined('ELEMENTOR_VERSION') ? ELEMENTOR_VERSION : null],
        'beaver'     => [class_exists('FLBuilderModel'), defined('FL_BUILDER_VERSION') ? FL_BUILDER_VERSION : null],
        'divi'       => [defined('ET_BUILDER_VERSION') || defined('ET_CORE_VERSION'), defined('ET_BUILDER_VERSION') ? ET_BUILDER_VERSION : null],
        'wpbakery'   => [defined('WPB_VC_VERSION'), defined('WPB_VC_VERSION') ? WPB_VC_VERSION : null],
        'bricks'     => [defined('BRICKS_VERSION'), defined('BRICKS_VERSION') ? BRICKS_VERSION : null],
        'oxygen'     => [defined('CT_VERSION'), defined('CT_VERSION') ? CT_VERSION : null],
        'siteorigin' => [bulkseme_siteorigin_active(), defined('SITEORIGIN_PANELS_VERSION') ? SITEORIGIN_PANELS_VERSION : null],
        'accordions' => [bulkseme_accordions_active(), defined('accordions_version') ? constant('accordions_version') : null],
        'acf'        => [bulkseme_acf_active(), bulkseme_acf_version()],
    ];
    $out = [];
    foreach ($list as $name => $row) {
        $out[$name] = ['active' => (bool) $row[0]];
        if ($with_versions) {
            $out[$name]['version'] = $row[1];
        }
    }
    return $out;
}

/**
 * Sanitize an incoming value for a control type. wp_kses_post across the
 * board: WYSIWYG/TEXTAREA settings keep safe HTML while scripts/handlers are
 * stripped, and headings legitimately carry <br>/<strong>, which
 * sanitize_text_field would destroy. Those render in HTML body context.
 *
 * Single-line TEXT controls get extra treatment: newlines collapsed, and the
 * quote characters neutralized to entities. wp_kses_post is a body-context
 * filter - it leaves a bracket-free payload like  x" onclick="alert(1)  fully
 * intact - and TEXT controls are the ones a widget is most likely to echo into
 * an HTML attribute (title=, placeholder=, data-*). Encoding the quotes makes
 * the value safe in an attribute AND unchanged to the eye in body context
 * (entities render as literal quotes there). The replace is idempotent: once a
 * literal quote is an entity it holds no bare quote to re-encode.
 */
function bulkseme_content_sanitize($value, $ctl_type) {
    $clean = wp_kses_post(bulkseme_content_utf8($value));
    if ($ctl_type === 'text') {
        $one   = preg_replace('/\s*\R+\s*/u', ' ', $clean);
        $clean = trim(is_string($one) ? $one : $clean);
        $clean = str_replace(['"', "'"], ['&quot;', '&#039;'], $clean);
    }
    return $clean;
}

/**
 * HTML for a field of a widget or module this plugin has no map for.
 *
 * Where such a widget prints the value is not known. Quotes in the text between
 * tags are therefore stored as entities: in the page body they read exactly the
 * same, and inside an HTML attribute they cannot end it. Tags and their own
 * attributes stay as the content filter left them.
 */
function bulkseme_content_guard_quotes($html) {
    $parts = wp_html_split((string) $html);
    foreach ($parts as $i => $part) {
        if ($part !== '' && $part[0] !== '<') {
            $parts[$i] = str_replace(['"', "'"], ['&quot;', '&#039;'], $part);
        }
    }
    return implode('', $parts);
}

/**
 * A value as valid UTF-8 text. Bytes that are not valid UTF-8 are dropped here,
 * once, so that nothing further down (a pattern match, JSON encoding, the
 * database) fails on them and empties the field.
 */
function bulkseme_content_utf8($value) {
    $clean = wp_check_invalid_utf8((string) $value, true);
    return is_string($clean) ? $clean : '';
}

// ---- REST handlers -----------------------------------------------------------

/**
 * Bump a post's modified date after a write that only changed its meta, so
 * page caches, sitemaps and the SEO plugin's own index notice the change.
 */
function bulkseme_content_touch($post_id) {
    $post = get_post((int) $post_id);
    if (!$post) {
        return;
    }
    // wp_update_post() sends the stored title, content and excerpt through the
    // save filters again (kses, for a user without unfiltered_html), which can
    // rewrite markup nobody asked to change. Only the modified date is wanted
    // here, so those fields go back exactly as they are stored: for this post on
    // this site, and for this one save only (a save another plugin starts from a
    // hook during it is left alone).
    $blog  = get_current_blog_id();
    $fired = false;
    $keep  = function ($data, $postarr) use ($post, $blog, &$fired) {
        if ($fired || get_current_blog_id() !== $blog || !is_array($postarr) || !isset($postarr['ID']) || (int) $postarr['ID'] !== (int) $post->ID) {
            return $data;
        }
        $fired                         = true;
        $data['post_title']            = wp_slash($post->post_title);
        $data['post_content']          = wp_slash($post->post_content);
        $data['post_excerpt']          = wp_slash($post->post_excerpt);
        $data['post_content_filtered'] = wp_slash($post->post_content_filtered);
        return $data;
    };
    add_filter('wp_insert_post_data', $keep, PHP_INT_MAX, 2);
    add_filter('wp_insert_attachment_data', $keep, PHP_INT_MAX, 2); // attachments are saved through this one instead
    try {
        wp_update_post(['ID' => (int) $post->ID]);
    } finally {
        remove_filter('wp_insert_post_data', $keep, PHP_INT_MAX);
        remove_filter('wp_insert_attachment_data', $keep, PHP_INT_MAX);
    }
}

/**
 * Shared entry guards for every /content route. Returns WP_Post or WP_Error.
 *
 * The caller must be allowed to edit this post, and the builder that owns its
 * content must let them in as well. $det receives the detected builder.
 */
function bulkseme_content_load_post($id, &$det = null) {
    $post = $id ? get_post($id) : null;
    // Only a post of a type with an edit screen or a REST route of its own is
    // page content: see bulkseme_post_type_reachable().
    if (!$post || $post->post_status === 'trash' || !bulkseme_post_type_reachable($post->post_type)) {
        return new WP_Error('post_not_found', 'post not found', ['status' => 404]);
    }
    if (!current_user_can('edit_post', $post->ID)) {
        return new WP_Error('forbidden', 'cannot edit this post', ['status' => 403]);
    }
    $det  = bulkseme_detect_builder($post);
    $gate = bulkseme_content_builder_gate($det['builder'], $post);
    if (is_wp_error($gate)) {
        return $gate;
    }
    return $post;
}

function bulkseme_rest_content_get(WP_REST_Request $req) {
    if ($req->get_param('kind') === 'term') {
        return bulkseme_rest_term_content_get($req);
    }
    $post = bulkseme_content_load_post(bulkseme_param_id($req->get_param('id')), $det);
    if (is_wp_error($post)) {
        return $post;
    }
    $id  = $post->ID;
    $out = [
        'id'             => $id,
        'post_type'      => $post->post_type,
        'builder'        => $det['builder'],
        'builder_active' => $det['active'],
        'editable'       => $det['editable'],
        'backups'        => count(bulkseme_content_backup_ids($id)),
    ];
    if ($det['builder'] === 'elementor') {
        $raw = get_post_meta($id, '_elementor_data', true);
        if (!is_string($raw) || $raw === '') {
            $raw = '[]';
        }
        $tree = json_decode($raw, true);
        if (!is_array($tree)) {
            return new WP_Error('invalid_elementor_data', '_elementor_data is not valid JSON', ['status' => 500]);
        }
        $out['elementor_version'] = (string) get_post_meta($id, '_elementor_version', true);
        if (defined('ELEMENTOR_VERSION')) {
            $out['elementor_plugin_version'] = ELEMENTOR_VERSION;
        }
        $out['template_type'] = (string) get_post_meta($id, '_elementor_template_type', true);
        $out['is_template']   = $post->post_type === 'elementor_library';
        $out['data_hash']     = md5($raw);
        $out['locked_by']     = bulkseme_elementor_locked_by($id);
        $out['introspection'] = bulkseme_elementor_active() ? 'live' : 'static_fallback';
        $out['nodes']         = bulkseme_elementor_flatten($tree);
        if ($req->get_param('raw')) {
            $out['raw'] = $tree;
        }
        return $out;
    }
    if ($det['builder'] === 'siteorigin') {
        $pd = bulkseme_so_load($id);
        if (is_wp_error($pd)) {
            return $pd;
        }
        list($nodes, $linked) = bulkseme_so_flatten($pd);
        $out['siteorigin_version'] = defined('SITEORIGIN_PANELS_VERSION') ? SITEORIGIN_PANELS_VERSION : null;
        $out['copy_content']       = bulkseme_siteorigin_active() && function_exists('siteorigin_panels_setting') ? (bool) siteorigin_panels_setting('copy-content') : null;
        $out['data_hash']          = md5(bulkseme_so_encode($pd));
        $out['nodes']              = $nodes;
        $out['linked']             = $linked;
        if ($req->get_param('raw')) {
            $out['raw'] = $pd;
        }
        return $out;
    }
    if ($det['builder'] === 'accordions') {
        $opts = bulkseme_acc_load($id);
        if (is_wp_error($opts)) {
            return $opts;
        }
        $out['data_hash'] = md5(bulkseme_acc_encode($opts));
        $out['wpautop']   = !empty($opts['enable_wpautop']);
        $out['nodes']     = bulkseme_acc_flatten($opts);
        if ($req->get_param('raw')) {
            $out['raw'] = $opts;
        }
        return $out;
    }
    if ($det['builder'] === 'beaver') {
        $pub = bulkseme_bb_load($id);
        if (is_wp_error($pub)) {
            return $pub;
        }
        $draft = bulkseme_bb_load($id, 'draft');
        list($nodes, $globals) = bulkseme_bb_flatten($pub);
        $out['beaver_version'] = defined('FL_BUILDER_VERSION') ? FL_BUILDER_VERSION : null;
        $out['site_wide']      = in_array($post->post_type, ['fl-builder-template', 'fl-theme-layout'], true);
        $out['data_hash']      = md5(bulkseme_bb_encode($pub));
        $out['draft_exists']   = is_array($draft);
        $out['draft_matches']  = is_array($draft) ? bulkseme_bb_copy_matches($pub, $draft) : null;
        $out['global_nodes']   = $globals;
        $out['nodes']          = $nodes;
        if ($req->get_param('raw')) {
            $out['raw'] = json_decode(wp_json_encode($pub), true);
        }
        return $out;
    }
    if ($det['builder'] === 'acf') {
        $data = bulkseme_acf_load($id);
        if (is_wp_error($data)) {
            return $data;
        }
        $out['acf_version'] = bulkseme_acf_version();
        $out['data_hash']   = md5(bulkseme_acf_encode($data));
        $out['nodes']       = bulkseme_acf_public_nodes($data);
        $out['containers']  = bulkseme_acf_public_containers($data);
        $out['skipped']     = $data['skipped'];
        if ($req->get_param('raw')) {
            $out['raw'] = $data['raw'];
        }
        return $out;
    }
    if ($det['editable']) {
        $out['post_content'] = (string) $post->post_content;
        $out['data_hash']    = md5((string) $post->post_content);
        if (bulkseme_acf_active() && bulkseme_user_can_use_builder('acf', $post)) {
            // The main copy is post_content, but ACF text fields on the post
            // (intro blocks, sidebars, FAQ repeaters) are editable too: POST
            // updates[] without post_content addresses these nodes.
            $acf = bulkseme_acf_load($id);
            if (!is_wp_error($acf)) {
                $out['acf'] = [
                    'data_hash'  => md5(bulkseme_acf_encode($acf)),
                    'nodes'      => bulkseme_acf_public_nodes($acf),
                    'containers' => bulkseme_acf_public_containers($acf),
                    'skipped'    => $acf['skipped'],
                ];
            }
        }
        return $out;
    }
    $out['reason'] = 'unsupported_builder_storage';
    return $out;
}

/** Write post_content (Gutenberg/classic/Divi/WPBakery) through the backup pipeline. */
function bulkseme_content_write_post_content($post, $new_content, $dry_run, $unsafe_html) {
    $id    = $post->ID;
    $new_content = bulkseme_content_utf8($new_content);
    $clean       = bulkseme_content_raw_html_allowed($unsafe_html)
        ? $new_content
        : wp_kses_post($new_content); // kses keeps HTML comments, so block markers and shortcodes survive
    $prev = (string) $post->post_content;
    $out  = ['id' => $id, 'dry_run' => (bool) $dry_run];
    if ($clean === $prev) {
        $out['status']    = 'unchanged';
        $out['data_hash'] = md5($prev);
        return $out;
    }
    $out['bytes_before'] = strlen($prev);
    $out['bytes_after']  = strlen($clean);
    if ($dry_run) {
        $out['status']    = 'ok';
        $out['data_hash'] = md5($prev);
        return $out;
    }
    $backup_ts = bulkseme_content_take_backup($id, 'post_content', $prev, 'pre-update');
    if (!$backup_ts) {
        return new WP_Error('backup_failed', 'could not store a backup of the current content; nothing was written', ['status' => 500]);
    }
    $res = wp_update_post(['ID' => $id, 'post_content' => wp_slash($clean)], true);
    if (is_wp_error($res)) {
        return new WP_Error('update_failed', $res->get_error_message(), ['status' => 500]);
    }
    $out['status']    = 'ok';
    $out['backup_ts'] = $backup_ts;
    $out['data_hash'] = md5($clean);
    do_action('bulkseme_content_updated', $id, [['setting' => 'post_content', 'status' => 'ok']], get_current_user_id());
    return $out;
}

/** Commit a patched Elementor tree: backup, encode, write, report. */
function bulkseme_content_commit_elementor($id, $raw, array $tree, array $results, $changed, $dry_run, $note = 'pre-update') {
    $out = [
        'id'      => $id,
        'builder' => 'elementor',
        'dry_run' => (bool) $dry_run,
        'count'   => count($results),
        'changed' => $changed,
        'results' => $results,
    ];
    if ($dry_run || $changed === 0) {
        $out['data_hash'] = md5($raw);
        return $out;
    }
    $json = wp_json_encode($tree);
    if ($json === false) {
        // Nothing has been written at this point - fail whole, never half.
        return new WP_Error('encode_failed', 'could not re-encode _elementor_data; nothing was written', ['status' => 500]);
    }
    $out['backup_ts'] = bulkseme_content_take_backup($id, 'elementor', $raw, $note);
    if (!$out['backup_ts']) {
        return new WP_Error('backup_failed', 'could not store a backup of the current content; nothing was written', ['status' => 500]);
    }
    $out = array_merge($out, bulkseme_elementor_write_data($id, $json));
    $out['data_hash'] = md5($json);
    do_action('bulkseme_content_updated', $id, $results, get_current_user_id());
    return $out;
}

function bulkseme_rest_content_post(WP_REST_Request $req) {
    if ($req->get_param('kind') === 'term') {
        return bulkseme_rest_term_content_post($req);
    }
    $post = bulkseme_content_load_post(bulkseme_param_id($req->get_param('id')), $det);
    if (is_wp_error($post)) {
        return $post;
    }
    $id      = $post->ID;
    $dry_run = (bool) $req->get_param('dry_run');
    $force   = (bool) $req->get_param('force');

    if ($det['builder'] === 'elementor') {
        $updates = $req->get_param('updates');
        if (!is_array($updates) || !$updates) {
            return new WP_Error('invalid_payload', 'elementor pages are edited via a non-empty updates[] array (post_content is derived output)', ['status' => 400]);
        }
        if (count($updates) > 100) {
            return new WP_Error('too_many', 'max 100 updates per request', ['status' => 400]);
        }
        if (!$det['active'] && !$force) {
            return new WP_Error('elementor_inactive', 'Elementor is not active on this site; pass force=1 to patch _elementor_data anyway (post_content will not re-sync and only core widgets are recognized)', ['status' => 409]);
        }
        $raw = get_post_meta($id, '_elementor_data', true);
        if (!is_string($raw) || $raw === '') {
            return new WP_Error('no_elementor_data', 'post has no _elementor_data', ['status' => 409]);
        }
        $base_hash = $req->get_param('base_hash');
        if (is_string($base_hash) && $base_hash !== '' && !hash_equals(md5($raw), $base_hash)) {
            return new WP_Error('hash_conflict', 'page content changed since it was read; re-GET /content and retry', ['status' => 409]);
        }
        $locked = bulkseme_elementor_locked_by($id);
        if ($locked !== null && !$force) {
            return new WP_Error('locked', 'an Elementor editor session by "' . $locked . '" holds the lock; pass force=1 to override', ['status' => 423]);
        }
        $tree = json_decode($raw, true);
        if (!is_array($tree)) {
            return new WP_Error('invalid_elementor_data', '_elementor_data is not valid JSON', ['status' => 500]);
        }
        list($results, $changed) = bulkseme_content_apply_updates($tree, $updates, $force);
        return bulkseme_content_commit_elementor($id, $raw, $tree, $results, $changed, $dry_run);
    }

    if ($det['builder'] === 'siteorigin' || $det['builder'] === 'accordions' || $det['builder'] === 'beaver' || $det['builder'] === 'acf') {
        return bulkseme_rest_content_post_meta_builder($post, $det, $req);
    }
    if (!$det['editable']) {
        return new WP_Error('unsupported_builder', 'content for builder "' . $det['builder'] . '" is stored in a format this version cannot safely edit', ['status' => 501]);
    }
    $acf_updates = $req->get_param('updates');
    if (is_array($acf_updates) && $acf_updates && !is_string($req->get_param('post_content')) && bulkseme_acf_active()) {
        // updates[] on a post_content page targets the ACF nodes GET listed under `acf`.
        $gate = bulkseme_content_builder_gate('acf', $post);
        if (is_wp_error($gate)) {
            return $gate;
        }
        return bulkseme_rest_content_post_meta_builder($post, ['builder' => 'acf', 'active' => true, 'editable' => true], $req);
    }
    $content = $req->get_param('post_content');
    if (!is_string($content)) {
        return new WP_Error('invalid_payload', 'post_content (string) is required for builder "' . $det['builder'] . '"', ['status' => 400]);
    }
    $out = bulkseme_content_write_post_content($post, $content, $dry_run, (bool) $req->get_param('unsafe_html'));
    if (is_wp_error($out)) {
        return $out;
    }
    $out['builder'] = $det['builder'];
    if (!$dry_run && $det['builder'] === 'wpbakery' && $req->get_param('rebuild_wpb_css')) {
        $out['wpb_css_bytes'] = bulkseme_wpb_rebuild_custom_css($id);
    }
    return $out;
}

function bulkseme_rest_content_replace(WP_REST_Request $req) {
    if ($req->get_param('kind') === 'term') {
        return new WP_Error('unsupported_for_terms', 'find/replace is not available for term descriptions; GET the description, edit it and POST it back with kind=term', ['status' => 501]);
    }
    $post = bulkseme_content_load_post(bulkseme_param_id($req->get_param('id')), $det);
    if (is_wp_error($post)) {
        return $post;
    }
    $id   = $post->ID;
    $find = $req->get_param('find');
    if (!is_string($find) || $find === '') {
        return new WP_Error('invalid_payload', 'find (non-empty string) is required', ['status' => 400]);
    }
    $replace = $req->get_param('replace');
    $replace = is_string($replace) ? $replace : '';
    // Bound the strings so a huge replace can't multiplicatively inflate the
    // tree across every matching node (memory / CPU amplification).
    if (strlen($find) > 2000 || strlen($replace) > 2000) {
        return new WP_Error('invalid_payload', 'find/replace are limited to 2000 characters each', ['status' => 400]);
    }
    $dry_run = (bool) $req->get_param('dry_run');
    $force   = (bool) $req->get_param('force');
    $case    = $req->get_param('case_sensitive');
    $case    = ($case === null) ? true : (bool) $case;

    if ($det['builder'] === 'elementor') {
        if (!$det['active'] && !$force) {
            return new WP_Error('elementor_inactive', 'Elementor is not active on this site; pass force=1 to patch _elementor_data anyway', ['status' => 409]);
        }
        $raw = get_post_meta($id, '_elementor_data', true);
        if (!is_string($raw) || $raw === '') {
            return new WP_Error('no_elementor_data', 'post has no _elementor_data', ['status' => 409]);
        }
        $locked = bulkseme_elementor_locked_by($id);
        if ($locked !== null && !$force) {
            return new WP_Error('locked', 'an Elementor editor session by "' . $locked . '" holds the lock; pass force=1 to override', ['status' => 423]);
        }
        $tree = json_decode($raw, true);
        if (!is_array($tree)) {
            return new WP_Error('invalid_elementor_data', '_elementor_data is not valid JSON', ['status' => 500]);
        }
        // Build an updates[] batch from every matching text node, then run it
        // through the exact same validation/sanitization path as POST /content.
        $updates = [];
        $matches = [];
        foreach (bulkseme_elementor_flatten($tree) as $node) {
            if (empty($node['editable']) || empty($node['setting']) || !empty($node['dynamic'])) {
                continue;
            }
            if (isset($node['kind']) && $node['kind'] !== 'url') {
                continue; // heading levels and custom CSS are never find/replace targets; link URLs are
            }
            $old = (string) $node['text'];
            if ($old === '') {
                continue;
            }
            $count = 0;
            $new   = $case
                ? str_replace($find, $replace, $old, $count)
                : str_ireplace($find, $replace, $old, $count);
            if ($count > 0 && $new !== $old) {
                $updates[] = ['el' => $node['el'], 'setting' => $node['setting'], 'value' => $new];
                $matches[] = [
                    'el' => $node['el'], 'widget' => $node['widget'], 'setting' => $node['setting'],
                    'occurrences' => $count, 'before' => $old, 'after' => $new,
                ];
            }
        }
        if (!$updates) {
            return ['id' => $id, 'builder' => 'elementor', 'dry_run' => $dry_run, 'matched_nodes' => 0, 'changed' => 0, 'matches' => [], 'data_hash' => md5($raw)];
        }
        // Same 100-node ceiling as POST /content - refuse rather than churn a
        // huge tree; the caller can narrow `find` or edit nodes directly.
        if (count($updates) > 100) {
            return new WP_Error('too_many', 'find matched ' . count($updates) . ' nodes (max 100); use a more specific find string', ['status' => 400]);
        }
        list($results, $changed) = bulkseme_content_apply_updates($tree, $updates, $force);
        $out = bulkseme_content_commit_elementor($id, $raw, $tree, $results, $changed, $dry_run);
        if (is_wp_error($out)) {
            return $out;
        }
        $out['matched_nodes'] = count($matches);
        $out['matches']       = $matches;
        return $out;
    }

    if ($det['builder'] === 'siteorigin' || $det['builder'] === 'accordions' || $det['builder'] === 'beaver' || $det['builder'] === 'acf') {
        return bulkseme_rest_content_replace_meta_builder($post, $det, $find, $replace, $case, $dry_run, $force);
    }
    if (!$det['editable']) {
        return new WP_Error('unsupported_builder', 'content for builder "' . $det['builder'] . '" is stored in a format this version cannot safely edit', ['status' => 501]);
    }
    $old   = (string) $post->post_content;
    $count = 0;
    $new   = $case
        ? str_replace($find, $replace, $old, $count)
        : str_ireplace($find, $replace, $old, $count);
    if ($count === 0 || $new === $old) {
        return ['id' => $id, 'builder' => $det['builder'], 'dry_run' => $dry_run, 'occurrences' => 0, 'status' => 'unchanged', 'data_hash' => md5($old)];
    }
    $out = bulkseme_content_write_post_content($post, $new, $dry_run, false);
    if (is_wp_error($out)) {
        return $out;
    }
    $out['builder']     = $det['builder'];
    $out['occurrences'] = $count;
    return $out;
}

function bulkseme_rest_content_backups(WP_REST_Request $req) {
    if ($req->get_param('kind') === 'term') {
        return bulkseme_rest_term_content_backups($req);
    }
    $post = bulkseme_content_load_post(bulkseme_param_id($req->get_param('id')));
    if (is_wp_error($post)) {
        return $post;
    }
    $items = [];
    foreach (bulkseme_content_backup_ids($post->ID) as $ts) {
        $env = bulkseme_content_read_backup($post->ID, $ts);
        if (!$env) {
            continue;
        }
        $items[] = [
            'ts'      => $ts,
            'kind'    => $env['kind'],
            'note'    => isset($env['note']) ? $env['note'] : '',
            'user'    => isset($env['user']) ? (int) $env['user'] : 0,
            'created' => isset($env['created']) ? $env['created'] : '',
            'bytes'   => strlen($env['data']),
        ];
    }
    return ['id' => $post->ID, 'count' => count($items), 'backups' => $items];
}

function bulkseme_rest_content_revert(WP_REST_Request $req) {
    if ($req->get_param('kind') === 'term') {
        return bulkseme_rest_term_content_revert($req);
    }
    $post = bulkseme_content_load_post(bulkseme_param_id($req->get_param('id')));
    if (is_wp_error($post)) {
        return $post;
    }
    $id = $post->ID;
    $ts = bulkseme_param_id($req->get_param('ts'));
    $env = $ts ? bulkseme_content_read_backup($id, $ts) : null;
    if (!$env) {
        return new WP_Error('backup_not_found', 'no backup with that ts for this post', ['status' => 404]);
    }
    // The backup may come from a builder the page no longer uses, so that builder's rule is checked too.
    if ($env['kind'] !== 'post_content') {
        $gate = bulkseme_content_builder_gate($env['kind'] === 'acf_rows' ? 'acf' : $env['kind'], $post);
        if (is_wp_error($gate)) {
            return $gate;
        }
    }
    // A caller who could not write raw markup does not get to restore it either.
    if (in_array($env['kind'], ['elementor', 'siteorigin', 'accordions', 'beaver'], true) && !current_user_can('unfiltered_html')) {
        return bulkseme_content_restore_text_only($post, $env, $ts);
    }
    if ($env['kind'] === 'elementor') {
        // An exact restore: the stored bytes go back as they were.
        $restore = (string) $env['data'];
        if (!is_array(json_decode($restore, true))) {
            return new WP_Error('backup_corrupt', 'backup payload is not valid Elementor data; nothing was restored', ['status' => 500]);
        }
        // A revert can itself be undone: the current state is stored first.
        $current = get_post_meta($id, '_elementor_data', true);
        $pre_ts  = bulkseme_content_take_backup($id, 'elementor', is_string($current) ? $current : '', 'pre-revert');
        if (!$pre_ts) {
            return new WP_Error('backup_failed', 'could not store a backup of the current content; nothing was restored', ['status' => 500]);
        }
        $side = bulkseme_elementor_write_data($id, $restore);
        do_action('bulkseme_content_reverted', $id, $ts, get_current_user_id());
        return array_merge([
            'id' => $id, 'status' => 'ok', 'reverted_to' => $ts,
            'pre_revert_backup_ts' => $pre_ts, 'kind' => 'elementor',
            'data_hash' => md5($restore),
        ], $side);
    }
    if ($env['kind'] === 'acf' || $env['kind'] === 'acf_rows') {
        return bulkseme_acf_revert($post, $env, $ts);
    }
    if ($env['kind'] === 'siteorigin' || $env['kind'] === 'accordions' || $env['kind'] === 'beaver') {
        return bulkseme_rest_content_revert_meta_builder($post, $env, $ts);
    }
    $pre_ts = bulkseme_content_take_backup($id, 'post_content', (string) $post->post_content, 'pre-revert');
    if (!$pre_ts) {
        return new WP_Error('backup_failed', 'could not store a backup of the current content; nothing was restored', ['status' => 500]);
    }
    $res = wp_update_post(['ID' => $id, 'post_content' => wp_slash($env['data'])], true);
    if (is_wp_error($res)) {
        return new WP_Error('update_failed', $res->get_error_message(), ['status' => 500]);
    }
    do_action('bulkseme_content_reverted', $id, $ts, get_current_user_id());
    return [
        'id' => $id, 'status' => 'ok', 'reverted_to' => $ts,
        'pre_revert_backup_ts' => $pre_ts, 'kind' => 'post_content',
        'data_hash' => md5($env['data']),
    ];
}

// ---- Shared dispatch for the meta-stored builders ---------------------------------
//
// SiteOrigin, Accordions and Beaver Builder all keep their real content in post
// meta with post_content as a derived mirror. These five adapters give the
// REST handlers one call per operation regardless of which store it is.

function bulkseme_mb_load($builder, $post_id) {
    switch ($builder) {
        case 'siteorigin':
            return bulkseme_so_load($post_id);
        case 'accordions':
            return bulkseme_acc_load($post_id);
        case 'beaver':
            return bulkseme_bb_load($post_id);
        case 'acf':
            return bulkseme_acf_load($post_id);
    }
    return new WP_Error('unsupported_builder', 'no meta-builder handler for "' . $builder . '"', ['status' => 501]);
}

function bulkseme_mb_encode($builder, array $data) {
    switch ($builder) {
        case 'siteorigin':
            return bulkseme_so_encode($data);
        case 'accordions':
            return bulkseme_acc_encode($data);
        case 'acf':
            return bulkseme_acf_encode($data);
    }
    return bulkseme_bb_encode($data);
}

function bulkseme_mb_nodes($builder, array $data) {
    switch ($builder) {
        case 'siteorigin':
            return bulkseme_so_flatten($data)[0];
        case 'accordions':
            return bulkseme_acc_flatten($data);
        case 'acf':
            return bulkseme_acf_public_nodes($data);
    }
    return bulkseme_bb_flatten($data)[0];
}

function bulkseme_mb_apply($builder, array &$data, array $updates, $unsafe_html) {
    switch ($builder) {
        case 'siteorigin':
            return bulkseme_so_apply_updates($data, $updates, $unsafe_html);
        case 'accordions':
            return bulkseme_acc_apply_updates($data, $updates, $unsafe_html);
        case 'acf':
            return bulkseme_acf_apply_updates($data, $updates, $unsafe_html);
    }
    return bulkseme_bb_apply_updates($data, $updates, $unsafe_html);
}

function bulkseme_mb_commit($builder, $post_id, $prev, array $data, array $results, $changed, $dry_run, $note = 'pre-update') {
    switch ($builder) {
        case 'siteorigin':
            return bulkseme_so_commit($post_id, $prev, $data, $results, $changed, $dry_run, $note);
        case 'accordions':
            return bulkseme_acc_commit($post_id, $prev, $data, $results, $changed, $dry_run, $note);
        case 'acf':
            return bulkseme_acf_commit($post_id, $prev, $data, $results, $changed, $dry_run);
    }
    return bulkseme_bb_commit($post_id, $prev, $data, $results, $changed, $dry_run, $note);
}

function bulkseme_rest_content_post_meta_builder($post, array $det, WP_REST_Request $req) {
    $id      = $post->ID;
    $builder = $det['builder'];
    $dry_run = (bool) $req->get_param('dry_run');
    $force   = (bool) $req->get_param('force');
    $unsafe  = (bool) $req->get_param('unsafe_html');
    $updates = $req->get_param('updates');
    if (!is_array($updates) || !$updates) {
        return new WP_Error('invalid_payload', $builder . ' posts are edited via a non-empty updates[] array (post_content is derived output)', ['status' => 400]);
    }
    if (count($updates) > 100) {
        return new WP_Error('too_many', 'max 100 updates per request', ['status' => 400]);
    }
    if (!$det['active'] && !$force) {
        return new WP_Error('builder_inactive', $builder . ' is not active on this site; pass force=1 to patch the stored data anyway', ['status' => 409]);
    }
    $data = bulkseme_mb_load($builder, $id);
    if (is_wp_error($data)) {
        return $data;
    }
    $prev      = bulkseme_mb_encode($builder, $data);
    $base_hash = $req->get_param('base_hash');
    if (is_string($base_hash) && $base_hash !== '' && !hash_equals(md5($prev), $base_hash)) {
        return new WP_Error('hash_conflict', 'content changed since it was read; re-GET /content and retry', ['status' => 409]);
    }
    list($results, $changed) = bulkseme_mb_apply($builder, $data, $updates, $unsafe);
    return bulkseme_mb_commit($builder, $id, $prev, $data, $results, $changed, $dry_run);
}

/** Shared find/replace for the meta-backed builders. */
function bulkseme_rest_content_replace_meta_builder($post, array $det, $find, $replace, $case, $dry_run, $force) {
    $id      = $post->ID;
    $builder = $det['builder'];
    if (!$det['active'] && !$force) {
        return new WP_Error('builder_inactive', $builder . ' is not active on this site; pass force=1 to patch the stored data anyway', ['status' => 409]);
    }
    $data = bulkseme_mb_load($builder, $id);
    if (is_wp_error($data)) {
        return $data;
    }
    $prev    = bulkseme_mb_encode($builder, $data);
    $updates = [];
    $matches = [];
    foreach (bulkseme_mb_nodes($builder, $data) as $node) {
        $old = (string) $node['text'];
        if ($old === '' || (isset($node['editable']) && !$node['editable'])) {
            continue;
        }
        $count = 0;
        $new   = $case ? str_replace($find, $replace, $old, $count) : str_ireplace($find, $replace, $old, $count);
        if ($count > 0 && $new !== $old) {
            $label     = isset($node['widget']) ? $node['widget'] : (isset($node['module']) ? $node['module'] : '');
            $updates[] = ['el' => $node['el'], 'setting' => $node['setting'], 'value' => $new];
            $matches[] = ['el' => $node['el'], 'widget' => $label, 'setting' => $node['setting'], 'occurrences' => $count, 'before' => $old, 'after' => $new];
        }
    }
    if (!$updates) {
        return ['id' => $id, 'builder' => $builder, 'dry_run' => $dry_run, 'matched_nodes' => 0, 'changed' => 0, 'matches' => [], 'data_hash' => md5($prev)];
    }
    if (count($updates) > 100) {
        return new WP_Error('too_many', 'find matched ' . count($updates) . ' nodes (max 100); use a more specific find string', ['status' => 400]);
    }
    list($results, $changed) = bulkseme_mb_apply($builder, $data, $updates, false);
    $out = bulkseme_mb_commit($builder, $id, $prev, $data, $results, $changed, $dry_run);
    if (is_wp_error($out)) {
        return $out;
    }
    $out['matched_nodes'] = count($matches);
    $out['matches']       = $matches;
    return $out;
}

/**
 * Restore for a caller who does not hold unfiltered_html.
 *
 * A backup holds the page exactly as it was stored, and that can include
 * things such a caller could never write: a raw HTML widget, custom CSS, link
 * targets, layout. For them a revert therefore puts back the text only. The
 * backup's editable text is applied to the page as it is now, through the same
 * cleaning path as any other write, and everything else on the page stays as it
 * is. A caller who holds unfiltered_html gets the exact restore instead.
 */
function bulkseme_content_restore_text_only($post, array $env, $ts) {
    $id   = $post->ID;
    $kind = $env['kind'];
    if ($kind === 'elementor') {
        $backup = json_decode((string) $env['data'], true);
        $raw    = get_post_meta($id, '_elementor_data', true);
        $tree   = (is_string($raw) && $raw !== '') ? json_decode($raw, true) : null;
        if (!is_array($backup) || !is_array($tree)) {
            return new WP_Error('backup_corrupt', 'the backup or the current page is not valid Elementor data; nothing was restored', ['status' => 409]);
        }
        $updates = [];
        foreach (bulkseme_elementor_flatten($backup) as $node) {
            if (empty($node['editable']) || empty($node['setting'])) {
                continue;
            }
            // Text, link targets and heading levels only. Custom CSS needs unfiltered_html to write, so it is never part of this.
            if (isset($node['kind']) && !in_array($node['kind'], ['url', 'tag'], true)) {
                continue;
            }
            if (isset($node['kind']) && $node['kind'] === 'tag' && !empty($node['default'])) {
                continue; // the backup held no explicit level, only the widget's default
            }
            $updates[] = ['el' => $node['el'], 'setting' => $node['setting'], 'value' => (string) $node['text']];
        }
        list($results, $changed) = bulkseme_content_apply_updates($tree, $updates, true);
        $out = bulkseme_content_commit_elementor($id, $raw, $tree, $results, $changed, false, 'pre-revert');
    } else {
        $current = bulkseme_mb_load($kind, $id);
        if (is_wp_error($current)) {
            return $current;
        }
        if ($kind === 'beaver') {
            $dec    = json_decode((string) $env['data'], true);
            $backup = (is_array($dec) && isset($dec['published'])) ? bulkseme_bb_decode($dec['published']) : null;
        } else {
            $backup = json_decode((string) $env['data'], true);
        }
        if (!is_array($backup)) {
            return new WP_Error('backup_corrupt', 'backup payload could not be read; nothing was restored', ['status' => 409]);
        }
        $prev    = bulkseme_mb_encode($kind, $current);
        $updates = [];
        foreach (bulkseme_mb_nodes($kind, $backup) as $node) {
            if (isset($node['editable']) && !$node['editable']) {
                continue;
            }
            $updates[] = ['el' => $node['el'], 'setting' => $node['setting'], 'value' => (string) $node['text']];
        }
        list($results, $changed) = bulkseme_mb_apply($kind, $current, $updates, false);
        $out = bulkseme_mb_commit($kind, $id, $prev, $current, $results, $changed, false, 'pre-revert');
    }
    if (is_wp_error($out)) {
        return $out;
    }
    $skipped = [];
    foreach ($results as $r) {
        if (!in_array($r['status'], ['ok', 'unchanged'], true) && count($skipped) < 50) {
            $skipped[] = ['el' => $r['el'], 'setting' => $r['setting'], 'status' => $r['status']];
        }
    }
    do_action('bulkseme_content_reverted', $id, $ts, get_current_user_id());
    return [
        'id'                   => $id,
        'status'               => 'ok',
        'reverted_to'          => $ts,
        'kind'                 => $kind,
        'mode'                 => 'text_only',
        'note'                 => 'Your account does not hold unfiltered_html, so only the text was restored. Layout, raw HTML, custom CSS and everything else on the page was left as it is now.',
        'pre_revert_backup_ts' => isset($out['backup_ts']) ? $out['backup_ts'] : 0,
        'restored'             => (int) $changed,
        'skipped'              => $skipped,
        'data_hash'            => isset($out['data_hash']) ? $out['data_hash'] : '',
    ];
}

/** Exact restore of one siteorigin, accordions or beaver backup, for a caller who holds unfiltered_html. */
function bulkseme_rest_content_revert_meta_builder($post, array $env, $ts) {
    $id   = $post->ID;
    $kind = $env['kind'];
    if ($kind === 'beaver') {
        return bulkseme_bb_revert($post, $env, $ts);
    }
    $decoded = json_decode((string) $env['data'], true);
    if (!is_array($decoded)) {
        return new WP_Error('backup_corrupt', 'backup payload is not valid JSON', ['status' => 500]);
    }
    if ($kind === 'siteorigin') {
        $current = bulkseme_so_load($id);
        $pre_ts  = bulkseme_content_take_backup($id, 'siteorigin', is_wp_error($current) ? '' : bulkseme_so_encode($current), 'pre-revert');
        if (!$pre_ts) {
            return new WP_Error('backup_failed', 'could not store a backup of the current content; nothing was restored', ['status' => 500]);
        }
        $side = bulkseme_so_write($id, $decoded);
        $hash = md5(bulkseme_so_encode($decoded));
    } else {
        $current = bulkseme_acc_load($id);
        $pre_ts  = bulkseme_content_take_backup($id, 'accordions', is_wp_error($current) ? '' : bulkseme_acc_encode($current), 'pre-revert');
        if (!$pre_ts) {
            return new WP_Error('backup_failed', 'could not store a backup of the current content; nothing was restored', ['status' => 500]);
        }
        $side = bulkseme_acc_write($id, $decoded);
        $hash = md5(bulkseme_acc_encode($decoded));
    }
    do_action('bulkseme_content_reverted', $id, $ts, get_current_user_id());
    return array_merge([
        'id' => $id, 'status' => 'ok', 'reverted_to' => $ts,
        'pre_revert_backup_ts' => $pre_ts, 'kind' => $kind, 'data_hash' => $hash,
    ], $side);
}

/**
 * Recompile WPBakery's per-page design CSS from post_content. The WPBakery
 * editor rebuilds _wpb_shortcodes_custom_css from every `.vc_custom_<n>{...}`
 * design option on save; a REST write skips that step, so design options added
 * or changed over the API would render without their styles. Only rules of
 * that exact shape are copied, none that carries a quote, an angle bracket or a
 * backslash, and each rule's declarations go through the filter WordPress
 * itself uses for an inline style attribute (safecss_filter_attr). So the style
 * block WPBakery prints can hold nothing that the same user could not already
 * write as style="..." in the post. Returns bytes.
 */
function bulkseme_wpb_rebuild_custom_css($post_id) {
    $content = (string) get_post_field('post_content', $post_id, 'raw');
    preg_match_all('/(\.vc_custom_\d+)\{([^{}"\'<>\\\\]*)\}/', $content, $m, PREG_SET_ORDER);
    $rules = [];
    foreach ($m as $rule) {
        // What is left of a declaration the filter cut in two (a data: URL holds
        // a semicolon) has no property name any more; such pieces are dropped.
        $declarations = implode(';', array_filter(array_map('trim', explode(';', safecss_filter_attr($rule[2]))), function ($d) {
            return (bool) preg_match('/^[a-z-]+\s*:/i', $d);
        }));
        if ($declarations !== '') {
            $rules[$rule[1] . '{' . $declarations . '}'] = true;
        }
    }
    $css = implode('', array_keys($rules));
    if ($css === '') {
        delete_post_meta($post_id, '_wpb_shortcodes_custom_css');
    } else {
        update_post_meta($post_id, '_wpb_shortcodes_custom_css', wp_slash($css));
    }
    return strlen($css);
}

add_action('rest_api_init', function () {

    $perm = function () { return current_user_can('edit_posts'); };

    // -------- /content -------------------------------------------------------
    // GET  ?id=N[&raw=1]  read builder + editable text nodes
    // POST { id, updates:[{el,setting,value}], dry_run, base_hash, force }
    //      or { id, post_content, rebuild_wpb_css } for builders that keep their content in post_content
    // Term descriptions: add kind=term&taxonomy=<tax> to GET, and send
    //      { id, kind:'term', taxonomy, description, dry_run, base_hash }
    //      to POST. /content/backups and /content/revert take the same kind and taxonomy.
    register_rest_route('seo-meta-bridge/v1', '/content', [
        [
            'methods'             => 'GET',
            'permission_callback' => $perm,
            'args'                => [
                'id'       => ['required' => true, 'sanitize_callback' => 'bulkseme_param_id'],
                'kind'     => ['type' => 'string', 'enum' => ['post', 'term'], 'required' => false],
                'taxonomy' => ['type' => 'string', 'required' => false, 'sanitize_callback' => 'sanitize_key'],
            ],
            'callback'            => 'bulkseme_rest_content_get',
        ],
        [
            'methods'             => 'POST',
            'permission_callback' => $perm,
            'callback'            => 'bulkseme_rest_content_post',
        ],
    ]);

    // -------- /content/replace ------------------------------------------------
    // POST { id, find, replace, dry_run, case_sensitive } - literal find/replace
    // across the post's editable text nodes (or post_content).
    register_rest_route('seo-meta-bridge/v1', '/content/replace', [
        'methods'             => 'POST',
        'permission_callback' => $perm,
        'callback'            => 'bulkseme_rest_content_replace',
    ]);

    // -------- /content/backups + /content/revert -------------------------------
    register_rest_route('seo-meta-bridge/v1', '/content/backups', [
        'methods'             => 'GET',
        'permission_callback' => $perm,
        'args'                => [
                'id'       => ['required' => true, 'sanitize_callback' => 'bulkseme_param_id'],
                'kind'     => ['type' => 'string', 'enum' => ['post', 'term'], 'required' => false],
                'taxonomy' => ['type' => 'string', 'required' => false, 'sanitize_callback' => 'sanitize_key'],
            ],
        'callback'            => 'bulkseme_rest_content_backups',
    ]);
    register_rest_route('seo-meta-bridge/v1', '/content/revert', [
        'methods'             => 'POST',
        'permission_callback' => $perm,
        'callback'            => 'bulkseme_rest_content_revert',
    ]);
});
