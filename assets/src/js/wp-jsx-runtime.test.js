import { createElement } from '@wordpress/element';
import { Fragment, jsx, jsxs, jsxDEV } from './wp-jsx-runtime';

jest.mock( '@wordpress/element', () => ( {
	createElement: jest.fn( ( ...args ) => args ),
	Fragment: Symbol( 'Fragment' ),
} ) );

describe( 'WordPress JSX runtime adapter', () => {
	beforeEach( () => createElement.mockClear() );

	it( 'uses WordPress createElement and preserves props, children, and refs', () => {
		const ref = jest.fn();
		const props = { children: 'Child', ref };

		jsx( 'button', props );

		expect( createElement ).toHaveBeenCalledWith( 'button', props );
		expect( Fragment ).toBeDefined();
	} );

	it( 'applies the compiler key argument without mutating props', () => {
		const props = { children: 'Child' };

		jsxs( 'li', props, 'compiler-key' );

		expect( createElement ).toHaveBeenCalledWith( 'li', {
			children: 'Child',
			key: 'compiler-key',
		} );
		expect( props ).not.toHaveProperty( 'key' );
	} );

	it( 'keeps the prop key when the compiler also supplies a key', () => {
		const props = { children: 'Child', key: 'original' };

		jsxs( 'li', props, 'compiler-key' );

		expect( createElement ).toHaveBeenCalledWith( 'li', props );
	} );

	it( 'supports the development JSX helper with the same element semantics', () => {
		jsxDEV( 'span', { children: 'Child' }, 'span-key', true );

		expect( createElement ).toHaveBeenCalledWith( 'span', {
			children: 'Child',
			key: 'span-key',
		} );
	} );
} );
