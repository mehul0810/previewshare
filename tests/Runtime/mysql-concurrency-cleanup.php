<?php
/** Remove only reservation rows created by the disposable concurrency proof. */

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
	fwrite( STDERR, "Concurrency proof cleanup configuration is missing.\n" );
	exit( 1 );
}
global $wpdb;
foreach ( $config['review_ids'] ?? [] as $review_id ) {
	if ( get_post( (int) $review_id ) && ! wp_delete_post( (int) $review_id, true ) ) {
		fwrite( STDERR, "Could not delete a disposable review record.\n" );
		exit( 1 );
	}
}
foreach ( $config['parent_ids'] ?? [] as $parent_id ) {
	if ( get_post( (int) $parent_id ) && ! wp_delete_post( (int) $parent_id, true ) ) {
		fwrite( STDERR, "Could not delete a disposable parent post.\n" );
		exit( 1 );
	}
}
foreach ( $config['option_names'] ?? [] as $option_name ) {
	$deleted = $wpdb->delete( $wpdb->options, [ 'option_name' => $option_name ], [ '%s' ] );
	if ( false === $deleted ) {
		fwrite( STDERR, "Could not clean a concurrency proof option row.\n" );
		exit( 1 );
	}
	wp_cache_delete( $option_name, 'options' );
}
