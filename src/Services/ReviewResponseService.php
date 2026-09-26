<?php
/**
 * Reviewer response persistence and privacy.
 *
 * @package PreviewShare
 */

namespace PreviewShare\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stores private review records using WordPress post and meta APIs.
 */
final class ReviewResponseService {

	private const POST_TYPE      = 'previewshare_review';
	private const RETENTION_DAYS = 90;
	private const CLEANUP_HOOK   = 'previewshare_cleanup_reviews';

	/**
	 * Register lifecycle and privacy hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'init', [ $this, 'register_type' ], 20 );
		add_action( 'init', [ $this, 'schedule_cleanup' ], 21 );
		add_action( self::CLEANUP_HOOK, [ $this, 'purge_expired' ] );
		add_action( 'before_delete_post', [ $this, 'delete_for_post' ] );
		add_filter( 'wp_privacy_personal_data_exporters', [ $this, 'register_exporter' ] );
		add_filter( 'wp_privacy_personal_data_erasers', [ $this, 'register_eraser' ] );
	}

	/**
	 * Keep review records outside public queries, search, UI, and REST.
	 *
	 * @return void
	 */
	public function register_type(): void {
		register_post_type(
			self::POST_TYPE,
			[
				'label'                => __( 'PreviewShare reviews', 'previewshare' ),
				'public'               => false,
				'publicly_queryable'   => false,
				'exclude_from_search'  => true,
				'show_ui'              => false,
				'show_in_rest'         => false,
				'query_var'            => false,
				'rewrite'              => false,
				'can_export'           => false,
				'supports'             => [],
			]
		);
	}

	/**
	 * Schedule daily cleanup for the 90-day retention period.
	 *
	 * @return void
	 */
	public function schedule_cleanup(): void {
		if ( ! wp_next_scheduled( self::CLEANUP_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CLEANUP_HOOK );
		}
	}

	/**
	 * Clear scheduled work on deactivation.
	 *
	 * @return void
	 */
	public static function unschedule_cleanup(): void {
		wp_clear_scheduled_hook( self::CLEANUP_HOOK );
	}

	/**
	 * Persist a validated response. The option name uniquely identifies retries.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $link_hash HMAC link identifier.
	 * @param string $content_hash Reviewed content fingerprint.
	 * @param string $response_type Response type.
	 * @param string $name Reviewer name.
	 * @param string $email Reviewer email.
	 * @param string $comment Plain-text feedback.
	 * @param string $submission_key HMAC idempotency key.
	 * @return int|\WP_Error Created row ID or error.
	 */
	public function create_response( int $post_id, string $link_hash, string $content_hash, string $response_type, string $name, string $email, string $comment, string $submission_key ) {
		$option_name = 'previewshare_review_request_' . $submission_key;
		if ( ! add_option( $option_name, 0, '', false ) ) {
			return new \WP_Error( 'review_duplicate', __( 'This response was already received.', 'previewshare' ), [ 'status' => 409 ] );
		}

		$created_gmt = current_time( 'mysql', true );
		$review_id   = wp_insert_post(
			[
				'post_type'       => self::POST_TYPE,
				'post_status'     => 'private',
				'post_date'       => get_date_from_gmt( $created_gmt ),
				'post_date_gmt'   => $created_gmt,
				'post_parent'     => $post_id,
				'post_title'      => __( 'PreviewShare review response', 'previewshare' ),
				'post_content'    => $comment,
				'comment_status'  => 'closed',
				'ping_status'     => 'closed',
			],
			true
		);

		if ( is_wp_error( $review_id ) || ! $review_id ) {
			delete_option( $option_name );
			return new \WP_Error( 'review_storage_failed', __( 'The response could not be saved. Please try again.', 'previewshare' ), [ 'status' => 500 ] );
		}

		$meta = [
			'_previewshare_link_hash'         => $link_hash,
			'_previewshare_content_hash'      => $content_hash,
			'_previewshare_response_type'     => $response_type,
			'_previewshare_reviewer_name'     => $name,
			'_previewshare_reviewer_email'    => $email,
			'_previewshare_submission_option' => $option_name,
		];
		foreach ( $meta as $key => $value ) {
			if ( ! add_post_meta( (int) $review_id, $key, $value, true ) ) {
				wp_delete_post( (int) $review_id, true );
				delete_option( $option_name );
				return new \WP_Error( 'review_storage_failed', __( 'The response could not be saved. Please try again.', 'previewshare' ), [ 'status' => 500 ] );
			}
		}

		update_option( $option_name, (int) $review_id, false );
		do_action( 'previewshare_review_response_accepted', (int) $review_id, $post_id, $link_hash, $response_type );
		return (int) $review_id;
	}

	/**
	 * Return a bounded history page for an authorized editor.
	 *
	 * @param int    $post_id Owning post ID.
	 * @param string $link_hash Link identifier.
	 * @param int    $page One-based page number.
	 * @return array<int,array<string,mixed>>
	 */
	public function history( int $post_id, string $link_hash, int $page = 1 ): array {
		$posts = get_posts(
			[
				'post_type'      => self::POST_TYPE,
				'post_status'    => 'private',
				'post_parent'    => $post_id,
				'posts_per_page' => 50,
				'paged'          => max( 1, $page ),
				'orderby'        => 'ID',
				'order'          => 'DESC',
				'no_found_rows'  => true,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Review history is scoped to one post and paginated.
				'meta_key'       => '_previewshare_link_hash',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Review history is scoped to one post and paginated.
				'meta_value'     => $link_hash,
			]
		);

		return array_map( [ $this, 'format_response' ], is_array( $posts ) ? $posts : [] );
	}

	/**
	 * Return the latest response for a link.
	 *
	 * @param int    $post_id Owning post ID.
	 * @param string $link_hash Link identifier.
	 * @return array<string,mixed>|null
	 */
	public function latest( int $post_id, string $link_hash ): ?array {
		$history = $this->history( $post_id, $link_hash );
		return $history[0] ?? null;
	}

	/**
	 * Fetch the latest response for every link on an inventory page in one query.
	 *
	 * @param array<string,int> $link_owners Link hash mapped to owning post ID.
	 * @return array<string,array<string,mixed>> Latest response data keyed by link hash.
	 */
	public function latest_for_links( array $link_owners ): array {
		if ( ! $link_owners ) {
			return [];
		}

		global $wpdb;
		$post_ids          = array_values( array_unique( array_map( 'intval', array_values( $link_owners ) ) ) );
		$post_placeholders = implode( ', ', array_fill( 0, count( $post_ids ), '%d' ) );
		$link_placeholders = implode( ', ', array_fill( 0, count( $link_owners ), '%s' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names are WordPress-owned; IN placeholders are generated above.
		$sql       = "SELECT links.meta_value AS link_hash, MAX(reviews.ID) AS response_id, MIN(reviews.post_parent) AS post_id
			FROM {$wpdb->posts} AS reviews
			INNER JOIN {$wpdb->postmeta} AS links ON links.post_id = reviews.ID
			WHERE reviews.post_type = %s AND reviews.post_status = %s AND links.meta_key = %s
			AND reviews.post_parent IN ({$post_placeholders}) AND links.meta_value IN ({$link_placeholders})
			GROUP BY links.meta_value";
		$arguments = array_merge( [ self::POST_TYPE, 'private', '_previewshare_link_hash' ], $post_ids, array_keys( $link_owners ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Bounded inventory read must reflect current review state.
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $arguments ), ARRAY_A );
		if ( ! is_array( $rows ) || $wpdb->last_error ) {
			$result = [];
			foreach ( $link_owners as $hash => $post_id ) {
				$latest = $this->latest( $post_id, $hash );
				if ( $latest ) {
					$result[ $hash ] = $latest;
				}
			}
			return $result;
		}

		$ids = array_map( 'intval', array_column( $rows, 'response_id' ) );
		if ( $ids ) {
			update_meta_cache( 'post', $ids );
		}
		$result = [];
		foreach ( $rows as $row ) {
			$hash = (string) $row['link_hash'];
			if ( ! isset( $link_owners[ $hash ] ) || $link_owners[ $hash ] !== (int) $row['post_id'] ) {
				continue;
			}
			$id              = (int) $row['response_id'];
			$result[ $hash ] = [
				'response_type' => (string) get_post_meta( $id, '_previewshare_response_type', true ),
				'content_hash'  => (string) get_post_meta( $id, '_previewshare_content_hash', true ),
				'resolved_at'   => (int) get_post_meta( $id, '_previewshare_resolved_at', true ),
			];
		}
		return $result;
	}

	/**
	 * Derive the current review state for an authorized editor.
	 *
	 * @param \WP_Post $post Reviewed post.
	 * @param string   $link_hash Link identifier.
	 * @return string
	 */
	public function state( \WP_Post $post, string $link_hash ): string {
		return ReviewVersion::state( $this->latest( (int) $post->ID, $link_hash ), ReviewVersion::fingerprint( $post ) );
	}

	/**
	 * Count recent responses to bound abuse of a shared link.
	 *
	 * @param string $link_hash Link identifier.
	 * @param int    $since Unix timestamp lower bound.
	 * @return int
	 */
	public function count_recent( string $link_hash, int $since ): int {
		$posts = get_posts(
			[
				'post_type'      => self::POST_TYPE,
				'post_status'    => 'private',
				'posts_per_page' => 100,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Bounded abuse query needs one indexed review field.
				'meta_key'       => '_previewshare_link_hash',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Bounded abuse query needs this link's current count.
				'meta_value'     => $link_hash,
				'date_query'     => [
					[
						'column' => 'post_date_gmt',
						'after' => gmdate( 'Y-m-d H:i:s', $since ),
					],
				],
			]
		);
		return is_array( $posts ) ? count( $posts ) : 0;
	}

	/**
	 * Resolve a change request without removing its history.
	 *
	 * @param int    $post_id Owning post ID.
	 * @param string $link_hash Link identifier.
	 * @param int    $response_id Response record ID.
	 * @return bool
	 */
	public function resolve_change_request( int $post_id, string $link_hash, int $response_id ): bool {
		$review = get_post( $response_id );
		if (
			! $review
			|| self::POST_TYPE !== $review->post_type
			|| $post_id !== (int) $review->post_parent
			|| $link_hash !== get_post_meta( $response_id, '_previewshare_link_hash', true )
			|| 'request_changes' !== get_post_meta( $response_id, '_previewshare_response_type', true )
			|| get_post_meta( $response_id, '_previewshare_resolved_at', true )
		) {
			return false;
		}
		return (bool) add_post_meta( $response_id, '_previewshare_resolved_at', time(), true );
	}

	/**
	 * Purge responses once their 90-day retention window has elapsed.
	 *
	 * @return void
	 */
	public function purge_expired(): void {
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( self::RETENTION_DAYS * DAY_IN_SECONDS ) );
		for ( $batch = 0; $batch < 20; $batch++ ) {
			$ids = get_posts(
				[
					'post_type'      => self::POST_TYPE,
					'post_status'    => 'private',
					'posts_per_page' => 100,
					'fields'         => 'ids',
					'orderby'        => 'ID',
					'order'          => 'ASC',
					'no_found_rows'  => true,
					'date_query'     => [
						[
							'column' => 'post_date_gmt',
							'before' => $cutoff,
						],
					],
				]
			);
			foreach ( $ids as $id ) {
				$this->delete_response( (int) $id );
			}
			if ( count( $ids ) < 100 ) {
				break;
			}
		}
	}

	/**
	 * Remove child review records when their owning post is deleted.
	 *
	 * @param int $post_id Post being deleted.
	 * @return void
	 */
	public function delete_for_post( int $post_id ): void {
		if ( self::POST_TYPE === get_post_type( $post_id ) ) {
			return;
		}
		$ids = get_posts(
			[
				'post_type'      => self::POST_TYPE,
				'post_status'    => 'private',
				'post_parent'    => $post_id,
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			]
		);
		foreach ( $ids as $id ) {
			$this->delete_response( (int) $id );
		}
	}

	/**
	 * Register the WordPress privacy exporter.
	 *
	 * @param array<string,mixed> $exporters Existing exporters.
	 * @return array<string,mixed>
	 */
	public function register_exporter( array $exporters ): array {
		$exporters['previewshare-reviews'] = [
			'exporter_friendly_name' => __( 'PreviewShare reviewer responses', 'previewshare' ),
			'callback'               => [ $this, 'export_by_email' ],
		];
		return $exporters;
	}

	/**
	 * Register the WordPress privacy eraser.
	 *
	 * @param array<string,mixed> $erasers Existing erasers.
	 * @return array<string,mixed>
	 */
	public function register_eraser( array $erasers ): array {
		$erasers['previewshare-reviews'] = [
			'eraser_friendly_name' => __( 'PreviewShare reviewer responses', 'previewshare' ),
			'callback'             => [ $this, 'erase_by_email' ],
		];
		return $erasers;
	}

	/**
	 * Export one page of responses for an email address.
	 *
	 * @param string $email_address Requested email.
	 * @param int    $page One-based page number.
	 * @return array<string,mixed>
	 */
	public function export_by_email( string $email_address, int $page = 1 ): array {
		$email = sanitize_email( $email_address );
		if ( '' === $email ) {
			return [
				'data' => [],
				'done' => true,
			];
		}
		$posts = $this->posts_for_email( $email, max( 1, $page ) );
		$data  = [];
		foreach ( $posts as $post ) {
			$response = $this->format_response( $post );
			$data[]   = [
				'group_id'    => 'previewshare-reviews',
				'group_label' => __( 'PreviewShare reviewer responses', 'previewshare' ),
				'item_id'     => 'previewshare-review-' . $post->ID,
				'data'        => [
					[
						'name' => __( 'Post ID', 'previewshare' ),
						'value' => (string) $post->post_parent,
					],
					[
						'name' => __( 'Response', 'previewshare' ),
						'value' => (string) $response['response_type'],
					],
					[
						'name' => __( 'Name', 'previewshare' ),
						'value' => (string) $response['reviewer_name'],
					],
					[
						'name' => __( 'Email', 'previewshare' ),
						'value' => (string) $response['reviewer_email'],
					],
					[
						'name' => __( 'Comment', 'previewshare' ),
						'value' => (string) $response['comment'],
					],
					[
						'name' => __( 'Submitted (UTC)', 'previewshare' ),
						'value' => gmdate( 'c', (int) $response['created_at'] ),
					],
				],
			];
		}
		return [
			'data' => $data,
			'done' => count( $posts ) < 100,
		];
	}

	/**
	 * Erase responses for an email address.
	 *
	 * @param string $email_address Requested email.
	 * @param int    $page WordPress privacy page (deletion always uses first page).
	 * @return array<string,mixed>
	 */
	public function erase_by_email( string $email_address, int $page = 1 ): array {
		unset( $page );
		$email = sanitize_email( $email_address );
		$posts = '' === $email ? [] : $this->posts_for_email( $email, 1 );
		foreach ( $posts as $post ) {
			$this->delete_response( (int) $post->ID );
		}
		return [
			'items_removed'  => ! empty( $posts ),
			'items_retained' => false,
			'messages'       => [],
			'done'           => count( $posts ) < 100,
		];
	}

	/**
	 * Format one private record for an authorized editor or privacy export.
	 *
	 * @param \WP_Post $post Review record.
	 * @return array<string,mixed>
	 */
	private function format_response( \WP_Post $post ): array {
		return [
			'id'             => (int) $post->ID,
			'post_id'        => (int) $post->post_parent,
			'content_hash'   => (string) get_post_meta( $post->ID, '_previewshare_content_hash', true ),
			'response_type'  => (string) get_post_meta( $post->ID, '_previewshare_response_type', true ),
			'reviewer_name'  => (string) get_post_meta( $post->ID, '_previewshare_reviewer_name', true ),
			'reviewer_email' => (string) get_post_meta( $post->ID, '_previewshare_reviewer_email', true ),
			'comment'        => (string) $post->post_content,
			'created_at'     => (int) get_post_time( 'U', true, $post ),
			'resolved_at'    => (int) get_post_meta( $post->ID, '_previewshare_resolved_at', true ),
		];
	}

	/**
	 * Query a bounded privacy page by exact reviewer email.
	 *
	 * @param string $email Sanitized email.
	 * @param int    $page One-based page number.
	 * @return array<int,\WP_Post>
	 */
	private function posts_for_email( string $email, int $page ): array {
		$posts = get_posts(
			[
				'post_type'      => self::POST_TYPE,
				'post_status'    => 'private',
				'posts_per_page' => 100,
				'paged'          => $page,
				'no_found_rows'  => true,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Privacy export and erasure must find exact reviewer email.
				'meta_key'       => '_previewshare_reviewer_email',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Privacy export and erasure must find exact reviewer email.
				'meta_value'     => $email,
			]
		);
		return is_array( $posts ) ? $posts : [];
	}

	/**
	 * Delete a record and its idempotency marker together.
	 *
	 * @param int $review_id Review record ID.
	 * @return void
	 */
	private function delete_response( int $review_id ): void {
		$option_name = (string) get_post_meta( $review_id, '_previewshare_submission_option', true );
		wp_delete_post( $review_id, true );
		if ( 0 === strpos( $option_name, 'previewshare_review_request_' ) ) {
			delete_option( $option_name );
		}
	}
}
