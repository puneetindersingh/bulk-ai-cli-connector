<?php
/**
 * Plugin Name: Bulk SEO Meta Editor for AI Agents
 * Plugin URI:  https://github.com/puneetindersingh/bulk-seo-meta-editor-for-ai-agents
 * Description: Bulk-update Yoast SEO or Rank Math meta tags via REST API. Designed for AI agents (Claude, ChatGPT, Perplexity) and automation scripts. Auto-detects the active SEO plugin. Supports posts, pages, custom post types, taxonomy term archives (categories, tags, custom taxonomies), and CPT archive pages. Includes CSV import/export and a bundled MCP server for one-command Claude Code / Claude Desktop integration.
 * Version: 1.8.2
 * Author: Puneet Singh
 * Author URI: https://github.com/puneetindersingh
 * License: GPL-2.0-or-later
 * Text Domain: bulk-seo-meta-editor-for-ai-agents
 */

if (!defined('ABSPATH')) exit;

// Keep in sync with the `Version:` header line above on every release.
define('BULKSEME_VERSION', '1.8.2');

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

        if ($type === 'array') {
            // Rank Math robots arrays — stored as serialized string[] in the
            // database, exposed in REST as a JSON array of strings.
            $args['show_in_rest'] = [
                'schema' => [
                    'type'  => 'array',
                    'items' => ['type' => 'string'],
                ],
            ];
        } else {
            $args['show_in_rest'] = true;
        }

        if (in_array($key, $url_keys, true)) {
            $args['sanitize_callback'] = 'esc_url_raw';
        }

        return $args;
    };

    // ---- Detect which SEO plugin is active ----------------------------------
    // Constant checks are the lightest — both plugins set these on init.
    $yoast_active    = defined('WPSEO_VERSION')    || class_exists('WPSEO_Options');
    $rankmath_active = defined('RANK_MATH_VERSION') || class_exists('RankMath\\Helper');

    if (!$yoast_active && !$rankmath_active) {
        // Neither plugin detected — nothing to expose. Bail silently rather
        // than registering inert meta keys that would pollute REST responses.
        return;
    }

    // Register against every public post type — covers post, page, product, CPTs.
    $post_types = get_post_types(['public' => true], 'names');

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
    // active plugin — Yoast uses _yoast_wpseo_primary_category which is
    // already covered above.
    if ($rankmath_active) {
        $taxonomies = get_taxonomies(['public' => true], 'names');
        foreach ($post_types as $post_type) {
            $pt_taxonomies = array_intersect($taxonomies, get_object_taxonomies($post_type));
            foreach ($pt_taxonomies as $taxonomy) {
                $key = 'rank_math_primary_' . $taxonomy;
                register_post_meta($post_type, $key, [
                    'show_in_rest'  => true,
                    'single'        => true,
                    'type'          => 'integer',
                    'auth_callback' => $auth_callback,
                ]);
            }
        }
    }

}, 20); // After Yoast and Rank Math register their own meta.

// =============================================================================
// REST surface
// =============================================================================
// Namespace: seo-meta-bridge/v1
//   GET  /status          Detection — which SEO plugin is active
//   POST /bulk            Update SEO meta on up to 100 posts in one call
//   GET  /export          Stream all posts' SEO meta as CSV
//   POST /import          Bulk update from CSV (upload or JSON rows)
// =============================================================================

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
        $cell = (string) $cell;
        if (preg_match('/[",\r\n]/', $cell)) {
            $cell = '"' . str_replace('"', '""', $cell) . '"';
        }
        $out[] = $cell;
    }
    // RFC 4180: CRLF terminators so multi-line cells parse correctly in Excel/Sheets/LibreOffice.
    return implode(',', $out) . "\r\n";
}

/**
 * Term-meta alias map for the active SEO plugin. Yoast term keys differ
 * from postmeta keys (no "_yoast_wpseo_" prefix — they live in the
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
        // term's current values first — that preserves every other field.
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
        // old meta description (verified on a real WP/Cloudflare site —
        // page cache cleared, but meta unchanged because Yoast served a
        // stale Indexable). Fire Yoast's own term-save signal so its
        // Indexable_Term_Watcher rebuilds the cached row for this term.
        // No-op when Yoast isn't installed; safe when the action isn't
        // hooked. Wrapped in function_exists for older Yoasts that
        // don't ship this hook.
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Yoast SEO's own documented integration hook; firing it triggers their Indexable_Term_Watcher cache rebuild. Not a hook we own.
        do_action('wpseo_save_taxonomy_meta', (int) $term_id, (string) $taxonomy);
        // Belt-and-braces: also fire WordPress's own edited_term hook —
        // Yoast's watcher listens to that as a fallback, and a few SEO
        // caches/CDN integrations (W3TC, WPRocket, Cloudflare APO) listen
        // for it to purge per-URL caches. Args: term_id, term_taxonomy_id,
        // taxonomy. We don't have term_taxonomy_id handy; pass term_id —
        // every consumer we care about reads $term_id, not $tt_id.
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress core action; firing it is the standard way to signal third-party caches that a term changed.
        do_action('edited_term', (int) $term_id, (int) $term_id, (string) $taxonomy);
        // Direct indexable wipe as final safety net — if the Yoast classes
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
            } catch (\Throwable $e) { /* swallow — best-effort */ }
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
    $active = bulkseme_active_term_keys();
    if (!$active['plugin']) {
        return ['ok' => false, 'errors' => ['no_seo_plugin']];
    }
    $alias_to_meta = $active['keys'];
    $allowed       = array_values($alias_to_meta);
    $url_keys      = [
        'wpseo_canonical', 'wpseo_opengraph-image', 'wpseo_twitter-image',
        'rank_math_canonical_url', 'rank_math_facebook_image', 'rank_math_twitter_image',
    ];

    $errors = [];
    foreach ($meta as $key => $value) {
        if (isset($alias_to_meta[$key])) {
            $key = $alias_to_meta[$key];
        }
        if (!in_array($key, $allowed, true)) {
            $errors[] = "unknown_or_disallowed_key:$key";
            continue;
        }
        if (in_array($key, $url_keys, true)) {
            $value = esc_url_raw($value);
        } elseif (is_string($value)) {
            $value = sanitize_text_field($value);
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
 * format consumers see — same shape as post/term keys for round-trip.
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
 * This plugin does not define or own this option — Rank Math does. This
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
 * Registry of "global" SEO scopes — non-post, non-term, non-CPT-archive
 * resources that carry their own title/description settings stored in the
 * SEO plugin's option arrays. Each scope maps to a Yoast key set (in
 * `wpseo_titles`) and a Rank Math key set (in `rank-math-options-titles`).
 *
 * Use the alias on the wire (`title`, `description`, etc.); the plugin
 * translates to the storage key for the active SEO plugin.
 *
 * To add a new scope: drop another entry into this array. No other code
 * change needed — bulk/export/import dispatch via this registry.
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
 * date_archive, search, p404, home). All globals are admin-only —
 * `manage_options` cap — because they affect site-wide SEO settings, not
 * a single post or term.
 */
function bulkseme_apply_global_update($scope, $meta) {
    $scopes = bulkseme_global_scopes();
    if (!isset($scopes[$scope])) {
        return ['ok' => false, 'errors' => ['unknown_scope']];
    }
    if (!current_user_can('manage_options')) {
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
        if (is_string($value)) {
            $value = sanitize_text_field($value);
        }
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
 * editing them is a site-settings change — same bar as the global scopes.
 */
function bulkseme_apply_archive_update($post_type, $meta) {
    if (!post_type_exists($post_type)) {
        return ['ok' => false, 'errors' => ['unknown_post_type']];
    }
    $pt = get_post_type_object($post_type);
    if (!$pt || empty($pt->has_archive)) {
        return ['ok' => false, 'errors' => ['post_type_has_no_archive']];
    }
    if (!current_user_can('manage_options')) {
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
        if (is_string($value)) {
            $value = sanitize_text_field($value);
        }
        bulkseme_archive_set_value($post_type, $key, $value, $active['plugin']);
    }
    return ['ok' => empty($errors), 'errors' => $errors];
}

/**
 * Apply a meta update to one post. Returns ['ok' => bool, 'errors' => [...]].
 * Permission is enforced per-post; the meta keys themselves are validated
 * against the active plugin's known fields to stop arbitrary postmeta writes.
 */
function bulkseme_apply_update($post_id, $meta) {
    $post = get_post($post_id);
    if (!$post) {
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

        // Accept friendly aliases (title, description, focus_kw, ...) and translate
        // to the active plugin's raw meta key. Lets /export columns round-trip
        // through /bulk without manual key remapping.
        if (isset($alias_to_meta[$key])) {
            $key = $alias_to_meta[$key];
        }
        $is_allowed = in_array($key, $allowed, true)
            || ($allowed_dynamic_prefix && strpos($key, $allowed_dynamic_prefix) === 0);
        if (!$is_allowed) {
            $errors[] = "unknown_or_disallowed_key:$key";
            continue;
        }
        if (in_array($key, ['_yoast_wpseo_canonical', '_yoast_wpseo_opengraph-image', '_yoast_wpseo_twitter-image',
                             'rank_math_canonical_url', 'rank_math_facebook_image', 'rank_math_twitter_image'], true)) {
            $value = esc_url_raw($value);
        } elseif (is_string($value)) {
            $value = sanitize_text_field($value);
        }
        update_post_meta($post_id, $key, $value);
    }
    return ['ok' => empty($errors), 'errors' => $errors];
}

// =============================================================================
// Custom JSON-LD schema (per post/page)
// =============================================================================
// Self-contained module. Lets an AI agent store a block of JSON-LD against a
// post and have it rendered in the page's structured data. We do NOT echo any
// markup ourselves. Instead we hand the decoded nodes to whichever SEO plugin
// is active so it encodes and prints them inside its own schema graph:
//   * Yoast SEO  -> wpseo_schema_graph filter
//   * Rank Math  -> rank_math/json_ld filter
// Two upsides: (1) no output-escaping surface in our code, the SEO plugin owns
// encoding; (2) the nodes join the existing @graph instead of creating a second
// competing block, so there is no duplicate-schema conflict.
//
// Storage (prefixed, protected postmeta):
//   _bulkseme_schema_jsonld  raw JSON-LD string (validated on write)
//   _bulkseme_schema_mode    'add' (merge into the graph) or 'replace'
//                            (our nodes become the whole graph for that page)

/**
 * True if any string key or string value anywhere in the decoded schema holds a
 * raw HTML angle bracket. A < or > has no legitimate place in a JSON-LD document
 * (text properties are plain text and URLs are percent-encoded), and it is the
 * one character class that can break out of the
 * <script type="application/ld+json"> block the schema is printed into. The
 * schema rides the active SEO plugin's own encoder at output time, and neither
 * Yoast nor Rank Math escapes object *keys* (Rank Math also prints with
 * JSON_UNESCAPED_SLASHES), so a stored </script> sequence would be emitted
 * verbatim. We refuse to store it. See bulkseme_schema_strip_markup() for the
 * matching output-side net that also neutralises anything a pre-1.8.2 install
 * may already have saved.
 */
function bulkseme_schema_contains_markup($data) {
    if (is_string($data)) {
        return strpbrk($data, '<>') !== false;
    }
    if (is_array($data)) {
        foreach ($data as $key => $value) {
            if (is_string($key) && strpbrk($key, '<>') !== false) {
                return true;
            }
            if (bulkseme_schema_contains_markup($value)) {
                return true;
            }
        }
    }
    return false;
}

/**
 * Recursively remove < and > from every string key and string value in a schema
 * structure. Defence in depth on the output path: a payload saved by an older
 * vulnerable build renders inert without the owner having to re-save the post.
 */
function bulkseme_schema_strip_markup($data) {
    if (is_string($data)) {
        return str_replace(array('<', '>'), '', $data);
    }
    if (!is_array($data)) {
        return $data;
    }
    $clean = array();
    foreach ($data as $key => $value) {
        if (is_string($key)) {
            $key = str_replace(array('<', '>'), '', $key);
        }
        $clean[$key] = bulkseme_schema_strip_markup($value);
    }
    return $clean;
}

/**
 * Validate and store a JSON-LD blob against a post. An empty value clears it.
 * Returns true on success, or a short error string on invalid JSON.
 *
 * The value is re-encoded canonically and wp_slash()'d before saving because
 * update_metadata() runs wp_unslash() on the value; without the matching slash,
 * backslash escapes inside the JSON (\", \n, \uXXXX) would be stripped and
 * corrupt the stored document.
 */
function bulkseme_store_schema($post_id, $value) {
    $raw = is_string($value) ? trim($value) : '';
    if ($raw === '') {
        delete_post_meta($post_id, '_bulkseme_schema_jsonld');
        return true;
    }
    $decoded = json_decode($raw, true);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
        return 'invalid_json_schema';
    }
    // Refuse raw HTML angle brackets: they are the stored-XSS breakout vector and
    // never occur in valid JSON-LD. See bulkseme_schema_contains_markup().
    if (bulkseme_schema_contains_markup($decoded)) {
        return 'schema_contains_markup';
    }
    $canonical = wp_json_encode($decoded, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($canonical)) {
        return 'invalid_json_schema';
    }
    update_post_meta($post_id, '_bulkseme_schema_jsonld', wp_slash($canonical));
    return true;
}

/**
 * Decode a post's stored JSON-LD into a flat list of node arrays, ready to drop
 * into a schema @graph. Accepts three author shapes:
 *   1. a full document  { "@context": "...", "@graph": [ ...nodes ] }
 *   2. a bare list       [ ...nodes ]
 *   3. a single node     { "@type": "FAQPage", ... }
 * Per-node "@context" is stripped because the SEO plugin sets one top-level
 * context for the whole graph.
 */
function bulkseme_schema_nodes_for_post($post_id) {
    $raw = get_post_meta($post_id, '_bulkseme_schema_jsonld', true);
    if (!is_string($raw) || trim($raw) === '') {
        return [];
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded) || empty($decoded)) {
        return [];
    }

    if (isset($decoded['@graph']) && is_array($decoded['@graph'])) {
        $nodes = $decoded['@graph'];
    } elseif (array_keys($decoded) === range(0, count($decoded) - 1)) {
        $nodes = $decoded; // bare numeric list of nodes
    } else {
        $nodes = [$decoded]; // single node object
    }

    $clean = [];
    foreach ($nodes as $node) {
        if (!is_array($node) || empty($node)) {
            continue;
        }
        unset($node['@context']);
        $clean[] = bulkseme_schema_strip_markup($node);
    }
    return $clean;
}

/**
 * 'add' (default) or 'replace' for a post's stored schema.
 */
function bulkseme_schema_mode_for_post($post_id) {
    return get_post_meta($post_id, '_bulkseme_schema_mode', true) === 'replace' ? 'replace' : 'add';
}

/**
 * The schema nodes + mode for the post currently being rendered. Returns empty
 * nodes for non-singular views (archives, search, home), where per-post schema
 * does not apply.
 */
function bulkseme_current_schema() {
    if (!is_singular()) {
        return ['mode' => 'add', 'nodes' => []];
    }
    $post_id = get_queried_object_id();
    if (!$post_id) {
        return ['mode' => 'add', 'nodes' => []];
    }
    return [
        'mode'  => bulkseme_schema_mode_for_post($post_id),
        'nodes' => bulkseme_schema_nodes_for_post($post_id),
    ];
}

// ---- Yoast SEO: merge our nodes into its @graph -----------------------------
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Yoast SEO's own public Schema API filter; we are a consumer of it, not the owner.
add_filter('wpseo_schema_graph', function ($graph, $context) {
    $schema = bulkseme_current_schema();
    if (empty($schema['nodes'])) {
        return $graph;
    }
    if ($schema['mode'] === 'replace') {
        return array_values($schema['nodes']);
    }
    return array_merge(is_array($graph) ? $graph : [], $schema['nodes']);
}, 11, 2);

// ---- Rank Math: merge our nodes into its JSON-LD data ------------------------
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Rank Math's own public JSON-LD filter; we are a consumer of it, not the owner.
add_filter('rank_math/json_ld', function ($data, $jsonld) {
    $schema = bulkseme_current_schema();
    if (empty($schema['nodes'])) {
        return $data;
    }
    if (!is_array($data) || $schema['mode'] === 'replace') {
        $data = [];
    }
    $i = 0;
    foreach ($schema['nodes'] as $node) {
        $data['bulkseme_schema_' . $i] = $node;
        $i++;
    }
    return $data;
}, 99, 2);

/**
 * Resolve an image URL to a media-library attachment ID, tolerant of the
 * URL forms WordPress actually serves on the front end.
 *
 * attachment_url_to_postid() only matches the exact `_wp_attached_file` URL.
 * It misses, for almost every real content photo:
 *   - size crops:        foo-1024x683.jpg, foo-300x200.jpg
 *   - the -scaled big-image original WP stores since 5.3 (foo-scaled.jpg),
 *     when the page references the un-scaled foo.jpg (or vice-versa)
 *   - CDN / different-host URLs that keep the /wp-content/uploads/ path
 *   - cache-busting query strings
 *
 * Strategy, cheapest first:
 *   1. core resolver on the URL, then query-stripped, then size-stripped
 *   2. exact `_wp_attached_file` match on the path reduced to its base file,
 *      trying both the plain and the -scaled variant
 *   3. unambiguous basename match (only when exactly one attachment owns it)
 *
 * @return array [int attachment_id (0 = not found), string matched_via]
 */
function bulkseme_resolve_attachment_id($url) {
    $url = trim((string) $url);
    if ($url === '') {
        return [0, ''];
    }

    // ---- 1. core resolver, with query + size-suffix fallbacks --------------
    $no_qs   = strtok($url, '?#');
    $url_base = ($no_qs !== false && $no_qs !== '') ? $no_qs : $url;

    $candidates_url = ['url_direct' => $url];
    if ($url_base !== $url) {
        $candidates_url['url_no_query'] = $url_base;
    }
    $stripped_size = preg_replace('#-\d+x\d+(\.[a-zA-Z0-9]+)$#', '$1', $url_base);
    if ($stripped_size !== $url_base) {
        $candidates_url['url_stripped_size'] = $stripped_size;
    }
    foreach ($candidates_url as $via => $u) {
        $aid = attachment_url_to_postid($u);
        if ($aid) {
            return [(int) $aid, $via];
        }
    }

    // ---- 2. meta match on the relative upload path -------------------------
    // Derive the path after the uploads dir so CDN / alternate hosts that keep
    // the /wp-content/uploads/<...> structure still resolve.
    $uploads   = wp_get_upload_dir();
    $base_path = wp_parse_url((string) ($uploads['baseurl'] ?? ''), PHP_URL_PATH); // /wp-content/uploads
    $u_path    = wp_parse_url($url_base, PHP_URL_PATH);
    $rel = '';
    if ($base_path && $u_path && ($pos = strpos($u_path, $base_path)) !== false) {
        $rel = ltrim(substr($u_path, $pos + strlen($base_path)), '/');
    } elseif ($u_path && ($pos = strpos($u_path, '/uploads/')) !== false) {
        // Fallback for custom uploads dirs / CDN rewrites that still say /uploads/.
        $rel = ltrim(substr($u_path, $pos + strlen('/uploads/')), '/');
    }
    if ($rel === '') {
        return [0, ''];
    }

    // Reduce to the base file: strip size crop, then strip -scaled.
    $no_size = preg_replace('#-\d+x\d+(\.[a-zA-Z0-9]+)$#', '$1', $rel);
    $base    = preg_replace('#-scaled(\.[a-zA-Z0-9]+)$#', '$1', $no_size);
    $ext     = '';
    if (preg_match('#(\.[a-zA-Z0-9]+)$#', $base, $m)) {
        $ext = $m[1];
    }
    $base_noext = $ext !== '' ? substr($base, 0, -strlen($ext)) : $base;

    // Exact match on _wp_attached_file against both the plain and the -scaled
    // stored file. WP_Query keeps this on the object cache and out of raw SQL.
    $path_candidates = array_values(array_unique(array_filter([
        $base,                          // 2024/03/hero-team.jpg
        $base_noext . '-scaled' . $ext, // 2024/03/hero-team-scaled.jpg
        $rel,                           // the raw relative path, just in case
    ])));
    if ($path_candidates) {
        $found = get_posts([
            'post_type'              => 'attachment',
            'post_status'            => 'inherit',
            'posts_per_page'         => 1,
            'fields'                 => 'ids',
            'no_found_rows'          => true,
            'update_post_term_cache' => false,
            'meta_query'             => [[
                'key'     => '_wp_attached_file',
                'value'   => $path_candidates,
                'compare' => 'IN',
            ]],
        ]);
        if (!empty($found)) {
            return [(int) $found[0], 'db_path_match'];
        }
    }

    // ---- 3. unambiguous basename match -------------------------------------
    // Last resort for year/month folder drift. Only trust it when exactly one
    // attachment owns the filename, so we never guess between duplicates.
    $basename       = basename($base);
    $basename_noext = $ext !== '' ? substr($basename, 0, -strlen($ext)) : $basename;
    if ($basename !== '') {
        $found = get_posts([
            'post_type'              => 'attachment',
            'post_status'            => 'inherit',
            'posts_per_page'         => 2,
            'fields'                 => 'ids',
            'no_found_rows'          => true,
            'update_post_term_cache' => false,
            'meta_query'             => [
                'relation' => 'OR',
                ['key' => '_wp_attached_file', 'value' => '/' . $basename, 'compare' => 'LIKE'],
                ['key' => '_wp_attached_file', 'value' => '/' . $basename_noext . '-scaled' . $ext, 'compare' => 'LIKE'],
            ],
        ]);
        if (count($found) === 1) {
            return [(int) $found[0], 'db_filename_match'];
        }
    }

    return [0, ''];
}

add_action('rest_api_init', function () {

    // Route-level gate: every route requires an authenticated user with at
    // least edit_posts. This is only the outer door — each handler enforces
    // object-level caps again per item (edit_post for posts/attachments,
    // edit_term for terms, manage_options for site-wide archive/global
    // settings), because bulk payloads mix objects the caller may and may
    // not be allowed to touch.
    $perm = function () { return current_user_can('edit_posts'); };

    // -------- /status --------------------------------------------------------
    register_rest_route('seo-meta-bridge/v1', '/status', [
        'methods'             => 'GET',
        'permission_callback' => $perm,
        'callback'            => function () {
            $active         = bulkseme_active_keys();
            $term_active    = bulkseme_active_term_keys();
            $archive_active = bulkseme_active_archive_keys();
            $global_scopes  = [];
            foreach (array_keys(bulkseme_global_scopes()) as $scope) {
                $sa = bulkseme_global_active_keys($scope);
                if ($sa['plugin']) {
                    $global_scopes[$scope] = $sa['keys'];
                }
            }
            return [
                'yoast'             => defined('WPSEO_VERSION')    || class_exists('WPSEO_Options'),
                'rankmath'          => defined('RANK_MATH_VERSION') || class_exists('RankMath\\Helper'),
                'active'            => $active['plugin'],
                'fields'            => $active['keys'],
                'term_fields'       => $term_active['keys'],
                'archive_fields'    => $archive_active['keys'],
                'global_scopes'     => $global_scopes,
                'supports_terms'    => !empty($term_active['plugin']),
                'supports_archives' => !empty($archive_active['plugin']),
                'supports_globals'  => !empty($global_scopes),
                'supports_alts'     => true,
                'supports_schema'   => true,
                'schema_fields'     => [
                    'schema'      => '_bulkseme_schema_jsonld',
                    'schema_mode' => '_bulkseme_schema_mode',
                ],
                'version'           => BULKSEME_VERSION,
            ];
        },
    ]);

    // -------- /schema --------------------------------------------------------
    // GET /schema?id=123 — read back the custom JSON-LD stored against a post
    // (plus its add/replace mode). Useful for an agent to verify what it wrote,
    // or to audit which posts already carry custom schema. Decoded so callers
    // get an object, not a re-encoded string.
    register_rest_route('seo-meta-bridge/v1', '/schema', [
        'methods'             => 'GET',
        'permission_callback' => $perm,
        'args'                => [
            'id' => [
                'required'          => true,
                'sanitize_callback' => 'absint',
            ],
        ],
        'callback'            => function (WP_REST_Request $req) {
            $id = (int) $req->get_param('id');
            if (!$id || !get_post($id)) {
                return new WP_Error('post_not_found', 'post not found', ['status' => 404]);
            }
            if (!current_user_can('edit_post', $id)) {
                return new WP_Error('forbidden', 'cannot edit this post', ['status' => 403]);
            }
            $raw = get_post_meta($id, '_bulkseme_schema_jsonld', true);
            $decoded = (is_string($raw) && $raw !== '') ? json_decode($raw, true) : null;
            return [
                'id'     => $id,
                'mode'   => bulkseme_schema_mode_for_post($id),
                'has_schema' => !empty($decoded),
                'schema' => $decoded,
            ];
        },
    ]);

    // -------- /bulk ----------------------------------------------------------
    // Body: { items: [{ id: 123, meta: { ...field => value } }, ...] }
    register_rest_route('seo-meta-bridge/v1', '/bulk', [
        'methods'             => 'POST',
        'permission_callback' => $perm,
        'callback'            => function (WP_REST_Request $req) {
            $items = $req->get_param('items');
            if (!is_array($items)) {
                return new WP_Error('invalid_payload', 'items must be an array', ['status' => 400]);
            }
            if (count($items) > 100) {
                return new WP_Error('too_many', 'max 100 items per request', ['status' => 400]);
            }
            $results = [];
            foreach ($items as $item) {
                $id   = isset($item['id']) ? (int) $item['id'] : 0;
                $meta = isset($item['meta']) && is_array($item['meta']) ? $item['meta'] : [];
                $kind_raw = isset($item['kind']) ? (string) $item['kind'] : 'post';
                $known_kinds = array_merge(['term', 'cpt_archive'], array_keys(bulkseme_global_scopes()));
                $kind = in_array($kind_raw, $known_kinds, true) ? $kind_raw : 'post';

                // Singleton "global" SEO scopes — author_archive, date_archive,
                // search, p404, home. No id, no taxonomy/post_type — the kind
                // itself names the resource.
                if (isset(bulkseme_global_scopes()[$kind])) {
                    if (!$meta) {
                        $results[] = ['id' => 0, 'kind' => $kind, 'status' => 'error', 'errors' => ['missing_meta']];
                        continue;
                    }
                    $r = bulkseme_apply_global_update($kind, $meta);
                    $results[] = [
                        'id'     => 0,
                        'kind'   => $kind,
                        'status' => $r['ok'] ? 'ok' : 'error',
                        'errors' => $r['errors'],
                    ];
                    continue;
                }

                if ($kind === 'cpt_archive') {
                    // CPT archive pages have no row ID — they're a synthetic
                    // resource keyed on post_type. Don't enforce id != 0.
                    $post_type = isset($item['post_type']) ? sanitize_key($item['post_type']) : '';
                    if (!$post_type || !$meta) {
                        $results[] = ['id' => 0, 'kind' => 'cpt_archive', 'post_type' => $post_type, 'status' => 'error', 'errors' => ['missing_post_type_or_meta']];
                        continue;
                    }
                    $r = bulkseme_apply_archive_update($post_type, $meta);
                    $results[] = [
                        'id'        => 0,
                        'kind'      => 'cpt_archive',
                        'post_type' => $post_type,
                        'status'    => $r['ok'] ? 'ok' : 'error',
                        'errors'    => $r['errors'],
                    ];
                    continue;
                }

                if (!$id || !$meta) {
                    $results[] = ['id' => $id, 'kind' => $kind, 'status' => 'error', 'errors' => ['missing_id_or_meta']];
                    continue;
                }
                if ($kind === 'term') {
                    $taxonomy = isset($item['taxonomy']) ? sanitize_key($item['taxonomy']) : '';
                    if (!$taxonomy) {
                        $results[] = ['id' => $id, 'kind' => 'term', 'status' => 'error', 'errors' => ['missing_taxonomy']];
                        continue;
                    }
                    $r = bulkseme_apply_term_update($id, $taxonomy, $meta);
                    $results[] = [
                        'id'       => $id,
                        'kind'     => 'term',
                        'taxonomy' => $taxonomy,
                        'status'   => $r['ok'] ? 'ok' : 'error',
                        'errors'   => $r['errors'],
                    ];
                } else {
                    $r = bulkseme_apply_update($id, $meta);
                    $results[] = [
                        'id'     => $id,
                        'kind'   => 'post',
                        'status' => $r['ok'] ? 'ok' : 'error',
                        'errors' => $r['errors'],
                    ];
                }
            }
            return ['count' => count($results), 'results' => $results];
        },
    ]);

    // -------- /bulk-alts -----------------------------------------------------
    // Body: { items: [{ image_url: "(full media-library image URL)", new_alt: "..." }, ...] }
    //
    // For each item: resolve the URL to an attachment via attachment_url_to_postid.
    // Strip any size suffix (-1024x768, -300x300) so a thumb URL still maps to
    // the parent attachment — that's where the canonical alt lives. Update
    // _wp_attachment_image_alt on the attachment post. Most themes read alt
    // via wp_get_attachment_image_alt() which pulls from this postmeta.
    //
    // We do NOT rewrite inline <img alt="..."> in post_content. Page builders
    // (Elementor, WPBakery, etc.) hard-code alt into block content; rewriting
    // post_content via DOM is fragile and risks breaking serialised blocks.
    register_rest_route('seo-meta-bridge/v1', '/bulk-alts', [
        'methods'             => 'POST',
        'permission_callback' => $perm,
        'callback'            => function (WP_REST_Request $req) {
            $items = $req->get_param('items');
            if (!is_array($items)) {
                return new WP_Error('invalid_payload', 'items must be an array', ['status' => 400]);
            }
            if (count($items) > 200) {
                return new WP_Error('too_many', 'max 200 items per request', ['status' => 400]);
            }

            $results = [];
            $changed_ids = [];
            foreach ($items as $item) {
                $url     = isset($item['image_url']) ? trim((string) $item['image_url']) : '';
                $new_alt = isset($item['new_alt'])   ? (string) $item['new_alt']         : '';
                if ($url === '') {
                    $results[] = ['image_url' => '', 'status' => 'error', 'errors' => ['missing_image_url']];
                    continue;
                }
                $clean = sanitize_text_field($new_alt);
                if (mb_strlen($clean) > 250) {
                    $clean = mb_substr($clean, 0, 250);
                }

                // Resolve the URL to an attachment, tolerant of size crops,
                // the -scaled big-image original, CDN hosts, and query strings.
                list($aid, $matched_via) = bulkseme_resolve_attachment_id($url);
                if (!$aid) {
                    $results[] = [
                        'image_url' => $url,
                        'status'    => 'skipped',
                        'errors'    => ['attachment_not_found'],
                        'hint'      => 'URL did not resolve to an attachment in the media library. The image is likely hard-coded into a page-builder block or theme template (common for SVG icons and hero images), or hot-linked from an external/CDN source — its alt text lives in the page builder, not the media library.',
                    ];
                    continue;
                }

                // Per-attachment permission: the route-level edit_posts check
                // grants access to the endpoint, but each write must also pass
                // edit_post for THIS attachment — so Authors/Contributors can
                // only touch alt text on media they are allowed to edit,
                // matching the wp-admin media library rules.
                if (!current_user_can('edit_post', $aid)) {
                    $results[] = [
                        'image_url'     => $url,
                        'status'        => 'error',
                        'errors'        => ['forbidden'],
                        'attachment_id' => $aid,
                    ];
                    continue;
                }

                $previous = get_post_meta($aid, '_wp_attachment_image_alt', true);
                if ((string) $previous === $clean) {
                    $results[] = [
                        'image_url'     => $url,
                        'status'        => 'unchanged',
                        'attachment_id' => $aid,
                        'matched_via'   => $matched_via,
                    ];
                    continue;
                }

                $ok = update_post_meta($aid, '_wp_attachment_image_alt', $clean);
                if ($ok === false) {
                    $results[] = [
                        'image_url'     => $url,
                        'status'        => 'error',
                        'errors'        => ['update_post_meta_failed'],
                        'attachment_id' => $aid,
                    ];
                    continue;
                }
                $changed_ids[] = $aid;
                $results[] = [
                    'image_url'     => $url,
                    'status'        => 'ok',
                    'attachment_id' => $aid,
                    'matched_via'   => $matched_via,
                    'previous_alt'  => (string) $previous,
                    'new_alt'       => $clean,
                ];
            }

            // Audit hook so site owners can pipe these into their own logger.
            // do_action so external plugins can subscribe; no built-in log file
            // (matches the existing /bulk endpoint's behavior — host-side
            // audit lives on the API caller, here we just expose the action).
            do_action('bulkseme_bulk_alts_completed', $results, get_current_user_id());

            return [
                'count'        => count($results),
                'changed'      => count($changed_ids),
                'results'      => $results,
            ];
        },
    ]);

    // -------- /export --------------------------------------------------------
    // GET /export?post_type=post,page&status=publish&limit=500&offset=0
    //           &include_terms=1&taxonomy=category,post_tag
    // Returns JSON { filename, row_count, csv } where `csv` holds the full
    // CSV body: id,url,post_type,status,post_title, plus the active plugin's
    // SEO fields, plus trailing `kind` and `taxonomy` columns. Clients save
    // the `csv` string to a file themselves. (Since 1.7.0 the CSV is returned
    // in the JSON body rather than streamed as raw bytes.)
    //
    // Every row is capability-filtered before it is written into the CSV, using
    // the same cap the corresponding write path enforces: edit_post per post,
    // edit_term per term, manage_options for CPT-archive settings. Rows the
    // caller may not manage are omitted rather than erroring, so a partially
    // privileged caller still gets a valid CSV of exactly what they may edit,
    // and `row_count` reports what was actually emitted.
    register_rest_route('seo-meta-bridge/v1', '/export', [
        'methods'             => 'GET',
        'permission_callback' => $perm,
        'callback'            => function (WP_REST_Request $req) {
            $active      = bulkseme_active_keys();
            $term_active = bulkseme_active_term_keys();
            if (!$active['plugin'] && !$term_active['plugin']) {
                return new WP_Error('no_seo_plugin', 'No SEO plugin active', ['status' => 400]);
            }

            $post_types_param = $req->get_param('post_type') ?: 'post,page';
            // 'any' is a magic WP_Query value meaning all public types except
            // attachment/revision. Pass it as a string, not an array.
            if (trim($post_types_param) === 'any') {
                $post_types = 'any';
            } else {
                $post_types = array_map('trim', explode(',', $post_types_param));
            }
            $status           = $req->get_param('status') ?: 'publish,draft';
            $limit            = min(2000, max(1, (int) ($req->get_param('limit') ?: 500)));
            $offset           = max(0, (int) ($req->get_param('offset') ?: 0));
            $include_terms    = (string) $req->get_param('include_terms') === '1';
            $include_archives = (string) $req->get_param('include_archives') === '1';
            $taxonomies_param = $req->get_param('taxonomy');
            $taxonomies = $taxonomies_param
                ? array_map('trim', explode(',', $taxonomies_param))
                : array_values(get_taxonomies(['public' => true], 'names'));

            // Author scoping for /export. When the caller lacks edit_others_posts
            // (i.e. Author / Contributor roles), restrict the query to posts they
            // own — mirrors the wp-admin Posts list, which hides other users'
            // drafts from these roles. This is only a cheap pre-filter so we do
            // not load rows we are about to discard; the authoritative gate is
            // the per-post edit_post check applied to every emitted row below.
            $query_args = [
                'post_type'      => $post_types,
                'post_status'    => array_map('trim', explode(',', $status)),
                'posts_per_page' => $limit,
                'offset'         => $offset,
                'orderby'        => 'ID',
                'order'          => 'ASC',
                'no_found_rows'  => true,
            ];
            if (!current_user_can('edit_others_posts')) {
                $query_args['author'] = get_current_user_id();
            }
            $query = new WP_Query($query_args);

            // Helper character-count columns inserted next to title and description
            // so the CSV is editable-ready (LibreOffice/Excel: spot over-limit cells
            // at a glance). Static at export time — re-export to refresh after edits.
            // Default on; pass ?lengths=0 to get the original column shape.
            $include_lengths = $req->get_param('lengths') !== '0';

            // 'post_title' disambiguates the WP post title from the SEO 'title'
            // alias which maps to _yoast_wpseo_title / rank_math_title.
            $headers = ['id', 'url', 'post_type', 'status', 'post_title'];
            $field_aliases = $active['plugin'] ? array_keys($active['keys']) : array_keys($term_active['keys']);
            foreach ($field_aliases as $alias) {
                $headers[] = $alias;
                if ($include_lengths && $alias === 'title')       $headers[] = 'title_chars';
                if ($include_lengths && $alias === 'description') $headers[] = 'desc_chars';
            }
            // Trailing kind/taxonomy columns let one CSV represent both posts
            // and taxonomy term archives in a single export.
            $headers[] = 'kind';
            $headers[] = 'taxonomy';

            // Pre-collect term rows before assembling the CSV body.
            //
            // Term rows carry the same SEO fields the /bulk and /import writers
            // gate behind the per-term edit_term meta cap, so reading them is
            // gated identically. A taxonomy registered with its own capabilities
            // (edit_terms => manage_woocommerce, a membership plugin's own cap,
            // etc.) is therefore respected here too: a caller who only holds
            // edit_posts gets no term rows at all, exactly as wp-admin shows
            // them no taxonomy screens.
            $term_rows = [];
            if ($include_terms && $term_active['plugin']) {
                foreach ($taxonomies as $tax_name) {
                    if (!taxonomy_exists($tax_name)) continue;
                    // Taxonomy-level pre-filter: skip the get_terms() query
                    // entirely when the caller cannot manage this taxonomy.
                    $tax_obj = get_taxonomy($tax_name);
                    if (!$tax_obj || !current_user_can($tax_obj->cap->edit_terms)) continue;
                    $terms = get_terms([
                        'taxonomy'   => $tax_name,
                        'hide_empty' => false,
                        'number'     => $limit,
                    ]);
                    if (is_wp_error($terms)) continue;
                    foreach ($terms as $t) {
                        // Authoritative per-term gate. edit_term is the meta cap
                        // map_meta_cap resolves against the individual term, so
                        // a map_meta_cap filter that restricts single terms is
                        // honoured rather than bypassed by the taxonomy check.
                        if (!current_user_can('edit_term', $t->term_id)) continue;
                        $term_rows[] = ['term' => $t, 'taxonomy' => $tax_name];
                    }
                }
            }

            // CPT archives — synthetic rows for each public CPT with has_archive=true.
            // post_type column carries the CPT slug; id stays 0 (archives have no
            // row ID); kind=cpt_archive flags the row for round-trip dispatch.
            //
            // CPT-archive SEO values are not per-object meta: they live in the
            // SEO plugin's site-wide settings option (wpseo_titles /
            // rank-math-options-titles), and wp-admin only exposes them on the
            // SEO plugin's settings screens, which are administrator-only.
            // bulkseme_apply_archive_update() already requires manage_options to
            // write them, so reading them requires the same cap — otherwise any
            // Author could read back site settings they can neither see nor edit.
            $archive_active = bulkseme_active_archive_keys();
            $archive_rows = [];
            if ($include_archives && $archive_active['plugin'] && current_user_can('manage_options')) {
                foreach (get_post_types(['public' => true, 'has_archive' => true], 'objects') as $pt_obj) {
                    if (empty($pt_obj->has_archive)) continue;
                    $link = get_post_type_archive_link($pt_obj->name);
                    if (!$link) continue;
                    $archive_rows[] = ['post_type' => $pt_obj->name, 'label' => $pt_obj->labels->name ?? $pt_obj->name, 'link' => $link];
                }
            }

            // Assemble the CSV in memory and RETURN it inside the JSON response
            // body. Nothing is echoed: REST clients read the `csv` field and
            // save it to a file themselves. Cells are CSV-quoted (RFC 4180) by
            // bulkseme_csv_line().
            $csv       = bulkseme_csv_line($headers);
            $row_count = 0;
            foreach ($query->posts as $p) {
                // Authoritative per-post read gate, matching the edit_post check
                // bulkseme_apply_update() enforces on the write side. The author
                // pre-filter above tests edit_others_posts, which is the core
                // 'post' capability only — a CPT registered with its own
                // capability_type can deny a user who passes it. edit_post is the
                // meta cap that resolves ownership, post status and per-type caps
                // together, so it is the check every emitted row must pass.
                if (!current_user_can('edit_post', $p->ID)) continue;
                $row = [$p->ID, get_permalink($p->ID), $p->post_type, $p->post_status, $p->post_title];
                // Walk $field_aliases, not $active['keys'], so the cells stay
                // positionally aligned with $headers even when the header set
                // was derived from a different scope's alias list.
                foreach ($field_aliases as $alias) {
                    $val = '';
                    if (isset($active['keys'][$alias])) {
                        $val = get_post_meta($p->ID, $active['keys'][$alias], true);
                        if (is_array($val)) $val = implode('|', $val);
                    }
                    $row[] = $val;
                    if ($include_lengths && ($alias === 'title' || $alias === 'description')) {
                        $row[] = mb_strlen((string) $val);
                    }
                }
                $row[] = 'post';  // kind
                $row[] = '';      // taxonomy (n/a for posts)
                $csv  .= bulkseme_csv_line($row);
                $row_count++;
            }
            if ($term_active['plugin']) {
                foreach ($term_rows as $tr) {
                    $t   = $tr['term'];
                    $tax = $tr['taxonomy'];
                    $link = get_term_link($t);
                    if (is_wp_error($link)) $link = '';
                    $row = [$t->term_id, $link, '', '', $t->name];
                    // Emit one cell per header alias. The term field set is not
                    // identical to the post field set (Yoast, for instance, has
                    // no per-term 'nofollow'), so iterating the term keys
                    // directly produced a short row: every value after the
                    // missing alias landed under the wrong header, and /import
                    // then discarded the row for having the wrong cell count.
                    // Aliases the term scope does not support emit empty.
                    foreach ($field_aliases as $alias) {
                        $val = '';
                        if (isset($term_active['keys'][$alias])) {
                            $val = bulkseme_term_get_value($t->term_id, $tax, $term_active['keys'][$alias], $term_active['plugin']);
                            if (is_array($val)) $val = implode('|', $val);
                        }
                        $row[] = $val;
                        if ($include_lengths && ($alias === 'title' || $alias === 'description')) {
                            $row[] = mb_strlen((string) $val);
                        }
                    }
                    $row[] = 'term';
                    $row[] = $tax;
                    $csv  .= bulkseme_csv_line($row);
                    $row_count++;
                }
            }
            if ($archive_active['plugin']) {
                // CPT-archive rows: id=0, post_type=<cpt_slug>, kind='cpt_archive'.
                // The "post_title" column carries the CPT label so the row is
                // human-recognisable in spreadsheets. Field-alias columns are
                // populated from each plugin's archive option storage.
                foreach ($archive_rows as $ar) {
                    $pt   = $ar['post_type'];
                    $row  = [0, $ar['link'], $pt, '', $ar['label']];
                    // Emit the SAME number of cells as $headers, matching the
                    // post-row column shape. The column aliases were derived
                    // from $active or $term_active (whichever exists) — archive
                    // aliases overlap on title/description but may not cover
                    // other fields. Map by alias name: if archive_active has
                    // the alias, emit its value; else emit empty (preserves
                    // CSV column count).
                    foreach ($field_aliases as $alias) {
                        $val = '';
                        if (isset($archive_active['keys'][$alias])) {
                            $val = bulkseme_archive_get_value($pt, $archive_active['keys'][$alias], $archive_active['plugin']);
                            if (is_array($val)) $val = implode('|', $val);
                        }
                        $row[] = $val;
                        if ($include_lengths && ($alias === 'title' || $alias === 'description')) {
                            $row[] = mb_strlen((string) $val);
                        }
                    }
                    $row[] = 'cpt_archive';
                    $row[] = '';  // taxonomy column repurposed empty for archive rows
                    $csv  .= bulkseme_csv_line($row);
                    $row_count++;
                }
            }

            return new WP_REST_Response([
                'filename'  => 'seo-meta-export.csv',
                'row_count' => $row_count,
                'csv'       => $csv,
            ], 200);
        },
    ]);

    // -------- /import --------------------------------------------------------
    // Two ways to call:
    //   1) JSON: { rows: [{ id, <field>: <value>, ... }, ...] }
    //   2) multipart upload: csv=@file.csv (with the same header row /export emits)
    register_rest_route('seo-meta-bridge/v1', '/import', [
        'methods'             => 'POST',
        'permission_callback' => $perm,
        'callback'            => function (WP_REST_Request $req) {
            $rows = [];

            // Multipart CSV upload?
            $files = $req->get_file_params();
            if (!empty($files['csv']['tmp_name']) && is_uploaded_file($files['csv']['tmp_name'])) {
                // Use WP_Filesystem rather than raw fopen/fread per WP coding
                // standards; pass through str_getcsv for line-by-line parsing.
                if (!function_exists('WP_Filesystem')) {
                    require_once ABSPATH . 'wp-admin/includes/file.php';
                }
                WP_Filesystem();
                global $wp_filesystem;
                $csv_text = $wp_filesystem->get_contents($files['csv']['tmp_name']);
                if ($csv_text !== false) {
                    // Strip leading UTF-8 BOM so the first header cell isn't "\xEF\xBB\xBFid"
                    // (which would silently drop every row's id on round-trip imports).
                    if (substr($csv_text, 0, 3) === "\xEF\xBB\xBF") {
                        $csv_text = substr($csv_text, 3);
                    }
                    $lines = preg_split('/\r\n|\r|\n/', $csv_text);
                    $hdr = null;
                    foreach ($lines as $line) {
                        if ($line === '' || $line === null) continue;
                        $cells = str_getcsv($line);
                        if ($hdr === null) {
                            $hdr = $cells;
                        } else {
                            // array_combine errors if counts differ — skip the bad row.
                            if (count($cells) === count($hdr)) {
                                $rows[] = array_combine($hdr, $cells);
                            }
                        }
                    }
                }
            } else {
                // JSON body
                $rows = $req->get_param('rows');
                if (!is_array($rows)) {
                    return new WP_Error('invalid_payload', 'Provide rows[] in JSON or csv file upload', ['status' => 400]);
                }
            }

            if (count($rows) > 2000) {
                return new WP_Error('too_many', 'max 2000 rows per import', ['status' => 400]);
            }

            $active         = bulkseme_active_keys();
            $term_active    = bulkseme_active_term_keys();
            $archive_active = bulkseme_active_archive_keys();
            // Trailing kind/taxonomy columns (v1.3.0+) flag term rows in a
            // mixed export. v1.2.x CSVs lack them — kind defaults to 'post'.
            // cpt_archive rows (v1.4.0+) reuse the post_type column for the
            // CPT slug and have id=0.
            $non_meta_cols = ['id', 'url', 'post_type', 'status', 'post_title', 'kind', 'taxonomy'];

            $results = [];
            foreach ($rows as $row) {
                $id   = isset($row['id']) ? (int) $row['id'] : 0;
                $kind_raw = isset($row['kind']) ? (string) $row['kind'] : 'post';
                $known_kinds = array_merge(['term', 'cpt_archive'], array_keys(bulkseme_global_scopes()));
                $kind = in_array($kind_raw, $known_kinds, true) ? $kind_raw : 'post';

                if (isset(bulkseme_global_scopes()[$kind])) {
                    $alias_to_meta = bulkseme_global_active_keys($kind)['keys'];
                    $meta = [];
                    foreach ($row as $col => $val) {
                        if (in_array($col, $non_meta_cols, true)) continue;
                        if ($val === null || $val === '') continue;
                        if (isset($alias_to_meta[$col])) {
                            $meta[$alias_to_meta[$col]] = $val;
                        } elseif (in_array($col, $alias_to_meta, true)) {
                            $meta[$col] = $val;
                        }
                    }
                    if (!$meta) {
                        $results[] = ['id' => 0, 'kind' => $kind, 'status' => 'noop', 'errors' => []];
                        continue;
                    }
                    $r = bulkseme_apply_global_update($kind, $meta);
                    $results[] = [
                        'id'     => 0,
                        'kind'   => $kind,
                        'status' => $r['ok'] ? 'ok' : 'error',
                        'errors' => $r['errors'],
                    ];
                    continue;
                }

                if ($kind === 'cpt_archive') {
                    $post_type = isset($row['post_type']) ? sanitize_key($row['post_type']) : '';
                    $alias_to_meta = $archive_active['keys'];
                    $meta = [];
                    foreach ($row as $col => $val) {
                        if (in_array($col, $non_meta_cols, true)) continue;
                        if ($val === null || $val === '') continue;
                        if (isset($alias_to_meta[$col])) {
                            $meta[$alias_to_meta[$col]] = $val;
                        } elseif (in_array($col, $alias_to_meta, true)) {
                            $meta[$col] = $val;
                        }
                    }
                    if (!$post_type) {
                        $results[] = ['id' => 0, 'kind' => 'cpt_archive', 'status' => 'error', 'errors' => ['missing_post_type']];
                        continue;
                    }
                    if (!$meta) {
                        $results[] = ['id' => 0, 'kind' => 'cpt_archive', 'post_type' => $post_type, 'status' => 'noop', 'errors' => []];
                        continue;
                    }
                    $r = bulkseme_apply_archive_update($post_type, $meta);
                    $results[] = [
                        'id'        => 0,
                        'kind'      => 'cpt_archive',
                        'post_type' => $post_type,
                        'status'    => $r['ok'] ? 'ok' : 'error',
                        'errors'    => $r['errors'],
                    ];
                    continue;
                }

                if (!$id) {
                    $results[] = ['id' => 0, 'kind' => $kind, 'status' => 'error', 'errors' => ['missing_id']];
                    continue;
                }
                $alias_to_meta = $kind === 'term' ? $term_active['keys'] : $active['keys'];
                $meta = [];
                foreach ($row as $col => $val) {
                    if (in_array($col, $non_meta_cols, true)) continue;
                    // Empty cells in a CSV mean "don't touch this field" — never overwrite
                    // an existing value with an empty string just because the column was blank.
                    if ($val === null || $val === '') continue;
                    if (isset($alias_to_meta[$col])) {
                        $meta[$alias_to_meta[$col]] = $val;
                    } elseif (in_array($col, $alias_to_meta, true)) {
                        // raw meta key was supplied
                        $meta[$col] = $val;
                    }
                    // unknown columns silently ignored — supports round-tripping export CSVs
                }
                if (!$meta) {
                    $results[] = ['id' => $id, 'kind' => $kind, 'status' => 'noop', 'errors' => []];
                    continue;
                }
                if ($kind === 'term') {
                    $taxonomy = isset($row['taxonomy']) ? sanitize_key($row['taxonomy']) : '';
                    if (!$taxonomy) {
                        $results[] = ['id' => $id, 'kind' => 'term', 'status' => 'error', 'errors' => ['missing_taxonomy']];
                        continue;
                    }
                    $r = bulkseme_apply_term_update($id, $taxonomy, $meta);
                    $results[] = [
                        'id'       => $id,
                        'kind'     => 'term',
                        'taxonomy' => $taxonomy,
                        'status'   => $r['ok'] ? 'ok' : 'error',
                        'errors'   => $r['errors'],
                    ];
                } else {
                    $r = bulkseme_apply_update($id, $meta);
                    $results[] = [
                        'id'     => $id,
                        'kind'   => 'post',
                        'status' => $r['ok'] ? 'ok' : 'error',
                        'errors' => $r['errors'],
                    ];
                }
            }
            return ['count' => count($results), 'results' => $results];
        },
    ]);
});
