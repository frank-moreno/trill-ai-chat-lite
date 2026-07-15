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

use TrillChatLite\Database\DbManager;
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
            return; // Plugin requires WooCommerce; without it the abilities have nothing to expose.
        }

        $this->register_search_products_ability();
        $this->register_get_store_context_ability();
        $this->register_get_conversation_summary_ability();
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

    /**
     * Register `trill-ai/get-store-context`.
     *
     * Returns the same store metadata that the chat widget already
     * sends to the AI proxy on every message: store name, URL, tagline,
     * currency, total product count, and the top five product
     * categories. Useful for AI agents that want to introduce
     * themselves or contextualise their responses without having to
     * call half a dozen WordPress / WooCommerce functions themselves.
     *
     * The logic mirrors RestController::build_store_context() (private)
     * deliberately — kept inline rather than extracted into a service
     * class to keep ABL-02 narrow. Consolidation can happen later as
     * part of a wider cleanup pass.
     */
    private function register_get_store_context_ability(): void {
        \wp_register_ability(
            'trill-ai/get-store-context',
            [
                'label'               => __( 'Get Store Context', 'trill-ai-chat-lite' ),
                'description'         => __(
                    'Returns metadata about the WooCommerce store: name, URL, tagline, currency code, currency symbol, total published product count, and up to five top categories ordered by product count. Designed as a one-shot context primer for AI agents before they call other Trill abilities or compose a response.',
                    'trill-ai-chat-lite'
                ),
                'category'            => 'ecommerce',
                'output_schema'       => [
                    'type'       => 'object',
                    'properties' => [
                        'store_name'        => [ 'type' => 'string', 'description' => 'Site name (get_bloginfo("name")).' ],
                        'store_url'         => [ 'type' => 'string', 'format' => 'uri', 'description' => 'Site URL.' ],
                        'store_description' => [ 'type' => 'string', 'description' => 'Site tagline.' ],
                        'currency'          => [ 'type' => 'string', 'description' => 'Three-letter currency code (e.g. GBP).' ],
                        'currency_symbol'   => [ 'type' => 'string', 'description' => 'Currency symbol (e.g. £). Decoded plain text, no HTML entities.' ],
                        'total_products'    => [ 'type' => 'integer', 'description' => 'Number of published products.' ],
                        'top_categories'    => [
                            'type'        => 'array',
                            'description' => 'Up to five top product category names ordered by product count.',
                            'items'       => [ 'type' => 'string' ],
                        ],
                    ],
                ],
                'execute_callback'    => static function () {
                    $context = [
                        'store_name'        => \get_bloginfo( 'name' ),
                        'store_url'         => \get_site_url(),
                        'store_description' => \get_bloginfo( 'description' ),
                        'currency'          => '',
                        'currency_symbol'   => '',
                        'total_products'    => 0,
                        'top_categories'    => [],
                    ];

                    if ( ! function_exists( 'WC' ) ) {
                        return $context;
                    }

                    $context['currency']        = \get_woocommerce_currency();
                    $context['currency_symbol'] = html_entity_decode(
                        \get_woocommerce_currency_symbol(),
                        ENT_QUOTES | ENT_HTML5,
                        'UTF-8'
                    );

                    $product_count             = \wp_count_posts( 'product' );
                    $context['total_products'] = (int) ( $product_count->publish ?? 0 );

                    $terms = \get_terms( [
                        'taxonomy'   => 'product_cat',
                        'orderby'    => 'count',
                        'order'      => 'DESC',
                        'number'     => 5,
                        'hide_empty' => true,
                    ] );

                    if ( ! \is_wp_error( $terms ) && ! empty( $terms ) ) {
                        $context['top_categories'] = array_values( \wp_list_pluck( $terms, 'name' ) );
                    }

                    return $context;
                },
                'permission_callback' => '__return_true', // Store metadata is public, mirrors get_bloginfo().
                'meta'                => [
                    'annotations'     => [
                        'readonly'    => true,
                        'destructive' => false,
                        'idempotent'  => true,
                    ],
                    'requires_plugin' => 'woocommerce',
                ],
            ]
        );
    }

    /**
     * Register `trill-ai/get-conversation-summary`.
     *
     * Returns the last N messages of a Trill chat session, identified
     * by its UUID session_id. Used by AI agents that need to follow up
     * on, summarise, or moderate an ongoing visitor conversation.
     *
     * Permission model (intentionally light, matches the existing
     * GET /trcl/v1/conversation/{session_id} endpoint):
     *
     *   - Administrators with `manage_options` are always allowed.
     *   - Other callers must pass a valid UUID session_id. Anyone
     *     who knows the UUID can read the conversation, which is
     *     the same posture as the public REST endpoint today.
     *
     * Cookie/fingerprint-based ownership ("only the visitor who owns
     * the session can read it") is a known gap in the REST surface
     * and is tracked separately — fixing it here would silently change
     * the permission model for a second consumer without addressing
     * the first.
     */
    private function register_get_conversation_summary_ability(): void {
        \wp_register_ability(
            'trill-ai/get-conversation-summary',
            [
                'label'               => __( 'Get Conversation Summary', 'trill-ai-chat-lite' ),
                'description'         => __(
                    'Retrieves up to fifty most-recent messages from a Trill chat session identified by its UUID session_id. Each message includes id, role (user or assistant), content, and timestamp. Returns an empty messages array if the session is unknown.',
                    'trill-ai-chat-lite'
                ),
                'category'            => 'ecommerce',
                'input_schema'        => [
                    'type'                 => 'object',
                    'properties'           => [
                        'session_id' => [
                            'type'        => 'string',
                            'description' => 'UUID v4 session identifier (lowercase hex with dashes).',
                            'pattern'     => '^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$',
                        ],
                        'limit'      => [
                            'type'        => 'integer',
                            'description' => 'Maximum number of messages to return (most recent first by storage order).',
                            'minimum'     => 1,
                            'maximum'     => 50,
                            'default'     => 20,
                        ],
                    ],
                    'required'             => [ 'session_id' ],
                    'additionalProperties' => false,
                ],
                'output_schema'       => [
                    'type'       => 'object',
                    'properties' => [
                        'session_id' => [ 'type' => 'string', 'description' => 'Echo of the requested session_id.' ],
                        'found'      => [ 'type' => 'boolean', 'description' => 'Whether the session exists in the database.' ],
                        'messages'   => [
                            'type'  => 'array',
                            'items' => [
                                'type'       => 'object',
                                'properties' => [
                                    'id'        => [ 'type' => 'integer', 'description' => 'Internal message ID.' ],
                                    'role'      => [ 'type' => 'string', 'description' => 'Either "user" or "assistant".' ],
                                    'content'   => [ 'type' => 'string', 'description' => 'Raw message body.' ],
                                    'timestamp' => [ 'type' => 'string', 'description' => 'MySQL DATETIME string in UTC.' ],
                                ],
                            ],
                        ],
                    ],
                ],
                'execute_callback'    => static function ( $input ) {
                    $session_id = (string) $input['session_id'];
                    $limit      = isset( $input['limit'] ) ? (int) $input['limit'] : 20;
                    $limit      = max( 1, min( 50, $limit ) );

                    $db = new DbManager();

                    if ( ! $db->conversation_exists( $session_id ) ) {
                        return [
                            'session_id' => $session_id,
                            'found'      => false,
                            'messages'   => [],
                        ];
                    }

                    $rows      = $db->get_messages( $session_id, $limit );
                    $formatted = array_map(
                        static function ( $msg ) {
                            return [
                                'id'        => (int) ( $msg->id ?? 0 ),
                                'role'      => (string) ( $msg->role ?? '' ),
                                'content'   => (string) ( $msg->content ?? '' ),
                                'timestamp' => (string) ( $msg->created_at ?? '' ),
                            ];
                        },
                        $rows
                    );

                    return [
                        'session_id' => $session_id,
                        'found'      => true,
                        'messages'   => $formatted,
                    ];
                },
                'permission_callback' => static function ( $input ) {
                    // Administrators always allowed.
                    if ( \current_user_can( 'manage_options' ) ) {
                        return true;
                    }

                    // Visitor path: validate UUID format. This matches the existing
                    // GET /trcl/v1/conversation/{session_id} endpoint posture —
                    // anyone with the UUID can read. Cookie/fingerprint ownership
                    // is a known gap to be addressed separately for both surfaces.
                    $session_id = $input['session_id'] ?? '';
                    if ( ! preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', (string) $session_id ) ) {
                        return new \WP_Error(
                            'invalid_session_id',
                            __( 'Invalid session identifier.', 'trill-ai-chat-lite' ),
                            [ 'status' => 400 ]
                        );
                    }

                    return true;
                },
                'meta'                => [
                    'annotations'     => [
                        'readonly'    => true,
                        'destructive' => false,
                        'idempotent'  => true,
                    ],
                    'requires_plugin' => 'woocommerce',
                ],
            ]
        );
    }
}
