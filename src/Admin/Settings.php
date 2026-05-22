<?php
/**
 * Admin Settings page.
 *
 * @package PreviewShare
 */

namespace PreviewShare\Admin;

// Bailout if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Settings
 */
class Settings {

	/**
	 * Admin page hook suffix.
	 *
	 * @var string|null
	 */
	private $hook_suffix;

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'admin_menu', [ $this, 'register_menu' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
	}

	/**
	 * Register the Settings menu page.
	 */
	public function register_menu(): void {
		// Register as an Options page under Settings -> PreviewShare.
		$this->hook_suffix = add_options_page(
			__( 'PreviewShare', 'previewshare' ),
			__( 'PreviewShare', 'previewshare' ),
			'manage_options',
			'previewshare_settings',
			[ $this, 'render_page' ]
		);
	}

	/**
	 * Enqueue admin assets only on our settings page.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_assets( $hook ): void {
		if ( $this->hook_suffix && $hook !== $this->hook_suffix ) {
			return;
		}

		\previewshare_maybe_initialize_default_settings();

		$asset      = \previewshare_get_asset_metadata(
			'assets/dist/js/previewshare-settings.min.asset.php',
			[ 'wp-element', 'wp-components', 'wp-i18n', 'wp-data' ]
		);
		$plugin_url = defined( 'PREVIEWSHARE_PLUGIN_URL' ) ? (string) constant( 'PREVIEWSHARE_PLUGIN_URL' ) : '';

		wp_enqueue_style( 'wp-components' );
		wp_enqueue_style(
			'previewshare-settings',
			$plugin_url . 'assets/dist/previewshare-settings.css',
			[ 'wp-components' ],
			$asset['version']
		);
		wp_style_add_data( 'previewshare-settings', 'rtl', 'replace' );

		// Main settings app script. Built asset expected in assets/dist/js.
		wp_enqueue_script(
			'previewshare-settings',
			$plugin_url . 'assets/dist/js/previewshare-settings.min.js',
			$asset['dependencies'],
			$asset['version'],
			true
		);

		wp_localize_script(
			'previewshare-settings',
			'previewshare_settings',
			[
				// Base REST namespace for the plugin.
				'rest_url' => rest_url( 'previewshare/v1' ),
				'nonce'    => wp_create_nonce( 'wp_rest' ),
				'settings' => \previewshare_get_settings(),
			]
		);
	}

	/**
	 * Render the settings page container; the React app will mount here.
	 */
	public function render_page(): void {
		echo '<div class="wrap"><div id="previewshare-settings-app"></div></div>';
	}
}
