<?php
/**
 * Real sales — what customers actually paid, over a FAKE of WooCommerce's
 * order tables (fake-store.php: the order stats, the order-product lookup
 * rows and the order items with their meta, every name and number
 * invented): the statuses that count and the ones that do not, refunds
 * netted into the line and the year they refund, a variation rolled up
 * to its species, the three ways a line is matched to a fish (its
 * product, the Invoices section's line, an exact name — species name,
 * product title, learned alias), an invoice kept as one line split only
 * when its lines add up, everything else under "not matched" with its
 * units and money so the totals always add up; the Invoices section
 * absent and present; the sales beside the landed cost (a profit only
 * when the fish landed that year); the cache, its flush and the Refresh
 * button; the statuses list and its form; the export sheets.
 *
 * Run from the plugin root:  php includes/sections/clean-room/tests/stats-sales-test.php
 */

require_once __DIR__ . '/wp-stubs.php';

use FisHotel\Misc\Sections\Clean_Room\Admin_Stats;
use FisHotel\Misc\Sections\Clean_Room\Clean_Room;
use FisHotel\Misc\Sections\Clean_Room\Orders;
use FisHotel\Misc\Sections\Clean_Room\Species;
use FisHotel\Misc\Sections\Clean_Room\Stats;
use FisHotel\Misc\Sections\Clean_Room\Stats_Sales;
use FisHotel\Misc\Sections\Clean_Room\Tests\Fake_Store;

function near( $a, $b, $eps = 1e-6 ) {
	return null !== $a && null !== $b && abs( (float) $a - (float) $b ) < $eps;
}

/**
 * The matched figures of one species: array( units, revenue ) — array( 0, 0.0 ) when it has none.
 */
function fh_sold( array $match, $species_id ) {
	return isset( $match['species'][ $species_id ] ) ? array( $match['species'][ $species_id ]['units'], round( $match['species'][ $species_id ]['revenue'], 2 ) ) : array( 0, 0.0 );
}

/**
 * The items of a "not matched" kind: label → array( units, revenue ).
 */
function fh_missed( array $match, $kind ) {
	$out = array();
	foreach ( $match['unmatched'][ $kind ]['items'] as $item ) {
		$out[ $item['label'] ] = array( $item['units'], round( $item['revenue'], 2 ) );
	}
	return $out;
}

echo "1. The statuses that count as a sale\n";
check( 'a status as stored ("wc-completed") and as named ("Completed ") are one', 'completed' === Stats_Sales::normalize_status( 'wc-completed' ) && 'completed' === Stats_Sales::normalize_status( ' Completed ' ) && 'awaiting-shipment' === Stats_Sales::normalize_status( 'wc-awaiting-shipment' ) && '' === Stats_Sales::normalize_status( array( 'x' ) ) && '' === Stats_Sales::normalize_status( null ) );
check( 'a list: normalised, unique, without empties', array( 'processing', 'completed', 'shipped' ) === Stats_Sales::normalize_statuses( array( 'wc-processing', 'completed', 'PROCESSING', '', 'wc-shipped', null ) ) && array() === Stats_Sales::normalize_statuses( 'completed' ) );
$registered = array( 'wc-pending', 'wc-processing', 'wc-on-hold', 'wc-completed', 'wc-cancelled', 'wc-refunded', 'wc-failed', 'wc-shipped', 'wc-awaiting-shipment', 'wc-fulfillment' );
check( 'the list a site starts with: WooCommerce\'s paid statuses plus every registered status whose slug contains "ship"', array( 'processing', 'completed', 'shipped', 'awaiting-shipment' ) === Stats_Sales::default_statuses( array( 'processing', 'completed' ), $registered ) );
check( '… no shipped-style status registered: just the paid ones; a paid status that says "ship" is not listed twice', array( 'processing', 'completed' ) === Stats_Sales::default_statuses( array( 'processing', 'completed' ), array( 'wc-pending', 'wc-completed' ) ) && array( 'shipped', 'completed' ) === Stats_Sales::default_statuses( array( 'shipped', 'completed' ), array( 'wc-shipped' ) ) );
check( 'without WooCommerce (nothing to ask): its usual paid statuses', array( 'processing', 'completed' ) === Stats_Sales::suggested_statuses() && array( 'processing', 'completed' ) === Stats_Sales::statuses() && array() === Stats_Sales::registered_statuses() );

// WooCommerce "active" from here on: its paid statuses and its registered ones. (Declared inside a
// block so they exist only once the lines above have run without them.)
if ( ! function_exists( 'wc_get_is_paid_statuses' ) ) {
	function wc_get_is_paid_statuses() {
		return array( 'processing', 'completed' );
	}
	function wc_get_order_statuses() {
		return array(
			'wc-pending'    => 'Pending payment',
			'wc-processing' => 'Processing',
			'wc-on-hold'    => 'On hold',
			'wc-completed'  => 'Completed',
			'wc-cancelled'  => 'Cancelled',
			'wc-refunded'   => 'Refunded',
			'wc-shipped'    => 'Shipped',
		);
	}
}
check( 'with WooCommerce: suggested = paid + shipped, labelled as the store names them', array( 'processing', 'completed', 'shipped' ) === Stats_Sales::suggested_statuses() && array( 'processing', 'completed', 'shipped' ) === Stats_Sales::statuses() && 'Shipped' === Stats_Sales::registered_statuses()['shipped'] && array( 'Processing', 'Completed', 'Shipped' ) === Admin_Stats::status_labels( Stats_Sales::statuses() ) );
update_option( Stats_Sales::OPTION, array( 'wc-completed', 'Completed', '' ) );
check( 'a saved list wins over the suggestion', array( 'completed' ) === Stats_Sales::statuses() );
update_option( Stats_Sales::OPTION, array() );
check( 'an empty saved list is no list: the suggestion applies', array( 'processing', 'completed', 'shipped' ) === Stats_Sales::statuses() );
delete_option( Stats_Sales::OPTION );
$statuses = Stats_Sales::statuses();

echo "2. The store: species, products, supplier orders, and WooCommerce's order tables\n";
$store = new Fake_Store(
	array(
		12 => 'Test Wrasse, Blue',       // linked to the wrasse: the shop's title differs from the species name
		30 => 'Green Test Blenny',       // linked to the blenny: a product with two size variations (31, 32)
		40 => 'Unlinked Test Shrimp',    // a shop product no species is linked to
		41 => 'Red Test Goby',           // not linked either, but titled exactly like a species
		50 => 'tester Invoice (1/2/26)', // an invoice product
	)
);
$ids = array();
foreach ( array(
	array( 'name' => 'Blue Test Wrasse', 'group_name' => 'Wrasses', 'product_id' => 12, 'my_price' => 40 ),
	array( 'name' => 'Red Test Goby', 'group_name' => 'Gobies', 'my_price' => 20 ),
	array( 'name' => 'Green Test Blenny', 'group_name' => 'Blennies', 'product_id' => 30 ),
	array( 'name' => 'Yellow Test Tang', 'group_name' => 'Tangs' ),
	array( 'name' => 'Zebra Test Eel', 'group_name' => 'Eels' ),
	array( 'name' => 'Orange Test Clown', 'group_name' => 'Clowns', 'my_price' => 30 ),
) as $row ) {
	$ids[ $row['name'] ] = $store->save_species( Species::normalize( $row ) );
}
list( $wrasse, $goby, $blenny, $tang, $eel, $clown ) = array_values( $ids );
$store->add_alias( 'Sunny  TANG', null, 'Yellow Test Tang' );
$store->product_prices = array(
	12 => array( 'prices' => array( 45.0 ), 'variations' => array() ),
	30 => array(
		'prices'     => array( 50.0, 55.0 ),
		'variations' => array(
			array( 'id' => 31, 'price' => 55.0, 'attributes' => array( 'pa_size' => 'small' ) ),
			array( 'id' => 32, 'price' => 50.0, 'attributes' => array( 'pa_size' => 'medium' ) ),
		),
	),
);

// Supplier orders, no fees: landed = received × price.
$save = static function ( array $header, array $lines ) use ( $store ) {
	$id = $store->save_order( Orders::normalize_order( $header ) );
	$store->save_order_lines( $id, Orders::normalize_lines( $lines ) );
	return $id;
};
$save(
	array( 'ref' => '2026-1', 'supplier' => 'Test Supplier One', 'origin' => 'Testland', 'received_date' => '2026-02-10', 'status' => 'received' ),
	array(
		array( 'species_id' => $wrasse, 'species' => 'Blue Test Wrasse', 'qty_ordered' => 10, 'qty_received' => 10, 'qty_survived' => 9, 'unit_price' => 6, 'worth' => 40 ),   // 6.00 a fish, est. revenue 360
		array( 'species_id' => $blenny, 'species' => 'Green Test Blenny', 'qty_ordered' => 4, 'qty_received' => 4, 'unit_price' => 10, 'worth' => 55 ),                      // 10.00 a fish, survivors not typed
		array( 'species_id' => $clown, 'species' => 'Orange Test Clown', 'qty_ordered' => 3, 'qty_received' => 3, 'qty_survived' => 3, 'unit_price' => 5, 'worth' => 30 ),    // 5.00 a fish, est. revenue 90, never sold
	)
);
$save(
	array( 'ref' => '2025-1', 'supplier' => 'Test Supplier One', 'origin' => 'Testland', 'received_date' => '2025-06-01', 'status' => 'closed' ),
	array(
		array( 'species_id' => $goby, 'species' => 'Red Test Goby', 'qty_ordered' => 5, 'qty_received' => 5, 'qty_survived' => 5, 'unit_price' => 4, 'worth' => 20 ),         // 4.00 a fish — in 2025 only
	)
);

$fish = static function ( $name, $qty, $revenue, $invoice = 50 ) {
	return array(
		'name'    => $name,
		'qty'     => $qty,
		'revenue' => $revenue,
		'meta'    => array( '_fh_invoice_fish' => 1, '_fh_invoice_product_id' => $invoice, '_fh_invoice_title' => 'tester Invoice (1/2/26)' ),
	);
};
$kept = static function ( $revenue, array $lines, $qty = 1 ) {
	return array(
		'product_id' => 50,
		'name'       => 'tester Invoice (1/2/26)',
		'qty'        => $qty,
		'revenue'    => $revenue,
		'meta'       => array( 'Includes' => 'see the lines', '_fh_invoice_lines' => json_encode( $lines ), '_fh_invoice_product_id' => 50 ),
	);
};

// S1  a shop order for the wrasse's product.
$store->sale( 'completed', '2026-02-01 10:00:00', array( array( 'product_id' => 12, 'name' => 'Test Wrasse, Blue', 'qty' => 2, 'revenue' => 70 ) ) );
// S2  both variations of the blenny's product.
$store->sale( 'processing', '2026-03-01 10:00:00', array(
	array( 'product_id' => 30, 'variation_id' => 31, 'name' => 'Green Test Blenny - Small', 'qty' => 1, 'revenue' => 55 ),
	array( 'product_id' => 30, 'variation_id' => 32, 'name' => 'Green Test Blenny - Medium', 'qty' => 3, 'revenue' => 150 ),
) );
// S3  the store's own shipped status.
$store->sale( 'shipped', '2026-03-05 10:00:00', array( array( 'product_id' => 12, 'name' => 'Test Wrasse, Blue', 'qty' => 1, 'revenue' => 38 ) ) );
// S4, S5  not paid: pending and cancelled.
$store->sale( 'pending', '2026-03-06 10:00:00', array( array( 'product_id' => 12, 'name' => 'Test Wrasse, Blue', 'qty' => 5, 'revenue' => 200 ) ) );
$store->sale( 'cancelled', '2026-03-07 10:00:00', array( array( 'product_id' => 12, 'name' => 'Test Wrasse, Blue', 'qty' => 7, 'revenue' => 280 ) ) );
// S6  sold on the last days of 2025, refunded in full in January 2026.
list( $s6, $s6_items ) = $store->sale( 'completed', '2025-12-30 10:00:00', array( array( 'product_id' => 12, 'name' => 'Test Wrasse, Blue', 'qty' => 1, 'revenue' => 40 ) ) );
$store->refund( $s6, '2026-01-05 09:00:00', array( $s6_items[0] => array( 1, 40 ) ) );
// S7  three sold, one of them refunded.
list( $s7, $s7_items ) = $store->sale( 'completed', '2026-04-01 10:00:00', array( array( 'product_id' => 12, 'name' => 'Test Wrasse, Blue', 'qty' => 3, 'revenue' => 120 ) ) );
$store->refund( $s7, '2026-04-10 09:00:00', array( $s7_items[0] => array( 1, 40 ) ) );
// S8  refunded in full: the order itself is "refunded".
list( $s8, $s8_items ) = $store->sale( 'refunded', '2026-05-01 10:00:00', array( array( 'product_id' => 12, 'name' => 'Test Wrasse, Blue', 'qty' => 2, 'revenue' => 80 ) ) );
$store->refund( $s8, '2026-05-02 09:00:00', array( $s8_items[0] => array( 2, 80 ) ) );
// S9  an invoice the theme itemised into its fish (lines without a product).
list( $s9 ) = $store->sale( 'completed', '2026-06-01 10:00:00', array(
	$fish( 'Red Test Goby', 2, 36 ),
	$fish( 'sunny tang', 1, 60 ),
	$fish( 'Mystery Test Eel', 1, 25 ),
	$fish( 'TEST WRASSE,  blue', 1, 35 ),
) );
// S10 an invoice kept as one line whose lines add up to what was paid: 2 × 20 + 70 − 10 = 100.
list( $s10 ) = $store->sale( 'completed', '2026-06-10 10:00:00', array(
	$kept( 100, array(
		array( 'name' => 'Red Test Goby', 'qty' => 2, 'price' => 20, 'kind' => 'fish', 'product_id' => 0 ),
		array( 'name' => 'Blue Test Wrasse', 'qty' => 1, 'price' => 70, 'kind' => 'fish', 'product_id' => 0 ),
		array( 'name' => 'Credit: lost fish', 'qty' => 1, 'price' => -10, 'kind' => 'credit', 'product_id' => 0 ),
	) ),
) );
// S11 kept as one line, but the lines say 100 and 90 was paid.
list( $s11 ) = $store->sale( 'completed', '2026-06-11 10:00:00', array(
	$kept( 90, array(
		array( 'name' => 'Blue Test Wrasse', 'qty' => 1, 'price' => 60, 'kind' => 'fish' ),
		array( 'name' => 'Zebra Test Eel', 'qty' => 1, 'price' => 40, 'kind' => 'fish' ),
	) ),
) );
// S12 kept as one line, a fish on it has no price.
$store->sale( 'completed', '2026-06-12 10:00:00', array(
	$kept( 50, array(
		array( 'name' => 'Red Test Goby', 'qty' => 2, 'price' => null, 'kind' => 'fish' ),
	) ),
) );
// S13 an old invoice order: one line, no fish list recorded at all.
$store->sale( 'completed', '2026-06-13 10:00:00', array( array( 'product_id' => 50, 'name' => 'tester Invoice (1/2/26)', 'qty' => 1, 'revenue' => 77 ) ) );
// S14 a shop product no species is linked to.
$store->sale( 'completed', '2026-07-01 10:00:00', array( array( 'product_id' => 40, 'name' => 'Unlinked Test Shrimp', 'qty' => 4, 'revenue' => 48 ) ) );
// S15 a shop product that is not linked but titled exactly like a species.
$store->sale( 'completed', '2026-07-02 10:00:00', array( array( 'product_id' => 41, 'name' => 'Red Test Goby', 'qty' => 1, 'revenue' => 22 ) ) );
// S16 a line without a product that is not an invoice fish.
$store->sale( 'completed', '2026-07-03 10:00:00', array( array( 'name' => 'Gift wrap', 'qty' => 1, 'revenue' => 5 ) ) );
// S17 one sold; 15.00 refunded on the line without a quantity, and 5.00 with no line at all.
list( $s17, $s17_items ) = $store->sale( 'completed', '2026-08-01 10:00:00', array( array( 'product_id' => 12, 'name' => 'Test Wrasse, Blue', 'qty' => 1, 'revenue' => 40 ) ) );
$store->refund( $s17, '2026-08-02 09:00:00', array( $s17_items[0] => array( 0, 15 ) ), 5 );
// S18 a product that was deleted since.
$store->sale( 'completed', '2026-09-01 10:00:00', array( array( 'product_id' => 99, 'name' => 'Gone Test Fish', 'qty' => 1, 'revenue' => 9 ) ) );
check( 'the fake tables: 18 orders and 4 refunds, 25 lookup rows (a refund line without a quantity has none)', 22 === count( $store->wc_orders ) && 25 === count( $store->wc_lines ) && array( 2026, 2025 ) === $store->sales_years( $statuses ) );

echo "3. One year read: what counts, and what it adds up to\n";
$ledger = Admin_Stats::build_ledger( $store, 2026, $statuses );
check( '19 lines counted: every line of a processing, completed or shipped order of 2026, with their refund lines', 19 === $ledger['rows'], (string) $ledger['rows'] );
check( 'statuses OUT of the list are skipped even when the store hands them over: pending, cancelled, and the refunded order with its refund (4 lines)', 4 === $ledger['skipped'] );
check( '26 units and 990.00 in all — net of the fish refunded in April', 26 === $ledger['qty'] && near( $ledger['revenue'], 990 ) );
check( 'read in one page, nothing cut off; the reading is stamped', false === $ledger['truncated'] && 1 === preg_match( '/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/', $ledger['built_at'] ) && array() === $ledger['items'] && 2026 === $ledger['year'] && $statuses === $ledger['statuses'] );
check( 'plain product lines are summed per product and variation: the wrasse product 6 units / 228.00 over 4 sale lines', array( 'product_id' => 12, 'variation_id' => 0, 'qty' => 6, 'revenue' => 228.0, 'lines' => 4 ) === $ledger['products']['12:0'] && 1 === $ledger['products']['30:31']['qty'] && 3 === $ledger['products']['30:32']['qty'] );
check( 'refunds are NET: 3 sold and 1 refunded is 2 units and 80.00 (within the 6 / 228.00)', near( $ledger['products']['12:0']['revenue'], 70 + 38 + 80 + 40 ) );
$paged       = Admin_Stats::build_ledger( $store, 2026, $statuses, 3 );
$ledger_copy = $ledger;
unset( $paged['built_at'], $ledger_copy['built_at'] );
check( 'read three lines at a time (seven reads) it is the same ledger — a refund line nets into its sale across pages', $ledger_copy == $paged );
$cut = Admin_Stats::build_ledger( $store, 2026, $statuses, 3, 2 );
check( 'stopped after two reads of three: 6 lines seen (4 counted, the pending and the cancelled one skipped), and it SAYS it was cut off', 4 === $cut['rows'] && 2 === $cut['skipped'] && true === $cut['truncated'] );

echo "4. Refunds belong to the sale they refund\n";
$l25 = Admin_Stats::build_ledger( $store, 2025, $statuses );
check( '2025: the December sale and its January refund are both 2025 lines — 0 units, 0.00', 2 === $l25['rows'] && 0 === $l25['qty'] && near( $l25['revenue'], 0 ) && 0 === $l25['products']['12:0']['qty'] );
check( '… and the January refund is no part of 2026 (the 990.00 above has no −40.00 in it)', 6 === $ledger['products']['12:0']['qty'] );
$direct = Stats_Sales::new_ledger( 2026, $statuses );
$n      = Stats_Sales::add_rows(
	$direct,
	array(
		array( 'row_id' => 1, 'order_id' => 10, 'product_id' => 12, 'variation_id' => 0, 'qty' => 1, 'revenue' => 40.0, 'parent_id' => 0, 'status' => 'wc-completed', 'date_created' => '2026-01-02 00:00:00', 'parent_status' => '', 'parent_date' => '', 'refunded_item_id' => 0 ),
		array( 'row_id' => 2, 'order_id' => 11, 'product_id' => 12, 'variation_id' => 0, 'qty' => 1, 'revenue' => 40.0, 'parent_id' => 0, 'status' => 'wc-completed', 'date_created' => '2025-12-31 23:59:59', 'parent_status' => '', 'parent_date' => '', 'refunded_item_id' => 0 ),
		array( 'row_id' => 3, 'order_id' => 12, 'product_id' => 12, 'variation_id' => 0, 'qty' => -1, 'revenue' => -40.0, 'parent_id' => 11, 'status' => 'wc-completed', 'date_created' => '2026-01-03 00:00:00', 'parent_status' => 'wc-completed', 'parent_date' => '2025-12-31 23:59:59', 'refunded_item_id' => 2 ),
		array( 'row_id' => 4, 'order_id' => 13, 'product_id' => 12, 'variation_id' => 0, 'qty' => -1, 'revenue' => -40.0, 'parent_id' => 10, 'status' => 'wc-processing', 'date_created' => '2027-02-01 00:00:00', 'parent_status' => 'wc-completed', 'parent_date' => '2026-01-02 00:00:00', 'refunded_item_id' => 1 ),
		array( 'row_id' => 5, 'order_id' => 14, 'product_id' => 12, 'variation_id' => 0, 'qty' => -2, 'revenue' => -80.0, 'parent_id' => 15, 'status' => 'wc-completed', 'date_created' => '2026-03-01 00:00:00', 'parent_status' => 'wc-refunded', 'parent_date' => '2026-02-01 00:00:00', 'refunded_item_id' => 9 ),
		'not a row',
	),
	array()
);
check( 'add_rows() itself checks the year and the status of the ORDER — a refund takes its order\'s: the 2025 sale and its 2026 refund are out, a 2027 refund of a 2026 sale is in, a refund of a "refunded" order is out', 2 === $n && 3 === $direct['skipped'] && 0 === $direct['products']['12:0']['qty'] && near( $direct['products']['12:0']['revenue'], 0 ) && 1 === $direct['products']['12:0']['lines'] );
check( 'a refund that names no line: 20.00 came off order S17 without a line and a quantity — counted apart, off no fish', 1 === $ledger['refunds']['count'] && near( $ledger['refunds']['amount'], -20 ) );
$fin = Stats_Sales::finish( Stats_Sales::new_ledger( 2026, $statuses ), array(), array( array( 'order_id' => 1, 'net_total' => -40.0, 'on_lines' => -40.0 ), array( 'order_id' => 2, 'net_total' => -12.5, 'on_lines' => -10.0 ), 'junk' ) );
check( 'a refund wholly on its lines is not counted; one partly on them counts the rest (−2.50)', 1 === $fin['refunds']['count'] && near( $fin['refunds']['amount'], -2.5 ) );
$newer = new Fake_Store();
list( $n1, $n1_items ) = $newer->sale( 'completed', '2026-02-01 10:00:00', array( array( 'product_id' => 12, 'name' => 'Test Wrasse, Blue', 'qty' => 2, 'revenue' => 80 ) ) );
$newer->refund( $n1, '2026-02-05 09:00:00', array( $n1_items[0] => array( 0, 15 ) ), 5, true );
$nl = Admin_Stats::build_ledger( $newer, 2026, $statuses );
check( 'a newer WooCommerce keeps a lookup row for money taken off a line without a quantity: the 15.00 comes off THAT line (2 units, 65.00) and only the 5.00 that named no line is counted apart', 2 === $nl['rows'] && 2 === $nl['products']['12:0']['qty'] && near( $nl['products']['12:0']['revenue'], 65 ) && 1 === $nl['products']['12:0']['lines'] && 1 === $nl['refunds']['count'] && near( $nl['refunds']['amount'], -5 ) );
list( $n2 ) = $newer->sale( 'completed', '2026-03-01 10:00:00', array(
	array( 'product_id' => 12, 'name' => 'Test Wrasse, Blue', 'qty' => 1, 'revenue' => 40 ),
	array( 'product_id' => 30, 'variation_id' => 31, 'name' => 'Green Test Blenny - Small', 'qty' => 2, 'revenue' => 110 ),
) );
$newer->full_refund( $n2, '2026-03-09 09:00:00' );
$whole = Admin_Stats::build_ledger( $newer, 2026, $statuses );
$split = Admin_Stats::build_ledger( $newer, 2026, $statuses, 1 );
unset( $whole['built_at'], $split['built_at'] );
check( 'a whole order refunded without naming a line is stored under the ORDER\'s line ids with the refund\'s order id: it nets to nothing (the blenny 0 units, 0.00; the wrasse back to 2 / 65.00)', 6 === $whole['rows'] && 0 === $whole['products']['30:31']['qty'] && near( $whole['products']['30:31']['revenue'], 0 ) && 2 === $whole['products']['12:0']['qty'] && near( $whole['products']['12:0']['revenue'], 65 ) && near( $whole['revenue'], 65 ) );
check( '… and read ONE row at a time nothing is lost between two rows that share a line id (the pages run by line id, then order id)', $whole == $split && 6 === $split['rows'] );

echo "5. Invoice lines: itemised fish, and invoices kept as one line\n";
$named = array();
foreach ( $ledger['named'] as $row ) {
	$named[ $row['via'] . ':' . $row['name'] ] = $row;
}
check( 'the itemised fish are kept by the name on the invoice', isset( $named['invoice:sunny tang'], $named['invoice:Mystery Test Eel'], $named['invoice:TEST WRASSE,  blue'] ) && 1 === $named['invoice:Mystery Test Eel']['qty'] && near( $named['invoice:Mystery Test Eel']['revenue'], 25 ) && 'mystery test eel' === $named['invoice:Mystery Test Eel']['key'] && null === $named['invoice:Mystery Test Eel']['species_id'] );
check( 'an invoice kept as one line whose lines add up (2 × 20 + 70 − 10 = 100) is split: the goby joins the itemised one (4 units, 76.00), the wrasse 1 / 70.00', 4 === $named['invoice:Red Test Goby']['qty'] && near( $named['invoice:Red Test Goby']['revenue'], 76 ) && 2 === $named['invoice:Red Test Goby']['lines'] && 1 === $named['invoice:Blue Test Wrasse']['qty'] && near( $named['invoice:Blue Test Wrasse']['revenue'], 70 ) );
check( '… and its credit is counted as a credit (−10.00), not as a fish', 1 === $ledger['credits']['lines'] && near( $ledger['credits']['revenue'], -10 ) );
check( 'kept as one line with lines that say 100 when 90 was paid: NOT split — whole, 1 line, 90.00, "mismatch"', array( 'lines' => 1, 'qty' => 1, 'revenue' => 90.0 ) === $ledger['unsplit']['mismatch'] );
check( 'kept as one line with a fish that has no price: not split — "unpriced", 50.00', array( 'lines' => 1, 'qty' => 1, 'revenue' => 50.0 ) === $ledger['unsplit']['unpriced'] && array( 'mismatch', 'unpriced' ) === array_keys( $ledger['unsplit'] ) );
check( 'a line without a product that is no invoice fish is kept apart', isset( $named['other:Gift wrap'] ) && 'other' === $named['other:Gift wrap']['via'] );
check( 'split_reason: no fish line / bought twice / a fish without a price / the lines do not add up / fine', 'no_lines' === Stats_Sales::split_reason( array(), 1, 10 ) && 'no_lines' === Stats_Sales::split_reason( Stats_Sales::invoice_parts( array( array( 'name' => 'Credit: x', 'price' => -5 ) ) ), 1, -5 ) && 'quantity' === Stats_Sales::split_reason( Stats_Sales::invoice_parts( array( array( 'name' => 'A', 'qty' => 1, 'price' => 10 ) ) ), 2, 20 ) && 'unpriced' === Stats_Sales::split_reason( Stats_Sales::invoice_parts( array( array( 'name' => 'A', 'qty' => 1 ) ) ), 1, 10 ) && 'mismatch' === Stats_Sales::split_reason( Stats_Sales::invoice_parts( array( array( 'name' => 'A', 'qty' => 2, 'price' => 10 ) ) ), 1, 20.02 ) && '' === Stats_Sales::split_reason( Stats_Sales::invoice_parts( array( array( 'name' => 'A', 'qty' => 2, 'price' => 10 ), array( 'name' => 'a note', 'kind' => 'note' ) ) ), 1, 20.005 ) );
check( 'the Invoices section is not there: nobody was asked', false === $ledger['invoices']['provider'] && 0 === $ledger['invoices']['orders'] && false === Admin_Stats::invoices_available() && null === Admin_Stats::invoice_lines_for( $s9 ) );

echo "6. Which fish a line is\n";
$species = $store->species_brief();
$titles  = $store->product_titles( array( 12, 30, 40, 41, 50, 99 ) );
$map     = Stats_Sales::name_map( $species, $titles, $store->aliases() );
check( 'the exact names: every species name; the title of a species\' linked product; a learned alias', array( 'id' => $wrasse, 'source' => 'name' ) === $map['blue test wrasse'] && array( 'id' => $wrasse, 'source' => 'title' ) === $map['test wrasse, blue'] && array( 'id' => $tang, 'source' => 'alias' ) === $map['sunny tang'] && array( 'id' => $goby, 'source' => 'name' ) === $map['red test goby'] );
check( '… a species name is never taken over by a title or an alias; the title of a product no species is linked to is no name', array( 'id' => $blenny, 'source' => 'name' ) === $map['green test blenny'] && ! isset( $map['unlinked test shrimp'] ) && ! isset( $map['tester invoice (1/2/26)'] ) && 8 === count( $map ) );
$map2 = Stats_Sales::name_map(
	$species,
	$titles,
	array(
		array( 'alias' => 'blue test wrasse', 'product_id' => 30, 'species' => 'Green Test Blenny' ),   // a species name: stays the wrasse
		array( 'alias' => 'shop blenny', 'product_id' => 30, 'species' => 'whatever' ),                   // by its product
		array( 'alias' => 'stripy', 'product_id' => null, 'species' => 'zebra TEST eel' ),                // by its species name
		array( 'alias' => 'nothing', 'product_id' => 77, 'species' => 'No Such Fish' ),                   // points nowhere
		array( 'alias' => 'second hand', 'product_id' => null, 'species' => 'stripy' ),                   // an alias of an alias is not followed
	)
);
check( 'an alias: by its linked product, else by its species name; pointing nowhere, or at another alias, it is no name', $wrasse === $map2['blue test wrasse']['id'] && array( 'id' => $blenny, 'source' => 'alias' ) === $map2['shop blenny'] && array( 'id' => $eel, 'source' => 'alias' ) === $map2['stripy'] && ! isset( $map2['nothing'] ) && ! isset( $map2['second hand'] ) );
$ctx   = array( 'species' => $species, 'titles' => $titles, 'aliases' => $store->aliases() );
$match = Stats_Sales::match( $ledger, $ctx );
check( 'the wrasse: 6 by its product (a shop line, the shipped one, the net of the refund, S17) + 1 by its product\'s exact title + 1 by its exact name = 8 units, 333.00', array( 8, 333.0 ) === fh_sold( $match, $wrasse ) && array( 'product' => 6, 'title' => 1, 'name' => 1 ) == $match['species'][ $wrasse ]['via'] );
check( 'a variation rolls up to its species: both variations of the blenny\'s product are the blenny — 4 units, 205.00', array( 4, 205.0 ) === fh_sold( $match, $blenny ) && array( 'product' => 4 ) === $match['species'][ $blenny ]['via'] );
check( 'the goby: 4 by its exact name on invoices + 1 from the shop product titled exactly like it = 5 units, 98.00', array( 5, 98.0 ) === fh_sold( $match, $goby ) && array( 'name' => 5 ) === $match['species'][ $goby ]['via'] );
check( 'the tang: by a learned alias, 1 unit, 60.00', array( 1, 60.0 ) === fh_sold( $match, $tang ) && array( 'alias' => 1 ) === $match['species'][ $tang ]['via'] );
check( 'nothing else is matched: four species', 4 === count( $match['species'] ) && array( 0, 0.0 ) === fh_sold( $match, $eel ) );

echo "7. Not matched — with its units and its money\n";
check( 'an invoice name that is no species, product title or alias: "Mystery Test Eel", 1 unit, 25.00', array( 'Mystery Test Eel' => array( 1, 25.0 ) ) === fh_missed( $match, 'names' ) );
check( 'shop products without a species: the shrimp (4 / 48.00) and the product that no longer exists (#99, 1 / 9.00), biggest first', array( 'Unlinked Test Shrimp' => array( 4, 48.0 ), '#99' => array( 1, 9.0 ) ) === fh_missed( $match, 'products' ) && true === $match['unmatched']['products']['items'][1]['gone'] && 40 === $match['unmatched']['products']['items'][0]['product_id'] );
check( 'invoices kept as one line that cannot be split: lines that do not add up (90.00), no fish list at all (77.00), a fish without a price (50.00)', array( 'mismatch' => array( 1, 90.0 ), 'no_lines' => array( 1, 77.0 ), 'unpriced' => array( 1, 50.0 ) ) === fh_missed( $match, 'invoices' ) );
check( 'lines without a product: the gift wrap; credits inside kept invoices: −10.00 and no unit', array( 'Gift wrap' => array( 1, 5.0 ) ) === fh_missed( $match, 'other' ) && array( 'credits' => array( 0, -10.0 ) ) === fh_missed( $match, 'credits' ) );
$t = $match['totals'];
check( 'matched 18 units / 696.00, not matched 10 units / 294.00', 18 === $t['matched_units'] && near( $t['matched_revenue'], 696 ) && 10 === $t['unmatched_units'] && near( $t['unmatched_revenue'], 294 ) );
check( 'THE TOTALS ADD UP: matched + not matched = every paid line (990.00), counted on its own from the lookup rows', near( $t['revenue'], 990 ) && near( $t['lines_revenue'], 990 ) && true === $t['balanced'] && 28 === $t['units'] );
check( 'the refund that named no line is reported beside them, and is in neither', array( 'count' => 1, 'amount' => -20.0 ) === $match['refunds'] );
$broken             = $ledger;
$broken['revenue'] += 1;
check( 'if they ever did not add up, the screen is told', false === Stats_Sales::match( $broken, $ctx )['totals']['balanced'] );
$many = Stats_Sales::new_ledger( 2026, $statuses );
$rows = array();
$det  = array();
for ( $i = 1; $i <= Stats_Sales::LIST_MAX + 5; $i++ ) {
	$rows[]    = array( 'row_id' => $i, 'order_id' => 500 + $i, 'product_id' => 0, 'variation_id' => 0, 'qty' => 1, 'revenue' => (float) $i, 'parent_id' => 0, 'status' => 'wc-completed', 'date_created' => '2026-01-02 00:00:00', 'parent_status' => '', 'parent_date' => '', 'refunded_item_id' => 0 );
	$det[ $i ] = array( 'name' => 'Nameless Test Fish ' . $i, 'fish' => true, 'lines' => null, 'invoice_product_id' => 50 );
}
Stats_Sales::add_rows( $many, $rows, $det );
$many_match = Stats_Sales::match( Stats_Sales::finish( $many ), $ctx );
check( 'a long list shows its biggest items and says how many more there are — the kind\'s total still counts them all', Stats_Sales::LIST_MAX === count( $many_match['unmatched']['names']['items'] ) && 5 === $many_match['unmatched']['names']['more'] && Stats_Sales::LIST_MAX + 5 === $many_match['unmatched']['names']['units'] && 'Nameless Test Fish ' . ( Stats_Sales::LIST_MAX + 5 ) === $many_match['unmatched']['names']['items'][0]['label'] && true === $many_match['totals']['balanced'] );

echo "8. What another section hands over about an order's invoices\n";
$a = Stats_Sales::invoice_parts( array( array( 'name' => ' Blue  <b>Test</b> Wrasse ', 'qty' => '2', 'price' => '45.50', 'species_id' => '3', 'product_id' => 12, 'variation_id' => 13 ) ) );
check( 'a plain list of lines: name cleaned, qty, the price PER FISH, the total = qty × price, the species and product it names', 1 === count( $a ) && 'fish' === $a[0]['kind'] && 'Blue Test Wrasse' === $a[0]['name'] && 'blue test wrasse' === $a[0]['key'] && 2 === $a[0]['qty'] && near( $a[0]['price'], 45.5 ) && near( $a[0]['total'], 91 ) && 3 === $a[0]['species_id'] && 12 === $a[0]['product_id'] && 13 === $a[0]['variation_id'] && 0 === $a[0]['invoice_product_id'] );
$b = Stats_Sales::invoice_parts( array( 'id' => 5, 'product_id' => 50, 'lines' => array( array( 'species' => 'Red Test Goby', 'quantity' => 3, 'unit_price' => 10, 'line_total' => 27 ), array( 'name' => '' ), 'junk' ) ) );
check( 'one invoice: its product is the invoice product of its lines; quantity / unit_price / line_total are read too (a stated total wins); a nameless line is dropped', 1 === count( $b ) && 50 === $b[0]['invoice_product_id'] && 3 === $b[0]['qty'] && near( $b[0]['total'], 27 ) && 'Red Test Goby' === $b[0]['name'] && null === $b[0]['species_id'] );
$c = Stats_Sales::invoice_parts( array( array( 'product_id' => 50, 'items' => array( array( 'name' => 'A', 'price' => 5 ) ) ), (object) array( 'invoice_product_id' => 60, 'lines' => array( (object) array( 'title' => 'B', 'price' => 7, 'invoice_product_id' => 61 ) ) ) ) );
check( 'a list of invoices, arrays or objects, "lines" or "items"; a line may name its own invoice product', array( 50, 61 ) === array_column( $c, 'invoice_product_id' ) && array( 'A', 'B' ) === array_column( $c, 'name' ) );
$d = Stats_Sales::invoice_parts( array( array( 'name' => 'Credit: lost fish', 'price' => 12 ), array( 'name' => 'Money off', 'kind' => 'discount', 'total' => -8 ), array( 'name' => 'A cheap one', 'price' => -3 ), array( 'name' => 'Ships Tuesday', 'kind' => 'note', 'price' => 9 ), array( 'name' => 'Weird', 'kind' => 'banana', 'qty' => 2, 'price' => 4 ) ) );
check( 'credits are money off whichever way they are written (−12, −8, −3), never fish; a note has no money; an unknown kind with a positive price is a fish', array( 'credit', 'credit', 'credit', 'note', 'fish' ) === array_column( $d, 'kind' ) && near( $d[0]['total'], -12 ) && near( $d[1]['total'], -8 ) && near( $d[2]['total'], -3 ) && null === $d[3]['total'] && near( $d[4]['total'], 8 ) && 0 === $d[0]['product_id'] );
check( 'a JSON string (the theme\'s snapshot) reads the same; nothing, junk or an empty answer is no line', 1 === count( Stats_Sales::invoice_parts( '[{"name":"Red Test Goby","qty":2,"price":20,"kind":"fish","product_id":0}]' ) ) && array() === Stats_Sales::invoice_parts( null ) && array() === Stats_Sales::invoice_parts( '' ) && array() === Stats_Sales::invoice_parts( 'not json' ) && array() === Stats_Sales::invoice_parts( array() ) && array() === Stats_Sales::invoice_parts( 42 ) && array() === Stats_Sales::invoice_parts( false ) );

echo "9. The Invoices section present\n";
$GLOBALS['fh_invoice_calls'] = array();
$GLOBALS['fh_invoices']      = array(
	// The itemised invoice: the eel's line names its species; the goby's too.
	$s9  => array(
		array(
			'id'         => 700,
			'product_id' => 50,
			'lines'      => array(
				array( 'kind' => 'fish', 'name' => 'Mystery Test Eel', 'qty' => 1, 'price' => 25, 'species_id' => $eel ),
				array( 'kind' => 'fish', 'name' => 'red test goby', 'qty' => 2, 'price' => 18, 'species_id' => $goby ),
			),
		),
	),
	// The kept invoice whose snapshot did not add up: the section's lines do (50 + 40 = 90).
	$s11 => array(
		array(
			'id'         => 701,
			'product_id' => 50,
			'lines'      => array(
				array( 'kind' => 'fish', 'name' => 'Wrasse for a friend', 'qty' => 1, 'price' => 50, 'species_id' => $wrasse ),
				array( 'kind' => 'fish', 'name' => 'Eel', 'qty' => 1, 'price' => 40, 'species_id' => $eel ),
			),
		),
	),
);
if ( ! function_exists( 'fishotel_invoices_for_order' ) ) {
	/**
	 * The sibling section's function, as far as this test knows it.
	 */
	function fishotel_invoices_for_order( $order_id ) {
		$GLOBALS['fh_invoice_calls'][] = (int) $order_id;
		if ( 'boom' === ( $GLOBALS['fh_invoices'][ (int) $order_id ] ?? null ) ) {
			throw new \RuntimeException( 'the other section broke' );
		}
		return $GLOBALS['fh_invoices'][ (int) $order_id ] ?? array();
	}
}
check( 'it is there now', true === Admin_Stats::invoices_available() && 2 === count( Admin_Stats::invoice_lines_for( $s9 ) ) && array() === Admin_Stats::invoice_lines_for( 123456 ) );
$GLOBALS['fh_invoice_calls'] = array();
$with                        = Admin_Stats::build_ledger( $store, 2026, $statuses );
sort( $GLOBALS['fh_invoice_calls'] );
check( 'it is asked only about the orders that hold invoice lines — four of them, once each — and answered for two', array( 'provider' => true, 'orders' => 4, 'answered' => 2, 'capped' => false ) === $with['invoices'] && 4 === count( $GLOBALS['fh_invoice_calls'] ) && array_unique( $GLOBALS['fh_invoice_calls'] ) === $GLOBALS['fh_invoice_calls'] && in_array( $s9, $GLOBALS['fh_invoice_calls'], true ) && in_array( $s10, $GLOBALS['fh_invoice_calls'], true ) && in_array( $s11, $GLOBALS['fh_invoice_calls'], true ) );
$match2 = Stats_Sales::match( $with, $ctx );
check( 'the eel line now names its species: matched "by invoice line", and no invoice name is left unmatched', array( 2, 65.0 ) === fh_sold( $match2, $eel ) && array( 'invoice' => 2 ) === $match2['species'][ $eel ]['via'] && array() === fh_missed( $match2, 'names' ) );
check( 'the kept invoice that did not add up is split by the section\'s lines (50.00 the wrasse, 40.00 the eel): only the unpriced one and the old one stay whole', array( 'no_lines' => array( 1, 77.0 ), 'unpriced' => array( 1, 50.0 ) ) === fh_missed( $match2, 'invoices' ) && array( 9, 383.0 ) === fh_sold( $match2, $wrasse ) && 1 === $match2['species'][ $wrasse ]['via']['invoice'] );
check( 'the goby\'s itemised line is matched by the section (2), the one in the kept invoice still by its name (2), the shop one by its title (1)', array( 5, 98.0 ) === fh_sold( $match2, $goby ) && array( 'name' => 3, 'invoice' => 2 ) == $match2['species'][ $goby ]['via'] );
check( 'and it still adds up: 990.00', true === $match2['totals']['balanced'] && near( $match2['totals']['revenue'], 990 ) && near( $match2['totals']['unmatched_revenue'], 77 + 50 + 48 + 9 + 5 - 10 ) );
$GLOBALS['fh_invoices'][ $s10 ] = 'boom';
$boom                           = Admin_Stats::build_ledger( $store, 2026, $statuses );
$boomed                         = Stats_Sales::match( $boom, $ctx );
check( 'the other section throwing on an order never breaks the Stats tab: that order is simply not answered for, and its kept invoice is split by its own snapshot as before', null === Admin_Stats::invoice_lines_for( $s10 ) && 2 === $boom['invoices']['answered'] && 4 === $boom['invoices']['orders'] && true === $boomed['totals']['balanced'] && array( 9, 383.0 ) === fh_sold( $boomed, $wrasse ) && array( 'credits' => array( 0, -10.0 ) ) === fh_missed( $boomed, 'credits' ) );
unset( $GLOBALS['fh_invoices'][ $s10 ] );
add_filter(
	Admin_Stats::INVOICE_FILTER,
	static function ( $lines, $order_id ) use ( $s9, $tang ) {
		return $s9 === (int) $order_id ? array( array( 'name' => 'Mystery Test Eel', 'qty' => 1, 'price' => 25, 'species_id' => $tang ) ) : $lines;
	},
	10,
	2
);
$filtered = Stats_Sales::match( Admin_Stats::build_ledger( $store, 2026, $statuses ), $ctx );
check( 'the filter answers before the function: the eel line is whatever the filter says (here the tang)', array( 2, 85.0 ) === fh_sold( $filtered, $tang ) && array( 1, 40.0 ) === fh_sold( $filtered, $eel ) );
$GLOBALS['fh_filters'][ Admin_Stats::INVOICE_FILTER ] = array();
$GLOBALS['fh_invoices']                               = array();
check( 'a species id that is not in the list is not believed: the line falls back to its exact name', array( 0, 0.0 ) === fh_sold( Stats_Sales::match( Stats_Sales::finish( ( static function () use ( $statuses ) {
	$l = Stats_Sales::new_ledger( 2026, $statuses );
	Stats_Sales::add_rows( $l, array( array( 'row_id' => 1, 'order_id' => 10, 'product_id' => 0, 'variation_id' => 0, 'qty' => 1, 'revenue' => 30.0, 'parent_id' => 0, 'status' => 'wc-completed', 'date_created' => '2026-01-02 00:00:00', 'parent_status' => '', 'parent_date' => '', 'refunded_item_id' => 0 ) ), array( 1 => array( 'name' => 'Blue Test Wrasse', 'fish' => true, 'lines' => null, 'invoice_product_id' => 50 ) ) );
	return $l;
} )(), array( 10 => Stats_Sales::invoice_parts( array( array( 'name' => 'Blue Test Wrasse', 'qty' => 1, 'price' => 30, 'species_id' => 99999 ) ) ) ) ), $ctx ), 99999 ) );

echo "10. The view: sales beside the supplier orders of the year\n";
$data = Admin_Stats::sales_data( $store, array( 'year' => 2026 ) );
$by   = array();
foreach ( $data['rows'] as $row ) {
	$by[ $row['name'] ] = $row;
}
check( 'available; 2026 shown; the years offered are those with sales or supplier orders; the statuses and their labels', true === $data['available'] && 2026 === $data['filters']['year'] && array( 2026, 2025 ) === $data['years'] && $statuses === $data['statuses'] && array( 'Processing', 'Completed', 'Shipped' ) === $data['labels'] && true === $data['invoices'] );
check( 'one row per fish that sold or landed in the year, most actual revenue first', array( 'Blue Test Wrasse', 'Green Test Blenny', 'Red Test Goby', 'Yellow Test Tang', 'Orange Test Clown' ) === array_column( $data['rows'], 'name' ) && 5 === $data['all'] );
$r = $by['Blue Test Wrasse'];
check( 'the wrasse — ACTUAL: 8 units, 333.00, 41.63 a fish on average; list price 45.00 (its product)', true === $r['sold'] && 8 === $r['units'] && near( $r['revenue'], 333 ) && near( $r['avg_paid'], 41.625 ) && near( $r['list_price'], 45 ) && 'product' === $r['price_source'] );
check( '… ESTIMATE beside it: 360.00 (9 survivors × 40.00); landed 6.00 a fish that year; cost of the 8 sold 48.00; ACTUAL PROFIT 285.00', near( $r['est_revenue'], 360 ) && 10 === $r['received'] && 9 === $r['survived'] && near( $r['landed_per_fish'], 6 ) && near( $r['cost_sold'], 48 ) && near( $r['profit'], 285 ) && false === $r['no_cost'] && null === $r['earlier'] );
check( '… matched by: its product, the product\'s title, its name', array( 'product', 'title', 'name' ) === $r['via'] || array( 'product', 'name', 'title' ) === $r['via'] );
$r = $by['Green Test Blenny'];
check( 'the blenny: 4 units, 205.00, 51.25 a fish; list price 50.00, the lowest variation; landed 10.00 → profit 165.00; its survivors are not known, so it has NO estimate (not an estimate of 0.00)', 4 === $r['units'] && near( $r['avg_paid'], 51.25 ) && near( $r['list_price'], 50 ) && true === $r['price_from'] && near( $r['profit'], 165 ) && null === $r['est_revenue'] && true === $r['est_unknown'] && 1 === $r['unknown_lines'] && null === $r['survived'] );
$r = $by['Red Test Goby'];
check( 'the goby sold 5 for 98.00 in 2026 but none LANDED in 2026: NO profit is shown — the row says so', 5 === $r['units'] && near( $r['revenue'], 98 ) && null === $r['profit'] && null === $r['cost_sold'] && null === $r['landed_per_fish'] && true === $r['no_cost'] && null === $r['est_revenue'] && null === $r['received'] );
check( '… and carries the last earlier landed cost for reference only (4.00 in 2025); list price 20.00 from the species list', array( 'year' => 2025, 'landed_per_fish' => 4.0 ) === $r['earlier'] && near( $r['list_price'], 20 ) && 'list' === $r['price_source'] && 'no landed cost in 2026 (last landed: 4.00 a fish in 2025)' === Admin_Stats::profit_note( $r ) );
$r = $by['Yellow Test Tang'];
check( 'the tang sold but never landed anywhere: no profit, no earlier cost, no price on file', true === $r['no_cost'] && null === $r['earlier'] && null === $r['profit'] && null === $r['list_price'] && 'no landed cost in 2026' === Admin_Stats::profit_note( $r ) );
$r = $by['Orange Test Clown'];
check( 'the clown landed but did not sell: 0 units, no profit and no "no cost" note — its estimate (90.00) stands alone', false === $r['sold'] && 0 === $r['units'] && null === $r['profit'] && false === $r['no_cost'] && near( $r['est_revenue'], 90 ) && false === $r['est_unknown'] && near( $r['landed_per_fish'], 5 ) && array() === $r['via'] && '' === Admin_Stats::profit_note( $r ) );
$sum = $data['totals'];
check( 'the sums: 18 units, 696.00 actual beside 450.00 estimated; profit 450.00 on the 538.00 that has a landed cost (88.00); 2 species, 158.00, without one', 18 === $sum['units'] && near( $sum['revenue'], 696 ) && near( $sum['est_revenue'], 450 ) && near( $sum['profit'], 450 ) && near( $sum['costed_revenue'], 538 ) && near( $sum['cost_sold'], 88 ) && 2 === $sum['no_cost'] && near( $sum['no_cost_revenue'], 158 ) );
$y25 = Admin_Stats::sales_data( $store, array( 'year' => 2025 ) );
check( '2025: the goby landed (est. 100.00) and the wrasse\'s only sale was refunded — nothing sold, nothing unmatched', 2025 === $y25['filters']['year'] && 0 === $y25['totals']['units'] && near( $y25['totals']['est_revenue'], 100 ) && 0 === $y25['match']['totals']['unmatched_units'] && false === $y25['rows'][0]['sold'] );
$latest = Admin_Stats::sales_data( $store, array() );
check( 'no year asked: the latest year that has sales', 2026 === $latest['filters']['year'] );
$found = Admin_Stats::sales_data( $store, array( 'year' => 2026, 'search' => 'gob', 'sort' => 'units', 'dir' => 'asc' ) );
check( 'a search keeps the rows that match; the totals stay the year\'s', array( 'Red Test Goby' ) === array_column( $found['rows'], 'name' ) && 5 === $found['all'] && 18 === $found['totals']['units'] );
$sorted = Admin_Stats::sales_data( $store, array( 'year' => 2026, 'sort' => 'units', 'dir' => 'asc' ) );
check( 'sorted by units, fewest first', array( 0, 1, 4, 5, 8 ) === array_column( $sorted['rows'], 'units' ) );
check( 'the request → the filters: a sort only by a column of the view, asc or desc', array( 'year' => 2026, 'search' => 'tang', 'sort' => 'profit', 'dir' => 'asc' ) === Admin_Stats::sales_filters( array( 'year' => '2026', 's' => ' <i>tang</i> ', 'sort' => 'profit', 'dir' => 'asc' ) ) && array( 'year' => 0, 'search' => '', 'sort' => '', 'dir' => 'desc' ) === Admin_Stats::sales_filters( array( 'sort' => 'password', 'dir' => 'sideways', 'year' => array( 1 ) ) ) );

echo "11. The cache: one transient, dropped when an order changes\n";
delete_transient( Stats_Sales::TRANSIENT );
$store->sales_reads = 0;
$first              = Admin_Stats::sales_data( $store, array( 'year' => 2026 ) );
$reads              = $store->sales_reads;
check( 'the first view reads WooCommerce\'s tables (one page) and keeps the year in the transient, for twelve hours', 1 === $reads && false === $first['ledger']['cached'] && isset( $GLOBALS['fh_transients'][ Stats_Sales::TRANSIENT ] ) && Stats_Sales::CACHE_TTL === $GLOBALS['fh_transients'][ Stats_Sales::TRANSIENT ][1] && 43200 === Stats_Sales::CACHE_TTL && isset( get_transient( Stats_Sales::TRANSIENT )['years'][2026] ) );
$second = Admin_Stats::sales_data( $store, array( 'year' => 2026 ) );
check( 'the second view reads nothing: the same figures from the cache', $reads === $store->sales_reads && true === $second['ledger']['cached'] && $first['totals'] === $second['totals'] && $first['ledger']['built_at'] === $second['ledger']['built_at'] );
Admin_Stats::sales_data( $store, array( 'year' => 2025 ) );
check( 'another year is read once and kept beside it', $reads + 1 === $store->sales_reads && array( 2026, 2025 ) === array_keys( get_transient( Stats_Sales::TRANSIENT )['years'] ) );
$shrimp = $store->save_species( Species::normalize( array( 'name' => 'Pink Test Shrimp', 'group_name' => 'Inverts', 'product_id' => 40 ) ) );
$linked = Admin_Stats::sales_data( $store, array( 'year' => 2026 ) );
check( 'WHICH FISH a sale is, is decided on every view: a species linked to the shrimp\'s product now has its 4 units / 48.00 — without reading WooCommerce again', $reads + 1 === $store->sales_reads && true === $linked['ledger']['cached'] && array( 4, 48.0 ) === fh_sold( $linked['match'], $shrimp ) && array( '#99' => array( 1, 9.0 ) ) === fh_missed( $linked['match'], 'products' ) && true === $linked['match']['totals']['balanced'] );
$store->delete_species( $shrimp );
add_action( 'woocommerce_order_status_changed', array( Stats_Sales::class, 'flush' ), 10, 0 );
do_action( 'woocommerce_order_status_changed', 9001, 'pending', 'processing' );
check( 'an order changing status drops the cache', false === get_transient( Stats_Sales::TRANSIENT ) );
$source = (string) file_get_contents( Clean_Room::path() . 'class-clean-room.php' );
check( 'the section hooks that flush to an order\'s status change and to WooCommerce\'s own updates of its order tables', false !== strpos( $source, "'woocommerce_order_status_changed'" ) && false !== strpos( $source, "'woocommerce_analytics_update_order_stats'" ) && false !== strpos( $source, "'woocommerce_analytics_delete_order_stats'" ) && false !== strpos( $source, "array( Stats_Sales::class, 'flush' )" ) );
list( $late ) = $store->sale( 'completed', '2026-10-01 10:00:00', array( array( 'product_id' => 12, 'name' => 'Test Wrasse, Blue', 'qty' => 1, 'revenue' => 44 ) ) );
$after        = Admin_Stats::sales_data( $store, array( 'year' => 2026 ) );
check( '… so the next view reads again and has the new sale (9 wrasses, 377.00)', $reads + 2 === $store->sales_reads && false === $after['ledger']['cached'] && array( 9, 377.0 ) === fh_sold( $after['match'], $wrasse ) );
Admin_Stats::sales_data( $store, array( 'year' => 2026 ) );
do_action( 'woocommerce_order_status_changed', 9002, 'processing', 'completed' );
check( 'EVERY change drops it, not only the first of a request: a queue runner lives for minutes, and a cache rebuilt by a view in between must not survive the next order', false === get_transient( Stats_Sales::TRANSIENT ) );
Stats_Sales::flush();
check( '… and dropping an empty cache is harmless', false === get_transient( Stats_Sales::TRANSIENT ) );
Admin_Stats::sales_data( $store, array( 'year' => 2026 ) );
update_option( Stats_Sales::OPTION, array( 'completed' ) );
$narrow = Admin_Stats::sales_data( $store, array( 'year' => 2026 ) );
check( 'a cache read for another list of statuses is not used: with "completed" only, the processing blenny order and the shipped wrasse are out', false === $narrow['ledger']['cached'] && array( 0, 0.0 ) === fh_sold( $narrow['match'], $blenny ) && array( 8, 339.0 ) === fh_sold( $narrow['match'], $wrasse ) && array( 'completed' ) === $narrow['statuses'] );
delete_option( Stats_Sales::OPTION );
delete_transient( Stats_Sales::TRANSIENT );
unset( $store->wc_orders[ $late ] );
$store->wc_lines = array_values(
	array_filter(
		$store->wc_lines,
		static function ( $line ) use ( $late ) {
			return (int) $line['order_id'] !== (int) $late;
		}
	)
);
$cache = array( 'v' => Stats_Sales::CACHE_VERSION, 'statuses' => $statuses, 'years' => array(), 'sales_years' => array( 2026 ) );
for ( $y = 2001; $y <= 2000 + Stats_Sales::CACHE_YEARS; $y++ ) {
	$cache['years'][ $y ] = Stats_Sales::new_ledger( $y, $statuses );
}
set_transient( Stats_Sales::TRANSIENT, $cache, 60 );
Admin_Stats::ledger( $store, 2026, $statuses );
check( 'the cache keeps a bounded number of years: reading one more drops the one read first', Stats_Sales::CACHE_YEARS === count( get_transient( Stats_Sales::TRANSIENT )['years'] ) && ! isset( get_transient( Stats_Sales::TRANSIENT )['years'][2001] ) && isset( get_transient( Stats_Sales::TRANSIENT )['years'][2026] ) );
set_transient( Stats_Sales::TRANSIENT, array( 'v' => Stats_Sales::CACHE_VERSION + 1, 'statuses' => $statuses, 'years' => array( 2026 => array( 'junk' => true ) ) ), 60 );
check( 'a cache of another shape (an older or newer version) is not read', false === Admin_Stats::ledger( $store, 2026, $statuses )['cached'] );
delete_transient( Stats_Sales::TRANSIENT );

echo "12. Refresh\n";
$GLOBALS['fh_user_caps'] = array( 'manage_woocommerce' => array( 5, 9 ), 'manage_options' => array( 9 ) );
Admin_Stats::sales_data( $store, array( 'year' => 2026 ) );
$GLOBALS['fh_current_user'] = 3;
$no                         = Admin_Stats::refresh_request( array( '_wpnonce' => wp_create_nonce( Admin_Stats::REFRESH_ACTION ), 'year' => '2026' ) );
check( 'without manage_woocommerce: refused (403), the cache untouched', 403 === $no['status'] && false === $no['ok'] && false !== get_transient( Stats_Sales::TRANSIENT ) );
$GLOBALS['fh_current_user'] = 5;
$bad                        = Admin_Stats::refresh_request( array( '_wpnonce' => 'nope', 'year' => '2026' ) );
check( 'a wrong or missing nonce: refused (403), the cache untouched', 403 === $bad['status'] && false !== get_transient( Stats_Sales::TRANSIENT ) && 403 === Admin_Stats::refresh_request( array() )['status'] );
$store->sales_reads = 0;
$ok                 = Admin_Stats::refresh_request( array( '_wpnonce' => wp_create_nonce( Admin_Stats::REFRESH_ACTION ), 'year' => '2026' ) );
check( 'with both: the cache is dropped, and the page goes back to that year of Real sales', 200 === $ok['status'] && true === $ok['ok'] && false === get_transient( Stats_Sales::TRANSIENT ) && false !== strpos( $ok['redirect'], 'view=sales' ) && false !== strpos( $ok['redirect'], 'year=2026' ) && false !== strpos( $ok['redirect'], 'tab=stats' ) );
check( '… which then reads WooCommerce again', false === Admin_Stats::sales_data( $store, array( 'year' => 2026 ) )['ledger']['cached'] && 1 === $store->sales_reads );
check( 'the action and the nonce are one name; it changes no order — only the cache', 'fishotel_cleanroom_sales_refresh' === Admin_Stats::REFRESH_ACTION && 22 === count( $store->wc_orders ) );

echo "13. Settings → Sales statuses\n";
$panel = Admin_Stats::statuses_panel();
$rows  = array();
foreach ( $panel['rows'] as $row ) {
	$rows[ $row['slug'] ] = $row;
}
check( 'the card lists every status of the store, ticked for the ones that count, the suggested ones marked; nothing saved yet', 7 === count( $rows ) && true === $rows['completed']['counts'] && true === $rows['shipped']['counts'] && true === $rows['shipped']['suggested'] && false === $rows['pending']['counts'] && false === $rows['refunded']['suggested'] && false === $panel['saved'] && true === $panel['woocommerce'] && 'Pending payment' === $rows['pending']['label'] );
$post = static function ( array $fields ) {
	return Admin_Stats::statuses_request( array_merge( array( '_wpnonce' => wp_create_nonce( Admin_Stats::STATUSES_ACTION ) ), $fields ) );
};
$GLOBALS['fh_current_user'] = 5;
$r                          = $post( array( 'statuses' => array( 'completed' ) ) );
check( 'a shop manager without manage_options: refused (403) — the list is a setting of the section', 403 === $r['status'] && false === get_option( Stats_Sales::OPTION ) );
$GLOBALS['fh_current_user'] = 9;
$r                          = Admin_Stats::statuses_request( array( '_wpnonce' => 'nope', 'statuses' => array( 'completed' ) ) );
check( 'a wrong nonce: refused (403), nothing saved', 403 === $r['status'] && false === get_option( Stats_Sales::OPTION ) );
$r = $post( array( 'statuses' => array() ) );
check( 'no status ticked: refused (400) — with none, no order would count; nothing saved', 400 === $r['status'] && false === $r['ok'] && false === get_option( Stats_Sales::OPTION ) && false !== strpos( $r['message'], 'Nothing was changed' ) );
$r = $post( array( 'statuses' => array( 'made-up', '<script>' ) ) );
check( 'only statuses that are not this store\'s: refused (400), nothing saved', 400 === $r['status'] && false === get_option( Stats_Sales::OPTION ) );
Admin_Stats::sales_data( $store, array( 'year' => 2026 ) );
$r = $post( array( 'statuses' => array( 'wc-completed', 'on-hold', 'made-up', 'completed' ) ) );
check( 'a real list: saved clean (known statuses only, once each), the cached sales dropped, back to the Settings card', 200 === $r['status'] && true === $r['ok'] && array( 'completed', 'on-hold' ) === get_option( Stats_Sales::OPTION ) && array( 'completed', 'on-hold' ) === Stats_Sales::statuses() && false === get_transient( Stats_Sales::TRANSIENT ) && false !== strpos( $r['redirect'], 'tab=settings' ) && false !== strpos( $r['redirect'], '#fishotel-cleanroom-sales-statuses' ) && false !== strpos( $r['message'], 'completed, on-hold' ) );
$panel = Admin_Stats::statuses_panel();
check( 'the card now says it was saved by hand; on-hold counts, shipped no longer does', true === $panel['saved'] && array( 'on-hold', 'completed' ) === array_values( array_column( array_filter( $panel['rows'], static function ( $row ) { return $row['counts']; } ), 'slug' ) ) );
update_option( Stats_Sales::OPTION, array( 'completed', 'old-custom' ) );
$panel = Admin_Stats::statuses_panel();
$last  = end( $panel['rows'] );
check( 'a saved status the store no longer has stays on the card (so it can be unticked), marked as such', 'old-custom' === $last['slug'] && false === $last['registered'] && true === $last['counts'] );
Admin_Stats::sales_data( $store, array( 'year' => 2026 ) );
$r = $post( array( 'op' => 'reset' ) );
check( '"Back to the suggested list": the saved list is forgotten, the suggestion applies, the cache is dropped', 200 === $r['status'] && false === get_option( Stats_Sales::OPTION ) && array( 'processing', 'completed', 'shipped' ) === $r['statuses'] && array( 'processing', 'completed', 'shipped' ) === Stats_Sales::statuses() && false === get_transient( Stats_Sales::TRANSIENT ) );

echo "14. Without WooCommerce's order tables\n";
$store->wc_tables = false;
delete_transient( Stats_Sales::TRANSIENT );
$off = Admin_Stats::sales_data( $store, array( 'year' => 2026 ) );
check( 'the view says the sales are not available, shows no sale — and still the landed costs and the estimates', false === $off['available'] && 0 === $off['totals']['units'] && near( $off['totals']['est_revenue'], 450 ) && 0 === $off['ledger']['rows'] && false === get_transient( Stats_Sales::TRANSIENT ) && array( 'Blue Test Wrasse', 'Orange Test Clown', 'Green Test Blenny' ) === array_column( $off['rows'], 'name' ) && true === $off['match']['totals']['balanced'] );
check( 'the years offered are then the supplier orders\'', array( 2026, 2025 ) === $off['years'] );
$store->wc_tables = true;

echo "15. The export sheets\n";
$data   = Admin_Stats::sales_data( $store, array( 'year' => 2026 ) );
$sheets = Admin_Stats::sales_sheets( $data );
check( 'two sheets: Sales by species (a header and the five fish) and Not matched', array( 'Sales by species', 'Not matched' ) === array_keys( $sheets ) && 6 === count( $sheets['Sales by species'] ) && 'species' === $sheets['Sales by species'][0][0] );
$row = array_combine( $sheets['Sales by species'][0], $sheets['Sales by species'][1] );
check( 'the wrasse, typed: 8 units, 333.00 actual, 41.625 average, list 45.00 from its product, 360.00 estimated, 6.00 landed, 48.00 cost, 285.00 profit, no note', 'Blue Test Wrasse' === $row['species'] && 'Wrasses' === $row['group'] && 2026 === $row['year'] && 8 === $row['units_sold'] && 333.0 === $row['actual_revenue'] && near( $row['avg_price_paid'], 41.625 ) && 45.0 === $row['list_price'] && 'product' === $row['list_price_source'] && 360.0 === $row['est_revenue'] && 10 === $row['received'] && 9 === $row['survived'] && 6.0 === $row['landed_per_fish'] && 48.0 === $row['cost_of_units_sold'] && 285.0 === $row['actual_profit'] && '' === $row['profit_note'] && $wrasse === $row['species_id'] && false !== strpos( $row['matched_by'], 'product' ) );
$row = array_combine( $sheets['Sales by species'][0], $sheets['Sales by species'][3] );
check( 'the goby: the profit cell is EMPTY — never 0 — and the note says why', 'Red Test Goby' === $row['species'] && null === $row['actual_profit'] && null === $row['cost_of_units_sold'] && null === $row['landed_per_fish'] && null === $row['est_revenue'] && 'no landed cost in 2026 (last landed: 4.00 a fish in 2025)' === $row['profit_note'] && 20.0 === $row['list_price'] );
$missed = $sheets['Not matched'];
$kinds  = array();
foreach ( array_slice( $missed, 1 ) as $line ) {
	if ( 'all' === $line[1] ) {
		$kinds[ $line[0] ] = array( $line[2], round( $line[3], 2 ) );
	}
}
check( 'Not matched: each kind with its units and money, then matched + not matched = every paid line, then the refund that named no line', array( 5, 57.0 ) === $kinds['Shop products without a species'] && array( 1, 25.0 ) === $kinds['Invoice fish whose name is no species'] && array( 3, 217.0 ) === $kinds['Invoices kept as one line'] && array( 18, 696.0 ) === $kinds['matched to a species'] && array( 10, 294.0 ) === $kinds['not matched'] && array( 28, 990.0 ) === $kinds['every paid line'] && array( null, -20.0 ) === $kinds['refunded without naming a line (not taken off any fish)'] );
$items = array();
foreach ( array_slice( $missed, 1 ) as $line ) {
	if ( 'all' !== $line[1] ) {
		$items[ $line[1] ] = round( $line[3], 2 );
	}
}
check( '… and the items in words: the unlinked product, the product that is gone, the name, why each invoice was not split, the credits', 48.0 === $items['Unlinked Test Shrimp'] && 9.0 === $items['#99 (no longer a product)'] && 25.0 === $items['Mystery Test Eel'] && 90.0 === $items['its lines do not add up to what was paid'] && 77.0 === $items['no fish lines recorded on the order'] && 50.0 === $items['a fish on it has no price'] && -10.0 === $items['credits'] && 5.0 === $items['Gift wrap'] );
$export = Admin_Stats::export( 'sales', 'xlsx', $store, array( 'year' => '2026' ), '2026-11-12' );
check( 'the export of the view is these sheets; a CSV carries Sales by species; the link names the year', $sheets == $export['sheets'] && 'Sales by species' === $export['csv'] && false !== strpos( $data['exports']['csv'], 'what=sales' ) && false !== strpos( $data['exports']['xlsx'], 'year=2026' ) );
check( 'the words on the screen: how a sale was matched, the kinds of "not matched"', 'product link' === Admin_Stats::via_label( 'product' ) && 'invoice line' === Admin_Stats::via_label( 'invoice' ) && 'exact name' === Admin_Stats::via_label( 'name' ) && 'exact product title' === Admin_Stats::via_label( 'title' ) && 'learned alias' === Admin_Stats::via_label( 'alias' ) && array( 'products', 'names', 'invoices', 'other', 'credits' ) === array_keys( Admin_Stats::unmatched_labels() ) && array_keys( $data['match']['unmatched'] ) === array_keys( Admin_Stats::unmatched_labels() ) );

echo "16. Nothing was written anywhere but the cache and the statuses option\n";
check( 'no order, line, item, species price or product changed through all of this', 22 === count( $store->wc_orders ) && 25 === count( $store->wc_lines ) && 40.0 === $store->species( $wrasse )['my_price'] && array( 45.0 ) === $store->product_prices[12]['prices'] && 2 === count( $store->orders ) );
check( 'the only options and transients this feature knows', 'fishotel_cleanroom_sales_statuses' === Stats_Sales::OPTION && 'fishotel_cleanroom_sales' === Stats_Sales::TRANSIENT && array() === array_diff( array_keys( $GLOBALS['fh_options'] ), array( Stats_Sales::OPTION ) ) && array() === array_diff( array_keys( $GLOBALS['fh_transients'] ), array( Stats_Sales::TRANSIENT ) ) );

fh_finish();
