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

    public const MONTHLY_LIMIT   = 50;
    public const AI_MODEL        = 'gpt-4o-mini';
    public const PROXY_BASE_URL  = 'https://api.trillai.io';
    public const PROXY_CHAT_PATH = '/v1/lite/chat';
    public const UPGRADE_URL     = 'https://trillai.io/pricing/';
    public const SUPPORT_URL     = 'https://trillai.io/support/';
    public const DOCS_URL        = 'https://trillai.io/docs/';

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
     * Widget branding.
     */
    public const SHOW_POWERED_BY = true;
    public const POWERED_BY_TEXT = 'Powered by Trill AI';
    public const POWERED_BY_URL  = 'https://trillai.io/?utm_source=widget&utm_medium=badge';

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
