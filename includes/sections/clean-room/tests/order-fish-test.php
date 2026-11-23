<?php
/**
 * The fish of the fish orders in the fish search (Order_Fish).
 *
 * A fish that is on a fish order — typed on a line, or imported as
 * "use as typed (unlisted fish)" — is "in the system", so every search
 * box of the section finds it: which fish are offered (linked lines, a
 * Species row, a product, a "??" fish, a closed order, a fish alive in
 * a tank, two spellings, two orders), where the search says they come
 * from, how they rank under the products and the Species rows, what
 * picking one sets and saves (an unlisted fish with that spelling — no
 * product, no Species row, nothing created), that the Requests section's
 * own suggestions do not name them, and the cache: read once, kept, and
 * dropped by each write that changes the answer — on the real Store
 * against a database stand-in that only records what it is asked.
 *
 * Every fish, supplier and number here is invented.
 *
 * Run from the plugin root:  php includes/sections/clean-room/tests/order-fish-test.php
 */

require_once __DIR__ . '/wp-stubs.php';

use FisHotel\Misc\Sections\Clean_Room\Admin;
use FisHotel\Misc\Sections\Clean_Room\Clean_Room;
use FisHotel\Misc\Sections\Clean_Room\Front_Desk;
use FisHotel\Misc\Sections\Clean_Room\Identify;
use FisHotel\Misc\Sections\Clean_Room\Order_Fish;
use FisHotel\Misc\Sections\Clean_Room\Orders;
use FisHotel\Misc\Sections\Clean_Room\Replay;
use FisHotel\Misc\Sections\Clean_Room\Settings;
use FisHotel\Misc\Sections\Clean_Room\Species;
use FisHotel\Misc\Sections\Clean_Room\Store;
use FisHotel\Misc\Sections\Clean_Room\Tests\Fake_Store;

$today    = Replay::date_in_zone( time(), wp_timezone() );
$settings = Settings::get();

/* ------------------------------------------------------------------ */
echo "1. Which fish are offered\n";

$known = Order_Fish::known( array( 12 => 'Blue Test Wrasse', 13 => 'Orange  Test Clown' ), array( 'Green Test Blenny', '' ) );
check( 'the names the lists know, case and spacing aside: every fish product\'s title and every Species row\'s name', array( 'blue test wrasse', 'orange test clown', 'green test blenny' ) === array_keys( $known ) );

$line = static function ( $id, $species, $ref, $status = 'ordered', array $more = array() ) {
	return array_merge( array( 'id' => $id, 'species' => $species, 'sci_name' => '', 'needs_id' => 0, 'species_id' => null, 'order_ref' => $ref, 'order_status' => $status ), $more );
};
$stay = static function ( $id, $species, $tank, array $more = array() ) {
	return array_merge( array( 'id' => $id, 'species' => $species, 'sci_name' => '', 'needs_id' => 0, 'product_id' => null, 'qty_live' => 2, 'status' => 'in_qt', 'tank_number' => $tank ), $more );
};

$lines = array(
	$line( 1, 'Zebra Sample Goby', '2026-7', 'draft' ),
	$line( 2, 'Golden Sample Tang', '2026-8', 'ordered', array( 'sci_name' => 'Samplus aureus' ) ),
	$line( 3, 'Dusky Sample Eel', '2026-9', 'received' ),
	$line( 4, 'Closed Sample Fish', '2025-3', 'closed' ),
	$line( 5, 'Listed Sample Fish', '2026-9', 'received', array( 'species_id' => 44 ) ),
	$line( 6, 'green test BLENNY', '2026-9', 'received' ),
	$line( 7, ' blue  test wrasse ', '2026-9', 'received' ),
	$line( 8, '?? small wrasse', '2026-9', 'received', array( 'needs_id' => 1 ) ),
	$line( 9, '??', '2026-9', 'received' ),
	$line( 10, '', '2026-9', 'received' ),
	$line( 11, 'Hippo Sample Tang', '2026-9', 'received', array( 'stay_product_id' => 12 ) ),
);
$fish  = Order_Fish::offered( $lines, array(), $known );
check_same( 'the fish of the lines of the orders that are not closed — draft, ordered, received —, by name', array( 'Dusky Sample Eel', 'Golden Sample Tang', 'Zebra Sample Goby' ), array_column( $fish, 'name' ) );
check( 'not offered: a line of a closed order, a line linked to a Species row, a line named like a Species row or like a product (any case, any spacing), a line whose group in the tank is a product (checked in under a learned name), a "??" line — flagged or only named so —, a line with no name', ! array_intersect( array( 'Closed Sample Fish', 'Listed Sample Fish', 'green test BLENNY', 'blue test wrasse', 'Hippo Sample Tang', '?? small wrasse', '??', '' ), array_column( $fish, 'name' ) ) );
check_same( 'each with its scientific name when the line has one, and the order it is on', array( array( 'name' => 'Golden Sample Tang', 'sci' => 'Samplus aureus', 'from' => 'order', 'ref' => '2026-8', 'tank' => null ) ), array_values( array_filter( $fish, static function ( $one ) { return 'Golden Sample Tang' === $one['name']; } ) ) );

$twice = Order_Fish::offered(
	array(
		$line( 3, 'golden sample tang', '2026-8', 'ordered', array( 'sci_name' => 'Samplus aureus' ) ),
		$line( 12, 'Golden  Sample TANG', '2026-11', 'draft' ),
		$line( 7, 'Golden Sample Tang', '2026-9', 'received' ),
	),
	array(),
	$known
);
check_same( 'one fish on three lines of three orders, spelled three ways: offered ONCE, in the spelling of its newest line (spacing tidied) with that line\'s order — and the scientific name an older line knows', array( array( 'name' => 'Golden Sample TANG', 'sci' => 'Samplus aureus', 'from' => 'order', 'ref' => '2026-11', 'tank' => null ) ), $twice );

$stays = array(
	$stay( 1, 'Closed Sample Fish', 512 ),
	$stay( 2, 'Hand Sample Puffer', 306, array( 'sci_name' => 'Samplus manus' ) ),
	$stay( 3, 'Gone Sample Fish', 307, array( 'qty_live' => 0 ) ),
	$stay( 4, 'Shipped Sample Fish', 308, array( 'status' => 'closed' ) ),
	$stay( 5, 'Blue Test Wrasse', 309, array( 'product_id' => 12 ) ),
	$stay( 6, 'Linked Sample Fish', 310, array( 'product_id' => 77 ) ),
	$stay( 7, '?? odd eel', 311, array( 'needs_id' => 1 ) ),
	$stay( 8, 'Green Test Blenny', 312 ),
	$stay( 9, 'Dusky Sample Eel', 313 ),
	$stay( 10, 'Tankless Sample Fish', 0 ),
);
$fish  = Order_Fish::offered( $lines, $stays, $known );
check_same( 'plus any unlisted fish that is ALIVE IN A TANK — the fish of a closed order, a fish added by hand', array( 'Closed Sample Fish' => 512, 'Hand Sample Puffer' => 306, 'Tankless Sample Fish' => null ), array_column( array_values( array_filter( $fish, static function ( $one ) { return 'tank' === $one['from']; } ) ), 'tank', 'name' ) );
check( '… with the scientific name of the line it was checked in from', 'Samplus manus' === array_column( $fish, 'sci', 'name' )['Hand Sample Puffer'] );
check( 'not offered: a group that is gone or closed, a group with a product, a "??" group, a group named like a Species row', ! array_intersect( array( 'Gone Sample Fish', 'Shipped Sample Fish', 'Blue Test Wrasse', 'Linked Sample Fish', '?? odd eel', 'Green Test Blenny' ), array_column( $fish, 'name' ) ) );
check( 'not offered either: a group whose order line is linked to a Species row — the row was renamed since, the group still carries the old name', ! in_array( 'Old Sample Puffer', array_column( Order_Fish::offered( array(), array( $stay( 11, 'Old Sample Puffer', 522, array( 'line_species_id' => 44 ) ) ), $known ), 'name' ), true ) && array( 'Old Sample Puffer' ) === array_column( Order_Fish::offered( array(), array( $stay( 11, 'Old Sample Puffer', 522, array( 'line_species_id' => null ) ) ), $known ), 'name' ) );
check( 'a fish that is on an open order AND in a tank is offered once, as the order line\'s', 1 === count( array_keys( array_column( $fish, 'name' ), 'Dusky Sample Eel', true ) ) && 'order' === array_column( $fish, 'from', 'name' )['Dusky Sample Eel'] && '2026-9' === array_column( $fish, 'ref', 'name' )['Dusky Sample Eel'] );
check( 'the rows may come in any order: the newest line is found by its id', $twice === Order_Fish::offered( array( $line( 12, 'Golden  Sample TANG', '2026-11', 'draft' ), $line( 7, 'Golden Sample Tang', '2026-9', 'received' ), $line( 3, 'golden sample tang', '2026-8', 'ordered', array( 'sci_name' => 'Samplus aureus' ) ) ), array(), $known ) );
$many = array();
for ( $i = 1; $i <= Order_Fish::MAX_OFFERED + 50; $i++ ) {
	$many[] = $line( $i, sprintf( 'Sample Fish %04d', $i ), '2026-1' );
}
$capped = array_column( Order_Fish::offered( $many, array( $stay( 1, 'Zulu Sample Tankfish', 512 ) ), array() ), 'from', 'name' );
check( '… and what is cut is the OLDEST lines\' fish, not the end of the alphabet: the newest line\'s fish is kept, a fish alive in a tank is kept, the 51 oldest lines\' are not', Order_Fish::MAX_OFFERED === count( $capped ) && isset( $capped[ sprintf( 'Sample Fish %04d', Order_Fish::MAX_OFFERED + 50 ) ] ) && 'tank' === ( $capped['Zulu Sample Tankfish'] ?? '' ) && ! isset( $capped['Sample Fish 0001'] ) && ! isset( $capped['Sample Fish 0051'] ) && isset( $capped['Sample Fish 0052'] ) && array_keys( $capped ) === array_values( array_unique( array_keys( $capped ) ) ) && 'Sample Fish 0052' === array_keys( $capped )[0] );
check( 'the list that is kept is bounded', Order_Fish::MAX_OFFERED === count( Order_Fish::offered( $many, array(), array() ) ) && array() === Order_Fish::offered( array(), array(), $known ) );

/* ------------------------------------------------------------------ */
echo "2. Where it comes from, and the item the search box gets\n";

check_same( 'the words', array( 'on fish order 2026-9', 'on a fish order', 'in tank 512', 'in a tank' ), array( Order_Fish::where( array( 'from' => 'order', 'ref' => '2026-9' ) ), Order_Fish::where( array( 'from' => 'order', 'ref' => '' ) ), Order_Fish::where( array( 'from' => 'tank', 'tank' => 512 ) ), Order_Fish::where( array( 'from' => 'tank', 'tank' => null ) ) ) );
$item = Order_Fish::suggestion( array( 'name' => 'Golden Sample Tang', 'sci' => 'Samplus aureus', 'from' => 'order', 'ref' => '2026-8', 'tank' => null ) );
check_same( 'the item: the search box\'s own shape, NO product and NO Species row — an unlisted fish —, and where it comes from', array( 'product_id' => null, 'name' => 'Golden Sample Tang', 'common' => '', 'sci' => 'Samplus aureus', 'match' => 'order', 'alias' => '', 'where' => 'on fish order 2026-8', 'ref' => '2026-8' ), $item );
check( '… a fish in a tank says so, and names no fish order', 'tank' === Order_Fish::suggestion( array( 'name' => 'x', 'from' => 'tank', 'tank' => 512 ) )['match'] && '' === Order_Fish::suggestion( array( 'name' => 'x', 'from' => 'tank', 'tank' => 512, 'ref' => '2026-8' ) )['ref'] && 'in tank 512' === Order_Fish::suggestion( array( 'name' => 'x', 'from' => 'tank', 'tank' => 512 ) )['where'] );
check( '… and it never says "supplier order"', false === stripos( (string) file_get_contents( Clean_Room::path() . 'class-order-fish.php' ), 'supplier order' ) );

$found = static function ( $term ) use ( $fish ) {
	return array_column( Order_Fish::matching( $fish, $term ), 'name' );
};
check_same( 'found like a product is: every word typed stands in the name or in the scientific name, any case', array( array( 'Golden Sample Tang' ), array( 'Golden Sample Tang' ), array( 'Golden Sample Tang' ), array( 'Hand Sample Puffer' ), array(), array() ), array( $found( 'golden' ), $found( 'TANG gold' ), $found( 'aureus' ), $found( 'puffer manus' ), $found( 'golden puffer' ), $found( '   ' ) ) );

/* ------------------------------------------------------------------ */
echo "3. Ranked below the products and the Species rows that answer as well\n";

check_same( 'how well a NAME answers: it is what was typed, it starts with it, every word stands in it, or it was found by something else', array( 0, 1, 2, 2, 3, 3, 3 ), array( Order_Fish::quality( 'Golden Sample Tang', ' golden  sample TANG ' ), Order_Fish::quality( 'Golden Sample Tang', 'gold' ), Order_Fish::quality( 'Golden Sample Tang', 'tang' ), Order_Fish::quality( 'Golden Sample Tang', 'tang gold' ), Order_Fish::quality( 'Golden Sample Tang', 'aureus' ), Order_Fish::quality( '', 'x' ), Order_Fish::quality( 'x', '' ) ) );

$p       = static function ( $id, $name, $match = 'title', array $more = array() ) {
	return array_merge( array( 'product_id' => $id, 'name' => $name, 'common' => '', 'sci' => '', 'match' => $match, 'alias' => '' ), $more );
};
$offered = array(
	array( 'name' => 'Golden Sample Tang', 'sci' => 'Samplus aureus', 'from' => 'order', 'ref' => '2026-8', 'tank' => null ),
	array( 'name' => 'Sample Tang Deluxe', 'sci' => '', 'from' => 'tank', 'ref' => '', 'tank' => 512 ),
);
$items   = array( $p( 21, 'Sample Tang Azure' ), $p( 22, 'Royal Sample Tang' ), $p( null, 'Sample Tangerine Goby', 'species', array( 'species_id' => 5 ) ), $p( 23, 'Purple Fish', 'sci', array( 'sci' => 'Sample tang' ) ) );
$placed  = Order_Fish::place( $items, $offered, 'sample tang', 10 );
check_same( 'the same quality of match: UNDER the products and the Species rows — the fish that starts with the term comes after the last product or Species row that does, the fish that only holds the words after it; a product found by its scientific name only (a worse answer) stays under them', array( 'Sample Tang Azure', 'Royal Sample Tang', 'Sample Tangerine Goby', 'Sample Tang Deluxe', 'Golden Sample Tang', 'Purple Fish' ), array_column( $placed, 'name' ) );
check( 'the products and the Species rows keep the order they came in, and are the same items', $items === array_values( array_filter( $placed, static function ( $one ) { return ! isset( $one['where'] ); } ) ) );
$tied = Order_Fish::place( array( $p( 22, 'Royal Sample Tang' ), $p( 21, 'Sample Tang Azure' ) ), $offered, 'sample tang', 10 );
check_same( 'a fish is put right after the LAST listed entry that answers at least as well — never above a product or a Species row of its own quality', array( 'Royal Sample Tang', 'Sample Tang Azure', 'Sample Tang Deluxe', 'Golden Sample Tang' ), array_column( $tied, 'name' ) );
$exact = array_merge( $offered, array( array( 'name' => 'Sample Tang', 'sci' => '', 'from' => 'order', 'ref' => '2026-9', 'tank' => null ) ) );
check_same( 'a better answer than anything listed comes first: the fish that IS what was typed, above the products that only start with it', array( 'Sample Tang', 'Sample Tang Azure', 'Sample Tang Deluxe', 'Royal Sample Tang', 'Golden Sample Tang' ), array_column( Order_Fish::place( array( $p( 21, 'Sample Tang Azure' ), $p( 22, 'Royal Sample Tang' ) ), $exact, 'sample tang', 10 ), 'name' ) );
check_same( '… and when a product IS what was typed, the fish of that name is that product: listed once, as the product', array( array( 'Sample Tang', 'Sample Tang Deluxe', 'Golden Sample Tang' ), 24 ), array( array_column( Order_Fish::place( array( $p( 24, 'Sample Tang' ) ), $exact, 'sample tang', 10 ), 'name' ), Order_Fish::place( array( $p( 24, 'Sample Tang' ) ), $exact, 'sample tang', 10 )[0]['product_id'] ) );
check_same( 'the row cap holds, and cuts from the bottom', array( 'Sample Tang Azure', 'Royal Sample Tang', 'Sample Tangerine Goby', 'Sample Tang Deluxe' ), array_column( Order_Fish::place( $items, $offered, 'sample tang', 4 ), 'name' ) );
$pj     = array( array( 'name' => 'PJ Sample Cardinal', 'sci' => '', 'from' => 'order', 'ref' => '2026-9', 'tank' => null ) );
$learnt = array( $p( 31, 'Pajama Sample Cardinalfish', 'alias', array( 'alias' => 'pj sample cardinal' ) ) );
check_same( 'a product found by a LEARNED NAME answers as well as that name does: typed "pj", the product "pj sample cardinal" was learned for stays above the fish a line calls "PJ Sample Cardinal"', array( 'Pajama Sample Cardinalfish', 'PJ Sample Cardinal' ), array_column( Order_Fish::place( $learnt, $pj, 'pj', 10 ), 'name' ) );
check_same( '… a product found by one of its COMMON NAMES likewise ("Spotted Sample Cardinal, PJ Sample Cardinal")', array( 'Pajama Sample Cardinalfish', 'PJ Sample Cardinal' ), array_column( Order_Fish::place( array( $p( 31, 'Pajama Sample Cardinalfish', 'common', array( 'common' => 'Spotted Sample Cardinal, PJ Sample Cardinal' ) ) ), $pj, 'pj', 10 ), 'name' ) );
check_same( 'how well an ITEM answers: the best of its name, its learned name and each common name', array( 1, 1, 0, 2, 3 ), array( Order_Fish::item_quality( $learnt[0], 'pj' ), Order_Fish::item_quality( array( 'name' => 'Pajama Sample Cardinalfish', 'common' => 'Spotted Sample Cardinal / PJ Sample Cardinal' ), 'pj sam' ), Order_Fish::item_quality( array( 'name' => 'x', 'common' => 'a, PJ Sample Cardinal' ), 'pj sample cardinal' ), Order_Fish::item_quality( array( 'name' => 'Pajama Sample Cardinalfish' ), 'sample' ), Order_Fish::item_quality( array( 'name' => 'Pajama Sample Cardinalfish', 'sci' => 'Samplus pj' ), 'pj' ) ) );
$alias = array( array( 'product_id' => null, 'name' => 'golden sample tang', 'common' => '', 'sci' => '', 'match' => 'alias', 'alias' => 'goldie' ) );
check( 'a fish whose name is already listed (a learned name of it) is not listed twice', array( 'golden sample tang' ) === array_column( Order_Fish::place( $alias, $offered, 'gold', 10 ), 'name' ) );
check( 'no fish found: the list as it was, capped; nothing typed: nothing added', $items === Order_Fish::place( $items, $offered, 'zzz', 10 ) && array_slice( $items, 0, 2 ) === Order_Fish::place( $items, $offered, 'zzz', 2 ) && $items === Order_Fish::place( $items, $offered, '', 10 ) );

/* ------------------------------------------------------------------ */
echo "4. On the store: a fish order's fish is found, and stops being offered once it is listed\n";

$store = new Fake_Store( array( 12 => 'Blue Test Wrasse', 13 => 'Sample Tang Azure' ) );
$store->save_species( Species::normalize( array( 'name' => 'Green Test Blenny', 'sci_name' => 'Ecsenius testus' ) ) );
$order = $store->save_order( Orders::normalize_order( array( 'ref' => '2026-9', 'supplier' => 'Example Aquatics', 'status' => 'ordered' ) ) );
$store->save_order_lines(
	$order,
	Orders::normalize_lines(
		array(
			array( 'species' => 'Golden Sample Tang', 'sci_name' => 'Samplus aureus', 'qty_ordered' => 3 ),
			array( 'species' => 'Blue Test Wrasse', 'qty_ordered' => 2 ),
			array( 'species' => 'Green Test Blenny', 'species_id' => 1, 'qty_ordered' => 2 ),
			array( 'species' => '?? small wrasse', 'needs_id' => '1', 'qty_ordered' => 1 ),
		)
	)
);
$items = $store->fish_search( 'tang', 8 );
check_same( 'the search boxes find it under the product that answers as well, with its scientific name and "on fish order 2026-9"', array( array( 'Sample Tang Azure', 'Golden Sample Tang' ), 'on fish order 2026-9', 'Samplus aureus', null ), array( array_column( $items, 'name' ), $items[1]['where'], $items[1]['sci'], $items[1]['product_id'] ) );
check( 'by its scientific name too', array( 'Golden Sample Tang' ) === array_column( $store->fish_search( 'aureus' ), 'name' ) );
check( 'a line that IS a product or a Species row is found as that — once, and not as an order-line fish; a "??" line is not found', array( 'title' ) === array_column( $store->fish_search( 'wrasse' ), 'match' ) && array( 'species' ) === array_column( $store->fish_search( 'blenny' ), 'match' ) && array() === $store->fish_search( 'small' ) );
check( 'the lists\' own suggestions — what the Requests section names to a customer — do not hold it', array( 'Sample Tang Azure' ) === array_column( $store->species_suggestions( 'tang', 8 ), 'name' ) && array() === $store->species_suggestions( 'aureus' ) );
check( 'nothing typed: nothing', array() === $store->fish_search( '  ' ) );

// What picking it saves: an unlisted fish with the line's own spelling.
$fd     = array(
	'store'    => $store,
	'settings' => $settings,
	'today'    => $today,
	'tank'     => $store->tank_by_number( 521 ),
	'base'     => array( 'actor_id' => 8, 'source' => 'qr' ),
);
$picked = $store->fish_search( 'golden', 8 )[0];
$as     = Identify::fish_from( $store, array( 'species' => $picked['name'], 'product_id' => (int) $picked['product_id'] ), $settings );
check_same( 'picking it names the fish exactly as "use as an unlisted fish" does with that spelling: the name, no product, no Species row', array( 'Golden Sample Tang', false, null, null ), array( $as['name'], $as['unknown'], $as['product_id'], $as['species_id'] ) );
check( '… the very answer a typed name gets', $as === Identify::fish_from( $store, array( 'species' => 'Golden Sample Tang', 'product_id' => 0 ), $settings ) );
$counts = array( count( $store->species_list() ), $store->species_count() );
$r      = Front_Desk::perform( 'arrival', $fd, array( 'species' => $picked['name'], 'product_id' => (int) $picked['product_id'], 'qty' => 3 ) );
$group  = $store->stay( 1, $today, $settings );
$on     = $store->order( $order )['lines'][0];
check( 'an Arrival with it: the group in the tank is that fish, linked to no product', empty( $r['error'] ) && 'Golden Sample Tang' === $group['species'] && null === $group['product_id'] && false === $group['needs_id'] && 3 === $group['qty_live'] );
check( '… and reads as the order line does wherever fish are grouped by name', $group['species'] === $on['species'] && Identify::same_fish( $group['species'], $on['species'] ) && 1 === $store->open_stay_counts()['name'][ Species::key( $on['species'] ) ] );
check( '… no Species row and no product was created by it', $counts === array( count( $store->species_list() ), $store->species_count() ) && null === $store->species_by_name( 'Golden Sample Tang' ) && null === $on['species_id'] );
check( '… a phone Arrival that carries the item\'s fish order ref writes it on the group — the group itself is linked to no line: the order\'s own Check in is what fills the line', '2026-9' === $picked['ref'] && null === $group['order_ref'] && null === $on['stay_id'] );
$store2 = clone $store;
Front_Desk::perform( 'arrival', array_merge( $fd, array( 'store' => $store2 ) ), array( 'species' => $picked['name'], 'product_id' => 0, 'qty' => 1, 'order_ref' => $picked['ref'] ) );
check( '… with the ref: the group says "2026-9"', '2026-9' === $store2->stay( 2, $today, $settings )['order_ref'] && 'Golden Sample Tang' === $store2->stay( 2, $today, $settings )['species'] );
$lt = Front_Desk::perform( 'arrival', array_merge( $fd, array( 'store' => $store2 ) ), array( 'species' => 'Clown Sample Goby <1in', 'product_id' => 0, 'qty' => 1 ) );
check_same( 'a name with a "<" in it ("Clown Sample Goby <1in") is stored as it is spelled — on an Arrival and on an Identify — so it is the same fish as its order line, not a second one', array( 'Clown Sample Goby <1in', 'Clown Sample Goby <1in', 'O\'Sample & Sons Wrasse', 'Sample Tang' ), array( $store2->stay( 3, $today, $settings )['species'], Identify::fish_from( $store2, array( 'species' => 'Clown  Sample Goby <1in' ), $settings )['name'], Identify::fish_from( $store2, array( 'species' => 'O\'Sample & Sons Wrasse' ), $settings )['name'], Identify::fish_from( $store2, array( 'species' => 'Sample <b>Tang</b>' ), $settings )['name'] ) );
$r = Admin::add_existing( $store, array( 'tank' => 522, 'species' => $picked['name'], 'product_id' => (int) $picked['product_id'], 'qty' => 1, 'arrived' => 'unknown', 'cleared' => 'no' ), 7, $today, $settings );
check( '"Add fish already here" with it (wp-admin): the same fish again', empty( $r['error'] ) && 'Golden Sample Tang' === $store->stay( 2, $today, $settings )['species'] && null === $store->stay( 2, $today, $settings )['product_id'] && 2 === $store->open_stay_counts()['name']['golden sample tang'] );

// A typed arrival of a fish that is on no order: alive in a tank, so it is found too.
Front_Desk::perform( 'arrival', $fd, array( 'species' => 'Hand Sample Puffer', 'qty' => 1 ) );
$puffer = $store->fish_search( 'puffer' );
check( 'an unlisted fish that is alive in a tank is found, with its tank', array( 'Hand Sample Puffer' ) === array_column( $puffer, 'name' ) && 'in tank 521' === $puffer[0]['where'] && 'tank' === $puffer[0]['match'] );

// The order is closed: its line is no longer offered — the fish alive in the tank still is.
$store->update_order_fields( $order, array( 'status' => 'closed' ) );
$tang = $store->fish_search( 'golden' );
check( 'the order is closed: the fish is still found while it is alive in a tank — "in tank …" now', array( 'Golden Sample Tang' ) === array_column( $tang, 'name' ) && 'tank' === $tang[0]['match'] && 0 === strpos( $tang[0]['where'], 'in tank 52' ) );
foreach ( array( 1, 2 ) as $id ) {
	$alive = $store->stay( $id, $today, $settings );
	$store->record_event( $id, array( 'type' => 'loss', 'qty' => (int) $alive['qty_live'], 'event_at' => gmdate( 'Y-m-d H:i:s' ) ), $today, $settings );
}
check( '… and once none is alive, a closed order\'s fish is offered no more', array() === $store->fish_search( 'golden' ) );
$store->update_order_fields( $order, array( 'status' => 'received' ) );
check( '… the order open again: found again, as the order line\'s', 'on fish order 2026-9' === $store->fish_search( 'golden' )[0]['where'] );

// It gets a Species row, or a product: it is found as that, and offered this way no more.
$store->save_species( Species::normalize( array( 'name' => 'golden sample tang', 'sci_name' => 'Samplus aureus' ) ) );
$listed = $store->fish_search( 'golden' );
check( 'a Species row of that name (any case): found as the Species row — once', 1 === count( $listed ) && 'species' === $listed[0]['match'] && ! isset( $listed[0]['where'] ) && $listed[0]['species_id'] > 0 );
$shop = new Fake_Store( array( 14 => 'Hand Sample Puffer' ) );
$shop->create_stay( array( 'species' => 'hand sample puffer', 'tank_id' => 1 ) );
$shop->record_event( 1, array( 'type' => 'arrival', 'qty' => 1, 'to_tank_id' => 1, 'event_at' => gmdate( 'Y-m-d H:i:s' ) ), $today, $settings );
check( 'a product of that name: found as the product — once', array( 'title' ) === array_column( $shop->fish_search( 'puffer' ), 'match' ) );

/* ------------------------------------------------------------------ */
echo "5. The endpoints, and the pages\n";

$front  = (string) file_get_contents( Clean_Room::path() . 'class-front-desk.php' );
$book   = (string) file_get_contents( Clean_Room::path() . 'class-admin-workbook.php' );
$phone  = (string) file_get_contents( Clean_Room::path() . 'js/front-desk.js' );
$admin  = (string) file_get_contents( Clean_Room::path() . 'js/clean-room-admin.js' );
$source = (string) file_get_contents( Clean_Room::path() . 'class-store.php' );
check( 'the phone\'s search (Arrival, Add fish already here, Identify) asks fish_search() — behind the same guard as before', 1 === preg_match( '/function ajax_suggest\(\) \{\s*self::guard\(\);.*?\$store->fish_search\( \$term, 8 \)/s', $front ) && false === strpos( $front, 'species_suggestions(' ) );
check( 'wp-admin\'s search boxes (the order line, Identify, Add fish already here) ask it too; the product link still looks at products only', false !== strpos( $book, "'products' === \$op ? \$store->species_suggestions( \$term, self::SUGGEST_LIMIT ) : \$store->fish_search( \$term, self::SUGGEST_LIMIT )" ) );
check( 'the phone draws where the fish comes from, and a pick goes through one rule', false !== strpos( $phone, 'var sub = suggestSub(item);' ) && false !== strpos( $phone, 'var picked = pickedFish(item);' ) );
check( 'wp-admin draws it as the item\'s tag, and a pick on an order line goes through one rule', false !== strpos( $admin, '.text(suggestTag(item))' ) && false !== strpos( $admin, 'var pick = linePick(item, $sci.val());' ) );
$requests = dirname( Clean_Room::path() ) . '/requests/class-cleanroom.php';
check( 'the Requests section reads species_suggestions() only: a fish of a fish order is never named to a customer by this', ! is_readable( $requests ) || ( false === strpos( (string) file_get_contents( $requests ), 'fish_search' ) && false === strpos( (string) file_get_contents( $requests ), 'order_fish' ) ) );
check( 'the voice parser\'s and the importer\'s candidates are as they were: products, Species rows and learned names', 0 === preg_match( '/function species_candidates\(\) \{.*?Order_Fish.*?\n\t\}\n/s', $source ) );

/* ------------------------------------------------------------------ */
echo "6. The cache on the real Store: read once, kept, dropped by each write that changes the answer\n";

/** A database that has the tables, answers from canned rows and records what it is asked. */
$GLOBALS['wpdb'] = new class() {
	public $prefix    = 'wp_';
	public $users     = 'wp_users';
	public $posts     = 'wp_posts';
	public $postmeta  = 'wp_postmeta';
	public $terms     = 'wp_terms';
	public $term_taxonomy      = 'wp_term_taxonomy';
	public $term_relationships = 'wp_term_relationships';
	public $insert_id = 0;
	public $queries   = array();
	public $fail      = false;
	public $stay      = null;
	public $events    = array();
	public function prepare( $query, ...$args ) {
		$args = isset( $args[0] ) && is_array( $args[0] ) ? $args[0] : $args;
		return vsprintf( str_replace( array( '%d', '%f' ), '%s', $query ), $args );
	}
	public function esc_like( $text ) {
		return addcslashes( (string) $text, '_%\\' );
	}
	public function get_var( $query ) {
		return 1 === preg_match( '/^SHOW TABLES LIKE (\S+)$/', (string) $query, $m ) ? $m[1] : 0;
	}
	public function get_col( $query ) {
		return false !== strpos( (string) $query, 'wp_terms' ) ? array( 9 ) : array();
	}
	public function get_row( $query, $output = null ) {
		$this->queries[] = (string) $query;
		return false !== strpos( (string) $query, 'wp_fh_stays' ) ? $this->stay : null;
	}
	public function get_results( $query, $output = null ) {
		$query           = (string) $query;
		$this->queries[] = $query;
		if ( $this->fail ) {
			throw new \RuntimeException( 'the database is away' );
		}
		if ( 0 === strpos( $query, 'SELECT l.id, l.species' ) ) {
			return array(
				array( 'id' => 5, 'species' => 'Golden Sample Tang', 'sci_name' => 'Samplus aureus', 'needs_id' => 0, 'species_id' => null, 'order_ref' => '2026-9', 'order_status' => 'ordered' ),
				array( 'id' => 6, 'species' => 'Blue Test Wrasse', 'sci_name' => '', 'needs_id' => 0, 'species_id' => null, 'order_ref' => '2026-9', 'order_status' => 'ordered' ),
				array( 'id' => 7, 'species' => 'Green Test Blenny', 'sci_name' => '', 'needs_id' => 0, 'species_id' => null, 'order_ref' => '2026-9', 'order_status' => 'ordered' ),
			);
		}
		if ( 0 === strpos( $query, 'SELECT s.id, s.species' ) ) {
			return array( array( 'id' => 3, 'species' => 'Hand Sample Puffer', 'needs_id' => 0, 'product_id' => null, 'qty_live' => 1, 'status' => 'in_qt', 'tank_number' => 512, 'sci_name' => null ) );
		}
		if ( false !== strpos( $query, 'FROM wp_posts p' ) ) {
			return false !== strpos( $query, ' LIKE ' ) ? array() : array( array( 'ID' => 12, 'post_title' => 'Blue Test Wrasse', 'common' => '', 'sci' => '' ) );
		}
		if ( false !== strpos( $query, 'FROM wp_fh_species' ) ) {
			return false !== strpos( $query, ' LIKE ' ) ? array() : array( array( 'id' => 1, 'name' => 'Green Test Blenny', 'sci_name' => '', 'group_name' => '', 'per_box' => 24, 'my_price' => null, 'product_id' => null ) );
		}
		if ( false !== strpos( $query, 'wp_fh_stay_events' ) ) {
			return $this->events;
		}
		return array();
	}
	public function insert( $table, $data, $format = null ) {
		$this->insert_id++;
		return 1;
	}
	public function update( $table, $data, $where, $format = null, $where_format = null ) {
		return 1;
	}
	public function delete( $table, $where, $where_format = null ) {
		return 1;
	}
};
defined( 'ARRAY_A' ) || define( 'ARRAY_A', 'ARRAY_A' ); // As WordPress has it: the real Store's reads name it.
$db   = $GLOBALS['wpdb'];
$flag = new ReflectionProperty( Store::class, 'tables_exist' ); // Asked before, without a database: ask again.
$flag->setAccessible( true );
$flag->setValue( null, null );
$real = new Store();
$kept = static function () {
	return false !== get_transient( Store::ORDER_FISH_TRANSIENT );
};
$fill = static function () use ( $real ) {
	set_transient( Store::SPECIES_TRANSIENT, array(), 600 );
	return $real->order_fish();
};

$first = $real->order_fish();
check_same( 'read from the lines of the open orders and from the groups alive in tanks; a line named like a product or like a Species row is not offered', array( array( 'Golden Sample Tang', 'order', '2026-9' ), array( 'Hand Sample Puffer', 'tank', 512 ) ), array( array( $first[0]['name'], $first[0]['from'], $first[0]['ref'] ), array( $first[1]['name'], $first[1]['from'], $first[1]['tank'] ) ) );
$reads = array_values( array_filter( $db->queries, static function ( $q ) { return 0 === strpos( $q, 'SELECT l.id, l.species' ) || 0 === strpos( $q, 'SELECT s.id, s.species' ); } ) );
check( 'two reads, bounded however long the history is: closed orders and closed or empty groups are out in the query itself, linked and "??" rows too (a group by its own product or by its order line\'s Species row), and each read has its row cap', 2 === count( $reads ) && false !== strpos( $reads[0], "o.status <> 'closed' AND l.species_id IS NULL AND l.needs_id = 0 AND l.species <> '' AND st.product_id IS NULL" ) && false !== strpos( $reads[0], 'LIMIT ' . Order_Fish::MAX_LINES ) && false !== strpos( $reads[1], "s.status <> 'closed' AND s.qty_live > 0 AND s.product_id IS NULL AND s.needs_id = 0 AND s.species <> '' AND ol.species_id IS NULL" ) && false !== strpos( $reads[1], 'LIMIT ' . Order_Fish::MAX_STAYS ) );
$asked = count( $db->queries );
check( 'kept: the next search asks the database nothing for it (10 minutes at most)', $first === $real->order_fish() && $asked === count( $db->queries ) && 600 === $GLOBALS['fh_transients'][ Store::ORDER_FISH_TRANSIENT ][1] );
$found = $real->fish_search( 'sample', 8 );
$again = array_filter( $db->queries, static function ( $q ) { return 0 === strpos( $q, 'SELECT l.id, l.species' ) || 0 === strpos( $q, 'SELECT s.id, s.species' ); } );
check( 'the real search puts them under what the lists know, straight from the kept list (the two reads were not made again)', array( 'Golden Sample Tang', 'Hand Sample Puffer' ) === array_column( $found, 'name' ) && array( 'on fish order 2026-9', 'in tank 512' ) === array_column( $found, 'where' ) && 2 === count( $again ) );
check( '… and species_suggestions() itself — the Requests section\'s read — does not hold them', array() === $real->species_suggestions( 'sample', 8 ) );

$drops = array(
	'a fish order saved (new)'                             => static function () use ( $real ) { $real->save_order( array( 'ref' => '2026-10' ) ); },
	'a fish order saved (its header, its status)'          => static function () use ( $real ) { $real->save_order( array( 'id' => 4, 'ref' => '2026-9', 'status' => 'closed' ) ); },
	'a fish order\'s lines saved — typed, or imported'     => static function () use ( $real ) { $real->save_order_lines( 4, array( array( 'species' => 'Zebra Sample Goby', 'qty_ordered' => 2 ) ) ); },
	'a fish order deleted'                                 => static function () use ( $real ) { $real->delete_order( 4 ); },
	'a check-in or a close that changes the order\'s status' => static function () use ( $real ) { $real->update_order_fields( 4, array( 'status' => 'received', 'received_date' => '2026-03-02' ) ); },
	'a line linked to the group it became (the check-in)'  => static function () use ( $real ) { $real->set_line_stay( 5, 3, 1 ); },
	'a correction of a line\'s fish'                       => static function () use ( $real ) { $real->update_line_fields( 5, array( 'species' => 'Golden Sample Tang', 'species_id' => 3 ) ); },
	'a line\'s "??" flag'                                  => static function () use ( $real ) { $real->update_line_fields( 5, array( 'needs_id' => 1 ) ); },
	'a group identified or corrected'                      => static function () use ( $real ) { $real->update_stay_fields( 3, array( 'species' => 'Hand Sample Puffer', 'product_id' => 12 ) ); },
	'a group\'s "??" flag'                                 => static function () use ( $real ) { $real->update_stay_fields( 3, array( 'needs_id' => 0 ) ); },
	'a product, a Species row or a learned name saved'     => static function () { Store::clear_species_cache(); },
);
foreach ( $drops as $what => $write ) {
	$fill();
	$was = $kept();
	$write();
	check( "dropped by: $what", $was && ! $kept() );
}
$keeps = array(
	'an order\'s sale date or notes'       => static function () use ( $real ) { $real->update_order_fields( 4, array( 'sale_date' => '2026-04-01', 'notes' => 'x' ) ); },
	'a line\'s cached landed total'        => static function () use ( $real ) { $real->cache_line_total( 5, 12.5 ); },
	'a line\'s notes, label or size'       => static function () use ( $real ) { $real->update_line_fields( 5, array( 'notes' => 'x', 'label_name' => 'y', 'size_slug' => 'small' ) ); },
	'a group\'s supplier, notes or size'   => static function () use ( $real ) { $real->update_stay_fields( 3, array( 'supplier' => 'Example Aquatics', 'notes' => 'x', 'size_slug' => 'small' ) ); },
);
foreach ( $keeps as $what => $write ) {
	$fill();
	$write();
	check( "kept through: $what", $kept() );
}
$fill();
Store::clear_order_fish_cache();
check( 'an order or a group write drops ONLY this list — the product list the voice parser reads stays', ! $kept() && false !== get_transient( Store::SPECIES_TRANSIENT ) );
$fill();
Store::clear_species_cache();
check( '… a product, Species row or learned-name save drops both', ! $kept() && false === get_transient( Store::SPECIES_TRANSIENT ) );

// A group's events replayed: only a change of the answer drops the list.
$row    = static function ( array $more ) {
	return array_merge( array( 'id' => 3, 'product_id' => null, 'species' => 'Hand Sample Puffer', 'needs_id' => 0, 'qty_arrived' => 0, 'qty_live' => 0, 'checkin_date' => '2026-03-01', 'status' => 'in_qt', 'tank_id' => 1, 'tank_number' => 512, 'source' => 'manual' ), $more );
};
$event  = static function ( $id, $type, $qty, array $more = array() ) {
	return array_merge( array( 'id' => $id, 'stay_id' => 3, 'type' => $type, 'qty' => $qty, 'from_tank_id' => null, 'to_tank_id' => 'arrival' === $type ? 1 : null, 'note' => '', 'event_at' => '2026-03-01 10:0' . $id . ':00', 'actor_id' => 1, 'source' => 'manual', 'created_at' => '2026-03-01 10:00:00' ), $more );
};
$replay = static function ( array $stay, array $events ) use ( $db, $real, $fill, $kept, $settings ) {
	$db->stay   = $stay;
	$db->events = $events;
	$fill();
	$state = $real->replay( 3, '2026-03-02', $settings );
	return array( (int) $state['qty_live'], $kept() );
};
check_same( 'a check-in — an unlisted fish comes alive in a tank: dropped', array( 3, false ), $replay( $row( array() ), array( $event( 1, 'arrival', 3 ) ) ) );
check_same( 'a loss that leaves fish alive (5 → 4): kept — the answer is the same', array( 4, true ), $replay( $row( array( 'qty_live' => 5 ) ), array( $event( 1, 'arrival', 5 ), $event( 2, 'loss', 1 ) ) ) );
check_same( 'the last one lost, or sold: dropped', array( 0, false ), $replay( $row( array( 'qty_live' => 1 ) ), array( $event( 1, 'arrival', 1 ), $event( 2, 'sold', 1 ) ) ) );
check_same( 'moved to another tank ("in tank …" changes): dropped', array( 2, false ), $replay( $row( array( 'qty_live' => 2 ) ), array( $event( 1, 'arrival', 2 ), $event( 2, 'move', null, array( 'from_tank_id' => 1, 'to_tank_id' => 2 ) ) ) ) );
check_same( 'a group with a product, or a "??" group, is never in the list: its check-in keeps it', array( array( 3, true ), array( 3, true ) ), array( $replay( $row( array( 'product_id' => 12 ) ), array( $event( 1, 'arrival', 3 ) ) ), $replay( $row( array( 'needs_id' => 1, 'species' => '?? small wrasse' ) ), array( $event( 1, 'arrival', 3 ) ) ) ) );
check_same( 'the two rules, as rules', array( true, false, true, true, false, false, true, true, true, false ), array( Order_Fish::stay_changes( array( 'qty_live' => 0, 'tank_id' => 1 ), array( 'qty_live' => 2, 'tank_id' => 1 ) ), Order_Fish::stay_changes( array( 'qty_live' => 5, 'tank_id' => 1 ), array( 'qty_live' => 4, 'tank_id' => 1 ) ), Order_Fish::stay_changes( array( 'qty_live' => 1, 'tank_id' => 1 ), array( 'qty_live' => 0, 'tank_id' => 1 ) ), Order_Fish::stay_changes( array( 'qty_live' => 2, 'tank_id' => 1 ), array( 'qty_live' => 2, 'tank_id' => 2 ) ), Order_Fish::stay_changes( array( 'qty_live' => 0, 'tank_id' => 1 ), array( 'qty_live' => 0, 'tank_id' => 2 ) ), Order_Fish::stay_changes( array( 'product_id' => 12, 'qty_live' => 0 ), array( 'qty_live' => 2 ) ), Order_Fish::stay_fields_change( array( 'species' => 'x' ) ), Order_Fish::stay_fields_change( array( 'product_id' => null ) ), Order_Fish::stay_fields_change( array( 'needs_id' => 0 ) ), Order_Fish::stay_fields_change( array( 'notes' => 'x', 'supplier' => 'y', 'size_slug' => 'small', 'order_line_id' => 4 ) ) ) );

// A read that fails is not kept: the next search asks again.
Store::clear_order_fish_cache();
$db->fail = true;
check( 'a read that fails gives nothing and is NOT kept', array() === $real->order_fish() && ! $kept() );
$db->fail = false;
check( '… so the next search reads again', 2 === count( $real->order_fish() ) && $kept() );
unset( $GLOBALS['wpdb'] );

fh_finish();
