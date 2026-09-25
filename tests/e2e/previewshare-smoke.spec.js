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
	'/previewshare/v1/tokens/extend',
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

function isExtendPreviewResponse( response ) {
	return (
		response.request().method() === 'POST' &&
		decodeURIComponent( response.url() ).includes(
			'/previewshare/v1/tokens/extend'
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
	const tablist = page.getByRole( 'tablist', {
		name: 'PreviewShare settings',
	} );
	for ( const tabName of [
		'Overview',
		'Preview links',
		'Content types',
		'Changelog',
		'More plugins',
	] ) {
		await expect(
			tablist.getByRole( 'tab', { name: tabName, exact: true } )
		).toBeVisible();
	}
	await expect(
		page.getByText( 'Active links', { exact: true } )
	).toBeVisible();
	await expect(
		page.getByText( 'Default expiry', { exact: true } )
	).toBeVisible();
	await expect(
		page.getByText( 'Expiring soon', { exact: true } )
	).toHaveCount( 0 );
	await page.screenshot( {
		path: testInfo.outputPath( 'previewshare-settings.png' ),
		fullPage: true,
	} );

	await tablist.getByRole( 'tab', { name: 'Changelog' } ).click();
	await expect(
		page.getByRole( 'heading', { name: 'v1.1.0' } )
	).toBeVisible();
	await page.screenshot( {
		path: testInfo.outputPath( 'previewshare-changelog.png' ),
		fullPage: true,
	} );
	await tablist.getByRole( 'tab', { name: 'Content types' } ).click();
	await expect(
		page.getByRole( 'heading', { name: 'Content types' } )
	).toBeVisible();
	await page.screenshot( {
		path: testInfo.outputPath( 'previewshare-content-types.png' ),
		fullPage: true,
	} );
	await tablist.getByRole( 'tab', { name: 'More plugins' } ).click();
	const pluginCards = page.locator( 'article.previewshare-plugin-card' );
	await expect( pluginCards ).toHaveCount( 9 );
	await expect(
		page.getByRole( 'heading', { name: 'OneCaptcha' } )
	).toBeVisible();
	await page.screenshot( {
		path: testInfo.outputPath( 'previewshare-more-plugins.png' ),
		fullPage: true,
	} );
	await page.setViewportSize( { width: 390, height: 844 } );
	for ( const [ tabName, screenshotName ] of [
		[ 'Overview', 'overview' ],
		[ 'Preview links', 'preview-links-empty' ],
		[ 'Content types', 'content-types' ],
		[ 'Changelog', 'changelog' ],
		[ 'More plugins', 'more-plugins' ],
	] ) {
		const mobileTab = tablist.getByRole( 'tab', {
			name: tabName,
			exact: true,
		} );
		await mobileTab.click();
		await expect( mobileTab ).toHaveAttribute( 'aria-selected', 'true' );
		await expect( page.getByRole( 'tabpanel' ) ).toBeVisible();
		const mobilePageWidth = await page.evaluate(
			() => document.documentElement.scrollWidth
		);
		expect( mobilePageWidth ).toBeLessThanOrEqual( 390 );
		if ( tabName === 'More plugins' ) {
			const firstPluginCard = await pluginCards.first().boundingBox();
			expect( firstPluginCard ).not.toBeNull();
			expect(
				firstPluginCard.x + firstPluginCard.width
			).toBeLessThanOrEqual( 390 );
		}
		await page.screenshot( {
			path: testInfo.outputPath(
				`previewshare-${ screenshotName }-mobile.png`
			),
			fullPage: true,
		} );
	}
	await page.setViewportSize( { width: 1280, height: 900 } );
	await tablist.getByRole( 'tab', { name: 'Overview' } ).click();

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

	const inventoryResponse = page.waitForResponse(
		( responseCandidate ) =>
			responseCandidate.request().method() === 'GET' &&
			responseMatchesRoute( responseCandidate, '/previewshare/v1/tokens' )
	);
	await admin.visitAdminPage(
		'options-general.php',
		'page=previewshare_settings'
	);
	await expectSuccessfulResponse(
		inventoryResponse,
		'PreviewShare token inventory request'
	);
	const inventory = await ( await inventoryResponse ).json();
	const generatedLink = inventory.items.find(
		( item ) => item.label === 'E2E smoke'
	);
	expect( generatedLink ).toBeDefined();
	expect( generatedLink.status ).toBe( 'active' );
	const previousExpiry = generatedLink.expires_at;
	await page.getByRole( 'tab', { name: 'Preview links' } ).click();
	const inventoryTable = page.locator( '.previewshare-dataviews' );
	await expect( inventoryTable ).toBeVisible();
	const desktopPageWidth = await page.evaluate(
		() => document.documentElement.scrollWidth
	);
	expect( desktopPageWidth ).toBeLessThanOrEqual( 1280 );
	await expect(
		page.getByRole( 'button', { name: 'Extend', exact: true } )
	).toBeVisible();
	await page.screenshot( {
		path: testInfo.outputPath( 'previewshare-preview-links-expiring.png' ),
		fullPage: true,
	} );
	await page.setViewportSize( { width: 390, height: 844 } );
	const mobilePageWidth = await page.evaluate(
		() => document.documentElement.scrollWidth
	);
	expect( mobilePageWidth ).toBeLessThanOrEqual( 390 );
	const inventoryViewport = inventoryTable.locator(
		'.dataviews-layout__container'
	);
	const inventoryScrollMetrics = await inventoryViewport.evaluate(
		( element ) => ( {
			clientWidth: element.clientWidth,
			scrollWidth: element.scrollWidth,
		} )
	);
	expect( inventoryScrollMetrics.scrollWidth ).toBeGreaterThan(
		inventoryScrollMetrics.clientWidth
	);
	const extendButton = page.getByRole( 'button', {
		name: 'Extend',
		exact: true,
	} );
	const expiringStatus = page.locator(
		'.previewshare-status.is-expiring_soon'
	);
	const mobileScrollLeft = await expiringStatus.evaluate( ( status ) => {
		const viewport = status.closest( '.dataviews-layout__container' );
		const viewportBounds = viewport.getBoundingClientRect();
		const statusBounds = status.getBoundingClientRect();
		const button = viewport.querySelector(
			'.previewshare-link-status-cell button'
		);
		const actionsHeader = viewport.querySelector(
			'th.dataviews-view-table__actions-column'
		);
		const safeLeft = viewportBounds.left + 16;

		viewport.scrollLeft += statusBounds.left - safeLeft;
		const visibleStatusBounds = status.getBoundingClientRect();
		const visibleButtonBounds = button.getBoundingClientRect();
		const visibleViewportBounds = viewport.getBoundingClientRect();
		const actionsWidth = actionsHeader.getBoundingClientRect().width;

		return {
			scrollLeft: viewport.scrollLeft,
			statusLeft: visibleStatusBounds.left,
			statusRight: visibleStatusBounds.right,
			buttonLeft: visibleButtonBounds.left,
			buttonRight: visibleButtonBounds.right,
			visibleLeft: visibleViewportBounds.left,
			visibleRight: visibleViewportBounds.right - actionsWidth,
		};
	} );
	expect( mobileScrollLeft.scrollLeft ).toBeGreaterThan( 0 );
	expect( mobileScrollLeft.buttonLeft ).toBeGreaterThanOrEqual(
		mobileScrollLeft.visibleLeft
	);
	expect( mobileScrollLeft.statusLeft ).toBeGreaterThanOrEqual(
		mobileScrollLeft.visibleLeft
	);
	expect( mobileScrollLeft.statusRight ).toBeLessThanOrEqual(
		mobileScrollLeft.visibleRight
	);
	expect( mobileScrollLeft.buttonRight ).toBeLessThanOrEqual(
		mobileScrollLeft.visibleRight
	);
	await expect( expiringStatus ).toBeVisible();
	await expect( expiringStatus ).toHaveText( 'Expiring soon' );
	await expect( extendButton ).toBeInViewport();
	const expiringStatusIsPainted = await expiringStatus.evaluate( ( status ) => {
		const bounds = status.getBoundingClientRect();
		const visibleElement = document.elementFromPoint(
			bounds.left + bounds.width / 2,
			bounds.top + bounds.height / 2
		);

		return status === visibleElement || status.contains( visibleElement );
	} );
	expect( expiringStatusIsPainted ).toBe( true );
	const extendButtonIsPainted = await extendButton.evaluate( ( button ) => {
		const bounds = button.getBoundingClientRect();
		const visibleElement = document.elementFromPoint(
			bounds.left + bounds.width / 2,
			bounds.top + bounds.height / 2
		);

		return button === visibleElement || button.contains( visibleElement );
	} );
	expect( extendButtonIsPainted ).toBe( true );
	const mobileExtendBounds = await extendButton.boundingBox();
	expect( mobileExtendBounds ).not.toBeNull();
	expect(
		mobileExtendBounds.x + mobileExtendBounds.width
	).toBeLessThanOrEqual( 390 );
	await page.screenshot( {
		path: testInfo.outputPath( 'previewshare-preview-links-mobile.png' ),
		fullPage: true,
	} );
	await page.setViewportSize( { width: 1280, height: 900 } );
	const [ extendResponse ] = await Promise.all( [
		page.waitForResponse( isExtendPreviewResponse ),
		extendButton.click(),
	] );
	await expectSuccessfulResponse( extendResponse, 'Extend preview link' );
	const extendedLink = await extendResponse.json();
	expect( extendedLink.expires_at ).toBe( previousExpiry + 24 * 60 * 60 );
	await expect(
		page
			.getByRole( 'region', { name: 'PreviewShare notices' } )
			.getByText( 'Access extended by 24 hours.', { exact: false } )
	).toBeVisible();
	await expect(
		page.getByRole( 'button', { name: 'Extend', exact: true } )
	).toHaveCount( 0 );
	const samePreviewResponse = await anonymous.goto( previewUrl );
	expect( samePreviewResponse.status() ).toBe( 200 );
	await expect(
		anonymous.getByText( postContent, { exact: true } )
	).toBeVisible();

	await admin.editPost( post.id );

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
	await expect(
		page
			.locator( '.components-snackbar__content', {
				hasText: 'Preview links revoked.',
			} )
			.last()
	).toBeVisible();

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
