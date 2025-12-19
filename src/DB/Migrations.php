<?php
/**
 * Database migrations for PreviewShare.
 *
 * @package PreviewShare
 */

namespace PreviewShare\DB;

// Abort if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class Migrations
 */
class Migrations {

    /**
     * Create the tokens table.
     *
     * @return void
     */
    public static function create_table(): void {
        global $wpdb;

        $table_name = $wpdb->prefix . 'previewshare_tokens';
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table_name} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            post_id bigint(20) unsigned NOT NULL,
            token_hash char(64) NOT NULL,
            user_id bigint(20) unsigned DEFAULT NULL,
            created_at bigint(20) unsigned NOT NULL,
            expires_at bigint(20) unsigned DEFAULT NULL,
            revoked tinyint(1) NOT NULL DEFAULT 0,
            PRIMARY KEY  (id),
            KEY token_hash (token_hash),
            KEY post_id (post_id)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
    }
}
