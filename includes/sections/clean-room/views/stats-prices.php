<?php
/**
 * Stats tab — Prices to raise: the fish priced under what their latest
 * supplier order suggests, the biggest gap first. READ-ONLY: nothing on
 * this page changes a price anywhere.
 *
 * @package FisHotel\Misc\Sections\Clean_Room
 *
 * @var array  $data         Admin_Stats::prices_data(): filters, rows, counts, suppliers, groups, exports.
 * @var string $flash        One-time notice.
 * @var bool   $tables_ok    Whether the tables exist.
 * @var bool   $can_settings Whether the Settings tab is offered.
 */

defined( 'ABSPATH' ) || exit;

use FisHotel\Misc\Sections\Clean_Room\Admin;
use FisHotel\Misc\Sections\Clean_Room\Admin_Stats;
use FisHotel\Misc\Sections\Clean_Room\Admin_Workbook;
use FisHotel\Misc\Sections\Clean_Room\Clean_Room;
use FisHotel\Misc\Sections\Clean_Room\Costing;
use FisHotel\Misc\Sections\Clean_Room\Stats_Prices;

$tab           = 'stats';
$fh_stats_view = 'prices';
$fh_filters    = $data['filters'];
$fh_counts     = $data['counts'];
?>
<div class="wrap fishotel-misc-wrap fh-cr-wrap">

	<?php include Clean_Room::path() . 'views/stats-head.php'; ?>

	<div class="fh-cr-toolbar">
		<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="fh-cr-filters">
			<input type="hidden" name="page" value="<?php echo esc_attr( Clean_Room::MENU_SLUG ); ?>">
			<input type="hidden" name="tab" value="stats">
			<input type="hidden" name="view" value="prices">
			<label class="fh-cr-filter">
				<span><?php esc_html_e( 'Species group', 'fishotel-misc-plugin' ); ?></span>
				<select name="group" class="fh-cr-input">
					<option value=""><?php esc_html_e( 'Every group', 'fishotel-misc-plugin' ); ?></option>
					<?php foreach ( array_unique( array_merge( $data['groups'], array_filter( array( $fh_filters['group'] ), 'strlen' ) ) ) as $fh_group ) : ?>
						<option value="<?php echo esc_attr( $fh_group ); ?>" <?php selected( $fh_group, $fh_filters['group'] ); ?>><?php echo esc_html( $fh_group ); ?></option>
					<?php endforeach; ?>
				</select>
			</label>
			<label class="fh-cr-filter">
				<span><?php esc_html_e( 'Supplier (costs from that supplier\'s orders only)', 'fishotel-misc-plugin' ); ?></span>
				<select name="supplier" class="fh-cr-input">
					<option value=""><?php esc_html_e( 'Every supplier', 'fishotel-misc-plugin' ); ?></option>
					<?php foreach ( array_unique( array_merge( $data['suppliers'], array_filter( array( $fh_filters['supplier'] ), 'strlen' ) ) ) as $fh_supplier ) : ?>
						<option value="<?php echo esc_attr( $fh_supplier ); ?>" <?php selected( strtolower( $fh_supplier ), strtolower( $fh_filters['supplier'] ) ); ?>><?php echo esc_html( $fh_supplier ); ?></option>
					<?php endforeach; ?>
				</select>
			</label>
			<label class="fh-cr-filter fh-cr-filter--check">
				<input type="checkbox" name="all" value="1" <?php checked( $fh_filters['all'] ); ?>>
				<span><?php esc_html_e( 'Show all fish, not only the ones priced under', 'fishotel-misc-plugin' ); ?></span>
			</label>
			<button type="submit" class="button"><?php esc_html_e( 'Filter', 'fishotel-misc-plugin' ); ?></button>
		</form>
		<div class="fh-cr-actions">
			<a class="button" href="<?php echo esc_url( $data['exports']['xlsx'] ); ?>"><?php esc_html_e( 'Export XLSX', 'fishotel-misc-plugin' ); ?></a>
			<a class="button" href="<?php echo esc_url( $data['exports']['csv'] ); ?>"><?php esc_html_e( 'Export CSV', 'fishotel-misc-plugin' ); ?></a>
		</div>
	</div>

	<p class="fh-cr-legend">
		<span class="fh-cr-kind fh-cr-kind--read"><?php esc_html_e( 'Read-only', 'fishotel-misc-plugin' ); ?></span>
		<?php esc_html_e( 'Nothing on this page changes a price: it only sets what a fish cost to land beside what FisHotel charges for it now. Change a price where it lives — on the product, or in the species list.', 'fishotel-misc-plugin' ); ?>
	</p>

	<div class="fh-cr-summary">
		<span class="fh-cr-chip<?php echo $fh_counts['under'] > 0 ? ' fh-cr-chip--warn' : ' fh-cr-chip--ready'; ?>"><strong><?php echo esc_html( $fh_counts['under'] ); ?></strong> <?php esc_html_e( 'priced under the suggestion', 'fishotel-misc-plugin' ); ?></span>
		<span class="fh-cr-chip"><strong><?php echo esc_html( $fh_counts['all'] ); ?></strong> <?php esc_html_e( 'fish with a landed cost', 'fishotel-misc-plugin' ); ?></span>
		<?php if ( $fh_counts['no_price'] > 0 ) : ?>
			<span class="fh-cr-chip"><strong><?php echo esc_html( $fh_counts['no_price'] ); ?></strong> <?php esc_html_e( 'with no price on file', 'fishotel-misc-plugin' ); ?></span>
		<?php endif; ?>
	</div>

	<?php if ( ! $data['rows'] ) : ?>
		<div class="fishotel-misc-empty"><span class="dashicons dashicons-yes-alt"></span><p>
			<?php
			if ( 0 === (int) $fh_counts['all'] ) {
				esc_html_e( 'No fish here has a supplier order line that received fish at a price yet.', 'fishotel-misc-plugin' );
			} else {
				esc_html_e( 'No fish here is priced under what its latest order suggests. Tick "Show all fish" to see every one.', 'fishotel-misc-plugin' );
			}
			?>
		</p></div>
	<?php else : ?>
		<div class="fh-cr-grid-wrap">
			<table class="widefat striped fh-cr-table">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Species', 'fishotel-misc-plugin' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Group', 'fishotel-misc-plugin' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Latest order', 'fishotel-misc-plugin' ); ?></th>
						<th scope="col" class="fh-cr-num" title="<?php esc_attr_e( 'The landed cost per fish in the latest order that has the fish: landed ÷ received.', 'fishotel-misc-plugin' ); ?>"><?php esc_html_e( 'Landed / fish, latest', 'fishotel-misc-plugin' ); ?></th>
						<th scope="col" class="fh-cr-num" title="<?php esc_attr_e( 'The plain average of the landed cost per fish over the last three orders that have the fish.', 'fishotel-misc-plugin' ); ?>"><?php esc_html_e( 'Landed / fish, last 3', 'fishotel-misc-plugin' ); ?></th>
						<th scope="col" class="fh-cr-num"><?php esc_html_e( 'FisHotel\'s price', 'fishotel-misc-plugin' ); ?></th>
						<th scope="col" class="fh-cr-num" title="<?php esc_attr_e( 'As the order page computes it: the landed cost per fish of the latest order × that order\'s markup, to the nearest 5.', 'fishotel-misc-plugin' ); ?>"><?php esc_html_e( 'Suggested', 'fishotel-misc-plugin' ); ?></th>
						<th scope="col" class="fh-cr-num" title="<?php esc_attr_e( 'Suggested − FisHotel\'s price. Above zero: priced under the suggestion.', 'fishotel-misc-plugin' ); ?>"><?php esc_html_e( 'Gap', 'fishotel-misc-plugin' ); ?></th>
						<th scope="col" class="fh-cr-num" title="<?php esc_attr_e( '(FisHotel\'s price − landed per fish, latest) ÷ landed per fish: the costing\'s margin for one fish at today\'s price.', 'fishotel-misc-plugin' ); ?>"><?php esc_html_e( 'Margin now', 'fishotel-misc-plugin' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $data['rows'] as $fh_r ) : ?>
						<tr>
							<td>
								<?php if ( $fh_r['species_id'] ) : ?>
									<a href="<?php echo esc_url( Admin::page_url( 'species', array( 's' => $fh_r['name'] ) ) ); ?>"><strong><?php echo esc_html( $fh_r['name'] ); ?></strong></a>
								<?php else : ?>
									<strong><?php echo esc_html( $fh_r['name'] ); ?></strong>
									<span class="fh-cr-sub"><?php esc_html_e( 'not in the species list', 'fishotel-misc-plugin' ); ?></span>
								<?php endif; ?>
							</td>
							<td><?php echo esc_html( '' !== $fh_r['group'] ? $fh_r['group'] : '—' ); ?></td>
							<td>
								<?php if ( $fh_r['latest_id'] > 0 ) : ?>
									<a href="<?php echo esc_url( Admin_Workbook::order_url( (int) $fh_r['latest_id'] ) ); ?>"><?php echo esc_html( $fh_r['latest_ref'] ); ?></a>
								<?php else : ?>
									<?php echo esc_html( $fh_r['latest_ref'] ); ?>
								<?php endif; ?>
								<span class="fh-cr-sub"><?php echo esc_html( trim( $fh_r['latest_date'] . ( '' !== $fh_r['supplier'] ? ' · ' . $fh_r['supplier'] : '' ), ' ·' ) ); ?></span>
							</td>
							<td class="fh-cr-num"><?php echo esc_html( Costing::money( $fh_r['landed_latest'] ) ); ?></td>
							<td class="fh-cr-num">
								<?php echo esc_html( Costing::money( $fh_r['landed_avg'] ) ); ?>
								<?php if ( $fh_r['avg_orders'] < Stats_Prices::LAST_ORDERS ) : ?>
									<span class="fh-cr-sub"><?php echo esc_html( sprintf( /* translators: %d: orders */ _n( 'over %d order', 'over %d orders', (int) $fh_r['avg_orders'], 'fishotel-misc-plugin' ), (int) $fh_r['avg_orders'] ) ); ?></span>
								<?php endif; ?>
							</td>
							<td class="fh-cr-num">
								<?php echo esc_html( Costing::money( $fh_r['price'] ) ); ?>
								<span class="fh-cr-sub"><?php echo esc_html( Admin_Stats::price_source_label( $fh_r['price_source'], $fh_r['price_from'] ) ); ?></span>
							</td>
							<td class="fh-cr-num">
								<?php echo esc_html( Costing::num( $fh_r['suggested'], '—', 0 ) ); ?>
								<span class="fh-cr-sub"><?php echo esc_html( sprintf( /* translators: %s: markup */ __( '× %s', 'fishotel-misc-plugin' ), Costing::num( $fh_r['markup'] ) ) ); ?></span>
							</td>
							<td class="fh-cr-num <?php echo $fh_r['under'] ? 'fh-cr-down' : ''; ?>"><?php echo esc_html( null === $fh_r['gap'] ? '—' : ( $fh_r['gap'] > 0 ? '+' : '' ) . Costing::money( $fh_r['gap'] ) ); ?></td>
							<td class="fh-cr-num"><?php echo esc_html( Costing::pct( $fh_r['margin'] ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	<?php endif; ?>

	<p class="fh-cr-footnote">
		<?php
		echo esc_html(
			sprintf(
				/* translators: %d: the step a suggested price is rounded to */
				__( 'One row per fish that has a supplier order line which received fish at a price. Latest = the newest order by its received date (else its order date). Suggested = the landed cost per fish of that order × its markup, to the nearest %d — exactly the order page\'s figure. FisHotel\'s price = the linked product\'s current price when the species is linked and the product has one (for a product with variations: the variation of the size the fish came in, when one matches; else its lowest variation price), else the species list\'s price. A fish with no price on file is listed only under "Show all fish". The supplier filter costs every fish from that supplier\'s orders alone.', 'fishotel-misc-plugin' ),
				Costing::PRICE_STEP
			)
		);
		?>
	</p>
</div>
