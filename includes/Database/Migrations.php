<?php
/**
 * Database Migrations.
 *
 * Creates and manages database schema for the Lite plugin.
 * Tables: trcl_conversations, trcl_messages, trcl_feedback,
 *         trcl_content_index, trcl_analytics_events.
 *
 * Schema history:
 *   1.0.0 — initial release (conversations, messages, feedback)
 *   1.1.0 — page content indexing (adds trcl_content_index, v2.0 Block 1)
 *   1.2.0 — analytics + cart attribution (adds trcl_analytics_events,
 *           v2.0 Block 3)
 *   1.3.0 — lead capture (adds trcl_leads, v2.0 Block 4)
 *   1.4.0 — conversations admin page (adds FULLTEXT index on
 *           trcl_messages.content for transcript search, v2.2 CNV-02)
 *   1.5.0 — GDPR audit log (adds trcl_gdpr_audit, v2.4 PRV-02)
 *
 * @package TrillChatLite\Database
 * @since 1.0.0
 * @license GPL-2.0-or-later
 */

namespace TrillChatLite\Database;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class Migrations
 *
 * SOLID: Single Responsibility — only database schema management.
 */
class Migrations {

    /**
     * Current schema version.
     */
    private const SCHEMA_VERSION = '1.5.0';

    /**
     * Option flag: whether the FULLTEXT index on trcl_messages.content
     * is available ('1') or the host refused it ('0', LIKE fallback).
     *
     * @since 2.2.0
     */
    public const OPT_MESSAGES_FULLTEXT = 'trcl_messages_fulltext';

    /**
     * Run all migrations.
     *
     * Called during plugin activation.
     */
    public static function run(): void {
        global $wpdb;

        $installed_version = \get_option( 'trcl_db_version', '0.0.0' );

        if ( version_compare( $installed_version, self::SCHEMA_VERSION, '>=' ) ) {
            return;
        }

        // Guard: ensure dbDelta() is available (may not be loaded in all contexts).
        if ( ! function_exists( 'dbDelta' ) ) {
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        }

        $charset_collate = $wpdb->get_charset_collate();

        self::create_conversations_table( $wpdb, $charset_collate );
        self::create_messages_table( $wpdb, $charset_collate );
        self::create_feedback_table( $wpdb, $charset_collate );
        self::create_content_index_table( $wpdb, $charset_collate );
        self::create_analytics_events_table( $wpdb, $charset_collate );
        self::create_leads_table( $wpdb, $charset_collate );
        self::create_gdpr_audit_table( $wpdb, $charset_collate );

        \update_option( 'trcl_db_version', self::SCHEMA_VERSION );

        trcl_log( 'Database migrations completed', 'info', [
            'version' => self::SCHEMA_VERSION,
        ] );
    }

    /**
     * Create conversations table.
     *
     * @param \wpdb  $wpdb            WordPress database object.
     * @param string $charset_collate Charset and collation.
     */
    private static function create_conversations_table( \wpdb $wpdb, string $charset_collate ): void {
        $table_name = $wpdb->prefix . 'trcl_conversations';

        $sql = "CREATE TABLE IF NOT EXISTS {$table_name} (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            session_id varchar(36) NOT NULL,
            user_id bigint(20) UNSIGNED DEFAULT 0,
            customer_email varchar(255) DEFAULT NULL,
            status varchar(20) NOT NULL DEFAULT 'active',
            started_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            ended_at datetime DEFAULT NULL,
            metadata text DEFAULT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY session_id (session_id),
            KEY user_id (user_id),
            KEY status (status),
            KEY started_at (started_at)
        ) {$charset_collate};";

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Table creation DDL.
        dbDelta( $sql );
    }

    /**
     * Create messages table.
     *
     * @param \wpdb  $wpdb            WordPress database object.
     * @param string $charset_collate Charset and collation.
     */
    private static function create_messages_table( \wpdb $wpdb, string $charset_collate ): void {
        $table_name     = $wpdb->prefix . 'trcl_messages';
        $conversations  = $wpdb->prefix . 'trcl_conversations';

        $sql = "CREATE TABLE IF NOT EXISTS {$table_name} (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            conversation_id bigint(20) UNSIGNED NOT NULL,
            role enum('user','assistant','system') NOT NULL,
            content longtext NOT NULL,
            metadata text DEFAULT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY conversation_id (conversation_id),
            KEY role (role),
            KEY created_at (created_at),
            FULLTEXT KEY ft_content (content)
        ) {$charset_collate};";

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Table creation DDL.
        dbDelta( $sql );

        self::ensure_messages_fulltext( $wpdb, $table_name );
    }

    /**
     * Verify the FULLTEXT index on trcl_messages.content exists, adding
     * it explicitly when dbDelta's ALTER path didn't (v2.2 CNV-02).
     *
     * dbDelta creates FULLTEXT keys reliably on the CREATE TABLE path but
     * has historically been inconsistent when upgrading an existing
     * table. Belt and braces: check SHOW INDEX, attempt a direct ALTER
     * if missing, and record the outcome in OPT_MESSAGES_FULLTEXT so the
     * conversations query service knows whether MATCH ... AGAINST is
     * available or it must fall back to LIKE.
     *
     * Failure is non-fatal by design — exotic hosts (older MyISAM
     * conversions, restricted ALTER privileges) simply get the LIKE
     * fallback and a log line.
     *
     * @since 2.2.0
     *
     * @param \wpdb  $wpdb       WordPress database object.
     * @param string $table_name Fully prefixed messages table name.
     */
    private static function ensure_messages_fulltext( \wpdb $wpdb, string $table_name ): void {
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Index introspection on custom table.
        $index = $wpdb->get_var( "SHOW INDEX FROM {$table_name} WHERE Key_name = 'ft_content'" );

        if ( null !== $index ) {
            \update_option( self::OPT_MESSAGES_FULLTEXT, '1', false );
            return;
        }

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- One-time index creation on custom table.
        $wpdb->query( "ALTER TABLE {$table_name} ADD FULLTEXT KEY ft_content (content)" );

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Verification read.
        $verify = $wpdb->get_var( "SHOW INDEX FROM {$table_name} WHERE Key_name = 'ft_content'" );

        if ( null !== $verify ) {
            \update_option( self::OPT_MESSAGES_FULLTEXT, '1', false );
            return;
        }

        \update_option( self::OPT_MESSAGES_FULLTEXT, '0', false );
        trcl_log( 'FULLTEXT index on messages could not be created — transcript search will use LIKE', 'warning', [
            'db_error' => $wpdb->last_error,
        ] );
    }

    /**
     * Create feedback table.
     *
     * @param \wpdb  $wpdb            WordPress database object.
     * @param string $charset_collate Charset and collation.
     */
    private static function create_feedback_table( \wpdb $wpdb, string $charset_collate ): void {
        $table_name = $wpdb->prefix . 'trcl_feedback';

        $sql = "CREATE TABLE IF NOT EXISTS {$table_name} (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            message_id bigint(20) UNSIGNED NOT NULL,
            rating tinyint(1) NOT NULL,
            comment text DEFAULT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY message_id (message_id),
            KEY rating (rating)
        ) {$charset_collate};";

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Table creation DDL.
        dbDelta( $sql );
    }

    /**
     * Create content index table (v1.1.0 — Block 1 page indexing).
     *
     * Stores pre-chunked snippets from WordPress pages, posts, and
     * product category descriptions for retrieval by ContentSearch.
     * Each row is a single chunk (~400 chars) tied to its source post.
     *
     * Uses a FULLTEXT index on (title, snippet) for MATCH AGAINST
     * relevance scoring. Requires InnoDB (default since WP 4.2).
     *
     * Reusable columns:
     *  - `post_id` holds the term_id when `post_type = 'product_cat'`.
     *  - `chunk_index` is 0 for single-chunk sources (taxonomy terms).
     *
     * @since 2.0.0
     *
     * @param \wpdb  $wpdb            WordPress database object.
     * @param string $charset_collate Charset and collation.
     */
    private static function create_content_index_table( \wpdb $wpdb, string $charset_collate ): void {
        $table_name = $wpdb->prefix . 'trcl_content_index';

        $sql = "CREATE TABLE IF NOT EXISTS {$table_name} (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            post_id bigint(20) UNSIGNED NOT NULL,
            post_type varchar(20) NOT NULL,
            chunk_index smallint(5) UNSIGNED NOT NULL DEFAULT 0,
            title varchar(255) NOT NULL,
            snippet text NOT NULL,
            url varchar(500) NOT NULL,
            last_indexed datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_post (post_id, chunk_index),
            KEY idx_type (post_type),
            FULLTEXT KEY ft_snippet (title, snippet)
        ) ENGINE=InnoDB {$charset_collate};";

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Table creation DDL.
        dbDelta( $sql );
    }

    /**
     * Create analytics events table (v1.2.0 — Block 3 cart + analytics).
     *
     * Append-only event log used by the dashboard to derive ROI metrics:
     * conversations started, items added to cart, orders completed, and
     * orders attributed to a chat session.
     *
     * Attribution links a chat to an order through `wc_customer_id` —
     * WooCommerce's per-visit customer identifier returned by
     * `WC()->session->get_customer_id()`. It is set for both logged-in
     * users (where it equals the WP user ID) and guests (where it is
     * a long opaque string set by WC for the session). Recording it
     * on every chat-started + thank-you event lets us correlate the
     * two without storing any extra PII.
     *
     * Column purpose:
     *   - event_type     One of: chat_started, add_to_cart,
     *                    order_completed, order_attributed.
     *   - session_id     Chat session UUID, when relevant. NULL on
     *                    cart events that pre-date a chat session.
     *   - wc_customer_id WC()->session->get_customer_id() at event time.
     *   - user_id        WP user ID (0 for guests).
     *   - order_id       WC order ID for order_* events; 0 otherwise.
     *   - value          Monetary value associated with the event
     *                    (cart line price, order total, etc.).
     *   - metadata       JSON for extra context (product_id, qty, …).
     *
     * @since 2.0.0
     *
     * @param \wpdb  $wpdb
     * @param string $charset_collate
     */
    private static function create_analytics_events_table( \wpdb $wpdb, string $charset_collate ): void {
        $table_name = $wpdb->prefix . 'trcl_analytics_events';

        $sql = "CREATE TABLE IF NOT EXISTS {$table_name} (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            event_type varchar(40) NOT NULL,
            session_id varchar(36) DEFAULT NULL,
            wc_customer_id varchar(64) DEFAULT NULL,
            user_id bigint(20) UNSIGNED NOT NULL DEFAULT 0,
            order_id bigint(20) UNSIGNED NOT NULL DEFAULT 0,
            value decimal(10,2) NOT NULL DEFAULT 0,
            metadata text DEFAULT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_type (event_type),
            KEY idx_session (session_id),
            KEY idx_wc_customer (wc_customer_id),
            KEY idx_order (order_id),
            KEY idx_created (created_at)
        ) {$charset_collate};";

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Table creation DDL.
        dbDelta( $sql );
    }

    /**
     * Create leads table (v1.3.0 — Block 4 lead capture).
     *
     * Stores email opt-ins captured proactively by Robin during chat
     * conversations (e.g. "notify me when the red shirt is back in
     * stock", "let me know if you run a sale"). Every row records the
     * exact consent text the visitor was shown so the merchant has an
     * audit trail in case a DSAR challenges the legitimate-interest
     * basis later.
     *
     * Column purpose:
     *   - session_id        Chat session UUID that produced the lead.
     *   - email             Visitor's email (sanitised before insert).
     *   - intent_type       out_of_stock | price_drop | generic.
     *   - product_id        WC product ID when intent is product-bound
     *                       (out_of_stock / price_drop); 0 otherwise.
     *   - status            new | contacted | erased.
     *   - opt_in_consent    Snapshot of the consent text Robin showed
     *                       at capture time (for GDPR audit).
     *   - metadata          JSON for extra context.
     *
     * Erasure is owned by GDPR\ConversationManager::erase_for_email
     * — when a DSAR comes in, the same email cascade also nukes any
     * lead rows so the WP Privacy flow stays consistent.
     *
     * @since 2.0.0
     *
     * @param \wpdb  $wpdb
     * @param string $charset_collate
     */
    private static function create_leads_table( \wpdb $wpdb, string $charset_collate ): void {
        $table_name = $wpdb->prefix . 'trcl_leads';

        $sql = "CREATE TABLE IF NOT EXISTS {$table_name} (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            session_id varchar(36) DEFAULT NULL,
            email varchar(255) NOT NULL,
            intent_type varchar(40) NOT NULL DEFAULT 'generic',
            product_id bigint(20) UNSIGNED NOT NULL DEFAULT 0,
            status varchar(20) NOT NULL DEFAULT 'new',
            opt_in_consent text DEFAULT NULL,
            metadata text DEFAULT NULL,
            captured_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_email (email),
            KEY idx_status (status),
            KEY idx_intent (intent_type),
            KEY idx_session (session_id),
            KEY idx_product (product_id),
            KEY idx_captured (captured_at)
        ) {$charset_collate};";

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Table creation DDL.
        dbDelta( $sql );
    }

    /**
     * Create GDPR audit log table (v1.5.0 — v2.4 PRV-02).
     *
     * Append-only record of every GDPR action performed from the
     * GDPR Tools admin page (search / view / export / erase), giving
     * the merchant an accountability trail for data subject requests.
     *
     * Privacy posture (deliberate, documented in the 2.4.0 functional
     * analysis):
     *   - `target_email` is stored MASKED (via
     *     ConversationManager::mask_email) — never in clear.
     *   - NO IP address column. Lite never stores IPs; adding one
     *     here would break that product stance.
     *   - The retention cleanup does NOT prune this table (decision
     *     D3) — it is the compliance record itself.
     *
     * @since 2.4.0
     *
     * @param \wpdb  $wpdb            WordPress database object.
     * @param string $charset_collate Charset and collation.
     */
    private static function create_gdpr_audit_table( \wpdb $wpdb, string $charset_collate ): void {
        $table_name = $wpdb->prefix . 'trcl_gdpr_audit';

        $sql = "CREATE TABLE IF NOT EXISTS {$table_name} (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            action varchar(20) NOT NULL,
            target_email varchar(255) NOT NULL,
            performed_by bigint(20) UNSIGNED NOT NULL DEFAULT 0,
            records_affected int(10) UNSIGNED NOT NULL DEFAULT 0,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_action (action),
            KEY idx_created (created_at)
        ) {$charset_collate};";

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Table creation DDL.
        dbDelta( $sql );
    }

    /**
     * Drop all plugin tables.
     *
     * Called from uninstall.php (since 2.4.0 — before that the
     * uninstaller kept its own, drifted copy of this list; this
     * method is the single source of truth now).
     */
    public static function drop_tables(): void {
        global $wpdb;

        $tables = [
            $wpdb->prefix . 'trcl_gdpr_audit',
            $wpdb->prefix . 'trcl_leads',
            $wpdb->prefix . 'trcl_analytics_events',
            $wpdb->prefix . 'trcl_content_index',
            $wpdb->prefix . 'trcl_feedback',
            $wpdb->prefix . 'trcl_messages',
            $wpdb->prefix . 'trcl_conversations',
        ];

        foreach ( $tables as $table ) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Uninstall cleanup.
            $wpdb->query( "DROP TABLE IF EXISTS {$table}" );
        }

        \delete_option( 'trcl_db_version' );

        trcl_log( 'All plugin tables dropped', 'info' );
    }

    /**
     * Get current schema version.
     *
     * @return string
     */
    public static function get_version(): string {
        return self::SCHEMA_VERSION;
    }
}
