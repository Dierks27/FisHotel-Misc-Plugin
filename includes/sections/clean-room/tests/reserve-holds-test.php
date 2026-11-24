<?php
/**
 * Reserved BY HAND, the whole way through the shop (Reserve_Shop + the
 * phone): "Reserve…" on a tank sets ready fish aside for somebody, they
 * show as reserved wherever reserved counts show and are not offered to
 * anybody else, Release ends it, a customer's own paid order takes their
 * hold (never another customer's), a hold for a typed name is only
 * suggested to an order and given in one tap, the hold follows its group
 * when it moves, and the tick off hides it all (on again shows it).
 *
 * Runs on the stand-in shop (fake-shop-orders.php) and the in-memory
 * store: invented orders, fish, customers and ids.
 *
 * Run from the plugin root:  php includes/sections/clean-room/tests/reserve-holds-test.php
 */

require_once __DIR__ . '/wp-stubs.php';
require_once __DIR__ . '/fake-shop-orders.php';

use FisHotel\Misc\Sections\Clean_Room\Front_Desk;
use FisHotel\Misc\Sections\Clean_Room\Holds;
use FisHotel\Misc\Sections\Clean_Room\Replay;
use FisHotel\Misc\Sections\Clean_Room\Reserve;
use FisHotel\Misc\Sections\Clean_Room\Reserve_Shop;
use FisHotel\Misc\Sections\Clean_Room\Settings;
use FisHotel\Misc\Sections\Clean_Room\Ship_Lines;
use FisHotel\Misc\Sections\Clean_Room\Tests\Fake_Store;

$GLOBALS['fh_now'] = '2026-04-01 09:00:00';
$today             = (string) current_time( 'Y-m-d' );
$tomorrow          = Replay::add_days( $today, 1 );
$settings          = Settings::get();
$protocols         = Settings::protocols();

add_filter( 'fishotel_cleanroom_batch_present', 'fh_holds_true' );
function fh_holds_true() {
	return true;
}

// The shop's customers (invented): an account is read by its id, never from what was posted.
$GLOBALS['fh_users'] = array(
	42 => (object) array( 'ID' => 42, 'user_email' => 'ann.sample@example.test', 'display_name' => 'annb' ),
	43 => (object) array( 'ID' => 43, 'user_email' => 'otto.demo@example.test', 'display_name' => 'ottod' ),
);
$GLOBALS['fh_user_meta'][42] = array( 'billing_first_name' => 'Ann', 'billing_last_name' => 'Bsample', 'billing_email' => 'ann.sample@example.test' );
$GLOBALS['fh_user_meta'][43] = array( 'billing_first_name' => 'Otto', 'billing_last_name' => 'Demo', 'billing_email' => 'otto.demo@example.test' );
function get_userdata( $id ) {
	return $GLOBALS['fh_users'][ (int) $id ] ?? false;
}

function place( Fake_Store $store, $tank_number, $species, $qty, array $more = array() ) {
	global $today, $settings;
	$tank = $store->tank_by_number( $tank_number );
	$date = Replay::add_days( $today, -40 );
	$id   = $store->create_stay( array_merge( array( 'species' => $species, 'checkin_date' => $date, 'tank_id' => (int) $tank['id'] ), $more ) );
	$store->record_event( $id, array( 'type' => 'arrival', 'qty' => $qty, 'to_tank_id' => (int) $tank['id'], 'event_at' => Front_Desk::event_at_for_date( $date, $today ) ), $today, $settings );
	$store->record_event( $id, array( 'type' => 'note', 'note' => Replay::NOTE_SALE_READY . Replay::add_days( $today, -10 ), 'event_at' => gmdate( 'Y-m-d H:i:s' ) ), $today, $settings );
	return $id;
}

function alive( Fake_Store $store, $stay_id ) {
	global $today, $settings;
	return (int) $store->stay( $stay_id, $today, $settings )['qty_live'];
}

function held( $item ) {
	return Reserve::held( $item->get_meta( Reserve::META ) );
}

function fresh() {
	Reserve_Shop::reset();
	Reserve_Shop::flush();
	Ship_Lines::flush();
}

function counts( Fake_Store $store ) {
	global $settings;
	fresh();
	return Reserve_Shop::ensure( $store, $settings )['counts'];
}

function occupant( Fake_Store $store, $tank, $stay_id ) {
	global $settings, $today;
	fresh();
	$screen = Front_Desk::screen_from( $store, $tank, $settings, $today );
	foreach ( $screen['tank']['occupants'] as $o ) {
		if ( (int) $o['stayId'] === (int) $stay_id ) {
			return $o;
		}
	}
	return null;
}

function ctx( Fake_Store $store, $tank ) {
	global $settings, $today;
	return array( 'store' => $store, 'settings' => $settings, 'today' => $today, 'tank' => $store->tank_by_number( $tank ), 'base' => array( 'actor_id' => 1, 'source' => 'qr' ) );
}

function book() {
	return Holds::book( get_option( Holds::OPTION, array() ) );
}

$tang   = new Fh_Shop_Product( 501, 'Test Tang', array( 'quarantined-fish' ) );
$tang_m = new Fh_Shop_Product( 502, 'Test Tang - Medium', array(), 501, array( 'pa_size' => 'medium' ) );
$medium = array( 'pa_size' => 'medium' );

$store = new Fake_Store();
$s121  = place( $store, 121, 'Test Tang', 5, array( 'product_id' => 501, 'size_slug' => 'medium', 'size_label' => 'Medium' ) );

/* ------------------------------------------------------------------ */
echo "1. Reserve… on the phone: create, and shown\n";

$r = Front_Desk::perform( 'hold', ctx( $store, 121 ), array( 'stay_id' => $s121, 'qty' => 2, 'who' => 'Mike S.', 'note' => 'paying Friday' ) );
check_contains( 'saved, in plain words', 'reserved for Mike S.', (string) ( $r['message'] ?? $r['error'] ?? '' ) );
check_same( 'not an event: still 5 alive, no new event', array( 5, 2 ), array( alive( $store, $s121 ), count( $store->events( $s121 ) ) ) );
$o = occupant( $store, 121, $s121 );
check_same( 'the phone\'s tank screen: "3 ready · 2 reserved (Mike S. — by hand, today)", the hold with its note', array( '3 ready · 2 reserved (Mike S. — by hand, today)', 3, array( '2 × Mike S. — by hand, today' ), array( 'paying Friday' ) ), array( $o['reservedText'], $o['ready'], array_column( $o['holds'], 'text' ), array_column( $o['holds'], 'note' ) ) );
fresh();
$room = $store->room( $today, $settings, $protocols );
check_same( 'the To do list of every hold by hand: tank, fish, who, how many, how long', array( array( 121, 'Test Tang Medium', 'Mike S.', 2, 'today', false ) ), array_map( static function ( $h ) { return array( $h['tank'], $h['fish'], $h['who'], $h['qty'], $h['age'], $h['gone'] ); }, $room['todo']['holds'] ) );

/* ------------------------------------------------------------------ */
echo "2. Larger than ready: refused\n";

$r = Front_Desk::perform( 'hold', ctx( $store, 121 ), array( 'stay_id' => $s121, 'qty' => 4, 'who' => 'Lee' ) );
check_contains( '4 asked, 3 ready: refused, saying how many', 'Only 3 are ready', (string) ( $r['error'] ?? '' ) );
check_same( '… nothing stored', 1, count( Holds::open( book() ) ) );

/* ------------------------------------------------------------------ */
echo "3. Two holds on one tank; held fish are not ready for anybody else\n";

$r = Reserve_Shop::act( $store, $settings, array( 'op' => 'hold', 'stay_id' => $s121, 'qty' => 1, 'user_id' => 42, 'who' => 'ignored when an account is picked' ) );
check_contains( 'an account picked from the search: named from the account', 'reserved for Ann B.', $r );
$c = counts( $store );
check_same( 'the group holds 3 by hand (2 + 1), two holds listed', array( 3, 3, array( 'Mike S.', 'Ann B.' ) ), array( $c[ $s121 ]['qty'], $c[ $s121 ]['hand'], array_column( $c[ $s121 ]['holds'], 'who' ) ) );
check_same( 'the Invoices picker and the Requests job read them as reserved (counts_for): 3, said by name', array( 3, '2 for Mike S. — by hand, today; 1 for Ann B. — by hand, today' ), ( static function () use ( $store, $settings, $s121 ) {
	fresh();
	$all = Reserve_Shop::ensure( $store, $settings )['counts'];
	return array( (int) $all[ $s121 ]['qty'], Reserve::reserved_who( $all[ $s121 ]['orders'], $all[ $s121 ]['holds'] ) );
} )() );
$o = occupant( $store, 121, $s121 );
check_same( 'the phone\'s Move sheet: 2 not reserved', 2, $o['qtyLive'] - $o['reserved'] );

// Otto (another customer) orders 3 of these: only the 2 ready are his; the held fish are never given to him.
$o1300 = fh_shop_order( new WC_Order( 1300, 'processing', array( '_fishotel_shipping_date' => $tomorrow, '_customer_user' => 43, '_billing_email' => 'otto.demo@example.test', '_paid_date' => (string) time() ), time() + 60 ) );
$i1300 = $o1300->add_item( 'Test Tang - Medium', 3, $tang_m, $medium );
$c     = counts( $store );
check_same( 'another customer\'s order takes the 2 ready only — never a held fish; 1 tank not known', array( array( $s121 => 2 ), 5, 3 ), array( held( $i1300 ), $c[ $s121 ]['qty'], $c[ $s121 ]['hand'] ) );
check_same( 'hold + order reservations never exceed alive: 3 + 2 = 5', 5, alive( $store, $s121 ) );
check_same( 'Hand holds are not on Pull today (no order behind them): only #1300\'s 2', array( 'Tank 121 — 2 × Test Tang Medium (#1300)' ), ( static function () use ( $store, $settings, $today, $protocols ) {
	fresh();
	$pull = $store->room( $today, $settings, $protocols )['todo']['pull'];
	$out  = array();
	foreach ( $pull['buildings'] as $b ) {
		foreach ( $b['tanks'] as $t ) {
			$out[] = Reserve::tank_text( $t );
		}
	}
	return $out;
} )() );

/* ------------------------------------------------------------------ */
echo "4. A free-text hold: suggested, one tap gives the held fish\n";

fresh();
$room = $store->room( $today, $settings, $protocols );
$sug  = $room['todo']['suggest'];
check_same( 'Otto\'s order came in after Mike S.\'s hold, same fish and size: asked — never matched by itself', array( array( 1300, 'i:' . $i1300->get_id(), 'Is #1300 the order for the fish held for Mike S.?' ) ), array_map( static function ( $s ) { return array( $s['order_id'], $s['ref'], Holds::question( $s ) ); }, $sug ) );
check_same( '… Ann\'s account hold is never suggested to Otto', array( 'Mike S.' ), array_values( array_unique( array_column( $sug, 'who' ) ) ) );
$mike = (int) $sug[0]['hold_id'];
$msg  = Reserve_Shop::act( $store, $settings, array( 'op' => 'not', 'hold_id' => $mike, 'order_id' => 1300, 'ref' => 'i:' . $i1300->get_id() ) );
fresh();
check_same( '"Not this order": not asked again', array(), $store->room( $today, $settings, $protocols )['todo']['suggest'] );
$book = book();
$book['holds'][ $mike ]['not'] = array();
update_option( Holds::OPTION, $book );
$msg = Reserve_Shop::act( $store, $settings, array( 'op' => 'give', 'hold_id' => $mike, 'order_id' => 1300, 'ref' => 'i:' . $i1300->get_id() ) );
check_contains( '"Give them the held fish": said', 'now go to order #1300', $msg );
$c = counts( $store );
check_same( 'the order holds its 3 in 121 (Mike S.\'s 2 first, then 1 of its own), the hold closed "taken by order #1300"', array( array( $s121 => 3 ), 0, 'taken by order #1300' ), array( held( $i1300 ), (int) book()['holds'][ $mike ]['closed'] > 0 ? 0 : 1, book()['holds'][ $mike ]['why'] ) );
check_same( '… still never more than alive: 3 for #1300 + Ann\'s 1 = 4 of 5', array( 4, 1 ), array( $c[ $s121 ]['qty'], $c[ $s121 ]['hand'] ) );

/* ------------------------------------------------------------------ */
echo "5. An account hold: taken by that customer's own paid order only\n";

$o1400 = fh_shop_order( new WC_Order( 1400, 'on-hold', array( '_fishotel_shipping_date' => $tomorrow, '_customer_user' => 42, '_billing_email' => 'ann.sample@example.test' ), time() + 120 ) );
$i1400 = $o1400->add_item( 'Test Tang - Medium', 1, $tang_m, $medium );
$c     = counts( $store );
check_same( 'Ann\'s order not paid yet: it waits for her held fish — not taken, no other fish given, the hold still open', array( array(), 1, 1 ), array( held( $i1400 ), $c[ $s121 ]['hand'], count( Holds::open( book() ) ) ) );
$o1400->update_meta_data( '_paid_date', (string) time() );
$o1400->set_status( 'processing' );
$c = counts( $store );
$ann = array_values( array_filter( book()['holds'], static function ( $h ) { return 'Ann B.' === $h['who']; } ) )[0];
check_same( 'paid: her order takes her held fish — the hold closed "taken by order #1400", nothing held by hand any more', array( array( $s121 => 1 ), 'taken by order #1400', 0, 4 ), array( held( $i1400 ), $ann['why'], (int) ( $c[ $s121 ]['hand'] ?? 0 ), $c[ $s121 ]['qty'] ) );
check_same( 'a normal order reservation now: on Pull today', true, false !== strpos( implode( ' ', array_map( static function ( $t ) { return Reserve::tank_text( $t ); }, ( static function () use ( $store, $settings, $today, $protocols ) { fresh(); return $store->room( $today, $settings, $protocols )['todo']['pull']['buildings'][0]['tanks']; } )() ) ), '#1400' ) );

$r = Reserve_Shop::act( $store, $settings, array( 'op' => 'hold', 'stay_id' => $s121, 'qty' => 1, 'user_id' => 42 ) );
$o1500 = fh_shop_order( new WC_Order( 1500, 'processing', array( '_fishotel_shipping_date' => $tomorrow, '_customer_user' => 43, '_billing_email' => 'otto.demo@example.test', '_paid_date' => (string) time() ), time() + 180 ) );
$i1500 = $o1500->add_item( 'Test Tang - Medium', 1, $tang_m, $medium );
$c = counts( $store );
check_same( 'a new hold for Ann; Otto\'s paid order of the same fish does not take it (tank not known)', array( array(), 1, 1 ), array( held( $i1500 ), (int) $c[ $s121 ]['hand'], count( Holds::open( book() ) ) ) );

/* ------------------------------------------------------------------ */
echo "6. The hold follows its group when it moves\n";

$s221 = place( $store, 221, 'Test Tang', 3, array( 'product_id' => 501, 'size_slug' => 'medium', 'size_label' => 'Medium' ) );
fresh();
$r = Front_Desk::perform( 'move', ctx( $store, 121 ), array( 'stay_id' => $s121, 'to_tank' => 122, 'qty' => 5 ) );
check( 'the whole group moved to tank 122', empty( $r['error'] ) && 122 === (int) $store->stay( $s121, $today, $settings )['tank_number'] );
$o = occupant( $store, 122, $s121 );
check_same( 'its hold went with it: shown on tank 122', array( '1 × Ann B. — by hand, today' ), array_column( (array) $o['holds'], 'text' ) );
fresh();
$rows = $store->room( $today, $settings, $protocols )['todo']['holds'];
check_same( '… and listed under tank 122', array( 122 ), array_column( $rows, 'tank' ) );

/* ------------------------------------------------------------------ */
echo "7. Fish died: the hold shrinks, never the orders\n";

$store->record_event( $s121, array( 'type' => 'loss', 'qty' => 2, 'event_at' => gmdate( 'Y-m-d H:i:s' ) ), $today, $settings );
$c = counts( $store );
check_same( '3 alive, 4 held for orders before: the orders keep theirs, the hold holds 0 — never more than alive', array( 3, 0 ), array( alive( $store, $s121 ), (int) ( $c[ $s121 ]['hand'] ?? 0 ) ) );
fresh();
$rows = $store->room( $today, $settings, $protocols )['todo']['holds'];
check_same( 'listed with what it asked and what it still holds', array( array( 1, 0 ) ), array_map( static function ( $h ) { return array( $h['asked'], $h['qty'] ); }, $rows ) );

/* ------------------------------------------------------------------ */
echo "8. Release\n";

$id = (int) $rows[0]['id'];
$r  = Front_Desk::perform( 'release', ctx( $store, 122 ), array( 'hold_id' => $id ) );
check_contains( 'Release: said', 'Released', (string) ( $r['message'] ?? '' ) );
check_same( 'no hold open; the list is empty', array( 0, array() ), array( count( Holds::open( book() ) ), ( static function () use ( $store, $settings, $today, $protocols ) { fresh(); return $store->room( $today, $settings, $protocols )['todo']['holds']; } )() ) );
check_contains( 'released twice: said it is gone', 'no longer there', (string) Front_Desk::perform( 'release', ctx( $store, 122 ), array( 'hold_id' => $id ) )['error'] );

/* ------------------------------------------------------------------ */
echo "9. The tick off hides the holds; on again shows them\n";

$r = Reserve_Shop::act( $store, $settings, array( 'op' => 'hold', 'stay_id' => $s221, 'qty' => 2, 'who' => 'Forum fan' ) );
check_same( 'a hold on 221', 2, (int) counts( $store )[ $s221 ]['hand'] );
$off = array_merge( $settings, array( Reserve::SETTING => false ) );
fresh();
check_same( 'off: nothing counted, nothing on the phone, the list empty, Reserve refused', array( array(), 0, array(), 'Reserving sold fish is switched off.' ), array(
	Reserve_Shop::ensure( $store, $off )['counts'],
	( static function () use ( $store, $off, $today, $s221 ) { fresh(); foreach ( Front_Desk::screen_from( $store, 221, $off, $today )['tank']['occupants'] as $o ) { if ( (int) $o['stayId'] === (int) $s221 ) { return (int) $o['reserved']; } } return -1; } )(),
	( static function () use ( $store, $off, $today, $protocols ) { fresh(); return (array) ( $store->room( $today, $off, $protocols )['todo']['holds'] ?? array() ); } )(),
	Reserve_Shop::act( $store, $off, array( 'op' => 'hold', 'stay_id' => $s221, 'qty' => 1, 'who' => 'X' ) ),
) );
check_same( '… the hold is kept, untouched', 1, count( Holds::open( book() ) ) );
check_same( 'on again: shown again', array( 2, 'Forum fan' ), array( (int) counts( $store )[ $s221 ]['hand'], counts( $store )[ $s221 ]['holds'][0]['who'] ) );

fh_finish();
