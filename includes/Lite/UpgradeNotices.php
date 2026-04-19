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
        \add_action( 'trcl_after_dashboard_stats', [ $this, 'renderUpgradeCard' ] );
        \add_action( 'trcl_chat_widget_footer', [ $this, 'renderPoweredByBadge' ] );
    }

    /**
     * Dismissible admin notice (shown once per week).
     *
     * No local usage tracking — limits enforced server-side by proxy.
     */
    public function renderDashboardNotice(): void {
        $screen = \get_current_screen();
        if ( ! $screen || strpos( $screen->id, 'trcl-chat' ) === false ) {
            return;
        }

        $dismissed = \get_option( 'trcl_upgrade_notice_dismissed', 0 );
        if ( $dismissed && ( time() - $dismissed ) < WEEK_IN_SECONDS ) {
            return;
        }

        printf(
            '<div class="notice notice-info is-dismissible trcl-upgrade-notice" data-nonce="%s">
                <p><strong>%s</strong> %s <a href="%s" target="_blank">%s</a></p>
            </div>',
            esc_attr( \wp_create_nonce( 'trcl_dismiss_notice' ) ),
            esc_html__( 'AI Shopping Assistant:', 'trill-ai-chat-lite' ),
            esc_html__( 'Unlock unlimited conversations, order tracking, and analytics.', 'trill-ai-chat-lite' ),
            esc_url( LiteConfig::getUpgradeUrl( 'admin_notice' ) ),
            esc_html__( 'Upgrade Now &rarr;', 'trill-ai-chat-lite' )
        );
    }

    /**
     * Upgrade card on dashboard page.
     */
    public function renderUpgradeCard(): void {
        include TRCL_PLUGIN_DIR . 'includes/Admin/views/upgrade-card.php';
    }

    /**
     * "Powered by Trill AI" badge in chat widget footer.
     *
     * Opt-in only (OFF by default) per WordPress.org Guideline 11.
     */
    public function renderPoweredByBadge(): void {
        if ( ! LiteConfig::get_show_powered_by() ) {
            return;
        }

        printf(
            '<a href="%s" target="_blank" rel="noopener" class="trcl-powered-by">%s</a>',
            esc_url( LiteConfig::get_powered_by_url() ),
            esc_html( LiteConfig::POWERED_BY_TEXT )
        );
    }
}
