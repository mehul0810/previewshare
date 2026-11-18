<?php
/**
 * Atomic persistence for new reviewer submissions.
 *
 * @package PreviewShare
 */

namespace PreviewShare\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Writes the established private post, metadata and numeric receipt together.
 *
 * @internal
 */
final class ReviewWriteStore {
	/**
	 * Save a validated response using a dedicated database connection.
	 *
	 * @param string               $option_name Request receipt key.
	 * @param int                  $post_id Owning post ID.
	 * @param string               $comment Plain-text response.
	 * @param array<string,string> $metadata Required response metadata.
	 * @return int|string|null New review ID, zero for an existing request, a denial code, or null on an unsupported backend.
	 * @throws \RuntimeException If a transaction cannot be confirmed.
	 */
	public function save( string $option_name, int $post_id, string $comment, array $metadata ): int|string|null {
		global $wpdb;
		if ( ! $wpdb instanceof \wpdb ) {
			return null;
		}

		$tables     = [ $wpdb->options, $wpdb->posts, $wpdb->postmeta ];
		$connection = ReviewWriteConnection::open( $wpdb, $tables );
		if ( null === $connection ) {
			return null;
		}

		try {
			// Evaluate WordPress functions before acquiring any database locks.
			$gmt                            = current_time( 'mysql', true );
			$local                          = get_date_from_gmt( $gmt );
			$title                          = __( 'PreviewShare review response', 'previewshare' );
			$guid_base                      = home_url( '/?post_type=previewshare_review&p=' );
			$author                         = get_current_user_id();
			$content                        = wp_unslash( $comment );
			$supported_types                = \previewshare_get_supported_post_types();
			$has_identity                   = '' !== $metadata['_previewshare_reviewer_name'] && '' !== $metadata['_previewshare_reviewer_email'];
			$values                         = array_map(
				static fn( string $value ): string => (string) maybe_serialize( wp_unslash( $value ) ),
				$metadata
			);
			[ $options, $posts, $postmeta ] = array_map( [ $connection, 'quote_identifier' ], $tables );

			$connection->begin();
			$mysql  = $connection->is_mysql();
			$insert = $mysql
				? "INSERT INTO $options (option_name, option_value, autoload) VALUES (?, 'ready', 'no') ON DUPLICATE KEY UPDATE option_id = option_id"
				: "INSERT INTO $options (option_name, option_value, autoload) VALUES (?, 'ready', 'no') ON CONFLICT(option_name) DO NOTHING";
			$connection->execute( $insert, [ $option_name ] );
			$lock    = $mysql ? ' FOR UPDATE' : '';
			$request = $connection->get_row( "SELECT option_id, option_value FROM $options WHERE option_name = ?" . $lock, [ $option_name ] );
			if ( ! isset( $request['option_id'], $request['option_value'] ) ) {
				throw new \RuntimeException( 'The review request could not be locked.' );
			}
			if ( 'ready' !== $request['option_value'] ) {
				// The legacy writer still owns ambiguous phases. Confirm any
				// existing response through ordinary reconciliation after closing.
				return 0;
			}
			$access_sql = ReviewSubmissionAccess::select_sql( $posts, $postmeta, '?', '?' );
			// Shared locks protect the existing post and metadata rows from edits
			// while allowing reviewers with distinct request keys to proceed.
			$access = $connection->get_row(
				$access_sql . ( $mysql ? ' LOCK IN SHARE MODE' : '' ),
				ReviewSubmissionAccess::parameters( $post_id, $metadata['_previewshare_link_hash'] )
			);
			$link   = ReviewSubmissionAccess::decode( $access['link'] ?? null );
			$denial = ReviewSubmissionAccess::denial( $access, $link, $supported_types, $has_identity );
			if ( '' !== $denial ) {
				return $denial;
			}

			$inserted  = $connection->execute(
				"INSERT INTO $posts (post_author, post_date, post_date_gmt, post_content, post_title, post_excerpt,
				post_status, comment_status, ping_status, post_password, post_name, to_ping, pinged, post_modified,
				post_modified_gmt, post_content_filtered, post_parent, guid, menu_order, post_type, post_mime_type, comment_count)
				VALUES (?, ?, ?, ?, ?, '', 'private', 'closed', 'closed', '', '', '', '', ?, ?, '', ?, '', 0, 'previewshare_review', '', 0)",
				[ $author, $local, $gmt, $content, $title, $local, $gmt, $post_id ]
			);
			$review_id = $connection->insert_id();
			if ( 1 !== $inserted || $review_id < 1 ) {
				throw new \RuntimeException( 'The review could not be inserted.' );
			}
			if ( 1 !== $connection->execute( "UPDATE $posts SET post_name = ?, guid = ? WHERE ID = ?", [ 'previewshare-review-' . $review_id, $guid_base . $review_id, $review_id ] ) ) {
				throw new \RuntimeException( 'The review identifiers could not be saved.' );
			}

			$parameters = [];
			foreach ( $values as $key => $value ) {
				array_push( $parameters, $review_id, $key, $value );
			}
			$placeholders = implode( ', ', array_fill( 0, count( $values ), '(?, ?, ?)' ) );
			if ( count( $values ) !== $connection->execute( "INSERT INTO $postmeta (post_id, meta_key, meta_value) VALUES $placeholders", $parameters ) ) {
				throw new \RuntimeException( 'The review metadata could not be inserted.' );
			}
			if ( 1 !== $connection->execute( "UPDATE $options SET option_value = ? WHERE option_id = ? AND option_name = ? AND option_value = 'ready'", [ (string) $review_id, (int) $request['option_id'], $option_name ] ) ) {
				throw new \RuntimeException( 'The review receipt could not be recorded.' );
			}
			if ( is_array( $link ) && ReviewSubmissionAccess::is_expired( $link ) ) {
				return 'review_expired';
			}
			$connection->commit();
			return $review_id;
		} finally {
			// Closing discards any uncommitted work. A lost commit response
			// must never fall back to the legacy writer on this request.
			$connection->close();
		}
	}
}
