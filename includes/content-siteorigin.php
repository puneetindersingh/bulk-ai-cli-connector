<?php
/**
 * Page content stored by SiteOrigin Page Builder, and by the Accordions plugin.
 *
 * SiteOrigin Page Builder keeps the real layout in the panels_data post meta
 * (rows, cells and widget instances). post_content only holds a rendered
 * mirror of it when the builder's "copy content" setting is on, so a
 * post_content write changes nothing on the page. These handlers read the
 * widget instances, list the fields that hold copy as nodes (el = w<index>,
 * nested layout widgets as w<index>.w<index>) and write them back with the
 * same backup, dry-run and hash rules as the other builders, then refresh the
 * mirror the way the editor's own save does.
 *
 * The Accordions plugin (post type `accordions`) stores every FAQ item in
 * accordions_options['content'] on its own post; pages embed it through its
 * widget or shortcode. A page GET reports those links, and the accordion post
 * is edited by its own id with el = item key and setting header or body.
 */

if (!defined('ABSPATH')) exit;

function bulkseme_siteorigin_active() {
    return class_exists('SiteOrigin_Panels') || defined('SITEORIGIN_PANELS_VERSION');
}

function bulkseme_accordions_active() {
    return class_exists('WidgetAccordions') || post_type_exists('accordions');
}

/**
 * Copy-bearing fields per widget class: setting path => text|html. A `[]`
 * segment stands for every row of a list field and expands to the rows that
 * exist on the instance. Unknown widget classes fall back to a small allowlist
 * of copy-looking keys walked over the instance. Filter
 * `bulkseme_siteorigin_field_map` extends the map.
 */
function bulkseme_so_field_map() {
    static $map = null;
    if ($map === null) {
        $map = [
            'WP_Widget_Black_Studio_TinyMCE'        => ['title' => 'text', 'text' => 'html'],
            'WP_Widget_Text'                        => ['title' => 'text', 'text' => 'html'],
            'WP_Widget_Custom_HTML'                 => ['title' => 'text', 'content' => 'html'],
            'SiteOrigin_Widget_Editor_Widget'       => ['title' => 'text', 'text' => 'html'],
            'SiteOrigin_Widget_Headline_Widget'     => ['headline.text' => 'text', 'sub_headline.text' => 'text'],
            'SiteOrigin_Widget_Button_Widget'       => ['text' => 'text'],
            'SiteOrigin_Widget_Cta_Widget'          => ['title' => 'text', 'sub_title' => 'text'],
            'SiteOrigin_Widget_Accordion_Widget'    => ['title' => 'text', 'panels[].title' => 'text', 'panels[].content_text' => 'html'],
            'SiteOrigin_Widget_Hero_Widget'         => ['frames[].content' => 'html'],
            'SiteOrigin_Widget_Features_Widget'     => ['features[].title' => 'text', 'features[].text' => 'html', 'features[].more_text' => 'text'],
            'SiteOrigin_Widgets_Testimonials_Widget' => ['title' => 'text', 'testimonials[].text' => 'html', 'testimonials[].name' => 'text', 'testimonials[].location' => 'text'],
            'SiteOrigin_Widget_Tabs_Widget'         => ['title' => 'text', 'tabs[].title' => 'text', 'tabs[].content_text' => 'html'],
            'SiteOrigin_Widget_Image_Widget'        => ['title' => 'text', 'alt' => 'text'],
            'SiteOrigin_Widget_Icon_Widget'         => [],
            'SiteOrigin_Widget_Slider_Widget'       => [],
            'SiteOrigin_Widget_GoogleMap_Widget'    => [],
            'SiteOrigin_Widgets_ContactForm_Widget' => ['title' => 'text'],
            'SiteOrigin_Widget_PostCarousel_Widget' => [],
            'WP_Widget_Media_Image'                 => [],
            'WP_Widget_Media_Video'                 => [],
            'WidgetAccordions'                      => ['title' => 'text'],
            'SiteOrigin_Panels_Widgets_Layout'      => [],
            'SiteOrigin_Panels_Widgets_PostContent' => [],
            'SiteOrigin_Panels_Widgets_PostLoop'    => [],
        ];
        $map = apply_filters('bulkseme_siteorigin_field_map', $map);
    }
    return $map;
}

/** Keys treated as copy on widget classes the map does not know. */
function bulkseme_so_generic_keys() {
    return ['title', 'text', 'content', 'html', 'headline', 'sub_title', 'subtitle', 'sub_headline', 'description', 'caption', 'label', 'button_text', 'body', 'heading'];
}

/** 'panels[2].title' => ['panels', 2, 'title']; null when the syntax is off. */
function bulkseme_so_path_parse($setting) {
    if (!is_string($setting) || $setting === '' || strlen($setting) > 200) {
        return null;
    }
    if (!preg_match('/^[A-Za-z0-9_-]+(?:\[\d+\]|\.[A-Za-z0-9_-]+)*$/', $setting)) {
        return null;
    }
    $segs = [];
    foreach (preg_split('/(\[\d+\]|\.)/', $setting, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) as $tok) {
        if ($tok === '.') {
            continue;
        }
        if (preg_match('/^\[(\d+)\]$/', $tok, $m)) {
            $segs[] = (int) $m[1];
        } else {
            $segs[] = $tok;
        }
    }
    return $segs ?: null;
}

function bulkseme_so_path_get(array $arr, array $segs) {
    $cur = $arr;
    foreach ($segs as $s) {
        if (!is_array($cur) || !array_key_exists($s, $cur)) {
            return null;
        }
        $cur = $cur[$s];
    }
    return $cur;
}

function bulkseme_so_path_set(array &$arr, array $segs, $value) {
    $cur = &$arr;
    $last = array_pop($segs);
    foreach ($segs as $s) {
        // $cur is always an array here: it starts as one and only ever steps into one.
        if (!array_key_exists($s, $cur) || !is_array($cur[$s])) {
            return false;
        }
        $cur = &$cur[$s];
    }
    if (!array_key_exists($last, $cur)) {
        return false;
    }
    $cur[$last] = $value;
    return true;
}

function bulkseme_so_widget_class(array $w) {
    $info = isset($w['panels_info']) && is_array($w['panels_info']) ? $w['panels_info'] : (isset($w['info']) && is_array($w['info']) ? $w['info'] : []);
    return isset($info['class']) ? (string) $info['class'] : '';
}

/** Concrete editable settings of one widget instance: setting path => type. */
function bulkseme_so_widget_fields(array $w, $class) {
    $map = bulkseme_so_field_map();
    $out = [];
    if (isset($map[$class])) {
        foreach ($map[$class] as $pattern => $type) {
            if (strpos($pattern, '[]') === false) {
                $segs = bulkseme_so_path_parse($pattern);
                if ($segs !== null && is_string(bulkseme_so_path_get($w, $segs))) {
                    $out[$pattern] = $type;
                }
                continue;
            }
            list($list, $field) = explode('[].', $pattern, 2);
            $rows = isset($w[$list]) && is_array($w[$list]) ? $w[$list] : [];
            foreach ($rows as $i => $row) {
                if (!is_int($i) || !is_array($row)) {
                    continue;
                }
                $setting = $list . '[' . $i . '].' . $field;
                $segs    = bulkseme_so_path_parse($setting);
                if ($segs !== null && is_string(bulkseme_so_path_get($w, $segs))) {
                    $out[$setting] = $type;
                }
            }
        }
        return $out;
    }
    // Unknown class: copy-looking string keys, two levels deep. A value that holds
    // markup is HTML, but where this widget prints it is not known, so it gets the
    // guarded kind: see bulkseme_content_guard_quotes().
    $keys = bulkseme_so_generic_keys();
    foreach ($w as $k => $v) {
        if ($k === 'panels_info' || $k === 'info' || !is_string($k) || !preg_match('/^[A-Za-z0-9_-]+$/', $k)) {
            continue;
        }
        if (is_string($v) && in_array($k, $keys, true)) {
            $out[$k] = (strpos($v, '<') !== false) ? 'html_guarded' : 'text';
        } elseif (is_array($v)) {
            foreach ($v as $k2 => $v2) {
                if (is_string($k2) && preg_match('/^[A-Za-z0-9_-]+$/', $k2) && is_string($v2) && in_array($k2, $keys, true)) {
                    $out[$k . '.' . $k2] = (strpos($v2, '<') !== false) ? 'html_guarded' : 'text';
                }
            }
        }
    }
    return $out;
}

/** Flatten a panels_data tree into [nodes, linked]. */
function bulkseme_so_flatten(array $panels_data, $prefix = '', $depth = 0) {
    $nodes   = [];
    $linked  = [];
    $widgets = isset($panels_data['widgets']) && is_array($panels_data['widgets']) ? $panels_data['widgets'] : [];
    foreach ($widgets as $i => $w) {
        if (!is_int($i) || !is_array($w)) {
            continue;
        }
        $class = bulkseme_so_widget_class($w);
        $el    = $prefix . 'w' . $i;
        $info  = isset($w['panels_info']) && is_array($w['panels_info']) ? $w['panels_info'] : [];
        $path  = 'grid' . (isset($info['grid']) ? (int) $info['grid'] : 0) . '.cell' . (isset($info['cell']) ? (int) $info['cell'] : 0);
        if ($class === 'SiteOrigin_Panels_Widgets_Layout' && $depth < 5) {
            $nested = isset($w['panels_data']) ? $w['panels_data'] : null;
            if (is_string($nested)) {
                $nested = json_decode($nested, true);
            }
            if (is_array($nested)) {
                list($n2, $l2) = bulkseme_so_flatten($nested, $el . '.', $depth + 1);
                $nodes  = array_merge($nodes, $n2);
                $linked = array_merge($linked, $l2);
            }
            continue;
        }
        if ($class === 'WidgetAccordions' && !empty($w['accordion_id'])) {
            $linked[] = ['el' => $el, 'widget' => $class, 'kind' => 'accordions', 'id' => (int) $w['accordion_id'], 'via' => 'widget'];
        }
        foreach (bulkseme_so_widget_fields($w, $class) as $setting => $type) {
            $val = bulkseme_so_path_get($w, bulkseme_so_path_parse($setting));
            if (!is_string($val)) {
                continue;
            }
            // "html_guarded" is how a value is cleaned on the way in; a reader sees plain "html".
            $type    = $type === 'html_guarded' ? 'html' : $type;
            $nodes[] = [
                'el' => $el, 'widget' => $class, 'setting' => $setting, 'type' => $type,
                'text' => $val, 'html' => ($type === 'html'), 'editable' => true, 'path' => $path,
            ];
            if ($type === 'html' && preg_match_all('/\[accordions(?:_tabs)?[^\]]*\bid=["\']?(\d+)/', $val, $m)) {
                foreach ($m[1] as $aid) {
                    $linked[] = ['el' => $el, 'widget' => $class, 'kind' => 'accordions', 'id' => (int) $aid, 'via' => 'shortcode'];
                }
            }
        }
    }
    return [$nodes, $linked];
}

/** Locate a widget instance by el ('w3' or 'w3.w0'); returns a reference or null. */
function &bulkseme_so_locate(array &$panels_data, $el) {
    $null = null;
    if (!preg_match('/^w\d+(?:\.w\d+)*$/', $el)) {
        return $null;
    }
    $cur   = &$panels_data;
    $parts = explode('.', $el);
    $n     = count($parts);
    foreach ($parts as $k => $part) {
        $idx = (int) substr($part, 1);
        if (!isset($cur['widgets']) || !is_array($cur['widgets']) || !isset($cur['widgets'][$idx]) || !is_array($cur['widgets'][$idx])) {
            return $null;
        }
        $w = &$cur['widgets'][$idx];
        if ($k === $n - 1) {
            return $w;
        }
        if (bulkseme_so_widget_class($w) !== 'SiteOrigin_Panels_Widgets_Layout') {
            return $null;
        }
        if (is_string($w['panels_data'] ?? null)) {
            $decoded = json_decode($w['panels_data'], true);
            if (!is_array($decoded)) {
                return $null;
            }
            // A nested layout can be stored as a JSON string. It is opened here to
            // reach the widget inside and closed again before anything is stored
            // or hashed (bulkseme_so_close_nested), so it keeps its stored form.
            $w['__bulkseme_nested_json'] = $w['panels_data'];
            $w['panels_data']            = $decoded;
        }
        if (!isset($w['panels_data']) || !is_array($w['panels_data'])) {
            return $null;
        }
        $cur = &$w['panels_data'];
    }
    return $null;
}

function bulkseme_so_clean($value, $type, $unsafe_html) {
    if ($type === 'html' || $type === 'html_guarded') {
        $value = bulkseme_content_utf8($value);
        if (bulkseme_content_raw_html_allowed($unsafe_html)) {
            return $value;
        }
        $value = wp_kses_post($value);
        return $type === 'html_guarded' ? bulkseme_content_guard_quotes($value) : $value;
    }
    return bulkseme_content_sanitize($value, 'text');
}

/** Apply updates[] to a panels_data tree (mutating). Returns [results, changed]. */
function bulkseme_so_apply_updates(array &$panels_data, array $updates, $unsafe_html) {
    $results = [];
    $changed = 0;
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
        $w = &bulkseme_so_locate($panels_data, $el);
        if ($w === null) {
            $res['status'] = 'el_not_found';
            $results[] = $res;
            continue;
        }
        $fields = bulkseme_so_widget_fields($w, bulkseme_so_widget_class($w));
        if (!isset($fields[$setting])) {
            $res['status'] = 'setting_not_editable';
            $results[] = $res;
            unset($w);
            continue;
        }
        $prev  = (string) bulkseme_so_path_get($w, $segs);
        $clean = bulkseme_so_clean($u['value'], $fields[$setting], $unsafe_html);
        if ($clean === $prev) {
            $res['status'] = 'unchanged';
        } else {
            bulkseme_so_path_set($w, $segs, $clean);
            $changed++;
            $res['status']   = 'ok';
            $res['previous'] = $prev;
            $res['new']      = $clean;
        }
        $results[] = $res;
        unset($w);
    }
    return [$results, $changed];
}

/**
 * Put nested layouts that bulkseme_so_locate() opened back into the JSON string
 * form they were stored in. One that was opened but not changed gets its
 * original string back, byte for byte.
 */
function bulkseme_so_close_nested(array $panels_data, $depth = 0) {
    if ($depth > 6 || empty($panels_data['widgets']) || !is_array($panels_data['widgets'])) {
        return $panels_data;
    }
    foreach ($panels_data['widgets'] as $i => $w) {
        if (!is_array($w) || !isset($w['panels_data']) || !is_array($w['panels_data'])) {
            continue;
        }
        $w['panels_data'] = bulkseme_so_close_nested($w['panels_data'], $depth + 1);
        if (isset($w['__bulkseme_nested_json']) && is_string($w['__bulkseme_nested_json'])) {
            $original = $w['__bulkseme_nested_json'];
            unset($w['__bulkseme_nested_json']);
            $now              = wp_json_encode($w['panels_data']);
            $same             = is_string($now) && $now === wp_json_encode(json_decode($original, true));
            $w['panels_data'] = ($same || !is_string($now)) ? $original : $now;
        }
        $panels_data['widgets'][$i] = $w;
    }
    return $panels_data;
}

function bulkseme_so_encode(array $panels_data) {
    return wp_json_encode(bulkseme_so_close_nested($panels_data));
}

/**
 * Persist panels_data and refresh the post_content mirror the way the editor
 * save does (SiteOrigin_Panels_Admin::copy_content_to_post, gated on the
 * "copy content" setting; older builder versions render through the renderer).
 */
function bulkseme_so_write($post_id, array $panels_data) {
    $panels_data = bulkseme_so_close_nested($panels_data);
    update_metadata('post', $post_id, 'panels_data', wp_slash($panels_data));
    $side = ['post_content_synced' => false, 'copy_content' => false];
    if (bulkseme_siteorigin_active() && function_exists('siteorigin_panels_setting') && siteorigin_panels_setting('copy-content')) {
        $side['copy_content'] = true;
        try {
            if (class_exists('SiteOrigin_Panels_Admin') && is_callable(['SiteOrigin_Panels_Admin', 'single']) && is_callable([SiteOrigin_Panels_Admin::single(), 'copy_content_to_post'])) {
                SiteOrigin_Panels_Admin::single()->copy_content_to_post(get_post($post_id), $post_id, $panels_data);
                $side['post_content_synced'] = true;
            } elseif (class_exists('SiteOrigin_Panels') && is_callable(['SiteOrigin_Panels', 'renderer'])) {
                $html = SiteOrigin_Panels::renderer()->render($post_id, false, $panels_data);
                if (is_string($html)) {
                    wp_update_post(['ID' => $post_id, 'post_content' => wp_slash($html)]);
                    $side['post_content_synced'] = true;
                }
            }
        } catch (\Throwable $e) {
            // meta write already succeeded; fall through to the touch
        }
    }
    if (!$side['post_content_synced']) {
        bulkseme_content_touch($post_id); // bump modified so caches and SEO indexables refresh
    }
    return $side;
}

function bulkseme_so_commit($post_id, $prev_json, array $panels_data, array $results, $changed, $dry_run, $note = 'pre-update') {
    $out = [
        'id' => $post_id, 'builder' => 'siteorigin', 'dry_run' => (bool) $dry_run,
        'count' => count($results), 'changed' => $changed, 'results' => $results,
    ];
    if ($dry_run || $changed === 0) {
        $out['data_hash'] = md5($prev_json);
        return $out;
    }
    $json = bulkseme_so_encode($panels_data);
    if ($json === false) {
        return new WP_Error('encode_failed', 'could not re-encode panels_data; nothing was written', ['status' => 500]);
    }
    $out['backup_ts'] = bulkseme_content_take_backup($post_id, 'siteorigin', $prev_json, $note);
    if (!$out['backup_ts']) {
        return new WP_Error('backup_failed', 'could not store a backup of the current content; nothing was written', ['status' => 500]);
    }
    $out = array_merge($out, bulkseme_so_write($post_id, $panels_data));
    $out['data_hash'] = md5($json);
    do_action('bulkseme_content_updated', $post_id, $results, get_current_user_id());
    return $out;
}

/** Load + validate panels_data for a post; WP_Error when missing. */
function bulkseme_so_load($post_id) {
    $pd = get_post_meta($post_id, 'panels_data', true);
    if (is_string($pd) && $pd !== '') {
        $pd = json_decode($pd, true);
    }
    if (!is_array($pd) || (empty($pd['widgets']) && empty($pd['grids']))) {
        return new WP_Error('no_panels_data', 'post has no panels_data', ['status' => 409]);
    }
    return $pd;
}

// ---- Accordions posts -------------------------------------------------------------

function bulkseme_acc_load($post_id) {
    $opts = get_post_meta($post_id, 'accordions_options', true);
    if (!is_array($opts)) {
        return new WP_Error('no_accordions_options', 'post has no accordions_options (old-format accordion, edit it in wp-admin once to migrate)', ['status' => 409]);
    }
    return $opts;
}

function bulkseme_acc_encode(array $opts) {
    return wp_json_encode($opts);
}

function bulkseme_acc_flatten(array $opts) {
    $nodes = [];
    $items = isset($opts['content']) && is_array($opts['content']) ? $opts['content'] : [];
    $pos   = 0;
    foreach ($items as $key => $item) {
        if (!is_array($item)) {
            continue;
        }
        $el = (string) $key;
        foreach (['header' => 'text', 'body' => 'html'] as $field => $type) {
            if (isset($item[$field]) && is_string($item[$field])) {
                $nodes[] = ['el' => $el, 'widget' => 'accordion_item', 'setting' => $field, 'type' => $type, 'text' => $item[$field], 'html' => ($type === 'html'), 'editable' => true, 'path' => 'item' . $pos];
            }
        }
        $pos++;
    }
    return $nodes;
}

function bulkseme_acc_apply_updates(array &$opts, array $updates, $unsafe_html) {
    $results = [];
    $changed = 0;
    foreach ($updates as $u) {
        $el      = is_array($u) && isset($u['el']) && (is_string($u['el']) || is_int($u['el'])) ? (string) $u['el'] : '';
        $setting = is_array($u) && isset($u['setting']) && is_string($u['setting']) ? $u['setting'] : '';
        $res     = ['el' => $el, 'setting' => $setting];
        if (!in_array($setting, ['header', 'body'], true)) {
            $res['status'] = 'setting_not_editable';
            $results[] = $res;
            continue;
        }
        if (!is_array($u) || !array_key_exists('value', $u) || !(is_string($u['value']) || is_numeric($u['value']))) {
            $res['status'] = 'invalid_value';
            $results[] = $res;
            continue;
        }
        if ($el === '' || !isset($opts['content']) || !is_array($opts['content']) || !array_key_exists($el, $opts['content']) || !is_array($opts['content'][$el])) {
            $res['status'] = 'el_not_found';
            $results[] = $res;
            continue;
        }
        $type  = $setting === 'body' ? 'html' : 'text';
        $prev  = isset($opts['content'][$el][$setting]) ? (string) $opts['content'][$el][$setting] : '';
        $clean = bulkseme_so_clean($u['value'], $type, $unsafe_html);
        if ($clean === $prev) {
            $res['status'] = 'unchanged';
        } else {
            $opts['content'][$el][$setting] = $clean;
            $changed++;
            $res['status']   = 'ok';
            $res['previous'] = $prev;
            $res['new']      = $clean;
        }
        $results[] = $res;
    }
    return [$results, $changed];
}

function bulkseme_acc_write($post_id, array $opts) {
    update_metadata('post', $post_id, 'accordions_options', wp_slash($opts));
    bulkseme_content_touch($post_id); // bump modified for caches
    return ['post_content_synced' => false];
}

function bulkseme_acc_commit($post_id, $prev_json, array $opts, array $results, $changed, $dry_run, $note = 'pre-update') {
    $out = [
        'id' => $post_id, 'builder' => 'accordions', 'dry_run' => (bool) $dry_run,
        'count' => count($results), 'changed' => $changed, 'results' => $results,
    ];
    if ($dry_run || $changed === 0) {
        $out['data_hash'] = md5($prev_json);
        return $out;
    }
    $json = bulkseme_acc_encode($opts);
    if ($json === false) {
        return new WP_Error('encode_failed', 'could not re-encode accordions_options; nothing was written', ['status' => 500]);
    }
    $out['backup_ts'] = bulkseme_content_take_backup($post_id, 'accordions', $prev_json, $note);
    if (!$out['backup_ts']) {
        return new WP_Error('backup_failed', 'could not store a backup of the current content; nothing was written', ['status' => 500]);
    }
    $out = array_merge($out, bulkseme_acc_write($post_id, $opts));
    $out['data_hash'] = md5($json);
    do_action('bulkseme_content_updated', $post_id, $results, get_current_user_id());
    return $out;
}
