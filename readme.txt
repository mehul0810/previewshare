=== PreviewShare ===
Contributors: mehul0810
Tags: preview, drafts, content, workflow, editor
Requires at least: 5.8
Tested up to: 6.9
Requires PHP: 8.0
Stable tag: 1.0.0
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Share secure public preview links for unpublished WordPress posts and pages.

== Description ==

PreviewShare lets editors share private preview links for WordPress posts and pages without publishing the content or creating temporary user accounts.

Use it when a client, reviewer, teammate, or stakeholder needs to view a draft, pending review item, scheduled post, or published post through a controlled preview URL.

Preview links use random tokens, token hashes are stored in post meta, and access can expire automatically based on the configured time-to-live.

= Key features =

* Generate secure preview links from the block editor.
* Share pretty URLs such as `https://example.com/preview/exampletoken`.
* Set a default expiration time from the PreviewShare settings screen.
* Override the expiration time per post or page.
* Revoke preview access when a link should stop working.
* View and manage generated preview links from the settings screen.
* Keep token lookup fast with optional object cache support.

== Installation ==

1. Upload the `previewshare` folder to the `/wp-content/plugins/` directory, or install the plugin ZIP from the WordPress admin.
2. Activate the plugin through the Plugins screen in WordPress.
3. Open Settings > PreviewShare to review the default expiration and caching options.
4. Open a post or page in the block editor.
5. Enable public preview from the PreviewShare panel and copy the generated preview URL.

== Frequently Asked Questions ==

= Who can generate preview links? =

Users must be able to edit the post or page before they can generate or revoke a preview link for it.

= Do visitors need a WordPress account to open a preview link? =

No. Anyone with a valid preview URL can view the linked content until the token expires or is revoked.

= Which content statuses can be shared? =

PreviewShare can generate preview links for published, draft, pending, and scheduled posts or pages.

= Can I change how long preview links remain active? =

Yes. You can set a global default expiration in Settings > PreviewShare and override it for individual posts or pages in the editor panel. A value of `0` means no automatic expiration.

= Can a preview link be revoked before it expires? =

Yes. Disable public preview from the editor panel or revoke a token from the PreviewShare settings screen.

= Where are preview tokens stored? =

PreviewShare stores token hashes and token metadata in `wp_postmeta`. Raw tokens are used in preview URLs but are not stored as plain text.

= What happens when a token expires? =

Expired tokens stop resolving to content. The editor panel will show the expired state and re-enabling preview sharing generates a fresh token.

= Does PreviewShare expose private content publicly? =

PreviewShare only exposes a specific post or page to visitors who have a valid preview URL. Treat preview URLs like private sharing links and send them only to intended reviewers.

= Does the plugin use custom database tables? =

No. PreviewShare stores its token index and token metadata in WordPress post meta.

== Changelog ==

= 1.0.0 =
* Initial release.
* Added secure preview links for posts and pages.
* Added block editor controls for enabling, copying, expiring, and revoking preview links.
* Added global settings for default expiration, logging, and object cache lookup.
* Added post meta based token storage with hashed token lookup.

== Upgrade Notice ==

= 1.0.0 =
Initial release.
