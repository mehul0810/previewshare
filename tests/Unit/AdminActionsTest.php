<?php
/**
 * Admin action tests.
 *
 * @package PreviewShare
 */

namespace PreviewShare\Tests\Unit;

use Brain\Monkey\Functions;
use Mockery;
use PreviewShare\Admin\Actions;
use PreviewShare\Services\PostMetaStorage;
use WP_Post;
use WP_Query;

class AdminActionsTest extends TestCase {

	public function test_preview_bar_styles_are_registered_through_enqueue_api(): void {
		$actions = $this->make_actions();
		$inline_css = '';

		Functions\expect( 'get_query_var' )
			->once()
			->with( 'previewshare_token' )
			->andReturn( 'preview-token' );
		Functions\expect( 'wp_register_style' )
			->once()
			->with( 'previewshare-preview-bar', false, [], '1.0.0' )
			->andReturn( true );
		Functions\expect( 'wp_enqueue_style' )
			->once()
			->with( 'previewshare-preview-bar' );
		Functions\expect( 'wp_add_inline_style' )
			->once()
			->withArgs(
				static function( string $handle, string $css ) use ( &$inline_css ): bool {
					$inline_css = $css;

					return 'previewshare-preview-bar' === $handle
						&& str_contains( $css, '.previewshare-preview-bar' )
						&& ! str_contains( $css, '<' . 'style' );
				}
			)
			->andReturn( true );

		$actions->enqueue_preview_bar_styles();

		$this->assertStringContainsString( '.previewshare-preview-bar', $inline_css );
		$this->assertStringNotContainsString( '<' . 'style', $inline_css );
	}

	public function test_filter_preview_robots_forces_noindex_for_preview_requests(): void {
		$actions = $this->make_actions();

		Functions\expect( 'get_query_var' )
			->once()
			->with( 'previewshare_token' )
			->andReturn( 'preview-token' );

		$robots = $actions->filter_preview_robots(
			[
				'index'             => true,
				'follow'            => true,
				'max-image-preview' => 'large',
			]
		);

		$this->assertArrayNotHasKey( 'index', $robots );
		$this->assertArrayNotHasKey( 'follow', $robots );
		$this->assertArrayNotHasKey( 'max-image-preview', $robots );
		$this->assertTrue( $robots['noindex'] );
		$this->assertTrue( $robots['nofollow'] );
		$this->assertTrue( $robots['noarchive'] );
		$this->assertTrue( $robots['nosnippet'] );
		$this->assertTrue( $robots['noimageindex'] );
	}

	public function test_maybe_handle_preview_request_sets_main_query_for_valid_draft(): void {
		$storage = Mockery::mock( PostMetaStorage::class );
		$actions = $this->make_actions( $storage );
		$query   = new WP_Query();
		$post    = new WP_Post(
			[
				'ID'          => 42,
				'post_type'   => 'post',
				'post_status' => 'draft',
			]
		);

		Functions\expect( 'is_admin' )->once()->andReturn( false );
		Functions\when( 'get_query_var' )->alias(
			static function( string $key ) {
				return 'previewshare_token' === $key ? 'preview-token' : null;
			}
		);
		Functions\when( 'PreviewShare\Admin\headers_sent' )->justReturn( true );
		Functions\expect( 'get_post_meta' )
			->once()
			->with( 42, '_previewshare_enabled', true )
			->andReturn( true );
		Functions\expect( 'get_post' )
			->once()
			->with( 42 )
			->andReturn( $post );
		$this->mock_supported_post_types();

		$storage->shouldReceive( 'get_post_id_by_token' )
			->once()
			->with( 'preview-token' )
			->andReturn( 42 );
		$storage->shouldReceive( 'get_token_meta' )
			->once()
			->with( 42 )
			->andReturn(
				[
					'revoked' => false,
					'expired' => false,
				]
			);
		$storage->shouldReceive( 'record_token_view' )
			->once()
			->with( 'preview-token' )
			->andReturn( true );

		$actions->maybe_handle_preview_request( $query );

		$this->assertSame( 42, $query->query_vars['p'] );
		$this->assertSame( 'post', $query->query_vars['post_type'] );
		$this->assertSame( [ 'publish', 'future', 'draft', 'pending', 'private' ], $query->query_vars['post_status'] );
		$this->assertTrue( $query->query_vars['ignore_sticky_posts'] );
		$this->assertTrue( $query->is_singular );
		$this->assertTrue( $query->is_single );
		$this->assertFalse( $query->is_page );
		$this->assertSame( $post, $query->queried_object );
		$this->assertSame( 42, $query->queried_object_id );
		$this->assertSame( 1, $query->found_posts );
		$this->assertSame( 1, $query->post_count );
	}

	private function make_actions( ?PostMetaStorage $storage = null ): Actions {
		Functions\when( 'add_action' )->justReturn( true );
		Functions\when( 'add_filter' )->justReturn( true );

		return new Actions( $storage ?: Mockery::mock( PostMetaStorage::class ) );
	}

	private function mock_supported_post_types(): void {
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
					'previewshare_default_ttl_hours' => 6,
					'previewshare_enable_logging'    => false,
					'previewshare_enable_caching'    => true,
				];

				return $values[ $option ] ?? $default;
			}
		);
	}
}
