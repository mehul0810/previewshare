<?php
/**
 * Post meta storage tests.
 *
 * @package PreviewShare
 */

namespace PreviewShare\Tests\Unit;

use Brain\Monkey\Functions;
use PreviewShare\Services\PostMetaStorage;
use PreviewShare\Services\TokenService;

class PostMetaStorageTest extends TestCase {

	private const HASH_KEY = 'previewshare-test-token-hash-key-for-storage-tests';

	public function test_store_token_persists_hashed_link_and_cache_entry(): void {
		$storage      = $this->make_storage();
		$post_id      = 123;
		$token        = 'raw-token';
		$hash         = hash_hmac( 'sha256', $token, self::HASH_KEY );
		$stored_links = null;

		Functions\expect( 'get_post_meta' )
			->times( 3 )
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

	public function test_store_token_rolls_back_when_required_detail_meta_fails(): void {
		$storage = $this->make_storage();
		$post_id = 124;
		$token   = 'rollback-token';
		$hash    = hash_hmac( 'sha256', $token, self::HASH_KEY );

		Functions\expect( 'get_post_meta' )
			->times( 4 )
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
				static function( int $requested_post_id, string $key, array $links ) use ( $post_id, $hash ): bool {
					return $requested_post_id === $post_id
						&& '_previewshare_links' === $key
						&& isset( $links[ $hash ] );
				}
			)
			->andReturn( true );
		Functions\expect( 'update_post_meta' )
			->once()
			->with( $post_id, '_previewshare_token_hash', $hash )
			->andReturn( true );
		Functions\expect( 'update_post_meta' )
			->once()
			->withArgs(
				static function( int $requested_post_id, string $key, $value ) use ( $post_id, $hash ): bool {
					unset( $value );

					return $requested_post_id === $post_id
						&& '_previewshare_token:' . $hash === $key;
				}
			)
			->andReturn( false );
		Functions\expect( 'update_post_meta' )
			->once()
			->with( $post_id, '_previewshare_links', [] )
			->andReturn( true );
		Functions\expect( 'delete_post_meta' )
			->once()
			->with( $post_id, '_previewshare_token:' . $hash )
			->andReturn( true );
		Functions\expect( 'delete_post_meta' )
			->once()
			->with( $post_id, '_previewshare_token_rev:' . $hash )
			->andReturn( true );
		Functions\expect( 'delete_post_meta' )
			->once()
			->with( $post_id, '_previewshare_token_hash' )
			->andReturn( true );

		$this->assertFalse( $storage->store_token( $post_id, $token, 2, 'Rollback test' ) );
	}

	public function test_get_link_by_token_returns_null_for_revoked_link_and_clears_cache_when_enabled(): void {
		$storage = $this->make_storage();
		$token   = 'revoked-token';
		$hash    = hash_hmac( 'sha256', $token, self::HASH_KEY );
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
			->with( $post_id, '_previewshare_token:' . $hash, true )
			->andReturn(
				[
					'hash'       => $hash,
					'label'      => 'Revoked',
					'created_at' => time() - 60,
					'created_by' => 1,
					'expires_at' => null,
					'revoked'    => 1,
				]
			);
		Functions\expect( 'wp_cache_delete' )
			->once()
			->with( $hash, 'previewshare_tokens' )
			->andReturn( true );

		$this->assertNull( $storage->get_link_by_token( $token ) );
	}

	public function test_record_token_view_updates_active_link_view_fields(): void {
		$storage = $this->make_storage();
		$token   = 'active-token';
		$hash    = hash_hmac( 'sha256', $token, self::HASH_KEY );
		$post_id = 789;

		Functions\expect( 'get_option' )
			->twice()
			->with( 'previewshare_enable_caching', true )
			->andReturn( false );
		Functions\expect( 'get_posts' )->once()->andReturn( [ $post_id ] );
		Functions\expect( 'get_post_meta' )
			->once()
			->with( $post_id, '_previewshare_token:' . $hash, true )
			->andReturn(
				[
					'hash'           => $hash,
					'label'          => 'Active',
					'created_at'     => time() - 60,
					'created_by'     => 1,
					'expires_at'     => time() + 60,
					'revoked'        => 0,
					'last_viewed_at' => null,
					'view_count'     => 2,
				]
			);
		Functions\expect( 'update_post_meta' )
			->once()
			->withArgs(
				static function( int $requested_post_id, string $key, array $link ) use ( $post_id, $hash ): bool {
					return $requested_post_id === $post_id
						&& '_previewshare_token:' . $hash === $key
						&& 3 === $link['view_count']
						&& is_int( $link['last_viewed_at'] );
				}
			)
			->andReturn( true );

		$this->assertTrue( $storage->record_token_view( $token ) );
	}

	public function test_get_token_meta_uses_detail_rows_for_current_view_counts(): void {
		$storage        = $this->make_storage();
		$post_id        = 790;
		$hash           = 'detail-hash';
		$last_viewed_at = time() - 10;
		$base_link      = [
			'hash'           => $hash,
			'label'          => 'Active',
			'created_at'     => time() - 60,
			'created_by'     => 1,
			'expires_at'     => time() + 60,
			'revoked'        => 0,
			'last_viewed_at' => null,
			'view_count'     => 0,
		];

		Functions\expect( 'get_post_meta' )
			->twice()
			->andReturnUsing(
				static function( int $requested_post_id, string $key, bool $single ) use ( $post_id, $hash, $base_link, $last_viewed_at ) {
					if ( $requested_post_id !== $post_id || true !== $single ) {
						return null;
					}

					if ( '_previewshare_links' === $key ) {
						return [ $hash => $base_link ];
					}

					$detail                    = $base_link;
					$detail['last_viewed_at']  = $last_viewed_at;
					$detail['view_count']      = 4;

					return $detail;
				}
			);

		$meta = $storage->get_token_meta( $post_id );

		$this->assertIsArray( $meta );
		$this->assertSame( 4, $meta['links'][0]['view_count'] );
		$this->assertSame( $last_viewed_at, $meta['links'][0]['last_viewed_at'] );
	}

	public function test_list_tokens_uses_bounded_detail_meta_query(): void {
		global $wpdb;

		$previous_wpdb = $wpdb ?? null;
		$fake_wpdb     = new FakePreviewShareWpdb( $this->make_link_rows( 105 ) );
		$wpdb          = $fake_wpdb;
		$storage       = $this->make_storage();

		try {
			$items = $storage->list_tokens( 200, 2 );
		} finally {
			$wpdb = $previous_wpdb;
		}

		$this->assertCount( 5, $items );
		$this->assertSame( 'hash-100', $items[0]['id'] );
		$this->assertSame( 1100, $items[0]['post_id'] );
		$this->assertSame( 'Link 100', $items[0]['label'] );
		$this->assertSame( 100, $items[0]['view_count'] );
		$this->assertSame( [ '\_previewshare\_token:%', 'revision', 100, 100 ], $fake_wpdb->last_result_args );
		$this->assertSame( 1, $fake_wpdb->get_results_calls );
	}

	public function test_count_tokens_uses_scalar_detail_meta_query_without_loading_rows(): void {
		global $wpdb;

		$previous_wpdb = $wpdb ?? null;
		$fake_wpdb     = new FakePreviewShareWpdb(
			[
				$this->make_link_row( 1 ),
				$this->make_link_row( 2, 'revision' ),
				[
					'post_id'    => 1003,
					'post_type'  => 'post',
					'meta_key'   => '_thumbnail_id',
					'meta_value' => '123',
				],
			]
		);
		$wpdb          = $fake_wpdb;
		$storage       = $this->make_storage();

		try {
			$count = $storage->count_tokens();
		} finally {
			$wpdb = $previous_wpdb;
		}

		$this->assertSame( 1, $count );
		$this->assertSame( [ '\_previewshare\_token:%', 'revision' ], $fake_wpdb->last_var_args );
		$this->assertSame( 0, $fake_wpdb->get_results_calls );
		$this->assertSame( 1, $fake_wpdb->get_var_calls );
	}

	private function make_storage(): PostMetaStorage {
		return new PostMetaStorage( new TokenService( self::HASH_KEY ) );
	}

	/**
	 * @return list<array<string,mixed>>
	 */
	private function make_link_rows( int $count ): array {
		$rows = [];

		for ( $i = 0; $i < $count; $i++ ) {
			$rows[] = $this->make_link_row( $i );
		}

		return $rows;
	}

	/**
	 * @return array<string,mixed>
	 */
	private function make_link_row( int $index, string $post_type = 'post' ): array {
		$hash = 'hash-' . $index;

		return [
			'post_id'    => 1000 + $index,
			'post_type'  => $post_type,
			'meta_key'   => '_previewshare_token:' . $hash,
			'meta_value' => serialize(
				[
					'hash'           => $hash,
					'label'          => 'Link ' . $index,
					'created_at'     => time() - $index,
					'created_by'     => 7,
					'expires_at'     => null,
					'revoked'        => 0,
					'last_viewed_at' => null,
					'view_count'     => $index,
				]
			),
		];
	}
}

class FakePreviewShareWpdb {
	/** @var string */
	public $postmeta = 'wp_postmeta';

	/** @var string */
	public $posts = 'wp_posts';

	/** @var int */
	public $get_results_calls = 0;

	/** @var int */
	public $get_var_calls = 0;

	/** @var list<mixed> */
	public $last_result_args = [];

	/** @var list<mixed> */
	public $last_var_args = [];

	/** @var list<mixed> */
	private $last_prepare_args = [];

	/** @var list<array<string,mixed>> */
	private $rows;

	/**
	 * @param list<array<string,mixed>> $rows Rows available to fake DB reads.
	 */
	public function __construct( array $rows ) {
		$this->rows = $rows;
	}

	public function esc_like( string $text ): string {
		return addcslashes( $text, '_%\\' );
	}

	public function prepare( string $query, ...$args ): string {
		$this->last_prepare_args = $args;

		return $query;
	}

	/**
	 * @return list<array<string,mixed>>
	 */
	public function get_results( string $query, string $output ): array {
		unset( $query, $output );

		$this->get_results_calls++;
		$this->last_result_args = $this->last_prepare_args;

		$per_page = isset( $this->last_prepare_args[2] ) ? (int) $this->last_prepare_args[2] : 50;
		$offset   = isset( $this->last_prepare_args[3] ) ? (int) $this->last_prepare_args[3] : 0;

		return array_slice( $this->matching_rows(), $offset, $per_page );
	}

	public function get_var( string $query ): int {
		unset( $query );

		$this->get_var_calls++;
		$this->last_var_args = $this->last_prepare_args;

		return count( $this->matching_rows() );
	}

	/**
	 * @return list<array<string,mixed>>
	 */
	private function matching_rows(): array {
		return array_values(
			array_filter(
				$this->rows,
				static function( array $row ): bool {
					return isset( $row['meta_key'] )
						&& str_starts_with( (string) $row['meta_key'], '_previewshare_token:' )
						&& 'revision' !== ( $row['post_type'] ?? '' );
				}
			)
		);
	}
}
