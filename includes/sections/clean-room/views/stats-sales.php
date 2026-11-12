<?php
/**
 * Stats tab — Real sales: what customers ACTUALLY paid per fish in a
 * year, from WooCommerce's paid orders (net of refunds), beside the
 * estimate and the landed cost of the supplier orders of the same year —
 * and everything that could not be matched to a fish, with its units and
 * its money, so the totals always add up.
 *
 * @package FisHotel\Misc\Sections\Clean_Room
 *
 * @var array  $data         Admin_Stats::sales_data(): filters, available, years, statuses, labels, ledger,
 *                           match, rows, all, totals, invoices, exports.
 * @var string $flash        One-time notice.
 * @var bool   $tables_ok    Whether the tables exist.
 * @var bool   $can_settings Whether the Settings tab is offered.
 */

defined( 'ABSPATH' ) || exit;

use FisHotel\Misc\Sections\Clean_Room\Admin;
use FisHotel\Misc\Sections\Clean_Room\Admin_Stats;
use FisHotel\Misc\Sections\Clean_Room\Clean_Room;
use FisHotel\Misc\Sections\Clean_Room\Costing;
use FisHotel\Misc\Sections\Clean_Room\Stats_Sales;

$tab           = 'stats';
$fh_stats_view = 'sales';
$fh_filters    = $data['filters'];
$fh_year       = (int) $fh_filters['year'];
$fh_ledger     = $data['ledger'];
$fh_match      = $data['match'];
$fh_m          = $fh_match['totals'];
$fh_sum        = $data['totals'];
$fh_kinds      = Admin_Stats::unmatched_labels();

/**
 * A sortable column heading.
 *
 * @param string $key   Column.
 * @param string $label Heading.
 * @param string $kind  '' | actual | est — which kind of number the column holds.
 * @param bool   $text  Whether it is a text column.
 * @param string $title Tooltip.
 */
$fh_th = static function ( $key, $label, $kind = '', $text = false, $title = '' ) use ( $fh_filters ) {
	$fh_link  = Admin_Stats::sort_link( 'sales', $fh_filters, $key, $text );
	$fh_arrow = 'asc' === $fh_link['dir'] ? ' ▲' : ( 'desc' === $fh_link['dir'] ? ' ▼' : '' );
	echo '<th scope="col" class="' . ( $text ? '' : 'fh-cr-num ' ) . 'fh-cr-sort' . ( '' !== $kind ? ' fh-cr-' . esc_attr( $kind ) : '' ) . ( '' !== $fh_link['dir'] ? ' is-sorted' : '' ) . '"' . ( '' !== $title ? ' title="' . esc_attr( $title ) . '"' : '' ) . ( '' !== $fh_link['dir'] ? ' aria-sort="' . ( 'asc' === $fh_link['dir'] ? 'ascending' : 'descending' ) . '"' : '' ) . '>';
	echo '<a href="' . esc_url( $fh_link['url'] ) . '">' . esc_html( $label . $fh_arrow ) . '</a></th>';
};
?>
<div class="wrap fishotel-misc-wrap fh-cr-wrap">

	<?php include Clean_Room::path() . 'views/stats-head.php'; ?>

	<div class="fh-cr-toolbar">
		<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="fh-cr-filters">
			<input type="hidden" name="page" value="<?php echo esc_attr( Clean_Room::MENU_SLUG ); ?>">
			<input type="hidden" name="tab" value="stats">
			<input type="hidden" name="view" value="sales">
			<?php if ( '' !== $fh_filters['sort'] ) : ?>
				<input type="hidden" name="sort" value="<?php echo esc_attr( $fh_filters['sort'] ); ?>">
				<input type="hidden" name="dir" value="<?php echo esc_attr( $fh_filters['dir'] ); ?>">
			<?php endif; ?>
			<label class="fh-cr-filter">
				<span><?php esc_html_e( 'Year', 'fishotel-misc-plugin' ); ?></span>
				<select name="year" class="fh-cr-input">
					<?php foreach ( $data['years'] as $fh_option ) : ?>
						<option value="<?php echo esc_attr( $fh_option ); ?>" <?php selected( (int) $fh_option, $fh_year ); ?>><?php echo esc_html( $fh_option ); ?></option>
					<?php endforeach; ?>
				</select>
			</label>
			<label class="fh-cr-filter fh-cr-filter--grow">
				<span><?php esc_html_e( 'Search (species or group)', 'fishotel-misc-plugin' ); ?></span>
				<input type="search" name="s" class="fh-cr-input" value="<?php echo esc_attr( $fh_filters['search'] ); ?>" maxlength="120">
			</label>
			<button type="submit" class="button"><?php esc_html_e( 'Show', 'fishotel-misc-plugin' ); ?></button>
		</form>
		<div class="fh-cr-actions">
			<?php if ( $data['available'] ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="fh-cr-refresh">
					<input type="hidden" name="action" value="<?php echo esc_attr( Admin_Stats::REFRESH_ACTION ); ?>">
					<input type="hidden" name="year" value="<?php echo esc_attr( $fh_year ); ?>">
					<?php wp_nonce_field( Admin_Stats::REFRESH_ACTION ); ?>
					<button type="submit" class="button" title="<?php esc_attr_e( 'Read the sales from WooCommerce again now.', 'fishotel-misc-plugin' ); ?>"><?php esc_html_e( 'Refresh', 'fishotel-misc-plugin' ); ?></button>
				</form>
			<?php endif; ?>
			<a class="button" href="<?php echo esc_url( $data['exports']['xlsx'] ); ?>"><?php esc_html_e( 'Export XLSX', 'fishotel-misc-plugin' ); ?></a>
			<a class="button" href="<?php echo esc_url( $data['exports']['csv'] ); ?>"><?php esc_html_e( 'Export CSV', 'fishotel-misc-plugin' ); ?></a>
		</div>
	</div>

	<?php if ( ! $data['available'] ) : ?>
		<div class="fishotel-misc-notice fishotel-misc-notice--error">
			<?php esc_html_e( 'Real sales are not available on this site: WooCommerce\'s order tables (wc_order_stats and wc_order_product_lookup, which WooCommerce Analytics keeps) were not found. Nothing below is an actual figure — the estimates and the landed costs still are what the supplier orders say.', 'fishotel-misc-plugin' ); ?>
		</div>
	<?php endif; ?>

	<p class="fh-cr-legend">
		<span class="fh-cr-kind fh-cr-kind--actual"><?php esc_html_e( 'Actual', 'fishotel-misc-plugin' ); ?></span>
		<?php
		echo esc_html(
			sprintf(
				/* translators: %s: comma-separated order statuses */
				__( 'Units sold, Actual revenue, Avg paid and Actual profit come from WooCommerce orders in a status that counts as a sale (%s): what was paid for each order line after discounts, before tax and shipping, net of refunds.', 'fishotel-misc-plugin' ),
				implode( ', ', $data['labels'] )
			)
		);
		?>
		<?php if ( $can_settings ) : ?>
			<a href="<?php echo esc_url( Admin::page_url( 'settings' ) . '#fishotel-cleanroom-sales-statuses' ); ?>"><?php esc_html_e( 'Change the statuses', 'fishotel-misc-plugin' ); ?></a>
		<?php endif; ?>
		<br>
		<span class="fh-cr-kind fh-cr-kind--est"><?php esc_html_e( 'Estimate', 'fishotel-misc-plugin' ); ?></span>
		<?php esc_html_e( 'Est. revenue is the spreadsheet\'s figure for the same year: survivors × the FisHotel price typed on the supplier order line. List price is today\'s price, not what was charged then.', 'fishotel-misc-plugin' ); ?>
	</p>

	<?php if ( $data['available'] ) : ?>
		<p class="fh-cr-count">
			<?php
			if ( '' !== (string) $fh_ledger['built_at'] ) {
				echo esc_html(
					sprintf(
						/* translators: 1: date and time, 2: order lines */
						__( 'Read from WooCommerce %1$s — %2$d order lines counted.', 'fishotel-misc-plugin' ),
						Admin::site_time( $fh_ledger['built_at'] ),
						(int) $fh_ledger['rows']
					)
				);
			}
			echo ' ';
			esc_html_e( 'Kept for up to twelve hours and read again whenever an order changes status; Refresh reads it now.', 'fishotel-misc-plugin' );
			echo ' ';
			if ( $data['invoices'] ) {
				echo esc_html(
					sprintf(
						/* translators: 1: orders that hold invoice lines, 2: of those, orders the Invoices section had lines for */
						__( 'Invoices section: asked about %1$d orders with invoice lines, answered for %2$d.', 'fishotel-misc-plugin' ),
						(int) $fh_ledger['invoices']['orders'],
						(int) $fh_ledger['invoices']['answered']
					)
				);
			} else {
				esc_html_e( 'The Invoices section is not active: invoice fish are matched by their exact name only.', 'fishotel-misc-plugin' );
			}
			?>
		</p>
		<?php if ( ! empty( $fh_ledger['truncated'] ) ) : ?>
			<div class="fishotel-misc-notice fishotel-misc-notice--error">
				<?php
				echo esc_html(
					sprintf(
						/* translators: %d: order lines */
						__( 'This year has more than %d order lines: only the first that many were read, so every figure below is INCOMPLETE.', 'fishotel-misc-plugin' ),
						Stats_Sales::PAGE_ROWS * Stats_Sales::MAX_PAGES
					)
				);
				?>
			</div>
		<?php endif; ?>
		<?php if ( ! empty( $fh_ledger['invoices']['capped'] ) ) : ?>
			<div class="fishotel-misc-notice fishotel-misc-notice--warning">
				<?php
				echo esc_html(
					sprintf(
						/* translators: %d: orders */
						__( 'More than %d orders hold invoice lines: the Invoices section was asked about the first that many only; the rest are matched by their exact name.', 'fishotel-misc-plugin' ),
						Stats_Sales::INVOICE_ORDERS_MAX
					)
				);
				?>
			</div>
		<?php endif; ?>
	<?php endif; ?>

	<div class="fh-cr-summary">
		<span class="fh-cr-chip fh-cr-chip--ready"><strong><?php echo esc_html( (int) $fh_m['matched_units'] ); ?></strong> <?php esc_html_e( 'fish sold, matched to a species', 'fishotel-misc-plugin' ); ?></span>
		<span class="fh-cr-chip fh-cr-chip--ready"><strong><?php echo esc_html( Costing::money( $fh_m['matched_revenue'] ) ); ?></strong> <?php esc_html_e( 'actual revenue, matched', 'fishotel-misc-plugin' ); ?></span>
		<span class="fh-cr-chip<?php echo abs( $fh_m['unmatched_revenue'] ) > 0.005 ? ' fh-cr-chip--warn' : ''; ?>"><strong><?php echo esc_html( Costing::money( $fh_m['unmatched_revenue'] ) ); ?></strong> <?php esc_html_e( 'not matched', 'fishotel-misc-plugin' ); ?></span>
		<span class="fh-cr-chip"><strong><?php echo esc_html( Costing::money( $fh_sum['profit'] ) ); ?></strong> <?php esc_html_e( 'actual profit on the fish with a landed cost', 'fishotel-misc-plugin' ); ?></span>
		<span class="fh-cr-summary__meta"><?php echo esc_html( $fh_year ); ?></span>
	</div>

	<h2 class="fh-cr-h2"><?php esc_html_e( 'By species', 'fishotel-misc-plugin' ); ?></h2>
	<?php if ( ! $data['rows'] ) : ?>
		<div class="fishotel-misc-empty"><span class="dashicons dashicons-chart-bar"></span><p><?php echo esc_html( $data['all'] > 0 ? __( 'No fish matches that search.', 'fishotel-misc-plugin' ) : __( 'No sale matched to a species and no supplier order in this year.', 'fishotel-misc-plugin' ) ); ?></p></div>
	<?php else : ?>
		<div class="fh-cr-grid-wrap">
			<table class="widefat striped fh-cr-table">
				<thead>
					<tr>
						<?php
						$fh_th( 'name', __( 'Species', 'fishotel-misc-plugin' ), '', true );
						$fh_th( 'group', __( 'Group', 'fishotel-misc-plugin' ), '', true );
						$fh_th( 'units', __( 'Units sold', 'fishotel-misc-plugin' ), 'actual', false, __( 'Actual: the quantity on paid order lines, net of refunds.', 'fishotel-misc-plugin' ) );
						$fh_th( 'revenue', __( 'Actual revenue', 'fishotel-misc-plugin' ), 'actual', false, __( 'Actual: what was paid for those lines after discounts, before tax and shipping, net of refunds.', 'fishotel-misc-plugin' ) );
						$fh_th( 'avg_paid', __( 'Avg paid', 'fishotel-misc-plugin' ), 'actual', false, __( 'Actual revenue ÷ units sold.', 'fishotel-misc-plugin' ) );
						$fh_th( 'list_price', __( 'List price', 'fishotel-misc-plugin' ), '', false, __( 'FisHotel\'s price today: the linked product\'s current price, else the species list\'s.', 'fishotel-misc-plugin' ) );
						$fh_th( 'est_revenue', __( 'Est. revenue', 'fishotel-misc-plugin' ), 'est', false, __( 'Estimate: survivors × the price typed on that year\'s supplier order lines.', 'fishotel-misc-plugin' ) );
						$fh_th( 'landed_per_fish', __( 'Landed / fish', 'fishotel-misc-plugin' ), '', false, __( 'That year\'s average landed cost of the fish: landed ÷ received over its supplier order lines of the year.', 'fishotel-misc-plugin' ) );
						$fh_th( 'cost_sold', __( 'Cost of units sold', 'fishotel-misc-plugin' ), '', false, __( 'Units sold × landed per fish.', 'fishotel-misc-plugin' ) );
						$fh_th( 'profit', __( 'Actual profit', 'fishotel-misc-plugin' ), 'actual', false, __( 'Actual revenue − the cost of the units sold. Not shown for a fish that sold in a year in which none landed.', 'fishotel-misc-plugin' ) );
						?>
						<th scope="col"><?php esc_html_e( 'Matched by', 'fishotel-misc-plugin' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $data['rows'] as $fh_r ) : ?>
						<tr>
							<td><strong><?php echo esc_html( $fh_r['name'] ); ?></strong></td>
							<td><?php echo esc_html( '' !== $fh_r['group'] ? $fh_r['group'] : '—' ); ?></td>
							<td class="fh-cr-num fh-cr-actual"><?php echo esc_html( $fh_r['sold'] ? $fh_r['units'] : '—' ); ?></td>
							<td class="fh-cr-num fh-cr-actual"><?php echo esc_html( $fh_r['sold'] ? Costing::money( $fh_r['revenue'] ) : '—' ); ?></td>
							<td class="fh-cr-num fh-cr-actual"><?php echo esc_html( Costing::money( $fh_r['avg_paid'] ) ); ?></td>
							<td class="fh-cr-num">
								<?php echo esc_html( Costing::money( $fh_r['list_price'] ) ); ?>
								<?php if ( null !== $fh_r['list_price'] ) : ?>
									<span class="fh-cr-sub"><?php echo esc_html( Admin_Stats::price_source_label( $fh_r['price_source'], $fh_r['price_from'] ) ); ?></span>
								<?php endif; ?>
							</td>
							<td class="fh-cr-num fh-cr-est">
								<?php if ( $fh_r['est_unknown'] ) : ?>
									<span class="fh-cr-sub" title="<?php esc_attr_e( 'No survivor count on any line of this fish that year, so there is nothing to estimate from.', 'fishotel-misc-plugin' ); ?>"><?php esc_html_e( 'survivors not known yet', 'fishotel-misc-plugin' ); ?></span>
								<?php else : ?>
									<?php echo esc_html( null === $fh_r['est_revenue'] ? '—' : Costing::money( $fh_r['est_revenue'] ) ); ?>
									<?php if ( (int) $fh_r['unknown_lines'] > 0 ) : ?>
										<span class="fh-cr-sub"><?php echo esc_html( sprintf( /* translators: %d: order lines */ _n( '%d line without survivors: not in the estimate', '%d lines without survivors: not in the estimate', (int) $fh_r['unknown_lines'], 'fishotel-misc-plugin' ), (int) $fh_r['unknown_lines'] ) ); ?></span>
									<?php endif; ?>
								<?php endif; ?>
							</td>
							<td class="fh-cr-num"><?php echo esc_html( Costing::money( $fh_r['landed_per_fish'] ) ); ?></td>
							<td class="fh-cr-num"><?php echo esc_html( Costing::money( $fh_r['cost_sold'] ) ); ?></td>
							<?php if ( null !== $fh_r['profit'] ) : ?>
								<td class="fh-cr-num fh-cr-actual <?php echo $fh_r['profit'] >= 0 ? 'fh-cr-up' : 'fh-cr-down'; ?>"><?php echo esc_html( ( $fh_r['profit'] > 0 ? '+' : '' ) . Costing::money( $fh_r['profit'] ) ); ?></td>
							<?php elseif ( $fh_r['no_cost'] ) : ?>
								<td class="fh-cr-num fh-cr-nocost"><?php echo esc_html( Admin_Stats::profit_note( $fh_r ) ); ?></td>
							<?php else : ?>
								<td class="fh-cr-num">—</td>
							<?php endif; ?>
							<td><?php echo esc_html( $fh_r['via'] ? implode( ', ', array_map( array( Admin_Stats::class, 'via_label' ), $fh_r['via'] ) ) : '—' ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
				<?php if ( '' === $fh_filters['search'] ) : ?>
					<tfoot>
						<tr>
							<th colspan="2"><?php esc_html_e( 'Every species', 'fishotel-misc-plugin' ); ?></th>
							<th class="fh-cr-num fh-cr-actual"><?php echo esc_html( $fh_sum['units'] ); ?></th>
							<th class="fh-cr-num fh-cr-actual"><?php echo esc_html( Costing::money( $fh_sum['revenue'] ) ); ?></th>
							<th class="fh-cr-num fh-cr-actual"><?php echo esc_html( Costing::money( $fh_sum['units'] > 0 ? $fh_sum['revenue'] / $fh_sum['units'] : null ) ); ?></th>
							<th></th>
							<th class="fh-cr-num fh-cr-est"><?php echo esc_html( Costing::money( $fh_sum['est_revenue'] ) ); ?></th>
							<th></th>
							<th class="fh-cr-num"><?php echo esc_html( Costing::money( $fh_sum['cost_sold'] ) ); ?></th>
							<th class="fh-cr-num fh-cr-actual"><?php echo esc_html( ( $fh_sum['profit'] > 0 ? '+' : '' ) . Costing::money( $fh_sum['profit'] ) ); ?></th>
							<th></th>
						</tr>
					</tfoot>
				<?php endif; ?>
			</table>
		</div>
		<?php if ( $fh_sum['no_cost'] > 0 ) : ?>
			<p class="fh-cr-count">
				<?php
				echo esc_html(
					sprintf(
						/* translators: 1: species, 2: money, 3: year */
						_n(
							'%1$d species sold %2$s in %3$d without a landed cost that year: it has no profit figure, and its revenue is not in the profit total.',
							'%1$d species sold %2$s in %3$d without a landed cost that year: they have no profit figure, and their revenue is not in the profit total.',
							(int) $fh_sum['no_cost'],
							'fishotel-misc-plugin'
						),
						(int) $fh_sum['no_cost'],
						Costing::money( $fh_sum['no_cost_revenue'] ),
						$fh_year
					)
				);
				?>
			</p>
		<?php endif; ?>
	<?php endif; ?>

	<h2 class="fh-cr-h2"><?php esc_html_e( 'Not matched to a species', 'fishotel-misc-plugin' ); ?></h2>
	<?php if ( (int) $fh_m['unmatched_units'] === 0 && abs( $fh_m['unmatched_revenue'] ) < 0.005 ) : ?>
		<p class="fh-cr-sub"><?php esc_html_e( 'Nothing: every paid line of the year is a species.', 'fishotel-misc-plugin' ); ?></p>
	<?php else : ?>
		<p class="fh-cr-count"><?php esc_html_e( 'These lines were paid for but are not counted for any fish above — nothing is guessed. Each kind says what would get it matched.', 'fishotel-misc-plugin' ); ?></p>
		<div class="fh-cr-columns">
			<?php foreach ( $fh_match['unmatched'] as $fh_kind => $fh_bucket ) : ?>
				<?php
				if ( (int) $fh_bucket['lines'] < 1 ) {
					continue;
				}
				?>
				<div class="fishotel-misc-card fh-cr-card">
					<div class="fishotel-misc-card-header">
						<h3 class="fishotel-misc-card-title"><?php echo esc_html( $fh_kinds[ $fh_kind ][0] ); ?></h3>
					</div>
					<p class="fishotel-misc-card-desc"><?php echo esc_html( $fh_kinds[ $fh_kind ][1] ); ?></p>
					<table class="widefat fh-cr-table">
						<thead>
							<tr>
								<th scope="col"><?php esc_html_e( 'What', 'fishotel-misc-plugin' ); ?></th>
								<th scope="col" class="fh-cr-num"><?php esc_html_e( 'Units', 'fishotel-misc-plugin' ); ?></th>
								<th scope="col" class="fh-cr-num"><?php esc_html_e( 'Paid', 'fishotel-misc-plugin' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $fh_bucket['items'] as $fh_item ) : ?>
								<tr>
									<td><?php echo esc_html( Admin_Stats::unmatched_item_label( $fh_kind, $fh_item ) ); ?></td>
									<td class="fh-cr-num"><?php echo esc_html( (int) $fh_item['units'] ); ?></td>
									<td class="fh-cr-num"><?php echo esc_html( Costing::money( $fh_item['revenue'] ) ); ?></td>
								</tr>
							<?php endforeach; ?>
							<?php if ( (int) $fh_bucket['more'] > 0 ) : ?>
								<tr>
									<td colspan="3" class="fh-cr-sub"><?php echo esc_html( sprintf( /* translators: %d: items */ __( '… and %d more, counted in the total below.', 'fishotel-misc-plugin' ), (int) $fh_bucket['more'] ) ); ?></td>
								</tr>
							<?php endif; ?>
						</tbody>
						<tfoot>
							<tr>
								<th><?php esc_html_e( 'All of this kind', 'fishotel-misc-plugin' ); ?></th>
								<th class="fh-cr-num"><?php echo esc_html( (int) $fh_bucket['units'] ); ?></th>
								<th class="fh-cr-num"><?php echo esc_html( Costing::money( $fh_bucket['revenue'] ) ); ?></th>
							</tr>
						</tfoot>
					</table>
				</div>
			<?php endforeach; ?>
		</div>
	<?php endif; ?>

	<h2 class="fh-cr-h2"><?php esc_html_e( 'How it adds up', 'fishotel-misc-plugin' ); ?></h2>
	<dl class="fh-cr-stats-block fh-cr-addsup">
		<div><dt><?php esc_html_e( 'Matched to a species', 'fishotel-misc-plugin' ); ?></dt><dd><?php echo esc_html( Costing::money( $fh_m['matched_revenue'] ) ); ?></dd></div>
		<div><dt><?php esc_html_e( '+ Not matched', 'fishotel-misc-plugin' ); ?></dt><dd><?php echo esc_html( Costing::money( $fh_m['unmatched_revenue'] ) ); ?></dd></div>
		<div><dt><?php esc_html_e( '= Every paid order line of the year', 'fishotel-misc-plugin' ); ?></dt><dd class="<?php echo $fh_m['balanced'] ? '' : 'fh-cr-down'; ?>"><?php echo esc_html( Costing::money( $fh_m['lines_revenue'] ) ); ?></dd></div>
		<div><dt><?php esc_html_e( 'Refunded without naming a line', 'fishotel-misc-plugin' ); ?></dt><dd><?php echo esc_html( 0 === (int) $fh_match['refunds']['count'] ? '—' : Costing::money( $fh_match['refunds']['amount'] ) . ' · ' . (int) $fh_match['refunds']['count'] ); ?></dd></div>
	</dl>
	<?php if ( ! $fh_m['balanced'] ) : ?>
		<div class="fishotel-misc-notice fishotel-misc-notice--error"><?php esc_html_e( 'Matched and not matched do not add up to the paid lines. Press Refresh; if this stays, the figures of this year should not be trusted.', 'fishotel-misc-plugin' ); ?></div>
	<?php endif; ?>

	<p class="fh-cr-footnote">
		<?php esc_html_e( 'A sale belongs to the year its order was placed; a refund is taken off the year of the order it refunds, so a year can differ a little from WooCommerce Analytics, which counts a refund on the day it was made. Money refunded without naming a line and a quantity cannot be taken off any fish: it is shown above and in no row. A fish is matched by its product (a species linked to that product, every variation included), by its line in the Invoices section when that is active, or by an exact name — the species name, the title of its linked product, or a learned alias — and by nothing else. Actual profit = actual revenue − units sold × that year\'s average landed cost per fish; the cost of fish that died is not taken off here (the landed totals are on the Species view), and neither are shipping, tax, fees or credits charged as their own order line. The numbers are read from WooCommerce\'s Analytics tables: orders from before Analytics existed show only after WooCommerce → Analytics → Settings → Import historical data.', 'fishotel-misc-plugin' ); ?>
	</p>
</div>
