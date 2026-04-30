=== Bulk SEO Meta Editor for AI Agents ===
Contributors: puneetindersingh
Tags: ai, seo, rest-api, mcp, headless
Requires at least: 5.6
Tested up to: 6.9
Stable tag: 1.4.2
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Bulk-update Yoast SEO or Rank Math meta tags via REST API. For AI agents and automation scripts. CSV import/export and MCP server included.

== Description ==

WordPress doesn't expose Yoast SEO or Rank Math meta fields via the REST API by default. This plugin adds a clean, authenticated REST surface so AI agents (Claude, ChatGPT, Perplexity), automation tools (n8n, Zapier, Make), and headless CMS workflows can read and update SEO titles, descriptions, canonical URLs, robots directives, OG and Twitter fields with a single HTTP call.

Auto-detects which SEO plugin is active and exposes plugin-neutral field aliases (`title`, `description`, `focus_kw`, ...) so you don't need to memorise Yoast vs Rank Math meta keys.

= What's included =

* **REST endpoints** — read and write SEO meta on any post, page or custom post type via the standard `/wp/v2/posts/{id}` route or the namespaced helpers
* **Taxonomy term archives** — edit SEO meta on category/tag/custom-taxonomy archive pages too, not just posts (Yoast and Rank Math both supported)
* **Bulk update** — apply changes to up to 100 posts or terms in a single call
* **CSV import / export** — round-trip your SEO meta through Excel or Google Sheets, posts and terms in one file
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

= Trademarks =

Yoast SEO is a trademark of Yoast BV. Rank Math is a trademark of RankMath. Claude is a trademark of Anthropic PBC. ChatGPT is a trademark of OpenAI OpCo. WordPress is a trademark of the WordPress Foundation. All other product names, logos, and brands are property of their respective owners. This plugin is an independent integration and is not affiliated with, endorsed by, or sponsored by any of them. Trademarks are referenced only to describe interoperability with the listed products.

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

Yes — meta is registered on every public post type, so products, custom post types, etc. are all covered. Product category and product tag archives (and any other custom taxonomy) are also editable via the term endpoints (v1.3.0+).

= Can I edit category / tag archive SEO meta in bulk? =

Yes (v1.3.0+). Pass `?include_terms=1` to `/export` to pull category, tag, and any custom-taxonomy archive rows alongside posts. Post `/bulk` items with `kind: "term"` and the `taxonomy` slug to update them. Yoast term meta is stored in the `wpseo_taxonomy_meta` option; Rank Math uses standard term meta. Both are handled transparently — you pass plugin-neutral aliases (`title`, `description`, `og_title`, etc.) and the plugin writes to the right place.

= Why am I getting 401 Unauthorized on localhost? =

WordPress disables Application Passwords on non-HTTPS sites by default. For local development only, drop a small mu-plugin into `wp-content/mu-plugins/` with `add_filter('wp_is_application_passwords_available', '__return_true');`.

= Why does `curl` work but my Python/Node script gets 403 Forbidden? =

The host's web-application firewall (Apache `mod_security`, Wordfence, Solid Security, Cloudflare WAF) is rejecting the request based on User-Agent before WordPress sees it. Default UAs like `python-requests/2.x` and Node's bare `node-fetch` are on most WAF blocklists. Send a regular browser User-Agent header on every REST call (e.g. `Mozilla/5.0 ... Chrome/124.0 Safari/537.36`) and the 403 disappears. The bundled MCP server (v1.4.2+) already does this automatically — override with `MCP_USER_AGENT` if your host has a stricter rule. Symptom signature: `curl -u 'user:pass' https://site/wp-json/seo-meta-bridge/v1/status` returns 200, but the same call from Python/Node with the same credentials returns 403 with an Apache HTML error page (not a WP JSON error).

= Can the plugin be used to write arbitrary postmeta? =

No. Only meta keys belonging to the active SEO plugin (Yoast or Rank Math) are accepted. Other keys are rejected with `unknown_or_disallowed_key`.

== Changelog ==

= 1.4.2 =
* **Security — `/export` now filters by author for low-privilege roles.** Authors and Contributors (anyone with `edit_posts` but not `edit_others_posts`) calling `/export` previously received titles and SEO meta for every post and draft on the site, including drafts owned by other users. The endpoint now scopes the underlying `WP_Query` to the calling user's own authored posts when they lack `edit_others_posts`, matching the behaviour of the wp-admin Posts list. No change for Editors or Administrators — they continue to see all posts as before. Recommended upgrade for any site with multi-author setups.

= 1.4.1 =
* **Bug fix — term meta updates now invalidate Yoast's Indexable cache.** Previously, writing to `wpseo_taxonomy_meta` via `/bulk` (kind=term) updated the option correctly and `/export` read the new value back, but the FRONT-END kept rendering the old meta description because Yoast 14+ caches rendered SEO meta in the `yoast_indexable` table and our update didn't fire the hooks Yoast's Indexable_Term_Watcher listens on. After updating the term option, the plugin now also fires `do_action('wpseo_save_taxonomy_meta', $term_id, $taxonomy)` and the standard `do_action('edited_term', ...)`, and as a final safety net deletes the term's row in the Yoast indexable repository so Yoast rebuilds it on the next request. No-op when Yoast isn't installed (Rank Math path was unaffected and unchanged).

= 1.4.0 =
* CPT archive page support — write SEO meta to custom-post-type archive pages (e.g. `/challenges/`, `/news/`) for any CPT registered with `has_archive=true`. Yoast: stored in `wpseo_titles` option (`title-ptarchive-{ptype}`, `metadesc-ptarchive-{ptype}`); Rank Math: stored in `rank-math-options-titles` (`pt_{ptype}_archive_title`, `pt_{ptype}_archive_description`).
* Global SEO scopes — write meta for `author_archive`, `date_archive`, `search`, `p404`, `home` (latest-posts mode) via a single registry. New scopes can be added in one place without touching dispatch code.
* `/status` reports `supports_archives`, `supports_globals`, `archive_fields`, and `global_scopes` (alias map per active scope).
* `/export?include_archives=1` appends one synthetic row per CPT-with-archive (id=0, kind=cpt_archive). Backwards compatible — pre-1.4 consumers ignore unknown kind values.
* `/bulk` accepts the new kinds:
  * `{ id: 0, kind: "cpt_archive", post_type: "challenges", meta: {...} }`
  * `{ kind: "author_archive" | "date_archive" | "search" | "p404" | "home", meta: {...} }`
* `/import` reads cpt_archive and global rows from CSV for round-trip edits.
* Permission: cpt_archive requires the post type's `edit_posts` cap; global scopes require `manage_options` (admin-only — they affect site-wide SEO settings).

= 1.3.0 =
* **Taxonomy term archives are now editable** — categories, tags, and any custom taxonomy archive (e.g. WooCommerce `product_cat`, `product_tag`, theme-registered taxonomies). Previously the plugin only handled posts, pages and CPTs; term archive SEO meta had to be edited in wp-admin one term at a time.
* `/status` now reports `supports_terms: true` and a `term_fields` alias map so clients can detect the capability.
* `/export?include_terms=1` appends term archive rows to the CSV. New trailing `kind` and `taxonomy` columns flag term rows; post rows have `kind=post` with an empty `taxonomy`. Filter to specific taxonomies with `?taxonomy=category,product_cat`. Backwards compatible — v1.2.x clients that ignore the trailing columns see the same column shape.
* `/export?post_type=any` now correctly returns all public post types (previously needed an explicit comma list of CPTs).
* `/bulk` accepts term updates via `{ id: <term_id>, kind: "term", taxonomy: "category", meta: {...} }`. Existing post-update payloads are unchanged.
* `/import` reads `kind` and `taxonomy` columns from CSV uploads so a mixed posts+terms export round-trips cleanly.
* Yoast term meta is stored in the `wpseo_taxonomy_meta` option (read-modify-write per call). Rank Math term meta uses `wp_termmeta`. Per-taxonomy `edit_terms` capability is enforced on every write.

= 1.2.6 =
* `/export` now includes `title_chars` and `desc_chars` helper columns by default — character counts for the SEO title and description so you can spot over-limit cells at a glance when editing in Excel/LibreOffice/Google Sheets. Pass `?lengths=0` to get the original column shape. `/import` ignores these columns, so exports round-trip unchanged. Counts are static at export time.
* MCP `export_csv` tool gains an `include_lengths` boolean (default true) that maps to the new query param.

= 1.2.5 =
* `/export` now writes a UTF-8 BOM and CRLF line endings so curly quotes, em dashes and other non-ASCII characters open correctly in Excel, LibreOffice and Google Sheets instead of appearing as mojibake (e.g. `’` rendering as `â€™`).
* `/import` now strips a leading UTF-8 BOM before parsing, so re-uploading an exported CSV doesn't silently drop the `id` column on round-trip.

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
