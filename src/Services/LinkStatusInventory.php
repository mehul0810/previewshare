<?php
/**
 * Correct status filtering for older or unusual stored link records.
 *
 * @package PreviewShare
 */

namespace PreviewShare\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Keep status filtering consistent with normalized link records.
 *
 * @internal
 */
final class LinkStatusInventory {
	/**
	 * Use the ordinary SQL status query only when every record has a known layout.
	 * Otherwise scan in bounded batches using the existing PHP formatter and status logic.
	 *
	 * @param string   $prefix Detail meta key prefix.
	 * @param string   $status Requested status.
	 * @param int      $per_page Page size.
	 * @param int      $offset Starting item offset.
	 * @param callable $format Converts raw detail rows to inventory items.
	 * @return array{items:array<int,array<string,mixed>>,total:int}|\WP_Error|null Null means the fast SQL path is safe.
	 */
	public static function fallback_if_needed( string $prefix, string $status, int $per_page, int $offset, callable $format ) {
		global $wpdb;
		$like                           = $wpdb->esc_like( $prefix ) . '%';
		[ $canonical, $canonical_args ] = PostMetaRow::canonical_status_record_clause();
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		$sql = $wpdb->prepare(
			"SELECT pm.meta_id FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			WHERE pm.meta_key LIKE %s AND p.post_type <> %s AND NOT COALESCE(($canonical), 0) LIMIT 1",
			...array_merge( [ $like, 'revision' ], $canonical_args )
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Check for incompatible layouts before using SQL's serialized-field shortcut.
		$unknown = $wpdb->get_var( $sql );
		if ( null === $unknown && empty( $wpdb->last_error ) ) {
			return null;
		}

		$items  = [];
		$total  = 0;
		$before = PHP_INT_MAX;
		do {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Keyset batches bound memory while PHP applies the authoritative status logic.
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT pm.meta_id, pm.post_id, pm.meta_key, pm.meta_value FROM {$wpdb->postmeta} pm
				INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				WHERE pm.meta_key LIKE %s AND p.post_type <> %s AND pm.meta_id < %d ORDER BY pm.meta_id DESC LIMIT %d",
					$like,
					'revision',
					$before,
					200
				),
				ARRAY_A
			);
			if ( ! is_array( $rows ) || ! empty( $wpdb->last_error ) ) {
				return new \WP_Error( 'previewshare_inventory_unavailable', __( 'Preview links could not be loaded. Please try again.', 'previewshare' ), [ 'status' => 503 ] );
			}
			if ( empty( $rows ) ) {
				break;
			}
			$row_count = count( $rows );
			$last      = end( $rows );
			$before    = (int) $last['meta_id'];
			foreach ( $format( $rows ) as $item ) {
				if ( ( $item['status'] ?? '' ) !== $status ) {
					continue;
				}
				if ( $total >= $offset && count( $items ) < $per_page ) {
					$items[] = $item;
				}
				++$total;
			}
		} while ( 200 === $row_count && $before > 0 );

		return [
			'items' => $items,
			'total' => $total,
		];
	}
}
