const fs = require( 'fs/promises' );
const { dirname } = require( 'path' );
const { request } = require( '@playwright/test' );
const { RequestUtils } = require( '@wordpress/e2e-test-utils-playwright' );

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

	const html = await adminPage.text();
	const settingsMatch = html.match(
		/\b(?:wpApiSettings|previewshare_settings)\s*=\s*(\{[^;]+\})\s*;/
	);
	if ( ! settingsMatch ) {
		throw new Error(
			'Could not find the REST settings on the authenticated PreviewShare admin page.'
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

async function globalSetup( config ) {
	const { storageState, baseURL } = config.projects[ 0 ].use;
	const storageStatePath =
		typeof storageState === 'string' ? storageState : undefined;
	const requestContext = await request.newContext( { baseURL } );
	const requestUtils = new RequestUtils( requestContext, { baseURL } );

	try {
		let nonce;
		try {
			nonce = await requestUtils.login();
		} catch ( error ) {
			nonce = await getRestNonceFromAdminPage( requestContext ).catch(
				( fallbackError ) => {
					throw new Error(
						`Could not retrieve a WordPress REST nonce. The standard endpoint failed (${ error.message }); the admin-page fallback failed (${ fallbackError.message }).`
					);
				}
			);
		}
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
