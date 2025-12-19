/**
 * PreviewShare Editor Plugin.
 *
 * @since 1.0.0
 */

import { registerPlugin } from '@wordpress/plugins';
import { PluginDocumentSettingPanel } from '@wordpress/edit-post';
import { __ } from '@wordpress/i18n';
import { useState } from '@wordpress/element';
import { useSelect } from '@wordpress/data';
import { ToggleControl, Button } from '@wordpress/components';

const PreviewSharePanel = () => {
	const [isEnabled, setIsEnabled] = useState(false);
	const { getCurrentPostId, getCurrentPostType } = useSelect((select) => ({
		getCurrentPostId: select('core/editor').getCurrentPostId,
		getCurrentPostType: select('core/editor').getCurrentPostType,
	}));

	const postId = getCurrentPostId();
	const postType = getCurrentPostType();

	// Only show for post and page types
	if (!['post', 'page'].includes(postType)) {
		return null;
	}

	// Generate preview URL - placeholder for now
	const previewUrl = `${window.location.origin}/?p=${postId}&preview=true&previewshare=1`;

	const copyToClipboard = async () => {
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
				onChange={setIsEnabled}
				help={__('Allow this post to be shared via secure preview link.', 'previewshare')}
			/>
			{isEnabled && (
				<Button
					variant="secondary"
					onClick={copyToClipboard}
					className="previewshare-copy-button"
				>
					{__('Copy Preview URL', 'previewshare')}
				</Button>
			)}
		</PluginDocumentSettingPanel>
	);
};

registerPlugin('previewshare', {
	render: PreviewSharePanel,
	icon: 'visibility',
});
