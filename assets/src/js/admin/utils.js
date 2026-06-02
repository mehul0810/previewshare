import { __ } from '@wordpress/i18n';

export const PREVIEWABLE_STATUSES = [
	'publish',
	'draft',
	'pending',
	'future',
	'private',
];

export const getSupportedPostTypesFromConfig = ( config = {} ) =>
	Array.isArray( config.post_types ) ? config.post_types : [ 'post', 'page' ];

export const getSupportedPostTypes = () =>
	getSupportedPostTypesFromConfig( window.previewshare_rest || {} );

export const resolvePreviewableStatus = (
	currentPostStatus,
	editedPostStatus
) =>
	PREVIEWABLE_STATUSES.includes( editedPostStatus )
		? editedPostStatus
		: currentPostStatus || '';

export const canGeneratePreview = ( {
	postId,
	postType,
	postStatus,
	isSavingPost = false,
	isAutosavingPost = false,
	supportedPostTypes = [ 'post', 'page' ],
} ) =>
	Boolean( postId ) &&
	supportedPostTypes.includes( postType ) &&
	PREVIEWABLE_STATUSES.includes( postStatus ) &&
	! isSavingPost &&
	! isAutosavingPost;

export const normalizeTtlHours = ( value ) =>
	value === '' || value === null || value === undefined
		? null
		: Math.max( 0, parseInt( value, 10 ) || 0 );

export const getStatusLabel = ( status ) => {
	switch ( status ) {
		case 'active':
			return __( 'Active', 'previewshare' );
		case 'expired':
			return __( 'Expired', 'previewshare' );
		case 'revoked':
			return __( 'Revoked', 'previewshare' );
		default:
			return status || __( 'Unknown', 'previewshare' );
	}
};
