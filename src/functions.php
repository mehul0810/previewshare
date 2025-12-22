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
 * @param int $post_id Post ID.
 * @return string|false Raw token on success, false on failure.
 */
function previewshare_generate_token_for_post( int $post_id, ?int $ttl_hours = null ) {
    $token_service = Container::token_service();
    $storage = Container::storage();
    if ( ! $token_service || ! $storage ) {
        return false;
    }

    $token = $token_service->generate();

    $ttl = is_null( $ttl_hours ) ? (int) get_option( 'previewshare_default_ttl_hours', 24 ) : absint( $ttl_hours );
    $storage->store_token( $post_id, $token, $ttl );

    return $token;
}
