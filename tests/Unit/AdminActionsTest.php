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

class AdminActionsTest extends TestCase {

	public function test_preview_bar_styles_are_registered_through_enqueue_api(): void {
		$actions = $this->make_actions();

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
				static function( string $handle, string $css ): bool {
					return 'previewshare-preview-bar' === $handle
						&& str_contains( $css, '.previewshare-preview-bar' )
						&& ! str_contains( $css, '<' . 'style' );
				}
			)
			->andReturn( true );

		$actions->enqueue_preview_bar_styles();
	}

	private function make_actions(): Actions {
		Functions\when( 'add_action' )->justReturn( true );
		Functions\when( 'add_filter' )->justReturn( true );

		return new Actions( Mockery::mock( PostMetaStorage::class ) );
	}
}
