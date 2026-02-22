<?php
/**
 * Upgrade comparison card view.
 *
 * Rendered by UpgradeNotices::renderUpgradeCard() via trill_chat_lite_after_dashboard_stats hook.
 *
 * @package TrillChatLite\Admin
 * @since 1.0.0
 * @license GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
?>

<div class="tcl-upgrade-card" style="background: #fff; border: 1px solid #c3c4c7; border-radius: 4px; padding: 24px; margin: 20px 0;">
    <h2 style="margin-top: 0; font-size: 18px;"><?php esc_html_e( 'Unlock More with Trill AI Chat', 'trill-ai-chat-lite' ); ?></h2>

    <table class="widefat" style="margin: 16px 0;">
        <thead>
            <tr>
                <th><?php esc_html_e( 'Feature', 'trill-ai-chat-lite' ); ?></th>
                <th style="text-align: center;"><?php esc_html_e( 'Lite (Free)', 'trill-ai-chat-lite' ); ?></th>
                <th style="text-align: center; background: #f0f6fc;"><?php esc_html_e( 'Starter', 'trill-ai-chat-lite' ); ?></th>
                <th style="text-align: center;"><?php esc_html_e( 'Pro', 'trill-ai-chat-lite' ); ?></th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td><?php esc_html_e( 'AI Chat Widget', 'trill-ai-chat-lite' ); ?></td>
                <td style="text-align: center;">&#10003;</td>
                <td style="text-align: center; background: #f9fafb;">&#10003;</td>
                <td style="text-align: center;">&#10003;</td>
            </tr>
            <tr>
                <td><?php esc_html_e( 'Product Search', 'trill-ai-chat-lite' ); ?></td>
                <td style="text-align: center;">&#10003;</td>
                <td style="text-align: center; background: #f9fafb;">&#10003;</td>
                <td style="text-align: center;">&#10003;</td>
            </tr>
            <tr>
                <td><?php esc_html_e( 'Conversations / month', 'trill-ai-chat-lite' ); ?></td>
                <td style="text-align: center;">50</td>
                <td style="text-align: center; background: #f9fafb; font-weight: 600;">500</td>
                <td style="text-align: center; font-weight: 600;">2,000</td>
            </tr>
            <tr>
                <td><?php esc_html_e( 'Order Tracking', 'trill-ai-chat-lite' ); ?></td>
                <td style="text-align: center; color: #999;">&mdash;</td>
                <td style="text-align: center; background: #f9fafb;">&#10003;</td>
                <td style="text-align: center;">&#10003;</td>
            </tr>
            <tr>
                <td><?php esc_html_e( 'Analytics', 'trill-ai-chat-lite' ); ?></td>
                <td style="text-align: center; color: #999;">&mdash;</td>
                <td style="text-align: center; background: #f9fafb;"><?php esc_html_e( 'Basic', 'trill-ai-chat-lite' ); ?></td>
                <td style="text-align: center;"><?php esc_html_e( 'Advanced', 'trill-ai-chat-lite' ); ?></td>
            </tr>
            <tr>
                <td><?php esc_html_e( 'Custom Branding', 'trill-ai-chat-lite' ); ?></td>
                <td style="text-align: center; color: #999;">&mdash;</td>
                <td style="text-align: center; background: #f9fafb; color: #999;">&mdash;</td>
                <td style="text-align: center;">&#10003;</td>
            </tr>
            <tr>
                <td><?php esc_html_e( 'Support', 'trill-ai-chat-lite' ); ?></td>
                <td style="text-align: center;"><?php esc_html_e( 'Forums', 'trill-ai-chat-lite' ); ?></td>
                <td style="text-align: center; background: #f9fafb;"><?php esc_html_e( 'Email', 'trill-ai-chat-lite' ); ?></td>
                <td style="text-align: center;"><?php esc_html_e( 'Priority', 'trill-ai-chat-lite' ); ?></td>
            </tr>
        </tbody>
    </table>

    <div style="text-align: center; margin-top: 16px;">
        <a href="<?php echo esc_url( \TrillChatLite\Lite\LiteConfig::getUpgradeUrl( 'upgrade_card' ) ); ?>" target="_blank" class="button button-primary button-hero">
            <?php esc_html_e( 'Compare Plans & Upgrade', 'trill-ai-chat-lite' ); ?>
        </a>
    </div>
</div>
