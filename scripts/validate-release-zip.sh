#!/usr/bin/env bash

set -euo pipefail

PLUGIN_SLUG="${PLUGIN_SLUG:-previewshare}"
ZIP_PATH="${1:-${PLUGIN_SLUG}.zip}"

if [[ ! -f "${ZIP_PATH}" ]]; then
	echo "Missing release zip: ${ZIP_PATH}" >&2
	exit 1
fi

if ! command -v unzip > /dev/null 2>&1; then
	echo "Missing required command: unzip" >&2
	exit 1
fi

LIST_FILE="$(mktemp)"
cleanup() {
	rm -f "${LIST_FILE}"
}

trap cleanup EXIT

unzip -Z1 "${ZIP_PATH}" > "${LIST_FILE}"

TOP_LEVEL="$(awk -F/ 'NF { print $1 }' "${LIST_FILE}" | sort -u | tr '\n' ' ')"
if [[ "${TOP_LEVEL}" != "${PLUGIN_SLUG} " ]]; then
	echo "Release zip must contain only the ${PLUGIN_SLUG}/ top-level directory." >&2
	echo "Found: ${TOP_LEVEL}" >&2
	exit 1
fi

required_paths=(
	"${PLUGIN_SLUG}/previewshare.php"
	"${PLUGIN_SLUG}/readme.txt"
	"${PLUGIN_SLUG}/license.txt"
	"${PLUGIN_SLUG}/config/constants.php"
	"${PLUGIN_SLUG}/src/Plugin.php"
	"${PLUGIN_SLUG}/assets/dist/js/previewshare-admin.min.js"
	"${PLUGIN_SLUG}/assets/dist/js/previewshare.min.js"
	"${PLUGIN_SLUG}/vendor/autoload.php"
)

for required_path in "${required_paths[@]}"; do
	if ! grep -Fxq "${required_path}" "${LIST_FILE}"; then
		echo "Release zip is missing required file: ${required_path}" >&2
		exit 1
	fi
done

forbidden_pattern="^${PLUGIN_SLUG}/(\\.git|\\.github|node_modules|assets/src|tests|scripts|vendor/bin|phpstan|phpcs|composer\\.json|composer\\.lock|package\\.json|package-lock\\.json|README\\.md|AGENTS\\.md|\\.distignore|\\.editorconfig|\\.gitignore|\\.babelrc|postcss\\.config\\.js|webpack\\.config\\.js|wp-textdomain\\.js|previewshare\\.zip)(/|$)"

if grep -E "${forbidden_pattern}" "${LIST_FILE}"; then
	echo "Release zip contains development-only files." >&2
	exit 1
fi

echo "Release zip validation passed: ${ZIP_PATH}"
