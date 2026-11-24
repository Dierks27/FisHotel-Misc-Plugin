<?php
/**
 * Room Board — the lines of one tank card (a rack tank's or a temporary
 * setup's): the fish in alphabetical order, the same fish of the same
 * size and state gathered into one line (Board_Lines::card()), each line
 * one row of columns — name · size · count · day · badge — with what
 * needs saying under it. A click or a tap on a line opens it: the name
 * and the size in full and its groups, each with its count, its day, the
 * quarantine tank it came out of, its sale date and its row on the Stays
 * tab.
 *
 * @package FisHotel\Misc\Sections\Clean_Room
 *
 * @var array   $fh_card    Board_Lines::card() of the tank's occupants.
 * @var array   $fh_tank    The tank (number).
 * @var string  $fh_species The Board's species filter.
 * @var bool    $fh_qt      Say "QT day" (a temporary tank has a day of its own).
 * @var array[] $sizes      The standard sizes (slug, label).
 * @var array   $origins    Admin::origins(): stay id => the quarantine tank it came out of.
 */

defined( 'ABSPATH' ) || exit;

use FisHotel\Misc\Sections\Clean_Room\Admin;
use FisHotel\Misc\Sections\Clean_Room\Admin_Reserve;
use FisHotel\Misc\Sections\Clean_Room\Board_Lines;
use FisHotel\Misc\Sections\Clean_Room\Front_Desk;
use FisHotel\Misc\Sections\Clean_Room\Holds;
use FisHotel\Misc\Sections\Clean_Room\Reserve;
use FisHotel\Misc\Sections\Clean_Room\Reserve_Shop;
use FisHotel\Misc\Sections\Clean_Room\Settings;

$fh_origins = isset( $origins ) && is_array( $origins ) ? $origins : array();
// "Reserve…" by hand: only while reserving is on (the holds are hidden while it is off, never dropped).
if ( ! isset( $fh_hold_on ) ) {
	$fh_hold_on = class_exists( Admin_Reserve::class, false ) && Reserve_Shop::enabled( Settings::get() );
}
?>
<ul class="fh-cr-tank__list fh-cr-cardlines">
	<?php foreach ( $fh_card['lines'] as $fh_at => $fh_line ) : ?>
		<?php
		$fh_first  = $fh_line['stays'][0];
		$fh_groups = count( $fh_line['stays'] );
		$fh_day    = Board_Lines::day_text( $fh_line, ! empty( $fh_qt ) );
		$fh_sale   = Board_Lines::sale_text( $fh_line );
		$fh_more   = 'fh-cr-cardline-' . (int) $fh_tank['number'] . '-' . (int) $fh_at;
		$fh_class  = 'fh-cr-cardline';
		$fh_class .= Board_Lines::matches( $fh_line, $fh_species ) ? ' is-hit' : '';
		$fh_class .= $fh_line['unknown'] ? ' is-unknown' : '';
		$fh_class .= $fh_line['repeat'] ? ' is-repeat' : '';
		// Ready → Reserved → Shipped: "5 ready · 2 reserved (#1234)" — reserved fish are still in the tank until they ship.
		$fh_reserved = Reserve::stage_text( Reserve::stage_sum( $fh_line['stays'] ) );
		$fh_notes    = $fh_groups > 1 || $fh_line['unknown'] || ( $fh_line['size'] && ! $fh_line['size']['standard'] ) || $fh_line['in_spa'] || '' !== $fh_sale || '' !== $fh_reserved;
		?>
		<li class="<?php echo esc_attr( $fh_class ); ?>">
			<button type="button" class="fh-cr-cardline__name" aria-expanded="false" aria-controls="<?php echo esc_attr( $fh_more ); ?>"><?php echo esc_html( $fh_line['species'] ); ?></button>
			<span class="fh-cr-cardline__size"><?php if ( $fh_line['size'] ) : ?><span class="fh-cr-size"><?php echo esc_html( $fh_line['size']['label'] ); ?></span><?php endif; ?></span>
			<span class="fh-cr-cardline__qty">&times;&nbsp;<?php echo esc_html( $fh_line['qty'] ); ?></span>
			<span class="fh-cr-cardline__day"><?php echo esc_html( $fh_day ); ?></span>
			<span class="fh-cr-cardline__state"><?php echo Admin::badge_html( $fh_first, false ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by badge_html(). ?></span>
			<?php if ( $fh_notes ) : ?>
				<span class="fh-cr-cardline__notes">
					<?php if ( $fh_groups > 1 ) : ?>
						<span class="fh-cr-muted"><?php echo esc_html( sprintf( /* translators: %d: groups gathered in this line */ __( '%d groups', 'fishotel-misc-plugin' ), $fh_groups ) ); ?></span>
					<?php endif; ?>
					<?php echo Admin::unknown_html( array( 'needs_id' => $fh_line['unknown'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by unknown_html(). ?>
					<?php if ( $fh_line['size'] && ! $fh_line['size']['standard'] ) : ?>
						<span class="fh-cr-warn"><?php esc_html_e( 'not a standard size', 'fishotel-misc-plugin' ); ?></span>
					<?php endif; ?>
					<?php if ( $fh_line['in_spa'] ) : ?>
						<span class="fh-cr-warn"><?php echo esc_html( Admin::sale_words( $fh_first )['warning'] ); ?></span>
					<?php endif; ?>
					<?php if ( '' !== $fh_sale ) : ?>
						<span class="fh-cr-tank__sale"><?php echo esc_html( $fh_sale ); ?></span>
					<?php endif; ?>
					<?php if ( '' !== $fh_reserved ) : ?>
						<span class="fh-cr-reserved"><?php echo esc_html( $fh_reserved ); ?></span>
					<?php endif; ?>
				</span>
			<?php endif; ?>
			<div class="fh-cr-cardline__more" id="<?php echo esc_attr( $fh_more ); ?>" hidden>
				<strong class="fh-cr-cardline__full"><?php echo esc_html( $fh_line['species'] . ( $fh_line['size'] ? ' · ' . $fh_line['size']['label'] : '' ) ); ?></strong>
				<ul class="fh-cr-cardline__groups">
					<?php foreach ( $fh_line['stays'] as $fh_stay ) : ?>
						<?php
						$fh_words = Admin::sale_words( $fh_stay );
						$fh_parts = array( '× ' . (int) $fh_stay['qty_live'] );
						if ( '' !== Admin::day_text( $fh_stay, ! empty( $fh_qt ) ) ) {
							$fh_parts[] = Admin::day_text( $fh_stay, ! empty( $fh_qt ) );
						}
						if ( 'cleared' === (string) $fh_stay['status'] && ! empty( $fh_origins[ (int) $fh_stay['id'] ] ) ) {
							/* translators: %d: the quarantine tank the group came out of */
							$fh_parts[] = sprintf( __( 'cleared from %d', 'fishotel-misc-plugin' ), (int) $fh_origins[ (int) $fh_stay['id'] ] );
						}
						$fh_parts[] = '' !== $fh_words['date'] ? $fh_words['text'] . ' (' . $fh_words['source'] . ')' : $fh_words['text'];
						if ( ! empty( $fh_stay['reserved'] ) ) {
							$fh_parts[] = Reserve::stage_text( $fh_stay );
						}
						?>
						<li>
							<?php echo esc_html( implode( ' · ', $fh_parts ) ); ?>
							<a href="<?php echo esc_url( Admin::tank_stays_url( (int) $fh_tank['number'], false, (int) $fh_stay['id'] ) ); ?>"><?php esc_html_e( 'Stays', 'fishotel-misc-plugin' ); ?> &rarr;</a>
							<?php if ( $fh_hold_on ) : ?>
								<?php foreach ( (array) ( $fh_stay['reserved_holds'] ?? array() ) as $fh_hold ) : // Held by hand: who, how long (an old one stands out), the note, Release. ?>
									<form class="fh-cr-hold__row<?php echo Holds::is_old( $fh_hold, time() ) ? ' is-old' : ''; ?>" method="post" action="<?php echo esc_url( Admin_Reserve::form_url() ); ?>">
										<?php wp_nonce_field( Reserve_Shop::ACTION ); ?>
										<input type="hidden" name="action" value="<?php echo esc_attr( Reserve_Shop::ACTION ); ?>">
										<input type="hidden" name="op" value="release">
										<input type="hidden" name="back" value="board">
										<input type="hidden" name="hold_id" value="<?php echo esc_attr( (int) $fh_hold['id'] ); ?>">
										<span><?php echo esc_html( (int) $fh_hold['qty'] . ' × ' . Reserve::hold_label( $fh_hold, time() ) . ( '' !== (string) ( $fh_hold['note'] ?? '' ) ? ' · ' . $fh_hold['note'] : '' ) ); ?></span>
										<button type="submit" class="button button-small"><?php esc_html_e( 'Release', 'fishotel-misc-plugin' ); ?></button>
									</form>
								<?php endforeach; ?>
								<?php if ( Reserve::ready( $fh_stay ) > 0 ) : ?>
									<details class="fh-cr-hold">
										<summary><?php esc_html_e( 'Reserve…', 'fishotel-misc-plugin' ); ?></summary>
										<form class="fh-cr-hold__form" method="post" action="<?php echo esc_url( Admin_Reserve::form_url() ); ?>" data-ajax="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>" data-nonce="<?php echo esc_attr( wp_create_nonce( Front_Desk::NONCE ) ); ?>">
											<?php wp_nonce_field( Reserve_Shop::ACTION ); ?>
											<input type="hidden" name="action" value="<?php echo esc_attr( Reserve_Shop::ACTION ); ?>">
											<input type="hidden" name="op" value="hold">
											<input type="hidden" name="back" value="board">
											<input type="hidden" name="stay_id" value="<?php echo esc_attr( (int) $fh_stay['id'] ); ?>">
											<input type="hidden" name="user_id" value="0" class="fh-cr-hold__user">
											<label><?php esc_html_e( 'How many', 'fishotel-misc-plugin' ); ?>
												<input type="number" name="qty" min="1" max="<?php echo esc_attr( Reserve::ready( $fh_stay ) ); ?>" value="1" required>
												<span class="fh-cr-muted"><?php echo esc_html( sprintf( /* translators: %d: fish ready */ __( 'of %d ready', 'fishotel-misc-plugin' ), Reserve::ready( $fh_stay ) ) ); ?></span>
											</label>
											<label><?php esc_html_e( 'For whom', 'fishotel-misc-plugin' ); ?>
												<input type="text" name="who" class="fh-cr-hold__who" maxlength="<?php echo esc_attr( Holds::WHO_MAX ); ?>" autocomplete="off" required placeholder="<?php esc_attr_e( 'a customer (name or e-mail) or any name', 'fishotel-misc-plugin' ); ?>">
											</label>
											<ul class="fh-cr-hold__found" hidden></ul>
											<label><?php esc_html_e( 'Note', 'fishotel-misc-plugin' ); ?>
												<input type="text" name="note" maxlength="<?php echo esc_attr( Holds::NOTE_MAX ); ?>" placeholder="<?php esc_attr_e( 'optional, e.g. paying Friday', 'fishotel-misc-plugin' ); ?>">
											</label>
											<button type="submit" class="button button-primary button-small"><?php esc_html_e( 'Reserve', 'fishotel-misc-plugin' ); ?></button>
										</form>
									</details>
								<?php endif; ?>
							<?php endif; ?>
						</li>
					<?php endforeach; ?>
				</ul>
			</div>
		</li>
	<?php endforeach; ?>
</ul>
