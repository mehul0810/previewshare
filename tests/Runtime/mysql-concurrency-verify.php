<?php
/** Assert the final generation/revocation and review cleanup race invariants. */

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
if ( ! is_array( $config ) || empty( $config['generation'] ) || empty( $config['review'] ) ) {
	fwrite( STDERR, "Concurrency proof verification configuration is incomplete.\n" );
	exit( 1 );
}
$storage = new \PreviewShare\Services\PostMetaStorage();
$links   = $storage->get_links_for_post( (int) $config['generation']['post_id'] );
$old     = $links[ $config['generation']['old_hash'] ] ?? null;
$new     = $storage->get_link_by_token( $config['generation']['new_token'] );
if ( ! is_array( $old ) || empty( $old['revoked'] ) || ! is_array( $new ) || (int) $new['post_id'] !== (int) $config['generation']['post_id'] || ! isset( $links[ $new['hash'] ] ) || ! empty( $links[ $new['hash'] ]['revoked'] ) ) {
	fwrite( STDERR, "Generation/revocation race did not leave the prior link revoked and later link active.\n" );
	exit( 1 );
}
if ( ! get_post_meta( (int) $config['generation']['post_id'], '_previewshare_enabled', true ) ) {
	fwrite( STDERR, "Generation/revocation race disabled sharing while a later active link remained.\n" );
	exit( 1 );
}
if ( get_post( (int) $config['review']['review_id'] ) ) {
	fwrite( STDERR, "Retention cleanup did not delete the expired review fixture.\n" );
	exit( 1 );
}
global $wpdb;
$marker = $wpdb->get_var( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", $config['review']['marker'] ) );
if ( null !== $marker || '' !== (string) $wpdb->last_error ) {
	fwrite( STDERR, "Review deletion and orphan cleanup left its idempotency marker behind or failed to query it.\n" );
	exit( 1 );
}
$meta_keys = [ '_previewshare_link_hash', '_previewshare_content_hash', '_previewshare_response_type', '_previewshare_reviewer_name', '_previewshare_reviewer_email', \PreviewShare\Services\ReviewSubmissionStore::META_KEY ];
$in        = implode( ', ', array_fill( 0, count( $meta_keys ), '%s' ) );
// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- One placeholder is supplied for each fixed key.
$meta_count = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key IN ($in)", array_merge( [ (int) $config['review']['review_id'] ], $meta_keys ) ) );
if ( null === $meta_count || false === $meta_count || '' !== (string) $wpdb->last_error ) {
	fwrite( STDERR, "Review response metadata could not be checked after deletion.\n" );
	exit( 1 );
}
if ( 0 !== (int) $meta_count ) {
	fwrite( STDERR, "Review deletion left response metadata behind.\n" );
	exit( 1 );
}
echo "Generation/revocation, authoritative inventory, and review-delete cleanup invariants passed.\n";
