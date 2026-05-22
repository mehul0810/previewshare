/**
 * PreviewShare Editor Plugin.
 *
 * @since 1.0.0
 */

import { registerPlugin } from '@wordpress/plugins';
import {
	PluginDocumentSettingPanel,
	PluginPreviewMenuItem,
} from '@wordpress/editor';
import { __, _n, sprintf } from '@wordpress/i18n';
import { Fragment, useState, useEffect } from '@wordpress/element';
import { useSelect, useDispatch } from '@wordpress/data';
import { Button, ToggleControl, TextControl } from '@wordpress/components';
import { copy } from '@wordpress/icons';

const getSupportedPostTypes = () =>
	window.previewshare_rest &&
	Array.isArray( window.previewshare_rest.post_types )
		? window.previewshare_rest.post_types
		: [ 'post', 'page' ];

const PREVIEWABLE_STATUSES = [
	'publish',
	'draft',
	'pending',
	'future',
	'private',
];

const getStatusLabel = ( status ) => {
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

const PreviewSharePanel = () => {
	const [ previewUrl, setPreviewUrl ] = useState( '' );
	const [ tokenMeta, setTokenMeta ] = useState( null );
	const [ isGenerating, setIsGenerating ] = useState( false );
	const [ linkLabel, setLinkLabel ] = useState( '' );
	const {
		postId,
		postType,
		postStatus,
		metaValue,
		isEnabled,
		ttlHours,
		isSavingPost,
		isAutosavingPost,
	} = useSelect( ( select ) => {
		const editor = select( 'core/editor' );
		const currentPostId = editor.getCurrentPostId();
		const currentPostType = editor.getCurrentPostType();
		const currentPostStatus = editor.getCurrentPostAttribute
			? editor.getCurrentPostAttribute( 'status' )
			: '';
		const editedPostStatus = editor.getEditedPostAttribute( 'status' );
		const currentMeta = editor.getEditedPostAttribute( 'meta' ) || {};
		const rawTtl = currentMeta._previewshare_ttl_hours;
		const resolvedPostStatus = PREVIEWABLE_STATUSES.includes(
			editedPostStatus
		)
			? editedPostStatus
			: currentPostStatus;

		return {
			postId: currentPostId,
			postType: currentPostType,
			postStatus: resolvedPostStatus || '',
			metaValue: currentMeta,
			isEnabled: currentMeta._previewshare_enabled === true,
			ttlHours:
				rawTtl === undefined || rawTtl === null || rawTtl === ''
					? null
					: rawTtl,
			isSavingPost: editor.isSavingPost ? editor.isSavingPost() : false,
			isAutosavingPost: editor.isAutosavingPost
				? editor.isAutosavingPost()
				: false,
		};
	} );
	const { editPost } = useDispatch( 'core/editor' );
	const { createNotice } = useDispatch( 'core/notices' );
	const isSupportedPostType = getSupportedPostTypes().includes( postType );
	const isPreviewableStatus = PREVIEWABLE_STATUSES.includes( postStatus );
	const canGeneratePreview =
		Boolean( postId ) &&
		isSupportedPostType &&
		isPreviewableStatus &&
		! isSavingPost &&
		! isAutosavingPost;

	useEffect( () => {
		if ( canGeneratePreview ) {
			fetchTokenMeta();
		}
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ postId, canGeneratePreview ] );

	if ( ! isSupportedPostType ) {
		return null;
	}

	const notify = ( status, message ) => {
		createNotice( status, message, {
			isDismissible: true,
			type: 'snackbar',
		} );
	};

	const fetchTokenMeta = async () => {
		if ( ! postId ) {
			setTokenMeta( null );
			return;
		}

		try {
			const options = {
				path: `/previewshare/v1/post-meta?post_id=${ postId }`,
			};

			if ( window.previewshare_rest && window.previewshare_rest.nonce ) {
				options.headers = {
					'X-WP-Nonce': window.previewshare_rest.nonce,
				};
			}

			const res = await wp.apiFetch( options );
			setTokenMeta( res );
		} catch ( err ) {
			setTokenMeta( null );
		}
	};

	const handleToggleChange = async ( enabled ) => {
		if ( enabled && ! canGeneratePreview ) {
			return;
		}

		editPost( {
			meta: {
				...metaValue,
				_previewshare_enabled: enabled,
			},
		} );

		if ( enabled ) {
			await generatePreviewUrl( { copy: true } );
		} else {
			await revokePreviewUrl();
			setPreviewUrl( '' );
		}
	};

	const handleTtlChange = ( val ) => {
		const normalized =
			val === '' ? null : Math.max( 0, parseInt( val, 10 ) || 0 );

		editPost( {
			meta: {
				...metaValue,
				_previewshare_ttl_hours: normalized,
			},
		} );
	};

	const generatePreviewUrl = async ( options = {} ) => {
		if ( ! canGeneratePreview || isGenerating ) {
			return null;
		}

		setIsGenerating( true );

		try {
			const fetchOptions = {
				path: '/previewshare/v1/v2/generate',
				method: 'POST',
				data: {
					post_id: postId,
					ttl_hours: ttlHours,
					label: linkLabel,
				},
			};

			if ( window.previewshare_rest && window.previewshare_rest.nonce ) {
				fetchOptions.headers = Object.assign(
					{},
					fetchOptions.headers,
					{ 'X-WP-Nonce': window.previewshare_rest.nonce }
				);
			}

			const response = await wp.apiFetch( fetchOptions );

			if ( response && response.url ) {
				setPreviewUrl( response.url );
				setLinkLabel( '' );
				editPost( {
					meta: {
						...metaValue,
						_previewshare_enabled: true,
					},
				} );
				await fetchTokenMeta();

				if ( options.copy ) {
					await copyUrl( response.url );
				}

				notify(
					'success',
					__( 'Preview link generated.', 'previewshare' )
				);
				return response.url;
			}

			setPreviewUrl( '' );
		} catch ( error ) {
			setPreviewUrl( '' );
			notify(
				'error',
				__( 'Preview link could not be generated.', 'previewshare' )
			);
		} finally {
			setIsGenerating( false );
		}

		return null;
	};

	const revokePreviewUrl = async () => {
		if ( ! postId ) {
			return;
		}

		try {
			const fetchOptions = {
				path: '/previewshare/v1/v2/revoke',
				method: 'POST',
				data: {
					post_id: postId,
				},
			};

			if ( window.previewshare_rest && window.previewshare_rest.nonce ) {
				fetchOptions.headers = Object.assign(
					{},
					fetchOptions.headers,
					{ 'X-WP-Nonce': window.previewshare_rest.nonce }
				);
			}

			await wp.apiFetch( fetchOptions );
			await fetchTokenMeta();
			notify( 'success', __( 'Preview links revoked.', 'previewshare' ) );
		} catch ( error ) {
			notify(
				'error',
				__( 'Preview links could not be revoked.', 'previewshare' )
			);
		}
	};

	const copyUrl = async ( url ) => {
		if ( ! url ) {
			return;
		}

		try {
			await window.navigator.clipboard.writeText( url );
			notify( 'success', __( 'Preview URL copied.', 'previewshare' ) );
		} catch ( error ) {
			notify(
				'error',
				__(
					'Preview URL generated, but could not be copied.',
					'previewshare'
				)
			);
		}
	};

	const copyToClipboard = async () => {
		let url = previewUrl;

		if ( ! url ) {
			url = await generatePreviewUrl();
		}

		await copyUrl( url );
	};

	const links =
		tokenMeta && tokenMeta.meta && Array.isArray( tokenMeta.meta.links )
			? tokenMeta.meta.links
			: [];
	const activeCount =
		tokenMeta && tokenMeta.meta ? tokenMeta.meta.active_count || 0 : 0;

	return (
		<Fragment>
			<PluginPreviewMenuItem
				icon="external"
				onClick={ () => generatePreviewUrl( { copy: true } ) }
				disabled={ ! canGeneratePreview || isGenerating }
			>
				{ __( 'Generate public preview link', 'previewshare' ) }
			</PluginPreviewMenuItem>
			<PluginDocumentSettingPanel
				name="previewshare-panel"
				title={ __( 'PreviewShare', 'previewshare' ) }
				className="previewshare-panel"
				initialOpen={ true }
			>
				<div className="previewshare-panel__body">
					<div className="previewshare-panel__toggle">
						<ToggleControl
							label={ __(
								'Enable Public Preview',
								'previewshare'
							) }
							checked={ isEnabled || activeCount > 0 }
							disabled={ ! canGeneratePreview && ! isEnabled }
							onChange={ handleToggleChange }
						/>
					</div>
					{ ! canGeneratePreview && (
						<p className="description previewshare-panel__notice">
							{ isSavingPost || isAutosavingPost
								? __(
										'PreviewShare will be available after the draft finishes saving.',
										'previewshare'
								  )
								: __(
										'Save this content as a draft before generating a preview link.',
										'previewshare'
								  ) }
						</p>
					) }
					<div className="previewshare-panel__field">
						<TextControl
							__next40pxDefaultSize
							label={ __(
								'Public Preview expires in (hours)',
								'previewshare'
							) }
							type="number"
							min="0"
							placeholder="6"
							value={ ttlHours === null ? '' : ttlHours }
							onChange={ handleTtlChange }
							help={ __(
								'Leave empty to use the site default from Settings. Use 0 for no automatic expiry.',
								'previewshare'
							) }
						/>
					</div>
					<div className="previewshare-panel__field">
						<TextControl
							__next40pxDefaultSize
							label={ __( 'Link label', 'previewshare' ) }
							value={ linkLabel }
							onChange={ setLinkLabel }
							placeholder={ __(
								'Client review, legal approval, etc.',
								'previewshare'
							) }
						/>
					</div>
					<div className="previewshare-panel__actions">
						<Button
							variant="primary"
							isBusy={ isGenerating }
							disabled={ isGenerating || ! canGeneratePreview }
							onClick={ () =>
								generatePreviewUrl( { copy: true } )
							}
						>
							{ __( 'Generate & copy', 'previewshare' ) }
						</Button>
						{ activeCount > 0 && (
							<Button
								variant="secondary"
								isDestructive
								onClick={ revokePreviewUrl }
							>
								{ __( 'Revoke all', 'previewshare' ) }
							</Button>
						) }
					</div>
					{ previewUrl && (
						<div className="previewshare-panel__url">
							<TextControl
								__next40pxDefaultSize
								value={ previewUrl }
								onFocus={ ( e ) => e.target.select() }
								readOnly={ true }
								aria-label={ __(
									'Preview URL',
									'previewshare'
								) }
							/>
							<Button
								className="previewshare-panel__copy-button"
								icon={ copy }
								label={ __(
									'Copy preview URL',
									'previewshare'
								) }
								showTooltip={ true }
								onClick={ copyToClipboard }
								disabled={ isGenerating }
							/>
						</div>
					) }
					{ links.length > 0 && (
						<div className="previewshare-panel__links">
							<h3 className="previewshare-panel__links-title">
								{ sprintf(
									/* translators: %d: Number of preview links. */
									_n(
										'%d preview link',
										'%d preview links',
										links.length,
										'previewshare'
									),
									links.length
								) }
							</h3>
							<ul className="previewshare-panel__links-list">
								{ links.slice( 0, 5 ).map( ( link ) => (
									<li
										className="previewshare-panel__link-item"
										key={ link.id }
									>
										<span className="previewshare-panel__link-label">
											{ link.label ||
												__(
													'Preview link',
													'previewshare'
												) }
										</span>
										<span className="previewshare-panel__link-meta">
											<span>
												{ getStatusLabel(
													link.status
												) }
											</span>
											<span aria-hidden="true">
												&middot;
											</span>
											<span>
												{ sprintf(
													/* translators: %d: Number of preview link views. */
													_n(
														'%d view',
														'%d views',
														link.view_count || 0,
														'previewshare'
													),
													link.view_count || 0
												) }
											</span>
										</span>
									</li>
								) ) }
							</ul>
						</div>
					) }
				</div>
			</PluginDocumentSettingPanel>
		</Fragment>
	);
};

registerPlugin( 'previewshare', {
	render: PreviewSharePanel,
	icon: 'visibility',
} );
