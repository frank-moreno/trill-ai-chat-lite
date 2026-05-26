<?php
/**
 * Wires WooCommerce + chat actions to the analytics recorder.
 *
 * Pure glue — every action handler is a one-liner that delegates to
 * AnalyticsRecorder. Lives in its own class so unit tests can swap
 * the recorder out, and so Plugin::init_components has a single line
 * to register the whole layer.
 *
 * @package TrillChatLite\Analytics
 * @since 2.0.0
 * @license GPL-2.0-or-later
 */

namespace TrillChatLite\Analytics;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class AnalyticsHooks
 *
 * SOLID: Single Responsibility — only WC hook → recorder wiring.
 */
class AnalyticsHooks {

    /**
     * @var AnalyticsRecorder
     */
    private AnalyticsRecorder $recorder;

    public function __construct( ?AnalyticsRecorder $recorder = null ) {
        $this->recorder = $recorder ?? new AnalyticsRecorder();
    }

    /**
     * Register WP / WC action hooks. Called once from Plugin during
     * init_components.
     *
     * Hooks wired:
     *   - woocommerce_add_to_cart  → on_add_to_cart (item added)
     *   - woocommerce_thankyou     → on_order_completed (order placed)
     */
    public function register_hooks(): void {
        \add_action( 'woocommerce_add_to_cart', [ $this, 'on_add_to_cart' ], 10, 6 );
        \add_action( 'woocommerce_thankyou', [ $this, 'on_order_completed' ], 10, 1 );
    }

    /**
     * woocommerce_add_to_cart handler.
     *
     * Signature mirrors WC's:
     *   do_action('woocommerce_add_to_cart',
     *     $cart_item_key, $product_id, $quantity, $variation_id,
     *     $variation, $cart_item_data);
     *
     * @param string $cart_item_key Unused (WC's internal key).
     * @param int    $product_id    Added product id.
     * @param int    $quantity      Quantity added.
     */
    public function on_add_to_cart(
        string $cart_item_key,
        int $product_id = 0,
        int $quantity = 0,
        int $variation_id = 0,
        $variation = [],
        $cart_item_data = []
    ): void {
        unset( $cart_item_key, $variation_id, $variation, $cart_item_data );

        if ( $product_id <= 0 || $quantity <= 0 ) {
            return;
        }

        $line_total = 0.0;
        if ( function_exists( 'wc_get_product' ) ) {
            $product = \wc_get_product( $product_id );
            if ( $product ) {
                $line_total = (float) $product->get_price() * (float) $quantity;
            }
        }

        $this->recorder->record_add_to_cart( $product_id, $quantity, $line_total );
    }

    /**
     * woocommerce_thankyou handler.
     *
     * Records the order_completed event and attempts attribution to
     * a recent chat session in the same visit.
     *
     * @param int $order_id
     */
    public function on_order_completed( $order_id ): void {
        $order_id = (int) $order_id;
        if ( $order_id <= 0 ) {
            return;
        }

        $result = $this->recorder->record_order_and_attribute( $order_id );

        trcl_log( 'Analytics: order recorded', 'info', [
            'order_id'     => $order_id,
            'attributed'   => $result['attributed_id'] > 0,
        ] );
    }
}
