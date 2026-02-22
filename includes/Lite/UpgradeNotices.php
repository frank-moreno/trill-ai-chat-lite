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
 * @package TrillChatLite\Lite
 * @since 1.0.0
 * @license GPL-2.0-or-later
 */

namespace TrillChatLite\Lite;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

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
        \add_action( 'trill_chat_lite_after_dashboard_stats', [ $this, 'renderUpgradeCard' ] );
        \add_action( 'trill_chat_lite_chat_widget_footer', [ $this, 'renderPoweredByBadge' ] );
    }

    /**
     * Dismissible admin notice (shown once per week, only when usage > 60%).
     */
    public function renderDashboardNotice(): void {
        $screen = \get_current_screen();
        if ( ! $screen || strpos( $screen->id, 'tclw-chat' ) === false ) {
            return;
        }

        $dismissed = \get_option( 'tclw_upgrade_notice_dismissed', 0 );
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
            '<div class="notice notice-info is-dismissible tcl-upgrade-notice" data-nonce="%s">
                <p><strong>%s</strong> %s <a href="%s" target="_blank">%s</a></p>
            </div>',
            esc_attr( \wp_create_nonce( 'tclw_dismiss_notice' ) ),
            esc_html__( 'Trill AI Chat:', 'trill-ai-chat-lite' ),
            sprintf(
                /* translators: %1$d: conversations used, %2$d: total limit */
                esc_html__( "You've used %1\$d of %2\$d free conversations this month.", 'trill-ai-chat-lite' ),
                absint( $stats['used'] ),
                absint( $stats['limit'] )
            ),
            esc_url( LiteConfig::getUpgradeUrl( 'admin_notice' ) ),
            esc_html__( 'Upgrade for unlimited conversations &rarr;', 'trill-ai-chat-lite' )
        );
    }

    /**
     * Upgrade card on dashboard page.
     */
    public function renderUpgradeCard(): void {
        include TRILL_CHAT_LITE_PLUGIN_DIR . 'includes/Admin/views/upgrade-card.php';
    }

    /**
     * "Powered by Trill AI" badge in chat widget footer.
     */
    public function renderPoweredByBadge(): void {
        if ( ! LiteConfig::SHOW_POWERED_BY ) {
            return;
        }

        printf(
            '<a href="%s" target="_blank" rel="noopener" class="tcl-powered-by">%s</a>',
            esc_url( LiteConfig::POWERED_BY_URL ),
            esc_html( LiteConfig::POWERED_BY_TEXT )
        );
    }
}
