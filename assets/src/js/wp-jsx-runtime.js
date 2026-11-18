import { createElement, Fragment } from '@wordpress/element';

/**
 * Adapt the automatic JSX runtime API to WordPress's long-supported element API.
 *
 * WordPress 5.8 registers wp-element but not react-jsx-runtime. This module is
 * resolved in place of React's JSX runtime so that the plugin does not ship a
 * copy of React or require a newer script handle.
 */
export { Fragment };

export function jsx( type, props, key ) {
	// The standard JSX runtime gives an explicit prop key precedence.
	if ( key !== undefined && props?.key === undefined ) {
		props = { ...props, key };
	}

	return createElement( type, props );
}

export const jsxs = jsx;

export function jsxDEV( type, props, key ) {
	return jsx( type, props, key );
}
