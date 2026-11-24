<?php
/**
 * Reserved → Pull today → Shipped, the whole way through the shop
 * (Reserve_Shop): an order comes in and its fish are reserved in their
 * tank, the ship day's list says which tank to pull them from, and once
 * the order has shipped the fish come off the tank's count by themselves
 * — exactly once, put back when the tracking is removed, never for an
 * order that had already left when this was switched on.
 *
 * Runs on a stand-in shop (fake-shop-orders.php) and the in-memory store:
 * invented orders, fish and ids. No WordPress, no WooCommerce, no database.
 *
 * Run from the plugin root:  php includes/sections/clean-room/tests/reserve-shop-test.php
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

$GLOBALS['fh_now'] = '2026-04-01 09:00:00';
$today             = (string) current_time( 'Y-m-d' );
$tomorrow          = Replay::add_days( $today, 1 );
$settings          = Settings::get();
$protocols         = Settings::protocols();

add_filter( 'fishotel_cleanroom_batch_present', '__return_true_for_reserve' );
function __return_true_for_reserve() {
	return true;
}

function place( Fake_Store $store, $tank_number, $species, $qty, array $more = array() ) {
	global $today, $settings;
	$tank = $store->tank_by_number( $tank_number );
	$date = Replay::add_days( $today, -40 );
	$id   = $store->create_stay( array_merge( array( 'species' => $species, 'checkin_date' => $date, 'tank_id' => (int) $tank['id'] ), $more ) );
	$store->record_event( $id, array( 'type' => 'arrival', 'qty' => $qty, 'to_tank_id' => (int) $tank['id'], 'event_at' => Front_Desk::event_at_for_date( $date, $today ) ), $today, $settings );
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

$tang      = new Fh_Shop_Product( 501, 'Test Tang', array( 'quarantined-fish' ) );
$tang_m    = new Fh_Shop_Product( 502, 'Test Tang - Medium', array(), 501, array( 'pa_size' => 'medium' ) );
$angel     = new Fh_Shop_Product( 601, 'Sample Angel', array( 'quarantined-fish' ) );
$angel_s   = new Fh_Shop_Product( 602, 'Sample Angel - Small', array(), 601, array( 'pa_size' => 'small' ) );
$hippo     = new Fh_Shop_Product( 701, 'Blue Hippo Tang', array( 'quarantined-fish' ) );
$hippo_s   = new Fh_Shop_Product( 702, 'Blue Hippo Tang - Small', array(), 701, array( 'pa_size' => 'small' ) );
$medium    = array( 'pa_size' => 'medium' );
$small     = array( 'pa_size' => 'small' );

$store  = new Fake_Store();
$s121   = place( $store, 121, 'Test Tang', 3, array( 'product_id' => 501, 'size_slug' => 'medium', 'size_label' => 'Medium' ) );
$s221   = place( $store, 221, 'Test Tang', 4, array( 'product_id' => 501, 'size_slug' => 'medium', 'size_label' => 'Medium' ) );
$s122   = place( $store, 122, 'Sample Angel', 2, array( 'product_id' => 601, 'size_slug' => 'small', 'size_label' => 'Small' ) );
$s222   = place( $store, 222, 'Sample Angel', 2, array( 'product_id' => 601, 'size_slug' => 'small', 'size_label' => 'Small' ) );
$s521   = place( $store, 521, 'Test Tang', 5, array( 'product_id' => 501, 'size_slug' => 'medium', 'size_label' => 'Medium' ) );
// 121 sells first: its own sale date is the earliest.
$store->record_event( $s121, array( 'type' => 'note', 'note' => Replay::NOTE_SALE_READY . Replay::add_days( $today, -10 ), 'event_at' => gmdate( 'Y-m-d H:i:s' ) ), $today, $settings );
$store->record_event( $s221, array( 'type' => 'note', 'note' => Replay::NOTE_SALE_READY . Replay::add_days( $today, -5 ), 'event_at' => gmdate( 'Y-m-d H:i:s' ) ), $today, $settings );
$store->record_event( $s122, array( 'type' => 'note', 'note' => Replay::NOTE_SALE_READY . Replay::add_days( $today, -10 ), 'event_at' => gmdate( 'Y-m-d H:i:s' ) ), $today, $settings );
$store->record_event( $s222, array( 'type' => 'note', 'note' => Replay::NOTE_SALE_READY . Replay::add_days( $today, -5 ), 'event_at' => gmdate( 'Y-m-d H:i:s' ) ), $today, $settings );

/* ------------------------------------------------------------------ */
echo "1. Switched on: an order already gone is never touched\n";

check_same( 'never switched on before', 0, Reserve_Shop::since() );
$gone_before = fh_shop_order( new WC_Order( 1100, 'completed', array( '_fishotel_shipping_date' => $today ), time() - 86400 * 3 ) );
$gone_item   = $gone_before->add_item( 'Test Tang - Medium', 2, $tang_m, $medium );
$shipped_bef = fh_shop_order( new WC_Order( 1101, 'shipped', array( '_fishotel_shipping_date' => $today ), time() - 86400 ) );
$shipped_it  = $shipped_bef->add_item( 'Sample Angel - Small', 1, $angel_s, $small );
ship( 1101 );

$room = $store->room( $today, $settings, $protocols );
check( 'the first read stores the switch-on time', Reserve_Shop::since() > 0 );
$since = Reserve_Shop::since();
check_same( 'orders that had left are not reserved', array( array(), array(), '', '' ), array( held( $gone_item ), held( $shipped_it ), (string) $gone_item->get_meta( Reserve::META_SEEN ), (string) $shipped_it->get_meta( Reserve::META_SEEN ) ) );
work( $store, 1100 );
work( $store, 1101 );
Reserve_Shop::sweep( $store, $settings );
check_same( '… nor taken out of a tank, by their hooks or by the sweep', array( 3, 2, array(), array() ), array( alive( $store, $s121 ), alive( $store, $s122 ), sold( $store, $s121 ), sold( $store, $s122 ) ) );
check_same( '… nor put on the not-found list', array(), Reserve_Shop::lost() );

/* ------------------------------------------------------------------ */
echo "2. Reserved when the order comes in\n";

$o1234 = fh_shop_order( new WC_Order( 1234, 'processing', array( '_fishotel_shipping_date' => $tomorrow ), time() - 7200 ) );
$i1234 = $o1234->add_item( 'Test Tang - Medium', 2, $tang_m, $medium );
fresh();
$room = $store->room( $today, $settings, $protocols );
check_same( 'the line is held in the group that sells first, and marked seen', array( array( $s121 => 2 ), true ), array( held( $i1234 ), (int) $i1234->get_meta( Reserve::META_SEEN ) > 0 ) );
$occ = array();
foreach ( $room['tanks'] as $tank ) {
	foreach ( $tank['occupants'] as $stay ) {
		$occ[ (int) $stay['id'] ] = $stay;
	}
}
check_same( 'the tank card: 2 reserved · #1234 — and still 3 alive (reserved is no event)', array( 2, array( '1234' ), 3, '2 reserved · #1234' ), array( $occ[ $s121 ]['reserved'], $occ[ $s121 ]['reserved_orders'], $occ[ $s121 ]['qty_live'], Reserve::reserved_text( $occ[ $s121 ]['reserved'], $occ[ $s121 ]['reserved_orders'] ) ) );
check_same( 'nothing was written in the tank', array(), sold( $store, $s121 ) );
check_same( 'the phone\'s tank screen says it too', array( 2, '#1234' ), ( static function () use ( $store, $settings, $today ) {
	$screen = Front_Desk::screen_from( $store, 121, $settings, $today );
	foreach ( $screen['tank']['occupants'] as $o ) {
		if ( 'Test Tang' === $o['species'] ) {
			return array( $o['reserved'], $o['reservedFor'] );
		}
	}
	return null;
} )() );

$o1240 = fh_shop_order( new WC_Order( 1240, 'on-hold', array( '_fishotel_shipping_date' => $tomorrow ), time() - 3600 ) );
$i1240 = $o1240->add_item( 'Test Tang - Medium', 4, $tang_m, $medium );
fresh();
$store->room( $today, $settings, $protocols );
check_same( 'a second order takes what is free: the 1 left in 121, then 3 of 221 — 1234 keeps its fish', array( array( $s121 => 2 ), array( $s121 => 1, $s221 => 3 ) ), array( held( $i1234 ), held( $i1240 ) ) );
fresh();
$store->room( $today, $settings, $protocols );
check_same( 'read again: nothing moves (stock must not drift)', array( array( $s121 => 2 ), array( $s121 => 1, $s221 => 3 ) ), array( held( $i1234 ), held( $i1240 ) ) );
check_same( 'the counts: 121 all reserved, 221 3 of 4', array( 3, 3 ), array( Reserve_Shop::ensure( $store, $settings )['counts'][ $s121 ]['qty'], Reserve_Shop::ensure( $store, $settings )['counts'][ $s221 ]['qty'] ) );
check_same( 'the quarantine group is never reserved by itself', false, isset( Reserve_Shop::ensure( $store, $settings )['counts'][ $s521 ] ) );

$o1251 = fh_shop_order( new WC_Order( 1251, 'processing', array( '_fishotel_shipping_date' => $tomorrow ), time() - 1800 ) );
$i1251 = $o1251->add_item( 'Blue Hippo Tang - Small', 1, $hippo_s, $small );
$i1252 = $o1251->add_item( 'Test Tang', 1, $tang ); // No size: only a guess.
fresh();
$store->room( $today, $settings, $protocols );
check_same( 'a fish in no tank, and a fish known only by its product without a size: tank not known', array( array(), array(), true ), array( held( $i1251 ), held( $i1252 ), (int) $i1251->get_meta( Reserve::META_SEEN ) > 0 ) );
$hippo_stay = place( $store, 112, 'Blue Hippo Tang', 2, array( 'product_id' => 701, 'size_slug' => 'small', 'size_label' => 'Small' ) );
fresh();
$store->room( $today, $settings, $protocols );
check_same( 'logged later: reserved on the next read', array( $hippo_stay => 1 ), held( $i1251 ) );

/* ------------------------------------------------------------------ */
echo "3. Pull today\n";

fresh();
$room = $store->room( $today, $settings, $protocols );
$pull = $room['todo']['pull'];
$text = array();
foreach ( $pull['buildings'] as $building ) {
	foreach ( $building['tanks'] as $tank ) {
		$text[] = Reserve::tank_text( $tank );
	}
}
check_same( 'grouped by building, then tank: which tank to pull each fish from', array( 'Tank 112 — 1 × Blue Hippo Tang Small (#1251)', 'Tank 121 — 2 × Test Tang Medium (#1234), 1 × Test Tang Medium (#1240)', 'Tank 221 — 3 × Test Tang Medium (#1240)' ), $text );
check_same( 'the fish known only as a guess: Tank not known', 'Tank not known — 1 × Test Tang (#1251)', Reserve::unknown_text( $pull['unknown'] ) );
check_same( 'Batch\'s pick list lists the same orders and fish: 2 + 4 + 1 + 1', 8, $pull['fish'] );
check( '"Pulled from a different tank" offers the other clean groups of that fish', in_array( $s221, array_column( $pull['buildings'][0]['tanks'][1]['rows'][0]['others'], 'stay_id' ), true ) && ! in_array( $s521, array_column( $pull['buildings'][0]['tanks'][1]['rows'][0]['others'], 'stay_id' ), true ) );

/* ------------------------------------------------------------------ */
echo "4. Shipped: exactly the line comes off\n";

ship( 1234, array( $i1234->get_id() ) );
$o1234->set_status( 'shipped' );
work( $store, 1234, true );
check_same( 'one sold event, noted with the order and item', array( array( 2, 'order:1234 item:' . $i1234->get_id() ) ), sold( $store, $s121 ) );
check_same( '121: 3 − 2 = 1 alive; 221 untouched', array( 1, 4 ), array( alive( $store, $s121 ), alive( $store, $s221 ) ) );
work( $store, 1234 );
Reserve_Shop::sweep( $store, $settings );
check_same( 'saved twice, swept: no change', array( array( array( 2, 'order:1234 item:' . $i1234->get_id() ) ), 1 ), array( sold( $store, $s121 ), alive( $store, $s121 ) ) );
check_same( 'History says "Shipped · order #1234"', 'Shipped · order #1234 (2 fish)', Reserve::event_text( $store->events( $s121 )[ count( $store->events( $s121 ) ) - 1 ] ) );
fresh();
$room = $store->room( $today, $settings, $protocols );
$rows = $room['todo']['pull']['buildings'][0]['tanks'][1]['rows'];
check_same( 'Pull today: the line already out of its tank reads Shipped', array( true, false ), array( $rows[0]['shipped'], $rows[1]['shipped'] ) );
check_same( '… and it no longer counts as reserved', 1, Reserve_Shop::ensure( $store, $settings )['counts'][ $s121 ]['qty'] );

/* ------------------------------------------------------------------ */
echo "5. Tracking removed before delivery: put back\n";

ship( 1240, array( $i1240->get_id() ) );
work( $store, 1240 );
check_same( 'a partial shipment naming the line (the order still on hold): taken out of both tanks', array( 0, 1 ), array( alive( $store, $s121 ), alive( $store, $s221 ) ) );
$GLOBALS['fh_shipments'][1240] = array();
work( $store, 1240 );
check_same( 'the tracking removed (no hook — the sweep or the next hook): back in both tanks', array( 1, 4 ), array( alive( $store, $s121 ), alive( $store, $s221 ) ) );
check_same( '… by the same note, as a put-back', array( 3, -3 ), array_column( sold( $store, $s221 ), 0 ) );

/* ------------------------------------------------------------------ */
echo "6. Cancelled before it left: released, nothing written\n";

$o1240->set_status( 'cancelled' );
work( $store, 1240 );
fresh();
check_same( 'no more event; its fish are free again', array( 2, false ), array( count( sold( $store, $s221 ) ), isset( Reserve_Shop::ensure( $store, $settings )['counts'][ $s221 ] ) ) );
check_same( 'the reservation stays on the item as history', array( $s121 => 1, $s221 => 3 ), held( $i1240 ) );

/* ------------------------------------------------------------------ */
echo "7. A line spread over two tanks; a refund to nothing before it left\n";

$o1300 = fh_shop_order( new WC_Order( 1300, 'processing', array(), time() - 600 ) );
$i1300 = $o1300->add_item( 'Sample Angel - Small', 3, $angel_s, $small );
$i1301 = $o1300->add_item( 'Test Tang - Medium', 2, $tang_m, $medium );
fresh();
$store->room( $today, $settings, $protocols );
check_same( 'Angel × 3: 2 from 122, 1 from 222', array( $s122 => 2, $s222 => 1 ), held( $i1300 ) );
$o1300->refund_item( $i1301->get_id(), 2 );
fresh();
check_same( 'the tang line refunded to nothing holds nothing any more: released', array( false, false ), array( isset( Reserve_Shop::ensure( $store, $settings )['counts'][ $s121 ] ), isset( Reserve_Shop::ensure( $store, $settings )['counts'][ $s221 ] ) ) );
$o1300->set_status( 'completed' );
work( $store, 1300, true );
check_same( 'completed: one event in each tank', array( array( array( 2, 'order:1300 item:' . $i1300->get_id() ) ), array( array( 1, 'order:1300 item:' . $i1300->get_id() ) ) ), array( sold( $store, $s122 ), sold( $store, $s222 ) ) );
check_same( '… and none for the refunded line', array( 3, 2 ), array( count( sold( $store, $s121 ) ), count( sold( $store, $s221 ) ) ) );

/* ------------------------------------------------------------------ */
echo "8. A refund after it left (dead on arrival) changes nothing\n";

$o1300->refund_item( $i1300->get_id(), 1 );
work( $store, 1300 );
$o1300->set_status( 'refunded' );
work( $store, 1300 );
check_same( 'still 2 out of 122 and 1 out of 222', array( 0, 1 ), array( alive( $store, $s122 ), alive( $store, $s222 ) ) );

/* ------------------------------------------------------------------ */
echo "9. Fewer fish alive than reserved: the not-found list\n";

$s311  = place( $store, 311, 'Mock Goby', 3, array( 'size_slug' => 'small', 'size_label' => 'Small' ) );
$goby  = new Fh_Shop_Product( 801, 'Mock Goby', array( 'quarantined-fish' ) );
$o1400 = fh_shop_order( new WC_Order( 1400, 'processing', array(), time() - 500 ) );
$i1400 = $o1400->add_item( 'Mock Goby', 2, $goby, $small );
fresh();
$store->room( $today, $settings, $protocols );
check_same( 'reserved by its name and size', array( $s311 => 2 ), held( $i1400 ) );
$store->record_event( $s311, array( 'type' => 'loss', 'qty' => 2, 'event_at' => gmdate( 'Y-m-d H:i:s' ) ), $today, $settings );
$o1400->update_meta_data( '_fst_ship_mode', 'local' );
work( $store, 1400 );
check_same( 'a local pickup has left — but only 1 is alive: nothing written', array( array(), 1 ), array( sold( $store, $s311 ), alive( $store, $s311 ) ) );
$lost = Reserve_Shop::lost();
check_same( 'it is on the not-found list instead', array( 1, 2, 'short' ), array( count( $lost ), (int) current( $lost )['qty'], current( $lost )['why'] ) );
check_same( 'the line on the To do tab', 'Could not find a tank for 2 fish that shipped', Reserve::lost_text( Reserve_Shop::lost_fish( $lost ) ) );

$o1251->set_status( 'shipped' );
work( $store, 1251, true );
check_same( 'the guessed fish shipped: on the list too; the hippo came out of 112', array( 2, 1, 1 ), array( count( Reserve_Shop::lost() ), alive( $store, $hippo_stay ), (int) Reserve_Shop::lost()[ '1251|i:' . $i1252->get_id() ]['qty'] ) );

$message = Reserve_Shop::act( $store, $settings, array( 'op' => 'pick', 'order_id' => 1251, 'ref' => 'i:' . $i1252->get_id(), 'stay_id' => $s221 ) );
check_same( '"It was tank 221": written there, and off the list', array( array( 1, 'order:1251 item:' . $i1252->get_id() ) ), array_slice( sold( $store, $s221 ), 2 ) );
check( '… stored as its reservation', array( $s221 => 1 ) === held( $i1252 ) && false === isset( Reserve_Shop::lost()[ '1251|i:' . $i1252->get_id() ] ) );
check_contains( '… and said', 'tank 221', $message );
check_same( '"Ignore" takes a line off for good', array( 'Ignored: that line is no longer listed.', array() ), array( Reserve_Shop::act( $store, $settings, array( 'op' => 'ignore', 'order_id' => 1400, 'ref' => 'i:' . $i1400->get_id() ) ), Reserve_Shop::lost() ) );
work( $store, 1400 );
check_same( '… and it does not come back', array(), Reserve_Shop::lost() );

/* ------------------------------------------------------------------ */
echo "10. Pulled from a different tank — before and after it shipped\n";

$s321  = place( $store, 321, 'Test Tang', 2, array( 'product_id' => 501, 'size_slug' => 'medium', 'size_label' => 'Medium' ) );
$o1500 = fh_shop_order( new WC_Order( 1500, 'processing', array(), time() - 400 ) );
$i1500 = $o1500->add_item( 'Test Tang - Medium', 1, $tang_m, $medium );
fresh();
$store->room( $today, $settings, $protocols );
$first = key( held( $i1500 ) );
Reserve_Shop::act( $store, $settings, array( 'op' => 'pick', 'order_id' => 1500, 'ref' => 'i:' . $i1500->get_id(), 'stay_id' => $s321, 'from' => $first ) );
check_same( 'before it shipped: only the reservation moves', array( array( $s321 => 1 ), array() ), array( held( $i1500 ), sold( $store, $s321 ) ) );
$o1500->set_status( 'shipped' );
work( $store, 1500, true );
check_same( 'shipped: it comes out of the tank picked', array( 1, 1 ), array( alive( $store, $s321 ), count( sold( $store, $s321 ) ) ) );
$before221 = alive( $store, $s221 );
Reserve_Shop::act( $store, $settings, array( 'op' => 'pick', 'order_id' => 1500, 'ref' => 'i:' . $i1500->get_id(), 'stay_id' => $s221, 'from' => $s321 ) );
check_same( 'after it shipped: 321 gets it back, 221 gives it', array( 2, $before221 - 1 ), array( alive( $store, $s321 ), alive( $store, $s221 ) ) );
work( $store, 1500 );
check_same( '… and asked again, nothing more', array( 2, $before221 - 1 ), array( alive( $store, $s321 ), alive( $store, $s221 ) ) );

/* ------------------------------------------------------------------ */
echo "11. A combined box: read through the orders in it\n";

$s131 = place( $store, 123, 'Sample Angel', 4, array( 'product_id' => 601, 'size_slug' => 'small', 'size_label' => 'Small' ) );
$out0 = alive( $store, $s131 ) + alive( $store, $s222 );
$box  = fh_shop_order( new WC_Order( 1600, 'fulfillment', array( '_fishotel_is_fulfillment' => '1', '_fishotel_fulfillment_sources' => '1601,1602' ), time() - 300 ) );
$copy = $box->add_item( 'Sample Angel - Small', 2, $angel_s, $small ); // A copy of 1601's line.
$a    = fh_shop_order( new WC_Order( 1601, 'processing', array( '_fishotel_fulfilled_by_order' => '1600' ), time() - 900 ) );
$ia   = $a->add_item( 'Sample Angel - Small', 2, $angel_s, $small );
$b    = fh_shop_order( new WC_Order( 1602, 'processing', array( '_fishotel_fulfilled_by_order' => '1600' ), time() - 800 ) );
$ib   = $b->add_item( 'Sample Angel - Small', 1, $angel_s, $small );
fresh();
$store->room( $today, $settings, $protocols );
check_same( 'the orders in the box are reserved; the box\'s own copies are not', array( 2, 1, array() ), array( Reserve::total( held( $ia ) ), Reserve::total( held( $ib ) ), held( $copy ) ) );
$box->set_status( 'shipped' );
work( $store, 1600, true );
check_same( 'the box shipped: the fish of both orders come out', 3, $out0 - alive( $store, $s131 ) - alive( $store, $s222 ) );

/* ------------------------------------------------------------------ */
echo "12. Switched off: nothing shown, nothing written; on again keeps the first time\n";

$off   = array_merge( $settings, array( Reserve::SETTING => false ) );
$o1700 = fh_shop_order( new WC_Order( 1700, 'processing', array(), time() - 100 ) );
$i1700 = $o1700->add_item( 'Test Tang - Medium', 1, $tang_m, $medium );
fresh();
$room = $store->room( $today, $off, $protocols );
check_same( 'off: no reservation made or shown, no pull list', array( array(), false, false ), array( held( $i1700 ), array_key_exists( 'pull', (array) $room['todo'] ), isset( $room['tanks'][0]['occupants'][0]['reserved'] ) && $room['tanks'][0]['occupants'][0]['reserved'] > 0 ) );
$o1700->set_status( 'shipped' );
check_same( '… and an order hook writes nothing', array(), Reserve_Shop::process( $store, $off, 1700, true ) );
check_same( 'on again: the switch-on time is the first one', $since, Reserve_Shop::since( true ) );

/* ------------------------------------------------------------------ */
echo "13. The feeding ladder reads the reservation first\n";

// Another clean group of the same fish and size, so the ladder alone would say "also in" another system.
place( $store, 211, 'Sample Angel', 2, array( 'product_id' => 601, 'size_slug' => 'small', 'size_label' => 'Small' ) );

$o1800 = fh_shop_order( new WC_Order( 1800, 'processing', array( '_fishotel_shipping_date' => $tomorrow ), time() - 50 ) );
$i1800 = $o1800->add_item( 'Sample Angel - Small', 1, $angel_s, $small );
$i1800->set_meta( Reserve::META, Reserve::encode( array( $s131 => 1 ) ) );
$i1800->set_meta( Reserve::META_SEEN, (string) time() );
fresh();
$room = $store->room( $today, $settings, $protocols );
$fasting = array();
foreach ( $room['todo']['blocks'] as $block ) {
	if ( ! empty( $block['fast'] ) && false !== strpos( $block['fast']['text'], '#1800' ) ) {
		$fasting[] = $block['fast']['text'];
	}
}
check_contains( 'the angel held in 123 makes only that system fast — not "also in" another', '1 × Sample Angel - Small (tank 123, #1800)', $fasting );

/* ------------------------------------------------------------------ */
echo "14. As the screens render it\n";

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
foreach ( array( 'selected', 'checked', 'disabled' ) as $fh_word ) {
	if ( ! function_exists( $fh_word ) ) {
		eval( 'function ' . $fh_word . '( $a, $b = true, $echo = true ) { if ( (string) $a === (string) $b ) { echo " ' . $fh_word . '=\"' . $fh_word . '\""; } }' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- three one-line template stubs.
	}
}

$o2000 = fh_shop_order( new WC_Order( 2000, 'processing', array( '_fishotel_shipping_date' => $tomorrow ), time() - 5 ) );
$i2000 = $o2000->add_item( 'Blue Hippo Tang - Small', 5, $hippo_s, $small );
fresh();
$store->room( $today, $settings, $protocols );
$o2000->set_status( 'shipped' );
work( $store, 2000, true );

fresh();
$tab          = 'todo';
$can_settings = false;
$tables_ok    = true;
$flash        = '';
$list         = Front_Desk::todo_screen( $store->room( $today, $settings, $protocols )['todo'] );
ob_start();
include FISHOTEL_MISC_PATH . 'includes/sections/clean-room/views/todo.php';
$html = (string) ob_get_clean();
check_contains( 'the To do tab has the Pull today block', '<h2>Pull today</h2>', $html );
check_contains( '… a tank with its fish and order', '2 × Test Tang Medium (#1234)', $html );
check_contains( '… a line out of its tank reads Shipped', '<em>Shipped</em>', $html );
check_contains( '… each row offers "Pulled from a different tank"', 'Pulled from a different tank', $html );
check_contains( '… posting to the corrections', 'name="action" value="fishotel_cleanroom_reserve"', $html );
check_contains( 'the not-found line', 'Could not find a tank for', $html );
check_contains( '… with Ignore all', 'Ignore all', $html );
check_same( 'the phone gets the same list, with its words', array( true, true ), array( '' !== (string) $list['pull']['count_text'], '' !== (string) $list['lost_text'] ) );

$labels_url = 'https://example.test/labels';
$filters    = \FisHotel\Misc\Sections\Clean_Room\Admin::filters_from_request( array() );
$room       = $store->room( $today, $settings, $protocols );
$tanks      = $room['tanks'];
$todo       = $room['todo'];
$groups     = \FisHotel\Misc\Sections\Clean_Room\Layout::group( $store->buildings(), $tanks );
$totals     = \FisHotel\Misc\Sections\Clean_Room\Admin::totals( $tanks );
$doses      = \FisHotel\Misc\Sections\Clean_Room\Dose::board_doses( $store->latest_doses() );
$sizes      = \FisHotel\Misc\Sections\Clean_Room\Sizes::standard( $store, $settings )['sizes'];
$temp       = \FisHotel\Misc\Sections\Clean_Room\Admin_Temp::board( $store, $tanks, $protocols, $today );
$origins    = \FisHotel\Misc\Sections\Clean_Room\Admin::origins( $store, $tanks );
$tab        = 'board';
ob_start();
include FISHOTEL_MISC_PATH . 'includes/sections/clean-room/views/board.php';
$board = (string) ob_get_clean();
check( 'the Board\'s tank card says what is reserved', 1 === preg_match( '/class="fh-cr-reserved">\d+ reserved · #\d+/', $board ) );

$screen = Front_Desk::screen_from( $store, 123, $settings, $today );
$angels = array_values( array_filter( $screen['tank']['occupants'], static function ( $o ) { return $o['reserved'] > 0; } ) );
check( 'the phone\'s hand "Shipped" on a group with reserved fish says so', ! empty( $angels ) && false !== strpos( (string) $angels[0]['soldNote'], 'when it ships' ) );
$before_hand = $angels ? (int) $angels[0]['qtyLive'] : 0;
$result      = $angels ? Front_Desk::perform( 'sold', array( 'store' => $store, 'settings' => $settings, 'today' => $today, 'tank' => $store->tank_by_number( 123 ), 'base' => array( 'actor_id' => 1, 'source' => 'qr' ) ), array( 'stay_id' => $angels[0]['stayId'], 'qty' => 1 ) ) : array( 'error' => 'none' );
check_same( '… and still allows it: the hand sale is written as before', array( false, $before_hand - 1 ), array( isset( $result['error'] ), $angels ? alive( $store, $angels[0]['stayId'] ) : -1 ) );

/* ------------------------------------------------------------------ */
echo "15. The hooks and the hourly sweep\n";

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

Reserve_Shop::register( $off );
check_same( 'switched off: no sweep is armed, no order hook added', array( false, false ), array( wp_next_scheduled( Reserve_Shop::CRON_HOOK ), isset( $GLOBALS['fh_actions']['woocommerce_order_note_added'] ) ) );
Reserve_Shop::register( $settings );
check( 'on: the hourly sweep is armed and the order hooks are there', false !== wp_next_scheduled( Reserve_Shop::CRON_HOOK ) && isset( $GLOBALS['fh_actions']['woocommerce_order_status_changed'], $GLOBALS['fh_actions']['woocommerce_order_note_added'], $GLOBALS['fh_actions']['woocommerce_order_refunded'] ) );

$o1900 = fh_shop_order( new WC_Order( 1900, 'processing', array(), time() - 20 ) );
$i1900 = $o1900->add_item( 'Test Tang - Medium', 1, $tang_m, $medium );
fresh();
set_transient( Reserve_Shop::TRANSIENT, array( 'counts' => array() ), 600 );
do_action( 'woocommerce_new_order', 1900 );
check_same( 'a new order on the storefront only drops the cache — the next read reserves it', array( false, array() ), array( get_transient( Reserve_Shop::TRANSIENT ), held( $i1900 ) ) );
$o1900->set_status( 'shipped' );
$before = alive( $store, $s221 ) + alive( $store, $s321 ) + alive( $store, $s121 );
do_action( 'woocommerce_order_status_changed', 1900, 'processing', 'shipped' );
Reserve_Shop::run_queue( $store, $settings );
check_same( 'it left before anyone looked (open until now): reserved and taken out at the end of that request', array( 1, 1 ), array( Reserve::total( held( $i1900 ) ), $before - alive( $store, $s221 ) - alive( $store, $s321 ) - alive( $store, $s121 ) ) );

$o1901 = fh_shop_order( new WC_Order( 1901, 'completed', array(), time() - 10 ) );
$i1901 = $o1901->add_item( 'Test Tang - Medium', 1, $tang_m, $medium );
do_action( 'woocommerce_order_status_changed', 1901, 'shipped', 'completed' );
Reserve_Shop::run_queue( $store, $settings );
check_same( 'an order that was never open after the switch-on: nothing, whatever the hook', array( array(), '' ), array( held( $i1901 ), (string) $i1901->get_meta( Reserve::META_SEEN ) ) );

Reserve_Shop::register( $off );
check_same( 'switched off again: the sweep is dropped', false, wp_next_scheduled( Reserve_Shop::CRON_HOOK ) );
\FisHotel\Misc\Sections\Clean_Room\Clean_Room::boot_disabled();
check_same( 'the section switched off drops it too (nothing armed: nothing to do)', false, wp_next_scheduled( Reserve_Shop::CRON_HOOK ) );

fh_finish();
