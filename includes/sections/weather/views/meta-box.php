<?php
/**
 * Weather meta box body — rendered on the order screen and returned by
 * the "Check weather now" AJAX call.
 *
 * @package FisHotel\Misc\Sections\Weather
 *
 * @var \WC_Order  $order       The order.
 * @var array|null $stored      Checker::stored() result, or null.
 * @var string     $delivery    Delivery date Y-m-d, or ''.
 * @var array      $destination Checker::destination() result.
 * @var string     $note_status Checker::note_status() for the delivery date.
 * @var int        $sources     Live (not cancelled / refunded / failed) source-order count when this is a fulfillment wrapper.
 * @var int        $folded_into Fulfillment order ID when this order was combined into one.
 */

defined( 'ABSPATH' ) || exit;

use FisHotel\Misc\Sections\Weather\Admin;
use FisHotel\Misc\Sections\Weather\Bands;
use FisHotel\Misc\Sections\Weather\Note;

$fh_box_id = 'fh-weather-email-' . (int) $order->get_id();
?>
<div class="fh-weather-box" data-order-id="<?php echo esc_attr( $order->get_id() ); ?>">

	<p class="fh-weather-box__row">
		<strong><?php esc_html_e( 'Delivery:', 'fishotel-misc-plugin' ); ?></strong>
		<?php echo '' !== $delivery ? esc_html( Note::long_date( $delivery ) ) : esc_html__( 'no delivery date on this order', 'fishotel-misc-plugin' ); ?>
	</p>
	<p class="fh-weather-box__row">
		<strong><?php esc_html_e( 'To:', 'fishotel-misc-plugin' ); ?></strong>
		<?php echo esc_html( trim( $destination['postcode'] . ' (' . Note::place( $destination['city'], $destination['state'] ) . ')' ) ); ?>
		<?php if ( $sources ) : ?>
			<span class="fh-weather-box__hint">
				<?php
				/* translators: %d: number of source orders in the combined box */
				echo esc_html( sprintf( _n( '· combined box, %d order', '· combined box, %d orders', $sources, 'fishotel-misc-plugin' ), $sources ) );
				?>
			</span>
		<?php elseif ( $folded_into > 0 ) : ?>
			<span class="fh-weather-box__hint">
				<?php
				/* translators: %d: fulfillment order ID */
				echo esc_html( sprintf( __( '· combined into #%d', 'fishotel-misc-plugin' ), $folded_into ) );
				?>
			</span>
		<?php endif; ?>
	</p>

	<?php if ( $stored ) : ?>
		<?php
		$fh_action_shown = 'unavailable' === $note_status ? Bands::ACTION_UNAVAILABLE : $stored['action'];
		?>
		<div class="fh-weather-box__result">
			<?php echo Admin::badge_html( $fh_action_shown, $stored['at_line'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by badge_html(). ?>
			<?php if ( null !== $stored['high'] ) : ?>
				<span class="fh-weather-box__temps">
					<?php
					echo esc_html( sprintf( 'High %d°F', $stored['high'] ) );
					if ( null !== $stored['low'] ) {
						echo esc_html( sprintf( ' / Low %d°F', $stored['low'] ) );
					}
					?>
				</span>
			<?php endif; ?>
		</div>

		<?php if ( 'stale' === $note_status ) : ?>
			<p class="fh-weather-box__warn">
				<?php
				/* translators: %s: the date the stored note was written for */
				echo esc_html( sprintf( __( 'Noted for %s, but the delivery date has changed — check again.', 'fishotel-misc-plugin' ), Note::long_date( $stored['checked_for'] ) ) );
				?>
			</p>
		<?php endif; ?>

		<?php if ( '' !== $stored['note'] ) : ?>
			<p class="fh-weather-box__note"><?php echo esc_html( $stored['note'] ); ?></p>
		<?php endif; ?>

		<p class="fh-weather-box__meta">
			<?php
			/* translators: %s: date/time of the last check */
			echo esc_html( sprintf( __( 'Checked %s', 'fishotel-misc-plugin' ), $stored['checked_at'] ) );
			?>
		</p>

		<?php if ( $stored['email'] ) : ?>
			<div class="fh-weather-box__email">
				<label class="fh-weather-box__label" for="<?php echo esc_attr( $fh_box_id ); ?>-subject">
					<?php esc_html_e( 'Customer email draft — NOT sent, Jeff sends it', 'fishotel-misc-plugin' ); ?>
				</label>
				<input type="text" readonly id="<?php echo esc_attr( $fh_box_id ); ?>-subject" class="fh-weather-box__subject" value="<?php echo esc_attr( $stored['email']['subject'] ); ?>">
				<textarea readonly id="<?php echo esc_attr( $fh_box_id ); ?>-body" class="fh-weather-box__body" rows="9"><?php echo esc_textarea( $stored['email']['body'] ); ?></textarea>
				<button type="button" class="button fh-weather-copy" data-subject="#<?php echo esc_attr( $fh_box_id ); ?>-subject" data-body="#<?php echo esc_attr( $fh_box_id ); ?>-body">
					<?php esc_html_e( 'Copy email', 'fishotel-misc-plugin' ); ?>
				</button>
			</div>
		<?php endif; ?>
	<?php else : ?>
		<p class="fh-weather-box__empty"><?php esc_html_e( 'Not checked yet. The nightly job checks orders delivering in the next 3 days.', 'fishotel-misc-plugin' ); ?></p>
	<?php endif; ?>

	<p class="fh-weather-box__actions">
		<button type="button" class="button button-primary fh-weather-check" data-order-id="<?php echo esc_attr( $order->get_id() ); ?>" <?php disabled( '' === $delivery ); ?>>
			<?php esc_html_e( 'Check weather now', 'fishotel-misc-plugin' ); ?>
		</button>
		<span class="fh-weather-status" aria-live="polite"></span>
	</p>
</div>
