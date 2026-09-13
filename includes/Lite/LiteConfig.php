<?php
/**
 * Hardcoded configuration for the OSS Lite plugin.
 *
 * Single source of truth for all upstream URLs, paths, and option names.
 * No JSON configs, no feature flags, no tier system.
 *
 * @package TrillChatLite\Lite
 * @since 1.0.0
 * @license GPL-2.0-or-later
 */

namespace TrillChatLite\Lite;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Hardcoded Lite configuration.
 *
 * SOLID: Single Responsibility — only configuration data.
 */
class LiteConfig {

    // =========================================================================
    // Trill Cloud backend (api-v2.trillai.io)
    // =========================================================================

    /**
     * Production base URL of the Trill Cloud backend.
     *
     * Migrated to `api-v2.trillai.io` in plugin v2.0. The legacy
     * `api.trillai.io` proxy stays alive for plugins v1.x that don't
     * auto-update — see https://trillai.io for the migration policy.
     *
     * To point the plugin at a non-production backend (local dev, staging)
     * define `TRCL_PROXY_BASE_URL` in wp-config.php. Example:
     *
     *     define( 'TRCL_PROXY_BASE_URL', 'http://localhost:3001' );
     *
     * Use `LiteConfig::get_proxy_base_url()` everywhere — never reference
     * this constant directly.
     */
    public const PROXY_BASE_URL = 'https://api-v2.trillai.io';

    /**
     * Path of the trial registration endpoint.
     *
     * Called ONCE per site to obtain the bearer secret. Idempotent at
     * the backend (returns 409 if the canonical site_url is already
     * registered).
     */
    public const TRIAL_REGISTER_PATH = '/v1/trial/register';

    /**
     * Path of the secret rotation endpoint (2.3.0).
     *
     * Called when register returns 409 (reinstall recovery). The backend
     * verifies site ownership by fetching GET /wp-json/trcl/v1/verify on
     * this site before issuing a replacement secret.
     */
    public const TRIAL_ROTATE_PATH = '/v1/trial/rotate';

    /**
     * Path of the trial chat endpoint.
     *
     * Called per message. Requires Authorization: Bearer <trial secret>.
     * Returns { reply, provider } + X-Trill-Trial-Remaining header.
     */
    public const TRIAL_CHAT_PATH = '/v1/trial/chat';

    /**
     * Public health check.
     */
    public const HEALTH_PATH = '/health';

    /**
     * Brand URLs (unchanged from v1.x).
     */
    public const SUPPORT_URL = 'https://trillai.io/support/';
    public const DOCS_URL    = 'https://trillai.io/documentation/';
    public const PRICING_URL = 'https://trillai.io/pricing/';

    // =========================================================================
    // Trial UX limits
    // =========================================================================

    /**
     * Monthly conversation limit for display purposes only.
     *
     * Authoritative cap is enforced server-side via 429 trial_exhausted.
     * The plugin reads the per-response X-Trill-Trial-Remaining header
     * for real-time remaining count.
     */
    public const MONTHLY_LIMIT = 50;

    /**
     * Max number of historical conversation messages sent to the backend
     * on each chat request. Older turns are truncated.
     *
     * 20 turns covers ~10 user/assistant pairs, which is enough context
     * for product-support conversations without bloating the system
     * prompt + history payload past sensible OpenAI token budgets.
     */
    public const MAX_HISTORY_MESSAGES = 20;

    // =========================================================================
    // WordPress option names (centralised so we never collide / leak typos)
    // =========================================================================

    /**
     * wp_option holding the trial bearer secret in plaintext.
     *
     * Format: `tt_trial_<32 chars>`. Set ONCE on /v1/trial/register
     * success; never overwritten by the plugin (the backend rejects
     * re-registration with 409, so the existing value is the only one
     * that works).
     *
     * autoload=no — secrets shouldn't be loaded on every page.
     */
    public const OPT_TRIAL_SECRET = 'trcl_trial_secret';

    /**
     * wp_option flagging that the on-activate register call failed with
     * a TRANSIENT error and should be retried on the next admin page load.
     *
     * Value: '1' (set), absent (clear).
     */
    public const OPT_TRIAL_REGISTER_RETRY = 'trcl_trial_register_retry';

    /**
     * wp_option flagging that registration has hit a PERMANENT failure
     * (409 already_registered, 400 invalid_site_url). Retrying won't help
     * and would just hammer the backend on every admin page load.
     *
     * Set by TrialRegistration on permanent failures; cleared on success
     * or on manual reactivate (Activator clears both flags before
     * attempting to register fresh).
     */
    public const OPT_TRIAL_REGISTER_PERMA_FAIL = 'trcl_trial_register_perma_fail';

    /**
     * wp_option tracking the last plugin code version that ran the boot path.
     *
     * Used by the upgrade-detection hook (UpgradeManager) to identify
     * v1.x → 2.0 jumps and trigger one-time migration (clear legacy
     * options + show admin notice + auto-register trial).
     *
     * NOTE: This is the PLUGIN code version, NOT the DB schema version.
     * The DB schema version is owned independently by
     * `TrillChatLite\Database\Migrations::SCHEMA_VERSION` and stored in
     * the option key `trcl_db_version`. The two were originally aliased
     * onto the same key in the v2.0 wiring commit (5dd2bc7) which caused
     * UpgradeManager to overwrite Migrations' schema version with the
     * plugin code version, making Migrations::run() early-return on
     * subsequent activations. Fix: split into two distinct option keys
     * (this one, `trcl_plugin_version`, owned by UpgradeManager).
     *
     * @since 2.0.0
     */
    public const OPT_PLUGIN_VERSION = 'trcl_plugin_version';

    /**
     * wp_option flagging that the post-upgrade admin notice should be
     * shown on the next admin page load. Cleared on dismiss.
     */
    public const OPT_SHOW_UPGRADE_NOTICE = 'trcl_show_upgrade_notice';

    /**
     * wp_option storing the last seen value of X-Trill-Trial-Remaining
     * so the dashboard widget can display it without a round-trip.
     *
     * Refreshed on every successful chat. May lag if no chats happen
     * for a while (fine — informational only).
     */
    public const OPT_TRIAL_REMAINING = 'trcl_trial_remaining';

    /**
     * wp_option caching the plan-aware monthly cap reported by the
     * backend via X-Trill-Trial-Cap (2.3.1). Lets the dashboard show
     * the real allowance (50 trial / 2,000 cloud) instead of the
     * hardcoded MONTHLY_LIMIT. Falls back to MONTHLY_LIMIT when unset
     * (older backend or no chats yet).
     */
    public const OPT_TRIAL_CAP = 'trcl_trial_cap';

    // =========================================================================
    // Branding (unchanged from v1.x)
    // =========================================================================

    /**
     * Default "Powered by" branding values.
     * Display is controlled via wp_option (opt-in, OFF by default).
     */
    public const POWERED_BY_TEXT = 'Powered by Trill AI';
    public const POWERED_BY_URL  = 'https://trillai.io/?utm_source=widget&utm_medium=badge';

    /**
     * Effective backend base URL.
     *
     * Honours the `TRCL_PROXY_BASE_URL` constant from wp-config.php when
     * defined — useful for local dev or staging. Always falls back to the
     * production endpoint.
     *
     * @return string Without trailing slash.
     */
    public static function get_proxy_base_url(): string {
        if ( defined( 'TRCL_PROXY_BASE_URL' ) && is_string( \TRCL_PROXY_BASE_URL ) && \TRCL_PROXY_BASE_URL !== '' ) {
            return rtrim( (string) \TRCL_PROXY_BASE_URL, '/' );
        }
        return self::PROXY_BASE_URL;
    }

    /**
     * Full URL of the trial register endpoint.
     */
    public static function get_trial_register_url(): string {
        return self::get_proxy_base_url() . self::TRIAL_REGISTER_PATH;
    }

    /**
     * Full URL of the secret rotation endpoint.
     */
    public static function get_trial_rotate_url(): string {
        return self::get_proxy_base_url() . self::TRIAL_ROTATE_PATH;
    }

    /**
     * Full URL of the trial chat endpoint.
     */
    public static function get_trial_chat_url(): string {
        return self::get_proxy_base_url() . self::TRIAL_CHAT_PATH;
    }

    /**
     * Full URL of the health endpoint.
     */
    public static function get_health_url(): string {
        return self::get_proxy_base_url() . self::HEALTH_PATH;
    }

    /**
     * Whether to show the "Powered by Trill AI" badge.
     *
     * Reads from wp_option. OFF by default (opt-in required per WP.org guidelines).
     */
    public static function get_show_powered_by(): bool {
        return \get_option( 'trcl_show_powered_by', '0' ) === '1';
    }

    /**
     * Get the "Powered by" URL.
     */
    public static function get_powered_by_url(): string {
        return self::POWERED_BY_URL;
    }
}
