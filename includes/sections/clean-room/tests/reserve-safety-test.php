<?php
/**
 * Reserved → Pull today → Shipped: the cases where a fish could come out
 * of the wrong tank, or out of a tank twice — each found by a review and
 * kept here so it never comes back. Switching this on never takes a fish
 * out for an order that had already left; shipping one order never gives
 * its fish to another; a request's group is only used in the line's own
 * size; two requests never write at once; a cancelled order never takes a
 * fish out; a recount after the pull is not taken off again. And the
 * words: Pull today's count, its own hint, one row per fish whose tank is
 * not known, "Back in the tank".
 *
 * Runs on a stand-in shop (fake-shop-orders.php) and the in-memory store:
 * invented orders, fish and ids. No WordPress, no WooCommerce, no database.
 *
 * Run from the plugin root:  php includes/sections/clean-room/tests/reserve-safety-test.php
 */

require_once __DIR__ . '/wp-stubs.php';
require_once __DIR__ . '/fake-shop-orders.php';

use FisHotel\Misc\Sections\Clean_Room\Front_Desk;
use FisHotel\Misc\Sections\Clean_Room\Replay;
use FisHotel\Misc\Sections\Clean_Room\Reserve;
use FisHotel\Misc\Sections\Clean_Room\Reserve_Shop;
use FisHotel\Misc\Sections\Clean_Room\Settings;
use FisHotel\Misc\Sections\Clean_Room\Ship_Lines;
use FisHotel\Misc\Sections\Clean_Room\Tests\Fake_Store;

/**
 * ShipTracker's shipments, as a stand-in: $GLOBALS['fh_shipments'][ order id ] = rows.
 */
class FST_Shipment {
	public static function get_by_order( $order_id ) {
		return $GLOBALS['fh_shipments'][ (int) $order_id ] ?? array();
	}
}
$GLOBALS['fh_shipments'] = array();

function ship( $order_id, $items = null ) {
	$GLOBALS['fh_shipments'][ (int) $order_id ][] = (object) array( 'line_item_ids' => null === $items ? null : json_encode( $items ) );
}

// The Requests section's store, as a stand-in: the requests a customer was offered.
eval( 'namespace FisHotel\Misc\Sections\Requests; class Store { public static function ready() { return true; } public function find( $f ) { return $GLOBALS["fh_requests"] ?? array(); } }' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- a two-method stand-in in another namespace.
$GLOBALS['fh_requests'] = array();

/**
 * An order with a billing e-mail (the request step asks for it).
 */
class Fh_Mail_Order extends WC_Order {
	public function get_billing_email() {
		return 'customer@example.test';
	}
}

$GLOBALS['fh_now'] = '2026-04-01 09:00:00';
$today             = (string) current_time( 'Y-m-d' );
$tomorrow          = Replay::add_days( $today, 1 );
$yesterday         = Replay::add_days( $today, -1 );
$settings          = Settings::get();
$protocols         = Settings::protocols();

add_filter( 'fishotel_cleanroom_batch_present', '__return_true_for_safety' );
function __return_true_for_safety() {
	return true;
}

function place( Fake_Store $store, $tank_number, $species, $qty, array $more = array() ) {
	global $today, $settings;
	$tank = $store->tank_by_number( $tank_number );
	$date = Replay::add_days( $today, -40 );
	$id   = $store->create_stay( array_merge( array( 'species' => $species, 'checkin_date' => $date, 'tank_id' => (int) $tank['id'] ), $more ) );
	$store->record_event( $id, array( 'type' => 'arrival', 'qty' => $qty, 'to_tank_id' => (int) $tank['id'], 'event_at' => Front_Desk::event_at_for_date( $date, $today ) ), $today, $settings );
	$store->record_event( $id, array( 'type' => 'note', 'note' => Replay::NOTE_SALE_READY . Replay::add_days( $today, -10 ), 'event_at' => Front_Desk::event_at_for_date( Replay::add_days( $today, -10 ), $today ) ), $today, $settings );
	return $id;
}

function alive( Fake_Store $store, $stay_id ) {
	global $today, $settings;
	return (int) $store->stay( $stay_id, $today, $settings )['qty_live'];
}

function sold( Fake_Store $store, $stay_id ) {
	$out = array();
	foreach ( $store->events( $stay_id ) as $event ) {
		if ( 'sold' === $event['type'] ) {
			$out[] = array( (int) $event['qty'], $event['note'] );
		}
	}
	return $out;
}

function held( $item ) {
	return Reserve::held( $item->get_meta( Reserve::META ) );
}

function fresh() {
	Reserve_Shop::reset();
	Reserve_Shop::flush();
	Ship_Lines::flush();
}

function work( Fake_Store $store, $order_id, $was_open = false ) {
	global $settings;
	fresh();
	return Reserve_Shop::process( $store, $settings, $order_id, $was_open );
}

function read_room( Fake_Store $store ) {
	global $today, $settings, $protocols;
	fresh();
	return $store->room( $today, $settings, $protocols );
}

$store = new Fake_Store();

/* ------------------------------------------------------------------ */
echo "1. Install safety: an order that had left before the switch-on is never taken out\n";

$tang   = new Fh_Shop_Product( 501, 'Test Tang', array( 'quarantined-fish' ) );
$tang_m = new Fh_Shop_Product( 502, 'Test Tang - Medium', array(), 501, array( 'pa_size' => 'medium' ) );
$pellet = new Fh_Shop_Product( 900, 'Test Pellets', array( 'dry-goods' ) );
$medium = array( 'pa_size' => 'medium' );
$s122   = place( $store, 122, 'Test Tang', 4, array( 'product_id' => 501, 'size_slug' => 'medium', 'size_label' => 'Medium' ) );

// Open at the switch-on, but its fish box went weeks before (a partial shipment); the pellets are still here.
$o3000 = fh_shop_order( new WC_Order( 3000, 'on-hold', array( '_fishotel_shipping_date' => $tomorrow ), time() - 86400 * 20 ) );
$i3000 = $o3000->add_item( 'Test Tang - Medium', 2, $tang_m, $medium );
$o3000->add_item( 'Test Pellets', 1, $pellet );
ship( 3000, array( $i3000->get_id() ) );
// Shipped weeks before the switch-on, then moved back to processing by hand afterwards.
$o3200 = fh_shop_order( new WC_Order( 3200, 'shipped', array(), time() - 86400 * 30 ) );
$i3200 = $o3200->add_item( 'Test Tang - Medium', 1, $tang_m, $medium );
ship( 3200, array( $i3200->get_id() ) );
// Still "processing" at the switch-on, but its delivery date was yesterday: it went out and was never marked.
$o3300 = fh_shop_order( new WC_Order( 3300, 'processing', array( '_fishotel_shipping_date' => $yesterday ), time() - 86400 * 3 ) );
$i3300 = $o3300->add_item( 'Test Tang - Medium', 1, $tang_m, $medium );

read_room( $store );
$since = Reserve_Shop::since();
check( 'switched on now', $since > 0 );
check_same( 'the first read reserves none of them, and marks none seen', array( array(), array(), array(), '', '', '' ), array( held( $i3000 ), held( $i3200 ), held( $i3300 ), (string) $i3000->get_meta( Reserve::META_SEEN ), (string) $i3200->get_meta( Reserve::META_SEEN ), (string) $i3300->get_meta( Reserve::META_SEEN ) ) );
work( $store, 3000 );
check_same( 'an order note on the open order (its box gone before): nothing taken out', array( 4, array() ), array( alive( $store, $s122 ), sold( $store, $s122 ) ) );
$o3000->set_status( 'completed' );
work( $store, 3000, true );
check_same( '… nor when it is completed afterwards', array( 4, array(), array() ), array( alive( $store, $s122 ), sold( $store, $s122 ), held( $i3000 ) ) );
$o3200->set_status( 'processing' );
work( $store, 3200 );
read_room( $store );
Reserve_Shop::sweep( $store, $settings );
check_same( 'shipped before, moved back to processing: still nothing (its shipment is from before)', array( 4, array(), array() ), array( alive( $store, $s122 ), held( $i3200 ), sold( $store, $s122 ) ) );
$o3300->set_status( 'completed' );
work( $store, 3300, true );
check_same( 'open but past its delivery date at the switch-on, tidied to completed: nothing', array( 4, array(), array() ), array( alive( $store, $s122 ), held( $i3300 ), sold( $store, $s122 ) ) );
// A combined box still "fulfillment" at the switch-on, its delivery date yesterday: it went out unmarked. The
// orders inside it have no date of their own — the box's date counts for them (found on the rehearsal site).
$o3400 = fh_shop_order( new WC_Order( 3400, 'fulfillment', array( '_fishotel_shipping_date' => $yesterday, '_fishotel_is_fulfillment' => '1', '_fishotel_fulfillment_sources' => '3401' ), time() - 86400 * 2 ) );
$o3401 = fh_shop_order( new WC_Order( 3401, 'processing', array( '_fishotel_fulfilled_by_order' => '3400' ), time() - 86400 * 4 ) );
$i3401 = $o3401->add_item( 'Test Tang - Medium', 1, $tang_m, $medium );
read_room( $store );
check_same( 'an order inside a combined box past its delivery date at the switch-on: not reserved, not seen', array( array(), '' ), array( held( $i3401 ), (string) $i3401->get_meta( Reserve::META_SEEN ) ) );
$o3400->set_status( 'shipped' );
work( $store, 3400, true );
work( $store, 3401, true );
Reserve_Shop::sweep( $store, $settings );
check_same( '… and nothing is taken out when the box is marked shipped afterwards', array( 4, array(), array() ), array( alive( $store, $s122 ), held( $i3401 ), sold( $store, $s122 ) ) );
check_same( 'none of them is on the not-found list', array(), Reserve_Shop::lost() );

/* ------------------------------------------------------------------ */
echo "2. A fish whose tank was not known ships; its group is logged later: not found, never taken out of the new group\n";

$goby   = new Fh_Shop_Product( 511, 'Test Goby', array( 'quarantined-fish' ) );
$goby_m = new Fh_Shop_Product( 512, 'Test Goby - Medium', array(), 511, array( 'pa_size' => 'medium' ) );
$o3400  = fh_shop_order( new WC_Order( 3400, 'processing', array( '_fishotel_shipping_date' => $tomorrow ), time() + 5 ) );
$i3400  = $o3400->add_item( 'Test Goby - Medium', 1, $goby_m, $medium );
read_room( $store );
check_same( 'seen open, tank not known', array( array(), true ), array( held( $i3400 ), (int) $i3400->get_meta( Reserve::META_SEEN ) > 0 ) );
ship( 3400, array( $i3400->get_id() ) );
work( $store, 3400 );
$s133 = place( $store, 123, 'Test Goby', 3, array( 'product_id' => 511, 'size_slug' => 'medium', 'size_label' => 'Medium' ) );
work( $store, 3400 );
read_room( $store );
Reserve_Shop::sweep( $store, $settings );
check_same( 'the group logged after it left keeps its 3 fish', array( 3, array(), array() ), array( alive( $store, $s133 ), sold( $store, $s133 ), held( $i3400 ) ) );
check_same( 'the line is on the not-found list instead', array( 1, 'unknown' ), array( (int) ( Reserve_Shop::lost()[ '3400|i:' . $i3400->get_id() ]['qty'] ?? 0 ), Reserve_Shop::lost()[ '3400|i:' . $i3400->get_id() ]['why'] ?? '' ) );
Reserve_Shop::act( $store, $settings, array( 'op' => 'pick', 'order_id' => 3400, 'ref' => 'i:' . $i3400->get_id(), 'stay_id' => $s133 ) );
check_same( '"It was tank 123" — the owner says so: then it is taken out', array( 2, false ), array( alive( $store, $s133 ), isset( Reserve_Shop::lost()[ '3400|i:' . $i3400->get_id() ] ) ) );

/* ------------------------------------------------------------------ */
echo "3. An order that came in after the switch-on and left before anyone looked: reserved as it leaves\n";

$o3500 = fh_shop_order( new WC_Order( 3500, 'processing', array(), time() + 10 ) );
$i3500 = $o3500->add_item( 'Test Tang - Medium', 1, $tang_m, $medium );
ship( 3500, array( $i3500->get_id() ) );
work( $store, 3500 );
check_same( 'tracking added before any read: taken out of its tank once', array( 3, array( array( 1, 'order:3500 item:' . $i3500->get_id() ) ) ), array( alive( $store, $s122 ), sold( $store, $s122 ) ) );

/* ------------------------------------------------------------------ */
echo "4. Shipping one order never gives its fish to the other open orders\n";

$blue   = new Fh_Shop_Product( 521, 'Blue Test Tang', array( 'quarantined-fish' ) );
$blue_l = new Fh_Shop_Product( 522, 'Blue Test Tang - Large', array(), 521, array( 'pa_size' => 'large' ) );
$large  = array( 'pa_size' => 'large' );
$s222   = place( $store, 222, 'Blue Test Tang', 4, array( 'product_id' => 521, 'size_slug' => 'large', 'size_label' => 'Large' ) );
$oa     = fh_shop_order( new WC_Order( 3601, 'processing', array( '_fishotel_shipping_date' => $tomorrow ), time() + 20 ) );
$ia     = $oa->add_item( 'Blue Test Tang - Large', 2, $blue_l, $large );
$ob     = fh_shop_order( new WC_Order( 3602, 'processing', array( '_fishotel_shipping_date' => $tomorrow ), time() + 21 ) );
$ib     = $ob->add_item( 'Blue Test Tang - Large', 3, $blue_l, $large );
$oc     = fh_shop_order( new WC_Order( 3603, 'on-hold', array( '_fishotel_shipping_date' => $tomorrow ), time() + 22 ) );
$ic     = $oc->add_item( 'Blue Test Tang - Large', 1, $blue_l, $large );
read_room( $store );
check_same( '4 fish: 2 for the first order, 2 for the second (1 short), none for the third', array( array( $s222 => 2 ), array( $s222 => 2 ), array() ), array( held( $ia ), held( $ib ), held( $ic ) ) );
ship( 3601 );
$oa->set_status( 'shipped' );
fresh();
Reserve_Shop::on_status( 3601, 'processing', 'shipped' );
Reserve_Shop::run_queue( $store, $settings );
read_room( $store );
check_same( 'the first ships: its 2 come out; the others keep what they had', array( 2, array( $s222 => 2 ), array() ), array( alive( $store, $s222 ), held( $ib ), held( $ic ) ) );
check_same( '… and the 2 left are the second order\'s', 2, Reserve_Shop::ensure( $store, $settings )['counts'][ $s222 ]['qty'] );
ship( 3602 ); // Its box went out; the order still reads processing (no hook: the sweep finds it).
read_room( $store );
check_same( 'a box gone with the order still open: its fish are still spoken for until written', array( array(), 2 ), array( held( $ic ), Reserve_Shop::ensure( $store, $settings )['counts'][ $s222 ]['qty'] ) );
Reserve_Shop::sweep( $store, $settings );
check_same( 'the sweep takes them out — the third order gets none', array( 0, array() ), array( alive( $store, $s222 ), held( $ic ) ) );
check_same( 'the second order\'s third fish, never held: not found', 1, (int) ( Reserve_Shop::lost()[ '3602|i:' . $ib->get_id() ]['qty'] ?? 0 ) );

/* ------------------------------------------------------------------ */
echo "5. A request's group is used only in the line's own size\n";

$s223  = place( $store, 211, 'Blue Test Tang', 2, array( 'product_id' => 521, 'size_slug' => 'medium', 'size_label' => 'Medium' ) );
$GLOBALS['fh_requests'] = array( array( 'product_id' => 521, 'stay_id' => $s223, 'state' => 'filled' ) );
$o3700 = fh_shop_order( new Fh_Mail_Order( 3700, 'processing', array(), time() + 30 ) );
$i3700 = $o3700->add_item( 'Blue Test Tang - Large', 1, $blue_l, $large );
read_room( $store );
check_same( 'once offered a Medium on group 211, now buys a Large: not reserved there', array(), held( $i3700 ) );
$o3700->set_status( 'shipped' );
work( $store, 3700, true );
check_same( '… and no Medium comes out of 211', array( 2, array() ), array( alive( $store, $s223 ), sold( $store, $s223 ) ) );
$GLOBALS['fh_requests'] = array();

/* ------------------------------------------------------------------ */
echo "6. Two requests never write at once\n";

$s224  = place( $store, 212, 'Blue Test Tang', 3, array( 'product_id' => 521, 'size_slug' => 'large', 'size_label' => 'Large' ) );
$o3800 = fh_shop_order( new WC_Order( 3800, 'processing', array(), time() + 40 ) );
$i3800 = $o3800->add_item( 'Blue Test Tang - Large', 1, $blue_l, $large );
read_room( $store );
check_same( 'reserved', array( $s224 => 1 ), held( $i3800 ) );
ship( 3800 );
$o3800->set_status( 'shipped' );
update_option( Reserve_Shop::LOCK_OPTION, (string) time() ); // Another request is writing.
check_same( 'while another request holds the lock: nothing is done (the sweep catches up)', array( array(), 3 ), array( work( $store, 3800, true ), alive( $store, $s224 ) ) );
update_option( Reserve_Shop::LOCK_OPTION, (string) ( time() - 1000 ) ); // That request died long ago.
work( $store, 3800, true );
check_same( 'a lock left by a request that died is taken over', 2, alive( $store, $s224 ) );
check_same( '… and given back', false, get_option( Reserve_Shop::LOCK_OPTION, false ) );

/* ------------------------------------------------------------------ */
echo "7. A cancelled order never takes a fish out\n";

$o3900 = fh_shop_order( new WC_Order( 3900, 'processing', array(), time() + 50 ) );
$i3900 = $o3900->add_item( 'Blue Test Tang - Large', 1, $blue_l, $large );
read_room( $store );
check_same( 'reserved', array( $s224 => 1 ), held( $i3900 ) );
ship( 3900, array( $i3900->get_id() ) );
$o3900->set_status( 'cancelled' );
work( $store, 3900, true );
Reserve_Shop::sweep( $store, $settings );
check_same( 'cancelled with its item on a shipment: nothing written', array( 2, 1 ), array( alive( $store, $s224 ), count( sold( $store, $s224 ) ) ) );

/* ------------------------------------------------------------------ */
echo "8. A recount between the pull and the label is not taken off twice\n";

$wrasse   = new Fh_Shop_Product( 531, 'Test Wrasse', array( 'quarantined-fish' ) );
$wrasse_s = new Fh_Shop_Product( 532, 'Test Wrasse - Small', array(), 531, array( 'pa_size' => 'small' ) );
$small    = array( 'pa_size' => 'small' );
$s231     = place( $store, 321, 'Test Wrasse', 5, array( 'product_id' => 531, 'size_slug' => 'small', 'size_label' => 'Small' ) );
$o4000    = fh_shop_order( new WC_Order( 4000, 'processing', array( '_fishotel_shipping_date' => $tomorrow ), time() + 60 ) );
$i4000    = $o4000->add_item( 'Test Wrasse - Small', 2, $wrasse_s, $small );
read_room( $store );
// Pulled this morning; the tank is recounted at noon with the 2 already bagged: 3.
$store->record_event( $s231, array( 'type' => 'count', 'qty' => 3, 'event_at' => Replay::is_date( $today ) ? Front_Desk::event_at_for_date( $today, '1999-01-01' ) : gmdate( 'Y-m-d H:i:s' ) ), $today, $settings );
check_same( 'the recount says 3', 3, alive( $store, $s231 ) );
ship( 4000 );
$o4000->set_status( 'shipped' );
work( $store, 4000, true );
check_same( 'the label: the event is dated at the start of the ship day, so the recount already counted them gone', array( 3, 1 ), array( alive( $store, $s231 ), count( sold( $store, $s231 ) ) ) );
check_same( 'an order whose ship day is long past: the event is dated now', '', Reserve::shipped_at( Replay::add_days( $today, -9 ), $today, '2026-03-22 05:00:01', Replay::add_days( $today, -40 ) ) );
check_same( 'a group checked in on the ship day: dated now', '', Reserve::shipped_at( $tomorrow, $today, '2026-04-01 05:00:01', $today ) );

/* ------------------------------------------------------------------ */
echo "9. The newest open orders are read first\n";

add_filter( 'fishotel_cleanroom_reserve_open_limit', '__fh_limit_two' );
function __fh_limit_two() {
	return 2;
}
$s232  = place( $store, 322, 'Test Wrasse', 9, array( 'product_id' => 531, 'size_slug' => 'small', 'size_label' => 'Small' ) );
$old   = fh_shop_order( new WC_Order( 4101, 'processing', array(), time() + 70 ) );
$i_old = $old->add_item( 'Test Wrasse - Small', 1, $wrasse_s, $small );
$new   = fh_shop_order( new WC_Order( 4102, 'processing', array(), time() + 71 ) );
$i_new = $new->add_item( 'Test Wrasse - Small', 1, $wrasse_s, $small );
$newer = fh_shop_order( new WC_Order( 4103, 'processing', array(), time() + 72 ) );
$i_nw  = $newer->add_item( 'Test Wrasse - Small', 1, $wrasse_s, $small );
read_room( $store );
check_same( 'past the limit, the two newest are reserved — they ship soonest', array( 0, 1, 1 ), array( Reserve::total( held( $i_old ) ), Reserve::total( held( $i_new ) ), Reserve::total( held( $i_nw ) ) ) );
$GLOBALS['fh_filters']['fishotel_cleanroom_reserve_open_limit'] = array();
foreach ( array( $old, $new, $newer ) as $fh_o ) {
	$fh_o->set_status( 'cancelled' );
}

/* ------------------------------------------------------------------ */
echo "10. Pull today: what already left is ticked, the count, the hint, one row per fish\n";

$hippo   = new Fh_Shop_Product( 541, 'Test Hippo', array( 'quarantined-fish' ) );
$hippo_s = new Fh_Shop_Product( 542, 'Test Hippo - Small', array(), 541, array( 'pa_size' => 'small' ) );
$puffer  = new Fh_Shop_Product( 551, 'Test Puffer', array( 'quarantined-fish' ) );
// Shipped before the switch-on (its delivery is tomorrow): no tank known, never written.
$o4200 = fh_shop_order( new WC_Order( 4200, 'shipped', array( '_fishotel_shipping_date' => $tomorrow ), time() - 86400 * 40 ) );
$i4200 = $o4200->add_item( 'Test Hippo - Small', 1, $hippo_s, $small );
// Open, two fish of two kinds the site knows no tank for.
$o4201 = fh_shop_order( new WC_Order( 4201, 'processing', array( '_fishotel_shipping_date' => $tomorrow ), time() + 80 ) );
$o4201->add_item( 'Test Hippo - Small', 1, $hippo_s, $small );
$o4201->add_item( 'Test Puffer', 1, $puffer );
$room = read_room( $store );
$list = Front_Desk::todo_screen( $room['todo'] );
$pull = $list['pull'];
$rows = array();
foreach ( $pull['unknown'] as $row ) {
	$rows[ $row['order_id'] . ':' . $row['name'] ] = array( $row['shipped'], count( $row['others'] ) );
}
check_same( 'the order shipped before the switch-on: ticked, nothing to pick', array( true, 0 ), $rows[ '4200:Test Hippo - Small' ] ?? null );
check_same( 'the open ones: not ticked', array( false, false ), array( $rows[ '4201:Test Hippo - Small' ][0] ?? null, $rows['4201:Test Puffer'][0] ?? null ) );
check_same( '"Tank not known" names only the fish still here', 'Tank not known — 1 × Test Hippo - Small (#4201), 1 × Test Puffer (#4201)', $pull['unknown_text'] );
check_contains( 'the count: every fish today, and the ones shipped', ' fish today · ', $pull['count_text'] );
check( '… and the shipped ones count as shipped', (int) $pull['shipped'] >= 3 && (int) $pull['shipped'] < (int) $pull['fish'] );
check_same( 'the feeding line above already says what to do: no second hint here', array( true, '' ), array( '' !== (string) ( $list['fast']['hint'] ?? '' ), $pull['hint'] ) );
check_same( 'all shipped once all left', '8 fish today · all shipped', Reserve::count_text( 8, 8 ) );
check_same( '… and never "still to pull"', '8 fish today · 2 shipped', Reserve::count_text( 8, 2 ) );

if ( ! function_exists( 'esc_html_e' ) ) {
	function esc_html_e( $text, $domain = null ) {
		echo esc_html( $text );
	}
}
if ( ! function_exists( 'esc_attr_e' ) ) {
	function esc_attr_e( $text, $domain = null ) {
		echo esc_attr( $text );
	}
}
if ( ! function_exists( 'wp_nonce_field' ) ) {
	function wp_nonce_field( $action = -1, $name = '_wpnonce' ) {
		echo '<input type="hidden" name="' . esc_attr( $name ) . '" value="' . esc_attr( wp_create_nonce( $action ) ) . '">';
	}
}
ob_start();
include FISHOTEL_MISC_PATH . 'includes/sections/clean-room/views/pull-today.php';
$html = (string) ob_get_clean();
check_same( 'the To do tab: one row per fish whose tank is not known (a list, not a paragraph)', 3, preg_match_all( '/<li class="fh-cr-pull__row[^"]*">\s*<span>(?:(?!<\/li>).)*?\(#420[01]\)/s', $html ) );
check( '… none of them repeats the feeding hint', false === strpos( $html, 'goes without' ) );

/* ------------------------------------------------------------------ */
echo "11. Ignore all, and the not-found list\n";

$before = count( Reserve_Shop::lost() );
check( 'there is something on the list', $before >= 1 );
$message = Reserve_Shop::act( $store, $settings, array( 'op' => 'ignore_all' ) );
check_contains( '"Ignore all" says how many', 'Ignored ' . $before, $message );
check_same( 'the list is empty', array(), Reserve_Shop::lost() );
work( $store, 3602 );
Reserve_Shop::sweep( $store, $settings );
check_same( '… and stays so when the orders are looked at again', array(), Reserve_Shop::lost() );

/* ------------------------------------------------------------------ */
echo "12. Pulled from a different tank: some of the line only\n";

$s233  = place( $store, 311, 'Test Wrasse', 4, array( 'product_id' => 531, 'size_slug' => 'small', 'size_label' => 'Small' ) );
$o4300 = fh_shop_order( new WC_Order( 4300, 'processing', array(), time() + 90 ) );
$i4300 = $o4300->add_item( 'Test Wrasse - Small', 2, $wrasse_s, $small );
read_room( $store );
$first = key( held( $i4300 ) );
$other = $first === $s233 ? $s232 : $s233;
Reserve_Shop::act( $store, $settings, array( 'op' => 'pick', 'order_id' => 4300, 'ref' => 'i:' . $i4300->get_id(), 'stay_id' => $other, 'from' => $first, 'qty' => 1 ) );
check_same( 'one came out of another tank: the line is split over the two', array( $first => 1, $other => 1 ), held( $i4300 ) );
$o4300->set_status( 'shipped' );
work( $store, 4300, true );
check_same( 'shipped: one out of each', array( array( 1 ), array( 1 ) ), array( array_column( array_values( array_filter( sold( $store, $first ), static function ( $e ) { return 0 === strpos( $e[1], 'order:4300 ' ); } ) ), 0 ), array_column( array_values( array_filter( sold( $store, $other ), static function ( $e ) { return 0 === strpos( $e[1], 'order:4300 ' ); } ) ), 0 ) ) );

// On Pull today, each row of a line split over two tanks offers the other one (found on the rehearsal
// site: both rows said "No other clean tank holds this fish").
$o4310 = fh_shop_order( new WC_Order( 4310, 'processing', array( '_fishotel_shipping_date' => $tomorrow ), time() + 91 ) );
$i4310 = $o4310->add_item( 'Test Wrasse - Small', 2, $wrasse_s, $small );
read_room( $store );
$first = key( held( $i4310 ) );
$other = $first === $s233 ? $s232 : $s233;
Reserve_Shop::act( $store, $settings, array( 'op' => 'pick', 'order_id' => 4310, 'ref' => 'i:' . $i4310->get_id(), 'stay_id' => $other, 'from' => $first, 'qty' => 1 ) );
$room   = read_room( $store );
$offers = array();
foreach ( Front_Desk::todo_screen( $room['todo'] )['pull']['buildings'] as $building ) {
	foreach ( $building['tanks'] as $ptank ) {
		foreach ( $ptank['rows'] as $row ) {
			if ( 4310 === (int) $row['order_id'] ) {
				$offers[ (int) $row['stay_id'] ] = array_column( $row['others'], 'stay_id' );
			}
		}
	}
}
check_same( 'a split line: each row offers the other tank of the two', array( true, true ), array( in_array( $other, $offers[ $first ] ?? array(), true ), in_array( $first, $offers[ $other ] ?? array(), true ) ) );
Reserve_Shop::act( $store, $settings, array( 'op' => 'pick', 'order_id' => 4310, 'ref' => 'i:' . $i4310->get_id(), 'stay_id' => $other, 'from' => $first, 'qty' => 0 ) );
check_same( '… and picking it joins the two rows', array( $other => 2 ), held( $i4310 ) );
$o4310->set_status( 'cancelled' );
work( $store, 4310, true );

/* ------------------------------------------------------------------ */
echo "13. The words\n";

check_same( 'a put-back reads as one', 'Back in the tank · order #1234 (2 fish)', Reserve::event_text( array( 'type' => 'sold', 'qty' => -2, 'note' => 'order:1234 item:5' ) ) );
check_same( 'the chip says Shipped, the rest names the order', array( 'Shipped', 'order #1234 (2 fish)', 'Back in the tank', '' ), array( Reserve::type_label( array( 'type' => 'sold', 'qty' => 2, 'note' => 'order:1234 item:5' ) ), Reserve::event_detail( array( 'type' => 'sold', 'qty' => 2, 'note' => 'order:1234 item:5' ) ), Reserve::type_label( array( 'type' => 'sold', 'qty' => -2, 'note' => 'order:1234 item:5' ) ), Reserve::type_label( array( 'type' => 'sold', 'qty' => 1, 'note' => '' ) ) ) );
check_contains( 'the hand "Shipped" note for several orders: when they ship', 'when they ship', Reserve::sold_note( 3, array( '1', '2', '3' ) ) );
check_contains( '… for one: when it ships', 'when it ships', Reserve::sold_note( 2, array( '1' ) ) );
// Found on the rehearsal site: "1 × Firefish Test - 2-3.5" 2-3.5" (#34275)" on the not-found list.
check_same(
	'a not-found row names the fish and its size once',
	array( '1 × Firefish Test 2-3.5" (#34275)', '1 × Firefish Test - 2-3.5" (#34275)' ),
	array(
		Reserve::lost_row_text( array( 'qty' => 1, 'name' => 'Firefish Test - 2-3.5"', 'names' => array( 'Firefish Test' ), 'size_label' => '2-3.5"', 'number' => '34275' ) ),
		Reserve::lost_row_text( array( 'qty' => 1, 'name' => 'Firefish Test - 2-3.5"', 'names' => array(), 'size_label' => '2-3.5"', 'number' => '34275' ) ),
	)
);

function get_term_by( $field, $value, $taxonomy ) {
	return 'pa_size' === $taxonomy && '2-3-5' === $value ? (object) array( 'name' => '2-3.5&quot;' ) : false;
}
check_same( 'a size kept as its slug on the line reads as the shop names it', array( '2-3.5"', 'Medium' ), array( Ship_Lines::size_name( 'pa_size', '2-3-5' ), Ship_Lines::size_name( 'size', 'Medium' ) ) );

/* ------------------------------------------------------------------ */
echo "14. The Invoices section never counts an unpaid invoice's fish twice\n";

$counts = Reserve_Shop::with_invoices(
	array( 7 => array( 'qty' => 3, 'orders' => array( '5000', '5001' ) ) ),
	array(
		array( 'id' => 5000, 'paid' => false, 'lines' => array( array( 'ref' => 'i:50001', 'qty' => 2, 'held' => array( 7 => 2 ), 'link' => array( 'stay_id' => 7 ) ) ) ),
		array( 'id' => 5001, 'paid' => true, 'lines' => array( array( 'ref' => 'i:50011', 'qty' => 1, 'held' => array( 7 => 1 ), 'link' => array( 'stay_id' => 7 ) ) ) ),
	),
	array()
);
check_same( 'the on-hold invoice order\'s 2 are marked as on an open invoice; the paid one\'s are not', 2, $counts[7]['invoice'] );

/* ------------------------------------------------------------------ */
echo "15. What it costs\n";

$o4400 = fh_shop_order( new WC_Order( 4400, 'processing', array(), time() + 95 ) );
$i4400 = $o4400->add_item( 'Test Wrasse - Small', 1, $wrasse_s, $small );
read_room( $store );
check_same( 'reserved', 1, Reserve::total( held( $i4400 ) ) );
ship( 4400, array( $i4400->get_id() ) );
$fh_queries = $GLOBALS['fh_shop_queries'];
work( $store, 4400 );
check_same( 'tracking added to an order whose fish are all reserved: taken out without reading every open order', array( 0, 1 ), array( $GLOBALS['fh_shop_queries'] - $fh_queries, count( array_filter( sold( $store, key( held( $i4400 ) ) ), static function ( $e ) { return 0 === strpos( $e[1], 'order:4400 ' ); } ) ) ) );
read_room( $store );
$fh_queries = $GLOBALS['fh_shop_queries'];
$fh_loads   = $GLOBALS['fh_shop_loads'];
Reserve_Shop::reset();
$store->room( $today, $settings, $protocols );
check_same( 'the Board read again: the counts and the pull list come from their caches — no order is read', array( 0, 0 ), array( $GLOBALS['fh_shop_queries'] - $fh_queries, $GLOBALS['fh_shop_loads'] - $fh_loads ) );

/* ------------------------------------------------------------------ */
echo "16. The hooks: what drops the counts\n";

$GLOBALS['fh_cron'] = array();
function wp_next_scheduled( $hook ) {
	return $GLOBALS['fh_cron'][ $hook ] ?? false;
}
function wp_schedule_event( $time, $recurrence, $hook ) {
	$GLOBALS['fh_cron'][ $hook ] = (int) $time;
	return true;
}
function wp_clear_scheduled_hook( $hook ) {
	unset( $GLOBALS['fh_cron'][ $hook ] );
	return 1;
}
$GLOBALS['fh_actions'] = array();
Reserve_Shop::register( $settings );
$flushes = static function ( $hook ) {
	foreach ( (array) ( $GLOBALS['fh_actions'][ $hook ] ?? array() ) as $entry ) {
		$callback = is_array( $entry ) && isset( $entry['callback'] ) ? $entry['callback'] : ( is_array( $entry ) && isset( $entry[0] ) ? $entry[0] : $entry );
		if ( array( Reserve_Shop::class, 'flush' ) === $callback ) {
			return true;
		}
	}
	return false;
};
check_same( 'any order save does not drop them (ShipTracker saves orders often); a status change, a note do', array( false, true, true ), array( $flushes( 'woocommerce_update_order' ), $flushes( 'woocommerce_order_status_changed' ), $flushes( 'woocommerce_order_note_added' ) ) );
set_transient( Reserve_Shop::TRANSIENT, array( 'counts' => array( 1 => array( 'qty' => 9 ) ) ), 600 );
do_action( 'update_option_fishotel_cleanroom_settings' );
check_same( 'the tick switched off or on: the counts worked out before are dropped', false, get_transient( Reserve_Shop::TRANSIENT ) );
set_transient( Reserve_Shop::TRANSIENT, array( 'counts' => array() ), 600 );
do_action( 'fishotel_cleanroom_event_recorded', 1, 1, array( 'type' => 'loss' ), array() );
check( 'a loss logged on the phone keeps them (no full read again after every tap)', false !== get_transient( Reserve_Shop::TRANSIENT ) );
do_action( 'fishotel_cleanroom_event_recorded', 1, 1, array( 'type' => 'arrival' ), array() );
check_same( 'a group logged: dropped — a fish whose tank was not known may be reserved now', false, get_transient( Reserve_Shop::TRANSIENT ) );

fh_finish();
