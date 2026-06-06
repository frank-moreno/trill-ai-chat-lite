<?php
/**
 * Conversation Query Service (v2.2 CNV-01).
 *
 * Read-only query layer for the Conversations admin page: filtered,
 * paginated conversation lists with per-row aggregates, plus single
 * transcripts for the View modal.
 *
 * Design decisions (deliberate divergences from the Pro plugin):
 *   - Message counts come from a real JOIN/COUNT on trcl_messages —
 *     never a denormalised counter column (Pro's counter drifted and
 *     showed 0 messages for every conversation).
 *   - converted / revenue come from trcl_analytics_events
 *     ('order_attributed' rows, Block 3) joined by session_id — we do
 *     NOT duplicate order columns onto the conversations table.
 *   - rating is aggregated from trcl_feedback (1–5 per message)
 *     through trcl_messages.
 *
 * Transcript search uses MATCH ... AGAINST when the ft_content index
 * is available (Migrations 1.4.0) and falls back to LIKE otherwise.
 *
 * @package TrillChatLite\Conversations
 * @since 2.2.0
 * @license GPL-2.0-or-later
 */

namespace TrillChatLite\Conversations;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use TrillChatLite\Database\Migrations;

/**
 * Class ConversationQueryService.
 *
 * SOLID: Single Responsibility — read-only conversation queries for
 * the admin surface. Writes stay in DbManager; erasure stays in
 * Gdpr\ConversationManager.
 */
class ConversationQueryService {

    /**
     * Statuses accepted by the status filter.
     *
     * @var string[]
     */
    public const ALLOWED_STATUSES = [ 'active', 'completed', 'ended', 'escalated' ];

    /**
     * Hard cap on per-page size (defence against ?per_page=100000).
     */
    private const PER_PAGE_MAX = 100;

    /**
     * Default rows per page.
     */
    private const PER_PAGE_DEFAULT = 20;

    /**
     * WordPress database object.
     *
     * @var \wpdb
     */
    private \wpdb $wpdb;

    /**
     * Fully prefixed table names.
     *
     * @var string
     */
    private string $conversations;
    private string $messages;
    private string $feedback;
    private string $analytics;

    /**
     * Constructor.
     */
    public function __construct() {
        global $wpdb;

        $this->wpdb          = $wpdb;
        $this->conversations = $wpdb->prefix . 'trcl_conversations';
        $this->messages      = $wpdb->prefix . 'trcl_messages';
        $this->feedback      = $wpdb->prefix . 'trcl_feedback';
        $this->analytics     = $wpdb->prefix . 'trcl_analytics_events';
    }

    /**
     * Whether transcript search can use the FULLTEXT index.
     *
     * @return bool
     */
    public function uses_fulltext(): bool {
        return \get_option( Migrations::OPT_MESSAGES_FULLTEXT, '0' ) === '1';
    }

    /**
     * Query conversations with filters, aggregates and pagination.
     *
     * Accepted $args (all optional):
     *   - date_from string 'Y-m-d'  inclusive lower bound on started_at
     *   - date_to   string 'Y-m-d'  inclusive upper bound on started_at
     *   - status    string          one of ALLOWED_STATUSES
     *   - rating    int 1–5         conversations whose rounded average
     *                               feedback equals this value
     *   - search    string          keyword in message content
     *   - page      int             1-based page number
     *   - per_page  int             rows per page (max PER_PAGE_MAX)
     *
     * Returns:
     *   [
     *     'rows'     => array<object>, // see SELECT list below
     *     'total'    => int,
     *     'pages'    => int,
     *     'page'     => int,
     *     'per_page' => int,
     *   ]
     *
     * @param array $args Filter arguments (raw — sanitised here).
     * @return array Result envelope.
     */
    public function query( array $args = [] ): array {
        $filters = $this->sanitise_filters( $args );

        list( $where_sql, $where_params ) = $this->build_where( $filters );

        // Aggregate sub-joins. All grouped by conversation/session so the
        // outer query stays one-row-per-conversation.
        $joins = "
            LEFT JOIN (
                SELECT conversation_id, COUNT(*) AS message_count
                  FROM {$this->messages}
                 WHERE role IN ('user','assistant')
                 GROUP BY conversation_id
            ) mc ON mc.conversation_id = c.id
            LEFT JOIN (
                SELECT m.conversation_id,
                       AVG(f.rating)  AS avg_rating,
                       COUNT(f.id)    AS rating_count
                  FROM {$this->feedback} f
                  INNER JOIN {$this->messages} m ON m.id = f.message_id
                 GROUP BY m.conversation_id
            ) fb ON fb.conversation_id = c.id
            LEFT JOIN (
                SELECT session_id,
                       MAX(order_id)  AS order_id,
                       SUM(value)     AS revenue
                  FROM {$this->analytics}
                 WHERE event_type = 'order_attributed'
                 GROUP BY session_id
            ) att ON att.session_id = c.session_id
        ";

        // Total count first — same FROM/JOIN/WHERE as the row query.
        $count_sql = "
            SELECT COUNT(*)
              FROM {$this->conversations} c
              {$joins}
              {$where_sql}
        ";

        $count_params = $where_params;

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Table names are trusted local properties; values bound below.
        $total = (int) $this->wpdb->get_var(
            $count_params
                // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                ? $this->wpdb->prepare( $count_sql, $count_params )
                : $count_sql
        );

        $pages  = max( 1, (int) ceil( $total / $filters['per_page'] ) );
        $page   = min( $filters['page'], $pages );
        $offset = ( $page - 1 ) * $filters['per_page'];

        $rows_sql = "
            SELECT c.id,
                   c.session_id,
                   c.user_id,
                   c.customer_email,
                   c.status,
                   c.started_at,
                   c.ended_at,
                   COALESCE(mc.message_count, 0)            AS message_count,
                   fb.avg_rating,
                   COALESCE(fb.rating_count, 0)             AS rating_count,
                   COALESCE(att.order_id, 0)                AS order_id,
                   COALESCE(att.revenue, 0)                 AS revenue
              FROM {$this->conversations} c
              {$joins}
              {$where_sql}
             ORDER BY c.started_at DESC, c.id DESC
             LIMIT %d OFFSET %d
        ";

        $rows_params   = array_merge( $where_params, [ $filters['per_page'], $offset ] );
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Table names are trusted local properties; values bound via prepare.
        $rows = $this->wpdb->get_results( $this->wpdb->prepare( $rows_sql, $rows_params ) );

        return [
            'rows'     => is_array( $rows ) ? $rows : [],
            'total'    => $total,
            'pages'    => $pages,
            'page'     => $page,
            'per_page' => $filters['per_page'],
        ];
    }

    /**
     * Fetch one conversation + its full message list for the View modal
     * (and the per-row CSV export).
     *
     * @param int $conversation_id Conversation row ID.
     * @return array|null ['conversation' => object, 'messages' => object[]] or null when not found.
     */
    public function get_transcript( int $conversation_id ): ?array {
        if ( $conversation_id <= 0 ) {
            return null;
        }

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Table name is a trusted local property.
        $conversation = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT id, session_id, user_id, customer_email, status, started_at, ended_at
                   FROM {$this->conversations}
                  WHERE id = %d",
                $conversation_id
            )
        );

        if ( null === $conversation ) {
            return null;
        }

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Table name is a trusted local property.
        $messages = $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT m.id, m.role, m.content, m.created_at, f.rating AS feedback_rating
                   FROM {$this->messages} m
                   LEFT JOIN {$this->feedback} f ON f.message_id = m.id
                  WHERE m.conversation_id = %d
                    AND m.role IN ('user','assistant')
                  ORDER BY m.created_at ASC, m.id ASC",
                $conversation_id
            )
        );

        return [
            'conversation' => $conversation,
            'messages'     => is_array( $messages ) ? $messages : [],
        ];
    }

    /**
     * Sanitise raw filter arguments into a trusted shape.
     *
     * @param array $args Raw args (typically from $_GET, unslashed by caller).
     * @return array{date_from:string, date_to:string, status:string, rating:int, search:string, page:int, per_page:int}
     */
    public function sanitise_filters( array $args ): array {
        $date_from = isset( $args['date_from'] ) ? $this->sanitise_date( (string) $args['date_from'] ) : '';
        $date_to   = isset( $args['date_to'] ) ? $this->sanitise_date( (string) $args['date_to'] ) : '';

        $status = isset( $args['status'] ) ? \sanitize_key( (string) $args['status'] ) : '';
        if ( ! in_array( $status, self::ALLOWED_STATUSES, true ) ) {
            $status = '';
        }

        $rating = isset( $args['rating'] ) ? (int) $args['rating'] : 0;
        if ( $rating < 1 || $rating > 5 ) {
            $rating = 0;
        }

        $search = isset( $args['search'] ) ? \sanitize_text_field( (string) $args['search'] ) : '';
        $search = mb_substr( $search, 0, 100 );

        $page     = isset( $args['page'] ) ? max( 1, (int) $args['page'] ) : 1;
        $per_page = isset( $args['per_page'] ) ? (int) $args['per_page'] : self::PER_PAGE_DEFAULT;
        $per_page = max( 1, min( self::PER_PAGE_MAX, $per_page ) );

        return compact( 'date_from', 'date_to', 'status', 'rating', 'search', 'page', 'per_page' );
    }

    /**
     * Validate a Y-m-d date string (returns '' when invalid).
     *
     * @param string $value Raw date.
     * @return string Valid Y-m-d or ''.
     */
    private function sanitise_date( string $value ): string {
        $value = trim( $value );

        if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
            return '';
        }

        $parts = explode( '-', $value );

        return checkdate( (int) $parts[1], (int) $parts[2], (int) $parts[0] ) ? $value : '';
    }

    /**
     * Build the WHERE clause + bind params from sanitised filters.
     *
     * @param array $filters Output of sanitise_filters().
     * @return array{0:string, 1:array} [SQL fragment, params].
     */
    private function build_where( array $filters ): array {
        $conditions = [];
        $params     = [];

        if ( '' !== $filters['date_from'] ) {
            $conditions[] = 'c.started_at >= %s';
            $params[]     = $filters['date_from'] . ' 00:00:00';
        }

        if ( '' !== $filters['date_to'] ) {
            $conditions[] = 'c.started_at <= %s';
            $params[]     = $filters['date_to'] . ' 23:59:59';
        }

        if ( '' !== $filters['status'] ) {
            $conditions[] = 'c.status = %s';
            $params[]     = $filters['status'];
        }

        if ( '' !== $filters['search'] ) {
            if ( $this->uses_fulltext() ) {
                $conditions[] = "EXISTS (
                    SELECT 1 FROM {$this->messages} ms
                     WHERE ms.conversation_id = c.id
                       AND MATCH(ms.content) AGAINST (%s IN NATURAL LANGUAGE MODE)
                )";
                $params[]     = $filters['search'];
            } else {
                $conditions[] = "EXISTS (
                    SELECT 1 FROM {$this->messages} ms
                     WHERE ms.conversation_id = c.id
                       AND ms.content LIKE %s
                )";
                $params[]     = '%' . $this->wpdb->esc_like( $filters['search'] ) . '%';
            }
        }

        // Rating filter: fb.avg_rating comes from a pre-grouped subquery
        // join, so it is a plain joined column here — WHERE is correct
        // (no outer GROUP BY exists for a HAVING to hang off).
        if ( $filters['rating'] > 0 ) {
            $conditions[] = 'ROUND(fb.avg_rating) = %d';
            $params[]     = $filters['rating'];
        }

        $sql = $conditions ? 'WHERE ' . implode( ' AND ', $conditions ) : '';

        return [ $sql, $params ];
    }
}
