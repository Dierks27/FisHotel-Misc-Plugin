<?php
/**
 * Arrivals tab — the supplier orders of a year with their figures.
 *
 * @package FisHotel\Misc\Sections\Clean_Room
 *
 * @var array[] $rows         Resolved orders, each with 'summary' and 'on_sale' (Admin_Workbook::on_sale_cell()).
 * @var int[]   $years        Years with orders.
 * @var int     $year         The year shown (0 = all).
 * @var array   $exports      xlsx / csv URLs.
 * @var string  $new_url      The new-order URL.
 * @var string  $flash        One-time notice.
 * @var bool    $tables_ok    Whether the tables exist.
 * @var bool    $can_settings Whether the Settings tab is offered.
 */

defined( 'ABSPATH' ) || exit;

use FisHotel\Misc\Sections\Clean_Room\Admin_Workbook;
use FisHotel\Misc\Sections\Clean_Room\Clean_Room;
use FisHotel\Misc\Sections\Clean_Room\Costing;

$tab = 'arrivals';
?>
<div class="wrap fishotel-misc-wrap fh-cr-wrap">

	<div class="fishotel-misc-header">
		<h1><?php esc_html_e( 'Clean Room', 'fishotel-misc-plugin' ); ?></h1>
		<span class="version-badge">v<?php echo esc_html( FISHOTEL_MISC_VERSION ); ?></span>
	</div>

	<?php include Clean_Room::path() . 'views/tabs.php'; ?>

	<?php if ( ! $tables_ok ) : ?>
		<div class="fishotel-misc-notice fishotel-misc-notice--error">
			<?php esc_html_e( 'The Clean Room tables do not exist. Deactivate and reactivate the plugin to create them.', 'fishotel-misc-plugin' ); ?>
		</div>
	<?php endif; ?>

	<div class="fh-cr-toolbar">
		<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="fh-cr-filters">
			<input type="hidden" name="page" value="<?php echo esc_attr( Clean_Room::MENU_SLUG ); ?>">
			<input type="hidden" name="tab" value="arrivals">
			<label class="fh-cr-filter">
				<span><?php esc_html_e( 'Year', 'fishotel-misc-plugin' ); ?></span>
				<select name="year" class="fh-cr-input" onchange="this.form.submit()">
					<option value="0" <?php selected( 0, $year ); ?>><?php esc_html_e( 'All years', 'fishotel-misc-plugin' ); ?></option>
					<?php foreach ( $years as $fh_year ) : ?>
						<option value="<?php echo esc_attr( $fh_year ); ?>" <?php selected( (int) $fh_year, $year ); ?>><?php echo esc_html( $fh_year ); ?></option>
					<?php endforeach; ?>
				</select>
			</label>
			<noscript><button type="submit" class="button"><?php esc_html_e( 'Filter', 'fishotel-misc-plugin' ); ?></button></noscript>
		</form>
		<div class="fh-cr-actions">
			<a class="button button-primary" href="<?php echo esc_url( $new_url ); ?>"><?php esc_html_e( 'New order', 'fishotel-misc-plugin' ); ?></a>
			<a class="button" href="<?php echo esc_url( $exports['xlsx'] ); ?>"><?php esc_html_e( 'Export XLSX', 'fishotel-misc-plugin' ); ?></a>
			<a class="button" href="<?php echo esc_url( $exports['csv'] ); ?>"><?php esc_html_e( 'Export lines CSV', 'fishotel-misc-plugin' ); ?></a>
		</div>
	</div>

	<?php if ( ! $rows ) : ?>
		<div class="fishotel-misc-empty"><span class="dashicons dashicons-cart"></span><p><?php esc_html_e( 'No supplier orders yet. "New order" starts one; the lines take the species, the counts and the prices, and "Check in received fish" turns them into stays.', 'fishotel-misc-plugin' ); ?></p></div>
	<?php else : ?>
		<p class="fh-cr-count"><?php echo esc_html( sprintf( /* translators: %d: orders */ __( '%d orders · newest first', 'fishotel-misc-plugin' ), count( $rows ) ) ); ?></p>
		<div class="fh-cr-grid-wrap">
			<table class="widefat striped fh-cr-table">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Ref', 'fishotel-misc-plugin' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Supplier', 'fishotel-misc-plugin' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Origin', 'fishotel-misc-plugin' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Ordered', 'fishotel-misc-plugin' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Received', 'fishotel-misc-plugin' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Status', 'fishotel-misc-plugin' ); ?></th>
						<th scope="col"><?php esc_html_e( 'On sale', 'fishotel-misc-plugin' ); ?></th>
						<th scope="col" class="fh-cr-num"><?php esc_html_e( 'Lines', 'fishotel-misc-plugin' ); ?></th>
						<th scope="col" class="fh-cr-num"><?php esc_html_e( 'Ordered / received', 'fishotel-misc-plugin' ); ?></th>
						<th scope="col" class="fh-cr-num"><?php esc_html_e( 'Fill', 'fishotel-misc-plugin' ); ?></th>
						<th scope="col" class="fh-cr-num"><?php esc_html_e( 'DOA', 'fishotel-misc-plugin' ); ?></th>
						<th scope="col" class="fh-cr-num"><?php esc_html_e( 'Survival', 'fishotel-misc-plugin' ); ?></th>
						<th scope="col" class="fh-cr-num"><?php esc_html_e( 'Landed', 'fishotel-misc-plugin' ); ?></th>
						<th scope="col" class="fh-cr-num"><?php esc_html_e( 'Revenue', 'fishotel-misc-plugin' ); ?></th>
						<th scope="col" class="fh-cr-num"><?php esc_html_e( 'Up / down', 'fishotel-misc-plugin' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $rows as $fh_order ) : ?>
						<?php $fh_s = $fh_order['summary']; ?>
						<tr>
							<td><a href="<?php echo esc_url( Admin_Workbook::order_url( (int) $fh_order['id'] ) ); ?>"><strong><?php echo esc_html( $fh_order['ref'] ); ?></strong></a> <span class="fh-cr-sub"><?php echo esc_html( 'bag' === $fh_order['cost_mode'] ? __( 'bag mode', 'fishotel-misc-plugin' ) : __( 'box mode', 'fishotel-misc-plugin' ) ); ?></span></td>
							<td><?php echo esc_html( '' !== $fh_order['supplier'] ? $fh_order['supplier'] : '—' ); ?></td>
							<td><?php echo esc_html( '' !== $fh_order['origin'] ? $fh_order['origin'] : '—' ); ?></td>
							<td><?php echo esc_html( $fh_order['order_date'] ? $fh_order['order_date'] : '—' ); ?></td>
							<td><?php echo esc_html( $fh_order['received_date'] ? $fh_order['received_date'] : '—' ); ?></td>
							<td><span class="fh-cr-badge fh-cr-badge--order-<?php echo esc_attr( $fh_order['status'] ); ?>"><?php echo esc_html( $fh_order['status'] ); ?></span></td>
							<td>
								<a href="<?php echo esc_url( Admin_Workbook::order_url( (int) $fh_order['id'] ) ); ?>" class="<?php echo '' === $fh_order['on_sale']['date'] ? 'fh-cr-muted' : ''; ?>"><?php echo esc_html( $fh_order['on_sale']['text'] ); ?></a>
								<?php if ( '' !== $fh_order['on_sale']['note'] ) : ?>
									<span class="fh-cr-sub"><?php echo esc_html( $fh_order['on_sale']['note'] ); ?></span>
								<?php endif; ?>
							</td>
							<td class="fh-cr-num"><?php echo esc_html( $fh_s['lines'] ); ?></td>
							<td class="fh-cr-num"><?php echo esc_html( $fh_s['ordered'] . ' / ' . $fh_s['received'] ); ?></td>
							<td class="fh-cr-num"><?php echo esc_html( Costing::pct( $fh_s['fill_rate'] ) ); ?></td>
							<td class="fh-cr-num"><?php echo esc_html( Costing::pct( $fh_s['doa_rate'] ) ); ?></td>
							<td class="fh-cr-num"><?php echo esc_html( Costing::pct( $fh_s['survival_rate'] ) ); ?></td>
							<td class="fh-cr-num"><?php echo esc_html( Costing::money( $fh_s['total'] ) ); ?></td>
							<td class="fh-cr-num"><?php echo esc_html( Costing::money( $fh_s['revenue'] ) ); ?></td>
							<td class="fh-cr-num <?php echo $fh_s['up_down'] >= 0 ? 'fh-cr-up' : 'fh-cr-down'; ?>"><?php echo esc_html( ( $fh_s['up_down'] > 0 ? '+' : '' ) . Costing::money( $fh_s['up_down'] ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	<?php endif; ?>

	<p class="fh-cr-footnote">
		<?php esc_html_e( 'On sale = the batch\'s "Goes on sale" date (set on the order): every group checked in from it goes on sale that day, unless it has its own date on its product page. Landed = what the fish cost once tariffs, the domestic flight and the import & re-ox fees are spread over them (the workbook\'s order sheet, in box or bag mode). Revenue = every survivor at FisHotel\'s price; up / down = revenue minus the grand total. Survival is counted over the fish that did not arrive dead; a line that became a stay reads its survivors live from the stay.', 'fishotel-misc-plugin' ); ?>
	</p>
</div>
