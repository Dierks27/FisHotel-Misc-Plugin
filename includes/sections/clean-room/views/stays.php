<?php
/**
 * Stays tab — every open stay, closed ones with a toggle, CSV export,
 * and the per-row edits (counts stay editable at any time).
 *
 * @package FisHotel\Misc\Sections\Clean_Room
 *
 * @var array[] $stays        Open stays.
 * @var bool    $show_closed  Whether closed stays are listed.
 * @var array[] $closed_stays Closed stays (when shown).
 * @var array   $filters      area, species.
 * @var string  $csv_url      Nonced CSV URL.
 * @var array   $settings     Settings.
 * @var string  $today        Site date.
 * @var bool    $tables_ok    Whether the tables exist.
 * @var bool    $can_settings Whether the Settings tab is offered.
 */

defined( 'ABSPATH' ) || exit;

use FisHotel\Misc\Sections\Clean_Room\Admin;
use FisHotel\Misc\Sections\Clean_Room\Clean_Room;
use FisHotel\Misc\Sections\Clean_Room\Layout;

$tab = 'stays';

/**
 * One table of stays.
 *
 * @param array[] $rows  Stays.
 * @param bool    $open  Whether these are open stays (edits offered).
 */
$fh_table = static function ( array $rows, $open ) {
	if ( ! $rows ) {
		echo '<div class="fishotel-misc-empty"><span class="dashicons dashicons-location-alt"></span><p>' . esc_html__( 'Nothing here.', 'fishotel-misc-plugin' ) . '</p></div>';
		return;
	}
	?>
	<table class="widefat striped fh-cr-table">
		<thead>
			<tr>
				<th scope="col"><?php esc_html_e( 'Species', 'fishotel-misc-plugin' ); ?></th>
				<th scope="col" class="fh-cr-num"><?php esc_html_e( 'Live / arrived', 'fishotel-misc-plugin' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Check-in', 'fishotel-misc-plugin' ); ?></th>
				<th scope="col" class="fh-cr-num"><?php esc_html_e( 'Day', 'fishotel-misc-plugin' ); ?></th>
				<th scope="col"><?php esc_html_e( 'State', 'fishotel-misc-plugin' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Cleared', 'fishotel-misc-plugin' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Sale-ready', 'fishotel-misc-plugin' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Tank', 'fishotel-misc-plugin' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Supplier / order', 'fishotel-misc-plugin' ); ?></th>
				<th scope="col"></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $rows as $fh_stay ) : ?>
				<tr data-stay="<?php echo esc_attr( $fh_stay['id'] ); ?>" data-live="<?php echo esc_attr( $fh_stay['qty_live'] ); ?>">
					<td>
						<strong><?php echo esc_html( $fh_stay['species'] ); ?></strong>
						<?php if ( $fh_stay['product_id'] ) : ?>
							<span class="fh-cr-sub"><a href="<?php echo esc_url( get_edit_post_link( (int) $fh_stay['product_id'] ) ?: '#' ); ?>"><?php echo esc_html( sprintf( /* translators: %d: product id */ __( 'product #%d', 'fishotel-misc-plugin' ), (int) $fh_stay['product_id'] ) ); ?></a></span>
						<?php else : ?>
							<span class="fh-cr-sub"><?php esc_html_e( 'no product', 'fishotel-misc-plugin' ); ?></span>
						<?php endif; ?>
						<?php if ( '' !== $fh_stay['notes'] ) : ?>
							<span class="fh-cr-sub"><?php echo esc_html( $fh_stay['notes'] ); ?></span>
						<?php endif; ?>
					</td>
					<td class="fh-cr-num"><?php echo esc_html( $fh_stay['qty_live'] ); ?> / <?php echo esc_html( $fh_stay['qty_arrived'] ); ?></td>
					<td><?php echo esc_html( $fh_stay['checkin_date'] ); ?></td>
					<td class="fh-cr-num"><?php echo null !== $fh_stay['day'] ? esc_html( $fh_stay['day'] ) : '—'; ?></td>
					<td><?php echo Admin::badge_html( $fh_stay ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by badge_html(). ?></td>
					<td><?php echo $fh_stay['cleared_date'] ? esc_html( $fh_stay['cleared_date'] ) : '—'; ?></td>
					<td>
						<?php echo '' !== $fh_stay['sale_ready_date'] ? esc_html( $fh_stay['sale_ready_date'] ) : '—'; ?>
						<?php if ( $fh_stay['sale_ready_override'] ) : ?>
							<span class="fh-cr-sub"><?php esc_html_e( 'set by hand', 'fishotel-misc-plugin' ); ?></span>
						<?php endif; ?>
					</td>
					<td>
						<?php if ( $fh_stay['tank_number'] ) : ?>
							<a href="<?php echo esc_url( Clean_Room::front_desk_url( (int) $fh_stay['tank_number'] ) ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $fh_stay['tank_number'] ); ?></a>
							<span class="fh-cr-sub"><?php echo esc_html( Layout::area_label( $fh_stay['area'] ) ); ?></span>
						<?php else : ?>
							—
						<?php endif; ?>
					</td>
					<td>
						<?php echo esc_html( trim( (string) $fh_stay['supplier'] . ' ' . (string) $fh_stay['order_ref'] ) ); ?>
						<?php if ( null !== $fh_stay['landed_cost'] ) : ?>
							<span class="fh-cr-sub"><?php echo esc_html( number_format( (float) $fh_stay['landed_cost'], 2 ) ); ?></span>
						<?php endif; ?>
						<span class="fh-cr-sub"><?php echo esc_html( $fh_stay['source'] ); ?></span>
					</td>
					<td class="fh-cr-row__actions">
						<?php if ( $open ) : ?>
							<button type="button" class="button button-small fh-cr-act" data-op="loss"><?php esc_html_e( 'Lost', 'fishotel-misc-plugin' ); ?></button>
							<button type="button" class="button button-small fh-cr-act" data-op="sold"><?php esc_html_e( 'Shipped', 'fishotel-misc-plugin' ); ?></button>
							<button type="button" class="button button-small fh-cr-act" data-op="count"><?php esc_html_e( 'Count', 'fishotel-misc-plugin' ); ?></button>
							<button type="button" class="button button-small fh-cr-act" data-op="clear"><?php esc_html_e( 'Cleared…', 'fishotel-misc-plugin' ); ?></button>
							<button type="button" class="button button-small fh-cr-act" data-op="ready"><?php esc_html_e( 'Sale-ready…', 'fishotel-misc-plugin' ); ?></button>
							<button type="button" class="button button-small fh-cr-act" data-op="move" data-tank="<?php echo esc_attr( (string) $fh_stay['tank_number'] ); ?>"><?php esc_html_e( 'Move…', 'fishotel-misc-plugin' ); ?></button>
							<?php if ( $fh_stay['product_id'] && '' !== $fh_stay['sale_ready_date'] ) : ?>
								<button type="button" class="button button-small fh-cr-release" title="<?php esc_attr_e( 'Set the product\'s Coming Soon countdown to this sale-ready date', 'fishotel-misc-plugin' ); ?>"><?php esc_html_e( 'Coming Soon', 'fishotel-misc-plugin' ); ?></button>
							<?php endif; ?>
							<button type="button" class="button button-small fh-cr-fields-toggle"><?php esc_html_e( 'Edit', 'fishotel-misc-plugin' ); ?></button>
						<?php endif; ?>
					</td>
				</tr>
				<?php if ( $open ) : ?>
					<tr class="fh-cr-fields" hidden data-stay="<?php echo esc_attr( $fh_stay['id'] ); ?>">
						<td colspan="10">
							<div class="fh-cr-fields__row">
								<label><?php esc_html_e( 'Species', 'fishotel-misc-plugin' ); ?> <input type="text" class="fh-cr-input" name="species" value="<?php echo esc_attr( $fh_stay['species'] ); ?>"></label>
								<label><?php esc_html_e( 'Product ID', 'fishotel-misc-plugin' ); ?> <input type="number" class="fh-cr-input fh-cr-input--num" name="product_id" value="<?php echo esc_attr( (string) $fh_stay['product_id'] ); ?>" min="0"></label>
								<label><?php esc_html_e( 'Check-in', 'fishotel-misc-plugin' ); ?> <input type="date" class="fh-cr-input" name="checkin_date" value="<?php echo esc_attr( $fh_stay['checkin_date'] ); ?>" max="<?php echo esc_attr( $today ); ?>"></label>
								<label><?php esc_html_e( 'Supplier', 'fishotel-misc-plugin' ); ?> <input type="text" class="fh-cr-input" name="supplier" value="<?php echo esc_attr( (string) $fh_stay['supplier'] ); ?>"></label>
								<label><?php esc_html_e( 'Order ref', 'fishotel-misc-plugin' ); ?> <input type="text" class="fh-cr-input" name="order_ref" value="<?php echo esc_attr( (string) $fh_stay['order_ref'] ); ?>"></label>
								<label><?php esc_html_e( 'Landed cost', 'fishotel-misc-plugin' ); ?> <input type="number" step="0.01" min="0" class="fh-cr-input fh-cr-input--num" name="landed_cost" value="<?php echo esc_attr( null !== $fh_stay['landed_cost'] ? number_format( (float) $fh_stay['landed_cost'], 2, '.', '' ) : '' ); ?>"></label>
								<label class="fh-cr-fields__note"><?php esc_html_e( 'Note', 'fishotel-misc-plugin' ); ?> <input type="text" class="fh-cr-input" name="note" value="" placeholder="<?php esc_attr_e( 'adds a note event', 'fishotel-misc-plugin' ); ?>"></label>
								<button type="button" class="button button-primary fh-cr-fields-save"><?php esc_html_e( 'Save', 'fishotel-misc-plugin' ); ?></button>
							</div>
						</td>
					</tr>
				<?php endif; ?>
			<?php endforeach; ?>
		</tbody>
	</table>
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

	<div class="fh-cr-toolbar">
		<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="fh-cr-filters">
			<input type="hidden" name="page" value="<?php echo esc_attr( Clean_Room::MENU_SLUG ); ?>">
			<input type="hidden" name="tab" value="stays">
			<label class="fh-cr-filter">
				<span><?php esc_html_e( 'Area', 'fishotel-misc-plugin' ); ?></span>
				<select name="area" class="fh-cr-input">
					<option value=""><?php esc_html_e( 'Both', 'fishotel-misc-plugin' ); ?></option>
					<?php foreach ( Layout::AREAS as $fh_key => $fh_label ) : ?>
						<option value="<?php echo esc_attr( $fh_key ); ?>" <?php selected( $filters['area'], $fh_key ); ?>><?php echo esc_html( $fh_label ); ?></option>
					<?php endforeach; ?>
				</select>
			</label>
			<label class="fh-cr-filter fh-cr-filter--grow">
				<span><?php esc_html_e( 'Species', 'fishotel-misc-plugin' ); ?></span>
				<input type="search" name="s" class="fh-cr-input" value="<?php echo esc_attr( $filters['species'] ); ?>">
			</label>
			<label class="fh-cr-filter fh-cr-filter--check">
				<input type="checkbox" name="closed" value="1" <?php checked( $show_closed ); ?>>
				<span><?php esc_html_e( 'Show closed', 'fishotel-misc-plugin' ); ?></span>
			</label>
			<button type="submit" class="button"><?php esc_html_e( 'Filter', 'fishotel-misc-plugin' ); ?></button>
		</form>
		<div class="fh-cr-actions">
			<a class="button" href="<?php echo esc_url( $csv_url ); ?>"><?php esc_html_e( 'Export CSV', 'fishotel-misc-plugin' ); ?></a>
			<span class="fh-cr-status" aria-live="polite"></span>
		</div>
	</div>

	<p class="fh-cr-count"><?php echo esc_html( sprintf( /* translators: %d: open stays */ __( '%d open stays · oldest check-in first', 'fishotel-misc-plugin' ), count( $stays ) ) ); ?></p>
	<?php $fh_table( $stays, true ); ?>

	<?php if ( $show_closed ) : ?>
		<h2 class="fh-cr-h2"><?php esc_html_e( 'Closed stays', 'fishotel-misc-plugin' ); ?></h2>
		<?php $fh_table( $closed_stays, false ); ?>
	<?php endif; ?>

	<p class="fh-cr-footnote">
		<?php echo esc_html( sprintf( /* translators: %d: days after check-in (the sale-ready setting) */ __( 'Lost / Shipped / Count / Move… write events (the counts stay editable at any time — the order itself is edited in WooCommerce). Cleared… sets the cleared date by hand (empty removes it); Sale-ready… overrides the date %d days after check-in (empty goes back to it); Edit corrects the check-in date and the supplier fields. Coming Soon sets the product\'s release countdown to the sale-ready date at 09:00. A mistake is undone from the Events tab. The CSV guards every cell against spreadsheet formulas.', 'fishotel-misc-plugin' ), (int) $settings['sale_ready_days'] ) ); ?>
	</p>
</div>
