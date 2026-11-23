<?php
/**
 * The shop price of an order line — READ, not typed (Shop_Price): its
 * sources in their order (the variation of the line's size, the product's
 * lowest price, a simple product's price, the species list, nothing), the
 * product found by its name alone, the typed override, the refresh when
 * the shop's price moves, "no shop price yet" in place of "No" and −100%
 * with the Numbers panel's count, the revenue and "up or down" that leave
 * such a line out — and the proof that an order whose lines all carry a
 * typed worth is costed to the cent as costing-test.php works it by hand.
 *
 * Every fish, product and price here is invented.
 *
 * Run from the plugin root:  php includes/sections/clean-room/tests/shop-price-test.php
 */

require_once __DIR__ . '/wp-stubs.php';

use FisHotel\Misc\Sections\Clean_Room\Admin_Workbook;
use FisHotel\Misc\Sections\Clean_Room\Clean_Room;
use FisHotel\Misc\Sections\Clean_Room\Costing;
use FisHotel\Misc\Sections\Clean_Room\Orders;
use FisHotel\Misc\Sections\Clean_Room\Shop_Price;
use FisHotel\Misc\Sections\Clean_Room\Species;
use FisHotel\Misc\Sections\Clean_Room\Tests\Fake_Store;

function near( $a, $b, $eps = 1e-9 ) {
	return null !== $a && null !== $b && abs( (float) $a - (float) $b ) < $eps;
}

update_option( Clean_Room::OPTION_KEY, array( 'size_list' => array( 'Small', 'Medium', 'Large' ) ) );
$today = '2026-03-02';

/*
 * The shop: 21 a simple product at 28; 22 a product with size variations
 * (small 35, medium 45, large 60); 23 a product without any price; 24 and
 * 25 two products with the same name; 26 a product nobody listed as a
 * species, found by its name alone.
 */
function fh_shop() {
	$store = new Fake_Store(
		array(
			21 => 'Blue Test Wrasse',
			22 => 'Green Test Blenny',
			23 => 'Pale Test Tang',
			24 => 'Twin Test Goby',
			25 => 'Twin Test Goby',
			26 => 'Lone Test Chromis',
		)
	);
	$store->product_prices = array(
		21 => array( 'prices' => array( 28.0 ), 'variations' => array() ),
		22 => array(
			'prices'     => array( 35.0, 60.0 ),
			'variations' => array(
				array( 'id' => 221, 'price' => 35.0, 'attributes' => array( 'pa_size' => 'small' ) ),
				array( 'id' => 222, 'price' => 45.0, 'attributes' => array( 'pa_size' => 'medium' ) ),
				array( 'id' => 223, 'price' => 60.0, 'attributes' => array( 'pa_size' => 'large' ) ),
			),
		),
		23 => array( 'prices' => array(), 'variations' => array() ),
		24 => array( 'prices' => array( 11.0 ), 'variations' => array() ),
		25 => array( 'prices' => array( 12.0 ), 'variations' => array() ),
		26 => array( 'prices' => array( 19.5 ), 'variations' => array() ),
	);
	$store->save_species( Species::normalize( array( 'name' => 'Blue Test Wrasse', 'per_box' => 8, 'my_price' => 99, 'product_id' => 21 ) ) );   // 1
	$store->save_species( Species::normalize( array( 'name' => 'Green Test Blenny', 'per_box' => 10, 'my_price' => 99, 'product_id' => 22 ) ) ); // 2
	$store->save_species( Species::normalize( array( 'name' => 'Pale Test Tang', 'per_box' => 4, 'my_price' => 90, 'product_id' => 23 ) ) );     // 3
	$store->save_species( Species::normalize( array( 'name' => 'Red Test Goby', 'per_box' => 24, 'my_price' => 20 ) ) );                         // 4
	$store->save_species( Species::normalize( array( 'name' => 'Grey Test Dartfish', 'per_box' => 12 ) ) );                                     // 5
	return $store;
}

function fh_row( $species, array $extra = array() ) {
	return array_merge(
		array(
			'id'           => '0',
			'species'      => $species,
			'qty_ordered'  => '10',
			'qty_received' => '10',
			'qty_doa'      => '0',
			'qty_survived' => '10',
			'unit_price'   => '4',
			'per_box'      => '10',
		),
		$extra
	);
}

function fh_save( $store, array $lines, array $header = array(), $id = 0 ) {
	global $today;
	return Admin_Workbook::save_order(
		$store,
		array_merge(
			array(
				'ref'       => $id > 0 ? $store->order( $id )['ref'] : '',
				'supplier'  => 'Example Aquatics',
				'origin'    => 'Northland',
				'status'    => 'received',
				'cost_mode' => 'box',
				'lines'     => $lines,
			),
			$header
		),
		$id,
		$today
	);
}

function fh_lines_by_name( array $order ) {
	$out = array();
	foreach ( $order['lines'] as $line ) {
		$out[ $line['species'] ] = $line;
	}
	return $out;
}

echo "1. Where the shop price comes from, in order\n";
$store = fh_shop();
$saved = fh_save(
	$store,
	array(
		fh_row( 'Blue Test Wrasse', array( 'species_id' => '1' ) ),
		fh_row( 'Green Test Blenny', array( 'species_id' => '2', 'size_slug' => 'large' ) ),
		fh_row( 'Green Test Blenny', array( 'species_id' => '2' ) ),
		fh_row( 'Green Test Blenny', array( 'species_id' => '2', 'size_slug' => 'medium' ) ),
		fh_row( 'Pale Test Tang', array( 'species_id' => '3' ) ),
		fh_row( 'Red Test Goby', array( 'species_id' => '4' ) ),
		fh_row( 'Grey Test Dartfish', array( 'species_id' => '5' ) ),
		fh_row( 'Twin Test Goby' ),
		fh_row( 'Lone Test Chromis' ),
		fh_row( 'small brown wrasse', array( 'needs_id' => '1' ) ),
	)
);
check( 'the order is saved', empty( $saved['error'] ) && $saved['order_id'] > 0, json_encode( $saved ) );
$order = Admin_Workbook::resolve_order( $store, $store->order( $saved['order_id'] ) );
$l     = $order['lines'];
check( 'a simple product: its price (28.00), not the species list\'s 99.00', near( $l[0]['shop_price'], 28 ) && 'product' === $l[0]['shop_price_from'] && false === $l[0]['shop_price_range'] && 21 === $l[0]['shop_product_id'] );
check( 'a product with variations: the variation of the line\'s size (large → 60.00)', near( $l[1]['shop_price'], 60 ) && 'variation' === $l[1]['shop_price_from'] && false === $l[1]['shop_price_range'] );
check( '… no size on the line: the lowest price (35.00), marked as the lowest of several', near( $l[2]['shop_price'], 35 ) && 'product' === $l[2]['shop_price_from'] && true === $l[2]['shop_price_range'] );
check( '… another size, another variation (medium → 45.00)', near( $l[3]['shop_price'], 45 ) && 'variation' === $l[3]['shop_price_from'] );
check( 'a linked product without a price: the species list\'s own price (90.00)', near( $l[4]['shop_price'], 90 ) && 'list' === $l[4]['shop_price_from'] && 23 === $l[4]['shop_product_id'] );
check( 'no product at all: the species list\'s price (20.00)', near( $l[5]['shop_price'], 20 ) && 'list' === $l[5]['shop_price_from'] && null === $l[5]['shop_product_id'] );
check( 'no product and no list price: nothing', null === $l[6]['shop_price'] && '' === $l[6]['shop_price_from'] );
check( 'two products with the line\'s name: neither is taken — nothing', null === $l[7]['shop_price'] && null === $l[7]['shop_product_id'] );
check( 'ONE product named exactly like the line, no species row: that product (19.50)', near( $l[8]['shop_price'], 19.5 ) && 'product' === $l[8]['shop_price_from'] && 26 === $l[8]['shop_product_id'] );
check( 'a fish nobody has identified has no shop price', null === $l[9]['shop_price'] && null === $l[9]['shop_product_id'] && ! empty( $l[9]['needs_id'] ) );
check( 'the costing takes the read price: priced, and where from', true === $l[0]['priced'] && 'product' === $l[0]['worth_from'] && true === $l[1]['priced'] && 'variation' === $l[1]['worth_from'] && 'list' === $l[5]['worth_from'] && false === $l[6]['priced'] && '' === $l[6]['worth_from'] );
check( 'nothing was typed and nothing is stored: every line\'s worth is still empty', array( null ) === array_values( array_unique( array_column( $store->order( $saved['order_id'] )['lines'], 'worth' ) ) ) );
check( 'the pure half: product_of() and line_price() agree with the store', 21 === Shop_Price::product_of( array( 'species' => 'x', 'species_product_id' => 21 ), array() ) && 26 === Shop_Price::product_of( array( 'species' => ' lone  test CHROMIS ' ), Shop_Price::name_index( $store->fish_product_names() ) ) && null === Shop_Price::product_of( array( 'species' => 'Twin Test Goby' ), Shop_Price::name_index( $store->fish_product_names() ) ) && null === Shop_Price::product_of( array( 'species' => 'Blue Test Wrasse', 'needs_id' => true, 'species_product_id' => 21 ), array() ) );

echo "2. The numbers of a line priced by the shop\n";
/*
 * No fees: landed = received × cost = 10 × 4 = 40, 4.00 a fish; the target is 4 × 4 = 16 → 15.
 * Blue Test Wrasse at 28: profit 10 × 28 − 40 = 240, margin 6 → worth it (above 2.75); revenue 28 × 10 = 280.
 * Green Test Blenny (no size) at 35: profit 310, margin 7.75.
 * Red Test Goby at 20 (the list): profit 160, margin 4.
 */
check( 'profit, margin, worth it and revenue from the read price', near( $l[0]['profit'], 240 ) && near( $l[0]['margin'], 6 ) && true === $l[0]['worth_it'] && near( $l[0]['revenue'], 280 ) && near( $l[2]['profit'], 310 ) && near( $l[5]['margin'], 4 ) );
$f = Admin_Workbook::figures( $order );
check( 'the page shows the price and where it came from', '28.00' === $f['lines'][0]['shop_price'] && 'product' === $f['lines'][0]['shop_from'] && false !== strpos( $f['lines'][0]['shop_note'], 'Read from the shop' ) && false !== strpos( $f['lines'][1]['shop_note'], 'Large variation' ) && false !== strpos( $f['lines'][2]['shop_note'], 'lowest price' ) && false !== strpos( $f['lines'][5]['shop_note'], 'species list' ) );
check( 'Yes / the margin / the profit as before for a priced line', 'Yes' === $f['lines'][0]['worth_it'] && true === $f['lines'][0]['worth_flag'] && '600.0%' === $f['lines'][0]['margin'] && '240.00' === $f['lines'][0]['profit'] && '280.00' === $f['lines'][0]['revenue'] );

echo "3. A line with NO shop price\n";
$none = Admin_Workbook::no_price_text();
check( '"no shop price yet" in the shop price, Worth it?, profit and margin — never "No", never −100%', 'no shop price yet' === $none && $none === $f['lines'][6]['shop_price'] && $none === $f['lines'][6]['worth_it'] && $none === $f['lines'][6]['profit'] && $none === $f['lines'][6]['margin'] && false === $f['lines'][6]['worth_flag'] && false === $f['lines'][6]['priced'] );
check( '… its revenue is a dash, and the tooltip says what to do', '—' === $f['lines'][6]['revenue'] && false !== strpos( $f['lines'][6]['shop_note'], 'No shop price yet' ) );
check( 'the costing says nothing about such a line (null, not a loss)', null === $l[6]['profit'] && null === $l[6]['margin'] && null === $l[6]['worth_it'] && near( $l[6]['revenue'], 0 ) );
/*
 * Ten lines of 40.00 landed = 400. Three have no price (Grey Test Dartfish, Twin Test Goby, the unknown fish).
 * Revenue = 280 + 600 + 350 + 450 + 900 + 200 + 195 = 2975 over the seven priced lines, which cost 280.
 * Up or down = 2975 − 280 = 2695 (the sheet's own figure, 2975 − 400 = 2575, would count three fish as sold for nothing).
 */
$c = $order['costing'];
check( 'the order counts them: 3 lines, 120.00 landed', 3 === $c['unpriced'] && near( $c['unpriced_landed'], 120 ) && near( $c['total'], 400 ) );
check( 'they are left out of the revenue and of "up or down"', near( $c['revenue'], 2975 ) && near( $c['up_down_priced'], 2695 ) && near( $c['up_down'], 2575 ) && near( $c['ratio_priced'], 2975 / 280 ) );
check( 'the page and the Fish orders list show the figure without them', '+2,695.00' === $f['order']['up_down'] && 'up' === $f['order']['up_down_flag'] && '10.63' === $f['order']['ratio'] && near( Orders::summary( $order )['up_down'], 2695 ) && 3 === Orders::summary( $order )['unpriced'] );
check( 'the Numbers panel says how many lines that is', '3' === $f['order']['unpriced'] && '3 lines have no shop price yet: they are left out of the revenue and of "up or down".' === $f['order']['no_price'] );
check( '… one line reads in the singular, none says nothing', '1 line has no shop price yet: it is left out of the revenue and of "up or down".' === Admin_Workbook::unpriced_text( 1 ) && '' === Admin_Workbook::unpriced_text( 0 ) );
check( 'the grand total still holds every line: a fish without a price was still paid for', '400.00' === $f['order']['grand_total'] );

echo "4. The override: a price typed with the pencil\n";
$rows = array();
foreach ( $store->order( $saved['order_id'] )['lines'] as $line ) {
	$rows[] = fh_row( $line['species'], array( 'id' => (string) $line['id'], 'species_id' => (string) $line['species_id'], 'size_slug' => (string) $line['size_slug'], 'needs_id' => $line['needs_id'] ? '1' : '' ) );
}
$rows[0]['worth'] = '50';   // The owner plans a new price for the wrasse.
$rows[6]['worth'] = '15.5'; // … and types one for the fish the shop does not have.
$rows[7]['worth'] = '0';    // A 0 is no price: it says nothing.
$again = fh_save( $store, $rows, array(), $saved['order_id'] );
check( 'saved again', empty( $again['error'] ), json_encode( $again ) );
$order = Admin_Workbook::resolve_order( $store, $store->order( $saved['order_id'] ) );
$l     = $order['lines'];
$f     = Admin_Workbook::figures( $order );
check( 'the typed price wins over the shop\'s (50.00, not 28.00) — and the shop\'s is still read', near( $l[0]['worth'], 50 ) && 'typed' === $l[0]['worth_from'] && near( $l[0]['shop_price'], 28 ) && near( $l[0]['profit'], 460 ) && '50.00' === $f['lines'][0]['shop_price'] && 'typed' === $f['lines'][0]['shop_from'] );
check( '… the tooltip says both', false !== strpos( $f['lines'][0]['shop_note'], 'Typed by hand' ) && false !== strpos( $f['lines'][0]['shop_note'], '28.00' ) );
check( 'a price typed where the shop has none prices the line', true === $l[6]['priced'] && near( $l[6]['profit'], 115 ) && '15.50' === $f['lines'][6]['shop_price'] && false !== strpos( $f['lines'][6]['shop_note'], 'The shop has no price' ) );
check( 'a 0 is no price — typed to clear the field, or an empty "worth" cell of the workbook that came in as 0: the line reads "no shop price yet", never "No" and −100%', false === $l[7]['priced'] && '' === $l[7]['worth_from'] && null === $l[7]['profit'] && null === $l[7]['margin'] && $none === $f['lines'][7]['worth_it'] && $none === $f['lines'][7]['margin'] && null === Costing::worth_of( array( 'worth' => 0 ) )['price'] && null === Costing::worth_of( array( 'worth' => '0.00' ) )['price'] );
check_same( '… and on a fish the shop has a price for, a 0 follows the shop again', array( 'price' => 28.0, 'from' => 'product' ), Costing::worth_of( array( 'worth' => 0, 'shop_price' => 28.0, 'shop_price_from' => 'product' ) ) );
check( 'two lines are left without a price (that one and the unknown fish)', 2 === $order['costing']['unpriced'] && '2' === $f['order']['unpriced'] );

echo "5. Refreshed on every load while nothing is typed\n";
$store->product_prices[21] = array( 'prices' => array( 31.0 ), 'variations' => array() );
$store->product_prices[22]['variations'][2]['price'] = 66.0; // The large blenny goes up.
$order = Admin_Workbook::resolve_order( $store, $store->order( $saved['order_id'] ) );
$l     = $order['lines'];
check( 'a line without an override follows the shop (large blenny 60.00 → 66.00)', near( $l[1]['shop_price'], 66 ) && near( $l[1]['revenue'], 660 ) );
check( 'a line with an override keeps it (50.00) while the read price moves beside it (31.00)', near( $l[0]['worth'], 50 ) && near( $l[0]['shop_price'], 31 ) && near( $l[0]['profit'], 460 ) );
$rows[0]['worth'] = '';
fh_save( $store, $rows, array(), $saved['order_id'] );
$order = Admin_Workbook::resolve_order( $store, $store->order( $saved['order_id'] ) );
$l     = $order['lines'];
check( 'emptying the field follows the shop again (31.00)', null === $l[0]['worth'] && 'product' === $l[0]['worth_from'] && near( $l[0]['profit'], 270 ) );
$listed = Admin_Workbook::resolved_orders( $store, array() );
check( 'the Fish orders list and the Stats read the same prices in one go', 1 === count( $listed ) && near( $listed[0]['lines'][1]['shop_price'], 66 ) && near( $listed[0]['costing']['revenue'], $order['costing']['revenue'] ) && near( $listed[0]['costing']['up_down_priced'], $order['costing']['up_down_priced'] ) );

echo "6. An order whose lines all carry a typed worth is costed as before — to the cent\n";
/* The worked example of costing-test.php, section 1: the same order, the same expected figures. */
$sheet = array(
	'cost_mode'       => 'box',
	'tariffs'         => 30,
	'domestic_flight' => 100,
	'import_fees'     => 200,
	'credit'          => 10,
	'markup'          => 4,
	'worth_threshold' => 2.75,
);
$typed = array(
	array( 'species' => 'Blue Test Wrasse', 'qty_ordered' => 10, 'qty_received' => 8, 'per_box' => 8, 'unit_price' => 5, 'worth' => 40, 'qty_survived' => 6, 'qty_doa' => 1 ),
	array( 'species' => 'Red Test Goby', 'qty_ordered' => 20, 'qty_received' => 12, 'per_box' => null, 'species_per_box' => 24, 'unit_price' => 2.5, 'worth' => 20, 'qty_survived' => 10, 'qty_doa' => 0 ),
	array( 'species' => 'Green Test Blenny', 'qty_ordered' => 5, 'qty_received' => 0, 'unit_price' => 10, 'worth' => 30, 'qty_survived' => null, 'qty_doa' => 0 ),
);
$plain = Costing::compute( $sheet, $typed );
// The same lines with a shop price read beside each typed worth — a different one, which must change nothing.
$with_shop = $typed;
foreach ( $with_shop as $i => $line ) {
	$with_shop[ $i ]['shop_price']      = 123.45;
	$with_shop[ $i ]['shop_price_from'] = 'product';
}
$read = Costing::compute( $sheet, $with_shop );
$o    = $read['order'];
check( 'the worked example\'s totals: total 400, grand total 390, revenue 440, up/down 50, ratio 440/390', near( $o['total'], 400 ) && near( $o['grand_total'], 390 ) && near( $o['revenue'], 440 ) && near( $o['up_down'], 50 ) && near( $o['ratio'], 440 / 390 ) );
check( '… and nothing is unpriced, so the page\'s "up or down" is the same 50', 0 === $o['unpriced'] && near( $o['unpriced_landed'], 0 ) && near( $o['up_down_priced'], 50 ) && near( $o['ratio_priced'], 440 / 390 ) );
check( 'the worked example\'s lines: profit 62.857142 / 97.142857, margin 0.68, nothing worth it, revenue 240 / 200 / 0', near( $read['lines'][0]['profit'], 320 - ( 40 * 10 / 7 + 200 ) ) && near( $read['lines'][1]['profit'], 240 - ( 30 * 10 / 7 + 100 ) ) && near( $read['lines'][1]['margin'], 0.68 ) && false === $read['lines'][0]['worth_it'] && false === $read['lines'][2]['worth_it'] && near( $read['lines'][0]['revenue'], 240 ) && near( $read['lines'][1]['revenue'], 200 ) && near( $read['lines'][2]['revenue'], 0 ) );
$same = true;
foreach ( array( 'total', 'grand_total', 'revenue', 'up_down', 'ratio', 'fill_rate', 'survival_rate', 'doa_rate', 'doa_cost_sheet', 'doa_cost_per_fish', 'fee_rate', 'tariff_factor', 'up_down_priced' ) as $key ) {
	$same = $same && $plain['order'][ $key ] === $read['order'][ $key ];
}
foreach ( $plain['lines'] as $i => $line ) {
	foreach ( array( 'landed_total', 'landed_per_fish', 'suggested_price', 'profit', 'margin', 'worth_it', 'revenue', 'doa_cost', 'doa_cost_per_fish', 'worth' ) as $key ) {
		$same = $same && $line[ $key ] === $read['lines'][ $i ][ $key ];
	}
}
check( 'bit for bit the same with and without a shop price read beside the typed worth', $same );
check( 'every line says its worth was typed', array( 'typed' ) === array_values( array_unique( array_column( $read['lines'], 'worth_from' ) ) ) );
$f = Admin_Workbook::figures( array( 'lines' => array(), 'costing' => $o ) );
check( 'to the cent on the page: 400.00 · 390.00 · 440.00 · +50.00 · 1.13', '400.00' === $f['order']['total'] && '390.00' === $f['order']['grand_total'] && '440.00' === $f['order']['revenue'] && '+50.00' === $f['order']['up_down'] && '1.13' === $f['order']['ratio'] && '' === $f['order']['no_price'] );
/* The bag-mode example of costing-test.php, section 3. */
$bag = Costing::compute(
	array( 'cost_mode' => 'bag', 'tariffs' => 20, 'domestic_flight' => 50, 'import_fees' => 30 ),
	array(
		array( 'qty_ordered' => 12, 'qty_received' => 10, 'bags' => 2, 'unit_price' => 15, 'worth' => 40, 'qty_survived' => 9, 'qty_doa' => 1, 'shop_price' => 7 ),
		array( 'qty_ordered' => 6, 'qty_received' => 6, 'bags' => null, 'unit_price' => 3, 'worth' => 25, 'qty_survived' => 6, 'qty_doa' => 0, 'shop_price' => 7 ),
	)
);
check( 'bag mode: landed 55 / 93, revenue 510, up/down 362, worth it only on the first line', near( $bag['lines'][0]['landed_total'], 55 ) && near( $bag['lines'][1]['landed_total'], 93 ) && near( $bag['order']['revenue'], 510 ) && near( $bag['order']['up_down'], 362 ) && near( $bag['order']['up_down_priced'], 362 ) && true === $bag['lines'][0]['worth_it'] && false === $bag['lines'][1]['worth_it'] );

echo "7. The worth-it rule, said plainly\n";
check( 'a margin above 2.75 is a shop price above 3.75× the landed cost', near( Costing::worth_times( 2.75 ), 3.75 ) && 'Worth it when the shop price is more than 3.75× what the fish cost landed.' === Admin_Workbook::worth_rule_text( 2.75 ) );
check( '… derived from the order\'s own threshold (3 → 4×), and the sheet\'s 2.75 when none is set', 'Worth it when the shop price is more than 4× what the fish cost landed.' === Admin_Workbook::worth_rule_text( 3 ) && near( Costing::worth_times( null ), 3.75 ) && near( Costing::DEFAULT_THRESHOLD, 2.75 ) );
// The rule and the wording say the same thing: at exactly 3.75× a fish is not yet worth it, a cent above it is.
$edge = static function ( $worth ) {
	$c = Costing::compute( array( 'cost_mode' => 'box' ), array( array( 'qty_received' => 10, 'per_box' => 10, 'unit_price' => 4, 'worth' => $worth, 'qty_survived' => 10 ) ) );
	return $c['lines'][0]['worth_it'];
};
check( 'landed 4.00 a fish: 15.00 (3.75×) is not worth it, 15.01 is', false === $edge( 15 ) && true === $edge( 15.01 ) );
check( 'the target price is headed as the sheet heads it: the markup × 100', '400%' === Admin_Workbook::target_head( 4 ) && '350%' === Admin_Workbook::target_head( 3.5 ) && '400%' === Admin_Workbook::target_head( null ) );

echo "8. The regular price, never the sale price; a product in the trash\n";
$sale                     = fh_shop();
$sale->product_prices[21] = array( 'prices' => array( 20.0 ), 'regular' => array( 28.0 ), 'variations' => array() ); // On sale this week: 20 instead of 28.
$sale->product_prices[22] = array(
	'prices'     => array( 30.0, 60.0 ),
	'regular'    => array(),
	'variations' => array(
		array( 'id' => 221, 'price' => 30.0, 'regular' => 35.0, 'attributes' => array( 'pa_size' => 'small' ) ), // The small one is on sale.
		array( 'id' => 222, 'price' => 45.0, 'regular' => 45.0, 'attributes' => array( 'pa_size' => 'medium' ) ),
		array( 'id' => 223, 'price' => 60.0, 'regular' => null, 'attributes' => array( 'pa_size' => 'large' ) ),
	),
);
$on_sale = fh_save( $sale, array( fh_row( 'Blue Test Wrasse', array( 'species_id' => '1', 'unit_price' => '7' ) ), fh_row( 'Green Test Blenny', array( 'species_id' => '2', 'size_slug' => 'small' ) ), fh_row( 'Green Test Blenny', array( 'species_id' => '2' ) ), fh_row( 'Green Test Blenny', array( 'species_id' => '2', 'size_slug' => 'large' ) ) ) );
$o8      = Admin_Workbook::resolve_order( $sale, $sale->order( $on_sale['order_id'] ) );
check( 'a product on sale: the order reads its REGULAR price (28.00), not the sale\'s 20.00', near( $o8['lines'][0]['shop_price'], 28 ) && 'product' === $o8['lines'][0]['shop_price_from'] );
check( '… so a sale does not turn "Worth it?" over: 7.00 landed × 3.75 = 26.25 — worth it at 28.00, not at 20.00', true === $o8['lines'][0]['worth_it'] && 20.0 < 7 * Costing::worth_times( 2.75 ) );
check( 'a variation on sale: its regular price (small 35.00); the lowest REGULAR price without a size (35.00, not 30.00); a variation without a regular price keeps its own (large 60.00)', near( $o8['lines'][1]['shop_price'], 35 ) && 'variation' === $o8['lines'][1]['shop_price_from'] && near( $o8['lines'][2]['shop_price'], 35 ) && true === $o8['lines'][2]['shop_price_range'] && near( $o8['lines'][3]['shop_price'], 60 ) );
check( 'the "Prices to raise" view still shows the ACTIVE price, as the shop sells today (20.00)', near( \FisHotel\Misc\Sections\Clean_Room\Stats_Prices::fish_price( array( 'product_id' => 21, 'my_price' => null ), $sale->product_prices )['price'], 20 ) );
check_same( 'the pure half: a product without a regular price keeps its own', array( 9 => array( 'prices' => array( 5.0 ), 'variations' => array() ) ), Shop_Price::regular_view( array( 9 => array( 'prices' => array( 5.0 ), 'variations' => array() ) ) ) );
$sql = (string) file_get_contents( Clean_Room::path() . 'class-store.php' );
check( 'the store reads _regular_price beside _price, and a product in the trash answers with nothing', false !== strpos( $sql, "p.post_status NOT IN ('trash', 'auto-draft')" ) && false !== strpos( $sql, "'_price', '_regular_price'," ) );
unset( $sale->product_prices[21] ); // The product went to the trash: the store has no price for it.
$o8 = Admin_Workbook::resolve_order( $sale, $sale->order( $on_sale['order_id'] ) );
check( '… the line then falls back to the species list\'s price, said so', near( $o8['lines'][0]['shop_price'], 99 ) && 'list' === $o8['lines'][0]['shop_price_from'] );

echo "9. A closed order is frozen\n";
$shop  = fh_shop();
$open  = fh_save( $shop, array( fh_row( 'Blue Test Wrasse', array( 'species_id' => '1', 'qty_ordered' => '6', 'qty_received' => '6', 'qty_survived' => '6', 'unit_price' => '10', 'per_box' => '6' ) ), fh_row( 'Grey Test Dartfish', array( 'species_id' => '5' ) ), fh_row( 'Red Test Goby', array( 'species_id' => '4', 'worth' => '25' ) ) ) );
$oid   = (int) $open['order_id'];
$shop->product_prices[21] = array( 'prices' => array( 60.0 ), 'variations' => array() );
$form9 = static function () use ( $shop, $oid ) {
	$rows = array();
	foreach ( $shop->order( $oid )['lines'] as $line ) {
		$rows[] = fh_row( $line['species'], array( 'id' => (string) $line['id'], 'species_id' => (string) $line['species_id'], 'qty_ordered' => (string) $line['qty_ordered'], 'qty_received' => (string) $line['qty_received'], 'qty_survived' => (string) $line['qty_survived'], 'unit_price' => (string) $line['unit_price'], 'per_box' => (string) $line['per_box'], 'worth' => null !== $line['worth'] ? (string) $line['worth'] : '' ) );
	}
	return $rows;
};
$while_open = Admin_Workbook::resolve_order( $shop, $shop->order( $oid ) );
check( 'while the order is open the wrasse follows the shop (60.00): revenue 360, profit 300 — and nothing is stored', near( $while_open['lines'][0]['revenue'], 360 ) && near( $while_open['lines'][0]['profit'], 300 ) && null === $shop->order( $oid )['lines'][0]['worth'] );
$closed = fh_save( $shop, $form9(), array( 'status' => 'closed' ), $oid );
$stored = $shop->order( $oid )['lines'];
check( 'the save that CLOSES the order writes the price read at that moment on every line that has none of its own', empty( $closed['error'] ) && 'closed' === $shop->order( $oid )['status'] && near( $stored[0]['worth'], 60 ) );
check( '… a line the shop has no price for stays without one; a typed price is not touched', null === $stored[1]['worth'] && near( $stored[2]['worth'], 25 ) );
$shop->product_prices[21] = array( 'prices' => array( 20.0 ), 'variations' => array() ); // The shop's price drops to a third.
$shop->save_species( array_merge( $shop->species( 5 ), array( 'my_price' => 44 ) ) );   // … and the dartfish gets a list price.
$later = Admin_Workbook::resolve_order( $shop, $shop->order( $oid ) );
check( 'the shop\'s price moves (60.00 → 20.00): the closed order does not — profit 300, revenue 360, as on the day it was closed', near( $later['lines'][0]['profit'], 300 ) && near( $later['lines'][0]['revenue'], 360 ) && near( $later['costing']['revenue'], $while_open['costing']['revenue'] ) && near( $later['costing']['up_down_priced'], $while_open['costing']['up_down_priced'] ) );
check( '… a closed order is not read for at all: its unpriced line stays "no shop price" though the list has one now', null === $later['lines'][0]['shop_price'] && true === $later['lines'][0]['shop_frozen'] && false === $later['lines'][1]['priced'] && 1 === $later['costing']['unpriced'] );
$f9 = Admin_Workbook::figures( $later );
check( '… and the tooltip says why', false !== strpos( $f9['lines'][0]['shop_note'], 'The price this order was closed with' ) && false !== strpos( $f9['lines'][1]['shop_note'], 'a closed order does not follow the shop' ) );
$year = Admin_Workbook::resolved_orders( $shop, array() );
check( 'the Fish orders list and the Stats read the same frozen figures', near( $year[0]['costing']['revenue'], $later['costing']['revenue'] ) && near( \FisHotel\Misc\Sections\Clean_Room\Stats::build( $year )['totals']['revenue'], $later['costing']['revenue'] ) );
fh_save( $shop, $form9(), array( 'status' => 'closed' ), $oid );
check( 'saving a closed order again freezes nothing anew: the line that had no price still has none', null === $shop->order( $oid )['lines'][1]['worth'] && near( $shop->order( $oid )['lines'][0]['worth'], 60 ) );
$history = fh_shop();
$old     = fh_save( $history, array( fh_row( 'Blue Test Wrasse', array( 'species_id' => '1' ) ) ), array( 'status' => 'received' ) );
$history->update_order_fields( (int) $old['order_id'], array( 'status' => 'closed' ) ); // As the workbook import files a past batch: closed, no worth on the line.
$past = Admin_Workbook::resolve_order( $history, $history->order( (int) $old['order_id'] ) );
check( 'history that was closed without a price is not priced by today\'s shop either: it reads "no shop price", and stays so', null === $history->order( (int) $old['order_id'] )['lines'][0]['worth'] && false === $past['lines'][0]['priced'] && near( $past['costing']['revenue'], 0 ) && 1 === $past['costing']['unpriced'] );
fh_save( $shop, $form9(), array( 'status' => 'received' ), $oid );
$reopened = Admin_Workbook::resolve_order( $shop, $shop->order( $oid ) );
check( 'reopened, the lines keep the prices they were closed with (now typed prices the owner can empty), and the line without one follows the shop again', near( $reopened['lines'][0]['worth'], 60 ) && 'typed' === $reopened['lines'][0]['worth_from'] && near( $reopened['lines'][1]['shop_price'], 44 ) && true === $reopened['lines'][1]['priced'] );

echo "10. The Stats say what the order page says\n";
$mixed = fh_shop();
$m     = fh_save( $mixed, array( fh_row( 'Blue Test Wrasse', array( 'species_id' => '1', 'qty_ordered' => '6', 'qty_received' => '6', 'qty_survived' => '6', 'unit_price' => '10', 'per_box' => '6', 'worth' => '60' ) ), fh_row( 'Grey Test Dartfish', array( 'species_id' => '5', 'qty_ordered' => '6', 'qty_received' => '6', 'qty_survived' => '6', 'unit_price' => '10', 'per_box' => '6' ) ) ) );
$mo    = Admin_Workbook::resolved_orders( $mixed, array() );
$ms    = \FisHotel\Misc\Sections\Clean_Room\Stats::build( $mo );
$mf    = Admin_Workbook::figures( $mo[0] );
/* Two lines of 60.00 landed. The wrasse sells for 6 × 60 = 360: up 300 on its own 60. The dartfish has no price: its 60 is not a loss yet. */
check( 'one priced line (+300.00) and one without a shop price: the order page says +300.00 …', '+300.00' === $mf['order']['up_down'] && near( $mo[0]['costing']['up_down_priced'], 300 ) && near( $mo[0]['costing']['up_down'], 240 ) );
check( '… and so do the Stats — the order\'s row, the totals, the supplier and the origin (not +240.00)', near( $ms['orders'][0]['up_down'], 300 ) && near( $ms['totals']['up_down'], 300 ) && near( $ms['by_supplier'][0]['up_down'], 300 ) && near( $ms['by_origin'][0]['up_down'], 300 ) && near( Orders::summary( $mo[0] )['up_down'], 300 ) );
check( '… which say how many lines that is, and what they cost', 1 === $ms['orders'][0]['unpriced'] && near( $ms['orders'][0]['unpriced_landed'], 60 ) && 1 === $ms['totals']['unpriced'] && near( $ms['totals']['unpriced_landed'], 60 ) && near( $ms['totals']['total'], 120 ) && near( $ms['totals']['ratio'], 6 ) );
$all_priced = \FisHotel\Misc\Sections\Clean_Room\Stats::build( array( Admin_Workbook::resolve_order( $shop, $shop->order( $oid ) ) ) );
check( 'an order whose lines all have a price reads in the Stats as it always did: revenue − landed', 0 === $all_priced['totals']['unpriced'] && near( $all_priced['totals']['up_down'], $all_priced['totals']['revenue'] - $all_priced['totals']['total'] ) );

fh_finish();
