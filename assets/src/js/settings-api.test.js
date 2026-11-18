import { createSettingsApi } from './settings-api';

describe( 'settings API client', () => {
	it( 'builds authenticated requests with encoded body and REST query routes', async () => {
		const request = jest.fn().mockResolvedValue( {
			ok: true,
			json: async () => ( { saved: true } ),
		} );
		const api = createSettingsApi( {
			restBase: '/index.php?rest_route=/previewshare/v1',
			nonce: 'test-nonce',
			origin: 'https://example.test',
			request,
		} );
		await expect(
			api( '/settings?context=edit', {
				method: 'POST',
				body: { enable_logging: true },
			} )
		).resolves.toEqual( { saved: true } );
		const [ url, options ] = request.mock.calls[ 0 ];
		expect( new URL( url ).searchParams.get( 'rest_route' ) ).toBe(
			'/previewshare/v1/settings'
		);
		expect( new URL( url ).searchParams.get( 'context' ) ).toBe( 'edit' );
		expect( options.headers[ 'X-WP-Nonce' ] ).toBe( 'test-nonce' );
		expect( JSON.parse( options.body ) ).toEqual( {
			enable_logging: true,
		} );
	} );

	it( 'preserves structured server errors for caller-specific notices', async () => {
		const error = { code: 'previewshare_inventory_changed' };
		const api = createSettingsApi( {
			restBase: '/wp-json/previewshare/v1',
			nonce: 'test-nonce',
			origin: 'https://example.test',
			request: jest.fn().mockResolvedValue( {
				ok: false,
				json: async () => error,
			} ),
		} );
		await expect( api( '/tokens', { method: 'GET' } ) ).rejects.toEqual(
			error
		);
	} );
} );
