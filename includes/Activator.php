<?php
/**
 * Fired during plugin activation.
 *
 * Handles database creation, default options, roles, and cron scheduling.
 * Simplified for Lite: no trial registration, no engine migration.
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

            // 5. Run initial product index so the chat works immediately.
            self::run_initial_index();

            // 6. Flush rewrite rules.
            \flush_rewrite_rules();

            // 7. Set activation flag.
            \update_option( 'tclw_activated', true );
            \update_option( 'tclw_activation_time', \current_time( 'mysql' ) );

            if ( function_exists( 'trill_chat_lite_log' ) ) {
                trill_chat_lite_log( 'Plugin activated successfully' );
            }

        } catch ( \Exception $e ) {
            if ( function_exists( 'trill_chat_lite_log' ) ) {
                trill_chat_lite_log( 'Activation failed: ' . $e->getMessage(), 'error' );
            }

            \wp_die(
                esc_html__( 'Trill Chat Lite activation failed: ', 'trill-ai-chat-lite' ) .
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
            'tclw_version'          => defined( 'TRILL_CHAT_LITE_VERSION' ) ? TRILL_CHAT_LITE_VERSION : '1.0.0',
            'tclw_chat_enabled'     => '1',
            'tclw_widget_position'  => 'bottom-right',
            'tclw_widget_color'     => '#10B981',
            'tclw_conversations_used'       => 0,
            'tclw_conversations_reset_date' => gmdate( 'Y-m-01' ),
        ];

        foreach ( $defaults as $option => $value ) {
            if ( get_option( $option ) === false ) {
                add_option( $option, $value );
            }
        }

        if ( function_exists( 'trill_chat_lite_log' ) ) {
            trill_chat_lite_log( 'Default options set', 'debug' );
        }
    }

    /**
     * Create custom roles and capabilities.
     */
    private static function create_roles(): void {
        $capabilities = [
            'manage_tclw_chat'    => true,
            'view_tclw_analytics' => true,
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
            if ( ! $shop_manager->has_cap( 'manage_tclw_chat' ) ) {
                $shop_manager->add_cap( 'manage_tclw_chat' );
            }
        }

        \update_option( 'tclw_capabilities', array_keys( $capabilities ) );

        if ( function_exists( 'trill_chat_lite_log' ) ) {
            trill_chat_lite_log( 'Roles and capabilities created', 'debug' );
        }
    }

    /**
     * Run initial product index so the chat can answer product queries
     * immediately after activation (without waiting for the first cron cycle).
     */
    private static function run_initial_index(): void {
        if ( ! function_exists( 'wc_get_products' ) ) {
            return;
        }

        $indexer = new WooCommerce\ProductIndexer();
        $result  = $indexer->index_products();

        if ( function_exists( 'trill_chat_lite_log' ) ) {
            trill_chat_lite_log( 'Initial product index complete', 'info', $result );
        }
    }

    /**
     * Schedule cron jobs.
     */
    private static function schedule_cron_jobs(): void {
        // Daily cleanup of old conversations.
        if ( ! \wp_next_scheduled( 'tclw_cleanup_conversations' ) ) {
            \wp_schedule_event( time(), 'daily', 'tclw_cleanup_conversations' );
        }

        // Hourly product indexing.
        if ( ! \wp_next_scheduled( 'tclw_index_products' ) ) {
            \wp_schedule_event( time(), 'hourly', 'tclw_index_products' );
        }

        if ( function_exists( 'trill_chat_lite_log' ) ) {
            trill_chat_lite_log( 'Cron jobs scheduled', 'debug' );
        }
    }
}
