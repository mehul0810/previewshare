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
}

class StatusInventoryFixtureDatabase {
	public $postmeta = 'wp_postmeta';
	public $posts = 'wp_posts';
	public $last_error = '';
	public $unknown = null;
	public $rows = [];
	public $batches = 0;
	public $fail = false;
	public function esc_like( string $value ): string { return addcslashes( $value, '_%\\' ); }
	public function prepare( string $sql, ...$args ): string { return serialize( [ $sql, $args ] ); }
	public function get_var( string $query ) { return $this->unknown; }
	public function get_results( string $query, string $output ): array {
		if ( $this->fail ) { $this->last_error = 'query failed'; return []; }
		[ $sql, $args ] = unserialize( $query );
		++$this->batches;
		$rows = array_filter( $this->rows, static function ( array $row ) use ( $args ): bool { return $row['meta_id'] < $args[2]; } );
		return array_slice( array_values( $rows ), 0, $args[3] );
	}
}
