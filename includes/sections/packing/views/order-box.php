<?php
/**
 * The Packing box body — drawn on the order screen and returned by its
 * AJAX call.
 *
 * @package FisHotel\Misc\Sections\Packing
 *
 * @var object $order      WC_Order.
 * @var array  $result     Order_Packing::for_order() (see fishotel_packing_suggest()).
 * @var array  $candidates Suggest::candidates(): every box against the whole order.
 * @var array  $settings   Settings::get().
 * @var string $nonce      Nonce for the confirm AJAX.
 * @var string $page_url   The Packing page.
 * @var string $wrapper_url The order screen of the combined box this order went into ('' = none).
 * @var string $message    The outcome of a save ('' = none).
 * @var bool   $error      Whether $message is an error.
 */

defined( 'ABSPATH' ) || exit;

use FisHotel\Misc\Sections\Packing\Bags;
use FisHotel\Misc\Sections\Packing\Boxes;
use FisHotel\Misc\Sections\Packing\Order_Packing;
use FisHotel\Misc\Sections\Packing\Suggest;

$fh_order_id  = method_exists( $order, 'get_id' ) ? (int) $order->get_id() : 0;
$fh_confirmed = $result['confirmed'];
$fh_folded    = (int) $result['packed_with'] > 0;
$fh_can_use   = in_array( $result['status'], array( Suggest::STATUS_FITS, Suggest::STATUS_SPLIT ), true ) && $result['boxes'] && ! $result['unplaced'];
$fh_pct       = static function ( $value ) {
	return Boxes::num_text( $value, 1 ) . '%';
};

// Whether the confirmed box is still what would be suggested.
$fh_same = false;
if ( $fh_confirmed && $fh_can_use ) {
	$fh_same = Order_Packing::boxes_name( $result['boxes'] ) === $fh_confirmed['name'];
}
?>
<div class="fh-pack-box" data-order-id="<?php echo esc_attr( $fh_order_id ); ?>" data-nonce="<?php echo esc_attr( $nonce ); ?>">

	<?php if ( '' !== $message ) : ?>
		<p class="fh-pack-box__flash<?php echo $error ? ' is-error' : ''; ?>"><?php echo esc_html( $message ); ?></p>
	<?php endif; ?>

	<?php if ( empty( $result['available'] ) ) : ?>
		<p class="fh-pack-box__warn"><?php esc_html_e( 'The packing suggestion could not be worked out for this order.', 'fishotel-misc-plugin' ); ?></p>
	<?php endif; ?>

	<?php if ( $result['is_fulfillment'] && $result['sources'] ) : ?>
		<p class="fh-pack-box__hint">
			<?php
			/* translators: %s: order numbers, separated by commas */
			echo esc_html( sprintf( __( 'Combined box — the fish of orders %s.', 'fishotel-misc-plugin' ), '#' . implode( ', #', array_map( 'intval', $result['sources'] ) ) ) );
			?>
		</p>
	<?php endif; ?>

	<?php if ( $result['lines'] ) : ?>
		<table class="fh-pack-box__lines">
			<thead>
				<tr>
					<th class="fh-pack-box__qty"><?php esc_html_e( 'Qty', 'fishotel-misc-plugin' ); ?></th>
					<th><?php esc_html_e( 'Fish', 'fishotel-misc-plugin' ); ?></th>
					<th><?php esc_html_e( 'Bag', 'fishotel-misc-plugin' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $result['lines'] as $fh_line ) : ?>
					<tr class="<?php echo $fh_line['bag'] ? '' : 'is-missing'; ?>">
						<td class="fh-pack-box__qty"><?php echo esc_html( (int) $fh_line['qty'] ); ?></td>
						<td>
							<?php echo esc_html( $fh_line['name'] ); ?>
							<?php if ( '' !== (string) $fh_line['size_label'] && false === stripos( (string) $fh_line['name'], (string) $fh_line['size_label'] ) ) : ?>
								<span class="fh-pack-box__sub"><?php echo esc_html( $fh_line['size_label'] ); ?></span>
							<?php endif; ?>
							<?php if ( '' !== (string) ( $fh_line['from'] ?? '' ) ) : ?>
								<span class="fh-pack-box__sub">
									<?php
									/* translators: %s: invoice name */
									echo esc_html( sprintf( __( 'from %s', 'fishotel-misc-plugin' ), $fh_line['from'] ) );
									?>
								</span>
							<?php endif; ?>
							<?php if ( 'backordered' === $fh_line['flag'] ) : ?>
								<span class="fh-pack-box__tag"><?php esc_html_e( 'backordered', 'fishotel-misc-plugin' ); ?></span>
							<?php endif; ?>
							<?php if ( $result['is_fulfillment'] && (int) $fh_line['order_id'] !== $fh_order_id ) : ?>
								<span class="fh-pack-box__sub">#<?php echo esc_html( (int) $fh_line['order_id'] ); ?></span>
							<?php endif; ?>
						</td>
						<td>
							<?php if ( $fh_line['bag'] ) : ?>
								<strong><?php echo esc_html( $fh_line['bag']['name'] ); ?></strong>
								<?php if ( (int) $fh_line['bags'] !== (int) $fh_line['qty'] ) : ?>
									<span class="fh-pack-box__sub">
										<?php
										/* translators: %d: number of bags */
										echo esc_html( sprintf( _n( '%d bag', '%d bags', (int) $fh_line['bags'], 'fishotel-misc-plugin' ), (int) $fh_line['bags'] ) );
										?>
									</span>
								<?php endif; ?>
								<span class="fh-pack-box__sub">
									<?php echo esc_html( Bags::SOURCE_OWN === $fh_line['bag']['source'] ? __( 'set on the product', 'fishotel-misc-plugin' ) : __( 'from the size map', 'fishotel-misc-plugin' ) ); ?>
								</span>
							<?php else : ?>
								<strong class="fh-pack-box__none"><?php esc_html_e( 'No bag size', 'fishotel-misc-plugin' ); ?></strong>
								<?php if ( isset( $fh_line['reason'] ) ) : ?>
									<span class="fh-pack-box__sub"><?php echo esc_html( $fh_line['reason'] ); ?></span>
								<?php endif; ?>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>

	<?php if ( $result['bags'] ) : ?>
		<p class="fh-pack-box__bags">
			<strong><?php esc_html_e( 'Bags:', 'fishotel-misc-plugin' ); ?></strong>
			<?php
			$fh_parts = array();
			foreach ( $result['bags'] as $fh_bag ) {
				$fh_parts[] = (int) $fh_bag['count'] . ' × ' . $fh_bag['name'];
			}
			echo esc_html( implode( ' · ', $fh_parts ) );
			?>
			<span class="fh-pack-box__sub">
				<?php
				/* translators: %d: number of bags */
				echo esc_html( sprintf( _n( '%d bag in all', '%d bags in all', (int) $result['bag_total'], 'fishotel-misc-plugin' ), (int) $result['bag_total'] ) );
				?>
			</span>
		</p>
	<?php endif; ?>

	<?php foreach ( $result['messages'] as $fh_message ) : ?>
		<p class="fh-pack-box__note"><?php echo esc_html( $fh_message ); ?></p>
	<?php endforeach; ?>

	<?php if ( $result['ignored'] ) : ?>
		<p class="fh-pack-box__hint">
			<strong><?php esc_html_e( 'Ignored (not fish):', 'fishotel-misc-plugin' ); ?></strong>
			<?php
			$fh_parts = array();
			foreach ( $result['ignored'] as $fh_line ) {
				$fh_parts[] = (int) $fh_line['qty'] . ' × ' . $fh_line['name'];
			}
			echo esc_html( implode( ', ', $fh_parts ) );
			?>
		</p>
	<?php endif; ?>

	<?php if ( $result['skipped'] ) : ?>
		<p class="fh-pack-box__hint">
			<strong><?php esc_html_e( 'Left out:', 'fishotel-misc-plugin' ); ?></strong>
			<?php
			$fh_parts = array();
			foreach ( $result['skipped'] as $fh_line ) {
				$fh_parts[] = (int) $fh_line['qty'] . ' × ' . $fh_line['name'] . ' (' . $fh_line['reason'] . ')';
			}
			echo esc_html( implode( ', ', $fh_parts ) );
			?>
		</p>
	<?php endif; ?>

	<?php if ( $fh_folded && '' !== $wrapper_url ) : ?>
		<p class="fh-pack-box__hint">
			<a href="<?php echo esc_url( $wrapper_url ); ?>">
				<?php
				/* translators: %d: order ID of the combined box */
				echo esc_html( sprintf( __( 'Open combined box #%d', 'fishotel-misc-plugin' ), (int) $result['packed_with'] ) );
				?>
			</a>
		</p>
	<?php endif; ?>

	<?php if ( $result['boxes'] && ! $fh_folded ) : ?>
		<div class="fh-pack-box__suggest<?php echo $result['on_the_line'] ? ' is-line' : ''; ?><?php echo $result['complete'] ? '' : ' is-incomplete'; ?>">
			<span class="fh-pack-box__label">
				<?php echo esc_html( $result['split'] ? __( 'Suggested boxes', 'fishotel-misc-plugin' ) : __( 'Suggested box', 'fishotel-misc-plugin' ) ); ?>
				<?php if ( ! $result['complete'] ) : ?>
					<span class="fh-pack-badge fh-pack-badge--incomplete"><?php esc_html_e( 'Incomplete', 'fishotel-misc-plugin' ); ?></span>
				<?php endif; ?>
				<?php if ( $result['on_the_line'] ) : ?>
					<span class="fh-pack-badge fh-pack-badge--line"><?php esc_html_e( 'On the line', 'fishotel-misc-plugin' ); ?></span>
				<?php endif; ?>
			</span>
			<ul class="fh-pack-box__boxes">
				<?php foreach ( $result['boxes'] as $fh_box ) : ?>
					<li>
						<strong><?php echo esc_html( $fh_box['name'] ); ?></strong>
						<span class="fh-pack-box__sub"><?php echo esc_html( Boxes::size_text( $fh_box ) ); ?></span>
						&mdash;
						<?php
						/* translators: %s: percent */
						echo esc_html( sprintf( __( '%s full', 'fishotel-misc-plugin' ), $fh_pct( $fh_box['fill_pct'] ) ) );
						?>
						<span class="fh-pack-meter" aria-hidden="true"><span class="fh-pack-meter__bar<?php echo $fh_box['on_the_line'] ? ' is-line' : ''; ?>" style="width: <?php echo esc_attr( min( 100, max( 0, (float) $fh_box['fill_pct'] ) ) ); ?>%;"></span></span>
						<?php if ( $result['split'] ) : ?>
							<span class="fh-pack-box__sub">
								<?php
								$fh_parts = array();
								foreach ( $settings['bags'] as $fh_bag ) {
									if ( ! empty( $fh_box['bags'][ (int) $fh_bag['id'] ] ) ) {
										$fh_parts[] = (int) $fh_box['bags'][ (int) $fh_bag['id'] ] . ' × ' . $fh_bag['name'];
									}
								}
								echo esc_html( implode( ' · ', $fh_parts ) );
								?>
							</span>
						<?php endif; ?>
					</li>
				<?php endforeach; ?>
			</ul>
		</div>
	<?php endif; ?>

	<?php if ( $fh_confirmed ) : ?>
		<div class="fh-pack-box__confirmed">
			<span class="fh-pack-box__label"><?php esc_html_e( 'Confirmed', 'fishotel-misc-plugin' ); ?></span>
			<strong><?php echo esc_html( $fh_confirmed['name'] ); ?></strong>
			<span class="fh-pack-box__sub">
				<?php
				$fh_sizes = array();
				foreach ( $fh_confirmed['boxes'] as $fh_box ) {
					$fh_sizes[ Boxes::size_text( $fh_box ) ] = true;
				}
				echo esc_html( implode( ' + ', array_keys( $fh_sizes ) ) );
				?>
			</span>
			<span class="fh-pack-box__sub">
				<?php
				$fh_when = '' !== $fh_confirmed['at'] && function_exists( 'get_date_from_gmt' ) ? get_date_from_gmt( $fh_confirmed['at'], 'M j, Y g:i a' ) : $fh_confirmed['at'];
				if ( '' !== $fh_confirmed['by_name'] ) {
					/* translators: 1: user name, 2: date and time */
					echo esc_html( sprintf( __( 'by %1$s, %2$s', 'fishotel-misc-plugin' ), $fh_confirmed['by_name'], $fh_when ) );
				} else {
					echo esc_html( $fh_when );
				}
				?>
			</span>
			<?php if ( $fh_can_use && ! $fh_same ) : ?>
				<p class="fh-pack-box__warn"><?php esc_html_e( 'The suggestion above is different now — the order, a bag size or the numbers changed since this was confirmed.', 'fishotel-misc-plugin' ); ?></p>
			<?php endif; ?>
			<?php if ( ! $fh_confirmed['complete'] ) : ?>
				<p class="fh-pack-box__warn"><?php esc_html_e( 'It was confirmed while a fish line had no bag size.', 'fishotel-misc-plugin' ); ?></p>
			<?php endif; ?>
		</div>
	<?php endif; ?>

	<?php if ( ! $fh_folded ) : ?>
		<div class="fh-pack-box__controls">
			<?php if ( $fh_can_use && ! ( $fh_confirmed && $fh_same ) ) : ?>
				<button type="button" class="button button-primary fh-pack-confirm" data-op="confirm" data-choice="suggested" data-shown="<?php echo esc_attr( Order_Packing::shown_token( $result['boxes'] ) ); ?>">
					<?php echo esc_html( $result['split'] ? __( 'Confirm these boxes', 'fishotel-misc-plugin' ) : __( 'Confirm this box', 'fishotel-misc-plugin' ) ); ?>
				</button>
			<?php endif; ?>

			<?php if ( $candidates ) : ?>
				<span class="fh-pack-box__choose">
					<label class="screen-reader-text" for="fh-pack-choice-<?php echo esc_attr( $fh_order_id ); ?>"><?php esc_html_e( 'Choose a box', 'fishotel-misc-plugin' ); ?></label>
					<select id="fh-pack-choice-<?php echo esc_attr( $fh_order_id ); ?>" class="fh-pack-choice">
						<option value=""><?php echo esc_html( $fh_confirmed ? __( 'Change to…', 'fishotel-misc-plugin' ) : __( 'Or choose a box…', 'fishotel-misc-plugin' ) ); ?></option>
						<?php foreach ( $candidates as $fh_box ) : ?>
							<?php
							if ( null === $fh_box['fill'] ) {
								$fh_note = $result['bags'] ? __( 'a bag size does not go in it', 'fishotel-misc-plugin' ) : '';
							} elseif ( ! $result['bags'] ) {
								$fh_note = '';
							} elseif ( ! $fh_box['holds'] ) {
								/* translators: %s: percent */
								$fh_note = sprintf( __( '%s — too small', 'fishotel-misc-plugin' ), $fh_pct( $fh_box['fill_pct'] ) );
							} else {
								$fh_note = $fh_pct( $fh_box['fill_pct'] );
							}
							?>
							<option value="<?php echo esc_attr( $fh_box['key'] ); ?>"><?php echo esc_html( $fh_box['name'] . ' (' . Boxes::size_text( $fh_box ) . ')' . ( '' !== $fh_note ? ' — ' . $fh_note : '' ) ); ?></option>
						<?php endforeach; ?>
					</select>
					<label>
						<span aria-hidden="true">×</span>
						<span class="screen-reader-text"><?php esc_html_e( 'How many of that box', 'fishotel-misc-plugin' ); ?></span>
						<input type="number" class="fh-pack-count" value="1" min="1" max="<?php echo esc_attr( Order_Packing::MAX_COUNT ); ?>" step="1">
					</label>
					<button type="button" class="button fh-pack-confirm" data-op="confirm" data-choice="chosen"><?php esc_html_e( 'Use this box', 'fishotel-misc-plugin' ); ?></button>
				</span>
			<?php endif; ?>

			<?php if ( $fh_confirmed ) : ?>
				<button type="button" class="button-link fh-pack-confirm fh-pack-clear" data-op="clear"><?php esc_html_e( 'Clear', 'fishotel-misc-plugin' ); ?></button>
			<?php endif; ?>

			<button type="button" class="button-link fh-pack-confirm" data-op="view"><?php esc_html_e( 'Refresh', 'fishotel-misc-plugin' ); ?></button>
			<span class="fh-pack-status" role="status" aria-live="polite"></span>
		</div>
	<?php endif; ?>

	<p class="fh-pack-box__foot">
		<a href="<?php echo esc_url( $page_url ); ?>"><?php esc_html_e( 'Bags, sizes and boxes', 'fishotel-misc-plugin' ); ?></a>
		&middot;
		<?php esc_html_e( 'Staff only: none of this is shown to the customer.', 'fishotel-misc-plugin' ); ?>
	</p>
</div>
