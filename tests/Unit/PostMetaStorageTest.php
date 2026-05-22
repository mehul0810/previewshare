<?php
/**
 * Post meta storage tests.
 *
 * @package PreviewShare
 */

namespace PreviewShare\Tests\Unit;

use Brain\Monkey\Functions;
use PreviewShare\Services\PostMetaStorage;

class PostMetaStorageTest extends TestCase {

	public function test_store_token_persists_hashed_link_and_cache_entry(): void {
		$storage      = new PostMetaStorage();
		$post_id      = 123;
		$token        = 'raw-token';
		$hash         = hash_hmac( 'sha256', $token, AUTH_SALT );
		$stored_links = null;

		Functions\expect( 'get_post_meta' )
			->twice()
			->andReturnUsing(
				static function( int $requested_post_id, string $key, bool $single ) use ( $post_id ) {
					if ( $requested_post_id !== $post_id || true !== $single ) {
						return null;
					}

					return '_previewshare_links' === $key ? [] : '';
				}
			);
		Functions\expect( 'get_current_user_id' )->once()->andReturn( 7 );
		Functions\expect( 'update_post_meta' )
			->once()
			->withArgs(
				static function( int $requested_post_id, string $key, array $links ) use ( $post_id, $hash, &$stored_links ): bool {
					$stored_links = $links;

					return $requested_post_id === $post_id
						&& '_previewshare_links' === $key
						&& isset( $links[ $hash ] )
						&& 'Legal review' === $links[ $hash ]['label'];
				}
			)
			->andReturn( true );
		Functions\expect( 'update_post_meta' )->times( 4 )->andReturn( true );
		Functions\expect( 'set_transient' )
			->once()
			->with( 'previewshare_token_tr:' . $hash, 1, 2 * HOUR_IN_SECONDS )
			->andReturn( true );
		Functions\expect( 'get_option' )
			->once()
			->with( 'previewshare_enable_caching', true )
			->andReturn( true );
		Functions\expect( 'wp_cache_set' )
			->once()
			->with( $hash, $post_id, 'previewshare_tokens', HOUR_IN_SECONDS )
			->andReturn( true );

		$this->assertTrue( $storage->store_token( $post_id, $token, 2, 'Legal review' ) );
		$this->assertIsArray( $stored_links );
		$this->assertArrayHasKey( $hash, $stored_links );
	}

	public function test_get_link_by_token_returns_null_for_revoked_link_and_clears_cache_when_enabled(): void {
		$storage = new PostMetaStorage();
		$token   = 'revoked-token';
		$hash    = hash_hmac( 'sha256', $token, AUTH_SALT );
		$post_id = 456;

		Functions\expect( 'get_option' )
			->times( 3 )
			->with( 'previewshare_enable_caching', true )
			->andReturn( true );
		Functions\expect( 'wp_cache_get' )
			->once()
			->with( $hash, 'previewshare_tokens' )
			->andReturn( false );
		Functions\expect( 'get_posts' )->once()->andReturn( [ $post_id ] );
		Functions\expect( 'wp_cache_set' )
			->once()
			->with( $hash, $post_id, 'previewshare_tokens', HOUR_IN_SECONDS )
			->andReturn( true );
		Functions\expect( 'get_post_meta' )
			->once()
			->with( $post_id, '_previewshare_links', true )
			->andReturn(
				[
					$hash => [
						'hash'       => $hash,
						'label'      => 'Revoked',
						'created_at' => time() - 60,
						'created_by' => 1,
						'expires_at' => null,
						'revoked'    => 1,
					],
				]
			);
		Functions\expect( 'wp_cache_delete' )
			->once()
			->with( $hash, 'previewshare_tokens' )
			->andReturn( true );

		$this->assertNull( $storage->get_link_by_token( $token ) );
	}

	public function test_record_token_view_updates_active_link_view_fields(): void {
		$storage = new PostMetaStorage();
		$token   = 'active-token';
		$hash    = hash_hmac( 'sha256', $token, AUTH_SALT );
		$post_id = 789;

		Functions\expect( 'get_option' )
			->twice()
			->with( 'previewshare_enable_caching', true )
			->andReturn( false );
		Functions\expect( 'get_posts' )->once()->andReturn( [ $post_id ] );
		Functions\expect( 'get_post_meta' )
			->once()
			->with( $post_id, '_previewshare_links', true )
			->andReturn(
				[
					$hash => [
						'hash'           => $hash,
						'label'          => 'Active',
						'created_at'     => time() - 60,
						'created_by'     => 1,
						'expires_at'     => time() + 60,
						'revoked'        => 0,
						'last_viewed_at' => null,
						'view_count'     => 2,
					],
				]
			);
		Functions\expect( 'update_post_meta' )
			->once()
			->withArgs(
				static function( int $requested_post_id, string $key, array $links ) use ( $post_id, $hash ): bool {
					return $requested_post_id === $post_id
						&& '_previewshare_links' === $key
						&& 3 === $links[ $hash ]['view_count']
						&& is_int( $links[ $hash ]['last_viewed_at'] );
				}
			)
			->andReturn( true );
		Functions\expect( 'update_post_meta' )->once()->andReturn( true );

		$this->assertTrue( $storage->record_token_view( $token ) );
	}
}
