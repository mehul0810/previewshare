<?php
/**
 * Deterministic storage interleavings using WordPress metadata semantics.
 *
 * @package PreviewShare
 */

namespace PreviewShare\Tests\Unit;

use Brain\Monkey\Functions;
use PreviewShare\Services\PostMetaStorage;
use PreviewShare\Services\TokenService;

class PostMetaStorageConcurrencyTest extends TestCase {

	private const POST_ID = 123;
	private const HASH_KEY = 'previewshare-concurrency-test-key';
	private $meta = [];
	private $cache = [];
	private $post_meta_cache;
	private $before_update;
	private $before_cas;
	private $failed_key;
	private $previous_db;
	private $storage;

	protected function setUp(): void {
		parent::setUp();
		$this->storage = new PostMetaStorage( new TokenService( self::HASH_KEY ) );
		$this->previous_db = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['wpdb'] = new StorageConcurrencyDatabase( $this->meta, $this->before_cas );
		Functions\when( 'get_current_user_id' )->justReturn( 7 );
		Functions\when( 'get_option' )->alias( static function( string $key, $default = false ) { return 'previewshare_enable_caching' === $key ? true : $default; } );
		Functions\when( 'get_post_meta' )->alias( function( int $post_id, string $key = '', bool $single = false ) {
			if ( self::POST_ID !== $post_id ) { return ''; }
			if ( null === $this->post_meta_cache ) { $this->post_meta_cache = $this->meta; }
			return '' === $key ? array_map( static function( $value ): array { return [ maybe_serialize( $value ) ]; }, $this->post_meta_cache ) : ( $this->post_meta_cache[ $key ] ?? '' );
		} );
		Functions\when( 'update_post_meta' )->alias( function( int $post_id, string $key, $value, $previous = '' ) {
			if ( $this->before_update ) { ( $this->before_update )( $key ); }
			// Core constrains UPDATE only for a nonempty previous value.
			if ( self::POST_ID !== $post_id || $this->failed_key === $key || ( ! empty( $previous ) && ( $this->meta[ $key ] ?? '' ) !== $previous ) ) { return false; }
			if ( isset( $this->meta[ $key ] ) && $this->meta[ $key ] === $value ) { return false; }
			$this->meta[ $key ] = $value;
			$this->post_meta_cache = null;
			return true;
		} );
		Functions\when( 'delete_post_meta' )->alias( function( int $post_id, string $key, $previous = '' ) {
			if ( self::POST_ID !== $post_id || ( ! empty( $previous ) && ( $this->meta[ $key ] ?? '' ) !== $previous ) ) { return false; }
			unset( $this->meta[ $key ] );
			$this->post_meta_cache = null;
			return true;
		} );
		Functions\when( 'get_posts' )->alias( function( array $args ): array { return isset( $this->meta[ $args['meta_key'] ] ) ? [ self::POST_ID ] : []; } );
		Functions\when( 'wp_cache_get' )->alias( function( $key, string $group ) { return $this->cache[ $group ][ $key ] ?? false; } );
		Functions\when( 'wp_cache_set' )->alias( function( $key, $value, string $group, int $ttl = 0 ): bool { $this->cache[ $group ][ $key ] = $value; return true; } );
		Functions\when( 'wp_cache_delete' )->alias( function( $key, string $group ): bool {
			if ( 'post_meta' === $group ) { $this->post_meta_cache = null; }
			unset( $this->cache[ $group ][ $key ] );
			return true;
		} );
	}

	protected function tearDown(): void {
		if ( null === $this->previous_db ) { unset( $GLOBALS['wpdb'] ); } else { $GLOBALS['wpdb'] = $this->previous_db; }
		parent::tearDown();
	}

	/** @dataProvider initial_aggregate_states */
	public function test_initial_generation_interleaving_keeps_both_links_visible_and_revocable( bool $empty_aggregate ): void {
		if ( $empty_aggregate ) { $this->meta['_previewshare_links'] = []; }
		$nested_result = false;
		$this->before_update = function( string $key ) use ( &$nested_result ): void {
			if ( '_previewshare_links' !== $key ) { return; }
			$this->before_update = null;
			$nested_result = $this->storage->store_token( self::POST_ID, 'second', 0, 'Second', true, true );
		};
		$this->assertTrue( $this->storage->store_token( self::POST_ID, 'first', 0, 'First' ) );
		$this->assertTrue( $nested_result );
		// The real core behavior still permits a stale compatibility projection.
		$this->assertCount( 1, $this->meta['_previewshare_links'] );
		$this->assertCount( 2, $this->storage->get_links_for_post( self::POST_ID ) );
		$summary = $this->storage->get_token_meta( self::POST_ID );
		$this->assertSame( 2, $summary['active_count'] );
		$this->assertEqualsCanonicalizing( [ 'First', 'Second' ], array_column( $summary['links'], 'label' ) );
		$this->assertSame( 2, $this->storage->count_tokens() );
		$this->assertCount( 2, $this->storage->list_tokens() );
		$this->assertFalse( $this->storage->get_link_by_token( 'first' )['link']['responses_enabled'] );
		$this->assertTrue( $this->storage->get_link_by_token( 'second' )['link']['identity_required'] );
		$this->storage->flush_post_cache( self::POST_ID );
		$this->assertEmpty( $this->cache['previewshare_tokens'] );

		$this->assertTrue( $this->storage->revoke_token_for_post( self::POST_ID ) );
		$this->assertFalse( $this->meta['_previewshare_enabled'] );
		$this->assertSame( 0, $this->storage->get_token_meta( self::POST_ID )['active_count'] );
		$this->assertTrue( $this->storage->store_token( self::POST_ID, 'later', 0, 'Later' ) );
		$this->assertTrue( $this->meta['_previewshare_enabled'] );
		$this->assertNull( $this->storage->get_link_by_token( 'first' ) );
		$this->assertNull( $this->storage->get_link_by_token( 'second' ) );
		$this->assertNotNull( $this->storage->get_link_by_token( 'later' ) );
		$this->assertSame( 1, $this->storage->get_token_meta( self::POST_ID )['active_count'] );
	}

	public function initial_aggregate_states(): array {
		return [ 'missing aggregate' => [ false ], 'empty aggregate' => [ true ] ];
	}

	public function test_bulk_revoke_covers_preexisting_orphan_detail_and_refreshes_request_cache(): void {
		$this->assertTrue( $this->storage->store_token( self::POST_ID, 'orphan', 0, 'Old unlisted link' ) );
		$hash = $this->hash( 'orphan' );
		// Model an earlier request's stale cache and an incomplete old projection.
		$this->post_meta_cache = [ '_previewshare_links' => [] ];
		unset( $this->meta['_previewshare_links'], $this->meta['_previewshare_token_hash'] );
		$this->assertTrue( $this->storage->revoke_token_for_post( self::POST_ID ) );
		$this->assertSame( 1, $this->meta[ '_previewshare_token:' . $hash ]['revoked'] );
		$this->assertTrue( $this->storage->store_token( self::POST_ID, 'new', 0 ) );
		$this->assertNull( $this->storage->get_link_by_token( 'orphan' ) );
	}

	public function test_later_generation_may_survive_snapshot_revoke_without_restoring_old_sibling(): void {
		$this->assertTrue( $this->storage->store_token( self::POST_ID, 'old', 0 ) );
		$this->before_cas = function(): void {
			$this->before_cas = null;
			$this->assertTrue( $this->storage->store_token( self::POST_ID, 'new', 0 ) );
		};
		$this->assertTrue( $this->storage->revoke_token_for_post( self::POST_ID ) );
		$this->assertNull( $this->storage->get_link_by_token( 'old' ) );
		$this->assertNotNull( $this->storage->get_link_by_token( 'new' ) );
		$this->assertTrue( $this->meta['_previewshare_enabled'] );
	}

	public function test_generation_with_stale_projection_does_not_restore_a_concurrently_revoked_detail(): void {
		$this->assertTrue( $this->storage->store_token( self::POST_ID, 'old', 0 ) );
		$this->before_update = function( string $key ): void {
			if ( '_previewshare_links' !== $key ) { return; }
			$this->before_update = null;
			$this->assertTrue( $this->storage->revoke_token_for_post( self::POST_ID ) );
		};
		// The old nonempty projection CAS fails; no new link was published.
		$this->assertFalse( $this->storage->store_token( self::POST_ID, 'new', 0 ) );
		$this->assertNull( $this->storage->get_link_by_token( 'old' ) );
		$this->assertNull( $this->storage->get_link_by_token( 'new' ) );
		$this->assertTrue( $this->storage->store_token( self::POST_ID, 'later', 0 ) );
		$this->assertNull( $this->storage->get_link_by_token( 'old' ) );
	}

	/** @dataProvider required_write_failures */
	public function test_failed_publication_leaves_no_active_orphan_and_preserves_sibling( string $failed_component ): void {
		$this->assertTrue( $this->storage->store_token( self::POST_ID, 'existing', 0 ) );
		$hash = $this->hash( 'failed' );
		$this->failed_key = [
			'aggregate' => '_previewshare_links', 'current' => '_previewshare_token_hash',
			'detail' => '_previewshare_token:' . $hash, 'reverse' => '_previewshare_token_rev:' . $hash,
		][ $failed_component ];
		$this->assertFalse( $this->storage->store_token( self::POST_ID, 'failed', 0 ) );
		$this->assertArrayNotHasKey( '_previewshare_token:' . $hash, $this->meta );
		$this->assertArrayNotHasKey( '_previewshare_token_rev:' . $hash, $this->meta );
		$this->assertSame( $this->hash( 'existing' ), $this->meta['_previewshare_token_hash'] );
		$this->assertCount( 1, $this->storage->get_links_for_post( self::POST_ID ) );
		$this->assertNull( $this->storage->get_link_by_token( 'failed' ) );
		$this->assertNotNull( $this->storage->get_link_by_token( 'existing' ) );
	}

	public function required_write_failures(): array {
		return array_map( static function( string $key ): array { return [ $key ]; }, [ 'aggregate', 'current', 'detail', 'reverse' ] );
	}

	public function test_sequential_generation_and_single_revoke_preserve_normal_siblings(): void {
		$this->assertTrue( $this->storage->store_token( self::POST_ID, 'first', 0, 'One' ) );
		$this->assertTrue( $this->storage->store_token( self::POST_ID, 'second', 0, 'Two' ) );
		$this->assertCount( 2, $this->meta['_previewshare_links'] );
		$this->assertTrue( $this->storage->revoke_token( 'first' ) );
		$this->assertNull( $this->storage->get_link_by_token( 'first' ) );
		$this->assertNotNull( $this->storage->get_link_by_token( 'second' ) );
		$this->assertSame( 1, $this->storage->get_token_meta( self::POST_ID )['active_count'] );
	}

	private function hash( string $token ): string {
		return hash_hmac( 'sha256', $token, self::HASH_KEY );
	}
}

/** In-memory database for existing-row CAS and detail inventory queries. */
class StorageConcurrencyDatabase {
	public $postmeta = 'wp_postmeta';
	public $posts = 'wp_posts';
	private $meta;
	private $before_cas;

	public function __construct( array &$meta, &$before_cas ) {
		$this->meta = &$meta;
		$this->before_cas = &$before_cas;
	}

	public function prepare( string $sql, ...$args ): string { return serialize( [ $sql, $args ] ); }
	public function esc_like( string $value ): string { return addcslashes( $value, '_%\\' ); }

	public function query( string $prepared ) {
		if ( $this->before_cas ) { ( $this->before_cas )(); }
		[ $sql, $args ] = unserialize( $prepared );
		[ $value, $post_id, $key, $previous ] = $args;
		if ( ! isset( $this->meta[ $key ] ) || maybe_serialize( $this->meta[ $key ] ) !== $previous ) { return 0; }
		$this->meta[ $key ] = maybe_unserialize( $value );
		return 1;
	}

	public function get_var( string $prepared ): int {
		[ $sql, $args ] = unserialize( $prepared );
		return false !== strpos( $sql, 'COUNT(*)' ) ? count( $this->get_results( $prepared, ARRAY_A ) ) : ( isset( $this->meta[ $args[0] ] ) ? 123 : 0 );
	}

	public function get_results( string $prepared, string $output ): array {
		$rows = [];
		foreach ( $this->meta as $key => $value ) {
			if ( 0 === strpos( $key, '_previewshare_token:' ) ) {
				$rows[] = [ 'post_id' => 123, 'meta_key' => $key, 'meta_value' => maybe_serialize( $value ) ];
			}
		}
		return $rows;
	}
}
