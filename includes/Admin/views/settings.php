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
$trcl_skip_checkout   = get_option( 'trcl_skip_checkout', '0' );
$trcl_skip_account    = get_option( 'trcl_skip_account', '0' );

// Resolve the default at render-time from the Settings class so the UI
// stays in sync with register_setting() without duplicating the list.
$trcl_settings_controller = new \TrillChatLite\Admin\Settings();
$trcl_initial_quick_replies = get_option(
    'trcl_initial_quick_replies',
    $trcl_settings_controller->get_default_quick_replies_raw()
);
?>

<div class="wrap tcl-settings-page">
    <h1><?php esc_html_e( 'Trill AI Product Chat — Settings', 'trill-ai-chat-lite' ); ?></h1>

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

            <!-- Initial Quick Replies -->
            <tr>
                <th scope="row"><?php esc_html_e( 'Starter Suggestions', 'trill-ai-chat-lite' ); ?></th>
                <td>
                    <textarea name="trcl_initial_quick_replies" rows="3" cols="50" class="large-text" placeholder="<?php esc_attr_e( "What's on sale?\nHelp me choose a product\nLabel|Actual value sent", 'trill-ai-chat-lite' ); ?>"><?php echo esc_textarea( $trcl_initial_quick_replies ); ?></textarea>
                    <p class="description">
                        <?php esc_html_e( 'Up to three suggested prompts displayed as chips when the chat opens. One per line. Use an optional pipe to split display Label from the actual message Value ("Sale? | Show me discounted items").', 'trill-ai-chat-lite' ); ?>
                    </p>
                </td>
            </tr>

            <!-- Page Visibility -->
            <tr>
                <th scope="row"><?php esc_html_e( 'Page Visibility', 'trill-ai-chat-lite' ); ?></th>
                <td>
                    <label style="display: block; margin-bottom: 6px;">
                        <input type="checkbox" name="trcl_skip_checkout" value="1" <?php checked( $trcl_skip_checkout, '1' ); ?> />
                        <?php esc_html_e( 'Hide widget on the WooCommerce checkout page', 'trill-ai-chat-lite' ); ?>
                    </label>
                    <label style="display: block;">
                        <input type="checkbox" name="trcl_skip_account" value="1" <?php checked( $trcl_skip_account, '1' ); ?> />
                        <?php esc_html_e( 'Hide widget on the WooCommerce My Account pages', 'trill-ai-chat-lite' ); ?>
                    </label>
                    <p class="description"><?php esc_html_e( 'Recommended when you want to minimise distractions during purchase or account management. The widget never loads on feeds, REST or the login page.', 'trill-ai-chat-lite' ); ?></p>
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
