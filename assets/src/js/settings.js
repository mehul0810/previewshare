( function ( wp ) {
	const { createElement, render, useState, useEffect } = wp.element;
	const { Button, ToggleControl, TextControl, PanelBody, TabPanel, Spinner } = wp.components;

	const restBase = window.previewshare_settings && window.previewshare_settings.rest_url ? window.previewshare_settings.rest_url.replace( /\/$/, '' ) : '/wp-json/previewshare/v1';
	const nonce = window.previewshare_settings && window.previewshare_settings.nonce ? window.previewshare_settings.nonce : '';

	function apiFetch( path, options = {} ) {
		const headers = Object.assign( {
			'Content-Type': 'application/json',
			'X-WP-Nonce': nonce,
		}, options.headers || {} );

		const opts = Object.assign( { headers: headers }, options );

		if ( opts.body && typeof opts.body !== 'string' ) {
			opts.body = JSON.stringify( opts.body );
		}

		return fetch( restBase + path, opts ).then( res => {
			if ( ! res.ok ) {
				return res.json().then( j => Promise.reject( j ) );
			}
			return res.json();
		} );
	}

	function TabContent() {
		const [ tokens, setTokens ] = useState( [] );
		const [ loadingTokens, setLoadingTokens ] = useState( false );
		const [ totalTokens, setTotalTokens ] = useState( 0 );
		const [ page, setPage ] = useState( 1 );
		const [ perPage ] = useState( 50 );

		const [ settings, setSettings ] = useState( { default_ttl_hours: 24, enable_logging: false, enable_caching: true } );
		const [ settingsLoading, setSettingsLoading ] = useState( true );
		const [ savingSettings, setSavingSettings ] = useState( false );

		useEffect( () => {
			fetchSettings();
			fetchTokens( page );
		}, [] );

		function fetchTokens( p = 1 ) {
			setLoadingTokens( true );
			apiFetch( `/tokens?per_page=${ perPage }&page=${ p }`, { method: 'GET' } )
				.then( data => {
					setTokens( data.items || [] );
					setTotalTokens( data.total || 0 );
					setLoadingTokens( false );
				} )
				.catch( () => setLoadingTokens( false ) );
		}

		function fetchSettings() {
			setSettingsLoading( true );
			apiFetch( '/settings', { method: 'GET' } )
				.then( data => {
					setSettings( data );
					setSettingsLoading( false );
				} )
				.catch( () => setSettingsLoading( false ) );
		}

		function saveSettings( values ) {
			setSavingSettings( true );
			apiFetch( '/settings', { method: 'POST', body: values } )
				.then( ( data ) => {
					setSavingSettings( false );
					// Update local settings with returned data and notify user.
					setSettings( data );
					if ( window.wp && window.wp.data && window.wp.data.dispatch ) {
						window.wp.data.dispatch( 'core/notices' ).createNotice( 'success', 'Settings saved.', { isDismissible: true } );
					} else {
						// Fallback alert
						try { alert( 'Settings saved.' ); } catch (e) {}
					}
				} )
				.catch( ( err ) => {
					setSavingSettings( false );
					if ( window.wp && window.wp.data && window.wp.data.dispatch ) {
						window.wp.data.dispatch( 'core/notices' ).createNotice( 'error', 'Unable to save settings.', { isDismissible: true } );
					} else {
						try { alert( 'Unable to save settings.' ); } catch (e) {}
					}
					console.error( 'PreviewShare settings save error', err );
				} );
		}

		function handleCopyPreview( postId ) {
			// Generate a fresh token for the post and copy URL to clipboard.
			apiFetch( '/v2/generate', { method: 'POST', body: { post_id: postId } } )
				.then( data => {
					const url = data.url;
					if ( navigator.clipboard && navigator.clipboard.writeText ) {
						navigator.clipboard.writeText( url );
						alert( 'Preview URL copied to clipboard' );
					} else {
						prompt( 'Copy this URL', url );
					}
				} )
				.catch( ( err ) => {
					console.error( err );
					alert( 'Could not generate preview URL' );
				} );
		}

		function renderPreviews() {
			if ( loadingTokens ) {
				return createElement( Spinner, {} );
			}

			if ( tokens.length === 0 ) {
				return createElement( 'p', null, 'No preview tokens found.' );
			}

			return createElement( 'div', null,
				createElement( 'table', { className: 'previewshare-table widefat striped' },
				createElement( 'thead', null,
					createElement( 'tr', null,
						createElement( 'th', null, 'Post Title' ),
						createElement( 'th', null, 'Actions' )
					)
				),
				createElement( 'tbody', null, tokens.map( t => {
					const editUrl = `/wp-admin/post.php?post=${ t.post_id }&action=edit`;
					return createElement( 'tr', { key: t.id },
						createElement( 'td', null, t.post_title ),
						createElement( 'td', null,
								createElement( Button, { isSecondary: true, onClick: () => handleCopyPreview( t.post_id ) }, 'Copy Preview URL' ),
								' ',
								createElement( Button, { isDestructive: true, isSecondary: true, onClick: () => handleRevoke( t.id ) }, 'Revoke' ),
								' ',
								createElement( 'a', { href: editUrl }, 'Edit Post' )
							)
					);
				} ) )
				),
				// Pagination controls
				createElement( 'div', { style: { marginTop: '12px' } },
					createElement( Button, { isSecondary: true, disabled: page <= 1, onClick: () => { const p = Math.max(1, page-1); setPage(p); fetchTokens(p); } }, 'Previous' ),
					' ',
					createElement( 'span', { style: { margin: '0 8px' } }, `Page ${ page } of ${ Math.max(1, Math.ceil( totalTokens / perPage )) }` ),
					' ',
					createElement( Button, { isSecondary: true, disabled: page >= Math.ceil( totalTokens / perPage ), onClick: () => { const p = page+1; setPage(p); fetchTokens(p); } }, 'Next' )
				)
			);
		}

		function handleRevoke( id ) {
			if ( ! confirm( 'Revoke this preview link? This cannot be undone.' ) ) {
				return;
			}

			apiFetch( '/tokens/revoke', { method: 'POST', body: { id: id } } )
				.then( data => {
					if ( settings.enable_logging ) {
						console.log( 'PreviewShare: revoked token id=', id, data );
					}
					fetchTokens( page );
				} )
				.catch( err => {
					console.error( err );
					alert( 'Could not revoke token' );
				} );
		}

		function renderGeneral() {
			if ( settingsLoading ) {
				return createElement( Spinner, {} );
			}

			return createElement( PanelBody, { title: 'General Settings', initialOpen: true },
				createElement( TextControl, {
					label: 'Default expiry (hours)',
					type: 'number',
					value: settings.default_ttl_hours,
					onChange: ( val ) => setSettings( Object.assign( {}, settings, { default_ttl_hours: parseInt( val, 10 ) || 0 } ) ),
				} ),
				settings.default_ttl_hours > 24 ? createElement( 'div', { style: { color: '#b91d47', marginTop: '8px' } }, 'Warning: settings more than 24 hours may present a content security risk.' ) : null,
				createElement( Button, { isPrimary: true, isBusy: savingSettings, onClick: () => saveSettings( { default_ttl_hours: settings.default_ttl_hours } ) }, 'Save' )
			);
		}

		function renderAdvanced() {
			if ( settingsLoading ) {
				return createElement( Spinner, {} );
			}

			return createElement( PanelBody, { title: 'Advanced', initialOpen: true },
				createElement( ToggleControl, {
					label: 'Enable logging',
					checked: settings.enable_logging,
					onChange: ( val ) => {
						setSettings( Object.assign( {}, settings, { enable_logging: val } ) );
						saveSettings( { enable_logging: val } );
					},
				} ),
				createElement( ToggleControl, {
					label: 'Enable caching (recommended)',
					checked: settings.enable_caching,
					onChange: ( val ) => {
						setSettings( Object.assign( {}, settings, { enable_caching: val } ) );
						saveSettings( { enable_caching: val } );
					},
				} ),
				createElement( 'p', null, 'When caching is enabled, the plugin uses the object cache for faster token lookups. Cache entries are flushed automatically when a post is updated.' )
			);
		}

		return createElement( 'div', { className: 'previewshare-settings' },
			createElement( 'h1', null, 'PreviewShare Settings' ),
			createElement( TabPanel, {
				className: 'previewshare-tabs',
				activeClass: 'is-active',
				tabs: [
					{ name: 'previews', title: 'Previews', content: 'Previews' },
					{ name: 'general', title: 'General', content: 'General' },
					{ name: 'advanced', title: 'Advanced', content: 'Advanced' },
				],
			}, function( tab ) {
				if ( tab.name === 'previews' ) {
					return renderPreviews();
				}
				if ( tab.name === 'general' ) {
					return renderGeneral();
				}
				return renderAdvanced();
			} )
		);
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		const mount = document.getElementById( 'previewshare-settings-app' );
		if ( mount ) {
			render( createElement( TabContent ), mount );
		}
	} );
} )( window.wp );
