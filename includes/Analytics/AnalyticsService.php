<?php
/**
 * Read-only KPI service over `wp_trcl_analytics_events`.
 *
 * Backs the merchant dashboard. Every public method takes a time
 * window (either an explicit start/end or a "last N days" shortcut)
 * and returns a single scalar so the view layer can drop it straight
 * into a KPI card without further massaging.
 *
 * Performance note: the events table is append-only and indexed on
 * `event_type` + `created_at`, so a "last 30 days" query stays fast
 * even on stores with tens of thousands of events. We never JOIN
 * across event types in the same query — attribution is precomputed
 * at insert time via AnalyticsRecorder so reads are pure SELECTs.
 *
 * @package TrillChatLite\Analytics
 * @since 2.0.0
 * @license GPL-2.0-or-later
 */

namespace TrillChatLite\Analytics;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
//
// All $wpdb->get_var() calls in this file ARE wrapped in
// $wpdb->prepare(). PHPCS misreads {$wpdb->prefix . self::TABLE} as
// "interpolation inside a prepared statement", but the only dynamic
// piece is a hard-coded class constant concatenated with $wpdb->prefix
// (sourced from wp-config, never from user input). Every prepare()
// passes user-supplied values exclusively through %s / %d / %f
// placeholders.

/**
 * Class AnalyticsService
 *
 * SOLID: Single Responsibility — only reads aggregated KPIs.
 */
class AnalyticsService {

    /**
     * Periods supported by the dashboard selector. Days, inclusive of
     * today. Clamped to known values to keep queries cacheable.
     */
    public const PERIOD_7  = 7;
    public const PERIOD_30 = 30;
    public const PERIOD_90 = 90;

    /**
     * Table name without prefix.
     */
    private const TABLE = 'trcl_analytics_events';

    /**
     * @var \wpdb
     */
    private \wpdb $wpdb;

    public function __construct() {
        global $wpdb;
        $this->wpdb = $wpdb;
    }

    /**
     * One-shot aggregate for the dashboard.
     *
     * Returns every KPI the four cards need in a single call. Each
     * underlying query touches one event_type partition, so the cost
     * is four index scans — cheap.
     *
     * @param int $days Lookback window (7, 30, or 90).
     * @return array{
     *     period_days: int,
     *     chats_started: int,
     *     orders_completed: int,
     *     orders_attributed: int,
     *     revenue_attributed: float,
     *     attribution_rate: float,
     * }
     */
    public function get_summary( int $days = self::PERIOD_30 ): array {
        $days   = $this->clamp_period( $days );
        $cutoff = $this->cutoff_for_days( $days );

        $chats     = $this->count_event( AnalyticsRecorder::EVT_CHAT_STARTED, $cutoff );
        $orders    = $this->count_event( AnalyticsRecorder::EVT_ORDER_COMPLETED, $cutoff );
        $attrib    = $this->count_event( AnalyticsRecorder::EVT_ORDER_ATTRIBUTED, $cutoff );
        $revenue   = $this->sum_event_value( AnalyticsRecorder::EVT_ORDER_ATTRIBUTED, $cutoff );

        $rate = $orders > 0 ? round( ( $attrib / $orders ) * 100, 1 ) : 0.0;

        return [
            'period_days'        => $days,
            'chats_started'      => $chats,
            'orders_completed'   => $orders,
            'orders_attributed'  => $attrib,
            'revenue_attributed' => $revenue,
            'attribution_rate'   => $rate,
        ];
    }

    /**
     * Count of chat_started events in the window.
     */
    public function get_chat_count( int $days = self::PERIOD_30 ): int {
        return $this->count_event(
            AnalyticsRecorder::EVT_CHAT_STARTED,
            $this->cutoff_for_days( $this->clamp_period( $days ) )
        );
    }

    /**
     * Count of order_completed events in the window.
     */
    public function get_order_count( int $days = self::PERIOD_30 ): int {
        return $this->count_event(
            AnalyticsRecorder::EVT_ORDER_COMPLETED,
            $this->cutoff_for_days( $this->clamp_period( $days ) )
        );
    }

    /**
     * Count of order_attributed events in the window.
     */
    public function get_attributed_order_count( int $days = self::PERIOD_30 ): int {
        return $this->count_event(
            AnalyticsRecorder::EVT_ORDER_ATTRIBUTED,
            $this->cutoff_for_days( $this->clamp_period( $days ) )
        );
    }

    /**
     * Sum of order_attributed.value in the window. Returns float so
     * the caller can format with the store currency.
     */
    public function get_attributed_revenue( int $days = self::PERIOD_30 ): float {
        return $this->sum_event_value(
            AnalyticsRecorder::EVT_ORDER_ATTRIBUTED,
            $this->cutoff_for_days( $this->clamp_period( $days ) )
        );
    }

    // =========================================================================
    // INTERNALS
    // =========================================================================

    /**
     * @return int
     */
    private function count_event( string $event_type, string $cutoff ): int {
        $table = $this->wpdb->prefix . self::TABLE;

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $count = $this->wpdb->get_var(
            $this->wpdb->prepare(
                "SELECT COUNT(*) FROM {$table}
                  WHERE event_type = %s AND created_at >= %s",
                $event_type,
                $cutoff
            )
        );

        return (int) ( $count ?? 0 );
    }

    /**
     * @return float
     */
    private function sum_event_value( string $event_type, string $cutoff ): float {
        $table = $this->wpdb->prefix . self::TABLE;

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $sum = $this->wpdb->get_var(
            $this->wpdb->prepare(
                "SELECT COALESCE(SUM(value), 0) FROM {$table}
                  WHERE event_type = %s AND created_at >= %s",
                $event_type,
                $cutoff
            )
        );

        return (float) ( $sum ?? 0 );
    }

    /**
     * Clamp arbitrary input to a supported period.
     *
     * @param int $days
     * @return int One of 7 / 30 / 90.
     */
    private function clamp_period( int $days ): int {
        if ( $days <= 7 ) {
            return self::PERIOD_7;
        }
        if ( $days <= 30 ) {
            return self::PERIOD_30;
        }
        return self::PERIOD_90;
    }

    /**
     * Compute the SQL cutoff timestamp for "last N days".
     *
     * Uses GMT so the boundary doesn't drift across the merchant's
     * timezone settings.
     *
     * @param int $days
     * @return string `Y-m-d H:i:s` in UTC.
     */
    private function cutoff_for_days( int $days ): string {
        return \gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );
    }
}
