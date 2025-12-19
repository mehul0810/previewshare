const path = require('path');
const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );
// const CopyWebpackPlugin = require( 'copy-webpack-plugin' );
const ImageminPlugin = require( 'imagemin-webpack-plugin' ).default;
// const { CleanWebpackPlugin } = require( 'clean-webpack-plugin' );
// const MiniCSSExtractPlugin = require( 'mini-css-extract-plugin' );
const wpPot = require( 'wp-pot' );

const inProduction = ( 'production' === process.env.NODE_ENV );
const mode = inProduction ? 'production' : 'development';

const config = {
	...defaultConfig,
	mode,
	entry: {
		...defaultConfig.entry,
		"previewshare": [ './assets/src/js/frontend/main.js', './assets/src/css/frontend/main.css' ],
		"previewshare-admin": [ './assets/src/js/admin/main.js', './assets/src/css/admin/main.css' ],
	},
	output: {
		...defaultConfig.output,
		path: path.join(__dirname, 'assets/dist/'),
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
	// Must go after CopyWebpackPlugin above: https://github.com/Klathmon/imagemin-webpack-plugin#example-usage
	config.plugins.push( new ImageminPlugin( { test: /\.(jpe?g|png|gif|svg)$/i } ) );

	// POT file.
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
