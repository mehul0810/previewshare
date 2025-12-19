<?php
/**
 * Post meta based storage driver for preview tokens.
 *
 * @package PreviewShare
 */

namespace PreviewShare\Services;

// Abort if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class PostMetaStorage
 *
 * Lightweight storage implementation using post meta. Intended as a simple
 * driver to be swapped out for a custom table in the future.
 */
class PostMetaStorage {

    /**
     * Store a raw token for a post (keeps raw token for backward compatibility).
     * In future we will store only hashes.
     *
     * @param int    $post_id Post ID.
     * @param string $token Raw token.
     * @return void
     */
    public function store_token( int $post_id, string $token ): void {
        update_post_meta( $post_id, '_previewshare_token', $token );
        update_post_meta( $post_id, '_previewshare_created', time() );
        update_post_meta( $post_id, '_previewshare_user_id', get_current_user_id() );
    }

    /**
     * Get post ID by raw token lookup in postmeta. Returns int|false.
     *
     * @param string $token Raw token.
     * @return int|false
     */
    public function get_post_id_by_token( string $token ) {
        global $wpdb;

        $post_id = $wpdb->get_var( $wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_previewshare_token' AND meta_value = %s LIMIT 1",
            $token
        ) );

        return $post_id ? (int) $post_id : false;
    }

    /**
     * Get token metadata for a post.
     *
     * @param int $post_id Post ID.
     * @return array{created:int,user_id:int}|null
     */
    public function get_token_meta( int $post_id ): ?array {
        $created = get_post_meta( $post_id, '_previewshare_created', true );
        $user_id = get_post_meta( $post_id, '_previewshare_user_id', true );

        if ( empty( $created ) ) {
            return null;
        }

        return [
            'created' => (int) $created,
            'user_id' => (int) $user_id,
        ];
    }
}
