<?php
/**
 * Settings admin view.
 *
 * @package TrillChatLite\Admin
 * @since 1.0.0
 * @license GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$trcl_chat_enabled    = get_option( 'trcl_chat_enabled', '1' );
$trcl_widget_position = get_option( 'trcl_widget_position', 'bottom-right' );
$trcl_widget_color    = get_option( 'trcl_widget_color', '#10B981' );
$trcl_welcome_message = get_option( 'trcl_welcome_message', '' );
$trcl_show_powered_by = get_option( 'trcl_show_powered_by', '0' );
?>

<div class="wrap tcl-settings-page">
    <h1><?php esc_html_e( 'Trill AI Chat — Settings', 'trill-ai-chat-lite' ); ?></h1>

    <form method="post" action="options.php">
        <?php settings_fields( 'trcl_settings' ); ?>

        <table class="form-table" role="presentation">

            <!-- Enable Chat -->
            <tr>
                <th scope="row"><?php esc_html_e( 'Enable Chat Widget', 'trill-ai-chat-lite' ); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="trcl_chat_enabled" value="1" <?php checked( $trcl_chat_enabled, '1' ); ?> />
                        <?php esc_html_e( 'Show the AI chat widget on your store', 'trill-ai-chat-lite' ); ?>
                    </label>
                </td>
            </tr>

            <!-- Widget Position -->
            <tr>
                <th scope="row"><?php esc_html_e( 'Widget Position', 'trill-ai-chat-lite' ); ?></th>
                <td>
                    <select name="trcl_widget_position">
                        <option value="bottom-right" <?php selected( $trcl_widget_position, 'bottom-right' ); ?>>
                            <?php esc_html_e( 'Bottom Right', 'trill-ai-chat-lite' ); ?>
                        </option>
                        <option value="bottom-left" <?php selected( $trcl_widget_position, 'bottom-left' ); ?>>
                            <?php esc_html_e( 'Bottom Left', 'trill-ai-chat-lite' ); ?>
                        </option>
                    </select>
                </td>
            </tr>

            <!-- Widget Colour -->
            <tr>
                <th scope="row"><?php esc_html_e( 'Widget Colour', 'trill-ai-chat-lite' ); ?></th>
                <td>
                    <input type="color" name="trcl_widget_color" value="<?php echo esc_attr( $trcl_widget_color ); ?>" />
                    <p class="description"><?php esc_html_e( 'Primary colour for the chat widget.', 'trill-ai-chat-lite' ); ?></p>
                </td>
            </tr>

            <!-- Show "Powered by Trill AI" badge -->
            <tr>
                <th scope="row"><?php esc_html_e( 'Show Attribution Badge', 'trill-ai-chat-lite' ); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="trcl_show_powered_by" value="1" <?php checked( $trcl_show_powered_by, '1' ); ?> />
                        <?php esc_html_e( 'Display "Powered by Trill AI" in the chat widget footer', 'trill-ai-chat-lite' ); ?>
                    </label>
                    <p class="description"><?php esc_html_e( 'Optional. Show a small attribution link in the chat widget.', 'trill-ai-chat-lite' ); ?></p>
                </td>
            </tr>

            <!-- Welcome Message -->
            <tr>
                <th scope="row"><?php esc_html_e( 'Welcome Message', 'trill-ai-chat-lite' ); ?></th>
                <td>
                    <textarea name="trcl_welcome_message" rows="3" cols="50" class="large-text"><?php echo esc_textarea( $trcl_welcome_message ); ?></textarea>
                    <p class="description"><?php esc_html_e( 'The first message shown when a visitor opens the chat.', 'trill-ai-chat-lite' ); ?></p>
                </td>
            </tr>

        </table>

        <?php submit_button(); ?>
    </form>

    <!-- Lite Limitations Notice -->
    <div class="trcl-card" style="background: #f0f6fc; border: 1px solid #c3c4c7; padding: 16px 20px; border-radius: 4px; margin-top: 20px;">
        <h3 style="margin-top: 0;"><?php esc_html_e( 'Lite Version Limitations', 'trill-ai-chat-lite' ); ?></h3>
        <p><?php esc_html_e( 'The free version includes basic chat and product search. Upgrade to unlock:', 'trill-ai-chat-lite' ); ?></p>
        <ul style="list-style: disc; padding-left: 20px;">
            <li><?php esc_html_e( 'Unlimited conversations', 'trill-ai-chat-lite' ); ?></li>
            <li><?php esc_html_e( 'Order tracking', 'trill-ai-chat-lite' ); ?></li>
            <li><?php esc_html_e( 'Advanced analytics', 'trill-ai-chat-lite' ); ?></li>
            <li><?php esc_html_e( 'Custom branding (remove "Powered by" badge)', 'trill-ai-chat-lite' ); ?></li>
            <li><?php esc_html_e( 'Priority email support', 'trill-ai-chat-lite' ); ?></li>
        </ul>
        <a href="<?php echo esc_url( \TrillChatLite\Lite\LiteConfig::getUpgradeUrl( 'settings_page' ) ); ?>" target="_blank" class="button button-primary">
            <?php esc_html_e( 'Upgrade Now &rarr;', 'trill-ai-chat-lite' ); ?>
        </a>
    </div>
</div>
