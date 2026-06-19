# Test plan: custom JSON-LD schema injection (v1.6.0)

Goal: prove the schema feature (a) injects our nodes into the active SEO plugin's
graph, (b) does NOT break pages built by the block editor, Elementor, or Beaver
Builder, and (c) behaves correctly for add vs replace, invalid input, and
non-singular views. Run the whole matrix once with Yoast active and once with
Rank Math active.

Env: ~/wp-test, WP 6.9.4, wp server on 127.0.0.1:8881. Builders installed:
elementor, beaver-builder-lite-version. SEO: wordpress-seo (Yoast), rank-math.

## Fixtures (one page per builder, each with a unique body marker)
* P-GUT  block-editor page, body marker "GUTMARK"
* P-ELE  Elementor page (_elementor_edit_mode=builder + heading widget "ELEMARK")
* P-BB   Beaver Builder page (_fl_builder_enabled=1 + module text "BBMARK")

## Per-render assertions
* A1 HTTP 200 on the page URL
* A2 builder actually rendered: builder marker class present in body
     (GUT: marker text; ELE: "elementor-" / "ELEMARK"; BB: "fl-builder-content" / "BBMARK")
* A3 document not truncated / no white screen: closing </html> present
* A4 the page's JSON-LD block parses as valid JSON
* A5 our node present: schema graph contains "@type":"FAQPage"
* A6 no debug.log line mentioning bulkseme / Fatal / Uncaught from our code

## Test cases (run for SEO = Yoast, then SEO = Rank Math)

| ID | Page | Mode | Expect |
|----|------|------|--------|
| TC1 | P-GUT | add | A1-A6 pass; SEO plugin's own WebPage/Article node ALSO present |
| TC2 | P-ELE | add | A1-A6 pass; Elementor heading rendered; SEO node still present |
| TC3 | P-BB  | add | A1-A6 pass; BB content rendered; SEO node still present |
| TC4 | P-GUT | replace | our FAQPage present; SEO plugin's WebPage/Article NOT in graph |
| TC5 | P-ELE | replace | same as TC4, page still renders |
| TC6 | P-BB  | replace | same as TC4, page still renders |

## Edge cases (SEO = Yoast)
| ID | What | Expect |
|----|------|--------|
| E1 | store invalid JSON "{bad" | rejected (invalid_json_schema), page unaffected, default graph intact |
| E2 | store "" (empty) to clear | no FAQPage node, page renders normal SEO graph |
| E3 | render a non-singular view (home/archive) | no FAQPage node injected |
| E4 | page with NO schema set | SEO plugin's default graph unchanged (regression guard) |
| E5 | multi-node graph doc ({"@graph":[FAQPage, HowTo]}) | both nodes injected |

## Builders x my code: why this should be safe
Our code only adds a filter to the SEO plugin's schema graph, which the SEO
plugin prints in wp_head. Page builders render the BODY (the_content / their own
frontend), not the head. So builder choice is orthogonal to schema injection.
These tests confirm that empirically rather than by assertion.

## Result log (run 2026-06-19, WP 6.9.4)

Fixtures: P-GUT=page 10 (block editor), P-ELE=page 11 (Elementor heading widget),
P-BB=page 12 (Beaver Builder html module). Baseline confirmed each builder renders
its own marker (Elementor "elementor-element", BB "fl-builder-content").

SEO = YOAST (active):
* TC1 P-GUT add      PASS  graph WebPage,BreadcrumbList,WebSite,FAQPage ; marker ok ; 200
* TC2 P-ELE add      PASS  same + Elementor heading rendered ; 200
* TC3 P-BB  add      PASS  same + BB content rendered ; 200
* TC4 P-GUT replace  PASS  graph = FAQPage only ; page intact
* TC5 P-ELE replace  PASS  graph = FAQPage only ; page intact
* TC6 P-BB  replace  PASS  graph = FAQPage only ; page intact
* E1 invalid JSON    PASS  rejected (invalid_json_schema), existing schema untouched
* E2 clear (empty)   PASS  graph back to WebPage,BreadcrumbList,WebSite
* E3 home/non-singular PASS  CollectionPage,BreadcrumbList,WebSite (no FAQPage)
* E4 no-schema page  PASS  Sample Page graph unchanged (no FAQPage)
* E5 multi-node doc  PASS  FAQPage + HowTo both injected

SEO = RANK MATH (active):
Note: a fresh RM emits NO frontend JSON-LD until its setup wizard is marked
complete (rank_math_wizard_completed). After setting that flag (test-env only;
real RM sites have it done), RM emits its graph and our filter injects into it.
* TC1-3 add      PASS  FAQPage injected alongside RM Person,Organization,WebSite,WebPage,Article ; all 3 builders ; 200
* TC4-6 replace  PASS  graph = FAQPage only ; all 3 builders ; 200
* regression     PASS  no-schema page keeps RM default graph (no FAQPage)

Cross-cutting:
* All 12 builder x SEO x mode combinations: HTTP 200, </html> present, builder
  content rendered, exactly one valid JSON-LD block, expected nodes.
* debug.log: 0 lines after the full run (no fatals/warnings/notices from our code).
* Plugin Check (default + experimental): No errors found.

Conclusion: schema injection is builder-independent (it rides the SEO plugin's
wp_head graph, not the_content), works identically under Yoast and Rank Math, and
does not break block-editor, Elementor, or Beaver Builder pages.
