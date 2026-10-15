import { setSettings, getSettings } from '@wordpress/date';
import { formatSiteDate, formatPreviewLabel } from './date-utils';

const formats = { date_format: 'Y-m-d', time_format: 'H:i' };

function configure( timezone, locale = 'en', months ) {
	setSettings( {
		l10n: {
			locale,
			months: months || [
				'January',
				'February',
				'March',
				'April',
				'May',
				'June',
				'July',
				'August',
				'September',
				'October',
				'November',
				'December',
			],
			monthsShort: [
				'Jan',
				'Feb',
				'Mar',
				'Apr',
				'May',
				'Jun',
				'Jul',
				'Aug',
				'Sep',
				'Oct',
				'Nov',
				'Dec',
			],
			weekdays: [
				'Sunday',
				'Monday',
				'Tuesday',
				'Wednesday',
				'Thursday',
				'Friday',
				'Saturday',
			],
			weekdaysShort: [ 'Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat' ],
			meridiem: { am: 'am', pm: 'pm', AM: 'AM', PM: 'PM' },
			relative: {},
			startOfWeek: 1,
		},
		formats: { datetime: 'F j, Y g:i a' },
		timezone: {
			string: timezone,
			offset: -5,
			offsetFormatted: '-5',
			abbr: '',
		},
	} );
}

describe( 'site date display', () => {
	beforeEach( () => {
		configure( 'America/New_York' );
		window.wp = { date: { getSettings } };
	} );

	it( 'uses PHP custom formats and literal escapes', () => {
		expect(
			formatSiteDate( Date.parse( '2026-01-15T18:05:00Z' ) / 1000, {
				date_format: 'd/m/Y',
				time_format: 'H:i \\h',
			} )
		).toBe( '15/01/2026 13:05 h' );
	} );

	it( 'applies historical DST offsets across the spring gap', () => {
		expect(
			formatSiteDate(
				Date.parse( '2026-03-08T06:59:00Z' ) / 1000,
				formats
			)
		).toBe( '2026-03-08 01:59' );
		expect(
			formatSiteDate(
				Date.parse( '2026-03-08T07:01:00Z' ) / 1000,
				formats
			)
		).toBe( '2026-03-08 03:01' );
	} );

	it( 'preserves absolute instants across the autumn repeated hour', () => {
		expect(
			formatSiteDate( Date.parse( '2026-11-01T05:30:00Z' ) / 1000, {
				...formats,
				time_format: 'H:i O',
			} )
		).toBe( '2026-11-01 01:30 -0400' );
		expect(
			formatSiteDate( Date.parse( '2026-11-01T06:30:00Z' ) / 1000, {
				...formats,
				time_format: 'H:i O',
			} )
		).toBe( '2026-11-01 01:30 -0500' );
	} );

	it( 'uses WordPress translated month names', () => {
		configure( 'Europe/Paris', 'fr', [
			'janvier',
			'février',
			'mars',
			'avril',
			'mai',
			'juin',
			'juillet',
			'août',
			'septembre',
			'octobre',
			'novembre',
			'décembre',
		] );
		expect(
			formatSiteDate( Date.parse( '2026-01-15T18:05:00Z' ) / 1000, {
				date_format: 'j F Y',
				time_format: 'H:i',
			} )
		).toBe( '15 janvier 2026 19:05' );
	} );

	it( 'uses no-expiration copy for missing or invalid values', () => {
		for ( const timestamp of [ 0, null, undefined, 'invalid' ] ) {
			expect( formatSiteDate( timestamp, formats ) ).toBe( 'Never' );
		}
	} );
	it( 'formats automatic labels but preserves custom labels and stored data', () => {
		const item = {
			created_at: Date.parse( '2026-01-15T18:05:00Z' ) / 1000,
			label: 'Preview link 2026-01-15 18:05',
		};
		expect( formatPreviewLabel( item, formats ) ).toBe(
			'Preview link 2026-01-15 13:05'
		);
		expect( item.label ).toBe( 'Preview link 2026-01-15 18:05' );
		expect(
			formatPreviewLabel( { ...item, label: 'Client review' }, formats )
		).toBe( 'Client review' );
	} );

	it( 'restores core date settings after applying site calendar names', () => {
		const original = getSettings();
		const siteCalendar = {
			...original.l10n,
			locale: 'fr',
			months: [
				'janvier',
				'février',
				'mars',
				'avril',
				'mai',
				'juin',
				'juillet',
				'août',
				'septembre',
				'octobre',
				'novembre',
				'décembre',
			],
		};
		expect(
			formatSiteDate( Date.parse( '2026-01-15T18:05:00Z' ) / 1000, {
				date_settings: {
					date_format: 'j F Y',
					time_format: 'H:i',
					date_l10n: siteCalendar,
				},
			} )
		).toBe( '15 janvier 2026 13:05' );
		expect( getSettings() ).toEqual( original );
	} );
} );
