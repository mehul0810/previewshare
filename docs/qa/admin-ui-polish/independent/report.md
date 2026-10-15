# Independent observable QA

Overall: satisfies exercised contract; native browser zoom blocked.
Contamination: none. No product source, diff, Git history, tests, or implementation review inspected. Only browser UI/DOM/runtime resource URLs and screenshots used.

Target: disposable WordPress 7.1.2, http://127.0.0.1:8896/wp-admin/options-general.php?page=previewshare_settings; headless session previewshare-independent; fixture qa administrator.
Final settings runtime identity: CSS/JS ver=39c1d43688152f1c5bab. Editor CSS/JS ver=3df182a4a9297127e551. Packaged ZIP SHA256 supplied by owner: 3e444d1d1771a63f058a1669508379896bab062a447bb385b2dde2125bb95c23 (not independently hashed).

| Clause | Result | Observable evidence |
|---|---|---|
| Five tabs, no Advanced | Pass | Overview, Preview links, Content types, Changelog, More plugins; final tab screenshots |
| Header preceding semantic icons, no trailing external icons | Pass | Rendered DOM has preceding document/help SVG; overview-1440.png and final-overview-1440.png |
| Header logo/title/version centered | Pass | Desktop screenshots; final enlarged title wraps without clipping |
| More plugins right arrow text color | Pass | Seven rendered arrows and links share rgb(37,99,235); final-more-plugins-1440.png |
| Changelog readable version hierarchy | Pass | final-changelog-1440.png / final-changelog-320.png |
| Compact accessible content types | Pass | Named Enable Post/Enable Page checkboxes; content-types screenshots; French site renders Article label |
| Compact aligned inventory and #ID subtitles | Pass | Three fixtures including long editorial title, subtitles #7/#6/#5; final-preview-links screenshots |
| No page overflow | Pass | All five tabs: scrollWidth=clientWidth at1440/1024/390/320 before final minor refinement; final nearby1440/320 all five equality |
| Persistence | Pass | Page true -> uncheck -> reload false -> check -> reload true; fixture restored; no save/auth regression |
| Keyboard focus | Pass | Final build Overview focused -> ArrowRight ->300ms: focused/selected Preview links; visible rgb(37,99,235) solid2px outline; Tab/Enter reachable |
| General formats + site TZ independent of browser TZ | Pass | Browser Asia/Calcutta. Formats d/m/Y and H:i O display09/10/2026 09:38 -0400; French site j F Y displays9 octobre2026 09:38 -0400 while admin UI English |
| Editor reviewer history | Pass | English custom format08/10/2026 09:46 -0400; final packaged French site8 octobre2026 09:46 -0400; final-editor-history-locale.png |
| 200% text stress | Pass | Baseline computed font sizes captured then doubled via ephemeral inline DOM font-size; final-text200-* at320; no page overflow, titles/dates wrap without overlap |
| Native browser200% zoom | Blocked | Headless CLI lacks native browser zoom control. CSS root zoom trial is explicitly not native zoom proof and is not counted |

Contrast/targets: arrow/text color parity measured; focus outline observed. No full contrast audit or numeric target-size audit performed, so no general WCAG certification claimed.
Runtime transition: owner package symlink switch briefly produced404 resource URLs/blank app; owner restarted fixture server and final reload recovered valid URLs. No remaining runtime blocker.
Synthetic initial font stress exposed clipping/crowding; final packaged rerun passes. CSS root zoom trial kept media-query viewport unchanged and is excluded from verdict.
Selected final screenshots accompany this exported report. The complete capture set remains in the local workspace at `output/playwright/independent`. No General settings were mutated by validator.
