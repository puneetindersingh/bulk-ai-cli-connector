<?php
/**
 * Taxonomy term descriptions through /content with kind=term.
 *
 * WordPress core runs wp_filter_kses on pre_term_description for every user,
 * and that allowlist is the one used for comments: links, bold, italic and a
 * few inline tags. Headings, paragraphs, lists and tables written to a
 * category description over the REST API are therefore stripped, even for
 * administrators, which wipes the structure of a WooCommerce product category
 * introduction. These handlers sanitise with the post content allowlist
 * (wp_kses_post) instead, and swap core's filter out only for the one save.
 */

if (!defined('ABSPATH')) exit;

/** Shared entry guards for kind=term. Returns WP_Term or WP_Error. */
function bulkseme_content_load_term(WP_REST_Request $req) {
    $taxonomy = sanitize_key(bulkseme_param_text($req->get_param('taxonomy')));
    if ($taxonomy === '' || !taxonomy_exists($taxonomy)) {
        return new WP_Error('unknown_taxonomy', 'kind=term needs an existing taxonomy', ['status' => 400]);
    }
    // The same reach as WordPress itself: a taxonomy with no edit screen and no
    // REST route (post_format, wp_theme, ...) is not edited here either.
    $tax = get_taxonomy($taxonomy);
    if (!$tax || (empty($tax->show_ui) && empty($tax->show_in_rest))) {
        return new WP_Error('unknown_taxonomy', 'kind=term needs a taxonomy that has an edit screen or a REST route', ['status' => 400]);
    }
    $id   = bulkseme_param_id($req->get_param('id'));
    $term = $id ? get_term($id, $taxonomy) : null;
    if (!$term || is_wp_error($term)) {
        return new WP_Error('term_not_found', 'term not found in that taxonomy', ['status' => 404]);
    }
    if (!current_user_can('edit_term', $term->term_id)) {
        return new WP_Error('forbidden', 'cannot edit this term', ['status' => 403]);
    }
    return $term;
}

/**
 * Save a term description with the post content allowlist.
 *
 * Core's comment-level filter on pre_term_description is taken off for this
 * one wp_update_term() call and wp_filter_post_kses stands in for it, so the
 * value is cleaned at the point of saving whatever the caller did before.
 * Core's filter is always put back at its original priority. $verbatim skips
 * the stand-in: it is only ever true for a user who holds unfiltered_html.
 */
function bulkseme_term_update_description($term, $clean, $verbatim = false) {
    $prio = has_filter('pre_term_description', 'wp_filter_kses');
    if ($prio !== false) {
        remove_filter('pre_term_description', 'wp_filter_kses', $prio);
    }
    if (!$verbatim) {
        add_filter('pre_term_description', 'wp_filter_post_kses', 10);
    }
    try {
        $res = wp_update_term($term->term_id, $term->taxonomy, ['description' => wp_slash($clean)]);
    } finally {
        if (!$verbatim) {
            remove_filter('pre_term_description', 'wp_filter_post_kses', 10);
        }
        if ($prio !== false) {
            add_filter('pre_term_description', 'wp_filter_kses', $prio);
        }
    }
    return $res;
}

/**
 * Whether the current user may read and write this term's ACF fields: the
 * term-side counterpart of the `acf` rule posts go through
 * (bulkseme_user_can_edit_with_builder), on top of edit_term.
 */
function bulkseme_term_acf_allowed($term) {
    /**
     * Filter: whether the current user may edit this term's ACF fields.
     *
     * @param bool    $allowed
     * @param WP_Term $term
     */
    return (bool) apply_filters('bulkseme_user_can_edit_term_acf', true, $term);
}

/** Current stored description, read fresh (bypasses a stale term object). */
function bulkseme_term_fresh_description($term) {
    clean_term_cache($term->term_id, $term->taxonomy);
    $fresh = get_term($term->term_id, $term->taxonomy);
    return ($fresh && !is_wp_error($fresh)) ? (string) $fresh->description : '';
}

function bulkseme_rest_term_content_get(WP_REST_Request $req) {
    $term = bulkseme_content_load_term($req);
    if (is_wp_error($term)) {
        return $term;
    }
    $desc = (string) $term->description;
    $link = get_term_link($term);
    $out  = [
        'id'          => (int) $term->term_id,
        'kind'        => 'term',
        'taxonomy'    => $term->taxonomy,
        'name'        => $term->name,
        'slug'        => $term->slug,
        'link'        => is_wp_error($link) ? '' : $link,
        'editable'    => true,
        'description' => $desc,
        'data_hash'   => md5($desc),
        'backups'     => count(bulkseme_term_content_backup_ids($term->term_id)),
    ];
    if (bulkseme_acf_active() && bulkseme_term_acf_allowed($term)) {
        // ACF fields on the term (a category intro block, an FAQ repeater):
        // POST updates[] without a description addresses these nodes.
        $acf = bulkseme_acf_load(bulkseme_acf_term_target($term));
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

/**
 * A term's ACF fields: kind=term with updates[] and no description. The same
 * nodes, row inserts, cleaning, backups and revert as ACF fields on a post,
 * written to term meta.
 */
function bulkseme_rest_term_acf_post($term, WP_REST_Request $req) {
    if (!bulkseme_term_acf_allowed($term)) {
        return new WP_Error('forbidden_builder', 'your account may not edit ACF fields on this term', ['status' => 403]);
    }
    $updates = $req->get_param('updates');
    if (count($updates) > 100) {
        return new WP_Error('too_many', 'max 100 updates per request', ['status' => 400]);
    }
    $target = bulkseme_acf_term_target($term);
    $data   = bulkseme_acf_load($target);
    if (is_wp_error($data)) {
        return $data;
    }
    $prev      = bulkseme_acf_encode($data);
    $base_hash = $req->get_param('base_hash');
    if (is_string($base_hash) && $base_hash !== '' && !hash_equals(md5($prev), $base_hash)) {
        return new WP_Error('hash_conflict', 'term ACF fields changed since they were read; re-GET /content and retry', ['status' => 409]);
    }
    list($results, $changed) = bulkseme_acf_apply_updates($data, $updates, (bool) $req->get_param('unsafe_html'));
    return bulkseme_acf_commit($target, $prev, $data, $results, $changed, (bool) $req->get_param('dry_run'));
}

function bulkseme_rest_term_content_post(WP_REST_Request $req) {
    $term = bulkseme_content_load_term($req);
    if (is_wp_error($term)) {
        return $term;
    }
    $new     = $req->get_param('description');
    $updates = $req->get_param('updates');
    if (!is_string($new) && is_array($updates) && $updates && bulkseme_acf_active()) {
        return bulkseme_rest_term_acf_post($term, $req);
    }
    if (!is_string($new)) {
        return new WP_Error('invalid_payload', 'description (string) is required for kind=term (or updates[] for the term\'s ACF fields)', ['status' => 400]);
    }
    $prev      = (string) $term->description;
    $base_hash = $req->get_param('base_hash');
    if (is_string($base_hash) && $base_hash !== '' && !hash_equals(md5($prev), $base_hash)) {
        return new WP_Error('hash_conflict', 'term description changed since it was read; re-GET /content and retry', ['status' => 409]);
    }
    $verbatim = bulkseme_content_raw_html_allowed((bool) $req->get_param('unsafe_html'));
    $clean    = $verbatim ? $new : wp_kses_post($new);
    $dry_run = (bool) $req->get_param('dry_run');
    $out     = ['id' => (int) $term->term_id, 'kind' => 'term', 'taxonomy' => $term->taxonomy, 'dry_run' => $dry_run];
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
    $backup_ts = bulkseme_term_content_take_backup($term->term_id, $prev, 'pre-update');
    if (!$backup_ts) {
        return new WP_Error('backup_failed', 'could not snapshot the current description; nothing was written', ['status' => 500]);
    }
    $res = bulkseme_term_update_description($term, $clean, $verbatim);
    if (is_wp_error($res)) {
        return new WP_Error('update_failed', $res->get_error_message(), ['status' => 500]);
    }
    $stored = bulkseme_term_fresh_description($term);
    $out['status']         = 'ok';
    $out['backup_ts']      = $backup_ts;
    $out['stored_matches'] = ($stored === $clean);
    $out['data_hash']      = md5($stored);
    do_action('bulkseme_term_content_updated', (int) $term->term_id, $term->taxonomy, get_current_user_id());
    return $out;
}

function bulkseme_rest_term_content_backups(WP_REST_Request $req) {
    $term = bulkseme_content_load_term($req);
    if (is_wp_error($term)) {
        return $term;
    }
    $items  = [];
    $acf_ok = bulkseme_term_acf_allowed($term);
    foreach (bulkseme_term_content_backup_ids($term->term_id) as $ts) {
        $env = bulkseme_term_content_read_backup($term->term_id, $ts);
        if (!$env || ($env['kind'] !== 'term_description' && !$acf_ok)) {
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
    return ['id' => (int) $term->term_id, 'kind' => 'term', 'taxonomy' => $term->taxonomy, 'count' => count($items), 'backups' => $items];
}

function bulkseme_rest_term_content_revert(WP_REST_Request $req) {
    $term = bulkseme_content_load_term($req);
    if (is_wp_error($term)) {
        return $term;
    }
    $ts  = bulkseme_param_id($req->get_param('ts'));
    $env = $ts ? bulkseme_term_content_read_backup($term->term_id, $ts) : null;
    if (!$env) {
        return new WP_Error('backup_not_found', 'no backup with that ts for this term', ['status' => 404]);
    }
    if ($env['kind'] === 'acf' || $env['kind'] === 'acf_rows') {
        if (!bulkseme_acf_active()) {
            return new WP_Error('acf_inactive', 'Advanced Custom Fields is not active on this site', ['status' => 409]);
        }
        if (!bulkseme_term_acf_allowed($term)) {
            return new WP_Error('forbidden_builder', 'your account may not edit ACF fields on this term', ['status' => 403]);
        }
        return bulkseme_acf_revert(bulkseme_acf_term_target($term), $env, $ts);
    }
    if ($env['kind'] !== 'term_description') {
        return new WP_Error('backup_not_found', 'no backup with that ts for this term', ['status' => 404]);
    }
    // A snapshot can carry markup an unfiltered_html user authored; callers
    // without that capability get it re-sanitized, like the post revert path.
    $verbatim = current_user_can('unfiltered_html');
    $restore  = $verbatim ? (string) $env['data'] : wp_kses_post((string) $env['data']);
    $pre_ts  = bulkseme_term_content_take_backup($term->term_id, (string) $term->description, 'pre-revert');
    if (!$pre_ts) {
        return new WP_Error('backup_failed', 'could not snapshot the current description; nothing was reverted', ['status' => 500]);
    }
    $res = bulkseme_term_update_description($term, $restore, $verbatim);
    if (is_wp_error($res)) {
        return new WP_Error('update_failed', $res->get_error_message(), ['status' => 500]);
    }
    $stored = bulkseme_term_fresh_description($term);
    do_action('bulkseme_term_content_reverted', (int) $term->term_id, $term->taxonomy, $ts, get_current_user_id());
    return [
        'id' => (int) $term->term_id, 'kind' => 'term', 'taxonomy' => $term->taxonomy,
        'status' => 'ok', 'reverted_to' => $ts, 'pre_revert_backup_ts' => $pre_ts,
        'stored_matches' => ($stored === $restore), 'data_hash' => md5($stored),
    ];
}
