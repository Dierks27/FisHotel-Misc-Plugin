<?php
/**
 * The pre-check, as it is shown before every Publish: what is wrong
 * (stops Publish) and what is worth a look, what will be written, this
 * customer's orders with PAID or not, this customer's invoices with
 * their lines and paid or not, and every fish that already appears on
 * one of them — each with a box that has to be ticked.
 *
 * Returned as HTML by the pre-check AJAX; everything is escaped here.
 *
 * @package FisHotel\Misc\Sections\Invoices
 *
 * @var array $report Precheck::report().
 */

defined( 'ABSPATH' ) || exit;

use FisHotel\Misc\Sections\Invoices\Admin;
use FisHotel\Misc\Sections\Invoices\Lines;
use FisHotel\Misc\Sections\Invoices\Rules;

$fh_writes = $report['writes'];
$fh_update = 'update' === $report['mode'];
$fh_ways   = array(
	'customer' => __( 'the linked customer', 'fishotel-misc-plugin' ),
	'email'    => __( 'the e-mail', 'fishotel-misc-plugin' ),
	'name'     => __( 'the name', 'fishotel-misc-plugin' ),
	'invoice'  => __( 'an invoice line', 'fishotel-misc-plugin' ),
);
$fh_by     = array(
	'stay'    => __( 'the same tank group', 'fishotel-misc-plugin' ),
	'product' => __( 'the same shop product', 'fishotel-misc-plugin' ),
	'species' => __( 'the same species-list fish', 'fishotel-misc-plugin' ),
	'name'    => __( 'the same name', 'fishotel-misc-plugin' ),
);
?>
<div class="fh-inv-report">

	<?php if ( $report['errors'] ) : ?>
		<div class="fishotel-misc-notice fishotel-misc-notice--error fh-inv-report__errors">
			<strong><?php echo $fh_update ? esc_html__( 'These have to be put right before the changes can be saved:', 'fishotel-misc-plugin' ) : esc_html__( 'These have to be put right before this invoice can be published:', 'fishotel-misc-plugin' ); ?></strong>
			<ul>
				<?php foreach ( $report['errors'] as $fh_text ) : ?>
					<li><?php echo esc_html( $fh_text ); ?></li>
				<?php endforeach; ?>
			</ul>
		</div>
	<?php endif; ?>

	<?php if ( $report['warnings'] || $report['line_notes'] ) : ?>
		<div class="fishotel-misc-notice fishotel-misc-notice--warning">
			<strong><?php esc_html_e( 'Worth a look:', 'fishotel-misc-plugin' ); ?></strong>
			<ul>
				<?php foreach ( $report['warnings'] as $fh_text ) : ?>
					<li><?php echo esc_html( $fh_text ); ?></li>
				<?php endforeach; ?>
				<?php foreach ( $report['line_notes'] as $fh_index => $fh_notes ) : ?>
					<?php foreach ( $fh_notes as $fh_text ) : ?>
						<li><?php echo esc_html( sprintf( /* translators: 1: line number, 2: note */ __( 'Line %1$d: %2$s', 'fishotel-misc-plugin' ), (int) $fh_index + 1, $fh_text ) ); ?></li>
					<?php endforeach; ?>
				<?php endforeach; ?>
			</ul>
		</div>
	<?php endif; ?>

	<section class="fh-inv-report__section">
		<h3><?php esc_html_e( '1. A fish that already appears on another invoice of this customer', 'fishotel-misc-plugin' ); ?></h3>
		<?php if ( ! $report['duplicates'] ) : ?>
			<p class="fh-inv-ok"><?php esc_html_e( 'None. No fish of this invoice is on another invoice of this customer.', 'fishotel-misc-plugin' ); ?></p>
		<?php else : ?>
			<p><?php esc_html_e( 'No fish goes on two invoices. Each of these is already on another one: take it off this invoice — or, when it really is a second fish, tick the box to confirm it.', 'fishotel-misc-plugin' ); ?></p>
			<ul class="fh-inv-dups">
				<?php foreach ( $report['duplicates'] as $fh_dup ) : ?>
					<?php foreach ( $fh_dup['matches'] as $fh_match ) : ?>
						<li class="fh-inv-dup<?php echo $fh_match['paid'] ? ' is-paid' : ''; ?>">
							<label>
								<input type="checkbox" class="fh-inv-dup-box" value="<?php echo esc_attr( $fh_match['key'] ); ?>">
								<span>
									<strong><?php echo esc_html( sprintf( /* translators: 1: line number, 2: fish */ __( 'Line %1$d, %2$s', 'fishotel-misc-plugin' ), (int) $fh_dup['index'] + 1, $fh_dup['name'] ) ); ?></strong>
									<?php
									echo esc_html(
										sprintf(
											/* translators: 1: the other invoice, 2: its state, 3: the fish there, 4: quantity there, 5: how it was matched */
											__( 'is already on %1$s (%2$s) as "%3$s" ×%4$d — %5$s.', 'fishotel-misc-plugin' ),
											$fh_match['label'],
											'' !== $fh_match['state'] ? $fh_match['state'] : ( $fh_match['paid'] ? __( 'paid', 'fishotel-misc-plugin' ) : __( 'not paid', 'fishotel-misc-plugin' ) ),
											$fh_match['name'],
											$fh_match['qty'],
											isset( $fh_by[ $fh_match['by'] ] ) ? $fh_by[ $fh_match['by'] ] : $fh_match['by']
										)
									);
									?>
									<em><?php esc_html_e( 'I checked: it is a second fish and belongs on this invoice too.', 'fishotel-misc-plugin' ); ?></em>
								</span>
							</label>
						</li>
					<?php endforeach; ?>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>
	</section>

	<section class="fh-inv-report__section">
		<h3><?php esc_html_e( '2. This customer\'s invoices', 'fishotel-misc-plugin' ); ?></h3>
		<?php if ( ! $report['invoices'] ) : ?>
			<p class="fh-inv-ok"><?php esc_html_e( 'None found: not built here, not made by hand under this handle, not on any of the orders below.', 'fishotel-misc-plugin' ); ?></p>
		<?php else : ?>
			<div class="fh-inv-entries">
				<?php foreach ( $report['invoices'] as $fh_entry ) : ?>
					<div class="fh-inv-entry<?php echo $fh_entry['counted'] ? '' : ' is-muted'; ?><?php echo $fh_entry['paid'] ? ' is-paid' : ''; ?>">
						<div class="fh-inv-entry__head">
							<?php if ( 'section' === $fh_entry['kind'] ) : ?>
								<a href="<?php echo esc_url( Admin::page_url( 'invoice', array( 'id' => $fh_entry['id'] ) ) ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $fh_entry['label'] ); ?></a>
							<?php elseif ( '' !== $fh_entry['edit_url'] ) : ?>
								<a href="<?php echo esc_url( $fh_entry['edit_url'] ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $fh_entry['label'] ); ?></a>
							<?php else : ?>
								<strong><?php echo esc_html( $fh_entry['label'] ); ?></strong>
							<?php endif; ?>
							<?php echo Admin::badge( $fh_entry['paid'] ? 'paid' : ( 'hand' === $fh_entry['kind'] ? 'hand' : $fh_entry['state'] ), $fh_entry['state_label'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in badge(). ?>
							<?php if ( null !== $fh_entry['total'] ) : ?>
								<span class="fh-inv-entry__total"><?php echo esc_html( Lines::dollars( $fh_entry['total'] ) ); ?></span>
							<?php endif; ?>
						</div>
						<?php if ( ! $fh_entry['counted'] ) : ?>
							<p class="fh-inv-sub"><?php esc_html_e( 'Not counted: its fish are free again.', 'fishotel-misc-plugin' ); ?></p>
						<?php elseif ( 'section' === $fh_entry['kind'] && ! $fh_entry['by_handle'] ) : ?>
							<p class="fh-inv-sub"><?php esc_html_e( 'Another handle of the same site customer or e-mail.', 'fishotel-misc-plugin' ); ?></p>
						<?php endif; ?>
						<?php if ( $fh_entry['lines'] ) : ?>
							<ul class="fh-inv-entry__lines">
								<?php foreach ( $fh_entry['lines'] as $fh_line ) : ?>
									<li><?php echo esc_html( $fh_line['text'] ); ?></li>
								<?php endforeach; ?>
							</ul>
						<?php else : ?>
							<p class="fh-inv-sub"><?php esc_html_e( 'Its lines are not known.', 'fishotel-misc-plugin' ); ?></p>
						<?php endif; ?>
					</div>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>
	</section>

	<section class="fh-inv-report__section">
		<h3><?php esc_html_e( '3. This customer\'s orders', 'fishotel-misc-plugin' ); ?></h3>
		<?php foreach ( $report['order_notes'] as $fh_text ) : ?>
			<p class="fh-inv-sub fh-inv-warn"><?php echo esc_html( $fh_text ); ?></p>
		<?php endforeach; ?>
		<?php if ( ! $report['orders'] ) : ?>
			<p class="fh-inv-ok"><?php esc_html_e( 'No order found.', 'fishotel-misc-plugin' ); ?></p>
		<?php else : ?>
			<table class="widefat fh-inv-table fh-inv-report__orders">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Order', 'fishotel-misc-plugin' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Status', 'fishotel-misc-plugin' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Paid?', 'fishotel-misc-plugin' ); ?></th>
						<th scope="col" class="fh-inv-num"><?php esc_html_e( 'Total', 'fishotel-misc-plugin' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Lines', 'fishotel-misc-plugin' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Found by', 'fishotel-misc-plugin' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $report['orders'] as $fh_order ) : ?>
						<tr>
							<td>
								<a href="<?php echo esc_url( $fh_order['edit_url'] ); ?>" target="_blank" rel="noopener">#<?php echo esc_html( $fh_order['number'] ); ?></a>
								<span class="fh-inv-sub"><?php echo esc_html( Admin::site_time( $fh_order['date_created'] ) ); ?></span>
								<?php if ( '' !== $fh_order['billing_name'] ) : ?>
									<span class="fh-inv-sub"><?php echo esc_html( $fh_order['billing_name'] ); ?></span>
								<?php endif; ?>
							</td>
							<td><?php echo esc_html( $fh_order['status_label'] ); ?></td>
							<td>
								<?php if ( Rules::order_is_dead( $fh_order ) ) : ?>
									<?php echo Admin::badge( 'cancelled', $fh_order['paid'] ? __( 'was paid — no longer stands', 'fishotel-misc-plugin' ) : __( 'not paid', 'fishotel-misc-plugin' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in badge(). ?>
								<?php elseif ( $fh_order['paid'] ) : ?>
									<?php echo Admin::badge( 'paid', __( 'PAID', 'fishotel-misc-plugin' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in badge(). ?>
									<span class="fh-inv-sub">
										<?php echo esc_html( trim( ( '' !== $fh_order['date_paid'] ? Admin::site_time( $fh_order['date_paid'] ) : '' ) . ( '' !== $fh_order['payment_method'] ? ' · ' . $fh_order['payment_method'] : '' ), ' ·' ) ); ?>
									</span>
								<?php else : ?>
									<?php echo Admin::badge( 'draft', __( 'NOT paid', 'fishotel-misc-plugin' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in badge(). ?>
								<?php endif; ?>
							</td>
							<td class="fh-inv-num"><?php echo esc_html( Lines::dollars( $fh_order['total'] ) ); ?></td>
							<td>
								<ul class="fh-inv-entry__lines">
									<?php foreach ( $fh_order['lines'] as $fh_line ) : ?>
										<li>
											<?php echo esc_html( $fh_line['name'] . ( 'line_item' === $fh_line['type'] ? ' ×' . $fh_line['qty'] : '' ) ); ?>
											<?php if ( $fh_line['is_invoice'] ) : ?>
												<span class="fh-inv-tag"><?php esc_html_e( 'invoice', 'fishotel-misc-plugin' ); ?></span>
											<?php elseif ( $fh_line['is_fish'] || $fh_line['is_credit'] ) : ?>
												<span class="fh-inv-tag"><?php echo esc_html( '' !== $fh_line['invoice_title'] ? sprintf( /* translators: %s: invoice title */ __( 'from %s', 'fishotel-misc-plugin' ), $fh_line['invoice_title'] ) : __( 'from an invoice', 'fishotel-misc-plugin' ) ); ?></span>
											<?php endif; ?>
										</li>
									<?php endforeach; ?>
								</ul>
							</td>
							<td>
								<?php
								$fh_found = array();
								foreach ( $fh_order['found_by'] as $fh_way ) {
									$fh_found[] = isset( $fh_ways[ $fh_way ] ) ? $fh_ways[ $fh_way ] : $fh_way;
								}
								echo esc_html( implode( ', ', $fh_found ) );
								?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
	</section>

	<section class="fh-inv-report__section">
		<h3><?php echo $fh_update ? esc_html__( '4. What will be written to the product', 'fishotel-misc-plugin' ) : esc_html__( '4. The product that will be created', 'fishotel-misc-plugin' ); ?></h3>
		<dl class="fh-inv-dl fh-inv-dl--wide">
			<dt><?php esc_html_e( 'Title', 'fishotel-misc-plugin' ); ?></dt>
			<dd>
				<?php echo esc_html( $fh_writes['title'] ); ?>
				<?php if ( $fh_update ) : ?>
					<span class="fh-inv-sub"><?php esc_html_e( 'unchanged — the customer has this link', 'fishotel-misc-plugin' ); ?></span>
				<?php endif; ?>
			</dd>
			<?php if ( ! $fh_update ) : ?>
				<dt><?php esc_html_e( 'Short description', 'fishotel-misc-plugin' ); ?></dt>
				<dd><?php echo esc_html( $fh_writes['excerpt'] ); ?></dd>
			<?php endif; ?>
			<dt><?php esc_html_e( 'Regular price', 'fishotel-misc-plugin' ); ?></dt>
			<dd>
				<strong><?php echo esc_html( Lines::dollars( $fh_writes['total'] ) ); ?></strong>
				<span class="fh-inv-sub"><?php esc_html_e( 'a sale price is never set or cleared', 'fishotel-misc-plugin' ); ?></span>
			</dd>
			<?php if ( ! $fh_update ) : ?>
				<dt><?php esc_html_e( 'Stock', 'fishotel-misc-plugin' ); ?></dt>
				<dd><?php esc_html_e( 'managed, quantity 1, backorders not allowed — it cannot be paid twice', 'fishotel-misc-plugin' ); ?></dd>
				<dt><?php esc_html_e( 'Catalog', 'fishotel-misc-plugin' ); ?></dt>
				<dd>
					<?php
					echo esc_html(
						$fh_writes['category']
							/* translators: %s: category name */
							? sprintf( __( 'hidden; the "%s" category only', 'fishotel-misc-plugin' ), $fh_writes['category']['name'] )
							: __( 'hidden; the Invoices category does not exist', 'fishotel-misc-plugin' )
					);
					?>
				</dd>
			<?php endif; ?>
			<dt><?php esc_html_e( 'Shipping class', 'fishotel-misc-plugin' ); ?></dt>
			<dd><?php echo esc_html( $fh_writes['shipping_class'] ? $fh_writes['shipping_class']['name'] . ' (' . $fh_writes['shipping_class']['slug'] . ')' : '—' ); ?></dd>
			<dt><?php esc_html_e( 'Image', 'fishotel-misc-plugin' ); ?></dt>
			<dd>
				<?php if ( $fh_writes['image'] ) : ?>
					<?php if ( '' !== $fh_writes['image']['url'] ) : ?>
						<img class="fh-inv-thumb" src="<?php echo esc_url( $fh_writes['image']['url'] ); ?>" alt="">
					<?php endif; ?>
					<?php echo esc_html( $fh_writes['image']['title'] ); ?>
				<?php else : ?>
					<?php echo $fh_update ? esc_html__( 'left as it is', 'fishotel-misc-plugin' ) : esc_html__( 'none', 'fishotel-misc-plugin' ); ?>
				<?php endif; ?>
			</dd>
			<dt><?php esc_html_e( 'Description', 'fishotel-misc-plugin' ); ?></dt>
			<dd class="fh-inv-description"><?php echo wp_kses( $fh_writes['description'], array( 'ul' => array(), 'li' => array() ) ); ?></dd>
		</dl>
	</section>
</div>
