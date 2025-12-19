<?php
/**
 * Admin Actions.
 *
 * @since      1.0.0
 * @package    WordPress
 * @subpackage PreviewShare
 * @author     Mehul Gohil
 * @link       https://mehulgohil.com
 */

namespace PreviewShare\Admin;

// Bailout, if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin Actions Class.
 *
 * @since 1.0.0
 */
class Actions {

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		add_action( 'enqueue_block_editor_assets', [ $this, 'enqueue_block_editor_assets' ] );
	}

	/**
	 * Enqueue block editor assets.
	 *
	 * @since 1.0.0
	 */
	public function enqueue_block_editor_assets() {
		wp_enqueue_script(
			'previewshare-editor',
			PREVIEWSHARE_PLUGIN_URL . 'assets/dist/js/previewshare-admin.min.js',
			[ 'wp-plugins', 'wp-edit-post', 'wp-element', 'wp-components', 'wp-data' ],
			PREVIEWSHARE_VERSION,
			true
		);
	}
}
