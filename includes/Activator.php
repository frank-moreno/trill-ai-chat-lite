<?php
/**
 * Fired during plugin activation.
 *
 * Handles database creation, default options, roles, and cron scheduling.
 * Simplified for Lite: no trial registration, no engine migration.
 *
 * @package GspltdChatLite
 * @since 1.0.0
 * @license GPL-2.0-or-later
 */

namespace GspltdChatLite;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Activator class.
 *
 * Single Responsibility: Handle plugin activation tasks only.
 */
class Activator {

    /**
     * Execute activation routines.
     */
    public static function activate(): void {
        try {
            // 1. Run database migrations.
            self::run_migrations();

            // 2. Set default options.
            self::set_default_options();

            // 3. Create roles and capabilities.
            self::create_roles();

            // 4. Schedule cron jobs.
            self::schedule_cron_jobs();

            // 5. Flush rewrite rules.
            \flush_rewrite_rules();

            // 6. Set activation flag.
            \update_option( 'gcl_activated', true );
            \update_option( 'gcl_activation_time', \current_time( 'mysql' ) );

            if ( function_exists( 'gcl_log' ) ) {
                gcl_log( 'Plugin activated successfully' );
            }

        } catch ( \Exception $e ) {
            if ( function_exists( 'gcl_log' ) ) {
                gcl_log( 'Activation failed: ' . $e->getMessage(), 'error' );
            }

            \wp_die(
                esc_html__( 'GSPLTD Chat Lite activation failed: ', 'gspltd-chat-lite' ) .
                esc_html( $e->getMessage() )
            );
        }
    }

    /**
     * Run database migrations.
     */
    private static function run_migrations(): void {
        $migrations = new Database\Migrations();
        $migrations->run();
    }

    /**
     * Set default plugin options.
     */
    private static function set_default_options(): void {
        $defaults = [
            'gcl_version'          => defined( 'GCL_VERSION' ) ? GCL_VERSION : '1.0.0',
            'gcl_chat_enabled'     => '1',
            'gcl_widget_position'  => 'bottom-right',
            'gcl_widget_color'     => '#10B981',
            'gcl_conversations_used'       => 0,
            'gcl_conversations_reset_date' => gmdate( 'Y-m-01' ),
        ];

        foreach ( $defaults as $option => $value ) {
            if ( get_option( $option ) === false ) {
                add_option( $option, $value );
            }
        }

        if ( function_exists( 'gcl_log' ) ) {
            gcl_log( 'Default options set', 'debug' );
        }
    }

    /**
     * Create custom roles and capabilities.
     */
    private static function create_roles(): void {
        $capabilities = [
            'manage_gcl_chat'    => true,
            'view_gcl_analytics' => true,
        ];

        // Add capabilities to administrator.
        $admin_role = \get_role( 'administrator' );
        if ( $admin_role ) {
            foreach ( $capabilities as $capability => $grant ) {
                if ( ! $admin_role->has_cap( $capability ) ) {
                    $admin_role->add_cap( $capability );
                }
            }
        }

        // Add limited capabilities to shop_manager.
        $shop_manager = \get_role( 'shop_manager' );
        if ( $shop_manager ) {
            if ( ! $shop_manager->has_cap( 'manage_gcl_chat' ) ) {
                $shop_manager->add_cap( 'manage_gcl_chat' );
            }
        }

        \update_option( 'gcl_capabilities', array_keys( $capabilities ) );

        if ( function_exists( 'gcl_log' ) ) {
            gcl_log( 'Roles and capabilities created', 'debug' );
        }
    }

    /**
     * Schedule cron jobs.
     */
    private static function schedule_cron_jobs(): void {
        // Daily cleanup of old conversations.
        if ( ! \wp_next_scheduled( 'gcl_cleanup_conversations' ) ) {
            \wp_schedule_event( time(), 'daily', 'gcl_cleanup_conversations' );
        }

        // Hourly product indexing.
        if ( ! \wp_next_scheduled( 'gcl_index_products' ) ) {
            \wp_schedule_event( time(), 'hourly', 'gcl_index_products' );
        }

        if ( function_exists( 'gcl_log' ) ) {
            gcl_log( 'Cron jobs scheduled', 'debug' );
        }
    }
}
