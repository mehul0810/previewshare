<?php
/**
 * PreviewShare Plugin Controller.
 *
 * @since      1.0.0
 * @package    WordPress
 * @subpackage PreviewShare
 * @author     Mehul Gohil
 * @link       https://mehulgohil.com
 */

namespace PreviewShare;

// Bailout, if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Loads and registers plugin functionality through WordPress hooks.
 *
 * @since 1.0.0
 */
final class Plugin {

	/**
	 * Registers functionality with WordPress hooks.
	 *
	 * @since  1.0.0
	 * @access public
	 *
	 * @return void
	 */
	public function register() {
		// Handle plugin activation and deactivation.
		register_activation_hook( PREVIEWSHARE_PLUGIN_FILE, [ $this, 'activate' ] );
		register_deactivation_hook( PREVIEWSHARE_PLUGIN_FILE, [ $this, 'deactivate' ] );

		// Register services used throughout the plugin.
		add_action( 'plugins_loaded', [ $this, 'register_services' ] );
	}

	/**
	 * Registers the individual services of the plugin.
	 *
	 * @since  1.0.0
	 * @access public
	 *
	 * @return void
	 */
	public function register_services() {
		// Load Freemius SDK.
		$this->load_freemius();

		// Load procedural helpers.
		require_once __DIR__ . '/functions.php';

		// Instantiate shared services.
		$token_service = new Services\TokenService();
		$storage = new Services\CustomTableStorage();

		// Register services in container for global access if needed.
		Container::set( 'token_service', $token_service );
		Container::set( 'storage', $storage );

		// Load Admin Files.
		new Admin\Actions( $storage );
		new Admin\Filters();

		// Load Frontend Files.
		new Includes\Actions();
		new Includes\Filters();

		// Register REST controllers that use services (modular approach).
		new REST\PreviewController( $token_service, $storage );
	}

	/**
	 * Loads the Freemius SDK.
	 *
	 * @since  1.4.0
	 * @access public
	 *
	 * @return void
	 */
	public function load_freemius() {
		// Load Freemius SDK once loaded.
	}

	/**
	 * Handles activation procedures during installation and updates.
	 *
	 * @since  1.0.0
	 * @access public
	 *
	 * @param bool $network_wide Optional. Whether the plugin is being enabled on
	 *                           all network sites or a single site. Default false.
	 *
	 * @return void
	 */
	public function activate( $network_wide = false ) {
		// Create custom table for tokens and flush rewrite rules to register preview URLs
		\PreviewShare\DB\Migrations::create_table();
		Admin\Actions::flush_rewrite_rules();
	}

	/**
	 * Handles deactivation procedures.
	 *
	 * @since  1.0.0
	 * @access public
	 *
	 * @return void
	 */
	public function deactivate() {}
}
