<?php
/**
 * Conversation lookup / export / erase service for GDPR compliance.
 *
 * Backs the WP Privacy API exporter and eraser hooks. Operates on:
 *
 *   - trcl_conversations (matched by user_id linked to a WP user OR
 *     by customer_email column when populated)
 *   - trcl_messages       (descendants of matched conversations)
 *   - trcl_feedback       (descendants of matched messages)
 *
 * Erasure strategy: HARD DELETE (per design decision 2026-05-26).
 * Rows are removed; the audit trail is written to trcl_log so admins
 * can correlate erasure events with their WP Privacy Tools timeline
 * if needed.
 *
 * No IP-address handling — IPs are NOT persisted by the plugin (they
 * exist only as transient hashes for rate-limiting and are not PII
 * once hashed). See block 2 audit doc.
 *
 * @package TrillChatLite\Gdpr
 * @since 2.0.0
 * @license GPL-2.0-or-later
 */

namespace TrillChatLite\Gdpr;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
//
// EVERY $wpdb->query() / get_var() / get_results() call in this file
// IS wrapped in $wpdb->prepare(). PHPCS gets tripped up by two patterns
// it cannot statically reason about:
//
//   (a) Table-name interpolation that comes from $wpdb->prefix concat
//       with a hard-coded suffix (e.g. {$wpdb->prefix . 'trcl_messages'}).
//       This is a safe, idiomatic WP pattern — $wpdb->prefix is sourced
//       from wp-config, not from user input.
//
//   (b) The `...$conv_ids` spread used to bind a variable-length IN()
//       clause through prepare(). The placeholders string is built from
//       trusted, intval'd arrays just above each call site.
//
// File-level disable keeps the per-call noise out. Every individual
// query is documented inline.

/**
 * Class ConversationManager
 *
 * SOLID: Single Responsibility — only PII lookup / export / erase
 * over the conversations / messages / feedback tables.
 */
class ConversationManager {

    /**
     * @var \wpdb
     */
    private \wpdb $wpdb;

    /**
     * Cached fully-qualified table names.
     *
     * @var string
     */
    private string $conversations_table;
    private string $messages_table;
    private string $feedback_table;

    public function __construct() {
        global $wpdb;
        $this->wpdb = $wpdb;

        $this->conversations_table = $wpdb->prefix . 'trcl_conversations';
        $this->messages_table      = $wpdb->prefix . 'trcl_messages';
        $this->feedback_table      = $wpdb->prefix . 'trcl_feedback';
    }

    // =========================================================================
    // PUBLIC API
    // =========================================================================

    /**
     * Find every conversation tied to the given email address.
     *
     * Resolution order:
     *   1. Look up a WP user by email. If found, include rows where
     *      `user_id` matches that user.
     *   2. Always also include rows where `customer_email` column
     *      contains the same email directly (covers guest checkouts
     *      that volunteered an email at chat time).
     *
     * @param string $email Email address.
     * @return array<int, object> Conversation rows (DB objects).
     */
    public function find_by_email( string $email ): array {
        $email = \sanitize_email( $email );
        if ( $email === '' ) {
            return [];
        }

        $user      = \get_user_by( 'email', $email );
        $user_id   = ( $user && isset( $user->ID ) ) ? (int) $user->ID : 0;
        $cv_table  = $this->conversations_table;

        if ( $user_id > 0 ) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $rows = $this->wpdb->get_results(
                $this->wpdb->prepare(
                    "SELECT * FROM {$cv_table}
                      WHERE user_id = %d OR customer_email = %s
                      ORDER BY started_at ASC",
                    $user_id,
                    $email
                )
            );
        } else {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $rows = $this->wpdb->get_results(
                $this->wpdb->prepare(
                    "SELECT * FROM {$cv_table}
                      WHERE customer_email = %s
                      ORDER BY started_at ASC",
                    $email
                )
            );
        }

        return is_array( $rows ) ? $rows : [];
    }

    /**
     * Find conversations directly by WP user ID.
     *
     * @param int $user_id WP user ID.
     * @return array<int, object>
     */
    public function find_by_user_id( int $user_id ): array {
        if ( $user_id <= 0 ) {
            return [];
        }

        $cv_table = $this->conversations_table;

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $rows = $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT * FROM {$cv_table} WHERE user_id = %d ORDER BY started_at ASC",
                $user_id
            )
        );

        return is_array( $rows ) ? $rows : [];
    }

    /**
     * Find every lead record opted into by the given email.
     *
     * Delegated to LeadCaptureService so the trcl_leads table stays
     * owned by its own service; this method only adapts the result
     * shape for the privacy exporter / eraser flows.
     *
     * @since 2.0.0
     *
     * @param string $email
     * @return array
     */
    public function find_leads_by_email( string $email ): array {
        if ( ! class_exists( '\TrillChatLite\Leads\LeadCaptureService' ) ) {
            return [];
        }
        return ( new \TrillChatLite\Leads\LeadCaptureService() )->find_by_email( $email );
    }

    /**
     * Data-subject summary for the GDPR Tools lookup (v2.4 PRV-01).
     *
     * Composes the existing finders plus one COUNT over messages so
     * the admin page can show scope-of-data at a glance without any
     * SQL of its own. Read-only — nothing is exported or mutated.
     *
     * @since 2.4.0
     *
     * @param string $email Email address (sanitised here).
     * @return array{email: string, conversations: int, messages: int,
     *               leads: int, first_activity: string, last_activity: string}
     */
    public function summarise_for_email( string $email ): array {
        $summary = [
            'email'          => \sanitize_email( $email ),
            'conversations'  => 0,
            'messages'       => 0,
            'leads'          => 0,
            'first_activity' => '',
            'last_activity'  => '',
        ];

        if ( $summary['email'] === '' ) {
            return $summary;
        }

        $conversations = $this->find_by_email( $summary['email'] );

        $summary['conversations'] = count( $conversations );
        $summary['leads']         = count( $this->find_leads_by_email( $summary['email'] ) );

        if ( empty( $conversations ) ) {
            return $summary;
        }

        // find_by_email orders by started_at ASC.
        $summary['first_activity'] = (string) $conversations[0]->started_at;
        $summary['last_activity']  = (string) end( $conversations )->started_at;

        $conv_ids     = array_map( static fn( $c ): int => (int) $c->id, $conversations );
        $placeholders = implode( ',', array_fill( 0, count( $conv_ids ), '%d' ) );
        $msg_table    = $this->messages_table;

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $summary['messages'] = (int) $this->wpdb->get_var(
            $this->wpdb->prepare(
                "SELECT COUNT(*) FROM {$msg_table} WHERE conversation_id IN ({$placeholders})",
                ...$conv_ids
            )
        );

        return $summary;
    }

    /**
     * Build a WP Privacy API exporter payload for the given email.
     *
     * Each conversation is a separate "item" inside the
     * `trill-ai-chat-lite-conversations` group. The conversation's
     * messages are concatenated into a single multi-line `data` value
     * keyed `Messages` to keep the WP export ZIP human-readable
     * without exploding row counts.
     *
     * @param string $email Email address.
     * @param int    $page  Page number (we return everything in page 1).
     * @return array{data: array, done: bool}
     */
    public function export_for_email( string $email, int $page = 1 ): array {
        unset( $page ); // single-page export.
        $conversations = $this->find_by_email( $email );
        $leads         = $this->find_leads_by_email( $email );

        $items = [];

        // 1) Lead opt-ins (Block 4).
        foreach ( $leads as $lead ) {
            $items[] = [
                'group_id'    => 'trill-ai-chat-lite-leads',
                'group_label' => __( 'Trill AI Chat — Lead opt-ins', 'trill-ai-chat-lite' ),
                'item_id'     => 'trill-lead-' . (int) $lead->id,
                'data'        => [
                    [
                        'name'  => __( 'Captured at', 'trill-ai-chat-lite' ),
                        'value' => (string) ( $lead->captured_at ?? '' ),
                    ],
                    [
                        'name'  => __( 'Intent', 'trill-ai-chat-lite' ),
                        'value' => (string) ( $lead->intent_type ?? '' ),
                    ],
                    [
                        'name'  => __( 'Product ID', 'trill-ai-chat-lite' ),
                        'value' => (string) ( (int) ( $lead->product_id ?? 0 ) ),
                    ],
                    [
                        'name'  => __( 'Status', 'trill-ai-chat-lite' ),
                        'value' => (string) ( $lead->status ?? '' ),
                    ],
                    [
                        'name'  => __( 'Consent text shown at capture', 'trill-ai-chat-lite' ),
                        'value' => (string) ( $lead->opt_in_consent ?? '' ),
                    ],
                ],
            ];
        }

        // 2) Conversations (existing).
        foreach ( $conversations as $conv ) {
            $messages   = $this->get_messages_for_conversation( (int) $conv->id );
            $msg_lines  = [];
            foreach ( $messages as $msg ) {
                $msg_lines[] = sprintf(
                    '[%s] %s: %s',
                    (string) ( $msg->created_at ?? '' ),
                    (string) ( $msg->role ?? '' ),
                    (string) ( $msg->content ?? '' )
                );
            }

            $items[] = [
                'group_id'    => 'trill-ai-chat-lite-conversations',
                'group_label' => __( 'Trill AI Chat conversations', 'trill-ai-chat-lite' ),
                'item_id'     => 'trill-conv-' . (int) $conv->id,
                'data'        => [
                    [
                        'name'  => __( 'Session ID', 'trill-ai-chat-lite' ),
                        'value' => (string) ( $conv->session_id ?? '' ),
                    ],
                    [
                        'name'  => __( 'Started at', 'trill-ai-chat-lite' ),
                        'value' => (string) ( $conv->started_at ?? '' ),
                    ],
                    [
                        'name'  => __( 'Ended at', 'trill-ai-chat-lite' ),
                        'value' => (string) ( $conv->ended_at ?? '' ),
                    ],
                    [
                        'name'  => __( 'Status', 'trill-ai-chat-lite' ),
                        'value' => (string) ( $conv->status ?? '' ),
                    ],
                    [
                        'name'  => __( 'Customer email on record', 'trill-ai-chat-lite' ),
                        'value' => (string) ( $conv->customer_email ?? '' ),
                    ],
                    [
                        'name'  => __( 'Messages', 'trill-ai-chat-lite' ),
                        'value' => implode( "\n", $msg_lines ),
                    ],
                ],
            ];
        }

        return [
            'data' => $items,
            'done' => true,
        ];
    }

    /**
     * Hard-delete every record tied to the given email.
     *
     * Order is important to avoid orphan rows even if a query fails
     * partway through:
     *   1. Feedback (children of messages)
     *   2. Messages  (children of conversations)
     *   3. Conversations
     *
     * Logs an audit entry via trcl_log so the erasure is traceable
     * outside the DB (server log / WP-CLI / admin debug viewer).
     *
     * @param string $email Email address.
     * @return array{items_removed: int, items_retained: int, messages: array, done: bool}
     */
    public function erase_for_email( string $email ): array {
        $email = \sanitize_email( $email );
        if ( $email === '' ) {
            return [
                'items_removed'  => 0,
                'items_retained' => 0,
                'messages'       => [],
                'done'           => true,
            ];
        }

        // Cascade across both Block 2 (conversations) and Block 4 (leads).
        $conversations  = $this->find_by_email( $email );
        $leads_removed  = $this->erase_leads_for_email( $email );

        if ( empty( $conversations ) && $leads_removed === 0 ) {
            trcl_log( 'GDPR erase: nothing to remove', 'info', [
                'email' => self::mask_email( $email ),
            ] );
            return [
                'items_removed'  => 0,
                'items_retained' => 0,
                'messages'       => [],
                'done'           => true,
            ];
        }

        $conv_ids = array_map( static fn( $c ): int => (int) $c->id, $conversations );
        $removed  = $this->delete_cascade( $conv_ids );

        trcl_log( 'GDPR erase complete', 'info', [
            'email_masked'      => self::mask_email( $email ),
            'conversations'     => count( $conv_ids ),
            'rows_deleted'      => $removed,
            'leads_removed'     => $leads_removed,
        ] );

        return [
            'items_removed'  => count( $conv_ids ) + $leads_removed,
            'items_retained' => 0,
            'messages'       => [],
            'done'           => true,
        ];
    }

    /**
     * Hard-delete a specific set of conversations by ID.
     *
     * Admin-initiated deletion (v2.2 CNV-08). Reuses the exact same
     * audited cascade as the GDPR eraser (feedback → messages →
     * conversations) so there is a single source of truth for what
     * "removing a conversation" means across the plugin — no parallel
     * delete path that could miss a related table.
     *
     * @since 2.2.0
     *
     * @param int[] $conversation_ids Conversation IDs to remove.
     * @return int Total rows deleted across the three tables.
     */
    public function delete_by_ids( array $conversation_ids ): int {
        $conv_ids = array_values(
            array_unique(
                array_filter(
                    array_map( 'intval', $conversation_ids ),
                    static fn( int $id ): bool => $id > 0
                )
            )
        );

        if ( empty( $conv_ids ) ) {
            return 0;
        }

        $removed = $this->delete_cascade( $conv_ids );

        trcl_log( 'Admin conversation delete', 'info', [
            'conversations' => count( $conv_ids ),
            'rows_deleted'  => $removed,
        ] );

        return $removed;
    }

    /**
     * Hard-delete leads associated with the given email.
     *
     * Wrapper around LeadCaptureService::erase_by_email so the GDPR
     * eraser stays in one place. Returns 0 silently when the leads
     * service isn't available (uninstall partway, etc).
     *
     * @since 2.0.0
     *
     * @param string $email
     * @return int Rows deleted.
     */
    private function erase_leads_for_email( string $email ): int {
        if ( ! class_exists( '\TrillChatLite\Leads\LeadCaptureService' ) ) {
            return 0;
        }
        return ( new \TrillChatLite\Leads\LeadCaptureService() )->erase_by_email( $email );
    }

    // =========================================================================
    // INTERNALS
    // =========================================================================

    /**
     * Get all messages for a conversation, ascending by time.
     *
     * @param int $conversation_id Numeric conversation ID.
     * @return array<int, object>
     */
    private function get_messages_for_conversation( int $conversation_id ): array {
        if ( $conversation_id <= 0 ) {
            return [];
        }

        $msg_table = $this->messages_table;

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $rows = $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT id, role, content, created_at FROM {$msg_table}
                  WHERE conversation_id = %d
                  ORDER BY created_at ASC, id ASC",
                $conversation_id
            )
        );

        return is_array( $rows ) ? $rows : [];
    }

    /**
     * Delete feedback → messages → conversations for the given IDs.
     *
     * @param int[] $conv_ids Conversation IDs to remove.
     * @return int Total rows deleted across the three tables.
     */
    private function delete_cascade( array $conv_ids ): int {
        if ( empty( $conv_ids ) ) {
            return 0;
        }

        $conv_ids   = array_map( 'intval', $conv_ids );
        $placeholders_conv = implode( ',', array_fill( 0, count( $conv_ids ), '%d' ) );

        $cv_table   = $this->conversations_table;
        $msg_table  = $this->messages_table;
        $fb_table   = $this->feedback_table;

        $total_deleted = 0;

        // 1) Collect message IDs that will go away (needed to wipe feedback).
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $message_ids = $this->wpdb->get_col(
            $this->wpdb->prepare(
                "SELECT id FROM {$msg_table} WHERE conversation_id IN ({$placeholders_conv})",
                ...$conv_ids
            )
        );
        $message_ids = is_array( $message_ids ) ? array_map( 'intval', $message_ids ) : [];

        // 2) Delete feedback rows for those messages.
        if ( ! empty( $message_ids ) ) {
            $placeholders_msg = implode( ',', array_fill( 0, count( $message_ids ), '%d' ) );
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
            $deleted_fb = $this->wpdb->query(
                $this->wpdb->prepare(
                    "DELETE FROM {$fb_table} WHERE message_id IN ({$placeholders_msg})",
                    ...$message_ids
                )
            );
            $total_deleted += (int) max( 0, $deleted_fb );
        }

        // 3) Delete messages.
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
        $deleted_msg = $this->wpdb->query(
            $this->wpdb->prepare(
                "DELETE FROM {$msg_table} WHERE conversation_id IN ({$placeholders_conv})",
                ...$conv_ids
            )
        );
        $total_deleted += (int) max( 0, $deleted_msg );

        // 4) Delete conversations.
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
        $deleted_cv = $this->wpdb->query(
            $this->wpdb->prepare(
                "DELETE FROM {$cv_table} WHERE id IN ({$placeholders_conv})",
                ...$conv_ids
            )
        );
        $total_deleted += (int) max( 0, $deleted_cv );

        return $total_deleted;
    }

    /**
     * Mask an email for log output: keeps the first char and the
     * domain, e.g. "f***@example.com". Prevents PII leaking into
     * server logs / error trackers.
     *
     * Public since 2.4.0 — AuditLogger reuses it so masking rules
     * live in exactly one place (PRV-02).
     *
     * @param string $email
     * @return string
     */
    public static function mask_email( string $email ): string {
        $at = strpos( $email, '@' );
        if ( $at === false || $at < 1 ) {
            return '***';
        }
        return $email[0] . str_repeat( '*', max( 1, $at - 1 ) ) . substr( $email, $at );
    }
}
