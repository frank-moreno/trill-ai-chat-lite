<?php
/**
 * Dashboard admin view.
 *
 * @package GspltdChatLite\Admin
 * @since 1.0.0
 * @license GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$usage_limiter = new \GspltdChatLite\Lite\UsageLimiter();
$stats         = $usage_limiter->getUsageStats();
$usage_percent = ( $stats['limit'] > 0 ) ? round( ( $stats['used'] / $stats['limit'] ) * 100 ) : 0;
$chat_enabled  = get_option( 'gcl_chat_enabled', '1' ) === '1';
?>

<div class="wrap gcl-dashboard">
    <h1><?php esc_html_e( 'GSPLTD AI Chat — Dashboard', 'gspltd-chat-lite' ); ?></h1>

    <!-- Status Card -->
    <div class="gcl-card" style="background: #fff; padding: 20px; border: 1px solid #c3c4c7; border-radius: 4px; margin: 20px 0;">
        <h2 style="margin-top: 0;"><?php esc_html_e( 'Chat Status', 'gspltd-chat-lite' ); ?></h2>
        <p>
            <?php if ( $chat_enabled ) : ?>
                <span style="color: #00a32a; font-weight: 600;">&#9679; <?php esc_html_e( 'Active', 'gspltd-chat-lite' ); ?></span>
                — <?php esc_html_e( 'The chat widget is visible on your store.', 'gspltd-chat-lite' ); ?>
            <?php else : ?>
                <span style="color: #d63638; font-weight: 600;">&#9679; <?php esc_html_e( 'Disabled', 'gspltd-chat-lite' ); ?></span>
                — <a href="<?php echo esc_url( admin_url( 'admin.php?page=gcl-settings' ) ); ?>"><?php esc_html_e( 'Enable in Settings', 'gspltd-chat-lite' ); ?></a>
            <?php endif; ?>
        </p>
    </div>

    <!-- Usage Card -->
    <div class="gcl-card" style="background: #fff; padding: 20px; border: 1px solid #c3c4c7; border-radius: 4px; margin: 20px 0;">
        <h2 style="margin-top: 0;"><?php esc_html_e( 'Monthly Usage', 'gspltd-chat-lite' ); ?></h2>

        <div style="display: flex; gap: 40px; align-items: center; margin: 16px 0;">
            <div>
                <div style="font-size: 36px; font-weight: 700; color: #1d2327;">
                    <?php echo esc_html( $stats['used'] ); ?> / <?php echo esc_html( $stats['limit'] ); ?>
                </div>
                <div style="color: #50575e; font-size: 13px;">
                    <?php esc_html_e( 'conversations this month', 'gspltd-chat-lite' ); ?>
                </div>
            </div>
            <div style="flex: 1; max-width: 300px;">
                <div style="background: #e5e7eb; border-radius: 9999px; height: 12px; overflow: hidden;">
                    <div style="background: <?php echo $usage_percent >= 80 ? '#d63638' : '#10B981'; ?>; width: <?php echo esc_attr( min( 100, $usage_percent ) ); ?>%; height: 100%; border-radius: 9999px; transition: width 0.3s;"></div>
                </div>
                <div style="color: #50575e; font-size: 12px; margin-top: 4px;">
                    <?php echo esc_html( $stats['remaining'] ); ?> <?php esc_html_e( 'remaining', 'gspltd-chat-lite' ); ?>
                </div>
            </div>
        </div>

        <?php if ( $usage_percent >= 80 ) : ?>
            <p style="background: #fef3cd; border: 1px solid #ffc107; padding: 10px 14px; border-radius: 4px; margin-top: 12px;">
                <?php esc_html_e( 'You are running low on free conversations.', 'gspltd-chat-lite' ); ?>
                <a href="<?php echo esc_url( \GspltdChatLite\Lite\LiteConfig::getUpgradeUrl( 'dashboard_usage' ) ); ?>" target="_blank" style="font-weight: 600;">
                    <?php esc_html_e( 'Upgrade for unlimited conversations &rarr;', 'gspltd-chat-lite' ); ?>
                </a>
            </p>
        <?php endif; ?>
    </div>

    <?php
    /**
     * Hook: gcl_after_dashboard_stats
     *
     * Fires after the dashboard stats cards.
     * Used by UpgradeNotices to render the upgrade comparison card.
     */
    do_action( 'gcl_after_dashboard_stats' );
    ?>

</div>
