# PreviewShare

PreviewShare creates revocable links for reviewing unpublished WordPress content without publishing it or requiring reviewers to create accounts. Anyone holding a valid link can open it, so share it only with the intended reviewers.

This README describes the 1.1.0 source revision. See [GitHub Releases](https://github.com/mehul0810/previewshare/releases) for published versions and installable downloads.

For branch strategy and the post-release synchronization process, see [RELEASE.md](RELEASE.md).

## What it does

- Create links for draft, pending, scheduled, and other supported unpublished content. Supported post types are managed in PreviewShare settings.
- Set an expiry, label links, revoke access, and generate a replacement link.
- Review links in the admin inventory and manage them from supported editor actions.
- Optionally collect reviewer approvals, change requests, or comments on a per-link basis. Reviewers do not need an account.
- Keep feedback private to authorized editors. Approval is marked stale when saved content changes.

Preview output is rendered through the active theme and WordPress hooks. Themes, page builders, plugins, caches, and proxies may affect the result. See the [preview compatibility matrix](docs/compatibility-matrix.md). Preview links do not replace authentication or permissions on the ordinary public permalink.

## Requirements

- WordPress 5.8 or later
- PHP 8.0 or later
- For source development: Composer 2, Node.js 20.19 or later, npm; Docker for the owned `wp-env` browser/runtime fixtures

## Installation

For a published installation, download a ZIP from [Releases](https://github.com/mehul0810/previewshare/releases), then in WordPress go to **Plugins → Add New Plugin → Upload Plugin**, select the ZIP, install, and activate it. The source checkout does not contain generated `assets/dist` or Composer `vendor` files and is not itself an installable package until built.

After activation, open PreviewShare in the WordPress admin. Review the enabled content types and default expiry, then generate a link from a supported post's PreviewShare controls. Send the URL to the reviewer. Revoking the link stops access through that URL; generate and share a replacement when needed.

Reviewer feedback is opt-in for each link. Site owners can choose whether name and email are optional or required; these fields are self-reported and do not authenticate the reviewer.

Daily WordPress scheduled cleanup deletes responses and any provided identity older than 90 days while the plugin is active. Cleanup depends on scheduled tasks running. Deactivation or uninstall stops cleanup and preserves existing records; reactivation resumes cleanup.

While PreviewShare is active, WordPress privacy tools can export or erase responses using the email address supplied by the reviewer. They cannot locate responses submitted without an email address.

## Maintainer Documentation

- [Design contract](DESIGN.md)
- [Preview compatibility matrix](docs/compatibility-matrix.md)
- [Dependency audit notes](docs/dependency-audit.md)
- [Contributor guide](CONTRIBUTING.md)

## End-to-end smoke test

Run the owned local WordPress fixture and Playwright smoke suite with:

```sh
npm ci
composer install
npm run test:e2e
```

The wrapper builds the plugin assets, starts the repository's Docker `wp-env` fixture on `http://localhost:8889` (test site `8890`), activates the plugin, and runs Playwright against that fixture. It uses the fixture's WP-CLI and removes posts created by its run. Do not point it at a Studio site, shared install, or production site, and do not invoke the lower-level Playwright command directly.

The hosted browser end-to-end job runs for pull requests targeting `release/**` and can be enabled through manual workflow dispatch. See [CI](.github/workflows/ci.yml) for its exact triggers. A local pass is not hosted CI or production proof.

## Development

Install locked dependencies:

```sh
composer install
npm ci
```

Useful checks:

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

PHP unit tests use PHPUnit, Brain Monkey, Mockery, and WordPress stubs; they do not require a WordPress installation. For the Abilities API runtime fixture, set an official version explicitly, for example `PREVIEWSHARE_WP_VERSION=6.9.7 npm run test:abilities:runtime`.

`npm run plugin-zip` builds production assets, installs Composer dependencies without development packages, and creates the release ZIP. Run `npm run validate:zip` to inspect it. Since packaging removes developer dependencies, run `composer install` again before continuing PHP development. Generated `assets/dist` and `vendor` directories are ignored in source control.

## Security and support

Report suspected vulnerabilities through [GitHub private vulnerability reporting](https://github.com/mehul0810/previewshare/security/advisories/new). Use [GitHub Issues](https://github.com/mehul0810/previewshare/issues) for ordinary bugs and feature requests. Do not include live preview URLs, tokens, personal data, or credentials in public reports.

## Contributors

See [CONTRIBUTORS.md](CONTRIBUTORS.md) for verified project credits and [GitHub's contributor graph](https://github.com/mehul0810/previewshare/graphs/contributors) for current repository activity.

## License

PreviewShare is licensed under [GPL-3.0-or-later](license.txt).
