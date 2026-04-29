# Bulk SEO Meta Editor for AI Agents

A WordPress plugin that lets AI agents (Claude, GPT, Perplexity) and scripts bulk-update **Yoast SEO** or **Rank Math** meta tags via the WordPress REST API. Auto-detects which SEO plugin is active.

Includes:

- REST endpoints for individual and **bulk updates** (up to 100 posts/call)
- **CSV import/export** for spreadsheet-based editing
- A bundled **MCP server** so Claude Code or Claude Desktop can drive it with one-command setup

## What problem does it solve?

WordPress doesn't expose Yoast or Rank Math meta fields via the REST API by default. To edit a meta title programmatically you'd normally need WP-CLI access, a custom plugin, or the SEO plugin's premium tier. This plugin adds a clean, authenticated REST surface that:

- Works with whichever SEO plugin is already installed (or both)
- Validates per-post permissions (so a Contributor can't edit other authors' posts)
- Sanitizes URL fields automatically
- Returns predictable, plugin-neutral field aliases (`title`, `description`, `focus_kw`, ...)

## Installation

### Option 1 — WordPress admin (zip upload)

1. Download `bulk-seo-meta-editor-for-ai-agents.zip` from [Releases](https://github.com/puneetindersingh/bulk-seo-meta-editor-for-ai-agents/releases)
2. WP admin → **Plugins → Add New → Upload Plugin**
3. Activate

### Option 2 — must-use plugin (auto-active, recommended for agencies)

Drop `bulk-seo-meta-editor-for-ai-agents.php` into `wp-content/mu-plugins/`. No activation required, can't be turned off from the admin.

### Option 3 — Composer

```
composer require puneetindersingh/bulk-seo-meta-editor-for-ai-agents
```

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
// LOCAL DEV ONLY — never ship this to production
add_filter('wp_is_application_passwords_available', '__return_true');
add_filter('wp_is_application_passwords_available_for_user', '__return_true');
```

Also note: PHP's built-in dev server (`php -S`) does not handle WordPress URL rewriting, so `/wp-json/...` URLs return the front-page HTML on a local install. Use the `?rest_route=/...` form instead — it works on every WP install regardless of permalink structure or web server. The bundled MCP server already uses that form.

## REST endpoints

All endpoints require the user to have `edit_posts` capability. Per-post writes additionally check `edit_post` against the target post.

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

**Allowlist:** only meta keys belonging to the active SEO plugin (Yoast or Rank Math) are accepted. Arbitrary postmeta writes are rejected — this endpoint cannot be used as a generic postmeta editor.

### `GET /wp-json/seo-meta-bridge/v1/export`

Stream all posts' SEO meta as CSV (header row matches the `/import` shape).

```
GET /wp-json/seo-meta-bridge/v1/export?post_type=post,page&status=publish&limit=500&offset=0
```

### `POST /wp-json/seo-meta-bridge/v1/import`

Apply updates from CSV (multipart upload with field name `csv`) **or** JSON `{ rows: [...] }`. Round-trips with `/export`.

```bash
curl -u 'user:app pass' -X POST https://site.com/wp-json/seo-meta-bridge/v1/import \
  -F 'csv=@updated.csv'
```

## MCP server (Claude Code / Claude Desktop)

A Node.js MCP server is bundled in `mcp-server/`. Adds these tools to Claude:

- `status` — detect active SEO plugin
- `get_post_meta` — read SEO meta for one post
- `set_post_meta` — update one post
- `bulk_update` — update up to 100 posts
- `list_posts` — find post IDs by search/post-type
- `export_csv` — get all SEO meta as CSV
- `import_csv` — apply CSV updates

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

- Application Passwords transmit as Basic Auth — **HTTPS required**.
- Per-post `edit_post` capability check on every write (not just `edit_posts`).
- Meta keys are allowlisted to the active plugin's known fields — arbitrary postmeta writes are rejected.
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
| `403 Forbidden` (HTML body) from REST endpoints, but admin login works | WAF blocking by User-Agent — see above |
| `404 rest_no_route` from `/seo-meta-bridge/v1/...` | Plugin not installed/active on this site (or this subsite, on multisite) |
| Front-end shows old meta after a successful `/bulk` write | Page cache (LiteSpeed, WP Rocket, Cloudflare APO) — purge the URL. Yoast indexable cache is auto-flushed by v1.4.1+ |

## License

GPL-2.0-or-later.

## Author

[Puneet Singh](https://github.com/puneetindersingh).
