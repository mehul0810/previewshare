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
 * Fetch a link page using post-meta IDs and a checked inventory snapshot.
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
	 * Read one page and refuse to combine different inventory snapshots.
	 *
	 * @param int    $limit Requested page size.
	 * @param string $cursor Start sentinel or opaque continuation.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function page( int $limit, string $cursor ) {
		global $wpdb;
		$limit    = max( 1, min( 100, $limit ) );
		$previous = 'start' === $cursor ? null : $this->decode( $cursor );
		if ( 'start' !== $cursor && null === $previous ) {
			return new \WP_Error( 'previewshare_invalid_cursor', __( 'The preview link continuation is invalid. Refresh the list.', 'previewshare' ), [ 'status' => 400 ] );
		}
		$summary = $this->summary();
		if ( null === $summary ) {
			return $this->unavailable();
		}
		if ( null !== $previous && ( $summary['max'] !== $previous['max'] || $summary['total'] !== $previous['total'] ) ) {
			return $this->changed();
		}
		if ( 0 === $summary['max'] ) {
			return [
				'rows' => [],
				'total' => 0,
				'next_cursor' => null,
			];
		}

		$operator = null === $previous ? '<=' : '<';
		$bound    = null === $previous ? $summary['max'] : $previous['before'];
		$like     = $wpdb->esc_like( $this->prefix ) . '%';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Trusted table identifiers and fixed operator; all values are prepared.
		$sql = "SELECT pm.meta_id, pm.post_id, pm.meta_key, pm.meta_value FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.meta_key LIKE %s AND p.post_type <> %s AND pm.meta_id {$operator} %d ORDER BY pm.meta_id DESC LIMIT %d";
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Snapshot must reflect committed inventory changes.
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $like, 'revision', $bound, $limit + 1 ), ARRAY_A );
		if ( ! is_array( $rows ) || '' !== (string) $wpdb->last_error ) {
			return $this->unavailable();
		}
		$after = $this->summary();
		if ( null === $after ) {
			return $this->unavailable();
		}
		if ( $summary !== $after ) {
			return $this->changed();
		}
		$more = count( $rows ) > $limit;
		$rows = array_slice( $rows, 0, $limit );
		$last = $rows ? (int) $rows[ count( $rows ) - 1 ]['meta_id'] : 0;
		$next = $more && $last > 0 ? $this->encode( $summary['max'], $summary['total'], $last ) : null;
		return [
			'rows' => $rows,
			'total' => $summary['total'],
			'next_cursor' => $next,
		];
	}

	/**
	 * Read the current inventory summary.
	 *
	 * @return array{max:int,total:int}|null
	 */
	private function summary(): ?array {
		global $wpdb;
		$like = $wpdb->esc_like( $this->prefix ) . '%';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Fresh snapshot detects concurrent additions and removals.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT COUNT(*) AS total, COALESCE(MAX(pm.meta_id), 0) AS max_id FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.meta_key LIKE %s AND p.post_type <> %s", $like, 'revision' ), ARRAY_A );
		if ( ! is_array( $row ) || '' !== (string) $wpdb->last_error || ! isset( $row['total'], $row['max_id'] ) ) {
			return null;
		}
		return [
			'max' => (int) $row['max_id'],
			'total' => (int) $row['total'],
		];
	}

	/**
	 * Encode a continuation for the next request.
	 *
	 * @param int $max Largest metadata ID.
	 * @param int $total Inventory total.
	 * @param int $before Last returned metadata ID.
	 * @return string
	 */
	private function encode( int $max, int $total, int $before ): string {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- URL-safe transport for a non-secret pagination cursor.
		return rtrim( strtr( base64_encode( $max . ':' . $total . ':' . $before ), '+/', '-_' ), '=' );
	}

	/**
	 * Validate and decode a continuation.
	 *
	 * @param string $cursor Continuation from the previous request.
	 * @return array{max:int,total:int,before:int}|null
	 */
	private function decode( string $cursor ): ?array {
		if ( strlen( $cursor ) > 100 || ! preg_match( '/^[A-Za-z0-9_-]+$/D', $cursor ) ) {
			return null;
		}
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decode the non-secret pagination cursor.
		$value = base64_decode( strtr( $cursor, '-_', '+/' ), true );
		if ( ! is_string( $value ) || ! preg_match( '/^([1-9][0-9]*):([1-9][0-9]*):([1-9][0-9]*)$/D', $value, $parts ) ) {
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
