<?php
/**
 * Preserve WordPress metadata hooks for guarded updates of existing rows.
 *
 * @package PreviewShare
 */

namespace PreviewShare\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WordPress hook protocol for an existing-only metadata write.
 *
 * @internal
 */
final class ExistingPostMetaUpdate {
	/**
	 * Run the core metadata update filter and actions around an existing-only write.
	 *
	 * @param int                             $post_id Post ID.
	 * @param string                          $key Metadata key.
	 * @param mixed                           $value Unslashed metadata value.
	 * @param mixed                           $previous Conditional value exposed to the filter.
	 * @param callable(mixed,list<int>):?bool $write Persists the sanitized value for captured row IDs.
	 * @return bool|null True on success, false for a missed write, null on a database error.
	 */
	public static function apply( int $post_id, string $key, $value, $previous, callable $write ): ?bool {
		global $wpdb;
		$value    = sanitize_meta( $key, $value, 'post', get_object_subtype( 'post', $post_id ) );
		$filtered = apply_filters( 'update_post_metadata', null, $post_id, $key, $value, $previous );
		if ( null !== $filtered ) {
			return (bool) $filtered;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Matches the core metadata mutation's ID lookup, without allowing its insert fallback.
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT meta_id FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s", $post_id, $key ) );
		if ( empty( $ids ) ) {
			return false;
		}

		$encoded = maybe_serialize( $value );
		foreach ( $ids as $id ) {
			do_action( 'update_post_meta', $id, $post_id, $key, $value );
			do_action( 'update_postmeta', $id, $post_id, $key, $encoded );
		}

		$changed = $write( $value, array_map( 'intval', array_values( $ids ) ) );
		if ( true === $changed ) {
			foreach ( $ids as $id ) {
				do_action( 'updated_post_meta', $id, $post_id, $key, $value );
				do_action( 'updated_postmeta', $id, $post_id, $key, $encoded );
			}
		}
		return $changed;
	}
}
