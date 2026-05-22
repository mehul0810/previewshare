<?php
/**
 * Post meta storage for PreviewShare links.
 *
 * @package PreviewShare
 */

namespace PreviewShare\Services;

// Bailout if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stores preview links in post meta.
 *
 * Tokens are stored as HMAC hashes. Each post can have multiple preview links,
 * while legacy single-token meta remains readable for existing installs.
 *
 * @phpstan-type LinkRecord array{hash:string,label:string,created_at:int,created_by:int,expires_at:int|null,revoked:int,last_viewed_at:int|null,view_count:int}
 * @phpstan-type LinkResponse array{id:string,token_hash:string,label:string,created_at:int,created_by:int,expires_at:int|null,revoked:bool,expired:bool,status:string,last_viewed_at:int|null,view_count:int}
 * @phpstan-type LinkListItem array{id:string,token_hash:string,label:string,created_at:int,created_by:int,expires_at:int|null,revoked:bool,expired:bool,status:string,last_viewed_at:int|null,view_count:int,post_id:int}
 */
class PostMetaStorage {

	private const LINKS_META_KEY        = '_previewshare_links';
	private const CURRENT_HASH_META_KEY = '_previewshare_token_hash';
	private const DETAIL_META_PREFIX    = '_previewshare_token:';
	private const REVERSE_META_PREFIX   = '_previewshare_token_rev:';
	private const TRANSIENT_PREFIX      = 'previewshare_token_tr:';
	private const CACHE_GROUP           = 'previewshare_tokens';

	/**
	 * Store a token for a post.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $token Raw token.
	 * @param int    $ttl_hours Hours until expiration. 0 for no expiry.
	 * @param string $label Optional link label.
	 * @return bool
	 */
	public function store_token( int $post_id, string $token, int $ttl_hours = 6, string $label = '' ): bool {
		$token_service = new TokenService();
		$hash          = $token_service->hash( $token );
		$now           = time();
		$ttl_seconds   = $ttl_hours > 0 ? ( $ttl_hours * HOUR_IN_SECONDS ) : 0;
		$expires       = $ttl_hours > 0 ? ( $now + $ttl_seconds ) : null;
		$links         = $this->get_links_for_post( $post_id );
		$current_user  = get_current_user_id();

		$links[ $hash ] = $this->normalize_link_record(
			[
				'hash'           => $hash,
				'label'          => $label ? $label : sprintf( 'Preview link %s', gmdate( 'Y-m-d H:i', $now ) ),
				'created_at'     => $now,
				'created_by'     => $current_user ? $current_user : 0,
				'expires_at'     => $expires,
				'revoked'        => 0,
				'last_viewed_at' => null,
				'view_count'     => 0,
			],
			$hash
		);

		$result = update_post_meta( $post_id, self::LINKS_META_KEY, $links );

		if ( false === $result ) {
			return false;
		}

		update_post_meta( $post_id, self::CURRENT_HASH_META_KEY, $hash );
		update_post_meta( $post_id, self::DETAIL_META_PREFIX . $hash, $links[ $hash ] );
		update_post_meta( $post_id, self::REVERSE_META_PREFIX . $hash, 1 );
		update_post_meta( $post_id, '_previewshare_enabled', true );
		set_transient( self::TRANSIENT_PREFIX . $hash, 1, $ttl_seconds );

		if ( $this->is_caching_enabled() ) {
			wp_cache_set( $hash, (int) $post_id, self::CACHE_GROUP, HOUR_IN_SECONDS );
		}

		return true;
	}

	/**
	 * Resolve post ID by raw token.
	 *
	 * @param string $token Raw token.
	 * @return int|false Post ID or false when not found.
	 */
	public function get_post_id_by_token( string $token ) {
		$link = $this->get_link_by_token( $token );

		return $link ? (int) $link['post_id'] : false;
	}

	/**
	 * Resolve an active preview link by raw token.
	 *
	 * @param string $token Raw token.
	 * @return array{post_id:int,hash:string,link:LinkRecord}|null Link context or null when invalid.
	 */
	public function get_link_by_token( string $token ): ?array {
		$token_service = new TokenService();
		$hash          = $token_service->hash( $token );
		$post_id       = $this->get_post_id_by_hash( $hash );

		if ( ! $post_id ) {
			return null;
		}

		$link = $this->get_link_record( $post_id, $hash );

		if ( ! $this->is_link_active( $link ) ) {
			$this->delete_cache( $hash );
			return null;
		}

		return [
			'post_id' => $post_id,
			'hash'    => $hash,
			'link'    => $link,
		];
	}

	/**
	 * Record a successful preview view.
	 *
	 * @param string $token Raw token.
	 * @return bool
	 */
	public function record_token_view( string $token ): bool {
		$token_service = new TokenService();
		$hash          = $token_service->hash( $token );
		$post_id       = $this->get_post_id_by_hash( $hash );

		if ( ! $post_id ) {
			return false;
		}

		$links = $this->get_links_for_post( $post_id );

		if ( empty( $links[ $hash ] ) || ! $this->is_link_active( $links[ $hash ] ) ) {
			return false;
		}

		$links[ $hash ]['last_viewed_at'] = time();
		$links[ $hash ]['view_count']     = (int) $links[ $hash ]['view_count'] + 1;

		update_post_meta( $post_id, self::LINKS_META_KEY, $links );
		update_post_meta( $post_id, self::DETAIL_META_PREFIX . $hash, $links[ $hash ] );

		return true;
	}

	/**
	 * Get token meta for a post.
	 *
	 * @param int $post_id Post ID.
	 * @return array{created:int,user_id:int,expires:int|null,revoked:bool,expired:bool,status:string,active_count:int,links:array<int,LinkResponse>}|null
	 */
	public function get_token_meta( int $post_id ): ?array {
		$links = $this->get_links_for_post( $post_id );

		if ( empty( $links ) ) {
			return null;
		}

		uasort(
			$links,
			static function ( array $a, array $b ): int {
				return (int) $b['created_at'] <=> (int) $a['created_at'];
			}
		);

		$active_links = array_filter( $links, [ $this, 'is_link_active' ] );
		$latest       = reset( $active_links );

		if ( ! $latest ) {
			$latest = reset( $links );
			update_post_meta( $post_id, '_previewshare_enabled', false );
		}

		return [
			'created'      => (int) $latest['created_at'],
			'user_id'      => (int) $latest['created_by'],
			'expires'      => $latest['expires_at'],
			'revoked'      => ! empty( $latest['revoked'] ),
			'expired'      => $this->is_link_expired( $latest ),
			'status'       => $this->get_link_status( $latest ),
			'active_count' => count( $active_links ),
			'links'        => array_values( array_map( [ $this, 'format_link_for_response' ], $links ) ),
		];
	}

	/**
	 * List preview links with pagination.
	 *
	 * @param int $per_page Per-page limit.
	 * @param int $page Page number.
	 * @return array<int,array<string,mixed>>
	 */
	public function list_tokens( int $per_page = 50, int $page = 1 ): array {
		$links  = $this->get_all_links();
		$offset = ( max( 1, $page ) - 1 ) * $per_page;

		return array_slice( $links, $offset, $per_page );
	}

	/**
	 * Count all preview links.
	 *
	 * @return int
	 */
	public function count_tokens(): int {
		return count( $this->get_all_links() );
	}

	/**
	 * Flush object cache entries for tokens that belong to a post.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public function flush_post_cache( int $post_id ): void {
		$links = $this->get_links_for_post( $post_id );

		foreach ( array_keys( $links ) as $hash ) {
			$this->delete_cache( $hash );
		}
	}

	/**
	 * Revoke a token by raw token.
	 *
	 * @param string $token Raw token.
	 * @return bool
	 */
	public function revoke_token( string $token ): bool {
		$token_service = new TokenService();

		return $this->revoke_token_by_id( $token_service->hash( $token ) );
	}

	/**
	 * Revoke a token by link ID/hash.
	 *
	 * @param string $id Link ID/hash.
	 * @return bool
	 */
	public function revoke_token_by_id( string $id ): bool {
		$hash    = sanitize_key( $id );
		$post_id = $this->get_post_id_by_hash( $hash );

		if ( ! $post_id ) {
			return false;
		}

		return $this->revoke_token_hash_for_post( $post_id, $hash );
	}

	/**
	 * Revoke all current links for a post.
	 *
	 * @param int $post_id Post ID.
	 * @return bool True when a token was revoked.
	 */
	public function revoke_token_for_post( int $post_id ): bool {
		$links = $this->get_links_for_post( $post_id );

		if ( empty( $links ) ) {
			update_post_meta( $post_id, '_previewshare_enabled', false );
			return false;
		}

		$changed = false;

		foreach ( $links as $hash => $link ) {
			if ( empty( $link['revoked'] ) ) {
				$links[ $hash ]['revoked'] = 1;
				$changed                   = true;
			}

			delete_transient( self::TRANSIENT_PREFIX . $hash );
			$this->delete_cache( $hash );
			update_post_meta( $post_id, self::DETAIL_META_PREFIX . $hash, $links[ $hash ] );
		}

		update_post_meta( $post_id, self::LINKS_META_KEY, $links );
		update_post_meta( $post_id, '_previewshare_enabled', false );

		return $changed;
	}

	/**
	 * Return all link records for a post.
	 *
	 * @param int $post_id Post ID.
	 * @return array<string,LinkRecord>
	 */
	public function get_links_for_post( int $post_id ): array {
		$links = get_post_meta( $post_id, self::LINKS_META_KEY, true );

		if ( ! is_array( $links ) ) {
			$links = [];
		}

		$links = $this->normalize_links( $links );

		if ( empty( $links ) ) {
			$legacy = $this->get_legacy_link_for_post( $post_id );

			if ( $legacy ) {
				$links[ $legacy['hash'] ] = $legacy;
				update_post_meta( $post_id, self::LINKS_META_KEY, $links );
				update_post_meta( $post_id, self::REVERSE_META_PREFIX . $legacy['hash'], 1 );
			}
		}

		return $links;
	}

	/**
	 * Revoke one link hash for a post.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $hash Token hash.
	 * @return bool
	 */
	private function revoke_token_hash_for_post( int $post_id, string $hash ): bool {
		$links = $this->get_links_for_post( $post_id );

		if ( empty( $links[ $hash ] ) ) {
			return false;
		}

		$links[ $hash ]['revoked'] = 1;
		update_post_meta( $post_id, self::LINKS_META_KEY, $links );
		update_post_meta( $post_id, self::DETAIL_META_PREFIX . $hash, $links[ $hash ] );
		delete_transient( self::TRANSIENT_PREFIX . $hash );
		$this->delete_cache( $hash );

		if ( ! $this->has_active_link( $links ) ) {
			update_post_meta( $post_id, '_previewshare_enabled', false );
		}

		return true;
	}

	/**
	 * Resolve a post ID by token hash.
	 *
	 * @param string $hash Token hash.
	 * @return int
	 */
	private function get_post_id_by_hash( string $hash ): int {
		if ( $this->is_caching_enabled() ) {
			$cached = wp_cache_get( $hash, self::CACHE_GROUP );

			if ( false !== $cached ) {
				return (int) $cached;
			}
		}

		$posts = get_posts(
			[
				'post_type'      => 'any',
				'post_status'    => 'any',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Exact-key reverse lookup is bounded to one ID and optionally cached.
				'meta_key'       => self::REVERSE_META_PREFIX . $hash,
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			]
		);

		if ( empty( $posts ) ) {
			return 0;
		}

		$post_id = (int) $posts[0];

		if ( $this->is_caching_enabled() ) {
			wp_cache_set( $hash, $post_id, self::CACHE_GROUP, HOUR_IN_SECONDS );
		}

		return $post_id;
	}

	/**
	 * Get a link record by post/hash.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $hash Token hash.
	 * @return LinkRecord|null
	 */
	private function get_link_record( int $post_id, string $hash ): ?array {
		$links = $this->get_links_for_post( $post_id );

		if ( ! empty( $links[ $hash ] ) ) {
			return $links[ $hash ];
		}

		$detail = get_post_meta( $post_id, self::DETAIL_META_PREFIX . $hash, true );

		if ( ! is_array( $detail ) ) {
			return null;
		}

		return $this->normalize_link_record( $detail, $hash );
	}

	/**
	 * Get all links across posts.
	 *
	 * @return list<LinkListItem>
	 */
	private function get_all_links(): array {
		$query = new \WP_Query(
			[
				'post_type'      => 'any',
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Admin-only inventory view; public token resolution uses exact-key lookup.
				'meta_query'     => [
					'relation' => 'OR',
					[
						'key'     => self::LINKS_META_KEY,
						'compare' => 'EXISTS',
					],
					[
						'key'     => self::CURRENT_HASH_META_KEY,
						'compare' => 'EXISTS',
					],
				],
			]
		);

		// phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan variable type annotation.
		/** @var list<LinkListItem> $items Preview link list items. */
		$items = [];

		foreach ( $query->posts as $post_id ) {
			foreach ( $this->get_links_for_post( (int) $post_id ) as $link ) {
				$item    = $this->format_link_for_response( $link );
				$items[] = [
					'id'             => $item['id'],
					'token_hash'     => $item['token_hash'],
					'label'          => $item['label'],
					'created_at'     => $item['created_at'],
					'created_by'     => $item['created_by'],
					'expires_at'     => $item['expires_at'],
					'revoked'        => $item['revoked'],
					'expired'        => $item['expired'],
					'status'         => $item['status'],
					'last_viewed_at' => $item['last_viewed_at'],
					'view_count'     => $item['view_count'],
					'post_id'        => (int) $post_id,
				];
			}
		}

		$created_at = array_column( $items, 'created_at' );
		array_multisort( $created_at, SORT_DESC, SORT_NUMERIC, $items );

		return $items;
	}

	/**
	 * Get a legacy single-token record.
	 *
	 * @param int $post_id Post ID.
	 * @return LinkRecord|null
	 */
	private function get_legacy_link_for_post( int $post_id ): ?array {
		$hash = get_post_meta( $post_id, self::CURRENT_HASH_META_KEY, true );

		if ( ! is_string( $hash ) || '' === $hash ) {
			return null;
		}

		$detail = get_post_meta( $post_id, self::DETAIL_META_PREFIX . $hash, true );

		if ( ! is_array( $detail ) ) {
			return null;
		}

		return $this->normalize_link_record( $detail, $hash );
	}

	/**
	 * Normalize a link collection.
	 *
	 * @param array<mixed> $links Raw links.
	 * @return array<string,LinkRecord>
	 */
	private function normalize_links( array $links ): array {
		$normalized = [];

		foreach ( $links as $hash => $link ) {
			if ( ! is_array( $link ) ) {
				continue;
			}

			$hash = is_string( $hash ) ? $hash : ( $link['hash'] ?? '' );

			if ( ! is_string( $hash ) || '' === $hash ) {
				continue;
			}

			$normalized[ $hash ] = $this->normalize_link_record( $link, $hash );
		}

		return $normalized;
	}

	/**
	 * Normalize one link record.
	 *
	 * @param array<string,mixed> $link Raw link data.
	 * @param string              $hash Token hash.
	 * @return LinkRecord
	 */
	private function normalize_link_record( array $link, string $hash ): array {
		$created_at = isset( $link['created_at'] ) ? (int) $link['created_at'] : time();
		$created_by = isset( $link['created_by'] ) ? (int) $link['created_by'] : ( isset( $link['user_id'] ) ? (int) $link['user_id'] : 0 );
		$expires_at = array_key_exists( 'expires_at', $link ) && null !== $link['expires_at'] ? (int) $link['expires_at'] : null;

		return [
			'hash'           => $hash,
			'label'          => isset( $link['label'] ) && '' !== $link['label'] ? sanitize_text_field( (string) $link['label'] ) : sprintf( 'Preview link %s', gmdate( 'Y-m-d H:i', $created_at ) ),
			'created_at'     => $created_at,
			'created_by'     => $created_by,
			'expires_at'     => $expires_at,
			'revoked'        => ! empty( $link['revoked'] ) ? 1 : 0,
			'last_viewed_at' => array_key_exists( 'last_viewed_at', $link ) && null !== $link['last_viewed_at'] ? (int) $link['last_viewed_at'] : null,
			'view_count'     => isset( $link['view_count'] ) ? max( 0, (int) $link['view_count'] ) : 0,
		];
	}

	/**
	 * Format a link for REST responses.
	 *
	 * @param array $link Link record.
	 * @phpstan-param LinkRecord $link
	 * @return LinkResponse
	 */
	private function format_link_for_response( array $link ): array {
		return [
			'id'             => $link['hash'],
			'token_hash'     => $link['hash'],
			'label'          => $link['label'],
			'created_at'     => (int) $link['created_at'],
			'created_by'     => (int) $link['created_by'],
			'expires_at'     => $link['expires_at'],
			'revoked'        => ! empty( $link['revoked'] ),
			'expired'        => $this->is_link_expired( $link ),
			'status'         => $this->get_link_status( $link ),
			'last_viewed_at' => $link['last_viewed_at'],
			'view_count'     => (int) $link['view_count'],
		];
	}

	/**
	 * Check whether any link is active.
	 *
	 * @param array<string,LinkRecord> $links Link collection.
	 * @return bool
	 */
	private function has_active_link( array $links ): bool {
		foreach ( $links as $link ) {
			if ( $this->is_link_active( $link ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Check whether a link is active.
	 *
	 * @param LinkRecord|null $link Link record.
	 * @return bool
	 */
	private function is_link_active( ?array $link ): bool {
		return ! empty( $link ) && empty( $link['revoked'] ) && ! $this->is_link_expired( $link );
	}

	/**
	 * Check whether a link is expired.
	 *
	 * @param array $link Link record.
	 * @phpstan-param LinkRecord $link
	 * @return bool
	 */
	private function is_link_expired( array $link ): bool {
		return null !== $link['expires_at'] && (int) $link['expires_at'] <= time();
	}

	/**
	 * Return the derived link status.
	 *
	 * @param array $link Link record.
	 * @phpstan-param LinkRecord $link
	 * @return string
	 */
	private function get_link_status( array $link ): string {
		if ( ! empty( $link['revoked'] ) ) {
			return 'revoked';
		}

		if ( $this->is_link_expired( $link ) ) {
			return 'expired';
		}

		return 'active';
	}

	/**
	 * Delete token object cache.
	 *
	 * @param string $hash Token hash.
	 * @return void
	 */
	private function delete_cache( string $hash ): void {
		if ( $this->is_caching_enabled() ) {
			wp_cache_delete( $hash, self::CACHE_GROUP );
		}
	}

	/**
	 * Check whether token object caching is enabled.
	 *
	 * @return bool
	 */
	private function is_caching_enabled(): bool {
		return (bool) get_option( 'previewshare_enable_caching', true );
	}
}
