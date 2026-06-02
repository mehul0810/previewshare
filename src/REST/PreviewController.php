<?php
/**
 * Preview REST controller.
 *
 * @package PreviewShare
 */

namespace PreviewShare\REST;

use PreviewShare\Container;
use PreviewShare\Services\TokenService;
use PreviewShare\Services\PostMetaStorage;

// Abort if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class PreviewController
 */
class PreviewController {

	/**
	 * Storage instance.
	 *
	 * @var PostMetaStorage
	 */
	private $storage;

	/**
	 * Constructor.
	 *
	 * @param TokenService    $token_service Token helper.
	 * @param PostMetaStorage $storage Storage driver.
	 */
	public function __construct( TokenService $token_service, PostMetaStorage $storage ) {
		$this->storage = $storage;
		Container::set( 'token_service', $token_service );
		Container::set( 'storage', $storage );

		// Register routes on rest_api_init (normal) and also immediately
		// if rest_api_init already fired (defensive - ensures route
		// availability if constructed later in the request lifecycle).
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );

		if ( did_action( 'rest_api_init' ) ) {
			$this->register_routes();
		}
	}

	/**
	 * Register REST routes for preview management.
	 */
	public function register_routes(): void {
		register_rest_route(
			'previewshare/v1',
			'/v2/generate',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'generate' ],
				'permission_callback' => [ $this, 'permission_generate' ],
				'args'                => [
					'post_id' => [
						'required'          => true,
						'validate_callback' => function ( $value ) {
							return is_numeric( $value ) && $value > 0;
						},
					],
					'ttl_hours' => [
						'required' => false,
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

		register_rest_route(
			'previewshare/v1',
			'/v2/revoke',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'revoke' ],
				'permission_callback' => [ $this, 'permission_revoke' ],
				'args'                => [
					'post_id' => [
						'required'          => false,
						'validate_callback' => function ( $value ) {
							return is_numeric( $value ) && $value > 0;
						},
					],
					'token' => [
						'required' => false,
						'type'     => 'string',
					],
				],
			]
		);

		// List tokens in the admin settings screen.
		register_rest_route(
			'previewshare/v1',
			'/tokens',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'list_tokens' ],
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
				'args'                => [
					'per_page' => [
						'required' => false,
						'default' => 50,
					],
					'page'     => [
						'required' => false,
						'default' => 1,
					],
				],
			]
		);

		// Revoke a token by stored ID/hash in the admin settings screen.
		register_rest_route(
			'previewshare/v1',
			'/tokens/revoke',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'revoke_by_id' ],
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
				'args'                => [
					'id' => [
						'required' => true,
						'type'     => 'string',
					],
				],
			]
		);

		// No legacy post meta REST routes are exposed.

		// Settings get/update routes.
		register_rest_route(
			'previewshare/v1',
			'/settings',
			[
				[
					'methods'             => 'GET',
					'callback'            => [ $this, 'get_settings' ],
					'permission_callback' => function () {
						return current_user_can( 'manage_options' );
					},
				],
				[
					'methods'             => 'POST',
					'callback'            => [ $this, 'update_settings' ],
					'permission_callback' => function () {
						return current_user_can( 'manage_options' );
					},
					'args'                => [
						'default_ttl_hours' => [
							'required' => false,
							'type' => 'integer',
						],
						'enable_logging'    => [
							'required' => false,
							'type' => 'boolean',
						],
						'enable_caching'    => [
							'required' => false,
							'type' => 'boolean',
						],
						'post_types'        => [
							'required' => false,
							'type' => 'array',
						],
						'reset_defaults'    => [
							'required' => false,
							'type' => 'boolean',
						],
					],
				],
			]
		);
	}

	/**
	 * List tokens callback.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function list_tokens( $request ) {
		$requested_per_page = absint( $request->get_param( 'per_page' ) );
		$requested_page     = absint( $request->get_param( 'page' ) );
		$per_page           = min( 100, $requested_per_page ? $requested_per_page : 50 );
		$page               = $requested_page ? $requested_page : 1;
		// Pull rows from post meta storage.
		$rows  = $this->storage->list_tokens( $per_page, $page );
		$total = $this->storage->count_tokens();

		// Enrich each token with the related post title.
		$items = array_map(
			function ( $row ) {
				$post     = get_post( $row['post_id'] );
				$edit_url = $post ? get_edit_post_link( $post->ID, 'raw' ) : '';

				return [
					'id'             => isset( $row['id'] ) ? (string) $row['id'] : '',
					'post_id'        => (int) $row['post_id'],
					'post_title'     => $post ? get_the_title( $post ) : '(deleted)',
					'edit_url'       => $edit_url ? $edit_url : '',
					'label'          => isset( $row['label'] ) ? (string) $row['label'] : '',
					'created_at'     => isset( $row['created_at'] ) ? (int) $row['created_at'] : 0,
					'expires_at'     => isset( $row['expires_at'] ) ? $row['expires_at'] : null,
					'revoked'        => (bool) $row['revoked'],
					'expired'        => ! empty( $row['expired'] ),
					'status'         => isset( $row['status'] ) ? (string) $row['status'] : 'active',
					'view_count'     => isset( $row['view_count'] ) ? (int) $row['view_count'] : 0,
					'last_viewed_at' => isset( $row['last_viewed_at'] ) ? $row['last_viewed_at'] : null,
				];
			},
			$rows
		);

		\previewshare_log(
			'list_tokens',
			[
				'user_id'  => get_current_user_id(),
				'page'     => $page,
				'per_page' => $per_page,
			]
		);

		return new \WP_REST_Response(
			[
				'items' => $items,
				'total' => $total,
			],
			200
		);
	}

	/**
	 * Revoke token by DB id.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function revoke_by_id( $request ) {
		$id = sanitize_key( (string) $request->get_param( 'id' ) );

		if ( ! $id ) {
			return new \WP_Error( 'invalid_id', 'Invalid token id', [ 'status' => 400 ] );
		}

		$revoked = $this->storage->revoke_token_by_id( $id );

		\previewshare_log(
			'revoke_by_id',
			[
				'user_id' => get_current_user_id(),
				'id'      => $id,
				'revoked' => (bool) $revoked,
			]
		);

		return new \WP_REST_Response( [ 'revoked' => (bool) $revoked ], 200 );
	}


	/**
	 * Return current plugin settings.
	 *
	 * @return \WP_REST_Response
	 */
	public function get_settings() {
		\previewshare_maybe_initialize_default_settings();

		return new \WP_REST_Response( \previewshare_get_settings(), 200 );
	}

	/**
	 * Update plugin settings.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function update_settings( $request ) {
		$reset_defaults = $request->get_param( 'reset_defaults' );

		if ( $reset_defaults ) {
			$settings = \previewshare_reset_default_settings();
		} else {
			$settings_to_update = [];

			foreach ( [ 'default_ttl_hours', 'enable_logging', 'enable_caching', 'post_types' ] as $key ) {
				if ( null !== $request->get_param( $key ) ) {
					$settings_to_update[ $key ] = $request->get_param( $key );
				}
			}

			$settings = \previewshare_update_settings( $settings_to_update );
		}

		\previewshare_log(
			'update_settings',
			[
				'user_id' => get_current_user_id(),
			]
		);

		return new \WP_REST_Response( $settings, 200 );
	}

	/**
	 * Permissions callback for generation/revocation.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return bool
	 */
	public function permission_generate( $request ): bool {
		$post_id = isset( $request['post_id'] ) ? absint( $request['post_id'] ) : 0;

		if ( $post_id ) {
			return current_user_can( 'edit_post', $post_id );
		}

		return false;
	}

	/**
	 * Permissions callback for revocation.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return bool
	 */
	public function permission_revoke( $request ): bool {
		$post_id = isset( $request['post_id'] ) ? absint( $request['post_id'] ) : 0;

		if ( $post_id ) {
			return current_user_can( 'edit_post', $post_id );
		}

		return current_user_can( 'manage_options' );
	}

	/**
	 * Create a preview token and persist it.
	 *
	 * @param \WP_REST_Request<array<string,mixed>> $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function generate( $request ) {
		$post_id = absint( $request->get_param( 'post_id' ) );
		$ttl     = $request->get_param( 'ttl_hours' );
		$result  = \previewshare_generate_preview_link(
			$post_id,
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
	 * Revoke token endpoint (simple implementation).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function revoke( $request ) {
		$post_id = absint( $request->get_param( 'post_id' ) );
		if ( $post_id ) {
			$revoked = $this->storage->revoke_token_for_post( $post_id );

			return new \WP_REST_Response( [ 'revoked' => (bool) $revoked ], 200 );
		}

		$token = $request->get_param( 'token' );

		if ( empty( $token ) ) {
			return new \WP_Error( 'invalid_token', 'Token is required', [ 'status' => 400 ] );
		}

		// Use storage driver revoke implementation.
		$revoked = $this->storage->revoke_token( $token );

		return new \WP_REST_Response( [ 'revoked' => (bool) $revoked ], 200 );
	}
}
