<?php
/**
 * Legacy PostMetaStorage (deprecated).
 *
 * The plugin now uses the custom table storage driver. This file remains as a
 * no-op shim to avoid accidental fatal errors if referenced; all methods are
 * deprecated and will emit a notice if called.
 *
 * @package PreviewShare
 */

namespace PreviewShare\Services;

// Bailout if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PostMetaStorage {
    public function __construct() {
        _deprecated_file( __FILE__, '1.0.0', 'Use CustomTableStorage instead.' );
    }

    public function store_token( int $post_id, string $token ): bool {
        _deprecated_function( __FUNCTION__, '1.0.0', 'Use CustomTableStorage::store_token' );
        return false;
    }

    public function get_post_id_by_token( string $token ) {
        _deprecated_function( __FUNCTION__, '1.0.0', 'Use CustomTableStorage::get_post_id_by_token' );
        return false;
    }

    public function get_token_meta( int $post_id ): ?array {
        _deprecated_function( __FUNCTION__, '1.0.0', 'Use CustomTableStorage::get_token_meta' );
        return null;
    }
}
