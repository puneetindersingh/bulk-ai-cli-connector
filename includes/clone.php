<?php
/**
 * POST /clone: copy a page or post into a new draft with its design intact.
 *
 * Page builders and themes keep a page's design outside post_content. WPBakery
 * compiles element design options into _wpb_shortcodes_custom_css, many themes
 * store title bar, header and sidebar settings as post meta, and the page
 * template lives in _wp_page_template. A page created through wp/v2 carries
 * none of that, so it renders unstyled. /clone copies content, template,
 * featured image, terms and post meta, minus the keys that belong to the
 * source page itself (its SEO fields, locks and caches).
 *
 * The caller must be allowed to edit the source, to create posts of its type,
 * and must hold unfiltered_html: without it WordPress would strip parts of the
 * builder markup on the way in. The copy is never published by this route.
 *
 * POST { source_id, title, slug, status: draft|pending|private,
 *        post_content, parent, rebuild_wpb_css, dry_run }
 */

if (!defined('ABSPATH')) exit;

/** Meta keys never copied to a clone: SEO fields, plugin data, locks, caches. */
function bulkseme_clone_skip_meta_key($key) {
    $exact = [
        '_edit_lock', '_edit_last', '_wp_old_slug', '_wp_old_date', '_wp_desired_post_slug',
        '_wp_trash_meta_status', '_wp_trash_meta_time', '_encloseme', '_pingme',
        '_dp_original', '_dp_is_rewrite_republish_copy', '_dp_has_rewrite_republish_copy',
        '_elementor_css', '_elementor_element_cache', '_elementor_page_assets',
    ];
    if (in_array($key, $exact, true)) {
        return true;
    }
    foreach (['_yoast_wpseo_', 'rank_math_', '_aioseo_', '_bulkseme_', '_seocli_'] as $prefix) {
        if (strpos($key, $prefix) === 0) {
            return true;
        }
    }
    return (bool) apply_filters('bulkseme_clone_skip_meta_key', false, $key);
}

/**
 * A stored meta value, read back so it can be saved on the copy. $ok is set to
 * false when the value must not be copied.
 *
 * WordPress keeps arrays in the database as serialised strings. Before one is
 * read back, its text is checked: a serialised object always appears as
 * O:<length>:"ClassName" (C: and E: for the two other object forms) at the
 * start of a value. Only plain data and stdClass, which Beaver Builder layouts
 * are made of, are let through. A value that names any other class is never
 * read back, so no object of that class is ever created, and it is left out of
 * the copy.
 */
function bulkseme_clone_meta_value($raw, &$ok) {
    $ok = true;
    if (!is_string($raw) || !is_serialized($raw)) {
        return $raw;
    }
    // Core trims the value before it unserialises it, and trim() also removes NUL
    // bytes, which \s does not match. The check below must read the same text.
    $text  = trim($raw);
    $found = [];
    $hits  = preg_match_all('/(?:^|[;{}])\s*([OCE]):\+?\d+:"([^"]*)"/i', $text, $found, PREG_SET_ORDER);
    if ($hits === false) {
        $ok = false; // the pattern could not run: refuse rather than guess
        return null;
    }
    foreach ($found as $hit) {
        if (strtoupper($hit[1]) !== 'O' || strtolower($hit[2]) !== 'stdclass') {
            $ok = false;
            return null;
        }
    }
    $value = maybe_unserialize($text);
    if (bulkseme_clone_holds_foreign_object($value)) {
        $ok = false;
        return null;
    }
    return $value;
}

/** True when the value holds an object that is not a plain stdClass, at any depth. */
function bulkseme_clone_holds_foreign_object($value, $depth = 0) {
    if ($depth > 64) {
        return true;
    }
    if (is_object($value)) {
        if (!($value instanceof stdClass)) {
            return true;
        }
        $value = get_object_vars($value);
    }
    if (is_array($value)) {
        foreach ($value as $v) {
            if (bulkseme_clone_holds_foreign_object($v, $depth + 1)) {
                return true;
            }
        }
    }
    return false;
}

/**
 * wp_slash() that also reaches into stdClass objects. add_post_meta() unslashes
 * every string it is given, inside objects too, so each one is slashed first to
 * come out as it went in.
 */
function bulkseme_clone_slash($value) {
    if (is_array($value)) {
        foreach ($value as $k => $v) {
            $value[$k] = bulkseme_clone_slash($v);
        }
        return $value;
    }
    if ($value instanceof stdClass) {
        $copy = new stdClass();
        foreach (get_object_vars($value) as $k => $v) {
            $copy->{$k} = bulkseme_clone_slash($v);
        }
        return $copy;
    }
    return is_string($value) ? wp_slash($value) : $value;
}

function bulkseme_rest_clone(WP_REST_Request $req) {
    $source = get_post(bulkseme_param_id($req->get_param('source_id')));
    $pto    = $source ? get_post_type_object($source->post_type) : null;
    if (!$source || $source->post_status === 'trash' || !bulkseme_post_type_reachable($source->post_type) || in_array($source->post_type, ['attachment', 'nav_menu_item'], true)) {
        return new WP_Error('not_found', 'source post not found', ['status' => 404]);
    }
    if (!$pto || !current_user_can('edit_post', $source->ID) || !current_user_can($pto->cap->create_posts)) {
        return new WP_Error('forbidden', 'you cannot copy this post', ['status' => 403]);
    }
    if (!current_user_can('unfiltered_html')) {
        return new WP_Error('needs_unfiltered_html', 'copying builder markup needs a user with unfiltered_html; without it WordPress would strip parts of the layout', ['status' => 403]);
    }
    // A copy carries the builder's own data, so the builder's rule for who may use it applies.
    $gate = bulkseme_content_builder_gate(bulkseme_detect_builder($source)['builder'], $source);
    if (is_wp_error($gate)) {
        return $gate;
    }
    $status = $req->get_param('status');
    $status = is_string($status) && $status !== '' ? $status : 'draft';
    if (!in_array($status, ['draft', 'pending', 'private'], true)) {
        return new WP_Error('invalid_status', 'status must be draft, pending or private; publish the copy after it has been checked', ['status' => 400]);
    }
    if ($status === 'private' && !current_user_can($pto->cap->publish_posts)) {
        return new WP_Error('forbidden', 'you cannot create private posts of this type', ['status' => 403]);
    }
    $title = $req->get_param('title');
    $title = is_string($title) ? sanitize_text_field($title) : '';
    if ($title === '') {
        return new WP_Error('invalid_payload', 'title is required', ['status' => 400]);
    }
    $content = $req->get_param('post_content');
    if ($content !== null && !is_string($content)) {
        return new WP_Error('invalid_payload', 'post_content must be a string', ['status' => 400]);
    }
    $parent = $req->get_param('parent');
    $parent = $parent === null ? (int) $source->post_parent : absint($parent);
    if ($parent && !get_post($parent)) {
        return new WP_Error('invalid_parent', 'parent post not found', ['status' => 400]);
    }
    $slug   = $req->get_param('slug');
    $slug   = is_string($slug) ? sanitize_title($slug) : '';
    if ($slug !== '' && wp_unique_post_slug($slug, 0, 'publish', $source->post_type, $parent) !== $slug) {
        return new WP_Error('slug_taken', 'slug "' . $slug . '" is already used by another ' . $source->post_type . ' under the same parent', ['status' => 409]);
    }

    $copy            = [];
    $skipped         = [];
    $skipped_objects = [];
    foreach (get_post_meta($source->ID) as $key => $values) {
        if (bulkseme_clone_skip_meta_key($key)) {
            $skipped[] = $key;
            continue;
        }
        $decoded = [];
        foreach ((array) $values as $raw) {
            $value = bulkseme_clone_meta_value($raw, $ok);
            if (!$ok) {
                $decoded = null;
                break;
            }
            $decoded[] = $value;
        }
        if ($decoded === null) {
            $skipped_objects[] = $key;
            continue;
        }
        $copy[$key] = $decoded;
    }
    $terms = [];
    foreach (get_object_taxonomies($source->post_type) as $tax) {
        $ids = wp_get_object_terms($source->ID, $tax, ['fields' => 'ids']);
        if (!is_wp_error($ids) && $ids) {
            $terms[$tax] = array_map('intval', $ids);
        }
    }
    $summary = [
        'source_id'    => $source->ID,
        'post_type'    => $source->post_type,
        'status'       => $status,
        'title'        => $title,
        'slug'         => $slug,
        'parent'       => $parent,
        'meta_copied'  => array_keys($copy),
        'meta_skipped' => $skipped,
        'meta_skipped_objects' => $skipped_objects,
        'terms'        => $terms,
        'builder'      => bulkseme_detect_builder($source)['builder'],
    ];
    if ($req->get_param('dry_run')) {
        return array_merge(['dry_run' => true], $summary);
    }

    $new_id = wp_insert_post([
        'post_type'      => $source->post_type,
        'post_status'    => $status,
        'post_title'     => wp_slash($title),
        'post_name'      => $slug,
        'post_content'   => wp_slash($content === null ? $source->post_content : $content),
        'post_excerpt'   => wp_slash($source->post_excerpt),
        'post_parent'    => $parent,
        'menu_order'     => (int) $source->menu_order,
        'comment_status' => $source->comment_status,
        'ping_status'    => $source->ping_status,
        'post_author'    => get_current_user_id(),
    ], true);
    if (is_wp_error($new_id)) {
        return new WP_Error('insert_failed', $new_id->get_error_message(), ['status' => 500]);
    }
    foreach ($copy as $key => $values) {
        // save_post hooks may already have written some keys; the source wins.
        delete_post_meta($new_id, $key);
        foreach ($values as $value) {
            add_post_meta($new_id, $key, bulkseme_clone_slash($value));
        }
    }
    foreach ($terms as $tax => $ids) {
        wp_set_object_terms($new_id, $ids, $tax);
    }
    $wpb_bytes = null;
    if ($req->get_param('rebuild_wpb_css')) {
        $wpb_bytes = bulkseme_wpb_rebuild_custom_css($new_id);
    }
    clean_post_cache($new_id);
    do_action('bulkseme_post_cloned', $new_id, $source->ID, get_current_user_id());

    return array_merge($summary, [
        'id'            => $new_id,
        'slug'          => get_post_field('post_name', $new_id),
        'preview_link'  => get_preview_post_link($new_id),
        'wpb_css_bytes' => $wpb_bytes,
    ]);
}

add_action('rest_api_init', function () {
    register_rest_route('seo-meta-bridge/v1', '/clone', [
        'methods'             => 'POST',
        'permission_callback' => function () {
            return current_user_can('edit_posts');
        },
        'callback'            => 'bulkseme_rest_clone',
    ]);
});
