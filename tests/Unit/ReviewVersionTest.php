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
	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
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
}
