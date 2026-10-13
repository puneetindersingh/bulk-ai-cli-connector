<?php
/**
 * SEO fields: registers the active SEO plugin's own meta keys for the REST API and holds
 * the readers and writers for posts, terms, post type archives and site-wide scopes.
 */

if (!defined('ABSPATH')) exit;

add_action('init', function () {

    // ---- Yoast SEO postmeta keys ---------------------------------------------
    $yoast_fields = [
        '_yoast_wpseo_title'                  => 'string',
        '_yoast_wpseo_metadesc'               => 'string',
        '_yoast_wpseo_focuskw'                => 'string',
        '_yoast_wpseo_canonical'              => 'string',
        '_yoast_wpseo_meta-robots-noindex'    => 'string',  // 0=default, 1=noindex, 2=index
        '_yoast_wpseo_meta-robots-nofollow'   => 'string',  // 0=follow, 1=nofollow
        '_yoast_wpseo_meta-robots-adv'        => 'string',  // "none" or csv: noimageindex,noarchive,nosnippet
        '_yoast_wpseo_opengraph-title'        => 'string',
        '_yoast_wpseo_opengraph-description'  => 'string',
        '_yoast_wpseo_opengraph-image'        => 'string',
        '_yoast_wpseo_twitter-title'          => 'string',
        '_yoast_wpseo_twitter-description'    => 'string',
        '_yoast_wpseo_twitter-image'          => 'string',
        '_yoast_wpseo_bctitle'                => 'string',
        '_yoast_wpseo_primary_category'       => 'integer',
    ];

    // ---- Rank Math postmeta keys ---------------------------------------------
    // Note: rank_math_robots is stored as a serialized array of strings
    // (e.g. ["index","follow","noarchive"]). All others are simple strings.
    $rankmath_fields = [
        'rank_math_title'                 => 'string',
        'rank_math_description'           => 'string',
        'rank_math_focus_keyword'         => 'string',  // primary + additional kws, comma-separated
        'rank_math_canonical_url'         => 'string',
        'rank_math_robots'                => 'array',
        'rank_math_advanced_robots'       => 'array',
        'rank_math_facebook_title'        => 'string',
        'rank_math_facebook_description'  => 'string',
        'rank_math_facebook_image'        => 'string',
        'rank_math_twitter_title'         => 'string',
        'rank_math_twitter_description'   => 'string',
        'rank_math_twitter_image'         => 'string',
        'rank_math_breadcrumb_title'      => 'string',
        'rank_math_pillar_content'        => 'string',  // "on" or empty
    ];

    // Per-post permission check shared by both groups.
    // Deliberately only "can edit this post". The SEO plugin's own role rule is NOT
    // applied here: the block editor sends every registered meta key back on save,
    // changed or not, and core refuses the whole save when an auth_callback denies a
    // key that has no stored row yet. bulkseme_rest_pre_insert_guard() applies the
    // role rule instead, and only to values that actually change.
    $auth_callback = function ($allowed, $meta_key, $object_id, $user_id) {
        return user_can($user_id, 'edit_post', $object_id);
    };

    $url_keys = [
        '_yoast_wpseo_canonical',
        '_yoast_wpseo_opengraph-image',
        '_yoast_wpseo_twitter-image',
        'rank_math_canonical_url',
        'rank_math_facebook_image',
        'rank_math_twitter_image',
    ];

    $register = function ($key, $type) use ($auth_callback, $url_keys) {
        $args = [
            'single'        => true,
            'type'          => $type,
            'auth_callback' => $auth_callback,
        ];

        // context => edit: core only returns these fields for ?context=edit, which it
        // allows only to users who can edit the post. Without it, wp/v2 would hand the
        // focus keyword and the other SEO fields to anyone who can read the post,
        // logged in or not.
        if ($type === 'array') {
            // Rank Math robots arrays - stored as serialized string[] in the
            // database, exposed in REST as a JSON array of strings.
            $args['show_in_rest'] = [
                'schema' => [
                    'type'    => 'array',
                    'items'   => ['type' => 'string'],
                    'context' => ['edit'],
                ],
            ];
        } else {
            $args['show_in_rest'] = [
                'schema' => [
                    'context' => ['edit'],
                ],
            ];
        }

        // Every key gets a cleaner that takes out bytes that are not valid UTF-8.
        // The database refuses a value that holds them, and through wp/v2 that
        // refusal is a 500. URL keys are cleaned as URLs on top of that.
        $args['sanitize_callback'] = in_array($key, $url_keys, true) ? 'bulkseme_sanitize_url' : 'bulkseme_sanitize_meta_utf8';

        return $args;
    };

    // ---- Detect which SEO plugin is active ----------------------------------
    // Constant checks are the lightest - both plugins set these on init.
    $yoast_active    = defined('WPSEO_VERSION')    || class_exists('WPSEO_Options');
    $rankmath_active = defined('RANK_MATH_VERSION') || class_exists('RankMath\\Helper');

    if (!$yoast_active && !$rankmath_active) {
        // Neither plugin detected - nothing to expose. Bail silently rather
        // than registering inert meta keys that would pollute REST responses.
        return;
    }

    // Register against every public post type - covers post, page, product, CPTs.
    $post_types = get_post_types(['public' => true], 'names');
    foreach ($post_types as $post_type) {
        add_filter("rest_pre_insert_{$post_type}", 'bulkseme_rest_pre_insert_guard', 10, 2);
    }

    foreach ($post_types as $post_type) {
        if ($yoast_active) {
            foreach ($yoast_fields as $key => $type) {
                register_post_meta($post_type, $key, $register($key, $type));
            }
        }
        if ($rankmath_active) {
            foreach ($rankmath_fields as $key => $type) {
                register_post_meta($post_type, $key, $register($key, $type));
            }
        }
    }

    // Rank Math primary-term-per-taxonomy keys are dynamic
    // (rank_math_primary_<taxonomy>). Only register if Rank Math is the
    // active plugin - Yoast uses _yoast_wpseo_primary_category which is
    // already covered above.
    if ($rankmath_active) {
        $taxonomies = get_taxonomies(['public' => true], 'names');
        foreach ($post_types as $post_type) {
            $pt_taxonomies = array_intersect($taxonomies, get_object_taxonomies($post_type));
            foreach ($pt_taxonomies as $taxonomy) {
                $key = 'rank_math_primary_' . $taxonomy;
                register_post_meta($post_type, $key, [
                    'show_in_rest'  => ['schema' => ['context' => ['edit']]],
                    'single'        => true,
                    'type'          => 'integer',
                    'auth_callback' => $auth_callback,
                ]);
            }
        }
    }

}, 20); // After Yoast and Rank Math register their own meta.

/**
 * Keys we expose, grouped by SEO plugin. Returns the set the active plugin
 * uses. If both are active, Yoast wins (you should only run one).
 */
function bulkseme_active_keys() {
    $yoast    = defined('WPSEO_VERSION')    || class_exists('WPSEO_Options');
    $rankmath = defined('RANK_MATH_VERSION') || class_exists('RankMath\\Helper');

    $yoast_keys = [
        'title'        => '_yoast_wpseo_title',
        'description'  => '_yoast_wpseo_metadesc',
        'focus_kw'     => '_yoast_wpseo_focuskw',
        'canonical'    => '_yoast_wpseo_canonical',
        'noindex'      => '_yoast_wpseo_meta-robots-noindex',
        'nofollow'     => '_yoast_wpseo_meta-robots-nofollow',
        'og_title'     => '_yoast_wpseo_opengraph-title',
        'og_desc'      => '_yoast_wpseo_opengraph-description',
        'og_image'     => '_yoast_wpseo_opengraph-image',
        'tw_title'     => '_yoast_wpseo_twitter-title',
        'tw_desc'      => '_yoast_wpseo_twitter-description',
        'tw_image'     => '_yoast_wpseo_twitter-image',
    ];

    $rankmath_keys = [
        'title'        => 'rank_math_title',
        'description'  => 'rank_math_description',
        'focus_kw'     => 'rank_math_focus_keyword',
        'canonical'    => 'rank_math_canonical_url',
        'robots'       => 'rank_math_robots',
        'og_title'     => 'rank_math_facebook_title',
        'og_desc'      => 'rank_math_facebook_description',
        'og_image'     => 'rank_math_facebook_image',
        'tw_title'     => 'rank_math_twitter_title',
        'tw_desc'      => 'rank_math_twitter_description',
        'tw_image'     => 'rank_math_twitter_image',
    ];

    if ($yoast)    return ['plugin' => 'yoast',    'keys' => $yoast_keys];
    if ($rankmath) return ['plugin' => 'rankmath', 'keys' => $rankmath_keys];
    return ['plugin' => null, 'keys' => []];
}

/**
 * Serialize one CSV row. Handles quoting/escaping the same way fputcsv
 * does, but as a pure string-builder so we don't have to fopen() a stream
 * (which Plugin Check flags as a filesystem operation).
 */
function bulkseme_csv_line($cells) {
    $out = [];
    foreach ($cells as $cell) {
        // One cell is one line. A post title can hold line breaks (WordPress keeps
        // them), and a title that carried whole CSV lines used to come back from
        // /import as rows of its own, written with the rights of whoever imported
        // the file. No exported value needs a line break, so none leaves here.
        // That covers every character some reader takes for a line break: CR and LF,
        // vertical tab and form feed, the file, group and record separators (Python's
        // splitlines() cuts at those), NEL, line separator and paragraph separator.
        // All control characters but the tab go; none of them belongs in a cell.
        $cell = str_replace(["\r\n", "\xC2\x85", "\xE2\x80\xA8", "\xE2\x80\xA9"], ' ', (string) $cell);
        $bare = preg_replace('/[\x00-\x08\x0A-\x1F\x7F]/', ' ', $cell);
        $cell = is_string($bare) ? $bare : $cell;
        // Spreadsheet formula injection: Excel, Google Sheets and LibreOffice run a
        // cell that starts with = + - @ (or a tab or carriage return) as a formula.
        // One leading apostrophe makes them show it as text. A cell that already
        // starts with apostrophes before such a character gets one more, so
        // bulkseme_csv_unguard() can remove exactly one and a round trip is lossless.
        if (preg_match('/^\'*[=+\-@\t\r]/', $cell)) {
            $cell = "'" . $cell;
        }
        if (preg_match('/[",\r\n]/', $cell)) {
            $cell = '"' . str_replace('"', '""', $cell) . '"';
        }
        $out[] = $cell;
    }
    // RFC 4180: CRLF terminators so multi-line cells parse correctly in Excel/Sheets/LibreOffice.
    return implode(',', $out) . "\r\n";
}

/**
 * Read CSV text into rows of cells, the way RFC 4180 defines it: a quoted cell
 * may hold commas, doubled quotes and line breaks, and stays one cell.
 *
 * /import used to cut the text at every line break first and read each piece as
 * a row. A quoted cell that held line breaks was torn into rows that nobody
 * with the right to write them had written. Returns [rows, ok]; ok is false
 * when the text ends inside a quoted cell, which no well-formed file does.
 * With $max_rows, reading stops once that many rows have been passed.
 */
function bulkseme_csv_parse($text, $max_rows = 0) {
    $text   = (string) $text;
    $rows   = [];
    $row    = [];
    $cell   = '';
    $quoted = false; // inside a quoted cell
    $any    = false; // the current row has content
    $len    = strlen($text);
    for ($i = 0; $i < $len; $i++) {
        $ch = $text[$i];
        if ($quoted) {
            if ($ch !== '"') {
                // Take the whole run up to the next quote in one step.
                $run   = strcspn($text, '"', $i);
                $cell .= substr($text, $i, $run);
                $i    += $run - 1;
            } elseif ($i + 1 < $len && $text[$i + 1] === '"') {
                $cell .= '"';
                $i++;
            } else {
                $quoted = false;
            }
            continue;
        }
        if ($ch === '"' && $cell === '') {
            $quoted = true;
            $any    = true;
        } elseif ($ch === ',') {
            $row[] = $cell;
            $cell  = '';
            $any   = true;
        } elseif ($ch === "\r" || $ch === "\n") {
            if ($ch === "\r" && $i + 1 < $len && $text[$i + 1] === "\n") {
                $i++;
            }
            if ($any) {
                $row[]  = $cell;
                $rows[] = $row;
                if ($max_rows && count($rows) > $max_rows) {
                    return [$rows, true]; // more than the caller will take: stop reading
                }
            }
            $row  = [];
            $cell = '';
            $any  = false;
        } else {
            $run   = strcspn($text, "\",\r\n", $i);
            $run   = $run > 0 ? $run : 1; // a quote in the middle of an unquoted cell is just a character
            $cell .= substr($text, $i, $run);
            $i    += $run - 1;
            $any   = true;
        }
    }
    if ($any) {
        $row[]  = $cell;
        $rows[] = $row;
    }
    return [$rows, !$quoted];
}

/**
 * Inverse of the formula guard in bulkseme_csv_line(): remove the one apostrophe
 * /export put in front of a cell that starts with = + - @, a tab or a carriage
 * return. Used by /import only, so a CSV round trip gives back the stored value.
 * Anything that is not a guarded string is returned untouched.
 */
function bulkseme_csv_unguard($cell) {
    if (is_string($cell) && preg_match('/^\'+[=+\-@\t\r]/', $cell)) {
        return substr($cell, 1);
    }
    return $cell;
}

/**
 * Turn one incoming value into text, or return null when it is not text-like.
 * Strings pass through, JSON null clears the field, booleans and finite numbers
 * are cast. Arrays and objects are refused: they are never cast, because a list
 * stored where the SEO plugin expects text bypasses the text sanitiser and can
 * fatal a string function further down.
 */
function bulkseme_scalar_text($value) {
    if (is_string($value)) {
        return $value;
    }
    if ($value === null) {
        return '';
    }
    if (is_bool($value)) {
        return $value ? '1' : '0';
    }
    if (is_int($value) || (is_float($value) && is_finite($value))) {
        return (string) $value;
    }
    return null;
}

/**
 * One request parameter as plain text.
 *
 * A parameter can arrive as a list or an object (?status[]=x, or a JSON array).
 * Those have no text form: cast, they become the word "Array" with a PHP
 * warning, and handed to a string function they end the request with a
 * TypeError. Here they read as the default.
 */
function bulkseme_param_text($value, $default = '') {
    return is_scalar($value) ? (string) $value : $default;
}

/**
 * One request parameter as a record id. A list or an object is no id at all:
 * cast to a number it would read as 1.
 */
function bulkseme_param_id($value) {
    return is_scalar($value) ? absint($value) : 0;
}

/**
 * One request parameter as a list of names: a comma-separated string or a list
 * of strings. Anything nested is left out.
 */
function bulkseme_param_list($value) {
    if (is_scalar($value)) {
        $value = explode(',', (string) $value);
    } elseif (!is_array($value)) {
        return [];
    }
    $out = [];
    foreach ($value as $v) {
        if (is_scalar($v)) {
            $v = trim((string) $v);
            if ($v !== '') {
                $out[] = $v;
            }
        }
    }
    return $out;
}

/**
 * Registered SEO meta, on any write path: take out bytes that are not valid
 * UTF-8, in a string or in a list of strings. Nothing else about the value is
 * changed here; the SEO plugin's own cleaning still applies.
 */
function bulkseme_sanitize_meta_utf8($value) {
    if (is_string($value)) {
        $clean = wp_check_invalid_utf8($value, true);
        return is_string($clean) ? $clean : '';
    }
    if (is_array($value)) {
        return array_map('bulkseme_sanitize_meta_utf8', $value);
    }
    return $value;
}

/**
 * Clean a URL field. Bytes that are not valid UTF-8 come out first: the
 * database refuses a value that still holds them, and through WordPress's own
 * REST routes that refusal is answered with a 500.
 */
function bulkseme_sanitize_url($value) {
    if (!is_scalar($value)) {
        return '';
    }
    $clean = wp_check_invalid_utf8((string) $value, true);
    return esc_url_raw(is_string($clean) ? $clean : '');
}

/**
 * Which group of SEO settings a meta key belongs to, in the terms Yoast SEO and
 * Rank Math use for their own role rules: 'advanced' (canonical URL, robots
 * flags, breadcrumb title), 'social' (Open Graph and Twitter fields) or
 * 'general' (title, description, focus keyword, primary term).
 */
function bulkseme_field_group($key) {
    $key = (string) $key;
    if (preg_match('/canonical|robots|noindex|nofollow|bctitle|breadcrumb_title/', $key)) {
        return 'advanced';
    }
    if (preg_match('/opengraph|facebook|twitter/', $key)) {
        return 'social';
    }
    return 'general';
}

/**
 * Whether a user may edit one group of SEO settings ('general', 'advanced',
 * 'social' or 'schema') under the ACTIVE SEO plugin's own role rules. This runs
 * on top of the per-item edit_post / edit_term check, never instead of it.
 *
 * Yoast SEO: with its "Security: no advanced or schema settings for authors"
 * setting on (the default), only users with wpseo_edit_advanced_metadata see
 * the Advanced and Schema tabs of its metabox. Rank Math: its Role Manager
 * capabilities rank_math_onpage_general / _advanced / _social / _snippet.
 * The 'site' group is the site-wide settings (archive and global titles):
 * wpseo_manage_options on Yoast, rank_math_titles on Rank Math. On multisite
 * Yoast can reserve that for super admins, and both plugins let a site owner
 * take it away from a role, so manage_options alone is not enough.
 * A caller of this API gets exactly what the same user gets in wp-admin.
 * With no SEO plugin active there is no extra rule.
 */
function bulkseme_user_can_seo_group($group, $user_id = 0) {
    $user_id = $user_id ? (int) $user_id : get_current_user_id();
    $plugin  = bulkseme_active_keys()['plugin'];
    if (!$plugin && (defined('WPSEO_VERSION') || class_exists('WPSEO_Options'))) {
        $plugin = 'yoast';
    }
    $allowed = true;
    if ($plugin === 'yoast') {
        if ($group === 'site') {
            $allowed = user_can($user_id, 'wpseo_manage_options');
        } elseif (in_array($group, ['advanced', 'schema'], true)
            && class_exists('WPSEO_Options') && WPSEO_Options::get('disableadvanced_meta', true)) {
            // Same test as WPSEO_Capability_Utils::current_user_can().
            $allowed = user_can($user_id, 'wpseo_manage_options') || user_can($user_id, 'wpseo_edit_advanced_metadata');
        }
    } elseif ($plugin === 'rankmath') {
        $caps = [
            'general'  => 'rank_math_onpage_general',
            'advanced' => 'rank_math_onpage_advanced',
            'social'   => 'rank_math_onpage_social',
            'schema'   => 'rank_math_onpage_snippet',
            'site'     => 'rank_math_titles',
        ];
        $allowed = isset($caps[$group]) ? user_can($user_id, $caps[$group]) : true;
    } elseif ($group === 'schema') {
        // No SEO plugin has a say here. Structured data is printed in the page
        // head, where an editor reviewing a post never sees it, and WordPress
        // would strip it from the content of anyone below Editor. So it takes
        // an Editor, as with Yoast SEO's own default for schema settings.
        $allowed = user_can($user_id, 'edit_others_posts');
    } elseif ($group === 'site') {
        // Site-wide settings belong to administrators. Every caller checks that
        // as well; the rule is stated here too so this function never answers
        // yes for them by default.
        $allowed = user_can($user_id, 'manage_options');
    }
    /**
     * Filters whether a user may edit one group of SEO settings through this plugin.
     *
     * @param bool        $allowed Result of the active SEO plugin's own role rule.
     * @param string      $group   general, advanced, social, schema or site.
     * @param int         $user_id User being checked.
     * @param string|null $plugin  yoast, rankmath or null.
     */
    return (bool) apply_filters('bulkseme_user_can_edit_field_group', $allowed, $group, $user_id, $plugin);
}

/**
 * Core REST (wp/v2) guard for the SEO meta keys this plugin registers there.
 *
 * Applies the active SEO plugin's own role rule (bulkseme_user_can_seo_group())
 * before anything is saved. It is done here and not in the keys' auth_callback
 * for two reasons. Yoast SEO grants edit_post_meta for every _yoast_wpseo_ key
 * to anyone who can edit the post (its allow_custom_field_edits() filter), which
 * overrides an auth_callback. And the block editor sends every registered meta
 * key back on save, changed or not, so the rule may only bite on a real change:
 * a value equal to what the REST API currently returns for that key (including
 * the default for a key with no stored row) is let through.
 */
function bulkseme_rest_pre_insert_guard($prepared_post, $request) {
    if (is_wp_error($prepared_post)) {
        return $prepared_post;
    }
    $meta = $request['meta'];
    if (!is_array($meta) || !$meta) {
        return $prepared_post;
    }
    $post_id = isset($prepared_post->ID) ? (int) $prepared_post->ID : 0;
    $current = null;
    foreach ($meta as $key => $value) {
        $key = (string) $key;
        if (strpos($key, '_yoast_wpseo_') !== 0 && strpos($key, 'rank_math_') !== 0) {
            continue;
        }
        if (bulkseme_user_can_seo_group(bulkseme_field_group($key))) {
            continue;
        }
        // Not a change: the value the REST API returns for this key right now.
        if ($post_id && $current === null && class_exists('WP_REST_Post_Meta_Fields')) {
            $fields  = new WP_REST_Post_Meta_Fields(get_post_type($post_id));
            $current = (array) $fields->get_value($post_id, $request);
        }
        if ($post_id && is_array($current) && array_key_exists($key, $current)
            && bulkseme_same_value($current[$key], $value)) {
            continue;
        }
        // Not a change either: an empty value on a post that does not exist yet.
        if (!$post_id && ($value === null || $value === '' || $value === [] || $value === 0 || $value === false)) {
            continue;
        }
        return new WP_Error(
            'bulkseme_forbidden_field',
            /* translators: %s: meta key name. */
            sprintf(__('Your role may not change the SEO field %s on this site.', 'bulk-seo-meta-editor-for-ai-agents'), $key),
            ['status' => 403]
        );
    }
    return $prepared_post;
}

/**
 * Loose "same value" test for two meta values as they travel through REST:
 * lists compare by content, everything else as text, so 0 and "0" match and
 * null matches an empty string.
 */
function bulkseme_same_value($a, $b) {
    if (is_array($a) || is_array($b)) {
        return is_array($a) && is_array($b) && wp_json_encode($a) === wp_json_encode($b);
    }
    if (is_object($a) || is_object($b)) {
        return false;
    }
    return (string) $a === (string) $b;
}

/**
 * Sanitise one of the two Rank Math fields that are lists by design
 * (rank_math_robots, rank_math_advanced_robots). Keeps text-like entries only.
 */
function bulkseme_clean_list($value) {
    $clean = [];
    foreach ((array) $value as $k => $v) {
        $v = bulkseme_scalar_text($v);
        if ($v === null) {
            continue;
        }
        if (is_int($k)) {
            $clean[] = sanitize_text_field($v);
        } else {
            $clean[sanitize_key((string) $k)] = sanitize_text_field($v);
        }
    }
    return $clean;
}

/**
 * Term-meta alias map for the active SEO plugin. Yoast term keys differ
 * from postmeta keys (no "_yoast_wpseo_" prefix - they live in the
 * wpseo_taxonomy_meta option); Rank Math reuses the same key names as
 * postmeta but stores them in wp_termmeta.
 */
function bulkseme_active_term_keys() {
    $yoast    = defined('WPSEO_VERSION')    || class_exists('WPSEO_Options');
    $rankmath = defined('RANK_MATH_VERSION') || class_exists('RankMath\\Helper');

    $yoast_keys = [
        'title'        => 'wpseo_title',
        'description'  => 'wpseo_desc',
        'focus_kw'     => 'wpseo_focuskw',
        'canonical'    => 'wpseo_canonical',
        'noindex'      => 'wpseo_noindex',
        'og_title'     => 'wpseo_opengraph-title',
        'og_desc'      => 'wpseo_opengraph-description',
        'og_image'     => 'wpseo_opengraph-image',
        'tw_title'     => 'wpseo_twitter-title',
        'tw_desc'      => 'wpseo_twitter-description',
        'tw_image'     => 'wpseo_twitter-image',
    ];

    $rankmath_keys = [
        'title'        => 'rank_math_title',
        'description'  => 'rank_math_description',
        'focus_kw'     => 'rank_math_focus_keyword',
        'canonical'    => 'rank_math_canonical_url',
        'robots'       => 'rank_math_robots',
        'og_title'     => 'rank_math_facebook_title',
        'og_desc'      => 'rank_math_facebook_description',
        'og_image'     => 'rank_math_facebook_image',
        'tw_title'     => 'rank_math_twitter_title',
        'tw_desc'      => 'rank_math_twitter_description',
        'tw_image'     => 'rank_math_twitter_image',
    ];

    if ($yoast)    return ['plugin' => 'yoast',    'keys' => $yoast_keys];
    if ($rankmath) return ['plugin' => 'rankmath', 'keys' => $rankmath_keys];
    return ['plugin' => null, 'keys' => []];
}

/**
 * Read one term-meta value, abstracting Yoast (option-array) vs Rank Math
 * (wp_termmeta) storage.
 */
function bulkseme_term_get_value($term_id, $taxonomy, $meta_key, $plugin) {
    if ($plugin === 'rankmath') {
        return get_term_meta($term_id, $meta_key, true);
    }
    if ($plugin === 'yoast') {
        $opt = get_option('wpseo_taxonomy_meta', []);
        if (isset($opt[$taxonomy][$term_id][$meta_key])) {
            return $opt[$taxonomy][$term_id][$meta_key];
        }
        return '';
    }
    return '';
}

/**
 * Write one term-meta value. Yoast stores all term SEO inside a single
 * wpseo_taxonomy_meta option (NOT in wp_termmeta), so we read-modify-write
 * the option once per call.
 */
function bulkseme_term_set_value($term_id, $taxonomy, $meta_key, $value, $plugin) {
    if ($plugin === 'rankmath') {
        update_term_meta($term_id, $meta_key, $value);
        return true;
    }
    if ($plugin === 'yoast') {
        // Term SEO meta is stored by Yoast SEO itself, so write it through
        // Yoast's own public API (WPSEO_Taxonomy_Meta) instead of touching
        // Yoast's storage directly. set_values() expects the FULL field set
        // (it mirrors Yoast's admin form, which always posts every field and
        // resets absent ones to defaults), so overlay our one change onto the
        // term's current values first - that preserves every other field.
        if (!class_exists('WPSEO_Taxonomy_Meta')) {
            return false;
        }
        $current = WPSEO_Taxonomy_Meta::get_term_meta((int) $term_id, $taxonomy);
        if (!is_array($current)) {
            $current = [];
        }
        $current[$meta_key] = $value;
        WPSEO_Taxonomy_Meta::set_values((int) $term_id, $taxonomy, $current);
        // Yoast 14+ caches rendered SEO meta in the yoast_indexable table.
        // set_values() persists the meta but does not fire the term-save
        // signals Yoast's own admin screen fires, so the indexable stays
        // stale and the FRONT-END keeps rendering the
        // old meta description (verified on a real WP/Cloudflare site -
        // page cache cleared, but meta unchanged because Yoast served a
        // stale Indexable). Fire Yoast's own term-save signal so its
        // Indexable_Term_Watcher rebuilds the cached row for this term.
        // No-op when Yoast isn't installed; safe when the action isn't
        // hooked. Wrapped in function_exists for older Yoasts that
        // don't ship this hook.
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Yoast SEO's own documented integration hook; firing it triggers their Indexable_Term_Watcher cache rebuild. Not a hook we own.
        do_action('wpseo_save_taxonomy_meta', (int) $term_id, (string) $taxonomy);
        // Belt-and-braces: also fire WordPress's own edited_term hook -
        // Yoast's watcher listens to that as a fallback, and a few SEO
        // caches/CDN integrations (W3TC, WPRocket, Cloudflare APO) listen
        // for it to purge per-URL caches. Args: term_id, term_taxonomy_id,
        // taxonomy. We don't have term_taxonomy_id handy; pass term_id -
        // every consumer we care about reads $term_id, not $tt_id.
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress core action; firing it is the standard way to signal third-party caches that a term changed.
        do_action('edited_term', (int) $term_id, (int) $term_id, (string) $taxonomy);
        // Direct indexable wipe as final safety net - if the Yoast classes
        // are loaded, delete the Indexable row for this term so Yoast
        // rebuilds on next request. Newer Yoasts expose a builder; older
        // ones use the legacy table directly.
        if (class_exists('\\Yoast\\WP\\SEO\\Repositories\\Indexable_Repository')
            && function_exists('YoastSEO')) {
            try {
                $repo = YoastSEO()->classes->get('\\Yoast\\WP\\SEO\\Repositories\\Indexable_Repository');
                if ($repo && method_exists($repo, 'find_by_id_and_type')) {
                    $idx = $repo->find_by_id_and_type((int) $term_id, 'term', false);
                    if ($idx && method_exists($idx, 'delete')) {
                        $idx->delete();
                    }
                }
            } catch (\Throwable $e) { /* swallow - best-effort */ }
        }
        return true;
    }
    return false;
}

/**
 * Apply a meta update to one term. Mirrors bulkseme_apply_update()
 * but for taxonomy terms. Permission is enforced via the taxonomy's
 * edit_terms cap.
 */
function bulkseme_apply_term_update($term_id, $taxonomy, $meta) {
    $term = get_term((int) $term_id, $taxonomy);
    if (!$term || is_wp_error($term)) {
        return ['ok' => false, 'errors' => ['term_not_found']];
    }
    $tax_obj = get_taxonomy($taxonomy);
    if (!$tax_obj) {
        return ['ok' => false, 'errors' => ['unknown_taxonomy']];
    }
    // Per-term permission: 'edit_term' is WordPress's per-object meta cap
    // (it maps to the taxonomy's edit_terms cap by default, but lets
    // map_meta_cap filters restrict individual terms).
    if (!current_user_can('edit_term', $term->term_id)) {
        return ['ok' => false, 'errors' => ['forbidden']];
    }

    // Plugin-owned JSON-LD schema fields. Like the post version they do not
    // depend on the active SEO plugin, so they are taken out of $meta before
    // the no_seo_plugin check and the Yoast/Rank Math allowlist below. The
    // per-term edit_term check above already gates them.
    $schema_errors = [];
    $may_schema    = bulkseme_user_can_seo_group('schema');
    foreach (['schema', '_bulkseme_schema_jsonld', 'schema_mode', '_bulkseme_schema_mode'] as $schema_key) {
        if (!$may_schema && array_key_exists($schema_key, $meta)) {
            $schema_errors[] = "forbidden_field:$schema_key";
            unset($meta[$schema_key]);
        }
    }
    foreach (['schema', '_bulkseme_schema_jsonld'] as $schema_key) {
        if (array_key_exists($schema_key, $meta)) {
            $res = bulkseme_store_term_schema((int) $term->term_id, $meta[$schema_key]);
            if ($res !== true) {
                $schema_errors[] = $res;
            }
            unset($meta[$schema_key]);
        }
    }
    foreach (['schema_mode', '_bulkseme_schema_mode'] as $mode_key) {
        if (array_key_exists($mode_key, $meta)) {
            update_term_meta((int) $term->term_id, '_bulkseme_schema_mode', $meta[$mode_key] === 'replace' ? 'replace' : 'add');
            unset($meta[$mode_key]);
        }
    }
    if (empty($meta)) {
        return ['ok' => empty($schema_errors), 'errors' => $schema_errors];
    }

    $active = bulkseme_active_term_keys();
    if (!$active['plugin']) {
        return ['ok' => false, 'errors' => array_merge($schema_errors, ['no_seo_plugin'])];
    }
    $alias_to_meta = $active['keys'];
    $allowed       = array_values($alias_to_meta);
    $url_keys      = [
        'wpseo_canonical', 'wpseo_opengraph-image', 'wpseo_twitter-image',
        'rank_math_canonical_url', 'rank_math_facebook_image', 'rank_math_twitter_image',
    ];

    $errors = $schema_errors;
    foreach ($meta as $key => $value) {
        if (isset($alias_to_meta[$key])) {
            $key = $alias_to_meta[$key];
        }
        if (!in_array($key, $allowed, true)) {
            $errors[] = "unknown_or_disallowed_key:$key";
            continue;
        }
        if (!bulkseme_user_can_seo_group(bulkseme_field_group($key))) {
            $errors[] = "forbidden_field:$key";
            continue;
        }
        if (is_array($value) && $key === 'rank_math_robots') {
            $value = bulkseme_clean_list($value);
        } else {
            $value = bulkseme_scalar_text($value);
            if ($value === null) {
                $errors[] = "invalid_value_type:$key";
                continue;
            }
            $value = in_array($key, $url_keys, true) ? bulkseme_sanitize_url($value) : bulkseme_sanitize_seo_text($value);
        }
        bulkseme_term_set_value($term_id, $taxonomy, $key, $value, $active['plugin']);
    }
    return ['ok' => empty($errors), 'errors' => $errors];
}

/**
 * Archive-meta alias map. Yoast stores CPT-archive titles/descriptions as
 * keys inside the `wpseo_titles` option (e.g. `title-ptarchive-{ptype}`,
 * `metadesc-ptarchive-{ptype}`); Rank Math uses `rank-math-options-titles`
 * (e.g. `pt_{ptype}_archive_title`). The alias keys here are the wire
 * format consumers see - same shape as post/term keys for round-trip.
 */
function bulkseme_active_archive_keys() {
    $yoast    = defined('WPSEO_VERSION')    || class_exists('WPSEO_Options');
    $rankmath = defined('RANK_MATH_VERSION') || class_exists('RankMath\\Helper');

    // Yoast: keys are template names; the plugin substitutes %%sitename%%
    // etc. server-side. We pass through verbatim.
    $yoast_keys = [
        'title'        => 'title-ptarchive',
        'description'  => 'metadesc-ptarchive',
        'noindex'      => 'noindex-ptarchive',
        'bctitle'      => 'bctitle-ptarchive',
    ];

    $rankmath_keys = [
        'title'        => 'archive_title',
        'description'  => 'archive_description',
        'robots'       => 'archive_robots',
    ];

    if ($yoast)    return ['plugin' => 'yoast',    'keys' => $yoast_keys];
    if ($rankmath) return ['plugin' => 'rankmath', 'keys' => $rankmath_keys];
    return ['plugin' => null, 'keys' => []];
}

/**
 * Read one archive-meta value, abstracting Yoast's `wpseo_titles` option-
 * array vs Rank Math's `rank-math-options-titles` option-array storage.
 */
function bulkseme_archive_get_value($post_type, $alias_or_key, $plugin) {
    if ($plugin === 'yoast') {
        $opt = get_option('wpseo_titles', []);
        // Accept either alias ("title") or full key ("title-ptarchive").
        $base = (strpos($alias_or_key, '-ptarchive') === false) ? $alias_or_key . '-ptarchive' : $alias_or_key;
        $key  = $base . '-' . $post_type;
        return isset($opt[$key]) ? $opt[$key] : '';
    }
    if ($plugin === 'rankmath') {
        $opt = get_option(bulkseme_rankmath_titles_option(), []);
        $base = $alias_or_key;
        // Strip leading 'archive_' if user passed the full key.
        $base = preg_replace('/^archive_/', '', $base);
        $key  = 'pt_' . $post_type . '_archive_' . $base;
        return isset($opt[$key]) ? $opt[$key] : '';
    }
    return '';
}

/**
 * The option key Rank Math itself stores its title/archive settings under.
 * This plugin does not define or own this option - Rank Math does. This
 * plugin is a REST bridge INTO Rank Math, and Rank Math (unlike Yoast, which
 * exposes WPSEO_Options::set()) ships no public setter for these settings,
 * so bridging a write means read-modify-writing the exact option Rank Math
 * reads back. Everything this plugin creates itself is bulkseme_-prefixed.
 */
function bulkseme_rankmath_titles_option() {
    return 'rank-math-options-titles';
}

/**
 * Write one key into the active SEO plugin's title-settings storage.
 * Yoast: via its public options API (WPSEO_Options::set() routes the key to
 * the right option group, validates it, and clears Yoast's own caches).
 * Rank Math: read-modify-write of its titles option (no public setter, see
 * bulkseme_rankmath_titles_option()).
 */
function bulkseme_titles_set($plugin, $key, $value) {
    if ($plugin === 'yoast') {
        if (!class_exists('WPSEO_Options')) {
            return false;
        }
        WPSEO_Options::set($key, $value);
        return true;
    }
    if ($plugin === 'rankmath') {
        $option_name = bulkseme_rankmath_titles_option();
        $opt = get_option($option_name, []);
        if (!is_array($opt)) {
            $opt = [];
        }
        $opt[$key] = $value;
        update_option($option_name, $opt);
        return true;
    }
    return false;
}

/**
 * Write one archive-meta value into the active SEO plugin's title settings.
 */
function bulkseme_archive_set_value($post_type, $meta_key, $value, $plugin) {
    if ($plugin === 'yoast') {
        // meta_key is the Yoast alias ('title-ptarchive'); append the ptype.
        return bulkseme_titles_set('yoast', $meta_key . '-' . $post_type, $value);
    }
    if ($plugin === 'rankmath') {
        // meta_key is the Rank Math alias ('archive_title'); wrap with
        // pt_{ptype}_ prefix so we hit the right field for this CPT.
        $base = preg_replace('/^archive_/', '', $meta_key);
        return bulkseme_titles_set('rankmath', 'pt_' . $post_type . '_archive_' . $base, $value);
    }
    return false;
}

/**
 * Registry of "global" SEO scopes - non-post, non-term, non-CPT-archive
 * resources that carry their own title/description settings stored in the
 * SEO plugin's option arrays. Each scope maps to a Yoast key set (in
 * `wpseo_titles`) and a Rank Math key set (in `rank-math-options-titles`).
 *
 * Use the alias on the wire (`title`, `description`, etc.); the plugin
 * translates to the storage key for the active SEO plugin.
 *
 * To add a new scope: drop another entry into this array. No other code
 * change needed - bulk/export/import dispatch via this registry.
 */
function bulkseme_global_scopes() {
    return [
        'author_archive' => [
            'label' => 'Author archive (global)',
            'yoast' => [
                'title'       => 'title-author-wpseo',
                'description' => 'metadesc-author-wpseo',
                'noindex'     => 'noindex-author-wpseo',
                'bctitle'     => 'bctitle-author-wpseo',
            ],
            'rankmath' => [
                'title'       => 'author_archive_title',
                'description' => 'author_archive_description',
                'robots'      => 'author_custom_robots',
            ],
        ],
        'date_archive' => [
            'label' => 'Date archive (global)',
            'yoast' => [
                'title'       => 'title-archive-wpseo',
                'description' => 'metadesc-archive-wpseo',
                'noindex'     => 'noindex-archive-wpseo',
            ],
            'rankmath' => [
                'title'       => 'date_archive_title',
                'description' => 'date_archive_description',
            ],
        ],
        'search' => [
            'label' => 'Search results page',
            'yoast' => [
                'title'       => 'title-search-wpseo',
                'description' => 'metadesc-search-wpseo',
            ],
            'rankmath' => [
                'title'       => 'search_title',
                'description' => 'search_description',
            ],
        ],
        'p404' => [
            'label' => '404 page',
            'yoast' => [
                'title'       => 'title-404-wpseo',
            ],
            'rankmath' => [
                'title'       => '404_title',
                'description' => '404_description',
            ],
        ],
        'home' => [
            'label' => 'Homepage (latest-posts mode)',
            'yoast' => [
                'title'       => 'title-home-wpseo',
                'description' => 'metadesc-home-wpseo',
            ],
            'rankmath' => [
                'title'       => 'homepage_title',
                'description' => 'homepage_description',
            ],
        ],
    ];
}

/**
 * Alias map for one global scope under the active SEO plugin. Returns
 * shape: ['plugin' => 'yoast'|'rankmath'|null, 'keys' => [alias=>storage_key]].
 */
function bulkseme_global_active_keys($scope) {
    $yoast    = defined('WPSEO_VERSION')    || class_exists('WPSEO_Options');
    $rankmath = defined('RANK_MATH_VERSION') || class_exists('RankMath\\Helper');
    $scopes   = bulkseme_global_scopes();
    if (!isset($scopes[$scope])) return ['plugin' => null, 'keys' => []];
    if ($yoast    && !empty($scopes[$scope]['yoast']))    return ['plugin' => 'yoast',    'keys' => $scopes[$scope]['yoast']];
    if ($rankmath && !empty($scopes[$scope]['rankmath'])) return ['plugin' => 'rankmath', 'keys' => $scopes[$scope]['rankmath']];
    return ['plugin' => null, 'keys' => []];
}

function bulkseme_global_get_value($scope, $alias_or_key, $plugin) {
    $active = bulkseme_global_active_keys($scope);
    if (!$active['plugin']) return '';
    $key = isset($active['keys'][$alias_or_key]) ? $active['keys'][$alias_or_key] : $alias_or_key;
    $opt = $plugin === 'yoast' ? get_option('wpseo_titles', []) : get_option(bulkseme_rankmath_titles_option(), []);
    return isset($opt[$key]) ? $opt[$key] : '';
}

/**
 * Apply a meta update to one "global" SEO scope (author_archive,
 * date_archive, search, p404, home). All globals are admin-only -
 * `manage_options` cap - because they affect site-wide SEO settings, not
 * a single post or term.
 */
function bulkseme_apply_global_update($scope, $meta) {
    $scopes = bulkseme_global_scopes();
    if (!isset($scopes[$scope])) {
        return ['ok' => false, 'errors' => ['unknown_scope']];
    }
    if (!current_user_can('manage_options') || !bulkseme_user_can_seo_group('site')) {
        return ['ok' => false, 'errors' => ['forbidden']];
    }
    $active = bulkseme_global_active_keys($scope);
    if (!$active['plugin']) {
        return ['ok' => false, 'errors' => ['no_seo_plugin_or_scope_unsupported']];
    }
    $alias_to_meta = $active['keys'];
    $allowed       = array_values($alias_to_meta);

    $errors = [];
    foreach ($meta as $key => $value) {
        if (isset($alias_to_meta[$key])) {
            $key = $alias_to_meta[$key];
        }
        if (!in_array($key, $allowed, true)) {
            $errors[] = "unknown_or_disallowed_key:$key";
            continue;
        }
        $value = bulkseme_scalar_text($value);
        if ($value === null) {
            $errors[] = "invalid_value_type:$key";
            continue;
        }
        $value = bulkseme_sanitize_seo_text($value);
        if (!bulkseme_titles_set($active['plugin'], $key, $value)) {
            $errors[] = "write_failed:$key";
        }
    }
    return ['ok' => empty($errors), 'errors' => $errors];
}

/**
 * Apply a meta update to one CPT archive page. Mirrors the post + term
 * update helpers but writes into the SEO plugin's options-array storage.
 * Permission: manage_options (admin-only). Archive titles/descriptions live
 * in the SEO plugin's site-wide settings, not on an individual post, so
 * editing them is a site-settings change - same bar as the global scopes.
 */
function bulkseme_apply_archive_update($post_type, $meta) {
    if (!post_type_exists($post_type)) {
        return ['ok' => false, 'errors' => ['unknown_post_type']];
    }
    $pt = get_post_type_object($post_type);
    if (!$pt || empty($pt->has_archive)) {
        return ['ok' => false, 'errors' => ['post_type_has_no_archive']];
    }
    if (!current_user_can('manage_options') || !bulkseme_user_can_seo_group('site')) {
        return ['ok' => false, 'errors' => ['forbidden']];
    }
    $active = bulkseme_active_archive_keys();
    if (!$active['plugin']) {
        return ['ok' => false, 'errors' => ['no_seo_plugin']];
    }
    $alias_to_meta = $active['keys'];
    $allowed       = array_values($alias_to_meta);

    $errors = [];
    foreach ($meta as $key => $value) {
        if (isset($alias_to_meta[$key])) {
            $key = $alias_to_meta[$key];
        }
        if (!in_array($key, $allowed, true)) {
            $errors[] = "unknown_or_disallowed_key:$key";
            continue;
        }
        $value = bulkseme_scalar_text($value);
        if ($value === null) {
            $errors[] = "invalid_value_type:$key";
            continue;
        }
        $value = bulkseme_sanitize_seo_text($value);
        bulkseme_archive_set_value($post_type, $key, $value, $active['plugin']);
    }
    return ['ok' => empty($errors), 'errors' => $errors];
}

/**
 * True when posts of this type can be reached through this plugin at all.
 *
 * The type must be registered and must have an edit screen or a REST route of
 * its own. That leaves out revisions and the record types WordPress and other
 * plugins keep for themselves, where edit_post says little about who should
 * change them: privacy requests (whose title is the address the request acts
 * on), the oEmbed cache, Customizer changesets and CSS, and this plugin's own
 * backups.
 */
function bulkseme_post_type_reachable($post_type) {
    $type = is_string($post_type) ? get_post_type_object($post_type) : null;
    if (!$type || $type->name === 'revision') {
        return false;
    }
    return !empty($type->show_ui) || !empty($type->show_in_rest);
}

/**
 * Clean one line of plain text for storage, keeping percent signs.
 *
 * Core's sanitize_text_field() removes anything that looks like a
 * percent-encoded byte, which eats real text ("50%cafe") and the start of the
 * SEO plugins' replacement variables (see bulkseme_sanitize_seo_text()). Here
 * tags, line breaks and extra spaces go as usual and percent signs stay.
 */
function bulkseme_sanitize_plain_text($value) {
    $hold  = "\u{E000}"; // a private-use character standing in for % while core cleans the rest
    $value = str_replace($hold, '', (string) $value);
    return str_replace($hold, '%', sanitize_text_field(str_replace('%', $hold, $value)));
}

/**
 * Clean one line of SEO text (a title, a description, a social title).
 *
 * Yoast SEO and Rank Math each have a text sanitiser of their own that knows
 * their replacement variables. Core's would turn %%category%% into %tegory%%
 * and %date% into te%. So the active plugin's own one is used, and without
 * either, the plain one above.
 */
function bulkseme_sanitize_seo_text($value) {
    $value  = (string) $value;
    $plugin = bulkseme_active_keys()['plugin'];
    if ($plugin === 'yoast' && is_callable(['WPSEO_Utils', 'sanitize_text_field'])) {
        return (string) WPSEO_Utils::sanitize_text_field($value);
    }
    if ($plugin === 'rankmath' && is_callable(['\\RankMath\\CMB2', 'sanitize_textfield'])) {
        return (string) \RankMath\CMB2::sanitize_textfield($value);
    }
    return bulkseme_sanitize_plain_text($value);
}

/**
 * Apply a meta update to one post. Returns ['ok' => bool, 'errors' => [...]].
 * Permission is enforced per-post; the meta keys themselves are validated
 * against the active plugin's known fields to stop arbitrary postmeta writes.
 */
function bulkseme_apply_update($post_id, $meta) {
    $post = get_post($post_id);
    if (!$post || !bulkseme_post_type_reachable($post->post_type)) {
        return ['ok' => false, 'errors' => ['post_not_found']];
    }
    if (!current_user_can('edit_post', $post_id)) {
        return ['ok' => false, 'errors' => ['forbidden']];
    }
    $active = bulkseme_active_keys();
    $alias_to_meta = $active['keys'];
    $allowed = array_values($active['keys']);
    // Also accept the dynamic Rank Math primary-taxonomy keys.
    $allowed_dynamic_prefix = $active['plugin'] === 'rankmath' ? 'rank_math_primary_' : null;

    $errors = [];
    foreach ($meta as $key => $value) {
        // Plugin-owned JSON-LD schema fields. These are independent of the active
        // SEO plugin (they ride that plugin's schema graph at output time), so they
        // are handled before the Yoast/Rank Math allowlist below. See the
        // "Custom JSON-LD schema" section lower in this file.
        if (in_array($key, ['schema', '_bulkseme_schema_jsonld', 'schema_mode', '_bulkseme_schema_mode'], true)
            && !bulkseme_user_can_seo_group('schema')) {
            $errors[] = "forbidden_field:$key";
            continue;
        }
        if ($key === 'schema' || $key === '_bulkseme_schema_jsonld') {
            $res = bulkseme_store_schema($post_id, $value);
            if ($res !== true) {
                $errors[] = $res;
            }
            continue;
        }
        if ($key === 'schema_mode' || $key === '_bulkseme_schema_mode') {
            update_post_meta($post_id, '_bulkseme_schema_mode', $value === 'replace' ? 'replace' : 'add');
            continue;
        }

        // WordPress post title. Themes print it as the page H1, so it is page
        // copy rather than SEO-plugin meta and it sits outside the allowlist
        // below. The edit_post check at the top of this function already
        // covers it. The slug is never touched: wp_update_post() keeps the
        // existing post_name. /import never sends this key (post_title is a
        // read-only column there), so a CSV round trip cannot rename posts.
        if ($key === 'post_title') {
            $title = bulkseme_sanitize_plain_text($value);
            if ($title === '') {
                $errors[] = 'empty_post_title';
                continue;
            }
            if ($title !== $post->post_title) {
                $res = wp_update_post(['ID' => $post_id, 'post_title' => wp_slash($title)], true);
                if (is_wp_error($res)) {
                    $errors[] = 'post_title_update_failed';
                }
            }
            continue;
        }

        // Accept friendly aliases (title, description, focus_kw, ...) and translate
        // to the active plugin's raw meta key. Lets /export columns round-trip
        // through /bulk without manual key remapping.
        if (isset($alias_to_meta[$key])) {
            $key = $alias_to_meta[$key];
        }
        $is_allowed = in_array($key, $allowed, true)
            || ($allowed_dynamic_prefix && strpos($key, $allowed_dynamic_prefix) === 0
                && taxonomy_exists(substr($key, strlen($allowed_dynamic_prefix))));
        if (!$is_allowed) {
            $errors[] = "unknown_or_disallowed_key:$key";
            continue;
        }
        // The active SEO plugin's own role rule for this group of settings.
        if (!bulkseme_user_can_seo_group(bulkseme_field_group($key))) {
            $errors[] = "forbidden_field:$key";
            continue;
        }
        $is_url = in_array($key, ['_yoast_wpseo_canonical', '_yoast_wpseo_opengraph-image', '_yoast_wpseo_twitter-image',
                                  'rank_math_canonical_url', 'rank_math_facebook_image', 'rank_math_twitter_image'], true);
        if (is_array($value) && in_array($key, ['rank_math_robots', 'rank_math_advanced_robots'], true)) {
            $value = bulkseme_clean_list($value);
        } else {
            $value = bulkseme_scalar_text($value);
            if ($value === null) {
                $errors[] = "invalid_value_type:$key";
                continue;
            }
            $value = $is_url ? bulkseme_sanitize_url($value) : bulkseme_sanitize_seo_text($value);
        }
        update_post_meta($post_id, $key, $value);
    }
    return ['ok' => empty($errors), 'errors' => $errors];
}
