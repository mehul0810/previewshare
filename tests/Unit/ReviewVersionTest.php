<?php
/**
 * Reviewer version-state tests.
 *
 * @package PreviewShare
 */

namespace PreviewShare\Tests\Unit;

use Brain\Monkey\Functions;
use PreviewShare\Services\ReviewVersion;

class ReviewVersionTest extends TestCase {
	/** @var array<string,array<int,string>> */
	private $metadata = [];

	/** @var array<int,object> */
	private $terms = [];

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'get_post_meta' )->alias(
			function () {
				return $this->metadata;
			}
		);
		Functions\when( 'get_object_taxonomies' )->justReturn( [ 'category' ] );
		Functions\when( 'get_object_term_cache' )->alias(
			function () {
				return $this->terms;
			}
		);
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'get_post' )->justReturn( null );
	}

	public function test_content_edit_makes_an_approval_stale(): void {
		$post = new \WP_Post(
			[
				'post_title'   => 'Launch',
				'post_content' => 'First draft',
			]
		);
		$reviewed_hash = ReviewVersion::fingerprint( $post );

		self::assertSame(
			'approved',
			ReviewVersion::state( [ 'response_type' => 'approve', 'content_hash' => $reviewed_hash ], $reviewed_hash )
		);

		$post->post_content = 'Revised draft';

		self::assertSame(
			'stale',
			ReviewVersion::state( [ 'response_type' => 'approve', 'content_hash' => $reviewed_hash ], ReviewVersion::fingerprint( $post ) )
		);
	}

	public function test_resolving_changes_preserves_pending_state(): void {
		self::assertSame( 'pending', ReviewVersion::state( null, 'current' ) );
		self::assertSame( 'changes_requested', ReviewVersion::state( [ 'response_type' => 'request_changes' ], 'current' ) );
		self::assertSame( 'pending', ReviewVersion::state( [ 'response_type' => 'request_changes', 'resolved_at' => 123 ], 'current' ) );
	}

	public function test_snapshot_is_signed_for_its_link_and_rejects_tampering(): void {
		Functions\when( 'wp_salt' )->justReturn( 'test-site-secret' );
		$post     = new \WP_Post( [ 'ID' => 42, 'post_content' => 'Draft A' ] );
		$snapshot = ReviewVersion::issue_snapshot( $post, 'link-hash-a' );
		$tampered = ( '0' === $snapshot[0] ? '1' : '0' ) . substr( $snapshot, 1 );

		self::assertSame( ReviewVersion::fingerprint( $post ), ReviewVersion::verify_snapshot( $snapshot, 'link-hash-a' ) );
		self::assertNull( ReviewVersion::verify_snapshot( $snapshot, 'link-hash-b' ) );
		self::assertNull( ReviewVersion::verify_snapshot( $tampered, 'link-hash-a' ) );
		self::assertNull( ReviewVersion::verify_snapshot( 'client-supplied-version', 'link-hash-a' ) );
	}

	public function test_custom_field_and_featured_image_changes_make_approval_stale(): void {
		$post          = new \WP_Post( [ 'ID' => 42, 'post_content' => 'Draft' ] );
		$reviewed_hash = ReviewVersion::fingerprint( $post );

		$this->metadata['_product_summary'] = [ 'Original' ];
		$meta_hash                          = ReviewVersion::fingerprint( $post );
		self::assertSame( 'stale', ReviewVersion::state( [ 'response_type' => 'approve', 'content_hash' => $reviewed_hash ], $meta_hash ) );

		$this->metadata['_thumbnail_id'] = [ '100' ];
		self::assertNotSame( $meta_hash, ReviewVersion::fingerprint( $post ) );
	}

	public function test_taxonomy_changes_make_approval_stale_but_link_operational_meta_does_not(): void {
		$post          = new \WP_Post( [ 'ID' => 42, 'post_content' => 'Draft' ] );
		$reviewed_hash = ReviewVersion::fingerprint( $post );

		$this->metadata['_previewshare_enabled'] = [ '1' ];
		self::assertSame( $reviewed_hash, ReviewVersion::fingerprint( $post ) );

		$this->terms[] = (object) [
			'term_id'     => 7,
			'taxonomy'    => 'category',
			'slug'        => 'launch',
			'name'        => 'Launch',
			'description' => '',
			'parent'      => 0,
		];
		self::assertSame( 'stale', ReviewVersion::state( [ 'response_type' => 'approve', 'content_hash' => $reviewed_hash ], ReviewVersion::fingerprint( $post ) ) );
	}
}
