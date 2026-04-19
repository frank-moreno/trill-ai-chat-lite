<?php
/**
 * Plugin Name: AI Shopping Assistant for WooCommerce — Trill AI
 * Description: AI-powered customer service chat for WooCommerce stores. Let AI answer product questions, recommend items, and boost conversions — automatically.
 * Version: 1.2.2
 * Requires at least: 6.0
 * Requires PHP: 8.0
 * Author: Trill AI
 * Author URI: https://trillai.io
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: trill-ai-chat-lite
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
define( 'TRCL_VERSION', '1.2.2' );
define( 'TRCL_PLUGIN_FILE', __FILE__ );
define( 'TRCL_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'TRCL_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'TRCL_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

// Lite-specific constants.
define( 'TRCL_AI_MODEL', 'gpt-5.4-nano' );
define( 'TRCL_PROXY_URL', 'https://api.trillai.io/v1/lite/chat' );
define( 'TRCL_UPGRADE_URL', 'https://trillai.io/pricing/' );
define( 'TRCL_IS_LITE', true );

// =========================================================================
// WOOCOMMERCE HPOS COMPATIBILITY
// =========================================================================
add_action( 'before_woocommerce_init', function () {
    if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
            'custom_order_tables',
            __FILE__,
            true
        );
    }
} );

// =========================================================================
// PHP VERSION CHECK
// =========================================================================
if ( version_compare( PHP_VERSION, '8.0', '<' ) ) {
    add_action( 'admin_notices', function () {
        printf(
            '<div class="error"><p>%s</p></div>',
            esc_html__( 'AI Shopping Assistant requires PHP 8.0 or higher.', 'trill-ai-chat-lite' )
        );
    } );
    return;
}

// =========================================================================
// AUTOLOADER (custom PSR-4, no Composer needed)
// =========================================================================
require_once TRCL_PLUGIN_DIR . 'includes/Autoloader.php';

// =========================================================================
// GLOBAL HELPER FUNCTIONS (loaded before plugin boot)
// =========================================================================
require_once TRCL_PLUGIN_DIR . 'includes/functions.php';

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
                esc_html__( 'AI Shopping Assistant has been deactivated because the full version is installed.', 'trill-ai-chat-lite' ),
                esc_url( admin_url( 'plugins.php' ) ),
                esc_html__( 'Manage plugins', 'trill-ai-chat-lite' )
            );
        } );
        deactivate_plugins( TRCL_PLUGIN_BASENAME );
        return;
    }

    // Also check by folder name
    if ( function_exists( 'is_plugin_active' ) ) {
        if ( is_plugin_active( 'woocommerce-ai-chat/gspltd-ai-chat.php' ) ||
             is_plugin_active( 'woocommerce-ai-chat/woocommerce-ai-chat.php' ) ) {
            deactivate_plugins( TRCL_PLUGIN_BASENAME );
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
                esc_html__( 'AI Shopping Assistant requires WooCommerce to be installed and active.', 'trill-ai-chat-lite' )
            );
        } );
        return;
    }

    // =====================================================================
    // BOOT
    // =====================================================================
    \TrillChatLite\Plugin::get_instance()->init();

}, 20 ); // Priority 20: load AFTER paid plugin would load
