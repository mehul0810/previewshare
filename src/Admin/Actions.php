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

use PreviewShare\Services\PostMetaStorage;
use PreviewShare\Services\TokenService;

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
	/**
	 * Storage driver instance.
	 *
	 * @var PostMetaStorage
	 */
	private $storage;

	/**
	 * Constructor.
	 *
	* @param PostMetaStorage|null $storage Optional storage driver, useful for testing.
	 */
	public function __construct( PostMetaStorage $storage = null ) {
		$this->storage = $storage ?: new PostMetaStorage();

		add_action( 'enqueue_block_editor_assets', [ $this, 'enqueue_block_editor_assets' ] );
		add_action( 'rest_api_init', [ $this, 'register_rest_routes' ] );
		add_action( 'init', [ $this, 'add_rewrite_rules' ] );
		add_action( 'init', [ $this, 'register_meta_fields' ] );
		add_action( 'init', [ $this, 'maybe_flush_rewrite_rules' ] );

		// Use pre_get_posts to safely alter the main query for preview URLs.
		add_action( 'pre_get_posts', [ $this, 'maybe_handle_preview_request' ], 1 );
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
				'rest_base' => rest_url( 'previewshare/v1' ),
				'generate_url' => rest_url( 'previewshare/v1/generate-url' ),
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

			// Per-post TTL (hours) to override global default. 0 = no expiry.
			register_post_meta(
				$post_type,
				'_previewshare_ttl_hours',
				[
					'show_in_rest' => true,
					'single'       => true,
					'type'         => 'integer',
					'default'      => null,
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

		// Generate new unique token and store in custom table
		$token_service = new TokenService();
		// Loop until we get a unique token (very unlikely to loop more than once)
		do {
			$token = $token_service->generate();
		} while ( $this->token_exists( $token ) );

		// Persist via storage driver
		$this->storage->store_token( $post_id, $token );

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
		// Use storage driver to check existence by attempting lookup
		$post_id = $this->storage->get_post_id_by_token( $token );
		return ( $post_id !== false );
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
	 * Intercept the main query and modify it when a preview token is present.
	 * Uses `pre_get_posts` so WordPress can continue normal template resolution.
	 *
	 * @since 1.0.0
	 * @param \WP_Query $query Query instance.
	 * @return void
	 */
	public function maybe_handle_preview_request( \WP_Query $query ) {
		if ( is_admin() || ! $query->is_main_query() ) {
			return;
		}

		$token = get_query_var( 'previewshare_token' );
		if ( ! $token ) {
			return;
		}

		$post_id = $this->storage->get_post_id_by_token( $token );
		if ( ! $post_id ) {
			wp_die( 'Preview link is invalid or has expired.' );
		}

		$meta = $this->storage->get_token_meta( $post_id );
		if ( $meta && ( time() - $meta['created'] ) > ( 30 * DAY_IN_SECONDS ) ) {
			wp_die( 'Preview link has expired.' );
		}

		$post = get_post( $post_id );
		if ( ! $post ) {
			wp_die( 'Preview link is invalid or has expired.' );
		}

		// Safely set the query so template hierarchy will pick the correct template.
		$query->set( 'p', $post_id );
		$query->set( 'post_type', $post->post_type );
		$query->set( 'posts_per_page', 1 );
		// Ensure non-published statuses (draft/pending/future/private) are included for preview links.
		$query->set( 'post_status', [ 'publish', 'future', 'draft', 'pending', 'private' ] );
		// Avoid sticky posts behavior and other list modifiers.
		$query->set( 'ignore_sticky_posts', true );

		// Pre-populate some query flags and objects so template code sees the right context.
		$query->is_home = false;
		$query->is_archive = false;
		$query->is_search = false;
		$query->is_singular = true;

		// Set some flags that template logic may check.
		if ( $post->post_type === 'page' ) {
			$query->is_page = true;
			$query->is_single = false;
		} else {
			$query->is_single = true;
			$query->is_page = false;
		}

		$query->queried_object = $post;
		$query->queried_object_id = $post_id;
		$query->found_posts = 1;
		$query->post_count = 1;

		// Prevent canonical redirects from interfering with the preview URL.
		add_filter( 'redirect_canonical', [ $this, 'disable_canonical_redirect_for_preview' ], 10, 2 );
	}

	/**
	 * Disable canonical redirects when serving preview URLs.
	 *
	 * @since 1.0.0
	 * @param string|false $redirect_url Redirect URL.
	 * @param string       $requested_url Requested URL.
	 * @return string|false
	 */
	public function disable_canonical_redirect_for_preview( $redirect_url, $requested_url ) {
		if ( get_query_var( 'previewshare_token' ) ) {
			return false;
		}

		return $redirect_url;
	}
}
