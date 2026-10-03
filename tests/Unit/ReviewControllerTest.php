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

class ReviewControllerTest extends TestCase {
	/** @var WP_Post */
	private $post;

	/** @var string */
	private $link_hash = 'link-hash-for-review';

	protected function setUp(): void {
		parent::setUp();
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
		Functions\when( 'get_posts' )->justReturn( [] );
		Functions\when( 'sanitize_email' )->returnArg( 1 );
		Functions\when( 'sanitize_textarea_field' )->alias( 'trim' );
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
