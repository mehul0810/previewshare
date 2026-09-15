const { execFileSync } = require( 'child_process' );
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );
const {
	FIXTURE_WP_CLI,
	assertFixtureConfiguration,
} = require( '../../scripts/e2e-fixture' );

const postTitle = `PreviewShare e2e draft ${ Date.now() }`;
const postContent = 'PreviewShare e2e draft content must stay unpublished.';
const publishedPostContent = 'Published content remains publicly available.';
const unavailablePreviewMessage = 'This preview link can no longer be opened.';
const previewShareRoutes = [
	'/previewshare/v1/v2/generate',
	'/previewshare/v1/settings',
	'/previewshare/v1/post-meta',
];

async function expectSuccessfulResponse( responsePromise, name ) {
	const response = await responsePromise;

	expect(
		response.status(),
		`${ name } returned ${ response.status() } at ${ response.url() }`
	).toBe( 200 );
}

function responseMatchesRoute( response, route ) {
	return decodeURIComponent( response.url() ).includes( route );
}

function resolvePreviewUrlForTestServer( previewUrl, baseURL ) {
	const url = new URL( previewUrl );
	const testBase = new URL( baseURL );

	url.protocol = testBase.protocol;
	url.host = testBase.host;

	return url.toString();
}

function getPreviewToken( url ) {
	return (
		url.searchParams.get( 'previewshare_token' ) ||
		url.pathname.split( '/' ).filter( Boolean ).pop()
	);
}

async function expectPreviewUrlVisible( page, url ) {
	await expect(
		page.getByRole( 'textbox', { name: 'Preview URL' } )
	).toHaveValue( url );
	await expect(
		page
			.locator( '.components-snackbar__content', {
				hasText: 'Preview link generated.',
			} )
			.last()
	).toBeVisible();
}

function runWpCli( command, args ) {
	const [ executable, ...commandArgs ] = command
		.trim()
		.split( /\s+/ )
		.filter( Boolean );

	execFileSync( executable, [ ...commandArgs, ...args ], {
		stdio: 'inherit',
	} );
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

function isGeneratePreviewResponse( response ) {
	const responseUrl = decodeURIComponent( response.url() );

	return (
		response.request().method() === 'POST' &&
		responseUrl.includes( '/previewshare/v1/v2/generate' )
	);
}

function isRevokePreviewResponse( response ) {
	return (
		response.request().method() === 'POST' &&
		decodeURIComponent( response.url() ).includes(
			'/previewshare/v1/v2/revoke'
		)
	);
}

async function expirePreviewLinkIfConfigured( { postId, previewUrl } ) {
	const token = getPreviewToken( new URL( previewUrl ) );

	if ( ! token ) {
		throw new Error(
			`Could not resolve preview token from ${ previewUrl }`
		);
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

	runWpCli( FIXTURE_WP_CLI, [ 'eval', php ] );
}

function configureFixturePrettyRoutes() {
	runWpCli( FIXTURE_WP_CLI, [
		'option',
		'update',
		'permalink_structure',
		'/%postname%/',
	] );
	runWpCli( FIXTURE_WP_CLI, [ 'rewrite', 'flush', '--hard' ] );
}

test.beforeEach( async ( { requestUtils } ) => {
	assertFixtureConfiguration();
	await requestUtils.activatePlugin( 'previewshare' );
	const restIndex = await requestUtils.rest( { path: '/' } );

	for ( const route of previewShareRoutes ) {
		expect( restIndex.routes ).toHaveProperty( route );
	}

	await requestUtils.updateSiteSettings( {
		permalink_structure: '/%postname%/',
	} );
	configureFixturePrettyRoutes();
} );

const createdPostIds = new Set();

test.afterEach( () => {
	for ( const postId of createdPostIds ) {
		runWpCli( FIXTURE_WP_CLI, [
			'post',
			'delete',
			String( postId ),
			'--force',
		] );
	}

	createdPostIds.clear();
} );

test( 'preview link admin, editor, public, invalid, expired, revoked, and post boundaries smoke test', async ( {
	page,
	admin,
	requestUtils,
	browser,
	baseURL,
}, testInfo ) => {
	const post = await requestUtils.createPost( {
		title: postTitle,
		content: postContent,
		status: 'draft',
		meta: {
			_previewshare_enabled: false,
			_previewshare_ttl_hours: 1,
		},
	} );
	createdPostIds.add( post.id );

	const settingsResponse = page.waitForResponse(
		( response ) =>
			response.request().method() === 'GET' &&
			responseMatchesRoute( response, '/previewshare/v1/settings' )
	);
	const tokensResponse = page.waitForResponse(
		( response ) =>
			response.request().method() === 'GET' &&
			responseMatchesRoute( response, '/previewshare/v1/tokens' )
	);

	await admin.visitAdminPage(
		'options-general.php',
		'page=previewshare_settings'
	);
	await expectSuccessfulResponse(
		settingsResponse,
		'PreviewShare settings request'
	);
	await expectSuccessfulResponse(
		tokensResponse,
		'PreviewShare tokens request'
	);
	await expect( page.locator( '#previewshare-settings-app' ) ).toBeVisible();
	await expect(
		page.getByText( 'Active links', { exact: true } )
	).toBeVisible();
	await expect(
		page.getByText( 'Default expiry', { exact: true } )
	).toBeVisible();
	await page.screenshot( {
		path: testInfo.outputPath( 'previewshare-settings.png' ),
		fullPage: true,
	} );

	const postMetaResponse = page.waitForResponse(
		( response ) =>
			response.request().method() === 'GET' &&
			responseMatchesRoute( response, '/previewshare/v1/post-meta' )
	);

	await admin.editPost( post.id );
	await expectSuccessfulResponse(
		postMetaResponse,
		'PreviewShare post-meta request'
	);
	await ensurePreviewSharePanelOpen( page );
	await expect(
		page.getByRole( 'checkbox', { name: 'Enable Public Preview' } )
	).toBeVisible();
	await page
		.getByRole( 'textbox', { name: 'Link label' } )
		.fill( 'E2E smoke' );

	const generateButton = page.getByRole( 'button', {
		name: 'Generate & copy',
	} );
	await expect( generateButton ).toBeEnabled();

	const [ response ] = await Promise.all( [
		page.waitForResponse( isGeneratePreviewResponse ),
		generateButton.click(),
	] );
	expect( response.status() ).toBe( 200 );
	const generated = await response.json();
	expect( generated.url ).toContain( '/preview/' );
	await expectPreviewUrlVisible( page, generated.url );
	const previewUrl = resolvePreviewUrlForTestServer( generated.url, baseURL );
	expect( new URL( previewUrl ).pathname ).toMatch(
		/^\/preview\/[a-zA-Z0-9]+\/?$/
	);

	const anonymousContext = await browser.newContext( {
		baseURL,
		storageState: {
			cookies: [],
			origins: [],
		},
	} );
	const anonymous = await anonymousContext.newPage();
	const directDraftResponse = await anonymous.goto( `/?p=${ post.id }` );
	expect( directDraftResponse.status() ).toBe( 404 );
	await expect(
		anonymous.getByText( postContent, { exact: true } )
	).toHaveCount( 0 );

	const validPreviewResponse = await anonymous.goto( previewUrl );
	expect( validPreviewResponse.status() ).toBe( 200 );
	await expect(
		anonymous.getByText( postContent, { exact: true } )
	).toBeVisible();

	const invalidPreviewResponse = await anonymous.goto(
		resolvePreviewUrlForTestServer(
			new URL( '/preview/invalidpreviewtokenabc123', baseURL ).toString(),
			baseURL
		)
	);
	expect( invalidPreviewResponse.status() ).toBe( 410 );
	await expect(
		anonymous.getByText( unavailablePreviewMessage )
	).toBeVisible();

	await expirePreviewLinkIfConfigured( {
		postId: post.id,
		previewUrl,
	} );
	const expiredPreviewResponse = await anonymous.goto( previewUrl );
	expect( expiredPreviewResponse.status() ).toBe( 410 );
	await expect(
		anonymous.getByText( unavailablePreviewMessage )
	).toBeVisible();

	const [ regeneratedResponse ] = await Promise.all( [
		page.waitForResponse( isGeneratePreviewResponse ),
		page.getByRole( 'button', { name: 'Generate & copy' } ).click(),
	] );
	expect( regeneratedResponse.status() ).toBe( 200 );
	const regenerated = await regeneratedResponse.json();
	expect( regenerated.url ).not.toBe( generated.url );
	await expectPreviewUrlVisible( page, regenerated.url );
	const regeneratedPreviewUrl = resolvePreviewUrlForTestServer(
		regenerated.url,
		baseURL
	);
	const regeneratedPreviewResponse = await anonymous.goto(
		regeneratedPreviewUrl
	);
	expect( regeneratedPreviewResponse.status() ).toBe( 200 );
	await expect(
		anonymous.getByText( postContent, { exact: true } )
	).toBeVisible();

	const enablePreviewToggle = page.getByRole( 'checkbox', {
		name: 'Enable Public Preview',
	} );
	await expect( enablePreviewToggle ).toBeChecked();
	const [ revokeResponse ] = await Promise.all( [
		page.waitForResponse( isRevokePreviewResponse ),
		enablePreviewToggle.click(),
	] );
	expect( revokeResponse.status() ).toBe( 200 );
	await expect( page.getByText( 'Preview links revoked.' ) ).toBeVisible();

	const revokedPreviewResponse = await anonymous.goto(
		regeneratedPreviewUrl
	);
	expect( revokedPreviewResponse.status() ).toBe( 410 );
	await expect(
		anonymous.getByText( unavailablePreviewMessage )
	).toBeVisible();

	const publishedPost = await requestUtils.createPost( {
		title: `PreviewShare e2e published ${ Date.now() }`,
		content: publishedPostContent,
		status: 'publish',
	} );
	createdPostIds.add( publishedPost.id );
	const publishedPostResponse = await anonymous.goto(
		`/?p=${ publishedPost.id }`
	);
	expect( publishedPostResponse.status() ).toBe( 200 );
	await expect(
		anonymous.getByText( publishedPostContent, { exact: true } )
	).toBeVisible();

	await anonymousContext.close();
} );
