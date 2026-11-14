<?php
/**
 * Open a case by hand — pick an order, then one of its lines.
 *
 * @package FisHotel\Misc\Sections\Lost_Fish
 *
 * @var string     $tab       Current tab.
 * @var array|null $flash     A one-time notice.
 * @var bool       $tables_ok Whether the tables exist.
 * @var array      $status    Admin::connections().
 * @var int        $order_id  The order asked for (0 = none).
 * @var string     $picked    The line the order screen's link pointed at ('' = none).
 * @var array      $lookup    Admin::order_lookup(): asked, facts, lines, cases.
 */

defined( 'ABSPATH' ) || exit;

use FisHotel\Misc\Sections\Lost_Fish\Admin;
use FisHotel\Misc\Sections\Lost_Fish\Cases;
use FisHotel\Misc\Sections\Lost_Fish\Cover;
use FisHotel\Misc\Sections\Lost_Fish\Lost_Fish;
use FisHotel\Misc\Sections\Lost_Fish\Money;

$fh_facts = $lookup['facts'];
?>
<div class="wrap fishotel-misc-wrap fh-lf-wrap">

	<?php include Lost_Fish::path() . 'views/top.php'; ?>

	<p class="fh-lf-lead"><?php esc_html_e( 'For a loss the Clean Room did not open a case for — a recount that came up short, a fish that is not in a tank group, or the Clean Room being off. Pick the order, then the line. Opening a case sends nothing.', 'fishotel-misc-plugin' ); ?></p>

	<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="fh-lf-toolbar">
		<input type="hidden" name="page" value="<?php echo esc_attr( Lost_Fish::MENU_SLUG ); ?>">
		<input type="hidden" name="tab" value="new">
		<label>
			<span><?php esc_html_e( 'Order number', 'fishotel-misc-plugin' ); ?></span>
			<input type="number" min="1" step="1" class="fh-lf-input fh-lf-input--num" name="order" value="<?php echo esc_attr( $order_id > 0 ? $order_id : '' ); ?>" required>
		</label>
		<button type="submit" class="button"><?php esc_html_e( 'Show its lines', 'fishotel-misc-plugin' ); ?></button>
	</form>

	<?php if ( $lookup['asked'] && ! is_array( $fh_facts ) ) : ?>
		<div class="fishotel-misc-notice fishotel-misc-notice--error"><?php echo esc_html( sprintf( /* translators: %d: order id */ __( 'There is no order #%d.', 'fishotel-misc-plugin' ), $order_id ) ); ?></div>
	<?php endif; ?>

	<?php if ( is_array( $fh_facts ) ) : ?>
		<h2 class="fh-lf-h2">
			<a href="<?php echo esc_url( $fh_facts['edit_url'] ); ?>"><?php echo esc_html( sprintf( /* translators: %s: order number */ __( 'Order #%s', 'fishotel-misc-plugin' ), $fh_facts['number'] ) ); ?></a>
			<span class="fh-lf-sub">
				<?php echo esc_html( $fh_facts['customer_name'] . ' · ' . $fh_facts['status'] . ' · ' . ( $fh_facts['paid'] ? __( 'paid', 'fishotel-misc-plugin' ) : __( 'NOT paid', 'fishotel-misc-plugin' ) ) ); ?>
			</span>
		</h2>

		<?php if ( ! $fh_facts['paid'] || $fh_facts['closed'] ) : ?>
			<div class="fishotel-misc-notice fishotel-misc-notice--error">
				<?php echo esc_html( $fh_facts['closed'] ? __( 'This order is closed — a case can be opened, but it cannot be sent while the order stays closed.', 'fishotel-misc-plugin' ) : __( 'This order is not paid: nobody is owed a fish on it yet.', 'fishotel-misc-plugin' ) ); ?>
			</div>
		<?php endif; ?>

		<?php if ( ! $lookup['lines'] ) : ?>
			<div class="fishotel-misc-empty"><span class="dashicons dashicons-heart"></span><p><?php esc_html_e( 'This order has no lines.', 'fishotel-misc-plugin' ); ?></p></div>
		<?php else : ?>
			<table class="widefat striped fh-lf-list">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Line', 'fishotel-misc-plugin' ); ?></th>
						<th scope="col" class="fh-lf-num"><?php esc_html_e( 'Qty', 'fishotel-misc-plugin' ); ?></th>
						<th scope="col" class="fh-lf-num"><?php esc_html_e( 'Paid per fish', 'fishotel-misc-plugin' ); ?></th>
						<th scope="col"><?php esc_html_e( 'State', 'fishotel-misc-plugin' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Lost', 'fishotel-misc-plugin' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $lookup['lines'] as $fh_line ) : ?>
						<?php
						$fh_left  = $fh_line['qty'] - $fh_line['qty_refunded'];
						$fh_notes = array();
						foreach ( $lookup['cases'] as $fh_other ) {
							if ( $fh_other['line_ref'] !== $fh_line['ref'] ) {
								continue;
							}
							/* translators: 1: case id, 2: status, 3: quantity */
							$fh_notes[] = sprintf( __( 'case #%1$d (%2$s, %3$d)', 'fishotel-misc-plugin' ), (int) $fh_other['id'], Admin::status_label( $fh_other ), (int) $fh_other['qty'] );
							if ( in_array( $fh_other['status'], array_merge( Cover::PENDING, Cover::RELEASED ), true ) || ( 'closed' === $fh_other['status'] && Cover::CLOSE_SETTLED === $fh_other['close_reason'] ) ) {
								$fh_left -= (int) $fh_other['qty'];
							}
						}
						?>
						<tr class="<?php echo $picked === $fh_line['ref'] ? 'is-picked' : ''; ?>">
							<td>
								<strong><?php echo esc_html( '' !== $fh_line['name'] ? $fh_line['name'] : __( '(unnamed line)', 'fishotel-misc-plugin' ) ); ?></strong>
								<?php if ( 'product' !== $fh_line['type'] ) : ?>
									<span class="fh-lf-sub"><?php esc_html_e( 'from an invoice', 'fishotel-misc-plugin' ); ?></span>
								<?php endif; ?>
							</td>
							<td class="fh-lf-num"><?php echo (int) $fh_line['qty']; ?></td>
							<td class="fh-lf-num"><?php echo esc_html( $fh_line['paid_known'] ? Money::format( $fh_line['unit_paid'], $fh_facts['symbol'] ) : __( 'not known', 'fishotel-misc-plugin' ) ); ?></td>
							<td>
								<?php
								$fh_state = array();
								if ( $fh_line['shipped'] ) {
									$fh_state[] = __( 'shipped', 'fishotel-misc-plugin' );
								}
								if ( $fh_line['qty_refunded'] > 0 ) {
									/* translators: %d: quantity */
									$fh_state[] = sprintf( __( '%d refunded', 'fishotel-misc-plugin' ), (int) $fh_line['qty_refunded'] );
								}
								echo esc_html( implode( ' · ', array_merge( $fh_state, $fh_notes ) ) );
								?>
							</td>
							<td>
								<?php if ( $fh_left > 0 && ! $fh_line['shipped'] ) : ?>
									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="fh-lf-inline">
										<input type="hidden" name="action" value="<?php echo esc_attr( Admin::ACTION_OPEN ); ?>">
										<input type="hidden" name="order_id" value="<?php echo esc_attr( (int) $fh_facts['id'] ); ?>">
										<input type="hidden" name="line" value="<?php echo esc_attr( $fh_line['ref'] ); ?>">
										<?php wp_nonce_field( Admin::NONCE_OPEN, '_wpnonce', false ); ?>
										<label class="screen-reader-text" for="fh-lf-qty-<?php echo esc_attr( $fh_line['ref'] ); ?>"><?php esc_html_e( 'How many were lost', 'fishotel-misc-plugin' ); ?></label>
										<select name="qty" class="fh-lf-input fh-lf-input--num" id="fh-lf-qty-<?php echo esc_attr( $fh_line['ref'] ); ?>">
											<?php for ( $fh_n = 1; $fh_n <= min( 50, $fh_left ); $fh_n++ ) : ?>
												<option value="<?php echo (int) $fh_n; ?>"><?php echo (int) $fh_n; ?></option>
											<?php endfor; ?>
										</select>
										<button type="submit" class="button"><?php esc_html_e( 'Open a case', 'fishotel-misc-plugin' ); ?></button>
									</form>
								<?php else : ?>
									<span class="fh-lf-sub"><?php echo esc_html( $fh_line['shipped'] ? __( 'already shipped', 'fishotel-misc-plugin' ) : __( 'nothing left without a case', 'fishotel-misc-plugin' ) ); ?></span>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<p class="fh-lf-sub"><?php esc_html_e( 'A case opened by hand is never sent by itself, whatever the setting.', 'fishotel-misc-plugin' ); ?></p>
		<?php endif; ?>
	<?php endif; ?>

</div>
