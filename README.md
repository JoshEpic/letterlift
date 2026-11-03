# Letterlift

A self-contained WordPress plugin for converting public Mailchimp newsletters into one or more WordPress drafts. Open **Tools → Letterlift** after activation.

## Install

Requires WordPress 6.6+, PHP 8.1+, the PHP DOM extension, and WordPress-supported image processing. No Node.js, Composer, API key, account, or cloud service is needed on the WordPress server.

1. Run `python3 scripts/package.py` to generate `dist/letterlift-1.0.0.zip`, or copy this directory to `wp-content/plugins/letterlift`.
2. Activate **Letterlift** in WordPress.
3. Open **Tools → Letterlift**, paste a public Mailchimp campaign URL, and choose **Analyze Newsletter**.
4. Select sections or expand individual blocks. In Multiple Posts mode, assign selected content to destination drafts. Removing a destination retains its content as unassigned.
5. Continue to Preview, edit post settings, and resolve integrity warnings. **Load image previews** safely retrieves selected images without adding them to the Media Library.
6. Choose **Create Draft** / **Create N Drafts**. The approved plan becomes immutable. Leave and resume from Import History if necessary; retries retain completed work.

Supported source domains: `mailchi.mp`, `campaign-archive.com`, `campaign-archive1.com`, `campaign-archive2.com`, `list-manage.com`, `list-manage1.com`, and `list-manage2.com`, including their subdomains. A link must point to a public campaign, rather than a campaign index, sign-up form, login page, or restricted page. Custom Mailchimp-hosted domains and other newsletter providers are outside this version's adapter.

## Content and privacy

Analysis saves a private original HTML source and normalized blocks in a dedicated WordPress table. It creates no posts or attachments. Selections, ordering, section titles, and destinations are stored separately from that source. Story boundaries and boilerplate labels are deterministic suggestions; commercial subject matter alone does not trigger exclusion. The dedicated serializer checks text, emphasis, links, images, alt text, and ordering before accepting a native conversion. Unsafe or lossy conversion preserves the sanitized region as HTML and exposes a formatting warning.

Newly approved imports use native Gutenberg Paragraph, Heading, Image, List/List Item, Quote, Separator, Table, and Buttons/Button blocks. Images include managed attachment IDs, alt text, meaningful links, and known captions. Sanitized Custom HTML is a reviewed fallback for structures outside safe native conversion, rather than the default article format. They remain editable and render without Letterlift. No plugin shortcode, custom block, or public attribution is inserted. The original URL and import identifiers are kept in private post metadata.

Images are validated and imported into the Media Library, with reusable attachment identities. Until explicitly loaded through the safe preview endpoint, images appear as placeholders. Previews use a sandboxed frame with no scripts or direct remote resource loading. A theme's final appearance can differ from the admin preview.

Only administrators who can edit posts and upload media may use the API. Every request also requires a WordPress REST nonce. Remote requests use WordPress safe HTTP APIs, public-address validation, bounded manual redirects, file-type checks, and response limits. No telemetry or external AI processing is included.

Limits: 2 MiB source HTML, 500 content blocks, 50 suggested stories/destinations, 100 images, 10 MiB and 40 megapixels per image. Each execution request handles one image or destination. The browser drives processing; navigating away pauses it after the in-flight operation. The next visit requires an explicit Resume Processing action.

Importing public content does not grant republication rights. Obtain the rights you need.

## Recovery and retention

Unfinished reviews autosave. Save Review gives an explicit checkpoint; a browser warning protects unsaved changes. Revision checks reject stale writes from other windows.

Approval records a frozen plan, approver, timestamp, hash, and serializer version. New approvals pin `gutenberg-v2`. Executions approved before serializer versioning retain `html-v1`; missing version fields are never interpreted as permission to upgrade. Existing posts are not migrated or rewritten. Session leases prevent concurrent execution. Stable post GUIDs and attachment slugs recover successful writes even when a response or checkpoint is interrupted. Imported draft content is compared with the approved content before completion. A changed existing post is preserved and reported for review.

Failed images block only destinations that need those images. Other destinations continue. Retry processes incomplete operations and reuses completed posts/media. A removed or manually edited in-progress post may require restoration before retry; Letterlift does not overwrite editorial changes or recreate deleted content automatically.

Settings provide manual cleanup of records older than the configured age. Cleanup removes original source snapshots and unfinished reviews, excluding active executions. Imported posts, attachments, and their private attribution metadata remain. Duplicate detection can still find imported posts after history cleanup. Deactivation preserves everything. Uninstall removes the session table and Letterlift options, retaining posts and media.

## Development and verification

There is no production build step. Frontend checks require Node.js 20+:

```sh
npm ci
npm test
npm run check
find . -path './node_modules' -prune -o -name '*.php' -exec php -l {} \;
```

The PHP integration, serializer, and recovery suites use real WordPress APIs and storage with mocked remote responses. It must run only on an isolated, disposable WordPress installation configured with `WP_ENVIRONMENT_TYPE` set to `local`, an administrator with ID 1, and Letterlift activated. The suite creates and deletes test posts/media, exercises deactivation and uninstall, and restores plugin tables/settings. Never run it against a working site.

```sh
LETTERLIFT_TEST_RUN=1 wp eval-file /absolute/path/to/letterlift/tests/integration.php --path=/path/to/disposable/wordpress
LETTERLIFT_TEST_RUN=1 wp eval-file /absolute/path/to/letterlift/tests/serializer.php --path=/path/to/disposable/wordpress
LETTERLIFT_TEST_RUN=1 wp eval-file /absolute/path/to/letterlift/tests/recovery.php --path=/path/to/disposable/wordpress
```

The mock blocks unexpected HTTP requests. DNS resolution for Mailchimp hosts is still needed because public-address checks run before the HTTP mock.

The admin preview is rendered on the server from the same serializer and saved content plan used for draft creation; the browser only isolates that result and substitutes validated image previews.

See the [native Gutenberg delivery report](docs/native-gutenberg.md) for the test matrix, official core JavaScript validation commands, compatibility rules, supported structures, and remaining browser acceptance gate.

See [manual acceptance](docs/acceptance.md) and the supplied [product specification](docs/specification.md). Automated checks are passing; a 1.0 release still requires representative live campaign testing and manual accessibility/responsive review. The browser connector was unavailable and native browser control was not approved, so real editor/visual acceptance is not claimed.
