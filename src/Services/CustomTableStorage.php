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
    public function store_token( int $post_id, string $token, int $ttl_days = 30 ): bool {
        global $wpdb;

        $table   = $wpdb->prefix . 'previewshare_tokens';
        $token_service = new TokenService();
        $hash    = $token_service->hash( $token );
        $now     = time();
        $expires = $ttl_days > 0 ? ( $now + ( $ttl_days * DAY_IN_SECONDS ) ) : null;

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

        $now = time();

        $sql = $wpdb->prepare(
            "SELECT post_id FROM {$table} WHERE token_hash = %s AND revoked = 0 AND (expires_at IS NULL OR expires_at > %d) LIMIT 1",
            $hash,
            $now
        );

        $post_id = $wpdb->get_var( $sql );

        return $post_id ? (int) $post_id : false;
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

        return (bool) $result;
    }
}
