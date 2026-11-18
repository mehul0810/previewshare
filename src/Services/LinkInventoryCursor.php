<?php
/**
 * Stable, additive inventory traversal for the administrator interface.
 *
 * @package PreviewShare
 */

namespace PreviewShare\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Fetch a link page and its inventory version in a single database read.
 *
 * The cursor checks aggregate membership and built-in link configuration.
 * Views, time-based expiry, related posts and reviews, and writes that
 * bypass the private revision are outside this check.
 */
final class LinkInventoryCursor {
	/**
	 * Authoritative metadata key prefix.
	 *
	 * @var string
	 */
	private $prefix;

	/**
	 * Create an inventory reader.
	 *
	 * @param string $prefix Authoritative detail metadata prefix.
	 */
	public function __construct( string $prefix ) {
		$this->prefix = $prefix;
	}

	/**
	 * Read one page and refuse to append after a tracked inventory change.
	 *
	 * @param int    $limit Requested page size.
	 * @param string $cursor Start sentinel or opaque continuation.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function page( int $limit, string $cursor ) {
		$limit    = max( 1, min( 100, $limit ) );
		$previous = 'start' === $cursor ? null : $this->decode( $cursor );
		if ( 'start' !== $cursor && null === $previous ) {
			return new \WP_Error( 'previewshare_invalid_cursor', __( 'The preview link continuation is invalid. Refresh the list.', 'previewshare' ), [ 'status' => 400 ] );
		}
		// A cursor issued before revisions were tracked cannot validate earlier rows.
		if ( null !== $previous && null === $previous['revision'] ) {
			return $this->changed();
		}
		$rows = $this->read_page( $limit + 1, $previous['before'] ?? null );
		if ( null === $rows || ! isset( $rows[0]['total'], $rows[0]['max_id'], $rows[0]['id_sum'], $rows[0]['revision_sums'] ) || ! array_key_exists( 'meta_id', $rows[0] ) ) {
			return $this->unavailable();
		}

		$total    = (int) $rows[0]['total'];
		$max      = (int) $rows[0]['max_id'];
		$revision = substr( hash( 'sha256', $rows[0]['id_sum'] . ':' . $rows[0]['revision_sums'], true ), 0, 12 );
		if ( null !== $previous && ( $max !== $previous['max'] || $total !== $previous['total'] || $revision !== $previous['revision'] ) ) {
			return $this->changed();
		}

		// The left join preserves the summary when the page has no rows.
		if ( null === $rows[0]['meta_id'] ) {
			$rows = [];
		}
		$more = count( $rows ) > $limit;
		$rows = array_slice( $rows, 0, $limit );
		foreach ( $rows as &$row ) {
			unset( $row['total'], $row['max_id'], $row['id_sum'], $row['revision_sums'] );
		}
		unset( $row );
		$last = $rows ? (int) $rows[ count( $rows ) - 1 ]['meta_id'] : 0;
		$next = $more && $last > 0 ? $this->encode( $max, $total, $last, $revision ) : null;
		return [
			'rows' => $rows,
			'total' => $total,
			'next_cursor' => $next,
		];
	}

	/**
	 * Read the membership, edit revisions and bounded page in one statement.
	 *
	 * @param int      $limit Page size including the extra continuation row.
	 * @param int|null $before Exclusive upper bound supplied by a continuation.
	 * @return list<array<string,mixed>>|null
	 */
	private function read_page( int $limit, ?int $before ): ?array {
		global $wpdb;
		[ $revisions, $markers ] = PostMetaRow::inventory_revision_sums();
		$like                    = $wpdb->esc_like( $this->prefix ) . '%';
		$bound                   = null === $before ? '' : ' AND pm.meta_id < %d';
		$args                    = array_merge( $markers, [ $like, 'revision', $like, 'revision' ] );
		if ( null !== $before ) {
			$args[] = $before;
		}
		$args[] = $limit;

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Table names and SQL expressions are fixed. All dynamic values are prepared.
		$sql = $wpdb->prepare(
			"SELECT inventory.total, inventory.max_id, inventory.id_sum, inventory.revision_sums, link_page.meta_id, link_page.post_id, link_page.meta_key, link_page.meta_value
			FROM (
				SELECT COUNT(*) AS total, COALESCE(MAX(pm.meta_id), 0) AS max_id,
					COALESCE(SUM(pm.meta_id %% 1000000007), 0) AS id_sum, $revisions AS revision_sums
				FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				WHERE pm.meta_key LIKE %s AND p.post_type <> %s
			) inventory
			LEFT JOIN (
				SELECT pm.meta_id, pm.post_id, pm.meta_key, pm.meta_value
				FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				WHERE pm.meta_key LIKE %s AND p.post_type <> %s$bound
				ORDER BY pm.meta_id DESC LIMIT %d
			) link_page ON 1 = 1
			ORDER BY link_page.meta_id DESC",
			...$args
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The summary and rows must reflect the same database read.
		$count = $wpdb->query( $sql );
		$rows  = $wpdb->last_result ?? null;
		if ( false === $count || ! is_array( $rows ) || count( $rows ) !== $count || '' !== (string) $wpdb->last_error ) {
			return null;
		}
		return array_map( static fn( $row ): array => (array) $row, $rows );
	}

	/**
	 * Encode a continuation for the next request.
	 *
	 * @param int    $max Largest metadata ID.
	 * @param int    $total Inventory total.
	 * @param int    $before Last returned metadata ID.
	 * @param string $revision Binary checksum of the membership and edit sums.
	 * @return string
	 */
	private function encode( int $max, int $total, int $before, string $revision ): string {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- URL-safe transport for a non-secret pagination cursor.
		return rtrim( strtr( base64_encode( $max . ':' . $total . ':' . $before . ':' . $revision ), '+/', '-_' ), '=' );
	}

	/**
	 * Validate and decode a continuation.
	 *
	 * @param string $cursor Continuation from the previous request.
	 * @return array{max:int,total:int,before:int,revision:?string}|null
	 */
	private function decode( string $cursor ): ?array {
		if ( strlen( $cursor ) > 100 || ! preg_match( '/^[A-Za-z0-9_-]+$/D', $cursor ) ) {
			return null;
		}
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decode the non-secret pagination cursor.
		$value = base64_decode( strtr( $cursor, '-_', '+/' ), true );
		if ( ! is_string( $value ) || ! preg_match( '/^([1-9][0-9]*):([1-9][0-9]*):([1-9][0-9]*)(?::(.{12}))?$/sD', $value, $parts ) ) {
			return null;
		}
		$max    = (int) $parts[1];
		$total  = (int) $parts[2];
		$before = (int) $parts[3];
		if ( (string) $max !== $parts[1] || (string) $total !== $parts[2] || (string) $before !== $parts[3] || $before > $max ) {
			return null;
		}
		return [
			'max' => $max,
			'total' => $total,
			'before' => $before,
			'revision' => $parts[4] ?? null,
		];
	}

	/**
	 * Report an inventory that changed during traversal.
	 *
	 * @return \WP_Error
	 */
	private function changed(): \WP_Error {
		return new \WP_Error( 'previewshare_inventory_changed', __( 'Preview links changed while loading. Refresh the list.', 'previewshare' ), [ 'status' => 409 ] );
	}

	/**
	 * Report an unavailable inventory query.
	 *
	 * @return \WP_Error
	 */
	private function unavailable(): \WP_Error {
		return new \WP_Error( 'previewshare_inventory_unavailable', __( 'Preview links could not be loaded. Please try again.', 'previewshare' ), [ 'status' => 503 ] );
	}
}
