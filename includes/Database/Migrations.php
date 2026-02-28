<?php
/**
 * Database Migrations.
 *
 * Creates and manages database schema for the Lite plugin.
 * Tables: trcl_conversations, trcl_messages, trcl_feedback.
 *
 * @package TrillChatLite\Database
 * @since 1.0.0
 * @license GPL-2.0-or-later
 */

namespace TrillChatLite\Database;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class Migrations
 *
 * SOLID: Single Responsibility — only database schema management.
 */
class Migrations {

    /**
     * Current schema version.
     */
    private const SCHEMA_VERSION = '1.0.0';

    /**
     * Run all migrations.
     *
     * Called during plugin activation.
     */
    public static function run(): void {
        global $wpdb;

        $installed_version = \get_option( 'trcl_db_version', '0.0.0' );

        if ( version_compare( $installed_version, self::SCHEMA_VERSION, '>=' ) ) {
            return;
        }

        // Guard: ensure dbDelta() is available (may not be loaded in all contexts).
        if ( ! function_exists( 'dbDelta' ) ) {
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        }

        $charset_collate = $wpdb->get_charset_collate();

        self::create_conversations_table( $wpdb, $charset_collate );
        self::create_messages_table( $wpdb, $charset_collate );
        self::create_feedback_table( $wpdb, $charset_collate );

        \update_option( 'trcl_db_version', self::SCHEMA_VERSION );

        trcl_log( 'Database migrations completed', 'info', [
            'version' => self::SCHEMA_VERSION,
        ] );
    }

    /**
     * Create conversations table.
     *
     * @param \wpdb  $wpdb            WordPress database object.
     * @param string $charset_collate Charset and collation.
     */
    private static function create_conversations_table( \wpdb $wpdb, string $charset_collate ): void {
        $table_name = $wpdb->prefix . 'trcl_conversations';

        $sql = "CREATE TABLE IF NOT EXISTS {$table_name} (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            session_id varchar(36) NOT NULL,
            user_id bigint(20) UNSIGNED DEFAULT 0,
            customer_email varchar(255) DEFAULT NULL,
            status varchar(20) NOT NULL DEFAULT 'active',
            started_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            ended_at datetime DEFAULT NULL,
            metadata text DEFAULT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY session_id (session_id),
            KEY user_id (user_id),
            KEY status (status),
            KEY started_at (started_at)
        ) {$charset_collate};";

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Table creation DDL.
        dbDelta( $sql );
    }

    /**
     * Create messages table.
     *
     * @param \wpdb  $wpdb            WordPress database object.
     * @param string $charset_collate Charset and collation.
     */
    private static function create_messages_table( \wpdb $wpdb, string $charset_collate ): void {
        $table_name     = $wpdb->prefix . 'trcl_messages';
        $conversations  = $wpdb->prefix . 'trcl_conversations';

        $sql = "CREATE TABLE IF NOT EXISTS {$table_name} (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            conversation_id bigint(20) UNSIGNED NOT NULL,
            role enum('user','assistant','system') NOT NULL,
            content longtext NOT NULL,
            metadata text DEFAULT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY conversation_id (conversation_id),
            KEY role (role),
            KEY created_at (created_at)
        ) {$charset_collate};";

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Table creation DDL.
        dbDelta( $sql );
    }

    /**
     * Create feedback table.
     *
     * @param \wpdb  $wpdb            WordPress database object.
     * @param string $charset_collate Charset and collation.
     */
    private static function create_feedback_table( \wpdb $wpdb, string $charset_collate ): void {
        $table_name = $wpdb->prefix . 'trcl_feedback';

        $sql = "CREATE TABLE IF NOT EXISTS {$table_name} (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            message_id bigint(20) UNSIGNED NOT NULL,
            rating tinyint(1) NOT NULL,
            comment text DEFAULT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY message_id (message_id),
            KEY rating (rating)
        ) {$charset_collate};";

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Table creation DDL.
        dbDelta( $sql );
    }

    /**
     * Drop all plugin tables.
     *
     * Called during uninstall.
     */
    public static function drop_tables(): void {
        global $wpdb;

        $tables = [
            $wpdb->prefix . 'trcl_feedback',
            $wpdb->prefix . 'trcl_messages',
            $wpdb->prefix . 'trcl_conversations',
        ];

        foreach ( $tables as $table ) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Uninstall cleanup.
            $wpdb->query( "DROP TABLE IF EXISTS {$table}" );
        }

        \delete_option( 'trcl_db_version' );

        trcl_log( 'All plugin tables dropped', 'info' );
    }

    /**
     * Get current schema version.
     *
     * @return string
     */
    public static function get_version(): string {
        return self::SCHEMA_VERSION;
    }
}
