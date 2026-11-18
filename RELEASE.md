# Release Workflow

## Branch model
- `develop` is the ongoing integration branch.
- `main` is the production branch.
- Release stabilization branches use `release/<version>` (for example, `release/1.1.0`).
- The production release source is the approved commit on `main`; a release branch or candidate commit alone is not release proof.

## Branching rules for work
- Create a version-scoped `release/<version>` branch from `develop` before implementation for that milestone, and keep it limited to that version.
- Open pull requests for milestone work against the matching `release/<version>` branch.
- Promote the approved release through the mainline-first process. Merge the release to `main`, then create the production tag and package from the approved `main` commit and publish through the authorized release workflow.

## WordPress.org metadata hygiene
- Keep `readme.txt` to a maximum of 5 high-intent generic tags.
- Avoid keyword stuffing or competitor/product-name tags in WordPress.org metadata.

## Post-release process
- After the production release has completed from `main`, merge the exact released `main` commit into `develop` with a normal merge. Preserve both branch histories and resolve documentation conflicts against current `main` guidance while retaining valid `develop`-only workflow instructions.
- Do not treat tag creation or a running release workflow as proof that publication completed; verify the release workflow before describing the release as published.
