# Contributing to PreviewShare

Thanks for helping improve PreviewShare. Keep changes focused and preserve the plugin's privacy, authorization, and preview-link behavior.

## Set up

Use PHP 8.0 or later, Composer 2, Node.js 20.19 or later, and npm. Docker is required for the repository-owned WordPress runtime and browser fixtures.

```sh
composer install
npm ci
```

Create a focused branch from the current project base, for example `fix/reviewer-retry` or `docs/setup-guide`. Do not include unrelated generated files or changes from another checkout.

## Make and validate a change

Follow existing code and UI patterns. For user-facing changes, consider keyboard operation, translated text, narrow editor panels, loading and failure states, and the active theme's role in frontend rendering. Avoid committing raw preview tokens, reviewer information, site URLs with credentials, or other private data.

Run checks that cover the changed area. Common checks are:

```sh
composer test
composer lint
composer check-cs
composer validate --no-check-publish --no-interaction
vendor/bin/phpstan analyse -c phpstan.neon
npm run lint:js
npm run lint:css
npm run test:js -- --runInBand
npm run build
```

For browser smoke tests, use only `npm run test:e2e`, which provisions the repository-owned Docker `wp-env` fixture and protects against caller-supplied site targets. Do not run its lower-level Playwright command directly. The Abilities API runtime runner requires an explicit official version, such as `PREVIEWSHARE_WP_VERSION=6.9.7 npm run test:abilities:runtime`.

If creating or validating a plugin ZIP, use `npm run plugin-zip` and `npm run validate:zip`. The ZIP command installs Composer dependencies with `--no-dev`; run `composer install` afterward to restore development tools.

## Open a pull request

Use the repository title convention `[component] Short summary of change`. Describe the problem, resulting behavior, scope, and meaningful validation. Link related issues when applicable; do not claim a local check is hosted CI or production verification. Keep generated build output and package artifacts out of the pull request unless the repository explicitly tracks them.

## Security reports

Do not publish vulnerability details, working preview links, raw tokens, reviewer personal data, or credentials in issues or pull requests. Use [GitHub private vulnerability reporting](https://github.com/mehul0810/previewshare/security/advisories/new). For ordinary bugs and requests, use [GitHub Issues](https://github.com/mehul0810/previewshare/issues).

## Project docs

Use [AGENTS.md](AGENTS.md) for architecture, development commands, and repository boundaries; [DESIGN.md](DESIGN.md) for product UI and privacy expectations; and [docs/compatibility-matrix.md](docs/compatibility-matrix.md) for preview rendering coverage. `README.md` describes the source repository, while `readme.txt` is the WordPress.org listing and release metadata.
