<?php
/**
 * Reserved BY HAND (Holds, pure): a hold is made only from fish that are
 * ready, never runs out by itself, is released by hand, counts with the
 * orders (orders first — together never more than alive), is taken by its
 * own customer's paid order (never somebody else's), and a hold with a
 * typed name is only ever suggested to an order. Invented names and ids.
 *
 * Run from the plugin root:  php includes/sections/clean-room/tests/holds-test.php
 */

require_once __DIR__ . '/wp-stubs.php';

use FisHotel\Misc\Sections\Clean_Room\Holds;
use FisHotel\Misc\Sections\Clean_Room\Reserve;

$now   = 1800000000;
$ready = array(
	'id'          => 7,
	'status'      => 'cleared',
	'sale_ready'  => true,
	'qty_live'    => 5,
	'reserved'    => 2,
	'species'     => 'Test Tang',
	'product_id'  => 501,
	'size_slug'   => 'medium',
	'size_label'  => 'Medium',
	'tank_number' => 121,
);

/* ------------------------------------------------------------------ */
echo "1. Made only from fish that are ready\n";

$book = Holds::book( null );
check_same( 'an empty book', array( 'next' => 1, 'holds' => array() ), $book );
check_same( '3 of 5 alive with 2 reserved: allowed', '', Holds::refuse( $ready, 3, 'Mike S.', $book ) );
check_contains( 'more than are ready: refused, saying how many are', 'Only 3 are ready', Holds::refuse( $ready, 4, 'Mike S.', $book ) );
check_contains( 'nothing ready: refused', 'every fish is reserved already', Holds::refuse( array_merge( $ready, array( 'reserved' => 5 ) ), 1, 'Mike S.', $book ) );
check_contains( 'a group not for sale yet: nothing ready', 'not for sale yet', Holds::refuse( array_merge( $ready, array( 'sale_ready' => false ) ), 1, 'Mike S.', $book ) );
check_contains( 'nobody named: asked who for', 'Who are they for?', Holds::refuse( $ready, 1, '  ', $book ) );
check_contains( 'a group with no fish', 'no fish alive', Holds::refuse( null, 1, 'Mike S.', $book ) );

$made = Holds::add( $book, array( 'stay_id' => 7, 'qty' => 2, 'who' => 'Mike S.', 'note' => '  paying   Friday ', 'by' => 1 ), $now );
$book = $made['book'];
check_same( 'a hold: its group, fish, who, the note on one line, when — open', array( 1, 7, 2, 'Mike S.', 'paying Friday', $now, 0, false ), array( $made['id'], $book['holds'][1]['stay_id'], $book['holds'][1]['qty'], $book['holds'][1]['who'], $book['holds'][1]['note'], $book['holds'][1]['at'], $book['holds'][1]['closed'], Holds::is_account( $book['holds'][1] ) ) );
$made = Holds::add( $book, array( 'stay_id' => 7, 'qty' => 1, 'who' => 'Ann B.', 'user_id' => 42, 'email' => 'ANN@example.test' ), $now + 60 );
$book = $made['book'];
check_same( 'two holds on one group; the second is an account (e-mail kept in lower case)', array( 2, true, 'ann@example.test' ), array( count( Holds::open( $book ) ), Holds::is_account( $book['holds'][2] ), $book['holds'][2]['email'] ) );
check_same( 'the book reads back the same', $book, Holds::book( $book ) );

/* ------------------------------------------------------------------ */
echo "2. Counted with the orders — the orders first, never more than alive\n";

$eff = Holds::effective( Holds::open( $book ), array( 7 => 5 ), array( 7 => array( 'qty' => 2 ) ) );
check_same( '5 alive, 2 for orders: both holds whole (2 + 1)', array( 2, 1 ), array( $eff[1]['qty'], $eff[2]['qty'] ) );
$eff = Holds::effective( Holds::open( $book ), array( 7 => 3 ), array( 7 => array( 'qty' => 2 ) ) );
check_same( 'two fish died (3 alive, 2 for orders): the older hold keeps 1, the newer none — orders + holds = alive', array( 1, 0, 2, 1 ), array( $eff[1]['qty'], $eff[2]['qty'], $eff[1]['asked'], $eff[2]['asked'] ) );
$eff = Holds::effective( Holds::open( $book ), array(), array() );
check_same( 'its group closed: it holds nothing (and is listed to release)', array( 0, 0 ), array( $eff[1]['qty'], $eff[2]['qty'] ) );

$eff    = Holds::effective( Holds::open( $book ), array( 7 => 5 ), array( 7 => array( 'qty' => 2 ) ) );
$counts = Holds::add_to( array( 7 => array( 'qty' => 2, 'orders' => array( '1234' ) ) ), $eff );
check_same( 'the counts: 5 held in all, 3 of them by hand, the holds listed', array( 5, 3, array( '1234' ), array( 'Mike S.', 'Ann B.' ) ), array( $counts[7]['qty'], $counts[7]['hand'], $counts[7]['orders'], array_column( $counts[7]['holds'], 'who' ) ) );
$stay = array_merge( $ready, array( 'reserved' => $counts[7]['qty'], 'reserved_orders' => $counts[7]['orders'], 'reserved_holds' => $counts[7]['holds'] ) );
check_same( 'ready is what nothing holds: 0 of 5', 0, Reserve::ready( $stay ) );
check_same( 'the tank card with nothing ready: "all 5 reserved (…)", never "0 ready"', 'all 5 reserved (#1234; 2 for Mike S. — by hand, today; 1 for Ann B. — by hand, today)', Reserve::stage_text( $stay, $now + 60 ) );
check_same( '… and its badge says RESERVED (every fish for sale reserved), not READY', array( true, false, false ), array( Reserve::all_reserved( $stay ), Reserve::all_reserved( array_merge( $stay, array( 'reserved' => 4 ) ) ), Reserve::all_reserved( array_merge( $stay, array( 'sale_ready' => false ) ) ) ) );
check_same( 'one hold alone, three days on: "5 ready · 2 reserved (Mike S. — by hand, 3 days ago)"', '5 ready · 2 reserved (Mike S. — by hand, 3 days ago)', Reserve::stage_text( array( 'qty_live' => 7, 'sale_ready' => true, 'reserved' => 2, 'reserved_holds' => array( array( 'who' => 'Mike S.', 'qty' => 2, 'at' => $now ) ) ), $now + 3 * DAY_IN_SECONDS + 5 ) );
check_same( 'how long: today, 1 day ago, 20 days ago — and 14 days or more stands out', array( 'today', '1 day ago', '20 days ago', false, true ), array( Reserve::age_text( $now, $now + 100 ), Reserve::age_text( $now, $now + DAY_IN_SECONDS ), Reserve::age_text( $now, $now + 20 * DAY_IN_SECONDS ), Holds::is_old( array( 'at' => $now ), $now + 13 * DAY_IN_SECONDS ), Holds::is_old( array( 'at' => $now ), $now + 14 * DAY_IN_SECONDS ) ) );

check_same( 'the phone\'s hand "Shipped" on a group with fish held by hand: they never come off by themselves', '2 of these are held by hand for Mike S. — tick below if these are theirs, and the hold goes down by them.', Reserve::hand_note( array( array( 'who' => 'Mike S.', 'qty' => 2 ) ) ) );

/* ------------------------------------------------------------------ */
echo "3. Never runs out by itself; released by hand\n";

check_same( 'a year on it is still open', 2, count( Holds::open( Holds::book( $book ) ) ) );
$released = Holds::release( $book, 1, $now + 99 );
check_same( 'Release closes it ("released"); the other stays', array( $now + 99, 'released', array( 2 ) ), array( $released['holds'][1]['closed'], $released['holds'][1]['why'], array_column( Holds::open( $released ), 'id' ) ) );
check_same( 'releasing it again changes nothing', $released, Holds::release( $released, 1, $now + 500 ) );

/* ------------------------------------------------------------------ */
echo "4. Taken by its own customer's order — never somebody else's\n";

$line = array( 'ref' => 'i:91', 'qty' => 1, 'name' => 'Test Tang - Medium', 'product_id' => 501, 'variation_id' => 502, 'size_slug' => 'medium', 'size_label' => 'Medium', 'names' => array( 'Test Tang' ) );
$by   = array( 7 => $ready );
check_same( 'Ann\'s own account: her hold', array( 2 ), array_column( Holds::for_line( $line, array( 'user_id' => 42 ), $eff, $by ), 'id' ) );
check_same( '… or her e-mail on a guest checkout', array( 2 ), array_column( Holds::for_line( $line, array( 'user_id' => 0, 'email' => 'ann@example.test' ), $eff, $by ), 'id' ) );
check_same( 'another customer: nothing (and never the typed name)', array(), Holds::for_line( $line, array( 'user_id' => 43, 'email' => 'otto@example.test' ), $eff, $by ) );
check_same( 'Ann, but a Large: nothing', array(), Holds::for_line( array_merge( $line, array( 'name' => 'Test Tang - Large', 'variation_id' => 503, 'size_slug' => 'large', 'size_label' => 'Large' ) ), array( 'user_id' => 42 ), $eff, $by ) );
$taken = Holds::take( $book, 2, 1, '1240', $now + 5 );
check_same( 'taken whole: closed, "taken by order #1240"', array( 0, 'taken by order #1240', array( array( 'order' => '1240', 'qty' => 1, 'at' => $now + 5 ) ) ), array( $taken['holds'][2]['qty'], $taken['holds'][2]['why'], $taken['holds'][2]['taken'] ) );
$part = Holds::take( $book, 1, 1, '1250', $now + 5 );
check_same( 'taken in part: it keeps the rest, open', array( 1, 0 ), array( $part['holds'][1]['qty'], $part['holds'][1]['closed'] ) );
$moved = Holds::move_in_counts( $counts, 7, 2, 1, '1240' );
check_same( 'in the counts the fish stay held — now for the order', array( 5, 2, array( '1234', '1240' ), array( 'Mike S.' ) ), array( $moved[7]['qty'], $moved[7]['hand'], $moved[7]['orders'], array_column( $moved[7]['holds'], 'who' ) ) );

$orders = array(
	array(
		'id'       => 1240,
		'number'   => '1240',
		'paid'     => true,
		'customer' => array( 'user_id' => 42 ),
		'lines'    => array( array_merge( $line, array( 'item_id' => 91, 'held' => array() ) ) ),
	),
);
$plan = Reserve::plan( $orders, array( $ready ), array( $ready ), $counts, $eff );
check_same( 'Reserve::plan(): the top rung — Ann\'s paid order takes her held fish in that group; taken whole, the hold is no longer held (it closes)', array( array( 1240 => array( 'i:91' => array( 7 => 1 ) ) ), array( 2 ), false ), array( $plan['adds'], array_keys( $plan['takes'] ), isset( $plan['holds'][2] ) ) );
check_same( '… the group still counts 5 held (the fish only changed hands)', 5, $plan['counts'][7]['qty'] );
$plan = Reserve::plan( array( array_merge( $orders[0], array( 'paid' => false ) ) ), array( $ready ), array( $ready ), $counts, $eff );
check_same( 'not paid yet: it waits for her held fish — no fish taken, none other given, not "tank not known"', array( array(), array(), array() ), array( $plan['adds'], $plan['takes'], $plan['unknown'] ) );
$plan = Reserve::plan( array( array_merge( $orders[0], array( 'customer' => array( 'user_id' => 43 ) ) ) ), array( $ready ), array( $ready ), $counts, $eff );
check_same( 'another customer\'s order of the same fish: the held fish are not free — tank not known', array( array(), array(), array( 1240 => array( 'i:91' => 1 ) ) ), array( $plan['adds'], $plan['takes'], $plan['unknown'] ) );

/* ------------------------------------------------------------------ */
echo "5. A typed name: suggested, never matched by itself\n";

$open_orders = array(
	array( 'id' => 1250, 'number' => '1250', 'created' => $now + 600, 'lines' => array( array_merge( $line, array( 'item_id' => 95, 'ref' => 'i:95' ) ) ) ),
	array( 'id' => 1200, 'number' => '1200', 'created' => $now - 600, 'lines' => array( array_merge( $line, array( 'item_id' => 96, 'ref' => 'i:96' ) ) ) ),
	array( 'id' => 1260, 'number' => '1260', 'created' => $now + 900, 'lines' => array( array_merge( $line, array( 'item_id' => 97, 'ref' => 'i:97', 'name' => 'Test Tang - Large', 'variation_id' => 503, 'size_slug' => 'large', 'size_label' => 'Large' ) ) ) ),
);
$sug = Holds::suggestions( $open_orders, $eff, $by );
check_same( 'Mike S.\'s hold: the order of that fish and size that came in after it — not one before, not another size, never for Ann\'s account hold', array( array( 1, 1250, 'i:95', 1 ) ), array_map( static function ( $s ) { return array( $s['hold_id'], $s['order_id'], $s['ref'], $s['qty'] ); }, $sug ) );
check_same( 'the question', 'Is #1250 the order for the fish held for Mike S.?', Holds::question( $sug[0] ) );
$no = Holds::dismiss( $book, 1, '1250|i:95' );
check_same( '"Not this order": not asked again', array(), Holds::suggestions( $open_orders, Holds::effective( Holds::open( $no ), array( 7 => 5 ), array( 7 => array( 'qty' => 2 ) ) ), $by ) );
check_same( 'giving the held fish: the hold\'s group first, the line keeps the rest of what it held', array( 9 => 1, 7 => 2 ), Holds::give_to_line( array( 9 => 3 ), 3, 7, 2 ) );

/* ------------------------------------------------------------------ */
echo "6. A cap that never drops a hold\n";

$full = Holds::book( null );
for ( $i = 0; $i < Holds::MAX_OPEN; $i++ ) {
	$full = Holds::add( $full, array( 'stay_id' => 7, 'qty' => 1, 'who' => 'Buyer ' . $i ), $now + $i )['book'];
}
check_contains( 'the 301st open hold is refused, with what to do', 'release the ones no longer wanted', Holds::refuse( $ready, 1, 'One more', $full ) );
check_same( '… and none was dropped', Holds::MAX_OPEN, count( Holds::open( $full ) ) );
for ( $i = 1; $i <= 150; $i++ ) {
	$full = Holds::release( $full, $i, $now + 1000 + $i );
}
check_same( 'only closed holds are trimmed: the newest 100 closed kept, every open one kept', array( 100, Holds::MAX_OPEN - 150 ), array( count( $full['holds'] ) - count( Holds::open( $full ) ), count( Holds::open( $full ) ) ) );
check_same( 'a short name from an account: "Mike S."', array( 'Mike S.', 'Ann', 'forum_fishfan' ), array( Holds::short_name( 'Mike', 'smith' ), Holds::short_name( 'Ann', '' ), Holds::short_name( '', '', 'forum_fishfan' ) ) );

/* ------------------------------------------------------------------ */
echo "7. What two reviews found\n";

// A paid order takes its customer's held fish first, though an older unpaid order of hers waits.
$ann5   = Holds::effective( array( array( 'id' => 5, 'stay_id' => 7, 'qty' => 2, 'who' => 'Ann B.', 'user_id' => 42, 'email' => '', 'at' => $now, 'not' => array() ) ), array( 7 => 2 ), array() );
$two    = array(
	array( 'id' => 1300, 'number' => '1300', 'paid' => false, 'customer' => array( 'user_id' => 42 ), 'lines' => array( array_merge( $line, array( 'item_id' => 81, 'ref' => 'i:81', 'qty' => 2, 'held' => array() ) ) ) ),
	array( 'id' => 1301, 'number' => '1301', 'paid' => true, 'customer' => array( 'user_id' => 42 ), 'lines' => array( array_merge( $line, array( 'item_id' => 82, 'ref' => 'i:82', 'qty' => 2, 'held' => array() ) ) ) ),
);
$g2     = array_merge( $ready, array( 'qty_live' => 2, 'reserved' => 2 ) );
$plan   = Reserve::plan( $two, array( $g2 ), array( $g2 ), Holds::add_to( array(), $ann5 ), $ann5 );
check_same( 'Reserve::plan(): the paid #1301 takes the 2 held for Ann though her older unpaid #1300 comes first; #1300 gets none', array( array( 1301 => array( 'i:82' => array( 7 => 2 ) ) ), array( 1301 ), array( 1300 => array( 'i:81' => 2 ) ) ), array( $plan['adds'], array_column( $plan['takes'][5], 'order_id' ), $plan['unknown'] ) );
check_same( '… taken whole: no longer among the holds (it closes) — never listed with 0 left', false, isset( $plan['holds'][5] ) );
$ann5b = Holds::effective( array( array( 'id' => 5, 'stay_id' => 7, 'qty' => 5, 'who' => 'Ann B.', 'user_id' => 42, 'email' => '', 'at' => $now, 'not' => array() ) ), array( 7 => 5 ), array() );
$plan  = Reserve::plan( array( $two[1] ), array( $ready ), array( $ready ), Holds::add_to( array(), $ann5b ), $ann5b );
check_same( 'taken in part: it holds 3 and still asks 3 (never "only 3 still in the tank")', array( 3, 3 ), array( $plan['holds'][5]['qty'], $plan['holds'][5]['asked'] ) );

// "Give them the held fish": the held fish in the held size only, and an account's hold only to its own customer.
$typed = array( 'id' => 1, 'who' => 'Mike S.', 'user_id' => 0, 'email' => '' );
$goby  = array( 'ref' => 'i:99', 'qty' => 1, 'name' => 'Mock Goby', 'product_id' => 601, 'variation_id' => 0, 'size_slug' => 'small', 'size_label' => 'Small', 'names' => array( 'Mock Goby' ) );
check_same( 'Holds::give_refuse(): a tang hold to a goby line — refused; to a Large of the same tang — refused; to its fish — given', array( true, true, '' ), array( '' !== Holds::give_refuse( $typed, $goby, $ready, array() ), '' !== Holds::give_refuse( $typed, array_merge( $line, array( 'name' => 'Test Tang - Large', 'variation_id' => 503, 'size_slug' => 'large', 'size_label' => 'Large' ) ), $ready, array() ), Holds::give_refuse( $typed, $line, $ready, array( 'user_id' => 43 ) ) ) );
$acct = array( 'id' => 2, 'who' => 'Ann B.', 'user_id' => 42, 'email' => 'ann@example.test' );
check_same( '… an account\'s hold to another customer — refused; to her own order — given; its group gone — refused', array( true, '', true ), array( '' !== Holds::give_refuse( $acct, $line, $ready, array( 'user_id' => 43, 'email' => 'otto@example.test' ) ), Holds::give_refuse( $acct, $line, $ready, array( 'user_id' => 42 ) ), '' !== Holds::give_refuse( $acct, $line, null, array( 'user_id' => 42 ) ) ) );

// A part of a group never moves off with fish held by hand.
$mike3 = array( array( 'who' => 'Mike S.', 'qty' => 3 ) );
check_contains( 'Holds::move_refuse(): 4 of 5 with 3 held — at most 2', 'move at most 2', Holds::move_refuse( 5, 3, $mike3, 4 ) );
check_same( '… 2 may; the whole group may (its hold goes with it); no hold — nothing refused', array( '', '', '' ), array( Holds::move_refuse( 5, 3, $mike3, 2 ), Holds::move_refuse( 5, 3, $mike3, 5 ), Holds::move_refuse( 5, 3, array(), 4 ) ) );
check_contains( '… everything reserved: none may move off in part', 'none is free to move', Holds::move_refuse( 5, 5, $mike3, 1 ) );

// Shipped by hand, ticked: the hold goes down by them; a hold ended stays said for a week.
$shipped = Holds::ship( $book, 1, 1, $now + 50 );
check_same( 'Holds::ship(): down by 1, still open', array( 1, 0 ), array( $shipped['holds'][1]['qty'], $shipped['holds'][1]['closed'] ) );
$shipped = Holds::ship( $shipped, 1, 5, $now + 60 );
check_same( '… the last ones: closed "shipped by hand", never below 0', array( 0, 'shipped by hand' ), array( $shipped['holds'][1]['qty'], $shipped['holds'][1]['why'] ) );
$ended = Holds::release( Holds::take( $book, 2, 1, '1240', $now + 10 ), 1, $now + 20 );
check_same( 'Holds::closed_lately(): the newest first, with why', array( array( 1, 'released' ), array( 2, 'taken by order #1240' ) ), array_map( static function ( $h ) { return array( $h['id'], Holds::why_text( $h ) ); }, Holds::closed_lately( $ended, $now + 30 ) ) );
check_same( '… a week on, no longer', array(), Holds::closed_lately( $ended, $now + 8 * DAY_IN_SECONDS ) );
check_same( 'one full stop, never two ("Maria T.")', array( 'For Maria T.', 'For Mike', '' ), array( Holds::sentence( 'For Maria T.' ), substr( Holds::sentence( 'For Mike' ), 0, -1 ), Holds::sentence( '' ) ) );

fh_finish();
