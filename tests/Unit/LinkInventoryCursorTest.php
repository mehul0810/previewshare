<?php
/** Cursor traversal and inventory-change handling. */
namespace PreviewShare\Tests\Unit;

use Brain\Monkey\Functions;
use PreviewShare\Services\LinkInventoryCursor;

class LinkInventoryCursorTest extends TestCase {
	private $previous_database;

	protected function setUp(): void {
		parent::setUp();
		$this->previous_database = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['wpdb'] = new CursorFixtureDatabase();
		Functions\when( '__' )->returnArg( 1 );
	}

	protected function tearDown(): void {
		$GLOBALS['wpdb'] = $this->previous_database;
		parent::tearDown();
	}

	public function test_continuation_detects_equal_total_replacement(): void {
		$db = $GLOBALS['wpdb'];
		$db->ids = [ 3, 2, 1 ];
		$cursor = new LinkInventoryCursor( '_previewshare_token:' );
		$first = $cursor->page( 2, 'start' );
		self::assertSame( [ 3, 2 ], array_column( $first['rows'], 'meta_id' ) );
		self::assertSame( 3, $first['total'] );
		self::assertIsString( $first['next_cursor'] );
		$db->ids = [ 4, 3, 2 ];
		$next = $cursor->page( 2, $first['next_cursor'] );
		self::assertSame( 'previewshare_inventory_changed', $next->get_error_code() );
	}

	public function test_stable_pages_have_no_overlap_and_stop_at_end(): void {
		$GLOBALS['wpdb']->ids = [ 3, 2, 1 ];
		$cursor = new LinkInventoryCursor( '_previewshare_token:' );
		$first = $cursor->page( 2, 'start' );
		$second = $cursor->page( 2, $first['next_cursor'] );
		self::assertSame( [ 1 ], array_column( $second['rows'], 'meta_id' ) );
		self::assertNull( $second['next_cursor'] );
	}

	public function test_change_during_page_read_is_not_returned_as_a_consistent_snapshot(): void {
		$GLOBALS['wpdb']->ids = [ 3, 2, 1 ];
		$GLOBALS['wpdb']->change_on_page = true;
		$result = ( new LinkInventoryCursor( '_previewshare_token:' ) )->page( 2, 'start' );
		self::assertSame( 'previewshare_inventory_changed', $result->get_error_code() );
	}

	public function test_invalid_cursor_and_database_error_are_reported(): void {
		$cursor = new LinkInventoryCursor( '_previewshare_token:' );
		self::assertSame( 'previewshare_invalid_cursor', $cursor->page( 2, 'bad!' )->get_error_code() );
		$GLOBALS['wpdb']->failed = true;
		self::assertSame( 'previewshare_inventory_unavailable', $cursor->page( 2, 'start' )->get_error_code() );
	}
}

class CursorFixtureDatabase {
	public $postmeta = 'wp_postmeta';
	public $posts = 'wp_posts';
	public $last_error = '';
	public $ids = [];
	public $failed = false;
	public $change_on_page = false;
	public function esc_like( string $value ): string { return addcslashes( $value, '_%\\' ); }
	public function prepare( string $sql, ...$args ): string { return serialize( [ $sql, $args ] ); }
	public function get_row( string $query, string $output ) {
		if ( $this->failed ) { $this->last_error = 'failure'; return null; }
		return [ 'total' => count( $this->ids ), 'max_id' => $this->ids ? max( $this->ids ) : 0 ];
	}
	public function get_results( string $query, string $output ): array {
		[ $sql, $args ] = unserialize( $query );
		$bound = $args[2];
		$limit = $args[3];
		$inclusive = false !== strpos( $sql, 'pm.meta_id <= %d' );
		$ids = array_values( array_filter( $this->ids, static function ( int $id ) use ( $bound, $inclusive ): bool { return $inclusive ? $id <= $bound : $id < $bound; } ) );
		rsort( $ids );
		if ( $this->change_on_page ) { $this->ids = [ 4, 3, 2 ]; }
		return array_map( static function ( int $id ): array { return [ 'meta_id' => $id, 'post_id' => $id, 'meta_key' => '_previewshare_token:' . $id, 'meta_value' => '' ]; }, array_slice( $ids, 0, $limit ) );
	}
}
