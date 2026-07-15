<?php
/**
 * Append-only event recorder for the dashboard analytics layer.
 *
 * Every meaningful interaction the plugin observes produces a row in
 * `wp_trcl_analytics_events`. The dashboard then derives its KPIs by
 * grouping / counting / summing those rows over a time window.
 *
 * Event vocabulary (v2.0):
 *
 *   chat_started      A new conversation row is created. Captures the
 *                     current WC()->session->get_customer_id() so we
 *                     can later attribute orders to this visitor's
 *                     chat session.
 *   add_to_cart       The visitor clicked add-to-cart on a product.
 *                     Stored with the product_id + qty + line value.
 *   order_completed   `woocommerce_thankyou` fired. Captures the
 *                     order_id, user_id, and order total.
 *   order_attributed  Derived: the visitor placing the order had a
 *                     recent chat (≤ 24h) with a matching wc_customer
 *                     id. Recorded once per order; idempotent.
 *
 * Recording is fire-and-forget — every method swallows exceptions and
 * logs a warning rather than disrupting the WC checkout / chat flow.
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
// The attribution lookup queries below interpolate {$wpdb->prefix . self::TABLE}
// inside $wpdb->prepare() calls. PHPCS flags the constant
// (self::EVT_CHAT_STARTED, self::EVT_ORDER_ATTRIBUTED) and the table-
// name interpolation as if they were untrusted dynamic SQL — they are
// not. Constants are compile-time strings, $wpdb->prefix comes from
// wp-config, and every user-supplied value goes through the %s / %d /
// %f placeholder system.

/**
 * Class AnalyticsRecorder
 *
 * SOLID: Single Responsibility — only writes to trcl_analytics_events.
 */
class AnalyticsRecorder {

    /**
     * Event type vocabulary. Constants instead of magic strings so a
     * typo at a call site surfaces as a parse error rather than a
     * silently-broken metric.
     */
    public const EVT_CHAT_STARTED     = 'chat_started';
    public const EVT_ADD_TO_CART      = 'add_to_cart';
    public const EVT_ORDER_COMPLETED  = 'order_completed';
    public const EVT_ORDER_ATTRIBUTED = 'order_attributed';

    /**
     * Lookback window for attribution. An order is attributed to a
     * chat if the same wc_customer_id had a chat_started event within
     * this many hours before the order completed.
     */
    public const ATTRIBUTION_WINDOW_HOURS = 24;

    /**
     * Table name (without prefix).
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
     * Insert a single event row.
     *
     * All fields are optional except `event_type`. The caller passes
     * an associative array; any missing key falls back to a sensible
     * neutral default (NULL for IDs, 0 for ints, '' / null for strings).
     *
     * @param string $event_type One of EVT_* constants.
     * @param array  $ctx        Optional event context:
     *                           - session_id      string|null Chat session UUID.
     *                           - wc_customer_id  string|null
     *                           - user_id         int  WP user (0 = guest)
     *                           - order_id        int  WC order id
     *                           - value           float|int|string
     *                           - metadata        array|string  JSON-encoded if array
     * @return int Inserted row id, or 0 on failure.
     */
    public function record( string $event_type, array $ctx = [] ): int {
        if ( $event_type === '' ) {
            return 0;
        }

        $table = $this->wpdb->prefix . self::TABLE;

        $data = [
            'event_type'     => $event_type,
            'session_id'     => isset( $ctx['session_id'] ) && $ctx['session_id'] !== '' ? (string) $ctx['session_id'] : null,
            'wc_customer_id' => isset( $ctx['wc_customer_id'] ) && $ctx['wc_customer_id'] !== '' ? (string) $ctx['wc_customer_id'] : null,
            'user_id'        => isset( $ctx['user_id'] ) ? (int) $ctx['user_id'] : 0,
            'order_id'       => isset( $ctx['order_id'] ) ? (int) $ctx['order_id'] : 0,
            'value'          => isset( $ctx['value'] ) ? (float) $ctx['value'] : 0.0,
            'metadata'       => $this->encode_metadata( $ctx['metadata'] ?? null ),
        ];

        $formats = [ '%s', '%s', '%s', '%d', '%d', '%f', '%s' ];

        try {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $ok = $this->wpdb->insert( $table, $data, $formats );

            if ( $ok === false ) {
                trcl_log( 'AnalyticsRecorder insert failed', 'warning', [
                    'event_type' => $event_type,
                    'error'      => $this->wpdb->last_error,
                ] );
                return 0;
            }
            return (int) $this->wpdb->insert_id;
        } catch ( \Throwable $e ) {
            trcl_log( 'AnalyticsRecorder threw', 'warning', [
                'event_type' => $event_type,
                'error'      => $e->getMessage(),
            ] );
            return 0;
        }
    }

    // =========================================================================
    // CONVENIENCE WRAPPERS — keep call sites short.
    // =========================================================================

    /**
     * Record a chat_started event. Captures the current WC session
     * customer id when WC is loaded, so we can later attribute orders.
     *
     * @param string $session_id Chat session UUID.
     * @param int    $user_id    WP user id (0 for guest).
     */
    public function record_chat_started( string $session_id, int $user_id = 0 ): int {
        return $this->record( self::EVT_CHAT_STARTED, [
            'session_id'     => $session_id,
            'user_id'        => $user_id,
            'wc_customer_id' => self::current_wc_customer_id(),
        ] );
    }

    /**
     * Record an add_to_cart event.
     *
     * @param int    $product_id  Product ID.
     * @param int    $qty         Quantity added.
     * @param float  $line_total  qty * unit price.
     */
    public function record_add_to_cart( int $product_id, int $qty, float $line_total ): int {
        return $this->record( self::EVT_ADD_TO_CART, [
            'value'          => $line_total,
            'wc_customer_id' => self::current_wc_customer_id(),
            'user_id'        => \get_current_user_id(),
            'metadata'       => [
                'product_id' => $product_id,
                'qty'        => $qty,
            ],
        ] );
    }

    /**
     * Record an order_completed event AND attempt attribution to a
     * recent chat session for the same wc_customer_id.
     *
     * Returns an array describing what was written:
     *   completed_id   id of the order_completed row
     *   attributed_id  id of the order_attributed row (0 if no chat
     *                  matched within the lookback window)
     *
     * @param int $order_id WC order ID.
     * @return array{completed_id: int, attributed_id: int}
     */
    public function record_order_and_attribute( int $order_id ): array {
        if ( $order_id <= 0 ) {
            return [ 'completed_id' => 0, 'attributed_id' => 0 ];
        }
        if ( ! function_exists( 'wc_get_order' ) ) {
            return [ 'completed_id' => 0, 'attributed_id' => 0 ];
        }

        $order = \wc_get_order( $order_id );
        if ( ! $order ) {
            return [ 'completed_id' => 0, 'attributed_id' => 0 ];
        }

        $wc_customer_id = self::current_wc_customer_id();
        $user_id        = (int) $order->get_user_id();
        $total          = (float) $order->get_total();

        $completed_id = $this->record( self::EVT_ORDER_COMPLETED, [
            'order_id'       => $order_id,
            'user_id'        => $user_id,
            'value'          => $total,
            'wc_customer_id' => $wc_customer_id,
        ] );

        $attributed_id = $this->maybe_attribute( $order_id, $user_id, $total, $wc_customer_id );

        return [
            'completed_id'  => $completed_id,
            'attributed_id' => $attributed_id,
        ];
    }

    // =========================================================================
    // INTERNALS
    // =========================================================================

    /**
     * Look up a recent chat session for the same wc_customer_id (or
     * for the same logged-in user_id) within ATTRIBUTION_WINDOW_HOURS.
     * If found, record an order_attributed event. Idempotent: if this
     * order has already been attributed, no second row is written.
     *
     * @return int Attribution row id, or 0 if no match.
     */
    private function maybe_attribute(
        int $order_id,
        int $user_id,
        float $total,
        ?string $wc_customer_id
    ): int {
        $table = $this->wpdb->prefix . self::TABLE;

        // Idempotency guard — has this order already been attributed?
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $already = (int) $this->wpdb->get_var(
            $this->wpdb->prepare(
                "SELECT id FROM {$table}
                  WHERE event_type = %s AND order_id = %d
                  LIMIT 1",
                self::EVT_ORDER_ATTRIBUTED,
                $order_id
            )
        );
        if ( $already > 0 ) {
            return $already;
        }

        $cutoff = \gmdate(
            'Y-m-d H:i:s',
            time() - ( self::ATTRIBUTION_WINDOW_HOURS * HOUR_IN_SECONDS )
        );

        $chat_session = null;

        // 1) Try wc_customer_id match (covers guests).
        if ( $wc_customer_id !== null && $wc_customer_id !== '' ) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $chat_session = $this->wpdb->get_var(
                $this->wpdb->prepare(
                    "SELECT session_id FROM {$table}
                      WHERE event_type = %s
                        AND wc_customer_id = %s
                        AND created_at >= %s
                      ORDER BY created_at DESC
                      LIMIT 1",
                    self::EVT_CHAT_STARTED,
                    $wc_customer_id,
                    $cutoff
                )
            );
        }

        // 2) Fallback: user_id match (covers logged-in users whose WC
        //    session id may have rolled, but whose user_id stays put).
        if ( ! $chat_session && $user_id > 0 ) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $chat_session = $this->wpdb->get_var(
                $this->wpdb->prepare(
                    "SELECT session_id FROM {$table}
                      WHERE event_type = %s
                        AND user_id = %d
                        AND created_at >= %s
                      ORDER BY created_at DESC
                      LIMIT 1",
                    self::EVT_CHAT_STARTED,
                    $user_id,
                    $cutoff
                )
            );
        }

        if ( ! $chat_session ) {
            return 0;
        }

        return $this->record( self::EVT_ORDER_ATTRIBUTED, [
            'session_id'     => (string) $chat_session,
            'wc_customer_id' => $wc_customer_id,
            'user_id'        => $user_id,
            'order_id'       => $order_id,
            'value'          => $total,
        ] );
    }

    /**
     * Read the current WC visit's customer id, or NULL when WC isn't
     * loaded / sessions aren't initialised (e.g. during WP-CLI).
     *
     * @return string|null
     */
    public static function current_wc_customer_id(): ?string {
        if ( ! function_exists( 'WC' ) ) {
            return null;
        }
        $wc = \WC();
        if ( ! $wc || ! isset( $wc->session ) || ! $wc->session ) {
            return null;
        }
        $id = $wc->session->get_customer_id();
        return is_string( $id ) && $id !== '' ? $id : null;
    }

    /**
     * Encode an optional metadata blob as JSON for the TEXT column.
     *
     * @param array|string|null $metadata
     * @return string|null
     */
    private function encode_metadata( $metadata ): ?string {
        if ( $metadata === null ) {
            return null;
        }
        if ( is_string( $metadata ) ) {
            return $metadata;
        }
        $json = \wp_json_encode( $metadata );
        return is_string( $json ) ? $json : null;
    }
}
