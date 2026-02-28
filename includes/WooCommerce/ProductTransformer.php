<?php
/**
 * Product Transformer.
 *
 * Transforms WooCommerce product objects into standardised arrays
 * for the AI context and frontend display.
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
 * Class ProductTransformer
 *
 * SOLID: Single Responsibility — only product data transformation.
 */
class ProductTransformer {

    /**
     * Transform a WooCommerce product to a standardised array.
     *
     * @param \WC_Product $product WooCommerce product object.
     * @return array Transformed product data.
     */
    public function transform( \WC_Product $product ): array {
        return [
            'product_id'  => $product->get_id(),
            'name'        => $product->get_name(),
            'price'       => $product->get_price(),
            'price_html'  => $product->get_price_html(),
            'description' => wp_trim_words( $product->get_short_description(), 30, '...' ),
            'url'         => $product->get_permalink(),
            'image'       => $this->get_image_url( $product ),
            'in_stock'    => $product->is_in_stock(),
            'on_sale'     => $product->is_on_sale(),
            'categories'  => $this->get_categories( $product ),
            'rating'      => (float) $product->get_average_rating(),
        ];
    }

    /**
     * Transform a product for AI context (lighter).
     *
     * @param \WC_Product $product WooCommerce product object.
     * @return array Lightweight product data for AI.
     */
    public function transform_for_ai( \WC_Product $product ): array {
        return [
            'id'          => $product->get_id(),
            'name'        => $product->get_name(),
            'price'       => trcl_format_price( $product->get_price() ),
            'description' => wp_trim_words( $product->get_short_description(), 20, '...' ),
            'url'         => $product->get_permalink(),
            'in_stock'    => $product->is_in_stock(),
        ];
    }

    /**
     * Batch transform products.
     *
     * @param array $products Array of WC_Product objects.
     * @return array Transformed products.
     */
    public function transform_batch( array $products ): array {
        return array_map( [ $this, 'transform' ], $products );
    }

    /**
     * Get product image URL.
     *
     * @param \WC_Product $product WooCommerce product object.
     * @return string Image URL or empty string.
     */
    private function get_image_url( \WC_Product $product ): string {
        $image_id = $product->get_image_id();

        if ( $image_id ) {
            $url = \wp_get_attachment_image_url( $image_id, 'woocommerce_thumbnail' );
            return $url ?: '';
        }

        return \wc_placeholder_img_src( 'woocommerce_thumbnail' );
    }

    /**
     * Get product categories.
     *
     * @param \WC_Product $product WooCommerce product object.
     * @return array Category names.
     */
    private function get_categories( \WC_Product $product ): array {
        $terms = \get_the_terms( $product->get_id(), 'product_cat' );

        if ( \is_wp_error( $terms ) || empty( $terms ) ) {
            return [];
        }

        return \wp_list_pluck( $terms, 'name' );
    }
}
