<?php
/**
 * Prices to raise — over invented orders: which fish get a row (a line
 * that received fish at a price), the landed cost per fish of the latest
 * order and the average of the last three, the suggested price exactly as
 * the costing computes it (landed per fish × the order's markup, to the
 * nearest 5), where FisHotel's price comes from and in which order (the
 * linked product, the variation of the size, the species list, none), the
 * gap and the margin, the selection (under the suggestion only, biggest
 * gap first; "show all"), the group and supplier filters — and, on the
 * fake store, that the view reads everything and writes nothing.
 *
 * Run from the plugin root:  php includes/sections/clean-room/tests/stats-prices-test.php
 */

require_once __DIR__ . '/wp-stubs.php';

use FisHotel\Misc\Sections\Clean_Room\Admin_Stats;
use FisHotel\Misc\Sections\Clean_Room\Costing;
use FisHotel\Misc\Sections\Clean_Room\Orders;
use FisHotel\Misc\Sections\Clean_Room\Species;
use FisHotel\Misc\Sections\Clean_Room\Stats_Prices;
use FisHotel\Misc\Sections\Clean_Room\Tests\Fake_Store;

function near( $a, $b, $eps = 1e-9 ) {
	return null !== $a && null !== $b && abs( (float) $a - (float) $b ) < $eps;
}

/**
 * An order without fees (landed = received × price, so the figures stay
 * round) unless $extra says otherwise.
 */
function fh_order( $id, $date, $supplier, array $lines, array $extra = array() ) {
	return array_merge(
		array(
			'id'            => $id,
			'ref'           => substr( $date, 0, 4 ) . '-' . $id,
			'supplier'      => $supplier,
			'origin'        => 'Testland',
			'received_date' => $date,
			'status'        => 'received',
			'cost_mode'     => 'box',
			'lines'         => $lines,
		),
		$extra
	);
}

function fh_line( $species_id, $name, $received, $price, array $extra = array() ) {
	return array_merge(
		array(
			'species_id'   => $species_id,
			'species'      => $name,
			'qty_ordered'  => $received,
			'qty_received' => $received,
			'unit_price'   => $price,
			'worth'        => 0,
		),
		$extra
	);
}

/*
 * The species list: the wrasse has only a list price; the goby is linked to a
 * simple product; the blenny to a product with size variations; the tang has
 * no price anywhere; the clown's product has no price, so its list price is
 * used; the shrimp is cheap to land and priced well above the suggestion.
 */
$species = array(
	array( 'id' => 1, 'name' => 'Blue Test Wrasse', 'group_name' => 'Wrasses', 'product_id' => null, 'my_price' => 20.0 ),
	array( 'id' => 2, 'name' => 'Red Test Goby', 'group_name' => 'Gobies', 'product_id' => 21, 'my_price' => 5.0 ),
	array( 'id' => 3, 'name' => 'Green Test Blenny', 'group_name' => 'Blennies', 'product_id' => 30, 'my_price' => 99.0 ),
	array( 'id' => 4, 'name' => 'Yellow Test Tang', 'group_name' => 'Tangs', 'product_id' => null, 'my_price' => null ),
	array( 'id' => 5, 'name' => 'Orange Test Clown', 'group_name' => 'Clowns', 'product_id' => 50, 'my_price' => 30.0 ),
	array( 'id' => 6, 'name' => 'Pink Test Shrimp', 'group_name' => 'Inverts', 'product_id' => null, 'my_price' => 60.0 ),
);
$prices  = array(
	21 => array( 'prices' => array( 28.0 ), 'variations' => array() ),
	30 => array(
		'prices'     => array( 35.0, 60.0 ),
		'variations' => array(
			array( 'id' => 31, 'price' => 35.0, 'attributes' => array( 'pa_size' => 'small' ) ),
			array( 'id' => 32, 'price' => 60.0, 'attributes' => array( 'pa_size' => 'large', 'pa_colour' => 'small' ) ),
			array( 'id' => 33, 'price' => null, 'attributes' => array( 'pa_size' => 'medium' ) ),
		),
	),
	50 => array( 'prices' => array(), 'variations' => array() ),
);

$orders = array(
	fh_order( 1, '2026-06-01', 'Test Supplier One', array(
		fh_line( 1, 'Blue Test Wrasse', 10, 6 ),                                   // 6.00 a fish → 24 → 25
		fh_line( 2, 'Red Test Goby', 4, 8 ),                                       // 8.00 → 32 → 30
		fh_line( 3, 'Green Test Blenny', 2, 11, array( 'size_slug' => 'large' ) ), // 11.00 → 44 → 45
		fh_line( 4, 'Yellow Test Tang', 1, 50 ),                                   // 50.00 → 200
		fh_line( 5, 'Orange Test Clown', 5, 7 ),                                   // 7.00 → 28 → 30
		fh_line( 6, 'Pink Test Shrimp', 20, 2 ),                                   // 2.00 → 8 → 10
	) ),
	fh_order( 2, '2026-03-01', 'Test Supplier Two', array(
		fh_line( 1, 'Blue Test Wrasse', 5, 8 ),
	) ),
	fh_order( 3, '2025-11-01', 'Test Supplier One', array(
		fh_line( 1, 'Blue Test Wrasse', 4, 10 ),
	) ),
	fh_order( 4, '2025-05-01', 'Test Supplier Two', array(
		fh_line( 1, 'Blue Test Wrasse', 2, 20 ),
	) ),
);

echo "1. One row per fish that received fish at a price\n";
$built = Stats_Prices::build( $orders, $species, $prices );
$rows  = array();
foreach ( $built['rows'] as $row ) {
	$rows[ $row['name'] ] = $row;
}
check( 'six fish, each once', 6 === count( $built['rows'] ) && 6 === count( $rows ) && 6 === $built['counts']['all'] );
$none = Stats_Prices::build(
	array(
		fh_order( 1, '2026-06-01', 'Test Supplier One', array(
			fh_line( 1, 'Blue Test Wrasse', 0, 6, array( 'qty_ordered' => 10 ) ),        // received nothing
			fh_line( 2, 'Red Test Goby', 4, 0 ),                                          // free: no price
			fh_line( 3, 'Green Test Blenny', 4, null ),                                   // no price typed
			fh_line( null, '?? small wrasse', 3, 9, array( 'needs_id' => true ) ),        // nobody knows what it is
			fh_line( null, '', 3, 9 ),                                                    // no name
		) ),
	),
	$species,
	$prices
);
check( 'a line that received nothing, a free line, a line without a price, an unknown fish and a nameless line give no row', array() === $none['rows'] && 0 === $none['counts']['all'] );
check( 'no orders: no rows, no suppliers, no groups', array() === Stats_Prices::build( array() )['rows'] && array() === Stats_Prices::build( array() )['suppliers'] && array() === Stats_Prices::build( array() )['groups'] );

echo "2. The landed cost: the latest order, and the average of the last three\n";
$w = $rows['Blue Test Wrasse'];
check( 'the wrasse is in four orders; the latest is 2026-1 from Supplier One (by the order\'s date)', 4 === $w['orders'] && '2026-1' === $w['latest_ref'] && 1 === $w['latest_id'] && '2026-06-01' === $w['latest_date'] && 'Test Supplier One' === $w['supplier'] );
check( 'landed per fish, latest: 6.00', near( $w['landed_latest'], 6 ) );
check( 'the average of the last three: (6 + 8 + 10) ÷ 3 = 8.00 — the plain average of the three orders\' per-fish cost, the fourth (20.00) left out', near( $w['landed_avg'], 8 ) && 3 === $w['avg_orders'] );
check( 'a fish in one order only: the average is that order\'s, over 1', near( $rows['Red Test Goby']['landed_avg'], 8 ) && 1 === $rows['Red Test Goby']['avg_orders'] && 1 === $rows['Red Test Goby']['orders'] );
$shuffled = Stats_Prices::build( array( $orders[3], $orders[1], $orders[0], $orders[2] ), $species, $prices );
$ws       = null;
foreach ( $shuffled['rows'] as $row ) {
	if ( 'Blue Test Wrasse' === $row['name'] ) {
		$ws = $row;
	}
}
check( 'the order the orders come in does not matter: the latest is still by date', '2026-1' === $ws['latest_ref'] && near( $ws['landed_avg'], 8 ) );
$same_day = Stats_Prices::build(
	array(
		fh_order( 7, '2026-06-01', 'Test Supplier One', array( fh_line( 1, 'Blue Test Wrasse', 1, 3 ) ) ),
		fh_order( 9, '2026-06-01', 'Test Supplier One', array( fh_line( 1, 'Blue Test Wrasse', 1, 5 ) ) ),
	),
	$species
);
check( 'two orders on one day: the higher id is the latest', '2026-9' === $same_day['rows'][0]['latest_ref'] && near( $same_day['rows'][0]['landed_latest'], 5 ) );
$two_lines = Stats_Prices::build(
	array(
		fh_order( 1, '2026-06-01', 'Test Supplier One', array(
			fh_line( 1, 'Blue Test Wrasse', 6, 5, array( 'size_slug' => 'small' ) ),
			fh_line( 1, 'Blue Test Wrasse', 2, 13, array( 'size_slug' => 'large' ) ),
		) ),
	),
	$species
);
check( 'two lines of one fish in the latest order: Σ landed ÷ Σ received = (30 + 26) ÷ 8 = 7.00', near( $two_lines['rows'][0]['landed_latest'], 7 ) && 1 === $two_lines['rows'][0]['orders'] );
$dated = Stats_Prices::build(
	array(
		fh_order( 1, '', 'Test Supplier One', array( fh_line( 1, 'Blue Test Wrasse', 1, 3 ) ), array( 'received_date' => null, 'order_date' => '2026-08-01' ) ),
		fh_order( 2, '2026-07-01', 'Test Supplier One', array( fh_line( 1, 'Blue Test Wrasse', 1, 5 ) ) ),
	),
	$species
);
check( 'an order not received yet files under its order date', near( $dated['rows'][0]['landed_latest'], 3 ) && '2026-08-01' === $dated['rows'][0]['latest_date'] );

echo "3. The suggested price: landed per fish × the order's markup, to the nearest 5\n";
check( '6.00 × 4 = 24 → 25', near( $w['suggested'], 25 ) && near( $w['markup'], 4 ) );
check( '8.00 × 4 = 32 → 30; 11.00 × 4 = 44 → 45; 7.00 × 4 = 28 → 30; 2.00 × 4 = 8 → 10; 50.00 × 4 = 200', near( $rows['Red Test Goby']['suggested'], 30 ) && near( $rows['Green Test Blenny']['suggested'], 45 ) && near( $rows['Orange Test Clown']['suggested'], 30 ) && near( $rows['Pink Test Shrimp']['suggested'], 10 ) && near( $rows['Yellow Test Tang']['suggested'], 200 ) );
$round = static function ( $price, $markup = null ) use ( $species ) {
	$built = Stats_Prices::build( array( fh_order( 1, '2026-06-01', 'S', array( fh_line( 1, 'Blue Test Wrasse', 1, $price ) ), null === $markup ? array() : array( 'markup' => $markup ) ) ), $species );
	return $built['rows'][0]['suggested'];
};
check( 'the rounding: 22.4 → 20, 22.5 → 25 (a half goes up), 27.4 → 25, 27.5 → 30, 2.4 → 0, 2.5 → 5', near( $round( 5.6 ), 20 ) && near( $round( 5.625 ), 25 ) && near( $round( 6.85 ), 25 ) && near( $round( 6.875 ), 30 ) && near( $round( 0.6 ), 0 ) && near( $round( 0.625 ), 5 ) );
check( 'the ORDER\'s markup, not a fixed 4: 6.00 × 3 = 18 → 20; × 2.5 = 15; × 5 = 30', near( $round( 6, 3 ), 20 ) && near( $round( 6, 2.5 ), 15 ) && near( $round( 6, 5 ), 30 ) );
$fees    = fh_order( 1, '2026-06-01', 'Test Supplier One', array(
	fh_line( 1, 'Blue Test Wrasse', 12, 6, array( 'per_box' => 24 ) ),
	fh_line( 2, 'Red Test Goby', 6, 9, array( 'box_pct' => 50 ) ),
), array( 'tariffs' => 30, 'domestic_flight' => 80, 'import_fees' => 40, 'markup' => 3.5 ) );
$costing = Costing::compute( $fees, $fees['lines'] );
$feed    = Stats_Prices::build( array( $fees ), $species );
$by      = array();
foreach ( $feed['rows'] as $row ) {
	$by[ $row['name'] ] = $row;
}
check( 'with tariffs, a flight, import fees, a percent of a box and a 3.5 markup it is EXACTLY the costing\'s line figure (landed per fish and suggested price)', near( $by['Blue Test Wrasse']['landed_latest'], $costing['lines'][0]['landed_per_fish'] ) && near( $by['Blue Test Wrasse']['suggested'], $costing['lines'][0]['suggested_price'] ) && near( $by['Red Test Goby']['landed_latest'], $costing['lines'][1]['landed_per_fish'] ) && near( $by['Red Test Goby']['suggested'], $costing['lines'][1]['suggested_price'] ) && $by['Blue Test Wrasse']['landed_latest'] > 6 );
$resolved = Orders::resolve( $fees, array() );
check( '… and the order page\'s own resolved line says the same', near( $by['Red Test Goby']['suggested'], $resolved['lines'][1]['suggested_price'] ) && 0.0 === fmod( $by['Red Test Goby']['suggested'], Costing::PRICE_STEP ) );

echo "4. FisHotel's price: the linked product, else the species list\n";
check( 'not linked: the species list\'s price (20.00)', near( $w['price'], 20 ) && 'list' === $w['price_source'] && null === $w['product_id'] );
check( 'linked to a product with a price: the product\'s current price (28.00) wins over the list\'s (5.00)', near( $rows['Red Test Goby']['price'], 28 ) && 'product' === $rows['Red Test Goby']['price_source'] && 21 === $rows['Red Test Goby']['product_id'] && false === $rows['Red Test Goby']['price_from'] );
check( 'linked to a product that has NO price: the species list\'s price (30.00)', near( $rows['Orange Test Clown']['price'], 30 ) && 'list' === $rows['Orange Test Clown']['price_source'] && 50 === $rows['Orange Test Clown']['product_id'] );
check( 'no product price and no list price: no price on file (null — not 0)', null === $rows['Yellow Test Tang']['price'] && '' === $rows['Yellow Test Tang']['price_source'] && null === $rows['Yellow Test Tang']['gap'] && null === $rows['Yellow Test Tang']['margin'] && false === $rows['Yellow Test Tang']['under'] );
check( 'a product with variations: the variation of the size the fish came in (large → 60.00), not the list price (99.00)', near( $rows['Green Test Blenny']['price'], 60 ) && 'variation' === $rows['Green Test Blenny']['price_source'] && false === $rows['Green Test Blenny']['price_from'] );
check( 'fish_price: no size, or a size no variation has → the lowest variation price, marked "from"', array( 'price' => 35.0, 'source' => 'product', 'from' => true, 'product_id' => 30 ) === Stats_Prices::fish_price( $species[2], $prices ) && array( 'price' => 35.0, 'source' => 'product', 'from' => true, 'product_id' => 30 ) === Stats_Prices::fish_price( $species[2], $prices, 'xl' ) );
check( 'fish_price: the size is read from a size attribute only (large\'s colour is "small"), any case; a variation without a price is not a price', near( Stats_Prices::fish_price( $species[2], $prices, 'SMALL' )['price'], 35 ) && 'variation' === Stats_Prices::fish_price( $species[2], $prices, 'small' )['source'] && 'product' === Stats_Prices::fish_price( $species[2], $prices, 'medium' )['source'] );
$mixed_sizes = Stats_Prices::build(
	array(
		fh_order( 1, '2026-06-01', 'S', array(
			fh_line( 3, 'Green Test Blenny', 2, 11, array( 'size_slug' => 'large' ) ),
			fh_line( 3, 'Green Test Blenny', 2, 9, array( 'size_slug' => 'small' ) ),
		) ),
	),
	$species,
	$prices
);
check( 'the fish came in two sizes: no one variation answers, so the lowest price, "from"', near( $mixed_sizes['rows'][0]['price'], 35 ) && 'product' === $mixed_sizes['rows'][0]['price_source'] && true === $mixed_sizes['rows'][0]['price_from'] );
check( 'product_price: one variation price for all → not "from"; no variations → the product\'s own lowest; nothing → none', array( 'price' => 40.0, 'source' => 'product', 'from' => false ) === Stats_Prices::product_price( array( 'variations' => array( array( 'price' => 40.0 ), array( 'price' => '40' ) ) ) ) && array( 'price' => 12.5, 'source' => 'product', 'from' => false ) === Stats_Prices::product_price( array( 'prices' => array( '12.5' ) ) ) && null === Stats_Prices::product_price( array() )['price'] && null === Stats_Prices::product_price( array( 'prices' => array( 0, '', 'x', -3 ) ) )['price'] );
check( 'fish_price: no species row → none; a list price of 0 is no price', null === Stats_Prices::fish_price( null, $prices )['price'] && null === Stats_Prices::fish_price( array( 'id' => 9, 'my_price' => 0.0 ), array() )['price'] && near( Stats_Prices::fish_price( array( 'id' => 9, 'my_price' => '12.50' ), array() )['price'], 12.5 ) );
$typed = Stats_Prices::build(
	array(
		fh_order( 1, '2026-06-01', 'S', array(
			fh_line( null, '  blue TEST wrasse ', 2, 6 ),
			fh_line( null, 'Unlisted Test Fish', 2, 6, array( 'group_name' => '' ) ),
		) ),
	),
	$species
);
$typed_by = array();
foreach ( $typed['rows'] as $row ) {
	$typed_by[ $row['key'] ] = $row;
}
check( 'a line typed with the exact name of a list row is that row (its name, group and price); a fish in no list has no price on file', isset( $typed_by['s:1'] ) && 'Blue Test Wrasse' === $typed_by['s:1']['name'] && 'Wrasses' === $typed_by['s:1']['group'] && near( $typed_by['s:1']['price'], 20 ) && isset( $typed_by['n:unlisted test fish'] ) && null === $typed_by['n:unlisted test fish']['price'] && null === $typed_by['n:unlisted test fish']['species_id'] );

echo "5. The gap, the margin, and who is under\n";
check( 'the wrasse: 20.00 against a suggested 25 → gap +5.00, under; margin (20 − 6) ÷ 6', near( $w['gap'], 5 ) && true === $w['under'] && near( $w['margin'], 14 / 6 ) );
check( 'the goby: 28.00 against 30 → gap +2.00, under; margin (28 − 8) ÷ 8 = 2.5', near( $rows['Red Test Goby']['gap'], 2 ) && true === $rows['Red Test Goby']['under'] && near( $rows['Red Test Goby']['margin'], 2.5 ) );
check( 'the blenny: 60.00 against 45 → gap −15.00, not under', near( $rows['Green Test Blenny']['gap'], -15 ) && false === $rows['Green Test Blenny']['under'] );
check( 'the clown: 30.00 against 30 → gap 0, NOT under (a price at the suggestion is fine)', near( $rows['Orange Test Clown']['gap'], 0 ) && false === $rows['Orange Test Clown']['under'] );
check( 'the shrimp: 60.00 against 10 → gap −50.00, margin 29', near( $rows['Pink Test Shrimp']['gap'], -50 ) && near( $rows['Pink Test Shrimp']['margin'], 29 ) );
check( 'counts: 6 fish, 2 under, 1 with no price on file', array( 'all' => 6, 'under' => 2, 'no_price' => 1 ) === $built['counts'] );

echo "6. What the list shows\n";
$under = Stats_Prices::select( $built['rows'] );
check( 'by default only the fish priced under the suggestion, the biggest gap first', array( 'Blue Test Wrasse', 'Red Test Goby' ) === array_column( $under, 'name' ) );
$all = Stats_Prices::select( $built['rows'], true );
check( '"show all": the under ones first, then the rest by gap (0, −15, −50), then the fish with no price on file', array( 'Blue Test Wrasse', 'Red Test Goby', 'Orange Test Clown', 'Green Test Blenny', 'Pink Test Shrimp', 'Yellow Test Tang' ) === array_column( $all, 'name' ) );
check( 'build() hands the rows over in that same order', array_column( $all, 'name' ) === array_column( $built['rows'], 'name' ) );
check( 'nothing under: an empty list, and "show all" still has them', array() === Stats_Prices::select( array( $rows['Pink Test Shrimp'], $rows['Yellow Test Tang'] ) ) && 2 === count( Stats_Prices::select( array( $rows['Pink Test Shrimp'], $rows['Yellow Test Tang'] ), true ) ) );
$tie = Stats_Prices::select( array( array_merge( $w, array( 'name' => 'Zeta', 'gap' => 5.0 ) ), array_merge( $w, array( 'name' => 'alpha', 'gap' => 5.0 ) ), array_merge( $w, array( 'name' => 'Beta', 'gap' => 9.0 ) ) ) );
check( 'the same gap: by name, without regard to case', array( 'Beta', 'alpha', 'Zeta' ) === array_column( $tie, 'name' ) );

echo "7. Filters: by group, by supplier\n";
check( 'the suppliers and the groups offered: every one in use, sorted', array( 'Test Supplier One', 'Test Supplier Two' ) === $built['suppliers'] && array( 'Blennies', 'Clowns', 'Gobies', 'Inverts', 'Tangs', 'Wrasses' ) === $built['groups'] );
$gobies = Stats_Prices::build( $orders, $species, $prices, array( 'group' => 'Gobies' ) );
check( 'group = Gobies: one fish; the group list still offers them all', array( 'Red Test Goby' ) === array_column( $gobies['rows'], 'name' ) && 1 === $gobies['counts']['all'] && 6 === count( $gobies['groups'] ) );
$two = Stats_Prices::build( $orders, $species, $prices, array( 'supplier' => 'test supplier TWO' ) );
check( 'supplier = Two (any case): the wrasse costed from that supplier\'s orders alone — latest 2026-2 at 8.00, average (8 + 20) ÷ 2, suggested 30', 1 === count( $two['rows'] ) && '2026-2' === $two['rows'][0]['latest_ref'] && near( $two['rows'][0]['landed_latest'], 8 ) && near( $two['rows'][0]['landed_avg'], 14 ) && 2 === $two['rows'][0]['avg_orders'] && near( $two['rows'][0]['suggested'], 30 ) && near( $two['rows'][0]['gap'], 10 ) && 2 === count( $two['suppliers'] ) );
check( 'both filters; a supplier nobody ordered from → nothing', array() === Stats_Prices::build( $orders, $species, $prices, array( 'supplier' => 'Test Supplier Two', 'group' => 'Gobies' ) )['rows'] && array() === Stats_Prices::build( $orders, $species, $prices, array( 'supplier' => 'Nobody' ) )['rows'] );

echo "8. On the store: the view reads everything and writes nothing\n";
$store = new Fake_Store( array( 21 => 'Red Test Goby (shop)', 30 => 'Green Test Blenny (shop)', 50 => 'Orange Test Clown (shop)' ) );
foreach ( $species as $row ) {
	$store->save_species( Species::normalize( $row ) );
}
$store->product_prices = $prices;
foreach ( $orders as $order ) {
	$id = $store->save_order( Orders::normalize_order( array_merge( $order, array( 'id' => 0 ) ) ) );
	$store->save_order_lines( $id, Orders::normalize_lines( $order['lines'] ) );
}
$before = serialize( array( $store->species, $store->orders, $store->lines, $store->products, $store->product_prices, $GLOBALS['fh_options'] ) );
$data   = Admin_Stats::prices_data( $store, array() );
check( 'prices_data: the two under-priced fish, the counts, the filters as cleaned, both export links', array( 'Blue Test Wrasse', 'Red Test Goby' ) === array_column( $data['rows'], 'name' ) && array( 'all' => 6, 'under' => 2, 'no_price' => 1 ) === $data['counts'] && array( 'supplier' => '', 'group' => '', 'all' => false ) === $data['filters'] && false !== strpos( $data['exports']['xlsx'], 'what=prices' ) && false !== strpos( $data['exports']['csv'], 'format=csv' ) );
$shown = Admin_Stats::prices_data( $store, array( 'all' => true, 'group' => ' Gobies ' ) );
check( '"show all" + a group: that group\'s fish, and the export link carries both', array( 'Red Test Goby' ) === array_column( $shown['rows'], 'name' ) && 'Gobies' === $shown['filters']['group'] && false !== strpos( $shown['exports']['xlsx'], 'group=Gobies' ) && false !== strpos( $shown['exports']['xlsx'], 'all=1' ) );
check( 'the request → the filters: text cleaned and cut, "all" only for 1', array( 'supplier' => 'Test Supplier Two', 'group' => 'Gobies', 'all' => true ) === Admin_Stats::prices_filters( array( 'supplier' => ' <b>Test Supplier Two</b> ', 'group' => 'Gobies', 'all' => '1' ) ) && false === Admin_Stats::prices_filters( array( 'all' => 'yes' ) )['all'] && '' === Admin_Stats::prices_filters( array( 'group' => array( 'x' ) ) )['group'] );
check( 'READ-ONLY: not a species, an order, a line, a product, a price or an option changed', serialize( array( $store->species, $store->orders, $store->lines, $store->products, $store->product_prices, $GLOBALS['fh_options'] ) ) === $before );
$bare = new Fake_Store();
check( 'an empty store: no rows, zero counts', array() === Admin_Stats::prices_data( $bare, array() )['rows'] && 0 === Admin_Stats::prices_data( $bare, array( 'all' => true ) )['counts']['all'] );

echo "9. The export sheet\n";
$sheet = Admin_Stats::prices_rows( $data['rows'] );
$first = array_combine( $sheet[0], $sheet[1] );
check( 'a header and one row per fish shown', 3 === count( $sheet ) && 'species' === $sheet[0][0] && 'Red Test Goby' === $sheet[2][0] );
check( 'typed cells: text, floats, ints, bools — the wrasse: 6.00, 8.00 over 3, × 4, suggested 25, price 20 from the list, gap 5, under', 'Blue Test Wrasse' === $first['species'] && 'Wrasses' === $first['group'] && '2026-1' === $first['latest_order'] && '2026-06-01' === $first['latest_date'] && 6.0 === $first['landed_per_fish_latest'] && 8.0 === $first['landed_per_fish_avg'] && 3 === $first['avg_over_orders'] && 4.0 === $first['markup'] && 25.0 === $first['suggested_price'] && 20.0 === $first['fishotel_price'] && 'list' === $first['price_source'] && false === $first['price_is_lowest_variation'] && 5.0 === $first['gap'] && true === $first['under_suggested'] && 4 === $first['orders'] && 1 === $first['species_id'] && null === $first['product_id'] );
$full = Admin_Stats::prices_rows( Admin_Stats::prices_data( $store, array( 'all' => true ) )['rows'] );
$tang = array_combine( $full[0], $full[6] );
check( 'a fish with no price on file exports empty cells, never 0', 7 === count( $full ) && 'Yellow Test Tang' === $tang['species'] && null === $tang['fishotel_price'] && null === $tang['gap'] && null === $tang['margin'] && false === $tang['under_suggested'] && '' === $tang['price_source'] );
check( 'the labels: where a price comes from', 'the species list' === Admin_Stats::price_source_label( 'list' ) && 'the product' === Admin_Stats::price_source_label( 'product' ) && 'the product, lowest variation' === Admin_Stats::price_source_label( 'product', true ) && 'the variation of that size' === Admin_Stats::price_source_label( 'variation' ) && 'no price on file' === Admin_Stats::price_source_label( '' ) );

fh_finish();
