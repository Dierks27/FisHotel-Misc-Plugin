<?php
/**
 * The "Clean room" meta box on the classic product edit screen: this
 * product's stays with day counts, the facts the fish page will read,
 * and a link to the board.
 *
 * @package FisHotel\Misc\Sections\Clean_Room
 *
 * @var int     $product_id Product ID.
 * @var array[] $stays      Stays of this product (open first).
 * @var array   $facts      Store::product_facts().
 * @var string  $board_url  Stays tab URL.
 * @var int     $release_ts The product's Coming Soon release timestamp (0 = none).
 */

defined( 'ABSPATH' ) || exit;

use FisHotel\Misc\Sections\Clean_Room\Admin;
use FisHotel\Misc\Sections\Clean_Room\Clean_Room;
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
					esc_html_e( 'No sale date set yet: set the batch\'s on its supplier order (Arrivals), or a group\'s own date.', 'fishotel-misc-plugin' );
				}
				?>
			</p>
		<?php endif; ?>
		<ul class="fh-cr-metabox__list">
			<?php foreach ( $stays as $fh_stay ) : ?>
				<li>
					<?php echo Admin::badge_html( $fh_stay ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by badge_html(). ?>
					<strong><?php echo esc_html( $fh_stay['qty_live'] ); ?></strong>/<?php echo esc_html( $fh_stay['qty_arrived'] ); ?>
					<?php if ( null !== $fh_stay['day'] && 'closed' !== $fh_stay['status'] ) : ?>
						· <?php echo esc_html( sprintf( /* translators: %d: day */ __( 'day %d', 'fishotel-misc-plugin' ), $fh_stay['day'] ) ); ?>
					<?php endif; ?>
					<?php if ( $fh_stay['tank_number'] ) : ?>
						· <a href="<?php echo esc_url( Clean_Room::front_desk_url( (int) $fh_stay['tank_number'] ) ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $fh_stay['tank_path'] ); ?></a>
					<?php endif; ?>
					<?php if ( 'closed' !== $fh_stay['status'] ) : ?>
						<span class="fh-cr-metabox__muted"><?php echo esc_html( Admin::sale_words( $fh_stay )['text'] ); ?></span>
					<?php endif; ?>
				</li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>
	<?php if ( $release_ts > 0 ) : ?>
		<p class="fh-cr-metabox__muted"><?php echo esc_html( sprintf( /* translators: %s: date/time */ __( 'Coming Soon release: %s', 'fishotel-misc-plugin' ), wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $release_ts ) ) ); ?></p>
	<?php endif; ?>
	<p><a href="<?php echo esc_url( $board_url ); ?>"><?php esc_html_e( 'Open the Clean Room board', 'fishotel-misc-plugin' ); ?> &rarr;</a></p>
	<p class="fh-cr-metabox__muted"><?php esc_html_e( 'The theme reads these numbers through fishotel_cleanroom_product_facts().', 'fishotel-misc-plugin' ); ?></p>
</div>
