import { DataViews, filterSortAndPaginate } from '@wordpress/dataviews/wp';
import {
	check,
	copy,
	external,
	grid,
	link,
	page,
	post,
	timeToRead,
	trash,
} from '@wordpress/icons';
import {
	fallbackSettings,
	getRestBase,
	normalizeSettings,
} from './settings-utils';

( function ( wp ) {
	const {
		createElement: el,
		Fragment,
		render,
		useEffect,
		useRef,
		useState,
	} = wp.element;
	const { __, sprintf } = wp.i18n;
	const { Button, Icon, Notice, Spinner, TextControl, ToggleControl } =
		wp.components;

	const AUTOSAVE_DELAY = 450;
	const INVENTORY_PAGE_SIZE = 100;
	const MAX_INVENTORY_ITEMS = 1000;
	const DAY_IN_SECONDS = 24 * 60 * 60;
	const localized = window.previewshare_settings || {};
	const restBase = getRestBase( localized );
	const nonce = localized.nonce || '';
	const tabDefinitions = [
		{
			name: 'overview',
			title: __( 'Overview', 'previewshare' ),
		},
		{
			name: 'previews',
			title: __( 'Preview links', 'previewshare' ),
		},
		{
			name: 'content-types',
			title: __( 'Content types', 'previewshare' ),
		},
		{
			name: 'changelog',
			title: __( 'Changelog', 'previewshare' ),
		},
	];

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

		const date = new Date( timestamp * 1000 );

		if ( Number.isNaN( date.getTime() ) ) {
			return __( 'Never', 'previewshare' );
		}

		return date.toLocaleString();
	}

	function formatHours( hours ) {
		if ( hours > 0 ) {
			return sprintf(
				/* translators: %d: Number of hours. */
				__( '%d hours', 'previewshare' ),
				hours
			);
		}

		return __( 'Never', 'previewshare' );
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
			{
				className: 'previewshare-status is-' + ( status || 'unknown' ),
			},
			statusLabel( status )
		);
	}

	function getPostTypeIcon( postType ) {
		if ( postType === 'post' ) {
			return post;
		}

		if ( postType === 'page' ) {
			return page;
		}

		return grid;
	}

	function getPostTypeLabel( postType, availablePostTypes ) {
		return (
			availablePostTypes[ postType ] ||
			postType ||
			__( 'Content', 'previewshare' )
		);
	}

	function getSettingsPayload( values ) {
		return {
			default_ttl_hours: Math.max(
				0,
				parseInt( values.default_ttl_hours, 10 ) || 0
			),
			enable_logging: Boolean( values.enable_logging ),
			enable_caching: Boolean( values.enable_caching ),
			post_types: Array.isArray( values.post_types )
				? values.post_types
				: [],
		};
	}

	function getDayRange() {
		const today = new Date();
		const start = new Date(
			today.getFullYear(),
			today.getMonth(),
			today.getDate()
		);
		const end = new Date(
			today.getFullYear(),
			today.getMonth(),
			today.getDate() + 1
		);

		return {
			start: Math.floor( start.getTime() / 1000 ),
			end: Math.floor( end.getTime() / 1000 ),
		};
	}

	function getTokenFields() {
		const statusElements = [
			{
				value: 'active',
				label: __( 'Active', 'previewshare' ),
			},
			{
				value: 'expired',
				label: __( 'Expired', 'previewshare' ),
			},
			{
				value: 'revoked',
				label: __( 'Revoked', 'previewshare' ),
			},
		];

		return [
			{
				id: 'content',
				type: 'text',
				label: __( 'Content', 'previewshare' ),
				enableHiding: false,
				enableGlobalSearch: true,
				getValue: ( { item } ) =>
					item.post_title || __( 'Untitled content', 'previewshare' ),
				render: ( { item } ) => {
					const title =
						item.post_title ||
						__( 'Untitled content', 'previewshare' );
					const meta = sprintf(
						/* translators: 1: Content type. 2: Post ID. */
						__( '%1$s - ID %2$d', 'previewshare' ),
						item.post_type || __( 'Content', 'previewshare' ),
						item.post_id
					);

					return el(
						'div',
						{ className: 'previewshare-content-cell' },
						item.edit_url
							? el( 'a', { href: item.edit_url }, title )
							: el( 'span', null, title ),
						el( 'small', null, meta )
					);
				},
			},
			{
				id: 'label',
				type: 'text',
				label: __( 'Label', 'previewshare' ),
				enableGlobalSearch: true,
				getValue: ( { item } ) =>
					item.label || __( 'Preview link', 'previewshare' ),
				render: ( { item } ) =>
					item.label || __( 'Preview link', 'previewshare' ),
			},
			{
				id: 'status',
				type: 'text',
				label: __( 'Status', 'previewshare' ),
				elements: statusElements,
				filterBy: {
					operators: [ 'isAny' ],
				},
				getValue: ( { item } ) => item.status || 'active',
				render: ( { item } ) =>
					el( StatusBadge, { status: item.status } ),
			},
			{
				id: 'view_count',
				type: 'integer',
				label: __( 'Views', 'previewshare' ),
				getValue: ( { item } ) => Number( item.view_count ) || 0,
			},
			{
				id: 'expires_at',
				type: 'integer',
				label: __( 'Expires', 'previewshare' ),
				getValue: ( { item } ) => Number( item.expires_at ) || 0,
				render: ( { item } ) => formatDate( item.expires_at ),
			},
			{
				id: 'last_viewed_at',
				type: 'integer',
				label: __( 'Last viewed', 'previewshare' ),
				getValue: ( { item } ) => Number( item.last_viewed_at ) || 0,
				render: ( { item } ) => formatDate( item.last_viewed_at ),
			},
			{
				id: 'created_at',
				type: 'integer',
				label: __( 'Created', 'previewshare' ),
				enableHiding: true,
				getValue: ( { item } ) => Number( item.created_at ) || 0,
				render: ( { item } ) => formatDate( item.created_at ),
			},
		];
	}

	function ExternalLink( { href, icon, children } ) {
		return el(
			'a',
			{
				className: 'previewshare-external-link',
				href,
				target: '_blank',
				rel: 'noopener noreferrer',
			},
			children,
			el( Icon, { icon, size: 16 } )
		);
	}

	function MetricCard( { label, value, icon } ) {
		return el(
			'div',
			{ className: 'previewshare-metric-card' },
			el(
				'div',
				{ className: 'previewshare-metric-label' },
				icon ? el( Icon, { icon, size: 18 } ) : null,
				el( 'span', null, label )
			),
			el( 'strong', null, value )
		);
	}

	function SettingsApp() {
		const initialSettings = normalizeSettings(
			localized.settings || fallbackSettings
		);
		const [ settings, setSettings ] = useState( initialSettings );
		const [ activeTab, setActiveTab ] = useState( 'overview' );
		const [ tokens, setTokens ] = useState( [] );
		const [ totalTokens, setTotalTokens ] = useState( 0 );
		const [ inventoryTruncated, setInventoryTruncated ] = useState( false );
		const [ view, setView ] = useState( {
			type: 'table',
			search: '',
			filters: [],
			page: 1,
			perPage: 20,
			sort: {
				field: 'created_at',
				direction: 'desc',
			},
			titleField: 'content',
			fields: [
				'content',
				'label',
				'status',
				'view_count',
				'expires_at',
				'last_viewed_at',
			],
			layout: {
				density: 'comfortable',
			},
		} );
		const [ contentTypeSearch, setContentTypeSearch ] = useState( '' );
		const [ loadingSettings, setLoadingSettings ] = useState( true );
		const [ loadingTokens, setLoadingTokens ] = useState( true );
		const [ savingSettings, setSavingSettings ] = useState( false );
		const [ saveState, setSaveState ] = useState( 'saved' );
		const [ workingTokenId, setWorkingTokenId ] = useState( '' );
		const [ notice, setNotice ] = useState( null );
		const persistedSettingsRef = useRef( initialSettings );
		const latestSettingsRef = useRef( initialSettings );
		const saveTimerRef = useRef( null );
		const saveRequestIdRef = useRef( 0 );
		const saveQueueRef = useRef( Promise.resolve() );
		const tokenRequestIdRef = useRef( 0 );
		const mountedRef = useRef( false );

		useEffect( () => {
			mountedRef.current = true;
			fetchSettings();
			fetchTokens();

			return () => {
				mountedRef.current = false;

				if ( saveTimerRef.current ) {
					window.clearTimeout( saveTimerRef.current );
				}
			};
			// eslint-disable-next-line react-hooks/exhaustive-deps
		}, [] );

		function notify( status, message, url = '' ) {
			setNotice( { status, message, url } );
		}

		function fetchSettings() {
			setLoadingSettings( true );
			apiFetch( '/settings', { method: 'GET' } )
				.then( ( data ) => {
					if ( ! mountedRef.current ) {
						return;
					}

					const normalized = normalizeSettings( data );
					persistedSettingsRef.current = normalized;
					latestSettingsRef.current = normalized;
					setSettings( normalized );
					setLoadingSettings( false );
					setSaveState( 'saved' );
				} )
				.catch( () => {
					if ( ! mountedRef.current ) {
						return;
					}

					setLoadingSettings( false );
					notify(
						'error',
						__( 'Settings could not be loaded.', 'previewshare' )
					);
				} );
		}

		async function fetchTokens() {
			const requestId = ++tokenRequestIdRef.current;
			let nextPage = 1;
			let reportedTotal = 0;
			const items = [];

			setLoadingTokens( true );

			try {
				while (
					nextPage <=
					Math.ceil( MAX_INVENTORY_ITEMS / INVENTORY_PAGE_SIZE )
				) {
					const data = await apiFetch(
						'/tokens?per_page=' +
							INVENTORY_PAGE_SIZE +
							'&page=' +
							nextPage,
						{
							method: 'GET',
						}
					);
					const pageItems = Array.isArray( data.items )
						? data.items
						: [];

					reportedTotal = Number( data.total ) || 0;
					items.push( ...pageItems );

					if (
						pageItems.length < INVENTORY_PAGE_SIZE ||
						items.length >= reportedTotal ||
						items.length >= MAX_INVENTORY_ITEMS
					) {
						break;
					}

					nextPage += 1;
				}

				if (
					! mountedRef.current ||
					requestId !== tokenRequestIdRef.current
				) {
					return;
				}

				setTokens( items.slice( 0, MAX_INVENTORY_ITEMS ) );
				setTotalTokens( reportedTotal || items.length );
				setInventoryTruncated(
					( reportedTotal || items.length ) > MAX_INVENTORY_ITEMS
				);
				setLoadingTokens( false );
			} catch {
				if (
					! mountedRef.current ||
					requestId !== tokenRequestIdRef.current
				) {
					return;
				}

				setLoadingTokens( false );
				notify(
					'error',
					__( 'Preview links could not be loaded.', 'previewshare' )
				);
			}
		}

		function scheduleAutoSave( nextSettings ) {
			if ( saveTimerRef.current ) {
				window.clearTimeout( saveTimerRef.current );
			}

			setSaveState( 'pending' );
			saveTimerRef.current = window.setTimeout( () => {
				saveTimerRef.current = null;
				persistSettings( nextSettings );
			}, AUTOSAVE_DELAY );
		}

		function updateSetting( key, value ) {
			const nextSettings = normalizeSettings(
				Object.assign( {}, latestSettingsRef.current, {
					[ key ]: value,
				} )
			);

			latestSettingsRef.current = nextSettings;
			setSettings( nextSettings );
			scheduleAutoSave( nextSettings );
		}

		function persistSettings( values, options = {} ) {
			const requestId = ++saveRequestIdRef.current;
			const payload = getSettingsPayload( values );

			setSavingSettings( true );
			setSaveState( 'saving' );

			const request = saveQueueRef.current
				.catch( () => null )
				.then( () =>
					apiFetch( '/settings', {
						method: 'POST',
						body: payload,
					} )
				);

			saveQueueRef.current = request.catch( () => null );

			return request
				.then( ( data ) => {
					if (
						! mountedRef.current ||
						requestId !== saveRequestIdRef.current
					) {
						return data;
					}

					const normalized = normalizeSettings( data );
					persistedSettingsRef.current = normalized;
					latestSettingsRef.current = normalized;
					setSettings( normalized );
					setSavingSettings( false );
					setSaveState( 'saved' );

					if ( options.notice ) {
						notify(
							'success',
							options.message ||
								__( 'Settings saved.', 'previewshare' )
						);
					}

					return data;
				} )
				.catch( () => {
					if (
						! mountedRef.current ||
						requestId !== saveRequestIdRef.current
					) {
						return null;
					}

					setSavingSettings( false );
					setSaveState( 'error' );
					notify(
						'error',
						__( 'Settings could not be saved.', 'previewshare' )
					);
					return null;
				} );
		}

		function flushAutoSave( options = {} ) {
			if ( saveTimerRef.current ) {
				window.clearTimeout( saveTimerRef.current );
				saveTimerRef.current = null;
			}

			return persistSettings( latestSettingsRef.current, options );
		}

		function saveAllSettings() {
			return flushAutoSave( {
				notice: true,
				message: __( 'Settings saved.', 'previewshare' ),
			} );
		}

		function restoreDefaults() {
			if ( saveTimerRef.current ) {
				window.clearTimeout( saveTimerRef.current );
				saveTimerRef.current = null;
			}

			const requestId = ++saveRequestIdRef.current;
			setSavingSettings( true );
			setSaveState( 'saving' );

			const request = saveQueueRef.current
				.catch( () => null )
				.then( () =>
					apiFetch( '/settings', {
						method: 'POST',
						body: { reset_defaults: true },
					} )
				);

			saveQueueRef.current = request.catch( () => null );

			request
				.then( ( data ) => {
					if (
						! mountedRef.current ||
						requestId !== saveRequestIdRef.current
					) {
						return;
					}

					const normalized = normalizeSettings( data );
					persistedSettingsRef.current = normalized;
					latestSettingsRef.current = normalized;
					setSettings( normalized );
					setSavingSettings( false );
					setSaveState( 'saved' );
					notify(
						'success',
						__( 'Default settings restored.', 'previewshare' )
					);
				} )
				.catch( () => {
					if (
						! mountedRef.current ||
						requestId !== saveRequestIdRef.current
					) {
						return;
					}

					setSavingSettings( false );
					setSaveState( 'error' );
					notify(
						'error',
						__(
							'Default settings could not be restored.',
							'previewshare'
						)
					);
				} );
		}

		function cancelSettings() {
			if ( saveTimerRef.current ) {
				window.clearTimeout( saveTimerRef.current );
				saveTimerRef.current = null;
			}

			const reverted = normalizeSettings( persistedSettingsRef.current );
			latestSettingsRef.current = reverted;
			setSettings( reverted );
			persistSettings( reverted );
		}

		function handleGenerateAndCopy( postId ) {
			setWorkingTokenId( 'post-' + postId );

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
						Promise.resolve(
							window.navigator.clipboard.writeText( url )
						)
							.then( () =>
								notify(
									'success',
									__( 'Preview URL copied.', 'previewshare' )
								)
							)
							.catch( () =>
								notify(
									'info',
									__(
										'Preview URL generated. Copy it from the field below.',
										'previewshare'
									),
									url
								)
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

					fetchTokens();
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
					fetchTokens();
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
					className: 'previewshare-app-notice',
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

		function renderSaveState() {
			const labels = {
				pending: __( 'Unsaved changes', 'previewshare' ),
				saving: __( 'Saving changes…', 'previewshare' ),
				saved: __( 'Saved', 'previewshare' ),
				error: __( 'Save failed', 'previewshare' ),
			};

			return el(
				'span',
				{
					className: 'previewshare-save-state is-' + saveState,
					'aria-live': 'polite',
				},
				saveState === 'saved'
					? el( Icon, { icon: check, size: 16 } )
					: null,
				labels[ saveState ] || labels.saved
			);
		}

		function renderSaveActions( showCancel = true ) {
			return el(
				'div',
				{ className: 'previewshare-settings-actions' },
				renderSaveState(),
				showCancel
					? el(
							Button,
							{
								variant: 'tertiary',
								disabled: savingSettings,
								onClick: cancelSettings,
							},
							__( 'Cancel', 'previewshare' )
					  )
					: null,
				el(
					Button,
					{
						variant: 'primary',
						isBusy: savingSettings,
						disabled: savingSettings,
						onClick: saveAllSettings,
					},
					__( 'Save changes', 'previewshare' )
				)
			);
		}

		function renderOverview() {
			const dayRange = getDayRange();
			const now = Math.floor( Date.now() / 1000 );
			const activeTokens = tokens.filter(
				( token ) => token.status === 'active'
			);
			const expiringToday = activeTokens.filter(
				( token ) =>
					token.expires_at >= dayRange.start &&
					token.expires_at < dayRange.end
			).length;
			const expiringSoon = activeTokens
				.filter(
					( token ) =>
						token.expires_at &&
						token.expires_at > now &&
						token.expires_at <= now + DAY_IN_SECONDS
				)
				.sort(
					( first, second ) => first.expires_at - second.expires_at
				)
				.slice( 0, 5 );
			const availablePostTypes = settings.available_post_types || {};
			const enabledPostTypes = settings.post_types || [];

			return el(
				'div',
				{ className: 'previewshare-tab-content' },
				el(
					'div',
					{ className: 'previewshare-tab-intro' },
					el( 'h2', null, __( 'Overview', 'previewshare' ) ),
					el(
						'p',
						null,
						__(
							'Keep preview sharing focused, observable, and ready for review.',
							'previewshare'
						)
					)
				),
				el(
					'div',
					{ className: 'previewshare-metrics' },
					el( MetricCard, {
						label: __( 'Active links', 'previewshare' ),
						value: activeTokens.length,
						icon: link,
					} ),
					el( MetricCard, {
						label: __( 'Expiring today', 'previewshare' ),
						value: expiringToday,
						icon: timeToRead,
					} ),
					el( MetricCard, {
						label: __( 'Default expiry', 'previewshare' ),
						value: formatHours( settings.default_ttl_hours ),
						icon: timeToRead,
					} ),
					el( MetricCard, {
						label: __( 'Token cache', 'previewshare' ),
						value: settings.enable_caching
							? __( 'Enabled', 'previewshare' )
							: __( 'Disabled', 'previewshare' ),
						icon: grid,
					} )
				),
				inventoryTruncated
					? el(
							'p',
							{ className: 'previewshare-inline-note' },
							__(
								'Showing the first 1,000 links. Narrow the inventory or archive older links before reviewing the rest.',
								'previewshare'
							)
					  )
					: null,
				el(
					'div',
					{ className: 'previewshare-overview-columns' },
					el(
						'section',
						{
							className: 'previewshare-overview-section',
							'aria-labelledby': 'previewshare-expiring-title',
						},
						el(
							'h3',
							{ id: 'previewshare-expiring-title' },
							__( 'Expiring soon', 'previewshare' )
						),
						el(
							'p',
							{ className: 'description' },
							__(
								'Links that need attention in the next 24 hours.',
								'previewshare'
							)
						),
						expiringSoon.length
							? el(
									'table',
									{
										className:
											'widefat previewshare-overview-table',
									},
									el(
										'thead',
										null,
										el(
											'tr',
											null,
											el(
												'th',
												null,
												__( 'Content', 'previewshare' )
											),
											el(
												'th',
												null,
												__( 'Label', 'previewshare' )
											),
											el(
												'th',
												null,
												__( 'Expires', 'previewshare' )
											)
										)
									),
									el(
										'tbody',
										null,
										expiringSoon.map( ( token ) =>
											el(
												'tr',
												{ key: token.id },
												el(
													'td',
													null,
													token.edit_url
														? el(
																'a',
																{
																	href: token.edit_url,
																},
																token.post_title ||
																	__(
																		'Untitled content',
																		'previewshare'
																	)
														  )
														: token.post_title ||
																__(
																	'Untitled content',
																	'previewshare'
																)
												),
												el(
													'td',
													null,
													token.label ||
														__(
															'Preview link',
															'previewshare'
														)
												),
												el(
													'td',
													null,
													formatDate(
														token.expires_at
													)
												)
											)
										)
									)
							  )
							: el(
									'p',
									{ className: 'previewshare-empty-state' },
									__(
										'No active links are expiring soon.',
										'previewshare'
									)
							  )
					),
					el(
						'section',
						{
							className: 'previewshare-overview-section',
							'aria-labelledby':
								'previewshare-content-types-title',
						},
						el(
							'h3',
							{ id: 'previewshare-content-types-title' },
							__( 'Enabled content types', 'previewshare' )
						),
						el(
							'p',
							{ className: 'description' },
							__(
								'Choose which public content can receive a preview link.',
								'previewshare'
							)
						),
						el(
							'ul',
							{ className: 'previewshare-type-summary' },
							enabledPostTypes.length
								? enabledPostTypes.map( ( postType ) =>
										el(
											'li',
											{ key: postType },
											el( Icon, {
												icon: getPostTypeIcon(
													postType
												),
												size: 20,
											} ),
											el(
												'span',
												null,
												getPostTypeLabel(
													postType,
													availablePostTypes
												)
											)
										)
								  )
								: el(
										'li',
										{ className: 'is-empty' },
										__(
											'No content types enabled.',
											'previewshare'
										)
								  )
						),
						el(
							Button,
							{
								variant: 'secondary',
								onClick: () => setActiveTab( 'content-types' ),
							},
							__( 'Manage content types', 'previewshare' )
						)
					)
				),
				el(
					'div',
					{ className: 'previewshare-overview-columns' },
					el(
						'section',
						{
							className: 'previewshare-overview-section',
							'aria-labelledby': 'previewshare-protection-title',
						},
						el(
							'h3',
							{ id: 'previewshare-protection-title' },
							__( 'Protection summary', 'previewshare' )
						),
						el(
							'ul',
							{ className: 'previewshare-fact-list' },
							el(
								'li',
								null,
								el( Icon, { icon: check, size: 18 } ),
								el(
									'span',
									null,
									__( 'Hashed token storage', 'previewshare' )
								)
							),
							el(
								'li',
								null,
								el( Icon, { icon: check, size: 18 } ),
								el(
									'span',
									null,
									__(
										'Raw tokens are not stored',
										'previewshare'
									)
								)
							),
							el(
								'li',
								null,
								el( Icon, { icon: check, size: 18 } ),
								el(
									'span',
									null,
									__(
										'Noindex directives enabled',
										'previewshare'
									)
								)
							),
							el(
								'li',
								null,
								el( Icon, { icon: check, size: 18 } ),
								el(
									'span',
									null,
									__(
										'Link revocation available for every link',
										'previewshare'
									)
								)
							)
						)
					),
					el(
						'section',
						{
							className:
								'previewshare-overview-section previewshare-site-defaults',
							'aria-labelledby': 'previewshare-defaults-title',
						},
						el(
							'h3',
							{ id: 'previewshare-defaults-title' },
							__( 'Site defaults', 'previewshare' )
						),
						el(
							'p',
							{ className: 'description' },
							__(
								'Changes save automatically after each field update.',
								'previewshare'
							)
						),
						el( TextControl, {
							label: __(
								'Default expiry in hours',
								'previewshare'
							),
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
						el( ToggleControl, {
							label: __(
								'Enable token lookup caching',
								'previewshare'
							),
							checked: settings.enable_caching,
							help: __(
								'Recommended when the site has a persistent object cache.',
								'previewshare'
							),
							onChange: ( checked ) =>
								updateSetting( 'enable_caching', checked ),
						} ),
						el( ToggleControl, {
							label: __(
								'Enable diagnostic logging',
								'previewshare'
							),
							checked: settings.enable_logging,
							help: __(
								'Keep disabled unless you are actively troubleshooting.',
								'previewshare'
							),
							onChange: ( checked ) =>
								updateSetting( 'enable_logging', checked ),
						} ),
						renderSaveActions()
					)
				)
			);
		}

		function renderPreviews() {
			const fields = getTokenFields();
			const processed = filterSortAndPaginate( tokens, view, fields );
			const actions = [
				{
					id: 'generate',
					label: __( 'Generate & copy', 'previewshare' ),
					icon: el( Icon, { icon: copy } ),
					isEligible: ( item ) =>
						Boolean( item.post_id ) && ! workingTokenId,
					callback: ( items ) => {
						if ( items[ 0 ] ) {
							handleGenerateAndCopy( items[ 0 ].post_id );
						}
					},
				},
				{
					id: 'revoke',
					label: __( 'Revoke link', 'previewshare' ),
					icon: el( Icon, { icon: trash } ),
					isEligible: ( item ) =>
						item.status === 'active' && ! workingTokenId,
					callback: ( items ) => {
						if ( items[ 0 ] ) {
							handleRevoke( items[ 0 ].id );
						}
					},
				},
			];

			return el(
				'div',
				{ className: 'previewshare-tab-content' },
				el(
					'div',
					{ className: 'previewshare-tab-intro' },
					el( 'h2', null, __( 'Preview links', 'previewshare' ) ),
					el(
						'p',
						null,
						sprintf(
							/* translators: %d: Number of links. */
							__(
								'%d links in this site inventory.',
								'previewshare'
							),
							totalTokens
						)
					)
				),
				! DataViews
					? el(
							'p',
							{ className: 'previewshare-empty-state' },
							__(
								'The preview link listing is unavailable in this WordPress version.',
								'previewshare'
							)
					  )
					: el(
							'div',
							{ className: 'previewshare-dataviews' },
							el( DataViews, {
								data: processed.data,
								fields,
								view,
								onChangeView: setView,
								paginationInfo: processed.paginationInfo,
								defaultLayouts: { table: {} },
								config: {
									perPageSizes: [ 20, 50, 100 ],
								},
								actions,
								isLoading: loadingTokens,
								getItemId: ( item ) => item.id,
								searchLabel: __(
									'Search preview links',
									'previewshare'
								),
								empty: el(
									'p',
									{ className: 'previewshare-empty-state' },
									__(
										'No preview links match this view.',
										'previewshare'
									)
								),
							} )
					  ),
				inventoryTruncated
					? el(
							'p',
							{ className: 'previewshare-inline-note' },
							__(
								'The inventory is capped at 1,000 links for this screen. Storage remains unchanged.',
								'previewshare'
							)
					  )
					: null
			);
		}

		function renderContentTypes() {
			const availablePostTypes = settings.available_post_types || {};
			const postTypeKeys = Object.keys( availablePostTypes );
			const selectedPostTypes = settings.post_types || [];
			const normalizedSearch = contentTypeSearch.trim().toLowerCase();
			const visiblePostTypes = postTypeKeys.filter( ( postType ) => {
				if ( ! normalizedSearch ) {
					return true;
				}

				return (
					postType.toLowerCase().includes( normalizedSearch ) ||
					availablePostTypes[ postType ]
						.toLowerCase()
						.includes( normalizedSearch )
				);
			} );

			return el(
				'div',
				{ className: 'previewshare-tab-content' },
				el(
					'div',
					{ className: 'previewshare-tab-intro' },
					el( 'h2', null, __( 'Content types', 'previewshare' ) ),
					el(
						'p',
						null,
						__(
							'Control where editors can create secure preview links.',
							'previewshare'
						)
					)
				),
				el( TextControl, {
					className: 'previewshare-content-type-search',
					label: __( 'Search content types', 'previewshare' ),
					value: contentTypeSearch,
					onChange: setContentTypeSearch,
					placeholder: __( 'Search by name or key', 'previewshare' ),
				} ),
				el(
					'div',
					{ className: 'previewshare-content-type-list' },
					visiblePostTypes.length
						? visiblePostTypes.map( ( postType ) => {
								const enabled =
									latestSettingsRef.current.post_types.includes(
										postType
									);
								const label = getPostTypeLabel(
									postType,
									availablePostTypes
								);

								return el(
									'div',
									{
										className:
											'previewshare-content-type-row',
										key: postType,
									},
									el(
										'div',
										{
											className:
												'previewshare-content-type-icon',
										},
										el( Icon, {
											icon: getPostTypeIcon( postType ),
											size: 24,
										} )
									),
									el(
										'div',
										{
											className:
												'previewshare-content-type-info',
										},
										el( 'strong', null, label ),
										el( 'code', null, postType ),
										el(
											'small',
											null,
											postType === 'post' ||
												postType === 'page'
												? __(
														'Built-in',
														'previewshare'
												  )
												: __( 'Custom', 'previewshare' )
										)
									),
									el( ToggleControl, {
										className:
											'previewshare-content-type-toggle',
										label: sprintf(
											/* translators: %s: Content type label. */
											__( 'Enable %s', 'previewshare' ),
											label
										),
										checked: enabled,
										onChange: ( checked ) => {
											const currentPostTypes =
												latestSettingsRef.current
													.post_types;
											const nextPostTypes = checked
												? currentPostTypes.concat(
														postType
												  )
												: currentPostTypes.filter(
														( item ) =>
															item !== postType
												  );
											updateSetting(
												'post_types',
												nextPostTypes
											);
										},
									} )
								);
						  } )
						: el(
								'p',
								{ className: 'previewshare-empty-state' },
								__(
									'No matching content types found.',
									'previewshare'
								)
						  )
				),
				el(
					'div',
					{ className: 'previewshare-content-type-footer' },
					el(
						'span',
						null,
						sprintf(
							/* translators: 1: Enabled count. 2: Total count. */
							__(
								'%1$d of %2$d content types enabled',
								'previewshare'
							),
							selectedPostTypes.length,
							postTypeKeys.length
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
					renderSaveActions()
				)
			);
		}

		function renderChangelog() {
			const entries = [
				{
					version: '1.0.2',
					items: [
						__(
							'Validated preview-link editor and admin workflows with WordPress 7.1.',
							'previewshare'
						),
						__(
							'Prepared editor controls for the WordPress 7.1 component API.',
							'previewshare'
						),
					],
				},
				{
					version: '1.0.1',
					items: [
						__(
							'Improved diagnostics, dependency audit coverage, and release automation.',
							'previewshare'
						),
						__(
							'Refined WordPress.org metadata and plugin discoverability copy.',
							'previewshare'
						),
					],
				},
				{
					version: '1.0.0',
					items: [
						__(
							'Added secure preview links for draft, pending, and scheduled content.',
							'previewshare'
						),
						__(
							'Added editor controls, multiple links, views, noindex directives, and revocation.',
							'previewshare'
						),
						__(
							'Added global settings and hashed token storage in post meta.',
							'previewshare'
						),
					],
				},
			];

			return el(
				'div',
				{ className: 'previewshare-tab-content' },
				el(
					'div',
					{ className: 'previewshare-tab-intro' },
					el( 'h2', null, __( 'Changelog', 'previewshare' ) ),
					el(
						'p',
						null,
						__(
							'Release notes for the PreviewShare settings experience.',
							'previewshare'
						)
					)
				),
				el(
					'div',
					{ className: 'previewshare-changelog' },
					entries.map( ( entry ) =>
						el(
							'section',
							{
								className: 'previewshare-changelog-entry',
								key: entry.version,
							},
							el( 'h3', null, 'v' + entry.version ),
							el(
								'ul',
								null,
								entry.items.map( ( item ) =>
									el( 'li', { key: item }, item )
								)
							)
						)
					)
				)
			);
		}

		function renderTabPanel() {
			let content = renderOverview();

			if ( activeTab === 'previews' ) {
				content = renderPreviews();
			} else if ( activeTab === 'content-types' ) {
				content = renderContentTypes();
			} else if ( activeTab === 'changelog' ) {
				content = renderChangelog();
			}

			return el(
				'div',
				{
					id: 'previewshare-panel-' + activeTab,
					className: 'previewshare-tab-panel',
					role: 'tabpanel',
					'aria-labelledby': 'previewshare-tab-' + activeTab,
					tabIndex: 0,
				},
				content
			);
		}

		function handleTabKeyDown( event, tabName ) {
			const currentIndex = tabDefinitions.findIndex(
				( tab ) => tab.name === tabName
			);
			let nextIndex = currentIndex;

			if ( event.key === 'ArrowRight' ) {
				nextIndex = ( currentIndex + 1 ) % tabDefinitions.length;
			} else if ( event.key === 'ArrowLeft' ) {
				nextIndex =
					( currentIndex - 1 + tabDefinitions.length ) %
					tabDefinitions.length;
			} else if ( event.key === 'Home' ) {
				nextIndex = 0;
			} else if ( event.key === 'End' ) {
				nextIndex = tabDefinitions.length - 1;
			} else {
				return;
			}

			event.preventDefault();
			setActiveTab( tabDefinitions[ nextIndex ].name );
			window.setTimeout( () => {
				const button = document.querySelector(
					'[data-previewshare-tab="' +
						tabDefinitions[ nextIndex ].name +
						'"]'
				);

				if ( button ) {
					button.focus();
				}
			}, 0 );
		}

		return el(
			'div',
			{ className: 'previewshare-settings' },
			el(
				'header',
				{ className: 'previewshare-product-header' },
				el(
					'div',
					{ className: 'previewshare-product-identity' },
					localized.icon_url
						? el( 'img', {
								className: 'previewshare-product-icon',
								src: localized.icon_url,
								alt: __( 'PreviewShare icon', 'previewshare' ),
						  } )
						: null,
					el(
						'div',
						{ className: 'previewshare-product-name' },
						el( 'h1', null, 'PreviewShare' ),
						el(
							'span',
							{ className: 'previewshare-version-badge' },
							'v' + ( localized.version || '1.0.2' )
						)
					)
				),
				el(
					'div',
					{ className: 'previewshare-product-links' },
					el(
						ExternalLink,
						{
							href:
								localized.documentation_url ||
								'https://github.com/mehul0810/previewshare#readme',
							icon: external,
						},
						__( 'View documentation', 'previewshare' )
					),
					el(
						ExternalLink,
						{
							href:
								localized.support_url ||
								'https://wordpress.org/support/plugin/previewshare/',
							icon: external,
						},
						__( 'Support', 'previewshare' )
					)
				)
			),
			el(
				'nav',
				{
					className: 'previewshare-tabs',
					'aria-label': __( 'PreviewShare settings', 'previewshare' ),
				},
				tabDefinitions.map( ( tab ) =>
					el(
						'button',
						{
							id: 'previewshare-tab-' + tab.name,
							className:
								'previewshare-tab' +
								( activeTab === tab.name ? ' is-active' : '' ),
							type: 'button',
							role: 'tab',
							'aria-selected': activeTab === tab.name,
							'aria-controls': 'previewshare-panel-' + tab.name,
							tabIndex: activeTab === tab.name ? 0 : -1,
							'data-previewshare-tab': tab.name,
							onClick: () => setActiveTab( tab.name ),
							onKeyDown: ( event ) =>
								handleTabKeyDown( event, tab.name ),
						},
						tab.title
					)
				)
			),
			renderNotice(),
			loadingSettings
				? el(
						'div',
						{ className: 'previewshare-loading' },
						el( Spinner )
				  )
				: el(
						Fragment,
						null,
						el(
							'div',
							{ className: 'previewshare-header-actions' },
							el(
								Button,
								{
									variant: 'secondary',
									disabled: savingSettings,
									onClick: restoreDefaults,
								},
								__( 'Restore defaults', 'previewshare' )
							)
						),
						renderTabPanel()
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
