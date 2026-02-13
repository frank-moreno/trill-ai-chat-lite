<?php
/**
 * Product Indexer for WooCommerce products.
 *
 * Simplified for Lite: basic product indexing using WooCommerce native search.
 * No vector embeddings, no external API calls for indexing.
 *
 * @package TrillChatLite\WooCommerce
 * @since 1.0.0
 * @license GPL-2.0-or-later
 */

namespace TrillChatLite\WooCommerce;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class ProductIndexer
 *
 * SOLID: Single Responsibility — only product indexing.
 */
class ProductIndexer {

    /**
     * Maximum products to index per batch.
     */
    private const BATCH_SIZE = 50;

    /**
     * Run a product index refresh.
     *
     * In the Lite version, this simply ensures WooCommerce product
     * data is available for the native search used by the chat.
     *
     * @return array{indexed: int, total: int, status: string}
     */
    public function index_products(): array {
        if ( ! function_exists( 'wc_get_products' ) ) {
            return [
                'indexed' => 0,
                'total'   => 0,
                'status'  => 'woocommerce_not_active',
            ];
        }

        $product_count = \wp_count_posts( 'product' );
        $total         = (int) ( $product_count->publish ?? 0 );

        // Store product count for dashboard display.
        \update_option( 'tcl_indexed_products', $total );
        \update_option( 'tcl_last_index_time', \current_time( 'mysql' ) );

        tcl_log( 'Product index refreshed', 'info', [
            'total' => $total,
        ] );

        return [
            'indexed' => $total,
            'total'   => $total,
            'status'  => 'complete',
        ];
    }

    /**
     * Get index status.
     *
     * @return array{indexed: int, last_indexed: string}
     */
    public function get_status(): array {
        return [
            'indexed'      => (int) \get_option( 'tcl_indexed_products', 0 ),
            'last_indexed' => \get_option( 'tcl_last_index_time', '' ),
        ];
    }

    /**
     * Search products by keyword.
     *
     * Lite uses WooCommerce native search — no vector search.
     *
     * @param string $query   Search query.
     * @param int    $limit   Maximum results.
     * @param array  $filters Optional filters (category, price range, etc.).
     * @return array Product results.
     */
    public function search( string $query, int $limit = 5, array $filters = [] ): array {
        if ( ! function_exists( 'wc_get_products' ) ) {
            return [];
        }

        $args = [
            'status' => 'publish',
            'limit'  => $limit,
            's'      => $query,
        ];

        // Apply category filter.
        if ( ! empty( $filters['category'] ) ) {
            $args['category'] = [ \sanitize_text_field( $filters['category'] ) ];
        }

        // Apply price filters.
        if ( ! empty( $filters['min_price'] ) ) {
            $args['min_price'] = (float) $filters['min_price'];
        }

        if ( ! empty( $filters['max_price'] ) ) {
            $args['max_price'] = (float) $filters['max_price'];
        }

        // Apply stock filter.
        if ( ! empty( $filters['in_stock'] ) ) {
            $args['stock_status'] = 'instock';
        }

        // Apply sale filter.
        if ( ! empty( $filters['on_sale'] ) ) {
            $sale_ids = \wc_get_product_ids_on_sale();
            if ( ! empty( $sale_ids ) ) {
                $args['include'] = array_slice( $sale_ids, 0, $limit );
                unset( $args['s'] );
            }
        }

        $products = \wc_get_products( $args );

        $results = [];
        foreach ( $products as $product ) {
            $results[] = ( new ProductTransformer() )->transform( $product );
        }

        return $results;
    }
}
