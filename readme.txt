=== PreviewShare ===
Contributors: mehul0810
Tags: preview,anonymous,drafts,posts,public
Requires at least: 5.8
Tested up to: 6.9
Requires PHP: 8.0
Stable tag: 1.0.0
License: GPLv3 or later

Allows you securely share preview links for draft, pending, or scheduled content without publishing it publicly.

== Description ==

PreviewShare WordPress plugin will help you to create secure public preview links for posts, pages, or any custom post types.

Key features:

- Pretty preview URLs (example: `https://example.com/preview/<token>`)
- Per-post TTL (hours) override in the post editor
- Editor UI with a copy-ready preview URL control
- Admin settings for defaults, logging, and caching
- List of generated preview links for quick glance.

== Installation ==

1. Upload the `previewshare` folder to `/wp-content/plugins/`.
2. Activate the plugin through the 'Plugins' screen in WordPress.
3. Open a post in the editor and toggle 'Enable Preview Sharing' in the PreviewShare panel to generate a preview URL.

Developer notes:

- If you're modifying or building assets, run the build from the plugin directory:

```bash
npm install
npm run build
```

== Changelog ==

= 1.0.0 =
* Initial release — secure preview links, editor UI, admin settings, postmeta storage.

== Upgrade Notice ==

= 1.0.0 =
If upgrading from a version that used a custom DB table for tokens: this version uses postmeta and does not migrate existing tokens. Back up your database before upgrading and reach out for migration assistance if you need active tokens preserved.

== Frequently Asked Questions ==

= Where are tokens stored? =
Tokens are stored in `wp_postmeta` with meta keys `_previewshare_token_hash` and per-token details in `_previewshare_token:<hash>`.

= Can I change how long a preview link lasts? =
Yes — there's a global default in Settings and a per-post TTL (hours) override in the editor.
