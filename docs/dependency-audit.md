# Dependency Audit Notes

## Historical npm audit snapshot

This section records the review of PR #60 on 2026-09-08. The counts describe the exact lockfile revisions below, not the current dependency graph or present security status.

PR #60 updated six direct WordPress development dependencies. A clean install with Node.js 20.19.0 produced this full-audit comparison:

| Lockfile | Low | Moderate | High | Critical | Total |
| --- | ---: | ---: | ---: | ---: | ---: |
| PR base `fa8c5d32097dd317e4d1443eff2b23e5b436a56f` | 1 | 9 | 19 | 1 | 30 |
| PR #60 head `eb4b2693a117e7be45ac721de93e3e1bcb9ae9fd` | 1 | 25 | 18 | 1 | 45 |

The recorded total increased by 15: 16 additional moderate findings and one fewer high finding. At that PR head, `npm run audit:prod` reported zero vulnerabilities. Neither result establishes the risk of a later lockfile or build.

Earlier dependency work in that review included `@wordpress/scripts` 32.4.1, `@wordpress/components` 35.0.1, removal of the unused direct `@wordpress/edit-post` dependency, and npm overrides for compatible transitive fixes. These are historical observations, not current version recommendations.

## Audit scope and policy

The production audit command is:

```sh
npm run audit:prod
```

It runs `npm audit --omit=dev --audit-level=moderate`. The release ZIP excludes `node_modules` and npm manifests, but can include compiled code from packages declared as development dependencies. Omitting development dependencies from an audit therefore does not establish that the emitted browser assets are free of advisories. Assess build-time tooling and code included in shipped assets separately.

The existing policy remains unchanged: `npm run audit:prod` is the CI audit gate. Any dependency update must run and report both the full audit and `npm run audit:prod` against its exact lockfile. Do not use `npm audit fix --force` or make a major toolchain upgrade solely to suppress audit findings. Evaluate compatible fixes and their effect on the built artifact.
