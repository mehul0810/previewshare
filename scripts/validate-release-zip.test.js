const { execFileSync, spawnSync } = require( 'child_process' );
const { mkdtempSync, mkdirSync, rmSync, writeFileSync } = require( 'fs' );
const { tmpdir } = require( 'os' );
const { join } = require( 'path' );

const requiredPaths = [
	'previewshare.php',
	'composer.json',
	'readme.txt',
	'license.txt',
	'config/constants.php',
	'src/Plugin.php',
	'assets/dist/js/previewshare-admin.min.js',
	'assets/dist/js/previewshare-settings.min.js',
	'assets/dist/js/previewshare-settings.min.asset.php',
	'assets/dist/js/previewshare.min.js',
	'languages/previewshare.pot',
	'vendor/autoload.php',
];
const defaultPotContent = [
	'msgid "Settings saved."',
	'msgid "Access extended by 24 hours. New expiry: %s."',
	'',
].join( '\n' );
const defaultSettingsAssetContent =
	"<?php return array('dependencies' => array('react', 'react-dom'), 'version' => 'test');";

function writeFixtureZip( extraFiles, potContent, settingsAssetContent ) {
	const tempDirectory = mkdtempSync(
		join( tmpdir(), 'previewshare-zip-test-' )
	);
	const pluginDirectory = join( tempDirectory, 'previewshare' );

	for ( const file of [ ...requiredPaths, ...extraFiles ] ) {
		const path = join( pluginDirectory, file );
		let content = '';

		if ( file === 'languages/previewshare.pot' ) {
			content = potContent ?? defaultPotContent;
		} else if (
			file === 'assets/dist/js/previewshare-settings.min.asset.php'
		) {
			content = settingsAssetContent ?? defaultSettingsAssetContent;
		}

		mkdirSync( join( path, '..' ), { recursive: true } );
		writeFileSync( path, content );
	}

	const zipPath = join( tempDirectory, 'previewshare.zip' );
	execFileSync( 'zip', [ '-qr', zipPath, 'previewshare' ], {
		cwd: tempDirectory,
	} );

	return { tempDirectory, zipPath };
}

describe( 'release ZIP validation', () => {
	test( 'requires the complete settings translation template', () => {
		const { tempDirectory, zipPath } = writeFixtureZip( [], '' );

		try {
			const result = spawnSync(
				'bash',
				[ 'scripts/validate-release-zip.sh', zipPath ],
				{
					cwd: process.cwd(),
					encoding: 'utf8',
				}
			);

			expect( result.status ).toBe( 1 );
			expect( result.stderr ).toContain(
				'Release zip translation template is missing:'
			);
		} finally {
			rmSync( tempDirectory, { recursive: true, force: true } );
		}
	} );

	test( 'rejects script dependencies missing from WordPress 5.8', () => {
		const { tempDirectory, zipPath } = writeFixtureZip(
			[],
			undefined,
			"<?php return array('dependencies' => array('react-jsx-runtime'));"
		);

		try {
			const result = spawnSync(
				'bash',
				[ 'scripts/validate-release-zip.sh', zipPath ],
				{
					cwd: process.cwd(),
					encoding: 'utf8',
				}
			);

			expect( result.status ).toBe( 1 );
			expect( result.stderr ).toContain(
				'Settings bundle requires react-jsx-runtime'
			);
		} finally {
			rmSync( tempDirectory, { recursive: true, force: true } );
		}
	} );

	test( 'rejects wp-env configuration and override files', () => {
		const { tempDirectory, zipPath } = writeFixtureZip( [
			'.wp-env.json',
			'.wp-env.override.json',
		] );

		try {
			const result = spawnSync(
				'bash',
				[ 'scripts/validate-release-zip.sh', zipPath ],
				{
					cwd: process.cwd(),
					encoding: 'utf8',
				}
			);

			expect( result.status ).toBe( 1 );
			expect( result.stderr ).toContain(
				'Release zip contains development-only files.'
			);
		} finally {
			rmSync( tempDirectory, { recursive: true, force: true } );
		}
	} );
} );
