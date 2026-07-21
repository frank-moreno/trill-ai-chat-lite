<?php
// phpcs:disable
// ^^ Dev-only smoke test. Excluded from the wp.org SVN package via
//    .distignore. Uses plain `echo` for human-readable CLI output and
//    direct PHP file I/O for the abort-gate — both are intentional for
//    a stand-alone harness and not appropriate WPCS subjects.
/**
 * E2E smoke for ContentSearch + PromptBuilder integration.
 *
 * Verifies:
 *   - should_search() intent gating (greetings, products-found, policy
 *     patterns, ambiguous).
 *   - search() returns FULLTEXT hits against the live index.
 *   - LIKE fallback fires when FULLTEXT under-delivers.
 *   - PromptBuilder renders the RELEVANT STORE CONTENT section when
 *     content_context is populated.
 *
 * Usage:
 *   wp eval-file tests/Content/SearchE2ESmoke.php
 *
 * Assumes ContentIndexer slice 2 has already populated the index.
 * (If empty, the search asserts will report 0 results which is a
 * useful signal, not a failure of ContentSearch itself.)
 *
 * @package TrillChatLite\Tests\Content
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

$search = new \TrillChatLite\Content\ContentSearch();

echo "ContentSearch + PromptBuilder E2E smoke\n";
echo "=======================================\n\n";

// ---------------------------------------------------------------------
// Step A: should_search intent gating.
// ---------------------------------------------------------------------
echo "A. should_search() intent gating\n";

trcl_assert(
    $search->should_search( '', false ) === false,
    'empty message → skip'
);
trcl_assert(
    $search->should_search( 'hi', false ) === false,
    'pure greeting "hi" → skip'
);
trcl_assert(
    $search->should_search( 'thanks', false ) === false,
    'pure greeting "thanks" → skip'
);
trcl_assert(
    $search->should_search( 'do you have red dresses', true ) === false,
    'product search succeeded + product-y message → skip content search'
);
trcl_assert(
    $search->should_search( 'what is your return policy', true ) === true,
    'policy pattern overrides product-found → search anyway'
);
trcl_assert(
    $search->should_search( 'what is your shipping policy', false ) === true,
    'shipping policy → search'
);
trcl_assert(
    $search->should_search( 'how do I contact support', false ) === true,
    'contact pattern → search'
);
trcl_assert(
    $search->should_search( 'who are you', false ) === true,
    'about-us pattern → search'
);
trcl_assert(
    $search->should_search( 'do you ship to france', false ) === true,
    '"do you ship" pattern → search'
);
trcl_assert(
    $search->should_search( 'random thing without products', false ) === true,
    'product search empty + ambiguous → last-resort search'
);

// ---------------------------------------------------------------------
// Step B: search() against the live index.
// ---------------------------------------------------------------------
echo "\nB. search() against the live index\n";

global $wpdb;
$total_chunks = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}trcl_content_index" );
echo "  index size: {$total_chunks} chunks\n";

if ( $total_chunks === 0 ) {
    echo "  SKIP  index is empty; re-run after ContentIndexer::index_all_opted_in()\n";
} else {
    // Try a few queries that should match SOMETHING on the dev site.
    // gsplvlite has WC's default "Sample Page" lorem-ipsum content plus
    // shop/cart/checkout/my-account pages. We pick queries that survive
    // FULLTEXT's stopword filter and have at least 3 chars per token.
    $queries = [
        'example page',
        'cart',
        'account',
        'shop',
    ];

    $hits_at_least_once = false;

    foreach ( $queries as $q ) {
        $results = $search->search( $q, 3 );
        $count   = count( $results );
        echo "  query \"{$q}\" → {$count} result(s)";
        if ( $count > 0 ) {
            echo " (top: \"" . $results[0]['title'] . "\", score=" . round( $results[0]['score'], 3 ) . ")";
            $hits_at_least_once = true;
        }
        echo "\n";

        // Per-result shape assertions.
        foreach ( $results as $r ) {
            trcl_assert(
                isset( $r['title'], $r['snippet'], $r['url'], $r['score'] ),
                'result shape has title/snippet/url/score for query "' . $q . '"'
            );
        }
    }

    trcl_assert(
        $hits_at_least_once,
        'at least one of the test queries returned a hit against the live index',
        '0 hits would suggest FULLTEXT min token size or stopwords issue'
    );

    // Empty query handling.
    trcl_assert(
        count( $search->search( '', 3 ) ) === 0,
        'empty query returns empty array'
    );

    // Pure-symbol query (post-normalisation becomes empty) → empty.
    trcl_assert(
        count( $search->search( '!!!???', 3 ) ) === 0,
        'punctuation-only query returns empty array'
    );
}

// ---------------------------------------------------------------------
// Step C: PromptBuilder renders RELEVANT STORE CONTENT section.
// ---------------------------------------------------------------------
echo "\nC. PromptBuilder integration\n";

$builder = new \TrillChatLite\AI\PromptBuilder();
$builder->with_store_context( [
    'store_name'        => 'Test Store',
    'store_description' => 'A test description.',
    'currency_symbol'   => '£',
] );

$fake_matches = [
    [
        'title'   => 'Shipping & Returns',
        'snippet' => 'Standard delivery takes 3-5 business days within the UK.',
        'url'     => 'https://example.test/shipping',
        'score'   => 1.23,
    ],
    [
        'title'   => 'FAQ',
        'snippet' => 'We accept Visa, Mastercard, and PayPal.',
        'url'     => 'https://example.test/faq',
        'score'   => 0.95,
    ],
];
$builder->with_content_context( $fake_matches );

$prompt = $builder->build();

trcl_assert(
    strpos( $prompt, 'RELEVANT STORE CONTENT:' ) !== false,
    'prompt includes RELEVANT STORE CONTENT header'
);
trcl_assert(
    strpos( $prompt, 'Shipping & Returns' ) !== false,
    'prompt includes first match title'
);
trcl_assert(
    strpos( $prompt, '3-5 business days' ) !== false,
    'prompt includes first match snippet'
);
trcl_assert(
    strpos( $prompt, 'FAQ' ) !== false && strpos( $prompt, 'Visa, Mastercard' ) !== false,
    'prompt includes second match (title + snippet)'
);
trcl_assert(
    strpos( $prompt, 'CONTENT USAGE RULES:' ) !== false,
    'prompt includes content usage rules block'
);

// Content section must appear BEFORE products section in the assembled
// prompt (the ordering decision from the design memo).
$builder2 = new \TrillChatLite\AI\PromptBuilder();
$builder2->with_store_context( [ 'store_name' => 'Test Store' ] );
$builder2->with_content_context( $fake_matches );
$builder2->with_product_context( [
    [ 'name' => 'Red Dress', 'price' => '£20', 'in_stock' => true ],
] );
$prompt2 = $builder2->build();

$content_pos = strpos( $prompt2, 'RELEVANT STORE CONTENT:' );
$product_pos = strpos( $prompt2, 'RELEVANT PRODUCTS FOUND:' );

trcl_assert(
    $content_pos !== false && $product_pos !== false && $content_pos < $product_pos,
    'content section appears BEFORE product section in prompt order',
    "content_pos={$content_pos}, product_pos={$product_pos}"
);

// Empty content_context → section is NOT rendered.
$builder3 = new \TrillChatLite\AI\PromptBuilder();
$builder3->with_store_context( [ 'store_name' => 'Test Store' ] );
$prompt3 = $builder3->build();

trcl_assert(
    strpos( $prompt3, 'RELEVANT STORE CONTENT:' ) === false,
    'no content section when with_content_context never called'
);

// ---------------------------------------------------------------------
// Step D: source diversification (2.3.0 — one chunk per source).
// ---------------------------------------------------------------------
echo "\nD. diversify_by_source() post-filter\n";

$diversify = new \ReflectionMethod( \TrillChatLite\Content\ContentSearch::class, 'diversify_by_source' );
$diversify->setAccessible( true );

// Real-world scenario from the 2026-07-15 testserver diagnosis: Cookie
// and Privacy chunks saturate "policy" and push Refund to 4th place.
$saturated = [
    [ 'id' => 1, 'post_id' => 10, 'post_type' => 'page', 'title' => 'Cookie Policy', 'snippet' => 'c1', 'url' => 'u', 'score' => 6.26 ],
    [ 'id' => 2, 'post_id' => 11, 'post_type' => 'page', 'title' => 'Privacy Policy', 'snippet' => 'p1', 'url' => 'u', 'score' => 5.06 ],
    [ 'id' => 3, 'post_id' => 10, 'post_type' => 'page', 'title' => 'Cookie Policy', 'snippet' => 'c2', 'url' => 'u', 'score' => 4.92 ],
    [ 'id' => 4, 'post_id' => 12, 'post_type' => 'page', 'title' => 'Refund and Returns Policy', 'snippet' => 'r1', 'url' => 'u', 'score' => 4.61 ],
    [ 'id' => 5, 'post_id' => 11, 'post_type' => 'page', 'title' => 'Privacy Policy', 'snippet' => 'p2', 'url' => 'u', 'score' => 4.10 ],
];

$diverse = $diversify->invoke( $search, $saturated, 3 );

trcl_assert(
    count( $diverse ) === 3,
    'diversified output capped at limit (3)'
);
trcl_assert(
    array_column( $diverse, 'title' ) === [ 'Cookie Policy', 'Privacy Policy', 'Refund and Returns Policy' ],
    'Refund page enters top-3 once duplicate Cookie chunk collapses',
    'got: ' . implode( ' | ', array_column( $diverse, 'title' ) )
);
trcl_assert(
    (float) $diverse[0]['score'] === 6.26 && (float) $diverse[2]['score'] === 4.61,
    'best chunk per source survives, score order preserved'
);

// Same source shared across post types must NOT collapse (page 10 vs
// product_cat 10 are different sources).
$cross_type = [
    [ 'id' => 1, 'post_id' => 10, 'post_type' => 'page', 'title' => 'A', 'snippet' => 's', 'url' => 'u', 'score' => 2.0 ],
    [ 'id' => 2, 'post_id' => 10, 'post_type' => 'product_cat', 'title' => 'B', 'snippet' => 's', 'url' => 'u', 'score' => 1.0 ],
];
trcl_assert(
    count( $diversify->invoke( $search, $cross_type, 3 ) ) === 2,
    'same post_id across different post_types treated as distinct sources'
);

// Rows without source columns (defensive) pass through uncollapsed.
$no_source = [
    [ 'id' => 7, 'title' => 'X', 'snippet' => 's', 'url' => 'u', 'score' => 1.0 ],
    [ 'id' => 8, 'title' => 'Y', 'snippet' => 's', 'url' => 'u', 'score' => 0.5 ],
];
trcl_assert(
    count( $diversify->invoke( $search, $no_source, 3 ) ) === 2,
    'rows without post_id pass through keyed by row id'
);

// ---------------------------------------------------------------------
// Step E: empty-search prompt section (2.3.0 — no raw URLs).
// ---------------------------------------------------------------------
echo "\nE. PromptBuilder empty-search section\n";

$builder4 = new \TrillChatLite\AI\PromptBuilder();
$builder4->with_store_context( [
    'store_name'     => 'Test Store',
    'store_url'      => 'https://example.test',
    'top_categories' => [ 'Software Subscriptions' ],
] );
$builder4->with_empty_search_result();
$prompt4 = $builder4->build();

trcl_assert(
    strpos( $prompt4, 'PRODUCT SEARCH RESULT:' ) !== false,
    'empty-search section renders when search came back empty'
);
trcl_assert(
    strpos( $prompt4, 'Do NOT paste raw URLs' ) !== false,
    'empty-search section forbids raw URLs'
);
trcl_assert(
    strpos( $prompt4, 'browse the store at' ) === false,
    'empty-search section no longer tells the customer to browse a URL'
);
trcl_assert(
    strpos( $prompt4, 'Software Subscriptions' ) !== false,
    'empty-search section still offers the store categories'
);

// ---------------------------------------------------------------------
// Summary
// ---------------------------------------------------------------------
echo "\n=======================================\n";
echo "Results: {$passes} passed, {$failures} failed\n";

if ( $failures > 0 ) {
    exit( 1 );
}
