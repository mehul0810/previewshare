const fs = require( 'fs/promises' );
const { dirname } = require( 'path' );
const { request } = require( '@playwright/test' );

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

async function getRestNonceFromAdminPage( requestContext ) {
	const adminPage = await requestContext.get(
		'wp-admin/options-general.php?page=previewshare_settings'
	);
	if ( ! adminPage.ok() ) {
		throw new Error(
			`Could not load the WordPress admin page to read its REST nonce (HTTP ${ adminPage.status() }).`
		);
	}

	if ( /wp-login\.php/i.test( adminPage.url() ) ) {
		throw new Error( 'WordPress redirected the PreviewShare settings request to the login page.' );
	}

	const html = await adminPage.text();
	const settingsMatch = html.match( /\b(?:wpApiSettings|previewshare_settings)\s*=\s*/ );
	if ( ! settingsMatch ) {
		const title = html.match( /<title>([^<]*)<\/title>/i )?.[ 1 ] || 'unknown';
		const contentType = adminPage.headers()[ 'content-type' ] || 'unknown';
		const scriptIds = [ ...html.matchAll( /<script\b[^>]*\bid=["']([^"']+)["']/gi ) ]
			.map( ( match ) => match[ 1 ] )
			.join( ', ' );
		throw new Error(
			`Could not find REST settings on the PreviewShare admin page (${ adminPage.url() }, HTTP ${ adminPage.status() }, content type: ${ contentType }, bytes: ${ Buffer.byteLength( html ) }, document: ${ /<html\b/i.test( html ) }, body: ${ /<body\b/i.test( html ) }, title: ${ title }, login form: ${ /id=["']loginform["']/i.test( html ) }, admin bar: ${ /id=["']wpadminbar["']/i.test( html ) }, app mount: ${ html.includes( 'previewshare-settings-app' ) }, permission denied: ${ /You do not have sufficient permissions/i.test( html ) }, scripts: ${ scriptIds || 'none' }).`
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

async function loginWithForm( requestContext ) {
	// Seed the WordPress test cookie before posting credentials. This is required
	// by older core versions supported by the plugin.
	await requestContext.get( 'wp-login.php' );

	const response = await requestContext.post( 'wp-login.php', {
		form: {
			log: process.env.WP_USERNAME || 'admin',
			pwd: process.env.WP_PASSWORD || 'password',
			'wp-submit': 'Log In',
			redirect_to: 'wp-admin/',
			testcookie: '1',
		},
	} );

	if ( ! response.ok() ) {
		throw new Error( `WordPress login failed with HTTP ${ response.status() }.` );
	}

	const responseHtml = await response.text();
	if (
		/\/wp-login\.php(?:[?#]|$)/i.test( response.url() ) ||
		/id=["']login_error["']/i.test( responseHtml )
	) {
		throw new Error(
			`WordPress did not accept the E2E login (HTTP ${ response.status() }, final URL: ${ response.url() }, login error shown: ${ /id=["']login_error["']/i.test( responseHtml ) }).`
		);
	}
}

async function globalSetup( config ) {
	const { storageState, baseURL } = config.projects[ 0 ].use;
	const storageStatePath =
		typeof storageState === 'string' ? storageState : undefined;
	const requestContext = await request.newContext( { baseURL } );

	try {
		await loginWithForm( requestContext );
		const nonce = await getRestNonceFromAdminPage( requestContext );
		const rootURL = await findRestRoot( requestContext, baseURL );
		const { cookies } = await requestContext.storageState();

		if ( storageStatePath ) {
			await fs.mkdir( dirname( storageStatePath ), { recursive: true } );
			await fs.writeFile(
				storageStatePath,
				JSON.stringify( { cookies, nonce, rootURL } ),
				'utf-8'
			);
		}
	} finally {
		await requestContext.dispose();
	}
}

module.exports = globalSetup;
