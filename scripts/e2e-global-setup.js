const fs = require( 'fs/promises' );
const { dirname } = require( 'path' );
const { chromium, request } = require( '@playwright/test' );

async function findRestRoot( requestContext, baseURL ) {
	const homepage = await requestContext.get( '/' );
	const linkHeader = homepage.headers().link;
	const restLink = linkHeader?.match(
		/<([^>]+)>; rel="https:\/\/api\.w\.org\/"/
	);

	if ( restLink ) {
		return restLink[ 1 ];
	}

	for ( const candidate of [ 'wp-json/', '?rest_route=/' ] ) {
		const response = await requestContext.get( candidate );
		if ( ! response.ok() ) {
			continue;
		}

		let index;
		try {
			index = await response.json();
		} catch {
			continue;
		}

		if ( index && typeof index.routes === 'object' ) {
			return new URL( candidate, baseURL ).href;
		}
	}

	throw new Error( 'Could not find the WordPress REST API root.' );
}

async function getRestNonceFromAdminPage( page, baseURL ) {
	const settingsResponse = await page.goto(
		new URL(
			'wp-admin/options-general.php?page=previewshare_settings',
			baseURL
		).href,
		{ waitUntil: 'domcontentloaded' }
	);
	if ( ! settingsResponse || ! settingsResponse.ok() ) {
		throw new Error(
			`Could not load the WordPress admin page to read its REST nonce (HTTP ${
				settingsResponse?.status() || 'unknown'
			}).`
		);
	}

	if ( /wp-login\.php/i.test( page.url() ) ) {
		throw new Error(
			'WordPress redirected the PreviewShare settings request to the login page.'
		);
	}

	const html = await page.content();
	const settingsMatch = html.match(
		/\b(?:wpApiSettings|previewshare_settings)\s*=\s*/
	);
	if ( ! settingsMatch ) {
		const title =
			html.match( /<title>([^<]*)<\/title>/i )?.[ 1 ] || 'unknown';
		const contentType =
			settingsResponse.headers()[ 'content-type' ] || 'unknown';
		const scriptIds = [
			...html.matchAll( /<script\b[^>]*\bid=["']([^"']+)["']/gi ),
		]
			.map( ( match ) => match[ 1 ] )
			.join( ', ' );
		throw new Error(
			`Could not find REST settings on the PreviewShare admin page (${ page.url() }, HTTP ${ settingsResponse.status() }, content type: ${ contentType }, bytes: ${ Buffer.byteLength(
				html
			) }, document: ${ /<html\b/i.test(
				html
			) }, body: ${ /<body\b/i.test(
				html
			) }, title: ${ title }, login form: ${ /id=["']loginform["']/i.test(
				html
			) }, admin bar: ${ /id=["']wpadminbar["']/i.test(
				html
			) }, app mount: ${ html.includes(
				'previewshare-settings-app'
			) }, permission denied: ${ /You do not have sufficient permissions/i.test(
				html
			) }, scripts: ${ scriptIds || 'none' }).`
		);
	}

	const nonceMatch = html
		.slice( settingsMatch.index + settingsMatch[ 0 ].length )
		.match( /["']nonce["']\s*:\s*["']([^"']+)["']/ );
	if ( ! nonceMatch || ! nonceMatch[ 1 ] ) {
		throw new Error(
			'The localized REST settings on the PreviewShare admin page did not include a nonce.'
		);
	}

	return nonceMatch[ 1 ];
}

async function loginWithForm( page, baseURL ) {
	const loginResponse = await page.goto(
		new URL( 'wp-login.php', baseURL ).href,
		{
			waitUntil: 'domcontentloaded',
		}
	);
	if ( ! loginResponse || ! loginResponse.ok() ) {
		throw new Error(
			`Could not load the WordPress login page (HTTP ${
				loginResponse?.status() || 'unknown'
			}).`
		);
	}

	await page
		.locator( '#user_login' )
		.fill( process.env.WP_USERNAME || 'admin' );
	await page
		.locator( '#user_pass' )
		.fill( process.env.WP_PASSWORD || 'password' );
	await page.locator( '#wp-submit' ).click();

	const loginError = page.locator( '#login_error' );
	if (
		/\/wp-login\.php(?:[?#]|$)/i.test( page.url() ) &&
		( await loginError.count() )
	) {
		const message = await loginError.innerText();
		let reason = 'WordPress rejected the login';
		if ( /cookie/i.test( message ) ) {
			reason = 'WordPress rejected the test cookie';
		} else if ( /password|username/i.test( message ) ) {
			reason = 'WordPress rejected the test credentials';
		}
		throw new Error( `${ reason } (HTTP ${ loginResponse.status() }).` );
	}

	const dashboardResponse = await page.goto(
		new URL( 'wp-admin/', baseURL ).href,
		{ waitUntil: 'domcontentloaded' }
	);
	if (
		! dashboardResponse ||
		! dashboardResponse.ok() ||
		/\/wp-login\.php(?:[?#]|$)/i.test( page.url() )
	) {
		throw new Error(
			`WordPress did not establish the E2E admin session (HTTP ${
				dashboardResponse?.status() || 'unknown'
			}).`
		);
	}
}

async function globalSetup( config ) {
	const { storageState, baseURL } = config.projects[ 0 ].use;
	const storageStatePath =
		typeof storageState === 'string' ? storageState : undefined;
	const browser = await chromium.launch();
	const browserContext = await browser.newContext( {
		baseURL,
		ignoreHTTPSErrors: true,
	} );

	try {
		const page = await browserContext.newPage();
		await loginWithForm( page, baseURL );
		const nonce = await getRestNonceFromAdminPage( page, baseURL );
		const state = await browserContext.storageState();
		const requestContext = await request.newContext( {
			baseURL,
			storageState: state,
		} );
		let rootURL;
		try {
			rootURL = await findRestRoot( requestContext, baseURL );
		} finally {
			await requestContext.dispose();
		}

		if ( storageStatePath ) {
			await fs.mkdir( dirname( storageStatePath ), { recursive: true } );
			await fs.writeFile(
				storageStatePath,
				JSON.stringify( { ...state, nonce, rootURL } ),
				'utf-8'
			);
		}
	} finally {
		await browserContext.close();
		await browser.close();
	}
}

module.exports = globalSetup;
