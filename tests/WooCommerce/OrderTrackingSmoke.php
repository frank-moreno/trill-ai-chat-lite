<?php
// phpcs:disable
// ^^ Dev-only smoke test. Excluded from the wp.org SVN package via
//    .distignore. Uses plain `echo` for human-readable CLI output and
//    direct PHP file I/O for the abort-gate — both are intentional for
//    a stand-alone harness and not appropriate WPCS subjects.
/**
 * E2E smoke for Block 5 — order tracking.
 *
 * Verifies:
 *   - OrderIntentDetector recognises status / tracking / delivery
 *     phrasing and ignores unrelated messages.
 *   - extract_order_id parses "#1234", "order 1234", and bare 4+ digit
 *     numbers but rejects 1-2 digit noise.
 *   - OrderLookup::find_by_id_and_email returns the order only when
 *     the billing email matches; mismatched email returns null.
 *   - format_for_prompt produces the narrow shape we expose to AI.
 *   - PromptBuilder renders CUSTOMER ORDERS (verified) and ORDER
 *     VERIFICATION NEEDED sections correctly, and they're absent
 *     when no order data is attached.
 *
 * Self-cleaning: the fixture order is deleted at the end.
 *
 * Usage:
 *   wp eval-file tests/WooCommerce/OrderTrackingSmoke.php
 *
 * @package TrillChatLite\Tests\WooCommerce
 * @since 2.0.0
 * @license GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
    fwrite( STDERR, "Must be run via wp eval-file (needs WordPress).\n" );
    exit( 1 );
}

global $passes, $failures;
$passes   = 0;
$failures = 0;

function trcl_assert( bool $cond, string $name, string $detail = '' ): void {
    global $passes, $failures;
    if ( $cond ) {
        $passes++;
        echo "  PASS  {$name}\n";
        return;
    }
    $failures++;
    echo "  FAIL  {$name}\n";
    if ( $detail !== '' ) {
        echo "        {$detail}\n";
    }
}

echo "Order tracking E2E smoke (Block 5)\n";
echo "===================================\n\n";

// ---------------------------------------------------------------------
// Step A: OrderIntentDetector classification + id extraction.
// ---------------------------------------------------------------------
echo "A. OrderIntentDetector\n";

$detector = new \TrillChatLite\WooCommerce\OrderIntentDetector();

trcl_assert(
    $detector->is_order_status_intent( "where's my order?" ) === true,
    'positive: "where\'s my order"'
);
trcl_assert(
    $detector->is_order_status_intent( 'track my parcel please' ) === true,
    'positive: "track my parcel"'
);
trcl_assert(
    $detector->is_order_status_intent( 'when will my order arrive' ) === true,
    'positive: "when will my order arrive"'
);
trcl_assert(
    $detector->is_order_status_intent( 'do you sell red shoes' ) === false,
    'negative: product query'
);
trcl_assert(
    $detector->is_order_status_intent( 'hello' ) === false,
    'negative: greeting'
);

trcl_assert(
    $detector->extract_order_id( 'where is order #1234?' ) === 1234,
    'extract: "#1234"'
);
trcl_assert(
    $detector->extract_order_id( 'my order number is 5678' ) === 5678,
    'extract: "order number is 5678"'
);
trcl_assert(
    $detector->extract_order_id( 'track 9876' ) === 9876,
    'extract: bare 4-digit number'
);
trcl_assert(
    $detector->extract_order_id( 'I want 2 shirts' ) === 0,
    'reject: 1-digit qty is not an order id'
);
trcl_assert(
    $detector->extract_order_id( 'no number here' ) === 0,
    'reject: no number → 0'
);

// ---------------------------------------------------------------------
// Step B: PromptBuilder absence + email-required section.
// ---------------------------------------------------------------------
echo "\nB. PromptBuilder (no order context)\n";

$builder = new \TrillChatLite\AI\PromptBuilder();
$builder->with_store_context( [ 'store_name' => 'Smoke Store' ] );

trcl_assert(
    strpos( $builder->build(), 'CUSTOMER ORDERS' ) === false,
    'no orders → no CUSTOMER ORDERS section'
);

$builder2 = new \TrillChatLite\AI\PromptBuilder();
$builder2->with_store_context( [ 'store_name' => 'Smoke Store' ] );
$builder2->with_order_email_required( 1234 );
$prompt_pending = $builder2->build();

trcl_assert(
    strpos( $prompt_pending, 'ORDER VERIFICATION NEEDED:' ) !== false,
    'pending verification → ORDER VERIFICATION NEEDED header'
);
trcl_assert(
    strpos( $prompt_pending, '#1234' ) !== false,
    'pending verification mentions the order id'
);
trcl_assert(
    strpos( $prompt_pending, 'CUSTOMER ORDERS (verified):' ) === false,
    'pending verification does NOT also render the verified-orders section'
);

// ---------------------------------------------------------------------
// Step C: create a fixture WC order, verify lookups + formatting.
// ---------------------------------------------------------------------
echo "\nC. OrderLookup against fixture order\n";

if ( ! function_exists( 'wc_create_order' ) ) {
    echo "  SKIP  WooCommerce not loaded — order lookup smoke can't run\n";
} else {
    $fixture_email      = 'smoke+order-' . time() . '@example.test';
    $fixture_wrong_email = 'wrong-' . time() . '@example.test';

    $order = \wc_create_order();
    if ( $order ) {
        $order->set_billing_email( $fixture_email );
        $order->set_status( 'processing' );
        $order->set_total( 42.50 );

        // Add a tiny line so format_for_prompt has something to render.
        $products = \wc_get_products( [ 'status' => 'publish', 'limit' => 1 ] );
        if ( ! empty( $products ) ) {
            $order->add_product( $products[0], 1 );
            $order->calculate_totals();
        }
        $order->save();

        $order_id = (int) $order->get_id();
        echo "  fixture order #{$order_id} (email={$fixture_email})\n";

        $lookup = new \TrillChatLite\WooCommerce\OrderLookup();

        $verified = $lookup->find_by_id_and_email( $order_id, $fixture_email );
        trcl_assert(
            $verified instanceof \WC_Order && (int) $verified->get_id() === $order_id,
            'find_by_id_and_email returns order for matching email'
        );

        $denied = $lookup->find_by_id_and_email( $order_id, $fixture_wrong_email );
        trcl_assert(
            $denied === null,
            'find_by_id_and_email returns NULL for mismatched email (privacy guard)'
        );

        $denied2 = $lookup->find_by_id_and_email( 999999999, $fixture_email );
        trcl_assert(
            $denied2 === null,
            'find_by_id_and_email returns NULL for non-existent order id'
        );

        if ( $verified ) {
            $formatted = $lookup->format_for_prompt( $verified );
            trcl_assert(
                isset( $formatted['id'], $formatted['status'], $formatted['total'], $formatted['items'], $formatted['view_url'] ),
                'format_for_prompt returns the documented shape'
            );
            trcl_assert(
                (int) $formatted['id'] === $order_id,
                'format_for_prompt carries the order id'
            );
            trcl_assert(
                (string) $formatted['status'] === 'processing',
                'format_for_prompt status = processing'
            );
            trcl_assert(
                is_array( $formatted['items'] ) && count( $formatted['items'] ) >= 1,
                'format_for_prompt includes at least one item entry'
            );

            // ---------------------------------------------------------
            // Step D: PromptBuilder renders the verified order.
            // ---------------------------------------------------------
            echo "\nD. PromptBuilder renders verified order\n";

            $builder3 = new \TrillChatLite\AI\PromptBuilder();
            $builder3->with_store_context( [ 'store_name' => 'Smoke Store' ] );
            $builder3->with_order_context( [ $formatted ] );
            $prompt3 = $builder3->build();

            trcl_assert(
                strpos( $prompt3, 'CUSTOMER ORDERS (verified):' ) !== false,
                'prompt includes CUSTOMER ORDERS header'
            );
            trcl_assert(
                strpos( $prompt3, 'status: processing' ) !== false,
                'prompt includes order status in plain text'
            );
            trcl_assert(
                strpos( $prompt3, 'ORDER USAGE RULES:' ) !== false,
                'prompt includes ORDER USAGE RULES block'
            );
        }

        // Cleanup.
        $order->delete( true );
        echo "  fixture order removed\n";
    } else {
        echo "  SKIP  wc_create_order() returned null on this site\n";
    }
}

echo "\n===================================\n";
echo "Results: {$passes} passed, {$failures} failed\n";

if ( $failures > 0 ) {
    exit( 1 );
}
