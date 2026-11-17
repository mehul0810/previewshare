export function loadDataViews() {
	return import(
		/* webpackChunkName: "previewshare-dataviews" */ '@wordpress/dataviews/wp'
	);
}
