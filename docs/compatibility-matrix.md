# Preview Compatibility Matrix

PreviewShare modifies the main WordPress query only for a valid tokenized
preview URL. The rendered result is still produced by the active theme and
normal WordPress hooks. This document defines the compatibility surface that
must be checked when changing preview behavior or preparing a release.

## Support Boundary

- Preview links support post types enabled in PreviewShare settings. On a fresh
  install, PreviewShare enables every public, viewable, non-attachment type
  registered when it initializes, including eligible public custom post types.
  A type registered later must be enabled in settings or added through the
  supported-post-types filter.
- A valid link can serve `draft`, `pending`, `future`, and `private` content.
  A published post redirects to its canonical public permalink.
- A preview URL is private to the holder of that URL. It is not a substitute
  for WordPress authentication or permissions on the normal public permalink.
- Tokenized preview responses set `DONOTCACHEPAGE` and a private, no-store
  cache policy. Caching plugins and proxies must honor those directives.

## Matrix

| Surface | Expected behavior | Automated evidence | Reproducible smoke |
| --- | --- | --- | --- |
| Public post and page | A valid token resolves the intended unpublished item without publishing it. | `AdminActionsTest::test_maybe_handle_preview_request_sets_main_query_for_valid_draft` | Generate a link for a draft post and a draft page; open each in an anonymous browser context. |
| Enabled public custom post type | A token resolves the enabled type through its normal single template. | Unit coverage verifies the supported-post-type gate; site smoke is required because templates are site-owned. | Register or enable one public custom post type, generate a link, and verify the expected single template renders. |
| Draft, pending, future, and private states | The main query includes previewable non-public states only for a valid token. | `AdminActionsTest::test_maybe_handle_preview_request_sets_main_query_for_valid_draft` | Generate one link per available status and verify the preview plus normal anonymous permalink boundary. |
| Published state | A valid preview URL redirects to the canonical published URL. | No focused published-redirect regression test exists yet. | Publish the content after generating a link and confirm the old preview URL redirects to the canonical permalink. |
| Invalid, expired, revoked, or disabled link | The request fails safely without showing private content or raw token data. | `AdminActionsTest` failure-path tests cover revoked, disabled, missing-post, and unsupported-status decisions. | Open an invalid URL, revoke one link, expire one link, and verify each returns the standard unavailable response. |
| Block editor and Classic Editor | Editors can create/revoke links through the applicable PreviewShare entry point without altering publish state. | JavaScript unit tests cover admin/settings utilities; PHP tests cover the REST permission and response contract. | Generate and revoke a link from the block editor and Classic Editor, checking browser console and notices. |
| Classic and block themes | The active theme renders its normal single template, with the PreviewShare banner added once. | PHP unit coverage verifies the query and preview-bar rendering guards. | Run the valid-link smoke in one classic and one block theme; verify title, featured image, media, dynamic blocks, and the banner. |
| Media, embeds, custom fields, and dynamic blocks | Theme/plugin-rendered content should use the ordinary WordPress render path. | No fixture can prove every third-party renderer. | Verify representative site content: featured image, embedded media, custom-field output, reusable or synced block, and any dynamic block used by the site. |
| Narrow viewport | The preview banner remains usable without hiding rendered content or controls. | CSS/JS lint and build checks validate shipped assets. | Run the valid and unavailable-link smoke at a narrow mobile viewport and inspect banner wrapping, focus, and touch targets. |
| Page caches and proxies | Tokenized responses are not stored or replayed after revocation/expiry. | `AdminActionsTest` verifies the no-store header and non-preview no-op. | With the site cache/proxy enabled, open a valid link, revoke it, then retry in a fresh anonymous context and verify the unavailable response. |

## Extension Points

PreviewShare intentionally keeps its public extension surface small:

- `previewshare_supported_post_types` filters the effective list of enabled
  post-type names. The second argument contains available public post-type
  labels keyed by name. Use it to add a site-specific eligible type without
  patching plugin internals.
- `previewshare_preview_request_failed` fires when a preview cannot be served.
  It receives a stable reason code and related post ID (or `0` when unknown).
  Raw tokens and hashes are intentionally omitted.
- `previewshare_log` fires only when PreviewShare diagnostic logging is enabled.
  Consumers receive an event name and context and must avoid recording raw
  token values or other sensitive request data.

These hooks are compatibility aids, not a guarantee that an unsupported post
type, theme, builder, cache, or proxy becomes supported automatically.

## Isolated Smoke Protocol

Use an isolated `wp-env`, Docker, WordPress Playground, or equivalent
non-production WordPress instance. Do not use a production site or upload a
candidate outside approved proof channels.

1. Install the packaged plugin, activate it, and enable the post types under
   test.
2. Create representative draft content for post, page, and one enabled custom
   post type. Include the site’s highest-risk media, embeds, custom fields, and
   dynamic blocks.
3. Generate a tokenized preview link in both editor paths that the site uses.
4. In an anonymous browser context, verify the valid preview renders the
   expected content without publishing it. Check the response carries the
   private/no-store cache policy.
5. Verify invalid, revoked, expired, disabled, and normal non-preview URLs do
   not expose unpublished content.
6. Repeat the valid and unavailable flows in a classic theme, a block theme,
   and a narrow viewport where those surfaces are in release scope.
7. Record the WordPress, PHP, theme, browser, cache/proxy, and fixture details
   with the release evidence. Any failure should name the matrix row and the
   smallest reproducible setup.

## Known Limitations

- PreviewShare cannot guarantee rendering correctness for every page builder,
  theme, cache, or third-party dynamic block. The site-level smoke protocol is
  the proof for those integrations.
- No browser harness is part of the merged release branch yet. Issue #8 tracks
  the Playwright fixture and must supply proof before relying on it for release
  readiness.
- The administrative global link inventory uses page/total pagination. Large
  inventories need a separately scoped cursor or data-access redesign.

## Release Readiness

For any release that changes preview generation, authorization, rendered
output, the editor UI, or cache behavior, rerun the relevant matrix rows and
attach the result to the release-ready brief. A green unit or package suite
does not replace isolated browser proof for user-visible preview workflows.
