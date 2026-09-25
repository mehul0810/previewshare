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
		Functions\expect( 'wp_insert_post' )
			->once()
			->andReturnUsing(
				static function ( array $data, bool $return_error ): int {
					self::assertTrue( $return_error );
					self::assertSame( 'previewshare_review', $data['post_type'] );
					self::assertSame( 'private', $data['post_status'] );
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
		Functions\expect( 'wp_insert_post' )->once()->andReturn( new WP_Error( 'db_error' ) );
		Functions\expect( 'delete_option' )->once()->andReturn( true );

		$result = ( new ReviewResponseService() )->create_response( 42, 'link', 'version', 'approve', '', '', '', 'request' );

		self::assertInstanceOf( WP_Error::class, $result );
		self::assertSame( 'review_storage_failed', $result->get_error_code() );
	}
}
