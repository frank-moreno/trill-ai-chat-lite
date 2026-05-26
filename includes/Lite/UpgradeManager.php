<?php
/**
 * One-time upgrade orchestration for the v1.x → 2.0 cutover.
 *
 * Runs on every plugins_loaded. On the first load after the plugin
 * version jumps to 2.0 (or higher) from a < 2.0 stored version, we:
 *
 *   1. Clean up legacy options that have no meaning in the new model
 *      (any leftover license-key state from earlier prototypes).
 *   2. Set a flag so the next admin page load shows a one-time notice
 *      explaining the migration.
 *   3. Trigger a trial registration so the plugin works immediately,
 *      without requiring the merchant to deactivate/reactivate.
 *   4. Store the new version in trcl_plugin_version so this branch
 *      only runs once.
 *
 * NOTE on option keys: the plugin CODE version tracked here is stored
 * in `trcl_plugin_version`. The DB SCHEMA version is independently
 * tracked by `TrillChatLite\Database\Migrations` in `trcl_db_version`.
 * The two were aliased onto the same key in an earlier draft which
 * caused UpgradeManager to overwrite the schema version; this is now
 * fixed by splitting the keys.
 *
 * "Hard cutover" by design — see the project decision doc for why we
 * chose this over a soft migration with cross-backend bridging.
 *
 * @package TrillChatLite\Lite
 * @since 2.0.0
 * @license GPL-2.0-or-later
 */

namespace TrillChatLite\Lite;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Detect and execute the v1.x → 2.0 migration; render the admin notice.
 */
class UpgradeManager {

    /**
     * Major version that triggers the cutover migration.
     */
    private const CUTOVER_MAJOR = 2;

    /**
     * Register WP hooks for the post-upgrade admin notice + AJAX dismiss.
     *
     * Note: the migration itself (maybe_run_migration) is NOT hooked here.
     * The Plugin bootstrap calls it directly inside its plugins_loaded
     * priority-20 callback, ensuring the migration runs on the same
     * request that loads the new plugin code (instead of waiting for the
     * next request, which would happen if we self-hooked plugins_loaded
     * from inside a plugins_loaded callback).
     */
    public function register_hooks(): void {
        \add_action( 'admin_notices', [ $this, 'maybe_render_notice' ] );
        \add_action( 'wp_ajax_trcl_dismiss_upgrade_notice', [ $this, 'handle_dismiss' ] );
    }

    /**
     * If a v1.x → 2.0 jump is detected, run the one-time migration.
     */
    public function maybe_run_migration(): void {
        $current_version = defined( 'TRCL_VERSION' ) ? TRCL_VERSION : '0.0.0';
        $stored_version  = (string) \get_option( LiteConfig::OPT_PLUGIN_VERSION, '' );

        // First-ever install (no stored version yet) — just record current.
        // Activator already handled migrations + trial register on activate.
        if ( $stored_version === '' ) {
            \update_option( LiteConfig::OPT_PLUGIN_VERSION, $current_version, false );
            return;
        }

        // Same or older code → nothing to do.
        if ( version_compare( $current_version, $stored_version, '<=' ) ) {
            return;
        }

        // We're moving forward. Did we cross the v2.0 boundary?
        $crossed_to_v2 =
            version_compare( $stored_version, self::CUTOVER_MAJOR . '.0.0', '<' ) &&
            version_compare( $current_version, self::CUTOVER_MAJOR . '.0.0', '>=' );

        if ( $crossed_to_v2 ) {
            trcl_log( 'Upgrade detected: v1.x → 2.0', 'info', [
                'from' => $stored_version,
                'to'   => $current_version,
            ] );
            $this->run_cutover_migration();
        }

        // Always advance the stored version after any forward upgrade.
        \update_option( LiteConfig::OPT_PLUGIN_VERSION, $current_version, false );
    }

    /**
     * Hard-cutover migration steps:
     *   - Wipe any orphaned legacy options.
     *   - Force a trial re-register (clear any stale secret, then register
     *     fresh against api-v2.trillai.io).
     *   - Set the flag to show the educational admin notice.
     */
    private function run_cutover_migration(): void {
        // Clear orphan v1.x options that have no role in the new model.
        // Defensive: this OSS plugin never shipped a license-key flow but
        // some early dev builds may have left options behind on test sites.
        $legacy_options = [
            'trcl_license_key',
            'trcl_license_status',
            'trcl_license_last_check',
            'trcl_license_expires_at',
            'trcl_engine_mode',
            'trcl_byok_api_key',
        ];
        foreach ( $legacy_options as $opt ) {
            \delete_option( $opt );
        }

        // Drop any pre-existing trial secret. The site URL might have
        // been registered under an old design that's no longer valid.
        // ensure_registered() will mint a fresh one.
        TrialSecretStore::clear_secret();

        // Try to register now. Failures set the retry flag and the
        // admin_init hook keeps retrying transparently.
        try {
            TrialRegistration::ensure_registered();
        } catch ( \Throwable $e ) {
            trcl_log( 'Cutover register failed', 'error', [
                'error' => $e->getMessage(),
            ] );
        }

        // Show the educational notice on the next admin page load.
        \update_option( LiteConfig::OPT_SHOW_UPGRADE_NOTICE, '1', false );
    }

    /**
     * Render the post-upgrade admin notice if the flag is set.
     */
    public function maybe_render_notice(): void {
        if ( \get_option( LiteConfig::OPT_SHOW_UPGRADE_NOTICE, '' ) !== '1' ) {
            return;
        }
        if ( ! \current_user_can( 'manage_options' ) ) {
            return;
        }

        $message    = esc_html__(
            'Trill AI Chat 2.0 is here — your free trial has been re-registered automatically against the new Trill Cloud backend. No action needed.',
            'trill-ai-chat-lite'
        );
        $docs_label = esc_html__( 'Read the release notes', 'trill-ai-chat-lite' );
        $docs_url   = esc_url( LiteConfig::DOCS_URL );

        // Dismiss requires a nonce + an AJAX call.
        $nonce = \wp_create_nonce( 'trcl_dismiss_upgrade_notice' );
        $ajax  = \esc_url( \admin_url( 'admin-ajax.php' ) );

        // We render the markup inline to keep the upgrade flow self-contained
        // (no view file required).
        printf(
            '<div class="notice notice-info is-dismissible" data-trcl-upgrade-notice="1">
                <p><strong>Trill AI Chat</strong> — %1$s <a href="%2$s" target="_blank" rel="noopener">%3$s</a></p>
            </div>
            <script>
            (function(){
                document.addEventListener("click", function(e){
                    var btn = e.target.closest(".notice[data-trcl-upgrade-notice] .notice-dismiss");
                    if (!btn) return;
                    var data = new FormData();
                    data.append("action", "trcl_dismiss_upgrade_notice");
                    data.append("_wpnonce", %4$s);
                    fetch(%5$s, { method: "POST", body: data, credentials: "same-origin" });
                });
            })();
            </script>',
            $message,
            $docs_url,
            $docs_label,
            \wp_json_encode( $nonce ),
            \wp_json_encode( $ajax )
        );
    }

    /**
     * AJAX: dismiss the post-upgrade notice for this site permanently.
     */
    public function handle_dismiss(): void {
        \check_ajax_referer( 'trcl_dismiss_upgrade_notice' );
        if ( ! \current_user_can( 'manage_options' ) ) {
            \wp_send_json_error( [ 'reason' => 'forbidden' ], 403 );
        }
        \delete_option( LiteConfig::OPT_SHOW_UPGRADE_NOTICE );
        \wp_send_json_success();
    }
}
