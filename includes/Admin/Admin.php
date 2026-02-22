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

        trill_chat_lite_log( 'Admin class initialised', 'debug' );
    }

    /**
     * Register all admin hooks.
     */
    public function register_hooks(): void {
        $this->loader->add_action( 'admin_menu', $this, 'add_admin_menu' );
        $this->loader->add_action( 'admin_enqueue_scripts', $this, 'enqueue_admin_assets' );
        $this->loader->add_action( 'admin_init', $this, 'register_settings' );

        // AJAX handlers.
        \add_action( 'wp_ajax_tclw_save_settings', [ $this, 'ajax_save_settings' ] );
        \add_action( 'wp_ajax_tclw_dismiss_notice', [ $this, 'ajax_dismiss_notice' ] );
        \add_action( 'wp_ajax_tclw_reindex_products', [ $this, 'ajax_reindex_products' ] );
    }

    /**
     * Add admin menu items.
     */
    public function add_admin_menu(): void {
        // Main menu page (Dashboard).
        \add_menu_page(
            __( 'Trill Chat', 'trill-chat-lite' ),
            __( 'Trill Chat', 'trill-chat-lite' ),
            'manage_tclw_chat',
            'tclw-chat',
            [ $this, 'render_dashboard' ],
            'dashicons-format-chat',
            58
        );

        // Dashboard submenu.
        \add_submenu_page(
            'tclw-chat',
            __( 'Dashboard', 'trill-chat-lite' ),
            __( 'Dashboard', 'trill-chat-lite' ),
            'manage_tclw_chat',
            'tclw-chat',
            [ $this, 'render_dashboard' ]
        );

        // Products submenu.
        \add_submenu_page(
            'tclw-chat',
            __( 'Products', 'trill-chat-lite' ),
            __( 'Products', 'trill-chat-lite' ),
            'manage_tclw_chat',
            'tclw-products',
            [ $this, 'render_products' ]
        );

        // Settings submenu.
        \add_submenu_page(
            'tclw-chat',
            __( 'Settings', 'trill-chat-lite' ),
            __( 'Settings', 'trill-chat-lite' ),
            'manage_tclw_chat',
            'tclw-settings',
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
        if ( strpos( $hook, 'tclw-chat' ) === false && strpos( $hook, 'tclw-settings' ) === false && strpos( $hook, 'tclw-products' ) === false ) {
            return;
        }

        // Admin CSS.
        \wp_enqueue_style(
            'tclw-admin',
            TRILL_CHAT_LITE_PLUGIN_URL . 'assets/css/admin.css',
            [],
            $this->version
        );

        // Admin JavaScript.
        \wp_enqueue_script(
            'tclw-admin',
            TRILL_CHAT_LITE_PLUGIN_URL . 'assets/js/admin.js',
            [ 'jquery' ],
            $this->version,
            true
        );

        // Lite upsell assets.
        \wp_enqueue_style(
            'tclw-lite-upsell',
            TRILL_CHAT_LITE_PLUGIN_URL . 'assets/css/lite-upsell.css',
            [ 'tclw-admin' ],
            $this->version
        );

        \wp_enqueue_script(
            'tclw-lite-upsell',
            TRILL_CHAT_LITE_PLUGIN_URL . 'assets/js/lite-upsell.js',
            [ 'jquery' ],
            $this->version,
            true
        );

        // Localise script for AJAX.
        \wp_localize_script( 'tclw-admin', 'tclAdmin', [
            'ajaxurl' => \admin_url( 'admin-ajax.php' ),
            'nonce'   => \wp_create_nonce( 'tclw_admin_nonce' ),
            'strings' => [
                'saving'          => __( 'Saving...', 'trill-chat-lite' ),
                'saved'           => __( 'Settings saved successfully!', 'trill-chat-lite' ),
                'error'           => __( 'An error occurred. Please try again.', 'trill-chat-lite' ),
                'indexing'        => __( 'Indexing...', 'trill-chat-lite' ),
                'please_wait'     => __( 'Please wait...', 'trill-chat-lite' ),
                'indexing_failed' => __( 'Indexing failed.', 'trill-chat-lite' ),
                'request_failed'  => __( 'Request failed. Please try again.', 'trill-chat-lite' ),
                'reindex_now'     => __( 'Reindex Products Now', 'trill-chat-lite' ),
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
        if ( ! \current_user_can( 'manage_tclw_chat' ) ) {
            \wp_die( esc_html__( 'You do not have sufficient permissions.', 'trill-chat-lite' ) );
        }

        include TRILL_CHAT_LITE_PLUGIN_DIR . 'includes/Admin/views/dashboard.php';
    }

    /**
     * Render Products page.
     */
    public function render_products(): void {
        if ( ! \current_user_can( 'manage_tclw_chat' ) ) {
            \wp_die( esc_html__( 'You do not have sufficient permissions.', 'trill-chat-lite' ) );
        }

        include TRILL_CHAT_LITE_PLUGIN_DIR . 'includes/Admin/views/products.php';
    }

    /**
     * Render Settings page.
     */
    public function render_settings(): void {
        if ( ! \current_user_can( 'manage_tclw_chat' ) ) {
            \wp_die( esc_html__( 'You do not have sufficient permissions.', 'trill-chat-lite' ) );
        }

        include TRILL_CHAT_LITE_PLUGIN_DIR . 'includes/Admin/views/settings.php';
    }

    // =========================================================================
    // AJAX HANDLERS
    // =========================================================================

    /**
     * AJAX: Save settings.
     */
    public function ajax_save_settings(): void {
        \check_ajax_referer( 'tclw_admin_nonce', 'nonce' );

        if ( ! \current_user_can( 'manage_tclw_chat' ) ) {
            \wp_send_json_error( [ 'message' => __( 'Insufficient permissions.', 'trill-chat-lite' ) ], 403 );
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified above
        $chat_enabled = isset( $_POST['chat_enabled'] ) ? sanitize_text_field( wp_unslash( $_POST['chat_enabled'] ) ) : '0';
        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $widget_position = isset( $_POST['widget_position'] ) ? sanitize_text_field( wp_unslash( $_POST['widget_position'] ) ) : 'bottom-right';
        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $widget_color = isset( $_POST['widget_color'] ) ? sanitize_hex_color( wp_unslash( $_POST['widget_color'] ) ) : '#10B981';

        \update_option( 'tclw_chat_enabled', $chat_enabled );
        \update_option( 'tclw_widget_position', $widget_position );
        \update_option( 'tclw_widget_color', $widget_color ?: '#10B981' );

        \wp_send_json_success( [ 'message' => __( 'Settings saved.', 'trill-chat-lite' ) ] );
    }

    /**
     * AJAX: Dismiss upgrade notice.
     */
    public function ajax_dismiss_notice(): void {
        \check_ajax_referer( 'tclw_dismiss_notice', 'nonce' );

        \update_option( 'tclw_upgrade_notice_dismissed', time() );

        \wp_send_json_success();
    }

    /**
     * AJAX: Reindex WooCommerce products.
     */
    public function ajax_reindex_products(): void {
        \check_ajax_referer( 'tclw_admin_nonce', 'nonce' );

        if ( ! \current_user_can( 'manage_tclw_chat' ) ) {
            \wp_send_json_error( [ 'message' => __( 'Insufficient permissions.', 'trill-chat-lite' ) ], 403 );
        }

        if ( ! function_exists( 'wc_get_products' ) ) {
            \wp_send_json_error( [ 'message' => __( 'WooCommerce is not active.', 'trill-chat-lite' ) ] );
        }

        $indexer = new \TrillChatLite\WooCommerce\ProductIndexer();
        $result  = $indexer->index_products();

        \wp_send_json_success( [
            'message'      => sprintf(
                /* translators: %d: number of products indexed */
                __( '%d products indexed successfully.', 'trill-chat-lite' ),
                $result['indexed']
            ),
            'indexed'      => $result['indexed'],
            'last_indexed' => \current_time( 'Y-m-d H:i:s' ),
        ] );
    }
}
