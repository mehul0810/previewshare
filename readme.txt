=== PreviewShare ===
Contributors: mehul0810
Tags: preview, draft preview, preview link, share draft, client review, public preview, private preview, drafts, scheduled posts, content approval, editorial workflow, block editor
Requires at least: 5.8
Tested up to: 7.0
Requires PHP: 8.0
Stable tag: 1.0.0
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Create secure public preview links for WordPress drafts, scheduled posts, and client reviews without user accounts.

== Description ==

PreviewShare is a secure public preview plugin for WordPress. It helps editors share draft posts, pending pages, scheduled content, private posts, and other enabled public post types through controlled preview links without publishing early or creating temporary reviewer accounts.

Use PreviewShare when a client, legal reviewer, teammate, editor, or stakeholder needs to review WordPress content before it goes live. Each preview URL uses a random token, can expire automatically, can be revoked, and is designed for private review workflows such as client approval, content approval, editorial review, and scheduled campaign checks.

PreviewShare stores token hashes instead of plain-text tokens, adds noindex/nofollow robots directives to shared previews, and shows a clear preview banner on private preview pages.

= Common use cases =

* Share a WordPress draft post with a client before publishing.
* Send a private preview link for a pending page, scheduled campaign, or unpublished landing page.
* Let stakeholders review content without giving them a WordPress login.
* Create separate labeled preview links for legal review, client review, editorial review, or team approval.
* Revoke access after feedback is complete or when a preview link should no longer work.
* Keep search engines from indexing private preview URLs with robots directives.

= Key features =

* Generate secure public preview links from the block editor, Classic Editor, post list table, or Preview dropdown.
* Share pretty URLs such as `https://example.com/preview/exampletoken`.
* Set a default expiration time for preview links from the PreviewShare settings screen.
* Override the expiration time per post or page.
* Create multiple labeled preview links for different reviewers.
* Revoke preview access when a link should stop working.
* View and manage preview link status, expiry, labels, and view counts from the settings screen.
* Choose which public post types support public preview sharing.
* Keep token lookup fast with optional object cache support.

= How PreviewShare protects preview links =

PreviewShare is built for private review, not public publishing. It uses random preview tokens, stores token hashes in WordPress post meta, supports automatic expiration, supports manual revocation, and sends `noindex`, `nofollow`, `noarchive`, `nosnippet`, and `noimageindex` directives for preview requests.

Anyone with a valid preview URL can open that preview, so links should still be shared only with intended reviewers.

== Installation ==

1. Upload the `previewshare` folder to the `/wp-content/plugins/` directory, or install the plugin ZIP from the WordPress admin.
2. Activate the plugin through the Plugins screen in WordPress.
3. Open Settings > PreviewShare to review the default expiration and caching options.
4. Open a supported post type in the block editor or Classic Editor.
5. Enable public preview from the PreviewShare panel and copy the generated preview URL.

== Screenshots ==

1. PreviewShare settings with preview link inventory, status, expiry, and view counts.
2. General defaults for the site-wide preview link expiry setting.
3. PreviewShare editor panel for enabling public preview and generating secure review links.

== Frequently Asked Questions ==

= What does PreviewShare do? =

PreviewShare creates public preview links for WordPress drafts and other enabled content statuses. Reviewers can open the preview URL without a WordPress account, while editors keep control over expiration, labels, view counts, and revocation.

= Is PreviewShare a public preview plugin for WordPress? =

Yes. PreviewShare is designed for public preview links, private draft previews, client review links, and editorial approval workflows in WordPress.

= How do I share a draft WordPress post with a client? =

Open the draft in the block editor or Classic Editor, enable public preview in the PreviewShare panel, and generate a preview link. You can copy the generated URL and send it to the client for review.

= Who can generate preview links? =

Users must be able to edit the content item before they can generate or revoke a preview link for it.

= Do visitors need a WordPress account to open a preview link? =

No. Anyone with a valid preview URL can view the linked content until the token expires or is revoked.

= Which content statuses can be shared? =

PreviewShare can generate preview links for published, draft, pending, scheduled, and private content in enabled post types. Published content redirects to its canonical permalink when a preview link is opened.

= Does PreviewShare work with the block editor and Classic Editor? =

Yes. PreviewShare supports the block editor, Classic Editor, post list table actions, and the Preview dropdown.

= Can I change how long preview links remain active? =

Yes. You can set a global default expiration in Settings > PreviewShare and override it for individual content items in the editor panel. A value of `0` means no automatic expiration.

= Can a preview link be revoked before it expires? =

Yes. Disable public preview from the editor panel, revoke links from the Classic Editor panel, or revoke individual links from the PreviewShare settings screen.

= Can I create different preview links for different reviewers? =

Yes. PreviewShare supports multiple labeled preview links, so you can create separate links for client review, legal approval, editorial review, or team feedback.

= Will preview links be indexed by search engines? =

PreviewShare adds robots directives to preview requests, including `noindex` and `nofollow`, and displays a preview banner on shared preview pages. Treat preview URLs as private links and share them only with intended reviewers.

= Where are preview tokens stored? =

PreviewShare stores token hashes and token metadata in `wp_postmeta`. Raw tokens are used in preview URLs but are not stored as plain text.

= What happens when a token expires? =

Expired tokens stop resolving to content. The editor panel will show the expired state and re-enabling preview sharing generates a fresh token.

= Does PreviewShare expose private content publicly? =

PreviewShare only exposes a specific content item to visitors who have a valid preview URL. Treat preview URLs like private sharing links and send them only to intended reviewers.

= Does the plugin use custom database tables? =

No. PreviewShare stores its token index and token metadata in WordPress post meta.

== Development ==

The public source repository is available at https://github.com/mehul0810/previewshare.

Production ZIP files are built from the source repository with:

`npm install`
`npm run plugin-zip`

The release artifact includes compiled assets, Composer autoload files, `composer.json`, plugin PHP, languages, readme, and license files. Development files such as `node_modules`, source assets, CI configuration, tests, and build tooling are excluded from production ZIP files.

== Changelog ==

= 1.0.0 =
* Initial release.
* Added secure preview links for enabled public post types.
* Added support for configurable public post types, multiple labeled links, view counts, noindex/nofollow previews, Classic Editor entry points, post-list actions, and Preview dropdown generation.
* Added global settings for default expiration, logging, and object cache lookup.
* Added post meta based token storage with hashed token lookup.
