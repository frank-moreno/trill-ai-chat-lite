<?php
/**
 * Plugin Name: Trill AI Chat — Lite
 * Plugin URI: https://trillai.io
 * Description: AI-powered customer service chat for WooCommerce stores. Let AI answer product questions, recommend items, and boost conversions — automatically. Free for up to 50 conversations/month.
 * Version: 1.0.0
 * Requires at least: 6.0
 * Requires PHP: 8.0
 * Author: Trill AI
 * Author URI: https://trillai.io
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: trill-chat-lite
 * Domain Path: /languages
 * WC requires at least: 8.0
 * WC tested up to: 9.5
 *
 * @package TrillChatLite
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// =========================================================================
// CONSTANTS
// =========================================================================
define( 'TRILL_CHAT_LITE_VERSION', '1.0.0' );
define( 'TRILL_CHAT_LITE_PLUGIN_FILE', __FILE__ );
define( 'TRILL_CHAT_LITE_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'TRILL_CHAT_LITE_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'TRILL_CHAT_LITE_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

// Lite-specific constants
define( 'TRILL_CHAT_LITE_MONTHLY_CONVERSATION_LIMIT', 50 );
define( 'TRILL_CHAT_LITE_AI_MODEL', 'gpt-4o-mini' );
define( 'TRILL_CHAT_LITE_PROXY_URL', 'https://api.trillai.io/v1/lite/chat' );
define( 'TRILL_CHAT_LITE_UPGRADE_URL', 'https://trillai.io/pricing/?utm_source=plugin&utm_medium=lite&utm_campaign=upgrade' );
define( 'TRILL_CHAT_LITE_IS_LITE', true );

// =========================================================================
// PHP VERSION CHECK
// =========================================================================
if ( version_compare( PHP_VERSION, '8.0', '<' ) ) {
    add_action( 'admin_notices', function () {
        printf(
            '<div class="error"><p>%s</p></div>',
            esc_html__( 'Trill Chat Lite requires PHP 8.0 or higher.', 'trill-chat-lite' )
        );
    } );
    return;
}

// =========================================================================
// AUTOLOADER
// =========================================================================
if ( file_exists( TRILL_CHAT_LITE_PLUGIN_DIR . 'vendor/autoload.php' ) ) {
    require_once TRILL_CHAT_LITE_PLUGIN_DIR . 'vendor/autoload.php';
}

// =========================================================================
// GLOBAL HELPER FUNCTIONS (loaded before plugin boot)
// =========================================================================
require_once TRILL_CHAT_LITE_PLUGIN_DIR . 'includes/functions.php';

// =========================================================================
// ACTIVATION / DEACTIVATION HOOKS
// =========================================================================
register_activation_hook( __FILE__, [ 'TrillChatLite\\Activator', 'activate' ] );
register_deactivation_hook( __FILE__, [ 'TrillChatLite\\Deactivator', 'deactivate' ] );

// =========================================================================
// BOOT PLUGIN
// =========================================================================
add_action( 'plugins_loaded', function () {

    // =====================================================================
    // CONFLICT DETECTION: Deactivate if paid plugin is active
    // =====================================================================
    if ( defined( 'WCAI_VERSION' ) || defined( 'WCAI_TIER' ) ) {
        add_action( 'admin_notices', function () {
            printf(
                '<div class="notice notice-warning is-dismissible"><p>%s <a href="%s">%s</a></p></div>',
                esc_html__( 'Trill Chat Lite has been deactivated because the full version is installed.', 'trill-chat-lite' ),
                esc_url( admin_url( 'plugins.php' ) ),
                esc_html__( 'Manage plugins', 'trill-chat-lite' )
            );
        } );
        deactivate_plugins( TRILL_CHAT_LITE_PLUGIN_BASENAME );
        return;
    }

    // Also check by folder name
    if ( function_exists( 'is_plugin_active' ) ) {
        if ( is_plugin_active( 'woocommerce-ai-chat/gspltd-ai-chat.php' ) ||
             is_plugin_active( 'woocommerce-ai-chat/woocommerce-ai-chat.php' ) ) {
            deactivate_plugins( TRILL_CHAT_LITE_PLUGIN_BASENAME );
            return;
        }
    }

    // =====================================================================
    // WOOCOMMERCE CHECK
    // =====================================================================
    if ( ! class_exists( 'WooCommerce' ) ) {
        add_action( 'admin_notices', function () {
            printf(
                '<div class="error"><p>%s</p></div>',
                esc_html__( 'Trill Chat Lite requires WooCommerce to be installed and active.', 'trill-chat-lite' )
            );
        } );
        return;
    }

    // =====================================================================
    // BOOT
    // =====================================================================
    \TrillChatLite\Plugin::get_instance()->init();

}, 20 ); // Priority 20: load AFTER paid plugin would load
