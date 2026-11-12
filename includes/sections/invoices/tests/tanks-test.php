<?php
/**
 * Tanks — this section's only way into the Clean Room: here against
 * stand-ins for that section's three classes (its Store, its settings,
 * its "today"), which answer in the shapes the real ones do. What a tank
 * group is read as (species, size, how many alive, tank, which supplier
 * order — the batch's ref before the stay's own), the search by every
 * word, the species list and its price for a product or a name, and what
 * happens when the Clean Room throws, has no tables, or is an older
 * version without a method.
 *
 * (search-test.php covers the case of no Clean Room at all.)
 *
 * Run from the plugin root:  php includes/sections/invoices/tests/tanks-test.php
 */

namespace FisHotel\Misc\Sections\Clean_Room {

	/**
	 * Stand-in for the Clean Room's main class.
	 */
	class Clean_Room {
		public static function today() {
			return '2026-11-03';
		}
	}

	/**
	 * Stand-in for the Clean Room's settings.
	 */
	class Settings {
		public static function get() {
			return array( 'sale_ready_days' => 14 );
		}
	}

	/**
	 * Stand-in for the Clean Room's Store: stays and species rows as the
	 * real one hydrates them.
	 */
	class Store {

		public static $tables = true;
		public static $throw  = false;
		public static $asked  = array();

		public static $stays = array();
		public static $rows  = array();

		public static function tables_exist() {
			return self::$tables;
		}

		public function open_stays( array $filters, $today, array $settings ) {
			self::$asked[] = array( 'open_stays', $filters, $today, $settings );
			if ( self::$throw ) {
				throw new \RuntimeException( 'the database went away' );
			}
			$out = array();
			foreach ( self::$stays as $stay ) {
				if ( 'closed' !== $stay['stored_status'] && false !== stripos( $stay['species'], (string) $filters['species'] ) ) {
					$out[] = $stay;
				}
			}
			return $out;
		}

		public function stay( $id, $today = '', array $settings = array() ) {
			foreach ( self::$stays as $stay ) {
				if ( (int) $stay['id'] === (int) $id ) {
					return $stay;
				}
			}
			return null;
		}

		public function species_list( array $filters = array(), $limit = 50, $offset = 0 ) {
			$out = array();
			foreach ( self::$rows as $row ) {
				if ( 0 === stripos( $row['name'], (string) $filters['search'] ) ) {
					$out[] = $row;
				}
			}
			return array_slice( $out, 0, $limit );
		}

		public function species_by_product( $product_id ) {
			foreach ( self::$rows as $row ) {
				if ( (int) $row['product_id'] === (int) $product_id ) {
					return $row;
				}
			}
			return null;
		}

		public function species_by_name( $name ) {
			foreach ( self::$rows as $row ) {
				if ( strtolower( $row['name'] ) === strtolower( (string) $name ) ) {
					return $row;
				}
			}
			return null;
		}
	}
}

namespace {

	require_once __DIR__ . '/wp-stubs.php';

	use FisHotel\Misc\Sections\Clean_Room\Store as Clean_Store;
	use FisHotel\Misc\Sections\Invoices\Tanks;

	/**
	 * A stay as the Clean Room's Store::hydrate_stay() gives it (the keys
	 * this section reads, and a few it does not).
	 */
	function stay( array $more ) {
		return array_merge(
			array(
				'id'              => 0,
				'product_id'      => null,
				'species'         => '',
				'size_slug'       => null,
				'size_label'      => null,
				'needs_id'        => false,
				'qty_arrived'     => 10,
				'qty_live'        => 0,
				'stored_status'   => 'in_qt',
				'status'          => 'in_qt',
				'tank_id'         => 4,
				'tank_number'     => null,
				'order_ref'       => null,
				'batch_order_ref' => null,
				'sale_ready'      => false,
			),
			$more
		);
	}

	Clean_Store::$stays = array(
		stay( array( 'id' => 21, 'species' => 'Blue Test Tang', 'size_slug' => 'medium', 'size_label' => 'Medium', 'qty_live' => 5, 'tank_number' => 121, 'batch_order_ref' => '2026-3', 'order_ref' => 'old-ref', 'product_id' => 501 ) ),
		stay( array( 'id' => 22, 'species' => 'Powder Blue Test Tang', 'qty_live' => 2, 'tank_number' => 511, 'order_ref' => '2026-1' ) ),
		stay( array( 'id' => 23, 'species' => '?? grey Test Tang', 'qty_live' => 1, 'needs_id' => true ) ),
		stay( array( 'id' => 24, 'species' => 'Dead Test Tang', 'qty_live' => 0, 'tank_number' => 122 ) ),
		stay( array( 'id' => 25, 'species' => 'Closed Test Tang', 'qty_live' => 0, 'stored_status' => 'closed', 'status' => 'closed' ) ),
		stay( array( 'id' => 26, 'species' => 'Blue Test Chromis', 'qty_live' => 9, 'tank_number' => 123 ) ),
	);
	Clean_Store::$rows  = array(
		array( 'id' => 31, 'name' => 'Blue Test Tang', 'sci_name' => 'Testus caeruleus', 'my_price' => 55.0, 'product_id' => 501 ),
		array( 'id' => 32, 'name' => 'Blue Test Chromis', 'sci_name' => '', 'my_price' => null, 'product_id' => null ),
		array( 'id' => 0, 'name' => 'Row Without Id', 'sci_name' => '', 'my_price' => 1.0, 'product_id' => null ),
	);

	echo "1. The Clean Room is there\n";
	$tanks = new Tanks();
	check( 'available, with nothing to explain', true === $tanks->available() && '' === $tanks->reason() );

	echo "2. Searching the tanks\n";
	$found = $tanks->search( 'tang' );
	check_same( 'the groups with fish alive — a group with nothing alive and a closed one are not offered', array( 21, 22, 23 ), array_column( $found, 'stay_id' ) );
	check_same( 'a tank group: species, size, how many alive, tank, supplier order, product, whether it is identified, open', array( 'stay_id' => 21, 'species' => 'Blue Test Tang', 'size_slug' => 'medium', 'size_label' => 'Medium', 'alive' => 5, 'tank' => 121, 'order_ref' => '2026-3', 'product_id' => 501, 'needs_id' => false, 'open' => true ), $found[0] );
	check( 'the supplier order is the batch\'s ref; without one, the stay\'s own', '2026-3' === $found[0]['order_ref'] && '2026-1' === $found[1]['order_ref'] && '' === $found[2]['order_ref'] );
	check( 'a group in no tank, of no product, of no size, still to be identified', null === $found[2]['tank'] && null === $found[2]['product_id'] && null === $found[2]['size_slug'] && true === $found[2]['needs_id'] );
	check_same( 'every word must be in the species, in any order', array( 22 ), array_column( $tanks->search( 'tang powder' ), 'stay_id' ) );
	check_same( 'case does not matter', array( 26 ), array_column( $tanks->search( 'CHROMIS' ), 'stay_id' ) );
	Clean_Store::$asked = array();
	$tanks->search( 'bl tang' );
	check( 'the Clean Room is asked with the longest word, today\'s date and its own settings', 'tang' === Clean_Store::$asked[0][1]['species'] && '2026-11-03' === Clean_Store::$asked[0][2] && array( 'sale_ready_days' => 14 ) === Clean_Store::$asked[0][3] );
	check_same( 'a cap on the rows', 2, count( $tanks->search( 'tang', 2 ) ) );
	check_same( 'nothing typed: nothing', array(), $tanks->search( '  ' ) );

	echo "3. One group by its id\n";
	check( 'an open group', 5 === $tanks->group_by_id( 21 )['alive'] && true === $tanks->group_by_id( 21 )['open'] );
	check( 'a closed group is found and says it is closed', false === $tanks->group_by_id( 25 )['open'] && 0 === $tanks->group_by_id( 25 )['alive'] );
	check( 'a group that does not exist, and no id', null === $tanks->group_by_id( 404 ) && null === $tanks->group_by_id( 0 ) );

	echo "4. The species list\n";
	check_same( 'rows whose name starts with the text, each with its id, price and product; a row without an id is dropped', array( array( 'species_id' => 31, 'name' => 'Blue Test Tang', 'sci_name' => 'Testus caeruleus', 'price' => 55.0, 'product_id' => 501 ), array( 'species_id' => 32, 'name' => 'Blue Test Chromis', 'sci_name' => '', 'price' => null, 'product_id' => null ) ), $tanks->species_search( 'blue' ) );
	check_same( 'the price of a fish: by its product', array( 'price' => 55.0, 'species_id' => 31 ), $tanks->species_price( 501, 'Whatever it is called' ) );
	check_same( '…else by its name', array( 'price' => 55.0, 'species_id' => 31 ), $tanks->species_price( null, 'blue test tang' ) );
	check_same( 'a species without a price: none, but its id', array( 'price' => null, 'species_id' => 32 ), $tanks->species_price( 0, 'Blue Test Chromis' ) );
	check_same( 'a fish the list does not know', array( 'price' => null, 'species_id' => 0 ), $tanks->species_price( 999, 'Unknown Test Fish' ) );

	echo "5. The Clean Room fails, has no tables, or is older\n";
	Clean_Store::$throw = true;
	check_same( 'it throws: the search answers "nothing" instead of failing', array(), $tanks->search( 'tang' ) );
	Clean_Store::$throw = false;

	Clean_Store::$tables = false;
	$fresh               = new Tanks();
	check( 'its tables do not exist: not available, with the reason', false === $fresh->available() && false !== strpos( $fresh->reason(), 'tables do not exist' ) && array() === $fresh->search( 'tang' ) && array() === $fresh->species_search( 'blue' ) && null === $fresh->group_by_id( 21 ) );
	Clean_Store::$tables = true;
	check( 'the answer is remembered per object, so one request asks once', true === $tanks->available() && false === $fresh->available() );

	echo "6. Nothing in the Clean Room is ever written from here\n";
	$source = (string) file_get_contents( dirname( __DIR__ ) . '/class-tanks.php' );
	preg_match_all( '/\$context\[0\]->(\w+)\(/', $source, $m );
	check_same( 'the only methods called on its store are reads', array( 'open_stays', 'species_by_name', 'species_by_product', 'species_list', 'stay' ), array_values( array_unique( ( sort( $m[1] ) ? $m[1] : $m[1] ) ) ) );
	check( 'its classes are looked up without the autoloader, and every method is checked first', 3 === substr_count( $source, 'class_exists( $' ) && substr_count( $source, 'method_exists(' ) >= 7 && false === strpos( $source, 'use FisHotel\\Misc\\Sections\\Clean_Room' ) );

	fh_finish();
}
