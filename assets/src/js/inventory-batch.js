import { getInventoryBatchState } from './settings-utils';

/**
 * Fetch a bounded batch, preferring the server's stable continuation.
 * @param {Object}   root0               Batch options.
 * @param {Function} root0.apiFetch      API request function.
 * @param {boolean}  root0.append        Whether to append to existing items.
 * @param {number}   root0.page          Initial page number.
 * @param {string}   root0.cursor        Initial continuation.
 * @param {number}   root0.pageSize      Maximum items per request.
 * @param {number}   root0.pageLimit     Maximum requests per batch.
 * @param {number}   root0.existingCount Number of previously loaded items.
 * @param {?number}  root0.previousTotal Previous inventory total.
 * @param {Function} root0.isCurrent     Whether the request is still current.
 */
export async function fetchInventoryBatch( {
	apiFetch,
	append,
	page,
	cursor,
	pageSize,
	pageLimit,
	existingCount,
	previousTotal,
	isCurrent,
} ) {
	let nextPage = page;
	let nextCursor = cursor;
	let reportedTotal = 0;
	let pagesFetched = 0;
	let hasMore = true;
	const items = [];

	while ( pagesFetched < pageLimit ) {
		if ( ! isCurrent() ) {
			return { cancelled: true };
		}
		let data;
		try {
			data = await apiFetch(
				'/tokens?per_page=' +
					pageSize +
					'&page=' +
					nextPage +
					'&cursor=' +
					encodeURIComponent( nextCursor || 'start' ),
				{ method: 'GET' }
			);
		} catch ( error ) {
			if ( error && error.code === 'previewshare_inventory_changed' ) {
				return { changed: true };
			}
			throw error;
		}
		if ( ! isCurrent() ) {
			return { cancelled: true };
		}
		const pageItems = Array.isArray( data.items ) ? data.items : [];
		const nextTotal = Number( data.total ) || 0;
		if (
			( append &&
				previousTotal !== null &&
				nextTotal !== previousTotal ) ||
			( pagesFetched > 0 && nextTotal !== reportedTotal )
		) {
			return { changed: true };
		}
		reportedTotal = nextTotal;
		items.push( ...pageItems );
		pagesFetched++;
		nextPage++;
		if ( Object.prototype.hasOwnProperty.call( data, 'next_cursor' ) ) {
			if (
				data.next_cursor !== null &&
				( typeof data.next_cursor !== 'string' || ! data.next_cursor )
			) {
				throw new Error( 'Invalid inventory continuation' );
			}
			nextCursor = data.next_cursor;
			hasMore = nextCursor !== null;
		} else {
			// Older servers retain their page-based response during an update.
			nextCursor = 'start';
			hasMore = getInventoryBatchState( {
				existingCount,
				loadedCount: items.length,
				pageItemsCount: pageItems.length,
				reportedTotal,
				pagesFetched,
				pageSize,
				batchPages: pageLimit,
			} ).hasMore;
		}
		if ( ! hasMore ) {
			break;
		}
	}
	return { items, total: reportedTotal, nextPage, nextCursor, hasMore };
}
