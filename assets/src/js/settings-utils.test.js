import {
	fallbackSettings,
	getRestBase,
	normalizeSettings,
} from './settings-utils';

describe( 'PreviewShare settings utils', () => {
	it( 'fills missing settings from defaults and available post types', () => {
		expect(
			normalizeSettings( {
				available_post_types: {
					post: 'Post',
					page: 'Page',
				},
				defaults: {},
			} )
		).toEqual( {
			...fallbackSettings,
			post_types: [],
			available_post_types: {
				post: 'Post',
				page: 'Page',
			},
			defaults: fallbackSettings.defaults,
		} );
	} );

	it( 'normalizes saved settings while preserving selected post types', () => {
		expect(
			normalizeSettings( {
				default_ttl_hours: '24',
				enable_logging: true,
				enable_caching: false,
				post_types: [ 'post' ],
				available_post_types: {
					post: 'Post',
					page: 'Page',
				},
			} )
		).toMatchObject( {
			default_ttl_hours: 24,
			enable_logging: true,
			enable_caching: false,
			post_types: [ 'post' ],
		} );
	} );

	it( 'normalizes localized REST base URLs', () => {
		expect(
			getRestBase( {
				rest_url: 'https://example.test/wp-json/previewshare/v1/',
			} )
		).toBe( 'https://example.test/wp-json/previewshare/v1' );
		expect( getRestBase( {} ) ).toBe( '/wp-json/previewshare/v1' );
	} );
} );
