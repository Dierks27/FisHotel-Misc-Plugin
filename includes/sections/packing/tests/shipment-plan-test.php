<?php
/**
 * The shipment plan — what Packing hands to the code that records a
 * shipment, so the box and the bags are not typed twice — on invented
 * orders, against small stand-ins for the two ShipTracker classes it meets
 * (FST_Settings::get_standard_boxes() and FST_Supplies::fish_count(), the
 * latter copied from ShipTracker 1.9.18 with its filter):
 *
 *   - fishotel_packing_shipment_plan(): its shape for one box, a split, a
 *     confirmed box, a combined box asked by its own id, a source order
 *     folded into one, an order with no plan, the section switched off;
 *   - ShipTracker's own row for a box: by name, else by the same numbers,
 *     else none — its strings handed on verbatim;
 *   - the plan worked out once per request;
 *   - ShipTracker's `fst_box_fish_count` answered with the bags to pull:
 *     item ids given, the whole order, a smaller count (proportional), a
 *     shipped and a refunded line counted whole, dry goods, an order
 *     Packing has no plan for (unchanged), never an exception;
 *   - nothing written by any of it.
 *
 * Every box, bag, fish and order here is invented.
 *
 * Run from the plugin root:  php includes/sections/packing/tests/shipment-plan-test.php
 */

require_once __DIR__ . '/wp-stubs.php';

use FisHotel\Misc\Sections\Packing\Boxes;
use FisHotel\Misc\Sections\Packing\Order_Box;
use FisHotel\Misc\Sections\Packing\Order_Packing;
use FisHotel\Misc\Sections\Packing\Packing;
use FisHotel\Misc\Sections\Packing\Settings;
use FisHotel\Misc\Sections\Packing\Shipment_Plan;

/**
 * ShipTracker's settings class, as far as Packing meets it: the standard
 * boxes, in ShipTracker's own shape.
 */
class FST_Settings {

	public static function get_standard_boxes() {
		if ( 'throw' === $GLOBALS['fh_fst_boxes'] ) {
			throw new \RuntimeException( 'ShipTracker failed' );
		}
		return $GLOBALS['fh_fst_boxes'];
	}
}

/**
 * ShipTracker's supplies class, as far as Packing meets it: fish_count()
 * as ShipTracker 1.9.18 has it — the quantities added up, then the filter.
 */
class FST_Supplies {

	public static function fish_count( $order, $item_ids = null ) {
		$count = 0;

		if ( is_object( $order ) && method_exists( $order, 'get_items' ) ) {
			foreach ( $order->get_items() as $item_id => $item ) {
				if ( null !== $item_ids && ! in_array( (int) $item_id, array_map( 'intval', $item_ids ), true ) ) {
					continue;
				}
				$count += max( 0, (float) $item->get_quantity() );
			}
		}

		$count = apply_filters( 'fst_box_fish_count', $count, $order, $item_ids );
		$count = is_numeric( $count ) ? max( 0, round( (float) $count, 2 ) ) : 0;

		return (float) (int) $count === $count ? (int) $count : $count;
	}
}

/**
 * ShipTracker's count for the next box of an order (its open_fish_count()):
 * the quantities of the lines that are not in a box yet — all of them when
 * none or every one is — through the same filter, with no item ids.
 *
 * @param object $order   The order.
 * @param array  $lines   Item id → quantity, of every line ShipTracker lists.
 * @param int[]  $covered The lines that are in a box already.
 * @return int|float
 */
function fh_fst_open_count( $order, array $lines, array $covered ) {
	$open = 0;
	$all  = 0;
	foreach ( $lines as $item_id => $quantity ) {
		$all += $quantity;
		if ( ! in_array( $item_id, $covered, true ) ) {
			$open += $quantity;
		}
	}
	$count = apply_filters( 'fst_box_fish_count', $open > 0 ? $open : $all, $order, null );

	return is_numeric( $count ) ? max( 0, $count + 0 ) : 0;
}

/**
 * ShipTracker's three invented boxes, in its own spelling.
 *
 * @return array[]
 */
function fh_fst_rows() {
	return array(
		array( 'name' => 'Cube 8', 'length' => '8', 'width' => '8', 'height' => '8', 'foam_cost' => '0.00', 'dims' => '8x8x8' ),
		array( 'name' => 'CUBE 12', 'length' => '12', 'width' => '12', 'height' => '12', 'foam_cost' => '1.25', 'dims' => '12x12x12' ),
		array( 'name' => 'Long 20', 'length' => '20', 'width' => '14', 'height' => '12', 'foam_cost' => '2.00', 'dims' => '20x14x12' ),
	);
}

/**
 * The invented catalogue: a fish parent with a variation per size, a jar of
 * salt, an Invoice product.
 */
function fh_plan_shop() {
	fh_reset_shop();
	fh_product( new Fh_Fake_Product( 100, 'variable' ) );
	fh_product( new Fh_Fake_Product( 101, 'variation', array( 'pa_size' => 'small' ), array(), 100 ) );
	fh_product( new Fh_Fake_Product( 102, 'variation', array( 'pa_size' => 'mid' ), array(), 100 ) );
	fh_product( new Fh_Fake_Product( 103, 'variation', array( 'pa_size' => 'big' ), array(), 100 ) );
	fh_product( new Fh_Fake_Product( 104, 'variation', array( 'pa_size' => 'show' ), array(), 100 ) ); // No bag for this size.
	fh_product( new Fh_Fake_Product( 120, 'simple' ) );
	fh_product( new Fh_Fake_Product( 140, 'simple' ) );
	$GLOBALS['fh_terms'] = array(
		100 => array( 'quarantined-fish' ),
		120 => array( 'dry-goods' ),
		140 => array( 'invoices' ),
	);
}

/**
 * An order whose lines know it.
 *
 * @param int   $id    Order ID.
 * @param array $lines Each: item id, name, quantity, variation id (0 = the salt, -1 = no product), meta.
 * @param array $meta  Order meta.
 * @return Fh_Fake_Order
 */
function fh_plan_order( $id, array $lines, array $meta = array() ) {
	$items = array();
	foreach ( $lines as $line ) {
		if ( -1 === $line[3] ) {
			$item = new Fh_Fake_Item( $line[0], $line[1], $line[2], 0, $line[4] ?? array() );
		} elseif ( 0 === $line[3] ) {
			$item = new Fh_Fake_Item( $line[0], $line[1], $line[2], 120, $line[4] ?? array() );
		} elseif ( 140 === $line[3] ) {
			$item = new Fh_Fake_Item( $line[0], $line[1], $line[2], 140, $line[4] ?? array() );
		} else {
			$item = new Fh_Fake_Item( $line[0], $line[1], $line[2], 100, $line[4] ?? array(), $line[3] );
		}
		$item->order_id = (int) $id;
		$items[]        = $item;
	}
	return fh_order( new Fh_Fake_Order( $id, $items, $meta ) );
}

$fh_keys     = array( 'available', 'reason', 'order_id', 'owner_id', 'packed_with', 'from', 'split', 'boxes', 'lines', 'totals' );
$fh_box_keys = array( 'index', 'name', 'dims', 'standard', 'match', 'fish', 'bags', 'layers', 'by_bag', 'items' );

$GLOBALS['fh_fst_boxes'] = fh_fst_rows();
fh_test_settings();
fh_use_sizes( fh_test_sizes() );
fh_plan_shop();
fh_plan_order( 5001, array( array( 7001, 'Invented Yellow Tang - Big', 2, 103 ), array( 7002, 'Invented Clown Goby - Small', 3, 101 ), array( 7003, 'Reef Salt 5 lb', 1, 0 ) ) );
fh_plan_order( 5002, array( array( 7004, 'Invented Lionfish - Big', 12, 103 ), array( 7005, 'Invented Clown Goby - Small', 1, 101 ) ) );
fh_plan_order( 5003, array( array( 7006, 'Reef Salt 5 lb', 2, 0 ) ) );

echo "1. Nothing to say\n";
$nothing = array(
	'available'   => false,
	'reason'      => 'disabled',
	'order_id'    => 5001,
	'owner_id'    => 5001,
	'packed_with' => 0,
	'from'        => '',
	'split'       => false,
	'boxes'       => array(),
	'lines'       => array(),
	'totals'      => array( 'fish' => 0, 'bags' => 0, 'layers' => 0 ),
);
check( 'the function is declared by the plugin whether the section is on or off', function_exists( 'fishotel_packing_shipment_plan' ) );
check_same( 'while the section is off: every key, empty, and the reason', $nothing, fishotel_packing_shipment_plan( 5001 ) );
Packing::set_enabled( true );
check_same( 'an order that does not exist', array_merge( $nothing, array( 'reason' => 'order_not_found', 'order_id' => 4040, 'owner_id' => 4040 ) ), fishotel_packing_shipment_plan( 4040 ) );
check_same( 'an order with no fish line has no plan', array_merge( $nothing, array( 'reason' => 'no_plan', 'order_id' => 5003, 'owner_id' => 5003 ) ), fishotel_packing_shipment_plan( 5003 ) );
check( 'the keys come in the same order every time', $fh_keys === array_keys( fishotel_packing_shipment_plan( 5003 ) ) && $fh_keys === array_keys( fishotel_packing_shipment_plan( 5001 ) ) );
check( 'anything that is not an order id is answered, never thrown at', false === fishotel_packing_shipment_plan( 'abc' )['available'] && false === fishotel_packing_shipment_plan( null )['available'] && false === fishotel_packing_shipment_plan( array( 5001 ) )['available'] && false === fishotel_packing_shipment_plan( -3 )['available'] );
$refund       = fh_plan_order( 5004, array( array( 7007, 'Invented Clown Goby - Small', 1, 101 ) ) );
$refund->type = 'shop_order_refund';
check( 'a refund is not an order', 'order_not_found' === fishotel_packing_shipment_plan( 5004 )['reason'] );

echo "2. One box\n";
$plan    = fishotel_packing_shipment_plan( 5001 );
$suggest = fishotel_packing_suggest( 5001 );
$box     = $plan['boxes'][0];
check( 'the order has a plan, its own, from the suggestion, in one box', true === $plan['available'] && '' === $plan['reason'] && 5001 === $plan['order_id'] && 5001 === $plan['owner_id'] && 0 === $plan['packed_with'] && 'suggestion' === $plan['from'] && false === $plan['split'] && 1 === count( $plan['boxes'] ) );
check( 'the box has exactly the keys of the contract, in order', $fh_box_keys === array_keys( $box ) );
check( 'it is Packing\'s box: its name, its size as LxWxH, its place in the list from 0', 0 === $box['index'] && 'CUBE 12' === $box['name'] && '12x12x12' === $box['dims'] && 'CUBE 12' === $suggest['boxes'][0]['name'], fh_test_export( $box ) );
check_same( 'with ShipTracker\'s own row for it — its name and dims, nothing else of the row', array( 'name' => 'CUBE 12', 'dims' => '12x12x12' ), $box['standard'] );
check( 'matched by name', 'name' === $box['match'] );
check( 'two tangs and three gobies: 5 fish in 5 bags — 2 × 3 + 3 × 2 = 12 bags to pull', 5 === $box['fish'] && 5 === $box['bags'] && 12 === $box['layers'] );
check_same( 'which order line puts how many bags in the box; the salt is not in it', array( '5001-7001' => 2, '5001-7002' => 3 ), $box['items'] );
$used = array();
foreach ( $suggest['packed']['boxes'][0]['bags'] as $bag_id => $count ) {
	$used[ $bag_id ] = $count;
}
krsort( $used );
check( 'by bag size, the largest first: the bags to USE (after upsizing), each with its single bags', array_keys( $used ) === array_column( $box['by_bag'], 'id' ) && array_values( $used ) === array_column( $box['by_bag'], 'count' ) && 5 === array_sum( array_column( $box['by_bag'], 'count' ) ) && 12 === array_sum( array_column( $box['by_bag'], 'layers' ) ) && array( 'id', 'name', 'count', 'layers' ) === array_keys( $box['by_bag'][0] ) && 'Large' === $box['by_bag'][0]['name'], fh_test_export( $box['by_bag'] ) );
check_same(
	'the lines: what is left to pack, with the layers of each and why',
	array(
		array( 'order_id' => 5001, 'item_id' => 7001, 'name' => 'Invented Yellow Tang - Big', 'qty' => 2, 'bags' => 2, 'layers_each' => 3, 'why' => 'word:tang', 'boxes' => array( 0 ) ),
		array( 'order_id' => 5001, 'item_id' => 7002, 'name' => 'Invented Clown Goby - Small', 'qty' => 3, 'bags' => 3, 'layers_each' => 2, 'why' => 'default', 'boxes' => array( 0 ) ),
	),
	$plan['lines']
);
check_same( 'the totals', array( 'fish' => 5, 'bags' => 5, 'layers' => 12 ), $plan['totals'] );
check( 'it survives JSON as it is: a list of boxes, a list of lines', $plan === json_decode( (string) wp_json_encode( $plan ), true ) );

echo "3. A confirmed box\n";
fh_login( 7, array( 'manage_woocommerce' ) );
$nonce      = wp_create_nonce( Order_Packing::NONCE_ACTION );
$fh_confirm = static function ( $order_id, $choice, $count = 1 ) use ( $nonce ) {
	return Order_Packing::confirm_request(
		array(
			'_wpnonce' => $nonce,
			'order_id' => (string) $order_id,
			'op'       => 'confirm',
			'choice'   => $choice,
			'count'    => (string) $count,
		)
	);
};
$out  = $fh_confirm( 5001, 'long 20' );
$plan = fishotel_packing_shipment_plan( 5001 );
check( 'the owner confirmed another box: the plan is that box, and says it is confirmed — in the same request', 200 === $out['status'] && 'confirmed' === $plan['from'] && array( 'Long 20' ) === array_column( $plan['boxes'], 'name' ) && '20x14x12' === $plan['boxes'][0]['dims'] && 'name' === $plan['boxes'][0]['match'] && 12 === $plan['boxes'][0]['layers'] && array( '5001-7001' => 2, '5001-7002' => 3 ) === $plan['boxes'][0]['items'], fh_test_export( $plan['boxes'] ) );
$out  = $fh_confirm( 5001, 'cube 12' );
$plan = fishotel_packing_shipment_plan( 5001 );
check( 'confirming the suggested box itself: confirmed, the same box', 'confirmed' === $plan['from'] && 'CUBE 12' === $plan['boxes'][0]['name'] && 12 === $plan['totals']['layers'] );

echo "4. ShipTracker's own row for a box\n";
$confirmed_as = wc_get_order( 5001 )->get_meta( Packing::META_BOX_NAME );
$GLOBALS['fh_fst_boxes'] = array(
	array( 'name' => 'Shipper S', 'length' => '8', 'width' => '8', 'height' => '8', 'dims' => '8x8x8' ),
	array( 'name' => 'Shipper M', 'length' => '12', 'width' => '12', 'height' => '12', 'dims' => '12x12x12' ),
	array( 'name' => 'Shipper M (old stock)', 'length' => '12', 'width' => '12', 'height' => '12', 'dims' => '12x12x12' ),
);
Order_Packing::forget();
$plan = fishotel_packing_shipment_plan( 5001 );
check( 'the box was renamed in ShipTracker since it was confirmed: the FIRST standard box of the same size, matched by its size', 'CUBE 12' === $confirmed_as && 'CUBE 12' === $plan['boxes'][0]['name'] && 'dims' === $plan['boxes'][0]['match'] && array( 'name' => 'Shipper M', 'dims' => '12x12x12' ) === $plan['boxes'][0]['standard'], fh_test_export( $plan['boxes'][0] ) );
$GLOBALS['fh_fst_boxes'] = array( array( 'name' => 'Shipper S', 'length' => '8', 'width' => '8', 'height' => '8', 'dims' => '8x8x8' ) );
Order_Packing::forget();
$plan = fishotel_packing_shipment_plan( 5001 );
check( 'ShipTracker has no box of that name or size: none — Packing\'s name and size are still there to type', null === $plan['boxes'][0]['standard'] && 'none' === $plan['boxes'][0]['match'] && 'CUBE 12' === $plan['boxes'][0]['name'] && '12x12x12' === $plan['boxes'][0]['dims'] );
$GLOBALS['fh_fst_boxes'] = array();
Order_Packing::forget();
$plan = fishotel_packing_shipment_plan( 5001 );
check( 'ShipTracker\'s list is empty (Packing uses its own boxes): none', null === $plan['boxes'][0]['standard'] && 'none' === $plan['boxes'][0]['match'] && true === $plan['available'] );
$GLOBALS['fh_fst_boxes'] = 'throw';
Order_Packing::forget();
$plan = fishotel_packing_shipment_plan( 5001 );
check( 'ShipTracker failing is answered as "none", not thrown on', true === $plan['available'] && 'none' === $plan['boxes'][0]['match'] && null === Boxes::shiptracker_rows() );
$GLOBALS['fh_fst_boxes'] = fh_fst_rows();
Order_Packing::forget();

$cube = array( 'name' => 'Cube 12', 'length' => 12.0, 'width' => 12.0, 'height' => 12.0 );
check_same( 'the name is compared as Packing keys a box: letter case and extra spaces aside — and handed on as ShipTracker spells it', array( 'standard' => array( 'name' => '  cUBE   12 ', 'dims' => '12x12x12' ), 'match' => 'name' ), Shipment_Plan::standard_for( $cube, array( array( 'name' => 'Other', 'length' => '12', 'width' => '12', 'height' => '12', 'dims' => '12x12x12' ), array( 'name' => '  cUBE   12 ', 'length' => '12', 'width' => '12', 'height' => '12', 'dims' => '12x12x12' ) ) ) );
check_same( 'the name wins over the size: a box of that name in another size is still that box', array( 'standard' => array( 'name' => 'Cube 12', 'dims' => '12x12x10' ), 'match' => 'name' ), Shipment_Plan::standard_for( $cube, array( array( 'name' => 'Same size', 'length' => '12', 'width' => '12', 'height' => '12', 'dims' => '12x12x12' ), array( 'name' => 'Cube 12', 'length' => '12', 'width' => '12', 'height' => '10', 'dims' => '12x12x10' ) ) ) );
check_same( 'sizes are compared as numbers: "12.0" is 12, and the row\'s own dims are handed on untouched', array( 'standard' => array( 'name' => 'Twelve', 'dims' => '12.0x12x12.00' ), 'match' => 'dims' ), Shipment_Plan::standard_for( $cube, array( array( 'name' => 'Twelve and a half', 'length' => '12.5', 'width' => '12', 'height' => '12', 'dims' => '12.5x12x12' ), array( 'name' => 'Twelve', 'length' => '12.0', 'width' => '12', 'height' => '12.00', 'dims' => '12.0x12x12.00' ) ) ) );
check_same( 'a row without dims gets them put together the way ShipTracker does', array( 'standard' => array( 'name' => 'Twelve', 'dims' => '12x12x12' ), 'match' => 'dims' ), Shipment_Plan::standard_for( $cube, array( array( 'name' => 'Twelve', 'length' => '12', 'width' => '12', 'height' => '12' ) ) ) );
$none = array( 'standard' => null, 'match' => 'none' );
check( 'no ShipTracker, an empty list, rows that are not rows, another size: none', $none === Shipment_Plan::standard_for( $cube, null ) && $none === Shipment_Plan::standard_for( $cube, array() ) && $none === Shipment_Plan::standard_for( $cube, array( 'x', array( 'length' => '12', 'width' => '12', 'height' => '12' ), array( 'name' => '', 'length' => '12', 'width' => '12', 'height' => '12' ), array( 'name' => 'Flat', 'length' => '12', 'width' => '12', 'height' => 'x' ) ) ) );

echo "5. An order in several boxes\n";
$plan = fishotel_packing_shipment_plan( 5002 );
check( 'twelve lionfish and a goby do not go in one box: a split, in Packing\'s order', true === $plan['split'] && 'suggestion' === $plan['from'] && array( 'Long 20', 'CUBE 12' ) === array_column( $plan['boxes'], 'name' ) && array( 0, 1 ) === array_column( $plan['boxes'], 'index' ), fh_test_export( array_column( $plan['boxes'], 'name' ) ) );
check_same( 'a line split over two boxes is in both, with its bags in each', array( array( '5002-7004' => 10 ), array( '5002-7004' => 2, '5002-7005' => 1 ) ), array_column( $plan['boxes'], 'items' ) );
check( 'each box counts its own fish, bags and single bags: 10 lionfish × 3, then 2 × 3 + a goby × 2', array( 10, 3 ) === array_column( $plan['boxes'], 'fish' ) && array( 10, 3 ) === array_column( $plan['boxes'], 'bags' ) && array( 30, 8 ) === array_column( $plan['boxes'], 'layers' ) );
check( 'the lines say which boxes hold them', array( 0, 1 ) === $plan['lines'][0]['boxes'] && array( 1 ) === $plan['lines'][1]['boxes'] && 'word:lionfish' === $plan['lines'][0]['why'] );
check( 'and the boxes add up to the totals', array( 'fish' => 13, 'bags' => 13, 'layers' => 38 ) === $plan['totals'] && 38 === array_sum( array_column( $plan['boxes'], 'layers' ) ) );
foreach ( $plan['boxes'] as $one ) {
	if ( $one['bags'] !== array_sum( $one['items'] ) || $one['bags'] !== array_sum( array_column( $one['by_bag'], 'count' ) ) || $one['layers'] !== array_sum( array_column( $one['by_bag'], 'layers' ) ) ) {
		check( 'a box\'s bags are its lines\' bags and its bag sizes\' bags', false, fh_test_export( $one ) );
	}
}
check( 'a box\'s bags are its lines\' bags and its bag sizes\' bags', true );
$fh_confirm( 5002, 'long 20', 2 );
$plan = fishotel_packing_shipment_plan( 5002 );
check( 'two of one box confirmed by hand: two boxes of the same name, the same ShipTracker row for both, every bag in one of them', 'confirmed' === $plan['from'] && array( 'Long 20', 'Long 20' ) === array_column( $plan['boxes'], 'name' ) && array( 'Long 20', 'Long 20' ) === array_column( array_column( $plan['boxes'], 'standard' ), 'name' ) && 13 === array_sum( array_column( $plan['boxes'], 'bags' ) ) && 38 === array_sum( array_column( $plan['boxes'], 'layers' ) ), fh_test_export( $plan['boxes'] ) );
Order_Packing::confirm_request( array( '_wpnonce' => $nonce, 'order_id' => '5002', 'op' => 'clear' ) );

echo "6. A combined box, and an order folded into one\n";
fh_plan_order( 5010, array( array( 7101, 'Invented Yellow Tang - Big', 1, 103 ), array( 7102, 'Reef Salt 5 lb', 1, 0 ) ), array( '_fishotel_fulfilled_by_order' => '5900' ) );
fh_plan_order( 5011, array( array( 7111, 'Invented Clown Goby - Small', 2, 101 ) ), array( '_fishotel_fulfilled_by_order' => '5900' ) );
fh_plan_order( 5900, array( array( 7901, 'Invented Yellow Tang - Big', 1, 103 ), array( 7902, 'Invented Clown Goby - Small', 2, 101 ) ), array( '_fishotel_is_fulfillment' => '1', '_fishotel_fulfillment_sources' => array( 5010, 5011 ) ) );
$plan = fishotel_packing_shipment_plan( 5900 );
check( 'the combined box is asked by its own id: its plan, its own', true === $plan['available'] && 5900 === $plan['order_id'] && 5900 === $plan['owner_id'] && 0 === $plan['packed_with'] && 1 === count( $plan['boxes'] ) );
check( 'its lines are the source orders\' lines — their order ids, their line ids, not the copies on the wrapper', array( 5010, 5011 ) === array_column( $plan['lines'], 'order_id' ) && array( 7101, 7111 ) === array_column( $plan['lines'], 'item_id' ) );
check_same( 'and so are the lines of its box', array( '5010-7101' => 1, '5011-7111' => 2 ), $plan['boxes'][0]['items'] );
check( '1 tang × 3 + 2 gobies × 2 = 7 bags to pull', 7 === $plan['boxes'][0]['layers'] && array( 'fish' => 3, 'bags' => 3, 'layers' => 7 ) === $plan['totals'] );
$plan = fishotel_packing_shipment_plan( 5010 );
check( 'a source order folded into it says so: no box of its own, the combined box named as the owner', true === $plan['available'] && '' === $plan['reason'] && 5010 === $plan['order_id'] && 5900 === $plan['owner_id'] && 5900 === $plan['packed_with'] && array() === $plan['boxes'] && '' === $plan['from'] && false === $plan['split'] );
check_same( 'its own fish are still listed, in no box', array( array( 'order_id' => 5010, 'item_id' => 7101, 'name' => 'Invented Yellow Tang - Big', 'qty' => 1, 'bags' => 1, 'layers_each' => 3, 'why' => 'word:tang', 'boxes' => array() ) ), $plan['lines'] );
check_same( 'with their totals', array( 'fish' => 1, 'bags' => 1, 'layers' => 3 ), $plan['totals'] );
fh_plan_order( 5012, array( array( 7121, 'Reef Salt 5 lb', 1, 0 ) ), array( '_fishotel_fulfilled_by_order' => '5900' ) );
$plan = fishotel_packing_shipment_plan( 5012 );
check( 'a folded order with no fish of its own is still "packed with": the screen has a combined box to point at', true === $plan['available'] && 5900 === $plan['packed_with'] && array() === $plan['lines'] && array() === $plan['boxes'] );

echo "7. What is left to pack, and a fish with no bag size\n";
fh_plan_order( 5020, array( array( 7201, 'Invented Yellow Tang - Big', 2, 103, array( '_fishotel_line_status' => 'shipped' ) ), array( 7202, 'Invented Clown Goby - Small', 3, 101 ), array( 7203, 'Invented Lionfish - Show', 2, 104 ) ) );
wc_get_order( 5020 )->refunded = array( 7202 => 1 );
$plan = fishotel_packing_shipment_plan( 5020 );
check( 'a line that already left is not in the plan; a partly refunded one counts what is left', array( 7202, 7203 ) === array_column( $plan['lines'], 'item_id' ) && 2 === $plan['lines'][0]['qty'] && 2 === $plan['lines'][0]['bags'] );
check( 'a fish with no bag size still counts its bags — in no box, as nothing can place it', 2 === $plan['lines'][1]['bags'] && 3 === $plan['lines'][1]['layers_each'] && array() === $plan['lines'][1]['boxes'] && array( '5020-7202' => 2 ) === $plan['boxes'][0]['items'] );
check( 'so the totals are the whole of what is left, the box only what is in it', array( 'fish' => 4, 'bags' => 4, 'layers' => 10 ) === $plan['totals'] && 4 === $plan['boxes'][0]['layers'] );

$fh_two_per_bag = static function ( $per_bag, $product, $item ) {
	return is_object( $item ) && 7301 === $item->get_id() ? 2 : $per_bag;
};
add_filter( 'fishotel_packing_fish_per_bag', $fh_two_per_bag, 10, 3 );
fh_plan_order( 5030, array( array( 7301, 'Invented Clown Goby - Small', 5, 101 ) ) );
$plan = fishotel_packing_shipment_plan( 5030 );
check( 'two fish to a bag (the fish-per-bag filter): 5 fish in 3 bags, 6 bags to pull', 5 === $plan['boxes'][0]['fish'] && 3 === $plan['boxes'][0]['bags'] && 6 === $plan['boxes'][0]['layers'] && array( 'fish' => 5, 'bags' => 3, 'layers' => 6 ) === $plan['totals'] && array( '5030-7301' => 3 ) === $plan['boxes'][0]['items'], fh_test_export( $plan ) );

echo "8. Worked out once per request\n";
fh_reset_hooks();
Order_Packing::forget();
$worked = 0;
add_filter(
	'fishotel_packing_bag_layers',
	static function ( $layers ) use ( &$worked ) {
		$worked++;
		return $layers;
	}
);
ob_start();
( new Order_Box() )->render_meta_box( wc_get_order( 5001 ) );
( new Order_Box() )->render_meta_box( (object) array( 'ID' => 5001 ) ); // The legacy screen hands a post.
$drawn = (string) ob_get_clean();
$after_box = $worked;
$plan      = fishotel_packing_shipment_plan( 5001 );
$bag       = fishotel_packing_bag_for_item( wc_get_order( 5001 )->get_items()[7001] );
check( 'the order\'s Packing box, the shipment plan and a line\'s bag read one plan: the two fish lines were worked out once', 2 === $after_box && 2 === $worked && true === $plan['available'] && 3 === $bag['layers'] && 2 === substr_count( $drawn, '12 bags to pull' ), (string) $worked );
$fh_confirm( 5001, 'long 20' );
$plan = fishotel_packing_shipment_plan( 5001 );
check( 'a write drops it: the next answer is the new one', 'Long 20' === $plan['boxes'][0]['name'] && $worked > 2 );
$fh_confirm( 5001, 'cube 12' );
fh_reset_hooks();

echo "9. The filter of the contract\n";
add_filter(
	'fishotel_packing_shipment_plan',
	static function ( $plan, $order_id ) {
		$plan['seen'] = $order_id;
		return $plan;
	},
	10,
	2
);
check( 'the answer runs through the filter fishotel_packing_shipment_plan, with the order id', 5001 === fishotel_packing_shipment_plan( 5001 )['seen'] );
fh_reset_hooks();
add_filter( 'fishotel_packing_shipment_plan', '__return_nonsense_for_the_test' );
function __return_nonsense_for_the_test() {
	return 'nonsense';
}
check( 'a filter that answers nonsense is ignored', true === fishotel_packing_shipment_plan( 5001 )['available'] );
fh_reset_hooks();
add_filter(
	'fishotel_packing_shipment_plan',
	static function () {
		throw new \RuntimeException( 'a filter failed' );
	}
);
check( 'and so is one that throws: the function never does', true === fishotel_packing_shipment_plan( 5001 )['available'] );
fh_reset_hooks();

echo "10. ShipTracker's fish count: the bags to pull\n";
$GLOBALS['fh_is_admin'] = false;
Packing::boot(); // Outside wp-admin too: ShipTracker counts in its REST routes.
check( 'the section hooks ShipTracker\'s filter, and nothing else, on a request that is not wp-admin', array( 'fst_box_fish_count' ) === array_keys( $GLOBALS['fh_hooks'] ) );
Order_Packing::forget();
$order = wc_get_order( 5001 );
check( 'the whole order — 2 tangs, 3 gobies and a jar of salt: 12 bags, where ShipTracker counted 6 "fish"', 12 === FST_Supplies::fish_count( $order ) );
check( 'the lines of a box: the tangs 6, the gobies 6, both 12', 6 === FST_Supplies::fish_count( $order, array( 7001 ) ) && 6 === FST_Supplies::fish_count( $order, array( 7002 ) ) && 12 === FST_Supplies::fish_count( $order, array( 7001, 7002 ) ) && 12 === FST_Supplies::fish_count( $order, array( '7001', 7002, 7002 ) ) );
check( 'what is not a fish takes no bag: the salt alone 0, the salt with the tangs 6', 0 === FST_Supplies::fish_count( $order, array( 7003 ) ) && 6 === FST_Supplies::fish_count( $order, array( 7003, 7001 ) ) );
check( 'no lines at all: 0, as ShipTracker counts', 0 === FST_Supplies::fish_count( $order, array() ) );
check( 'a line id that is not on the order counts nothing beside one that is — and alone it is left as ShipTracker counted it', 6 === FST_Supplies::fish_count( $order, array( 7001, 9999 ) ) && 0 === FST_Supplies::fish_count( $order, array( 9999 ) ) && 5 === apply_filters( 'fst_box_fish_count', 5, $order, array( 9999 ) ) );
check( 'it agrees with the plan: the lines one by one add up to the plan\'s total', fishotel_packing_shipment_plan( 5001 )['totals']['layers'] === FST_Supplies::fish_count( $order, array( 7001 ) ) + FST_Supplies::fish_count( $order, array( 7002 ) ) + FST_Supplies::fish_count( $order, array( 7003 ) ) );

$lines = array( 7001 => 2, 7002 => 3, 7003 => 1 );
check( 'the next box, nothing boxed yet (ShipTracker\'s count is the whole order): all 12', 12 === fh_fst_open_count( $order, $lines, array() ) );
check( 'the next box, every line boxed (ShipTracker falls back on the whole order): all 12', 12 === fh_fst_open_count( $order, $lines, array( 7001, 7002, 7003 ) ) );
check( 'the next box with the tangs gone — ShipTracker counts 4 of 6 still open: that share of the bags, 12 × 4 ÷ 6 = 8', 8 === fh_fst_open_count( $order, $lines, array( 7001 ) ) );
check( 'the share is rounded to a whole bag: 1 of 6 open is 2 bags, 5 of 6 is 10', 2 === fh_fst_open_count( $order, $lines, array( 7001, 7002 ) ) && 10 === fh_fst_open_count( $order, $lines, array( 7003 ) ) );
check( 'a count above the order\'s quantity is the whole order, not more', 12 === apply_filters( 'fst_box_fish_count', 60, $order, null ) );
check( 'a count that is no number, or none, is handed back as it came', 'abc' === apply_filters( 'fst_box_fish_count', 'abc', $order, null ) && 0 === apply_filters( 'fst_box_fish_count', 0, $order, null ) && null === apply_filters( 'fst_box_fish_count', null, $order, null ) );
check( 'item ids that are not a list are not read as "the whole order"', 4 === apply_filters( 'fst_box_fish_count', 4, $order, '7001' ) );

$left = wc_get_order( 5020 );
check( 'a line counts WHOLE: the shipped tangs still 6 (the card of the box that left asks), the partly refunded gobies still 6 (the theme scales the refund itself)', 6 === FST_Supplies::fish_count( $left, array( 7201 ) ) && 6 === FST_Supplies::fish_count( $left, array( 7202 ) ) );
check( 'a fish with no bag size is still bagged: 2 lionfish × 3', 6 === FST_Supplies::fish_count( $left, array( 7203 ) ) && 18 === FST_Supplies::fish_count( $left ) );
add_filter( 'fishotel_packing_fish_per_bag', $fh_two_per_bag, 10, 3 );
check( 'two fish to a bag: 5 fish, 3 bags, 6 to pull', 6 === FST_Supplies::fish_count( wc_get_order( 5030 ) ) );

$snapshot = wp_json_encode( array( array( 'name' => 'Invented Dwarf Lionfish', 'qty' => 1, 'kind' => 'fish', 'size' => 'mid' ), array( 'name' => 'Invented Clown Goby', 'qty' => 2, 'kind' => 'fish', 'size' => 'small' ), array( 'name' => 'Credit', 'qty' => 1, 'kind' => 'credit' ) ) );
$invoice  = fh_plan_order( 5040, array( array( 7401, 'Invoice for an invented customer', 1, 140, array( '_fh_invoice_lines' => $snapshot ) ), array( 7402, 'Credit for a lost fish', 1, -1, array( '_fh_invoice_credit' => '1' ) ) ) );
check( 'an Invoice kept as one line is its fish: 1 × 3 + 2 × 2 = 7; a credit is money and takes no bag', 7 === FST_Supplies::fish_count( $invoice, array( 7401 ) ) && 0 === FST_Supplies::fish_count( $invoice, array( 7402 ) ) && 7 === FST_Supplies::fish_count( $invoice ) );

$salt = wc_get_order( 5003 );
check( 'an order Packing has no plan for — no fish line on it — keeps ShipTracker\'s count: 2, and 2 for its line', 2 === FST_Supplies::fish_count( $salt ) && 2 === FST_Supplies::fish_count( $salt, array( 7006 ) ) );

$wrapper = wc_get_order( 5900 );
check( 'a combined box is counted through its source orders: 1 tang × 3 + 2 gobies × 2 = 7 — by their line ids too', 7 === apply_filters( 'fst_box_fish_count', 4, $wrapper, null ) && 3 === apply_filters( 'fst_box_fish_count', 1, $wrapper, array( 7101 ) ) && 4 === apply_filters( 'fst_box_fish_count', 2, $wrapper, array( 7111 ) ) );
check( 'asked by the ids of its own copied lines it cannot tell: ShipTracker\'s count stays', 3 === apply_filters( 'fst_box_fish_count', 3, $wrapper, array( 7901, 7902 ) ) );
check( 'a source order folded into it is counted by its own lines', 3 === FST_Supplies::fish_count( wc_get_order( 5010 ) ) && 4 === FST_Supplies::fish_count( wc_get_order( 5011 ) ) && 3 === FST_Supplies::fish_count( wc_get_order( 5010 ), array( 7101 ) ) );

$read = 0;
add_filter(
	'fishotel_packing_bag_layers',
	static function ( $layers ) use ( &$read ) {
		$read++;
		return $layers;
	}
);
Order_Packing::forget();
foreach ( array( array( 7001 ), array( 7002 ), array( 7003 ), null ) as $ids ) {
	FST_Supplies::fish_count( $order, $ids );
}
check( 'an order is read once per request however many of its lines are asked about (the Add form asks line by line)', 2 === $read, (string) $read );

/**
 * An order that fails when it is read.
 */
class Fh_Broken_Order extends Fh_Fake_Order {

	public function get_items( $type = 'line_item' ) {
		throw new \RuntimeException( 'the order could not be read' );
	}
}
check( 'it never throws into ShipTracker: an order that fails keeps the count', 9 === apply_filters( 'fst_box_fish_count', 9, new Fh_Broken_Order( 5050 ), null ) && 9 === apply_filters( 'fst_box_fish_count', 9, new Fh_Broken_Order( 5051 ), array( 1 ) ) );
check( 'nor for something that is not an order: null, a number, an object with no lines', 9 === apply_filters( 'fst_box_fish_count', 9, null, null ) && 9 === apply_filters( 'fst_box_fish_count', 9, 5001, null ) && 9 === apply_filters( 'fst_box_fish_count', 9, new stdClass(), null ) );
add_filter(
	'fishotel_packing_item_size',
	static function () {
		throw new \RuntimeException( 'another plugin failed' );
	}
);
Order_Packing::forget();
check( 'nor when other code fails underneath it', 6 === apply_filters( 'fst_box_fish_count', 6, wc_get_order( 5020 ), null ) );

Packing::set_enabled( false );
Order_Packing::forget();
check( 'while the section is switched off the count is ShipTracker\'s own again: 6 "fish"', 6 === FST_Supplies::fish_count( $order ) && 2 === FST_Supplies::fish_count( $order, array( 7001 ) ) );
Packing::set_enabled( true );
fh_reset_hooks();

echo "11. The pure halves\n";
$items  = array( 1 => 2, 2 => 3, 3 => 1 );
$layers = array( 1 => 6, 2 => 6 );
check( 'the bags behind a count: lines given, the whole order, a share of it', 6 === Shipment_Plan::bags_for_count( 2, $items, $layers, array( 1 ) ) && 12 === Shipment_Plan::bags_for_count( 6, $items, $layers ) && 6 === Shipment_Plan::bags_for_count( 3, $items, $layers ) && 12 === Shipment_Plan::bags_for_count( 5.99999, $items, $layers ) );
check( 'and when it cannot say: no count, no quantity on the order, none of the lines known', null === Shipment_Plan::bags_for_count( 0, $items, $layers ) && null === Shipment_Plan::bags_for_count( 'x', $items, $layers ) && null === Shipment_Plan::bags_for_count( 2, array(), array() ) && null === Shipment_Plan::bags_for_count( 2, $items, $layers, array( 8, 9 ) ) );
$built = Shipment_Plan::build( array( 'lines' => array(), 'packed_with' => 0 ), array(), null, 12 );
check( 'a suggestion with no fish line builds "no plan"; with ShipTracker absent a box has no row', 'no_plan' === $built['reason'] && null === Shipment_Plan::build( fishotel_packing_suggest( 5001 ), Settings::get()['bags'], null, 5001 )['boxes'][0]['standard'] );
check( 'the plan builder touches nothing: no option, no meta, no database, no hook', 0 === preg_match( '/update_option|update_meta_data|delete_meta_data|->save\s*\(|\$wpdb|add_action|add_filter/', (string) file_get_contents( FISHOTEL_MISC_PATH . 'includes/sections/packing/class-shipment-plan.php' ) ) );

echo "12. Nothing is written\n";
$writes = array();
foreach ( array( 5003, 5010, 5011, 5012, 5020, 5030, 5040, 5900 ) as $id ) {
	$writes = array_merge( $writes, wc_get_order( $id )->writes() );
	foreach ( wc_get_order( $id )->get_items() as $item ) {
		$writes = array_merge( $writes, $item->writes );
	}
}
check( 'the plan and the count wrote nothing to an order or an order line (only confirming a box did, on the orders it was confirmed on)', array() === $writes );
check( 'and the only option ever written is the settings\' own, by the test\'s setup', array( array( Packing::OPTION_KEY, false ) ) === array_values( array_unique( $GLOBALS['fh_option_writes'], SORT_REGULAR ) ) );

fh_finish();
