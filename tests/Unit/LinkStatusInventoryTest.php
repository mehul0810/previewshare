<?php
/** Status filtering of noncanonical link metadata. */
namespace PreviewShare\Tests\Unit;

use PreviewShare\Services\LinkStatusInventory;
use Brain\Monkey\Functions;

class LinkStatusInventoryTest extends TestCase {
	private $previous_database;

	protected function setUp(): void {
		parent::setUp();
		$this->previous_database = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['wpdb'] = new StatusInventoryFixtureDatabase();
		Functions\when( '__' )->returnArg( 1 );
	}

	protected function tearDown(): void {
		$GLOBALS['wpdb'] = $this->previous_database;
		parent::tearDown();
	}

	public function test_known_layouts_use_the_existing_sql_path(): void {
		$result = LinkStatusInventory::fallback_if_needed( '_previewshare_token:', 'active', 10, 0, static function ( array $rows ): array { return $rows; } );
		self::assertNull( $result );
	}

	public function test_unknown_layouts_use_php_status_across_bounded_pages(): void {
		$db = $GLOBALS['wpdb'];
		$db->unknown = 1;
		for ( $id = 405; $id > 0; --$id ) {
			$db->rows[] = [ 'meta_id' => $id, 'status' => 0 === $id % 2 ? 'revoked' : 'active' ];
		}
		$result = LinkStatusInventory::fallback_if_needed( '_previewshare_token:', 'active', 3, 100, static function ( array $rows ): array { return $rows; } );
		self::assertSame( 203, $result['total'] );
		self::assertSame( [ 205, 203, 201 ], array_column( $result['items'], 'meta_id' ) );
		self::assertSame( 3, $db->batches );
	}

	public function test_failed_scan_is_not_reported_as_a_partial_success(): void {
		$GLOBALS['wpdb']->unknown = 1;
		$GLOBALS['wpdb']->fail = true;
		$result = LinkStatusInventory::fallback_if_needed( '_previewshare_token:', 'active', 10, 0, static function ( array $rows ): array { return $rows; } );
		self::assertSame( 'previewshare_inventory_unavailable', $result->get_error_code() );
	}

	public function test_aborted_fallback_query_is_not_reported_as_an_empty_success(): void {
		$db = $GLOBALS['wpdb'];
		$db->unknown = 1;
		$db->abort_fallback = true;
		$db->last_result = [ (object) [ 'meta_id' => 500, 'post_id' => 1, 'meta_key' => '_previewshare_token:stale', 'meta_value' => '' ] ];
		$result = LinkStatusInventory::fallback_if_needed( '_previewshare_token:', 'active', 10, 0, static function ( array $rows ): array { return $rows; } );
		self::assertSame( 'previewshare_inventory_unavailable', $result->get_error_code() );
	}

	public function test_successful_empty_fallback_scan_remains_a_valid_empty_result(): void {
		$db = $GLOBALS['wpdb'];
		$db->unknown = 1;
		$result = LinkStatusInventory::fallback_if_needed( '_previewshare_token:', 'active', 10, 0, static function ( array $rows ): array { return $rows; } );
		self::assertSame( [ 'items' => [], 'total' => 0 ], $result );
	}
}

class StatusInventoryFixtureDatabase {
	public $postmeta = 'wp_postmeta';
	public $posts = 'wp_posts';
	public $last_error = '';
	public $unknown = null;
	public $rows = [];
	public $batches = 0;
	public $fail = false;
	public $abort_fallback = false;
	public $last_result = [];
	public function esc_like( string $value ): string { return addcslashes( $value, '_%\\' ); }
	public function prepare( string $sql, ...$args ): string { return serialize( [ $sql, $args ] ); }
	public function query( string $query ) {
		[ $sql, $args ] = unserialize( $query );
		if ( false !== strpos( $sql, 'SELECT pm.meta_id FROM' ) ) {
			$this->last_result = null === $this->unknown ? [] : [ (object) [ 'meta_id' => $this->unknown ] ];
			return null === $this->unknown ? 0 : 1;
		}
		++$this->batches;
		if ( $this->fail || $this->abort_fallback ) { return false; }
		$rows = array_filter( $this->rows, static function ( array $row ) use ( $args ): bool { return $row['meta_id'] < $args[2]; } );
		$rows = array_slice( array_values( $rows ), 0, $args[3] );
		$this->last_result = array_map( static fn( array $row ): object => (object) $row, $rows );
		return count( $rows );
	}
}
