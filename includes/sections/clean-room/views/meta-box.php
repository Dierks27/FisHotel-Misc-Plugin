<?php
/**
 * The "Clean room" meta box on the classic product edit screen: the facts
 * the fish page will read, a sale-date row for each open stay of this
 * product (saved with the product's Update button — Admin::save_meta_box()),
 * the closed stays, and a link to the board.
 *
 * Each open stay shows its supplier order (linked), tank, check-in and
 * live count, and a date picker holding its effective sale date —
 * labelled "from the batch" (the supplier order's "Goes on sale") or
 * "own date" — with "Use the batch date" to drop its own date. The
 * hidden "shown" value is what the picker showed, so an untouched picker
 * (say, showing the batch's date) writes nothing.
 *
 * @package FisHotel\Misc\Sections\Clean_Room
 *
 * @var int     $product_id   Product ID.
 * @var array[] $stays        Stays of this product (open first).
 * @var array   $facts        Store::product_facts().
 * @var string  $board_url    Stays tab URL.
 * @var int     $release_ts   The product's Coming Soon release timestamp (0 = none).
 * @var string  $nonce_action The sale dates' nonce action (Admin::NONCE_PRODUCT + the product id).
 * @var string  $today        Site date.
 */

defined( 'ABSPATH' ) || exit;

use FisHotel\Misc\Sections\Clean_Room\Admin;
use FisHotel\Misc\Sections\Clean_Room\Admin_Workbook;
use FisHotel\Misc\Sections\Clean_Room\Clean_Room;
use FisHotel\Misc\Sections\Clean_Room\Replay;

$fh_open   = array();
$fh_closed = array();
foreach ( $stays as $fh_stay ) {
	if ( 'closed' === $fh_stay['status'] ) {
		$fh_closed[] = $fh_stay;
	} else {
		$fh_open[] = $fh_stay;
	}
}
?>
<div class="fh-cr-metabox">
	<?php if ( ! $stays ) : ?>
		<p class="fh-cr-metabox__muted"><?php esc_html_e( 'No stays for this product yet. Check fish in from a tank\'s Front Desk screen and pick this product as the species.', 'fishotel-misc-plugin' ); ?></p>
	<?php else : ?>
		<p class="fh-cr-metabox__facts">
			<strong><?php echo esc_html( sprintf( /* translators: %d: fish alive */ __( '%d alive', 'fishotel-misc-plugin' ), (int) $facts['live_total'] ) ); ?></strong>
			· <?php echo esc_html( sprintf( /* translators: %d: groups */ __( '%d in QT', 'fishotel-misc-plugin' ), count( $facts['in_qt'] ) ) ); ?>
			· <?php echo esc_html( sprintf( /* translators: %d: groups */ __( '%d cleared', 'fishotel-misc-plugin' ), count( $facts['cleared'] ) ) ); ?>
			· <?php echo esc_html( sprintf( /* translators: %d: fish */ __( '%d for sale', 'fishotel-misc-plugin' ), (int) $facts['ready_total'] ) ); ?>
		</p>
		<?php if ( ! empty( $facts['next'] ) ) : ?>
			<p class="fh-cr-metabox__muted">
				<?php
				if ( ! empty( $facts['next']['sale_ready'] ) ) {
					/* translators: 1: tank path */
					echo esc_html( sprintf( __( 'For sale now from %s.', 'fishotel-misc-plugin' ), $facts['next']['tank'] ) );
				} elseif ( null !== $facts['next']['sale_ready_date'] ) {
					/* translators: 1: date, 2: tank path */
					echo esc_html( sprintf( __( 'Next on sale %1$s (%2$s).', 'fishotel-misc-plugin' ), $facts['next']['sale_ready_date'], $facts['next']['tank'] ) );
				} else {
					esc_html_e( 'No sale date set yet: set the batch\'s on its supplier order (Arrivals), or a group\'s own date below.', 'fishotel-misc-plugin' );
				}
				?>
			</p>
		<?php endif; ?>

		<?php if ( $fh_open ) : ?>
			<?php wp_nonce_field( $nonce_action, Admin::NONCE_PRODUCT_FIELD, false ); ?>
			<p class="fh-cr-metabox__head"><?php esc_html_e( 'Sale dates', 'fishotel-misc-plugin' ); ?></p>
			<ul class="fh-cr-metabox__list fh-cr-metabox__sales">
				<?php foreach ( $fh_open as $fh_stay ) : ?>
					<?php
					$fh_id    = (int) $fh_stay['id'];
					$fh_field = Admin::SALE_FIELD . '[' . $fh_id . ']';
					$fh_input = 'fh-cr-sale-' . $fh_id;
					$fh_words = Admin::sale_words( $fh_stay );
					$fh_own   = Replay::is_date( $fh_stay['sale_ready_override'] ) ? (string) $fh_stay['sale_ready_override'] : '';
					$fh_batch = Replay::is_date( $fh_stay['batch_sale_date'] ) ? (string) $fh_stay['batch_sale_date'] : '';
					?>
					<li class="fh-cr-metabox__sale">
						<div>
							<?php echo Admin::badge_html( $fh_stay ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by badge_html(). ?>
							<?php if ( ! empty( $fh_stay['batch_order_id'] ) ) : ?>
								<a href="<?php echo esc_url( Admin_Workbook::order_url( (int) $fh_stay['batch_order_id'] ) ); ?>"><?php echo esc_html( (string) $fh_stay['batch_order_ref'] ); ?></a>
							<?php else : ?>
								<span class="fh-cr-metabox__muted"><?php esc_html_e( 'no supplier order', 'fishotel-misc-plugin' ); ?></span>
							<?php endif; ?>
							<?php if ( $fh_stay['tank_number'] ) : ?>
								· <a href="<?php echo esc_url( Clean_Room::front_desk_url( (int) $fh_stay['tank_number'] ) ); ?>" target="_blank" rel="noopener"><?php echo esc_html( sprintf( /* translators: %d: tank number */ __( 'Tank %d', 'fishotel-misc-plugin' ), (int) $fh_stay['tank_number'] ) ); ?></a>
							<?php endif; ?>
						</div>
						<div class="fh-cr-metabox__muted">
							<?php echo esc_html( sprintf( /* translators: 1: check-in date, 2: fish alive */ __( 'in %1$s · %2$d live', 'fishotel-misc-plugin' ), (string) $fh_stay['checkin_date'], (int) $fh_stay['qty_live'] ) ); ?>
							<?php if ( null !== $fh_stay['day'] ) : ?>
								· <?php echo esc_html( sprintf( /* translators: %d: day */ __( 'day %d', 'fishotel-misc-plugin' ), (int) $fh_stay['day'] ) ); ?>
							<?php endif; ?>
						</div>
						<label class="fh-cr-metabox__date" for="<?php echo esc_attr( $fh_input ); ?>">
							<span><?php esc_html_e( 'On sale', 'fishotel-misc-plugin' ); ?></span>
							<input type="date" id="<?php echo esc_attr( $fh_input ); ?>" name="<?php echo esc_attr( $fh_field . '[date]' ); ?>" value="<?php echo esc_attr( $fh_words['date'] ); ?>" min="<?php echo esc_attr( Replay::SALE_DATE_MIN ); ?>" max="<?php echo esc_attr( Replay::sale_date_max( (string) $today ) ); ?>">
						</label>
						<input type="hidden" name="<?php echo esc_attr( $fh_field . '[shown]' ); ?>" value="<?php echo esc_attr( $fh_words['date'] ); ?>">
						<span class="fh-cr-metabox__muted">
							<?php echo esc_html( '' !== $fh_words['date'] ? $fh_words['source'] : $fh_words['text'] ); ?>
							<?php if ( '' !== $fh_words['hint'] ) : ?>
								· <button type="button" class="button-link fh-cr-suggest-date" data-target="<?php echo esc_attr( $fh_input ); ?>" data-date="<?php echo esc_attr( (string) $fh_stay['sale_ready_suggested'] ); ?>"><?php echo esc_html( $fh_words['hint'] ); ?></button>
							<?php endif; ?>
						</span>
						<?php if ( '' !== $fh_own ) : ?>
							<label class="fh-cr-metabox__batch">
								<input type="checkbox" name="<?php echo esc_attr( $fh_field . '[batch]' ); ?>" value="1">
								<?php
								echo esc_html(
									'' !== $fh_batch
										/* translators: %s: the batch's sale date */
										? sprintf( __( 'Use the batch date (%s)', 'fishotel-misc-plugin' ), $fh_batch )
										: __( 'Use the batch date (none set yet)', 'fishotel-misc-plugin' )
								);
								?>
							</label>
						<?php endif; ?>
					</li>
				<?php endforeach; ?>
			</ul>
			<p class="fh-cr-metabox__muted"><?php esc_html_e( 'Saved with Update. A group is for sale from this date on — nothing on the shop changes by itself.', 'fishotel-misc-plugin' ); ?></p>
		<?php endif; ?>

		<?php if ( $fh_closed ) : ?>
			<p class="fh-cr-metabox__head"><?php esc_html_e( 'Closed', 'fishotel-misc-plugin' ); ?></p>
			<ul class="fh-cr-metabox__list">
				<?php foreach ( $fh_closed as $fh_stay ) : ?>
					<li>
						<?php echo Admin::badge_html( $fh_stay ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by badge_html(). ?>
						<strong><?php echo esc_html( $fh_stay['qty_live'] ); ?></strong>/<?php echo esc_html( $fh_stay['qty_arrived'] ); ?>
						· <?php echo esc_html( (string) $fh_stay['checkin_date'] ); ?>
						<?php if ( ! empty( $fh_stay['batch_order_ref'] ) ) : ?>
							· <?php echo esc_html( (string) $fh_stay['batch_order_ref'] ); ?>
						<?php endif; ?>
					</li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>
	<?php endif; ?>
	<?php if ( $release_ts > 0 ) : ?>
		<p class="fh-cr-metabox__muted"><?php echo esc_html( sprintf( /* translators: %s: date/time */ __( 'Coming Soon release: %s', 'fishotel-misc-plugin' ), wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $release_ts ) ) ); ?></p>
	<?php endif; ?>
	<p><a href="<?php echo esc_url( $board_url ); ?>"><?php esc_html_e( 'Open the Clean Room board', 'fishotel-misc-plugin' ); ?> &rarr;</a></p>
	<p class="fh-cr-metabox__muted"><?php esc_html_e( 'The theme reads these numbers through fishotel_cleanroom_product_facts().', 'fishotel-misc-plugin' ); ?></p>
</div>
