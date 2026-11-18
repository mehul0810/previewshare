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
	resolve: {
		...defaultConfig.resolve,
		alias: {
			...defaultConfig.resolve.alias,
			// WordPress 5.8 provides createElement and Fragment through wp-element,
			// but does not register the newer react-jsx-runtime script handle.
			// Adapt JSX calls to that stable API instead of bundling React's runtime.
			'react/jsx-runtime$': path.join(
				__dirname,
				'assets/src/js/wp-jsx-runtime.js'
			),
			'react/jsx-dev-runtime$': path.join(
				__dirname,
				'assets/src/js/wp-jsx-runtime.js'
			),
		},
	},
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
		chunkFilename: 'js/[name].[contenthash:8].min.js',
		publicPath: '',
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
			// WordPress 5.8 does not register react-jsx-runtime or wp-primitives.
			// The JSX runtime requests resolve to the wp-element adapter above;
			// bundle primitives to support the declared minimum version.
			useDefaults: false,
			requestToExternal( request ) {
				// DataViews' ESM build uses newer package internals. Bundle those
				// dependencies in its lazy chunk while retaining stable core globals.
				if (
					[
						'@wordpress/private-apis',
						'@wordpress/theme',
						'@wordpress/components',
						'@wordpress/compose',
						'@wordpress/a11y',
						'@wordpress/deprecated',
						'@wordpress/keycodes',
						'@wordpress/is-shallow-equal',
						'@wordpress/warning',
					].includes( request )
				) {
					return undefined;
				}
				if (
					request === 'react/jsx-runtime' ||
					request === 'react/jsx-dev-runtime' ||
					request === '@wordpress/primitives'
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
