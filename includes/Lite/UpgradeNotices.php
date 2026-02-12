<?php
/**
 * Renders upgrade CTAs throughout admin.
 *
 * WordPress.org policy compliance:
 * - No more than 1 persistent admin notice
 * - No popup/modal upsells
 * - CTAs must be clearly dismissible
 * - No fake urgency
 *
 * @package GspltdChatLite\Lite
 * @since 1.0.0
 * @license GPL-2.0-or-later
 */

namespace GspltdChatLite\Lite;

/**
 * Upgrade Notices — Lite CTA system.
 *
 * SOLID: Single Responsibility — only upgrade CTAs.
 */
class UpgradeNotices {

    /**
     * Initialise hooks.
     */
    public function init(): void {
        \add_action( 'admin_notices', [ $this, 'renderDashboardNotice' ] );
        \add_action( 'gcl_after_dashboard_stats', [ $this, 'renderUpgradeCard' ] );
        \add_action( 'gcl_chat_widget_footer', [ $this, 'renderPoweredByBadge' ] );
    }

    /**
     * Dismissible admin notice (shown once per week, only when usage > 60%).
     */
    public function renderDashboardNotice(): void {
        $screen = \get_current_screen();
        if ( ! $screen || strpos( $screen->id, 'gcl-chat' ) === false ) {
            return;
        }

        $dismissed = \get_option( 'gcl_upgrade_notice_dismissed', 0 );
        if ( $dismissed && ( time() - $dismissed ) < WEEK_IN_SECONDS ) {
            return;
        }

        $stats         = ( new UsageLimiter() )->getUsageStats();
        $usage_percent = ( $stats['limit'] > 0 ) ? round( ( $stats['used'] / $stats['limit'] ) * 100 ) : 0;

        // Only show when usage > 60%.
        if ( $usage_percent < 60 ) {
            return;
        }

        printf(
            '<div class="notice notice-info is-dismissible gcl-upgrade-notice" data-nonce="%s">
                <p><strong>%s</strong> %s <a href="%s" target="_blank">%s</a></p>
            </div>',
            esc_attr( \wp_create_nonce( 'gcl_dismiss_notice' ) ),
            esc_html__( 'GSPLTD AI Chat:', 'gspltd-chat-lite' ),
            sprintf(
                /* translators: %1$d: conversations used, %2$d: total limit */
                esc_html__( "You've used %1\$d of %2\$d free conversations this month.", 'gspltd-chat-lite' ),
                $stats['used'],
                $stats['limit']
            ),
            esc_url( LiteConfig::getUpgradeUrl( 'admin_notice' ) ),
            esc_html__( 'Upgrade for unlimited conversations &rarr;', 'gspltd-chat-lite' )
        );
    }

    /**
     * Upgrade card on dashboard page.
     */
    public function renderUpgradeCard(): void {
        include GCL_PLUGIN_DIR . 'includes/Admin/views/upgrade-card.php';
    }

    /**
     * "Powered by GSPLTD" badge in chat widget footer.
     */
    public function renderPoweredByBadge(): void {
        if ( ! LiteConfig::SHOW_POWERED_BY ) {
            return;
        }

        printf(
            '<a href="%s" target="_blank" rel="noopener" class="gcl-powered-by">%s</a>',
            esc_url( LiteConfig::POWERED_BY_URL ),
            esc_html( LiteConfig::POWERED_BY_TEXT )
        );
    }
}
