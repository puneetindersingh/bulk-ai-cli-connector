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
 *
 * Since 2.7.0 a few layout settings are listed next to the copy, each with its
 * own validator and none of them through kses: link URLs (kind "url",
 * esc_url_raw, relative paths allowed, script schemes refused), heading tags
 * (kind "tag", only the options the module offers) and link target / nofollow
 * (kind "choice"). inserts[] copies a module or a row already on the page and
 * places the copy next to another node, with fresh node ids
 * (bulkseme_bb_apply_inserts()).
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
 * the instance (`items[].text`), or, at the end of the path, to every entry of
 * a list of plain strings (`list_items[]`). Unknown modules (Ultimate Addons,
 * custom, PowerPack modules not listed here) fall back to copy-looking string
 * keys on the settings and one level into repeater rows. The PowerPack entries
 * are the modules of PowerPack Lite (powerpack-addon-for-beaver-builder),
 * whose field names were read from its code. Filter `bulkseme_beaver_field_map`
 * extends the map.
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
            // PowerPack Lite. pp-iconlist prints each list entry as it is stored, so entries are HTML (kses).
            'pp-iconlist'    => ['list_items[]' => 'html'],
            'pp-infobox'     => ['title_prefix' => 'text', 'title' => 'text', 'description' => 'html', 'pp_infobox_read_more_text' => 'text'],
            'pp-heading'     => ['prefix_text' => 'text', 'heading_title' => 'text', 'heading_title2' => 'text', 'heading_sub_title' => 'html'],
            'pp-dual-button' => ['button_1_title' => 'text', 'button_2_title' => 'text', 'title_attr_1' => 'text', 'title_attr_2' => 'text'],
        ];
        $map = apply_filters('bulkseme_beaver_field_map', $map);
    }
    return $map;
}

/** Heading tags a Beaver Builder heading-level select offers (FLBuilderUtils::esc_tags keeps the same list). */
function bulkseme_bb_tag_options() {
    return ['h1', 'h2', 'h3', 'h4', 'h5', 'h6'];
}

/**
 * Layout settings per module slug (2.7.0): setting path => spec, where spec is
 * 'url' (a link field), ['tag' => options, 'default' => tag] (a heading-level
 * select) or ['choice' => options] (a link's target or nofollow switch). Path
 * patterns work as in bulkseme_bb_field_map(). Only settings already stored on
 * the module are listed and written; nothing is added. Every name was read from
 * the module code of Beaver Builder (lite 2.10) and PowerPack Lite 1.3: a link
 * field that shows a target and nofollow switch stores them as <name>_target
 * ("_self" or "_blank") and <name>_nofollow ("yes" or "no"). Filter
 * `bulkseme_beaver_layout_map` extends the map.
 */
function bulkseme_bb_layout_map() {
    static $map = null;
    if ($map === null) {
        $h      = bulkseme_bb_tag_options();
        $target = ['choice' => ['_self', '_blank']];
        $nofol  = ['choice' => ['yes', 'no']];
        $link   = function ($name, $nofollow = true) use ($target, $nofol) {
            $out = [$name => 'url', $name . '_target' => $target];
            if ($nofollow) {
                $out[$name . '_nofollow'] = $nofol;
            }
            return $out;
        };
        $map = [
            'heading'        => ['tag' => ['tag' => $h, 'default' => 'h2']] + $link('link'),
            'button'         => $link('link'),
            'button-group'   => ['items[].link' => 'url', 'items[].link_target' => $target, 'items[].link_nofollow' => $nofol],
            'callout'        => ['title_tag' => ['tag' => $h, 'default' => 'h3']] + $link('link'),
            'cta'            => ['title_tag' => ['tag' => $h, 'default' => 'h3']] + $link('btn_link'),
            'icon'           => $link('link'),
            'photo'          => $link('link_url'),
            'pp-heading'     => ['heading_tag' => ['tag' => array_merge($h, ['div', 'p', 'span']), 'default' => 'h2']] + $link('heading_link'),
            'pp-infobox'     => $link('link'),
            'pp-dual-button' => $link('button_1_link') + $link('button_2_link'),
        ];
        $map = apply_filters('bulkseme_beaver_layout_map', $map);
        $map = is_array($map) ? $map : [];
    }
    return $map;
}

/**
 * Concrete setting paths a map pattern stands for on one module instance,
 * limited to those whose stored value is a string.
 */
function bulkseme_bb_expand_pattern($settings, $pattern) {
    if (!is_string($pattern) || $pattern === '') {
        return [];
    }
    if (strpos($pattern, '[]') === false) {
        $segs = bulkseme_so_path_parse($pattern);
        return ($segs !== null && is_string(bulkseme_bb_path_get($settings, $segs))) ? [$pattern] : [];
    }
    list($list, $rest) = explode('[]', $pattern, 2);
    if ($rest !== '' && $rest[0] !== '.') {
        return [];
    }
    $lsegs = bulkseme_so_path_parse($list);
    if ($lsegs === null) {
        return [];
    }
    $rows = bulkseme_bb_path_get($settings, $lsegs);
    if (is_object($rows)) {
        $rows = (array) $rows;
    }
    if (!is_array($rows)) {
        return [];
    }
    $out = [];
    foreach (array_keys($rows) as $i) {
        if (!is_int($i)) {
            continue;
        }
        $setting = $list . '[' . $i . ']' . $rest;
        $segs    = bulkseme_so_path_parse($setting);
        if ($segs !== null && is_string(bulkseme_bb_path_get($settings, $segs))) {
            $out[] = $setting;
        }
    }
    return $out;
}

/**
 * True when a node's container_element is not a bare tag name the builder's
 * own save would keep (FLBuilderUtils::esc_tags, allowed_tags()). Rows,
 * columns and modules print it raw as their element name; an imported page
 * may hold anything. Settings stored as an array are read the way the builder
 * renders them.
 */
function bulkseme_bb_bad_container($settings) {
    if (is_array($settings)) {
        $settings = (object) $settings;
    }
    if (!is_object($settings) || !isset($settings->container_element)) {
        return false;
    }
    $v  = $settings->container_element;
    $ok = ['h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'div', 'li', 'ul', 'ol', 'a', 'p', 'article', 'section', 'aside', 'main', 'span', 'header', 'footer'];
    return !(is_string($v) && ($v === '' || in_array($v, $ok, true)));
}

/**
 * True when the node or any of its ancestors has a bad container_element.
 * Fails closed: a parent that is missing from the layout, a cycle, or a chain
 * deeper than 40 levels counts as bad.
 */
function bulkseme_bb_bad_container_chain(array $data, $el) {
    $cur  = (string) $el;
    $seen = [];
    for ($i = 0; $i <= 40; $i++) {
        if ($cur === '') {
            return false;
        }
        if (isset($seen[$cur]) || !isset($data[$cur]) || !is_object($data[$cur])) {
            return true;
        }
        $seen[$cur] = true;
        if (isset($data[$cur]->settings) && bulkseme_bb_bad_container($data[$cur]->settings)) {
            return true;
        }
        $cur = empty($data[$cur]->parent) ? '' : (string) $data[$cur]->parent;
    }
    return true;
}

/**
 * True when a module holds a tag setting with a value outside the tags modules
 * offer: a tag-kind setting of the layout map outside its own options, or any
 * setting named `tag` or `*_tag` (top level, or one level into repeater rows,
 * on any module, mapped or not) outside h1-h6, div, p, span. Several modules
 * print such a setting as the element name around copy (cta and callout
 * title_tag, PowerPack title_tag, title_prefix_tag, prefix_tag), and the
 * builder's own save would have cleaned it, which an imported page skipped.
 */
function bulkseme_bb_bad_tag($settings, $slug) {
    foreach (bulkseme_bb_layout_fields($settings, $slug) as $setting => $spec) {
        if (is_array($spec) && isset($spec['tag'])) {
            $v = bulkseme_bb_path_get($settings, bulkseme_so_path_parse($setting));
            if (is_string($v) && $v !== '' && !in_array(strtolower(trim($v)), $spec['tag'], true)) {
                return true;
            }
        }
    }
    if (!is_object($settings)) {
        return false;
    }
    $ok    = array_merge(bulkseme_bb_tag_options(), ['div', 'p', 'span']);
    $check = function ($row) use ($ok) {
        foreach ((array) $row as $k => $v) {
            // A loop module's taxonomy filter (tax_post_post_tag) holds term ids, which print as text, not as an element.
            if (is_string($k) && preg_match('/(?:^|_)tag$/', $k) && is_string($v) && $v !== '' && !preg_match('/^[\d,\s]*$/', $v) && !in_array(strtolower(trim($v)), $ok, true)) {
                return true;
            }
        }
        return false;
    };
    if ($check(get_object_vars($settings))) {
        return true;
    }
    foreach (get_object_vars($settings) as $v) {
        if (is_object($v)) {
            $v = get_object_vars($v);
        }
        if (!is_array($v)) {
            continue;
        }
        foreach ($v as $row) {
            if ((is_object($row) || is_array($row)) && $check(is_object($row) ? get_object_vars($row) : $row)) {
                return true;
            }
        }
    }
    return false;
}

/**
 * URL for a Beaver Builder link field: the Elementor link validator
 * (esc_url_raw, relative paths kept, empty clears), and on top of it a refusal
 * of anything that reads as a script or data URL once entities are decoded
 * (esc_url_raw turns "javascript&#58;x" into a harmless http:// address, but such
 * input is never what a caller meant). Null when refused.
 */
function bulkseme_bb_sanitize_url($value) {
    $value = is_scalar($value) ? (string) $value : '';
    if (strlen($value) > 2048) {
        return null;
    }
    $d    = html_entity_decode(html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $flat = preg_replace('/[\x00-\x20\x7f]+/', '', $d);
    if (!is_string($flat) || preg_match('/(?:java|vb)script:|data:/i', $flat) !== 0) {
        return null;
    }
    return bulkseme_elementor_sanitize_url($value);
}

/** Concrete layout settings of one module instance: setting path => spec from bulkseme_bb_layout_map(). */
function bulkseme_bb_layout_fields($settings, $slug) {
    $out = [];
    $map = bulkseme_bb_layout_map();
    if (!is_object($settings) || !isset($map[$slug]) || !is_array($map[$slug])) {
        return $out;
    }
    foreach ($map[$slug] as $pattern => $spec) {
        $ok = $spec === 'url'
            || (is_array($spec) && isset($spec['tag']) && is_array($spec['tag']))
            || (is_array($spec) && isset($spec['choice']) && is_array($spec['choice']));
        if (!$ok) {
            continue;
        }
        foreach (bulkseme_bb_expand_pattern($settings, $pattern) as $setting) {
            $out[$setting] = $spec;
        }
    }
    return $out;
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
        foreach ((array) $map[$slug] as $pattern => $type) {
            if (!in_array($type, ['text', 'html'], true)) {
                continue;
            }
            foreach (bulkseme_bb_expand_pattern($settings, $pattern) as $setting) {
                $out[$setting] = $type;
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

/**
 * Children of every parent in one pass: parent id ('' = the layout root) =>
 * [node id => node] in position order. Ties keep the stored order, the same on
 * PHP 7.4 (whose sort is not stable) as on PHP 8. Walks that visit many parents
 * build this once instead of scanning the layout per parent (2.7.0).
 */
function bulkseme_bb_index(array $data) {
    $groups = [];
    $seq    = 0;
    foreach ($data as $id => $n) {
        if (!is_object($n) || !isset($n->type)) {
            continue;
        }
        $p = empty($n->parent) ? '' : (string) $n->parent;
        $groups[$p][] = [isset($n->position) && is_scalar($n->position) ? (int) $n->position : 0, $seq++, (string) $id, $n];
    }
    $index = [];
    foreach ($groups as $p => $rows) {
        usort($rows, function ($a, $b) {
            return ($a[0] <=> $b[0]) ?: ($a[1] <=> $b[1]);
        });
        foreach ($rows as $r) {
            $index[$p][$r[2]] = $r[3];
        }
    }
    return $index;
}

/** Child nodes of one parent in position order ('' = the layout root). Pass an index from bulkseme_bb_index() to reuse it. */
function bulkseme_bb_children(array $data, $parent_id, $index = null) {
    if (!is_array($index)) {
        $index = bulkseme_bb_index($data);
    }
    $p = (string) $parent_id;
    return isset($index[$p]) ? $index[$p] : [];
}

/** Flatten a layout into [nodes, global node count, global map (node id => template post id)]. */
function bulkseme_bb_flatten(array $data) {
    $nodes   = [];
    $globals = 0;
    $gmap    = [];
    $index   = bulkseme_bb_index($data);
    $walk    = function ($parent_id, $path, $depth, $inherited) use (&$walk, &$nodes, &$globals, &$gmap, $data, $index) {
        if ($depth > 12) {
            return;
        }
        $i = 0;
        foreach (bulkseme_bb_children($data, $parent_id, $index) as $id => $n) {
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
                // Layout settings (2.7.0): link URLs, heading tags, link target and nofollow.
                foreach (bulkseme_bb_layout_fields($n->settings, $slug) as $setting => $spec) {
                    $val  = bulkseme_bb_path_get($n->settings, bulkseme_so_path_parse($setting));
                    $kind = $spec === 'url' ? 'url' : (isset($spec['tag']) ? 'tag' : 'choice');
                    $node = [
                        'el' => (string) $id, 'module' => $slug, 'setting' => $setting, 'type' => $kind, 'kind' => $kind,
                        'text' => (string) $val, 'html' => false, 'editable' => !$tpl, 'path' => $p,
                    ];
                    if ($kind !== 'url') {
                        $node['options'] = array_values($kind === 'tag' ? $spec['tag'] : $spec['choice']);
                    }
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

/**
 * Every row, column group, column and module in page order with its parent,
 * for inserts[] (ref and copy_from.el). Nodes of a saved global row or module
 * carry global: true and the template post id.
 */
function bulkseme_bb_outline(array $data) {
    list(, , $gmap) = bulkseme_bb_flatten($data);
    $out   = [];
    $index = bulkseme_bb_index($data);
    $walk  = function ($parent, $depth) use (&$walk, &$out, $data, $gmap, $index) {
        if ($depth > 12) {
            return;
        }
        foreach (bulkseme_bb_children($data, $parent, $index) as $id => $n) {
            $id  = (string) $id;
            $row = ['el' => $id, 'type' => (string) $n->type, 'parent' => $parent === '' ? null : $parent];
            if ($n->type === 'module' && isset($n->settings) && is_object($n->settings) && isset($n->settings->type) && is_string($n->settings->type)) {
                $row['module'] = $n->settings->type;
            }
            if (isset($n->settings) && is_object($n->settings) && isset($n->settings->node_label) && is_string($n->settings->node_label) && $n->settings->node_label !== '') {
                $row['label'] = $n->settings->node_label;
            }
            if (isset($gmap[$id])) {
                $row['global']           = true;
                $row['template_post_id'] = $gmap[$id] > 0 ? $gmap[$id] : null;
            }
            $out[] = $row;
            $walk($id, $depth + 1);
        }
    };
    $walk('', 0);
    return $out;
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
        $layout = isset($fields[$setting]) ? [] : bulkseme_bb_layout_fields($n->settings, $slug);
        if (!isset($fields[$setting]) && !isset($layout[$setting])) {
            $res['status'] = 'setting_not_editable';
            $results[] = $res;
            continue;
        }
        $prev = (string) bulkseme_bb_path_get($n->settings, $segs);
        if (isset($layout[$setting])) {
            $spec = $layout[$setting];
            if ($spec === 'url') {
                // esc_url_raw, relative paths kept, script and data URLs refused (bulkseme_bb_sanitize_url).
                $clean = bulkseme_bb_sanitize_url($u['value']);
                if ($clean === null) {
                    $res['status'] = 'invalid_value';
                    $res['errors'] = ['url_rejected'];
                    $results[] = $res;
                    continue;
                }
            } else {
                $opts  = isset($spec['tag']) ? $spec['tag'] : $spec['choice'];
                $clean = strtolower(trim((string) $u['value']));
                if (!in_array($clean, $opts, true)) {
                    $res['status']  = 'invalid_value';
                    $res['errors']  = [isset($spec['tag']) ? 'tag_not_allowed' : 'option_not_allowed'];
                    $res['allowed'] = array_values($opts);
                    $results[] = $res;
                    continue;
                }
            }
        } else {
            if ((bulkseme_bb_bad_tag($n->settings, $slug) || bulkseme_bb_bad_container_chain($data, $el)) && !bulkseme_content_raw_html_allowed($unsafe_html)) {
                // A tag outside its list (an old or imported page) may be printed raw around this text.
                $res['status'] = 'setting_not_editable';
                $res['errors'] = ['module_tag_outside_allowed_values'];
                $res['hint']   = 'a tag or container element on this module or around it is not one the builder offers; fix it in Beaver Builder first';
                $results[] = $res;
                continue;
            }
            $clean = bulkseme_so_clean($u['value'], $fields[$setting], $unsafe_html);
            if ($slug === 'html' && !bulkseme_content_raw_html_allowed($unsafe_html) && $clean !== wp_kses_normalize_entities(bulkseme_content_utf8((string) $u['value']))) {
                // An HTML module is there for markup the content filter does not allow (style, script,
                // iframes): storing the filtered remainder would break the page and show code as text.
                $res['status'] = 'invalid_value';
                $res['errors'] = ['markup_would_be_stripped'];
                $res['hint']   = 'this HTML module value needs markup the content filter removes; edit it in Beaver Builder, or see unsafe_html';
                $results[] = $res;
                continue;
            }
        }
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

// ---- inserts[]: copy a module or a row already on the page (2.7.0) -------------
//
// { ref, position, copy_from: { el }, set: [ { el?, setting, value } ] }
//   copy_from.el  a module or a row of this same layout; its whole subtree is copied
//   ref           module copy: a module (position before|after) or a column (first|last);
//                 row copy: a row (before|after)
//   set           text and layout settings of the copy, addressed by the SOURCE node id
//                 (default: the copied node itself) and written through the same
//                 validators as updates[]. If one of them fails, that insert is dropped.
// Every copied node gets a fresh id. Global (saved) rows and modules are never copied
// and nothing is placed inside one. Unless raw HTML is allowed for the request
// (bulkseme_content_raw_html_allowed), a copy is refused when any part of it holds
// markup, a script URL or code outside the fields this plugin cleans, and HTML and
// widget modules are refused outright.

/** Deep copy of a layout or node (objects included), through the backup encoding. */
function bulkseme_bb_clone($value) {
    return bulkseme_bb_unpack(bulkseme_bb_pack($value));
}

/** Ids of a node and everything under it, parents first. Null past 12 levels, $max nodes, or on a cycle. */
function bulkseme_bb_subtree_ids(array $data, $root_id, $max = 500) {
    $root_id = (string) $root_id;
    if ($root_id === '' || !isset($data[$root_id]) || !is_object($data[$root_id])) {
        return null;
    }
    $out   = [$root_id => true];
    $level = [$root_id];
    $index = bulkseme_bb_index($data);
    for ($depth = 0; $level; $depth++) {
        if ($depth > 12) {
            return null;
        }
        $next = [];
        foreach ($level as $pid) {
            foreach (array_keys(bulkseme_bb_children($data, $pid, $index)) as $cid) {
                $cid = (string) $cid;
                if (isset($out[$cid])) {
                    return null;
                }
                $out[$cid] = true;
                $next[]    = $cid;
                if (count($out) > $max) {
                    return null;
                }
            }
        }
        $level = $next;
    }
    return array_map('strval', array_keys($out)); // all-digit ids come back from array_keys() as int
}

/**
 * Names of settings that hold code: every field of type "code" in a registered
 * module or settings form (a button's click script, a video's embed code, row
 * code), plus the per-node CSS and JavaScript Beaver Builder keeps on any node.
 */
function bulkseme_bb_code_setting_names() {
    static $names = null;
    if ($names !== null) {
        return $names;
    }
    $names = ['bb_css_code' => true, 'bb_js_code' => true];
    if (!bulkseme_beaver_active() || !is_callable(['FLBuilderModel', 'get_settings_form_fields'])) {
        return $names;
    }
    $forms = [];
    foreach ((array) FLBuilderModel::$modules as $m) {
        if (is_object($m) && isset($m->form) && is_array($m->form)) {
            $forms[] = $m->form;
        }
    }
    foreach ((array) FLBuilderModel::$settings_forms as $f) {
        if (is_array($f) && isset($f['tabs']) && is_array($f['tabs'])) {
            $forms[] = $f['tabs'];
        } elseif (is_array($f) && isset($f['sections']) && is_array($f['sections'])) {
            $forms[] = [['sections' => $f['sections']]];
        }
    }
    foreach ($forms as $form) {
        try {
            $fields = FLBuilderModel::get_settings_form_fields($form);
        } catch (\Throwable $e) {
            continue;
        }
        foreach ((array) $fields as $name => $field) {
            if (is_string($name) && is_array($field) && isset($field['type']) && in_array($field['type'], ['code', 'raw'], true)) {
                $names[$name] = true;
            }
        }
    }
    return $names;
}

/** True when a code setting (by name, at any depth, e.g. inside button-group items) holds a value. */
function bulkseme_bb_holds_code($v, array $code, $depth = 0) {
    if ($depth > 64) {
        return true;
    }
    if (is_object($v)) {
        $v = get_object_vars($v);
    }
    if (!is_array($v)) {
        return false;
    }
    foreach ($v as $k => $x) {
        $empty = $x === '' || $x === null || $x === [] || (is_object($x) && !get_object_vars($x));
        if (is_string($k) && isset($code[$k]) && !$empty) {
            return true;
        }
        if ((is_array($x) || is_object($x)) && bulkseme_bb_holds_code($x, $code, $depth + 1)) {
            return true;
        }
    }
    return false;
}

/**
 * True when a value copied as it is (at any depth) can hold no markup and no
 * script: no angle brackets or backticks, no script-scheme URL, no event
 * handler or style attribute, also after decoding entities twice. Quotes are
 * allowed (attachment alt text, titles): the copy repeats a value already
 * stored on this page, printed by the same module code.
 */
function bulkseme_bb_value_is_inert($v, $depth = 0) {
    if ($depth > 64) {
        return false;
    }
    if (is_object($v)) {
        $v = get_object_vars($v);
    }
    if (is_array($v)) {
        foreach ($v as $k => $x) {
            if ((is_string($k) && !bulkseme_bb_value_is_inert($k, $depth + 1)) || !bulkseme_bb_value_is_inert($x, $depth + 1)) {
                return false;
            }
        }
        return true;
    }
    if (!is_string($v)) {
        return true;
    }
    // Long values are refused outright, and every pattern test fails closed: a regex error
    // (backtrack or JIT limit) returns false, which must never read as "no match".
    if (strlen($v) > 65536) {
        return false;
    }
    $once = html_entity_decode($v, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $d    = html_entity_decode($once, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    foreach ([$v, $once, $d] as $stage) {
        if (strpbrk($stage, '<>`') !== false || preg_match('/&#x?[0-9a-f]+(?![0-9a-f;])/i', $stage) !== 0) {
            return false;
        }
    }
    $flat = preg_replace('/[\x00-\x20\x7f]+/', '', $d);
    if (!is_string($flat) || preg_match('/(?:java|vb)script:|data:text\/html/i', $flat) !== 0) {
        return false;
    }
    return preg_match('/(?:^|[\s"\'\/;])(?:on[a-z]+|style|formaction|srcdoc)\s*=/i', $d) === 0;
}

/**
 * Clean one copied node for a caller without raw HTML rights. Mapped copy
 * fields go through the same cleaning as updates[], link URLs through the URL
 * validator; everything else must be inert and no code setting may hold a
 * value. Returns '' when the node may be copied (it is cleaned in place) or
 * the reason it may not. $sanitized is set when anything was changed.
 */
function bulkseme_bb_clean_copy_node($node, &$sanitized) {
    if (!is_object($node) || !isset($node->type) || !in_array($node->type, ['row', 'column-group', 'column', 'module'], true)) {
        return 'node_type_not_copyable';
    }
    $settings = isset($node->settings) && is_object($node->settings) ? $node->settings : new stdClass();
    $slug     = '';
    if ($node->type === 'module') {
        $slug = isset($settings->type) && is_string($settings->type) ? $settings->type : '';
        if ($slug === '' || in_array($slug, ['html', 'widget'], true)) {
            return 'module_type_not_copyable_without_unfiltered_html';
        }
        if (!isset(FLBuilderModel::$modules[$slug])) {
            return 'module_not_registered';
        }
    }
    if (bulkseme_bb_holds_code($settings, bulkseme_bb_code_setting_names())) {
        return 'code_setting_requires_unfiltered_html';
    }
    if ($node->type === 'module' && bulkseme_bb_bad_tag($settings, $slug)) {
        return 'setting_outside_its_allowed_values';
    }
    if (bulkseme_bb_bad_container($settings)) {
        return 'setting_outside_its_allowed_values';
    }
    // Node properties other than these are left out of the copy.
    foreach (array_keys(get_object_vars($node)) as $prop) {
        if (!in_array($prop, ['node', 'type', 'parent', 'position', 'settings', 'version'], true)) {
            unset($node->{$prop});
            $sanitized = true;
        }
    }
    if (isset($node->version) && !is_int($node->version)) {
        $node->version = (int) $node->version;
        $sanitized     = true;
    }
    $handled = [];
    if ($node->type === 'module') {
        foreach (bulkseme_bb_module_fields($settings, $slug) as $setting => $type) {
            $segs = bulkseme_so_path_parse($setting);
            $old  = $segs !== null ? bulkseme_bb_path_get($settings, $segs) : null;
            if (!is_string($old)) {
                continue;
            }
            $new = bulkseme_so_clean($old, $type, false);
            if ($new !== $old) {
                bulkseme_bb_path_set($settings, $segs, $new);
                $sanitized = true;
            }
            $handled[$setting] = true;
        }
        foreach (bulkseme_bb_layout_fields($settings, $slug) as $setting => $spec) {
            $segs = bulkseme_so_path_parse($setting);
            $old  = $segs !== null ? bulkseme_bb_path_get($settings, $segs) : null;
            if (!is_string($old)) {
                continue;
            }
            if ($spec !== 'url') {
                // Some modules print a tag setting raw (cta, callout title_tag): only listed values are copied.
                $opts = isset($spec['tag']) ? $spec['tag'] : $spec['choice'];
                if ($old !== '' && !in_array(strtolower(trim($old)), $opts, true)) {
                    return 'setting_outside_its_allowed_values';
                }
                $handled[$setting] = true;
                continue;
            }
            $new = bulkseme_bb_sanitize_url($old);
            if ($new === null) {
                return 'url_rejected_in_copy';
            }
            if ($new !== $old) {
                bulkseme_bb_path_set($settings, $segs, $new);
                $sanitized = true;
            }
            $handled[$setting] = true;
        }
    }
    // Every other value must be inert. Cleaned fields are blanked in a scratch copy for this check.
    $scan = bulkseme_bb_clone($settings);
    foreach (array_keys($handled) as $setting) {
        bulkseme_bb_path_set($scan, bulkseme_so_path_parse($setting), '');
    }
    if (!bulkseme_bb_value_is_inert($scan)) {
        return 'markup_in_unmapped_setting_requires_unfiltered_html';
    }
    $node->settings = $settings;
    return '';
}

/** A fresh node id in Beaver Builder's own form (12 of [a-z0-9], not all digits), not in $taken. */
function bulkseme_bb_new_id(array &$taken) {
    $chars = '0123456789abcdefghijklmnopqrstuvwxyz';
    for ($try = 0; $try < 100; $try++) {
        $id = '';
        for ($i = 0; $i < 12; $i++) {
            $id .= $chars[wp_rand(0, 35)];
        }
        if (!is_numeric($id) && !isset($taken[$id])) {
            $taken[$id] = true;
            return $id;
        }
    }
    return '';
}

/**
 * Where a copy whose top node has type $root_type goes for ($ref, $position):
 * [parent id, ref id or '' for first/last], or an error code.
 */
function bulkseme_bb_target(array $data, $ref, $position, $root_type) {
    if (!isset($data[$ref]) || !is_object($data[$ref]) || !isset($data[$ref]->type)) {
        return 'ref_not_found';
    }
    $r      = $data[$ref];
    $parent = empty($r->parent) ? '' : (string) $r->parent;
    if ($root_type === 'module') {
        if (in_array($position, ['before', 'after'], true)) {
            return $r->type === 'module' ? [$parent, (string) $ref] : 'ref_must_be_a_module_for_before_after';
        }
        return $r->type === 'column' ? [(string) $ref, ''] : 'ref_must_be_a_column_for_first_last';
    }
    if ($root_type === 'row') {
        if (!in_array($position, ['before', 'after'], true)) {
            return 'rows_go_before_or_after_a_row';
        }
        return ($r->type === 'row' && $parent === '') ? ['', (string) $ref] : 'ref_must_be_a_top_level_row';
    }
    return 'copy_must_be_a_module_or_a_row';
}

/**
 * Put already re-keyed nodes into a layout: the top node goes under $parent at
 * the place ($ref, $position) names, its siblings are renumbered 0..n.
 */
function bulkseme_bb_place(array &$data, $parent, $ref, $position, $root_id, array $nodes) {
    $order = array_keys(bulkseme_bb_children($data, $parent));
    $order = array_map('strval', $order);
    if ($position === 'first') {
        array_unshift($order, $root_id);
    } elseif ($position === 'last') {
        $order[] = $root_id;
    } else {
        $at = array_search((string) $ref, $order, true);
        if ($at === false) {
            return false;
        }
        array_splice($order, $position === 'before' ? $at : $at + 1, 0, [$root_id]);
    }
    foreach ($nodes as $nid => $n) {
        $data[$nid] = $n;
    }
    $data[$root_id]->parent = $parent === '' ? null : $parent;
    foreach ($order as $pos => $sid) {
        if (isset($data[$sid]) && is_object($data[$sid])) {
            $data[$sid]->position = $pos;
        }
    }
    return true;
}

/** Take a node and its subtree out of a layout and renumber the siblings it leaves. */
function bulkseme_bb_remove(array &$data, array $ids) {
    $root = isset($ids[0]) ? (string) $ids[0] : '';
    if ($root === '' || !isset($data[$root]) || !is_object($data[$root])) {
        return false;
    }
    $parent = empty($data[$root]->parent) ? '' : (string) $data[$root]->parent;
    foreach ($ids as $nid) {
        unset($data[(string) $nid]);
    }
    $pos = 0;
    foreach (bulkseme_bb_children($data, $parent) as $n) {
        $n->position = $pos++;
    }
    return true;
}

/**
 * Apply inserts[] to a layout. Returns [results, changed, created], where
 * created lists, per placed copy, [ref, position, root, ids] for the draft.
 * $taken holds every node id already in use (published and draft).
 */
function bulkseme_bb_apply_inserts(array &$data, array $inserts, $verbatim, array $taken, $post) {
    $results    = [];
    $changed    = 0;
    $created    = [];
    $total      = 0;
    $base_nodes = count($data);
    $bytes      = strlen(bulkseme_bb_encode($data));
    $max_nodes  = (int) apply_filters('bulkseme_beaver_max_nodes', 3000);
    $max_bytes  = (int) apply_filters('bulkseme_beaver_max_layout_bytes', 8388608);
    list(, , $gmap) = bulkseme_bb_flatten($data);
    $global_template = is_object($post) && $post->post_type === 'fl-builder-template' && get_post_meta($post->ID, '_fl_builder_template_global', true);
    foreach ($inserts as $n => $ins) {
        $ins    = is_array($ins) ? $ins : [];
        $ref    = isset($ins['ref']) && is_scalar($ins['ref']) ? (string) $ins['ref'] : '';
        $pos    = isset($ins['position']) && is_scalar($ins['position']) ? (string) $ins['position'] : 'after';
        $from   = isset($ins['copy_from']) && is_array($ins['copy_from']) ? $ins['copy_from'] : [];
        $src    = isset($from['el']) && is_scalar($from['el']) ? (string) $from['el'] : '';
        $set    = isset($ins['set']) ? $ins['set'] : [];
        $base   = ['insert' => $n, 'ref' => $ref, 'position' => $pos, 'copy_from' => ['el' => $src]];
        $fail   = function ($status, $err, $extra = []) use (&$results, $base) {
            $results[] = $base + ['status' => $status, 'errors' => [$err]] + $extra;
        };
        if (!preg_match('/^[A-Za-z0-9_-]{1,64}$/', $ref) || !preg_match('/^[A-Za-z0-9_-]{1,64}$/', $src)) {
            $fail('invalid_value', 'missing_or_invalid_ref_or_el');
            continue;
        }
        if (isset($from['post_id']) && absint($from['post_id']) !== (int) $post->ID) {
            $fail('invalid_value', 'copy_from_must_be_this_page');
            continue;
        }
        if (!in_array($pos, ['before', 'after', 'first', 'last'], true)) {
            $fail('invalid_value', 'position_must_be_before_after_first_last');
            continue;
        }
        if (!is_array($set) || count($set) > 50) {
            $fail('invalid_value', 'set_must_be_a_list_of_at_most_50');
            continue;
        }
        if ($global_template) {
            $fail('setting_not_editable', 'not_on_a_global_template', ['hint' => 'copies inside a saved global row or module are not supported; edit its text with updates[]']);
            continue;
        }
        if (!isset($data[$src]) || !is_object($data[$src]) || !isset($data[$src]->type)) {
            $fail('element_not_found', 'copy_from_el_not_found');
            continue;
        }
        if (isset($gmap[$src]) || isset($gmap[$ref])) {
            $tpl = isset($gmap[$src]) ? $gmap[$src] : $gmap[$ref];
            $fail('global_node_template', 'global_nodes_are_not_copied_or_inserted_into', ['template_post_id' => $tpl > 0 ? $tpl : null]);
            continue;
        }
        $ids = bulkseme_bb_subtree_ids($data, $src, 200);
        if ($ids !== null && $total + count($ids) + $base_nodes > $max_nodes) {
            $fail('invalid_value', 'layout_too_large', ['hint' => 'the layout would pass ' . $max_nodes . ' nodes']);
            continue;
        }
        if ($ids === null || $total + count($ids) > 500) {
            $fail('invalid_value', 'copy_too_large', ['hint' => 'max 200 nodes per copy, 500 per request, 12 levels']);
            continue;
        }
        $root_type = (string) $data[$src]->type;
        $target    = bulkseme_bb_target($data, $ref, $pos, $root_type);
        if (!is_array($target)) {
            $fail('invalid_value', $target);
            continue;
        }
        list($parent, $sib) = $target;
        if ($parent !== '' && isset($gmap[$parent])) {
            $fail('global_node_template', 'global_nodes_are_not_copied_or_inserted_into', ['template_post_id' => $gmap[$parent] > 0 ? $gmap[$parent] : null]);
            continue;
        }
        if ($parent !== '' && in_array($parent, $ids, true)) {
            $fail('invalid_value', 'ref_inside_the_copied_node');
            continue;
        }
        if (!$verbatim && $parent !== '' && bulkseme_bb_bad_container_chain($data, $parent)) {
            // Copied text would be printed inside an element whose name the builder would not allow.
            $fail('setting_not_editable', 'container_element_outside_allowed_values');
            continue;
        }
        // Copy, check and re-key every node of the subtree.
        $sanitized = false;
        $why       = '';
        $nodes     = [];
        $id_map    = [];
        $try_taken = $taken;
        foreach ($ids as $old) {
            $id_map[$old] = bulkseme_bb_new_id($try_taken);
            if ($id_map[$old] === '') {
                $why = 'could_not_make_a_node_id';
                break;
            }
        }
        foreach ($ids as $old) {
            if ($why !== '') {
                break;
            }
            $c = bulkseme_bb_clone($data[$old]);
            if (!is_object($c) || !empty($c->template_id) || !empty($c->template_node_id) || !empty($c->global)) {
                $why = 'global_node_in_copy';
                break;
            }
            if (!$verbatim) {
                $why = bulkseme_bb_clean_copy_node($c, $sanitized);
                if ($why !== '') {
                    break;
                }
            }
            $c->node = $id_map[$old];
            if ($old !== $src) {
                $op        = empty($c->parent) ? '' : (string) $c->parent;
                $c->parent = isset($id_map[$op]) ? $id_map[$op] : null;
            }
            $nodes[$id_map[$old]] = $c;
        }
        if ($why !== '') {
            $fail('setting_not_editable', $why, ['hint' => 'a verbatim copy needs the bulkseme_allow_unfiltered_html site filter, the unfiltered_html capability and unsafe_html=1']);
            continue;
        }
        $add_bytes = strlen(bulkseme_bb_encode($nodes));
        if ($bytes + $add_bytes > $max_bytes) {
            $fail('invalid_value', 'layout_too_large', ['hint' => 'the layout would pass ' . $max_bytes . ' bytes']);
            continue;
        }
        $trial = bulkseme_bb_clone($data);
        $root  = $id_map[$src];
        if (!bulkseme_bb_place($trial, $parent, $sib, $pos, $root, $nodes)) {
            $fail('invalid_value', 'ref_not_found');
            continue;
        }
        // set[]: text and layout settings of the copy, through the updates[] validators.
        $set_updates = [];
        $set_bad     = '';
        foreach ($set as $item) {
            $sel = is_array($item) && isset($item['el']) && is_scalar($item['el']) ? (string) $item['el'] : $src;
            if (!isset($id_map[$sel])) {
                $set_bad = 'set_el_not_in_copy';
                break;
            }
            $set_updates[] = [
                'el'      => $id_map[$sel],
                'setting' => is_array($item) && isset($item['setting']) ? $item['setting'] : '',
                'value'   => is_array($item) && array_key_exists('value', $item) ? $item['value'] : null,
            ];
        }
        if ($set_bad !== '') {
            $fail('invalid_value', $set_bad);
            continue;
        }
        $set_results = [];
        if ($set_updates) {
            list($set_results) = bulkseme_bb_apply_updates($trial, $set_updates, $verbatim);
            foreach ($set_results as $sr) {
                if (!in_array($sr['status'], ['ok', 'unchanged'], true)) {
                    $fail('invalid_value', 'set_failed', ['set' => $set_results]);
                    continue 2;
                }
            }
        }
        $data    = $trial;
        $taken   = $try_taken;
        $total  += count($ids);
        $bytes  += $add_bytes;
        $changed++;
        $created[] = ['ref' => $sib !== '' ? $sib : $parent, 'position' => $pos, 'root' => $root, 'ids' => array_values($id_map)];
        $results[] = $base + [
            'status' => 'ok', 'new_el' => $root, 'created' => array_values($id_map), 'id_map' => $id_map,
            'type' => $root_type, 'module' => ($root_type === 'module' && isset($nodes[$root]->settings->type)) ? (string) $nodes[$root]->settings->type : '',
            'sanitized' => $sanitized, 'set' => $set_results,
        ];
    }
    return [$results, $changed, $created];
}

/**
 * Carry placed copies into the draft layout, at the same place relative to the
 * same ref, with the same node ids and the values the published copy ended with.
 * Returns [copied count, conflicts].
 */
function bulkseme_bb_draft_inserts(array &$draft, array $published, array $created, $verbatim = false) {
    $done      = 0;
    $conflicts = [];
    foreach ($created as $c) {
        $root  = $c['root'];
        $clash = false;
        foreach ($c['ids'] as $nid) {
            if (isset($draft[$nid])) {
                $clash = true;
            }
        }
        $rtype  = isset($published[$root]->type) ? (string) $published[$root]->type : '';
        $target = $clash ? 'id_in_use' : bulkseme_bb_target($draft, $c['ref'], $c['position'], $rtype);
        if (is_array($target) && !$verbatim && $target[0] !== '' && bulkseme_bb_bad_container_chain($draft, $target[0])) {
            $target = 'bad_container_in_draft';
        }
        if (!is_array($target)) {
            $conflicts[] = ['el' => $root, 'setting' => '', 'reason' => 'insert_not_in_draft'];
            continue;
        }
        $nodes = [];
        foreach ($c['ids'] as $nid) {
            if (!isset($published[$nid])) {
                continue 2;
            }
            $nodes[$nid] = bulkseme_bb_clone($published[$nid]);
        }
        if (bulkseme_bb_place($draft, $target[0], $target[1], $c['position'], $root, $nodes)) {
            $done++;
        } else {
            $conflicts[] = ['el' => $root, 'setting' => '', 'reason' => 'insert_not_in_draft'];
        }
    }
    return [$done, $conflicts];
}

/**
 * Text-only revert: take out copies the backed-up request placed (listed in its
 * note) while each still holds exactly the nodes that request made and none of
 * them is in the backup. Returns [results, removed id lists].
 */
function bulkseme_bb_remove_inserted(array &$data, array $backup, $note) {
    $results = [];
    $removed = [];
    if (!preg_match('/(?:^|\s)inserts:([A-Za-z0-9_,+-]{1,20000})$/', (string) $note, $m)) {
        return [$results, $removed];
    }
    foreach (array_filter(explode(',', $m[1])) as $entry) {
        $made = array_values(array_filter(explode('+', $entry)));
        $root = isset($made[0]) ? $made[0] : '';
        if ($root === '' || !isset($data[$root]) || isset($backup[$root])) {
            continue;
        }
        $now = bulkseme_bb_subtree_ids($data, $root);
        if ($now === null || array_diff($now, $made) || array_intersect($now, array_keys($backup))) {
            $results[] = ['el' => $root, 'setting' => '', 'status' => 'skipped', 'errors' => ['insert_changed_since_keep_it']];
            continue;
        }
        if (bulkseme_bb_remove($data, $now)) {
            $results[] = ['el' => $root, 'setting' => '', 'status' => 'ok', 'removed' => true];
            $removed[] = $now;
        }
    }
    return [$results, $removed];
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
    // A backup written by 2.6.2 or older was not checked on the way in; it is read under the same rules as a stored layout.
    return (is_array($layout) && $layout && bulkseme_bb_readable($layout)) ? $layout : null;
}

/** Load the published (or draft) layout; WP_Error when the published one is missing, null for a missing draft. */
function bulkseme_bb_load($post_id, $status = 'published') {
    $raw = get_metadata('post', $post_id, ($status === 'draft') ? '_fl_builder_draft' : '_fl_builder_data', true);
    if (!is_array($raw) || !$raw) {
        return ($status === 'draft') ? null : new WP_Error('no_beaver_data', 'post has no _fl_builder_data layout', ['status' => 409]);
    }
    if (!bulkseme_bb_readable($raw)) {
        return ($status === 'draft') ? null : new WP_Error('beaver_layout_unreadable', 'the stored layout holds node ids or data this plugin cannot read the way Beaver Builder does; open and save the page in Beaver Builder once', ['status' => 409]);
    }
    return $raw;
}

/**
 * True when a layout (stored, or decoded from a backup) is one this plugin
 * reads exactly as Beaver Builder does: ids that match one way only
 * (bulkseme_bb_ids_ok), node types, module types and template ids that are
 * strings or scalars (never cast from an object), and plain data only.
 */
function bulkseme_bb_readable(array $raw) {
    if (!bulkseme_bb_ids_ok($raw)) {
        return false;
    }
    foreach ($raw as $n) {
        $types_ok = !is_object($n) || ((!isset($n->type) || is_string($n->type))
            && (!isset($n->template_id) || is_scalar($n->template_id))
            && (!isset($n->settings) || !is_object($n->settings) || !isset($n->settings->type) || is_string($n->settings->type)));
        if ((is_object($n) && !($n instanceof stdClass)) || !$types_ok || !bulkseme_bb_plain($n)) {
            return false;
        }
    }
    return true;
}

/**
 * True when node ids and parents can only be matched one way. Beaver Builder
 * keys nodes by ->node and finds children with a loose ==; this plugin walks
 * them with exact string matches. So every ->node must be a string equal to
 * its key, every parent null, '' or a string, all of the id form, and no two
 * different numeric-looking ids may be equal as numbers ("100", "1e2", " 100").
 * Older layouts with hex ids that look numeric stay readable unless two of
 * them collide.
 */
function bulkseme_bb_ids_ok(array $raw) {
    $nums = [];
    $note = function ($id) use (&$nums) {
        if (is_numeric($id)) {
            // -0.0 + 0.0 is 0.0, so "0", "-0", "0e5" share a group. Ids past float range compare
            // as plain strings in PHP (and in the builder), so each keeps a group of its own.
            $f = (float) $id + 0.0;
            $nums[is_finite($f) ? sprintf('%.17G', $f) : 's:' . $id][$id] = true;
        }
    };
    // An id the plugin's own empty() checks would read as "no parent" ("0") is never accepted.
    $id_form = function ($v) {
        return is_string($v) && $v !== '0' && preg_match('/^[A-Za-z0-9_-]{1,64}\z/', $v) === 1;
    };
    foreach ($raw as $key => $n) {
        if (!is_object($n)) {
            continue;
        }
        if (!isset($n->node) || !$id_form($n->node) || $n->node !== (string) $key) {
            return false;
        }
        $note($n->node);
        if (isset($n->parent) && $n->parent !== '') {
            if (!$id_form($n->parent)) {
                return false;
            }
            $note($n->parent);
        }
    }
    foreach ($nums as $group) {
        if (count($group) > 1) {
            return false;
        }
    }
    return true;
}

/** True when every string and key in a layout value is valid UTF-8 (the backup encoding would rewrite the rest). */
function bulkseme_bb_utf8($value, $depth = 0) {
    if ($depth > 64) {
        return false;
    }
    if (is_string($value)) {
        return $value === '' || preg_match('//u', $value) === 1;
    }
    if (is_object($value)) {
        $value = get_object_vars($value);
    }
    if (is_array($value)) {
        foreach ($value as $k => $v) {
            if ((is_string($k) && !bulkseme_bb_utf8($k, $depth + 1)) || !bulkseme_bb_utf8($v, $depth + 1)) {
                return false;
            }
        }
    }
    return true;
}

/**
 * True when a layout value is plain data the backup encoding carries exactly:
 * stdClass objects, arrays and scalars only, no property name holding a NUL
 * byte (a cast from a class with private or protected members), at most 60
 * levels deep. Anything else would be lost in a backup or a copy (2.7.0).
 */
function bulkseme_bb_plain($value, $depth = 0) {
    if ($depth > 60) {
        return false;
    }
    if (is_object($value)) {
        if (!($value instanceof stdClass)) {
            return false;
        }
        foreach (get_object_vars($value) as $k => $v) {
            if (strpos((string) $k, "\0") !== false || !bulkseme_bb_plain($v, $depth + 1)) {
                return false;
            }
        }
        return true;
    }
    if (is_array($value)) {
        foreach ($value as $v) {
            if (!bulkseme_bb_plain($v, $depth + 1)) {
                return false;
            }
        }
        return true;
    }
    return $value === null || is_scalar($value);
}

/**
 * Carry successful updates into the draft layout when it still holds the value
 * the published layout had. Returns [synced count, conflicts].
 */
function bulkseme_bb_sync_draft(array &$draft, array $results, $verbatim = false, array $published = []) {
    $synced       = 0;
    $conflicts    = [];
    $fields_cache = [];
    foreach ($results as $r) {
        if (!isset($r['status']) || $r['status'] !== 'ok' || !array_key_exists('previous', $r)) {
            continue; // removals and other non-value results are carried by their own step
        }
        $n    = bulkseme_bb_locate($draft, $r['el']);
        $segs = bulkseme_so_path_parse($r['setting']);
        if ($n === null || $segs === null) {
            $conflicts[] = ['el' => $r['el'], 'setting' => $r['setting'], 'reason' => 'node_missing_in_draft'];
            continue;
        }
        // The value was checked against the published module: the draft node must be the same module type,
        // with no tag or container around it that the published one did not have.
        $dslug = isset($n->settings->type) && is_string($n->settings->type) ? $n->settings->type : '';
        $pn    = isset($published[$r['el']]) && is_object($published[$r['el']]) ? $published[$r['el']] : null;
        $pset  = ($pn && isset($pn->settings) && is_object($pn->settings)) ? $pn->settings : null;
        $pslug = ($pset && isset($pset->type) && is_string($pset->type)) ? $pset->type : '';
        // The tag check guards text printed inside an element; a link, tag or target value is not such text,
        // and a fix of a bad tag must be able to reach the draft too. Same precedence as
        // bulkseme_bb_apply_updates(): a setting the copy map lists is text, even if a filter also maps it as layout.
        // Worked out only when the draft node has a bad tag, with the published maps cached per node.
        $is_text = function () use ($pset, $pslug, $r, &$fields_cache) {
            if (!$pset) {
                return true;
            }
            if (!isset($fields_cache[$r['el']])) {
                $fields_cache[$r['el']] = [bulkseme_bb_module_fields($pset, $pslug), bulkseme_bb_layout_fields($pset, $pslug)];
            }
            list($text_fields, $layout_fields) = $fields_cache[$r['el']];
            return isset($text_fields[$r['setting']]) || !isset($layout_fields[$r['setting']]);
        };
        if ($pn === null || $dslug !== $pslug
            || (!$verbatim && ((bulkseme_bb_bad_tag($n->settings, $dslug) && $is_text()) || bulkseme_bb_bad_container_chain($draft, $r['el'])))) {
            $conflicts[] = ['el' => $r['el'], 'setting' => $r['setting'], 'reason' => 'draft_differs'];
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

/**
 * Back up, then save. $ops (2.7.0) carries structural changes for the draft:
 * 'inserted' => created list from bulkseme_bb_apply_inserts(), 'removed' => id
 * lists from bulkseme_bb_remove_inserted(). They are applied to the draft only
 * where it still matches (same ref, ids free, or exactly the same subtree).
 */
function bulkseme_bb_commit($post_id, $prev, array $published, array $results, $changed, $dry_run, $note = 'pre-update', array $ops = []) {
    $out = [
        'id' => $post_id, 'builder' => 'beaver', 'dry_run' => (bool) $dry_run,
        'count' => count($results), 'changed' => $changed, 'results' => $results,
    ];
    // Byte cap for every write (also answered on a dry run). A text-only revert may go back up to the
    // size of the backup it restores, which the page really had, but never beyond.
    if ($changed > 0) {
        $max_bytes = (int) apply_filters('bulkseme_beaver_max_layout_bytes', 8388608);
        $new_bytes = strlen(bulkseme_bb_encode($published));
        $allow     = max($max_bytes, strlen((string) $prev), isset($ops['revert_bytes']) ? (int) $ops['revert_bytes'] : 0);
        if ($new_bytes > $allow) {
            return new WP_Error('layout_too_large', 'after this write the layout would exceed ' . $max_bytes . ' bytes; nothing was written', ['status' => 413]);
        }
    }
    if ($dry_run || $changed === 0) {
        $out['data_hash'] = md5($prev);
        return $out;
    }
    if (!bulkseme_beaver_active()) {
        return new WP_Error('builder_inactive', 'Beaver Builder must be active to save a layout; nothing was written', ['status' => 409]);
    }
    $stored     = bulkseme_bb_load($post_id);
    $draft      = bulkseme_bb_load($post_id, 'draft');
    $draft_raw  = get_metadata('post', $post_id, '_fl_builder_draft', true);
    if (($draft === null && is_array($draft_raw) && $draft_raw) || !is_array($stored) || !bulkseme_bb_utf8($stored) || !bulkseme_bb_utf8($published) || (is_array($draft) && !bulkseme_bb_utf8($draft))) {
        // A backup must restore exactly; a draft this plugin cannot read would also undo the write on the next publish.
        return new WP_Error('beaver_layout_unreadable', 'the layout or its draft holds data this plugin cannot back up exactly (invalid UTF-8 or objects it does not read); open and save the page in Beaver Builder once; nothing was written', ['status' => 409]);
    }
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
        $structural = 0;
        $conflicts  = [];
        if (!empty($ops['inserted']) && is_array($ops['inserted'])) {
            list($done, $conflicts) = bulkseme_bb_draft_inserts($draft, $published, $ops['inserted'], !empty($ops['verbatim']));
            $structural += $done;
        }
        if (!empty($ops['removed']) && is_array($ops['removed'])) {
            foreach ($ops['removed'] as $ids) {
                $root = isset($ids[0]) ? (string) $ids[0] : '';
                $now  = $root !== '' ? bulkseme_bb_subtree_ids($draft, $root) : null;
                if ($now !== null && !array_diff($now, $ids) && !array_diff($ids, $now) && bulkseme_bb_remove($draft, $now)) {
                    $structural++;
                } elseif ($root !== '' && isset($draft[$root])) {
                    $conflicts[] = ['el' => $root, 'setting' => '', 'reason' => 'draft_differs'];
                }
            }
        }
        list($synced, $more) = bulkseme_bb_sync_draft($draft, $results, !empty($ops['verbatim']), $published);
        $out['draft_synced']    = $synced + $structural;
        $out['draft_conflicts'] = array_merge($conflicts, $more);
        if ($synced + $structural > 0) {
            $write_draft = $draft;
        }
    }
    $out   = array_merge($out, bulkseme_bb_write($post_id, $published, $write_draft));
    $after = bulkseme_bb_load($post_id);
    $out['data_hash'] = is_wp_error($after) ? '' : md5(bulkseme_bb_encode($after));
    do_action('bulkseme_content_updated', $post_id, $results, get_current_user_id());
    return $out;
}

/**
 * POST /content on a Beaver Builder page: inserts[] first, then updates[], then
 * one backup and one save. atomic=1 refuses the whole request when any insert or
 * update fails. The backup note lists the nodes the inserts created, so a
 * text-only revert can take them out again.
 */
function bulkseme_bb_post($post, array $updates, array $inserts, $unsafe, $dry_run, $atomic, $base_hash) {
    $id = (int) $post->ID;
    if ($inserts && !bulkseme_beaver_user_has_access('unrestricted_editing')) {
        // Beaver Builder gives roles without Unrestricted Editing a simple editor with no duplicate, move or remove.
        return new WP_Error('forbidden_builder', 'Beaver Builder\'s Unrestricted Editing setting does not include your role, so you may change text and links but not copy modules or rows', ['status' => 403]);
    }
    if ($inserts && !bulkseme_beaver_active()) {
        return new WP_Error('builder_inactive', 'Beaver Builder must be active to copy modules or rows; nothing was written', ['status' => 409]);
    }
    $data = bulkseme_bb_load($id);
    if (is_wp_error($data)) {
        return $data;
    }
    $prev = bulkseme_bb_encode($data);
    if (is_string($base_hash) && $base_hash !== '' && !hash_equals(md5($prev), $base_hash)) {
        return new WP_Error('hash_conflict', 'content changed since it was read; re-GET /content and retry', ['status' => 409]);
    }
    $ins_results = [];
    $ins_changed = 0;
    $created     = [];
    $max_nodes   = (int) apply_filters('bulkseme_beaver_max_nodes', 3000);
    $max_bytes   = (int) apply_filters('bulkseme_beaver_max_layout_bytes', 8388608);
    if ($inserts && (count($data) >= $max_nodes || strlen($prev) >= $max_bytes)) {
        return new WP_Error('layout_too_large', 'this layout already holds ' . count($data) . ' nodes (' . strlen($prev) . ' bytes); copies are refused past ' . $max_nodes . ' nodes or ' . $max_bytes . ' bytes', ['status' => 413]);
    }
    if ($inserts) {
        $taken = [];
        foreach (array_keys($data) as $k) {
            $taken[(string) $k] = true;
        }
        $draft = bulkseme_bb_load($id, 'draft');
        if (is_array($draft)) {
            foreach (array_keys($draft) as $k) {
                $taken[(string) $k] = true;
            }
        }
        list($ins_results, $ins_changed, $created) = bulkseme_bb_apply_inserts($data, $inserts, bulkseme_content_raw_html_allowed($unsafe), $taken, $post);
    }
    list($results, $changed) = $updates ? bulkseme_bb_apply_updates($data, $updates, $unsafe) : [[], 0];
    if ($ins_changed && count($data) > $max_nodes) {
        return new WP_Error('layout_too_large', 'after the inserts the layout would hold more than ' . $max_nodes . ' nodes; nothing was written', ['status' => 413]);
    }
    // The byte cap for every write path (updates, inserts, find and replace) is in bulkseme_bb_commit().
    if ($atomic) {
        foreach (array_merge($ins_results, $results) as $r) {
            if (!in_array($r['status'], ['ok', 'unchanged'], true)) {
                return new WP_Error('atomic_failed', 'atomic=1 and at least one item failed; nothing was written', ['status' => 409, 'inserts' => $ins_results, 'results' => $results]);
            }
        }
    }
    $made = [];
    foreach ($created as $c) {
        $made[] = implode('+', $c['ids']); // top node first, then every node the copy created under it
    }
    $note = $made ? 'pre-update inserts:' . implode(',', $made) : 'pre-update';
    $out  = bulkseme_bb_commit($id, $prev, $data, $results, $changed + $ins_changed, $dry_run, $note, ['inserted' => $created, 'verbatim' => bulkseme_content_raw_html_allowed($unsafe)]);
    if (!is_wp_error($out) && $inserts) {
        $out['inserts'] = $ins_results;
    }
    return $out;
}

/** Structure of a layout for comparison: node id => type, parent and position, sorted by id. */
function bulkseme_bb_shape(array $data) {
    $out = [];
    foreach ($data as $id => $n) {
        if (is_object($n)) {
            $out[(string) $id] = (isset($n->type) ? (string) $n->type : '') . '|' . (empty($n->parent) ? '' : (string) $n->parent) . '|' . (isset($n->position) && is_scalar($n->position) ? (int) $n->position : 0);
        }
    }
    ksort($out, SORT_STRING);
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
    if (!bulkseme_beaver_user_has_access('unrestricted_editing')) {
        // Adding, removing or moving rows and modules is not this role's to do in Beaver Builder:
        // unless the backup has exactly the page's structure (published and draft), restore the text only.
        $cur_d = bulkseme_bb_load($id, 'draft');
        if (is_wp_error($cur) || bulkseme_bb_shape($pub) !== bulkseme_bb_shape($cur)
            || bulkseme_bb_shape(is_array($draft) ? $draft : []) !== bulkseme_bb_shape(is_array($cur_d) ? $cur_d : [])) {
            return bulkseme_content_restore_text_only($post, $env, $ts);
        }
    }
    $cur_draft = bulkseme_bb_load($id, 'draft');
    if (is_wp_error($cur) && $cur->get_error_code() !== 'no_beaver_data') {
        return new WP_Error('backup_failed', 'the current layout cannot be backed up (' . $cur->get_error_code() . '), so it is not overwritten; nothing was restored', ['status' => 409]);
    }
    $cur_enc   = is_wp_error($cur) ? '' : bulkseme_bb_encode($cur);
    $draft_enc = is_array($cur_draft) ? bulkseme_bb_encode($cur_draft) : null;
    $draft_raw = get_metadata('post', $id, '_fl_builder_draft', true);
    if ((!is_wp_error($cur) && ($cur_enc === '' || !bulkseme_bb_utf8($cur))) || $draft_enc === '' || (is_array($cur_draft) && !bulkseme_bb_utf8($cur_draft)) || ($cur_draft === null && is_array($draft_raw) && $draft_raw)) {
        return new WP_Error('backup_failed', 'the current layout or draft cannot be backed up exactly, so it is not overwritten; nothing was restored', ['status' => 409]);
    }
    $pre    = wp_json_encode(['published' => $cur_enc, 'draft' => $draft_enc]);
    $pre_ts = bulkseme_content_take_backup($id, 'beaver', $pre === false ? '' : $pre, 'pre-revert');
    if (!$pre_ts) {
        return new WP_Error('backup_failed', 'could not store a backup of the current content; nothing was restored', ['status' => 500]);
    }
    $side  = bulkseme_bb_write($id, $pub, is_array($draft) ? $draft : null);
    if (!is_array($draft) && is_array($cur_draft)) {
        // The backup was taken with no draft: a newer draft would bring the reverted content back on the next publish.
        delete_post_meta($id, '_fl_builder_draft');
        $side['draft_removed'] = true;
    }
    $after = bulkseme_bb_load($id);
    do_action('bulkseme_content_reverted', $id, $ts, get_current_user_id());
    return array_merge([
        'id' => $id, 'status' => 'ok', 'reverted_to' => $ts,
        'pre_revert_backup_ts' => $pre_ts, 'kind' => 'beaver',
        'data_hash' => is_wp_error($after) ? '' : md5(bulkseme_bb_encode($after)),
    ], $side);
}
