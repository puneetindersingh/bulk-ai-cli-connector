#!/usr/bin/env node
// MCP server for Bulk SEO Meta Editor for AI Agents.
// Exposes the WP plugin's REST endpoints as MCP tools so Claude Code /
// Claude Desktop can read and bulk-update SEO meta with a single tool call.
//
// Configure via env vars:
//   WP_BASE_URL   e.g. https://example.com
//   WP_USER       WordPress username (Editor or Admin recommended)
//   WP_APP_PASS   Application Password (Users → Your Profile → Application Passwords)

import { Server } from '@modelcontextprotocol/sdk/server/index.js';
import { StdioServerTransport } from '@modelcontextprotocol/sdk/server/stdio.js';
import {
  CallToolRequestSchema,
  ListToolsRequestSchema,
} from '@modelcontextprotocol/sdk/types.js';

const BASE = (process.env.WP_BASE_URL || '').replace(/\/$/, '');
const USER = process.env.WP_USER || '';
const PASS = process.env.WP_APP_PASS || '';

if (!BASE || !USER || !PASS) {
  console.error('Missing env: WP_BASE_URL, WP_USER, WP_APP_PASS');
  process.exit(1);
}

const auth = 'Basic ' + Buffer.from(`${USER}:${PASS}`).toString('base64');

// Build a REST URL using ?rest_route=/X form. This is the universal URL
// shape — it works on every WP install (pretty permalinks or plain), so
// we don't need to detect permalink structure or worry about htaccess /
// nginx rewrites being missing on the target site.
function restUrl(path, params) {
  const u = new URL(BASE + '/');
  // path may already contain a ?query → split it back into the URL params.
  let pathPart = path;
  let queryPart = '';
  const qi = path.indexOf('?');
  if (qi >= 0) {
    pathPart = path.slice(0, qi);
    queryPart = path.slice(qi + 1);
  }
  u.searchParams.set('rest_route', pathPart);
  if (queryPart) {
    for (const [k, v] of new URLSearchParams(queryPart)) {
      u.searchParams.set(k, v);
    }
  }
  if (params) {
    for (const [k, v] of Object.entries(params)) {
      u.searchParams.set(k, String(v));
    }
  }
  return u.toString();
}

async function wp(path, init = {}) {
  const url = restUrl(path);
  const headers = { 'Authorization': auth, ...(init.headers || {}) };
  if (init.body && typeof init.body === 'object' && !(init.body instanceof FormData)) {
    headers['Content-Type'] = 'application/json';
    init.body = JSON.stringify(init.body);
  }
  const res = await fetch(url, { ...init, headers });
  const text = await res.text();
  let data;
  try { data = JSON.parse(text); } catch { data = text; }
  if (!res.ok) {
    const msg = typeof data === 'object' ? (data.message || JSON.stringify(data)) : data;
    throw new Error(`WP ${res.status}: ${msg}`);
  }
  return data;
}

const tools = [
  {
    name: 'status',
    description: 'Check which SEO plugin is active on the WordPress site (Yoast or Rank Math) and return the available field aliases. Always call this first to learn the field names.',
    inputSchema: { type: 'object', properties: {}, additionalProperties: false },
  },
  {
    name: 'get_post_meta',
    description: 'Read SEO meta (title, description, focus keyword, canonical, OG/Twitter fields) for a single post or page by ID.',
    inputSchema: {
      type: 'object',
      properties: { id: { type: 'integer', description: 'WordPress post ID' } },
      required: ['id'],
      additionalProperties: false,
    },
  },
  {
    name: 'set_post_meta',
    description: 'Update SEO meta on a single post. Pass either field aliases (title, description, focus_kw, canonical, og_title, og_desc, og_image, tw_title, tw_desc, tw_image) or raw meta keys (_yoast_wpseo_title, rank_math_title, etc.). Call status first to know which plugin is active.',
    inputSchema: {
      type: 'object',
      properties: {
        id:   { type: 'integer' },
        meta: { type: 'object', description: 'Map of field alias or raw meta key -> string value' },
      },
      required: ['id', 'meta'],
      additionalProperties: false,
    },
  },
  {
    name: 'bulk_update',
    description: 'Update SEO meta on up to 100 posts or taxonomy terms in a single call. Each item is { id, meta } for a post, or { id, kind: "term", taxonomy: "<slug>", meta } for a taxonomy term archive (category, tag, product_cat, etc.). Returns per-item status. Requires plugin v1.3.0+ for term updates.',
    inputSchema: {
      type: 'object',
      properties: {
        items: {
          type: 'array',
          maxItems: 100,
          items: {
            type: 'object',
            properties: {
              id:       { type: 'integer' },
              kind:     { type: 'string', enum: ['post', 'term'], default: 'post', description: 'Defaults to post. Set to "term" to update a taxonomy term archive.' },
              taxonomy: { type: 'string', description: 'Required when kind=term. Taxonomy slug (category, post_tag, product_cat, etc.).' },
              meta:     { type: 'object' },
            },
            required: ['id', 'meta'],
          },
        },
      },
      required: ['items'],
      additionalProperties: false,
    },
  },
  {
    name: 'list_terms',
    description: 'List taxonomy terms (categories, tags, or any custom taxonomy terms) with their core fields (id, slug, name, count, link). Use this to find term IDs and the taxonomy slug before calling bulk_update with kind=term.',
    inputSchema: {
      type: 'object',
      properties: {
        taxonomy:   { type: 'string', default: 'category', description: 'Taxonomy slug — category, post_tag, product_cat, product_tag, or any custom taxonomy.' },
        per_page:   { type: 'integer', default: 50, maximum: 100 },
        page:       { type: 'integer', default: 1 },
        search:     { type: 'string', description: 'Optional name/slug search.' },
        hide_empty: { type: 'boolean', default: false, description: 'Skip terms with zero posts.' },
      },
      additionalProperties: false,
    },
  },
  {
    name: 'list_posts',
    description: 'List posts/pages with their core fields (id, slug, title, status, link). Use this to find IDs before calling set_post_meta.',
    inputSchema: {
      type: 'object',
      properties: {
        post_type: { type: 'string', default: 'post', description: 'post, page, product, or any registered post type' },
        per_page:  { type: 'integer', default: 20, maximum: 100 },
        page:      { type: 'integer', default: 1 },
        search:    { type: 'string', description: 'Optional search term for title/content' },
        status:    { type: 'string', default: 'publish', description: 'publish, draft, any' },
      },
      additionalProperties: false,
    },
  },
  {
    name: 'export_csv',
    description: 'Export all posts (and optionally taxonomy term archives) and their SEO meta as CSV. Returns the CSV content as a string. Useful for bulk-editing in a spreadsheet. Includes title_chars and desc_chars helper columns by default; pass include_lengths=false to omit them. Set include_terms=true to append term archive rows (categories, tags, custom taxonomies) — requires plugin v1.3.0+.',
    inputSchema: {
      type: 'object',
      properties: {
        post_type:       { type: 'string',  default: 'post,page', description: 'Comma-separated post types, or "any" for all public types.' },
        status:          { type: 'string',  default: 'publish,draft' },
        limit:           { type: 'integer', default: 500, maximum: 2000 },
        offset:          { type: 'integer', default: 0 },
        include_lengths: { type: 'boolean', default: true, description: 'Include title_chars / desc_chars helper columns next to title/description.' },
        include_terms:   { type: 'boolean', default: false, description: 'Append taxonomy term archive rows (categories, tags, custom taxonomies). Requires plugin v1.3.0+.' },
        taxonomy:        { type: 'string', description: 'Optional comma-separated taxonomy slugs to include when include_terms=true (default: all public taxonomies).' },
      },
      additionalProperties: false,
    },
  },
  {
    name: 'import_csv',
    description: 'Import SEO meta updates from CSV rows. Each row must include id; other columns can be field aliases (title, description, focus_kw, canonical, og_title, ...) or raw meta keys. Round-trips with export_csv output.',
    inputSchema: {
      type: 'object',
      properties: {
        rows: { type: 'array', items: { type: 'object' }, maxItems: 2000 },
      },
      required: ['rows'],
      additionalProperties: false,
    },
  },
];

const server = new Server(
  { name: 'bulk-seo-meta-editor-for-ai-agents', version: '1.3.0' },
  { capabilities: { tools: {} } }
);

server.setRequestHandler(ListToolsRequestSchema, async () => ({ tools }));

server.setRequestHandler(CallToolRequestSchema, async (req) => {
  const { name, arguments: args = {} } = req.params;
  try {
    let result;
    switch (name) {
      case 'status':
        result = await wp('/seo-meta-bridge/v1/status');
        break;
      case 'get_post_meta': {
        // Use the standard /wp/v2/posts route — registered meta surfaces under .meta
        const post = await wp(`/wp/v2/posts/${args.id}?context=edit`);
        result = { id: post.id, link: post.link, title: post.title?.rendered, meta: post.meta };
        break;
      }
      case 'set_post_meta':
        result = await wp(`/wp/v2/posts/${args.id}`, { method: 'POST', body: { meta: args.meta } });
        break;
      case 'bulk_update':
        result = await wp('/seo-meta-bridge/v1/bulk', { method: 'POST', body: { items: args.items } });
        break;
      case 'list_posts': {
        const qs = new URLSearchParams();
        qs.set('per_page', String(args.per_page ?? 20));
        qs.set('page',     String(args.page ?? 1));
        qs.set('status',   args.status ?? 'publish');
        if (args.search) qs.set('search', args.search);
        const route = (args.post_type === 'page') ? '/wp/v2/pages' : `/wp/v2/${args.post_type || 'posts'}`;
        const posts = await wp(`${route}?${qs.toString()}`);
        result = posts.map(p => ({ id: p.id, slug: p.slug, title: p.title?.rendered, status: p.status, link: p.link }));
        break;
      }
      case 'export_csv': {
        const qs = new URLSearchParams();
        if (args.post_type) qs.set('post_type', args.post_type);
        if (args.status)    qs.set('status', args.status);
        if (args.limit)     qs.set('limit', String(args.limit));
        if (args.offset)    qs.set('offset', String(args.offset));
        if (args.include_lengths === false) qs.set('lengths', '0');
        if (args.include_terms === true)    qs.set('include_terms', '1');
        if (args.taxonomy)  qs.set('taxonomy', args.taxonomy);
        const csv = await wp(`/seo-meta-bridge/v1/export?${qs.toString()}`);
        result = { csv };
        break;
      }
      case 'list_terms': {
        // Standard WP REST exposes built-in taxonomies at /wp/v2/categories,
        // /wp/v2/tags, and custom taxonomies at /wp/v2/<rest_base>. Resolve
        // the rest_base via /wp/v2/taxonomies/{slug} so any registered
        // taxonomy works (product_cat, portfolio_category, etc.).
        const taxSlug = args.taxonomy || 'category';
        let restBase;
        if (taxSlug === 'category')      restBase = 'categories';
        else if (taxSlug === 'post_tag') restBase = 'tags';
        else {
          const taxInfo = await wp(`/wp/v2/taxonomies/${encodeURIComponent(taxSlug)}`);
          restBase = taxInfo?.rest_base || taxSlug;
        }
        const qs = new URLSearchParams();
        qs.set('per_page',   String(args.per_page ?? 50));
        qs.set('page',       String(args.page ?? 1));
        qs.set('hide_empty', String(args.hide_empty ?? false));
        if (args.search) qs.set('search', args.search);
        const terms = await wp(`/wp/v2/${restBase}?${qs.toString()}`);
        result = (Array.isArray(terms) ? terms : []).map(t => ({
          id: t.id, slug: t.slug, name: t.name, count: t.count, link: t.link,
          taxonomy: t.taxonomy || taxSlug,
        }));
        break;
      }
      case 'import_csv':
        result = await wp('/seo-meta-bridge/v1/import', { method: 'POST', body: { rows: args.rows } });
        break;
      default:
        throw new Error(`Unknown tool: ${name}`);
    }
    return { content: [{ type: 'text', text: typeof result === 'string' ? result : JSON.stringify(result, null, 2) }] };
  } catch (err) {
    return { content: [{ type: 'text', text: `Error: ${err.message}` }], isError: true };
  }
});

const transport = new StdioServerTransport();
await server.connect(transport);
console.error('bulk-seo-meta-editor-for-ai-agents MCP server ready');
