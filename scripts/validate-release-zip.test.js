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
	'assets/dist/js/previewshare.min.js',
	'vendor/autoload.php',
];

function writeFixtureZip( extraFiles ) {
	const tempDirectory = mkdtempSync( join( tmpdir(), 'previewshare-zip-test-' ) );
	const pluginDirectory = join( tempDirectory, 'previewshare' );

	for ( const file of [ ...requiredPaths, ...extraFiles ] ) {
		const path = join( pluginDirectory, file );
		mkdirSync( join( path, '..' ), { recursive: true } );
		writeFileSync( path, '' );
	}

	const zipPath = join( tempDirectory, 'previewshare.zip' );
	execFileSync( 'zip', [ '-qr', zipPath, 'previewshare' ], {
		cwd: tempDirectory,
	} );

	return { tempDirectory, zipPath };
}

describe( 'release ZIP validation', () => {
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
