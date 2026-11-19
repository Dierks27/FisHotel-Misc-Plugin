<?php
/**
 * Packing page — Boxes: where the boxes come from, this section's own
 * list, how many bags fit each box, and the "on the line" percent.
 *
 * @package FisHotel\Misc\Sections\Packing
 *
 * @var array $settings Settings::get().
 * @var array $current  Boxes::current(): source, shiptracker, boxes, own.
 * @var array $boxes    The boxes in use with `fits` and `fits_from`, and the fit model's
 *                      `fits_typed`, `fits_computed`, `tie_off` and `usable`.
 * @var array $model    Boxes::model(): foam, share, foam_from.
 */

defined( 'ABSPATH' ) || exit;

use FisHotel\Misc\Sections\Packing\Admin;
use FisHotel\Misc\Sections\Packing\Boxes;
use FisHotel\Misc\Sections\Packing\Fit;
use FisHotel\Misc\Sections\Packing\Settings;

$fh_bags     = $settings['bags'];
$fh_own      = array_values( $settings['boxes'] );
$fh_from_st  = Boxes::SOURCE_SHIPTRACKER === $current['source'];
$fh_spare    = $fh_own ? 1 : 3;
$fh_next     = count( $fh_own ) + $fh_spare;
$fh_st_url   = admin_url( 'admin.php?page=fst-settings&tab=boxes' );
$fh_adopted  = array();
foreach ( $boxes as $fh_box ) {
	if ( '' !== $fh_box['fits_from'] && $fh_box['fits_from'] !== $fh_box['key'] ) {
		$fh_adopted[] = $fh_box['name'];
	}
}

if ( $fh_from_st ) {
	/* translators: %d: number of boxes */
	$fh_source = sprintf( _n( 'The boxes come from ShipTracker → Settings → Boxes & label costs (%d box) — one list for label costs, packing and foam: add, rename or resize a box there.', 'The boxes come from ShipTracker → Settings → Boxes & label costs (%d boxes) — one list for label costs, packing and foam: add, rename or resize a box there.', count( $current['boxes'] ), 'fishotel-misc-plugin' ), count( $current['boxes'] ) );
} elseif ( Boxes::SHIPTRACKER_EMPTY === $current['shiptracker'] ) {
	$fh_source = __( 'ShipTracker has a list of standard boxes, but it is still empty — so this section\'s own list below is used. As soon as ShipTracker has a box, its list takes over (numbers typed here follow a box with the same name, or the same size).', 'fishotel-misc-plugin' );
} else {
	$fh_source = __( 'ShipTracker does not provide standard boxes on this site (it is not active, or its version has no such list yet) — so this section\'s own list below is used. When ShipTracker provides them, its list takes over (numbers typed here follow a box with the same name, or the same size).', 'fishotel-misc-plugin' );
}
?>
<div class="fishotel-misc-card fh-pack-card">
	<div class="fishotel-misc-card-header">
		<div class="fishotel-misc-card-icon"><span class="dashicons dashicons-archive"></span></div>
		<h2 class="fishotel-misc-card-title"><?php esc_html_e( 'Boxes in use', 'fishotel-misc-plugin' ); ?></h2>
	</div>
	<p class="fishotel-misc-card-desc"><?php echo esc_html( $fh_source ); ?></p>

	<?php if ( $fh_from_st ) : ?>
		<p class="fishotel-misc-card-desc"><a href="<?php echo esc_url( $fh_st_url ); ?>"><?php esc_html_e( 'Edit the boxes in ShipTracker', 'fishotel-misc-plugin' ); ?> &rarr;</a></p>
	<?php endif; ?>

	<?php if ( $boxes ) : ?>
		<table class="fh-pack-table fh-pack-table--narrow">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Box', 'fishotel-misc-plugin' ); ?></th>
					<th><?php esc_html_e( 'Length × width × height', 'fishotel-misc-plugin' ); ?></th>
					<th class="fh-pack-num"><?php esc_html_e( 'Volume (cu in)', 'fishotel-misc-plugin' ); ?></th>
					<th><?php esc_html_e( 'Room for bags (inside the foam)', 'fishotel-misc-plugin' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $boxes as $fh_box ) : ?>
					<tr>
						<td><?php echo esc_html( $fh_box['name'] ); ?></td>
						<td><?php echo esc_html( Boxes::size_text( $fh_box ) ); ?></td>
						<td class="fh-pack-num"><?php echo esc_html( Boxes::num_text( $fh_box['volume'], 1 ) ); ?></td>
						<td class="fh-pack-muted">
							<?php
							$fh_room = $fh_box['usable'] ?? Fit::usable( $fh_box, $model['foam'] );
							/* translators: 1: floor length, 2: floor width, 3: height, all in inches */
							echo esc_html( sprintf( __( 'floor %1$s × %2$s in, %3$s in tall', 'fishotel-misc-plugin' ), Boxes::num_text( $fh_room['length'] ), Boxes::num_text( $fh_room['width'] ), Boxes::num_text( $fh_room['height'] ) ) );
							?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<p class="fishotel-misc-card-desc"><?php esc_html_e( 'Smallest first — the suggestion is the smallest box (by volume) that holds the order.', 'fishotel-misc-plugin' ); ?></p>
	<?php else : ?>
		<div class="fishotel-misc-notice fishotel-misc-notice--error"><?php esc_html_e( 'There are no boxes yet, so no box can be suggested.', 'fishotel-misc-plugin' ); ?></div>
	<?php endif; ?>
</div>

<?php
// One home per list: while ShipTracker provides the boxes, this section's
// own list is hidden (never deleted) — it is used again, as it was, the
// moment ShipTracker has none.
if ( ! $fh_from_st ) :
	?>
<div class="fishotel-misc-card fh-pack-card">
	<div class="fishotel-misc-card-header">
		<div class="fishotel-misc-card-icon"><span class="dashicons dashicons-editor-ul"></span></div>
		<h2 class="fishotel-misc-card-title"><?php esc_html_e( 'This section\'s own list of boxes', 'fishotel-misc-plugin' ); ?></h2>
	</div>
	<p class="fishotel-misc-card-desc">
		<?php
		esc_html_e( 'Name and inside size in inches. Nothing is filled in for you.', 'fishotel-misc-plugin' );
		?>
	</p>

	<form class="fh-pack-form" method="post" action="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>" data-reload="1">
		<?php wp_nonce_field( Settings::NONCE_ACTION ); ?>
		<input type="hidden" name="action" value="fishotel_packing_boxes">

		<table class="fh-pack-table" data-next="<?php echo esc_attr( $fh_next ); ?>">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Name', 'fishotel-misc-plugin' ); ?></th>
					<th><?php esc_html_e( 'Length (in)', 'fishotel-misc-plugin' ); ?></th>
					<th><?php esc_html_e( 'Width (in)', 'fishotel-misc-plugin' ); ?></th>
					<th><?php esc_html_e( 'Height (in)', 'fishotel-misc-plugin' ); ?></th>
					<th><?php esc_html_e( 'Remove', 'fishotel-misc-plugin' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php for ( $fh_i = 0; $fh_i < $fh_next; $fh_i++ ) : ?>
					<?php
					$fh_row = $fh_own[ $fh_i ] ?? array(
						'name'   => '',
						'length' => '',
						'width'  => '',
						'height' => '',
					);
					?>
					<tr<?php echo isset( $fh_own[ $fh_i ] ) ? '' : ' class="fh-pack-row--new"'; ?>>
						<td>
							<?php if ( isset( $fh_own[ $fh_i ] ) ) : ?>
								<input type="hidden" name="boxes[<?php echo esc_attr( $fh_i ); ?>][was]" value="<?php echo esc_attr( $fh_row['name'] ); ?>">
							<?php endif; ?>
							<input type="text" class="fh-pack-input" name="boxes[<?php echo esc_attr( $fh_i ); ?>][name]" value="<?php echo esc_attr( $fh_row['name'] ); ?>" maxlength="<?php echo esc_attr( Settings::MAX_NAME ); ?>" aria-label="<?php esc_attr_e( 'Name', 'fishotel-misc-plugin' ); ?>">
						</td>
						<?php foreach ( array( 'length', 'width', 'height' ) as $fh_side ) : ?>
							<td><input type="number" class="fh-pack-input fh-pack-input--num" name="boxes[<?php echo esc_attr( $fh_i ); ?>][<?php echo esc_attr( $fh_side ); ?>]" value="<?php echo esc_attr( $fh_row[ $fh_side ] ); ?>" min="0" step="0.01" aria-label="<?php echo esc_attr( ucfirst( $fh_side ) ); ?>"></td>
						<?php endforeach; ?>
						<td>
							<?php if ( isset( $fh_own[ $fh_i ] ) ) : ?>
								<label class="fh-pack-check"><input type="checkbox" name="boxes[<?php echo esc_attr( $fh_i ); ?>][remove]" value="1"> <span class="screen-reader-text"><?php esc_html_e( 'Remove this box', 'fishotel-misc-plugin' ); ?></span></label>
							<?php endif; ?>
						</td>
					</tr>
				<?php endfor; ?>
			</tbody>
		</table>

		<script type="text/template" class="fh-pack-row-template">
			<tr class="fh-pack-row--new">
				<td><input type="text" class="fh-pack-input" name="boxes[__i__][name]" value="" maxlength="<?php echo esc_attr( Settings::MAX_NAME ); ?>" aria-label="<?php esc_attr_e( 'Name', 'fishotel-misc-plugin' ); ?>"></td>
				<td><input type="number" class="fh-pack-input fh-pack-input--num" name="boxes[__i__][length]" value="" min="0" step="0.01" aria-label="<?php esc_attr_e( 'Length', 'fishotel-misc-plugin' ); ?>"></td>
				<td><input type="number" class="fh-pack-input fh-pack-input--num" name="boxes[__i__][width]" value="" min="0" step="0.01" aria-label="<?php esc_attr_e( 'Width', 'fishotel-misc-plugin' ); ?>"></td>
				<td><input type="number" class="fh-pack-input fh-pack-input--num" name="boxes[__i__][height]" value="" min="0" step="0.01" aria-label="<?php esc_attr_e( 'Height', 'fishotel-misc-plugin' ); ?>"></td>
				<td></td>
			</tr>
		</script>

		<p class="fh-pack-actions">
			<button type="button" class="button fh-pack-add-row"><?php esc_html_e( 'Add a box', 'fishotel-misc-plugin' ); ?></button>
			<button type="submit" class="button button-primary"><?php esc_html_e( 'Save boxes', 'fishotel-misc-plugin' ); ?></button>
			<span class="fh-pack-status" role="status" aria-live="polite"></span>
		</p>
	</form>
</div>
<?php endif; ?>

<div class="fishotel-misc-card fh-pack-card">
	<div class="fishotel-misc-card-header">
		<div class="fishotel-misc-card-icon"><span class="dashicons dashicons-grid-view"></span></div>
		<h2 class="fishotel-misc-card-title"><?php esc_html_e( 'How many fit', 'fishotel-misc-plugin' ); ?></h2>
	</div>
	<p class="fishotel-misc-card-desc">
		<?php esc_html_e( 'Worked out for you from each bag\'s width and length (Bags tab): bags are never stacked, so only the floor counts — each filled bag takes a square of floor as wide as the bag stands across, and a bag goes in a box when it fits the floor\'s shorter side. Under each cell, "by size" is that number; "too wide" means the bag does not go in that box. A bag that stands taller than the box is still used — tie it off shorter (the order says at about what height).', 'fishotel-misc-plugin' ); ?>
	</p>
	<p class="fishotel-misc-card-desc">
		<?php esc_html_e( 'A number you type here wins over the worked-out one ("your number"), for that box and bag — blank goes back to the worked-out number. A bag without a width and a length goes in a box only where you typed a number. An order\'s fill adds up each bag\'s share of the box: 6 small in a box that takes 12 plus 2 large in a box that takes 6 is 50% + 33% = 83%.', 'fishotel-misc-plugin' ); ?>
	</p>

	<?php if ( ! $fh_bags || ! $boxes ) : ?>
		<div class="fishotel-misc-notice fishotel-misc-notice--error">
			<?php
			if ( ! $fh_bags ) {
				printf(
					/* translators: %s: link to the Bags tab */
					esc_html__( 'There are no bag sizes yet — add them under %s first.', 'fishotel-misc-plugin' ),
					'<a href="' . esc_url( Admin::tab_url( 'bags' ) ) . '">' . esc_html__( 'Bags', 'fishotel-misc-plugin' ) . '</a>'
				);
			} else {
				esc_html_e( 'There are no boxes yet — add them above first.', 'fishotel-misc-plugin' );
			}
			?>
		</div>
	<?php endif; ?>

	<form class="fh-pack-form" method="post" action="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>">
		<?php wp_nonce_field( Settings::NONCE_ACTION ); ?>
		<input type="hidden" name="action" value="fishotel_packing_fits">

		<?php if ( $fh_bags && $boxes ) : ?>
			<?php if ( $fh_adopted ) : ?>
				<p class="fishotel-misc-card-desc fh-pack-hint">
					<?php
					/* translators: %s: box names, separated by commas */
					echo esc_html( sprintf( __( 'Carried over from a box of the same size that is no longer in the list: %s. Save to keep the numbers under the new name.', 'fishotel-misc-plugin' ), implode( ', ', $fh_adopted ) ) );
					?>
				</p>
			<?php endif; ?>

			<?php foreach ( $fh_bags as $fh_bag ) : ?>
				<input type="hidden" name="bag_ids[]" value="<?php echo esc_attr( (int) $fh_bag['id'] ); ?>">
			<?php endforeach; ?>

			<div class="fh-pack-scroll">
				<table class="fh-pack-table fh-pack-table--matrix">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Box', 'fishotel-misc-plugin' ); ?></th>
							<?php foreach ( $fh_bags as $fh_bag ) : ?>
								<th class="fh-pack-num"><?php echo esc_html( $fh_bag['name'] ); ?></th>
							<?php endforeach; ?>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $boxes as $fh_i => $fh_box ) : ?>
							<tr>
								<th scope="row">
									<?php echo esc_html( $fh_box['name'] ); ?>
									<span class="fh-pack-sub"><?php echo esc_html( Boxes::size_text( $fh_box ) ); ?></span>
									<input type="hidden" name="box_key[<?php echo esc_attr( $fh_i ); ?>]" value="<?php echo esc_attr( $fh_box['key'] ); ?>">
								</th>
								<?php foreach ( $fh_bags as $fh_bag ) : ?>
									<?php
									$fh_id   = (int) $fh_bag['id'];
									$fh_fit  = (int) ( $fh_box['fits_typed'][ $fh_id ] ?? 0 );
									$fh_calc = $fh_box['fits_computed'][ $fh_id ] ?? null;
									if ( null === $fh_calc ) {
										$fh_note = __( 'no size', 'fishotel-misc-plugin' );
									} elseif ( 0 === (int) $fh_calc ) {
										$fh_note = __( 'too wide', 'fishotel-misc-plugin' );
									} else {
										/* translators: %d: how many fit by the bag's size */
										$fh_note = sprintf( __( 'by size: %d', 'fishotel-misc-plugin' ), (int) $fh_calc );
									}
									if ( null !== $fh_calc && (int) $fh_calc > 0 && isset( $fh_box['tie_off'][ $fh_id ] ) ) {
										/* translators: %s: inches */
										$fh_note .= ' · ' . sprintf( __( 'tie off at about %s in', 'fishotel-misc-plugin' ), Boxes::num_text( $fh_box['tie_off'][ $fh_id ], 1 ) );
									}
									?>
									<td class="fh-pack-num">
										<input type="number" class="fh-pack-input fh-pack-input--fit" name="fits[<?php echo esc_attr( $fh_i ); ?>][<?php echo esc_attr( $fh_id ); ?>]" value="<?php echo esc_attr( $fh_fit > 0 ? $fh_fit : '' ); ?>" min="1" max="<?php echo esc_attr( Settings::MAX_FIT ); ?>" step="1" placeholder="<?php echo esc_attr( null !== $fh_calc && (int) $fh_calc > 0 ? (int) $fh_calc : '' ); ?>" aria-label="<?php echo esc_attr( sprintf( /* translators: 1: bag name, 2: box name */ __( 'How many %1$s bags fit %2$s', 'fishotel-misc-plugin' ), $fh_bag['name'], $fh_box['name'] ) ); ?>">
										<span class="fh-pack-sub fh-pack-fit-note"><?php echo esc_html( ( $fh_fit > 0 ? __( 'your number', 'fishotel-misc-plugin' ) . ' · ' : '' ) . $fh_note ); ?></span>
									</td>
								<?php endforeach; ?>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		<?php endif; ?>

		<div class="fh-pack-field">
			<label class="fh-pack-label" for="fh-pack-threshold"><?php esc_html_e( 'A suggested box is "on the line" — flagged for you to confirm — at or over', 'fishotel-misc-plugin' ); ?></label>
			<input type="number" id="fh-pack-threshold" class="fh-pack-input fh-pack-input--num" name="threshold" value="<?php echo esc_attr( (int) $settings['threshold'] ); ?>" min="<?php echo esc_attr( Settings::THRESHOLD_MIN ); ?>" max="<?php echo esc_attr( Settings::THRESHOLD_MAX ); ?>" step="1" required>
			<span class="fh-pack-muted"><?php esc_html_e( '% full (default 90) — judged with every fish in its smallest bag, before the bags are upsized to fill the box', 'fishotel-misc-plugin' ); ?></span>
		</div>

		<div class="fh-pack-field">
			<label class="fh-pack-label" for="fh-pack-fill-share"><?php esc_html_e( 'Water and oxygen take this share of a bag\'s length (the rest is the tie-off)', 'fishotel-misc-plugin' ); ?></label>
			<input type="number" id="fh-pack-fill-share" class="fh-pack-input fh-pack-input--num" name="fill_share" value="<?php echo esc_attr( (int) $settings['fill_share'] ); ?>" min="<?php echo esc_attr( Fit::SHARE_MIN ); ?>" max="<?php echo esc_attr( Fit::SHARE_MAX ); ?>" step="1" required>
			<span class="fh-pack-muted">
				<?php
				/* translators: %d: the default percent */
				echo esc_html( sprintf( __( '%% (default %d) — a bag can have its own on the Bags tab', 'fishotel-misc-plugin' ), Fit::SHARE_DEFAULT ) );
				?>
			</span>
		</div>

		<div class="fh-pack-field">
			<label class="fh-pack-label" for="fh-pack-foam"><?php esc_html_e( 'Foam on each side of a box', 'fishotel-misc-plugin' ); ?></label>
			<input type="number" id="fh-pack-foam" class="fh-pack-input fh-pack-input--num" name="foam" value="<?php echo esc_attr( Boxes::num_text( $settings['foam'] ) ); ?>" min="0" max="<?php echo esc_attr( Fit::FOAM_MAX ); ?>" step="0.01" required>
			<span class="fh-pack-muted">
				<?php
				if ( 'foam-cuts' === $model['foam_from'] ) {
					/* translators: %s: inches */
					echo esc_html( sprintf( __( 'in — not used while Foam Cuts is switched on: its foam thickness (%s in) is taken off every side.', 'fishotel-misc-plugin' ), Boxes::num_text( $model['foam'] ) ) );
				} else {
					/* translators: %s: the default inches */
					echo esc_html( sprintf( __( 'in (default %s), taken off every side — while Foam Cuts is switched on, its thickness is used instead.', 'fishotel-misc-plugin' ), Boxes::num_text( Fit::FOAM_DEFAULT ) ) );
				}
				?>
			</span>
		</div>

		<p class="fh-pack-actions">
			<button type="submit" class="button button-primary"><?php esc_html_e( 'Save', 'fishotel-misc-plugin' ); ?></button>
			<span class="fh-pack-status" role="status" aria-live="polite"></span>
		</p>
	</form>
</div>
