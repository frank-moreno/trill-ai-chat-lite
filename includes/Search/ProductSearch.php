<?php
/**
 * Product search service.
 *
 * Encapsulates the product-discovery logic that maps a shopper message to a
 * list of WooCommerce products. Extracted from RestController in v2.0.0
 * (CORE-01) so the same service can be reused by the Abilities API
 * (`trill-ai/search-products`) and by future internal callers without
 * duplicating intent-detection and de-pluralisation logic.
 *
 * Self-contained: no injected dependencies. Relies only on WooCommerce
 * globals (`wc_get_products`, `get_terms`, etc.) and plugin-wide helpers
 * (`trcl_format_price`, `trcl_log`) defined in `includes/functions.php`.
 *
 * @package TrillChatLite\Search
 * @since 2.0.0
 * @license GPL-2.0-or-later
 */

namespace TrillChatLite\Search;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Product search service.
 *
 * SOLID: Single Responsibility — only product discovery from a message.
 */
class ProductSearch {

    /**
     * Search for products relevant to the message.
     *
     * Uses WooCommerce native search as primary strategy, then falls back
     * to taxonomy-based search (categories/tags) if no results are found.
     *
     * @param string $message User message.
     * @return array Product results or empty array.
     */
    public function search( string $message ): array {
        if ( ! function_exists( 'wc_get_products' ) ) {
            return [];
        }

        // Check if message is product-related.
        if ( ! $this->is_product_query( $message ) ) {
            return [];
        }

        try {
            $search_query = $this->extract_search_query( $message );
            $variants     = $this->get_search_variants( $search_query );
            $products     = [];

            // Primary: WooCommerce native full-text search with plural variants.
            foreach ( $variants as $variant ) {
                $products = \wc_get_products( [
                    'status' => 'publish',
                    'limit'  => 5,
                    's'      => $variant,
                ] );
                if ( ! empty( $products ) ) {
                    break;
                }
            }

            // Fallback: taxonomy search (categories + tags) when native returns empty.
            if ( empty( $products ) ) {
                foreach ( $variants as $variant ) {
                    $products = $this->search_by_taxonomy( $variant );
                    if ( ! empty( $products ) ) {
                        break;
                    }
                }
            }

            $results = [];
            foreach ( $products as $product ) {
                $results[] = [
                    'product_id' => $product->get_id(),
                    'name'       => $product->get_name(),
                    'price'      => trcl_format_price( $product->get_price() ),
                    'url'        => $product->get_permalink(),
                    'in_stock'   => $product->is_in_stock(),
                ];
            }

            return $results;

        } catch ( \Exception $e ) {
            trcl_log( 'Product search failed', 'warning', [ 'error' => $e->getMessage() ] );
            return [];
        }
    }

    /**
     * Check if message is asking about products.
     *
     * Uses inverted logic: assumes any message COULD be product-related
     * unless it clearly matches a non-product pattern (greetings, thanks,
     * support requests, etc.). This is safer for an e-commerce chatbot
     * where false negatives (missing a product query) are more costly
     * than false positives (searching when unnecessary).
     *
     * @param string $message User message.
     * @return bool True if the message may be product-related.
     */
    public function is_product_query( string $message ): bool {
        $message_lower = strtolower( trim( $message ) );

        // Very short messages that are clearly not product queries.
        $non_product_exact = [
            'hi', 'hello', 'hey', 'hiya', 'yo',
            'thanks', 'thank you', 'cheers', 'ta',
            'bye', 'goodbye', 'see you', 'ciao',
            'yes', 'no', 'ok', 'okay', 'sure', 'nope', 'yep',
            'help', 'support', 'help me',
        ];

        if ( in_array( $message_lower, $non_product_exact, true ) ) {
            return false;
        }

        // Patterns that indicate non-product queries.
        $non_product_patterns = [
            '/^(hi|hello|hey|good\s+(morning|afternoon|evening))\b/i',
            '/^(thanks?|thank\s+you|cheers)\b/i',
            '/^(bye|goodbye|see\s+you|take\s+care)\b/i',
            '/\b(opening\s+hours?|business\s+hours?|when\s+(are\s+you|do\s+you)\s+open)\b/i',
            '/\b(contact|email|phone|call|speak\s+to|talk\s+to)\s+(a\s+)?(human|person|agent|someone|support|staff)\b/i',
            '/\b(return\s+policy|refund\s+policy|shipping\s+policy|privacy\s+policy|terms\s+and\s+conditions)\b/i',
            '/\b(track|tracking)\s+(my\s+)?(order|parcel|package|delivery)\b/i',
            '/\b(who\s+are\s+you|what\s+are\s+you|what\s+can\s+you\s+do)\b/i',
            '/\b(how\s+(long|much)\s+(does|do|is)\s+(delivery|shipping|postage))\b/i',
            '/\b(where\s+is\s+my\s+order|order\s+status|my\s+order)\b/i',
            '/\b(payment\s+method|pay\s+with|accept\s+(paypal|visa|mastercard|card))\b/i',
            '/\b(cancel|change|amend)\s+(my\s+)?(order|subscription)\b/i',
        ];

        foreach ( $non_product_patterns as $pattern ) {
            if ( preg_match( $pattern, $message_lower ) ) {
                return false;
            }
        }

        // Everything else: assume it could be product-related.
        return true;
    }

    /**
     * Fallback: search products by matching category or tag names.
     *
     * WooCommerce native 's' parameter only searches post_title and
     * post_content. This method catches products that are tagged or
     * categorised with the search term but whose title doesn't contain it
     * (e.g. a "V-Neck Tee" in the "T-Shirts" category).
     *
     * @param string $query Cleaned search query.
     * @param int    $limit Maximum results.
     * @return \WC_Product[] Matching products or empty array.
     */
    private function search_by_taxonomy( string $query, int $limit = 5 ): array {
        $words    = array_filter( explode( ' ', $query ) );
        $products = [];

        // Search product categories.
        $cat_terms = \get_terms( [
            'taxonomy'   => 'product_cat',
            'hide_empty' => true,
            'search'     => $query,
        ] );

        if ( ! \is_wp_error( $cat_terms ) && ! empty( $cat_terms ) ) {
            $cat_slugs = \wp_list_pluck( $cat_terms, 'slug' );
            $products  = \wc_get_products( [
                'status'   => 'publish',
                'limit'    => $limit,
                'category' => $cat_slugs,
            ] );
        }

        // If still empty, try product tags.
        if ( empty( $products ) ) {
            $tag_terms = \get_terms( [
                'taxonomy'   => 'product_tag',
                'hide_empty' => true,
                'search'     => $query,
            ] );

            if ( ! \is_wp_error( $tag_terms ) && ! empty( $tag_terms ) ) {
                $tag_slugs = \wp_list_pluck( $tag_terms, 'slug' );
                $products  = \wc_get_products( [
                    'status' => 'publish',
                    'limit'  => $limit,
                    'tag'    => $tag_slugs,
                ] );
            }
        }

        // Last resort: try each word individually (with plural variants).
        if ( empty( $products ) && count( $words ) > 1 ) {
            foreach ( $words as $word ) {
                if ( mb_strlen( $word ) < 3 ) {
                    continue;
                }
                $word_variants = $this->get_search_variants( $word );
                foreach ( $word_variants as $wv ) {
                    $products = \wc_get_products( [
                        'status' => 'publish',
                        'limit'  => $limit,
                        's'      => $wv,
                    ] );
                    if ( ! empty( $products ) ) {
                        break 2;
                    }
                }
            }
        }

        return $products;
    }

    /**
     * Extract search query from user message.
     *
     * Strips conversational preamble and filler words, leaving only
     * the terms likely to match WooCommerce product titles/descriptions.
     *
     * @param string $message User message.
     * @return string Cleaned search query.
     */
    private function extract_search_query( string $message ): string {
        $query = strtolower( $message );

        // Remove conversational preambles (order matters: longest first).
        $remove_phrases = [
            // "I'm looking for / I am looking for" family.
            "i'm looking for", 'i am looking for',
            // "Do you have / sell" family.
            'do you have any', 'do you have',
            'do you sell any', 'do you sell',
            'do you stock any', 'do you stock',
            'do you carry any', 'do you carry',
            // "Can / Could" family.
            'can i buy', 'can i get', 'can i see',
            'can you show me', 'can you recommend',
            'could you show me', 'could you recommend',
            // "Where / What" family.
            'where can i find', 'where are the', 'where are your',
            "what's the price of", 'what is the price of',
            "what's available in", 'what is available in',
            'what about', 'what kind of', 'what types of',
            'what sort of',
            // "Show / List" family.
            'show me your', 'show me some', 'show me all', 'show me',
            'list me your', 'list me all', 'list me', 'list your', 'list all',
            // "Have you got" family (British English).
            'have you got any', 'have you got',
            'got any',
            // "I want / need / would like" family.
            'i want to buy', 'i want to see', 'i want some', 'i want',
            'i need to buy', 'i need some', 'i need',
            'i would like to see', 'i would like to buy',
            'i would like some', 'i would like',
            "i'd like to see", "i'd like to buy",
            "i'd like some", "i'd like",
            // "Tell me / Know about" family.
            'tell me about your', 'tell me about',
            'tell me more about', 'know about your',
            // "Looking / Search" family.
            'looking for some', 'looking for',
            'search for', 'find me some', 'find me',
            // "How much" family.
            'how much is', 'how much are', 'how much do',
            'how much does', 'how much for',
            // "Are / Is there" family.
            'are there any', 'is there any', 'is there a',
            // "Recommend" family.
            'any recommendations for', 'any good',
            'recommend me some', 'recommend me',
            'what do you recommend for', 'what do you recommend',
            "what's popular in",
            // "Please" family.
            'please show me', 'please show', 'please find',
            'please list',
        ];

        foreach ( $remove_phrases as $phrase ) {
            $query = str_ireplace( $phrase, '', $query );
        }

        // Remove location/context suffixes that pollute the search term.
        $remove_suffixes = [
            'in your store', 'in the store', 'in your shop', 'in the shop',
            'in this store', 'in this shop', 'on your website', 'on the website',
            'on your site', 'on the site', 'on this site',
            'in your catalogue', 'in the catalogue', 'in your catalog', 'in the catalog',
            'in your collection', 'in the collection',
            'in stock', 'available', 'for sale',
            'that you sell', 'that you have', 'that you offer',
            'you carry', 'you stock', 'you offer',
            'right now', 'at the moment', 'currently', 'today',
            'for me', 'for us',
        ];

        foreach ( $remove_suffixes as $suffix ) {
            $query = str_ireplace( $suffix, '', $query );
        }

        // Remove filler words, punctuation, and articles.
        $query = preg_replace( '/\b(a|an|the|some|any|please|just|maybe|all|your|my|this|that|those|these)\b/', '', $query );
        $query = str_replace( [ '?', '!', '.', ',', ';', ':' ], '', $query );
        $query = trim( preg_replace( '/\s+/', ' ', $query ) );

        // If the cleaned query is empty or too short (< 2 chars), fall back to
        // the longest word(s) from the original message as a last resort.
        if ( mb_strlen( $query ) < 2 ) {
            $fallback_words = array_filter(
                explode( ' ', strtolower( preg_replace( '/[^a-zA-Z0-9\s\-]/', '', $message ) ) ),
                function ( $w ) {
                    return mb_strlen( $w ) >= 3;
                }
            );
            if ( ! empty( $fallback_words ) ) {
                // Sort by length descending — longest words are most likely product terms.
                usort( $fallback_words, function ( $a, $b ) {
                    return mb_strlen( $b ) - mb_strlen( $a );
                } );
                $query = implode( ' ', array_slice( $fallback_words, 0, 3 ) );
            }
        }

        return $query ?: strtolower( $message );
    }

    /**
     * Normalise a search term for WooCommerce: try the original and
     * de-pluralised / stemmed variants.
     *
     * WooCommerce native search uses MySQL LIKE %term% which is literal,
     * so "t-shirts" won't match "T-Shirt". This helper returns multiple
     * forms so the caller can try each until one matches.
     *
     * Covers common English plural rules:
     *  - ies → y   (accessories → accessory, hoodies handled by -s rule too)
     *  - ves → f   (scarves → scarf)
     *  - ses/xes/zes/ches/shes → remove trailing "es"
     *  - generic -es  (dresses → dress)
     *  - generic -s   (t-shirts → t-shirt)
     *
     * For multi-word queries each word is also de-pluralised individually
     * and the result added as an extra variant.
     *
     * @param string $term Single or multi-word search term.
     * @return string[] Array of term variants to try (original first).
     */
    private function get_search_variants( string $term ): array {
        $variants = [ $term ];

        // De-pluralise the whole term.
        $singular = $this->depluralize( $term );
        if ( $singular !== $term ) {
            $variants[] = $singular;
        }

        // For multi-word terms, de-pluralise each word individually.
        if ( strpos( $term, ' ' ) !== false ) {
            $words   = explode( ' ', $term );
            $stemmed = array_map( [ $this, 'depluralize' ], $words );
            $joined  = implode( ' ', $stemmed );
            if ( $joined !== $term ) {
                $variants[] = $joined;
            }
        }

        return array_unique( $variants );
    }

    /**
     * Attempt to de-pluralise a single English word.
     *
     * @param string $word Single word.
     * @return string Singular form (best effort) or original.
     */
    private function depluralize( string $word ): string {
        $len = mb_strlen( $word );

        // Too short to safely stem.
        if ( $len < 4 ) {
            return $word;
        }

        // -ies → -y  (accessories → accessory, categories → category).
        if ( preg_match( '/[^aeiou]ies$/i', $word ) ) {
            return preg_replace( '/ies$/i', 'y', $word );
        }

        // -ves → -f  (scarves → scarf, knives → knife).
        if ( preg_match( '/ves$/i', $word ) && $len > 4 ) {
            return preg_replace( '/ves$/i', 'f', $word );
        }

        // -ses, -xes, -zes, -ches, -shes → remove "es"
        // (dresses→dress, boxes→box, watches→watch, brushes→brush).
        if ( preg_match( '/(ss|x|z|ch|sh)es$/i', $word ) ) {
            return preg_replace( '/es$/i', '', $word );
        }

        // Generic -es when word is long enough (shoes stays shoes→shoe OK).
        if ( preg_match( '/[^s]es$/i', $word ) && $len > 4 ) {
            return preg_replace( '/es$/i', '', $word );
        }

        // Generic -s (t-shirts→t-shirt, bags→bag).
        if ( preg_match( '/[^s]s$/i', $word ) && $len > 3 ) {
            return preg_replace( '/s$/i', '', $word );
        }

        return $word;
    }
}
