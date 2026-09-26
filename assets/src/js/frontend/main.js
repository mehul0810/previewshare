/**
 * Public reviewer response form for opted-in PreviewShare links.
 */

const form = document.getElementById( 'previewshare-review-form' );
const config = window.previewshareReview;

if ( form && config ) {
	const comment = form.querySelector( '[name="comment"]' );
	const message = document.getElementById( 'previewshare-review-message' );
	const button = form.querySelector( '[type="submit"]' );
	let requestId = null;

	const newRequestId = () => {
		if ( ! window.crypto || ! window.crypto.getRandomValues ) {
			return null;
		}
		const bytes = window.crypto.getRandomValues( new Uint8Array( 16 ) );
		return Array.from( bytes, ( byte ) =>
			byte.toString( 16 ).padStart( 2, '0' )
		).join( '' );
	};

	const updateCommentRequirement = () => {
		const type = form.querySelector( '[name="response_type"]:checked' );
		comment.required = type && type.value !== 'approve';
	};

	form.addEventListener( 'change', updateCommentRequirement );
	updateCommentRequirement();

	form.addEventListener( 'submit', async ( event ) => {
		event.preventDefault();
		requestId = requestId || newRequestId();
		if ( ! requestId ) {
			message.textContent = config.messages.unavailable;
			return;
		}

		const data = new window.FormData( form );
		button.disabled = true;
		message.textContent = config.messages.sending;

		try {
			const response = await window.fetch( config.endpoint, {
				method: 'POST',
				credentials: 'same-origin',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': config.nonce,
				},
				body: JSON.stringify( {
					token: config.token,
					response_type: data.get( 'response_type' ),
					request_id: requestId,
					content_snapshot: data.get( 'content_snapshot' ),
					name: data.get( 'name' ),
					email: data.get( 'email' ),
					comment: data.get( 'comment' ),
				} ),
			} );
			const result = await response.json();
			if ( result.code === 'review_stale_version' ) {
				message.textContent = config.messages.stale;
				return;
			}
			if ( ! response.ok && result.code !== 'review_duplicate' ) {
				throw new Error( result.message || config.messages.failed );
			}

			message.textContent =
				result.code === 'review_duplicate'
					? config.messages.duplicate
					: config.messages.received;
			form.reset();
			updateCommentRequirement();
			requestId = null;
		} catch ( error ) {
			message.textContent = error.message || config.messages.failed;
		} finally {
			button.disabled = false;
		}
	} );
}
