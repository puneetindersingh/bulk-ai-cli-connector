<?php
/**
 * Backups for /content. Every write first stores what was there, and
 * POST /content/revert puts it back.
 *
 * A backup is a post of a hidden type (bulkseme_backup). It is kept out of the
 * edited post's own meta on purpose: WordPress loads all of a post's meta on
 * every view of the page, and ten copies of a page builder layout do not belong
 * there.
 *
 * Nobody reaches a backup post through wp-admin, the REST API, XML-RPC or an
 * export: the type has no screens and every capability of it maps to
 * do_not_allow. Backups are read only by /content/backups and /content/revert,
 * after those have checked edit_post (or edit_term) on the item the backup
 * belongs to, and a backup can only ever be restored onto the item it was
 * taken from. The newest ten are kept per item. A post's or term's backups go
 * when it is deleted for good.
 */

if (!defined('ABSPATH')) exit;

/** How many backups are kept for one post or term. */
function bulkseme_backup_keep() {
    return 10;
}

/** Content kinds a backup can hold. */
function bulkseme_backup_kinds() {
    return ['post_content', 'elementor', 'siteorigin', 'accordions', 'beaver', 'acf', 'term_description'];
}

add_action('init', function () {
    $never = 'do_not_allow';
    register_post_type('bulkseme_backup', [
        'labels'              => ['name' => __('Content backups', 'bulk-seo-meta-editor-for-ai-agents')],
        'public'              => false,
        'publicly_queryable'  => false,
        'exclude_from_search' => true,
        'show_ui'             => false,
        'show_in_menu'        => false,
        'show_in_nav_menus'   => false,
        'show_in_admin_bar'   => false,
        'show_in_rest'        => false,
        'can_export'          => false,
        'has_archive'         => false,
        'rewrite'             => false,
        'query_var'           => false,
        'hierarchical'        => false,
        'supports'            => ['title'],
        'map_meta_cap'        => false,
        'capabilities'        => [
            'edit_post'              => $never,
            'read_post'              => $never,
            'delete_post'            => $never,
            'edit_posts'             => $never,
            'edit_others_posts'      => $never,
            'delete_posts'           => $never,
            'publish_posts'          => $never,
            'read_private_posts'     => $never,
            'read'                   => $never,
            'delete_private_posts'   => $never,
            'delete_published_posts' => $never,
            'delete_others_posts'    => $never,
            'edit_private_posts'     => $never,
            'edit_published_posts'   => $never,
            'create_posts'           => $never,
        ],
    ]);
});

/** Ids of the backups one item owns, oldest first. $owner is "post:12" or "term:34". */
function bulkseme_backup_ids($owner) {
    $ids = get_posts([
        'post_type'              => 'bulkseme_backup',
        'post_status'            => 'private',
        'title'                  => (string) $owner,
        'fields'                 => 'ids',
        'posts_per_page'         => 100,
        'orderby'                => 'ID',
        'order'                  => 'ASC',
        'no_found_rows'          => true,
        'update_post_meta_cache' => false,
        'update_post_term_cache' => false,
    ]);
    return array_map('intval', is_array($ids) ? $ids : []);
}

/**
 * Store one backup and trim the item's backups to the newest ten.
 * Returns the backup id (the `ts` the API hands out), or 0 when it could not be stored.
 */
function bulkseme_backup_add($owner, $parent_post_id, $kind, $raw, $note, array $extra = []) {
    if (!in_array($kind, bulkseme_backup_kinds(), true)) {
        return 0;
    }
    $env  = array_merge($extra, [
        'kind'    => $kind,
        'data'    => (string) $raw,
        'user'    => get_current_user_id(),
        'note'    => (string) $note,
        'created' => gmdate('c'),
    ]);
    $json = wp_json_encode($env);
    if ($json === false) {
        return 0;
    }
    $id = wp_insert_post([
        'post_type'      => 'bulkseme_backup',
        'post_status'    => 'private',
        'post_title'     => (string) $owner,
        'post_parent'    => (int) $parent_post_id,
        'post_author'    => get_current_user_id(),
        'comment_status' => 'closed',
        'ping_status'    => 'closed',
    ], true);
    if (is_wp_error($id) || !$id) {
        return 0;
    }
    // Stored as it was read: wp_slash only undoes the unslash update_post_meta applies.
    if (!update_post_meta($id, '_bulkseme_backup', wp_slash($json))) {
        wp_delete_post($id, true);
        return 0;
    }
    $ids = bulkseme_backup_ids($owner);
    while (count($ids) > bulkseme_backup_keep()) {
        wp_delete_post(array_shift($ids), true);
    }
    return (int) $id;
}

/**
 * Read one backup of one item. Null when the id is not a backup, or belongs to
 * a different item: a backup is only ever restored onto the item it came from.
 */
function bulkseme_backup_get($owner, $id) {
    $id = (int) $id;
    if ($id <= 0) {
        return null;
    }
    $post = get_post($id);
    if (!$post || $post->post_type !== 'bulkseme_backup' || $post->post_title !== (string) $owner) {
        return null;
    }
    $raw = get_post_meta($id, '_bulkseme_backup', true);
    if (!is_string($raw) || $raw === '') {
        return null;
    }
    $env = json_decode($raw, true);
    if (!is_array($env) || !isset($env['kind'], $env['data']) || !is_string($env['kind']) || !is_string($env['data'])) {
        return null;
    }
    return $env;
}

/** Delete every backup one item owns. */
function bulkseme_backup_delete_all($owner) {
    foreach (bulkseme_backup_ids($owner) as $id) {
        wp_delete_post($id, true);
    }
}

add_action('deleted_post', function ($post_id, $post = null) {
    if ($post instanceof WP_Post && $post->post_type === 'bulkseme_backup') {
        return;
    }
    bulkseme_backup_delete_all('post:' . (int) $post_id);
}, 10, 2);

add_action('delete_term', function ($term_id) {
    bulkseme_backup_delete_all('term:' . (int) $term_id);
});

// ---- Posts --------------------------------------------------------------------

/** Backup ids for a post, oldest first. */
function bulkseme_content_backup_ids($post_id) {
    return bulkseme_backup_ids('post:' . (int) $post_id);
}

/**
 * Snapshot a post's content as it is stored (the string is never decoded and
 * encoded again, so a revert gives back the same bytes). Returns the backup id.
 */
function bulkseme_content_take_backup($post_id, $kind, $raw, $note = 'pre-update') {
    $post_id = (int) $post_id;
    return bulkseme_backup_add('post:' . $post_id, $post_id, $kind, $raw, $note, [
        'elementor_version' => (string) get_post_meta($post_id, '_elementor_version', true),
    ]);
}

/** One backup of a post, or null. */
function bulkseme_content_read_backup($post_id, $id) {
    $env = bulkseme_backup_get('post:' . (int) $post_id, $id);
    return ($env && $env['kind'] !== 'term_description') ? $env : null;
}

// ---- Terms --------------------------------------------------------------------

/** Backup ids for a term, oldest first. */
function bulkseme_term_content_backup_ids($term_id) {
    return bulkseme_backup_ids('term:' . (int) $term_id);
}

/** Snapshot a term description as it is stored. Returns the backup id, or 0. */
function bulkseme_term_content_take_backup($term_id, $raw, $note = 'pre-update') {
    return bulkseme_backup_add('term:' . (int) $term_id, 0, 'term_description', $raw, $note);
}

/** One backup of a term, or null. */
function bulkseme_term_content_read_backup($term_id, $id) {
    $env = bulkseme_backup_get('term:' . (int) $term_id, $id);
    return ($env && $env['kind'] === 'term_description') ? $env : null;
}
