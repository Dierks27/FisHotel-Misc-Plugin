<?php
/**
 * "Tidy up the sizes": the status panel — a tidy-up that is not
 * finished, the ones Undo can take back, the sizes that are retiring,
 * and the "Sizes to fill in" list (Admin_Sizes_Tidy::status_html()).
 *
 * @var array $status Admin_Sizes_Tidy::status().
 *
 * @package FisHotel\Misc\Sections\Clean_Room
 */

defined( 'ABSPATH' ) || exit;

use FisHotel\Misc\Sections\Clean_Room\Admin_Sizes_Tidy;

$fh_open = $status['open'];
$fh_runs = $status['runs'];
?>
<?php if ( $fh_open ) : ?>
	<div class="fh-tidy__box fh-tidy__box--warn">
		<h3 class="fh-cr-h3"><?php echo esc_html( 'undoing' === $fh_open['status'] ? __( 'An undo stopped half-way', 'fishotel-misc-plugin' ) : __( 'A tidy-up stopped half-way', 'fishotel-misc-plugin' ) ); ?></h3>
		<p><?php echo esc_html( Admin_Sizes_Tidy::progress_text( $fh_open['progress'] ) ); ?> <?php esc_html_e( 'Nothing is lost: carry on and it picks up where it stopped.', 'fishotel-misc-plugin' ); ?></p>
		<?php if ( 'undoing' !== $fh_open['status'] ) : ?>
			<p class="fh-cr-sub"><?php esc_html_e( 'It finishes with the choices it was started with. A fish that came back into stock in the meantime is not taken off: it is left for sale and named at the end.', 'fishotel-misc-plugin' ); ?></p>
		<?php endif; ?>
		<p class="fh-cr-actions-row">
			<button type="button" class="button button-primary fh-tidy__continue" data-run="<?php echo esc_attr( (string) $fh_open['id'] ); ?>"><?php esc_html_e( 'Carry on', 'fishotel-misc-plugin' ); ?></button>
			<?php if ( 'undoing' !== $fh_open['status'] ) : ?>
				<button type="button" class="button fh-tidy__undo"><?php esc_html_e( 'Undo it instead', 'fishotel-misc-plugin' ); ?></button>
			<?php endif; ?>
		</p>
	</div>
<?php endif; ?>

<p class="fh-tidy__progress" role="status" aria-live="polite" hidden></p>

<?php if ( $fh_runs ) : ?>
	<div class="fh-tidy__box">
		<h3 class="fh-cr-h3"><?php esc_html_e( 'Undo', 'fishotel-misc-plugin' ); ?></h3>
		<p class="fh-cr-sub">
			<?php
			/* translators: %d: how many tidy-ups are kept */
			echo esc_html( sprintf( __( 'Before a tidy-up changes anything it saves a complete record of what it is about to change; Undo puts every bit of it back. Two things stay as they are on the day of the undo: a variation you have given another size since (it is named, not overwritten), and stock and prices, which Undo never touches — a fish that sold out since still reads sold out. The last %d tidy-ups are kept — the latest is undone first.', 'fishotel-misc-plugin' ), (int) $status['max_runs'] ) );
			?>
		</p>
		<ol class="fh-tidy__runs">
			<?php foreach ( $fh_runs as $fh_index => $fh_run ) : ?>
				<li>
					<?php
					/* translators: 1: date and time, 2: user login */
					echo esc_html( sprintf( __( '%1$s, by %2$s', 'fishotel-misc-plugin' ), Admin_Sizes_Tidy::when( (string) $fh_run['created_at'] ), '' !== (string) $fh_run['created_by'] ? (string) $fh_run['created_by'] : '—' ) );
					if ( isset( $fh_run['data']['totals'] ) && is_array( $fh_run['data']['totals'] ) ) {
						echo ' — ' . esc_html( Admin_Sizes_Tidy::totals_text( $fh_run['data']['totals'] ) );
					}
					?>
					<?php if ( 0 === $fh_index && ! $fh_open ) : ?>
						<button type="button" class="button fh-tidy__undo"><?php esc_html_e( 'Undo the last tidy-up', 'fishotel-misc-plugin' ); ?></button>
					<?php endif; ?>
				</li>
			<?php endforeach; ?>
		</ol>
	</div>
<?php endif; ?>

<?php if ( $status['retiring'] || $status['waiting'] ) : ?>
	<div class="fh-tidy__box">
		<h3 class="fh-cr-h3"><?php esc_html_e( 'Sizes on their way out', 'fishotel-misc-plugin' ); ?></h3>
		<p class="fh-cr-sub"><?php esc_html_e( 'Still in the shop because something still uses them. They are no longer offered when you pick a size in the Clean Room.', 'fishotel-misc-plugin' ); ?></p>
		<ul class="fh-tidy__list">
			<?php foreach ( $status['retiring'] as $fh_size ) : ?>
				<li>
					<strong><?php echo esc_html( $fh_size['label'] ); ?></strong> —
					<?php
					if ( $fh_size['left'] > 0 ) {
						/* translators: %d: fish left */
						echo esc_html( sprintf( _n( 'retiring — %d fish left for sale', 'retiring — %d fish left for sale', $fh_size['left'], 'fishotel-misc-plugin' ), $fh_size['left'] ) );
					} else {
						esc_html_e( 'retiring — nothing in stock carries it any more: it can be finished', 'fishotel-misc-plugin' );
					}
					?>
				</li>
			<?php endforeach; ?>
			<?php foreach ( $status['waiting'] as $fh_label ) : ?>
				<li><strong><?php echo esc_html( (string) $fh_label ); ?></strong> — <?php esc_html_e( 'waiting: a product that needs a look, or one in the trash, still uses it', 'fishotel-misc-plugin' ); ?></li>
			<?php endforeach; ?>
		</ul>
		<?php if ( '' !== $status['taxonomy'] ) : ?>
			<p class="fh-cr-actions-row">
				<button type="button" class="button fh-tidy__finish"><?php esc_html_e( 'Finish retiring…', 'fishotel-misc-plugin' ); ?></button>
				<span class="fh-cr-muted"><?php esc_html_e( 'Shows what would change first, like any tidy-up. A size whose fish have sold out is taken off; one that still has fish for sale keeps waiting.', 'fishotel-misc-plugin' ); ?></span>
			</p>
		<?php endif; ?>
	</div>
<?php endif; ?>

<?php if ( $status['fill'] ) : ?>
	<div class="fh-tidy__box">
		<h3 class="fh-cr-h3"><?php esc_html_e( 'Sizes to fill in', 'fishotel-misc-plugin' ); ?></h3>
		<p class="fh-cr-sub"><?php esc_html_e( 'Every fish a tidy-up took a size off that has not been given one since. The size it had is switched off on the product, with its price, stock and SKU kept: open the product, give that variation a size of the short list and tick "Enabled" again. A fish leaves this list by itself once that is done.', 'fishotel-misc-plugin' ); ?></p>
		<table class="widefat striped fh-cr-table fh-tidy__fill">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Fish', 'fishotel-misc-plugin' ); ?></th>
					<th scope="col"><?php esc_html_e( 'The size it had', 'fishotel-misc-plugin' ); ?></th>
					<th scope="col"><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'fishotel-misc-plugin' ); ?></span></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $status['fill'] as $fh_fish ) : ?>
					<tr>
						<td><a href="<?php echo esc_url( Admin_Sizes_Tidy::edit_url( $fh_fish['id'] ) ); ?>"><?php echo esc_html( '' !== (string) $fh_fish['title'] ? (string) $fh_fish['title'] : '#' . (int) $fh_fish['id'] ); ?></a></td>
						<td><?php echo esc_html( implode( ', ', array_unique( array_map( 'strval', array_column( $fh_fish['sizes'], 'label' ) ) ) ) ); ?></td>
						<td><button type="button" class="button-link fh-tidy__dismiss" data-product="<?php echo esc_attr( (string) $fh_fish['id'] ); ?>"><?php esc_html_e( 'Tick off — no size needed', 'fishotel-misc-plugin' ); ?></button></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	</div>
<?php endif; ?>
