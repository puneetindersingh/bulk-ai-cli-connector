# Bulk SEO Meta Editor for AI Agents

[![WordPress Plugin Version](https://img.shields.io/wordpress/plugin/v/bulk-seo-meta-editor-for-ai-agents?logo=wordpress&color=9B51E0)](https://wordpress.org/plugins/bulk-seo-meta-editor-for-ai-agents/)
[![WordPress Plugin Downloads](https://img.shields.io/wordpress/plugin/dt/bulk-seo-meta-editor-for-ai-agents?color=9B51E0)](https://wordpress.org/plugins/bulk-seo-meta-editor-for-ai-agents/)
[![Tested WP Version](https://img.shields.io/wordpress/plugin/tested/bulk-seo-meta-editor-for-ai-agents?color=9B51E0)](https://wordpress.org/plugins/bulk-seo-meta-editor-for-ai-agents/)
[![License: GPL v2](https://img.shields.io/badge/license-GPL--2.0-FF7F50)](LICENSE)
[![npm](https://img.shields.io/npm/v/bulk-seo-meta-editor-mcp?logo=npm&color=FF7F50&label=MCP%20server)](https://www.npmjs.com/package/bulk-seo-meta-editor-mcp)

**Bulk edit Yoast SEO and Rank Math meta over the WordPress REST API.** A free, GPL-2.0 WordPress plugin that
exposes SEO titles, meta descriptions, canonicals, robots directives, Open Graph and Twitter fields, image alt
text and custom JSON-LD schema to AI agents (Claude, GPT, Perplexity), automation tools (n8n, Zapier, Make) and
your own scripts. Auto-detects whichever SEO plugin is active.

Available in the official directory: **[wordpress.org/plugins/bulk-seo-meta-editor-for-ai-agents](https://wordpress.org/plugins/bulk-seo-meta-editor-for-ai-agents/)**

Includes:

- REST endpoints for individual and **bulk meta-tag updates** (up to 100 posts/call): titles, descriptions, canonical, robots, Open Graph and Twitter fields
- **Bulk image alt text** updates by image URL (resolves CDN paths and `-WIDTHxHEIGHT` thumbnail suffixes back to the parent attachment)
- **Custom JSON-LD schema** injection per post (FAQPage, Service, LocalBusiness, HowTo, any type), merged into the active SEO plugin's existing schema graph
- **CSV import/export** for spreadsheet-based editing
- Taxonomy term archives, CPT archive pages, and global SEO scopes (author/date/search/404/home)
- A bundled **MCP server** so Claude Code or Claude Desktop can drive it with one-command setup

## What problem does it solve?

WordPress doesn't expose Yoast or Rank Math meta fields via the REST API by default. To edit a meta title programmatically you'd normally need WP-CLI access, a custom plugin, or the SEO plugin's premium tier. This plugin adds a clean, authenticated REST surface that:

- Works with whichever SEO plugin is already installed (or both)
- Validates per-post permissions (so a Contributor can't edit other authors' posts)
- Sanitizes URL fields automatically
- Returns predictable, plugin-neutral field aliases (`title`, `description`, `focus_kw`, ...)

## Installation

### Option 1: WordPress.org directory (recommended)

WP admin, **Plugins → Add New**, search for **Bulk SEO Meta Editor for AI Agents**, then Install and Activate.
Updates then arrive through the normal WordPress update channel.

### Option 2: WP-CLI

```
wp plugin install bulk-seo-meta-editor-for-ai-agents --activate
```

### Option 3: zip upload

1. Download the zip from [WordPress.org](https://wordpress.org/plugins/bulk-seo-meta-editor-for-ai-agents/) or from [Releases](https://github.com/puneetindersingh/bulk-seo-meta-editor-for-ai-agents/releases)
2. WP admin, **Plugins → Add New → Upload Plugin**
3. Activate

### Option 4: must-use plugin (auto-active, for agency fleets)

Drop `bulk-seo-meta-editor-for-ai-agents.php` into `wp-content/mu-plugins/`. No activation required and it cannot be switched off from the admin.

## Authentication

Uses standard WordPress Application Passwords. **HTTPS required in production.**

1. WP admin → **Users → Your Profile → Application Passwords**
2. Name it (e.g. `Claude Code`) → **Add New Application Password**
3. Copy the generated password (24 chars, shown once)

Use it as Basic Auth: `username:application password`

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

**Allowlist:** only meta keys belonging to the active SEO plugin (Yoast or Rank Math) are accepted. Arbitrary postmeta writes are rejected, this endpoint cannot be used as a generic postmeta editor.

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

Attach custom JSON-LD to a post. The plugin stores it and injects it into the active SEO plugin's schema graph at render time (Yoast via `wpseo_schema_graph`, Rank Math via `rank_math/json_ld`), so your nodes join the existing `@graph` rather than creating a second, competing block. The plugin emits no markup of its own, so there is no output-escaping surface.

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

- Application Passwords transmit as Basic Auth, so **HTTPS is required**.
- Per-object capability check on every write (not just `edit_posts`): `edit_post` for posts and attachments, `edit_term` for terms, `manage_options` for archive and global settings.
- Meta keys are allowlisted to the active plugin's known fields. Arbitrary postmeta writes are rejected.
- URL-shaped fields are run through `esc_url_raw` before save.
- No new admin UI; nothing to misconfigure.

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
