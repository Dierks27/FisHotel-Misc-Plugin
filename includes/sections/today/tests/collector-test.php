<?php
/**
 * Collector tests — the window built from fake orders: one query, the
 * wrapper as one destination, folded and dead sources skipped, the
 * invoice and tracking columns with and without their plugins, caching.
 * No WordPress, no database, no sibling plugin.
 *
 * Run from the plugin root:
 *   php includes/sections/today/tests/collector-test.php
 *
 * "Today" is pinned to Mon 2025-10-13 (site time), so the window is
 * 2025-10-14 … 2025-11-03.
 *
 * @package FisHotel\Misc\Sections\Today
 */

require_once __DIR__ . '/wp-stubs.php';
require_once __DIR__ . '/fake-order.php';

use FisHotel\Misc\Sections\Today\Collector;
use FisHotel\Misc\Sections\Today\Model;
use FisHotel\Misc\Sections\Today\Settings;
use FisHotel\Misc\Sections\Weather\Checker;

$GLOBALS['fh_now'] = '2025-10-13 08:00:00';

function fh_find( array $destinations, $number ) {
	foreach ( $destinations as $d ) {
		if ( (string) $number === $d['number'] ) {
			return $d;
		}
	}
	return null;
}

/* ---------------------------------------------------------------- */
/*  Fixture                                                          */
/* ---------------------------------------------------------------- */
fh_reset_orders();

$fish_product = new WC_Product( 11, array( 'quarantined-fish' ) );
$invert       = new WC_Product( 12, array( 'inverts' ) );
$variation    = new WC_Product( 13, array(), 11 ); // A variation of the fish product: categories live on the parent.
$dry_product  = new WC_Product( 14, array( 'supplies' ) );

// A combined box: wrapper 300 with sources 301 (live), 302 (live), 303 (cancelled), 399 (does not load).
$o300 = fh_register_order( new WC_Order( 300, 'fulfillment', array(
	Checker::META_DELIVERY_DATE  => '2025-10-14',
	Checker::META_IS_FULFILLMENT => '1',
	Checker::META_SOURCES        => '301,302,303,399',
), array( 'first_name' => '', 'last_name' => '', 'address_1' => '1 Main St', 'address_2' => '', 'city' => 'Austin', 'state' => 'TX', 'postcode' => '78701', 'country' => 'US' ) ) );
$o300->add_item( 'Copy of fish', 2, $fish_product ); // The theme copies source lines onto the wrapper — must NOT be double counted.

$o301 = fh_register_order( new WC_Order( 301, 'processing', array( Checker::META_DELIVERY_DATE => '2025-10-14', Checker::META_FULFILLED_BY => '300' ) ) );
$o301->add_item( 'Clownfish', 2, $fish_product );
$o301->add_item( 'Cleaner shrimp', 1, $invert );
$o301->customer_note = 'Leave at the side door';

$o302 = fh_register_order( new WC_Order( 302, 'processing', array( Checker::META_DELIVERY_DATE => '2025-10-14', Checker::META_FULFILLED_BY => '300' ) ) );
$o302->add_item( 'Royal gramma (variation)', 3, $variation );
$o302->add_item( 'Shipped already', 5, $fish_product, array( '_fishotel_line_status' => 'shipped' ) );
$o302->add_item( 'Not applicable', 5, $fish_product, array( '_fishotel_line_status' => 'na' ) );

$o303 = fh_register_order( new WC_Order( 303, 'cancelled', array( Checker::META_DELIVERY_DATE => '2025-10-14', Checker::META_FULFILLED_BY => '300' ) ) );
$o303->add_item( 'Dead source fish', 9, $fish_product );

// A regular order delivering the 14th: 3 fish + 1 dry, one line fully refunded, one partly.
$o310 = fh_register_order( new WC_Order( 310, 'processing', array(
	Checker::META_DELIVERY_DATE => '2025-10-14',
	Checker::META_CHECKED_FOR   => '2025-10-14',
	Checker::META_ACTION        => 'heat_pack',
	Checker::META_HIGH          => '55',
	Checker::META_LOW           => '41',
	Checker::META_SUMMARY       => 'High 55°F / Low 41°F — Cloudy',
) ) );
$o310->add_item( 'Firefish', 2, $fish_product );
$o310->add_item( 'Goby', 2, $fish_product, array(), 1 );          // 2 − 1 refunded = 1
$o310->add_item( 'Refunded fish', 1, $fish_product, array(), 1 ); // fully refunded → skipped
$o310->add_item( 'Net', 1, $dry_product );
$o310->customer_note = 'Please ring the bell';

// A regular order delivering the 14th with a UPS Store band — must sort first.
$o311 = fh_register_order( new WC_Order( 311, 'processing', array(
	Checker::META_DELIVERY_DATE => '2025-10-14',
	Checker::META_CHECKED_FOR   => '2025-10-14',
	Checker::META_ACTION        => 'ups_store_hot',
	Checker::META_HIGH          => '93',
) ) );
$o311->add_item( 'Tang', 1, $fish_product );

// The 15th: on hold, weather says HOLD.
$o320 = fh_register_order( new WC_Order( 320, 'on-hold', array(
	Checker::META_DELIVERY_DATE => '2025-10-15',
	Checker::META_CHECKED_FOR   => '2025-10-15',
	Checker::META_ACTION        => 'hold_hot',
	Checker::META_HIGH          => '99',
) ) );
$o320->add_item( 'Wrasse', 4, $fish_product );

// The 20th and the 22nd: one order each, beyond dates_shown = 3 when the 14th, 15th and 20th fill the blocks.
$o330 = fh_register_order( new WC_Order( 330, 'processing', array( Checker::META_DELIVERY_DATE => '2025-10-20' ) ) );
$o330->add_item( 'Blenny', 1, $fish_product );
$o340 = fh_register_order( new WC_Order( 340, 'processing', array( Checker::META_DELIVERY_DATE => '2025-10-22' ) ) );
$o340->add_item( 'Blenny', 1, $fish_product );

// Excluded: completed, no date, today, past, outside the window.
fh_register_order( new WC_Order( 350, 'completed', array( Checker::META_DELIVERY_DATE => '2025-10-14' ) ) )->add_item( 'Done fish', 1, $fish_product );
fh_register_order( new WC_Order( 351, 'processing', array() ) )->add_item( 'Undated fish', 1, $fish_product );
fh_register_order( new WC_Order( 352, 'processing', array( Checker::META_DELIVERY_DATE => '2025-10-13' ) ) )->add_item( 'Today fish', 1, $fish_product );
fh_register_order( new WC_Order( 353, 'processing', array( Checker::META_DELIVERY_DATE => '2025-10-10' ) ) )->add_item( 'Past fish', 1, $fish_product );
fh_register_order( new WC_Order( 354, 'processing', array( Checker::META_DELIVERY_DATE => '2025-11-20' ) ) )->add_item( 'Far fish', 1, $fish_product );

$settings = Settings::get();

/* ---------------------------------------------------------------- */
echo "1. Environment without the sibling plugins\n";
check( 'today comes from current_time() without the Batch plugin', '2025-10-13' === Collector::today() );
check( 'site zone', 'America/Chicago' === Collector::timezone()->getName() );
check( 'no Ship Day class, no ShipTracker', ! Collector::has_ship_day() && ! Collector::has_shiptracker() );

$columns = Collector::columns( $settings );
check( 'weather column shown', true === $columns['weather'] );
check( 'invoice column hidden without the PDF plugin, with the note', false === $columns['invoice'] && 'Invoice PDF check needs the PDF Invoices plugin' === $columns['notes']['invoice'] );
check( 'tracking column hidden without ShipTracker, with a note', false === $columns['tracking'] && false !== strpos( $columns['notes']['tracking'], 'ShipTracker' ) );
check( 'toggles off hide without a note', array() === Collector::columns( array( 'show_weather' => false, 'show_invoice' => false, 'show_tracking' => false ) )['notes'] );

/* ---------------------------------------------------------------- */
echo "2. The window\n";
$GLOBALS['fh_order_queries'] = 0;
$data = Collector::collect( $settings );
check( 'ONE order query for the whole window', 1 === $GLOBALS['fh_order_queries'], (string) $GLOBALS['fh_order_queries'] );
check( 'today and the window', '2025-10-13' === $data['today'] && 21 === count( $data['window'] ) && '2025-10-14' === $data['window'][0] && '2025-11-03' === $data['window'][20] );
check( 'dates with orders and their counts (folded sources not counted)', array( '2025-10-14' => 3, '2025-10-15' => 1, '2025-10-20' => 1, '2025-10-22' => 1 ) === $data['counts'], json_encode( $data['counts'] ) );
check( 'the first three dates are laid out', array( '2025-10-14', '2025-10-15', '2025-10-20' ) === array_keys( $data['blocks'] ) );
check( 'ships today for the 14th', true === $data['plan']['ships_today'] && '2025-10-14' === $data['plan']['delivery'] && '2025-10-15' === $data['plan']['next']['delivery'] );
check( 'ship date per block', '2025-10-13' === $data['blocks']['2025-10-14']['ship'] && '2025-10-19' === $data['blocks']['2025-10-20']['ship'] );
check( 'chips per date include the dates beyond the blocks (orders only)', 3 === $data['chips']['2025-10-14']['orders'] && array( 'orders' => 1 ) === $data['chips']['2025-10-22'] );
check( 'header line', 'Today, Mon Oct 13 — ship day for Tue Oct 14 delivery · 3 orders · 10 fish' === Model::header_line( $data['today'], $data['plan'], $data['chips'], $data['lookahead'], Collector::timezone() ), Model::header_line( $data['today'], $data['plan'], $data['chips'], $data['lookahead'], Collector::timezone() ) );

$dests = $data['blocks']['2025-10-14']['destinations'];
check( 'the 14th has three destinations: 311 (UPS Store), 300 (not checked), 310 (heat pack) — in that order', '311,300,310' === implode( ',', array_column( $dests, 'number' ) ), implode( ',', array_column( $dests, 'number' ) ) );
check( 'folded sources 301 / 302 / 303 are not destinations', null === fh_find( $dests, 301 ) && null === fh_find( $dests, 302 ) && null === fh_find( $dests, 303 ) );

/* ---------------------------------------------------------------- */
echo "3. The wrapper is ONE destination\n";
$w = fh_find( $dests, 300 );
check( 'combined label from the live sources only (303 dead, 399 missing)', 'Order #300 (combined: #301, #302)' === $w['label'], $w['label'] );
check( 'is_fulfillment with the source numbers and ids', true === $w['is_fulfillment'] && array( '301', '302' ) === $w['sources'] && array( 301, 302 ) === $w['source_ids'] );
check( 'fish counted from the live sources, not the wrapper copies: 2 + 1 + 3 (shipped / na lines skipped, variation via its parent)', 6 === $w['fish'] && 0 === $w['dry'], json_encode( array( $w['fish'], $w['dry'] ) ) );
check( 'destination from the wrapper address, name falls back to Guest', 'Austin, TX' === $w['place'] && 'Guest' === $w['customer'] );
check( 'status label', 'fulfillment' === $w['status'] && 'Fulfillment' === $w['status_label'] );
check( 'weather not checked on the wrapper', 'not_checked' === $w['weather']['status'] );
check( 'the note icon fires from a live source (301 has a note; the wrapper never does)', true === $w['has_note'] );
check( 'edit URL', false !== strpos( $w['edit_url'], 'id=300' ) );
$o301->customer_note = '';
check( 'no note on the wrapper or any live source → no icon', false === Collector::destination( $o300, '2025-10-14', $columns )['has_note'] );
$o303->customer_note = 'Dead source note';
check( 'a note on a dead source does not count', false === Collector::destination( $o300, '2025-10-14', $columns )['has_note'] );
$o303->customer_note = '';
$o301->customer_note = 'Leave at the side door';

/* ---------------------------------------------------------------- */
echo "4. A regular destination\n";
$r = fh_find( $dests, 310 );
check( 'fish 3 (2 + 1 after a partial refund; the fully refunded line skipped), dry 1', 3 === $r['fish'] && 1 === $r['dry'], json_encode( array( $r['fish'], $r['dry'] ) ) );
check( 'customer from the shipping name', 'Dana Reed' === $r['customer'] && 'Carrollton, VA' === $r['place'] );
check( 'weather checked for the date: heat pack, 55°F', 'checked' === $r['weather']['status'] && 'heat_pack' === $r['weather']['action'] && 55 === $r['weather']['high'] );
check( 'customer note present', true === $r['has_note'] );
check( 'invoice and tracking are null while their columns are hidden', null === $r['invoice'] && null === $r['tracking'] );
check( 'delivery date carried', '2025-10-14' === $r['delivery_date'] );

$block = $data['blocks']['2025-10-14'];
check( 'chips: 3 orders, 10 fish, 1 dry, 1 needs weather, 1 UPS Store', 3 === $block['chips']['orders'] && 10 === $block['chips']['fish'] && 1 === $block['chips']['dry'] && 1 === $block['chips']['needs_weather'] && 1 === $block['chips']['ups_store'] && 0 === $block['chips']['needs_invoice'], json_encode( $block['chips'] ) );
check( 'attention: 311 (UPS Store) then 300 (not checked); 310 is fine', '311,300' === implode( ',', array_column( $block['attention'], 'number' ) ) );
check( 'the 15th: hold first in its chips', 1 === $data['blocks']['2025-10-15']['chips']['holds'] && 'hold' === $data['blocks']['2025-10-15']['attention'][0]['kind'] );

/* ---------------------------------------------------------------- */
echo "4b. The Weather column toggled off\n";
$no_weather = $settings;
$no_weather['show_weather'] = false;
$nw = Collector::collect( $no_weather );
check( 'columns: weather off', false === $nw['columns']['weather'] );
$nw_block = $nw['blocks']['2025-10-14'];
check( 'rows by order number only (no weather rank)', '300,310,311' === implode( ',', array_column( $nw_block['destinations'], 'number' ) ), implode( ',', array_column( $nw_block['destinations'], 'number' ) ) );
check( 'no weather reason in the attention strip (invoice / tracking hidden too → empty)', array() === $nw_block['attention'], json_encode( $nw_block['attention'] ) );
check( 'weather chips stay 0', 0 === $nw_block['chips']['needs_weather'] && 0 === $nw_block['chips']['ups_store'] && 0 === $nw['blocks']['2025-10-15']['chips']['holds'] );
check( 'the weather state is still on the destination (widget badge, cache key)', 'ups_store_hot' === fh_find( $nw_block['destinations'], 311 )['weather']['action'] );

/* ---------------------------------------------------------------- */
echo "4c. Active NWS alerts from the weather note\n";
$o312 = fh_register_order( new WC_Order( 312, 'processing', array(
	Checker::META_DELIVERY_DATE => '2025-10-14',
	Checker::META_CHECKED_FOR   => '2025-10-14',
	Checker::META_ACTION        => 'none',
	Checker::META_HIGH          => '76',
	Checker::META_LOW           => '60',
	Checker::META_SUMMARY       => 'High 76°F / Low 60°F — Rain',
	Checker::META_NOTE          => 'Weather for Tuesday, October 14 at 23314 (Carrollton, VA): High 76°F / Low 60°F — Rain. Alerts: Flood Watch, Wind Advisory. Nothing needed.',
) ) );
$o312->add_item( 'Chromis', 1, $fish_product );
$d312 = Collector::destination( $o312, '2025-10-14', $columns );
check( 'weather_meta() reads the note and the state carries the alerts', array( 'FLOOD WATCH', 'WIND ADVISORY' ) === $d312['weather']['alerts'] && 'none' === $d312['weather']['action'], json_encode( $d312['weather'] ) );
$alert_block = Collector::collect( $settings )['blocks']['2025-10-14'];
$alert_item  = null;
foreach ( $alert_block['attention'] as $item ) {
	if ( '312' === $item['number'] ) {
		$alert_item = $item;
	}
}
check( 'a NOTHING badge with a Flood Watch still needs attention, after UPS Store, before not checked', null !== $alert_item && 'alerts' === $alert_item['kind'] && array( 'FLOOD WATCH, WIND ADVISORY' ) === $alert_item['reasons'] && '311,312,300' === implode( ',', array_column( $alert_block['attention'], 'number' ) ), json_encode( $alert_block['attention'] ) );
check( '…and not with the Weather column off', ! in_array( '312', array_column( Collector::collect( $no_weather )['blocks']['2025-10-14']['attention'], 'number' ), true ) );
unset( $GLOBALS['fh_orders'][312] );

/* ---------------------------------------------------------------- */
echo "5. A wrapper whose sources cannot be loaded, and one whose sources are all dead\n";
$o400 = fh_register_order( new WC_Order( 400, 'fulfillment', array( Checker::META_DELIVERY_DATE => '2025-10-16', Checker::META_IS_FULFILLMENT => '1', Checker::META_SOURCES => '998,999' ) ) );
$o400->add_item( 'Copied fish', 4, $fish_product );
$d400 = Collector::destination( $o400, '2025-10-16', $columns );
check( 'no source loads → the wrapper\'s own copied lines count', 4 === $d400['fish'] && 'Order #400' === $d400['label'] && array() === $d400['sources'] );

$o410 = fh_register_order( new WC_Order( 410, 'fulfillment', array( Checker::META_DELIVERY_DATE => '2025-10-16', Checker::META_IS_FULFILLMENT => '1', Checker::META_SOURCES => '303' ) ) );
$o410->add_item( 'Copied dead fish', 9, $fish_product );
$d410 = Collector::destination( $o410, '2025-10-16', $columns );
check( 'every source dead → nothing to pack, copies ignored', 0 === $d410['fish'] && 0 === $d410['dry'] && array() === $d410['sources'] );

/* ---------------------------------------------------------------- */
echo "6. Invoice column\n";
check( 'default keys', array( '_wcpdf_invoice_date', '_fishotel_invoice_printed_at' ) === Collector::invoice_meta_keys() );
check( 'not available without the PDF plugin', false === Collector::invoice_available() );

add_filter( 'fishotel_today_invoice_meta_keys', static function ( $keys ) { $keys[] = '_my_invoice_flag'; return $keys; } );
check( 'a filter key of its own makes the column available without the PDF plugin', true === Collector::invoice_available() && in_array( '_my_invoice_flag', Collector::invoice_meta_keys(), true ) );
$cols = Collector::columns( $settings );
check( '…and the column shows with no note', true === $cols['invoice'] && ! isset( $cols['notes']['invoice'] ) );
$o310->update_meta_data( '_my_invoice_flag', '1' );
check( 'the custom key marks the invoice done', true === Collector::destination( $o310, '2025-10-14', $cols )['invoice']['done'] );
$o310->delete_meta_data( '_my_invoice_flag' );
remove_all_filters( 'fishotel_today_invoice_meta_keys' );
check( 'filter removed → hidden again', false === Collector::invoice_available() );

// Declared at runtime so the "absent" checks above ran first.
if ( ! class_exists( 'WPO_WCPDF' ) ) {
	class WPO_WCPDF {}
}
check( 'with the PDF plugin class the column is available', true === Collector::invoice_available() );
$cols = Collector::columns( $settings );
check( 'columns: invoice on, no note', true === $cols['invoice'] && ! isset( $cols['notes']['invoice'] ) );

$d310 = Collector::destination( $o310, '2025-10-14', $cols );
check( 'no invoice meta → open', array( 'done' => false, 'where' => '' ) === $d310['invoice'] );
$o310->update_meta_data( '_wcpdf_invoice_date', 1760400000 );
check( 'WPO timestamp → done on the order', true === Collector::destination( $o310, '2025-10-14', $cols )['invoice']['done'] && 'order' === Collector::destination( $o310, '2025-10-14', $cols )['invoice']['where'] );
$o310->update_meta_data( '_wcpdf_invoice_date', '0' );
check( 'a "0" marker is not done', false === Collector::destination( $o310, '2025-10-14', $cols )['invoice']['done'] );
$o310->delete_meta_data( '_wcpdf_invoice_date' );
$o310->update_meta_data( '_fishotel_invoice_printed_at', '2025-10-13 07:55:00' );
check( 'the FisHotel printed-at key counts too', true === Collector::destination( $o310, '2025-10-14', $cols )['invoice']['done'] );

$d300 = Collector::destination( $o300, '2025-10-14', $cols );
check( 'wrapper: nothing on the wrapper or its sources → open', false === $d300['invoice']['done'] );
$o301->update_meta_data( '_wcpdf_invoice_date', 1760400000 );
check( 'wrapper: one live source only → still open', false === Collector::destination( $o300, '2025-10-14', $cols )['invoice']['done'] );
$o302->update_meta_data( '_wcpdf_invoice_date', 1760400001 );
$d300 = Collector::destination( $o300, '2025-10-14', $cols );
check( 'wrapper: every live source has it → done from the sources (dead 303 ignored)', true === $d300['invoice']['done'] && 'sources' === $d300['invoice']['where'] );
$o302->delete_meta_data( '_wcpdf_invoice_date' );
$o300->update_meta_data( '_wcpdf_invoice_date', 1760400002 );
check( 'wrapper: the wrapper\'s own marker is enough', 'order' === Collector::destination( $o300, '2025-10-14', $cols )['invoice']['where'] );
$o300->delete_meta_data( '_wcpdf_invoice_date' );

/* ---------------------------------------------------------------- */
echo "7. Tracking column\n";
check( 'hidden while FST_Shipment is absent', false === Collector::columns( $settings )['tracking'] && null === Collector::destination( $o310, '2025-10-14', Collector::columns( $settings ) )['tracking'] );

if ( ! class_exists( 'FST_Shipment' ) ) {
	/**
	 * Just enough ShipTracker: rows per order, newest first.
	 */
	class FST_Shipment {
		public static $rows = array();
		public static function get_by_order( $order_id ) {
			$rows = self::$rows[ (int) $order_id ] ?? array();
			usort( $rows, static function ( $a, $b ) { return strcmp( $b->created_at, $a->created_at ); } );
			return $rows;
		}
	}
}
function fh_ship( $order_id, $status, $created_at, $tracking = null ) {
	FST_Shipment::$rows[ $order_id ][] = (object) array(
		'id'              => count( FST_Shipment::$rows, COUNT_RECURSIVE ) + 1,
		'order_id'        => $order_id,
		'tracking_number' => null === $tracking ? '1Z' . $order_id : $tracking,
		'status'          => $status,
		'created_at'      => $created_at,
	);
}

$cols = Collector::columns( $settings );
check( 'with ShipTracker the column shows, no note', true === $cols['tracking'] && ! isset( $cols['notes']['tracking'] ) );
check( 'no rows → open, count 0', array( 'done' => false, 'count' => 0, 'status' => '', 'status_label' => '' ) === Collector::destination( $o310, '2025-10-14', $cols )['tracking'] );

fh_ship( 310, 'label_created', '2025-10-13 07:00:00' );
fh_ship( 310, 'in_transit', '2025-10-13 09:00:00' );
$t = Collector::destination( $o310, '2025-10-14', $cols )['tracking'];
check( 'rows → done, count 2, newest status with its label (no FST_Carrier → ucwords)', true === $t['done'] && 2 === $t['count'] && 'in_transit' === $t['status'] && 'In Transit' === $t['status_label'], json_encode( $t ) );

$t = Collector::destination( $o300, '2025-10-14', $cols )['tracking'];
check( 'wrapper with nothing anywhere → open', false === $t['done'] && 0 === $t['count'] );
fh_ship( 301, 'pre_transit', '2025-10-13 09:30:00' );
$t = Collector::destination( $o300, '2025-10-14', $cols )['tracking'];
check( 'wrapper: one live source tracked, the other not → open but counted', false === $t['done'] && 1 === $t['count'] && 'Pre Transit' === $t['status_label'] );
fh_ship( 302, 'pre_transit', '2025-10-13 09:31:00' );
$t = Collector::destination( $o300, '2025-10-14', $cols )['tracking'];
check( 'wrapper: every live source tracked with distinct numbers → done, count 2 = two boxes (dead 303 ignored)', true === $t['done'] && 2 === $t['count'] );

// The real fan-out shape: ShipTracker writes ONE label to a row per source order.
FST_Shipment::$rows = array();
fh_ship( 301, 'label_created', '2025-10-13 09:40:00', '1Z50W72W0115498880' );
fh_ship( 302, 'label_created', '2025-10-13 09:40:01', '1z50w72w0115498880 ' ); // same number, case / whitespace differ
$t = Collector::destination( $o300, '2025-10-14', $cols )['tracking'];
check( 'wrapper: one label fanned out to both sources → done, count 1 (one physical box)', true === $t['done'] && 1 === $t['count'] && 'label_created' === $t['status'], json_encode( $t ) );
fh_ship( 302, 'in_transit', '2025-10-13 11:00:00', '1Z50W72W0115498881' );
$t = Collector::destination( $o300, '2025-10-14', $cols )['tracking'];
check( 'a second number on one source → count 2, newest status', 2 === $t['count'] && 'in_transit' === $t['status'] );
fh_ship( 301, 'pre_transit', '2025-10-13 12:00:00', '' );
check( 'a row without a number still counts (keyed by id)', 3 === Collector::destination( $o300, '2025-10-14', $cols )['tracking']['count'] );
FST_Shipment::$rows = array();
fh_ship( 310, 'label_created', '2025-10-13 07:00:00' );
fh_ship( 310, 'in_transit', '2025-10-13 09:00:00' );
fh_ship( 301, 'pre_transit', '2025-10-13 09:30:00' );
fh_ship( 302, 'pre_transit', '2025-10-13 09:31:00' );

fh_ship( 300, 'in_transit', '2025-10-13 10:00:00' );
$t = Collector::destination( $o300, '2025-10-14', $cols )['tracking'];
check( 'wrapper: its own row wins (count 1, its status)', true === $t['done'] && 1 === $t['count'] && 'in_transit' === $t['status'] );

$checklist = Model::checklist( Collector::destination( $o310, '2025-10-14', $cols ), $cols );
check( 'checklist on a live destination: weather done, invoice done, tracking "2 · In Transit"', 'weather:1,invoice:1,tracking:1' === implode( ',', array_map( static function ( $i ) { return $i['key'] . ':' . (int) $i['done']; }, $checklist ) ) && '2 · In Transit' === $checklist[2]['note'] );

/* ---------------------------------------------------------------- */
echo "8. Caching\n";
$GLOBALS['fh_transients'] = array();
$settings                 = Settings::get(); // cache_minutes 5
$GLOBALS['fh_order_queries'] = 0;
$first = Collector::get( $settings );
check( 'first get() builds and stores the transient for 5 minutes', false === $first['cached'] && 1 === $GLOBALS['fh_order_queries'] && isset( $GLOBALS['fh_transients'][ Collector::TRANSIENT ] ) && 300 === $GLOBALS['fh_transients'][ Collector::TRANSIENT ][1] );
check( 'the stored key is today + the settings hash', Model::cache_key( '2025-10-13', $settings ) === $GLOBALS['fh_transients'][ Collector::TRANSIENT ][0]['key'] );
$second = Collector::get( $settings );
check( 'second get() answers from the cache without a query', true === $second['cached'] && 1 === $GLOBALS['fh_order_queries'] && $first['counts'] === $second['counts'] );

$changed = $settings;
$changed['dates_shown'] = 1;
$third = Collector::get( $changed );
check( 'a changed setting is a miss (different key) → rebuilt', false === $third['cached'] && 2 === $GLOBALS['fh_order_queries'] && 1 === count( $third['blocks'] ) );

Collector::get( $changed );
check( '…and cached again under the new key', 2 === $GLOBALS['fh_order_queries'] && Model::cache_key( '2025-10-13', $changed ) === $GLOBALS['fh_transients'][ Collector::TRANSIENT ][0]['key'] );

$GLOBALS['fh_now'] = '2025-10-14 08:00:00';
$tomorrow = Collector::get( $changed );
check( 'a new day is a miss', false === $tomorrow['cached'] && '2025-10-14' === $tomorrow['today'] && 3 === $GLOBALS['fh_order_queries'] );
$GLOBALS['fh_now'] = '2025-10-13 08:00:00';

Collector::clear_cache();
check( 'clear_cache() drops the transient', ! isset( $GLOBALS['fh_transients'][ Collector::TRANSIENT ] ) );
Collector::get( $settings );
check( '…so the next get() rebuilds', 4 === $GLOBALS['fh_order_queries'] );

$off = $settings;
$off['cache_minutes'] = 0;
$GLOBALS['fh_transients'] = array();
Collector::get( $off );
Collector::get( $off );
check( 'cache_minutes 0 → never stored, every get() queries', ! isset( $GLOBALS['fh_transients'][ Collector::TRANSIENT ] ) && 6 === $GLOBALS['fh_order_queries'] );

// The hooks the section arms drop the cache too.
Collector::get( $settings );
check( 'cached again', isset( $GLOBALS['fh_transients'][ Collector::TRANSIENT ] ) );
add_action( 'woocommerce_update_order', array( Collector::class, 'clear_cache' ), 10, 0 );
do_action( 'woocommerce_update_order', 310 );
check( 'an order save clears it', ! isset( $GLOBALS['fh_transients'][ Collector::TRANSIENT ] ) );

Collector::get( $settings );
add_action( 'woocommerce_order_note_added', array( Collector::class, 'clear_cache' ), 10, 0 );
do_action( 'woocommerce_order_note_added', 77, $o310 );
check( 'an order note (ShipTracker adds one per shipment row, without a save) clears it', ! isset( $GLOBALS['fh_transients'][ Collector::TRANSIENT ] ) );

Collector::get( $settings );
add_action( 'wpo_wcpdf_save_document', array( Collector::class, 'clear_cache' ), 10, 0 );
do_action( 'wpo_wcpdf_save_document', null, $o310 );
check( 'an invoice PDF save (save_meta_data, no order save) clears it', ! isset( $GLOBALS['fh_transients'][ Collector::TRANSIENT ] ) );

/* ---------------------------------------------------------------- */
echo "9. Empty window and bad input\n";
fh_reset_orders();
$empty = Collector::collect( $settings );
check( 'no orders → no counts, no blocks, idle plan', array() === $empty['counts'] && array() === $empty['blocks'] && false === $empty['plan']['ships_today'] && null === $empty['plan']['next'] );
check( 'the header names the lookahead', 'Nothing ships today. No open orders deliver in the next 21 days.' === Model::header_line( $empty['today'], $empty['plan'], $empty['chips'], $empty['lookahead'], Collector::timezone() ) );
check( 'no statuses → no query at all', array() === Collector::open_orders( array(), array( '2025-10-14' ) ) );
check( 'destination() on a non-order → null', null === Collector::destination( 'nope', '2025-10-14', $cols ) );

/* ---------------------------------------------------------------- */
echo "10. With the Batch plugin's Ship Day class (the production path)\n";
// Declared inside an if so PHP does not hoist it above the no-Batch checks.
if ( ! class_exists( 'FisHotel_Ship_Day' ) ) {
	/**
	 * Just enough of Batch 10.27: build_destination() returns a label, a
	 * customer and a 'City, ST ZIP' place (as the real place() does), and
	 * totals() sums its rows — numbers chosen to differ from the local count.
	 */
	class FisHotel_Ship_Day {
		public static function place( $city, $state, $zip ) {
			return trim( $city . ', ' . $state . ' ' . $zip );
		}
		public static function build_destination( $order, $ymd ) {
			return array(
				'label'    => 'Order #' . $order->get_order_number() . ' (ship day label)',
				'customer' => 'Ship Day Name',
				'place'    => self::place( $order->get_shipping_city(), $order->get_shipping_state(), $order->get_shipping_postcode() ),
				'rows'     => array( array( 'kind' => 'fish', 'qty' => 42 ), array( 'kind' => 'dry', 'qty' => 7 ) ),
			);
		}
		public static function totals( array $destinations ) {
			$t = array( 'orders' => 0, 'fish' => 0, 'dry' => 0 );
			foreach ( $destinations as $d ) {
				$t['orders']++;
				foreach ( (array) ( $d['rows'] ?? array() ) as $row ) {
					$t[ $row['kind'] ] += (int) $row['qty'];
				}
			}
			return $t;
		}
	}
}
check( 'Ship Day class detected', true === Collector::has_ship_day() );
$o510 = fh_register_order( new WC_Order( 510, 'processing', array( Checker::META_DELIVERY_DATE => '2025-10-14' ) ) );
$o510->add_item( 'Firefish', 2, $fish_product );
$d510 = Collector::destination( $o510, '2025-10-14', $cols );
check( 'fish / dry come from Ship Day totals()', 42 === $d510['fish'] && 7 === $d510['dry'], json_encode( array( $d510['fish'], $d510['dry'] ) ) );
check( 'the label comes from Ship Day', 'Order #510 (ship day label)' === $d510['label'] );
check( 'place stays "City, ST" from the order — never Ship Day\'s "City, ST ZIP"', 'Carrollton, VA' === $d510['place'], $d510['place'] );
check( 'customer stays the order\'s shipping name', 'Dana Reed' === $d510['customer'], $d510['customer'] );

fh_finish();
