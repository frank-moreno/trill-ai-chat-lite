<?php
/**
 * Prompt Builder for AI system prompt construction.
 *
 * Builds the system prompt with store context, product context,
 * and conversation guidelines for the AI assistant.
 *
 * @package GspltdChatLite\AI
 * @since 1.0.0
 * @license GPL-2.0-or-later
 */

namespace GspltdChatLite\AI;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Prompt Builder — constructs AI system prompts.
 *
 * SOLID: Single Responsibility — only prompt construction.
 */
class PromptBuilder {

    /**
     * Store context data.
     *
     * @var array
     */
    private array $store_context = [];

    /**
     * Product context data.
     *
     * @var array
     */
    private array $product_context = [];

    /**
     * Conversation history.
     *
     * @var array
     */
    private array $history = [];

    /**
     * Custom system prompt from settings.
     *
     * @var string
     */
    private string $custom_prompt = '';

    /**
     * Set store context.
     *
     * @param array $context Store context data.
     * @return self
     */
    public function with_store_context( array $context ): self {
        $this->store_context = $context;
        return $this;
    }

    /**
     * Set product context.
     *
     * @param array $products Product data array.
     * @return self
     */
    public function with_product_context( array $products ): self {
        $this->product_context = $products;
        return $this;
    }

    /**
     * Set conversation history.
     *
     * @param array $history Message history.
     * @return self
     */
    public function with_history( array $history ): self {
        $this->history = $history;
        return $this;
    }

    /**
     * Set custom system prompt.
     *
     * @param string $prompt Custom prompt text.
     * @return self
     */
    public function with_custom_prompt( string $prompt ): self {
        $this->custom_prompt = $prompt;
        return $this;
    }

    /**
     * Build the complete system prompt.
     *
     * @return string Constructed system prompt.
     */
    public function build(): string {
        $parts = [];

        // Base persona.
        $parts[] = $this->build_persona();

        // Store context.
        if ( ! empty( $this->store_context ) ) {
            $parts[] = $this->build_store_section();
        }

        // Product context.
        if ( ! empty( $this->product_context ) ) {
            $parts[] = $this->build_product_section();
        }

        // Guidelines.
        $parts[] = $this->build_guidelines();

        // Custom prompt override.
        if ( ! empty( $this->custom_prompt ) ) {
            $parts[] = "\nAdditional instructions:\n" . $this->custom_prompt;
        }

        return implode( "\n\n", array_filter( $parts ) );
    }

    /**
     * Build persona section.
     *
     * @return string Persona prompt.
     */
    private function build_persona(): string {
        $store_name = $this->store_context['store_name'] ?? \get_bloginfo( 'name' );

        return sprintf(
            "You are Robin, a friendly and knowledgeable AI shopping assistant for %s. " .
            "Your role is to help customers find products, answer questions about the store, " .
            "and provide excellent customer service. Be helpful, concise, and always try to " .
            "guide customers towards making a purchase when relevant.",
            $store_name
        );
    }

    /**
     * Build store context section.
     *
     * @return string Store context prompt.
     */
    private function build_store_section(): string {
        $lines = [ 'STORE INFORMATION:' ];

        if ( ! empty( $this->store_context['store_name'] ) ) {
            $lines[] = sprintf( '- Store: %s', $this->store_context['store_name'] );
        }

        if ( ! empty( $this->store_context['store_description'] ) ) {
            $lines[] = sprintf( '- Description: %s', $this->store_context['store_description'] );
        }

        if ( ! empty( $this->store_context['currency_symbol'] ) ) {
            $lines[] = sprintf( '- Currency: %s', $this->store_context['currency_symbol'] );
        }

        if ( ! empty( $this->store_context['total_products'] ) ) {
            $lines[] = sprintf( '- Total products: %d', $this->store_context['total_products'] );
        }

        if ( ! empty( $this->store_context['top_categories'] ) && is_array( $this->store_context['top_categories'] ) ) {
            $lines[] = sprintf( '- Categories: %s', implode( ', ', $this->store_context['top_categories'] ) );
        }

        return implode( "\n", $lines );
    }

    /**
     * Build product context section.
     *
     * @return string Product context prompt.
     */
    private function build_product_section(): string {
        if ( empty( $this->product_context ) ) {
            return '';
        }

        $lines = [ 'RELEVANT PRODUCTS FOUND:' ];

        foreach ( $this->product_context as $product ) {
            $name  = $product['name'] ?? 'Unknown';
            $price = $product['price'] ?? 'N/A';
            $stock = ! empty( $product['in_stock'] ) ? '[In Stock]' : '[Out of Stock]';
            $url   = $product['url'] ?? '';

            $lines[] = sprintf( '- %s: %s %s %s', $name, $price, $stock, $url );
        }

        $lines[] = '';
        $lines[] = 'Use these products when answering product-related questions.';
        $lines[] = 'Always mention current prices and availability.';

        return implode( "\n", $lines );
    }

    /**
     * Build response guidelines section.
     *
     * @return string Guidelines prompt.
     */
    private function build_guidelines(): string {
        return implode( "\n", [
            'RESPONSE GUIDELINES:',
            '- Keep responses concise (2-3 sentences unless detail is requested)',
            '- Always be helpful and friendly',
            '- If you mention a product, include its price and a link when available',
            '- If you cannot find what the customer is looking for, suggest alternatives',
            '- Do not discuss competitors or external websites',
            '- Do not provide medical, legal, or financial advice',
            '- If a question is outside your scope, politely redirect to store support',
            '- Format product names in bold when mentioning them',
            '- Use the store currency for all prices',
        ] );
    }

    /**
     * Build a context array for the proxy request.
     *
     * @return array Context data for the API.
     */
    public function build_context(): array {
        $context = [];

        if ( ! empty( $this->store_context ) ) {
            $context['store'] = $this->store_context;
        }

        if ( ! empty( $this->product_context ) ) {
            $context['products'] = $this->product_context;
        }

        if ( ! empty( $this->history ) ) {
            $context['history'] = array_map( function ( $msg ) {
                return [
                    'role'    => $msg->role ?? $msg['role'] ?? 'user',
                    'content' => $msg->content ?? $msg['content'] ?? '',
                ];
            }, $this->history );
        }

        return $context;
    }
}
