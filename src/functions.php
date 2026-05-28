<?php
/**
 * Procedural helper wrappers for PreviewShare.
 *
 * Provides a small set of helper functions that call into the service
 * container. These are safe to use from procedural code or templates.
 *
 * @package PreviewShare
 */

// Abort if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use PreviewShare\Container;

/**
 * Resolve post ID from a preview token.
 *
 * @param string $token Raw token string.
 * @return int|false Post ID or false when not found.
 */
function previewshare_get_post_id_by_token( string $token ) {
	$storage = Container::storage();
	if ( ! $storage ) {
		return false;
	}

	return $storage->get_post_id_by_token( $token );
}

/**
 * Generate and store a preview token for a post.
 *
 * @param int      $post_id Post ID.
 * @param int|null $ttl_hours Optional expiry in hours.
 * @param string   $label Optional link label.
 * @return string|false Raw token on success, false on failure.
 */
function previewshare_generate_token_for_post( int $post_id, ?int $ttl_hours = null, string $label = '' ) {
	$token_service = Container::token_service();
	$storage       = Container::storage();
	if ( ! $token_service || ! $storage ) {
		return false;
	}

	$post = get_post( $post_id );
	if ( ! $post || ! previewshare_is_supported_post_type( $post->post_type ) ) {
		return false;
	}

	$token = $token_service->generate();

	$ttl    = is_null( $ttl_hours ) ? (int) get_option( 'previewshare_default_ttl_hours', 6 ) : absint( $ttl_hours );
	$stored = $storage->store_token( $post_id, $token, $ttl, $label );

	if ( ! $stored ) {
		return false;
	}

	update_post_meta( $post_id, '_previewshare_enabled', true );

	return $token;
}

/**
 * Get public, viewable post types that PreviewShare can support.
 *
 * @return array<string,string> Post type names keyed to labels.
 */
function previewshare_get_available_post_types(): array {
	$post_types = get_post_types( [ 'public' => true ], 'objects' );
	$available  = [];

	foreach ( $post_types as $post_type => $object ) {
		if ( 'attachment' === $post_type || ! is_post_type_viewable( $object ) ) {
			continue;
		}

		$available[ $post_type ] = $object->labels->singular_name ? $object->labels->singular_name : $object->label;
	}

	return $available;
}

/**
 * Get default plugin settings.
 *
 * @return array{default_ttl_hours:int,enable_logging:bool,enable_caching:bool,post_types:string[]}
 */
function previewshare_get_default_settings(): array {
	return [
		'default_ttl_hours' => 6,
		'enable_logging'    => false,
		'enable_caching'    => true,
		'post_types'        => array_keys( previewshare_get_available_post_types() ),
	];
}

/**
 * Ensure default settings are persisted for fresh installs and upgraded sites.
 *
 * @return void
 */
function previewshare_maybe_initialize_default_settings(): void {
	$defaults = previewshare_get_default_settings();

	add_option( 'previewshare_default_ttl_hours', $defaults['default_ttl_hours'], '', false );
	add_option( 'previewshare_enable_logging', $defaults['enable_logging'], '', false );
	add_option( 'previewshare_enable_caching', $defaults['enable_caching'], '', false );

	if ( false === get_option( 'previewshare_post_types', false ) && ! empty( $defaults['post_types'] ) ) {
		add_option( 'previewshare_post_types', $defaults['post_types'], '', false );
	}
}

/**
 * Get the plugin-owned secret key used to hash preview tokens.
 *
 * @return string Token hash key.
 */
function previewshare_get_token_hash_key(): string {
	$option_name = 'previewshare_token_hash_key';
	$stored_key  = get_option( $option_name, '' );

	if ( is_string( $stored_key ) && strlen( $stored_key ) >= 32 ) {
		return $stored_key;
	}

	try {
		$generated_key = bin2hex( random_bytes( 32 ) );
	} catch ( \Throwable ) {
		$generated_key = wp_generate_password( 64, true, true );
	}

	if ( ! add_option( $option_name, $generated_key, '', false ) ) {
		$stored_key = get_option( $option_name, '' );

		if ( is_string( $stored_key ) && strlen( $stored_key ) >= 32 ) {
			return $stored_key;
		}

		update_option( $option_name, $generated_key, false );
	}

	return $generated_key;
}

/**
 * Get normalized plugin settings.
 *
 * @return array{default_ttl_hours:int,enable_logging:bool,enable_caching:bool,post_types:string[],available_post_types:array<string,string>,defaults:array{default_ttl_hours:int,enable_logging:bool,enable_caching:bool,post_types:string[]}}
 */
function previewshare_get_settings(): array {
	$available        = previewshare_get_available_post_types();
	$defaults         = previewshare_get_default_settings();
	$saved_post_types = get_option( 'previewshare_post_types', false );

	if ( ! is_array( $saved_post_types ) ) {
		$saved_post_types = $defaults['post_types'];
	}

	$post_types = array_values( array_intersect( array_map( 'sanitize_key', $saved_post_types ), array_keys( $available ) ) );

	return [
		'default_ttl_hours'  => absint( get_option( 'previewshare_default_ttl_hours', $defaults['default_ttl_hours'] ) ),
		'enable_logging'     => (bool) get_option( 'previewshare_enable_logging', $defaults['enable_logging'] ),
		'enable_caching'     => (bool) get_option( 'previewshare_enable_caching', $defaults['enable_caching'] ),
		'post_types'         => $post_types,
		'available_post_types' => $available,
		'defaults'           => $defaults,
	];
}

/**
 * Persist sanitized plugin settings.
 *
 * @param array<string,mixed> $settings Settings to update.
 * @return array{default_ttl_hours:int,enable_logging:bool,enable_caching:bool,post_types:string[],available_post_types:array<string,string>,defaults:array{default_ttl_hours:int,enable_logging:bool,enable_caching:bool,post_types:string[]}}
 */
function previewshare_update_settings( array $settings ): array {
	if ( array_key_exists( 'default_ttl_hours', $settings ) ) {
		update_option( 'previewshare_default_ttl_hours', absint( $settings['default_ttl_hours'] ), false );
	}

	if ( array_key_exists( 'enable_logging', $settings ) ) {
		update_option( 'previewshare_enable_logging', (bool) filter_var( $settings['enable_logging'], FILTER_VALIDATE_BOOLEAN ), false );
	}

	if ( array_key_exists( 'enable_caching', $settings ) ) {
		update_option( 'previewshare_enable_caching', (bool) filter_var( $settings['enable_caching'], FILTER_VALIDATE_BOOLEAN ), false );
	}

	if ( array_key_exists( 'post_types', $settings ) && is_array( $settings['post_types'] ) ) {
		$available  = previewshare_get_available_post_types();
		$post_types = array_values( array_intersect( array_map( 'sanitize_key', $settings['post_types'] ), array_keys( $available ) ) );
		update_option( 'previewshare_post_types', $post_types, false );
	}

	return previewshare_get_settings();
}

/**
 * Reset persisted settings to defaults.
 *
 * @return array{default_ttl_hours:int,enable_logging:bool,enable_caching:bool,post_types:string[],available_post_types:array<string,string>,defaults:array{default_ttl_hours:int,enable_logging:bool,enable_caching:bool,post_types:string[]}}
 */
function previewshare_reset_default_settings(): array {
	$defaults = previewshare_get_default_settings();

	update_option( 'previewshare_default_ttl_hours', $defaults['default_ttl_hours'], false );
	update_option( 'previewshare_enable_logging', $defaults['enable_logging'], false );
	update_option( 'previewshare_enable_caching', $defaults['enable_caching'], false );
	update_option( 'previewshare_post_types', $defaults['post_types'], false );

	return previewshare_get_settings();
}

/**
 * Get post types currently enabled for PreviewShare.
 *
 * @return string[] Post type names.
 */
function previewshare_get_supported_post_types(): array {
	$settings  = previewshare_get_settings();
	$supported = $settings['post_types'];
	$available = $settings['available_post_types'];

	/**
	 * Filters the post types supported by PreviewShare.
	 *
	 * @param string[] $supported Supported post type names.
	 * @param array    $available Available post type labels keyed by post type.
	 */
	return apply_filters( 'previewshare_supported_post_types', $supported, $available );
}

/**
 * Check whether a post type is enabled for PreviewShare.
 *
 * @param string $post_type Post type name.
 * @return bool
 */
function previewshare_is_supported_post_type( string $post_type ): bool {
	return in_array( $post_type, previewshare_get_supported_post_types(), true );
}

/**
 * Emit an optional diagnostic event.
 *
 * PreviewShare does not write to PHP error logs in production. Sites that need
 * diagnostics can enable logging and attach their own handler to this action.
 *
 * @param string              $event Event identifier.
 * @param array<string,mixed> $context Event context.
 * @return void
 */
function previewshare_log( string $event, array $context = [] ): void {
	if ( ! (bool) get_option( 'previewshare_enable_logging', false ) ) {
		return;
	}

	/**
	 * Fires when PreviewShare emits a diagnostic event.
	 *
	 * @param string              $event Event identifier.
	 * @param array<string,mixed> $context Event context.
	 */
	do_action( 'previewshare_log', $event, $context );
}

/**
 * Read a built WordPress asset metadata file.
 *
 * @param string   $relative_path Relative path from the plugin root.
 * @param string[] $extra_dependencies Additional WordPress script handles.
 * @return array{dependencies:string[],version:string}
 */
function previewshare_get_asset_metadata( string $relative_path, array $extra_dependencies = [] ): array {
	$plugin_dir = defined( 'PREVIEWSHARE_PLUGIN_DIR' ) ? (string) constant( 'PREVIEWSHARE_PLUGIN_DIR' ) : dirname( __DIR__ ) . '/';
	$version    = defined( 'PREVIEWSHARE_VERSION' ) ? (string) constant( 'PREVIEWSHARE_VERSION' ) : '1.0.0';
	$asset_path = trailingslashit( $plugin_dir ) . ltrim( $relative_path, '/' );
	$asset      = is_readable( $asset_path ) ? include $asset_path : [];

	if ( ! is_array( $asset ) ) {
		$asset = [];
	}

	$dependencies = isset( $asset['dependencies'] ) && is_array( $asset['dependencies'] ) ? $asset['dependencies'] : [];
	$dependencies = array_values(
		array_unique(
			array_filter(
				array_merge( $dependencies, $extra_dependencies ),
				'is_string'
			)
		)
	);

	return [
		'dependencies' => $dependencies,
		'version'      => isset( $asset['version'] ) && is_string( $asset['version'] ) ? $asset['version'] : $version,
	];
}
