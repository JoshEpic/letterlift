# Letterlift 1.0 acceptance status

Implementation date: November 3, 2026.

The plugin is implemented and installable. Automated checks pass. Release acceptance remains open until the manual checks below have been completed on representative public Mailchimp campaigns in a real WordPress admin browser.

## Verified automatically

The integration suite runs on an isolated WordPress installation using the official SQLite integration. Remote HTML/image responses are mocked, while WordPress REST, permissions, database writes, post insertion, Media Library processing, cleanup, and lifecycle APIs run normally.

Current results for the native Gutenberg improvement: **94 integration assertions**, **81 serializer assertions**, and **12 compatibility/recovery assertions** on each tested WordPress version (**6.6** and **7.1.4**), using PHP **8.4.27** and isolated SQLite databases. The original 75 integration assertions are retained (their old Custom HTML expectation is updated to assert native block types). **5 DOM interface tests** pass. Official core JavaScript parsing/save-function checks exercise **159 block instances per WordPress version**, including save round trips and editing through native block APIs. PHP and JavaScript syntax checks pass.

These automated DOM and core JS checks are not evidence of manual browser editing, assistive technology behavior, or visual layout. See [the native Gutenberg report](native-gutenberg.md).

| Specification scenario | Automated evidence | Remaining manual review |
| --- | --- | --- |
| Simple newsletter → one draft | Single-post execution, content, caption, local images | Live simple campaign |
| Multi-story → multiple drafts | Separate destination execution | Live multi-story campaign |
| Incorrect story boundaries | Cross-story section assignments | Combine and split real stories |
| Partial section selection | DOM mixed checkbox state | Screen-reader mixed state |
| Move blocks between posts | Assignment and source immutability checks | Full keyboard workflow |
| Images and captions | Caption text, safe image preview, managed attachments, featured image | Visual image/caption placement |
| Retain promotional content | Product/price/sale content is not boilerplate | Editorial judgment on real examples |
| Exclude boilerplate | Defaults, explicit selections, recoverability | Footer variations |
| Complex table email | Layout traversal, data table retention with/without headers | Representative legacy campaigns |
| Unsupported structures | Inline and standalone embed warnings, approval/exclusion blocking | Review original vs fallback |
| Duplicate URLs | REST conflict and tracking-insensitive identity | Live URL variants |
| Revisited source | Explicit separate import; attribution survives history cleanup | Revised live source |
| Interrupted multi-post import | Stable GUID recovery after INSERT before checkpoint | Close browser / reload during execution |
| One destination fails | Image-dependent destination fails while another completes | Host-specific failure presentation |
| Image failure/retry | Mock timeout, safe retry, attachment reuse | Real unavailable or malformed assets |
| Session restoration | Persisted review, revisions, resume endpoint | Navigate away and reopen admin |
| Keyboard-only admin | Accessible control types and non-drag controls; text fields retain mounting | Complete import without mouse |
| Small-screen admin | Responsive CSS and full DOM control availability | 375px viewport and 200–400% zoom |
| Deactivation | Imported content remains intact | Front-end rendering with plugin disabled |
| Uninstall | Posts and image files survive removal of plugin data | Fresh install → import → uninstall |

Security checks cover anonymous access, independent nonce enforcement, unsafe/unsupported source URLs, credentials/ports, unsafe image redirects, response sizes, MIME types, XSS/event handlers, stale writes, concurrent leases, immutable approved plans, dropped/repeated blocks, unassigned content, and unresolved integrity warnings. Preview endpoints accept only asset IDs already present in the private analysis session.

## Manual release checklist

1. Install the packaged ZIP on a staging WordPress site with PHP DOM and supported image processing. Exercise both the minimum supported WordPress/PHP versions and the site's production versions. Repeat storage/concurrency checks on MySQL/MariaDB; automated execution used SQLite.
2. Use at least one real legacy table campaign, one modern campaign, and one campaign with structured/unsupported content. Obtain permission to republish the source material.
3. Compare the saved original, each normalized block, the draft outline, the preview, and the final editor. Confirm wording, emphasis, links, non-ASCII text, lists, table cells, image order, and captions.
4. Load image previews and choose distinct featured images per draft. Exclude an image and resolve its now-invalid featured image selection. Confirm media reuse across destinations and separate imports.
5. Exercise section mixed states, section titles, individual assignments, Unassigned, exclusions, draft removal, mode switching twice, block/section reordering, and empty destination validation.
6. Reload or close the tab during review and execution. Resume deliberately from history. Retry a failed image/destination. Confirm completed post IDs remain stable.
7. Make an editorial change to an in-progress draft, then retry. Confirm Letterlift reports the difference and preserves the change. Restore approved content if the operation must be completed.
8. Run the whole workflow using only Tab, Shift+Tab, Space, Enter, and arrow keys. Check visible focus, linked integrity warnings, labels, mixed states, error focus, and announcements with a screen reader.
9. Test at desktop, tablet, and 375px mobile widths and 200–400% zoom. Confirm all controls remain usable, preview content scrolls as needed, and no action depends on hover/dragging. Check contrast with the installed admin color scheme.
10. Test expected source failures: malformed URL, unsupported provider, login/restriction, timeout, unsafe redirect, oversized HTML, and no content. Confirm useful recovery text and no server paths/stack traces.
11. Complete an import, deactivate, and inspect the front end and Block Editor. Then uninstall. Confirm imported posts and media remain, plugin data is removed, and posts have no dependency on Letterlift.

## Implementation choices

- New approvals use native core Paragraph, Heading, Image, List/List Item, Quote, Separator, Table, and Buttons/Button blocks. Complex regions use sanitized Custom HTML with a formatting warning. No Letterlift blocks are required. Existing approved executions without a serializer version retain their original Custom HTML output.
- Original image URLs are not loaded directly by the admin browser. An explicit preview action retrieves and validates them on the WordPress server; the sandbox displays bounded image data.
- Processing is driven by explicit browser requests, one asset or destination per request. It resumes on user action and does not run cron/background publishing.
- Approved plans cannot be edited during recovery. A different content plan requires a deliberate separate import.
- DOM-based analysis is deterministic and conservative. Unsupported embeds require a reviewed safe fallback or explicit exclusion. No AI rewriting is performed.
- Exact theme styling, custom providers/domains, custom post types, bulk archive discovery, and automatic publishing remain outside 1.0 scope.

Real browser/editor and live-campaign acceptance remains unverified: the browser-control host was unavailable, and native computer-use access to Brave was not approved. Native serialization and natural editing in a real Gutenberg editor are release blockers until checks A–G in the native Gutenberg report are completed. No production deployment or existing-site change was performed.
