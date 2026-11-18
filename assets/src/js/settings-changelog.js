import { chevronDown } from '@wordpress/icons';

export function Changelog( { wp, installedVersion, intro } ) {
	const { createElement: el } = wp.element;
	const { __ } = wp.i18n;
	const { Icon } = wp.components;
	const entries = [
		{
			version: '1.1.1',
			summary: __(
				'Safer reviews and faster link management',
				'previewshare'
			),
			items: [
				__(
					'Fixed false success messages for reviewer responses and preserved edits when a submission needs to be retried.',
					'previewshare'
				),
				__(
					'Improved protection against duplicate responses and rechecked link access before accepting feedback.',
					'previewshare'
				),
				__(
					'Strengthened preview indexing protections, suppressed browser referrers, and updated a bundled dependency for security.',
					'previewshare'
				),
				__(
					'Fixed status-filtered link results and refreshed the inventory when PreviewShare detects changes during pagination.',
					'previewshare'
				),
				__(
					'Reduced repeated work when opening previews and browsing links, and made the link inventory lighter on narrower screens.',
					'previewshare'
				),
				__(
					'Improved WordPress privacy export and erasure for reviewer responses, including clearer errors and more reliable handling of large batches.',
					'previewshare'
				),
				__(
					'Improved recovery from interrupted review writes and cleanup, and made bulk link revocation more efficient.',
					'previewshare'
				),
				__(
					'Added WordPress language pack support for the settings screen, editor controls, and preview link messages.',
					'previewshare'
				),
			],
		},
		{
			version: '1.1.0',
			summary: __( 'Settings and reviewer feedback', 'previewshare' ),
			items: [
				__(
					'Redesigned the settings workspace across all five tabs.',
					'previewshare'
				),
				__(
					'Polished the admin header, plugin links, Changelog, content-type controls, and preview inventory.',
					'previewshare'
				),
				__(
					'Respected WordPress date and time formats, site timezone, and locale in link history.',
					'previewshare'
				),
				__(
					'Improved preview-link inventory filters, descriptions, dates, and content IDs.',
					'previewshare'
				),
				__(
					'Added row actions to revoke access or revoke and delete a preview-link entry, with confirmation before deletion.',
					'previewshare'
				),
				__(
					'Added retries for concurrent revocation updates and clearer errors when preview links could not all be revoked.',
					'previewshare'
				),
				__(
					'Simplified content type selection with autosaving checkboxes and kept the selected settings tab after refresh.',
					'previewshare'
				),
				__(
					'Made release notes easier to scan with version summaries, latest and installed-version labels, and expandable older entries.',
					'previewshare'
				),
				__(
					'Added expiring-link status and a fixed 24-hour extension action.',
					'previewshare'
				),
				__(
					'Added verified plugin cards in More plugins.',
					'previewshare'
				),
				__(
					'Added optional reviewer responses with approval tracking, retries that preserve edits, multilingual feedback limits, and 90-day retention.',
					'previewshare'
				),
				__(
					'Improved preview-link security by keeping concurrently generated links visible and revocable and avoiding temporary storage of generated Classic Editor and post-list URLs.',
					'previewshare'
				),
				__(
					'Kept preview sharing controls available when Classic Editor is selected for an individual post.',
					'previewshare'
				),
			],
		},
		{
			version: '1.0.2',
			summary: __( 'WordPress compatibility', 'previewshare' ),
			items: [
				__(
					'Validated preview-link editor and admin workflows with WordPress 7.1.',
					'previewshare'
				),
				__(
					'Prepared editor controls for the WordPress 7.1 component API.',
					'previewshare'
				),
			],
		},
		{
			version: '1.0.1',
			summary: __( 'Diagnostics and maintenance', 'previewshare' ),
			items: [
				__(
					'Improved diagnostics, dependency audit coverage, and release automation.',
					'previewshare'
				),
				__(
					'Refined WordPress.org metadata and plugin discoverability copy.',
					'previewshare'
				),
			],
		},
		{
			version: '1.0.0',
			summary: __( 'First release', 'previewshare' ),
			items: [
				__(
					'Added secure preview links for draft, pending, and scheduled content.',
					'previewshare'
				),
				__(
					'Added editor controls, multiple links, views, noindex directives, and revocation.',
					'previewshare'
				),
				__(
					'Added global settings and hashed token storage in post meta.',
					'previewshare'
				),
			],
		},
	];
	const hasInstalledEntry = entries.some(
		( entry ) => entry.version === installedVersion
	);

	return el(
		'div',
		{ className: 'previewshare-tab-content' },
		intro,
		el(
			'div',
			{ className: 'previewshare-changelog' },
			entries.map( ( entry, index ) =>
				el(
					'details',
					{
						className: 'previewshare-changelog-entry',
						key: entry.version,
						open:
							entry.version === installedVersion ||
							( ! hasInstalledEntry && index === 0 ),
					},
					el(
						'summary',
						null,
						el(
							'h3',
							null,
							el(
								'span',
								{
									className: 'previewshare-release-heading',
								},
								el(
									'span',
									{
										className:
											'previewshare-release-version',
									},
									'v' + entry.version,
									index === 0
										? el(
												'span',
												{
													className:
														'previewshare-release-latest',
												},
												__( 'Latest', 'previewshare' )
										  )
										: null,
									entry.version === installedVersion
										? el(
												'span',
												{
													className:
														'previewshare-release-installed',
												},
												__(
													'Installed',
													'previewshare'
												)
										  )
										: null
								),
								el(
									'span',
									{
										className:
											'previewshare-release-summary',
									},
									entry.summary
								)
							),
							el( Icon, {
								icon: chevronDown,
								size: 20,
								className: 'previewshare-release-chevron',
								'aria-hidden': true,
							} )
						)
					),
					el(
						'ul',
						null,
						entry.items.map( ( item ) =>
							el( 'li', { key: item }, item )
						)
					)
				)
			)
		)
	);
}
