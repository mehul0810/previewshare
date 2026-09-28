<?php
/**
 * Shared preview comment controls and reviewer form placement.
 *
 * @package PreviewShare
 */

namespace PreviewShare\Tests\Unit;

use Brain\Monkey\Functions;
use Mockery;
use PreviewShare\Includes\ReviewForm;
use PreviewShare\Services\PostMetaStorage;
use WP_Post;

class ReviewFormTest extends TestCase {

	/** @var string */
	private $token = 'review-token';

	/** @var bool */
	private $responses_enabled = true;

	/** @var bool */
	private $sharing_enabled = true;

	/** @var int */
	private $queried_post_id = 42;

	/** @var WP_Post */
	private $post;

	/** @var ReviewForm */
	private $form;

	protected function setUp(): void {
		parent::setUp();
		$this->post = new WP_Post( [ 'ID' => 42, 'post_type' => 'post', 'post_status' => 'draft', 'post_content' => 'Shared draft' ] );

		$storage = Mockery::mock( PostMetaStorage::class );
		$storage->shouldReceive( 'get_link_by_token' )->andReturnUsing(
			function ( string $token ) {
				if ( 'review-token' !== $token ) {
					return null;
				}

				return [
					'post_id' => 42,
					'hash'    => 'link-hash',
					'link'    => [ 'responses_enabled' => $this->responses_enabled, 'identity_required' => false ],
				];
			}
		);
		Functions\when( 'add_action' )->justReturn( null );
		Functions\when( 'add_filter' )->justReturn( true );
		Functions\when( 'get_query_var' )->alias( function () { return $this->token; } );
		Functions\when( 'get_queried_object_id' )->alias( function () { return $this->queried_post_id; } );
		Functions\when( 'get_the_ID' )->justReturn( 42 );
		Functions\when( 'get_post' )->alias( function () { return $this->post; } );
		Functions\when( 'get_post_meta' )->alias(
			function ( int $post_id, ?string $key = null ) {
				return '_previewshare_enabled' === $key ? $this->sharing_enabled : [];
			}
		);
		Functions\when( 'get_post_types' )->justReturn( [ 'post' => (object) [ 'label' => 'Posts', 'labels' => (object) [ 'singular_name' => 'Post' ] ] ] );
		Functions\when( 'is_post_type_viewable' )->justReturn( true );
		Functions\when( 'get_option' )->alias(
			static function ( string $name, $default = false ) {
				return 'previewshare_post_types' === $name ? [ 'post' ] : $default;
			}
		);
		Functions\when( 'is_singular' )->justReturn( true );
		Functions\when( 'is_main_query' )->justReturn( true );
		Functions\when( 'in_the_loop' )->justReturn( true );
		Functions\when( 'get_object_taxonomies' )->justReturn( [] );
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'wp_salt' )->justReturn( 'test-secret' );
		Functions\when( 'wp_generate_uuid4' )->justReturn( 'request-uuid' );
		Functions\when( 'rest_url' )->justReturn( 'https://example.test/wp-json/previewshare/v1/reviews/submit' );
		Functions\when( 'esc_url' )->returnArg( 1 );
		Functions\when( 'esc_attr' )->returnArg( 1 );
		Functions\when( 'esc_html_e' )->alias( static function ( string $text ): void { echo $text; } );

		$this->form = new ReviewForm( $storage );
	}

	public function test_opted_in_preview_has_one_review_form_after_content_and_no_native_comments(): void {
		$this->assertFalse( $this->form->close_native_comments( true, 42 ) );
		$this->assertTrue( $this->form->close_native_comments( true, 99 ) );
		$this->assertSame( realpath( __DIR__ . '/../../src/Includes/preview-comments.php' ), $this->form->hide_native_comments_template( 'theme-comments.php' ) );

		$content = $this->form->append_to_content( '<p>Shared draft</p>' );
		$this->assertStringStartsWith( '<p>Shared draft</p>', $content );
		$this->assertSame( 1, substr_count( $content, 'id="previewshare-review-form"' ) );
		$this->assertSame( $content, $this->form->append_to_content( $content ) );
		ob_start();
		$this->form->render();
		$this->assertSame( '', ob_get_clean() );
	}

	public function test_response_opt_out_still_hides_native_comments_without_a_review_form(): void {
		$this->responses_enabled = false;
		$this->assertFalse( $this->form->close_native_comments( true, 42 ) );
		$this->assertSame( realpath( __DIR__ . '/../../src/Includes/preview-comments.php' ), $this->form->hide_native_comments_template( 'theme-comments.php' ) );
		$this->assertSame( '<p>Draft</p>', $this->form->append_to_content( '<p>Draft</p>' ) );
		ob_start();
		$this->form->render();
		$this->assertSame( '', ob_get_clean() );
	}

	public function test_normal_and_mismatched_requests_keep_their_comment_controls(): void {
		$this->token = '';
		$this->assertTrue( $this->form->close_native_comments( true, 42 ) );
		$this->assertSame( 'theme-comments.php', $this->form->hide_native_comments_template( 'theme-comments.php' ) );

		$this->token           = 'review-token';
		$this->sharing_enabled = false;
		$this->assertTrue( $this->form->close_native_comments( true, 42 ) );

		$this->sharing_enabled = true;
		$this->queried_post_id = 99;
		$this->assertTrue( $this->form->close_native_comments( true, 42 ) );

		$this->queried_post_id = 42;
		$this->post->post_status = 'publish';
		$this->assertTrue( $this->form->close_native_comments( true, 42 ) );
		$this->assertSame( '<p>Published</p>', $this->form->append_to_content( '<p>Published</p>' ) );

		$this->post->post_status = 'draft';
		$this->token             = 'invalid-token';
		$this->assertTrue( $this->form->close_native_comments( true, 42 ) );
	}

	public function test_shared_preview_suppresses_native_comment_blocks_for_its_post_only(): void {
		$instance = new \stdClass();
		$instance->context = [ 'postId' => 42 ];
		$comments_block = [ 'blockName' => 'core/comments' ];
		$template_block = [ 'blockName' => 'core/comment-template' ];

		$this->assertSame( '', $this->form->hide_native_comments_blocks( '<div>Comments</div>', $comments_block, $instance ) );
		$this->assertSame( '', $this->form->hide_native_comments_blocks( '<li>Comment</li>', $template_block, $instance ) );

		$instance->context = [ 'postId' => 99 ];
		$this->assertSame( '<div>Comments</div>', $this->form->hide_native_comments_blocks( '<div>Comments</div>', $comments_block, $instance ) );
		$this->assertSame( '<p>Other block</p>', $this->form->hide_native_comments_blocks( '<p>Other block</p>', [ 'blockName' => 'core/paragraph' ], $instance ) );
	}
}
