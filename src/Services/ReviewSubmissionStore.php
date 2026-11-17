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
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Unique insert arbitrates concurrent requests; the table identifier is WordPress-owned.
		$inserted = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", $option_name, '0' ) );
		if ( false === $inserted || '' !== (string) $wpdb->last_error ) {
			return null;
		}
		if ( 1 !== (int) $inserted ) {
			return 0;
		}
		$reservation_id = (int) $wpdb->insert_id;
		// The option was inserted outside the Options API; clear cached misses before finalization.
		$this->flush_option_cache( $option_name );
		return $reservation_id > 0 ? $reservation_id : null;
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
		$stored_id = $this->read( $option_name );
		if ( '0' !== $stored_id ) {
			$review_id = $this->numeric_id( $stored_id );
			return $review_id && $this->matches_response( $review_id, $option_name, $post_id, $link_hash ) && $this->is_bound( $option_name, $review_id, $post_id );
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
			return false;
		}
		$review_id = (int) $ids[0];
		return $this->matches_response( $review_id, $option_name, $post_id, $link_hash ) && $this->commit( $option_name, $review_id, $post_id );
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
	 * @return bool Whether the marker points to the review.
	 */
	public function commit( string $option_name, int $review_id, int $post_id ): bool {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Compare and set only while the review still exists and its owner has not been deleted.
		$updated = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = '0' AND EXISTS (SELECT 1 FROM {$wpdb->posts} AS reviews INNER JOIN {$wpdb->postmeta} AS markers ON markers.post_id = reviews.ID AND markers.meta_key = %s AND markers.meta_value = %s LEFT JOIN {$wpdb->posts} AS owners ON owners.ID = reviews.post_parent WHERE reviews.ID = %d AND reviews.post_type = %s AND reviews.post_status = 'private' AND (%d = 0 OR owners.ID = %d))", (string) $review_id, $option_name, self::META_KEY, $option_name, $review_id, self::POST_TYPE, $post_id, $post_id ) );
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
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- A stale marker or WordPress object cache cannot confirm a review whose parent or review row is gone.
		$confirmed = $wpdb->get_var( $wpdb->prepare( "SELECT reviews.ID FROM {$wpdb->options} AS requests INNER JOIN {$wpdb->posts} AS reviews ON reviews.ID = %d INNER JOIN {$wpdb->postmeta} AS markers ON markers.post_id = reviews.ID AND markers.meta_key = %s AND markers.meta_value = requests.option_name LEFT JOIN {$wpdb->posts} AS owners ON owners.ID = reviews.post_parent WHERE requests.option_name = %s AND requests.option_value = %s AND reviews.post_type = %s AND reviews.post_status = 'private' AND (%d = 0 OR owners.ID = %d) LIMIT 1", $review_id, self::META_KEY, $option_name, (string) $review_id, self::POST_TYPE, $post_id, $post_id ) );
		return '' === (string) $wpdb->last_error && (string) $review_id === (string) $confirmed;
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
		$deleted = $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_id = %d AND option_name = %s AND option_value = '0' AND (%d = 0 OR NOT EXISTS (SELECT 1 FROM {$wpdb->posts} WHERE ID = %d))", $reservation_id, $option_name, $review_id, $review_id ) );
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
		if ( $this->remove_orphaned_metadata( $review_id ) && $this->release( $option_name, $reservation_id, $review_id ) ) {
			return true;
		}

		// Store only the original option reference. Its row ID fences out any later reservation.
		$job   = self::METADATA_RETRY_PREFIX . $review_id;
		$value = $option_name . ':' . $reservation_id;
		return add_option( $job, $value, '', false ) || $value === $this->read( $job ) ? null : false;
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
		return false !== $value && ( '0' !== $value || $this->commit( $option_name, $review_id, 0 ) );
	}

	/**
	 * Remove a marker only while it names the missing review.
	 *
	 * @param string $option_name Private request option.
	 * @param int    $review_id Review record ID.
	 * @return bool Whether the old marker is gone.
	 */
	public function remove_orphan( string $option_name, int $review_id ): bool {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- A CAS delete must preserve replacement reservations and references to existing posts.
		$deleted = $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s AND NOT EXISTS (SELECT 1 FROM {$wpdb->posts} WHERE ID = %d)", $option_name, (string) $review_id, $review_id ) );
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
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT markers.option_name, markers.option_value, existing.ID AS existing_id FROM (SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s AND option_name > %s ORDER BY option_name ASC LIMIT %d) AS markers LEFT JOIN {$wpdb->posts} AS existing ON existing.ID = CASE WHEN markers.option_value REGEXP '^[1-9][0-9]{0,18}$' THEN CAST(markers.option_value AS UNSIGNED) END ORDER BY markers.option_name ASC", $prefix, $after, $limit ), ARRAY_A );
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
	 * Invalidate Options API cache entries after a direct marker mutation.
	 *
	 * @param string $option_name Changed option.
	 * @return void
	 */
	private function flush_option_cache( string $option_name ): void {
		wp_cache_delete( $option_name, 'options' );
		wp_cache_delete( 'notoptions', 'options' );
	}
}
