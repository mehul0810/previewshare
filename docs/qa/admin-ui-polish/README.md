# PreviewShare admin UI verification

Candidate based on current `main` `bb6f22dd0f330ce362be602b100c5c9b6630dc36`, refreshed from GitHub on 2026-10-08. The historical checkout's uncommitted Overview and verified plugin-card work is preserved in this single isolated branch; that checkout was not edited.

The five existing tabs remain. No Advanced tab, version bump, release, tag, migration, or REST timestamp/label contract change is included.

## Requested behavior

| Change | Result |
| --- | --- |
| Header links | Documentation and Support use preceding document/help icons; no trailing external icons. |
| More plugins | Learn more uses a trailing right arrow inheriting text color. |
| Changelog | Separate version cards, short summaries, clear change lists, and an installed-version badge. |
| Content types | Bounded workspace, compact grouped rows, inline metadata, 44px toggle targets, compact save actions. |
| Preview links | Compact desktop table and responsive cards; consistent cell alignment, wrapping, and actions. |
| Dates | Shared WordPress formatter uses Settings → General formats, site timezone with historical DST offsets, and site calendar translations. Admin and editor history use it; automatic labels are formatted only for display. |
| Item subtitles | `#<ID>` only in native DataViews and legacy listing. |
| Header identity | Logo, title, and version pill share vertical centering; inherited heading padding removed. |

## Runtime evidence

Disposable WordPress 7.1.2 / PHP 8.1.34 / SQLite fixture, separate database and headless browser. Candidate loaded from the extracted production ZIP. Only artificial QA content and review responses were used; shared Studio content and settings were not modified.

Final asset version: `39c1d43688152f1c5bab`.

ZIP SHA-256: `3e444d1d1771a63f058a1669508379896bab062a447bb385b2dde2125bb95c23`.

- All five tabs rendered at 1440, 1024, 390, and 320 CSS pixels. Document scroll width equalled viewport width in all 20 cases: [measurements](final-width-proof.txt).
- General's actual custom date/time controls saved `d/m/Y` and `H:i O`; the values persisted and appeared in inventory and editor history after reload.
- Browser timezone `Asia/Calcutta`, site timezone `America/New_York`: inventory expiry displayed `09/10/2026 09:38 -0400`; editor response displayed `08/10/2026 09:46 -0400`.
- Native French site translation pack with English admin profile: `9 octobre 2026 09:38 -0400`, while core date settings retained `en_US`. [Locale receipt](locale-proof.txt), [rendered locale case](site-locale-fr-user-en.png).
- A fresh source-blind validator separately checked packaged UI, keyboard focus, target sizes, contrast, persistence, and content stress. Its [final source-blind receipt](independent/report.md) is included separately. A separate owner measurement covers [affected text contrast and actual toggle bounds](contrast-target-proof.txt).

## Before / after

| Screen | Baseline | Final desktop | Final mobile |
| --- | --- | --- | --- |
| Preview links | [1440](baseline-Preview-links-1440.png) | [1440](final-Preview-links-1440.png) | [390](final-Preview-links-390.png) |
| Content types | [390](baseline-Content-types-390.png) | [1440](final-Content-types-1440.png) | [390](final-Content-types-390.png) |
| Changelog | [1440](baseline-Changelog-1440.png) | [1440](final-Changelog-1440.png) | [390](final-Changelog-390.png) |
| More plugins | Captured locally at all baseline widths | Captured locally at all final widths | [390](final-More-plugins-390.png) |

## Repository gates

- JavaScript: 42 tests / 6 suites passed with process `TZ=Asia/Tokyo`; includes PHP custom format/literal escaping, spring DST gap, autumn repeated hour, translated months, preservation of core date settings, automatic/custom labels, and ID subtitle checks.
- PHP: 105 tests / 473 assertions passed on PHP 8.1.
- JS lint, CSS lint, changed-PHP WPCS, PHPStan, POT parsing, and `git diff --check`: passed.
- Production build: passed with the existing three webpack bundle-size warnings.
- Production dependency ZIP build, release ZIP validation, and WordPress.org asset validation: passed. QA evidence and browser artifacts are excluded from the ZIP.

The Docker-owned `npm run test:e2e` fixture was not run because Docker is unavailable. Real admin/editor interactions and the packaged plugin were verified in the separate disposable WordPress fixture. The declared WordPress 5.8 minimum is covered by existing asset/dependency gates; this run's rendered proof is WordPress 7.1.2. Browser-native zoom is not exposed by the headless CLI; narrow-width reflow and separate enlarged-text stress are recorded without calling them native browser zoom.
