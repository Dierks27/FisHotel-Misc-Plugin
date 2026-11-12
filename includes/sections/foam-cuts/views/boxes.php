<?php
/**
 * Boxes tab — the box list in use (and where it comes from), each box's
 * cut list (the suggestion, or the list the owner saved), and this
 * section's own box list.
 *
 * @package FisHotel\Misc\Sections\Foam_Cuts
 *
 * @var array $context Planner::context(): settings, source, notes, boxes.
 * @var array $orphans Saved cut lists whose box is no longer on the list (key → entry).
 */

defined( 'ABSPATH' ) || exit;

use FisHotel\Misc\Sections\Foam_Cuts\Admin;
use FisHotel\Misc\Sections\Foam_Cuts\Boxes;
use FisHotel\Misc\Sections\Foam_Cuts\Units;

$fh_post = admin_url( 'admin-post.php' );
$fh_own  = Boxes::SOURCE_OWN === $context['source'];
$fh_t    = Units::format( $context['settings']['thickness'] );
?>
<section class="fh-fc-card">
	<h2><?php esc_html_e( 'Boxes and what each takes', 'fishotel-misc-plugin' ); ?></h2>
	<p class="fh-fc-muted">
		<?php
		/* translators: %s: where the box list comes from */
		echo esc_html( sprintf( __( 'The boxes come from %s.', 'fishotel-misc-plugin' ), Boxes::source_label( $context['source'] ) ) );
		?>
		<?php foreach ( $context['notes'] as $fh_note ) : ?>
			<?php echo esc_html( $fh_note ); ?>
		<?php endforeach; ?>
	</p>
	<p class="fh-fc-muted">
		<?php
		echo esc_html(
			sprintf(
				/* translators: %s: foam thickness in inches */
				__( 'A box is lined on all six sides. The suggestion takes the sizes as the INSIDE of the box and foam %s in thick: top and bottom L × W, two long sides L × (H − 2 × foam), two short sides (W − 2 × foam) × (H − 2 × foam). If you cut them differently — or your list holds outside sizes — type the pieces the way you really cut them and save: that list is used from then on.', 'fishotel-misc-plugin' ),
				$fh_t
			)
		);
		?>
	</p>

	<?php if ( ! $context['boxes'] ) : ?>
		<p class="fh-fc-warn"><?php esc_html_e( 'No boxes yet. Add them to the list below.', 'fishotel-misc-plugin' ); ?></p>
	<?php endif; ?>

	<div class="fh-fc-boxes">
		<?php foreach ( $context['boxes'] as $fh_box ) : ?>
			<div class="fh-fc-box">
				<h3>
					<?php echo esc_html( $fh_box['name'] ); ?>
					<span class="fh-fc-muted"><?php echo esc_html( Units::format( $fh_box['length'] ) . ' × ' . Units::format( $fh_box['width'] ) . ' × ' . Units::format( $fh_box['height'] ) . ' in' ); ?></span>
					<span class="fh-fc-chip<?php echo 'saved' === $fh_box['cut_list'] ? ' is-ok' : ''; ?>"><?php echo 'saved' === $fh_box['cut_list'] ? esc_html__( 'your cut list', 'fishotel-misc-plugin' ) : esc_html__( 'suggested', 'fishotel-misc-plugin' ); ?></span>
				</h3>
				<?php if ( $fh_box['changed'] ) : ?>
					<p class="fh-fc-warn"><?php esc_html_e( 'This box has changed size since its cut list was saved. Check the pieces, or go back to the suggestion.', 'fishotel-misc-plugin' ); ?></p>
				<?php endif; ?>
				<?php foreach ( $fh_box['notes'] as $fh_note ) : ?>
					<p class="fh-fc-warn"><?php echo esc_html( $fh_note ); ?></p>
				<?php endforeach; ?>

				<form method="post" action="<?php echo esc_url( $fh_post ); ?>">
					<?php Admin::form_fields( 'cutlist_save', array( 'box' => $fh_box['name'] ) ); ?>
					<table class="fh-fc-rows">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Piece', 'fishotel-misc-plugin' ); ?></th>
								<th><?php esc_html_e( 'Length', 'fishotel-misc-plugin' ); ?></th>
								<th><?php esc_html_e( 'Width', 'fishotel-misc-plugin' ); ?></th>
								<th><?php esc_html_e( 'How many', 'fishotel-misc-plugin' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php
							$fh_rows = $fh_box['pieces'];
							for ( $fh_i = 0; $fh_i < Admin::SPARE_ROWS; $fh_i++ ) {
								$fh_rows[] = null;
							}
							foreach ( $fh_rows as $fh_i => $fh_piece ) :
								?>
								<tr>
									<td><input type="text" name="pieces[<?php echo (int) $fh_i; ?>][name]" value="<?php echo esc_attr( $fh_piece ? $fh_piece['name'] : '' ); ?>" maxlength="40" /></td>
									<td><input type="text" name="pieces[<?php echo (int) $fh_i; ?>][length]" value="<?php echo esc_attr( $fh_piece ? Units::decimal( $fh_piece['length'] ) : '' ); ?>" class="fh-fc-size" inputmode="decimal" /></td>
									<td><input type="text" name="pieces[<?php echo (int) $fh_i; ?>][width]" value="<?php echo esc_attr( $fh_piece ? Units::decimal( $fh_piece['width'] ) : '' ); ?>" class="fh-fc-size" inputmode="decimal" /></td>
									<td><input type="number" name="pieces[<?php echo (int) $fh_i; ?>][count]" value="<?php echo esc_attr( $fh_piece ? (string) (int) $fh_piece['count'] : '' ); ?>" min="0" max="24" step="1" class="fh-fc-count" /></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
					<p class="fh-fc-actions">
						<button type="submit" class="button"><?php esc_html_e( 'Save this cut list', 'fishotel-misc-plugin' ); ?></button>
						<span class="fh-fc-muted"><?php esc_html_e( 'An empty row is ignored; clear a row to drop the piece.', 'fishotel-misc-plugin' ); ?></span>
					</p>
				</form>
				<?php if ( 'saved' === $fh_box['cut_list'] ) : ?>
					<form method="post" action="<?php echo esc_url( $fh_post ); ?>" onsubmit="return confirm('<?php echo esc_js( __( 'Forget your cut list for this box and use the suggestion again?', 'fishotel-misc-plugin' ) ); ?>');">
						<?php Admin::form_fields( 'cutlist_reset', array( 'box' => $fh_box['name'] ) ); ?>
						<button type="submit" class="button-link fh-fc-danger"><?php esc_html_e( 'Use the suggestion again', 'fishotel-misc-plugin' ); ?></button>
					</form>
				<?php endif; ?>
			</div>
		<?php endforeach; ?>
	</div>

	<?php if ( $orphans ) : ?>
		<h3><?php esc_html_e( 'Saved cut lists of boxes that are no longer on the list', 'fishotel-misc-plugin' ); ?></h3>
		<ul class="fh-fc-pieces">
			<?php foreach ( $orphans as $fh_entry ) : ?>
				<li>
					<form method="post" action="<?php echo esc_url( $fh_post ); ?>" class="fh-fc-inline">
						<?php Admin::form_fields( 'cutlist_reset', array( 'box' => $fh_entry['name'] ) ); ?>
						<strong><?php echo esc_html( $fh_entry['name'] ); ?></strong>
						<span class="fh-fc-muted"><?php esc_html_e( '— kept in case the box comes back under the same name', 'fishotel-misc-plugin' ); ?></span>
						<button type="submit" class="button-link fh-fc-danger"><?php esc_html_e( 'Forget', 'fishotel-misc-plugin' ); ?></button>
					</form>
				</li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>
</section>

<section class="fh-fc-card">
	<h2><?php esc_html_e( 'This section\'s own box list', 'fishotel-misc-plugin' ); ?></h2>
	<?php if ( $fh_own ) : ?>
		<p class="fh-fc-muted"><?php esc_html_e( 'In use now. Name and inside length, width, height in inches.', 'fishotel-misc-plugin' ); ?></p>
	<?php else : ?>
		<p class="fh-fc-warn">
			<?php
			/* translators: %s: where the box list comes from */
			echo esc_html( sprintf( __( 'Not in use: the boxes come from %s, so change them there. This list is only used when no other list is available.', 'fishotel-misc-plugin' ), Boxes::source_label( $context['source'] ) ) );
			?>
		</p>
	<?php endif; ?>

	<form method="post" action="<?php echo esc_url( $fh_post ); ?>">
		<?php Admin::form_fields( 'boxes_save' ); ?>
		<table class="fh-fc-rows">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Name', 'fishotel-misc-plugin' ); ?></th>
					<th><?php esc_html_e( 'Length', 'fishotel-misc-plugin' ); ?></th>
					<th><?php esc_html_e( 'Width', 'fishotel-misc-plugin' ); ?></th>
					<th><?php esc_html_e( 'Height', 'fishotel-misc-plugin' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php
				$fh_rows = $context['settings']['boxes'];
				for ( $fh_i = 0; $fh_i < Admin::SPARE_ROWS; $fh_i++ ) {
					$fh_rows[] = null;
				}
				foreach ( $fh_rows as $fh_i => $fh_box ) :
					?>
					<tr>
						<td><input type="text" name="own[<?php echo (int) $fh_i; ?>][name]" value="<?php echo esc_attr( $fh_box ? $fh_box['name'] : '' ); ?>" maxlength="40" /></td>
						<td><input type="text" name="own[<?php echo (int) $fh_i; ?>][length]" value="<?php echo esc_attr( $fh_box ? Units::decimal( $fh_box['length'] ) : '' ); ?>" class="fh-fc-size" inputmode="decimal" /></td>
						<td><input type="text" name="own[<?php echo (int) $fh_i; ?>][width]" value="<?php echo esc_attr( $fh_box ? Units::decimal( $fh_box['width'] ) : '' ); ?>" class="fh-fc-size" inputmode="decimal" /></td>
						<td><input type="text" name="own[<?php echo (int) $fh_i; ?>][height]" value="<?php echo esc_attr( $fh_box ? Units::decimal( $fh_box['height'] ) : '' ); ?>" class="fh-fc-size" inputmode="decimal" /></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<p class="fh-fc-actions">
			<button type="submit" class="button"><?php esc_html_e( 'Save the list', 'fishotel-misc-plugin' ); ?></button>
			<span class="fh-fc-muted"><?php esc_html_e( 'Clear a row to remove the box. Save to get more empty rows.', 'fishotel-misc-plugin' ); ?></span>
		</p>
	</form>
</section>
