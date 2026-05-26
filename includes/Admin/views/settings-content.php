<?php
/**
 * Settings — Content tab view (v2.0 Block 1, page content indexing).
 *
 * Rendered by settings.php when ?tab=content. Provides:
 *   - Master enable toggle.
 *   - Per-post-type toggles (Pages, Posts, Product categories).
 *   - Per-page selector (checkbox grid; empty selection = all published).
 *   - Auto-reindex toggle.
 *   - Read-only status (last indexed, indexed sources, total chunks).
 *   - "Reindex now" button (admin-post submission with nonce).
 *
 * The settings form posts to options.php with the `trcl_settings`
 * group; sanitisation lives in Settings::sanitize_content_settings.
 * The Reindex action is a separate form posting to admin-post.php so
 * a long-running reindex doesn't collide with the settings save flow.
 *
 * @package TrillChatLite\Admin
 * @since 2.0.0
 * @license GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$trcl_content_settings = new \TrillChatLite\Content\ContentSettings();
$trcl_content_indexer  = new \TrillChatLite\Content\ContentIndexer( $trcl_content_settings );

$trcl_cs        = $trcl_content_settings->get();
$trcl_status    = $trcl_content_indexer->get_status();

$trcl_enabled       = $trcl_cs['enabled'];
$trcl_auto_reindex  = $trcl_cs['auto_reindex'];
$trcl_pt_page       = $trcl_cs['post_types']['page'] ?? '0';
$trcl_pt_post       = $trcl_cs['post_types']['post'] ?? '0';
$trcl_pt_prodcat    = $trcl_cs['post_types']['product_cat'] ?? '0';
$trcl_included_page = $trcl_cs['included_ids']['page'] ?? [];
$trcl_included_post = $trcl_cs['included_ids']['post'] ?? [];

// Acknowledge a recent reindex / save (set by the admin-post handler
// via a transient — see Admin::handle_reindex_content_post).
$trcl_reindex_notice = \get_transient( 'trcl_reindex_content_notice' );
if ( $trcl_reindex_notice ) {
    \delete_transient( 'trcl_reindex_content_notice' );
}

// Settings API success notice (?settings-updated=true after options.php).
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display flag.
$trcl_settings_updated = isset( $_GET['settings-updated'] ) && $_GET['settings-updated'] === 'true';

$trcl_pages = \get_posts( [
    'post_type'      => 'page',
    'post_status'    => 'publish',
    'posts_per_page' => 200,
    'orderby'        => 'menu_order title',
    'order'          => 'ASC',
] );
$trcl_posts = \get_posts( [
    'post_type'      => 'post',
    'post_status'    => 'publish',
    'posts_per_page' => 200,
    'orderby'        => 'date',
    'order'          => 'DESC',
] );

$trcl_action_url = \admin_url( 'admin-post.php' );
?>

<?php if ( $trcl_settings_updated ) : ?>
    <div class="notice notice-success is-dismissible">
        <p><?php esc_html_e( 'Content settings saved.', 'trill-ai-chat-lite' ); ?></p>
    </div>
<?php endif; ?>

<?php if ( is_array( $trcl_reindex_notice ) && isset( $trcl_reindex_notice['type'], $trcl_reindex_notice['message'] ) ) : ?>
    <div class="notice notice-<?php echo esc_attr( $trcl_reindex_notice['type'] ); ?> is-dismissible">
        <p><?php echo esc_html( $trcl_reindex_notice['message'] ); ?></p>
    </div>
<?php endif; ?>

<p class="description" style="max-width: 720px;">
    <?php
    esc_html_e(
        'Let Robin (the AI assistant) answer questions about your store\'s pages — FAQ, shipping, returns, contact, etc. — by indexing selected content. Robin always knows your products; this tab teaches it everything else.',
        'trill-ai-chat-lite'
    );
    ?>
</p>

<form method="post" action="options.php">
    <?php settings_fields( 'trcl_settings' ); ?>

    <table class="form-table" role="presentation">

        <!-- Master enable toggle -->
        <tr>
            <th scope="row"><?php esc_html_e( 'Enable content indexing', 'trill-ai-chat-lite' ); ?></th>
            <td>
                <label>
                    <input type="checkbox"
                           name="trcl_content_settings[enabled]"
                           value="1"
                           <?php checked( $trcl_enabled, '1' ); ?> />
                    <?php esc_html_e( 'Allow Robin to use indexed page content when answering customer questions', 'trill-ai-chat-lite' ); ?>
                </label>
                <p class="description">
                    <?php esc_html_e( 'When off, only product information is sent to the AI.', 'trill-ai-chat-lite' ); ?>
                </p>
            </td>
        </tr>

        <!-- Post type toggles -->
        <tr>
            <th scope="row"><?php esc_html_e( 'What to index', 'trill-ai-chat-lite' ); ?></th>
            <td>
                <label style="display: block; margin-bottom: 6px;">
                    <input type="checkbox"
                           name="trcl_content_settings[post_types][page]"
                           value="1"
                           <?php checked( $trcl_pt_page, '1' ); ?> />
                    <?php esc_html_e( 'WordPress Pages', 'trill-ai-chat-lite' ); ?>
                    <span style="color:#777;">— <?php
                        /* translators: %d: number of published pages */
                        printf( esc_html__( '%d published', 'trill-ai-chat-lite' ), count( $trcl_pages ) );
                    ?></span>
                </label>

                <label style="display: block; margin-bottom: 6px;">
                    <input type="checkbox"
                           name="trcl_content_settings[post_types][post]"
                           value="1"
                           <?php checked( $trcl_pt_post, '1' ); ?> />
                    <?php esc_html_e( 'Blog posts', 'trill-ai-chat-lite' ); ?>
                    <span style="color:#777;">— <?php
                        /* translators: %d: number of published posts */
                        printf( esc_html__( '%d published', 'trill-ai-chat-lite' ), count( $trcl_posts ) );
                    ?></span>
                </label>

                <label style="display: block;">
                    <input type="checkbox"
                           name="trcl_content_settings[post_types][product_cat]"
                           value="1"
                           <?php checked( $trcl_pt_prodcat, '1' ); ?> />
                    <?php esc_html_e( 'Product category descriptions', 'trill-ai-chat-lite' ); ?>
                </label>
                <p class="description">
                    <?php esc_html_e( 'Robin always knows your individual products via WooCommerce search — indexing them again here would be redundant.', 'trill-ai-chat-lite' ); ?>
                </p>
            </td>
        </tr>

        <!-- Per-page selector -->
        <tr>
            <th scope="row"><?php esc_html_e( 'Pages to include', 'trill-ai-chat-lite' ); ?></th>
            <td>
                <?php if ( empty( $trcl_pages ) ) : ?>
                    <p><em><?php esc_html_e( 'No published pages on this site yet.', 'trill-ai-chat-lite' ); ?></em></p>
                <?php else : ?>
                    <div style="max-height: 280px; overflow-y: auto; border: 1px solid #c3c4c7; border-radius: 4px; padding: 10px; background: #fff; max-width: 520px;">
                        <?php foreach ( $trcl_pages as $trcl_page ) :
                            $trcl_selected = empty( $trcl_included_page ) || in_array( (int) $trcl_page->ID, array_map( 'intval', $trcl_included_page ), true );
                        ?>
                            <label style="display: block; padding: 3px 0;">
                                <input type="checkbox"
                                       name="trcl_content_settings[included_ids][page][]"
                                       value="<?php echo esc_attr( $trcl_page->ID ); ?>"
                                       <?php checked( $trcl_selected, true ); ?> />
                                <?php echo esc_html( $trcl_page->post_title ); ?>
                                <span style="color:#777;">(ID <?php echo (int) $trcl_page->ID; ?>)</span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                    <p class="description">
                        <?php esc_html_e( 'Leave all unchecked to index every published page. Tick specific pages to narrow the index to those only.', 'trill-ai-chat-lite' ); ?>
                    </p>
                <?php endif; ?>
            </td>
        </tr>

        <!-- Posts selector (only if posts opted-in or any are present) -->
        <?php if ( ! empty( $trcl_posts ) ) : ?>
        <tr>
            <th scope="row"><?php esc_html_e( 'Posts to include', 'trill-ai-chat-lite' ); ?></th>
            <td>
                <div style="max-height: 220px; overflow-y: auto; border: 1px solid #c3c4c7; border-radius: 4px; padding: 10px; background: #fff; max-width: 520px;">
                    <?php foreach ( $trcl_posts as $trcl_post ) :
                        $trcl_selected = empty( $trcl_included_post ) || in_array( (int) $trcl_post->ID, array_map( 'intval', $trcl_included_post ), true );
                    ?>
                        <label style="display: block; padding: 3px 0;">
                            <input type="checkbox"
                                   name="trcl_content_settings[included_ids][post][]"
                                   value="<?php echo esc_attr( $trcl_post->ID ); ?>"
                                   <?php checked( $trcl_selected, true ); ?> />
                            <?php echo esc_html( $trcl_post->post_title ); ?>
                            <span style="color:#777;">(ID <?php echo (int) $trcl_post->ID; ?>)</span>
                        </label>
                    <?php endforeach; ?>
                </div>
                <p class="description">
                    <?php esc_html_e( 'Only used when "Blog posts" is checked above. Leave all unchecked to index every published post.', 'trill-ai-chat-lite' ); ?>
                </p>
            </td>
        </tr>
        <?php endif; ?>

        <!-- Auto-reindex toggle -->
        <tr>
            <th scope="row"><?php esc_html_e( 'Automatic re-indexing', 'trill-ai-chat-lite' ); ?></th>
            <td>
                <label>
                    <input type="checkbox"
                           name="trcl_content_settings[auto_reindex]"
                           value="1"
                           <?php checked( $trcl_auto_reindex, '1' ); ?> />
                    <?php esc_html_e( 'Re-index automatically when content changes', 'trill-ai-chat-lite' ); ?>
                </label>
                <p class="description">
                    <?php esc_html_e( 'When on, editing a page or category description refreshes the index in real time. A daily safety-net cron also runs in the background.', 'trill-ai-chat-lite' ); ?>
                </p>
            </td>
        </tr>

    </table>

    <?php submit_button( __( 'Save content settings', 'trill-ai-chat-lite' ) ); ?>
</form>

<hr style="margin: 30px 0;" />

<h2><?php esc_html_e( 'Index status', 'trill-ai-chat-lite' ); ?></h2>

<table class="widefat striped" style="max-width: 520px;">
    <tbody>
        <tr>
            <th scope="row" style="width: 200px;">
                <?php esc_html_e( 'Indexed sources', 'trill-ai-chat-lite' ); ?>
            </th>
            <td><?php echo (int) $trcl_status['indexed_sources']; ?></td>
        </tr>
        <tr>
            <th scope="row"><?php esc_html_e( 'Total chunks', 'trill-ai-chat-lite' ); ?></th>
            <td><?php echo (int) $trcl_status['total_chunks']; ?></td>
        </tr>
        <tr>
            <th scope="row"><?php esc_html_e( 'Last indexed', 'trill-ai-chat-lite' ); ?></th>
            <td>
                <?php echo $trcl_status['last_indexed']
                    ? esc_html( $trcl_status['last_indexed'] )
                    : '<em>' . esc_html__( 'Never', 'trill-ai-chat-lite' ) . '</em>'; ?>
            </td>
        </tr>
    </tbody>
</table>

<p style="margin-top: 16px;">
    <form method="post" action="<?php echo esc_url( $trcl_action_url ); ?>" style="display: inline;">
        <input type="hidden" name="action" value="trcl_reindex_content" />
        <?php wp_nonce_field( 'trcl_reindex_content' ); ?>
        <?php submit_button(
            __( 'Reindex now', 'trill-ai-chat-lite' ),
            'secondary',
            'submit',
            false
        ); ?>
        <span class="description" style="margin-left: 10px;">
            <?php esc_html_e( 'Rebuild the full content index for all opted-in sources.', 'trill-ai-chat-lite' ); ?>
        </span>
    </form>
</p>
