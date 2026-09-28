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

	public function test_cleanup_keyset_progresses_past_a_full_batch_of_failed_oldest_records(): void {
		$cutoff_timestamp = time() - ( 90 * DAY_IN_SECONDS );
		$cutoff           = gmdate( 'Y-m-d H:i:s', $cutoff_timestamp );
		$records          = [];
		for ( $id = 1; $id <= 110; $id++ ) {
			$records[ $id ] = [ 'date' => gmdate( 'Y-m-d H:i:s', $cutoff_timestamp - 60 ), 'marker' => 'previewshare_review_request_' . $id ];
		}
		$records[111] = [ 'date' => gmdate( 'Y-m-d H:i:s', $cutoff_timestamp + 60 ), 'marker' => 'previewshare_review_request_111' ];
		$previous_db = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['wpdb'] = $this->review_database( $records );
		$options = [];
		$deleted_options = [];
		Functions\when( 'get_option' )->alias( static function ( string $name, $default = false ) use ( &$options, &$records ) {
			return 'previewshare_cleanup_reviews_cursor' === $name ? ( $options[ $name ] ?? $default ) : ( $records[ (int) preg_replace( '/[^0-9]/', '', $name ) ]['marker'] ?? '' );
		} );
		Functions\when( 'update_option' )->alias( static function ( string $name, $value ) use ( &$options ): bool { $options[ $name ] = $value; return true; } );
		Functions\when( 'delete_option' )->alias( static function ( string $name ) use ( &$options, &$deleted_options ): bool { $deleted_options[] = $name; unset( $options[ $name ] ); return true; } );
		Functions\when( 'get_post_meta' )->alias( static function ( int $id ) use ( &$records ): string { return $records[ $id ]['marker'] ?? ''; } );
		Functions\when( 'wp_delete_post' )->alias( static function ( int $id ) use ( &$records ) {
			if ( $id <= 100 ) { return false; }
			unset( $records[ $id ] );
			return true;
		} );
		Functions\when( 'wp_next_scheduled' )->justReturn( false );
		Functions\expect( 'wp_schedule_single_event' )->once()->andReturnUsing( static function ( int $timestamp, string $hook ): bool {
			self::assertSame( 'previewshare_cleanup_reviews_continue', $hook );
			self::assertGreaterThanOrEqual( time() + ( HOUR_IN_SECONDS - 1 ), $timestamp );
			return true;
		} );
		Functions\expect( 'wp_clear_scheduled_hook' )->never();

		try {
			( new ReviewResponseService() )->purge_expired();
		} finally {
			$GLOBALS['wpdb'] = $previous_db;
		}

		self::assertSame( array_merge( range( 1, 100 ), [ 111 ] ), array_keys( $records ) );
		self::assertSame( 101, count( $records ) );
		self::assertArrayNotHasKey( 'previewshare_cleanup_reviews_cursor', $options );
		self::assertContains( 'previewshare_cleanup_reviews_failed', $deleted_options );
		self::assertNotContains( 'previewshare_review_request_1', $deleted_options );
		self::assertContains( 'previewshare_review_request_101', $deleted_options );
	}

	public function test_cleanup_does_not_delete_reviews_before_the_90_day_cutoff(): void {
		$cutoff_timestamp = time() - ( 90 * DAY_IN_SECONDS );
		$records = [
			1 => [ 'date' => gmdate( 'Y-m-d H:i:s', $cutoff_timestamp - 1 ), 'marker' => '' ],
			2 => [ 'date' => gmdate( 'Y-m-d H:i:s', $cutoff_timestamp + 1 ), 'marker' => '' ],
		];
		$previous_db = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['wpdb'] = $this->review_database( $records );
		Functions\when( 'get_option' )->justReturn( 0 );
		Functions\when( 'update_option' )->justReturn( true );
		Functions\when( 'delete_option' )->justReturn( true );
		Functions\when( 'get_post_meta' )->justReturn( '' );
		Functions\when( 'wp_delete_post' )->alias( static function ( int $id ) use ( &$records ) { unset( $records[ $id ] ); return true; } );
		Functions\when( 'wp_next_scheduled' )->justReturn( false );
		Functions\expect( 'wp_clear_scheduled_hook' )->once()->with( 'previewshare_cleanup_reviews_continue' );
		try {
			( new ReviewResponseService() )->purge_expired();
		} finally {
			$GLOBALS['wpdb'] = $previous_db;
		}
		self::assertSame( [ 2 ], array_keys( $records ) );
	}

	public function test_cleanup_schedule_is_deduplicated_and_deactivation_clears_both_events(): void {
		$cleared_hooks = [];
		$schedule_checks = 0;
		Functions\when( 'wp_next_scheduled' )->alias( static function ( string $hook ) use ( &$schedule_checks ): bool { self::assertSame( 'previewshare_cleanup_reviews', $hook ); return 0 < $schedule_checks++; } );
		Functions\expect( 'wp_schedule_event' )->once()->andReturn( true );
		Functions\when( 'wp_clear_scheduled_hook' )->alias( static function ( string $hook ) use ( &$cleared_hooks ): int { $cleared_hooks[] = $hook; return 1; } );
		$service = new ReviewResponseService();
		$service->schedule_cleanup();
		$service->schedule_cleanup();
		ReviewResponseService::unschedule_cleanup();
		self::assertSame( 2, $schedule_checks );
		self::assertSame( [ 'previewshare_cleanup_reviews', 'previewshare_cleanup_reviews_continue' ], $cleared_hooks );
	}

	public function test_cleanup_continuation_processes_the_next_keyset_page(): void {
		$cutoff_timestamp = time() - ( 90 * DAY_IN_SECONDS );
		$records = [];
		for ( $id = 1; $id <= 2001; $id++ ) { $records[ $id ] = [ 'date' => gmdate( 'Y-m-d H:i:s', $cutoff_timestamp - 60 ), 'marker' => '' ]; }
		$records[2002] = [ 'date' => gmdate( 'Y-m-d H:i:s', $cutoff_timestamp + 60 ), 'marker' => '' ];
		$previous_db = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['wpdb'] = $this->review_database( $records );
		$options = [ 'previewshare_cleanup_reviews_cursor' => 2000 ];
		Functions\when( 'get_option' )->alias( static function ( string $name, $default = false ) use ( &$options ) { return $options[ $name ] ?? $default; } );
		Functions\when( 'update_option' )->alias( static function ( string $name, $value ) use ( &$options ): bool { $options[ $name ] = $value; return true; } );
		Functions\when( 'delete_option' )->alias( static function ( string $name ) use ( &$options ): bool { unset( $options[ $name ] ); return true; } );
		Functions\when( 'get_post_meta' )->justReturn( '' );
		Functions\when( 'wp_delete_post' )->alias( static function ( int $id ) use ( &$records ) { unset( $records[ $id ] ); return true; } );
		Functions\when( 'wp_next_scheduled' )->justReturn( false );
		Functions\expect( 'wp_clear_scheduled_hook' )->once()->with( 'previewshare_cleanup_reviews_continue' );
		try {
			( new ReviewResponseService() )->purge_expired();
		} finally {
			$GLOBALS['wpdb'] = $previous_db;
		}
		self::assertSame( array_merge( range( 1, 2000 ), [ 2002 ] ), array_keys( $records ) );
	}

	public function test_cleanup_query_failure_keeps_existing_cursor_and_schedules_a_retry(): void {
		$records = [];
		$previous_db = $GLOBALS['wpdb'] ?? null;
		$database = $this->review_database( $records );
		$database->fail_query_at = [ 1 ];
		$GLOBALS['wpdb'] = $database;
		$options = [ 'previewshare_cleanup_reviews_cursor' => 35, 'previewshare_cleanup_reviews_failed' => true ];
		Functions\when( 'get_option' )->alias( static function ( string $name, $default = false ) use ( &$options ) { return $options[ $name ] ?? $default; } );
		Functions\expect( 'update_option' )->never();
		Functions\expect( 'delete_option' )->never();
		Functions\when( 'wp_next_scheduled' )->justReturn( false );
		Functions\expect( 'wp_schedule_single_event' )->once()->andReturnUsing( static function ( int $timestamp, string $hook ): bool {
			self::assertSame( 'previewshare_cleanup_reviews_continue', $hook );
			self::assertGreaterThanOrEqual( time() + ( HOUR_IN_SECONDS - 1 ), $timestamp );
			return true;
		} );
		Functions\expect( 'wp_clear_scheduled_hook' )->never();
		try {
			( new ReviewResponseService() )->purge_expired();
		} finally {
			$GLOBALS['wpdb'] = $previous_db;
		}
		self::assertSame( 35, $options['previewshare_cleanup_reviews_cursor'] );
		self::assertTrue( $options['previewshare_cleanup_reviews_failed'] );
	}

	public function test_cleanup_lookahead_query_failure_keeps_completed_batch_cursor(): void {
		$cutoff_timestamp = time() - ( 90 * DAY_IN_SECONDS );
		$records = [];
		for ( $id = 1; $id <= 2000; $id++ ) { $records[ $id ] = [ 'date' => gmdate( 'Y-m-d H:i:s', $cutoff_timestamp - 60 ), 'marker' => '' ]; }
		$previous_db = $GLOBALS['wpdb'] ?? null;
		$database = $this->review_database( $records );
		$database->fail_query_at = [ 21 ];
		$GLOBALS['wpdb'] = $database;
		$options = [];
		Functions\when( 'get_option' )->justReturn( 0 );
		Functions\when( 'update_option' )->alias( static function ( string $name, $value ) use ( &$options ): bool { $options[ $name ] = $value; return true; } );
		Functions\when( 'delete_option' )->alias( static function ( string $name ) use ( &$options ): bool { unset( $options[ $name ] ); return true; } );
		Functions\when( 'get_post_meta' )->justReturn( '' );
		Functions\when( 'wp_delete_post' )->alias( static function ( int $id ) use ( &$records ) { unset( $records[ $id ] ); return true; } );
		Functions\when( 'wp_next_scheduled' )->justReturn( false );
		Functions\expect( 'wp_schedule_single_event' )->once()->andReturn( true );
		Functions\expect( 'wp_clear_scheduled_hook' )->never();
		try {
			( new ReviewResponseService() )->purge_expired();
		} finally {
			$GLOBALS['wpdb'] = $previous_db;
		}
		self::assertSame( [], $records );
		self::assertSame( 2000, $options['previewshare_cleanup_reviews_cursor'] );
		self::assertArrayNotHasKey( 'previewshare_cleanup_reviews_failed', $options );
	}

	public function test_privacy_eraser_moves_past_failed_first_hundred_and_removes_the_101st_record(): void {
		$records = $this->review_records( 101, 'reviewer@example.test' );
		$previous_db = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['wpdb'] = $this->review_database( $records );
		$transients = [];
		$deleted_options = [];
		$this->mock_eraser_state( $records, $transients, $deleted_options );
		Functions\when( 'wp_delete_post' )->alias( static function ( int $id ) use ( &$records ) { if ( $id <= 100 ) { return false; } unset( $records[ $id ] ); return true; } );
		try {
			$service = new ReviewResponseService();
			$first = $service->erase_by_email( 'reviewer@example.test', 1 );
			$second = $service->erase_by_email( 'reviewer@example.test', 2 );
		} finally {
			$GLOBALS['wpdb'] = $previous_db;
		}
		self::assertFalse( $first['done'] );
		self::assertTrue( $first['items_retained'] );
		self::assertTrue( $second['done'] );
		self::assertTrue( $second['items_removed'] );
		self::assertTrue( $second['items_retained'] );
		self::assertNotEmpty( $second['messages'] );
		self::assertSame( range( 1, 100 ), array_keys( $records ) );
		self::assertContains( 'previewshare_review_request_101', $deleted_options );
	}

	public function test_privacy_eraser_processes_mixed_failures_across_more_than_two_pages(): void {
		$records = $this->review_records( 250, 'reviewer@example.test' );
		$previous_db = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['wpdb'] = $this->review_database( $records );
		$transients = [];
		$deleted_options = [];
		$this->mock_eraser_state( $records, $transients, $deleted_options );
		Functions\when( 'wp_delete_post' )->alias( static function ( int $id ) use ( &$records ) { if ( in_array( $id, [ 1, 130, 250 ], true ) ) { return false; } unset( $records[ $id ] ); return true; } );
		try {
			$service = new ReviewResponseService();
			$first = $service->erase_by_email( 'reviewer@example.test', 1 );
			$second = $service->erase_by_email( 'reviewer@example.test', 2 );
			$third = $service->erase_by_email( 'reviewer@example.test', 3 );
		} finally {
			$GLOBALS['wpdb'] = $previous_db;
		}
		self::assertFalse( $first['done'] );
		self::assertTrue( $first['items_removed'] );
		self::assertTrue( $first['items_retained'] );
		self::assertFalse( $second['done'] );
		self::assertTrue( $second['items_retained'] );
		self::assertTrue( $third['done'] );
		self::assertTrue( $third['items_retained'] );
		self::assertSame( [ 1, 130, 250 ], array_keys( $records ) );
		self::assertNotContains( 'previewshare_review_request_1', $deleted_options );
		self::assertContains( 'previewshare_review_request_2', $deleted_options );
	}

	public function test_privacy_eraser_retry_starts_over_and_removes_a_previously_failed_record(): void {
		$records = $this->review_records( 1, 'reviewer@example.test' );
		$previous_db = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['wpdb'] = $this->review_database( $records );
		$transients = [];
		$deleted_options = [];
		$this->mock_eraser_state( $records, $transients, $deleted_options );
		$fail = true;
		Functions\when( 'wp_delete_post' )->alias( static function ( int $id ) use ( &$records, &$fail ) { if ( $fail ) { return false; } unset( $records[ $id ] ); return true; } );
		try {
			$service = new ReviewResponseService();
			$first = $service->erase_by_email( 'reviewer@example.test', 1 );
			$fail = false;
			$retry = $service->erase_by_email( 'reviewer@example.test', 1 );
		} finally {
			$GLOBALS['wpdb'] = $previous_db;
		}
		self::assertTrue( $first['items_retained'] );
		self::assertTrue( $first['done'] );
		self::assertTrue( $retry['items_removed'] );
		self::assertTrue( $retry['done'] );
		self::assertSame( [], $records );
		self::assertContains( 'previewshare_review_request_1', $deleted_options );
	}

	public function test_privacy_eraser_query_failure_returns_an_error_without_claiming_completion(): void {
		$records = $this->review_records( 1, 'reviewer@example.test' );
		$previous_db = $GLOBALS['wpdb'] ?? null;
		$database = $this->review_database( $records );
		$database->fail_query_at = [ 1 ];
		$GLOBALS['wpdb'] = $database;
		$transients = [];
		$deleted_options = [];
		$this->mock_eraser_state( $records, $transients, $deleted_options );
		Functions\expect( 'wp_delete_post' )->never();
		try {
			$result = ( new ReviewResponseService() )->erase_by_email( 'reviewer@example.test', 1 );
		} finally {
			$GLOBALS['wpdb'] = $previous_db;
		}
		self::assertInstanceOf( WP_Error::class, $result );
		self::assertSame( 'review_eraser_query_failed', $result->get_error_code() );
	}

	public function test_privacy_eraser_fails_closed_when_cursor_cannot_be_saved_or_resumed(): void {
		$records = $this->review_records( 101, 'reviewer@example.test' );
		$previous_db = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['wpdb'] = $this->review_database( $records );
		$transients = [ '__fail_write' => true ];
		$deleted_options = [];
		$this->mock_eraser_state( $records, $transients, $deleted_options );
		Functions\when( 'wp_delete_post' )->justReturn( false );
		try {
			$service = new ReviewResponseService();
			$failed_write = $service->erase_by_email( 'reviewer@example.test', 1 );
			$missing_after_write_failure = $service->erase_by_email( 'reviewer@example.test', 2 );
			unset( $transients['__fail_write'] );
			$first_page = $service->erase_by_email( 'reviewer@example.test', 1 );
			$cursor_key = 'previewshare_review_eraser_' . hash( 'sha256', 'reviewer@example.test' );
			unset( $transients[ $cursor_key ] );
			$missing_after_eviction = $service->erase_by_email( 'reviewer@example.test', 2 );
		} finally {
			$GLOBALS['wpdb'] = $previous_db;
		}
		self::assertInstanceOf( WP_Error::class, $failed_write );
		self::assertSame( 'review_eraser_progress_failed', $failed_write->get_error_code() );
		self::assertInstanceOf( WP_Error::class, $missing_after_write_failure );
		self::assertSame( 'review_eraser_progress_missing', $missing_after_write_failure->get_error_code() );
		self::assertFalse( $first_page['done'] );
		self::assertInstanceOf( WP_Error::class, $missing_after_eviction );
		self::assertSame( 'review_eraser_progress_missing', $missing_after_eviction->get_error_code() );
	}

	public function test_continuation_hook_is_registered_to_drain_expired_reviews(): void {
		$registered_actions = [];
		Functions\when( 'add_action' )->alias( static function ( string $hook ) use ( &$registered_actions ): void { $registered_actions[] = $hook; } );
		Functions\when( 'add_filter' )->justReturn( [] );
		( new ReviewResponseService() )->register();
		self::assertContains( 'previewshare_cleanup_reviews_continue', $registered_actions );
	}

	/**
	 * Build an in-memory wpdb double for keyset review queries.
	 *
	 * @param array<int,array<string,string>> $records Review records.
	 * @return object
	 */
	private function review_database( array &$records ) {
		return new class( $records ) {
			public $posts = 'wp_posts';
			public $postmeta = 'wp_postmeta';
			public $last_error = '';
			public $query_count = 0;
			public $fail_query_at = [];
			private $records;
			public function __construct( array &$records ) { $this->records =& $records; }
			public function prepare( string $sql, array $arguments ): array { return [ $sql, $arguments ]; }
			public function get_col( array $prepared ): ?array {
				++$this->query_count;
				if ( in_array( $this->query_count, $this->fail_query_at, true ) ) {
					$this->last_error = 'simulated database error';
					return null;
				}
				$this->last_error = '';
				[ $sql, $arguments ] = $prepared;
				$after = (int) $arguments[2];
				$email = false;
				$cutoff = false;
		if ( false !== strpos( $sql, 'reviewer_email' ) ) { $email = (string) $arguments[4]; }
				if ( false !== strpos( $sql, 'post_date_gmt' ) ) { $cutoff = $arguments[ count( $arguments ) - 2 ]; }
				$limit = (int) end( $arguments );
				$ids = [];
				foreach ( $this->records as $id => $record ) {
					if ( $id <= $after || ( false !== $email && ( $record['email'] ?? '' ) !== $email ) || ( false !== $cutoff && $record['date'] >= $cutoff ) ) { continue; }
					$ids[] = $id;
				}
				sort( $ids );
				return array_slice( $ids, 0, $limit );
			}
		};
	}

	/**
	 * Create matching records for privacy eraser tests.
	 *
	 * @param int    $count Number of records.
	 * @param string $email Reviewer email.
	 * @return array<int,array<string,string>>
	 */
	private function review_records( int $count, string $email ): array {
		$records = [];
		for ( $id = 1; $id <= $count; $id++ ) { $records[ $id ] = [ 'date' => '', 'email' => $email, 'marker' => 'previewshare_review_request_' . $id ]; }
		return $records;
	}

	/**
	 * Stub option and transient state used by privacy eraser tests.
	 *
	 * @param array<int,array<string,string>> $records Review records.
	 * @param array<string,mixed>            $transients Transient values.
	 * @param array<int,string>               $deleted_options Deleted idempotency option names.
	 * @return void
	 */
	private function mock_eraser_state( array &$records, array &$transients, array &$deleted_options ): void {
		Functions\when( 'sanitize_email' )->returnArg( 1 );
		Functions\when( 'get_transient' )->alias( static function ( string $key ) use ( &$transients ) { return $transients[ $key ] ?? false; } );
		Functions\when( 'set_transient' )->alias( static function ( string $key, $value ) use ( &$transients ): bool {
			if ( ! empty( $transients['__fail_write'] ) ) { return false; }
			$transients[ $key ] = $value;
			return true;
		} );
		Functions\when( 'delete_transient' )->alias( static function ( string $key ) use ( &$transients ): bool { unset( $transients[ $key ] ); return true; } );
		Functions\when( 'get_post_meta' )->alias( static function ( int $id ) use ( &$records ): string { return $records[ $id ]['marker'] ?? ''; } );
		Functions\when( 'delete_option' )->alias( static function ( string $name ) use ( &$deleted_options ): bool { $deleted_options[] = $name; return true; } );
	}
}
