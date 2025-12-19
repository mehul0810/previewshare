/**
 * PreviewShare Editor Plugin.
 *
 * @since 1.0.0
 */

import { registerPlugin } from '@wordpress/plugins';
import { PluginDocumentSettingPanel } from '@wordpress/edit-post';
import { __ } from '@wordpress/i18n';
import { useState, useEffect } from '@wordpress/element';
import { useSelect, useDispatch } from '@wordpress/data';
import { ToggleControl, Button } from '@wordpress/components';

const PreviewSharePanel = () => {
	const [previewUrl, setPreviewUrl] = useState(existingUrl || '');
	const [isGenerating, setIsGenerating] = useState(false);
	const { postId, postType, postStatus, metaValue, isEnabled, existingUrl } = useSelect((select) => {
		const postId = select('core/editor').getCurrentPostId();
		const postType = select('core/editor').getCurrentPostType();
		const post = select('core/editor').getCurrentPost();
		const postStatus = select('core/editor').getEditedPostAttribute('status') || post?.status || 'draft';

		// Get meta value with explicit default handling
		const metaValue = select('core/editor').getEditedPostAttribute('meta') || {};
		const isEnabled = metaValue._previewshare_enabled === true;

		// Generate URL if token exists
		let existingUrl = '';
		if (metaValue._previewshare_token) {
			existingUrl = `${previewshare_rest.home_url}/preview/${metaValue._previewshare_token}`;
		}

		return {
			postId,
			postType,
			postStatus,
			metaValue,
			isEnabled,
			existingUrl,
		};
	});	const { editPost } = useDispatch('core/editor');

	// Update preview URL when existing URL changes
	useEffect(() => {
		if (existingUrl) {
			setPreviewUrl(existingUrl);
		}
	}, [existingUrl]);

	const handleToggleChange = (enabled) => {
		editPost({
			meta: {
				...metaValue,
				_previewshare_enabled: enabled,
			},
		});
	};

	const generatePreviewUrl = async () => {
		setIsGenerating(true);
		try {
			const response = await wp.apiFetch({
				path: 'previewshare/v1/generate-url',
				method: 'POST',
				data: {
					post_id: postId,
				},
			});

			setPreviewUrl(response.url);
		} catch (error) {
			console.error('Failed to generate preview URL:', error);
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
				label={__('Enable Preview Sharing', 'previewshare')}
				checked={isEnabled}
				onChange={handleToggleChange}
				help={__('Allow this post to be shared via secure preview link.', 'previewshare')}
			/>
			{isEnabled && (
				<div>
					<Button
						variant="secondary"
						onClick={copyToClipboard}
						className="previewshare-copy-button"
						disabled={isGenerating}
					>
						{isGenerating ? __('Generating...', 'previewshare') : __('Copy Preview URL', 'previewshare')}
					</Button>
					{previewUrl && (
						<p style={{ marginTop: '8px', fontSize: '12px', color: '#666' }}>
							{__('Preview URL generated successfully!', 'previewshare')}
						</p>
					)}
				</div>
			)}
		</PluginDocumentSettingPanel>
	);
};

registerPlugin('previewshare', {
	render: PreviewSharePanel,
	icon: 'visibility',
});
