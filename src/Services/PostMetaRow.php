<?php
/**
 * Conditional writes to existing post metadata.
 *
 * @package PreviewShare
 */

namespace PreviewShare\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Compare exact serialized values without letting WordPress insert a missing row.
 *
 * @internal
 */
final class PostMetaRow {
	private const STATUS_MARKERS = [ 's:10:"created_by";', 's:10:"expires_at";', 's:7:"revoked";' ];
	private const RECORD_PATTERN = '^a:(8|10):[{]s:4:"hash";s:[0-9]+:"[^"]*";s:5:"label";s:[0-9]+:".*";'
		. 's:10:"created_at";i:[0-9]+;s:10:"created_by";i:[0-9]+;s:10:"expires_at";(N|i:[0-9]+);'
		. 's:7:"revoked";i:[01];s:14:"last_viewed_at";(N|i:[0-9]+);s:10:"view_count";i:[0-9]+;'
		. '(s:17:"responses_enabled";b:[01];s:17:"identity_required";b:[01];)?[}]$';

	/**
	 * Disable sharing only if no active detail row exists at the time of the write.
	 *
	 * @param int                               $post_id Post ID.
	 * @param string                            $detail_prefix Detail meta key prefix.
	 * @param array<string,array<string,mixed>> $inactive Details PHP has confirmed inactive.
	 * @return void
	 */
	public static function disable_sharing_without_active_links( int $post_id, string $detail_prefix, array $inactive = [] ): void {
		ExistingPostMetaUpdate::apply(
			$post_id,
			'_previewshare_enabled',
			false,
			'',
			static function ( $value, array $ids ) use ( $post_id, $detail_prefix, $inactive ): bool {
				return self::write_sharing_flag( $post_id, $detail_prefix, $inactive, $value, $ids );
			}
		);
	}

	/**
	 * Guard the sharing update using current authoritative rows.
	 *
	 * @param int                               $post_id Post ID.
	 * @param string                            $detail_prefix Detail meta key prefix.
	 * @param array<string,array<string,mixed>> $inactive Confirmed inactive records.
	 * @param mixed                             $value Sanitized sharing flag.
	 * @param array                             $ids Existing metadata row IDs.
	 * @phpstan-param list<int> $ids
	 * @return bool True if the guarded write updated a row.
	 */
	private static function write_sharing_flag( int $post_id, string $detail_prefix, array $inactive, $value, array $ids ): bool {
		global $wpdb;
		$like = $wpdb->esc_like( $detail_prefix ) . '%';

		[ $active, $args ] = self::potentially_active_clause();

		[ $known, $known_args ] = self::known_inactive_clause( $detail_prefix, $inactive );

		$guard           = "($active) AND NOT COALESCE(($known), 0)";
		$args            = array_merge( $args, $known_args );
		$id_placeholders = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );
		// The WordPress SQLite drop-in translates UPDATE joins to inner joins. Its
		// database allows reading the target table in a subquery instead.
		if ( self::uses_sqlite( $wpdb ) ) {
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $active is a fixed condition with a bound timestamp.
			$sql = $wpdb->prepare(
				"UPDATE {$wpdb->postmeta} SET meta_value = %s
				WHERE post_id = %d AND meta_key = %s AND meta_id IN ($id_placeholders) AND NOT EXISTS (
					SELECT 1 FROM {$wpdb->postmeta} pm WHERE pm.post_id = %d AND pm.meta_key LIKE %s AND ($guard)
				)",
				...array_merge( [ maybe_serialize( $value ), $post_id, '_previewshare_enabled' ], $ids, [ $post_id, $like ], $args )
			);
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		} else {
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $active is a fixed condition with a bound timestamp.
			$sql = $wpdb->prepare(
				"UPDATE {$wpdb->postmeta} sharing
				LEFT JOIN {$wpdb->postmeta} pm ON pm.post_id = sharing.post_id AND pm.meta_key LIKE %s AND ($guard)
				SET sharing.meta_value = %s
				WHERE sharing.post_id = %d AND sharing.meta_key = %s AND sharing.meta_id IN ($id_placeholders) AND pm.meta_id IS NULL",
				...array_merge( [ $like ], $args, [ maybe_serialize( $value ), $post_id, '_previewshare_enabled' ], $ids )
			);
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared -- The link check and flag write must be atomic on the same database.
		if ( $wpdb->query( $sql ) > 0 ) {
			wp_cache_delete( $post_id, 'post_meta' );
			return true;
		}
		return false;
	}

	/**
	 * Check for the WordPress SQLite drop-in without making it a dependency.
	 *
	 * @param object $database WordPress database adapter.
	 * @return bool
	 */
	private static function uses_sqlite( object $database ): bool {
		return is_a( $database, 'WP_SQLite_DB' );
	}

	/**
	 * Patch the projection once for a batch of authoritative revocations.
	 *
	 * @param int                $post_id Post ID.
	 * @param string             $key Aggregate meta key.
	 * @param array<string,true> $hashes Revoked detail hashes.
	 * @return bool Whether the projection was updated or already consistent.
	 */
	public static function mark_aggregate_revoked( int $post_id, string $key, array $hashes ): bool {
		if ( [] === $hashes ) {
			return true;
		}
		for ( $attempt = 0; $attempt < 3; $attempt++ ) {
			$previous = get_post_meta( $post_id, $key, true );
			if ( ! is_array( $previous ) ) {
				return true;
			}
			$updated = $previous;
			foreach ( array_intersect_key( $previous, $hashes ) as $hash => $entry ) {
				if ( is_array( $entry ) ) {
					$updated[ $hash ]['revoked'] = 1;
				}
			}
			if ( $updated === $previous ) {
				return true;
			}
			// Core's update_post_meta can recreate the map if its row was just deleted.
			$changed = ExistingPostMetaUpdate::apply(
				$post_id,
				$key,
				$updated,
				$previous,
				static function ( $value, array $ids ) use ( $post_id, $key, $previous ): ?bool {
					return self::compare_and_swap( $post_id, $key, $previous, $value, $ids );
				}
			);
			if ( false !== $changed ) {
				return true === $changed;
			}
		}
		return false;
	}

	/**
	 * Veto an automatic disable when an active or ambiguous detail exists.
	 *
	 * Inventory tolerates other record layouts. A disable can only classify
	 * canonical old and current values with unambiguous status anchors as inactive.
	 *
	 * @return array{string,list<int|string>} Fixed SQL and its placeholder arguments.
	 */
	private static function potentially_active_clause(): array {
		[ $active, $args ] = self::status_clause( 'active', time() );

		[ $canonical, $canonical_args ] = self::canonical_status_record_clause();

		return [ "NOT COALESCE(($canonical), 0) OR ($active)", array_merge( $canonical_args, $args ) ];
	}

	/**
	 * Recognize serialized records with unambiguous status fields in the `pm` alias.
	 *
	 * @return array{string,list<string>} Fixed SQL and its placeholder arguments.
	 */
	public static function canonical_status_record_clause(): array {
		$unambiguous = implode(
			' AND ',
			array_map(
				static function ( string $marker ): string {
					return "LENGTH(pm.meta_value) - LENGTH(REPLACE(pm.meta_value, '$marker', '')) = LENGTH('$marker')";
				},
				self::STATUS_MARKERS
			)
		);

		return [ "pm.meta_value REGEXP %s AND $unambiguous", [ self::RECORD_PATTERN ] ];
	}

	/**
	 * Recognize ambiguous records already verified inactive by exact raw value.
	 * Protect against changes between PHP classification and the database write.
	 *
	 * @param string                            $prefix Detail meta key prefix.
	 * @param array<string,array<string,mixed>> $inactive Verified inactive detail snapshot.
	 * @return array{string,list<string>} Fixed SQL and exact key/value arguments.
	 */
	private static function known_inactive_clause( string $prefix, array $inactive ): array {
		$clauses = [];
		$args    = [];
		$bytes   = 0;
		foreach ( $inactive as $hash => $link ) {
			$encoded = maybe_serialize( $link );
			if ( self::is_canonical_status_record( $encoded ) ) {
				continue;
			}
			// Bound statement size for old or malformed metadata. Other ambiguous
			// detail rows keep the flag unchanged and their detail state remains authoritative.
			$bytes += strlen( $hash ) + strlen( $encoded );
			if ( $bytes > 65536 || count( $clauses ) >= 100 ) {
				break;
			}
			$clauses[] = '(pm.meta_key = %s AND CAST(pm.meta_value AS BINARY) = %s)';
			$args[]    = $prefix . $hash;
			$args[]    = $encoded;
		}
		return [ empty( $clauses ) ? '0' : implode( ' OR ', $clauses ), $args ];
	}

	/**
	 * Check the canonical serialized record shape before an exact comparison is needed.
	 *
	 * @param string $encoded Serialized detail.
	 * @return bool
	 */
	private static function is_canonical_status_record( string $encoded ): bool {
		if ( 1 !== preg_match( '~' . self::RECORD_PATTERN . '~D', $encoded ) ) {
			return false;
		}
		foreach ( self::STATUS_MARKERS as $marker ) {
			if ( 1 !== substr_count( $encoded, $marker ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Build a status condition for serialized link records in the `pm` alias.
	 *
	 * @param string $status Link status.
	 * @param int    $now Reference timestamp.
	 * @return array{string,list<int>} Fixed SQL and any placeholder arguments.
	 */
	public static function status_clause( string $status, int $now ): array {
		$created_by_marker = 's:10:"created_by";';
		$revoked_marker    = 's:7:"revoked";';
		$expires_marker    = 's:10:"expires_at";';
		$created_by_offset = "INSTR(REVERSE(pm.meta_value), REVERSE('$created_by_marker'))";
		$revoked_offset    = "INSTR(REVERSE(pm.meta_value), REVERSE('$revoked_marker'))";
		$expires_offset    = "INSTR(REVERSE(pm.meta_value), REVERSE('$expires_marker'))";
		$revoked_tail      = "SUBSTR(pm.meta_value, 1 - $revoked_offset)";
		$expires_tail      = "SUBSTR(pm.meta_value, 1 - $expires_offset)";
		$revoked           = "SUBSTR($revoked_tail, 1, INSTR($revoked_tail, ';') - 1)";
		$expires           = "SUBSTR($expires_tail, 1, INSTR($expires_tail, ';') - 1)";
		$revoked_present   = "$revoked_offset > 0 AND $revoked_offset < $created_by_offset";
		$expires_present   = "$expires_offset > 0 AND $expires_offset < $created_by_offset";

		if ( 'revoked' === $status ) {
			return [ "$revoked_present AND CAST(SUBSTRING($revoked, 3) AS UNSIGNED) > 0", [] ];
		}
		if ( 'expired' === $status ) {
			return [ "NOT ($revoked_present AND CAST(SUBSTRING($revoked, 3) AS UNSIGNED) > 0) AND $expires_present AND 'N' <> $expires AND CAST(SUBSTRING($expires, 3) AS UNSIGNED) <= %d", [ $now ] ];
		}
		return [ "NOT ($revoked_present AND CAST(SUBSTRING($revoked, 3) AS UNSIGNED) > 0) AND (NOT $expires_present OR 'N' = $expires OR CAST(SUBSTRING($expires, 3) AS UNSIGNED) > %d)", [ $now ] ];
	}

	/**
	 * Replace an existing row if it still matches the caller's snapshot.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $key Meta key.
	 * @param mixed  $previous Existing value.
	 * @param mixed  $value Replacement value.
	 * @param array  $ids Optional existing metadata row IDs.
	 * @phpstan-param list<int> $ids
	 * @return bool|null True if this write changed the row, false on mismatch, null on database failure.
	 */
	public static function compare_and_swap( int $post_id, string $key, $previous, $value, array $ids = [] ): ?bool {
		global $wpdb;
		if ( ! is_object( $wpdb ) || ! isset( $wpdb->postmeta ) || ! method_exists( $wpdb, 'prepare' ) || ! method_exists( $wpdb, 'query' ) ) {
			return null;
		}

		$exact_ids = [] !== $ids ? ' AND meta_id IN (' . implode( ', ', array_fill( 0, count( $ids ), '%d' ) ) . ')' : '';
		// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $exact_ids contributes one placeholder per ID.
		$sql = $wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $exact_ids contains only positional placeholders.
			"UPDATE {$wpdb->postmeta} SET meta_value = %s WHERE post_id = %d AND meta_key = %s AND CAST(meta_value AS BINARY) = %s$exact_ids",
			maybe_serialize( $value ),
			$post_id,
			$key,
			maybe_serialize( $previous ),
			...$ids
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared -- Core's metadata API can insert after a concurrent delete.
		$updated = $wpdb->query( $sql );
		if ( false === $updated ) {
			return null;
		}

		wp_cache_delete( $post_id, 'post_meta' );
		return $updated > 0;
	}
}
