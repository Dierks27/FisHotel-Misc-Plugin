<?php
/**
 * Stats tab — Species: one row per fish and year over the supplier
 * orders — received, DOA, survived, landed, and the ESTIMATED revenue,
 * profit and margin — sortable by any column, with a search.
 *
 * @package FisHotel\Misc\Sections\Clean_Room
 *
 * @var array  $data         Admin_Stats::species_data(): filters, years, rows, totals, all, year_totals, exports.
 * @var string $flash        One-time notice.
 * @var bool   $tables_ok    Whether the tables exist.
 * @var bool   $can_settings Whether the Settings tab is offered.
 */

defined( 'ABSPATH' ) || exit;

use FisHotel\Misc\Sections\Clean_Room\Admin_Stats;
use FisHotel\Misc\Sections\Clean_Room\Clean_Room;
use FisHotel\Misc\Sections\Clean_Room\Costing;

$tab           = 'stats';
$fh_stats_view = 'species';
$fh_filters    = $data['filters'];
$fh_t          = $data['totals'];
$fh_year       = (int) $fh_filters['year'];

/**
 * A sortable column heading.
 *
 * @param string $key   Column.
 * @param string $label Heading.
 * @param bool   $text  Whether it is a text column (left-aligned, A to Z first).
 * @param string $title Tooltip.
 */
$fh_th = static function ( $key, $label, $text = false, $title = '' ) use ( $fh_filters ) {
	$fh_link  = Admin_Stats::sort_link( 'species', $fh_filters, $key, $text );
	$fh_arrow = 'asc' === $fh_link['dir'] ? ' ▲' : ( 'desc' === $fh_link['dir'] ? ' ▼' : '' );
	echo '<th scope="col" class="' . ( $text ? '' : 'fh-cr-num ' ) . 'fh-cr-sort' . ( '' !== $fh_link['dir'] ? ' is-sorted' : '' ) . '"' . ( '' !== $title ? ' title="' . esc_attr( $title ) . '"' : '' ) . ( '' !== $fh_link['dir'] ? ' aria-sort="' . ( 'asc' === $fh_link['dir'] ? 'ascending' : 'descending' ) . '"' : '' ) . '>';
	echo '<a href="' . esc_url( $fh_link['url'] ) . '">' . esc_html( $label . $fh_arrow ) . '</a></th>';
};
?>
<div class="wrap fishotel-misc-wrap fh-cr-wrap">

	<?php include Clean_Room::path() . 'views/stats-head.php'; ?>

	<div class="fh-cr-toolbar">
		<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="fh-cr-filters">
			<input type="hidden" name="page" value="<?php echo esc_attr( Clean_Room::MENU_SLUG ); ?>">
			<input type="hidden" name="tab" value="stats">
			<input type="hidden" name="view" value="species">
			<?php if ( '' !== $fh_filters['sort'] ) : ?>
				<input type="hidden" name="sort" value="<?php echo esc_attr( $fh_filters['sort'] ); ?>">
				<input type="hidden" name="dir" value="<?php echo esc_attr( $fh_filters['dir'] ); ?>">
			<?php endif; ?>
			<label class="fh-cr-filter">
				<span><?php esc_html_e( 'Year', 'fishotel-misc-plugin' ); ?></span>
				<select name="year" class="fh-cr-input">
					<option value="0" <?php selected( 0, $fh_year ); ?>><?php esc_html_e( 'All years', 'fishotel-misc-plugin' ); ?></option>
					<?php foreach ( $data['years'] as $fh_option ) : ?>
						<option value="<?php echo esc_attr( $fh_option ); ?>" <?php selected( (int) $fh_option, $fh_year ); ?>><?php echo esc_html( $fh_option ); ?></option>
					<?php endforeach; ?>
				</select>
			</label>
			<label class="fh-cr-filter fh-cr-filter--grow">
				<span><?php esc_html_e( 'Search (species or group)', 'fishotel-misc-plugin' ); ?></span>
				<input type="search" name="s" class="fh-cr-input" value="<?php echo esc_attr( $fh_filters['search'] ); ?>" maxlength="120">
			</label>
			<button type="submit" class="button"><?php esc_html_e( 'Filter', 'fishotel-misc-plugin' ); ?></button>
		</form>
		<div class="fh-cr-actions">
			<a class="button" href="<?php echo esc_url( $data['exports']['xlsx'] ); ?>"><?php esc_html_e( 'Export XLSX', 'fishotel-misc-plugin' ); ?></a>
			<a class="button" href="<?php echo esc_url( $data['exports']['csv'] ); ?>"><?php esc_html_e( 'Export CSV', 'fishotel-misc-plugin' ); ?></a>
		</div>
	</div>

	<p class="fh-cr-legend">
		<span class="fh-cr-kind fh-cr-kind--est"><?php esc_html_e( 'Estimate', 'fishotel-misc-plugin' ); ?></span>
		<?php esc_html_e( 'Est. revenue, Est. profit and Est. margin are estimates, as in the spreadsheet: survivors × the FisHotel price typed on the supplier order line, less the landed cost. Received, DOA, survived and landed are counted from the supplier orders. What customers actually paid for each fish is under Real sales.', 'fishotel-misc-plugin' ); ?>
	</p>

	<div class="fh-cr-summary">
		<span class="fh-cr-chip"><strong><?php echo esc_html( $fh_t['rows'] ); ?></strong> <?php echo esc_html( $data['all'] === $fh_t['rows'] ? __( 'fish', 'fishotel-misc-plugin' ) : sprintf( /* translators: %d: rows in the year */ __( 'of %d fish', 'fishotel-misc-plugin' ), $data['all'] ) ); ?></span>
		<span class="fh-cr-chip"><strong><?php echo esc_html( $fh_t['received'] ); ?></strong> <?php esc_html_e( 'received', 'fishotel-misc-plugin' ); ?></span>
		<span class="fh-cr-chip"><strong><?php echo esc_html( Costing::money( $fh_t['total'] ) ); ?></strong> <?php esc_html_e( 'landed', 'fishotel-misc-plugin' ); ?></span>
		<span class="fh-cr-chip"><strong><?php echo esc_html( Costing::money( $fh_t['revenue'] ) ); ?></strong> <?php esc_html_e( 'est. revenue', 'fishotel-misc-plugin' ); ?></span>
		<span class="fh-cr-summary__meta"><?php echo esc_html( $fh_year > 0 ? (string) $fh_year : __( 'all years', 'fishotel-misc-plugin' ) ); ?></span>
	</div>

	<?php if ( ! $data['rows'] ) : ?>
		<div class="fishotel-misc-empty"><span class="dashicons dashicons-chart-bar"></span><p><?php echo esc_html( $data['all'] > 0 ? __( 'No fish matches that search.', 'fishotel-misc-plugin' ) : __( 'No supplier order lines in this year.', 'fishotel-misc-plugin' ) ); ?></p></div>
	<?php else : ?>
		<div class="fh-cr-grid-wrap">
			<table class="widefat striped fh-cr-table">
				<thead>
					<tr>
						<?php
						$fh_th( 'name', __( 'Species', 'fishotel-misc-plugin' ), true );
						$fh_th( 'group', __( 'Group', 'fishotel-misc-plugin' ), true, __( 'The group "By year" counts these lines under: the group of the species the line is linked to. A line typed without picking the fish from the list is under no group — so a fish can show twice in a year.', 'fishotel-misc-plugin' ) );
						$fh_th( 'year', __( 'Year', 'fishotel-misc-plugin' ) );
						$fh_th( 'orders', __( 'Orders', 'fishotel-misc-plugin' ) );
						$fh_th( 'ordered', __( 'Ordered', 'fishotel-misc-plugin' ) );
						$fh_th( 'received', __( 'Received', 'fishotel-misc-plugin' ) );
						$fh_th( 'doa', __( 'DOA', 'fishotel-misc-plugin' ) );
						$fh_th( 'doa_rate', __( 'DOA rate', 'fishotel-misc-plugin' ) );
						$fh_th( 'survived', __( 'Survived', 'fishotel-misc-plugin' ) );
						$fh_th( 'survival_rate', __( 'Survival', 'fishotel-misc-plugin' ) );
						$fh_th( 'total', __( 'Landed', 'fishotel-misc-plugin' ) );
						$fh_th( 'landed_per_fish', __( 'Landed / fish', 'fishotel-misc-plugin' ), false, __( 'The landed total ÷ the fish received.', 'fishotel-misc-plugin' ) );
						$fh_th( 'revenue', __( 'Est. revenue', 'fishotel-misc-plugin' ), false, __( 'Estimate: survivors × the FisHotel price typed on the line.', 'fishotel-misc-plugin' ) );
						$fh_th( 'profit', __( 'Est. profit', 'fishotel-misc-plugin' ), false, __( 'Estimate: est. revenue − landed.', 'fishotel-misc-plugin' ) );
						$fh_th( 'margin', __( 'Est. margin', 'fishotel-misc-plugin' ), false, __( 'Estimate: est. profit ÷ landed.', 'fishotel-misc-plugin' ) );
						?>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $data['rows'] as $fh_r ) : ?>
						<tr>
							<td>
								<strong><?php echo esc_html( $fh_r['name'] ); ?></strong>
								<?php if ( (int) $fh_r['unknown_lines'] > 0 && $fh_r['survived_known'] ) : ?>
									<span class="fh-cr-sub"><?php echo esc_html( sprintf( /* translators: %d: order lines */ _n( '%d line without a survivor count: not in the estimate', '%d lines without a survivor count: not in the estimate', (int) $fh_r['unknown_lines'], 'fishotel-misc-plugin' ), (int) $fh_r['unknown_lines'] ) ); ?></span>
								<?php endif; ?>
							</td>
							<td><?php echo esc_html( '' !== $fh_r['group'] ? $fh_r['group'] : '—' ); ?></td>
							<td class="fh-cr-num"><?php echo esc_html( null !== $fh_r['year'] ? $fh_r['year'] : '—' ); ?></td>
							<td class="fh-cr-num"><?php echo esc_html( $fh_r['orders'] ); ?></td>
							<td class="fh-cr-num"><?php echo esc_html( $fh_r['ordered'] ); ?></td>
							<td class="fh-cr-num"><?php echo esc_html( $fh_r['received'] ); ?></td>
							<td class="fh-cr-num"><?php echo esc_html( $fh_r['doa'] ); ?></td>
							<td class="fh-cr-num"><?php echo esc_html( Costing::pct( $fh_r['doa_rate'] ) ); ?></td>
							<td class="fh-cr-num"><?php echo esc_html( $fh_r['survived_known'] ? $fh_r['survived'] : '—' ); ?></td>
							<td class="fh-cr-num"><?php echo esc_html( Costing::pct( $fh_r['survival_rate'] ) ); ?></td>
							<td class="fh-cr-num"><?php echo esc_html( Costing::money( $fh_r['total'] ) ); ?></td>
							<td class="fh-cr-num"><?php echo esc_html( Costing::money( $fh_r['landed_per_fish'] ) ); ?></td>
							<?php if ( $fh_r['survived_known'] ) : ?>
								<td class="fh-cr-num fh-cr-est"><?php echo esc_html( Costing::money( $fh_r['revenue'] ) ); ?></td>
								<td class="fh-cr-num fh-cr-est <?php echo $fh_r['profit'] >= 0 ? 'fh-cr-up' : 'fh-cr-down'; ?>"><?php echo esc_html( ( $fh_r['profit'] > 0 ? '+' : '' ) . Costing::money( $fh_r['profit'] ) ); ?></td>
								<td class="fh-cr-num fh-cr-est"><?php echo esc_html( Costing::pct( $fh_r['margin'] ) ); ?></td>
							<?php else : ?>
								<td class="fh-cr-num fh-cr-est" colspan="3" title="<?php esc_attr_e( 'No survivor count on any line of this fish yet, so there is nothing to estimate from.', 'fishotel-misc-plugin' ); ?>"><?php esc_html_e( 'survivors not known yet', 'fishotel-misc-plugin' ); ?></td>
							<?php endif; ?>
						</tr>
					<?php endforeach; ?>
				</tbody>
				<tfoot>
					<tr>
						<th colspan="4"><?php esc_html_e( 'Sum of the rows shown', 'fishotel-misc-plugin' ); ?></th>
						<th class="fh-cr-num"><?php echo esc_html( $fh_t['ordered'] ); ?></th>
						<th class="fh-cr-num"><?php echo esc_html( $fh_t['received'] ); ?></th>
						<th class="fh-cr-num"><?php echo esc_html( $fh_t['doa'] ); ?></th>
						<th class="fh-cr-num"><?php echo esc_html( Costing::pct( $fh_t['received'] > 0 ? $fh_t['doa'] / $fh_t['received'] : null ) ); ?></th>
						<th class="fh-cr-num"><?php echo esc_html( $fh_t['survived_known'] ? $fh_t['survived'] : '—' ); ?></th>
						<th class="fh-cr-num"></th>
						<th class="fh-cr-num"><?php echo esc_html( Costing::money( $fh_t['total'] ) ); ?></th>
						<th class="fh-cr-num"><?php echo esc_html( Costing::money( $fh_t['received'] > 0 ? $fh_t['total'] / $fh_t['received'] : null ) ); ?></th>
						<th class="fh-cr-num fh-cr-est"><?php echo esc_html( Costing::money( $fh_t['revenue'] ) ); ?></th>
						<th class="fh-cr-num fh-cr-est"><?php echo esc_html( ( $fh_t['profit'] > 0 ? '+' : '' ) . Costing::money( $fh_t['profit'] ) ); ?></th>
						<th class="fh-cr-num fh-cr-est"><?php echo esc_html( Costing::pct( $fh_t['margin'] ) ); ?></th>
					</tr>
				</tfoot>
			</table>
		</div>
	<?php endif; ?>

	<p class="fh-cr-footnote">
		<?php esc_html_e( 'A fish is its row of the species list — a line typed with the exact name of a row counts for that row — else its name as typed; the fish nobody has identified yet (??) are one row, so the rows of a year add up to that year\'s totals on the By year view. Landed is the costing\'s landed total of the fish\'s lines (its price with its share of the tariffs, the flight and the import fees). Click a heading to sort by it; click again to turn the order round.', 'fishotel-misc-plugin' ); ?>
	</p>
</div>
