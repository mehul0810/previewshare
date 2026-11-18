export function loadDataViews() {
	return import(
		/* webpackChunkName: "previewshare-dataviews" */
		/* webpackExports: ["DataViews", "filterSortAndPaginate"] */
		'@wordpress/dataviews'
	);
}
