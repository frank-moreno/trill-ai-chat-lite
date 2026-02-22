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

$trill_chat_lite_indexer      = new \TrillChatLite\WooCommerce\ProductIndexer();
$trill_chat_lite_index_status = $trill_chat_lite_indexer->get_status();
$trill_chat_lite_product_count = \wp_count_posts( 'product' );
$trill_chat_lite_total_wc     = (int) ( $trill_chat_lite_product_count->publish ?? 0 );
$trill_chat_lite_indexed      = $trill_chat_lite_index_status['indexed'];
$trill_chat_lite_last_indexed = $trill_chat_lite_index_status['last_indexed'];
$trill_chat_lite_is_synced    = ( $trill_chat_lite_indexed === $trill_chat_lite_total_wc && $trill_chat_lite_indexed > 0 );
?>

<div class="wrap tcl-products-page">
	<h1><?php esc_html_e( 'Trill AI Chat — Products', 'trill-chat-lite' ); ?></h1>

	<!-- Index Status Card -->
	<div class="tcl-card" style="background: #fff; padding: 20px; border: 1px solid #c3c4c7; border-radius: 4px; margin: 20px 0;">
		<h2 style="margin-top: 0;"><?php esc_html_e( 'Product Index Status', 'trill-chat-lite' ); ?></h2>

		<table class="widefat" style="max-width: 500px;">
			<tbody>
				<tr>
					<td><strong><?php esc_html_e( 'WooCommerce Products', 'trill-chat-lite' ); ?></strong></td>
					<td><?php echo esc_html( $trill_chat_lite_total_wc ); ?></td>
				</tr>
				<tr>
					<td><strong><?php esc_html_e( 'Indexed Products', 'trill-chat-lite' ); ?></strong></td>
					<td>
						<?php echo esc_html( $trill_chat_lite_indexed ); ?>
						<?php if ( $trill_chat_lite_is_synced ) : ?>
							<span style="color: #00a32a; margin-left: 6px;">&#10003; <?php esc_html_e( 'In sync', 'trill-chat-lite' ); ?></span>
						<?php elseif ( $trill_chat_lite_indexed > 0 ) : ?>
							<span style="color: #dba617; margin-left: 6px;">&#9888; <?php esc_html_e( 'Out of sync', 'trill-chat-lite' ); ?></span>
						<?php else : ?>
							<span style="color: #d63638; margin-left: 6px;">&#9679; <?php esc_html_e( 'Not indexed', 'trill-chat-lite' ); ?></span>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<td><strong><?php esc_html_e( 'Last Indexed', 'trill-chat-lite' ); ?></strong></td>
					<td>
						<?php if ( ! empty( $trill_chat_lite_last_indexed ) ) : ?>
							<?php echo esc_html( $trill_chat_lite_last_indexed ); ?>
						<?php else : ?>
							<em><?php esc_html_e( 'Never', 'trill-chat-lite' ); ?></em>
						<?php endif; ?>
					</td>
				</tr>
			</tbody>
		</table>

		<p style="margin-top: 16px;">
			<button type="button" id="tcl-reindex-btn" class="button button-primary">
				<?php esc_html_e( 'Reindex Products Now', 'trill-chat-lite' ); ?>
			</button>
			<span id="tcl-reindex-status" style="margin-left: 10px; display: none;"></span>
		</p>

		<p class="description">
			<?php esc_html_e( 'The product index allows the AI chat to answer questions about your products. It refreshes automatically every hour via cron.', 'trill-chat-lite' ); ?>
		</p>
	</div>

	<!-- How it works -->
	<div class="tcl-card" style="background: #f0f6fc; border: 1px solid #c3c4c7; padding: 16px 20px; border-radius: 4px; margin: 20px 0;">
		<h3 style="margin-top: 0;"><?php esc_html_e( 'How Product Indexing Works', 'trill-chat-lite' ); ?></h3>
		<p><?php esc_html_e( 'The Lite version uses WooCommerce native search to find products relevant to customer questions. The index counts your published products so the AI knows what is available in your store.', 'trill-chat-lite' ); ?></p>
		<p><?php esc_html_e( 'When a customer asks about a product, the AI searches your WooCommerce catalogue in real time and includes matching products in its response.', 'trill-chat-lite' ); ?></p>
	</div>
</div>

<script>
(function($) {
	$('#tcl-reindex-btn').on('click', function() {
		var $btn    = $(this);
		var $status = $('#tcl-reindex-status');

		$btn.prop('disabled', true).text('<?php echo esc_js( __( 'Indexing...', 'trill-chat-lite' ) ); ?>');
		$status.show().css('color', '#50575e').text('<?php echo esc_js( __( 'Please wait...', 'trill-chat-lite' ) ); ?>');

		$.post(ajaxurl, {
			action: 'tclw_reindex_products',
			nonce:  tclAdmin.nonce
		}, function(response) {
			if (response.success) {
				$status.css('color', '#00a32a').text(response.data.message);
				// Reload after a short delay to refresh the table.
				setTimeout(function() { location.reload(); }, 1500);
			} else {
				$status.css('color', '#d63638').text(response.data.message || '<?php echo esc_js( __( 'Indexing failed.', 'trill-chat-lite' ) ); ?>');
			}
		}).fail(function() {
			$status.css('color', '#d63638').text('<?php echo esc_js( __( 'Request failed. Please try again.', 'trill-chat-lite' ) ); ?>');
		}).always(function() {
			$btn.prop('disabled', false).text('<?php echo esc_js( __( 'Reindex Products Now', 'trill-chat-lite' ) ); ?>');
		});
	});
})(jQuery);
</script>
