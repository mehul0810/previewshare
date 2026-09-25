const fs = require( 'fs/promises' );
const { execFileSync } = require( 'child_process' );
const { dirname } = require( 'path' );
const { chromium } = require( '@playwright/test' );

function getAdminCookies( baseURL ) {
	const username = process.env.WP_USERNAME || 'admin';
	const php = `
$user = get_user_by( 'login', ${ JSON.stringify( username ) } );
if ( ! $user ) {
	fwrite( STDERR, 'PreviewShare E2E admin user was not found.' );
	exit( 1 );
}
$expiration = time() + HOUR_IN_SECONDS;
$token = WP_Session_Tokens::get_instance( $user->ID )->create( $expiration );
echo wp_json_encode(
	[
		[ 'name' => AUTH_COOKIE, 'value' => wp_generate_auth_cookie( $user->ID, $expiration, 'auth', $token ) ],
		[ 'name' => SECURE_AUTH_COOKIE, 'value' => wp_generate_auth_cookie( $user->ID, $expiration, 'secure_auth', $token ) ],
		[ 'name' => LOGGED_IN_COOKIE, 'value' => wp_generate_auth_cookie( $user->ID, $expiration, 'logged_in', $token ) ],
	]
);
`;
	const output = execFileSync(
		'wp-env',
		[ 'run', 'cli', 'wp', 'eval', php ],
		{ encoding: 'utf8', env: { ...process.env, NO_COLOR: '1' } }
	).trim();
	const jsonLine = output
		.split( /\r?\n/ )
		.reverse()
		.find( ( line ) => {
			try {
				return Array.isArray( JSON.parse( line ) );
			} catch {
				return false;
			}
		} );
	if ( ! jsonLine ) {
		throw new Error(
			'WP-CLI did not return the PreviewShare E2E authentication cookies.'
		);
	}

	let cookies;
	try {
		cookies = JSON.parse( jsonLine );
	} catch {
		throw new Error(
			'WP-CLI returned invalid PreviewShare E2E authentication cookies.'
		);
	}
	if (
		! Array.isArray( cookies ) ||
		cookies.length !== 3 ||
		! cookies.every(
			( cookie ) =>
				typeof cookie.name === 'string' &&
				typeof cookie.value === 'string' &&
				cookie.value.length > 0
		)
	) {
		throw new Error(
			'WP-CLI returned an incomplete PreviewShare E2E authentication cookie set.'
		);
	}

	return cookies.map( ( cookie ) => ( { ...cookie, url: baseURL } ) );
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

async function verifyAdminSession( page, baseURL ) {
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
		await browserContext.addCookies( getAdminCookies( baseURL ) );
		const page = await browserContext.newPage();
		await verifyAdminSession( page, baseURL );
		const nonce = await getRestNonceFromAdminPage( page, baseURL );
		const state = await browserContext.storageState();
		if ( storageStatePath ) {
			await fs.mkdir( dirname( storageStatePath ), { recursive: true } );
			await fs.writeFile(
				storageStatePath,
				JSON.stringify( { ...state, nonce } ),
				'utf-8'
			);
		}
	} finally {
		await browserContext.close();
		await browser.close();
	}
}

module.exports = globalSetup;
