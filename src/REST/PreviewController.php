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
            ],
        ] );

        register_rest_route( 'previewshare/v1', '/v2/revoke', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'revoke' ],
            'permission_callback' => [ $this, 'permission_generate' ],
        ] );
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

        $token = $this->token_service->generate();
        $this->storage->store_token( $post_id, $token );

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
