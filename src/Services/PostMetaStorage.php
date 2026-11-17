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
 * @phpstan-type LinkRecord array{hash:string,label:string,created_at:int,created_by:int,expires_at:int|null,revoked:int,last_viewed_at:int|null,view_count:int,responses_enabled:bool,identity_required:bool}
 * @phpstan-type LinkResponse array{id:string,token_hash:string,label:string,created_at:int,created_by:int,expires_at:int|null,revoked:bool,expired:bool,status:string,last_viewed_at:int|null,view_count:int,responses_enabled:bool,identity_required:bool}
 * @phpstan-type LinkListItem array{id:string,token_hash:string,label:string,created_at:int,created_by:int,expires_at:int|null,revoked:bool,expired:bool,status:string,last_viewed_at:int|null,view_count:int,responses_enabled:bool,identity_required:bool,post_id:int}
 */
class PostMetaStorage {

	private const LINKS_META_KEY        = '_previewshare_links';
	private const CURRENT_HASH_META_KEY = '_previewshare_token_hash';
	private const DETAIL_META_PREFIX    = '_previewshare_token:';
	private const REVERSE_META_PREFIX   = '_previewshare_token_rev:';
	private const CACHE_GROUP           = 'previewshare_tokens';

	/**
	 * Token helper.
	 *
	 * @var TokenService
	 */
	private $token_service;

	/**
	 * Constructor.
	 *
	 * @param TokenService|null $token_service Optional token helper.
	 */
	public function __construct( ?TokenService $token_service = null ) {
		$this->token_service = $token_service ? $token_service : new TokenService();
	}

	/**
	 * Store a token for a post.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $token Raw token.
	 * @param int    $ttl_hours Hours until expiration. 0 for no expiry.
	 * @param string $label Optional link label.
	 * @param bool   $responses_enabled Whether reviewers may respond.
	 * @param bool   $identity_required Whether response identity is required.
	 * @return bool
	 */
	public function store_token( int $post_id, string $token, int $ttl_hours = 6, string $label = '', bool $responses_enabled = false, bool $identity_required = false ): bool {
		$hash    = $this->token_service->hash( $token );
		$now     = time();
		$expires = $ttl_hours > 0 ? ( $now + ( $ttl_hours * HOUR_IN_SECONDS ) ) : null;
		// The aggregate is a compatibility projection. Authoritative detail rows
		// keep every sibling visible even when concurrent initialization replaces it.
		$stored_links          = get_post_meta( $post_id, self::LINKS_META_KEY, true );
		$links                 = $this->get_links_for_post( $post_id );
		$current_user          = get_current_user_id();
		$previous_current_hash = get_post_meta( $post_id, self::CURRENT_HASH_META_KEY, true );

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
				'responses_enabled' => $responses_enabled,
				'identity_required' => $responses_enabled && $identity_required,
			],
			$hash
		);

		$result = is_array( $stored_links )
			? update_post_meta( $post_id, self::LINKS_META_KEY, $links, $stored_links )
			: update_post_meta( $post_id, self::LINKS_META_KEY, $links );

		if ( false === $result ) {
			return false;
		}

		if (
			! $this->update_required_post_meta( $post_id, self::CURRENT_HASH_META_KEY, $hash )
			|| ! $this->update_required_post_meta( $post_id, self::DETAIL_META_PREFIX . $hash, $links[ $hash ] )
			|| ! $this->update_required_post_meta( $post_id, self::REVERSE_META_PREFIX . $hash, 1 )
		) {
			$this->remove_aggregate_link( $post_id, $hash );
			delete_post_meta( $post_id, self::DETAIL_META_PREFIX . $hash );
			delete_post_meta( $post_id, self::REVERSE_META_PREFIX . $hash );

			$current_hash = get_post_meta( $post_id, self::CURRENT_HASH_META_KEY, true );
			if ( $current_hash === $hash ) {
				if ( is_string( $previous_current_hash ) && '' !== $previous_current_hash ) {
					update_post_meta( $post_id, self::CURRENT_HASH_META_KEY, $previous_current_hash, $hash );
				} else {
					delete_post_meta( $post_id, self::CURRENT_HASH_META_KEY, $hash );
				}
			}

			return false;
		}

		update_post_meta( $post_id, '_previewshare_enabled', true );
		// A bulk revoke can include this detail while generation is publishing it.
		wp_cache_delete( $post_id, 'post_meta' );
		if ( ! $this->is_link_active( $this->get_link_detail_record( $post_id, $hash ) ) ) {
			$this->refresh_sharing_after_revoke( $post_id, $this->get_link_detail_snapshot( $post_id ) );
			return false;
		}

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
		$hash    = $this->token_service->hash( $token );
		$post_id = $this->get_post_id_by_hash( $hash );

		if ( ! $post_id ) {
			return null;
		}

		$stored_link = get_post_meta( $post_id, self::DETAIL_META_PREFIX . $hash, true );
		$link        = is_array( $stored_link ) ? $this->normalize_link_record( $stored_link, $hash ) : null;

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
	 * Resolve a link by its stored identifier, including revoked links.
	 *
	 * Callers must check the current user's post capability before returning
	 * this context or its response history.
	 *
	 * @param string $id Link identifier.
	 * @return array{post_id:int,hash:string,link:LinkRecord}|null
	 */
	public function get_link_context_by_id( string $id ): ?array {
		$hash = strtolower( $id );
		if ( ! preg_match( '/^[a-f0-9]{64}$/', $hash ) ) {
			return null;
		}

		$post_id = $this->get_post_id_by_detail_hash( $hash );
		$link    = $post_id ? $this->get_link_record( $post_id, $hash ) : null;
		if ( ! $link ) {
			return null;
		}

		return [
			'post_id' => $post_id,
			'hash'    => $hash,
			'link'    => $link,
		];
	}

	/**
	 * Inspect a token for diagnostics without exposing token material.
	 *
	 * @param string $token Raw token.
	 * @return array{reason_code:string,post_id:int|null,status:string|null}
	 */
	public function get_token_diagnostic( string $token ): array {
		if ( '' === trim( $token ) ) {
			return [
				'reason_code' => 'missing_token',
				'post_id'     => null,
				'status'      => null,
			];
		}

		$hash    = $this->token_service->hash( $token );
		$post_id = $this->get_post_id_by_hash( $hash );

		if ( ! $post_id ) {
			return [
				'reason_code' => 'token_not_found',
				'post_id'     => null,
				'status'      => null,
			];
		}

		$stored_link = get_post_meta( $post_id, self::DETAIL_META_PREFIX . $hash, true );
		$link        = is_array( $stored_link ) ? $this->normalize_link_record( $stored_link, $hash ) : null;

		if ( ! $link ) {
			$this->delete_cache( $hash );

			return [
				'reason_code' => 'token_not_found',
				'post_id'     => $post_id,
				'status'      => null,
			];
		}

		if ( ! empty( $link['revoked'] ) ) {
			$this->delete_cache( $hash );

			return [
				'reason_code' => 'token_revoked',
				'post_id'     => $post_id,
				'status'      => 'revoked',
			];
		}

		if ( $this->is_link_expired( $link ) ) {
			$this->delete_cache( $hash );

			return [
				'reason_code' => 'token_expired',
				'post_id'     => $post_id,
				'status'      => 'expired',
			];
		}

		return [
			'reason_code' => 'active',
			'post_id'     => $post_id,
			'status'      => 'active',
		];
	}

	/**
	 * Record a successful preview view.
	 *
	 * @param string $token Raw token.
	 * @return bool
	 */
	public function record_token_view( string $token ): bool {
		$hash    = $this->token_service->hash( $token );
		$post_id = $this->get_post_id_by_hash( $hash );

		if ( ! $post_id ) {
			return false;
		}

		$key = self::DETAIL_META_PREFIX . $hash;
		for ( $attempt = 0; $attempt < 3; $attempt++ ) {
			$stored_link = get_post_meta( $post_id, $key, true );
			$link        = is_array( $stored_link ) ? $this->normalize_link_record( $stored_link, $hash ) : null;
			if ( ! $this->is_link_active( $link ) ) {
				return false;
			}

			$link['last_viewed_at'] = time();
			++$link['view_count'];
			// An identical replacement may have been written by another view in this second.
			$changed = PostMetaRow::compare_and_swap( $post_id, $key, $stored_link, $link );
			if ( false !== $changed ) {
				return true === $changed;
			}
		}

		return false;
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
		$per_page = max( 1, min( 100, $per_page ) );
		$offset   = ( max( 1, $page ) - 1 ) * $per_page;

		return $this->get_link_rows( $per_page, $offset );
	}

	/**
	 * Fetch one stable inventory page without changing the existing page API.
	 *
	 * @param int    $per_page Maximum page size.
	 * @param string $cursor Start sentinel or opaque continuation.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function list_tokens_by_cursor( int $per_page, string $cursor ) {
		$page = ( new LinkInventoryCursor( self::DETAIL_META_PREFIX ) )->page( $per_page, $cursor );
		if ( $page instanceof \WP_Error ) {
			return $page;
		}
		return [
			'items' => $this->format_link_rows( $page['rows'] ),
			'total' => $page['total'],
			'next_cursor' => $page['next_cursor'],
		];
	}

	/**
	 * Count all preview links.
	 *
	 * @return int
	 */
	public function count_tokens(): int {
		return $this->count_link_rows();
	}

	/**
	 * Return one status-filtered page and its exact total using the serialized record fields.
	 *
	 * @param string $status Link status.
	 * @param int    $per_page Number of rows per page.
	 * @param int    $page Page number.
	 * @return array{items:list<LinkListItem>,total:int}|\WP_Error
	 */
	public function list_tokens_by_status( string $status, int $per_page = 50, int $page = 1 ) {
		global $wpdb;

		$per_page = max( 1, min( 100, $per_page ) );
		$offset   = ( max( 1, $page ) - 1 ) * $per_page;
		$like     = $wpdb->esc_like( self::DETAIL_META_PREFIX ) . '%';
		$fallback = LinkStatusInventory::fallback_if_needed( self::DETAIL_META_PREFIX, $status, $per_page, $offset, \Closure::fromCallable( [ $this, 'format_link_rows' ] ) );
		if ( null !== $fallback ) {
			return $fallback;
		}

		[ $status_where, $status_args ] = PostMetaRow::status_clause( $status, time() );

		$base = "FROM {$wpdb->postmeta} pm
			INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			WHERE pm.meta_key LIKE %s
				AND p.post_type <> %s
				AND ($status_where)";
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- The fixed status clause is generated above; values are passed as placeholders.
		$count_sql = $wpdb->prepare( "SELECT COUNT(*) $base", ...array_merge( [ $like, 'revision' ], $status_args ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- SQL fragments are fixed local expressions and all variable values are passed through $wpdb->prepare; exact status count returns one scalar.
		$total = max( 0, (int) $wpdb->get_var( $count_sql ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- The fixed status clause is generated above; values are passed as placeholders.
		$list_sql = $wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $base contains fixed SQL expressions; all values are placeholders.
			"SELECT pm.post_id, pm.meta_key, pm.meta_value $base ORDER BY pm.meta_id DESC LIMIT %d OFFSET %d",
			...array_merge( [ $like, 'revision' ], $status_args, [ $per_page, $offset ] )
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- SQL fragments are fixed local expressions and all variable values are passed through $wpdb->prepare; the page is bounded.
		$rows = $wpdb->get_results( $list_sql, ARRAY_A );

		return [
			'items' => is_array( $rows ) ? $this->format_link_rows( $rows ) : [],
			'total' => $total,
		];
	}

	/**
	 * Flush object cache entries for tokens that belong to a post.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public function flush_post_cache( int $post_id ): void {
		if ( ! $this->is_caching_enabled() ) {
			return;
		}

		global $wpdb;
		$prefix = $wpdb->esc_like( self::DETAIL_META_PREFIX ) . '%';
		// Query authoritative keys directly: the request-local post-meta cache may predate a concurrent link.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Cache invalidation must see current detail keys; table name is WordPress-owned.
		$keys = $wpdb->get_col( $wpdb->prepare( "SELECT meta_key FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key LIKE %s", $post_id, $prefix ) );
		foreach ( is_array( $keys ) ? $keys : [] as $key ) {
			if ( 0 === strpos( (string) $key, self::DETAIL_META_PREFIX ) ) {
				wp_cache_delete( substr( (string) $key, strlen( self::DETAIL_META_PREFIX ) ), self::CACHE_GROUP );
			}
		}
	}

	/**
	 * Revoke a token by raw token.
	 *
	 * @param string $token Raw token.
	 * @return bool
	 */
	public function revoke_token( string $token ): bool {
		return $this->revoke_token_by_id( $this->token_service->hash( $token ) );
	}

	/**
	 * Revoke a token by link ID/hash.
	 *
	 * @param string $id Link ID/hash.
	 * @return bool
	 */
	public function revoke_token_by_id( string $id ): bool {
		if ( 1 !== preg_match( '/\\A[a-fA-F0-9]{64}\\z/', $id ) ) {
			return false;
		}

		$hash    = strtolower( $id );
		$post_id = $this->get_post_id_by_detail_hash( $hash );

		if ( ! $post_id ) {
			return false;
		}

		return $this->revoke_token_and_refresh_sharing( $post_id, $hash );
	}

	/**
	 * Revoke a link and remove its inventory and lookup metadata.
	 *
	 * Revocation is persisted before cleanup. If cleanup is interrupted, the
	 * detail record remains revoked and takes precedence over aggregate data.
	 *
	 * @param string $id Link ID/hash.
	 * @return true|\WP_Error
	 */
	public function delete_token_by_id( string $id ) {
		if ( 1 !== preg_match( '/\\A[a-fA-F0-9]{64}\\z/', $id ) ) {
			return new \WP_Error( 'invalid_id', 'Invalid preview link.', [ 'status' => 400 ] );
		}

		$hash    = strtolower( $id );
		$post_id = $this->get_post_id_by_detail_hash( $hash );
		$link    = $post_id ? $this->get_link_record( $post_id, $hash ) : null;
		if ( ! $post_id || ! $link ) {
			return new \WP_Error( 'link_not_found', 'Preview link not found.', [ 'status' => 404 ] );
		}

		if ( ! $this->revoke_token_and_refresh_sharing( $post_id, $hash ) ) {
			return new \WP_Error( 'link_revoke_failed', 'Preview access could not be revoked.', [ 'status' => 500 ] );
		}

		if ( ! $this->remove_aggregate_link( $post_id, $hash ) ) {
			return new \WP_Error( 'link_delete_failed', 'Preview link could not be removed from inventory.', [ 'status' => 500 ] );
		}

		// Keep the revoked detail record until every resolver index is removed.
		$current_hash = get_post_meta( $post_id, self::CURRENT_HASH_META_KEY, true );
		if ( is_string( $current_hash ) && strtolower( $current_hash ) === $hash && ! $this->delete_post_meta_value( $post_id, self::CURRENT_HASH_META_KEY, $current_hash ) ) {
			return new \WP_Error( 'link_delete_failed', 'Preview link could not be removed from inventory.', [ 'status' => 500 ] );
		}
		if ( ! $this->delete_post_meta_value( $post_id, self::REVERSE_META_PREFIX . $hash ) ) {
			return new \WP_Error( 'link_delete_failed', 'Preview link could not be removed from inventory.', [ 'status' => 500 ] );
		}

		if ( ! $this->delete_post_meta_value( $post_id, self::DETAIL_META_PREFIX . $hash ) ) {
			return new \WP_Error( 'link_delete_failed', 'Preview link could not be removed from inventory.', [ 'status' => 500 ] );
		}

		$this->delete_cache( $hash );
		return true;
	}

	/**
	 * Get an opaque preview link context by its inventory identifier.
	 *
	 * This returns no raw token material and is intended for authenticated
	 * management operations that already know the stored identifier.
	 *
	 * @param string $id Link ID/hash.
	 * @return array{id:string,post_id:int,label:string,expires_at:int|null,status:string}|null Link context, or null when not found.
	 */
	public function get_token_context_by_id( string $id ): ?array {
		if ( 1 !== preg_match( '/\\A[a-f0-9]{64}\\z/', $id ) ) {
			return null;
		}

		$hash = $id;

		$post_id = $this->get_post_id_by_detail_hash( $hash );

		if ( ! $post_id ) {
			return null;
		}

		$stored_link = get_post_meta( $post_id, self::DETAIL_META_PREFIX . $hash, true );
		$link        = is_array( $stored_link ) ? $this->normalize_link_record( $stored_link, $hash ) : null;

		if ( ! $link ) {
			return null;
		}

		return [
			'id'         => $hash,
			'post_id'    => $post_id,
			'label'      => $link['label'],
			'expires_at' => $link['expires_at'],
			'status'     => $this->get_link_status( $link ),
		];
	}

	/**
	 * Extend an active expiring link by 24 hours.
	 *
	 * @param string $id Link ID/hash.
	 * @return array{expires_at:int}|\WP_Error
	 */
	public function extend_token_by_id( string $id ) {
		$hash = sanitize_key( $id );
		if ( '' === $hash ) {
			return new \WP_Error( 'invalid_id', 'Invalid preview link.', [ 'status' => 400 ] );
		}

		$post_id = $this->get_post_id_by_hash( $hash );
		if ( ! $post_id ) {
			return new \WP_Error( 'link_not_found', 'Preview link not found.', [ 'status' => 404 ] );
		}

		$stored_link = get_post_meta( $post_id, self::DETAIL_META_PREFIX . $hash, true );
		$link        = is_array( $stored_link ) ? $this->normalize_link_record( $stored_link, $hash ) : null;
		if (
			! $link
			|| ! $this->is_link_active( $link )
			|| null === $link['expires_at']
			|| (int) $link['expires_at'] > time() + DAY_IN_SECONDS
		) {
			return new \WP_Error( 'link_not_extendable', 'Only active links expiring within 24 hours can be extended.', [ 'status' => 409 ] );
		}

		$updated_link = array_merge(
			$link,
			[ 'expires_at' => (int) $link['expires_at'] + DAY_IN_SECONDS ]
		);
		if ( ! $this->compare_and_swap_existing_meta( $post_id, self::DETAIL_META_PREFIX . $hash, $stored_link, $updated_link ) ) {
			return new \WP_Error( 'link_update_failed', 'Preview link could not be extended.', [ 'status' => 500 ] );
		}
		$this->update_existing_aggregate_link( $post_id, $hash, $updated_link );

		$this->delete_cache( $hash );

		return [ 'expires_at' => (int) $updated_link['expires_at'] ];
	}

	/**
	 * Set the response policy for one link without changing its URL or history.
	 *
	 * @param string $id Link identifier.
	 * @param bool   $enabled Whether responses are enabled.
	 * @param bool   $identity_required Whether both name and email are required.
	 * @return bool
	 */
	public function set_review_policy_by_id( string $id, bool $enabled, bool $identity_required ): bool {
		$context = $this->get_link_context_by_id( $id );
		if ( ! $context ) {
			return false;
		}

		$post_id       = $context['post_id'];
		$hash          = $context['hash'];
		$stored_detail = get_post_meta( $post_id, self::DETAIL_META_PREFIX . $hash, true );
		if ( ! is_array( $stored_detail ) ) {
			return false;
		}
		$updated                      = $this->normalize_link_record( $stored_detail, $hash );
		$updated['responses_enabled'] = $enabled;
		$updated['identity_required'] = $enabled && $identity_required;
		$detail                       = $updated;

		if ( ! $this->compare_and_swap_existing_meta( $post_id, self::DETAIL_META_PREFIX . $hash, $stored_detail, $detail ) ) {
			return false;
		}
		$this->update_existing_aggregate_link( $post_id, $hash, $detail );

		$this->delete_cache( $hash );
		return true;
	}

	/**
	 * Revoke every authoritative link in the initial post snapshot.
	 *
	 * A new detail row inserted after that snapshot is a later generation and
	 * may survive. Existing detail rows are never restored from the aggregate.
	 *
	 * @param int $post_id Post ID.
	 * @return bool True when a token was revoked.
	 */
	public function revoke_token_for_post( int $post_id ): bool {
		$snapshot = $this->get_link_detail_snapshot( $post_id );
		if ( empty( $snapshot ) ) {
			PostMetaRow::disable_sharing_without_active_links( $post_id, self::DETAIL_META_PREFIX );
			return false;
		}

		$revoked = [];
		foreach ( $snapshot as $hash => $detail ) {
			$link = $this->revoke_link_detail( $post_id, $hash, $detail );
			if ( $link ) {
				$revoked[ $hash ] = true;
			} elseif ( is_array( get_post_meta( $post_id, self::DETAIL_META_PREFIX . $hash, true ) ) ) {
				PostMetaRow::mark_aggregate_revoked( $post_id, self::LINKS_META_KEY, $revoked );
				return false;
			}
		}
		PostMetaRow::mark_aggregate_revoked( $post_id, self::LINKS_META_KEY, $revoked );

		// Confirm the entire original snapshot is revoked or has since been
		// deleted. A partial success must never be reported as completion.
		$remaining = $this->get_link_detail_snapshot( $post_id );
		foreach ( array_keys( $snapshot ) as $hash ) {
			if ( isset( $remaining[ $hash ] ) && empty( $remaining[ $hash ]['revoked'] ) ) {
				return false;
			}
		}

		$this->refresh_sharing_after_revoke( $post_id, $remaining );
		return true;
	}

	/**
	 * Return the authoritative link snapshot for a post.
	 *
	 * The aggregate is only a compatibility projection: initial concurrent
	 * writes can replace it because WordPress has no unique post-meta key and
	 * an empty previous value does not constrain update_post_meta(). Enumerate
	 * detail rows instead, including legacy and previously unlisted links.
	 *
	 * @param int $post_id Post ID.
	 * @return array<string,LinkRecord>
	 */
	public function get_links_for_post( int $post_id ): array {
		$links = [];
		foreach ( $this->get_link_detail_snapshot( $post_id ) as $hash => $detail ) {
			$links[ $hash ] = $this->normalize_link_record( $detail, $hash );
		}
		return $links;
	}

	/**
	 * Read fresh raw detail values suitable for conditional writes.
	 *
	 * @param int $post_id Post ID.
	 * @return array<string,array<string,mixed>>
	 */
	private function get_link_detail_snapshot( int $post_id ): array {
		// A request-local meta cache may predate another request's generation.
		wp_cache_delete( $post_id, 'post_meta' );
		$metadata = get_post_meta( $post_id, '', false );
		$links    = [];

		foreach ( is_array( $metadata ) ? $metadata : [] as $key => $values ) {
			if ( 0 !== strpos( (string) $key, self::DETAIL_META_PREFIX ) || ! is_array( $values ) ) {
				continue;
			}
			$hash   = substr( (string) $key, strlen( self::DETAIL_META_PREFIX ) );
			$detail = maybe_unserialize( $values[0] ?? null );
			if ( '' !== $hash && is_array( $detail ) ) {
				$links[ $hash ] = $detail;
			}
		}

		return $links;
	}

	/**
	 * Revoke an extant detail with bounded retries after a concurrent write.
	 *
	 * @param int                 $post_id Post ID.
	 * @param string              $hash Token hash.
	 * @param array<string,mixed> $stored_link Raw link detail at the start of the operation.
	 * @return LinkRecord|null
	 */
	private function revoke_link_detail( int $post_id, string $hash, array $stored_link ): ?array {
		$key = self::DETAIL_META_PREFIX . $hash;
		for ( $attempt = 0; $attempt < 3; $attempt++ ) {
			$link            = $this->normalize_link_record( $stored_link, $hash );
			$link['revoked'] = 1;
			if ( ! empty( $stored_link['revoked'] ) || $this->compare_and_swap_existing_meta( $post_id, $key, $stored_link, $link ) ) {
				$this->delete_cache( $hash );
				return $link;
			}
			$stored_link = get_post_meta( $post_id, $key, true );
			if ( ! is_array( $stored_link ) ) {
				break;
			}
		}
		$this->delete_cache( $hash );
		return null;
	}

	/**
	 * Revoke a single link and synchronize the post's sharing flag.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $hash Token hash.
	 * @return bool
	 */
	private function revoke_token_and_refresh_sharing( int $post_id, string $hash ): bool {
		$detail = get_post_meta( $post_id, self::DETAIL_META_PREFIX . $hash, true );
		$link   = is_array( $detail ) ? $this->revoke_link_detail( $post_id, $hash, $detail ) : null;
		if ( ! $link ) {
			return false;
		}
		$aggregate_ok = PostMetaRow::mark_aggregate_revoked( $post_id, self::LINKS_META_KEY, [ $hash => true ] );
		$links        = $this->get_link_detail_snapshot( $post_id );
		$this->refresh_sharing_after_revoke( $post_id, $links );
		return $aggregate_ok || ! empty( $links[ $hash ]['revoked'] );
	}

	/**
	 * Remove a single link from the aggregate link map with a conditional write.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $hash Link hash.
	 * @return bool
	 */
	private function remove_aggregate_link( int $post_id, string $hash ): bool {
		for ( $attempt = 0; $attempt < 3; $attempt++ ) {
			$previous = get_post_meta( $post_id, self::LINKS_META_KEY, true );
			if ( ! is_array( $previous ) || ! array_key_exists( $hash, $previous ) ) {
				return true;
			}
			$updated = $previous;
			unset( $updated[ $hash ] );

			$result = update_post_meta( $post_id, self::LINKS_META_KEY, $updated, $previous );
			if ( false !== $result || $this->post_meta_value_matches( get_post_meta( $post_id, self::LINKS_META_KEY, true ), $updated ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Delete metadata and verify it is gone when WordPress reports no change.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $key Meta key.
	 * @param mixed  $previous Optional exact previous value for conditional deletion.
	 * @return bool
	 */
	private function delete_post_meta_value( int $post_id, string $key, $previous = null ): bool {
		$result  = func_num_args() >= 3
			? delete_post_meta( $post_id, $key, $previous )
			: delete_post_meta( $post_id, $key );
		$current = get_post_meta( $post_id, $key, true );
		return (bool) $result || '' === $current || ( func_num_args() >= 3 && $current !== $previous );
	}

	/**
	 * Synchronize an existing aggregate entry without creating one after deletion.
	 *
	 * @param int                 $post_id Post ID.
	 * @param string              $hash Link hash.
	 * @param array<string,mixed> $link Updated record.
	 * @return bool
	 */
	private function update_existing_aggregate_link( int $post_id, string $hash, array $link ): bool {
		for ( $attempt = 0; $attempt < 3; $attempt++ ) {
			$previous = get_post_meta( $post_id, self::LINKS_META_KEY, true );
			if ( ! is_array( $previous ) || ! array_key_exists( $hash, $previous ) ) {
				return true;
			}
			$updated          = $previous;
			$updated[ $hash ] = $link;
			$result           = update_post_meta( $post_id, self::LINKS_META_KEY, $updated, $previous );
			if ( false !== $result || $this->post_meta_value_matches( get_post_meta( $post_id, self::LINKS_META_KEY, true ), $updated ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Replace an existing metadata row only if its previous value still matches.
	 * This intentionally avoids update_post_meta(), which inserts a missing row.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $key Meta key.
	 * @param mixed  $previous Existing value.
	 * @param mixed  $value Replacement value.
	 * @return bool
	 */
	private function compare_and_swap_existing_meta( int $post_id, string $key, $previous, $value ): bool {
		$changed = PostMetaRow::compare_and_swap( $post_id, $key, $previous, $value );
		return true === $changed || ( false === $changed && $this->post_meta_value_matches( get_post_meta( $post_id, $key, true ), $value ) );
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
	 * Resolve a post ID by detail key across the admin inventory's post domain.
	 * This lookup is separate from raw-token resolution.
	 *
	 * @param string $hash Token hash.
	 * @return int
	 */
	private function get_post_id_by_detail_hash( string $hash ): int {
		global $wpdb;
		if ( is_object( $wpdb ) && isset( $wpdb->postmeta, $wpdb->posts ) && method_exists( $wpdb, 'prepare' ) && method_exists( $wpdb, 'get_var' ) ) {
			$sql = $wpdb->prepare(
				"SELECT pm.post_id FROM {$wpdb->postmeta} pm
				INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				WHERE pm.meta_key = %s AND p.post_type <> %s
				LIMIT 1",
				self::DETAIL_META_PREFIX . $hash,
				'revision'
			);
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Exact-key recovery is bounded to one identifier and matches the admin inventory domain.
			return max( 0, (int) $wpdb->get_var( $sql ) );
		}
		return 0;
	}

	/**
	 * Get a link record by post/hash.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $hash Token hash.
	 * @return LinkRecord|null
	 */
	private function get_link_record( int $post_id, string $hash ): ?array {
		// Detail metadata is authoritative. An aggregate snapshot can be stale
		// after deletion and must never restore public preview access.
		return $this->get_link_detail_record( $post_id, $hash );
	}

	/**
	 * Get a link detail record by post/hash.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $hash Token hash.
	 * @return LinkRecord|null
	 */
	private function get_link_detail_record( int $post_id, string $hash ): ?array {
		$detail = get_post_meta( $post_id, self::DETAIL_META_PREFIX . $hash, true );

		if ( ! is_array( $detail ) ) {
			return null;
		}

		return $this->normalize_link_record( $detail, $hash );
	}

	/**
	 * Update post meta that is required for token lookup consistency.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $key Meta key.
	 * @param mixed  $value Meta value.
	 * @return bool
	 */
	private function update_required_post_meta( int $post_id, string $key, $value ): bool {
		$result = update_post_meta( $post_id, $key, $value );

		if ( false !== $result ) {
			return true;
		}

		return $this->post_meta_value_matches( get_post_meta( $post_id, $key, true ), $value );
	}

	/**
	 * Check whether a stored meta value matches an expected value.
	 *
	 * @param mixed $stored Stored meta value.
	 * @param mixed $expected Expected meta value.
	 * @return bool
	 */
	private function post_meta_value_matches( $stored, $expected ): bool {
		return maybe_serialize( $stored ) === maybe_serialize( $expected );
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
			'responses_enabled' => ! empty( $link['responses_enabled'] ),
			'identity_required' => ! empty( $link['responses_enabled'] ) && ! empty( $link['identity_required'] ),
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
			'responses_enabled' => $link['responses_enabled'],
			'identity_required' => $link['identity_required'],
		];
	}

	/**
	 * Get a bounded set of link detail rows for the admin inventory.
	 *
	 * @param int $per_page Number of rows to fetch.
	 * @param int $offset Row offset.
	 * @return list<LinkListItem>
	 */
	private function get_link_rows( int $per_page, int $offset ): array {
		global $wpdb;

		$like = $wpdb->esc_like( self::DETAIL_META_PREFIX ) . '%';
		$sql  = $wpdb->prepare(
			"SELECT pm.post_id, pm.meta_key, pm.meta_value
			FROM {$wpdb->postmeta} pm
			INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			WHERE pm.meta_key LIKE %s
				AND p.post_type <> %s
			ORDER BY pm.meta_id DESC
			LIMIT %d OFFSET %d",
			$like,
			'revision',
			$per_page,
			max( 0, $offset )
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Admin token inventory is bounded and uses the prepared SQL built above.
		$rows = $wpdb->get_results( $sql, ARRAY_A );

		if ( ! is_array( $rows ) ) {
			return [];
		}

		return $this->format_link_rows( $rows );
	}

	/**
	 * Count link detail rows without loading the full inventory.
	 *
	 * @return int
	 */
	private function count_link_rows(): int {
		global $wpdb;

		$like = $wpdb->esc_like( self::DETAIL_META_PREFIX ) . '%';
		$sql  = $wpdb->prepare(
			"SELECT COUNT(*)
			FROM {$wpdb->postmeta} pm
			INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			WHERE pm.meta_key LIKE %s
				AND p.post_type <> %s",
			$like,
			'revision'
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Admin token inventory count is a bounded scalar query and uses the prepared SQL built above.
		return max( 0, (int) $wpdb->get_var( $sql ) );
	}

	/**
	 * Format raw postmeta rows as token inventory rows.
	 *
	 * @param array<int,array<string,mixed>> $rows Raw postmeta rows.
	 * @return list<LinkListItem>
	 */
	private function format_link_rows( array $rows ): array {
		// phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan variable type annotation.
		/** @var list<LinkListItem> $items Preview link list items. */
		$items = [];

		foreach ( $rows as $row ) {
			$meta_key = isset( $row['meta_key'] ) ? (string) $row['meta_key'] : '';

			if ( 0 !== strpos( $meta_key, self::DETAIL_META_PREFIX ) ) {
				continue;
			}

			$hash   = substr( $meta_key, strlen( self::DETAIL_META_PREFIX ) );
			$detail = maybe_unserialize( $row['meta_value'] ?? null );

			if ( '' === $hash || ! is_array( $detail ) ) {
				continue;
			}

			$item    = $this->format_link_for_response( $this->normalize_link_record( $detail, $hash ) );
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
				'responses_enabled' => $item['responses_enabled'],
				'identity_required' => $item['identity_required'],
				'post_id'        => isset( $row['post_id'] ) ? (int) $row['post_id'] : 0,
			];
		}

		return $items;
	}

	/**
	 * Refresh the sharing flag after revocation while respecting legacy detail records.
	 *
	 * @param int                               $post_id Post ID.
	 * @param array<string,array<string,mixed>> $links Fresh raw detail snapshot.
	 * @return void
	 */
	private function refresh_sharing_after_revoke( int $post_id, array $links ): void {
		foreach ( $links as $hash => $link ) {
			if ( $this->is_link_active( $this->normalize_link_record( $link, $hash ) ) ) {
				return;
			}
		}

		PostMetaRow::disable_sharing_without_active_links( $post_id, self::DETAIL_META_PREFIX, $links );
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
