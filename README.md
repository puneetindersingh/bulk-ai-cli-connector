# Bulk AI CLI Connector: Edit Pages, SEO and Schema with Claude, ChatGPT and MCP Agents

![Bulk AI CLI Connector: edit pages, SEO and schema with Claude, ChatGPT and MCP agents](assets/banner-1544x500.png)

Formerly Bulk SEO Meta Editor for AI Agents. Same plugin, same WordPress.org address.

[![WordPress Plugin Version](https://img.shields.io/wordpress/plugin/v/bulk-seo-meta-editor-for-ai-agents?logo=wordpress&color=9B51E0)](https://wordpress.org/plugins/bulk-seo-meta-editor-for-ai-agents/)
[![WordPress Plugin Downloads](https://img.shields.io/wordpress/plugin/dt/bulk-seo-meta-editor-for-ai-agents?color=9B51E0)](https://wordpress.org/plugins/bulk-seo-meta-editor-for-ai-agents/)
[![Tested WP Version](https://img.shields.io/wordpress/plugin/tested/bulk-seo-meta-editor-for-ai-agents?color=9B51E0)](https://wordpress.org/plugins/bulk-seo-meta-editor-for-ai-agents/)
[![License: GPL v2](https://img.shields.io/badge/license-GPL--2.0-FF7F50)](LICENSE)
[![npm](https://img.shields.io/npm/v/bulk-seo-meta-editor-mcp?logo=npm&color=FF7F50&label=MCP%20server)](https://www.npmjs.com/package/bulk-seo-meta-editor-mcp)

**Connect Claude, ChatGPT and MCP agents to WordPress.** A free, GPL-2.0 WordPress plugin that lets AI agents,
command-line tools and your own scripts read and rewrite SEO titles, meta descriptions, JSON-LD schema, redirects,
image alt text and the visible copy of a page, in bulk, over an authenticated REST API. Every page content edit is
backed up first and can be undone.

There is no AI inside the plugin and it makes no requests of its own to any service. Each call signs in as a WordPress user and can
only do what that user could do in wp-admin.

Available in the official directory: **[wordpress.org/plugins/bulk-seo-meta-editor-for-ai-agents](https://wordpress.org/plugins/bulk-seo-meta-editor-for-ai-agents/)**

What you can do with it:

- **Connect Claude or ChatGPT to WordPress**: Claude Code and other command-line agents call the REST routes directly, Claude Desktop connects through the bundled MCP server, and n8n, Zapier, Make or any script works over plain HTTPS
- **Bulk edit Yoast SEO and Rank Math meta** (up to 100 posts, pages, products or term archives per call): titles, descriptions, canonical, robots, Open Graph and Twitter fields. Works on the free editions
- **Edit Elementor and Beaver Builder pages with an AI agent** (2.0.0+): read a page's headings, paragraphs, button labels and list items as a list and change only the ones you name. SiteOrigin Page Builder, Advanced Custom Fields text fields (and, from 2.1.0, new rows in ACF repeaters and flexible content, and ACF fields on terms), Gutenberg, the classic editor, Divi and WPBakery pages are covered too
- **Undo and dry run** (2.0.0+): every page content write stores the previous version first, `dry_run` shows what would change and writes nothing
- **Custom JSON-LD schema** per post and per taxonomy term (FAQPage, Service, LocalBusiness, HowTo, any type), merged into the active SEO plugin's schema graph, or printed as the plugin's own block when no SEO plugin graph is on the page (1.9.0+)
- **Redirects, page copies and WooCommerce merchant listing fields** (2.0.0+)
- **API keys** (2.0.0+) for sites where the host or a security plugin blocks application passwords
- **Bulk image alt text** updates by image URL (resolves CDN paths and `-WIDTHxHEIGHT` thumbnail suffixes back to the parent attachment)
- **Post titles** through `/bulk` (`post_title`, slug untouched), **sitemap cache flush** (`POST /sitemap/flush`) and an **invalid Product schema guard** for quote-only WooCommerce catalogues (1.9.0+)
- **CSV import/export** for spreadsheet-based editing
- Taxonomy term archives, CPT archive pages, and global SEO scopes (author/date/search/404/home)
- A bundled **MCP server** so Claude Code or Claude Desktop can drive the SEO meta and schema tools with one-command setup

## What problem does it solve?

WordPress doesn't expose Yoast or Rank Math meta fields via the REST API by default. To edit a meta title programmatically you'd normally need WP-CLI access, a custom plugin, or the SEO plugin's premium tier. This plugin adds a clean, authenticated REST surface that:

- Works with whichever SEO plugin is already installed (or both)
- Validates per-post permissions (so a Contributor can't edit other authors' posts)
- Sanitizes URL fields automatically
- Returns predictable, plugin-neutral field aliases (`title`, `description`, `focus_kw`, ...)

## Installation

### Option 1: WordPress.org directory (recommended)

WP admin, **Plugins → Add New**, search for **Bulk AI CLI Connector**, then Install and Activate.
Updates then arrive through the normal WordPress update channel.

### Option 2: WP-CLI

```
wp plugin install bulk-seo-meta-editor-for-ai-agents --activate
```

### Option 3: zip upload

1. Download the zip from [WordPress.org](https://wordpress.org/plugins/bulk-seo-meta-editor-for-ai-agents/) or from [Releases](https://github.com/puneetindersingh/bulk-ai-cli-connector/releases)
2. WP admin, **Plugins → Add New → Upload Plugin**
3. Activate

### Option 4: must-use plugin (auto-active, for agency fleets)

Copy the whole plugin folder into `wp-content/mu-plugins/` and add a one-line loader file next to it, for example `wp-content/mu-plugins/load-bulk-seo.php` containing `<?php require __DIR__ . '/bulk-seo-meta-editor-for-ai-agents/bulk-seo-meta-editor-for-ai-agents.php';`. Since 2.0.0 the plugin is more than one file, so the main file on its own is not enough. No activation required and it cannot be switched off from the admin.

## Authentication

Uses standard WordPress Application Passwords. **HTTPS required in production.**

1. WP admin → **Users → Your Profile → Application Passwords**
2. Name it (e.g. `Claude Code`) → **Add New Application Password**
3. Copy the generated password (24 chars, shown once)

Use it as Basic Auth: `username:application password`

### API keys (2.0.0+)

For sites where a security plugin or the host blocks application passwords. An administrator makes a key under **Settings → AI CLI Connector**. It is shown once and only its SHA-256 hash is stored.

    curl -H 'X-Bulkseme-Key: bsme_...' https://example.com/wp-json/seo-meta-bridge/v1/status
    curl -u 'username:bsme_...'        https://example.com/wp-json/seo-meta-bridge/v1/status

- A key signs requests in as the account that made it and is removed when that account is deleted.
- It is read for REST API requests only, over HTTPS only.
- By default it is accepted on this plugin's routes only. Choose the wider scope when the tool also needs WordPress's own REST routes; such a key is as powerful as an application password.
- Switch key sign-in off with `add_filter('bulkseme_api_key_auth_enabled', '__return_false');`.

### Local development over HTTP

WordPress disables Application Passwords on non-HTTPS sites by default, so you'll get **401 Unauthorized** on a local `http://127.0.0.1` install. Drop this mu-plugin into `wp-content/mu-plugins/enable-app-passwords-local.php` for local testing **only**:

```php
<?php
// LOCAL DEV ONLY. Never ship this to production.
add_filter('wp_is_application_passwords_available', '__return_true');
add_filter('wp_is_application_passwords_available_for_user', '__return_true');
```

Also note: PHP's built-in dev server (`php -S`) does not handle WordPress URL rewriting, so `/wp-json/...` URLs return the front-page HTML on a local install. Use the `?rest_route=/...` form instead, it works on every WP install regardless of permalink structure or web server. The bundled MCP server already uses that form.

## REST endpoints

All endpoints require the user to have `edit_posts` capability. That is only the outer gate: every write also checks a per-object capability. Post writes check `edit_post` against the target post, image alt writes check `edit_post` against the resolved attachment, term writes check the `edit_term` meta capability against the term, and CPT archive plus global-scope writes require `manage_options` because they change the SEO plugin's site-wide settings.

### `GET /wp-json/seo-meta-bridge/v1/status`

Detect active SEO plugin and list available field aliases.

```bash
curl -u 'user:app pass' https://site.com/wp-json/seo-meta-bridge/v1/status
```

### `POST /wp-json/wp/v2/posts/{id}` (standard WP route)

Update meta on a single post. The plugin registers Yoast/Rank Math meta keys with the standard route, so vanilla WP REST works:

```bash
curl -u 'user:app pass' -X POST https://site.com/wp-json/wp/v2/posts/123 \
  -H 'Content-Type: application/json' \
  -d '{
    "meta": {
      "_yoast_wpseo_title": "New Title",
      "_yoast_wpseo_metadesc": "New meta description under 150 chars."
    }
  }'
```

### `POST /wp-json/seo-meta-bridge/v1/bulk`

Update up to 100 posts in one call.

```bash
curl -u 'user:app pass' -X POST https://site.com/wp-json/seo-meta-bridge/v1/bulk \
  -H 'Content-Type: application/json' \
  -d '{
    "items": [
      { "id": 123, "meta": { "_yoast_wpseo_title": "Title A" } },
      { "id": 124, "meta": { "_yoast_wpseo_title": "Title B" } }
    ]
  }'
```

Response: `{ "count": N, "results": [{ "id", "status", "errors": [...] }] }`

**Partial-success semantics:** within a single item, valid meta keys are applied even when other keys in the same payload are rejected. The item's `status` is `error` if any key was rejected; the `errors` array names which ones. Use it to detect typos without re-sending the whole batch. Example: a payload with `_yoast_wpseo_title` (valid) and `_arbitrary_key` (rejected) updates the title and reports `errors: ["unknown_or_disallowed_key:_arbitrary_key"]`.

**Allowlist:** only meta keys belonging to the active SEO plugin (Yoast or Rank Math) are accepted, plus the plugin's own `schema` / `schema_mode` fields and `post_title`. Arbitrary postmeta writes are rejected, this endpoint cannot be used as a generic postmeta editor.

**Post title (1.9.0+):** `{ "id": 123, "meta": { "post_title": "New title" } }` renames the post. Plain text only, the slug never changes, `edit_post` is required and an empty value returns `empty_post_title`. `/import` keeps `post_title` read-only, so CSV round trips cannot rename posts.

### `GET /wp-json/seo-meta-bridge/v1/export`

Export all posts' SEO meta as CSV (header row matches the `/import` shape). Since 1.7.0 the response is JSON: `{ "filename": "seo-meta-export.csv", "row_count": N, "csv": "..." }`. Save the `csv` field to a file:

```bash
curl -u 'user:app pass' 'https://site.com/wp-json/seo-meta-bridge/v1/export?post_type=post,page&status=publish&limit=500&offset=0' \
  | jq -r .csv > export.csv
```

### `POST /wp-json/seo-meta-bridge/v1/import`

Apply updates from CSV (multipart upload with field name `csv`) **or** JSON `{ rows: [...] }`. Round-trips with `/export`.

```bash
curl -u 'user:app pass' -X POST https://site.com/wp-json/seo-meta-bridge/v1/import \
  -F 'csv=@updated.csv'
```

### `POST /wp-json/seo-meta-bridge/v1/bulk-alts` (image alt text)

Update image alt text on up to 200 media-library attachments by image URL. The plugin resolves each URL to its attachment (stripping `-WIDTHxHEIGHT` size suffixes and query strings) and updates `_wp_attachment_image_alt`.

```bash
curl -u 'user:app pass' -X POST https://site.com/wp-json/seo-meta-bridge/v1/bulk-alts \
  -H 'Content-Type: application/json' \
  -d '{ "items": [ { "image_url": "https://site.com/wp-content/uploads/photo.jpg", "new_alt": "Descriptive alt text" } ] }'
```

Each row reports `status` (`ok` / `unchanged` / `skipped` / `error`), the resolved `attachment_id`, and the `previous_alt`.

### Custom JSON-LD schema (via `/bulk` + `/schema`)

Attach custom JSON-LD to a post or a taxonomy term. The plugin stores it and injects it into the active SEO plugin's schema graph at render time (Yoast via `wpseo_schema_graph`, Rank Math via `rank_math/json_ld`), so your nodes join the existing `@graph` rather than creating a second, competing block. Since 1.9.0, when no SEO plugin graph is printed on the page (no Yoast or Rank Math, or their schema output switched off), the stored schema is printed as the plugin's own `<script type="application/ld+json">` block, encoded with `JSON_HEX_TAG` and `JSON_HEX_AMP` on top of the markup checks applied on write. Filter `bulkseme_print_standalone_schema` turns that block off.

```bash
curl -u 'user:app pass' -X POST https://site.com/wp-json/seo-meta-bridge/v1/bulk \
  -H 'Content-Type: application/json' \
  -d '{ "items": [ { "id": 123, "meta": {
      "schema": "{\"@context\":\"https://schema.org\",\"@type\":\"FAQPage\",\"mainEntity\":[]}",
      "schema_mode": "add"
  } } ] }'
```

- `schema` is a JSON-LD string: a full document (`{ "@context": ..., "@graph": [...] }`), a list of nodes, or a single node. Invalid JSON is rejected with `invalid_json_schema`.
- `schema_mode`: `add` (default) merges your nodes into the page graph; `replace` makes your nodes the whole graph for that page.
- An empty `schema` value clears it.

Read it back with `GET /wp-json/seo-meta-bridge/v1/schema?id=123` (returns the decoded schema and its add/replace mode).

**Terms (1.9.0+):** send the same fields in a term item, `{ "id": 45, "kind": "term", "taxonomy": "product_cat", "meta": { "schema": "...", "schema_mode": "add" } }`, and read back with `GET /schema?id=45&kind=term&taxonomy=product_cat`. Term schema renders on the first page of the archive only (the page that shows the term description) and needs `edit_term` on that term. On Rank Math the archive keeps its `CollectionPage` type next to an injected `FAQPage`.

**Invalid Product guard (1.9.0+):** `Product` nodes without `offers`, `review` or `aggregateRating` are removed from the Yoast and Rank Math graphs (with any dangling `@id` pointers to them), because Google cannot show a product rich result for them and only reports them as invalid in Search Console. Priced products are untouched. Opt out with `add_filter('bulkseme_drop_invalid_product_schema', '__return_false');`.

### `POST /wp-json/seo-meta-bridge/v1/sitemap/flush` (1.9.0+)

Clears the Rank Math and Yoast sitemap caches so the next sitemap request rebuilds from live data. Useful after a batch of robots or slug edits over REST, which neither plugin notices on its own. Needs `edit_others_posts`. Response: `{ "ok": true, "flushed": ["yoast"] }`.

### Page content (2.0.0+)

`GET /wp-json/seo-meta-bridge/v1/content?id=123` tells you which builder owns the page and returns its text as nodes:

    { "id": 123, "builder": "elementor", "data_hash": "...", "nodes": [
        { "el": "3f9a21c", "widget": "heading", "setting": "title", "text": "Old heading", "html": false },
        { "el": "7bc0d1e", "widget": "text-editor", "setting": "editor", "text": "<p>Old copy</p>", "html": true } ] }

Change the ones you name and nothing else:

    POST /wp-json/seo-meta-bridge/v1/content
    { "id": 123, "base_hash": "...", "updates": [ { "el": "3f9a21c", "setting": "title", "value": "New heading" } ] }

- **Builders with nodes:** Elementor (classic and atomic widgets, heading level, link URLs), Beaver Builder, SiteOrigin Page Builder, the Accordions plugin, Advanced Custom Fields text fields. ACF repeaters and flexible content also take new rows (`op: add_row`, see `containers` in `GET /content`), and `kind=term` reaches a term's ACF fields.
- **Pages that keep their text in post content** (Gutenberg, classic, Divi, WPBakery): send `"post_content": "..."` instead of `updates`.
- **Term descriptions:** add `"kind": "term", "taxonomy": "product_cat"` and send `"description"`.
- `"dry_run": true` reports what would change and writes nothing. `base_hash` refuses the write if the page changed since you read it.
- Every value is cleaned with `wp_kses_post`. Every write stores a backup first and returns its id as `backup_ts`.
- `POST /content/replace { "id", "find", "replace" }` runs a literal find and replace across the page's text.
- `GET /content/backups?id=123` lists the newest ten backups. `POST /content/revert { "id", "ts" }` restores one.

The caller needs `edit_post` on the page (`edit_term` for a term), and where the page builder has its own access rule (Elementor's excluded roles, Beaver Builder's Builder Access), that rule too.

### `POST /wp-json/seo-meta-bridge/v1/clone` (2.0.0+)

`{ "source_id": 123, "title": "Copy of services", "slug": "services-copy", "status": "draft" }` copies a page or post into a new draft with its template, design meta, featured image and terms. SEO fields are not copied. Needs `edit_post` on the source, the right to create posts of that type, and `unfiltered_html`.

### `/wp-json/seo-meta-bridge/v1/redirect` (2.0.0+)

- `POST { "from": "/old-page/", "to": "/new-page/", "type": 301 }` adds or updates a rule. `type` is 301, 302, 307 or 410.
- `GET` lists the rules. `DELETE { "from": "/old-page/" }` removes one.
- With Rank Math's Redirections module on, rules are saved through Rank Math and show in its own list. Otherwise the plugin stores and serves them.
- Needs `manage_options`, and Rank Math's `rank_math_redirections` capability for rules that go through Rank Math.

### `/wp-json/seo-meta-bridge/v1/merchant-policy` (2.0.0+)

`POST { "return_policy": { ... }, "shipping": { ... } }` stores one return policy and shipping description. On WooCommerce product pages the plugin then adds `hasMerchantReturnPolicy`, `shippingDetails`, `brand` and a price start date to the Product schema your SEO plugin prints, where they are missing. `GET` shows what is stored and what would be printed. Needs `manage_options`.

### `/wp-json/seo-meta-bridge/v1/update` (2.0.2+)

`GET` shows the installed version and the version WordPress.org offers. `POST {"check": true}` asks WordPress to look for a new version now, `POST {"install": true}` installs the offered version the way the Update button does (the plugin stays active). It runs WordPress's own update code, so the build can only come from WordPress.org. Installing needs the right to update plugins, a site that forbids file changes is left alone, and an API key with the default scope cannot use the route.

## MCP server (Claude Code / Claude Desktop)

A Node.js MCP server is bundled in `mcp-server/`. Adds these tools to Claude:

- `status`: detect the active SEO plugin and list available field aliases
- `get_post_meta`: read SEO meta for one post
- `set_post_meta`: update one post
- `bulk_update`: update up to 100 posts or terms in one call
- `list_posts`: find post IDs by search or post type
- `list_terms`: find term IDs by taxonomy or search
- `export_csv`: pull all SEO meta as CSV
- `import_csv`: apply CSV updates
- `set_schema`: store custom JSON-LD against a post
- `get_schema`: read back the stored JSON-LD
- `set_schema` - attach custom JSON-LD schema to a post (add or replace mode)
- `get_schema` - read the custom JSON-LD schema stored on a post

### Install (Claude Desktop)

```json
{
  "mcpServers": {
    "bulk-seo-meta-editor": {
      "command": "npx",
      "args": ["-y", "bulk-seo-meta-editor-mcp"],
      "env": {
        "WP_BASE_URL": "https://your-site.com",
        "WP_USER": "your-username",
        "WP_APP_PASS": "xxxx xxxx xxxx xxxx xxxx xxxx"
      }
    }
  }
}
```

### Install (Claude Code)

```
claude mcp add bulk-seo-meta-editor \
  --env WP_BASE_URL=https://your-site.com \
  --env WP_USER=your-username \
  --env WP_APP_PASS='xxxx xxxx xxxx xxxx xxxx xxxx' \
  -- npx -y bulk-seo-meta-editor-mcp
```

Then in Claude: *"Pull the SEO meta for post 123 and rewrite the title to be ≤60 chars and meta description ≤150 chars, focusing on the page's primary keyword."*

## Field reference

| Alias | Yoast meta key | Rank Math meta key |
|---|---|---|
| `title` | `_yoast_wpseo_title` | `rank_math_title` |
| `description` | `_yoast_wpseo_metadesc` | `rank_math_description` |
| `focus_kw` | `_yoast_wpseo_focuskw` | `rank_math_focus_keyword` |
| `canonical` | `_yoast_wpseo_canonical` | `rank_math_canonical_url` |
| `noindex` | `_yoast_wpseo_meta-robots-noindex` (0/1/2) | inside `rank_math_robots[]` |
| `og_title` | `_yoast_wpseo_opengraph-title` | `rank_math_facebook_title` |
| `og_desc` | `_yoast_wpseo_opengraph-description` | `rank_math_facebook_description` |
| `og_image` | `_yoast_wpseo_opengraph-image` | `rank_math_facebook_image` |
| `tw_title` | `_yoast_wpseo_twitter-title` | `rank_math_twitter_title` |
| `tw_desc` | `_yoast_wpseo_twitter-description` | `rank_math_twitter_description` |
| `tw_image` | `_yoast_wpseo_twitter-image` | `rank_math_twitter_image` |

## Security model

- Application Passwords transmit as Basic Auth, so **HTTPS is required**. API keys are refused without it.
- Per-object capability check on every read and write (not just `edit_posts`): `edit_post` for posts and attachments, `edit_term` for terms, `manage_options` for archive and global settings, redirects and the merchant policy.
- The SEO plugin's and the page builder's own rules for who may edit are followed as well.
- Posts of a type with no edit screen and no REST route of its own (privacy requests, caches, the plugin's own backups) cannot be reached at all.
- Page copy is cleaned with `wp_kses_post` for every caller. A backup is stored before every content write and can only be restored onto the page it came from.
- Meta keys are allowlisted to the active plugin's known fields (plus the plugin's own `schema`, `schema_mode` and `post_title`). Arbitrary postmeta writes are rejected.
- Stored schema refuses raw `<` or `>` on write and strips them again on output; the standalone block is additionally encoded with `JSON_HEX_TAG` and `JSON_HEX_AMP`.
- URL-shaped fields are run through `esc_url_raw` before save.
- One admin screen, **Settings → AI CLI Connector**, for administrators only.

## Troubleshooting

### `403 Forbidden` from `/wp-json/seo-meta-bridge/v1/...` even with correct credentials

If `curl -u 'user:app pass' https://yoursite.com/wp-json/seo-meta-bridge/v1/status` returns `200` but the same call from a Python (`requests`) or Node (`fetch`) script with the same credentials returns `403`, the host's web-application firewall (Apache `mod_security`, Wordfence, Solid Security, Cloudflare WAF, etc.) is blocking the request based on **User-Agent** before WordPress ever sees it. Default UAs like `python-requests/2.x` and Node's bare `node`/`node-fetch/x` are on most WAF blocklists.

**Fix:** send a regular browser User-Agent on every request.

```python
# Python
requests.get(url, auth=(user, pw), headers={
    'User-Agent': 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) '
                  'AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36',
})
```

```js
// Node
await fetch(url, {
  headers: {
    'Authorization': auth,
    'User-Agent': 'Mozilla/5.0 (...) Chrome/124.0 Safari/537.36',
  },
});
```

The bundled MCP server does this automatically (v1.4.2+). Override the UA with `MCP_USER_AGENT` if your host has a stricter rule.

### Symptoms cheat-sheet

| Status | Likely cause |
|---|---|
| `401 Unauthorized` from any endpoint | Wrong username, wrong/revoked Application Password, or HTTPS not enabled (App Passwords are HTTPS-only by default) |
| `403 Forbidden` (HTML body) from REST endpoints, but admin login works | WAF blocking by User-Agent, see above |
| `404 rest_no_route` from `/seo-meta-bridge/v1/...` | Plugin not installed/active on this site (or this subsite, on multisite) |
| Front-end shows old meta after a successful `/bulk` write | Page cache (LiteSpeed, WP Rocket, Cloudflare APO), purge the URL. Yoast indexable cache is auto-flushed by v1.4.1+ |

## License

GPL-2.0-or-later.

## Author

[Puneet Singh](https://github.com/puneetindersingh).

More free SEO tools from the same maker, including a self-hosted SEO crawler and the SEO Side Panel Chrome extension: **[puneetindersingh.github.io](https://puneetindersingh.github.io/)**
