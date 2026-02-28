<?php
/**
 * Fired when the plugin is uninstalled.
 *
 * Removes ALL plugin data from the database:
 * - Custom database tables (tcl_*)
 * - WordPress options (trcl_*)
 * - Transients (trcl_*)
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
$trcl_tables = [
    $wpdb->prefix . 'trcl_conversations',
    $wpdb->prefix . 'trcl_messages',
    $wpdb->prefix . 'trcl_feedback',
    $wpdb->prefix . 'trcl_product_index',
];

foreach ( $trcl_tables as $trcl_table ) {
    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Uninstall cleanup, table name from $wpdb->prefix.
    $wpdb->query( "DROP TABLE IF EXISTS {$trcl_table}" );
}

// =========================================================================
// 2. DELETE ALL OPTIONS WITH trcl_ PREFIX
// =========================================================================
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Uninstall cleanup.
$wpdb->query(
    "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'trcl_%'"
);

// =========================================================================
// 3. DELETE ALL TRANSIENTS WITH trcl_ PREFIX
// =========================================================================
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Uninstall cleanup.
$wpdb->query(
    "DELETE FROM {$wpdb->options}
     WHERE option_name LIKE '_transient_trcl_%'
     OR option_name LIKE '_transient_timeout_trcl_%'"
);

// =========================================================================
// 4. REMOVE CUSTOM ROLES AND CAPABILITIES
// =========================================================================
$trcl_capabilities = [
    'manage_trcl_chat',
    'view_trcl_analytics',
];

// Remove custom role.
if ( get_role( 'trcl_chat_operator' ) ) {
    remove_role( 'trcl_chat_operator' );
}

// Remove capabilities from standard roles.
$trcl_roles = [ 'administrator', 'shop_manager' ];
foreach ( $trcl_roles as $trcl_role_name ) {
    $trcl_role = get_role( $trcl_role_name );
    if ( $trcl_role ) {
        foreach ( $trcl_capabilities as $trcl_cap ) {
            $trcl_role->remove_cap( $trcl_cap );
        }
    }
}

// =========================================================================
// 5. CLEAR CRON JOBS
// =========================================================================
$trcl_cron_hooks = [
    'trcl_cleanup_conversations',
    'trcl_index_products',
];

foreach ( $trcl_cron_hooks as $trcl_hook ) {
    $trcl_timestamp = wp_next_scheduled( $trcl_hook );
    if ( $trcl_timestamp ) {
        wp_unschedule_event( $trcl_timestamp, $trcl_hook );
    }
}
