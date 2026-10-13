<?php
/**
 * Page content stored by Elementor.
 *
 * Elementor keeps a page in the _elementor_data post meta as a JSON string, not
 * in post_content, so core REST cannot change it.
 *
 * This module does not write through Elementor's Document::save(). That path
 * builds every element on read and on save: widgets whose type is not
 * registered at that moment (Elementor Pro or an add-on switched off for a
 * while) are silently dropped from the page, and unregistered dynamic tags are
 * stripped. Instead the JSON is decoded, only the targeted text values are
 * changed, and the result is stored the way Elementor itself stores it.
 * Unknown widgets, dynamic tags and untouched settings come through unchanged
 * because they never pass through element instances.
 *
 * After a write the same side effects as Document::save() are repeated: the
 * post's generated CSS and its rendered-HTML cache are dropped and post_content
 * is synced through Elementor's own save_plain_text(), which also creates the
 * WordPress revision and fires save_post so page caches purge the URL.
 *
 * Reads use the raw meta, the published data. Autosaves live on the revision.
 */

if (!defined('ABSPATH')) exit;

/**
 * Elementor's own answer to "may this user edit this document?".
 *
 * With Elementor running this is Document::is_editable_by_current_user(), the
 * check its own save goes through: the capability of the document type (the
 * site Kit needs edit_theme_options), the roles excluded in its settings, the
 * post types it is switched on for, and edit_post. Components render on every
 * page that embeds them, and Elementor keeps managing them to administrators,
 * so they need manage_options here. If Elementor is running but gives no
 * answer, the answer is no. With Elementor switched off its excluded roles
 * setting is still read and followed.
 */
function bulkseme_elementor_user_can_edit($post) {
    if ($post->post_type === 'elementor_component' && !current_user_can('manage_options')) {
        return false;
    }
    if (!bulkseme_elementor_active()) {
        $excluded = get_option('elementor_exclude_user_roles', []);
        $roles    = (array) wp_get_current_user()->roles;
        return !(is_array($excluded) && array_intersect($roles, $excluded));
    }
    try {
        $document = \Elementor\Plugin::$instance->documents->get($post->ID);
        if ($document && is_callable([$document, 'is_editable_by_current_user'])) {
            return (bool) $document->is_editable_by_current_user();
        }
    } catch (\Throwable $e) {
        return false;
    }
    return false;
}

// ---- Editable-settings resolution -------------------------------------------

/**
 * Fallback widget -> text-setting map for when Elementor is INACTIVE and we
 * cannot introspect controls. Core widgets only, verified against the 4.x
 * sources (call-to-action/blockquote are Pro-only; video has no caption; the
 * image widget's alt lives on the attachment, handled by /bulk-alts).
 * Shapes: 'setting' => control type, or repeater => ['repeater' => [field => type]].
 */
function bulkseme_elementor_static_settings_map() {
    return [
        'heading'     => ['title' => 'textarea'],
        'text-editor' => ['editor' => 'wysiwyg'],
        'button'      => ['text' => 'text'],
        'image'       => ['caption' => 'text'], // rendered only when caption_source=custom
        'icon-box'    => ['title_text' => 'text', 'description_text' => 'textarea'],
        'image-box'   => ['title_text' => 'text', 'description_text' => 'textarea'],
        'testimonial' => ['testimonial_content' => 'textarea', 'testimonial_name' => 'text', 'testimonial_job' => 'text'],
        'accordion'   => ['tabs' => ['repeater' => ['tab_title' => 'text', 'tab_content' => 'wysiwyg']]],
        'tabs'        => ['tabs' => ['repeater' => ['tab_title' => 'text', 'tab_content' => 'wysiwyg']]],
        'toggle'      => ['tabs' => ['repeater' => ['tab_title' => 'text', 'tab_content' => 'wysiwyg']]],
        'icon-list'   => ['icon_list' => ['repeater' => ['text' => 'text']]],
        'counter'     => ['title' => 'text', 'prefix' => 'text', 'suffix' => 'text'],
        'alert'       => ['alert_title' => 'text', 'alert_description' => 'textarea'],
        'progress'    => ['title' => 'text', 'inner_text' => 'text'],
        'star-rating' => ['title' => 'text'],
        'divider'     => ['text' => 'text'],
    ];
}

/**
 * Decide whether one registered control is agent-editable page copy.
 * Returns 'text'|'textarea'|'wysiwyg', a ['repeater' => fields] spec, or null.
 * Filters out style/advanced-tab controls and CSS/ID hooks (button_css_id,
 * _css_classes, ...) so we only ever touch human-visible text.
 */
function bulkseme_elementor_control_spec($name, $ctl) {
    if (!is_string($name) || $name === '' || $name[0] === '_') {
        return null;
    }
    if (!is_array($ctl) || empty($ctl['type']) || !is_string($ctl['type'])) {
        return null;
    }
    if (preg_match('/(_css|_class(es)?|_id)$/', $name)) {
        return null;
    }
    if (isset($ctl['tab']) && $ctl['tab'] !== 'content') {
        return null;
    }
    $type = $ctl['type'];
    if (in_array($type, ['text', 'textarea', 'wysiwyg'], true)) {
        return $type;
    }
    if ($type === 'repeater' && !empty($ctl['fields']) && is_array($ctl['fields'])) {
        $fields = [];
        foreach ($ctl['fields'] as $fkey => $fctl) {
            if (!is_array($fctl) || empty($fctl['type'])) {
                continue;
            }
            $fname = isset($fctl['name']) && is_string($fctl['name']) ? $fctl['name'] : $fkey;
            if (!is_string($fname) || $fname === '' || $fname[0] === '_') {
                continue;
            }
            if (preg_match('/(_css|_class(es)?|_id)$/', $fname)) {
                continue;
            }
            if (in_array($fctl['type'], ['text', 'textarea', 'wysiwyg'], true)) {
                $fields[$fname] = $fctl['type'];
            }
        }
        if ($fields) {
            return ['repeater' => $fields];
        }
    }
    return null;
}

/**
 * Editable settings for one widget type. When Elementor is active we
 * introspect the registered control stack (TEXT/TEXTAREA/WYSIWYG controls,
 * recursing into REPEATER fields) - this automatically covers Elementor Pro
 * and third-party widgets with zero per-widget maintenance. When it is not,
 * fall back to the static core map above.
 */
function bulkseme_elementor_editable_settings($widget_type) {
    static $cache = [];
    if (array_key_exists($widget_type, $cache)) {
        return $cache[$widget_type];
    }
    $map = null;
    if (bulkseme_elementor_active()) {
        try {
            $widget = \Elementor\Plugin::$instance->widgets_manager->get_widget_types($widget_type);
            if ($widget && method_exists($widget, 'get_controls')) {
                $map = [];
                $controls = $widget->get_controls();
                if (is_array($controls)) {
                    foreach ($controls as $name => $ctl) {
                        $spec = bulkseme_elementor_control_spec($name, $ctl);
                        if ($spec !== null) {
                            $map[$name] = $spec;
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
            $map = null; // fall through to the static map
        }
    }
    if ($map === null) {
        $static = bulkseme_elementor_static_settings_map();
        $map = isset($static[$widget_type]) ? $static[$widget_type] : [];
    }
    $cache[$widget_type] = $map;
    return $map;
}

// ---- Layout settings: heading level, link URLs, custom CSS ----------
// The text controls above are page copy. Three structural controls an SEO agent
// also has to reach get their own validators (none of them goes through kses):
//   - the HTML tag a heading renders as (`header_size` on heading, `title_size`
//     on icon-box/image-box, any SELECT control whose options are h1..h6 tags;
//     `tag` on Elementor 4 atomic e-heading), addressed by the control name;
//   - the URL of a URL control (`link.url`, or `icon_list[<_id>].link.url` inside
//     a repeater), validated with esc_url_raw, relative paths allowed;
//   - the element's Custom CSS (`custom_css`, Elementor Pro's Advanced tab), on
//     ANY element type, no markup characters, caller needs unfiltered_html.

/** HTML tags Elementor offers for a heading-level control. */
function bulkseme_elementor_tag_options() {
    return ['h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'div', 'span', 'p'];
}

/** Fallback widget -> layout-setting map for when Elementor is INACTIVE. Core widgets only. */
function bulkseme_elementor_static_layout_map() {
    $tags = bulkseme_elementor_tag_options();
    return [
        'heading'   => ['header_size' => ['tag' => $tags, 'default' => 'h2'], 'link' => 'url'],
        'icon-box'  => ['title_size' => ['tag' => $tags, 'default' => 'h3'], 'link' => 'url'],
        'image-box' => ['title_size' => ['tag' => $tags, 'default' => 'h3'], 'link' => 'url'],
        'button'    => ['link' => 'url'],
        'icon'      => ['link' => 'url'],
        'image'     => ['link' => 'url'],
        'icon-list' => ['icon_list' => ['repeater' => ['link' => 'url']]],
    ];
}

/**
 * Decide whether one registered control is a layout setting we expose.
 * Returns ['tag' => options, 'default' => tag] | 'url' | ['repeater' => [field => 'url']] | null.
 * Same content-tab and name filters as bulkseme_elementor_control_spec().
 */
function bulkseme_elementor_layout_control_spec($name, $ctl) {
    if (!is_string($name) || $name === '' || $name[0] === '_') {
        return null;
    }
    if (!is_array($ctl) || empty($ctl['type']) || !is_string($ctl['type'])) {
        return null;
    }
    if (preg_match('/(_css|_class(es)?|_id)$/', $name)) {
        return null;
    }
    if (isset($ctl['tab']) && $ctl['tab'] !== 'content') {
        return null;
    }
    $type = $ctl['type'];
    if ($type === 'url') {
        return 'url';
    }
    if ($type === 'select') {
        $tags = bulkseme_elementor_tag_options();
        $keys = (!empty($ctl['options']) && is_array($ctl['options'])) ? array_map('strval', array_keys($ctl['options'])) : [];
        if ($keys) {
            if (!array_diff($keys, $tags) && in_array('h1', $keys, true) && in_array('h6', $keys, true)) {
                $default = (isset($ctl['default']) && is_string($ctl['default']) && in_array($ctl['default'], $keys, true)) ? $ctl['default'] : $keys[0];
                return ['tag' => $keys, 'default' => $default];
            }
            return null;
        }
        // Elementor 4 serialises the heading-level SELECT controls without their
        // options list (heading `header_size`, icon-box/image-box `title_size`
        // come back as type + default only). Recognise them by name and by a
        // default that is itself a heading tag, and offer the full tag list.
        if (preg_match('/(^|_)(header_size|title_size|title_tag|html_tag|heading_tag|tag)$/', $name)
            && isset($ctl['default']) && is_string($ctl['default']) && in_array($ctl['default'], $tags, true)) {
            return ['tag' => $tags, 'default' => $ctl['default']];
        }
        return null;
    }
    if ($type === 'repeater' && !empty($ctl['fields']) && is_array($ctl['fields'])) {
        $fields = [];
        foreach ($ctl['fields'] as $fkey => $fctl) {
            if (!is_array($fctl) || empty($fctl['type']) || $fctl['type'] !== 'url') {
                continue;
            }
            $fname = isset($fctl['name']) && is_string($fctl['name']) ? $fctl['name'] : $fkey;
            if (!is_string($fname) || $fname === '' || $fname[0] === '_' || preg_match('/(_css|_class(es)?|_id)$/', $fname)) {
                continue;
            }
            $fields[$fname] = 'url';
        }
        if ($fields) {
            return ['repeater' => $fields];
        }
    }
    return null;
}

/** Layout settings for one widget type: live introspection when Elementor runs, static map otherwise. */
function bulkseme_elementor_layout_settings($widget_type) {
    static $cache = [];
    if (array_key_exists($widget_type, $cache)) {
        return $cache[$widget_type];
    }
    $map = null;
    if (bulkseme_elementor_active()) {
        try {
            $widget = \Elementor\Plugin::$instance->widgets_manager->get_widget_types($widget_type);
            if ($widget && method_exists($widget, 'get_controls')) {
                $map = [];
                $controls = $widget->get_controls();
                if (is_array($controls)) {
                    foreach ($controls as $name => $ctl) {
                        $spec = bulkseme_elementor_layout_control_spec($name, $ctl);
                        if ($spec !== null) {
                            $map[$name] = $spec;
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
            $map = null;
        }
    }
    if ($map === null) {
        $static = bulkseme_elementor_static_layout_map();
        $map = isset($static[$widget_type]) ? $static[$widget_type] : [];
    }
    $cache[$widget_type] = $map;
    return $map;
}

/**
 * URL for a link control. Empty clears the link. Relative paths, fragments,
 * mailto: and tel: survive esc_url_raw; anything it rejects is refused (null).
 */
function bulkseme_elementor_sanitize_url($value) {
    $value = trim((string) $value);
    if ($value === '') {
        return '';
    }
    if (strlen($value) > 2048 || preg_match('/[\x00-\x1F\x7F]/', $value)) {
        return null;
    }
    $clean = esc_url_raw($value);
    return $clean === '' ? null : $clean;
}

/**
 * Custom CSS for one element. Refuses (null) anything that could leave CSS
 * context or load remote code: angle brackets (Elementor may print the CSS
 * inline in a <style> block), backslash escapes (\3C is <), @import/@charset,
 * expression(), javascript:/vbscript:/behavior:/-moz-binding (also when a
 * comment is placed inside the word), and url() to anything other than a path
 * or an http(s) URL. 20000 character ceiling.
 */
function bulkseme_elementor_sanitize_css($value) {
    $value = (string) $value;
    if (strlen($value) > 20000 || strpos($value, "\0") !== false) {
        return null;
    }
    if (strpos($value, '<') !== false || strpos($value, '>') !== false || strpos($value, '\\') !== false) {
        return null;
    }
    // Keywords are looked for with comments taken out, so a comment cannot hide one.
    $scan = preg_replace('#/\*.*?(\*/|$)#s', '', $value);
    if (!is_string($scan) || preg_match('/expression\s*\(|javascript\s*:|vbscript\s*:|behavior\s*:|-moz-binding|@import|@charset/i', $scan)) {
        return null;
    }
    if (preg_match_all('/url\s*\(\s*([\'"]?)([^\'")]*)\1\s*\)/i', $value, $m)) {
        foreach ($m[2] as $u) {
            $u = trim($u);
            if ($u !== '' && !preg_match('#^(https?:)?//|^/|^\.\.?/#i', $u)) {
                return null;
            }
        }
    }
    return trim($value);
}

/** Write a heading-level control. $spec = ['tag' => options, 'default' => tag]. */
function bulkseme_elementor_apply_tag(array &$settings, $key, array $spec, $value) {
    $v = strtolower(trim((string) $value));
    if (!in_array($v, $spec['tag'], true)) {
        return ['status' => 'invalid_value', 'errors' => ['tag_not_allowed'], 'allowed' => $spec['tag']];
    }
    if (isset($settings[$key]) && !is_string($settings[$key])) {
        return ['status' => 'setting_not_editable', 'errors' => ['stored_value_not_string']];
    }
    $stored = isset($settings[$key]) ? (string) $settings[$key] : '';
    $prev   = $stored !== '' ? $stored : $spec['default'];
    if ($v === $prev) {
        return ['status' => 'unchanged', 'previous' => $prev];
    }
    $settings[$key] = $v;
    return ['status' => 'ok', 'previous' => $prev, 'new' => $v];
}

/** Write the `url` of a URL control stored as {url, is_external, nofollow, custom_attributes}. $settings may be a repeater row. */
function bulkseme_elementor_apply_url(array &$settings, $key, $value) {
    if (isset($settings[$key]) && !is_array($settings[$key])) {
        return ['status' => 'setting_not_editable', 'errors' => ['stored_value_not_url_control']];
    }
    $clean = bulkseme_elementor_sanitize_url($value);
    if ($clean === null) {
        return ['status' => 'invalid_value', 'errors' => ['url_rejected']];
    }
    $prev = isset($settings[$key]['url']) && is_string($settings[$key]['url']) ? $settings[$key]['url'] : '';
    if ($clean === $prev) {
        return ['status' => 'unchanged', 'previous' => $prev];
    }
    if (!isset($settings[$key]) || !is_array($settings[$key])) {
        $settings[$key] = ['url' => '', 'is_external' => '', 'nofollow' => '', 'custom_attributes' => ''];
    }
    $settings[$key]['url'] = $clean;
    return ['status' => 'ok', 'previous' => $prev, 'new' => $clean];
}

/** Write an element's Custom CSS (any elType). Empty removes it. */
function bulkseme_elementor_apply_custom_css(array &$node, $value) {
    if (!current_user_can('unfiltered_html')) {
        return ['status' => 'setting_not_editable', 'errors' => ['custom_css_requires_unfiltered_html']];
    }
    $clean = bulkseme_elementor_sanitize_css($value);
    if ($clean === null) {
        return [
            'status' => 'invalid_value', 'errors' => ['css_rejected'],
            'hint'   => 'no < > or backslash, no @import, expression(), javascript:, behavior:, url() only to a path or http(s) URL, max 20000 chars',
        ];
    }
    if (!isset($node['settings']) || !is_array($node['settings'])) {
        $node['settings'] = [];
    }
    $prev = isset($node['settings']['custom_css']) && is_string($node['settings']['custom_css']) ? $node['settings']['custom_css'] : '';
    $res  = ['previous' => $prev];
    if (!defined('ELEMENTOR_PRO_VERSION')) {
        $res['hint'] = 'custom_css is stored but only Elementor Pro renders it';
    }
    if ($clean === $prev) {
        return ['status' => 'unchanged'] + $res;
    }
    if ($clean === '') {
        unset($node['settings']['custom_css']);
    } else {
        $node['settings']['custom_css'] = $clean;
    }
    return ['status' => 'ok', 'new' => $clean] + $res;
}

// ---- Elementor 4 atomic widgets ------------------------------------------------
// e-heading, e-paragraph, e-button and the e-component instances that wrap them
// register no classic controls, so get_controls() introspection sees nothing.
// Their copy lives in typed props:
//   { "$$type": "html-v3", "value": { "content": { "$$type": "string", "value": "<html>" }, "children": [...] } }
// Every html-v3 prop is visible text by definition (the heading level lives in
// a separate `tag` string prop and styling in `styles`, neither of which we
// touch), so all of them are exposed. Component instances carry per-page
// overrides of the same shape, addressed as
// `component_instance[<override_key>].override_value`. The `children` list holds
// inline styled spans keyed by the id on tags like <strong id="e-...">; keep
// those ids in a rewritten value so the inline styling survives.

/** Text of an atomic typed prop, or null when the prop is not text. */
function bulkseme_elementor_atomic_text_get($prop) {
    if (!is_array($prop) || !isset($prop['$$type'])) {
        return null;
    }
    if ($prop['$$type'] === 'html-v3') {
        return isset($prop['value']['content']['value']) && is_string($prop['value']['content']['value'])
            ? $prop['value']['content']['value'] : null;
    }
    if ($prop['$$type'] === 'html') {
        return isset($prop['value']) && is_string($prop['value']) ? $prop['value'] : null;
    }
    return null;
}

/** Ids of inline styled children on an html-v3 prop (empty for anything else). */
function bulkseme_elementor_atomic_inline_ids($prop) {
    $ids = [];
    if (is_array($prop) && isset($prop['$$type']) && $prop['$$type'] === 'html-v3'
        && isset($prop['value']['children']) && is_array($prop['value']['children'])) {
        foreach ($prop['value']['children'] as $child) {
            if (is_array($child) && isset($child['id']) && is_string($child['id'])) {
                $ids[] = $child['id'];
            }
        }
    }
    return $ids;
}

/** Overwrite the text of an atomic typed prop in place. */
function bulkseme_elementor_atomic_text_set(array &$prop, $text) {
    if ($prop['$$type'] === 'html-v3') {
        $prop['value']['content']['value'] = $text;
        return true;
    }
    if ($prop['$$type'] === 'html') {
        $prop['value'] = $text;
        return true;
    }
    return false;
}

/** The overrides list of an e-component instance, by reference, or null. */
function &bulkseme_elementor_component_overrides(array &$settings) {
    $null = null;
    if (isset($settings['component_instance']['value']['overrides']['value'])
        && is_array($settings['component_instance']['value']['overrides']['value'])) {
        return $settings['component_instance']['value']['overrides']['value'];
    }
    return $null;
}

/** Flatten the atomic text props (and component overrides) of one widget. */
function bulkseme_elementor_atomic_nodes($el_id, $wt, array $settings, $path, array $skip) {
    $nodes = [];
    foreach ($settings as $key => $prop) {
        if (!is_string($key) || isset($skip[$key]) || !preg_match('/^[A-Za-z0-9_-]+$/', $key)) {
            continue;
        }
        $txt = bulkseme_elementor_atomic_text_get($prop);
        if ($txt === null) {
            continue;
        }
        $nodes[] = [
            'el' => $el_id, 'widget' => $wt, 'setting' => $key,
            'text' => $txt, 'html' => true, 'dynamic' => false, 'editable' => true,
            'atomic' => true, 'inline_ids' => bulkseme_elementor_atomic_inline_ids($prop), 'path' => $path,
        ];
    }
    // Atomic e-heading level: `tag` is a typed string prop, writable as setting "tag".
    if (!isset($skip['tag']) && isset($settings['tag']['$$type']) && $settings['tag']['$$type'] === 'string'
        && isset($settings['tag']['value']) && is_string($settings['tag']['value'])
        && in_array(strtolower($settings['tag']['value']), bulkseme_elementor_tag_options(), true)) {
        $nodes[] = [
            'el' => $el_id, 'widget' => $wt, 'setting' => 'tag', 'kind' => 'tag',
            'text' => strtolower($settings['tag']['value']), 'default' => false, 'options' => bulkseme_elementor_tag_options(),
            'html' => false, 'dynamic' => false, 'editable' => true, 'atomic' => true, 'path' => $path,
        ];
    }
    $overrides = &bulkseme_elementor_component_overrides($settings);
    if (is_array($overrides)) {
        $component_id = isset($settings['component_instance']['value']['component_id']['value'])
            ? (int) $settings['component_instance']['value']['component_id']['value'] : null;
        foreach ($overrides as $idx => $ov) {
            if (!is_array($ov) || !isset($ov['value']['override_key']) || !isset($ov['value']['override_value'])) {
                continue;
            }
            $okey = (string) $ov['value']['override_key'];
            if ($okey === '' || !preg_match('/^[A-Za-z0-9_-]+$/', $okey)) {
                continue;
            }
            $txt = bulkseme_elementor_atomic_text_get($ov['value']['override_value']);
            if ($txt === null) {
                continue;
            }
            $nodes[] = [
                'el' => $el_id, 'widget' => $wt,
                'setting' => 'component_instance[' . $okey . '].override_value',
                'text' => $txt, 'html' => true, 'dynamic' => false, 'editable' => true,
                'atomic' => true, 'component_id' => $component_id, 'item_index' => $idx,
                'inline_ids' => bulkseme_elementor_atomic_inline_ids($ov['value']['override_value']), 'path' => $path,
            ];
        }
    }
    return $nodes;
}

/** Write one atomic prop through the wysiwyg sanitizer. */
function bulkseme_elementor_apply_atomic(array &$prop, $value) {
    $prev = bulkseme_elementor_atomic_text_get($prop);
    if ($prev === null) {
        return ['status' => 'setting_not_editable', 'errors' => ['atomic_prop_not_text']];
    }
    $clean = bulkseme_content_sanitize($value, 'wysiwyg');
    if ($clean === $prev) {
        return ['status' => 'unchanged', 'previous' => $prev];
    }
    bulkseme_elementor_atomic_text_set($prop, $clean);
    return ['status' => 'ok', 'previous' => $prev, 'new' => $clean, 'atomic' => true];
}

// ---- Tree walking ------------------------------------------------------------

/**
 * Flatten an Elementor element tree into addressable text nodes. Every node
 * carries the owning element's stable `id` plus a setting path - repeater
 * fields are addressed by the row's own `_id` (index-shift-proof), e.g.
 * `tabs[3f9a21c].tab_title`. Settings overridden by a dynamic tag are flagged
 * (`dynamic:true`): editing their static value would not change the render.
 */
function bulkseme_elementor_flatten(array $elements, $parent_path = '') {
    $nodes = [];
    foreach ($elements as $el) {
        if (!is_array($el) || empty($el['id'])) {
            continue;
        }
        $el_id   = (string) $el['id'];
        $el_type = isset($el['elType']) ? (string) $el['elType'] : '';
        $wt      = isset($el['widgetType']) ? (string) $el['widgetType'] : '';
        $label   = $el_type === 'widget' ? ($wt !== '' ? $wt : 'widget') : ($el_type !== '' ? $el_type : 'element');
        $path    = ($parent_path !== '' ? $parent_path . '>' : '') . $label . '(' . $el_id . ')';
        $settings = isset($el['settings']) && is_array($el['settings']) ? $el['settings'] : [];

        if ($el_type === 'widget' && $wt === 'global') {
            // Elementor Pro global widget: its settings live on the linked
            // elementor_library post. Surface a pointer, not an editable node.
            $nodes[] = [
                'el' => $el_id, 'widget' => 'global', 'setting' => null,
                'editable' => false, 'reason' => 'global_widget',
                'template_id' => isset($settings['templateID']) ? (int) $settings['templateID'] : null,
                'path' => $path,
            ];
        } elseif ($el_type === 'widget' && $wt !== '') {
            $map = bulkseme_elementor_editable_settings($wt);
            $dyn = isset($settings['__dynamic__']) && is_array($settings['__dynamic__']) ? $settings['__dynamic__'] : [];
            foreach ($map as $key => $spec) {
                if (is_array($spec)) { // repeater
                    $rows = isset($settings[$key]) && is_array($settings[$key]) ? $settings[$key] : [];
                    foreach ($rows as $idx => $row) {
                        if (!is_array($row)) {
                            continue;
                        }
                        $row_dyn = isset($row['__dynamic__']) && is_array($row['__dynamic__']) ? $row['__dynamic__'] : [];
                        $row_id  = isset($row['_id']) ? (string) $row['_id'] : '';
                        foreach ($spec['repeater'] as $fname => $ftype) {
                            $val = isset($row[$fname]) && is_string($row[$fname]) ? $row[$fname] : '';
                            $nodes[] = [
                                'el' => $el_id, 'widget' => $wt,
                                'setting' => $row_id !== ''
                                    ? $key . '[' . $row_id . '].' . $fname
                                    : $key . '.' . $idx . '.' . $fname,
                                'text' => $val, 'html' => $ftype === 'wysiwyg',
                                'dynamic' => isset($row_dyn[$fname]), 'editable' => true,
                                'item_index' => $idx, 'path' => $path,
                            ];
                        }
                    }
                } else {
                    $val = isset($settings[$key]) && is_string($settings[$key]) ? $settings[$key] : '';
                    $nodes[] = [
                        'el' => $el_id, 'widget' => $wt, 'setting' => $key,
                        'text' => $val, 'html' => $spec === 'wysiwyg',
                        'dynamic' => isset($dyn[$key]), 'editable' => true,
                        'path' => $path,
                    ];
                }
            }
            // Layout settings: heading level and link URLs, with their current values.
            foreach (bulkseme_elementor_layout_settings($wt) as $key => $spec) {
                if ($spec === 'url') {
                    $u = isset($settings[$key]['url']) && is_string($settings[$key]['url']) ? $settings[$key]['url'] : '';
                    $nodes[] = [
                        'el' => $el_id, 'widget' => $wt, 'setting' => $key . '.url', 'kind' => 'url',
                        'text' => $u, 'html' => false, 'dynamic' => isset($dyn[$key]), 'editable' => true, 'path' => $path,
                    ];
                } elseif (is_array($spec) && isset($spec['tag'])) {
                    $stored = isset($settings[$key]) && is_string($settings[$key]) ? $settings[$key] : '';
                    $nodes[] = [
                        'el' => $el_id, 'widget' => $wt, 'setting' => $key, 'kind' => 'tag',
                        'text' => $stored !== '' ? $stored : $spec['default'], 'default' => $stored === '',
                        'options' => $spec['tag'], 'html' => false, 'dynamic' => false, 'editable' => true, 'path' => $path,
                    ];
                } elseif (is_array($spec) && isset($spec['repeater'])) {
                    $rows = isset($settings[$key]) && is_array($settings[$key]) ? $settings[$key] : [];
                    foreach ($rows as $idx => $row) {
                        if (!is_array($row)) {
                            continue;
                        }
                        $row_id = isset($row['_id']) ? (string) $row['_id'] : '';
                        foreach ($spec['repeater'] as $fname => $ftype) {
                            $u = isset($row[$fname]['url']) && is_string($row[$fname]['url']) ? $row[$fname]['url'] : '';
                            $nodes[] = [
                                'el' => $el_id, 'widget' => $wt, 'kind' => 'url',
                                'setting' => ($row_id !== '' ? $key . '[' . $row_id . '].' : $key . '.' . $idx . '.') . $fname . '.url',
                                'text' => $u, 'html' => false, 'dynamic' => isset($row['__dynamic__'][$fname]),
                                'editable' => true, 'item_index' => $idx, 'path' => $path,
                            ];
                        }
                    }
                }
            }
            // Elementor 4 atomic props and component overrides (no classic controls).
            $nodes = array_merge($nodes, bulkseme_elementor_atomic_nodes($el_id, $wt, $settings, $path, $map));
        }
        if (!($el_type === 'widget' && $wt === 'global') && isset($settings['custom_css'])
            && is_string($settings['custom_css']) && trim($settings['custom_css']) !== '') {
            // Elementor Pro Custom CSS on any element; listed only when set, writable on every element.
            $nodes[] = [
                'el' => $el_id, 'widget' => $label, 'setting' => 'custom_css', 'kind' => 'css',
                'text' => $settings['custom_css'], 'html' => false, 'dynamic' => false, 'editable' => true, 'path' => $path,
            ];
        }
        if (!empty($el['elements']) && is_array($el['elements'])) {
            $nodes = array_merge($nodes, bulkseme_elementor_flatten($el['elements'], $path));
        }
    }
    return $nodes;
}

/**
 * Depth-first search for the element with the given id and hand a mutable
 * reference to $fn. Returns $fn's result, or null when the id is not found
 * ($fn must therefore never return null).
 */
function bulkseme_elementor_patch_node(array &$elements, $el_id, callable $fn) {
    foreach ($elements as &$el) {
        if (!is_array($el)) {
            continue;
        }
        if (isset($el['id']) && (string) $el['id'] === $el_id) {
            $res = $fn($el);
            unset($el);
            return $res;
        }
        if (isset($el['elements']) && is_array($el['elements'])) {
            $res = bulkseme_elementor_patch_node($el['elements'], $el_id, $fn);
            if ($res !== null) {
                unset($el);
                return $res;
            }
        }
    }
    unset($el);
    return null;
}

/**
 * Parse a setting address. Accepted forms:
 *   "title"                     plain setting
 *   "tabs[3f9a21c].tab_title"   repeater row by _id (preferred)
 *   "tabs.2.tab_title"          repeater row by index (fallback)
 */
function bulkseme_content_parse_setting($setting) {
    if (!is_string($setting) || $setting === '' || strlen($setting) > 200) {
        return null;
    }
    if (preg_match('/^([A-Za-z0-9_-]+)\[([A-Za-z0-9_-]+)\]\.([A-Za-z0-9_-]+)$/', $setting, $m)) {
        return ['repeater' => $m[1], 'row_id' => $m[2], 'field' => $m[3]];
    }
    if (preg_match('/^([A-Za-z0-9_-]+)\.(\d+)\.([A-Za-z0-9_-]+)$/', $setting, $m)) {
        return ['repeater' => $m[1], 'index' => (int) $m[2], 'field' => $m[3]];
    }
    if (preg_match('/^[A-Za-z0-9_-]+$/', $setting)) {
        return ['key' => $setting];
    }
    // URL controls: "link.url", "icon_list[3f9a21c].link.url", "icon_list.2.link.url".
    if (preg_match('/^([A-Za-z0-9_-]+)\.url$/', $setting, $m)) {
        return ['key' => $m[1], 'sub' => 'url'];
    }
    if (preg_match('/^([A-Za-z0-9_-]+)\[([A-Za-z0-9_-]+)\]\.([A-Za-z0-9_-]+)\.url$/', $setting, $m)) {
        return ['repeater' => $m[1], 'row_id' => $m[2], 'field' => $m[3], 'sub' => 'url'];
    }
    if (preg_match('/^([A-Za-z0-9_-]+)\.(\d+)\.([A-Za-z0-9_-]+)\.url$/', $setting, $m)) {
        return ['repeater' => $m[1], 'index' => (int) $m[2], 'field' => $m[3], 'sub' => 'url'];
    }
    return null;
}

/**
 * Apply one parsed update to one element node (mutating it). Returns a
 * result array with `status` of: ok | unchanged | setting_not_editable |
 * dynamic_skipped | row_not_found | invalid_value.
 */
function bulkseme_elementor_apply_to_node(&$node, $parsed, $value, $force) {
    // Custom CSS lives on every element type (containers, sections, columns, widgets).
    if (isset($parsed['key']) && $parsed['key'] === 'custom_css' && !isset($parsed['sub'])) {
        if (!is_array($node)) {
            return ['status' => 'setting_not_editable', 'errors' => ['element_not_array']];
        }
        return bulkseme_elementor_apply_custom_css($node, $value);
    }
    if (!isset($node['elType']) || $node['elType'] !== 'widget') {
        return ['status' => 'setting_not_editable', 'errors' => ['element_is_not_a_widget']];
    }
    $wt = isset($node['widgetType']) ? (string) $node['widgetType'] : '';
    if ($wt === '') {
        return ['status' => 'setting_not_editable', 'errors' => ['widget_type_missing']];
    }
    if ($wt === 'global') {
        $tid = isset($node['settings']['templateID']) ? (int) $node['settings']['templateID'] : null;
        return [
            'status' => 'setting_not_editable', 'errors' => ['global_widget'],
            'template_id' => $tid,
            'hint' => 'Global widget content lives on its elementor_library template post - edit that post id instead.',
        ];
    }
    $map = bulkseme_elementor_editable_settings($wt);
    if (!isset($node['settings']) || !is_array($node['settings'])) {
        $node['settings'] = [];
    }
    $settings = &$node['settings'];

    // Elementor 4 atomic e-heading level: `tag` is a typed string prop.
    if (isset($parsed['key']) && $parsed['key'] === 'tag' && !isset($parsed['sub']) && !isset($map['tag'])
        && isset($settings['tag']['$$type']) && $settings['tag']['$$type'] === 'string') {
        $v = strtolower(trim((string) $value));
        if (!in_array($v, bulkseme_elementor_tag_options(), true)) {
            return ['status' => 'invalid_value', 'errors' => ['tag_not_allowed'], 'allowed' => bulkseme_elementor_tag_options()];
        }
        $prev = isset($settings['tag']['value']) && is_string($settings['tag']['value']) ? $settings['tag']['value'] : '';
        if ($v === $prev) {
            return ['status' => 'unchanged', 'previous' => $prev];
        }
        $settings['tag']['value'] = $v;
        return ['status' => 'ok', 'previous' => $prev, 'new' => $v];
    }

    // Elementor 4 atomic props: a typed array where a classic control holds a
    // string. Handled before the control map, which cannot see these widgets.
    if (isset($parsed['key']) && !isset($map[$parsed['key']])
        && isset($settings[$parsed['key']]) && is_array($settings[$parsed['key']])
        && isset($settings[$parsed['key']]['$$type'])) {
        return bulkseme_elementor_apply_atomic($settings[$parsed['key']], $value);
    }
    if (isset($parsed['repeater']) && $parsed['repeater'] === 'component_instance'
        && $parsed['field'] === 'override_value' && isset($parsed['row_id'])) {
        $overrides = &bulkseme_elementor_component_overrides($settings);
        if (!is_array($overrides)) {
            return ['status' => 'row_not_found', 'errors' => ['override_not_found:' . $parsed['row_id']]];
        }
        foreach ($overrides as &$ov) {
            if (is_array($ov) && isset($ov['value']['override_key'])
                && (string) $ov['value']['override_key'] === $parsed['row_id']) {
                if (!isset($ov['value']['override_value']) || !is_array($ov['value']['override_value'])) {
                    unset($ov);
                    return ['status' => 'setting_not_editable', 'errors' => ['override_value_not_text']];
                }
                $res = bulkseme_elementor_apply_atomic($ov['value']['override_value'], $value);
                unset($ov);
                return $res;
            }
        }
        unset($ov);
        return ['status' => 'row_not_found', 'errors' => ['override_not_found:' . $parsed['row_id']]];
    }

    // Layout settings: heading level and link URLs.
    $lmap = bulkseme_elementor_layout_settings($wt);
    if (isset($parsed['sub'])) { // "<control>.url"
        if (isset($parsed['key'])) {
            $key = $parsed['key'];
            if (!isset($lmap[$key]) || $lmap[$key] !== 'url') {
                return ['status' => 'setting_not_editable', 'errors' => ['setting_not_editable:' . $key . '.url']];
            }
            if (!$force && isset($settings['__dynamic__'][$key])) {
                return [
                    'status' => 'dynamic_skipped',
                    'hint'   => 'This link is overridden by a dynamic tag; the static URL is ignored at render. Pass force=1 to write it anyway.',
                ];
            }
            return bulkseme_elementor_apply_url($settings, $key, $value);
        }
        $rep   = $parsed['repeater'];
        $field = $parsed['field'];
        if (!isset($lmap[$rep]['repeater'][$field]) || $lmap[$rep]['repeater'][$field] !== 'url') {
            return ['status' => 'setting_not_editable', 'errors' => ['setting_not_editable:' . $rep . '.' . $field . '.url']];
        }
        if (!isset($settings[$rep]) || !is_array($settings[$rep])) {
            return ['status' => 'row_not_found', 'errors' => ['repeater_empty:' . $rep]];
        }
        $rows = &$settings[$rep];
        $hit  = null;
        if (isset($parsed['row_id'])) {
            foreach ($rows as $i => $row) {
                if (is_array($row) && isset($row['_id']) && (string) $row['_id'] === $parsed['row_id']) {
                    $hit = $i;
                    break;
                }
            }
        } elseif (isset($rows[$parsed['index']]) && is_array($rows[$parsed['index']])) {
            $hit = $parsed['index'];
        }
        if ($hit === null) {
            return ['status' => 'row_not_found', 'errors' => ['row_not_found:' . $rep]];
        }
        if (!$force && isset($rows[$hit]['__dynamic__'][$field])) {
            return ['status' => 'dynamic_skipped', 'hint' => 'This repeater link is overridden by a dynamic tag. Pass force=1 to write the static URL anyway.'];
        }
        return bulkseme_elementor_apply_url($rows[$hit], $field, $value);
    }
    if (isset($parsed['key']) && isset($lmap[$parsed['key']]) && is_array($lmap[$parsed['key']]) && isset($lmap[$parsed['key']]['tag'])) {
        return bulkseme_elementor_apply_tag($settings, $parsed['key'], $lmap[$parsed['key']], $value);
    }

    if (isset($parsed['key'])) {
        $key = $parsed['key'];
        if (!isset($map[$key]) || is_array($map[$key])) {
            return ['status' => 'setting_not_editable', 'errors' => ['setting_not_editable:' . $key]];
        }
        if (!$force && isset($settings['__dynamic__'][$key])) {
            return [
                'status' => 'dynamic_skipped',
                'hint'   => 'This setting is overridden by a dynamic tag; the static value is ignored at render. Pass force=1 to write it anyway.',
            ];
        }
        if (isset($settings[$key]) && !is_string($settings[$key])) {
            return ['status' => 'setting_not_editable', 'errors' => ['stored_value_not_string']];
        }
        $prev  = isset($settings[$key]) ? (string) $settings[$key] : '';
        $clean = bulkseme_content_sanitize($value, $map[$key]);
        if ($clean === $prev) {
            return ['status' => 'unchanged', 'previous' => $prev];
        }
        $settings[$key] = $clean;
        return ['status' => 'ok', 'previous' => $prev, 'new' => $clean];
    }

    // Repeater field.
    $rep   = $parsed['repeater'];
    $field = $parsed['field'];
    if (!isset($map[$rep]) || !is_array($map[$rep]) || !isset($map[$rep]['repeater'][$field])) {
        return ['status' => 'setting_not_editable', 'errors' => ['setting_not_editable:' . $rep . '.' . $field]];
    }
    if (!isset($settings[$rep]) || !is_array($settings[$rep])) {
        return ['status' => 'row_not_found', 'errors' => ['repeater_empty:' . $rep]];
    }
    $rows = &$settings[$rep];
    $found = false;
    if (isset($parsed['row_id'])) {
        foreach ($rows as $i => &$row) {
            if (is_array($row) && isset($row['_id']) && (string) $row['_id'] === $parsed['row_id']) {
                $target = &$row;
                $found  = true;
                break;
            }
        }
        unset($row);
    } else {
        $idx = $parsed['index'];
        if (isset($rows[$idx]) && is_array($rows[$idx])) {
            $target = &$rows[$idx];
            $found  = true;
        }
    }
    if (!$found) {
        return ['status' => 'row_not_found', 'errors' => ['row_not_found:' . $rep]];
    }
    if (!$force && isset($target['__dynamic__'][$field])) {
        return [
            'status' => 'dynamic_skipped',
            'hint'   => 'This repeater field is overridden by a dynamic tag. Pass force=1 to write the static value anyway.',
        ];
    }
    if (isset($target[$field]) && !is_string($target[$field])) {
        return ['status' => 'setting_not_editable', 'errors' => ['stored_value_not_string']];
    }
    $prev  = isset($target[$field]) ? (string) $target[$field] : '';
    $clean = bulkseme_content_sanitize($value, $map[$rep]['repeater'][$field]);
    if ($clean === $prev) {
        return ['status' => 'unchanged', 'previous' => $prev];
    }
    $target[$field] = $clean;
    return ['status' => 'ok', 'previous' => $prev, 'new' => $clean];
}

/**
 * Validate and apply a batch of updates against a decoded tree. Returns
 * [results[], changed_count]. All updates mutate the SAME tree, so one
 * request = one backup + one meta write (atomic per request).
 */
function bulkseme_content_apply_updates(array &$tree, array $updates, $force) {
    $results = [];
    $changed = 0;
    foreach ($updates as $u) {
        $el      = isset($u['el']) && is_scalar($u['el']) ? (string) $u['el'] : '';
        $setting = isset($u['setting']) && is_scalar($u['setting']) ? (string) $u['setting'] : '';
        $base    = ['el' => $el, 'setting' => $setting];
        if ($el === '' || !preg_match('/^[A-Za-z0-9_-]{1,64}$/', $el)) {
            $results[] = $base + ['status' => 'invalid_value', 'errors' => ['missing_or_invalid_el']];
            continue;
        }
        $parsed = bulkseme_content_parse_setting($setting);
        if ($parsed === null) {
            $results[] = $base + ['status' => 'invalid_value', 'errors' => ['invalid_setting_path']];
            continue;
        }
        if (!isset($u['value']) || !is_string($u['value'])) {
            $results[] = $base + ['status' => 'invalid_value', 'errors' => ['value_must_be_string']];
            continue;
        }
        $value = $u['value'];
        $res = bulkseme_elementor_patch_node($tree, $el, function (&$node) use ($parsed, $value, $force) {
            return bulkseme_elementor_apply_to_node($node, $parsed, $value, $force);
        });
        if ($res === null) {
            $results[] = $base + ['status' => 'element_not_found'];
            continue;
        }
        if ($res['status'] === 'ok') {
            $changed++;
        }
        $results[] = $base + $res;
    }
    return [$results, $changed];
}

// ---- Write pipeline -------------------------------------------------------------

/** Elementor editor lock holder's display name, or null. */
function bulkseme_elementor_locked_by($post_id) {
    if (!bulkseme_elementor_active()) {
        return null;
    }
    try {
        $user = \Elementor\Plugin::$instance->editor->get_locked_user($post_id);
        return ($user && isset($user->display_name)) ? (string) $user->display_name : null;
    } catch (\Throwable $e) {
        return null;
    }
}

/**
 * Drop the post's generated CSS and rendered-HTML cache so the next front-end
 * view rebuilds them from the new data, as Document::save() does. With
 * Elementor switched off only its meta flags are cleared: it rebuilds the CSS
 * file itself the next time it runs.
 */
function bulkseme_elementor_clear_generated($post_id) {
    $ok = true;
    try {
        if (bulkseme_elementor_active() && class_exists('\\Elementor\\Core\\Files\\CSS\\Post')) {
            \Elementor\Core\Files\CSS\Post::create($post_id)->delete();
        } else {
            delete_post_meta($post_id, '_elementor_css');
        }
    } catch (\Throwable $e) {
        delete_post_meta($post_id, '_elementor_css');
        $ok = false;
    }
    delete_post_meta($post_id, '_elementor_element_cache');
    return $ok;
}

/**
 * Persist a patched tree (already wp_json_encode'd) the way Elementor does,
 * then replicate Document::save()'s side effects. save_plain_text() re-syncs
 * post_content, creates the WP revision, and fires save_post /
 * clean_post_cache (which is what makes page caches purge the URL); when it
 * is unavailable we still touch the post so caches invalidate.
 */
function bulkseme_elementor_write_data($post_id, $json) {
    update_metadata('post', $post_id, '_elementor_data', wp_slash($json));
    $synced = false;
    if (bulkseme_elementor_active()) {
        try {
            \Elementor\Plugin::$instance->db->save_plain_text($post_id);
            $synced = true;
        } catch (\Throwable $e) {
            // get_elements_raw_data() can throw on malformed data; the meta
            // write above already succeeded, so fall through to the touch.
        }
    }
    if (!$synced) {
        bulkseme_content_touch($post_id);
    }
    // Clear generated CSS/render caches LAST: the render inside
    // save_plain_text() repopulates _elementor_element_cache, so clearing
    // first leaves a stale cache behind (Document::save() clears at the end
    // for the same reason).
    $css_cleared = bulkseme_elementor_clear_generated($post_id);
    return ['css_cache_cleared' => (bool) $css_cleared, 'post_content_synced' => $synced];
}
