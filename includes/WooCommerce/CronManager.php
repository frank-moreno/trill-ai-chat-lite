<?php
/**
 * Cron Manager for scheduled tasks.
 *
 * Manages scheduled tasks: product indexing refresh and conversation cleanup.
 * Simplified for Lite: fewer tasks, longer intervals.
 *
 * @package TrillChatLite\WooCommerce
 * @since 1.0.0
 * @license GPL-2.0-or-later
 */

namespace TrillChatLite\WooCommerce;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use TrillChatLite\Database\DbManager;

/**
 * Class CronManager
 *
 * SOLID: Single Responsibility — only scheduled task management.
 */
class CronManager {

    /**
     * Cron hook names.
     */
    private const HOOK_INDEX_PRODUCTS = 'tclw_index_products';
    private const HOOK_CLEANUP        = 'tclw_cleanup_conversations';

    /**
     * Initialise cron hooks.
     */
    public function init(): void {
        \add_action( self::HOOK_INDEX_PRODUCTS, [ $this, 'run_product_index' ] );
        \add_action( self::HOOK_CLEANUP, [ $this, 'run_cleanup' ] );
    }

    /**
     * Schedule all cron events.
     *
     * Called during plugin activation.
     */
    public static function schedule(): void {
        if ( ! \wp_next_scheduled( self::HOOK_INDEX_PRODUCTS ) ) {
            \wp_schedule_event( time(), 'daily', self::HOOK_INDEX_PRODUCTS );
        }

        if ( ! \wp_next_scheduled( self::HOOK_CLEANUP ) ) {
            \wp_schedule_event( time(), 'daily', self::HOOK_CLEANUP );
        }

        trill_chat_lite_log( 'Cron events scheduled', 'info' );
    }

    /**
     * Unschedule all cron events.
     *
     * Called during plugin deactivation.
     */
    public static function unschedule(): void {
        $hooks = [
            self::HOOK_INDEX_PRODUCTS,
            self::HOOK_CLEANUP,
        ];

        foreach ( $hooks as $hook ) {
            $timestamp = \wp_next_scheduled( $hook );
            if ( $timestamp ) {
                \wp_unschedule_event( $timestamp, $hook );
            }
        }

        trill_chat_lite_log( 'Cron events unscheduled', 'info' );
    }

    /**
     * Run product index refresh.
     */
    public function run_product_index(): void {
        trill_chat_lite_log( 'Cron: starting product index refresh', 'info' );

        $indexer = new ProductIndexer();
        $result  = $indexer->index_products();

        trill_chat_lite_log( 'Cron: product index complete', 'info', $result );
    }

    /**
     * Run conversation cleanup.
     */
    public function run_cleanup(): void {
        trill_chat_lite_log( 'Cron: starting conversation cleanup', 'info' );

        $db      = new DbManager();
        $deleted = $db->cleanup_old_conversations( 30 );

        trill_chat_lite_log( 'Cron: cleanup complete', 'info', [
            'deleted' => $deleted,
        ] );
    }
}
