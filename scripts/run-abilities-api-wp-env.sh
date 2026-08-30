#!/usr/bin/env bash

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
RUNTIME_DIR="${ROOT_DIR}/tests/Runtime"
WORDPRESS_VERSION="${PREVIEWSHARE_WP_VERSION:?Set PREVIEWSHARE_WP_VERSION to an official WordPress ref.}"

WP_ENV_HOME="${WP_ENV_HOME:-${RUNNER_TEMP:-/tmp}/previewshare-abilities-${WORDPRESS_VERSION}}"
export WP_ENV_HOME
export WP_ENV_CORE="WordPress/WordPress#${WORDPRESS_VERSION}"

if command -v wp-env >/dev/null 2>&1; then
	WP_ENV_COMMAND=( wp-env )
elif [ -x "${ROOT_DIR}/node_modules/.bin/wp-env" ]; then
	WP_ENV_COMMAND=( "${ROOT_DIR}/node_modules/.bin/wp-env" )
else
	WP_ENV_COMMAND=( npx --yes --package=@wordpress/env@10.39.0 wp-env )
fi

run_wp_env() {
	"${WP_ENV_COMMAND[@]}" "$@"
}

cd "${RUNTIME_DIR}"

run_wp_env start
run_wp_env run cli wp plugin activate previewshare

runtime_output="$(
	run_wp_env run cli --env-cwd=/var/www/html/wp-content/plugins/previewshare \
		wp eval-file tests/Runtime/abilities-api-smoke.php
)"
printf '%s\n' "${runtime_output}"

receipt="$(
	printf '%s\n' "${runtime_output}" |
		sed -n 's/^PREVIEWSHARE_ABILITIES_RUNTIME_RECEIPT=//p'
)"

if [ -z "${receipt}" ]; then
	echo 'PreviewShare Abilities runtime proof did not emit a receipt.' >&2
	exit 1
fi

node -e '
const expectedVersion = process.argv[1];
const receipt = JSON.parse( process.argv[2] );
const expectedMode = expectedVersion.startsWith( "6.8." ) ? "compatibility" : "native";
if ( receipt.wordpress !== expectedVersion || receipt.mode !== expectedMode || ! Array.isArray( receipt.assertions ) || receipt.assertions.length === 0 ) {
	throw new Error( "PreviewShare Abilities runtime receipt is incomplete or does not match the requested WordPress version." );
}
' "${WORDPRESS_VERSION}" "${receipt}"
