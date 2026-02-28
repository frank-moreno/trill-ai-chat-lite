<?php
/**
 * Hardcoded configuration for Lite tier.
 *
 * No JSON configs, no feature flags, no tier system.
 *
 * @package TrillChatLite\Lite
 * @since 1.0.0
 * @license GPL-2.0-or-later
 */

namespace TrillChatLite\Lite;

/**
 * Hardcoded Lite configuration.
 *
 * SOLID: Single Responsibility — only configuration data.
 */
class LiteConfig {

    public const AI_MODEL        = 'gpt-4o-mini';
    public const PROXY_BASE_URL  = 'https://api.trillai.io';
    public const PROXY_CHAT_PATH = '/v1/lite/chat';
    public const UPGRADE_URL     = 'https://trillai.io/pricing/';
    public const SUPPORT_URL     = 'https://trillai.io/support/';
    public const DOCS_URL        = 'https://trillai.io/docs/';

    /**
     * Default "Powered by" branding values.
     * Display is controlled via wp_option (opt-in, OFF by default).
     */
    public const POWERED_BY_TEXT = 'Powered by Trill AI';
    public const POWERED_BY_URL  = 'https://trillai.io/?utm_source=widget&utm_medium=badge';

    /**
     * Features included in Lite.
     */
    public const FEATURES = [
        'chat_widget'     => true,
        'product_search'  => true,
        'order_tracking'  => false,
        'analytics'       => false,
        'custom_branding' => false,
    ];

    /**
     * Check if a feature is enabled.
     *
     * @param string $feature Feature key.
     * @return bool
     */
    public static function hasFeature( string $feature ): bool {
        return self::FEATURES[ $feature ] ?? false;
    }

    /**
     * Whether to show the "Powered by Trill AI" badge.
     *
     * Reads from wp_option. OFF by default (opt-in required per WP.org guidelines).
     *
     * @return bool
     */
    public static function get_show_powered_by(): bool {
        return \get_option( 'trcl_show_powered_by', '0' ) === '1';
    }

    /**
     * Get the "Powered by" URL.
     *
     * @return string
     */
    public static function get_powered_by_url(): string {
        return self::POWERED_BY_URL;
    }

    /**
     * Get upgrade URL with UTM parameters.
     *
     * @param string $context UTM content value.
     * @return string
     */
    public static function getUpgradeUrl( string $context = 'generic' ): string {
        return \add_query_arg( [
            'utm_source'   => 'plugin',
            'utm_medium'   => 'lite',
            'utm_campaign' => 'upgrade',
            'utm_content'  => $context,
        ], self::UPGRADE_URL );
    }
}
