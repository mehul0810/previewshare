<?php
/**
 * Preview REST controller tests.
 *
 * @package PreviewShare
 */

namespace PreviewShare\Tests\Unit;

use Brain\Monkey\Functions;
use Mockery;
use PreviewShare\REST\PreviewController;
use PreviewShare\Services\PostMetaStorage;
use PreviewShare\Services\TokenService;
use WP_Error;
use WP_Post;
use WP_REST_Request;
use WP_REST_Response;

class PreviewControllerTest extends TestCase {

	public function test_list_tokens_returns_enriched_response(): void {
		$storage    = Mockery::mock( PostMetaStorage::class );
		$controller = $this->makeController( $storage );

		$storage->shouldReceive( 'list_tokens' )
			->once()
			->with( 25, 2 )
			->andReturn(
				[
					[
						'id'             => 'abc',
						'post_id'        => 42,
						'label'          => 'Client review',
						'created_at'     => 100,
						'expires_at'     => null,
						'revoked'        => false,
						'expired'        => false,
						'status'         => 'active',
						'view_count'     => 3,
						'last_viewed_at' => null,
					],
				]
			);
		$storage->shouldReceive( 'count_tokens' )->once()->andReturn( 1 );

		Functions\expect( 'get_post' )
			->once()
			->with( 42 )
			->andReturn( new WP_Post( [ 'ID' => 42, 'post_type' => 'post' ] ) );
		Functions\expect( 'get_the_title' )->once()->andReturn( 'Draft post' );
		Functions\expect( 'get_current_user_id' )->once()->andReturn( 7 );
		Functions\expect( 'get_option' )->once()->with( 'previewshare_enable_logging', false )->andReturn( false );
		Functions\expect( 'do_action' )->never();

		$response = $controller->list_tokens( new WP_REST_Request( [ 'per_page' => 25, 'page' => 2 ] ) );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'Draft post', $response->get_data()['items'][0]['post_title'] );
		$this->assertSame( 1, $response->get_data()['total'] );
	}

	public function test_revoke_by_id_rejects_empty_sanitized_id(): void {
		$controller = $this->makeController();

		$response = $controller->revoke_by_id( new WP_REST_Request( [ 'id' => '###' ] ) );

		$this->assertInstanceOf( WP_Error::class, $response );
		$this->assertSame( 'invalid_id', $response->get_error_code() );
		$this->assertSame( [ 'status' => 400 ], $response->get_error_data() );
	}

	public function test_update_settings_resets_defaults_when_requested(): void {
		$controller = $this->makeController();

		Functions\expect( 'get_current_user_id' )->once()->andReturn( 9 );
		Functions\expect( 'do_action' )->never();
		Functions\expect( 'get_post_types' )
			->times( 3 )
			->with( [ 'public' => true ], 'objects' )
			->andReturn(
				[
					'post' => (object) [
						'label'  => 'Posts',
						'labels' => (object) [ 'singular_name' => 'Post' ],
					],
				]
			);
		Functions\expect( 'is_post_type_viewable' )->times( 3 )->andReturn( true );
		Functions\expect( 'update_option' )->times( 4 )->andReturn( true );
		Functions\expect( 'get_option' )
			->times( 5 )
			->andReturnUsing(
				static function( string $option, $default = false ) {
					$values = [
						'previewshare_post_types'        => [ 'post' ],
						'previewshare_default_ttl_hours' => 6,
						'previewshare_enable_logging'    => false,
						'previewshare_enable_caching'    => true,
					];

					return $values[ $option ] ?? $default;
				}
			);

		$response = $controller->update_settings( new WP_REST_Request( [ 'reset_defaults' => true ] ) );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 6, $response->get_data()['default_ttl_hours'] );
		$this->assertSame( [ 'post' ], $response->get_data()['post_types'] );
	}

	private function makeController( ?PostMetaStorage $storage = null ): PreviewController {
		Functions\expect( 'add_action' )->once()->with( 'rest_api_init', Mockery::type( 'array' ) );
		Functions\expect( 'did_action' )->once()->with( 'rest_api_init' )->andReturn( 0 );

		return new PreviewController( new TokenService(), $storage ?: Mockery::mock( PostMetaStorage::class ) );
	}
}
