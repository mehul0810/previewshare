#!/usr/bin/env bash

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
E2E_DIR="$(mktemp -d "${TMPDIR:-/tmp}/previewshare-e2e.XXXXXX")"
PACKAGE_DIR="${ROOT_DIR}/artifacts/e2e-package"
PACKAGE_DIR_CREATED=0
ZIP_PATH="${ROOT_DIR}/previewshare.zip"
ZIP_CREATED=0
WP_ENV_START_ATTEMPTED=0
PRESERVE_FIXTURE=0

cleanup() {
	if [ "${WP_ENV_START_ATTEMPTED}" -eq 1 ]; then
		if ! wp-env cleanup --force; then
			echo "Could not clean the owned wp-env fixture; preserving its package and state." >&2
			PRESERVE_FIXTURE=1
		fi
	fi
	if [ "${PRESERVE_FIXTURE}" -eq 0 ]; then
		if [ "${ZIP_CREATED}" -eq 1 ]; then
			rm -f "${ZIP_PATH}"
		fi
		if [ "${PACKAGE_DIR_CREATED}" -eq 1 ]; then
			rm -f "${PACKAGE_DIR}/previewshare.zip"
			rmdir "${PACKAGE_DIR}"
		fi
		rm -rf "${E2E_DIR}"
	fi
}

trap cleanup EXIT

if [ -e "${PACKAGE_DIR}" ] || [ -L "${PACKAGE_DIR}" ]; then
	echo "Refusing to reuse existing e2e package directory: ${PACKAGE_DIR}" >&2
	exit 1
fi
if [ -e "${ZIP_PATH}" ] || [ -L "${ZIP_PATH}" ]; then
	echo "Refusing to overwrite existing plugin ZIP: ${ZIP_PATH}" >&2
	exit 1
fi
if [ -L "${ROOT_DIR}/artifacts" ]; then
	echo "Refusing to use symlinked artifacts directory: ${ROOT_DIR}/artifacts" >&2
	exit 1
fi

node "${ROOT_DIR}/scripts/e2e-fixture.js" assert-no-target-overrides

if ! command -v wp-env >/dev/null 2>&1; then
	if [ -x "./node_modules/.bin/wp-env" ]; then
		PATH="$(pwd)/node_modules/.bin:${PATH}"
	else
		echo "wp-env is not installed. Run npm ci before npm run test:e2e." >&2
		exit 1
	fi
fi

mkdir -p "${ROOT_DIR}/artifacts"
mkdir "${PACKAGE_DIR}"
PACKAGE_DIR_CREATED=1
ZIP_CREATED=1
npm run plugin-zip
ZIP_SHA256="$(node -e 'const crypto = require("crypto"); const fs = require("fs"); process.stdout.write(crypto.createHash("sha256").update(fs.readFileSync(process.argv[1])).digest("hex"));' "${ZIP_PATH}")"
printf '%s  %s\n' "${ZIP_SHA256}" "previewshare.zip" | tee "${E2E_DIR}/previewshare.zip.sha256"
cp "${ZIP_PATH}" "${PACKAGE_DIR}/previewshare.zip"

export WP_ENV_HOME="${E2E_DIR}/wp-env-home"
WP_ENV_START_ATTEMPTED=1
wp-env start
wp-env run cli wp plugin install /var/www/html/wp-content/uploads/previewshare-e2e/previewshare.zip
wp-env run cli wp plugin activate previewshare

INSTALLED_PATH="$(wp-env run cli wp plugin path previewshare --dir | grep -Fx '/var/www/html/wp-content/plugins/previewshare')"
if [ "${INSTALLED_PATH}" != "/var/www/html/wp-content/plugins/previewshare" ]; then
	echo "PreviewShare was not installed into the expected package path: ${INSTALLED_PATH}" >&2
	exit 1
fi

PLUGIN_SLUG="$(wp-env run cli wp plugin get previewshare --field=name | grep -Fx 'previewshare')"
EXPECTED_PLUGIN_VERSION="$(awk '/^[[:space:]]*\* Version:/ { print $3; exit }' "${ROOT_DIR}/previewshare.php")"
PLUGIN_VERSION="$(wp-env run cli wp plugin get previewshare --field=version | grep -Fx "${EXPECTED_PLUGIN_VERSION}")"
if [ "${PLUGIN_SLUG}" != "previewshare" ] || [ "${PLUGIN_VERSION}" != "${EXPECTED_PLUGIN_VERSION}" ]; then
	echo "Unexpected installed package identity: ${PLUGIN_SLUG} ${PLUGIN_VERSION}" >&2
	exit 1
fi

ARCHIVE_MAIN_HASH="$(unzip -p "${ZIP_PATH}" previewshare/previewshare.php | node -e 'const crypto = require("crypto"); const fs = require("fs"); process.stdout.write(crypto.createHash("sha256").update(fs.readFileSync(0)).digest("hex"));')"
INSTALLED_MAIN_HASH="$(wp-env run cli wp eval 'echo hash_file("sha256", ABSPATH . "wp-content/plugins/previewshare/previewshare.php");' | grep -E '^[a-f0-9]{64}$')"
if [ -z "${INSTALLED_MAIN_HASH}" ] || [ "${ARCHIVE_MAIN_HASH}" != "${INSTALLED_MAIN_HASH}" ]; then
	echo "Installed PreviewShare main file does not match the packaged ZIP." >&2
	exit 1
fi

node "${ROOT_DIR}/scripts/e2e-fixture.js" assert-cli-binding

export WP_BASE_URL="http://localhost:8889"
export PREVIEWSHARE_E2E_BASE_URL="http://localhost:8889"
export PREVIEWSHARE_E2E_WP_CLI="wp-env run cli wp"

npm run test:e2e:playwright
