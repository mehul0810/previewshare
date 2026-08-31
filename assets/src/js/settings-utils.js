export const fallbackSettings = {
	default_ttl_hours: 6,
	enable_logging: false,
	enable_caching: true,
	post_types: [],
	available_post_types: {},
	defaults: {
		default_ttl_hours: 6,
		enable_logging: false,
		enable_caching: true,
		post_types: [],
	},
};

export const INVENTORY_PAGE_SIZE = 100;
export const INVENTORY_BATCH_PAGES = 10;

export function getInventoryBatchState( {
	existingCount = 0,
	loadedCount = 0,
	pageItemsCount = 0,
	reportedTotal = 0,
	pagesFetched = 0,
	pageSize = INVENTORY_PAGE_SIZE,
	batchPages = INVENTORY_BATCH_PAGES,
} = {} ) {
	const totalReached =
		reportedTotal > 0 && existingCount + loadedCount >= reportedTotal;
	const hasMore = pageItemsCount >= pageSize && ! totalReached;

	return {
		hasMore,
		shouldFetchNextPage: hasMore && pagesFetched < batchPages,
	};
}

export function mergeInventoryItems( existingItems = [], nextItems = [] ) {
	const items = new Map();

	existingItems.concat( nextItems ).forEach( ( item ) => {
		if ( item && item.id !== undefined && item.id !== null ) {
			items.set( item.id, item );
		}
	} );

	return Array.from( items.values() );
}

export function normalizeSettings( settings = {} ) {
	const availablePostTypes = settings.available_post_types || {};
	const defaults = Object.assign(
		{},
		fallbackSettings.defaults,
		settings.defaults || {}
	);
	const defaultPostTypes = Array.isArray( defaults.post_types )
		? defaults.post_types
		: Object.keys( availablePostTypes );
	const selectedPostTypes = Array.isArray( settings.post_types )
		? settings.post_types
		: defaultPostTypes;

	return {
		default_ttl_hours: parseInt(
			settings.default_ttl_hours ?? fallbackSettings.default_ttl_hours,
			10
		),
		enable_logging:
			settings.enable_logging ?? fallbackSettings.enable_logging,
		enable_caching:
			settings.enable_caching ?? fallbackSettings.enable_caching,
		post_types: selectedPostTypes,
		available_post_types: availablePostTypes,
		defaults,
	};
}

export function getRestBase( localized = {} ) {
	return localized.rest_url
		? localized.rest_url.replace( /\/$/, '' )
		: '/wp-json/previewshare/v1';
}
