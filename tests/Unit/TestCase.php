<?php
/**
 * Base unit test case.
 *
 * @package PreviewShare
 */

namespace PreviewShare\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase as PHPUnitTestCase;

abstract class TestCase extends PHPUnitTestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( 'absint' )->alias(
			static function( $value ): int {
				return abs( (int) $value );
			}
		);

		Functions\when( 'sanitize_key' )->alias(
			static function( $key ): string {
				return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) ) ?: '';
			}
		);

		Functions\when( 'sanitize_text_field' )->alias(
			static function( $value ): string {
				return trim( wp_strip_all_tags( (string) $value ) );
			}
		);

		Functions\when( 'wp_strip_all_tags' )->alias(
			static function( string $value ): string {
				return strip_tags( $value );
			}
		);

		Functions\when( 'trailingslashit' )->alias(
			static function( string $value ): string {
				return rtrim( $value, '/\\' ) . '/';
			}
		);

		Functions\when( 'maybe_unserialize' )->alias(
			static function( $value ) {
				if ( ! is_string( $value ) ) {
					return $value;
				}

				$unserialized = @unserialize( $value );

				return false === $unserialized && 'b:0;' !== $value ? $value : $unserialized;
			}
		);

		Functions\when( 'maybe_serialize' )->alias(
			static function( $value ) {
				return is_array( $value ) || is_object( $value ) ? serialize( $value ) : $value;
			}
		);

		Functions\when( 'apply_filters' )->returnArg( 2 );
	}

	protected function tearDown(): void {
		if ( class_exists( '\PreviewShare\Container' ) ) {
			\PreviewShare\Container::set( 'token_service', null );
			\PreviewShare\Container::set( 'storage', null );
		}

		Monkey\tearDown();
		\Mockery::close();
		parent::tearDown();
	}
}
