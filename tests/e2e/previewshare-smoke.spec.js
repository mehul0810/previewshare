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
const runBlockThemePreviewRegression = /#7\.1(?:\.|$)/.test(
	process.env.WP_ENV_CORE || ''
);
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

async function expectCellTextToFit( cells ) {
	const overflowingText = await cells.evaluateAll( ( elements ) => {
		return elements.flatMap( ( cell ) => {
			const bounds = cell.getBoundingClientRect();
			const walker = document.createTreeWalker(
				cell,
				NodeFilter.SHOW_TEXT
			);
			const failures = [];
			let node;
			while ( ( node = walker.nextNode() ) ) {
				if ( ! node.textContent.trim() ) {
					continue;
				}
				const parent = node.parentElement;
				if (
					parent.closest(
						'.screen-reader-text, .components-visually-hidden'
					)
				) {
					continue;
				}
				const range = document.createRange();
				range.selectNodeContents( node );
				for ( const rect of range.getClientRects() ) {
					if (
						rect.width > 1 &&
						rect.height > 1 &&
						( rect.left < bounds.left - 1 ||
							rect.right > bounds.right + 1 )
					) {
						failures.push( {
							cell: cell.textContent.trim(),
							text: node.textContent.trim(),
							cellLeft: bounds.left,
							cellRight: bounds.right,
							textLeft: rect.left,
							textRight: rect.right,
						} );
						break;
					}
				}
			}
			return failures;
		} );
	} );
	expect( overflowingText ).toEqual( [] );
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

function runWpCliForOutput( command, args ) {
	const [ executable, ...commandArgs ] = command
		.trim()
		.split( /\s+/ )
		.filter( Boolean );

	return execFileSync( executable, [ ...commandArgs, ...args ], {
		encoding: 'utf8',
	}).trim();
}

function cleanupReviewChildren( parentId ) {
	const php = `
$parent_id = ${ Number( parentId ) };
$args = array(
	'post_type' => 'previewshare_review',
	'post_status' => 'private',
	'post_parent' => $parent_id,
	'fields' => 'ids',
	'posts_per_page' => -1,
);
foreach ( get_posts( $args ) as $review_id ) {
	$option_name = (string) get_post_meta( $review_id, '_previewshare_submission_option', true );
	if ( ! wp_delete_post( (int) $review_id, true ) ) {
		fwrite( STDERR, 'Could not clean up fixture review ' . (int) $review_id . '.' );
		exit( 1 );
	}
	if ( 0 === strpos( $option_name, 'previewshare_review_request_' ) ) {
		delete_option( $option_name );
	}
}
if ( get_posts( $args ) ) {
	fwrite( STDERR, 'Fixture review children remain for parent ' . $parent_id . '.' );
	exit( 1 );
}
`;

	runWpCli( FIXTURE_WP_CLI, [ 'eval', php ] );
}

async function ensurePreviewSharePanelOpen( page ) {
	const welcomeGuide = page.getByText( 'Welcome to the block editor', {
		exact: true,
	} );
	if ( await welcomeGuide.isVisible() ) {
		await page.keyboard.press( 'Escape' );
		await expect( welcomeGuide ).not.toBeVisible();
	}

	const panelToggle = page
		.locator( 'button.components-panel__body-toggle' )
		.filter( { hasText: 'PreviewShare' } );

	await expect( panelToggle ).toBeVisible();

	if ( ( await panelToggle.getAttribute( 'aria-expanded' ) ) === 'false' ) {
		await panelToggle.click();
	}
}

async function visitEditor( admin, postId ) {
	if ( /WordPress#5\.8(?:\.|$)/.test( process.env.WP_ENV_CORE || '' ) ) {
		await admin.visitAdminPage(
			'post.php',
			`post=${ postId }&action=edit`
		);
		return;
	}

	await admin.editPost( postId );
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
let originalTheme;

test.afterEach( () => {
	for ( const postId of createdPostIds ) {
		cleanupReviewChildren( postId );
		runWpCli( FIXTURE_WP_CLI, [
			'post',
			'delete',
			String( postId ),
			'--force',
		] );
	}

	createdPostIds.clear();
	if ( originalTheme ) {
		runWpCli( FIXTURE_WP_CLI, [ 'theme', 'activate', originalTheme ] );
		originalTheme = undefined;
	}
} );

test( 'preview link admin, editor, public, invalid, expired, revoked, and post boundaries smoke test', async ( {
	page,
	admin,
	requestUtils,
	browser,
	baseURL,
}, testInfo ) => {
	if ( runBlockThemePreviewRegression ) {
		originalTheme = runWpCliForOutput( FIXTURE_WP_CLI, [
			'option',
			'get',
			'stylesheet',
		] );
		runWpCli( FIXTURE_WP_CLI, [ 'theme', 'activate', 'twentytwentyfive' ] );
	}
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
	const nativeComment = `Existing native comment ${ Date.now() }`;
	if ( runBlockThemePreviewRegression ) {
		runWpCli( FIXTURE_WP_CLI, [
			'comment',
			'create',
			`--comment_post_ID=${ post.id }`,
			`--comment_content=${ nativeComment }`,
			'--comment_author=PreviewShare E2E',
			'--comment_approved=1',
		] );
	}

	const browserDiagnostics = {
		consoleErrors: [],
		pageErrors: [],
		failedRequests: [],
		relevantResponses: [],
	};
	page.on( 'console', ( message ) => {
		if ( message.type() === 'error' ) {
			browserDiagnostics.consoleErrors.push( message.text() );
		}
	} );
	page.on( 'pageerror', ( error ) => {
		browserDiagnostics.pageErrors.push( error.stack || error.message );
	} );
	page.on( 'requestfailed', ( request ) => {
		browserDiagnostics.failedRequests.push(
			`${ request.method() } ${ request.url() }: ${ request.failure()?.errorText || 'unknown failure' }`
		);
	} );
	page.on( 'response', ( response ) => {
		if (
			/previewshare|settings\.min\.js|wp-admin\/load/i.test(
				response.url()
			)
		) {
			browserDiagnostics.relevantResponses.push(
				`${ response.status() } ${ response.url() }`
			);
		}
	} );

	let settingsResponseStatus = null;
	let tokensResponseStatus = null;
	const settingsResponse = page
		.waitForResponse(
			( response ) =>
				response.request().method() === 'GET' &&
				responseMatchesRoute( response, '/previewshare/v1/settings' )
		)
		.then( ( response ) => {
			settingsResponseStatus = response.status();
			return response;
		} );
	const tokensResponse = page
		.waitForResponse(
			( response ) =>
				response.request().method() === 'GET' &&
				responseMatchesRoute( response, '/previewshare/v1/tokens' )
		)
		.then( ( response ) => {
			tokensResponseStatus = response.status();
			return response;
		} );

	await admin.visitAdminPage(
		'options-general.php',
		'page=previewshare_settings'
	);
	if ( ! settingsResponseStatus || ! tokensResponseStatus ) {
		await page.waitForTimeout( 5000 );
		console.log(
			'[PreviewShare E2E] Settings page diagnostics:',
			JSON.stringify( {
				url: page.url(),
				title: await page.title(),
				appHTML: (
					await page.locator( '#previewshare-settings-app' ).innerHTML()
				).slice( 0, 2000 ),
				bodyText: ( await page.locator( 'body' ).innerText() ).slice(
					0,
					2000
				),
				...browserDiagnostics,
			} )
		);
	}
	await expectSuccessfulResponse(
		settingsResponse,
		'PreviewShare settings request'
	);
	await expectSuccessfulResponse(
		tokensResponse,
		'PreviewShare tokens request'
	);
	await expect( page.locator( '#previewshare-settings-app' ) ).toBeVisible();

	const settingsIconSizes = await page
		.locator( '#previewshare-settings-app svg' )
		.evaluateAll( ( icons ) =>
			icons.map( ( icon ) => {
				const bounds = icon.getBoundingClientRect();
				return Math.max( bounds.width, bounds.height );
			} )
		);
	expect( Math.max( 0, ...settingsIconSizes ) ).toBeLessThanOrEqual( 32 );

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
	const overviewTab = tablist.getByRole( 'tab', {
		name: 'Overview',
		exact: true,
	} );
	await overviewTab.focus();
	await overviewTab.press( 'ArrowRight' );
	console.log(
		'[PreviewShare E2E] ArrowRight result:',
		JSON.stringify( {
			pageErrors: browserDiagnostics.pageErrors,
			consoleErrors: browserDiagnostics.consoleErrors,
			tabs: await page.locator( '[role="tab"]' ).evaluateAll(
				( tabs ) =>
					tabs.map( ( tab ) => ( {
						text: tab.textContent,
						selected: tab.getAttribute( 'aria-selected' ),
					} ) )
			),
			appText: await page.locator( '#previewshare-settings-app' ).textContent(),
			reactRuntime: await page.evaluate( () => ( {
				wpElementInsertion: typeof window.wp?.element?.useInsertionEffect,
				wpElementLayout: typeof window.wp?.element?.useLayoutEffect,
				windowReactInsertion: typeof window.React?.useInsertionEffect,
				windowReactLayout: typeof window.React?.useLayoutEffect,
			} ) ),
		} )
	);
	const previewLinksTab = tablist.getByRole( 'tab', {
		name: 'Preview links',
		exact: true,
	} );
	await expect( previewLinksTab ).toHaveAttribute( 'aria-selected', 'true' );
	const focusStyle = await previewLinksTab.evaluate( ( tab ) => {
		const style = window.getComputedStyle( tab );
		return {
			outlineStyle: style.outlineStyle,
			outlineWidth: Number.parseFloat( style.outlineWidth ),
		};
	} );
	expect( focusStyle.outlineStyle ).toBe( 'solid' );
	expect( focusStyle.outlineWidth ).toBeGreaterThanOrEqual( 2 );
	await previewLinksTab.press( 'Home' );
	await expect( overviewTab ).toHaveAttribute( 'aria-selected', 'true' );
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
	await expect( pluginCards ).toHaveCount( 7 );
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

	await visitEditor( admin, post.id );
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
	const previewUrl = resolvePreviewUrlForTestServer(
		generated.url,
		baseURL
	);
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
	await expect( anonymous.locator( '#previewshare-review-form' ) ).toHaveCount( 0 );
	await expect( anonymous.locator( '#commentform, #respond form' ) ).toHaveCount( 0 );
	if ( runBlockThemePreviewRegression ) {
		await expect( anonymous.getByText( nativeComment, { exact: true } ) ).toHaveCount( 0 );
	}

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
	const modernInventory = page.locator( '.previewshare-dataviews' );
	const legacyInventory = page.locator(
		'.previewshare-tab-content > .previewshare-legacy-inventory'
	);
	const usingLegacyInventory = await legacyInventory.isVisible();
	expect( usingLegacyInventory ).toBe( true );
	const inventoryTable = usingLegacyInventory
		? legacyInventory
		: modernInventory;
	const expiringStatus = inventoryTable.locator(
		'.previewshare-status.is-expiring_soon'
	);
	const extendButton = inventoryTable.getByRole( 'button', {
		name: 'Extend',
		exact: true,
	} );
	await expect( inventoryTable ).toBeVisible();
	const desktopCard = expiringStatus.locator( 'xpath=ancestor::tr[1]' );
	for ( const label of [
		'Content',
		'Label',
		'Status',
		'Views',
		'Expires',
		'Last viewed',
		'Actions',
	] ) {
		const cell = desktopCard.locator( `td[data-label="${ label }"]` );
		await expect( cell ).toBeVisible();
		await expect( cell ).not.toBeEmpty();
	}
	await expectCellTextToFit( desktopCard.locator( 'td' ) );
	const desktopCardWidths = await desktopCard.evaluate( ( card ) => ( {
		client: card.clientWidth,
		scroll: card.scrollWidth,
	} ) );
	expect( desktopCardWidths.scroll ).toBeLessThanOrEqual(
		desktopCardWidths.client
	);
	const desktopPageWidth = await page.evaluate(
		() => document.documentElement.scrollWidth
	);
	expect( desktopPageWidth ).toBeLessThanOrEqual( 1280 );
	await expect(
		page.getByRole( 'button', { name: 'Extend', exact: true } )
	).toBeVisible();
	const linkSearch = page.getByRole( 'textbox', {
		name: 'Search preview links',
	} );
	await linkSearch.fill( String( generatedLink.id ) );
	await expect( extendButton ).toHaveCount( 0 );
	await linkSearch.fill( 'E2E smoke' );
	await expect( extendButton ).toBeVisible();
	await linkSearch.fill( 'no matching preview link' );
	await expect( extendButton ).toHaveCount( 0 );
	await linkSearch.fill( 'E2E smoke' );
	const expiredFilter = page.getByRole( 'checkbox', { name: 'Expired' } );
	await expiredFilter.check();
	await expect( extendButton ).toHaveCount( 0 );
	await expiredFilter.uncheck();
	const expiringFilter = page.getByRole( 'checkbox', {
		name: 'Expiring soon',
	} );
	await expiringFilter.check();
	await expect( extendButton ).toBeVisible();
	await page.setViewportSize( { width: 1600, height: 900 } );
	const hasModernRuntime = await page.evaluate(
		() => typeof window.wp.element.useInsertionEffect === 'function'
	);
	if ( hasModernRuntime ) {
		await expect( modernInventory ).toBeVisible();
		await expect(
			modernInventory.getByText( postTitle, { exact: true } )
		).toBeVisible();
		await expectCellTextToFit(
			modernInventory.locator( 'thead th, tbody tr:first-child td' )
		);
		await page.screenshot( {
			path: testInfo.outputPath( 'previewshare-preview-links-wide.png' ),
			fullPage: true,
		} );
	}
	await page.setViewportSize( { width: 1280, height: 900 } );
	await expect( legacyInventory ).toBeVisible();
	await expect(
		legacyInventory.getByRole( 'checkbox', { name: 'Expiring soon' } )
	).toBeChecked();
	await page.screenshot( {
		path: testInfo.outputPath( 'previewshare-preview-links-expiring.png' ),
		fullPage: true,
	} );
	await page.setViewportSize( { width: 390, height: 844 } );
	const mobileInventory = legacyInventory;
	await expect( mobileInventory ).toBeVisible();
	const mobileStatus = mobileInventory.locator(
		'.previewshare-status.is-expiring_soon'
	);
	const mobileCard = mobileStatus.locator( 'xpath=ancestor::tr[1]' );
	for ( const label of [
		'Content',
		'Label',
		'Status',
		'Views',
		'Expires',
		'Last viewed',
		'Actions',
	] ) {
		const cell = mobileCard.locator( `td[data-label="${ label }"]` );
		await expect( cell ).toBeVisible();
		await expect( cell ).not.toBeEmpty();
	}
	await expect( mobileCard ).toContainText( generatedLink.post_title );
	await expect( mobileCard ).toContainText( generatedLink.label );
	const mobileExtendButton = mobileCard.getByRole( 'button', {
		name: 'Extend',
		exact: true,
	} );
	await mobileExtendButton.focus();
	await expect( mobileExtendButton ).toBeFocused();
	await expect( mobileExtendButton ).toBeInViewport();
	const mobilePageWidth = await page.evaluate(
		() => document.documentElement.scrollWidth
	);
	expect( mobilePageWidth ).toBeLessThanOrEqual( 390 );
	const cardScrollMetrics = await mobileCard.evaluate( ( card ) => ( {
		clientWidth: card.clientWidth,
		scrollWidth: card.scrollWidth,
	} ) );
	expect( cardScrollMetrics.scrollWidth ).toBeLessThanOrEqual(
		cardScrollMetrics.clientWidth
	);
	await expect( mobileStatus ).toHaveText( 'Expiring soon' );

	const expiringStatusIsPainted = await mobileStatus.evaluate( ( status ) => {
		const bounds = status.getBoundingClientRect();
		const visibleElement = document.elementFromPoint(
			bounds.left + bounds.width / 2,
			bounds.top + bounds.height / 2
		);

		return status === visibleElement || status.contains( visibleElement );
	} );
	expect( expiringStatusIsPainted ).toBe( true );
	const extendButtonIsPainted = await mobileExtendButton.evaluate(
		( button ) => {
			const bounds = button.getBoundingClientRect();
			const visibleElement = document.elementFromPoint(
				bounds.left + bounds.width / 2,
				bounds.top + bounds.height / 2
			);

			return (
				button === visibleElement || button.contains( visibleElement )
			);
		}
	);
	expect( extendButtonIsPainted ).toBe( true );
	const mobileExtendBounds = await mobileExtendButton.boundingBox();
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

	await visitEditor( admin, post.id );

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

	await page.screenshot( {
		path: testInfo.outputPath( 'previewshare-revoked-editor.png' ),
		fullPage: true,
	} );

	const revokedPreviewResponse = await anonymous.goto(
		regeneratedPreviewUrl
	);
	expect( revokedPreviewResponse.status() ).toBe( 410 );
	await expect(
		anonymous.getByText( unavailablePreviewMessage )
	).toBeVisible();
	await anonymous.screenshot( {
		path: testInfo.outputPath( 'previewshare-revoked-public-denial.png' ),
		fullPage: true,
	} );

	const publishedPost = await requestUtils.createPost( {
		title: `PreviewShare e2e published ${ Date.now() }`,
		content: publishedPostContent,
		status: 'publish',
	} );
	createdPostIds.add( publishedPost.id );
	runWpCli( FIXTURE_WP_CLI, [
		'post',
		'update',
		String( publishedPost.id ),
		'--comment_status=open',
	] );
	const publishedComment = `Published native comment ${ Date.now() }`;
	runWpCli( FIXTURE_WP_CLI, [
		'comment',
		'create',
		`--comment_post_ID=${ publishedPost.id }`,
		`--comment_content=${ publishedComment }`,
		'--comment_author=PreviewShare E2E',
		'--comment_approved=1',
	] );
	const publishedPostResponse = await anonymous.goto(
		`/?p=${ publishedPost.id }`
	);
	expect( publishedPostResponse.status() ).toBe( 200 );
	await expect(
		anonymous.getByText( publishedPostContent, { exact: true } )
	).toBeVisible();
	await expect( anonymous.locator( '#commentform' ) ).toHaveCount( 1 );
	await expect( anonymous.getByText( publishedComment, { exact: true } ) ).toBeVisible();
	await anonymous.screenshot( {
		path: testInfo.outputPath( 'previewshare-published-comments.png' ),
		fullPage: true,
	} );

	await anonymousContext.close();
} );

test( 'anonymous reviewers can send comments by keyboard and expired links reject responses', async ( {
	page,
	admin,
	requestUtils,
	browser,
	baseURL,
}, testInfo ) => {
	test.setTimeout( 180000 );
	const post = await requestUtils.createPost( {
		title: `PreviewShare anonymous comment ${ Date.now() }`,
		content: 'Draft for anonymous reviewer feedback.',
		status: 'draft',
	} );
	createdPostIds.add( post.id );

	await visitEditor( admin, post.id );
	await ensurePreviewSharePanelOpen( page );
	await page
		.getByRole( 'checkbox', { name: 'Allow reviewer responses' } )
		.check();
	const [ generatedResponse ] = await Promise.all( [
		page.waitForResponse( isGeneratePreviewResponse ),
		page.getByRole( 'button', { name: 'Generate & copy' } ).click(),
	] );
	expect( generatedResponse.status() ).toBe( 200 );
	const generated = await generatedResponse.json();
	const previewUrl = resolvePreviewUrlForTestServer( generated.url, baseURL );

	const anonymousContext = await browser.newContext( {
		baseURL,
		storageState: { cookies: [], origins: [] },
	} );
	const anonymous = await anonymousContext.newPage();
	const previewResponse = await anonymous.goto( previewUrl );
	expect( previewResponse.status() ).toBe( 200 );
	await anonymous.setViewportSize( { width: 320, height: 640 } );
	expect(
		await anonymous.evaluate( () => document.documentElement.scrollWidth )
	).toBeLessThanOrEqual( 320 );
	const form = anonymous.locator( '#previewshare-review-form' );
	await expect(
		form.getByRole( 'textbox', { name: 'Name' } )
	).not.toHaveAttribute( 'required', '' );
	await expect(
		form.getByRole( 'textbox', { name: 'Email' } )
	).not.toHaveAttribute( 'required', '' );

	const responseType = form.getByRole( 'radio', { name: 'Approve' } );
	await responseType.focus();
	await anonymous.keyboard.press( 'ArrowRight' );
	await anonymous.keyboard.press( 'ArrowRight' );
	await anonymous.keyboard.press( 'Space' );
	await expect( form.getByRole( 'radio', { name: 'Comment' } ) ).toBeChecked();
	await anonymous.keyboard.press( 'Tab' );
	const comment = form.getByRole( 'textbox', { name: 'Comment' } );
	await expect( comment ).toBeFocused();
	await expect( comment ).toHaveCSS( 'outline-color', 'rgb(37, 85, 170)' );
	await anonymous.screenshot( {
		path: testInfo.outputPath( 'previewshare-review-focus-field-mobile.png' ),
		fullPage: true,
	} );
	await comment.pressSequentially( 'The draft reads clearly.' );
	await anonymous.keyboard.press( 'Tab' );
	await anonymous.keyboard.press( 'Tab' );
	await anonymous.keyboard.press( 'Tab' );
	const submitButton = form.getByRole( 'button', { name: 'Send response' } );
	await expect( submitButton ).toBeFocused();
	await expect( submitButton ).toHaveCSS( 'outline-color', 'rgb(37, 85, 170)' );
	await anonymous.screenshot( {
		path: testInfo.outputPath( 'previewshare-review-focus-mobile.png' ),
		fullPage: true,
	} );
	const [ submitResponse ] = await Promise.all( [
		anonymous.waitForResponse( ( response ) =>
			responseMatchesRoute( response, '/previewshare/v1/reviews/submit' )
		),
		anonymous.keyboard.press( 'Enter' ),
	] );
	expect( submitResponse.status() ).toBe( 201 );
	const submittedBody = submitResponse.request().postDataJSON();
	expect( submittedBody.response_type ).toBe( 'comment' );
	expect( submittedBody.name ).toBe( '' );
	expect( submittedBody.email ).toBe( '' );
	await expect( form.getByRole( 'status' ) ).toHaveText(
		'Your response was received.'
	);

	await expirePreviewLinkIfConfigured( { postId: post.id, previewUrl } );
	const expiredPreviewResponse = await anonymous.goto( previewUrl );
	expect( expiredPreviewResponse.status() ).toBe( 410 );
	await expect(
		anonymous.getByText( unavailablePreviewMessage )
	).toBeVisible();
	const staleSubmission = await anonymousContext.request.post(
		new URL( '/wp-json/previewshare/v1/reviews/submit', baseURL ).toString(),
		{
			data: {
				...submittedBody,
				request_id: 'expired-link-stale-123456',
			},
		}
	);
	expect( staleSubmission.status() ).toBe( 410 );
	await anonymousContext.close();
} );

test( 'opted-in reviewer responses stay private, follow content versions, and stop on revoke', async ( {
	page,
	admin,
	requestUtils,
	browser,
	baseURL,
}, testInfo ) => {
	test.setTimeout( 180000 );
	if ( runBlockThemePreviewRegression ) {
		originalTheme = runWpCliForOutput( FIXTURE_WP_CLI, [
			'option',
			'get',
			'stylesheet',
		] );
		runWpCli( FIXTURE_WP_CLI, [ 'theme', 'activate', 'twentytwentyfive' ] );
	}
	const post = await requestUtils.createPost( {
		title: `PreviewShare review ${ Date.now() }`,
		content: 'First review draft.',
		status: 'draft',
	} );
	createdPostIds.add( post.id );
	const nativeComment = `Existing reviewer-preview comment ${ Date.now() }`;
	if ( runBlockThemePreviewRegression ) {
		runWpCli( FIXTURE_WP_CLI, [
			'comment',
			'create',
			`--comment_post_ID=${ post.id }`,
			`--comment_content=${ nativeComment }`,
			'--comment_author=PreviewShare E2E',
			'--comment_approved=1',
		] );
	}

	await visitEditor( admin, post.id );
	await ensurePreviewSharePanelOpen( page );
	await page
		.getByRole( 'textbox', { name: 'Link label' } )
		.fill( 'Reviewer feedback E2E' );
	await page
		.getByRole( 'checkbox', { name: 'Allow reviewer responses' } )
		.check();
	await page
		.getByRole( 'checkbox', { name: 'Require name and email' } )
		.check();
	const [ generatedResponse ] = await Promise.all( [
		page.waitForResponse( isGeneratePreviewResponse ),
		page.getByRole( 'button', { name: 'Generate & copy' } ).click(),
	] );
	expect( generatedResponse.status() ).toBe( 200 );
	const generated = await generatedResponse.json();
	const previewUrl = resolvePreviewUrlForTestServer( generated.url, baseURL );

	const anonymousContext = await browser.newContext( {
		baseURL,
		storageState: { cookies: [], origins: [] },
	} );
	const anonymous = await anonymousContext.newPage();
	const publicResponse = await anonymous.goto( previewUrl );
	expect( publicResponse.status() ).toBe( 200 );
	const form = anonymous.locator( '#previewshare-review-form' );
	await expect( form ).toBeVisible();
	await expect( form ).toHaveCount( 1 );
	await expect( anonymous.locator( '#commentform, #respond form' ) ).toHaveCount( 0 );
	if ( runBlockThemePreviewRegression ) {
		await expect( anonymous.getByText( nativeComment, { exact: true } ) ).toHaveCount( 0 );
		const reviewFormFollowsContent = await anonymous.evaluate( () => {
			const formElement = document.querySelector( '#previewshare-review-form' );
			const content = formElement?.closest( '.wp-block-post-content' );
			const contentParagraph = content?.querySelector( 'p' );

			return Boolean(
				contentParagraph &&
				contentParagraph.compareDocumentPosition( formElement ) &
					Node.DOCUMENT_POSITION_FOLLOWING
			);
		} );
		expect( reviewFormFollowsContent ).toBe( true );
	}
	await expect(
		anonymous.locator(
			'.entry-content #previewshare-review, .wp-block-post-content #previewshare-review'
		)
	).toHaveCount( 1 );
	await expect( form.getByRole( 'textbox', { name: 'Name' } ) ).toHaveAttribute( 'required', '' );
	await expect( form.locator( '[name="content_snapshot"]' ) ).toHaveValue( /^[a-f0-9]{64}\.[a-f0-9]{64}$/ );
	await anonymous.screenshot( {
		path: testInfo.outputPath( 'previewshare-review-desktop.png' ),
		fullPage: true,
	} );
	await anonymous.setViewportSize( { width: 390, height: 844 } );
	await anonymous.screenshot( {
		path: testInfo.outputPath( 'previewshare-review-mobile.png' ),
		fullPage: true,
	} );
	const mobileWidth = await anonymous.evaluate(
		() => document.documentElement.scrollWidth
	);
	expect( mobileWidth ).toBeLessThanOrEqual( 390 );
	await form.getByRole( 'textbox', { name: 'Name' } ).fill( 'Review Tester' );
	await form
		.getByRole( 'textbox', { name: 'Email' } )
		.fill( 'reviewer@example.test' );
	runWpCli( FIXTURE_WP_CLI, [
		'post',
		'update',
		String( post.id ),
		'--post_content=Revised review draft before approval.',
	] );
	const [ submitResponse ] = await Promise.all( [
		anonymous.waitForResponse( ( response ) =>
			responseMatchesRoute(
				response,
				'/previewshare/v1/reviews/submit'
			)
		),
		form.getByRole( 'button', { name: 'Send response' } ).click(),
	] );
	expect( submitResponse.status() ).toBe( 409 );
	await expect( form.getByRole( 'status' ) ).toHaveText(
		'This content changed after you opened the preview. Refresh the page to review the latest version before approving.'
	);
	const noJsContext = await browser.newContext( {
		baseURL,
		javaScriptEnabled: false,
		storageState: { cookies: [], origins: [] },
	} );
	const noJsReviewer = await noJsContext.newPage();
	await noJsReviewer.goto( previewUrl );
	const noJsForm = noJsReviewer.locator( '#previewshare-review-form' );
	await noJsForm.getByRole( 'textbox', { name: 'Name' } ).fill( 'Review Tester' );
	await noJsForm.getByRole( 'textbox', { name: 'Email' } ).fill( 'reviewer@example.test' );
	const [ noJsResponse ] = await Promise.all( [
		noJsReviewer.waitForResponse( ( response ) =>
			responseMatchesRoute( response, '/previewshare/v1/reviews/submit' )
		),
		noJsForm.getByRole( 'button', { name: 'Send response' } ).click(),
	] );
	expect( noJsResponse.status() ).toBe( 201 );
	await expect( noJsReviewer.locator( 'body' ) ).toContainText( '"received":true' );
	await noJsContext.close();

	await page.reload();
	await ensurePreviewSharePanelOpen( page );
	await page.getByRole( 'button', { name: 'Review settings and history' } ).click();
	await expect(
		page.locator( '.previewshare-panel__review-state' )
	).toHaveText( 'Approved' );
	await expect( page.getByText( 'Review Tester' ) ).toBeVisible();
	await expect( page.getByText( 'reviewer@example.test' ) ).toBeVisible();

	runWpCli( FIXTURE_WP_CLI, [
		'post',
		'update',
		String( post.id ),
		'--post_content=Another revised review draft.',
	] );
	await page.reload();
	await ensurePreviewSharePanelOpen( page );
	await page.getByRole( 'button', { name: 'Review settings and history' } ).click();
	await expect(
		page.getByText( 'Approval needs review after edit' )
	).toBeVisible();

	await anonymous.goto( previewUrl );
	await form.getByRole( 'radio', { name: 'Request changes' } ).check();
	await form
		.getByRole( 'textbox', { name: 'Comment' } )
		.fill( 'Please revise the opening paragraph.' );
	await form.getByRole( 'textbox', { name: 'Name' } ).fill( 'Review Tester' );
	await form
		.getByRole( 'textbox', { name: 'Email' } )
		.fill( 'reviewer@example.test' );
	const [ changeResponse ] = await Promise.all( [
		anonymous.waitForResponse( ( response ) =>
			responseMatchesRoute(
				response,
				'/previewshare/v1/reviews/submit'
			)
		),
		form.getByRole( 'button', { name: 'Send response' } ).click(),
	] );
	expect( changeResponse.status() ).toBe( 201 );
	await expect( form.getByRole( 'status' ) ).toHaveText(
		'Your response was received.'
	);

	await page.reload();
	await ensurePreviewSharePanelOpen( page );
	await page.getByRole( 'button', { name: 'Review settings and history' } ).click();
	await expect(
		page.locator( '.previewshare-panel__review-state' )
	).toHaveText( 'Changes requested' );
	await expect(
		page.getByText( 'Please revise the opening paragraph.' )
	).toBeVisible();
	await page.getByRole( 'button', { name: 'Resolve', exact: true } ).click();
	await expect( page.getByText( 'Resolved', { exact: true } ) ).toBeVisible();

	const [ revokeResponse ] = await Promise.all( [
		page.waitForResponse( isRevokePreviewResponse ),
		page
			.getByRole( 'checkbox', { name: 'Enable Public Preview' } )
			.click(),
	] );
	expect( revokeResponse.status() ).toBe( 200 );
	await expect(
		page.locator( '.previewshare-panel__review-history li' )
	).toHaveCount( 2 );
	const rejected = await anonymousContext.request.post(
		new URL( '/wp-json/previewshare/v1/reviews/submit', baseURL ).toString(),
		{
			data: {
				...submitResponse.request().postDataJSON(),
				request_id: 'new-request-after-revoke-123456',
			},
		}
	);
	expect( rejected.status() ).toBe( 410 );
	const retentionProof = `
$post_id = ${ Number( post.id ) };
$args = array( 'post_type' => 'previewshare_review', 'post_status' => 'private', 'post_parent' => $post_id, 'fields' => 'ids', 'posts_per_page' => -1 );
$ids = get_posts( $args );
if ( count( $ids ) !== 2 ) { fwrite( STDERR, 'Expected two private review records.' ); exit( 1 ); }
foreach ( $ids as $id ) { if ( '0000-00-00 00:00:00' === get_post( $id )->post_date_gmt ) { fwrite( STDERR, 'Review GMT date was not stored.' ); exit( 1 ); } }
$hash = (string) get_post_meta( $ids[0], '_previewshare_link_hash', true );
$reviews = PreviewShare\\Container::get( 'reviews' );
$latest = $reviews->latest_for_links( array( $hash => $post_id ) );
if ( ! isset( $latest[ $hash ] ) || 'request_changes' !== $latest[ $hash ]['response_type'] ) { fwrite( STDERR, 'Batched inventory missed the latest response.' ); exit( 1 ); }
$exporters = apply_filters( 'wp_privacy_personal_data_exporters', array() );
$export = call_user_func( $exporters['previewshare-reviews']['callback'], 'reviewer@example.test', 1 );
if ( count( $export['data'] ) !== 2 ) { fwrite( STDERR, 'Privacy exporter missed reviewer responses.' ); exit( 1 ); }
do_action( 'previewshare_cleanup_reviews' );
if ( count( get_posts( $args ) ) !== 2 ) { fwrite( STDERR, 'Fresh review records were removed early.' ); exit( 1 ); }
$old = time() - ( 91 * DAY_IN_SECONDS );
foreach ( $ids as $id ) { wp_update_post( array( 'ID' => $id, 'post_date' => gmdate( 'Y-m-d H:i:s', $old ), 'post_date_gmt' => gmdate( 'Y-m-d H:i:s', $old ) ) ); }
do_action( 'previewshare_cleanup_reviews' );
if ( get_posts( $args ) ) { fwrite( STDERR, 'Expired review records were not removed.' ); exit( 1 ); }
`;
	runWpCli( FIXTURE_WP_CLI, [ 'eval', retentionProof ] );
	await anonymousContext.close();
} );

test( 'privacy eraser paginates matching reviews, preserves other emails, and rejects stale reset state', async ( {
	requestUtils,
} ) => {
	const parent = await requestUtils.createPost( {
		title: `PreviewShare erasure ${ Date.now() }`,
		content: 'Privacy erasure fixture parent.',
		status: 'draft',
	} );
	createdPostIds.add( parent.id );

	const erasureProof = `
$parent_id = ${ Number( parent.id ) };
$target_email = 'eraser-target-${ Number( parent.id ) }@example.test';
$control_email = 'eraser-control-${ Number( parent.id ) }@example.test';
$target_ids = array();
for ( $index = 1; $index <= 101; $index++ ) {
	$review_id = wp_insert_post( array(
		'post_type' => 'previewshare_review',
		'post_status' => 'private',
		'post_parent' => $parent_id,
		'post_title' => 'Privacy erasure target ' . $index,
		'post_content' => 'Synthetic response for the isolated erasure fixture.',
	), true );
	if ( is_wp_error( $review_id ) ) { fwrite( STDERR, 'Could not create target review: ' . $review_id->get_error_message() ); exit( 1 ); }
	update_post_meta( $review_id, '_previewshare_reviewer_email', $target_email );
	if ( $index <= 100 ) { $target_ids[] = (int) $review_id; }
}
$control_id = wp_insert_post( array(
	'post_type' => 'previewshare_review',
	'post_status' => 'private',
	'post_parent' => $parent_id,
	'post_title' => 'Privacy erasure control',
	'post_content' => 'This response belongs to another email.',
), true );
if ( is_wp_error( $control_id ) ) { fwrite( STDERR, 'Could not create control review: ' . $control_id->get_error_message() ); exit( 1 ); }
update_post_meta( $control_id, '_previewshare_reviewer_email', $control_email );

$erasers = apply_filters( 'wp_privacy_personal_data_erasers', array() );
if ( empty( $erasers['previewshare-reviews']['callback'] ) ) { fwrite( STDERR, 'PreviewShare privacy eraser was not registered.' ); exit( 1 ); }
$erase = $erasers['previewshare-reviews']['callback'];
$fail_target_deletes = static function ( $delete, $post ) use ( $target_ids ) {
	return in_array( (int) $post->ID, $target_ids, true ) ? false : $delete;
};
add_filter( 'pre_delete_post', $fail_target_deletes, 10, 3 );
$first = call_user_func( $erase, $target_email, 1 );
remove_filter( 'pre_delete_post', $fail_target_deletes, 10 );
if ( is_wp_error( $first ) || ! empty( $first['done'] ) || empty( $first['items_retained'] ) ) { fwrite( STDERR, 'First erasure page did not retain the failed records and report more work.' ); exit( 1 ); }

$second = call_user_func( $erase, $target_email, 2 );
if ( is_wp_error( $second ) || empty( $second['done'] ) || empty( $second['items_removed'] ) || empty( $second['items_retained'] ) ) { fwrite( STDERR, 'Second erasure page did not remove the 101st record and report completion.' ); exit( 1 ); }
$remaining_target = get_posts( array(
	'post_type' => 'previewshare_review',
	'post_status' => 'private',
	'fields' => 'ids',
	'posts_per_page' => -1,
	'orderby' => 'ID',
	'order' => 'ASC',
	'meta_key' => '_previewshare_reviewer_email',
	'meta_value' => $target_email,
) );
if ( array_map( 'intval', $remaining_target ) !== $target_ids ) { fwrite( STDERR, 'Erasure changed target records outside the first hundred failed deletions.' ); exit( 1 ); }
if ( ! get_post( $control_id ) || $control_email !== get_post_meta( $control_id, '_previewshare_reviewer_email', true ) ) { fwrite( STDERR, 'Erasure changed the other-email control record.' ); exit( 1 ); }

$cursor_key = 'previewshare_review_eraser_' . hash( 'sha256', $target_email );
$stale_state = array( 'cursor' => (int) end( $target_ids ) + 1, 'items_removed' => true, 'items_retained' => true, 'next_page' => 3 );
set_transient( $cursor_key, $stale_state, DAY_IN_SECONDS );
// Re-expose stale progress after reset to model a transient backend with a stale read.
$restore_stale_state = static function ( $transient ) use ( $cursor_key, $stale_state ) {
	if ( $cursor_key === $transient ) { set_transient( $cursor_key, $stale_state, DAY_IN_SECONDS ); }
};
add_action( 'deleted_transient', $restore_stale_state, 10, 1 );
$retry = call_user_func( $erase, $target_email, 1 );
remove_action( 'deleted_transient', $restore_stale_state, 10 );
delete_transient( $cursor_key );
if ( ! is_wp_error( $retry ) || 'review_eraser_progress_reset_failed' !== $retry->get_error_code() ) { fwrite( STDERR, 'Fresh erasure retry did not fail closed when stale progress remained after reset.' ); exit( 1 ); }
if ( count( get_posts( array(
	'post_type' => 'previewshare_review',
	'post_status' => 'private',
	'fields' => 'ids',
	'posts_per_page' => -1,
	'orderby' => 'ID',
	'order' => 'ASC',
	'meta_key' => '_previewshare_reviewer_email',
	'meta_value' => $target_email,
) ) ) !== 100 ) { fwrite( STDERR, 'Failed retry did not leave the 100 undeleted target responses intact.' ); exit( 1 ); }
`;
	runWpCli( FIXTURE_WP_CLI, [ 'eval', erasureProof ] );
} );
