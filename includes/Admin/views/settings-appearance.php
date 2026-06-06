<?php
/**
 * Settings → Appearance tab (v2.1).
 *
 * Widget look & feel: colour palette, position (4 corners), dimensions
 * (width / height / border radius), identity (assistant name, welcome
 * message) and font.
 *
 * Posts to options.php with the shared `trcl_settings` option group —
 * the Settings API saves per-field, so the other tabs' settings are
 * untouched.
 *
 * Colour inputs use wp-color-picker (enqueued in Admin::enqueue_admin_assets
 * for this screen); sliders are native <input type="range"> wired to a
 * live px read-out in admin.js.
 *
 * @package TrillChatLite\Admin
 * @since 2.1.0
 * @license GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$trcl_settings_ctrl = new \TrillChatLite\Admin\Settings();
$trcl_appearance    = $trcl_settings_ctrl->get_appearance_config();

$trcl_welcome_message  = get_option( 'trcl_welcome_message', '' );
$trcl_assistant_name   = get_option( 'trcl_assistant_name', '' );
$trcl_widget_font      = get_option( 'trcl_widget_font', '' );

$trcl_color_fields = [
    'trcl_widget_color'       => [
        'label' => __( 'Primary Colour', 'trill-ai-chat-lite' ),
        'desc'  => __( 'Header, launcher icon and send button.', 'trill-ai-chat-lite' ),
    ],
    'trcl_widget_color_hover' => [
        'label' => __( 'Primary Hover', 'trill-ai-chat-lite' ),
        'desc'  => __( 'Links and hover states.', 'trill-ai-chat-lite' ),
    ],
    'trcl_user_bubble_color'  => [
        'label' => __( 'User Message Bubble', 'trill-ai-chat-lite' ),
        'desc'  => '',
    ],
    'trcl_ai_bubble_color'    => [
        'label' => __( 'AI Message Bubble', 'trill-ai-chat-lite' ),
        'desc'  => '',
    ],
    'trcl_header_text_color'  => [
        'label' => __( 'Header Text', 'trill-ai-chat-lite' ),
        'desc'  => '',
    ],
    'trcl_body_text_color'    => [
        'label' => __( 'Body Text', 'trill-ai-chat-lite' ),
        'desc'  => '',
    ],
    'trcl_widget_bg_color'    => [
        'label' => __( 'Background', 'trill-ai-chat-lite' ),
        'desc'  => __( 'Chat window background.', 'trill-ai-chat-lite' ),
    ],
];

$trcl_positions = [
    'top-left'     => __( 'Top Left', 'trill-ai-chat-lite' ),
    'top-right'    => __( 'Top Right', 'trill-ai-chat-lite' ),
    'bottom-left'  => __( 'Bottom Left', 'trill-ai-chat-lite' ),
    'bottom-right' => __( 'Bottom Right', 'trill-ai-chat-lite' ),
];

$trcl_font_labels = [
    ''          => __( 'Default (system font)', 'trill-ai-chat-lite' ),
    'serif'     => __( 'Serif (Georgia)', 'trill-ai-chat-lite' ),
    'helvetica' => __( 'Helvetica / Arial', 'trill-ai-chat-lite' ),
    'mono'      => __( 'Monospace', 'trill-ai-chat-lite' ),
];
?>

<form method="post" action="options.php">
    <?php settings_fields( 'trcl_settings' ); ?>

    <h2><?php esc_html_e( 'Colours', 'trill-ai-chat-lite' ); ?></h2>

    <table class="form-table" role="presentation">
        <?php foreach ( $trcl_color_fields as $trcl_option => $trcl_field ) : ?>
            <tr>
                <th scope="row">
                    <label for="<?php echo esc_attr( $trcl_option ); ?>">
                        <?php echo esc_html( $trcl_field['label'] ); ?>
                    </label>
                </th>
                <td>
                    <input type="text"
                           class="trcl-color-field"
                           id="<?php echo esc_attr( $trcl_option ); ?>"
                           name="<?php echo esc_attr( $trcl_option ); ?>"
                           value="<?php echo esc_attr( $trcl_appearance['colors'][ $trcl_option ] ); ?>"
                           data-default-color="<?php echo esc_attr( \TrillChatLite\Admin\Settings::COLOR_DEFAULTS[ $trcl_option ] ); ?>" />
                    <?php if ( '' !== $trcl_field['desc'] ) : ?>
                        <p class="description"><?php echo esc_html( $trcl_field['desc'] ); ?></p>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
    </table>

    <h2><?php esc_html_e( 'Position & Dimensions', 'trill-ai-chat-lite' ); ?></h2>

    <table class="form-table" role="presentation">

        <tr>
            <th scope="row"><?php esc_html_e( 'Widget Position', 'trill-ai-chat-lite' ); ?></th>
            <td>
                <fieldset class="trcl-position-grid">
                    <legend class="screen-reader-text">
                        <?php esc_html_e( 'Widget Position', 'trill-ai-chat-lite' ); ?>
                    </legend>
                    <?php foreach ( $trcl_positions as $trcl_pos_value => $trcl_pos_label ) : ?>
                        <label class="trcl-position-option">
                            <input type="radio"
                                   name="trcl_widget_position"
                                   value="<?php echo esc_attr( $trcl_pos_value ); ?>"
                                   <?php checked( $trcl_appearance['position'], $trcl_pos_value ); ?> />
                            <?php echo esc_html( $trcl_pos_label ); ?>
                        </label>
                    <?php endforeach; ?>
                </fieldset>
            </td>
        </tr>

        <tr>
            <th scope="row">
                <label for="trcl_widget_width"><?php esc_html_e( 'Widget Width (px)', 'trill-ai-chat-lite' ); ?></label>
            </th>
            <td>
                <input type="range"
                       id="trcl_widget_width"
                       name="trcl_widget_width"
                       class="trcl-range"
                       min="<?php echo esc_attr( (string) \TrillChatLite\Admin\Settings::WIDTH_MIN ); ?>"
                       max="<?php echo esc_attr( (string) \TrillChatLite\Admin\Settings::WIDTH_MAX ); ?>"
                       step="10"
                       value="<?php echo esc_attr( (string) $trcl_appearance['width'] ); ?>" />
                <span class="trcl-range-value" data-suffix="px"><?php echo esc_html( (string) $trcl_appearance['width'] ); ?>px</span>
                <p class="description"><?php esc_html_e( 'Desktop only — the widget is full-screen on mobile.', 'trill-ai-chat-lite' ); ?></p>
            </td>
        </tr>

        <tr>
            <th scope="row">
                <label for="trcl_widget_height"><?php esc_html_e( 'Widget Height (px)', 'trill-ai-chat-lite' ); ?></label>
            </th>
            <td>
                <input type="range"
                       id="trcl_widget_height"
                       name="trcl_widget_height"
                       class="trcl-range"
                       min="<?php echo esc_attr( (string) \TrillChatLite\Admin\Settings::HEIGHT_MIN ); ?>"
                       max="<?php echo esc_attr( (string) \TrillChatLite\Admin\Settings::HEIGHT_MAX ); ?>"
                       step="10"
                       value="<?php echo esc_attr( (string) $trcl_appearance['height'] ); ?>" />
                <span class="trcl-range-value" data-suffix="px"><?php echo esc_html( (string) $trcl_appearance['height'] ); ?>px</span>
            </td>
        </tr>

        <tr>
            <th scope="row">
                <label for="trcl_widget_border_radius"><?php esc_html_e( 'Border Radius (px)', 'trill-ai-chat-lite' ); ?></label>
            </th>
            <td>
                <input type="range"
                       id="trcl_widget_border_radius"
                       name="trcl_widget_border_radius"
                       class="trcl-range"
                       min="<?php echo esc_attr( (string) \TrillChatLite\Admin\Settings::RADIUS_MIN ); ?>"
                       max="<?php echo esc_attr( (string) \TrillChatLite\Admin\Settings::RADIUS_MAX ); ?>"
                       step="1"
                       value="<?php echo esc_attr( (string) $trcl_appearance['radius'] ); ?>" />
                <span class="trcl-range-value" data-suffix="px"><?php echo esc_html( (string) $trcl_appearance['radius'] ); ?>px</span>
            </td>
        </tr>

    </table>

    <h2><?php esc_html_e( 'Identity & Messaging', 'trill-ai-chat-lite' ); ?></h2>

    <table class="form-table" role="presentation">

        <tr>
            <th scope="row">
                <label for="trcl_assistant_name"><?php esc_html_e( 'Assistant Name', 'trill-ai-chat-lite' ); ?></label>
            </th>
            <td>
                <input type="text"
                       id="trcl_assistant_name"
                       name="trcl_assistant_name"
                       class="regular-text"
                       maxlength="40"
                       value="<?php echo esc_attr( $trcl_assistant_name ); ?>"
                       placeholder="<?php esc_attr_e( 'Robin', 'trill-ai-chat-lite' ); ?>" />
                <p class="description">
                    <?php esc_html_e( 'The name shown in the chat header. Leave blank for the default (Robin).', 'trill-ai-chat-lite' ); ?>
                </p>
            </td>
        </tr>

        <tr>
            <th scope="row">
                <label for="trcl_welcome_message"><?php esc_html_e( 'Welcome Message', 'trill-ai-chat-lite' ); ?></label>
            </th>
            <td>
                <textarea id="trcl_welcome_message"
                          name="trcl_welcome_message"
                          rows="3"
                          cols="50"
                          class="large-text"><?php echo esc_textarea( $trcl_welcome_message ); ?></textarea>
                <p class="description">
                    <?php esc_html_e( 'The first message visitors see when they open the chat. Leave blank for the default.', 'trill-ai-chat-lite' ); ?>
                </p>
            </td>
        </tr>

        <tr>
            <th scope="row">
                <label for="trcl_widget_font"><?php esc_html_e( 'Font Family', 'trill-ai-chat-lite' ); ?></label>
            </th>
            <td>
                <select id="trcl_widget_font" name="trcl_widget_font">
                    <?php foreach ( $trcl_font_labels as $trcl_font_key => $trcl_font_label ) : ?>
                        <option value="<?php echo esc_attr( $trcl_font_key ); ?>" <?php selected( $trcl_widget_font, $trcl_font_key ); ?>>
                            <?php echo esc_html( $trcl_font_label ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <p class="description">
                    <?php esc_html_e( 'Curated system fonts only — no external font requests are ever made.', 'trill-ai-chat-lite' ); ?>
                </p>
            </td>
        </tr>

    </table>

    <?php submit_button(); ?>
</form>
