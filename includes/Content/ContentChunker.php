<?php
/**
 * Content chunker — pure paragraph splitter for the page indexing pipeline.
 *
 * Takes raw post_content (HTML / shortcodes / mixed) and produces an array
 * of ~400 char text chunks suitable for FULLTEXT indexing and prompt
 * injection. No WordPress dependencies inside `chunk()` itself so it can
 * be unit-tested in isolation; the WP-aware `prepare()` helper performs
 * the shortcode-strip and tag-strip pass when WordPress is loaded.
 *
 * Algorithm:
 *   1. Normalise whitespace and quote characters.
 *   2. Split on paragraph boundaries (\n\n or </p>-equivalent newlines).
 *   3. For oversized paragraphs (> target * 1.5), split by sentence
 *      delimiters ('. ', '? ', '! ').
 *   4. Walk the resulting fragments greedily, accumulating until the
 *      working chunk reaches `target` chars, then emit.
 *   5. Carry the last `overlap` chars into the next chunk so a query
 *      that straddles a chunk boundary still hits.
 *
 * @package TrillChatLite\Content
 * @since 2.0.0
 * @license GPL-2.0-or-later
 */

namespace TrillChatLite\Content;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class ContentChunker.
 *
 * SOLID: Single Responsibility — only text-to-chunks transformation.
 * Pure: no side effects, no DB access, no WP hooks.
 */
class ContentChunker {

    /**
     * Default target chunk size in characters.
     */
    public const DEFAULT_TARGET = 400;

    /**
     * Default overlap between adjacent chunks (chars).
     */
    public const DEFAULT_OVERLAP = 50;

    /**
     * Hard ceiling on chunks per source to prevent runaway indexing.
     *
     * Acts as a safety net for pages that paste in long logs / dumps.
     * Source content beyond ~50 chunks worth of text is silently dropped
     * from the index (the most relevant content is almost always near
     * the top of a page).
     */
    public const MAX_CHUNKS_PER_SOURCE = 50;

    /**
     * Split content into chunks.
     *
     * Pure function: no WP calls, no side effects.
     *
     * @param string $content Plain text (HTML and shortcodes should
     *                        already be stripped by `prepare()`).
     * @param int    $target  Target chunk size in characters.
     * @param int    $overlap Overlap between adjacent chunks in chars.
     * @return string[] Array of chunks (empty array when input is empty).
     */
    public static function chunk(
        string $content,
        int $target = self::DEFAULT_TARGET,
        int $overlap = self::DEFAULT_OVERLAP
    ): array {
        $content = self::normalise_whitespace( $content );

        if ( $content === '' ) {
            return [];
        }

        // Sanity bounds — protect against silly callers.
        $target  = max( 80, $target );
        $overlap = max( 0, min( $overlap, (int) ( $target / 2 ) ) );

        $paragraphs = self::split_paragraphs( $content );
        $fragments  = self::split_oversized( $paragraphs, $target );

        $chunks  = [];
        $current = '';

        foreach ( $fragments as $fragment ) {
            $fragment = trim( $fragment );
            if ( $fragment === '' ) {
                continue;
            }

            $separator = $current === '' ? '' : ' ';
            $candidate = $current . $separator . $fragment;

            if ( mb_strlen( $candidate ) <= $target ) {
                $current = $candidate;
                continue;
            }

            // Candidate would overflow — emit current chunk if non-empty.
            if ( $current !== '' ) {
                $chunks[] = $current;

                if ( count( $chunks ) >= self::MAX_CHUNKS_PER_SOURCE ) {
                    return $chunks;
                }

                // Carry the tail of the previous chunk as overlap.
                $tail    = $overlap > 0
                    ? mb_substr( $current, max( 0, mb_strlen( $current ) - $overlap ) )
                    : '';
                $current = $tail === '' ? $fragment : trim( $tail ) . ' ' . $fragment;
            } else {
                // Fragment alone exceeds target — keep it as its own chunk.
                $current = $fragment;
            }
        }

        if ( $current !== '' ) {
            $chunks[] = $current;
        }

        return $chunks;
    }

    /**
     * Prepare raw post_content for chunking.
     *
     * WordPress-aware: strips shortcodes and HTML tags via WP helpers
     * when they are loaded. Falls back to regex-based stripping when
     * called outside a WP request (unit tests, CLI smoke runs).
     *
     * @param string $raw_content Raw HTML / shortcode content.
     * @return string Plain text ready for `chunk()`.
     */
    public static function prepare( string $raw_content ): string {
        if ( $raw_content === '' ) {
            return '';
        }

        // Strip shortcodes if WP is loaded; otherwise use a regex.
        if ( function_exists( 'strip_shortcodes' ) ) {
            $text = \strip_shortcodes( $raw_content );
        } else {
            $text = preg_replace( '/\[[^\]]*\]/', ' ', $raw_content ) ?? $raw_content;
        }

        // Convert block-level closers to paragraph breaks before stripping
        // so we preserve some structure (\0 = full match).
        $text = preg_replace(
            '#</(?:p|div|li|h[1-6]|tr|blockquote|article|section)>#i',
            "$0\n\n",
            $text
        ) ?? $text;
        $text = preg_replace( '#<br\s*/?>#i', "\n", $text ) ?? $text;

        // Strip remaining HTML. wp_strip_all_tags() is the WP-approved
        // path; the regex fallback only runs in CLI / unit-test contexts
        // where WordPress isn't bootstrapped (e.g. tests/Content/
        // ChunkerSmokeTest.php). We intentionally avoid PHP's strip_tags()
        // to stay aligned with the WPCS recommendation.
        if ( function_exists( 'wp_strip_all_tags' ) ) {
            $text = \wp_strip_all_tags( $text, false );
        } else {
            // Remove HTML/PHP/XML-like tags including those broken across
            // lines. Mirrors wp_strip_all_tags' core behaviour for our
            // purposes (we already collapsed scripts/styles to text
            // earlier in this pipeline).
            $text = preg_replace( '@<[^>]*?>@u', '', $text ) ?? '';
        }

        // Decode entities (&amp; → &, &#8217; → ').
        $text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );

        return self::normalise_whitespace( $text );
    }

    /**
     * Normalise whitespace and curly quotes / dashes.
     *
     * @param string $text Input text.
     * @return string Cleaned text.
     */
    private static function normalise_whitespace( string $text ): string {
        // Curly quotes / dashes → ASCII.
        $text = strtr(
            $text,
            [
                "\u{2018}" => "'", "\u{2019}" => "'",
                "\u{201C}" => '"', "\u{201D}" => '"',
                "\u{2013}" => '-', "\u{2014}" => '-',
                "\u{00A0}" => ' ', // non-breaking space
            ]
        );

        // Normalise line endings.
        $text = str_replace( [ "\r\n", "\r" ], "\n", $text );

        // Collapse 3+ newlines to 2 (paragraph boundary preserved).
        $text = preg_replace( "/\n{3,}/", "\n\n", $text ) ?? $text;

        // Collapse runs of inline whitespace (preserve newlines).
        $text = preg_replace( '/[ \t]+/', ' ', $text ) ?? $text;

        // Trim each line so we don't drag leading indent into snippets.
        $lines = array_map( 'trim', explode( "\n", $text ) );
        $text  = implode( "\n", $lines );

        return trim( $text );
    }

    /**
     * Split text on paragraph boundaries.
     *
     * @param string $text Normalised text.
     * @return string[] Paragraphs (no empty strings).
     */
    private static function split_paragraphs( string $text ): array {
        $parts = preg_split( "/\n{2,}/", $text ) ?: [];

        return array_values( array_filter(
            array_map( 'trim', $parts ),
            static fn( string $p ): bool => $p !== ''
        ) );
    }

    /**
     * Break paragraphs that exceed target * 1.5 into sentence-sized pieces.
     *
     * @param string[] $paragraphs Paragraphs from `split_paragraphs()`.
     * @param int      $target     Target chunk size.
     * @return string[] Fragments — paragraphs or sentences.
     */
    private static function split_oversized( array $paragraphs, int $target ): array {
        $threshold = (int) ( $target * 1.5 );
        $out       = [];

        foreach ( $paragraphs as $p ) {
            if ( mb_strlen( $p ) <= $threshold ) {
                $out[] = $p;
                continue;
            }

            // Split on sentence boundaries (preserve the delimiter so
            // queries against the rendered snippet still feel natural).
            $sentences = preg_split( '/(?<=[.!?])\s+/u', $p ) ?: [ $p ];

            foreach ( $sentences as $s ) {
                $s = trim( $s );
                if ( $s !== '' ) {
                    $out[] = $s;
                }
            }
        }

        return $out;
    }
}
