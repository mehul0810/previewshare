/**
 * PreviewShare Editor Plugin.
 *
 * @since 1.0.0
 */

import { registerPlugin } from '@wordpress/plugins';
import { PluginDocumentSettingPanel } from '@wordpress/editor';
import { __ } from '@wordpress/i18n';
import { useState, useRef, useEffect } from '@wordpress/element';
import { useSelect, useDispatch } from '@wordpress/data';
import { ToggleControl, Button, TextControl, IconButton } from '@wordpress/components';
import { copy as copyIcon } from '@wordpress/icons';

const PreviewSharePanel = () => {
	const [previewUrl, setPreviewUrl] = useState('');
	const [previewUrl, setPreviewUrl] = useState('');
	const [tokenMeta, setTokenMeta] = useState(null);
	const [isGenerating, setIsGenerating] = useState(false);

	// Auto-generate a preview URL when the panel loads and preview sharing is enabled.
	// This ensures a pretty token URL is shown after a page refresh.
	useEffect( () => {
		if ( isEnabled && ! previewUrl ) {
			generatePreviewUrl();
		}
	// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ isEnabled ] );

	// Fetch token meta (expired/revoked) for the current post so we can show
	// a message in the panel when the token is expired.
	useEffect( () => {
		const fetchMeta = async () => {
			if ( ! postId ) {
				setTokenMeta(null);
				return;
			}

			try {
				const path = `/previewshare/v1/post-meta?post_id=${postId}`;
				const options = { path };
				if ( window.previewshare_rest && window.previewshare_rest.nonce ) {
					options.headers = { 'X-WP-Nonce': window.previewshare_rest.nonce };
				}

				const res = await wp.apiFetch( options );
				setTokenMeta( res );
			} catch (err) {
				console.error('Failed to fetch preview token meta', err);
				setTokenMeta(null);
			}
		};

		fetchMeta();
	}, [ postId, isEnabled ] );
	const { postId, metaValue, isEnabled, ttlHours } = useSelect((select) => {
		const postId = select('core/editor').getCurrentPostId();
		const postType = select('core/editor').getCurrentPostType();
		const post = select('core/editor').getCurrentPost();
		const postStatus = select('core/editor').getEditedPostAttribute('status') || post?.status || 'draft';

		// Get meta value with explicit default handling
		const metaValue = select('core/editor').getEditedPostAttribute('meta') || {};
		const isEnabled = metaValue._previewshare_enabled === true;
		const ttlHours = metaValue._previewshare_ttl_hours ? metaValue._previewshare_ttl_hours : null;

		return {
			postId,
			postType,
			postStatus,
			metaValue,
			isEnabled,
			ttlHours,
		};
	});
	const { editPost } = useDispatch('core/editor');

	const handleToggleChange = (enabled) => {
		editPost({
			meta: {
				...metaValue,
				_previewshare_enabled: enabled,
			},
		});

		// If enabling preview sharing, generate a preview URL immediately.
		if ( enabled ) {
			// Generate a fresh preview URL and populate the field.
			generatePreviewUrl();
		} else {
			// Clear any generated URL when disabling.
			setPreviewUrl('');
		}
	};

	const handleTtlChange = ( val ) => {
		editPost({
			meta: {
				...metaValue,
				_previewshare_ttl_hours: parseInt( val, 10 ) || null,
			},
		});
	};

	const generatePreviewUrl = async () => {
		setIsGenerating(true);
		try {
			// Use the REST path we control to ensure apiFetch resolves correctly.
			const apiPath = '/previewshare/v1/generate-url';

			const fetchOptions = {
				path: apiPath,
				method: 'POST',
				data: {
					post_id: postId,
					ttl_hours: ttlHours,
				},
			};

			// If a localized nonce is available, include it so the request is authenticated.
			if ( window.previewshare_rest && window.previewshare_rest.nonce ) {
				fetchOptions.headers = Object.assign( {}, fetchOptions.headers, { 'X-WP-Nonce': window.previewshare_rest.nonce } );
			}

			const response = await wp.apiFetch( fetchOptions );

			if ( response && response.url ) {
				setPreviewUrl( response.url );
			} else {
				// If no token URL returned, clear previous value.
				setPreviewUrl( '' );
			}
		} catch (error) {
			console.error('Failed to generate preview URL:', error);
			setPreviewUrl( '' );
		} finally {
			setIsGenerating(false);
		}
	};

	const copyToClipboard = async () => {
		if (!previewUrl) {
			await generatePreviewUrl();
			return;
		}

		try {
			await navigator.clipboard.writeText(previewUrl);
			// Could add a success notice here
		} catch (error) {
			console.error('Failed to copy URL:', error);
		}
	};

	return (
		<PluginDocumentSettingPanel
			name="previewshare-panel"
			title={__('PreviewShare', 'previewshare')}
			className="previewshare-panel"
			initialOpen={true}
		>
			<ToggleControl
				label={__('Enable Public Preview', 'previewshare')}
				checked={isEnabled}
				onChange={handleToggleChange}
			/>
			{isEnabled && (
				<div>
					<div style={{ position: 'relative', marginTop: '8px' }}>
						<TextControl
							value={ previewUrl || ( window.previewshare_rest && window.previewshare_rest.home_url && postId ? `${ window.previewshare_rest.home_url }/?p=${ postId }&preview=true` : '' ) }
							onFocus={ ( e ) => e.target.select() }
							readOnly={ true }
							aria-label={ __( 'Preview URL', 'previewshare' ) }
							style={ { width: '100%', padding: '20px 35px 20px 10px' } }
						/>
						<IconButton
							icon={ copyIcon }
							label={ __( 'Copy preview URL', 'previewshare' ) }
							onClick={ copyToClipboard }
							disabled={ isGenerating }
							size='small'
							style={ {
								position: 'absolute',
								right: 0,
								top: '50%',
								transform: 'translateY(-50%)',
								zIndex: 2,
								background: 'transparent',
								border: 'none',
								boxShadow: 'none',
								padding: '6px',
								height: '32px',
								width: '32px',
								borderRadius: '4px',
							} }
						/>
					</div>
					<div style={{ display: 'flex', alignItems: 'center', gap: '8px', marginTop: '8px' }}>
						<TextControl
							label={__('Public Preview expires in (hours)', 'previewshare')}
							type="number"
							placeholder="24"
							value={ ttlHours || '' }
							onChange={ handleTtlChange }
							help={__('Leave empty to use the site default from Settings.', 'previewshare')}
							style={{ flex: 1 }}
						/>
					</div>
					{ tokenMeta && tokenMeta.meta && tokenMeta.meta.expired && (
						<div style={{ marginTop: '8px', color: '#b00020' }}>
							{ __( 'The current preview token has expired. Generate a new token to re-enable public preview.', 'previewshare' ) }
						</div>
					) }
				</div>
			)}
		</PluginDocumentSettingPanel>
	);
};

registerPlugin('previewshare', {
	render: PreviewSharePanel,
	icon: 'visibility',
});
