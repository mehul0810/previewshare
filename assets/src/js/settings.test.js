import * as element from '@wordpress/element';
import * as date from '@wordpress/date';

// eslint-disable-next-line import/no-extraneous-dependencies
const { Simulate } = require( 'react-dom/test-utils' );
// eslint-disable-next-line import/no-extraneous-dependencies
const { act } = require( 'react' );
// eslint-disable-next-line import/no-extraneous-dependencies
const { createRoot } = require( 'react-dom/client' );

jest.mock(
	require
		.resolve( '@wordpress/dataviews/package.json' )
		.replace(
			'package.json',
			'build/components/dataform-controls/index.cjs'
		),
	() => ( { getControl: () => () => null } )
);

jest.mock( '@wordpress/dataviews/wp', () => {
	const { createElement } = require( '@wordpress/element' );
	// Exercise the installed data pipeline without loading its UI entry point.
	const { default: filterSortAndPaginate } = jest.requireActual(
		require
			.resolve( '@wordpress/dataviews/package.json' )
			.replace(
				'package.json',
				'build/utils/filter-sort-and-paginate.cjs'
			)
	);
	const DataViews = ( props ) => {
		const { data = [], fields = [], view = {}, children } = props;
		globalThis.__previewshareDataViewsProps = props;
		return createElement(
			'div',
			{
				'data-testid': 'previewshare-dataviews',
				'data-title-field': view.titleField,
				'data-fields': ( view.fields || [] ).join( ',' ),
			},
			children ||
				data.map( ( item ) =>
					createElement(
						'div',
						{ key: item.id },
						item.id,
						...fields
							.filter(
								( field ) =>
									field.id === view.titleField ||
									( view.fields || [] ).includes( field.id )
							)
							.map( ( field ) =>
								createElement(
									'span',
									{ key: field.id },
									field.render
										? field.render( { item } )
										: field.getValue( { item } )
								)
							)
					)
				)
		);
	};
	DataViews.Search = () =>
		createElement( 'div', { 'data-testid': 'native-search' } );
	DataViews.Filters = () =>
		createElement( 'div', { 'data-testid': 'native-filters' } );
	DataViews.Pagination = () =>
		createElement( 'div', { 'data-testid': 'native-pagination' } );
	return { DataViews, filterSortAndPaginate };
} );

function response( data, ok = true ) {
	return {
		ok,
		json: () => Promise.resolve( data ),
	};
}

function deferred() {
	let resolve;
	let reject;
	const promise = new Promise( ( resolvePromise, rejectPromise ) => {
		resolve = resolvePromise;
		reject = rejectPromise;
	} );

	return { promise, resolve, reject };
}

function flushPromises() {
	return Promise.resolve()
		.then( () => Promise.resolve() )
		.then( () => Promise.resolve() )
		.then( () => Promise.resolve() );
}

function setupWordPressMocks() {
	const { createElement } = element;
	let root;
	const Button = ( {
		children,
		onClick,
		disabled,
		isBusy,
		'aria-label': ariaLabel,
	} ) =>
		createElement(
			'button',
			{
				type: 'button',
				onClick,
				disabled: disabled || isBusy,
				'aria-label': ariaLabel,
			},
			children
		);
	const TextControl = ( { label, value, onChange, type = 'text' } ) =>
		createElement(
			'label',
			null,
			label,
			createElement( 'input', {
				'aria-label': label,
				type,
				value,
				onChange: ( event ) => onChange( event.target.value ),
			} )
		);
	const ToggleControl = ( { label, checked, onChange } ) =>
		createElement(
			'label',
			null,
			label,
			createElement( 'input', {
				'aria-label': label,
				type: 'checkbox',
				checked,
				onChange: ( event ) => onChange( event.target.checked ),
			} )
		);
	const CheckboxControl = ( { label, checked, onChange } ) =>
		createElement(
			'label',
			null,
			label,
			createElement( 'input', {
				'aria-label': label,
				type: 'checkbox',
				checked,
				onChange: ( event ) => onChange( event.target.checked ),
			} )
		);
	const Notice = ( { children, className } ) =>
		createElement( 'div', { className }, children );

	window.wp = {
		element: {
			...element,
			render: ( component, mount ) => {
				if ( root ) {
					root.unmount();
				}

				root = createRoot( mount );
				root.render( component );
			},
		},
		i18n: {
			__: ( text ) => text,
			_n: ( singular, plural, count ) =>
				count === 1 ? singular : plural,
			sprintf: ( format, ...args ) =>
				format.replace( /%(\d+\$)?[sd]/g, ( match, position ) => {
					const index = position
						? Number.parseInt( position, 10 ) - 1
						: 0;
					return String( args[ index ] );
				} ),
		},
		date,
		components: {
			Button,
			Modal: ( { title, children, onRequestClose } ) =>
				createElement(
					'div',
					{ role: 'dialog', 'aria-label': title },
					createElement(
						'button',
						{ onClick: onRequestClose },
						'Close'
					),
					children
				),
			Icon: () => createElement( 'span' ),
			Notice,
			CheckboxControl,
			SearchControl: TextControl,
			Spinner: () => createElement( 'span' ),
			TextControl,
			ToggleControl,
		},
	};
	window.__previewshareTestUnmount = () => {
		if ( root ) {
			root.unmount();
			root = null;
		}
	};
}

function setupFetch( settingsOverride = null, initialItems = [] ) {
	const posts = [];
	const tokenItems = initialItems.map( ( item ) => ( { ...item } ) );
	const initialSettings = settingsOverride || {
		default_ttl_hours: 6,
		enable_logging: false,
		enable_caching: true,
		post_types: [ 'post' ],
		available_post_types: { post: 'Post', page: 'Page' },
		defaults: {
			default_ttl_hours: 6,
			enable_logging: false,
			enable_caching: true,
			post_types: [ 'post' ],
		},
	};

	window.fetch = jest.fn( ( url, options = {} ) => {
		if ( options.method === 'POST' ) {
			if ( String( url ).endsWith( '/tokens/revoke' ) ) {
				const { id } = JSON.parse( options.body );
				const item = tokenItems.find( ( token ) => token.id === id );
				if ( ! item ) {
					return Promise.reject( new Error( 'missing link' ) );
				}
				item.status = 'revoked';
				item.revoked = true;
				return Promise.resolve( response( { revoked: true } ) );
			}
			if ( String( url ).endsWith( '/tokens/delete' ) ) {
				const { id } = JSON.parse( options.body );
				const index = tokenItems.findIndex(
					( token ) => token.id === id
				);
				if ( index < 0 ) {
					return Promise.reject( new Error( 'missing link' ) );
				}
				tokenItems.splice( index, 1 );
				return Promise.resolve(
					response( { deleted: true, revoked: true } )
				);
			}
			if ( String( url ).endsWith( '/tokens/extend' ) ) {
				const { id } = JSON.parse( options.body );
				const item = tokenItems.find( ( token ) => token.id === id );
				if ( ! item ) {
					return Promise.reject( new Error( 'missing link' ) );
				}

				item.expires_at += 24 * 60 * 60;
				return Promise.resolve(
					response( { expires_at: item.expires_at } )
				);
			}

			const request = deferred();
			posts.push( {
				body: JSON.parse( options.body ),
				request,
			} );
			return request.promise;
		}

		if ( String( url ).includes( '/tokens' ) ) {
			return Promise.resolve(
				response( { items: tokenItems, total: tokenItems.length } )
			);
		}

		return Promise.resolve( response( initialSettings ) );
	} );

	return { posts, initialSettings };
}

async function mountSettingsApp( initialSettings, dateSettings = {} ) {
	document.body.innerHTML = '<div id="previewshare-settings-app"></div>';
	Object.assign( window.previewshare_settings, {
		version: '1.1.0',
		plugin_icons_url:
			'/wp-content/plugins/previewshare/assets/images/plugins/',
		rest_url: '/wp-json/previewshare/v1',
		nonce: 'test-nonce',
		settings: initialSettings,
		date_settings: dateSettings,
	} );

	await act( async () => {
		document.dispatchEvent( new Event( 'DOMContentLoaded' ) );
		await flushPromises();
	} );
}

function findInput( label ) {
	return document.querySelector( 'input[aria-label="' + label + '"]' );
}

function findButton( label ) {
	return Array.from( document.querySelectorAll( 'button' ) ).find(
		( button ) => button.textContent === label
	);
}

beforeAll( () => {
	setupWordPressMocks();
	globalThis.IS_REACT_ACT_ENVIRONMENT = true;
	window.previewshare_settings = {
		version: '1.1.0',
		plugin_icons_url:
			'/wp-content/plugins/previewshare/assets/images/plugins/',
		rest_url: '/wp-json/previewshare/v1',
		nonce: 'test-nonce',
	};
	// The settings bundle registers its DOMContentLoaded mount listener.
	require( './settings' );
} );

beforeEach( () => {
	jest.useFakeTimers();
	window.history.replaceState( null, '', '/' );
	date.setSettings( {
		...date.getSettings(),
		formats: {
			...date.getSettings().formats,
			date: 'F j, Y',
			time: 'g:i a',
		},
		timezone: { ...date.getSettings().timezone, string: 'UTC', offset: 0 },
	} );
} );

afterEach( () => {
	jest.useFakeTimers();
	jest.runOnlyPendingTimers();
	act( () => window.__previewshareTestUnmount() );
	document.body.innerHTML = '';
	jest.useRealTimers();
	delete window.matchMedia;
	window.wp.element.useInsertionEffect = element.useInsertionEffect;
} );

describe( 'PreviewShare responsive inventory', () => {
	it( 'combines and clears status, content type, and search in the older runtime fallback', async () => {
		window.wp.element.useInsertionEffect = undefined;
		const expiresAt = Math.floor( Date.now() / 1000 ) + 100000;
		const { initialSettings } = setupFetch( null, [
			{
				id: 'active-post',
				post_type: 'post',
				post_title: 'Alpha post',
				status: 'active',
				expires_at: expiresAt,
			},
			{
				id: 'active-page',
				post_type: 'page',
				post_title: 'Alpha page',
				status: 'active',
				expires_at: expiresAt,
			},
			{
				id: 'expired-page',
				post_type: 'page',
				post_title: 'Old alpha page',
				status: 'expired',
				expires_at: expiresAt - 86400,
			},
			{
				id: 'other-page',
				post_type: 'page',
				post_title: 'Beta page',
				status: 'active',
				expires_at: expiresAt,
			},
		] );
		await mountSettingsApp( initialSettings );
		await act( async () => {
			findButton( 'Preview links' ).click();
			await flushPromises();
		} );
		expect(
			document.querySelector( '.previewshare-dataviews' )
		).toBeNull();
		const rows = () =>
			Array.from(
				document.querySelectorAll(
					'.previewshare-legacy-link-table tbody tr'
				)
			).map( ( row ) => row.textContent );
		const filterInput = ( group, label ) => {
			const fieldset = Array.from(
				document.querySelectorAll(
					'.previewshare-legacy-toolbar fieldset'
				)
			).find(
				( item ) => item.querySelector( 'legend' ).textContent === group
			);
			return fieldset.querySelector(
				'input[aria-label="' + label + '"]'
			);
		};
		expect( rows() ).toHaveLength( 4 );
		await act( async () => {
			Simulate.change( filterInput( 'Status', 'Active' ), {
				target: { checked: true },
			} );
			Simulate.change( filterInput( 'Content type', 'Page' ), {
				target: { checked: true },
			} );
			Simulate.change( findInput( 'Search preview links' ), {
				target: { value: 'alpha' },
			} );
			await flushPromises();
		} );
		expect( rows() ).toHaveLength( 1 );
		expect( rows()[ 0 ] ).toContain( 'Alpha page' );
		await act( async () => {
			Simulate.change( filterInput( 'Content type', 'Page' ), {
				target: { checked: false },
			} );
			await flushPromises();
		} );
		expect( rows() ).toHaveLength( 2 );
		await act( async () => {
			Simulate.change( filterInput( 'Status', 'Active' ), {
				target: { checked: false },
			} );
			await flushPromises();
		} );
		expect( rows() ).toHaveLength( 3 );
		await act( async () => {
			Simulate.change( findInput( 'Search preview links' ), {
				target: { value: '' },
			} );
			await flushPromises();
		} );
		expect( rows() ).toHaveLength( 4 );
	} );

	it.each( [ false, true ] )(
		'combines native status and content-type filters in compact mode %s',
		async ( compact ) => {
			window.matchMedia = jest.fn( () => ( {
				matches: compact,
				addEventListener: jest.fn(),
				removeEventListener: jest.fn(),
			} ) );
			const expiresAt = Math.floor( Date.now() / 1000 ) + 3600;
			const { initialSettings } = setupFetch( null, [
				{
					id: 'post-link',
					post_id: 42,
					post_type: 'post',
					post_title: 'Post review',
					status: 'active',
					expires_at: expiresAt,
				},
				{
					id: 'page-link',
					post_id: 43,
					post_type: 'page',
					post_title: 'Page review',
					status: 'active',
					expires_at: expiresAt,
				},
				{
					id: 'expired-page',
					post_id: 44,
					post_type: 'page',
					post_title: 'Old page review',
					status: 'expired',
					expires_at: expiresAt - 86400,
				},
			] );
			await mountSettingsApp( initialSettings );
			await act( async () => {
				findButton( 'Preview links' ).click();
				await flushPromises();
			} );
			const statusField = window.__previewshareDataViewsProps.fields.find(
				( field ) => field.id === 'status'
			);
			expect( statusField.filterBy ).toEqual( {
				operators: [ 'isAny' ],
				isPrimary: true,
			} );
			expect(
				statusField.elements.map( ( option ) => option.value )
			).toEqual( [ 'active', 'expiring_soon', 'expired', 'revoked' ] );
			await act( async () => {
				window.__previewshareDataViewsProps.onChangeView( {
					...window.__previewshareDataViewsProps.view,
					filters: [
						{
							field: 'status',
							operator: 'isAny',
							value: [ 'expiring_soon' ],
						},
						{
							field: 'post_type',
							operator: 'isAny',
							value: [ 'page' ],
						},
					],
				} );
				await flushPromises();
			} );
			expect(
				window.__previewshareDataViewsProps.data.map(
					( item ) => item.id
				)
			).toEqual( [ 'page-link' ] );
			expect( document.body.textContent ).toContain( 'Page review' );
			expect( document.body.textContent ).toContain( '#43' );
			expect( document.body.textContent ).not.toContain( 'Post review' );
			expect( document.body.textContent ).not.toContain(
				'Old page review'
			);
			expect( document.body.textContent ).not.toContain( 'post - ID' );
			expect(
				document.querySelector( '.previewshare-link-quick-filters' )
			).toBeNull();
		}
	);

	it.each( [
		[
			false,
			'd/m/Y',
			'H:i',
			'Asia/Kolkata',
			'15/11/2023 03:43',
			'15/11/2023 04:43',
		],
		[
			true,
			'd/m/Y',
			'H:i',
			'Asia/Kolkata',
			'15/11/2023 03:43',
			'15/11/2023 04:43',
		],
		[
			false,
			'Y-m-d',
			'g:i a',
			'America/New_York',
			'2023-11-14 5:13 pm',
			'2023-11-14 6:13 pm',
		],
		[
			true,
			'Y-m-d',
			'g:i a',
			'America/New_York',
			'2023-11-14 5:13 pm',
			'2023-11-14 6:13 pm',
		],
	] )(
		'uses site date/time formats and timezone in compact mode %s (%s %s, %s)',
		async (
			compact,
			dateFormat,
			timeFormat,
			timezone,
			viewed,
			expires
		) => {
			window.matchMedia = jest.fn( () => ( {
				matches: compact,
				addEventListener: jest.fn(),
				removeEventListener: jest.fn(),
			} ) );
			date.setSettings( {
				...date.getSettings(),
				formats: {
					...date.getSettings().formats,
					date: dateFormat,
					time: timeFormat,
				},
				timezone: { ...date.getSettings().timezone, string: timezone },
			} );
			const { initialSettings } = setupFetch( null, [
				{
					id: 'dated-link',
					post_id: 42,
					post_title: 'Review draft',
					status: 'expired',
					last_viewed_at: 1700000000,
					expires_at: 1700003600,
					created_at: 1700000000,
				},
			] );
			await mountSettingsApp( initialSettings, {
				date_format: dateFormat,
				time_format: timeFormat,
			} );
			await act( async () => {
				findButton( 'Preview links' ).click();
				await flushPromises();
			} );
			expect( document.body.textContent ).toContain( viewed );
			expect( document.body.textContent ).toContain( expires );
			expect( document.body.textContent ).toContain( '#42' );
			const created = window.__previewshareDataViewsProps.fields.find(
				( field ) => field.id === 'created_at'
			);
			expect(
				created.render( { item: { created_at: 1700000000 } } )
			).toBe( viewed );
		}
	);

	it( 'keeps Never for absent or invalid timestamps', async () => {
		const { initialSettings } = setupFetch( null, [
			{
				id: 'undated-link',
				post_id: 42,
				status: 'active',
				expires_at: null,
				last_viewed_at: 'invalid',
			},
		] );
		await mountSettingsApp( initialSettings );
		await act( async () => {
			findButton( 'Preview links' ).click();
			await flushPromises();
		} );
		expect( document.body.textContent.match( /Never/g ) ).toHaveLength( 2 );
	} );

	it( 'exposes every link detail when a modern screen becomes narrow', async () => {
		let narrow = false;
		let onViewportChange;
		window.matchMedia = jest.fn( () => ( {
			get matches() {
				return narrow;
			},
			addEventListener: ( type, callback ) => {
				if ( type === 'change' ) {
					onViewportChange = callback;
				}
			},
			removeEventListener: jest.fn(),
		} ) );
		const { initialSettings } = setupFetch( null, [
			{
				id: 'link-1',
				post_id: 42,
				post_title: 'Review draft',
				post_type: 'post',
				label: 'Client review',
				status: 'active',
				expires_at: 1800000000,
				last_viewed_at: 1700000000,
				view_count: 2,
			},
		] );
		await mountSettingsApp( initialSettings );
		await act( async () => {
			findButton( 'Preview links' ).click();
			await flushPromises();
		} );
		expect(
			document.querySelector( '.previewshare-dataviews' )
		).not.toBeNull();

		await act( async () => {
			narrow = true;
			onViewportChange();
			await flushPromises();
		} );
		expect(
			document.querySelector( '.previewshare-dataviews' )
		).not.toBeNull();
		expect(
			document.querySelector( '[data-testid="native-filters"]' )
		).not.toBeNull();
		expect(
			document.querySelector( '.previewshare-legacy-toolbar' )
		).toBeNull();
		const row = document.querySelector(
			'.previewshare-legacy-link-table tbody tr'
		);
		expect(
			Array.from( row.cells, ( cell ) => cell.dataset.label )
		).toEqual( [
			'Content',
			'Label',
			'Status',
			'Views',
			'Expires',
			'Last viewed',
			'Actions',
		] );
		expect( row.textContent ).toContain( 'Review draft' );
		expect(
			row.querySelector( '.previewshare-content-cell small' ).textContent
		).toBe( '#42' );
		expect( row.textContent ).toContain( 'Client review' );
		expect( document.body.textContent ).toContain(
			'Manage preview links, monitor views and expiry, and revoke access when a review is complete.'
		);
		expect( findButton( 'Revoke link' ) ).toBeDefined();
	} );

	it( 'preserves multi-status filters, sorting, and pagination across resize', async () => {
		let narrow = false;
		let onViewportChange;
		window.matchMedia = jest.fn( () => ( {
			get matches() {
				return narrow;
			},
			addEventListener: ( type, callback ) => {
				if ( type === 'change' ) {
					onViewportChange = callback;
				}
			},
			removeEventListener: jest.fn(),
		} ) );
		const { initialSettings } = setupFetch( null, [
			{
				id: 'expired-z',
				post_id: 42,
				post_title: 'Item 2 review',
				post_type: 'post',
				label: 'Client review',
				status: 'revoked',
				expires_at: 1800000000,
				last_viewed_at: 1700000000,
				view_count: 2,
			},
			{
				id: 'expired-a',
				post_id: 43,
				post_title: 'Item 10 review',
				post_type: 'post',
				label: 'Client review',
				status: 'expired',
				expires_at: 1800000000,
				last_viewed_at: 1700000000,
				view_count: 1,
			},
			{
				id: 'active-1',
				post_id: 44,
				post_title: 'Active review',
				post_type: 'post',
				label: 'Client review',
				status: 'active',
				expires_at: 1800000000,
				last_viewed_at: 1700000000,
				view_count: 3,
			},
		] );
		await mountSettingsApp( initialSettings );
		await act( async () => {
			findButton( 'Preview links' ).click();
			await flushPromises();
		} );

		await act( async () => {
			window.__previewshareDataViewsProps.onChangeView( {
				...window.__previewshareDataViewsProps.view,
				filters: [
					{
						field: 'status',
						operator: 'isAny',
						value: [ 'expired', 'revoked' ],
					},
				],
				sort: { field: 'content', direction: 'asc' },
				page: 1,
				perPage: 1,
			} );
			await flushPromises();
		} );
		await act( async () => {
			narrow = true;
			onViewportChange();
			await flushPromises();
		} );

		const compactRows = Array.from(
			document.querySelectorAll(
				'.previewshare-legacy-link-table tbody tr'
			)
		);
		expect( compactRows ).toHaveLength( 1 );
		expect( compactRows[ 0 ].textContent ).toContain( 'Item 10 review' );
		expect(
			document.querySelector(
				'.previewshare-legacy-link-table th[aria-sort="ascending"]'
			).textContent
		).toBe( 'Content' );
		expect(
			document
				.querySelector( '.previewshare-legacy-link-table' )
				.getAttribute( 'aria-label' )
		).toBe( 'Preview link inventory, sorted by Content ascending' );
		expect(
			window.__previewshareDataViewsProps.view.filters[ 0 ].value
		).toEqual( [ 'expired', 'revoked' ] );
		await act( async () => {
			window.__previewshareDataViewsProps.onChangeView( {
				...window.__previewshareDataViewsProps.view,
				page: 2,
			} );
			await flushPromises();
		} );
		expect(
			document.querySelector( '.previewshare-legacy-link-table tbody tr' )
				.textContent
		).toContain( 'Item 2 review' );

		await act( async () => {
			narrow = false;
			onViewportChange();
			await flushPromises();
		} );
		expect(
			document.querySelector( '.previewshare-dataviews' )
		).not.toBeNull();
		expect( window.__previewshareDataViewsProps.view.filters ).toEqual( [
			{
				field: 'status',
				operator: 'isAny',
				value: [ 'expired', 'revoked' ],
			},
		] );
		expect( window.__previewshareDataViewsProps.view.sort ).toEqual( {
			field: 'content',
			direction: 'asc',
		} );
		expect( window.__previewshareDataViewsProps.view.page ).toBe( 2 );
		expect( window.__previewshareDataViewsProps.view.perPage ).toBe( 1 );
	} );

	it( 'keeps Active separate from Expiring soon across resize', async () => {
		let narrow = false;
		let onViewportChange;
		window.matchMedia = jest.fn( () => ( {
			get matches() {
				return narrow;
			},
			addEventListener: ( type, callback ) => {
				if ( type === 'change' ) {
					onViewportChange = callback;
				}
			},
			removeEventListener: jest.fn(),
		} ) );
		const { initialSettings } = setupFetch( null, [
			{
				id: 'active',
				post_id: 42,
				post_title: 'Active link',
				status: 'active',
				expires_at: Math.floor( Date.now() / 1000 ) + 3 * 86400,
			},
			{
				id: 'expiring',
				post_id: 43,
				post_title: 'Expiring link',
				status: 'active',
				expires_at: Math.floor( Date.now() / 1000 ) + 3600,
			},
		] );
		await mountSettingsApp( initialSettings );
		await act( async () => {
			findButton( 'Preview links' ).click();
			await flushPromises();
		} );
		await act( async () => {
			window.__previewshareDataViewsProps.onChangeView( {
				...window.__previewshareDataViewsProps.view,
				filters: [
					{
						field: 'status',
						operator: 'isAny',
						value: [ 'active' ],
					},
				],
			} );
			await flushPromises();
		} );
		await act( async () => {
			narrow = true;
			onViewportChange();
			await flushPromises();
		} );
		const rows = Array.from(
			document.querySelectorAll(
				'.previewshare-legacy-link-table tbody tr'
			)
		);
		expect( rows ).toHaveLength( 1 );
		expect( rows[ 0 ].textContent ).toContain( 'Active link' );
		expect( rows[ 0 ].textContent ).not.toContain( 'Expiring link' );
		expect(
			window.__previewshareDataViewsProps.view.filters[ 0 ].value
		).toEqual( [ 'active' ] );
		await act( async () => {
			narrow = false;
			onViewportChange();
			await flushPromises();
		} );
		expect( window.__previewshareDataViewsProps.view.filters ).toEqual( [
			{
				field: 'status',
				operator: 'isAny',
				value: [ 'active' ],
			},
		] );
	} );

	it( 'uses DataViews global-search fields and accent normalization in compact mode', async () => {
		let narrow = true;
		let onViewportChange;
		window.matchMedia = jest.fn( () => ( {
			get matches() {
				return narrow;
			},
			addEventListener: ( type, callback ) => {
				if ( type === 'change' ) {
					onViewportChange = callback;
				}
			},
			removeEventListener: jest.fn(),
		} ) );
		const { initialSettings } = setupFetch( null, [
			{
				id: 'token-id-search-must-not-match',
				post_id: 42,
				post_title: 'A review',
				label: 'Søren client',
				status: 'active',
				expires_at: 1800000000,
			},
		] );
		await mountSettingsApp( initialSettings );
		await act( async () => {
			findButton( 'Preview links' ).click();
			await flushPromises();
		} );
		await act( async () => {
			window.__previewshareDataViewsProps.onChangeView( {
				...window.__previewshareDataViewsProps.view,
				search: 'token-id-search-must-not-match',
			} );
			await flushPromises();
		} );
		expect(
			document.querySelectorAll(
				'.previewshare-legacy-link-table tbody tr'
			)
		).toHaveLength( 0 );
		await act( async () => {
			window.__previewshareDataViewsProps.onChangeView( {
				...window.__previewshareDataViewsProps.view,
				search: 'soren',
			} );
			await flushPromises();
		} );
		expect(
			document.querySelector( '.previewshare-legacy-link-table tbody tr' )
				.textContent
		).toContain( 'A review' );
		await act( async () => {
			narrow = false;
			onViewportChange();
			await flushPromises();
		} );
		expect( window.__previewshareDataViewsProps.view.search ).toBe(
			'soren'
		);
	} );
} );

describe( 'PreviewShare inventory row actions', () => {
	const links = () => [
		{
			id: 'selected-link',
			post_id: 42,
			post_title: 'Review draft',
			label: 'Client review',
			status: 'expired',
			expires_at: 1700000000,
		},
		{
			id: 'sibling-link',
			post_id: 42,
			post_title: 'Review draft',
			label: 'Legal approval',
			status: 'active',
			expires_at: null,
		},
	];
	const dialogButton = ( label ) =>
		Array.from(
			document.querySelectorAll( '[role="dialog"] button' )
		).find( ( button ) => button.textContent === label );
	async function openInventory( compact ) {
		window.matchMedia = jest.fn( () => ( {
			matches: compact,
			addEventListener: jest.fn(),
			removeEventListener: jest.fn(),
		} ) );
		const { initialSettings } = setupFetch( null, links() );
		await mountSettingsApp( initialSettings );
		await act( async () => {
			findButton( 'Preview links' ).click();
			await flushPromises();
		} );
	}
	async function invokeAction( actionId, compact ) {
		await act( async () => {
			if ( compact ) {
				findButton(
					actionId === 'revoke' ? 'Revoke link' : 'Delete link'
				).click();
			} else {
				window.__previewshareDataViewsProps.actions
					.find( ( action ) => action.id === actionId )
					.callback( [ links()[ 0 ] ] );
			}
			await flushPromises();
		} );
	}
	it.each( [ false, true ] )(
		'revokes the selected expired link and retains its entry in compact mode %s',
		async ( compact ) => {
			await openInventory( compact );
			const revoke = window.__previewshareDataViewsProps.actions.find(
				( action ) => action.id === 'revoke'
			);
			expect( revoke.isEligible( links()[ 0 ] ) ).toBe( true );
			expect( revoke.isEligible( { status: 'revoked' } ) ).toBe( false );
			await invokeAction( 'revoke', compact );
			const data = window.__previewshareDataViewsProps.data;
			expect( data ).toHaveLength( 2 );
			expect(
				data.find( ( item ) => item.id === 'selected-link' ).status
			).toBe( 'revoked' );
			expect(
				data.find( ( item ) => item.id === 'sibling-link' ).status
			).toBe( 'active' );
			const request = window.fetch.mock.calls.find( ( [ url ] ) =>
				String( url ).endsWith( '/tokens/revoke' )
			);
			expect( JSON.parse( request[ 1 ].body ) ).toEqual( {
				id: 'selected-link',
			} );
			expect( request[ 1 ].headers[ 'X-WP-Nonce' ] ).toBe( 'test-nonce' );
			expect( document.body.textContent ).toContain(
				'Preview link revoked.'
			);
		}
	);
	it.each( [ false, true ] )(
		'requires confirmation and deletes only the chosen entry in compact mode %s',
		async ( compact ) => {
			await openInventory( compact );
			await invokeAction( 'delete', compact );
			expect(
				document.querySelector( '[role="dialog"]' ).textContent
			).toContain( 'permanently remove its entry' );
			expect(
				window.fetch.mock.calls.filter(
					( [ , options ] ) => options.method === 'POST'
				)
			).toHaveLength( 0 );
			await act( async () => {
				dialogButton( 'Cancel' ).click();
				await flushPromises();
			} );
			expect( document.querySelector( '[role="dialog"]' ) ).toBeNull();
			expect( window.__previewshareDataViewsProps.data ).toHaveLength(
				2
			);
			await invokeAction( 'delete', compact );
			await act( async () => {
				dialogButton( 'Delete link' ).click();
				await flushPromises();
			} );
			expect( document.querySelector( '[role="dialog"]' ) ).toBeNull();
			expect(
				window.__previewshareDataViewsProps.data.map(
					( item ) => item.id
				)
			).toEqual( [ 'sibling-link' ] );
			expect( window.__previewshareDataViewsProps.view.page ).toBe( 1 );
			const request = window.fetch.mock.calls.find( ( [ url ] ) =>
				String( url ).endsWith( '/tokens/delete' )
			);
			expect( JSON.parse( request[ 1 ].body ) ).toEqual( {
				id: 'selected-link',
			} );
			expect( request[ 1 ].headers[ 'X-WP-Nonce' ] ).toBe( 'test-nonce' );
			expect( document.body.textContent ).toContain(
				'Preview link revoked and deleted.'
			);
		}
	);
	it.each( [ 'revoke', 'delete' ] )(
		'does not claim success or remove the entry after a false %s response',
		async ( actionId ) => {
			await openInventory( false );
			const defaultFetch = window.fetch;
			window.fetch = jest.fn( ( url, options = {} ) =>
				String( url ).endsWith( '/tokens/' + actionId )
					? Promise.resolve(
							response( { revoked: false, deleted: false } )
					  )
					: defaultFetch( url, options )
			);
			await invokeAction( actionId, false );
			if ( actionId === 'delete' ) {
				await act( async () => {
					dialogButton( 'Delete link' ).click();
					await flushPromises();
				} );
			}
			const errorRegion =
				actionId === 'delete'
					? document.querySelector( '[role="dialog"]' )
					: document.body;
			expect( errorRegion.textContent ).toContain(
				actionId === 'delete'
					? 'Preview link could not be deleted. Please try again.'
					: 'Preview link could not be revoked.'
			);
			expect( window.__previewshareDataViewsProps.data ).toHaveLength(
				2
			);
			expect(
				window.__previewshareDataViewsProps.data.find(
					( item ) => item.id === 'selected-link'
				).status
			).toBe( 'expired' );
			expect( document.body.textContent ).not.toContain(
				'Preview link revoked.'
			);
			expect( document.body.textContent ).not.toContain(
				'Preview link revoked and deleted.'
			);
		}
	);
	it( 'prevents duplicate deletion and modal dismissal while the request is pending', async () => {
		await openInventory( false );
		const defaultFetch = window.fetch;
		const request = deferred();
		window.fetch = jest.fn( ( url, options = {} ) =>
			String( url ).endsWith( '/tokens/delete' )
				? request.promise
				: defaultFetch( url, options )
		);
		await invokeAction( 'delete', false );
		await act( async () => {
			dialogButton( 'Delete link' ).click();
			await flushPromises();
		} );
		expect( dialogButton( 'Delete link' ).disabled ).toBe( true );
		expect( dialogButton( 'Cancel' ).disabled ).toBe( true );
		await act( async () => {
			dialogButton( 'Delete link' ).click();
			dialogButton( 'Close' ).click();
			await flushPromises();
		} );
		expect(
			window.fetch.mock.calls.filter( ( [ url ] ) =>
				String( url ).endsWith( '/tokens/delete' )
			)
		).toHaveLength( 1 );
		expect( document.querySelector( '[role="dialog"]' ) ).not.toBeNull();
		await act( async () => {
			request.resolve( response( {}, false ) );
			await flushPromises();
		} );
		expect( dialogButton( 'Delete link' ).disabled ).toBe( false );
		expect(
			document.querySelector( '[role="dialog"]' ).textContent
		).toContain( 'could not be deleted' );
	} );
} );

describe( 'PreviewShare tab navigation', () => {
	it.each( [
		[ 'Overview', 'overview' ],
		[ 'Preview links', 'previews' ],
		[ 'Content types', 'content-types' ],
		[ 'Changelog', 'changelog' ],
		[ 'More plugins', 'more-plugins' ],
	] )(
		'restores %s after remounting at the same URL',
		async ( label, tab ) => {
			window.history.replaceState(
				{ retained: true },
				'',
				'/wp-admin/options-general.php?page=previewshare_settings&other=value#anchor'
			);
			const { initialSettings } = setupFetch();
			await mountSettingsApp( initialSettings );
			await act( async () => {
				findButton( label ).click();
				await flushPromises();
			} );
			const url = new URL( window.location.href );
			expect( url.searchParams.get( 'tab' ) ).toBe( tab );
			expect( url.searchParams.get( 'page' ) ).toBe(
				'previewshare_settings'
			);
			expect( url.searchParams.get( 'other' ) ).toBe( 'value' );
			expect( url.hash ).toBe( '#anchor' );
			expect( window.history.state ).toEqual( { retained: true } );
			await act( async () => window.__previewshareTestUnmount() );
			await mountSettingsApp( initialSettings );
			expect(
				document.querySelector( '[role="tab"][aria-selected="true"]' )
					.textContent
			).toBe( label );
		}
	);

	it( 'falls back to Overview for an unknown tab', async () => {
		window.history.replaceState( null, '', '/?tab=unknown' );
		const { initialSettings } = setupFetch();
		await mountSettingsApp( initialSettings );
		expect(
			document.querySelector( '[role="tab"][aria-selected="true"]' )
				.textContent
		).toBe( 'Overview' );
		expect(
			new URL( window.location.href ).searchParams.get( 'tab' )
		).toBe( 'overview' );
	} );

	it( 'keeps keyboard and browser-history navigation synchronized with the URL', async () => {
		const { initialSettings } = setupFetch();
		await mountSettingsApp( initialSettings );
		await act( async () => {
			Simulate.keyDown( findButton( 'Overview' ), { key: 'ArrowRight' } );
			await flushPromises();
		} );
		expect(
			new URL( window.location.href ).searchParams.get( 'tab' )
		).toBe( 'previews' );
		await act( async () => {
			window.history.replaceState( null, '', '/?tab=content-types' );
			window.dispatchEvent( new window.PopStateEvent( 'popstate' ) );
			await flushPromises();
		} );
		expect(
			document.querySelector( '[role="tab"][aria-selected="true"]' )
				.textContent
		).toBe( 'Content types' );
	} );
} );

describe( 'PreviewShare content type checklist', () => {
	it( 'autosaves checkbox changes and provides a retry after failure', async () => {
		const { initialSettings, posts } = setupFetch();
		await mountSettingsApp( initialSettings );
		await act( async () => findButton( 'Content types' ).click() );
		expect( findInput( 'Search content types' ) ).toBeNull();
		expect( findButton( 'Save changes' ) ).toBeUndefined();
		await act( async () => {
			Simulate.change( findInput( 'Page' ), {
				target: { checked: true },
			} );
			jest.advanceTimersByTime( 450 );
			await flushPromises();
		} );
		expect( posts[ 0 ].body.post_types ).toEqual( [ 'post', 'page' ] );
		await act( async () => {
			posts[ 0 ].request.resolve( response( {}, false ) );
			await flushPromises();
		} );
		expect( findInput( 'Page' ).checked ).toBe( true );
		expect( findButton( 'Retry save' ) ).toBeDefined();
		await act( async () => {
			findButton( 'Retry save' ).click();
			await flushPromises();
		} );
		expect( posts[ 1 ].body.post_types ).toEqual( [ 'post', 'page' ] );
		await act( async () => {
			posts[ 1 ].request.resolve(
				response( {
					...initialSettings,
					post_types: [ 'post', 'page' ],
				} )
			);
			await flushPromises();
		} );
		expect( findButton( 'Retry save' ) ).toBeUndefined();
		expect(
			document.querySelector( '.previewshare-save-state' ).textContent
		).toContain( 'Saved' );
	} );

	it( 'disambiguates duplicate labels and searches longer lists without changing selections', async () => {
		const { initialSettings } = setupFetch();
		initialSettings.available_post_types = {
			post: 'Post',
			page: 'Page',
			first_case: 'Case Study',
			second_case: 'Case Study',
			product: 'Product',
			service: 'Service',
			note: 'Note',
		};
		await mountSettingsApp( initialSettings );
		await act( async () => findButton( 'Content types' ).click() );
		expect( findInput( 'Case Study (first_case)' ) ).not.toBeNull();
		expect( findInput( 'Case Study (second_case)' ) ).not.toBeNull();
		await act( async () => {
			Simulate.change( findInput( 'Search content types' ), {
				target: { value: 'second_case' },
			} );
		} );
		expect( findInput( 'Case Study (first_case)' ) ).toBeNull();
		expect( findInput( 'Case Study (second_case)' ) ).not.toBeNull();
		await act( async () => {
			Simulate.change( findInput( 'Search content types' ), {
				target: { value: 'nothing matches' },
			} );
		} );
		expect( document.body.textContent ).toContain(
			'No matching content types found.'
		);
		await act( async () => {
			Simulate.change( findInput( 'Search content types' ), {
				target: { value: '' },
			} );
		} );
		expect( findInput( 'Post' ).checked ).toBe( true );
	} );
} );

describe( 'PreviewShare settings autosave', () => {
	it( 'keeps the global restore action on Overview', async () => {
		const { initialSettings } = setupFetch();
		await mountSettingsApp( initialSettings );

		expect( findButton( 'Restore defaults' ) ).toBeDefined();

		await act( async () => {
			findButton( 'Preview links' ).click();
			await flushPromises();
		} );
		expect( findButton( 'Restore defaults' ) ).toBeUndefined();
		expect(
			document.querySelector( '[data-testid="previewshare-dataviews"]' )
		).toMatchObject( {
			dataset: expect.objectContaining( {
				titleField: 'content',
				fields: 'label,status,view_count,expires_at,last_viewed_at',
			} ),
		} );

		await act( async () => {
			findButton( 'Content types' ).click();
			await flushPromises();
		} );
		expect( findButton( 'Restore defaults' ) ).toBeUndefined();

		await act( async () => {
			findButton( 'Changelog' ).click();
			await flushPromises();
		} );
		expect( findButton( 'Restore defaults' ) ).toBeUndefined();
	} );

	it( 'keeps a newer draft when an older save resolves first', async () => {
		const { posts, initialSettings } = setupFetch();
		await mountSettingsApp( initialSettings );

		await act( async () => {
			Simulate.change( findInput( 'Default expiry in hours' ), {
				target: { value: '12' },
			} );
		} );
		await act( async () => {
			jest.advanceTimersByTime( 450 );
			await flushPromises();
		} );
		expect( posts ).toHaveLength( 1 );

		await act( async () => {
			Simulate.change( findInput( 'Enable token lookup caching' ), {
				target: { checked: false },
			} );
		} );
		await act( async () => {
			posts[ 0 ].request.resolve(
				response( { ...initialSettings, default_ttl_hours: 12 } )
			);
			await flushPromises();
		} );

		expect( findInput( 'Enable token lookup caching' ).checked ).toBe(
			false
		);
		expect(
			document.querySelector( '.previewshare-save-state' ).textContent
		).toContain( 'Unsaved changes' );

		await act( async () => {
			jest.advanceTimersByTime( 450 );
			await flushPromises();
		} );
		expect( posts ).toHaveLength( 2 );
		expect( posts[ 1 ].body ).toMatchObject( {
			default_ttl_hours: 12,
			enable_caching: false,
		} );

		await act( async () => {
			posts[ 1 ].request.resolve(
				response( {
					...initialSettings,
					default_ttl_hours: 12,
					enable_caching: false,
				} )
			);
			await flushPromises();
		} );
		expect(
			document.querySelector( '.previewshare-save-state' ).textContent
		).toContain( 'Saved' );
	} );

	it( 'queues a manual save for a newer draft after an older request starts', async () => {
		const { posts, initialSettings } = setupFetch();
		await mountSettingsApp( initialSettings );

		await act( async () => {
			Simulate.change( findInput( 'Default expiry in hours' ), {
				target: { value: '12' },
			} );
			jest.advanceTimersByTime( 450 );
			await flushPromises();
		} );
		expect( posts ).toHaveLength( 1 );

		await act( async () => {
			Simulate.change( findInput( 'Enable token lookup caching' ), {
				target: { checked: false },
			} );
		} );
		await act( async () => {
			findButton( 'Save changes' ).click();
			await flushPromises();
		} );

		await act( async () => {
			posts[ 0 ].request.resolve(
				response( { ...initialSettings, default_ttl_hours: 12 } )
			);
			await flushPromises();
		} );
		expect( posts ).toHaveLength( 2 );
		expect( posts[ 1 ].body ).toMatchObject( {
			default_ttl_hours: 12,
			enable_caching: false,
		} );
		expect( findInput( 'Enable token lookup caching' ).checked ).toBe(
			false
		);

		await act( async () => {
			posts[ 1 ].request.resolve(
				response( {
					...initialSettings,
					default_ttl_hours: 12,
					enable_caching: false,
				} )
			);
			await flushPromises();
		} );
		expect(
			document.querySelector( '.previewshare-save-state' ).textContent
		).toContain( 'Saved' );
	} );

	it( 'keeps the draft after a failed save and clears the error on retry', async () => {
		const { posts, initialSettings } = setupFetch();
		await mountSettingsApp( initialSettings );

		await act( async () => {
			Simulate.change( findInput( 'Enable diagnostic logging' ), {
				target: { checked: true },
			} );
			jest.advanceTimersByTime( 450 );
			await flushPromises();
		} );
		expect( posts ).toHaveLength( 1 );

		await act( async () => {
			posts[ 0 ].request.reject( new Error( 'offline' ) );
			await flushPromises();
		} );
		expect(
			document.querySelector( '.previewshare-app-notice' ).textContent
		).toContain( 'Settings could not be saved.' );
		expect(
			document
				.querySelector( '.previewshare-app-notice' )
				.closest( '.previewshare-notice-region' )
		).not.toBeNull();

		await act( async () => {
			Simulate.change( findInput( 'Enable token lookup caching' ), {
				target: { checked: false },
			} );
			jest.advanceTimersByTime( 450 );
			await flushPromises();
		} );
		expect( posts ).toHaveLength( 2 );
		await act( async () => {
			posts[ 1 ].request.resolve(
				response( {
					...initialSettings,
					enable_logging: true,
					enable_caching: false,
				} )
			);
			await flushPromises();
		} );

		expect(
			document.querySelector( '.previewshare-app-notice' )
		).toBeNull();
		expect(
			document.querySelector( '.previewshare-save-state' ).textContent
		).toContain( 'Saved' );
	} );

	it( 'keeps an edit made while restoring defaults queued after the reset', async () => {
		const { posts, initialSettings } = setupFetch();
		await mountSettingsApp( initialSettings );

		await act( async () => {
			findButton( 'Restore defaults' ).click();
			await flushPromises();
		} );
		expect( posts[ 0 ].body ).toEqual( { reset_defaults: true } );

		await act( async () => {
			Simulate.change( findInput( 'Default expiry in hours' ), {
				target: { value: '12' },
			} );
		} );
		await act( async () => {
			posts[ 0 ].request.resolve(
				response( { ...initialSettings, default_ttl_hours: 6 } )
			);
			await flushPromises();
		} );
		expect( findInput( 'Default expiry in hours' ).value ).toBe( '12' );

		await act( async () => {
			jest.advanceTimersByTime( 450 );
			await flushPromises();
		} );
		expect( posts ).toHaveLength( 2 );
		expect( posts[ 1 ].body.default_ttl_hours ).toBe( 12 );

		await act( async () => {
			posts[ 1 ].request.resolve(
				response( { ...initialSettings, default_ttl_hours: 12 } )
			);
			await flushPromises();
		} );
		expect( findInput( 'Default expiry in hours' ).value ).toBe( '12' );
	} );

	it( 'rebases edits made during reset onto the authoritative defaults', async () => {
		const customSettings = {
			default_ttl_hours: 8,
			enable_logging: true,
			enable_caching: false,
			post_types: [ 'page' ],
			available_post_types: { post: 'Post', page: 'Page' },
			defaults: {
				default_ttl_hours: 6,
				enable_logging: false,
				enable_caching: true,
				post_types: [ 'post' ],
			},
		};
		const { posts } = setupFetch( customSettings );
		await mountSettingsApp( customSettings );

		await act( async () => {
			findButton( 'Restore defaults' ).click();
			await flushPromises();
		} );
		expect( posts[ 0 ].body ).toEqual( { reset_defaults: true } );

		await act( async () => {
			Simulate.change( findInput( 'Default expiry in hours' ), {
				target: { value: '12' },
			} );
		} );
		await act( async () => {
			posts[ 0 ].request.resolve(
				response( {
					...customSettings,
					default_ttl_hours: 6,
					enable_logging: false,
					enable_caching: true,
					post_types: [ 'post' ],
				} )
			);
			await flushPromises();
		} );

		expect( posts ).toHaveLength( 2 );
		expect( posts[ 1 ].body ).toEqual( {
			default_ttl_hours: 12,
			enable_logging: false,
			enable_caching: true,
			post_types: [ 'post' ],
		} );

		await act( async () => {
			posts[ 1 ].request.resolve(
				response( {
					...customSettings,
					default_ttl_hours: 12,
					enable_logging: false,
					enable_caching: true,
					post_types: [ 'post' ],
				} )
			);
			await flushPromises();
		} );

		expect( findInput( 'Default expiry in hours' ).value ).toBe( '12' );
		expect( findInput( 'Enable diagnostic logging' ).checked ).toBe(
			false
		);
		expect( findInput( 'Enable token lookup caching' ).checked ).toBe(
			true
		);
		expect(
			document.querySelector( '.previewshare-save-state' ).textContent
		).toContain( 'Saved' );
	} );

	it( 'queues cancel after an in-flight autosave and restores the acknowledged snapshot', async () => {
		const { posts, initialSettings } = setupFetch();
		await mountSettingsApp( initialSettings );

		await act( async () => {
			Simulate.change( findInput( 'Default expiry in hours' ), {
				target: { value: '12' },
			} );
			jest.advanceTimersByTime( 450 );
			await flushPromises();
		} );
		expect( posts ).toHaveLength( 1 );

		await act( async () => {
			findButton( 'Cancel' ).click();
			await flushPromises();
		} );
		await act( async () => {
			posts[ 0 ].request.resolve(
				response( { ...initialSettings, default_ttl_hours: 12 } )
			);
			await flushPromises();
		} );
		expect( posts ).toHaveLength( 2 );
		expect( posts[ 1 ].body.default_ttl_hours ).toBe( 6 );

		await act( async () => {
			posts[ 1 ].request.resolve( response( initialSettings ) );
			await flushPromises();
		} );
		expect( findInput( 'Default expiry in hours' ).value ).toBe( '6' );
	} );
} );

describe( 'PreviewShare settings navigation', () => {
	it( 'keeps diagnostics logging in Overview without a diagnostics tab', async () => {
		const { initialSettings } = setupFetch();
		await mountSettingsApp( initialSettings );

		expect(
			document.querySelector(
				'input[aria-label="Enable diagnostic logging"]'
			)
		).not.toBeNull();
		expect(
			Array.from( document.querySelectorAll( '[role="tab"]' ) ).some(
				( tab ) => tab.textContent.includes( 'Diagnostics' )
			)
		).toBe( false );
	} );

	it( 'renders tabs inside a labelled tablist with an associated panel', async () => {
		const { initialSettings } = setupFetch();
		await mountSettingsApp( initialSettings );

		const tablist = document.querySelector( '[role="tablist"]' );
		const tabs = Array.from( document.querySelectorAll( '[role="tab"]' ) );
		const panel = document.querySelector( '[role="tabpanel"]' );

		expect( tablist ).not.toBeNull();
		expect( tablist.getAttribute( 'aria-label' ) ).toBe(
			'PreviewShare settings'
		);
		expect( tabs ).toHaveLength( 5 );
		expect( tabs.every( ( tab ) => tab.parentElement === tablist ) ).toBe(
			true
		);
		expect(
			tabs.filter(
				( tab ) => tab.getAttribute( 'aria-selected' ) === 'true'
			)
		).toHaveLength( 1 );
		expect(
			tabs.find(
				( tab ) => tab.getAttribute( 'aria-selected' ) === 'true'
			).tabIndex
		).toBe( 0 );
		expect( panel.getAttribute( 'aria-labelledby' ) ).toBe( tabs[ 0 ].id );
		expect( tabs[ 0 ].getAttribute( 'aria-controls' ) ).toBe( panel.id );
	} );

	it( 'renders the More plugins catalog as product cards', async () => {
		const { initialSettings } = setupFetch();
		await mountSettingsApp( initialSettings );

		await act( async () => {
			findButton( 'More plugins' ).click();
			await flushPromises();
		} );

		expect(
			document.querySelectorAll( '.previewshare-plugin-card' )
		).toHaveLength( 7 );
		expect( document.body.textContent ).toContain( 'ThemeRouter' );
		expect( document.body.textContent ).toContain( 'Aculect Icon Library' );
		expect( document.body.textContent ).toContain( 'OneCaptcha' );
		expect( document.body.textContent ).not.toContain( 'Aculect SEO' );
		expect( document.body.textContent ).not.toContain( 'Aculect Docs' );

		const productLinks = Array.from(
			document.querySelectorAll(
				'.previewshare-plugin-card .previewshare-external-link'
			)
		);
		expect( productLinks ).toHaveLength( 7 );
		expect(
			productLinks.find( ( link ) =>
				link.getAttribute( 'aria-label' ).includes( 'ThemeRouter' )
			).href
		).toBe( 'https://themerouter.com/' );
		expect(
			productLinks.find( ( link ) =>
				link
					.getAttribute( 'aria-label' )
					.includes( 'Aculect AI Companion' )
			).href
		).toBe( 'https://wordpress.org/plugins/aculect-ai-companion/' );
		productLinks.forEach( ( link ) => {
			expect( link.href ).toMatch( /^https:\/\// );
			expect( link.target ).toBe( '_blank' );
			expect( link.rel ).toContain( 'noopener' );
			expect( link.getAttribute( 'aria-label' ) ).toMatch(
				/^Learn more about .+/
			);
		} );
	} );
} );

describe( 'PreviewShare expiring-link management', () => {
	it( 'gives repeated labels distinct Extend names in both inventory layouts', async () => {
		let narrow = false;
		let onViewportChange;
		window.matchMedia = jest.fn( () => ( {
			get matches() {
				return narrow;
			},
			addEventListener: ( type, callback ) => {
				if ( type === 'change' ) {
					onViewportChange = callback;
				}
			},
			removeEventListener: jest.fn(),
		} ) );
		const expiresAt = Math.floor( Date.now() / 1000 ) + 3600;
		const { initialSettings } = setupFetch( null, [
			{
				id: 'abcdef01' + '0'.repeat( 56 ),
				post_title: 'Review draft',
				label: 'Client review',
				status: 'active',
				expires_at: expiresAt,
			},
			{
				id: 'abcdef01' + '1'.repeat( 56 ),
				post_title: 'Review draft',
				label: 'Client review',
				status: 'active',
				expires_at: expiresAt,
			},
			{
				id: 'abcdef010' + '2'.repeat( 55 ),
				post_title: 'Other draft',
				label: 'Legal review',
				status: 'active',
				expires_at: expiresAt,
			},
			{
				id: '12345678' + '2'.repeat( 56 ),
				status: 'active',
				expires_at: expiresAt,
			},
		] );
		await mountSettingsApp( initialSettings );
		await act( async () => {
			findButton( 'Preview links' ).click();
			await flushPromises();
		} );

		const expectedNames = [
			'Extend Client review link for Review draft, reference abcdef0100',
			'Extend Client review link for Review draft, reference abcdef011',
			'Extend Legal review link for Other draft, reference abcdef0102',
			'Extend preview link for Untitled content, reference 12345678',
		];
		const expectExtendNames = ( selector, names = expectedNames ) => {
			const buttons = Array.from( document.querySelectorAll( selector ) );
			expect( buttons ).toHaveLength( names.length );
			expect( buttons.map( ( button ) => button.textContent ) ).toEqual(
				names.map( () => 'Extend' )
			);
			expect(
				buttons.map( ( button ) => button.getAttribute( 'aria-label' ) )
			).toEqual( names );
		};
		expectExtendNames(
			'.previewshare-dataviews button[aria-label^="Extend"]'
		);

		await act( async () => {
			narrow = true;
			onViewportChange();
			await flushPromises();
		} );
		const compactSelector =
			'.previewshare-legacy-link-table button[aria-label^="Extend"]';
		expectExtendNames( compactSelector );
		await act( async () => {
			window.__previewshareDataViewsProps.onChangeView( {
				...window.__previewshareDataViewsProps.view,
				search: 'Other draft',
				page: 1,
			} );
			await flushPromises();
		} );
		expectExtendNames( compactSelector, [ expectedNames[ 2 ] ] );
		await act( async () => {
			window.__previewshareDataViewsProps.onChangeView( {
				...window.__previewshareDataViewsProps.view,
				search: '',
				page: 1,
			} );
			await flushPromises();
		} );
		await act( async () => {
			window.__previewshareDataViewsProps.onChangeView( {
				...window.__previewshareDataViewsProps.view,
				perPage: 1,
			} );
			await flushPromises();
		} );
		expectExtendNames( compactSelector, [ expectedNames[ 0 ] ] );
		await act( async () => {
			window.__previewshareDataViewsProps.onChangeView( {
				...window.__previewshareDataViewsProps.view,
				page: 2,
			} );
			await flushPromises();
		} );
		expectExtendNames( compactSelector, [ expectedNames[ 1 ] ] );
	} );

	it( 'moves expiring links into the inventory and extends the same link by 24 hours', async () => {
		const expiresAt = Math.floor( Date.now() / 1000 ) + 3 * 60 * 60;
		const linkItem = {
			id: 'link-hash-1',
			post_id: 42,
			post_title: 'Review draft',
			post_type: 'post',
			label: 'Client review',
			status: 'active',
			expires_at: expiresAt,
			view_count: 2,
			created_at: expiresAt - 60,
		};
		const { initialSettings } = setupFetch( null, [ linkItem ] );
		await mountSettingsApp( initialSettings );

		expect( document.body.textContent ).not.toContain( 'Expiring soon' );
		await act( async () => {
			findButton( 'Preview links' ).click();
			await flushPromises();
		} );

		expect( document.body.textContent ).toContain( 'Expiring soon' );
		expect( findButton( 'Extend' ) ).toBeDefined();
		await act( async () => {
			findButton( 'Extend' ).click();
			await flushPromises();
		} );
		const extensionRequest = window.fetch.mock.calls.find( ( [ url ] ) =>
			String( url ).endsWith( '/tokens/extend' )
		);
		expect( extensionRequest[ 1 ].headers[ 'X-WP-Nonce' ] ).toBe(
			'test-nonce'
		);

		expect( findButton( 'Extend' ) ).toBeUndefined();
		expect( document.body.textContent ).toContain(
			'Access extended by 24 hours.'
		);
		expect(
			document.querySelector( '.previewshare-status.is-expiring_soon' )
		).toBeNull();
		expect(
			document.querySelector( '.previewshare-link-quick-filters' )
		).toBeNull();
	} );

	it( 'includes the 24-hour boundary and excludes inactive or later expiries', async () => {
		const now = Math.floor( Date.now() / 1000 );
		const links = [
			{ id: 'at-boundary', status: 'active', expires_at: now + 86400 },
			{ id: 'outside-window', status: 'active', expires_at: now + 86401 },
			{ id: 'expired-now', status: 'active', expires_at: now },
			{ id: 'expired', status: 'expired', expires_at: now - 1 },
			{ id: 'revoked', status: 'revoked', expires_at: now + 60 },
			{ id: 'non-expiring', status: 'active', expires_at: null },
		];
		const { initialSettings } = setupFetch( null, links );
		await mountSettingsApp( initialSettings );

		await act( async () => {
			findButton( 'Preview links' ).click();
			await flushPromises();
		} );

		const statusField = window.__previewshareDataViewsProps.fields.find(
			( field ) => field.id === 'status'
		);
		expect( statusField.filterBy.isPrimary ).toBe( true );
		await act( async () => {
			window.__previewshareDataViewsProps.onChangeView( {
				...window.__previewshareDataViewsProps.view,
				filters: [
					{
						field: 'status',
						operator: 'isAny',
						value: [ 'expiring_soon' ],
					},
				],
			} );
			await flushPromises();
		} );
		expect(
			document.querySelectorAll( '.previewshare-status.is-expiring_soon' )
		).toHaveLength( 1 );
		expect( document.body.textContent ).toContain( 'at-boundary' );
	} );

	it( 'shows a pending state and preserves the link after an extension failure', async () => {
		const expiresAt = Math.floor( Date.now() / 1000 ) + 3 * 60 * 60;
		const { initialSettings } = setupFetch( null, [
			{
				id: 'link-hash-1',
				status: 'active',
				expires_at: expiresAt,
			},
		] );
		const defaultFetch = window.fetch;
		const request = deferred();
		window.fetch = jest.fn( ( url, options = {} ) => {
			if ( String( url ).endsWith( '/tokens/extend' ) ) {
				return request.promise;
			}

			return defaultFetch( url, options );
		} );
		await mountSettingsApp( initialSettings );

		await act( async () => {
			findButton( 'Preview links' ).click();
			await flushPromises();
		} );

		await act( async () => {
			findButton( 'Extend' ).click();
			await flushPromises();
		} );
		expect( findButton( 'Extend' ).disabled ).toBe( true );

		await act( async () => {
			request.reject( new Error( 'network failure' ) );
			await flushPromises();
		} );
		expect( document.body.textContent ).toContain(
			'Preview link could not be extended. Its expiry is unchanged.'
		);
		expect( findButton( 'Extend' ) ).toBeDefined();
		expect(
			document.querySelectorAll( '.previewshare-status.is-expiring_soon' )
		).toHaveLength( 1 );
	} );
} );
