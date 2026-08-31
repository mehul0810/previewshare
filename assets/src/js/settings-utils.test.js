import {
	fallbackSettings,
	getRestBase,
	getInventoryBatchState,
	mergeInventoryItems,
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

	it( 'keeps a continuation after the first 1,000 inventory items', () => {
		expect(
			getInventoryBatchState( {
				loadedCount: 1000,
				pageItemsCount: 100,
				reportedTotal: 1001,
				pagesFetched: 10,
			} )
		).toEqual( { hasMore: true, shouldFetchNextPage: false } );

		expect(
			getInventoryBatchState( {
				existingCount: 1000,
				loadedCount: 1,
				pageItemsCount: 1,
				reportedTotal: 1001,
				pagesFetched: 1,
			} )
		).toEqual( { hasMore: false, shouldFetchNextPage: false } );

		const firstBatch = Array.from( { length: 1000 }, ( _, index ) => ( {
			id: index + 1,
		} ) );
		const merged = mergeInventoryItems( firstBatch, [ { id: 1001 } ] );

		expect( merged ).toHaveLength( 1001 );
		expect( merged[ 1000 ] ).toEqual( { id: 1001 } );
	} );
} );
