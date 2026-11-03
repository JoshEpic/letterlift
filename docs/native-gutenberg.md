# Native Gutenberg serialization: implementation and acceptance

Date: November 3, 2026. Status: implementation and automated verification delivered; real browser/staging acceptance is blocked and not complete. Letterlift 1.0 is **not declared release-ready**.

## Architecture

The processing path is:

`Source HTML → Mailchimp parser → immutable normalized blocks → selected/ordered plan → versioned Gutenberg serializer → WordPress draft`

Normalized blocks remain in `analysis.blocks`, with HTML, source positions, section relationships, image identifiers, warnings, and editorial types. The Mailchimp adapter does not produce Gutenberg delimiters.

`Letterlift_Serializer` is the dedicated conversion layer. `Letterlift_Plan::content()` delegates to it. It uses WordPress `serialize_blocks()` and the parsed-block `innerBlocks`/`innerContent` representation, including proper nested list items, quote children, and button containers. Native markup matches the supported core save functions. PHP `parse_blocks()` round-trip validation checks structure; separate tests execute the official WordPress JavaScript parser and save functions.

`Letterlift_Executor` retains its existing bounded image/post operations, leases, immutable snapshot, plan hash, stable GUID recovery, draft-only insertion, content-hash verification, attachment reuse, source metadata, and featured image selection. It now supplies both local image URLs and attachment IDs to the serializer.

A focused parser correction prevents nested data tables from turning an enclosing email presentation table into one large data-table block. Safe normalized attributes now retain ordered-list starts, reversed/type semantics, explicit item values, and table captions. No new provider or extraction engine was introduced.

## Modified and added files

| File | Change |
| --- | --- |
| `letterlift.php` | Loads the serializer before the plan/executor. |
| `includes/class-serializer.php` (new) | Native conversion, fallback, fidelity signatures, legacy serializer, version selection, shared server preview. |
| `includes/class-parser.php` | Correct layout/data table classification; preserve necessary list/table attributes. |
| `includes/class-plan.php` | Delegates content serialization to the dedicated versioned layer. |
| `includes/class-executor.php` | Pins new approvals to native serialization; passes attachment IDs; honors legacy recovery; records content format metadata. |
| `includes/class-plugin.php` | Authorized, nonce-protected read-only preview endpoint. |
| `assets/admin.js` | Uses server-serialized preview content; preserves sandbox/image protections; shows fallback warnings; invalidates stale previews without remounting text controls. |
| `tests/fixtures/native.html` (new) | Realistic nested Mailchimp layout, Unicode, links/emphasis, images/captions, nested lists, quotes, data tables, CTAs, and complex fallback regions. |
| `tests/serializer.php` (new) | Native types, nesting, fidelity, ordering, fallback, preview, and PHP structural checks. |
| `tests/recovery.php` (new) | Old approval format, immutable snapshots, interrupted writes, edited-draft protection, and stable IDs. |
| `tests/export-gutenberg-runtime.php` (new) | Exports installed official core JS dependency paths for validation. |
| `tests/gutenberg.test.cjs` (new) | Runs official core parsing, save validation, save round trips, and native block edit operations in jsdom. |
| `tests/integration.php` | Retains original coverage; checks native output, preview/approval versioning, rich fixture insertion, and lifecycle rendering. |
| `tests/admin.test.cjs` | Retains organizer tests and verifies that previews consume server output and display fallback warnings. |
| `package.json`, `package-lock.json` | Locked development-only jsdom dependency and explicit Gutenberg validation command. |
| `README.md`, `readme.txt`, `docs/specification.md`, `docs/acceptance.md`, this report | Updated behavior, compatibility, verification, and release gate. |
| `dist/letterlift-1.0.0.zip` | Rebuilt installable runtime package; development dependencies/tests are excluded. |

## Supported native content

| Source representation | Generated core blocks |
| --- | --- |
| Paragraphs and supported inline phrasing | `core/paragraph` |
| Semantic h1–h6, or explicit normalized heading metadata | `core/heading` with the known level |
| Image, linked image, figure with a known caption | `core/image`, local attachment ID/URL, original alt/caption/link |
| Explicit adjacent caption from the same source section and original position | Caption inside the corresponding `core/image` |
| Ordered/unordered/nested lists | `core/list` and `core/list-item`, retaining starts, supported type, and reversed ordering |
| Quotation and trailing citation | `core/quote` with native paragraph/other supported children and citation |
| Horizontal rule | `core/separator` |
| Simple data table with inline cell content | `core/table`, preserving head/body/foot, th/td, scope, links, and emphasis |
| A standalone link explicitly classified as a CTA by the normalized model | `core/buttons` and `core/button`; more general formatted links stay native paragraphs |

Bold and italic synonyms are canonicalized to semantic strong/em markup without changing wording. Underline, strike, superscript/subscript, code phrasing, hyperlinks, line breaks, Unicode, and escaped literal text are preserved.

Known semantic heading levels remain unchanged. If normalized metadata explicitly says heading but has no level, h2 is the conservative fallback. Bold paragraphs are not guessed to be headings. Ordinary italic paragraphs are not guessed to be image captions. Uncertain caption associations stay separate and in source order.

## HTML fallback and content fidelity

Fallback is local to the unsupported normalized region, rather than the whole article. Current fallbacks include definition lists, preformatted/structured regions not in the native conversion set, merged/nested/complex data tables, table captions whose source placement cannot be preserved by this converter, complex list items containing multiple paragraphs or text after nested lists, and mixed text/inline-image paragraphs whose paragraph boundaries would otherwise change. Some image-link attributes not supported by native Image save markup also require fallback. Earlier extractor warnings for unsupported embeds remain in force; this serializer does not reconstruct discarded executable embeds or bypass required integrity acknowledgement.

Before conversion, markup is sanitized with the existing allowlist and source comments are removed so they cannot inject Gutenberg delimiters. The serializer compares source/output signatures for visible text, emphasis, line breaks, link destinations/titles, image URLs/alt text, and ordering. A mismatch preserves the sanitized source region as `core/html` and creates a source-block-linked review warning. It does not silently flatten a complex structure into text.

Explicit high-severity warnings still block execution until acknowledged or explicitly excluded through the existing review system. A safe HTML fallback warning is a formatting review, because the sanitized content is preserved. Original source snapshots remain immutable.

## Preview consistency and image safety

`GET /letterlift/v1/sessions/{id}/preview` uses the saved review plan, or the immutable execution snapshot if already approved. The endpoint uses the same versioned serializer as draft creation, renders only a checked allowlist of core blocks with WordPress rendering, and sanitizes the result. It returns serialized content, rendered HTML, revision, serializer version, and per-destination fallback warnings.

The client no longer constructs a separate article preview from normalized block HTML. It displays server output in the existing restricted sandbox and replaces source images with placeholders or validated image data fetched through the existing safe image-preview endpoint. The browser never requests source image URLs directly. Unsaved changes invalidate the displayed preview; late responses cannot replace a newer plan's preview.

Before execution, image references still identify analyzed source assets and have no attachment IDs. During execution, the same conversion rules substitute validated local Media Library URLs and attachment IDs. This is the intended reference resolution; wording, grouping, captions, and block types use the same rules.

## Backward compatibility and recovery

- Newly approved plans record `serializer_version: gutenberg-v2` beside their immutable snapshot and plan hash.
- Existing executions missing that field use `html-v1`. They are never upgraded by inference.
- Legacy serialization retains the original byte-for-byte wrapper behavior, including original line breaks, sanitization, and URL substitution. URL-only and richer attachment maps are accepted.
- The version stays fixed through retries. Unknown versions fail explicitly.
- Previously completed posts are skipped. An interrupted post INSERT is found by stable GUID and compared against the content from its own serializer version.
- User-edited drafts fail integrity verification and remain untouched. Restoration of approved content is an explicit editorial action, not an automatic migration.
- Existing posts are not rewritten. Native blocks and fallback HTML both render without Letterlift after deactivation/uninstall.

## Automated results and reproduction

Tested with PHP 8.4.27 and isolated local WordPress 6.6 and 7.1.4 installations using the official SQLite integration. There were no production-site changes.

| Suite | Result per tested WordPress version |
| --- | --- |
| Original integration suite, extended | 94 assertions pass; original 75 behaviors retained with native output expectations |
| Serializer suite | 74 assertions pass |
| Legacy compatibility/recovery suite | 12 assertions pass |
| Official core JS validity suite | 141 block instances exercised, including parse/save round trips and native paragraph/heading/image/list edits |
| Admin DOM suite | 5 tests pass |
| PHP / JavaScript syntax | Pass |

Core JS tests load the scripts from each actual WordPress installation, not approximations of core block save functions. They check native block `isValid`/`validateBlock`, nested structures, and survival of text/emphasis/links/images through editor serialization. Custom HTML intentionally bypasses normal native save validation in current WordPress; its parser state and raw content preservation are tested separately across save round trips. This exception is not used for native blocks.

On an isolated local site with Letterlift active:

```sh
npm ci
npm test
npm run check
LETTERLIFT_TEST_RUN=1 wp eval-file tests/integration.php --path=/path/to/disposable/wordpress
LETTERLIFT_TEST_RUN=1 wp eval-file tests/recovery.php --path=/path/to/disposable/wordpress
LETTERLIFT_TEST_RUN=1 LETTERLIFT_GUTENBERG_CASES=/tmp/letterlift-cases.json wp eval-file tests/serializer.php --path=/path/to/disposable/wordpress
wp eval-file tests/export-gutenberg-runtime.php --path=/path/to/disposable/wordpress > /tmp/letterlift-runtime.json
LETTERLIFT_GUTENBERG_RUNTIME=/tmp/letterlift-runtime.json LETTERLIFT_GUTENBERG_CASES=/tmp/letterlift-cases.json npm run test:gutenberg
```

Repeat against the minimum supported WordPress and the intended staging version. The explicit Gutenberg command needs both exported files; without them, the Node test reports skipped rather than implying core validation happened. Run integration/lifecycle tests only on disposable sites: they create/delete test content and exercise uninstall.

Coverage includes all 25 requested areas: paragraphs, headings, inline emphasis/links, ordered/unordered/nested lists, managed images/captions/alt text, data/layout tables, quotes, Unicode/escaping, HTML fallback and integrity warnings, multiple destinations, mixed native/fallback content, manual ordering, shared previews, PHP/JS structure checks, legacy recovery, completed post preservation, and deactivation/uninstall rendering.

## Real browser acceptance results

No checks A–G are marked complete. The browser connector reported that its host was unavailable. Native computer-use access to the installed Brave browser was then not approved. jsdom and block API edits do not substitute for real Gutenberg controls.

| Required check | Result |
| --- | --- |
| A: Edit simple article using native controls | Unverified / release blocker |
| B: Replace native images in Media Library controls; inspect attached captions | Unverified / release blocker |
| C: Edit lists and data table using native controls | Unverified / release blocker |
| D: Inspect representative older real Mailchimp campaign | Unverified / release blocker |
| E: Inspect only the complex fallback region and its warning | Unverified / release blocker |
| F: Open/edit each draft in a multi-post import | Unverified / release blocker |
| G: Deactivate and confirm real editor/front-end independence | Automated rendering passes; real browser check unverified / release blocker |

Before acceptance, capture browser evidence for A–G, confirm there are no invalid-block/recovery prompts, and complete the existing staging, keyboard, responsive, and live-source checks. MySQL/MariaDB behavior, PHP 8.1 runtime execution, theme-specific appearance, and manual accessibility remain staging verification items; the implementation preserves the declared minimum and introduces no production runtime dependencies.
