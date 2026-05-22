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

    public const PROXY_BASE_URL  = 'https://api.trillai.io';
    public const PROXY_CHAT_PATH = '/v1/lite/chat';
    public const SUPPORT_URL     = 'https://trillai.io/support/';
    public const DOCS_URL        = 'https://trillai.io/documentation/';

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
}
