<?php
/**
 * PHPStan bootstrap for WordPress runtime constants.
 *
 * @package PreviewShare
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', '/tmp/wordpress/' );
}

if ( ! defined( 'AUTH_SALT' ) ) {
	define( 'AUTH_SALT', 'previewshare-phpstan-auth-salt' );
}

if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 3600 );
}

if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
	define( 'MINUTE_IN_SECONDS', 60 );
}

if ( ! defined( 'PREVIEWSHARE_VERSION' ) ) {
	define( 'PREVIEWSHARE_VERSION', '1.0.0' );
}

if ( ! defined( 'PREVIEWSHARE_PLUGIN_FILE' ) ) {
	define( 'PREVIEWSHARE_PLUGIN_FILE', __DIR__ . '/previewshare.php' );
}

if ( ! defined( 'PREVIEWSHARE_PLUGIN_DIR' ) ) {
	define( 'PREVIEWSHARE_PLUGIN_DIR', __DIR__ . '/' );
}

if ( ! defined( 'PREVIEWSHARE_PLUGIN_URL' ) ) {
	define( 'PREVIEWSHARE_PLUGIN_URL', 'https://example.test/wp-content/plugins/previewshare/' );
}
