<?php
/**
 * Trill AI integration with the WordPress Abilities API (WP 7.0+).
 *
 * Registers the `ecommerce` category and a first ability,
 * `trill-ai/search-products`, that exposes the plugin's WooCommerce
 * catalogue search to AI agents, MCP adapters, and any other consumer
 * of the Abilities API.
 *
 * Why expose via Abilities API: a single product-discovery service
 * (TrillChatLite\Search\ProductSearch) can be invoked from the plugin's
 * own chat widget, from external AI agents discovering capabilities
 * generically, and from automation tools — without duplicating logic.
 *
 * Backwards compatibility: every public method guards on the existence
 * of the WP 7.0 helpers so the plugin still loads cleanly on WP 6.x
 * (the abilities simply do not register).
 *
 * @package TrillChatLite\Abilities
 * @since 2.0.0
 * @license GPL-2.0-or-later
 */

namespace TrillChatLite\Abilities;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use TrillChatLite\Search\ProductSearch;

/**
 * Ability registrar.
 *
 * SOLID: Single Responsibility — only Abilities API registration.
 */
class AbilityRegistrar {

    /**
     * Hook into the Abilities API init actions.
     */
    public function init(): void {
        \add_action( 'wp_abilities_api_categories_init', [ $this, 'register_categories' ] );
        \add_action( 'wp_abilities_api_init', [ $this, 'register_abilities' ] );
    }

    /**
     * Register the categories this plugin owns.
     */
    public function register_categories(): void {
        if ( ! function_exists( 'wp_register_ability_category' ) ) {
            return; // WP < 7.0 — no Abilities API available.
        }

        \wp_register_ability_category(
            'ecommerce',
            [
                'label'       => __( 'E-commerce', 'trill-ai-chat-lite' ),
                'description' => __( 'Abilities related to e-commerce operations such as product discovery and store information.', 'trill-ai-chat-lite' ),
            ]
        );
    }

    /**
     * Register the abilities.
     */
    public function register_abilities(): void {
        if ( ! function_exists( 'wp_register_ability' ) ) {
            return; // WP < 7.0 — no Abilities API available.
        }
        if ( ! class_exists( 'WooCommerce' ) ) {
            return; // Plugin requires WooCommerce; without it the ability has nothing to search.
        }

        $this->register_search_products_ability();
    }

    /**
     * Register `trill-ai/search-products`.
     *
     * Maps directly to TrillChatLite\Search\ProductSearch::search(), with
     * the result trimmed to the caller-supplied `limit` (1-10, default 5).
     */
    private function register_search_products_ability(): void {
        \wp_register_ability(
            'trill-ai/search-products',
            [
                'label'               => __( 'Search WooCommerce Products', 'trill-ai-chat-lite' ),
                'description'         => __(
                    'Search the WooCommerce catalogue for products matching a natural-language query and return up to ten compact product cards (name, price, URL, stock status). Uses WooCommerce native full-text search as the primary strategy, with category/tag taxonomy fallback and English de-pluralisation so plural queries match singular product titles. Best for product discovery, "do you have X?" questions, and price/availability lookups.',
                    'trill-ai-chat-lite'
                ),
                'category'            => 'ecommerce',
                'input_schema'        => [
                    'type'                 => 'object',
                    'properties'           => [
                        'query' => [
                            'type'        => 'string',
                            'description' => 'Natural-language search query (e.g. "blue t-shirt", "running shoes", "red dress in stock").',
                            'minLength'   => 1,
                        ],
                        'limit' => [
                            'type'        => 'integer',
                            'description' => 'Maximum number of products to return.',
                            'minimum'     => 1,
                            'maximum'     => 10,
                            'default'     => 5,
                        ],
                    ],
                    'required'             => [ 'query' ],
                    'additionalProperties' => false,
                ],
                'output_schema'       => [
                    'type'  => 'array',
                    'items' => [
                        'type'       => 'object',
                        'properties' => [
                            'product_id' => [
                                'type'        => 'integer',
                                'description' => 'WooCommerce product ID.',
                            ],
                            'name'       => [
                                'type'        => 'string',
                                'description' => 'Product name.',
                            ],
                            'price'      => [
                                'type'        => 'string',
                                'description' => 'Formatted product price including currency symbol.',
                            ],
                            'url'        => [
                                'type'        => 'string',
                                'format'      => 'uri',
                                'description' => 'Public product page URL.',
                            ],
                            'in_stock'   => [
                                'type'        => 'boolean',
                                'description' => 'Whether the product is currently purchasable.',
                            ],
                        ],
                    ],
                ],
                'execute_callback'    => static function ( $input ) {
                    $search  = new ProductSearch();
                    $results = $search->search( $input['query'] );
                    $limit   = isset( $input['limit'] ) ? (int) $input['limit'] : 5;
                    $results = array_slice( $results, 0, max( 1, min( 10, $limit ) ) );

                    // Plain-text price for ability consumers.
                    //
                    // ProductSearch returns the WooCommerce-formatted price
                    // (HTML wrapper with span.woocommerce-Price-amount). The
                    // chat widget renders that HTML natively, but an AI
                    // agent invoking this ability wants a clean string like
                    // "£18.00". Stripping tags and decoding entities here
                    // keeps the chat widget output untouched while giving
                    // ability consumers the format they expect.
                    foreach ( $results as &$result ) {
                        $result['price'] = html_entity_decode(
                            \wp_strip_all_tags( $result['price'] ),
                            ENT_QUOTES | ENT_HTML5,
                            'UTF-8'
                        );
                    }
                    unset( $result );

                    return $results;
                },
                'permission_callback' => '__return_true', // Product data is public, mirrors wc_get_products() behaviour.
                'meta'                => [
                    'annotations'     => [
                        'readonly'    => true,
                        'destructive' => false,
                        'idempotent'  => true,
                    ],
                    'requires_plugin' => 'woocommerce',
                ],
                // NOTE: WP 7.0.0 (current) does not accept `show_in_rest`
                // — the documented property appears to land in 7.1. Until
                // then the ability is only reachable via wp_get_ability()
                // / $ability->execute() from PHP, which is fine for the
                // plugin's own consumers. When 7.1 ships we can re-add it.
            ]
        );
    }
}
