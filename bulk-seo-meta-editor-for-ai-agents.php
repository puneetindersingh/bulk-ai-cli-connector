<?php
/**
 * Plugin Name: Bulk AI CLI Connector: Edit Pages, SEO and Schema with Claude, ChatGPT and MCP Agents
 * Plugin URI:  https://github.com/puneetindersingh/bulk-ai-cli-connector
 * Description: Lets AI agents (Claude, ChatGPT, Perplexity) and automation scripts work on a site over the REST API: bulk-update Yoast SEO or Rank Math titles, descriptions and schema, edit page copy in Elementor, Beaver Builder, SiteOrigin, ACF and block or classic pages with a backup before every write, manage redirects, and sign in with an application password or an API key. Includes CSV import and export and a bundled MCP server.
 * Version: 2.0.2
 * Author: Puneet Singh
 * Author URI: https://github.com/puneetindersingh
 * License: GPL-2.0-or-later
 * Text Domain: bulk-seo-meta-editor-for-ai-agents
 */

if (!defined('ABSPATH')) exit;

// Keep in sync with the `Version:` header line above on every release.
define('BULKSEME_VERSION', '2.0.2');

define('BULKSEME_FILE', __FILE__);
define('BULKSEME_DIR', __DIR__);

// SEO fields and schema.
require_once BULKSEME_DIR . '/includes/seo-fields.php';
require_once BULKSEME_DIR . '/includes/schema.php';
require_once BULKSEME_DIR . '/includes/merchant.php';
require_once BULKSEME_DIR . '/includes/rest-seo.php';

// Signing in with an API key.
require_once BULKSEME_DIR . '/includes/key-auth.php';
require_once BULKSEME_DIR . '/includes/admin.php';

// Page content, for each place a site keeps it.
require_once BULKSEME_DIR . '/includes/backups.php';
require_once BULKSEME_DIR . '/includes/content.php';
require_once BULKSEME_DIR . '/includes/content-elementor.php';
require_once BULKSEME_DIR . '/includes/content-siteorigin.php';
require_once BULKSEME_DIR . '/includes/content-acf.php';
require_once BULKSEME_DIR . '/includes/content-beaver.php';
require_once BULKSEME_DIR . '/includes/content-terms.php';
require_once BULKSEME_DIR . '/includes/clone.php';

// Site-level tools.
require_once BULKSEME_DIR . '/includes/redirects.php';
require_once BULKSEME_DIR . '/includes/updates.php';

// ---- Schema merge filters ----------------------------------------------------
// Registered from this file on purpose: a companion build finds these two
// filters by this file's name so a page never prints the same schema twice.
// The work is done in includes/schema.php.
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Yoast SEO's own public Schema API filter; we are a consumer of it, not the owner.
add_filter('wpseo_schema_graph', function ($graph, $context) {
    return bulkseme_schema_merge_yoast($graph);
}, 11, 2);

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Rank Math's own public JSON-LD filter; we are a consumer of it, not the owner.
add_filter('rank_math/json_ld', function ($data, $jsonld) {
    return bulkseme_schema_merge_rankmath($data);
}, 99, 2);
