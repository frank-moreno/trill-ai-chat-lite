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
    private const HOOK_INDEX_PRODUCTS = 'trcl_index_products';
    private const HOOK_CLEANUP        = 'trcl_cleanup_conversations';

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

        trcl_log( 'Cron events scheduled', 'info' );
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

        trcl_log( 'Cron events unscheduled', 'info' );
    }

    /**
     * Run product index refresh.
     */
    public function run_product_index(): void {
        trcl_log( 'Cron: starting product index refresh', 'info' );

        $indexer = new ProductIndexer();
        $result  = $indexer->index_products();

        trcl_log( 'Cron: product index complete', 'info', $result );
    }

    /**
     * Run conversation cleanup using the GDPR-configured retention
     * window (default 365 days, clamped to [7, 3650]).
     *
     * @see \TrillChatLite\Gdpr\GdprSettings::get_retention_days()
     */
    public function run_cleanup(): void {
        $gdpr           = new \TrillChatLite\Gdpr\GdprSettings();
        $retention_days = $gdpr->get_retention_days();

        trcl_log( 'Cron: starting conversation cleanup', 'info', [
            'retention_days' => $retention_days,
        ] );

        // Since 2.4.0 the cron funnels through RetentionService::run()
        // (same as the Privacy tab's "Run Cleanup Now") so the last-
        // cleanup stats reflect cron runs too.
        $deleted = ( new \TrillChatLite\Gdpr\RetentionService() )->run();

        trcl_log( 'Cron: cleanup complete', 'info', [
            'deleted'        => $deleted,
            'retention_days' => $retention_days,
        ] );
    }
}
