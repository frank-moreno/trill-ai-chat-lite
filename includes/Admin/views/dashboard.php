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
    $trcl_db             = new \TrillChatLite\Database\DbManager();
    $trcl_monthly_count  = $trcl_db->get_monthly_conversation_count();
    $trcl_monthly_limit  = \TrillChatLite\Lite\LiteConfig::MONTHLY_LIMIT;
    $trcl_usage_percent  = min( 100, round( ( $trcl_monthly_count / max( 1, $trcl_monthly_limit ) ) * 100 ) );

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
                    <a href="<?php echo esc_url( \TrillChatLite\Lite\LiteConfig::getUpgradeUrl( 'usage_limit' ) ); ?>" target="_blank">
                        <?php esc_html_e( 'Upgrade for more conversations &rarr;', 'trill-ai-chat-lite' ); ?>
                    </a>
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
                    <a href="<?php echo esc_url( \TrillChatLite\Lite\LiteConfig::getUpgradeUrl( 'usage_warning' ) ); ?>" target="_blank">
                        <?php esc_html_e( 'Upgrade now', 'trill-ai-chat-lite' ); ?>
                    </a>
                </p>
            </div>
        <?php else : ?>
            <p style="margin: 12px 0 0;">
                <a href="<?php echo esc_url( \TrillChatLite\Lite\LiteConfig::getUpgradeUrl( 'dashboard' ) ); ?>" target="_blank" class="button button-primary">
                    <?php esc_html_e( 'Upgrade for unlimited conversations &rarr;', 'trill-ai-chat-lite' ); ?>
                </a>
            </p>
        <?php endif; ?>
    </div>

    <?php
    /**
     * Hook: trcl_after_dashboard_stats
     *
     * Fires after the dashboard stats cards.
     * Used by UpgradeNotices to render the upgrade comparison card.
     */
    do_action( 'trcl_after_dashboard_stats' );
    ?>

</div>
