<?php
/**
 * About page (v2.0 Block 6).
 *
 * Sections rendered:
 *   1. Hero        — Trill AI branding + plugin version + status badge.
 *   2. What's new  — five-bullet summary of v2.0 user-facing features.
 *   3. Built by    — Francisco Carracedo dev credit + portfolio links.
 *   4. Help        — WP.org forum link + email + documentation URL.
 *   5. Footer      — license + company info.
 *
 * No forms here — purely informational. Internationalisation strings
 * are wrapped in __() so the merchant's locale can override them.
 *
 * @package TrillChatLite\Admin
 * @since 2.0.0
 * @license GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$trcl_version       = defined( 'TRCL_VERSION' ) ? TRCL_VERSION : '2.0.0';
$trcl_chat_enabled  = \get_option( 'trcl_chat_enabled', '1' ) === '1';
$trcl_dev_portfolio = 'https://francisco-carracedo.com';
$trcl_dev_linkedin  = 'https://www.linkedin.com/in/francisco-carracedo/';
$trcl_dev_github    = 'https://github.com/frank-moreno';
$trcl_support_email = 'hello@trillai.io';
$trcl_docs_url      = 'https://trillai.io/documentation/';
$trcl_forum_url     = 'https://wordpress.org/support/plugin/trill-ai-chat-lite/';
$trcl_company       = 'Greensolutions Pioneers Limited';
$trcl_company_no    = '15693716';
?>

<div class="wrap tcl-about-page" style="max-width: 960px;">

    <!-- ============================================================
         Hero
         ============================================================ -->
    <div style="background: linear-gradient(135deg, #10B981 0%, #0d9668 100%); color: #fff; padding: 32px 36px; border-radius: 8px; margin: 20px 0; box-shadow: 0 2px 8px rgba(0,0,0,0.08);">
        <div style="display: flex; align-items: center; gap: 16px; flex-wrap: wrap;">
            <h1 style="margin: 0; color: #fff; font-size: 28px; font-weight: 700;">
                <?php esc_html_e( 'Trill AI Product Chat for WooCommerce', 'trill-ai-chat-lite' ); ?>
            </h1>
            <span style="background: rgba(255,255,255,0.2); padding: 4px 12px; border-radius: 14px; font-size: 13px; font-weight: 600;">
                v<?php echo esc_html( $trcl_version ); ?>
            </span>
            <span style="background: <?php echo $trcl_chat_enabled ? 'rgba(16,185,129,0.25)' : 'rgba(239,68,68,0.25)'; ?>; padding: 4px 12px; border-radius: 14px; font-size: 13px; font-weight: 600;">
                <?php echo $trcl_chat_enabled
                    ? '● ' . esc_html__( 'Active', 'trill-ai-chat-lite' )
                    : '● ' . esc_html__( 'Disabled', 'trill-ai-chat-lite' ); ?>
            </span>
        </div>
        <p style="margin: 14px 0 0; font-size: 16px; opacity: 0.95; line-height: 1.5; max-width: 720px;">
            <?php
            esc_html_e(
                'An AI shopping assistant designed for WooCommerce stores. Robin answers customer questions, recommends products, tracks orders, and captures leads — using your real catalogue, your real pages, and your customer\'s real cart.',
                'trill-ai-chat-lite'
            );
            ?>
        </p>
    </div>

    <!-- ============================================================
         What's new in 2.0
         ============================================================ -->
    <div style="background: #fff; padding: 28px 32px; border: 1px solid #c3c4c7; border-radius: 8px; margin: 20px 0;">
        <h2 style="margin: 0 0 6px; font-size: 22px;">
            <?php esc_html_e( "What's new in 2.0", 'trill-ai-chat-lite' ); ?>
        </h2>
        <p style="color: #6b7280; margin: 0 0 20px;">
            <?php esc_html_e( 'Five user-facing features that turn Robin from a product-only chatbot into a full-stack store assistant.', 'trill-ai-chat-lite' ); ?>
        </p>

        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 16px;">

            <!-- Page content indexing -->
            <div style="border-left: 3px solid #10B981; padding: 4px 0 4px 14px;">
                <h3 style="margin: 0 0 4px; font-size: 14px; font-weight: 600;">
                    <?php esc_html_e( 'Page content indexing', 'trill-ai-chat-lite' ); ?>
                </h3>
                <p style="margin: 0; font-size: 13px; color: #4b5563; line-height: 1.5;">
                    <?php esc_html_e( 'Robin can answer questions about your FAQ, shipping, returns, and other pages — not just products.', 'trill-ai-chat-lite' ); ?>
                </p>
            </div>

            <!-- GDPR -->
            <div style="border-left: 3px solid #10B981; padding: 4px 0 4px 14px;">
                <h3 style="margin: 0 0 4px; font-size: 14px; font-weight: 600;">
                    <?php esc_html_e( 'GDPR-ready', 'trill-ai-chat-lite' ); ?>
                </h3>
                <p style="margin: 0; font-size: 13px; color: #4b5563; line-height: 1.5;">
                    <?php esc_html_e( 'DSAR exports and right-to-erasure work end-to-end through WordPress Tools → Personal Data. Configurable retention.', 'trill-ai-chat-lite' ); ?>
                </p>
            </div>

            <!-- ROI analytics -->
            <div style="border-left: 3px solid #10B981; padding: 4px 0 4px 14px;">
                <h3 style="margin: 0 0 4px; font-size: 14px; font-weight: 600;">
                    <?php esc_html_e( 'Cart-aware chat + ROI analytics', 'trill-ai-chat-lite' ); ?>
                </h3>
                <p style="margin: 0; font-size: 13px; color: #4b5563; line-height: 1.5;">
                    <?php esc_html_e( 'Robin knows what is in the customer\'s cart. The dashboard shows orders and revenue attributed to chat conversations.', 'trill-ai-chat-lite' ); ?>
                </p>
            </div>

            <!-- Lead capture -->
            <div style="border-left: 3px solid #10B981; padding: 4px 0 4px 14px;">
                <h3 style="margin: 0 0 4px; font-size: 14px; font-weight: 600;">
                    <?php esc_html_e( 'Lead capture', 'trill-ai-chat-lite' ); ?>
                </h3>
                <p style="margin: 0; font-size: 13px; color: #4b5563; line-height: 1.5;">
                    <?php esc_html_e( 'When a product is out of stock or a visitor hesitates on price, Robin offers to take their email — with explicit, audited consent.', 'trill-ai-chat-lite' ); ?>
                </p>
            </div>

            <!-- Order tracking -->
            <div style="border-left: 3px solid #10B981; padding: 4px 0 4px 14px;">
                <h3 style="margin: 0 0 4px; font-size: 14px; font-weight: 600;">
                    <?php esc_html_e( 'Verified order tracking', 'trill-ai-chat-lite' ); ?>
                </h3>
                <p style="margin: 0; font-size: 13px; color: #4b5563; line-height: 1.5;">
                    <?php esc_html_e( 'Customers can ask "where is my order?". Identity is verified before any order data is shared, by user account or email match.', 'trill-ai-chat-lite' ); ?>
                </p>
            </div>

        </div>
    </div>

    <!-- ============================================================
         Built by Francisco Carracedo
         ============================================================ -->
    <div style="background: #fff; padding: 28px 32px; border: 1px solid #c3c4c7; border-radius: 8px; margin: 20px 0;">
        <h2 style="margin: 0 0 6px; font-size: 22px;">
            <?php esc_html_e( 'Built by Francisco Carracedo', 'trill-ai-chat-lite' ); ?>
        </h2>
        <p style="color: #6b7280; margin: 0 0 16px; max-width: 720px; line-height: 1.6;">
            <?php
            esc_html_e(
                'Francisco Carracedo is a WordPress freelance developer specialising in WooCommerce, AI integrations, and merchant-focused tooling. Trill AI Chat is part of his Trill AI product line; he also takes on contract work for stores that need custom plugin or integration development.',
                'trill-ai-chat-lite'
            );
            ?>
        </p>

        <div style="display: flex; flex-wrap: wrap; gap: 10px;">
            <a href="<?php echo esc_url( $trcl_dev_portfolio ); ?>"
               target="_blank" rel="noopener"
               class="button button-primary"
               style="background: #111827; border-color: #111827;">
                <?php esc_html_e( 'Portfolio →', 'trill-ai-chat-lite' ); ?>
            </a>
            <a href="<?php echo esc_url( $trcl_dev_linkedin ); ?>"
               target="_blank" rel="noopener"
               class="button">
                <?php esc_html_e( 'LinkedIn', 'trill-ai-chat-lite' ); ?>
            </a>
            <a href="<?php echo esc_url( $trcl_dev_github ); ?>"
               target="_blank" rel="noopener"
               class="button">
                <?php esc_html_e( 'GitHub', 'trill-ai-chat-lite' ); ?>
            </a>
        </div>

        <p style="margin: 16px 0 0; font-size: 13px; color: #6b7280;">
            <?php esc_html_e( 'Need a custom integration, a bespoke chatbot, or other WordPress development work? Get in touch via portfolio or LinkedIn.', 'trill-ai-chat-lite' ); ?>
        </p>
    </div>

    <!-- ============================================================
         Help & Support
         ============================================================ -->
    <div style="background: #f0f9f3; padding: 28px 32px; border: 1px solid #c7e9cf; border-radius: 8px; margin: 20px 0;">
        <h2 style="margin: 0 0 6px; font-size: 22px; color: #14532d;">
            <?php esc_html_e( 'Need help?', 'trill-ai-chat-lite' ); ?>
        </h2>
        <p style="color: #166534; margin: 0 0 16px; max-width: 720px; line-height: 1.6;">
            <?php esc_html_e( 'A few ways to get unstuck:', 'trill-ai-chat-lite' ); ?>
        </p>

        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 16px;">

            <div>
                <h3 style="margin: 0 0 6px; font-size: 14px; font-weight: 600; color: #14532d;">
                    <?php esc_html_e( 'Documentation', 'trill-ai-chat-lite' ); ?>
                </h3>
                <p style="margin: 0 0 6px; font-size: 13px; color: #166534;">
                    <?php esc_html_e( 'Setup guides, FAQs, troubleshooting.', 'trill-ai-chat-lite' ); ?>
                </p>
                <a href="<?php echo esc_url( $trcl_docs_url ); ?>"
                   target="_blank" rel="noopener"
                   style="color: #14532d; font-weight: 600;">
                    trillai.io/documentation →
                </a>
            </div>

            <div>
                <h3 style="margin: 0 0 6px; font-size: 14px; font-weight: 600; color: #14532d;">
                    <?php esc_html_e( 'WordPress.org forum', 'trill-ai-chat-lite' ); ?>
                </h3>
                <p style="margin: 0 0 6px; font-size: 13px; color: #166534;">
                    <?php esc_html_e( 'Public Q&A, every topic is answered.', 'trill-ai-chat-lite' ); ?>
                </p>
                <a href="<?php echo esc_url( $trcl_forum_url ); ?>"
                   target="_blank" rel="noopener"
                   style="color: #14532d; font-weight: 600;">
                    <?php esc_html_e( 'Open the support forum →', 'trill-ai-chat-lite' ); ?>
                </a>
            </div>

            <div>
                <h3 style="margin: 0 0 6px; font-size: 14px; font-weight: 600; color: #14532d;">
                    <?php esc_html_e( 'Email', 'trill-ai-chat-lite' ); ?>
                </h3>
                <p style="margin: 0 0 6px; font-size: 13px; color: #166534;">
                    <?php esc_html_e( 'Best for billing, account, or private issues.', 'trill-ai-chat-lite' ); ?>
                </p>
                <a href="mailto:<?php echo esc_attr( $trcl_support_email ); ?>"
                   style="color: #14532d; font-weight: 600;">
                    <?php echo esc_html( $trcl_support_email ); ?> →
                </a>
            </div>

        </div>
    </div>

    <!-- ============================================================
         Footer
         ============================================================ -->
    <div style="background: #fff; padding: 18px 24px; border: 1px solid #e5e7eb; border-radius: 8px; margin: 20px 0; font-size: 12px; color: #6b7280; line-height: 1.6;">
        <p style="margin: 0;">
            <?php
            printf(
                /* translators: 1: company name, 2: Companies House number, 3: plugin version */
                esc_html__( '%1$s (Companies House %2$s). Trill AI Product Chat for WooCommerce v%3$s. Released under GPL v2 or later.', 'trill-ai-chat-lite' ),
                esc_html( $trcl_company ),
                esc_html( $trcl_company_no ),
                esc_html( $trcl_version )
            );
            ?>
        </p>
        <p style="margin: 6px 0 0;">
            <a href="https://trillai.io/privacy/" target="_blank" rel="noopener"><?php esc_html_e( 'Privacy', 'trill-ai-chat-lite' ); ?></a>
            &nbsp;·&nbsp;
            <a href="https://trillai.io/terms/" target="_blank" rel="noopener"><?php esc_html_e( 'Terms', 'trill-ai-chat-lite' ); ?></a>
            &nbsp;·&nbsp;
            <a href="https://www.gnu.org/licenses/gpl-2.0.html" target="_blank" rel="noopener">GPL v2</a>
        </p>
    </div>

</div>
