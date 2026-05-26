<?php
/**
 * WP Privacy API integration.
 *
 * Wires our exporter + eraser into WordPress's native Tools → Export
 * Personal Data and Tools → Erase Personal Data flows. Merchants
 * subject to GDPR / UK DP Act / CCPA can satisfy DSAR requests
 * entirely through the WP admin without needing a custom UI.
 *
 * The actual lookup + delete logic lives in ConversationManager; this
 * class is just the glue that registers the callbacks with the right
 * filter shape.
 *
 * @package TrillChatLite\Gdpr
 * @since 2.0.0
 * @license GPL-2.0-or-later
 */

namespace TrillChatLite\Gdpr;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class PrivacyHooks
 *
 * SOLID: Single Responsibility — only WP Privacy API wiring.
 */
class PrivacyHooks {

    /**
     * Identifier used as the array key in the WP Privacy registries.
     * WordPress also uses this as the slug for the export folder
     * inside the generated ZIP.
     */
    public const EXPORTER_ID = 'trill-ai-chat-lite';
    public const ERASER_ID   = 'trill-ai-chat-lite';

    /**
     * @var ConversationManager
     */
    private ConversationManager $conversations;

    public function __construct( ?ConversationManager $conversations = null ) {
        $this->conversations = $conversations ?? new ConversationManager();
    }

    /**
     * Register the two WP Privacy filters. Called once from Plugin
     * during init_components.
     */
    public function register_hooks(): void {
        \add_filter( 'wp_privacy_personal_data_exporters', [ $this, 'register_exporter' ] );
        \add_filter( 'wp_privacy_personal_data_erasers', [ $this, 'register_eraser' ] );
    }

    /**
     * Filter callback: append our exporter to WP's registry.
     *
     * @param array $exporters Existing exporters.
     * @return array
     */
    public function register_exporter( $exporters ): array {
        if ( ! is_array( $exporters ) ) {
            $exporters = [];
        }
        $exporters[ self::EXPORTER_ID ] = [
            'exporter_friendly_name' => __( 'Trill AI Chat conversations', 'trill-ai-chat-lite' ),
            'callback'               => [ $this, 'exporter_callback' ],
        ];
        return $exporters;
    }

    /**
     * Filter callback: append our eraser to WP's registry.
     *
     * @param array $erasers Existing erasers.
     * @return array
     */
    public function register_eraser( $erasers ): array {
        if ( ! is_array( $erasers ) ) {
            $erasers = [];
        }
        $erasers[ self::ERASER_ID ] = [
            'eraser_friendly_name' => __( 'Trill AI Chat conversations', 'trill-ai-chat-lite' ),
            'callback'             => [ $this, 'eraser_callback' ],
        ];
        return $erasers;
    }

    /**
     * Exporter callback. Delegates to ConversationManager.
     *
     * @param string $email_address Email being exported.
     * @param int    $page          Page index (WP paginates if >50 items).
     * @return array
     */
    public function exporter_callback( string $email_address, int $page = 1 ): array {
        return $this->conversations->export_for_email( $email_address, $page );
    }

    /**
     * Eraser callback. Delegates to ConversationManager (hard delete).
     *
     * @param string $email_address Email being erased.
     * @param int    $page          Page index (ignored — we erase everything in one pass).
     * @return array
     */
    public function eraser_callback( string $email_address, int $page = 1 ): array {
        unset( $page );
        return $this->conversations->erase_for_email( $email_address );
    }
}
