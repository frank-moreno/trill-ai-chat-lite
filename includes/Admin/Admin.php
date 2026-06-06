<?php
/**
 * Admin functionality
 *
 * Handles admin-side UI: menus, pages, asset enqueueing, AJAX.
 * Simplified for Lite: no licensing, no modes, no BYOK.
 *
 * @package TrillChatLite\Admin
 * @since 1.0.0
 * @license GPL-2.0-or-later
 */

namespace TrillChatLite\Admin;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use TrillChatLite\Loader;

/**
 * Admin class.
 *
 * Single Responsibility: Only handles admin UI registration and rendering.
 */
class Admin {

    /**
     * Loader instance.
     *
     * @var Loader
     */
    private Loader $loader;

    /**
     * Plugin version.
     *
     * @var string
     */
    private string $version;

    /**
     * Settings manager.
     *
     * @var Settings
     */
    private Settings $settings;

    /**
     * Constructor.
     *
     * @param Loader   $loader   Plugin loader.
     * @param string   $version  Plugin version.
     * @param Settings $settings Settings manager.
     */
    public function __construct( Loader $loader, string $version, Settings $settings ) {
        $this->loader   = $loader;
        $this->version  = $version;
        $this->settings = $settings;

        trcl_log( 'Admin class initialised', 'debug' );
    }

    /**
     * Register all admin hooks.
     */
    public function register_hooks(): void {
        $this->loader->add_action( 'admin_menu', $this, 'add_admin_menu' );
        $this->loader->add_action( 'admin_enqueue_scripts', $this, 'enqueue_admin_assets' );
        $this->loader->add_action( 'admin_init', $this, 'register_settings' );

        // AJAX handlers.
        \add_action( 'wp_ajax_trcl_save_settings', [ $this, 'ajax_save_settings' ] );
        \add_action( 'wp_ajax_trcl_reindex_products', [ $this, 'ajax_reindex_products' ] );

        // admin-post handlers (full page reload pattern, used for the
        // Content tab's "Reindex now" button — keeps the implementation
        // simple and shows progress via a post-redirect-get notice).
        \add_action( 'admin_post_trcl_reindex_content', [ $this, 'handle_reindex_content_post' ] );

        // Leads admin actions (Block 4): mark contacted, erase, export.
        \add_action( 'admin_post_trcl_lead_action', [ $this, 'handle_lead_action_post' ] );
        \add_action( 'admin_post_trcl_leads_export', [ $this, 'handle_leads_export_post' ] );
    }

    /**
     * Add admin menu items.
     */
    public function add_admin_menu(): void {
        // Main menu page (Dashboard).
        \add_menu_page(
            __( 'Trill Chat', 'trill-ai-chat-lite' ),
            __( 'Trill Chat', 'trill-ai-chat-lite' ),
            'manage_trcl_chat',
            'trcl-chat',
            [ $this, 'render_dashboard' ],
            'dashicons-format-chat',
            58
        );

        // Dashboard submenu.
        \add_submenu_page(
            'trcl-chat',
            __( 'Dashboard', 'trill-ai-chat-lite' ),
            __( 'Dashboard', 'trill-ai-chat-lite' ),
            'manage_trcl_chat',
            'trcl-chat',
            [ $this, 'render_dashboard' ]
        );

        // Products submenu.
        \add_submenu_page(
            'trcl-chat',
            __( 'Products', 'trill-ai-chat-lite' ),
            __( 'Products', 'trill-ai-chat-lite' ),
            'manage_trcl_chat',
            'trcl-products',
            [ $this, 'render_products' ]
        );

        // Leads submenu (v2.0 Block 4).
        \add_submenu_page(
            'trcl-chat',
            __( 'Leads', 'trill-ai-chat-lite' ),
            __( 'Leads', 'trill-ai-chat-lite' ),
            'manage_trcl_chat',
            'trcl-leads',
            [ $this, 'render_leads' ]
        );

        // Settings submenu.
        \add_submenu_page(
            'trcl-chat',
            __( 'Settings', 'trill-ai-chat-lite' ),
            __( 'Settings', 'trill-ai-chat-lite' ),
            'manage_trcl_chat',
            'trcl-settings',
            [ $this, 'render_settings' ]
        );

        // About submenu (v2.0 Block 6 — branding + help anchor).
        \add_submenu_page(
            'trcl-chat',
            __( 'About', 'trill-ai-chat-lite' ),
            __( 'About', 'trill-ai-chat-lite' ),
            'manage_trcl_chat',
            'trcl-about',
            [ $this, 'render_about' ]
        );
    }

    /**
     * Register settings with WordPress Settings API.
     */
    public function register_settings(): void {
        $this->settings->register_settings();
    }

    /**
     * Enqueue admin assets.
     *
     * @param string $hook Current admin page hook.
     */
    public function enqueue_admin_assets( string $hook ): void {
        // Only load on our plugin pages.
        if ( strpos( $hook, 'trcl-chat' ) === false && strpos( $hook, 'trcl-settings' ) === false && strpos( $hook, 'trcl-products' ) === false ) {
            return;
        }

        // Admin CSS.
        \wp_enqueue_style(
            'trcl-admin',
            TRCL_PLUGIN_URL . 'assets/css/admin.css',
            [],
            $this->version
        );

        // Colour pickers — only needed by the Settings → Appearance tab
        // (v2.1). wp-color-picker ships with core, so this adds no
        // external requests.
        $script_deps = [ 'jquery' ];
        if ( strpos( $hook, 'trcl-settings' ) !== false ) {
            \wp_enqueue_style( 'wp-color-picker' );
            $script_deps[] = 'wp-color-picker';
        }

        // Admin JavaScript.
        \wp_enqueue_script(
            'trcl-admin',
            TRCL_PLUGIN_URL . 'assets/js/admin.js',
            $script_deps,
            $this->version,
            true
        );

        // Localise script for AJAX.
        \wp_localize_script( 'trcl-admin', 'trclAdmin', [
            'ajaxurl' => \admin_url( 'admin-ajax.php' ),
            'nonce'   => \wp_create_nonce( 'trcl_admin_nonce' ),
            'strings' => [
                'saving'          => __( 'Saving...', 'trill-ai-chat-lite' ),
                'saved'           => __( 'Settings saved successfully!', 'trill-ai-chat-lite' ),
                'error'           => __( 'An error occurred. Please try again.', 'trill-ai-chat-lite' ),
                'indexing'        => __( 'Indexing...', 'trill-ai-chat-lite' ),
                'please_wait'     => __( 'Please wait...', 'trill-ai-chat-lite' ),
                'indexing_failed' => __( 'Indexing failed.', 'trill-ai-chat-lite' ),
                'request_failed'  => __( 'Request failed. Please try again.', 'trill-ai-chat-lite' ),
                'reindex_now'     => __( 'Reindex Products Now', 'trill-ai-chat-lite' ),
            ],
        ] );
    }

    // =========================================================================
    // RENDER METHODS
    // =========================================================================

    /**
     * Render Dashboard page.
     */
    public function render_dashboard(): void {
        if ( ! \current_user_can( 'manage_trcl_chat' ) ) {
            \wp_die( esc_html__( 'You do not have sufficient permissions.', 'trill-ai-chat-lite' ) );
        }

        include TRCL_PLUGIN_DIR . 'includes/Admin/views/dashboard.php';
    }

    /**
     * Render Products page.
     */
    public function render_products(): void {
        if ( ! \current_user_can( 'manage_trcl_chat' ) ) {
            \wp_die( esc_html__( 'You do not have sufficient permissions.', 'trill-ai-chat-lite' ) );
        }

        include TRCL_PLUGIN_DIR . 'includes/Admin/views/products.php';
    }

    /**
     * Render Leads page (v2.0 Block 4 slice 2).
     */
    public function render_leads(): void {
        if ( ! \current_user_can( 'manage_trcl_chat' ) ) {
            \wp_die( esc_html__( 'You do not have sufficient permissions.', 'trill-ai-chat-lite' ) );
        }

        include TRCL_PLUGIN_DIR . 'includes/Admin/views/leads.php';
    }

    /**
     * Render About page (v2.0 Block 6 — branding + help).
     */
    public function render_about(): void {
        if ( ! \current_user_can( 'manage_trcl_chat' ) ) {
            \wp_die( esc_html__( 'You do not have sufficient permissions.', 'trill-ai-chat-lite' ) );
        }

        include TRCL_PLUGIN_DIR . 'includes/Admin/views/about.php';
    }

    /**
     * Render Settings page.
     */
    public function render_settings(): void {
        if ( ! \current_user_can( 'manage_trcl_chat' ) ) {
            \wp_die( esc_html__( 'You do not have sufficient permissions.', 'trill-ai-chat-lite' ) );
        }

        include TRCL_PLUGIN_DIR . 'includes/Admin/views/settings.php';
    }

    // =========================================================================
    // AJAX HANDLERS
    // =========================================================================

    /**
     * AJAX: Save settings.
     */
    public function ajax_save_settings(): void {
        \check_ajax_referer( 'trcl_admin_nonce', 'nonce' );

        if ( ! \current_user_can( 'manage_trcl_chat' ) ) {
            \wp_send_json_error( [ 'message' => __( 'Insufficient permissions.', 'trill-ai-chat-lite' ) ], 403 );
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified above
        $chat_enabled = isset( $_POST['chat_enabled'] ) ? sanitize_text_field( wp_unslash( $_POST['chat_enabled'] ) ) : '0';
        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $widget_position = isset( $_POST['widget_position'] ) ? sanitize_text_field( wp_unslash( $_POST['widget_position'] ) ) : 'bottom-right';
        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $widget_color = isset( $_POST['widget_color'] ) ? sanitize_hex_color( wp_unslash( $_POST['widget_color'] ) ) : '#10B981';

        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $show_powered_by = isset( $_POST['show_powered_by'] ) ? sanitize_text_field( wp_unslash( $_POST['show_powered_by'] ) ) : '0';
        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $welcome_message = isset( $_POST['welcome_message'] ) ? sanitize_textarea_field( wp_unslash( $_POST['welcome_message'] ) ) : '';

        \update_option( 'trcl_chat_enabled', $chat_enabled );
        \update_option( 'trcl_widget_position', $widget_position );
        \update_option( 'trcl_widget_color', $widget_color ?: '#10B981' );
        \update_option( 'trcl_show_powered_by', $show_powered_by );
        \update_option( 'trcl_welcome_message', $welcome_message );

        \wp_send_json_success( [ 'message' => __( 'Settings saved.', 'trill-ai-chat-lite' ) ] );
    }

    /**
     * admin-post handler: rebuild the full content index.
     *
     * Triggered by the "Reindex now" button on the Settings → Content
     * tab. Uses the classic post-redirect-get pattern: verify nonce
     * and capability, run the reindex synchronously, stash a notice
     * in a short-lived transient, then redirect back to the tab so
     * the next render shows the result.
     *
     * Synchronous reindex is acceptable for the Lite tier because the
     * blacklist (products excluded) keeps the workload small — a few
     * dozen pages at most. If/when we expose larger CPTs we can
     * switch to a queued / chunked job runner.
     *
     * @since 2.0.0
     */
    public function handle_reindex_content_post(): void {
        // Nonce + capability — admin-post.php does NOT verify these for us.
        \check_admin_referer( 'trcl_reindex_content' );

        if ( ! \current_user_can( 'manage_trcl_chat' ) ) {
            \wp_die(
                esc_html__( 'You do not have sufficient permissions.', 'trill-ai-chat-lite' ),
                '',
                [ 'response' => 403 ]
            );
        }

        try {
            $settings = new \TrillChatLite\Content\ContentSettings();
            $indexer  = new \TrillChatLite\Content\ContentIndexer( $settings );
            $result   = $indexer->index_all_opted_in();

            \set_transient(
                'trcl_reindex_content_notice',
                [
                    'type'    => 'success',
                    'message' => sprintf(
                        /* translators: 1: posts indexed, 2: terms indexed, 3: total chunks */
                        __( 'Reindex complete — %1$d posts, %2$d categories, %3$d chunks in total.', 'trill-ai-chat-lite' ),
                        (int) $result['posts_indexed'],
                        (int) $result['terms_indexed'],
                        (int) $result['total_chunks']
                    ),
                ],
                60
            );
        } catch ( \Throwable $e ) {
            trcl_log( 'Manual content reindex failed', 'error', [
                'error' => $e->getMessage(),
            ] );
            \set_transient(
                'trcl_reindex_content_notice',
                [
                    'type'    => 'error',
                    'message' => sprintf(
                        /* translators: %s: error message */
                        __( 'Reindex failed: %s', 'trill-ai-chat-lite' ),
                        $e->getMessage()
                    ),
                ],
                60
            );
        }

        $redirect = \add_query_arg(
            [ 'page' => 'trcl-settings', 'tab' => 'content' ],
            \admin_url( 'admin.php' )
        );
        \wp_safe_redirect( $redirect );
        exit;
    }

    /**
     * admin-post handler: per-row lead action (mark contacted / erase).
     *
     * Receives:
     *   - lead_id    int
     *   - lead_op    'contacted' | 'erase'
     *
     * Nonce: 'trcl_lead_action_<lead_id>'. Redirects back to the Leads
     * page with a transient-backed notice.
     *
     * @since 2.0.0
     */
    public function handle_lead_action_post(): void {
        $lead_id = isset( $_POST['lead_id'] ) ? (int) $_POST['lead_id'] : 0;
        $op      = isset( $_POST['lead_op'] ) ? \sanitize_key( \wp_unslash( $_POST['lead_op'] ) ) : '';

        \check_admin_referer( 'trcl_lead_action_' . $lead_id );

        if ( ! \current_user_can( 'manage_trcl_chat' ) ) {
            \wp_die(
                esc_html__( 'You do not have sufficient permissions.', 'trill-ai-chat-lite' ),
                '',
                [ 'response' => 403 ]
            );
        }

        if ( $lead_id <= 0 || ! in_array( $op, [ 'contacted', 'erase' ], true ) ) {
            \set_transient( 'trcl_lead_action_notice', [
                'type'    => 'error',
                'message' => __( 'Invalid lead action.', 'trill-ai-chat-lite' ),
            ], 60 );
            $this->redirect_to_leads();
        }

        try {
            $svc = new \TrillChatLite\Leads\LeadCaptureService();

            if ( $op === 'contacted' ) {
                $ok = $svc->update_status( $lead_id, \TrillChatLite\Leads\LeadCaptureService::STATUS_CONTACTED );
                \set_transient( 'trcl_lead_action_notice', [
                    'type'    => $ok ? 'success' : 'error',
                    'message' => $ok
                        ? __( 'Lead marked as contacted.', 'trill-ai-chat-lite' )
                        : __( 'Could not update the lead.', 'trill-ai-chat-lite' ),
                ], 60 );
            } elseif ( $op === 'erase' ) {
                // Erase by id → look up the email, then cascade through
                // ConversationManager so the full GDPR posture stays in
                // one place (in case the email had multiple lead rows).
                global $wpdb;
                $email = (string) $wpdb->get_var(
                    $wpdb->prepare(
                        "SELECT email FROM {$wpdb->prefix}trcl_leads WHERE id = %d",
                        $lead_id
                    )
                );
                if ( $email !== '' ) {
                    $deleted = $svc->erase_by_email( $email );
                    \set_transient( 'trcl_lead_action_notice', [
                        'type'    => 'success',
                        'message' => sprintf(
                            /* translators: %d: lead rows removed */
                            __( 'Erased %d lead row(s) for that email.', 'trill-ai-chat-lite' ),
                            (int) $deleted
                        ),
                    ], 60 );
                }
            }
        } catch ( \Throwable $e ) {
            trcl_log( 'Lead action failed', 'error', [ 'error' => $e->getMessage() ] );
            \set_transient( 'trcl_lead_action_notice', [
                'type'    => 'error',
                'message' => sprintf(
                    /* translators: %s: error message */
                    __( 'Action failed: %s', 'trill-ai-chat-lite' ),
                    $e->getMessage()
                ),
            ], 60 );
        }

        $this->redirect_to_leads();
    }

    /**
     * admin-post handler: stream a CSV export of every lead.
     *
     * No row-level filtering yet — exports the full table. Sized for
     * the dev tier (up to a few thousand rows). For very large stores
     * a future slice can paginate / stream in chunks.
     *
     * @since 2.0.0
     */
    public function handle_leads_export_post(): void {
        \check_admin_referer( 'trcl_leads_export' );

        if ( ! \current_user_can( 'manage_trcl_chat' ) ) {
            \wp_die(
                esc_html__( 'You do not have sufficient permissions.', 'trill-ai-chat-lite' ),
                '',
                [ 'response' => 403 ]
            );
        }

        global $wpdb;
        $table = $wpdb->prefix . 'trcl_leads';

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $rows = $wpdb->get_results(
            "SELECT id, email, intent_type, product_id, status, captured_at, session_id
               FROM {$table}
              ORDER BY captured_at DESC",
            ARRAY_A
        );

        $filename = 'trill-leads-' . \gmdate( 'Y-m-d' ) . '.csv';

        // Stream CSV. No buffering games — exit at the end to stop
        // any trailing admin output from contaminating the file.
        //
        // PHPCS suggests WP_Filesystem for file operations, but that
        // applies to ON-DISK files; here we are writing to the HTTP
        // response stream (php://output). WP_Filesystem has no
        // equivalent of fputcsv on a stream, and using it would force
        // us to buffer the whole CSV into memory before the response —
        // unsafe for large lead tables.
        \nocache_headers();
        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="' . $filename . '"' );

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- streaming HTTP response, not file IO.
        $fh = fopen( 'php://output', 'w' );

        // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_fputcsv -- writing to HTTP response stream.
        fputcsv( $fh, [ 'id', 'email', 'intent', 'product_id', 'status', 'captured_at', 'session_id' ] );

        if ( is_array( $rows ) ) {
            foreach ( $rows as $row ) {
                // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_fputcsv -- writing to HTTP response stream.
                fputcsv( $fh, [
                    $row['id'] ?? '',
                    $row['email'] ?? '',
                    $row['intent_type'] ?? '',
                    $row['product_id'] ?? 0,
                    $row['status'] ?? '',
                    $row['captured_at'] ?? '',
                    $row['session_id'] ?? '',
                ] );
            }
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- closing HTTP response stream opened above.
        fclose( $fh );
        exit;
    }

    /**
     * Redirect to the Leads admin page (used by lead action handlers).
     */
    private function redirect_to_leads(): void {
        $redirect = \add_query_arg(
            [ 'page' => 'trcl-leads' ],
            \admin_url( 'admin.php' )
        );
        \wp_safe_redirect( $redirect );
        exit;
    }

    /**
     * AJAX: Reindex WooCommerce products.
     */
    public function ajax_reindex_products(): void {
        \check_ajax_referer( 'trcl_admin_nonce', 'nonce' );

        if ( ! \current_user_can( 'manage_trcl_chat' ) ) {
            \wp_send_json_error( [ 'message' => __( 'Insufficient permissions.', 'trill-ai-chat-lite' ) ], 403 );
        }

        if ( ! function_exists( 'wc_get_products' ) ) {
            \wp_send_json_error( [ 'message' => __( 'WooCommerce is not active.', 'trill-ai-chat-lite' ) ] );
        }

        $indexer = new \TrillChatLite\WooCommerce\ProductIndexer();
        $result  = $indexer->index_products();

        \wp_send_json_success( [
            'message'      => sprintf(
                /* translators: %d: number of products indexed */
                __( '%d products indexed successfully.', 'trill-ai-chat-lite' ),
                $result['indexed']
            ),
            'indexed'      => $result['indexed'],
            'last_indexed' => \current_time( 'Y-m-d H:i:s' ),
        ] );
    }
}
