<?php
/**
 * A clean system is not fed on the day its fish ship — the rule
 * (Feeding), pure: which orders go out today (the dates around midnight
 * in the site's timezone, each status, a combined box and the orders in
 * it, a cancelled one), which lines are fish, which water each fish is
 * in, the fasting set (one system, two, all of them because one fish is
 * in no tank the site knows, none; the tick off; the Batch Manager
 * absent), and what the to-do then shows: no Feed, the reason in its
 * place — never a line to tick, never counted —, a feed ticked before
 * the order came in, the phone's strip, the Board, the Today line.
 *
 * Invented fish, orders and ids. No WordPress, no WooCommerce.
 *
 * Run from the plugin root:  php includes/sections/clean-room/tests/feeding-test.php
 */

require_once __DIR__ . '/wp-stubs.php';

use FisHotel\Misc\Sections\Clean_Room\Admin;
use FisHotel\Misc\Sections\Clean_Room\Admin_Temp;
use FisHotel\Misc\Sections\Clean_Room\Dose;
use FisHotel\Misc\Sections\Clean_Room\Feeding;
use FisHotel\Misc\Sections\Clean_Room\Front_Desk;
use FisHotel\Misc\Sections\Clean_Room\Layout;
use FisHotel\Misc\Sections\Clean_Room\Replay;
use FisHotel\Misc\Sections\Clean_Room\Settings;
use FisHotel\Misc\Sections\Clean_Room\Ship_Lines;
use FisHotel\Misc\Sections\Clean_Room\Sizes;
use FisHotel\Misc\Sections\Clean_Room\Todo;
use FisHotel\Misc\Sections\Clean_Room\Tests\Fake_Store;

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
foreach ( array( 'selected', 'checked', 'disabled' ) as $fh_word ) {
	if ( ! function_exists( $fh_word ) ) {
		eval( 'function ' . $fh_word . '( $a, $b = true, $echo = true ) { if ( (string) $a === (string) $b ) { echo " ' . $fh_word . '=\"' . $fh_word . '\""; } }' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- three one-line template stubs.
	}
}

$today     = Replay::date_in_zone( time(), wp_timezone() ); // The ticks stamp logs with the real clock.
$tomorrow  = Replay::add_days( $today, 1 );
$settings  = Settings::get();
$protocols = Settings::protocols();
$cats      = array( 'quarantined-fish', 'inverts' );
$base      = array(
	'actor_id' => 8,
	'source'   => 'qr',
);

/**
 * An order as Ship_Lines hands it to the rule: going out today unless
 * told otherwise.
 */
function order( $id, array $rows = array(), array $more = array() ) {
	global $tomorrow;
	return array_merge(
		array(
			'id'       => $id,
			'number'   => (string) $id,
			'status'   => 'processing',
			'delivery' => $tomorrow,
			'wrapper'  => false,
			'folded'   => false,
			'sources'  => array(),
			'created'  => 0,
			'rows'     => $rows,
		),
		$more
	);
}

/**
 * One line of an order: a fish from the shop unless told otherwise.
 */
function row( $name, $qty, array $more = array() ) {
	return array_merge(
		array(
			'type'         => 'product',
			'name'         => $name,
			'qty'          => $qty,
			'qty_refunded' => 0,
			'product_id'   => 0,
			'variation_id' => 0,
			'categories'   => array( 'quarantined-fish' ),
			'line_status'  => '',
			'size_slug'    => '',
			'size_label'   => '',
			'title'        => '',
			'link'         => array(),
		),
		$more
	);
}

/**
 * What ships today, from a list of orders.
 */
function ship( array $orders ) {
	global $today, $cats;
	$by_id = array();
	foreach ( $orders as $order ) {
		$by_id[ $order['id'] ] = $order;
	}
	return Feeding::shipping( Feeding::going_out( $by_id, $today ), $today, $cats );
}

/**
 * A group put straight into any tank, on day 20 of its stay: past every
 * step of the Spa's schedule, so the feeding is all that is due anywhere.
 */
function place( Fake_Store $store, $tank_number, $species, $qty, array $more = array() ) {
	global $today, $settings;
	$tank = $store->tank_by_number( $tank_number );
	$date = Replay::add_days( $today, -19 );
	$id   = $store->create_stay( array_merge( array( 'species' => $species, 'checkin_date' => $date, 'tank_id' => (int) $tank['id'] ), $more ) );
	$store->record_event( $id, array( 'type' => 'arrival', 'qty' => $qty, 'to_tank_id' => (int) $tank['id'], 'event_at' => Front_Desk::event_at_for_date( $date, $today ) ), $today, $settings );
	return $id;
}

function room( Fake_Store $store ) {
	global $today, $settings, $protocols;
	return $store->room( $today, $settings, $protocols );
}

function plan( Fake_Store $store, array $ship ) {
	global $today, $settings;
	return Feeding::plan( $ship, $store->open_stays( array(), $today, $settings ), $store->tanks() );
}

function block( array $list, $number ) {
	foreach ( $list['blocks'] as $block ) {
		if ( (int) $block['number'] === (int) $number ) {
			return $block;
		}
	}
	return null;
}

function tank_of( array $room, $number ) {
	foreach ( $room['tanks'] as $tank ) {
		if ( (int) $tank['number'] === (int) $number ) {
			return $tank;
		}
	}
	return null;
}

function kinds( array $items ) {
	return array_values( array_map( 'strval', array_column( $items, 'kind' ) ) );
}

/**
 * A temporary tank set up through the phone's own act.
 */
function set_up( Fake_Store $store, array $raw ) {
	global $settings, $today, $base;
	$GLOBALS['fh_user_caps'][ \FisHotel\Misc\Sections\Clean_Room\Clean_Room::CAPABILITY ] = array( 8 );
	$GLOBALS['fh_current_user']                                                           = 8;
	return Front_Desk::perform(
		'setup',
		array(
			'store'    => $store,
			'settings' => $settings,
			'today'    => $today,
			'tank'     => null,
			'base'     => $base,
		),
		$raw
	);
}

/* ------------------------------------------------------------------ */
echo "1. Today is the site's day: around midnight\n";

$tz = new DateTimeZone( 'America/Chicago' );
$at = static function ( $local ) use ( $tz ) {
	return ( new DateTimeImmutable( $local, $tz ) )->getTimestamp();
};
$box = array( 1 => order( 1, array( row( 'Test Tang', 1 ) ), array( 'delivery' => '2026-11-25' ) ) );

check_same( 'a box arrives the day after it ships', '2026-11-25', Feeding::delivery_for( '2026-11-24' ) );
check_same( '… over a month end, a year end and a leap day', array( '2026-12-01', '2027-01-01', '2028-02-29', '2028-03-01' ), array( Feeding::delivery_for( '2026-11-30' ), Feeding::delivery_for( '2026-12-31' ), Feeding::delivery_for( '2028-02-28' ), Feeding::delivery_for( '2028-02-29' ) ) );
check_same( 'no date, no delivery', array( '', '' ), array( Feeding::delivery_for( '' ), Feeding::delivery_for( '2026-02-30' ) ) );

$late  = Replay::date_in_zone( $at( '2026-11-24 23:59:00' ), $tz );
$early = Replay::date_in_zone( $at( '2026-11-24 00:01:00' ), $tz );
$next  = Replay::date_in_zone( $at( '2026-11-25 00:01:00' ), $tz );
$prev  = Replay::date_in_zone( $at( '2026-11-23 23:59:00' ), $tz );
check_same( 'one minute past midnight on the ship day: it goes out today', array( 1 ), array_keys( Feeding::going_out( $box, $early ) ) );
check_same( 'one minute before the next midnight: still today — the fast lasts the whole site day', array( 1 ), array_keys( Feeding::going_out( $box, $late ) ) );
check_same( 'one minute past that midnight: the box left yesterday', array(), array_keys( Feeding::going_out( $box, $next ) ) );
check_same( 'the evening before (already the 24th in UTC, still the 23rd on site): not yet', array( '2026-11-23', array() ), array( $prev, array_keys( Feeding::going_out( $box, $prev ) ) ) );
check_same( 'an order with no delivery date never ships "today"', array(), array_keys( Feeding::going_out( array( 2 => order( 2, array(), array( 'delivery' => '' ) ) ), '2026-11-24' ) ) );

/* ------------------------------------------------------------------ */
echo "2. Each status\n";

foreach ( array( 'processing', 'on-hold', 'shipped', 'wc-processing', 'wc-on-hold', 'wc-shipped' ) as $status ) {
	check_same( $status . ': goes out', array( 7 ), array_keys( Feeding::going_out( array( 7 => order( 7, array(), array( 'status' => $status ) ) ), $today ) ) );
}
foreach ( array( 'pending', 'completed', 'cancelled', 'refunded', 'failed', 'checkout-draft', 'trash', '' ) as $status ) {
	check_same( '"' . $status . '": does not', array(), array_keys( Feeding::going_out( array( 7 => order( 7, array(), array( 'status' => $status ) ) ), $today ) ) );
}
check_same( 'an order already marked shipped keeps the fast on: Feed does not come back when tracking is added', 2, ship( array( order( 7, array( row( 'Test Tang', 2 ) ), array( 'status' => 'shipped' ) ) ) )['fish'] );
check_same( 'the statuses can be told from outside', array( 7 ), array_keys( Feeding::going_out( array( 7 => order( 7, array(), array( 'status' => 'packing' ) ) ), $today, array( 'wc-packing' ) ) ) );

/* ------------------------------------------------------------------ */
echo "3. A combined box and the orders in it\n";

$orders = array(
	900 => order( 900, array( row( 'A copy of the lines', 9 ) ), array( 'status' => 'fulfillment', 'wrapper' => true, 'sources' => array( 901, 902, 903, 904, 900 ) ) ),
	901 => order( 901, array( row( 'Test Tang', 2 ) ), array( 'folded' => true, 'delivery' => '' ) ),
	902 => order( 902, array( row( 'Sample Angel', 1 ) ), array( 'folded' => true, 'status' => 'cancelled' ) ),
	903 => order( 903, array( row( 'Demo Wrasse', 1 ) ), array( 'folded' => true, 'status' => 'shipped', 'delivery' => '2020-01-01' ) ),
);
$out    = Feeding::going_out( $orders, $today );
check_same( 'the box is read through the orders in it — the live ones; never by its own copied lines', array( 901, 903 ), array_keys( $out ) );
check_same( 'a cancelled order in the box ships nothing; one that is not loaded is skipped', 3, Feeding::shipping( $out, $today, $cats )['fish'] );

$orders[900]['status'] = 'shipped';
check_same( 'a box already marked shipped: its orders still count', array( 901, 903 ), array_keys( Feeding::going_out( $orders, $today ) ) );
$orders[900]['delivery'] = Replay::add_days( $today, 3 );
$orders[901]['delivery'] = $tomorrow;
check_same( 'an order folded into a box follows the box\'s date, not its own', array(), array_keys( Feeding::going_out( $orders, $today ) ) );
$orders[900]['delivery'] = $tomorrow;
$orders[900]['status']   = 'cancelled';
check_same( 'a cancelled box ships nothing', array(), array_keys( Feeding::going_out( $orders, $today ) ) );
foreach ( array( 'refunded', 'failed', 'trash' ) as $status ) {
	$orders[900]['status'] = 'fulfillment';
	$orders[901]['status'] = $status;
	check_same( 'a ' . $status . ' order in the box is left out', array( 903 ), array_keys( Feeding::going_out( $orders, $today ) ) );
}
$nested = array(
	900 => order( 900, array(), array( 'status' => 'fulfillment', 'wrapper' => true, 'sources' => array( 950, 901 ) ) ),
	950 => order( 950, array(), array( 'status' => 'fulfillment', 'wrapper' => true, 'sources' => array( 900 ) ) ),
	901 => order( 901, array( row( 'Test Tang', 1 ) ), array( 'folded' => true ) ),
	905 => order( 905, array( row( 'Mock Blenny', 1 ) ) ),
);
check_same( 'a box inside a box is not read as an order; an order on its own beside them is', array( 901, 905 ), array_keys( Feeding::going_out( $nested, $today ) ) );
$orphan = array( 900 => order( 900, array( row( 'A copy of a Tang', 2 ) ), array( 'status' => 'fulfillment', 'wrapper' => true, 'sources' => array( 4040, 4041 ) ) ) );
check_same( 'a box none of whose orders can be read: its own copied lines are all there is — better no feed than a fed fish in a bag', array( array( 900 ), 2 ), array( array_keys( Feeding::going_out( $orphan, $today ) ), Feeding::shipping( Feeding::going_out( $orphan, $today ), $today, $cats )['fish'] ) );
$dead = array( 900 => order( 900, array( row( 'A copy of a Tang', 2 ) ), array( 'status' => 'fulfillment', 'wrapper' => true, 'sources' => array( 901 ) ) ), 901 => order( 901, array( row( 'Test Tang', 2 ) ), array( 'folded' => true, 'status' => 'cancelled' ) ) );
check_same( '… but a box whose every order is cancelled ships nothing', array(), array_keys( Feeding::going_out( $dead, $today ) ) );

/* ------------------------------------------------------------------ */
echo "4. Which lines are fish\n";

$lines = Feeding::fish_lines(
	order(
		1234,
		array(
			row( 'Test Tang - Medium', 2, array( 'product_id' => 501, 'variation_id' => 502, 'size_slug' => 'medium', 'size_label' => 'medium', 'title' => 'Test Tang' ) ),
			row( 'Salt mix', 1, array( 'product_id' => 60, 'categories' => array( 'dry-goods' ) ) ),
			row( 'Test Snail', 3, array( 'categories' => array( 'supplies', 'inverts' ) ) ),
			row( 'A product without a category', 1, array( 'categories' => array() ) ),
			row( 'Invoice fish', 1, array( 'type' => 'invoice_fish', 'categories' => array() ) ),
			row( 'Fish of an invoice kept whole', 2, array( 'type' => 'invoice_line', 'categories' => array() ) ),
			row( 'Fish the Invoices section lists', 1, array( 'type' => 'invoice', 'categories' => array(), 'link' => array( 'stay_id' => 12 ) ) ),
			row( 'Something else', 1, array( 'type' => 'fee' ) ),
			row( 'Refunded Tang', 2, array( 'qty_refunded' => 2 ) ),
			row( 'Half refunded Tang', 3, array( 'qty_refunded' => -1 ) ),
			row( 'Tang that is not coming', 1, array( 'line_status' => 'na' ) ),
			row( 'Tang already in the box', 1, array( 'line_status' => 'shipped' ) ),
			row( 'Nothing of it', 0 ),
			'not a row',
		),
		array( 'created' => 1700000000 )
	),
	$cats
);
check_same( 'a product in a fish category, a fish out of an invoice in each shape — and nothing else', array( 'Test Tang - Medium', 'Test Snail', 'Invoice fish', 'Fish of an invoice kept whole', 'Fish the Invoices section lists', 'Half refunded Tang', 'Tang already in the box' ), array_column( $lines, 'name' ) );
check_same( 'a refund is taken off; a line marked "not available" and a fully refunded one are no fish to bag', array( 2, 3, 1, 2, 1, 2, 1 ), array_column( $lines, 'qty' ) );
check_same( 'a line carries its order, its product, its size and what the Invoices section linked', array( 'order_id' => 1234, 'number' => '1234', 'created' => 1700000000, 'name' => 'Test Tang - Medium', 'qty' => 2, 'product_id' => 501, 'variation_id' => 502, 'size_slug' => 'medium', 'size_label' => 'medium', 'names' => array( 'Test Tang' ), 'link' => array(), 'loose' => false ), $lines[0] );
check_same( '… the link', array( 'stay_id' => 12 ), $lines[4]['link'] );
check_same( 'with no fish category at all, only invoice fish are fish', array( 'Invoice fish', 'Fish of an invoice kept whole', 'Fish the Invoices section lists' ), array_column( Feeding::fish_lines( order( 1, array( row( 'Test Tang', 1 ), row( 'Invoice fish', 1, array( 'type' => 'invoice_fish' ) ), row( 'Fish of an invoice kept whole', 1, array( 'type' => 'invoice_line' ) ), row( 'Fish the Invoices section lists', 1, array( 'type' => 'invoice' ) ) ) ), array() ), 'name' ) );

$shipping = ship( array( order( 1234, array( row( 'Test Tang', 2 ), row( 'Salt mix', 4, array( 'categories' => array( 'dry-goods' ) ) ) ) ), order( 1240, array( row( 'Sample Angel', 1 ) ) ), order( 1241, array( row( 'Salt mix', 1, array( 'categories' => array( 'dry-goods' ) ) ) ) ), order( 1242, array( row( 'Demo Wrasse', 5 ) ), array( 'delivery' => Replay::add_days( $today, 2 ) ) ) ) );
check_same( 'what ships today: the fish of today\'s orders, counted — an order of dry goods is not one of them', array( 'date' => $today, 'orders' => 2, 'fish' => 3 ), array( 'date' => $shipping['date'], 'orders' => $shipping['orders'], 'fish' => $shipping['fish'] ) );

/* ------------------------------------------------------------------ */
echo "5. Which water each fish is in: the fasting set\n";

$store = new Fake_Store();
$tang  = place( $store, 121, 'Test Tang', 4, array( 'product_id' => 501, 'size_slug' => 'medium', 'size_label' => 'Medium' ) );
$angel = place( $store, 111, 'Sample Angel', 2 );
$wras  = place( $store, 221, 'Demo Wrasse', 3, array( 'product_id' => 777 ) );
$wras2 = place( $store, 312, 'Demo Wrasse', 1, array( 'product_id' => 777 ) );
$goby  = place( $store, 521, 'Spa Goby', 5 );
$chrom = place( $store, 321, 'Both Chromis', 2 );
$chro2 = place( $store, 522, 'Both Chromis', 2 );

$none = plan( $store, ship( array() ) );
check_same( 'no order goes out: nobody fasts', array( 'state' => 'on', 'buildings' => array(), 'tanks' => array(), 'all' => false, 'on' => false ), array( 'state' => $none['state'], 'buildings' => $none['buildings'], 'tanks' => $none['tanks'], 'all' => $none['all'], 'on' => Feeding::is_on( $none ) ) );

$one = plan( $store, ship( array( order( 1234, array( row( 'Test Tang - Medium', 2, array( 'product_id' => 501, 'size_slug' => 'medium' ) ) ), array( 'created' => 500 ) ), order( 1240, array( row( 'Sample Angel', 1 ) ) ) ) ) );
check_same( 'one system: both fish are in building 100, by their product and by their name', array( 1 ), array_keys( $one['buildings'] ) );
check_same( '… its fish, each with the tank it is in and its order', array( 'fish' => 3, 'maybe' => 0, 'lines' => array( array( 'qty' => 2, 'name' => 'Test Tang - Medium', 'number' => '1234', 'created' => 500, 'tanks' => array( 121 ) ), array( 'qty' => 1, 'name' => 'Sample Angel', 'number' => '1240', 'created' => 0, 'tanks' => array( 111 ) ) ) ), $one['buildings'][1] );
check_same( '… and nothing else: no fish unaccounted for, the other systems are fed', array( false, 0, array(), true ), array( $one['all'], $one['unlocated']['fish'], $one['tanks'], Feeding::is_on( $one ) ) );
check( 'building 100 fasts; 200, 300 and the Spa do not', null !== Feeding::for_building( $one, $store->building( 1 ) ) && null === Feeding::for_building( $one, $store->building( 2 ) ) && null === Feeding::for_building( $one, $store->building( 3 ) ) && null === Feeding::for_building( $one, $store->building( 5 ) ) );

$two = plan( $store, ship( array( order( 1250, array( row( 'Demo Wrasse', 1, array( 'product_id' => 777 ) ) ) ) ) ) );
check_same( 'the same fish in two clean systems: both fast, each naming its own tank', array( 2 => array( 221 ), 3 => array( 312 ) ), array( 2 => $two['buildings'][2]['lines'][0]['tanks'], 3 => $two['buildings'][3]['lines'][0]['tanks'] ) );
check_same( '… building 100 is fed', array( 2, 3 ), array_keys( $two['buildings'] ) );

$spa = plan( $store, ship( array( order( 1260, array( row( 'Spa Goby', 2 ) ) ) ) ) );
check_same( 'a fish the site only guesses into the Spa (by its name): its clean tank is not logged yet — every clean system fasts, and the quarantine tank is named', array( array(), true, 2, array( 521 ), true ), array( $spa['buildings'], $spa['all'], $spa['unlocated']['fish'], $spa['unlocated']['lines'][0]['quarantine'], Feeding::is_on( $spa ) ) );
check( '… the Spa itself is fed as always', null === Feeding::for_building( $spa, $store->building( 5 ) ) && null !== Feeding::for_building( $spa, $store->building( 1 ) ) );
$spa_link = plan( $store, ship( array( order( 1260, array( row( 'Invoice fish', 2, array( 'type' => 'invoice_fish', 'link' => array( 'stay_id' => $goby ) ) ) ) ) ) ) );
check_same( 'a fish the Invoices section tied to a Spa group is located there and changes nothing: quarantine is fed as always', array( array(), array(), false, 0, false ), array( $spa_link['buildings'], $spa_link['tanks'], $spa_link['all'], $spa_link['unlocated']['fish'], Feeding::is_on( $spa_link ) ) );

$both = plan( $store, ship( array( order( 1261, array( row( 'Both Chromis', 1 ) ) ) ) ) );
check_same( 'candidates in a clean system and in the Spa: the clean one fasts, the Spa is fed', array( array( 3 ), array( 321 ) ), array( array_keys( $both['buildings'] ), $both['buildings'][3]['lines'][0]['tanks'] ) );
check( '… and for_building never names a Spa building', null === Feeding::for_building( $both, $store->building( 5 ) ) && null === Feeding::for_building( array_merge( $both, array( 'all' => true ) ), $store->building( 5 ) ) );

$lost = plan( $store, ship( array( order( 1234, array( row( 'Test Tang - Medium', 2, array( 'product_id' => 501, 'size_slug' => 'medium' ) ) ) ), order( 1251, array( row( 'Unlogged Clownfish', 3 ), row( 'Unlogged Dottyback', 1 ) ) ) ) ) );
check_same( 'a shipping fish in no tank the site knows: every clean system fasts', array( true, 4, array( 'Unlogged Clownfish', 'Unlogged Dottyback' ) ), array( $lost['all'], $lost['unlocated']['fish'], array_column( $lost['unlocated']['lines'], 'name' ) ) );
check( '… 100 for its own fish too, 200 and 300 because of the unknown one; the Spa still not', array( 'fish' => 2, 'all' => true ) === array_intersect_key( Feeding::for_building( $lost, $store->building( 1 ) ), array( 'fish' => 1, 'all' => 1 ) ) && array( 'fish' => 0, 'maybe' => 0, 'lines' => array(), 'all' => true ) === Feeding::for_building( $lost, $store->building( 2 ) ) && null !== Feeding::for_building( $lost, $store->building( 3 ) ) && null === Feeding::for_building( $lost, $store->building( 5 ) ) && null === Feeding::for_building( $lost, $store->building( 6 ) ) );

$closed = plan( $store, ship( array( order( 1262, array( row( 'Invoice fish', 1, array( 'type' => 'invoice_fish', 'link' => array( 'stay_id' => $wras2 ) ) ) ) ) ) ) );
check_same( 'the Invoices section\'s link names the group: only that system, though the same fish is in another', array( 3 ), array_keys( $closed['buildings'] ) );

// A better-fitting group in the Spa must not hide the group in a clean tank (the arrivals are logged with
// their product and size; the fish for sale beside them often only by name).
$store = new Fake_Store();
place( $store, 512, 'Firefish Test', 6, array( 'product_id' => 880, 'size_slug' => '1-2', 'size_label' => '1-2"' ) );
$named = place( $store, 312, 'Firefish Test', 2 );
$line  = row( 'Firefish Test - 1-2"', 1, array( 'product_id' => 880, 'size_slug' => '1-2', 'size_label' => '1-2"', 'title' => 'Firefish Test' ) );
$hid   = plan( $store, ship( array( order( 1280, array( $line ) ) ) ) );
check_same( 'the Spa group has the product and the size, the clean group only the name: the clean system fasts', array( array( 3 ), array( 312 ), false, 0 ), array( array_keys( $hid['buildings'] ), $hid['buildings'][3]['lines'][0]['tanks'], $hid['all'], $hid['unlocated']['fish'] ) );
check( '… and it is not marked as being in another system: the Spa is none', ! isset( $hid['buildings'][3]['lines'][0]['elsewhere'] ) );
$store = new Fake_Store();
place( $store, 512, 'Firefish Test', 6, array( 'product_id' => 880, 'size_slug' => '1-2', 'size_label' => '1-2"' ) );
place( $store, 312, 'Firefish Test', 2, array( 'product_id' => 880 ) );
$hid = plan( $store, ship( array( order( 1280, array( $line ) ) ) ) );
check_same( '… the same when the clean group has the product but no size written down', array( array( 3 ), false ), array( array_keys( $hid['buildings'] ), $hid['all'] ) );
$store = new Fake_Store();
$spa_f = place( $store, 512, 'Firefish Test', 6, array( 'product_id' => 880, 'size_slug' => '1-2', 'size_label' => '1-2"' ) );
place( $store, 312, 'Firefish Test', 2 );
$tied = plan( $store, ship( array( order( 1280, array( array_merge( $line, array( 'type' => 'invoice_fish', 'link' => array( 'stay_id' => $spa_f ) ) ) ) ) ) ) );
check_same( '… but the Invoices section\'s link to the Spa group is no guess: nothing changes', array( array(), false ), array( $tied['buildings'], Feeding::is_on( $tied ) ) );
$store = new Fake_Store();
place( $store, 512, 'Firefish Test', 6, array( 'product_id' => 880, 'size_slug' => '1-2', 'size_label' => '1-2"' ) );
place( $store, 513, 'Firefish Test', 1, array( 'product_id' => 880, 'size_slug' => '1-2', 'size_label' => '1-2"' ) );
place( $store, 121, 'Bystander Blenny', 1 );
$store->ship = ship( array( order( 1280, array( $line ) ) ) );
$rm          = room( $store );
check_same( 'guessed into the Spa only, in the room: no clean Feed, the Spa fed, and the sentence says where the site has the fish', array( array(), array( 'feed' ), 'No feeding in the Clean Room systems today — 1 fish ships and the site doesn\'t know its tank: 1 × Firefish Test - 1-2" (#1280, on the site only in quarantine tanks 512 and 513).' ), array( kinds( tank_of( $rm, 121 )['todo'] ), kinds( tank_of( $rm, 512 )['todo'] ), $rm['todo']['fast']['text'] ) );

// Inverts: found in a clean tank, that system goes without; found nowhere, nothing changes.
$store = new Fake_Store();
place( $store, 121, 'Test Tang', 2 );
place( $store, 221, 'Sample Snail', 9, array( 'product_id' => 640 ) );
place( $store, 521, 'Spa Shrimp', 4 );
$snail  = row( 'Sample Snail', 3, array( 'product_id' => 640, 'categories' => array( 'inverts' ) ) );
$crab   = row( 'Unlogged Crab', 5, array( 'categories' => array( 'inverts' ) ) );
$shrimp = row( 'Spa Shrimp', 1, array( 'categories' => array( 'inverts' ) ) );
check_same( 'a product only in the inverts\' category is marked; one that is also a fish is a fish', array( true, false, false ), array( Feeding::fish_lines( order( 1, array( $snail ) ), $cats )[0]['loose'], Feeding::fish_lines( order( 1, array( row( 'Both', 1, array( 'categories' => array( 'inverts', 'quarantined-fish' ) ) ) ) ), $cats )[0]['loose'], Feeding::fish_lines( order( 1, array( row( 'Invoice fish', 1, array( 'type' => 'invoice_fish', 'categories' => array() ) ) ) ), $cats )[0]['loose'] ) );
$inv = plan( $store, ship( array( order( 1290, array( $snail, $crab, $shrimp ) ) ) ) );
check_same( 'an invert found in a clean tank: its system goes without', array( array( 2 ), 3, array( 221 ) ), array( array_keys( $inv['buildings'] ), $inv['buildings'][2]['fish'], $inv['buildings'][2]['lines'][0]['tanks'] ) );
check_same( '… the ones in no clean tank the site knows change nothing: no "the site doesn\'t know their tank", the other systems fed', array( false, 0, array() ), array( $inv['all'], $inv['unlocated']['fish'], $inv['unlocated']['lines'] ) );
$inv = plan( $store, ship( array( order( 1291, array( $crab ) ) ) ) );
check_same( 'only unlogged inverts ship: an ordinary day', array( false, array(), false ), array( Feeding::is_on( $inv ), $inv['buildings'], $inv['all'] ) );
$inv = plan( $store, Feeding::shipping( Feeding::going_out( array( 1291 => order( 1291, array( $crab ) ) ), $today ), $today, $cats, array() ) );
check_same( '… unless the shop says inverts are to be found like fish (no loose category): then every clean system fasts', array( true, 5 ), array( $inv['all'], $inv['unlocated']['fish'] ) );

// The same fish in two clean systems: both go without — and neither says all of them ship from it.
$store = new Fake_Store();
place( $store, 121, 'Many Chromis', 60, array( 'product_id' => 910 ) );
place( $store, 221, 'Many Chromis', 60, array( 'product_id' => 910 ) );
place( $store, 222, 'Sample Angel', 1 );
$store->ship = ship( array( order( 1295, array( row( 'Many Chromis', 180, array( 'product_id' => 910 ) ), row( 'Sample Angel', 1 ) ) ) ) );
$rm          = room( $store );
check_same( 'two systems hold the fish: each names the other, and neither counts the 180 as surely its own (the fish it surely ships first)', array( 'Building 100 — no feeding today. 180 fish may ship from this system: 180 × Many Chromis (tank 121, also in Building 200, #1295).', 'Building 200 — no feeding today. 1 fish ships from this system and 180 more may: 1 × Sample Angel (tank 222, #1295), 180 × Many Chromis (tank 221, also in Building 100, #1295).' ), array( block( $rm['todo'], 100 )['fast']['text'], block( $rm['todo'], 200 )['fast']['text'] ) );
$fs = Feeding::plan( $store->ship, $store->open_stays( array(), $today, array() ), $store->tanks() );
check_same( '… the plan counts each fish once: surely 0 + 1, maybe 180 in each, 181 in all', array( 0, 180, 1, 180, 181 ), array( $fs['buildings'][1]['fish'], $fs['buildings'][1]['maybe'], $fs['buildings'][2]['fish'], $fs['buildings'][2]['maybe'], $fs['fish'] ) );

// The fish is bagged and logged as shipped: the group closes — and the fish is not thereby "lost".
$store = new Fake_Store();
$only  = place( $store, 121, 'Test Tang', 2, array( 'product_id' => 501 ) );
place( $store, 122, 'Bystander Blenny', 1 );
place( $store, 221, 'Demo Wrasse', 3 );
$going = ship( array( order( 1234, array( row( 'Test Tang', 2, array( 'product_id' => 501 ) ) ) ) ) );
$store->record_event( $only, array( 'type' => 'sold', 'qty' => 2, 'event_at' => gmdate( 'Y-m-d H:i:s' ) ), $today, $settings );
check_same( 'the group is closed, today', array( 'closed', $today, 0 ), array( $store->stay( $only, $today, $settings )['status'], $store->stay( $only, $today, $settings )['closed_date'], count( array_filter( $store->open_stays( array(), $today, $settings ), static function ( $s ) use ( $only ) { return (int) $s['id'] === $only; } ) ) ) );
$after = Feeding::plan( $going, $store->open_stays( array(), $today, $settings ), $store->tanks(), $store->closed_stays( $today, $settings ) );
check_same( 'logged as shipped at nine: its system goes on fasting, and no fish is "one the site doesn\'t know"', array( array( 1 ), array( 121 ), false, 0 ), array( array_keys( $after['buildings'] ), $after['buildings'][1]['lines'][0]['tanks'], $after['all'], $after['unlocated']['fish'] ) );
$store->ship = $going;
$rm          = room( $store );
check_same( '… the tank beside it still has no Feed; building 200 still has', array( array(), array( 'feed' ), 1 ), array( kinds( tank_of( $rm, 122 )['todo'] ), kinds( tank_of( $rm, 221 )['todo'] ), $rm['todo']['total'] ) );
$without = Feeding::plan( $going, $store->open_stays( array(), $today, $settings ), $store->tanks() );
check_same( '… without the day\'s closed groups it would have been a fish in no tank: that is what they are asked for', array( true, 2 ), array( $without['all'], $without['unlocated']['fish'] ) );
check_same( 'a group closed on another day does not answer', array( array(), array( 'x' => true ) ), array( Feeding::left_today( array( array( 'id' => 1, 'closed_date' => Replay::add_days( $today, -1 ) ), array( 'id' => 2, 'closed_date' => null ), 'junk' ), $today ), array( 'x' => Feeding::left_today( array( array( 'id' => 3, 'closed_date' => $today ) ), $today )[0]['left_today'] ) ) );

/* ------------------------------------------------------------------ */
echo "6. Temporary tanks\n";

$store = new Fake_Store();
set_up( $store, array( 'name' => 'Holding A', 'kind' => 'clean', 'gallons' => 20 ) );      // 901
set_up( $store, array( 'name' => 'Hospital B', 'kind' => 'quarantine', 'gallons' => 10 ) ); // 902
set_up( $store, array( 'name' => 'Holding C', 'kind' => 'clean', 'gallons' => 20 ) );      // 903
place( $store, 901, 'Held Tang', 2 );
$sick = place( $store, 902, 'Sick Goby', 1 );
place( $store, 903, 'Other Angel', 1 );
place( $store, 121, 'Rack Wrasse', 1 );

$t901 = $store->tank_by_number( 901 );
$t903 = $store->tank_by_number( 903 );
$temp = plan( $store, ship( array( order( 1270, array( row( 'Held Tang', 1 ), row( 'Sick Goby', 1, array( 'type' => 'invoice_fish', 'link' => array( 'stay_id' => $sick ) ) ) ) ) ) ) );
check_same( 'a clean temporary tank a shipping fish is in fasts — it alone: it is its own water', array( array( (int) $t901['id'] ), array() ), array( array_keys( $temp['tanks'] ), $temp['buildings'] ) );
check_same( '… a quarantine one is fed as always', 1, $temp['tanks'][ (int) $t901['id'] ]['fish'] );
$r = $store;
$r->ship = ship( array( order( 1270, array( row( 'Held Tang', 1 ), row( 'Sick Goby', 1, array( 'type' => 'invoice_fish', 'link' => array( 'stay_id' => $sick ) ) ) ) ) ) );
$rm      = room( $r );
check_same( 'its to-do: no Feed, the reason on the tank', array( array(), 'TEMP 1 · 901 · Holding A — no feeding today. 1 fish ships from this tank: 1 × Held Tang (tank 901, #1270).', 'No feeding today — fish ship from here.' ), array( kinds( tank_of( $rm, 901 )['todo'] ), tank_of( $rm, 901 )['fast']['text'], tank_of( $rm, 901 )['fast']['strip'] ) );
check_same( '… the other temporary tanks and the rack are fed', array( array( 'feed' ), array( 'feed' ), array( 'feed' ), false, false ), array( kinds( tank_of( $rm, 902 )['todo'] ), kinds( tank_of( $rm, 903 )['todo'] ), kinds( tank_of( $rm, 121 )['todo'] ), isset( tank_of( $rm, 902 )['fast'] ), isset( tank_of( $rm, 121 )['fast'] ) ) );
check_same( '… its block in the day\'s list carries the reason and counts nothing', array( array(), 0, 'TEMP 1 · 901 · Holding A — no feeding today. 1 fish ships from this tank: 1 × Held Tang (tank 901, #1270).' ), array( block( $rm['todo'], 901 )['items'], block( $rm['todo'], 901 )['left'], block( $rm['todo'], 901 )['fast']['text'] ) );
check_same( '… and the Today words name it', 'no feeding in TEMP 1 · 901 (ship day)', $rm['todo']['fast']['words'] );
$guess = plan( $store, ship( array( order( 1271, array( row( 'Sick Goby', 1 ) ) ) ) ) );
check_same( 'a fish the site only guesses into a quarantine temporary tank: every Clean Room building fasts — the clean temporary tanks are their own water and are fed', array( true, array(), array( 902 ) ), array( $guess['all'], $guess['tanks'], $guess['unlocated']['lines'][0]['quarantine'] ) );
$r->ship = ship( array( order( 1271, array( row( 'Sick Goby', 1 ) ) ) ) );
$rg      = room( $r );
check_same( '… in the room: the rack has no Feed, both clean temporary tanks and the quarantine one have', array( array(), array( 'feed' ), array( 'feed' ), array( 'feed' ) ), array( kinds( tank_of( $rg, 121 )['todo'] ), kinds( tank_of( $rg, 901 )['todo'] ), kinds( tank_of( $rg, 902 )['todo'] ), kinds( tank_of( $rg, 903 )['todo'] ) ) );
check_same( '… and the words say "systems", not "tanks": a clean temporary tank is still fed', array( 'no feeding in the Clean Room systems (ship day)', true ), array( $rg['todo']['fast']['words'], 0 === strpos( $rg['todo']['fast']['text'], 'No feeding in the Clean Room systems today' ) ) );

$r->ship = ship( array( order( 1271, array( row( 'Unlogged Clownfish', 1 ) ) ) ) );
$rm      = room( $r );
check_same( 'a fish the site cannot find: the clean rack fasts, the temporary tanks are left as they are', array( array(), array( 'feed' ), array( 'feed' ), array( 'feed' ) ), array( kinds( tank_of( $rm, 121 )['todo'] ), kinds( tank_of( $rm, 901 )['todo'] ), kinds( tank_of( $rm, 902 )['todo'] ), kinds( tank_of( $rm, 903 )['todo'] ) ) );

/* ------------------------------------------------------------------ */
echo "7. The tick, and the day the rule cannot run\n";

check_same( 'on by default', true, Settings::get()[ Feeding::SETTING ] );
check_same( 'a settings array that never heard of it: on', true, Settings::normalize( array() )[ Feeding::SETTING ] );
check_same( 'unticked in the form: off', false, Settings::sanitize( array( 'sale_ready_days' => 28, 'copper_floor' => 2.25, 'copper_target' => 2.5, 'auto_confirm_seconds' => 4 ) )[ Feeding::SETTING ] );
check_same( 'ticked: on', true, Settings::sanitize( array( 'sale_ready_days' => 28, 'copper_floor' => 2.25, 'copper_target' => 2.5, 'auto_confirm_seconds' => 4, Feeding::SETTING => '1' ) )[ Feeding::SETTING ] );
check_same( 'stored off stays off', false, Settings::normalize( array( Feeding::SETTING => false ) )[ Feeding::SETTING ] );

check_same( 'the tick on, the Batch Manager and the shop there: the rule runs', Feeding::STATE_ON, Feeding::state( array( Feeding::SETTING => true ), true, true ) );
check_same( 'the tick off: off, whatever else is there', Feeding::STATE_OFF, Feeding::state( array( Feeding::SETTING => false ), true, true ) );
check_same( 'no Batch Manager: no order has a ship day', Feeding::STATE_NO_BATCH, Feeding::state( array( Feeding::SETTING => true ), false, true ) );
check_same( 'no WooCommerce: no orders', Feeding::STATE_NO_SHOP, Feeding::state( array( Feeding::SETTING => true ), true, false ) );
check_same( 'here, without the Batch Manager, the room is as it always was', Feeding::STATE_NO_BATCH, Ship_Lines::fast( $today, $settings, array(), array() )['state'] );
foreach ( array( Feeding::STATE_OFF, Feeding::STATE_NO_BATCH, Feeding::STATE_NO_SHOP ) as $state ) {
	$off = array_merge( $lost, array( 'state' => $state ) );
	check( 'state "' . $state . '": nobody fasts, even with a plan in hand', ! Feeding::is_on( $off ) && null === Feeding::for_building( $off, array( 'id' => 1, 'area' => 'clean' ) ) && '' === Feeding::list_notice( $off ) && '' === Feeding::today_words( $off, array( 'Building 100' ) ) );
}
$text = Feeding::setting_text( Feeding::STATE_ON );
check_same( 'the tick\'s name', 'Don\'t feed a clean system on a day its fish ship', $text['label'] );
check( 'two plain sentences: what it does, and what happens when the site does not know a shipping fish\'s tank', 2 === substr_count( $text['text'], '. ' ) + 1 && false !== strpos( $text['text'], 'Feed is left off' ) && false !== strpos( $text['text'], 'does not know which Clean Room tank' ) && false !== strpos( $text['text'], 'under another name' ) && '' === $text['why'] );
check_contains( 'without the Batch Manager the tick says why it does nothing', 'Batch Manager plugin is not active', Feeding::setting_text( Feeding::STATE_NO_BATCH )['why'] );
check_contains( 'without WooCommerce too', 'WooCommerce is not active', Feeding::setting_text( Feeding::STATE_NO_SHOP )['why'] );

/* ------------------------------------------------------------------ */
echo "8. The to-do: no Feed, and the reason in its place\n";

$store = new Fake_Store();
place( $store, 121, 'Test Tang', 4, array( 'product_id' => 501, 'size_slug' => 'medium', 'size_label' => 'Medium' ) );
place( $store, 111, 'Sample Angel', 2 );
place( $store, 122, 'Bystander Blenny', 1 );
place( $store, 221, 'Demo Wrasse', 3 );
place( $store, 521, 'Spa Goby', 5 );

$plain = room( $store );
check_same( 'an ordinary day: Feed in each clean system and in the Spa — 3 things to do', array( 3, 3, array( 'feed' ), array( 'feed' ), null ), array( $plain['todo']['left'], $plain['todo']['total'], kinds( block( $plain['todo'], 100 )['items'] ), kinds( block( $plain['todo'], 200 )['items'] ), $plain['todo']['fast'] ) );
check( '… and no block or tank says anything about feeding', null === block( $plain['todo'], 100 )['fast'] && null === block( $plain['todo'], 500 )['fast'] && ! isset( tank_of( $plain, 121 )['fast'] ) );

$store->ship = ship( array( order( 1234, array( row( 'Test Tang - Medium', 2, array( 'product_id' => 501, 'size_slug' => 'medium' ) ) ) ), order( 1240, array( row( 'Sample Angel', 1 ) ) ) ) );
$fast        = room( $store );
$b100        = block( $fast['todo'], 100 );
check_same( 'ship day: building 100 has no Feed — and still has its block, for the reason', array( array(), 0 ), array( $b100['items'], $b100['left'] ) );
check_same( '… the reason, in plain words', 'Building 100 — no feeding today. 3 fish ship from this system: 2 × Test Tang - Medium (tank 121, #1234), 1 × Sample Angel (tank 111, #1240).', $b100['fast']['text'] );
check_same( '… not a thing to do: the count leaves it out (2 left of 2: building 200 and the Spa)', array( 2, 2, false ), array( $fast['todo']['left'], $fast['todo']['total'], $fast['todo']['all_done'] ) );
check( '… not a line: nothing in the block can be ticked', array() === $b100['items'] && ! array_key_exists( 'key', $b100['fast'] ) && ! array_key_exists( 'scope', $b100['fast'] ) );
check_same( '… building 200 and the Spa are fed as always', array( array( 'feed' ), null, array( 'feed' ), null ), array( kinds( block( $fast['todo'], 200 )['items'] ), block( $fast['todo'], 200 )['fast'], kinds( block( $fast['todo'], 500 )['items'] ), block( $fast['todo'], 500 )['fast'] ) );
check_same( 'every tank of the fasting system is without Feed — the whole system is one water, also the tank no shipping fish is in', array( array(), array(), array() ), array( kinds( tank_of( $fast, 121 )['todo'] ), kinds( tank_of( $fast, 111 )['todo'] ), kinds( tank_of( $fast, 122 )['todo'] ) ) );
check_same( '… each says why on its own screen', array( 'No feeding today — fish ship from here.', 'No feeding today — fish ship from here.', '' ), array( tank_of( $fast, 121 )['fast']['strip'], tank_of( $fast, 122 )['fast']['strip'], tank_of( $fast, 122 )['fast']['fed'] ) );
check( '… an empty tank of that system says nothing; a tank elsewhere neither', ! isset( tank_of( $fast, 123 )['fast'] ) && ! isset( tank_of( $fast, 221 )['fast'] ) && ! isset( tank_of( $fast, 521 )['fast'] ) && array( 'feed' ) === kinds( tank_of( $fast, 221 )['todo'] ) );
check_same( 'the list itself has no notice (every fish was found) and the words for other screens', array( 'text' => '', 'hint' => '', 'words' => 'no feeding in Building 100 (ship day)' ), $fast['todo']['fast'] );
check_same( 'the Board card of the fasting system reads the same sentence; the others read nothing', array( $b100['fast']['text'], '', '' ), array( Todo::fast_text( $fast['todo'], 1 ), Todo::fast_text( $fast['todo'], 2 ), Todo::fast_text( $fast['todo'], 5 ) ) );

$tick = Todo::tick( $store, array( 'scope' => 'system', 'building' => 2, 'key' => 'feed', 'kind' => 'feed', 'label' => 'Feed' ), $base );
$tick = Todo::tick( $store, array( 'scope' => 'system', 'building' => 5, 'key' => 'feed', 'kind' => 'feed', 'label' => 'Feed' ), $base );
$done = room( $store );
check_same( 'the others fed: all done — the fasting system never stood in the way', array( 0, 2, true, 'Tanks: all done' ), array( $done['todo']['left'], $done['todo']['total'], $done['todo']['all_done'], Todo::headline( $done['todo'] ) ) );

$screen = Front_Desk::screen_from( $store, 121, $settings, $today );
check_same( 'the phone, on a tank of the fasting system: no Feed chip, and the strip\'s sentence', array( array(), 'No feeding today — fish ship from here.' ), array( $screen['tank']['todo'], $screen['tank']['fast']['strip'] ) );
check_same( '… the day\'s list on the phone carries the block\'s reason', $b100['fast']['text'], block( $screen['todoList'], 100 )['fast']['text'] );
$screen = Front_Desk::screen_from( $store, 221, $settings, $today );
check_same( '… a tank of a fed system: nothing about feeding', null, $screen['tank']['fast'] );

/* ------------------------------------------------------------------ */
echo "9. A fish the site cannot find: no clean system is fed, and the list says which fish\n";

$store       = new Fake_Store();
place( $store, 121, 'Test Tang', 4 );
place( $store, 221, 'Demo Wrasse', 3 );
place( $store, 521, 'Spa Goby', 5 );
$store->ship = ship( array( order( 1250, array( row( 'Unlogged Clownfish', 2 ) ) ), order( 1251, array( row( 'Unlogged Dottyback', 2 ) ) ) ) );
$all         = room( $store );
check_same( 'no Feed in either clean system; the Spa is fed', array( array(), array(), array( 'feed' ) ), array( block( $all['todo'], 100 )['items'], block( $all['todo'], 200 )['items'], kinds( block( $all['todo'], 500 )['items'] ) ) );
check_same( 'the sentence at the top names the fish', 'No feeding in the Clean Room systems today — 4 fish ship and the site doesn\'t know their tank: 2 × Unlogged Clownfish (#1250), 2 × Unlogged Dottyback (#1251).', $all['todo']['fast']['text'] );
check_contains( '… and how to end it', 'Add fish already here', $all['todo']['fast']['hint'] );
check_same( 'each clean block says why it has no Feed', 'Building 200 — no feeding today. A fish ships today and the site doesn\'t know its tank, so no Clean Room system is fed.', block( $all['todo'], 200 )['fast']['text'] );
check_same( '… and each of its tanks', 'No feeding today — a fish ships and the site doesn\'t know its tank.', tank_of( $all, 221 )['fast']['strip'] );
check_same( 'the count: the Spa\'s feed alone', array( 1, 1 ), array( $all['todo']['left'], $all['todo']['total'] ) );
check_same( 'the words for other screens', 'no feeding in the Clean Room systems (ship day)', $all['todo']['fast']['words'] );

// The rule narrows by itself: the fish gets logged in its tank.
place( $store, 222, 'Unlogged Clownfish', 2 );
place( $store, 211, 'Unlogged Dottyback', 2 );
$narrow = room( $store );
check_same( 'once the fish are logged in their tanks, only their system goes without', array( array( 'feed' ), array(), '', 'no feeding in Building 200 (ship day)' ), array( kinds( block( $narrow['todo'], 100 )['items'] ), block( $narrow['todo'], 200 )['items'], $narrow['todo']['fast']['text'], $narrow['todo']['fast']['words'] ) );

$empty       = new Fake_Store();
$empty->ship = $store->ship;
$bare        = room( $empty );
check_same( 'a room with no fish logged at all: no block to change, the sentence still stands', array( 0, 'no feeding in the Clean Room systems (ship day)' ), array( $bare['todo']['total'], $bare['todo']['fast']['words'] ) );
check_contains( '… naming the fish', 'Unlogged Clownfish', $bare['todo']['fast']['text'] );

/* ------------------------------------------------------------------ */
echo "10. A feed ticked before the order came in\n";

$store = new Fake_Store();
place( $store, 121, 'Test Tang', 4 );
place( $store, 111, 'Sample Angel', 2 );
place( $store, 122, 'Bystander Blenny', 1 );
Todo::tick( $store, array( 'scope' => 'tank', 'tick_tank' => 121, 'key' => 'feed', 'kind' => 'feed', 'label' => 'Feed' ), $base );
$fed_at      = array_values( $store->day_logs( $today ) )[0]['logged_at'];
$fed_time    = ( new DateTimeImmutable( $fed_at, new DateTimeZone( 'UTC' ) ) )->setTimezone( wp_timezone() )->format( 'g:i' );
$store->ship = ship( array( order( 1251, array( row( 'Test Tang', 1 ) ), array( 'created' => time() + 3600 ) ) ) );
$late        = room( $store );
$b100        = block( $late['todo'], 100 );
check_same( 'the tank that was fed keeps its tick; the two that were not get no Feed', array( array( 'feed' ), true, array(), array() ), array( kinds( tank_of( $late, 121 )['todo'] ), tank_of( $late, 121 )['todo'][0]['done'], kinds( tank_of( $late, 111 )['todo'] ), kinds( tank_of( $late, 122 )['todo'] ) ) );
check_same( '… and reads when it was fed', 'Fed at ' . $fed_time . ', before order #1251 came in.', tank_of( $late, 121 )['todo'][0]['note'] );
check_same( '… on the tank\'s own strip too', 'Fed at ' . $fed_time . ', before order #1251 came in.', tank_of( $late, 121 )['fast']['fed'] );
check_same( 'the block: the feed that was done, as done — one tank, nothing left — and the reason', array( 1, 0, true, 1, 'Fed at ' . $fed_time . ', before order #1251 came in.' ), array( count( $b100['items'] ), $b100['left'], $b100['items'][0]['done'], $b100['items'][0]['count'], $b100['items'][0]['note'] ) );
check_contains( '… the reason still stands under it', 'no feeding today. 1 fish ships from this system: 1 × Test Tang (tank 121, #1251).', $b100['fast']['text'] );
check_same( '… counted as done, not as something to do', array( 0, 1 ), array( $late['todo']['left'], $late['todo']['total'] ) );

check_same( 'pure: the time is the site\'s, and the order named is the first one placed after the feed', 'Fed at 7:40, before order #1251 came in.', Feeding::fed_text( '2026-11-24 13:40:00', array( '1249' => strtotime( '2026-11-24 12:00:00 UTC' ), '1260' => strtotime( '2026-11-24 15:00:00 UTC' ), '1251' => strtotime( '2026-11-24 13:41:00 UTC' ) ), new DateTimeZone( 'America/Chicago' ) ) );
check_same( '… no order came in after it: just when', 'Fed at 7:40.', Feeding::fed_text( '2026-11-24 13:40:00', array( '1249' => strtotime( '2026-11-24 12:00:00 UTC' ) ), new DateTimeZone( 'America/Chicago' ) ) );
check_same( '… an afternoon feed in the site\'s own hours', 'Fed at 4:05.', Feeding::fed_text( '2026-11-24 22:05:00', array(), new DateTimeZone( 'America/Chicago' ) ) );
check_same( '… no time known', 'Fed before the order came in.', Feeding::fed_text( '', array( '1251' => 5 ) ) );
check_same( 'the first tick of the day is the one that counts', '2026-11-24 13:40:00', Feeding::ticked_at( 'feed', array( array( 'note' => 'Feed', 'logged_at' => '2026-11-24 15:00:00' ), array( 'note' => 'feed', 'logged_at' => '2026-11-24 13:40:00' ), array( 'note' => 'copper test', 'logged_at' => '2026-11-24 09:00:00' ) ) ) );

Todo::tick( $store, array( 'scope' => 'system', 'building' => 1, 'key' => 'feed', 'kind' => 'feed', 'label' => 'Feed' ), $base );
$whole = room( $store );
check_same( 'the whole system ticked "All fed" before the order: every tank keeps it', array( true, true, true, 3, 0 ), array( tank_of( $whole, 121 )['todo'][0]['done'], tank_of( $whole, 111 )['todo'][0]['done'], tank_of( $whole, 122 )['todo'][0]['done'], block( $whole['todo'], 100 )['items'][0]['count'], block( $whole['todo'], 100 )['left'] ) );

/* ------------------------------------------------------------------ */
echo "11. The words\n";

$why = array(
	'fish'  => 3,
	'lines' => array(
		array( 'qty' => 2, 'name' => 'Yellow Tang', 'number' => '1234', 'tanks' => array( 121 ) ),
		array( 'qty' => 1, 'name' => 'Flame Angel', 'number' => '1240', 'tanks' => array( 118 ) ),
	),
	'all'   => false,
);
check_same( 'the block and the Board card', 'Clean Room 2 — no feeding today. 3 fish ship from this system: 2 × Yellow Tang (tank 121, #1234), 1 × Flame Angel (tank 118, #1240).', Feeding::block( 'Clean Room 2', $why )['text'] );
check_same( 'the phone\'s strip', 'No feeding today — fish ship from here.', Feeding::block( 'Clean Room 2', $why )['strip'] );
check_same( 'one fish', 'Clean Room 2 — no feeding today. 1 fish ships from this system: 1 × Flame Angel (tanks 118 and 119, #1240).', Feeding::block( 'Clean Room 2', array( 'fish' => 1, 'lines' => array( array( 'qty' => 1, 'name' => 'Flame Angel', 'number' => '1240', 'tanks' => array( 118, 119 ) ) ), 'all' => false ) )['text'] );

$many = array();
for ( $i = 1; $i <= 10; $i++ ) {
	$many[] = array( 'qty' => 1, 'name' => 'Fish ' . $i, 'number' => (string) ( 1300 + $i ), 'tanks' => array() );
}
check_same( 'a long list is shortened', '1 × Fish 1 (#1301), 1 × Fish 2 (#1302), 1 × Fish 3 (#1303), 1 × Fish 4 (#1304) and 6 more fish', Feeding::lines_text( $many ) );
check_same( '… in the sentence at the top', 'No feeding in the Clean Room systems today — 10 fish ship and the site doesn\'t know their tank: 1 × Fish 1 (#1301), 1 × Fish 2 (#1302), 1 × Fish 3 (#1303), 1 × Fish 4 (#1304) and 6 more fish.', Feeding::list_notice( array( 'state' => 'on', 'all' => true, 'unlocated' => array( 'fish' => 10, 'lines' => $many ), 'buildings' => array(), 'tanks' => array() ) ) );
check_same( '… one fish: "its tank"', 'No feeding in the Clean Room systems today — 1 fish ships and the site doesn\'t know its tank: 1 × Fish 1 (#1301).', Feeding::list_notice( array( 'state' => 'on', 'all' => true, 'unlocated' => array( 'fish' => 1, 'lines' => array( $many[0] ) ), 'buildings' => array(), 'tanks' => array() ) ) );
check_same( 'a fish without a name still stands in the list', '1 × a fish without a name (#1301)', Feeding::lines_text( array( array( 'qty' => 1, 'name' => ' ', 'number' => '1301' ) ) ) );
$uneven = array();
foreach ( array( 1, 2, 3, 1, 1, 2, 3, 1, 2, 3 ) as $i => $qty ) {
	$uneven[] = array( 'qty' => $qty, 'name' => 'Fish ' . ( $i + 1 ), 'number' => '1260', 'tanks' => array() );
}
check_same( 'the sentence adds up: 19 fish, 7 named, "and 12 more fish" — the fish left out, not the lines', '1 × Fish 1 (#1260), 2 × Fish 2 (#1260), 3 × Fish 3 (#1260), 1 × Fish 4 (#1260) and 12 more fish', Feeding::lines_text( $uneven ) );
check_same( '… on a system\'s line too', 'Clean Room 2 — no feeding today. 7 fish ship from this system: 1 × A (tank 121, #1), 1 × B (tank 121, #1), 1 × C (tank 121, #1), 1 × D (tank 121, #1) and 3 more fish.', Feeding::block( 'Clean Room 2', array( 'fish' => 7, 'lines' => array( array( 'qty' => 1, 'name' => 'A', 'number' => '1', 'tanks' => array( 121 ) ), array( 'qty' => 1, 'name' => 'B', 'number' => '1', 'tanks' => array( 121 ) ), array( 'qty' => 1, 'name' => 'C', 'number' => '1', 'tanks' => array( 121 ) ), array( 'qty' => 1, 'name' => 'D', 'number' => '1', 'tanks' => array( 121 ) ), array( 'qty' => 2, 'name' => 'E', 'number' => '1', 'tanks' => array( 121 ) ), array( 'qty' => 1, 'name' => 'F', 'number' => '1', 'tanks' => array( 121 ) ) ), 'all' => false ) )['text'] );
check_same( 'a fish in another clean system too: "may", and the fish names the other one — one temporary tank the same', array( 'Clean Room 2 — no feeding today. 1 fish may ship from this system: 1 × Flame Angel (tank 118, also in Clean Room 3, #1240).', 'TEMP 1 · 901 — no feeding today. 2 fish may ship from this tank: 2 × Flame Angel (tank 901, also in Clean Room 2 and Clean Room 3, #1240).', 'Clean Room 2 — no feeding today. 2 fish ship from this system and 1 more may: 2 × Yellow Tang (tank 121, #1234), 1 × Flame Angel (tank 118, also in Clean Room 3, #1240).' ), array( Feeding::block( 'Clean Room 2', array( 'fish' => 0, 'maybe' => 1, 'lines' => array( array( 'qty' => 1, 'name' => 'Flame Angel', 'number' => '1240', 'tanks' => array( 118 ), 'also' => array( 'Clean Room 3' ) ) ), 'all' => false ) )['text'], Feeding::block( 'TEMP 1 · 901', array( 'fish' => 0, 'maybe' => 2, 'lines' => array( array( 'qty' => 2, 'name' => 'Flame Angel', 'number' => '1240', 'tanks' => array( 901 ), 'also' => array( 'Clean Room 2', 'Clean Room 3' ) ) ), 'all' => false ), true )['text'], Feeding::block( 'Clean Room 2', array( 'fish' => 2, 'maybe' => 1, 'lines' => array( array( 'qty' => 1, 'name' => 'Flame Angel', 'number' => '1240', 'tanks' => array( 118 ), 'also' => array( 'Clean Room 3' ) ), array( 'qty' => 2, 'name' => 'Yellow Tang', 'number' => '1234', 'tanks' => array( 121 ) ) ), 'all' => false ) )['text'] ) );
check_same( 'a fish the site only has in quarantine names that tank', '1 × Firefish Test (#1280, on the site only in quarantine tank 512)', Feeding::lines_text( array( array( 'qty' => 1, 'name' => 'Firefish Test', 'number' => '1280', 'tanks' => array(), 'quarantine' => array( 512 ) ) ) ) );
$hint = Feeding::list_hint();
check( 'how to end it: a fish already on the site under another name (or still in quarantine) is corrected or moved FIRST — adding it again would count it twice; only then "add"', false !== strpos( $hint, 'under another name' ) && false !== strpos( $hint, 'do not add it again' ) && false !== strpos( $hint, 'Identify…' ) && false !== strpos( $hint, 'Correct the fish…' ) && strpos( $hint, 'under another name' ) < strpos( $hint, 'Add fish already here' ) );

$on = array( 'state' => 'on', 'all' => false, 'buildings' => array( 2 => array( 'fish' => 1, 'lines' => array() ) ), 'tanks' => array() );
check_same( 'the few words for the Today page: one system', 'no feeding in Clean Room 2 (ship day)', Feeding::today_words( $on, array( 'Clean Room 2' ) ) );
check_same( '… two, three', array( 'no feeding in Clean Room 2 and Building 300 (ship day)', 'no feeding in A, B and C (ship day)' ), array( Feeding::today_words( $on, array( 'Clean Room 2', 'Building 300', 'Clean Room 2' ) ), Feeding::today_words( $on, array( 'A', 'B', 'C' ) ) ) );
check_same( '… none with fish: no words', '', Feeding::today_words( $on, array() ) );

/* ------------------------------------------------------------------ */
echo "12. The Today page's line and the count other screens read\n";

$summary = Todo::summary( $fast['todo'], 'u' );
check_same( 'the summary: the fasting system\'s feeds are in neither number, and the words ride along', array( 'left' => 2, 'total' => 2, 'all_done' => false, 'flags' => 0, 'text' => 'Tanks: 2 things to do', 'url' => 'u', 'fast' => 'no feeding in Building 100 (ship day)' ), $summary );
$line = Todo::today_line( $summary );
check_same( 'the Today line: the count, and the few words beside it', array( 'Tanks: 2 things to do', 'no feeding in Building 100 (ship day)', 2, 'todo' ), array( $line['text'], $line['detail'], $line['count'], $line['tone'] ) );
check_same( '… beside a copper flag', '2 copper flags · no feeding in Building 100 (ship day)', Todo::today_line( array_merge( $summary, array( 'flags' => 2 ) ) )['detail'] );
check_same( '… on an ordinary day the line is as it was', array( 'Tanks: 3 things to do', '' ), array( Todo::today_line( Todo::summary( $plain['todo'], 'u' ) )['text'], Todo::today_line( Todo::summary( $plain['todo'], 'u' ) )['detail'] ) );

$lone       = new Fake_Store();
place( $lone, 121, 'Test Tang', 4 );
$lone->ship = ship( array( order( 1234, array( row( 'Test Tang', 1 ) ) ) ) );
$only       = Todo::summary( room( $lone )['todo'], 'u' );
$line       = Todo::today_line( $only );
check_same( 'the fasting system held the day\'s only to-do: nothing is due — and the line still says why, instead of vanishing', array( 0, 'Tanks: nothing due today', 'no feeding in Building 100 (ship day)', 'done' ), array( $only['total'], $line['text'], $line['detail'], $line['tone'] ) );
check( 'a day with nothing due and nobody fasting still adds no line', null === Todo::today_line( Todo::summary( room( new Fake_Store() )['todo'], 'u' ) ) );

/* ------------------------------------------------------------------ */
echo "13. As the screens render it\n";

function fh_todo_html( Fake_Store $store ) {
	global $today, $settings, $protocols;
	$tab          = 'todo';
	$can_settings = false;
	$tables_ok    = true;
	$list         = Front_Desk::todo_screen( $store->room( $today, $settings, $protocols )['todo'] );
	ob_start();
	include FISHOTEL_MISC_PATH . 'includes/sections/clean-room/views/todo.php';
	return (string) ob_get_clean();
}

function fh_board_html( Fake_Store $store ) {
	global $today, $settings, $protocols;
	$tab          = 'board';
	$can_settings = false;
	$tables_ok    = true;
	$labels_url   = 'https://example.test/labels';
	$filters      = Admin::filters_from_request( array() );
	$room         = $store->room( $today, $settings, $protocols );
	$tanks        = $room['tanks'];
	$todo         = is_array( $room['todo'] ) ? $room['todo'] : array( 'left' => 0, 'total' => 0 );
	$groups       = Layout::group( $store->buildings(), $tanks );
	$totals       = Admin::totals( $tanks );
	$doses        = Dose::board_doses( $store->latest_doses() );
	$sizes        = Sizes::standard( $store, $settings )['sizes'];
	$temp         = Admin_Temp::board( $store, $tanks, $protocols, $today );
	$origins      = Admin::origins( $store, $tanks );
	ob_start();
	include FISHOTEL_MISC_PATH . 'includes/sections/clean-room/views/board.php';
	return (string) ob_get_clean();
}

/**
 * One block of the To do tab's HTML, by its title.
 */
function fh_block_html( $html, $title ) {
	foreach ( preg_split( '/<section class="fh-cr-todo-block/', $html ) as $part ) {
		if ( false !== strpos( $part, esc_html( $title ) . "\n" ) || false !== strpos( $part, esc_html( $title ) . "\t" ) || 1 === preg_match( '/<h2>\s*' . preg_quote( esc_html( $title ), '/' ) . '\s*<\/h2>/', $part ) ) {
			return $part;
		}
	}
	return '';
}

$store = new Fake_Store();
place( $store, 121, 'Test Tang', 4, array( 'product_id' => 501 ) );
place( $store, 221, 'Demo Wrasse', 3 );
place( $store, 521, 'Spa Goby', 5 );
$store->ship = ship( array( order( 1234, array( row( 'Test Tang', 2, array( 'product_id' => 501 ) ) ) ) ) );

$html   = fh_todo_html( $store );
$reason = esc_html( 'Building 100 — no feeding today. 2 fish ship from this system: 2 × Test Tang (tank 121, #1234).' );
$b100   = fh_block_html( $html, 'Clean Room · Building 100' );
check( 'the To do tab: the fasting system\'s block is there, with the reason', '' !== $b100 && false !== strpos( $b100, '<p class="fh-cr-fast">' . $reason . '</p>' ) );
check( '… with no button to tick and no line', false === strpos( $b100, 'fh-cr-todo-tick' ) && false === strpos( $b100, 'fh-cr-todo-line' ) && false === strpos( $b100, 'is-idle' ) );
check( '… the fed systems keep their Feed button', false !== strpos( fh_block_html( $html, 'Clean Room · Building 200' ), 'fh-cr-todo-tick' ) && false === strpos( fh_block_html( $html, 'Clean Room · Building 200' ), 'fh-cr-fast' ) && false !== strpos( fh_block_html( $html, 'Spa · Building 500' ), 'fh-cr-todo-tick' ) );
check( '… the count at the top: 2 things, not 3', 1 === preg_match( '/<strong>2<\/strong>\s*things left to do\s*<span class="fh-cr-muted">of 2<\/span>/', $html ) );
check( '… no notice at the top: every fish was found', false === strpos( $html, 'fh-cr-fast--all' ) );

$board = fh_board_html( $store );
check( 'the Board: the fasting system\'s card reads the same sentence, once', 1 === substr_count( $board, '<p class="fh-cr-fast">' . $reason . '</p>' ) && 1 === substr_count( $board, 'class="fh-cr-fast"' ) );

$store->ship = ship( array( order( 1250, array( row( 'Unlogged Clownfish', 4 ) ) ) ) );
$html        = fh_todo_html( $store );
$notice      = esc_html( 'No feeding in the Clean Room systems today — 4 fish ship and the site doesn\'t know their tank: 4 × Unlogged Clownfish (#1250).' );
check( 'a fish the site cannot find: the notice at the top of the To do tab, once', 1 === substr_count( $html, 'fh-cr-fast--all' ) && false !== strpos( $html, '<strong>' . $notice . '</strong>' ) );
check( '… both clean blocks say why; neither can be ticked; the Spa can', false !== strpos( fh_block_html( $html, 'Clean Room · Building 100' ), 'so no Clean Room system is fed.' ) && false !== strpos( fh_block_html( $html, 'Clean Room · Building 200' ), 'so no Clean Room system is fed.' ) && false === strpos( fh_block_html( $html, 'Clean Room · Building 100' ), 'fh-cr-todo-tick' ) && false === strpos( fh_block_html( $html, 'Clean Room · Building 200' ), 'fh-cr-todo-tick' ) && false !== strpos( fh_block_html( $html, 'Spa · Building 500' ), 'fh-cr-todo-tick' ) );
$board = fh_board_html( $store );
check( '… and on the Board: the notice once, a line on each clean system with fish', 1 === substr_count( $board, 'fh-cr-fast--all' ) && false !== strpos( $board, $notice ) && 2 === substr_count( $board, 'class="fh-cr-fast"' ) );

$store->ship = null;
$html        = fh_todo_html( $store );
check( 'an ordinary day: not a word about it on either screen', false === strpos( $html, 'fh-cr-fast"' ) && false === strpos( $html, 'fh-cr-fast--all' ) && false === strpos( fh_board_html( $store ), 'class="fh-cr-fast' ) );

fh_finish();
