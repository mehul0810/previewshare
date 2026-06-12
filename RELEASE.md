# Release Workflow

## Branch model
- `develop` is the ongoing integration branch.
- `main` is the production branch.
- Milestone work uses `release/<milestone>` branches.

## Branching rules for work
- Create a `release/<milestone>` branch from `develop` before implementation for a milestone.
- Open PRs for milestone work against the matching `release/<milestone>` branch.
- Keep `release/<milestone>` branches scoped to that milestone.

## Post-release process
- After each production release is merged/tagged on `main`, sync `develop` from `main` so future development starts from released state.

