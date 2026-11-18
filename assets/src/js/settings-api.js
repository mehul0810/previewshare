import { buildRestRequestUrl } from './settings-utils';

export function createSettingsApi( { restBase, nonce, origin, request } ) {
	return function apiFetch( path, options = {} ) {
		const requestUrl = buildRestRequestUrl( restBase, path, origin );
		const headers = Object.assign(
			{
				'Content-Type': 'application/json',
				'X-WP-Nonce': nonce,
			},
			options.headers || {}
		);
		const requestOptions = Object.assign( { headers }, options );

		if ( requestOptions.body && typeof requestOptions.body !== 'string' ) {
			requestOptions.body = JSON.stringify( requestOptions.body );
		}

		return request( requestUrl, requestOptions ).then( ( response ) => {
			if ( ! response.ok ) {
				return response
					.json()
					.then( ( error ) => Promise.reject( error ) );
			}

			return response.json();
		} );
	};
}
