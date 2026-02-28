<?php
/**
 * Fired during plugin deactivation.
 *
 * Handles cron cleanup and optional role removal.
 * Does NOT delete data — that is handled by uninstall.php.
 *
 * @package TrillChatLite
 * @since 1.0.0
 * @license GPL-2.0-or-later
 */

namespace TrillChatLite;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Deactivator class.
 *
 * Single Responsibility: Handle plugin deactivation tasks only.
 */
class Deactivator {

    /**
     * Execute deactivation routines.
     */
    public static function deactivate(): void {
        try {
            // 1. Clear scheduled cron jobs.
            self::clear_cron_jobs();

            // 2. Clear transients.
            self::clear_transients();

            // 3. Flush rewrite rules.
            \flush_rewrite_rules();

            // 4. Set deactivation flag.
            \update_option( 'trcl_activated', false );
            \update_option( 'trcl_deactivation_time', \current_time( 'mysql' ) );

            if ( function_exists( 'trcl_log' ) ) {
                trcl_log( 'Plugin deactivated successfully' );
            }

        } catch ( \Exception $e ) {
            if ( function_exists( 'trcl_log' ) ) {
                trcl_log( 'Deactivation failed: ' . $e->getMessage(), 'error' );
            }
        }
    }

    /**
     * Clear all scheduled cron jobs.
     */
    private static function clear_cron_jobs(): void {
        $hooks = [
            'trcl_cleanup_conversations',
            'trcl_index_products',
        ];

        foreach ( $hooks as $hook ) {
            $timestamp = \wp_next_scheduled( $hook );
            if ( $timestamp ) {
                \wp_unschedule_event( $timestamp, $hook );
            }
        }
    }

    /**
     * Clear plugin transients.
     */
    private static function clear_transients(): void {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->query(
            "DELETE FROM {$wpdb->options}
             WHERE option_name LIKE '_transient_trcl_%'
             OR option_name LIKE '_transient_timeout_trcl_%'"
        );
    }
}
