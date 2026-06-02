export const fallbackSettings = {
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

export function normalizeSettings( settings = {} ) {
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
			settings.default_ttl_hours ?? fallbackSettings.default_ttl_hours,
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

export function getRestBase( localized = {} ) {
	return localized.rest_url
		? localized.rest_url.replace( /\/$/, '' )
		: '/wp-json/previewshare/v1';
}
