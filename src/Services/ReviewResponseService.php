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

	private const POST_TYPE          = 'previewshare_review';
	private const RETENTION_DAYS     = 90;
	private const CLEANUP_HOOK       = 'previewshare_cleanup_reviews';
	private const CONTINUE_HOOK      = 'previewshare_cleanup_reviews_continue';
	private const CLEANUP_CURSOR     = 'previewshare_cleanup_reviews_cursor';
	private const CLEANUP_FAILED     = 'previewshare_cleanup_reviews_failed';
	private const CLEANUP_LIMIT      = 2000;
	private const CLEANUP_BATCH_SIZE = 100;
	private const ERASER_BATCH_SIZE  = 100;
	private const NOT_DELETED        = 0;
	private const DELETED            = 1;
	private const MARKER_RETAINED    = 2;

	/**
	 * Submission marker persistence.
	 *
	 * @var ReviewSubmissionStore
	 */
	private $submissions;

	/**
	 * Initialize the review service.
	 */
	public function __construct() {
		$this->submissions = new ReviewSubmissionStore();
	}

	/**
	 * Register lifecycle and privacy hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'init', [ $this, 'register_type' ], 20 );
		add_action( 'init', [ $this, 'schedule_cleanup' ], 21 );
		add_action( self::CLEANUP_HOOK, [ $this, 'purge_expired' ] );
		add_action( self::CONTINUE_HOOK, [ $this, 'purge_expired' ] );
		add_action( 'deleted_post', [ $this, 'delete_for_post' ], 10, 2 );
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
		wp_clear_scheduled_hook( self::CONTINUE_HOOK );
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
		$option_name = ReviewSubmissionStore::PREFIX . $submission_key;
		$meta        = [
			'_previewshare_link_hash'         => $link_hash,
			'_previewshare_content_hash'      => $content_hash,
			'_previewshare_response_type'     => $response_type,
			'_previewshare_reviewer_name'     => $name,
			'_previewshare_reviewer_email'    => $email,
			ReviewSubmissionStore::META_KEY  => $option_name,
		];

		try {
			$review_id = ( new ReviewWriteStore() )->save( $option_name, $post_id, $comment, $meta );
		} catch ( \RuntimeException $error ) {
			// The database may have committed before losing the connection.
			return $this->unconfirmed_submission();
		}
		if ( null === $review_id ) {
			$review_id = $this->create_legacy_response( $post_id, $link_hash, $comment, $option_name, $meta );
		} elseif ( 0 === $review_id ) {
			return $this->confirm_existing_response( $option_name, $post_id, $link_hash, true );
		} else {
			$this->refresh_review_caches( $option_name, $review_id );
		}

		if ( is_int( $review_id ) ) {
			do_action( 'previewshare_review_response_accepted', $review_id, $post_id, $link_hash, $response_type );
		}
		return $review_id;
	}

	/**
	 * Preserve the existing write path on unsupported database adapters.
	 *
	 * @param int                  $post_id Owning post ID.
	 * @param string               $link_hash Link identifier.
	 * @param string               $comment Plain-text response.
	 * @param string               $option_name Request receipt key.
	 * @param array<string,string> $meta Required response metadata.
	 * @return int|\WP_Error Created row ID or error.
	 */
	private function create_legacy_response( int $post_id, string $link_hash, string $comment, string $option_name, array $meta ) {
		$reservation_id = $this->submissions->reserve( $option_name );
		if ( ! $reservation_id ) {
			return 0 === $reservation_id
				? $this->confirm_existing_response( $option_name, $post_id, $link_hash )
				: $this->unconfirmed_submission();
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
			if ( ! $this->submissions->release( $option_name, $reservation_id ) ) {
				return $this->unconfirmed_submission();
			}
			return $this->storage_failure();
		}

		$review_id = (int) $review_id;
		// Record the ID before metadata hooks can write to a concurrently deleted
		// review. The parent delete hook can also finish before the child is inserted.
		if ( ! $this->submissions->track_review( $option_name, $reservation_id, $review_id ) || true !== $this->submissions->post_exists( $post_id ) ) {
			return $this->rollback_response( $review_id, $option_name, $reservation_id );
		}

		foreach ( $meta as $key => $value ) {
			if ( ! add_post_meta( $review_id, $key, $value, true ) ) {
				return $this->rollback_response( $review_id, $option_name, $reservation_id );
			}
		}

		if ( ! $this->submissions->commit( $option_name, $review_id, $post_id, $reservation_id ) ) {
			if ( false === $this->submissions->post_exists( $post_id ) && true === $this->submissions->post_exists( $review_id ) ) {
				$this->delete_response( $review_id );
			}
			if ( null === $this->submissions->clear_deleted_response( $review_id, $option_name, $reservation_id ) ) {
				$this->schedule_cleanup_continuation( HOUR_IN_SECONDS );
			}
			return $this->unconfirmed_submission();
		}
		return $review_id;
	}

	/**
	 * Confirm a prior submission only when its durable record is complete.
	 *
	 * @param string $option_name Request receipt key.
	 * @param int    $post_id Owning post ID.
	 * @param string $link_hash Link identifier.
	 * @param bool   $refresh_caches Whether the original transaction may have stopped before clearing caches.
	 * @return \WP_Error Duplicate acknowledgement or unconfirmed outcome.
	 */
	private function confirm_existing_response( string $option_name, int $post_id, string $link_hash, bool $refresh_caches = false ): \WP_Error {
		$review_id = $this->submissions->confirmed_response_id( $option_name, $post_id, $link_hash );
		if ( $review_id ) {
			if ( $refresh_caches ) {
				$this->refresh_review_caches( $option_name, $review_id );
			}
			return new \WP_Error( 'review_duplicate', __( 'This response was already received.', 'previewshare' ), [ 'status' => 409 ] );
		}
		return $this->unconfirmed_submission();
	}

	/**
	 * Invalidate the caches that Core would clear after a post and receipt write.
	 *
	 * @param string $option_name Request receipt key.
	 * @param int    $review_id Confirmed review ID.
	 * @return void
	 */
	private function refresh_review_caches( string $option_name, int $review_id ): void {
		$this->submissions->flush_option_cache( $option_name );
		clean_post_cache( $review_id );
	}

	/**
	 * Delete an unsuccessful response and clear any metadata left behind.
	 *
	 * @param int    $review_id Review record ID.
	 * @param string $option_name Private request option.
	 * @param int    $reservation_id Option row ID of the writer.
	 * @return \WP_Error Storage failure or unconfirmed outcome.
	 */
	private function rollback_response( int $review_id, string $option_name, int $reservation_id ): \WP_Error {
		if ( ! $this->submissions->finish_rollback( $option_name, $reservation_id, $review_id ) ) {
			return $this->unconfirmed_submission();
		}

		wp_delete_post( $review_id, true );
		$cleared = $this->submissions->clear_deleted_response( $review_id, $option_name, $reservation_id );
		if ( null === $cleared ) {
			$this->schedule_cleanup_continuation( HOUR_IN_SECONDS );
		}
		return true === $cleared ? $this->storage_failure() : $this->unconfirmed_submission();
	}

	/**
	 * Report an unsuccessful review write with a known outcome.
	 *
	 * @return \WP_Error
	 */
	private function storage_failure(): \WP_Error {
		return new \WP_Error( 'review_storage_failed', __( 'The response could not be saved. Please try again.', 'previewshare' ), [ 'status' => 500 ] );
	}

	/**
	 * Report a response whose durable state could not be confirmed.
	 *
	 * @return \WP_Error
	 */
	private function unconfirmed_submission(): \WP_Error {
		return new \WP_Error( 'review_unconfirmed', __( 'We could not confirm whether your response was saved. Please retry. Before refreshing, copy your response and ask the editor whether it was saved.', 'previewshare' ), [ 'status' => 409 ] );
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
	 * Return a bounded set of recent response timestamps for rate-limit seeding.
	 *
	 * @param string $link_hash Link identifier.
	 * @param int    $since Unix timestamp lower bound.
	 * @return array<int,int>|null Timestamps, or null when the database read fails.
	 */
	public function get_recent_timestamps( string $link_hash, int $since ): ?array {
		global $wpdb;

		$after = gmdate( 'Y-m-d H:i:s', $since );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Bounded seed read uses committed data and trusted WordPress table identifiers.
		$dates = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT p.post_date_gmt FROM {$wpdb->posts} AS p INNER JOIN {$wpdb->postmeta} AS pm ON pm.post_id = p.ID WHERE p.post_type = %s AND p.post_status = %s AND pm.meta_key = %s AND pm.meta_value = %s AND p.post_date_gmt > %s ORDER BY p.post_date_gmt DESC, p.ID DESC LIMIT 100",
				self::POST_TYPE,
				'private',
				'_previewshare_link_hash',
				$link_hash,
				$after
			)
		);
		if ( ! is_array( $dates ) || '' !== (string) $wpdb->last_error ) {
			return null;
		}

		$timestamps = [];
		foreach ( $dates as $date ) {
			$timestamp = is_string( $date ) ? strtotime( $date . ' UTC' ) : false;
			if ( false === $timestamp ) {
				return null;
			}
			$timestamps[] = $timestamp;
		}
		return $timestamps;
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
		$cutoff       = gmdate( 'Y-m-d H:i:s', time() - ( self::RETENTION_DAYS * DAY_IN_SECONDS ) );
		$cursor       = max( 0, (int) get_option( self::CLEANUP_CURSOR, 0 ) );
		$had_failures = (bool) get_option( self::CLEANUP_FAILED, false );
		$processed    = 0;

		while ( $processed < self::CLEANUP_LIMIT ) {
			$ids = $this->query_review_ids( $cursor, self::CLEANUP_BATCH_SIZE, null, $cutoff );
			if ( null === $ids ) {
				$this->schedule_cleanup_continuation( HOUR_IN_SECONDS );
				return;
			}
			if ( ! $ids ) {
				break;
			}

			foreach ( $ids as $id ) {
				$id     = (int) $id;
				$cursor = $id;
				if ( self::DELETED !== $this->delete_response( $id ) ) {
					$had_failures = true;
					update_option( self::CLEANUP_FAILED, true, false );
				}
			}
			$processed += count( $ids );
			update_option( self::CLEANUP_CURSOR, $cursor, false );
			if ( count( $ids ) < self::CLEANUP_BATCH_SIZE ) {
				break;
			}
		}

		$more = false;
		if ( self::CLEANUP_LIMIT === $processed ) {
			$more = $this->query_review_ids( $cursor, 1, null, $cutoff );
			if ( null === $more ) {
				$this->schedule_cleanup_continuation( HOUR_IN_SECONDS );
				return;
			}
		}
		if ( $more ) {
			$this->schedule_cleanup_continuation( 5 * MINUTE_IN_SECONDS );
			return;
		}

		delete_option( self::CLEANUP_CURSOR );
		delete_option( self::CLEANUP_FAILED );
		$markers = $this->submissions->purge_orphans( self::CLEANUP_BATCH_SIZE, self::CLEANUP_LIMIT );
		if ( $had_failures || false !== $markers ) {
			$this->schedule_cleanup_continuation( true === $markers ? 5 * MINUTE_IN_SECONDS : HOUR_IN_SECONDS );
			return;
		}

		wp_clear_scheduled_hook( self::CONTINUE_HOOK );
	}

	/**
	 * Remove child review records when their owning post is deleted.
	 *
	 * @param int           $post_id Deleted post ID.
	 * @param \WP_Post|null $post Deleted post passed by WordPress.
	 * @return void
	 */
	public function delete_for_post( int $post_id, ?\WP_Post $post = null ): void {
		if ( in_array( $post ? $post->post_type : get_post_type( $post_id ), [ self::POST_TYPE, 'attachment' ], true ) ) {
			return;
		}
		$cursor = 0;
		$failed = false;
		do {
			$ids = $this->query_review_ids( $cursor, self::CLEANUP_BATCH_SIZE, null, null, $post_id );
			if ( null === $ids ) {
				$failed = true;
				break;
			}
			$count = count( $ids );
			foreach ( $ids as $id ) {
				$cursor  = $id;
				$deleted = $this->delete_response( $id );
				$failed  = $failed || self::NOT_DELETED === $deleted;
			}
		} while ( self::CLEANUP_BATCH_SIZE === $count );
		if ( $failed ) {
			update_option( self::CLEANUP_FAILED, true, false );
			$this->schedule_cleanup_continuation( HOUR_IN_SECONDS );
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
	 * @return array<string,mixed>|\WP_Error
	 */
	public function export_by_email( string $email_address, int $page = 1 ) {
		$email = sanitize_email( $email_address );
		if ( '' === $email ) {
			return [
				'data' => [],
				'done' => true,
			];
		}
		$rows = $this->export_rows_for_email( $email, max( 1, $page ) );
		if ( is_wp_error( $rows ) ) {
			return $rows;
		}
		$data = [];
		foreach ( $rows as $row ) {
			$post       = $row->_previewshare_export_post ?? new \WP_Post(
				(object) [
					'ID'            => (int) $row->ID,
					'post_date_gmt' => (string) $row->post_date_gmt,
					'filter'        => 'raw',
				]
			);
			$created_at = (int) get_post_time( 'U', true, $post );
			$data[]     = [
				'group_id'    => 'previewshare-reviews',
				'group_label' => __( 'PreviewShare reviewer responses', 'previewshare' ),
				'item_id'     => 'previewshare-review-' . $row->ID,
				'data'        => [
					[
						'name' => __( 'Post ID', 'previewshare' ),
						'value' => (string) $row->post_parent,
					],
					[
						'name' => __( 'Response', 'previewshare' ),
						'value' => $this->export_meta_value( (int) $row->ID, '_previewshare_response_type', $row->response_type ),
					],
					[
						'name' => __( 'Name', 'previewshare' ),
						'value' => $this->export_meta_value( (int) $row->ID, '_previewshare_reviewer_name', $row->reviewer_name ),
					],
					[
						'name' => __( 'Email', 'previewshare' ),
						'value' => $this->export_meta_value( (int) $row->ID, '_previewshare_reviewer_email', $row->reviewer_email ),
					],
					[
						'name' => __( 'Comment', 'previewshare' ),
						'value' => (string) $row->post_content,
					],
					[
						'name' => __( 'Submitted (UTC)', 'previewshare' ),
						'value' => gmdate( 'c', $created_at ),
					],
				],
			];
		}
		return [
			'data' => $data,
			'done' => count( $rows ) < 100,
		];
	}

	/**
	 * Erase responses for an email address.
	 *
	 * @param string $email_address Requested email.
	 * @param int    $page WordPress privacy page.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function erase_by_email( string $email_address, int $page = 1 ) {
		$email      = sanitize_email( $email_address );
		$cursor_key = 'previewshare_review_eraser_' . hash( 'sha256', $email );
		if ( 1 >= $page ) {
			delete_transient( $cursor_key );
		}
		$state = get_transient( $cursor_key );
		if ( 1 >= $page && false !== $state ) {
			return new \WP_Error( 'review_eraser_progress_reset_failed', __( 'PreviewShare could not restart this erasure request. Please try again.', 'previewshare' ) );
		}
		if (
			1 < $page
			&& (
				! is_array( $state )
				|| (int) ( $state['next_page'] ?? 0 ) !== $page
				|| ! isset( $state['cursor'], $state['items_removed'], $state['items_retained'] )
			)
		) {
			return new \WP_Error( 'review_eraser_progress_missing', __( 'PreviewShare could not resume this erasure page. Please restart the erasure request.', 'previewshare' ) );
		}
		$state  = is_array( $state ) ? $state : [
			'cursor'         => 0,
			'items_removed'  => false,
			'items_retained' => false,
		];
		$cursor = max( 0, (int) ( $state['cursor'] ?? 0 ) );
		$ids    = '' === $email ? [] : $this->query_review_ids( $cursor, self::ERASER_BATCH_SIZE, $email );
		if ( null === $ids ) {
			return new \WP_Error( 'review_eraser_query_failed', __( 'PreviewShare could not check for responses to erase. Please try the erasure request again.', 'previewshare' ) );
		}
		foreach ( $ids as $id ) {
			$id                      = (int) $id;
			$cursor                  = $id;
			$deletion                = $this->delete_response( $id );
			$state['items_removed']  = $state['items_removed'] || self::NOT_DELETED !== $deletion;
			$state['items_retained'] = $state['items_retained'] || self::DELETED !== $deletion;
		}
		$more = false;
		if ( '' !== $email ) {
			$more = $this->query_review_ids( $cursor, 1, $email );
			if ( null === $more ) {
				$progress = $this->save_eraser_progress( $cursor_key, $state, $cursor, $page - 1 );
				if ( is_wp_error( $progress ) ) {
					return $progress;
				}
				return new \WP_Error( 'review_eraser_query_failed', __( 'PreviewShare could not check for responses to erase. Please try the erasure request again.', 'previewshare' ) );
			}
		}
		if ( $more ) {
			$progress = $this->save_eraser_progress( $cursor_key, $state, $cursor, $page );
			if ( is_wp_error( $progress ) ) {
				return $progress;
			}
		} else {
			delete_transient( $cursor_key );
		}
		return [
			'items_removed'  => (bool) $state['items_removed'],
			'items_retained' => (bool) $state['items_retained'],
			'messages'       => $state['items_retained'] ? [ __( 'Some PreviewShare responses could not be removed. Please try again.', 'previewshare' ) ] : [],
			'done'           => ! $more,
		];
	}

	/**
	 * Save eraser progress for the next WordPress callback page.
	 *
	 * @param string              $cursor_key Transient key.
	 * @param array<string,mixed> $state Cumulative eraser state.
	 * @param int                 $cursor Last attempted record ID.
	 * @param int                 $page Current WordPress page.
	 * @return true|\WP_Error
	 */
	private function save_eraser_progress( string $cursor_key, array $state, int $cursor, int $page ) {
		$state['cursor']    = $cursor;
		$state['next_page'] = $page + 1;
		if ( set_transient( $cursor_key, $state, DAY_IN_SECONDS ) ) {
			return true;
		}
		return new \WP_Error( 'review_eraser_progress_failed', __( 'PreviewShare could not save erasure progress. Please restart the erasure request.', 'previewshare' ) );
	}

	/**
	 * Schedule a bounded cleanup continuation unless one is already queued.
	 *
	 * @param int $delay Seconds until the continuation.
	 * @return void
	 */
	private function schedule_cleanup_continuation( int $delay ): void {
		if ( ! wp_next_scheduled( self::CONTINUE_HOOK ) ) {
			wp_schedule_single_event( time() + $delay, self::CONTINUE_HOOK );
		}
	}

	/**
	 * Query review IDs after a keyset cursor.
	 *
	 * @param int         $after_id Exclusive ID lower bound.
	 * @param int         $limit Maximum rows.
	 * @param string|null $email Optional exact reviewer email.
	 * @param string|null $cutoff Optional GMT cutoff for reviews whose parent still exists.
	 * @param int|null    $parent_id Optional owning post ID.
	 * @return array<int,int>|null Null when the database query fails.
	 */
	private function query_review_ids( int $after_id, int $limit, ?string $email = null, ?string $cutoff = null, ?int $parent_id = null ): ?array {
		global $wpdb;

		$sql       = "SELECT reviews.ID FROM {$wpdb->posts} AS reviews";
		$arguments = [];
		$sql      .= ' WHERE reviews.post_type = %s AND reviews.post_status = %s AND reviews.ID > %d';
		$arguments = array_merge( $arguments, [ self::POST_TYPE, 'private', $after_id ] );
		if ( null !== $email ) {
			$sql      .= " AND EXISTS (SELECT 1 FROM {$wpdb->postmeta} AS reviewer_email WHERE reviewer_email.post_id = reviews.ID AND reviewer_email.meta_key = %s AND reviewer_email.meta_value = %s)";
			$arguments = array_merge( $arguments, [ '_previewshare_reviewer_email', $email ] );
		}
		if ( null !== $cutoff ) {
			// Retry a failed parent cascade at the next bounded pass without shortening
			// retention for reviews whose owning post still exists.
			$sql        .= " AND (reviews.post_date_gmt < %s OR (reviews.post_parent > 0 AND NOT EXISTS (SELECT 1 FROM {$wpdb->posts} AS owners WHERE owners.ID = reviews.post_parent)))";
			$arguments[] = $cutoff;
		}
		if ( null !== $parent_id ) {
			$sql        .= ' AND reviews.post_parent = %d';
			$arguments[] = $parent_id;
		}
		$sql        .= ' ORDER BY reviews.ID ASC LIMIT %d';
		$arguments[] = $limit;
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Bounded keyset query must progress across deletions and failed records.
		$ids = $wpdb->get_col( $wpdb->prepare( $sql, $arguments ) );
		if ( ( isset( $wpdb->last_error ) && '' !== $wpdb->last_error ) || ! is_array( $ids ) ) {
			return null;
		}
		return array_map( 'intval', $ids );
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
	 * Query one bounded privacy export page without WordPress's cached empty-query behavior.
	 *
	 * @param string $email Sanitized email.
	 * @param int    $page One-based page number.
	 * @return array<int,object>|\WP_Error
	 */
	private function export_rows_for_email( string $email, int $page ) {
		global $wpdb;
		$selection      = new \WP_Query();
		$failed         = false;
		$projected_rows = null;
		$pre_query      = function ( $posts, $current_query ) use ( &$failed, &$projected_rows, $selection, $wpdb, $email ) {
			if ( $current_query !== $selection ) {
				return $posts;
			}
			$current_query->set( 'cache_results', false );
			$current_query->set( 'update_post_meta_cache', false );
			$current_query->set( 'update_post_term_cache', false );
			$current_query->set( 'lazy_load_term_meta', false );
			$source_posts = [];
			if ( null === $posts ) {
				if (
					( function_exists( 'has_filter' ) && ( false !== has_filter( 'posts_request_ids' ) || false !== has_filter( 'split_the_query' ) ) )
					|| 100 !== (int) $current_query->get( 'posts_per_page' )
					|| $current_query->get( 'nopaging' )
					|| 'all' !== $current_query->get( 'fields' )
					|| ! is_string( $current_query->request )
					|| '' === $current_query->request
				) {
					$failed = true;
					return [];
				}
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Executes the exact Core-compiled and posts_request-filtered query once.
					$result = $wpdb->query( $current_query->request );
					$rows   = $wpdb->last_result ?? null;
				if ( false === $result || ! is_int( $result ) || ! is_array( $rows ) || count( $rows ) !== $result ) {
					$failed = true;
					return [];
				}
					$ids = [];
				foreach ( $rows as $row ) {
					$ids[] = is_object( $row ) && property_exists( $row, 'ID' ) ? (int) $row->ID : 0;
				}
			} else {
				if ( ! is_array( $posts ) ) {
					$failed = true;
					return [];
				}
				$ids = [];
				foreach ( $posts as $post ) {
					$id = is_object( $post ) && property_exists( $post, 'ID' ) ? (int) $post->ID : ( is_numeric( $post ) ? (int) $post : 0 );
					if ( $id < 1 ) {
						$failed = true;
						return [];
					}
					$ids[] = $id;
					if ( $post instanceof \WP_Post ) {
						$source_posts[ $id ] = $post;
					}
				}
			}
			$ids = array_values( array_unique( $ids ) );
			if ( count( $ids ) > 100 || count( $ids ) !== ( null === $posts ? count( $rows ) : count( $posts ) ) || in_array( 0, $ids, true ) ) {
				$failed = true;
				return [];
			}
			if ( [] === $ids ) {
				$projected_rows = [];
				return [];
			}
			$projected_rows = $this->project_export_rows( $ids, $email );
			if ( is_wp_error( $projected_rows ) ) {
				$failed = true;
				return [];
			}
			$result_posts = [];
			foreach ( $projected_rows as $row ) {
				$id       = (int) $row->ID;
				$post_row = clone $row;
				unset( $post_row->response_type, $post_row->reviewer_name, $post_row->reviewer_email );
				unset( $post_row->filter );
				$post_row->ID          = (int) $post_row->ID;
				$post_row->post_parent = (int) $post_row->post_parent;
				$post_row->menu_order  = (int) ( $post_row->menu_order ?? 0 );
				$post                  = new \WP_Post( $post_row );
				$post->filter          = 'raw';
				if ( isset( $source_posts[ $id ] ) ) {
					$post = clone $source_posts[ $id ];
				}
				$result_posts[] = $post;
			}
			return $result_posts;
		};
		add_filter( 'posts_pre_query', $pre_query, PHP_INT_MAX, 2 );
		try {
			$selection->query(
				[
					'post_type'              => self::POST_TYPE,
					'post_status'            => 'private',
					// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Required exact-email query for the private privacy export.
					'meta_key'               => '_previewshare_reviewer_email',
					// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Required exact-email query for the private privacy export.
					'meta_value'             => $email,
					'orderby'                => [
						'date' => 'DESC',
						'ID' => 'DESC',
					],
					'posts_per_page'         => 100,
					'paged'                  => $page,
					'nopaging'               => false,
					'fields'                 => 'all',
					'ignore_sticky_posts'    => true,
					'no_found_rows'          => true,
					'cache_results'          => false,
					'suppress_filters'       => true,
					'update_post_meta_cache' => false,
					'update_post_term_cache' => false,
				]
			);
		} finally {
			remove_filter( 'posts_pre_query', $pre_query, PHP_INT_MAX );
		}
		if ( $failed || ! is_array( $projected_rows ) || ! is_array( $selection->posts ) ) {
			return new \WP_Error( 'review_export_query_failed', __( 'Reviewer responses could not be retrieved for export.', 'previewshare' ) );
		}
		if ( [] === $selection->posts && [] === $projected_rows ) {
			return [];
		}
		$rows_by_id = [];
		foreach ( $projected_rows as $row ) {
			$rows_by_id[ (int) $row->ID ] = $row;
		}
		$rows     = [];
		$seen_ids = [];
		foreach ( $selection->posts as $post ) {
			$id = $post instanceof \WP_Post ? (int) $post->ID : 0;
			if ( $id < 1 || isset( $seen_ids[ $id ] ) || ! isset( $rows_by_id[ $id ] ) ) {
				return new \WP_Error( 'review_export_query_failed', __( 'Reviewer responses could not be retrieved for export.', 'previewshare' ) );
			}
			$seen_ids[ $id ]                = true;
			$row                            = clone $rows_by_id[ $id ];
			$row->post_parent               = (int) $post->post_parent;
			$row->post_content              = (string) $post->post_content;
			$row->post_date_gmt             = (string) $post->post_date_gmt;
			$row->_previewshare_export_post = $post;
			$rows[]                         = $row;
		}
		return $rows;
	}

	/**
	 * Project private response metadata for a bounded, validated ID list.
	 *
	 * @param int[]  $ids Selected review IDs.
	 * @param string $email Sanitized reviewer email.
	 * @return array<int,object>|\WP_Error
	 */
	private function project_export_rows( array $ids, string $email ) {
		global $wpdb;
		$id_placeholders = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );
		$sql             = "SELECT reviews.*,
			(SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = reviews.ID AND CAST(meta_key AS BINARY) = CAST(%s AS BINARY) ORDER BY meta_id ASC LIMIT 1) AS response_type,
			(SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = reviews.ID AND CAST(meta_key AS BINARY) = CAST(%s AS BINARY) ORDER BY meta_id ASC LIMIT 1) AS reviewer_name,
			(SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = reviews.ID AND CAST(meta_key AS BINARY) = CAST(%s AS BINARY) ORDER BY meta_id ASC LIMIT 1) AS reviewer_email
			FROM {$wpdb->posts} AS reviews
			WHERE reviews.post_type = %s AND reviews.post_status = %s AND reviews.ID IN ($id_placeholders)
			AND EXISTS (SELECT 1 FROM {$wpdb->postmeta} AS email_meta WHERE email_meta.post_id = reviews.ID AND email_meta.meta_key = %s AND email_meta.meta_value = %s)
			ORDER BY FIELD(reviews.ID, $id_placeholders)";
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- The SQL string is a fixed template and all variable values are placeholders passed to prepare.
		$prepared = $wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- The SQL string is fixed and values are bound below.
			$sql,
			array_merge(
				[ '_previewshare_response_type', '_previewshare_reviewer_name', '_previewshare_reviewer_email', self::POST_TYPE, 'private' ],
				$ids,
				[ '_previewshare_reviewer_email', $email ],
				$ids
			)
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Export is a bounded, uncached query and checks execution before reading rows.
		$result = $wpdb->query( $prepared );
		$rows   = $wpdb->last_result ?? null;
		if ( false === $result || ! is_array( $rows ) || count( $rows ) !== $result ) {
			return new \WP_Error( 'review_export_query_failed', __( 'Reviewer responses could not be retrieved for export.', 'previewshare' ) );
		}
		if ( count( $rows ) !== count( $ids ) ) {
			return new \WP_Error( 'review_export_query_failed', __( 'Reviewer responses could not be retrieved for export.', 'previewshare' ) );
		}
		foreach ( $rows as $row ) {
			if ( ! is_object( $row ) || ! property_exists( $row, 'ID' ) || ! in_array( (int) $row->ID, $ids, true ) ) {
				return new \WP_Error( 'review_export_query_failed', __( 'Reviewer responses could not be retrieved for export.', 'previewshare' ) );
			}
			$row->filter = 'raw';
		}
		return $rows;
	}

	/**
	 * Resolve one projected metadata value with Core's short-circuit and default filters.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $meta_key Exact metadata key.
	 * @param mixed  $stored_value Raw projected database value, or null if absent.
	 * @return string
	 */
	private function export_meta_value( int $post_id, string $meta_key, $stored_value ): string {
		$value = apply_filters( 'get_post_metadata', null, $post_id, $meta_key, true, 'post' );
		if ( null !== $value ) {
			$value = is_array( $value ) ? ( $value[0] ?? null ) : $value;
		} else {
			$value = null === $stored_value ? null : maybe_unserialize( $stored_value );
		}

		if ( null === $value ) {
			$value = get_metadata_default( 'post', $post_id, $meta_key, true );
		}

		return (string) $value;
	}

	/**
	 * Delete a record and its idempotency marker together.
	 *
	 * @param int $review_id Review record ID.
	 * @return int Outcome of deleting the response and marker.
	 */
	private function delete_response( int $review_id ): int {
		$option_name = get_post_meta( $review_id, ReviewSubmissionStore::META_KEY, true );
		$has_marker  = is_string( $option_name ) && 0 === strpos( $option_name, ReviewSubmissionStore::PREFIX );
		if ( $has_marker && ! $this->submissions->prepare_review_deletion( $option_name, $review_id ) ) {
			return self::NOT_DELETED;
		}
		if ( ! wp_delete_post( $review_id, true ) ) {
			return self::NOT_DELETED;
		}
		if ( $has_marker && ! $this->submissions->remove_orphan( $option_name, $review_id ) ) {
			$this->schedule_cleanup_continuation( HOUR_IN_SECONDS );
			return self::MARKER_RETAINED;
		}
		return self::DELETED;
	}
}
