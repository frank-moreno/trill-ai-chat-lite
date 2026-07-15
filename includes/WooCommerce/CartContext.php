<?php
/**
 * Read-only snapshot of the current visitor's WooCommerce cart.
 *
 * Powers Block 3 slice 4 ("cart-aware chat"): the system prompt is
 * enriched on every message with a compact summary of what the
 * shopper currently has in their cart, so Robin can answer:
 *
 *   - "What's in my cart?"
 *   - "How much will I pay?"
 *   - "Can I checkout?"
 *   - "Suggest something that goes with what I have."
 *
 * Pure read layer — never mutates the cart. Wrapped in defensive
 * guards because REST contexts can hit this before WC fully bootstraps
 * its session, and the AI flow must never crash on cart edge cases.
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
 * Class CartContext
 *
 * SOLID: Single Responsibility — only WC cart introspection for the AI.
 */
class CartContext {

    /**
     * Max cart line items rendered in the prompt. Beyond this we
     * collapse to a "+N more" hint to keep the token footprint bounded.
     */
    public const MAX_ITEMS_IN_PROMPT = 10;

    /**
     * Return a structured snapshot of the current cart, or empty
     * array when there's nothing useful to report.
     *
     * Shape (when populated):
     *   items[]:
     *     name        string  product display name
     *     qty         int     line quantity
     *     unit_price  float   pre-tax unit price
     *     line_total  float   qty * unit_price (pre-tax)
     *     url         string  product permalink
     *   subtotal      float   cart subtotal pre-tax
     *   total         float   cart total post-tax + shipping
     *   currency      string  ISO 3-letter currency code
     *   currency_symbol string '£', '$', etc.
     *   item_count    int     total items (sum of qty)
     *   cart_url      string  /cart permalink
     *   checkout_url  string  /checkout permalink
     *   has_more      bool    true when the cart exceeds MAX_ITEMS_IN_PROMPT
     *
     * Returns [] when:
     *   - WooCommerce is not loaded.
     *   - The cart hasn't been initialised (REST request before
     *     woocommerce_init, very rare).
     *   - The cart is empty.
     *
     * @return array
     */
    public function get_current_cart(): array {
        if ( ! function_exists( 'WC' ) ) {
            return [];
        }

        try {
            // WooCommerce ONLY auto-loads the cart on traditional
            // frontend requests. Our chat handler runs inside the
            // REST API (wp-json/trcl/v1/message), where WC has the
            // session cookie but never initialises WC()->cart from
            // it — so `get_cart()` would silently return [] even
            // when the visitor has items.
            //
            // wc_load_cart() is the canonical bootstrap helper
            // (introduced in WC 3.6) that explicitly initialises
            // session → customer → cart from the current request.
            // Safe to call repeatedly; it short-circuits when the
            // objects are already loaded.
            if ( function_exists( 'wc_load_cart' ) ) {
                \wc_load_cart();
            }

            $wc = \WC();
            if ( ! $wc || ! isset( $wc->cart ) || ! $wc->cart ) {
                return [];
            }

            $cart_items = $wc->cart->get_cart();
            if ( empty( $cart_items ) || ! is_array( $cart_items ) ) {
                return [];
            }

            $items       = [];
            $rendered    = 0;
            $total_items = 0;

            foreach ( $cart_items as $cart_item ) {
                $total_items += isset( $cart_item['quantity'] ) ? (int) $cart_item['quantity'] : 0;

                if ( $rendered >= self::MAX_ITEMS_IN_PROMPT ) {
                    continue;
                }
                $rendered++;

                $product = isset( $cart_item['data'] ) ? $cart_item['data'] : null;
                if ( ! $product || ! is_object( $product ) ) {
                    continue;
                }

                $qty        = isset( $cart_item['quantity'] ) ? (int) $cart_item['quantity'] : 0;
                $unit_price = (float) ( method_exists( $product, 'get_price' ) ? $product->get_price() : 0 );
                $line_total = isset( $cart_item['line_total'] )
                    ? (float) $cart_item['line_total']
                    : ( $unit_price * $qty );

                $items[] = [
                    'name'       => method_exists( $product, 'get_name' ) ? (string) $product->get_name() : '',
                    'qty'        => $qty,
                    'unit_price' => $unit_price,
                    'line_total' => $line_total,
                    'url'        => method_exists( $product, 'get_permalink' ) ? (string) $product->get_permalink() : '',
                ];
            }

            // If we filtered everything out (e.g. data was malformed), bail.
            if ( empty( $items ) ) {
                return [];
            }

            return [
                'items'           => $items,
                'subtotal'        => (float) $wc->cart->get_subtotal(),
                'total'           => (float) $wc->cart->get_total( 'raw' ),
                'currency'        => function_exists( 'get_woocommerce_currency' )
                    ? (string) \get_woocommerce_currency()
                    : '',
                'currency_symbol' => function_exists( 'get_woocommerce_currency_symbol' )
                    ? html_entity_decode( \get_woocommerce_currency_symbol(), ENT_QUOTES | ENT_HTML5, 'UTF-8' )
                    : '',
                'item_count'      => $total_items,
                'cart_url'        => function_exists( 'wc_get_cart_url' )
                    ? (string) \wc_get_cart_url()
                    : '',
                'checkout_url'    => function_exists( 'wc_get_checkout_url' )
                    ? (string) \wc_get_checkout_url()
                    : '',
                'has_more'        => count( $cart_items ) > self::MAX_ITEMS_IN_PROMPT,
            ];
        } catch ( \Throwable $e ) {
            trcl_log( 'CartContext failed', 'warning', [
                'error' => $e->getMessage(),
            ] );
            return [];
        }
    }
}
