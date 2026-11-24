<?php
/**
 * To do — Pull today: which tank each fish of today's boxes comes out of
 * (building → tank → fish → order), a line already out of its tank
 * ticked "Shipped", a fish whose tank is not known with what to do about
 * it; and the fish that shipped but whose tank could not be found. A list
 * to work from: nothing here is ticked — a fish comes off its tank by
 * itself once its order ships. The only writes are the owner's
 * corrections ("Pulled from a different tank", "It was tank…", "Ignore").
 *
 * @package FisHotel\Misc\Sections\Clean_Room
 *
 * @var array  $list     Front_Desk::todo_screen( Todo::build() ) with pull and lost.
 * @var object $store    The store.
 * @var array  $settings Settings.
 */

defined( 'ABSPATH' ) || exit;

use FisHotel\Misc\Sections\Clean_Room\Admin_Reserve;
use FisHotel\Misc\Sections\Clean_Room\Reserve;
use FisHotel\Misc\Sections\Clean_Room\Reserve_Shop;

$fh_pull = isset( $list['pull'] ) && is_array( $list['pull'] ) ? $list['pull'] : null;
$fh_lost = isset( $list['lost'] ) && is_array( $list['lost'] ) ? $list['lost'] : array();
if ( null === $fh_pull && ! $fh_lost ) {
	return;
}

/**
 * One correction form: a picker of tank groups and a button.
 *
 * @param string  $op      pick.
 * @param int     $order   Order id.
 * @param string  $ref     The line's ref.
 * @param int     $from    The group it is held in (0 = the whole line).
 * @param array[] $choices Reserve_Shop::choices().
 * @param string  $label   The button.
 * @param int     $split   Fish of the row that may be moved on their own (0 = the whole row only).
 */
$fh_pick_form = static function ( $op, $order, $ref, $from, array $choices, $label, $split = 0 ) {
	if ( ! $choices ) {
		echo '<span class="fh-cr-muted">' . esc_html__( 'No other clean tank holds this fish on the site.', 'fishotel-misc-plugin' ) . '</span>';
		return;
	}
	?>
	<form class="fh-cr-pull__form" method="post" action="<?php echo esc_url( Admin_Reserve::form_url() ); ?>">
		<?php wp_nonce_field( Reserve_Shop::ACTION ); ?>
		<input type="hidden" name="action" value="<?php echo esc_attr( Reserve_Shop::ACTION ); ?>">
		<input type="hidden" name="op" value="<?php echo esc_attr( $op ); ?>">
		<input type="hidden" name="order_id" value="<?php echo esc_attr( (int) $order ); ?>">
		<input type="hidden" name="ref" value="<?php echo esc_attr( $ref ); ?>">
		<input type="hidden" name="from" value="<?php echo esc_attr( (int) $from ); ?>">
		<?php if ( (int) $split > 1 ) : // Only some of them came out of another tank: the line is split over the two. ?>
			<select name="qty" aria-label="<?php esc_attr_e( 'How many', 'fishotel-misc-plugin' ); ?>">
				<?php for ( $fh_n = (int) $split; $fh_n >= 1; $fh_n-- ) : ?>
					<option value="<?php echo esc_attr( $fh_n === (int) $split ? 0 : $fh_n ); ?>"><?php echo esc_html( $fh_n === (int) $split ? sprintf( /* translators: %d: fish */ __( 'all %d', 'fishotel-misc-plugin' ), $fh_n ) : (string) $fh_n ); ?></option>
				<?php endfor; ?>
			</select>
		<?php endif; ?>
		<select name="stay_id">
			<?php foreach ( $choices as $fh_choice ) : ?>
				<option value="<?php echo esc_attr( (int) $fh_choice['stay_id'] ); ?>"><?php echo esc_html( $fh_choice['text'] ); ?></option>
			<?php endforeach; ?>
		</select>
		<button type="submit" class="button button-small"><?php echo esc_html( $label ); ?></button>
	</form>
	<?php
};
?>
<section class="fh-cr-todo-block fh-cr-pull">
	<header class="fh-cr-todo-block__head">
		<h2><?php esc_html_e( 'Pull today', 'fishotel-misc-plugin' ); ?></h2>
		<?php if ( $fh_pull && '' !== (string) ( $fh_pull['count_text'] ?? '' ) ) : ?>
			<span class="fh-cr-building__meta"><?php echo esc_html( $fh_pull['count_text'] ); ?></span>
		<?php endif; ?>
	</header>

	<?php if ( $fh_pull && ! $fh_pull['buildings'] && ! $fh_pull['unknown'] && ! $fh_pull['loose'] ) : ?>
		<p class="fh-cr-building__empty"><?php esc_html_e( 'No fish ship today.', 'fishotel-misc-plugin' ); ?></p>
	<?php endif; ?>

	<?php foreach ( $fh_pull ? (array) $fh_pull['buildings'] : array() as $fh_building ) : ?>
		<?php if ( '' !== (string) $fh_building['label'] ) : ?>
			<h3 class="fh-cr-pull__building"><?php echo esc_html( $fh_building['label'] ); ?></h3>
		<?php endif; ?>
		<?php foreach ( $fh_building['tanks'] as $fh_ptank ) : ?>
			<div class="fh-cr-pull__tank">
				<strong><?php echo esc_html( sprintf( /* translators: %d: tank number */ __( 'Tank %d', 'fishotel-misc-plugin' ), (int) $fh_ptank['number'] ) ); ?></strong>
				<ul class="fh-cr-pull__rows">
					<?php foreach ( $fh_ptank['rows'] as $fh_row ) : ?>
						<li class="fh-cr-pull__row<?php echo ! empty( $fh_row['shipped'] ) ? ' is-done' : ''; ?>">
							<span>
								<?php if ( ! empty( $fh_row['shipped'] ) ) : ?>
									<span class="fh-cr-todo-check">&#10003;</span>
								<?php endif; ?>
								<?php echo esc_html( $fh_row['text'] ); ?>
								<a href="<?php echo esc_url( Admin_Reserve::order_url( (int) $fh_row['order_id'] ) ); ?>"><?php esc_html_e( 'order', 'fishotel-misc-plugin' ); ?></a>
								<?php if ( ! empty( $fh_row['shipped'] ) ) : ?>
									<em><?php esc_html_e( 'Shipped', 'fishotel-misc-plugin' ); ?></em>
								<?php endif; ?>
							</span>
							<details class="fh-cr-pull__other">
								<summary><?php esc_html_e( 'Pulled from a different tank', 'fishotel-misc-plugin' ); ?></summary>
								<?php $fh_pick_form( 'pick', (int) $fh_row['order_id'], (string) $fh_row['ref'], (int) $fh_row['stay_id'], (array) ( $fh_row['others'] ?? array() ), __( 'It came out of this one', 'fishotel-misc-plugin' ), empty( $fh_row['left'] ) ? (int) $fh_row['qty'] : 0 ); ?>
							</details>
						</li>
					<?php endforeach; ?>
				</ul>
			</div>
		<?php endforeach; ?>
	<?php endforeach; ?>

	<?php if ( $fh_pull && ! empty( $fh_pull['unknown'] ) ) : // One row per fish: a fish already gone is ticked, the others say what to do. ?>
		<div class="fh-cr-pull__tank fh-cr-pull__unknown">
			<strong><?php echo esc_html( (string) ( $fh_pull['unknown_head'] ?? __( 'Tank not known', 'fishotel-misc-plugin' ) ) ); ?></strong>
			<?php if ( '' !== (string) ( $fh_pull['hint'] ?? '' ) ) : ?>
				<p class="fh-cr-muted"><?php echo esc_html( $fh_pull['hint'] ); ?></p>
			<?php endif; ?>
			<ul class="fh-cr-pull__rows">
				<?php foreach ( (array) $fh_pull['unknown'] as $fh_row ) : ?>
					<li class="fh-cr-pull__row<?php echo ! empty( $fh_row['shipped'] ) ? ' is-done' : ' fh-cr-warn'; ?>">
						<span>
							<?php if ( ! empty( $fh_row['shipped'] ) ) : ?>
								<span class="fh-cr-todo-check">&#10003;</span>
							<?php endif; ?>
							<?php echo esc_html( Reserve::row_text( $fh_row ) ); ?>
							<a href="<?php echo esc_url( Admin_Reserve::order_url( (int) $fh_row['order_id'] ) ); ?>"><?php esc_html_e( 'order', 'fishotel-misc-plugin' ); ?></a>
							<?php if ( ! empty( $fh_row['shipped'] ) ) : ?>
								<em><?php esc_html_e( 'Shipped', 'fishotel-misc-plugin' ); ?></em>
							<?php endif; ?>
						</span>
						<?php if ( empty( $fh_row['shipped'] ) && ! empty( $fh_row['others'] ) ) : ?>
							<details class="fh-cr-pull__other">
								<summary><?php esc_html_e( 'It is in a tank already', 'fishotel-misc-plugin' ); ?></summary>
								<?php $fh_pick_form( 'pick', (int) $fh_row['order_id'], (string) $fh_row['ref'], 0, (array) $fh_row['others'], __( 'It was this tank', 'fishotel-misc-plugin' ) ); ?>
							</details>
						<?php endif; ?>
					</li>
				<?php endforeach; ?>
			</ul>
		</div>
	<?php endif; ?>

	<?php if ( $fh_pull && '' !== (string) ( $fh_pull['loose_text'] ?? '' ) ) : ?>
		<p class="fh-cr-muted"><?php echo esc_html( $fh_pull['loose_text'] ); ?></p>
	<?php endif; ?>

	<?php if ( $fh_lost ) : ?>
		<details class="fh-cr-lost">
			<summary class="fh-cr-warn"><?php echo esc_html( Reserve::lost_text( Reserve_Shop::lost_fish( $fh_lost ) ) ); ?></summary>
			<ul class="fh-cr-pull__rows">
				<?php foreach ( $fh_lost as $fh_entry ) : ?>
					<li class="fh-cr-pull__row">
						<span>
							<?php echo esc_html( Reserve::row_text( array_merge( $fh_entry, array( 'size' => (string) ( $fh_entry['size_label'] ?? '' ) ) ) ) ); ?>
							· <?php echo esc_html( (string) $fh_entry['date'] ); ?>
							· <?php echo esc_html( 'short' === (string) $fh_entry['why'] ? __( 'its tank has fewer alive — recount it, or say which tank', 'fishotel-misc-plugin' ) : __( 'its tank was not known', 'fishotel-misc-plugin' ) ); ?>
							<a href="<?php echo esc_url( Admin_Reserve::order_url( (int) $fh_entry['order_id'] ) ); ?>"><?php esc_html_e( 'order', 'fishotel-misc-plugin' ); ?></a>
						</span>
						<?php $fh_pick_form( 'pick', (int) $fh_entry['order_id'], (string) $fh_entry['ref'], 0, Reserve_Shop::lost_choices( $store, $settings, $fh_entry ), __( 'It was this tank', 'fishotel-misc-plugin' ) ); ?>
						<form class="fh-cr-pull__form" method="post" action="<?php echo esc_url( Admin_Reserve::form_url() ); ?>">
							<?php wp_nonce_field( Reserve_Shop::ACTION ); ?>
							<input type="hidden" name="action" value="<?php echo esc_attr( Reserve_Shop::ACTION ); ?>">
							<input type="hidden" name="op" value="ignore">
							<input type="hidden" name="order_id" value="<?php echo esc_attr( (int) $fh_entry['order_id'] ); ?>">
							<input type="hidden" name="ref" value="<?php echo esc_attr( (string) $fh_entry['ref'] ); ?>">
							<button type="submit" class="button button-small"><?php esc_html_e( 'Ignore', 'fishotel-misc-plugin' ); ?></button>
						</form>
					</li>
				<?php endforeach; ?>
			</ul>
			<form method="post" action="<?php echo esc_url( Admin_Reserve::form_url() ); ?>">
				<?php wp_nonce_field( Reserve_Shop::ACTION ); ?>
				<input type="hidden" name="action" value="<?php echo esc_attr( Reserve_Shop::ACTION ); ?>">
				<input type="hidden" name="op" value="ignore_all">
				<button type="submit" class="button button-small"><?php esc_html_e( 'Ignore all', 'fishotel-misc-plugin' ); ?></button>
			</form>
		</details>
	<?php endif; ?>
</section>
