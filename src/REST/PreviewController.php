<?php
/**
 * Preview REST controller.
 *
 * @package PreviewShare
 */

namespace PreviewShare\REST;

use PreviewShare\Services\TokenService;
use PreviewShare\Services\CustomTableStorage;

// Abort if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class PreviewController
 */
class PreviewController {

    /**
     * Token service instance.
     *
     * @var TokenService
     */
    private $token_service;

    /**
     * Storage instance.
     *
     * @var CustomTableStorage
     */
    private $storage;

    /**
     * Constructor.
     *
     * @param TokenService         $token_service Token helper.
     * @param CustomTableStorage   $storage Storage driver.
     */
    public function __construct( TokenService $token_service, CustomTableStorage $storage ) {
        $this->token_service = $token_service;
        $this->storage       = $storage;

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
        register_rest_route( 'previewshare/v1', '/v2/generate', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'generate' ],
            'permission_callback' => [ $this, 'permission_generate' ],
            'args'                => [
                'post_id' => [
                    'required'          => true,
                    'validate_callback' => function( $value ) {
                        return is_numeric( $value ) && $value > 0;
                    },
                ],
                'ttl_hours' => [
                    'required' => false,
                    'validate_callback' => function( $value ) {
                        return is_numeric( $value ) && $value >= 0;
                    },
                ],
            ],
        ] );

        register_rest_route( 'previewshare/v1', '/v2/revoke', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'revoke' ],
            'permission_callback' => [ $this, 'permission_generate' ],
        ] );

        // List tokens (admin)
        register_rest_route( 'previewshare/v1', '/tokens', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'list_tokens' ],
            'permission_callback' => function() {
                return current_user_can( 'manage_options' );
            },
            'args'                => [
                'per_page' => [ 'required' => false, 'default' => 50 ],
                'page'     => [ 'required' => false, 'default' => 1 ],
            ],
        ] );

        // Revoke token by DB id (admin)
        register_rest_route( 'previewshare/v1', '/tokens/revoke', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'revoke_by_id' ],
            'permission_callback' => function() {
                return current_user_can( 'manage_options' );
            },
            'args'                => [
                'id' => [
                    'required' => true,
                    'validate_callback' => function( $v ) {
                        return is_numeric( $v ) && $v > 0;
                    },
                ],
            ],
        ] );

        // No legacy postmeta routes - plugin now uses custom table only.

        // Settings get/update
        register_rest_route( 'previewshare/v1', '/settings', [
            [
                'methods'             => 'GET',
                'callback'            => [ $this, 'get_settings' ],
                'permission_callback' => function() {
                    return current_user_can( 'manage_options' );
                },
            ],
            [
                'methods'             => 'POST',
                'callback'            => [ $this, 'update_settings' ],
                'permission_callback' => function() {
                    return current_user_can( 'manage_options' );
                },
                'args'                => [
                    'default_ttl_hours' => [ 'required' => false, 'type' => 'integer' ],
                    'enable_logging'    => [ 'required' => false, 'type' => 'boolean' ],
                    'enable_caching'    => [ 'required' => false, 'type' => 'boolean' ],
                ],
            ],
        ] );
    }

    /**
     * List tokens callback.
     *
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response
     */
    public function list_tokens( $request ) {
        $per_page = absint( $request->get_param( 'per_page' ) ) ?: 50;
        $page     = absint( $request->get_param( 'page' ) ) ?: 1;
        // Pull rows from custom table only (no legacy postmeta handling).
        $rows = $this->storage->list_tokens( $per_page, $page );
        $total = $this->storage->count_tokens();

        // Enrich with post title
        $items = array_map( function( $row ) {
            $post = get_post( $row['post_id'] );
            return [
                'id'        => isset( $row['id'] ) ? (int) $row['id'] : 0,
                'post_id'   => (int) $row['post_id'],
                'post_title'=> $post ? get_the_title( $post ) : '(deleted)',
                'created_at'=> isset( $row['created_at'] ) ? (int) $row['created_at'] : 0,
                'expires_at'=> isset( $row['expires_at'] ) ? (int) $row['expires_at'] : null,
                'revoked'   => (bool) $row['revoked'],
            ];
        }, $rows );

        if ( get_option( 'previewshare_enable_logging', false ) ) {
            error_log( '[PreviewShare] list_tokens called by user ' . get_current_user_id() . ' page=' . $page . ' per_page=' . $per_page );
        }

        return new \WP_REST_Response( [ 'items' => $items, 'total' => $total ], 200 );
    }

    /**
     * Revoke token by DB id.
     *
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response
     */
    public function revoke_by_id( $request ) {
        $id = absint( $request->get_param( 'id' ) );

        if ( ! $id ) {
            return new \WP_Error( 'invalid_id', 'Invalid token id', [ 'status' => 400 ] );
        }

        $revoked = $this->storage->revoke_token_by_id( $id );

        if ( get_option( 'previewshare_enable_logging', false ) ) {
            error_log( '[PreviewShare] revoke_by_id called by user ' . get_current_user_id() . ' id=' . $id . ' result=' . (int) $revoked );
        }

        return new \WP_REST_Response( [ 'revoked' => (bool) $revoked ], 200 );
    }


    /**
     * Return current plugin settings.
     *
     * @return \WP_REST_Response
     */
    public function get_settings() {
        $settings = [
            'default_ttl_hours' => (int) get_option( 'previewshare_default_ttl_hours', 24 ),
            'enable_logging'    => (bool) get_option( 'previewshare_enable_logging', false ),
            'enable_caching'    => (bool) get_option( 'previewshare_enable_caching', true ),
        ];

        return new \WP_REST_Response( $settings, 200 );
    }

    /**
     * Update plugin settings.
     *
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response
     */
    public function update_settings( $request ) {
        $ttl = $request->get_param( 'default_ttl_hours' );
        $log = $request->get_param( 'enable_logging' );
        $cache = $request->get_param( 'enable_caching' );

        // Sanitize and persist only the provided keys.
        if ( null !== $ttl ) {
            $sanitized_ttl = absint( $ttl );
            update_option( 'previewshare_default_ttl_hours', $sanitized_ttl );
        }

        if ( null !== $log ) {
            $sanitized_log = filter_var( $log, FILTER_VALIDATE_BOOLEAN );
            update_option( 'previewshare_enable_logging', (bool) $sanitized_log );
        }

        if ( null !== $cache ) {
            $sanitized_cache = filter_var( $cache, FILTER_VALIDATE_BOOLEAN );
            update_option( 'previewshare_enable_caching', (bool) $sanitized_cache );
        }

        // Return the current settings so the client can refresh its state.
        $settings = [
            'default_ttl_hours' => (int) get_option( 'previewshare_default_ttl_hours', 24 ),
            'enable_logging'    => (bool) get_option( 'previewshare_enable_logging', false ),
            'enable_caching'    => (bool) get_option( 'previewshare_enable_caching', true ),
        ];

        if ( get_option( 'previewshare_enable_logging', false ) ) {
            error_log( '[PreviewShare] update_settings called by user ' . get_current_user_id() );
        }

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

        return current_user_can( 'edit_posts' );
    }

    /**
     * Create a preview token and persist it.
     *
     * @param \WP_REST_Request $request Request.
     * @return \WP_REST_Response
     */
    public function generate( $request ) {
        $post_id = absint( $request->get_param( 'post_id' ) );

        if ( ! $post_id || ! get_post( $post_id ) ) {
            return new \WP_Error( 'invalid_post', 'Invalid post ID', [ 'status' => 400 ] );
        }

        $ttl = $request->get_param( 'ttl_hours' );
        $ttl = is_null( $ttl ) ? (int) get_option( 'previewshare_default_ttl_hours', 24 ) : absint( $ttl );

        $token = $this->token_service->generate();
        $this->storage->store_token( $post_id, $token, $ttl );

        $preview_url = home_url( '/preview/' . $token );

        return new \WP_REST_Response( [ 'url' => $preview_url, 'token' => $token ], 200 );
    }

    /**
     * Revoke token endpoint (simple implementation).
     *
     * @param \WP_REST_Request $request Request.
     * @return \WP_REST_Response
     */
    public function revoke( $request ) {
        $token = $request->get_param( 'token' );

        if ( empty( $token ) ) {
            return new \WP_Error( 'invalid_token', 'Token is required', [ 'status' => 400 ] );
        }

        // Use storage driver revoke implementation.
        $revoked = $this->storage->revoke_token( $token );

        return new \WP_REST_Response( [ 'revoked' => (bool) $revoked ], 200 );
    }
}
