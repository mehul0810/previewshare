<?php
/**
 * Exercise the fenced reviewer write in an isolated CLI WordPress fixture.
 *
 * @package PreviewShare
 */

use PreviewShare\Services\ReviewResponseService;
use PreviewShare\Services\ReviewSubmissionStore;

if ( ! defined( 'ABSPATH' ) || PHP_SAPI !== 'cli' || wp_get_environment_type() !== 'local' || ( $args[0] ?? '' ) !== '--disposable-fixture' ) {
	throw new RuntimeException( 'Run only in disposable local WordPress.' );
}

$mode = $args[1] ?? '';
$id   = (int) ( $args[2] ?? 0 );
$key  = (string) ( $args[3] ?? '' );
$link = str_repeat( 'a', 64 );

if ( 'seed' === $mode ) {
	$parent = wp_insert_post( [ 'post_type' => 'post', 'post_status' => 'draft', 'post_title' => 'Synthetic transaction proof' ], true );
	if ( ! is_int( $parent ) || $parent <= 0 ) {
		throw new RuntimeException( 'Could not seed the transaction proof.' );
	}
	echo $parent, PHP_EOL;
	return;
}
if ( $id < 1 || ! preg_match( '/^[a-f0-9]{64}$/D', $key ) ) {
	throw new RuntimeException( 'Expected a synthetic parent ID and request key.' );
}

$marker  = ReviewSubmissionStore::PREFIX . $key;
$email   = 'synthetic-' . substr( $key, 0, 12 ) . '@example.test';
$service = new ReviewResponseService();
$check   = static function ( bool $passed, string $failure ): void {
	if ( ! $passed ) {
		throw new RuntimeException( $failure );
	}
};

if ( 'legacy-zero' === $mode ) {
	$check( add_option( $marker, '0', '', false ), 'Could not seed an ambiguous legacy reservation.' );
	$result = $service->create_response( $id, $link, str_repeat( 'b', 64 ), 'comment', 'Synthetic reviewer', $email, 'Synthetic feedback', $key );
	$check( is_wp_error( $result ) && 'review_unconfirmed' === $result->get_error_code() && '0' === get_option( $marker ), 'An ambiguous legacy reservation was changed or accepted.' );
	echo "legacy reservation preserved\n";
	return;
}

if ( 'write' === $mode || 'exit-after-commit' === $mode ) {
	$generic = static function (): void {
		throw new RuntimeException( 'Unexpected generic WordPress review save hook.' );
	};
	add_action( 'save_post_previewshare_review', $generic );
	add_filter( 'add_post_metadata', $generic );
	$calls = 0;
	add_action(
		'previewshare_review_response_accepted',
		static function ( int $review_id, int $parent, string $hash, string $kind ) use ( &$calls, $check, $email, $id, $link, $marker, $mode ): void {
			++$calls;
			$check( $parent === $id && $hash === $link && 'comment' === $kind, 'Accepted hook arguments changed.' );
			$check( (string) $review_id === get_option( $marker ), 'Accepted hook did not see the committed receipt.' );
			$check( (int) get_post_field( 'post_parent', $review_id ) === $parent && $email === get_post_meta( $review_id, '_previewshare_reviewer_email', true ), 'Accepted hook did not see the committed review and email.' );
			if ( 'exit-after-commit' === $mode ) {
				exit( 75 );
			}
		},
		10,
		4
	);
	$result = $service->create_response( $id, $link, str_repeat( 'b', 64 ), 'comment', 'Synthetic reviewer', $email, 'Synthetic feedback', $key );
	$check( is_int( $result ) ? 1 === $calls : 0 === $calls, 'Accepted hook fired more than once or before commit.' );
	echo wp_json_encode( [ 'result' => is_wp_error( $result ) ? $result->get_error_code() : 'accepted', 'accepted_hooks' => $calls ] ), PHP_EOL;
	return;
}

if ( 'verify' === $mode || 'erase' === $mode ) {
	$review_id = (int) get_option( $marker );
	$check( $review_id > 0 && ( new ReviewSubmissionStore() )->has_response( $marker, $id, $link ), 'The persisted request was not confirmed.' );
	$export = $service->export_by_email( $email );
	$check( ! is_wp_error( $export ) && 1 === count( $export['data'] ) && 'previewshare-review-' . $review_id === $export['data'][0]['item_id'], 'Expected exactly one private export record.' );
	if ( 'erase' === $mode ) {
		$erased = $service->erase_by_email( $email );
		$check( ! is_wp_error( $erased ) && $erased['items_removed'] && ! get_post( $review_id ) && false === get_option( $marker ), 'Privacy erasure did not remove the review and receipt.' );
	}
	echo "verified\n";
	return;
}

if ( 'absent' === $mode ) {
	$check( false === get_option( $marker ), 'A rolled back request left a receipt.' );
	$export = $service->export_by_email( $email );
	$check( ! is_wp_error( $export ) && [] === $export['data'], 'A rolled back request left private reviewer data.' );
	global $wpdb;
	$reviews = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_parent = %d AND post_type = 'previewshare_review'", $id ) );
	$orphan  = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = '_previewshare_reviewer_email' AND meta_value = %s", $email ) );
	$check( (string) $reviews === '0' && (string) $orphan === '0', 'A rolled back request left a partial review or orphan email.' );
	echo "absent\n";
	return;
}

throw new RuntimeException( 'Unknown synthetic transaction mode.' );
