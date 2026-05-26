<?php
/**
 * Verified order lookup for the chat order-tracking flow.
 *
 * Two authenticated paths into the WooCommerce orders table:
 *
 *   1. find_for_logged_in_user(user_id) — returns the visitor's recent
 *      orders by matching wp_users.ID. Cheap, guaranteed to be theirs
 *      because WP's auth already proved the user_id at request time.
 *
 *   2. find_by_id_and_email(order_id, email) — the privacy-critical
 *      path for guests. We only return an order when its billing_email
 *      matches the caller-supplied email AFTER both sides are
 *      normalised (lowercased + trimmed). No partial matches, no
 *      "starts with" tricks. Mismatch -> null, never the wrong order.
 *
 * Output shape (format_for_prompt) is intentionally narrow — just
 * enough for Robin to answer "where's my order" without leaking
 * shipping addresses, internal notes, or other personal data that
 * the visitor didn't ask for.
 *
 * @package TrillChatLite\WooCommerce
 * @since 2.0.0
 * @license GPL-2.0-or-later
 */

namespace TrillChatLite\WooCommerce;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class OrderLookup
 *
 * SOLID: Single Responsibility — only verified order retrieval.
 */
class OrderLookup {

    /**
     * Max recent orders we expose to Robin for a logged-in user.
     * Keeps the prompt token-bounded and limits how much history a
     * compromised session could enumerate in one prompt.
     */
    public const MAX_ORDERS_PER_LOOKUP = 3;

    /**
     * Return up to N most-recent orders for the given WP user.
     *
     * Trusted path: the caller has already authenticated the user via
     * WP's session, so no extra email check is needed. We still scope
     * to the user_id to avoid accidental cross-account exposure if a
     * future call site forgets to pass the right id.
     *
     * @param int $user_id  WP user id (must be > 0).
     * @param int $limit    Max orders to return.
     * @return array<int, \WC_Order>
     */
    public function find_for_logged_in_user( int $user_id, int $limit = self::MAX_ORDERS_PER_LOOKUP ): array {
        if ( $user_id <= 0 || ! function_exists( 'wc_get_orders' ) ) {
            return [];
        }

        try {
            $orders = \wc_get_orders( [
                'customer_id' => $user_id,
                'limit'       => max( 1, min( 20, $limit ) ),
                'orderby'     => 'date',
                'order'       => 'DESC',
            ] );
        } catch ( \Throwable $e ) {
            trcl_log( 'OrderLookup: find_for_logged_in_user failed', 'warning', [
                'error' => $e->getMessage(),
            ] );
            return [];
        }

        return is_array( $orders ) ? $orders : [];
    }

    /**
     * Return an order ONLY when the supplied email matches the order's
     * billing email after normalisation. Used for guest verification.
     *
     * Constant-time comparison would be ideal here to avoid timing
     * attacks on email enumeration, but the WC layer already touches
     * the DB on every lookup so the timing signal is dominated by
     * MySQL latency. We use a plain string compare on lowercased,
     * trimmed strings.
     *
     * @param int    $order_id WC order id.
     * @param string $email    Email claimed by the visitor.
     * @return \WC_Order|null Verified order, or null on any mismatch.
     */
    public function find_by_id_and_email( int $order_id, string $email ): ?\WC_Order {
        if ( $order_id <= 0 || ! function_exists( 'wc_get_order' ) ) {
            return null;
        }

        $normalised_email = strtolower( trim( \sanitize_email( $email ) ) );
        if ( $normalised_email === '' ) {
            return null;
        }

        try {
            $order = \wc_get_order( $order_id );
        } catch ( \Throwable $e ) {
            trcl_log( 'OrderLookup: wc_get_order threw', 'warning', [
                'order_id' => $order_id,
                'error'    => $e->getMessage(),
            ] );
            return null;
        }

        if ( ! $order || ! method_exists( $order, 'get_billing_email' ) ) {
            return null;
        }

        $billing = strtolower( trim( (string) $order->get_billing_email() ) );
        if ( $billing === '' || $billing !== $normalised_email ) {
            // Defensive: do NOT log the email or order id together — we
            // don't want a forensic trail of attempted enumeration.
            trcl_log( 'OrderLookup: email mismatch (rejected)', 'info' );
            return null;
        }

        return $order;
    }

    /**
     * Reduce a WC_Order to the narrow shape we expose to the AI prompt.
     *
     * Deliberately drops shipping addresses, internal notes, customer
     * IP, etc. — Robin only needs the visitor-facing summary, and the
     * less PII we drop into the prompt the smaller the GDPR surface.
     *
     * @param \WC_Order $order
     * @return array
     */
    public function format_for_prompt( \WC_Order $order ): array {
        $items = [];
        if ( method_exists( $order, 'get_items' ) ) {
            foreach ( $order->get_items() as $item ) {
                $items[] = [
                    'name' => method_exists( $item, 'get_name' ) ? (string) $item->get_name() : 'Item',
                    'qty'  => method_exists( $item, 'get_quantity' ) ? (int) $item->get_quantity() : 0,
                ];
            }
        }

        $status   = method_exists( $order, 'get_status' ) ? (string) $order->get_status() : '';
        $total    = method_exists( $order, 'get_total' ) ? (float) $order->get_total() : 0.0;
        $currency = method_exists( $order, 'get_currency' ) ? (string) $order->get_currency() : '';
        $date     = method_exists( $order, 'get_date_created' ) && $order->get_date_created()
            ? (string) $order->get_date_created()->date( 'Y-m-d' )
            : '';

        $view_url = '';
        if ( method_exists( $order, 'get_view_order_url' ) ) {
            $view_url = (string) $order->get_view_order_url();
        }

        return [
            'id'       => method_exists( $order, 'get_id' ) ? (int) $order->get_id() : 0,
            'number'   => method_exists( $order, 'get_order_number' ) ? (string) $order->get_order_number() : '',
            'status'   => $status,
            'date'     => $date,
            'total'    => $total,
            'currency' => $currency,
            'items'    => $items,
            'view_url' => $view_url,
        ];
    }
}
