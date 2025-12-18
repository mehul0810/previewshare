<?php
/**
 * The plugin bootstrap file
 *
 * This file is read by WordPress to generate the plugin information in the plugin
 * admin area. This file also includes all of the dependencies used by the plugin,
 * registers the activation and deactivation functions, and starts the plugin.
 *
 * @since      1.0.0
 * @package    WordPress
 * @subpackage PreviewShare
 * @author     Mehul Gohil
 * @link       https://mehulgohil.com
 *
 * @wordpress-plugin
 *
 * Plugin Name: PreviewShare
 * Plugin URI: https://mehulgohil.com/products/previewshare/
 * Description: This plugin lets you securely share preview links for draft, pending, or scheduled content without publishing it publicly.
 * Version: 1.0.0
 * Author: Mehul Gohil
 * Author URI: https://mehulgohil.com/
 * License: GPLv3 or later
 * License URI: http://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: previewshare
 * Domain Path: /languages
 */

 namespace PreviewShare;

// Bailout, if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Load Constants.
require_once __DIR__ . '/config/constants.php';

// Automatically loads files used throughout the plugin.
require_once PREVIEWSHARE_PLUGIN_DIR . 'vendor/autoload.php';

// Initialize the plugin.
$previewshare_init = new Plugin();
$previewshare_init->register();