<?php
/**
 * Reserved → Pull today → Shipped, the pure rules (Reserve): which tank
 * group a sold fish is reserved in (the ladder, free fish, the group that
 * ships first, a line spread over two tanks, a guess never reserved),
 * the counts per group, whether a line has left, the idempotent
 * difference that takes it out of its tank (and puts it back), the gate
 * that keeps an order gone before the switch-on out of it, the pull
 * list and the words.
 *
 * Invented fish, invented ids. No WordPress, no WooCommerce.
 *
 * Run from the plugin root:  php includes/sections/clean-room/tests/reserve-test.php
 */

require_once __DIR__ . '/wp-stubs.php';

use FisHotel\Misc\Sections\Clean_Room\Reserve;
use FisHotel\Misc\Sections\Clean_Room\Store;

function rstay( $id, $species, array $more = array() ) {
	return array_merge(
		array(
			'id'              => $id,
			'product_id'      => null,
			'species'         => $species,
			'size_slug'       => null,
			'size_label'      => null,
			'qty_live'        => 3,
			'tank_id'         => 10 + $id,
			'tank_number'     => 120 + $id,
			'building_id'     => 1,
			'area'            => 'clean',
			'own_water'       => false,
			'sale_ready'      => true,
			'sale_ready_date' => '2026-03-01',
			'checkin_date'    => '2026-02-01',
		),
		$more
	);
}

function rline( $ref, $name, $qty, array $more = array() ) {
	return array_merge(
		array(
			'ref'          => $ref,
			'item_id'      => Reserve::item_of( $ref ),
			'name'         => $name,
			'qty'          => $qty,
			'product_id'   => 0,
			'variation_id' => 0,
			'size_slug'    => '',
			'size_label'   => '',
			'names'        => array(),
			'link'         => array(),
			'loose'        => false,
			'held'         => array(),
		),
		$more
	);
}

/* ------------------------------------------------------------------ */
echo "1. Keys, notes, the stored reservation\n";

check_same( 'a line of its own: _fh_stay; a fish of an invoice kept as one line: _fh_stay_<n>; no order item: none', array( '_fh_stay', '_fh_stay_2', null, null ), array( Reserve::key( Reserve::META, 'i:77' ), Reserve::key( Reserve::META, 'i:77:2' ), Reserve::key( Reserve::META, 'inv:5:1' ), Reserve::key( Reserve::META, 'i:0' ) ) );
check_same( 'the shipped note names the order and the item', array( 'order:1234 item:77', 'order:1234 item:77:2', '' ), array( Reserve::note( 1234, 'i:77' ), Reserve::note( 1234, 'i:77:2' ), Reserve::note( 1234, 'x' ) ) );
check_same( '… and is read back', array( array( 'order_id' => 1234, 'ref' => 'i:77:2' ), null, null ), array( Reserve::parse_note( 'order:1234 item:77:2' ), Reserve::parse_note( 'undo of #5' ), Reserve::parse_note( 'order:12 item:3 extra' ) ) );
check_same( 'a reservation is read from JSON or an array, zeros and junk dropped, its order kept', array( 9 => 2, 4 => 1 ), Reserve::held( '{"9":2,"4":1,"0":3,"7":0,"x":1}' ) );
check_same( '… stored as a JSON object', '{"9":2,"4":1}', Reserve::encode( array( 9 => 2, 4 => 1 ) ) );
check_same( 'a refund takes fish off the end of it', array( 9 => 2 ), Reserve::trim( array( 9 => 2, 4 => 1 ), 2 ) );
check_same( '… a line refunded to nothing holds nothing', array(), Reserve::trim( array( 9 => 2 ), 0 ) );
check( 'only this feature\'s item meta drops the caches', Reserve::is_meta_key( '_fh_stay_seen' ) && Reserve::is_meta_key( '_fh_stay_3' ) && ! Reserve::is_meta_key( '_fishotel_line_status' ) );

/* ------------------------------------------------------------------ */
echo "2. Which group: the ladder, in clean water, never a guess\n";

$tang_m   = rstay( 1, 'Test Tang', array( 'product_id' => 501, 'size_slug' => 'medium', 'size_label' => 'Medium' ) );
$tang_m2  = rstay( 2, 'Test Tang', array( 'product_id' => 501, 'size_slug' => 'medium', 'size_label' => 'Medium', 'sale_ready_date' => '2026-03-10', 'tank_number' => 221, 'building_id' => 2 ) );
$tang_qt  = rstay( 3, 'Test Tang', array( 'product_id' => 501, 'size_slug' => 'medium', 'size_label' => 'Medium', 'area' => 'spa', 'tank_number' => 521, 'building_id' => 5, 'sale_ready' => false ) );
$angel    = rstay( 4, 'Sample Angel', array( 'size_slug' => 'small', 'size_label' => 'Small' ) );
$wrasse   = rstay( 5, 'Demo Wrasse', array( 'product_id' => 777 ) );
$clean    = array( $tang_m, $tang_m2, $angel, $wrasse );
$all      = array( $tang_m, $tang_m2, $tang_qt, $angel, $wrasse );

$tang_line = rline( 'i:11', 'Test Tang - Medium', 2, array( 'product_id' => 501, 'variation_id' => 502, 'size_slug' => 'medium' ) );
$found     = Reserve::candidates( $tang_line, $clean, $all );
check_same( 'the same product AND the same size: every clean group of it', array( 'product', array( 1, 2 ) ), array( $found['rung'], array_column( $found['stays'], 'id' ) ) );

$found = Reserve::candidates( rline( 'i:12', 'Whatever', 1, array( 'link' => array( 'stay_id' => 3 ) ) ), $clean, $all );
check_same( 'the Invoices section\'s link first — even to a group still in quarantine (the fish moves with its group)', array( 'invoice', array( 3 ) ), array( $found['rung'], array_column( $found['stays'], 'id' ) ) );

$found = Reserve::candidates( array_merge( $tang_line, array( 'offered' => array( 2 ) ) ), $clean, $all );
check_same( 'a group the same customer was offered by a request comes before the product', array( 'request', array( 2 ) ), array( $found['rung'], array_column( $found['stays'], 'id' ) ) );

$found = Reserve::candidates( rline( 'i:13', 'Sample Angel', 1, array( 'size_slug' => 'small' ) ), $clean, $all );
check_same( 'the same name word for word AND the size', array( 'name', array( 4 ) ), array( $found['rung'], array_column( $found['stays'], 'id' ) ) );

$found = Reserve::candidates( rline( 'i:14', 'Test Tang', 1, array( 'product_id' => 501 ) ), $clean, $all );
check_same( 'a product with no size on the line: only a guess — no group is reserved by itself', array( 'coarse', array() ), array( $found['rung'], $found['stays'] ) );
$found = Reserve::candidates( rline( 'i:15', 'Demo Wrasse', 1, array( 'product_id' => 777, 'size_slug' => 'large' ) ), $clean, $all );
check_same( '… nor a group whose size nobody wrote down', array( 'coarse', array() ), array( $found['rung'], $found['stays'] ) );
$found = Reserve::candidates( rline( 'i:16', 'Sample Angel', 1 ), $clean, $all );
check_same( '… nor a name without a size', 'coarse', $found['rung'] );
$found = Reserve::candidates( rline( 'i:17', 'Sample Angel - Small', 1 ), $clean, $all );
check_same( 'a name that ends in the group\'s size names the size itself', array( 'name', array( 4 ) ), array( $found['rung'], array_column( $found['stays'], 'id' ) ) );
$found = Reserve::candidates( rline( 'i:18', 'Blue Hippo Tang', 1, array( 'size_slug' => 'small' ) ), $clean, $all );
check_same( 'a fish in no tank: nothing', array( '', array() ), array( $found['rung'], $found['stays'] ) );
$found = Reserve::candidates( $tang_line, array(), $all );
check_same( 'only in quarantine by its product: not reserved (a fish for sale is in a clean tank)', '', $found['rung'] );

/* ------------------------------------------------------------------ */
echo "3. How many: free fish, the group that ships first, a split\n";

check_same( 'the group for sale longest gives first', array( 1 => 2 ), Reserve::assign( 2, array( $tang_m2, $tang_m ), array() ) );
check_same( 'a line of 5 over two tanks: 3 from the first, 2 from the next', array( 1 => 3, 2 => 2 ), Reserve::assign( 5, array( $tang_m2, $tang_m ), array() ) );
check_same( 'fish another order holds are not free', array( 1 => 1, 2 => 1 ), Reserve::assign( 2, array( $tang_m, $tang_m2 ), array( 1 => array( 'qty' => 2, 'orders' => array( '1200' ) ) ) ) );
check_same( 'not enough free fish: what there is', array( 1 => 3 ), Reserve::assign( 4, array( $tang_m ), array() ) );
check_same( 'the order: for sale first, then the earliest sale date, then the earliest check-in, then the oldest group', array( 1, 2, 5 ), array_column( ( static function () {
	$list = array(
		rstay( 5, 'x', array( 'sale_ready' => false, 'sale_ready_date' => null ) ),
		rstay( 2, 'x', array( 'sale_ready_date' => '2026-03-10' ) ),
		rstay( 1, 'x', array( 'sale_ready_date' => '2026-03-01' ) ),
	);
	usort( $list, array( Reserve::class, 'ships_first' ) );
	return $list;
} )(), 'id' ) );
check_same( 'Store::ships_first() is the product page\'s own order (unknown check-in last)', -1, Store::ships_first( array( 'sale_ready' => false, 'sale_ready_date' => null, 'checkin_date' => '2026-01-01' ), array( 'sale_ready' => false, 'sale_ready_date' => null, 'checkin_date' => '' ) ) );

/* ------------------------------------------------------------------ */
echo "4. Every open order at once: nothing held moves, the oldest order first\n";

$orders = array(
	array(
		'id'     => 1200,
		'number' => '1200',
		'lines'  => array( array_merge( $tang_line, array( 'ref' => 'i:21', 'item_id' => 21, 'qty' => 2, 'held' => array( 2 => 2 ) ) ) ),
	),
	array(
		'id'     => 1234,
		'number' => '1234',
		'lines'  => array(
			array_merge( $tang_line, array( 'ref' => 'i:31', 'item_id' => 31, 'qty' => 4 ) ),
			rline( 'i:32', 'Blue Hippo Tang', 1, array( 'size_slug' => 'small' ) ),
			rline( 'i:33', 'Test Snail', 2, array( 'loose' => true ) ),
			rline( 'x:1', 'A fish with no order item', 1, array( 'size_slug' => 'small', 'name' => 'Sample Angel' ) ),
		),
	),
);
$plan = Reserve::plan( $orders, $clean, $all );
check_same( 'a held line keeps its group, even when another ships first; the new one is added', array( 2 => array( 'qty' => 3, 'orders' => array( '1200', '1234' ) ), 1 => array( 'qty' => 3, 'orders' => array( '1234' ) ) ), $plan['counts'] );
check_same( '… the new line takes the free fish: 3 of tank 121, then the 1 left in 222', array( 1234 => array( 'i:31' => array( 1 => 3, 2 => 1 ) ) ), $plan['adds'] );
check_same( 'a fish in no tank is "tank not known"; an invert is not, a line that is no order item is never reserved', array( 1234 => array( 'i:32' => 1 ) ), $plan['unknown'] );
$again = $orders;
$again[1]['lines'][0]['held'] = Reserve::merge( array(), $plan['adds'][1234]['i:31'] );
$plan2 = Reserve::plan( $again, $clean, $all );
check_same( 'asked again once stored: nothing more to add', array(), $plan2['adds'] );
$partial                    = $orders;
$partial[1]['lines'][0]['qty'] = 1;
$partial[1]['lines'][0]['held'] = array( 1 => 3 );
check_same( 'a line refunded down holds only what it still asks', array( 'qty' => 1, 'orders' => array( '1234' ) ), Reserve::plan( $partial, $clean, $all )['counts'][1] );

/* ------------------------------------------------------------------ */
echo "5. Has it left?\n";

check( 'the order is shipped or completed', Reserve::left( array( 'status' => 'wc-shipped' ), 5 ) && Reserve::left( array( 'status' => 'completed' ), 5 ) );
check( 'a ShipTracker shipment names its item', Reserve::left( array( 'status' => 'processing', 'items' => array( 5 => true ) ), 5 ) );
check( '… another item only: not this one', ! Reserve::left( array( 'status' => 'processing', 'items' => array( 6 => true ) ), 5 ) );
check( 'a shipment with no item list: the whole order', Reserve::left( array( 'status' => 'processing', 'whole' => true ), 5 ) );
check( 'a local pickup, or in another order\'s box', Reserve::left( array( 'status' => 'processing', 'mode' => 'local' ), 5 ) && Reserve::left( array( 'status' => 'on-hold', 'mode' => 'with_order' ), 5 ) );
check( 'a label of its own still to come: not left', ! Reserve::left( array( 'status' => 'processing', 'mode' => 'label' ), 5 ) );
check( 'the combined box it is in went out', Reserve::left( array( 'status' => 'processing', 'box' => array( 'status' => 'shipped' ) ), 5 ) );
check( '… the box still open: not left', ! Reserve::left( array( 'status' => 'processing', 'box' => array( 'status' => 'fulfillment' ) ), 5 ) );

/* ------------------------------------------------------------------ */
echo "6. Shipped: the difference, any number of times\n";

$note   = Reserve::note( 1234, 'i:31' );
$events = array(
	array( 'id' => 40, 'stay_id' => 1, 'type' => 'sold', 'qty' => 3, 'note' => $note ),
	array( 'id' => 41, 'stay_id' => 2, 'type' => 'sold', 'qty' => 1, 'note' => $note ),
	array( 'id' => 42, 'stay_id' => 1, 'type' => 'sold', 'qty' => 1, 'note' => 'order:1234 item:32' ),
	array( 'id' => 43, 'stay_id' => 1, 'type' => 'loss', 'qty' => 1, 'note' => '' ),
);
$done = Reserve::written( $events, $note );
check_same( 'what this line\'s note took out, per group — another line\'s note and a loss are not it', array( 1 => 3, 2 => 1 ), $done['net'] );

$held = array( 1 => 3, 2 => 1 );
check_same( 'left, nothing written yet: all of it', array( 'writes' => array( 1 => 3, 2 => 1 ), 'short' => 0 ), Reserve::diff( Reserve::wanted( true, 'shipped', $held, 4, array() ), array(), array( 1 => 3, 2 => 3 ) ) );
check_same( 'left, written already: nothing (saved twice changes nothing)', array( 'writes' => array(), 'short' => 0 ), Reserve::diff( Reserve::wanted( true, 'shipped', $held, 4, $done['net'] ), $done['net'], array( 1 => 0, 2 => 2 ) ) );
check_same( 'tracking removed, the order still processing: put back', array( 'writes' => array( 1 => -3, 2 => -1 ), 'short' => 0 ), Reserve::diff( Reserve::wanted( false, 'processing', $held, 4, $done['net'] ), $done['net'], array() ) );
check_same( 'a refund after it left (dead on arrival): nothing changes', array( 'writes' => array(), 'short' => 0 ), Reserve::diff( Reserve::wanted( true, 'shipped', $held, 1, $done['net'] ), $done['net'], array() ) );
check_same( '… nor when the refund turned the order "refunded"', array( 1 => 3, 2 => 1 ), Reserve::wanted( false, 'refunded', $held, 0, $done['net'] ) );
check_same( 'cancelled before it left: nothing was written, nothing is', array(), Reserve::wanted( false, 'cancelled', $held, 4, array() ) );
check_same( 'the owner picked another tank after it shipped: the old one gets them back, the new one gives them', array( 'writes' => array( 5 => 3, 1 => -3, 2 => -1 ), 'short' => 0 ), Reserve::diff( Reserve::wanted( true, 'shipped', array( 5 => 3 ), 4, $done['net'] ), $done['net'], array( 5 => 3 ) ) );
check_same( 'fewer fish alive than it should give: not written at all — short, for the not-found list', array( 'writes' => array( 2 => 1 ), 'short' => 3 ), Reserve::diff( Reserve::wanted( true, 'shipped', $held, 4, array() ), array(), array( 1 => 2, 2 => 1 ) ) );

$undo   = array_merge( $events, array( array( 'id' => 44, 'stay_id' => 1, 'type' => 'sold', 'qty' => -3, 'note' => 'undo of #40' ) ) );
$undone = Reserve::written( $undo, $note );
check_same( 'an undo by hand counts in the net, and that group is left alone from then on', array( array( 1 => 0, 2 => 1 ), array( 1 => true ) ), array( $undone['net'], $undone['undone'] ) );
check_same( '… so the next pass does not take them out again', array( 'writes' => array(), 'short' => 0 ), Reserve::diff( Reserve::wanted( true, 'shipped', $held, 4, $undone['net'] ), $undone['net'], array( 1 => 3, 2 => 3 ), $undone['undone'] ) );

check( 'the gate: a line seen open after the switch-on, or reserved', Reserve::gated( array( 'seen' => 1700000000 ) ) && Reserve::gated( array( 'held' => array( 1 => 1 ) ) ) );
check( '… an order that had left before the switch-on was never seen open: never taken out', ! Reserve::gated( array( 'seen' => 0, 'held' => array() ) ) );

/* ------------------------------------------------------------------ */
echo "7. Pull today\n";

$stays = array(
	1 => array_merge( $tang_m, array( 'building_label' => 'Clean Room 1' ) ),
	2 => array_merge( $tang_m2, array( 'building_label' => 'Clean Room 2' ) ),
	4 => array_merge( $angel, array( 'building_label' => 'Clean Room 1' ) ),
);
$today = array(
	array_merge( rline( 'i:31', 'Test Tang - Medium', 3 ), array( 'order_id' => 1234, 'number' => '1234', 'held' => array( 1 => 2, 2 => 1 ) ) ),
	array_merge( rline( 'i:41', 'Sample Angel', 1 ), array( 'order_id' => 1240, 'number' => '1240', 'held' => array( 4 => 1 ) ) ),
	array_merge( rline( 'i:51', 'Blue Hippo Tang', 1 ), array( 'order_id' => 1251, 'number' => '1251' ) ),
	array_merge( rline( 'i:52', 'Test Snail', 2, array( 'loose' => true ) ), array( 'order_id' => 1251, 'number' => '1251' ) ),
);
$pull = Reserve::pull( $today, $stays, array( 'order:1240 item:41' => array( 4 => 1 ) ) );
check_same( 'by building, then tank', array( array( 1, array( 121, 124 ) ), array( 2, array( 221 ) ) ), array_map( static function ( $b ) { return array( $b['id'], array_column( $b['tanks'], 'number' ) ); }, $pull['buildings'] ) );
check_same( 'one tank\'s line: its fish and the order', 'Tank 121 — 2 × Test Tang Medium (#1234)', Reserve::tank_text( $pull['buildings'][0]['tanks'][0] ) );
check_same( 'a line spread over two tanks is under each with its own fish', 'Tank 221 — 1 × Test Tang Medium (#1234)', Reserve::tank_text( $pull['buildings'][1]['tanks'][0] ) );
check( 'a line already taken out of its tank is ticked', ! empty( $pull['buildings'][0]['tanks'][1]['rows'][0]['shipped'] ) && empty( $pull['buildings'][0]['tanks'][0]['rows'][0]['shipped'] ) );
check_same( 'a fish with no tank: "Tank not known"', 'Tank not known — 1 × Blue Hippo Tang (#1251)', Reserve::unknown_text( $pull['unknown'] ) );
check_same( 'an invert in no tank is named apart, quietly', array( 'Test Snail' ), array_column( $pull['loose'], 'name' ) );
check_same( 'the count: every fish, and the ones still to pull', array( 7, 6 ), array( $pull['fish'], $pull['left'] ) );

/* ------------------------------------------------------------------ */
echo "8. The words\n";

check_same( 'the tank card', array( '2 reserved · #1234', '3 reserved · #1234, #1240', '' ), array( Reserve::reserved_text( 2, array( '1234' ) ), Reserve::reserved_text( 3, array( '1234', '1240' ) ), Reserve::reserved_text( 0, array() ) ) );
check_same( 'the hand "Shipped" on a group that holds fish for an order', '2 of these are reserved for #1234 and will come off by themselves when it ships.', Reserve::sold_note( 2, array( '1234' ) ) );
check_same( 'History says "Shipped" and the order', array( 'Shipped · order #1234 (2 fish)', 'Shipped · order #1234 — 2 fish put back', '' ), array( Reserve::event_text( array( 'type' => 'sold', 'qty' => 2, 'note' => 'order:1234 item:31' ) ), Reserve::event_text( array( 'type' => 'sold', 'qty' => -2, 'note' => 'order:1234 item:31' ) ), Reserve::event_text( array( 'type' => 'sold', 'qty' => 2, 'note' => '' ) ) ) );
check_same( 'the stock warning — only when the shop and the tanks differ', array( 'Shop says 5 in stock, tanks have 3 free', '', '' ), array( Reserve::stock_text( 5, 3 ), Reserve::stock_text( 3, 3 ), Reserve::stock_text( null, 3 ) ) );
check_same( 'the not-found line', 'Could not find a tank for 4 fish that shipped', Reserve::lost_text( 4 ) );
check_contains( 'the tick in Settings says what it does', 'reserved in the tank', Reserve::setting_text()['text'] );

/* ------------------------------------------------------------------ */
echo "9. The shop's stock against the free fish (a warning only)\n";

$stock = array(
	array( 'product_id' => 501, 'size_slug' => 'medium', 'label' => 'Test Tang · Medium', 'stock' => 5 ),
	array( 'product_id' => 601, 'size_slug' => '', 'label' => 'Sample Angel', 'stock' => 1 ),
	array( 'product_id' => 777, 'size_slug' => '', 'label' => 'Demo Wrasse', 'stock' => null ),
);
$groups = array(
	rstay( 1, 'Test Tang', array( 'product_id' => 501, 'size_slug' => 'medium', 'qty_live' => 3 ) ),
	rstay( 2, 'Test Tang', array( 'product_id' => 501, 'size_slug' => 'medium', 'qty_live' => 2 ) ),
	rstay( 3, 'Test Tang', array( 'product_id' => 501, 'size_slug' => 'large', 'qty_live' => 4 ) ),
	rstay( 4, 'Sample Angel', array( 'product_id' => 601, 'qty_live' => 1 ) ),
	rstay( 5, 'Demo Wrasse', array( 'product_id' => 777, 'qty_live' => 2 ) ),
);
$warn = Reserve::stock_warnings( $stock, $groups, array( 1 => array( 'qty' => 2, 'orders' => array( '1234' ) ) ) );
check_same( 'the medium tangs: 5 in the shop, 3 + 2 alive less 2 reserved = 3 free — said; the angel agrees; an untracked product is never compared', array( array( 501, 3, 'Shop says 5 in stock, tanks have 3 free' ) ), array_map( static function ( $w ) { return array( $w['product_id'], $w['free'], $w['text'] ); }, $warn ) );

/* ------------------------------------------------------------------ */
echo "10. The pull list's words\n";

$words = Reserve::pull_words( $pull );
check_same( 'each row, the count, the unknown line with its hint, the inverts', array( '2 × Test Tang Medium (#1234)', '6 of 7 fish still to pull', 'Tank not known — 1 × Blue Hippo Tang (#1251)', 'Not in a tank on the site: 2 × Test Snail (#1251)' ), array( $words['buildings'][0]['tanks'][0]['rows'][0]['text'], $words['count_text'], $words['unknown_text'], $words['loose_text'] ) );
check_contains( '… the hint is the feeding rule\'s: correct a group already there before adding one', 'do not add it again', $words['hint'] );
check_same( 'alternatives: the other clean groups of the fish, the one that ships first first', array( 2 ), array_column( Reserve::alternatives( $tang_line, $clean, array( 1 ) ), 'id' ) );

fh_finish();
