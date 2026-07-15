<?php
/**
 * Leads admin view (v2.0 Block 4 slice 2).
 *
 * Lists every email captured by Robin during chat conversations, with
 * per-row actions (mark contacted, erase) and a CSV export of the
 * full table.
 *
 * Filtering supported via querystring:
 *   ?status=new|contacted|erased
 *   ?intent=out_of_stock|price_drop|generic
 *   ?search=<email substring>
 *   ?paged=<n>
 *
 * Erasure is GDPR-cascading: clicking "Erase" delegates to
 * LeadCaptureService::erase_by_email which hard-deletes every row for
 * that email so the same email isn't left half-erased.
 *
 * @package TrillChatLite\Admin
 * @since 2.0.0
 * @license GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$trcl_svc = new \TrillChatLite\Leads\LeadCaptureService();

// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only filter routing.
$trcl_status_filter = isset( $_GET['status'] ) ? \sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
$trcl_intent_filter = isset( $_GET['intent'] ) ? \sanitize_key( wp_unslash( $_GET['intent'] ) ) : '';
$trcl_search        = isset( $_GET['search'] ) ? \sanitize_text_field( wp_unslash( $_GET['search'] ) ) : '';
$trcl_paged         = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;
// phpcs:enable

if ( ! in_array( $trcl_status_filter, [ 'new', 'contacted', 'erased' ], true ) ) {
    $trcl_status_filter = '';
}
if ( ! in_array( $trcl_intent_filter, [ 'out_of_stock', 'price_drop', 'generic' ], true ) ) {
    $trcl_intent_filter = '';
}

$trcl_filters = [];
if ( $trcl_status_filter ) {
    $trcl_filters['status'] = $trcl_status_filter;
}
if ( $trcl_intent_filter ) {
    $trcl_filters['intent_type'] = $trcl_intent_filter;
}
if ( $trcl_search ) {
    $trcl_filters['search'] = $trcl_search;
}

$trcl_per_page = 20;
$trcl_result   = $trcl_svc->list_paginated( $trcl_filters, $trcl_paged, $trcl_per_page );
$trcl_rows     = $trcl_result['rows'];
$trcl_total    = $trcl_result['total'];
$trcl_pages    = max( 1, (int) ceil( $trcl_total / $trcl_per_page ) );

$trcl_action_url = \admin_url( 'admin-post.php' );

// Transient notice from a previous lead action.
$trcl_notice = \get_transient( 'trcl_lead_action_notice' );
if ( $trcl_notice ) {
    \delete_transient( 'trcl_lead_action_notice' );
}

$trcl_status_options = [
    ''          => __( 'All statuses', 'trill-ai-chat-lite' ),
    'new'       => __( 'New', 'trill-ai-chat-lite' ),
    'contacted' => __( 'Contacted', 'trill-ai-chat-lite' ),
    'erased'    => __( 'Erased', 'trill-ai-chat-lite' ),
];
$trcl_intent_options = [
    ''             => __( 'All intents', 'trill-ai-chat-lite' ),
    'out_of_stock' => __( 'Out of stock', 'trill-ai-chat-lite' ),
    'price_drop'   => __( 'Price drop', 'trill-ai-chat-lite' ),
    'generic'      => __( 'Generic', 'trill-ai-chat-lite' ),
];
?>

<div class="wrap tcl-leads-page">
    <h1 style="display: flex; align-items: center; gap: 16px;">
        <span><?php esc_html_e( 'Trill AI Chat — Leads', 'trill-ai-chat-lite' ); ?></span>
        <span style="font-size: 13px; color: #6b7280; font-weight: normal;">
            <?php
            /* translators: %d: total leads */
            printf( esc_html__( '%d leads captured', 'trill-ai-chat-lite' ), (int) $trcl_total );
            ?>
        </span>
    </h1>

    <?php if ( is_array( $trcl_notice ) && isset( $trcl_notice['type'], $trcl_notice['message'] ) ) : ?>
        <div class="notice notice-<?php echo esc_attr( $trcl_notice['type'] ); ?> is-dismissible">
            <p><?php echo esc_html( $trcl_notice['message'] ); ?></p>
        </div>
    <?php endif; ?>

    <p class="description" style="max-width: 720px;">
        <?php
        esc_html_e(
            'Emails opted in by visitors during a chat — typically "notify me when this is back in stock" or "let me know if you have a sale". The exact consent text shown at capture time is stored alongside each row for compliance.',
            'trill-ai-chat-lite'
        );
        ?>
    </p>

    <!-- Filter + export bar -->
    <div style="background: #fff; border: 1px solid #c3c4c7; border-radius: 4px; padding: 12px 16px; margin: 16px 0; display: flex; flex-wrap: wrap; align-items: center; gap: 12px;">

        <form method="get" action="" style="display: flex; flex-wrap: wrap; align-items: center; gap: 8px; margin: 0;">
            <input type="hidden" name="page" value="trcl-leads" />

            <select name="status">
                <?php foreach ( $trcl_status_options as $trcl_v => $trcl_l ) : ?>
                    <option value="<?php echo esc_attr( $trcl_v ); ?>" <?php selected( $trcl_status_filter, $trcl_v ); ?>>
                        <?php echo esc_html( $trcl_l ); ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <select name="intent">
                <?php foreach ( $trcl_intent_options as $trcl_v => $trcl_l ) : ?>
                    <option value="<?php echo esc_attr( $trcl_v ); ?>" <?php selected( $trcl_intent_filter, $trcl_v ); ?>>
                        <?php echo esc_html( $trcl_l ); ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <input type="search" name="search" value="<?php echo esc_attr( $trcl_search ); ?>"
                   placeholder="<?php esc_attr_e( 'Search by email...', 'trill-ai-chat-lite' ); ?>"
                   class="regular-text" style="min-width: 220px;" />

            <button type="submit" class="button"><?php esc_html_e( 'Filter', 'trill-ai-chat-lite' ); ?></button>

            <?php if ( $trcl_status_filter || $trcl_intent_filter || $trcl_search ) : ?>
                <a class="button-link"
                   href="<?php echo esc_url( admin_url( 'admin.php?page=trcl-leads' ) ); ?>"
                   style="margin-left: 4px;">
                    <?php esc_html_e( 'Clear', 'trill-ai-chat-lite' ); ?>
                </a>
            <?php endif; ?>
        </form>

        <span style="flex: 1;"></span>

        <form method="post" action="<?php echo esc_url( $trcl_action_url ); ?>" style="margin: 0;">
            <input type="hidden" name="action" value="trcl_leads_export" />
            <?php wp_nonce_field( 'trcl_leads_export' ); ?>
            <button type="submit" class="button button-secondary">
                <?php esc_html_e( 'Export CSV', 'trill-ai-chat-lite' ); ?>
            </button>
        </form>
    </div>

    <!-- Table -->
    <?php if ( empty( $trcl_rows ) ) : ?>
        <div style="background: #fff; border: 1px solid #c3c4c7; border-radius: 4px; padding: 24px; text-align: center; color: #6b7280;">
            <?php esc_html_e( 'No leads captured yet that match these filters.', 'trill-ai-chat-lite' ); ?>
        </div>
    <?php else : ?>
        <table class="widefat striped">
            <thead>
                <tr>
                    <th scope="col"><?php esc_html_e( 'Email', 'trill-ai-chat-lite' ); ?></th>
                    <th scope="col"><?php esc_html_e( 'Intent', 'trill-ai-chat-lite' ); ?></th>
                    <th scope="col"><?php esc_html_e( 'Product', 'trill-ai-chat-lite' ); ?></th>
                    <th scope="col"><?php esc_html_e( 'Captured', 'trill-ai-chat-lite' ); ?></th>
                    <th scope="col"><?php esc_html_e( 'Status', 'trill-ai-chat-lite' ); ?></th>
                    <th scope="col" style="text-align: right;"><?php esc_html_e( 'Actions', 'trill-ai-chat-lite' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $trcl_rows as $trcl_row ) :
                    $trcl_lead_id  = (int) $trcl_row->id;
                    $trcl_email    = (string) $trcl_row->email;
                    $trcl_intent   = (string) $trcl_row->intent_type;
                    $trcl_pid      = (int) $trcl_row->product_id;
                    $trcl_status   = (string) $trcl_row->status;
                    $trcl_captured = (string) $trcl_row->captured_at;

                    $trcl_intent_pretty = $trcl_intent_options[ $trcl_intent ] ?? $trcl_intent;
                    $trcl_status_pretty = $trcl_status_options[ $trcl_status ] ?? $trcl_status;

                    $trcl_product_link = '';
                    if ( $trcl_pid > 0 ) {
                        $trcl_product_link = \get_edit_post_link( $trcl_pid );
                        $trcl_product_name = \get_the_title( $trcl_pid );
                    }
                ?>
                    <tr>
                        <td>
                            <strong><?php echo esc_html( $trcl_email ); ?></strong>
                        </td>
                        <td><?php echo esc_html( $trcl_intent_pretty ); ?></td>
                        <td>
                            <?php if ( $trcl_pid > 0 ) : ?>
                                <?php if ( $trcl_product_link ) : ?>
                                    <a href="<?php echo esc_url( $trcl_product_link ); ?>">
                                        <?php echo esc_html( $trcl_product_name ?: ( '#' . $trcl_pid ) ); ?>
                                    </a>
                                <?php else : ?>
                                    #<?php echo (int) $trcl_pid; ?>
                                <?php endif; ?>
                            <?php else : ?>
                                <span style="color: #9ca3af;">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span title="<?php echo esc_attr( $trcl_captured ); ?>">
                                <?php echo esc_html( \human_time_diff( strtotime( $trcl_captured ), time() ) ); ?>
                                <?php esc_html_e( 'ago', 'trill-ai-chat-lite' ); ?>
                            </span>
                        </td>
                        <td>
                            <span class="trcl-status-badge trcl-status-<?php echo esc_attr( $trcl_status ); ?>"
                                  style="
                                    padding: 2px 8px; border-radius: 12px; font-size: 11px; font-weight: 600;
                                    background: <?php echo $trcl_status === 'new' ? '#dbeafe' : ( $trcl_status === 'contacted' ? '#dcfce7' : '#f3f4f6' ); ?>;
                                    color:     <?php echo $trcl_status === 'new' ? '#1e40af' : ( $trcl_status === 'contacted' ? '#166534' : '#6b7280' ); ?>;
                                  ">
                                <?php echo esc_html( $trcl_status_pretty ); ?>
                            </span>
                        </td>
                        <td style="text-align: right; white-space: nowrap;">
                            <?php if ( $trcl_status === 'new' ) : ?>
                                <form method="post" action="<?php echo esc_url( $trcl_action_url ); ?>"
                                      style="display: inline;">
                                    <input type="hidden" name="action" value="trcl_lead_action" />
                                    <input type="hidden" name="lead_id" value="<?php echo (int) $trcl_lead_id; ?>" />
                                    <input type="hidden" name="lead_op" value="contacted" />
                                    <?php wp_nonce_field( 'trcl_lead_action_' . $trcl_lead_id ); ?>
                                    <button type="submit" class="button button-small">
                                        <?php esc_html_e( 'Mark contacted', 'trill-ai-chat-lite' ); ?>
                                    </button>
                                </form>
                            <?php endif; ?>

                            <form method="post" action="<?php echo esc_url( $trcl_action_url ); ?>"
                                  style="display: inline;"
                                  onsubmit="return confirm('<?php echo esc_js( __( 'Erase ALL lead rows for this email? This cannot be undone.', 'trill-ai-chat-lite' ) ); ?>');">
                                <input type="hidden" name="action" value="trcl_lead_action" />
                                <input type="hidden" name="lead_id" value="<?php echo (int) $trcl_lead_id; ?>" />
                                <input type="hidden" name="lead_op" value="erase" />
                                <?php wp_nonce_field( 'trcl_lead_action_' . $trcl_lead_id ); ?>
                                <button type="submit" class="button button-small button-link-delete"
                                        style="color: #b32d2e;">
                                    <?php esc_html_e( 'Erase', 'trill-ai-chat-lite' ); ?>
                                </button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <?php if ( $trcl_pages > 1 ) : ?>
            <div class="tablenav" style="margin-top: 16px;">
                <div class="tablenav-pages">
                    <?php
                    $trcl_base_args = [ 'page' => 'trcl-leads' ];
                    if ( $trcl_status_filter ) {
                        $trcl_base_args['status'] = $trcl_status_filter;
                    }
                    if ( $trcl_intent_filter ) {
                        $trcl_base_args['intent'] = $trcl_intent_filter;
                    }
                    if ( $trcl_search ) {
                        $trcl_base_args['search'] = $trcl_search;
                    }

                    // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- paginate_links() returns sanitised <a>/<span> markup; running it through esc_html would double-encode the entities and break the pagination links.
                    echo \paginate_links( [
                        'base'      => \add_query_arg( $trcl_base_args, \admin_url( 'admin.php' ) ) . '%_%',
                        'format'    => '&paged=%#%',
                        'current'   => $trcl_paged,
                        'total'     => $trcl_pages,
                        'prev_text' => '&laquo;',
                        'next_text' => '&raquo;',
                    ] );
                    ?>
                </div>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>
