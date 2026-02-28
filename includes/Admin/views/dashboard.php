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
    <h1><?php esc_html_e( 'Trill AI Chat — Dashboard', 'trill-ai-chat-lite' ); ?></h1>

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

    <!-- Service Info Card -->
    <div class="trcl-card" style="background: #fff; padding: 20px; border: 1px solid #c3c4c7; border-radius: 4px; margin: 20px 0;">
        <h2 style="margin-top: 0;"><?php esc_html_e( 'Service Information', 'trill-ai-chat-lite' ); ?></h2>
        <p>
            <?php esc_html_e( 'Chat conversations are processed by the Trill AI service. Usage limits are managed server-side.', 'trill-ai-chat-lite' ); ?>
        </p>
        <p>
            <a href="<?php echo esc_url( \TrillChatLite\Lite\LiteConfig::getUpgradeUrl( 'dashboard' ) ); ?>" target="_blank" class="button button-primary">
                <?php esc_html_e( 'Upgrade for unlimited conversations &rarr;', 'trill-ai-chat-lite' ); ?>
            </a>
        </p>
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
