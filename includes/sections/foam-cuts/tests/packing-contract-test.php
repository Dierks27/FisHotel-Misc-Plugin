<?php
/**
 * The Packing section as it really answers — doubles shaped like its
 * contracts, with invented boxes and orders:
 *
 *   fishotel_packing_suggest( int $order_id ): array   always the same
 *       keys; { available: false, status: "unavailable", boxes: [] } for
 *       anything that is not an order ID — an order OBJECT included, and
 *       without an error
 *   fishotel_packing_confirmed_box( int $order_id ): ?array   { boxes: [
 *       { name, length, width, height, … } ], count (how many boxes),
 *       name ("A + 2 × B"), … } or null
 *   no fishotel_packing_boxes(): its box list is
 *       Packing\Boxes::current( Packing\Settings::get() )['boxes']
 *
 * So: an order's box is asked for by ID, the count beside a confirmed
 * record is not a multiplier, and the list is read from the classes.
 *
 * Run from the plugin root:  php includes/sections/foam-cuts/tests/packing-contract-test.php
 */

require_once __DIR__ . '/wp-stubs.php';

use FisHotel\Misc\Sections\Foam_Cuts\Boxes;
use FisHotel\Misc\Sections\Foam_Cuts\Orders;
use FisHotel\Misc\Sections\Foam_Cuts\Planner;
use FisHotel\Misc\Sections\Foam_Cuts\Settings;
use FisHotel\Misc\Sections\Foam_Cuts\Tests\Fake_Store;

$GLOBALS['fh_pk_asked']   = array(); // [ function, what it was handed ]
$GLOBALS['fh_pk_boxes']   = array(); // The Packing section's own list.
$GLOBALS['fh_pk_loaded']  = 0;
$GLOBALS['fh_pk_suggest'] = array(); // order id → boxes
$GLOBALS['fh_pk_confirm'] = array(); // order id → boxes

/**
 * One box as the Packing section hands it out.
 */
function fh_pk_box( $name, $l, $w, $h ) {
	return array(
		'key'         => strtolower( $name ),
		'name'        => $name,
		'length'      => (float) $l,
		'width'       => (float) $w,
		'height'      => (float) $h,
		'dims'        => $l . 'x' . $w . 'x' . $h,
		'volume'      => (float) ( $l * $w * $h ),
		'source'      => 'own',
		'bags'        => array( 'small' => 6 ),
		'fill'        => 0.6,
		'fill_pct'    => 60,
		'on_the_line' => false,
	);
}

function fishotel_packing_suggest( $order_id ): array {
	$GLOBALS['fh_pk_asked'][] = array( 'suggest', gettype( $order_id ) );
	$order_id                 = is_scalar( $order_id ) ? (int) $order_id : 0;
	$boxes                    = isset( $GLOBALS['fh_pk_suggest'][ $order_id ] ) ? $GLOBALS['fh_pk_suggest'][ $order_id ] : array();

	return array(
		'available' => $order_id > 0,
		'order_id'  => $order_id,
		'status'    => $order_id < 1 ? 'unavailable' : ( $boxes ? ( count( $boxes ) > 1 ? 'split' : 'fits' ) : 'no_fit' ),
		'boxes'     => $boxes,
		'split'     => count( $boxes ) > 1,
		'bags'      => array( array( 'id' => 'small', 'name' => 'Small', 'width' => 6, 'length' => 12, 'count' => 6 ) ),
		'bag_total' => 6,
		'unplaced'  => array(),
		'lines'     => array( array( 'item_id' => 1, 'order_id' => $order_id, 'name' => 'Invented Goby', 'qty' => 6 ) ),
		'confirmed' => null,
		'messages'  => array(),
	);
}

function fishotel_packing_confirmed_box( $order_id ): ?array {
	$GLOBALS['fh_pk_asked'][] = array( 'confirmed', gettype( $order_id ) );
	$order_id                 = is_scalar( $order_id ) ? (int) $order_id : 0;
	if ( ! isset( $GLOBALS['fh_pk_confirm'][ $order_id ] ) ) {
		return null;
	}
	$boxes = $GLOBALS['fh_pk_confirm'][ $order_id ];

	return array(
		'order_id'  => $order_id,
		'boxes'     => $boxes,
		'count'     => count( $boxes ),
		'name'      => implode( ' + ', array_column( $boxes, 'name' ) ),
		'length'    => $boxes[0]['length'],
		'width'     => $boxes[0]['width'],
		'height'    => $boxes[0]['height'],
		'dims'      => $boxes[0]['dims'],
		'fill'      => array(),
		'suggested' => false,
		'complete'  => true,
		'by'        => 1,
		'at'        => '2026-03-10 09:00:00',
	);
}

// phpcs:ignore Squiz.PHP.Eval.Discouraged -- stand-ins for the sibling section's classes.
eval(
	'namespace FisHotel\Misc\Sections\Packing;
	class Packing { public static function load() { $GLOBALS["fh_pk_loaded"]++; } }
	class Settings { public static function get() { return array( "boxes" => $GLOBALS["fh_pk_boxes"] ); } }
	class Boxes { public static function current( array $settings, $shiptracker = null, $ask = true ) {
		if ( "throw" === $settings["boxes"] ) { throw new \RuntimeException( "a bad day" ); }
		return array( "source" => "own", "shiptracker" => "absent", "boxes" => $settings["boxes"], "own" => $settings["boxes"] );
	} }'
);

$own = Settings::sanitize( array( 'boxes' => array( array( 'name' => 'Own box', 'length' => fh_in( 14 ), 'width' => fh_in( 10 ), 'height' => fh_in( 8 ) ) ) ) );
update_option( Settings::OPTION_KEY, $own );
update_option( Boxes::SECTIONS_OPTION, array( 'packing', 'foam-cuts' ) );

echo "1. The Packing section's box list, read from its classes\n";
check( 'that section has no function for its list', ! function_exists( 'fishotel_packing_boxes' ) );
$GLOBALS['fh_pk_boxes'] = array( fh_pk_box( 'Cube 8', 8, 8, 8 ), fh_pk_box( 'Long 20', 20, 10.5, 8 ) );
$list                   = Boxes::all( $own );
check_same( 'its list is the one in use (ShipTracker provides none)', array( 'packing', array( 'Cube 8', 'Long 20' ) ), array( $list['source'], array_column( $list['boxes'], 'name' ) ) );
check( 'inches become units; nothing else of a row is kept', array( 'key', 'name', 'length', 'width', 'height' ) === array_keys( $list['boxes'][1] ) && fh_in( 10.5 ) === $list['boxes'][1]['width'] );
check( 'its classes were loaded through its own load()', $GLOBALS['fh_pk_loaded'] >= 1 );
$GLOBALS['fh_pk_boxes'] = array();
$list                   = Boxes::all( $own );
check( 'no boxes there: the own list, and the page says so', 'own' === $list['source'] && array( 'Own box' ) === array_column( $list['boxes'], 'name' ) && false !== strpos( implode( ' ', $list['notes'] ), 'The Packing section has no boxes yet' ) );
$GLOBALS['fh_pk_boxes'] = 'throw';
check( 'a list that throws: the own list, nothing breaks', 'own' === Boxes::all( $own )['source'] && null === Boxes::from_packing() );
$GLOBALS['fh_pk_boxes'] = array( fh_pk_box( 'Cube 8', 8, 8, 8 ), fh_pk_box( 'Long 20', 20, 10.5, 8 ) );
update_option( Boxes::SECTIONS_OPTION, array( 'foam-cuts' ) );
check( 'that section switched off: not asked', 'own' === Boxes::all( $own )['source'] && null === Boxes::from_packing() );
update_option( Boxes::SECTIONS_OPTION, array( 'packing', 'foam-cuts' ) );

echo "2. An order's box is asked for by the order's ID\n";
$boxes                = Boxes::all( $own )['boxes'];
$GLOBALS['fh_orders'] = array(
	201 => new WC_Order( 201, 'processing', array( '_fishotel_shipping_date' => '2026-03-11' ) ),
	202 => new WC_Order( 202, 'processing', array( '_fishotel_shipping_date' => '2026-03-11' ) ),
	203 => new WC_Order( 203, 'processing', array( '_fishotel_shipping_date' => '2026-03-11' ) ),
	204 => new WC_Order( 204, 'processing', array( '_fishotel_shipping_date' => '2026-03-11' ) ),
);
$GLOBALS['fh_pk_confirm'] = array( 201 => array( fh_pk_box( 'Long 20', 20, 10.5, 8 ), fh_pk_box( 'Cube 8', 8, 8, 8 ), fh_pk_box( 'Cube 8', 8, 8, 8 ) ) );
$GLOBALS['fh_pk_suggest'] = array(
	201 => array( fh_pk_box( 'Cube 8', 8, 8, 8 ) ),
	202 => array( fh_pk_box( 'Cube 8', 8, 8, 8 ) ),
	204 => array( fh_pk_box( 'Cube 8', 8, 8, 8 ), fh_pk_box( 'Long 20', 20, 10.5, 8 ) ),
);

$object = fishotel_packing_suggest( $GLOBALS['fh_orders'][202] );
check( 'handed an order OBJECT that section answers "unavailable" with no boxes — and no error', false === $object['available'] && array() === $object['boxes'] );
$GLOBALS['fh_pk_asked'] = array();

$found = Orders::for_ship_date( '2026-03-10', $boxes );
check_same( 'every question was asked with an ID', array( 'integer' ), array_values( array_unique( array_column( $GLOBALS['fh_pk_asked'], 1 ) ) ) );
check_same( 'so each order has its box: confirmed where one is, else suggested, else none', array( 201 => 'confirmed', 202 => 'suggested', 203 => '', 204 => 'suggested' ), array_column( $found['orders'], 'how', 'id' ) );
check_same( 'a confirmed record of three boxes is three boxes — its "count" is not a multiplier', array( '1 × Long 20', '2 × Cube 8' ), Planner::order_lines( $found['orders'] )[0]['boxes'] );
check_same( 'a split suggestion counts each of its boxes', array( '1 × Cube 8', '1 × Long 20' ), Planner::order_lines( $found['orders'] )[3]['boxes'] );
check_same( 'the day adds up', array( 'long 20' => 2, 'cube 8' => 4 ), $found['counts'] );
check_same( 'the order with no box is listed, not guessed', array( 203 ), $found['without_box'] );

echo "3. The orders of the day become a plan\n";
fh_login( 1, array( 'manage_woocommerce' ) );
$store = new Fake_Store();
$store->add_sheet_entry( 'bought', 3, 0, '', 1 );
$made = Planner::create_for_orders( $store, '2026-03-10', 1 );
check( 'made, for four Cube 8 and two Long 20', true === $made['ok'] && '4 × Cube 8, 2 × Long 20' === $store->plan( $made['id'] )['title'], $made['message'] . ' / ' . ( $made['ok'] ? $store->plan( $made['id'] )['title'] : '' ) );

fh_finish();
