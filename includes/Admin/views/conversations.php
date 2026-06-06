<?php
/**
 * Conversations admin view (v2.2 CNV-03).
 *
 * Filtered, paginated list of chat conversations with per-row
 * aggregates (messages, rating, converted, revenue), a transcript
 * View modal (AJAX, rendered via .text() in admin.js) and CSV exports
 * (global filtered + per conversation).
 *
 * Included from Admin::render_conversations() — $this is the Admin
 * instance, used for resolve_customer_label().
 *
 * @package TrillChatLite\Admin
 * @since 2.2.0
 * @license GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$trcl_service = new \TrillChatLite\Conversations\ConversationQueryService();

// Read + sanitise filters. Read-only list filtering — same nonce posture
// as core list tables.
// phpcs:ignore WordPress.Security.NonceVerification.Recommended
$trcl_raw     = \wp_unslash( $_GET );
$trcl_filters = $trcl_service->sanitise_filters( is_array( $trcl_raw ) ? $trcl_raw : [] );
$trcl_result  = $trcl_service->query( $trcl_filters );

$trcl_base_url = \admin_url( 'admin.php?page=trcl-conversations' );

// Global export URL — mirrors the active filters.
$trcl_export_url = \wp_nonce_url(
    \add_query_arg(
        array_filter( [
            'action'    => 'trcl_conversations_export',
            'date_from' => $trcl_filters['date_from'],
            'date_to'   => $trcl_filters['date_to'],
            'status'    => $trcl_filters['status'],
            'rating'    => $trcl_filters['rating'] > 0 ? (string) $trcl_filters['rating'] : '',
            'search'    => $trcl_filters['search'],
        ] ),
        \admin_url( 'admin-post.php' )
    ),
    'trcl_conversations_export'
);

$trcl_statuses = \TrillChatLite\Conversations\ConversationQueryService::ALLOWED_STATUSES;
?>

<div class="wrap trcl-conversations-page">
    <h1><?php esc_html_e( 'Conversations', 'trill-ai-chat-lite' ); ?></h1>

    <?php if ( ! $trcl_service->uses_fulltext() ) : ?>
        <p class="description">
            <?php esc_html_e( 'Note: message search is running in compatibility mode on this host (slower on large tables).', 'trill-ai-chat-lite' ); ?>
        </p>
    <?php endif; ?>

    <form method="get" class="trcl-conversations-filters">
        <input type="hidden" name="page" value="trcl-conversations" />

        <label>
            <?php esc_html_e( 'From', 'trill-ai-chat-lite' ); ?>
            <input type="date" name="date_from" value="<?php echo esc_attr( $trcl_filters['date_from'] ); ?>" />
        </label>

        <label>
            <?php esc_html_e( 'To', 'trill-ai-chat-lite' ); ?>
            <input type="date" name="date_to" value="<?php echo esc_attr( $trcl_filters['date_to'] ); ?>" />
        </label>

        <select name="status">
            <option value=""><?php esc_html_e( 'All Statuses', 'trill-ai-chat-lite' ); ?></option>
            <?php foreach ( $trcl_statuses as $trcl_status_option ) : ?>
                <option value="<?php echo esc_attr( $trcl_status_option ); ?>" <?php selected( $trcl_filters['status'], $trcl_status_option ); ?>>
                    <?php echo esc_html( ucfirst( $trcl_status_option ) ); ?>
                </option>
            <?php endforeach; ?>
        </select>

        <select name="rating">
            <option value=""><?php esc_html_e( 'All Ratings', 'trill-ai-chat-lite' ); ?></option>
            <?php for ( $trcl_star = 5; $trcl_star >= 1; $trcl_star-- ) : ?>
                <option value="<?php echo esc_attr( (string) $trcl_star ); ?>" <?php selected( $trcl_filters['rating'], $trcl_star ); ?>>
                    <?php
                    /* translators: %d: star rating 1-5 */
                    echo esc_html( sprintf( _n( '%d star', '%d stars', $trcl_star, 'trill-ai-chat-lite' ), $trcl_star ) );
                    ?>
                </option>
            <?php endfor; ?>
        </select>

        <input type="search"
               name="search"
               value="<?php echo esc_attr( $trcl_filters['search'] ); ?>"
               placeholder="<?php esc_attr_e( 'Search messages…', 'trill-ai-chat-lite' ); ?>" />

        <button type="submit" class="button button-primary"><?php esc_html_e( 'Filter', 'trill-ai-chat-lite' ); ?></button>
        <a href="<?php echo esc_url( $trcl_base_url ); ?>" class="button"><?php esc_html_e( 'Reset', 'trill-ai-chat-lite' ); ?></a>

        <a href="<?php echo esc_url( $trcl_export_url ); ?>" class="button trcl-export-btn">
            <?php esc_html_e( 'Export CSV', 'trill-ai-chat-lite' ); ?>
        </a>
    </form>

    <p class="trcl-conversations-count">
        <?php
        /* translators: %d: number of conversations matching the filters */
        echo esc_html( sprintf( _n( '%d conversation', '%d conversations', $trcl_result['total'], 'trill-ai-chat-lite' ), $trcl_result['total'] ) );
        ?>
    </p>

    <table class="widefat striped trcl-conversations-table">
        <thead>
            <tr>
                <th><?php esc_html_e( 'Date', 'trill-ai-chat-lite' ); ?></th>
                <th><?php esc_html_e( 'Customer', 'trill-ai-chat-lite' ); ?></th>
                <th><?php esc_html_e( 'Status', 'trill-ai-chat-lite' ); ?></th>
                <th><?php esc_html_e( 'Messages', 'trill-ai-chat-lite' ); ?></th>
                <th><?php esc_html_e( 'Rating', 'trill-ai-chat-lite' ); ?></th>
                <th><?php esc_html_e( 'Converted', 'trill-ai-chat-lite' ); ?></th>
                <th><?php esc_html_e( 'Revenue', 'trill-ai-chat-lite' ); ?></th>
                <th><?php esc_html_e( 'Actions', 'trill-ai-chat-lite' ); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php if ( empty( $trcl_result['rows'] ) ) : ?>
                <tr>
                    <td colspan="8"><?php esc_html_e( 'No conversations match the current filters.', 'trill-ai-chat-lite' ); ?></td>
                </tr>
            <?php else : ?>
                <?php foreach ( $trcl_result['rows'] as $trcl_row ) : ?>
                    <?php
                    $trcl_row_csv_url = \wp_nonce_url(
                        \add_query_arg(
                            [
                                'action'          => 'trcl_transcript_export',
                                'conversation_id' => (int) $trcl_row->id,
                            ],
                            \admin_url( 'admin-post.php' )
                        ),
                        'trcl_transcript_export_' . (int) $trcl_row->id
                    );
                    $trcl_converted = ( (int) $trcl_row->order_id > 0 );
                    ?>
                    <tr>
                        <td><?php echo esc_html( \mysql2date( 'Y-m-d H:i', $trcl_row->started_at ) ); ?></td>
                        <td><?php echo esc_html( $this->resolve_customer_label( (int) $trcl_row->user_id, (string) $trcl_row->customer_email ) ); ?></td>
                        <td>
                            <span class="trcl-status-badge trcl-status-badge--<?php echo esc_attr( $trcl_row->status ); ?>">
                                <?php echo esc_html( strtoupper( $trcl_row->status ) ); ?>
                            </span>
                        </td>
                        <td><?php echo esc_html( (string) (int) $trcl_row->message_count ); ?></td>
                        <td>
                            <?php if ( null !== $trcl_row->avg_rating ) : ?>
                                <span class="trcl-rating" title="<?php echo esc_attr( round( (float) $trcl_row->avg_rating, 1 ) . ' / 5' ); ?>">
                                    <?php echo esc_html( str_repeat( '★', (int) round( (float) $trcl_row->avg_rating ) ) . str_repeat( '☆', 5 - (int) round( (float) $trcl_row->avg_rating ) ) ); ?>
                                </span>
                            <?php else : ?>
                                <span aria-hidden="true">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ( $trcl_converted ) : ?>
                                <span class="trcl-converted-yes">✓</span>
                                <a href="<?php echo esc_url( \admin_url( 'post.php?post=' . (int) $trcl_row->order_id . '&action=edit' ) ); ?>">
                                    #<?php echo esc_html( (string) (int) $trcl_row->order_id ); ?>
                                </a>
                            <?php else : ?>
                                <span aria-hidden="true">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php
                            if ( $trcl_converted && function_exists( 'wc_price' ) ) {
                                echo \wp_kses_post( \wc_price( (float) $trcl_row->revenue ) );
                            } else {
                                echo '<span aria-hidden="true">—</span>';
                            }
                            ?>
                        </td>
                        <td>
                            <button type="button"
                                    class="button button-small trcl-view-transcript"
                                    data-conversation-id="<?php echo esc_attr( (string) (int) $trcl_row->id ); ?>">
                                <?php esc_html_e( 'View', 'trill-ai-chat-lite' ); ?>
                            </button>
                            <a href="<?php echo esc_url( $trcl_row_csv_url ); ?>" class="button button-small">
                                <?php esc_html_e( 'CSV', 'trill-ai-chat-lite' ); ?>
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>

    <?php if ( $trcl_result['pages'] > 1 ) : ?>
        <div class="tablenav bottom">
            <div class="tablenav-pages">
                <?php
                $trcl_pagination = \paginate_links( [
                    'base'      => \add_query_arg( 'paged', '%#%' ),
                    'format'    => '',
                    'current'   => $trcl_result['page'],
                    'total'     => $trcl_result['pages'],
                    'prev_text' => '‹',
                    'next_text' => '›',
                ] );
                if ( is_string( $trcl_pagination ) ) {
                    echo \wp_kses_post( $trcl_pagination );
                }
                ?>
            </div>
        </div>
    <?php endif; ?>

    <!-- Transcript modal (populated by admin.js via .text() only) -->
    <div class="trcl-modal-backdrop" id="trcl-transcript-modal" style="display:none" role="dialog" aria-modal="true" aria-labelledby="trcl-modal-title">
        <div class="trcl-modal">
            <div class="trcl-modal-header">
                <h2 id="trcl-modal-title"><?php esc_html_e( 'Conversation', 'trill-ai-chat-lite' ); ?></h2>
                <button type="button" class="button-link trcl-modal-close" aria-label="<?php esc_attr_e( 'Close', 'trill-ai-chat-lite' ); ?>">×</button>
            </div>
            <div class="trcl-modal-meta" id="trcl-modal-meta"></div>
            <div class="trcl-modal-messages" id="trcl-modal-messages"></div>
        </div>
    </div>
</div>
