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

async function globalSetup( config ) {
	const { storageState, baseURL } = config.projects[ 0 ].use;
	const storageStatePath =
		typeof storageState === 'string' ? storageState : undefined;
	const requestContext = await request.newContext( { baseURL } );
	const requestUtils = new RequestUtils( requestContext, { baseURL } );

	try {
		const nonce = await requestUtils.login();
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
