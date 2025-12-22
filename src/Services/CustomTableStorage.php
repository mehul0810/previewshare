<?php
/**
 * Custom table storage driver for preview tokens.
 *
 * @package PreviewShare
 */

namespace PreviewShare\Services;

// Abort if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class CustomTableStorage
 *
 * Stores token hashes in a dedicated table for performance and scalability.
 */
class CustomTableStorage {

    /**
     * Store a token for a post (stores hash, not raw token).
     *
     * @param int    $post_id Post ID.
     * @param string $token Raw token.
     * @param int    $ttl_days Days until expiration. 0 for no expiry.
     * @return bool True on success.
     */
    /**
     * Store a token for a post (stores hash, not raw token).
     *
     * @param int    $post_id Post ID.
     * @param string $token Raw token.
     * @param int    $ttl_hours Hours until expiration. 0 for no expiry.
     * @return bool True on success.
     */
    public function store_token( int $post_id, string $token, int $ttl_hours = 24 ): bool {
        global $wpdb;

        $table   = $wpdb->prefix . 'previewshare_tokens';
        $token_service = new TokenService();
        $hash    = $token_service->hash( $token );
        $now     = time();
        $expires = $ttl_hours > 0 ? ( $now + ( $ttl_hours * HOUR_IN_SECONDS ) ) : null;

        $result = $wpdb->insert(
            $table,
            [
                'post_id'    => $post_id,
                'token_hash' => $hash,
                'user_id'    => get_current_user_id() ?: null,
                'created_at' => $now,
                'expires_at' => $expires,
                'revoked'    => 0,
            ],
            [ '%d', '%s', '%d', '%d', '%d', '%d' ]
        );

        if ( $result ) {
            // Warm object cache: map hash => post_id for faster lookups.
            wp_cache_set( $hash, (int) $post_id, 'previewshare_tokens', HOUR_IN_SECONDS );
        }

        return (bool) $result;
    }

    /**
     * Get post ID by raw token. Returns int|false.
     *
     * @param string $token Raw token.
     * @return int|false
     */
    public function get_post_id_by_token( string $token ) {
        global $wpdb;

        $table = $wpdb->prefix . 'previewshare_tokens';
        $token_service = new TokenService();
        $hash = $token_service->hash( $token );

        // Check object cache first.
        $cached = wp_cache_get( $hash, 'previewshare_tokens' );
        if ( $cached !== false ) {
            return (int) $cached;
        }

        $now = time();

        $sql = $wpdb->prepare(
            "SELECT post_id FROM {$table} WHERE token_hash = %s AND revoked = 0 AND (expires_at IS NULL OR expires_at > %d) LIMIT 1",
            $hash,
            $now
        );

        $post_id = $wpdb->get_var( $sql );

        if ( $post_id ) {
            wp_cache_set( $hash, (int) $post_id, 'previewshare_tokens', HOUR_IN_SECONDS );
            return (int) $post_id;
        }

        return false;
    }

    /**
     * Get token meta for a post (created_at, user_id).
     *
     * @param int $post_id Post ID.
     * @return array|null
     */
    public function get_token_meta( int $post_id ): ?array {
        global $wpdb;

        $table = $wpdb->prefix . 'previewshare_tokens';

        $sql = $wpdb->prepare( "SELECT created_at, user_id, expires_at FROM {$table} WHERE post_id = %d ORDER BY created_at DESC LIMIT 1", $post_id );
        $row = $wpdb->get_row( $sql, ARRAY_A );

        if ( ! $row ) {
            return null;
        }

        return [
            'created' => (int) $row['created_at'],
            'user_id' => isset( $row['user_id'] ) ? (int) $row['user_id'] : 0,
            'expires' => ! empty( $row['expires_at'] ) ? (int) $row['expires_at'] : null,
        ];
    }

    /**
     * List tokens with pagination.
     * Returns array of rows with post_id, token_hash, created_at, user_id, expires_at, revoked
     *
     * @param int $per_page
     * @param int $page
     * @return array
     */
    public function list_tokens( int $per_page = 50, int $page = 1 ): array {
        global $wpdb;

        $table = $wpdb->prefix . 'previewshare_tokens';
        $offset = max( 0, ( $page - 1 ) * $per_page );

        $sql = $wpdb->prepare( "SELECT id, post_id, token_hash, user_id, created_at, expires_at, revoked FROM {$table} ORDER BY created_at DESC LIMIT %d OFFSET %d", $per_page, $offset );

        $rows = $wpdb->get_results( $sql, ARRAY_A );

        return $rows ?: [];
    }

    /**
     * Count total tokens.
     *
     * @return int
     */
    public function count_tokens(): int {
        global $wpdb;

        $table = $wpdb->prefix . 'previewshare_tokens';

        $sql = "SELECT COUNT(1) FROM {$table}";
        $count = (int) $wpdb->get_var( $sql );

        return $count;
    }

    /**
     * Flush object cache entries for tokens that belong to a post.
     * This queries token hashes for the given post and deletes cache entries.
     *
     * @param int $post_id
     * @return void
     */
    public function flush_post_cache( int $post_id ): void {
        global $wpdb;

        $table = $wpdb->prefix . 'previewshare_tokens';

        $sql = $wpdb->prepare( "SELECT token_hash FROM {$table} WHERE post_id = %d", $post_id );
        $rows = $wpdb->get_col( $sql );

        if ( empty( $rows ) ) {
            return;
        }

        foreach ( $rows as $hash ) {
            wp_cache_delete( $hash, 'previewshare_tokens' );
        }
    }

    /**
     * Revoke a token by raw token.
     *
     * @param string $token Raw token.
     * @return bool
     */
    public function revoke_token( string $token ): bool {
        global $wpdb;

        $table = $wpdb->prefix . 'previewshare_tokens';
        $token_service = new TokenService();
        $hash = $token_service->hash( $token );

        $result = $wpdb->update( $table, [ 'revoked' => 1 ], [ 'token_hash' => $hash ], [ '%d' ], [ '%s' ] );

        if ( $result ) {
            // Invalidate cache for this token hash.
            wp_cache_delete( $hash, 'previewshare_tokens' );
        }

        return (bool) $result;
    }

    /**
     * Revoke a token record by its DB ID.
     *
     * @param int $id Token row ID.
     * @return bool
     */
    public function revoke_token_by_id( int $id ): bool {
        global $wpdb;

        $table = $wpdb->prefix . 'previewshare_tokens';

        // Get token_hash for cache invalidation.
        $hash = $wpdb->get_var( $wpdb->prepare( "SELECT token_hash FROM {$table} WHERE id = %d LIMIT 1", $id ) );

        $result = $wpdb->update( $table, [ 'revoked' => 1 ], [ 'id' => $id ], [ '%d' ], [ '%d' ] );

        if ( $result && $hash ) {
            wp_cache_delete( $hash, 'previewshare_tokens' );
        }

        return (bool) $result;
    }
}
