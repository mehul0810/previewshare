<?php
/**
 * Public reviewer submission tests.
 *
 * @package PreviewShare
 */

namespace PreviewShare\Tests\Unit;

use Brain\Monkey\Functions;
use PreviewShare\REST\ReviewController;
use PreviewShare\Services\PostMetaStorage;
use PreviewShare\Services\ReviewResponseService;
use PreviewShare\Services\ReviewVersion;
use WP_Error;
use WP_Post;
use WP_REST_Request;
use WP_REST_Response;

/** Prepared SQL holder for the rate-limit database fake. */
class ReviewRateLimitPreparedQuery {
	/** @var string */
	public $sql;
	/** @var array<int,mixed> */
	public $args;

	public function __construct( string $sql, array $args ) {
		$this->sql  = $sql;
		$this->args = $args;
	}
}

/** Minimal database fake that models unique inserts and value-conditional updates. */
class ReviewRateLimitWpdb {
	public $options = 'wp_options';
	public $posts = 'wp_posts';
	public $postmeta = 'wp_postmeta';
	public $last_error = '';
	/** @var array<string,string> */
	public $rows = [];
	/** @var int */
	public $update_conflicts = 0;
	/** @var int */
	public $insert_conflicts = 0;
	/** @var int */
	public $insert_zero_conflicts = 0;
	/** @var bool */
	public $fail_reads = false;
	/** @var bool */
	public $fail_seed = false;
	/** @var bool */
	public $fail_inserts = false;
	/** @var bool */
	public $fail_updates = false;
	/** @var bool */
	public $refresh_before_delete = false;
	/** @var bool */
	public $fail_deletes = false;
	/** @var string|null */
	public $conflict_ip_hash;
	/** @var array<int,string> */
	public $recent_timestamps = [];

	public function prepare( string $sql, ...$args ): ReviewRateLimitPreparedQuery {
		return new ReviewRateLimitPreparedQuery( $sql, $args );
	}

	public function esc_like( string $text ): string {
		return addcslashes( $text, '_%\\' );
	}

	public function get_var( ReviewRateLimitPreparedQuery $query ) {
		if ( $this->fail_reads ) {
			$this->last_error = 'database read failed';
			return null;
		}
		$this->last_error = '';
		return $this->rows[ $query->args[0] ] ?? null;
	}

	public function query( ReviewRateLimitPreparedQuery $query ) {
		$this->last_error = '';
		if ( false !== strpos( $query->sql, 'INSERT IGNORE' ) ) {
			if ( $this->fail_inserts ) {
				$this->last_error = 'database insert failed';
				return false;
			}
			list( $name, $value ) = $query->args;
			if ( $this->insert_zero_conflicts > 0 ) {
				--$this->insert_zero_conflicts;
				return 0;
			}
			if ( $this->insert_conflicts > 0 ) {
				--$this->insert_conflicts;
				$this->rows[ $name ] = substr( $value, 0, 11 ) . json_encode( [ [ time(), str_repeat( 'f', 64 ) ] ] );
				return 0;
			}
			if ( isset( $this->rows[ $name ] ) ) {
				return 0;
			}
			$this->rows[ $name ] = $value;
			return 1;
		}
		if ( false !== strpos( $query->sql, 'UPDATE' ) ) {
			if ( $this->fail_updates ) {
				$this->last_error = 'database update failed';
				return false;
			}
			list( $value, $name, $expected ) = $query->args;
			if ( $this->update_conflicts > 0 ) {
				--$this->update_conflicts;
				$entries               = json_decode( substr( $expected, 11 ), true );
				$entries[]             = [ time(), $this->conflict_ip_hash ?? str_repeat( 'f', 64 ) ];
				$this->rows[ $name ]   = substr( $expected, 0, 11 ) . json_encode( $entries );
				return 0;
			}
			if ( ! isset( $this->rows[ $name ] ) || $this->rows[ $name ] !== $expected ) {
				return 0;
			}
			$this->rows[ $name ] = $value;
			return 1;
		}
		if ( false !== strpos( $query->sql, 'DELETE' ) ) {
			if ( $this->fail_deletes ) {
				$this->last_error = 'database delete failed';
				return false;
			}
			list( $name, $expected ) = $query->args;
			if ( $this->refresh_before_delete ) {
				$this->rows[ $name ] = sprintf( '%010d:', time() + 600 ) . json_encode( [ [ time(), str_repeat( 'f', 64 ) ] ] );
				$this->refresh_before_delete = false;
			}
			if ( isset( $this->rows[ $name ] ) && $this->rows[ $name ] === $expected ) {
				unset( $this->rows[ $name ] );
				return 1;
			}
			return 0;
		}
		return false;
	}

	public function get_col( ReviewRateLimitPreparedQuery $query ) {
		if ( $this->fail_seed ) {
			$this->last_error = 'database seed read failed';
			return false;
		}
		$this->last_error = '';
		return $this->recent_timestamps;
	}

	public function get_results( ReviewRateLimitPreparedQuery $query, $format ) {
		$cutoff = $query->args[1];
		$rows = [];
		foreach ( $this->rows as $name => $value ) {
			if ( 0 === strpos( $name, 'previewshare_review_rate_' ) && strcmp( $value, $cutoff ) < 0 ) {
				$rows[] = [ 'option_name' => $name, 'option_value' => $value ];
			}
		}
		usort( $rows, static function ( array $left, array $right ): int { return strcmp( $left['option_value'], $right['option_value'] ); } );
		return array_slice( $rows, 0, 100 );
	}
}

class ReviewControllerTest extends TestCase {
	/** @var mixed */
	private $previous_wpdb;
	/** @var WP_Post */
	private $post;

	/** @var string */
	private $link_hash = 'link-hash-for-review';

	protected function setUp(): void {
		parent::setUp();
		global $wpdb;
		$this->previous_wpdb = $wpdb ?? null;
		$wpdb                 = new ReviewRateLimitWpdb();
		$this->post = new WP_Post(
			[
				'ID' => 42,
				'post_type' => 'post',
				'post_status' => 'draft',
				'post_title' => 'Review draft',
				'post_content' => 'Version A',
			]
		);

		Functions\when( '__' )->returnArg( 1 );
		Functions\when( 'wp_salt' )->justReturn( 'test-site-secret' );
		Functions\when( 'get_post' )->alias(
			function () {
				return $this->post;
			}
		);
		Functions\when( 'get_post_meta' )->alias(
			static function ( int $post_id, ?string $key = null, bool $single = false ) {
				return '_previewshare_enabled' === $key ? true : [];
			}
		);
		Functions\when( 'get_object_taxonomies' )->justReturn( [] );
		Functions\when( 'get_post_types' )->justReturn( [ 'post' => (object) [ 'label' => 'Posts', 'labels' => (object) [ 'singular_name' => 'Post' ] ] ] );
		Functions\when( 'is_post_type_viewable' )->justReturn( true );
		Functions\when( 'get_option' )->alias(
			static function ( string $name, $default = false ) {
				return 'previewshare_post_types' === $name ? [ 'post' ] : $default;
			}
		);
		Functions\when( 'add_action' )->justReturn( null );
		Functions\when( 'get_transient' )->justReturn( 0 );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'wp_unslash' )->returnArg( 1 );
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'get_posts' )->justReturn( [] );
		Functions\when( 'sanitize_email' )->returnArg( 1 );
		Functions\when( 'sanitize_textarea_field' )->alias( 'trim' );
	}

	protected function tearDown(): void {
		global $wpdb;
		$wpdb = $this->previous_wpdb;
		parent::tearDown();
	}

	public function test_approval_opened_on_an_older_version_is_rejected(): void {
		$snapshot = ReviewVersion::issue_snapshot( $this->post, $this->link_hash );
		$this->post->post_content = 'Version B';
		$response = $this->controller()->submit( $this->request( $snapshot ) );

		self::assertInstanceOf( WP_Error::class, $response );
		self::assertSame( 'review_stale_version', $response->get_error_code() );
		self::assertSame( [ 'status' => 409 ], $response->get_error_data() );
	}

	public function test_current_version_approval_is_saved_against_the_presented_version(): void {
		$snapshot   = ReviewVersion::issue_snapshot( $this->post, $this->link_hash );
		$fingerprint = ReviewVersion::fingerprint( $this->post );
		Functions\expect( 'add_option' )->once()->andReturn( true );
		Functions\expect( 'current_time' )->once()->with( 'mysql', true )->andReturn( '2026-09-25 18:00:00' );
		Functions\expect( 'get_date_from_gmt' )->once()->with( '2026-09-25 18:00:00' )->andReturn( '2026-09-25 23:30:00' );
		Functions\expect( 'wp_insert_post' )->once()->andReturn( 73 );
		Functions\expect( 'add_post_meta' )->times( 6 )->andReturn( true );
		Functions\expect( 'update_option' )->once()->with( \Mockery::type( 'string' ), 73, false )->andReturn( true );
		Functions\expect( 'do_action' )->once();

		$response = $this->controller()->submit( $this->request( $snapshot ) );

		self::assertInstanceOf( WP_REST_Response::class, $response );
		self::assertSame( 201, $response->get_status() );
		self::assertSame( [ 'received' => true, 'response_type' => 'approve' ], $response->get_data() );
	}

	public function test_rate_limit_reserves_atomically_after_competing_initial_insert(): void {
		global $wpdb;
		$previous_wpdb = $wpdb ?? null;
		$wpdb          = new ReviewRateLimitWpdb();
		$method        = new \ReflectionMethod( ReviewController::class, 'within_rate_limit' );
		$controller    = new ReviewController( \Mockery::mock( PostMetaStorage::class ), new ReviewResponseService() );
		$_SERVER['REMOTE_ADDR'] = '192.0.2.10';
		$wpdb->insert_conflicts = 1;

		try {
			$this->assertTrue( $method->invoke( $controller, $this->link_hash ) );
			$this->assertTrue( $method->invoke( $controller, $this->link_hash ) );
			$state = json_decode( substr( reset( $wpdb->rows ), 11 ), true );
			$this->assertCount( 3, $state );
			$this->assertStringNotContainsString( '192.0.2.10', reset( $wpdb->rows ) );
		} finally {
			$wpdb = $previous_wpdb;
			unset( $_SERVER['REMOTE_ADDR'] );
		}
	}

	public function test_rate_limit_retries_lost_compare_and_swap(): void {
		global $wpdb;
		$previous_wpdb = $wpdb ?? null;
		$wpdb          = new ReviewRateLimitWpdb();
		$method        = new \ReflectionMethod( ReviewController::class, 'within_rate_limit' );
		$controller    = new ReviewController( \Mockery::mock( PostMetaStorage::class ), new ReviewResponseService() );
		$_SERVER['REMOTE_ADDR'] = '192.0.2.11';

		try {
			$this->assertTrue( $method->invoke( $controller, $this->link_hash ) );
			$wpdb->update_conflicts = 1;
			$this->assertTrue( $method->invoke( $controller, $this->link_hash ) );
			$state = json_decode( substr( reset( $wpdb->rows ), 11 ), true );
			$this->assertCount( 3, $state );
		} finally {
			$wpdb = $previous_wpdb;
			unset( $_SERVER['REMOTE_ADDR'] );
		}
	}

	public function test_rate_limit_reserves_without_option_cache_or_transient_writes(): void {
		global $wpdb;
		$previous_wpdb = $wpdb ?? null;
		$wpdb          = new ReviewRateLimitWpdb();
		$method        = new \ReflectionMethod( ReviewController::class, 'within_rate_limit' );
		$controller    = new ReviewController( \Mockery::mock( PostMetaStorage::class ), new ReviewResponseService() );
		$remote        = '192.0.2.14';
		$expected_key  = 'previewshare_review_' . substr( hash_hmac( 'sha256', $this->link_hash . ':' . $remote, 'test-site-secret' ), 0, 32 );
		$transient_reads = 0;
		Functions\when( 'get_transient' )->alias(
			static function ( string $key ) use ( $expected_key, &$transient_reads ) {
				if ( $key !== $expected_key ) {
					throw new \RuntimeException( 'Unexpected transient read.' );
				}
				++$transient_reads;
				return 0;
			}
		);
		Functions\expect( 'set_transient' )->never();
		Functions\expect( 'get_option' )->never();
		$_SERVER['REMOTE_ADDR'] = $remote;

		try {
			$this->assertTrue( $method->invoke( $controller, $this->link_hash ) );
			$this->assertSame( 1, $transient_reads );
		} finally {
			$wpdb = $previous_wpdb;
			unset( $_SERVER['REMOTE_ADDR'] );
		}
	}

	public function test_rate_limit_seeds_an_absent_ledger_from_recent_responses(): void {
		global $wpdb;
		$previous_wpdb = $wpdb ?? null;
		$wpdb          = new ReviewRateLimitWpdb();
		$wpdb->recent_timestamps = array_fill( 0, 98, gmdate( 'Y-m-d H:i:s', time() - 5 ) );
		$method     = new \ReflectionMethod( ReviewController::class, 'within_rate_limit' );
		$controller = new ReviewController( \Mockery::mock( PostMetaStorage::class ), new ReviewResponseService() );

		try {
			for ( $index = 0; $index < 2; $index++ ) {
				$_SERVER['REMOTE_ADDR'] = '198.51.100.' . ( 1 + $index );
				$this->assertTrue( $method->invoke( $controller, $this->link_hash ) );
			}
			$_SERVER['REMOTE_ADDR'] = '198.51.100.3';
			$this->assertFalse( $method->invoke( $controller, $this->link_hash ) );
			$state = json_decode( substr( reset( $wpdb->rows ), 11 ), true );
			$this->assertCount( 100, $state );
			$this->assertSame( str_repeat( '0', 64 ), $state[0][1] );
		} finally {
			$wpdb = $previous_wpdb;
			unset( $_SERVER['REMOTE_ADDR'] );
		}
	}

	public function test_rate_limit_fails_closed_when_legacy_seed_query_fails(): void {
		global $wpdb;
		$previous_wpdb = $wpdb ?? null;
		$wpdb          = new ReviewRateLimitWpdb();
		$wpdb->fail_seed = true;
		$method     = new \ReflectionMethod( ReviewController::class, 'within_rate_limit' );
		$controller = new ReviewController( \Mockery::mock( PostMetaStorage::class ), new ReviewResponseService() );
		$_SERVER['REMOTE_ADDR'] = '192.0.2.15';

		try {
			$this->assertFalse( $method->invoke( $controller, $this->link_hash ) );
			$this->assertSame( [], $wpdb->rows );
		} finally {
			$wpdb = $previous_wpdb;
			unset( $_SERVER['REMOTE_ADDR'] );
		}
	}

	public function test_rate_limit_bridges_legacy_per_ip_transient_until_it_expires(): void {
		global $wpdb;
		$previous_wpdb = $wpdb ?? null;
		$wpdb          = new ReviewRateLimitWpdb();
		$method        = new \ReflectionMethod( ReviewController::class, 'within_rate_limit' );
		$controller    = new ReviewController( \Mockery::mock( PostMetaStorage::class ), new ReviewResponseService() );
		$remote        = '192.0.2.16';
		$_SERVER['REMOTE_ADDR'] = $remote;
		$legacy_key = 'previewshare_review_' . substr( hash_hmac( 'sha256', $this->link_hash . ':' . $remote, 'test-site-secret' ), 0, 32 );
		$transient_reads = 0;
		Functions\when( 'get_transient' )->alias(
			static function ( string $key ) use ( $legacy_key, &$transient_reads ) {
				if ( $key !== $legacy_key ) {
					throw new \RuntimeException( 'Unexpected transient read.' );
				}
				++$transient_reads;
				return 8;
			}
		);

		try {
			$this->assertTrue( $method->invoke( $controller, $this->link_hash ) );
			$this->assertTrue( $method->invoke( $controller, $this->link_hash ) );
			$state = json_decode( substr( reset( $wpdb->rows ), 11 ), true );
			$this->assertCount( 2, $state );
			$this->assertFalse( $method->invoke( $controller, $this->link_hash ) );
			$this->assertSame( 3, $transient_reads );
		} finally {
			$wpdb = $previous_wpdb;
			unset( $_SERVER['REMOTE_ADDR'] );
		}
	}

	public function test_rate_limit_fails_closed_on_insert_update_errors_and_contention_exhaustion(): void {
		global $wpdb;
		$previous_wpdb = $wpdb ?? null;
		$method        = new \ReflectionMethod( ReviewController::class, 'within_rate_limit' );
		$controller    = new ReviewController( \Mockery::mock( PostMetaStorage::class ), new ReviewResponseService() );
		$_SERVER['REMOTE_ADDR'] = '192.0.2.17';

		try {
			$wpdb = new ReviewRateLimitWpdb();
			$wpdb->fail_inserts = true;
			$this->assertFalse( $method->invoke( $controller, $this->link_hash ) );

			$wpdb = new ReviewRateLimitWpdb();
			$wpdb->rows[ 'previewshare_review_rate_' . substr( hash( 'sha256', $this->link_hash ), 0, 32 ) ] = sprintf( '%010d:', time() + 600 ) . '[]';
			$wpdb->fail_updates = true;
			$this->assertFalse( $method->invoke( $controller, $this->link_hash ) );

			$wpdb = new ReviewRateLimitWpdb();
			$wpdb->insert_zero_conflicts = 3;
			$this->assertFalse( $method->invoke( $controller, $this->link_hash ) );
			$this->assertSame( [], $wpdb->rows );
		} finally {
			$wpdb = $previous_wpdb;
			unset( $_SERVER['REMOTE_ADDR'] );
		}
	}

	public function test_rate_limit_rechecks_quota_after_competitor_takes_final_slot(): void {
		global $wpdb;
		$previous_wpdb = $wpdb ?? null;
		$method        = new \ReflectionMethod( ReviewController::class, 'within_rate_limit' );
		$controller    = new ReviewController( \Mockery::mock( PostMetaStorage::class ), new ReviewResponseService() );
		$remote        = '192.0.2.18';
		$ip_hash       = hash_hmac( 'sha256', $this->link_hash . ':' . $remote, 'test-site-secret' );
		$name          = 'previewshare_review_rate_' . substr( hash( 'sha256', $this->link_hash ), 0, 32 );
		$_SERVER['REMOTE_ADDR'] = $remote;

		try {
			$wpdb = new ReviewRateLimitWpdb();
			$wpdb->rows[ $name ] = sprintf( '%010d:', time() + 600 ) . json_encode( array_fill( 0, 9, [ time(), $ip_hash ] ) );
			$wpdb->conflict_ip_hash = $ip_hash;
			$wpdb->update_conflicts = 1;
			$this->assertFalse( $method->invoke( $controller, $this->link_hash ) );

			$wpdb = new ReviewRateLimitWpdb();
			$wpdb->rows[ $name ] = sprintf( '%010d:', time() + 600 ) . json_encode( array_fill( 0, 99, [ time(), str_repeat( '0', 64 ) ] ) );
			$wpdb->update_conflicts = 1;
			$this->assertFalse( $method->invoke( $controller, $this->link_hash ) );
		} finally {
			$wpdb = $previous_wpdb;
			unset( $_SERVER['REMOTE_ADDR'] );
		}
	}

	public function test_rate_limit_decoder_rejects_two_property_objects(): void {
		$method = new \ReflectionMethod( ReviewController::class, 'decode_rate_limit_state' );
		$value  = sprintf( '%010d:', time() + 600 ) . json_encode( [ (object) [ 'time' => time(), 'ip_hash' => str_repeat( 'a', 64 ) ] ] );

		$this->assertFalse( $method->invoke( new ReviewController( \Mockery::mock( PostMetaStorage::class ), new ReviewResponseService() ), $value ) );
	}

	public function test_rate_limit_enforces_ip_and_link_quotas(): void {
		global $wpdb;
		$previous_wpdb = $wpdb ?? null;
		$wpdb          = new ReviewRateLimitWpdb();
		$method        = new \ReflectionMethod( ReviewController::class, 'within_rate_limit' );
		$controller    = new ReviewController( \Mockery::mock( PostMetaStorage::class ), new ReviewResponseService() );

		try {
			$_SERVER['REMOTE_ADDR'] = '192.0.2.12';
			for ( $index = 0; $index < 10; $index++ ) {
				$this->assertTrue( $method->invoke( $controller, $this->link_hash ) );
			}
			$this->assertFalse( $method->invoke( $controller, $this->link_hash ) );

			$wpdb->rows = [];
			for ( $index = 0; $index < 100; $index++ ) {
				$_SERVER['REMOTE_ADDR'] = '198.51.100.' . ( 1 + ( $index % 200 ) );
				$this->assertTrue( $method->invoke( $controller, $this->link_hash ) );
			}
			$_SERVER['REMOTE_ADDR'] = '203.0.113.1';
			$this->assertFalse( $method->invoke( $controller, $this->link_hash ) );
		} finally {
			$wpdb = $previous_wpdb;
			unset( $_SERVER['REMOTE_ADDR'] );
		}
	}

	public function test_rate_limit_expires_reservations_and_fails_closed_on_database_error(): void {
		global $wpdb;
		$previous_wpdb = $wpdb ?? null;
		$wpdb          = new ReviewRateLimitWpdb();
		$method        = new \ReflectionMethod( ReviewController::class, 'within_rate_limit' );
		$controller    = new ReviewController( \Mockery::mock( PostMetaStorage::class ), new ReviewResponseService() );
		$_SERVER['REMOTE_ADDR'] = '192.0.2.13';

		try {
			$name = 'previewshare_review_rate_' . substr( hash( 'sha256', $this->link_hash ), 0, 32 );
			$ip_hash = hash_hmac( 'sha256', $this->link_hash . ':192.0.2.13', 'test-site-secret' );
			$wpdb->rows[ $name ] = sprintf( '%010d:', time() - 1 ) . json_encode( [ [ time() - 601, $ip_hash ] ] );
			$this->assertTrue( $method->invoke( $controller, $this->link_hash ) );
			$state = json_decode( substr( $wpdb->rows[ $name ], 11 ), true );
			$this->assertCount( 1, $state );
			$wpdb->fail_reads = true;
			$this->assertFalse( $method->invoke( $controller, $this->link_hash ) );
		} finally {
			$wpdb = $previous_wpdb;
			unset( $_SERVER['REMOTE_ADDR'] );
		}
	}

	public function test_rate_limit_cleanup_does_not_delete_a_concurrently_refreshed_row(): void {
		global $wpdb;
		$previous_wpdb = $wpdb ?? null;
		$wpdb          = new ReviewRateLimitWpdb();
		$name          = 'previewshare_review_rate_' . str_repeat( 'a', 32 );
		$wpdb->rows[ $name ] = sprintf( '%010d:', time() - 1 ) . '[]';
		$wpdb->refresh_before_delete = true;
		$controller = new ReviewController( \Mockery::mock( PostMetaStorage::class ), new ReviewResponseService() );

		try {
			$controller->cleanup_rate_limits();
			$this->assertArrayHasKey( $name, $wpdb->rows );
		} finally {
			$wpdb = $previous_wpdb;
		}
	}

	public function test_rate_limit_cleanup_schedules_continuation_for_a_full_batch(): void {
		global $wpdb;
		$previous_wpdb = $wpdb ?? null;
		$wpdb          = new ReviewRateLimitWpdb();
		for ( $index = 0; $index < 100; $index++ ) {
			$wpdb->rows[ 'previewshare_review_rate_' . sprintf( '%032d', $index ) ] = sprintf( '%010d:', time() - 1 ) . '[]';
		}
		Functions\expect( 'wp_next_scheduled' )->once()->with( 'previewshare_cleanup_reviews_continue' )->andReturn( false );
		Functions\expect( 'wp_schedule_single_event' )->once()->with( \Mockery::type( 'int' ), 'previewshare_cleanup_reviews_continue' )->andReturn( true );
		$controller = new ReviewController( \Mockery::mock( PostMetaStorage::class ), new ReviewResponseService() );

		try {
			$controller->cleanup_rate_limits();
			$this->assertSame( [], $wpdb->rows );
		} finally {
			$wpdb = $previous_wpdb;
		}
	}

	public function test_rate_limit_cleanup_does_not_schedule_again_after_delete_error(): void {
		global $wpdb;
		$previous_wpdb = $wpdb ?? null;
		$wpdb          = new ReviewRateLimitWpdb();
		for ( $index = 0; $index < 100; $index++ ) {
			$wpdb->rows[ 'previewshare_review_rate_' . sprintf( '%032d', $index ) ] = sprintf( '%010d:', time() - 1 ) . '[]';
		}
		$wpdb->fail_deletes = true;
		Functions\expect( 'wp_next_scheduled' )->never();
		Functions\expect( 'wp_schedule_single_event' )->never();
		$controller = new ReviewController( \Mockery::mock( PostMetaStorage::class ), new ReviewResponseService() );

		try {
			$controller->cleanup_rate_limits();
		} finally {
			$wpdb = $previous_wpdb;
		}
	}

	private function controller(): ReviewController {
		$storage = \Mockery::mock( PostMetaStorage::class );
		$storage->shouldReceive( 'get_link_by_token' )
			->once()
			->with( str_repeat( 'a', 48 ) )
			->andReturn(
				[
					'post_id' => 42,
					'hash' => $this->link_hash,
					'link' => [ 'responses_enabled' => true ],
				]
			);

		return new ReviewController( $storage, new ReviewResponseService() );
	}

	private function request( string $snapshot ): WP_REST_Request {
		return new WP_REST_Request(
			[
				'token' => str_repeat( 'a', 48 ),
				'response_type' => 'approve',
				'request_id' => 'unit-test-request-123456',
				'content_snapshot' => $snapshot,
			]
		);
	}
}
