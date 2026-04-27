<?php
/**
 * Plugin Name: Bulk SEO Meta Editor for AI Agents
 * Plugin URI:  https://github.com/puneetindersingh/bulk-seo-meta-editor-for-ai-agents
 * Description: Bulk-update Yoast SEO or Rank Math meta tags via REST API. Designed for AI agents (Claude, ChatGPT, Perplexity) and automation scripts. Auto-detects the active SEO plugin. Includes CSV import/export and a bundled MCP server for one-command Claude Code / Claude Desktop integration.
 * Version: 1.2.5
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
        define('SEO_META_BRIDGE_VERSION', '1.2.5');
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
            $active = seo_meta_bridge_active_keys();
            return [
                'yoast'    => defined('WPSEO_VERSION')    || class_exists('WPSEO_Options'),
                'rankmath' => defined('RANK_MATH_VERSION') || class_exists('RankMath\\Helper'),
                'active'   => $active['plugin'],
                'fields'   => $active['keys'],
                'version'  => SEO_META_BRIDGE_VERSION,
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
                if (!$id || !$meta) {
                    $results[] = ['id' => $id, 'status' => 'error', 'errors' => ['missing_id_or_meta']];
                    continue;
                }
                $r = seo_meta_bridge_apply_update($id, $meta);
                $results[] = [
                    'id'     => $id,
                    'status' => $r['ok'] ? 'ok' : 'error',
                    'errors' => $r['errors'],
                ];
            }
            return ['count' => count($results), 'results' => $results];
        },
    ]);

    // -------- /export --------------------------------------------------------
    // GET /export?post_type=post,page&status=publish&limit=500&offset=0
    // Streams CSV with id,url,post_type,status,title,plus the active plugin's
    // SEO fields as columns.
    register_rest_route('seo-meta-bridge/v1', '/export', [
        'methods'             => 'GET',
        'permission_callback' => $perm,
        'callback'            => function (WP_REST_Request $req) {
            $active = seo_meta_bridge_active_keys();
            if (!$active['plugin']) {
                return new WP_Error('no_seo_plugin', 'No SEO plugin active', ['status' => 400]);
            }

            $post_types_param = $req->get_param('post_type') ?: 'post,page';
            $post_types = array_map('trim', explode(',', $post_types_param));
            $status     = $req->get_param('status') ?: 'publish,draft';
            $limit      = min(2000, max(1, (int) ($req->get_param('limit') ?: 500)));
            $offset     = max(0, (int) ($req->get_param('offset') ?: 0));

            $query = new WP_Query([
                'post_type'      => $post_types,
                'post_status'    => array_map('trim', explode(',', $status)),
                'posts_per_page' => $limit,
                'offset'         => $offset,
                'orderby'        => 'ID',
                'order'          => 'ASC',
                'no_found_rows'  => true,
            ]);

            // 'post_title' disambiguates the WP post title from the SEO 'title'
            // alias which maps to _yoast_wpseo_title / rank_math_title.
            $headers = array_merge(['id', 'url', 'post_type', 'status', 'post_title'], array_keys($active['keys']));

            // WP_REST_Response always JSON-encodes its body, so emit raw CSV
            // bytes via rest_pre_serve_request and short-circuit serialization.
            add_filter('rest_pre_serve_request', function ($served) use ($query, $active, $headers) {
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
                    }
                    // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSV body, cells quoted by helper above.
                    echo seo_meta_bridge_csv_line($row);
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

            $active = seo_meta_bridge_active_keys();
            $alias_to_meta = $active['keys'];
            // Allow either alias names (title, description, focus_kw...) or raw meta keys.
            $non_meta_cols = ['id', 'url', 'post_type', 'status', 'post_title']; // post_title = WP post title, distinct from SEO 'title' alias

            $results = [];
            foreach ($rows as $row) {
                $id = isset($row['id']) ? (int) $row['id'] : 0;
                if (!$id) {
                    $results[] = ['id' => 0, 'status' => 'error', 'errors' => ['missing_id']];
                    continue;
                }
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
                    $results[] = ['id' => $id, 'status' => 'noop', 'errors' => []];
                    continue;
                }
                $r = seo_meta_bridge_apply_update($id, $meta);
                $results[] = [
                    'id'     => $id,
                    'status' => $r['ok'] ? 'ok' : 'error',
                    'errors' => $r['errors'],
                ];
            }
            return ['count' => count($results), 'results' => $results];
        },
    ]);
});
