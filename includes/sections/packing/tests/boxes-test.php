<?php
/**
 * Where the boxes come from — on invented boxes: ShipTracker's standard
 * boxes when the build that is active provides them (reached through
 * class_exists / method_exists, never trusted to not throw), this
 * section's own list in every other case, said plainly; and "how many
 * fit" following a box from one list to the other by its name or by its
 * size.
 *
 * Run from the plugin root:  php includes/sections/packing/tests/boxes-test.php
 */

require_once __DIR__ . '/wp-stubs.php';

use FisHotel\Misc\Sections\Packing\Boxes;
use FisHotel\Misc\Sections\Packing\Order_Packing;
use FisHotel\Misc\Sections\Packing\Settings;
use FisHotel\Misc\Sections\Packing\Suggest;

$settings = fh_test_settings();

echo "1. A box\n";
check( 'its key is its name, lower-cased, whitespace collapsed', 'cube 12' === Boxes::key( '  Cube   12 ' ) && '' === Boxes::key( array( 'x' ) ) && '' === Boxes::key( '' ) );
check( 'a size is a number of inches above 0 (two places at most)', 12.5 === Boxes::number( '12.50' ) && 8.0 === Boxes::number( 8 ) && 8.13 === Boxes::number( '8.126' ) && null === Boxes::number( '0' ) && null === Boxes::number( '-3' ) && null === Boxes::number( 'ten' ) && null === Boxes::number( '' ) && null === Boxes::number( null ) && null === Boxes::number( array( 8 ) ) && null === Boxes::number( '1000' ) );
check( 'numbers are written without trailing zeros; the size as ShipTracker stores it', '12.5' === Boxes::num_text( 12.50 ) && '10' === Boxes::num_text( '10.00' ) && '0' === Boxes::num_text( 0 ) && '10x8.5x6' === Boxes::dims( 10, '8.50', 6.0 ) );
$box = Boxes::row( array( 'name' => ' Long  20 ', 'length' => '20', 'width' => '14', 'height' => '12.5' ), Boxes::SOURCE_OWN );
check( 'a row becomes a box with its key, size and volume', array( 'key' => 'long 20', 'name' => 'Long 20', 'length' => 20.0, 'width' => 14.0, 'height' => 12.5, 'dims' => '20x14x12.5', 'volume' => 3500.0, 'source' => 'own' ) === $box, fh_test_export( $box ) );
check( 'for a person: "20 × 14 × 12.5 in"', '20 × 14 × 12.5 in' === Boxes::size_text( $box ) );
check( 'a row without a name or with a size that is not above 0 is not a box', null === Boxes::row( array( 'name' => '', 'length' => 1, 'width' => 1, 'height' => 1 ), 'own' ) && null === Boxes::row( array( 'name' => 'Flat', 'length' => 1, 'width' => 1, 'height' => 0 ), 'own' ) && null === Boxes::row( 'box', 'own' ) );
$rows = Boxes::rows(
	array(
		array( 'name' => 'Big', 'length' => 20, 'width' => 20, 'height' => 20 ),
		'junk',
		array( 'name' => 'Small', 'length' => 5, 'width' => 5, 'height' => 5 ),
		array( 'name' => 'BIG', 'length' => 1, 'width' => 1, 'height' => 1 ),
		array( 'name' => 'No height', 'length' => 5, 'width' => 5 ),
	),
	Boxes::SOURCE_OWN
);
check( 'a list: what is not a box and a second box of the same name dropped, the smallest first', array( 'Small', 'Big' ) === array_column( $rows, 'name' ) && 8000.0 === $rows[1]['volume'] );
check( 'a box is found by its key or its name', 'Big' === Boxes::find( $rows, 'big' )['name'] && 'Big' === Boxes::find( $rows, ' BIG ' )['name'] && null === Boxes::find( $rows, 'huge' ) && null === Boxes::find( $rows, '' ) );

echo "2. ShipTracker is not there\n";
check( 'no such class: it provides nothing', null === Boxes::from_shiptracker() && null === Boxes::from_shiptracker( 'Fh_No_Such_Class' ) );
$current = Boxes::current( $settings );
check( 'this section\'s own list is used, and the answer says ShipTracker is absent', Boxes::SOURCE_OWN === $current['source'] && Boxes::SHIPTRACKER_ABSENT === $current['shiptracker'] && array( 'Cube 8', 'Cube 12', 'Long 20' ) === array_column( $current['boxes'], 'name' ) && $current['boxes'] === $current['own'] );

/**
 * A ShipTracker build from before the standard boxes: the class, without
 * the method.
 */
class Fh_Old_Ship_Settings {

	public static function get_label_sources() {
		return array();
	}
}
check( 'an older build without get_standard_boxes(): it provides nothing — guarded with method_exists', null === Boxes::from_shiptracker( 'Fh_Old_Ship_Settings' ) );

echo "3. The active build provides them\n";
/**
 * A ShipTracker build with the standard boxes: sizes as number strings,
 * the normalised "LxWxH", and the materials fields this section ignores.
 */
class Fh_New_Ship_Settings {

	public static $boxes = array();
	public static $throw = false;

	public static function get_standard_boxes() {
		if ( self::$throw ) {
			throw new \RuntimeException( 'ShipTracker failed' );
		}
		return self::$boxes;
	}
}
Fh_New_Ship_Settings::$boxes = array(
	array( 'name' => 'Shipper M', 'length' => '12', 'width' => '12', 'height' => '12', 'dims' => '12x12x12', 'foam_cost' => '1.80', 'paper_cost' => '0.40', 'paper_feet' => '', 'ice_packs' => '2' ),
	array( 'name' => 'Shipper S', 'length' => '8', 'width' => '8', 'height' => '8', 'dims' => '8x8x8', 'foam_cost' => '0.00', 'paper_cost' => '0.00', 'paper_feet' => '3', 'ice_packs' => '0' ),
	array( 'name' => 'Cube 8', 'length' => '9.5', 'width' => '9', 'height' => '9', 'dims' => '9.5x9x9', 'foam_cost' => '0.00', 'paper_cost' => '0.00', 'paper_feet' => '', 'ice_packs' => '0' ),
);
$provided = Boxes::from_shiptracker( 'Fh_New_Ship_Settings' );
check( 'its boxes are read as they are: name and inches, smallest first, marked as ShipTracker\'s', array( 'Shipper S', 'Cube 8', 'Shipper M' ) === array_column( $provided, 'name' ) && array( 'shiptracker', 'shiptracker', 'shiptracker' ) === array_column( $provided, 'source' ) && '9.5x9x9' === $provided[1]['dims'] && 769.5 === $provided[1]['volume'], fh_test_export( array_column( $provided, 'dims' ) ) );
$current = Boxes::current( $settings, $provided, false );
check( 'they are the boxes in use; the own list is kept but not used', Boxes::SOURCE_SHIPTRACKER === $current['source'] && Boxes::SHIPTRACKER_USED === $current['shiptracker'] && $provided === $current['boxes'] && 3 === count( $current['own'] ) && 'own' === $current['own'][0]['source'] );

echo "4. How many fit follows a box from one list to the other\n";
$with = Boxes::with_fits( $current['boxes'], $settings['fits'] );
check( 'a ShipTracker box with the name of an own box keeps the numbers typed under that name — even at another size', array( 1 => 6, 2 => 3 ) === $with[1]['fits'] && 'cube 8' === $with[1]['fits_from'] && 'Cube 8' === $with[1]['name'] );
check( 'a box under a new name with exactly the size of an own box that is not in the list takes its numbers over', array( 1 => 12, 2 => 8, 3 => 4 ) === $with[2]['fits'] && 'cube 12' === $with[2]['fits_from'] && 'Shipper M' === $with[2]['name'] );
check( 'the 8-inch cube is not taken over: its numbers belong to "Cube 8", which is still in the list', array() === $with[0]['fits'] && '' === $with[0]['fits_from'] );
check( 'numbers of the own boxes that are not in use are not lost', isset( $settings['fits']['long 20'] ) );

fh_reset_shop();
fh_product( new Fh_Fake_Product( 100, 'variable' ) );
fh_product( new Fh_Fake_Product( 101, 'variation', array( 'pa_size' => 'big' ), array(), 100 ) );
$GLOBALS['fh_terms'] = array( 100 => array( 'quarantined-fish' ) );
$order               = fh_order( new Fh_Fake_Order( 900, array( new Fh_Fake_Item( 1, 'Invented Angel - Big', 3, 100, array(), 101 ) ) ) );
$result              = Order_Packing::for_order( $order, $settings, fh_test_sizes(), $current );
check( 'an order is then suggested one of ShipTracker\'s boxes, and says where the boxes come from', Suggest::STATUS_FITS === $result['status'] && 'Shipper M' === $result['boxes'][0]['name'] && 75.0 === $result['boxes'][0]['fill_pct'] && 'shiptracker' === $result['boxes'][0]['source'] && 'shiptracker' === $result['box_source'] );

echo "5. It has the list, but nothing in it yet — or it fails\n";
Fh_New_Ship_Settings::$boxes = array();
$empty                       = Boxes::from_shiptracker( 'Fh_New_Ship_Settings' );
$current                     = Boxes::current( $settings, $empty, false );
check( 'an empty ShipTracker list: the own list is used, and the answer says "empty", not "absent"', array() === $empty && Boxes::SOURCE_OWN === $current['source'] && Boxes::SHIPTRACKER_EMPTY === $current['shiptracker'] && 3 === count( $current['boxes'] ) );
Fh_New_Ship_Settings::$boxes = array( array( 'name' => 'No size at all' ), 'junk' );
check( 'a list with nothing usable in it counts as empty', array() === Boxes::from_shiptracker( 'Fh_New_Ship_Settings' ) );
Fh_New_Ship_Settings::$throw = true;
check( 'ShipTracker throwing is not this section failing: it provides nothing', null === Boxes::from_shiptracker( 'Fh_New_Ship_Settings' ) );
Fh_New_Ship_Settings::$throw = false;
Fh_New_Ship_Settings::$boxes = 'not a list';
check( 'an answer that is not a list: nothing', null === Boxes::from_shiptracker( 'Fh_New_Ship_Settings' ) );
$current = Boxes::current( array_merge( $settings, array( 'boxes' => array() ) ), null, false );
check( 'neither list has a box: there are none, and nothing is made up', array() === $current['boxes'] && Boxes::SOURCE_OWN === $current['source'] );

echo "6. The filter has the last word\n";
add_filter(
	'fishotel_packing_boxes',
	static function ( $boxes, $source ) {
		$boxes[] = array( 'name' => 'Added by a filter (' . $source . ')', 'length' => 30, 'width' => 20, 'height' => 20 );
		return $boxes;
	},
	10,
	2
);
$current = Boxes::current( $settings, null, false );
check( 'fishotel_packing_boxes can add to (or replace) the list; what it returns is cleaned like any other', 4 === count( $current['boxes'] ) && 'Added by a filter (own)' === $current['boxes'][3]['name'] && 12000.0 === $current['boxes'][3]['volume'] );
fh_reset_hooks();
add_filter(
	'fishotel_packing_boxes',
	static function () {
		return 'nonsense';
	}
);
check( 'a filter that answers something else than a list is not obeyed', 3 === count( Boxes::current( $settings, null, false )['boxes'] ) );
fh_reset_hooks();

echo "7. The real class name\n";
if ( ! class_exists( 'FST_Settings' ) ) {
	/**
	 * ShipTracker's settings class under its real name, as far as this
	 * section calls it.
	 */
	class FST_Settings {

		public static function get_standard_boxes() {
			return array(
				array( 'name' => 'Shipper L', 'length' => '20', 'width' => '14', 'height' => '12', 'dims' => '20x14x12' ),
			);
		}
	}
}
$current = Boxes::current( $settings );
check( 'with FST_Settings::get_standard_boxes() there, asking for the boxes in use asks it', Boxes::SOURCE_SHIPTRACKER === $current['source'] && array( 'Shipper L' ) === array_column( $current['boxes'], 'name' ) );
$with = Boxes::with_fits( $current['boxes'], $settings['fits'] );
check( 'and the numbers typed for the own box of that size come along', array( 1 => 30, 2 => 18, 3 => 10, 4 => 2 ) === $with[0]['fits'] && 'long 20' === $with[0]['fits_from'] );
fh_login( 5, array( 'manage_woocommerce' ) );
$out = Settings::boxes_request(
	array(
		'_wpnonce' => wp_create_nonce( Settings::NONCE_ACTION ),
		'boxes'    => array(
			array( 'name' => 'Cube 8', 'length' => '8', 'width' => '8', 'height' => '8' ),
			array( 'name' => 'Cube twelve', 'length' => '12', 'width' => '12', 'height' => '12' ),
			array( 'name' => 'Long 20', 'length' => '20', 'width' => '14', 'height' => '12' ),
		),
	)
);
$after = Settings::get();
check( 'saving the own list while ShipTracker provides the boxes says it is not in use — and a rename there moves no numbers', 200 === $out['status'] && false !== strpos( $out['message'], 'not in use' ) && isset( $after['fits']['cube 12'] ) && ! isset( $after['fits']['cube twelve'] ) && 'Cube twelve' === $after['boxes'][1]['name'], $out['message'] );

fh_finish();
