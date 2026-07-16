const { execSync } = require( 'child_process' );
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );
const { request } = require( '@playwright/test' );

const postTitle = `PreviewShare e2e draft ${ Date.now() }`;
const postContent = 'PreviewShare e2e draft content must stay unpublished.';

async function expectPreviewUrlVisible( page, url ) {
	await expect( page.getByLabel( 'Preview URL' ) ).toHaveValue( url );
	await expect( page.getByText( 'Preview link generated.' ) ).toBeVisible();
}

async function ensurePreviewSharePanelOpen( page ) {
	const panelToggle = page.getByRole( 'button', {
		name: 'PreviewShare',
		exact: true,
	} );

	await expect( panelToggle ).toBeVisible();

	if ( ( await panelToggle.getAttribute( 'aria-expanded' ) ) === 'false' ) {
		await panelToggle.click();
	}
}

async function expirePreviewLinkIfConfigured( { postId, previewUrl } ) {
	const wpCli = process.env.PREVIEWSHARE_E2E_WP_CLI;

	if ( ! wpCli ) {
		return false;
	}

	const token = new URL( previewUrl ).pathname.split( '/' ).filter( Boolean ).pop();

	if ( ! token ) {
		throw new Error( `Could not resolve preview token from ${ previewUrl }` );
	}

	const php = `
$post_id = ${ Number( postId ) };
$token = '${ token.replace( /'/g, "\\'" ) }';
$hash = hash_hmac( 'sha256', $token, previewshare_get_token_hash_key() );
$links = get_post_meta( $post_id, '_previewshare_links', true );
if ( ! is_array( $links ) || empty( $links[ $hash ] ) ) {
	fwrite( STDERR, 'PreviewShare e2e token metadata was not found.' );
	exit( 1 );
}
$links[ $hash ]['expires_at'] = time() - MINUTE_IN_SECONDS;
update_post_meta( $post_id, '_previewshare_links', $links );
update_post_meta( $post_id, '_previewshare_token:' . $hash, $links[ $hash ] );
wp_cache_delete( $hash, 'previewshare_tokens' );
`;

	execSync( `${ wpCli } eval ${ JSON.stringify( php ) }`, {
		stdio: 'inherit',
	} );

	return true;
}

test.beforeEach( async ( { requestUtils } ) => {
	await requestUtils.activatePlugin( 'previewshare' );
	await requestUtils.updateSiteSettings( {
		permalink_structure: '/%postname%/',
	} );
	await requestUtils.deleteAllPosts();
	await requestUtils.rest( {
		method: 'POST',
		path: '/previewshare/v1/settings',
		data: {
			reset_defaults: true,
		},
	} );
} );

test( 'preview link admin, editor, public, invalid, expired, and unpublished boundaries smoke test', async ( {
	page,
	admin,
	requestUtils,
	baseURL,
} ) => {
	const post = await requestUtils.createPost( {
		title: postTitle,
		content: postContent,
		status: 'draft',
		meta: {
			_previewshare_enabled: false,
			_previewshare_ttl_hours: 1,
		},
	} );

	await admin.visitAdminPage(
		'options-general.php',
		'page=previewshare_settings'
	);
	await expect( page.locator( '#previewshare-settings-app' ) ).toBeVisible();
	await expect( page.getByText( 'Active links' ) ).toBeVisible();
	await expect( page.getByText( 'Default expiry', { exact: true } ) ).toBeVisible();

	await admin.editPost( post.id );
	await ensurePreviewSharePanelOpen( page );
	await expect(
		page.getByRole( 'checkbox', { name: 'Enable Public Preview' } )
	).toBeVisible();
	await page.getByRole( 'textbox', { name: 'Link label' } ).fill( 'E2E smoke' );

	const generateResponse = page.waitForResponse(
		( response ) =>
			response.url().includes( '/previewshare/v1/v2/generate' ) &&
			response.request().method() === 'POST'
	);
	await page.getByRole( 'button', { name: 'Generate & copy' } ).click();
	const response = await generateResponse;
	expect( response.status() ).toBe( 200 );
	const generated = await response.json();
	expect( generated.url ).toContain( '/preview/' );
	await expectPreviewUrlVisible( page, generated.url );

	const anonymous = await request.newContext( { baseURL } );
	const directDraftResponse = await anonymous.get( `/?p=${ post.id }` );
	expect( directDraftResponse.status() ).not.toBe( 200 );
	expect( await directDraftResponse.text() ).not.toContain( postContent );

	const validPreviewResponse = await anonymous.get( generated.url );
	expect( validPreviewResponse.status() ).toBe( 200 );
	expect( await validPreviewResponse.text() ).toContain( postContent );

	const invalidPreviewResponse = await anonymous.get( '/preview/not-a-real-token' );
	expect( invalidPreviewResponse.status() ).toBe( 410 );
	expect( await invalidPreviewResponse.text() ).toContain(
		'Preview link is invalid or has expired.'
	);

	if (
		await expirePreviewLinkIfConfigured( {
			postId: post.id,
			previewUrl: generated.url,
		} )
	) {
		const expiredPreviewResponse = await anonymous.get( generated.url );
		expect( expiredPreviewResponse.status() ).toBe( 410 );
		expect( await expiredPreviewResponse.text() ).toContain(
			'Preview link is invalid or has expired.'
		);
	} else {
		test.info().annotations.push( {
			type: 'proof-gap',
			description:
				'Set PREVIEWSHARE_E2E_WP_CLI to enable the true expired-token assertion.',
		} );
	}

	await anonymous.dispose();
} );
