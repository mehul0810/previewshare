# PreviewShare design contract

PreviewShare helps editors share unpublished WordPress content and collect optional reviewer feedback. The interface should make link access, expiry, response policy, and recovery clear while keeping review separate from publishing and account creation.

## Product Principles

Keep the primary task visible and use familiar WordPress controls, notices, editor panels, and navigation. Make link access and revocation understandable without alarmist copy. Treat settings, editor controls, and list-table shortcuts as parts of the same workflow.

## Admin UI Surfaces

The plugin page has five tabs: **Overview**, **Preview links**, **Content types**, **Changelog**, and **More plugins**. Preserve their distinct roles and WordPress admin navigation patterns. The link inventory is paginated; do not imply that one page is the entire inventory. It supports a compact presentation at narrower widths. Keep post title, label, link status, expiry, view count, reviewer state, and available actions identifiable in either layout.

The block editor uses its PreviewShare panel for per-post link and reviewer settings. The Classic Editor uses its metabox, and Classic Editor/list-table actions remain available where supported. Do not add a duplicate Block Editor panel or make one entry point suggest controls unavailable in the underlying workflow. The frontend preview banner and reviewer form are rendered with the site's theme content.

## Preview Link Lifecycle

- **Generate and copy:** Explain that anyone with the URL can view the selected unpublished item. Confirm successful generation or copying only after the operation succeeds. The Classic Editor's server-rendered response is a read-only HTML page with a URL for manual copying; do not promise clipboard automation there.
- **Label and expiry:** Keep labels tied to the review purpose. Distinguish active, expired, revoked, scheduled expiry, and no automatic expiry. Site dates, time, and locale govern date display.
- **Revoke and regenerate:** Explain that revocation removes access through that link and that a new link must be shared. Avoid leaking the token in status, logs, or inventory details beyond the usable URL presented to its owner.
- **Reviewer responses:** Responses are opt-in per link. Communicate whether identity fields are optional or required. Review history is private to authorized editors. An approval becomes stale when saved content changes. On uncertain submission failure, retain the original response and edits so the reviewer can retry or correct them.
- **Retention:** Daily WordPress scheduled cleanup deletes responses and provided identity older than 90 days while PreviewShare is active; timing depends on scheduled tasks running. Deactivation or uninstall stops scheduled cleanup and leaves records in the database; activating the plugin again resumes cleanup. WordPress privacy export and erasure tools locate responses by a supplied reviewer email address while the plugin is active; they cannot locate responses without an email address.

Names accept up to 120 UTF-16 code units and comments up to 5,000. Errors should identify a recoverable next action without exposing raw tokens, private data, database details, or stack traces. Invalid, expired, revoked, and unavailable links must not reveal unpublished content.

## State Guidance

- **Empty:** State what the inventory or response area contains and provide a relevant next action.
- **Loading/saving:** Preserve layout and distinguish pending work from saved state. Do not show success before the server confirms it.
- **Success:** Confirm the completed action and useful next step.
- **Error/conflict:** Say what failed, whether access or content remains protected, and how to retry or recover. Preserve user input when retry is safe.

## Accessibility

- **Keyboard:** All tabs and controls must work by keyboard with visible focus and sensible focus order. Tabs support arrow keys plus Home and End. Async notices should be announced without unexpectedly moving focus.
- **Locale and text direction:** Keep strings translatable, allow longer translated labels and RTL layouts, and format dates using WordPress site settings.
- **Names and status:** Give controls accessible names, including the affected object where needed. Do not rely on color alone; pair visual states with readable text or assistive text. Maintain contrast and respect reduced-motion preferences.

## Responsive Behavior

Keep sidebar controls usable in narrow editor panels. Inventory compact mode may reduce density, but must retain status and action access. Long titles, labels, timestamps, and URLs must wrap or truncate without obscuring critical state. Keep touch targets usable and verify text enlargement as well as viewport reflow.

## Preview rendering and limits

PreviewShare modifies the WordPress query for a valid preview link; the active theme and normal WordPress rendering hooks produce the page. A preview link is private to its holder, not a login or replacement for permissions on the public permalink. Tokenized responses set no-store/private cache directives, but caches and proxies must honor them. Do not claim universal behavior for themes, page builders, caching layers, or third-party rendering extensions. Use the [compatibility matrix](docs/compatibility-matrix.md) for the supported review boundary and site-specific smoke coverage.

## WordPress.org And Website Assets

Preserve the existing product icons and their recorded source provenance in `docs/qa/admin-ui-polish/preserved-icon-sources.json`. Do not replace or publish new WordPress.org artwork as part of UI design work. Any new asset requires a separately scoped review of source, license, and package inclusion.

Use real, current workflows in screenshots. Keep public copy consistent with `README.md` and `readme.txt`, and retain the five-tag limit for WordPress.org metadata.

## Copy And Tone

Use calm, direct language and workflow verbs: generate, copy, share, label, expire, revoke, regenerate, and review. Be clear that anyone holding an active link can open it. Keep errors actionable and avoid sales claims or implementation details in user-facing copy.

## Non-goals

This contract records current product behavior and quality expectations. It does not authorize a redesign, new feature, setting, API, data format, retention change, source asset, WordPress.org publication, release tag, or deployment. Preserve existing anchors and behavior when changing this document.
