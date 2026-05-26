<?php
// phpcs:disable
// ^^ Dev-only smoke test. Excluded from the wp.org SVN package via
//    .distignore. Uses plain `echo` for human-readable CLI output and
//    direct PHP file I/O for the abort-gate — both are intentional for
//    a stand-alone harness and not appropriate WPCS subjects.
/**
 * Smoke test for ContentChunker — runs under plain PHP, no PHPUnit.
 *
 * Usage:
 *   php tests/Content/ChunkerSmokeTest.php
 *
 * Exits 0 on success, 1 on failure. Designed so we can validate the
 * pure-function chunker without the WordPress test harness. Once the
 * project grows a real PHPUnit setup these cases port directly.
 *
 * @package TrillChatLite\Tests\Content
 * @since 2.0.0
 * @license GPL-2.0-or-later
 */

// Allow direct CLI invocation.
if ( ! defined( 'ABSPATH' ) ) {
    define( 'ABSPATH', dirname( __DIR__, 2 ) . '/' );
}

require_once dirname( __DIR__, 2 ) . '/includes/Content/ContentChunker.php';

use TrillChatLite\Content\ContentChunker;

$failures = 0;
$passes   = 0;

/**
 * Tiny assertion helper.
 */
function trcl_assert( bool $cond, string $name, string $detail = '' ): void {
    global $failures, $passes;
    if ( $cond ) {
        $passes++;
        echo "  ✓ {$name}\n";
        return;
    }
    $failures++;
    echo "  ✗ {$name}\n";
    if ( $detail !== '' ) {
        echo "    {$detail}\n";
    }
}

echo "ContentChunker smoke test\n";
echo "=========================\n\n";

// ---------------------------------------------------------------------
// Case 1: empty input returns empty array.
// ---------------------------------------------------------------------
echo "Case 1: empty input\n";
trcl_assert(
    ContentChunker::chunk( '' ) === [],
    'empty string returns empty array'
);
trcl_assert(
    ContentChunker::chunk( "   \n\n   " ) === [],
    'whitespace-only input returns empty array'
);

// ---------------------------------------------------------------------
// Case 2: single short paragraph fits in one chunk.
// ---------------------------------------------------------------------
echo "\nCase 2: short paragraph\n";
$short  = 'Our standard shipping takes 3-5 business days within the UK.';
$chunks = ContentChunker::chunk( $short );
trcl_assert( count( $chunks ) === 1, 'one chunk produced', 'got ' . count( $chunks ) );
trcl_assert( $chunks[0] === $short, 'content preserved verbatim' );

// ---------------------------------------------------------------------
// Case 3: multi-paragraph under target stays as one chunk (concatenated).
// ---------------------------------------------------------------------
echo "\nCase 3: short multi-paragraph\n";
$multi  = "We accept returns within 30 days.\n\nContact support@example.com for help.";
$chunks = ContentChunker::chunk( $multi, 400 );
trcl_assert( count( $chunks ) === 1, 'concatenated into one chunk', 'got ' . count( $chunks ) );
trcl_assert(
    str_contains( $chunks[0], 'returns within 30 days' ) && str_contains( $chunks[0], 'support@example.com' ),
    'both paragraphs present in single chunk'
);

// ---------------------------------------------------------------------
// Case 4: long input requiring sentence split produces multiple chunks.
// ---------------------------------------------------------------------
echo "\nCase 4: long content forces multiple chunks\n";
$long = str_repeat(
    'Standard delivery within the UK takes three to five business days from dispatch. '
    . 'Express delivery is one to two business days and costs an extra five pounds. '
    . 'International orders take seven to fourteen days depending on the destination country. ',
    4
);
$chunks = ContentChunker::chunk( $long, 200, 30 );
trcl_assert( count( $chunks ) >= 2, 'multiple chunks produced', 'got ' . count( $chunks ) );
trcl_assert(
    array_reduce(
        $chunks,
        static fn( bool $ok, string $c ): bool => $ok && mb_strlen( $c ) <= 320,
        true
    ),
    'no chunk grossly exceeds target (allowing for sentence-final spillover)'
);

// ---------------------------------------------------------------------
// Case 5: overlap carries trailing content into the next chunk.
// ---------------------------------------------------------------------
echo "\nCase 5: overlap preserved between adjacent chunks\n";
$paragraph1 = 'Returns must be initiated within thirty days of the original purchase date.';
$paragraph2 = 'Refunds are issued to the original payment method within five business days.';
$paragraph3 = 'For exchanges please contact our support team via the help centre.';
$input      = $paragraph1 . "\n\n" . $paragraph2 . "\n\n" . $paragraph3;
$chunks     = ContentChunker::chunk( $input, 100, 30 );
trcl_assert( count( $chunks ) >= 2, 'multiple chunks produced for overlap test', 'got ' . count( $chunks ) );
if ( count( $chunks ) >= 2 ) {
    $tail_of_first = mb_substr( $chunks[0], max( 0, mb_strlen( $chunks[0] ) - 20 ) );
    trcl_assert(
        str_contains( $chunks[1], mb_substr( $tail_of_first, -10 ) ),
        'second chunk begins with overlap from first'
    );
}

// ---------------------------------------------------------------------
// Case 6: prepare() strips shortcodes and HTML.
// ---------------------------------------------------------------------
echo "\nCase 6: prepare() cleans HTML and shortcodes\n";
$raw     = '<p>Hello <strong>world</strong>!</p>[gallery ids="1,2,3"]<p>Goodbye &amp; thanks.</p>';
$cleaned = ContentChunker::prepare( $raw );
trcl_assert(
    ! str_contains( $cleaned, '<' ) && ! str_contains( $cleaned, '>' ),
    'no HTML tags remain',
    'cleaned: ' . $cleaned
);
trcl_assert(
    ! str_contains( $cleaned, '[gallery' ),
    'shortcode removed'
);
trcl_assert(
    str_contains( $cleaned, 'Hello world' ),
    'visible text preserved'
);
trcl_assert(
    str_contains( $cleaned, 'Goodbye & thanks' ),
    'entities decoded (&amp; → &)'
);

// ---------------------------------------------------------------------
// Case 7: respects MAX_CHUNKS_PER_SOURCE ceiling.
// ---------------------------------------------------------------------
echo "\nCase 7: hard ceiling on chunks per source\n";
$huge = str_repeat( "This is paragraph filler.\n\n", 1000 );
$chunks = ContentChunker::chunk( $huge, 80, 10 );
trcl_assert(
    count( $chunks ) <= ContentChunker::MAX_CHUNKS_PER_SOURCE,
    'chunk count capped at MAX_CHUNKS_PER_SOURCE (' . ContentChunker::MAX_CHUNKS_PER_SOURCE . ')',
    'got ' . count( $chunks )
);

// ---------------------------------------------------------------------
// Summary
// ---------------------------------------------------------------------
echo "\n=========================\n";
echo "Results: {$passes} passed, {$failures} failed\n";

exit( $failures === 0 ? 0 : 1 );
