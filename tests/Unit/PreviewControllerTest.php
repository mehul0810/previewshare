<?php
/**
 * Preview REST controller tests.
 *
 * @package PreviewShare
 */

namespace PreviewShare\Tests\Unit;

use Brain\Monkey\Functions;
use Mockery;
use PreviewShare\Container;
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
		Functions\expect( 'get_edit_post_link' )
			->once()
			->with( 42, 'raw' )
			->andReturn( 'https://example.test/wp-admin/post.php?post=42&action=edit' );
		Functions\expect( 'get_current_user_id' )->once()->andReturn( 7 );
		Functions\expect( 'get_option' )->once()->with( 'previewshare_enable_logging', false )->andReturn( false );
		Functions\expect( 'do_action' )->never();

		$response = $controller->list_tokens( new WP_REST_Request( [ 'per_page' => 25, 'page' => 2 ] ) );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'Draft post', $response->get_data()['items'][0]['post_title'] );
		$this->assertSame( 'https://example.test/wp-admin/post.php?post=42&action=edit', $response->get_data()['items'][0]['edit_url'] );
		$this->assertSame( 1, $response->get_data()['total'] );
	}

	public function test_revoke_by_id_rejects_empty_sanitized_id(): void {
		$controller = $this->makeController();

		$response = $controller->revoke_by_id( new WP_REST_Request( [ 'id' => '###' ] ) );

		$this->assertInstanceOf( WP_Error::class, $response );
		$this->assertSame( 'invalid_id', $response->get_error_code() );
		$this->assertSame( [ 'status' => 400 ], $response->get_error_data() );
	}

	public function test_generate_permission_requires_edit_post_capability(): void {
		$controller = $this->makeController();

		Functions\expect( 'current_user_can' )
			->once()
			->with( 'edit_post', 42 )
			->andReturn( false );

		$this->assertFalse( $controller->permission_generate( new WP_REST_Request( [ 'post_id' => 42 ] ) ) );
	}

	public function test_revoke_permission_falls_back_to_manage_options_without_post_id(): void {
		$controller = $this->makeController();

		Functions\expect( 'current_user_can' )
			->once()
			->with( 'manage_options' )
			->andReturn( true );

		$this->assertTrue( $controller->permission_revoke( new WP_REST_Request() ) );
	}

	public function test_generate_stores_token_for_supported_previewable_post(): void {
		$storage       = Mockery::mock( PostMetaStorage::class );
		$token_service = Mockery::mock( TokenService::class );
		$controller    = $this->makeController( $storage, $token_service );
		$post          = new WP_Post(
			[
				'ID'          => 42,
				'post_type'   => 'post',
				'post_status' => 'draft',
			]
		);

		Functions\expect( 'get_post' )
			->once()
			->with( 42 )
			->andReturn( $post );
		$this->mockSupportedPostTypes();
		Functions\expect( 'get_post_meta' )
			->once()
			->with( 42, '_previewshare_ttl_hours', true )
			->andReturn( '' );
		Functions\expect( 'update_post_meta' )
			->once()
			->with( 42, '_previewshare_enabled', true )
			->andReturn( true );
		Functions\expect( 'home_url' )
			->once()
			->with( '/preview/generated-token' )
			->andReturn( 'https://example.test/preview/generated-token' );

		$token_service->shouldReceive( 'generate' )->once()->andReturn( 'generated-token' );
		$storage->shouldReceive( 'get_post_id_by_token' )
			->once()
			->with( 'generated-token' )
			->andReturn( false );
		$storage->shouldReceive( 'store_token' )
			->once()
			->with( 42, 'generated-token', 12, 'Client review' )
			->andReturn( true );

		$response = $controller->generate(
			new WP_REST_Request(
				[
					'post_id' => 42,
					'label'   => '<b>Client review</b>',
				]
			)
		);

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'https://example.test/preview/generated-token', $response->get_data()['url'] );
		$this->assertSame( 'generated-token', $response->get_data()['token'] );
	}

	public function test_generate_returns_error_when_token_storage_fails(): void {
		$storage       = Mockery::mock( PostMetaStorage::class );
		$token_service = Mockery::mock( TokenService::class );
		$controller    = $this->makeController( $storage, $token_service );
		$post          = new WP_Post(
			[
				'ID'          => 42,
				'post_type'   => 'post',
				'post_status' => 'draft',
			]
		);

		Functions\expect( 'get_post' )
			->once()
			->with( 42 )
			->andReturn( $post );
		$this->mockSupportedPostTypes();
		Functions\expect( 'get_post_meta' )
			->once()
			->with( 42, '_previewshare_ttl_hours', true )
			->andReturn( '' );

		$token_service->shouldReceive( 'generate' )->once()->andReturn( 'generated-token' );
		$storage->shouldReceive( 'get_post_id_by_token' )
			->once()
			->with( 'generated-token' )
			->andReturn( false );
		$storage->shouldReceive( 'store_token' )
			->once()
			->with( 42, 'generated-token', 12, 'Client review' )
			->andReturn( false );

		$response = $controller->generate(
			new WP_REST_Request(
				[
					'post_id' => 42,
					'label'   => '<b>Client review</b>',
				]
			)
		);

		$this->assertInstanceOf( WP_Error::class, $response );
		$this->assertSame( 'token_storage_failed', $response->get_error_code() );
		$this->assertSame( [ 'status' => 500 ], $response->get_error_data() );
	}

	public function test_generate_rejects_unsupported_post_type(): void {
		$controller = $this->makeController();
		$post       = new WP_Post(
			[
				'ID'          => 55,
				'post_type'   => 'product',
				'post_status' => 'draft',
			]
		);

		Functions\expect( 'get_post' )
			->once()
			->with( 55 )
			->andReturn( $post );
		$this->mockSupportedPostTypes();

		$response = $controller->generate( new WP_REST_Request( [ 'post_id' => 55 ] ) );

		$this->assertInstanceOf( WP_Error::class, $response );
		$this->assertSame( 'unsupported_post_type', $response->get_error_code() );
		$this->assertSame( [ 'status' => 400 ], $response->get_error_data() );
	}

	public function test_revoke_post_id_uses_post_scoped_storage(): void {
		$storage    = Mockery::mock( PostMetaStorage::class );
		$controller = $this->makeController( $storage );

		$storage->shouldReceive( 'revoke_token_for_post' )
			->once()
			->with( 42 )
			->andReturn( true );

		$response = $controller->revoke( new WP_REST_Request( [ 'post_id' => 42 ] ) );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $response->get_data()['revoked'] );
	}

	public function test_revoke_requires_token_when_post_id_is_missing(): void {
		$controller = $this->makeController();

		$response = $controller->revoke( new WP_REST_Request() );

		$this->assertInstanceOf( WP_Error::class, $response );
		$this->assertSame( 'invalid_token', $response->get_error_code() );
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

	private function makeController( ?PostMetaStorage $storage = null, ?TokenService $token_service = null ): PreviewController {
		Functions\expect( 'add_action' )->once()->with( 'rest_api_init', Mockery::type( 'array' ) );
		Functions\expect( 'did_action' )->once()->with( 'rest_api_init' )->andReturn( 0 );

		$token_service = $token_service ?: new TokenService();
		$storage       = $storage ?: Mockery::mock( PostMetaStorage::class );

		Container::set( 'token_service', $token_service );
		Container::set( 'storage', $storage );

		return new PreviewController( $token_service, $storage );
	}

	private function mockSupportedPostTypes(): void {
		Functions\expect( 'get_post_types' )
			->twice()
			->with( [ 'public' => true ], 'objects' )
			->andReturn(
				[
					'post' => (object) [
						'label'  => 'Posts',
						'labels' => (object) [ 'singular_name' => 'Post' ],
					],
				]
			);
		Functions\expect( 'is_post_type_viewable' )->twice()->andReturn( true );
		Functions\when( 'get_option' )->alias(
			static function( string $option, $default = false ) {
				$values = [
					'previewshare_post_types'        => [ 'post' ],
					'previewshare_default_ttl_hours' => 12,
					'previewshare_enable_logging'    => false,
					'previewshare_enable_caching'    => true,
				];

				return $values[ $option ] ?? $default;
			}
		);
	}
}
