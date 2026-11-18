/**
 * Public reviewer response form for opted-in PreviewShare links.
 */

const form = document.getElementById( 'previewshare-review-form' );
const config = window.previewshareReview;

if ( form && config ) {
	const comment = form.querySelector( '[name="comment"]' );
	const message = document.getElementById( 'previewshare-review-message' );
	const button = form.querySelector( '[type="submit"]' );
	const sendLabel = button.textContent;
	let pending = null;
	let sending = false;

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
	const formValues = () => {
		const data = new window.FormData( form );
		return {
			response_type: data.get( 'response_type' ),
			name: data.get( 'name' ),
			email: data.get( 'email' ),
			comment: data.get( 'comment' ),
		};
	};
	const notePendingEdits = () => {
		if (
			pending &&
			! sending &&
			JSON.stringify( formValues() ) !== JSON.stringify( pending.values )
		) {
			message.textContent = config.messages.retryOriginal;
		}
	};

	form.addEventListener( 'change', updateCommentRequirement );
	form.addEventListener( 'change', notePendingEdits );
	form.addEventListener( 'input', notePendingEdits );
	updateCommentRequirement();

	form.addEventListener( 'submit', async ( event ) => {
		event.preventDefault();
		if ( sending ) {
			return;
		}
		const retry = pending !== null;
		if ( ! pending ) {
			const requestId = newRequestId();
			if ( requestId ) {
				const values = formValues();
				pending = {
					values,
					payload: {
						token: config.token,
						request_id: requestId,
						content_snapshot: new window.FormData( form ).get(
							'content_snapshot'
						),
						...values,
					},
				};
			}
		}
		if ( ! pending ) {
			message.textContent = config.messages.unavailable;
			return;
		}

		const submission = pending;
		sending = true;
		button.setAttribute( 'aria-disabled', 'true' );
		message.textContent =
			JSON.stringify( formValues() ) ===
			JSON.stringify( submission.values )
				? config.messages.sending
				: config.messages.retrying;

		try {
			const response = await window.fetch( config.endpoint, {
				method: 'POST',
				credentials: 'same-origin',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': config.nonce,
				},
				body: JSON.stringify( submission.payload ),
			} );
			const result = await response.json();
			const code = result?.code;
			if ( response.status === 409 && code === 'review_stale_version' ) {
				if ( ! retry ) {
					pending = null;
				}
				message.textContent = config.messages.stale;
				return;
			}
			const duplicate =
				response.status === 409 && code === 'review_duplicate';
			const received =
				response.status === 201 && result?.received === true;
			if ( ! received && ! duplicate ) {
				// Validation only settles the current attempt. A retry's rejection
				// does not say whether an earlier attempt committed.
				if (
					! retry &&
					response.status === 400 &&
					result?.data?.status === 400 &&
					typeof code === 'string'
				) {
					pending = null;
				}
				message.textContent =
					! response.ok &&
					typeof code === 'string' &&
					typeof result.message === 'string' &&
					result.message
						? result.message
						: config.messages.failed;
				if ( pending ) {
					message.textContent += ' ' + config.messages.retryOriginal;
				}
				return;
			}

			const edited =
				JSON.stringify( formValues() ) !==
				JSON.stringify( submission.values );
			if ( edited ) {
				message.textContent = config.messages.receivedEdits;
			} else {
				message.textContent = duplicate
					? config.messages.duplicate
					: config.messages.received;
			}
			if ( ! edited ) {
				form.reset();
				updateCommentRequirement();
			}
			pending = null;
		} catch {
			message.textContent =
				config.messages.failed + ' ' + config.messages.retryOriginal;
		} finally {
			sending = false;
			button.removeAttribute( 'aria-disabled' );
			// A retry sends the captured response. Current edits are validated
			// only when the reviewer submits them as a new response.
			button.formNoValidate = pending !== null;
			button.textContent = pending
				? config.messages.retryButton || sendLabel
				: sendLabel;
		}
	} );
}
