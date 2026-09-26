<?php
/**
 * Content version and reviewer-state helpers.
 *
 * @package PreviewShare
 */

namespace PreviewShare\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Keeps approval freshness tied to the saved content a reviewer saw.
 */
final class ReviewVersion {

	/**
	 * Fingerprint the saved fields that appear in a preview.
	 *
	 * @param \WP_Post $post Reviewed post.
	 * @return string
	 */
	public static function fingerprint( \WP_Post $post ): string {
		$metadata = get_post_meta( (int) $post->ID );
		$metadata = is_array( $metadata ) ? $metadata : [];
		foreach ( array_keys( $metadata ) as $key ) {
			if ( 0 === strpos( (string) $key, '_previewshare_' ) || in_array( $key, [ '_edit_lock', '_edit_last' ], true ) ) {
				unset( $metadata[ $key ] );
			}
		}
		ksort( $metadata, SORT_STRING );

		$taxonomies = get_object_taxonomies( (string) $post->post_type, 'names' );
		$taxonomies = is_array( $taxonomies ) ? array_values( $taxonomies ) : [];
		sort( $taxonomies, SORT_STRING );
		$terms = [];
		if ( $taxonomies ) {
			foreach ( $taxonomies as $taxonomy ) {
				$assigned = get_object_term_cache( (int) $post->ID, $taxonomy );
				if ( false === $assigned ) {
					$assigned = wp_get_object_terms( (int) $post->ID, $taxonomy );
				}
				if ( ! is_array( $assigned ) ) {
					continue;
				}
				foreach ( $assigned as $term ) {
					$terms[] = [
						'id'          => (int) $term->term_id,
						'taxonomy'    => (string) $term->taxonomy,
						'slug'        => (string) $term->slug,
						'name'        => (string) $term->name,
						'description' => (string) $term->description,
						'parent'      => (int) $term->parent,
					];
				}
			}
			usort(
				$terms,
				static function ( array $left, array $right ): int {
					return strcmp( $left['taxonomy'] . ':' . $left['id'], $right['taxonomy'] . ':' . $right['id'] );
				}
			);
		}

		$thumbnail_id = isset( $metadata['_thumbnail_id'][0] ) ? (int) $metadata['_thumbnail_id'][0] : 0;
		$thumbnail    = $thumbnail_id ? get_post( $thumbnail_id ) : null;
		$image        = $thumbnail ? [
			'post_modified_gmt' => (string) $thumbnail->post_modified_gmt,
			'post_title'        => (string) $thumbnail->post_title,
			'post_excerpt'      => (string) $thumbnail->post_excerpt,
			'metadata'          => get_post_meta( $thumbnail_id ),
		] : null;

		return hash(
			'sha256',
			(string) wp_json_encode(
				[
					(string) $post->post_title,
					(string) $post->post_content,
					(string) $post->post_excerpt,
					$metadata,
					$terms,
					$image,
				]
			)
		);
	}

	/**
	 * Issue a link-bound signature for the content version rendered to a reviewer.
	 *
	 * @param \WP_Post $post Reviewed post.
	 * @param string   $link_hash HMAC link identifier.
	 * @return string
	 */
	public static function issue_snapshot( \WP_Post $post, string $link_hash ): string {
		$fingerprint = self::fingerprint( $post );
		$signature   = hash_hmac( 'sha256', $link_hash . ':' . $fingerprint, wp_salt( 'auth' ) );
		return $fingerprint . '.' . $signature;
	}

	/**
	 * Verify a snapshot issued by this site for this preview link.
	 *
	 * @param string $snapshot Signed content fingerprint from the form.
	 * @param string $link_hash HMAC link identifier.
	 * @return string|null Fingerprint when valid, otherwise null.
	 */
	public static function verify_snapshot( string $snapshot, string $link_hash ): ?string {
		if ( ! preg_match( '/^([a-f0-9]{64})\.([a-f0-9]{64})$/', $snapshot, $matches ) ) {
			return null;
		}

		$expected = hash_hmac( 'sha256', $link_hash . ':' . $matches[1], wp_salt( 'auth' ) );
		return hash_equals( $expected, $matches[2] ) ? $matches[1] : null;
	}

	/**
	 * Derive the current editorial state from the latest response.
	 *
	 * @param array<string,mixed>|null $response Latest response, if present.
	 * @param string                   $current_fingerprint Current saved-content fingerprint.
	 * @return string Pending, approved, stale, changes_requested, or commented.
	 */
	public static function state( ?array $response, string $current_fingerprint ): string {
		if ( null === $response ) {
			return 'pending';
		}

		$type = isset( $response['response_type'] ) ? (string) $response['response_type'] : '';

		if ( 'approve' === $type ) {
			return hash_equals( (string) ( $response['content_hash'] ?? '' ), $current_fingerprint ) ? 'approved' : 'stale';
		}

		if ( 'request_changes' === $type ) {
			return empty( $response['resolved_at'] ) ? 'changes_requested' : 'pending';
		}

		return 'comment' === $type ? 'commented' : 'pending';
	}
}
