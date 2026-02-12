<?php
/**
 * Fired when the plugin is uninstalled.
 *
 * Removes ALL plugin data from the database:
 * - Custom database tables (gcl_*)
 * - WordPress options (gcl_*)
 * - Transients (gcl_*)
 * - Custom roles and capabilities
 * - Cron jobs
 *
 * @package GspltdChatLite
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
    $wpdb->prefix . 'gcl_conversations',
    $wpdb->prefix . 'gcl_messages',
    $wpdb->prefix . 'gcl_feedback',
    $wpdb->prefix . 'gcl_product_index',
];

foreach ( $tables as $table ) {
    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from $wpdb->prefix
    $wpdb->query( "DROP TABLE IF EXISTS {$table}" );
}

// =========================================================================
// 2. DELETE ALL OPTIONS WITH gcl_ PREFIX
// =========================================================================
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query(
    "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'gcl_%'"
);

// =========================================================================
// 3. DELETE ALL TRANSIENTS WITH gcl_ PREFIX
// =========================================================================
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query(
    "DELETE FROM {$wpdb->options}
     WHERE option_name LIKE '_transient_gcl_%'
     OR option_name LIKE '_transient_timeout_gcl_%'"
);

// =========================================================================
// 4. REMOVE CUSTOM ROLES AND CAPABILITIES
// =========================================================================
$capabilities = [
    'manage_gcl_chat',
    'view_gcl_analytics',
];

// Remove custom role
if ( get_role( 'gcl_chat_operator' ) ) {
    remove_role( 'gcl_chat_operator' );
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
    'gcl_cleanup_conversations',
    'gcl_index_products',
];

foreach ( $cron_hooks as $hook ) {
    $timestamp = wp_next_scheduled( $hook );
    if ( $timestamp ) {
        wp_unschedule_event( $timestamp, $hook );
    }
}
