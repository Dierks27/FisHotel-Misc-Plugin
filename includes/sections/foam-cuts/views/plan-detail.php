<?php
/**
 * The plan that is open on the Plan tab: what it is for, whether the
 * stock still matches, the buttons (Print, Mark as cut, Undo, Delete),
 * every source with its drawing, cuts, pieces and new leftovers, and the
 * pieces per box.
 *
 * @package FisHotel\Misc\Sections\Foam_Cuts
 *
 * @var array    $plan  Plan row (with data).
 * @var array    $state Stock::state(): stale, undo_blockers.
 * @var string[] $twins The other plans of the same ship day, in words.
 */

defined( 'ABSPATH' ) || exit;

use FisHotel\Misc\Sections\Foam_Cuts\Admin;
use FisHotel\Misc\Sections\Foam_Cuts\Svg;

$fh_data    = isset( $plan['data'] ) && is_array( $plan['data'] ) ? $plan['data'] : array();
$fh_sources = isset( $fh_data['sources'] ) ? (array) $fh_data['sources'] : array();
$fh_totals  = isset( $fh_data['totals'] ) ? (array) $fh_data['totals'] : array();
$fh_is_cut  = 'cut' === $plan['status'];
$fh_post    = admin_url( 'admin-post.php' );
?>
<section class="fh-fc-card fh-fc-plan" id="fh-fc-plan">
	<div class="fh-fc-planhead">
		<h2>
			<?php
			/* translators: %d: plan number */
			echo esc_html( sprintf( __( 'Plan %d', 'fishotel-misc-plugin' ), (int) $plan['id'] ) );
			?>
			<span class="fh-fc-plantitle"><?php echo esc_html( $plan['title'] ); ?></span>
			<?php if ( $fh_is_cut ) : ?>
				<span class="fh-fc-chip is-ok">
					<?php
					/* translators: %s: date and time */
					echo esc_html( sprintf( __( 'marked as cut %s', 'fishotel-misc-plugin' ), Admin::when( $plan['cut_at'] ) ) );
					?>
				</span>
			<?php else : ?>
				<span class="fh-fc-chip"><?php esc_html_e( 'not cut yet', 'fishotel-misc-plugin' ); ?></span>
			<?php endif; ?>
		</h2>

		<div class="fh-fc-buttons">
			<a class="button button-primary" href="<?php echo esc_url( Admin::print_link( (int) $plan['id'] ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Print the cut sheet', 'fishotel-misc-plugin' ); ?></a>

			<?php if ( ! $fh_is_cut ) : ?>
				<form method="post" action="<?php echo esc_url( $fh_post ); ?>" onsubmit="return confirm('<?php echo esc_js( __( 'Mark this plan as cut? The leftovers it used go out of the stock, its sheets are taken and its new leftovers are added.', 'fishotel-misc-plugin' ) ); ?>');">
					<?php Admin::form_fields( 'cut', array( 'plan' => (int) $plan['id'] ) ); ?>
					<button type="submit" class="button fh-fc-cut"<?php echo $state['stale'] || ! $fh_sources ? ' disabled' : ''; ?>><?php esc_html_e( 'Mark as cut', 'fishotel-misc-plugin' ); ?></button>
				</form>
				<form method="post" action="<?php echo esc_url( $fh_post ); ?>" onsubmit="return confirm('<?php echo esc_js( __( 'Delete this plan? The stock is not touched.', 'fishotel-misc-plugin' ) ); ?>');">
					<?php Admin::form_fields( 'delete', array( 'plan' => (int) $plan['id'] ) ); ?>
					<button type="submit" class="button-link fh-fc-danger"><?php esc_html_e( 'Delete', 'fishotel-misc-plugin' ); ?></button>
				</form>
			<?php else : ?>
				<form method="post" action="<?php echo esc_url( $fh_post ); ?>" onsubmit="return confirm('<?php echo esc_js( __( 'Undo "Mark as cut"? The stock goes back to what it was before this plan.', 'fishotel-misc-plugin' ) ); ?>');">
					<?php Admin::form_fields( 'undo', array( 'plan' => (int) $plan['id'] ) ); ?>
					<button type="submit" class="button"<?php echo $state['undo_blockers'] ? ' disabled' : ''; ?>><?php esc_html_e( 'Undo', 'fishotel-misc-plugin' ); ?></button>
				</form>
			<?php endif; ?>
		</div>
	</div>

	<?php if ( $state['stale'] ) : ?>
		<div class="fh-fc-notice is-error">
			<p><strong><?php esc_html_e( 'The stock has changed since this plan was made, so it can no longer be cut as printed:', 'fishotel-misc-plugin' ); ?></strong></p>
			<?php foreach ( $state['stale'] as $fh_line ) : ?>
				<p><?php echo esc_html( $fh_line ); ?></p>
			<?php endforeach; ?>
			<p><?php esc_html_e( 'Delete it and make the plan again.', 'fishotel-misc-plugin' ); ?></p>
		</div>
	<?php endif; ?>

	<?php if ( ! $fh_is_cut && ! empty( $twins ) ) : ?>
		<div class="fh-fc-notice is-warn">
			<?php foreach ( $twins as $fh_line ) : ?>
				<p><?php echo esc_html( $fh_line ); ?></p>
			<?php endforeach; ?>
		</div>
	<?php endif; ?>

	<?php if ( $fh_is_cut && $state['undo_blockers'] ) : ?>
		<div class="fh-fc-notice is-warn">
			<p><strong><?php esc_html_e( 'Undo is no longer possible — the stock this plan touched has changed since:', 'fishotel-misc-plugin' ); ?></strong></p>
			<?php foreach ( $state['undo_blockers'] as $fh_line ) : ?>
				<p><?php echo esc_html( $fh_line ); ?></p>
			<?php endforeach; ?>
		</div>
	<?php endif; ?>

	<?php foreach ( isset( $fh_data['warnings'] ) ? (array) $fh_data['warnings'] : array() as $fh_line ) : ?>
		<div class="fh-fc-notice is-warn"><p><?php echo esc_html( $fh_line ); ?></p></div>
	<?php endforeach; ?>

	<?php if ( ! empty( $fh_data['unplaced'] ) ) : ?>
		<div class="fh-fc-notice is-error">
			<p><strong><?php esc_html_e( 'Not on this cut sheet:', 'fishotel-misc-plugin' ); ?></strong></p>
			<?php foreach ( $fh_data['unplaced'] as $fh_piece ) : ?>
				<p><?php echo esc_html( (int) $fh_piece['count'] . ' × ' . $fh_piece['text'] ); ?></p>
			<?php endforeach; ?>
		</div>
	<?php endif; ?>

	<?php if ( $fh_totals ) : ?>
		<p class="fh-fc-facts">
			<span class="fh-fc-fact">
				<?php
				/* translators: 1: pieces, 2: boxes */
				echo esc_html( sprintf( __( '%1$d pieces for %2$d boxes', 'fishotel-misc-plugin' ), (int) $fh_totals['placed'], (int) $fh_totals['boxes'] ) );
				?>
			</span>
			<span class="fh-fc-fact">
				<?php
				/* translators: %d: whole sheets */
				echo esc_html( sprintf( _n( '%d new sheet', '%d new sheets', (int) $fh_totals['sheets'], 'fishotel-misc-plugin' ), (int) $fh_totals['sheets'] ) );
				?>
			</span>
			<span class="fh-fc-fact">
				<?php
				/* translators: %d: leftovers */
				echo esc_html( sprintf( _n( '%d leftover used up', '%d leftovers used up', (int) $fh_totals['leftovers_used'], 'fishotel-misc-plugin' ), (int) $fh_totals['leftovers_used'] ) );
				?>
			</span>
			<span class="fh-fc-fact">
				<?php
				/* translators: %d: leftovers */
				echo esc_html( sprintf( _n( '%d new leftover', '%d new leftovers', (int) $fh_totals['leftovers_new'], 'fishotel-misc-plugin' ), (int) $fh_totals['leftovers_new'] ) );
				?>
			</span>
			<span class="fh-fc-fact">
				<?php
				/* translators: %d: cuts */
				echo esc_html( sprintf( _n( '%d cut', '%d cuts', (int) $fh_totals['cuts'], 'fishotel-misc-plugin' ), (int) $fh_totals['cuts'] ) );
				?>
			</span>
			<span class="fh-fc-fact<?php echo (float) $fh_totals['waste_pct'] > 10 ? ' is-warn' : ''; ?>">
				<?php
				/* translators: %s: percent */
				echo esc_html( sprintf( __( 'waste %s%%', 'fishotel-misc-plugin' ), number_format_i18n( (float) $fh_totals['waste_pct'], 1 ) ) );
				?>
			</span>
		</p>
	<?php endif; ?>

	<?php foreach ( $fh_sources as $fh_source ) : ?>
		<div class="fh-fc-source">
			<h3>
				<?php echo esc_html( $fh_source['label'] ); ?>
				<span class="fh-fc-muted">
					<?php
					echo esc_html(
						sprintf(
							/* translators: 1: size, 2: pieces, 3: cuts, 4: waste percent */
							__( '%1$s in · %2$d pieces · %3$d cuts · waste %4$s%%', 'fishotel-misc-plugin' ),
							Svg::size( $fh_source['length'], $fh_source['width'] ),
							count( $fh_source['pieces'] ),
							count( $fh_source['cuts'] ),
							number_format_i18n( (float) $fh_source['waste_pct'], 1 )
						)
					);
					?>
				</span>
			</h3>
			<div class="fh-fc-paper">
				<?php echo Svg::source( $fh_source ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built by Svg::source(): numbers, and texts escaped for XML. ?>
			</div>
			<div class="fh-fc-lists">
				<div>
					<h4><?php esc_html_e( 'Cuts, in this order', 'fishotel-misc-plugin' ); ?></h4>
					<?php if ( $fh_source['cuts'] ) : ?>
						<ol class="fh-fc-cuts">
							<?php foreach ( $fh_source['cuts'] as $fh_cut ) : ?>
								<li><?php echo esc_html( preg_replace( '/^[^:]+:\s*/u', '', (string) $fh_cut['text'] ) ); ?></li>
							<?php endforeach; ?>
						</ol>
					<?php else : ?>
						<p class="fh-fc-muted"><?php esc_html_e( 'No cut: it is used whole.', 'fishotel-misc-plugin' ); ?></p>
					<?php endif; ?>
				</div>
				<div>
					<h4><?php esc_html_e( 'Pieces', 'fishotel-misc-plugin' ); ?></h4>
					<ul class="fh-fc-pieces">
						<?php foreach ( Admin::group_pieces( $fh_source['pieces'] ) as $fh_group ) : ?>
							<li><?php echo esc_html( $fh_group['count'] . ' × ' . $fh_group['box'] . ' · ' . $fh_group['name'] . ' — ' . $fh_group['size'] . ' in' ); ?></li>
						<?php endforeach; ?>
					</ul>
					<h4><?php esc_html_e( 'New leftovers — write the label on each', 'fishotel-misc-plugin' ); ?></h4>
					<?php if ( $fh_source['leftovers'] ) : ?>
						<ul class="fh-fc-pieces">
							<?php foreach ( $fh_source['leftovers'] as $fh_left ) : ?>
								<li><strong><?php echo esc_html( $fh_left['label'] ); ?></strong> — <?php echo esc_html( Svg::size( $fh_left['w'], $fh_left['h'] ) . ' in' ); ?></li>
							<?php endforeach; ?>
						</ul>
					<?php else : ?>
						<p class="fh-fc-muted"><?php esc_html_e( 'None.', 'fishotel-misc-plugin' ); ?></p>
					<?php endif; ?>
				</div>
			</div>
		</div>
	<?php endforeach; ?>

	<?php if ( ! empty( $fh_data['summary'] ) ) : ?>
		<div class="fh-fc-source">
			<h3><?php esc_html_e( 'The pieces per box', 'fishotel-misc-plugin' ); ?></h3>
			<table class="widefat striped fh-fc-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Box', 'fishotel-misc-plugin' ); ?></th>
						<th><?php esc_html_e( 'Piece', 'fishotel-misc-plugin' ); ?></th>
						<th><?php esc_html_e( 'Size (in)', 'fishotel-misc-plugin' ); ?></th>
						<th class="fh-fc-num"><?php esc_html_e( 'Per box', 'fishotel-misc-plugin' ); ?></th>
						<th class="fh-fc-num"><?php esc_html_e( 'In all', 'fishotel-misc-plugin' ); ?></th>
						<th><?php esc_html_e( 'Cut from', 'fishotel-misc-plugin' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $fh_data['summary'] as $fh_box ) : ?>
						<?php foreach ( $fh_box['pieces'] as $fh_i => $fh_piece ) : ?>
							<tr>
								<td><?php echo 0 === $fh_i ? '<strong>' . esc_html( (int) $fh_box['count'] . ' × ' . $fh_box['box'] ) . '</strong>' : ''; ?></td>
								<td><?php echo esc_html( $fh_piece['name'] ); ?></td>
								<td><?php echo esc_html( Svg::size( $fh_piece['length'], $fh_piece['width'] ) ); ?></td>
								<td class="fh-fc-num"><?php echo (int) $fh_piece['per_box']; ?></td>
								<td class="fh-fc-num">
									<?php
									echo (int) $fh_piece['total'];
									if ( (int) $fh_piece['placed'] < (int) $fh_piece['total'] ) {
										/* translators: %d: pieces */
										echo ' <span class="fh-fc-chip is-warn">' . esc_html( sprintf( __( '%d not placed', 'fishotel-misc-plugin' ), (int) $fh_piece['total'] - (int) $fh_piece['placed'] ) ) . '</span>';
									}
									?>
								</td>
								<td>
									<?php
									$fh_from = array();
									foreach ( (array) $fh_piece['from'] as $fh_label => $fh_count ) {
										$fh_from[] = $fh_label . ' × ' . (int) $fh_count;
									}
									echo esc_html( implode( ', ', $fh_from ) );
									?>
								</td>
							</tr>
						<?php endforeach; ?>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	<?php endif; ?>
</section>
