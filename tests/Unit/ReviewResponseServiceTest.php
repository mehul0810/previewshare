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
	private $original_database;
	private $reservation;
	private $reservation_failure;

	protected function setUp(): void {
		parent::setUp();
		$this->original_database = $GLOBALS['wpdb'] ?? null;
		$this->reservation = null;
		$this->reservation_failure = false;
		$GLOBALS['wpdb'] = new class( $this->reservation, $this->reservation_failure ) {
			public $options = 'wp_options';
			public $posts = 'wp_posts';
			public $postmeta = 'wp_postmeta';
			public $last_error = '';
			public $commit_fails = false;
			public $release_fails = false;
			public $review_exists = true;
			public $parent_exists = true;
			public $insert_id = 1;
			public $marker_row_id = 1;
			public $orphaned_metadata = [];
			public $fail_orphan_metadata = false;
			public $queued_metadata = [];
			public $restore_during_cleanup = false;
			private $reservation;
			private $failure;
			public function __construct( &$reservation, &$failure ) {
				$this->reservation = &$reservation;
				$this->failure = &$failure;
			}
			public function prepare( string $sql, ...$args ): array { return [ $sql, $args ]; }

			public function query( array $prepared ) {
				[ $sql, $args ] = $prepared;
				if ( 0 === strpos( $sql, 'DELETE FROM wp_postmeta' ) ) {
					\PHPUnit\Framework\Assert::assertStringContainsString( 'AND NOT EXISTS', $sql );
					if ( $this->fail_orphan_metadata ) { return false; }
					if ( $this->restore_during_cleanup ) {
						$this->review_exists = true;
						$this->restore_during_cleanup = false;
					}
					if ( $this->review_exists ) { return 0; }
					foreach ( array_slice( $args[0], 1, -1 ) as $key ) {
						unset( $this->orphaned_metadata[ $key ] );
					}
					return 1;
				}
				if ( 0 === strpos( $sql, 'DELETE' ) ) {
					\PHPUnit\Framework\Assert::assertStringContainsString( 'option_id = %d', $sql );
					\PHPUnit\Framework\Assert::assertStringContainsString( 'OR NOT EXISTS', $sql );
					if ( $this->marker_row_id !== $args[0] || ( 0 !== $args[2] && $this->review_exists ) ) { return 0; }
					\PHPUnit\Framework\Assert::assertStringContainsString( "option_value = '0'", $sql );
					if ( $this->release_fails ) { return false; }
					if ( '0' !== (string) $this->reservation ) { return 0; }
					$this->reservation = null;
					return 1;
				}
				if ( 0 === strpos( $sql, 'UPDATE' ) ) {
					\PHPUnit\Framework\Assert::assertStringContainsString( "option_value = '0' AND EXISTS", $sql );
					if ( $this->commit_fails ) { return false; }
					\PHPUnit\Framework\Assert::assertStringContainsString( 'owners.ID = %d', $sql );
					if ( ! $this->review_exists || ( 0 !== end( $args ) && ! $this->parent_exists ) || '0' !== (string) $this->reservation ) { return 0; }
					$this->reservation = $args[0];
					return 1;
				}
				if ( $this->failure ) { return false; }
				if ( null !== $this->reservation ) { return 0; }
				$this->reservation = 0;
				return 1;
			}
			public function get_var( array $prepared ) {
				[ $sql, $args ] = $prepared;
				if ( 0 === strpos( $sql, 'SELECT ID FROM' ) ) {
					$exists = 42 === $args[0] ? $this->parent_exists : $this->review_exists;
					return $exists ? (string) $args[0] : null;
				}
				if ( 0 === strpos( $sql, 'SELECT reviews.ID' ) && ( ! $this->review_exists || ( 0 !== end( $args ) && ! $this->parent_exists ) ) ) { return null; }
				return $this->reservation;
			}
			public function esc_like( string $value ): string { return addcslashes( $value, '_%' ); }
			public function get_col( array $prepared ): array { return []; }
			public function get_results( array $prepared, string $format ): array {
				$rows = [];
				foreach ( $this->queued_metadata as $name => $value ) {
					$rows[] = [ 'option_name' => $name, 'option_value' => $value, 'existing_id' => null ];
				}
				return $rows;
			}
		};
		Functions\when( 'wp_cache_delete' )->justReturn( true );
		Functions\when( 'get_posts' )->justReturn( [] );
		Functions\when( '__' )->returnArg( 1 );
		Functions\when( 'is_wp_error' )->alias(
			static function ( $value ): bool {
				return $value instanceof WP_Error;
			}
		);
	}

	protected function tearDown(): void {
		$GLOBALS['wpdb'] = $this->original_database;
		parent::tearDown();
	}

	public function test_retry_remains_unconfirmed_until_the_reserved_response_is_saved(): void {
		$this->reservation = 0;
		Functions\expect( 'wp_insert_post' )->never();
		Functions\expect( 'get_post' )->times( 3 )->with( 77 )->andReturn(
			new \WP_Post( [ 'ID' => 77, 'post_type' => 'previewshare_review', 'post_status' => 'private', 'post_parent' => 42 ] )
		);
		Functions\when( 'get_post_meta' )->alias( static function ( int $id, string $key ) {
			return [ '_previewshare_link_hash' => 'link', '_previewshare_submission_option' => 'previewshare_review_request_request', '_previewshare_content_hash' => str_repeat( 'a', 64 ), '_previewshare_response_type' => 'approve' ][ $key ] ?? '';
		} );
		Functions\when( 'metadata_exists' )->justReturn( true );

		$service = new ReviewResponseService();
		$pending = $service->create_response( 42, 'link', 'version', 'approve', '', '', '', 'request' );
		self::assertInstanceOf( WP_Error::class, $pending );
		self::assertSame( 'review_unconfirmed', $pending->get_error_code() );
		self::assertSame( [ 'status' => 409 ], $pending->get_error_data() );

		$this->reservation = '77';
		$committed = $service->create_response( 42, 'link', 'version', 'approve', '', '', '', 'request' );
		self::assertInstanceOf( WP_Error::class, $committed );
		self::assertSame( 'review_duplicate', $committed->get_error_code() );
		$this->reservation = 77;
		$committed = $service->create_response( 42, 'link', 'version', 'approve', '', '', '', 'request' );
		self::assertSame( 'review_duplicate', $committed->get_error_code() );

		$GLOBALS['wpdb']->parent_exists = false;
		$orphan = $service->create_response( 42, 'link', 'version', 'approve', '', '', '', 'request' );
		self::assertSame( 'review_unconfirmed', $orphan->get_error_code() );
	}

	public function test_live_writer_retains_its_marker_until_the_same_request_finishes(): void {
		$service = new ReviewResponseService();
		Functions\expect( 'current_time' )->once()->andReturn( '2026-09-25 18:00:00' );
		Functions\expect( 'get_date_from_gmt' )->once()->andReturn( '2026-09-25 23:30:00' );
		Functions\expect( 'wp_insert_post' )->once()->andReturnUsing( function () use ( $service ): int {
			$retry = $service->create_response( 42, 'link', 'version', 'approve', '', '', '', 'request' );
			self::assertSame( 'review_unconfirmed', $retry->get_error_code() );
			self::assertSame( 0, $this->reservation );
			return 77;
		} );
		Functions\expect( 'add_post_meta' )->times( 6 )->andReturn( true );
		Functions\expect( 'do_action' )->once();

		self::assertSame( 77, $service->create_response( 42, 'link', 'version', 'approve', '', '', '', 'request' ) );
		self::assertSame( '77', $this->reservation );
	}

	public function test_retry_repairs_a_failed_marker_commit_only_for_a_complete_matching_review(): void {
		$service = new ReviewResponseService();
		$GLOBALS['wpdb']->commit_fails = true;
		$complete = false;
		Functions\expect( 'current_time' )->once()->andReturn( '2026-09-25 18:00:00' );
		Functions\expect( 'get_date_from_gmt' )->once()->andReturn( '2026-09-25 23:30:00' );
		Functions\expect( 'wp_insert_post' )->once()->andReturn( 77 );
		Functions\expect( 'add_post_meta' )->times( 6 )->andReturn( true );
		Functions\expect( 'do_action' )->never();
		Functions\when( 'get_posts' )->alias( static function ( array $args ): array {
			self::assertSame( 42, $args['post_parent'] );
			self::assertSame( 2, $args['posts_per_page'] );
			self::assertFalse( $args['cache_results'] );
			self::assertSame( 'previewshare_review_request_request', $args['meta_value'] );
			return [ 77 ];
		} );
		Functions\when( 'get_post' )->justReturn( new \WP_Post( [ 'ID' => 77, 'post_type' => 'previewshare_review', 'post_status' => 'private', 'post_parent' => 42 ] ) );
		Functions\when( 'get_post_meta' )->alias( static function ( int $id, string $key ): string {
			return [ '_previewshare_link_hash' => 'link', '_previewshare_submission_option' => 'previewshare_review_request_request', '_previewshare_content_hash' => str_repeat( 'a', 64 ), '_previewshare_response_type' => 'approve' ][ $key ] ?? '';
		} );
		Functions\when( 'metadata_exists' )->alias( static function () use ( &$complete ): bool { return $complete; } );

		$failed = $service->create_response( 42, 'link', 'version', 'approve', '', '', '', 'request' );
		self::assertSame( 'review_unconfirmed', $failed->get_error_code() );
		self::assertSame( 0, $this->reservation );

		$GLOBALS['wpdb']->commit_fails = false;
		$partial = $service->create_response( 42, 'link', 'version', 'approve', '', '', '', 'request' );
		self::assertSame( 'review_unconfirmed', $partial->get_error_code() );
		self::assertSame( 0, $this->reservation );

		$complete = true;
		$recovered = $service->create_response( 42, 'link', 'version', 'approve', '', '', '', 'request' );
		self::assertSame( 'review_duplicate', $recovered->get_error_code() );
		self::assertSame( '77', $this->reservation );
	}

	public function test_writer_cannot_commit_to_a_replacement_reservation_after_its_review_disappears(): void {
		Functions\expect( 'current_time' )->once()->andReturn( '2026-09-25 18:00:00' );
		Functions\expect( 'get_date_from_gmt' )->once()->andReturn( '2026-09-25 23:30:00' );
		Functions\expect( 'wp_insert_post' )->once()->andReturn( 77 );
		Functions\expect( 'add_post_meta' )->times( 6 )->andReturnUsing( static function ( int $id, string $key ): bool {
			if ( '_previewshare_submission_option' === $key ) {
				$GLOBALS['wpdb']->review_exists = false;
				$GLOBALS['wpdb']->marker_row_id = 2;
			}
			return true;
		} );
		Functions\expect( 'do_action' )->never();

		$result = ( new ReviewResponseService() )->create_response( 42, 'link', 'version', 'approve', '', '', '', 'request' );
		self::assertSame( 'review_unconfirmed', $result->get_error_code() );
		self::assertSame( 0, $this->reservation );
	}

	public function test_metadata_written_after_review_deletion_is_cleaned_without_releasing_a_replacement_marker(): void {
		$database = $GLOBALS['wpdb'];
		$database->orphaned_metadata = [ 'unrelated_key' => 'retain' ];
		Functions\expect( 'current_time' )->once()->andReturn( '2026-09-25 18:00:00' );
		Functions\expect( 'get_date_from_gmt' )->once()->andReturn( '2026-09-25 23:30:00' );
		Functions\expect( 'wp_insert_post' )->once()->andReturn( 77 );
		Functions\expect( 'add_post_meta' )->times( 6 )->andReturnUsing( static function ( int $id, string $key, string $value ) use ( $database ): bool {
			$database->parent_exists = false;
			$database->review_exists = false;
			$database->marker_row_id = 2;
			$database->orphaned_metadata[ $key ] = $value;
			return true;
		} );
		Functions\expect( 'wp_delete_post' )->never();
		Functions\expect( 'do_action' )->never();

		$result = ( new ReviewResponseService() )->create_response( 42, 'link', 'version', 'approve', 'Name', 'reviewer@example.test', '', 'request' );
		self::assertSame( 'review_unconfirmed', $result->get_error_code() );
		self::assertSame( [ 'unrelated_key' => 'retain' ], $database->orphaned_metadata );
		self::assertSame( 0, $this->reservation );
	}

	/**
	 * @dataProvider durable_metadata_retry_cases
	 */
	public function test_failed_orphan_metadata_compensation_is_durably_retried( bool $replace_marker, bool $restore_review, bool $restore_in_cleanup ): void {
		$database = $GLOBALS['wpdb'];
		$database->fail_orphan_metadata = true;
		$database->orphaned_metadata = [ 'other_metadata' => 'preserve' ];
		$child = 77;
		$key = str_repeat( 'a', 64 );
		$job = 'previewshare_review_request_meta_retry_' . $child;
		Functions\expect( 'current_time' )->once()->andReturn( '2026-09-25 18:00:00' );
		Functions\expect( 'get_date_from_gmt' )->once()->andReturn( '2026-09-25 23:30:00' );
		Functions\expect( 'wp_insert_post' )->once()->andReturn( $child );
		Functions\expect( 'add_post_meta' )->times( 6 )->andReturnUsing( static function ( int $id, string $meta_key, string $value ) use ( $database ): bool {
			$database->parent_exists = false;
			$database->review_exists = false;
			$database->orphaned_metadata[ $meta_key ] = $value;
			return true;
		} );
		Functions\expect( 'wp_delete_post' )->never();
		Functions\expect( 'do_action' )->never();
		Functions\expect( 'add_option' )->once()->with( $job, 'previewshare_review_request_' . $key . ':1', '', false )->andReturnUsing( static function ( string $name, string $value ) use ( $database ): bool {
			$database->queued_metadata[ $name ] = $value;
			return true;
		} );
		Functions\when( 'get_option' )->alias( static function ( string $name, $default ) use ( $database ) { return $database->queued_metadata[ $name ] ?? $default; } );
		Functions\when( 'delete_option' )->alias( static function ( string $name ) use ( $database ): bool { unset( $database->queued_metadata[ $name ] ); return true; } );
		Functions\expect( 'wp_next_scheduled' )->times( $restore_in_cleanup ? 2 : 1 )->andReturn( false, true );
		Functions\expect( 'wp_schedule_single_event' )->once()->andReturn( true );
		Functions\expect( 'wp_clear_scheduled_hook' )->once()->with( 'previewshare_cleanup_reviews_continue' );

		$result = ( new ReviewResponseService() )->create_response( 42, 'link', 'version', 'approve', 'Name', 'reviewer@example.test', '', $key );
		self::assertSame( 'review_unconfirmed', $result->get_error_code() );
		self::assertArrayHasKey( '_previewshare_reviewer_email', $database->orphaned_metadata );
		self::assertArrayHasKey( $job, $database->queued_metadata );
		self::assertSame( 0, $this->reservation );

		$database->fail_orphan_metadata = false;
		$database->review_exists = $restore_review;
		$database->restore_during_cleanup = $restore_in_cleanup;
		if ( $replace_marker ) {
			$database->marker_row_id = 2;
		}
		( new ReviewResponseService() )->purge_expired();

		if ( $restore_in_cleanup ) {
			self::assertArrayHasKey( $job, $database->queued_metadata );
			self::assertSame( 0, $this->reservation );
			( new ReviewResponseService() )->purge_expired();
		}
		self::assertSame( [], $database->queued_metadata );
		if ( $restore_review || $restore_in_cleanup ) {
			self::assertArrayHasKey( '_previewshare_reviewer_email', $database->orphaned_metadata );
		} else {
			self::assertSame( [ 'other_metadata' => 'preserve' ], $database->orphaned_metadata );
		}
		self::assertSame( $replace_marker || $restore_review || $restore_in_cleanup ? 0 : null, $this->reservation );
	}

	public static function durable_metadata_retry_cases(): array {
		return [
			'original writer' => [ false, false, false ],
			'replacement writer' => [ true, false, false ],
			'restored review' => [ false, true, false ],
			'restored during cleanup' => [ false, false, true ],
		];
	}

	public function test_failed_reservation_without_a_marker_is_not_acknowledged(): void {
		$this->reservation_failure = true;
		Functions\expect( 'wp_insert_post' )->never();

		$result = ( new ReviewResponseService() )->create_response( 42, 'link', 'version', 'approve', '', '', '', 'request' );
		self::assertSame( 'review_unconfirmed', $result->get_error_code() );
	}

	public function test_parent_deleted_during_insertion_rolls_back_new_orphan_and_does_not_accept_review(): void {
		$database = $GLOBALS['wpdb'];
		Functions\expect( 'current_time' )->once()->andReturn( '2026-09-25 18:00:00' );
		Functions\expect( 'get_date_from_gmt' )->once()->andReturn( '2026-09-25 23:30:00' );
		Functions\expect( 'wp_insert_post' )->once()->andReturnUsing( static function () use ( $database ): int {
			$database->parent_exists = false;
			return 77;
		} );
		Functions\expect( 'wp_delete_post' )->once()->with( 77, true )->andReturnUsing( static function () use ( $database ): bool {
			$database->review_exists = false;
			return true;
		} );
		Functions\expect( 'add_post_meta' )->never();
		Functions\expect( 'do_action' )->never();

		$result = ( new ReviewResponseService() )->create_response( 42, 'link', 'version', 'approve', '', '', '', 'request' );
		self::assertSame( 'review_storage_failed', $result->get_error_code() );
		self::assertNull( $this->reservation );
		self::assertFalse( $database->review_exists );
	}

	public function test_parent_gone_at_commit_cannot_confirm_a_remaining_review(): void {
		$database = $GLOBALS['wpdb'];
		Functions\expect( 'current_time' )->once()->andReturn( '2026-09-25 18:00:00' );
		Functions\expect( 'get_date_from_gmt' )->once()->andReturn( '2026-09-25 23:30:00' );
		Functions\expect( 'wp_insert_post' )->once()->andReturn( 77 );
		Functions\expect( 'add_post_meta' )->times( 6 )->andReturnUsing( static function ( int $id, string $key ) use ( $database ): bool {
			if ( '_previewshare_submission_option' === $key ) {
				$database->parent_exists = false;
			}
			return true;
		} );
		Functions\expect( 'get_post_meta' )->once()->with( 77, '_previewshare_submission_option', true )->andReturn( '' );
		Functions\expect( 'wp_delete_post' )->once()->with( 77, true )->andReturn( false );
		Functions\expect( 'do_action' )->never();

		$result = ( new ReviewResponseService() )->create_response( 42, 'link', 'version', 'approve', '', '', '', 'request' );
		self::assertSame( 'review_unconfirmed', $result->get_error_code() );
		self::assertSame( 0, $this->reservation );
	}

	public function test_failed_parent_delete_keeps_child_reviews_until_wordpress_confirms_deletion(): void {
		$hooks = [];
		Functions\when( 'add_action' )->alias( static function ( string $hook, callable $callback, int $priority = 10, int $args = 1 ) use ( &$hooks ): void {
			$hooks[ $hook ][] = [ $callback, $args ];
		} );
		Functions\when( 'add_filter' )->justReturn( [] );
		Functions\expect( 'get_post_type' )->never();
		$records = [ 11 => [ 'parent' => 42, 'marker' => 'previewshare_review_request_one' ] ];
		$GLOBALS['wpdb'] = $database = $this->review_database( $records );
		Functions\when( 'get_post_meta' )->alias( static function ( int $id ) use ( &$records ): string { return $records[ $id ]['marker'] ?? ''; } );
		Functions\expect( 'wp_delete_post' )->once()->with( 11, true )->andReturnUsing( static function ( int $id ) use ( &$records ): bool {
			unset( $records[ $id ] );
			return true;
		} );

		( new ReviewResponseService() )->register();
		$parent = new \WP_Post( [ 'ID' => 42, 'post_type' => 'page' ] );
		// WordPress runs the before hook even when its delete query later fails.
		foreach ( $hooks['before_delete_post'] ?? [] as [ $callback, $args ] ) {
			$callback( ...array_slice( [ 42, $parent ], 0, $args ) );
		}
		self::assertArrayHasKey( 11, $records );
		self::assertArrayHasKey( 'previewshare_review_request_one', $database->markers );

		foreach ( $hooks['deleted_post'] ?? [] as [ $callback, $args ] ) {
			$callback( ...array_slice( [ 42, $parent ], 0, $args ) );
		}
		self::assertSame( [], $records );
		self::assertSame( [], $database->markers );
	}

	public function test_post_deletion_fetches_review_ids_in_bounded_batches_and_continues_after_failure(): void {
		$attempted = [];
		$markers = [];
		$records = $this->review_records( 230, '' );
		foreach ( $records as &$record ) { $record['parent'] = 42; }
		unset( $record );
		$GLOBALS['wpdb'] = $this->review_database( $records, $markers );
		Functions\when( 'get_post_type' )->justReturn( 'post' );
		Functions\when( 'get_post_meta' )->alias( static function ( int $id ) use ( &$records ): string { return $records[ $id ]['marker'] ?? ''; } );
		Functions\when( 'wp_delete_post' )->alias( static function ( int $id ) use ( &$attempted, &$records ) {
			$attempted[] = $id;
			if ( 101 === $id ) { return false; }
			unset( $records[ $id ] );
			return true;
		} );

		Functions\expect( 'update_option' )->once()->with( 'previewshare_cleanup_reviews_failed', true, false )->andReturn( true );
		Functions\expect( 'wp_next_scheduled' )->once()->andReturn( false );
		Functions\expect( 'wp_schedule_single_event' )->once()->andReturn( true );

		( new ReviewResponseService() )->delete_for_post( 42 );
		self::assertCount( 230, $attempted );
		self::assertSame( range( 1, 230 ), $attempted );
		self::assertSame( [ 100, 100, 100 ], $GLOBALS['wpdb']->limits );
		self::assertSame( [ 0, 100, 200 ], $GLOBALS['wpdb']->cursors );
		self::assertStringContainsString( 'reviews.post_type = %s AND reviews.post_status = %s AND reviews.ID > %d', $GLOBALS['wpdb']->sql );
		self::assertStringContainsString( 'ORDER BY reviews.ID ASC LIMIT %d', $GLOBALS['wpdb']->sql );
		self::assertCount( 229, $markers );
		self::assertNotContains( 'previewshare_review_request_101', $markers );
	}

	public function test_marker_pointing_to_a_missing_or_unrelated_record_is_not_acknowledged(): void {
		$this->reservation = 77;
		$post = null;
		Functions\when( 'get_post' )->alias( static function () use ( &$post ) { return $post; } );
		Functions\when( 'get_post_meta' )->alias( static function ( int $id, string $key ) {
			return [ '_previewshare_link_hash' => 'different-link', '_previewshare_submission_option' => 'previewshare_review_request_request' ][ $key ] ?? '';
		} );
		Functions\expect( 'wp_insert_post' )->never();
		$service = new ReviewResponseService();
		$result = $service->create_response( 42, 'link', 'version', 'approve', '', '', '', 'request' );
		self::assertSame( 'review_unconfirmed', $result->get_error_code() );

		$post = new \WP_Post( [ 'ID' => 77, 'post_type' => 'previewshare_review', 'post_status' => 'private', 'post_parent' => 99 ] );
		$result = $service->create_response( 42, 'link', 'version', 'approve', '', '', '', 'request' );
		self::assertSame( 'review_unconfirmed', $result->get_error_code() );

		$post = new \WP_Post( [ 'ID' => 77, 'post_type' => 'previewshare_review', 'post_status' => 'private', 'post_parent' => 42 ] );
		$result = $service->create_response( 42, 'link', 'version', 'approve', '', '', '', 'request' );
		self::assertSame( 'review_unconfirmed', $result->get_error_code() );
	}

	public function test_incomplete_record_is_not_acknowledged(): void {
		$this->reservation = 77;
		Functions\when( 'get_post' )->justReturn( new \WP_Post( [ 'ID' => 77, 'post_type' => 'previewshare_review', 'post_status' => 'private', 'post_parent' => 42 ] ) );
		Functions\when( 'get_post_meta' )->alias( static function ( int $id, string $key ) {
			return [ '_previewshare_link_hash' => 'link', '_previewshare_submission_option' => 'previewshare_review_request_request', '_previewshare_content_hash' => str_repeat( 'a', 64 ), '_previewshare_response_type' => 'approve' ][ $key ] ?? '';
		} );
		Functions\when( 'metadata_exists' )->alias( static function ( string $type, int $id, string $key ): bool {
			return '_previewshare_reviewer_email' !== $key;
		} );
		$result = ( new ReviewResponseService() )->create_response( 42, 'link', 'version', 'approve', '', '', '', 'request' );
		self::assertSame( 'review_unconfirmed', $result->get_error_code() );
	}

	public function test_out_of_range_reservation_is_not_acknowledged(): void {
		$this->reservation = (string) PHP_INT_MAX . '0';
		Functions\expect( 'get_post' )->never();
		$result = ( new ReviewResponseService() )->create_response( 42, 'link', 'version', 'approve', '', '', '', 'request' );
		self::assertSame( 'review_unconfirmed', $result->get_error_code() );
	}

	public function test_corrupt_response_metadata_is_not_acknowledged(): void {
		$this->reservation = 77;
		Functions\when( 'get_post' )->justReturn( new \WP_Post( [ 'ID' => 77, 'post_type' => 'previewshare_review', 'post_status' => 'private', 'post_parent' => 42 ] ) );
		Functions\when( 'metadata_exists' )->justReturn( true );
		Functions\when( 'get_post_meta' )->alias( static function ( int $id, string $key ) {
			return [ '_previewshare_link_hash' => 'link', '_previewshare_submission_option' => 'previewshare_review_request_request', '_previewshare_content_hash' => [ 'invalid' ], '_previewshare_response_type' => 'approve', '_previewshare_reviewer_name' => '', '_previewshare_reviewer_email' => '' ][ $key ] ?? '';
		} );
		$result = ( new ReviewResponseService() )->create_response( 42, 'link', 'version', 'approve', '', '', '', 'request' );
		self::assertSame( 'review_unconfirmed', $result->get_error_code() );
	}

	public function test_non_string_identity_metadata_is_not_acknowledged(): void {
		$this->reservation = 77;
		Functions\when( 'get_post' )->justReturn( new \WP_Post( [ 'ID' => 77, 'post_type' => 'previewshare_review', 'post_status' => 'private', 'post_parent' => 42 ] ) );
		Functions\when( 'metadata_exists' )->justReturn( true );
		Functions\when( 'get_post_meta' )->alias( static function ( int $id, string $key ) {
			return [ '_previewshare_link_hash' => 'link', '_previewshare_submission_option' => 'previewshare_review_request_request', '_previewshare_content_hash' => str_repeat( 'a', 64 ), '_previewshare_response_type' => 'approve', '_previewshare_reviewer_name' => '', '_previewshare_reviewer_email' => [ 'invalid' ] ][ $key ] ?? '';
		} );
		$result = ( new ReviewResponseService() )->create_response( 42, 'link', 'version', 'approve', '', '', '', 'request' );
		self::assertSame( 'review_unconfirmed', $result->get_error_code() );
	}

	public function test_response_is_stored_as_a_private_child_post(): void {
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
		Functions\expect( 'current_time' )->once()->with( 'mysql', true )->andReturn( '2026-09-25 18:00:00' );
		Functions\expect( 'get_date_from_gmt' )->once()->with( '2026-09-25 18:00:00' )->andReturn( '2026-09-25 23:30:00' );
		Functions\expect( 'wp_insert_post' )->once()->andReturn( new WP_Error( 'db_error' ) );

		$result = ( new ReviewResponseService() )->create_response( 42, 'link', 'version', 'approve', '', '', '', 'request' );

		self::assertInstanceOf( WP_Error::class, $result );
		self::assertSame( 'review_storage_failed', $result->get_error_code() );
		self::assertNull( $this->reservation );
	}

	public function test_failed_insert_and_failed_marker_release_stay_unconfirmed(): void {
		$GLOBALS['wpdb']->release_fails = true;
		Functions\expect( 'current_time' )->once()->andReturn( '2026-09-25 18:00:00' );
		Functions\expect( 'get_date_from_gmt' )->once()->andReturn( '2026-09-25 23:30:00' );
		Functions\expect( 'wp_insert_post' )->once()->andReturn( new WP_Error( 'db_error' ) );
		Functions\expect( 'do_action' )->never();

		$service = new ReviewResponseService();
		$error = $service->create_response( 42, 'link', 'version', 'approve', '', '', '', 'request' );
		self::assertSame( 'review_unconfirmed', $error->get_error_code() );
		self::assertSame( 0, $this->reservation );
		$retry = $service->create_response( 42, 'link', 'version', 'approve', '', '', '', 'request' );
		self::assertSame( 'review_unconfirmed', $retry->get_error_code() );
		self::assertSame( 0, $this->reservation );
	}

	public function test_failed_rollback_keeps_reservation_and_blocks_a_duplicate(): void {
		Functions\expect( 'current_time' )->once()->andReturn( '2026-09-25 18:00:00' );
		Functions\expect( 'get_date_from_gmt' )->once()->andReturn( '2026-09-25 23:30:00' );
		Functions\expect( 'wp_insert_post' )->once()->andReturn( 77 );
		Functions\expect( 'add_post_meta' )->twice()->andReturn( true, false );
		Functions\expect( 'wp_delete_post' )->once()->with( 77, true )->andReturn( false );
		Functions\expect( 'delete_option' )->never();
		$service = new ReviewResponseService();
		$failed = $service->create_response( 42, 'link', 'version', 'approve', '', '', '', 'request' );
		self::assertSame( 'review_unconfirmed', $failed->get_error_code() );
		self::assertSame( 0, $this->reservation );
		$retry = $service->create_response( 42, 'link', 'version', 'approve', '', '', '', 'request' );
		self::assertSame( 'review_unconfirmed', $retry->get_error_code() );
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
		$options = [];
		$deleted_options = [];
		$GLOBALS['wpdb'] = $this->review_database( $records, $deleted_options );
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

	public function test_failed_parent_cascade_retries_young_orphan_without_deleting_standalone_or_owned_reviews(): void {
		$date = gmdate( 'Y-m-d H:i:s' );
		$records = [
			1 => [ 'date' => $date, 'parent' => 42, 'parent_missing' => true, 'marker' => 'previewshare_review_request_1' ],
			2 => [ 'date' => $date, 'parent' => 0, 'marker' => '' ],
			3 => [ 'date' => $date, 'parent' => 43, 'marker' => '' ],
		];
		$deleted = [];
		$GLOBALS['wpdb'] = $database = $this->review_database( $records, $deleted );
		$options = [];
		Functions\when( 'get_option' )->alias( static function ( string $name, $default ) use ( &$options ) { return $options[ $name ] ?? $default; } );
		Functions\when( 'update_option' )->alias( static function ( string $name, $value ) use ( &$options ): bool { $options[ $name ] = $value; return true; } );
		Functions\when( 'delete_option' )->alias( static function ( string $name ) use ( &$options ): bool { unset( $options[ $name ] ); return true; } );
		Functions\when( 'get_post_meta' )->alias( static function ( int $id ) use ( &$records ): string { return $records[ $id ]['marker'] ?? ''; } );
		$fail = true;
		Functions\expect( 'wp_delete_post' )->twice()->with( 1, true )->andReturnUsing( static function ( int $id ) use ( &$records, &$fail ): bool {
			if ( $fail ) { return false; }
			unset( $records[ $id ] );
			return true;
		} );
		Functions\expect( 'wp_next_scheduled' )->twice()->with( 'previewshare_cleanup_reviews_continue' )->andReturn( false, true );
		Functions\expect( 'wp_schedule_single_event' )->once()->andReturn( true );
		Functions\expect( 'wp_clear_scheduled_hook' )->never();

		$service = new ReviewResponseService();
		$service->delete_for_post( 42, new \WP_Post( [ 'ID' => 42, 'post_type' => 'post' ] ) );
		self::assertArrayHasKey( 1, $records );
		$fail = false;
		$service->purge_expired();

		self::assertSame( [ 2, 3 ], array_keys( $records ) );
		self::assertSame( [], $database->markers );
		self::assertStringContainsString( 'reviews.post_parent > 0 AND NOT EXISTS', $database->sql );
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

	public function test_failed_marker_cleanup_is_reported_and_reconciled_without_releasing_live_work(): void {
		$records = $this->review_records( 2, 'reviewer@example.test' );
		$records[2]['email'] = 'other@example.test';
		$records[2]['date'] = gmdate( 'Y-m-d H:i:s' );
		$pending = 'previewshare_review_request_1';
		$database = $this->review_database( $records );
		$database->markers[ $pending ] = '0';
		$database->markers['previewshare_review_request_live'] = '0';
		$database->markers['previewshare_review_request_replaced'] = '99';
		$database->fail_marker_delete = true;
		$GLOBALS['wpdb'] = $database;
		$transients = [];
		$this->mock_eraser_state( $records, $transients );
		Functions\expect( 'wp_delete_post' )->once()->with( 1, true )->andReturnUsing( static function ( int $id ) use ( &$records ): bool {
			unset( $records[ $id ] );
			return true;
		} );
		Functions\expect( 'wp_next_scheduled' )->once()->with( 'previewshare_cleanup_reviews_continue' )->andReturn( false );
		Functions\expect( 'wp_schedule_single_event' )->once()->andReturn( true );

		$result = ( new ReviewResponseService() )->erase_by_email( 'reviewer@example.test', 1 );
		self::assertTrue( $result['items_removed'] );
		self::assertTrue( $result['items_retained'] );
		self::assertNotEmpty( $result['messages'] );
		self::assertSame( '1', $database->markers[ $pending ] );

		$database->replace_before_delete['previewshare_review_request_replaced'] = '0';
		$database->fail_marker_delete = false;
		Functions\when( 'get_option' )->alias( static function ( string $name, $default ) { return $default; } );
		Functions\when( 'delete_option' )->justReturn( true );
		Functions\expect( 'wp_clear_scheduled_hook' )->once()->with( 'previewshare_cleanup_reviews_continue' );
		( new ReviewResponseService() )->purge_expired();

		self::assertSame(
			[
				'previewshare_review_request_2' => '2',
				'previewshare_review_request_live' => '0',
				'previewshare_review_request_replaced' => '0',
			],
			$database->markers
		);
	}

	public function test_orphan_marker_scan_is_bounded_and_resumes_from_its_own_cursor(): void {
		$records = [];
		$GLOBALS['wpdb'] = $database = $this->review_database( $records );
		for ( $id = 1; $id <= 2000; ++$id ) {
			$database->markers[ 'previewshare_review_request_' . sprintf( '%04d', $id ) ] = '0';
		}
		$orphan = 'previewshare_review_request_2001';
		$database->markers[ $orphan ] = '2001';
		$options = [];
		Functions\when( 'get_option' )->alias( static function ( string $key, $default ) use ( &$options ) { return $options[ $key ] ?? $default; } );
		Functions\when( 'update_option' )->alias( static function ( string $key, $value ) use ( &$options ): bool { $options[ $key ] = $value; return true; } );
		Functions\when( 'delete_option' )->alias( static function ( string $key ) use ( &$options ): bool { unset( $options[ $key ] ); return true; } );
		Functions\expect( 'wp_next_scheduled' )->once()->andReturn( false );
		Functions\expect( 'wp_schedule_single_event' )->once()->andReturnUsing( static function ( int $timestamp, string $hook ): bool {
			self::assertSame( 'previewshare_cleanup_reviews_continue', $hook );
			self::assertGreaterThanOrEqual( time() + 5 * MINUTE_IN_SECONDS - 1, $timestamp );
			return true;
		} );
		Functions\expect( 'wp_clear_scheduled_hook' )->once()->with( 'previewshare_cleanup_reviews_continue' );

		( new ReviewResponseService() )->purge_expired();
		self::assertSame( array_fill( 0, 20, 100 ), $database->marker_limits );
		self::assertSame( 'previewshare_review_request_2000', $options['previewshare_cleanup_review_markers_cursor']['cursor'] );
		self::assertArrayHasKey( $orphan, $database->markers );

		( new ReviewResponseService() )->purge_expired();
		self::assertSame( 'previewshare_review_request_2000', end( $database->marker_cursors ) );
		self::assertArrayNotHasKey( $orphan, $database->markers );
		self::assertCount( 2000, $database->markers );
		self::assertArrayNotHasKey( 'previewshare_cleanup_review_markers_cursor', $options );
	}

	public function test_privacy_eraser_moves_past_failed_first_hundred_and_removes_the_101st_record(): void {
		$records = $this->review_records( 101, 'reviewer@example.test' );
		$previous_db = $GLOBALS['wpdb'] ?? null;
		$transients = [];
		$deleted_options = [];
		$GLOBALS['wpdb'] = $this->review_database( $records, $deleted_options );
		$this->mock_eraser_state( $records, $transients );
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
		$transients = [];
		$deleted_options = [];
		$GLOBALS['wpdb'] = $this->review_database( $records, $deleted_options );
		$this->mock_eraser_state( $records, $transients );
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
		$transients = [];
		$deleted_options = [];
		$GLOBALS['wpdb'] = $this->review_database( $records, $deleted_options );
		$this->mock_eraser_state( $records, $transients );
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

	public function test_privacy_eraser_retry_fails_when_stale_cursor_cannot_be_reset(): void {
		$records = $this->review_records( 101, 'reviewer@example.test' );
		$previous_db = $GLOBALS['wpdb'] ?? null;
		$transients = [];
		$deleted_options = [];
		$GLOBALS['wpdb'] = $this->review_database( $records, $deleted_options );
		$this->mock_eraser_state( $records, $transients );
		Functions\when( 'wp_delete_post' )->alias( static function ( int $id ) use ( &$records ) { if ( $id <= 100 ) { return false; } unset( $records[ $id ] ); return true; } );
		try {
			$service = new ReviewResponseService();
			$first = $service->erase_by_email( 'reviewer@example.test', 1 );
			$transients['__fail_delete'] = true;
			$second = $service->erase_by_email( 'reviewer@example.test', 2 );
			$retry = $service->erase_by_email( 'reviewer@example.test', 1 );
		} finally {
			$GLOBALS['wpdb'] = $previous_db;
		}
		self::assertFalse( $first['done'] );
		self::assertTrue( $second['done'] );
		self::assertInstanceOf( WP_Error::class, $retry );
		self::assertSame( 'review_eraser_progress_reset_failed', $retry->get_error_code() );
		self::assertSame( range( 1, 100 ), array_keys( $records ) );
	}

	public function test_privacy_eraser_query_failure_returns_an_error_without_claiming_completion(): void {
		$records = $this->review_records( 1, 'reviewer@example.test' );
		$previous_db = $GLOBALS['wpdb'] ?? null;
		$database = $this->review_database( $records );
		$database->fail_query_at = [ 1 ];
		$GLOBALS['wpdb'] = $database;
		$transients = [];
		$deleted_options = [];
		$this->mock_eraser_state( $records, $transients );
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
		$this->mock_eraser_state( $records, $transients );
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
	 * @param array<int,array<string,mixed>> $records Review records.
	 * @param ?array<int,string> $deleted_options Deleted idempotency option names.
	 * @return object
	 */
	private function review_database( array &$records, ?array &$deleted_options = null ) {
		return new class( $records, $deleted_options ) {
			public $options = 'wp_options';
			public $posts = 'wp_posts';
			public $postmeta = 'wp_postmeta';
			public $last_error = '';
			public $query_count = 0;
			public $fail_query_at = [];
			public $fail_marker_delete = false;
			public $replace_before_delete = [];
			public $markers = [];
			public $marker_limits = [];
			public $marker_cursors = [];
			public $limits = [];
			public $cursors = [];
			public $sql = '';
			private $records;
			private $deleted_options;
			public function __construct( array &$records, ?array &$deleted_options ) {
				$this->records =& $records;
				$this->deleted_options =& $deleted_options;
				foreach ( $records as $id => $record ) {
					if ( ! empty( $record['marker'] ) ) { $this->markers[ $record['marker'] ] = (string) $id; }
				}
			}
			public function prepare( string $sql, ...$args ): array { return [ $sql, is_array( $args[0] ) ? $args[0] : $args ]; }
			public function esc_like( string $text ): string { return addcslashes( $text, '_%\\' ); }
			public function get_var( array $prepared ): ?string {
				$this->last_error = '';
				return $this->markers[ $prepared[1][0] ] ?? null;
			}
			public function query( array $prepared ) {
				[ $sql, $args ] = $prepared;
				$this->last_error = '';
				if ( 0 === strpos( $sql, 'UPDATE' ) ) {
					[ $id, $name ] = $args;
					if ( '0' !== ( $this->markers[ $name ] ?? null ) || ! isset( $this->records[ (int) $id ] ) ) { return 0; }
					$this->markers[ $name ] = $id;
					return 1;
				}
				\PHPUnit\Framework\Assert::assertStringContainsString( 'AND NOT EXISTS', $sql );
				[ $name, $expected, $id ] = $args;
				if ( $this->fail_marker_delete ) {
					$this->last_error = 'simulated database error';
					return false;
				}
				if ( isset( $this->replace_before_delete[ $name ] ) ) {
					$this->markers[ $name ] = $this->replace_before_delete[ $name ];
					unset( $this->replace_before_delete[ $name ] );
				}
				if ( $expected !== ( $this->markers[ $name ] ?? null ) || isset( $this->records[ $id ] ) ) { return 0; }
				unset( $this->markers[ $name ] );
				$this->deleted_options[] = $name;
				return 1;
			}
			public function get_results( array $prepared, string $output ): array {
				$this->last_error = '';
				[ $prefix, $after, $limit ] = $prepared[1];
				$this->marker_limits[] = $limit;
				$this->marker_cursors[] = $after;
				$markers = $this->markers;
				ksort( $markers, SORT_STRING );
				$rows = [];
				foreach ( $markers as $name => $value ) {
					if ( strcmp( $name, $after ) <= 0 ) { continue; }
					$rows[] = [ 'option_name' => $name, 'option_value' => $value, 'existing_id' => isset( $this->records[ (int) $value ] ) ? $value : null ];
					if ( count( $rows ) === $limit ) { break; }
				}
				return $rows;
			}
			public function get_col( array $prepared ): ?array {
				++$this->query_count;
				if ( in_array( $this->query_count, $this->fail_query_at, true ) ) {
					$this->last_error = 'simulated database error';
					return null;
				}
				$this->last_error = '';
				[ $sql, $arguments ] = $prepared;
				$after = (int) $arguments[2];
				$this->sql = $sql;
				$email = false;
				$cutoff = false;
				$parent = false;
				if ( false !== strpos( $sql, 'reviewer_email' ) ) { $email = (string) $arguments[4]; }
				if ( false !== strpos( $sql, 'reviews.post_parent = %d' ) ) { $parent = (int) $arguments[3]; }
				if ( false !== strpos( $sql, 'post_date_gmt' ) ) { $cutoff = $arguments[ count( $arguments ) - 2 ]; }
				$limit = (int) end( $arguments );
				$this->limits[] = $limit;
				$this->cursors[] = $after;
				$ids = [];
				foreach ( $this->records as $id => $record ) {
					$orphan = ( $record['parent'] ?? 0 ) > 0 && ! empty( $record['parent_missing'] );
					if ( $id <= $after || ( false !== $parent && ( $record['parent'] ?? null ) !== $parent ) || ( false !== $email && ( $record['email'] ?? '' ) !== $email ) || ( false !== $cutoff && $record['date'] >= $cutoff && ! $orphan ) ) { continue; }
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
	 * @return void
	 */
	private function mock_eraser_state( array &$records, array &$transients ): void {
		Functions\when( 'sanitize_email' )->returnArg( 1 );
		Functions\when( 'get_transient' )->alias( static function ( string $key ) use ( &$transients ) { return $transients[ $key ] ?? false; } );
		Functions\when( 'set_transient' )->alias( static function ( string $key, $value ) use ( &$transients ): bool {
			if ( ! empty( $transients['__fail_write'] ) ) { return false; }
			$transients[ $key ] = $value;
			return true;
		} );
		Functions\when( 'delete_transient' )->alias( static function ( string $key ) use ( &$transients ): bool {
			if ( ! empty( $transients['__fail_delete'] ) ) { return false; }
			unset( $transients[ $key ] );
			return true;
		} );
		Functions\when( 'get_post_meta' )->alias( static function ( int $id ) use ( &$records ): string { return $records[ $id ]['marker'] ?? ''; } );
	}
}
