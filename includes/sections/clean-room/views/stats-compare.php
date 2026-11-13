<?php
/**
 * Stats tab — Compare years: the weighted figures of two or more years
 * side by side, with the change between consecutive years, for the
 * totals and by supplier, origin and species group. A supplier, origin
 * or group with no order in a year shows a dash there — never a zero.
 *
 * @package FisHotel\Misc\Sections\Clean_Room
 *
 * @var array  $data         Admin_Stats::compare_data(): years, picked, defaulted, compare, exports.
 * @var string $flash        One-time notice.
 * @var bool   $tables_ok    Whether the tables exist.
 * @var bool   $can_settings Whether the Settings tab is offered.
 */

defined( 'ABSPATH' ) || exit;

use FisHotel\Misc\Sections\Clean_Room\Admin_Stats;
use FisHotel\Misc\Sections\Clean_Room\Clean_Room;
use FisHotel\Misc\Sections\Clean_Room\Stats;

$tab           = 'stats';
$fh_stats_view = 'compare';
$fh_compare    = $data['compare'];
$fh_years      = $fh_compare['years'];
$fh_labels     = Admin_Stats::figure_labels();

/**
 * One compared table: per name a row for each year, and under each year
 * but the first a row with the change from the year before.
 *
 * @param array[] $rows  Stats::compare() rows.
 * @param string  $label The first column's heading ('' = no name column: the totals).
 */
$fh_table = static function ( array $rows, $label ) use ( $fh_years, $fh_labels ) {
	if ( ! $rows ) {
		echo '<p class="fh-cr-sub">' . esc_html__( 'Nothing here.', 'fishotel-misc-plugin' ) . '</p>';
		return;
	}
	$fh_span = count( $fh_years ) * 2 - 1;
	?>
	<table class="widefat fh-cr-table fh-cr-compare">
		<thead>
			<tr>
				<?php if ( '' !== $label ) : ?>
					<th scope="col"><?php echo esc_html( $label ); ?></th>
				<?php endif; ?>
				<th scope="col"><?php esc_html_e( 'Year', 'fishotel-misc-plugin' ); ?></th>
				<?php foreach ( $fh_labels as $fh_key => $fh_heading ) : ?>
					<th scope="col" class="fh-cr-num"><?php echo esc_html( $fh_heading ); ?></th>
				<?php endforeach; ?>
			</tr>
		</thead>
		<?php foreach ( $rows as $fh_row ) : ?>
			<tbody>
				<?php foreach ( $fh_years as $fh_position => $fh_year ) : ?>
					<tr class="fh-cr-compare__year<?php echo $fh_row['present'][ $fh_year ] ? '' : ' is-missing'; ?>">
						<?php if ( '' !== $label && 0 === $fh_position ) : ?>
							<th scope="rowgroup" rowspan="<?php echo esc_attr( $fh_span ); ?>" class="fh-cr-compare__name"><?php echo esc_html( $fh_row['name'] ); ?></th>
						<?php endif; ?>
						<th scope="row">
							<?php echo esc_html( $fh_year ); ?>
							<?php if ( ! $fh_row['present'][ $fh_year ] ) : ?>
								<span class="fh-cr-sub"><?php esc_html_e( 'no order', 'fishotel-misc-plugin' ); ?></span>
							<?php endif; ?>
						</th>
						<?php foreach ( Stats::COMPARE_FIGURES as $fh_key => $fh_kind ) : ?>
							<td class="fh-cr-num"><?php echo esc_html( Admin_Stats::figure_text( $fh_row['values'][ $fh_key ][ $fh_year ], $fh_kind ) ); ?></td>
						<?php endforeach; ?>
					</tr>
					<?php if ( $fh_position > 0 ) : ?>
						<tr class="fh-cr-compare__change">
							<th scope="row"><?php echo esc_html( $fh_years[ $fh_position - 1 ] . ' → ' . $fh_year ); ?></th>
							<?php foreach ( Stats::COMPARE_FIGURES as $fh_key => $fh_kind ) : ?>
								<?php $fh_change = $fh_row['changes'][ $fh_key ][ $fh_position - 1 ]; ?>
								<td class="fh-cr-num <?php echo esc_attr( Admin_Stats::change_class( $fh_key, $fh_change ) ); ?>"><?php echo esc_html( Admin_Stats::change_text( $fh_change, $fh_kind ) ); ?></td>
							<?php endforeach; ?>
						</tr>
					<?php endif; ?>
				<?php endforeach; ?>
			</tbody>
		<?php endforeach; ?>
	</table>
	<?php
};
?>
<div class="wrap fishotel-misc-wrap fh-cr-wrap">

	<?php include Clean_Room::path() . 'views/stats-head.php'; ?>

	<div class="fh-cr-toolbar">
		<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="fh-cr-filters">
			<input type="hidden" name="page" value="<?php echo esc_attr( Clean_Room::MENU_SLUG ); ?>">
			<input type="hidden" name="tab" value="stats">
			<input type="hidden" name="view" value="compare">
			<fieldset class="fh-cr-years">
				<legend><?php esc_html_e( 'Years to compare (two or more)', 'fishotel-misc-plugin' ); ?></legend>
				<?php foreach ( $data['years'] as $fh_year ) : ?>
					<label class="fh-cr-filter fh-cr-filter--check">
						<input type="checkbox" name="years[]" value="<?php echo esc_attr( $fh_year ); ?>" <?php checked( in_array( (int) $fh_year, $data['picked'], true ) ); ?>>
						<span><?php echo esc_html( $fh_year ); ?></span>
					</label>
				<?php endforeach; ?>
			</fieldset>
			<button type="submit" class="button"><?php esc_html_e( 'Compare', 'fishotel-misc-plugin' ); ?></button>
		</form>
		<?php if ( $fh_years ) : ?>
			<div class="fh-cr-actions">
				<a class="button" href="<?php echo esc_url( $data['exports']['xlsx'] ); ?>"><?php esc_html_e( 'Export XLSX', 'fishotel-misc-plugin' ); ?></a>
				<a class="button" href="<?php echo esc_url( $data['exports']['csv'] ); ?>"><?php esc_html_e( 'Export CSV', 'fishotel-misc-plugin' ); ?></a>
			</div>
		<?php endif; ?>
	</div>

	<?php if ( $data['defaulted'] ) : ?>
		<div class="fishotel-misc-notice fishotel-misc-notice--warning"><?php esc_html_e( 'Pick at least two years that have orders. Showing the last two instead.', 'fishotel-misc-plugin' ); ?></div>
	<?php endif; ?>

	<?php if ( ! $fh_years ) : ?>
		<div class="fishotel-misc-empty"><span class="dashicons dashicons-chart-bar"></span><p><?php esc_html_e( 'No supplier orders yet: there is nothing to compare.', 'fishotel-misc-plugin' ); ?></p></div>
	<?php else : ?>

		<p class="fh-cr-legend">
			<span class="fh-cr-kind fh-cr-kind--est"><?php esc_html_e( 'Estimate', 'fishotel-misc-plugin' ); ?></span>
			<?php esc_html_e( 'Est. revenue and Est. up / down are estimates, as in the spreadsheet: survivors × the FisHotel price typed on each supplier order line. Everything else on this page is counted from the supplier orders. What customers actually paid is under Real sales.', 'fishotel-misc-plugin' ); ?>
		</p>

		<?php if ( count( $fh_years ) < 2 ) : ?>
			<div class="fishotel-misc-notice fishotel-misc-notice--warning"><?php esc_html_e( 'Only one year has supplier orders so far, so there is no change to show yet.', 'fishotel-misc-plugin' ); ?></div>
		<?php endif; ?>

		<h2 class="fh-cr-h2"><?php esc_html_e( 'Totals', 'fishotel-misc-plugin' ); ?></h2>
		<div class="fh-cr-grid-wrap"><?php $fh_table( array( $fh_compare['totals'] ), '' ); ?></div>

		<h2 class="fh-cr-h2"><?php esc_html_e( 'By supplier', 'fishotel-misc-plugin' ); ?></h2>
		<div class="fh-cr-grid-wrap"><?php $fh_table( $fh_compare['by_supplier'], __( 'Supplier', 'fishotel-misc-plugin' ) ); ?></div>

		<h2 class="fh-cr-h2"><?php esc_html_e( 'By origin', 'fishotel-misc-plugin' ); ?></h2>
		<div class="fh-cr-grid-wrap"><?php $fh_table( $fh_compare['by_origin'], __( 'Origin', 'fishotel-misc-plugin' ) ); ?></div>

		<h2 class="fh-cr-h2"><?php esc_html_e( 'By species group', 'fishotel-misc-plugin' ); ?></h2>
		<div class="fh-cr-grid-wrap"><?php $fh_table( $fh_compare['by_group'], __( 'Species group', 'fishotel-misc-plugin' ) ); ?></div>

		<p class="fh-cr-footnote">
			<?php esc_html_e( 'A year is the order\'s received date, else its order date. The figures are the weighted ones of the By year view (Σ ÷ Σ over every order of the year). The row under a year is its change from the year before: counts and money as the difference, with the percent where one is defined (not from zero, and not for up / down, which can cross zero); a rate changes in percentage points (80.0% → 83.2% is +3.2 pts). A dash means there is no figure: a supplier, origin or group with no order that year, survivors not known yet, or a rate with nothing to divide by — and no change is shown into or out of such a year.', 'fishotel-misc-plugin' ); ?>
		</p>
	<?php endif; ?>
</div>
