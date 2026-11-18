<?php
/** Create disposable records for the MySQL concurrency proof. */

$state_name = isset( $args[0] ) ? (string) $args[0] : '';
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
$config    = json_decode( (string) file_get_contents( $state_dir . '/config.json' ), true );
if ( ! is_array( $config ) ) {
	fwrite( STDERR, "Concurrency proof setup configuration is missing.\n" );
	exit( 1 );
}

$parent_id = wp_insert_post(
	[
		'post_type'   => 'post',
		'post_status' => 'draft',
		'post_title'  => 'PreviewShare disposable concurrency fixture',
	],
	true
);
if ( is_wp_error( $parent_id ) || ! $parent_id ) {
	fwrite( STDERR, "Could not create the disposable concurrency parent post.\n" );
	exit( 1 );
}
$config['parent_ids'][] = (int) $parent_id;
file_put_contents( $state_dir . '/config.json', wp_json_encode( $config ), LOCK_EX );
$old_token              = 'mysql-race-old-' . bin2hex( random_bytes( 24 ) );
$new_token              = 'mysql-race-new-' . bin2hex( random_bytes( 24 ) );
$storage                = new \PreviewShare\Services\PostMetaStorage();
if ( ! $storage->store_token( (int) $parent_id, $old_token, 6, 'Initial concurrent link' ) ) {
	fwrite( STDERR, "Could not create the initial link fixture.\n" );
	exit( 1 );
}
$token_service = new \PreviewShare\Services\TokenService();
$config['generation'] = [
	'post_id'   => (int) $parent_id,
	'old_hash'  => $token_service->hash( $old_token ),
	'old_token' => $old_token,
	'new_token' => $new_token,
];
file_put_contents( $state_dir . '/config.json', wp_json_encode( $config ), LOCK_EX );

$review_marker = \PreviewShare\Services\ReviewSubmissionStore::PREFIX . hash( 'sha256', random_bytes( 32 ) );
$reservation   = ( new \PreviewShare\Services\ReviewSubmissionStore() )->reserve( $review_marker );
if ( ! $reservation ) {
	fwrite( STDERR, "Could not reserve the disposable review marker.\n" );
	exit( 1 );
}
$config['option_names'][] = $review_marker;
$config['review_reservation_id'] = $reservation;
file_put_contents( $state_dir . '/config.json', wp_json_encode( $config ), LOCK_EX );
$review_id = wp_insert_post(
	[
		'post_type'      => 'previewshare_review',
		'post_status'    => 'private',
		'post_parent'    => (int) $parent_id,
		'post_title'     => 'Disposable retention fixture',
		'post_content'   => 'Disposable test response',
		'post_date'      => gmdate( 'Y-m-d H:i:s', time() - 100 * DAY_IN_SECONDS ),
		'post_date_gmt'  => gmdate( 'Y-m-d H:i:s', time() - 100 * DAY_IN_SECONDS ),
	],
	true
);
if ( is_wp_error( $review_id ) || ! $review_id ) {
	fwrite( STDERR, "Could not create the disposable review fixture.\n" );
	exit( 1 );
}
$config['review_ids'][] = (int) $review_id;
file_put_contents( $state_dir . '/config.json', wp_json_encode( $config ), LOCK_EX );

if ( ! ( new \PreviewShare\Services\ReviewSubmissionStore() )->track_review( $review_marker, (int) $reservation, (int) $review_id ) ) {
	fwrite( STDERR, "Could not track the disposable review reservation.\n" );
	exit( 1 );
}
foreach (
	[
		'_previewshare_link_hash'      => hash( 'sha256', 'fixture-link' ),
		'_previewshare_content_hash'   => hash( 'sha256', 'fixture-content' ),
		'_previewshare_response_type'  => 'comment',
		'_previewshare_reviewer_name'  => 'Disposable reviewer',
		'_previewshare_reviewer_email' => 'mysql-race@example.test',
		\PreviewShare\Services\ReviewSubmissionStore::META_KEY => $review_marker,
	] as $key => $value
) {
	if ( ! add_post_meta( (int) $review_id, $key, $value, true ) ) {
		fwrite( STDERR, "Could not complete the disposable review fixture.\n" );
		exit( 1 );
	}
}
if ( ! ( new \PreviewShare\Services\ReviewSubmissionStore() )->commit( $review_marker, (int) $review_id, (int) $parent_id, (int) $reservation ) ) {
	fwrite( STDERR, "Could not bind the disposable review marker.\n" );
	exit( 1 );
}
$config['review'] = [ 'review_id' => (int) $review_id, 'marker' => $review_marker ];
file_put_contents( $state_dir . '/config.json', wp_json_encode( $config ), LOCK_EX );

global $wpdb;
$database_version           = (string) $wpdb->get_var( 'SELECT VERSION()' );
$config['database_version'] = $database_version;
file_put_contents( $state_dir . '/config.json', wp_json_encode( $config ), LOCK_EX );
echo 'PREVIEWSHARE_MYSQL_RUNTIME_RECEIPT=' . wp_json_encode(
	[
		'database_version' => $database_version,
		'php_version'     => PHP_VERSION,
		'wordpress'        => get_bloginfo( 'version' ),
	]
) . "\n";
