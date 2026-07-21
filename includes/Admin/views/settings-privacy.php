<?php
/**
 * Settings — Privacy tab view (v2.0 Block 2, slice 2).
 *
 * Rendered by settings.php when ?tab=privacy. Provides three fields:
 *
 *   1. Retention period (days) — how long conversations are kept
 *      before the daily cleanup cron removes them. Clamped on save
 *      to [GdprSettings::RETENTION_MIN_DAYS, RETENTION_MAX_DAYS].
 *   2. Privacy policy URL — when set, the chat widget renders a
 *      discreet "By chatting, you accept our Privacy Policy" link
 *      in its footer.
 *   3. Custom notice text — optional override for the default
 *      notice copy.
 *
 * The actual DSAR and erasure flows live behind WP's native
 * Tools → Export / Erase Personal Data — see the slice 1 commit.
 * This tab only configures the policy + retention; it does not
 * expose erasure controls of its own.
 *
 * @package TrillChatLite\Admin
 * @since 2.0.0
 * @license GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$trcl_gdpr           = new \TrillChatLite\Gdpr\GdprSettings();
$trcl_retention_days = $trcl_gdpr->get_retention_days();
$trcl_notice_url     = $trcl_gdpr->get_privacy_notice_url();
$trcl_notice_text    = (string) \get_option( \TrillChatLite\Gdpr\GdprSettings::OPT_PRIVACY_NOTICE_TEXT, '' );

// WP redirects with ?settings-updated=true after a successful options.php save.
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display flag.
$trcl_settings_updated = isset( $_GET['settings-updated'] ) && $_GET['settings-updated'] === 'true';

$trcl_min = \TrillChatLite\Gdpr\GdprSettings::RETENTION_MIN_DAYS;
$trcl_max = \TrillChatLite\Gdpr\GdprSettings::RETENTION_MAX_DAYS;

// Find the WP Privacy Policy page if it's set so we can offer a one-click
// "use the privacy page" link. Returns 0 when no policy page is configured.
$trcl_wp_policy_id  = (int) \get_option( 'wp_page_for_privacy_policy', 0 );
$trcl_wp_policy_url = $trcl_wp_policy_id > 0 ? (string) \get_permalink( $trcl_wp_policy_id ) : '';
?>

<?php if ( $trcl_settings_updated ) : ?>
    <div class="notice notice-success is-dismissible">
        <p><?php esc_html_e( 'Privacy settings saved.', 'trill-ai-chat-lite' ); ?></p>
    </div>
<?php endif; ?>

<p class="description" style="max-width: 720px;">
    <?php
    esc_html_e(
        'Configure how long chat conversations are retained and where shoppers can read your privacy policy. Data Subject Access Requests and right-to-erasure are handled through WordPress\'s native Tools → Personal Data screens (this plugin registers itself there automatically).',
        'trill-ai-chat-lite'
    );
    ?>
</p>

<form method="post" action="options.php">
    <?php settings_fields( \TrillChatLite\Admin\Settings::GROUP_PRIVACY ); ?>

    <table class="form-table" role="presentation">

        <!-- Retention period -->
        <tr>
            <th scope="row">
                <label for="trcl_retention_days">
                    <?php esc_html_e( 'Conversation retention', 'trill-ai-chat-lite' ); ?>
                </label>
            </th>
            <td>
                <input type="number"
                       id="trcl_retention_days"
                       name="<?php echo esc_attr( \TrillChatLite\Gdpr\GdprSettings::OPT_RETENTION_DAYS ); ?>"
                       value="<?php echo esc_attr( (string) $trcl_retention_days ); ?>"
                       min="<?php echo esc_attr( (string) $trcl_min ); ?>"
                       max="<?php echo esc_attr( (string) $trcl_max ); ?>"
                       step="1"
                       class="small-text" />
                <?php esc_html_e( 'days', 'trill-ai-chat-lite' ); ?>
                <p class="description">
                    <?php
                    printf(
                        /* translators: 1: min days, 2: max days */
                        esc_html__( 'Conversations older than this are removed daily by a background job. Clamped to %1$d–%2$d days.', 'trill-ai-chat-lite' ),
                        (int) $trcl_min,
                        (int) $trcl_max
                    );
                    ?>
                </p>
            </td>
        </tr>

        <!-- Privacy policy URL -->
        <tr>
            <th scope="row">
                <label for="trcl_privacy_notice_url">
                    <?php esc_html_e( 'Privacy policy URL', 'trill-ai-chat-lite' ); ?>
                </label>
            </th>
            <td>
                <input type="url"
                       id="trcl_privacy_notice_url"
                       name="<?php echo esc_attr( \TrillChatLite\Gdpr\GdprSettings::OPT_PRIVACY_NOTICE_URL ); ?>"
                       value="<?php echo esc_attr( $trcl_notice_url ); ?>"
                       placeholder="https://example.com/privacy"
                       class="regular-text" />

                <?php if ( $trcl_wp_policy_url !== '' && $trcl_wp_policy_url !== $trcl_notice_url ) : ?>
                    <p class="description" style="margin-top: 6px;">
                        <?php
                        printf(
                            /* translators: %s: privacy page URL */
                            esc_html__( 'Tip — your WordPress privacy page is %s. Copy it into the field above to link to it.', 'trill-ai-chat-lite' ),
                            '<a href="' . esc_url( $trcl_wp_policy_url ) . '" target="_blank" rel="noopener">'
                                . esc_html( $trcl_wp_policy_url ) . '</a>'
                        );
                        ?>
                    </p>
                <?php endif; ?>

                <p class="description">
                    <?php esc_html_e( 'When set, the chat widget shows a small footer link "By chatting, you accept our Privacy Policy" pointing here. Leave empty to hide the notice.', 'trill-ai-chat-lite' ); ?>
                </p>
            </td>
        </tr>

        <!-- Custom notice text -->
        <tr>
            <th scope="row">
                <label for="trcl_privacy_notice_text">
                    <?php esc_html_e( 'Notice text', 'trill-ai-chat-lite' ); ?>
                </label>
            </th>
            <td>
                <input type="text"
                       id="trcl_privacy_notice_text"
                       name="<?php echo esc_attr( \TrillChatLite\Gdpr\GdprSettings::OPT_PRIVACY_NOTICE_TEXT ); ?>"
                       value="<?php echo esc_attr( $trcl_notice_text ); ?>"
                       placeholder="<?php echo esc_attr( \TrillChatLite\Gdpr\GdprSettings::NOTICE_DEFAULT_TEXT ); ?>"
                       class="regular-text" />
                <p class="description">
                    <?php
                    printf(
                        /* translators: %s: default notice copy */
                        esc_html__( 'Optional override for the text shown before the policy link. Default: "%s".', 'trill-ai-chat-lite' ),
                        esc_html( \TrillChatLite\Gdpr\GdprSettings::NOTICE_DEFAULT_TEXT )
                    );
                    ?>
                </p>
            </td>
        </tr>

    </table>

    <?php submit_button( __( 'Save privacy settings', 'trill-ai-chat-lite' ) ); ?>
</form>

<hr style="margin: 30px 0;" />

<h2><?php esc_html_e( 'Data retention status', 'trill-ai-chat-lite' ); ?></h2>

<?php
// Stats cards + manual cleanup (v2.4 PRV-03). Preview is a dry-run;
// Run Now reuses the exact routine the daily cron executes.
$trcl_retention_stats = ( new \TrillChatLite\Gdpr\RetentionService() )->get_stats();
?>
<div style="display: flex; flex-wrap: wrap; gap: 1em;">
    <div class="card">
        <h3><?php esc_html_e( 'Stored conversations', 'trill-ai-chat-lite' ); ?></h3>
        <p style="font-size: 2em; margin: 0;"><?php echo esc_html( (string) $trcl_retention_stats['conversations'] ); ?></p>
    </div>
    <div class="card">
        <h3><?php esc_html_e( 'Stored messages', 'trill-ai-chat-lite' ); ?></h3>
        <p style="font-size: 2em; margin: 0;"><?php echo esc_html( (string) $trcl_retention_stats['messages'] ); ?></p>
    </div>
    <div class="card">
        <h3><?php esc_html_e( 'Retention window', 'trill-ai-chat-lite' ); ?></h3>
        <p style="font-size: 2em; margin: 0;">
            <?php
            printf(
                /* translators: %d: retention period in days */
                esc_html__( '%d days', 'trill-ai-chat-lite' ),
                (int) $trcl_retention_stats['retention_days']
            );
            ?>
        </p>
    </div>
    <div class="card">
        <h3><?php esc_html_e( 'Last cleanup', 'trill-ai-chat-lite' ); ?></h3>
        <p style="margin: 0;">
            <?php if ( $trcl_retention_stats['last_cleanup_at'] !== '' ) : ?>
                <?php echo esc_html( $trcl_retention_stats['last_cleanup_at'] ); ?> UTC<br />
                <?php
                printf(
                    /* translators: %d: conversations removed in the last cleanup */
                    esc_html__( '%d conversations removed', 'trill-ai-chat-lite' ),
                    (int) $trcl_retention_stats['last_cleanup_count']
                );
                ?>
            <?php else : ?>
                <?php esc_html_e( 'Never', 'trill-ai-chat-lite' ); ?>
            <?php endif; ?>
        </p>
    </div>
</div>

<p style="margin-top: 1em;">
    <button type="button" class="button" id="trcl-retention-preview-btn">
        <?php esc_html_e( 'Preview Cleanup', 'trill-ai-chat-lite' ); ?>
    </button>
    <button type="button" class="button button-link-delete" id="trcl-retention-run-btn">
        <?php esc_html_e( 'Run Cleanup Now', 'trill-ai-chat-lite' ); ?>
    </button>
    <span id="trcl-retention-status" style="margin-left: 8px;"></span>
</p>

<hr style="margin: 30px 0;" />

<h2><?php esc_html_e( 'DSAR & erasure', 'trill-ai-chat-lite' ); ?></h2>

<p class="description" style="max-width: 720px;">
    <?php
    printf(
        /* translators: 1: link to Tools → Export Personal Data, 2: link to Tools → Erase Personal Data */
        esc_html__( 'This plugin integrates with WordPress\'s built-in privacy tools. To handle a Data Subject Access Request, use %1$s. To erase a customer\'s chat data, use %2$s. In both cases enter the customer\'s email — the plugin will include or remove the matching conversations automatically.', 'trill-ai-chat-lite' ),
        '<a href="' . esc_url( \admin_url( 'export-personal-data.php' ) ) . '">'
            . esc_html__( 'Tools → Export Personal Data', 'trill-ai-chat-lite' ) . '</a>',
        '<a href="' . esc_url( \admin_url( 'erase-personal-data.php' ) ) . '">'
            . esc_html__( 'Tools → Erase Personal Data', 'trill-ai-chat-lite' ) . '</a>'
    );
    ?>
</p>
