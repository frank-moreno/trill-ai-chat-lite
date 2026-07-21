<?php
/**
 * Content search service — finds relevant page/policy/FAQ snippets to
 * inject into the system prompt before the chat call.
 *
 * Two-tier search:
 *
 *   1. FULLTEXT MATCH AGAINST `title`, `snippet` IN NATURAL LANGUAGE MODE.
 *      Returns rows ranked by relevance score. Fast (uses the FULLTEXT
 *      index we created in Migrations 1.1.0). The primary strategy.
 *
 *   2. LIKE %term% on title + snippet. Fallback for short queries or
 *      queries that FULLTEXT discards (single-token below
 *      innodb_ft_min_token_size, all-stopword queries). Slower but
 *      catches edge cases that would otherwise miss.
 *
 * Results are diversified by source (since 2.3.0): a wider pool is
 * fetched and only the best-scoring chunk per page survives, so a
 * page saturated with a shared term (e.g. "policy") cannot crowd
 * every other page out of the top results.
 *
 * Intent gating happens in `should_search()`:
 *
 *   - SKIP when product search already returned results (the shopper
 *     is in commercial-discovery mode; injecting policy content would
 *     dilute the prompt).
 *   - SKIP for pure greetings ("hi", "thanks", "bye") — no point
 *     searching the catalogue or the FAQ index.
 *   - DEFINITELY SEARCH when the message matches a known policy/FAQ
 *     pattern (shipping, returns, contact, hours, payment, etc.).
 *   - DEFAULT SEARCH when nothing else matched (last-resort discovery
 *     for ambiguous queries — Robin can still answer from FAQ context
 *     if products were a dead end).
 *
 * Output shape is intentionally narrow (title / snippet / url / score)
 * so PromptBuilder doesn't have to know about the underlying schema.
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
 * Class ContentSearch
 *
 * SOLID: Single Responsibility — only content retrieval (no indexing,
 * no prompt construction).
 */
class ContentSearch {

    /**
     * Table name without prefix.
     */
    private const TABLE = 'trcl_content_index';

    /**
     * Max snippet length returned to the prompt (chars, UTF-8 safe).
     * Each match should fit comfortably inside ~400 chars to keep
     * top-3 injection within ~1200 chars (~300 tokens).
     */
    private const SNIPPET_DISPLAY_MAX = 320;

    /**
     * Minimum FULLTEXT score to accept. Set to 0 (any positive match)
     * because relative ordering is what matters and a small corpus
     * (a few pages) will never reach high absolute scores.
     */
    private const FULLTEXT_MIN_SCORE = 0.0;

    /**
     * Threshold below which we trigger the LIKE fallback in addition
     * to whatever FULLTEXT returned. Catches single-token queries
     * (e.g. "shipping") whose results FULLTEXT may rank low.
     */
    private const FULLTEXT_MIN_RESULTS = 2;

    /**
     * Rows fetched from the DB before source diversification. Wider
     * than the returned limit so that a source whose chunks saturate
     * the ranking (e.g. "policy" repeated across every Cookie Policy
     * chunk) cannot crowd every other source out of the top results.
     */
    private const FETCH_POOL = 10;

    /**
     * Exact non-content messages that should short-circuit without
     * touching the DB. Identical to ProductSearch's greeting list.
     */
    private const GREETING_EXACT = [
        'hi', 'hello', 'hey', 'hiya', 'yo',
        'thanks', 'thank you', 'cheers', 'ta',
        'bye', 'goodbye', 'see you', 'ciao',
        'yes', 'no', 'ok', 'okay', 'sure', 'nope', 'yep',
    ];

    /**
     * Greeting patterns (regex). Match → not a content query.
     */
    private const GREETING_PATTERNS = [
        '/^(hi|hello|hey|good\s+(morning|afternoon|evening))\b/i',
        '/^(thanks?|thank\s+you|cheers)\b/i',
        '/^(bye|goodbye|see\s+you|take\s+care)\b/i',
    ];

    /**
     * Patterns that strongly indicate the user is asking about store
     * policies, shipping, FAQs, contact details, etc. When any of
     * these match we ALWAYS search even if product search succeeded —
     * the user clearly wants policy info, not products.
     */
    private const POLICY_PATTERNS = [
        '/\b(opening\s+hours?|business\s+hours?|when\s+(are\s+you|do\s+you)\s+open)\b/i',
        '/\b(contact|email|phone|call|speak\s+to|talk\s+to)\s+(a\s+)?(human|person|agent|someone|support|staff)\b/i',
        '/\b(return|refund|shipping|delivery|privacy|terms|conditions)\s+(policy|policies)\b/i',
        '/\b(how\s+(long|much)\s+(does|do|is)\s+(delivery|shipping|postage))\b/i',
        '/\b(track|tracking)\s+(my\s+)?(order|parcel|package|delivery)\b/i',
        '/\b(where\s+is\s+my\s+order|order\s+status|my\s+order)\b/i',
        '/\b(payment\s+method|pay\s+with|accept\s+(paypal|visa|mastercard|card|apple\s*pay|google\s*pay))\b/i',
        '/\b(cancel|change|amend)\s+(my\s+)?(order|subscription)\b/i',
        '/\b(faq|frequently\s+asked|how\s+do\s+i|how\s+can\s+i)\b/i',
        '/\b(about\s+(you|us)|who\s+are\s+you)\b/i',
        '/\b(do\s+you\s+(ship|deliver|accept|offer|provide))\b/i',
    ];

    /**
     * Decide whether to run a content search for this message.
     *
     * @param string $message                User message.
     * @param bool   $product_search_results True if ProductSearch returned hits.
     * @return bool
     */
    public function should_search( string $message, bool $product_search_results ): bool {
        $message = trim( $message );
        if ( $message === '' ) {
            return false;
        }

        // Pure greeting → skip regardless of product results.
        if ( $this->is_greeting( $message ) ) {
            return false;
        }

        // Explicit policy/FAQ intent → always search, even if product
        // search succeeded. A user asking "what's your return policy"
        // doesn't care about matching products.
        if ( $this->matches_policy_pattern( $message ) ) {
            return true;
        }

        // Product search already nailed it — don't pollute the prompt
        // with potentially-irrelevant content matches.
        if ( $product_search_results ) {
            return false;
        }

        // Fallthrough: product search came up empty, message has no
        // obvious policy intent. Worth one shot at the content index
        // in case the user is asking something off-catalogue.
        return true;
    }

    /**
     * Search the content index for snippets relevant to the message.
     *
     * @param string $message User message.
     * @param int    $limit   Max snippets to return.
     * @return array<int, array{title:string, snippet:string, url:string, score:float}>
     */
    public function search( string $message, int $limit = 3 ): array {
        $normalised = $this->normalise_query( $message );
        if ( $normalised === '' ) {
            return [];
        }
        $limit = max( 1, min( 10, $limit ) );

        try {
            // Fetch a wider pool, then keep only the best chunk per
            // source (post_id + post_type) so one keyword-saturated
            // page can't fill every slot — e.g. "refund policy" must
            // surface the Refund page even when Cookie/Privacy chunks
            // out-score it on the shared term "policy".
            $pool    = $this->fulltext_search( $normalised, self::FETCH_POOL );
            $results = $this->diversify_by_source( $pool, $limit );

            // Top-up with LIKE fallback if FULLTEXT under-delivered
            // (very short query, all stopwords, etc.). Merge dedupes by id.
            if ( count( $results ) < self::FULLTEXT_MIN_RESULTS ) {
                $like    = $this->like_search( $normalised, self::FETCH_POOL );
                $merged  = $this->merge_unique( $pool, $like, self::FETCH_POOL * 2 );
                $results = $this->diversify_by_source( $merged, $limit );
            }

            // Light formatting pass — truncate display snippet for the
            // prompt and strip the internal `id` column.
            $out = [];
            foreach ( $results as $row ) {
                $out[] = [
                    'title'   => (string) $row['title'],
                    'snippet' => $this->truncate_utf8( (string) $row['snippet'], self::SNIPPET_DISPLAY_MAX ),
                    'url'     => (string) $row['url'],
                    'score'   => (float) ( $row['score'] ?? 0 ),
                ];
            }

            trcl_log( 'Content search', 'debug', [
                'query'    => $normalised,
                'count'    => count( $out ),
                'titles'   => array_column( $out, 'title' ),
            ] );

            return $out;

        } catch ( \Throwable $e ) {
            trcl_log( 'Content search failed', 'warning', [
                'error' => $e->getMessage(),
                'query' => $normalised,
            ] );
            return [];
        }
    }

    // =========================================================================
    // INTERNALS
    // =========================================================================

    /**
     * Run a FULLTEXT NATURAL LANGUAGE search and return matched rows
     * with relevance scores, descending.
     *
     * @param string $query Normalised query.
     * @param int    $limit Max rows.
     * @return array<int, array{id:int, title:string, snippet:string, url:string, score:float}>
     */
    private function fulltext_search( string $query, int $limit ): array {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;

        // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Table from $wpdb->prefix (trusted); values bound via prepare. Block-scoped for the multi-line statement.
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, post_id, post_type, title, snippet, url,
                        MATCH(title, snippet) AGAINST(%s IN NATURAL LANGUAGE MODE) AS score
                 FROM {$table}
                 WHERE MATCH(title, snippet) AGAINST(%s IN NATURAL LANGUAGE MODE) > %f
                 ORDER BY score DESC
                 LIMIT %d",
                $query,
                $query,
                self::FULLTEXT_MIN_SCORE,
                $limit
            ),
            ARRAY_A
        );
        // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

        if ( ! is_array( $rows ) ) {
            return [];
        }
        return $rows;
    }

    /**
     * LIKE-based fallback. Slower but handles short or stopword
     * queries that FULLTEXT ignores.
     *
     * @param string $query Normalised query.
     * @param int    $limit Max rows.
     * @return array<int, array{id:int, title:string, snippet:string, url:string, score:float}>
     */
    private function like_search( string $query, int $limit ): array {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;

        $like = '%' . $wpdb->esc_like( $query ) . '%';

        // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Table from $wpdb->prefix (trusted); values esc_like'd and bound via prepare. Block-scoped for the multi-line statement.
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, post_id, post_type, title, snippet, url,
                        CASE
                            WHEN title LIKE %s THEN 1.0
                            ELSE 0.5
                        END AS score
                 FROM {$table}
                 WHERE title LIKE %s OR snippet LIKE %s
                 ORDER BY score DESC, id ASC
                 LIMIT %d",
                $like,
                $like,
                $like,
                $limit
            ),
            ARRAY_A
        );
        // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

        if ( ! is_array( $rows ) ) {
            return [];
        }
        return $rows;
    }

    /**
     * Keep only the highest-ranked chunk per source, preserving input
     * order (input is already sorted by score, descending), capped at
     * $limit distinct sources.
     *
     * A "source" is a post_id + post_type pair — two chunks of the
     * same page collapse to the best one, freeing slots for other
     * pages. Rows without source columns (defensive) pass through
     * keyed by row id, i.e. never collapsed.
     *
     * @since 2.3.0
     *
     * @param array $rows  Ranked rows (best first).
     * @param int   $limit Max distinct sources to return.
     * @return array
     */
    private function diversify_by_source( array $rows, int $limit ): array {
        $seen = [];
        $out  = [];

        foreach ( $rows as $row ) {
            $post_id = (int) ( $row['post_id'] ?? 0 );
            $key     = $post_id > 0
                ? ( (string) ( $row['post_type'] ?? '' ) ) . ':' . $post_id
                : 'row:' . (int) ( $row['id'] ?? 0 );

            if ( isset( $seen[ $key ] ) ) {
                continue;
            }
            $seen[ $key ] = true;
            $out[]        = $row;

            if ( count( $out ) >= $limit ) {
                break;
            }
        }

        return $out;
    }

    /**
     * Merge two result sets, deduplicating by row id, preserving the
     * first set's order. Caps at $limit.
     *
     * @param array $primary  First set (FULLTEXT).
     * @param array $fallback Second set (LIKE).
     * @param int   $limit    Hard cap on combined output.
     * @return array
     */
    private function merge_unique( array $primary, array $fallback, int $limit ): array {
        $seen = [];
        $out  = [];

        foreach ( $primary as $row ) {
            $id = (int) ( $row['id'] ?? 0 );
            if ( $id === 0 || isset( $seen[ $id ] ) ) {
                continue;
            }
            $seen[ $id ] = true;
            $out[]       = $row;
        }
        foreach ( $fallback as $row ) {
            if ( count( $out ) >= $limit ) {
                break;
            }
            $id = (int) ( $row['id'] ?? 0 );
            if ( $id === 0 || isset( $seen[ $id ] ) ) {
                continue;
            }
            $seen[ $id ] = true;
            $out[]       = $row;
        }

        return array_slice( $out, 0, $limit );
    }

    /**
     * Normalise the user message into a search-friendly string.
     *
     * Goals:
     *   - Lowercase for case-insensitive matching.
     *   - Drop punctuation that FULLTEXT treats as boundaries anyway.
     *   - Collapse whitespace.
     *   - Trim filler that adds nothing ("please", "could you", etc.)
     *     so single-keyword intent shines through.
     *
     * Conservative: we never invent words. If after normalisation
     * the query is empty, return '' so callers skip.
     *
     * @param string $message Raw message.
     * @return string
     */
    private function normalise_query( string $message ): string {
        $q = strtolower( trim( $message ) );

        // Strip leading conversational openers.
        $openers = [
            'could you tell me', 'could you', 'can you tell me', 'can you',
            'please tell me', 'please', 'tell me about', 'tell me',
            'i would like to know about', 'i would like to know',
            'i want to know about', 'i want to know',
            'do you know', 'i was wondering',
        ];
        foreach ( $openers as $opener ) {
            if ( str_starts_with( $q, $opener . ' ' ) ) {
                $q = (string) substr( $q, strlen( $opener ) + 1 );
            }
        }

        // Replace punctuation with spaces.
        $q = preg_replace( '/[^\p{L}\p{N}\s]/u', ' ', $q ) ?? $q;

        // Collapse whitespace.
        $q = preg_replace( '/\s+/', ' ', $q ) ?? $q;

        return trim( $q );
    }

    /**
     * @param string $message Lowercased + trimmed message.
     * @return bool
     */
    private function is_greeting( string $message ): bool {
        $lower = strtolower( $message );
        if ( in_array( $lower, self::GREETING_EXACT, true ) ) {
            return true;
        }
        foreach ( self::GREETING_PATTERNS as $pattern ) {
            if ( preg_match( $pattern, $lower ) ) {
                // Heuristic: don't treat "hi do you ship to france" as
                // a greeting just because it starts with "hi". Only
                // short messages count.
                if ( mb_strlen( $lower ) <= 20 ) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * @param string $message User message.
     * @return bool
     */
    private function matches_policy_pattern( string $message ): bool {
        foreach ( self::POLICY_PATTERNS as $pattern ) {
            if ( preg_match( $pattern, $message ) ) {
                return true;
            }
        }
        return false;
    }

    /**
     * UTF-8 safe truncation.
     *
     * @param string $text Input.
     * @param int    $max  Max characters.
     * @return string
     */
    private function truncate_utf8( string $text, int $max ): string {
        if ( $max <= 0 ) {
            return '';
        }
        if ( mb_strlen( $text ) <= $max ) {
            return $text;
        }
        // Trim to max chars then back to last whitespace boundary if close.
        $cut = mb_substr( $text, 0, $max );
        $sp  = mb_strrpos( $cut, ' ' );
        if ( $sp !== false && $sp >= $max - 40 ) {
            $cut = mb_substr( $cut, 0, $sp );
        }
        return rtrim( $cut, " \t\n\r.,;:" ) . '...';
    }
}
