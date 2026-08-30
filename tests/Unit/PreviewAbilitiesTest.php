<?php
/**
 * Preview Abilities API integration tests.
 *
 * @package PreviewShare
 */

namespace PreviewShare\Tests\Unit;

use Brain\Monkey\Functions;
use Mockery;
use PreviewShare\Abilities\PreviewAbilities;
use PreviewShare\Container;
use PreviewShare\Services\PostMetaStorage;
use PreviewShare\Services\TokenService;
use WP_Error;
use WP_Post;

class PreviewAbilitiesTest extends TestCase {

	public function test_does_not_register_hooks_when_the_abilities_api_is_unavailable(): void {
		Functions\expect( 'add_action' )->never();

		new PreviewAbilities( new TokenService(), Mockery::mock( PostMetaStorage::class ) );

		$this->assertFalse( PreviewAbilities::is_available() );
	}

	public function test_registers_category_and_three_rest_exposed_abilities(): void {
		$category  = [];
		$abilities = [];

		Functions\when( '__' )->returnArg( 1 );
		Functions\expect( 'add_action' )
			->twice()
			->with( Mockery::type( 'string' ), Mockery::type( 'array' ) );
		Functions\expect( 'wp_register_ability_category' )
			->once()
			->andReturnUsing(
				static function ( string $slug, array $args ) use ( &$category ) {
					$category = [
						'slug' => $slug,
						'args' => $args,
					];

					return null;
				}
			);
		Functions\expect( 'wp_register_ability' )
			->times( 3 )
			->andReturnUsing(
				static function ( string $name, array $args ) use ( &$abilities ) {
					$abilities[ $name ] = $args;

					return null;
				}
			);

		$abilities_api = $this->makeAbilities();
		$abilities_api->register_category();
		$abilities_api->register_abilities();

		$this->assertSame( 'previewshare', $category['slug'] );
		$this->assertSame( 'PreviewShare', $category['args']['label'] );
		$this->assertSame(
			[
				'previewshare/generate-preview-link',
				'previewshare/list-preview-links',
				'previewshare/revoke-preview-link',
			],
			array_keys( $abilities )
		);

		foreach ( $abilities as $ability ) {
			$this->assertSame( 'previewshare', $ability['category'] );
			$this->assertIsArray( $ability['input_schema'] );
			$this->assertIsArray( $ability['output_schema'] );
			$this->assertIsCallable( $ability['execute_callback'] );
			$this->assertIsCallable( $ability['permission_callback'] );
			$this->assertTrue( $ability['meta']['show_in_rest'] );
		}

		$this->assertSame( [ 'post_id' ], $abilities['previewshare/generate-preview-link']['input_schema']['required'] );
		$this->assertSame( [ 'token_id' ], $abilities['previewshare/revoke-preview-link']['input_schema']['required'] );
	}

	public function test_generate_permission_requires_edit_post_capability(): void {
		$abilities = $this->makeAbilities();

		Functions\expect( 'current_user_can' )
			->once()
			->with( 'edit_post', 42 )
			->andReturn( false );
		Functions\when( '__' )->returnArg( 1 );

		$result = $abilities->can_generate_preview_link( [ 'post_id' => 42 ] );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'previewshare_forbidden', $result->get_error_code() );
		$this->assertSame( [ 'status' => 403 ], $result->get_error_data() );
	}

	public function test_generate_permission_rejects_disabled_post_types(): void {
		$abilities = $this->makeAbilities();

		Functions\when( '__' )->returnArg( 1 );
		Functions\expect( 'current_user_can' )
			->once()
			->with( 'edit_post', 42 )
			->andReturn( true );
		Functions\expect( 'get_post' )
			->once()
			->with( 42 )
			->andReturn( new WP_Post( [ 'ID' => 42, 'post_type' => 'product' ] ) );
		$this->mockSupportedPostTypes();

		$result = $abilities->can_generate_preview_link( [ 'post_id' => 42 ] );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'previewshare_unsupported_post_type', $result->get_error_code() );
	}

	public function test_generate_preview_link_returns_structured_link_data(): void {
		$storage       = Mockery::mock( PostMetaStorage::class );
		$token_service = Mockery::mock( TokenService::class );
		$abilities     = $this->makeAbilities( $storage, $token_service );
		$post          = new WP_Post(
			[
				'ID'          => 42,
				'post_type'   => 'post',
				'post_status' => 'draft',
			]
		);

		Functions\expect( 'get_post' )->once()->with( 42 )->andReturn( $post );
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
		$token_service->shouldReceive( 'hash' )->once()->with( 'generated-token' )->andReturn( 'token-hash' );
		$storage->shouldReceive( 'get_post_id_by_token' )
			->once()
			->with( 'generated-token' )
			->andReturn( false );
		$storage->shouldReceive( 'store_token' )
			->once()
			->with( 42, 'generated-token', 12, 'Client review' )
			->andReturn( true );
		$storage->shouldReceive( 'get_token_context_by_id' )
			->once()
			->with( 'token-hash' )
			->andReturn(
				[
					'id'         => 'token-hash',
					'post_id'    => 42,
					'label'      => 'Client review',
					'expires_at' => 123456,
					'status'     => 'active',
				]
			);

		$result = $abilities->generate_preview_link(
			[
				'post_id' => 42,
				'label'   => '<b>Client review</b>',
			]
		);

		$this->assertSame(
			[
				'url'        => 'https://example.test/preview/generated-token',
				'token_id'   => 'token-hash',
				'post_id'    => 42,
				'status'     => 'active',
				'expires_at' => 123456,
				'label'      => 'Client review',
			],
			$result
		);
	}

	public function test_list_preview_links_requires_admin_permission_and_returns_existing_inventory(): void {
		$storage   = Mockery::mock( PostMetaStorage::class );
		$abilities = $this->makeAbilities( $storage );

		Functions\when( '__' )->returnArg( 1 );
		Functions\expect( 'current_user_can' )->once()->with( 'manage_options' )->andReturn( false );

		$permission = $abilities->can_manage_preview_links();

		$this->assertInstanceOf( WP_Error::class, $permission );

		$storage->shouldReceive( 'list_tokens' )
			->once()
			->with( 25, 2 )
			->andReturn(
				[
					[
						'id'         => 'token-hash',
						'post_id'    => 42,
						'label'      => 'Client review',
						'created_at' => 100,
						'expires_at' => null,
						'status'     => 'active',
					],
				]
			);
		$storage->shouldReceive( 'count_tokens' )->once()->andReturn( 1 );

		$result = $abilities->list_preview_links( [ 'per_page' => 25, 'page' => 2 ] );

		$this->assertSame( 1, $result['total'] );
		$this->assertSame( 2, $result['page'] );
		$this->assertSame( 'token-hash', $result['items'][0]['token_id'] );
		$this->assertSame( 42, $result['items'][0]['post_id'] );
	}

	public function test_revoke_preview_link_uses_existing_admin_revoke_service(): void {
		$storage   = Mockery::mock( PostMetaStorage::class );
		$abilities = $this->makeAbilities( $storage );

		$storage->shouldReceive( 'get_token_context_by_id' )
			->once()
			->with( 'token-hash' )
			->andReturn(
				[
					'id'         => 'token-hash',
					'post_id'    => 42,
					'label'      => 'Client review',
					'expires_at' => null,
					'status'     => 'active',
				]
			);
		$storage->shouldReceive( 'revoke_token_by_id' )
			->once()
			->with( 'token-hash' )
			->andReturn( true );

		$result = $abilities->revoke_preview_link( [ 'token_id' => 'token-hash' ] );

		$this->assertSame(
			[
				'token_id' => 'token-hash',
				'post_id'  => 42,
				'status'   => 'revoked',
				'revoked'  => true,
			],
			$result
		);
	}

	private function makeAbilities( ?PostMetaStorage $storage = null, ?TokenService $token_service = null ): PreviewAbilities {
		$storage       = $storage ?: Mockery::mock( PostMetaStorage::class );
		$token_service = $token_service ?: new TokenService();

		Container::set( 'storage', $storage );
		Container::set( 'token_service', $token_service );

		return new PreviewAbilities( $token_service, $storage );
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
			static function ( string $option, $default = false ) {
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
