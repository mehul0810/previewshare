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
	private $before_flag_write;
	private $failed_key;
	private $caching_enabled = true;
	private $previous_db;
	private $storage;

	protected function setUp(): void {
		parent::setUp();
		$this->storage = new PostMetaStorage( new TokenService( self::HASH_KEY ) );
		$this->previous_db = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['wpdb'] = new StorageConcurrencyDatabase( $this->meta, $this->before_cas, $this->before_flag_write );
		Functions\when( 'get_current_user_id' )->justReturn( 7 );
		Functions\when( 'get_object_subtype' )->justReturn( 'post' );
		Functions\when( 'sanitize_meta' )->returnArg( 2 );
		Functions\when( 'get_option' )->alias( function( string $key, $default = false ) { return 'previewshare_enable_caching' === $key ? $this->caching_enabled : $default; } );
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

	public function test_cache_flush_reads_fresh_link_keys_without_evicting_post_metadata(): void {
		$this->assertTrue( $this->storage->store_token( self::POST_ID, 'first', 0 ) );
		$stale_meta = [ '_previewshare_links' => [] ];
		$this->post_meta_cache = $stale_meta;
		$this->storage->flush_post_cache( self::POST_ID );
		$this->assertEmpty( $this->cache['previewshare_tokens'] );
		$this->assertSame( $stale_meta, $this->post_meta_cache );
		$this->assertSame( 1, $GLOBALS['wpdb']->col_reads );
		$this->caching_enabled = false;
		$this->storage->flush_post_cache( self::POST_ID );
		$this->assertSame( 1, $GLOBALS['wpdb']->col_reads );
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

	public function test_bulk_revoke_updates_the_aggregate_once_for_multiple_links(): void {
		foreach ( range( 1, 20 ) as $index ) {
			$this->assertTrue( $this->storage->store_token( self::POST_ID, 'link-' . $index, 0 ) );
		}
		$writes = 0;
		$this->before_cas = static function( array $args ) use ( &$writes ): void {
			if ( '_previewshare_links' === $args[2] ) {
				++$writes;
			}
		};

		$this->assertTrue( $this->storage->revoke_token_for_post( self::POST_ID ) );
		$this->assertSame( 1, $writes );
		$this->assertSame( array_fill( 0, 20, 1 ), array_column( $this->meta['_previewshare_links'], 'revoked' ) );
	}

	public function test_bulk_projection_retry_preserves_concurrent_generation_and_deletion(): void {
		$this->assertTrue( $this->storage->store_token( self::POST_ID, 'retain', 0 ) );
		$this->assertTrue( $this->storage->store_token( self::POST_ID, 'delete', 0 ) );
		$this->before_cas = function( array $args ): void {
			if ( '_previewshare_links' !== $args[2] ) {
				return;
			}
			$this->before_cas = null;
			$this->assertTrue( $this->storage->store_token( self::POST_ID, 'new', 0 ) );
			$this->assertTrue( $this->storage->set_review_policy_by_id( $this->hash( 'retain' ), true, true ) );
			$this->assertTrue( $this->storage->delete_token_by_id( $this->hash( 'delete' ) ) );
		};

		$this->assertTrue( $this->storage->revoke_token_for_post( self::POST_ID ) );
		$this->assertSame( 1, $this->meta['_previewshare_links'][ $this->hash( 'retain' ) ]['revoked'] );
		$this->assertTrue( $this->meta['_previewshare_links'][ $this->hash( 'retain' ) ]['identity_required'] );
		$this->assertArrayNotHasKey( $this->hash( 'delete' ), $this->meta['_previewshare_links'] );
		$this->assertArrayNotHasKey( '_previewshare_token:' . $this->hash( 'delete' ), $this->meta );
		$this->assertSame( 0, $this->meta['_previewshare_links'][ $this->hash( 'new' ) ]['revoked'] );
		$this->assertNotNull( $this->storage->get_link_by_token( 'new' ) );
	}

	public function test_bulk_projection_does_not_restore_a_deleted_aggregate_row(): void {
		$this->assertTrue( $this->storage->store_token( self::POST_ID, 'shared', 0 ) );
		$this->before_cas = function( array $args ): void {
			if ( '_previewshare_links' === $args[2] ) {
				unset( $this->meta['_previewshare_links'] );
			}
		};
		$this->assertTrue( $this->storage->revoke_token_for_post( self::POST_ID ) );
		$this->assertArrayNotHasKey( '_previewshare_links', $this->meta );
		$this->assertNull( $this->storage->get_link_by_token( 'shared' ) );
	}

	public function test_revoking_last_active_link_disables_sharing_with_inactive_legacy_detail(): void {
		$this->assertTrue( $this->storage->store_token( self::POST_ID, 'active', 0, 's:7:"revoked";' ) );
		$this->meta[ '_previewshare_token:' . $this->hash( 'expired-legacy' ) ] = [ 'created_at' => time() - 10, 'user_id' => 1, 'expires_at' => time() - 1, 'revoked' => false ];
		$this->assertTrue( $this->storage->revoke_token( 'active' ) );
		$this->assertFalse( $this->meta['_previewshare_enabled'] );
	}

	public function test_sharing_guard_preserves_metadata_sanitization_and_core_actions(): void {
		$this->assertTrue( $this->storage->store_token( self::POST_ID, 'shared', 0 ) );
		$actions = [];
		Functions\when( 'sanitize_meta' )->alias( function( string $key, $value, string $type, string $subtype ): int {
			$this->assertSame( [ '_previewshare_enabled', false, 'post', 'post' ], [ $key, $value, $type, $subtype ] );
			return 0;
		} );
		Functions\when( 'do_action' )->alias( function( string $hook, $row_id, int $post_id, string $key, $value ) use ( &$actions ): void {
			if ( '_previewshare_enabled' === $key ) {
				$actions[] = [ $hook, $row_id, $post_id, $value, $this->meta[ $key ] ];
			}
		} );
		// Omit the projection so only the flag update invokes sanitize_meta.
		unset( $this->meta['_previewshare_links'] );

		$this->assertTrue( $this->storage->revoke_token( 'shared' ) );
		$this->assertSame( 0, $this->meta['_previewshare_enabled'] );
		$this->assertSame(
			[
				[ 'update_post_meta', '17', self::POST_ID, 0, true ],
				[ 'update_postmeta', '17', self::POST_ID, 0, true ],
				[ 'updated_post_meta', '17', self::POST_ID, 0, 0 ],
				[ 'updated_postmeta', '17', self::POST_ID, 0, 0 ],
			],
			$actions
		);
	}

	public function test_sharing_update_can_be_short_circuited_by_core_metadata_filter(): void {
		$this->meta['_previewshare_enabled'] = true;
		Functions\when( 'apply_filters' )->alias( function( ...$args ): bool {
			$this->assertSame( [ 'update_post_metadata', null, self::POST_ID, '_previewshare_enabled', false, '' ], $args );
			return false;
		} );
		Functions\expect( 'do_action' )->never();
		$this->before_flag_write = function(): void { $this->fail( 'Short-circuited flag write reached the database' ); };

		$this->assertFalse( $this->storage->revoke_token_for_post( self::POST_ID ) );
		$this->assertTrue( $this->meta['_previewshare_enabled'] );
	}

	public function test_sharing_guard_mismatch_does_not_fire_after_actions(): void {
		$this->meta['_previewshare_enabled'] = true;
		$actions = [];
		Functions\when( 'do_action' )->alias( static function( string $hook ) use ( &$actions ): void { $actions[] = $hook; } );
		$this->before_flag_write = function(): void {
			$this->before_flag_write = null;
			$this->assertTrue( $this->storage->store_token( self::POST_ID, 'new', 0 ) );
		};

		$this->assertFalse( $this->storage->revoke_token_for_post( self::POST_ID ) );
		$this->assertTrue( $this->meta['_previewshare_enabled'] );
		$this->assertSame( [ 'update_post_meta', 'update_postmeta' ], $actions );
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

	public function test_generation_does_not_report_success_when_revoked_before_publication_finishes(): void {
		$this->before_update = function( string $key ): void {
			if ( '_previewshare_enabled' === $key ) {
				$this->before_update = null;
				$this->assertTrue( $this->storage->revoke_token_for_post( self::POST_ID ) );
			}
		};

		$this->assertFalse( $this->storage->store_token( self::POST_ID, 'revoked-before-publication', 0 ) );
		$this->assertNull( $this->storage->get_link_by_token( 'revoked-before-publication' ) );
		$this->assertFalse( $this->meta['_previewshare_enabled'] );
	}

	public function test_summary_for_inactive_links_does_not_change_sharing(): void {
		$this->assertTrue( $this->storage->store_token( self::POST_ID, 'expired', 0 ) );
		$this->meta[ '_previewshare_token:' . $this->hash( 'expired' ) ]['expires_at'] = time() - 1;
		$this->before_update = function( string $key ): void {
			$this->fail( 'Reading the summary attempted to write ' . $key );
		};

		$this->assertSame( 0, $this->storage->get_token_meta( self::POST_ID )['active_count'] );
		$this->assertTrue( $this->meta['_previewshare_enabled'] );
	}

	/** @dataProvider revoke_scenarios */
	public function test_generation_at_sharing_disable_preserves_new_link( string $scenario ): void {
		if ( 'empty snapshot' !== $scenario ) {
			$this->assertTrue( $this->storage->store_token( self::POST_ID, 'old', 0 ) );
		} else {
			$this->meta['_previewshare_enabled'] = true;
		}
		$generate = function(): void {
			$this->before_flag_write = null;
			$this->before_update = null;
			$this->assertTrue( $this->storage->store_token( self::POST_ID, 'new', 0 ) );
		};
		$this->before_flag_write = $generate;
		$this->before_update = static function( string $key ) use ( $generate ): void {
			if ( '_previewshare_enabled' === $key ) {
				$generate();
			}
		};

		if ( 'single revoke' === $scenario ) {
			$this->assertTrue( $this->storage->revoke_token( 'old' ) );
		} else {
			$this->assertSame( 'bulk revoke' === $scenario, $this->storage->revoke_token_for_post( self::POST_ID ) );
		}
		$this->assertNull( $this->storage->get_link_by_token( 'old' ) );
		$this->assertNotNull( $this->storage->get_link_by_token( 'new' ) );
		$this->assertTrue( $this->meta['_previewshare_enabled'] );
	}

	public function revoke_scenarios(): array {
		return [ [ 'single revoke' ], [ 'bulk revoke' ], [ 'empty snapshot' ] ];
	}

	public function test_concurrent_views_each_persist_their_increment(): void {
		$this->assertTrue( $this->storage->store_token( self::POST_ID, 'shared', 0 ) );
		$this->before_cas = function( array $args ): void {
			$this->before_cas = null;
			$this->assertTrue( $this->storage->record_token_view( 'shared' ) );
			// Keep both writes in the same logical second even if the clock ticks here.
			$this->meta[ $args[2] ]['last_viewed_at'] = maybe_unserialize( $args[0] )['last_viewed_at'];
		};

		$this->assertTrue( $this->storage->record_token_view( 'shared' ) );
		$this->assertSame( 2, $this->storage->get_link_by_token( 'shared' )['link']['view_count'] );
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
	public $col_reads = 0;
	public $postmeta = 'wp_postmeta';
	public $posts = 'wp_posts';
	private $meta;
	private $before_cas;
	private $before_flag_write;

	public function __construct( array &$meta, &$before_cas, &$before_flag_write ) {
		$this->meta = &$meta;
		$this->before_cas = &$before_cas;
		$this->before_flag_write = &$before_flag_write;
	}

	public function prepare( string $sql, ...$args ): string { return serialize( [ $sql, $args ] ); }
	public function esc_like( string $value ): string { return addcslashes( $value, '_%\\' ); }

	public function query( string $prepared ) {
		[ $sql, $args ] = unserialize( $prepared );
		if ( str_contains( $sql, 'SET sharing.meta_value' ) ) {
			return $this->disable_sharing( $sql, $args );
		}
		if ( $this->before_cas ) { ( $this->before_cas )( $args ); }
		[ $value, $post_id, $key, $previous ] = $args;
		if ( ! isset( $this->meta[ $key ] ) || maybe_serialize( $this->meta[ $key ] ) !== $previous ) { return 0; }
		$this->meta[ $key ] = maybe_unserialize( $value );
		return 1;
	}

	private function disable_sharing( string $sql, array $args ): int {
		if ( $this->before_flag_write ) { ( $this->before_flag_write )(); }
		[ $prefix, $canonical, $now ] = $args;
		[ $value, $post_id, $key, $row_id ] = array_slice( $args, -4 );
		$known = [];
		foreach ( array_chunk( array_slice( $args, 3, -4 ), 2 ) as [ $meta_key, $encoded ] ) {
			$known[ $meta_key ] = $encoded;
		}
		if ( ! str_contains( $sql, 'pm.meta_id IS NULL' ) || 123 !== $post_id || 17 !== $row_id || empty( $this->meta[ $key ] ) ) {
			return 0;
		}
		foreach ( $this->meta as $meta_key => $link ) {
			if ( ! str_starts_with( $meta_key, stripslashes( substr( $prefix, 0, -1 ) ) ) ) {
				continue;
			}
			$encoded = maybe_serialize( $link );
			if ( ( $known[ $meta_key ] ?? null ) === $encoded ) {
				continue;
			}
			if ( ! preg_match( "~$canonical~", $encoded ) || ( empty( $link['revoked'] ) && ( ! isset( $link['expires_at'] ) || $link['expires_at'] > $now ) ) ) {
				return 0;
			}
		}
		$this->meta[ $key ] = $value;
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

	public function get_col( string $prepared ): array {
		[ $sql, $args ] = unserialize( $prepared );
		if ( str_starts_with( $sql, 'SELECT meta_id' ) ) {
			return array_key_exists( $args[1], $this->meta ) ? [ '17' ] : [];
		}
		++$this->col_reads;
		return array_column( $this->get_results( $prepared, ARRAY_A ), 'meta_key' );
	}
}
