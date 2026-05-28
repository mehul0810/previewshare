<?php
/**
 * Token service.
 *
 * @package PreviewShare
 */

namespace PreviewShare\Services;

// Abort if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class TokenService
 *
 * Responsible for generating and validating preview tokens.
 */
class TokenService {

	/**
	 * Optional token hash key override.
	 *
	 * @var string|null
	 */
	private $hash_key;

	/**
	 * Constructor.
	 *
	 * @param string|null $hash_key Optional token hash key, useful for tests.
	 */
	public function __construct( ?string $hash_key = null ) {
		$this->hash_key = $hash_key;
	}

	/**
	 * Generate a new random token for user consumption.
	 *
	 * @return string Raw token string.
	 */
	public function generate(): string {
		return bin2hex( random_bytes( 24 ) );
	}

	/**
	 * Hash a token for storage/lookup.
	 *
	 * @param string $token Raw token.
	 * @return string Hash of token.
	 */
	public function hash( string $token ): string {
		return hash_hmac( 'sha256', $token, $this->get_hash_key() );
	}

	/**
	 * Verify a raw token against a stored hash.
	 *
	 * @param string $token Raw token.
	 * @param string $stored_hash Stored token hash.
	 * @return bool True when match.
	 */
	public function verify( string $token, string $stored_hash ): bool {
		return hash_equals( $stored_hash, $this->hash( $token ) );
	}

	/**
	 * Resolve the hash key used for token storage.
	 *
	 * @return string Hash key.
	 */
	private function get_hash_key(): string {
		if ( is_string( $this->hash_key ) && '' !== $this->hash_key ) {
			return $this->hash_key;
		}

		return \previewshare_get_token_hash_key();
	}
}
