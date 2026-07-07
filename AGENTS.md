# AGENTS.md for PreviewShare

## Project Overview

PreviewShare is a WordPress plugin that provides **secure, expiring preview links** for draft, pending, and scheduled WordPress content. It focuses on safety, control, and predictability for editorial workflows. It solves known gaps such as lack of expiration, revocation, SEO safety, and per-post preview visibility.

PreviewShare includes:
- Secure, unguessable preview links
- Expiry and custom expiration dates
- Link revocation and regeneration
- Support for posts, pages, and Custom Post Types
- SEO-safe noindex/nofollow
- Read-only preview behavior
- Lightweight and theme-agnostic functionality

Include this file in the project’s root so AI agents can understand the plugin’s structure, workflow, tests, build commands (if any), coding style, and release expectations.

---

## Development Environment

- WordPress environment (local or remote) with PHP >= 7.4
- WP-CLI available for WordPress commands
- Git for version control
- Unix-like shell recommended (Linux or macOS)

---

## Setup Instructions

1. Clone the repository to your development directory.
2. Run `composer install` if you are tracking dependencies via Composer.
3. Set up a local WordPress install (using tools like LocalWP, Docker, or similar).
4. Link the plugin into `wp-content/plugins/previewshare`.
5. Enable the plugin from WordPress admin.
6. Ensure dev tools (like a linter or test suite if added later) are configured before committing.

---

## Coding Conventions

- Follow WordPress PHP coding standards:
  - Indentation: 4 Tab Size
  - Function names: `snake_case`
  - Use proper escaping functions (`esc_html`, `esc_attr`, etc.)
  - Sanitization on any user input
- Documentation comments for public classes and functions
- Keep logic modular and testable

---

## Testing Instructions

*(Define tests here once they exist)*  
If the project adds unit tests:
- Use the WordPress PHPUnit test framework
- Run tests locally before submitting pull requests
- Use `vendor/bin/phpunit` or WP-CLI test commands

---

## Build Commands

Since this is a PHP/WordPress plugin:
- No mandatory build toolchain for v1.0.0
- If you add assets (CSS/JS), specify build tools (npm/yarn) and commands (e.g., `npm run build`)
- List any build steps clearly once implemented

---

## Quality Gates

Before finalizing any patch:
- Ensure coding standards pass linting (if configured)
- Unit tests should be green
- No unhandled PHP notices, warnings, or errors
- No introduced security vulnerabilities
- When work targets `develop` or `release/<milestone>`, verify whether GitHub Actions covers that branch. If hosted CI does not run there yet, run the cheapest reliable local validation for the touched surface and call out the missing hosted coverage explicitly in the PR or check-in.

---

## Release and Versioning

- Follow semantic versioning (MAJOR.MINOR.PATCH)
- v1.0.0 is the initial release
- Tag releases in Git with their version (e.g., `v1.0.0`)
- Keep CHANGELOG updated with meaningful changes
- For WordPress.org metadata work, keep `readme.txt` to a maximum of 5 high-intent generic tags even though the directory allows more.

---

## Pull Request Guidelines

- Title should follow: `[component] Short summary of change`
- For milestone work, use the active `release/<milestone>` branch as the PR base.
- Include a concise description of what changed and why
- Reference relevant issues or tickets
- Avoid unrelated changes in the same PR

## Discovery Capture

- When agents find behavior that is not working as expected, code that needs improvement, weak or missing comments around non-obvious logic, maintainability gaps, missing tests, repeated validation/tooling friction, or reusable proof/runtime lessons, do not leave the finding only in chat.
- Duplicate-screen open and recently closed issues and PRs before adding new tracking.
- If the finding is inside the current issue scope, fix it in the active scoped PR. If it is outside scope, create a focused GitHub issue or product-doc task instead of broadening the active PR.
- Treat product documentation as living contracts. Update or create focused tasks for `AGENTS.md`, `DESIGN.md`, `TESTING.md`, `RELEASE.md`, architecture docs, README, and docs when discoveries change future worker behavior.
- Issue bodies for these findings should include the observed symptom, why it matters, suspected source, affected files or surfaces, acceptance criteria, validation or proof expectation, risk, and whether docs need updating.

## Release Branch Strategy

- `develop` is the ongoing integration branch.
- `main` is the production branch.
- Create `release/<milestone>` branches from `develop` before milestone work starts.
- Open PRs for milestone-tied work against the matching `release/<milestone>` branch.
- After each production release on `main`, sync `develop` from `main`.

---

## Security & Boundaries

- Do not expose user credentials or site environment variables
- Ensure preview tokens remain secure and unguessable
- Avoid injecting preview URLs in publicly indexed content
- Agents should not modify production environment variables or secrets

---

## Summary Checklist for AI Agents

When generating or modifying code in this project:
- Understand PreviewShare’s core use cases (secure previews, expiry, revocation)
- Respect WordPress coding conventions
- Use existing API functions and WordPress hooks responsibly
- Validate all input/output sanitation
- Maintain minimal performance overhead
- Keep WordPress.org-facing metadata concise, especially the 5-tag `readme.txt` cap.
