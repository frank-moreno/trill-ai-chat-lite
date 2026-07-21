<?php
/**
 * GDPR Tools admin page orchestrator (v2.4 PRV-01).
 *
 * Thin coordinator for the Trill Chat → GDPR Tools screen: registers
 * the submenu, renders the view, and handles the data-subject lookup
 * form. It contains NO SQL and no erase/export logic of its own —
 * everything delegates to Gdpr\ConversationManager (lookup) and
 * Gdpr\AuditLogger (accountability trail), keeping the audited GDPR
 * core in one place (SRP / DIP).
 *
 * Lookup flow (PRG, no PII in URLs):
 *   1. The view posts the email to admin-post.php
 *      (action trcl_gdpr_lookup) with nonce + capability checks.
 *   2. The handler runs summarise_for_email(), writes ONE `search`
 *      audit entry, stashes the summary in a short-lived, per-user
 *      transient, and redirects back to the page.
 *   3. The view consumes the transient and renders the summary.
 *      Reloading the page does not repeat the search (and therefore
 *      does not duplicate audit rows).
 *
 * The three data-subject rights hang off the lookup result:
 *   - Right of Access (action trcl_gdpr_view): renders the full
 *     expediente on screen — audit action `view`.
 *   - Data Portability (action trcl_gdpr_export, format json|csv,
 *     decision D1): streams a download via Gdpr\SubjectExporter —
 *     audit action `export`.
 *   - Right to Erasure (action trcl_gdpr_erase): hard-deletes the
 *     subject's data after the admin types DELETE literally — audit
 *     action `erase`.
 *
 * @package TrillChatLite\Admin
 * @since 2.4.0
 * @license GPL-2.0-or-later
 */

namespace TrillChatLite\Admin;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use TrillChatLite\Gdpr\AuditLogger;
use TrillChatLite\Gdpr\ConversationManager;
use TrillChatLite\Gdpr\SubjectExporter;

/**
 * Class GdprToolsPage
 *
 * SOLID: Single Responsibility — only the GDPR Tools screen
 * (menu entry, render, form handlers).
 */
class GdprToolsPage {

    /**
     * Transient prefix for the per-user lookup result. The user ID is
     * appended so two admins searching at once never see each other's
     * result.
     */
    private const LOOKUP_TRANSIENT_PREFIX = 'trcl_gdpr_lookup_';

    /**
     * Transient prefix for the per-user notice (error feedback).
     */
    private const NOTICE_TRANSIENT_PREFIX = 'trcl_gdpr_notice_';

    /**
     * Transient prefix for the per-user on-screen expediente
     * (Right of Access). Same single-shot semantics as the lookup.
     */
    private const EXPEDIENTE_TRANSIENT_PREFIX = 'trcl_gdpr_expediente_';

    /**
     * Register all hooks for the page.
     *
     * admin_menu runs at priority 20: Admin registers its hooks
     * through the deferred Loader (Loader::run), so a default-priority
     * add_action here would land BEFORE Admin::add_admin_menu and
     * push GDPR Tools above Dashboard. Priority 20 guarantees the
     * main menu is built first and GDPR Tools is the last entry.
     */
    public function register_hooks(): void {
        \add_action( 'admin_menu', [ $this, 'add_menu' ], 20 );
        \add_action( 'admin_post_trcl_gdpr_lookup', [ $this, 'handle_lookup_post' ] );
        \add_action( 'admin_post_trcl_gdpr_view', [ $this, 'handle_view_post' ] );
        \add_action( 'admin_post_trcl_gdpr_export', [ $this, 'handle_export_post' ] );
        \add_action( 'admin_post_trcl_gdpr_erase', [ $this, 'handle_erase_post' ] );
    }

    /**
     * Add the GDPR Tools submenu under Trill Chat.
     */
    public function add_menu(): void {
        \add_submenu_page(
            'trcl-chat',
            __( 'GDPR Tools', 'trill-ai-chat-lite' ),
            __( 'GDPR Tools', 'trill-ai-chat-lite' ),
            'manage_trcl_chat',
            'trcl-gdpr-tools',
            [ $this, 'render' ]
        );
    }

    /**
     * Render the GDPR Tools page.
     */
    public function render(): void {
        if ( ! \current_user_can( 'manage_trcl_chat' ) ) {
            \wp_die( esc_html__( 'You do not have sufficient permissions.', 'trill-ai-chat-lite' ) );
        }

        include TRCL_PLUGIN_DIR . 'includes/Admin/views/gdpr-tools.php';
    }

    /**
     * admin-post handler: data-subject lookup by email.
     *
     * Security posture (OWASP):
     *   - POST only; nonce via check_admin_referer.
     *   - Capability gate (manage_trcl_chat), 403 otherwise.
     *   - Email sanitised with sanitize_email; invalid input never
     *     reaches the manager.
     *   - The email travels via a per-user transient, never in the
     *     redirect URL (no PII in access logs / referrers).
     *
     * Every VALID search — including one with zero results — writes
     * exactly one `search` audit entry (PRV-02 acceptance criterion).
     */
    public function handle_lookup_post(): void {
        \check_admin_referer( 'trcl_gdpr_lookup' );
        $this->require_capability();

        $email = $this->require_posted_email();

        $manager = new ConversationManager();
        $summary = $manager->summarise_for_email( $email );

        $records = $summary['conversations'] + $summary['messages'] + $summary['leads'];
        ( new AuditLogger() )->log( AuditLogger::ACTION_SEARCH, $email, $records );

        \set_transient( self::LOOKUP_TRANSIENT_PREFIX . \get_current_user_id(), $summary, 60 );

        $this->redirect_to_page();
    }

    /**
     * admin-post handler: Right of Access — render the subject's full
     * expediente on screen.
     *
     * Same PRG + single-shot transient pattern as the lookup: the
     * expediente (WP-Privacy-shaped items from export_for_email) is
     * stashed per-user together with a refreshed summary, so the page
     * re-renders summary + rights cards + expediente in one pass.
     * Audit action: `view`, records = items in the expediente.
     */
    public function handle_view_post(): void {
        \check_admin_referer( 'trcl_gdpr_view' );
        $this->require_capability();

        $email = $this->require_posted_email();

        $manager = new ConversationManager();
        $items   = $manager->export_for_email( $email )['data'];

        ( new AuditLogger() )->log( AuditLogger::ACTION_VIEW, $email, count( $items ) );

        $user_id = \get_current_user_id();
        \set_transient( self::LOOKUP_TRANSIENT_PREFIX . $user_id, $manager->summarise_for_email( $email ), 60 );
        \set_transient( self::EXPEDIENTE_TRANSIENT_PREFIX . $user_id, $items, 60 );

        $this->redirect_to_page();
    }

    /**
     * admin-post handler: Data Portability — stream the expediente as
     * a JSON or CSV download (decision D1).
     *
     * The audit entry is written BEFORE streaming starts: once headers
     * are out we cannot recover from a failed write, and an export
     * that died halfway must still be accountable.
     */
    public function handle_export_post(): void {
        \check_admin_referer( 'trcl_gdpr_export' );
        $this->require_capability();

        $email  = $this->require_posted_email();
        $format = isset( $_POST['trcl_gdpr_format'] )
            ? \sanitize_key( \wp_unslash( $_POST['trcl_gdpr_format'] ) )
            : '';

        if ( ! in_array( $format, [ 'json', 'csv' ], true ) ) {
            $this->fail_with_notice( __( 'Unknown export format.', 'trill-ai-chat-lite' ) );
        }

        $exporter = new SubjectExporter();

        ( new AuditLogger() )->log( AuditLogger::ACTION_EXPORT, $email, $exporter->count_items( $email ) );

        if ( $format === 'json' ) {
            $exporter->stream_json( $email );
        } else {
            $exporter->stream_csv( $email );
        }
        exit;
    }

    /**
     * admin-post handler: Right to Erasure.
     *
     * Double confirmation: nonce + the admin must type DELETE
     * (literally, case-sensitive) into the confirmation field.
     * Delegates to ConversationManager::erase_for_email() — the same
     * audited cascade the WP Privacy eraser uses. Audit action:
     * `erase`, records = items removed (conversations + leads).
     */
    public function handle_erase_post(): void {
        \check_admin_referer( 'trcl_gdpr_erase' );
        $this->require_capability();

        $email   = $this->require_posted_email();
        $confirm = isset( $_POST['trcl_gdpr_confirm'] )
            ? trim( \sanitize_text_field( \wp_unslash( $_POST['trcl_gdpr_confirm'] ) ) )
            : '';

        if ( $confirm !== 'DELETE' ) {
            $this->fail_with_notice(
                __( 'Erasure not confirmed — type DELETE (in capitals) to proceed.', 'trill-ai-chat-lite' )
            );
        }

        $result = ( new ConversationManager() )->erase_for_email( $email );
        $removed = (int) $result['items_removed'];

        ( new AuditLogger() )->log( AuditLogger::ACTION_ERASE, $email, $removed );

        \set_transient( self::NOTICE_TRANSIENT_PREFIX . \get_current_user_id(), [
            'type'    => 'success',
            'message' => sprintf(
                /* translators: %d: number of records (conversations + leads) erased */
                _n(
                    'Erasure complete — %d record removed.',
                    'Erasure complete — %d records removed.',
                    $removed,
                    'trill-ai-chat-lite'
                ),
                $removed
            ),
        ], 60 );

        $this->redirect_to_page();
    }

    /**
     * Consume (read + delete) the current user's lookup result.
     *
     * Called from the view. Single-shot by design: a page reload
     * after the PRG render shows the empty state again instead of
     * re-running or re-displaying a stale search.
     *
     * @return array|null Summary array from summarise_for_email(),
     *                    or null when there is nothing pending.
     */
    public function consume_lookup_result(): ?array {
        $key    = self::LOOKUP_TRANSIENT_PREFIX . \get_current_user_id();
        $result = \get_transient( $key );

        if ( ! is_array( $result ) ) {
            return null;
        }

        \delete_transient( $key );
        return $result;
    }

    /**
     * Consume (read + delete) the current user's on-screen expediente
     * (Right of Access). Single-shot, like the lookup result.
     *
     * @return array|null Items array from export_for_email()['data'],
     *                    or null when there is nothing pending.
     */
    public function consume_expediente_result(): ?array {
        $key   = self::EXPEDIENTE_TRANSIENT_PREFIX . \get_current_user_id();
        $items = \get_transient( $key );

        if ( ! is_array( $items ) ) {
            return null;
        }

        \delete_transient( $key );
        return $items;
    }

    /**
     * Consume the current user's pending notice, if any.
     *
     * @return array|null ['type' => ..., 'message' => ...] or null.
     */
    public function consume_notice(): ?array {
        $key    = self::NOTICE_TRANSIENT_PREFIX . \get_current_user_id();
        $notice = \get_transient( $key );

        if ( ! is_array( $notice ) ) {
            return null;
        }

        \delete_transient( $key );
        return $notice;
    }

    /**
     * Capability gate shared by every handler. Dies with 403 when the
     * user lacks manage_trcl_chat. check_admin_referer runs first at
     * each call site (admin-post.php verifies neither for us).
     */
    private function require_capability(): void {
        if ( ! \current_user_can( 'manage_trcl_chat' ) ) {
            \wp_die(
                esc_html__( 'You do not have sufficient permissions.', 'trill-ai-chat-lite' ),
                '',
                [ 'response' => 403 ]
            );
        }
    }

    /**
     * Sanitised email from the POST body, shared by all four handlers.
     *
     * Invalid or missing → error notice + redirect (never returns).
     * The email travels in the POST body only — it must never appear
     * in a URL.
     *
     * @return string Valid, sanitised email.
     */
    private function require_posted_email(): string {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- check_admin_referer runs in each caller before this.
        $email = isset( $_POST['trcl_gdpr_email'] )
            ? \sanitize_email( \wp_unslash( $_POST['trcl_gdpr_email'] ) )
            : '';

        if ( $email === '' ) {
            $this->fail_with_notice( __( 'Please enter a valid email address.', 'trill-ai-chat-lite' ) );
        }

        return $email;
    }

    /**
     * Stash an error notice for the current user and redirect back to
     * the page. Never returns.
     *
     * @param string $message Translated error message.
     */
    private function fail_with_notice( string $message ): void {
        \set_transient( self::NOTICE_TRANSIENT_PREFIX . \get_current_user_id(), [
            'type'    => 'error',
            'message' => $message,
        ], 60 );

        $this->redirect_to_page();
    }

    /**
     * Redirect back to the GDPR Tools page (PRG).
     */
    private function redirect_to_page(): void {
        \wp_safe_redirect(
            \add_query_arg(
                [ 'page' => 'trcl-gdpr-tools' ],
                \admin_url( 'admin.php' )
            )
        );
        exit;
    }
}
