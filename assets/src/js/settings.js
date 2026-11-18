import { formatSiteDate, formatPreviewLabel } from './date-utils';
import { loadDataViews } from './load-dataviews';
import { useScopedNotices, NoticeRegion } from './scoped-notices';
import { fetchInventoryBatch } from './inventory-batch';
import { Changelog } from './settings-changelog';
import { createSettingsApi } from './settings-api';
import removeAccents from 'remove-accents';
import {
	check,
	copy,
	arrowRight,
	help,
	grid,
	link,
	page,
	timeToRead,
	trash,
} from '@wordpress/icons';
import {
	fallbackSettings,
	getRestBase,
	mergeInventoryItems,
	INVENTORY_BATCH_PAGES,
	INVENTORY_PAGE_SIZE,
	normalizeSettings,
} from './settings-utils';

if ( window.previewshare_settings && window.previewshare_settings.asset_base ) {
	// Webpack uses this special assignment to locate the lazy chunk in the plugin directory.
	// eslint-disable-next-line camelcase, no-undef
	__webpack_public_path__ = window.previewshare_settings.asset_base;
}

( function ( wp ) {
	const {
		createElement: el,
		render,
		useEffect,
		useRef,
		useState,
	} = wp.element;
	const { __, sprintf } = wp.i18n;
	const {
		Button,
		CheckboxControl,
		Icon,
		Modal,
		Notice,
		SearchControl,
		Spinner,
		TextControl,
		ToggleControl,
	} = wp.components;

	const AUTOSAVE_DELAY = 450;
	const MAX_AUTO_REFRESH_PAGES = 10;
	const DAY_IN_SECONDS = 24 * 60 * 60;
	const localized = window.previewshare_settings || {};
	const restBase = getRestBase( localized );
	const apiFetch = createSettingsApi( {
		restBase,
		nonce: localized.nonce || '',
		origin: window.location.origin,
		request: ( ...args ) => fetch( ...args ),
	} );
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
		{
			name: 'more-plugins',
			title: __( 'More plugins', 'previewshare' ),
		},
	];

	function getTabFromUrl() {
		const tabName = new URL( window.location.href ).searchParams.get(
			'tab'
		);
		return tabDefinitions.some( ( tab ) => tab.name === tabName )
			? tabName
			: 'overview';
	}
	const pluginCatalog = [
		{
			name: 'ThemeRouter',
			icon: 'themerouter.svg',
			description: __(
				'Preview installed themes privately and route ready WordPress content to them in controlled stages.',
				'previewshare'
			),
			url: 'https://themerouter.com/',
		},
		{
			name: 'Perform',
			icon: 'perform.jpg',
			description: __(
				'Optimize WordPress performance with asset controls, page caching, CDN rewriting, and cleanup tools.',
				'previewshare'
			),
			url: 'https://performwp.com/',
		},
		{
			name: 'CleanLinks',
			icon: 'cleanlinks.png',
			description: __(
				'Create branded short links, manage redirects, cloak affiliate URLs, and export links via CSV.',
				'previewshare'
			),
			url: 'https://github.com/mehul0810/cleanlinks',
		},
		{
			name: 'Aculect AI Companion',
			icon: 'aculect-ai-companion.png',
			description: __(
				'Use MCP-compatible AI apps to manage WordPress content, internal links, media, comments, and site workflows.',
				'previewshare'
			),
			url: 'https://wordpress.org/plugins/aculect-ai-companion/',
		},
		{
			name: 'Aculect Icon Library',
			icon: 'aculect-icon-library.png',
			description: __(
				'Add Heroicons, Bootstrap Icons, Font Awesome Free, and custom SVG icons to the native WordPress Icon block.',
				'previewshare'
			),
			url: 'https://github.com/mehul0810/aculect-icon-library',
		},
		{
			name: 'WP Distraction Free View',
			icon: 'wp-distraction-free-view.png',
			description: __(
				'Give visitors a focused Reader Mode for posts, pages, and selected public post types.',
				'previewshare'
			),
			url: 'https://github.com/mehul0810/wp-distraction-free-view',
		},
		{
			name: 'OneCaptcha',
			icon: 'onecaptcha.svg',
			description: __(
				'Protect WordPress forms and sign-in flows with reCAPTCHA, Cloudflare Turnstile, and hCaptcha.',
				'previewshare'
			),
			url: 'https://onecaptchawp.com/',
		},
	];

	function formatDate( timestamp ) {
		return formatSiteDate( timestamp, localized );
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
		if ( status === 'expiring_soon' ) {
			return __( 'Expiring soon', 'previewshare' );
		}

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

	function isExpiringSoon( item, now = Math.floor( Date.now() / 1000 ) ) {
		const expiresAt = Number( item.expires_at ) || 0;

		return (
			item.status === 'active' &&
			expiresAt > now &&
			expiresAt <= now + DAY_IN_SECONDS
		);
	}

	const linkReferencesByInventory = new WeakMap();

	function getUniqueLinkReference( item, inventoryItems ) {
		const id = String( item.id );
		let references = linkReferencesByInventory.get( inventoryItems );
		if ( ! references ) {
			references = new Map();
			linkReferencesByInventory.set( inventoryItems, references );
		}
		if ( references.has( id ) ) {
			return references.get( id );
		}

		// Only resolve references for links whose Extend action is rendered.
		let length = Math.min( 8, id.length );
		while ( length < id.length ) {
			const prefix = id.slice( 0, length );
			if (
				! inventoryItems.some(
					( other ) =>
						other.id !== item.id &&
						String( other.id ).startsWith( prefix )
				)
			) {
				break;
			}
			length++;
		}
		const reference = id.slice( 0, length );
		references.set( id, reference );
		return reference;
	}

	function extendLinkAriaLabel( item, inventoryItems ) {
		const title =
			item.post_title || __( 'Untitled content', 'previewshare' );
		const reference = getUniqueLinkReference( item, inventoryItems );

		if ( item.label ) {
			return sprintf(
				/* translators: 1: Preview link label. 2: Content title. 3: Short unique link reference. */
				__(
					'Extend %1$s link for %2$s, reference %3$s',
					'previewshare'
				),
				formatPreviewLabel( item, localized ),
				title,
				reference
			);
		}

		return sprintf(
			/* translators: 1: Content title. 2: Short unique link reference. */
			__(
				'Extend preview link for %1$s, reference %2$s',
				'previewshare'
			),
			title,
			reference
		);
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

	function reviewStateLabel( state ) {
		const labels = {
			pending: __( 'Awaiting response', 'previewshare' ),
			approved: __( 'Approved', 'previewshare' ),
			stale: __( 'Approval needs review', 'previewshare' ),
			changes_requested: __( 'Changes requested', 'previewshare' ),
			commented: __( 'Comment received', 'previewshare' ),
		};

		return labels[ state ] || '';
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

	function getTokenFields(
		onExtend,
		workingTokenId,
		inventoryItems,
		availablePostTypes = {}
	) {
		const statusElements = [
			{
				value: 'active',
				label: __( 'Active', 'previewshare' ),
			},
			{
				value: 'expiring_soon',
				label: __( 'Expiring soon', 'previewshare' ),
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
						/* translators: %d: Post ID. */
						__( '#%d', 'previewshare' ),
						Number( item.post_id ) || 0
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
				getValue: ( { item } ) => formatPreviewLabel( item, localized ),
				render: ( { item } ) => formatPreviewLabel( item, localized ),
			},
			{
				id: 'post_type',
				type: 'text',
				label: __( 'Content type', 'previewshare' ),
				elements: Object.entries( availablePostTypes ).map(
					( [ value, label ] ) => ( {
						value,
						label,
					} )
				),
				filterBy: { operators: [ 'isAny' ] },
				getValue: ( { item } ) => item.post_type,
				render: ( { item } ) =>
					availablePostTypes[ item.post_type ] || item.post_type,
			},
			{
				id: 'status',
				type: 'text',
				label: __( 'Status', 'previewshare' ),
				elements: statusElements,
				filterBy: {
					operators: [ 'isAny' ],
					isPrimary: true,
				},
				getValue: ( { item } ) =>
					isExpiringSoon( item )
						? 'expiring_soon'
						: item.status || 'active',
				render: ( { item } ) => {
					const expiringSoon = isExpiringSoon( item );

					return el(
						'div',
						{ className: 'previewshare-link-status-cell' },
						el( StatusBadge, {
							status: expiringSoon
								? 'expiring_soon'
								: item.status,
						} ),
						item.review_state
							? el(
									'span',
									{ className: 'previewshare-review-state' },
									reviewStateLabel( item.review_state )
							  )
							: null,
						expiringSoon
							? el(
									Button,
									{
										variant: 'tertiary',
										isBusy: workingTokenId === item.id,
										disabled: Boolean( workingTokenId ),
										'aria-label': extendLinkAriaLabel(
											item,
											inventoryItems
										),
										onClick: () => onExtend( item.id ),
									},
									__( 'Extend', 'previewshare' )
							  )
							: null
					);
				},
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
				filterBy: false,
				getValue: ( { item } ) => Number( item.expires_at ) || 0,
				render: ( { item } ) => formatDate( item.expires_at ),
			},
			{
				id: 'last_viewed_at',
				type: 'integer',
				label: __( 'Last viewed', 'previewshare' ),
				filterBy: false,
				getValue: ( { item } ) => Number( item.last_viewed_at ) || 0,
				render: ( { item } ) => formatDate( item.last_viewed_at ),
			},
			{
				id: 'created_at',
				type: 'integer',
				label: __( 'Created', 'previewshare' ),
				enableHiding: true,
				filterBy: false,
				getValue: ( { item } ) => Number( item.created_at ) || 0,
				render: ( { item } ) => formatDate( item.created_at ),
			},
		];
	}

	function getFilterValues( filter ) {
		if ( ! filter ) {
			return [];
		}

		return Array.isArray( filter.value ) ? filter.value : [ filter.value ];
	}

	function normalizeSearchInput( input ) {
		return removeAccents( String( input ).trim().toLowerCase() );
	}

	function ExternalLink( {
		href,
		icon,
		children,
		ariaLabel,
		iconBefore = false,
	} ) {
		return el(
			'a',
			{
				className: 'previewshare-external-link',
				href,
				'aria-label': ariaLabel,
				target: '_blank',
				rel: 'noopener noreferrer',
			},
			iconBefore ? el( Icon, { icon, size: 16 } ) : null,
			children,
			! iconBefore ? el( Icon, { icon, size: 16 } ) : null
		);
	}

	function MetricCard( { label, value, icon, description } ) {
		return el(
			'div',
			{ className: 'previewshare-metric-card' },
			el(
				'div',
				{ className: 'previewshare-metric-label' },
				icon ? el( Icon, { icon, size: 18 } ) : null,
				el( 'span', null, label )
			),
			el( 'strong', null, value ),
			description
				? el(
						'p',
						{ className: 'previewshare-metric-description' },
						description
				  )
				: null
		);
	}

	function SettingsApp() {
		const initialSettings = normalizeSettings(
			localized.settings || fallbackSettings
		);
		const [ settings, setSettings ] = useState( initialSettings );
		const [ activeTab, setActiveTab ] = useState( getTabFromUrl );
		const [ dataViewsModule, setDataViewsModule ] = useState( null );
		const [ dataViewsFailed, setDataViewsFailed ] = useState( false );
		const [ tokens, setTokens ] = useState( [] );
		const [ totalTokens, setTotalTokens ] = useState( 0 );
		const [ hasMoreTokens, setHasMoreTokens ] = useState( false );
		const [ inventoryStale, setInventoryStale ] = useState( false );
		const [ loadingMoreTokens, setLoadingMoreTokens ] = useState( false );
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
		const [ compactInventory, setCompactInventory ] = useState(
			() =>
				typeof window.matchMedia === 'function' &&
				window.matchMedia( '(max-width: 1440px)' ).matches
		);
		const [ loadingSettings, setLoadingSettings ] = useState( true );
		const [ loadingTokens, setLoadingTokens ] = useState( true );
		const [ savingSettings, setSavingSettings ] = useState( false );
		const [ saveState, setSaveState ] = useState( 'saved' );
		const [ workingTokenId, setWorkingTokenId ] = useState( '' );
		const [ deleteCandidate, setDeleteCandidate ] = useState( null );
		const [ deleteError, setDeleteError ] = useState( '' );
		const { notices, notify, clearNotice } = useScopedNotices();
		const persistedSettingsRef = useRef( initialSettings );
		const latestSettingsRef = useRef( initialSettings );
		const saveTimerRef = useRef( null );
		const saveRequestIdRef = useRef( 0 );
		const editRevisionRef = useRef( 0 );
		const saveQueueRef = useRef( Promise.resolve() );
		const saveErrorRef = useRef( false );
		const resetContextRef = useRef( null );
		const tokenRequestIdRef = useRef( 0 );
		const tokensRef = useRef( [] );
		const inventoryTotalRef = useRef( null );
		const nextInventoryPageRef = useRef( 1 );
		const nextInventoryCursorRef = useRef( 'start' );
		const refreshInventoryPendingRef = useRef( false );
		const mountedRef = useRef( false );
		const inventoryStartedRef = useRef( false );
		const tabListRef = useRef( null );

		useEffect( () => {
			const revealActiveTab = () => {
				const tabList = tabListRef.current;
				const selectedTab = tabList?.querySelector(
					'[aria-selected="true"]'
				);

				if ( ! selectedTab ) {
					return;
				}

				const listBounds = tabList.getBoundingClientRect();
				const tabBounds = selectedTab.getBoundingClientRect();

				// Keep the current location visible without moving the page vertically.
				if ( tabBounds.left < listBounds.left ) {
					tabList.scrollLeft += tabBounds.left - listBounds.left;
				} else if ( tabBounds.right > listBounds.right ) {
					tabList.scrollLeft += tabBounds.right - listBounds.right;
				}
			};

			revealActiveTab();
			const observer =
				typeof window.ResizeObserver === 'function'
					? new window.ResizeObserver( revealActiveTab )
					: null;
			observer?.observe( tabListRef.current );
			window.addEventListener( 'resize', revealActiveTab );
			return () => {
				observer?.disconnect();
				window.removeEventListener( 'resize', revealActiveTab );
			};
		}, [ activeTab ] );

		useEffect( () => {
			const url = new URL( window.location.href );
			url.searchParams.set( 'tab', activeTab );
			window.history.replaceState(
				window.history.state,
				'',
				url.toString()
			);
		}, [ activeTab ] );

		useEffect( () => {
			const syncTab = () => setActiveTab( getTabFromUrl() );
			window.addEventListener( 'popstate', syncTab );
			return () => window.removeEventListener( 'popstate', syncTab );
		}, [] );

		useEffect( () => {
			if ( typeof window.matchMedia !== 'function' ) {
				return undefined;
			}

			const media = window.matchMedia( '(max-width: 1440px)' );
			const onChange = () => setCompactInventory( media.matches );
			media.addEventListener( 'change', onChange );
			return () => media.removeEventListener( 'change', onChange );
		}, [] );

		useEffect( () => {
			mountedRef.current = true;
			fetchSettings();

			return () => {
				mountedRef.current = false;

				if ( saveTimerRef.current ) {
					window.clearTimeout( saveTimerRef.current );
				}
			};
			// eslint-disable-next-line react-hooks/exhaustive-deps
		}, [] );

		useEffect( () => {
			if (
				! inventoryStartedRef.current &&
				( activeTab === 'overview' || activeTab === 'previews' )
			) {
				inventoryStartedRef.current = true;
				fetchTokens();
			}
			// Fetch once when a tab first needs the inventory.
			// eslint-disable-next-line react-hooks/exhaustive-deps
		}, [ activeTab ] );

		useEffect( () => {
			let cancelled = false;
			let settled = false;
			let timeout;
			if (
				activeTab === 'previews' &&
				! compactInventory &&
				! dataViewsModule &&
				! dataViewsFailed &&
				typeof wp.element.useInsertionEffect === 'function'
			) {
				timeout = window.setTimeout( () => {
					settled = true;
					if ( ! cancelled ) {
						setDataViewsFailed( true );
					}
				}, 5000 );
				loadDataViews()
					.then( ( module ) => {
						if ( ! cancelled && ! settled ) {
							settled = true;
							window.clearTimeout( timeout );
							setDataViewsModule( module );
						}
					} )
					.catch( () => {
						if ( ! cancelled && ! settled ) {
							settled = true;
							window.clearTimeout( timeout );
							setDataViewsFailed( true );
						}
					} );
			}
			return () => {
				cancelled = true;
				window.clearTimeout( timeout );
			};
		}, [ activeTab, compactInventory, dataViewsModule, dataViewsFailed ] );

		function notifySettings( status, message ) {
			notify( status, message, '', 'settings' );
		}

		function fetchSettings() {
			const revisionAtRequest = editRevisionRef.current;
			setLoadingSettings( true );
			apiFetch( '/settings', { method: 'GET' } )
				.then( ( data ) => {
					if ( ! mountedRef.current ) {
						return;
					}

					if ( revisionAtRequest !== editRevisionRef.current ) {
						setLoadingSettings( false );
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
					notifySettings(
						'error',
						__( 'Settings could not be loaded.', 'previewshare' )
					);
				} );
		}

		async function fetchTokens( {
			append = false,
			refreshLoaded = false,
			resetView = false,
			changed = false,
		} = {} ) {
			const requestId = ++tokenRequestIdRef.current;
			const firstPage = append ? nextInventoryPageRef.current : 1;
			const existingItems = append ? tokensRef.current : [];
			const pageLimit = refreshLoaded
				? Math.min(
						MAX_AUTO_REFRESH_PAGES,
						Math.max( 1, nextInventoryPageRef.current - 1 )
				  )
				: INVENTORY_BATCH_PAGES;
			if ( ! append ) {
				setLoadingTokens( true );
			} else {
				setLoadingMoreTokens( true );
			}

			try {
				const batch = await fetchInventoryBatch( {
					apiFetch,
					append,
					page: firstPage,
					cursor: append ? nextInventoryCursorRef.current : 'start',
					pageSize: INVENTORY_PAGE_SIZE,
					pageLimit,
					existingCount: existingItems.length,
					previousTotal: inventoryTotalRef.current,
					isCurrent: () =>
						mountedRef.current &&
						requestId === tokenRequestIdRef.current,
				} );
				if ( batch.cancelled ) {
					return;
				}
				if ( batch.changed ) {
					if ( ! changed ) {
						await fetchTokens( { resetView: true, changed: true } );
						return;
					}
					throw new Error( 'Inventory changed during refresh' );
				}

				if (
					! mountedRef.current ||
					requestId !== tokenRequestIdRef.current
				) {
					return;
				}

				const mergedItems = mergeInventoryItems(
					existingItems,
					batch.items
				);
				const total = batch.total || mergedItems.length;

				tokensRef.current = mergedItems;
				inventoryTotalRef.current = batch.total;
				setTokens( mergedItems );
				if ( ! append ) {
					setInventoryStale( false );
				}
				if ( resetView ) {
					setView( ( current ) => ( { ...current, page: 1 } ) );
				}
				if ( changed ) {
					notify(
						'info',
						__(
							'Preview links changed while loading. The list was refreshed; load more to see older links.',
							'previewshare'
						)
					);
				}
				setTotalTokens( total );
				setHasMoreTokens( batch.hasMore && mergedItems.length < total );
				nextInventoryPageRef.current = batch.nextPage;
				nextInventoryCursorRef.current = batch.nextCursor;
				setLoadingTokens( false );
				setLoadingMoreTokens( false );
			} catch {
				if (
					! mountedRef.current ||
					requestId !== tokenRequestIdRef.current
				) {
					return;
				}

				setLoadingTokens( false );
				setLoadingMoreTokens( false );
				if ( changed ) {
					setInventoryStale( true );
				}
				notify(
					'error',
					__( 'Preview links could not be loaded.', 'previewshare' )
				);
			}
		}

		function refreshAfterMutation() {
			if ( nextInventoryPageRef.current - 1 > MAX_AUTO_REFRESH_PAGES ) {
				++tokenRequestIdRef.current;
				setLoadingTokens( false );
				setLoadingMoreTokens( false );
				setInventoryStale( true );
				return;
			}
			fetchTokens( { refreshLoaded: true } );
		}

		async function refreshInventory() {
			if (
				refreshInventoryPendingRef.current ||
				loadingTokens ||
				loadingMoreTokens
			) {
				return;
			}
			refreshInventoryPendingRef.current = true;
			try {
				await fetchTokens( { resetView: true } );
			} finally {
				refreshInventoryPendingRef.current = false;
			}
		}

		function scheduleAutoSave( nextSettings, revision ) {
			if ( saveTimerRef.current ) {
				window.clearTimeout( saveTimerRef.current );
			}

			setSavingSettings( false );
			setSaveState( 'pending' );
			saveTimerRef.current = window.setTimeout( () => {
				saveTimerRef.current = null;
				if ( resetContextRef.current?.pending ) {
					return;
				}
				persistSettings( nextSettings, { revision } );
			}, AUTOSAVE_DELAY );
		}

		function updateSetting( key, value ) {
			const revision = ++editRevisionRef.current;
			if ( resetContextRef.current?.pending ) {
				resetContextRef.current.changes[ key ] = value;
			}
			const nextSettings = normalizeSettings(
				Object.assign( {}, latestSettingsRef.current, {
					[ key ]: value,
				} )
			);

			latestSettingsRef.current = nextSettings;
			setSettings( nextSettings );
			scheduleAutoSave( nextSettings, revision );
		}

		function persistSettings( values, options = {} ) {
			if ( resetContextRef.current?.pending ) {
				if ( options.notice ) {
					resetContextRef.current.manualSave = true;
				}

				return Promise.resolve( null );
			}

			const requestId = ++saveRequestIdRef.current;
			const revision = Number.isInteger( options.revision )
				? options.revision
				: editRevisionRef.current;
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
					if ( ! mountedRef.current ) {
						return data;
					}

					const normalized = normalizeSettings( data );
					persistedSettingsRef.current = normalized;

					if (
						revision !== editRevisionRef.current ||
						requestId !== saveRequestIdRef.current
					) {
						return data;
					}

					latestSettingsRef.current = normalized;
					setSettings( normalized );
					setSavingSettings( false );
					setSaveState( 'saved' );
					if ( saveErrorRef.current ) {
						saveErrorRef.current = false;
						clearNotice( 'settings' );
					}

					if ( options.notice ) {
						notifySettings(
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
						revision !== editRevisionRef.current ||
						requestId !== saveRequestIdRef.current
					) {
						return null;
					}

					setSavingSettings( false );
					setSaveState( 'error' );
					saveErrorRef.current = true;
					notifySettings(
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

			if ( resetContextRef.current?.pending ) {
				if ( options.notice ) {
					resetContextRef.current.manualSave = true;
				}

				return Promise.resolve( null );
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

			const revision = ++editRevisionRef.current;
			const requestId = ++saveRequestIdRef.current;
			resetContextRef.current = {
				pending: true,
				revision,
				changes: {},
				manualSave: false,
			};
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
					if ( ! mountedRef.current ) {
						return;
					}

					const normalized = normalizeSettings( data );
					persistedSettingsRef.current = normalized;
					const resetContext = resetContextRef.current;

					if (
						requestId !== saveRequestIdRef.current ||
						! resetContext ||
						resetContext.revision !== revision
					) {
						return;
					}

					resetContextRef.current = null;
					const changedKeys = Object.keys( resetContext.changes );

					if ( changedKeys.length ) {
						if ( saveTimerRef.current ) {
							window.clearTimeout( saveTimerRef.current );
							saveTimerRef.current = null;
						}

						const rebased = normalizeSettings(
							Object.assign(
								{},
								normalized,
								resetContext.changes
							)
						);
						latestSettingsRef.current = rebased;
						setSettings( rebased );
						persistSettings( rebased, {
							revision: editRevisionRef.current,
							notice: resetContext.manualSave,
							message: __( 'Settings saved.', 'previewshare' ),
						} );
						return;
					}

					latestSettingsRef.current = normalized;
					setSettings( normalized );
					setSavingSettings( false );
					setSaveState( 'saved' );
					if ( saveErrorRef.current ) {
						saveErrorRef.current = false;
						clearNotice( 'settings' );
					}
					notifySettings(
						'success',
						__( 'Default settings restored.', 'previewshare' )
					);
				} )
				.catch( () => {
					const resetContext = resetContextRef.current;
					if (
						! mountedRef.current ||
						requestId !== saveRequestIdRef.current ||
						! resetContext ||
						resetContext.revision !== revision
					) {
						return;
					}

					resetContextRef.current = null;
					if ( Object.keys( resetContext.changes ).length ) {
						if ( saveTimerRef.current ) {
							window.clearTimeout( saveTimerRef.current );
							saveTimerRef.current = null;
						}

						setSavingSettings( false );
						setSaveState( 'error' );
						saveErrorRef.current = true;
						notifySettings(
							'error',
							__(
								'Default settings could not be restored.',
								'previewshare'
							)
						);
						persistSettings( latestSettingsRef.current, {
							revision: editRevisionRef.current,
						} );
						return;
					}

					setSavingSettings( false );
					setSaveState( 'error' );
					saveErrorRef.current = true;
					notifySettings(
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

			resetContextRef.current = null;

			const revision = ++editRevisionRef.current;
			const reverted = normalizeSettings( persistedSettingsRef.current );
			latestSettingsRef.current = reverted;
			setSettings( reverted );
			persistSettings( reverted, { revision } );
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

					refreshAfterMutation();
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
			if ( workingTokenId ) {
				return;
			}
			setWorkingTokenId( id );

			apiFetch( '/tokens/revoke', {
				method: 'POST',
				body: { id },
			} )
				.then( ( data ) => {
					if ( data.revoked !== true ) {
						throw new Error( 'revoke_failed' );
					}
					tokensRef.current = tokensRef.current.map( ( item ) =>
						item.id === id
							? { ...item, status: 'revoked', revoked: true }
							: item
					);
					setTokens( tokensRef.current );
					notify(
						'success',
						__( 'Preview link revoked.', 'previewshare' )
					);
					refreshAfterMutation();
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

		function handleDelete( id ) {
			if ( workingTokenId ) {
				return;
			}
			setWorkingTokenId( id );
			setDeleteError( '' );

			apiFetch( '/tokens/delete', { method: 'POST', body: { id } } )
				.then( ( data ) => {
					if ( data.deleted !== true || data.revoked !== true ) {
						throw new Error( 'delete_failed' );
					}
					tokensRef.current = tokensRef.current.filter(
						( item ) => item.id !== id
					);
					setTokens( tokensRef.current );
					setTotalTokens( ( total ) => Math.max( 0, total - 1 ) );
					setView( ( current ) => ( { ...current, page: 1 } ) );
					setDeleteCandidate( null );
					notify(
						'success',
						__(
							'Preview link revoked and deleted.',
							'previewshare'
						)
					);
					refreshAfterMutation();
					window.setTimeout(
						() =>
							document
								.getElementById( 'previewshare-tab-previews' )
								?.focus(),
						0
					);
				} )
				.catch( () =>
					setDeleteError(
						__(
							'Preview link could not be deleted. Please try again.',
							'previewshare'
						)
					)
				)
				.finally( () => setWorkingTokenId( '' ) );
		}

		function getInventoryActions() {
			return [
				{
					id: 'generate',
					label: __( 'Generate & copy', 'previewshare' ),
					icon: el( Icon, { icon: copy } ),
					disabled: Boolean( workingTokenId ),
					isEligible: ( item ) => Boolean( item.post_id ),
					callback: ( items ) =>
						items[ 0 ] &&
						handleGenerateAndCopy( items[ 0 ].post_id ),
				},
				{
					id: 'revoke',
					label: __( 'Revoke link', 'previewshare' ),
					icon: el( Icon, { icon: trash } ),
					disabled: Boolean( workingTokenId ),
					isEligible: ( item ) => item.status !== 'revoked',
					callback: ( items ) =>
						items[ 0 ] && handleRevoke( items[ 0 ].id ),
				},
				{
					id: 'delete',
					label: __( 'Delete link', 'previewshare' ),
					icon: el( Icon, { icon: trash } ),
					disabled: Boolean( workingTokenId ),
					callback: ( items ) => {
						if ( items[ 0 ] && ! workingTokenId ) {
							setDeleteError( '' );
							setDeleteCandidate( items[ 0 ] );
						}
					},
				},
			];
		}

		function renderDeleteConfirmation() {
			if ( ! deleteCandidate ) {
				return null;
			}
			return el(
				Modal,
				{
					title: __( 'Delete preview link?', 'previewshare' ),
					onRequestClose: () =>
						! workingTokenId && setDeleteCandidate( null ),
				},
				el(
					'p',
					null,
					__(
						'This will revoke the preview link and permanently remove its entry from the list.',
						'previewshare'
					)
				),
				el(
					'p',
					null,
					el(
						'strong',
						null,
						deleteCandidate.post_title ||
							__( 'Untitled content', 'previewshare' )
					),
					' · ',
					deleteCandidate.label ||
						__( 'Preview link', 'previewshare' )
				),
				deleteError
					? el(
							Notice,
							{
								status: 'error',
								className: 'previewshare-app-notice',
								isDismissible: false,
							},
							deleteError
					  )
					: null,
				el(
					'div',
					{ className: 'previewshare-dialog-actions' },
					el(
						Button,
						{
							variant: 'tertiary',
							disabled: Boolean( workingTokenId ),
							onClick: () => setDeleteCandidate( null ),
						},
						__( 'Cancel', 'previewshare' )
					),
					el(
						Button,
						{
							variant: 'primary',
							isDestructive: true,
							isBusy: Boolean( workingTokenId ),
							disabled: Boolean( workingTokenId ),
							onClick: () => handleDelete( deleteCandidate.id ),
						},
						__( 'Delete link', 'previewshare' )
					)
				)
			);
		}

		function handleExtend( id ) {
			setWorkingTokenId( id );

			apiFetch( '/tokens/extend', {
				method: 'POST',
				body: { id },
			} )
				.then( ( data ) => {
					tokensRef.current = tokensRef.current.map( ( item ) =>
						item.id === id
							? {
									...item,
									expires_at: data.expires_at,
									expired: false,
									status: 'active',
							  }
							: item
					);
					setTokens( tokensRef.current );
					notify(
						'success',
						sprintf(
							/* translators: %s: New preview-link expiry date and time. */
							__(
								'Access extended by 24 hours. New expiry: %s.',
								'previewshare'
							),
							formatDate( data.expires_at )
						)
					);
					refreshAfterMutation();
				} )
				.catch( () => {
					notify(
						'error',
						__(
							'Preview link could not be extended. Its expiry is unchanged.',
							'previewshare'
						)
					);
				} )
				.finally( () => setWorkingTokenId( '' ) );
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

		function renderTabIntro( title, description, showRestore = false ) {
			return el(
				'div',
				{ className: 'previewshare-tab-intro' },
				el(
					'div',
					{ className: 'previewshare-tab-intro-copy' },
					el( 'h2', null, title ),
					el( 'p', null, description )
				),
				showRestore
					? el(
							Button,
							{
								variant: 'secondary',
								onClick: restoreDefaults,
							},
							__( 'Restore defaults', 'previewshare' )
					  )
					: null
			);
		}

		function navigateToTab( tabName ) {
			setActiveTab( tabName );
			const tab = document.getElementById(
				'previewshare-tab-' + tabName
			);
			if ( tab ) {
				tab.focus();
			}
		}

		function renderInventoryStale( allowRefresh = false ) {
			return inventoryStale || allowRefresh
				? el(
						'div',
						{
							className: 'previewshare-inventory-continuation',
							role: inventoryStale ? 'status' : undefined,
						},
						inventoryStale
							? el(
									'p',
									{ className: 'previewshare-inline-note' },
									__(
										'The loaded list may be out of date. Refresh it before loading older links.',
										'previewshare'
									)
							  )
							: null,
						el(
							Button,
							{
								variant: 'secondary',
								isBusy: loadingTokens || loadingMoreTokens,
								'aria-disabled':
									loadingTokens || loadingMoreTokens,
								onClick: refreshInventory,
							},
							__( 'Refresh preview links', 'previewshare' )
						)
				  )
				: null;
		}

		function renderOverview() {
			const activeTokens = tokens.filter(
				( token ) => token.status === 'active'
			);
			const enabledPostTypes = settings.post_types || [];
			const availablePostTypes = settings.available_post_types || {};
			// Keep both gettext calls extractable from the minified bundle.
			const activeLinkLabels = {
				loaded: __( 'Loaded active links', 'previewshare' ),
				total: __( 'Active links', 'previewshare' ),
			};

			return el(
				'div',
				{ className: 'previewshare-tab-content previewshare-overview' },
				renderInventoryStale(),
				el(
					'div',
					{ className: 'previewshare-tab-intro' },
					el(
						'div',
						{ className: 'previewshare-tab-intro-copy' },
						el( 'h2', null, __( 'Overview', 'previewshare' ) ),
						el(
							'p',
							null,
							__(
								'Manage your preview links and site defaults in one place.',
								'previewshare'
							)
						)
					),
					el(
						Button,
						{
							variant: 'primary',
							onClick: () => navigateToTab( 'previews' ),
						},
						__( 'View preview links', 'previewshare' )
					)
				),
				el(
					'div',
					{ className: 'previewshare-metrics' },
					el( MetricCard, {
						label: activeLinkLabels[
							hasMoreTokens ? 'loaded' : 'total'
						],
						value: activeTokens.length,
						icon: link,
						description: __(
							'Create preview links from the content editor.',
							'previewshare'
						),
					} ),
					el( MetricCard, {
						label: __( 'Default expiry', 'previewshare' ),
						value: formatHours( settings.default_ttl_hours ),
						icon: timeToRead,
						description: __(
							'Used when you create a new preview link.',
							'previewshare'
						),
					} ),
					el( MetricCard, {
						label: __( 'Token cache', 'previewshare' ),
						value: settings.enable_caching
							? __( 'Enabled', 'previewshare' )
							: __( 'Disabled', 'previewshare' ),
						icon: grid,
						description: __(
							'Controls caching for preview token lookups.',
							'previewshare'
						),
					} )
				),
				hasMoreTokens
					? el(
							'p',
							{ className: 'previewshare-inline-note' },
							sprintf(
								/* translators: 1: Number of loaded links. 2: Total links. */
								__(
									'%1$d of %2$d links loaded.',
									'previewshare'
								),
								tokens.length,
								totalTokens
							)
					  )
					: null,
				el(
					'div',
					{ className: 'previewshare-overview-layout' },
					el(
						'section',
						{
							className:
								'previewshare-overview-panel previewshare-site-defaults',
							'aria-labelledby': 'previewshare-defaults-title',
						},
						el(
							'div',
							{ className: 'previewshare-panel-heading' },
							el(
								'h3',
								{ id: 'previewshare-defaults-title' },
								__( 'Site defaults', 'previewshare' )
							),
							el(
								'p',
								null,
								__(
									'Changes save automatically after each field update.',
									'previewshare'
								)
							)
						),
						el(
							'div',
							{
								className:
									'previewshare-setting-row previewshare-expiry-setting',
							},
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
										Math.max(
											0,
											parseInt( value, 10 ) || 0
										)
									),
							} )
						),
						el(
							'div',
							{ className: 'previewshare-setting-row' },
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
							} )
						),
						el(
							'div',
							{ className: 'previewshare-setting-row' },
							el( ToggleControl, {
								label: __(
									'Enable diagnostic logging',
									'previewshare'
								),
								checked: settings.enable_logging,
								help: __(
									'Enable this while troubleshooting. Diagnostic events are made available to site integrations; PreviewShare does not store or display a log here.',
									'previewshare'
								),
								onChange: ( checked ) =>
									updateSetting( 'enable_logging', checked ),
							} )
						),
						renderSaveActions()
					),
					el(
						'div',
						{ className: 'previewshare-overview-sidebar' },
						el(
							'section',
							{
								className: 'previewshare-overview-panel',
								'aria-labelledby':
									'previewshare-content-types-title',
							},
							el(
								'div',
								{ className: 'previewshare-panel-heading' },
								el(
									'h3',
									{ id: 'previewshare-content-types-title' },
									__(
										'Enabled content types',
										'previewshare'
									),
									el(
										'span',
										{
											className:
												'previewshare-count-badge',
											'aria-hidden': true,
										},
										enabledPostTypes.length
									)
								),
								el(
									'p',
									null,
									__(
										'Choose which public content can receive a preview link.',
										'previewshare'
									)
								)
							),
							enabledPostTypes.length
								? el(
										'ul',
										{
											className:
												'previewshare-overview-list',
										},
										...enabledPostTypes.map( ( postType ) =>
											el(
												'li',
												{ key: postType },
												getPostTypeLabel(
													postType,
													availablePostTypes
												)
											)
										)
								  )
								: el(
										'p',
										{
											className:
												'previewshare-empty-state',
										},
										__(
											'No content types are enabled.',
											'previewshare'
										)
								  ),
							el(
								Button,
								{
									variant: 'secondary',
									onClick: () =>
										navigateToTab( 'content-types' ),
								},
								__( 'Manage content types', 'previewshare' )
							)
						),
						el(
							'section',
							{
								className: 'previewshare-overview-panel',
								'aria-labelledby':
									'previewshare-protection-title',
							},
							el(
								'div',
								{ className: 'previewshare-panel-heading' },
								el(
									'h3',
									{ id: 'previewshare-protection-title' },
									__( 'Protection summary', 'previewshare' )
								),
								el(
									'p',
									null,
									__(
										'Preview links grant read-only access to unpublished items without requiring an account.',
										'previewshare'
									)
								)
							),
							el(
								'ul',
								{ className: 'previewshare-fact-list' },
								...[
									__(
										'Tokens are stored as hashes',
										'previewshare'
									),
									__(
										'Search engines are asked not to index shared previews',
										'previewshare'
									),
									__(
										'Revoke access at any time',
										'previewshare'
									),
								].map( ( item ) =>
									el(
										'li',
										{ key: item },
										el( Icon, { icon: check, size: 18 } ),
										el( 'span', null, item )
									)
								)
							)
						)
					)
				),
				el(
					'div',
					{ className: 'previewshare-overview-reset' },
					el(
						'p',
						null,
						__(
							'Restore the original defaults for all PreviewShare settings.',
							'previewshare'
						)
					),
					el(
						Button,
						{ variant: 'tertiary', onClick: restoreDefaults },
						__( 'Restore defaults', 'previewshare' )
					)
				)
			);
		}

		function renderMorePlugins() {
			return el(
				'div',
				{ className: 'previewshare-tab-content' },
				renderTabIntro(
					__( 'More plugins', 'previewshare' ),
					__(
						'Explore more tools from the PreviewShare team.',
						'previewshare'
					)
				),
				el(
					'div',
					{ className: 'previewshare-plugin-grid' },
					...pluginCatalog.map( ( plugin ) =>
						el(
							'article',
							{
								className: 'previewshare-plugin-card',
								key: plugin.name,
							},
							el( 'img', {
								className: 'previewshare-plugin-card-mark',
								src: localized.plugin_icons_url + plugin.icon,
								alt: '',
								width: 48,
								height: 48,
								loading: 'lazy',
								decoding: 'async',
							} ),
							el( 'h3', null, plugin.name ),
							el( 'p', null, plugin.description ),
							el(
								ExternalLink,
								{
									href: plugin.url,
									icon: arrowRight,
									ariaLabel: sprintf(
										/* translators: %s: Plugin name. */
										__(
											'Learn more about %s',
											'previewshare'
										),
										plugin.name
									),
								},
								__( 'Learn more', 'previewshare' )
							)
						)
					)
				)
			);
		}

		function renderLegacyLinkTable( items ) {
			const selectedStatuses = getFilterValues(
				( view.filters || [] ).find(
					( filter ) => filter.field === 'status'
				)
			);
			const selectedPostTypes = getFilterValues(
				( view.filters || [] ).find(
					( filter ) => filter.field === 'post_type'
				)
			);
			const availablePostTypes = settings.available_post_types || {};
			const sortedItems = [ ...items ];
			const sort = view.sort || {};
			const sortField = getTokenFields(
				handleExtend,
				workingTokenId,
				tokens
			).find( ( field ) => field.id === sort.field );
			const getAriaSort = ( field ) => {
				if ( ! field || sort.field !== field ) {
					return 'none';
				}

				return 'asc' === sort.direction ? 'ascending' : 'descending';
			};
			if ( sortField ) {
				sortedItems.sort( ( left, right ) => {
					const leftValue = sortField.getValue( { item: left } );
					const rightValue = sortField.getValue( { item: right } );
					const comparison =
						'integer' === sortField.type
							? Number( leftValue ) - Number( rightValue )
							: String( leftValue ?? '' ).localeCompare(
									String( rightValue ?? '' )
							  );
					return 'asc' === sort.direction ? comparison : -comparison;
				} );
			}
			const perPage = Math.max( 1, Number( view.perPage ) || 20 );
			const pageSizes = [ ...new Set( [ 20, 50, 100, perPage ] ) ].sort(
				( left, right ) => left - right
			);
			const totalPages = Math.max(
				1,
				Math.ceil( sortedItems.length / perPage )
			);
			const currentPage = Math.min(
				Math.max( 1, Number( view.page ) || 1 ),
				totalPages
			);
			const pageItems = sortedItems.slice(
				( currentPage - 1 ) * perPage,
				currentPage * perPage
			);
			const renderCell = ( label, content, className ) =>
				el( 'td', { className, 'data-label': label }, content );

			return el(
				'div',
				{ className: 'previewshare-legacy-inventory' },
				el(
					'div',
					{ className: 'previewshare-legacy-toolbar' },
					el( TextControl, {
						label: __( 'Search preview links', 'previewshare' ),
						value: view.search || '',
						placeholder: __(
							'Search by content or label',
							'previewshare'
						),
						onChange: ( search ) =>
							setView( ( current ) => ( {
								...current,
								search,
								page: 1,
							} ) ),
					} ),
					el(
						'fieldset',
						{ className: 'previewshare-status-filters' },
						el( 'legend', null, __( 'Status', 'previewshare' ) ),
						...[
							{
								label: __( 'Active', 'previewshare' ),
								value: 'active',
							},
							{
								label: __( 'Expiring soon', 'previewshare' ),
								value: 'expiring_soon',
							},
							{
								label: __( 'Expired', 'previewshare' ),
								value: 'expired',
							},
							{
								label: __( 'Revoked', 'previewshare' ),
								value: 'revoked',
							},
						].map( ( option ) =>
							el( CheckboxControl, {
								key: option.value,
								label: option.label,
								checked: selectedStatuses.includes(
									option.value
								),
								onChange: ( checked ) => {
									setView( ( current ) => {
										const currentFilters =
											current.filters || [];
										const statusFilter =
											currentFilters.find(
												( filter ) =>
													filter.field === 'status'
											);
										const statuses = getFilterValues(
											statusFilter
										).filter(
											( status ) =>
												status !== option.value
										);
										if ( checked ) {
											statuses.push( option.value );
										}

										return {
											...current,
											filters: [
												...currentFilters.filter(
													( filter ) =>
														filter.field !==
														'status'
												),
												...( statuses.length
													? [
															{
																field: 'status',
																operator:
																	'isAny',
																value: statuses,
															},
													  ]
													: [] ),
											],
											page: 1,
										};
									} );
								},
							} )
						)
					),
					el(
						'fieldset',
						{ className: 'previewshare-status-filters' },
						el(
							'legend',
							null,
							__( 'Content type', 'previewshare' )
						),
						...Object.entries( availablePostTypes ).map(
							( [ postType, label ] ) =>
								el( CheckboxControl, {
									key: postType,
									label,
									checked:
										selectedPostTypes.includes( postType ),
									onChange: ( checked ) =>
										setView( ( current ) => {
											const currentFilters =
												current.filters || [];
											const currentPostTypes =
												getFilterValues(
													currentFilters.find(
														( filter ) =>
															filter.field ===
															'post_type'
													)
												).filter(
													( value ) =>
														value !== postType
												);
											if ( checked ) {
												currentPostTypes.push(
													postType
												);
											}
											return {
												...current,
												filters: [
													...currentFilters.filter(
														( filter ) =>
															filter.field !==
															'post_type'
													),
													...( currentPostTypes.length
														? [
																{
																	field: 'post_type',
																	operator:
																		'isAny',
																	value: currentPostTypes,
																},
														  ]
														: [] ),
												],
												page: 1,
											};
										} ),
								} )
						)
					)
				),
				loadingTokens
					? el(
							'div',
							{ className: 'previewshare-loading' },
							el( Spinner )
					  )
					: ( pageItems.length > 0 &&
							el(
								'div',
								{ className: 'previewshare-legacy-table-wrap' },
								el(
									'table',
									{
										className:
											'previewshare-legacy-link-table',
										'aria-label': sortField
											? sprintf(
													/* translators: 1: Sort field. 2: Sort direction. */
													__(
														'Preview link inventory, sorted by %1$s %2$s',
														'previewshare'
													),
													sortField.label,
													'asc' === sort.direction
														? __(
																'ascending',
																'previewshare'
														  )
														: __(
																'descending',
																'previewshare'
														  )
											  )
											: __(
													'Preview link inventory',
													'previewshare'
											  ),
									},
									el(
										'thead',
										null,
										el(
											'tr',
											null,
											...[
												{
													field: 'content',
													label: __(
														'Content',
														'previewshare'
													),
												},
												{
													field: 'label',
													label: __(
														'Label',
														'previewshare'
													),
												},
												{
													field: 'status',
													label: __(
														'Status',
														'previewshare'
													),
												},
												{
													field: 'view_count',
													label: __(
														'Views',
														'previewshare'
													),
												},
												{
													field: 'expires_at',
													label: __(
														'Expires',
														'previewshare'
													),
												},
												{
													field: 'last_viewed_at',
													label: __(
														'Last viewed',
														'previewshare'
													),
												},
												{
													field: null,
													label: __(
														'Actions',
														'previewshare'
													),
												},
											].map( ( { field, label } ) =>
												el(
													'th',
													{
														key: label,
														scope: 'col',
														...( field
															? {
																	'aria-sort':
																		getAriaSort(
																			field
																		),
															  }
															: {} ),
													},
													label
												)
											)
										)
									),
									el(
										'tbody',
										null,
										...pageItems.map( ( item ) => {
											const title =
												item.post_title ||
												__(
													'Untitled content',
													'previewshare'
												);
											const meta = sprintf(
												/* translators: %d: Post ID. */
												__( '#%d', 'previewshare' ),
												Number( item.post_id ) || 0
											);
											const expiringSoon =
												isExpiringSoon( item );
											const status = expiringSoon
												? 'expiring_soon'
												: item.status || 'unknown';

											return el(
												'tr',
												{ key: item.id },
												renderCell(
													__(
														'Content',
														'previewshare'
													),
													el(
														'div',
														{
															className:
																'previewshare-content-cell',
														},
														item.edit_url
															? el(
																	'a',
																	{
																		href: item.edit_url,
																	},
																	title
															  )
															: el(
																	'span',
																	null,
																	title
															  ),
														el(
															'small',
															null,
															meta
														)
													)
												),
												renderCell(
													__(
														'Label',
														'previewshare'
													),
													formatPreviewLabel(
														item,
														localized
													)
												),
												renderCell(
													__(
														'Status',
														'previewshare'
													),
													el(
														'div',
														{
															className:
																'previewshare-link-status-cell',
														},
														el( StatusBadge, {
															status,
														} ),
														item.review_state
															? el(
																	'span',
																	{
																		className:
																			'previewshare-review-state',
																	},
																	reviewStateLabel(
																		item.review_state
																	)
															  )
															: null,
														expiringSoon
															? el(
																	Button,
																	{
																		variant:
																			'tertiary',
																		isBusy:
																			workingTokenId ===
																			item.id,
																		'aria-label':
																			extendLinkAriaLabel(
																				item,
																				tokens
																			),
																		disabled:
																			Boolean(
																				workingTokenId
																			),
																		onClick:
																			() =>
																				handleExtend(
																					item.id
																				),
																	},
																	__(
																		'Extend',
																		'previewshare'
																	)
															  )
															: null
													)
												),
												renderCell(
													__(
														'Views',
														'previewshare'
													),
													Number( item.view_count ) ||
														0
												),
												renderCell(
													__(
														'Expires',
														'previewshare'
													),
													formatDate(
														item.expires_at
													)
												),
												renderCell(
													__(
														'Last viewed',
														'previewshare'
													),
													formatDate(
														item.last_viewed_at
													)
												),
												renderCell(
													__(
														'Actions',
														'previewshare'
													),
													el(
														'div',
														{
															className:
																'previewshare-legacy-row-actions',
														},
														...getInventoryActions()
															.filter(
																( action ) =>
																	! action.isEligible ||
																	action.isEligible(
																		item
																	)
															)
															.map( ( action ) =>
																el(
																	Button,
																	{
																		key: action.id,
																		variant:
																			'tertiary',
																		isDestructive:
																			action.id ===
																			'delete',
																		disabled:
																			action.disabled,
																		onClick:
																			() =>
																				action.callback(
																					[
																						item,
																					]
																				),
																	},
																	action.label
																)
															)
													),
													'previewshare-actions-cell'
												)
											);
										} )
									)
								)
							) ) ||
							el(
								'p',
								{ className: 'previewshare-empty-state' },
								__(
									'No preview links match this view.',
									'previewshare'
								)
							),
				! loadingTokens
					? el(
							'div',
							{ className: 'previewshare-legacy-pagination' },
							el(
								'span',
								null,
								sprintf(
									/* translators: 1: Current page. 2: Number of pages. */
									__( 'Page %1$d of %2$d', 'previewshare' ),
									currentPage,
									totalPages
								)
							),
							el(
								'label',
								{
									className: 'previewshare-legacy-page-size',
								},
								__( 'Links per page', 'previewshare' ),
								el(
									'select',
									{
										'aria-label': __(
											'Links per page',
											'previewshare'
										),
										value: String( perPage ),
										onChange: ( event ) =>
											setView( ( current ) => ( {
												...current,
												page: 1,
												perPage: Number(
													event.target.value
												),
											} ) ),
									},
									...pageSizes.map( ( size ) =>
										el(
											'option',
											{
												key: size,
												value: String( size ),
											},
											size
										)
									)
								)
							),
							el(
								Button,
								{
									variant: 'secondary',
									disabled: currentPage <= 1,
									onClick: () =>
										setView( ( current ) => ( {
											...current,
											page: currentPage - 1,
										} ) ),
								},
								__( 'Previous', 'previewshare' )
							),
							el(
								Button,
								{
									variant: 'secondary',
									disabled: currentPage >= totalPages,
									onClick: () =>
										setView( ( current ) => ( {
											...current,
											page: currentPage + 1,
										} ) ),
								},
								__( 'Next', 'previewshare' )
							)
					  )
					: null
			);
		}

		function renderPreviews() {
			if (
				! compactInventory &&
				typeof wp.element.useInsertionEffect === 'function' &&
				! dataViewsModule &&
				! dataViewsFailed
			) {
				return el(
					'div',
					{ className: 'previewshare-loading', role: 'status' },
					el( Spinner ),
					__( 'Loading preview links…', 'previewshare' )
				);
			}
			const { DataViews, filterSortAndPaginate } = dataViewsModule || {};
			const fields = getTokenFields(
				handleExtend,
				workingTokenId,
				tokens,
				settings.available_post_types
			);
			const canRenderDataViews = Boolean(
				DataViews &&
					! compactInventory &&
					typeof wp.element.useInsertionEffect === 'function'
			);
			const processed = canRenderDataViews
				? filterSortAndPaginate( tokens, view, fields )
				: null;
			let legacyTokens = null;
			if ( ! canRenderDataViews ) {
				const normalizedSearch = normalizeSearchInput(
					view.search || ''
				);
				const searchableFields = fields.filter(
					( field ) => field.enableGlobalSearch
				);
				const statusFilter = ( view.filters || [] ).find(
					( filter ) => filter.field === 'status'
				);
				const selectedStatuses = getFilterValues( statusFilter );
				const selectedPostTypes = getFilterValues(
					( view.filters || [] ).find(
						( filter ) => filter.field === 'post_type'
					)
				);
				legacyTokens = tokens.filter( ( token ) => {
					const actualStatus = isExpiringSoon( token )
						? 'expiring_soon'
						: token.status;
					const statusMatches =
						selectedStatuses.length === 0 ||
						selectedStatuses.includes( actualStatus );
					const postTypeMatches =
						selectedPostTypes.length === 0 ||
						selectedPostTypes.includes( token.post_type );
					const searchMatches =
						! normalizedSearch ||
						searchableFields.some( ( field ) =>
							normalizeSearchInput(
								String( field.getValue( { item: token } ) )
							).includes( normalizedSearch )
						);

					return statusMatches && postTypeMatches && searchMatches;
				} );
			}
			const actions = getInventoryActions();

			return el(
				'div',
				{ className: 'previewshare-tab-content' },
				renderTabIntro(
					__( 'Preview links', 'previewshare' ),
					__(
						'Manage preview links, monitor views and expiry, and revoke access when a review is complete.',
						'previewshare'
					)
				),
				renderInventoryStale( true ),
				legacyTokens
					? renderLegacyLinkTable( legacyTokens )
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
								isLoading: loadingTokens || loadingMoreTokens,
								getItemId: ( item ) => item.id,
								searchLabel: __(
									'Search preview links',
									'previewshare'
								),
								empty: el(
									'p',
									{
										className: 'previewshare-empty-state',
									},
									__(
										'No preview links match this view.',
										'previewshare'
									)
								),
							} )
					  ),
				hasMoreTokens && ! inventoryStale
					? el(
							'div',
							{
								className:
									'previewshare-inventory-continuation',
							},
							el(
								'p',
								{ className: 'previewshare-inline-note' },
								sprintf(
									/* translators: 1: Number of loaded links. 2: Total links. */
									__(
										'%1$d of %2$d links loaded. Load more to search older links.',
										'previewshare'
									),
									tokens.length,
									totalTokens
								)
							),
							el(
								Button,
								{
									variant: 'secondary',
									isBusy: loadingMoreTokens,
									disabled:
										loadingMoreTokens || loadingTokens,
									onClick: () =>
										fetchTokens( { append: true } ),
								},
								__( 'Load more links', 'previewshare' )
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
			const labels = postTypeKeys.map( ( key ) =>
				getPostTypeLabel( key, availablePostTypes )
			);
			const visiblePostTypes = postTypeKeys.filter(
				( postType ) =>
					! normalizedSearch ||
					postType.toLowerCase().includes( normalizedSearch ) ||
					availablePostTypes[ postType ]
						.toLowerCase()
						.includes( normalizedSearch )
			);

			return el(
				'div',
				{
					className:
						'previewshare-tab-content previewshare-content-types',
				},
				renderTabIntro(
					__( 'Content types', 'previewshare' ),
					__(
						'Choose where editors can create preview links. Changes save automatically.',
						'previewshare'
					)
				),
				el(
					'div',
					{ className: 'previewshare-content-type-panel' },
					postTypeKeys.length > 6
						? el( SearchControl, {
								className: 'previewshare-content-type-search',
								label: __(
									'Search content types',
									'previewshare'
								),
								placeholder: __(
									'Search content types',
									'previewshare'
								),
								value: contentTypeSearch,
								onChange: setContentTypeSearch,
						  } )
						: null,
					el(
						'div',
						{
							className: 'previewshare-content-type-list',
							role: 'group',
							'aria-label': __( 'Content types', 'previewshare' ),
						},
						visiblePostTypes.length
							? visiblePostTypes.map( ( postType ) => {
									const label = getPostTypeLabel(
										postType,
										availablePostTypes
									);
									const duplicateLabel =
										labels.filter(
											( name ) => name === label
										).length > 1;
									return el( CheckboxControl, {
										key: postType,
										className:
											'previewshare-content-type-row',
										label: duplicateLabel
											? sprintf(
													/* translators: 1: Content type label. 2: Unique content type name. */
													__(
														'%1$s (%2$s)',
														'previewshare'
													),
													label,
													postType
											  )
											: label,
										checked:
											selectedPostTypes.includes(
												postType
											),
										onChange: ( checked ) => {
											const currentPostTypes =
												latestSettingsRef.current
													.post_types;
											updateSetting(
												'post_types',
												checked
													? currentPostTypes.concat(
															postType
													  )
													: currentPostTypes.filter(
															( item ) =>
																item !==
																postType
													  )
											);
										},
									} );
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
						renderSaveState(),
						saveState === 'error'
							? el(
									Button,
									{
										variant: 'secondary',
										onClick: saveAllSettings,
									},
									__( 'Retry save', 'previewshare' )
							  )
							: null,
						selectedPostTypes.length === 0
							? el(
									'p',
									{ className: 'previewshare-warning' },
									__(
										'PreviewShare is disabled until at least one content type is selected.',
										'previewshare'
									)
							  )
							: null
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
				content = el( Changelog, {
					wp,
					installedVersion: localized.version,
					intro: renderTabIntro(
						__( 'Changelog', 'previewshare' ),
						__(
							'Updates, improvements, and fixes in each version.',
							'previewshare'
						)
					),
				} );
			} else if ( activeTab === 'more-plugins' ) {
				content = renderMorePlugins();
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
				'div',
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
							'v' + ( localized.version || '1.1.0' )
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
							icon: page,
							iconBefore: true,
						},
						__( 'Documentation', 'previewshare' )
					),
					el(
						ExternalLink,
						{
							href:
								localized.support_url ||
								'https://wordpress.org/support/plugin/previewshare/',
							icon: help,
							iconBefore: true,
						},
						__( 'Support', 'previewshare' )
					)
				)
			),
			el(
				'nav',
				{
					className: 'previewshare-tabs',
					ref: tabListRef,
					role: 'tablist',
					'aria-label': __( 'PreviewShare settings', 'previewshare' ),
				},
				tabDefinitions.map( ( tab ) =>
					el(
						'button',
						{
							id: 'previewshare-tab-' + tab.name,
							key: tab.name,
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
			el( NoticeRegion, { notices, clearNotice, Notice } ),
			loadingSettings
				? el(
						'div',
						{ className: 'previewshare-loading' },
						el( Spinner )
				  )
				: renderTabPanel(),
			renderDeleteConfirmation()
		);
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		const mount = document.getElementById( 'previewshare-settings-app' );

		if ( mount ) {
			render( el( SettingsApp ), mount );
		}
	} );
} )( window.wp );
