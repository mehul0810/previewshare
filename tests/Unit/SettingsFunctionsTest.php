<?php
/**
 * Settings helper tests.
 *
 * @package PreviewShare
 */

namespace PreviewShare\Tests\Unit;

use Brain\Monkey\Functions;

class SettingsFunctionsTest extends TestCase {

	public function test_available_post_types_excludes_attachments_and_non_viewable_types(): void {
		$post        = (object) [
			'label'  => 'Posts',
			'labels' => (object) [ 'singular_name' => 'Post' ],
		];
		$attachment  = (object) [
			'label'  => 'Media',
			'labels' => (object) [ 'singular_name' => 'Media' ],
		];
		$not_viewable = (object) [
			'label'  => 'Hidden',
			'labels' => (object) [ 'singular_name' => 'Hidden' ],
		];

		Functions\expect( 'get_post_types' )
			->once()
			->with( [ 'public' => true ], 'objects' )
			->andReturn(
				[
					'post'       => $post,
					'attachment' => $attachment,
					'hidden'     => $not_viewable,
				]
			);

		Functions\expect( 'is_post_type_viewable' )
			->twice()
			->andReturnUsing(
				static function( $object ) use ( $post ): bool {
					return $object === $post;
				}
			);

		$this->assertSame( [ 'post' => 'Post' ], \previewshare_get_available_post_types() );
	}

	public function test_get_settings_normalizes_saved_values_against_available_post_types(): void {
		$this->mockAvailablePostTypes();

		Functions\expect( 'get_option' )
			->times( 4 )
			->andReturnUsing(
				static function( string $option, $default = false ) {
					$values = [
						'previewshare_post_types'        => [ 'post', 'missing', 'page' ],
						'previewshare_default_ttl_hours' => '12',
						'previewshare_enable_logging'    => '1',
						'previewshare_enable_caching'    => '',
					];

					return $values[ $option ] ?? $default;
				}
			);

		$settings = \previewshare_get_settings();

		$this->assertSame( 12, $settings['default_ttl_hours'] );
		$this->assertTrue( $settings['enable_logging'] );
		$this->assertFalse( $settings['enable_caching'] );
		$this->assertSame( [ 'post', 'page' ], $settings['post_types'] );
	}

	public function test_previewshare_log_only_fires_when_enabled(): void {
		Functions\expect( 'get_option' )
			->once()
			->with( 'previewshare_enable_logging', false )
			->andReturn( false );
		Functions\expect( 'do_action' )->never();

		\previewshare_log( 'list_tokens', [ 'page' => 1 ] );
		$this->addToAssertionCount( 1 );
	}

	public function test_previewshare_log_fires_diagnostic_action_when_enabled(): void {
		Functions\expect( 'get_option' )
			->once()
			->with( 'previewshare_enable_logging', false )
			->andReturn( true );
		Functions\expect( 'do_action' )
			->once()
			->with( 'previewshare_log', 'update_settings', [ 'user_id' => 7 ] );

		\previewshare_log( 'update_settings', [ 'user_id' => 7 ] );
		$this->addToAssertionCount( 1 );
	}

	public function test_token_hash_key_is_generated_and_persisted_without_autoloading(): void {
		Functions\expect( 'get_option' )
			->once()
			->with( 'previewshare_token_hash_key', '' )
			->andReturn( '' );
		Functions\expect( 'add_option' )
			->once()
			->withArgs(
				static function( string $option, string $key, string $deprecated, bool $autoload ): bool {
					return 'previewshare_token_hash_key' === $option
						&& 1 === preg_match( '/^[a-f0-9]{64}$/', $key )
						&& '' === $deprecated
						&& false === $autoload;
				}
			)
			->andReturn( true );

		$this->assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', \previewshare_get_token_hash_key() );
	}

	public function test_token_hash_key_uses_existing_plugin_owned_key(): void {
		$key = 'previewshare-existing-plugin-owned-key';

		Functions\expect( 'get_option' )
			->once()
			->with( 'previewshare_token_hash_key', '' )
			->andReturn( $key );

		$this->assertSame( $key, \previewshare_get_token_hash_key() );
	}

	private function mockAvailablePostTypes(): void {
		Functions\expect( 'get_post_types' )
			->twice()
			->with( [ 'public' => true ], 'objects' )
			->andReturn(
				[
					'post' => (object) [
						'label'  => 'Posts',
						'labels' => (object) [ 'singular_name' => 'Post' ],
					],
					'page' => (object) [
						'label'  => 'Pages',
						'labels' => (object) [ 'singular_name' => 'Page' ],
					],
				]
			);

		Functions\expect( 'is_post_type_viewable' )
			->times( 4 )
			->andReturn( true );
	}
}
