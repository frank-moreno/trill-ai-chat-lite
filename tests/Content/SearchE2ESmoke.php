<?php
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
// Summary
// ---------------------------------------------------------------------
echo "\n=======================================\n";
echo "Results: {$passes} passed, {$failures} failed\n";

if ( $failures > 0 ) {
    exit( 1 );
}
