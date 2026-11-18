<?php
/**
 * One independent WordPress process for the MySQL concurrency proof.
 *
 * @package PreviewShare
 */

$state_name = isset( $args[1] ) ? (string) $args[1] : '';
if ( 1 !== preg_match( '/^\.mysql-concurrency-([a-f0-9]{24})$/D', $state_name, $state_match ) ) {
	fwrite( STDERR, "Invalid concurrency state directory name.\n" );
	exit( 1 );
}
$state_dir = dirname( __FILE__ ) . '/' . $state_name;
$real_parent = realpath( dirname( __FILE__ ) );
if ( ! is_dir( $state_dir ) || false === $real_parent || realpath( $state_dir ) !== $real_parent . '/' . $state_name || trim( (string) @file_get_contents( $state_dir . '/.previewshare-owned' ) ) !== $state_match[1] ) {
	fwrite( STDERR, "Concurrency state directory ownership check failed.\n" );
	exit( 1 );
}
$worker    = isset( $args[0] ) ? (string) $args[0] : '';
if ( ! in_array( $worker, [ 'a', 'b', 'generate', 'revoke', 'review-delete', 'review-cleanup' ], true ) ) {
	fwrite( STDERR, "Unknown MySQL concurrency worker.\n" );
	exit( 1 );
}
$config = json_decode( (string) file_get_contents( $state_dir . '/config.json' ), true );
if ( ! is_array( $config ) ) {
	fwrite( STDERR, "Concurrency proof configuration is missing.\n" );
	exit( 1 );
}

$wait_for = static function ( string $path ): bool {
	$deadline = microtime( true ) + 30;
	while ( ! is_file( $path ) ) {
		if ( microtime( true ) >= $deadline ) {
			return false;
		}
		usleep( 10000 );
	}
	return true;
};

if ( 'a' === $worker || 'b' === $worker ) {
	$slot = $worker;
	if ( empty( $config['option_name'] ) ) {
		fwrite( STDERR, "Reservation option name is missing.\n" );
		exit( 1 );
	}
	global $wpdb;
	$connection_id = (int) $wpdb->get_var( 'SELECT CONNECTION_ID()' );
	if ( $connection_id <= 0 ) {
		fwrite( STDERR, "Could not identify the MySQL session.\n" );
		exit( 1 );
	}
	if ( 'a' === $slot ) {
		$option_name = $config['option_name'];
		add_filter(
			'query',
			static function ( $query ) use ( $option_name, $state_dir, $wait_for, $wpdb ) {
				if ( false !== strpos( $query, "UPDATE {$wpdb->options} SET option_value = '0'" ) && false !== strpos( $query, $option_name ) ) {
					file_put_contents( $state_dir . '/insert-paused', 'inserted-before-claim', LOCK_EX );
					if ( ! $wait_for( $state_dir . '/insert-continue' ) ) {
						throw new \RuntimeException( 'Timed out waiting to resume the first reservation claim.' );
					}
				}
				return $query;
			}
		);
	}
	$wpdb->last_error = '';
	$inserted         = ( new \PreviewShare\Services\ReviewSubmissionStore() )->reserve( $config['option_name'] );
	if ( '' !== (string) $wpdb->last_error || ( null !== $inserted && ! is_int( $inserted ) ) || ( is_int( $inserted ) && $inserted < 0 ) ) {
		fwrite( STDERR, "Unexpected reservation result or database error.\n" );
		exit( 1 );
	}
	$result = [ 'inserted' => $inserted, 'connection_id' => $connection_id ];
	if ( 'b' === $slot && is_int( $inserted ) && $inserted > 0 ) {
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT option_id, option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", $config['option_name'] ), ARRAY_A );
		if ( ! is_array( $row ) ) {
			fwrite( STDERR, "Could not read the claimed reservation row.\n" );
			exit( 1 );
		}
		$result['row_id']    = (int) $row['option_id'];
		$result['row_phase'] = (string) $row['option_value'];
	}
	file_put_contents( $state_dir . '/result-' . $slot, wp_json_encode( $result ), LOCK_EX );
	return;
}

if ( 'generate' === $worker ) {
	$storage = new \PreviewShare\Services\PostMetaStorage();
	$result  = $storage->store_token( (int) $config['generation']['post_id'], $config['generation']['new_token'], 6, 'Concurrent generation' );
	file_put_contents( $state_dir . '/result-generate', wp_json_encode( [ 'stored' => $result ] ), LOCK_EX );
	return;
}

if ( 'revoke' === $worker ) {
	$target_key = '_previewshare_token:' . $config['generation']['old_hash'];
	add_filter(
		'query',
		static function ( $query ) use ( $target_key, $state_dir, $wait_for ) {
			if ( false !== strpos( $query, 'UPDATE ' . $GLOBALS['wpdb']->postmeta . ' SET meta_value' ) && false !== strpos( $query, $target_key ) ) {
				file_put_contents( $state_dir . '/revoke-paused', 'snapshot-read', LOCK_EX );
				if ( ! $wait_for( $state_dir . '/revoke-continue' ) ) {
					throw new \RuntimeException( 'Timed out waiting to release the revocation write.' );
				}
			}
			return $query;
		}
	);
	$result = ( new \PreviewShare\Services\PostMetaStorage() )->revoke_token_for_post( (int) $config['generation']['post_id'] );
	file_put_contents( $state_dir . '/result-revoke', wp_json_encode( [ 'revoked' => $result ] ), LOCK_EX );
	return;
}

if ( 'review-delete' === $worker ) {
	$review_id = (int) $config['review']['review_id'];
	add_action(
		'before_delete_post',
		static function ( $post_id ) use ( $review_id, $state_dir, $wait_for ) {
			if ( $review_id === (int) $post_id ) {
				file_put_contents( $state_dir . '/delete-paused', 'marker-bound', LOCK_EX );
				if ( ! $wait_for( $state_dir . '/delete-continue' ) ) {
					throw new \RuntimeException( 'Timed out waiting to release review deletion.' );
				}
			}
		},
		10,
		1
	);
	( new \PreviewShare\Services\ReviewResponseService() )->purge_expired();
	file_put_contents( $state_dir . '/result-review-delete', 'done', LOCK_EX );
	return;
}

$result = ( new \PreviewShare\Services\ReviewSubmissionStore() )->purge_orphans( 100, 2000 );
global $wpdb;
$review_id = (int) $config['review']['review_id'];
$marker    = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", $config['review']['marker'] ) );
$meta_keys = [ '_previewshare_link_hash', '_previewshare_content_hash', '_previewshare_response_type', '_previewshare_reviewer_name', '_previewshare_reviewer_email', \PreviewShare\Services\ReviewSubmissionStore::META_KEY ];
$in        = implode( ', ', array_fill( 0, count( $meta_keys ), '%s' ) );
// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- One placeholder is supplied for each fixed key.
$meta_count = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key IN ($in)", array_merge( [ $review_id ], $meta_keys ) ) );
if ( null === $meta_count || false === $meta_count || '' !== (string) $wpdb->last_error ) {
	fwrite( STDERR, "Could not verify response metadata during the concurrent orphan scan.\n" );
	exit( 1 );
}
file_put_contents(
	$state_dir . '/result-review-cleanup',
	wp_json_encode(
		[
			'result'        => $result,
			'review_exists' => (bool) get_post( $review_id ),
			'marker_value'  => null === $marker ? null : (string) $marker,
			'meta_count'    => (int) $meta_count,
		]
	),
	LOCK_EX
);
