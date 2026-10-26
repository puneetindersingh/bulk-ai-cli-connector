/**
 * Live SEO panel (Bulk AI CLI Connector).
 *
 * mode "site":    an "SEO" item in the admin bar opens a panel over the page: what the page sends
 *                 to search engines, quick edits, the schema on the page and the change history.
 * mode "block":   a "Live on the site" panel in the block editor's document sidebar.
 * mode "classic": the same view in a side box of the classic editor.
 *
 * Everything shown is read from the rendered page (the editors fetch the published page), so it is
 * true whichever plugin printed it. Text from the page is always set with textContent, never as HTML.
 */
(function () {
	'use strict';

	var cfg = window.bulksemeLive;
	if (!cfg) { return; }

	/* ---------- DOM helper ---------- */
	function h(tag, attrs, kids) {
		var el = document.createElement(tag);
		Object.keys(attrs || {}).forEach(function (k) {
			var v = attrs[k];
			if (v === null || v === undefined || v === false) { return; }
			if (k === 'text') { el.textContent = v; } else if (k === 'on') {
				Object.keys(v).forEach(function (ev) { el.addEventListener(ev, v[ev]); });
			} else { el.setAttribute(k, v === true ? '' : v); }
		});
		(kids || []).forEach(function (c) {
			if (c === null || c === undefined || c === false) { return; }
			el.appendChild(typeof c === 'string' ? document.createTextNode(c) : c);
		});
		return el;
	}
	function mark(state) { return h('span', { 'class': 'mark ' + state, 'aria-hidden': 'true' }); }

	/* ---------- reading a page ---------- */
	var ctx2d = document.createElement('canvas').getContext('2d');
	function px(text, font) { ctx2d.font = font; return ctx2d.measureText(text || '').width; }

	function readPage(doc, pageUrl) {
		function meta(sel) { var el = doc.querySelector(sel); return el ? (el.getAttribute('content') || '').trim() : ''; }
		function outside(el) { return !el.closest('#wpadminbar') && !el.closest('#bulkseme-live-host'); }
		var canon = doc.querySelector('link[rel="canonical"]');
		var robots = Array.prototype.map.call(doc.querySelectorAll('meta[name="robots"]'), function (m) { return m.getAttribute('content') || ''; }).join(', ');
		var h1s = Array.prototype.filter.call(doc.querySelectorAll('h1'), outside).map(function (x) { return x.textContent.replace(/\s+/g, ' ').trim(); });
		var imgs = Array.prototype.filter.call(doc.querySelectorAll('img'), outside);
		var host = '', internal = 0, external = 0;
		try { host = new URL(pageUrl).host; } catch (e) {}
		Array.prototype.forEach.call(doc.querySelectorAll('a[href]'), function (a) {
			var href = a.getAttribute('href') || '';
			if (!outside(a) || /^(#|mailto:|tel:|javascript:)/i.test(href)) { return; }
			try { new URL(href, pageUrl).host === host ? internal++ : external++; } catch (e) {}
		});
		var titleEl = doc.querySelector('title');
		return {
			url: pageUrl,
			title: titleEl ? titleEl.textContent.trim() : '',
			desc: meta('meta[name="description"]'),
			canonical: canon ? (function () { try { return new URL(canon.getAttribute('href'), pageUrl).href; } catch (e) { return canon.getAttribute('href') || ''; } })() : '',
			robots: robots,
			headerRobots: '',
			ogTitle: meta('meta[property="og:title"]'),
			ogImage: meta('meta[property="og:image"]'),
			h1s: h1s,
			images: imgs.length,
			noAlt: imgs.filter(function (i) { return !i.hasAttribute('alt'); }).length,
			internal: internal,
			external: external,
			schema: readSchema(doc)
		};
	}

	function sameUrl(a, b) {
		try {
			var x = new URL(a), y = new URL(b);
			return x.host === y.host && x.pathname.replace(/\/$/, '') === y.pathname.replace(/\/$/, '') && x.search === y.search;
		} catch (e) { return false; }
	}

	function judge(p) {
		var rows = [];
		var tw = px(p.title, '20px Arial');
		// A short title is not a fault (a brand name on a home page), so it is a note, not a warning.
		rows.push(['Title', p.title || '(none)', !p.title ? 'fail' : (tw > 580 ? 'warn' : (p.title.length < 30 ? 'note' : 'pass')),
			!p.title ? 'Every page needs a title.' : p.title.length + ' characters, ' + Math.round(tw) + ' of 580 px' + (tw > 580 ? '. The end will be cut in results.' : (p.title.length < 30 ? '. Short; room for more of what the page offers.' : ''))]);
		var dw = px(p.desc, '14px Arial');
		rows.push(['Description', p.desc || '(none)', !p.desc || dw > 920 || p.desc.length < 70 ? 'warn' : 'pass',
			!p.desc ? 'Search engines will pick text from the page.' : p.desc.length + ' characters' + (dw > 920 ? '. The end will be cut.' : (p.desc.length < 70 ? '. Under 70 characters says little.' : ''))]);
		var noindex = /noindex/i.test(p.robots) || /noindex/i.test(p.headerRobots);
		rows.push(['Indexing', noindex ? 'noindex' : 'index', noindex ? 'fail' : 'pass',
			noindex ? 'This page asks to stay out of search results.' + (p.headerRobots ? ' (also sent as an X-Robots-Tag header)' : '') : (p.robots || 'No robots tag: indexable by default.')]);
		if (!noindex) {
			rows.push(['Canonical', p.canonical || '(none)', !p.canonical || !sameUrl(p.canonical, p.url) ? 'warn' : 'pass',
				!p.canonical ? 'No canonical tag.' : (sameUrl(p.canonical, p.url) ? 'Points to this page.' : 'Points to another address, so this one is unlikely to rank.')]);
		}
		rows.push(['H1', p.h1s.length ? p.h1s[0] : '(none)', p.h1s.length === 1 ? 'pass' : (p.h1s.length ? 'warn' : 'fail'),
			p.h1s.length === 1 ? 'One main heading.' : (p.h1s.length ? p.h1s.length + ' H1 headings; one is clearer.' : 'No H1 heading on the page.')]);
		// Without og:title, shared links use the page title, so only a missing image is worth a look.
		rows.push(['Social', p.ogTitle || '(no og:title)', p.ogImage ? 'pass' : 'warn',
			p.ogImage ? 'Share image set.' + (p.ogTitle ? '' : ' Shared links use the page title.') : 'No share image: shared links will show without a picture.']);
		rows.push(['Images', p.images + ' on the page', p.noAlt ? 'warn' : 'pass',
			p.noAlt ? p.noAlt + ' without an alt attribute.' : 'Every image has an alt attribute.']);
		rows.push(['Links', p.internal + ' internal, ' + p.external + ' outbound', p.internal ? 'pass' : 'warn',
			p.internal ? 'Links to other pages on the site.' : 'No links to other pages on this site.']);
		return rows;
	}

	/* ---------- schema ---------- */
	function lenient(t) {
		return t.replace(/^\s*<!\[CDATA\[|\]\]>\s*$/g, '').replace(/\/\*[\s\S]*?\*\//g, '').replace(/(^|[^:"'\\])\/\/[^\n]*/g, '$1');
	}
	var RULES = {
		Article: { rec: ['headline', 'image', 'datePublished', 'author'] },
		NewsArticle: { rec: ['headline', 'image', 'datePublished', 'author'] },
		BlogPosting: { rec: ['headline', 'image', 'datePublished', 'author'] },
		Product: { req: ['name'], oneOf: ['offers', 'review', 'aggregateRating'] },
		Offer: { rec: ['price', 'priceCurrency'] },
		FAQPage: { req: ['mainEntity'] },
		Question: { req: ['name', 'acceptedAnswer'] },
		Answer: { req: ['text'] },
		BreadcrumbList: { req: ['itemListElement'] },
		ListItem: { req: ['position'] },
		Event: { req: ['name', 'startDate', 'location'] },
		Recipe: { req: ['name', 'image'] },
		LocalBusiness: { req: ['name', 'address'] },
		Organization: { rec: ['name', 'logo'] },
		AggregateRating: { req: ['ratingValue'], oneOf: ['ratingCount', 'reviewCount'] },
		Review: { req: ['author', 'reviewRating'] },
		VideoObject: { req: ['name', 'thumbnailUrl', 'uploadDate'] },
		JobPosting: { req: ['title', 'description', 'datePosted', 'hiringOrganization'] }
	};
	// Types whose checks ask for offers or prices: applied on shop product pages only.
	var COMMERCE = { Product: true, Offer: true };
	var LOCAL = /^(LocalBusiness|Restaurant|Dentist|Store|AutoRepair|HomeAndConstructionBusiness|ProfessionalService|LegalService|MedicalBusiness|HealthAndBeautyBusiness|FoodEstablishment|LodgingBusiness|Plumber|Electrician|RoofingContractor|HVACBusiness|RealEstateAgent|AccountingService|FinancialService)$/;
	// JSON from a page can hold an object where text belongs ({"toString": 1} cannot even be
	// turned into a string), so only strings and numbers are ever read as text.
	function str(v) { return typeof v === 'string' || typeof v === 'number' ? String(v) : ''; }
	function safeUrl(u) { return typeof u === 'string' && /^https?:\/\//i.test(u) ? u : ''; }
	function types(n) { var t = n['@type']; return (Array.isArray(t) ? t : [t]).map(str).filter(Boolean); }
	function has(n, k) { var v = n[k]; return v !== undefined && v !== null && v !== '' && !(Array.isArray(v) && !v.length); }

	function readSchema(doc) {
		var blocks = [], nodes = [], ids = {}, refs = [];
		function walk(v, block, top) {
			if (Array.isArray(v)) { v.forEach(function (x) { walk(x, block, top); }); return; }
			if (!v || typeof v !== 'object') { return; }
			var keys = Object.keys(v);
			if (keys.length === 1 && keys[0] === '@id') { if (str(v['@id'])) { refs.push(str(v['@id'])); } return; }
			if (v['@type']) {
				nodes.push({ node: v, block: block, top: top });
				if (str(v['@id'])) { ids[str(v['@id'])] = true; }
			}
			keys.forEach(function (k) { if (k !== '@context') { walk(v[k], block, false); } });
		}
		Array.prototype.forEach.call(doc.querySelectorAll('script[type="application/ld+json"]'), function (s, i) {
			var text = s.textContent || '', data = null, strict = true;
			try { data = JSON.parse(text); } catch (e) {
				strict = false;
				try { data = JSON.parse(lenient(text)); } catch (e2) { data = null; }
			}
			var cls = (s.className || '') + ' ' + (s.id || '');
			var source = /yoast/i.test(cls) ? 'Yoast SEO' : (/rank-math/i.test(cls) ? 'Rank Math' : (/bulkseme/i.test(cls) ? 'Bulk AI CLI Connector' : 'Theme or another plugin'));
			var block = { index: i + 1, source: source, strict: strict, data: data, text: text };
			blocks.push(block);
			// One hostile block (too deep to walk, odd shapes) must not stop the panel.
			if (data) { try { walk(data['@graph'] || data, block, true); } catch (e) { block.data = null; } }
		});
		var issues = [];
		blocks.forEach(function (b) {
			if (!b.data) { issues.push(['fail', 'Block ' + b.index + ' does not parse', 'No search engine can read it. Source: ' + b.source + '.']); }
			else if (!b.strict) { issues.push(['warn', 'Block ' + b.index + ' is not strict JSON', 'Google forgives comments, other readers may not. Source: ' + b.source + '.']); }
		});
		nodes.forEach(function (o) {
			types(o.node).forEach(function (t) {
				var rule = RULES[t] || (LOCAL.test(t) ? RULES.LocalBusiness : null);
				if (!rule) { return; }
				var name = str(o.node.name) || str(o.node.headline);
				var label = t + (name ? ' "' + name.slice(0, 40) + '"' : '');
				// Offers, prices and product fields only matter where a shop product is sold. A service
				// page (an OfferCatalog of services, say) is never told to add a price nobody has set.
				if (COMMERCE[t] && !cfg.commerce) {
					if (t === 'Product') { issues.push(['note', label + ' on a page that is not a shop product', 'Product markup is for an item sold on this page. A service is better described as Service, with no price added.']); }
					return;
				}
				(rule.req || []).forEach(function (k) {
					if (!has(o.node, k)) { issues.push(['fail', label + ': ' + k + ' is missing', 'Required for this type to show as a rich result.']); }
				});
				if (rule.oneOf && !rule.oneOf.some(function (k) { return has(o.node, k); })) {
					issues.push(['fail', label + ': needs ' + rule.oneOf.join(' or '), 'At least one is required.']);
				}
				(rule.rec || []).forEach(function (k) {
					if (!has(o.node, k)) { issues.push(['warn', label + ': no ' + k, 'Recommended; it helps the result stand out.']); }
				});
			});
		});
		var orgs = {}, sites = {};
		nodes.forEach(function (o) {
			if (types(o.node).indexOf('Organization') !== -1) { orgs[str(o.node['@id']) || ('block' + o.block.index)] = true; }
			if (types(o.node).indexOf('WebSite') !== -1) { sites[o.block.index] = true; }
		});
		if (Object.keys(orgs).length > 1) { issues.push(['warn', Object.keys(orgs).length + ' different Organization nodes', 'Two sources describe the business; search engines may not know which to trust.']); }
		if (Object.keys(sites).length > 1) { issues.push(['warn', 'WebSite described in ' + Object.keys(sites).length + ' blocks', 'Usually two plugins printing schema side by side.']); }
		var dangling = refs.filter(function (r, i, a) { return r && !ids[r] && a.indexOf(r) === i; });
		if (dangling.length) { issues.push(['note', dangling.length + ' link' + (dangling.length === 1 ? '' : 's') + ' to an @id not on this page', dangling.slice(0, 3).join(', ')]); }
		if (!blocks.length) { issues.push(['note', 'No structured data', 'Search engines get no machine-readable description of this page.']); }
		return { blocks: blocks, nodes: nodes, issues: issues };
	}

	function tally(p) {
		var rows = judge(p);
		var all = rows.map(function (r) { return r[2]; }).concat(p.schema.issues.map(function (i) { return i[0]; }));
		return {
			rows: rows,
			fails: all.filter(function (s) { return s === 'fail'; }).length,
			warns: all.filter(function (s) { return s === 'warn'; }).length
		};
	}

	function ago(t) {
		var s = Math.max(1, Math.round(Date.now() / 1000 - t));
		if (s < 60) { return s + ' s ago'; }
		if (s < 3600) { return Math.round(s / 60) + ' min ago'; }
		if (s < 86400) { return Math.round(s / 3600) + ' h ago'; }
		return new Date(t * 1000).toLocaleDateString();
	}

	function historyList(list, limit) {
		return h('ul', { 'class': 'hist' }, list.slice(0, limit).map(function (e) {
			return h('li', {}, [
				h('div', { 'class': 'meta', text: ago(e.t) + ' \u00b7 ' + e.source + (e.who ? ' \u00b7 ' + e.who : '') }),
				h('div', { 'class': 'chg' }, [h('b', { text: e.field + ': ' }), e.old ? h('del', { text: e.old }) : null, e.old && e['new'] ? ' \u2192 ' : null,
					e['new'] ? h('ins', { text: e['new'] }) : h('span', { 'class': 'hint', text: '(cleared)' })])
			]);
		}));
	}

	var CSS = [
		'.lv{--ink:#1f2328;--ink-2:#3d444d;--muted:#5f6872;--line:#d6dbe0;--line-soft:#e8ebee;--bg:#fff;--app:#f3f4f6;--head:#f7f8fa;--link:#0a5bc4;--fail:#d92d20;--fail-text:#b42318;--fail-soft:#fdf0ee;--fail-line:#f1c4be;--warn:#8a5300;--warn-soft:#fdf6e3;--warn-bar:#e8a200;--warn-line:#ecd9a4;--pass:#1a7f37;--pass-soft:#e6f4ea;--pass-line:#b4dcc0;--note:#57606a;--note-soft:#eef0f3;--mono:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font:400 13px/1.5 system-ui,-apple-system,"Segoe UI",Roboto,"Noto Sans",Ubuntu,Arial,sans-serif;color:var(--ink);font-variant-numeric:tabular-nums;text-align:left}',
		'.lv *,.lv *::before,.lv *::after{box-sizing:border-box}',
		'.lv a{color:var(--link)}.lv button{font:inherit}.lv :focus-visible{outline:2px solid var(--link);outline-offset:1px}',
		'.lv .mark{display:inline-block;flex:none;width:10px;height:10px;margin-top:4px}.lv .mark.pass{border-radius:50%;background:var(--pass)}.lv .mark.warn{background:var(--warn-bar)}.lv .mark.fail{width:11px;background:var(--fail);clip-path:polygon(50% 0,100% 100%,0 100%)}.lv .mark.note{height:3px;margin-top:8px;background:var(--note);border-radius:2px}',
		'.lv .rows,.lv .issues,.lv .hist{list-style:none;margin:0;padding:0}',
		'.lv .rows li{display:grid;grid-template-columns:14px 82px minmax(0,1fr);gap:8px;padding:8px 12px;border-top:1px solid var(--line-soft);align-items:start}.lv .rows li:first-child{border-top:0}',
		'.lv .k{color:var(--muted)}.lv .v{word-break:break-word}.lv .why{display:block;color:var(--muted);font-size:12px;margin-top:2px}',
		'.lv .issues li{display:flex;gap:9px;padding:7px 12px;border-top:1px solid var(--line-soft)}.lv .issues li:first-child{border-top:0}.lv .issues b{font-weight:600}',
		'.lv .hist li{padding:8px 12px;border-top:1px solid var(--line-soft)}.lv .hist li:first-child{border-top:0}.lv .hist .meta{color:var(--muted);font-size:12px}.lv .hist .chg{margin-top:3px;word-break:break-word}.lv del{color:var(--fail-text)}.lv ins{color:var(--pass);text-decoration:none}',
		'.lv .card{background:var(--bg);border:1px solid var(--line);border-radius:6px;margin:0 0 12px;overflow:hidden}.lv .card>h3{margin:0;padding:9px 12px;font-size:13px;font-weight:600;background:var(--head);border-bottom:1px solid var(--line-soft);display:flex;justify-content:space-between;gap:8px;color:var(--ink)}.lv .card>h3 small{font-weight:400;color:var(--muted)}.lv .body{padding:10px 12px}',
		'.lv .serp{font-family:Arial,sans-serif;padding:12px}.lv .serp .site{font-size:12px;color:#4d5156;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.lv .serp .title{font-size:18px;line-height:24px;color:#1a0dab;margin:2px 0}.lv .serp .desc{font-size:13px;line-height:20px;color:#4d5156}.lv .serp .desc.empty{color:var(--muted);font-style:italic}',
		'.lv label.f{display:block;font-weight:600;margin:0 0 4px}.lv .field{margin:0 0 12px}.lv .lr{display:flex;justify-content:space-between;gap:8px;align-items:baseline}.lv .lr span{font-size:12px;color:var(--muted)}.lv .lr span.fail{color:var(--fail-text)}.lv .lr span.warn{color:var(--warn)}',
		'.lv input[type=text],.lv textarea,.lv select{width:100%;border:1px solid var(--line);border-radius:4px;padding:6px 9px;font:inherit;color:var(--ink);background:var(--bg);min-height:32px;margin:0;box-shadow:none}.lv textarea{resize:vertical;min-height:66px}.lv textarea.code{font:12px/1.5 var(--mono);min-height:200px;white-space:pre}.lv input:focus,.lv textarea:focus,.lv select:focus{outline:none;border-color:var(--link);box-shadow:0 0 0 1px var(--link)}',
		'.lv .meter{height:4px;border-radius:999px;background:var(--line-soft);margin-top:5px;overflow:hidden}.lv .meter i{display:block;height:100%;width:0;background:var(--note)}.lv .meter.pass i{background:var(--pass)}.lv .meter.warn i{background:var(--warn-bar)}.lv .meter.fail i{background:var(--fail)}',
		'.lv .hint{color:var(--muted);font-size:12px;margin:4px 0 0}.lv .btn{display:inline-flex;align-items:center;gap:6px;padding:6px 14px;min-height:32px;border-radius:4px;border:1px solid var(--line);background:var(--bg);color:var(--ink);font-weight:600;cursor:pointer;text-decoration:none}.lv .btn:hover{border-color:var(--ink-2)}.lv .btn.primary{background:var(--ink);border-color:var(--ink);color:#fff}.lv .btn[disabled]{opacity:.55;cursor:default}.lv .actions{display:flex;gap:8px;align-items:center;flex-wrap:wrap}',
		'.lv .msg{padding:8px 12px;border-radius:4px;margin:0 0 12px;border:1px solid var(--line);background:var(--head)}.lv .msg.pass{background:var(--pass-soft);border-color:var(--pass-line);color:var(--pass);font-weight:600}.lv .msg.fail{background:var(--fail-soft);border-color:var(--fail-line);color:var(--fail-text)}.lv .msg.warn{background:var(--warn-soft);border-color:var(--warn-line)}',
		'.lv .chips{display:flex;flex-wrap:wrap;gap:6px}.lv .chip{font:600 11.5px var(--mono);padding:2px 7px;border-radius:3px;background:#e8f0fb;color:var(--link)}.lv .node{padding:8px 12px;border-top:1px solid var(--line-soft)}.lv .node:first-child{border-top:0}.lv .node .ty{font-weight:600}.lv .node .props{color:var(--muted);font-size:12px;word-break:break-word}',
		'.lv details>summary{cursor:pointer;padding:8px 12px;color:var(--link);font-size:12.5px}.lv pre{margin:0;padding:10px 12px;max-height:320px;overflow:auto;font:11.5px/1.5 var(--mono);background:#fafbfc;border-top:1px solid var(--line-soft);white-space:pre}.lv .empty{color:var(--muted);padding:14px 12px;text-align:center}',
		'.drawer{position:fixed;top:var(--top,32px);right:0;bottom:0;z-index:99999;width:min(440px,100vw);display:flex;flex-direction:column;background:var(--app);border-left:1px solid var(--line);box-shadow:-8px 0 24px rgba(0,0,0,.12);transform:translateX(105%);transition:transform .18s ease}.drawer.open{transform:none}@media (prefers-reduced-motion:reduce){.drawer{transition:none}}',
		'.drawer header{flex:none;background:var(--bg);border-bottom:1px solid var(--line)}.drawer .top{display:flex;align-items:center;gap:10px;padding:10px 12px 8px}.drawer .top .t{flex:1;min-width:0}.drawer .name{font-weight:600;font-size:14px}.drawer .sub{color:var(--muted);font-size:12px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}',
		'.drawer .x{width:30px;height:30px;border:1px solid transparent;border-radius:4px;background:none;cursor:pointer;color:var(--muted);font-size:18px;line-height:1}.drawer .x:hover{background:var(--app);border-color:var(--line);color:var(--ink)}',
		'.drawer .tabs{display:flex;padding:0 6px}.drawer .tabs button{flex:1;border:0;background:none;cursor:pointer;padding:8px 4px 9px;color:var(--muted);font-weight:500;box-shadow:inset 0 -2px 0 transparent;display:inline-flex;gap:5px;justify-content:center;align-items:center}.drawer .tabs button:hover{color:var(--ink);box-shadow:inset 0 -2px 0 var(--line)}.drawer .tabs button[aria-selected=true]{color:var(--ink);font-weight:600;box-shadow:inset 0 -2px 0 var(--ink)}',
		'.drawer .badge{font-size:10.5px;font-weight:600;min-width:16px;line-height:16px;padding:0 4px;border-radius:3px;background:var(--fail);color:#fff}',
		'.drawer main{flex:1;overflow-y:auto;padding:12px;scrollbar-width:thin}.drawer footer{flex:none;padding:8px 12px;background:var(--bg);border-top:1px solid var(--line);font-size:12.5px;color:var(--muted)}',
		'.box .card{margin:0 0 10px}.box .rows li{grid-template-columns:14px 70px minmax(0,1fr);padding:6px 0}.box .issues li,.box .hist li{padding:6px 0}'
	].join('\n');

	function card(title, small, kids) {
		return h('section', { 'class': 'card' }, [h('h3', {}, [title, small ? h('small', { text: small }) : null])].concat(kids));
	}

	function rowsList(rows) {
		return h('ul', { 'class': 'rows' }, rows.map(function (r) {
			return h('li', {}, [mark(r[2]), h('span', { 'class': 'k', text: r[0] }), h('span', { 'class': 'v' }, [r[1], h('span', { 'class': 'why', text: r[3] })])]);
		}));
	}

	function issuesList(issues) {
		return h('ul', { 'class': 'issues' }, issues.map(function (i) {
			return h('li', {}, [mark(i[0]), h('div', {}, [h('b', { text: i[1] }), ' ', h('span', { text: i[2] })])]);
		}));
	}

	/* =====================================================================
	 * Editors: compact live view
	 * ===================================================================== */
	function editorView(container) {
		container.textContent = '';
		var wrap = h('div', { 'class': 'lv box' });
		container.appendChild(wrap);
		if (!cfg.published || !safeUrl(cfg.url)) {
			wrap.appendChild(h('p', { 'class': 'hint', text: 'Not published yet. Once it is, this shows what the live page sends to search engines.' }));
			return;
		}
		wrap.appendChild(h('p', { 'class': 'hint', text: 'Reading the live page...' }));
		fetch(cfg.url, { credentials: 'same-origin', cache: 'no-store' }).then(function (r) {
			var xr = r.headers.get('x-robots-tag') || '';
			return r.text().then(function (t) { return [t, xr]; });
		}).then(function (res) {
			var doc = new DOMParser().parseFromString(res[0], 'text/html');
			var p = readPage(doc, cfg.url);
			p.headerRobots = res[1];
			var c = tally(p);
			wrap.textContent = '';
			wrap.appendChild(h('p', { 'class': 'hint', text: c.fails + ' to fix, ' + c.warns + ' worth a look. Printed by ' + cfg.engine + '.' }));
			wrap.appendChild(rowsList(c.rows.slice(0, 4)));
			var typeSet = {};
			p.schema.nodes.forEach(function (o) { types(o.node).forEach(function (t) { typeSet[t] = true; }); });
			wrap.appendChild(h('p', { 'class': 'hint', text: 'Schema: ' + (Object.keys(typeSet).join(', ') || 'none') }));
			var sfails = p.schema.issues.filter(function (i) { return i[0] !== 'note'; });
			if (sfails.length) { wrap.appendChild(issuesList(sfails.slice(0, 4))); }
			if (cfg.history && cfg.history.length) {
				wrap.appendChild(h('p', { 'class': 'hint', text: 'Recent SEO changes:' }));
				wrap.appendChild(historyList(cfg.history, 3));
			}
			if (safeUrl(cfg.url)) {
				wrap.appendChild(h('p', {}, [h('a', { href: safeUrl(cfg.url) + '#bulkseme-live', target: '_blank', rel: 'noopener', text: 'Open the page with the full SEO panel' })]));
			}
		}).catch(function () {
			wrap.textContent = '';
			wrap.appendChild(h('p', { 'class': 'hint', text: 'The live page could not be read from here.' }));
		});
	}

	function addStyle(rootNode) {
		var s = document.createElement('style');
		s.textContent = CSS;
		(rootNode || document.head).appendChild(s);
	}

	if (cfg.mode === 'classic') {
		var startClassic = function () {
			var box = document.getElementById('bulkseme-live-box');
			if (box) { addStyle(); editorView(box); }
		};
		if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', startClassic); } else { startClassic(); }
		return;
	}

	if (cfg.mode === 'block') {
		var wp = window.wp;
		var Panel = wp && ((wp.editor && wp.editor.PluginDocumentSettingPanel) || (wp.editPost && wp.editPost.PluginDocumentSettingPanel));
		if (!Panel || !wp.plugins || !wp.element || !wp.data) { return; }
		addStyle();
		var el = wp.element.createElement, useRef = wp.element.useRef, useEffect = wp.element.useEffect;
		// The panel only renders its body while it is open, so the body reads the live page
		// itself each time it appears, and again after every save that finishes.
		var Body = function () {
			var ref = useRef(null);
			useEffect(function () {
				if (ref.current) { editorView(ref.current); }
				var saving = false;
				return wp.data.subscribe(function () {
					var ed = wp.data.select('core/editor');
					var now = ed.isSavingPost() && !ed.isAutosavingPost();
					if (saving && !now && ref.current) {
						cfg.published = ed.getEditedPostAttribute('status') === 'publish';
						cfg.url = ed.getPermalink() || cfg.url;
						setTimeout(function () { if (ref.current) { editorView(ref.current); } }, 800);
					}
					saving = now;
				});
			}, []);
			return el('div', { ref: ref });
		};
		var LivePanel = function () {
			return el(Panel, { name: 'bulkseme-live', title: 'Live on the site' }, el(Body));
		};
		wp.plugins.registerPlugin('bulkseme-live', { render: LivePanel, icon: null });
		return;
	}

	/* =====================================================================
	 * Site: the panel over the page
	 * ===================================================================== */
	var REOPEN = 'bulkseme-live-reopen';
	var host, root, drawer, lastFocus = null;
	var state = { tab: 'overview', page: null };

	function save(meta, btn, out) {
		btn.disabled = true;
		out.hidden = false; out.className = 'msg'; out.textContent = 'Saving...';
		fetch(cfg.rest, {
			method: 'POST', credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce },
			body: JSON.stringify({ kind: cfg.target.kind, id: cfg.target.id, meta: meta })
		}).then(function (r) { return r.json(); }).then(function (res) {
			if (res && res.ok) {
				try { sessionStorage.setItem(REOPEN, state.tab); } catch (e) {}
				out.className = 'msg pass'; out.textContent = 'Saved. Reloading to show the page as it now renders...';
				location.reload();
				return;
			}
			btn.disabled = false;
			var errs = res && res.errors ? res.errors.join(', ') : (res && res.message ? res.message : 'unknown error');
			out.className = 'msg fail';
			out.textContent = /invalid_json_schema|schema/.test(errs) && /invalid/.test(errs) ? 'The schema was refused: ' + errs + '. Nothing else changed.' : 'Not saved: ' + errs;
		}).catch(function () {
			btn.disabled = false; out.className = 'msg fail'; out.textContent = 'Not saved: the site did not answer.';
		});
	}

	function meterField(id, label, value, current, font, max, textarea) {
		var input = textarea ? h('textarea', { id: id, rows: '3', placeholder: current }) : h('input', { type: 'text', id: id, placeholder: current });
		input.value = value || '';
		var info = h('span'), bar = h('div', { 'class': 'meter' }, [h('i')]);
		function upd() {
			var v = input.value || current || '';
			var w = px(v, font), st = !v ? '' : (w > max ? 'fail' : (w < max * 0.45 ? 'warn' : 'pass'));
			bar.className = 'meter' + (st ? ' ' + st : '');
			bar.firstChild.style.width = Math.min(100, Math.round(w / max * 100)) + '%';
			info.className = st === 'pass' ? '' : st;
			info.textContent = v ? v.length + ' characters, ' + Math.round(w) + ' of ' + max + ' px' + (input.value ? '' : ' (now)') : '';
		}
		input.addEventListener('input', upd);
		upd();
		return { el: h('div', { 'class': 'field' }, [h('div', { 'class': 'lr' }, [h('label', { 'class': 'f', 'for': id, text: label }), info]), input, bar]), input: input };
	}

	var PANELS = {
		overview: ['Overview', function () {
			var p = state.page, c = tally(p);
			var crumbs = location.host + location.pathname.replace(/\/$/, '').split('/').filter(Boolean).map(function (s) {
				try { return ' \u203a ' + decodeURIComponent(s); } catch (e) { return ' \u203a ' + s; }
			}).join('');
			return [
				card('Search result', 'as the page renders now', [h('div', { 'class': 'serp' }, [
					h('div', { 'class': 'site', text: cfg.site + ' \u00b7 ' + crumbs }),
					h('div', { 'class': 'title', text: p.title || '(no title)' }),
					h('div', { 'class': 'desc' + (p.desc ? '' : ' empty'), text: p.desc || 'No description: Google will choose text from the page.' })
				])]),
				card('What this page sends', c.fails + ' to fix, ' + c.warns + ' worth a look', [rowsList(c.rows)]),
				h('p', { 'class': 'hint', text: 'Printed by ' + cfg.engine + '. Read from the live page, so it is what search engines receive.' })
			];
		}],
		edit: ['Edit', function () {
			var v = cfg.values;
			if (cfg.target.kind === 'view') {
				return [h('p', { 'class': 'empty', text: 'This kind of page has no SEO fields of its own; its title comes from the SEO plugin\u2019s templates.' })];
			}
			if (!v.can_fields) {
				return [h('p', { 'class': 'empty', text: cfg.engine === 'Yoast SEO' || cfg.engine === 'Rank Math' ? 'Your role cannot change these SEO fields in ' + cfg.engine + '.' : 'Titles and descriptions are edited here when Yoast SEO or Rank Math is active.' })];
			}
			var t = meterField('lv-title', 'SEO title', v.title, state.page.title, '20px Arial', 580, false);
			var d = meterField('lv-desc', 'Meta description', v.description, state.page.desc, '14px Arial', 920, true);
			var kw = h('input', { type: 'text', id: 'lv-kw', placeholder: 'The phrase this page should rank for' });
			kw.value = v.focus_kw || '';
			var out = h('div', { 'class': 'msg', hidden: true, role: 'status' });
			var btn = h('button', { type: 'button', 'class': 'btn primary', text: 'Save' });
			btn.addEventListener('click', function () {
				// Only fields that changed are sent, so a field the role may not touch is never part of a save.
				var meta = {};
				if (t.input.value !== (v.title || '')) { meta.title = t.input.value; }
				if (d.input.value !== (v.description || '')) { meta.description = d.input.value; }
				if (kw.value !== (v.focus_kw || '')) { meta.focus_kw = kw.value; }
				if (!Object.keys(meta).length) { out.hidden = false; out.className = 'msg'; out.textContent = 'Nothing changed.'; return; }
				save(meta, btn, out);
			});
			return [out, card(cfg.target.label, 'saved into ' + cfg.engine, [h('div', { 'class': 'body' }, [
				t.el, d.el,
				h('div', { 'class': 'field' }, [h('label', { 'class': 'f', 'for': 'lv-kw', text: 'Focus keyword' }), kw]),
				h('p', { 'class': 'hint', text: 'An empty field uses the SEO plugin\u2019s template. Grey text is what the page shows now.' }),
				h('div', { 'class': 'actions' }, [btn, safeUrl(cfg.target.edit) ? h('a', { href: safeUrl(cfg.target.edit), text: 'More in the editor' }) : null])
			])])];
		}],
		schema: ['Schema', function () {
			var s = state.page.schema, out = [], typeSet = {};
			s.nodes.forEach(function (o) { types(o.node).forEach(function (t) { typeSet[t] = (typeSet[t] || 0) + 1; }); });
			out.push(card('On this page', s.blocks.length + ' block' + (s.blocks.length === 1 ? '' : 's') + ', ' + s.nodes.length + ' nodes', [
				h('div', { 'class': 'body' }, [h('div', { 'class': 'chips' }, Object.keys(typeSet).map(function (t) {
					return h('span', { 'class': 'chip', text: t + (typeSet[t] > 1 ? ' \u00d7' + typeSet[t] : '') });
				}).concat(s.nodes.length ? [] : [h('span', { 'class': 'hint', text: 'None.' })]))])
			]));
			if (s.issues.length) { out.push(card('Problems', '', [issuesList(s.issues)])); }
			s.blocks.forEach(function (b) {
				out.push(card('Block ' + b.index, b.source, [
					h('div', {}, s.nodes.filter(function (o) { return o.block === b && o.top; }).map(function (o) {
						var n = o.node, name = str(n.name) || str(n.headline);
						return h('div', { 'class': 'node' }, [
							h('div', { 'class': 'ty', text: types(n).join(', ') + (name ? ': ' + name.slice(0, 60) : '') }),
							h('div', { 'class': 'props', text: Object.keys(n).filter(function (k) { return k.charAt(0) !== '@'; }).slice(0, 8).join(', ') })
						]);
					})),
					h('details', {}, [h('summary', { text: 'Show the JSON' }), h('pre', { text: b.data ? JSON.stringify(b.data, null, 2) : b.text })])
				]));
			});
			var url = encodeURIComponent(location.href.split('#')[0]);
			out.push(h('p', { 'class': 'hint' }, ['Test it: ',
				h('a', { href: 'https://search.google.com/test/rich-results?url=' + url, target: '_blank', rel: 'noopener', text: 'Rich Results Test' }), ' \u00b7 ',
				h('a', { href: 'https://validator.schema.org/#url=' + url, target: '_blank', rel: 'noopener', text: 'Schema validator' })]));
			var v = cfg.values;
			if (cfg.target.kind !== 'view' && v.can_schema) {
				var ta = h('textarea', { 'class': 'code', id: 'lv-schema', spellcheck: 'false', placeholder: '{\n  "@type": "Service",\n  "name": "...",\n  "provider": { "@id": "..." }\n}' });
				ta.value = v.schema || '';
				var mode = h('select', { id: 'lv-mode' }, [h('option', { value: 'add', text: 'Add to the page\u2019s schema' }), h('option', { value: 'replace', text: 'Replace the page\u2019s schema' })]);
				mode.value = v.schema_mode || 'add';
				var check = h('p', { 'class': 'hint', role: 'status' });
				var validate = function () {
					if (!ta.value.trim()) { check.textContent = 'Empty: no custom schema.'; return true; }
					try { JSON.parse(ta.value); check.textContent = 'Valid JSON.'; return true; } catch (e) { check.textContent = 'Not valid JSON: ' + e.message; return false; }
				};
				ta.addEventListener('input', validate);
				validate();
				var msg = h('div', { 'class': 'msg', hidden: true, role: 'status' });
				var btn = h('button', { type: 'button', 'class': 'btn primary', text: 'Save schema' });
				btn.addEventListener('click', function () {
					if (!validate()) { msg.hidden = false; msg.className = 'msg fail'; msg.textContent = 'Fix the JSON first. Nothing was saved.'; return; }
					save({ schema: ta.value.trim(), schema_mode: mode.value }, btn, msg);
				});
				out.push(msg);
				out.push(card('Custom schema', 'merged into ' + cfg.engine + '\u2019s output', [h('div', { 'class': 'body' }, [
					h('div', { 'class': 'field' }, [h('label', { 'class': 'f', 'for': 'lv-schema', text: 'JSON-LD' }), ta, check]),
					h('div', { 'class': 'field' }, [h('label', { 'class': 'f', 'for': 'lv-mode', text: 'How it is used' }), mode]),
					h('div', { 'class': 'actions' }, [btn])
				])]));
			}
			return out;
		}],
		history: ['History', function () {
			var list = cfg.history || [];
			if (!list.length) {
				return [h('p', { 'class': 'empty', text: cfg.target.kind === 'post' ? 'No SEO changes recorded on this page yet.' : 'History is kept for posts and pages.' })];
			}
			return [card('Recent changes', 'newest first', [historyList(list, 15)])];
		}]
	};

	function render() {
		var main = root.querySelector('main');
		main.textContent = '';
		main.appendChild(h('div', { role: 'tabpanel', id: 'p-' + state.tab, 'aria-labelledby': 't-' + state.tab }, PANELS[state.tab][1]()));
		root.querySelectorAll('.tabs button').forEach(function (b) {
			var on = b.getAttribute('data-tab') === state.tab;
			b.setAttribute('aria-selected', on ? 'true' : 'false');
			b.tabIndex = on ? 0 : -1;
		});
		var c = tally(state.page);
		var pf = c.rows.filter(function (r) { return r[2] === 'fail'; }).length;
		var sf = state.page.schema.issues.filter(function (i) { return i[0] === 'fail'; }).length;
		var ob = root.querySelector('[data-badge=overview]'), sb = root.querySelector('[data-badge=schema]');
		ob.hidden = !pf; ob.textContent = String(pf);
		sb.hidden = !sf; sb.textContent = String(sf);
	}

	function build() {
		host = h('div', { id: 'bulkseme-live-host' });
		document.body.appendChild(host);
		root = host.attachShadow({ mode: 'open' });
		var style = document.createElement('style');
		style.textContent = ':host{all:initial}' + CSS;
		root.appendChild(style);
		var bar = document.getElementById('wpadminbar');
		var tabs = Object.keys(PANELS).map(function (k) {
			return h('button', { type: 'button', role: 'tab', id: 't-' + k, 'data-tab': k, 'aria-controls': 'p-' + k, on: { click: function () { state.tab = k; render(); } } },
				[PANELS[k][0], k === 'overview' || k === 'schema' ? h('span', { 'class': 'badge', 'data-badge': k, hidden: true }) : null]);
		});
		drawer = h('div', { 'class': 'lv drawer', role: 'dialog', 'aria-label': 'SEO for this page', style: '--top:' + (bar ? bar.offsetHeight : 0) + 'px' }, [
			h('header', {}, [
				h('div', { 'class': 'top' }, [
					h('div', { 'class': 't' }, [h('div', { 'class': 'name', text: 'SEO for this ' + String(cfg.target.label).toLowerCase() }), h('div', { 'class': 'sub', text: location.pathname })]),
					h('button', { type: 'button', 'class': 'x', 'aria-label': 'Close', text: '\u00d7', on: { click: close } })
				]),
				h('div', { 'class': 'tabs', role: 'tablist' }, tabs)
			]),
			h('main'),
			h('footer', { text: 'Bulk AI CLI Connector' })
		]);
		root.appendChild(drawer);
		root.querySelector('.tabs').addEventListener('keydown', function (e) {
			var keys = Object.keys(PANELS), i = keys.indexOf(state.tab);
			if (e.key === 'ArrowRight' || e.key === 'ArrowLeft') {
				e.preventDefault();
				state.tab = keys[(i + (e.key === 'ArrowRight' ? 1 : keys.length - 1)) % keys.length];
				render();
				root.getElementById('t-' + state.tab).focus();
			}
		});
		drawer.addEventListener('keydown', function (e) { if (e.key === 'Escape') { close(); } });
	}

	function open(tab) {
		if (!host) { build(); }
		if (tab && PANELS[tab]) { state.tab = tab; }
		render();
		lastFocus = document.activeElement;
		requestAnimationFrame(function () { drawer.classList.add('open'); root.getElementById('t-' + state.tab).focus(); });
	}
	function close() {
		if (!drawer) { return; }
		drawer.classList.remove('open');
		if (lastFocus && lastFocus.focus) { lastFocus.focus(); }
	}

	function paintBar() {
		var c = tally(state.page);
		var badge = document.querySelector('#wp-admin-bar-bulkseme-live .bulkseme-live-count');
		if (!badge) { return; }
		badge.hidden = !c.fails && !c.warns;
		badge.className = 'bulkseme-live-count' + (c.fails ? '' : ' warn');
		badge.textContent = String(c.fails || c.warns);
		badge.setAttribute('title', c.fails + ' to fix, ' + c.warns + ' worth a look');
	}

	function init() {
		state.page = readPage(document, location.href.split('#')[0]);
		paintBar();
		// A noindex can also come as a header, which the page itself cannot show.
		fetch(location.href.split('#')[0], { method: 'HEAD', credentials: 'same-origin' }).then(function (r) {
			var x = r.headers.get('x-robots-tag');
			if (x) { state.page.headerRobots = x; paintBar(); if (drawer && drawer.classList.contains('open')) { render(); } }
		}).catch(function () {});
		var node = document.querySelector('#wp-admin-bar-bulkseme-live > .ab-item');
		if (node) {
			node.setAttribute('role', 'button');
			node.setAttribute('aria-haspopup', 'dialog');
			node.addEventListener('click', function (e) {
				e.preventDefault();
				if (drawer && drawer.classList.contains('open')) { close(); } else { open(); }
			});
		}
		var again = null;
		try { again = sessionStorage.getItem(REOPEN); sessionStorage.removeItem(REOPEN); } catch (e) {}
		if (again || location.hash === '#bulkseme-live') {
			open(again || 'overview');
			if (again) {
				var main = root.querySelector('main');
				main.insertBefore(h('div', { 'class': 'msg pass', role: 'status', text: 'Saved. This is the page as it renders now.' }), main.firstChild);
			}
		}
	}

	if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', init); } else { init(); }
})();
