<?php
/**
 * GDPR Tools admin view (v2.4 PRV-01 skeleton).
 *
 * Data-subject lookup by email + rights cards (Access / Portability /
 * Erasure) + recent GDPR audit trail (PRV-02). Included from
 * GdprToolsPage::render(), so `$this` is the page instance. Lookup
 * result and on-screen expediente arrive via single-shot per-user
 * transients (PRG — see GdprToolsPage docblock); no PII in URLs —
 * the subject's email travels only in POST bodies (hidden fields).
 *
 * @package TrillChatLite\Admin
 * @since 2.4.0
 * @license GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$trcl_notice     = $this->consume_notice();
$trcl_summary    = $this->consume_lookup_result();
$trcl_expediente = $this->consume_expediente_result();
$trcl_audit      = ( new \TrillChatLite\Gdpr\AuditLogger() )->get_recent( 10 );

$trcl_action_url = \admin_url( 'admin-post.php' );

$trcl_action_labels = [
    'search' => __( 'Search', 'trill-ai-chat-lite' ),
    'view'   => __( 'View', 'trill-ai-chat-lite' ),
    'export' => __( 'Export', 'trill-ai-chat-lite' ),
    'erase'  => __( 'Erase', 'trill-ai-chat-lite' ),
];
?>
<div class="wrap trcl-gdpr-tools-page">
    <h1><?php esc_html_e( 'GDPR Tools', 'trill-ai-chat-lite' ); ?></h1>

    <p class="description">
        <?php esc_html_e( 'Look up, export, and erase the personal data this plugin holds for a visitor. Every action taken here is recorded in the audit trail below. No IP addresses are ever stored.', 'trill-ai-chat-lite' ); ?>
    </p>

    <?php if ( $trcl_notice ) : ?>
        <div class="notice notice-<?php echo esc_attr( $trcl_notice['type'] ); ?> is-dismissible">
            <p><?php echo esc_html( $trcl_notice['message'] ); ?></p>
        </div>
    <?php endif; ?>

    <h2><?php esc_html_e( 'Data subject lookup', 'trill-ai-chat-lite' ); ?></h2>

    <form method="post" action="<?php echo esc_url( $trcl_action_url ); ?>">
        <input type="hidden" name="action" value="trcl_gdpr_lookup" />
        <?php wp_nonce_field( 'trcl_gdpr_lookup' ); ?>

        <label class="screen-reader-text" for="trcl_gdpr_email">
            <?php esc_html_e( 'Email address', 'trill-ai-chat-lite' ); ?>
        </label>
        <input
            type="email"
            id="trcl_gdpr_email"
            name="trcl_gdpr_email"
            class="regular-text"
            placeholder="<?php esc_attr_e( 'visitor@example.com', 'trill-ai-chat-lite' ); ?>"
            required
        />
        <button type="submit" class="button button-primary">
            <?php esc_html_e( 'Look up', 'trill-ai-chat-lite' ); ?>
        </button>
    </form>

    <?php if ( $trcl_summary ) : ?>
        <?php if ( $trcl_summary['conversations'] === 0 && $trcl_summary['leads'] === 0 ) : ?>
            <div class="notice notice-info inline" style="margin-top: 1em;">
                <p>
                    <?php
                    printf(
                        /* translators: %s: masked email address */
                        esc_html__( 'No data found for %s.', 'trill-ai-chat-lite' ),
                        esc_html( \TrillChatLite\Gdpr\ConversationManager::mask_email( $trcl_summary['email'] ) )
                    );
                    ?>
                </p>
            </div>
        <?php else : ?>
            <h3>
                <?php
                printf(
                    /* translators: %s: masked email address */
                    esc_html__( 'Data held for %s', 'trill-ai-chat-lite' ),
                    esc_html( \TrillChatLite\Gdpr\ConversationManager::mask_email( $trcl_summary['email'] ) )
                );
                ?>
            </h3>
            <table class="widefat striped" style="max-width: 640px;">
                <tbody>
                    <tr>
                        <td><?php esc_html_e( 'Conversations', 'trill-ai-chat-lite' ); ?></td>
                        <td><?php echo esc_html( (string) $trcl_summary['conversations'] ); ?></td>
                    </tr>
                    <tr>
                        <td><?php esc_html_e( 'Messages', 'trill-ai-chat-lite' ); ?></td>
                        <td><?php echo esc_html( (string) $trcl_summary['messages'] ); ?></td>
                    </tr>
                    <tr>
                        <td><?php esc_html_e( 'Leads', 'trill-ai-chat-lite' ); ?></td>
                        <td><?php echo esc_html( (string) $trcl_summary['leads'] ); ?></td>
                    </tr>
                    <tr>
                        <td><?php esc_html_e( 'First activity', 'trill-ai-chat-lite' ); ?></td>
                        <td><?php echo esc_html( $trcl_summary['first_activity'] ?: '—' ); ?></td>
                    </tr>
                    <tr>
                        <td><?php esc_html_e( 'Last activity', 'trill-ai-chat-lite' ); ?></td>
                        <td><?php echo esc_html( $trcl_summary['last_activity'] ?: '—' ); ?></td>
                    </tr>
                </tbody>
            </table>

            <?php // Data-subject rights cards. The email travels in POST bodies only. ?>
            <div style="display: flex; flex-wrap: wrap; gap: 1em; align-items: stretch;">

                <div class="card" style="margin-top: 1em;">
                    <h3><?php esc_html_e( 'Right of Access', 'trill-ai-chat-lite' ); ?></h3>
                    <p><?php esc_html_e( 'Review everything this plugin holds for the visitor, on screen.', 'trill-ai-chat-lite' ); ?></p>
                    <form method="post" action="<?php echo esc_url( $trcl_action_url ); ?>">
                        <input type="hidden" name="action" value="trcl_gdpr_view" />
                        <input type="hidden" name="trcl_gdpr_email" value="<?php echo esc_attr( $trcl_summary['email'] ); ?>" />
                        <?php wp_nonce_field( 'trcl_gdpr_view' ); ?>
                        <button type="submit" class="button">
                            <?php esc_html_e( 'View data', 'trill-ai-chat-lite' ); ?>
                        </button>
                    </form>
                </div>

                <div class="card" style="margin-top: 1em;">
                    <h3><?php esc_html_e( 'Data Portability', 'trill-ai-chat-lite' ); ?></h3>
                    <p><?php esc_html_e( 'Download the same data in a portable format to pass on to the visitor.', 'trill-ai-chat-lite' ); ?></p>
                    <form method="post" action="<?php echo esc_url( $trcl_action_url ); ?>">
                        <input type="hidden" name="action" value="trcl_gdpr_export" />
                        <input type="hidden" name="trcl_gdpr_email" value="<?php echo esc_attr( $trcl_summary['email'] ); ?>" />
                        <?php wp_nonce_field( 'trcl_gdpr_export' ); ?>
                        <button type="submit" class="button" name="trcl_gdpr_format" value="json">
                            <?php esc_html_e( 'Download JSON', 'trill-ai-chat-lite' ); ?>
                        </button>
                        <button type="submit" class="button" name="trcl_gdpr_format" value="csv">
                            <?php esc_html_e( 'Download CSV', 'trill-ai-chat-lite' ); ?>
                        </button>
                    </form>
                </div>

                <div class="card" style="margin-top: 1em;">
                    <h3><?php esc_html_e( 'Right to Erasure', 'trill-ai-chat-lite' ); ?></h3>
                    <p><?php esc_html_e( 'Permanently delete every conversation, message, feedback entry and lead for this email. This cannot be undone.', 'trill-ai-chat-lite' ); ?></p>
                    <form method="post" action="<?php echo esc_url( $trcl_action_url ); ?>">
                        <input type="hidden" name="action" value="trcl_gdpr_erase" />
                        <input type="hidden" name="trcl_gdpr_email" value="<?php echo esc_attr( $trcl_summary['email'] ); ?>" />
                        <?php wp_nonce_field( 'trcl_gdpr_erase' ); ?>
                        <label class="screen-reader-text" for="trcl_gdpr_confirm">
                            <?php esc_html_e( 'Type DELETE to confirm', 'trill-ai-chat-lite' ); ?>
                        </label>
                        <input
                            type="text"
                            id="trcl_gdpr_confirm"
                            name="trcl_gdpr_confirm"
                            class="regular-text"
                            autocomplete="off"
                            placeholder="<?php esc_attr_e( 'Type DELETE to confirm', 'trill-ai-chat-lite' ); ?>"
                            required
                        />
                        <button type="submit" class="button button-link-delete">
                            <?php esc_html_e( 'Erase all data', 'trill-ai-chat-lite' ); ?>
                        </button>
                    </form>
                </div>
            </div>
        <?php endif; ?>
    <?php endif; ?>

    <?php if ( $trcl_expediente !== null ) : ?>
        <h2 style="margin-top: 2em;"><?php esc_html_e( 'Data on record', 'trill-ai-chat-lite' ); ?></h2>

        <?php if ( empty( $trcl_expediente ) ) : ?>
            <p class="description"><?php esc_html_e( 'Nothing on record for this email.', 'trill-ai-chat-lite' ); ?></p>
        <?php else : ?>
            <?php foreach ( $trcl_expediente as $trcl_item ) : ?>
                <h4>
                    <?php echo esc_html( $trcl_item['group_label'] ); ?>
                    <code><?php echo esc_html( $trcl_item['item_id'] ); ?></code>
                </h4>
                <table class="widefat striped" style="max-width: 900px; margin-bottom: 1em;">
                    <tbody>
                        <?php foreach ( $trcl_item['data'] as $trcl_field ) : ?>
                            <tr>
                                <td style="width: 220px;"><?php echo esc_html( $trcl_field['name'] ); ?></td>
                                <td style="white-space: pre-wrap;"><?php echo esc_html( $trcl_field['value'] ); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endforeach; ?>
        <?php endif; ?>
    <?php endif; ?>

    <h2 style="margin-top: 2em;"><?php esc_html_e( 'Recent GDPR actions', 'trill-ai-chat-lite' ); ?></h2>

    <?php if ( empty( $trcl_audit ) ) : ?>
        <p class="description"><?php esc_html_e( 'No GDPR actions recorded yet.', 'trill-ai-chat-lite' ); ?></p>
    <?php else : ?>
        <table class="widefat striped">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'Date (UTC)', 'trill-ai-chat-lite' ); ?></th>
                    <th><?php esc_html_e( 'Action', 'trill-ai-chat-lite' ); ?></th>
                    <th><?php esc_html_e( 'Email', 'trill-ai-chat-lite' ); ?></th>
                    <th><?php esc_html_e( 'Performed by', 'trill-ai-chat-lite' ); ?></th>
                    <th><?php esc_html_e( 'Records', 'trill-ai-chat-lite' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $trcl_audit as $trcl_entry ) : ?>
                    <?php
                    $trcl_admin_user  = \get_userdata( (int) $trcl_entry->performed_by );
                    $trcl_admin_label = ( $trcl_admin_user instanceof \WP_User )
                        ? $trcl_admin_user->display_name
                        : __( 'Unknown', 'trill-ai-chat-lite' );
                    ?>
                    <tr>
                        <td><?php echo esc_html( $trcl_entry->created_at ); ?></td>
                        <td><?php echo esc_html( $trcl_action_labels[ $trcl_entry->action ] ?? $trcl_entry->action ); ?></td>
                        <td><?php echo esc_html( $trcl_entry->target_email ); ?></td>
                        <td><?php echo esc_html( $trcl_admin_label ); ?></td>
                        <td><?php echo esc_html( (string) $trcl_entry->records_affected ); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
