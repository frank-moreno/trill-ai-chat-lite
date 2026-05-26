<?php
/**
 * Detects when a chat message expresses an opt-in opportunity:
 *   - Out-of-stock interest ("can you notify me…", "when will it be back")
 *   - Price-drop interest ("any chance of a discount", "let me know if you have a sale")
 *
 * Lives next to (not inside) ProductSearch because its job is purely
 * to recognise intent + extract structure for the lead capture flow.
 * Production callers feed it the user message PLUS the product search
 * results from the same turn so out_of_stock signals can be tied to a
 * concrete product_id.
 *
 * Pure functions only — no DB, no WP options, no side effects.
 *
 * @package TrillChatLite\Leads
 * @since 2.0.0
 * @license GPL-2.0-or-later
 */

namespace TrillChatLite\Leads;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class LeadIntentDetector
 *
 * SOLID: Single Responsibility — pure detection of opt-in intents.
 */
class LeadIntentDetector {

    /**
     * Intent type constants. Match the enum-ish set used by the
     * LeadCaptureService and the trcl_leads.intent_type column.
     */
    public const INTENT_OUT_OF_STOCK = 'out_of_stock';
    public const INTENT_PRICE_DROP   = 'price_drop';
    public const INTENT_NONE         = 'none';

    /**
     * Phrases that imply the visitor wants to be notified when an
     * item comes back, framed as questions, requests, or wishes.
     */
    private const NOTIFY_PATTERNS = [
        '/\b(notify|let\s+me\s+know|email\s+me|tell\s+me|alert\s+me|send\s+(me\s+)?(an?\s+)?(notification|alert|email))\b/i',
        '/\b(when\s+(will\s+)?(it|this|that|they)\s+be\s+(back|available|in\s+stock|restocked))\b/i',
        '/\b(any\s+idea\s+when|when\s+do\s+you\s+(restock|get\s+more))\b/i',
        '/\b(back\s+in\s+stock|in\s+stock\s+again|restocking)\b/i',
    ];

    /**
     * Phrases that suggest the visitor is hesitating on price and
     * would value a follow-up if a discount happens.
     */
    private const PRICE_DROP_PATTERNS = [
        '/\b(discount|deal|sale|coupon|promo|cheaper|on\s+offer)\b/i',
        '/\b(is\s+there\s+a\s+sale|do\s+you\s+(ever|usually)\s+have\s+sales)\b/i',
        '/\b(let\s+me\s+know\s+if\s+(it|this|prices?)\s+(drops?|goes?\s+down|are\s+lower))\b/i',
        '/\b(too\s+expensive|too\s+pricey|out\s+of\s+budget|can(?:\'|’)?t\s+afford)\b/i',
    ];

    /**
     * RFC-light email regex. We don't need full RFC-5322 compliance —
     * the goal is to recognise a plausible-looking email inside free
     * text so we can capture it. The eventual insert goes through
     * sanitize_email() which is the canonical filter.
     */
    private const EMAIL_REGEX = '/[a-z0-9._%+\-]+@[a-z0-9.\-]+\.[a-z]{2,24}/i';

    /**
     * Decide whether this message expresses an opt-in intent and, if
     * so, which kind.
     *
     * Resolution order:
     *   1. Notify intent (out-of-stock) — only fires when we ALSO have
     *      a product candidate. Without a concrete product reference
     *      we'd capture a useless "notify me about something" lead.
     *   2. Price-drop intent — fires regardless of product context,
     *      but if a product is in scope we record it for follow-up.
     *
     * @param string $message  User message text.
     * @param array  $products Product search results from the same
     *                         turn (empty when none found). Used to
     *                         tie out_of_stock intent to a product_id.
     * @return array{type:string, product_id:int}
     */
    public function detect( string $message, array $products = [] ): array {
        if ( $message === '' ) {
            return [ 'type' => self::INTENT_NONE, 'product_id' => 0 ];
        }

        if ( $this->matches_any( $message, self::NOTIFY_PATTERNS ) ) {
            // Pick the first product as the target — ProductSearch
            // already ranks by relevance so this is the best guess.
            $product_id = $this->extract_first_product_id( $products );
            return [
                'type'       => self::INTENT_OUT_OF_STOCK,
                'product_id' => $product_id,
            ];
        }

        if ( $this->matches_any( $message, self::PRICE_DROP_PATTERNS ) ) {
            $product_id = $this->extract_first_product_id( $products );
            return [
                'type'       => self::INTENT_PRICE_DROP,
                'product_id' => $product_id,
            ];
        }

        return [ 'type' => self::INTENT_NONE, 'product_id' => 0 ];
    }

    /**
     * Find an email address inside the message, if any. Returns the
     * first plausible-looking email or '' when none is present.
     *
     * Caller is expected to pass the raw user message — we lowercase
     * + trim internally before matching.
     *
     * @param string $message
     * @return string Email lowercased, or '' if not found.
     */
    public function extract_email( string $message ): string {
        if ( $message === '' ) {
            return '';
        }
        if ( ! preg_match( self::EMAIL_REGEX, $message, $matches ) ) {
            return '';
        }
        return strtolower( trim( $matches[0] ) );
    }

    // =========================================================================
    // INTERNALS
    // =========================================================================

    /**
     * @param string   $message
     * @param string[] $patterns
     * @return bool
     */
    private function matches_any( string $message, array $patterns ): bool {
        foreach ( $patterns as $pattern ) {
            if ( preg_match( $pattern, $message ) ) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param array $products Result of ProductSearch::search().
     * @return int
     */
    private function extract_first_product_id( array $products ): int {
        if ( empty( $products ) ) {
            return 0;
        }
        $first = $products[0];
        if ( is_array( $first ) && isset( $first['product_id'] ) ) {
            return (int) $first['product_id'];
        }
        return 0;
    }
}
