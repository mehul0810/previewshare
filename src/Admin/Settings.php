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
        // Register as an Options page under Settings -> PreviewShare
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

        // Main settings app script (built asset expected in assets/dist/js)
        wp_enqueue_script(
            'previewshare-settings',
            PREVIEWSHARE_PLUGIN_URL . 'assets/dist/js/previewshare-settings.min.js',
            [ 'wp-element', 'wp-components', 'wp-i18n', 'wp-api-fetch', 'wp-data' ],
            PREVIEWSHARE_VERSION,
            true
        );

        wp_localize_script(
            'previewshare-settings',
            'previewshare_settings',
            [
                // Base REST namespace for the plugin.
                'rest_url' => rest_url( 'previewshare/v1' ),
                'nonce'    => wp_create_nonce( 'wp_rest' ),
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
