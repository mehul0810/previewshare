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
		add_action( 'rest_api_init', [ $this, 'register_rest_routes' ] );
		add_action( 'init', [ $this, 'add_rewrite_rules' ] );
		add_action( 'init', [ $this, 'register_meta_fields' ] );
		add_action( 'init', [ $this, 'maybe_flush_rewrite_rules' ] );
		add_action( 'template_redirect', [ $this, 'handle_preview_request' ] );
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
			[ 'wp-plugins', 'wp-edit-post', 'wp-element', 'wp-components', 'wp-data', 'wp-api-fetch' ],
			PREVIEWSHARE_VERSION,
			true
		);

		wp_localize_script(
			'previewshare-editor',
			'previewshare_rest',
			[
				'api_url' => rest_url( 'previewshare/v1/generate-url' ),
				'home_url' => home_url(),
				'nonce'   => wp_create_nonce( 'wp_rest' ),
			]
		);
	}

	/**
	 * Register custom meta fields.
	 *
	 * @since 1.0.0
	 */
	public function register_meta_fields() {
		$post_types = [ 'post', 'page' ];

		foreach ( $post_types as $post_type ) {
			register_post_meta(
				$post_type,
				'_previewshare_enabled',
				[
					'show_in_rest' => true,
					'single'       => true,
					'type'         => 'boolean',
					'default'      => false,
					'auth_callback' => function() {
						return current_user_can( 'edit_posts' );
					},
				]
			);
		}
	}

	/**
	 * Maybe flush rewrite rules if needed.
	 *
	 * @since 1.0.0
	 */
	public function maybe_flush_rewrite_rules() {
		if ( get_option( 'previewshare_rewrite_rules_flushed' ) !== PREVIEWSHARE_VERSION ) {
			flush_rewrite_rules();
			update_option( 'previewshare_rewrite_rules_flushed', PREVIEWSHARE_VERSION );
		}
	}

	/**
	 * Register REST API routes.
	 *
	 * @since 1.0.0
	 */
	public function register_rest_routes() {
		register_rest_route( 'previewshare/v1', '/generate-url', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'generate_preview_url' ],
			'permission_callback' => [ $this, 'check_generate_url_permissions' ],
			'args'                => [
				'post_id' => [
					'required'          => true,
					'validate_callback' => function( $value ) {
						return is_numeric( $value ) && $value > 0;
					},
				],
			],
		] );
	}

	/**
	 * Check permissions for generating preview URLs.
	 *
	 * @since 1.0.0
	 * @param WP_REST_Request $request The REST request.
	 * @return bool|WP_Error
	 */
	public function check_generate_url_permissions( $request ) {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return new \WP_Error( 'rest_forbidden', 'Insufficient permissions', [ 'status' => 403 ] );
		}

		$post_id = $request->get_param( 'post_id' );
		if ( $post_id && ! current_user_can( 'edit_post', $post_id ) ) {
			return new \WP_Error( 'rest_forbidden', 'Cannot edit this post', [ 'status' => 403 ] );
		}

		return true;
	}

	/**
	 * Generate a secure preview URL for a post.
	 *
	 * @since 1.0.0
	 * @param WP_REST_Request $request The REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function generate_preview_url( $request ) {
		$post_id = intval( $request->get_param( 'post_id' ) );
		if ( ! $post_id || ! get_post( $post_id ) ) {
			return new \WP_Error( 'invalid_post', 'Invalid post ID', [ 'status' => 400 ] );
		}

		$post = get_post( $post_id );
		if ( ! $post || ! in_array( $post->post_status, [ 'publish', 'draft', 'pending', 'future' ], true ) ) {
			return new \WP_Error( 'invalid_post_status', 'Post must be published, draft, pending, or scheduled to generate preview links', [ 'status' => 400 ] );
		}

		// Check if token already exists
		$existing_token = get_post_meta( $post_id, '_previewshare_token', true );

		if ( $existing_token ) {
			// Use existing token
			$token = $existing_token;
		} else {
			// Generate new unique token
			$token = $this->generate_unique_token();

			// Store token with post meta
			update_post_meta( $post_id, '_previewshare_token', $token );
			update_post_meta( $post_id, '_previewshare_created', time() );
			update_post_meta( $post_id, '_previewshare_user_id', get_current_user_id() );
		}

		// Generate pretty URL
		$preview_url = home_url() . '/preview/' . $token;

		return new \WP_REST_Response( [
			'url'   => $preview_url,
			'token' => $token,
		], 200 );
	}

	/**
	 * Generate a unique token for preview URLs.
	 *
	 * @since 1.0.0
	 * @return string
	 */
	private function generate_unique_token() {
		do {
			$token = wp_generate_password( 32, false );
		} while ( $this->token_exists( $token ) );

		return $token;
	}

	/**
	 * Check if a token already exists.
	 *
	 * @since 1.0.0
	 * @param string $token Token to check.
	 * @return bool
	 */
	private function token_exists( $token ) {
		global $wpdb;
		return $wpdb->get_var( $wpdb->prepare(
			"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_previewshare_token' AND meta_value = %s LIMIT 1",
			$token
		) ) !== null;
	}

	/**
	 * Add rewrite rules for preview URLs.
	 *
	 * @since 1.0.0
	 */
	public function add_rewrite_rules() {
		add_rewrite_rule( '^preview/([a-zA-Z0-9]+)/?$', 'index.php?previewshare_token=$matches[1]', 'top' );
		add_rewrite_tag( '%previewshare_token%', '([a-zA-Z0-9]+)' );
	}

	/**
	 * Flush rewrite rules on plugin activation.
	 *
	 * @since 1.0.0
	 */
	public static function flush_rewrite_rules() {
		$instance = new self();
		$instance->add_rewrite_rules();
		flush_rewrite_rules();
	}

	/**
	 * Handle preview URL requests.
	 *
	 * @since 1.0.0
	 */
	public function handle_preview_request() {
		$token = get_query_var( 'previewshare_token' );
		if ( ! $token ) {
			return;
		}

		// Find post by token
		global $wpdb;
		$post_id = $wpdb->get_var( $wpdb->prepare(
			"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_previewshare_token' AND meta_value = %s LIMIT 1",
			$token
		) );

		if ( ! $post_id ) {
			wp_die( 'Preview link is invalid or has expired.' );
		}

		$post = get_post( $post_id );
		if ( ! $post ) {
			wp_die( 'Preview link is invalid or has expired.' );
		}

		// Check if preview has expired (30 days)
		$created = get_post_meta( $post_id, '_previewshare_created', true );
		if ( $created && ( time() - $created ) > ( 30 * DAY_IN_SECONDS ) ) {
			wp_die( 'Preview link has expired.' );
		}

		// Allow access to posts with valid tokens, regardless of status
		// This enables previewing draft/pending/future posts

		// Set up the main query to show the post
		global $wp_query;
		$wp_query = new \WP_Query( [
			'p' => $post_id,
			'post_type' => $post->post_type,
			'posts_per_page' => 1,
		] );

		// Set query flags for template hierarchy
		$wp_query->is_single = true;
		$wp_query->is_singular = true;
		if ( $post->post_type === 'page' ) {
			$wp_query->is_page = true;
		} else {
			$wp_query->is_single = true;
		}
		$wp_query->query_vars['p'] = $post_id;
		$wp_query->query_vars['post_type'] = $post->post_type;

		// Populate the main query object so template functions have expected data.
		$wp_query->posts = array( $post );
		$wp_query->post = $post;
		$wp_query->found_posts = 1;
		$wp_query->post_count = 1;
		$wp_query->max_num_pages = 1;
		$wp_query->queried_object = $post;

		// Set global post and prepare it for template functions
		global $post;
		$post = get_post( $post_id );
		setup_postdata( $post );

		// WordPress will now load the appropriate template and handle the loop
		return;
	}
}
