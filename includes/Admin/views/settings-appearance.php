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

// Avatar (v2.1 APP-03).
$trcl_avatar_id      = (int) get_option( 'trcl_custom_avatar_id', 0 );
$trcl_avatar_url     = $trcl_settings_ctrl->get_avatar_url();
$trcl_default_avatar = TRCL_PLUGIN_URL . 'assets/images/avatar.png';
$trcl_avatar_display = ( '' !== $trcl_avatar_url ) ? $trcl_avatar_url : $trcl_default_avatar;

// Live preview (v2.1 APP-05) — initial values; admin.js keeps them in
// sync with the controls as they change.
$trcl_pv_welcome = ( '' !== trim( $trcl_welcome_message ) )
    ? $trcl_welcome_message
    : sprintf(
        /* translators: %s: assistant display name */
        __( "Hi there! I'm %s, your AI shopping assistant. How can I help you today?", 'trill-ai-chat-lite' ),
        $trcl_appearance['assistant_name']
    );

$trcl_pv_style = sprintf(
    '--trcl-primary:%s;--trcl-primary-hover:%s;--trcl-user-bubble:%s;--trcl-ai-bubble:%s;--trcl-header-text:%s;--trcl-body-text:%s;--trcl-window-bg:%s;--trcl-widget-width:%dpx;--trcl-widget-height:%dpx;--trcl-radius:%dpx;%s',
    $trcl_appearance['colors']['trcl_widget_color'],
    $trcl_appearance['colors']['trcl_widget_color_hover'],
    $trcl_appearance['colors']['trcl_user_bubble_color'],
    $trcl_appearance['colors']['trcl_ai_bubble_color'],
    $trcl_appearance['colors']['trcl_header_text_color'],
    $trcl_appearance['colors']['trcl_body_text_color'],
    $trcl_appearance['colors']['trcl_widget_bg_color'],
    $trcl_appearance['width'],
    $trcl_appearance['height'],
    $trcl_appearance['radius'],
    ( '' !== $trcl_appearance['font_stack'] ) ? 'font-family:' . $trcl_appearance['font_stack'] . ';' : ''
);

// PRG notice from the reset handler.
$trcl_appearance_notice = get_transient( 'trcl_appearance_notice' );
if ( is_array( $trcl_appearance_notice ) ) {
    delete_transient( 'trcl_appearance_notice' );
}
?>

<?php if ( is_array( $trcl_appearance_notice ) ) : ?>
    <div class="notice notice-<?php echo esc_attr( $trcl_appearance_notice['type'] ); ?> is-dismissible">
        <p><?php echo esc_html( $trcl_appearance_notice['message'] ); ?></p>
    </div>
<?php endif; ?>

<div class="trcl-appearance-layout">
<div class="trcl-appearance-main">

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
            <th scope="row"><?php esc_html_e( 'Avatar / Logo', 'trill-ai-chat-lite' ); ?></th>
            <td>
                <div class="trcl-avatar-control">
                    <span class="trcl-avatar-preview" id="trcl-avatar-preview"
                          data-default="<?php echo esc_url( $trcl_default_avatar ); ?>">
                        <img src="<?php echo esc_url( $trcl_avatar_display ); ?>" alt="" width="48" height="48" />
                    </span>
                    <input type="hidden"
                           id="trcl_custom_avatar_id"
                           name="trcl_custom_avatar_id"
                           value="<?php echo esc_attr( (string) $trcl_avatar_id ); ?>" />
                    <button type="button" class="button" id="trcl-avatar-upload">
                        <?php esc_html_e( 'Upload Image', 'trill-ai-chat-lite' ); ?>
                    </button>
                    <button type="button" class="button-link-delete" id="trcl-avatar-remove"
                            <?php echo ( 0 === $trcl_avatar_id ) ? 'style="display:none"' : ''; ?>>
                        <?php esc_html_e( 'Remove', 'trill-ai-chat-lite' ); ?>
                    </button>
                </div>
                <p class="description">
                    <?php esc_html_e( 'Recommended: 128×128px, PNG or JPG. Leave empty for the default avatar.', 'trill-ai-chat-lite' ); ?>
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
                        <option value="<?php echo esc_attr( $trcl_font_key ); ?>"
                                data-stack="<?php echo esc_attr( \TrillChatLite\Admin\Settings::FONT_CHOICES[ $trcl_font_key ] ); ?>"
                                <?php selected( $trcl_widget_font, $trcl_font_key ); ?>>
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

<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="trcl-reset-form"
      onsubmit="return confirm( '<?php echo esc_js( __( 'Reset all appearance settings to their defaults? Your welcome message will be kept.', 'trill-ai-chat-lite' ) ); ?>' );">
    <input type="hidden" name="action" value="trcl_reset_appearance" />
    <?php wp_nonce_field( 'trcl_reset_appearance' ); ?>
    <button type="submit" class="button">
        <?php esc_html_e( 'Reset to Defaults', 'trill-ai-chat-lite' ); ?>
    </button>
    <p class="description">
        <?php esc_html_e( 'Restores colours, position, dimensions, assistant name, avatar and font. The welcome message is not touched.', 'trill-ai-chat-lite' ); ?>
    </p>
</form>

</div><!-- /.trcl-appearance-main -->

<div class="trcl-appearance-preview-col">
    <h2><?php esc_html_e( 'Live Preview', 'trill-ai-chat-lite' ); ?></h2>

    <div class="trcl-pv" id="trcl-pv" style="<?php echo esc_attr( $trcl_pv_style ); ?>">
        <div class="trcl-pv-window">
            <div class="trcl-pv-header">
                <span class="trcl-pv-avatar">
                    <img src="<?php echo esc_url( $trcl_avatar_display ); ?>" alt="" width="40" height="40" />
                </span>
                <span class="trcl-pv-header-info">
                    <span class="trcl-pv-name"><?php echo esc_html( $trcl_appearance['assistant_name'] ); ?></span>
                    <span class="trcl-pv-role">
                        <?php esc_html_e( 'AI Assistant', 'trill-ai-chat-lite' ); ?>
                        — <?php esc_html_e( 'Online', 'trill-ai-chat-lite' ); ?>
                    </span>
                </span>
            </div>
            <div class="trcl-pv-messages">
                <div class="trcl-pv-msg trcl-pv-msg--ai" id="trcl-pv-welcome"><?php echo esc_html( $trcl_pv_welcome ); ?></div>
                <div class="trcl-pv-msg trcl-pv-msg--user"><?php esc_html_e( 'Do you have wireless headphones?', 'trill-ai-chat-lite' ); ?></div>
                <div class="trcl-pv-msg trcl-pv-msg--ai"><?php esc_html_e( 'Yes! We have 3 wireless headphone models in stock. Would you like me to show you the options?', 'trill-ai-chat-lite' ); ?></div>
            </div>
            <div class="trcl-pv-input-row">
                <span class="trcl-pv-input"><?php esc_html_e( 'Type your message...', 'trill-ai-chat-lite' ); ?></span>
                <span class="trcl-pv-send" aria-hidden="true">
                    <svg viewBox="0 0 24 24" width="14" height="14" xmlns="http://www.w3.org/2000/svg"><path d="M2.01 21L23 12 2.01 3 2 10l15 2-15 2z" fill="currentColor"/></svg>
                </span>
            </div>
        </div>
        <p class="description trcl-pv-note">
            <?php esc_html_e( 'Approximate preview — exact rendering depends on your theme.', 'trill-ai-chat-lite' ); ?>
        </p>
    </div>
</div><!-- /.trcl-appearance-preview-col -->

</div><!-- /.trcl-appearance-layout -->
