<?php
/**
 * GDPR settings facade — read-only access to the three privacy-related
 * options registered in Settings.php.
 *
 * Stored options:
 *   - trcl_retention_days       int    Default 365. Conversations older
 *                                       than this are hard-deleted by
 *                                       the daily cleanup cron.
 *   - trcl_privacy_notice_url   string The merchant's privacy policy URL.
 *                                       When set, the chat widget renders
 *                                       a discreet "By chatting, you
 *                                       accept our Privacy Policy" link
 *                                       in its footer.
 *   - trcl_privacy_notice_text  string Optional override for the notice
 *                                       text shown next to that link.
 *
 * Defaults are biased towards "do the right thing on a fresh install":
 * a 365-day retention is generous enough not to surprise merchants who
 * never look at the setting, while still bounded for GDPR purposes.
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
 * Class GdprSettings
 *
 * SOLID: Single Responsibility — only settings access for GDPR features.
 */
class GdprSettings {

    /**
     * Option key for the retention period (integer, days).
     */
    public const OPT_RETENTION_DAYS = 'trcl_retention_days';

    /**
     * Option key for the privacy-policy URL.
     */
    public const OPT_PRIVACY_NOTICE_URL = 'trcl_privacy_notice_url';

    /**
     * Option key for the optional notice text override.
     */
    public const OPT_PRIVACY_NOTICE_TEXT = 'trcl_privacy_notice_text';

    /**
     * Hard floor on retention. Anything below this would risk losing
     * the very recent chats the merchant uses to coach the AI / debug
     * issues. A merchant who genuinely needs zero retention should
     * disable the chat instead.
     */
    public const RETENTION_MIN_DAYS = 7;

    /**
     * Hard ceiling on retention. Anything above this is effectively
     * "keep forever" — we cap to a sane upper bound so a typo
     * (e.g. "9999") doesn't quietly disable cleanup. ~10 years is
     * deliberately well above any normal merchant horizon.
     */
    public const RETENTION_MAX_DAYS = 3650;

    /**
     * Default retention period for fresh installs.
     */
    public const RETENTION_DEFAULT_DAYS = 365;

    /**
     * Default notice text rendered next to the policy link.
     */
    public const NOTICE_DEFAULT_TEXT = 'By chatting, you accept our';

    /**
     * Get the configured retention period (days), clamped to safe bounds.
     *
     * @return int
     */
    public function get_retention_days(): int {
        $raw = (int) \get_option( self::OPT_RETENTION_DAYS, self::RETENTION_DEFAULT_DAYS );
        if ( $raw < self::RETENTION_MIN_DAYS ) {
            return self::RETENTION_MIN_DAYS;
        }
        if ( $raw > self::RETENTION_MAX_DAYS ) {
            return self::RETENTION_MAX_DAYS;
        }
        return $raw;
    }

    /**
     * Configured privacy policy URL, or '' if unset.
     *
     * @return string
     */
    public function get_privacy_notice_url(): string {
        return (string) \get_option( self::OPT_PRIVACY_NOTICE_URL, '' );
    }

    /**
     * Notice text. Falls back to the default if unset or empty.
     *
     * @return string
     */
    public function get_privacy_notice_text(): string {
        $stored = (string) \get_option( self::OPT_PRIVACY_NOTICE_TEXT, '' );
        $stored = trim( $stored );
        return $stored !== '' ? $stored : self::NOTICE_DEFAULT_TEXT;
    }

    /**
     * Does the widget have everything it needs to render the notice?
     *
     * Returns true only when a privacy URL is configured. Without a URL
     * the widget has nowhere to link to and we skip the notice entirely
     * rather than render a dangling "Privacy Policy" with no target.
     *
     * @return bool
     */
    public function should_render_widget_notice(): bool {
        return $this->get_privacy_notice_url() !== '';
    }

    /**
     * Versioned localStorage key for the widget consent gate
     * (v2.4 PRV-01 D11, decision D2).
     *
     * The key embeds a short hash of the notice URL + text, so if the
     * merchant changes either, every visitor's stored consent becomes
     * stale and the gate re-appears — re-consent is forced without any
     * server-side state (no PII is created just by showing the gate).
     *
     * '' when the notice is not configured (no gate to render).
     *
     * @since 2.4.0
     *
     * @return string
     */
    public function get_consent_key(): string {
        if ( ! $this->should_render_widget_notice() ) {
            return '';
        }

        return 'trcl_consent_' . substr(
            md5( $this->get_privacy_notice_url() . '|' . $this->get_privacy_notice_text() ),
            0,
            8
        );
    }
}
