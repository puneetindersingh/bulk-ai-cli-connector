<?php
/**
 * Plugin Name: Bulk SEO Meta Editor for AI Agents
 * Plugin URI:  https://github.com/puneetindersingh/bulk-seo-meta-editor-for-ai-agents
 * Description: Bulk-update Yoast SEO or Rank Math meta tags via REST API. Designed for AI agents (Claude, ChatGPT, Perplexity) and automation scripts. Auto-detects the active SEO plugin. Supports posts, pages, custom post types, taxonomy term archives (categories, tags, custom taxonomies), and CPT archive pages. Includes CSV import/export and a bundled MCP server for one-command Claude Code / Claude Desktop integration.
 * Version: 1.4.2
 * Author: Puneet Singh
 * Author URI: https://github.com/puneetindersingh
 * License: GPL-2.0-or-later
 * Text Domain: bulk-seo-meta-editor-for-ai-agents
 */

if (!defined('ABSPATH')) exit;

// Read version from this file's own plugin header so /status can never drift
// from the file's `Version:` line. Falls back to a literal if get_file_data
// isn't loaded yet (early bootstrap path).
if (!defined('SEO_META_BRIDGE_VERSION')) {
    if (function_exists('get_file_data')) {
        $sm_bridge_hdr = get_file_data(__FILE__, ['Version' => 'Version'], 'plugin');
        define('SEO_META_BRIDGE_VERSION', $sm_bridge_hdr['Version'] ?: '0.0.0');
        unset($sm_bridge_hdr);
    } else {
        define('SEO_META_BRIDGE_VERSION', '1.4.2');
    }
}

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

if (!function_exists('seo_meta_bridge_active_keys')) {
    /**
     * Keys we expose, grouped by SEO plugin. Returns the set the active plugin
     * uses. If both are active, Yoast wins (you should only run one).
     */
    function seo_meta_bridge_active_keys() {
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
}

if (!function_exists('seo_meta_bridge_csv_line')) {
    /**
     * Serialize one CSV row. Handles quoting/escaping the same way fputcsv
     * does, but as a pure string-builder so we don't have to fopen() a stream
     * (which Plugin Check flags as a filesystem operation).
     */
    function seo_meta_bridge_csv_line($cells) {
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
}

if (!function_exists('seo_meta_bridge_active_term_keys')) {
    /**
     * Term-meta alias map for the active SEO plugin. Yoast term keys differ
     * from postmeta keys (no "_yoast_wpseo_" prefix — they live in the
     * wpseo_taxonomy_meta option); Rank Math reuses the same key names as
     * postmeta but stores them in wp_termmeta.
     */
    function seo_meta_bridge_active_term_keys() {
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
}

if (!function_exists('seo_meta_bridge_term_get_value')) {
    /**
     * Read one term-meta value, abstracting Yoast (option-array) vs Rank Math
     * (wp_termmeta) storage.
     */
    function seo_meta_bridge_term_get_value($term_id, $taxonomy, $meta_key, $plugin) {
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
}

if (!function_exists('seo_meta_bridge_term_set_value')) {
    /**
     * Write one term-meta value. Yoast stores all term SEO inside a single
     * wpseo_taxonomy_meta option (NOT in wp_termmeta), so we read-modify-write
     * the option once per call.
     */
    function seo_meta_bridge_term_set_value($term_id, $taxonomy, $meta_key, $value, $plugin) {
        if ($plugin === 'rankmath') {
            update_term_meta($term_id, $meta_key, $value);
            return true;
        }
        if ($plugin === 'yoast') {
            $opt = get_option('wpseo_taxonomy_meta', []);
            if (!isset($opt[$taxonomy]) || !is_array($opt[$taxonomy])) {
                $opt[$taxonomy] = [];
            }
            if (!isset($opt[$taxonomy][$term_id]) || !is_array($opt[$taxonomy][$term_id])) {
                $opt[$taxonomy][$term_id] = [];
            }
            $opt[$taxonomy][$term_id][$meta_key] = $value;
            update_option('wpseo_taxonomy_meta', $opt);
            // Yoast 14+ caches rendered SEO meta in the yoast_indexable table.
            // Updating wpseo_taxonomy_meta directly bypasses Yoast's hook chain,
            // so the indexable stays stale and the FRONT-END keeps rendering the
            // old meta description (verified on a real WP/Cloudflare site —
            // page cache cleared, but meta unchanged because Yoast served a
            // stale Indexable). Fire Yoast's own term-save signal so its
            // Indexable_Term_Watcher rebuilds the cached row for this term.
            // No-op when Yoast isn't installed; safe when the action isn't
            // hooked. Wrapped in function_exists for older Yoasts that
            // don't ship this hook.
            do_action('wpseo_save_taxonomy_meta', (int) $term_id, (string) $taxonomy);
            // Belt-and-braces: also fire WordPress's own edited_term hook —
            // Yoast's watcher listens to that as a fallback, and a few SEO
            // caches/CDN integrations (W3TC, WPRocket, Cloudflare APO) listen
            // for it to purge per-URL caches. Args: term_id, term_taxonomy_id,
            // taxonomy. We don't have term_taxonomy_id handy; pass term_id —
            // every consumer we care about reads $term_id, not $tt_id.
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
}

if (!function_exists('seo_meta_bridge_apply_term_update')) {
    /**
     * Apply a meta update to one term. Mirrors seo_meta_bridge_apply_update()
     * but for taxonomy terms. Permission is enforced via the taxonomy's
     * edit_terms cap.
     */
    function seo_meta_bridge_apply_term_update($term_id, $taxonomy, $meta) {
        $term = get_term((int) $term_id, $taxonomy);
        if (!$term || is_wp_error($term)) {
            return ['ok' => false, 'errors' => ['term_not_found']];
        }
        $tax_obj = get_taxonomy($taxonomy);
        if (!$tax_obj) {
            return ['ok' => false, 'errors' => ['unknown_taxonomy']];
        }
        if (!current_user_can($tax_obj->cap->edit_terms)) {
            return ['ok' => false, 'errors' => ['forbidden']];
        }
        $active = seo_meta_bridge_active_term_keys();
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
            seo_meta_bridge_term_set_value($term_id, $taxonomy, $key, $value, $active['plugin']);
        }
        return ['ok' => empty($errors), 'errors' => $errors];
    }
}

if (!function_exists('seo_meta_bridge_active_archive_keys')) {
    /**
     * Archive-meta alias map. Yoast stores CPT-archive titles/descriptions as
     * keys inside the `wpseo_titles` option (e.g. `title-ptarchive-{ptype}`,
     * `metadesc-ptarchive-{ptype}`); Rank Math uses `rank-math-options-titles`
     * (e.g. `pt_{ptype}_archive_title`). The alias keys here are the wire
     * format consumers see — same shape as post/term keys for round-trip.
     */
    function seo_meta_bridge_active_archive_keys() {
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
}

if (!function_exists('seo_meta_bridge_archive_get_value')) {
    /**
     * Read one archive-meta value, abstracting Yoast's `wpseo_titles` option-
     * array vs Rank Math's `rank-math-options-titles` option-array storage.
     */
    function seo_meta_bridge_archive_get_value($post_type, $alias_or_key, $plugin) {
        if ($plugin === 'yoast') {
            $opt = get_option('wpseo_titles', []);
            // Accept either alias ("title") or full key ("title-ptarchive").
            $base = (strpos($alias_or_key, '-ptarchive') === false) ? $alias_or_key . '-ptarchive' : $alias_or_key;
            $key  = $base . '-' . $post_type;
            return isset($opt[$key]) ? $opt[$key] : '';
        }
        if ($plugin === 'rankmath') {
            $opt = get_option('rank-math-options-titles', []);
            $base = $alias_or_key;
            // Strip leading 'archive_' if user passed the full key.
            $base = preg_replace('/^archive_/', '', $base);
            $key  = 'pt_' . $post_type . '_archive_' . $base;
            return isset($opt[$key]) ? $opt[$key] : '';
        }
        return '';
    }
}

if (!function_exists('seo_meta_bridge_archive_set_value')) {
    /**
     * Write one archive-meta value via read-modify-write of the relevant
     * option array. Both Yoast and Rank Math keep all archive settings in a
     * single option, so we update the array and flush once per call.
     */
    function seo_meta_bridge_archive_set_value($post_type, $meta_key, $value, $plugin) {
        if ($plugin === 'yoast') {
            $opt = get_option('wpseo_titles', []);
            // meta_key is the Yoast alias ('title-ptarchive'); append the ptype.
            $key = $meta_key . '-' . $post_type;
            $opt[$key] = $value;
            update_option('wpseo_titles', $opt);
            return true;
        }
        if ($plugin === 'rankmath') {
            $opt = get_option('rank-math-options-titles', []);
            // meta_key is the Rank Math alias ('archive_title'); wrap with
            // pt_{ptype}_ prefix so we hit the right field for this CPT.
            $base = preg_replace('/^archive_/', '', $meta_key);
            $key  = 'pt_' . $post_type . '_archive_' . $base;
            $opt[$key] = $value;
            update_option('rank-math-options-titles', $opt);
            return true;
        }
        return false;
    }
}

if (!function_exists('seo_meta_bridge_global_scopes')) {
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
    function seo_meta_bridge_global_scopes() {
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
}

if (!function_exists('seo_meta_bridge_global_active_keys')) {
    /**
     * Alias map for one global scope under the active SEO plugin. Returns
     * shape: ['plugin' => 'yoast'|'rankmath'|null, 'keys' => [alias=>storage_key]].
     */
    function seo_meta_bridge_global_active_keys($scope) {
        $yoast    = defined('WPSEO_VERSION')    || class_exists('WPSEO_Options');
        $rankmath = defined('RANK_MATH_VERSION') || class_exists('RankMath\\Helper');
        $scopes   = seo_meta_bridge_global_scopes();
        if (!isset($scopes[$scope])) return ['plugin' => null, 'keys' => []];
        if ($yoast    && !empty($scopes[$scope]['yoast']))    return ['plugin' => 'yoast',    'keys' => $scopes[$scope]['yoast']];
        if ($rankmath && !empty($scopes[$scope]['rankmath'])) return ['plugin' => 'rankmath', 'keys' => $scopes[$scope]['rankmath']];
        return ['plugin' => null, 'keys' => []];
    }
}

if (!function_exists('seo_meta_bridge_global_get_value')) {
    function seo_meta_bridge_global_get_value($scope, $alias_or_key, $plugin) {
        $active = seo_meta_bridge_global_active_keys($scope);
        if (!$active['plugin']) return '';
        $key = isset($active['keys'][$alias_or_key]) ? $active['keys'][$alias_or_key] : $alias_or_key;
        $opt = $plugin === 'yoast' ? get_option('wpseo_titles', []) : get_option('rank-math-options-titles', []);
        return isset($opt[$key]) ? $opt[$key] : '';
    }
}

if (!function_exists('seo_meta_bridge_apply_global_update')) {
    /**
     * Apply a meta update to one "global" SEO scope (author_archive,
     * date_archive, search, p404, home). All globals are admin-only —
     * `manage_options` cap — because they affect site-wide SEO settings, not
     * a single post or term.
     */
    function seo_meta_bridge_apply_global_update($scope, $meta) {
        $scopes = seo_meta_bridge_global_scopes();
        if (!isset($scopes[$scope])) {
            return ['ok' => false, 'errors' => ['unknown_scope']];
        }
        if (!current_user_can('manage_options')) {
            return ['ok' => false, 'errors' => ['forbidden']];
        }
        $active = seo_meta_bridge_global_active_keys($scope);
        if (!$active['plugin']) {
            return ['ok' => false, 'errors' => ['no_seo_plugin_or_scope_unsupported']];
        }
        $alias_to_meta = $active['keys'];
        $allowed       = array_values($alias_to_meta);
        $option_name   = $active['plugin'] === 'yoast' ? 'wpseo_titles' : 'rank-math-options-titles';
        $opt           = get_option($option_name, []);

        $errors = [];
        $changed = false;
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
            $opt[$key] = $value;
            $changed = true;
        }
        if ($changed) {
            update_option($option_name, $opt);
        }
        return ['ok' => empty($errors), 'errors' => $errors];
    }
}

if (!function_exists('seo_meta_bridge_apply_archive_update')) {
    /**
     * Apply a meta update to one CPT archive page. Mirrors the post + term
     * update helpers but writes into the SEO plugin's options-array storage.
     * Permission: requires the post type's edit_posts cap (or the generic
     * edit_posts cap if the CPT has no custom cap_type).
     */
    function seo_meta_bridge_apply_archive_update($post_type, $meta) {
        if (!post_type_exists($post_type)) {
            return ['ok' => false, 'errors' => ['unknown_post_type']];
        }
        $pt = get_post_type_object($post_type);
        if (!$pt || empty($pt->has_archive)) {
            return ['ok' => false, 'errors' => ['post_type_has_no_archive']];
        }
        $cap = isset($pt->cap->edit_posts) ? $pt->cap->edit_posts : 'edit_posts';
        if (!current_user_can($cap)) {
            return ['ok' => false, 'errors' => ['forbidden']];
        }
        $active = seo_meta_bridge_active_archive_keys();
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
            seo_meta_bridge_archive_set_value($post_type, $key, $value, $active['plugin']);
        }
        return ['ok' => empty($errors), 'errors' => $errors];
    }
}

if (!function_exists('seo_meta_bridge_apply_update')) {
    /**
     * Apply a meta update to one post. Returns ['ok' => bool, 'errors' => [...]].
     * Permission is enforced per-post; the meta keys themselves are validated
     * against the active plugin's known fields to stop arbitrary postmeta writes.
     */
    function seo_meta_bridge_apply_update($post_id, $meta) {
        $post = get_post($post_id);
        if (!$post) {
            return ['ok' => false, 'errors' => ['post_not_found']];
        }
        if (!current_user_can('edit_post', $post_id)) {
            return ['ok' => false, 'errors' => ['forbidden']];
        }
        $active = seo_meta_bridge_active_keys();
        $alias_to_meta = $active['keys'];
        $allowed = array_values($active['keys']);
        // Also accept the dynamic Rank Math primary-taxonomy keys.
        $allowed_dynamic_prefix = $active['plugin'] === 'rankmath' ? 'rank_math_primary_' : null;

        $errors = [];
        foreach ($meta as $key => $value) {
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
}

add_action('rest_api_init', function () {

    $perm = function () { return current_user_can('edit_posts'); };

    // -------- /status --------------------------------------------------------
    register_rest_route('seo-meta-bridge/v1', '/status', [
        'methods'             => 'GET',
        'permission_callback' => $perm,
        'callback'            => function () {
            $active         = seo_meta_bridge_active_keys();
            $term_active    = seo_meta_bridge_active_term_keys();
            $archive_active = seo_meta_bridge_active_archive_keys();
            $global_scopes  = [];
            foreach (array_keys(seo_meta_bridge_global_scopes()) as $scope) {
                $sa = seo_meta_bridge_global_active_keys($scope);
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
                'version'           => SEO_META_BRIDGE_VERSION,
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
                $known_kinds = array_merge(['term', 'cpt_archive'], array_keys(seo_meta_bridge_global_scopes()));
                $kind = in_array($kind_raw, $known_kinds, true) ? $kind_raw : 'post';

                // Singleton "global" SEO scopes — author_archive, date_archive,
                // search, p404, home. No id, no taxonomy/post_type — the kind
                // itself names the resource.
                if (isset(seo_meta_bridge_global_scopes()[$kind])) {
                    if (!$meta) {
                        $results[] = ['id' => 0, 'kind' => $kind, 'status' => 'error', 'errors' => ['missing_meta']];
                        continue;
                    }
                    $r = seo_meta_bridge_apply_global_update($kind, $meta);
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
                    $r = seo_meta_bridge_apply_archive_update($post_type, $meta);
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
                    $r = seo_meta_bridge_apply_term_update($id, $taxonomy, $meta);
                    $results[] = [
                        'id'       => $id,
                        'kind'     => 'term',
                        'taxonomy' => $taxonomy,
                        'status'   => $r['ok'] ? 'ok' : 'error',
                        'errors'   => $r['errors'],
                    ];
                } else {
                    $r = seo_meta_bridge_apply_update($id, $meta);
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

    // -------- /export --------------------------------------------------------
    // GET /export?post_type=post,page&status=publish&limit=500&offset=0
    //           &include_terms=1&taxonomy=category,post_tag
    // Streams CSV with id,url,post_type,status,post_title,plus the active
    // plugin's SEO fields, plus trailing `kind` and `taxonomy` columns.
    // Backwards compatible: v1.2.x clients that ignore the trailing columns
    // see the same shape they had.
    register_rest_route('seo-meta-bridge/v1', '/export', [
        'methods'             => 'GET',
        'permission_callback' => $perm,
        'callback'            => function (WP_REST_Request $req) {
            $active      = seo_meta_bridge_active_keys();
            $term_active = seo_meta_bridge_active_term_keys();
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
            // drafts from these roles. Without this filter, a low-privilege user
            // with an app password could call /export?status=draft and read
            // titles + SEO meta of every draft on the site, including ones they
            // were never supposed to see in admin. Editors and Administrators
            // (which have edit_others_posts) see everything as before — no
            // behaviour change for normal /export consumers.
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

            // Pre-collect terms so the streamer doesn't need to query inside
            // the response filter (keeps the filter side-effect-free apart
            // from emitting bytes).
            $term_rows = [];
            if ($include_terms && $term_active['plugin']) {
                foreach ($taxonomies as $tax_name) {
                    if (!taxonomy_exists($tax_name)) continue;
                    $terms = get_terms([
                        'taxonomy'   => $tax_name,
                        'hide_empty' => false,
                        'number'     => $limit,
                    ]);
                    if (is_wp_error($terms)) continue;
                    foreach ($terms as $t) {
                        $term_rows[] = ['term' => $t, 'taxonomy' => $tax_name];
                    }
                }
            }

            // CPT archives — synthetic rows for each public CPT with has_archive=true.
            // post_type column carries the CPT slug; id stays 0 (archives have no
            // row ID); kind=cpt_archive flags the row for round-trip dispatch.
            $archive_active = seo_meta_bridge_active_archive_keys();
            $archive_rows = [];
            if ($include_archives && $archive_active['plugin']) {
                foreach (get_post_types(['public' => true, 'has_archive' => true], 'objects') as $pt_obj) {
                    if (empty($pt_obj->has_archive)) continue;
                    $link = get_post_type_archive_link($pt_obj->name);
                    if (!$link) continue;
                    $archive_rows[] = ['post_type' => $pt_obj->name, 'label' => $pt_obj->labels->name ?? $pt_obj->name, 'link' => $link];
                }
            }

            // WP_REST_Response always JSON-encodes its body, so emit raw CSV
            // bytes via rest_pre_serve_request and short-circuit serialization.
            add_filter('rest_pre_serve_request', function ($served) use ($query, $active, $term_active, $archive_active, $headers, $include_lengths, $term_rows, $archive_rows, $field_aliases) {
                if ($served) return $served;
                if (!headers_sent()) {
                    header('Content-Type: text/csv; charset=utf-8');
                    header('Content-Disposition: attachment; filename="seo-meta-export.csv"');
                }
                // Build CSV manually instead of fopen/fputcsv — Plugin Check
                // flags raw filesystem calls, and php://output is the response
                // stream so we just emit strings directly. CSV cells are
                // already CSV-quoted by seo_meta_bridge_csv_line(); HTML-
                // escaping (esc_html) would corrupt the CSV format, so we
                // suppress the OutputNotEscaped check on these emit lines.
                // UTF-8 BOM so Excel/LibreOffice auto-detect encoding and don't
                // mangle curly quotes / em dashes into mojibake (â€™, â€").
                // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- 3-byte UTF-8 BOM, no user data.
                echo "\xEF\xBB\xBF";
                // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSV body, cells quoted by helper above.
                echo seo_meta_bridge_csv_line($headers);
                foreach ($query->posts as $p) {
                    $row = [$p->ID, get_permalink($p->ID), $p->post_type, $p->post_status, $p->post_title];
                    foreach ($active['keys'] as $alias => $meta_key) {
                        $val = get_post_meta($p->ID, $meta_key, true);
                        if (is_array($val)) $val = implode('|', $val);
                        $row[] = $val;
                        if ($include_lengths && ($alias === 'title' || $alias === 'description')) {
                            $row[] = mb_strlen((string) $val);
                        }
                    }
                    $row[] = 'post';  // kind
                    $row[] = '';      // taxonomy (n/a for posts)
                    // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSV body, cells quoted by helper above.
                    echo seo_meta_bridge_csv_line($row);
                }
                if ($term_active['plugin']) {
                    foreach ($term_rows as $tr) {
                        $t   = $tr['term'];
                        $tax = $tr['taxonomy'];
                        $link = get_term_link($t);
                        if (is_wp_error($link)) $link = '';
                        $row = [$t->term_id, $link, '', '', $t->name];
                        foreach ($term_active['keys'] as $alias => $meta_key) {
                            $val = seo_meta_bridge_term_get_value($t->term_id, $tax, $meta_key, $term_active['plugin']);
                            if (is_array($val)) $val = implode('|', $val);
                            $row[] = $val;
                            if ($include_lengths && ($alias === 'title' || $alias === 'description')) {
                                $row[] = mb_strlen((string) $val);
                            }
                        }
                        $row[] = 'term';
                        $row[] = $tax;
                        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSV body, cells quoted by helper above.
                        echo seo_meta_bridge_csv_line($row);
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
                        // We need to emit the SAME number of cells as $headers,
                        // matching the post-row column shape. The column aliases
                        // were derived from $active or $term_active (whichever
                        // exists) — archive aliases overlap on title/description
                        // but may not cover other fields. Map by alias name:
                        //   if archive_active has the alias → emit value;
                        //   else → emit empty (preserves CSV column count).
                        foreach ($field_aliases as $alias) {
                            $val = '';
                            if (isset($archive_active['keys'][$alias])) {
                                $val = seo_meta_bridge_archive_get_value($pt, $archive_active['keys'][$alias], $archive_active['plugin']);
                                if (is_array($val)) $val = implode('|', $val);
                            }
                            $row[] = $val;
                            if ($include_lengths && ($alias === 'title' || $alias === 'description')) {
                                $row[] = mb_strlen((string) $val);
                            }
                        }
                        $row[] = 'cpt_archive';
                        $row[] = '';  // taxonomy column repurposed empty for archive rows
                        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSV body, cells quoted by helper above.
                        echo seo_meta_bridge_csv_line($row);
                    }
                }
                return true;
            });

            return new WP_REST_Response(null, 200);
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

            $active         = seo_meta_bridge_active_keys();
            $term_active    = seo_meta_bridge_active_term_keys();
            $archive_active = seo_meta_bridge_active_archive_keys();
            // Trailing kind/taxonomy columns (v1.3.0+) flag term rows in a
            // mixed export. v1.2.x CSVs lack them — kind defaults to 'post'.
            // cpt_archive rows (v1.4.0+) reuse the post_type column for the
            // CPT slug and have id=0.
            $non_meta_cols = ['id', 'url', 'post_type', 'status', 'post_title', 'kind', 'taxonomy'];

            $results = [];
            foreach ($rows as $row) {
                $id   = isset($row['id']) ? (int) $row['id'] : 0;
                $kind_raw = isset($row['kind']) ? (string) $row['kind'] : 'post';
                $known_kinds = array_merge(['term', 'cpt_archive'], array_keys(seo_meta_bridge_global_scopes()));
                $kind = in_array($kind_raw, $known_kinds, true) ? $kind_raw : 'post';

                if (isset(seo_meta_bridge_global_scopes()[$kind])) {
                    $alias_to_meta = seo_meta_bridge_global_active_keys($kind)['keys'];
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
                    $r = seo_meta_bridge_apply_global_update($kind, $meta);
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
                    $r = seo_meta_bridge_apply_archive_update($post_type, $meta);
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
                    $r = seo_meta_bridge_apply_term_update($id, $taxonomy, $meta);
                    $results[] = [
                        'id'       => $id,
                        'kind'     => 'term',
                        'taxonomy' => $taxonomy,
                        'status'   => $r['ok'] ? 'ok' : 'error',
                        'errors'   => $r['errors'],
                    ];
                } else {
                    $r = seo_meta_bridge_apply_update($id, $meta);
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
