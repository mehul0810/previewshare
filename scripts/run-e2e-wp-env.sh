#!/usr/bin/env bash

set -euo pipefail

if ! command -v wp-env >/dev/null 2>&1; then
	if [ -x "./node_modules/.bin/wp-env" ]; then
		PATH="$(pwd)/node_modules/.bin:${PATH}"
	else
		echo "wp-env is not installed. Run npm ci before npm run test:e2e." >&2
		exit 1
	fi
fi

if [ ! -f "vendor/autoload.php" ]; then
	if ! command -v composer >/dev/null 2>&1; then
		echo "Composer dependencies are missing. Install Composer or run composer install before npm run test:e2e." >&2
		exit 1
	fi

	composer install --no-progress --prefer-dist
fi

npm run build

wp-env start
wp-env run cli wp plugin activate previewshare

export PREVIEWSHARE_E2E_BASE_URL="${PREVIEWSHARE_E2E_BASE_URL:-http://localhost:8889}"
export PREVIEWSHARE_E2E_WP_CLI="${PREVIEWSHARE_E2E_WP_CLI:-wp-env run cli wp}"

npm run test:e2e:playwright
