=== Bulk AI CLI Connector: Edit Pages, SEO and Schema with Claude, ChatGPT and MCP Agents ===
Contributors: puneetindersingh
Tags: mcp, claude, chatgpt, elementor, yoast
Requires at least: 5.7
Tested up to: 7.1
Stable tag: 2.4.0
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Connect Claude, ChatGPT and MCP agents to WordPress. Bulk edit SEO meta, schema, redirects and Elementor page copy over the REST API. With undo.

== Description ==

Bulk AI CLI Connector connects AI agents and command-line tools to a WordPress site. Claude, Claude Code, ChatGPT, an MCP client or a plain script can read and rewrite SEO titles, meta descriptions, JSON-LD schema, redirects, image alt text and the visible copy of a page, in bulk, over an authenticated REST API. Every page content edit is backed up first and can be undone.

There is no AI inside the plugin and it sends nothing anywhere: it makes no requests of its own to any service and needs no AI account. It gives the agent you already use a safe way into the site. Each call signs in as a WordPress user and can only do what that user could do in wp-admin.

= See what is live, where you work =

New in 2.3: an SEO item in the admin bar on every page you can edit. It opens a panel over the page with what the page really sends to search engines (title, description, robots, canonical, social tags, headings, every JSON-LD block), read from the page itself, so it is true whichever plugin printed it. Problems are marked, the title and description can be changed in place, custom schema can be added, and a history lists every SEO change on the page with who made it and how: in the editor, through an API key, or from the panel. The block editor and the classic editor get the same live view beside the post.

= What you can do with it =

* **Connect Claude or ChatGPT to WordPress**: Claude Code and other command-line agents call the REST routes directly. Claude Desktop connects through the bundled MCP server. Anything else that can send an authenticated HTTP request works the same way, including n8n, Zapier and Make.
* **Bulk edit Yoast SEO and Rank Math meta**: SEO titles, meta descriptions, focus keywords, canonical URLs, robots directives, Open Graph and Twitter fields on up to 100 posts, pages, products or term archives in one call. Works on the free editions.
* **Edit Elementor and Beaver Builder pages with an AI agent**: read the headings, paragraphs, button labels and list items of a page as a list, change only the ones you name, and leave the layout exactly as it was. SiteOrigin Page Builder, Advanced Custom Fields text fields, Gutenberg, the classic editor, Divi and WPBakery pages are covered too.
* **Add JSON-LD schema**: FAQPage, HowTo, Service or any other type, per post or per category, merged into the Yoast or Rank Math schema graph.
* **Add redirects**: 301, 302, 307 and 410 rules, saved into Rank Math's Redirections where that is in use.
* **Bulk update image alt text**: up to 200 media library images per call, matched by image URL.
* **Work in a spreadsheet**: export every SEO title and description as CSV, edit in Excel or Google Sheets, import it back.
* **Undo**: every page content write stores the previous version first. Dry runs show what would change and write nothing.

= Works with =

* **SEO plugins**: Yoast SEO and Rank Math, free and paid editions. The active one is detected automatically
* **Page builders and editors**: Elementor (including Elementor 4 atomic widgets), Beaver Builder, SiteOrigin Page Builder, Advanced Custom Fields, Gutenberg, the classic editor, Divi, WPBakery
* **WooCommerce**: products, product categories and product tags, plus return policy and shipping details on Product schema
* **Agents and tools**: Claude Code, Claude Desktop, ChatGPT, MCP clients, n8n, Zapier, Make, cURL, Python and Node scripts, headless front ends

= Why it exists =

WordPress does not expose Yoast SEO or Rank Math fields over the REST API, and page builders keep a page in post meta where the core REST API cannot reach it. Editing a meta title or an Elementor heading programmatically normally means WP-CLI with server access, a custom plugin, or a premium tier. This plugin adds a clean, authenticated REST surface instead.

It auto-detects which SEO plugin is active and exposes plugin-neutral field aliases (`title`, `description`, `focus_kw`, ...) so you don't need to memorise Yoast vs Rank Math meta keys.

This plugin was called Bulk SEO Meta Editor for AI Agents up to version 1.9.1. Same plugin, same address, same REST routes, new name: it now edits page content as well as SEO fields.

= New in 2.0: page copy, redirects and API keys =

Version 2.0 goes past SEO fields. The same REST API can now read and change the visible text of a page, wherever the site keeps it:

* **Page builders**: Elementor (including Elementor 4 atomic widgets), Beaver Builder, SiteOrigin Page Builder, and text fields in Advanced Custom Fields. These keep a page in post meta, where the core REST API cannot reach it. The plugin lists every piece of text as a node and changes only the ones you name. Layout, styling and widgets it does not know are written back exactly as they were read. In Advanced Custom Fields it can also add a row to a repeater or flexible content field (an FAQ item, a product tab), and edit the ACF fields of a category or any other taxonomy term.
* **Gutenberg, classic editor, Divi and WPBakery**: read and replace the post content, or run a literal find and replace across it.
* **Term descriptions**: category and product category introductions keep their headings, lists and tables.
* **Undo for every edit**: each write stores the previous version first. `GET /content/backups` lists what can be restored and `POST /content/revert` puts it back. The newest ten are kept per page.
* **Dry run and conflict check**: `dry_run` shows what would change and writes nothing. `base_hash` refuses a write when the page changed since it was read.
* **Redirects**: `POST /redirect` adds a 301, 302, 307 or 410 rule. On a site that uses Rank Math's Redirections the rule goes into Rank Math itself. Elsewhere the plugin serves it.
* **Copy a page**: `POST /clone` copies a page with its template, design meta and featured image into a new draft.
* **Merchant listing fields**: fills in return policy, shipping details and brand on WooCommerce Product schema from one stored policy.
* **API keys**: for sites where a security plugin or the host blocks application passwords. Made on **Settings > AI CLI Connector**, shown once, stored only as a hash.

= What's included =

* **REST endpoints**: read and write SEO meta on any post, page or custom post type via the standard `/wp/v2/posts/{id}` route (reads need `?context=edit`) or the namespaced helpers
* **Taxonomy term archives**: edit SEO meta on category/tag/custom-taxonomy archive pages too, not just posts (Yoast and Rank Math both supported)
* **Bulk update**: apply changes to up to 100 posts or terms in a single call
* **Bulk image alt text**: update `_wp_attachment_image_alt` on up to 200 media-library attachments per call by image URL. Resolves CDN paths and `-WIDTHxHEIGHT` thumbnail suffixes back to the parent attachment automatically (v1.5.0+)
* **CSV import / export**: round-trip your SEO meta through Excel or Google Sheets, posts and terms in one file
* **MCP server**: bundled Node.js companion (`bulk-seo-meta-editor-mcp` on npm) so Claude Desktop and Claude Code can drive the plugin natively
* **Auto-detection**: works with Yoast SEO or Rank Math, picks the active one automatically
* **Per-object permission checks**: Contributors and Authors can only edit posts, media and terms they could edit in wp-admin; site-wide archive and global settings require an administrator
* **Allowlist enforcement**: only meta keys belonging to the active SEO plugin are accepted; arbitrary postmeta writes are rejected
* **Custom JSON-LD schema** (v1.6.0+): store structured data (FAQPage, HowTo, Service, or any type) per post, and since 1.9.0 per category, tag or custom taxonomy term; it is injected into Yoast's or Rank Math's existing schema graph rather than as a duplicate block. When no SEO plugin schema graph is printed on the page (no Yoast or Rank Math, or their schema output switched off), the plugin prints the stored schema as its own JSON-LD block (v1.9.0+)
* **Post titles** (v1.9.0+): send `post_title` in a `/bulk` item to rename a post; the slug is never touched
* **Sitemap refresh** (v1.9.0+): `POST /sitemap/flush` clears the Yoast or Rank Math sitemap cache after REST edits
* **Invalid Product schema guard** (v1.9.0+): drops `Product` nodes that carry no `offers`, `review` or `aggregateRating`, the ones Search Console reports as invalid on quote-only WooCommerce catalogues. Disable with `add_filter('bulkseme_drop_invalid_product_schema', '__return_false')`
* **Page content** (v2.0.0+): read and edit the text of Elementor, Beaver Builder, SiteOrigin, ACF, Gutenberg, classic, Divi and WPBakery pages and of term descriptions, with a backup before every write. From v2.1.0: add rows to ACF repeaters and flexible content, and edit ACF fields on terms. From v2.2.0: Elementor dropdowns and switches, and adding a widget by copying an existing one
* **Redirects, page copies and merchant listing fields** (v2.0.0+): see "New in 2.0" above
* **API keys** (v2.0.0+): a second way to sign in, next to application passwords

= REST endpoints =

All endpoints live under `/wp-json/seo-meta-bridge/v1/` (or `?rest_route=/seo-meta-bridge/v1/...` on plain-permalink installs):

* `GET /status`: detect the active SEO plugin and list available fields
* `POST /bulk`: update SEO meta on up to 100 posts/terms in one call
* `POST /bulk-alts`: update image alt text on up to 200 media-library attachments by URL
* `GET /export`: export all posts' SEO meta as CSV, returned in the JSON response body
* `POST /import`: apply updates from a CSV upload or JSON rows array
* `GET /schema` (v1.6.0+): read back the custom JSON-LD schema stored on a post; add `kind=term&taxonomy=<slug>` for a term (v1.9.0+)
* `POST /sitemap/flush` (v1.9.0+): clear the Yoast or Rank Math sitemap cache so the next sitemap request rebuilds from live data
* `GET /content?id=N` (v2.0.0+): which builder owns the page and its text as nodes; add `kind=term&taxonomy=<slug>` for a term description
* `POST /content` (v2.0.0+): change nodes with `updates: [{el, setting, value}]`, or send `post_content` for a page that keeps its text there
* `POST /content/replace` (v2.0.0+): literal find and replace across the page's text
* `GET /content/backups` and `POST /content/revert` (v2.0.0+): list and restore earlier versions
* `POST /clone` (v2.0.0+): copy a page or post into a new draft
* `GET`, `POST`, `DELETE /redirect` (v2.0.0+): list, add or remove redirect rules
* `GET`, `POST`, `DELETE /merchant-policy` (v2.0.0+): the return policy and shipping details added to WooCommerce Product schema
* `POST /register-key` (v2.0.0+): store the hash of an API key from your own signed-in browser session
* `GET`, `POST /update` (v2.0.2+): see which version is installed and which one WordPress.org offers, and install the offered version without opening wp-admin

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

= MCP server for Claude Desktop and Claude Code =

A Node.js MCP server is bundled at https://github.com/puneetindersingh/bulk-ai-cli-connector/tree/main/mcp-server and published to npm as `bulk-seo-meta-editor-mcp`. Add to Claude Desktop / Claude Code with one command and Claude can read, edit, bulk-update, and CSV-roundtrip SEO meta on any of your sites.

The MCP server has ten tools, for SEO meta and schema. Page content, redirects and the other routes added in 2.0 are REST routes: an agent that can send HTTP requests, Claude Code for example, calls them directly.

= Security =

* Application Passwords (Basic Auth), HTTPS required in production. API keys (v2.0.0+) work over the REST API only, over HTTPS only, on this plugin's routes only unless made with the wider scope, and are stored as SHA-256 hashes
* Per-post `edit_post` capability check on every read and write, `edit_term` for terms, `manage_options` for redirects and site-wide settings
* The SEO plugin's and the page builder's own rules for who may edit are followed as well: Yoast and Rank Math role settings, Elementor's excluded roles, Beaver Builder's Builder Access setting
* Page copy is always cleaned with the post content allowlist (`wp_kses_post`), whoever sends it. Scripts and event handlers are never stored
* Meta keys are allowlisted to the active plugin's known fields
* URL-shaped fields (canonical, og_image, twitter_image, link URLs, redirect destinations) sanitised through `esc_url_raw`
* One admin screen, **Settings > AI CLI Connector**, and only administrators can open it

= Trademarks =

Yoast SEO is a trademark of Yoast BV. Rank Math is a trademark of RankMath. Claude is a trademark of Anthropic PBC. ChatGPT is a trademark of OpenAI OpCo. WordPress is a trademark of the WordPress Foundation. All other product names, logos, and brands are property of their respective owners. This plugin is an independent integration and is not affiliated with, endorsed by, or sponsored by any of them. Trademarks are referenced only to describe interoperability with the listed products.

== Installation ==

1. Install from the WordPress.org plugin directory: **Plugins → Add New**, search for **Bulk AI CLI Connector**. Or with WP-CLI: `wp plugin install bulk-seo-meta-editor-for-ai-agents --activate`. Or upload `bulk-seo-meta-editor-for-ai-agents.zip` via **Plugins → Add New → Upload Plugin**
2. Activate. A notice offers **Set up now**, or click **Settings** next to the plugin on the Plugins screen. Both open **Settings > AI CLI Connector**
3. Click **Make my key**, then **Copy connection details**, and paste the site address, username and key into your AI tool. An Application Password (**Users > Your Profile > Application Passwords**) works too where the host allows it
4. Test: `curl -u 'username:app pass' https://yoursite.com/wp-json/seo-meta-bridge/v1/status`, or with a key: `curl -H 'X-Bulkseme-Key: bsme_...' https://yoursite.com/wp-json/seo-meta-bridge/v1/status`

For the optional MCP server, install via npm: `npm install -g bulk-seo-meta-editor-mcp` and configure Claude Desktop / Claude Code with `WP_BASE_URL`, `WP_USER`, and `WP_APP_PASS` environment variables.

== Frequently Asked Questions ==

= How do I connect Claude or ChatGPT to WordPress? =

Install and activate the plugin, then open **Settings > AI CLI Connector** (or click **Settings** under the plugin's name on the Plugins screen), make a key and copy the connection details into your agent. An Application Password under **Users > Your Profile** works too where the host allows it. Claude Code and other command-line agents call the REST routes under `/wp-json/seo-meta-bridge/v1/` directly. Claude Desktop uses the bundled MCP server. ChatGPT and other tools work wherever they can send an authenticated HTTPS request. Start with `GET /status`, which reports the active SEO plugin and the fields available.

= Is this an MCP server for WordPress? =

It is a REST API plugin with an MCP server bundled alongside it. The MCP server (`bulk-seo-meta-editor-mcp` on npm) runs on your own computer and gives Claude Desktop and Claude Code ten tools for SEO meta and schema. Page content, redirects, page copies and API keys are REST routes, which an agent calls over HTTPS without MCP.

= Do I need WP-CLI, SSH or server access? =

No. "CLI" in the name refers to the command-line agents and scripts on your own computer. Everything goes over HTTPS to the WordPress REST API, so it works on shared hosting and managed hosts where you have no shell.

= Does the plugin send my content to an AI service? =

No. The plugin contains no AI and makes no requests of its own to any service. It only answers REST calls from a signed-in WordPress user. (The one exception is not about content: when asked through `/update`, it has WordPress check WordPress.org for a new version of this plugin and, if told to install it, download it from there.) What your agent does with the text it reads is between you and that agent.

= How do I bulk edit meta descriptions in WordPress? =

Activate the plugin, create an Application Password under **Users → Your Profile**, then send one `POST` to `/wp-json/seo-meta-bridge/v1/bulk` with up to 100 items. Each item is a post ID plus the fields to change, for example `{"id":123,"meta":{"description":"..."}}`. If you would rather work in a spreadsheet, `GET /export` returns every row as CSV (with live character counts) and `POST /import` writes the edited file back.

= Can I bulk edit Yoast SEO titles without Yoast Premium? =

Yes. The plugin writes the same postmeta keys Yoast Free reads from, so bulk title and description edits work on the free edition. Rank Math Free works the same way.

= Does it work with Rank Math as well as Yoast? =

Both. It detects whichever plugin is active and maps plugin-neutral aliases (`title`, `description`, `focus_kw`) onto the right meta keys for that plugin, so the same request body works on either site.

= Can Claude or ChatGPT edit my WordPress SEO meta? =

Yes, that is what it was built for. The bundled MCP server (`bulk-seo-meta-editor-mcp` on npm) gives Claude Code and Claude Desktop ten tools: status, read, write, bulk update, list posts, list terms, CSV export, CSV import, and schema get and set. Any other agent or automation tool can call the REST endpoints directly over HTTP Basic Auth.

= How do I bulk update image alt text? =

`POST /bulk-alts` accepts up to 200 `{ image_url, new_alt }` pairs per call and writes `_wp_attachment_image_alt` on the matching media-library attachment. CDN paths and `-WIDTHxHEIGHT` thumbnail suffixes resolve back to the parent attachment automatically.

= Can I add custom JSON-LD schema to a post? =

Yes, since 1.6.0. Schema is stored per post and injected through Yoast's `wpseo_schema_graph` filter or Rank Math's `rank_math/json_ld` filter, so your nodes merge into the graph those plugins already output rather than appearing as a duplicate second block. Add mode appends, replace mode swaps the graph.

= Can I add schema to a category or product category archive? =

Yes, since 1.9.0. Send a `/bulk` item with `kind: "term"`, the `taxonomy` slug and a `schema` field, the same way you send term titles and descriptions. The schema renders on the first page of that term's archive only, which is the page that shows the term description. Read it back with `GET /schema?id=<term_id>&kind=term&taxonomy=<slug>`. Writing needs the `edit_term` capability on that term.

= Does the schema work without Yoast or Rank Math? =

Yes, since 1.9.0. If no SEO plugin schema graph is printed on the page, whether because neither plugin is installed or because its schema output has been switched off, the plugin prints the stored schema as its own `<script type="application/ld+json">` block. Meta title and description editing still needs Yoast or Rank Math, because those fields belong to them.

= Why did the Product schema disappear from some WooCommerce product pages? =

Since 1.9.0 the plugin removes `Product` nodes that carry none of `offers`, `review` or `aggregateRating` from the Yoast and Rank Math schema graphs. Google requires at least one of them, so a quote-only product (no price) can never earn a product rich result and only produces an "invalid" entry in Search Console. Products with a price are untouched, and the node comes back on its own once a price is added. To keep those nodes anyway: `add_filter('bulkseme_drop_invalid_product_schema', '__return_false');`.

= Can I rename a post through the API? =

Yes, since 1.9.0. Send `post_title` in a `/bulk` item. The value is sanitised as plain text, the slug and URL never change, and the caller needs `edit_post` on that post. `/import` ignores the `post_title` column, so a CSV round trip cannot rename anything.

= Does this work with a headless or decoupled WordPress setup? =

Yes. Every field is readable and writable over REST, so a headless front end, a CI job or a content-ops script can manage SEO meta the same way it manages any other structured field.

= Does this work with Yoast SEO Free / Premium / Rank Math Free / PRO? =

Yes. The plugin uses the postmeta keys that all editions read from, so it works regardless of which tier is installed.

= Can I run both Yoast and Rank Math at the same time? =

You shouldn't. They conflict with each other in the front-end (duplicate meta tags). This plugin auto-detects whichever is active; if both are active, Yoast wins.

= Is it safe to install on production? =

Yes. The plugin does nothing until something calls the REST endpoints, and every call needs a signed-in user who could make the same change in wp-admin. SEO fields, schema, alt text and titles are checked per post. Since 2.0.0 page copy can be edited too: each write is cleaned with the post content allowlist, stores a backup first, and can be reverted. The plugin cannot create users, change settings outside its own, or run code that was sent to it. The only thing it can install is its own update from WordPress.org (through `/update`, for users who may update plugins).

= Can Claude or ChatGPT edit an Elementor or Beaver Builder page? =

Yes, since 2.0.0. `GET /content?id=<page id>` returns the page's headings, paragraphs, button labels and list items as nodes, each with the element id and setting name it lives in. Send the ones you want changed to `POST /content` as `updates: [{"el": "...", "setting": "...", "value": "..."}]`. Only those values change. Add `"dry_run": true` to see the result without writing.

= How do I undo an edit? =

Every write through `/content` stores the previous version first and returns its id as `backup_ts`. `POST /content/revert` with the page `id` and that `ts` restores it, and the revert can itself be undone. `GET /content/backups?id=<page id>` lists the newest ten. A backup can only be restored onto the page it was taken from.

= Who can edit page content through the API? =

Only a user who could edit that page in wp-admin. The plugin checks `edit_post` on the page (or `edit_term` on the term) for every read and write. Where the page builder has its own rule it is followed as well: a role that Elementor excludes from its editor, or that Beaver Builder's Builder Access setting keeps out, is refused here too.

= Can I send scripts or iframes in page copy? =

No. Every value is cleaned with WordPress's post content allowlist (`wp_kses_post`) before it is stored, whoever sends it, administrators included. A site owner who needs the unfiltered behaviour WordPress gives administrators in the editor can switch it on with `add_filter('bulkseme_allow_unfiltered_html', '__return_true');`. Even then the caller must hold the `unfiltered_html` capability and the request must ask for it with `unsafe_html: 1`.

= How do API keys work, and are they safe? =

An administrator makes a key on **Settings > AI CLI Connector**. It is shown once and only its SHA-256 hash is stored, so a copy of the database holds nothing that can be replayed. A key signs REST API requests in as the account that made it, with that account's capabilities, and is removed when that account is deleted. It is read only for REST API requests over HTTPS, so wp-login.php, wp-admin, admin-ajax and XML-RPC never see it.

By default a key is accepted on this plugin's own routes and nowhere else (and not on `/update`, which installs a new version of the plugin). If a tool also needs WordPress's own REST routes (to list posts or upload media, for example), make the key with the wider scope. Such a key is as powerful as an application password for your account, so store it like a password. Revoke any key on the same screen. To switch key sign-in off entirely: `add_filter('bulkseme_api_key_auth_enabled', '__return_false');`.

= How do I keep the plugin up to date on sites I manage over the API? =

Since 2.0.2 there is a route for it. `GET /update` shows the installed version and the version WordPress.org offers. `POST /update {"install": true}` installs the offered version straight away, the way the Update button does: the plugin stays active and the site shows its maintenance notice for a few seconds. `POST /update {"check": true}` asks WordPress to look for a new version now. The route runs WordPress's own update code, takes the build from WordPress.org and nowhere else, and cannot be given a file or an address. Installing needs the right to update plugins (an administrator, or a network administrator on a multisite), and a site that forbids file changes is left alone. An API key with the default scope cannot use this route: it takes a key with the wider scope, an application password or a browser session. The plugin does not change how WordPress updates plugins; automatic updates stay as you set them on the Plugins screen.

= How do redirects work? =

`POST /redirect` with `from` (a path on your site), `to` (a path or a full URL) and `type` (301, 302, 307 or 410). If Rank Math's Redirections module is on, the rule is saved through Rank Math and shows in its own list. Otherwise the plugin stores the rule and serves it. Redirects need an administrator, and Rank Math's own Redirections capability when the rule goes through Rank Math.

= What is left behind if I delete the plugin? =

Deleting the plugin removes its API key hashes, so no way of signing in is left behind. Stored schema, redirect rules, the merchant policy and content backups are kept in the database, because a site may still want them, and they come back into use if the plugin is installed again.

= Does it work with WooCommerce products? =

Yes. Meta is registered on every public post type, so products, custom post types, etc. are all covered. Product category and product tag archives (and any other custom taxonomy) are also editable via the term endpoints (v1.3.0+).

= Can I edit category / tag archive SEO meta in bulk? =

Yes (v1.3.0+). Pass `?include_terms=1` to `/export` to pull category, tag, and any custom-taxonomy archive rows alongside posts. Post `/bulk` items with `kind: "term"` and the `taxonomy` slug to update them. Yoast term meta is stored in the `wpseo_taxonomy_meta` option; Rank Math uses standard term meta. Both are handled transparently, you pass plugin-neutral aliases (`title`, `description`, `og_title`, etc.) and the plugin writes to the right place.

= Why am I getting 401 Unauthorized on localhost? =

WordPress disables Application Passwords on non-HTTPS sites by default. For local development only, drop a small mu-plugin into `wp-content/mu-plugins/` with `add_filter('wp_is_application_passwords_available', '__return_true');`.

= Why does `curl` work but my Python/Node script gets 403 Forbidden? =

The host's web-application firewall (Apache `mod_security`, Wordfence, Solid Security, Cloudflare WAF) is rejecting the request based on User-Agent before WordPress sees it. Default UAs like `python-requests/2.x` and Node's bare `node-fetch` are on most WAF blocklists. Send a regular browser User-Agent header on every REST call (e.g. `Mozilla/5.0 ... Chrome/124.0 Safari/537.36`) and the 403 disappears. The bundled MCP server (v1.4.2+) already does this automatically, override with `MCP_USER_AGENT` if your host has a stricter rule. Symptom signature: `curl -u 'user:pass' https://site/wp-json/seo-meta-bridge/v1/status` returns 200, but the same call from Python/Node with the same credentials returns 403 with an Apache HTML error page (not a WP JSON error).

= Can the plugin be used to write arbitrary postmeta? =

No. Only meta keys belonging to the active SEO plugin (Yoast or Rank Math) are accepted, plus the plugin's own `schema` and `schema_mode` fields and, since 1.9.0, `post_title`. Other keys are rejected with `unknown_or_disallowed_key`.

== Changelog ==

= 2.4.0 =
* Easier setup. The key screen moved from Tools > Agent API Keys to **Settings > AI CLI Connector**, and the old address forwards to it.
* A **Settings** link next to the plugin on the Plugins screen.
* After activation, a dismissible notice on the Dashboard and Plugins screen offers **Set up now**. It goes away once a key is made or it is dismissed, and an update never shows it.
* The setup screen walks through three steps, and a new key is shown together with the site address and username behind one **Copy connection details** button. The curl examples moved under "For developers".

= 2.3.2 =
* Live SEO panel: no price, offer or product checks on pages that are not WooCommerce products, so a service page is no longer told to add a price nobody set.
* Live SEO panel: fewer busywork warnings. A short title or description and a page without structured data are notes now; the Social row warns only without a share image; nested organizers and sponsors are not checked as the business.
* Live SEO panel: the canonical check ignores tracking parameters (utm_*, gclid, fbclid and similar) and parameter order.
* `GET /status` reports `merchant_enrichment.enabled` false when WooCommerce is not active.

= 2.3.1 =
* Fix: `POST /update` with `check` answered "up to date" without asking WordPress.org when the stored time of the last update check was in the future. The "checked less than a minute ago" shortcut took any future time as recent, so the check was skipped until the server's clock caught up (seen on a live site, about an hour ahead). Only a last check between 0 and 60 seconds ago counts now; a future one is treated as stale and the check runs. If WordPress.org does not answer, what WordPress knew is put back and the route answers 503, as before; a last check stamped in the future is put back as 0, so WordPress's own scheduled checks are not held back either.
* `GET /update` also reports `server_time` and `checked_in_future`, so a stored check time ahead of the server's clock is visible.

= 2.3.0 =
* New: live SEO panel. Signed-in users who may edit the page in view get an "SEO" item in the admin bar with a count of problems. It opens a panel over the page with four tabs. Overview: the search result and every tag read from the rendered page (title, description, robots including an X-Robots-Tag header, canonical, H1, social tags, images, links), each judged. Edit: SEO title, description and focus keyword with length checks, saved into Yoast SEO or Rank Math. Schema: every JSON-LD block with the plugin that printed it, missing required and recommended properties, duplicate Organization or WebSite nodes, broken JSON, links to an @id that is not on the page; custom schema can be added or set to replace the page's schema. History: the SEO changes on this post.
* New: change history. Every change to a post's SEO title, description, keyword, canonical, robots, social fields or custom schema is kept on the post (the last 30), with the time, the user and where it came from: the editor, an API key, the REST API, WP-CLI or the live panel.
* New: "Live on the site" in the block editor's document sidebar and as a side box in the classic editor: what the published page sends, read again after every save, with the last changes.
* New route `POST /live`, for the panel only. It needs a signed-in browser session with a REST nonce (keys and application passwords use `/bulk`), the right to edit that post or term, and each field passes the same checks as in `/bulk`, including the SEO plugin's own role rules for its settings groups.

= 2.2.0 =
* New: Elementor choice settings in `/content`. Dropdown, choose and switch controls on a widget's Content tab, including the fields of repeater rows (for example a Post Info row's `type`, `taxonomy` and `link`), are listed with `kind: choice` and their `options`. A write is accepted only when the value is one of those options. Heading levels stay `kind: tag`. Needs Elementor running, because the options come from its registered controls.
* New: add an Elementor element by copying an existing one. `POST /content {"inserts": [{"ref": "<element id>", "position": "after", "copy_from": {"post_id": 123, "el": "<element id>"}}]}` clones an element from the same page or another Elementor page you can edit, with new element and row ids and without its CSS ID, before, after or inside `ref`. Elements only go where Elementor accepts them (a section placed in a column becomes an inner section). Up to 20 per request, applied before `updates[]`, with the usual dry run, base_hash, backup and revert. New `atomic=1` writes nothing when any update or insert in the request fails.
* Security: the copy source passes the same checks as editing it (edit rights, the builder gate and Elementor's own permission). Unless the site allows raw HTML (the `bulkseme_allow_unfiltered_html` filter) and the request sends `unsafe_html=1`, a copy, including an administrator's, keeps only the standard element keys (no local styles), its text is cleaned like a direct write (the result says `sanitized: true` when anything was cleaned or left out), and they cannot copy HTML, shortcode, template, code or WordPress widgets, any widget with a code control, Custom CSS, custom attributes, dynamic tags, or any other setting that holds markup or a script link; nor set a choice to a raw output value. Size limits per copy, per request and per page (8 MB, 24 levels) stop runaway growth.
* `/status` reports `supports_choice_settings` and `supports_inserts`.

= 2.1.0 =
* New: add a row to an Advanced Custom Fields repeater or flexible content field. Send an update `{"op": "add_row", "el": "<field>", "position": 2, "layout": "<layout>", "values": {"<sub field>": "text"}}` in `POST /content`. `GET /content` lists every repeater and flexible content field under `containers`, with its row count and the sub fields a new row may fill. The rows below the insert move down with every value they hold, images and nested rows included. Text is cleaned like any other write, the field's maximum row count holds, and a stored value that belongs to another field, or that names a PHP class, stops the insert before anything is written. The backup holds the whole field, so `/content/revert` puts the rows back exactly.
* New: Advanced Custom Fields on taxonomy terms. With `kind=term`, `GET /content` lists the term's ACF text fields and rows under `acf`, and `POST /content` with `updates[]` edits them and adds rows, with the same backup and revert as on posts. The term description is left alone.
* Fix: a write to text kept in post meta (ACF, Beaver Builder, SiteOrigin, Accordions, and Elementor when its own resync is not available) bumped the modified date by saving the post again. For a user without unfiltered_html that ran the stored title, content and excerpt through kses and could rewrite markup the write never aimed at, such as the trailing semicolon of a `style` attribute. Only the date changes now.
* Security hardening: `kind=term` no longer reaches a taxonomy that has neither an edit screen nor a REST route (such as post formats), the same reach WordPress itself gives. Present since 2.0.0.
* Security hardening: on a network, the fallback that reads ACF field definitions from stored meta when no field group matches is for super admins only.
* New filter `bulkseme_user_can_edit_term_acf` keeps a role out of a term's ACF fields, as `bulkseme_user_can_edit_with_builder` does for posts.
* `/status` reports `supports_acf_rows` and `supports_term_acf`.

= 2.0.2 =
* Security: `/import` read an uploaded CSV file line by line, so one quoted cell that held line breaks was read as several rows. A post title can hold line breaks and `/export` wrote it as it was, so an Author could plant rows in a title, and they were applied with the rights of whoever re-imported that export: SEO fields of posts the Author may not edit, and the site-wide title settings. The file is now read as a whole, the way RFC 4180 defines it, and line breaks and other control characters are taken out of every exported cell. If you read an export in a script, use a CSV reader over the whole text, never line by line. Present in every earlier version. Found in an independent review; not known to have been used.
* Security: stored JSON-LD schema is no longer printed on a password-protected post until the visitor has entered the password.
* Security hardening: a key with the default scope signs in on this plugin's own routes by name, and no longer on everything under its REST namespace, so it cannot reach a route another plugin registers there.
* Fix: `/export` ended with a PHP error when `post_type`, `status` or `taxonomy` was sent as a list (`?status[]=draft`). Lists are accepted now. A list sent where one value belongs is refused cleanly, and a list sent as an `id` is no longer read as post 1.
* Fix: a URL field (canonical, Open Graph image, Twitter image) holding bytes that are not valid UTF-8, written through WordPress's own REST route, was answered with a 500. Such bytes no longer reach the database.
* Hardening: WPBakery design rules rebuilt with `rebuild_wpb_css` go through the filter WordPress uses for inline styles. HTML written to a SiteOrigin widget or Beaver Builder module that the plugin has no field map for has the quotes in its text stored as entities.
* `/import` of a file: 10 MB limit, a file that ends inside a quoted cell is refused, and rows whose cell count does not match the header are counted in `skipped_rows`.
* New: `GET` and `POST /update`. See which version is installed and which one WordPress.org offers, and install the offered version without opening wp-admin. It runs WordPress's own update code, so the build can only come from WordPress.org; installing needs the right to update plugins, a site that forbids file changes is left alone, and a key with the default scope cannot use the route.
* Security hardening: a key that is revoked here is also taken out of the copy a companion build keeps of the keys it handed over, whether or not that build is active at the time.
* MCP server 1.7.2: `list_posts` lists only post types the site exposes, every tool argument that reaches a request path is checked, requests time out after 60 seconds, an answer over 20 MB is refused, and the dependency lock file is refreshed.
* The GitHub repository moved to github.com/puneetindersingh/bulk-ai-cli-connector.

= 2.0.1 =
* Security hardening: `/clone` now checks a stored meta value in exactly the form WordPress reads it back. A value planted straight into the database with a NUL byte in front of a serialised object could get past the check that keeps `/clone` from recreating objects of other classes. Using it needed a row written outside WordPress and a caller with the `unfiltered_html` capability. Found in an independent review; not known to have been used.

= 2.0.0 =
* Renamed from "Bulk SEO Meta Editor for AI Agents" to "Bulk AI CLI Connector". The plugin address, folder, REST routes and stored data are unchanged.
* New: page content. `GET /content` and `POST /content` read and edit the visible text of a page for Elementor (classic and Elementor 4 atomic widgets, heading level, link URLs and per-element custom CSS), Beaver Builder, SiteOrigin Page Builder (core text widgets and the SiteOrigin Widgets Bundle: editor, headline, button, call to action, accordion, tabs, hero, features, testimonials and image), the Accordions plugin and Advanced Custom Fields text fields, and the post content of Gutenberg, classic, Divi and WPBakery pages. `POST /content/replace` runs a literal find and replace. Add `kind=term&taxonomy=<slug>` for a term description, which keeps headings, lists and tables.
* New: a backup before every content write, with `GET /content/backups` and `POST /content/revert`. Backups are kept in a hidden post type that nobody can read or edit outside these two routes, the newest ten per item, and a backup can only be restored onto the item it came from.
* New: `dry_run` and `base_hash` on content writes.
* New: `POST /clone` copies a page or post into a new draft with its template, design meta, featured image and terms. SEO fields are not copied.
* New: `GET`, `POST` and `DELETE /redirect`. Rules go through Rank Math's Redirections when that module is on, otherwise the plugin stores and serves them.
* New: `GET`, `POST` and `DELETE /merchant-policy`. Adds return policy, shipping details, brand and a price start date to WooCommerce Product schema that the SEO plugin left without them. Opt out with `add_filter('bulkseme_merchant_enrichment', '__return_false')`.
* New: API keys. Made on Tools > Agent API Keys, shown once, stored as SHA-256 hashes, REST only, HTTPS only. Send a key in the `X-Bulkseme-Key` header or as the password of HTTP Basic auth. A key is accepted on this plugin's own routes only, unless it is made with the wider scope, which covers every REST route. A key is tied to the account that made it and is removed when that account is deleted. `POST /register-key` stores the hash of a key from an administrator's own browser session.
* Security: content routes check `edit_post` or `edit_term` per item and follow the page builder's own access rules (Elementor's excluded roles, Beaver Builder's Builder Access). Every value is cleaned with `wp_kses_post`, administrators included. Storing unfiltered HTML needs the `bulkseme_allow_unfiltered_html` filter, the `unfiltered_html` capability and `unsafe_html: 1` in the request.
* Security: redirects and merchant policy need `manage_options`. Redirects written through Rank Math also need its `rank_math_redirections` capability.
* Security: posts of a type that has no edit screen and no REST route of its own are now out of reach of every route, including `/bulk`, `/export` and `/schema`. Before this, a user could rename such a record through `post_title` in `/bulk` if WordPress counted them as its author, and that included a privacy request they had filed themselves, whose title is the address the request acts on. Recommended update for every site.
* Security: stored JSON-LD is limited to 128 KB and 5,000 values per post or term (`schema_too_large`), because it is read on every view of the page. Filters: `bulkseme_schema_max_bytes`, `bulkseme_schema_max_values`.
* Security: on a site with neither Yoast SEO nor Rank Math, storing schema now needs `edit_others_posts` (Editor and above), in line with Yoast SEO's own default for schema settings. Filter: `bulkseme_user_can_edit_field_group`.
* Fix: titles and descriptions that use replacement variables are stored intact. WordPress's general text cleaner removed anything that looked like a percent-encoded byte, so `%%category%%` was stored as `%tegory%%` and Rank Math's `%date%` as `te%`. The active SEO plugin's own text cleaner is now used. Post titles keep their percent signs too.
* Fix: a merchant policy number that is not finite (for example `1e400`) is refused. It could not be printed as JSON and emptied the product schema block.
* `/status` reports the new capabilities: `supports_content`, `supports_redirects`, `supports_api_key`, `supports_clone`, `builders` and more. Page builder version numbers, the redirect engine and the merchant policy summary are only shown to administrators. Filter: `bulkseme_status`.
* MCP server 1.7.1: post ids and post type names are checked before they are used in a request path.
* The plugin is now split into files under `includes/`. No setting, stored value or existing route changed name.
* Deleting the plugin now removes its API key hashes, on every site of a network.

= 1.9.1 =
* Security: SEO fields registered on the core `wp/v2` routes are now returned in the `edit` context only. Before this, a plain `GET /wp/v2/posts/<id>` included them for anyone who could read the post, logged in or not, and that included the focus keyword, which is not printed anywhere on the page. Clients that read `meta` from a core route must add `?context=edit`. The bundled MCP server already does.
* Security: the active SEO plugin's own role rules now apply to writes through `/bulk`, `/import` and the core routes. Yoast SEO: canonical, robots and breadcrumb title fields, and custom schema, need `wpseo_edit_advanced_metadata` or `wpseo_manage_options` while Yoast's "Security: no advanced or schema settings for authors" setting is on. Rank Math: its `rank_math_onpage_general`, `rank_math_onpage_advanced`, `rank_math_onpage_social` and `rank_math_onpage_snippet` capabilities. A refused field is reported as `forbidden_field:<key>`. Filter: `bulkseme_user_can_edit_field_group`.
* Security: archive and global scope settings now need the SEO plugin's own settings capability (`wpseo_manage_options` or `rank_math_titles`) as well as `manage_options`. This matters on multisite and on sites that use a role manager.
* Security: `/bulk-alts` now also requires `upload_files`, so only users who can manage media can call it.
* Security: `/export` now neutralises spreadsheet formulas. A cell that starts with `=`, `+`, `-`, `@`, a tab or a carriage return is written with a leading apostrophe, so Excel, Google Sheets and LibreOffice show it as text instead of running it. `/import` removes that apostrophe again, so a CSV round trip gives back the stored value unchanged. Use `/bulk` for values that must be taken literally.
* Security hardening: text fields sent as an array or an object are now refused with `invalid_value_type:<key>` instead of being stored unsanitised. Before this, such a value could also end the request with a PHP error when it reached a URL field. Booleans and numbers are stored as text, and the two Rank Math list fields (`robots`, advanced robots) still accept lists.
* `schema` sent as a JSON object or array, not only as a JSON string, is now validated and stored. It used to be read as empty, which cleared the stored schema.
* `rank_math_primary_<taxonomy>` is only accepted for a taxonomy that exists.
* Items and rows that are not objects are answered with `invalid_item` or `invalid_row`.

= 1.9.0 =
* New: custom JSON-LD schema on taxonomy terms. `/bulk` items with `kind: "term"` accept the same `schema` and `schema_mode` fields as posts, stored in term meta under the same keys, and `GET /schema?id=<term_id>&kind=term&taxonomy=<slug>` reads them back. The schema renders on the first page of the term archive only. On Rank Math the archive keeps its `CollectionPage` type next to an injected `FAQPage` instead of losing it.
* New: schema output when no SEO plugin graph is printed. With no Yoast or Rank Math installed, or with their schema output switched off, stored schema is printed as the plugin's own JSON-LD block (encoded with JSON_HEX_TAG and JSON_HEX_AMP, on top of the existing markup checks). When a graph is printed, nothing changes: the nodes still merge into it. Filter `bulkseme_print_standalone_schema` disables the standalone block.
* New: `post_title` accepted in `/bulk` items. Plain text only, slug untouched, `edit_post` required, empty values rejected with `empty_post_title`. `/import` keeps treating `post_title` as a read-only column.
* New: `POST /sitemap/flush` clears the Rank Math and Yoast sitemap caches (`edit_others_posts` required) so sitemaps pick up REST edits without a wp-admin save.
* New: invalid Product schema guard. `Product` nodes without `offers`, `review` or `aggregateRating` are removed from the Yoast and Rank Math graphs, together with any dangling `@id` references to them. Priced products are untouched. Disable with `add_filter('bulkseme_drop_invalid_product_schema', '__return_false')`.
* `/status` now reports `supports_term_schema`, `supports_post_title`, `supports_sitemap_flush` and `drops_invalid_product_schema`.
* Requires WordPress 5.7 or later (was 5.6) for `wp_print_inline_script_tag()`.

= 1.8.2 =
* Security: the custom JSON-LD schema field now rejects any value that contains a raw HTML angle bracket (< or >) when saved, and strips angle brackets from stored schema on output. This closes a stored cross-site scripting issue on Rank Math sites, where a crafted JSON-LD object key could break out of the schema script block. Yoast sites were not affected. No valid JSON-LD needs raw angle brackets, so normal schema is untouched. Reported responsibly by Junhui Mo (Guangzhou University).

= 1.8.1 =
* Security: `GET /export` now capability-filters every row it returns, using the same capability the matching write path enforces. Term rows require the `edit_term` meta capability on each term, so taxonomies registered with their own capabilities are respected. CPT archive rows require `manage_options`, matching the archive write path, because those values live in the SEO plugin's site-wide settings rather than on an object. Post rows are checked with `edit_post` per post instead of relying on the `edit_others_posts` pre-filter, which covers only the core post capabilities and not custom post types that declare their own `capability_type`. Rows the caller cannot manage are omitted, so `/export` no longer returns SEO metadata for objects the caller could not open in wp-admin.
* Note: a caller who could previously export taxonomy terms or CPT archive settings without holding the matching capability will now receive fewer rows. Administrators, and Editors acting within their own capabilities, see no change. `row_count` still reports the number of rows actually emitted.

= 1.8.0 =
* Security: every bulk write now enforces a per-object capability check in addition to the route-level `edit_posts` gate. `POST /bulk-alts` checks `edit_post` on each resolved attachment, term updates check the `edit_term` meta capability on each term, and CPT archive updates now require `manage_options` (they change the SEO plugin's site-wide settings, the same bar the global scopes already had). Authors and Contributors can no longer touch objects they could not edit in wp-admin.
* Changed: writes into Yoast SEO settings now go through Yoast's own public API instead of updating its stored options directly. Term meta is saved with `WPSEO_Taxonomy_Meta::set_values()` (merged with the term's current values so untouched fields are preserved) and archive/global titles with `WPSEO_Options::set()`, which routes each key to the right option group, validates it, and clears Yoast's caches. Rank Math ships no public setter for its title settings, so those writes still read-modify-write the option array Rank Math reads back, through a single documented helper.
* Internal: the plugin version constant is now a plain define kept in sync with the plugin header, replacing the runtime header parse.

= 1.7.0 =
* Changed: `GET /export` now returns JSON in the shape `{ "filename", "row_count", "csv" }` instead of streaming raw CSV bytes. The `csv` field holds the complete CSV content; save it to a file to open in a spreadsheet. The plugin no longer produces any direct output, so every response flows through the REST API serializer. Query parameters and the CSV column shape are unchanged, and `/import` accepts the same CSV as before.
* Upgraders: scripts that saved the old raw response body to a file should now read the `csv` field of the JSON response. The bundled MCP server 1.7.0 handles both shapes automatically.

= 1.6.1 =
* Fix: image alt updates now resolve far more image URLs to their media-library attachment. Previously the resolver only matched the exact stored file URL, so it missed the most common front-end forms and returned "not in media library" for images that were genuinely in the library. It now also resolves: size crops (foo-1024x683.jpg), the "-scaled" big-image original WordPress generates since 5.3 (whether the page references the scaled file or the plain original), cache-busting query strings, and images served from a CDN or alternate host that keep the /wp-content/uploads/ path. A safe filename fallback resolves year/month folder drift only when exactly one attachment owns that filename, so it never guesses between duplicates. Genuinely external or page-builder-hardcoded images (including most inline SVG icons) still report a clear "not an attachment" hint, since their alt lives in the builder, not the media library.

= 1.6.0 =
* New: custom JSON-LD schema per post or page. Store a block of structured data against any post and it is rendered inside the active SEO plugin's schema graph at output time (Yoast via the `wpseo_schema_graph` filter, Rank Math via `rank_math/json_ld`). The plugin emits no markup itself, so there is no extra output-escaping surface, and the nodes join the existing `@graph` instead of creating a second competing block (no duplicate-schema conflict).
  * Write with the `schema` field on `/bulk` or `/import`, e.g. `{ id: 12, meta: { schema: "{...JSON-LD...}", schema_mode: "add" } }`. JSON is validated on write; invalid JSON is rejected with `invalid_json_schema`.
  * `schema_mode`: `add` (default) merges your nodes into the graph; `replace` makes your nodes the whole graph for that page.
  * Accepts a full document (`{ "@context": ..., "@graph": [...] }`), a bare list of nodes, or a single node object. Per-node `@context` is dropped so the SEO plugin sets one top-level context.
  * New `GET /schema?id=123` reads back the stored schema and mode for a post.
  * `/status` now reports `supports_schema: true`.

= 1.5.1 =
* Internal: renamed all internal PHP function and define prefixes from `seo_meta_bridge_*` / `SEO_META_BRIDGE_*` to `bulkseme_*` / `BULKSEME_*` so the prefix derives directly from the plugin slug. No public REST surface changes: endpoints still live at `/wp-json/seo-meta-bridge/v1/*`. No migration required.
* Internal: removed `if (!function_exists(...))` guards around the plugin's own helper functions per WordPress.org plugin guidelines. Unique prefixes prevent collisions; the guards were unnecessary and risked loading a same-named function from another plugin instead of ours.
* Code clarity: added comments next to `update_option('wpseo_titles', ...)` and `update_option('rank-math-options-titles', ...)` documenting that those option keys belong to Yoast SEO and Rank Math respectively, not this plugin. We integrate WITH those plugins by reading and writing the exact keys they use; a custom-prefixed key would store data nowhere those plugins look.

= 1.5.0 =
* **New: bulk update image alt text.** New endpoint `POST /seo-meta-bridge/v1/bulk-alts` accepts `{ items: [{ image_url, new_alt }, ...] }` (max 200/req) and updates `_wp_attachment_image_alt` on the matching media-library attachment. Resolves URLs via `attachment_url_to_postid()` with two fallbacks: strips `-WIDTHxHEIGHT.ext` size suffix (so a `-300x200.jpg` thumb still maps to its parent attachment), then strips any query string. Each row reports `status: ok | unchanged | skipped | error` plus `attachment_id`, `matched_via`, and `previous_alt` so callers can audit changes. Skipped rows include a hint when the URL doesn't resolve to a media-library item (typical for CDN-hosted or page-builder-hardcoded images). `/status` now reports `supports_alts: true`.

= 1.4.2 =
* **Security: `/export` now filters by author for low-privilege roles.** Authors and Contributors (anyone with `edit_posts` but not `edit_others_posts`) calling `/export` previously received titles and SEO meta for every post and draft on the site, including drafts owned by other users. The endpoint now scopes the underlying `WP_Query` to the calling user's own authored posts when they lack `edit_others_posts`, matching the behaviour of the wp-admin Posts list. No change for Editors or Administrators: they continue to see all posts as before. Recommended upgrade for any site with multi-author setups.

= 1.4.1 =
* **Bug fix: term meta updates now invalidate Yoast's Indexable cache.** Previously, writing to `wpseo_taxonomy_meta` via `/bulk` (kind=term) updated the option correctly and `/export` read the new value back, but the FRONT-END kept rendering the old meta description because Yoast 14+ caches rendered SEO meta in the `yoast_indexable` table and our update didn't fire the hooks Yoast's Indexable_Term_Watcher listens on. After updating the term option, the plugin now also fires `do_action('wpseo_save_taxonomy_meta', $term_id, $taxonomy)` and the standard `do_action('edited_term', ...)`, and as a final safety net deletes the term's row in the Yoast indexable repository so Yoast rebuilds it on the next request. No-op when Yoast isn't installed (Rank Math path was unaffected and unchanged).

= 1.4.0 =
* CPT archive page support: write SEO meta to custom-post-type archive pages (e.g. `/challenges/`, `/news/`) for any CPT registered with `has_archive=true`. Yoast: stored in `wpseo_titles` option (`title-ptarchive-{ptype}`, `metadesc-ptarchive-{ptype}`); Rank Math: stored in `rank-math-options-titles` (`pt_{ptype}_archive_title`, `pt_{ptype}_archive_description`).
* Global SEO scopes: write meta for `author_archive`, `date_archive`, `search`, `p404`, `home` (latest-posts mode) via a single registry. New scopes can be added in one place without touching dispatch code.
* `/status` reports `supports_archives`, `supports_globals`, `archive_fields`, and `global_scopes` (alias map per active scope).
* `/export?include_archives=1` appends one synthetic row per CPT-with-archive (id=0, kind=cpt_archive). Backwards compatible: pre-1.4 consumers ignore unknown kind values.
* `/bulk` accepts the new kinds:
  * `{ id: 0, kind: "cpt_archive", post_type: "challenges", meta: {...} }`
  * `{ kind: "author_archive" | "date_archive" | "search" | "p404" | "home", meta: {...} }`
* `/import` reads cpt_archive and global rows from CSV for round-trip edits.
* Permission: cpt_archive requires the post type's `edit_posts` cap; global scopes require `manage_options` (admin-only: they affect site-wide SEO settings).

= 1.3.0 =
* **Taxonomy term archives are now editable**: categories, tags, and any custom taxonomy archive (e.g. WooCommerce `product_cat`, `product_tag`, theme-registered taxonomies). Previously the plugin only handled posts, pages and CPTs; term archive SEO meta had to be edited in wp-admin one term at a time.
* `/status` now reports `supports_terms: true` and a `term_fields` alias map so clients can detect the capability.
* `/export?include_terms=1` appends term archive rows to the CSV. New trailing `kind` and `taxonomy` columns flag term rows; post rows have `kind=post` with an empty `taxonomy`. Filter to specific taxonomies with `?taxonomy=category,product_cat`. Backwards compatible: v1.2.x clients that ignore the trailing columns see the same column shape.
* `/export?post_type=any` now correctly returns all public post types (previously needed an explicit comma list of CPTs).
* `/bulk` accepts term updates via `{ id: <term_id>, kind: "term", taxonomy: "category", meta: {...} }`. Existing post-update payloads are unchanged.
* `/import` reads `kind` and `taxonomy` columns from CSV uploads so a mixed posts+terms export round-trips cleanly.
* Yoast term meta is stored in the `wpseo_taxonomy_meta` option (read-modify-write per call). Rank Math term meta uses `wp_termmeta`. Per-taxonomy `edit_terms` capability is enforced on every write.

= 1.2.0 to 1.2.6 =
* First releases: `/status`, `/bulk`, `/export` and `/import` for Yoast and Rank Math, with per-post permission checks and the bundled MCP server. Later 1.2.x builds added the length columns and UTF-8 BOM in `/export`, field aliases in `/bulk`, and empty CSV cells left alone by `/import`.

== Upgrade Notice ==

= 2.4.0 =
The key screen moved to Settings > AI CLI Connector, with a Settings link on the Plugins screen and a guided setup. Existing keys keep working.

= 2.3.2 =
The live SEO panel no longer asks for prices or product markup on pages that are not WooCommerce products, and shows fewer busywork warnings.

= 2.3.1 =
Fixes POST /update reporting "up to date" when the last update check was stored with a time in the future.

= 2.3.0 =
Adds a live SEO panel on the site and in the editors, and a history of SEO changes per post. Nothing changes in what your pages print.

= 2.2.0 =
Adds Elementor dropdown and switch settings and inserting an element by copy, both checked against Elementor's own permissions and capped for accounts without unfiltered_html.

= 2.1.0 =
Adds rows to ACF repeaters and flexible content, and ACF fields on terms. If you filter `bulkseme_user_can_edit_with_builder` to keep a role out of ACF, add the same rule to the new `bulkseme_user_can_edit_term_acf`.

= 2.0.2 =
Security release. Fixes a CSV import flaw that let an Author's post title change SEO fields when an administrator re-imported an export, and holds stored schema back on password-protected posts. Recommended for every site.

= 2.0.1 =
Security hardening for /clone. Recommended for every site on 2.0.0.

= 2.0.0 =
Adds page content editing (Elementor, Beaver Builder, SiteOrigin, ACF, block and classic pages) with undo, redirects, page copies and API keys. Security: records without an edit screen, such as privacy requests, can no longer be renamed through /bulk.

= 1.9.1 =
Security release. SEO fields can no longer be read through the core REST route without edit rights, the SEO plugin's own role rules apply to every write, and CSV exports are safe to open in a spreadsheet. Reading meta from wp/v2 now needs ?context=edit.

= 1.9.0 =
Adds schema on taxonomy terms, schema output without an SEO plugin graph, post_title via /bulk, POST /sitemap/flush, and removes Product schema nodes that have no offers, review or rating (filter bulkseme_drop_invalid_product_schema to keep them). Now requires WordPress 5.7.

= 1.8.2 =
Security fix. Closes a stored XSS in the JSON-LD schema field on Rank Math sites. Updating is recommended for anyone using the schema feature.

= 1.8.1 =
Permission fix: GET /export now filters its rows by capability, so terms need edit_term, CPT archive settings need manage_options, and posts are checked with edit_post per post. Automation that exported terms or archive settings on a low-privilege account will get fewer rows.

= 1.8.0 =
Permission hardening: per-object capability checks on every bulk write, and CPT archive updates now require an administrator (manage_options). Update automation credentials if they relied on editor-level access to archive settings.

= 1.7.0 =
GET /export now returns JSON with a csv field instead of raw CSV bytes. Update any script that saved the raw response body; the bundled MCP server 1.7.0 handles both shapes.

= 1.5.1 =
Internal cleanup per WordPress.org review feedback. No public API changes; safe to upgrade.

= 1.5.0 =
New endpoint to bulk-update image alt text on media-library attachments. No breaking changes.

= 1.2.0 =
Initial release.
