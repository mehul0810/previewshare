<?php
// Bailout, if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Define plugin version in SemVer format.
if ( ! defined( 'PREVIEWSHARE_VERSION' ) ) {
	define( 'PREVIEWSHARE_VERSION', '1.0.0' );
}

// Define plugin root File.
if ( ! defined( 'PREVIEWSHARE_PLUGIN_FILE' ) ) {
	define( 'PREVIEWSHARE_PLUGIN_FILE', dirname( dirname( __FILE__ ) ) . '/previewshare.php' );
}

// Define plugin basename.
if ( ! defined( 'PREVIEWSHARE_PLUGIN_BASENAME' ) ) {
	define( 'PREVIEWSHARE_PLUGIN_BASENAME', plugin_basename( PREVIEWSHARE_PLUGIN_FILE ) );
}

// Define plugin directory Path.
if ( ! defined( 'PREVIEWSHARE_PLUGIN_DIR' ) ) {
	define( 'PREVIEWSHARE_PLUGIN_DIR', plugin_dir_path( PREVIEWSHARE_PLUGIN_FILE ) );
}

// Define plugin directory URL.
if ( ! defined( 'PREVIEWSHARE_PLUGIN_URL' ) ) {
	define( 'PREVIEWSHARE_PLUGIN_URL', plugin_dir_url( PREVIEWSHARE_PLUGIN_FILE ) );
}

// Define plugin docs URL
if ( ! defined( 'PREVIEWSHARE_PLUGIN_DOCS_URL' ) ) {
	define( 'PREVIEWSHARE_PLUGIN_DOCS_URL', 'https://mehulgohil.com/docs/previewshare/' );
}
