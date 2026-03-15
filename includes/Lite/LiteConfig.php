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
     * Monthly conversation limit for display purposes only.
     *
     * The actual limit is enforced server-side by the proxy (api.trillai.io).
     * This constant is used in the admin dashboard usage widget to give
     * store owners a visual reference of their remaining quota.
     */
    public const MONTHLY_LIMIT = 50;

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
     * UTM schema (per Upgrade Path Analysis v1.1):
     *   utm_source   = lite_plugin (fixed — identifies the origin product)
     *   utm_medium   = varies by CTA placement (admin_notice, widget, dashboard_cta, etc.)
     *   utm_campaign = upgrade (fixed)
     *
     * @param string $medium The CTA placement context, used as utm_medium value.
     * @return string Fully-qualified URL with UTM query parameters.
     */
    public static function getUpgradeUrl( string $medium = 'generic' ): string {
        return \add_query_arg( [
            'utm_source'   => 'lite_plugin',
            'utm_medium'   => sanitize_key( $medium ),
            'utm_campaign' => 'upgrade',
        ], self::UPGRADE_URL );
    }
}
