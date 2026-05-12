#!/usr/bin/env bash

set -euo pipefail

PLUGIN_SLUG="${PLUGIN_SLUG:-previewshare}"
ZIP_NAME="${1:-${PLUGIN_SLUG}.zip}"
ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
BUILD_DIR="$(mktemp -d)"
STAGING_DIR="${BUILD_DIR}/${PLUGIN_SLUG}"

for required_command in rsync zip; do
	if ! command -v "${required_command}" > /dev/null 2>&1; then
		echo "Missing required command: ${required_command}" >&2
		exit 1
	fi
done

cleanup() {
	rm -rf "${BUILD_DIR}"
}

trap cleanup EXIT

mkdir -p "${STAGING_DIR}"
rm -f "${ROOT_DIR}/${ZIP_NAME}"

rsync -a --delete --exclude-from="${ROOT_DIR}/.distignore" "${ROOT_DIR}/" "${STAGING_DIR}/"

(
	cd "${BUILD_DIR}"
	zip -rq "${ROOT_DIR}/${ZIP_NAME}" "${PLUGIN_SLUG}"
)

echo "Created ${ZIP_NAME}"
