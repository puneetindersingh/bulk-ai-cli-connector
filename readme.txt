=== Bulk SEO Meta Editor for AI Agents ===
Contributors: puneetindersingh
Tags: ai, seo, rest-api, mcp, headless
Requires at least: 5.6
Tested up to: 6.9
Stable tag: 1.2.4
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Bulk-update Yoast SEO or Rank Math meta tags via REST API. For AI agents and automation scripts. CSV import/export and MCP server included.

== Description ==

WordPress doesn't expose Yoast SEO or Rank Math meta fields via the REST API by default. This plugin adds a clean, authenticated REST surface so AI agents (Claude, ChatGPT, Perplexity), automation tools (n8n, Zapier, Make), and headless CMS workflows can read and update SEO titles, descriptions, canonical URLs, robots directives, OG and Twitter fields with a single HTTP call.

Auto-detects which SEO plugin is active and exposes plugin-neutral field aliases (`title`, `description`, `focus_kw`, ...) so you don't need to memorise Yoast vs Rank Math meta keys.

= What's included =

* **REST endpoints** — read and write SEO meta on any post, page or custom post type via the standard `/wp/v2/posts/{id}` route or the namespaced helpers
* **Bulk update** — apply changes to up to 100 posts in a single call
* **CSV import / export** — round-trip your SEO meta through Excel or Google Sheets
* **MCP server** — bundled Node.js companion (`bulk-seo-meta-editor-mcp` on npm) so Claude Desktop and Claude Code can drive the plugin natively
* **Auto-detection** — works with Yoast SEO or Rank Math, picks the active one automatically
* **Per-post permission checks** — Contributors and Authors can only edit their own posts via the API, just like the wp-admin UI
* **Allowlist enforcement** — only meta keys belonging to the active SEO plugin are accepted; arbitrary postmeta writes are rejected

= REST endpoints =

All endpoints live under `/wp-json/seo-meta-bridge/v1/` (or `?rest_route=/seo-meta-bridge/v1/...` on plain-permalink installs):

* `GET /status` — detect the active SEO plugin and list available fields
* `POST /bulk` — update SEO meta on up to 100 posts in one call
* `GET /export` — stream all posts' SEO meta as CSV
* `POST /import` — apply updates from a CSV upload or JSON rows array

The standard WordPress REST route also works: `POST /wp/v2/posts/{id}` with a `meta` payload containing Yoast or Rank Math keys.

= Field aliases =

| Alias | Yoast meta key | Rank Math meta key |
|---|---|---|
| title | _yoast_wpseo_title | rank_math_title |
| description | _yoast_wpseo_metadesc | rank_math_description |
| focus_kw | _yoast_wpseo_focuskw | rank_math_focus_keyword |
| canonical | _yoast_wpseo_canonical | rank_math_canonical_url |
| og_title | _yoast_wpseo_opengraph-title | rank_math_facebook_title |
| og_image | _yoast_wpseo_opengraph-image | rank_math_facebook_image |
| tw_title | _yoast_wpseo_twitter-title | rank_math_twitter_title |

Plus robots, OG/Twitter description fields, and Rank Math primary-taxonomy keys.

= MCP server (Claude / Claude Code) =

A Node.js MCP server is bundled at https://github.com/puneetindersingh/bulk-seo-meta-editor-for-ai-agents/tree/main/mcp-server and published to npm as `bulk-seo-meta-editor-mcp`. Add to Claude Desktop / Claude Code with one command and Claude can read, edit, bulk-update, and CSV-roundtrip SEO meta on any of your sites.

= Security =

* Application Passwords (Basic Auth) — HTTPS required in production
* Per-post `edit_post` capability check on every write
* Meta keys are allowlisted to the active plugin's known fields
* URL-shaped fields (canonical, og_image, twitter_image) sanitised through `esc_url_raw`
* No new admin UI, no settings page, nothing to misconfigure

== Installation ==

1. Upload `bulk-seo-meta-editor-for-ai-agents.zip` via **Plugins → Add New → Upload Plugin**, or drop the PHP file into `wp-content/plugins/` via SFTP
2. Activate
3. Set up an Application Password: **Users → Your Profile → Application Passwords → Add New**
4. Test: `curl -u 'username:app pass' https://yoursite.com/wp-json/seo-meta-bridge/v1/status`

For the optional MCP server, install via npm: `npm install -g bulk-seo-meta-editor-mcp` and configure Claude Desktop / Claude Code with `WP_BASE_URL`, `WP_USER`, and `WP_APP_PASS` environment variables.

== Frequently Asked Questions ==

= Does this work with Yoast SEO Free / Premium / Rank Math Free / PRO? =

Yes — the plugin uses the postmeta keys that all editions read from, so it works regardless of which tier is installed.

= Can I run both Yoast and Rank Math at the same time? =

You shouldn't — they conflict with each other in the front-end (duplicate meta tags). This plugin auto-detects whichever is active; if both are active, Yoast wins.

= Is it safe to install on production? =

Yes. The plugin is read-only until something hits the REST endpoints, and writes are scoped to SEO meta keys only — it cannot edit post content, users, or any other table. Per-post permission checks prevent privilege escalation.

= Does it work with WooCommerce products? =

Yes — meta is registered on every public post type, so products, custom post types, etc. are all covered.

= Why am I getting 401 Unauthorized on localhost? =

WordPress disables Application Passwords on non-HTTPS sites by default. For local development only, drop a small mu-plugin into `wp-content/mu-plugins/` with `add_filter('wp_is_application_passwords_available', '__return_true');`.

= Can the plugin be used to write arbitrary postmeta? =

No. Only meta keys belonging to the active SEO plugin (Yoast or Rank Math) are accepted. Other keys are rejected with `unknown_or_disallowed_key`.

== Changelog ==

= 1.2.4 =
* `/import` now treats empty CSV cells as "do not touch this field" instead of overwriting existing values with empty strings. Lets you upload a partially-filled CSV (e.g. only the `description` column populated) without wiping titles, OG fields, etc.

= 1.2.3 =
* `/bulk` now accepts friendly field aliases (`title`, `description`, `focus_kw`, ...) in addition to raw Yoast/Rank Math meta keys, so CSV columns from `/export` round-trip through `/bulk` without manual remapping.
* `/status` now reports the actual plugin version dynamically instead of a hardcoded string.

= 1.2.2 =
* Updated author metadata

= 1.2.1 =
* Replaced raw fopen/fclose with WP_Filesystem (CSV import) and string-builder CSV (export) per Plugin Check
* Bumped tested-up-to to 6.9
* Trimmed short description to <=150 chars

= 1.2.0 =
* Initial public release
* REST endpoints: /status, /bulk, /export, /import
* Standard `/wp/v2/posts/{id}` route registers Yoast and Rank Math meta keys
* Bundled Node.js MCP server companion
* Auto-detection of active SEO plugin (Yoast / Rank Math)
* Per-post permission checks; allowlisted meta keys; URL field sanitisation

== Upgrade Notice ==

= 1.2.0 =
Initial release.
