<?php
/**
 * Simple service container for PreviewShare.
 *
 * Provides a minimal global registry for shared services. This keeps the
 * code modular while allowing procedural code to access shared services.
 *
 * @package PreviewShare
 */

namespace PreviewShare;

// Abort if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class Container
 */
class Container {

    /**
     * Services map.
     *
     * @var array
     */
    private static $services = [];

    /**
     * Set a service by key.
     *
     * @param string $key Key name.
     * @param mixed  $service Service instance.
     * @return void
     */
    public static function set( string $key, $service ): void {
        self::$services[ $key ] = $service;
    }

    /**
     * Get a service by key.
     *
     * @param string $key Key name.
     * @return mixed|null
     */
    public static function get( string $key ) {
        return self::$services[ $key ] ?? null;
    }

    /**
     * Convenience getter for token service.
     *
     * @return mixed|null
     */
    public static function token_service() {
        return self::get( 'token_service' );
    }

    /**
     * Convenience getter for storage.
     *
     * @return mixed|null
     */
    public static function storage() {
        return self::get( 'storage' );
    }
}
