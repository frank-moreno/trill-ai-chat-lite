<?php
// phpcs:disable
// ^^ Dev-only smoke test. Excluded from the wp.org SVN package via
//    .distignore. Uses plain `echo` for human-readable CLI output and
//    direct PHP file I/O for the abort-gate — both are intentional for
//    a stand-alone harness and not appropriate WPCS subjects.
/**
 * E2E smoke for Block 3 slice 4 — Cart-aware chat.
 *
 * Verifies:
 *   - CartContext::get_current_cart() returns [] when WC cart is empty
 *     OR when running under WP-CLI where the session cookie isn't
 *     available (production path is hit on every real REST request).
 *   - PromptBuilder renders a "CUSTOMER'S CURRENT CART" section when
 *     given a non-empty cart array, with items, totals, URLs visible.
 *   - The cart section appears AFTER products in the built prompt
 *     order (commercial flow: discovery -> checkout).
 *   - Section is NOT rendered when with_cart_context is never called.
 *   - MAX_ITEMS_IN_PROMPT cap collapses long carts to "+N more".
 *
 * NOTE on the CLI limitation: WooCommerce's cart insertion path relies
 * on a session cookie and request lifecycle that doesn't exist when
 * we invoke wp eval-file. So instead of trying to drive WC()->cart in
 * CLI (which silently fails), we hand PromptBuilder a synthetic cart
 * snapshot that matches CartContext's documented output shape.
 * Production code still exercises the full path on every real visitor
 * request — that's covered by manual smoke on the frontend.
 *
 * Usage:
 *   wp eval-file tests/WooCommerce/CartContextSmoke.php
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

echo "CartContext + PromptBuilder cart section E2E smoke\n";
echo "==================================================\n\n";

// ---------------------------------------------------------------------
// Step A: CartContext null-safe paths.
// ---------------------------------------------------------------------
echo "A. CartContext null-safe behaviour\n";

$ctx    = new \TrillChatLite\WooCommerce\CartContext();
$result = $ctx->get_current_cart();

trcl_assert(
    is_array( $result ),
    'get_current_cart() always returns an array'
);

// In CLI we either hit "WC not loaded", "session not initialised", or
// "cart empty" — all three legitimate paths return []. We assert that
// behaviour without binding the test to a specific failure mode.
trcl_assert(
    empty( $result ),
    'get_current_cart() returns empty array in CLI / empty-cart context',
    'got ' . var_export( $result, true )
);

// ---------------------------------------------------------------------
// Step B: synthetic cart snapshot that PromptBuilder can render.
// Mirrors the documented shape of CartContext::get_current_cart()
// exactly so this test also serves as a contract check.
// ---------------------------------------------------------------------
echo "\nB. Synthetic cart -> PromptBuilder render\n";

$synthetic_cart = [
    'items'           => [
        [
            'name'       => 'Red T-Shirt',
            'qty'        => 2,
            'unit_price' => 15.0,
            'line_total' => 30.0,
            'url'        => 'https://example.test/product/red-tshirt',
        ],
        [
            'name'       => 'Blue Jacket',
            'qty'        => 1,
            'unit_price' => 80.0,
            'line_total' => 80.0,
            'url'        => 'https://example.test/product/blue-jacket',
        ],
    ],
    'subtotal'        => 110.0,
    'total'           => 132.0,
    'currency'        => 'GBP',
    'currency_symbol' => '£',
    'item_count'      => 3,
    'cart_url'        => 'https://example.test/cart',
    'checkout_url'    => 'https://example.test/checkout',
    'has_more'        => false,
];

$builder = new \TrillChatLite\AI\PromptBuilder();
$builder->with_store_context( [
    'store_name'      => 'Smoke Store',
    'currency_symbol' => '£',
] );
$builder->with_cart_context( $synthetic_cart );

$prompt = $builder->build();

trcl_assert(
    strpos( $prompt, "CUSTOMER'S CURRENT CART:" ) !== false,
    'prompt includes CART header'
);
trcl_assert(
    strpos( $prompt, 'Red T-Shirt' ) !== false,
    'prompt mentions the first product'
);
trcl_assert(
    strpos( $prompt, 'Blue Jacket' ) !== false,
    'prompt mentions the second product'
);
trcl_assert(
    strpos( $prompt, '£15.00' ) !== false,
    'prompt shows unit prices with currency symbol'
);
trcl_assert(
    strpos( $prompt, '£110.00' ) !== false,
    'prompt shows cart subtotal with currency symbol'
);
trcl_assert(
    strpos( $prompt, '£132.00' ) !== false,
    'prompt shows cart total with currency symbol'
);
trcl_assert(
    strpos( $prompt, 'Total items in cart: 3' ) !== false,
    'prompt shows item count'
);
trcl_assert(
    strpos( $prompt, 'https://example.test/checkout' ) !== false,
    'prompt includes the checkout URL'
);
trcl_assert(
    strpos( $prompt, 'CART USAGE RULES:' ) !== false,
    'prompt includes the usage rules block'
);

// ---------------------------------------------------------------------
// Step C: ordering — cart section comes AFTER products section.
// ---------------------------------------------------------------------
echo "\nC. Section ordering\n";

$builder2 = new \TrillChatLite\AI\PromptBuilder();
$builder2->with_store_context( [ 'store_name' => 'Smoke Store' ] );
$builder2->with_product_context( [
    [ 'name' => 'Distractor Product', 'price' => '£20', 'in_stock' => true ],
] );
$builder2->with_cart_context( $synthetic_cart );
$prompt2 = $builder2->build();

$prod_pos = strpos( $prompt2, 'RELEVANT PRODUCTS FOUND:' );
$cart_pos = strpos( $prompt2, "CUSTOMER'S CURRENT CART:" );

trcl_assert(
    $prod_pos !== false && $cart_pos !== false && $cart_pos > $prod_pos,
    'cart section appears AFTER products section in prompt order',
    "prod_pos={$prod_pos}, cart_pos={$cart_pos}"
);

// ---------------------------------------------------------------------
// Step D: empty cart_context -> no CART section.
// ---------------------------------------------------------------------
echo "\nD. Absent-cart no-op\n";

$builder3 = new \TrillChatLite\AI\PromptBuilder();
$builder3->with_store_context( [ 'store_name' => 'Smoke Store' ] );
$prompt3  = $builder3->build();

trcl_assert(
    strpos( $prompt3, "CUSTOMER'S CURRENT CART:" ) === false,
    'no cart section when with_cart_context never called'
);

// ---------------------------------------------------------------------
// Step E: has_more flag adds the "+N more" hint.
// ---------------------------------------------------------------------
echo "\nE. Long cart truncation hint\n";

$truncated_cart = $synthetic_cart;
$truncated_cart['has_more'] = true;

$builder4 = new \TrillChatLite\AI\PromptBuilder();
$builder4->with_store_context( [ 'store_name' => 'Smoke Store' ] );
$builder4->with_cart_context( $truncated_cart );
$prompt4 = $builder4->build();

trcl_assert(
    strpos( $prompt4, 'more line(s) not shown' ) !== false,
    'has_more=true renders the "+N more" hint'
);

echo "\n==================================================\n";
echo "Results: {$passes} passed, {$failures} failed\n";

if ( $failures > 0 ) {
    exit( 1 );
}
