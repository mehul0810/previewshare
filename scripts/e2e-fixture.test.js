const {
	FIXTURE_BASE_URL,
	FIXTURE_WP_CLI,
	assertFixtureCliBinding,
	assertFixtureConfiguration,
	assertFixtureHomeUrl,
	assertNoTargetOverrides,
} = require( './e2e-fixture' );

function fixtureEnvironment() {
	return {
		WP_BASE_URL: FIXTURE_BASE_URL,
		PREVIEWSHARE_E2E_BASE_URL: FIXTURE_BASE_URL,
		PREVIEWSHARE_E2E_WP_CLI: FIXTURE_WP_CLI,
	};
}

describe( 'e2e fixture ownership', () => {
	test( 'rejects caller-supplied browser or CLI targets', () => {
		expect( () =>
			assertNoTargetOverrides( {
				WP_BASE_URL: 'https://owner-site.invalid',
			} )
		).toThrow( 'owned local wp-env fixture' );
		expect( () =>
			assertNoTargetOverrides( {
				PREVIEWSHARE_E2E_BASE_URL: 'http://localhost:9999',
			} )
		).toThrow( 'PREVIEWSHARE_E2E_BASE_URL' );
		expect( () =>
			assertNoTargetOverrides( {
				PREVIEWSHARE_E2E_WP_CLI: 'wp --path=/shared-site',
			} )
		).toThrow( 'PREVIEWSHARE_E2E_WP_CLI' );
	} );

	test( 'requires browser and CLI configuration for the same fixture', () => {
		expect( () => assertFixtureConfiguration( fixtureEnvironment() ) ).not.toThrow();
		expect( () =>
			assertFixtureConfiguration( {
				...fixtureEnvironment(),
				PREVIEWSHARE_E2E_WP_CLI: 'wp-env run cli wp --url=https://owner-site.invalid',
			} )
		).toThrow( 'PREVIEWSHARE_E2E_WP_CLI' );
	} );

	test( 'rejects a CLI fixture URL that differs from the browser fixture', () => {
		expect( () => assertFixtureHomeUrl( FIXTURE_BASE_URL ) ).not.toThrow();
		expect( () =>
			assertFixtureHomeUrl( 'https://owner-site.invalid' )
		).toThrow( 'not bound' );
	} );

	test( 'requires the wp-env CLI to report the browser fixture URL', () => {
		expect( () =>
			assertFixtureCliBinding( () => JSON.stringify( FIXTURE_BASE_URL ) )
		).not.toThrow();
		expect( () =>
			assertFixtureCliBinding( () => JSON.stringify( 'https://owner-site.invalid' ) )
		).toThrow( 'not bound' );
	} );
} );
