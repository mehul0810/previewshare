( function ( wp ) {
	const { createElement: el, render, useEffect, useState } = wp.element;
	const { __, sprintf } = wp.i18n;
	const { Button, Notice, Spinner, TabPanel, TextControl, ToggleControl } =
		wp.components;

	const PER_PAGE = 25;
	const localized = window.previewshare_settings || {};
	const restBase = localized.rest_url
		? localized.rest_url.replace( /\/$/, '' )
		: '/wp-json/previewshare/v1';
	const nonce = localized.nonce || '';
	const fallbackSettings = {
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

	function normalizeSettings( settings = {} ) {
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
				settings.default_ttl_hours ??
					fallbackSettings.default_ttl_hours,
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

	function apiFetch( path, options = {} ) {
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

		return fetch( restBase + path, requestOptions ).then( ( response ) => {
			if ( ! response.ok ) {
				return response
					.json()
					.then( ( error ) => Promise.reject( error ) );
			}

			return response.json();
		} );
	}

	function formatDate( timestamp ) {
		if ( ! timestamp ) {
			return __( 'Never', 'previewshare' );
		}

		return new Date( timestamp * 1000 ).toLocaleString();
	}

	function statusLabel( status ) {
		if ( status === 'active' ) {
			return __( 'Active', 'previewshare' );
		}

		if ( status === 'revoked' ) {
			return __( 'Revoked', 'previewshare' );
		}

		if ( status === 'expired' ) {
			return __( 'Expired', 'previewshare' );
		}

		return __( 'Unknown', 'previewshare' );
	}

	function StatusBadge( { status } ) {
		return el(
			'span',
			{ className: `previewshare-status is-${ status || 'unknown' }` },
			statusLabel( status )
		);
	}

	function SettingsApp() {
		const [ settings, setSettings ] = useState(
			normalizeSettings( localized.settings || fallbackSettings )
		);
		const [ tokens, setTokens ] = useState( [] );
		const [ totalTokens, setTotalTokens ] = useState( 0 );
		const [ page, setPage ] = useState( 1 );
		const [ loadingSettings, setLoadingSettings ] = useState( true );
		const [ loadingTokens, setLoadingTokens ] = useState( true );
		const [ savingSettings, setSavingSettings ] = useState( false );
		const [ workingTokenId, setWorkingTokenId ] = useState( '' );
		const [ notice, setNotice ] = useState( null );

		useEffect( () => {
			fetchSettings();
			fetchTokens( 1 );
			// eslint-disable-next-line react-hooks/exhaustive-deps
		}, [] );

		function notify( status, message, url = '' ) {
			setNotice( { status, message, url } );
		}

		function fetchSettings() {
			setLoadingSettings( true );
			apiFetch( '/settings', { method: 'GET' } )
				.then( ( data ) => {
					setSettings( normalizeSettings( data ) );
					setLoadingSettings( false );
				} )
				.catch( () => {
					setLoadingSettings( false );
					notify(
						'error',
						__( 'Settings could not be loaded.', 'previewshare' )
					);
				} );
		}

		function fetchTokens( nextPage = 1 ) {
			setLoadingTokens( true );
			apiFetch( `/tokens?per_page=${ PER_PAGE }&page=${ nextPage }`, {
				method: 'GET',
			} )
				.then( ( data ) => {
					setTokens( data.items || [] );
					setTotalTokens( data.total || 0 );
					setPage( nextPage );
					setLoadingTokens( false );
				} )
				.catch( () => {
					setLoadingTokens( false );
					notify(
						'error',
						__(
							'Preview links could not be loaded.',
							'previewshare'
						)
					);
				} );
		}

		function updateSetting( key, value ) {
			setSettings(
				normalizeSettings(
					Object.assign( {}, settings, {
						[ key ]: value,
					} )
				)
			);
		}

		function saveSettings( values, successMessage ) {
			setSavingSettings( true );

			return apiFetch( '/settings', {
				method: 'POST',
				body: values,
			} )
				.then( ( data ) => {
					setSettings( normalizeSettings( data ) );
					setSavingSettings( false );
					notify(
						'success',
						successMessage ||
							__( 'Settings saved.', 'previewshare' )
					);
				} )
				.catch( () => {
					setSavingSettings( false );
					notify(
						'error',
						__( 'Settings could not be saved.', 'previewshare' )
					);
				} );
		}

		function saveAllSettings() {
			return saveSettings( {
				default_ttl_hours: settings.default_ttl_hours,
				enable_logging: settings.enable_logging,
				enable_caching: settings.enable_caching,
				post_types: settings.post_types,
			} );
		}

		function restoreDefaults() {
			return saveSettings(
				{ reset_defaults: true },
				__( 'Default settings restored.', 'previewshare' )
			);
		}

		function handleGenerateAndCopy( postId ) {
			setWorkingTokenId( `post-${ postId }` );

			apiFetch( '/v2/generate', {
				method: 'POST',
				body: { post_id: postId },
			} )
				.then( ( data ) => {
					const url = data.url || '';

					if (
						url &&
						window.navigator.clipboard &&
						window.navigator.clipboard.writeText
					) {
						window.navigator.clipboard.writeText( url );
						notify(
							'success',
							__( 'Preview URL copied.', 'previewshare' )
						);
					} else if ( url ) {
						notify(
							'info',
							__(
								'Preview URL generated. Copy it from the field below.',
								'previewshare'
							),
							url
						);
					}

					fetchTokens( page );
				} )
				.catch( () => {
					notify(
						'error',
						__(
							'Preview URL could not be generated.',
							'previewshare'
						)
					);
				} )
				.finally( () => setWorkingTokenId( '' ) );
		}

		function handleRevoke( id ) {
			setWorkingTokenId( id );

			apiFetch( '/tokens/revoke', {
				method: 'POST',
				body: { id },
			} )
				.then( () => {
					notify(
						'success',
						__( 'Preview link revoked.', 'previewshare' )
					);
					fetchTokens( page );
				} )
				.catch( () => {
					notify(
						'error',
						__(
							'Preview link could not be revoked.',
							'previewshare'
						)
					);
				} )
				.finally( () => setWorkingTokenId( '' ) );
		}

		function renderNotice() {
			if ( ! notice ) {
				return null;
			}

			return el(
				Notice,
				{
					status: notice.status,
					isDismissible: true,
					onRemove: () => setNotice( null ),
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
			);
		}

		function renderSummary() {
			const activeTokens = tokens.filter(
				( token ) => token.status === 'active'
			).length;
			const enabledTypes = settings.post_types.length;
			const ttl = settings.default_ttl_hours;
			const cacheLabel = settings.enable_caching
				? __( 'Enabled', 'previewshare' )
				: __( 'Disabled', 'previewshare' );

			return el(
				'div',
				{ className: 'previewshare-summary' },
				el(
					'div',
					{ className: 'previewshare-summary-item' },
					el( 'span', null, __( 'Active links', 'previewshare' ) ),
					el( 'strong', null, activeTokens )
				),
				el(
					'div',
					{ className: 'previewshare-summary-item' },
					el( 'span', null, __( 'Total links', 'previewshare' ) ),
					el( 'strong', null, totalTokens )
				),
				el(
					'div',
					{ className: 'previewshare-summary-item' },
					el( 'span', null, __( 'Default expiry', 'previewshare' ) ),
					el(
						'strong',
						null,
						ttl > 0
							? sprintf(
									/* translators: %d: Number of hours. */
									__( '%d hours', 'previewshare' ),
									ttl
							  )
							: __( 'Never', 'previewshare' )
					)
				),
				el(
					'div',
					{ className: 'previewshare-summary-item' },
					el( 'span', null, __( 'Content types', 'previewshare' ) ),
					el( 'strong', null, enabledTypes )
				),
				el(
					'div',
					{ className: 'previewshare-summary-item' },
					el( 'span', null, __( 'Token cache', 'previewshare' ) ),
					el( 'strong', null, cacheLabel )
				)
			);
		}

		function renderPreviews() {
			if ( loadingTokens ) {
				return el(
					'div',
					{ className: 'previewshare-loading' },
					el( Spinner )
				);
			}

			if ( tokens.length === 0 ) {
				return el(
					'section',
					{ className: 'previewshare-section' },
					el( 'h2', null, __( 'Preview Links', 'previewshare' ) ),
					el(
						'p',
						{ className: 'description' },
						__(
							'No preview links have been generated yet.',
							'previewshare'
						)
					)
				);
			}

			const pageCount = Math.max(
				1,
				Math.ceil( totalTokens / PER_PAGE )
			);

			return el(
				'section',
				{ className: 'previewshare-section' },
				el( 'h2', null, __( 'Preview Links', 'previewshare' ) ),
				el(
					'table',
					{ className: 'widefat striped previewshare-table' },
					el(
						'thead',
						null,
						el(
							'tr',
							null,
							el( 'th', null, __( 'Label', 'previewshare' ) ),
							el( 'th', null, __( 'Content', 'previewshare' ) ),
							el( 'th', null, __( 'Status', 'previewshare' ) ),
							el( 'th', null, __( 'Views', 'previewshare' ) ),
							el( 'th', null, __( 'Expires', 'previewshare' ) ),
							el(
								'th',
								null,
								__( 'Last viewed', 'previewshare' )
							),
							el( 'th', null, __( 'Actions', 'previewshare' ) )
						)
					),
					el(
						'tbody',
						null,
						tokens.map( ( token ) => {
							const editUrl = `/wp-admin/post.php?post=${ token.post_id }&action=edit`;
							const isWorking = workingTokenId === token.id;

							return el(
								'tr',
								{ key: token.id },
								el(
									'td',
									null,
									token.label ||
										__( 'Preview link', 'previewshare' )
								),
								el(
									'td',
									{ className: 'previewshare-content-cell' },
									el(
										'a',
										{ href: editUrl },
										token.post_title ||
											__( 'Untitled', 'previewshare' )
									),
									el(
										'small',
										null,
										sprintf(
											/* translators: %d: Post ID. */
											__( 'ID %d', 'previewshare' ),
											token.post_id
										)
									)
								),
								el(
									'td',
									null,
									el( StatusBadge, { status: token.status } )
								),
								el( 'td', null, token.view_count || 0 ),
								el(
									'td',
									null,
									formatDate( token.expires_at )
								),
								el(
									'td',
									null,
									formatDate( token.last_viewed_at )
								),
								el(
									'td',
									{ className: 'previewshare-actions' },
									el(
										Button,
										{
											variant: 'secondary',
											isBusy:
												workingTokenId ===
												`post-${ token.post_id }`,
											disabled: Boolean( workingTokenId ),
											onClick: () =>
												handleGenerateAndCopy(
													token.post_id
												),
										},
										__( 'Generate & Copy', 'previewshare' )
									),
									token.status === 'active'
										? el(
												Button,
												{
													variant: 'secondary',
													isDestructive: true,
													isBusy: isWorking,
													disabled:
														Boolean(
															workingTokenId
														),
													onClick: () =>
														handleRevoke(
															token.id
														),
												},
												__( 'Revoke', 'previewshare' )
										  )
										: null
								)
							);
						} )
					)
				),
				el(
					'div',
					{ className: 'previewshare-pagination' },
					el(
						Button,
						{
							variant: 'secondary',
							disabled: page <= 1 || loadingTokens,
							onClick: () =>
								fetchTokens( Math.max( 1, page - 1 ) ),
						},
						__( 'Previous', 'previewshare' )
					),
					el(
						'span',
						null,
						sprintf(
							/* translators: 1: Current page. 2: Total pages. */
							__( 'Page %1$d of %2$d', 'previewshare' ),
							page,
							pageCount
						)
					),
					el(
						Button,
						{
							variant: 'secondary',
							disabled: page >= pageCount || loadingTokens,
							onClick: () => fetchTokens( page + 1 ),
						},
						__( 'Next', 'previewshare' )
					)
				)
			);
		}

		function renderGeneral() {
			if ( loadingSettings ) {
				return el(
					'div',
					{ className: 'previewshare-loading' },
					el( Spinner )
				);
			}

			return el(
				'section',
				{ className: 'previewshare-section' },
				el( 'h2', null, __( 'General Defaults', 'previewshare' ) ),
				el( TextControl, {
					label: __( 'Default expiry in hours', 'previewshare' ),
					type: 'number',
					min: 0,
					value: settings.default_ttl_hours,
					help: __(
						'Use 0 for links that do not expire automatically.',
						'previewshare'
					),
					onChange: ( value ) =>
						updateSetting(
							'default_ttl_hours',
							Math.max( 0, parseInt( value, 10 ) || 0 )
						),
				} ),
				settings.default_ttl_hours > 24
					? el(
							'p',
							{ className: 'previewshare-warning' },
							__(
								'Long-lived links should be used only when there is a clear review workflow.',
								'previewshare'
							)
					  )
					: null,
				renderSettingsActions()
			);
		}

		function renderPostTypes() {
			if ( loadingSettings ) {
				return el(
					'div',
					{ className: 'previewshare-loading' },
					el( Spinner )
				);
			}

			const availablePostTypes = settings.available_post_types || {};
			const postTypeKeys = Object.keys( availablePostTypes );
			const selectedPostTypes = settings.post_types || [];

			return el(
				'section',
				{ className: 'previewshare-section' },
				el(
					'h2',
					null,
					__( 'Supported Content Types', 'previewshare' )
				),
				postTypeKeys.length
					? el(
							'div',
							{ className: 'previewshare-post-types' },
							postTypeKeys.map( ( postType ) =>
								el( ToggleControl, {
									key: postType,
									label: availablePostTypes[ postType ],
									checked:
										selectedPostTypes.includes( postType ),
									onChange: ( checked ) => {
										const nextPostTypes = checked
											? selectedPostTypes.concat(
													postType
											  )
											: selectedPostTypes.filter(
													( item ) =>
														item !== postType
											  );
										updateSetting(
											'post_types',
											nextPostTypes
										);
									},
								} )
							)
					  )
					: el(
							'p',
							{ className: 'description' },
							__(
								'No public viewable post types are available.',
								'previewshare'
							)
					  ),
				el(
					'div',
					{ className: 'previewshare-secondary-actions' },
					el(
						Button,
						{
							variant: 'secondary',
							onClick: () =>
								updateSetting( 'post_types', postTypeKeys ),
						},
						__( 'Select all', 'previewshare' )
					),
					el(
						Button,
						{
							variant: 'secondary',
							onClick: () =>
								updateSetting(
									'post_types',
									settings.defaults.post_types || []
								),
						},
						__( 'Use defaults', 'previewshare' )
					)
				),
				selectedPostTypes.length === 0
					? el(
							'p',
							{ className: 'previewshare-warning' },
							__(
								'PreviewShare is disabled until at least one content type is selected.',
								'previewshare'
							)
					  )
					: null,
				renderSettingsActions()
			);
		}

		function renderAdvanced() {
			if ( loadingSettings ) {
				return el(
					'div',
					{ className: 'previewshare-loading' },
					el( Spinner )
				);
			}

			return el(
				'section',
				{ className: 'previewshare-section' },
				el( 'h2', null, __( 'Advanced', 'previewshare' ) ),
				el( ToggleControl, {
					label: __( 'Enable token lookup caching', 'previewshare' ),
					checked: settings.enable_caching,
					help: __(
						'Recommended for production sites with a persistent object cache.',
						'previewshare'
					),
					onChange: ( checked ) =>
						updateSetting( 'enable_caching', checked ),
				} ),
				el( ToggleControl, {
					label: __( 'Enable diagnostic logging', 'previewshare' ),
					checked: settings.enable_logging,
					help: __(
						'Emits diagnostic events for custom logging integrations. Keep disabled unless you are actively troubleshooting.',
						'previewshare'
					),
					onChange: ( checked ) =>
						updateSetting( 'enable_logging', checked ),
				} ),
				renderSettingsActions()
			);
		}

		function renderSettingsActions() {
			return el(
				'div',
				{ className: 'previewshare-settings-actions' },
				el(
					Button,
					{
						variant: 'primary',
						isBusy: savingSettings,
						disabled: savingSettings,
						onClick: saveAllSettings,
					},
					__( 'Save Settings', 'previewshare' )
				),
				el(
					Button,
					{
						variant: 'secondary',
						disabled: savingSettings,
						onClick: restoreDefaults,
					},
					__( 'Restore Defaults', 'previewshare' )
				)
			);
		}

		return el(
			'div',
			{ className: 'previewshare-settings' },
			el(
				'div',
				{ className: 'previewshare-header' },
				el(
					'div',
					null,
					el(
						'h1',
						null,
						__( 'PreviewShare Settings', 'previewshare' )
					),
					el(
						'p',
						null,
						__(
							'Manage public preview links, default expiry, supported content types, and production behavior.',
							'previewshare'
						)
					)
				),
				el(
					Button,
					{
						variant: 'secondary',
						disabled: savingSettings,
						onClick: restoreDefaults,
					},
					__( 'Restore Defaults', 'previewshare' )
				)
			),
			renderNotice(),
			renderSummary(),
			el(
				TabPanel,
				{
					className: 'previewshare-tabs',
					activeClass: 'is-active',
					tabs: [
						{
							name: 'previews',
							title: __( 'Preview Links', 'previewshare' ),
						},
						{
							name: 'general',
							title: __( 'General', 'previewshare' ),
						},
						{
							name: 'post-types',
							title: __( 'Content Types', 'previewshare' ),
						},
						{
							name: 'advanced',
							title: __( 'Advanced', 'previewshare' ),
						},
					],
				},
				( tab ) => {
					if ( tab.name === 'previews' ) {
						return renderPreviews();
					}

					if ( tab.name === 'general' ) {
						return renderGeneral();
					}

					if ( tab.name === 'post-types' ) {
						return renderPostTypes();
					}

					return renderAdvanced();
				}
			)
		);
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		const mount = document.getElementById( 'previewshare-settings-app' );

		if ( mount ) {
			render( el( SettingsApp ), mount );
		}
	} );
} )( window.wp );
