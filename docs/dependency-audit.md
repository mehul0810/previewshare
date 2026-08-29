# Dependency Audit Notes

## npm dev-tooling alerts

Last reviewed for the `1.0.1` release line on 2026-06-21.

PreviewShare does not ship npm packages in the production plugin artifact. The release ZIP is built from compiled assets and excludes `node_modules`, package manifests, and build tooling. Production npm audit remains clean with:

```bash
npm run audit:prod
```

The npm development dependency graph was updated to reduce Dependabot/audit findings where npm provided a safe package path:

- `@wordpress/scripts` updated to `32.4.1`.
- `@wordpress/components` updated to `35.0.1`.
- Unused direct `@wordpress/edit-post` development dependency removed.
- npm `overrides` pin patched transitive dev-tooling packages for `http-proxy-middleware`, `js-yaml`, `markdown-it`, `minimatch`, `serialize-javascript`, `uuid`, and `webpack-dev-server`.

Remaining `npm audit` findings are dev-only moderate alerts from `showdown` via the latest available `@wordpress/editor` and its transitive `@wordpress/blocks` chain. npm reports `fixAvailable: false` for that chain, and the latest published `@wordpress/editor` package is still in the affected range. These alerts should be dismissed as development-only for the `1.0.1` release line until the upstream WordPress package graph publishes a fixed dependency path.
