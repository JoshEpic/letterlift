=== Letterlift ===
Contributors: letterlift
Tags: newsletter, mailchimp, importer, drafts
Requires at least: 6.6
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Turn public Mailchimp newsletters into independently editable WordPress drafts.

== Description ==
Paste a public newsletter URL, analyze its content without creating posts, organize sections or individual blocks, preview your plan, and create one or multiple WordPress drafts.

* Native Gutenberg paragraphs, headings, images with captions, lists, quotes, separators, simple tables, and buttons.
* Custom HTML only for reviewed fallback regions.
* Section and block selection with mixed checkbox states.
* Separate single-post and multi-post organization.
* Accessible move controls and recoverable unassigned content.
* Safe image previews and Media Library imports.
* Saved reviews, immutable approved plans, and resumable retries.
* No account, cloud service, AI rewriting, telemetry, or automatic publishing.

Only administrators with post-editing and media-upload permissions may use Letterlift. PHP DOM is required. Obtain republication rights before importing content.

== Installation ==
1. Upload the letterlift directory to wp-content/plugins, or install the plugin ZIP.
2. Activate Letterlift.
3. Open Tools -> Letterlift.

== Frequently Asked Questions ==
= Does analysis create posts or Media Library images? =
No. Posts and attachments are created only after the final Create Drafts action.

= Can I deactivate or uninstall Letterlift after importing? =
Yes. Posts use core WordPress content and managed images. They remain after deactivation or uninstall.

= Are posts published automatically? =
No. All created posts have draft status.

= What happens if an import is interrupted? =
Resume from Import History. Completed operations are reused; failed destinations can be retried independently.

== Changelog ==
= 1.0.0 =
Native Gutenberg serialization for new approvals; older approved imports retain their original serializer during recovery. Existing posts are not migrated. Real Block Editor acceptance remains a release requirement. See the repository acceptance checklist before production release.
