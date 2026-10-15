# PreviewShare repository guidance

## Product and scope

PreviewShare creates revocable links for reviewing unpublished WordPress posts, pages, and enabled public post types. A link grants access to anyone holding its URL; it does not publish the content or authenticate the reviewer. Keep this repository guidance specific to this plugin and follow the current issue or PR acceptance criteria.

Verify release status from published tags and the [Releases page](https://github.com/mehul0810/previewshare/releases). A version in source or a candidate branch does not establish publication; do not describe candidate-only behavior as released.

## Architecture and invariants

- `previewshare.php` loads configuration and Composer autoloading; `src/Plugin.php` composes and registers plugin services.
- `src/Services/TokenService.php` creates and validates secret tokens. Keep raw tokens and usable preview URLs out of persistent plugin storage and logs; store hashed references.
- `src/Services/PostMetaStorage.php` stores link detail records. Those records are authoritative; aggregate compatibility metadata is a projection, not the sole inventory source.
- `src/Services/ReviewResponseService.php` owns private reviewer responses, retention, cleanup, and WordPress privacy exporter/eraser hooks. `src/Services/ReviewVersion.php` ties approval freshness to saved content.
- `src/REST/PreviewController.php` and `ReviewController.php` enforce link, capability, ownership, and response boundaries. `src/Abilities/PreviewAbilities.php` exposes optional WordPress Abilities API support through feature detection.
- `src/Admin/Actions.php` coordinates preview resolution, Classic Editor actions, and the frontend preview bar. `assets/src/js/admin` and `settings.js` provide editor/settings interfaces; `assets/src/js/frontend` provides the reviewer form.
- Unit tests are under `tests/Unit`; isolated WordPress runtime fixtures are under `tests/Runtime`; Playwright flows live in `tests/e2e`.

Preserve authorization checks, object ownership checks, nonces where applicable, generic unavailable responses, and private cache headers. Never expose reviewer identity or history on the public preview.

Reviewer responses are opt-in per link; name/email may be optional or required. Saved-content changes make an approval stale. Retry errors must preserve the entered response for correction or retry. Inputs are limited to 120 UTF-16 code units for names and 5,000 for comments.

Daily WordPress scheduled cleanup deletes responses and identity older than 90 days while the plugin is active; timing depends on scheduled tasks running. Deactivation or uninstall stops cleanup and preserves records; reactivation resumes cleanup. Do not change these data, retention, security, or public extension contracts without explicit product approval.

## Local setup and commands

Requirements: PHP 8.0+, Composer 2, Node.js 20.19+, npm, and Docker for WordPress `wp-env` runtime/browser fixtures. The plugin itself requires WordPress 5.8+; package headers are the compatibility source of truth.

Install exact locked development dependencies with `composer install` and `npm ci`. Do not borrow another checkout's `node_modules` or build release assets from an unverified dependency tree.

PHP checks:

```sh
composer test
composer lint
composer check-cs
composer validate --no-check-publish --no-interaction
vendor/bin/phpstan analyse -c phpstan.neon
```

JavaScript, styles, and production asset build:

```sh
npm run lint:js
npm run lint:css
npm run test:js -- --runInBand
npm run build
```

The PHP tests use PHPUnit 9.6+, Brain Monkey, Mockery, and WordPress stubs; they do not require an installed WordPress site. `npm run test:e2e` is the owned local end-to-end entry point. It builds assets, starts the repository's `.wp-env.json` Docker fixture (ports 8889/8890), and cleans up posts created by its run. Do not target Studio, shared, or production sites; do not call `test:e2e:playwright` directly as the fixture setup and safety checks are in the wrapper.

The Abilities API runtime check uses its own `tests/Runtime` fixture and requires an explicit official WordPress version, for example:

```sh
PREVIEWSHARE_WP_VERSION=6.9.7 npm run test:abilities:runtime
```

If working against a WordPress Studio site, check CLI availability first and use `studio wp`; never assume bare `wp` is connected to the intended site.

## Built artifacts and release ZIP

`assets/dist` and `vendor` are ignored generated directories. A source checkout is not an installable release ZIP until built. `npm run plugin-zip` runs the production JS build, replaces Composer dependencies with `--no-dev`, and creates the official ZIP. Restore development Composer tools with `composer install` afterward. Then use `npm run validate:zip` and, when WordPress.org assets are in scope, `npm run validate:wporg`. Never use arbitrary existing generated assets as release proof.

The ZIP deliberately omits repository-only guidance and tooling. Keep new root documentation excluded through `.distignore` and verify the generated package when packaging behavior changes.

## Quality and design

Follow the configured WordPress coding standards, escape output, sanitize and validate input, and document public functions and classes. Keep behavior modular and testable. PHP behavior changes require `composer test`; complete relevant syntax, coding-standard, and static-analysis checks. Do not finalize with unhandled plugin PHP errors or warnings.

Use the narrowest relevant checks, then inspect the changed behavior at its actual surface. UI changes should account for loading, empty, error, success, keyboard, locale, and narrow-layout states. Preview rendering depends on the site's active theme, cache, and plugins; don't promise universal compatibility. See [DESIGN.md](DESIGN.md) and [docs/compatibility-matrix.md](docs/compatibility-matrix.md).

For dependency changes, follow [docs/dependency-audit.md](docs/dependency-audit.md): report full and production audits and assess code included in browser assets, not only dependency labels.

## Documentation and collaboration

- `README.md` is source and contributor onboarding; `readme.txt` is the WordPress.org listing and release metadata. Keep versions and requirements aligned with `previewshare.php` and `package.json`.
- Release history belongs in `readme.txt` and the plugin settings release notes; translations may require regenerating the POT. Keep WordPress.org tags within the existing five-tag limit. Do not create a separate changelog unless asked.
- Use the repository PR title format `[component] Short summary of change`. Keep a PR focused and include relevant validation and evidence.
- Report vulnerabilities through [GitHub private vulnerability reporting](https://github.com/mehul0810/previewshare/security/advisories/new). Use [GitHub Issues](https://github.com/mehul0810/previewshare/issues) for ordinary bugs and requests.
- Do not change dependency policy, public APIs, data formats, retention, compatibility promises, WordPress.org metadata, or release controls as incidental documentation work.

## Release boundary

Keep the mainline-first release process. A 1.1.0 candidate branch or clean local checks do not mean the candidate is published. Protected-branch merges, tag creation, beta/production publication, and deployment require the applicable owner authorization. Do not trigger a release workflow or claim release status from source alone.
