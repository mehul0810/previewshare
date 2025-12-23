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
    public function store_token( int $post_id, string $token, int $ttl_hours = 6 ): bool {
        $token_service = new TokenService();
        $hash = $token_service->hash( $token );
        $now = time();
        $expires = $ttl_hours > 0 ? ( $now + ( $ttl_hours * HOUR_IN_SECONDS ) ) : null;
        $ttl_seconds = $ttl_hours > 0 ? ( $ttl_hours * HOUR_IN_SECONDS ) : 0;

        $meta_key_index = '_previewshare_token_hash';
        $meta_key_detail = '_previewshare_token:' . $hash;

        $user_id = get_current_user_id() ?: null;
        // Enforce single token per post: use update_post_meta for index so it
        // replaces existing value rather than creating multiple entries.
        $existing_hash = get_post_meta( $post_id, $meta_key_index, true );

        // If there's an existing hash for this post and it's different from
        // the new one, mark the old token as revoked and remove its reverse
        // lookup meta so lookups avoid scanning meta_value.
        if ( $existing_hash && $existing_hash !== $hash ) {
            $old_detail_key = '_previewshare_token:' . $existing_hash;
            $old_detail = get_post_meta( $post_id, $old_detail_key, true );
            if ( is_array( $old_detail ) ) {
                $old_detail['revoked'] = 1;
                update_post_meta( $post_id, $old_detail_key, $old_detail );
                wp_cache_delete( $existing_hash, 'previewshare_tokens' );
            }

            // Remove reverse lookup meta for the old hash so future lookups
            // using meta_key do not need to scan meta_value columns.
            delete_post_meta( $post_id, '_previewshare_token_rev:' . $existing_hash );
        }

        // If the post already has the same token hash, do not create a new
        // token — simply update expiry and unrevoke it so toggling preserves
        // the same token value.
        $detail = get_post_meta( $post_id, $meta_key_detail, true );

        if ( ! empty( $detail ) && is_array( $detail ) && $existing_hash === $hash ) {
            // Preserve original created_at. Update expires_at, user_id and
            // clear revoked flag.
            $detail['user_id']    = $user_id;
            $detail['expires_at'] = $expires;
            $detail['revoked']    = 0;

            $result_detail = update_post_meta( $post_id, $meta_key_detail, $detail );
            $result_index  = update_post_meta( $post_id, $meta_key_index, $hash );
        } else {
            // New token for this post — create/replace index and detail.
            $detail = [
                'created_at' => $now,
                'user_id'    => $user_id,
                'expires_at' => $expires,
                'revoked'    => 0,
            ];

            $result_detail = update_post_meta( $post_id, $meta_key_detail, $detail );
            $result_index  = update_post_meta( $post_id, $meta_key_index, $hash );
        }

        if ( $result_index ) {
            // Warm object cache for quicker lookup similar to custom table driver.
            wp_cache_set( $hash, (int) $post_id, 'previewshare_tokens', HOUR_IN_SECONDS );

            // Maintain a reverse-lookup meta key per token hash. Using a
            // unique meta_key per hash lets us query by meta_key (indexed)
            // instead of meta_value (which causes full meta scans).
            update_post_meta( $post_id, '_previewshare_token_rev:' . $hash, 1 );
            // Set a transient so token expiry is enforced automatically by
            // WordPress without a cron. The transient's lifetime equals the
            // requested TTL. If $ttl_seconds is 0, the transient will not
            // expire.
            set_transient( 'previewshare_token_tr:' . $hash, 1, $ttl_seconds );
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
        $token_service = new TokenService();
        $hash = $token_service->hash( $token );

        // Check object cache first.
        $cached = wp_cache_get( $hash, 'previewshare_tokens' );
        if ( $cached !== false ) {
            return (int) $cached;
        }

        // Query posts that have the reverse-lookup meta key for this hash.
        // Searching by meta_key (a unique key per token) avoids scanning
        // the meta_value column and is far more efficient for large sites.
        $posts = get_posts( [
            'post_type'      => 'any',
            'meta_key'       => '_previewshare_token_rev:' . $hash,
            'posts_per_page' => 1,
            'fields'         => 'ids',
            'no_found_rows'  => true,
        ] );

        if ( empty( $posts ) ) {
            return false;
        }

        $post_id = (int) $posts[0];

        // Fetch detail meta and validate not revoked.
        $detail = get_post_meta( $post_id, '_previewshare_token:' . $hash, true );

        if ( empty( $detail ) || ! is_array( $detail ) ) {
            return false;
        }

        if ( ! empty( $detail['revoked'] ) ) {
            return false;
        }

        // Check transient to determine if token is still valid. Transients
        // expire automatically after their TTL without needing cron.
        $transient_key = 'previewshare_token_tr:' . $hash;
        $transient = get_transient( $transient_key );
        if ( $transient === false ) {
            return false;
        }

        wp_cache_set( $hash, $post_id, 'previewshare_tokens', HOUR_IN_SECONDS );

        return $post_id;
    }

    /**
     * Get token meta for a post (created_at, user_id, expires_at).
     *
     * @param int $post_id
     * @return array|null
     */
    public function get_token_meta( int $post_id ): ?array {
        $meta_key_index = '_previewshare_token_hash';

        // Use stored single index via postmeta.
        $hash = get_post_meta( $post_id, $meta_key_index, true );

        if ( ! $hash ) {
            return null;
        }

        $detail = get_post_meta( $post_id, '_previewshare_token:' . $hash, true );

        if ( empty( $detail ) || ! is_array( $detail ) ) {
            return null;
        }

        $now = time();
        $expires = ! empty( $detail['expires_at'] ) ? (int) $detail['expires_at'] : null;
        $revoked = ! empty( $detail['revoked'] );

        // Determine expired state by checking transient existence. If the
        // transient has expired, treat the token as expired.
        $transient_key = 'previewshare_token_tr:' . $hash;
        $transient = get_transient( $transient_key );
        $expired = ( $transient === false ) || ( $expires !== null && $expires <= $now );

        // If the token is expired, ensure the post's "enable public preview"
        // meta is switched off so the editor toggle reflects the disabled
        // state. This makes re-enabling generate a fresh token.
        if ( $expired ) {
            $enabled_meta = get_post_meta( $post_id, '_previewshare_enabled', true );
            if ( $enabled_meta ) {
                update_post_meta( $post_id, '_previewshare_enabled', false );
            }
        }

        return [
            'created' => isset( $detail['created_at'] ) ? (int) $detail['created_at'] : 0,
            'user_id' => isset( $detail['user_id'] ) ? (int) $detail['user_id'] : 0,
            'expires' => $expires,
            'revoked' => $revoked,
            'expired' => $expired,
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
        $meta_key_index = '_previewshare_token_hash';

        $args = [
            'post_type'      => 'any',
            'meta_key'       => $meta_key_index,
            'posts_per_page' => $per_page,
            'paged'          => max( 1, $page ),
            'fields'         => 'ids',
            'no_found_rows'  => false,
        ];

        $q = new \WP_Query( $args );
        $ids = $q->posts;

        if ( empty( $ids ) ) {
            return [];
        }

        $out = [];
        $now = time();

        foreach ( $ids as $post_id ) {
            $post_id = (int) $post_id;
            $hash = get_post_meta( $post_id, $meta_key_index, true );
            $detail = get_post_meta( $post_id, '_previewshare_token:' . $hash, true );

            $expires = isset( $detail['expires_at'] ) ? (int) $detail['expires_at'] : null;
            $revoked = ! empty( $detail['revoked'] );

            // Check transient for automatic expiry.
            $transient_key = 'previewshare_token_tr:' . $hash;
            $transient = get_transient( $transient_key );
            $expired = ( $transient === false ) || ( $expires !== null && $expires <= $now );

            $out[] = [
                'id'         => $post_id,
                'post_id'    => $post_id,
                'token_hash' => $hash,
                'user_id'    => isset( $detail['user_id'] ) ? (int) $detail['user_id'] : 0,
                'created_at' => isset( $detail['created_at'] ) ? (int) $detail['created_at'] : 0,
                'expires_at' => $expires,
                'revoked'    => $revoked,
                'expired'    => $expired,
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
        $meta_key_index = '_previewshare_token_hash';

        // Use WP_Query with no_found_rows=false and posts_per_page=1 to get
        // found_posts without fetching all posts.
        $q = new \WP_Query( [
            'post_type'      => 'any',
            'meta_key'       => $meta_key_index,
            'posts_per_page' => 1,
            'no_found_rows'  => false,
            'fields'         => 'ids',
        ] );

        return (int) $q->found_posts;
    }

    /**
     * Flush object cache entries for tokens that belong to a post.
     *
     * @param int $post_id
     * @return void
     */
    public function flush_post_cache( int $post_id ): void {
        $hash = get_post_meta( $post_id, '_previewshare_token_hash', true );

        if ( empty( $hash ) ) {
            return;
        }

        wp_cache_delete( $hash, 'previewshare_tokens' );
    }

    /**
     * Revoke a token by raw token.
     *
     * @param string $token
     * @return bool
     */
    public function revoke_token( string $token ): bool {
        $token_service = new TokenService();
        $hash = $token_service->hash( $token );

        // Find post that has this token hash.
        // Query posts using the reverse meta key for performance.
        $posts = get_posts( [
            'post_type'      => 'any',
            'meta_key'       => '_previewshare_token_rev:' . $hash,
            'posts_per_page' => 1,
            'fields'         => 'ids',
            'no_found_rows'  => true,
        ] );

        if ( empty( $posts ) ) {
            return false;
        }

        $post_id = (int) $posts[0];

        $detail_key = '_previewshare_token:' . $hash;
        $detail = get_post_meta( $post_id, $detail_key, true );

        if ( empty( $detail ) || ! is_array( $detail ) ) {
            return false;
        }

        $detail['revoked'] = 1;
        $updated = update_post_meta( $post_id, $detail_key, $detail );

        // Invalidate cache, delete transient (so token immediately stops
        // validating) and remove reverse lookup meta.
        wp_cache_delete( $hash, 'previewshare_tokens' );
        delete_transient( 'previewshare_token_tr:' . $hash );
        delete_post_meta( $post_id, '_previewshare_token_rev:' . $hash );

        return (bool) $updated;
    }

    /**
     * Revoke a token record by its meta ID (index row).
     *
     * @param int $id
     * @return bool
     */
    public function revoke_token_by_id( int $id ): bool {
        // Here $id represents a post_id (we no longer expose meta_id). If a
        // caller passed a non-post-id, bail out.
        $post_id = absint( $id );
        if ( ! $post_id || ! get_post( $post_id ) ) {
            return false;
        }

        $hash = get_post_meta( $post_id, '_previewshare_token_hash', true );
        if ( ! $hash ) {
            return false;
        }

        $detail_key = '_previewshare_token:' . $hash;
        $detail = get_post_meta( $post_id, $detail_key, true );

        if ( empty( $detail ) || ! is_array( $detail ) ) {
            return false;
        }

        $detail['revoked'] = 1;
        $updated = update_post_meta( $post_id, $detail_key, $detail );

        // Remove transient so token is immediately invalidated, remove
        // reverse lookup and invalidate cache.
        delete_transient( 'previewshare_token_tr:' . $hash );
        delete_post_meta( $post_id, '_previewshare_token_rev:' . $hash );
        wp_cache_delete( $hash, 'previewshare_tokens' );

        return (bool) $updated;
    }
}
