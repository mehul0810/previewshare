<?php
/** Cursor traversal and inventory-change handling. */
namespace PreviewShare\Tests\Unit;

use Brain\Monkey\Functions;
use PreviewShare\Services\LinkInventoryCursor;
use PreviewShare\Services\PostMetaRow;

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

	public function test_each_page_reads_rows_and_summary_together(): void {
		$db = $GLOBALS['wpdb'];
		$db->ids = [ 3, 2, 1 ];
		$db->after_read = static function ( CursorFixtureDatabase $database ): void {
			$database->ids = [ 4, 3, 2 ];
		};
		$cursor = new LinkInventoryCursor( '_previewshare_token:' );
		$first = $cursor->page( 2, 'start' );
		self::assertSame( [ 3, 2 ], array_column( $first['rows'], 'meta_id' ) );
		self::assertSame( 1, $db->queries );
		self::assertSame( 'previewshare_inventory_changed', $cursor->page( 2, $first['next_cursor'] )->get_error_code() );
		self::assertSame( 2, $db->queries );
	}

	/** @dataProvider edit_cases */
	public function test_in_place_edit_of_previously_returned_link_requires_refresh( array $detail ): void {
		$db = $GLOBALS['wpdb'];
		$db->ids = [ 3, 2, 1 ];
		$cursor = new LinkInventoryCursor( '_previewshare_token:' );
		$first  = $cursor->page( 2, 'start' );

		// Both links may receive the same per-row generation after their first edit.
		$db->details[3] = PostMetaRow::with_inventory_revision( 3, [], $detail, true );
		$db->details[2] = PostMetaRow::with_inventory_revision( 3, [], $detail, true );
		self::assertMatchesRegularExpression( '/^[0-9]{32}$/D', $db->details[3]['inventory_revision'] );
		self::assertSame( $db->details[3]['inventory_revision'], $db->details[2]['inventory_revision'] );
		self::assertSame( 'previewshare_inventory_changed', $cursor->page( 2, $first['next_cursor'] )->get_error_code() );
	}

	public function edit_cases(): array {
		return [
			'revoke' => [ [ 'revoked' => 1 ] ],
			'extend' => [ [ 'expires_at' => 7200 ] ],
			'policy' => [ [ 'responses_enabled' => true, 'identity_required' => true ] ],
		];
	}

	public function test_swapping_policies_between_different_links_changes_the_summary(): void {
		$db = $GLOBALS['wpdb'];
		$db->ids = [ 3, 2, 1 ];
		$first_link = [ 'hash' => 'first', 'responses_enabled' => true ];
		$second_link = [ 'hash' => 'second', 'responses_enabled' => false ];
		$db->details = [
			3 => PostMetaRow::with_inventory_revision( 10, [], $first_link, true ),
			2 => PostMetaRow::with_inventory_revision( 10, [], $second_link, true ),
		];
		$cursor = new LinkInventoryCursor( '_previewshare_token:' );
		$first = $cursor->page( 2, 'start' );
		$first_link['responses_enabled'] = false;
		$second_link['responses_enabled'] = true;
		$db->details[3] = PostMetaRow::with_inventory_revision( 10, [], $first_link, true );
		$db->details[2] = PostMetaRow::with_inventory_revision( 10, [], $second_link, true );
		self::assertSame( 'previewshare_inventory_changed', $cursor->page( 2, $first['next_cursor'] )->get_error_code() );
	}

	public function test_wordpress_meta_write_stamps_the_effective_unslashed_label(): void {
		Functions\when( 'wp_unslash' )->alias( static fn( array $value ): array => array_map( 'stripslashes', $value ) );
		$once_slashed = 'Client' . chr( 92 ) . 'review';
		$twice_slashed = 'Client' . str_repeat( chr( 92 ), 2 ) . 'review';
		$previous = PostMetaRow::with_inventory_revision( 10, [], [ 'label' => $once_slashed ], true );
		$record_with_same_label = PostMetaRow::stamp_new_link( 10, [ 'label' => $twice_slashed ] );
		$record_without_slash = PostMetaRow::stamp_new_link( 10, [ 'label' => $once_slashed ] );
		self::assertSame( $twice_slashed, $record_with_same_label['label'] );
		self::assertSame( $once_slashed, $record_without_slash['label'] );
		self::assertSame( $previous['inventory_revision'], $record_with_same_label['inventory_revision'] );
		self::assertNotSame( $previous['inventory_revision'], $record_without_slash['inventory_revision'] );
	}

	public function test_legacy_and_stamped_view_changes_leave_a_continuation_valid(): void {
		$db = $GLOBALS['wpdb'];
		$db->ids = [ 3, 2, 1 ];
		$db->details = [ 3 => [ 'view_count' => 2, 'inventory_revision' => '00000004000000110000022200003333' ], 2 => [ 'view_count' => 3 ] ];
		$cursor = new LinkInventoryCursor( '_previewshare_token:' );
		$first = $cursor->page( 2, 'start' );
		$db->details[3] = PostMetaRow::with_inventory_revision( 3, $db->details[3], [ 'view_count' => 3 ], false );
		$db->details[2] = PostMetaRow::with_inventory_revision( 2, $db->details[2], [ 'view_count' => 4 ], false );
		self::assertSame( '00000004000000110000022200003333', $db->details[3]['inventory_revision'] );
		self::assertArrayNotHasKey( 'inventory_revision', $db->details[2] );
		self::assertSame( [ 1 ], array_column( $cursor->page( 2, $first['next_cursor'] )['rows'], 'meta_id' ) );
	}

	public function test_older_cursor_requests_refresh_without_querying_database(): void {
		$cursor = new LinkInventoryCursor( '_previewshare_token:' );
		$result = $cursor->page( 2, 'MzozOjI' ); // Legacy cursor for metadata ID 2 of 3.
		self::assertSame( 'previewshare_inventory_changed', $result->get_error_code() );
		self::assertSame( 0, $GLOBALS['wpdb']->queries );
	}

	public function test_empty_inventory_has_no_placeholder_item(): void {
		$result = ( new LinkInventoryCursor( '_previewshare_token:' ) )->page( 2, 'start' );
		self::assertSame( [ 'rows' => [], 'total' => 0, 'next_cursor' => null ], $result );
	}

	public function test_same_count_and_maximum_with_different_membership_requires_refresh(): void {
		$db = $GLOBALS['wpdb'];
		$db->ids = [ 4, 3, 1 ];
		$cursor = new LinkInventoryCursor( '_previewshare_token:' );
		$first = $cursor->page( 2, 'start' );
		$db->ids = [ 4, 3, 2 ];
		self::assertSame( 'previewshare_inventory_changed', $cursor->page( 2, $first['next_cursor'] )->get_error_code() );
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
	public $last_result = [];
	public $ids = [];
	public $details = [];
	public $failed = false;
	public $after_read;
	public $queries = 0;

	public function esc_like( string $value ): string { return addcslashes( $value, '_%\\' ); }
	public function prepare( string $sql, ...$args ): string { return serialize( [ $sql, $args ] ); }

	public function query( string $query ) {
		++$this->queries;
		if ( $this->failed ) {
			// Simulate a drop-in leaving stale rows and clearing last_error.
			$this->last_result = [ (object) [ 'total' => 4, 'max_id' => 4, 'id_sum' => 10, 'revision_sums' => '0:0:0:0', 'meta_id' => null ] ];
			return false;
		}

		[ $sql, $args ] = unserialize( $query );
		TestCase::assertStringContainsString( 'LEFT JOIN', $sql );
		TestCase::assertStringContainsString( 'SUM(', $sql );
		TestCase::assertSame( 'a:11:{s:18:"inventory_revision";s:32:"', $args[0] );
		$has_bound = str_contains( $sql, 'AND pm.meta_id < %d' );
		$bound = $has_bound ? $args[8] : PHP_INT_MAX;
		$limit = $args[ $has_bound ? 9 : 8 ];
		$ids = array_values( array_filter( $this->ids, static fn( int $id ): bool => $id < $bound ) );
		rsort( $ids );
		$sums = [ 0, 0, 0, 0 ];
		foreach ( $this->ids as $id ) {
			$chunks = str_split( $this->details[ $id ]['inventory_revision'] ?? '', 8 );
			foreach ( $chunks as $index => $chunk ) {
				$sums[ $index ] += (int) $chunk;
			}
		}
		$summary = [
			'total' => count( $this->ids ),
			'max_id' => $this->ids ? max( $this->ids ) : 0,
			'id_sum' => array_sum( $this->ids ),
			'revision_sums' => implode( ':', $sums ),
		];
		$rows = array_map(
			fn( int $id ): object => (object) ( $summary + [ 'meta_id' => $id, 'post_id' => $id, 'meta_key' => '_previewshare_token:' . $id, 'meta_value' => serialize( $this->details[ $id ] ?? [] ) ] ),
			array_slice( $ids, 0, $limit )
		);
		$this->last_result = $rows ?: [ (object) ( $summary + [ 'meta_id' => null ] ) ];
		if ( null !== $this->after_read ) {
			( $this->after_read )( $this );
			$this->after_read = null;
		}
		return count( $this->last_result );
	}
}
