import * as element from '@wordpress/element';

// eslint-disable-next-line import/no-extraneous-dependencies
const { Simulate } = require( 'react-dom/test-utils' );
// eslint-disable-next-line import/no-extraneous-dependencies
const { act } = require( 'react' );
// eslint-disable-next-line import/no-extraneous-dependencies
const { createRoot } = require( 'react-dom/client' );

jest.mock( '@wordpress/dataviews/wp', () => {
	const { createElement } = require( '@wordpress/element' );

	return {
		DataViews: ( { data = [], fields = [], view = {} } ) =>
			createElement(
				'div',
				{
					'data-testid': 'previewshare-dataviews',
					'data-title-field': view.titleField,
					'data-fields': ( view.fields || [] ).join( ',' ),
				},
				data.map( ( item ) =>
					createElement(
						'span',
						{ key: item.id },
						item.id,
						...fields
							.filter( ( field ) => field.id === 'status' )
							.map( ( field ) => field.render( { item } ) )
					)
				)
			),
		filterSortAndPaginate: ( data ) => ( {
			data,
			paginationInfo: {
				totalItems: data.length,
				totalPages: 1,
			},
		} ),
	};
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
	const Button = ( { children, onClick, disabled, isBusy } ) =>
		createElement(
			'button',
			{ type: 'button', onClick, disabled: disabled || isBusy },
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
			sprintf: ( format, ...args ) =>
				format.replace( /%(\d+\$)?[sd]/g, ( match, position ) => {
					const index = position
						? Number.parseInt( position, 10 ) - 1
						: 0;
					return String( args[ index ] );
				} ),
		},
		components: {
			Button,
			Icon: () => createElement( 'span' ),
			Notice,
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

async function mountSettingsApp( initialSettings ) {
	document.body.innerHTML = '<div id="previewshare-settings-app"></div>';
	window.previewshare_settings = {
		version: '1.1.0',
		rest_url: '/wp-json/previewshare/v1',
		nonce: 'test-nonce',
		settings: initialSettings,
	};

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
		rest_url: '/wp-json/previewshare/v1',
		nonce: 'test-nonce',
	};
	// The settings bundle registers its DOMContentLoaded mount listener.
	require( './settings' );
} );

beforeEach( () => {
	jest.useFakeTimers();
} );

afterEach( () => {
	jest.useFakeTimers();
	jest.runOnlyPendingTimers();
	act( () => window.__previewshareTestUnmount() );
	document.body.innerHTML = '';
	jest.useRealTimers();
} );

describe( 'PreviewShare settings autosave', () => {
	it( 'shows restore defaults only on tabs that contain settings', async () => {
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
		expect( findButton( 'Restore defaults' ) ).toBeDefined();

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
		).toHaveLength( 9 );
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
		expect( productLinks ).toHaveLength( 9 );
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
		expect( document.body.textContent ).toContain( 'Expiring soon (0)' );
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

		expect( findButton( 'Expiring soon (1)' ) ).toBeDefined();
		await act( async () => {
			findButton( 'Expiring soon (1)' ).click();
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
