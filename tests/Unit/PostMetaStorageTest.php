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

class PostMetaCasTestDatabase {
	public $postmeta = 'wp_postmeta';
	public $posts    = 'wp_posts';
	public $queries  = [];
	public $lookup_result = 0;
	private $callback;

	public function __construct( callable $callback ) {
		$this->callback = $callback;
	}

	public function prepare( string $sql, ...$args ): string {
		return serialize( [ $sql, $args ] );
	}

	public function query( string $query ): int {
		[ $sql, $args ] = unserialize( $query );
		$this->queries[] = $sql;
		return call_user_func_array( $this->callback, $args );
	}

	public function get_var( string $query ) {
		return $this->lookup_result;
	}
}

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
					if ( $requested_post_id !== $post_id ) {
						return null;
					}

					return '' === $key || '_previewshare_links' === $key ? [] : '';
				}
			);
		Functions\when( 'wp_cache_delete' )->justReturn( true );
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
		$meta    = [];
		Functions\when( 'wp_cache_delete' )->justReturn( true );
		Functions\when( 'get_post_meta' )->alias( static function( int $requested_post_id, string $key, bool $single ) use ( &$meta, $post_id ) { return $post_id !== $requested_post_id ? '' : ( '' === $key ? array_map( static function( $value ): array { return [ maybe_serialize( $value ) ]; }, $meta ) : ( $meta[ $key ] ?? '' ) ); } );
		Functions\when( 'get_current_user_id' )->justReturn( 7 );
		Functions\when( 'update_post_meta' )->alias( static function( int $requested_post_id, string $key, $value, $previous = null ) use ( &$meta, $post_id, $hash ) {
			if ( $requested_post_id !== $post_id || ( null !== $previous && ( $meta[ $key ] ?? null ) !== $previous ) ) { return false; }
			if ( '_previewshare_token:' . $hash === $key ) { return false; }
			$meta[ $key ] = $value;
			return true;
		} );
		Functions\when( 'delete_post_meta' )->alias( static function( int $requested_post_id, string $key, $previous = null ) use ( &$meta, $post_id ) { if ( $requested_post_id !== $post_id || ( null !== $previous && ( $meta[ $key ] ?? null ) !== $previous ) ) { return false; } unset( $meta[ $key ] ); return true; } );

		$this->assertFalse( $storage->store_token( $post_id, $token, 2, 'Rollback test' ) );
		$this->assertSame( [], $meta['_previewshare_links'] );
		$this->assertArrayNotHasKey( '_previewshare_token_hash', $meta );
		$this->assertArrayNotHasKey( '_previewshare_token:' . $hash, $meta );
		$this->assertArrayNotHasKey( '_previewshare_token_rev:' . $hash, $meta );
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

	public function test_get_token_diagnostic_maps_missing_token(): void {
		$storage = $this->make_storage();

		$this->assertSame(
			[
				'reason_code' => 'missing_token',
				'post_id'     => null,
				'status'      => null,
			],
			$storage->get_token_diagnostic( '' )
		);
	}

	public function test_record_token_view_does_not_overwrite_a_changed_detail_or_recreate_a_missing_row(): void {
		$storage = $this->make_storage();
		$token   = 'racing-view-token';
		$hash    = hash_hmac( 'sha256', $token, self::HASH_KEY );
		$post_id = 812;
		$detail  = [
			'hash' => $hash, 'label' => 'Review', 'created_at' => time() - 30,
			'created_by' => 1, 'expires_at' => null, 'revoked' => 0,
		];
		$previous_db = isset( $GLOBALS['wpdb'] ) ? $GLOBALS['wpdb'] : null;
		$GLOBALS['wpdb'] = new class() {
			public $postmeta = 'wp_postmeta';
			public $sql      = '';
			public function prepare( string $sql, ...$args ): string {
				$this->sql = $sql . ' | ' . implode( '|', array_map( 'strval', $args ) );
				return $this->sql;
			}
			public function query( string $sql ): int {
				return 0; // A concurrent revoke/delete changed or removed the stored row.
			}
		};

		Functions\expect( 'get_option' )->twice()->with( 'previewshare_enable_caching', true )->andReturn( false );
		Functions\expect( 'get_posts' )->once()->andReturn( [ $post_id ] );
		Functions\expect( 'get_post_meta' )->twice()->andReturnUsing(
			static function ( int $requested_post_id, string $key, bool $single ) use ( $post_id, $hash, $detail ) {
				if ( $post_id !== $requested_post_id || true !== $single ) {
					return '';
				}
				return '_previewshare_token:' . $hash === $key ? $detail : [];
			}
		);
		Functions\expect( 'update_post_meta' )->never();
		Functions\expect( 'wp_cache_delete' )->once()->with( $post_id, 'post_meta' )->andReturn( true );

		$this->assertFalse( $storage->record_token_view( $token ) );
		$this->assertStringContainsString( 'UPDATE wp_postmeta SET meta_value', $GLOBALS['wpdb']->sql );
		$this->assertStringContainsString( '_previewshare_token:' . $hash, $GLOBALS['wpdb']->sql );
		$this->assertStringContainsString( 'AND meta_value = %s', $GLOBALS['wpdb']->sql );

		if ( null === $previous_db ) {
			unset( $GLOBALS['wpdb'] );
		} else {
			$GLOBALS['wpdb'] = $previous_db;
		}
	}

	public function test_delete_token_revokes_then_removes_only_the_selected_inventory_entry(): void {
		$storage    = $this->make_storage();
		$hash       = str_repeat( 'a', 64 );
		$sibling    = str_repeat( 'b', 64 );
		$post_id    = 813;
		$meta       = [
			'_previewshare_links' => [
				$hash    => [ 'hash' => $hash, 'label' => 'Delete me', 'created_at' => time() - 20, 'created_by' => 1, 'expires_at' => null, 'revoked' => 0 ],
				$sibling => [ 'hash' => $sibling, 'label' => 'Keep me', 'created_at' => time() - 10, 'created_by' => 1, 'expires_at' => null, 'revoked' => 0 ],
			],
			'_previewshare_token_hash' => $hash,
			'_previewshare_token:' . $hash => [ 'hash' => $hash, 'label' => 'Delete me', 'created_at' => time() - 20, 'created_by' => 1, 'expires_at' => null, 'revoked' => 0 ],
			'_previewshare_token:' . $sibling => [ 'hash' => $sibling, 'label' => 'Keep me', 'created_at' => time() - 10, 'created_by' => 1, 'expires_at' => null, 'revoked' => 0 ],
			'_previewshare_token_rev:' . $hash => 1,
			'_previewshare_token_rev:' . $sibling => 1,
			'_previewshare_enabled' => true,
		];
		$previous_db   = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['wpdb'] = new PostMetaCasTestDatabase(
			static function( string $value, int $requested_post_id, string $key, string $previous ) use ( &$meta, $post_id ): int {
				if ( $requested_post_id !== $post_id || ! isset( $meta[ $key ] ) || maybe_serialize( $meta[ $key ] ) !== $previous ) {
					return 0;
				}
				$meta[ $key ] = maybe_unserialize( $value );
				return 1;
			}
		);
		$GLOBALS['wpdb']->lookup_result = $post_id;
		Functions\when( 'get_option' )->alias( static function( string $option, $default = false ) {
			return 'previewshare_enable_caching' === $option ? false : $default;
		} );
		Functions\when( 'get_posts' )->alias( static function( array $args ) use ( &$meta, $post_id ): array {
			return isset( $meta[ $args['meta_key'] ] ) ? [ $post_id ] : [];
		} );
		Functions\when( 'get_post_meta' )->alias( static function( int $requested_post_id, string $key, bool $single ) use ( &$meta, $post_id ) {
			return $post_id !== $requested_post_id ? '' : ( '' === $key ? array_map( static function( $value ): array { return [ maybe_serialize( $value ) ]; }, $meta ) : ( $meta[ $key ] ?? '' ) );
		} );
		Functions\when( 'update_post_meta' )->alias( static function( int $requested_post_id, string $key, $value, $previous = null ) use ( &$meta, $post_id ) {
			if ( $post_id !== $requested_post_id || ( null !== $previous && ( $meta[ $key ] ?? null ) !== $previous ) ) {
				return false;
			}
			$meta[ $key ] = $value;
			return true;
		} );
		Functions\when( 'delete_post_meta' )->alias( static function( int $requested_post_id, string $key, $previous = null ) use ( &$meta, $post_id ) {
			if ( $post_id !== $requested_post_id || ( null !== $previous && ( $meta[ $key ] ?? null ) !== $previous ) ) {
				return false;
			}
			unset( $meta[ $key ] );
			return true;
		} );
		Functions\when( 'wp_cache_delete' )->justReturn( true );

		$this->assertTrue( $storage->delete_token_by_id( $hash ) );
		$this->assertArrayNotHasKey( $hash, $meta['_previewshare_links'] );
		$this->assertArrayHasKey( $sibling, $meta['_previewshare_links'] );
		$this->assertArrayNotHasKey( '_previewshare_token:' . $hash, $meta );
		$this->assertArrayNotHasKey( '_previewshare_token_rev:' . $hash, $meta );
		$this->assertArrayNotHasKey( '_previewshare_token_hash', $meta );
		$this->assertTrue( $meta['_previewshare_enabled'] );
		if ( null === $previous_db ) {
			unset( $GLOBALS['wpdb'] );
		} else {
			$GLOBALS['wpdb'] = $previous_db;
		}
	}

	public function test_store_token_migrates_an_existing_legacy_link_as_a_visible_sibling(): void {
		$storage   = $this->make_storage();
		$post_id   = 815;
		$old_token = 'legacy-visible-token';
		$old_hash  = hash_hmac( 'sha256', $old_token, self::HASH_KEY );
		Functions\when( 'wp_cache_delete' )->justReturn( true );
		$old_link  = [ 'hash' => $old_hash, 'label' => 'Legacy link', 'created_at' => time() - 20, 'created_by' => 1, 'expires_at' => null, 'revoked' => 0 ];
		$meta      = [
			'_previewshare_token_hash' => $old_hash,
			'_previewshare_token:' . $old_hash => $old_link,
			'_previewshare_token_rev:' . $old_hash => 1,
			'_previewshare_enabled' => true,
		];
		Functions\when( 'get_option' )->alias( static function( string $key, $default = false ) { return 'previewshare_enable_caching' === $key ? false : $default; } );
		Functions\when( 'get_current_user_id' )->justReturn( 1 );
		Functions\when( 'get_posts' )->alias( static function( array $args ) use ( &$meta, $post_id ): array { return isset( $meta[ $args['meta_key'] ] ) ? [ $post_id ] : []; } );
		Functions\when( 'get_post_meta' )->alias( static function( int $requested_post_id, string $key, bool $single ) use ( &$meta, $post_id ) { return $post_id !== $requested_post_id ? '' : ( '' === $key ? array_map( static function( $value ): array { return [ maybe_serialize( $value ) ]; }, $meta ) : ( $meta[ $key ] ?? '' ) ); } );
		Functions\when( 'update_post_meta' )->alias( static function( int $requested_post_id, string $key, $value, $previous = null ) use ( &$meta, $post_id ) { if ( $requested_post_id !== $post_id || ( null !== $previous && ( $meta[ $key ] ?? null ) !== $previous ) ) { return false; } $meta[ $key ] = $value; return true; } );

		$this->assertTrue( $storage->store_token( $post_id, 'new-visible-token', 0, 'New link' ) );
		$this->assertArrayHasKey( $old_hash, $meta['_previewshare_links'] );
		$this->assertCount( 2, $storage->get_token_meta( $post_id )['links'] );
		$this->assertNotNull( $storage->get_link_by_token( $old_token ) );
	}

	public function test_revoke_cas_does_not_recreate_detail_after_concurrent_delete(): void {
		$storage = $this->make_storage();
		$token   = 'cas-revoke-race';
		$hash    = hash_hmac( 'sha256', $token, self::HASH_KEY );
		$post_id = 816;
		$link    = [ 'hash' => $hash, 'label' => 'Race', 'created_at' => time() - 10, 'created_by' => 1, 'expires_at' => null, 'revoked' => 0 ];
		$meta    = [ '_previewshare_links' => [ $hash => $link ], '_previewshare_token_hash' => $hash, '_previewshare_token:' . $hash => $link, '_previewshare_token_rev:' . $hash => 1, '_previewshare_enabled' => true ];
		$nested_delete_succeeded = false;
		$inject_delete = true;
		$previous_db = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['wpdb'] = new PostMetaCasTestDatabase(
			static function( string $value, int $requested_post_id, string $key, string $previous ) use ( &$meta, &$inject_delete, &$nested_delete_succeeded, $storage, $hash, $post_id ): int {
				if ( $inject_delete && '_previewshare_token:' . $hash === $key ) {
					$inject_delete = false;
					$nested_delete_succeeded = true === $storage->delete_token_by_id( $hash );
				}
				if ( $requested_post_id !== $post_id || ! isset( $meta[ $key ] ) || maybe_serialize( $meta[ $key ] ) !== $previous ) { return 0; }
				$meta[ $key ] = maybe_unserialize( $value );
				return 1;
			}
		);
		$GLOBALS['wpdb']->lookup_result = $post_id;
		Functions\when( 'get_option' )->alias( static function( string $key, $default = false ) { return 'previewshare_enable_caching' === $key ? false : $default; } );
		Functions\when( 'get_posts' )->alias( static function( array $args ) use ( &$meta, $post_id ): array { return isset( $meta[ $args['meta_key'] ] ) ? [ $post_id ] : []; } );
		Functions\when( 'get_post_meta' )->alias( static function( int $requested_post_id, string $key, bool $single ) use ( &$meta, $post_id ) { return $post_id !== $requested_post_id ? '' : ( '' === $key ? array_map( static function( $value ): array { return [ maybe_serialize( $value ) ]; }, $meta ) : ( $meta[ $key ] ?? '' ) ); } );
		Functions\when( 'update_post_meta' )->alias( static function( int $requested_post_id, string $key, $value, $previous = null ) use ( &$meta, $post_id ) { if ( $requested_post_id !== $post_id || ( null !== $previous && ( $meta[ $key ] ?? null ) !== $previous ) ) { return false; } $meta[ $key ] = $value; return true; } );
		Functions\when( 'delete_post_meta' )->alias( static function( int $requested_post_id, string $key, $previous = null ) use ( &$meta, $post_id ) { if ( $requested_post_id !== $post_id || ( null !== $previous && ( $meta[ $key ] ?? null ) !== $previous ) ) { return false; } unset( $meta[ $key ] ); return true; } );
		Functions\when( 'wp_cache_delete' )->justReturn( true );

		$this->assertFalse( $storage->revoke_token_by_id( $hash ) );
		$this->assertTrue( $nested_delete_succeeded );
		$this->assertArrayNotHasKey( '_previewshare_token:' . $hash, $meta );
		$this->assertArrayNotHasKey( '_previewshare_token_rev:' . $hash, $meta );
		$this->assertNull( $storage->get_link_by_token( $token ) );
		if ( null === $previous_db ) { unset( $GLOBALS['wpdb'] ); } else { $GLOBALS['wpdb'] = $previous_db; }
	}

	public function test_revoke_all_retries_view_conflict_and_reports_persistent_conflict(): void {
		$storage = $this->make_storage();
		$post_id = 819;
		$hashes  = [ hash_hmac( 'sha256', 'revoke-all-a', self::HASH_KEY ), hash_hmac( 'sha256', 'revoke-all-b', self::HASH_KEY ) ];
		$meta    = [ '_previewshare_links' => [] ];
		foreach ( $hashes as $hash ) {
			$link = [ 'hash' => $hash, 'label' => 'Review', 'created_at' => time() - 10, 'created_by' => 1, 'expires_at' => null, 'revoked' => 0, 'last_viewed_at' => null, 'view_count' => 0, 'responses_enabled' => false, 'identity_required' => false ];
			$meta['_previewshare_links'][ $hash ] = $link;
			$meta[ '_previewshare_token:' . $hash ] = $link;
			$meta[ '_previewshare_token_rev:' . $hash ] = 1;
		}
		$previous_db = $GLOBALS['wpdb'] ?? null;
		$inject_once = true;
		$GLOBALS['wpdb'] = new PostMetaCasTestDatabase(
			static function( string $value, int $requested_post_id, string $key, string $previous ) use ( &$meta, &$inject_once, $post_id, $hashes ): int {
				if ( $requested_post_id !== $post_id || ! isset( $meta[ $key ] ) || maybe_serialize( $meta[ $key ] ) !== $previous ) { return 0; }
				if ( $inject_once && $key === '_previewshare_token:' . $hashes[1] ) {
					$inject_once = false;
					++$meta[ $key ]['view_count'];
					$meta[ $key ]['last_viewed_at'] = time();
					return 0;
				}
				$meta[ $key ] = maybe_unserialize( $value );
				return 1;
			}
		);
		Functions\when( 'get_option' )->alias( static function( string $key, $default = false ) { return 'previewshare_enable_caching' === $key ? false : $default; } );
		Functions\when( 'get_posts' )->alias( static function( array $args ) use ( &$meta, $post_id ): array { return isset( $meta[ $args['meta_key'] ] ) ? [ $post_id ] : []; } );
		Functions\when( 'get_post_meta' )->alias( static function( int $requested_post_id, string $key, bool $single ) use ( &$meta, $post_id ) { return $post_id !== $requested_post_id ? '' : ( '' === $key ? array_map( static function( $value ): array { return [ maybe_serialize( $value ) ]; }, $meta ) : ( $meta[ $key ] ?? '' ) ); } );
		Functions\when( 'update_post_meta' )->alias( static function( int $requested_post_id, string $key, $value, $previous = null ) use ( &$meta, $post_id ) { if ( $requested_post_id !== $post_id || ( null !== $previous && ( $meta[ $key ] ?? null ) !== $previous ) ) { return false; } $meta[ $key ] = $value; return true; } );
		Functions\when( 'wp_cache_delete' )->justReturn( true );
		$GLOBALS['wpdb']->lookup_result = $post_id;

		$this->assertTrue( $storage->revoke_token_for_post( $post_id ) );
		$this->assertSame( 1, $meta[ '_previewshare_token:' . $hashes[0] ]['revoked'] );
		$this->assertSame( 1, $meta[ '_previewshare_token:' . $hashes[1] ]['revoked'] );
		$this->assertSame( 1, $meta[ '_previewshare_token:' . $hashes[1] ]['view_count'] );

		// Keep one active detail and force every bounded CAS attempt to lose.
		$meta[ '_previewshare_token:' . $hashes[1] ]['revoked'] = 0;
		$meta['_previewshare_links'][ $hashes[1] ]['revoked'] = 0;
		$GLOBALS['wpdb'] = new PostMetaCasTestDatabase( static function() use ( &$meta, $hashes ): int { ++$meta[ '_previewshare_token:' . $hashes[1] ]['view_count']; return 0; } );
		$GLOBALS['wpdb']->lookup_result = $post_id;
		$this->assertFalse( $storage->revoke_token_for_post( $post_id ) );
		$this->assertSame( 0, $meta[ '_previewshare_token:' . $hashes[1] ]['revoked'] );
		if ( null === $previous_db ) { unset( $GLOBALS['wpdb'] ); } else { $GLOBALS['wpdb'] = $previous_db; }
	}

	public function test_delete_retry_recovers_revoked_detail_after_reverse_index_was_removed(): void {
		$storage       = $this->make_storage();
		$raw_token     = 'retry-delete-token';
		$hash          = hash_hmac( 'sha256', $raw_token, self::HASH_KEY );
		$post_id       = 814;
		$detail_key    = '_previewshare_token:' . $hash;
		$reverse_key   = '_previewshare_token_rev:' . $hash;
		$detail_delete = 0;
		$meta          = [
			'_previewshare_links' => [
				$hash => [ 'hash' => $hash, 'label' => 'Retry me', 'created_at' => time() - 20, 'created_by' => 1, 'expires_at' => null, 'revoked' => 0 ],
			],
			'_previewshare_token_hash' => $hash,
			$detail_key => [ 'hash' => $hash, 'label' => 'Retry me', 'created_at' => time() - 20, 'created_by' => 1, 'expires_at' => null, 'revoked' => 0 ],
			$reverse_key => 1,
			'_previewshare_enabled' => true,
		];
		$previous_db   = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['wpdb'] = new PostMetaCasTestDatabase(
			static function( string $value, int $requested_post_id, string $key, string $previous ) use ( &$meta, $post_id ): int {
				if ( $requested_post_id !== $post_id || ! isset( $meta[ $key ] ) || maybe_serialize( $meta[ $key ] ) !== $previous ) {
					return 0;
				}
				$meta[ $key ] = maybe_unserialize( $value );
				return 1;
			}
		);
		$GLOBALS['wpdb']->lookup_result = $post_id;
		$GLOBALS['wpdb']->lookup_result = $post_id;

		Functions\when( 'get_option' )->alias( static function( string $option, $default = false ) {
			return 'previewshare_enable_caching' === $option ? false : $default;
		} );
		Functions\when( 'get_posts' )->alias( static function( array $args ) use ( &$meta, $post_id ): array {
			return isset( $meta[ $args['meta_key'] ] ) ? [ $post_id ] : [];
		} );
		Functions\when( 'get_post_meta' )->alias( static function( int $requested_post_id, string $key, bool $single ) use ( &$meta, $post_id ) {
			return $post_id !== $requested_post_id ? '' : ( '' === $key ? array_map( static function( $value ): array { return [ maybe_serialize( $value ) ]; }, $meta ) : ( $meta[ $key ] ?? '' ) );
		} );
		Functions\when( 'update_post_meta' )->alias( static function( int $requested_post_id, string $key, $value, $previous = null ) use ( &$meta, $post_id ) {
			if ( $post_id !== $requested_post_id || ( null !== $previous && ( $meta[ $key ] ?? null ) !== $previous ) ) {
				return false;
			}
			$meta[ $key ] = $value;
			return true;
		} );
		Functions\when( 'delete_post_meta' )->alias( static function( int $requested_post_id, string $key, $previous = null ) use ( &$meta, $post_id, &$detail_delete, $detail_key ) {
			if ( $post_id !== $requested_post_id || ( null !== $previous && ( $meta[ $key ] ?? null ) !== $previous ) ) {
				return false;
			}
			if ( $detail_key === $key && 0 === $detail_delete++ ) {
				return false;
			}
			unset( $meta[ $key ] );
			return true;
		} );
		Functions\when( 'wp_cache_delete' )->justReturn( true );

		$first = $storage->delete_token_by_id( $hash );
		$this->assertInstanceOf( \WP_Error::class, $first );
		$this->assertSame( 500, $first->get_error_data()['status'] );
		$this->assertSame( 1, $meta[ $detail_key ]['revoked'] );
		$this->assertArrayNotHasKey( $reverse_key, $meta );
		$this->assertFalse( $storage->get_post_id_by_token( $raw_token ) );

		$retry = $storage->delete_token_by_id( $hash );
		$this->assertTrue( $retry );
		$this->assertArrayNotHasKey( $detail_key, $meta );
		$this->assertArrayNotHasKey( $hash, $meta['_previewshare_links'] );
		if ( null === $previous_db ) {
			unset( $GLOBALS['wpdb'] );
		} else {
			$GLOBALS['wpdb'] = $previous_db;
		}
	}

	public function test_get_token_diagnostic_maps_token_not_found(): void {
		$storage = $this->make_storage();
		$token   = 'unknown-token';

		Functions\expect( 'get_option' )
			->once()
			->with( 'previewshare_enable_caching', true )
			->andReturn( false );
		Functions\expect( 'get_posts' )->once()->andReturn( [] );

		$diagnostic = $storage->get_token_diagnostic( $token );

		$this->assertSame( 'token_not_found', $diagnostic['reason_code'] );
		$this->assertNull( $diagnostic['post_id'] );
		$this->assertNull( $diagnostic['status'] );
	}

	public function test_get_token_diagnostic_maps_revoked_and_expired_links(): void {
		$revoked_storage = $this->make_storage();
		$revoked_token   = 'diagnostic-revoked';
		$revoked_hash    = hash_hmac( 'sha256', $revoked_token, self::HASH_KEY );
		$revoked_post_id = 654;

		Functions\when( 'get_option' )->alias(
			static function( string $option, $default = false ) {
				return 'previewshare_enable_caching' === $option ? false : $default;
			}
		);
		Functions\expect( 'get_posts' )->once()->andReturn( [ $revoked_post_id ] );
		Functions\expect( 'get_post_meta' )
			->once()
			->with( $revoked_post_id, '_previewshare_token:' . $revoked_hash, true )
			->andReturn(
				[
					'hash'       => $revoked_hash,
					'label'      => 'Revoked',
					'created_at' => time() - 60,
					'created_by' => 1,
					'expires_at' => null,
					'revoked'    => 1,
				]
			);

		$revoked = $revoked_storage->get_token_diagnostic( $revoked_token );

		$this->assertSame( 'token_revoked', $revoked['reason_code'] );
		$this->assertSame( $revoked_post_id, $revoked['post_id'] );
		$this->assertSame( 'revoked', $revoked['status'] );

		$expired_storage = $this->make_storage();
		$expired_token   = 'diagnostic-expired';
		$expired_hash    = hash_hmac( 'sha256', $expired_token, self::HASH_KEY );
		$expired_post_id = 655;

		Functions\expect( 'get_posts' )->once()->andReturn( [ $expired_post_id ] );
		Functions\expect( 'get_post_meta' )
			->once()
			->with( $expired_post_id, '_previewshare_token:' . $expired_hash, true )
			->andReturn(
				[
					'hash'       => $expired_hash,
					'label'      => 'Expired',
					'created_at' => time() - 60,
					'created_by' => 1,
					'expires_at' => time() - 1,
					'revoked'    => 0,
				]
			);

		$expired = $expired_storage->get_token_diagnostic( $expired_token );

		$this->assertSame( 'token_expired', $expired['reason_code'] );
		$this->assertSame( $expired_post_id, $expired['post_id'] );
		$this->assertSame( 'expired', $expired['status'] );
	}

	public function test_record_token_view_updates_active_link_view_fields(): void {
		$storage = $this->make_storage();
		$token   = 'active-token';
		$hash    = hash_hmac( 'sha256', $token, self::HASH_KEY );
		$post_id = 789;
		$previous_db = isset( $GLOBALS['wpdb'] ) ? $GLOBALS['wpdb'] : null;
		$GLOBALS['wpdb'] = new class() {
			public $postmeta = 'wp_postmeta';
			public $args     = [];
			public function prepare( string $sql, ...$args ): string {
				$this->args = $args;
				return $sql;
			}
			public function query( string $sql ): int {
				return 1;
			}
		};

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
		Functions\expect( 'update_post_meta' )->never();
		Functions\expect( 'wp_cache_delete' )
			->once()
			->with( $post_id, 'post_meta' )
			->andReturn( true );

		$this->assertTrue( $storage->record_token_view( $token ) );
		$updated_link = maybe_unserialize( $GLOBALS['wpdb']->args[0] );
		$this->assertSame( 3, $updated_link['view_count'] );
		$this->assertIsInt( $updated_link['last_viewed_at'] );
		if ( null === $previous_db ) {
			unset( $GLOBALS['wpdb'] );
		} else {
			$GLOBALS['wpdb'] = $previous_db;
		}
	}

	public function test_get_token_meta_uses_detail_rows_for_current_view_counts(): void {
		$storage        = $this->make_storage();
		$post_id        = 790;
		$hash           = 'detail-hash';
		$last_viewed_at = time() - 10;
		$detail         = [
			'hash' => $hash, 'label' => 'Active', 'created_at' => time() - 60,
			'created_by' => 1, 'expires_at' => time() + 60, 'revoked' => 0,
			'last_viewed_at' => $last_viewed_at, 'view_count' => 4,
		];
		Functions\expect( 'wp_cache_delete' )->once()->with( $post_id, 'post_meta' )->andReturn( true );
		Functions\expect( 'get_post_meta' )->once()->with( $post_id, '', false )->andReturn(
			[
				'_previewshare_links' => [ serialize( [ $hash => array_merge( $detail, [ 'view_count' => 0, 'last_viewed_at' => null ] ) ] ) ],
				'_previewshare_token:' . $hash => [ serialize( $detail ) ],
			]
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

	public function test_status_filtered_page_and_count_preserve_status_precedence(): void {
		global $wpdb;

		$previous_wpdb = $wpdb ?? null;
		$now           = time();
		$rows          = [];
		foreach ( [ [ 'active', null, 0 ], [ 'expired', $now - 1, 0 ], [ 'revoked', $now - 1, 1 ], [ 'future', $now + 60, 0 ] ] as $index => $state ) {
			$row                    = $this->make_link_row( $index );
			$detail                 = maybe_unserialize( $row['meta_value'] );
			$detail['label']        = 0 === $index ? '日本語 s:7:"revoked";i:1; s:10:"expires_at";i:1;' : $detail['label'];
			$detail['expires_at']   = $state[1];
			$detail['revoked']      = $state[2];
			$row['meta_value']      = serialize( $detail );
			$rows[]                 = $row;
		}
		$fake_wpdb = new FakePreviewShareWpdb( $rows );
		$wpdb      = $fake_wpdb;
		$storage   = $this->make_storage();

		try {
			$expired = $storage->list_tokens_by_status( 'expired', 1, 1 );
			$revoked = $storage->list_tokens_by_status( 'revoked', 10, 1 );
		} finally {
			$wpdb = $previous_wpdb;
		}

		$this->assertSame( 1, $expired['total'] );
		$this->assertSame( 'expired', $expired['items'][0]['status'] );
		$this->assertSame( 1, $revoked['total'] );
		$this->assertSame( 'revoked', $revoked['items'][0]['status'] );
		$this->assertSame( 2, $fake_wpdb->get_results_calls );
		$this->assertSame( 2, $fake_wpdb->get_var_calls );
		$this->assertStringContainsString( 'INSTR(REVERSE(pm.meta_value)', $fake_wpdb->last_result_query );
		$this->assertStringContainsString( 'SUBSTR(pm.meta_value', $fake_wpdb->last_result_query );
		$this->assertStringContainsString( 'SUBSTR(pm.meta_value, 1 - INSTR(REVERSE(pm.meta_value)', $fake_wpdb->last_result_query );
		$this->assertStringContainsString( 'created_by', $fake_wpdb->last_result_query );
	}

	public function test_extend_token_by_id_adds_twenty_four_hours_and_preserves_the_link(): void {
		$storage = $this->make_storage();
		$hash    = hash_hmac( 'sha256', 'extend-token', self::HASH_KEY );
		$post_id = 654;
		$expires = time() + ( 3 * HOUR_IN_SECONDS );
		$link    = [
			'hash' => $hash, 'label' => 'Client review', 'created_at' => time() - 60,
			'created_by' => 7, 'expires_at' => $expires, 'revoked' => 0,
			'last_viewed_at' => time() - 15, 'view_count' => 7,
		];
		$meta       = [ '_previewshare_token:' . $hash => $link, '_previewshare_links' => [ $hash => $link ] ];
		$previous_db = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['wpdb'] = new PostMetaCasTestDatabase(
			static function( string $value, int $requested_post_id, string $key, string $previous ) use ( &$meta, $post_id ): int {
				if ( $post_id !== $requested_post_id || maybe_serialize( $meta[ $key ] ?? null ) !== $previous ) {
					return 0;
				}
				$meta[ $key ] = maybe_unserialize( $value );
				return 1;
			}
		);
		$GLOBALS['wpdb']->lookup_result = $post_id;
		Functions\when( 'get_option' )->alias( static function( string $key, $default = false ) { return 'previewshare_enable_caching' === $key ? false : $default; } );
		Functions\when( 'get_posts' )->justReturn( [ $post_id ] );
		Functions\when( 'get_post_meta' )->alias( static function( int $requested_post_id, string $key, bool $single ) use ( &$meta, $post_id ) { return $post_id === $requested_post_id && $single ? ( $meta[ $key ] ?? '' ) : ''; } );
		Functions\when( 'update_post_meta' )->alias( static function( int $requested_post_id, string $key, $value, $previous = null ) use ( &$meta, $post_id ) { if ( $post_id !== $requested_post_id || ( null !== $previous && ( $meta[ $key ] ?? null ) !== $previous ) ) { return false; } $meta[ $key ] = $value; return true; } );
		Functions\when( 'wp_cache_delete' )->justReturn( true );

		$result = $storage->extend_token_by_id( $hash );

		$this->assertSame( [ 'expires_at' => $expires + DAY_IN_SECONDS ], $result );
		$this->assertSame( $expires + DAY_IN_SECONDS, $meta[ '_previewshare_token:' . $hash ]['expires_at'] );
		$this->assertSame( 7, $meta[ '_previewshare_token:' . $hash ]['view_count'] );
		$this->assertSame( $expires + DAY_IN_SECONDS, $meta['_previewshare_links'][ $hash ]['expires_at'] );
		if ( null === $previous_db ) { unset( $GLOBALS['wpdb'] ); } else { $GLOBALS['wpdb'] = $previous_db; }
	}

	public function test_extend_token_by_id_returns_failure_if_the_detail_cas_loses_a_race(): void {
		$storage = $this->make_storage();
		$hash    = hash_hmac( 'sha256', 'failed-extend-token', self::HASH_KEY );
		$post_id = 657;
		$link    = [ 'hash' => $hash, 'label' => 'Client review', 'created_at' => time() - 60, 'created_by' => 7, 'expires_at' => time() + ( 3 * HOUR_IN_SECONDS ), 'revoked' => 0, 'last_viewed_at' => null, 'view_count' => 1 ];
		$meta = [ '_previewshare_token:' . $hash => $link ];
		$previous_db = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['wpdb'] = new PostMetaCasTestDatabase( static function() use ( &$meta, $hash ): int { $meta[ '_previewshare_token:' . $hash ]['revoked'] = 1; return 0; } );
		$GLOBALS['wpdb']->lookup_result = $post_id;
		Functions\when( 'get_option' )->alias( static function( string $key, $default = false ) { return 'previewshare_enable_caching' === $key ? false : $default; } );
		Functions\when( 'get_posts' )->justReturn( [ $post_id ] );
		Functions\when( 'get_post_meta' )->alias( static function( int $requested_post_id, string $key, bool $single ) use ( &$meta, $post_id ) { return $post_id === $requested_post_id && $single ? ( $meta[ $key ] ?? '' ) : ''; } );
		Functions\when( 'wp_cache_delete' )->justReturn( true );

		$result = $storage->extend_token_by_id( $hash );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'link_update_failed', $result->get_error_code() );
		$this->assertSame( 1, $meta[ '_previewshare_token:' . $hash ]['revoked'] );
		if ( null === $previous_db ) { unset( $GLOBALS['wpdb'] ); } else { $GLOBALS['wpdb'] = $previous_db; }
	}

	public function test_extend_token_by_id_rejects_non_expiring_links_without_writes(): void {
		$storage = $this->make_storage();
		$hash    = hash_hmac( 'sha256', 'non-expiring-token', self::HASH_KEY );
		$post_id = 655;
		$link    = [
			'hash'           => $hash,
			'label'          => 'Permanent review',
			'created_at'     => time() - 60,
			'created_by'     => 7,
			'expires_at'     => null,
			'revoked'        => 0,
			'last_viewed_at' => null,
			'view_count'     => 0,
		];

		Functions\expect( 'get_option' )
			->twice()
			->with( 'previewshare_enable_caching', true )
			->andReturn( false );
		Functions\expect( 'get_posts' )->once()->andReturn( [ $post_id ] );
		Functions\expect( 'get_post_meta' )
			->once()
			->andReturnUsing(
				static function ( int $requested_post_id, string $key, bool $single ) use ( $post_id, $hash, $link ): array {
					if ( $post_id !== $requested_post_id || true !== $single ) {
						return [];
					}

					return '_previewshare_token:' . $hash === $key
						? $link
						: [ $hash => $link ];
				}
			);
		Functions\expect( 'update_post_meta' )->never();

		$result = $storage->extend_token_by_id( $hash );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'link_not_extendable', $result->get_error_code() );
		$this->assertSame( [ 'status' => 409 ], $result->get_error_data() );
	}

	public function test_extend_token_by_id_rejects_links_outside_the_expiring_soon_window(): void {
		$storage = $this->make_storage();
		$hash    = hash_hmac( 'sha256', 'not-expiring-soon', self::HASH_KEY );
		$post_id = 656;
		$link    = [
			'hash'           => $hash,
			'label'          => 'Future review',
			'created_at'     => time() - 60,
			'created_by'     => 7,
			'expires_at'     => time() + ( 2 * DAY_IN_SECONDS ),
			'revoked'        => 0,
			'last_viewed_at' => null,
			'view_count'     => 0,
		];

		Functions\expect( 'get_option' )
			->twice()
			->with( 'previewshare_enable_caching', true )
			->andReturn( false );
		Functions\expect( 'get_posts' )->once()->andReturn( [ $post_id ] );
		Functions\expect( 'get_post_meta' )
			->once()
			->andReturnUsing(
				static function ( int $requested_post_id, string $key, bool $single ) use ( $post_id, $hash, $link ): array {
					if ( $post_id !== $requested_post_id || true !== $single ) {
						return [];
					}

					return '_previewshare_token:' . $hash === $key
						? $link
						: [ $hash => $link ];
				}
			);
		Functions\expect( 'update_post_meta' )->never();

		$result = $storage->extend_token_by_id( $hash );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'link_not_extendable', $result->get_error_code() );
		$this->assertSame( [ 'status' => 409 ], $result->get_error_data() );
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

	/** @var string */
	public $last_result_query = '';

	/** @var string */
	public $last_var_query = '';

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
		unset( $output );

		$this->get_results_calls++;
		$this->last_result_query = $query;
		$this->last_var_query    = '';
		$this->last_result_args = $this->last_prepare_args;

		$pagination_index = false !== strpos( $query, '<= %d' ) || false !== strpos( $query, '> %d' ) ? 3 : 2;
		$per_page         = isset( $this->last_prepare_args[ $pagination_index ] ) ? (int) $this->last_prepare_args[ $pagination_index ] : 50;
		$offset           = isset( $this->last_prepare_args[ $pagination_index + 1 ] ) ? (int) $this->last_prepare_args[ $pagination_index + 1 ] : 0;

		return array_slice( $this->matching_rows(), $offset, $per_page );
	}

	public function get_var( string $query ): int {
		$this->get_var_calls++;
		$this->last_var_query = $query;
		$this->last_result_query = '';
		$this->last_var_args = $this->last_prepare_args;

		return count( $this->matching_rows() );
	}

	/**
	 * @return list<array<string,mixed>>
	 */
	private function matching_rows(): array {
		$rows = array_values(
			array_filter(
				$this->rows,
				static function( array $row ): bool {
					return isset( $row['meta_key'] )
						&& str_starts_with( (string) $row['meta_key'], '_previewshare_token:' )
						&& 'revision' !== ( $row['post_type'] ?? '' );
				}
			)
		);

		if ( false === strpos( $this->last_result_query . $this->last_var_query, 'CAST(SUBSTR' ) ) {
			return $rows;
		}

		$status = false !== strpos( $this->last_result_query . $this->last_var_query, '<= %d' ) ? 'expired' : ( false !== strpos( $this->last_result_query . $this->last_var_query, '> %d' ) ? 'active' : 'revoked' );

		return array_values(
			array_filter(
				$rows,
				static function( array $row ) use ( $status ): bool {
					$detail = maybe_unserialize( $row['meta_value'] );
					$now    = time();
					$actual = ! empty( $detail['revoked'] ) ? 'revoked' : ( null !== $detail['expires_at'] && (int) $detail['expires_at'] <= $now ? 'expired' : 'active' );

					return $status === $actual;
				}
			)
		);
	}
}
