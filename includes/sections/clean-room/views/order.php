<?php
/**
 * The order editor — the header, the lines grid with the sheet's
 * computed columns, the totals and the stats block, Save and Check in.
 *
 * @package FisHotel\Misc\Sections\Clean_Room
 *
 * @var array   $order         Resolved order (Orders::resolve()).
 * @var array   $figures       Admin_Workbook::figures().
 * @var array[] $spa_tanks     Active Spa tanks.
 * @var array[] $spa_buildings Spa buildings.
 * @var array[] $box_rates     Settings::box_rates().
 * @var array   $pick          suppliers, origins.
 * @var bool    $can_checkin   Whether a line can be checked in.
 * @var bool    $is_new        Whether the order is unsaved.
 * @var array   $batch         Admin_Workbook::batch_sale_panel() (empty for a new order).
 * @var string  $list_url      The Arrivals list URL.
 * @var string  $flash         One-time notice.
 * @var string  $today         Site date.
 * @var bool    $tables_ok     Whether the tables exist.
 * @var bool    $can_settings  Whether the Settings tab is offered.
 */

defined( 'ABSPATH' ) || exit;

use FisHotel\Misc\Sections\Clean_Room\Admin;
use FisHotel\Misc\Sections\Clean_Room\Admin_Workbook;
use FisHotel\Misc\Sections\Clean_Room\Clean_Room;
use FisHotel\Misc\Sections\Clean_Room\Costing;
use FisHotel\Misc\Sections\Clean_Room\Layout;
use FisHotel\Misc\Sections\Clean_Room\Orders;
use FisHotel\Misc\Sections\Clean_Room\Replay;

$tab        = 'arrivals';
$fh_mode    = (string) $order['cost_mode'];
$fh_totals  = $figures['order'];
$fh_linked  = false;
foreach ( $order['lines'] as $fh_l ) {
	if ( ! empty( $fh_l['stay_id'] ) ) {
		$fh_linked = true;
		break;
	}
}

/**
 * One line row (or the template row with index __i__).
 *
 * @param string|int $i    Row index.
 * @param array      $line Resolved line (empty for the template).
 * @param array      $fig  Its figures (empty for the template).
 */
$fh_line_row = static function ( $i, array $line, array $fig ) use ( $spa_tanks ) {
	$name   = static function ( $field ) use ( $i ) {
		return 'lines[' . $i . '][' . $field . ']';
	};
	$value  = static function ( $field, $default = '' ) use ( $line ) {
		return isset( $line[ $field ] ) && null !== $line[ $field ] ? (string) $line[ $field ] : (string) $default;
	};
	$f      = static function ( $key ) use ( $fig ) {
		return isset( $fig[ $key ] ) ? (string) $fig[ $key ] : '—';
	};
	$linked = ! empty( $line['stay_id'] );
	$money  = static function ( $field, $decimals ) use ( $line ) {
		return isset( $line[ $field ] ) && null !== $line[ $field ] ? number_format( (float) $line[ $field ], $decimals, '.', '' ) : '';
	};
	?>
	<tr class="fh-cr-line<?php echo $linked ? ' is-linked' : ''; ?>" data-line="<?php echo esc_attr( $value( 'id', 0 ) ); ?>">
		<td class="fh-cr-num fh-cr-line__n"><span class="fh-cr-line__num"><?php echo esc_html( is_int( $i ) ? (string) ( $i + 1 ) : '' ); ?></span><input type="hidden" name="<?php echo esc_attr( $name( 'id' ) ); ?>" value="<?php echo esc_attr( $value( 'id', 0 ) ); ?>"><input type="hidden" name="<?php echo esc_attr( $name( 'species_id' ) ); ?>" value="<?php echo esc_attr( $value( 'species_id' ) ); ?>"></td>
		<td class="fh-cr-suggest-wrap"><input type="text" class="fh-cr-input fh-cr-species" name="<?php echo esc_attr( $name( 'species' ) ); ?>" value="<?php echo esc_attr( $value( 'species' ) ); ?>" placeholder="<?php esc_attr_e( 'species', 'fishotel-misc-plugin' ); ?>" autocomplete="off" maxlength="120">
			<?php if ( $linked ) : ?>
				<span class="fh-cr-sub"><a href="<?php echo esc_url( Admin::page_url( 'stays', array( 's' => $value( 'species' ) ) ) ); ?>"><?php echo esc_html( sprintf( /* translators: %d: stay id */ __( 'stay #%d', 'fishotel-misc-plugin' ), (int) $line['stay_id'] ) ); ?></a></span>
			<?php endif; ?>
		</td>
		<td><input type="text" class="fh-cr-input fh-cr-input--sci" name="<?php echo esc_attr( $name( 'sci_name' ) ); ?>" value="<?php echo esc_attr( $value( 'sci_name' ) ); ?>" maxlength="120"></td>
		<td class="fh-cr-num"><input type="number" class="fh-cr-input fh-cr-input--qty" name="<?php echo esc_attr( $name( 'qty_ordered' ) ); ?>" value="<?php echo esc_attr( $value( 'qty_ordered', 0 ) ); ?>" min="0" max="9999"></td>
		<td class="fh-cr-num fh-cr-col--per-box"><input type="number" class="fh-cr-input fh-cr-input--qty" name="<?php echo esc_attr( $name( 'per_box' ) ); ?>" value="<?php echo esc_attr( $value( 'per_box' ) ); ?>" min="1" max="999" placeholder="<?php echo esc_attr( $f( 'per_box_used' ) ); ?>"></td>
		<td class="fh-cr-num fh-cr-col--bags"><input type="number" class="fh-cr-input fh-cr-input--qty" name="<?php echo esc_attr( $name( 'bags' ) ); ?>" value="<?php echo esc_attr( $value( 'bags' ) ); ?>" min="1" max="9999"></td>
		<?php if ( $linked ) : ?>
			<td class="fh-cr-num"><?php echo esc_html( $value( 'qty_received', 0 ) ); ?></td>
		<?php else : ?>
			<td class="fh-cr-num"><input type="number" class="fh-cr-input fh-cr-input--qty" name="<?php echo esc_attr( $name( 'qty_received' ) ); ?>" value="<?php echo esc_attr( $value( 'qty_received' ) ); ?>" min="0" max="9999"></td>
		<?php endif; ?>
		<td class="fh-cr-num"><input type="number" step="0.0001" class="fh-cr-input fh-cr-input--money" name="<?php echo esc_attr( $name( 'unit_price' ) ); ?>" value="<?php echo esc_attr( $money( 'unit_price', 4 ) ); ?>" min="0"></td>
		<td class="fh-cr-num"><input type="number" step="0.01" class="fh-cr-input fh-cr-input--money" name="<?php echo esc_attr( $name( 'worth' ) ); ?>" value="<?php echo esc_attr( $money( 'worth', 2 ) ); ?>" min="0"></td>
		<?php if ( $linked ) : ?>
			<td class="fh-cr-num" data-f="doa"><?php echo esc_html( $f( 'doa' ) ); ?></td>
			<td class="fh-cr-num" data-f="survived"><?php echo esc_html( $f( 'survived' ) ); ?></td>
			<td><?php echo esc_html( ! empty( $line['tank_number'] ) ? (string) $line['tank_number'] : '—' ); ?></td>
		<?php else : ?>
			<td class="fh-cr-num"><input type="number" class="fh-cr-input fh-cr-input--qty" name="<?php echo esc_attr( $name( 'qty_doa' ) ); ?>" value="<?php echo esc_attr( $value( 'qty_doa', 0 ) ); ?>" min="0" max="9999"></td>
			<td class="fh-cr-num"><input type="number" class="fh-cr-input fh-cr-input--qty" name="<?php echo esc_attr( $name( 'qty_survived' ) ); ?>" value="<?php echo esc_attr( $value( 'qty_survived' ) ); ?>" min="0" max="9999" placeholder="—"></td>
			<td>
				<select class="fh-cr-input fh-cr-input--tank" name="<?php echo esc_attr( $name( 'tank_id' ) ); ?>">
					<option value=""><?php esc_html_e( 'auto', 'fishotel-misc-plugin' ); ?></option>
					<?php foreach ( $spa_tanks as $fh_t ) : ?>
						<option value="<?php echo esc_attr( $fh_t['id'] ); ?>" <?php selected( (int) $fh_t['id'], (int) $value( 'tank_id', 0 ) ); ?>><?php echo esc_html( $fh_t['number'] ); ?></option>
					<?php endforeach; ?>
				</select>
			</td>
		<?php endif; ?>
		<td class="fh-cr-num fh-cr-calc" data-f="units"><?php echo esc_html( $f( 'units' ) ); ?></td>
		<td class="fh-cr-num fh-cr-calc" data-f="landed_total"><?php echo esc_html( $f( 'landed_total' ) ); ?></td>
		<td class="fh-cr-num fh-cr-calc" data-f="landed_per_fish"><?php echo esc_html( $f( 'landed_per_fish' ) ); ?></td>
		<td class="fh-cr-num fh-cr-calc" data-f="suggested_price"><?php echo esc_html( $f( 'suggested_price' ) ); ?></td>
		<td class="fh-cr-num fh-cr-calc" data-f="profit"><?php echo esc_html( $f( 'profit' ) ); ?></td>
		<td class="fh-cr-num fh-cr-calc" data-f="margin"><?php echo esc_html( $f( 'margin' ) ); ?></td>
		<td class="fh-cr-calc fh-cr-worth<?php echo ! empty( $fig['worth_flag'] ) ? ' is-yes' : ''; ?>" data-f="worth_it"><?php echo esc_html( $f( 'worth_it' ) ); ?></td>
		<td class="fh-cr-num fh-cr-calc" data-f="revenue"><?php echo esc_html( $f( 'revenue' ) ); ?></td>
		<td class="fh-cr-num fh-cr-calc" data-f="live"><?php echo esc_html( $f( 'live' ) ); ?></td>
		<td class="fh-cr-num fh-cr-calc" data-f="sold"><?php echo esc_html( $f( 'sold' ) ); ?></td>
		<td class="fh-cr-row__actions"><?php if ( ! $linked ) : ?><button type="button" class="button button-small fh-cr-line-remove" title="<?php esc_attr_e( 'Remove line', 'fishotel-misc-plugin' ); ?>">&times;</button><?php endif; ?></td>
	</tr>
	<?php
};
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

	<?php if ( ! $is_new && ! empty( $batch ) ) : ?>
		<form class="fishotel-misc-card fh-cr-card fh-cr-sale" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( $batch['nonce_action'] ); ?>
			<input type="hidden" name="action" value="<?php echo esc_attr( Admin_Workbook::SALE_DATE_ACTION ); ?>">
			<input type="hidden" name="order_id" value="<?php echo esc_attr( (int) $order['id'] ); ?>">
			<div class="fishotel-misc-card-header">
				<div class="fishotel-misc-card-icon"><span class="dashicons dashicons-calendar-alt"></span></div>
				<h2 class="fishotel-misc-card-title"><?php esc_html_e( 'Goes on sale', 'fishotel-misc-plugin' ); ?></h2>
			</div>
			<p class="fishotel-misc-card-desc"><?php esc_html_e( 'The day every group of this batch goes on sale. A group with its own date (set in the Clean room box on its product page) keeps that one. Nothing on the shop changes by itself: stock and visibility stay yours to set.', 'fishotel-misc-plugin' ); ?></p>
			<div class="fh-cr-sale__row">
				<label class="screen-reader-text" for="fh-cr-sale-date"><?php esc_html_e( 'Goes on sale', 'fishotel-misc-plugin' ); ?></label>
				<input type="date" id="fh-cr-sale-date" class="fh-cr-input" name="sale_date" value="<?php echo esc_attr( $batch['date'] ); ?>" min="<?php echo esc_attr( Replay::SALE_DATE_MIN ); ?>" max="<?php echo esc_attr( Replay::sale_date_max( (string) $today ) ); ?>">
				<button type="submit" class="button button-primary" name="op" value="save"><?php esc_html_e( 'Save', 'fishotel-misc-plugin' ); ?></button>
				<button type="submit" class="button" name="op" value="clear" <?php disabled( '' === $batch['date'] ); ?>><?php esc_html_e( 'Clear', 'fishotel-misc-plugin' ); ?></button>
				<?php if ( '' !== $batch['suggested'] ) : ?>
					<button type="button" class="button-link fh-cr-suggest-date" data-target="fh-cr-sale-date" data-date="<?php echo esc_attr( $batch['suggested'] ); ?>" title="<?php esc_attr_e( 'Check-in + the suggested days (Settings). Fills the date; Save keeps it.', 'fishotel-misc-plugin' ); ?>"><?php echo esc_html( sprintf( /* translators: %s: date */ __( 'suggested %s', 'fishotel-misc-plugin' ), $batch['suggested'] ) ); ?></button>
				<?php else : ?>
					<span class="fh-cr-sub fh-cr-sub--inline"><?php esc_html_e( 'A suggestion appears once the fish are checked in.', 'fishotel-misc-plugin' ); ?></span>
				<?php endif; ?>
			</div>
			<p class="fh-cr-sale__status"><?php echo esc_html( Admin_Workbook::batch_sale_status( $batch, (string) $today ) ); ?></p>
			<?php if ( $batch['own'] ) : ?>
				<p class="fh-cr-sub"><?php esc_html_e( 'These groups have their own date and keep it (change it on the product page):', 'fishotel-misc-plugin' ); ?></p>
				<table class="widefat fh-cr-table fh-cr-sale__own">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'Product', 'fishotel-misc-plugin' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Tank', 'fishotel-misc-plugin' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Own date', 'fishotel-misc-plugin' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $batch['own'] as $fh_own ) : ?>
							<tr>
								<td>
									<?php if ( '' !== $fh_own['edit_url'] ) : ?>
										<a href="<?php echo esc_url( $fh_own['edit_url'] ); ?>"><?php echo esc_html( '' !== $fh_own['product'] ? $fh_own['product'] : $fh_own['species'] ); ?></a>
									<?php else : ?>
										<?php echo esc_html( $fh_own['species'] ); ?> <span class="fh-cr-sub fh-cr-sub--inline"><?php esc_html_e( 'no product — Stays tab', 'fishotel-misc-plugin' ); ?></span>
									<?php endif; ?>
								</td>
								<td><?php echo esc_html( null !== $fh_own['tank'] ? (string) $fh_own['tank'] : '—' ); ?></td>
								<td>
									<?php echo esc_html( $fh_own['date'] ); ?><?php echo $fh_own['ready'] ? ' <span class="fh-cr-sub fh-cr-sub--inline">' . esc_html__( 'for sale', 'fishotel-misc-plugin' ) . '</span>' : ''; ?>
									<?php if ( ! empty( $fh_own['in_spa'] ) ) : ?>
										<span class="fh-cr-warn"><?php esc_html_e( 'still in the Spa', 'fishotel-misc-plugin' ); ?></span>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</form>
	<?php endif; ?>

	<form id="fh-cr-order" class="fh-cr-order" data-order="<?php echo esc_attr( (int) $order['id'] ); ?>" data-mode="<?php echo esc_attr( $fh_mode ); ?>" onsubmit="return false;">
		<input type="hidden" name="order_id" value="<?php echo esc_attr( (int) $order['id'] ); ?>">

		<div class="fh-cr-toolbar fh-cr-toolbar--order">
			<a class="button" href="<?php echo esc_url( $list_url ); ?>">&larr; <?php esc_html_e( 'Orders', 'fishotel-misc-plugin' ); ?></a>
			<h2 class="fh-cr-order__title">
				<?php echo $is_new ? esc_html__( 'New order', 'fishotel-misc-plugin' ) : esc_html( sprintf( /* translators: %s: order ref */ __( 'Order %s', 'fishotel-misc-plugin' ), $order['ref'] ) ); ?>
				<span class="fh-cr-badge fh-cr-badge--order-<?php echo esc_attr( $order['status'] ); ?> fh-cr-order__status"><?php echo esc_html( $order['status'] ); ?></span>
			</h2>
			<div class="fh-cr-actions">
				<span class="fh-cr-status" aria-live="polite"></span>
				<button type="submit" class="button button-primary fh-cr-order-save"><?php esc_html_e( 'Save', 'fishotel-misc-plugin' ); ?></button>
				<?php if ( ! $is_new ) : ?>
					<button type="button" class="button fh-cr-checkin" <?php disabled( ! $can_checkin ); ?> title="<?php esc_attr_e( 'One stay per line with received fish, into a Spa tank; lines already checked in are skipped.', 'fishotel-misc-plugin' ); ?>"><?php esc_html_e( 'Check in received fish', 'fishotel-misc-plugin' ); ?></button>
					<?php if ( ! $fh_linked ) : ?>
						<button type="button" class="button fh-cr-order-delete"><?php esc_html_e( 'Delete', 'fishotel-misc-plugin' ); ?></button>
					<?php endif; ?>
				<?php endif; ?>
			</div>
		</div>

		<div class="fishotel-misc-card fh-cr-card fh-cr-order__head">
			<div class="fh-cr-fields-grid">
				<label><span><?php esc_html_e( 'Ref', 'fishotel-misc-plugin' ); ?></span><input type="text" class="fh-cr-input" name="ref" value="<?php echo esc_attr( $order['ref'] ); ?>" maxlength="40" required></label>
				<label><span><?php esc_html_e( 'Supplier', 'fishotel-misc-plugin' ); ?></span><input type="text" class="fh-cr-input" name="supplier" value="<?php echo esc_attr( $order['supplier'] ); ?>" list="fh-cr-suppliers" maxlength="80"></label>
				<label><span><?php esc_html_e( 'Origin', 'fishotel-misc-plugin' ); ?></span><input type="text" class="fh-cr-input" name="origin" value="<?php echo esc_attr( $order['origin'] ); ?>" list="fh-cr-origins" maxlength="80"></label>
				<label><span><?php esc_html_e( 'Order date', 'fishotel-misc-plugin' ); ?></span><input type="date" class="fh-cr-input" name="order_date" value="<?php echo esc_attr( (string) $order['order_date'] ); ?>"></label>
				<label><span><?php esc_html_e( 'Received date', 'fishotel-misc-plugin' ); ?></span><input type="date" class="fh-cr-input" name="received_date" value="<?php echo esc_attr( (string) $order['received_date'] ); ?>" max="<?php echo esc_attr( $today ); ?>"></label>
				<label><span><?php esc_html_e( 'Status', 'fishotel-misc-plugin' ); ?></span>
					<select class="fh-cr-input" name="status">
						<?php foreach ( Orders::STATUSES as $fh_status ) : ?>
							<option value="<?php echo esc_attr( $fh_status ); ?>" <?php selected( $order['status'], $fh_status ); ?>><?php echo esc_html( $fh_status ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
				<label><span><?php esc_html_e( 'Spa building', 'fishotel-misc-plugin' ); ?></span>
					<select class="fh-cr-input" name="building_id">
						<option value=""><?php esc_html_e( 'any', 'fishotel-misc-plugin' ); ?></option>
						<?php foreach ( $spa_buildings as $fh_b ) : ?>
							<option value="<?php echo esc_attr( $fh_b['id'] ); ?>" <?php selected( (int) $fh_b['id'], (int) $order['building_id'] ); ?>><?php echo esc_html( Layout::building_label( $fh_b ) ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
				<label><span><?php esc_html_e( 'Schedule', 'fishotel-misc-plugin' ); ?></span><input type="text" class="fh-cr-input" name="schedule" value="<?php echo esc_attr( $order['schedule'] ); ?>" maxlength="40" placeholder="<?php esc_attr_e( '14 Day CM', 'fishotel-misc-plugin' ); ?>"></label>
				<label><span><?php esc_html_e( 'Cost mode', 'fishotel-misc-plugin' ); ?></span>
					<select class="fh-cr-input" name="cost_mode">
						<option value="box" <?php selected( $fh_mode, 'box' ); ?>><?php esc_html_e( 'box — fees per full box', 'fishotel-misc-plugin' ); ?></option>
						<option value="bag" <?php selected( $fh_mode, 'bag' ); ?>><?php esc_html_e( 'bag — shipping per bag', 'fishotel-misc-plugin' ); ?></option>
					</select>
				</label>
				<label><span><?php esc_html_e( 'Boxes', 'fishotel-misc-plugin' ); ?></span><input type="number" class="fh-cr-input" name="boxes" value="<?php echo esc_attr( null !== $order['boxes'] ? (string) $order['boxes'] : '' ); ?>" min="0" max="999"></label>
				<label><span><?php esc_html_e( 'Tariffs', 'fishotel-misc-plugin' ); ?></span><input type="number" step="0.01" class="fh-cr-input" name="tariffs" value="<?php echo esc_attr( number_format( (float) $order['tariffs'], 2, '.', '' ) ); ?>" min="0"></label>
				<label><span><?php esc_html_e( 'Domestic flight', 'fishotel-misc-plugin' ); ?></span><input type="number" step="0.01" class="fh-cr-input" name="domestic_flight" value="<?php echo esc_attr( number_format( (float) $order['domestic_flight'], 2, '.', '' ) ); ?>" min="0"></label>
				<label><span><?php esc_html_e( 'Import & re-ox fees', 'fishotel-misc-plugin' ); ?></span>
					<span class="fh-cr-inline">
						<input type="number" step="0.01" class="fh-cr-input" name="import_fees" value="<?php echo esc_attr( number_format( (float) $order['import_fees'], 2, '.', '' ) ); ?>" min="0">
						<button type="button" class="button button-small fh-cr-suggest-fees" title="<?php esc_attr_e( 'boxes × (per box + re-ox) from Settings → Box rates for this supplier and origin', 'fishotel-misc-plugin' ); ?>"><?php esc_html_e( 'Suggest', 'fishotel-misc-plugin' ); ?></button>
					</span>
				</label>
				<label><span><?php esc_html_e( 'Credit', 'fishotel-misc-plugin' ); ?></span><input type="number" step="0.01" class="fh-cr-input" name="credit" value="<?php echo esc_attr( number_format( (float) $order['credit'], 2, '.', '' ) ); ?>" min="0"></label>
				<label><span><?php esc_html_e( 'Markup (suggested price)', 'fishotel-misc-plugin' ); ?></span><input type="number" step="0.01" class="fh-cr-input" name="markup" value="<?php echo esc_attr( Costing::num( $order['markup'] ) ); ?>" min="0.01"></label>
				<label><span><?php esc_html_e( 'Worth-it margin', 'fishotel-misc-plugin' ); ?></span><input type="number" step="0.01" class="fh-cr-input" name="worth_threshold" value="<?php echo esc_attr( Costing::num( $order['worth_threshold'] ) ); ?>" min="0"></label>
				<label class="fh-cr-fields-grid__wide"><span><?php esc_html_e( 'Notes', 'fishotel-misc-plugin' ); ?></span><textarea class="fh-cr-input" name="notes" rows="2"><?php echo esc_textarea( $order['notes'] ); ?></textarea></label>
			</div>
			<datalist id="fh-cr-suppliers"><?php foreach ( $pick['suppliers'] as $fh_v ) : ?><option value="<?php echo esc_attr( $fh_v ); ?>"></option><?php endforeach; ?></datalist>
			<datalist id="fh-cr-origins"><?php foreach ( $pick['origins'] as $fh_v ) : ?><option value="<?php echo esc_attr( $fh_v ); ?>"></option><?php endforeach; ?></datalist>
		</div>

		<div class="fh-cr-grid-wrap">
			<table class="widefat fh-cr-table fh-cr-grid" id="fh-cr-lines">
				<thead>
					<tr>
						<th scope="col">#</th>
						<th scope="col"><?php esc_html_e( 'Species', 'fishotel-misc-plugin' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Sci name', 'fishotel-misc-plugin' ); ?></th>
						<th scope="col" class="fh-cr-num"><?php esc_html_e( 'Order', 'fishotel-misc-plugin' ); ?></th>
						<th scope="col" class="fh-cr-num fh-cr-col--per-box"><?php esc_html_e( 'Per box', 'fishotel-misc-plugin' ); ?></th>
						<th scope="col" class="fh-cr-num fh-cr-col--bags"><?php esc_html_e( 'Bags', 'fishotel-misc-plugin' ); ?></th>
						<th scope="col" class="fh-cr-num"><?php esc_html_e( 'Rcv\'d', 'fishotel-misc-plugin' ); ?></th>
						<th scope="col" class="fh-cr-num"><?php esc_html_e( 'Price', 'fishotel-misc-plugin' ); ?></th>
						<th scope="col" class="fh-cr-num"><?php esc_html_e( 'Worth $', 'fishotel-misc-plugin' ); ?></th>
						<th scope="col" class="fh-cr-num"><?php esc_html_e( 'DOA', 'fishotel-misc-plugin' ); ?></th>
						<th scope="col" class="fh-cr-num"><?php esc_html_e( 'Srv\'d', 'fishotel-misc-plugin' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Tank', 'fishotel-misc-plugin' ); ?></th>
						<th scope="col" class="fh-cr-num" title="<?php esc_attr_e( 'box mode: received ÷ per box; bag mode: bags, else received', 'fishotel-misc-plugin' ); ?>"><?php echo esc_html( 'box' === $fh_mode ? __( 'Box frac.', 'fishotel-misc-plugin' ) : __( 'Units', 'fishotel-misc-plugin' ) ); ?></th>
						<th scope="col" class="fh-cr-num"><?php esc_html_e( 'Total', 'fishotel-misc-plugin' ); ?></th>
						<th scope="col" class="fh-cr-num"><?php esc_html_e( 'Per fish', 'fishotel-misc-plugin' ); ?></th>
						<th scope="col" class="fh-cr-num"><?php echo esc_html( Costing::num( $order['markup'] ) ); ?>&times;</th>
						<th scope="col" class="fh-cr-num"><?php esc_html_e( 'Profit', 'fishotel-misc-plugin' ); ?></th>
						<th scope="col" class="fh-cr-num"><?php esc_html_e( 'Margin', 'fishotel-misc-plugin' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Worth?', 'fishotel-misc-plugin' ); ?></th>
						<th scope="col" class="fh-cr-num"><?php esc_html_e( 'Revenue', 'fishotel-misc-plugin' ); ?></th>
						<th scope="col" class="fh-cr-num"><?php esc_html_e( 'Live', 'fishotel-misc-plugin' ); ?></th>
						<th scope="col" class="fh-cr-num"><?php esc_html_e( 'Sold', 'fishotel-misc-plugin' ); ?></th>
						<th scope="col"></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $order['lines'] as $fh_i => $fh_line ) : ?>
						<?php $fh_line_row( (int) $fh_i, $fh_line, isset( $figures['lines'][ $fh_i ] ) ? $figures['lines'][ $fh_i ] : array() ); ?>
					<?php endforeach; ?>
				</tbody>
				<tfoot>
					<tr>
						<th colspan="3"><?php esc_html_e( 'Totals', 'fishotel-misc-plugin' ); ?></th>
						<th class="fh-cr-num" data-t="ordered"><?php echo esc_html( $fh_totals['ordered'] ); ?></th>
						<th class="fh-cr-col--per-box"></th>
						<th class="fh-cr-col--bags"></th>
						<th class="fh-cr-num" data-t="received"><?php echo esc_html( $fh_totals['received'] ); ?></th>
						<th></th>
						<th></th>
						<th class="fh-cr-num" data-t="doa"><?php echo esc_html( $fh_totals['doa'] ); ?></th>
						<th class="fh-cr-num" data-t="survived"><?php echo esc_html( $fh_totals['survived'] ); ?></th>
						<th></th>
						<th class="fh-cr-num" data-t="units"><?php echo esc_html( $fh_totals['units'] ); ?></th>
						<th class="fh-cr-num" data-t="total"><?php echo esc_html( $fh_totals['total'] ); ?></th>
						<th colspan="5"></th>
						<th class="fh-cr-num" data-t="revenue"><?php echo esc_html( $fh_totals['revenue'] ); ?></th>
						<th colspan="3"></th>
					</tr>
				</tfoot>
			</table>
		</div>
		<template id="fh-cr-line-template"><?php $fh_line_row( '__i__', array(), array() ); ?></template>
		<p class="fh-cr-actions-row">
			<button type="button" class="button fh-cr-line-add"><?php esc_html_e( 'Add line', 'fishotel-misc-plugin' ); ?></button>
			<span class="fh-cr-sub fh-cr-sub--inline"><?php esc_html_e( 'Type a species for suggestions (products and the species list); picking one fills the scientific name, the per-box count and the worth from the list. Computed columns update on Save.', 'fishotel-misc-plugin' ); ?></span>
		</p>

		<div class="fishotel-misc-card fh-cr-card fh-cr-order__stats">
			<div class="fishotel-misc-card-header">
				<div class="fishotel-misc-card-icon"><span class="dashicons dashicons-chart-bar"></span></div>
				<h2 class="fishotel-misc-card-title"><?php esc_html_e( 'This order', 'fishotel-misc-plugin' ); ?></h2>
			</div>
			<dl class="fh-cr-stats-block">
				<div><dt><?php esc_html_e( 'Total', 'fishotel-misc-plugin' ); ?></dt><dd data-t="total"><?php echo esc_html( $fh_totals['total'] ); ?></dd></div>
				<div><dt><?php esc_html_e( 'Credit', 'fishotel-misc-plugin' ); ?></dt><dd data-t="credit"><?php echo esc_html( $fh_totals['credit'] ); ?></dd></div>
				<div><dt><?php esc_html_e( 'Grand total', 'fishotel-misc-plugin' ); ?></dt><dd data-t="grand_total"><?php echo esc_html( $fh_totals['grand_total'] ); ?></dd></div>
				<div><dt><?php esc_html_e( 'Revenue (survivors at FisHotel prices)', 'fishotel-misc-plugin' ); ?></dt><dd data-t="revenue"><?php echo esc_html( $fh_totals['revenue'] ); ?></dd></div>
				<div><dt><?php esc_html_e( 'Up or down', 'fishotel-misc-plugin' ); ?></dt><dd data-t="up_down" class="fh-cr-<?php echo esc_attr( $fh_totals['up_down_flag'] ); ?>"><?php echo esc_html( $fh_totals['up_down'] ); ?></dd></div>
				<div><dt><?php esc_html_e( 'Revenue ÷ grand total', 'fishotel-misc-plugin' ); ?></dt><dd data-t="ratio"><?php echo esc_html( $fh_totals['ratio'] ); ?></dd></div>
				<div><dt><?php esc_html_e( 'Total critters (received)', 'fishotel-misc-plugin' ); ?></dt><dd data-t="received"><?php echo esc_html( $fh_totals['received'] ); ?></dd></div>
				<div><dt><?php esc_html_e( 'Fill rate', 'fishotel-misc-plugin' ); ?></dt><dd data-t="fill_rate"><?php echo esc_html( $fh_totals['fill_rate'] ); ?></dd></div>
				<div><dt><?php esc_html_e( 'Survival rate', 'fishotel-misc-plugin' ); ?></dt><dd data-t="survival_rate"><?php echo esc_html( $fh_totals['survival_rate'] ); ?></dd></div>
				<div><dt><?php esc_html_e( 'DOA rate', 'fishotel-misc-plugin' ); ?></dt><dd data-t="doa_rate"><?php echo esc_html( $fh_totals['doa_rate'] ); ?></dd></div>
				<div><dt><?php esc_html_e( 'DOA cost (as the sheet: a whole box\'s fees per fish)', 'fishotel-misc-plugin' ); ?></dt><dd data-t="doa_cost_sheet"><?php echo esc_html( $fh_totals['doa_cost_sheet'] ); ?></dd></div>
				<div><dt><?php esc_html_e( 'DOA cost (fees per fish)', 'fishotel-misc-plugin' ); ?></dt><dd data-t="doa_cost_per_fish"><?php echo esc_html( $fh_totals['doa_cost_per_fish'] ); ?></dd></div>
				<div><dt data-t="fee_label"><?php echo esc_html( $fh_totals['fee_label'] ); ?></dt><dd data-t="fee_rate"><?php echo esc_html( $fh_totals['fee_rate'] ); ?></dd></div>
				<div><dt data-t="units_label"><?php echo esc_html( $fh_totals['units_label'] ); ?></dt><dd data-t="units"><?php echo esc_html( $fh_totals['units'] ); ?></dd></div>
			</dl>
		</div>
	</form>

	<p class="fh-cr-footnote">
		<?php esc_html_e( 'Box mode: each line takes a fraction of a box (received ÷ per box — the line\'s, else the species list\'s, else 24); the domestic flight and the import & re-ox fees are shared by box space, the tariffs by goods value. Bag mode: bags (else received) × price, plus the flight, import fees and tariffs per bag. Per fish × markup, to the nearest 5, is the suggested price; a line is worth it when its margin (profit ÷ landed) is above the threshold. Checking in creates one stay per line with received fish (the DOA as a loss) and the order becomes "received"; from then on a line\'s DOA, survived, live and sold come from its stay, and its received count is kept as typed.', 'fishotel-misc-plugin' ); ?>
	</p>
</div>
