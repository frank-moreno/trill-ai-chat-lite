<?php
// phpcs:disable
// ^^ Dev-only smoke test. Excluded from the wp.org SVN package via
//    .distignore. Uses plain `echo` for human-readable CLI output —
//    intentional for a stand-alone harness, not a WPCS subject.
/**
 * E2E smoke for ProductSearch generic-catalogue handling (2.3.0 §9).
 *
 * Verifies:
 *   - is_generic_catalogue_query() pattern coverage (positives and
 *     negatives — specific searches must NOT be treated as generic).
 *   - search() answers a generic catalogue question with a storefront
 *     overview (featured + recent) instead of an empty literal search.
 *   - Result shape matches the regular search path (product cards
 *     render unchanged).
 *
 * Usage:
 *   wp eval-file tests/Search/ProductSearchSmoke.php
 *
 * Assumes at least one published product exists (any WC dev store).
 * On a zero-product store the overview asserts report 0 results,
 * which is correct behaviour, not a failure.
 *
 * @package TrillChatLite\Tests\Search
 * @since 2.3.0
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

$search = new \TrillChatLite\Search\ProductSearch();

echo "ProductSearch generic-catalogue smoke\n";
echo "=====================================\n\n";

// ---------------------------------------------------------------------
// Step A: is_generic_catalogue_query() pattern coverage.
// ---------------------------------------------------------------------
echo "A. is_generic_catalogue_query() patterns\n";

$positives = [
    'what products do you have?',
    'What products are available?',
    'what do you sell',
    'what do you offer?',
    'what plans do you have',
    'what kind of products do you sell?',
    'show me your products',
    'show me the catalogue',
    'what can i buy here?',
    "what's in your store?",
    'list all your products',
    'browse your catalog',
    'do you have any products?',
];

foreach ( $positives as $msg ) {
    trcl_assert(
        $search->is_generic_catalogue_query( $msg ) === true,
        'generic: "' . $msg . '"'
    );
}

$negatives = [
    'do you have red dresses',
    'do you have Trill Cloud?',
    'tell me about the Trill Cloud plan',
    'how much is the cloud plan',
    'i am looking for a t-shirt',
    'what is your refund policy?',
    'show me your shipping options',
    'hi',
];

foreach ( $negatives as $msg ) {
    trcl_assert(
        $search->is_generic_catalogue_query( $msg ) === false,
        'NOT generic: "' . $msg . '"'
    );
}

// ---------------------------------------------------------------------
// Step B: search() serves the storefront overview for generic asks.
// ---------------------------------------------------------------------
echo "\nB. search() catalogue overview (live store)\n";

$published = (int) ( \wp_count_posts( 'product' )->publish ?? 0 );
echo "  published products in store: {$published}\n";

$overview = $search->search( 'what products do you have?' );

if ( $published === 0 ) {
    trcl_assert(
        $overview === [],
        'zero-product store → overview is empty (correct, not a failure)'
    );
} else {
    trcl_assert(
        count( $overview ) > 0,
        'generic catalogue question returns products',
        'store has ' . $published . ' published product(s) but overview came back empty'
    );
    trcl_assert(
        count( $overview ) <= 5,
        'overview capped at 5 products'
    );

    foreach ( $overview as $r ) {
        trcl_assert(
            isset( $r['product_id'], $r['name'], $r['price'], $r['url'], $r['in_stock'] ),
            'overview result shape matches regular search path ("' . ( $r['name'] ?? '?' ) . '")'
        );
        break; // shape check on first row is enough signal.
    }

    // No duplicate products in the overview (featured + recent merge).
    $ids = array_column( $overview, 'product_id' );
    trcl_assert(
        count( $ids ) === count( array_unique( $ids ) ),
        'overview contains no duplicate products'
    );
}

// ---------------------------------------------------------------------
// Step C: regression — specific searches still use the literal path.
// ---------------------------------------------------------------------
echo "\nC. regression: specific search path intact\n";

// A nonsense specific term must return empty (literal search), proving
// the overview branch did not swallow specific queries.
$specific = $search->search( 'do you have zzz-nonexistent-widget-9000' );
trcl_assert(
    $specific === [],
    'specific nonsense query still returns empty (not the overview)'
);

// ---------------------------------------------------------------------
// Summary
// ---------------------------------------------------------------------
echo "\n=====================================\n";
echo "Results: {$passes} passed, {$failures} failed\n";

if ( $failures > 0 ) {
    exit( 1 );
}
