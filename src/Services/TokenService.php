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
        return hash_hmac( 'sha256', $token, AUTH_SALT );
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
}
