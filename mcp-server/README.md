# bulk-seo-meta-editor-mcp

MCP (Model Context Protocol) server companion for the **Bulk SEO Meta Editor for AI Agents** WordPress plugin. Lets Claude Code / Claude Desktop read and bulk-update Yoast SEO or Rank Math meta tags on WordPress sites with a single tool call.

> **Requires the WordPress plugin** to be installed and activated on the target site. Get it from the [WordPress.org Plugin Directory](https://wordpress.org/plugins/bulk-seo-meta-editor-for-ai-agents/) (after approval) or [GitHub](https://github.com/puneetindersingh/bulk-seo-meta-editor-for-ai-agents).

## Tools exposed

| Tool | What it does |
|---|---|
| `status` | Detects which SEO plugin is active (Yoast / Rank Math) and lists field aliases |
| `get_post_meta` | Read SEO meta for a single post or page |
| `set_post_meta` | Update SEO meta on a single post |
| `bulk_update` | Update SEO meta on up to 100 posts in one call |
| `list_posts` | Search/list posts to find IDs |
| `export_csv` | Export all posts' SEO meta as CSV |
| `import_csv` | Apply CSV updates (round-trips with `export_csv`) |

## Install

### Claude Code

```bash
claude mcp add bulk-seo-meta-editor \
  --env WP_BASE_URL=https://your-site.com \
  --env WP_USER=your-username \
  --env WP_APP_PASS='xxxx xxxx xxxx xxxx xxxx xxxx' \
  -- npx -y bulk-seo-meta-editor-mcp
```

### Claude Desktop (`claude_desktop_config.json`)

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

## Authentication

Uses standard WordPress Application Passwords. **HTTPS required in production.**

1. WP admin → Users → Your Profile → Application Passwords
2. Add new (e.g. name it "Claude Code")
3. Copy the 24-character password and put it in `WP_APP_PASS`

## Endpoint conventions

The server uses the universal `?rest_route=/X` URL form, so it works on every WordPress install regardless of permalink structure or web server (no dependence on htaccess / nginx rewrites).

## License

GPL-2.0-or-later
