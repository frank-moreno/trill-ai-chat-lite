<?php
/**
 * Dashboard admin view.
 *
 * @package TrillChatLite\Admin
 * @since 1.0.0
 * @license GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$trcl_chat_enabled = get_option( 'trcl_chat_enabled', '1' ) === '1';
?>

<div class="wrap trcl-dashboard">
    <h1><?php esc_html_e( 'Trill AI Product Chat — Dashboard', 'trill-ai-chat-lite' ); ?></h1>

    <!-- Status Card -->
    <div class="trcl-card" style="background: #fff; padding: 20px; border: 1px solid #c3c4c7; border-radius: 4px; margin: 20px 0;">
        <h2 style="margin-top: 0;"><?php esc_html_e( 'Chat Status', 'trill-ai-chat-lite' ); ?></h2>
        <p>
            <?php if ( $trcl_chat_enabled ) : ?>
                <span style="color: #00a32a; font-weight: 600;">&#9679; <?php esc_html_e( 'Active', 'trill-ai-chat-lite' ); ?></span>
                — <?php esc_html_e( 'The chat widget is visible on your store.', 'trill-ai-chat-lite' ); ?>
            <?php else : ?>
                <span style="color: #d63638; font-weight: 600;">&#9679; <?php esc_html_e( 'Disabled', 'trill-ai-chat-lite' ); ?></span>
                — <a href="<?php echo esc_url( admin_url( 'admin.php?page=trcl-settings' ) ); ?>"><?php esc_html_e( 'Enable in Settings', 'trill-ai-chat-lite' ); ?></a>
            <?php endif; ?>
        </p>
    </div>

    <!-- Monthly Usage Card -->
    <?php
    $trcl_db            = new \TrillChatLite\Database\DbManager();
    $trcl_monthly_limit = \TrillChatLite\Lite\LiteConfig::MONTHLY_LIMIT;

    // Prefer the server-authoritative count (X-Trill-Trial-Remaining,
    // captured on every successful chat). Fall back to the local
    // conversation-count approximation if the option is not set yet
    // (first activation, no chats yet, etc.).
    $trcl_server_remaining = \get_option(
        \TrillChatLite\Lite\LiteConfig::OPT_TRIAL_REMAINING,
        null
    );
    if ( $trcl_server_remaining !== null && is_numeric( $trcl_server_remaining ) ) {
        $trcl_monthly_count = max( 0, $trcl_monthly_limit - (int) $trcl_server_remaining );
        $trcl_count_source  = 'server';
    } else {
        $trcl_monthly_count = $trcl_db->get_monthly_conversation_count();
        $trcl_count_source  = 'local';
    }

    $trcl_usage_percent = min( 100, round( ( $trcl_monthly_count / max( 1, $trcl_monthly_limit ) ) * 100 ) );

    // Determine bar colour class based on thresholds.
    $trcl_bar_class = '';
    if ( $trcl_usage_percent >= 100 ) {
        $trcl_bar_class = ' trcl-usage-bar-fill--danger';
    } elseif ( $trcl_usage_percent >= 80 ) {
        $trcl_bar_class = ' trcl-usage-bar-fill--warning';
    }
    ?>
    <div class="trcl-status-card">
        <h2><?php esc_html_e( 'Monthly Usage', 'trill-ai-chat-lite' ); ?></h2>
        <p style="color: #50575e; margin-bottom: 16px;">
            <?php esc_html_e( 'Conversations used this month. Limits are managed by the Trill AI service.', 'trill-ai-chat-lite' ); ?>
        </p>

        <div class="trcl-usage-bar-container">
            <div class="trcl-usage-bar">
                <div class="trcl-usage-bar-fill<?php echo esc_attr( $trcl_bar_class ); ?>"
                     style="width: <?php echo esc_attr( $trcl_usage_percent ); ?>%;"
                     role="progressbar"
                     aria-valuenow="<?php echo esc_attr( $trcl_monthly_count ); ?>"
                     aria-valuemin="0"
                     aria-valuemax="<?php echo esc_attr( $trcl_monthly_limit ); ?>">
                </div>
            </div>
            <div class="trcl-usage-text">
                <span>
                    <?php
                    printf(
                        /* translators: 1: conversations used, 2: monthly limit */
                        esc_html__( '%1$d of %2$d conversations', 'trill-ai-chat-lite' ),
                        absint( $trcl_monthly_count ),
                        absint( $trcl_monthly_limit )
                    );
                    ?>
                </span>
                <span><?php echo esc_html( $trcl_usage_percent . '%' ); ?></span>
            </div>
        </div>

        <?php if ( $trcl_usage_percent >= 100 ) : ?>
            <div class="notice notice-error inline" style="margin: 16px 0 0;">
                <p>
                    <?php esc_html_e( 'You have reached your monthly conversation limit. New conversations will be declined until next month.', 'trill-ai-chat-lite' ); ?>
                </p>
            </div>
        <?php elseif ( $trcl_usage_percent >= 80 ) : ?>
            <div class="notice notice-warning inline" style="margin: 16px 0 0;">
                <p>
                    <?php
                    printf(
                        /* translators: %d: remaining conversations */
                        esc_html__( 'You have %d conversations remaining this month.', 'trill-ai-chat-lite' ),
                        absint( $trcl_monthly_limit - $trcl_monthly_count )
                    );
                    ?>
                </p>
            </div>
        <?php endif; ?>
    </div>

    <?php
    // -----------------------------------------------------------------
    // Performance — Block 3 KPIs (chats, orders, attribution, revenue).
    //
    // Reads from trcl_analytics_events. Hidden if WooCommerce is not
    // active — the order-related metrics are meaningless without it.
    // -----------------------------------------------------------------
    if ( function_exists( 'wc_get_product' ) ) :
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only period selector.
        $trcl_range = isset( $_GET['range'] ) ? (int) $_GET['range'] : 30;
        if ( ! in_array( $trcl_range, [ 7, 30, 90 ], true ) ) {
            $trcl_range = 30;
        }

        $trcl_analytics = new \TrillChatLite\Analytics\AnalyticsService();
        $trcl_summary   = $trcl_analytics->get_summary( $trcl_range );

        $trcl_currency = function_exists( 'get_woocommerce_currency_symbol' )
            ? html_entity_decode( \get_woocommerce_currency_symbol(), ENT_QUOTES | ENT_HTML5, 'UTF-8' )
            : '';

        $trcl_dash_base = admin_url( 'admin.php?page=trcl-chat' );
        $trcl_range_url = static function ( int $n ) use ( $trcl_dash_base ): string {
            return esc_url( add_query_arg( 'range', $n, $trcl_dash_base ) );
        };
        ?>
        <div class="trcl-perf-card" style="background: #fff; padding: 20px 24px; border: 1px solid #c3c4c7; border-radius: 4px; margin: 20px 0;">
            <div style="display: flex; justify-content: space-between; align-items: baseline; flex-wrap: wrap; gap: 12px; margin-bottom: 16px;">
                <h2 style="margin: 0;"><?php esc_html_e( 'Performance', 'trill-ai-chat-lite' ); ?></h2>
                <div class="trcl-period-selector" style="font-size: 13px;">
                    <span style="color: #6b7280; margin-right: 8px;"><?php esc_html_e( 'Last:', 'trill-ai-chat-lite' ); ?></span>
                    <?php foreach ( [ 7, 30, 90 ] as $trcl_opt ) :
                        $trcl_active = ( $trcl_opt === $trcl_range );
                        ?>
                        <a href="<?php echo $trcl_range_url( $trcl_opt ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped via esc_url() ?>"
                           style="padding: 4px 10px; margin: 0 2px; text-decoration: none; border-radius: 3px; <?php echo $trcl_active ? 'background:#2271b1;color:#fff;font-weight:600;' : 'color:#2271b1;'; ?>">
                            <?php
                            printf(
                                /* translators: %d: number of days */
                                esc_html__( '%d days', 'trill-ai-chat-lite' ),
                                (int) $trcl_opt
                            );
                            ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>

            <p style="color: #6b7280; margin: 0 0 18px; max-width: 720px;">
                <?php esc_html_e( 'Activity over the selected window. An order is "from chat" when the customer had a chat conversation in the 24 hours before placing the order.', 'trill-ai-chat-lite' ); ?>
            </p>

            <div class="trcl-kpi-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 16px;">

                <!-- Card: Chats started -->
                <div class="trcl-kpi-card" style="border: 1px solid #e5e7eb; border-radius: 6px; padding: 18px 20px; background: #f9fafb;">
                    <div style="font-size: 12px; color: #6b7280; text-transform: uppercase; letter-spacing: 0.04em; margin-bottom: 6px;">
                        <?php esc_html_e( 'Chats started', 'trill-ai-chat-lite' ); ?>
                    </div>
                    <div style="font-size: 32px; font-weight: 700; color: #111827; line-height: 1;">
                        <?php echo esc_html( number_format_i18n( (int) $trcl_summary['chats_started'] ) ); ?>
                    </div>
                </div>

                <!-- Card: Orders completed -->
                <div class="trcl-kpi-card" style="border: 1px solid #e5e7eb; border-radius: 6px; padding: 18px 20px; background: #f9fafb;">
                    <div style="font-size: 12px; color: #6b7280; text-transform: uppercase; letter-spacing: 0.04em; margin-bottom: 6px;">
                        <?php esc_html_e( 'Orders completed', 'trill-ai-chat-lite' ); ?>
                    </div>
                    <div style="font-size: 32px; font-weight: 700; color: #111827; line-height: 1;">
                        <?php echo esc_html( number_format_i18n( (int) $trcl_summary['orders_completed'] ) ); ?>
                    </div>
                </div>

                <!-- Card: Orders from chat (attributed) -->
                <div class="trcl-kpi-card" style="border: 1px solid #c7e9cf; border-radius: 6px; padding: 18px 20px; background: #f0f9f3;">
                    <div style="font-size: 12px; color: #166534; text-transform: uppercase; letter-spacing: 0.04em; margin-bottom: 6px;">
                        <?php esc_html_e( 'Orders from chat', 'trill-ai-chat-lite' ); ?>
                    </div>
                    <div style="font-size: 32px; font-weight: 700; color: #14532d; line-height: 1;">
                        <?php echo esc_html( number_format_i18n( (int) $trcl_summary['orders_attributed'] ) ); ?>
                    </div>
                    <div style="font-size: 12px; color: #166534; margin-top: 6px;">
                        <?php
                        /* translators: %s: attribution rate as a percentage */
                        printf( esc_html__( '%s%% attribution rate', 'trill-ai-chat-lite' ), esc_html( (string) $trcl_summary['attribution_rate'] ) );
                        ?>
                    </div>
                </div>

                <!-- Card: Revenue from chat -->
                <div class="trcl-kpi-card" style="border: 1px solid #c7e9cf; border-radius: 6px; padding: 18px 20px; background: #f0f9f3;">
                    <div style="font-size: 12px; color: #166534; text-transform: uppercase; letter-spacing: 0.04em; margin-bottom: 6px;">
                        <?php esc_html_e( 'Revenue from chat', 'trill-ai-chat-lite' ); ?>
                    </div>
                    <div style="font-size: 32px; font-weight: 700; color: #14532d; line-height: 1;">
                        <?php echo esc_html( $trcl_currency . number_format_i18n( (float) $trcl_summary['revenue_attributed'], 2 ) ); ?>
                    </div>
                </div>

            </div>

            <?php if ( (int) $trcl_summary['chats_started'] === 0 ) : ?>
                <p style="margin: 18px 0 0; font-size: 13px; color: #6b7280;">
                    <?php esc_html_e( 'No chat activity yet in this window. Once shoppers start chatting, you\'ll see chats, attributed orders, and revenue here.', 'trill-ai-chat-lite' ); ?>
                </p>
            <?php endif; ?>
        </div>
        <?php
    endif;
    ?>

</div>
