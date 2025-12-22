const path = require( 'path' );

// Reuse the default @wordpress/scripts webpack config and extend it.
// Keep WP Scripts behaviors (Babel, externals, optimizations) while
// adding our custom entries (frontend/admin/settings) and an output
// layout that writes to `assets/dist/js/[name].min.js` so existing
// enqueue paths continue to work.
const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );
const ImageminPlugin = require( 'imagemin-webpack-plugin' ).default;
const wpPot = require( 'wp-pot' );

const inProduction = ( 'production' === process.env.NODE_ENV );
const mode = inProduction ? 'production' : 'development';

const config = {
	...defaultConfig,
	mode,
	entry: {
		...defaultConfig.entry,
		previewshare: [ './assets/src/js/frontend/main.js', './assets/src/css/frontend/main.css' ],
		'previewshare-admin': [ './assets/src/js/admin/main.js', './assets/src/css/admin/main.css' ],
		// Settings React app we added - builds to assets/dist/js/previewshare-settings.min.js
		'previewshare-settings': [ './assets/src/js/settings.js' ],
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
	plugins: [ ...defaultConfig.plugins ],
};

if ( inProduction ) {
	// Minify images.
	config.plugins.push( new ImageminPlugin( { test: /\.(jpe?g|png|gif|svg)$/i } ) );

	// POT file generation for translations.
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
