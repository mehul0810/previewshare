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

	private const FAILURE_MISSING_TOKEN      = 'missing_token';
	private const FAILURE_TOKEN_NOT_FOUND    = 'token_not_found';
	private const FAILURE_TOKEN_REVOKED      = 'token_revoked';
	private const FAILURE_TOKEN_EXPIRED      = 'token_expired';
	private const FAILURE_LINK_DISABLED      = 'link_disabled';
	private const FAILURE_MISSING_POST       = 'missing_post';
	private const FAILURE_UNSUPPORTED_POST   = 'unsupported_post_type';
	private const FAILURE_UNSUPPORTED_STATUS = 'unsupported_post_status';

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
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_preview_bar_styles' ] );
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
		$ttl    = $request->get_param( 'ttl_hours' );
		$result = \previewshare_generate_preview_link(
			absint( $request->get_param( 'post_id' ) ),
			null === $ttl ? null : absint( $ttl ),
			(string) $request->get_param( 'label' )
		);

		if ( $result instanceof \WP_Error ) {
			return $result;
		}

		return new \WP_REST_Response(
			[
				'url'   => $result['url'],
				'token' => $result['token'],
			],
			200
		);
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

		$token = (string) get_query_var( 'previewshare_token' );
		if ( '' === $token ) {
			return;
		}

		$this->send_preview_robots_header();

		$diagnostic = $this->storage->get_token_diagnostic( $token );
		$post_id    = isset( $diagnostic['post_id'] ) ? (int) $diagnostic['post_id'] : 0;

		if ( self::FAILURE_MISSING_TOKEN === $diagnostic['reason_code'] || self::FAILURE_TOKEN_NOT_FOUND === $diagnostic['reason_code'] ) {
			$this->fail_preview_request( (string) $diagnostic['reason_code'], $post_id );
		}

		if ( self::FAILURE_TOKEN_REVOKED === $diagnostic['reason_code'] || self::FAILURE_TOKEN_EXPIRED === $diagnostic['reason_code'] ) {
			$this->fail_preview_request( (string) $diagnostic['reason_code'], $post_id );
		}

		if ( ! $post_id ) {
			$this->fail_preview_request( self::FAILURE_TOKEN_NOT_FOUND );
		}

		if ( ! get_post_meta( $post_id, '_previewshare_enabled', true ) ) {
			$this->fail_preview_request( self::FAILURE_LINK_DISABLED, $post_id );
		}

		$meta = $this->storage->get_token_meta( $post_id );
		if ( empty( $meta ) ) {
			$this->fail_preview_request( self::FAILURE_TOKEN_NOT_FOUND, $post_id );
		}

		if ( ! empty( $meta['revoked'] ) ) {
			$this->fail_preview_request( self::FAILURE_TOKEN_REVOKED, $post_id );
		}

		if ( ! empty( $meta['expired'] ) ) {
			$this->fail_preview_request( self::FAILURE_TOKEN_EXPIRED, $post_id );
		}

		$post = get_post( $post_id );
		if ( ! $post ) {
			$this->fail_preview_request( self::FAILURE_MISSING_POST, $post_id );
		}

		if ( ! \previewshare_is_supported_post_type( $post->post_type ) ) {
			$this->fail_preview_request( self::FAILURE_UNSUPPORTED_POST, $post_id );
		}

		if ( ! \previewshare_is_previewable_post_status( $post->post_status ) ) {
			$this->fail_preview_request( self::FAILURE_UNSUPPORTED_STATUS, $post_id );
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
	 * Render a safe public failure screen and emit opt-in diagnostics.
	 *
	 * @param string $reason_code Stable internal failure reason.
	 * @param int    $post_id Optional related post ID when known.
	 * @return void
	 */
	private function fail_preview_request( string $reason_code, int $post_id = 0 ): void {
		$reason_code = sanitize_key( $reason_code );

		\previewshare_log(
			'preview_request_failed',
			[
				'reason_code' => $reason_code,
				'post_id'     => $post_id > 0 ? $post_id : null,
			]
		);

		/**
		 * Fires when a public preview request cannot be served.
		 *
		 * The raw token and token hash are intentionally omitted.
		 *
		 * @param string $reason_code Stable internal failure reason.
		 * @param int    $post_id Related post ID, or 0 when unknown.
		 */
		do_action( 'previewshare_preview_request_failed', $reason_code, $post_id );

		wp_die(
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Message HTML is built from escaped translation strings in get_preview_failure_message().
			$this->get_preview_failure_message(),
			esc_html__( 'Preview link unavailable', 'previewshare' ),
			[ 'response' => 410 ]
		);
	}

	/**
	 * Build the anonymous-safe preview failure message.
	 *
	 * @return string
	 */
	private function get_preview_failure_message(): string {
		return '<h1>' . esc_html__( 'Preview link unavailable', 'previewshare' ) . '</h1>'
			. '<p>' . esc_html__( 'This preview link can no longer be opened. It may have expired, been revoked, or no longer match available content.', 'previewshare' ) . '</p>'
			. '<p>' . esc_html__( 'Please ask the person who sent the link to generate and share a new PreviewShare link.', 'previewshare' ) . '</p>';
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
		$has_token  = (bool) get_post_meta( $post_id, '_previewshare_token_hash', true );
		$diagnostic = $this->get_post_recovery_guidance( $post_id, $post, $meta, $has_token );

		return new \WP_REST_Response(
			[
				'has_token'  => $has_token,
				'meta'       => $meta,
				'diagnostic' => $diagnostic,
			],
			200
		);
	}

	/**
	 * Get admin/editor recovery guidance for a post's current link state.
	 *
	 * @param int                      $post_id Post ID.
	 * @param \WP_Post                 $post Post object.
	 * @param array<string,mixed>|null $meta Token meta.
	 * @param bool                     $has_token Whether an indexed token exists.
	 * @return array{reason_code:string,message:string,action:string}
	 */
	private function get_post_recovery_guidance( int $post_id, \WP_Post $post, ?array $meta, bool $has_token ): array {
		unset( $post_id );

		if ( ! \previewshare_is_previewable_post_status( $post->post_status ) ) {
			return [
				'reason_code' => self::FAILURE_UNSUPPORTED_STATUS,
				'message'     => __( 'Preview links are unavailable for this content status.', 'previewshare' ),
				'action'      => __( 'Move the content to a draft, pending, scheduled, private, or published status before generating a link.', 'previewshare' ),
			];
		}

		if ( empty( $meta ) || ! $has_token ) {
			return [
				'reason_code' => self::FAILURE_TOKEN_NOT_FOUND,
				'message'     => __( 'No active preview link is available for this content.', 'previewshare' ),
				'action'      => __( 'Generate a new PreviewShare link and send the new URL to reviewers.', 'previewshare' ),
			];
		}

		if ( ! empty( $meta['revoked'] ) ) {
			return [
				'reason_code' => self::FAILURE_TOKEN_REVOKED,
				'message'     => __( 'The latest preview link was revoked.', 'previewshare' ),
				'action'      => __( 'Generate a new PreviewShare link before sharing this content again.', 'previewshare' ),
			];
		}

		if ( ! empty( $meta['expired'] ) ) {
			return [
				'reason_code' => self::FAILURE_TOKEN_EXPIRED,
				'message'     => __( 'The latest preview link has expired.', 'previewshare' ),
				'action'      => __( 'Generate a new PreviewShare link or increase the expiry time before sharing.', 'previewshare' ),
			];
		}

		return [
			'reason_code' => 'active',
			'message'     => __( 'Preview links are available for this content.', 'previewshare' ),
			'action'      => __( 'Copy an active link or generate a fresh one when reviewers need access.', 'previewshare' ),
		];
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
		if ( ! current_user_can( 'edit_post', $post->ID ) || ! \previewshare_is_previewable_post_status( $post->post_status ) ) {
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
		} elseif ( ! empty( $meta['revoked'] ) || ! empty( $meta['expired'] ) ) {
			echo '<p class="description">' . esc_html__( 'The latest preview link is no longer usable. Generate a new link before sharing this content again.', 'previewshare' ) . '</p>';
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
		if ( ! \previewshare_is_supported_post_type( $post->post_type ) || ! current_user_can( 'edit_post', $post->ID ) || ! \previewshare_is_previewable_post_status( $post->post_status ) ) {
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

		$result = \previewshare_generate_preview_link(
			$post_id,
			(int) get_option( 'previewshare_default_ttl_hours', 6 ),
			__( 'Admin generated link', 'previewshare' )
		);

		if ( ! ( $result instanceof \WP_Error ) ) {
			set_transient(
				'previewshare_admin_notice_' . get_current_user_id(),
				[
					'type' => 'success',
					'url'  => $result['url'],
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
	 * Enqueue the frontend preview bar styles.
	 *
	 * @return void
	 */
	public function enqueue_preview_bar_styles(): void {
		if ( ! $this->is_previewshare_request() ) {
			return;
		}

		$version = defined( 'PREVIEWSHARE_VERSION' ) ? (string) constant( 'PREVIEWSHARE_VERSION' ) : '1.0.0';

		wp_register_style( 'previewshare-preview-bar', false, [], $version );
		wp_enqueue_style( 'previewshare-preview-bar' );
		wp_add_inline_style( 'previewshare-preview-bar', $this->get_preview_bar_styles() );
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
	 * Get the frontend preview bar CSS.
	 *
	 * @return string CSS rules.
	 */
	private function get_preview_bar_styles(): string {
		return '
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
';
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

		if ( ! \previewshare_is_supported_post_type( $post->post_type ) || ! \previewshare_is_previewable_post_status( $post->post_status ) ) {
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
