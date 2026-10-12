<?php
/**
 * Page content stored in Advanced Custom Fields.
 *
 * Many themes keep the page copy in ACF fields (a flexible content field,
 * repeaters, groups) and leave post_content empty. These handlers walk the
 * post's top-level ACF fields with the field definitions in hand and list
 * every text, textarea and wysiwyg value as a node: el = the field path up to
 * the row it sits in (`content[2]`, `content[2].columns_group.columns[1]`, or
 * the field name for a top-level field), setting = the rest of the path
 * (`text`, `button.text`, or `value` for a top-level field). Values are read
 * unformatted, so a node shows exactly what is stored.
 *
 * A changed value is written to the meta key ACF stores it under
 * (`content_2_columns_group_columns_1_text`), and only after checking that the
 * `_<meta key>` reference ACF keeps beside it still names that field. Nothing
 * else in the field tree is rewritten. Image, relationship, select and other
 * fields that are not text are listed under `skipped` and never touched.
 * Backups (kind `acf`) hold every text value, so a revert restores each one by
 * its meta key.
 */

if (!defined('ABSPATH')) exit;

function bulkseme_acf_active() {
    return function_exists('acf_get_field') && (function_exists('acf_get_field_groups') || function_exists('get_field_objects'));
}

function bulkseme_acf_version() {
    if (defined('ACF_VERSION')) {
        return ACF_VERSION;
    }
    if (function_exists('acf_get_setting')) {
        $v = acf_get_setting('version');
        return is_string($v) ? $v : null;
    }
    return null;
}

/** Leaf field types we edit, mapped to the sanitizer class (text | textarea | html). */
function bulkseme_acf_leaf_types() {
    $types = ['text' => 'text', 'textarea' => 'textarea', 'wysiwyg' => 'html'];
    $types = apply_filters('bulkseme_acf_leaf_types', $types);
    return is_array($types) ? $types : [];
}

/** Sub-field value from a row: ACF keys unformatted rows by field key, formatted rows by name. */
function bulkseme_acf_sub_value(array $row, array $sub) {
    if (isset($sub['key']) && array_key_exists($sub['key'], $row)) {
        return $row[$sub['key']];
    }
    if (isset($sub['name']) && array_key_exists($sub['name'], $row)) {
        return $row[$sub['name']];
    }
    return null;
}

/**
 * Walk one field definition with its (unformatted) value and append every
 * text leaf to $nodes. $meta is the meta key the field is stored under and
 * $path the node path, both extended the way ACF composes sub-field names.
 */
function bulkseme_acf_walk(array $field, $value, $meta, $path, array &$nodes, array &$skipped, $depth = 0) {
    $type = isset($field['type']) ? (string) $field['type'] : '';
    $name = isset($field['name']) ? (string) $field['name'] : '';
    $key  = isset($field['key']) ? (string) $field['key'] : '';
    if ($name === '' || $type === '' || $key === '' || $depth > 8) {
        return;
    }
    $leaf_types = bulkseme_acf_leaf_types();
    if (isset($leaf_types[$type])) {
        if (!is_string($value) && !is_numeric($value)) {
            return; // never saved, or not text
        }
        $nodes[] = [
            'path'       => $path,
            'meta_key'   => $meta,
            'key'        => $key,
            'field_type' => $type,
            'type'       => $leaf_types[$type],
            'text'       => (string) $value,
            'label'      => isset($field['label']) && is_string($field['label']) ? $field['label'] : $name,
        ];
        return;
    }
    if ($type === 'flexible_content') {
        if (!is_array($value)) {
            return;
        }
        $layouts = [];
        $defs    = isset($field['layouts']) && is_array($field['layouts']) ? $field['layouts'] : [];
        foreach ($defs as $l) {
            if (is_array($l) && isset($l['name'])) {
                $layouts[(string) $l['name']] = $l;
            }
        }
        foreach ($value as $i => $row) {
            if (!is_int($i) || !is_array($row) || !isset($row['acf_fc_layout']) || !isset($layouts[$row['acf_fc_layout']])) {
                continue;
            }
            $layout = $layouts[$row['acf_fc_layout']];
            $subs   = isset($layout['sub_fields']) && is_array($layout['sub_fields']) ? $layout['sub_fields'] : [];
            foreach ($subs as $sub) {
                if (!is_array($sub) || empty($sub['name'])) {
                    continue;
                }
                bulkseme_acf_walk($sub, bulkseme_acf_sub_value($row, $sub), $meta . '_' . $i . '_' . $sub['name'], $path . '[' . $i . '].' . $sub['name'], $nodes, $skipped, $depth + 1);
            }
        }
        return;
    }
    if ($type === 'repeater') {
        if (!is_array($value)) {
            return;
        }
        $subs = isset($field['sub_fields']) && is_array($field['sub_fields']) ? $field['sub_fields'] : [];
        foreach ($value as $i => $row) {
            if (!is_int($i) || !is_array($row)) {
                continue;
            }
            foreach ($subs as $sub) {
                if (!is_array($sub) || empty($sub['name'])) {
                    continue;
                }
                bulkseme_acf_walk($sub, bulkseme_acf_sub_value($row, $sub), $meta . '_' . $i . '_' . $sub['name'], $path . '[' . $i . '].' . $sub['name'], $nodes, $skipped, $depth + 1);
            }
        }
        return;
    }
    if ($type === 'group') {
        if (!is_array($value)) {
            return;
        }
        $subs = isset($field['sub_fields']) && is_array($field['sub_fields']) ? $field['sub_fields'] : [];
        foreach ($subs as $sub) {
            if (!is_array($sub) || empty($sub['name'])) {
                continue;
            }
            bulkseme_acf_walk($sub, bulkseme_acf_sub_value($value, $sub), $meta . '_' . $sub['name'], $path . '.' . $sub['name'], $nodes, $skipped, $depth + 1);
        }
        return;
    }
    if (in_array($type, ['tab', 'message', 'accordion'], true)) {
        return; // layout-only field types, no value
    }
    if (count($skipped) < 200) {
        $skipped[] = ['path' => $path, 'type' => $type];
    }
}

/** Top-level ACF fields for a post, keyed by field key, with sub fields/layouts hydrated. */
function bulkseme_acf_top_fields($post_id) {
    $fields = [];
    $post   = get_post($post_id);
    if (function_exists('acf_get_field_groups') && function_exists('acf_get_fields')) {
        $args   = ['post_id' => (int) $post_id];
        if ($post && !empty($post->post_type)) {
            $args['post_type'] = $post->post_type;
        }
        $groups = acf_get_field_groups($args);
        foreach ((is_array($groups) ? $groups : []) as $group) {
            $list = acf_get_fields($group);
            foreach ((is_array($list) ? $list : []) as $f) {
                if (is_array($f) && !empty($f['key']) && !empty($f['name'])) {
                    $fields[$f['key']] = $f;
                }
            }
        }
    }
    if (!$fields && function_exists('get_field_objects') && current_user_can('manage_options')) {
        // Location rules did not match (group moved, legacy data): fall back
        // to the fields the stored meta references. Administrators only, since
        // a field group can be limited to certain roles by those same rules.
        $objects = get_field_objects($post_id, false, false);
        foreach ((is_array($objects) ? $objects : []) as $f) {
            if (is_array($f) && !empty($f['key']) && !empty($f['name'])) {
                $fields[$f['key']] = $f;
            }
        }
    }
    return $fields;
}

/** Unformatted value of one top-level field (rows keyed by sub-field key). */
function bulkseme_acf_field_value($post_id, array $field) {
    if (function_exists('acf_get_value')) {
        return acf_get_value($post_id, $field);
    }
    if (function_exists('get_field')) {
        return get_field($field['key'], $post_id, false);
    }
    return null;
}

/** Split a node path into (el, setting): el runs to the last row index, or the field name. */
function bulkseme_acf_split_path($path) {
    $segs = explode('.', (string) $path);
    $last = -1;
    foreach ($segs as $i => $s) {
        if (strpos($s, '[') !== false) {
            $last = $i;
        }
    }
    if ($last === -1) {
        $el   = $segs[0];
        $rest = array_slice($segs, 1);
    } else {
        $el   = implode('.', array_slice($segs, 0, $last + 1));
        $rest = array_slice($segs, $last + 1);
    }
    return [$el, $rest ? implode('.', $rest) : 'value'];
}

/** Load every text leaf of a post's ACF fields; WP_Error when there are none. */
function bulkseme_acf_load($post_id) {
    if (!bulkseme_acf_active()) {
        return new WP_Error('acf_inactive', 'Advanced Custom Fields is not active on this site', ['status' => 409]);
    }
    $fields = bulkseme_acf_top_fields($post_id);
    if (!$fields) {
        return new WP_Error('no_acf_fields', 'post has no ACF fields', ['status' => 409]);
    }
    $nodes   = [];
    $skipped = [];
    $raw     = [];
    foreach ($fields as $field) {
        $value = bulkseme_acf_field_value($post_id, $field);
        $raw[(string) $field['name']] = $value;
        bulkseme_acf_walk($field, $value, (string) $field['name'], (string) $field['name'], $nodes, $skipped);
    }
    if (!$nodes) {
        return new WP_Error('no_acf_text', 'post has ACF fields but no text, textarea or wysiwyg values', ['status' => 409]);
    }
    $index = [];
    $out   = [];
    foreach ($nodes as $n) {
        if (isset($index[$n['path'] . '|'])) {
            continue; // same path twice (duplicated meta reference): keep the first
        }
        list($el, $setting) = bulkseme_acf_split_path($n['path']);
        $n['el']      = $el;
        $n['setting'] = $setting;
        $n['dirty']   = false;
        $index[$n['path'] . '|'] = true;
        $index[$el . '|' . $setting] = count($out);
        $out[] = $n;
    }
    return ['post_id' => (int) $post_id, 'nodes' => $out, 'index' => $index, 'skipped' => $skipped, 'raw' => $raw];
}

/** True when the post has at least one ACF text leaf (used by builder detection). */
function bulkseme_acf_has_text($post_id) {
    $data = bulkseme_acf_load($post_id);
    return !is_wp_error($data) && !empty($data['nodes']);
}

/** Canonical JSON of the text leaves: the data_hash source and the backup payload. */
function bulkseme_acf_encode(array $data) {
    $leaves = [];
    foreach ($data['nodes'] as $n) {
        $leaves[$n['path']] = ['meta_key' => $n['meta_key'], 'key' => $n['key'], 'type' => $n['type'], 'text' => $n['text']];
    }
    $json = wp_json_encode(['post_id' => $data['post_id'], 'leaves' => $leaves]);
    return $json === false ? '' : $json;
}

function bulkseme_acf_public_nodes(array $data) {
    $out = [];
    foreach ($data['nodes'] as $n) {
        $out[] = [
            'el' => $n['el'], 'setting' => $n['setting'], 'widget' => 'acf:' . $n['field_type'], 'field' => $n['label'],
            'type' => $n['type'], 'text' => $n['text'], 'html' => ($n['type'] === 'html'), 'editable' => true,
            'path' => $n['path'], 'meta_key' => $n['meta_key'],
        ];
    }
    return $out;
}

function bulkseme_acf_clean($value, $type, $unsafe_html) {
    if ($type === 'html') {
        $value = bulkseme_content_utf8($value);
        return bulkseme_content_raw_html_allowed($unsafe_html) ? $value : wp_kses_post($value);
    }
    if ($type === 'textarea') {
        return bulkseme_content_sanitize($value, 'textarea');
    }
    return bulkseme_content_sanitize($value, 'text');
}

/** Apply updates[] to the loaded leaves (mutating). Returns [results, changed]. */
function bulkseme_acf_apply_updates(array &$data, array $updates, $unsafe_html) {
    $results = [];
    $changed = 0;
    foreach ($updates as $u) {
        $el      = is_array($u) && isset($u['el']) && is_string($u['el']) ? $u['el'] : '';
        $setting = is_array($u) && isset($u['setting']) && is_string($u['setting']) ? $u['setting'] : '';
        $res     = ['el' => $el, 'setting' => $setting];
        if ($setting === '') {
            $setting = 'value'; // a bare el addresses a top-level field
        }
        if (!is_array($u) || !array_key_exists('value', $u) || !(is_string($u['value']) || is_numeric($u['value']))) {
            $res['status'] = 'invalid_value';
            $results[] = $res;
            continue;
        }
        $k = $el . '|' . $setting;
        if ($el === '' || !isset($data['index'][$k]) || !is_int($data['index'][$k])) {
            $el_known = false;
            foreach ($data['nodes'] as $n) {
                if ($n['el'] === $el) {
                    $el_known = true;
                    break;
                }
            }
            $res['status'] = $el_known ? 'setting_not_editable' : 'el_not_found';
            $results[] = $res;
            continue;
        }
        $i     = $data['index'][$k];
        $prev  = $data['nodes'][$i]['text'];
        $clean = bulkseme_acf_clean($u['value'], $data['nodes'][$i]['type'], $unsafe_html);
        if ($clean === $prev) {
            $res['status'] = 'unchanged';
        } else {
            $data['nodes'][$i]['text']  = $clean;
            $data['nodes'][$i]['dirty'] = true;
            $changed++;
            $res['status']   = 'ok';
            $res['previous'] = $prev;
            $res['new']      = $clean;
            $res['meta_key'] = $data['nodes'][$i]['meta_key'];
        }
        $results[] = $res;
    }
    return [$results, $changed];
}

/**
 * Write the dirty leaves. Each one is checked against the `_<meta key>`
 * reference ACF stores next to the value; a leaf whose reference no longer
 * names the field (field renamed or moved since the read) is left alone and
 * reported under reference_mismatch.
 */
function bulkseme_acf_write($post_id, array &$data) {
    $written  = [];
    $mismatch = [];
    foreach ($data['nodes'] as $i => $n) {
        if (empty($n['dirty'])) {
            continue;
        }
        $data['nodes'][$i]['dirty'] = false;
        $ref = get_post_meta($post_id, '_' . $n['meta_key'], true);
        if (!is_string($ref) || $ref !== $n['key']) {
            $mismatch[] = ['path' => $n['path'], 'meta_key' => $n['meta_key'], 'expected' => $n['key'], 'found' => is_string($ref) ? $ref : ''];
            continue;
        }
        update_metadata('post', $post_id, $n['meta_key'], wp_slash($n['text']));
        $written[] = $n['path'];
    }
    if ($written) {
        bulkseme_content_touch($post_id); // bump modified so caches and sitemaps notice
    }
    return ['written' => $written, 'reference_mismatch' => $mismatch, 'post_content_synced' => false];
}

function bulkseme_acf_commit($post_id, $prev_json, array $data, array $results, $changed, $dry_run) {
    $out = [
        'id' => $post_id, 'builder' => 'acf', 'dry_run' => (bool) $dry_run,
        'count' => count($results), 'changed' => $changed, 'results' => $results,
    ];
    if ($dry_run || $changed === 0) {
        $out['data_hash'] = md5($prev_json);
        return $out;
    }
    $out['backup_ts'] = bulkseme_content_take_backup($post_id, 'acf', $prev_json, 'pre-update');
    if (!$out['backup_ts']) {
        return new WP_Error('backup_failed', 'could not store a backup of the current content; nothing was written', ['status' => 500]);
    }
    $side = bulkseme_acf_write($post_id, $data);
    if ($side['reference_mismatch']) {
        $bad = [];
        foreach ($side['reference_mismatch'] as $m) {
            $bad[$m['path']] = true;
        }
        foreach ($out['results'] as $i => $r) {
            if (isset($r['status']) && $r['status'] === 'ok') {
                $p = ($r['setting'] === 'value' || $r['setting'] === '') ? $r['el'] : $r['el'] . '.' . $r['setting'];
                if (isset($bad[$p])) {
                    $out['results'][$i]['status'] = 'meta_reference_mismatch';
                    $out['changed']--;
                }
            }
        }
    }
    $out = array_merge($out, $side);
    $fresh = bulkseme_acf_load($post_id);
    $out['data_hash'] = is_wp_error($fresh) ? md5(bulkseme_acf_encode($data)) : md5(bulkseme_acf_encode($fresh));
    do_action('bulkseme_content_updated', $post_id, $out['results'], get_current_user_id());
    return $out;
}

/** Restore one `acf` backup envelope leaf by leaf; itself undoable. */
function bulkseme_acf_revert($post, array $env, $ts) {
    $id   = $post->ID;
    $snap = json_decode((string) $env['data'], true);
    if (!is_array($snap) || !isset($snap['leaves']) || !is_array($snap['leaves'])) {
        return new WP_Error('backup_corrupt', 'backup payload is not valid JSON', ['status' => 500]);
    }
    $current = bulkseme_acf_load($id);
    $pre_ts  = bulkseme_content_take_backup($id, 'acf', is_wp_error($current) ? '' : bulkseme_acf_encode($current), 'pre-revert');
    if (!$pre_ts) {
        return new WP_Error('backup_failed', 'could not store a backup of the current content; nothing was restored', ['status' => 500]);
    }
    $unfiltered = current_user_can('unfiltered_html');
    // Only fields the caller's own field groups show are restored: a backup can
    // have been taken by someone who sees more of them.
    $visible = [];
    if (!is_wp_error($current)) {
        foreach ($current['nodes'] as $n) {
            $visible[$n['meta_key'] . '|' . $n['key']] = true;
        }
    }
    $restored = [];
    $skipped  = [];
    foreach ($snap['leaves'] as $path => $leaf) {
        if (!is_array($leaf) || empty($leaf['meta_key']) || empty($leaf['key']) || !isset($leaf['text']) || !is_string($leaf['text'])) {
            continue;
        }
        if (!isset($visible[$leaf['meta_key'] . '|' . $leaf['key']])) {
            $skipped[] = ['path' => $path, 'reason' => 'not_in_your_field_groups'];
            continue;
        }
        $ref = get_post_meta($id, '_' . $leaf['meta_key'], true);
        if (!is_string($ref) || $ref !== $leaf['key']) {
            $skipped[] = ['path' => $path, 'reason' => 'meta_reference_mismatch'];
            continue;
        }
        $text = $leaf['text'];
        if (!$unfiltered) {
            $text = bulkseme_acf_clean($text, isset($leaf['type']) ? $leaf['type'] : 'html', false);
        }
        $cur = get_post_meta($id, $leaf['meta_key'], true);
        if (is_string($cur) && $cur === $text) {
            continue;
        }
        update_metadata('post', $id, $leaf['meta_key'], wp_slash($text));
        $restored[] = $path;
    }
    if ($restored) {
        bulkseme_content_touch($id);
    }
    do_action('bulkseme_content_reverted', $id, $ts, get_current_user_id());
    $after = bulkseme_acf_load($id);
    return [
        'id' => $id, 'status' => 'ok', 'reverted_to' => $ts, 'pre_revert_backup_ts' => $pre_ts, 'kind' => 'acf',
        'restored' => $restored, 'skipped' => $skipped, 'post_content_synced' => false,
        'data_hash' => is_wp_error($after) ? '' : md5(bulkseme_acf_encode($after)),
    ];
}
