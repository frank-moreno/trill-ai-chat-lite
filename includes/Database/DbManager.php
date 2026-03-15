<?php
/**
 * Database Manager for Lite plugin.
 *
 * Handles all database operations for conversations, messages, and feedback.
 * Simplified for Lite: no analytics table, no tool executions.
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
 * Class DbManager
 *
 * SOLID: Single Responsibility — only database operations.
 */
class DbManager {

    /**
     * WordPress database object.
     *
     * @var \wpdb
     */
    private \wpdb $wpdb;

    /**
     * Table names.
     *
     * @var string
     */
    private string $conversations_table;
    private string $messages_table;
    private string $feedback_table;

    /**
     * Constructor.
     */
    public function __construct() {
        global $wpdb;
        $this->wpdb = $wpdb;

        $this->conversations_table = $wpdb->prefix . 'trcl_conversations';
        $this->messages_table      = $wpdb->prefix . 'trcl_messages';
        $this->feedback_table      = $wpdb->prefix . 'trcl_feedback';
    }

    /**
     * Create a new conversation.
     *
     * @param int   $user_id  User ID (0 for guest).
     * @param array $metadata Additional metadata.
     * @return string Session ID (UUID) or empty string on failure.
     */
    public function create_conversation( int $user_id = 0, array $metadata = [] ): string {
        $session_id = \wp_generate_uuid4();

        $data    = [
            'session_id' => $session_id,
            'status'     => 'active',
        ];
        $formats = [ '%s', '%s' ];

        if ( $user_id > 0 ) {
            $data['user_id'] = $user_id;
            $formats[]       = '%d';
        }

        if ( ! empty( $metadata['customer_email'] ) ) {
            $data['customer_email'] = \sanitize_email( $metadata['customer_email'] );
            $formats[]              = '%s';
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table, no cache needed.
        $result = $this->wpdb->insert(
            $this->conversations_table,
            $data,
            $formats
        );

        if ( false === $result ) {
            trcl_log( 'Failed to create conversation: ' . $this->wpdb->last_error, 'error', [
                'session_id' => $session_id,
            ] );
            return '';
        }

        trcl_log( 'Conversation created', 'info', [
            'session_id' => $session_id,
        ] );

        return $session_id;
    }

    /**
     * Get conversation by session_id.
     *
     * @param string $session_id Session ID (UUID).
     * @return object|null Conversation object or null.
     */
    public function get_conversation( string $session_id ): ?object {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name from $wpdb->prefix, safe.
        $result = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->conversations_table} WHERE session_id = %s",
                $session_id
            )
        );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared

        return $result ?: null;
    }

    /**
     * Get conversation numeric ID from session_id.
     *
     * @param string $session_id Session ID (UUID).
     * @return int|null Conversation ID or null.
     */
    public function get_conversation_id( string $session_id ): ?int {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name from $wpdb->prefix, safe.
        $id = $this->wpdb->get_var(
            $this->wpdb->prepare(
                "SELECT id FROM {$this->conversations_table} WHERE session_id = %s",
                $session_id
            )
        );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared

        return $id ? (int) $id : null;
    }

    /**
     * Check if conversation exists.
     *
     * @param string $session_id Session ID (UUID).
     * @return bool True if conversation exists.
     */
    public function conversation_exists( string $session_id ): bool {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name from $wpdb->prefix, safe.
        $count = $this->wpdb->get_var(
            $this->wpdb->prepare(
                "SELECT COUNT(*) FROM {$this->conversations_table} WHERE session_id = %s",
                $session_id
            )
        );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared

        return (int) $count > 0;
    }

    /**
     * Create a new message.
     *
     * @param string $session_id Session ID (UUID).
     * @param string $role       Message role (user|assistant|system).
     * @param string $content    Message content.
     * @param array  $metadata   Optional metadata.
     * @return int|false Message ID or false on failure.
     */
    public function create_message(
        string $session_id,
        string $role,
        string $content,
        array $metadata = []
    ) {
        $conversation_id = $this->get_conversation_id( $session_id );

        if ( ! $conversation_id ) {
            trcl_log( 'Cannot create message: conversation not found', 'error', [
                'session_id' => $session_id,
            ] );
            return false;
        }

        $data    = [
            'conversation_id' => $conversation_id,
            'role'            => $role,
            'content'         => $content,
        ];
        $formats = [ '%d', '%s', '%s' ];

        if ( ! empty( $metadata['metadata'] ) ) {
            $data['metadata'] = is_string( $metadata['metadata'] )
                ? $metadata['metadata']
                : \wp_json_encode( $metadata['metadata'] );
            $formats[]        = '%s';
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table, no cache needed.
        $result = $this->wpdb->insert(
            $this->messages_table,
            $data,
            $formats
        );

        if ( false === $result ) {
            trcl_log( 'Failed to create message: ' . $this->wpdb->last_error, 'error', [
                'session_id' => $session_id,
            ] );
            return false;
        }

        return $this->wpdb->insert_id;
    }

    /**
     * Get messages for conversation.
     *
     * @param string $session_id Session ID (UUID).
     * @param int    $limit      Maximum number of messages.
     * @param int    $offset     Offset for pagination.
     * @return array Array of message objects.
     */
    public function get_messages( string $session_id, int $limit = 50, int $offset = 0 ): array {
        $conversation_id = $this->get_conversation_id( $session_id );

        if ( ! $conversation_id ) {
            return [];
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name from $wpdb->prefix, safe.
        $results = $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->messages_table}
                WHERE conversation_id = %d
                ORDER BY created_at ASC
                LIMIT %d OFFSET %d",
                $conversation_id,
                $limit,
                $offset
            )
        );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared

        return $results ?: [];
    }

    /**
     * Count conversations started in the current calendar month.
     *
     * This is an INFORMATIONAL counter only — the actual conversation limit
     * is enforced server-side by the proxy (api.trillai.io) via HTTP 402.
     * The local count may diverge slightly from the server count.
     *
     * @return int Number of conversations this month.
     */
    public function get_monthly_conversation_count(): int {
        $first_day = \gmdate( 'Y-m-01 00:00:00' );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name from $wpdb->prefix, safe.
        $count = $this->wpdb->get_var(
            $this->wpdb->prepare(
                "SELECT COUNT(*) FROM {$this->conversations_table} WHERE started_at >= %s",
                $first_day
            )
        );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

        return (int) $count;
    }

    /**
     * Get conversation statistics.
     *
     * @return array Statistics array.
     */
    public function get_conversation_stats(): array {
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table names from $wpdb->prefix, safe.
        $total_conversations = (int) $this->wpdb->get_var(
            "SELECT COUNT(*) FROM {$this->conversations_table}"
        );

        $total_messages = (int) $this->wpdb->get_var(
            "SELECT COUNT(*) FROM {$this->messages_table}"
        );

        $avg_messages = $total_conversations > 0
            ? round( $total_messages / $total_conversations, 2 )
            : 0;

        $active_conversations = (int) $this->wpdb->get_var(
            "SELECT COUNT(*) FROM {$this->conversations_table} WHERE status = 'active'"
        );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter

        return [
            'total_conversations' => $total_conversations,
            'total_messages'      => $total_messages,
            'avg_messages_per_conversation' => $avg_messages,
            'active_conversations' => $active_conversations,
        ];
    }

    /**
     * Save feedback.
     *
     * @param int    $message_id Message ID.
     * @param int    $rating     Rating (1-5).
     * @param string $comment    Optional comment.
     * @return bool True on success.
     */
    public function save_feedback( int $message_id, int $rating, string $comment = '' ): bool {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table.
        $result = $this->wpdb->insert(
            $this->feedback_table,
            [
                'message_id' => $message_id,
                'rating'     => $rating,
                'comment'    => $comment,
            ],
            [ '%d', '%d', '%s' ]
        );

        return false !== $result;
    }

    /**
     * Update conversation status.
     *
     * @param string $session_id Session ID (UUID).
     * @param string $status     New status (active|ended|escalated).
     * @return bool True on success.
     */
    public function update_conversation_status( string $session_id, string $status ): bool {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
        $result = $this->wpdb->update(
            $this->conversations_table,
            [ 'status' => $status ],
            [ 'session_id' => $session_id ],
            [ '%s' ],
            [ '%s' ]
        );

        return false !== $result;
    }

    /**
     * Delete old conversations (cleanup).
     *
     * @param int $days Number of days to keep.
     * @return int Number of conversations deleted.
     */
    public function cleanup_old_conversations( int $days = 30 ): int {
        $cutoff_date = \gmdate( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) );

        // Get conversation IDs to delete.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name from $wpdb->prefix, safe.
        $conversation_ids = $this->wpdb->get_col(
            $this->wpdb->prepare(
                "SELECT id FROM {$this->conversations_table} WHERE started_at < %s",
                $cutoff_date
            )
        );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared

        if ( empty( $conversation_ids ) ) {
            return 0;
        }

        // Delete messages first.
        $ids_placeholders = implode( ',', array_fill( 0, count( $conversation_ids ), '%d' ) );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Dynamic placeholders.
        $this->wpdb->query(
            $this->wpdb->prepare(
                "DELETE FROM {$this->messages_table} WHERE conversation_id IN ({$ids_placeholders})",
                ...$conversation_ids
            )
        );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare

        // Delete conversations.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name from $wpdb->prefix, safe.
        $deleted = $this->wpdb->query(
            $this->wpdb->prepare(
                "DELETE FROM {$this->conversations_table} WHERE started_at < %s",
                $cutoff_date
            )
        );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared

        trcl_log( "Cleaned up {$deleted} old conversations", 'info' );

        return (int) $deleted;
    }
}
