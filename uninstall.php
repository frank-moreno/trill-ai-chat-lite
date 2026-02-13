<?php
/**
 * Fired when the plugin is uninstalled.
 *
 * Removes ALL plugin data from the database:
 * - Custom database tables (tcl_*)
 * - WordPress options (tcl_*)
 * - Transients (tcl_*)
 * - Custom roles and capabilities
 * - Cron jobs
 *
 * @package TrillChatLite
 * @since 1.0.0
 * @license GPL-2.0-or-later
 */

// If uninstall not called from WordPress, exit.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

global $wpdb;

// =========================================================================
// 1. DROP CUSTOM TABLES
// =========================================================================
$tables = [
    $wpdb->prefix . 'tcl_conversations',
    $wpdb->prefix . 'tcl_messages',
    $wpdb->prefix . 'tcl_feedback',
    $wpdb->prefix . 'tcl_product_index',
];

foreach ( $tables as $table ) {
    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from $wpdb->prefix
    $wpdb->query( "DROP TABLE IF EXISTS {$table}" );
}

// =========================================================================
// 2. DELETE ALL OPTIONS WITH tcl_ PREFIX
// =========================================================================
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query(
    "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'tcl_%'"
);

// =========================================================================
// 3. DELETE ALL TRANSIENTS WITH tcl_ PREFIX
// =========================================================================
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query(
    "DELETE FROM {$wpdb->options}
     WHERE option_name LIKE '_transient_tcl_%'
     OR option_name LIKE '_transient_timeout_tcl_%'"
);

// =========================================================================
// 4. REMOVE CUSTOM ROLES AND CAPABILITIES
// =========================================================================
$capabilities = [
    'manage_tcl_chat',
    'view_tcl_analytics',
];

// Remove custom role
if ( get_role( 'tcl_chat_operator' ) ) {
    remove_role( 'tcl_chat_operator' );
}

// Remove capabilities from standard roles
$roles = [ 'administrator', 'shop_manager' ];
foreach ( $roles as $role_name ) {
    $role = get_role( $role_name );
    if ( $role ) {
        foreach ( $capabilities as $cap ) {
            $role->remove_cap( $cap );
        }
    }
}

// =========================================================================
// 5. CLEAR CRON JOBS
// =========================================================================
$cron_hooks = [
    'tcl_cleanup_conversations',
    'tcl_index_products',
];

foreach ( $cron_hooks as $hook ) {
    $timestamp = wp_next_scheduled( $hook );
    if ( $timestamp ) {
        wp_unschedule_event( $timestamp, $hook );
    }
}
