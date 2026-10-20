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
 *
 * Rows. Every repeater and flexible content field is also listed as a
 * container (`containers`: its path, its row count and the sub fields a new row
 * may fill). An update `{op: "add_row", el, position, layout, values}` inserts
 * one row: the rows from `position` on move down one place with every value
 * they hold (images, nested rows and all), the new row gets the given text
 * values, cleaned like any other write, and the row count (or the flexible
 * field's layout list) grows by one. Only meta whose ACF reference names a
 * field of that container is moved; a stored value of any other field in the
 * way stops the insert before anything is written. One request adds rows to
 * one container. The backup (kind `acf_rows`) holds every stored value of the
 * top-level field the rows sit in, so a revert puts its rows back exactly.
 *
 * Terms. The same handlers read and write a taxonomy term's ACF fields (term
 * meta, which ACF addresses as `term_<id>`), through /content with kind=term.
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

/**
 * Where a set of ACF values lives. A post id stands for that post's own meta;
 * a term target comes from bulkseme_acf_term_target(). `acf_id` is the id ACF's
 * own functions take for it.
 */
function bulkseme_acf_target($target) {
    if (is_array($target) && isset($target['meta'], $target['id'], $target['taxonomy']) && $target['meta'] === 'term') {
        return ['meta' => 'term', 'id' => (int) $target['id'], 'acf_id' => 'term_' . (int) $target['id'], 'taxonomy' => (string) $target['taxonomy']];
    }
    if ($target instanceof WP_Post) {
        $id = (int) $target->ID;
    } elseif (is_array($target)) {
        $id = isset($target['id']) ? (int) $target['id'] : 0;
    } else {
        $id = (int) $target;
    }
    return ['meta' => 'post', 'id' => $id, 'acf_id' => $id, 'taxonomy' => ''];
}

function bulkseme_acf_term_target($term) {
    $id = (int) $term->term_id;
    return ['meta' => 'term', 'id' => $id, 'acf_id' => 'term_' . $id, 'taxonomy' => (string) $term->taxonomy];
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
 * Every repeater and flexible content field met on the way is added to
 * $containers, keyed by its path.
 */
function bulkseme_acf_walk(array $field, $value, $meta, $path, array &$nodes, array &$skipped, $depth = 0, &$containers = null) {
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
    if (($type === 'flexible_content' || $type === 'repeater') && is_array($containers)) {
        $rows = 0;
        $used = [];
        foreach ((is_array($value) ? $value : []) as $i => $row) {
            if (!is_int($i)) {
                continue;
            }
            $rows++;
            if ($type === 'flexible_content' && is_array($row) && isset($row['acf_fc_layout']) && is_string($row['acf_fc_layout'])) {
                $l = $row['acf_fc_layout'];
                $used[$l] = isset($used[$l]) ? $used[$l] + 1 : 1;
            }
        }
        $containers[$path] = ['path' => $path, 'meta_key' => $meta, 'key' => $key, 'type' => $type, 'rows' => $rows, 'layouts_used' => $used, 'field' => $field];
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
                bulkseme_acf_walk($sub, bulkseme_acf_sub_value($row, $sub), $meta . '_' . $i . '_' . $sub['name'], $path . '[' . $i . '].' . $sub['name'], $nodes, $skipped, $depth + 1, $containers);
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
                bulkseme_acf_walk($sub, bulkseme_acf_sub_value($row, $sub), $meta . '_' . $i . '_' . $sub['name'], $path . '[' . $i . '].' . $sub['name'], $nodes, $skipped, $depth + 1, $containers);
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
            bulkseme_acf_walk($sub, bulkseme_acf_sub_value($value, $sub), $meta . '_' . $sub['name'], $path . '.' . $sub['name'], $nodes, $skipped, $depth + 1, $containers);
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

/** Top-level ACF fields for a post or term, keyed by field key, with sub fields/layouts hydrated. */
function bulkseme_acf_top_fields($target) {
    $t      = bulkseme_acf_target($target);
    $fields = [];
    if (function_exists('acf_get_field_groups') && function_exists('acf_get_fields')) {
        if ($t['meta'] === 'term') {
            $args = ['taxonomy' => $t['taxonomy']];
        } else {
            $args = ['post_id' => $t['id']];
            $post = get_post($t['id']);
            if ($post && !empty($post->post_type)) {
                $args['post_type'] = $post->post_type;
            }
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
    if (!$fields && function_exists('get_field_objects') && current_user_can('manage_options') && (!is_multisite() || is_super_admin())) {
        // Location rules did not match (group moved, legacy data): fall back
        // to the fields the stored meta references. Administrators only (on a
        // network, super admins), since a field group can be limited to certain
        // roles by those same rules.
        $objects = get_field_objects($t['acf_id'], false, false);
        foreach ((is_array($objects) ? $objects : []) as $f) {
            if (is_array($f) && !empty($f['key']) && !empty($f['name'])) {
                $fields[$f['key']] = $f;
            }
        }
    }
    return $fields;
}

/** Unformatted value of one top-level field (rows keyed by sub-field key). */
function bulkseme_acf_field_value($target, array $field) {
    $t = bulkseme_acf_target($target);
    if (function_exists('acf_get_value')) {
        return acf_get_value($t['acf_id'], $field);
    }
    if (function_exists('get_field')) {
        return get_field($field['key'], $t['acf_id'], false);
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

/** Name of the top-level field a node or container path sits in. */
function bulkseme_acf_path_top($path) {
    return preg_match('/^[^\[.]+/', (string) $path, $m) ? $m[0] : '';
}

/**
 * Load every text leaf and every row container of a post's (or term's) ACF
 * fields; WP_Error when there are none.
 */
function bulkseme_acf_load($target) {
    $t = bulkseme_acf_target($target);
    if (!bulkseme_acf_active()) {
        return new WP_Error('acf_inactive', 'Advanced Custom Fields is not active on this site', ['status' => 409]);
    }
    $what   = $t['meta'] === 'term' ? 'term' : 'post';
    $fields = bulkseme_acf_top_fields($t);
    if (!$fields) {
        return new WP_Error('no_acf_fields', $what . ' has no ACF fields', ['status' => 409]);
    }
    $nodes      = [];
    $skipped    = [];
    $raw        = [];
    $containers = [];
    foreach ($fields as $field) {
        $value = bulkseme_acf_field_value($t, $field);
        $raw[(string) $field['name']] = $value;
        bulkseme_acf_walk($field, $value, (string) $field['name'], (string) $field['name'], $nodes, $skipped, 0, $containers);
    }
    if (!$nodes && !$containers) {
        return new WP_Error('no_acf_text', $what . ' has ACF fields but no text, textarea or wysiwyg values', ['status' => 409]);
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
    return [
        'post_id' => $t['meta'] === 'post' ? $t['id'] : 0, 'target' => $t, 'nodes' => $out, 'index' => $index,
        'skipped' => $skipped, 'raw' => $raw, 'containers' => $containers, 'fields' => $fields, 'row_ops' => [],
    ];
}

/** True when the post has at least one ACF text leaf (used by builder detection). */
function bulkseme_acf_has_text($post_id) {
    $data = bulkseme_acf_load($post_id);
    return !is_wp_error($data) && !empty($data['nodes']);
}

/**
 * Canonical JSON of the text leaves (and the row count of every container):
 * the data_hash source and the payload of an `acf` backup.
 */
function bulkseme_acf_encode(array $data) {
    $leaves = [];
    foreach ($data['nodes'] as $n) {
        $leaves[$n['path']] = ['meta_key' => $n['meta_key'], 'key' => $n['key'], 'type' => $n['type'], 'text' => $n['text']];
    }
    $head = (isset($data['target']['meta']) && $data['target']['meta'] === 'term') ? ['term_id' => (int) $data['target']['id']] : ['post_id' => $data['post_id']];
    $body = ['leaves' => $leaves];
    if (!empty($data['containers'])) {
        $rows = [];
        foreach ($data['containers'] as $path => $c) {
            $rows[$path] = (int) $c['rows'];
        }
        $body['rows'] = $rows;
    }
    $json = wp_json_encode($head + $body);
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

/** The sub fields a new row may fill, as GET lists them. */
function bulkseme_acf_public_subs($subs) {
    $leaf = bulkseme_acf_leaf_types();
    $out  = [];
    foreach ((is_array($subs) ? $subs : []) as $s) {
        if (!is_array($s) || empty($s['name']) || empty($s['type'])) {
            continue;
        }
        $type  = (string) $s['type'];
        $out[] = [
            'name'     => (string) $s['name'],
            'type'     => $type,
            'label'    => isset($s['label']) && is_string($s['label']) ? $s['label'] : (string) $s['name'],
            'editable' => isset($leaf[$type]),
        ];
    }
    return $out;
}

/** Repeaters and flexible content fields a row can be added to (`op: add_row`). */
function bulkseme_acf_public_containers(array $data) {
    $out = [];
    foreach ((isset($data['containers']) && is_array($data['containers'])) ? $data['containers'] : [] as $c) {
        $f    = $c['field'];
        $item = [
            'el'    => $c['path'],
            'type'  => $c['type'],
            'label' => isset($f['label']) && is_string($f['label']) ? $f['label'] : (string) $f['name'],
            'rows'  => (int) $c['rows'],
            'max'   => isset($f['max']) && is_numeric($f['max']) ? (int) $f['max'] : 0,
        ];
        if ($c['type'] === 'repeater') {
            $item['sub_fields'] = bulkseme_acf_public_subs(isset($f['sub_fields']) ? $f['sub_fields'] : []);
        } else {
            $item['layouts'] = [];
            foreach ((isset($f['layouts']) && is_array($f['layouts'])) ? $f['layouts'] : [] as $l) {
                if (!is_array($l) || empty($l['name'])) {
                    continue;
                }
                $item['layouts'][] = [
                    'name'       => (string) $l['name'],
                    'label'      => isset($l['label']) && is_string($l['label']) ? $l['label'] : (string) $l['name'],
                    'max'        => isset($l['max']) && is_numeric($l['max']) ? (int) $l['max'] : 0,
                    'sub_fields' => bulkseme_acf_public_subs(isset($l['sub_fields']) ? $l['sub_fields'] : []),
                ];
            }
        }
        $out[] = $item;
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

/**
 * Apply updates[] to the loaded leaves (mutating). Returns [results, changed].
 * An update with `op` plans a row insert instead; it is carried out by the
 * commit, after the text edits.
 */
function bulkseme_acf_apply_updates(array &$data, array $updates, $unsafe_html) {
    $results = [];
    $changed = 0;
    if (!isset($data['row_ops']) || !is_array($data['row_ops'])) {
        $data['row_ops'] = [];
    }
    foreach ($updates as $u) {
        if (is_array($u) && array_key_exists('op', $u)) {
            $res = bulkseme_acf_plan_row($data, $u, $unsafe_html);
            if ($res['status'] === 'ok') {
                $data['row_ops'][count($data['row_ops']) - 1]['ri'] = count($results);
                $changed++;
            }
            $results[] = $res;
            continue;
        }
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
 * Check one `add_row` update against the container's own definition and queue
 * it on $data['row_ops']. Every value must name a text, textarea or wysiwyg
 * sub field of the row (or of the chosen layout) and is cleaned the way any
 * other write to that field type is. `position` counts from 0 and defaults to
 * the end; a later insert into the same container counts with the rows the
 * earlier ones added.
 */
function bulkseme_acf_plan_row(array &$data, array $u, $unsafe_html) {
    $op  = is_string($u['op']) ? $u['op'] : '';
    $el  = isset($u['el']) && is_string($u['el']) ? $u['el'] : '';
    $res = ['op' => $op, 'el' => $el];
    if ($op !== 'add_row') {
        $res['status'] = 'unknown_op';
        return $res;
    }
    if ($el === '' || !isset($data['containers'][$el])) {
        $res['status'] = 'container_not_found';
        return $res;
    }
    if ($data['row_ops'] && $data['row_ops'][0]['el'] !== $el) {
        $res['status'] = 'one_container_per_request';
        return $res;
    }
    if (count($data['row_ops']) >= 20) {
        $res['status'] = 'too_many_rows';
        return $res;
    }
    $c     = $data['containers'][$el];
    $field = $c['field'];
    $rows  = (int) $c['rows'];
    $pos   = $rows;
    if (isset($u['position'])) {
        $p = $u['position'];
        if (is_int($p)) {
            $pos = $p;
        } elseif (is_string($p) && preg_match('/^[0-9]{1,6}$/', $p)) {
            $pos = (int) $p;
        } else {
            $res['status'] = 'invalid_position';
            return $res;
        }
    }
    if ($pos < 0 || $pos > $rows) {
        $res['status'] = 'invalid_position';
        $res['rows']   = $rows;
        return $res;
    }
    $max = isset($field['max']) && is_numeric($field['max']) ? (int) $field['max'] : 0;
    if ($max > 0 && $rows >= $max) {
        $res['status'] = 'row_limit';
        $res['max']    = $max;
        return $res;
    }
    $layout = '';
    $used   = 0;
    if ($c['type'] === 'flexible_content') {
        $layout = isset($u['layout']) && is_string($u['layout']) ? $u['layout'] : '';
        $def    = null;
        foreach ((isset($field['layouts']) && is_array($field['layouts'])) ? $field['layouts'] : [] as $l) {
            if ($layout !== '' && is_array($l) && isset($l['name']) && (string) $l['name'] === $layout) {
                $def = $l;
                break;
            }
        }
        if (!$def) {
            $res['status'] = 'layout_not_found';
            return $res;
        }
        $lmax = isset($def['max']) && is_numeric($def['max']) ? (int) $def['max'] : 0;
        $used = isset($c['layouts_used'][$layout]) ? (int) $c['layouts_used'][$layout] : 0;
        if ($lmax > 0 && $used >= $lmax) {
            $res['status'] = 'row_limit';
            $res['max']    = $lmax;
            return $res;
        }
        $subs          = isset($def['sub_fields']) && is_array($def['sub_fields']) ? $def['sub_fields'] : [];
        $res['layout'] = $layout;
    } else {
        $subs = isset($field['sub_fields']) && is_array($field['sub_fields']) ? $field['sub_fields'] : [];
    }
    $values = isset($u['values']) && is_array($u['values']) ? $u['values'] : [];
    if (!$values || count($values) > 50) {
        $res['status'] = 'invalid_values';
        return $res;
    }
    $leaf = bulkseme_acf_leaf_types();
    $by   = [];
    foreach ($subs as $s) {
        if (is_array($s) && !empty($s['name']) && !empty($s['key']) && is_string($s['key'])) {
            $by[(string) $s['name']] = $s;
        }
    }
    $clean = [];
    $shown = [];
    foreach ($values as $name => $v) {
        $name = (string) $name;
        if (!isset($by[$name])) {
            $res['status']   = 'subfield_not_found';
            $res['subfield'] = $name;
            return $res;
        }
        $type = isset($by[$name]['type']) ? (string) $by[$name]['type'] : '';
        if (!isset($leaf[$type])) {
            $res['status']   = 'subfield_not_editable';
            $res['subfield'] = $name;
            return $res;
        }
        if (!is_string($v) && !is_numeric($v)) {
            $res['status']   = 'invalid_value';
            $res['subfield'] = $name;
            return $res;
        }
        $text          = bulkseme_acf_clean((string) $v, $leaf[$type], $unsafe_html);
        $clean[$name]  = ['key' => (string) $by[$name]['key'], 'text' => $text];
        $shown[$name]  = $text;
    }
    $data['containers'][$el]['rows'] = $rows + 1;
    if ($layout !== '') {
        $data['containers'][$el]['layouts_used'][$layout] = $used + 1;
    }
    $data['row_ops'][] = [
        'el' => $el, 'meta_key' => $c['meta_key'], 'key' => $c['key'], 'type' => $c['type'], 'position' => $pos,
        'layout' => $layout, 'values' => $clean, 'field' => $field, 'ri' => -1,
    ];
    $res['status']   = 'ok';
    $res['position'] = $pos;
    $res['row']      = $el . '[' . $pos . ']';
    $res['values']   = $shown;
    return $res;
}

// ---- Stored meta of rows --------------------------------------------------------

function bulkseme_acf_meta_all(array $t) {
    $all = get_metadata($t['meta'], $t['id']);
    return is_array($all) ? $all : [];
}

/**
 * The stored values of one meta key, decoded the way /clone reads meta: the
 * stored text is checked before it is unserialised, so a value that names a
 * class is never turned back into an object. Null when any value of the key is
 * such a value, or holds an object of any kind; such a key is never copied.
 */
function bulkseme_acf_meta_values(array $t, $key, $all = null) {
    if (!is_array($all)) {
        $all = bulkseme_acf_meta_all($t);
    }
    $out = [];
    foreach ((isset($all[$key]) ? (array) $all[$key] : []) as $raw) {
        if (!is_string($raw)) {
            return null;
        }
        $ok = true;
        $v  = bulkseme_clone_meta_value($raw, $ok);
        if (!$ok || bulkseme_acf_has_object($v)) {
            return null;
        }
        // Moved, backed up and restored values are written again through add_metadata(),
        // which serialises them. Only a value that comes out as the very same stored bytes
        // is read back: serialised data PHP cannot read (it decodes to false), a serialised
        // scalar, a float stored with other precision, or trailing bytes are refused instead
        // of being rewritten.
        if (maybe_serialize(wp_unslash(wp_slash($v))) !== $raw) {
            return null;
        }
        $json = wp_json_encode($v);
        if ($json === false || maybe_serialize(json_decode($json, true)) !== $raw) {
            return null; // PHP references, or anything else a backup could not hold exactly
        }
        // Text that is not valid UTF-8 would not survive the JSON of a backup unchanged.
        if (!bulkseme_acf_utf8_ok($v)) {
            return null;
        }
        $out[] = $v;
    }
    return $out;
}

/** True when every string in a decoded value is valid UTF-8 (on a UTF-8 site). */
function bulkseme_acf_utf8_ok($v, $depth = 0) {
    if (is_string($v)) {
        return $v === '' || wp_check_invalid_utf8($v) !== '';
    }
    if (is_array($v)) {
        if ($depth > 64) {
            return false;
        }
        foreach ($v as $k => $x) {
            if (!bulkseme_acf_utf8_ok($k, $depth + 1) || !bulkseme_acf_utf8_ok($x, $depth + 1)) {
                return false;
            }
        }
    }
    return true;
}

/** First stored value of a key ('' when there is none, null when it cannot be read safely). */
function bulkseme_acf_meta_one(array $t, $key, $all = null) {
    $vals = bulkseme_acf_meta_values($t, $key, $all);
    if ($vals === null) {
        return null;
    }
    return $vals ? $vals[0] : '';
}

/**
 * True when the stored meta holds a key that the database would match as one of
 * $keys but that is not the same string: meta_key compares without regard to
 * case, accents or trailing spaces, so a delete or update of one would hit the other.
 */
/**
 * A meta key folded the way the database compares keys. $sharp_s is what ß
 * becomes: 'ss' under the unicode collations WordPress creates, 's' under the
 * older general ones; the clash check tries both.
 */
function bulkseme_acf_key_fold($k, $sharp_s = 'ss') {
    // remove_accents() follows the site language unless one is given (German turns ö into oe);
    // the database folds ö to o, which is what the default mapping does.
    $k = strtr(rtrim((string) $k, ' '), ['ß' => $sharp_s, 'ẞ' => $sharp_s, 'ä' => 'a', 'ö' => 'o', 'ü' => 'u', 'Ä' => 'A', 'Ö' => 'O', 'Ü' => 'U']);
    $k = remove_accents($k, 'en_US');
    return function_exists('mb_strtolower') ? mb_strtolower($k, 'UTF-8') : strtolower($k);
}

function bulkseme_acf_case_clash(array $all, array $keys) {
    foreach (['ss', 's'] as $sharp) {
        $fold = [];
        foreach (array_keys($all) as $k) {
            $fold[bulkseme_acf_key_fold($k, $sharp)][] = (string) $k;
        }
        foreach ($keys as $k) {
            foreach ($fold[bulkseme_acf_key_fold($k, $sharp)] ?? [] as $other) {
                if ($other !== (string) $k) {
                    return true;
                }
            }
        }
    }
    return false;
}

/** True when a decoded value holds an object anywhere. */
function bulkseme_acf_has_object($v, $depth = 0) {
    if (is_object($v) || $depth > 64) {
        return true;
    }
    if (is_array($v)) {
        foreach ($v as $x) {
            if (bulkseme_acf_has_object($x, $depth + 1)) {
                return true;
            }
        }
    }
    return false;
}

/** An ACF reference as stored: the field key when it is a plain string, else null. */
function bulkseme_acf_ref_text($raw) {
    return (is_string($raw) && $raw !== '' && !is_serialized($raw)) ? $raw : null;
}

/** Every field definition under a field (sub fields, layouts, at any depth), by key. */
function bulkseme_acf_subtree(array $field) {
    $out = [];
    bulkseme_acf_subtree_collect($field, $out, 0);
    return $out;
}

function bulkseme_acf_subtree_collect(array $field, array &$out, $depth) {
    if ($depth > 8) {
        return;
    }
    $lists = [];
    if (isset($field['sub_fields']) && is_array($field['sub_fields'])) {
        $lists[] = $field['sub_fields'];
    }
    if (isset($field['layouts']) && is_array($field['layouts'])) {
        foreach ($field['layouts'] as $l) {
            if (is_array($l) && isset($l['sub_fields']) && is_array($l['sub_fields'])) {
                $lists[] = $l['sub_fields'];
            }
        }
    }
    foreach ($lists as $list) {
        foreach ($list as $sub) {
            if (is_array($sub) && !empty($sub['key']) && is_string($sub['key'])) {
                $out[$sub['key']] = $sub;
                bulkseme_acf_subtree_collect($sub, $out, $depth + 1);
            }
        }
    }
}

/**
 * Whether a stored key under a field is that field's own. Its ACF reference
 * (for a value key the `_<key>` beside it, for a reference key its own value)
 * must be a plain field key of the field's tree, and the key must end in that
 * sub field's name, the way ACF composes it. A value with no reference, or with
 * the reference of some other field, belongs to something else and is left alone.
 */
function bulkseme_acf_key_is_own($k, $is_ref, array $all, array $subtree) {
    if (strpos($k, '\\') !== false) {
        return false; // WordPress unslashes meta keys, so such a key could not be written back as it is
    }
    if ($is_ref) {
        $raw  = isset($all[$k][0]) ? $all[$k][0] : null;
        $base = substr($k, 1);
    } else {
        $raw  = isset($all['_' . $k][0]) ? $all['_' . $k][0] : null;
        $base = $k;
    }
    $ref = bulkseme_acf_ref_text($raw);
    if ($ref === null || !isset($subtree[$ref]['name'])) {
        return false;
    }
    $tail = '_' . (string) $subtree[$ref]['name'];
    return strlen($base) > strlen($tail) && substr($base, -strlen($tail)) === $tail;
}

/**
 * The stored row meta under one container: every key `<prefix>_<n>_...` and its
 * `_` reference, sorted into the container's own keys and keys that only match
 * the pattern (see bulkseme_acf_key_is_own).
 */
function bulkseme_acf_row_keys(array $t, $prefix, array $subtree, array $all) {
    $own     = [];
    $foreign = [];
    $re      = '/^(_?)' . preg_quote($prefix, '/') . '_([0-9]+)_(.+)$/';
    foreach (array_keys($all) as $k) {
        $k = (string) $k;
        if (!preg_match($re, $k, $m)) {
            continue;
        }
        $is_ref = ($m[1] === '_');
        $entry  = ['k' => $k, 'idx' => (int) $m[2], 'rest' => $m[3], 'ref' => $is_ref];
        if (bulkseme_acf_key_is_own($k, $is_ref, $all, $subtree)) {
            $own[] = $entry;
        } else {
            $foreign[] = $entry;
        }
    }
    return ['own' => $own, 'foreign' => $foreign];
}

/**
 * Carry out one planned row insert. Returns 'ok' or the reason nothing was
 * written: the container's stored count or reference no longer matches what
 * was read (conflict, meta_reference_mismatch), a key of something else is in
 * the way (meta_collision), a stored value cannot be copied safely
 * (unsupported_meta), or the rows to move are too many (too_large). Every check
 * runs before the first write.
 */
/** Names of a container's own sub fields, layouts included: the first part of the rest of a row key. */
function bulkseme_acf_row_sub_names(array $field) {
    $names = [];
    $lists = [isset($field['sub_fields']) && is_array($field['sub_fields']) ? $field['sub_fields'] : []];
    foreach ((isset($field['layouts']) && is_array($field['layouts'])) ? $field['layouts'] : [] as $l) {
        if (is_array($l) && isset($l['sub_fields']) && is_array($l['sub_fields'])) {
            $lists[] = $l['sub_fields'];
        }
    }
    foreach ($lists as $list) {
        foreach ($list as $sub) {
            if (is_array($sub) && !empty($sub['name']) && is_string($sub['name'])) {
                $names[$sub['name']] = true;
            }
        }
    }
    return array_keys($names);
}

function bulkseme_acf_insert_row(array $t, array $op, &$budget, array &$touched) {
    $prefix = (string) $op['meta_key'];
    $all    = bulkseme_acf_meta_all($t);
    $cref   = isset($all['_' . $prefix]) ? bulkseme_acf_ref_text(isset($all['_' . $prefix][0]) ? $all['_' . $prefix][0] : null) : null;
    if ((isset($all[$prefix]) || isset($all['_' . $prefix])) && $cref !== $op['key']) {
        return 'meta_reference_mismatch';
    }
    $stored = isset($all[$prefix]) ? bulkseme_acf_meta_one($t, $prefix, $all) : '';
    if ($stored === null) {
        return 'unsupported_meta';
    }
    $list = [];
    if ($op['type'] === 'flexible_content') {
        if (is_array($stored)) {
            $list = array_values($stored);
        } elseif ($stored !== '') {
            return 'unsupported_meta';
        }
        foreach ($list as $ln) {
            if (!is_string($ln)) {
                return 'unsupported_meta';
            }
        }
        $rows = count($list);
    } else {
        if ($stored !== '' && !is_numeric($stored)) {
            return 'unsupported_meta';
        }
        $rows = (int) $stored;
    }
    $pos = (int) $op['position'];
    if ($pos < 0 || $pos > $rows) {
        return 'conflict';
    }
    $found   = bulkseme_acf_row_keys($t, $prefix, bulkseme_acf_subtree($op['field']), $all);
    $moves   = [];
    $vacated = [];
    foreach ($found['own'] as $e) {
        if ($e['idx'] >= $pos) {
            $moves[]           = $e;
            $vacated[$e['k']] = true;
        }
    }
    // A value of something else sitting under one of the container's own sub field names in a row
    // that would move: moving the rest of the row without it would pair values of different rows.
    $subs = bulkseme_acf_row_sub_names($op['field']);
    foreach ($found['foreign'] as $e) {
        if ($e['idx'] < $pos) {
            continue;
        }
        foreach ($subs as $n) {
            if ($e['rest'] === $n || strpos($e['rest'], $n . '_') === 0) {
                return 'meta_collision';
            }
        }
    }
    if (count($moves) > $budget) {
        return 'too_large';
    }
    $budget -= count($moves);
    $dest = [];
    $vals = [];
    foreach ($moves as $e) {
        $d = ($e['ref'] ? '_' : '') . $prefix . '_' . ($e['idx'] + 1) . '_' . $e['rest'];
        if (isset($all[$d]) && !isset($vacated[$d])) {
            return 'meta_collision';
        }
        $v = bulkseme_acf_meta_values($t, $e['k'], $all);
        if ($v === null) {
            return 'unsupported_meta';
        }
        $dest[$e['k']] = $d;
        $vals[$e['k']] = $v;
    }
    $writes = [$prefix, '_' . $prefix];
    foreach ($op['values'] as $name => $v) {
        $mk = $prefix . '_' . $pos . '_' . $name;
        if ((isset($all[$mk]) && !isset($vacated[$mk])) || (isset($all['_' . $mk]) && !isset($vacated['_' . $mk]))) {
            return 'meta_collision';
        }
        $writes[] = $mk;
        $writes[] = '_' . $mk;
    }
    foreach ($moves as $e) {
        $writes[] = $e['k'];
        $writes[] = $dest[$e['k']];
    }
    if (bulkseme_acf_case_clash($all, $writes)) {
        return 'meta_collision';
    }
    // From the last row up, so a key is only ever written where the row below has already moved away.
    usort($moves, function ($a, $b) {
        return $b['idx'] - $a['idx'];
    });
    // Every key about to change is noted first, so a rollback can put exactly these back.
    foreach ($moves as $e) {
        $touched[$e['k']]          = true;
        $touched[$dest[$e['k']]] = true;
    }
    foreach ($op['values'] as $name => $v) {
        $touched[$prefix . '_' . $pos . '_' . $name]       = true;
        $touched['_' . $prefix . '_' . $pos . '_' . $name] = true;
    }
    $touched[$prefix]       = true;
    $touched['_' . $prefix] = true;
    // Any write the database refuses stops here; the commit then rolls the request back.
    foreach ($moves as $e) {
        foreach ($vals[$e['k']] as $v) {
            if (!add_metadata($t['meta'], $t['id'], $dest[$e['k']], wp_slash($v))) {
                return 'write_failed';
            }
        }
        if (!delete_metadata($t['meta'], $t['id'], $e['k'])) {
            return 'write_failed';
        }
    }
    foreach ($op['values'] as $name => $v) {
        $mk = $prefix . '_' . $pos . '_' . $name;
        if (!add_metadata($t['meta'], $t['id'], $mk, wp_slash($v['text'])) || !add_metadata($t['meta'], $t['id'], '_' . $mk, $v['key'])) {
            return 'write_failed';
        }
    }
    if ($op['type'] === 'flexible_content') {
        array_splice($list, $pos, 0, [$op['layout']]);
        $ok = update_metadata($t['meta'], $t['id'], $prefix, wp_slash($list));
    } else {
        $ok = update_metadata($t['meta'], $t['id'], $prefix, $rows + 1);
    }
    if (!$ok) {
        return 'write_failed';
    }
    if ($cref !== $op['key'] && !update_metadata($t['meta'], $t['id'], '_' . $prefix, $op['key'])) {
        return 'write_failed';
    }
    return 'ok';
}

/**
 * The stored keys of one top-level field: its own value and reference, and
 * every key `<name>_...` (rows, group members, nested rows) that is the field's
 * own by bulkseme_acf_key_is_own. Other keys that only start the same way are
 * listed apart and never touched.
 */
function bulkseme_acf_field_keys(array $field, array $all) {
    $name    = (string) $field['name'];
    $subtree = bulkseme_acf_subtree($field);
    $own     = [];
    $foreign = [];
    foreach (array_keys($all) as $k) {
        $k = (string) $k;
        if ($k === $name || $k === '_' . $name) {
            $own[] = $k;
            continue;
        }
        if (strpos($k, '_' . $name . '_') === 0) {
            $is_ref = true;
        } elseif (strpos($k, $name . '_') === 0) {
            $is_ref = false;
        } else {
            continue;
        }
        if (bulkseme_acf_key_is_own($k, $is_ref, $all, $subtree)) {
            $own[] = $k;
        } else {
            $foreign[] = $k;
        }
    }
    return ['own' => $own, 'foreign' => $foreign];
}

/**
 * Every stored value of one top-level field, for an `acf_rows` backup.
 * WP_Error when its reference names another field, or a value holds an object.
 */
function bulkseme_acf_field_snapshot(array $t, array $field, array $all) {
    $name = (string) $field['name'];
    if (isset($all['_' . $name]) && bulkseme_acf_ref_text(isset($all['_' . $name][0]) ? $all['_' . $name][0] : null) !== (string) $field['key']) {
        return new WP_Error('meta_reference_mismatch', 'the stored reference of ' . $name . ' names another field; nothing was written', ['status' => 409]);
    }
    $meta = [];
    foreach (bulkseme_acf_field_keys($field, $all)['own'] as $k) {
        $vals = bulkseme_acf_meta_values($t, $k, $all);
        if ($vals === null) {
            return new WP_Error('unsupported_meta', 'a stored value of ' . $name . ' holds an object and cannot be backed up; nothing was written', ['status' => 409]);
        }
        $meta[] = ['k' => $k, 'v' => $vals];
    }
    return ['key' => (string) $field['key'], 'name' => $name, 'meta' => $meta];
}

/** Field types whose own stored value is structure (a row count, a layout list, a group's empty value). */
function bulkseme_acf_structural_types() {
    return ['repeater', 'flexible_content', 'group'];
}

/** Whether a stored value is a valid count, layout list or group value for this field. */
function bulkseme_acf_structural_ok(array $def, array $vals) {
    $v    = isset($vals[0]) ? $vals[0] : '';
    $type = isset($def['type']) ? (string) $def['type'] : '';
    if (count($vals) > 1) {
        return false;
    }
    if ($type === 'repeater') {
        return $v === '' || (is_numeric($v) && (int) $v >= 0);
    }
    if ($type === 'flexible_content') {
        if ($v === '') {
            return true;
        }
        if (!is_array($v)) {
            return false;
        }
        $names = [];
        foreach ((isset($def['layouts']) && is_array($def['layouts'])) ? $def['layouts'] : [] as $l) {
            if (is_array($l) && isset($l['name'])) {
                $names[(string) $l['name']] = true;
            }
        }
        foreach ($v as $ln) {
            if (!is_string($ln) || !isset($names[$ln])) {
                return false;
            }
        }
        return true;
    }
    return $v === '';
}

/**
 * Work out how to put one top-level field back the way an `acf_rows` backup
 * holds it, without writing anything: the field's own keys that are there now
 * and not in the backup go, the backup's own keys go back. A backup key that is
 * not the field's own (bulkseme_acf_key_is_own, judged on the backup's own
 * references), or where a key of something else now sits, is not restored.
 *
 * For a caller without unfiltered_html ($exact false) every text value is
 * cleaned like any other write, and a value that is not text (an image, a
 * link, an option) may only come back if the same value is stored in the same
 * sub field now: such a caller can undo the rows' structure, but never bring
 * back a value somebody has since removed or changed. Returns ['delete' => [],
 * 'write' => []] or WP_Error.
 */
function bulkseme_acf_restore_plan(array $t, array $field, array $snap, $exact) {
    $name    = (string) $field['name'];
    $subtree = bulkseme_acf_subtree($field);
    $leaf    = bulkseme_acf_leaf_types();
    $struct  = array_flip(bulkseme_acf_structural_types());
    $all     = bulkseme_acf_meta_all($t);
    if (isset($all['_' . $name]) && bulkseme_acf_ref_text(isset($all['_' . $name][0]) ? $all['_' . $name][0] : null) !== (string) $field['key']) {
        return new WP_Error('meta_reference_mismatch', 'the stored reference of ' . $name . ' names another field; nothing was restored', ['status' => 409]);
    }
    $now     = bulkseme_acf_field_keys($field, $all);
    $foreign = array_flip($now['foreign']);
    $want    = [];
    foreach ((isset($snap['meta']) && is_array($snap['meta'])) ? $snap['meta'] : [] as $m) {
        if (is_array($m) && isset($m['k'], $m['v']) && is_string($m['k']) && is_array($m['v']) && !bulkseme_acf_has_object($m['v'])) {
            $want[$m['k']] = array_values($m['v']);
        }
    }
    $backup = $want; // the backup as a meta map, to judge its keys the way stored ones are judged
    foreach (array_keys($want) as $k) {
        $k = (string) $k;
        if (isset($foreign[$k])) {
            unset($want[$k]);
            continue;
        }
        if ($k === '_' . $name) {
            if (!isset($want[$k][0]) || $want[$k][0] !== (string) $field['key'] || count($want[$k]) > 1) {
                unset($want[$k]);
            }
            continue;
        }
        if ($k === $name) {
            if (!bulkseme_acf_structural_ok($field, $want[$k])) {
                // Dropping it would delete the field's rows outright: refuse instead.
                return new WP_Error('structure_mismatch', 'the backup holds a row count or layout list for ' . $name . ' that the field no longer accepts (a layout removed since?); nothing was restored', ['status' => 409]);
            }
            continue;
        }
        if (strpos($k, '_' . $name . '_') === 0) {
            $is_ref = true;
        } elseif (strpos($k, $name . '_') === 0) {
            $is_ref = false;
        } else {
            unset($want[$k]);
            continue;
        }
        if (!bulkseme_acf_key_is_own($k, $is_ref, $backup, $subtree)) {
            unset($want[$k]);
        }
    }
    // What is stored now, for the rule on values that are not text.
    $have = [];
    if (!$exact) {
        foreach ($now['own'] as $k) {
            if ($k === $name || $k === '_' . $name || strpos($k, '_' . $name . '_') === 0) {
                continue;
            }
            $ref = bulkseme_acf_ref_text(isset($all['_' . $k][0]) ? $all['_' . $k][0] : null);
            $v   = bulkseme_acf_meta_values($t, $k, $all);
            if ($ref !== null && $v !== null) {
                $sig = $ref . '|' . wp_json_encode($v);
                $have[$sig] = isset($have[$sig]) ? $have[$sig] + 1 : 1;
            }
        }
    }
    foreach ($want as $k => $vals) {
        $k = (string) $k;
        if ($k === $name || $k === '_' . $name || strpos($k, '_' . $name . '_') === 0) {
            continue;
        }
        if (!isset($want['_' . $k][0]) || !is_string($want['_' . $k][0]) || !isset($subtree[$want['_' . $k][0]])) {
            unset($want[$k]); // a value only goes back together with its own reference
            continue;
        }
        $ref  = $want['_' . $k][0];
        $def  = $subtree[$ref];
        $type = isset($def['type']) ? (string) $def['type'] : '';
        if (isset($leaf[$type])) {
            if (!$exact) {
                $sig = $ref . '|' . wp_json_encode($vals);
                if (!empty($have[$sig])) {
                    $have[$sig]--; // the same text is stored now: it goes back exactly as it is
                    continue;
                }
                foreach ($vals as $i => $v) {
                    if (!is_string($v)) {
                        return new WP_Error('revert_needs_unfiltered_html', 'this backup holds a text field of ' . $name . ' whose value is not text; only a user who may post unfiltered HTML can restore it. Nothing was restored.', ['status' => 403]);
                    }
                    $want[$k][$i] = bulkseme_acf_clean($v, $leaf[$type], false);
                }
            }
            continue;
        }
        if (isset($struct[$type])) {
            if (!bulkseme_acf_structural_ok($def, $vals)) {
                return new WP_Error('structure_mismatch', 'the backup holds a row count or layout list under ' . $name . ' that the field no longer accepts; nothing was restored', ['status' => 409]);
            }
            continue;
        }
        if (!$exact) {
            $sig = $ref . '|' . wp_json_encode($vals);
            if (empty($have[$sig])) {
                return new WP_Error('revert_needs_unfiltered_html', 'this backup would bring back a value of ' . $name . ' that is not text (an image, a link, an option) and is no longer stored; only a user who may post unfiltered HTML can restore it. Nothing was restored.', ['status' => 403]);
            }
            $have[$sig]--;
        }
    }
    // A reference whose value key did not survive is not put back on its own.
    foreach (array_keys($want) as $k) {
        $k = (string) $k;
        if (strpos($k, '_' . $name . '_') === 0 && !isset($want[substr($k, 1)])) {
            unset($want[$k]);
        }
    }
    $delete = [];
    foreach ($now['own'] as $k) {
        if (!isset($want[$k])) {
            $delete[] = $k;
        }
    }
    if (bulkseme_acf_case_clash($all, array_merge($delete, array_map('strval', array_keys($want))))) {
        return new WP_Error('meta_collision', 'a stored key of something else differs from one of ' . $name . ' only in case or accents; nothing was restored', ['status' => 409]);
    }
    return ['delete' => $delete, 'write' => $want];
}

/**
 * Carry out a plan from bulkseme_acf_restore_plan. A key that already holds
 * exactly the planned values is left as it is. Returns the number of keys written.
 */
function bulkseme_acf_restore_apply(array $t, array $plan) {
    foreach ($plan['delete'] as $k) {
        if (!delete_metadata($t['meta'], $t['id'], $k)) {
            return false;
        }
    }
    $all     = bulkseme_acf_meta_all($t);
    $written = 0;
    foreach ($plan['write'] as $k => $vals) {
        $k = (string) $k;
        if (isset($all[$k]) && bulkseme_acf_meta_values($t, $k, $all) === $vals) {
            continue;
        }
        if (isset($all[$k]) && !delete_metadata($t['meta'], $t['id'], $k)) {
            return false;
        }
        foreach ($vals as $v) {
            if (!add_metadata($t['meta'], $t['id'], $k, wp_slash($v))) {
                return false;
            }
        }
        $written++;
    }
    return $written;
}

/**
 * Undo the inserts of one request: every key they touched goes back to what the
 * snapshot taken under the same lock holds, or away when the snapshot does not
 * hold it. Nothing else is read or judged, so a row left half moved comes back
 * whole. Returns false when a key could not be written back.
 */
function bulkseme_acf_rollback(array $t, array $snap, array $touched) {
    $want = [];
    foreach ($snap['meta'] as $m) {
        $want[(string) $m['k']] = $m['v'];
    }
    $all = bulkseme_acf_meta_all($t);
    foreach (array_keys($touched) as $k) {
        $k = (string) $k;
        if (!isset($want[$k])) {
            if (isset($all[$k])) {
                delete_metadata($t['meta'], $t['id'], $k);
            }
            continue;
        }
        if (isset($all[$k]) && bulkseme_acf_meta_values($t, $k, $all) === $want[$k]) {
            continue;
        }
        delete_metadata($t['meta'], $t['id'], $k);
        foreach ($want[$k] as $v) {
            add_metadata($t['meta'], $t['id'], $k, wp_slash($v));
        }
    }
    // Only what is stored now counts: every touched key is gone or holds the snapshot again.
    wp_cache_delete($t['id'], $t['meta'] . '_meta');
    $after = bulkseme_acf_meta_all($t);
    foreach (array_keys($touched) as $k) {
        $k = (string) $k;
        if (!isset($want[$k]) ? isset($after[$k]) : (!isset($after[$k]) || bulkseme_acf_meta_values($t, $k, $after) !== $want[$k])) {
            return false;
        }
    }
    return true;
}

// ---- Writes, backups and revert ---------------------------------------------------

function bulkseme_acf_backup_owner(array $t) {
    return ($t['meta'] === 'term' ? 'term:' : 'post:') . $t['id'];
}

/** Store a backup; with $trim false the item's older backups are only trimmed once bulkseme_backup_trim() runs. */
function bulkseme_acf_take_backup(array $t, $kind, $raw, $note, $trim = true) {
    return bulkseme_backup_add(bulkseme_acf_backup_owner($t), $t['meta'] === 'term' ? 0 : $t['id'], $kind, $raw, $note, [], $trim);
}

/** Bump the post's modified date (or clear the term's cache) so caches and sitemaps notice. */
function bulkseme_acf_touch(array $t) {
    if ($t['meta'] === 'term') {
        clean_term_cache($t['id'], $t['taxonomy']);
        return;
    }
    bulkseme_content_touch($t['id']);
}

/** Drop ACF's own in-request copy of loaded values, so a read after a write sees the stored meta. */
function bulkseme_acf_flush_cache() {
    if (function_exists('acf_get_store')) {
        $store = acf_get_store('values');
        if (is_object($store) && method_exists($store, 'reset')) {
            $store->reset();
        }
    }
}

/**
 * One writer at a time per post or term for row inserts and reverts, through
 * WordPress's own lock (WP_Upgrader::create_lock: an options row only one
 * request can create, taken over once it is older than five minutes). Returns
 * the lock name, or null when another write holds it.
 */
function bulkseme_acf_lock(array $t) {
    if (!class_exists('WP_Upgrader')) {
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
    }
    $name = 'bulkseme_acf_' . ($t['meta'] === 'term' ? 'term_' : 'post_') . (int) $t['id'];
    if (!WP_Upgrader::create_lock($name, 5 * MINUTE_IN_SECONDS)) {
        return null;
    }
    // The time the lock was taken: it is only released while it still holds that
    // time, so a request that overran never releases a lock someone else took over.
    return [$name, (string) get_option($name . '.lock')];
}

function bulkseme_acf_unlock($lock) {
    if (!is_array($lock) || !isset($lock[0], $lock[1])) {
        return;
    }
    wp_cache_delete($lock[0] . '.lock', 'options');
    if ((string) get_option($lock[0] . '.lock') === $lock[1]) {
        WP_Upgrader::release_lock($lock[0]);
    }
}

/**
 * Write the dirty leaves. Each one is checked against the `_<meta key>`
 * reference ACF stores next to the value; a leaf whose reference no longer
 * names the field (field renamed or moved since the read) is left alone and
 * reported under reference_mismatch.
 */
function bulkseme_acf_write($target, array &$data) {
    $t        = bulkseme_acf_target($target);
    $written  = [];
    $mismatch = [];
    $all      = bulkseme_acf_meta_all($t);
    foreach ($data['nodes'] as $i => $n) {
        if (empty($n['dirty'])) {
            continue;
        }
        $data['nodes'][$i]['dirty'] = false;
        $ref = bulkseme_acf_meta_one($t, '_' . $n['meta_key'], $all);
        if (!is_string($ref) || $ref !== $n['key'] || bulkseme_acf_case_clash($all, [$n['meta_key']])) {
            $mismatch[] = ['path' => $n['path'], 'meta_key' => $n['meta_key'], 'expected' => $n['key'], 'found' => is_string($ref) ? $ref : ''];
            continue;
        }
        if (!update_metadata($t['meta'], $t['id'], $n['meta_key'], wp_slash($n['text']))) {
            $mismatch[] = ['path' => $n['path'], 'meta_key' => $n['meta_key'], 'expected' => $n['key'], 'found' => $n['key'], 'reason' => 'write_failed'];
            continue;
        }
        $written[] = $n['path'];
    }
    return ['written' => $written, 'reference_mismatch' => $mismatch, 'post_content_synced' => false];
}

/** The top-level field definition a row container sits in. */
function bulkseme_acf_top_field(array $data, $path) {
    $top = bulkseme_acf_path_top($path);
    foreach ($data['fields'] as $f) {
        if ((string) $f['name'] === $top) {
            return $f;
        }
    }
    return null;
}

/**
 * The row insert in progress, if any. A request that dies in the middle (a time
 * limit, a fatal error) never reaches its own rollback or the finally block, so
 * a shutdown function puts back the keys it had noted and releases its lock.
 * $state is ['t' => target, 'snap' => snapshot, 'touched' => &keys, 'lock' => lock];
 * pass false to clear it.
 */
function bulkseme_acf_inflight($state = null) {
    static $current    = null;
    static $registered = false;
    if ($state === false) {
        $current = null;
        return null;
    }
    if (is_array($state)) {
        $current = $state;
        if (!$registered) {
            $registered = true;
            register_shutdown_function('bulkseme_acf_shutdown');
        }
    }
    return $current;
}

function bulkseme_acf_shutdown() {
    $state = bulkseme_acf_inflight();
    if (!is_array($state)) {
        return;
    }
    bulkseme_acf_inflight(false);
    try {
        bulkseme_acf_settle($state, bulkseme_acf_rollback($state['t'], $state['snap'], $state['touched']));
    } finally {
        bulkseme_acf_unlock($state['lock']);
    }
}

/**
 * After a rollback of an insert: a clean one drops the backup taken for it (the
 * undo history stays as it was); one that could not finish keeps it, records the
 * keys the insert touched so a revert can clear them, and trims older backups.
 */
function bulkseme_acf_settle(array $state, $back) {
    $owner = bulkseme_acf_backup_owner($state['t']);
    if (empty($state['backup_ts'])) {
        return;
    }
    if ($back) {
        bulkseme_backup_drop($owner, $state['backup_ts']);
        return;
    }
    $kept = wp_json_encode(['leaves' => $state['leaves'], 'fields' => [$state['snap']], 'touched' => array_map('strval', array_keys($state['touched']))]);
    if ($kept !== false) {
        bulkseme_backup_amend($owner, $state['backup_ts'], $kept);
    }
    bulkseme_backup_trim($owner);
}

function bulkseme_acf_commit($target, $prev_json, array $data, array $results, $changed, $dry_run) {
    $t   = bulkseme_acf_target(isset($data['target']) ? $data['target'] : $target);
    $ops = isset($data['row_ops']) && is_array($data['row_ops']) ? $data['row_ops'] : [];
    if ($ops) {
        foreach ($data['nodes'] as $n) {
            if (!empty($n['dirty'])) {
                return new WP_Error('mixed_update', 'add_row cannot be combined with text edits in one request; send the text edits separately', ['status' => 400]);
            }
        }
    }
    $out = [
        'id' => $t['id'], 'builder' => 'acf', 'dry_run' => (bool) $dry_run,
        'count' => count($results), 'changed' => $changed, 'results' => $results,
    ];
    if ($t['meta'] === 'term') {
        $out['kind']     = 'term';
        $out['taxonomy'] = $t['taxonomy'];
    }
    if ($dry_run || $changed === 0) {
        $out['data_hash'] = md5($prev_json);
        return $out;
    }
    $field = null;
    if ($ops) {
        $field = bulkseme_acf_top_field($data, $ops[0]['el']);
        if (!$field) {
            return new WP_Error('container_not_found', 'the field the rows belong to is gone; nothing was written', ['status' => 409]);
        }
    }
    $lock = bulkseme_acf_lock($t);
    if ($lock === null) {
        return new WP_Error('locked', 'another write to these fields is running; retry in a moment', ['status' => 423]);
    }
    try {
        // Read again under the lock: what is about to be written was planned on this exact state.
        bulkseme_acf_flush_cache();
        $now = bulkseme_acf_load($t);
        if (is_wp_error($now) || !hash_equals(md5($prev_json), md5(bulkseme_acf_encode($now)))) {
            return new WP_Error('hash_conflict', 'content changed since it was read; re-GET /content and retry', ['status' => 409]);
        }
        $side = ['written' => [], 'reference_mismatch' => [], 'post_content_synced' => false];
        if (!$ops) {
            // Text edits: the backup is stored first, then the leaves are written.
            $out['backup_ts'] = bulkseme_acf_take_backup($t, 'acf', $prev_json, 'pre-update', false);
            if (!$out['backup_ts']) {
                return new WP_Error('backup_failed', 'could not store a backup of the current content; nothing was written', ['status' => 500]);
            }
            $side = bulkseme_acf_write($t, $data);
            if ($side['written']) {
                bulkseme_backup_trim(bulkseme_acf_backup_owner($t));
            } else {
                bulkseme_backup_drop(bulkseme_acf_backup_owner($t), $out['backup_ts']);
                unset($out['backup_ts']);
            }
            if ($side['reference_mismatch']) {
                $bad = [];
                foreach ($side['reference_mismatch'] as $m) {
                    $bad[(string) $m['meta_key']] = isset($m['reason']) ? $m['reason'] : 'meta_reference_mismatch';
                }
                foreach ($out['results'] as $i => $r) {
                    if (isset($r['status'], $r['meta_key']) && $r['status'] === 'ok' && isset($bad[(string) $r['meta_key']])) {
                        $out['results'][$i]['status'] = $bad[(string) $r['meta_key']];
                        $out['changed']--;
                    }
                }
            }
            $out = array_merge($out, $side);
        } else {
            // New rows: the whole field is snapshot under the lock and stored as a backup before the
            // first write, without trimming older backups yet. A refused or rolled back request
            // removes that backup again, so the undo history stays as it was; one that succeeds, or
            // whose rollback could not finish, keeps it. If the request dies on the way, the
            // shutdown guard rolls it back from the snapshot.
            $all_now = bulkseme_acf_meta_all($t);
            /**
             * Filter: the most stored keys a field may hold for rows to be added to it (its backup holds them all).
             *
             * @param int $max
             */
            $max_keys = (int) apply_filters('bulkseme_acf_max_field_keys', 20000);
            if (count(bulkseme_acf_field_keys($field, $all_now)['own']) > $max_keys) {
                return new WP_Error('too_large', 'this field holds too many stored values to add rows to it safely; nothing was written', ['status' => 413]);
            }
            $snap = bulkseme_acf_field_snapshot($t, $field, $all_now);
            if (is_wp_error($snap)) {
                return $snap;
            }
            $raw = wp_json_encode(['leaves' => $prev_json, 'fields' => [$snap]]);
            if ($raw === false) {
                return new WP_Error('backup_failed', 'could not store a backup of the current content; nothing was written', ['status' => 500]);
            }
            $out['backup_ts'] = bulkseme_acf_take_backup($t, 'acf_rows', $raw, 'pre-update', false);
            if (!$out['backup_ts']) {
                return new WP_Error('backup_failed', 'could not store a backup of the current content; nothing was written', ['status' => 500]);
            }
            $out = array_merge($out, $side);
            $added   = [];
            $failed  = '';
            /**
             * Filter: the most stored keys one request may move down to make room for new rows.
             *
             * @param int $max
             */
            $budget  = (int) apply_filters('bulkseme_acf_max_moves', 5000);
            $touched = [];
            bulkseme_acf_inflight(['t' => $t, 'snap' => $snap, 'touched' => &$touched, 'lock' => $lock, 'backup_ts' => $out['backup_ts'], 'leaves' => $prev_json]);
            foreach ($ops as $op) {
                $status = ($failed !== '') ? 'not_run' : bulkseme_acf_insert_row($t, $op, $budget, $touched);
                if ($status === 'ok') {
                    $added[] = $op['el'] . '[' . (int) $op['position'] . ']';
                    continue;
                }
                if ($failed === '') {
                    $failed = $status;
                }
                if ($op['ri'] >= 0 && isset($out['results'][$op['ri']])) {
                    $out['results'][$op['ri']]['status'] = $status;
                }
            }
            if ($failed !== '') {
                // All or nothing: the rows this request added go again, back to the state the snapshot holds.
                $state = bulkseme_acf_inflight();
                $back  = bulkseme_acf_rollback($t, $snap, $touched);
                bulkseme_acf_inflight(false);
                bulkseme_acf_settle(is_array($state) ? $state : ['t' => $t, 'snap' => $snap, 'touched' => $touched, 'backup_ts' => $out['backup_ts'], 'leaves' => $prev_json], $back);
                if ($back) {
                    unset($out['backup_ts']);
                }
                foreach ($ops as $op) {
                    if ($op['ri'] >= 0 && isset($out['results'][$op['ri']]['status']) && $out['results'][$op['ri']]['status'] === 'ok') {
                        $out['results'][$op['ri']]['status'] = 'rolled_back';
                    }
                }
                $out['changed']     = 0;
                $added              = [];
                $out['rolled_back'] = $back;
            }
            if ($failed === '') {
                bulkseme_backup_trim(bulkseme_acf_backup_owner($t));
            }
            bulkseme_acf_inflight(false);
            $out['rows_added'] = $added;
        }
        if ($side['written'] || !empty($out['rows_added'])) {
            bulkseme_acf_touch($t);
        }
    } finally {
        // An exception thrown on the way (another plugin's meta hook) unwinds through here: the
        // rows go back before the lock is released, not later and unlocked at shutdown.
        $inflight = bulkseme_acf_inflight();
        try {
            if (is_array($inflight) && $inflight['lock'] === $lock) {
                bulkseme_acf_inflight(false);
                bulkseme_acf_settle($inflight, bulkseme_acf_rollback($inflight['t'], $inflight['snap'], $inflight['touched']));
            }
        } finally {
            bulkseme_acf_unlock($lock);
        }
    }
    bulkseme_acf_flush_cache();
    $fresh = bulkseme_acf_load($t);
    $out['data_hash'] = is_wp_error($fresh) ? md5(bulkseme_acf_encode($data)) : md5(bulkseme_acf_encode($fresh));
    if ($t['meta'] === 'term') {
        do_action('bulkseme_term_content_updated', $t['id'], $t['taxonomy'], get_current_user_id());
    } else {
        do_action('bulkseme_content_updated', $t['id'], $out['results'], get_current_user_id());
    }
    return $out;
}

/**
 * Restore one `acf` or `acf_rows` backup; itself undoable. An `acf` backup is
 * put back leaf by leaf. An `acf_rows` backup puts its fields back whole (rows,
 * count and all, see bulkseme_acf_restore_plan) and the other text leaves it
 * holds leaf by leaf. Every field is planned before anything is written.
 */
function bulkseme_acf_revert($target, array $env, $ts) {
    $t           = bulkseme_acf_target($target);
    $leaves_json = (string) $env['data'];
    $row_fields  = null;
    $left_behind = [];
    if ($env['kind'] === 'acf_rows') {
        $payload = json_decode((string) $env['data'], true);
        if (!is_array($payload) || !isset($payload['leaves'], $payload['fields']) || !is_string($payload['leaves']) || !is_array($payload['fields'])) {
            return new WP_Error('backup_corrupt', 'backup payload is not valid JSON', ['status' => 500]);
        }
        $leaves_json = $payload['leaves'];
        $row_fields  = $payload['fields'];
        $left_behind = (isset($payload['touched']) && is_array($payload['touched'])) ? array_filter($payload['touched'], 'is_string') : [];
    }
    $snap = json_decode($leaves_json, true);
    if (!is_array($snap) || !isset($snap['leaves']) || !is_array($snap['leaves'])) {
        return new WP_Error('backup_corrupt', 'backup payload is not valid JSON', ['status' => 500]);
    }
    $lock = bulkseme_acf_lock($t);
    if ($lock === null) {
        return new WP_Error('locked', 'another write to these fields is running; retry in a moment', ['status' => 423]);
    }
    try {
        bulkseme_acf_flush_cache();
        $current    = bulkseme_acf_load($t);
        $skipped    = [];
        $hidden     = 0;
        $unfiltered = current_user_can('unfiltered_html');
        // Only fields the caller's own field groups show are restored: a backup can
        // have been taken by someone who sees more of them.
        $restore = [];
        if ($row_fields !== null) {
            $fields_now = is_wp_error($current) ? bulkseme_acf_top_fields($t) : $current['fields'];
            foreach ($row_fields as $fs) {
                if (!is_array($fs) || !isset($fs['key'], $fs['name'], $fs['meta']) || !is_string($fs['key']) || !is_array($fs['meta'])) {
                    continue;
                }
                $f = isset($fields_now[$fs['key']]) ? $fields_now[$fs['key']] : null;
                if (!$f || (string) $f['name'] !== (string) $fs['name'] || !in_array($f['type'], bulkseme_acf_structural_types(), true)) {
                    $hidden++;
                    continue;
                }
                $plan = bulkseme_acf_restore_plan($t, $f, $fs, $unfiltered);
                if (is_wp_error($plan)) {
                    return $plan;
                }
                $restore[] = [$f, $plan, $fs];
            }
        }
        // Text is put back by its meta key. If rows were added or removed under a container since
        // the backup, those keys now belong to other rows: refuse rather than write into them. Only
        // text the caller's field groups show counts (the rest is skipped anyway, and never named).
        $whole_names = [];
        foreach ($restore as $pair) {
            $whole_names[(string) $pair[0]['name']] = true;
        }
        $visible = [];
        if (!is_wp_error($current)) {
            foreach ($current['nodes'] as $n) {
                $visible[$n['meta_key'] . '|' . $n['key']] = true;
            }
        }
        $shown = [];
        foreach ($snap['leaves'] as $lpath => $leaf) {
            if (is_array($leaf) && isset($leaf['meta_key'], $leaf['key']) && isset($visible[$leaf['meta_key'] . '|' . $leaf['key']]) && !isset($whole_names[bulkseme_acf_path_top($lpath)])) {
                $shown[] = (string) $lpath;
            }
        }
        $rows_now = [];
        if (!is_wp_error($current)) {
            foreach ($current['containers'] as $cpath => $c) {
                $rows_now[(string) $cpath] = (int) $c['rows'];
            }
        }
        if (!isset($snap['rows'])) {
            // A backup from before 2.1.0 does not say how many rows there were: text inside rows
            // cannot be placed safely.
            foreach ($shown as $lpath) {
                if (strpos($lpath, '[') !== false) {
                    return new WP_Error('rows_unknown', 'this backup is older than row tracking and holds text inside rows (' . $lpath . '), which may have moved since; nothing was restored', ['status' => 409]);
                }
            }
        } elseif (is_array($snap['rows'])) {
            foreach ($snap['rows'] as $cpath => $count) {
                $cpath = (string) $cpath;
                if (isset($rows_now[$cpath]) && $rows_now[$cpath] === (int) $count) {
                    continue;
                }
                foreach ($shown as $lpath) {
                    if (strpos($lpath, $cpath . '[') === 0) {
                        return new WP_Error('rows_changed', 'rows of ' . $cpath . ' were added or removed since this backup, so its text would land in other rows; revert the row change first. Nothing was restored.', ['status' => 409]);
                    }
                }
            }
        }
        $leaves_now = is_wp_error($current) ? (string) wp_json_encode(['leaves' => []]) : bulkseme_acf_encode($current);
        if ($row_fields !== null) {
            $all = bulkseme_acf_meta_all($t);
            $pre = [];
            foreach ($restore as $pair) {
                $s = bulkseme_acf_field_snapshot($t, $pair[0], $all);
                if (is_wp_error($s)) {
                    return $s;
                }
                $pre[] = $s;
            }
            $pre_raw  = wp_json_encode(['leaves' => $leaves_now, 'fields' => $pre]);
            $pre_kind = 'acf_rows';
        } else {
            $pre_raw  = $leaves_now;
            $pre_kind = 'acf';
        }
        $pre_ts = ($pre_raw === false) ? 0 : bulkseme_acf_take_backup($t, $pre_kind, $pre_raw, 'pre-revert', false);
        if (!$pre_ts) {
            return new WP_Error('backup_failed', 'could not store a backup of the current content; nothing was restored', ['status' => 500]);
        }
        $restored = [];
        $whole    = [];
        $stored_now = $left_behind ? bulkseme_acf_meta_all($t) : [];
        $leftovers  = [];
        foreach ($restore as $j => $pair) {
            // Keys an insert wrote and could not take back (listed in the backup it kept): any of this
            // field's that are still stored and that the backup does not hold go now, with the restore.
            $fname = (string) $pair[0]['name'];
            $held  = [];
            foreach ((isset($pair[2]['meta']) && is_array($pair[2]['meta'])) ? $pair[2]['meta'] : [] as $m) {
                if (is_array($m) && isset($m['k'])) {
                    $held[(string) $m['k']] = true;
                }
            }
            foreach ($left_behind as $lk) {
                if (isset($stored_now[$lk]) && !isset($held[$lk]) && !isset($pair[1]['write'][$lk]) && !in_array($lk, $pair[1]['delete'], true) && (strpos($lk, $fname . '_') === 0 || strpos($lk, '_' . $fname . '_') === 0) && !bulkseme_acf_case_clash($stored_now, [$lk])) {
                    $leftovers[] = $lk;
                }
            }
            if (bulkseme_acf_restore_apply($t, $pair[1]) === false) {
                // Put back exactly the keys this revert planned to touch, from the snapshots taken a
                // moment ago, and check what is stored afterwards.
                $undone = true;
                foreach ($restore as $j => $back) {
                    $keys = array_fill_keys(array_merge($back[1]['delete'], array_map('strval', array_keys($back[1]['write']))), true);
                    if (!bulkseme_acf_rollback($t, $pre[$j], $keys)) {
                        $undone = false;
                    }
                }
                if ($undone) {
                    bulkseme_backup_drop(bulkseme_acf_backup_owner($t), $pre_ts);
                } // else: no trim here, it could remove the very backup being reverted
                return new WP_Error('restore_failed', 'the database refused a write while ' . $pair[0]['name'] . ' was being restored; ' . ($undone ? 'the fields are back as they were before this revert' : 'not every field could be put back: revert backup ' . $pre_ts), ['status' => 500]);
            }
            $restored[]                        = (string) $pair[0]['name'];
            $whole[(string) $pair[0]['name']] = true;
        }
        foreach ($leftovers as $lk) {
            delete_metadata($t['meta'], $t['id'], $lk); // keys a failed insert wrote, listed in its own backup
        }
        foreach ($snap['leaves'] as $path => $leaf) {
            if (!is_array($leaf) || empty($leaf['meta_key']) || empty($leaf['key']) || !isset($leaf['text']) || !is_string($leaf['text'])) {
                continue;
            }
            if (isset($whole[bulkseme_acf_path_top($path)])) {
                continue; // already back with its field
            }
            if (!isset($visible[$leaf['meta_key'] . '|' . $leaf['key']])) {
                $hidden++; // counted, not named: the caller's field groups do not show it
                continue;
            }
            $ref = bulkseme_acf_meta_one($t, '_' . $leaf['meta_key']);
            if (!is_string($ref) || $ref !== $leaf['key']) {
                $skipped[] = ['path' => $path, 'reason' => 'meta_reference_mismatch'];
                continue;
            }
            if (bulkseme_acf_case_clash(bulkseme_acf_meta_all($t), [$leaf['meta_key']])) {
                $skipped[] = ['path' => $path, 'reason' => 'meta_collision'];
                continue;
            }
            $text = $leaf['text'];
            if (!$unfiltered) {
                $text = bulkseme_acf_clean($text, isset($leaf['type']) ? $leaf['type'] : 'html', false);
            }
            $cur = bulkseme_acf_meta_one($t, $leaf['meta_key']);
            if (is_string($cur) && $cur === $text) {
                continue;
            }
            if (!update_metadata($t['meta'], $t['id'], $leaf['meta_key'], wp_slash($text))) {
                $skipped[] = ['path' => $path, 'reason' => 'write_failed'];
                continue;
            }
            $restored[] = $path;
        }
        if ($restored || $leftovers) {
            bulkseme_backup_trim(bulkseme_acf_backup_owner($t));
        } else {
            bulkseme_backup_drop(bulkseme_acf_backup_owner($t), $pre_ts);
            $pre_ts = 0;
        }
        if ($hidden) {
            $skipped[] = ['reason' => 'not_in_your_field_groups', 'count' => $hidden];
        }
        if ($restored) {
            bulkseme_acf_touch($t);
        }
    } finally {
        bulkseme_acf_unlock($lock);
    }
    bulkseme_acf_flush_cache();
    if ($t['meta'] === 'term') {
        do_action('bulkseme_term_content_reverted', $t['id'], $t['taxonomy'], $ts, get_current_user_id());
    } else {
        do_action('bulkseme_content_reverted', $t['id'], $ts, get_current_user_id());
    }
    $after = bulkseme_acf_load($t);
    $out   = [
        'id' => $t['id'], 'status' => 'ok', 'reverted_to' => $ts, 'pre_revert_backup_ts' => $pre_ts, 'kind' => $env['kind'],
        'restored' => $restored, 'skipped' => $skipped, 'post_content_synced' => false,
        'data_hash' => is_wp_error($after) ? '' : md5(bulkseme_acf_encode($after)),
    ];
    if ($t['meta'] === 'term') {
        $out['taxonomy'] = $t['taxonomy'];
    }
    return $out;
}
