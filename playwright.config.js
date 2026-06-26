const path = require( 'path' );
const { defineConfig, devices } = require( '@playwright/test' );

const baseURL =
	process.env.PREVIEWSHARE_E2E_BASE_URL ||
	process.env.WP_BASE_URL ||
	'http://localhost:8889';

process.env.WP_BASE_URL = baseURL;
process.env.WP_ARTIFACTS_PATH ||= path.join( process.cwd(), 'artifacts' );
process.env.STORAGE_STATE_PATH ||= path.join(
	process.env.WP_ARTIFACTS_PATH,
	'storage-states/admin.json'
);

module.exports = defineConfig( {
	testDir: './tests/e2e',
	testMatch: /.*\.spec\.js/,
	timeout: 90 * 1000,
	expect: {
		timeout: 15 * 1000,
	},
	fullyParallel: false,
	workers: 1,
	retries: process.env.CI ? 1 : 0,
	reporter: process.env.CI ? [ [ 'github' ], [ 'html', { open: 'never' } ] ] : 'list',
	globalSetup: require.resolve(
		'@wordpress/scripts/config/playwright/global-setup.js'
	),
	outputDir: path.join( process.env.WP_ARTIFACTS_PATH, 'test-results' ),
	use: {
		baseURL,
		storageState: process.env.STORAGE_STATE_PATH,
		trace: 'retain-on-failure',
		screenshot: 'only-on-failure',
		video: 'retain-on-failure',
		ignoreHTTPSErrors: true,
		viewport: {
			width: 1280,
			height: 900,
		},
	},
	projects: [
		{
			name: 'chromium',
			use: {
				...devices[ 'Desktop Chrome' ],
			},
		},
	],
} );
