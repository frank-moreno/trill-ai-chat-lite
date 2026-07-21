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
// Delegated to Migrations::drop_tables() since 2.4.0 (decision D4).
// This file previously kept its own table list, which had drifted:
// it dropped a non-existent trcl_product_index and left trcl_leads,
// trcl_analytics_events and trcl_content_index behind — orphaning
// lead PII after uninstall. Migrations owns the schema, so it owns
// the teardown too. functions.php is required first because
// drop_tables() logs through trcl_log() (the plugin itself is NOT
// loaded during uninstall).
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/Database/Migrations.php';

\TrillChatLite\Database\Migrations::drop_tables();

// =========================================================================
// 2. DELETE ALL OPTIONS WITH trcl_ PREFIX
// =========================================================================
// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Uninstall cleanup on core options table; fixed literal pattern, no user input. Block-scoped for the multi-line statement.
$wpdb->query(
    "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'trcl_%'"
);
// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter

// =========================================================================
// 3. DELETE ALL TRANSIENTS WITH trcl_ PREFIX
// =========================================================================
// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Uninstall cleanup on core options table; fixed literal patterns, no user input. Block-scoped for the multi-line statement.
$wpdb->query(
    "DELETE FROM {$wpdb->options}
     WHERE option_name LIKE '_transient_trcl_%'
     OR option_name LIKE '_transient_timeout_trcl_%'"
);
// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter

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
