<?php
/**
 * Token service tests.
 *
 * @package PreviewShare
 */

namespace PreviewShare\Tests\Unit;

use PreviewShare\Services\TokenService;

class TokenServiceTest extends TestCase {

	public function test_generate_returns_48_character_hex_token(): void {
		$service = new TokenService();
		$token   = $service->generate();

		$this->assertMatchesRegularExpression( '/^[a-f0-9]{48}$/', $token );
	}

	public function test_hash_and_verify_use_auth_salt_hmac(): void {
		$service = new TokenService();
		$token   = 'client-preview-token';
		$hash    = hash_hmac( 'sha256', $token, AUTH_SALT );

		$this->assertSame( $hash, $service->hash( $token ) );
		$this->assertTrue( $service->verify( $token, $hash ) );
		$this->assertFalse( $service->verify( 'different-token', $hash ) );
	}
}
