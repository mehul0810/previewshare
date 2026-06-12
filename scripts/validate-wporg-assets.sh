#!/usr/bin/env bash

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
ASSETS_DIR="${ROOT_DIR}/.wordpress-org"
README_FILE="${ROOT_DIR}/readme.txt"
BLUEPRINT_FILE="${ASSETS_DIR}/blueprints/blueprint.json"

required_files=(
	"${ASSETS_DIR}/banner-772x250.png"
	"${ASSETS_DIR}/banner-1544x500.png"
	"${ASSETS_DIR}/icon-128x128.png"
	"${ASSETS_DIR}/icon-256x256.png"
	"${ASSETS_DIR}/screenshot-1.png"
	"${ASSETS_DIR}/screenshot-2.png"
	"${ASSETS_DIR}/screenshot-3.png"
	"${BLUEPRINT_FILE}"
)

for required_file in "${required_files[@]}"; do
	if [[ ! -f "${required_file}" ]]; then
		echo "Missing WordPress.org asset: ${required_file#${ROOT_DIR}/}" >&2
		exit 1
	fi
done

check_png_size() {
	local file="$1"
	local expected_width="$2"
	local expected_height="$3"

	php -r '
		$file = $argv[1];
		$expected_width = (int) $argv[2];
		$expected_height = (int) $argv[3];
		$size = getimagesize( $file );

		if ( false === $size || "image/png" !== $size["mime"] || $size[0] !== $expected_width || $size[1] !== $expected_height ) {
			fwrite( STDERR, sprintf( "Invalid image dimensions for %s. Expected %dx%d PNG, got %s.\n", $file, $expected_width, $expected_height, false === $size ? "unreadable image" : $size[0] . "x" . $size[1] . " " . $size["mime"] ) );
			exit( 1 );
		}
	' "${file}" "${expected_width}" "${expected_height}"
}

check_png_size "${ASSETS_DIR}/banner-772x250.png" 772 250
check_png_size "${ASSETS_DIR}/banner-1544x500.png" 1544 500
check_png_size "${ASSETS_DIR}/icon-128x128.png" 128 128
check_png_size "${ASSETS_DIR}/icon-256x256.png" 256 256

for screenshot in "${ASSETS_DIR}"/screenshot-*.png; do
	php -r '
		$file = $argv[1];
		$size = getimagesize( $file );

		if ( false === $size || "image/png" !== $size["mime"] ) {
			fwrite( STDERR, sprintf( "Invalid screenshot PNG: %s\n", $file ) );
			exit( 1 );
		}
	' "${screenshot}"
done

php -r '
	$file = $argv[1];
	$blueprint = json_decode( file_get_contents( $file ), true );

	if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $blueprint ) ) {
		fwrite( STDERR, sprintf( "Invalid blueprint JSON: %s\n", json_last_error_msg() ) );
		exit( 1 );
	}

	foreach ( [ "landingPage", "preferredVersions", "steps" ] as $required_key ) {
		if ( ! array_key_exists( $required_key, $blueprint ) ) {
			fwrite( STDERR, sprintf( "Blueprint is missing required key: %s\n", $required_key ) );
			exit( 1 );
		}
	}
' "${BLUEPRINT_FILE}"

if ! grep -Eq '^== Screenshots ==$' "${README_FILE}"; then
	echo "readme.txt is missing a Screenshots section." >&2
	exit 1
fi

screenshot_file_count="$(find "${ASSETS_DIR}" -maxdepth 1 -type f -name 'screenshot-[0-9]*.png' | wc -l | tr -d ' ')"
screenshot_caption_count="$(
	awk '
		/^== Screenshots ==$/ { in_section = 1; next }
		/^== / && in_section { in_section = 0 }
		in_section && /^[0-9]+[.] / { count++ }
		END { print count + 0 }
	' "${README_FILE}"
)"

if [[ "${screenshot_file_count}" != "${screenshot_caption_count}" ]]; then
	echo "Screenshot count mismatch: found ${screenshot_file_count} files but ${screenshot_caption_count} readme captions." >&2
	exit 1
fi

echo "WordPress.org asset validation passed."
