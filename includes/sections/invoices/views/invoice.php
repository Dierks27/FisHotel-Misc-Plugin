<?php
/**
 * One invoice, read-only: what it is right now, its link, its lines,
 * what the product really holds, the orders that contain it, who did
 * what and when — and the few things that may be done with it. The
 * default path for someone who already has an invoice is "Add fish for
 * this customer": a NEW invoice.
 *
 * @package FisHotel\Misc\Sections\Invoices
 *
 * @var array  $data      Admin::invoice_data() + notice.
 * @var array  $settings  Settings.
 * @var string $tab       Current tab.
 * @var bool   $tables_ok Whether the section's tables are installed.
 * @var bool   $shop_ok   Whether WooCommerce is active.
 */

defined( 'ABSPATH' ) || exit;

use FisHotel\Misc\Sections\Invoices\Admin;
use FisHotel\Misc\Sections\Invoices\Invoices;
use FisHotel\Misc\Sections\Invoices\Lines;
use FisHotel\Misc\Sections\Invoices\Rules;
use FisHotel\Misc\Sections\Invoices\Settings;

$fh_inv     = $data['invoice'];
$fh_eff     = $data['effective'];
$fh_state   = (string) $fh_eff['state'];
$fh_product = $data['product'];
$fh_lines   = $data['lines'];
$fh_link    = $fh_product && 'publish' === $fh_product['status'] ? (string) $fh_product['permalink'] : '';
$fh_title   = '' !== $fh_inv['title'] ? $fh_inv['title'] : sprintf( /* translators: 1: invoice id, 2: handle */ __( 'Draft #%1$d for %2$s', 'fishotel-misc-plugin' ), $fh_inv['id'], $fh_inv['handle'] );
?>
<div class="wrap fishotel-misc-wrap fh-inv-wrap fh-inv-view" data-invoice="<?php echo esc_attr( $fh_inv['id'] ); ?>">

	<?php include Invoices::path() . 'views/tabs.php'; ?>

	<?php if ( ! empty( $data['notice'] ) ) : ?>
		<div class="fishotel-misc-notice fishotel-misc-notice--error"><?php echo esc_html( $data['notice'] ); ?></div>
	<?php endif; ?>

	<div class="fishotel-misc-card fh-inv-card">
		<div class="fh-inv-head">
			<div>
				<h2 class="fh-inv-title"><?php echo esc_html( $fh_title ); ?></h2>
				<p class="fh-inv-meta">
					<?php echo Admin::badge( $fh_state ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in badge(). ?>
					<?php if ( 'paid' === $fh_state && $fh_eff['order'] ) : ?>
						<a href="<?php echo esc_url( $fh_eff['order']['edit_url'] ); ?>"><?php echo esc_html( sprintf( /* translators: %s: order number and status */ __( 'order %s', 'fishotel-misc-plugin' ), Rules::order_label( $fh_eff['order'] ) ) ); ?></a>
					<?php endif; ?>
				</p>
			</div>
			<div class="fh-inv-total"><?php echo esc_html( Lines::dollars( $fh_inv['total'] ) ); ?></div>
		</div>

		<?php if ( '' !== $fh_eff['lock_reason'] ) : ?>
			<div class="fishotel-misc-notice <?php echo 'paid' === $fh_state ? 'fishotel-misc-notice--success' : 'fishotel-misc-notice--warning'; ?>">
				<span class="dashicons dashicons-lock"></span> <?php echo esc_html( $fh_eff['lock_reason'] ); ?>
			</div>
		<?php endif; ?>

		<?php if ( '' !== $fh_link ) : ?>
			<div class="fh-inv-linkrow">
				<label for="fh-inv-link"><?php esc_html_e( 'The link for the customer', 'fishotel-misc-plugin' ); ?></label>
				<input type="text" id="fh-inv-link" class="fh-inv-input fh-inv-link" readonly value="<?php echo esc_attr( $fh_link ); ?>">
				<button type="button" class="button button-primary fh-inv-copy" data-copy="<?php echo esc_attr( $fh_link ); ?>"><?php esc_html_e( 'Copy', 'fishotel-misc-plugin' ); ?></button>
				<a class="button" href="<?php echo esc_url( $fh_link ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Open', 'fishotel-misc-plugin' ); ?></a>
			</div>
		<?php endif; ?>

		<div class="fh-inv-buttons">
			<a class="button button-primary" href="<?php echo esc_url( Admin::page_url( 'new', array( 'from' => $fh_inv['id'] ) ) ); ?>"><?php esc_html_e( 'Add fish for this customer', 'fishotel-misc-plugin' ); ?></a>

			<?php if ( 'draft' === $fh_state ) : ?>
				<a class="button" href="<?php echo esc_url( Admin::page_url( 'edit', array( 'id' => $fh_inv['id'] ) ) ); ?>"><?php esc_html_e( 'Edit draft', 'fishotel-misc-plugin' ); ?></a>
				<button type="button" class="button fh-inv-discard" data-id="<?php echo esc_attr( $fh_inv['id'] ); ?>"><?php esc_html_e( 'Withdraw draft', 'fishotel-misc-plugin' ); ?></button>
			<?php endif; ?>

			<?php if ( '' !== $fh_link ) : ?>
				<a class="button" href="<?php echo esc_url( Admin::page_url( 'forum', array( 'ids' => $fh_inv['id'] ) ) ); ?>"><?php esc_html_e( 'Forum line', 'fishotel-misc-plugin' ); ?></a>
			<?php endif; ?>

			<?php if ( $fh_eff['can_edit'] && 'published' === $fh_state ) : ?>
				<button type="button" class="button fh-inv-edit-unpaid" data-url="<?php echo esc_attr( Admin::page_url( 'edit', array( 'id' => $fh_inv['id'], 'unpaid' => '1' ) ) ); ?>"><?php esc_html_e( 'Edit this unpaid invoice…', 'fishotel-misc-plugin' ); ?></button>
			<?php endif; ?>

			<?php if ( $fh_eff['can_trash'] ) : ?>
				<button type="button" class="button fh-inv-trash" data-id="<?php echo esc_attr( $fh_inv['id'] ); ?>"><?php esc_html_e( 'Move to the Trash', 'fishotel-misc-plugin' ); ?></button>
			<?php endif; ?>

			<span class="fh-inv-status" aria-live="polite"></span>
		</div>

		<p class="fh-inv-footnote">
			<?php esc_html_e( '"Add fish for this customer" starts a NEW invoice: an invoice a customer already has is not added to, and no fish goes on two invoices.', 'fishotel-misc-plugin' ); ?>
			<?php if ( 'published' === $fh_state && ! $fh_eff['can_trash'] && '' !== $fh_eff['trash_reason'] ) : ?>
				<?php echo esc_html( $fh_eff['trash_reason'] ); ?>
			<?php endif; ?>
		</p>
	</div>

	<div class="fh-inv-grid">
		<div class="fishotel-misc-card fh-inv-card">
			<h3 class="fh-inv-h3"><?php esc_html_e( 'Customer', 'fishotel-misc-plugin' ); ?></h3>
			<dl class="fh-inv-dl">
				<dt><?php esc_html_e( 'Handle', 'fishotel-misc-plugin' ); ?></dt>
				<dd><?php echo esc_html( $fh_inv['handle'] ); ?></dd>
				<dt><?php esc_html_e( 'Forum user id', 'fishotel-misc-plugin' ); ?></dt>
				<dd><?php echo esc_html( $fh_inv['forum_user_id'] ? $fh_inv['forum_user_id'] : '—' ); ?></dd>
				<dt><?php esc_html_e( 'Site customer', 'fishotel-misc-plugin' ); ?></dt>
				<dd>
					<?php if ( $data['customer'] ) : ?>
						<a href="<?php echo esc_url( admin_url( 'user-edit.php?user_id=' . (int) $data['customer']['id'] ) ); ?>"><?php echo esc_html( $data['customer']['name'] ); ?></a>
						<span class="fh-inv-sub"><?php echo esc_html( implode( ', ', $data['customer']['emails'] ) ); ?></span>
					<?php elseif ( $fh_inv['customer_id'] ) : ?>
						<?php echo esc_html( sprintf( /* translators: %d: user id */ __( 'user #%d (no longer exists)', 'fishotel-misc-plugin' ), $fh_inv['customer_id'] ) ); ?>
					<?php else : ?>
						<?php esc_html_e( 'not linked', 'fishotel-misc-plugin' ); ?>
					<?php endif; ?>
				</dd>
				<dt><?php esc_html_e( 'Name', 'fishotel-misc-plugin' ); ?></dt>
				<dd><?php echo esc_html( '' !== $fh_inv['customer_name'] ? $fh_inv['customer_name'] : '—' ); ?></dd>
				<dt><?php esc_html_e( 'E-mail', 'fishotel-misc-plugin' ); ?></dt>
				<dd><?php echo esc_html( '' !== $fh_inv['customer_email'] ? $fh_inv['customer_email'] : '—' ); ?></dd>
				<dt><?php esc_html_e( 'Shipping', 'fishotel-misc-plugin' ); ?></dt>
				<dd>
					<?php echo esc_html( Settings::shipping_label( $fh_inv['shipping'] ) ); ?>
					<?php if ( '' !== $fh_inv['shipping_slug'] ) : ?>
						<span class="fh-inv-sub"><?php echo esc_html( sprintf( /* translators: %s: shipping class slug */ __( 'class "%s"', 'fishotel-misc-plugin' ), $fh_inv['shipping_slug'] ) ); ?></span>
					<?php endif; ?>
				</dd>
			</dl>
		</div>

		<div class="fishotel-misc-card fh-inv-card">
			<h3 class="fh-inv-h3"><?php esc_html_e( 'Who and when', 'fishotel-misc-plugin' ); ?></h3>
			<dl class="fh-inv-dl">
				<dt><?php esc_html_e( 'Built', 'fishotel-misc-plugin' ); ?></dt>
				<dd><?php echo esc_html( trim( Admin::site_time( $fh_inv['created_at'] ) . ( '' !== $data['who']['created'] ? ' · ' . $data['who']['created'] : '' ) ) ); ?></dd>
				<dt><?php esc_html_e( 'Last changed', 'fishotel-misc-plugin' ); ?></dt>
				<dd><?php echo esc_html( trim( Admin::site_time( $fh_inv['updated_at'] ) . ( '' !== $data['who']['updated'] ? ' · ' . $data['who']['updated'] : '' ) ) ); ?></dd>
				<dt><?php esc_html_e( 'Published', 'fishotel-misc-plugin' ); ?></dt>
				<dd><?php echo esc_html( $fh_inv['published_at'] ? trim( Admin::site_time( $fh_inv['published_at'] ) . ( '' !== $data['who']['published'] ? ' · ' . $data['who']['published'] : '' ) ) : '—' ); ?></dd>
				<dt><?php esc_html_e( 'Paid', 'fishotel-misc-plugin' ); ?></dt>
				<dd>
					<?php if ( 'paid' === $fh_state ) : ?>
						<?php echo esc_html( Admin::site_time( $fh_inv['paid_at'] ) ); ?>
						<?php if ( $fh_eff['order'] ) : ?>
							· <a href="<?php echo esc_url( $fh_eff['order']['edit_url'] ); ?>"><?php echo esc_html( Rules::order_label( $fh_eff['order'] ) ); ?></a>
						<?php endif; ?>
					<?php else : ?>
						<?php esc_html_e( 'not paid', 'fishotel-misc-plugin' ); ?>
					<?php endif; ?>
				</dd>
			</dl>
			<?php if ( '' !== $fh_inv['notes'] ) : ?>
				<h3 class="fh-inv-h3"><?php esc_html_e( 'Notes (internal)', 'fishotel-misc-plugin' ); ?></h3>
				<p class="fh-inv-notes"><?php echo nl2br( esc_html( $fh_inv['notes'] ) ); ?></p>
			<?php endif; ?>
		</div>
	</div>

	<div class="fishotel-misc-card fh-inv-card">
		<h3 class="fh-inv-h3"><?php esc_html_e( 'Lines', 'fishotel-misc-plugin' ); ?></h3>
		<?php if ( ! $fh_lines ) : ?>
			<p class="fh-inv-footnote"><?php esc_html_e( 'No lines.', 'fishotel-misc-plugin' ); ?></p>
		<?php else : ?>
			<table class="widefat fh-inv-table">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Line', 'fishotel-misc-plugin' ); ?></th>
						<th scope="col" class="fh-inv-num"><?php esc_html_e( 'Qty', 'fishotel-misc-plugin' ); ?></th>
						<th scope="col" class="fh-inv-num"><?php esc_html_e( 'Each', 'fishotel-misc-plugin' ); ?></th>
						<th scope="col" class="fh-inv-num"><?php esc_html_e( 'Amount', 'fishotel-misc-plugin' ); ?></th>
						<th scope="col"><?php esc_html_e( 'From', 'fishotel-misc-plugin' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( array_values( $fh_lines ) as $fh_index => $fh_line ) : ?>
						<tr class="fh-inv-line fh-inv-line--<?php echo esc_attr( $fh_line['kind'] ); ?>">
							<td>
								<?php echo esc_html( Lines::public_name( $fh_line ) ); ?>
								<?php foreach ( isset( $data['line_notes'][ $fh_index ] ) ? $data['line_notes'][ $fh_index ] : array() as $fh_note ) : ?>
									<span class="fh-inv-sub fh-inv-warn"><?php echo esc_html( $fh_note ); ?></span>
								<?php endforeach; ?>
							</td>
							<td class="fh-inv-num"><?php echo esc_html( 'fish' === $fh_line['kind'] ? $fh_line['qty'] : '' ); ?></td>
							<td class="fh-inv-num"><?php echo esc_html( 'fish' === $fh_line['kind'] && null !== $fh_line['unit_price'] ? Lines::dollars( $fh_line['unit_price'] ) : '' ); ?></td>
							<td class="fh-inv-num"><?php echo esc_html( 'note' === $fh_line['kind'] ? '' : ( 'fish' === $fh_line['kind'] && null === $fh_line['unit_price'] ? __( 'no price', 'fishotel-misc-plugin' ) : Lines::dollars( Lines::amount( $fh_line ) ) ) ); ?></td>
							<td>
								<?php echo esc_html( Admin::source_text( $fh_line ) ); ?>
								<?php if ( 'fish' === $fh_line['kind'] && null !== $fh_line['size_label'] ) : ?>
									<span class="fh-inv-sub"><?php echo esc_html( $fh_line['size_label'] ); ?></span>
								<?php endif; ?>
								<?php if ( 'fish' === $fh_line['kind'] && 'product' === $fh_line['source'] ) : ?>
									<span class="fh-inv-sub"><?php echo $fh_line['take_stock'] ? esc_html__( 'takes from shop stock when paid', 'fishotel-misc-plugin' ) : esc_html__( 'shop stock is not touched', 'fishotel-misc-plugin' ); ?></span>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
				<tfoot>
					<tr>
						<th scope="row" colspan="3"><?php echo null !== $fh_inv['flat_total'] ? esc_html__( 'Total (a flat total was charged)', 'fishotel-misc-plugin' ) : esc_html__( 'Total', 'fishotel-misc-plugin' ); ?></th>
						<td class="fh-inv-num"><strong><?php echo esc_html( Lines::dollars( Lines::total( $fh_lines ) ) ); ?></strong></td>
						<td></td>
					</tr>
				</tfoot>
			</table>
		<?php endif; ?>
	</div>

	<?php if ( $fh_product ) : ?>
		<div class="fishotel-misc-card fh-inv-card">
			<h3 class="fh-inv-h3">
				<?php esc_html_e( 'The product, as it is in the shop right now', 'fishotel-misc-plugin' ); ?>
				<a class="fh-inv-h3__link" href="<?php echo esc_url( $fh_product['edit_url'] ); ?>"><?php echo esc_html( sprintf( /* translators: %d: product id */ __( 'product #%d', 'fishotel-misc-plugin' ), $fh_product['id'] ) ); ?></a>
			</h3>
			<dl class="fh-inv-dl fh-inv-dl--wide">
				<dt><?php esc_html_e( 'Regular price', 'fishotel-misc-plugin' ); ?></dt>
				<dd>
					<?php echo esc_html( '' !== $fh_product['regular_price'] ? Lines::dollars( (float) $fh_product['regular_price'] ) : '—' ); ?>
					<?php if ( '' !== $fh_product['regular_price'] && Lines::cents( $fh_product['regular_price'] ) !== Lines::cents( $fh_inv['total'] ) ) : ?>
						<span class="fh-inv-sub fh-inv-warn"><?php esc_html_e( 'not the total of this invoice — it was changed on the product', 'fishotel-misc-plugin' ); ?></span>
					<?php endif; ?>
				</dd>
				<dt><?php esc_html_e( 'Sale price', 'fishotel-misc-plugin' ); ?></dt>
				<dd>
					<?php if ( '' !== $fh_product['sale_price'] ) : ?>
						<?php echo esc_html( Lines::dollars( (float) $fh_product['sale_price'] ) ); ?>
						<span class="fh-inv-sub fh-inv-warn"><?php esc_html_e( 'set by hand: the customer pays this, not the total. This section never sets or clears a sale price.', 'fishotel-misc-plugin' ); ?></span>
					<?php else : ?>
						<?php esc_html_e( 'none', 'fishotel-misc-plugin' ); ?>
					<?php endif; ?>
				</dd>
				<dt><?php esc_html_e( 'Stock', 'fishotel-misc-plugin' ); ?></dt>
				<dd>
					<?php
					echo esc_html(
						$fh_product['manage_stock']
							/* translators: 1: stock quantity, 2: backorders setting, 3: stock status */
							? sprintf( __( 'managed, quantity %1$s, backorders: %2$s, %3$s', 'fishotel-misc-plugin' ), null === $fh_product['stock_quantity'] ? '—' : $fh_product['stock_quantity'], $fh_product['backorders'], $fh_product['stock_status'] )
							: __( 'not managed — nothing stops it from being paid twice', 'fishotel-misc-plugin' )
					);
					?>
				</dd>
				<dt><?php esc_html_e( 'Visibility', 'fishotel-misc-plugin' ); ?></dt>
				<dd><?php echo esc_html( $fh_product['status'] . ' · ' . $fh_product['catalog_visibility'] ); ?></dd>
				<dt><?php esc_html_e( 'Category', 'fishotel-misc-plugin' ); ?></dt>
				<dd><?php echo esc_html( $fh_product['category_slugs'] ? implode( ', ', $fh_product['category_slugs'] ) : '—' ); ?></dd>
				<dt><?php esc_html_e( 'Shipping class', 'fishotel-misc-plugin' ); ?></dt>
				<dd><?php echo esc_html( '' !== $fh_product['shipping_class'] ? $fh_product['shipping_class'] : '—' ); ?></dd>
			</dl>
		</div>
	<?php endif; ?>

	<?php if ( $data['orders'] ) : ?>
		<div class="fishotel-misc-card fh-inv-card">
			<h3 class="fh-inv-h3"><?php esc_html_e( 'Orders that contain this invoice', 'fishotel-misc-plugin' ); ?></h3>
			<ul class="fh-inv-orders">
				<?php foreach ( $data['orders'] as $fh_order ) : ?>
					<li>
						<a href="<?php echo esc_url( $fh_order['edit_url'] ); ?>"><?php echo esc_html( Rules::order_label( $fh_order ) ); ?></a>
						<?php echo Admin::badge( $fh_order['paid'] && ! Rules::order_is_dead( $fh_order ) ? 'paid' : 'draft', $fh_order['paid'] ? __( 'PAID', 'fishotel-misc-plugin' ) : __( 'not paid', 'fishotel-misc-plugin' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in badge(). ?>
						<?php echo esc_html( Lines::dollars( $fh_order['total'] ) ); ?>
						<?php if ( '' !== $fh_order['billing_name'] ) : ?>
							· <?php echo esc_html( $fh_order['billing_name'] ); ?>
						<?php endif; ?>
					</li>
				<?php endforeach; ?>
			</ul>
		</div>
	<?php endif; ?>

	<?php if ( $data['others'] ) : ?>
		<div class="fishotel-misc-card fh-inv-card">
			<h3 class="fh-inv-h3"><?php esc_html_e( 'Other invoices of this customer, built here', 'fishotel-misc-plugin' ); ?></h3>
			<ul class="fh-inv-orders">
				<?php foreach ( $data['others'] as $fh_other ) : ?>
					<li>
						<a href="<?php echo esc_url( Admin::page_url( 'invoice', array( 'id' => $fh_other['id'] ) ) ); ?>"><?php echo esc_html( '' !== $fh_other['title'] ? $fh_other['title'] : sprintf( /* translators: %d: invoice id */ __( 'Draft #%d', 'fishotel-misc-plugin' ), $fh_other['id'] ) ); ?></a>
						<?php echo Admin::badge( $fh_other['state'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in badge(). ?>
						<?php echo esc_html( Lines::dollars( $fh_other['total'] ) ); ?>
					</li>
				<?php endforeach; ?>
			</ul>
		</div>
	<?php endif; ?>
</div>
