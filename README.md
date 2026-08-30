# PreviewShare
PreviewShare lets you securely share preview links for draft, pending, or scheduled content without publishing it publicly.

## Maintainer Documentation

- [Design contract](DESIGN.md)
- [Preview compatibility matrix](docs/compatibility-matrix.md)

## End-to-end smoke test

Run the no-secret WordPress fixture and Playwright smoke test with:

```sh
npm ci
npm run test:e2e
```

Use this local command as the default e2e validation route; the hosted
Playwright e2e job is manual-only through the CI workflow dispatch
`run_e2e` input.

The command installs Composer dependencies if needed, builds the admin assets,
starts `wp-env`, points Playwright at `http://localhost:8889`, and sets
`PREVIEWSHARE_E2E_WP_CLI` to `wp-env run cli wp` so the smoke test can mutate
the generated token into an expired state and assert the public 410 boundary.
