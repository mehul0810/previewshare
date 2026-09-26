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
}
