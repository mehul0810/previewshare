const path = require( 'path' );
const DependencyExtractionWebpackPlugin = require( '@wordpress/dependency-extraction-webpack-plugin' );
const {
	defaultRequestToExternal,
	defaultRequestToExternalModule,
	defaultRequestToHandle,
} = require( '@wordpress/dependency-extraction-webpack-plugin/lib/util' );

// Reuse the default @wordpress/scripts webpack config and extend it.
// Keep WP Scripts behaviors (Babel, externals, optimizations) while
// adding our custom entries (frontend/admin/settings) and an output
// layout that writes to `assets/dist/js/[name].min.js` so existing
// enqueue paths continue to work.
const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );
const wpPot = require( 'wp-pot' );

const inProduction = 'production' === process.env.NODE_ENV;
const mode = inProduction ? 'production' : 'development';

const config = {
	...defaultConfig,
	mode,
	entry: {
		...defaultConfig.entry,
		previewshare: [
			'./assets/src/js/frontend/main.js',
			'./assets/src/css/frontend/main.css',
		],
		'previewshare-admin': [
			'./assets/src/js/admin/main.js',
			'./assets/src/css/admin/main.css',
		],
		// Settings React app we added - builds to assets/dist/js/previewshare-settings.min.js
		'previewshare-settings': [
			'./assets/src/js/settings.js',
			'./assets/src/css/admin/settings.css',
		],
	},
	output: {
		...defaultConfig.output,
		path: path.join( __dirname, 'assets/dist/' ),
		filename: 'js/[name].min.js',
	},
	module: {
		...defaultConfig.module,
		rules: [ ...defaultConfig.module.rules ],
	},
	plugins: [
		...defaultConfig.plugins.filter(
			( plugin ) =>
				plugin.constructor.name !== 'DependencyExtractionWebpackPlugin'
		),
		new DependencyExtractionWebpackPlugin( {
			// WordPress 5.8 does not register react-jsx-runtime. Bundle it so the
			// settings app stays compatible with the declared minimum version.
			useDefaults: false,
			requestToExternal( request ) {
				if (
					request === 'react/jsx-runtime' ||
					request === 'react/jsx-dev-runtime'
				) {
					return undefined;
				}

				return defaultRequestToExternal( request );
			},
			requestToExternalModule: defaultRequestToExternalModule,
			requestToHandle: defaultRequestToHandle,
		} ),
	],
};

if ( inProduction && 'true' === process.env.PREVIEWSHARE_GENERATE_PHP_POT ) {
	// This generates PHP strings only. Keep it opt-in because the checked-in POT
	// also contains JavaScript strings needed by the settings interface.
	wpPot( {
		package: 'PreviewShare',
		domain: 'previewshare',
		destFile: 'languages/previewshare.pot',
		relativeTo: './',
		src: [ './**/*.php', '!./includes/libraries/**/*', '!./vendor/**/*' ],
		bugReport: 'https://github.com/mehul0810/previewshare/issues/new',
		team: 'Mehul Gohil <hello@mehulgohil.com>',
	} );
}

module.exports = config;
