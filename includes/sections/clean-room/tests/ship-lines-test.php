<?php
/**
 * Today's shipping fish, read from the shop — Ship_Lines, the edge of
 * the feeding rule: it asks the readers the plugin already has (the
 * Weather section's order query, the Lost Fish section's lines, the
 * Today section's fish categories) and loads their classes itself when
 * those sections are switched off; what it read is cached and dropped on
 * every order hook; with the tick off, or without the Batch Manager, no
 * order is asked for at all. Then the whole way: an order in the shop →
 * no Feed in the room, a cancelled order → Feed is back.
 *
 * Runs on a stand-in shop (fake-shop-orders.php): invented orders, fish
 * and ids. No WordPress, no WooCommerce, no database.
 *
 * Run from the plugin root:  php includes/sections/clean-room/tests/ship-lines-test.php
 */

require_once __DIR__ . '/wp-stubs.php';
require_once __DIR__ . '/fake-shop-orders.php';

use FisHotel\Misc\Sections\Clean_Room\Feeding;
use FisHotel\Misc\Sections\Clean_Room\Front_Desk;
use FisHotel\Misc\Sections\Clean_Room\Replay;
use FisHotel\Misc\Sections\Clean_Room\Settings;
use FisHotel\Misc\Sections\Clean_Room\Ship_Lines;
use FisHotel\Misc\Sections\Clean_Room\Todo;
use FisHotel\Misc\Sections\Clean_Room\Tests\Fake_Store;

$today     = Replay::date_in_zone( time(), wp_timezone() );
$tomorrow  = Replay::add_days( $today, 1 );
$settings  = Settings::get();
$protocols = Settings::protocols();

/**
 * The Invoices section's two contract functions, as a stand-in: the
 * invoices of an order come from $GLOBALS['fh_invoices'].
 */
$GLOBALS['fh_invoices'] = array();
function fishotel_invoices_for_order( $order_id ) {
	return isset( $GLOBALS['fh_invoices'][ (int) $order_id ] ) ? $GLOBALS['fh_invoices'][ (int) $order_id ] : array();
}
function fishotel_invoices_is_adjustment( $name ) {
	return 'adjustment' === strtolower( trim( (string) $name ) );
}

function place( Fake_Store $store, $tank_number, $species, $qty, array $more = array() ) {
	global $today, $settings;
	$tank = $store->tank_by_number( $tank_number );
	$date = Replay::add_days( $today, -19 ); // Day 20: the feeding is all that is due.
	$id   = $store->create_stay( array_merge( array( 'species' => $species, 'checkin_date' => $date, 'tank_id' => (int) $tank['id'] ), $more ) );
	$store->record_event( $id, array( 'type' => 'arrival', 'qty' => $qty, 'to_tank_id' => (int) $tank['id'], 'event_at' => Front_Desk::event_at_for_date( $date, $today ) ), $today, $settings );
	return $id;
}

function block( array $list, $number ) {
	foreach ( $list['blocks'] as $block ) {
		if ( (int) $block['number'] === (int) $number ) {
			return $block;
		}
	}
	return null;
}

function by_name( array $ship ) {
	$out = array();
	foreach ( $ship['lines'] as $line ) {
		$out[ $line['name'] ] = $line;
	}
	return $out;
}

/* ------------------------------------------------------------------ */
echo "1. Nothing is asked when the rule cannot run\n";

$tang_parent = new Fh_Shop_Product( 501, 'Test Tang', array( 'quarantined-fish' ) );
$tang_medium = new Fh_Shop_Product( 502, 'Test Tang - Medium', array(), 501, array( 'pa_size' => 'medium' ) );
$salt        = new Fh_Shop_Product( 60, 'Salt mix', array( 'dry-goods' ) );
$snail       = new Fh_Shop_Product( 70, 'Test Snail', array( 'inverts' ) );
$invoice     = new Fh_Shop_Product( 80, 'Invoice for a customer', array( 'invoices' ) );

$store = new Fake_Store();
place( $store, 121, 'Test Tang', 4, array( 'product_id' => 501, 'size_slug' => 'medium', 'size_label' => 'Medium' ) );
place( $store, 221, 'Demo Wrasse', 3 );
place( $store, 521, 'Spa Goby', 5 );

$order = fh_shop_order( new WC_Order( 1234, 'processing', array( '_fishotel_shipping_date' => $tomorrow ), time() - 7200 ) );
$order->add_item( 'Test Tang - Medium', 2, $tang_medium, array( 'pa_size' => 'medium' ) );

check_same( 'this test starts without the Batch Manager', false, Ship_Lines::batch_present() );
$room = $store->room( $today, $settings, $protocols );
check_same( 'no Batch Manager: the room is as it always was — and no order was asked for', array( 3, null, 0, 0 ), array( $room['todo']['total'], $room['todo']['fast'], $GLOBALS['fh_shop_queries'], $GLOBALS['fh_shop_loads'] ) );
check_same( '… the plan says why', Feeding::STATE_NO_BATCH, Ship_Lines::fast( $today, $settings, array(), array() )['state'] );
check_same( '… and the tick in Settings would too', Feeding::STATE_NO_BATCH, Ship_Lines::state( array( Feeding::SETTING => true ) ) );
check( 'none of the other sections\' readers was loaded for it', ! class_exists( 'FisHotel\\Misc\\Sections\\Lost_Fish\\Orders', false ) && ! class_exists( 'FisHotel\\Misc\\Sections\\Today\\Collector', false ) && ! class_exists( 'FisHotel\\Misc\\Sections\\Weather\\Checker', false ) );

// The Batch Manager is there from here on.
add_filter( 'fishotel_cleanroom_batch_present', '__return_true_for_test' );
function __return_true_for_test() {
	return true;
}
check_same( 'with the Batch Manager and the shop: the rule runs', Feeding::STATE_ON, Ship_Lines::state( $settings ) );

$off  = array_merge( $settings, array( Feeding::SETTING => false ) );
$room = $store->room( $today, $off, $protocols );
check_same( 'the tick off: Feed everywhere, and still no order asked for', array( 3, null, 0, 0, Feeding::STATE_OFF ), array( $room['todo']['total'], $room['todo']['fast'], $GLOBALS['fh_shop_queries'], $GLOBALS['fh_shop_loads'], Ship_Lines::fast( $today, $off, array(), array() )['state'] ) );
check( '… nor is a reader loaded or anything cached', ! class_exists( 'FisHotel\\Misc\\Sections\\Lost_Fish\\Orders', false ) && false === get_transient( Ship_Lines::TRANSIENT ) );

/* ------------------------------------------------------------------ */
echo "2. The readers of the switched-off sections\n";

$hooks_before = array( count( $GLOBALS['fh_actions'], COUNT_RECURSIVE ), count( $GLOBALS['fh_filters'], COUNT_RECURSIVE ), count( $GLOBALS['fh_options'] ), count( $GLOBALS['fh_transients'] ) );
check_same( 'the six classes it asks are loaded on demand', true, Ship_Lines::load_readers() );
check( '… the Weather section\'s query, the Lost Fish section\'s lines, the Today section\'s fish categories', class_exists( 'FisHotel\\Misc\\Sections\\Weather\\Checker', false ) && class_exists( 'FisHotel\\Misc\\Sections\\Lost_Fish\\Orders', false ) && class_exists( 'FisHotel\\Misc\\Sections\\Today\\Collector', false ) );
check_same( '… loading them hooks nothing, stores nothing', $hooks_before, array( count( $GLOBALS['fh_actions'], COUNT_RECURSIVE ), count( $GLOBALS['fh_filters'], COUNT_RECURSIVE ), count( $GLOBALS['fh_options'] ), count( $GLOBALS['fh_transients'] ) ) );
check( '… and none of the three sections is thereby "on": their own classes are not there', ! class_exists( 'FisHotel\\Misc\\Sections\\Lost_Fish\\Lost_Fish', false ) && ! class_exists( 'FisHotel\\Misc\\Sections\\Today\\Today', false ) && ! class_exists( 'FisHotel\\Misc\\Sections\\Weather\\Weather', false ) );
foreach ( Ship_Lines::READERS as $class => $file ) {
	check( basename( $file ) . ' is where it is expected', file_exists( FISHOTEL_MISC_PATH . 'includes/sections/' . $file ) && class_exists( $class, false ) );
}
check_same( 'a second call loads nothing twice', true, Ship_Lines::load_readers() );

/* ------------------------------------------------------------------ */
echo "3. What it reads from the shop\n";

fh_shop_reset();
Ship_Lines::flush();

// 1234 — an ordinary order: a sized fish, dry goods, a fish that is not coming, a refunded one.
$order = fh_shop_order( new WC_Order( 1234, 'processing', array( '_fishotel_shipping_date' => $tomorrow ), 1700000000 ) );
$order->add_item( 'Test Tang - Medium', 2, $tang_medium, array( 'pa_size' => 'medium' ) );
$order->add_item( 'Salt mix', 3, $salt );
$order->add_item( 'Not Coming Goby', 1, new Fh_Shop_Product( 91, 'Not Coming Goby', array( 'quarantined-fish' ) ), array( '_fishotel_line_status' => 'na' ) );
$order->add_item( 'Refunded Chromis', 2, new Fh_Shop_Product( 92, 'Refunded Chromis', array( 'quarantined-fish' ) ), array(), 2 );
$order->add_item( 'Half Refunded Chromis', 3, new Fh_Shop_Product( 93, 'Half Refunded Chromis', array( 'quarantined-fish' ) ), array(), 1 );

// 1240 — on hold, an invoice's fish as lines of their own, with the invoice's "Adjustment".
$order = fh_shop_order( new WC_Order( 1240, 'on-hold', array( '_fishotel_shipping_date' => $tomorrow ) ) );
$order->add_item( 'Sample Angel', 1, null, array( '_fh_invoice_fish' => '1', '_fh_invoice_product_id' => 80 ) );
$order->add_item( 'Adjustment', 1, null, array( '_fh_invoice_fish' => '1', '_fh_invoice_product_id' => 80 ) );

// 1245 — already marked shipped: an invert.
$order = fh_shop_order( new WC_Order( 1245, 'shipped', array( '_fishotel_shipping_date' => $tomorrow ) ) );
$order->add_item( 'Test Snail', 4, $snail );

// 1300 — goes out another day. 1301 — delivered long ago. 1302 — no date.
$order = fh_shop_order( new WC_Order( 1300, 'processing', array( '_fishotel_shipping_date' => Replay::add_days( $today, 2 ) ) ) );
$order->add_item( 'Later Wrasse', 1, new Fh_Shop_Product( 94, 'Later Wrasse', array( 'quarantined-fish' ) ) );
$order = fh_shop_order( new WC_Order( 1301, 'completed', array( '_fishotel_shipping_date' => $tomorrow ) ) );
$order->add_item( 'Delivered Wrasse', 1, new Fh_Shop_Product( 95, 'Delivered Wrasse', array( 'quarantined-fish' ) ) );
$order = fh_shop_order( new WC_Order( 1302, 'processing' ) );
$order->add_item( 'Undated Wrasse', 1, new Fh_Shop_Product( 96, 'Undated Wrasse', array( 'quarantined-fish' ) ) );

// 1400 — a combined box of 1401 (live) and 1402 (cancelled); its own lines are copies.
$order = fh_shop_order( new WC_Order( 1400, 'fulfillment', array( '_fishotel_shipping_date' => $tomorrow, '_fishotel_is_fulfillment' => '1', '_fishotel_fulfillment_sources' => '1401, 1402, 1499' ) ) );
$order->add_item( 'Boxed Blenny', 1, new Fh_Shop_Product( 97, 'Boxed Blenny', array( 'quarantined-fish' ) ) );
$order->add_item( 'Cancelled Blenny', 1, new Fh_Shop_Product( 98, 'Cancelled Blenny', array( 'quarantined-fish' ) ) );
$order = fh_shop_order( new WC_Order( 1401, 'processing', array( '_fishotel_fulfilled_by_order' => '1400' ) ) );
$order->add_item( 'Boxed Blenny', 1, new Fh_Shop_Product( 97, 'Boxed Blenny', array( 'quarantined-fish' ) ) );
$order = fh_shop_order( new WC_Order( 1402, 'cancelled', array( '_fishotel_fulfilled_by_order' => '1400' ) ) );
$order->add_item( 'Cancelled Blenny', 1, new Fh_Shop_Product( 98, 'Cancelled Blenny', array( 'quarantined-fish' ) ) );

// 1500 — an invoice kept as ONE line: its fish are in the line's snapshot.
$order = fh_shop_order( new WC_Order( 1500, 'processing', array( '_fishotel_shipping_date' => $tomorrow ) ) );
$order->add_item( 'Invoice for a customer', 1, $invoice, array( '_fh_invoice_lines' => wp_json_encode( array( array( 'name' => 'Snapshot Tang', 'qty' => 2, 'price' => 40, 'kind' => 'fish' ), array( 'name' => 'Credit: a lost fish', 'qty' => 1, 'price' => -20, 'kind' => 'credit' ), array( 'name' => 'Adjustment', 'qty' => 1, 'price' => 5, 'kind' => 'fish' ) ) ) ) );

// 1501 — the same, but the whole invoice is marked "not available": none of its fish ships.
$order = fh_shop_order( new WC_Order( 1501, 'processing', array( '_fishotel_shipping_date' => $tomorrow ) ) );
$order->add_item( 'Invoice for a customer', 1, $invoice, array( '_fishotel_line_status' => 'na', '_fh_invoice_lines' => wp_json_encode( array( array( 'name' => 'Called Off Tang', 'qty' => 1, 'price' => 40, 'kind' => 'fish' ) ) ) ) );

// 1600 — a fish the Invoices section tied to a tank group.
$order                         = fh_shop_order( new WC_Order( 1600, 'processing', array( '_fishotel_shipping_date' => $tomorrow ) ) );
$linked                        = $order->add_item( 'Linked Wrasse', 1, null, array( '_fh_invoice_fish' => '1', '_fh_invoice_product_id' => 81 ) );
$GLOBALS['fh_invoices'][1600] = array(
	array(
		'id'         => 7,
		'product_id' => 81,
		'lines'      => array(
			array( 'id' => 70, 'kind' => 'fish', 'name' => 'Linked Wrasse', 'qty' => 1, 'unit_price' => 30.0, 'stay_id' => 4321, 'size_slug' => 'large', 'order_item_id' => $linked->get_id() ),
			array( 'id' => 71, 'kind' => 'discount', 'name' => 'Adjustment', 'qty' => 1, 'unit_price' => 5.0 ),
		),
	),
);

$ship  = Ship_Lines::read( $today );
$lines = by_name( $ship );
check_same( 'the fish that go out today — and nothing else', array( 'Test Tang - Medium', 'Half Refunded Chromis', 'Sample Angel', 'Test Snail', 'Boxed Blenny', 'Snapshot Tang', 'Linked Wrasse' ), array_column( $ship['lines'], 'name' ) );
check_same( '… counted: 6 orders, 13 fish, for today', array( $today, 6, 13 ), array( $ship['date'], $ship['orders'], $ship['fish'] ) );
check_same( 'one query for the date; the orders inside the box are loaded by id', array( 1, 3 ), array( $GLOBALS['fh_shop_queries'], $GLOBALS['fh_shop_loads'] ) );
check_same( 'a shop fish: its product and variation, its size as it was bought, its own title, its order', array( 'order_id' => 1234, 'number' => '1234', 'created' => 1700000000, 'qty' => 2, 'product_id' => 501, 'variation_id' => 502, 'size_slug' => 'medium', 'names' => array( 'Test Tang' ) ), array_intersect_key( $lines['Test Tang - Medium'], array( 'order_id' => 1, 'number' => 1, 'created' => 1, 'qty' => 1, 'product_id' => 1, 'variation_id' => 1, 'size_slug' => 1, 'names' => 1 ) ) );
check_same( 'a refund is netted', 2, $lines['Half Refunded Chromis']['qty'] );
check_same( 'the fish of the box come from the order in it, under that order\'s number', array( '1401', 1 ), array( $lines['Boxed Blenny']['number'], $lines['Boxed Blenny']['qty'] ) );
check_same( 'an invoice kept as one line is split into its fish — without its credit, without its "Adjustment"', array( '1500', 2, 0, '' ), array( $lines['Snapshot Tang']['number'], $lines['Snapshot Tang']['qty'], $lines['Snapshot Tang']['product_id'], $lines['Snapshot Tang']['size_slug'] ) );
check_same( '… such a fish is never called by the invoice\'s own title; an invoice marked "not available" ships none of its fish', array( array(), false ), array( $lines['Snapshot Tang']['names'], isset( $lines['Called Off Tang'] ) ) );
check_same( 'the Invoices section\'s link to a tank group rides along', array( 4321, 'large' ), array( $lines['Linked Wrasse']['link']['stay_id'], $lines['Linked Wrasse']['link']['size_slug'] ) );
check( 'the dry goods, the fish that is not coming, the fully refunded one, the "Adjustment", the cancelled order, the box\'s own copies, other days: none of them', ! isset( $lines['Salt mix'] ) && ! isset( $lines['Not Coming Goby'] ) && ! isset( $lines['Refunded Chromis'] ) && ! isset( $lines['Adjustment'] ) && ! isset( $lines['Cancelled Blenny'] ) && ! isset( $lines['Later Wrasse'] ) && ! isset( $lines['Delivered Wrasse'] ) && ! isset( $lines['Undated Wrasse'] ) && 1 === count( array_keys( array_column( $ship['lines'], 'name' ), 'Boxed Blenny', true ) ) );

$size = Ship_Lines::item_facts( new Fh_Shop_Item( 1, 'Test Tang - Medium', 1, $tang_medium ) );
check_same( 'a line that kept no size of its own: what the variation says', array( 'medium', array( 'quarantined-fish' ), 'Test Tang' ), array( $size['size_slug'], $size['categories'], $size['title'] ) );
$size = Ship_Lines::item_facts( new Fh_Shop_Item( 1, 'Test Tang - Medium', 1, $tang_medium, array( 'pa_size' => 'large', '_reduced_stock' => '1', '_size_note' => 'x' ) ) );
check_same( '… the size the line was bought as wins; a hidden meta key is never a size', 'large', $size['size_slug'] );
check_same( 'a product deleted since: no categories, no size — no fish by category', array( array(), '', '' ), array_values( array_intersect_key( Ship_Lines::item_facts( new Fh_Shop_Item( 1, 'Gone', 1, null ) ), array( 'categories' => 1, 'title' => 1, 'size_slug' => 1 ) ) ) );
check_same( 'anything that is no order is no order', array( null, null, null ), array( Ship_Lines::head( null ), Ship_Lines::head( new stdClass() ), Ship_Lines::head( new WC_Order( 0 ) ) ) );
$head = Ship_Lines::head( $GLOBALS['fh_shop_orders'][1400] );
check_same( 'a combined box: marked, with the orders in it', array( true, false, array( 1401, 1402, 1499 ), 'fulfillment', $tomorrow ), array( $head['wrapper'], $head['folded'], $head['sources'], $head['status'], $head['delivery'] ) );
$head = Ship_Lines::head( $GLOBALS['fh_shop_orders'][1401] );
check_same( 'an order inside it: folded, no date of its own', array( false, true, '' ), array( $head['wrapper'], $head['folded'], $head['delivery'] ) );

/* ------------------------------------------------------------------ */
echo "4. Cached, and dropped when an order changes\n";

fh_shop_reset();
Ship_Lines::flush();
$order = fh_shop_order( new WC_Order( 1234, 'processing', array( '_fishotel_shipping_date' => $tomorrow ), time() - 7200 ) );
$order->add_item( 'Test Tang - Medium', 2, $tang_medium, array( 'pa_size' => 'medium' ) );

$first = Ship_Lines::today( $today );
check_same( 'the first ask reads the shop: one query', array( 1, 2 ), array( $GLOBALS['fh_shop_queries'], $first['fish'] ) );
check_same( '… and keeps it for about five minutes', array( 300, $today ), array( $GLOBALS['fh_transients'][ Ship_Lines::TRANSIENT ][1], get_transient( Ship_Lines::TRANSIENT )['date'] ) );
Ship_Lines::today( $today );
Ship_Lines::today( $today );
$store->room( $today, $settings, $protocols );
check_same( 'every later ask — the Board, the phone, the Today page — costs no query', 1, $GLOBALS['fh_shop_queries'] );
Ship_Lines::today( $tomorrow );
check_same( 'past midnight the kept answer is yesterday\'s: the shop is read again', array( 2, $tomorrow ), array( $GLOBALS['fh_shop_queries'], get_transient( Ship_Lines::TRANSIENT )['date'] ) );

check_same( 'nothing is hooked until the section boots', array(), array_values( array_intersect( Ship_Lines::HOOKS, array_keys( $GLOBALS['fh_actions'] ) ) ) );
Ship_Lines::register();
check_same( 'the order hooks the Today section listens to, and a new order', array( 'woocommerce_order_status_changed', 'woocommerce_update_order', 'woocommerce_order_note_added', 'woocommerce_new_order' ), Ship_Lines::HOOKS );
foreach ( Ship_Lines::HOOKS as $hook ) {
	Ship_Lines::today( $today );
	$before = $GLOBALS['fh_shop_queries'];
	Ship_Lines::today( $today );
	$kept = $before === $GLOBALS['fh_shop_queries'] && false !== get_transient( Ship_Lines::TRANSIENT );
	do_action( $hook, 1234, 'processing', 'cancelled' );
	$dropped = false === get_transient( Ship_Lines::TRANSIENT );
	Ship_Lines::today( $today );
	check( $hook . ' drops it: the next ask reads the shop again', $kept && $dropped && $before + 1 === $GLOBALS['fh_shop_queries'] );
}

/* ------------------------------------------------------------------ */
echo "5. The whole way: an order in the shop, the to-do in the room\n";

fh_shop_reset();
Ship_Lines::flush();
$room = $store->room( $today, $settings, $protocols );
check_same( 'no order: Feed in every system', array( 3, 3, null ), array( $room['todo']['left'], $room['todo']['total'], $room['todo']['fast'] ) );

$order = fh_shop_order( new WC_Order( 1234, 'processing', array( '_fishotel_shipping_date' => $tomorrow ), time() - 7200 ) );
$order->add_item( 'Test Tang - Medium', 2, $tang_medium, array( 'pa_size' => 'medium' ) );
$room  = $store->room( $today, $settings, $protocols );
check_same( 'the order is in the shop but nothing has said so yet: the kept answer stands', 3, $room['todo']['total'] );
do_action( 'woocommerce_order_status_changed', 1234, 'pending', 'processing' );
$room = $store->room( $today, $settings, $protocols );
check_same( 'the order comes in: on the next page load building 100 has no Feed, and says why', array( 2, array(), 'Building 100 — no feeding today. 2 fish ship from this system: 2 × Test Tang - Medium (tank 121, #1234).', 'no feeding in Building 100 (ship day)' ), array( $room['todo']['total'], block( $room['todo'], 100 )['items'], block( $room['todo'], 100 )['fast']['text'], $room['todo']['fast']['words'] ) );
check_same( '… building 200 and the Spa are fed', array( 1, 1 ), array( count( block( $room['todo'], 200 )['items'] ), count( block( $room['todo'], 500 )['items'] ) ) );

$summary = Todo::summary( $room['todo'], 'u' );
check_same( '… the Today page\'s line', array( 'Tanks: 2 things to do', 'no feeding in Building 100 (ship day)' ), array( Todo::today_line( $summary )['text'], Todo::today_line( $summary )['detail'] ) );
$screen = Front_Desk::screen_from( $store, 121, $settings, $today );
check_same( '… the phone, on tank 121', array( array(), 'No feeding today — fish ship from here.' ), array( $screen['tank']['todo'], $screen['tank']['fast']['strip'] ) );

$order->set_status( 'shipped' );
do_action( 'woocommerce_order_status_changed', 1234, 'processing', 'shipped' );
$room = $store->room( $today, $settings, $protocols );
check_same( 'tracking is added and the order turns "shipped": Feed does not come back', array( 2, array() ), array( $room['todo']['total'], block( $room['todo'], 100 )['items'] ) );

$asked = 0;
$gone  = static function () use ( &$asked ) {
	$asked++;
	return array();
};
Ship_Lines::fast( $today, $settings, array(), array(), $gone );
check_same( 'on a day fish ship the day\'s closed groups are asked for', 1, $asked );

$order->update_meta_data( '_fishotel_shipping_date', Replay::add_days( $today, 4 ) );
do_action( 'woocommerce_update_order', 1234 );
$room = $store->room( $today, $settings, $protocols );
check_same( 'the delivery date is moved to another day: Feed is back', array( 3, null ), array( $room['todo']['total'], $room['todo']['fast'] ) );

$order->update_meta_data( '_fishotel_shipping_date', $tomorrow );
$order->set_status( 'processing' );
do_action( 'woocommerce_update_order', 1234 );
check_same( 'moved back: no Feed again', 2, $store->room( $today, $settings, $protocols )['todo']['total'] );
$order->set_status( 'cancelled' );
do_action( 'woocommerce_order_status_changed', 1234, 'processing', 'cancelled' );
$room = $store->room( $today, $settings, $protocols );
check_same( 'the order is cancelled: Feed is back', array( 3, null ), array( $room['todo']['total'], $room['todo']['fast'] ) );
Ship_Lines::fast( $today, $settings, array(), array(), $gone );
check_same( '… and on a day none ship, they are not', 1, $asked );

$other = fh_shop_order( new WC_Order( 1251, 'processing', array( '_fishotel_shipping_date' => $tomorrow ) ) );
$other->add_item( 'Unlogged Clownfish', 2, new Fh_Shop_Product( 99, 'Unlogged Clownfish', array( 'quarantined-fish' ) ) );
do_action( 'woocommerce_new_order', 1251 );
$room = $store->room( $today, $settings, $protocols );
check_same( 'a new order for a fish the site cannot find: no clean system is fed, and the list says which fish', array( 1, 'No feeding in the clean tanks today — 2 fish ship and the site doesn\'t know their tank: 2 × Unlogged Clownfish (#1251).' ), array( $room['todo']['total'], $room['todo']['fast']['text'] ) );

$queries = $GLOBALS['fh_shop_queries'];
$room    = $store->room( $today, array_merge( $settings, array( Feeding::SETTING => false ) ), $protocols );
check_same( 'the tick switched off: Feed everywhere, at once, without asking the shop', array( 3, null, $queries ), array( $room['todo']['total'], $room['todo']['fast'], $GLOBALS['fh_shop_queries'] ) );

fh_finish();
