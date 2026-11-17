import { fetchInventoryBatch } from './inventory-batch';

const defaults = {
	append: false,
	page: 1,
	cursor: 'start',
	pageSize: 2,
	pageLimit: 1,
	existingCount: 0,
	previousTotal: null,
	isCurrent: () => true,
};

test( 'uses the server continuation and recognizes an equal-total inventory change', async () => {
	const apiFetch = jest
		.fn()
		.mockResolvedValueOnce( {
			items: [ { id: '3' }, { id: '2' } ],
			total: 3,
			next_cursor: 'opaque-2',
		} )
		.mockRejectedValueOnce( { code: 'previewshare_inventory_changed' } );
	const first = await fetchInventoryBatch( { ...defaults, apiFetch } );
	expect( first.nextCursor ).toBe( 'opaque-2' );
	expect( first.hasMore ).toBe( true );
	const next = await fetchInventoryBatch( {
		...defaults,
		apiFetch,
		append: true,
		page: first.nextPage,
		cursor: first.nextCursor,
		previousTotal: first.total,
	} );
	expect( apiFetch.mock.calls[ 1 ][ 0 ] ).toContain( 'cursor=opaque-2' );
	expect( next ).toEqual( { changed: true } );
} );

test( 'stops before starting a superseded page', async () => {
	let current = true;
	const apiFetch = jest.fn( async () => {
		current = false;
		return {
			items: [ { id: '3' }, { id: '2' } ],
			total: 4,
			next_cursor: 'opaque-2',
		};
	} );
	const result = await fetchInventoryBatch( {
		...defaults,
		apiFetch,
		pageLimit: 2,
		isCurrent: () => current,
	} );
	expect( result ).toEqual( { cancelled: true } );
	expect( apiFetch ).toHaveBeenCalledTimes( 1 );
} );

test( 'retains page-based compatibility with an older server response', async () => {
	const apiFetch = jest
		.fn()
		.mockResolvedValueOnce( {
			items: [ { id: '3' }, { id: '2' } ],
			total: 3,
		} )
		.mockResolvedValueOnce( { items: [ { id: '1' } ], total: 3 } );
	const result = await fetchInventoryBatch( {
		...defaults,
		apiFetch,
		pageLimit: 2,
	} );
	expect( result.items.map( ( item ) => item.id ) ).toEqual( [
		'3',
		'2',
		'1',
	] );
	expect( apiFetch.mock.calls[ 1 ][ 0 ] ).toContain( 'page=2' );
	expect( result.hasMore ).toBe( false );
} );
