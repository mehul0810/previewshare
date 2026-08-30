#!/usr/bin/env bash

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
CONFIG_PATH="${ROOT_DIR}/tests/Runtime/.wp-env.abilities.json"
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
	"${WP_ENV_COMMAND[@]}" --config "${CONFIG_PATH}" "$@"
}

cd "${ROOT_DIR}"

run_wp_env start
run_wp_env run cli wp plugin activate previewshare
run_wp_env run cli --env-cwd=/var/www/html/wp-content/plugins/previewshare \
	wp eval-file tests/Runtime/abilities-api-smoke.php
