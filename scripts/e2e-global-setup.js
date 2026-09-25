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
	const settingsMatch = html.match(
		/\b(?:wpApiSettings|previewshare_settings)\s*=\s*(\{[\s\S]*?\})\s*;/
	);
	if ( ! settingsMatch ) {
		throw new Error(
			`Could not find REST settings on the PreviewShare admin page (${ adminPage.url() }, ${ html.length } bytes).`
		);
	}

	let settings;
	try {
		settings = JSON.parse( settingsMatch[ 1 ] );
	} catch {
		throw new Error(
			'Could not parse the REST settings on the authenticated PreviewShare admin page.'
		);
	}

	if ( typeof settings.nonce !== 'string' || ! settings.nonce ) {
		throw new Error(
			'The REST settings on the authenticated PreviewShare admin page did not include a nonce.'
		);
	}

	return settings.nonce;
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
