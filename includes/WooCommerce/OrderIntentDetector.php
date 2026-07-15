<?php
/**
 * Detects when a chat message is asking about an order status, and
 * extracts the order number from free text when present.
 *
 * Pure functions only — no DB, no WC calls. Lives in the WooCommerce
 * namespace because its intent vocabulary is e-commerce-specific
 * (shipping, tracking, delivery, refund).
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
 * Class OrderIntentDetector
 *
 * SOLID: Single Responsibility — order-status intent classification.
 */
class OrderIntentDetector {

    /**
     * Phrases that indicate the visitor is asking about an order's
     * status, delivery progress, or tracking. The list deliberately
     * skews towards customer-facing phrasing rather than admin lingo.
     *
     * The "where(?:'s|...)?" alternation handles common contractions
     * across keyboard layouts:
     *   - "where's"   (straight ASCII apostrophe)
     *   - "where's"   (U+2019, the smart/curly variant macOS inserts by default)
     *   - "where´s"   (U+00B4, a frequent typo on Spanish/Portuguese keyboards)
     *   - "wheres"    (no apostrophe at all — autocorrect failures)
     *   - "where is"  (uncontracted)
     * The `u` flag makes \b and the literal unicode chars behave correctly.
     */
    private const STATUS_PATTERNS = [
        '/\bwhere(?:\s+is|[\'\x{2019}\x{00B4}]?s)\s+my\s+(order|parcel|package|delivery|shipment)\b/iu',
        '/\b(order\s+status|status\s+of\s+(my\s+)?order)\b/i',
        '/\b(track|tracking)\s+(my\s+)?(order|parcel|package|delivery|shipment)\b/i',
        '/\b(has\s+my\s+order\s+(shipped|been\s+shipped|been\s+sent))\b/i',
        '/\b(when\s+will\s+(my\s+)?order\s+(arrive|ship))\b/i',
        '/\b(have\s+you\s+(shipped|sent)\s+my\s+order)\b/i',
        '/\b(delivery\s+date|estimated\s+delivery)\b/i',
        '/\b(refund\s+status|where(?:\s+is|[\'\x{2019}\x{00B4}]?s)\s+my\s+refund)\b/iu',
        '/\bmy\s+order\s*(number|#)?\s*(is\s+)?(#\d+|\d+)/i',
    ];

    /**
     * Extract an order number from free text.
     *
     * Order numbers in WooCommerce are positive integers up to 10
     * digits in practice. We accept several phrasings:
     *
     *   "#1234"
     *   "order 1234"
     *   "order number 1234"
     *   "my order is 1234"
     *   "1234" (as long as it's at least 3 digits and ≤ 10)
     *
     * Lone 1-2-digit numbers are ignored because they're almost
     * never order ids (visitors are more likely to type postcodes,
     * quantities, etc.).
     *
     * @param string $message
     * @return int 0 when no plausible order number found.
     */
    public function extract_order_id( string $message ): int {
        if ( $message === '' ) {
            return 0;
        }

        // 1) Hash-prefixed: "#1234", "# 1234"
        if ( preg_match( '/#\s*(\d{1,10})\b/', $message, $m ) ) {
            return (int) $m[1];
        }

        // 2) "order [number] 1234" / "order is 1234" / "order: 1234"
        if ( preg_match( '/\border\b[^0-9]{0,30}?(\d{3,10})\b/i', $message, $m ) ) {
            return (int) $m[1];
        }

        // 3) Bare number, only if no ambiguous context is around it.
        //    We restrict to 4-10 digits to avoid quantities ("3"),
        //    postcodes ("28080"), or years ("2026"). 4 digits is a
        //    decent floor because WC order ids typically start in
        //    the hundreds and quickly cross 1000 on real stores.
        if ( preg_match( '/(?<!\d)(\d{4,10})(?!\d)/', $message, $m ) ) {
            return (int) $m[1];
        }

        return 0;
    }

    /**
     * @param string $message
     * @return bool
     */
    public function is_order_status_intent( string $message ): bool {
        if ( $message === '' ) {
            return false;
        }
        foreach ( self::STATUS_PATTERNS as $pattern ) {
            if ( preg_match( $pattern, $message ) ) {
                return true;
            }
        }
        return false;
    }
}
