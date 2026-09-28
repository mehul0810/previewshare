/**
 * PreviewShare Editor Plugin.
 *
 * @since 1.0.0
 */

import { registerPlugin } from '@wordpress/plugins';
import {
	PluginDocumentSettingPanel as EditorDocumentSettingPanel,
	PluginPreviewMenuItem,
} from '@wordpress/editor';
import { __, _n, sprintf } from '@wordpress/i18n';
import { Fragment, useState, useEffect } from '@wordpress/element';
import { useSelect, useDispatch } from '@wordpress/data';
import { Button, ToggleControl, TextControl } from '@wordpress/components';
import { copy } from '@wordpress/icons';
import {
	canGeneratePreview as canGeneratePreviewForState,
	getStatusLabel,
	getSupportedPostTypes,
	normalizeTtlHours,
	resolvePreviewableStatus,
} from './utils';

// WordPress 5.8 exposes this SlotFill from wp.editPost. Newer core
// versions expose it from wp.editor.
const PluginDocumentSettingPanel =
	EditorDocumentSettingPanel ||
	window.wp?.editPost?.PluginDocumentSettingPanel;

const reviewStateLabels = {
	pending: __( 'Awaiting response', 'previewshare' ),
	approved: __( 'Approved', 'previewshare' ),
	stale: __( 'Approval needs review after edit', 'previewshare' ),
	changes_requested: __( 'Changes requested', 'previewshare' ),
	commented: __( 'Comment received', 'previewshare' ),
};

const responseLabels = {
	approve: __( 'Approved', 'previewshare' ),
	request_changes: __( 'Requested changes', 'previewshare' ),
	comment: __( 'Commented', 'previewshare' ),
};

const ReviewLinkControls = ( { link, postId, onPolicySaved, notify } ) => {
	const [ expanded, setExpanded ] = useState( false );
	const [ busy, setBusy ] = useState( false );
	const [ state, setState ] = useState( 'pending' );
	const [ history, setHistory ] = useState( [] );
	const [ page, setPage ] = useState( 1 );
	const [ hasMore, setHasMore ] = useState( false );

	const request = ( path, method = 'GET', data ) =>
		wp.apiFetch( {
			path,
			method,
			...( data ? { data } : {} ),
			headers: {
				'X-WP-Nonce': window.previewshare_rest?.nonce || '',
			},
		} );

	const loadHistory = async ( nextPage = 1 ) => {
		setBusy( true );
		try {
			const result = await request(
				`/previewshare/v1/reviews/history?post_id=${ postId }&id=${ encodeURIComponent(
					link.id
				) }&page=${ nextPage }`
			);
			setState( result.state );
			setHistory( ( previous ) =>
				nextPage === 1
					? result.history
					: [ ...previous, ...result.history ]
			);
			setPage( nextPage );
			setHasMore( result.history.length === 50 );
		} catch {
			notify(
				'error',
				__( 'Review history could not be loaded.', 'previewshare' )
			);
		} finally {
			setBusy( false );
		}
	};

	const setPolicy = async ( key, value ) => {
		setBusy( true );
		try {
			await request( '/previewshare/v1/reviews/policy', 'POST', {
				post_id: postId,
				id: link.id,
				responses_enabled:
					key === 'responses_enabled'
						? value
						: link.responses_enabled,
				identity_required:
					key === 'identity_required'
						? value
						: link.identity_required,
			} );
			await onPolicySaved();
			notify( 'success', __( 'Review settings saved.', 'previewshare' ) );
		} catch {
			notify(
				'error',
				__( 'Review settings could not be saved.', 'previewshare' )
			);
		} finally {
			setBusy( false );
		}
	};

	const resolve = async ( responseId ) => {
		setBusy( true );
		try {
			await request( '/previewshare/v1/reviews/resolve', 'POST', {
				post_id: postId,
				id: link.id,
				response_id: responseId,
			} );
			await loadHistory();
			notify(
				'success',
				__( 'Change request resolved.', 'previewshare' )
			);
		} catch {
			notify(
				'error',
				__( 'Change request could not be resolved.', 'previewshare' )
			);
		} finally {
			setBusy( false );
		}
	};

	return (
		<div className="previewshare-panel__review">
			<Button
				variant="link"
				aria-expanded={ expanded }
				onClick={ () => {
					setExpanded( ! expanded );
					if ( ! expanded ) {
						loadHistory();
					}
				} }
			>
				{ expanded
					? __( 'Hide review', 'previewshare' )
					: __( 'Review settings and history', 'previewshare' ) }
			</Button>
			{ expanded && (
				<div className="previewshare-panel__review-details">
					<ToggleControl
						label={ __(
							'Allow reviewer responses',
							'previewshare'
						) }
						checked={ !! link.responses_enabled }
						disabled={ busy || link.revoked || link.expired }
						onChange={ ( value ) =>
							setPolicy( 'responses_enabled', value )
						}
					/>
					{ link.responses_enabled && (
						<ToggleControl
							label={ __(
								'Require name and email',
								'previewshare'
							) }
							checked={ !! link.identity_required }
							disabled={ busy || link.revoked || link.expired }
							onChange={ ( value ) =>
								setPolicy( 'identity_required', value )
							}
						/>
					) }
					<p
						className="previewshare-panel__review-state"
						role="status"
					>
						{ reviewStateLabels[ state ] ||
							reviewStateLabels.pending }
					</p>
					<p className="description">
						{ __(
							'Responses and reviewer identity are removed after 90 days. Revoking the link stops new responses and keeps existing history until then.',
							'previewshare'
						) }
					</p>
					{ history.length > 0 ? (
						<ol className="previewshare-panel__review-history">
							{ history.map( ( response ) => (
								<li key={ response.id }>
									<strong>
										{
											responseLabels[
												response.response_type
											]
										}
									</strong>
									<span>
										{ response.reviewer_name ||
											__(
												'Anonymous reviewer',
												'previewshare'
											) }
										{ response.reviewer_email
											? ` (${ response.reviewer_email })`
											: '' }
									</span>
									<time
										dateTime={ new Date(
											response.created_at * 1000
										).toISOString() }
									>
										{ new Date(
											response.created_at * 1000
										).toLocaleString() }
									</time>
									{ response.comment && (
										<p>{ response.comment }</p>
									) }
									{ response.response_type ===
										'request_changes' &&
										! response.resolved_at && (
											<Button
												variant="secondary"
												disabled={ busy }
												onClick={ () =>
													resolve( response.id )
												}
											>
												{ __(
													'Resolve',
													'previewshare'
												) }
											</Button>
										) }
									{ !! response.resolved_at && (
										<span>
											{ __( 'Resolved', 'previewshare' ) }
										</span>
									) }
								</li>
							) ) }
						</ol>
					) : (
						<p>{ __( 'No responses yet.', 'previewshare' ) }</p>
					) }
					{ hasMore && (
						<Button
							disabled={ busy }
							onClick={ () => loadHistory( page + 1 ) }
						>
							{ __( 'Load more responses', 'previewshare' ) }
						</Button>
					) }
				</div>
			) }
		</div>
	);
};

const PreviewSharePanel = () => {
	const [ previewUrl, setPreviewUrl ] = useState( '' );
	const [ tokenMeta, setTokenMeta ] = useState( null );
	const [ isGenerating, setIsGenerating ] = useState( false );
	const [ linkLabel, setLinkLabel ] = useState( '' );
	const [ responsesEnabled, setResponsesEnabled ] = useState( false );
	const [ identityRequired, setIdentityRequired ] = useState( false );
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
		const resolvedPostStatus = resolvePreviewableStatus(
			currentPostStatus,
			editedPostStatus
		);

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
	const supportedPostTypes = getSupportedPostTypes();
	const isSupportedPostType = supportedPostTypes.includes( postType );
	const canGeneratePreview = canGeneratePreviewForState( {
		postId,
		postType,
		postStatus,
		isSavingPost,
		isAutosavingPost,
		supportedPostTypes,
	} );

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
		} catch {
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
		const normalized = normalizeTtlHours( val );

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
					responses_enabled: responsesEnabled,
					identity_required: responsesEnabled && identityRequired,
				},
			};

			if ( window.previewshare_rest && window.previewshare_rest.nonce ) {
				fetchOptions.headers = Object.assign(
					{},
					fetchOptions.headers,
					{
						'X-WP-Nonce': window.previewshare_rest.nonce,
					}
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
		} catch {
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
					{
						'X-WP-Nonce': window.previewshare_rest.nonce,
					}
				);
			}

			await wp.apiFetch( fetchOptions );
			await fetchTokenMeta();
			notify( 'success', __( 'Preview links revoked.', 'previewshare' ) );
		} catch {
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
		} catch {
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
	const diagnostic =
		tokenMeta && tokenMeta.diagnostic ? tokenMeta.diagnostic : null;

	return (
		<Fragment>
			{ PluginPreviewMenuItem && (
				<PluginPreviewMenuItem
					icon="external"
					onClick={ () => generatePreviewUrl( { copy: true } ) }
					disabled={ ! canGeneratePreview || isGenerating }
				>
					{ __( 'Generate public preview link', 'previewshare' ) }
				</PluginPreviewMenuItem>
			) }
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
					{ diagnostic && diagnostic.reason_code !== 'active' && (
						<p className="description previewshare-panel__notice">
							<strong>{ diagnostic.message }</strong>{ ' ' }
							{ diagnostic.action }
						</p>
					) }
					<div className="previewshare-panel__field">
						<TextControl
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
							label={ __( 'Link label', 'previewshare' ) }
							value={ linkLabel }
							onChange={ setLinkLabel }
							placeholder={ __(
								'Client review, legal approval, etc.',
								'previewshare'
							) }
						/>
					</div>
					<div className="previewshare-panel__field">
						<ToggleControl
							label={ __(
								'Allow reviewer responses',
								'previewshare'
							) }
							checked={ responsesEnabled }
							onChange={ setResponsesEnabled }
							help={ __(
								'Reviewers can approve, request changes, or comment on this link. Responses are kept for 90 days.',
								'previewshare'
							) }
						/>
						{ responsesEnabled && (
							<ToggleControl
								label={ __(
									'Require name and email',
									'previewshare'
								) }
								checked={ identityRequired }
								onChange={ setIdentityRequired }
							/>
						) }
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
										<ReviewLinkControls
											link={ link }
											postId={ postId }
											onPolicySaved={ fetchTokenMeta }
											notify={ notify }
										/>
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
