<?php
/**
 * The first read after an order changed (Reserved → Pull today → Shipped)
 * asks ShipTracker's shipments and the Requests section's offers ONCE for
 * all the open orders — not one or two queries per order — and works out
 * exactly the same reservations, pull list and holds as reading them one
 * order at a time.
 *
 * A stand-in shop (fake-shop-orders.php), a stand-in $wpdb answering the
 * one shipments query, a stand-in Requests store; invented orders and fish.
 *
 * Run from the plugin root:  php includes/sections/clean-room/tests/reserve-queries-test.php
 */

namespace FisHotel\Misc\Sections\Requests {
	/**
	 * Stand-in for the Requests store: counts how it is asked.
	 */
	class Store {
		public static $finds   = 0;
		public static $batched = 0;
		public static $rows    = array();
		public static function ready() {
			return true;
		}
		public function find( array $filters = array() ) {
			self::$finds++;
			$out = array();
			foreach ( self::$rows as $row ) {
				if ( ( isset( $filters['user_id'] ) && (int) $filters['user_id'] === (int) $row['user_id'] ) || ( isset( $filters['email'] ) && (string) $filters['email'] === (string) $row['email'] ) ) {
					$out[] = $row;
				}
			}
			return $out;
		}
		public function offered_to_stays( array $stay_ids ) {
			self::$batched++;
			return array_values( array_filter( self::$rows, static function ( $row ) use ( $stay_ids ) { return in_array( (int) $row['stay_id'], array_map( 'intval', $stay_ids ), true ); } ) );
		}
	}
}

namespace {
	require_once __DIR__ . '/wp-stubs.php';
	require_once __DIR__ . '/fake-shop-orders.php';

	use FisHotel\Misc\Sections\Clean_Room\Front_Desk;
	use FisHotel\Misc\Sections\Clean_Room\Replay;
	use FisHotel\Misc\Sections\Clean_Room\Reserve;
	use FisHotel\Misc\Sections\Clean_Room\Reserve_Shop;
	use FisHotel\Misc\Sections\Clean_Room\Settings;
	use FisHotel\Misc\Sections\Clean_Room\Ship_Lines;
	use FisHotel\Misc\Sections\Clean_Room\Tests\Fake_Store;
	use FisHotel\Misc\Sections\Lost_Fish\Orders;
	use FisHotel\Misc\Sections\Requests\Store as Requests_Store;

	/**
	 * ShipTracker's shipments: one order at a time (counted), and its table's name.
	 */
	class FST_Shipment {
		public static $single = 0;
		public static function get_by_order( $order_id ) {
			self::$single++;
			return $GLOBALS['fh_shipments'][ (int) $order_id ] ?? array();
		}
		public static function table() {
			return 'wp_fst_shipments';
		}
	}
	$GLOBALS['fh_shipments'] = array();

	/**
	 * Just enough of $wpdb to answer the one shipments query (counted).
	 */
	class Fh_Wpdb {
		public $queries = 0;
		public function get_results( $sql, $output = null ) {
			$this->queries++;
			if ( ! preg_match( '/FROM wp_fst_shipments WHERE order_id IN \(([\d,]+)\)/', (string) $sql, $m ) ) {
				return array();
			}
			$out = array();
			foreach ( array_map( 'intval', explode( ',', $m[1] ) ) as $id ) {
				foreach ( $GLOBALS['fh_shipments'][ $id ] ?? array() as $row ) {
					$out[] = (object) array( 'order_id' => $id, 'line_item_ids' => $row->line_item_ids );
				}
			}
			return $out;
		}
	}

	$GLOBALS['fh_now'] = '2026-04-01 09:00:00';
	$today             = (string) current_time( 'Y-m-d' );
	$tomorrow          = Replay::add_days( $today, 1 );
	$settings          = Settings::get();
	$protocols         = Settings::protocols();
	add_filter( 'fishotel_cleanroom_batch_present', 'fh_q_true' );
	function fh_q_true() {
		return true;
	}

	function fh_q_place( Fake_Store $store, $tank, $species, $qty, array $more ) {
		global $today, $settings;
		$row  = $store->tank_by_number( $tank );
		$date = Replay::add_days( $today, -40 );
		$id   = $store->create_stay( array_merge( array( 'species' => $species, 'checkin_date' => $date, 'tank_id' => (int) $row['id'] ), $more ) );
		$store->record_event( $id, array( 'type' => 'arrival', 'qty' => $qty, 'to_tank_id' => (int) $row['id'], 'event_at' => Front_Desk::event_at_for_date( $date, $today ) ), $today, $settings );
		$store->record_event( $id, array( 'type' => 'note', 'note' => Replay::NOTE_SALE_READY . Replay::add_days( $today, -10 ), 'event_at' => gmdate( 'Y-m-d H:i:s' ) ), $today, $settings );
		return $id;
	}

	/**
	 * One cold read (the transients dropped), as the Board after an order change: what it worked out and what it asked.
	 */
	function fh_q_cold( Fake_Store $store ) {
		global $today, $settings, $protocols;
		Reserve_Shop::reset();
		Reserve_Shop::flush();
		Ship_Lines::flush();
		FST_Shipment::$single     = 0;
		Requests_Store::$finds    = 0;
		Requests_Store::$batched  = 0;
		$GLOBALS['fh_shop_loads'] = 0;
		if ( isset( $GLOBALS['wpdb'] ) && is_object( $GLOBALS['wpdb'] ) ) {
			$GLOBALS['wpdb']->queries = 0;
		}
		$room = $store->room( $today, $settings, $protocols );
		return array(
			'counts'  => Reserve_Shop::ensure( $store, $settings )['counts'],
			'pull'    => $room['todo']['pull']['buildings'],
			'single'  => FST_Shipment::$single,
			'batched' => isset( $GLOBALS['wpdb'] ) && is_object( $GLOBALS['wpdb'] ) ? $GLOBALS['wpdb']->queries : 0,
			'finds'   => Requests_Store::$finds,
			'offers'  => Requests_Store::$batched,
		);
	}

	$tang_m = new Fh_Shop_Product( 502, 'Test Tang - Medium', array(), 501, array( 'pa_size' => 'medium' ) );
	new Fh_Shop_Product( 501, 'Test Tang', array( 'quarantined-fish' ) );
	$goby = new Fh_Shop_Product( 801, 'Mock Goby', array( 'quarantined-fish' ) );

	$store = new Fake_Store();
	$s121  = fh_q_place( $store, 121, 'Test Tang', 12, array( 'product_id' => 501, 'size_slug' => 'medium', 'size_label' => 'Medium' ) );
	$s221  = fh_q_place( $store, 221, 'Test Tang', 12, array( 'product_id' => 501, 'size_slug' => 'medium', 'size_label' => 'Medium' ) );

	// 30 open orders for tomorrow: 20 tangs (reserved), 10 gobies (in no tank: asked again on every read), 4 shipped in part.
	$n = 30;
	for ( $i = 0; $i < $n; $i++ ) {
		$id    = 3000 + $i;
		$order = fh_shop_order( new WC_Order( $id, 'processing', array( '_fishotel_shipping_date' => $tomorrow, '_customer_user' => 100 + $i, '_billing_email' => 'buyer' . $i . '@example.test' ), time() - 3600 + $i ) );
		$item  = $i < 20 ? $order->add_item( 'Test Tang - Medium', 1, $tang_m, array( 'pa_size' => 'medium' ) ) : $order->add_item( 'Mock Goby', 1, $goby, array( 'pa_size' => 'small' ) );
		if ( $i < 4 ) {
			$GLOBALS['fh_shipments'][ $id ][] = (object) array( 'line_item_ids' => json_encode( array( $item->get_id() ) ) );
		}
	}
	// One customer was offered a group by a request.
	Requests_Store::$rows = array( array( 'product_id' => 501, 'stay_id' => $s221, 'user_id' => 105, 'email' => 'buyer5@example.test', 'state' => 'offered' ) );

	echo "1. One order at a time (no table to read in one go): the reference\n";
	unset( $GLOBALS['wpdb'] );
	$one = fh_q_cold( $store );
	check( 'ShipTracker is asked per order — at least twice each (the order, its lines) on a cold read', $one['single'] >= 2 * $n, (string) $one['single'] );
	if ( getenv( 'FH_COUNTS' ) ) {
		echo '    one at a time: ' . $one['single'] . ' shipment reads, ' . $one['finds'] . " request searches\n";
	}
	check_same( 'reset the reservations, so the second run starts from the same place', true, true );

	// The same orders again from scratch: the reservations it wrote are cleared.
	foreach ( $GLOBALS['fh_shop_items'] as $item ) {
		foreach ( array( Reserve::META, Reserve::META_SEEN ) as $key ) {
			$item->delete_meta( $key );
		}
	}
	echo "2. Primed: the shipments and the offers read once for every open order\n";
	$GLOBALS['wpdb'] = new Fh_Wpdb();
	$all             = fh_q_cold( $store );
	check_same( 'no order asks ShipTracker on its own any more', 0, $all['single'] );
	if ( getenv( 'FH_COUNTS' ) ) {
		echo '    primed: ' . $all['batched'] . ' shipment queries, ' . $all['offers'] . " offer read\n";
	}
	check( 'the shipments: a few queries for the whole read (the open orders, tomorrow\'s lines, the pull list), whatever the number of orders', $all['batched'] <= 4, (string) $all['batched'] );
	check_same( 'the offers: one read for the pass, no search per order', array( 0, 1 ), array( $all['finds'], $all['offers'] ) );
	check_same( 'exactly the same reservations as one order at a time', $one['counts'], $all['counts'] );
	check_same( 'exactly the same Pull today', $one['pull'], $all['pull'] );
	check_same( 'the partial shipments are still seen: those 4 lines are not reserved again, the request\'s customer got the group offered', array( 16, $s221 ), array( (int) ( $all['counts'][ $s121 ]['qty'] ?? 0 ) + (int) ( $all['counts'][ $s221 ]['qty'] ?? 0 ), (int) key( array_filter( array( $s121 => 0, $s221 => 1 ) ) ) ) );
	check( 'the order offered 221 by its request holds its fish there', array( $s221 => 1 ) === Reserve::held( $GLOBALS['fh_shop_items'][ 3005 * 10 + 1 ]->get_meta( Reserve::META ) ) );

	echo "3. Nothing primed outlives the read\n";
	$GLOBALS['fh_shipments'][3010] = array( (object) array( 'line_item_ids' => json_encode( array( 30101 ) ) ) );
	$reader                        = new Orders();
	check_same( 'a shipment added after the read is seen at once (asked on its own again)', array( 30101 => true ), $reader->shipments( wc_get_order( 3010 ) )['items'] );
	check_same( '… one single read', 1, FST_Shipment::$single );

	echo "4. A table that cannot be read: each order on its own, as before\n";
	$GLOBALS['wpdb'] = new class() {
		public $queries = 0;
		public function get_results( $sql, $output = null ) {
			$this->queries++;
			return null;
		}
	};
	foreach ( $GLOBALS['fh_shop_items'] as $item ) {
		foreach ( array( Reserve::META, Reserve::META_SEEN ) as $key ) {
			$item->delete_meta( $key );
		}
	}
	unset( $GLOBALS['fh_shipments'][3010] );
	$bad = fh_q_cold( $store );
	check( 'it falls back to one order at a time', $bad['single'] >= 2 * $n );
	check_same( '… with the same reservations', $one['counts'], $bad['counts'] );

	fh_finish();
}
