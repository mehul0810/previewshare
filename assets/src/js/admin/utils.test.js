import {
	canGeneratePreview,
	getSupportedPostTypesFromConfig,
	normalizeTtlHours,
	resolvePreviewableStatus,
} from './utils';

describe( 'PreviewShare editor utils', () => {
	it( 'detects supported post types from localized config', () => {
		expect(
			getSupportedPostTypesFromConfig( { post_types: [ 'post' ] } )
		).toEqual( [ 'post' ] );
		expect( getSupportedPostTypesFromConfig( {} ) ).toEqual( [
			'post',
			'page',
		] );
	} );

	it( 'allows generation only for saved previewable content', () => {
		const baseState = {
			postId: 10,
			postType: 'post',
			postStatus: 'draft',
			supportedPostTypes: [ 'post' ],
		};

		expect( canGeneratePreview( baseState ) ).toBe( true );
		expect( canGeneratePreview( { ...baseState, postId: 0 } ) ).toBe(
			false
		);
		expect(
			canGeneratePreview( { ...baseState, postType: 'product' } )
		).toBe( false );
		expect(
			canGeneratePreview( { ...baseState, postStatus: 'auto-draft' } )
		).toBe( false );
		expect(
			canGeneratePreview( { ...baseState, isSavingPost: true } )
		).toBe( false );
	} );

	it( 'normalizes ttl values for meta persistence', () => {
		expect( normalizeTtlHours( '' ) ).toBeNull();
		expect( normalizeTtlHours( null ) ).toBeNull();
		expect( normalizeTtlHours( '12' ) ).toBe( 12 );
		expect( normalizeTtlHours( '-4' ) ).toBe( 0 );
		expect( normalizeTtlHours( 'abc' ) ).toBe( 0 );
	} );

	it( 'prefers edited status only when previewable', () => {
		expect( resolvePreviewableStatus( 'draft', 'pending' ) ).toBe(
			'pending'
		);
		expect( resolvePreviewableStatus( 'draft', 'auto-draft' ) ).toBe(
			'draft'
		);
	} );
} );
