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
		return hash(
			'sha256',
			(string) wp_json_encode(
				[
					(string) $post->post_title,
					(string) $post->post_content,
					(string) $post->post_excerpt,
				]
			)
		);
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
