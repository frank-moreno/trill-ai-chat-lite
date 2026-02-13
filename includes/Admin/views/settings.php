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

$chat_enabled    = get_option( 'tcl_chat_enabled', '1' );
$widget_position = get_option( 'tcl_widget_position', 'bottom-right' );
$widget_color    = get_option( 'tcl_widget_color', '#10B981' );
$welcome_message = get_option( 'tcl_welcome_message', '' );
?>

<div class="wrap tcl-settings-page">
    <h1><?php esc_html_e( 'Trill AI Chat — Settings', 'trill-chat-lite' ); ?></h1>

    <form method="post" action="options.php">
        <?php settings_fields( 'tcl_settings' ); ?>

        <table class="form-table" role="presentation">

            <!-- Enable Chat -->
            <tr>
                <th scope="row"><?php esc_html_e( 'Enable Chat Widget', 'trill-chat-lite' ); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="tcl_chat_enabled" value="1" <?php checked( $chat_enabled, '1' ); ?> />
                        <?php esc_html_e( 'Show the AI chat widget on your store', 'trill-chat-lite' ); ?>
                    </label>
                </td>
            </tr>

            <!-- Widget Position -->
            <tr>
                <th scope="row"><?php esc_html_e( 'Widget Position', 'trill-chat-lite' ); ?></th>
                <td>
                    <select name="tcl_widget_position">
                        <option value="bottom-right" <?php selected( $widget_position, 'bottom-right' ); ?>>
                            <?php esc_html_e( 'Bottom Right', 'trill-chat-lite' ); ?>
                        </option>
                        <option value="bottom-left" <?php selected( $widget_position, 'bottom-left' ); ?>>
                            <?php esc_html_e( 'Bottom Left', 'trill-chat-lite' ); ?>
                        </option>
                    </select>
                </td>
            </tr>

            <!-- Widget Colour -->
            <tr>
                <th scope="row"><?php esc_html_e( 'Widget Colour', 'trill-chat-lite' ); ?></th>
                <td>
                    <input type="color" name="tcl_widget_color" value="<?php echo esc_attr( $widget_color ); ?>" />
                    <p class="description"><?php esc_html_e( 'Primary colour for the chat widget.', 'trill-chat-lite' ); ?></p>
                </td>
            </tr>

            <!-- Welcome Message -->
            <tr>
                <th scope="row"><?php esc_html_e( 'Welcome Message', 'trill-chat-lite' ); ?></th>
                <td>
                    <textarea name="tcl_welcome_message" rows="3" cols="50" class="large-text"><?php echo esc_textarea( $welcome_message ); ?></textarea>
                    <p class="description"><?php esc_html_e( 'The first message shown when a visitor opens the chat.', 'trill-chat-lite' ); ?></p>
                </td>
            </tr>

        </table>

        <?php submit_button(); ?>
    </form>

    <!-- Lite Limitations Notice -->
    <div class="tcl-card" style="background: #f0f6fc; border: 1px solid #c3c4c7; padding: 16px 20px; border-radius: 4px; margin-top: 20px;">
        <h3 style="margin-top: 0;"><?php esc_html_e( 'Lite Version Limitations', 'trill-chat-lite' ); ?></h3>
        <p><?php esc_html_e( 'The free version includes basic chat and product search. Upgrade to unlock:', 'trill-chat-lite' ); ?></p>
        <ul style="list-style: disc; padding-left: 20px;">
            <li><?php esc_html_e( 'Unlimited conversations', 'trill-chat-lite' ); ?></li>
            <li><?php esc_html_e( 'Order tracking', 'trill-chat-lite' ); ?></li>
            <li><?php esc_html_e( 'Advanced analytics', 'trill-chat-lite' ); ?></li>
            <li><?php esc_html_e( 'Custom branding (remove "Powered by" badge)', 'trill-chat-lite' ); ?></li>
            <li><?php esc_html_e( 'Priority email support', 'trill-chat-lite' ); ?></li>
        </ul>
        <a href="<?php echo esc_url( \TrillChatLite\Lite\LiteConfig::getUpgradeUrl( 'settings_page' ) ); ?>" target="_blank" class="button button-primary">
            <?php esc_html_e( 'Upgrade Now &rarr;', 'trill-chat-lite' ); ?>
        </a>
    </div>
</div>
