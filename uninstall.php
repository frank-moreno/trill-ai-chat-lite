<?php
/**
 * Fired when the plugin is uninstalled.
 *
 * Removes ALL plugin data from the database:
 * - Custom database tables (tcl_*)
 * - WordPress options (tclw_*)
 * - Transients (tclw_*)
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
$trill_chat_lite_tables = [
    $wpdb->prefix . 'tcl_conversations',
    $wpdb->prefix . 'tcl_messages',
    $wpdb->prefix . 'tcl_feedback',
    $wpdb->prefix . 'tcl_product_index',
];

foreach ( $trill_chat_lite_tables as $trill_chat_lite_table ) {
    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Uninstall cleanup, table name from $wpdb->prefix.
    $wpdb->query( "DROP TABLE IF EXISTS {$trill_chat_lite_table}" );
}

// =========================================================================
// 2. DELETE ALL OPTIONS WITH tclw_ PREFIX
// =========================================================================
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Uninstall cleanup.
$wpdb->query(
    "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'tclw_%'"
);

// =========================================================================
// 3. DELETE ALL TRANSIENTS WITH tclw_ PREFIX
// =========================================================================
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Uninstall cleanup.
$wpdb->query(
    "DELETE FROM {$wpdb->options}
     WHERE option_name LIKE '_transient_tclw_%'
     OR option_name LIKE '_transient_timeout_tclw_%'"
);

// =========================================================================
// 4. REMOVE CUSTOM ROLES AND CAPABILITIES
// =========================================================================
$trill_chat_lite_capabilities = [
    'manage_tclw_chat',
    'view_tclw_analytics',
];

// Remove custom role.
if ( get_role( 'tclw_chat_operator' ) ) {
    remove_role( 'tclw_chat_operator' );
}

// Remove capabilities from standard roles.
$trill_chat_lite_roles = [ 'administrator', 'shop_manager' ];
foreach ( $trill_chat_lite_roles as $trill_chat_lite_role_name ) {
    $trill_chat_lite_role = get_role( $trill_chat_lite_role_name );
    if ( $trill_chat_lite_role ) {
        foreach ( $trill_chat_lite_capabilities as $trill_chat_lite_cap ) {
            $trill_chat_lite_role->remove_cap( $trill_chat_lite_cap );
        }
    }
}

// =========================================================================
// 5. CLEAR CRON JOBS
// =========================================================================
$trill_chat_lite_cron_hooks = [
    'tclw_cleanup_conversations',
    'tclw_index_products',
];

foreach ( $trill_chat_lite_cron_hooks as $trill_chat_lite_hook ) {
    $trill_chat_lite_timestamp = wp_next_scheduled( $trill_chat_lite_hook );
    if ( $trill_chat_lite_timestamp ) {
        wp_unschedule_event( $trill_chat_lite_timestamp, $trill_chat_lite_hook );
    }
}
