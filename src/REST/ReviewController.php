<?php
/**
 * Reviewer response REST endpoints.
 *
 * @package PreviewShare
 */

namespace PreviewShare\REST;

use PreviewShare\Services\PostMetaStorage;
use PreviewShare\Services\ReviewResponseService;
use PreviewShare\Services\ReviewVersion;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles anonymous submissions and permissioned editorial review controls.
 */
final class ReviewController {

	/**
	 * Preview-link storage.
	 *
	 * @var PostMetaStorage
	 */
	private $storage;

	/**
	 * Review-response service.
	 *
	 * @var ReviewResponseService
	 */
	private $reviews;

	/**
	 * Initialize the REST controller.
	 *
	 * @param PostMetaStorage       $storage Preview link storage.
	 * @param ReviewResponseService $reviews Review-response service.
	 */
	public function __construct( PostMetaStorage $storage, ReviewResponseService $reviews ) {
		$this->storage = $storage;
		$this->reviews = $reviews;
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	/**
	 * Register public and editor-only routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			'previewshare/v1',
			'/reviews/submit',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'submit' ],
				'permission_callback' => '__return_true',
				'args'                => [
					'token'         => [
						'required' => true,
						'type' => 'string',
					],
					'response_type' => [
						'required' => true,
						'type' => 'string',
					],
					'request_id'    => [
						'required' => true,
						'type' => 'string',
					],
					'content_snapshot' => [
						'required' => true,
						'type' => 'string',
					],
					'name'          => [
						'required' => false,
						'type' => 'string',
					],
					'email'         => [
						'required' => false,
						'type' => 'string',
					],
					'comment'       => [
						'required' => false,
						'type' => 'string',
					],
				],
			]
		);

		foreach ( [
			'policy' => 'POST',
			'history' => 'GET',
			'resolve' => 'POST',
		] as $action => $method ) {
			register_rest_route(
				'previewshare/v1',
				'/reviews/' . $action,
				[
					'methods'             => $method,
					'callback'            => [ $this, $action ],
					'permission_callback' => [ $this, 'can_edit_link' ],
					'args'                => [
						'post_id' => [
							'required' => true,
							'type' => 'integer',
						],
						'id'      => [
							'required' => true,
							'type' => 'string',
						],
					],
				]
			);
		}
	}

	/**
	 * Check the editor's post capability before link-specific callbacks run.
	 *
	 * @param \WP_REST_Request<array<string,mixed>> $request REST request.
	 * @return bool
	 */
	public function can_edit_link( $request ): bool {
		return current_user_can( 'edit_post', absint( $request->get_param( 'post_id' ) ) );
	}

	/**
	 * Accept a reviewer response using an active, opted-in preview link.
	 *
	 * @param \WP_REST_Request<array<string,mixed>> $request REST request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function submit( $request ) {
		$token = (string) $request->get_param( 'token' );
		if ( ! preg_match( '/^[a-f0-9]{48}$/', $token ) ) {
			return new \WP_Error( 'review_unavailable', __( 'This preview link is unavailable.', 'previewshare' ), [ 'status' => 404 ] );
		}

		$context = $this->storage->get_link_by_token( $token );
		if ( ! $context ) {
			$diagnostic = $this->storage->get_token_diagnostic( $token );
			if ( 'token_expired' === $diagnostic['reason_code'] ) {
				return new \WP_Error( 'review_expired', __( 'This preview link has expired.', 'previewshare' ), [ 'status' => 410 ] );
			}
			if ( 'token_revoked' === $diagnostic['reason_code'] ) {
				return new \WP_Error( 'review_revoked', __( 'This preview link has been revoked.', 'previewshare' ), [ 'status' => 410 ] );
			}
			return new \WP_Error( 'review_unavailable', __( 'This preview link is unavailable.', 'previewshare' ), [ 'status' => 404 ] );
		}

		$post_id = (int) $context['post_id'];
		$hash    = (string) $context['hash'];
		$link    = $context['link'];
		$post    = get_post( $post_id );
		if (
			! $post
			|| ! get_post_meta( $post_id, '_previewshare_enabled', true )
			|| ! \previewshare_is_supported_post_type( (string) $post->post_type )
			|| ! \previewshare_is_previewable_post_status( (string) $post->post_status )
			|| 'publish' === $post->post_status
		) {
			return new \WP_Error( 'review_unavailable', __( 'Review responses are unavailable for this preview.', 'previewshare' ), [ 'status' => 403 ] );
		}

		if ( empty( $link['responses_enabled'] ) ) {
			return new \WP_Error( 'review_disabled', __( 'Review responses are disabled for this link.', 'previewshare' ), [ 'status' => 403 ] );
		}

		$allowed = apply_filters( 'previewshare_review_submission_allowed', true, $context );
		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}
		if ( ! $allowed ) {
			return new \WP_Error( 'review_blocked', __( 'This response could not be accepted.', 'previewshare' ), [ 'status' => 403 ] );
		}

		if ( ! $this->within_rate_limit( $hash ) ) {
			return new \WP_Error( 'review_rate_limited', __( 'Too many responses. Please try again later.', 'previewshare' ), [ 'status' => 429 ] );
		}

		$type       = sanitize_key( (string) $request->get_param( 'response_type' ) );
		$name       = sanitize_text_field( (string) $request->get_param( 'name' ) );
		$raw_email  = trim( (string) $request->get_param( 'email' ) );
		$email      = sanitize_email( $raw_email );
		$comment    = sanitize_textarea_field( (string) $request->get_param( 'comment' ) );
		$request_id = (string) $request->get_param( 'request_id' );

		if ( ! in_array( $type, [ 'approve', 'request_changes', 'comment' ], true ) ) {
			return new \WP_Error( 'review_invalid_type', __( 'Choose a valid response.', 'previewshare' ), [ 'status' => 400 ] );
		}
		if ( ! preg_match( '/^[a-zA-Z0-9-]{16,80}$/', $request_id ) ) {
			return new \WP_Error( 'review_invalid_request', __( 'Please refresh the page and try again.', 'previewshare' ), [ 'status' => 400 ] );
		}
		$content_fingerprint = ReviewVersion::verify_snapshot( (string) $request->get_param( 'content_snapshot' ), $hash );
		if ( null === $content_fingerprint ) {
			return new \WP_Error( 'review_invalid_snapshot', __( 'Please refresh the page and try again.', 'previewshare' ), [ 'status' => 400 ] );
		}
		if ( 'approve' === $type && ! hash_equals( $content_fingerprint, ReviewVersion::fingerprint( $post ) ) ) {
			return new \WP_Error( 'review_stale_version', __( 'This content changed after you opened the preview. Refresh the page to review the latest version before approving.', 'previewshare' ), [ 'status' => 409 ] );
		}
		if ( strlen( $name ) > 120 || strlen( $email ) > 190 || strlen( $comment ) > 5000 ) {
			return new \WP_Error( 'review_too_long', __( 'Your response is too long. Shorten it and try again.', 'previewshare' ), [ 'status' => 400 ] );
		}
		if ( '' !== $raw_email && ( $email !== $raw_email || ! is_email( $email ) ) ) {
			return new \WP_Error( 'review_invalid_email', __( 'Enter a valid email address.', 'previewshare' ), [ 'status' => 400 ] );
		}
		if ( ! empty( $link['identity_required'] ) && ( '' === $name || '' === $email ) ) {
			return new \WP_Error( 'review_identity_required', __( 'Enter your name and email to respond.', 'previewshare' ), [ 'status' => 400 ] );
		}
		if ( in_array( $type, [ 'request_changes', 'comment' ], true ) && '' === trim( $comment ) ) {
			return new \WP_Error( 'review_comment_required', __( 'Add a comment for this response.', 'previewshare' ), [ 'status' => 400 ] );
		}

		$submission_key = hash_hmac( 'sha256', $hash . ':' . $request_id, wp_salt( 'auth' ) );
		$result         = $this->reviews->create_response(
			$post_id,
			$hash,
			$content_fingerprint,
			$type,
			$name,
			$email,
			$comment,
			$submission_key
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new \WP_REST_Response(
			[
				'received' => true,
				'response_type' => $type,
			],
			201
		);
	}

	/**
	 * Change one link's reviewer-response policy.
	 *
	 * @param \WP_REST_Request<array<string,mixed>> $request REST request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function policy( $request ) {
		$context = $this->editor_link_context( $request );
		if ( is_wp_error( $context ) ) {
			return $context;
		}

		$enabled  = $request->get_param( 'responses_enabled' );
		$required = $request->get_param( 'identity_required' );
		if ( ! is_bool( $enabled ) || ! is_bool( $required ) ) {
			return new \WP_Error( 'review_invalid_policy', __( 'Choose valid review settings.', 'previewshare' ), [ 'status' => 400 ] );
		}
		$link = $context['link'];
		if ( $enabled && ( ! empty( $link['revoked'] ) || ( null !== $link['expires_at'] && (int) $link['expires_at'] <= time() ) ) ) {
			return new \WP_Error( 'review_inactive_link', __( 'Only active links can accept responses.', 'previewshare' ), [ 'status' => 409 ] );
		}
		if ( ! $this->storage->set_review_policy_by_id( $context['hash'], $enabled, $required ) ) {
			return new \WP_Error( 'review_policy_failed', __( 'Review settings could not be saved.', 'previewshare' ), [ 'status' => 500 ] );
		}

		return new \WP_REST_Response(
			[
				'responses_enabled' => $enabled,
				'identity_required' => $enabled && $required,
			],
			200
		);
	}

	/**
	 * Return response state and history to the post editor.
	 *
	 * @param \WP_REST_Request<array<string,mixed>> $request REST request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function history( $request ) {
		$context = $this->editor_link_context( $request );
		if ( is_wp_error( $context ) ) {
			return $context;
		}

		$post = get_post( $context['post_id'] );
		if ( ! $post ) {
			return new \WP_Error( 'review_unavailable', __( 'This post is unavailable.', 'previewshare' ), [ 'status' => 404 ] );
		}

		$page = max( 1, absint( $request->get_param( 'page' ) ) );
		return new \WP_REST_Response(
			[
				'state'   => $this->reviews->state( $post, $context['hash'] ),
				'history' => $this->reviews->history( $context['post_id'], $context['hash'], $page ),
			],
			200
		);
	}

	/**
	 * Resolve an outstanding change request.
	 *
	 * @param \WP_REST_Request<array<string,mixed>> $request REST request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function resolve( $request ) {
		$context = $this->editor_link_context( $request );
		if ( is_wp_error( $context ) ) {
			return $context;
		}

		$response_id = absint( $request->get_param( 'response_id' ) );
		if ( ! $response_id || ! $this->reviews->resolve_change_request( $context['post_id'], $context['hash'], $response_id ) ) {
			return new \WP_Error( 'review_not_resolvable', __( 'This change request could not be resolved.', 'previewshare' ), [ 'status' => 409 ] );
		}

		return new \WP_REST_Response( [ 'resolved' => true ], 200 );
	}

	/**
	 * Resolve a link only after an editor's post capability check.
	 *
	 * @param \WP_REST_Request<array<string,mixed>> $request REST request.
	 * @return array{post_id:int,hash:string,link:array<string,mixed>}|\WP_Error
	 */
	private function editor_link_context( $request ) {
		$post_id = absint( $request->get_param( 'post_id' ) );
		$context = $this->storage->get_link_context_by_id( (string) $request->get_param( 'id' ) );
		if ( ! $context || $post_id !== $context['post_id'] || ! current_user_can( 'edit_post', $post_id ) ) {
			return new \WP_Error( 'review_not_found', __( 'This preview link was not found.', 'previewshare' ), [ 'status' => 404 ] );
		}
		return $context;
	}

	/**
	 * Apply per-link and per-IP limits without storing a raw IP address.
	 *
	 * @param string $link_hash Link identifier.
	 * @return bool
	 */
	private function within_rate_limit( string $link_hash ): bool {
		$remote = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REMOTE_ADDR'] ) ) : '';
		$key    = 'previewshare_review_' . substr( hash_hmac( 'sha256', $link_hash . ':' . $remote, wp_salt( 'auth' ) ), 0, 32 );
		$count  = (int) get_transient( $key );
		if ( $count >= 10 || $this->reviews->count_recent( $link_hash, time() - ( 10 * MINUTE_IN_SECONDS ) ) >= 100 ) {
			return false;
		}

		set_transient( $key, $count + 1, 10 * MINUTE_IN_SECONDS );
		return true;
	}
}
