<?php
/**
 * Review submission markers and reconciliation.
 *
 * @package PreviewShare
 */

namespace PreviewShare\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stores durable request markers without expiring ambiguous reservations.
 *
 * @internal
 */
final class ReviewSubmissionStore {
	public const PREFIX   = 'previewshare_review_request_';
	public const META_KEY = '_previewshare_submission_option';

	private const POST_TYPE      = 'previewshare_review';
	private const CLEANUP_CURSOR = 'previewshare_cleanup_review_markers_cursor';
	private const READY          = 'ready';
	private const PENDING        = 'pending:';
	private const FINISHED       = 'finished:';

	private const METADATA_RETRY_PREFIX = self::PREFIX . 'meta_retry_';
	private const RESPONSE_META_KEYS    = [
		'_previewshare_link_hash',
		'_previewshare_content_hash',
		'_previewshare_response_type',
		'_previewshare_reviewer_name',
		'_previewshare_reviewer_email',
		self::META_KEY,
	];

	/**
	 * Reserve a request without allowing concurrent inserts to overwrite it.
	 *
	 * @param string $option_name Private request option.
	 * @return int|null The inserted option ID, zero when already reserved, null on failure.
	 */
	public function reserve( string $option_name ): ?int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Unique insert protects the request from concurrent replacement.
		$inserted = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", $option_name, self::READY ) );
		if ( false === $inserted || '' !== (string) $wpdb->last_error ) {
			return null;
		}
		if ( 1 === (int) $inserted ) {
			$reservation_id = (int) $wpdb->insert_id;
			$this->flush_option_cache( $option_name );
			if ( $reservation_id <= 0 ) {
				return null;
			}
		} else {
			$reservation_id = $this->ready_reservation_id( $option_name );
			if ( ! $reservation_id ) {
				return $reservation_id;
			}
		}

		// A request may write a post only after winning this transition. There
		// is no elapsed-time takeover once a writer has been authorized.
		$claimed = $this->transition( $option_name, $reservation_id, self::READY, '0' );
		return null === $claimed ? null : ( $claimed ? $reservation_id : 0 );
	}

	/**
	 * Find a reservation that has not authorized a writer yet.
	 *
	 * @param string $option_name Private request option.
	 * @return int|null Row ID, zero if absent, or null on database failure.
	 */
	private function ready_reservation_id( string $option_name ): ?int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Only an unclaimed reservation can be picked up again.
		$id = $wpdb->get_var( $wpdb->prepare( "SELECT option_id FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s LIMIT 1", $option_name, self::READY ) );
		if ( '' !== (string) $wpdb->last_error ) {
			return null;
		}
		return $this->numeric_id( is_scalar( $id ) ? (string) $id : null );
	}

	/**
	 * Record where metadata can be written before invoking WordPress metadata hooks.
	 *
	 * @param string $option_name Private request option.
	 * @param int    $reservation_id Option row ID of the writer.
	 * @param int    $review_id Newly inserted review.
	 * @return bool Whether this writer may proceed.
	 */
	public function track_review( string $option_name, int $reservation_id, int $review_id ): bool {
		return true === $this->transition( $option_name, $reservation_id, '0', self::PENDING . $review_id );
	}

	/**
	 * Change a reservation phase only for the expected generation and phase.
	 *
	 * @param string $option_name Private request option.
	 * @param int    $reservation_id Option row ID.
	 * @param string $from Expected value.
	 * @param string $to New value.
	 * @return bool|null True on success, false on conflict, null on database failure.
	 */
	private function transition( string $option_name, int $reservation_id, string $from, string $to ): ?bool {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- A compare-and-set fences stale request generations.
		$updated = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s AND option_id = %d", $to, $option_name, $from, $reservation_id ) );
		if ( false === $updated || '' !== (string) $wpdb->last_error ) {
			return null;
		}
		if ( 1 === $updated ) {
			$this->flush_option_cache( $option_name );
			return true;
		}
		return false;
	}

	/**
	 * Confirm that a stored reservation belongs to a complete response.
	 *
	 * @param string $option_name Private request option.
	 * @param int    $post_id Owning post ID.
	 * @param string $link_hash Link identifier.
	 * @return bool Whether a complete matching response exists.
	 */
	public function has_response( string $option_name, int $post_id, string $link_hash ): bool {
		return 0 !== $this->confirmed_response_id( $option_name, $post_id, $link_hash );
	}

	/**
	 * Confirm a durable response and return its ID for cache reconciliation.
	 *
	 * @param string $option_name Private request option.
	 * @param int    $post_id Owning post ID.
	 * @param string $link_hash Link identifier.
	 * @return int The matching review ID, or zero when unconfirmed.
	 */
	public function confirmed_response_id( string $option_name, int $post_id, string $link_hash ): int {
		$stored_id = $this->read( $option_name );
		$pending   = $this->phase_review_id( $stored_id, self::PENDING );
		if ( $pending ) {
			return $this->matches_response( $pending, $option_name, $post_id, $link_hash ) && $this->bind( $option_name, $pending, $post_id, self::PENDING . $pending ) ? $pending : 0;
		}
		if ( '0' !== $stored_id ) {
			$review_id = $this->numeric_id( $stored_id );
			return $review_id && $this->matches_response( $review_id, $option_name, $post_id, $link_hash ) && $this->is_bound( $option_name, $review_id, $post_id ) ? $review_id : 0;
		}

		// The last metadata write links the complete review to the request. An
		// absent or partial review cannot distinguish a crash from a live writer.
		$ids = get_posts(
			[
				'post_type'      => self::POST_TYPE,
				'post_status'    => 'private',
				'post_parent'    => $post_id,
				'fields'         => 'ids',
				'posts_per_page' => 2,
				'no_found_rows'  => true,
				'cache_results'  => false,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Retry is scoped to the parent and unique request marker.
				'meta_key'       => self::META_KEY,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Match the exact request rather than searching review content.
				'meta_value'     => $option_name,
			]
		);
		if ( ! is_array( $ids ) || 1 !== count( $ids ) ) {
			return 0;
		}
		$review_id = (int) $ids[0];
		return $this->matches_response( $review_id, $option_name, $post_id, $link_hash ) && $this->commit( $option_name, $review_id, $post_id ) ? $review_id : 0;
	}

	/**
	 * Check for a post in the database without the WordPress object cache.
	 *
	 * @param int $post_id Post to check.
	 * @return bool|null True when present, false when missing, null on a database error.
	 * @phpstan-impure The post can disappear between database reads.
	 */
	public function post_exists( int $post_id ): ?bool {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The owner may have been deleted while this request was inserting its review.
		$id = $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE ID = %d LIMIT 1", $post_id ) );
		return '' === (string) $wpdb->last_error ? (string) $post_id === (string) $id : null;
	}

	/**
	 * Associate a still-present review with its reserved request.
	 *
	 * @param string $option_name Private request option.
	 * @param int    $review_id Review record ID.
	 * @param int    $post_id Owning post ID. Zero binds the marker for deletion after the owner is gone.
	 * @param int    $reservation_id Original writer, or zero for a legacy marker.
	 * @return bool Whether the marker points to the review.
	 */
	public function commit( string $option_name, int $review_id, int $post_id, int $reservation_id = 0 ): bool {
		$expected = $reservation_id ? self::PENDING . $review_id : '0';
		return $this->bind( $option_name, $review_id, $post_id, $expected, $reservation_id );
	}

	/**
	 * Bind a review only if the request is in the expected phase.
	 *
	 * @param string $option_name Private request option.
	 * @param int    $review_id Review record ID.
	 * @param int    $post_id Owning post ID, or zero for cleanup.
	 * @param string $expected Expected marker value.
	 * @param int    $reservation_id Original writer, or zero for reconciliation.
	 * @return bool Whether the marker points to the review.
	 */
	private function bind( string $option_name, int $review_id, int $post_id, string $expected, int $reservation_id = 0 ): bool {
		global $wpdb;
		[ $response, $arguments ] = $this->matching_response_query( $option_name, $review_id, $post_id );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- The subquery contains trusted table names and placeholders only.
		$updated = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s AND (%d = 0 OR option_id = %d) AND EXISTS ($response)", array_merge( [ (string) $review_id, $option_name, $expected, $reservation_id, $reservation_id ], $arguments ) ) );
		if ( false === $updated || '' !== (string) $wpdb->last_error ) {
			return false;
		}
		if ( 1 === $updated ) {
			$this->flush_option_cache( $option_name );
			return true;
		}

		return $this->is_bound( $option_name, $review_id, $post_id );
	}

	/**
	 * Confirm the durable marker, response and parent still agree.
	 *
	 * @param string $option_name Private request option.
	 * @param int    $review_id Review record ID.
	 * @param int    $post_id Owning post ID, or zero when binding a marker for cleanup.
	 * @return bool Whether the marker points to the existing review.
	 */
	private function is_bound( string $option_name, int $review_id, int $post_id ): bool {
		global $wpdb;
		[ $response, $arguments ] = $this->matching_response_query( $option_name, $review_id, $post_id );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- The subquery contains trusted table names and placeholders only.
		$confirmed = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s AND EXISTS ($response) LIMIT 1", array_merge( [ $option_name, (string) $review_id ], $arguments ) ) );
		return '' === (string) $wpdb->last_error && (string) $review_id === (string) $confirmed;
	}

	/**
	 * Match a review directly against its database state.
	 *
	 * @param string $option_name Private request option.
	 * @param int    $review_id Review record ID.
	 * @param int    $post_id Owning post ID. Zero discovers a review for deletion.
	 * @return array{string, array<int, string|int>} SQL subquery and placeholder arguments.
	 */
	private function matching_response_query( string $option_name, int $review_id, int $post_id ): array {
		global $wpdb;
		$placeholders = implode( ', ', array_fill( 0, count( self::RESPONSE_META_KEYS ), '%s' ) );
		// WordPress may delete selected metadata while the post row still exists.
		// Cleanup can still bind such a row, but accepting it requires every field.
		return [
			"SELECT 1 FROM {$wpdb->posts} AS reviews INNER JOIN {$wpdb->postmeta} AS markers ON markers.post_id = reviews.ID AND markers.meta_key = %s AND markers.meta_value = %s LEFT JOIN {$wpdb->posts} AS owners ON owners.ID = reviews.post_parent WHERE reviews.ID = %d AND reviews.post_type = %s AND reviews.post_status = 'private' AND (%d = 0 OR (owners.ID = %d AND (SELECT COUNT(DISTINCT fields.meta_key) FROM {$wpdb->postmeta} AS fields WHERE fields.post_id = reviews.ID AND fields.meta_key IN ($placeholders)) = %d))",
			array_merge( [ self::META_KEY, $option_name, $review_id, self::POST_TYPE, $post_id, $post_id ], self::RESPONSE_META_KEYS, [ count( self::RESPONSE_META_KEYS ) ] ),
		];
	}

	/**
	 * Release a reservation only after its original writer confirms rollback.
	 *
	 * @param string $option_name Private request option.
	 * @param int    $reservation_id Option row ID of the original reservation.
	 * @param int    $review_id Review that must be missing, or zero if insertion never created one.
	 * @return bool Whether the original unresolved marker is gone.
	 */
	public function release( string $option_name, int $reservation_id, int $review_id = 0 ): bool {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Fence the original pending marker and check the review's absence in the same statement.
		$deleted = $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_id = %d AND option_name = %s AND option_value IN ('0', %s, %s) AND (%d = 0 OR NOT EXISTS (SELECT 1 FROM {$wpdb->posts} WHERE ID = %d))", $reservation_id, $option_name, self::PENDING . $review_id, self::FINISHED . $review_id, $review_id, $review_id ) );
		if ( false === $deleted || '' !== (string) $wpdb->last_error ) {
			return false;
		}
		if ( 1 === $deleted ) {
			$this->flush_option_cache( $option_name );
			return true;
		}
		return 0 === $review_id || false === $this->post_exists( $review_id );
	}

	/**
	 * Clean up after a failed writer has stopped writing review metadata.
	 *
	 * @param int    $review_id Review record ID.
	 * @param string $option_name Private request option.
	 * @param int    $reservation_id Original option row ID.
	 * @return bool|null True if cleaned, null if durably queued, false otherwise.
	 */
	public function clear_deleted_response( int $review_id, string $option_name, int $reservation_id ): ?bool {
		if ( false !== $this->post_exists( $review_id ) ) {
			return false;
		}
		// The writer has finished every metadata call. Persist that fact before
		// attempting cleanup so interruption here cannot strand compensation.
		$this->transition( $option_name, $reservation_id, self::PENDING . $review_id, self::FINISHED . $review_id );
		if ( $this->remove_orphaned_metadata( $review_id ) && $this->release( $option_name, $reservation_id, $review_id ) ) {
			return true;
		}

		// Store only the original option reference. Its row ID fences out any later reservation.
		$job   = self::METADATA_RETRY_PREFIX . $review_id;
		$value = $option_name . ':' . $reservation_id;
		return add_option( $job, $value, '', false ) || $value === $this->read( $job ) ? null : false;
	}

	/**
	 * Record that the failed writer has stopped writing metadata before Core deletes its post.
	 *
	 * @param string $option_name Private request option.
	 * @param int    $reservation_id Original option row ID.
	 * @param int    $review_id Review the writer unsuccessfully tried to save.
	 * @return bool Whether the original writer may proceed with synchronous rollback.
	 */
	public function finish_rollback( string $option_name, int $reservation_id, int $review_id ): bool {
		$finished = self::FINISHED . $review_id;
		$recorded = $this->transition( $option_name, $reservation_id, self::PENDING . $review_id, $finished );
		if ( false === $recorded ) {
			// Tracking can fail after Core returns the new ID. Only this writer may
			// close its own generation. A concurrently bound response wins the race.
			$recorded = $this->transition( $option_name, $reservation_id, '0', $finished );
		}
		return true === $recorded;
	}

	/**
	 * Remove metadata WordPress may have written after the owning review was deleted.
	 *
	 * @param int $review_id Review record ID.
	 * @return bool Whether the orphan metadata cleanup succeeded.
	 */
	private function remove_orphaned_metadata( int $review_id ): bool {
		global $wpdb;
		$placeholders = implode( ', ', array_fill( 0, count( self::RESPONSE_META_KEYS ), '%s' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Only fixed placeholders are interpolated. Confirm the review is missing in the same statement that deletes the orphan metadata.
		$deleted = $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key IN ($placeholders) AND NOT EXISTS (SELECT 1 FROM {$wpdb->posts} WHERE ID = %d)", array_merge( [ $review_id ], self::RESPONSE_META_KEYS, [ $review_id ] ) ) );
		if ( false === $deleted || '' !== (string) $wpdb->last_error ) {
			return false;
		}
		wp_cache_delete( $review_id, 'post_meta' );
		return true;
	}

	/**
	 * Retry compensation for one finished writer and remove the job once resolved.
	 *
	 * @param string $job Durable cleanup option name.
	 * @param string $value Original marker name and reservation ID.
	 * @return bool Whether the job was completed.
	 */
	private function retry_orphaned_metadata( string $job, string $value ): bool {
		$review_id                        = $this->numeric_id( substr( $job, strlen( self::METADATA_RETRY_PREFIX ) ) );
		[ $option_name, $reservation_id ] = array_pad( explode( ':', $value, 2 ), 2, '' );
		$reservation_id                   = $this->numeric_id( $reservation_id );
		if ( ! $review_id || ! $reservation_id || 1 !== preg_match( '/^' . self::PREFIX . '[a-f0-9]{64}$/D', $option_name ) ) {
			return false;
		}

		$exists = $this->post_exists( $review_id );
		if ( null === $exists || ( ! $exists && ( ! $this->remove_orphaned_metadata( $review_id ) || ! $this->release( $option_name, $reservation_id, $review_id ) ) ) ) {
			return false;
		}

		// The job is immutable. If the row ID was restored, neither its metadata nor its marker were changed.
		return delete_option( $job ) || null === $this->read( $job );
	}

	/**
	 * Preserve the marker's review ID before the review metadata is deleted.
	 *
	 * @param string $option_name Private request option from the review record.
	 * @param int    $review_id Review record ID.
	 * @return bool Whether deletion can proceed.
	 */
	public function prepare_review_deletion( string $option_name, int $review_id ): bool {
		$value = $this->read( $option_name );
		// The link to this marker is written last. Keep its review ID available
		// for recovery when a delete succeeds but marker cleanup is interrupted.
		if ( '0' === $value || self::PENDING . $review_id === $value ) {
			return $this->bind( $option_name, $review_id, 0, $value );
		}
		return false !== $value;
	}

	/**
	 * Remove a marker only after the review and its metadata are gone.
	 *
	 * @param string $option_name Private request option.
	 * @param int    $review_id Review record ID.
	 * @return bool Whether the old marker is gone.
	 */
	public function remove_orphan( string $option_name, int $review_id ): bool {
		global $wpdb;
		// Core may have fetched the metadata IDs before the writer finished
		// adding fields. Keep the numeric marker until those fields are gone.
		if ( ! $this->remove_orphaned_metadata( $review_id ) ) {
			return false;
		}
		$placeholders = implode( ', ', array_fill( 0, count( self::RESPONSE_META_KEYS ), '%s' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- A CAS delete checks the post and metadata still agree with cleanup. Only fixed placeholders and table names are interpolated.
		$deleted = $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s AND NOT EXISTS (SELECT 1 FROM {$wpdb->posts} WHERE ID = %d) AND NOT EXISTS (SELECT 1 FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key IN ($placeholders))", array_merge( [ $option_name, (string) $review_id, $review_id, $review_id ], self::RESPONSE_META_KEYS ) ) );
		if ( false === $deleted || '' !== (string) $wpdb->last_error ) {
			return false;
		}
		if ( 1 === $deleted ) {
			$this->flush_option_cache( $option_name );
			return true;
		}

		$value = $this->read( $option_name );
		return false !== $value && (string) $review_id !== $value;
	}

	/**
	 * Retry marker and metadata cleanup after failed or interrupted record deletion.
	 *
	 * @param int $batch_size Maximum candidates per database page.
	 * @param int $limit Total candidates per cleanup pass.
	 * @return bool|null True for another page, false when finished, null to retry failures.
	 */
	public function purge_orphans( int $batch_size, int $limit ): ?bool {
		$progress  = get_option( self::CLEANUP_CURSOR, [] );
		$cursor    = is_array( $progress ) ? (string) ( $progress['cursor'] ?? '' ) : '';
		$failed    = is_array( $progress ) && ! empty( $progress['failed'] );
		$processed = 0;
		while ( $processed < $limit ) {
			$page_size = min( $batch_size, $limit - $processed );
			$page      = $this->purge_orphan_page( $cursor, $page_size );
			if ( null === $page ) {
				return null;
			}
			$failed = $failed || $page['failed'];
			if ( '' === $page['next'] ) {
				if ( '' !== $cursor && ! delete_option( self::CLEANUP_CURSOR ) && get_option( self::CLEANUP_CURSOR, [] ) ) {
					return null;
				}
				return $failed ? null : false;
			}
			$cursor     = $page['next'];
			$processed += $page_size;
			$progress   = [
				'cursor' => $cursor,
				'failed' => $failed,
			];
			if ( ! update_option( self::CLEANUP_CURSOR, $progress, false ) && $progress !== get_option( self::CLEANUP_CURSOR, [] ) ) {
				return null;
			}
		}
		return true;
	}

	/**
	 * Scan one bounded page of request options and retry orphan marker removal.
	 *
	 * @param string $after Exclusive marker name lower bound.
	 * @param int    $limit Marker candidates per page.
	 * @return array{next:string,failed:bool}|null Next cursor and failure state, or null if querying failed.
	 */
	private function purge_orphan_page( string $after, int $limit ): ?array {
		global $wpdb;
		$prefix = $wpdb->esc_like( self::PREFIX ) . '%';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Limit the indexed options range before checking for existing review rows.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT markers.option_id, markers.option_name, markers.option_value, existing.ID AS existing_id FROM (SELECT option_id, option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s AND option_name > %s ORDER BY option_name ASC LIMIT %d) AS markers LEFT JOIN {$wpdb->posts} AS existing ON existing.ID = CASE WHEN markers.option_value REGEXP '^[1-9][0-9]{0,18}$' THEN CAST(markers.option_value AS UNSIGNED) END ORDER BY markers.option_name ASC", $prefix, $after, $limit ), ARRAY_A );
		if ( '' !== (string) $wpdb->last_error || ! is_array( $rows ) ) {
			return null;
		}

		$last   = '';
		$failed = false;
		foreach ( $rows as $row ) {
			$last = (string) $row['option_name'];
			if ( 0 === strpos( $last, self::METADATA_RETRY_PREFIX ) ) {
				$recovered = $this->retry_orphaned_metadata( $last, (string) $row['option_value'] );
				$failed    = $failed || ! $recovered;
				continue;
			}
			$finished  = $this->phase_review_id( $row['option_value'], self::FINISHED );
			$review_id = $finished ? $finished : $this->phase_review_id( $row['option_value'], self::PENDING );
			if ( $review_id ) {
				$recovered = $this->clean_tracked_metadata( $last, (int) $row['option_id'], $review_id, (bool) $finished );
				$failed    = $failed || ! $recovered;
				continue;
			}
			$review_id = $this->numeric_id( $row['option_value'] );
			if ( $review_id && null === $row['existing_id'] && ! $this->remove_orphan( $last, $review_id ) ) {
				$failed = true;
			}
		}

		return [
			'next'   => count( $rows ) === $limit ? $last : '',
			'failed' => $failed,
		];
	}

	/**
	 * Remove metadata whose review is absent, even if no cleanup job was queued.
	 *
	 * @param string $option_name Private request option.
	 * @param int    $reservation_id Option row ID from the cleanup page.
	 * @param int    $review_id Tracked review ID.
	 * @param bool   $finished Whether the writer confirmed compensation.
	 * @return bool Whether the cleanup step succeeded.
	 */
	private function clean_tracked_metadata( string $option_name, int $reservation_id, int $review_id, bool $finished ): bool {
		$exists = $this->post_exists( $review_id );
		// A marker authorizes orphan cleanup only. The retention and deletion
		// paths own any present post, including a restored row at the same ID.
		if ( false !== $exists ) {
			return true === $exists;
		}

		// A paused writer could still add more metadata to a deleted post.
		// Keep its pending marker discoverable for the next scheduled pass.
		return $this->remove_orphaned_metadata( $review_id ) && ( ! $finished || $this->release( $option_name, $reservation_id, $review_id ) );
	}

	/**
	 * Verify the post and metadata that constitute a committed response.
	 *
	 * @param int    $review_id Candidate review ID.
	 * @param string $option_name Private request option.
	 * @param int    $post_id Owning post ID.
	 * @param string $link_hash Link identifier.
	 * @return bool Whether the candidate is complete and belongs to the request.
	 */
	private function matches_response( int $review_id, string $option_name, int $post_id, string $link_hash ): bool {
		$review = get_post( $review_id );
		if ( ! $review instanceof \WP_Post || self::POST_TYPE !== $review->post_type || 'private' !== $review->post_status || $post_id !== (int) $review->post_parent ) {
			return false;
		}
		if ( $link_hash !== get_post_meta( $review_id, '_previewshare_link_hash', true ) || $option_name !== get_post_meta( $review_id, self::META_KEY, true ) ) {
			return false;
		}
		foreach ( [ '_previewshare_content_hash', '_previewshare_response_type', '_previewshare_reviewer_name', '_previewshare_reviewer_email' ] as $key ) {
			if ( ! metadata_exists( 'post', $review_id, $key ) ) {
				return false;
			}
		}
		$hash  = get_post_meta( $review_id, '_previewshare_content_hash', true );
		$type  = get_post_meta( $review_id, '_previewshare_response_type', true );
		$name  = get_post_meta( $review_id, '_previewshare_reviewer_name', true );
		$email = get_post_meta( $review_id, '_previewshare_reviewer_email', true );
		return is_string( $hash ) && 1 === preg_match( '/^[a-f0-9]{64}$/D', $hash )
			&& in_array( $type, [ 'approve', 'request_changes', 'comment' ], true )
			&& is_string( $name ) && is_string( $email );
	}

	/**
	 * Read the durable marker without object-cache races.
	 *
	 * @param string $option_name Private request option.
	 * @return string|null|false Marker value, null if missing, false on read failure.
	 */
	private function read( string $option_name ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- A request may have updated this marker concurrently.
		$value = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", $option_name ) );
		if ( '' !== (string) $wpdb->last_error ) {
			return false;
		}

		return is_int( $value ) || is_string( $value ) ? (string) $value : null;
	}

	/**
	 * Decode a positive, canonical review ID.
	 *
	 * @param string|null|false $value Durable marker value.
	 * @return int Positive ID, or zero for an unrecognized value.
	 */
	private function numeric_id( $value ): int {
		if ( ! is_string( $value ) || 1 !== preg_match( '/^[1-9][0-9]*$/D', $value ) ) {
			return 0;
		}

		$id = (int) $value;
		return (string) $id === $value ? $id : 0;
	}

	/**
	 * Decode the review ID for a pending or finished marker.
	 *
	 * @param string|null|false $value Stored marker.
	 * @param string            $phase Expected phase prefix.
	 * @return int Positive ID, or zero for a different phase.
	 */
	private function phase_review_id( $value, string $phase ): int {
		return is_string( $value ) && 0 === strpos( $value, $phase ) ? $this->numeric_id( substr( $value, strlen( $phase ) ) ) : 0;
	}

	/**
	 * Invalidate Options API cache entries after a direct marker mutation.
	 *
	 * @param string $option_name Changed option.
	 * @return void
	 */
	public function flush_option_cache( string $option_name ): void {
		wp_cache_delete( $option_name, 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		// Core can cache every option when its autoload query has no matches.
		$alloptions = wp_cache_get( 'alloptions', 'options' );
		if ( is_array( $alloptions ) && isset( $alloptions[ $option_name ] ) ) {
			wp_cache_delete( 'alloptions', 'options' );
		}
	}
}
