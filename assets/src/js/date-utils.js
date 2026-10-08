import { dateI18n, gmdate, setSettings } from '@wordpress/date';
import { __, sprintf } from '@wordpress/i18n';

/**
 * Format a stored Unix timestamp with WordPress formats, locale and timezone.
 *
 * @param {number} timestamp Unix timestamp in seconds.
 * @param {Object} settings  Localized WordPress display formats.
 * @return {string} Localized date and time, or the no-expiration label.
 */
export function formatSiteDate( timestamp, settings = {} ) {
	settings = settings.date_settings || settings;
	const value = Number( timestamp );
	if ( ! Number.isFinite( value ) || value <= 0 ) {
		return __( 'Never', 'previewshare' );
	}
	// A Date is an absolute instant; WordPress applies its site timezone,
	// including historical DST offsets, rather than the browser's timezone.
	const getSettings =
		window.wp?.date?.getSettings ||
		window.wp?.date?.__experimentalGetSettings;
	const original = getSettings?.();
	// Apply site calendar names only during this synchronous call, then restore
	// core's user-locale settings so other editor and admin components keep them.
	if ( original && settings.date_l10n ) {
		setSettings( {
			...original,
			l10n: { ...original.l10n, ...settings.date_l10n },
		} );
	}
	try {
		return dateI18n(
			`${ settings.date_format ?? 'F j, Y' } ${
				settings.time_format ?? 'g:i a'
			}`,
			new Date( value * 1000 )
		);
	} finally {
		if ( original && settings.date_l10n ) {
			setSettings( original );
		}
	}
}

/**
 * Display legacy automatic labels without rewriting stored or custom labels.
 *
 * @param {Object} item     Stored link.
 * @param {Object} settings WordPress date display settings.
 * @return {string} Human-facing link label.
 */
export function formatPreviewLabel( item, settings = {} ) {
	const automatic =
		item.created_at &&
		`Preview link ${ gmdate(
			'Y-m-d H:i',
			new Date( item.created_at * 1000 )
		) }`;
	if ( automatic && item.label === automatic ) {
		return sprintf(
			/* translators: %s: Link creation date and time. */ __(
				'Preview link %s',
				'previewshare'
			),
			formatSiteDate( item.created_at, settings )
		);
	}
	return item.label || __( 'Preview link', 'previewshare' );
}
