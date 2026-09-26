<?php
/**
 * Anonymous review form on opted-in preview links.
 *
 * @package PreviewShare
 */

namespace PreviewShare\Includes;

use PreviewShare\Services\PostMetaStorage;
use PreviewShare\Services\ReviewVersion;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders only for an active preview whose link allows responses.
 */
final class ReviewForm {

	/**
	 * Preview-link storage.
	 *
	 * @var PostMetaStorage
	 */
	private $storage;

	/**
	 * Initialize the review form hooks.
	 *
	 * @param PostMetaStorage $storage Preview-link storage.
	 */
	public function __construct( PostMetaStorage $storage ) {
		$this->storage = $storage;
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_assets' ] );
		// Render before WordPress prints footer scripts (priority 20), so the
		// frontend bundle can bind to the form as soon as it executes.
		add_action( 'wp_footer', [ $this, 'render' ], 10 );
	}

	/**
	 * Load the small frontend bundle only on an opted-in preview.
	 *
	 * @return void
	 */
	public function enqueue_assets(): void {
		$context = $this->current_link();
		if ( ! $context ) {
			return;
		}

		$asset = \previewshare_get_asset_metadata( 'assets/dist/js/previewshare.min.asset.php', [] );
		wp_enqueue_style( 'previewshare-review', PREVIEWSHARE_PLUGIN_URL . 'assets/dist/previewshare.css', [], $asset['version'] );
		wp_enqueue_script( 'previewshare-review', PREVIEWSHARE_PLUGIN_URL . 'assets/dist/js/previewshare.min.js', $asset['dependencies'], $asset['version'], true );
		wp_localize_script(
			'previewshare-review',
			'previewshareReview',
			[
				'endpoint' => rest_url( 'previewshare/v1/reviews/submit' ),
				'token'    => $context['token'],
				'nonce'    => wp_create_nonce( 'wp_rest' ),
				'messages' => [
					'unavailable' => __( 'This browser cannot send a response securely.', 'previewshare' ),
					'sending'     => __( 'Sending your response…', 'previewshare' ),
					'failed'      => __( 'Your response could not be sent. Please try again.', 'previewshare' ),
					'stale'       => __( 'This content changed after you opened the preview. Refresh the page to review the latest version before approving.', 'previewshare' ),
					'duplicate'   => __( 'Your response was already received.', 'previewshare' ),
					'received'    => __( 'Your response was received.', 'previewshare' ),
				],
			]
		);
	}

	/**
	 * Render a page-level, keyboard-accessible reviewer response form.
	 *
	 * @return void
	 */
	public function render(): void {
		$context = $this->current_link();
		if ( ! $context ) {
			return;
		}

		$required = ! empty( $context['link']['identity_required'] );
		?>
		<section id="previewshare-review" class="previewshare-review" aria-labelledby="previewshare-review-title">
			<div class="previewshare-review__inner">
				<p class="previewshare-review__eyebrow"><?php esc_html_e( 'PREVIEWSHARE REVIEW', 'previewshare' ); ?></p>
				<h2 id="previewshare-review-title"><?php esc_html_e( 'Share your feedback', 'previewshare' ); ?></h2>
				<p><?php esc_html_e( 'Your response is shared with the editor of this draft. It does not publish the content.', 'previewshare' ); ?></p>
				<form id="previewshare-review-form" method="post" action="<?php echo esc_url( rest_url( 'previewshare/v1/reviews/submit' ) ); ?>">
					<input type="hidden" name="token" value="<?php echo esc_attr( $context['token'] ); ?>">
					<input type="hidden" name="request_id" value="<?php echo esc_attr( $context['request_id'] ); ?>">
					<input type="hidden" name="content_snapshot" value="<?php echo esc_attr( $context['content_snapshot'] ); ?>">
					<fieldset>
						<legend><?php esc_html_e( 'Your response', 'previewshare' ); ?></legend>
						<label><input type="radio" name="response_type" value="approve" checked> <?php esc_html_e( 'Approve', 'previewshare' ); ?></label>
						<label><input type="radio" name="response_type" value="request_changes"> <?php esc_html_e( 'Request changes', 'previewshare' ); ?></label>
						<label><input type="radio" name="response_type" value="comment"> <?php esc_html_e( 'Comment', 'previewshare' ); ?></label>
					</fieldset>
					<label for="previewshare-review-comment"><?php esc_html_e( 'Comment', 'previewshare' ); ?></label>
					<textarea id="previewshare-review-comment" name="comment" maxlength="5000" rows="4" aria-describedby="previewshare-review-comment-help"></textarea>
					<p id="previewshare-review-comment-help" class="previewshare-review__help"><?php esc_html_e( 'Required when requesting changes or leaving a comment.', 'previewshare' ); ?></p>
					<div class="previewshare-review__identity">
						<div>
							<label for="previewshare-review-name"><?php esc_html_e( 'Name', 'previewshare' ); ?><?php echo $required ? ' *' : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Fixed marker. ?></label>
							<input id="previewshare-review-name" name="name" type="text" maxlength="120" autocomplete="name" <?php echo $required ? 'required' : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Fixed attribute. ?>>
						</div>
						<div>
							<label for="previewshare-review-email"><?php esc_html_e( 'Email', 'previewshare' ); ?><?php echo $required ? ' *' : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Fixed marker. ?></label>
							<input id="previewshare-review-email" name="email" type="email" maxlength="190" autocomplete="email" <?php echo $required ? 'required' : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Fixed attribute. ?>>
						</div>
					</div>
					<p class="previewshare-review__help"><?php esc_html_e( 'Responses and any identity you provide are removed after 90 days.', 'previewshare' ); ?></p>
					<button type="submit"><?php esc_html_e( 'Send response', 'previewshare' ); ?></button>
					<p id="previewshare-review-message" role="status" aria-live="polite"></p>
				</form>
			</div>
		</section>
		<?php
	}

	/**
	 * Resolve the current opted-in preview without exposing response data.
	 *
	 * @return array{token:string,link:array<string,mixed>,content_snapshot:string,request_id:string}|null
	 */
	private function current_link(): ?array {
		$token = (string) get_query_var( 'previewshare_token' );
		if ( '' === $token ) {
			return null;
		}

		$context = $this->storage->get_link_by_token( $token );
		if ( ! $context || empty( $context['link']['responses_enabled'] ) ) {
			return null;
		}

		$post_id = (int) $context['post_id'];
		$post    = get_post( $post_id );
		if (
			! $post
			|| ! get_post_meta( $post_id, '_previewshare_enabled', true )
			|| ! \previewshare_is_supported_post_type( (string) $post->post_type )
			|| ! \previewshare_is_previewable_post_status( (string) $post->post_status )
			|| 'publish' === $post->post_status
		) {
			return null;
		}

		return [
			'token'            => $token,
			'link'             => $context['link'],
			'content_snapshot' => ReviewVersion::issue_snapshot( $post, $context['hash'] ),
			'request_id'       => wp_generate_uuid4(),
		];
	}
}
