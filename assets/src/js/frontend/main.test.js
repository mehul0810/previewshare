/**
 * Reviewer form retries must never reuse an ID with edited content.
 */

const messages = {
	unavailable: 'Unavailable',
	sending: 'Sending',
	retrying: 'Checking earlier response',
	retryOriginal: 'Retry earlier response first',
	failed: 'Failed',
	stale: 'Stale',
	duplicate: 'Already received',
	received: 'Received',
	receivedEdits: 'Earlier response received; edits unsent',
};

const response = ( status, result ) => ( {
	ok: status >= 200 && status < 300,
	status,
	json: async () => result,
} );

const flush = async () => {
	await Promise.resolve();
	await Promise.resolve();
	await Promise.resolve();
};

beforeEach( () => {
	document.body.innerHTML = `
		<form id="previewshare-review-form">
			<input name="content_snapshot" value="snapshot">
			<input name="response_type" type="radio" value="approve" checked>
			<input name="response_type" type="radio" value="comment">
			<input name="name" value="Reviewer">
			<input name="email" value="reviewer@example.test">
			<textarea name="comment"></textarea>
			<button type="submit">Send</button>
		</form>
		<p id="previewshare-review-message"></p>`;
	window.previewshareReview = {
		endpoint: '/reviews/submit',
		token: 'token',
		nonce: 'nonce',
		messages,
	};
	window.fetch = jest.fn();
	jest.resetModules();
	require( './main' );
} );

const submit = () =>
	document
		.getElementById( 'previewshare-review-form' )
		.dispatchEvent(
			new Event( 'submit', { bubbles: true, cancelable: true } )
		);

const sent = ( index ) =>
	JSON.parse( window.fetch.mock.calls[ index ][ 1 ].body );

test( 'an uncertain commit retries the exact payload, then sends later edits with a new ID', async () => {
	const comment = document.querySelector( '[name="comment"]' );
	const types = document.querySelectorAll( '[name="response_type"]' );
	comment.value = 'Original';
	types[ 1 ].checked = true;
	window.fetch.mockRejectedValueOnce( new Error( 'Connection lost' ) );
	submit();
	await flush();
	const first = sent( 0 );

	comment.value = 'Edited after uncertain commit';
	comment.dispatchEvent( new Event( 'input', { bubbles: true } ) );
	expect(
		document.getElementById( 'previewshare-review-message' ).textContent
	).toBe( messages.retryOriginal );
	types[ 0 ].checked = true;
	window.fetch.mockResolvedValueOnce(
		response( 409, { code: 'review_duplicate' } )
	);
	submit();
	await flush();
	expect( sent( 1 ) ).toEqual( first );
	expect( comment.value ).toBe( 'Edited after uncertain commit' );
	expect( types[ 0 ].checked ).toBe( true );
	expect(
		document.getElementById( 'previewshare-review-message' ).textContent
	).toBe( messages.receivedEdits );

	window.fetch.mockResolvedValueOnce( response( 201, { received: true } ) );
	submit();
	await flush();
	expect( sent( 2 ).comment ).toBe( 'Edited after uncertain commit' );
	expect( sent( 2 ).response_type ).toBe( 'approve' );
	expect( sent( 2 ).request_id ).not.toBe( first.request_id );
} );

test( 'a successful in-flight response preserves edits made while waiting', async () => {
	let finish;
	window.fetch.mockImplementationOnce(
		() =>
			new Promise( ( resolve ) => {
				finish = resolve;
			} )
	);
	const comment = document.querySelector( '[name="comment"]' );
	comment.value = 'Sent';
	submit();
	comment.value = 'Still writing';
	finish( response( 201, { received: true } ) );
	await flush();
	expect( comment.value ).toBe( 'Still writing' );
	expect(
		document.getElementById( 'previewshare-review-message' ).textContent
	).toBe( messages.receivedEdits );
} );

test( 'definite validation errors keep input and permit a corrected new request', async () => {
	const name = document.querySelector( '[name="name"]' );
	name.value = 'Invalid';
	window.fetch.mockResolvedValueOnce(
		response( 400, {
			code: 'review_invalid_name',
			message: 'Enter a valid name.',
		} )
	);
	submit();
	await flush();
	const first = sent( 0 );
	expect( name.value ).toBe( 'Invalid' );
	name.value = 'Corrected';
	window.fetch.mockResolvedValueOnce( response( 201, { received: true } ) );
	submit();
	await flush();
	expect( sent( 1 ).name ).toBe( 'Corrected' );
	expect( sent( 1 ).request_id ).not.toBe( first.request_id );
} );

test( 'an unchanged successful response clears the form and starts a new request', async () => {
	const comment = document.querySelector( '[name="comment"]' );
	comment.value = 'First response';
	window.fetch.mockResolvedValueOnce( response( 201, { received: true } ) );
	submit();
	await flush();
	const first = sent( 0 );
	expect( first.comment ).toBe( 'First response' );
	expect( comment.value ).toBe( '' );
	expect(
		document.getElementById( 'previewshare-review-message' ).textContent
	).toBe( messages.received );

	comment.value = 'Second response';
	window.fetch.mockResolvedValueOnce( response( 201, { received: true } ) );
	submit();
	await flush();
	expect( sent( 1 ).comment ).toBe( 'Second response' );
	expect( sent( 1 ).request_id ).not.toBe( first.request_id );
} );
