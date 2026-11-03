# Letterlift 1.0
## WordPress Administration UX and Product Behavior Specification

**Status:** Proposed implementation specification  
**Version:** 1.0  
**Date:** November 3, 2026  
**Product:** Letterlift, Newsletter to WordPress Importer

---

## 1. Product purpose

Letterlift helps WordPress administrators convert publicly accessible email newsletters into clean, independently editable WordPress posts.

The core experience is:

**Paste a URL → Analyze → Organize → Preview → Create Drafts**

Letterlift must support both:

1. Converting selected newsletter content into one WordPress post.
2. Converting separate stories or sections from one newsletter into multiple WordPress posts.

The plugin must preserve the original editorial material, provide human-controlled content selection, and leave all resulting posts usable without Letterlift.

### 1.1 Design principles

- **Simple by default:** Common tasks should require minimal configuration.
- **Precise when needed:** Users can expand sections to inspect and manage individual blocks.
- **Editorial control:** Recommendations are suggestions, not irreversible automated decisions.
- **Content preservation:** Original wording, links, images, captions, and meaningful formatting are preserved wherever possible.
- **Progressive disclosure:** Advanced options remain available without overwhelming beginners.
- **WordPress-native:** The admin interface should feel like part of WordPress.
- **Recoverable:** Users can safely return to unfinished imports.
- **Non-destructive:** Analysis, exclusions, failures, and plugin removal must not destroy source or previously imported content.

### 1.2 Supported scope

The first release supports public Mailchimp campaign archive URLs, including representative legacy table-based layouts and modern campaign templates.

Other newsletter platforms are not supported in 1.0, although the underlying architecture must allow additional provider adapters.

---

## 2. Navigation and information architecture

Letterlift lives under **Tools → Letterlift**.

The plugin provides three main views.

| View | Purpose |
|---|---|
| New Import | Begin and complete the guided import workflow |
| Import History | Review, resume, inspect, and retry previous imports |
| Settings | Configure site-wide defaults and plugin behavior |

An unfinished import may appear as a resumable workspace within New Import and Import History. A separate dashboard is unnecessary for 1.0.

### 2.1 Navigation behavior

- The plugin opens to New Import by default.
- Saved unfinished sessions are prominently offered for resumption.
- Users can navigate backward through review steps without losing work.
- Navigating away does not initiate or cancel import execution.
- Import history remains available independently of the original source URL.
- Navigation changes should not trigger unnecessary full-page reloads within the workflow.

---

## 3. Step 1: Add Newsletter

### Screen title

**Give your newsletter a new home.**

Supporting text:

*Paste a link to a published newsletter. We'll help you turn its content into WordPress posts.*

### Controls

- Newsletter URL input.
- Analyze Newsletter primary action.
- Resume Existing Import link, displayed when relevant.

### Required behavior

1. Accept a public HTTP or HTTPS newsletter URL.
2. Validate that the URL is structurally valid and supported.
3. Confirm that the current administrator has permission to initiate analysis.
4. Detect whether the URL was previously imported or is associated with an unfinished session.
5. Start read-only source analysis after explicit user action.

### Duplicate handling

If an existing import is identified, show:

**We've seen this newsletter before.**

Provide access to its import history and existing posts.

The user may choose to review it again or deliberately create a separate import. Existing posts must never be overwritten simply because the source URL matches.

### Error states

Provide specific messages for:

- Invalid URL.
- Unsupported newsletter provider.
- Newsletter unavailable.
- Authentication or restricted-access page.
- Request timed out.
- Unsafe URL or redirect.
- Newsletter exceeds supported limits.
- No recognizable content.

Do not expose server paths, credentials, stack traces, or technical implementation details to normal administrators.

### Acceptance criteria

- Analysis creates no posts or Media Library attachments.
- Invalid and unsafe URLs are rejected.
- Existing imports are identified.
- The screen provides a clear recovery action for expected failures.

---

## 4. Step 2: Analyze Newsletter

### Screen title

**Let's take a look at your newsletter.**

The interface displays progress through the analysis process.

Suggested phases:

1. Retrieving newsletter.
2. Identifying content.
3. Finding images and captions.
4. Identifying possible stories.
5. Checking content integrity.
6. Preparing review.

These phases should reflect real processing work, not artificial progress animations.

### Analysis results

Display a summary containing:

- Extracted newsletter title.
- Detected provider.
- Source URL.
- Number of detected sections.
- Number of content blocks.
- Number of referenced images.
- Number of suggested story boundaries.
- Number of sections suggested for exclusion.
- Content integrity warnings.

The summary must distinguish between detected facts, heuristic suggestions, and unsupported structures.

### Important rule

**Analysis is not an import.**

It may persist a private, bounded analysis session for resumption, but it must not create posts, download images into the Media Library, or publish content.

### Confidence indicators

Use descriptive states rather than unsupported percentage scores:

- Ready for review.
- Review recommended.
- Some content needs attention.
- Unable to safely process.

Warnings must explain what requires attention and provide a way to inspect the relevant original content.

---

## 5. Step 3: Organize Content

This is the primary Letterlift workspace.

### Screen title

**Make this newsletter yours.**

Supporting text:

*Choose what to keep, organize your stories, and decide what becomes a WordPress post.*

### 5.1 Layout

On desktop, use a two-panel composition:

**Left panel: Content Organizer**

- Detected newsletter sections.
- Section selection controls.
- Expandable content blocks.
- Content grouping and assignments.
- Reordering controls.
- Suggested exclusions.
- Unassigned content.

**Right panel: Draft Outline and Preview**

- Selected import mode.
- Planned destination posts.
- Content outline for the active draft.
- Selected block count.
- Editorial warnings.
- Preview of the active draft.

On smaller screens, the interface switches to a vertical layout, with Content Organizer and Draft Preview available as separate panels or tabs.

No functionality may depend exclusively on hover or dragging.

### 5.2 Import mode selection

Two modes are supported:

**Single Post**

All selected content assigned to the destination is combined into one WordPress post.

**Multiple Posts**

Selected content may be distributed across multiple independent destination posts.

Single Post is the default mode.

Switching modes is non-destructive. In particular, switching from Multiple Posts to Single Post must preserve the underlying multi-post organization so it can be restored if the user switches back.

The interface must explain that changing the mode changes the planned output, not the original extracted content.

### 5.3 Section representation

Each section displays:

- Detected or user-edited section title.
- Section type.
- Number of child blocks.
- Selection status.
- Destination assignment.
- Recommendation indicator, when applicable.
- Expand or collapse control.
- Original newsletter position.

Example:

**The Story Behind the Collection**

Feature Story · 7 content blocks

Selected · Assigned to Draft 1

### 5.4 Section selection states

Sections support three states:

| State | Meaning |
|---|---|
| Selected | All eligible child blocks are selected |
| Partially selected | Some child blocks are selected |
| Not selected | No child blocks are selected |

A parent section checkbox reflects the state of its child blocks.

Selecting a section selects its eligible child blocks.

Deselecting a section deselects its child blocks.

The interface must not represent a partially selected section as fully selected.

### 5.5 Expandable content blocks

Expanding a section exposes its individual blocks.

Initial block categories:

- Heading.
- Paragraph.
- Image.
- Caption.
- List.
- Quote.
- Divider.
- Button or linked call-to-action.
- Table or structured content.
- Unsupported HTML region.

Every block should retain its original source position and parent-section relationship.

Each block includes:

- Selection control.
- Type indicator.
- Readable content or visual representation.
- Destination assignment when applicable.
- Warnings when extraction is uncertain.

Long paragraphs should be expandable rather than arbitrarily truncated with no way to inspect their complete contents.

### 5.6 Selection versus assignment

Selection and destination assignment are separate concepts.

A block may be:

- Selected and assigned to a draft.
- Selected but unassigned.
- Excluded from the import.

Unassigned content must remain visible.

A selected but unassigned block must not be imported until it receives a valid destination.

The final review must clearly distinguish intentionally excluded blocks from blocks that still require assignment.

### 5.7 Content grouping

Users can:

- Create a destination draft.
- Rename a destination draft.
- Assign a section to a draft.
- Assign individual blocks to different drafts.
- Move content between drafts.
- Reorder content within a draft.
- Remove a draft from the plan without destroying extracted content.
- Restore excluded or unassigned content.

Each selected block may belong to only one destination post within the same import plan in 1.0.

Users should not be required to manually split the source HTML.

### 5.8 Story detection

Letterlift may recommend story boundaries based on headings, separators, visual grouping, provider structures, and other deterministic indicators.

Suggested stories must be editable.

Users can combine suggested stories, split a story into multiple destinations, or ignore suggested boundaries entirely.

A suggested story does not automatically become a post.

### 5.9 Reordering

Provide accessible controls for moving sections and individual blocks.

For example:

- Move Up.
- Move Down.
- Move to Draft.
- Move to Unassigned.

Drag-and-drop may enhance this workflow but cannot be the only method.

The final post order must match the order shown in the draft preview.

### 5.10 Promotional content

Letterlift may suggest exclusions for:

- Subscription links.
- Email preference links.
- Unsubscribe links.
- Repeated email-only headers.
- Tracking-related footer elements.
- Social sharing boilerplate.
- Common advertising or promotional regions.

The user makes the final decision.

A mention of a product, price, event, sale, or commercial organization must not automatically justify removal.

Historical and editorial material must be treated as potentially valuable even when it resembles promotional content.

### 5.11 Warnings and content integrity

Warnings should appear beside the relevant sections or blocks.

Examples:

- This table may need formatting review.
- This image could not be identified.
- This caption may be associated with a different image.
- Part of this section contains unsupported HTML.
- We found text that may not appear in the current draft.

Unresolved high-severity integrity warnings must block execution when they indicate potential silent content loss, unless the user explicitly resolves them through a supported, auditable selection or exclusion decision.

Cosmetic warnings should not unnecessarily prevent importing.

### Acceptance criteria

- The administrator can complete an import using only section-level decisions.
- Any section can be expanded for block-level control.
- Mixed selection states display accurately.
- Blocks can move across posts without copying or data loss.
- Draft output order is predictable.
- Excluded and unassigned content remains recoverable.
- Source content cannot silently disappear during reorganization.

---

## 6. Step 4: Preview Drafts

### Screen title

**Everything look good?**

Supporting text:

*Review your WordPress drafts before we create them.*

### 6.1 Destination navigation

For single-post imports, display one draft preview.

For multi-post imports, display a list of planned drafts.

Each draft shows:

- Title.
- Assigned section count.
- Assigned block count.
- Featured image selection.
- Warnings.
- Readiness status.

Selecting a draft updates its details and preview.

### 6.2 Post settings

Each draft supports:

| Field | Default |
|---|---|
| Title | Extracted or suggested title |
| Slug | Generated from title |
| Excerpt | Suggested from selected editorial text |
| Category | Site-wide default |
| Author | Configured default or current authorized user |
| Featured image | Suggested from included images |
| Post status | Draft |
| Publication date | Current date unless changed |
| Post type | Standard WordPress post |

Users may edit each field individually.

Custom post types are deferred beyond 1.0.

WordPress must remain responsible for final slug uniqueness and post validation.

### 6.3 Article preview

The preview renders the planned normalized WordPress content, including:

- Heading hierarchy.
- Paragraphs.
- Images.
- Captions.
- Lists.
- Quotes.
- Links.
- Emphasis.
- Supported structured content.

The preview should be visually representative of the content entering WordPress.

It need not reproduce the site's exact front-end styling. Theme-specific appearance is the responsibility of the active WordPress theme.

### 6.4 Image selection

Featured images are selected independently for each destination post.

Image choices come from the analyzed newsletter.

An image used within more than one imported post should reuse the same safe, managed Media Library attachment where appropriate.

Caption text and image order must remain preserved.

The user must be alerted when an image cannot be safely downloaded or converted.

### 6.5 Final review summary

Before executing, show:

- Newsletter source.
- Number of destination posts.
- Titles of the planned drafts.
- Included and excluded block counts.
- Unassigned block count.
- Images to import.
- Outstanding warnings.
- Duplicate-source warnings.
- Confirmation that all posts will be created as drafts.

The primary action is:

**Create [N] Drafts**

For a single post, use **Create Draft**.

Do not offer Publish Now in 1.0.

### Acceptance criteria

- Every draft has a valid title and at least one meaningful content block.
- Each destination has independently configurable post details.
- The final preview matches the approved content plan.
- All import-blocking warnings are resolved.
- No post is created before the final execution action.

---

## 7. Step 5: Create Drafts

### Screen title

**Bringing your stories into WordPress.**

This screen displays actual import execution progress.

### 7.1 Progress model

Track:

1. Approved plan preparation.
2. Image downloading and validation.
3. Media Library attachment creation or reuse.
4. Post content generation.
5. WordPress draft creation.
6. Featured image and metadata assignment.
7. Final integrity and completion checks.

Each step must be safely resumable.

### 7.2 Per-post status

For multiple posts, display each destination individually.

Supported states:

- Pending.
- Processing.
- Complete.
- Needs attention.
- Failed.

A failure in one destination must not cause completed posts to be duplicated during recovery.

### 7.3 Safe execution

Once import begins, the approved plan is recorded as an immutable execution snapshot.

Execution operations must use stable identifiers and duplicate protection.

Retries should reuse successfully created WordPress posts and Media Library attachments where appropriate.

Incomplete processing must not leave posts accidentally published.

Execution must accommodate normal shared-hosting request limits through bounded, resumable operations.

### 7.4 Completion screen

On success:

**Your drafts are ready!**

Show each created post with an Edit Post action.

Additional actions:

- View Import Details.
- Start Another Import.

For partially successful imports:

**Some drafts need attention.**

Show completed drafts, incomplete destinations, and supported retry actions.

The user must not need to repeat the entire import because one image or post failed.

---

## 8. Import History

### Purpose

Provide a persistent record of newsletter imports, including attempts with multiple destination posts.

### 8.1 History list

Display:

- Newsletter title.
- Source provider.
- Source URL or hostname.
- Import date.
- Number of destination posts.
- Status.
- Resume or View Details action.

Statuses:

- Analyzed.
- In Review.
- Ready to Import.
- Importing.
- Complete.
- Needs Attention.
- Failed.

### 8.2 Import detail screen

The detail view displays:

- Original source URL.
- Provider.
- Source identity and relevant fingerprints.
- Analysis date.
- Approved import plan.
- All destination post IDs.
- Associated Media Library assets.
- Import operation status.
- Error details and recovery actions.
- Integrity review outcomes.

### 8.3 Duplicate detection

Duplicate prevention must operate at multiple levels:

**Source level:** Detect a previously analyzed or imported newsletter.

**Destination level:** Identify posts created from the source and approved content plan.

**Operation level:** Prevent retries from recreating already completed posts or media.

A revised source may be analyzed again, but the user must explicitly approve any additional import.

A repeated import must not silently update or overwrite existing published content.

### 8.4 Recovery

Users must be able to:

- Resume an unfinished review.
- Retry incomplete processing.
- Return to the existing posts.
- Review unresolved errors.
- Start a separate import deliberately.

Recovery must be designed around preserving completed work, not automatically deleting it.

---

## 9. Settings

Settings should be minimal, understandable, and optional for ordinary imports.

### 9.1 General defaults

- Default category.
- Default post author.
- Default import mode.
- Preferred featured-image behavior.
- Default handling of suggested newsletter boilerplate.

### 9.2 Processing settings

Only expose advanced operational controls when they are useful and safe to modify.

Reasonable processing and resource limits should have safe defaults.

Avoid presenting administrators with raw PHP memory limits, arbitrary parsing parameters, or complex adapter configuration.

### 9.3 Data retention

Allow administrators to manage old analysis sessions and diagnostic records.

Imported posts and Media Library attachments must remain untouched by routine history cleanup and plugin uninstall.

Cleanup options should clearly differentiate plugin-generated temporary data from permanent WordPress content.

### 9.4 Privacy

The plugin must not require an account.

Newsletter analysis and transformation must happen within the WordPress installation, with network retrieval limited to the selected public source and required content assets.

No analytics or telemetry should be enabled without appropriate disclosure and consent.

---

## 10. Accessibility requirements

Letterlift should follow WordPress admin accessibility conventions and target WCAG 2.2 AA for plugin-owned interfaces.

Requirements include:

- Full keyboard navigation.
- Visible focus indicators.
- Proper labels and descriptions.
- Accessible checkbox mixed states.
- Accessible dialog and panel behavior.
- Screen-reader announcements for important status changes.
- Adequate contrast.
- No color-only communication.
- No drag-and-drop-only workflows.
- Support for browser zoom and smaller viewports.
- Accessible error summaries that link to affected controls.
- Reduced-motion preferences respected.

Progress updates must not overwhelm screen-reader users with constant announcements.

---

## 11. Content safety and trust

### 11.1 Source fidelity

Source content should be immutable within the analysis record.

User selections and transformations operate on normalized representations, not by rewriting the original retrieved source.

### 11.2 Content integrity

The system must compare the extracted editorial content with the source representation wherever feasible.

Complex or unsupported structures must be preserved safely or flagged, not discarded without notice.

### 11.3 Remote content

All remote newsletter and image retrieval must be protected against unsafe destinations, redirects, oversized responses, unexpected content types, and resource exhaustion.

### 11.4 WordPress permissions

Only users with explicitly authorized capabilities may create import plans and generate posts.

Actions must use appropriate authorization, request validation, and anti-CSRF protections.

A capability check must not be replaced by a nonce check.

### 11.5 Rights and attribution

Letterlift must explain that importing a publicly accessible newsletter does not grant republication rights.

Preserve source attribution metadata and provide the original URL in import history.

No attribution should be inserted into public post content without an explicit product decision or user selection.

---

## 12. Responsive behavior

### Desktop

Use a two-panel organizer with an accessible draft outline and preview.

### Tablet

Allow the organizer and preview to stack or switch through labeled panels.

### Mobile

Support the complete import workflow with a single-column layout.

Users must still be able to:

- Select sections.
- Expand blocks.
- Assign content to drafts.
- Reorder content.
- Edit post details.
- Review warnings.
- Create drafts.
- Recover failures.

A desktop-first visual arrangement is acceptable, but core functionality must not disappear on mobile.

---

## 13. Non-goals for 1.0

The following are intentionally excluded:

- Scheduled newsletter synchronization.
- Automatic publishing.
- Bulk campaign archive discovery.
- Additional newsletter platform adapters.
- External AI extraction or rewriting.
- Custom WordPress post types.
- Third-party automation integrations.
- Full rich-text editing inside Letterlift.
- Newsletter creation or email delivery.
- Replacing the WordPress Block Editor.
- Commercial subscriptions or required cloud services.

---

## 14. Final release acceptance scenarios

The 1.0 release must demonstrate successful handling of:

1. A simple newsletter converted into one draft.
2. A multi-story newsletter converted into multiple drafts.
3. A newsletter with incorrectly detected story boundaries.
4. A newsletter with partially selected sections.
5. Content blocks moved between destination posts.
6. Images and captions preserved across story grouping.
7. Promotional content retained by user choice.
8. Newsletter boilerplate excluded by user choice.
9. Complex table-based email content.
10. Unrecognized or unsupported content structures.
11. Duplicate newsletter URLs.
12. A source revisited after previous imports.
13. An interrupted multi-post import.
14. One destination failing while others succeed.
15. Image download failures and safe retries.
16. Session interruption and restoration.
17. Keyboard-only administration.
18. Small-screen administration.
19. Plugin deactivation after import.
20. Plugin uninstall without deleting imported posts or media.

Release acceptance requires automated test coverage for the core behavior, security tests for untrusted input, and manual WordPress acceptance testing with representative Mailchimp newsletters.

---

## 15. Definition of done

Letterlift 1.0 is complete when an ordinary WordPress administrator can paste a supported public Mailchimp newsletter URL, review the extracted content, select entire sections or individual blocks, organize the material into one or multiple posts, preview the results, and safely create native WordPress drafts.

The process must preserve original editorial content, transparently report uncertainties, avoid duplicate creation, recover from interruption, and leave the resulting WordPress content independent of the plugin.

**The product succeeds when people trust the result enough to use Letterlift without needing a developer to supervise every import.**

## 16. Required native Gutenberg generation amendment

New import approvals must generate independently editable native WordPress core blocks wherever safely supported: Paragraph, Heading, Image (including known captions and managed attachment IDs), List/List Item, Quote, Separator, simple data Table, and supported Buttons/Button CTAs. Sanitized Custom HTML is restricted to reviewed fallback regions. Native serialization lives separately from provider extraction.

The server preview and execution must use the same versioned serializer and approved content model. Existing posts are not migrated. Previously approved unversioned executions retain the original serializer during recovery. New approvals record their serializer version without changing prior immutable plans.

Valid Gutenberg save markup, natural editing with native controls, and independence after deactivation/uninstall are release blockers. PHP parsing and automated JavaScript validation do not replace real browser acceptance. See [native Gutenberg implementation and acceptance](native-gutenberg.md).
