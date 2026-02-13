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

$usage_limiter = new \TrillChatLite\Lite\UsageLimiter();
$stats         = $usage_limiter->getUsageStats();
$usage_percent = ( $stats['limit'] > 0 ) ? round( ( $stats['used'] / $stats['limit'] ) * 100 ) : 0;
$chat_enabled  = get_option( 'tcl_chat_enabled', '1' ) === '1';
?>

<div class="wrap tcl-dashboard">
    <h1><?php esc_html_e( 'Trill AI Chat — Dashboard', 'trill-chat-lite' ); ?></h1>

    <!-- Status Card -->
    <div class="tcl-card" style="background: #fff; padding: 20px; border: 1px solid #c3c4c7; border-radius: 4px; margin: 20px 0;">
        <h2 style="margin-top: 0;"><?php esc_html_e( 'Chat Status', 'trill-chat-lite' ); ?></h2>
        <p>
            <?php if ( $chat_enabled ) : ?>
                <span style="color: #00a32a; font-weight: 600;">&#9679; <?php esc_html_e( 'Active', 'trill-chat-lite' ); ?></span>
                — <?php esc_html_e( 'The chat widget is visible on your store.', 'trill-chat-lite' ); ?>
            <?php else : ?>
                <span style="color: #d63638; font-weight: 600;">&#9679; <?php esc_html_e( 'Disabled', 'trill-chat-lite' ); ?></span>
                — <a href="<?php echo esc_url( admin_url( 'admin.php?page=tcl-settings' ) ); ?>"><?php esc_html_e( 'Enable in Settings', 'trill-chat-lite' ); ?></a>
            <?php endif; ?>
        </p>
    </div>

    <!-- Usage Card -->
    <div class="tcl-card" style="background: #fff; padding: 20px; border: 1px solid #c3c4c7; border-radius: 4px; margin: 20px 0;">
        <h2 style="margin-top: 0;"><?php esc_html_e( 'Monthly Usage', 'trill-chat-lite' ); ?></h2>

        <div style="display: flex; gap: 40px; align-items: center; margin: 16px 0;">
            <div>
                <div style="font-size: 36px; font-weight: 700; color: #1d2327;">
                    <?php echo esc_html( $stats['used'] ); ?> / <?php echo esc_html( $stats['limit'] ); ?>
                </div>
                <div style="color: #50575e; font-size: 13px;">
                    <?php esc_html_e( 'conversations this month', 'trill-chat-lite' ); ?>
                </div>
            </div>
            <div style="flex: 1; max-width: 300px;">
                <div style="background: #e5e7eb; border-radius: 9999px; height: 12px; overflow: hidden;">
                    <div style="background: <?php echo $usage_percent >= 80 ? '#d63638' : '#10B981'; ?>; width: <?php echo esc_attr( min( 100, $usage_percent ) ); ?>%; height: 100%; border-radius: 9999px; transition: width 0.3s;"></div>
                </div>
                <div style="color: #50575e; font-size: 12px; margin-top: 4px;">
                    <?php echo esc_html( $stats['remaining'] ); ?> <?php esc_html_e( 'remaining', 'trill-chat-lite' ); ?>
                </div>
            </div>
        </div>

        <?php if ( $usage_percent >= 80 ) : ?>
            <p style="background: #fef3cd; border: 1px solid #ffc107; padding: 10px 14px; border-radius: 4px; margin-top: 12px;">
                <?php esc_html_e( 'You are running low on free conversations.', 'trill-chat-lite' ); ?>
                <a href="<?php echo esc_url( \TrillChatLite\Lite\LiteConfig::getUpgradeUrl( 'dashboard_usage' ) ); ?>" target="_blank" style="font-weight: 600;">
                    <?php esc_html_e( 'Upgrade for unlimited conversations &rarr;', 'trill-chat-lite' ); ?>
                </a>
            </p>
        <?php endif; ?>
    </div>

    <?php
    /**
     * Hook: tcl_after_dashboard_stats
     *
     * Fires after the dashboard stats cards.
     * Used by UpgradeNotices to render the upgrade comparison card.
     */
    do_action( 'tcl_after_dashboard_stats' );
    ?>

</div>
