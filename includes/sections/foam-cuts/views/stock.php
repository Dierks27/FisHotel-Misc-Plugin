<?php
/**
 * Stock tab — whole sheets (a count, with its entries) and the leftovers
 * (label, size), each kept by hand when reality differs.
 *
 * @package FisHotel\Misc\Sections\Foam_Cuts
 *
 * @var int     $sheets    The sheet entries added up (may be below 0).
 * @var array[] $entries   The latest sheet entries.
 * @var array[] $leftovers The leftovers in stock.
 * @var array   $context   Planner::context().
 * @var bool    $installed Whether the tables are there.
 */

defined( 'ABSPATH' ) || exit;

use FisHotel\Misc\Sections\Foam_Cuts\Admin;
use FisHotel\Misc\Sections\Foam_Cuts\Units;

$fh_post  = admin_url( 'admin-post.php' );
$fh_sheet = $context['settings'];
$fh_area  = 0;
foreach ( $leftovers as $fh_left ) {
	$fh_area += $fh_left['length'] * $fh_left['width'];
}
?>
<div class="fh-fc-columns">

	<section class="fh-fc-card">
		<h2><?php esc_html_e( 'Whole sheets', 'fishotel-misc-plugin' ); ?></h2>
		<p class="fh-fc-big">
			<?php echo (int) Admin::sheets_shown( $sheets ); ?>
			<span class="fh-fc-muted">
				<?php
				/* translators: 1: sheet size, 2: thickness */
				echo esc_html( sprintf( __( 'sheets of %1$s in, %2$s in thick', 'fishotel-misc-plugin' ), Units::size( $fh_sheet['sheet_length'], $fh_sheet['sheet_width'] ), Units::format( $fh_sheet['thickness'] ) ) );
				?>
			</span>
		</p>
		<?php if ( $sheets < 0 ) : ?>
			<div class="fh-fc-notice is-warn">
				<p>
					<?php
					/* translators: %d: sheets */
					echo esc_html( sprintf( _n( 'Plans have taken %d sheet more than was ever logged here. Log the sheets you bought, or correct the count to what is on the rack.', 'Plans have taken %d sheets more than were ever logged here. Log the sheets you bought, or correct the count to what is on the rack.', -$sheets, 'fishotel-misc-plugin' ), -$sheets ) );
					?>
				</p>
			</div>
		<?php endif; ?>

		<form method="post" action="<?php echo esc_url( $fh_post ); ?>" class="fh-fc-inline">
			<?php Admin::form_fields( 'stock_buy' ); ?>
			<label for="fh-fc-buy"><?php esc_html_e( 'Bought', 'fishotel-misc-plugin' ); ?></label>
			<input type="number" id="fh-fc-buy" name="sheets" min="1" max="10000" step="1" class="fh-fc-count" required />
			<label for="fh-fc-buy-note"><?php esc_html_e( 'sheets', 'fishotel-misc-plugin' ); ?></label>
			<input type="text" id="fh-fc-buy-note" name="note" maxlength="190" placeholder="<?php esc_attr_e( 'note (optional)', 'fishotel-misc-plugin' ); ?>" />
			<button type="submit" class="button button-primary"<?php echo $installed ? '' : ' disabled'; ?>><?php esc_html_e( 'Add', 'fishotel-misc-plugin' ); ?></button>
		</form>

		<form method="post" action="<?php echo esc_url( $fh_post ); ?>" class="fh-fc-inline">
			<?php Admin::form_fields( 'stock_count' ); ?>
			<label for="fh-fc-recount"><?php esc_html_e( 'I counted', 'fishotel-misc-plugin' ); ?></label>
			<input type="number" id="fh-fc-recount" name="sheets" min="0" max="10000" step="1" class="fh-fc-count" required />
			<label for="fh-fc-recount-note"><?php esc_html_e( 'sheets on the rack', 'fishotel-misc-plugin' ); ?></label>
			<input type="text" id="fh-fc-recount-note" name="note" maxlength="190" placeholder="<?php esc_attr_e( 'note (optional)', 'fishotel-misc-plugin' ); ?>" />
			<button type="submit" class="button"<?php echo $installed ? '' : ' disabled'; ?>><?php esc_html_e( 'Correct the count', 'fishotel-misc-plugin' ); ?></button>
		</form>

		<?php if ( $entries ) : ?>
			<table class="widefat striped fh-fc-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'When', 'fishotel-misc-plugin' ); ?></th>
						<th><?php esc_html_e( 'What', 'fishotel-misc-plugin' ); ?></th>
						<th class="fh-fc-num"><?php esc_html_e( 'Sheets', 'fishotel-misc-plugin' ); ?></th>
						<th><?php esc_html_e( 'Note', 'fishotel-misc-plugin' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $entries as $fh_entry ) : ?>
						<tr>
							<td><?php echo esc_html( Admin::when( $fh_entry['created_at'] ) ); ?></td>
							<td>
								<?php if ( $fh_entry['plan_id'] > 0 ) : ?>
									<a class="fh-fc-link" href="<?php echo esc_url( Admin::page_url( 'plan', array( 'plan' => (int) $fh_entry['plan_id'] ) ) ); ?>"><?php echo esc_html( Admin::entry_text( $fh_entry ) ); ?></a>
								<?php else : ?>
									<?php echo esc_html( Admin::entry_text( $fh_entry ) ); ?>
								<?php endif; ?>
							</td>
							<td class="fh-fc-num"><?php echo esc_html( ( $fh_entry['sheets'] > 0 ? '+' : '' ) . (int) $fh_entry['sheets'] ); ?></td>
							<td><?php echo esc_html( $fh_entry['plan_id'] > 0 ? '' : $fh_entry['note'] ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
	</section>

	<section class="fh-fc-card">
		<h2>
			<?php esc_html_e( 'Leftovers', 'fishotel-misc-plugin' ); ?>
			<span class="fh-fc-muted">
				<?php
				/* translators: 1: leftovers, 2: square feet */
				echo esc_html( sprintf( _n( '%1$d piece, %2$s sq ft', '%1$d pieces, %2$s sq ft', count( $leftovers ), 'fishotel-misc-plugin' ), count( $leftovers ), number_format_i18n( Units::square_feet( $fh_area ), 1 ) ) );
				?>
			</span>
		</h2>
		<p class="fh-fc-muted"><?php esc_html_e( 'Each leftover carries the label written on it. A plan uses the leftover that wastes least before it takes a whole sheet. When a piece on the shelf is not what this list says — trimmed, broken, thrown away, found — correct it here.', 'fishotel-misc-plugin' ); ?></p>

		<?php if ( $leftovers ) : ?>
			<table class="widefat striped fh-fc-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Label', 'fishotel-misc-plugin' ); ?></th>
						<th><?php esc_html_e( 'Length × width (in)', 'fishotel-misc-plugin' ); ?></th>
						<th><?php esc_html_e( 'From', 'fishotel-misc-plugin' ); ?></th>
						<th></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $leftovers as $fh_left ) : ?>
						<tr>
							<td><strong><?php echo esc_html( $fh_left['label'] ); ?></strong></td>
							<td>
								<form method="post" action="<?php echo esc_url( $fh_post ); ?>" class="fh-fc-inline">
									<?php Admin::form_fields( 'leftover_resize', array( 'leftover' => (int) $fh_left['id'] ) ); ?>
									<input type="text" name="length" value="<?php echo esc_attr( Units::decimal( $fh_left['length'] ) ); ?>" class="fh-fc-size" inputmode="decimal" aria-label="<?php esc_attr_e( 'Length', 'fishotel-misc-plugin' ); ?>" />
									×
									<input type="text" name="width" value="<?php echo esc_attr( Units::decimal( $fh_left['width'] ) ); ?>" class="fh-fc-size" inputmode="decimal" aria-label="<?php esc_attr_e( 'Width', 'fishotel-misc-plugin' ); ?>" />
									<button type="submit" class="button button-small"><?php esc_html_e( 'Resize', 'fishotel-misc-plugin' ); ?></button>
								</form>
							</td>
							<td>
								<?php if ( $fh_left['plan_id'] > 0 ) : ?>
									<a class="fh-fc-link" href="<?php echo esc_url( Admin::page_url( 'plan', array( 'plan' => (int) $fh_left['plan_id'] ) ) ); ?>">
										<?php
										/* translators: %d: plan number */
										echo esc_html( sprintf( __( 'plan %d', 'fishotel-misc-plugin' ), (int) $fh_left['plan_id'] ) );
										?>
									</a>
									<span class="fh-fc-muted"><?php echo esc_html( $fh_left['note'] ); ?></span>
								<?php else : ?>
									<span class="fh-fc-muted"><?php echo esc_html( '' !== $fh_left['note'] ? $fh_left['note'] : __( 'added by hand', 'fishotel-misc-plugin' ) ); ?></span>
								<?php endif; ?>
							</td>
							<td>
								<form method="post" action="<?php echo esc_url( $fh_post ); ?>" onsubmit="return confirm('<?php echo esc_js( __( 'Remove this leftover from the stock?', 'fishotel-misc-plugin' ) ); ?>');">
									<?php Admin::form_fields( 'leftover_remove', array( 'leftover' => (int) $fh_left['id'] ) ); ?>
									<button type="submit" class="button-link fh-fc-danger"><?php esc_html_e( 'Remove', 'fishotel-misc-plugin' ); ?></button>
								</form>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php else : ?>
			<p class="fh-fc-muted"><?php esc_html_e( 'No leftovers in stock.', 'fishotel-misc-plugin' ); ?></p>
		<?php endif; ?>

		<h3><?php esc_html_e( 'Add a leftover', 'fishotel-misc-plugin' ); ?></h3>
		<form method="post" action="<?php echo esc_url( $fh_post ); ?>" class="fh-fc-inline">
			<?php Admin::form_fields( 'leftover_add' ); ?>
			<input type="text" name="length" class="fh-fc-size" inputmode="decimal" placeholder="<?php esc_attr_e( 'length', 'fishotel-misc-plugin' ); ?>" aria-label="<?php esc_attr_e( 'Length in inches', 'fishotel-misc-plugin' ); ?>" required />
			×
			<input type="text" name="width" class="fh-fc-size" inputmode="decimal" placeholder="<?php esc_attr_e( 'width', 'fishotel-misc-plugin' ); ?>" aria-label="<?php esc_attr_e( 'Width in inches', 'fishotel-misc-plugin' ); ?>" required />
			<?php esc_html_e( 'in', 'fishotel-misc-plugin' ); ?>
			<input type="text" name="note" maxlength="190" placeholder="<?php esc_attr_e( 'note (optional)', 'fishotel-misc-plugin' ); ?>" />
			<button type="submit" class="button"<?php echo $installed ? '' : ' disabled'; ?>><?php esc_html_e( 'Add — and tell me its label', 'fishotel-misc-plugin' ); ?></button>
		</form>
		<p class="fh-fc-muted"><?php esc_html_e( 'Sizes in inches: 11.75 or 11 3/4 both work.', 'fishotel-misc-plugin' ); ?></p>
	</section>

</div>
