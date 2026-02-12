<?php
/**
 * Admin functionality
 *
 * Handles admin-side UI: menus, pages, asset enqueueing, AJAX.
 * Simplified for Lite: no licensing, no modes, no BYOK.
 *
 * @package GspltdChatLite\Admin
 * @since 1.0.0
 * @license GPL-2.0-or-later
 */

namespace GspltdChatLite\Admin;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use GspltdChatLite\Loader;

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

        gcl_log( 'Admin class initialised', 'debug' );
    }

    /**
     * Register all admin hooks.
     */
    public function register_hooks(): void {
        $this->loader->add_action( 'admin_menu', $this, 'add_admin_menu' );
        $this->loader->add_action( 'admin_enqueue_scripts', $this, 'enqueue_admin_assets' );
        $this->loader->add_action( 'admin_init', $this, 'register_settings' );

        // AJAX handlers.
        \add_action( 'wp_ajax_gcl_save_settings', [ $this, 'ajax_save_settings' ] );
        \add_action( 'wp_ajax_gcl_dismiss_notice', [ $this, 'ajax_dismiss_notice' ] );
    }

    /**
     * Add admin menu items.
     */
    public function add_admin_menu(): void {
        // Main menu page (Dashboard).
        \add_menu_page(
            __( 'GSPLTD Chat', 'gspltd-chat-lite' ),
            __( 'GSPLTD Chat', 'gspltd-chat-lite' ),
            'manage_gcl_chat',
            'gcl-chat',
            [ $this, 'render_dashboard' ],
            'dashicons-format-chat',
            58
        );

        // Dashboard submenu.
        \add_submenu_page(
            'gcl-chat',
            __( 'Dashboard', 'gspltd-chat-lite' ),
            __( 'Dashboard', 'gspltd-chat-lite' ),
            'manage_gcl_chat',
            'gcl-chat',
            [ $this, 'render_dashboard' ]
        );

        // Settings submenu.
        \add_submenu_page(
            'gcl-chat',
            __( 'Settings', 'gspltd-chat-lite' ),
            __( 'Settings', 'gspltd-chat-lite' ),
            'manage_gcl_chat',
            'gcl-settings',
            [ $this, 'render_settings' ]
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
        if ( strpos( $hook, 'gcl-chat' ) === false && strpos( $hook, 'gcl-settings' ) === false ) {
            return;
        }

        // Admin CSS.
        \wp_enqueue_style(
            'gcl-admin',
            GCL_PLUGIN_URL . 'assets/css/admin.css',
            [],
            $this->version
        );

        // Admin JavaScript.
        \wp_enqueue_script(
            'gcl-admin',
            GCL_PLUGIN_URL . 'assets/js/admin.js',
            [ 'jquery' ],
            $this->version,
            true
        );

        // Lite upsell assets.
        \wp_enqueue_style(
            'gcl-lite-upsell',
            GCL_PLUGIN_URL . 'assets/css/lite-upsell.css',
            [ 'gcl-admin' ],
            $this->version
        );

        \wp_enqueue_script(
            'gcl-lite-upsell',
            GCL_PLUGIN_URL . 'assets/js/lite-upsell.js',
            [ 'jquery' ],
            $this->version,
            true
        );

        // Localise script for AJAX.
        \wp_localize_script( 'gcl-admin', 'tclAdmin', [
            'ajaxurl' => \admin_url( 'admin-ajax.php' ),
            'nonce'   => \wp_create_nonce( 'gcl_admin_nonce' ),
            'strings' => [
                'saving' => __( 'Saving...', 'gspltd-chat-lite' ),
                'saved'  => __( 'Settings saved successfully!', 'gspltd-chat-lite' ),
                'error'  => __( 'An error occurred. Please try again.', 'gspltd-chat-lite' ),
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
        if ( ! \current_user_can( 'manage_gcl_chat' ) ) {
            \wp_die( esc_html__( 'You do not have sufficient permissions.', 'gspltd-chat-lite' ) );
        }

        include GCL_PLUGIN_DIR . 'includes/Admin/views/dashboard.php';
    }

    /**
     * Render Settings page.
     */
    public function render_settings(): void {
        if ( ! \current_user_can( 'manage_gcl_chat' ) ) {
            \wp_die( esc_html__( 'You do not have sufficient permissions.', 'gspltd-chat-lite' ) );
        }

        include GCL_PLUGIN_DIR . 'includes/Admin/views/settings.php';
    }

    // =========================================================================
    // AJAX HANDLERS
    // =========================================================================

    /**
     * AJAX: Save settings.
     */
    public function ajax_save_settings(): void {
        \check_ajax_referer( 'gcl_admin_nonce', 'nonce' );

        if ( ! \current_user_can( 'manage_gcl_chat' ) ) {
            \wp_send_json_error( [ 'message' => __( 'Insufficient permissions.', 'gspltd-chat-lite' ) ], 403 );
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified above
        $chat_enabled = isset( $_POST['chat_enabled'] ) ? sanitize_text_field( wp_unslash( $_POST['chat_enabled'] ) ) : '0';
        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $widget_position = isset( $_POST['widget_position'] ) ? sanitize_text_field( wp_unslash( $_POST['widget_position'] ) ) : 'bottom-right';
        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $widget_color = isset( $_POST['widget_color'] ) ? sanitize_hex_color( wp_unslash( $_POST['widget_color'] ) ) : '#10B981';

        \update_option( 'gcl_chat_enabled', $chat_enabled );
        \update_option( 'gcl_widget_position', $widget_position );
        \update_option( 'gcl_widget_color', $widget_color ?: '#10B981' );

        \wp_send_json_success( [ 'message' => __( 'Settings saved.', 'gspltd-chat-lite' ) ] );
    }

    /**
     * AJAX: Dismiss upgrade notice.
     */
    public function ajax_dismiss_notice(): void {
        \check_ajax_referer( 'gcl_dismiss_notice', 'nonce' );

        \update_option( 'gcl_upgrade_notice_dismissed', time() );

        \wp_send_json_success();
    }
}
