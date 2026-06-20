# PreviewShare Design Contract

PreviewShare helps editors share unpublished WordPress content through secure, expiring review links. Product decisions should protect that trust: the admin UI must feel native to WordPress, make link state obvious, and keep reviewers out of publishing or account-management workflows.

## Product Principles

- Keep the primary job visible: generate, copy, label, monitor, expire, or revoke a preview link for a specific content item.
- Prefer WordPress admin patterns, notices, tables, form controls, and editor panels over custom app conventions.
- Make security and reversibility clear without alarmist copy. Editors should understand who can open a link, when it expires, and how to revoke it.
- Keep controls lightweight and theme-agnostic. PreviewShare should not impose frontend design beyond the preview banner and safe preview behavior.
- Treat settings, editor entry points, list-table actions, and preview dropdown actions as one workflow, not separate products.

## Admin UI Surfaces

- Settings pages should prioritize defaults, enabled post types, cache/logging options, and link inventory. Advanced controls belong below the main workflow or behind clear grouping.
- Editor panels should be compact and action-oriented: current status, label or expiry controls when available, copy action, revoke/regenerate action, and concise helper text.
- Post-list and Preview dropdown entry points should be shortcuts into the same state model. They must not expose capabilities unavailable in the editor panel.
- Link inventory rows should make status, label, post title, expiry, view count, and revoke action scannable without requiring users to inspect raw token data.

## Preview Link Lifecycle

- **Generate:** Explain that the URL gives access to the selected unpublished item for anyone who has the link.
- **Copy:** Confirm the copied URL with a short success notice. Do not expose token hashes or internal storage details.
- **Label:** Labels should identify reviewer purpose, such as client review, legal approval, editorial review, or team feedback.
- **Expire:** Show active, scheduled-to-expire, expired, and no-expiration states distinctly. A value of `0` means no automatic expiration and should be described carefully.
- **Revoke:** Use clear consequence copy. Revoked links should no longer resolve, and the UI should make recovery path obvious by generating a fresh link.
- **View status:** Prefer human-readable timestamps and counts. Avoid ambiguous labels such as "valid" without explaining active, expired, or revoked state.
- **Recover:** Invalid, expired, or revoked preview requests should fail safely with useful next steps, not private content, stack traces, or raw provider data.

## State Guidance

- **Empty:** Say what the area tracks and offer the next safe action, such as enabling preview sharing on a supported post type.
- **Loading:** Preserve layout stability and use WordPress-native busy or disabled states. Do not imply a link was created until the request succeeds.
- **Success:** Confirm the result and next step, such as "Preview link copied" or "Preview access revoked".
- **Warning:** Use warnings for security-relevant conditions such as no expiration, public possession of a link, unsupported post type, or stale copied URLs.
- **Error:** State what failed, whether content remains protected, and what the editor can do next. Never show raw tokens, filesystem paths, stack traces, secrets, or PII.

## Accessibility

- Every action must be reachable by keyboard with visible focus and predictable tab order.
- Do not rely on color alone for status. Pair status colors with text, icons, or screen-reader text.
- Notices and async updates should be announced where appropriate and should not steal focus unless user recovery requires it.
- Controls need accessible names that describe the object being changed, especially copy, revoke, regenerate, and per-link actions.
- Maintain WordPress admin contrast expectations and support reduced motion for any animation or transition.
- Keep strings translatable and avoid layout-dependent copy that breaks with longer translations or RTL languages.

## Responsive Behavior

- Editor panels must remain usable in narrow sidebars and mobile admin views. Actions can stack, but the primary action should stay visible.
- Settings tables should degrade gracefully on narrow screens by preserving status, title, expiry, and action access.
- Long post titles, labels, URLs, and timestamps must wrap or truncate intentionally without hiding critical state.
- Touch targets should remain large enough for mobile admin use, especially copy and revoke controls.

## WordPress.org And Website Assets

- Screenshots should show real PreviewShare workflows: settings defaults, link inventory, editor link generation, and reviewer-safe preview messaging.
- Banners, icons, documentation visuals, and website copy should reinforce secure preview sharing for draft, pending, scheduled, and client-review workflows.
- WordPress.org metadata must stay concise and generic. Follow the release guidance to keep `readme.txt` tags high-intent and avoid keyword stuffing or competitor/product-name tags.
- Public-facing copy should match the plugin promise in `README.md` and `readme.txt`: secure public preview links without publishing content or creating reviewer accounts.
- Do not publish or replace WordPress.org assets from design-contract work unless a separate release or owner-approved asset task explicitly calls for it.

## Copy And Tone

- Use calm, direct, editor-focused language.
- Prefer verbs tied to the workflow: generate, copy, share, label, expire, revoke, regenerate, review.
- Be explicit about access: anyone with a valid preview URL can open the preview.
- Keep success messages short and errors recoverable.
- Avoid fear-based language, jokes, sales copy, and implementation jargon in user-facing UI.

## Non-goals

- This contract does not redesign PreviewShare.
- It does not introduce new settings, preview behavior, APIs, database changes, or source assets.
- It does not define a broad brand system beyond PreviewShare product UI and support assets.
- It does not authorize WordPress.org publishing, release tagging, version changes, or website deployment.
- It does not replace issue-specific acceptance criteria, release notes, or security review for behavior changes.
