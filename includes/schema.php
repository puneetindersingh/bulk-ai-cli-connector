<?php
/**
 * Custom JSON-LD schema: storage per post and per term, output through the SEO plugin's graph
 * (or a block of our own when there is none), and the guard that drops invalid Product nodes.
 */

if (!defined('ABSPATH')) exit;

// =============================================================================
// Custom JSON-LD schema (per post/page and per taxonomy term)
// =============================================================================
// Self-contained module. Lets an AI agent store a block of JSON-LD against a
// post or a taxonomy term and have it rendered in that page's structured data.
//
// Output, in order of preference:
//   1. Merged into the active SEO plugin's own schema graph, so the nodes join
//      the existing @graph and there is no competing second block:
//        * Yoast SEO  -> wpseo_schema_graph filter
//        * Rank Math  -> rank_math/json_ld filter
//   2. Our own <script type="application/ld+json"> block in wp_head, ONLY when
//      no SEO plugin schema graph was built for the page: no Yoast or Rank Math
//      installed, or one is installed with its schema output switched off
//      (for example a site that returns false from wpseo_json_ld_output).
//      Without this, stored schema never reached the page on those sites.
//
// Storage (prefixed, protected post meta and term meta, same keys for both):
//   _bulkseme_schema_jsonld  raw JSON-LD string (validated on write)
//   _bulkseme_schema_mode    'add' (merge into the graph) or 'replace'
//                            (our nodes become the whole graph for that page)
//
// Term schema renders on the FIRST page of the term's archive only, because
// that is the only page where WordPress and WooCommerce print the term
// description, which is where the content the schema describes lives.

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
 * Largest JSON-LD document that can be stored for one post or term, in bytes.
 * Stored schema is decoded and walked on every view of the page, so its size
 * is bounded. 128 KB is many times what a long FAQ or product list needs.
 */
function bulkseme_schema_max_bytes() {
    return max(1024, (int) apply_filters('bulkseme_schema_max_bytes', 131072));
}

/** Most values (keys at every depth) one stored JSON-LD document may hold. */
function bulkseme_schema_max_values() {
    return max(100, (int) apply_filters('bulkseme_schema_max_values', 5000));
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
    if (is_array($value)) {
        // A decoded object or list goes through the same checks as a JSON string.
        // (It used to be read as "empty", which cleared the stored schema.)
        $value = $value ? wp_json_encode($value) : '';
    }
    $raw = is_string($value) ? trim($value) : '';
    if ($raw === '') {
        delete_post_meta($post_id, '_bulkseme_schema_jsonld');
        return true;
    }
    if (strlen($raw) > bulkseme_schema_max_bytes()) {
        return 'schema_too_large';
    }
    $decoded = json_decode($raw, true, 64);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
        return 'invalid_json_schema';
    }
    if (count($decoded, COUNT_RECURSIVE) > bulkseme_schema_max_values()) {
        return 'schema_too_large';
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
 * Term counterpart of bulkseme_store_schema(): validate and store a JSON-LD
 * blob against a taxonomy term (category, tag, product category, any public
 * taxonomy). Same rules: empty clears it, markup is refused, the document is
 * re-encoded canonically and slashed for update_metadata(). The caller has
 * already checked edit_term for this term.
 */
function bulkseme_store_term_schema($term_id, $value) {
    if (is_array($value)) {
        // A decoded object or list goes through the same checks as a JSON string.
        // (It used to be read as "empty", which cleared the stored schema.)
        $value = $value ? wp_json_encode($value) : '';
    }
    $raw = is_string($value) ? trim($value) : '';
    if ($raw === '') {
        delete_term_meta($term_id, '_bulkseme_schema_jsonld');
        return true;
    }
    if (strlen($raw) > bulkseme_schema_max_bytes()) {
        return 'schema_too_large';
    }
    $decoded = json_decode($raw, true, 64);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
        return 'invalid_json_schema';
    }
    if (count($decoded, COUNT_RECURSIVE) > bulkseme_schema_max_values()) {
        return 'schema_too_large';
    }
    if (bulkseme_schema_contains_markup($decoded)) {
        return 'schema_contains_markup';
    }
    $canonical = wp_json_encode($decoded, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($canonical)) {
        return 'invalid_json_schema';
    }
    update_term_meta($term_id, '_bulkseme_schema_jsonld', wp_slash($canonical));
    return true;
}

/**
 * Decode a post's stored JSON-LD into a flat list of node arrays, ready to drop
 * into a schema @graph. See bulkseme_schema_nodes_from_raw() for the accepted
 * shapes.
 */
function bulkseme_schema_nodes_for_post($post_id) {
    return bulkseme_schema_nodes_from_raw(get_post_meta($post_id, '_bulkseme_schema_jsonld', true));
}

/**
 * Same as bulkseme_schema_nodes_for_post(), for a taxonomy term.
 */
function bulkseme_schema_nodes_for_term($term_id) {
    return bulkseme_schema_nodes_from_raw(get_term_meta($term_id, '_bulkseme_schema_jsonld', true));
}

/**
 * 'add' (default) or 'replace' for a term's stored schema.
 */
function bulkseme_schema_mode_for_term($term_id) {
    return get_term_meta($term_id, '_bulkseme_schema_mode', true) === 'replace' ? 'replace' : 'add';
}

/**
 * Shared decoder behind the post and term node getters. Accepts three author
 * shapes:
 *   1. a full document  { "@context": "...", "@graph": [ ...nodes ] }
 *   2. a bare list       [ ...nodes ]
 *   3. a single node     { "@type": "FAQPage", ... }
 * Per-node "@context" is stripped because the graph carries one top-level
 * context for the whole document.
 */
function bulkseme_schema_nodes_from_raw($raw) {
    if (!is_string($raw) || trim($raw) === '') {
        return [];
    }
    // Far beyond what can be stored today: written before the limit existed, or
    // straight into the database. Not worth decoding on every page view.
    if (strlen($raw) > 4 * bulkseme_schema_max_bytes()) {
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
 * The schema nodes + mode for the view currently being rendered: a singular
 * post, or the first page of a taxonomy term archive. Returns empty nodes for
 * every other view (search, home, date and author archives, CPT archives).
 *
 * Term schema is limited to the first page because that is the only page of an
 * archive where WordPress and WooCommerce print the term description, which is
 * where the content the schema describes (an FAQ block, a service summary)
 * lives. Marking up /page/2/ would describe content that is not on the page.
 */
function bulkseme_current_schema() {
    if (is_category() || is_tag() || is_tax()) {
        $term = get_queried_object();
        if (!($term instanceof WP_Term) || is_paged()) {
            return ['mode' => 'add', 'nodes' => []];
        }
        return [
            'mode'  => bulkseme_schema_mode_for_term($term->term_id),
            'nodes' => bulkseme_schema_nodes_for_term($term->term_id),
        ];
    }
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

/**
 * Yoast SEO: merge the stored nodes into its @graph. The filter itself is
 * registered in the main plugin file.
 */
function bulkseme_schema_merge_yoast($graph) {
    $schema = bulkseme_current_schema();
    if (empty($schema['nodes'])) {
        return $graph;
    }
    if ($schema['mode'] === 'replace') {
        return array_values($schema['nodes']);
    }
    return array_merge(is_array($graph) ? $graph : [], $schema['nodes']);
}

/**
 * Rank Math: merge the stored nodes into its JSON-LD data. The filter itself
 * is registered in the main plugin file.
 */
function bulkseme_schema_merge_rankmath($data) {
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
}

// ---- Did an SEO plugin build a schema graph for this page? -------------------
// Yoast applies wpseo_schema_graph, and Rank Math applies rank_math/json_ld,
// only while building the JSON-LD block they are about to print. When a site
// switches that output off (Yoast's wpseo_json_ld_output returning false, its
// Schema presenter removed, or Rank Math's Schema module disabled) the filters
// never run, so the merge filters above never see our nodes and the schema
// would silently go missing. These priority-1 observers record that a graph
// was built; the standalone block below prints only when none was.
function bulkseme_seo_graph_built($set = null) {
    static $built = false;
    if ($set !== null) {
        $built = (bool) $set;
    }
    return $built;
}
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Yoast SEO's own public Schema API filter; we observe it and return the graph unchanged.
add_filter('wpseo_schema_graph', function ($graph) {
    bulkseme_seo_graph_built(true);
    return $graph;
}, 1);
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Rank Math's own public JSON-LD filter; we observe it and return the data unchanged.
add_filter('rank_math/json_ld', function ($data) {
    bulkseme_seo_graph_built(true);
    return $data;
}, 1);

// ---- Rank Math, term archives: keep the archive's own page type -------------
// Rank Math folds any FAQPage node into its WebPage node (priority 99, the same
// hook our nodes arrive on). On a post that carries Rank Math schema types the
// result is a type list such as ["WebPage","FAQPage"], but when the view has
// none, which is always the case on a taxonomy archive, it overwrites the type
// with a bare "FAQPage" and the archive stops being a CollectionPage. Record
// the type before that runs and put it back next to FAQPage afterwards. Scoped
// to term archives, so post output is exactly what it was before 1.9.0.
function bulkseme_term_webpage_type($set = null) {
    static $type = null;
    if ($set !== null) {
        $type = $set;
    }
    return $type;
}
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Rank Math's own public JSON-LD filter; we are a consumer of it, not the owner.
add_filter('rank_math/json_ld', function ($data) {
    if ((is_category() || is_tag() || is_tax()) && is_array($data) && !empty($data['WebPage']['@type'])) {
        bulkseme_term_webpage_type($data['WebPage']['@type']);
    }
    return $data;
}, 98);
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Rank Math's own public JSON-LD filter; we are a consumer of it, not the owner.
add_filter('rank_math/json_ld', function ($data) {
    $before = bulkseme_term_webpage_type();
    if (!(is_category() || is_tag() || is_tax()) || $before === null || !is_array($data)) {
        return $data;
    }
    if (isset($data['WebPage']['@type']) && $data['WebPage']['@type'] === 'FAQPage') {
        $types = array_values(array_unique(array_merge((array) $before, ['FAQPage'])));
        $data['WebPage']['@type'] = count($types) === 1 ? $types[0] : $types;
    }
    return $data;
}, 100);

// ---- Standalone output: no SEO plugin schema graph on this page -------------
// Covers a site with no Yoast or Rank Math (or another schema plugin, or none),
// and a site where one is installed with its schema output switched off. The
// block is printed through wp_print_inline_script_tag(). The JSON is encoded
// with JSON_HEX_TAG | JSON_HEX_AMP, so < > & can never appear literally inside
// the <script> element, on top of the markup already refused on write and
// stripped again by bulkseme_schema_nodes_from_raw().
add_action('wp_head', function () {
    if (bulkseme_seo_graph_built()) {
        return; // the SEO plugin's graph carried our nodes
    }
    $schema = bulkseme_current_schema();
    if (empty($schema['nodes'])) {
        return;
    }
    $nodes = bulkseme_drop_invalid_product_nodes($schema['nodes']);
    /**
     * Filter: return false to stop the plugin printing its own JSON-LD block
     * on pages where no SEO plugin schema graph was built.
     *
     * @param bool  $print Default true.
     * @param array $nodes The nodes that would be printed.
     */
    if (empty($nodes) || !apply_filters('bulkseme_print_standalone_schema', true, $nodes)) {
        return;
    }
    $json = wp_json_encode(
        ['@context' => 'https://schema.org', '@graph' => array_values($nodes)],
        JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    );
    if (!is_string($json)) {
        return;
    }
    wp_print_inline_script_tag($json, ['type' => 'application/ld+json', 'class' => 'bulkseme-schema']);
}, 20);

// -----------------------------------------------------------------------------
// Invalid-Product schema guard
// -----------------------------------------------------------------------------
// Google requires a Product entity to carry at least one of `offers`, `review`
// or `aggregateRating`. A quote-only catalogue (B2B manufacturer, hire fleet,
// "contact us for pricing") has none of them, but the WooCommerce integrations
// in Yoast and Rank Math still emit a Product node on every product page. Each
// one is permanently Invalid under Search Console > Shopping > Product snippets;
// no product rich result was ever achievable, so the markup buys nothing and
// leaves a red report the site owner can never clear.
//
// This drops ONLY graph-level Product nodes that carry none of the required
// properties. A product with a price keeps its Product schema untouched, and if
// pricing is added later the schema returns on the next page load with nothing
// to undo. Every other node is left exactly as the SEO plugin built it.
//
// Opt out on a site:
//   add_filter('bulkseme_drop_invalid_product_schema', '__return_false');

/**
 * True if a Product node carries at least one property that makes it eligible
 * for a product rich result. `hasVariant` is included because a variant parent
 * carries its pricing on the child ProductGroup entries.
 */
function bulkseme_product_node_is_valid($node) {
    foreach (['offers', 'review', 'aggregateRating', 'hasVariant'] as $required) {
        if (!empty($node[$required])) {
            return true;
        }
    }
    return false;
}

/**
 * Strip `{"@id": "..."}` pointers to nodes we removed, at any depth, so the
 * graph is never left referencing an entity that no longer exists. Returns null
 * when the value IS such a pointer (or becomes empty once pruned), which tells
 * the caller to drop the key that held it.
 */
function bulkseme_schema_prune_refs($value, $dead_ids) {
    if (!is_array($value)) {
        return $value;
    }
    // A pure reference: exactly {"@id": "<id we dropped>"}.
    if (isset($value['@id']) && 1 === count($value)
        && is_string($value['@id']) && isset($dead_ids[$value['@id']])) {
        return null;
    }
    $is_list = empty($value) || array_keys($value) === range(0, count($value) - 1);
    $out     = [];
    $removed = false;
    foreach ($value as $k => $v) {
        $pruned = bulkseme_schema_prune_refs($v, $dead_ids);
        if (null === $pruned) {
            $removed = true;
            continue;
        }
        $out[$k] = $pruned;
    }
    // Only collapse a list that we emptied ourselves. A property that was
    // already an empty array is left exactly as the SEO plugin wrote it.
    if ($is_list) {
        $out = array_values($out);
        if (empty($out) && $removed) {
            return null;
        }
    }
    return $out;
}

/**
 * Remove unqualifiable Product nodes from a schema graph. Accepts Yoast's
 * numerically indexed graph and Rank Math's keyed one; keys are preserved so
 * either plugin gets back the shape it handed us.
 */
function bulkseme_drop_invalid_product_nodes($nodes) {
    if (!is_array($nodes) || empty($nodes)) {
        return $nodes;
    }
    /**
     * Filter: return false to keep Product nodes that cannot validate.
     *
     * @param bool $enabled Default true.
     */
    if (!apply_filters('bulkseme_drop_invalid_product_schema', true)) {
        return $nodes;
    }
    $dead_ids = [];
    $kept     = [];
    foreach ($nodes as $key => $node) {
        if (is_array($node) && isset($node['@type'])) {
            $types = is_array($node['@type']) ? $node['@type'] : [$node['@type']];
            if (in_array('Product', $types, true) && !bulkseme_product_node_is_valid($node)) {
                if (!empty($node['@id']) && is_string($node['@id'])) {
                    $dead_ids[$node['@id']] = true; // a lookup map: id => true
                }
                continue;
            }
        }
        $kept[$key] = $node;
    }
    if (count($kept) === count($nodes)) {
        return $nodes; // nothing qualified for removal: hand back the original
    }
    if (!empty($dead_ids)) {
        foreach ($kept as $key => $node) {
            $pruned = bulkseme_schema_prune_refs($node, $dead_ids);
            if (null === $pruned) {
                unset($kept[$key]);
                continue;
            }
            $kept[$key] = $pruned;
        }
    }
    return $kept;
}

// Runs after our own node injection above (Yoast 11, Rank Math 99) so the guard
// sees the final graph, including anything we merged into it.
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Yoast SEO's own public Schema API filter; we are a consumer of it, not the owner.
add_filter('wpseo_schema_graph', function ($graph) {
    if (!is_array($graph)) {
        return $graph;
    }
    return array_values(bulkseme_drop_invalid_product_nodes($graph));
}, 20, 1);
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Rank Math's own public JSON-LD filter; we are a consumer of it, not the owner.
add_filter('rank_math/json_ld', function ($data) {
    return bulkseme_drop_invalid_product_nodes($data);
}, 999, 1);
