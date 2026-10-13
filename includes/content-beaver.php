<?php
/**
 * Page content stored by Beaver Builder.
 *
 * Beaver Builder keeps the published layout in the _fl_builder_data post meta
 * and the editor's working copy in _fl_builder_draft: arrays of nodes (rows,
 * column groups, columns, modules) keyed by node id, each module carrying its
 * copy inside `settings`. post_content only holds a plain mirror the builder
 * regenerates on publish, so a post_content write never shows on the page.
 *
 * These handlers list the module copy fields as nodes (el = node id, setting =
 * `text`, `heading`, `items[1].content` and so on) and change only the targeted
 * values, in the published layout and in the draft when it still carries the
 * same value (otherwise the next publish from the editor would undo the
 * change). The layout is then saved through the builder's own
 * FLBuilderModel::update_layout_data(), its per-post CSS and JS cache is
 * dropped, and the post_content mirror is rebuilt with the builder's own
 * renderer.
 *
 * Nodes that render from a saved global row, column or module live in that
 * template's own post and are reported read-only with its id. Writing needs
 * the builder active: its own cleaning and rendering code is what keeps a
 * stored layout valid.
 */

if (!defined('ABSPATH')) exit;

function bulkseme_beaver_active() {
    return class_exists('FLBuilderModel') && class_exists('FLBuilder');
}

/**
 * One of Beaver Builder's user access settings for the current user, by key
 * ("builder_access", or in the premium builder "global_node_editing").
 *
 * Worked out from the builder's saved setting and its registered default
 * rather than by calling FLBuilderUserAccess::current_user_can(). That method
 * keeps a per-request copy of the role list, built the first time anything
 * asks, and on a REST request that can be before the caller is signed in. With
 * a plugin that hides the Administrator role from other users (WooCommerce
 * does) the copy then has no Administrator entry and an administrator is
 * turned away. The rule itself is the builder's own: a role is in when its
 * saved setting says so, or, when nothing is saved for it, when the default
 * names it. With the builder switched off the saved setting is read straight
 * from its option and the builder's stock default applies.
 */
function bulkseme_beaver_user_has_access($key = 'builder_access') {
    $user  = wp_get_current_user();
    $roles = (array) $user->roles;
    if (is_multisite() && is_super_admin() && !in_array('administrator', $roles, true)) {
        $roles[] = 'administrator';
    }
    $default = ['administrator', 'editor'];
    if (class_exists('FLBuilderUserAccess') && class_exists('FLBuilderModel')
        && is_callable(['FLBuilderModel', 'get_admin_settings_option']) && is_callable(['FLBuilderUserAccess', 'get_registered_settings'])) {
        $saved      = FLBuilderModel::get_admin_settings_option('_fl_builder_user_access', true);
        $registered = FLBuilderUserAccess::get_registered_settings();
        if (!is_array($registered) || !isset($registered[$key]) || !is_array($registered[$key])) {
            return true; // this builder has no such setting
        }
        if (array_key_exists('default', $registered[$key])) {
            $default = $registered[$key]['default'];
        }
        $rule = (is_array($saved) && isset($saved[$key]) && is_array($saved[$key])) ? $saved[$key] : null;
        if ($rule === null && is_multisite() && is_callable(['FLBuilderAdminSettings', 'multisite_support']) && FLBuilderAdminSettings::multisite_support()) {
            $network = FLBuilderModel::get_admin_settings_option('_fl_builder_user_access', false);
            if (is_array($network) && isset($network[$key]) && is_array($network[$key])) {
                $rule = $network[$key];
            }
        }
    } else {
        $saved = get_option('_fl_builder_user_access', []);
        $rule  = (is_array($saved) && isset($saved[$key]) && is_array($saved[$key])) ? $saved[$key] : null;
        if ($key !== 'builder_access' && $rule === null) {
            return true;
        }
    }
    foreach ($roles as $role) {
        if ($rule !== null && array_key_exists($role, $rule)) {
            if ($rule[$role]) {
                return true;
            }
            continue;
        }
        if (is_array($default) ? in_array($role, $default, true) : (bool) $default) {
            return true;
        }
    }
    return false;
}

/**
 * Beaver Builder's own answer to "may this user edit this layout?": its
 * Builder Access setting, and for a saved global row, column or module, which
 * renders on every page that uses it, the premium builder's setting for who
 * may edit those.
 */
function bulkseme_beaver_user_can_edit($post) {
    if (!bulkseme_beaver_user_has_access('builder_access')) {
        return false;
    }
    if ($post->post_type === 'fl-builder-template' && get_post_meta($post->ID, '_fl_builder_template_global', true)) {
        return bulkseme_beaver_user_has_access('global_node_editing');
    }
    return true;
}

/**
 * Copy-bearing settings per module slug: setting path => text|html. A `[]`
 * segment stands for every row of a repeater field and expands to the rows on
 * the instance. Unknown modules (PowerPack, Ultimate Addons, custom) fall back
 * to copy-looking string keys on the settings and one level into repeater rows.
 * Filter `bulkseme_beaver_field_map` extends the map.
 */
function bulkseme_bb_field_map() {
    static $map = null;
    if ($map === null) {
        $map = [
            'rich-text'      => ['text' => 'html'],
            'heading'        => ['heading' => 'text'],
            'html'           => ['html' => 'html'],
            'button'         => ['text' => 'text'],
            'button-group'   => ['items[].text' => 'text'],
            'callout'        => ['title' => 'text', 'text' => 'html', 'cta_text' => 'text'],
            'cta'            => ['title' => 'text', 'text' => 'html', 'btn_text' => 'text'],
            'icon'           => ['text' => 'html', 'sr_text' => 'text'],
            'icon-group'     => ['icons[].text' => 'html'],
            'photo'          => ['caption' => 'text'],
            'numbers'        => ['before_number_text' => 'text', 'after_number_text' => 'text', 'number_prefix' => 'text', 'number_suffix' => 'text'],
            'video'          => ['name' => 'text', 'description' => 'text'],
            'accordion'      => ['items[].label' => 'text', 'items[].content' => 'html'],
            'tabs'           => ['items[].label' => 'text', 'items[].content' => 'html'],
            'list'           => ['items[].heading' => 'text', 'items[].content' => 'html'],
            'testimonials'   => ['testimonials[].testimonial' => 'html', 'testimonials[].author' => 'text'],
            'pricing-table'  => ['pricing_columns[].title' => 'text', 'pricing_columns[].price' => 'text', 'pricing_columns[].duration' => 'text', 'pricing_columns[].features' => 'html', 'pricing_columns[].button_text' => 'text'],
            'content-slider' => ['slides[].heading' => 'text', 'slides[].text' => 'html', 'slides[].cta_text' => 'text'],
            'subscribe-form' => ['btn_text' => 'text', 'success_message' => 'html'],
            'contact-form'   => ['btn_text' => 'text', 'success_message' => 'html'],
            'separator' => [], 'box' => [], 'menu' => [], 'sidebar' => [], 'search' => [], 'login-form' => [], 'countdown' => [],
            'post-grid' => [], 'post-carousel' => [], 'post-slider' => [], 'slideshow' => [], 'gallery' => [], 'map' => [], 'audio' => [],
            'widget' => [], 'reusable-block' => [], 'acf-block' => [], 'star-rating' => [],
        ];
        $map = apply_filters('bulkseme_beaver_field_map', $map);
    }
    return $map;
}

/** Keys treated as copy on module types the map does not know. */
function bulkseme_bb_generic_keys() {
    return ['title', 'text', 'heading', 'sub_heading', 'subheading', 'sub_title', 'subtitle', 'tagline', 'description', 'content', 'html', 'caption', 'label', 'button_text', 'btn_text', 'cta_text', 'link_text', 'body', 'before_text', 'after_text', 'success_message', 'testimonial', 'author', 'quote', 'excerpt', 'message', 'price', 'duration', 'number_prefix', 'number_suffix', 'before_number_text', 'after_number_text'];
}

/** Path read over nested stdClass / array settings; null when the path is off. */
function bulkseme_bb_path_get($cur, array $segs) {
    foreach ($segs as $s) {
        if (is_object($cur)) {
            $k = (string) $s;
            if (!property_exists($cur, $k)) {
                return null;
            }
            $cur = $cur->{$k};
        } elseif (is_array($cur)) {
            if (!array_key_exists($s, $cur)) {
                return null;
            }
            $cur = $cur[$s];
        } else {
            return null;
        }
    }
    return $cur;
}

/** Path write; only existing leaves are written. */
function bulkseme_bb_path_set(&$cur, array $segs, $value) {
    $last = array_pop($segs);
    if ($last === null) {
        return false;
    }
    $ref = &$cur;
    foreach ($segs as $s) {
        if (is_object($ref)) {
            $k = (string) $s;
            if (!property_exists($ref, $k)) {
                return false;
            }
            $ref = &$ref->{$k};
        } elseif (is_array($ref)) {
            if (!array_key_exists($s, $ref)) {
                return false;
            }
            $ref = &$ref[$s];
        } else {
            return false;
        }
    }
    if (is_object($ref)) {
        $k = (string) $last;
        if (!property_exists($ref, $k)) {
            return false;
        }
        $ref->{$k} = $value;
        return true;
    }
    if (is_array($ref) && array_key_exists($last, $ref)) {
        $ref[$last] = $value;
        return true;
    }
    return false;
}

/** Concrete editable settings of one module instance: setting path => type. */
function bulkseme_bb_module_fields($settings, $slug) {
    $out = [];
    if (!is_object($settings)) {
        return $out;
    }
    $map = bulkseme_bb_field_map();
    if (isset($map[$slug])) {
        foreach ($map[$slug] as $pattern => $type) {
            if (strpos($pattern, '[]') === false) {
                $segs = bulkseme_so_path_parse($pattern);
                if ($segs !== null && is_string(bulkseme_bb_path_get($settings, $segs))) {
                    $out[$pattern] = $type;
                }
                continue;
            }
            list($list, $field) = explode('[].', $pattern, 2);
            $rows = bulkseme_bb_path_get($settings, [$list]);
            if (is_object($rows)) {
                $rows = (array) $rows;
            }
            if (!is_array($rows)) {
                continue;
            }
            foreach ($rows as $i => $row) {
                if (!is_int($i)) {
                    continue;
                }
                $setting = $list . '[' . $i . '].' . $field;
                $segs    = bulkseme_so_path_parse($setting);
                if ($segs !== null && is_string(bulkseme_bb_path_get($settings, $segs))) {
                    $out[$setting] = $type;
                }
            }
        }
        return $out;
    }
    // Unknown module: copy-looking string keys, plus one level into repeater rows.
    // Markup in a module with no map gets the guarded kind (bulkseme_content_guard_quotes()).
    $keys = bulkseme_bb_generic_keys();
    foreach ((array) $settings as $k => $v) {
        if (!is_string($k) || $k === 'type' || !preg_match('/^[A-Za-z0-9_-]+$/', $k)) {
            continue;
        }
        if (is_string($v)) {
            if ($v !== '' && in_array($k, $keys, true)) {
                $out[$k] = (strpos($v, '<') !== false) ? 'html_guarded' : 'text';
            }
            continue;
        }
        if (is_object($v)) {
            $v = (array) $v;
        }
        if (!is_array($v)) {
            continue;
        }
        foreach ($v as $i => $row) {
            if (!is_int($i) || !(is_object($row) || is_array($row))) {
                continue;
            }
            foreach ((array) $row as $k2 => $v2) {
                if (is_string($k2) && preg_match('/^[A-Za-z0-9_-]+$/', $k2) && is_string($v2) && $v2 !== '' && in_array($k2, $keys, true)) {
                    $out[$k . '[' . $i . '].' . $k2] = (strpos($v2, '<') !== false) ? 'html_guarded' : 'text';
                }
            }
        }
    }
    return $out;
}

/**
 * Template post id when the node is an instance of a saved global node
 * template (its content renders from that post), -1 when the builder is not
 * loaded to resolve it, 0 for an ordinary node.
 */
function bulkseme_bb_template_post($node) {
    if (!is_object($node) || empty($node->template_id)) {
        return 0;
    }
    if (bulkseme_beaver_active()) {
        $pid = FLBuilderModel::get_node_template_post_id($node->template_id);
        return $pid ? (int) $pid : 0;
    }
    return -1;
}

/** Child nodes of one parent in position order ('' = the layout root). */
function bulkseme_bb_children(array $data, $parent_id) {
    $out = [];
    foreach ($data as $id => $n) {
        if (!is_object($n) || !isset($n->type)) {
            continue;
        }
        $p = empty($n->parent) ? '' : (string) $n->parent;
        if ($p === (string) $parent_id) {
            $out[$id] = $n;
        }
    }
    uasort($out, function ($a, $b) {
        return (isset($a->position) ? (int) $a->position : 0) <=> (isset($b->position) ? (int) $b->position : 0);
    });
    return $out;
}

/** Flatten a layout into [nodes, global node count, global map (node id => template post id)]. */
function bulkseme_bb_flatten(array $data) {
    $nodes   = [];
    $globals = 0;
    $gmap    = [];
    $walk    = function ($parent_id, $path, $depth, $inherited) use (&$walk, &$nodes, &$globals, &$gmap, $data) {
        if ($depth > 12) {
            return;
        }
        $i = 0;
        foreach (bulkseme_bb_children($data, $parent_id) as $id => $n) {
            $type  = isset($n->type) ? (string) $n->type : '';
            $label = ($type === 'column-group') ? 'group' : (($type === 'column') ? 'col' : (($type === 'row') ? 'row' : 'module'));
            $p     = ($path === '' ? '' : $path . '.') . $label . $i;
            $tpl   = $inherited ? $inherited : bulkseme_bb_template_post($n);
            if ($tpl) {
                $globals++;
                $gmap[(string) $id] = $tpl;
            }
            if ($type === 'module' && isset($n->settings) && is_object($n->settings)) {
                $slug = isset($n->settings->type) ? (string) $n->settings->type : '';
                foreach (bulkseme_bb_module_fields($n->settings, $slug) as $setting => $ftype) {
                    $val = bulkseme_bb_path_get($n->settings, bulkseme_so_path_parse($setting));
                    if (!is_string($val)) {
                        continue;
                    }
                    // "html_guarded" is how a value is cleaned on the way in; a reader sees plain "html".
                    $ftype = $ftype === 'html_guarded' ? 'html' : $ftype;
                    $node  = [
                        'el' => (string) $id, 'module' => $slug, 'setting' => $setting, 'type' => $ftype,
                        'text' => $val, 'html' => ($ftype === 'html'), 'editable' => !$tpl, 'path' => $p,
                    ];
                    if ($tpl) {
                        $node['reason']           = 'global_node_template';
                        $node['template_post_id'] = $tpl > 0 ? $tpl : null;
                    }
                    $nodes[] = $node;
                }
            }
            $walk((string) $id, $p, $depth + 1, $tpl);
            $i++;
        }
    };
    $walk('', '', 0, 0);
    return [$nodes, $globals, $gmap];
}

/** The module node for an el, or null. Objects are handles, so edits reach the array. */
function bulkseme_bb_locate(array $data, $el) {
    if (!is_string($el) || !preg_match('/^[A-Za-z0-9_-]{1,64}$/', $el) || !isset($data[$el]) || !is_object($data[$el])) {
        return null;
    }
    $n = $data[$el];
    if (!isset($n->type) || $n->type !== 'module' || !isset($n->settings) || !is_object($n->settings)) {
        return null;
    }
    return $n;
}

/** Apply updates[] to a layout (mutating its node objects). Returns [results, changed]. */
function bulkseme_bb_apply_updates(array &$data, array $updates, $unsafe_html) {
    $results = [];
    $changed = 0;
    list(, , $gmap) = bulkseme_bb_flatten($data);
    foreach ($updates as $u) {
        $el      = is_array($u) && isset($u['el']) && is_string($u['el']) ? $u['el'] : '';
        $setting = is_array($u) && isset($u['setting']) ? $u['setting'] : '';
        $res     = ['el' => $el, 'setting' => is_string($setting) ? $setting : ''];
        $segs    = bulkseme_so_path_parse($setting);
        if ($segs === null) {
            $res['status'] = 'invalid_setting';
            $results[] = $res;
            continue;
        }
        if (!is_array($u) || !array_key_exists('value', $u) || !(is_string($u['value']) || is_numeric($u['value']))) {
            $res['status'] = 'invalid_value';
            $results[] = $res;
            continue;
        }
        $n = bulkseme_bb_locate($data, $el);
        if ($n === null) {
            $res['status'] = 'el_not_found';
            $results[] = $res;
            continue;
        }
        if (isset($gmap[$el])) {
            $res['status']           = 'global_node_template';
            $res['template_post_id'] = $gmap[$el] > 0 ? $gmap[$el] : null;
            $results[] = $res;
            continue;
        }
        $slug   = isset($n->settings->type) ? (string) $n->settings->type : '';
        $fields = bulkseme_bb_module_fields($n->settings, $slug);
        if (!isset($fields[$setting])) {
            $res['status'] = 'setting_not_editable';
            $results[] = $res;
            continue;
        }
        $prev  = (string) bulkseme_bb_path_get($n->settings, $segs);
        $clean = bulkseme_so_clean($u['value'], $fields[$setting], $unsafe_html);
        if ($clean === $prev) {
            $res['status'] = 'unchanged';
        } else {
            bulkseme_bb_path_set($n->settings, $segs, $clean);
            $changed++;
            $res['status']   = 'ok';
            $res['previous'] = $prev;
            $res['new']      = $clean;
        }
        $results[] = $res;
    }
    return [$results, $changed];
}

/** True when both layouts carry the same copy nodes (el, setting, text); other node properties are ignored. */
function bulkseme_bb_copy_matches(array $a, array $b) {
    $pick = function (array $layout) {
        $out = [];
        foreach (bulkseme_bb_flatten($layout)[0] as $n) {
            $out[$n['el'] . '|' . $n['setting']] = $n['text'];
        }
        ksort($out);
        return $out;
    };
    return $pick($a) === $pick($b);
}

/**
 * Layout value -> plain arrays that keep objects and arrays apart.
 *
 * A layout mixes stdClass nodes with keyed arrays (typography and border
 * settings). Plain JSON would hand a keyed array back as an object, so every
 * stdClass is wrapped as ['@o' => properties]. An array that happens to use
 * that key is wrapped as ['@a' => array]. Returns null for anything that is
 * not plain data.
 */
function bulkseme_bb_pack($value, $depth = 0) {
    if ($depth > 64) {
        return null;
    }
    if (is_object($value)) {
        if (!($value instanceof stdClass)) {
            return null;
        }
        $props = [];
        foreach (get_object_vars($value) as $k => $v) {
            $props[(string) $k] = bulkseme_bb_pack($v, $depth + 1);
        }
        return ['@o' => $props];
    }
    if (is_array($value)) {
        $out = [];
        foreach ($value as $k => $v) {
            $out[$k] = bulkseme_bb_pack($v, $depth + 1);
        }
        return (array_key_exists('@o', $out) || array_key_exists('@a', $out)) ? ['@a' => $out] : $out;
    }
    return (is_scalar($value) || $value === null) ? $value : null;
}

/** The reverse of bulkseme_bb_pack(). */
function bulkseme_bb_unpack($value, $depth = 0) {
    if (!is_array($value) || $depth > 64) {
        return is_array($value) ? [] : $value;
    }
    if (count($value) === 1 && array_key_exists('@o', $value) && is_array($value['@o'])) {
        $obj = new stdClass();
        foreach ($value['@o'] as $k => $v) {
            $obj->{(string) $k} = bulkseme_bb_unpack($v, $depth + 1);
        }
        return $obj;
    }
    if (count($value) === 1 && array_key_exists('@a', $value) && is_array($value['@a'])) {
        $value = $value['@a'];
    }
    $out = [];
    foreach ($value as $k => $v) {
        $out[$k] = bulkseme_bb_unpack($v, $depth + 1);
    }
    return $out;
}

/**
 * Layout -> JSON string for hashing and backups. '' when it cannot be encoded.
 * JSON_PRESERVE_ZERO_FRACTION keeps 1.0 a float instead of turning it into 1.
 */
function bulkseme_bb_encode(array $data) {
    $json = wp_json_encode(bulkseme_bb_pack($data), JSON_PRESERVE_ZERO_FRACTION);
    return is_string($json) ? $json : '';
}

/** JSON string from bulkseme_bb_encode() -> layout array, or null. */
function bulkseme_bb_decode($json) {
    if (!is_string($json) || $json === '') {
        return null;
    }
    $raw = json_decode($json, true);
    if (!is_array($raw)) {
        return null;
    }
    $layout = bulkseme_bb_unpack($raw);
    return (is_array($layout) && $layout) ? $layout : null;
}

/** Load the published (or draft) layout; WP_Error when the published one is missing, null for a missing draft. */
function bulkseme_bb_load($post_id, $status = 'published') {
    $raw = get_metadata('post', $post_id, ($status === 'draft') ? '_fl_builder_draft' : '_fl_builder_data', true);
    if (!is_array($raw) || !$raw) {
        return ($status === 'draft') ? null : new WP_Error('no_beaver_data', 'post has no _fl_builder_data layout', ['status' => 409]);
    }
    foreach ($raw as $n) {
        if (is_object($n) && !($n instanceof stdClass)) {
            return ($status === 'draft') ? null : new WP_Error('beaver_layout_unreadable', 'the stored layout holds objects this plugin does not read; open and save the page in Beaver Builder once', ['status' => 409]);
        }
    }
    return $raw;
}

/**
 * Carry successful updates into the draft layout when it still holds the value
 * the published layout had. Returns [synced count, conflicts].
 */
function bulkseme_bb_sync_draft(array $draft, array $results) {
    $synced    = 0;
    $conflicts = [];
    foreach ($results as $r) {
        if (!isset($r['status']) || $r['status'] !== 'ok') {
            continue;
        }
        $n    = bulkseme_bb_locate($draft, $r['el']);
        $segs = bulkseme_so_path_parse($r['setting']);
        if ($n === null || $segs === null) {
            $conflicts[] = ['el' => $r['el'], 'setting' => $r['setting'], 'reason' => 'node_missing_in_draft'];
            continue;
        }
        $cur = bulkseme_bb_path_get($n->settings, $segs);
        if ($cur === $r['previous']) {
            bulkseme_bb_path_set($n->settings, $segs, $r['new']);
            $synced++;
        } elseif ($cur === $r['new']) {
            $synced++;
        } else {
            $conflicts[] = ['el' => $r['el'], 'setting' => $r['setting'], 'reason' => 'draft_differs'];
        }
    }
    return [$synced, $conflicts];
}

/**
 * Save a layout through the builder, drop its per-post asset cache and rebuild
 * the post_content mirror with the builder's own renderer. Pass $draft to save
 * the draft layout as well. The builder must be active.
 *
 * update_layout_data() slashes the node objects it is given and keeps that
 * slashed copy in its request cache, so the mirror is rendered from a fresh
 * read of the stored meta, handed to the renderer through the builder's
 * fl_builder_layout_data filter for this one post.
 */
function bulkseme_bb_write($post_id, array $published, $draft = null) {
    $post_id = (int) $post_id;
    $side    = ['post_content_synced' => false, 'asset_cache_cleared' => false, 'draft_written' => false];
    if (!bulkseme_beaver_active()) {
        return $side;
    }
    FLBuilderModel::update_layout_data($published, 'published', $post_id);
    if (is_array($draft)) {
        FLBuilderModel::update_layout_data($draft, 'draft', $post_id);
        $side['draft_written'] = true;
    }
    try {
        FLBuilderModel::delete_all_asset_cache($post_id);
        if (in_array(get_post_type($post_id), ['fl-builder-template', 'fl-theme-layout'], true)) {
            FLBuilderModel::delete_node_template_asset_cache($post_id);
        }
        $side['asset_cache_cleared'] = true;
    } catch (\Throwable $e) {
        // Best effort: the layout is already saved.
        $side['asset_cache_cleared'] = false;
    }

    $fresh = get_metadata('post', $post_id, '_fl_builder_data', true);
    if (is_array($fresh) && $fresh) {
        $fresh = FLBuilderModel::clean_layout_data($fresh);
        if (is_callable(['FLBuilderSettingsCompat', 'filter_layout_data'])) {
            $fresh = FLBuilderSettingsCompat::filter_layout_data($fresh);
        }
        $serve = function ($data, $status = '', $for_post = 0) use ($fresh, $post_id) {
            if ((int) $for_post !== $post_id || $status !== 'published' || !is_array($fresh)) {
                return $data;
            }
            $copy = [];
            foreach ($fresh as $node_id => $node) {
                $copy[$node_id] = is_object($node) ? clone $node : $node;
                if (is_object($copy[$node_id]) && !empty($copy[$node_id]->global) && isset($copy[$node_id]->type, $copy[$node_id]->settings->type) && $copy[$node_id]->type === 'module') {
                    $copy[$node_id]->moduleType = $copy[$node_id]->settings->type;
                }
            }
            return $copy;
        };
        $level = ob_get_level();
        add_filter('fl_builder_layout_data', $serve, 9999, 3);
        try {
            FLBuilderModel::set_post_id($post_id);
            $content = FLBuilder::render_editor_content();
            FLBuilderModel::reset_post_id();
            if (is_string($content) && trim(preg_replace('/<!--.*?-->/s', '', $content)) !== '') {
                wp_update_post(['ID' => $post_id, 'post_content' => wp_slash($content)]);
                $side['post_content_synced'] = true;
            }
        } catch (\Throwable $e) {
            FLBuilderModel::reset_post_id();
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
        }
        remove_filter('fl_builder_layout_data', $serve, 9999);
    }
    if (!$side['post_content_synced']) {
        bulkseme_content_touch($post_id); // bump the modified date so page caches and SEO indexables refresh
    }
    return $side;
}

function bulkseme_bb_commit($post_id, $prev, array $published, array $results, $changed, $dry_run, $note = 'pre-update') {
    $out = [
        'id' => $post_id, 'builder' => 'beaver', 'dry_run' => (bool) $dry_run,
        'count' => count($results), 'changed' => $changed, 'results' => $results,
    ];
    if ($dry_run || $changed === 0) {
        $out['data_hash'] = md5($prev);
        return $out;
    }
    if (!bulkseme_beaver_active()) {
        return new WP_Error('builder_inactive', 'Beaver Builder must be active to save a layout; nothing was written', ['status' => 409]);
    }
    $draft      = bulkseme_bb_load($post_id, 'draft');
    $draft_prev = is_array($draft) ? bulkseme_bb_encode($draft) : null;
    $env        = wp_json_encode(['published' => $prev, 'draft' => $draft_prev]);
    if ($env === false || $prev === '' || $draft_prev === '') {
        return new WP_Error('encode_failed', 'could not snapshot the current layout; nothing was written', ['status' => 500]);
    }
    $out['backup_ts'] = bulkseme_content_take_backup($post_id, 'beaver', $env, $note);
    if (!$out['backup_ts']) {
        return new WP_Error('backup_failed', 'could not store a backup of the current layout; nothing was written', ['status' => 500]);
    }
    $out['draft_exists'] = is_array($draft);
    $write_draft         = null;
    if (is_array($draft)) {
        list($synced, $conflicts) = bulkseme_bb_sync_draft($draft, $results);
        $out['draft_synced']    = $synced;
        $out['draft_conflicts'] = $conflicts;
        if ($synced > 0) {
            $write_draft = $draft;
        }
    }
    $out   = array_merge($out, bulkseme_bb_write($post_id, $published, $write_draft));
    $after = bulkseme_bb_load($post_id);
    $out['data_hash'] = is_wp_error($after) ? '' : md5(bulkseme_bb_encode($after));
    do_action('bulkseme_content_updated', $post_id, $results, get_current_user_id());
    return $out;
}

/** Exact restore of a Beaver Builder backup, for a caller who holds unfiltered_html. */
function bulkseme_bb_revert($post, array $env, $ts) {
    $id = $post->ID;
    if (!bulkseme_beaver_active()) {
        return new WP_Error('builder_inactive', 'Beaver Builder must be active to restore a layout; nothing was written', ['status' => 409]);
    }
    $dec = json_decode((string) $env['data'], true);
    $pub = is_array($dec) && isset($dec['published']) ? bulkseme_bb_decode($dec['published']) : null;
    if ($pub === null) {
        return new WP_Error('backup_corrupt', 'backup payload is not a Beaver Builder layout this version can read', ['status' => 500]);
    }
    $draft     = isset($dec['draft']) ? bulkseme_bb_decode($dec['draft']) : null;
    $cur       = bulkseme_bb_load($id);
    $cur_draft = bulkseme_bb_load($id, 'draft');
    $pre       = wp_json_encode([
        'published' => is_wp_error($cur) ? '' : bulkseme_bb_encode($cur),
        'draft'     => is_array($cur_draft) ? bulkseme_bb_encode($cur_draft) : null,
    ]);
    $pre_ts = bulkseme_content_take_backup($id, 'beaver', $pre === false ? '' : $pre, 'pre-revert');
    if (!$pre_ts) {
        return new WP_Error('backup_failed', 'could not store a backup of the current content; nothing was restored', ['status' => 500]);
    }
    $side  = bulkseme_bb_write($id, $pub, is_array($draft) ? $draft : null);
    $after = bulkseme_bb_load($id);
    do_action('bulkseme_content_reverted', $id, $ts, get_current_user_id());
    return array_merge([
        'id' => $id, 'status' => 'ok', 'reverted_to' => $ts,
        'pre_revert_backup_ts' => $pre_ts, 'kind' => 'beaver',
        'data_hash' => is_wp_error($after) ? '' : md5(bulkseme_bb_encode($after)),
    ], $side);
}
