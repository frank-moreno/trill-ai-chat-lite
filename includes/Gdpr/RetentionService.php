<?php
/**
 * Retention operations service (v2.4 PRV-03).
 *
 * Thin operational layer over the existing retention machinery:
 *
 *   - preview(): dry-run — how many conversations the current
 *     retention window would delete, WITHOUT deleting anything.
 *   - run(): manual cleanup, reusing the exact same
 *     DbManager::cleanup_old_conversations() the daily cron calls,
 *     then persisting when/how-much for the Privacy tab's stats.
 *   - get_stats(): the numbers the Privacy tab renders.
 *
 * Both the cron (CronManager::run_cleanup) and the "Run Cleanup Now"
 * button funnel through run(), so the "Last cleanup" stat reflects
 * whichever ran most recently — no parallel bookkeeping.
 *
 * The audit table (trcl_gdpr_audit) is deliberately NOT touched by
 * any cleanup — it is the compliance record (decision D3).
 *
 * @package TrillChatLite\Gdpr
 * @since 2.4.0
 * @license GPL-2.0-or-later
 */

namespace TrillChatLite\Gdpr;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use TrillChatLite\Database\DbManager;

/**
 * Class RetentionService
 *
 * SOLID: Single Responsibility — retention preview/run/stats only.
 * Deletion semantics stay in DbManager; policy (days) stays in
 * GdprSettings.
 */
class RetentionService {

    /**
     * Options persisting the last cleanup outcome (manual or cron).
     * uninstall.php clears both via the generic trcl_% sweep.
     */
    public const OPT_LAST_CLEANUP_AT    = 'trcl_last_cleanup_at';
    public const OPT_LAST_CLEANUP_COUNT = 'trcl_last_cleanup_count';

    /**
     * @var DbManager
     */
    private DbManager $db;

    /**
     * @var GdprSettings
     */
    private GdprSettings $settings;

    /**
     * Constructor.
     *
     * @param DbManager|null    $db       Injectable for tests.
     * @param GdprSettings|null $settings Injectable for tests.
     */
    public function __construct( ?DbManager $db = null, ?GdprSettings $settings = null ) {
        $this->db       = $db ?? new DbManager();
        $this->settings = $settings ?? new GdprSettings();
    }

    /**
     * Dry-run: conversations the current retention window would remove.
     *
     * Mirrors the cutoff arithmetic of
     * DbManager::cleanup_old_conversations() (started_at < now - days)
     * so Preview and Run Now can never disagree.
     *
     * @return int
     */
    public function preview(): int {
        global $wpdb;

        $days   = $this->settings->get_retention_days();
        $cutoff = \gmdate( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) );
        $table  = $wpdb->prefix . 'trcl_conversations';

        // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table from $wpdb->prefix (trusted); value bound via prepare. Block-scoped for the multi-line statement.
        return (int) $wpdb->get_var(
            $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE started_at < %s", $cutoff )
        );
        // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    }

    /**
     * Run the cleanup now and persist the outcome.
     *
     * @return int Conversations deleted.
     */
    public function run(): int {
        $deleted = $this->db->cleanup_old_conversations( $this->settings->get_retention_days() );

        \update_option( self::OPT_LAST_CLEANUP_AT, \gmdate( 'Y-m-d H:i:s' ), false );
        \update_option( self::OPT_LAST_CLEANUP_COUNT, (string) $deleted, false );

        return $deleted;
    }

    /**
     * Stats for the Privacy tab cards.
     *
     * @return array{conversations: int, messages: int, retention_days: int,
     *               last_cleanup_at: string, last_cleanup_count: int}
     */
    public function get_stats(): array {
        global $wpdb;

        $conv_table = $wpdb->prefix . 'trcl_conversations';
        $msg_table  = $wpdb->prefix . 'trcl_messages';

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table from $wpdb->prefix, no user input.
        $conversations = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$conv_table}" );
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table from $wpdb->prefix, no user input.
        $messages = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$msg_table}" );

        return [
            'conversations'      => $conversations,
            'messages'           => $messages,
            'retention_days'     => $this->settings->get_retention_days(),
            'last_cleanup_at'    => (string) \get_option( self::OPT_LAST_CLEANUP_AT, '' ),
            'last_cleanup_count' => (int) \get_option( self::OPT_LAST_CLEANUP_COUNT, 0 ),
        ];
    }
}
