<?php
/**
 * To do — Held by hand: every hold made with "Reserve…" (tank, fish, who
 * for, how many, how long — an old one stands out — and the note), each
 * with Release; above them the orders that may be the one a hold for a
 * typed name was made for ("Is #1240 the order for the fish held for Mike
 * S.?" — Give them the held fish / Not this order). Not on Pull today: no
 * order is behind a hold.
 *
 * @package FisHotel\Misc\Sections\Clean_Room
 *
 * @var array $list Front_Desk::todo_screen( Todo::build() ) with holds and suggest.
 */

defined( 'ABSPATH' ) || exit;

use FisHotel\Misc\Sections\Clean_Room\Admin_Reserve;
use FisHotel\Misc\Sections\Clean_Room\Holds;
use FisHotel\Misc\Sections\Clean_Room\Reserve_Shop;

$fh_holds   = isset( $list['holds'] ) && is_array( $list['holds'] ) ? $list['holds'] : array();
$fh_suggest = isset( $list['suggest'] ) && is_array( $list['suggest'] ) ? $list['suggest'] : array();
$fh_closed  = isset( $list['closed'] ) && is_array( $list['closed'] ) ? $list['closed'] : array(); // Ended in the last 7 days.
if ( ! $fh_holds && ! $fh_suggest && ! $fh_closed ) {
	return;
}

/**
 * One button posting one hold action.
 *
 * @param string $op     release | give | not.
 * @param array  $fields hold_id, order_id, ref.
 * @param string $label  The button.
 * @param string $class  Extra class.
 */
$fh_hold_button = static function ( $op, array $fields, $label, $class = '' ) {
	?>
	<form class="fh-cr-pull__form" method="post" action="<?php echo esc_url( Admin_Reserve::form_url() ); ?>">
		<?php wp_nonce_field( Reserve_Shop::ACTION ); ?>
		<input type="hidden" name="action" value="<?php echo esc_attr( Reserve_Shop::ACTION ); ?>">
		<input type="hidden" name="op" value="<?php echo esc_attr( $op ); ?>">
		<input type="hidden" name="back" value="todo">
		<?php foreach ( $fields as $fh_key => $fh_value ) : ?>
			<input type="hidden" name="<?php echo esc_attr( $fh_key ); ?>" value="<?php echo esc_attr( (string) $fh_value ); ?>">
		<?php endforeach; ?>
		<button type="submit" class="button button-small <?php echo esc_attr( $class ); ?>"><?php echo esc_html( $label ); ?></button>
	</form>
	<?php
};
?>
<section class="fh-cr-todo-block fh-cr-holds">
	<header class="fh-cr-todo-block__head">
		<h2><?php esc_html_e( 'Held by hand', 'fishotel-misc-plugin' ); ?></h2>
		<span class="fh-cr-building__meta"><?php echo esc_html( sprintf( /* translators: %d: holds */ _n( '%d hold — released by hand only', '%d holds — released by hand only', count( $fh_holds ), 'fishotel-misc-plugin' ), count( $fh_holds ) ) ); ?></span>
	</header>

	<?php foreach ( $fh_suggest as $fh_s ) : ?>
		<div class="fh-cr-pull__row fh-cr-holds__ask">
			<span><strong><?php echo esc_html( Holds::question( $fh_s ) ); ?></strong>
				<span class="fh-cr-muted"><?php echo esc_html( sprintf( /* translators: 1: fish, 2: the fish, 3: tank */ __( '%1$d × %2$s, tank %3$d', 'fishotel-misc-plugin' ), (int) $fh_s['qty'], (string) $fh_s['name'], (int) $fh_s['tank'] ) ); ?>
				· <a href="<?php echo esc_url( Admin_Reserve::order_url( (int) $fh_s['order_id'] ) ); ?>"><?php esc_html_e( 'order', 'fishotel-misc-plugin' ); ?> &rarr;</a></span>
			</span>
			<?php $fh_hold_button( 'give', array( 'hold_id' => (int) $fh_s['hold_id'], 'order_id' => (int) $fh_s['order_id'], 'ref' => (string) $fh_s['ref'] ), __( 'Give them the held fish', 'fishotel-misc-plugin' ), 'button-primary' ); ?>
			<?php $fh_hold_button( 'not', array( 'hold_id' => (int) $fh_s['hold_id'], 'order_id' => (int) $fh_s['order_id'], 'ref' => (string) $fh_s['ref'] ), __( 'Not this order', 'fishotel-misc-plugin' ) ); ?>
		</div>
	<?php endforeach; ?>

	<?php foreach ( $fh_holds as $fh_h ) : ?>
		<div class="fh-cr-pull__row<?php echo ! empty( $fh_h['old'] ) ? ' fh-cr-holds--old' : ''; ?>">
			<span>
				<?php if ( ! empty( $fh_h['gone'] ) ) : ?>
					<span class="fh-cr-warn"><?php esc_html_e( 'its tank group is gone', 'fishotel-misc-plugin' ); ?></span>
				<?php else : ?>
					<?php echo esc_html( sprintf( /* translators: 1: tank, 2: fish */ __( 'Tank %1$d — %2$s', 'fishotel-misc-plugin' ), (int) $fh_h['tank'], (string) $fh_h['fish'] ) ); ?>
				<?php endif; ?>
				· <strong><?php echo esc_html( (int) $fh_h['asked'] . ' × ' . (string) $fh_h['who'] ); ?></strong>
				<?php if ( ! empty( $fh_h['account'] ) ) : ?>
					<span class="fh-cr-muted"><?php esc_html_e( '(customer — their own order takes them)', 'fishotel-misc-plugin' ); ?></span>
				<?php endif; ?>
				· <span class="<?php echo ! empty( $fh_h['old'] ) ? 'fh-cr-warn' : 'fh-cr-muted'; ?>"><?php echo esc_html( sprintf( /* translators: %s: "3 days ago" */ __( 'held %s', 'fishotel-misc-plugin' ), (string) $fh_h['age'] ) ); ?></span>
				<?php if ( (int) $fh_h['qty'] < (int) $fh_h['asked'] ) : // Fish died: the orders keep theirs, the hold shrinks. ?>
					· <span class="fh-cr-warn"><?php echo esc_html( Holds::short_text( (int) $fh_h['qty'] ) ); ?></span>
				<?php endif; ?>
				<?php if ( '' !== (string) $fh_h['note'] ) : ?>
					· <em><?php echo esc_html( (string) $fh_h['note'] ); ?></em>
				<?php endif; ?>
			</span>
			<?php $fh_hold_button( 'release', array( 'hold_id' => (int) $fh_h['id'] ), __( 'Release', 'fishotel-misc-plugin' ) ); ?>
		</div>
	<?php endforeach; ?>

	<?php if ( $fh_closed ) : // A hold never just vanishes: what ended it, for a week. ?>
		<h3 class="fh-cr-holds__closed-head"><?php esc_html_e( 'Ended lately', 'fishotel-misc-plugin' ); ?></h3>
		<?php foreach ( $fh_closed as $fh_c ) : ?>
			<div class="fh-cr-pull__row fh-cr-holds__closed">
				<span>
					<?php if ( (int) $fh_c['tank'] > 0 ) : ?>
						<?php echo esc_html( sprintf( /* translators: 1: tank, 2: fish */ __( 'Tank %1$d — %2$s', 'fishotel-misc-plugin' ), (int) $fh_c['tank'], (string) $fh_c['fish'] ) ); ?> ·
					<?php endif; ?>
					<?php echo esc_html( (int) $fh_c['qty'] . ' × ' . (string) $fh_c['who'] ); ?>
					· <strong><?php echo esc_html( (string) $fh_c['why'] ); ?></strong>
					· <span class="fh-cr-muted"><?php echo esc_html( (string) $fh_c['when'] ); ?></span>
				</span>
			</div>
		<?php endforeach; ?>
	<?php endif; ?>
</section>
