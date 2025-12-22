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
    /**
     * Store a token for a post using postmeta.
     *
     * @param int    $post_id
     * @param string $token Raw token
     * @param int    $ttl_hours Hours until expiration. 0 for no expiry.
     * @return bool
     */
    public function store_token( int $post_id, string $token, int $ttl_hours = 24 ): bool {
        $token_service = new TokenService();
        $hash = $token_service->hash( $token );
        $now = time();
        $expires = $ttl_hours > 0 ? ( $now + ( $ttl_hours * HOUR_IN_SECONDS ) ) : null;

        $meta_key_index = '_previewshare_token_hash';
        $meta_key_detail = '_previewshare_token:' . $hash;

        $user_id = get_current_user_id() ?: null;

        // Store the index (allows searching post_id by hash across posts).
        $result_index = add_post_meta( $post_id, $meta_key_index, $hash );

        // Store detailed meta per token hash for this post.
        $detail = [
            'created_at' => $now,
            'user_id'    => $user_id,
            'expires_at' => $expires,
            'revoked'    => 0,
        ];

        $result_detail = add_post_meta( $post_id, $meta_key_detail, $detail );

        if ( $result_index ) {
            // Warm object cache for quicker lookup similar to custom table driver.
            wp_cache_set( $hash, (int) $post_id, 'previewshare_tokens', HOUR_IN_SECONDS );
        }

        return (bool) ( $result_index && $result_detail );
    }

    /**
     * Get post ID by raw token. Returns int|false.
     *
     * @param string $token Raw token
     * @return int|false
     */
    public function get_post_id_by_token( string $token ) {
        global $wpdb;

        $token_service = new TokenService();
        $hash = $token_service->hash( $token );

        // Check object cache first.
        $cached = wp_cache_get( $hash, 'previewshare_tokens' );
        if ( $cached !== false ) {
            return (int) $cached;
        }

        $meta_key_index = '_previewshare_token_hash';

        $sql = $wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s LIMIT 1",
            $meta_key_index,
            $hash
        );

        $post_id = $wpdb->get_var( $sql );

        if ( ! $post_id ) {
            return false;
        }

        // Fetch detail meta and validate not revoked and not expired.
        $detail = get_post_meta( (int) $post_id, '_previewshare_token:' . $hash, true );

        if ( empty( $detail ) || ! is_array( $detail ) ) {
            return false;
        }

        $now = time();

        if ( ! empty( $detail['revoked'] ) ) {
            return false;
        }

        if ( ! empty( $detail['expires_at'] ) && (int) $detail['expires_at'] <= $now ) {
            return false;
        }

        wp_cache_set( $hash, (int) $post_id, 'previewshare_tokens', HOUR_IN_SECONDS );

        return (int) $post_id;
    }

    /**
     * Get token meta for a post (created_at, user_id, expires_at).
     *
     * @param int $post_id
     * @return array|null
     */
    public function get_token_meta( int $post_id ): ?array {
        global $wpdb;

        $meta_key_index = '_previewshare_token_hash';

        // Get latest token hash for the post (by meta_id desc)
        $sql = $wpdb->prepare(
            "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s ORDER BY meta_id DESC LIMIT 1",
            $post_id,
            $meta_key_index
        );

        $hash = $wpdb->get_var( $sql );

        if ( ! $hash ) {
            return null;
        }

        $detail = get_post_meta( $post_id, '_previewshare_token:' . $hash, true );

        if ( empty( $detail ) || ! is_array( $detail ) ) {
            return null;
        }

        return [
            'created' => isset( $detail['created_at'] ) ? (int) $detail['created_at'] : 0,
            'user_id' => isset( $detail['user_id'] ) ? (int) $detail['user_id'] : 0,
            'expires' => ! empty( $detail['expires_at'] ) ? (int) $detail['expires_at'] : null,
        ];
    }

    /**
     * List tokens with pagination.
     * Returns array of rows with meta_id as id, post_id, token_hash, user_id, created_at, expires_at, revoked
     *
     * @param int $per_page
     * @param int $page
     * @return array
     */
    public function list_tokens( int $per_page = 50, int $page = 1 ): array {
        global $wpdb;

        $meta_key_index = '_previewshare_token_hash';
        $offset = max( 0, ( $page - 1 ) * $per_page );

        $sql = $wpdb->prepare(
            "SELECT meta_id AS id, post_id, meta_value AS token_hash FROM {$wpdb->postmeta} WHERE meta_key = %s ORDER BY meta_id DESC LIMIT %d OFFSET %d",
            $meta_key_index,
            $per_page,
            $offset
        );

        $rows = $wpdb->get_results( $sql, ARRAY_A );

        if ( ! $rows ) {
            return [];
        }

        $out = [];

        foreach ( $rows as $row ) {
            $post_id = (int) $row['post_id'];
            $hash = $row['token_hash'];
            $detail = get_post_meta( $post_id, '_previewshare_token:' . $hash, true );

            $out[] = [
                'id'         => isset( $row['id'] ) ? (int) $row['id'] : 0,
                'post_id'    => $post_id,
                'token_hash' => $hash,
                'user_id'    => isset( $detail['user_id'] ) ? (int) $detail['user_id'] : 0,
                'created_at' => isset( $detail['created_at'] ) ? (int) $detail['created_at'] : 0,
                'expires_at' => isset( $detail['expires_at'] ) ? (int) $detail['expires_at'] : null,
                'revoked'    => ! empty( $detail['revoked'] ),
            ];
        }

        return $out;
    }

    /**
     * Count total tokens.
     *
     * @return int
     */
    public function count_tokens(): int {
        global $wpdb;

        $meta_key_index = '_previewshare_token_hash';

        $sql = $wpdb->prepare( "SELECT COUNT(1) FROM {$wpdb->postmeta} WHERE meta_key = %s", $meta_key_index );
        $count = (int) $wpdb->get_var( $sql );

        return $count;
    }

    /**
     * Flush object cache entries for tokens that belong to a post.
     *
     * @param int $post_id
     * @return void
     */
    public function flush_post_cache( int $post_id ): void {
        $hashes = get_post_meta( $post_id, '_previewshare_token_hash' );

        if ( empty( $hashes ) ) {
            return;
        }

        foreach ( $hashes as $hash ) {
            wp_cache_delete( $hash, 'previewshare_tokens' );
        }
    }

    /**
     * Revoke a token by raw token.
     *
     * @param string $token
     * @return bool
     */
    public function revoke_token( string $token ): bool {
        global $wpdb;

        $token_service = new TokenService();
        $hash = $token_service->hash( $token );

        // Find meta row for this hash.
        $meta_key_index = '_previewshare_token_hash';

        $row = $wpdb->get_row( $wpdb->prepare( "SELECT meta_id, post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s LIMIT 1", $meta_key_index, $hash ), ARRAY_A );

        if ( ! $row ) {
            return false;
        }

        $post_id = (int) $row['post_id'];

        $detail_key = '_previewshare_token:' . $hash;
        $detail = get_post_meta( $post_id, $detail_key, true );

        if ( empty( $detail ) || ! is_array( $detail ) ) {
            return false;
        }

        $detail['revoked'] = 1;
        $updated = update_post_meta( $post_id, $detail_key, $detail );

        // Invalidate cache.
        wp_cache_delete( $hash, 'previewshare_tokens' );

        return (bool) $updated;
    }

    /**
     * Revoke a token record by its meta ID (index row).
     *
     * @param int $id
     * @return bool
     */
    public function revoke_token_by_id( int $id ): bool {
        global $wpdb;

        $meta_key_index = '_previewshare_token_hash';

        $row = $wpdb->get_row( $wpdb->prepare( "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_id = %d AND meta_key = %s LIMIT 1", $id, $meta_key_index ), ARRAY_A );

        if ( ! $row ) {
            return false;
        }

        $post_id = (int) $row['post_id'];
        $hash = $row['meta_value'];

        $detail_key = '_previewshare_token:' . $hash;
        $detail = get_post_meta( $post_id, $detail_key, true );

        if ( empty( $detail ) || ! is_array( $detail ) ) {
            return false;
        }

        $detail['revoked'] = 1;
        $updated = update_post_meta( $post_id, $detail_key, $detail );

        wp_cache_delete( $hash, 'previewshare_tokens' );

        return (bool) $updated;
    }
}
