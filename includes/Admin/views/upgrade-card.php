<?php
/**
 * Upgrade comparison card view.
 *
 * Rendered by UpgradeNotices::renderUpgradeCard() via gcl_after_dashboard_stats hook.
 *
 * @package GspltdChatLite\Admin
 * @since 1.0.0
 * @license GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
?>

<div class="gcl-upgrade-card" style="background: #fff; border: 1px solid #c3c4c7; border-radius: 4px; padding: 24px; margin: 20px 0;">
    <h2 style="margin-top: 0; font-size: 18px;"><?php esc_html_e( 'Unlock More with GSPLTD AI Chat', 'gspltd-chat-lite' ); ?></h2>

    <table class="widefat" style="margin: 16px 0;">
        <thead>
            <tr>
                <th><?php esc_html_e( 'Feature', 'gspltd-chat-lite' ); ?></th>
                <th style="text-align: center;"><?php esc_html_e( 'Lite (Free)', 'gspltd-chat-lite' ); ?></th>
                <th style="text-align: center; background: #f0f6fc;"><?php esc_html_e( 'Starter', 'gspltd-chat-lite' ); ?></th>
                <th style="text-align: center;"><?php esc_html_e( 'Pro', 'gspltd-chat-lite' ); ?></th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td><?php esc_html_e( 'AI Chat Widget', 'gspltd-chat-lite' ); ?></td>
                <td style="text-align: center;">&#10003;</td>
                <td style="text-align: center; background: #f9fafb;">&#10003;</td>
                <td style="text-align: center;">&#10003;</td>
            </tr>
            <tr>
                <td><?php esc_html_e( 'Product Search', 'gspltd-chat-lite' ); ?></td>
                <td style="text-align: center;">&#10003;</td>
                <td style="text-align: center; background: #f9fafb;">&#10003;</td>
                <td style="text-align: center;">&#10003;</td>
            </tr>
            <tr>
                <td><?php esc_html_e( 'Conversations / month', 'gspltd-chat-lite' ); ?></td>
                <td style="text-align: center;">50</td>
                <td style="text-align: center; background: #f9fafb; font-weight: 600;">500</td>
                <td style="text-align: center; font-weight: 600;"><?php esc_html_e( 'Unlimited', 'gspltd-chat-lite' ); ?></td>
            </tr>
            <tr>
                <td><?php esc_html_e( 'Order Tracking', 'gspltd-chat-lite' ); ?></td>
                <td style="text-align: center; color: #999;">&mdash;</td>
                <td style="text-align: center; background: #f9fafb;">&#10003;</td>
                <td style="text-align: center;">&#10003;</td>
            </tr>
            <tr>
                <td><?php esc_html_e( 'Analytics', 'gspltd-chat-lite' ); ?></td>
                <td style="text-align: center; color: #999;">&mdash;</td>
                <td style="text-align: center; background: #f9fafb;"><?php esc_html_e( 'Basic', 'gspltd-chat-lite' ); ?></td>
                <td style="text-align: center;"><?php esc_html_e( 'Advanced', 'gspltd-chat-lite' ); ?></td>
            </tr>
            <tr>
                <td><?php esc_html_e( 'Custom Branding', 'gspltd-chat-lite' ); ?></td>
                <td style="text-align: center; color: #999;">&mdash;</td>
                <td style="text-align: center; background: #f9fafb; color: #999;">&mdash;</td>
                <td style="text-align: center;">&#10003;</td>
            </tr>
            <tr>
                <td><?php esc_html_e( 'Support', 'gspltd-chat-lite' ); ?></td>
                <td style="text-align: center;"><?php esc_html_e( 'Forums', 'gspltd-chat-lite' ); ?></td>
                <td style="text-align: center; background: #f9fafb;"><?php esc_html_e( 'Email', 'gspltd-chat-lite' ); ?></td>
                <td style="text-align: center;"><?php esc_html_e( 'Priority', 'gspltd-chat-lite' ); ?></td>
            </tr>
        </tbody>
    </table>

    <div style="text-align: center; margin-top: 16px;">
        <a href="<?php echo esc_url( \GspltdChatLite\Lite\LiteConfig::getUpgradeUrl( 'upgrade_card' ) ); ?>" target="_blank" class="button button-primary button-hero">
            <?php esc_html_e( 'Compare Plans & Upgrade', 'gspltd-chat-lite' ); ?>
        </a>
    </div>
</div>
