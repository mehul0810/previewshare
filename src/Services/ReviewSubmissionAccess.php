<?php
/**
 * Authoritative eligibility for new review writes.
 *
 * @package PreviewShare
 */

namespace PreviewShare\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads policy directly from the same rows that editors change.
 *
 * @internal
 */
final class ReviewSubmissionAccess {
	/**
	 * Select the post and its effective metadata rows by primary key.
	 *
	 * WordPress reads the first matching row in meta ID order. The keyed joins
	 * let a locking read hold those rows without locking the metadata range.
	 *
	 * @param string $posts Site posts table.
	 * @param string $postmeta Site post metadata table.
	 * @param string $key_placeholder Placeholder for the expected link key in its original and hex form.
	 * @param string $post_placeholder Placeholder for the parent ID.
	 * @return string
	 */
	public static function select_sql( string $posts, string $postmeta, string $key_placeholder, string $post_placeholder ): string {
		return "SELECT p.post_type, p.post_status, enabled.meta_value AS enabled, link.meta_value AS link
			FROM $posts p
			INNER JOIN $postmeta enabled ON enabled.meta_id = (
				SELECT MIN(meta_id) FROM $postmeta
				WHERE post_id = p.ID AND meta_key = '_previewshare_enabled'
				AND HEX(meta_key) = HEX('_previewshare_enabled')
			) AND enabled.post_id = p.ID AND HEX(enabled.meta_key) = HEX('_previewshare_enabled')
			INNER JOIN $postmeta link ON link.meta_id = (
				SELECT MIN(meta_id) FROM $postmeta
				WHERE post_id = p.ID AND meta_key = $key_placeholder AND HEX(meta_key) = $key_placeholder
			) AND link.post_id = p.ID AND HEX(link.meta_key) = $key_placeholder
			WHERE p.ID = $post_placeholder";
	}

	/**
	 * Build query parameters for a link identifier and owning post.
	 *
	 * @param int    $post_id Owning post ID.
	 * @param string $link_hash Link identifier.
	 * @return list<int|string>
	 */
	public static function parameters( int $post_id, string $link_hash ): array {
		$key = PostMetaStorage::DETAIL_META_PREFIX . $link_hash;
		$hex = strtoupper( bin2hex( $key ) );
		return [ $key, $hex, $hex, $post_id ];
	}

	/**
	 * Decode a stored metadata value without invoking serialized object hooks.
	 *
	 * @param mixed $value Raw value from the metadata table.
	 * @return mixed
	 */
	public static function decode( $value ) {
		if ( ! is_serialized( $value ) ) {
			return $value;
		}
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- Mirror Core metadata decoding without instantiating objects or exposing warnings for bad stored data.
		return @unserialize( trim( $value ), [ 'allowed_classes' => false ] );
	}

	/**
	 * Determine whether the current stored expiration has passed.
	 *
	 * @param array<string,mixed> $link Decoded detail record.
	 * @return bool
	 */
	public static function is_expired( array $link ): bool {
		return isset( $link['expires_at'] ) && (int) $link['expires_at'] <= time();
	}

	/**
	 * Check eligibility from a fresh database snapshot.
	 *
	 * @param array<string,mixed>|null $access Post and enabled flag from the database.
	 * @param mixed                    $link Decoded detail record.
	 * @param string[]                 $supported_types Post types supported by the current request.
	 * @param bool                     $has_identity Whether the validated response includes both name and email.
	 * @return string Existing REST error code, or an empty string when eligible.
	 */
	public static function denial( ?array $access, $link, array $supported_types, bool $has_identity ): string {
		if ( ! is_array( $link ) || empty( $access['enabled'] ) || ! self::decode( $access['enabled'] ) ) {
			return 'review_unavailable';
		}
		if ( ! in_array( $access['post_type'], $supported_types, true )
			|| 'publish' === $access['post_status']
			|| ! \previewshare_is_previewable_post_status( (string) $access['post_status'] ) ) {
			return 'review_unavailable';
		}
		if ( ! empty( $link['revoked'] ) ) {
			return 'review_revoked';
		}
		if ( self::is_expired( $link ) ) {
			return 'review_expired';
		}
		if ( empty( $link['responses_enabled'] ) ) {
			return 'review_disabled';
		}
		if ( ! empty( $link['identity_required'] ) && ! $has_identity ) {
			return 'review_identity_required';
		}
		return '';
	}

	/**
	 * Add the legacy reconciliation marker only while the checked rows still match.
	 *
	 * @param int                  $post_id Owning post ID.
	 * @param int                  $review_id Private response ID.
	 * @param string               $link_hash Link identifier.
	 * @param array<string,string> $metadata Validated response metadata.
	 * @return string Existing REST error code, or an empty string after writing the marker.
	 * @throws \RuntimeException If the marker could not be stored.
	 */
	public static function seal_legacy_response( int $post_id, int $review_id, string $link_hash, array $metadata ): string {
		global $wpdb;
		$marker = $metadata[ ReviewSubmissionStore::META_KEY ];
		if ( ! $wpdb instanceof \wpdb ) {
			if ( ! add_post_meta( $review_id, ReviewSubmissionStore::META_KEY, $marker, true ) ) {
				throw new \RuntimeException( 'Could not store the review marker.' );
			}
			return '';
		}

		$supported_types = \previewshare_get_supported_post_types();
		$has_identity    = '' !== $metadata['_previewshare_reviewer_name'] && '' !== $metadata['_previewshare_reviewer_email'];
		$sql             = self::select_sql( $wpdb->posts, $wpdb->postmeta, '%s', '%d' );
		$parameters      = self::parameters( $post_id, $link_hash );
		for ( $attempt = 0; $attempt < 2; $attempt++ ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Read live eligibility on adapters without the dedicated transaction.
			$access = $wpdb->get_row( $wpdb->prepare( $sql, $parameters ), ARRAY_A );
			if ( '' !== $wpdb->last_error ) {
				throw new \RuntimeException( 'Could not verify current review access.' );
			}
			$denial = self::denial( $access, self::decode( $access['link'] ?? null ), $supported_types, $has_identity );
			if ( '' !== $denial ) {
				return $denial;
			}

			// The private marker makes the response eligible for reconciliation.
			// Insert it and compare the authoritative snapshot in one statement.
			$insert_parameters = array_merge(
				[ $review_id, ReviewSubmissionStore::META_KEY, $marker ],
				$parameters,
				[ $access['post_type'], $access['post_status'], $access['enabled'], $access['link'], $review_id, $post_id, $review_id, ReviewSubmissionStore::META_KEY ]
			);
			$insert_sql        = "INSERT INTO {$wpdb->postmeta} (post_id, meta_key, meta_value)
				SELECT %d, %s, %s FROM ($sql) AS eligible
				WHERE HEX(eligible.post_type) = HEX(%s) AND HEX(eligible.post_status) = HEX(%s)
				AND HEX(eligible.enabled) = HEX(%s) AND HEX(eligible.link) = HEX(%s)
				AND EXISTS (SELECT 1 FROM {$wpdb->posts} WHERE ID = %d AND post_parent = %d AND post_type = 'previewshare_review' AND post_status = 'private')
				AND NOT EXISTS (SELECT 1 FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s)";
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- The complete query above is prepared with the eligibility and marker parameters together.
			$inserted = $wpdb->query( $wpdb->prepare( $insert_sql, $insert_parameters ) );
			if ( false === $inserted ) {
				throw new \RuntimeException( 'Could not store the review marker.' );
			}
			wp_cache_delete( $review_id, 'post_meta' );
			if ( 1 === $inserted || $marker === get_post_meta( $review_id, ReviewSubmissionStore::META_KEY, true ) ) {
				return '';
			}
		}
		throw new \RuntimeException( 'Review access changed during the write.' );
	}

	/**
	 * Convert a denial to the established public REST error after releasing locks.
	 *
	 * @param string $code Denial code.
	 * @return \WP_Error
	 */
	public static function error( string $code ): \WP_Error {
		[ $message, $status ] = match ( $code ) {
			'review_revoked'           => [ __( 'This preview link has been revoked.', 'previewshare' ), 410 ],
			'review_expired'           => [ __( 'This preview link has expired.', 'previewshare' ), 410 ],
			'review_disabled'          => [ __( 'Review responses are disabled for this link.', 'previewshare' ), 403 ],
			'review_identity_required' => [ __( 'Enter your name and email to respond.', 'previewshare' ), 400 ],
			default                    => [ __( 'Review responses are unavailable for this preview.', 'previewshare' ), 403 ],
		};
		return new \WP_Error( $code, $message, [ 'status' => $status ] );
	}
}
