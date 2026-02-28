<?php
/**
 * Products admin view — product index status and manual reindex.
 *
 * @package TrillChatLite\Admin
 * @since 1.0.0
 * @license GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$trcl_indexer      = new \TrillChatLite\WooCommerce\ProductIndexer();
$trcl_index_status = $trcl_indexer->get_status();
$trcl_product_count = \wp_count_posts( 'product' );
$trcl_total_wc     = (int) ( $trcl_product_count->publish ?? 0 );
$trcl_indexed      = $trcl_index_status['indexed'];
$trcl_last_indexed = $trcl_index_status['last_indexed'];
$trcl_is_synced    = ( $trcl_indexed === $trcl_total_wc && $trcl_indexed > 0 );
?>

<div class="wrap tcl-products-page">
	<h1><?php esc_html_e( 'Trill AI Chat — Products', 'trill-ai-chat-lite' ); ?></h1>

	<!-- Index Status Card -->
	<div class="trcl-card" style="background: #fff; padding: 20px; border: 1px solid #c3c4c7; border-radius: 4px; margin: 20px 0;">
		<h2 style="margin-top: 0;"><?php esc_html_e( 'Product Index Status', 'trill-ai-chat-lite' ); ?></h2>

		<table class="widefat" style="max-width: 500px;">
			<tbody>
				<tr>
					<td><strong><?php esc_html_e( 'WooCommerce Products', 'trill-ai-chat-lite' ); ?></strong></td>
					<td><?php echo esc_html( $trcl_total_wc ); ?></td>
				</tr>
				<tr>
					<td><strong><?php esc_html_e( 'Indexed Products', 'trill-ai-chat-lite' ); ?></strong></td>
					<td>
						<?php echo esc_html( $trcl_indexed ); ?>
						<?php if ( $trcl_is_synced ) : ?>
							<span style="color: #00a32a; margin-left: 6px;">&#10003; <?php esc_html_e( 'In sync', 'trill-ai-chat-lite' ); ?></span>
						<?php elseif ( $trcl_indexed > 0 ) : ?>
							<span style="color: #dba617; margin-left: 6px;">&#9888; <?php esc_html_e( 'Out of sync', 'trill-ai-chat-lite' ); ?></span>
						<?php else : ?>
							<span style="color: #d63638; margin-left: 6px;">&#9679; <?php esc_html_e( 'Not indexed', 'trill-ai-chat-lite' ); ?></span>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<td><strong><?php esc_html_e( 'Last Indexed', 'trill-ai-chat-lite' ); ?></strong></td>
					<td>
						<?php if ( ! empty( $trcl_last_indexed ) ) : ?>
							<?php echo esc_html( $trcl_last_indexed ); ?>
						<?php else : ?>
							<em><?php esc_html_e( 'Never', 'trill-ai-chat-lite' ); ?></em>
						<?php endif; ?>
					</td>
				</tr>
			</tbody>
		</table>

		<p style="margin-top: 16px;">
			<button type="button" id="trcl-reindex-btn" class="button button-primary">
				<?php esc_html_e( 'Reindex Products Now', 'trill-ai-chat-lite' ); ?>
			</button>
			<span id="trcl-reindex-status" style="margin-left: 10px; display: none;"></span>
		</p>

		<p class="description">
			<?php esc_html_e( 'The product index allows the AI chat to answer questions about your products. It refreshes automatically every hour via cron.', 'trill-ai-chat-lite' ); ?>
		</p>
	</div>

	<!-- How it works -->
	<div class="trcl-card" style="background: #f0f6fc; border: 1px solid #c3c4c7; padding: 16px 20px; border-radius: 4px; margin: 20px 0;">
		<h3 style="margin-top: 0;"><?php esc_html_e( 'How Product Indexing Works', 'trill-ai-chat-lite' ); ?></h3>
		<p><?php esc_html_e( 'The Lite version uses WooCommerce native search to find products relevant to customer questions. The index counts your published products so the AI knows what is available in your store.', 'trill-ai-chat-lite' ); ?></p>
		<p><?php esc_html_e( 'When a customer asks about a product, the AI searches your WooCommerce catalogue in real time and includes matching products in its response.', 'trill-ai-chat-lite' ); ?></p>
	</div>
</div>
