import { createElement as el, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

export function useScopedNotices() {
	const [ notices, setNotices ] = useState( {} );
	function notify( status, message, url = '', scope = 'inventory' ) {
		setNotices( ( current ) => ( {
			...current,
			[ scope ]: { status, message, url },
		} ) );
	}
	function clearNotice( scope ) {
		setNotices( ( current ) => {
			if ( ! current[ scope ] ) {
				return current;
			}
			const next = { ...current };
			delete next[ scope ];
			return next;
		} );
	}
	return { notices, notify, clearNotice };
}

export function NoticeRegion( { notices, clearNotice, Notice } ) {
	const entries = Object.entries( notices );
	if ( ! entries.length ) {
		return null;
	}
	return el(
		'div',
		{
			className: 'previewshare-notice-region',
			role: 'region',
			'aria-label': __( 'PreviewShare notices', 'previewshare' ),
		},
		entries.map( ( [ scope, notice ] ) =>
			el(
				Notice,
				{
					key: scope,
					className: 'previewshare-app-notice',
					status: notice.status,
					isDismissible: true,
					onRemove: () => clearNotice( scope ),
				},
				el( 'p', null, notice.message ),
				notice.url
					? el( 'input', {
							className: 'previewshare-copy-field code',
							readOnly: true,
							type: 'url',
							value: notice.url,
							onFocus: ( event ) => event.target.select(),
					  } )
					: null
			)
		)
	);
}
