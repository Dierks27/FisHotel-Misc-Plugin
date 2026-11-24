<?php
/**
 * Reserved by hand — what two reviews of 0.46.17 found, each kept from
 * coming back: "Give them the held fish" never puts a wrong fish on a
 * line nor gives an account's hold to somebody else; a paid order takes
 * its customer's held fish even while an older unpaid order of theirs
 * waits; a part of a group never moves off with fish held by hand; a
 * shipments read that fails is never "nothing shipped"; fish on open
 * invoices are not reserved again by hand; a hold shrinks the moment its
 * fish die; the hold is closed before its fish are written to the order;
 * only somebody who may see the shop's customers picks an account; a
 * taken hold leaves the list and is said ("taken by order #N", on the
 * order too); nothing ready reads "all 4 reserved" with a RESERVED badge;
 * a notice is shown once; the phone's hand "Shipped" takes the hold down
 * in the same step.
 *
 * Runs on the stand-in shop (fake-shop-orders.php) and the in-memory
 * store: invented orders, fish, customers and ids.
 *
 * Run from the plugin root:  php includes/sections/clean-room/tests/reserve-review-test.php
 */

namespace FisHotel\Misc\Sections\Invoices {
	/**
	 * A stand-in for the Invoices section's store: the fish of each tank
	 * group on its open invoices (drafts and published, not paid).
	 */
	class Store {
		public static $open = array();
		public static function ready() {
			return true;
		}
		public function stay_reservations( array $stay_ids, $exclude = 0 ) {
			return array_intersect_key( self::$open, array_flip( array_map( 'intval', $stay_ids ) ) );
		}
	}
}

namespace {
	require_once __DIR__ . '/wp-stubs.php';
	require_once __DIR__ . '/fake-shop-orders.php';

	use FisHotel\Misc\Sections\Clean_Room\Admin_Workbook;
	use FisHotel\Misc\Sections\Clean_Room\Front_Desk;
	use FisHotel\Misc\Sections\Clean_Room\Holds;
	use FisHotel\Misc\Sections\Clean_Room\Replay;
	use FisHotel\Misc\Sections\Clean_Room\Reserve;
	use FisHotel\Misc\Sections\Clean_Room\Reserve_Shop;
	use FisHotel\Misc\Sections\Clean_Room\Settings;
	use FisHotel\Misc\Sections\Clean_Room\Ship_Lines;
	use FisHotel\Misc\Sections\Clean_Room\Store;
	use FisHotel\Misc\Sections\Clean_Room\Tests\Fake_Store;

	$GLOBALS['fh_now'] = '2026-04-01 09:00:00';
	$today             = (string) current_time( 'Y-m-d' );
	$tomorrow          = Replay::add_days( $today, 1 );
	$settings          = Settings::get();
	$protocols         = Settings::protocols();

	add_filter( 'fishotel_cleanroom_batch_present', 'fh_rv_true' );
	function fh_rv_true() {
		return true;
	}

	// The shop's customers (invented).
	$GLOBALS['fh_users'] = array(
		42 => (object) array( 'ID' => 42, 'user_email' => 'ann.sample@example.test', 'display_name' => 'annb' ),
		43 => (object) array( 'ID' => 43, 'user_email' => 'otto.demo@example.test', 'display_name' => 'ottod' ),
	);
	$GLOBALS['fh_user_meta'][42] = array( 'billing_first_name' => 'Ann', 'billing_last_name' => 'Bsample', 'billing_email' => 'ann.sample@example.test' );
	$GLOBALS['fh_user_meta'][43] = array( 'billing_first_name' => 'Otto', 'billing_last_name' => 'Demo', 'billing_email' => 'otto.demo@example.test' );
	function get_userdata( $id ) {
		return $GLOBALS['fh_users'][ (int) $id ] ?? false;
	}
	// The person working may see the shop's customers.
	$GLOBALS['fh_current_user']                    = 7;
	$GLOBALS['fh_user_caps']['edit_shop_orders'][] = 7;

	function rv_place( Fake_Store $store, $tank_number, $species, $qty, array $more = array() ) {
		global $today, $settings;
		$tank = $store->tank_by_number( $tank_number );
		$date = Replay::add_days( $today, -40 );
		$id   = $store->create_stay( array_merge( array( 'species' => $species, 'checkin_date' => $date, 'tank_id' => (int) $tank['id'] ), $more ) );
		$store->record_event( $id, array( 'type' => 'arrival', 'qty' => $qty, 'to_tank_id' => (int) $tank['id'], 'event_at' => Front_Desk::event_at_for_date( $date, $today ) ), $today, $settings );
		$store->record_event( $id, array( 'type' => 'note', 'note' => Replay::NOTE_SALE_READY . Replay::add_days( $today, -10 ), 'event_at' => gmdate( 'Y-m-d H:i:s' ) ), $today, $settings );
		return $id;
	}
	function rv_alive( Fake_Store $store, $stay_id ) {
		global $today, $settings;
		return (int) $store->stay( $stay_id, $today, $settings )['qty_live'];
	}
	function rv_held( $item ) {
		return Reserve::held( $item->get_meta( Reserve::META ) );
	}
	function rv_fresh() {
		Reserve_Shop::reset();
		Reserve_Shop::flush();
		Ship_Lines::flush();
	}
	function rv_counts( Fake_Store $store ) {
		global $settings;
		rv_fresh();
		return Reserve_Shop::ensure( $store, $settings )['counts'];
	}
	function rv_ctx( Fake_Store $store, $tank ) {
		global $settings, $today;
		return array( 'store' => $store, 'settings' => $settings, 'today' => $today, 'tank' => $store->tank_by_number( $tank ), 'base' => array( 'actor_id' => 7, 'source' => 'qr' ) );
	}
	function rv_book() {
		return Holds::book( get_option( Holds::OPTION, array() ) );
	}
	function rv_hold_for( $who ) {
		foreach ( Holds::open( rv_book() ) as $h ) {
			if ( $who === $h['who'] ) {
				return (int) $h['id'];
			}
		}
		return 0;
	}
	function rv_release_all() {
		foreach ( Holds::open( rv_book() ) as $h ) {
			Reserve_Shop::hold_release( $h['id'] );
		}
	}
	function rv_occupant( Fake_Store $store, $tank, $stay_id ) {
		global $settings, $today;
		rv_fresh();
		foreach ( Front_Desk::screen_from( $store, $tank, $settings, $today )['tank']['occupants'] as $o ) {
			if ( (int) $o['stayId'] === (int) $stay_id ) {
				return $o;
			}
		}
		return null;
	}

	new Fh_Shop_Product( 501, 'Test Tang', array( 'quarantined-fish' ) );
	$tang_m = new Fh_Shop_Product( 502, 'Test Tang - Medium', array(), 501, array( 'pa_size' => 'medium' ) );
	$medium = array( 'pa_size' => 'medium' );
	$goby   = new Fh_Shop_Product( 601, 'Mock Goby', array( 'quarantined-fish' ) );
	$t5     = array( 'product_id' => 501, 'size_slug' => 'medium', 'size_label' => 'Medium' );

	$store = new Fake_Store();
	$s121  = rv_place( $store, 121, 'Test Tang', 5, $t5 );
	$s131  = rv_place( $store, 111, 'Mock Goby', 4, array( 'product_id' => 601, 'size_slug' => 'small', 'size_label' => 'Small' ) );

	/* ------------------------------------------------------------------ */
	echo "1. \"Give them the held fish\" never puts a wrong fish on a line\n";
	Front_Desk::perform( 'hold', rv_ctx( $store, 121 ), array( 'stay_id' => $s121, 'qty' => 2, 'who' => 'Mike S.' ) );
	$o2100 = fh_shop_order( new WC_Order( 2100, 'processing', array( '_fishotel_shipping_date' => $tomorrow, '_customer_user' => 43, '_billing_email' => 'otto.demo@example.test', '_paid_date' => (string) time() ), time() + 60 ) );
	$i2100 = $o2100->add_item( 'Mock Goby', 1, $goby, array() );
	rv_counts( $store );
	$before = rv_held( $i2100 );
	$mike   = rv_hold_for( 'Mike S.' );
	$r      = Reserve_Shop::act_result( $store, $settings, array( 'op' => 'give', 'hold_id' => $mike, 'order_id' => 2100, 'ref' => 'i:' . $i2100->get_id() ) );
	check_same( 'a stale or hand-made request giving the tangs held for Mike S. to a GOBY line: refused, said why', array( false, true ), array( $r['ok'], false !== strpos( $r['message'], 'not the held fish' ) ) );
	check_same( '… the goby line keeps what it had — no tang in tank 121 on it — and the hold is still open with its 2', array( $before, false, 2 ), array( rv_held( $i2100 ), isset( rv_held( $i2100 )[ $s121 ] ), (int) rv_book()['holds'][ $mike ]['qty'] ) );
	$o2100->set_status( 'cancelled' );
	rv_release_all();

	/* ------------------------------------------------------------------ */
	echo "2. An account's hold is never given to somebody else's order\n";
	Reserve_Shop::act( $store, $settings, array( 'op' => 'hold', 'stay_id' => $s121, 'qty' => 1, 'user_id' => 42 ) );
	$ann   = rv_hold_for( 'Ann B.' );
	$o2200 = fh_shop_order( new WC_Order( 2200, 'on-hold', array( '_fishotel_shipping_date' => $tomorrow, '_customer_user' => 43, '_billing_email' => 'otto.demo@example.test' ), time() + 120 ) );
	$i2200 = $o2200->add_item( 'Test Tang - Medium', 1, $tang_m, $medium );
	rv_counts( $store );
	$r = Reserve_Shop::act_result( $store, $settings, array( 'op' => 'give', 'hold_id' => $ann, 'order_id' => 2200, 'ref' => 'i:' . $i2200->get_id() ) );
	check_same( 'Ann\'s account hold given to Otto\'s order by a hand-made request: refused, said whose it is', array( false, true ), array( $r['ok'], false !== strpos( $r['message'], 'Ann B.' ) ) );
	check_same( '… Ann\'s hold still open and still holding her 1', array( true, 1 ), array( empty( rv_book()['holds'][ $ann ]['closed'] ), (int) rv_counts( $store )[ $s121 ]['hand'] ) );
	$o2210 = fh_shop_order( new WC_Order( 2210, 'on-hold', array( '_fishotel_shipping_date' => $tomorrow, '_customer_user' => 42, '_billing_email' => 'ann.sample@example.test' ), time() + 130 ) );
	$i2210 = $o2210->add_item( 'Test Tang - Medium', 1, $tang_m, $medium );
	rv_counts( $store );
	$r = Reserve_Shop::act_result( $store, $settings, array( 'op' => 'give', 'hold_id' => $ann, 'order_id' => 2210, 'ref' => 'i:' . $i2210->get_id() ) );
	check_same( '… given to her OWN order (not paid yet) by hand: it goes, the singular said right', array( true, 'The fish held for Ann B. now goes to order #2210.' ), array( $r['ok'], $r['message'] ) );
	check_same( '… the line holds her fish in 121, the hold closed "taken by order #2210"', array( array( $s121 => 1 ), 'taken by order #2210' ), array( rv_held( $i2210 ), rv_book()['holds'][ $ann ]['why'] ) );
	check_contains( '… and the order says so in a note: whose held fish, from which tank', 'held by hand for Ann B.', implode( ' | ', $GLOBALS['fh_order_notes'][2210] ?? array() ) );
	foreach ( array( $o2200, $o2210 ) as $o ) {
		$o->set_status( 'cancelled' );
	}

	/* ------------------------------------------------------------------ */
	echo "3. A paid order takes its customer's held fish though an older unpaid order of theirs waits\n";
	$s141 = rv_place( $store, 122, 'Test Tang', 5, $t5 );
	rv_fresh();
	Reserve_Shop::act( $store, $settings, array( 'op' => 'hold', 'stay_id' => $s141, 'qty' => 5, 'user_id' => 42 ) );
	$ann   = rv_hold_for( 'Ann B.' );
	$o2300 = fh_shop_order( new WC_Order( 2300, 'on-hold', array( '_fishotel_shipping_date' => $tomorrow, '_customer_user' => 42, '_billing_email' => 'ann.sample@example.test' ), time() + 200 ) );
	$i2300 = $o2300->add_item( 'Test Tang - Medium', 5, $tang_m, $medium );
	$o2301 = fh_shop_order( new WC_Order( 2301, 'processing', array( '_fishotel_shipping_date' => $tomorrow, '_customer_user' => 42, '_billing_email' => 'ann.sample@example.test', '_paid_date' => (string) time() ), time() + 260 ) );
	$i2301 = $o2301->add_item( 'Test Tang - Medium', 5, $tang_m, $medium );
	$c     = rv_counts( $store );
	check_same( 'the paid #2301 takes the 5 held for her in 141 — not "tank not known" while they sit there', array( $s141 => 5 ), rv_held( $i2301 ) );
	check_same( '… the older unpaid #2300 gets none of them; the hold closed "taken by order #2301"', array( false, 'taken by order #2301' ), array( isset( rv_held( $i2300 )[ $s141 ] ), rv_book()['holds'][ $ann ]['why'] ) );
	check_same( '… never more than alive in 141', true, (int) $c[ $s141 ]['qty'] <= rv_alive( $store, $s141 ) );
	$o2300->set_status( 'cancelled' );
	$o2301->set_status( 'cancelled' );

	/* ------------------------------------------------------------------ */
	echo "4. A part of a group never moves off with fish held by hand\n";
	rv_release_all();
	$s151 = rv_place( $store, 123, 'Test Tang', 5, $t5 );
	rv_fresh();
	Front_Desk::perform( 'hold', rv_ctx( $store, 123 ), array( 'stay_id' => $s151, 'qty' => 3, 'who' => 'Mike S.' ) );
	rv_fresh();
	$r = Front_Desk::perform( 'move', rv_ctx( $store, 123 ), array( 'stay_id' => $s151, 'to_tank' => 211, 'qty' => 4 ) );
	check_contains( 'move 4 of 5 with 3 held for Mike S.: refused, saying at most 2 may move', 'move at most 2', (string) ( $r['error'] ?? '' ) );
	check_same( '… nothing moved, the hold whole', array( 5, 3 ), array( rv_alive( $store, $s151 ), (int) rv_counts( $store )[ $s151 ]['hand'] ) );
	rv_fresh();
	$r = Front_Desk::perform( 'move', rv_ctx( $store, 123 ), array( 'stay_id' => $s151, 'to_tank' => 211, 'qty' => 2 ) );
	check_same( 'move the 2 nothing holds: it goes, the hold keeps its 3', array( '', 3, 3 ), array( (string) ( $r['error'] ?? '' ), rv_alive( $store, $s151 ), (int) rv_counts( $store )[ $s151 ]['hand'] ) );
	rv_fresh();
	$r = Front_Desk::perform( 'move', rv_ctx( $store, 123 ), array( 'stay_id' => $s151, 'to_tank' => 212, 'qty' => 3 ) );
	check_same( 'the whole group (3 of 3): it moves, its hold with it', array( '', 212, 3 ), array( (string) ( $r['error'] ?? '' ), (int) $store->stay( $s151, $today, $settings )['tank_number'], (int) rv_counts( $store )[ $s151 ]['hand'] ) );
	check_same( 'Holds::move_refuse(): no hold, nothing refused (an order\'s reservation stays with its group, as before)', '', Holds::move_refuse( 4, 4, array(), 3 ) );
	rv_release_all();

	/* ------------------------------------------------------------------ */
	echo "5. A shipments read that fails is never \"nothing shipped\"\n";
	class FST_Shipment {
		public static function table() {
			return 'wp_fst_shipments';
		}
		public static function get_by_order( $id ) {
			return array();
		}
	}
	$GLOBALS['wpdb'] = new class() {
		public $last_error = '';
		public function get_results( $sql, $output = null ) {
			$this->last_error = "Unknown column 'line_item_ids' in 'field list'";
			return array(); // What WordPress answers for a failed query.
		}
	};
	check_same( 'a ShipTracker before 1.9 (no line_item_ids column): not primed — each order is read on its own, as before', null, Store::shipment_rows( array( 2100, 2200 ) ) );
	$GLOBALS['wpdb'] = new class() {
		public $last_error = '';
		public function get_results( $sql, $output = null ) {
			$this->last_error = '';
			return array( (object) array( 'order_id' => 2100, 'line_item_ids' => null ) );
		}
	};
	check_same( '… a read that works is used: order id => its rows', array( 2100 => 1, 2200 => 0 ), array_map( 'count', Store::shipment_rows( array( 2100, 2200 ) ) ) );
	unset( $GLOBALS['wpdb'] );

	/* ------------------------------------------------------------------ */
	echo "6. Fish on open invoices are not reserved again by hand\n";
	$s171 = rv_place( $store, 321, 'Test Tang', 5, $t5 );
	\FisHotel\Misc\Sections\Invoices\Store::$open = array( $s171 => 2 );
	rv_fresh();
	$r = Front_Desk::perform( 'hold', rv_ctx( $store, 321 ), array( 'stay_id' => $s171, 'qty' => 4, 'who' => 'Phone caller' ) );
	check_contains( '5 alive, 2 on an open invoice: 4 by hand refused — only 3 are ready', 'Only 3 are ready', (string) ( $r['error'] ?? '' ) );
	rv_fresh();
	$r = Front_Desk::perform( 'hold', rv_ctx( $store, 321 ), array( 'stay_id' => $s171, 'qty' => 3, 'who' => 'Phone caller' ) );
	check_same( '… 3 may be', '', (string) ( $r['error'] ?? '' ) );
	\FisHotel\Misc\Sections\Invoices\Store::$open = array();
	rv_release_all();

	/* ------------------------------------------------------------------ */
	echo "7. A hold shrinks the moment its fish die (never an hour later)\n";
	new Fh_Shop_Product( 701, 'Test Wrasse', array( 'quarantined-fish' ) );
	$wrasse_m = new Fh_Shop_Product( 702, 'Test Wrasse - Medium', array(), 701, array( 'pa_size' => 'medium' ) );
	$s181     = rv_place( $store, 322, 'Test Wrasse', 5, array( 'product_id' => 701, 'size_slug' => 'medium', 'size_label' => 'Medium' ) );
	rv_fresh();
	Front_Desk::perform( 'hold', rv_ctx( $store, 322 ), array( 'stay_id' => $s181, 'qty' => 3, 'who' => 'Mike S.' ) );
	$o2500 = fh_shop_order( new WC_Order( 2500, 'processing', array( '_fishotel_shipping_date' => $tomorrow, '_customer_user' => 43, '_paid_date' => (string) time() ), time() + 400 ) );
	$o2500->add_item( 'Test Wrasse - Medium', 2, $wrasse_m, $medium );
	check_same( 'orders 2 + hold 3 = 5 alive', array( 5, 3 ), ( static function () use ( $store, $s181 ) { $c = rv_counts( $store ); return array( (int) $c[ $s181 ]['qty'], (int) $c[ $s181 ]['hand'] ); } )() );
	Reserve_Shop::reset(); // A new request: the counts come from the cache (the transient).
	$store->record_event( $s181, array( 'type' => 'loss', 'qty' => 2, 'event_at' => gmdate( 'Y-m-d H:i:s' ) ), $today, $settings );
	Reserve_Shop::flush_for_event( $s181, 0, array( 'type' => 'loss' ) ); // What the Clean Room's event hook calls.
	Reserve_Shop::reset();
	$c = Reserve_Shop::ensure( $store, $settings )['counts'];
	check_same( '2 died: the next read (no hour waited) has the order\'s 2 and the hold shrunk to 1 — 3 alive', array( 3, 1, 3 ), array( (int) $c[ $s181 ]['qty'], (int) $c[ $s181 ]['hand'], rv_alive( $store, $s181 ) ) );
	set_transient( Reserve_Shop::TRANSIENT, array( 'counts' => array( 99 => array( 'qty' => 1 ) ) ), 60 );
	Reserve_Shop::reset();
	Reserve_Shop::flush_for_event( $s131, 0, array( 'type' => 'loss' ) );
	check_same( '… a loss in a group with no hold keeps the cache (the phone logs many)', true, false !== get_transient( Reserve_Shop::TRANSIENT ) );
	$o2500->set_status( 'cancelled' );
	rv_release_all();

	/* ------------------------------------------------------------------ */
	echo "8. The hold is closed before its fish are written to the order\n";
	new Fh_Shop_Product( 711, 'Test Angel', array( 'quarantined-fish' ) );
	$angel_m = new Fh_Shop_Product( 712, 'Test Angel - Medium', array(), 711, array( 'pa_size' => 'medium' ) );
	$s191    = rv_place( $store, 311, 'Test Angel', 3, array( 'product_id' => 711, 'size_slug' => 'medium', 'size_label' => 'Medium' ) );
	rv_fresh();
	Reserve_Shop::act( $store, $settings, array( 'op' => 'hold', 'stay_id' => $s191, 'qty' => 2, 'user_id' => 42 ) );
	$ann   = rv_hold_for( 'Ann B.' );
	$o2600 = fh_shop_order( new WC_Order( 2600, 'processing', array( '_fishotel_shipping_date' => $tomorrow, '_customer_user' => 42, '_billing_email' => 'ann.sample@example.test', '_paid_date' => (string) time() ), time() + 500 ) );
	$i2600 = $o2600->add_item( 'Test Angel - Medium', 2, $angel_m, $medium );
	$GLOBALS['fh_item_meta_fail'] = 1; // The request dies at its first item meta write.
	rv_fresh();
	try {
		Reserve_Shop::ensure( $store, $settings );
	} catch ( \RuntimeException $e ) {
		unset( $e );
	}
	check_same( 'it died between the two: the hold is closed ("taken by order #2600"), the line not written yet', array( 'taken by order #2600', array() ), array( rv_book()['holds'][ $ann ]['why'], rv_held( $i2600 ) ) );
	$c = rv_counts( $store );
	check_same( '… read again: the line is reserved in 191 once — never the same fish for the hold and the order', array( array( $s191 => 2 ), 2, 0 ), array( rv_held( $i2600 ), (int) $c[ $s191 ]['qty'], (int) ( $c[ $s191 ]['hand'] ?? 0 ) ) );
	$o2600->set_status( 'cancelled' );

	/* ------------------------------------------------------------------ */
	echo "9. Only somebody who may see the shop's customers picks an account\n";
	$GLOBALS['fh_current_user'] = 8; // Clean Room staff, not allowed to see the shop's customers.
	check_same( 'the customer search answers nothing', array(), Reserve_Shop::customers( 'ann' ) );
	$s201 = rv_place( $store, 312, 'Test Tang', 3, $t5 );
	rv_fresh();
	$r = Reserve_Shop::act_result( $store, $settings, array( 'op' => 'hold', 'stay_id' => $s201, 'qty' => 1, 'user_id' => 42 ) );
	check_same( 'a hold posted with an account id: refused — type a name instead', array( false, true ), array( $r['ok'], false !== strpos( $r['message'], 'type a name' ) ) );
	$r = Reserve_Shop::act_result( $store, $settings, array( 'op' => 'hold', 'stay_id' => $s201, 'qty' => 1, 'who' => 'Ann from the forum' ) );
	check_same( '… a name typed still works', true, $r['ok'] );
	$GLOBALS['fh_current_user'] = 7;
	rv_release_all();

	/* ------------------------------------------------------------------ */
	echo "10. A taken hold leaves the list and is said\n";
	$s211 = rv_place( $store, 221, 'Test Tang', 4, $t5 );
	rv_fresh();
	Reserve_Shop::act( $store, $settings, array( 'op' => 'hold', 'stay_id' => $s211, 'qty' => 2, 'user_id' => 42 ) );
	$o2700 = fh_shop_order( new WC_Order( 2700, 'processing', array( '_fishotel_shipping_date' => $tomorrow, '_customer_user' => 42, '_billing_email' => 'ann.sample@example.test', '_paid_date' => (string) time() ), time() + 600 ) );
	$i2700 = $o2700->add_item( 'Test Tang - Medium', 2, $tang_m, $medium );
	rv_fresh();
	$todo = $store->room( $today, $settings, $protocols )['todo'];
	check_same( 'Ann\'s paid order took her 2: no longer under "Held by hand" — on this read already', array(), array_values( array_filter( $todo['holds'], static function ( $h ) { return 'Ann B.' === $h['who']; } ) ) );
	Reserve_Shop::reset();
	$todo = $store->room( $today, $settings, $protocols )['todo'];
	check_same( '… nor on the next (from the cache)', array(), array_values( array_filter( $todo['holds'], static function ( $h ) { return 'Ann B.' === $h['who']; } ) ) );
	check_same( '… listed under "Ended lately" with why: taken by order #2700', array( 'Ann B.', 2, 221, 'taken by order #2700' ), array( $todo['closed'][0]['who'], $todo['closed'][0]['qty'], $todo['closed'][0]['tank'], $todo['closed'][0]['why'] ) );
	check_contains( '… and the order has a note: whose held fish it took, from which tank', 'Clean Room: 2 × Test Tang Medium in tank 221, held by hand for Ann B.', implode( ' | ', $GLOBALS['fh_order_notes'][2700] ?? array() ) );
	check_same( 'the line holds them', array( $s211 => 2 ), rv_held( $i2700 ) );

	/* ------------------------------------------------------------------ */
	echo "11. Nothing ready: \"all 2 reserved\" and a RESERVED badge — on the phone too\n";
	$s221 = rv_place( $store, 222, 'Test Tang', 2, $t5 );
	rv_fresh();
	Front_Desk::perform( 'hold', rv_ctx( $store, 222 ), array( 'stay_id' => $s221, 'qty' => 2, 'who' => 'Maria T.' ) );
	$o = rv_occupant( $store, 222, $s221 );
	check_same( 'the phone: "all 2 reserved (Maria T. — by hand, today)", badge reserved', array( 'all 2 reserved (Maria T. — by hand, today)', 'reserved', 'reserved' ), array( $o['reservedText'], $o['badge'], $o['stateLabel'] ) );
	check_same( '… a group with fish ready keeps its READY badge', 'ready', rv_occupant( $store, 221, $s211 )['badge'] );

	/* ------------------------------------------------------------------ */
	echo "12. Words\n";
	$s231 = rv_place( $store, 112, 'Test Tang', 3, $t5 );
	rv_fresh();
	$r = Front_Desk::perform( 'hold', rv_ctx( $store, 112 ), array( 'stay_id' => $s231, 'qty' => 1, 'who' => 'Maria T.' ) );
	check_same( 'a name ending in a full stop: one full stop, not two', '1 × Test Tang in tank 112 reserved for Maria T.', (string) ( $r['message'] ?? '' ) );
	check_same( 'Holds::sentence()', array( 'For Mike S.', 'For Mike S.', '' ), array( Holds::sentence( 'For Mike S.' ), Holds::sentence( 'For Mike S' ), Holds::sentence( '' ) ) );
	check_same( 'a hold whose fish all died: said plainly', 'none of its fish is left in the tank — release it', Holds::short_text( 0 ) );
	$js = (string) file_get_contents( FISHOTEL_MISC_PATH . 'includes/sections/clean-room/js/front-desk.js' );
	check( 'the phone\'s Reserve… label: "How many (1 ready)" — not "HOW MANY? OF 1 READY"', false !== strpos( $js, "fmt(t('holdHowMany', 'How many (%d ready)'), o.ready)" ) && false === strpos( $js, "t('howMany', 'How many?') + ' ' + fmt(t('readyOf'" ) );
	check( '… and under the name, always: tied to an account, or a name typed (picking then editing says so)', false !== strpos( $js, 'showTie()' ) && false !== strpos( (string) file_get_contents( FISHOTEL_MISC_PATH . 'includes/sections/clean-room/js/clean-room-admin.js' ), 'holdTie($form)' ) );
	$css = (string) file_get_contents( FISHOTEL_MISC_PATH . 'includes/sections/clean-room/css/clean-room-admin.css' );
	check( '"Ended lately" is readable on the dark To do card (its own colour), and no "0 holds" header', (bool) preg_match( '/\.fh-cr-holds__closed-head \{[^}]*color:/', (string) file_get_contents( FISHOTEL_MISC_PATH . 'includes/sections/clean-room/css/clean-room-admin.css' ) ) && false !== strpos( (string) file_get_contents( FISHOTEL_MISC_PATH . 'includes/sections/clean-room/views/holds.php' ), "'none held now'" ) );
	check( 'the Board\'s "For whom" box keeps inside its card', (bool) preg_match( '/\.fh-cr-hold__form input\[type="text"\] \{[^}]*max-width: 100%;[^}]*box-sizing: border-box;/', $css ) );

	/* ------------------------------------------------------------------ */
	echo "13. A notice is shown once — green when something was done\n";
	Admin_Workbook::flash( 'Released: the fish held for Mike S. is ready again.', true );
	$flash = Admin_Workbook::take_flash();
	$tab   = 'board';
	ob_start();
	include FISHOTEL_MISC_PATH . 'includes/sections/clean-room/views/tabs.php';
	$html = (string) ob_get_clean();
	check_same( 'one notice, green', array( 1, 1 ), array( substr_count( $html, 'class="fishotel-misc-notice ' ), substr_count( $html, 'fishotel-misc-notice--success' ) ) );
	Admin_Workbook::flash( 'That hold is no longer there.' );
	$flash = Admin_Workbook::take_flash();
	ob_start();
	include FISHOTEL_MISC_PATH . 'includes/sections/clean-room/views/tabs.php';
	check_contains( '… a refusal stays yellow', 'fishotel-misc-notice--warning', (string) ob_get_clean() );
	foreach ( array( 'board', 'todo' ) as $view ) {
		check( 'views/' . $view . '.php prints no notice of its own (tabs.php does)', false === strpos( (string) file_get_contents( FISHOTEL_MISC_PATH . 'includes/sections/clean-room/views/' . $view . '.php' ), 'esc_html( $flash )' ) );
	}

	/* ------------------------------------------------------------------ */
	echo "14. The phone's hand \"Shipped\": the hold goes down in the same step\n";
	$s241 = rv_place( $store, 123, 'Test Tang', 4, $t5 );
	rv_fresh();
	Front_Desk::perform( 'hold', rv_ctx( $store, 123 ), array( 'stay_id' => $s241, 'qty' => 3, 'who' => 'Forum fan' ) );
	$fan = rv_hold_for( 'Forum fan' );
	$o   = rv_occupant( $store, 123, $s241 );
	check_same( 'the hand "Shipped" offers each hold to tick (who, how many)', array( array( $fan, 'Forum fan', 3 ) ), array_map( static function ( $h ) { return array( $h['id'], $h['who'], $h['qty'] ); }, $o['holds'] ) );
	rv_fresh();
	$r = Front_Desk::perform( 'sold', rv_ctx( $store, 123 ), array( 'stay_id' => $s241, 'qty' => 2, 'hold_ids' => array( $fan ) ) );
	check_contains( 'shipped 2, ticked: said', 'the hold by hand went down by 2', (string) ( $r['message'] ?? '' ) );
	check_same( '… 2 alive, the hold down to 1 — one step', array( 2, 1, 1 ), array( rv_alive( $store, $s241 ), (int) rv_book()['holds'][ $fan ]['qty'], (int) rv_counts( $store )[ $s241 ]['hand'] ) );
	rv_fresh();
	Front_Desk::perform( 'sold', rv_ctx( $store, 123 ), array( 'stay_id' => $s241, 'qty' => 1, 'hold_ids' => array( $fan ) ) );
	check_same( '… the last one: the hold closes "shipped by hand"', 'shipped by hand', rv_book()['holds'][ $fan ]['why'] );
	rv_fresh();
	$s251 = rv_place( $store, 211, 'Test Tang', 4, $t5 );
	rv_fresh();
	Front_Desk::perform( 'hold', rv_ctx( $store, 211 ), array( 'stay_id' => $s251, 'qty' => 2, 'who' => 'Forum fan 2' ) );
	rv_fresh();
	Front_Desk::perform( 'sold', rv_ctx( $store, 211 ), array( 'stay_id' => $s251, 'qty' => 1 ) );
	check_same( 'not ticked: the hold is left as it was', 2, (int) rv_book()['holds'][ rv_hold_for( 'Forum fan 2' ) ]['qty'] );

	fh_finish();
}
