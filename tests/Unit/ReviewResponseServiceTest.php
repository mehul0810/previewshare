<?php
/**
 * Review response persistence tests.
 *
 * @package PreviewShare
 */

namespace PreviewShare\Tests\Unit;

use Brain\Monkey\Functions;
use PreviewShare\Services\ReviewResponseService;
use WP_Error;

class ReviewResponseServiceTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Functions\when( '__' )->returnArg( 1 );
		Functions\when( 'is_wp_error' )->alias(
			static function ( $value ): bool {
				return $value instanceof WP_Error;
			}
		);
	}

	public function test_duplicate_request_does_not_create_a_record(): void {
		Functions\expect( 'add_option' )->once()->andReturn( false );
		Functions\expect( 'wp_insert_post' )->never();

		$result = ( new ReviewResponseService() )->create_response( 42, 'link', 'version', 'approve', '', '', '', 'request' );

		self::assertInstanceOf( WP_Error::class, $result );
		self::assertSame( 'review_duplicate', $result->get_error_code() );
		self::assertSame( [ 'status' => 409 ], $result->get_error_data() );
	}

	public function test_response_is_stored_as_a_private_child_post(): void {
		Functions\expect( 'add_option' )->once()->andReturn( true );
		Functions\expect( 'current_time' )->once()->with( 'mysql', true )->andReturn( '2026-09-25 18:00:00' );
		Functions\expect( 'get_date_from_gmt' )->once()->with( '2026-09-25 18:00:00' )->andReturn( '2026-09-25 23:30:00' );
		Functions\expect( 'wp_insert_post' )
			->once()
			->andReturnUsing(
				static function ( array $data, bool $return_error ): int {
					self::assertTrue( $return_error );
					self::assertSame( 'previewshare_review', $data['post_type'] );
					self::assertSame( 'private', $data['post_status'] );
					self::assertSame( '2026-09-25 23:30:00', $data['post_date'] );
					self::assertSame( '2026-09-25 18:00:00', $data['post_date_gmt'] );
					self::assertSame( 42, $data['post_parent'] );
					self::assertSame( 'Please revise.', $data['post_content'] );
					return 77;
				}
			);
		Functions\expect( 'add_post_meta' )->times( 6 )->andReturn( true );
		Functions\expect( 'update_option' )->once()->andReturn( true );
		Functions\expect( 'do_action' )->once();

		$result = ( new ReviewResponseService() )->create_response(
			42,
			'link',
			'version',
			'request_changes',
			'Reviewer',
			'reviewer@example.test',
			'Please revise.',
			'request'
		);

		self::assertSame( 77, $result );
	}

	public function test_storage_failure_releases_the_request_marker(): void {
		Functions\expect( 'add_option' )->once()->andReturn( true );
		Functions\expect( 'current_time' )->once()->with( 'mysql', true )->andReturn( '2026-09-25 18:00:00' );
		Functions\expect( 'get_date_from_gmt' )->once()->with( '2026-09-25 18:00:00' )->andReturn( '2026-09-25 23:30:00' );
		Functions\expect( 'wp_insert_post' )->once()->andReturn( new WP_Error( 'db_error' ) );
		Functions\expect( 'delete_option' )->once()->andReturn( true );

		$result = ( new ReviewResponseService() )->create_response( 42, 'link', 'version', 'approve', '', '', '', 'request' );

		self::assertInstanceOf( WP_Error::class, $result );
		self::assertSame( 'review_storage_failed', $result->get_error_code() );
	}

	public function test_inventory_loads_latest_responses_in_one_bounded_query(): void {
		$database = new class() {
			public $posts = 'wp_posts';
			public $postmeta = 'wp_postmeta';
			public $last_error = '';
			public $queries = 0;
			public $arguments = [];

			public function prepare( string $sql, array $arguments ): string {
				$this->arguments = $arguments;
				return $sql;
			}

			public function get_results( string $sql, string $output ): array {
				++$this->queries;
				\PHPUnit\Framework\Assert::assertStringContainsString( 'MAX(reviews.ID)', $sql );
				\PHPUnit\Framework\Assert::assertSame( ARRAY_A, $output );
				return [
					[ 'link_hash' => 'first', 'response_id' => '71', 'post_id' => '42' ],
					[ 'link_hash' => 'second', 'response_id' => '72', 'post_id' => '43' ],
				];
			}
		};
		$previous = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['wpdb'] = $database;
		Functions\expect( 'update_meta_cache' )->once()->with( 'post', [ 71, 72 ] );
		Functions\when( 'get_post_meta' )->alias(
			static function ( int $id, string $key ) {
				$values = [
					71 => [ '_previewshare_response_type' => 'approve', '_previewshare_content_hash' => 'version-a' ],
					72 => [ '_previewshare_response_type' => 'request_changes', '_previewshare_content_hash' => 'version-b' ],
				];
				return $values[ $id ][ $key ] ?? '';
			}
		);

		try {
			$result = ( new ReviewResponseService() )->latest_for_links( [ 'first' => 42, 'second' => 43 ] );
		} finally {
			$GLOBALS['wpdb'] = $previous;
		}

		self::assertSame( 1, $database->queries );
		self::assertSame( [ 'previewshare_review', 'private', '_previewshare_link_hash', 42, 43, 'first', 'second' ], $database->arguments );
		self::assertSame( 'approve', $result['first']['response_type'] );
		self::assertSame( 'request_changes', $result['second']['response_type'] );
	}

	public function test_cleanup_continues_a_bounded_backlog_and_preserves_younger_records(): void {
		$queries = 0;
		$cutoff_timestamp = time() - ( 90 * DAY_IN_SECONDS );
		$cutoff           = gmdate( 'Y-m-d H:i:s', $cutoff_timestamp );
		$records          = [];
		for ( $id = 1; $id <= 2201; $id++ ) {
			$records[ $id ] = gmdate( 'Y-m-d H:i:s', $cutoff_timestamp - 60 );
		}
		$records[2202] = gmdate( 'Y-m-d H:i:s', $cutoff_timestamp + 60 );
		Functions\when( 'get_posts' )->alias(
			static function ( array $args ) use ( &$queries, &$records ): array {
				++$queries;
				self::assertSame( 100, $args['posts_per_page'] );
				self::assertSame( 'post_date_gmt', $args['date_query'][0]['column'] );
				$expected_cutoff = gmdate( 'Y-m-d H:i:s', time() - ( 90 * DAY_IN_SECONDS ) );
				self::assertLessThanOrEqual( 1, abs( strtotime( $expected_cutoff ) - strtotime( $args['date_query'][0]['before'] ) ) );
				$eligible = [];
				foreach ( $records as $id => $created_gmt ) {
					if ( $created_gmt < $args['date_query'][0]['before'] ) {
						$eligible[] = $id;
					}
				}
				sort( $eligible );
				return array_slice( $eligible, 0, $args['posts_per_page'] );
			}
		);
		Functions\when( 'get_post_meta' )->justReturn( '' );
		Functions\when( 'wp_delete_post' )->alias(
			static function ( int $id ) use ( &$records ): bool {
				unset( $records[ $id ] );
				return true;
			}
		);
		Functions\expect( 'delete_option' )->never();
		Functions\expect( 'wp_next_scheduled' )->once()->with( 'previewshare_cleanup_reviews_continue' )->andReturn( false );
		Functions\expect( 'wp_schedule_single_event' )->once()->andReturnUsing(
			static function ( int $timestamp, string $hook ): bool {
				self::assertSame( 'previewshare_cleanup_reviews_continue', $hook );
				self::assertGreaterThanOrEqual( time() + 299, $timestamp );
				self::assertLessThanOrEqual( time() + 300, $timestamp );
				return true;
			}
		);
		Functions\expect( 'wp_clear_scheduled_hook' )->once()->with( 'previewshare_cleanup_reviews_continue' )->andReturn( 1 );

		$service = new ReviewResponseService();
		$service->purge_expired();
		self::assertCount( 202, $records );
		$service->purge_expired();

		self::assertSame( 23, $queries );
		self::assertSame( [ 2202 ], array_keys( $records ) );
	}

	public function test_cleanup_schedule_is_deduplicated_and_deactivation_clears_both_events(): void {
		$cleared_hooks = [];
		$schedule_checks = 0;
		Functions\when( 'wp_next_scheduled' )->alias(
			static function ( string $hook ) use ( &$schedule_checks ): bool {
				self::assertSame( 'previewshare_cleanup_reviews', $hook );
				return 0 < $schedule_checks++;
			}
		);
		Functions\expect( 'wp_schedule_event' )->once()->andReturnUsing(
			static function ( int $timestamp, string $recurrence, string $hook ): bool {
				self::assertSame( 'daily', $recurrence );
				self::assertSame( 'previewshare_cleanup_reviews', $hook );
				self::assertGreaterThanOrEqual( time() + 3599, $timestamp );
				self::assertLessThanOrEqual( time() + 3600, $timestamp );
				return true;
			}
		);
		Functions\when( 'wp_clear_scheduled_hook' )->alias(
			static function ( string $hook ) use ( &$cleared_hooks ): int {
				$cleared_hooks[] = $hook;
				return 1;
			}
		);

		$service = new ReviewResponseService();
		$service->schedule_cleanup();
		$service->schedule_cleanup();
		ReviewResponseService::unschedule_cleanup();
		self::assertSame( 2, $schedule_checks );
		self::assertSame( [ 'previewshare_cleanup_reviews', 'previewshare_cleanup_reviews_continue' ], $cleared_hooks );
	}

	public function test_cleanup_does_not_schedule_a_duplicate_continuation(): void {
		$query = 0;
		Functions\when( 'get_posts' )->alias(
			static function () use ( &$query ): array {
				++$query;
				return range( 1, 100 );
			}
		);
		Functions\when( 'get_post_meta' )->justReturn( '' );
		Functions\when( 'wp_delete_post' )->justReturn( true );
		Functions\expect( 'wp_next_scheduled' )->once()->with( 'previewshare_cleanup_reviews_continue' )->andReturn( true );
		Functions\expect( 'wp_schedule_single_event' )->never();
		Functions\expect( 'wp_clear_scheduled_hook' )->never();

		( new ReviewResponseService() )->purge_expired();

		self::assertSame( 20, $query );
	}

	public function test_continuation_hook_is_registered_to_drain_expired_reviews(): void {
		$registered_actions = [];
		Functions\when( 'add_action' )->alias(
			static function ( string $hook ) use ( &$registered_actions ): void {
				$registered_actions[] = $hook;
			}
		);
		Functions\when( 'add_filter' )->justReturn( [] );

		( new ReviewResponseService() )->register();

		self::assertContains( 'previewshare_cleanup_reviews_continue', $registered_actions );
	}

	public function test_cleanup_keeps_submission_marker_when_deletion_fails_and_uses_slow_retry(): void {
		$queries = 0;
		Functions\when( 'get_posts' )->alias(
			static function () use ( &$queries ): array {
				++$queries;
				return [ 1 ];
			}
		);
		Functions\when( 'get_post_meta' )->justReturn( 'previewshare_review_request_key' );
		Functions\when( 'wp_delete_post' )->justReturn( false );
		Functions\expect( 'delete_option' )->never();
		Functions\expect( 'wp_next_scheduled' )->once()->with( 'previewshare_cleanup_reviews_continue' )->andReturn( false );
		Functions\expect( 'wp_schedule_single_event' )->once()->andReturnUsing(
			static function ( int $timestamp, string $hook ): bool {
				self::assertSame( 'previewshare_cleanup_reviews_continue', $hook );
				self::assertGreaterThanOrEqual( time() + ( HOUR_IN_SECONDS - 1 ), $timestamp );
				self::assertLessThanOrEqual( time() + HOUR_IN_SECONDS, $timestamp );
				return true;
			}
		);

		( new ReviewResponseService() )->purge_expired();

		self::assertSame( 1, $queries );
	}

	public function test_privacy_eraser_reports_failed_deletion_as_retained_and_incomplete(): void {
		Functions\when( 'sanitize_email' )->returnArg( 1 );
		Functions\when( 'get_posts' )->alias(
			static function ( array $args ): array {
				self::assertSame( '_previewshare_reviewer_email', $args['meta_key'] );
				self::assertSame( 'reviewer@example.test', $args['meta_value'] );
				self::assertSame( 1, $args['paged'] );
				return [ new \WP_Post( [ 'ID' => 91 ] ) ];
			}
		);
		Functions\when( 'get_post_meta' )->justReturn( 'previewshare_review_request_91' );
		Functions\expect( 'wp_delete_post' )->once()->with( 91, true )->andReturn( false );
		Functions\expect( 'delete_option' )->never();

		$result = ( new ReviewResponseService() )->erase_by_email( 'reviewer@example.test' );

		self::assertFalse( $result['items_removed'] );
		self::assertTrue( $result['items_retained'] );
		self::assertSame( [ 'Some PreviewShare responses could not be removed. Please try again.' ], $result['messages'] );
		self::assertFalse( $result['done'] );
	}
}
