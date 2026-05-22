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
	 * Tracks whether the frontend preview bar has already been rendered.
	 *
	 * @var bool
	 */
	private $preview_bar_rendered = false;

	/**
	 * Constructor.
	 *
	 * @param PostMetaStorage|null $storage Optional storage driver, useful for testing.
	 */
	public function __construct( ?PostMetaStorage $storage = null ) {
		$this->storage = $storage ? $storage : new PostMetaStorage();

		add_action( 'enqueue_block_editor_assets', [ $this, 'enqueue_block_editor_assets' ] );
		add_action( 'rest_api_init', [ $this, 'register_rest_routes' ] );
		add_action( 'init', [ $this, 'add_rewrite_rules' ] );
		add_action( 'init', [ $this, 'register_meta_fields' ] );
		add_action( 'init', [ $this, 'maybe_flush_rewrite_rules' ] );
		add_action( 'add_meta_boxes', [ $this, 'register_classic_meta_boxes' ] );
		add_action( 'admin_post_previewshare_generate_link', [ $this, 'handle_admin_generate_link' ] );
		add_action( 'admin_post_previewshare_revoke_links', [ $this, 'handle_admin_revoke_links' ] );
		add_action( 'admin_notices', [ $this, 'render_admin_notice' ] );

		// Use pre_get_posts to safely alter the main query for preview URLs.
		add_action( 'pre_get_posts', [ $this, 'maybe_handle_preview_request' ], 1 );
		add_action( 'send_headers', [ $this, 'send_preview_robots_header' ] );
		add_action( 'wp_head', [ $this, 'render_preview_bar_styles' ], 1 );
		add_action( 'wp_body_open', [ $this, 'render_preview_bar' ], 0 );
		add_action( 'wp_footer', [ $this, 'render_preview_bar' ], 0 );
		add_filter( 'wp_robots', [ $this, 'filter_preview_robots' ] );
		add_filter( 'body_class', [ $this, 'add_preview_body_class' ] );
		add_filter( 'post_row_actions', [ $this, 'add_post_row_actions' ], 10, 2 );
		add_filter( 'page_row_actions', [ $this, 'add_post_row_actions' ], 10, 2 );
	}

	/**
	 * Enqueue block editor assets.
	 *
	 * @since 1.0.0
	 */
	public function enqueue_block_editor_assets() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $screen && ! \previewshare_is_supported_post_type( (string) $screen->post_type ) ) {
			return;
		}

		$asset = \previewshare_get_asset_metadata(
			'assets/dist/js/previewshare-admin.min.asset.php',
			[ 'wp-api-fetch', 'wp-edit-post' ]
		);

		wp_enqueue_script(
			'previewshare-editor',
			PREVIEWSHARE_PLUGIN_URL . 'assets/dist/js/previewshare-admin.min.js',
			$asset['dependencies'],
			$asset['version'],
			true
		);

		wp_enqueue_style(
			'previewshare-editor',
			PREVIEWSHARE_PLUGIN_URL . 'assets/dist/previewshare-admin.css',
			[ 'wp-components' ],
			$asset['version']
		);
		wp_style_add_data( 'previewshare-editor', 'rtl', 'replace' );

		wp_localize_script(
			'previewshare-editor',
			'previewshare_rest',
			[
				'rest_base' => rest_url( 'previewshare/v1' ),
				'generate_url' => rest_url( 'previewshare/v1/generate-url' ),
				'home_url' => home_url(),
				'post_types' => \previewshare_get_supported_post_types(),
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
		$post_types = \previewshare_get_supported_post_types();

		foreach ( $post_types as $post_type ) {
			register_post_meta(
				$post_type,
				'_previewshare_enabled',
				[
					'show_in_rest' => true,
					'single'       => true,
					'type'         => 'boolean',
					'default'      => false,
					'auth_callback' => function ( $allowed, $meta_key, $post_id ) {
						return current_user_can( 'edit_post', $post_id );
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
					'auth_callback' => function ( $allowed, $meta_key, $post_id ) {
						return current_user_can( 'edit_post', $post_id );
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
			update_option( 'previewshare_rewrite_rules_flushed', PREVIEWSHARE_VERSION, false );
		}
	}

	/**
	 * Register REST API routes.
	 *
	 * @since 1.0.0
	 */
	public function register_rest_routes() {
		register_rest_route(
			'previewshare/v1',
			'/generate-url',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'generate_preview_url' ],
				'permission_callback' => [ $this, 'check_generate_url_permissions' ],
				'args'                => [
					'post_id' => [
						'required'          => true,
						'validate_callback' => function ( $value ) {
							return is_numeric( $value ) && $value > 0;
						},
					],
					'ttl_hours' => [
						'required'          => false,
						'validate_callback' => function ( $value ) {
							return is_numeric( $value ) && $value >= 0;
						},
					],
					'label' => [
						'required' => false,
						'type'     => 'string',
					],
				],
			]
		);

		// Return token meta for a post (used by editor UI to show expired state).
		register_rest_route(
			'previewshare/v1',
			'/post-meta',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_post_token_meta' ],
				'permission_callback' => function ( $request ) {
					$post_id = isset( $request['post_id'] ) ? absint( $request['post_id'] ) : 0;
					if ( $post_id ) {
						return current_user_can( 'edit_post', $post_id );
					}
					return current_user_can( 'edit_posts' );
				},
				'args'                => [
					'post_id' => [
						'required' => true,
						'validate_callback' => function ( $v ) {
														return is_numeric( $v ) && $v > 0; },
					],
				],
			]
		);
	}

	/**
	 * Check permissions for generating preview URLs.
	 *
	 * @since 1.0.0
	 * @param \WP_REST_Request<array<string,mixed>> $request The REST request.
	 * @return bool|\WP_Error
	 */
	public function check_generate_url_permissions( $request ) {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return new \WP_Error( 'rest_forbidden', 'Insufficient permissions', [ 'status' => 403 ] );
		}

		$post_id = $request->get_param( 'post_id' );
		if ( $post_id && ! current_user_can( 'edit_post', $post_id ) ) {
			return new \WP_Error( 'rest_forbidden', 'Cannot edit this post', [ 'status' => 403 ] );
		}

		$post = $post_id ? get_post( $post_id ) : null;
		if ( $post && ! \previewshare_is_supported_post_type( $post->post_type ) ) {
			return new \WP_Error( 'rest_forbidden', 'PreviewShare is not enabled for this post type', [ 'status' => 403 ] );
		}

		return true;
	}

	/**
	 * Generate a secure preview URL for a post.
	 *
	 * @since 1.0.0
	 * @param \WP_REST_Request<array<string,mixed>> $request The REST request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function generate_preview_url( $request ) {
		$post_id = intval( $request->get_param( 'post_id' ) );
		if ( ! $post_id || ! get_post( $post_id ) ) {
			return new \WP_Error( 'invalid_post', 'Invalid post ID', [ 'status' => 400 ] );
		}

		$post = get_post( $post_id );
		if ( ! $post || ! \previewshare_is_supported_post_type( $post->post_type ) ) {
			return new \WP_Error( 'unsupported_post_type', 'PreviewShare is not enabled for this post type', [ 'status' => 400 ] );
		}

		if ( ! $this->is_previewable_post_status( $post->post_status ) ) {
			return new \WP_Error( 'invalid_post_status', 'Post must be published, draft, pending, scheduled, or private to generate preview links', [ 'status' => 400 ] );
		}

		$ttl   = $this->get_requested_ttl_hours( $request, $post_id );
		$label = sanitize_text_field( (string) $request->get_param( 'label' ) );

		// Generate a unique token and store it in post meta.
		$token_service = new TokenService();
		// This is very unlikely to loop more than once.
		do {
			$token = $token_service->generate();
		} while ( $this->token_exists( $token ) );

		// Persist via the storage driver.
		$stored = $this->storage->store_token( $post_id, $token, $ttl, $label );

		if ( ! $stored ) {
			return new \WP_Error( 'token_storage_failed', 'Preview token could not be stored', [ 'status' => 500 ] );
		}

		update_post_meta( $post_id, '_previewshare_enabled', true );
		if ( null !== $request->get_param( 'ttl_hours' ) ) {
			update_post_meta( $post_id, '_previewshare_ttl_hours', $ttl );
		}

		// Generate the pretty URL.
		$preview_url = home_url() . '/preview/' . $token;

		return new \WP_REST_Response(
			[
				'url'   => $preview_url,
				'token' => $token,
			],
			200
		);
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
		// Use the storage driver to check existence by attempting lookup.
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

		$this->send_preview_robots_header();

		$post_id = $this->storage->get_post_id_by_token( $token );
		if ( ! $post_id ) {
			wp_die( esc_html__( 'Preview link is invalid or has expired.', 'previewshare' ), '', [ 'response' => 410 ] );
		}

		if ( ! get_post_meta( $post_id, '_previewshare_enabled', true ) ) {
			wp_die( esc_html__( 'Preview link is disabled.', 'previewshare' ), '', [ 'response' => 410 ] );
		}

		$meta = $this->storage->get_token_meta( $post_id );
		if ( empty( $meta ) || ! empty( $meta['revoked'] ) || ! empty( $meta['expired'] ) ) {
			wp_die( esc_html__( 'Preview link is invalid or has expired.', 'previewshare' ), '', [ 'response' => 410 ] );
		}

		$post = get_post( $post_id );
		if ( ! $post ) {
			wp_die( esc_html__( 'Preview link is invalid or has expired.', 'previewshare' ), '', [ 'response' => 410 ] );
		}

		if ( ! \previewshare_is_supported_post_type( $post->post_type ) || ! $this->is_previewable_post_status( $post->post_status ) ) {
			wp_die( esc_html__( 'Preview link is not available for this content.', 'previewshare' ), '', [ 'response' => 410 ] );
		}

		if ( 'publish' === $post->post_status ) {
			wp_safe_redirect( get_permalink( $post ), 302 );
			exit;
		}

		$this->storage->record_token_view( $token );

		// Safely set the query so template hierarchy will pick the correct template.
		$query->set( 'p', $post_id );
		$query->set( 'post_type', $post->post_type );
		$query->set( 'posts_per_page', 1 );
		// Ensure non-published statuses (draft/pending/future/private) are included for preview links.
		$query->set( 'post_status', [ 'publish', 'future', 'draft', 'pending', 'private' ] );
		// Avoid sticky posts behavior and other list modifiers.
		$query->set( 'ignore_sticky_posts', true );

		// Pre-populate some query flags and objects so template code sees the right context.
		$query->is_home     = false;
		$query->is_archive  = false;
		$query->is_search   = false;
		$query->is_singular = true;

		// Set some flags that template logic may check.
		if ( $post->post_type === 'page' ) {
			$query->is_page   = true;
			$query->is_single = false;
		} else {
			$query->is_single = true;
			$query->is_page   = false;
		}

		$query->queried_object    = $post;
		$query->queried_object_id = $post_id;
		$query->found_posts       = 1;
		$query->post_count        = 1;

		// Prevent canonical redirects from interfering with the preview URL.
		add_filter( 'redirect_canonical', [ $this, 'disable_canonical_redirect_for_preview' ], 10, 2 );
	}

	/**
	 * REST handler: return token meta for a given post_id.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_post_token_meta( $request ) {
		$post_id = absint( $request->get_param( 'post_id' ) );
		if ( ! $post_id || ! get_post( $post_id ) ) {
			return new \WP_Error( 'invalid_post', 'Invalid post ID', [ 'status' => 400 ] );
		}

		$post = get_post( $post_id );
		if ( ! \previewshare_is_supported_post_type( $post->post_type ) ) {
			return new \WP_Error( 'unsupported_post_type', 'PreviewShare is not enabled for this post type', [ 'status' => 400 ] );
		}

		$meta = $this->storage->get_token_meta( $post_id );

		// Also indicate whether the post currently has an indexed token.
		$has_token = (bool) get_post_meta( $post_id, '_previewshare_token_hash', true );

		return new \WP_REST_Response(
			[
				'has_token' => $has_token,
				'meta' => $meta,
			],
			200
		);
	}

	/**
	 * Disable canonical redirects when serving preview URLs.
	 *
	 * @since 1.0.0
	 * @param string|false $redirect_url Redirect URL.
	 * @param string       $_requested_url Requested URL.
	 * @return string|false
	 */
	public function disable_canonical_redirect_for_preview( $redirect_url, $_requested_url ) {
		unset( $_requested_url );

		if ( get_query_var( 'previewshare_token' ) ) {
			return false;
		}

		return $redirect_url;
	}

	/**
	 * Get a normalized TTL for a preview generation request.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @param int              $post_id Post ID.
	 * @return int TTL in hours. 0 means no expiry.
	 */
	private function get_requested_ttl_hours( $request, int $post_id ): int {
		$ttl = $request->get_param( 'ttl_hours' );

		if ( null !== $ttl ) {
			return absint( $ttl );
		}

		$post_ttl = get_post_meta( $post_id, '_previewshare_ttl_hours', true );
		if ( '' !== $post_ttl && null !== $post_ttl ) {
			return absint( $post_ttl );
		}

		return (int) get_option( 'previewshare_default_ttl_hours', 6 );
	}

	/**
	 * Check whether a post status can be exposed through a preview token.
	 *
	 * @param string $status Post status.
	 * @return bool
	 */
	private function is_previewable_post_status( string $status ): bool {
		return in_array( $status, [ 'publish', 'draft', 'pending', 'future', 'private' ], true );
	}

	/**
	 * Register Classic Editor meta boxes.
	 *
	 * @return void
	 */
	public function register_classic_meta_boxes(): void {
		foreach ( \previewshare_get_supported_post_types() as $post_type ) {
			if ( function_exists( 'use_block_editor_for_post_type' ) && use_block_editor_for_post_type( $post_type ) ) {
				continue;
			}

			add_meta_box(
				'previewshare-classic',
				__( 'PreviewShare', 'previewshare' ),
				[ $this, 'render_classic_meta_box' ],
				$post_type,
				'side',
				'default'
			);
		}
	}

	/**
	 * Render Classic Editor meta box.
	 *
	 * @param \WP_Post $post Post object.
	 * @return void
	 */
	public function render_classic_meta_box( \WP_Post $post ): void {
		if ( ! current_user_can( 'edit_post', $post->ID ) || ! $this->is_previewable_post_status( $post->post_status ) ) {
			echo '<p>' . esc_html__( 'Preview sharing is unavailable for this content.', 'previewshare' ) . '</p>';
			return;
		}

		$meta         = $this->storage->get_token_meta( (int) $post->ID );
		$active_count = isset( $meta['active_count'] ) ? (int) $meta['active_count'] : 0;
		$generate_url = $this->get_admin_action_url( 'previewshare_generate_link', (int) $post->ID );
		$revoke_url   = $this->get_admin_action_url( 'previewshare_revoke_links', (int) $post->ID );

		echo '<p>' . esc_html(
			sprintf(
			/* translators: %d: Number of active preview links. */
				_n( '%d active preview link.', '%d active preview links.', $active_count, 'previewshare' ),
				$active_count
			)
		) . '</p>';
		echo '<p><a class="button button-primary" href="' . esc_url( $generate_url ) . '">' . esc_html__( 'Generate Preview Link', 'previewshare' ) . '</a></p>';

		if ( $active_count > 0 ) {
			echo '<p><a class="button" href="' . esc_url( $revoke_url ) . '">' . esc_html__( 'Revoke Active Links', 'previewshare' ) . '</a></p>';
		}
	}

	/**
	 * Add post list row actions.
	 *
	 * @param array<string,string> $actions Existing row actions.
	 * @param \WP_Post             $post Post object.
	 * @return array<string,string>
	 */
	public function add_post_row_actions( array $actions, \WP_Post $post ): array {
		if ( ! \previewshare_is_supported_post_type( $post->post_type ) || ! current_user_can( 'edit_post', $post->ID ) || ! $this->is_previewable_post_status( $post->post_status ) ) {
			return $actions;
		}

		$actions['previewshare_generate'] = '<a href="' . esc_url( $this->get_admin_action_url( 'previewshare_generate_link', (int) $post->ID ) ) . '">' . esc_html__( 'Generate Preview Link', 'previewshare' ) . '</a>';

		return $actions;
	}

	/**
	 * Handle admin link generation from Classic Editor/list tables.
	 *
	 * @return void
	 */
	public function handle_admin_generate_link(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce is verified by verify_admin_action() immediately after resolving the post ID.
		$post_id = isset( $_GET['post_id'] ) ? absint( $_GET['post_id'] ) : 0;
		$this->verify_admin_action( 'previewshare_generate_link', $post_id );

		$token  = $this->generate_unique_token();
		$ttl    = (int) get_option( 'previewshare_default_ttl_hours', 6 );
		$stored = $this->storage->store_token( $post_id, $token, $ttl, __( 'Admin generated link', 'previewshare' ) );

		if ( $stored ) {
			set_transient(
				'previewshare_admin_notice_' . get_current_user_id(),
				[
					'type' => 'success',
					'url'  => home_url( '/preview/' . $token ),
				],
				10 * MINUTE_IN_SECONDS
			);
		} else {
			set_transient(
				'previewshare_admin_notice_' . get_current_user_id(),
				[
					'type'    => 'error',
					'message' => __( 'Preview link could not be generated.', 'previewshare' ),
				],
				10 * MINUTE_IN_SECONDS
			);
		}

		wp_safe_redirect( $this->get_admin_redirect_url( $post_id ) );
		exit;
	}

	/**
	 * Handle admin link revocation from Classic Editor.
	 *
	 * @return void
	 */
	public function handle_admin_revoke_links(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce is verified by verify_admin_action() immediately after resolving the post ID.
		$post_id = isset( $_GET['post_id'] ) ? absint( $_GET['post_id'] ) : 0;
		$this->verify_admin_action( 'previewshare_revoke_links', $post_id );

		$revoked = $this->storage->revoke_token_for_post( $post_id );
		set_transient(
			'previewshare_admin_notice_' . get_current_user_id(),
			[
				'type'    => $revoked ? 'success' : 'info',
				'message' => $revoked ? __( 'Preview links revoked.', 'previewshare' ) : __( 'No active preview links were found.', 'previewshare' ),
			],
			10 * MINUTE_IN_SECONDS
		);

		wp_safe_redirect( $this->get_admin_redirect_url( $post_id ) );
		exit;
	}

	/**
	 * Render admin notice after server-side actions.
	 *
	 * @return void
	 */
	public function render_admin_notice(): void {
		$key    = 'previewshare_admin_notice_' . get_current_user_id();
		$notice = get_transient( $key );

		if ( ! is_array( $notice ) ) {
			return;
		}

		delete_transient( $key );

		$type  = isset( $notice['type'] ) ? sanitize_key( $notice['type'] ) : 'info';
		$class = in_array( $type, [ 'success', 'error', 'warning', 'info' ], true ) ? $type : 'info';

		echo '<div class="notice notice-' . esc_attr( $class ) . ' is-dismissible">';

		if ( ! empty( $notice['url'] ) ) {
			echo '<p><strong>' . esc_html__( 'Preview link generated.', 'previewshare' ) . '</strong></p>';
			echo '<p><input type="url" readonly class="large-text code" value="' . esc_attr( $notice['url'] ) . '" onclick="this.select();" /></p>';
		} elseif ( ! empty( $notice['message'] ) ) {
			echo '<p>' . esc_html( $notice['message'] ) . '</p>';
		}

		echo '</div>';
	}

	/**
	 * Force preview links out of search indexes.
	 *
	 * @param array<string,bool|string> $robots Robots directives.
	 * @return array<string,bool|string>
	 */
	public function filter_preview_robots( array $robots ): array {
		if ( $this->is_previewshare_request() ) {
			unset(
				$robots['index'],
				$robots['follow'],
				$robots['max-snippet'],
				$robots['max-image-preview'],
				$robots['max-video-preview']
			);

			$robots['noindex']      = true;
			$robots['nofollow']     = true;
			$robots['noarchive']    = true;
			$robots['nosnippet']    = true;
			$robots['noimageindex'] = true;
		}

		return $robots;
	}

	/**
	 * Send robots headers for preview links.
	 *
	 * @return void
	 */
	public function send_preview_robots_header(): void {
		if ( ! $this->is_previewshare_request() || headers_sent() ) {
			return;
		}

		header( 'X-Robots-Tag: noindex, nofollow, noarchive, nosnippet, noimageindex', true );
	}

	/**
	 * Add a body class while preview links are being viewed.
	 *
	 * @param string[] $classes Body classes.
	 * @return string[]
	 */
	public function add_preview_body_class( array $classes ): array {
		if ( $this->is_previewshare_request() ) {
			$classes[] = 'previewshare-preview-active';
		}

		return $classes;
	}

	/**
	 * Render the frontend preview bar styles.
	 *
	 * @return void
	 */
	public function render_preview_bar_styles(): void {
		if ( ! $this->is_previewshare_request() ) {
			return;
		}
		?>
		<style id="previewshare-preview-bar-css">
			html {
				margin-top: 40px !important;
			}

			body.previewshare-preview-active.admin-bar {
				padding-top: 40px;
			}

			.previewshare-preview-bar {
				position: fixed;
				top: 0;
				left: 0;
				right: 0;
				z-index: 99999;
				box-sizing: border-box;
				min-height: 40px;
				padding: 8px 16px;
				display: flex;
				align-items: center;
				justify-content: center;
				background: #1d2327;
				color: #f0f0f1;
				box-shadow: 0 1px 3px rgba(0, 0, 0, 0.25);
				font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif;
				font-size: 13px;
				line-height: 1.4;
				text-align: center;
			}

			body.admin-bar .previewshare-preview-bar {
				top: 32px;
			}

			.previewshare-preview-bar__inner {
				width: 100%;
				max-width: 1200px;
				display: flex;
				flex-wrap: wrap;
				align-items: center;
				justify-content: center;
				gap: 4px 8px;
			}

			.previewshare-preview-bar__label {
				color: #fff;
				font-weight: 600;
			}

			.previewshare-preview-bar__message {
				color: #f0f0f1;
			}

			@media screen and (max-width: 782px) {
				body.admin-bar .previewshare-preview-bar {
					top: 46px;
				}
			}
		</style>
		<?php
	}

	/**
	 * Render the frontend preview bar.
	 *
	 * @return void
	 */
	public function render_preview_bar(): void {
		if ( ! $this->is_previewshare_request() || $this->preview_bar_rendered ) {
			return;
		}

		$this->preview_bar_rendered = true;
		?>
		<div class="previewshare-preview-bar" role="status" aria-live="polite">
			<div class="previewshare-preview-bar__inner">
				<strong class="previewshare-preview-bar__label"><?php esc_html_e( 'Draft preview', 'previewshare' ); ?></strong>
				<span class="previewshare-preview-bar__message"><?php esc_html_e( 'This content is shared privately. Search engines are blocked from indexing this preview link.', 'previewshare' ); ?></span>
			</div>
		</div>
		<?php
	}

	/**
	 * Check whether the current request is for a PreviewShare URL.
	 *
	 * @return bool
	 */
	private function is_previewshare_request(): bool {
		if ( get_query_var( 'previewshare_token' ) ) {
			return true;
		}

		global $wp;

		return isset( $wp->query_vars['previewshare_token'] ) && (bool) $wp->query_vars['previewshare_token'];
	}

	/**
	 * Verify a server-side admin action.
	 *
	 * @param string $action Action name.
	 * @param int    $post_id Post ID.
	 * @return void
	 */
	private function verify_admin_action( string $action, int $post_id ): void {
		if ( ! $post_id || ! get_post( $post_id ) ) {
			wp_die( esc_html__( 'Invalid post.', 'previewshare' ), '', [ 'response' => 400 ] );
		}

		$post = get_post( $post_id );

		if ( ! \previewshare_is_supported_post_type( $post->post_type ) || ! $this->is_previewable_post_status( $post->post_status ) ) {
			wp_die( esc_html__( 'PreviewShare is not available for this content.', 'previewshare' ), '', [ 'response' => 400 ] );
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			wp_die( esc_html__( 'You are not allowed to manage preview links for this content.', 'previewshare' ), '', [ 'response' => 403 ] );
		}

		check_admin_referer( $action . '_' . $post_id );
	}

	/**
	 * Build an admin action URL.
	 *
	 * @param string $action Action name.
	 * @param int    $post_id Post ID.
	 * @return string
	 */
	private function get_admin_action_url( string $action, int $post_id ): string {
		return wp_nonce_url(
			add_query_arg(
				[
					'action'  => $action,
					'post_id' => $post_id,
				],
				admin_url( 'admin-post.php' )
			),
			$action . '_' . $post_id
		);
	}

	/**
	 * Get redirect URL after admin action.
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	private function get_admin_redirect_url( int $post_id ): string {
		$referer = wp_get_referer();

		if ( $referer ) {
			return $referer;
		}

		$edit_link = get_edit_post_link( $post_id, 'raw' );

		return $edit_link ? $edit_link : admin_url( 'edit.php' );
	}
}
