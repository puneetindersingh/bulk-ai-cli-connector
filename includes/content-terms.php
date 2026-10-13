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
    return [
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
}

function bulkseme_rest_term_content_post(WP_REST_Request $req) {
    $term = bulkseme_content_load_term($req);
    if (is_wp_error($term)) {
        return $term;
    }
    $new = $req->get_param('description');
    if (!is_string($new)) {
        return new WP_Error('invalid_payload', 'description (string) is required for kind=term', ['status' => 400]);
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
    $items = [];
    foreach (bulkseme_term_content_backup_ids($term->term_id) as $ts) {
        $env = bulkseme_term_content_read_backup($term->term_id, $ts);
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
