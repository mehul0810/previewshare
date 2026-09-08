# Dependency Audit Notes

## npm dev-tooling alerts

Last reviewed for PR #60 on 2026-09-08.

PreviewShare does not ship npm packages in the production plugin artifact. The release ZIP is built from compiled assets and excludes `node_modules`, package manifests, and build tooling. Production npm audit remains clean with:

```bash
npm run audit:prod
```

PR #60 updates six direct WordPress development dependencies. A clean install with Node.js 20.19.0 produced the following full-audit comparison:

| Lockfile | Low | Moderate | High | Critical | Total |
| --- | ---: | ---: | ---: | ---: | ---: |
| PR base `fa8c5d32097dd317e4d1443eff2b23e5b436a56f` | 1 | 9 | 19 | 1 | 30 |
| PR #60 head `eb4b2693a117e7be45ac721de93e3e1bcb9ae9fd` | 1 | 25 | 18 | 1 | 45 |

This is a full-audit regression of 15 findings: 16 additional moderate findings and one fewer high finding. The full audit covers development tooling as well as the shipped product and is recorded here for review; it is not a release gate in place of the production audit. The production audit remains the CI gate and passed at the PR head with zero vulnerabilities:

```bash
npm run audit:prod
# found 0 vulnerabilities
```

The existing audit policy remains unchanged: do not use `npm audit fix --force` or make a major toolchain upgrade solely to suppress development-only findings. Any future dependency update must run and report both the full audit and `npm run audit:prod`.

Earlier development dependency work reduced findings where npm provided a safe package path:

- `@wordpress/scripts` updated to `32.4.1`.
- `@wordpress/components` updated to `35.0.1`.
- Unused direct `@wordpress/edit-post` development dependency removed.
- npm `overrides` pin patched transitive dev-tooling packages for `http-proxy-middleware`, `js-yaml`, `markdown-it`, `minimatch`, `serialize-javascript`, `uuid`, and `webpack-dev-server`.

Current full-audit findings are confined to the development graph. They must remain visible in audit documentation and be reassessed when the upstream WordPress package graph provides a compatible remediation.
