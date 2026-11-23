<?php
/**
 * The order editor — the header, then the lines in TWO VIEWS of one
 * table, switched by the two tabs above it and remembered per user:
 *
 *   Entry    only what is typed — # · Fish · Sci name · Size · Ordered ·
 *            Received · Cost · DOA · Box # · % of box · Tank (bag mode:
 *            Bags in place of the two box columns) —, narrow enough that
 *            a whole row fits a 1280-px window. Per box, the typed
 *            survivors and the supplier's code sit behind "More columns".
 *            The Tank cell carries Split (js: rows of "how many → which
 *            tank" under the line, posted as the line's hidden `split`).
 *   Numbers  the fish and what the site works out, nothing to type: shop
 *            price (read — Shop_Price; a pencil types an override) ·
 *            landed per fish · the target price headed as the sheet heads
 *            it ("400%") · profit · margin · Worth it? · revenue ·
 *            survived · live · sold, under a panel of the order's summary.
 *
 * NOTHING IS LOST BY HIDING: both views are the same rows of the same
 * form — a column that is not shown is display:none, and its inputs are
 * posted on Save exactly as the shown ones (tests/order-view-test.php
 * saves from each view and compares the stored lines).
 *
 * Under the table: Add line, "Import lines…" (views/order-import.php),
 * the Boxes card that adds the percents up, and — in Numbers — the
 * order's full figures. A line can be an unknown fish (the "??" box); a
 * line that received fish shows what its label said once it was
 * corrected, and "Identify…" / "Correct the fish…" opens the panel of
 * views/identify-panel.php under it. Every saved line posts the token of
 * the fish it was rendered with (Identify::seen()), so a page loaded
 * before a correction made elsewhere cannot undo it.
 *
 * @package FisHotel\Misc\Sections\Clean_Room
 *
 * @var array   $order         Resolved order (Orders::resolve()).
 * @var array   $figures       Admin_Workbook::figures().
 * @var array[] $spa_tanks     Active Spa tanks.
 * @var array[] $spa_buildings Spa buildings.
 * @var array[] $box_rates     Settings::box_rates().
 * @var array   $pick          suppliers, origins.
 * @var array[] $sizes         The standard sizes (slug, label).
 * @var array[] $shares        The remembered box shares of the order's supplier + origin.
 * @var array   $boxes         Admin_Workbook::boxes_panel(): summary, rows, total, how_full, shares.
 * @var bool    $can_checkin   Whether a line can be checked in.
 * @var bool    $is_new        Whether the order is unsaved.
 * @var array   $batch         Admin_Workbook::batch_sale_panel() (empty for a new order).
 * @var array   $view          Admin_Workbook::order_view(): view (entry|numbers), stage, more.
 * @var array   $split         Admin_Workbook::split_panel(): tanks, fields, offers.
 * @var string  $list_url      The Fish orders list URL.
 * @var string  $flash         One-time notice.
 * @var string  $today         Site date.
 * @var bool    $tables_ok     Whether the tables exist.
 * @var bool    $can_settings  Whether the Settings tab is offered.
 */

defined( 'ABSPATH' ) || exit;

use FisHotel\Misc\Sections\Clean_Room\Admin;
use FisHotel\Misc\Sections\Clean_Room\Admin_No_Product;
use FisHotel\Misc\Sections\Clean_Room\Admin_Workbook;
use FisHotel\Misc\Sections\Clean_Room\Box_Shares;
use FisHotel\Misc\Sections\Clean_Room\Clean_Room;
use FisHotel\Misc\Sections\Clean_Room\Costing;
use FisHotel\Misc\Sections\Clean_Room\Identify;
use FisHotel\Misc\Sections\Clean_Room\Layout;
use FisHotel\Misc\Sections\Clean_Room\Order_Import;
use FisHotel\Misc\Sections\Clean_Room\Orders;
use FisHotel\Misc\Sections\Clean_Room\Replay;
use FisHotel\Misc\Sections\Clean_Room\Sizes;

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
$fh_unknown = Admin_Workbook::unknown_lines( $order );
$fh_view    = in_array( (string) ( $view['view'] ?? '' ), Admin_Workbook::VIEWS, true ) ? (string) $view['view'] : 'entry';
$fh_more    = ! empty( $view['more'] );
$fh_cols    = 26; // Every column of the grid, shown or not: a row that opens under a line spans them all.

/**
 * One line row (or the template row with index __i__). A cell is marked
 * with the view it belongs to: fh-cr-c-entry, fh-cr-c-more (Entry, behind
 * "More columns") or fh-cr-c-numbers; the CSS shows the ones of the
 * form's data-view. Every input is in the row in both views.
 *
 * @param string|int $i    Row index.
 * @param array      $line Resolved line (empty for the template).
 * @param array      $fig  Its figures (empty for the template).
 */
// Where a line's "no product yet" tag leads: the list, narrowed to this order's fish.
$fh_np_url   = Admin_No_Product::url( (int) ( $order['id'] ?? 0 ) > 0 ? array( 'order' => (int) $order['id'] ) : array() );
$fh_line_row = static function ( $i, array $line, array $fig ) use ( $spa_tanks, $sizes, $shares, $fh_mode, $split, $fh_np_url ) {
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
	$id      = (int) $value( 'id', 0 );
	// The remembered share's suggestion, for a line that has no percent yet (box mode only).
	$hint    = $line && 'bag' !== $fh_mode ? Box_Shares::suggest_for( $shares, $line ) : null;
	$unknown = ! empty( $line['needs_id'] );
	// "Correct the fish…" once fish arrived on a saved line; before that its species is simply retyped.
	$correct = $id > 0 && ( $linked || (int) $value( 'qty_received', 0 ) > 0 );
	// The split planned for the line (its hidden field), or the offer of one because its fish was split last time.
	$plan    = ! $linked && $id > 0 && isset( $split['fields'][ $id ] ) ? (string) $split['fields'][ $id ] : '';
	$offer   = ! $linked && $id > 0 && isset( $split['offers'][ $id ] ) ? (string) $split['offers'][ $id ] : '';
	$last    = '' !== $offer && isset( $split['last'][ $id ] ) ? (int) $split['last'][ $id ] : 0;
	$priced  = ! empty( $fig['priced'] );
	$typed   = 'typed' === (string) ( $fig['shop_from'] ?? '' );
	// The notes under the fish, without the supplier's code: that has its own field behind "More columns".
	$notes   = Order_Import::note_with( $value( 'notes' ), Order_Import::NOTE_CODE, '' );
	?>
	<tr class="fh-cr-line<?php echo $linked ? ' is-linked' : ''; ?><?php echo $unknown ? ' is-unknown' : ''; ?>" data-line="<?php echo esc_attr( $id ); ?>"<?php echo '' !== $offer ? ' data-split-offer="' . esc_attr( $offer ) . '" data-split-last="' . esc_attr( $last ) . '"' : ''; ?>>
		<td class="fh-cr-num fh-cr-line__n"><span class="fh-cr-line__num"><?php echo esc_html( is_int( $i ) ? (string) ( $i + 1 ) : '' ); ?></span><input type="hidden" name="<?php echo esc_attr( $name( 'id' ) ); ?>" value="<?php echo esc_attr( $id ); ?>"><input type="hidden" name="<?php echo esc_attr( $name( 'species_id' ) ); ?>" value="<?php echo esc_attr( $value( 'species_id' ) ); ?>"><input type="hidden" name="<?php echo esc_attr( $name( 'seen' ) ); ?>" value="<?php echo esc_attr( $id > 0 ? Identify::seen( $line ) : '' ); ?>"><?php if ( ! $linked ) : ?><input type="hidden" class="fh-cr-split-field" name="<?php echo esc_attr( $name( 'split' ) ); ?>" value="<?php echo esc_attr( $plan ); ?>"><?php endif; ?></td>
		<td class="fh-cr-line__fish">
			<span class="fh-cr-line__name fh-cr-suggest-wrap fh-cr-c-entry">
				<input type="text" class="fh-cr-input fh-cr-species" name="<?php echo esc_attr( $name( 'species' ) ); ?>" value="<?php echo esc_attr( $value( 'species' ) ); ?>" placeholder="<?php esc_attr_e( 'fish', 'fishotel-misc-plugin' ); ?>" autocomplete="off" maxlength="120">
				<label class="fh-cr-unknown-box" title="<?php esc_attr_e( 'Nobody knows what this fish is yet: it reads "??" and what you type here (what it looks like). It still gets its tank, count, size, percent of the box and landed cost.', 'fishotel-misc-plugin' ); ?>"><input type="checkbox" name="<?php echo esc_attr( $name( 'needs_id' ) ); ?>" value="1" <?php checked( $unknown ); ?>> ??</label>
			</span>
			<?php // On a window too narrow for its column the scientific name reads here, under the fish (its field: "More columns"). ?>
			<span class="fh-cr-sub fh-cr-line__sci fh-cr-c-entry"><?php echo esc_html( $value( 'sci_name' ) ); ?></span>
			<span class="fh-cr-line__read fh-cr-c-numbers"><?php echo esc_html( $value( 'species' ) ); ?><?php if ( '' !== $value( 'size_label' ) ) : ?> <span class="fh-cr-sub fh-cr-sub--inline"><?php echo esc_html( $value( 'size_label' ) ); ?></span><?php endif; ?></span>
			<?php // The fish is not linked to a product of the shop yet — said in both views, whatever its price. Empty = no tag; a Save fills it in again. ?>
			<a class="fh-cr-sub fh-cr-noproduct" data-f="no_product" href="<?php echo esc_url( $fh_np_url ); ?>" title="<?php esc_attr_e( 'This fish is not linked to a product of the shop yet. Opens "Fish with no product yet", where it can be linked to a product or get a draft product.', 'fishotel-misc-plugin' ); ?>"><?php echo esc_html( isset( $fig['no_product'] ) ? (string) $fig['no_product'] : '' ); ?></a>
			<?php if ( $linked ) : ?>
				<span class="fh-cr-sub"><a href="<?php echo esc_url( Admin::page_url( 'stays', array( 's' => $value( 'species' ) ) ) ); ?>"><?php echo esc_html( sprintf( /* translators: %d: stay id */ __( 'stay #%d', 'fishotel-misc-plugin' ), (int) $line['stay_id'] ) ); ?></a></span>
			<?php endif; ?>
			<?php if ( $unknown ) : ?>
				<span class="fh-cr-sub"><?php echo Admin::unknown_html( $line ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by unknown_html(). ?></span>
			<?php endif; ?>
			<?php if ( '' !== Identify::label_shown( $line ) ) : ?>
				<span class="fh-cr-sub fh-cr-label-name" title="<?php esc_attr_e( 'What the supplier\'s label or invoice called this fish before it was corrected. Kept for the mislabel stats; a later correction never replaces it.', 'fishotel-misc-plugin' ); ?>"><?php echo esc_html( sprintf( /* translators: %s: what the label called the fish */ __( 'arrived labeled: %s', 'fishotel-misc-plugin' ), Identify::label_shown( $line ) ) ); ?></span>
			<?php endif; ?>
			<?php if ( '' !== $notes ) : ?>
				<span class="fh-cr-sub fh-cr-line__notes fh-cr-c-entry"><?php echo esc_html( $notes ); ?></span>
			<?php endif; ?>
			<?php do_action( 'fishotel_cleanroom_order_line', $line ); // Other sections add a line under the fish (Requests: "Requested by: …"). ?>
			<?php if ( $correct ) : ?>
				<button type="button" class="button-link fh-cr-identify-open fh-cr-c-entry" data-line="<?php echo esc_attr( $id ); ?>" data-size="<?php echo esc_attr( $value( 'size_slug' ) ); ?>" data-size-label="<?php echo esc_attr( $value( 'size_label' ) ); ?>" title="<?php esc_attr_e( 'Name the real fish and its size. The line keeps what the label said, its stays follow, and the remembered box share is written for the corrected fish.', 'fishotel-misc-plugin' ); ?>"><?php echo esc_html( $unknown ? __( 'Identify…', 'fishotel-misc-plugin' ) : __( 'Correct the fish…', 'fishotel-misc-plugin' ) ); ?></button>
			<?php endif; ?>
		</td>
		<td class="fh-cr-c-entry fh-cr-c-sci"><input type="text" class="fh-cr-input fh-cr-input--sci" name="<?php echo esc_attr( $name( 'sci_name' ) ); ?>" value="<?php echo esc_attr( $value( 'sci_name' ) ); ?>" maxlength="120"></td>
		<td class="fh-cr-c-entry">
			<select class="fh-cr-input fh-cr-input--size" name="<?php echo esc_attr( $name( 'size_slug' ) ); ?>">
				<option value="">—</option>
				<?php foreach ( Sizes::options( $sizes, $line ) as $fh_o ) : ?>
					<option value="<?php echo esc_attr( $fh_o['value'] ); ?>" <?php selected( $fh_o['selected'] ); ?>><?php echo esc_html( $fh_o['label'] ); ?></option>
				<?php endforeach; ?>
			</select>
		</td>
		<td class="fh-cr-num fh-cr-c-entry"><input type="number" class="fh-cr-input fh-cr-input--qty" name="<?php echo esc_attr( $name( 'qty_ordered' ) ); ?>" value="<?php echo esc_attr( $value( 'qty_ordered', 0 ) ); ?>" min="0" max="9999"></td>
		<?php if ( $linked ) : ?>
			<td class="fh-cr-num fh-cr-c-entry" data-received="<?php echo esc_attr( $value( 'qty_received', 0 ) ); ?>"><?php echo esc_html( $value( 'qty_received', 0 ) ); ?></td>
		<?php else : ?>
			<td class="fh-cr-num fh-cr-c-entry"><input type="number" class="fh-cr-input fh-cr-input--qty" name="<?php echo esc_attr( $name( 'qty_received' ) ); ?>" value="<?php echo esc_attr( $value( 'qty_received' ) ); ?>" min="0" max="9999"></td>
		<?php endif; ?>
		<td class="fh-cr-num fh-cr-c-entry"><input type="number" step="0.0001" class="fh-cr-input fh-cr-input--money" name="<?php echo esc_attr( $name( 'unit_price' ) ); ?>" value="<?php echo esc_attr( $money( 'unit_price', 4 ) ); ?>" min="0"></td>
		<?php if ( $linked ) : ?>
			<td class="fh-cr-num fh-cr-c-entry" data-f="doa"><?php echo esc_html( $f( 'doa' ) ); ?></td>
		<?php else : ?>
			<td class="fh-cr-num fh-cr-c-entry"><input type="number" class="fh-cr-input fh-cr-input--qty" name="<?php echo esc_attr( $name( 'qty_doa' ) ); ?>" value="<?php echo esc_attr( $value( 'qty_doa', 0 ) ); ?>" min="0" max="9999"></td>
		<?php endif; ?>
		<td class="fh-cr-num fh-cr-c-entry fh-cr-col--per-box"><input type="number" class="fh-cr-input fh-cr-input--qty" name="<?php echo esc_attr( $name( 'box_no' ) ); ?>" value="<?php echo esc_attr( $value( 'box_no' ) ); ?>" min="1" max="<?php echo esc_attr( Costing::BOX_NO_MAX ); ?>" step="1"></td>
		<td class="fh-cr-num fh-cr-c-entry fh-cr-col--per-box">
			<input type="number" class="fh-cr-input fh-cr-input--qty" name="<?php echo esc_attr( $name( 'box_pct' ) ); ?>" value="<?php echo esc_attr( Costing::num( $line['box_pct'] ?? null, '', 2 ) ); ?>" min="0" max="<?php echo esc_attr( Costing::num( Costing::BOX_PCT_MAX ) ); ?>" step="0.01">
			<span class="fh-cr-pct-hint"><?php if ( $hint ) : ?><button type="button" class="button-link fh-cr-suggest-pct" data-pct="<?php echo esc_attr( Costing::num( $hint['pct'], '', 2 ) ); ?>"><?php echo esc_html( Box_Shares::suggest_text( $hint ) ); ?></button><?php endif; ?></span>
		</td>
		<td class="fh-cr-num fh-cr-c-entry fh-cr-col--bags"><input type="number" class="fh-cr-input fh-cr-input--qty" name="<?php echo esc_attr( $name( 'bags' ) ); ?>" value="<?php echo esc_attr( $value( 'bags' ) ); ?>" min="1" max="9999"></td>
		<?php if ( $linked ) : ?>
			<td class="fh-cr-c-entry fh-cr-line__tank"><?php echo esc_html( ! empty( $line['tank_number'] ) ? (string) $line['tank_number'] : '—' ); ?></td>
		<?php else : ?>
			<td class="fh-cr-c-entry fh-cr-line__tank">
				<select class="fh-cr-input fh-cr-input--tank" name="<?php echo esc_attr( $name( 'tank_id' ) ); ?>">
					<option value=""><?php esc_html_e( 'auto', 'fishotel-misc-plugin' ); ?></option>
					<?php foreach ( $spa_tanks as $fh_t ) : ?>
						<option value="<?php echo esc_attr( $fh_t['id'] ); ?>" <?php selected( (int) $fh_t['id'], (int) $value( 'tank_id', 0 ) ); ?>><?php echo esc_html( $fh_t['number'] ); ?></option>
					<?php endforeach; ?>
				</select>
				<span class="fh-cr-split-said"></span>
				<button type="button" class="button button-small fh-cr-split-open" aria-expanded="false" title="<?php esc_attr_e( 'Lay this line out over several tanks: how many go into which tank. The check-in then puts them there.', 'fishotel-misc-plugin' ); ?>"><?php esc_html_e( 'Split', 'fishotel-misc-plugin' ); ?></button>
			</td>
		<?php endif; ?>
		<td class="fh-cr-num fh-cr-c-more fh-cr-col--per-box"><input type="number" class="fh-cr-input fh-cr-input--qty" name="<?php echo esc_attr( $name( 'per_box' ) ); ?>" value="<?php echo esc_attr( $value( 'per_box' ) ); ?>" min="1" max="999" placeholder="<?php echo esc_attr( $f( 'per_box_used' ) ); ?>"></td>
		<?php if ( $linked ) : ?>
			<td class="fh-cr-num fh-cr-c-more" data-f="survived"><?php echo esc_html( $f( 'survived' ) ); ?></td>
		<?php else : ?>
			<td class="fh-cr-num fh-cr-c-more"><input type="number" class="fh-cr-input fh-cr-input--qty" name="<?php echo esc_attr( $name( 'qty_survived' ) ); ?>" value="<?php echo esc_attr( $value( 'qty_survived' ) ); ?>" min="0" max="9999" placeholder="—"></td>
		<?php endif; ?>
		<td class="fh-cr-c-more"><input type="text" class="fh-cr-input fh-cr-input--code" name="<?php echo esc_attr( $name( 'code' ) ); ?>" value="<?php echo esc_attr( Order_Import::code_of( $value( 'notes' ) ) ); ?>" maxlength="40"></td>
		<td class="fh-cr-num fh-cr-c-numbers fh-cr-shop<?php echo $priced ? '' : ' is-none'; ?><?php echo $typed ? ' is-typed' : ''; ?>">
			<span class="fh-cr-shop__price" data-f="shop_price" title="<?php echo esc_attr( $f( 'shop_note' ) ); ?>"><?php echo esc_html( $line ? $f( 'shop_price' ) : '—' ); ?></span>
			<button type="button" class="button-link fh-cr-worth-edit" aria-expanded="false" title="<?php esc_attr_e( 'Type a price of your own for this line (a price you plan). Empty the field to follow the shop again.', 'fishotel-misc-plugin' ); ?>"><span class="dashicons dashicons-edit" aria-hidden="true"></span><span class="screen-reader-text"><?php esc_html_e( 'Type a price of your own', 'fishotel-misc-plugin' ); ?></span></button>
			<input type="number" step="0.01" class="fh-cr-input fh-cr-input--money fh-cr-worth-input" name="<?php echo esc_attr( $name( 'worth' ) ); ?>" value="<?php echo esc_attr( $money( 'worth', 2 ) ); ?>" min="0" hidden>
		</td>
		<td class="fh-cr-num fh-cr-calc fh-cr-c-numbers"><span data-f="landed_per_fish"><?php echo esc_html( $f( 'landed_per_fish' ) ); ?></span><span class="fh-cr-sub" title="<?php echo esc_attr( 'bag' === $fh_mode ? __( 'What the whole line cost landed, and its bags (else the received count).', 'fishotel-misc-plugin' ) : __( 'What the whole line cost landed, and the part of a box it took.', 'fishotel-misc-plugin' ) ); ?>"><span data-f="landed_total"><?php echo esc_html( $f( 'landed_total' ) ); ?></span> · <span data-f="units"><?php echo esc_html( $f( 'units' ) ); ?></span></span></td>
		<td class="fh-cr-num fh-cr-calc fh-cr-c-numbers" data-f="suggested_price"><?php echo esc_html( $f( 'suggested_price' ) ); ?></td>
		<td class="fh-cr-num fh-cr-calc fh-cr-c-numbers fh-cr-priced<?php echo $priced ? '' : ' is-none'; ?>" data-f="profit"><?php echo esc_html( $f( 'profit' ) ); ?></td>
		<td class="fh-cr-num fh-cr-calc fh-cr-c-numbers fh-cr-priced<?php echo $priced ? '' : ' is-none'; ?>" data-f="margin"><?php echo esc_html( $f( 'margin' ) ); ?></td>
		<td class="fh-cr-calc fh-cr-c-numbers fh-cr-priced fh-cr-worth<?php echo ! empty( $fig['worth_flag'] ) ? ' is-yes' : ''; ?><?php echo $priced ? '' : ' is-none'; ?>" data-f="worth_it"><?php echo esc_html( $f( 'worth_it' ) ); ?></td>
		<td class="fh-cr-num fh-cr-calc fh-cr-c-numbers" data-f="revenue"><?php echo esc_html( $f( 'revenue' ) ); ?></td>
		<td class="fh-cr-num fh-cr-calc fh-cr-c-numbers" data-f="survived"><?php echo esc_html( $f( 'survived' ) ); ?></td>
		<td class="fh-cr-num fh-cr-calc fh-cr-c-numbers" data-f="live"><?php echo esc_html( $f( 'live' ) ); ?></td>
		<td class="fh-cr-num fh-cr-calc fh-cr-c-numbers" data-f="sold"><?php echo esc_html( $f( 'sold' ) ); ?></td>
		<td class="fh-cr-row__actions fh-cr-c-entry"><?php if ( ! $linked ) : ?><button type="button" class="button button-small fh-cr-line-remove" title="<?php esc_attr_e( 'Remove line', 'fishotel-misc-plugin' ); ?>">&times;</button><?php endif; ?></td>
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
		<?php // The "Goes on sale" card sits under the order's title, inside the order form; its fields belong to this form (form="…"). ?>
		<form id="fh-cr-sale-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( $batch['nonce_action'] ); ?>
			<input type="hidden" name="action" value="<?php echo esc_attr( Admin_Workbook::SALE_DATE_ACTION ); ?>">
			<input type="hidden" name="order_id" value="<?php echo esc_attr( (int) $order['id'] ); ?>">
		</form>
	<?php endif; ?>

	<form id="fh-cr-order" class="fh-cr-order" data-order="<?php echo esc_attr( (int) $order['id'] ); ?>" data-mode="<?php echo esc_attr( $fh_mode ); ?>" data-view="<?php echo esc_attr( $fh_view ); ?>" data-more="<?php echo $fh_more ? '1' : '0'; ?>" data-stage="<?php echo esc_attr( (string) ( $view['stage'] ?? 'working' ) ); ?>" onsubmit="return false;">
		<input type="hidden" name="order_id" value="<?php echo esc_attr( (int) $order['id'] ); ?>">

		<div class="fh-cr-toolbar fh-cr-toolbar--order">
			<a class="button" href="<?php echo esc_url( $list_url ); ?>">&larr; <?php esc_html_e( 'Fish orders', 'fishotel-misc-plugin' ); ?></a>
			<h2 class="fh-cr-order__title">
				<?php echo $is_new ? esc_html__( 'New fish order', 'fishotel-misc-plugin' ) : esc_html( sprintf( /* translators: %s: fish order ref */ __( 'Fish order %s', 'fishotel-misc-plugin' ), $order['ref'] ) ); ?>
				<span class="fh-cr-badge fh-cr-badge--order-<?php echo esc_attr( $order['status'] ); ?> fh-cr-order__status"><?php echo esc_html( $order['status'] ); ?></span>
				<?php if ( $fh_unknown > 0 ) : ?>
					<span class="fh-cr-warn fh-cr-unknown"><?php echo esc_html( sprintf( /* translators: %d: order lines */ __( '?? %d to identify', 'fishotel-misc-plugin' ), $fh_unknown ) ); ?></span>
				<?php endif; ?>
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

		<?php if ( ! $is_new && ! empty( $batch ) ) : ?>
			<div class="fishotel-misc-card fh-cr-card fh-cr-sale">
				<div class="fishotel-misc-card-header">
					<div class="fishotel-misc-card-icon"><span class="dashicons dashicons-calendar-alt"></span></div>
					<h2 class="fishotel-misc-card-title"><?php esc_html_e( 'Goes on sale', 'fishotel-misc-plugin' ); ?></h2>
				</div>
				<p class="fishotel-misc-card-desc"><?php esc_html_e( 'The day every group of this batch goes on sale. A group with its own date (set in the Clean room box on its product page) keeps that one. Nothing on the shop changes by itself: stock and visibility stay yours to set.', 'fishotel-misc-plugin' ); ?></p>
				<div class="fh-cr-sale__row">
					<label class="screen-reader-text" for="fh-cr-sale-date"><?php esc_html_e( 'Goes on sale', 'fishotel-misc-plugin' ); ?></label>
					<input type="date" id="fh-cr-sale-date" class="fh-cr-input" form="fh-cr-sale-form" name="sale_date" value="<?php echo esc_attr( $batch['date'] ); ?>" min="<?php echo esc_attr( Replay::SALE_DATE_MIN ); ?>" max="<?php echo esc_attr( Replay::sale_date_max( (string) $today ) ); ?>">
					<button type="submit" class="button button-primary" form="fh-cr-sale-form" name="op" value="save"><?php esc_html_e( 'Save', 'fishotel-misc-plugin' ); ?></button>
					<button type="submit" class="button" form="fh-cr-sale-form" name="op" value="clear" <?php disabled( '' === $batch['date'] ); ?>><?php esc_html_e( 'Clear', 'fishotel-misc-plugin' ); ?></button>
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
			</div>
		<?php endif; ?>

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
				<label title="<?php esc_attr_e( 'The target price is the landed cost per fish × this (4 = the sheet\'s "400%").', 'fishotel-misc-plugin' ); ?>"><span><?php esc_html_e( 'Markup (target price)', 'fishotel-misc-plugin' ); ?></span><input type="number" step="0.01" class="fh-cr-input" name="markup" value="<?php echo esc_attr( Costing::num( $order['markup'] ) ); ?>" min="0.01"></label>
				<label title="<?php echo esc_attr( $fh_totals['worth_rule'] . ' ' . __( 'That is a margin (profit ÷ landed cost) above this number.', 'fishotel-misc-plugin' ) ); ?>"><span><?php esc_html_e( 'Worth-it margin', 'fishotel-misc-plugin' ); ?></span><input type="number" step="0.01" class="fh-cr-input" name="worth_threshold" value="<?php echo esc_attr( Costing::num( $order['worth_threshold'] ) ); ?>" min="0"></label>
				<label class="fh-cr-fields-grid__wide"><span><?php esc_html_e( 'Notes', 'fishotel-misc-plugin' ); ?></span><textarea class="fh-cr-input" name="notes" rows="2"><?php echo esc_textarea( $order['notes'] ); ?></textarea></label>
			</div>
			<datalist id="fh-cr-suppliers"><?php foreach ( $pick['suppliers'] as $fh_v ) : ?><option value="<?php echo esc_attr( $fh_v ); ?>"></option><?php endforeach; ?></datalist>
			<datalist id="fh-cr-origins"><?php foreach ( $pick['origins'] as $fh_v ) : ?><option value="<?php echo esc_attr( $fh_v ); ?>"></option><?php endforeach; ?></datalist>
		</div>

		<div class="fh-cr-views" id="fh-cr-views">
			<div class="fh-cr-views__tabs" role="tablist" aria-label="<?php esc_attr_e( 'Two views of the same lines', 'fishotel-misc-plugin' ); ?>">
				<button type="button" class="fh-cr-view-tab<?php echo 'entry' === $fh_view ? ' is-on' : ''; ?>" role="tab" data-view="entry" aria-selected="<?php echo 'entry' === $fh_view ? 'true' : 'false'; ?>" aria-controls="fh-cr-lines">
					<strong><?php esc_html_e( 'Entry', 'fishotel-misc-plugin' ); ?></strong>
					<span><?php esc_html_e( 'what you type: the fish, the counts, the cost, where they went', 'fishotel-misc-plugin' ); ?></span>
				</button>
				<button type="button" class="fh-cr-view-tab<?php echo 'numbers' === $fh_view ? ' is-on' : ''; ?>" role="tab" data-view="numbers" aria-selected="<?php echo 'numbers' === $fh_view ? 'true' : 'false'; ?>" aria-controls="fh-cr-lines">
					<strong><?php esc_html_e( 'Numbers', 'fishotel-misc-plugin' ); ?></strong>
					<span><?php esc_html_e( 'what the site works out: prices, profit, survival', 'fishotel-misc-plugin' ); ?></span>
				</button>
			</div>
			<div class="fh-cr-views__tools fh-cr-c-entry">
				<button type="button" class="button fh-cr-received-all" title="<?php esc_attr_e( 'Fills every EMPTY received field with its ordered count — then correct the few that differ. Nothing is saved until Save.', 'fishotel-misc-plugin' ); ?>"><?php esc_html_e( 'Received: all as ordered', 'fishotel-misc-plugin' ); ?></button>
				<span class="fh-cr-sub fh-cr-sub--inline fh-cr-received-all__said" aria-live="polite"></span>
				<label class="fh-cr-more-switch" title="<?php esc_attr_e( 'The fields that are rarely typed: fish per box, the survivors of an order that was never checked in, the supplier\'s code.', 'fishotel-misc-plugin' ); ?>"><input type="checkbox" class="fh-cr-more-toggle" <?php checked( $fh_more ); ?>> <?php esc_html_e( 'More columns', 'fishotel-misc-plugin' ); ?></label>
			</div>
		</div>

		<div class="fh-cr-numbers-panel fh-cr-c-numbers" id="fh-cr-numbers-panel">
			<div class="fh-cr-kpis">
				<div class="fh-cr-kpi"><span class="fh-cr-kpi__label"><?php esc_html_e( 'Grand total', 'fishotel-misc-plugin' ); ?></span><span class="fh-cr-kpi__value" data-t="grand_total"><?php echo esc_html( $fh_totals['grand_total'] ); ?></span></div>
				<div class="fh-cr-kpi"><span class="fh-cr-kpi__label"><?php esc_html_e( 'Up or down', 'fishotel-misc-plugin' ); ?></span><span class="fh-cr-kpi__value fh-cr-<?php echo esc_attr( $fh_totals['up_down_flag'] ); ?>" data-t="up_down"><?php echo esc_html( $fh_totals['up_down'] ); ?></span></div>
				<div class="fh-cr-kpi"><span class="fh-cr-kpi__label"><?php esc_html_e( 'Revenue', 'fishotel-misc-plugin' ); ?></span><span class="fh-cr-kpi__value" data-t="revenue"><?php echo esc_html( $fh_totals['revenue'] ); ?></span></div>
				<div class="fh-cr-kpi"><span class="fh-cr-kpi__label"><?php esc_html_e( 'Fill rate', 'fishotel-misc-plugin' ); ?></span><span class="fh-cr-kpi__value" data-t="fill_rate"><?php echo esc_html( $fh_totals['fill_rate'] ); ?></span></div>
				<div class="fh-cr-kpi"><span class="fh-cr-kpi__label"><?php esc_html_e( 'Survival', 'fishotel-misc-plugin' ); ?></span><span class="fh-cr-kpi__value" data-t="survival_rate"><?php echo esc_html( $fh_totals['survival_rate'] ); ?></span></div>
				<div class="fh-cr-kpi"><span class="fh-cr-kpi__label"><?php esc_html_e( 'DOA', 'fishotel-misc-plugin' ); ?></span><span class="fh-cr-kpi__value"><span data-t="doa"><?php echo esc_html( $fh_totals['doa'] ); ?></span> · <span data-t="doa_rate"><?php echo esc_html( $fh_totals['doa_rate'] ); ?></span></span></div>
				<div class="fh-cr-kpi" title="<?php esc_attr_e( 'What the DOA fish cost you: the price of each one plus its own share of the box\'s fees. (The old sheet charged every DOA fish a whole box\'s fees; that figure is in the full list under the lines.)', 'fishotel-misc-plugin' ); ?>"><span class="fh-cr-kpi__label"><?php esc_html_e( 'DOA cost', 'fishotel-misc-plugin' ); ?></span><span class="fh-cr-kpi__value" data-t="doa_cost_per_fish"><?php echo esc_html( $fh_totals['doa_cost_per_fish'] ); ?></span></div>
				<div class="fh-cr-kpi"><span class="fh-cr-kpi__label"><?php esc_html_e( 'Credit', 'fishotel-misc-plugin' ); ?></span><span class="fh-cr-kpi__value" data-t="credit"><?php echo esc_html( $fh_totals['credit'] ); ?></span></div>
			</div>
			<p class="fh-cr-numbers-panel__note fh-cr-warn" data-t="no_price" <?php echo '' === $fh_totals['no_price'] ? 'hidden' : ''; ?>><?php echo esc_html( $fh_totals['no_price'] ); ?></p>
			<p class="fh-cr-sub fh-cr-numbers-panel__rule"><span data-t="worth_rule"><?php echo esc_html( $fh_totals['worth_rule'] ); ?></span> <?php esc_html_e( 'The shop price is read from the fish\'s product (else the species list) every time this page loads; the pencil types a price of your own.', 'fishotel-misc-plugin' ); ?></p>
		</div>

		<div class="fh-cr-grid-wrap">
			<table class="widefat fh-cr-table fh-cr-grid" id="fh-cr-lines" data-cols="<?php echo esc_attr( $fh_cols ); ?>" data-split-tanks="<?php echo esc_attr( (string) wp_json_encode( $split['tanks'] ) ); ?>">
				<thead>
					<tr>
						<th scope="col" class="fh-cr-w-n">#</th>
						<th scope="col" class="fh-cr-w-fish"><?php esc_html_e( 'Fish', 'fishotel-misc-plugin' ); ?></th>
						<th scope="col" class="fh-cr-c-entry fh-cr-c-sci fh-cr-w-sci"><?php esc_html_e( 'Sci name', 'fishotel-misc-plugin' ); ?></th>
						<th scope="col" class="fh-cr-c-entry fh-cr-w-size" title="<?php esc_attr_e( 'One of the standard sizes (Settings → Sizes) — the same list the product variations use.', 'fishotel-misc-plugin' ); ?>"><?php esc_html_e( 'Size', 'fishotel-misc-plugin' ); ?></th>
						<th scope="col" class="fh-cr-num fh-cr-c-entry fh-cr-w-qty"><?php esc_html_e( 'Ordered', 'fishotel-misc-plugin' ); ?></th>
						<th scope="col" class="fh-cr-num fh-cr-c-entry fh-cr-w-qty" title="<?php esc_attr_e( 'How many came. Enter or Tab goes down the column.', 'fishotel-misc-plugin' ); ?>"><?php esc_html_e( 'Received', 'fishotel-misc-plugin' ); ?></th>
						<th scope="col" class="fh-cr-num fh-cr-c-entry fh-cr-w-money" title="<?php esc_attr_e( 'The supplier\'s price for one fish (bag mode: for one bag).', 'fishotel-misc-plugin' ); ?>"><?php esc_html_e( 'Cost', 'fishotel-misc-plugin' ); ?></th>
						<th scope="col" class="fh-cr-num fh-cr-c-entry fh-cr-w-qty"><?php esc_html_e( 'DOA', 'fishotel-misc-plugin' ); ?></th>
						<th scope="col" class="fh-cr-num fh-cr-c-entry fh-cr-col--per-box fh-cr-w-qty" title="<?php esc_attr_e( 'Which box the line came out of (optional).', 'fishotel-misc-plugin' ); ?>"><?php esc_html_e( 'Box #', 'fishotel-misc-plugin' ); ?></th>
						<th scope="col" class="fh-cr-num fh-cr-c-entry fh-cr-col--per-box fh-cr-w-pct" title="<?php esc_attr_e( 'About how much of ONE box the whole line took: 25 = a quarter of a box; above 100 when the line spans boxes. Used in place of received ÷ per box.', 'fishotel-misc-plugin' ); ?>"><?php esc_html_e( '% of box', 'fishotel-misc-plugin' ); ?></th>
						<th scope="col" class="fh-cr-num fh-cr-c-entry fh-cr-col--bags fh-cr-w-qty"><?php esc_html_e( 'Bags', 'fishotel-misc-plugin' ); ?></th>
						<th scope="col" class="fh-cr-c-entry fh-cr-w-tank" title="<?php esc_attr_e( 'The Spa tank the line is checked in to ("auto" picks an empty one). Split lays the line out over several tanks.', 'fishotel-misc-plugin' ); ?>"><?php esc_html_e( 'Tank', 'fishotel-misc-plugin' ); ?></th>
						<th scope="col" class="fh-cr-num fh-cr-c-more fh-cr-col--per-box fh-cr-w-qty" title="<?php esc_attr_e( 'How many of this fish fill a box — used only while the line has no % of box.', 'fishotel-misc-plugin' ); ?>"><?php esc_html_e( 'Per box', 'fishotel-misc-plugin' ); ?></th>
						<th scope="col" class="fh-cr-num fh-cr-c-more fh-cr-w-qty" title="<?php esc_attr_e( 'Survivors typed by hand, for an order that was never checked in (history). A checked-in line counts them from its tanks.', 'fishotel-misc-plugin' ); ?>"><?php esc_html_e( 'Srv\'d', 'fishotel-misc-plugin' ); ?></th>
						<th scope="col" class="fh-cr-c-more fh-cr-w-code" title="<?php esc_attr_e( 'The supplier\'s code for this fish, as on their price list and invoice. An arrived invoice is matched by it first.', 'fishotel-misc-plugin' ); ?>"><?php esc_html_e( 'Code', 'fishotel-misc-plugin' ); ?></th>
						<th scope="col" class="fh-cr-num fh-cr-c-numbers fh-cr-w-shop" title="<?php esc_attr_e( 'What the fish sells for: read from its product (else the species list), or typed with the pencil.', 'fishotel-misc-plugin' ); ?>"><?php esc_html_e( 'Shop price', 'fishotel-misc-plugin' ); ?></th>
						<th scope="col" class="fh-cr-num fh-cr-c-numbers fh-cr-w-calc" title="<?php esc_attr_e( 'What one fish cost by the time it was here: its price, its share of the fees and of the tariffs.', 'fishotel-misc-plugin' ); ?>"><?php esc_html_e( 'Landed per fish', 'fishotel-misc-plugin' ); ?></th>
						<th scope="col" class="fh-cr-num fh-cr-c-numbers fh-cr-w-calc" data-t="target_head" title="<?php esc_attr_e( 'The target price: landed per fish × the order\'s markup, to the nearest 5.', 'fishotel-misc-plugin' ); ?>"><?php echo esc_html( $fh_totals['target_head'] ); ?></th>
						<th scope="col" class="fh-cr-num fh-cr-c-numbers fh-cr-w-calc"><?php esc_html_e( 'Profit', 'fishotel-misc-plugin' ); ?></th>
						<th scope="col" class="fh-cr-num fh-cr-c-numbers fh-cr-w-calc"><?php esc_html_e( 'Margin', 'fishotel-misc-plugin' ); ?></th>
						<th scope="col" class="fh-cr-c-numbers fh-cr-w-calc" data-rule="worth_rule" title="<?php echo esc_attr( $fh_totals['worth_rule'] ); ?>"><?php esc_html_e( 'Worth it?', 'fishotel-misc-plugin' ); ?></th>
						<th scope="col" class="fh-cr-num fh-cr-c-numbers fh-cr-w-calc" title="<?php esc_attr_e( 'Shop price × survivors.', 'fishotel-misc-plugin' ); ?>"><?php esc_html_e( 'Revenue', 'fishotel-misc-plugin' ); ?></th>
						<th scope="col" class="fh-cr-num fh-cr-c-numbers fh-cr-w-count"><?php esc_html_e( 'Survived', 'fishotel-misc-plugin' ); ?></th>
						<th scope="col" class="fh-cr-num fh-cr-c-numbers fh-cr-w-count"><?php esc_html_e( 'Live', 'fishotel-misc-plugin' ); ?></th>
						<th scope="col" class="fh-cr-num fh-cr-c-numbers fh-cr-w-count"><?php esc_html_e( 'Sold', 'fishotel-misc-plugin' ); ?></th>
						<th scope="col" class="fh-cr-c-entry fh-cr-w-x"></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $order['lines'] as $fh_i => $fh_line ) : ?>
						<?php $fh_line_row( (int) $fh_i, $fh_line, isset( $figures['lines'][ $fh_i ] ) ? $figures['lines'][ $fh_i ] : array() ); ?>
					<?php endforeach; ?>
				</tbody>
				<tfoot>
					<tr>
						<th></th>
						<th><?php esc_html_e( 'Totals', 'fishotel-misc-plugin' ); ?></th>
						<th class="fh-cr-c-entry fh-cr-c-sci"></th>
						<th class="fh-cr-c-entry"></th>
						<th class="fh-cr-num fh-cr-c-entry" data-t="ordered"><?php echo esc_html( $fh_totals['ordered'] ); ?></th>
						<th class="fh-cr-num fh-cr-c-entry" data-t="received"><?php echo esc_html( $fh_totals['received'] ); ?></th>
						<th class="fh-cr-c-entry"></th>
						<th class="fh-cr-num fh-cr-c-entry" data-t="doa"><?php echo esc_html( $fh_totals['doa'] ); ?></th>
						<th class="fh-cr-c-entry fh-cr-col--per-box"></th>
						<th class="fh-cr-num fh-cr-c-entry fh-cr-col--per-box" data-box="pct_total"><?php echo esc_html( $boxes['summary']['lines'] > 0 ? Costing::num( $boxes['summary']['pct_total'], '', 2 ) . '%' : '' ); ?></th>
						<th class="fh-cr-c-entry fh-cr-col--bags"></th>
						<th class="fh-cr-c-entry"></th>
						<th class="fh-cr-c-more fh-cr-col--per-box"></th>
						<th class="fh-cr-num fh-cr-c-more" data-t="survived"><?php echo esc_html( $fh_totals['survived'] ); ?></th>
						<th class="fh-cr-c-more"></th>
						<th class="fh-cr-c-numbers"></th>
						<th class="fh-cr-num fh-cr-c-numbers" data-t="total" title="<?php esc_attr_e( 'What every line cost landed, together.', 'fishotel-misc-plugin' ); ?>"><?php echo esc_html( $fh_totals['total'] ); ?></th>
						<th class="fh-cr-c-numbers"></th>
						<th class="fh-cr-c-numbers"></th>
						<th class="fh-cr-c-numbers"></th>
						<th class="fh-cr-c-numbers"></th>
						<th class="fh-cr-num fh-cr-c-numbers" data-t="revenue"><?php echo esc_html( $fh_totals['revenue'] ); ?></th>
						<th class="fh-cr-num fh-cr-c-numbers" data-t="survived"><?php echo esc_html( $fh_totals['survived'] ); ?></th>
						<th class="fh-cr-c-numbers"></th>
						<th class="fh-cr-c-numbers"></th>
						<th class="fh-cr-c-entry"></th>
					</tr>
				</tfoot>
			</table>
		</div>
		<template id="fh-cr-line-template"><?php $fh_line_row( '__i__', array(), array() ); ?></template>
		<p class="fh-cr-actions-row">
			<button type="button" class="button fh-cr-line-add fh-cr-c-entry"><?php esc_html_e( 'Add line', 'fishotel-misc-plugin' ); ?></button>
			<button type="button" class="button fh-cr-import-open fh-cr-c-entry" aria-expanded="false" aria-controls="fh-cr-import" title="<?php esc_attr_e( 'Paste the rows of your order sheet or of the supplier\'s invoice, or pick a file, instead of typing the lines one by one.', 'fishotel-misc-plugin' ); ?>"><?php esc_html_e( 'Import lines…', 'fishotel-misc-plugin' ); ?></button>
			<span class="fh-cr-sub fh-cr-sub--inline fh-cr-c-entry"><?php esc_html_e( 'Type a fish for suggestions (products and the species list); picking one fills its scientific name. Tick ?? for a fish nobody knows yet — what you type is then what it looks like. Enter goes down a column; in Received, DOA, Box # and % of box so does Tab. Nothing is saved until Save.', 'fishotel-misc-plugin' ); ?></span>
			<span class="fh-cr-sub fh-cr-sub--inline fh-cr-c-numbers"><?php esc_html_e( 'These figures are worked out when the order is saved; the Entry view is where the lines are typed.', 'fishotel-misc-plugin' ); ?></span>
		</p>

		<?php include Clean_Room::path() . 'views/order-import.php'; ?>

		<div class="fishotel-misc-card fh-cr-card fh-cr-boxes" id="fh-cr-boxes" data-shares="<?php echo esc_attr( (string) wp_json_encode( $boxes['shares'] ) ); ?>">
			<div class="fishotel-misc-card-header">
				<div class="fishotel-misc-card-icon"><span class="dashicons dashicons-archive"></span></div>
				<h2 class="fishotel-misc-card-title"><?php esc_html_e( 'Boxes', 'fishotel-misc-plugin' ); ?></h2>
			</div>
			<p class="fishotel-misc-card-desc"><?php esc_html_e( 'While unloading, type the box a line came out of and about how much of that box the whole line took (25 = a quarter of a box). The fees are split by each fish\'s share of the total, so the percents only need to be right compared to each other: if you guess everything a little high, the costs still come out the same. That holds once every line that received fish has a percent — a line without one keeps its per-box count, and the card counts those.', 'fishotel-misc-plugin' ); ?></p>
			<div class="fh-cr-summary fh-cr-boxes__list" data-box="rows">
				<?php foreach ( $boxes['rows'] as $fh_row ) : ?>
					<span class="fh-cr-chip fh-cr-chip--box-<?php echo esc_attr( $fh_row['state'] ); ?>"><?php echo esc_html( $fh_row['text'] ); ?></span>
				<?php endforeach; ?>
			</div>
			<p class="fh-cr-boxes__total" data-box="total"><?php echo esc_html( $boxes['total'] ); ?></p>
			<p class="fh-cr-boxes__full" data-box="how_full" <?php echo '' === $boxes['how_full'] ? 'hidden' : ''; ?>><?php echo esc_html( $boxes['how_full'] ); ?></p>
		</div>

		<div class="fishotel-misc-card fh-cr-card fh-cr-order__stats fh-cr-c-numbers">
			<div class="fishotel-misc-card-header">
				<div class="fishotel-misc-card-icon"><span class="dashicons dashicons-chart-bar"></span></div>
				<h2 class="fishotel-misc-card-title"><?php esc_html_e( 'This order', 'fishotel-misc-plugin' ); ?></h2>
			</div>
			<dl class="fh-cr-stats-block">
				<div><dt><?php esc_html_e( 'Total', 'fishotel-misc-plugin' ); ?></dt><dd data-t="total"><?php echo esc_html( $fh_totals['total'] ); ?></dd></div>
				<div><dt><?php esc_html_e( 'Credit', 'fishotel-misc-plugin' ); ?></dt><dd data-t="credit"><?php echo esc_html( $fh_totals['credit'] ); ?></dd></div>
				<div><dt><?php esc_html_e( 'Grand total', 'fishotel-misc-plugin' ); ?></dt><dd data-t="grand_total"><?php echo esc_html( $fh_totals['grand_total'] ); ?></dd></div>
				<div><dt><?php esc_html_e( 'Revenue (survivors at shop prices)', 'fishotel-misc-plugin' ); ?></dt><dd data-t="revenue"><?php echo esc_html( $fh_totals['revenue'] ); ?></dd></div>
				<div><dt><?php esc_html_e( 'Up or down', 'fishotel-misc-plugin' ); ?></dt><dd data-t="up_down" class="fh-cr-<?php echo esc_attr( $fh_totals['up_down_flag'] ); ?>"><?php echo esc_html( $fh_totals['up_down'] ); ?></dd></div>
				<div><dt><?php esc_html_e( 'Revenue ÷ what those fish cost', 'fishotel-misc-plugin' ); ?></dt><dd data-t="ratio"><?php echo esc_html( $fh_totals['ratio'] ); ?></dd></div>
				<div><dt><?php esc_html_e( 'Total critters (received)', 'fishotel-misc-plugin' ); ?></dt><dd data-t="received"><?php echo esc_html( $fh_totals['received'] ); ?></dd></div>
				<div><dt><?php esc_html_e( 'Fill rate', 'fishotel-misc-plugin' ); ?></dt><dd data-t="fill_rate"><?php echo esc_html( $fh_totals['fill_rate'] ); ?></dd></div>
				<div><dt><?php esc_html_e( 'Survival rate', 'fishotel-misc-plugin' ); ?></dt><dd data-t="survival_rate"><?php echo esc_html( $fh_totals['survival_rate'] ); ?></dd></div>
				<div><dt><?php esc_html_e( 'DOA rate', 'fishotel-misc-plugin' ); ?></dt><dd data-t="doa_rate"><?php echo esc_html( $fh_totals['doa_rate'] ); ?></dd></div>
				<div><dt><?php esc_html_e( 'DOA cost (each fish\'s price plus its own share of the fees)', 'fishotel-misc-plugin' ); ?></dt><dd data-t="doa_cost_per_fish"><?php echo esc_html( $fh_totals['doa_cost_per_fish'] ); ?></dd></div>
				<div><dt><?php esc_html_e( 'DOA cost as the old sheet counted it (a whole box\'s fees for every DOA fish)', 'fishotel-misc-plugin' ); ?></dt><dd data-t="doa_cost_sheet"><?php echo esc_html( $fh_totals['doa_cost_sheet'] ); ?></dd></div>
				<div><dt data-t="fee_label"><?php echo esc_html( $fh_totals['fee_label'] ); ?></dt><dd data-t="fee_rate"><?php echo esc_html( $fh_totals['fee_rate'] ); ?></dd></div>
				<div><dt data-t="units_label"><?php echo esc_html( $fh_totals['units_label'] ); ?></dt><dd data-t="units"><?php echo esc_html( $fh_totals['units'] ); ?></dd></div>
			</dl>
		</div>
	</form>

	<?php
	$fh_identify_colspan = $fh_cols;
	include Clean_Room::path() . 'views/identify-panel.php';
	?>

	<p class="fh-cr-footnote">
		<?php esc_html_e( 'Box mode: each line takes a fraction of a box (its % of box ÷ 100 when one is typed, else received ÷ per box — the line\'s, else the species list\'s, else 24); the domestic flight and the import & re-ox fees are shared by box space, the tariffs by goods value. Bag mode: bags (else received) × cost, plus the flight, import fees and tariffs per bag. Landed per fish × markup, to the nearest 5, is the target price.', 'fishotel-misc-plugin' ); ?>
		<?php echo esc_html( $fh_totals['worth_rule'] ); ?>
		<?php esc_html_e( 'A fish with no shop price yet says so: it is not counted as a loss, and it is left out of the revenue and of "up or down". Checking in creates one stay per line with received fish (the DOA as a loss) — a line with a split goes on into its other tanks, one move per tank, as if it were done by hand — and the order becomes "received"; from then on a line\'s DOA, survived, live and sold come from its tanks, all of them, and its received count is kept as typed. A fish that is not what its label said: "Correct the fish…" on a line that received fish names the real one — the line keeps what the label said ("arrived labeled"), its stays follow, and the remembered box share is written for the corrected fish; once a line is a stay, changing its species, its ?? box or its size here does the same. A fish that still needs identifying is never remembered as a box share.', 'fishotel-misc-plugin' ); ?>
	</p>
</div>
