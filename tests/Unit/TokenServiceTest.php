<?php
/**
 * Token service tests.
 *
 * @package PreviewShare
 */

namespace PreviewShare\Tests\Unit;

use Brain\Monkey\Functions;
use PreviewShare\Services\TokenService;

class TokenServiceTest extends TestCase {

	private const HASH_KEY = 'previewshare-test-token-hash-key-for-unit-tests';

	public function test_generate_returns_48_character_hex_token(): void {
		$service = new TokenService();
		$token   = $service->generate();

		$this->assertMatchesRegularExpression( '/^[a-f0-9]{48}$/', $token );
	}

	public function test_hash_and_verify_use_plugin_hash_key_hmac(): void {
		$service = new TokenService( self::HASH_KEY );
		$token   = 'client-preview-token';
		$hash    = hash_hmac( 'sha256', $token, self::HASH_KEY );

		$this->assertSame( $hash, $service->hash( $token ) );
		$this->assertTrue( $service->verify( $token, $hash ) );
		$this->assertFalse( $service->verify( 'different-token', $hash ) );
	}

	public function test_hash_uses_persisted_plugin_hash_key_by_default(): void {
		Functions\expect( 'get_option' )
			->once()
			->with( 'previewshare_token_hash_key', '' )
			->andReturn( self::HASH_KEY );

		$service = new TokenService();
		$token   = 'client-preview-token';

		$this->assertSame( hash_hmac( 'sha256', $token, self::HASH_KEY ), $service->hash( $token ) );
	}
}
