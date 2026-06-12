=== PreviewShare ===
Contributors: mehul0810
Tags: preview, drafts, content, workflow, editor
Requires at least: 5.8
Tested up to: 7.0
Requires PHP: 8.0
Stable tag: 1.0.0
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Share secure, time-limited public preview links for unpublished WordPress content.

== Description ==

PreviewShare lets editors share private preview links for WordPress content without publishing it or creating temporary user accounts.

Use it when a client, reviewer, teammate, or stakeholder needs to view a draft, pending review item, scheduled post, private post, or published post through a controlled preview URL.

Preview links use random tokens, token hashes are stored in post meta, and access can expire automatically based on the configured time-to-live. Shared previews also send noindex/nofollow robots directives and display a clear preview banner.

= Key features =

* Generate secure preview links from the block editor, Classic Editor, post list table, or Preview dropdown.
* Share pretty URLs such as `https://example.com/preview/exampletoken`.
* Set a default expiration time from the PreviewShare settings screen.
* Override the expiration time per content item.
* Create multiple labeled links for different reviewers.
* Revoke preview access when a link should stop working.
* View and manage generated preview links, status, expiry, and view counts from the settings screen.
* Choose which public post types support preview sharing.
* Keep token lookup fast with optional object cache support.

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

= Who can generate preview links? =

Users must be able to edit the content item before they can generate or revoke a preview link for it.

= Do visitors need a WordPress account to open a preview link? =

No. Anyone with a valid preview URL can view the linked content until the token expires or is revoked.

= Which content statuses can be shared? =

PreviewShare can generate preview links for published, draft, pending, scheduled, and private content in enabled post types. Published content redirects to its canonical permalink when a preview link is opened.

= Can I change how long preview links remain active? =

Yes. You can set a global default expiration in Settings > PreviewShare and override it for individual content items in the editor panel. A value of `0` means no automatic expiration.

= Can a preview link be revoked before it expires? =

Yes. Disable public preview from the editor panel, revoke links from the Classic Editor panel, or revoke individual links from the PreviewShare settings screen.

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
