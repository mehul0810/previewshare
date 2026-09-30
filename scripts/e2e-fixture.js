const { execFileSync } = require( 'child_process' );

const FIXTURE_BASE_URL = 'http://localhost:8889';
const FIXTURE_WP_CLI = 'wp-env run cli wp';
const TARGET_OVERRIDE_VARIABLES = [
	'WP_BASE_URL',
	'PREVIEWSHARE_E2E_BASE_URL',
	'PREVIEWSHARE_E2E_WP_CLI',
];

function assertNoTargetOverrides( environment = process.env ) {
	const overrides = TARGET_OVERRIDE_VARIABLES.filter(
		( name ) => environment[ name ]
	);

	if ( overrides.length ) {
		throw new Error(
			`PreviewShare e2e only runs in its owned local wp-env fixture; refusing ${ overrides.join(
				', '
			) } override.`
		);
	}
}

function assertFixtureConfiguration( environment = process.env ) {
	const expected = {
		WP_BASE_URL: FIXTURE_BASE_URL,
		PREVIEWSHARE_E2E_BASE_URL: FIXTURE_BASE_URL,
		PREVIEWSHARE_E2E_WP_CLI: FIXTURE_WP_CLI,
	};

	for ( const [ name, value ] of Object.entries( expected ) ) {
		if ( environment[ name ] !== value ) {
			throw new Error(
				`PreviewShare e2e requires ${ name }=${ value } for its owned wp-env fixture.`
			);
		}
	}
}

function assertFixtureHomeUrl( value ) {
	const expected = new URL( FIXTURE_BASE_URL );
	const actual = new URL( value );

	if (
		actual.origin !== expected.origin ||
		actual.pathname !== '/' ||
		actual.search ||
		actual.hash
	) {
		throw new Error(
			`PreviewShare e2e CLI is not bound to ${ FIXTURE_BASE_URL }: ${ value }`
		);
	}
}

function assertFixtureCliBinding( execute = execFileSync ) {
	const output = execute(
		'wp-env',
		[ 'run', 'cli', 'wp', 'option', 'get', 'home', '--format=json' ],
		{ encoding: 'utf8' }
	);

	assertFixtureHomeUrl( JSON.parse( output ) );
}

function runCli() {
	const [ command, value ] = process.argv.slice( 2 );

	if ( command === 'assert-no-target-overrides' ) {
		assertNoTargetOverrides();
		return;
	}

	if ( command === 'assert-home-url' && value ) {
		assertFixtureHomeUrl( value );
		return;
	}

	if ( command === 'assert-cli-binding' ) {
		assertFixtureCliBinding();
		return;
	}

	throw new Error(
		'Usage: e2e-fixture.js <assert-no-target-overrides|assert-home-url|assert-cli-binding>'
	);
}

if ( require.main === module ) {
	try {
		runCli();
	} catch ( error ) {
		console.error( error.message );
		process.exitCode = 1;
	}
}

module.exports = {
	FIXTURE_BASE_URL,
	FIXTURE_WP_CLI,
	assertFixtureConfiguration,
	assertFixtureCliBinding,
	assertFixtureHomeUrl,
	assertNoTargetOverrides,
};
