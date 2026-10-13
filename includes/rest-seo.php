<?php
/**
 * REST routes for SEO fields: /status, /schema, /sitemap/flush, /bulk, /bulk-alts, /export, /import.
 */

if (!defined('ABSPATH')) exit;

// =============================================================================
// REST surface
// =============================================================================
// Namespace: seo-meta-bridge/v1
//   GET  /status          Detection - which SEO plugin is active
//   POST /bulk            Update SEO meta on up to 100 posts in one call
//   GET  /export          Stream all posts' SEO meta as CSV
//   POST /import          Bulk update from CSV (upload or JSON rows)
// =============================================================================

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
    // least edit_posts. This is only the outer door - each handler enforces
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
            $status = [
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
                'supports_term_schema'   => true,
                'supports_post_title'    => true,
                'supports_sitemap_flush' => true,
                'supports_api_key'  => true,
                'supports_content'  => true,
                'supports_layout_settings' => true,
                'supports_atomic_widgets'  => true,
                'supports_term_content'    => true,
                'supports_clone'    => true,
                'supports_wpb_css_rebuild' => true,
                'supports_siteorigin' => true,
                'supports_accordions' => true,
                'supports_beaver'   => true,
                'supports_acf'      => bulkseme_acf_active(),
                'supports_redirects' => true,
                'supports_update'   => true,
                'drops_invalid_product_schema' => (bool) apply_filters('bulkseme_drop_invalid_product_schema', true),
                'builders'          => bulkseme_builders_status(current_user_can('activate_plugins')),
                'content_editable_builders' => ['elementor', 'gutenberg', 'classic', 'divi', 'wpbakery', 'siteorigin', 'accordions', 'beaver', 'acf'],
                'schema_fields'     => [
                    'schema'      => '_bulkseme_schema_jsonld',
                    'schema_mode' => '_bulkseme_schema_mode',
                ],
                'version'           => BULKSEME_VERSION,
            ];
            // How the site is put together is for those who manage it.
            if (current_user_can('manage_options')) {
                $status['redirect_engine']     = bulkseme_redirect_engine();
                $status['merchant_enrichment'] = bulkseme_merchant_status();
            }
            /**
             * Filter: the /status answer, for add-ons that register routes of their own.
             *
             * @param array $status
             */
            $filtered = apply_filters('bulkseme_status', $status);
            return is_array($filtered) ? $filtered : $status;
        },
    ]);

    // -------- /schema --------------------------------------------------------
    // GET /schema?id=123 - read back the custom JSON-LD stored against a post
    // (plus its add/replace mode). Useful for an agent to verify what it wrote,
    // or to audit which posts already carry custom schema. Decoded so callers
    // get an object, not a re-encoded string.
    // GET /schema?id=45&kind=term&taxonomy=product_cat reads a term's schema.
    register_rest_route('seo-meta-bridge/v1', '/schema', [
        'methods'             => 'GET',
        'permission_callback' => $perm,
        'args'                => [
            'id' => [
                'required'          => true,
                'sanitize_callback' => 'bulkseme_param_id',
            ],
            'kind' => [
                'required'          => false,
                'sanitize_callback' => 'sanitize_key',
            ],
            'taxonomy' => [
                'required'          => false,
                'sanitize_callback' => 'sanitize_key',
            ],
        ],
        'callback'            => function (WP_REST_Request $req) {
            $id = bulkseme_param_id($req->get_param('id'));
            if (bulkseme_param_text($req->get_param('kind')) === 'term') {
                $taxonomy = bulkseme_param_text($req->get_param('taxonomy'));
                if ($taxonomy === '' || !taxonomy_exists($taxonomy)) {
                    return new WP_Error('unknown_taxonomy', 'a valid taxonomy is required for kind=term', ['status' => 400]);
                }
                $term = $id ? get_term($id, $taxonomy) : null;
                if (!$term || is_wp_error($term)) {
                    return new WP_Error('term_not_found', 'term not found', ['status' => 404]);
                }
                // Per-term meta cap, the same gate the /bulk term writer uses.
                if (!current_user_can('edit_term', $term->term_id)) {
                    return new WP_Error('forbidden', 'cannot edit this term', ['status' => 403]);
                }
                $raw     = get_term_meta($term->term_id, '_bulkseme_schema_jsonld', true);
                $decoded = (is_string($raw) && $raw !== '') ? json_decode($raw, true) : null;
                return [
                    'id'         => (int) $term->term_id,
                    'kind'       => 'term',
                    'taxonomy'   => $taxonomy,
                    'mode'       => bulkseme_schema_mode_for_term($term->term_id),
                    'has_schema' => !empty($decoded),
                    'schema'     => $decoded,
                ];
            }
            $post = $id ? get_post($id) : null;
            if (!$post || !bulkseme_post_type_reachable($post->post_type)) {
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

    // -------- /sitemap/flush -------------------------------------------------
    // Rebuild the SEO plugin's XML sitemap after REST edits. Rank Math keeps its
    // sitemaps cached and only invalidates them from wp-admin or WP-Cron, so a
    // noindex flip, slug change or new page made through this API leaves the
    // sitemap stale until someone saves a setting in wp-admin. Yoast's sitemap
    // cache has the same blind spot for direct meta writes. This calls each
    // plugin's own public cache-clear method; nothing is written and no setting
    // changes. The next sitemap request rebuilds from live data. Editors and up
    // (edit_others_posts), because it affects every URL in the sitemap.
    register_rest_route('seo-meta-bridge/v1', '/sitemap/flush', [
        'methods'             => 'POST',
        'permission_callback' => $perm,
        'callback'            => function () {
            if (!current_user_can('edit_others_posts')) {
                return new WP_Error('forbidden', 'editor capability required', ['status' => 403]);
            }
            $flushed = [];
            if (class_exists('\\RankMath\\Sitemap\\Cache') && is_callable(['\\RankMath\\Sitemap\\Cache', 'invalidate_storage'])) {
                \RankMath\Sitemap\Cache::invalidate_storage();
                $flushed[] = 'rankmath';
            }
            if (class_exists('WPSEO_Sitemaps_Cache') && is_callable(['WPSEO_Sitemaps_Cache', 'clear'])) {
                WPSEO_Sitemaps_Cache::clear();
                $flushed[] = 'yoast';
            }
            return [
                'ok'      => !empty($flushed),
                'flushed' => $flushed,
                'note'    => empty($flushed) ? 'no supported sitemap cache found (Rank Math or Yoast sitemap module not active)' : '',
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
                if (!is_array($item)) {
                    $results[] = ['id' => 0, 'kind' => 'post', 'status' => 'error', 'errors' => ['invalid_item']];
                    continue;
                }
                $id   = isset($item['id']) && is_scalar($item['id']) ? (int) $item['id'] : 0;
                $meta = isset($item['meta']) && is_array($item['meta']) ? $item['meta'] : [];
                $kind_raw = isset($item['kind']) && is_scalar($item['kind']) ? (string) $item['kind'] : 'post';
                $known_kinds = array_merge(['term', 'cpt_archive'], array_keys(bulkseme_global_scopes()));
                $kind = in_array($kind_raw, $known_kinds, true) ? $kind_raw : 'post';

                // Singleton "global" SEO scopes - author_archive, date_archive,
                // search, p404, home. No id, no taxonomy/post_type - the kind
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
                    // CPT archive pages have no row ID - they're a synthetic
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
    // the parent attachment - that's where the canonical alt lives. Update
    // _wp_attachment_image_alt on the attachment post. Most themes read alt
    // via wp_get_attachment_image_alt() which pulls from this postmeta.
    //
    // We do NOT rewrite inline <img alt="..."> in post_content. Page builders
    // (Elementor, WPBakery, etc.) hard-code alt into block content; rewriting
    // post_content via DOM is fragile and risks breaking serialised blocks.
    register_rest_route('seo-meta-bridge/v1', '/bulk-alts', [
        'methods'             => 'POST',
        // Media only, so the caller must also be allowed to manage media. This keeps
        // Contributors, who cannot edit any attachment, away from the URL lookups.
        'permission_callback' => function () {
            return current_user_can('edit_posts') && current_user_can('upload_files');
        },
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
                if (!is_array($item)) {
                    $results[] = ['image_url' => '', 'status' => 'error', 'errors' => ['invalid_item']];
                    continue;
                }
                $url     = isset($item['image_url']) && is_scalar($item['image_url']) ? trim((string) $item['image_url']) : '';
                $new_alt = isset($item['new_alt']) && is_scalar($item['new_alt'])     ? (string) $item['new_alt']         : '';
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
                        'hint'      => 'URL did not resolve to an attachment in the media library. The image is likely hard-coded into a page-builder block or theme template (common for SVG icons and hero images), or hot-linked from an external/CDN source - its alt text lives in the page builder, not the media library.',
                    ];
                    continue;
                }

                // Per-attachment permission: the route-level edit_posts check
                // grants access to the endpoint, but each write must also pass
                // edit_post for THIS attachment - so Authors/Contributors can
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
            // (matches the existing /bulk endpoint's behavior - host-side
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

            // Every parameter is read through a helper that takes a comma-separated
            // string or a list and nothing else: ?post_type[]=x used to end the
            // request with a TypeError.
            $post_types_param = bulkseme_param_list($req->get_param('post_type')) ?: ['post', 'page'];
            // 'any' is a magic WP_Query value meaning all public types except
            // attachment/revision. Pass it as a string, not an array.
            if ($post_types_param === ['any']) {
                $post_types = 'any';
            } else {
                // Only types that have an edit screen or a REST route of their own.
                $post_types = array_values(array_filter($post_types_param, 'bulkseme_post_type_reachable'));
                if (!$post_types) {
                    $post_types = ['post', 'page'];
                }
            }
            $statuses         = bulkseme_param_list($req->get_param('status')) ?: ['publish', 'draft'];
            $limit            = min(2000, max(1, (int) (bulkseme_param_text($req->get_param('limit')) ?: 500)));
            $offset           = max(0, (int) (bulkseme_param_text($req->get_param('offset')) ?: 0));
            $include_terms    = bulkseme_param_text($req->get_param('include_terms')) === '1';
            $include_archives = bulkseme_param_text($req->get_param('include_archives')) === '1';
            $taxonomies       = bulkseme_param_list($req->get_param('taxonomy'))
                ?: array_values(get_taxonomies(['public' => true], 'names'));

            // Author scoping for /export. When the caller lacks edit_others_posts
            // (i.e. Author / Contributor roles), restrict the query to posts they
            // own - mirrors the wp-admin Posts list, which hides other users'
            // drafts from these roles. This is only a cheap pre-filter so we do
            // not load rows we are about to discard; the authoritative gate is
            // the per-post edit_post check applied to every emitted row below.
            $query_args = [
                'post_type'      => $post_types,
                'post_status'    => $statuses,
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
            // at a glance). Static at export time - re-export to refresh after edits.
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

            // CPT archives - synthetic rows for each public CPT with has_archive=true.
            // post_type column carries the CPT slug; id stays 0 (archives have no
            // row ID); kind=cpt_archive flags the row for round-trip dispatch.
            //
            // CPT-archive SEO values are not per-object meta: they live in the
            // SEO plugin's site-wide settings option (wpseo_titles /
            // rank-math-options-titles), and wp-admin only exposes them on the
            // SEO plugin's settings screens, which are administrator-only.
            // bulkseme_apply_archive_update() already requires manage_options to
            // write them, so reading them requires the same cap - otherwise any
            // Author could read back site settings they can neither see nor edit.
            $archive_active = bulkseme_active_archive_keys();
            $archive_rows = [];
            if ($include_archives && $archive_active['plugin'] && current_user_can('manage_options') && bulkseme_user_can_seo_group('site')) {
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
                // 'post' capability only - a CPT registered with its own
                // capability_type can deny a user who passes it. edit_post is the
                // meta cap that resolves ownership, post status and per-type caps
                // together, so it is the check every emitted row must pass.
                if (!bulkseme_post_type_reachable($p->post_type) || !current_user_can('edit_post', $p->ID)) continue;
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
                    // from $active or $term_active (whichever exists) - archive
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
            $rows         = [];
            $skipped_rows = 0;

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
                    if (strlen($csv_text) > 10 * MB_IN_BYTES) {
                        return new WP_Error('file_too_large', 'the CSV file is larger than 10 MB', ['status' => 413]);
                    }
                    // Strip leading UTF-8 BOM so the first header cell isn't "\xEF\xBB\xBFid"
                    // (which would silently drop every row's id on round-trip imports).
                    if (substr($csv_text, 0, 3) === "\xEF\xBB\xBF") {
                        $csv_text = substr($csv_text, 3);
                    }
                    // The whole text is read as CSV, so a quoted cell that holds line
                    // breaks stays one cell. It is never cut into lines first.
                    // 2,000 data rows and a header is the most one import takes; the reader stops just past it.
                    list($parsed, $well_formed) = bulkseme_csv_parse($csv_text, 2002);
                    if (!$well_formed) {
                        return new WP_Error('malformed_csv', 'the CSV file ends inside a quoted cell; nothing was imported', ['status' => 400]);
                    }
                    $hdr = null;
                    foreach ($parsed as $cells) {
                        if ($hdr === null) {
                            $hdr = $cells;
                            if (count(array_unique($hdr)) !== count($hdr)) {
                                return new WP_Error('malformed_csv', 'the CSV header names a column twice; nothing was imported', ['status' => 400]);
                            }
                        } elseif (count($cells) === count($hdr)) {
                            // A row with more or fewer cells than the header is left out.
                            $rows[] = array_combine($hdr, $cells);
                        } else {
                            $skipped_rows++;
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
            // mixed export. v1.2.x CSVs lack them - kind defaults to 'post'.
            // cpt_archive rows (v1.4.0+) reuse the post_type column for the
            // CPT slug and have id=0.
            $non_meta_cols = ['id', 'url', 'post_type', 'status', 'post_title', 'kind', 'taxonomy'];

            $results = [];
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    $results[] = ['id' => 0, 'kind' => 'post', 'status' => 'error', 'errors' => ['invalid_row']];
                    continue;
                }
                // /export guards formula-like cells with a leading apostrophe; take it off again.
                $row  = array_map('bulkseme_csv_unguard', $row);
                $id   = isset($row['id']) && is_scalar($row['id']) ? (int) $row['id'] : 0;
                $kind_raw = isset($row['kind']) && is_scalar($row['kind']) ? (string) $row['kind'] : 'post';
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
                    // Empty cells in a CSV mean "don't touch this field" - never overwrite
                    // an existing value with an empty string just because the column was blank.
                    if ($val === null || $val === '') continue;
                    if (isset($alias_to_meta[$col])) {
                        $meta[$alias_to_meta[$col]] = $val;
                    } elseif (in_array($col, $alias_to_meta, true)) {
                        // raw meta key was supplied
                        $meta[$col] = $val;
                    }
                    // unknown columns silently ignored - supports round-tripping export CSVs
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
            $out = ['count' => count($results), 'results' => $results];
            if ($skipped_rows) {
                // Rows of an uploaded file whose cell count did not match the header.
                $out['skipped_rows'] = $skipped_rows;
            }
            return $out;
        },
    ]);
});
