<?php
/**
 * The seam with the Foam Cuts section — BOTH real: Foam Cuts' own
 * readers (Boxes::from_packing() / all(), Orders::order_boxes()) run
 * against this section's real box list and its real
 * fishotel_packing_suggest() / fishotel_packing_confirmed_box().
 *
 * Foam Cuts was built from this section's documentation alone
 * (foam-cuts/tests/packing-contract-test.php stands in for it). Here:
 *
 *   - the functions are declared whenever the plugin is active; while
 *     Packing is switched off Foam Cuts must not take them for the
 *     section (no box list from here, "today's orders" says why);
 *   - there is no fishotel_packing_boxes() function — that name is this
 *     section's FILTER — so the box list is read from the classes;
 *   - both functions take an order ID (never an order object) and answer
 *     boxes in inches under `boxes`, which Foam Cuts reads into its own
 *     units, by name, with a count per box;
 *   - a confirmed box wins over the suggestion; an order nothing can be
 *     boxed for is an order "without a box", not an error.
 *
 * Run from the plugin root:  php includes/sections/packing/tests/foam-cuts-seam-test.php
 */

require_once __DIR__ . '/wp-stubs.php';

if ( ! function_exists( 'has_filter' ) ) {
	function has_filter( $hook, $callback = false ) {
		return false;
	}
}
if ( ! function_exists( 'wc_get_orders' ) ) {
	function wc_get_orders( $args = array() ) {
		return array();
	}
}

require_once FISHOTEL_MISC_PATH . 'includes/sections/foam-cuts/class-foam-cuts.php';
\FisHotel\Misc\Sections\Foam_Cuts\Foam_Cuts::load();

use FisHotel\Misc\Sections\Foam_Cuts\Boxes as Foam_Boxes;
use FisHotel\Misc\Sections\Foam_Cuts\Orders as Foam_Orders;
use FisHotel\Misc\Sections\Foam_Cuts\Settings as Foam_Settings;
use FisHotel\Misc\Sections\Foam_Cuts\Units;
use FisHotel\Misc\Sections\Packing\Order_Packing;
use FisHotel\Misc\Sections\Packing\Packing;

fh_test_settings();
fh_use_sizes( fh_test_sizes() );

/**
 * The invented catalogue and three orders: 700 fits one Cube 12 exactly,
 * 701 needs two boxes, 702 is dry goods only.
 */
function fh_seam_shop() {
	fh_reset_shop();
	fh_product( new Fh_Fake_Product( 100, 'variable' ) );
	fh_product( new Fh_Fake_Product( 101, 'variation', array( 'pa_size' => 'small' ), array(), 100 ) );
	fh_product( new Fh_Fake_Product( 102, 'variation', array( 'pa_size' => 'big' ), array(), 100 ) );
	fh_product( new Fh_Fake_Product( 120, 'simple' ) );
	$GLOBALS['fh_terms'] = array(
		100 => array( 'quarantined-fish' ),
		120 => array( 'dry-goods' ),
	);
	fh_order( new Fh_Fake_Order( 700, array( new Fh_Fake_Item( 1, 'Invented Goby - Small', 6, 100, array(), 101 ), new Fh_Fake_Item( 2, 'Invented Goby - Big', 2, 100, array(), 102 ) ) ) );
	fh_order( new Fh_Fake_Order( 701, array( new Fh_Fake_Item( 3, 'Invented Goby - Big', 14, 100, array(), 102 ) ) ) );
	fh_order( new Fh_Fake_Order( 702, array( new Fh_Fake_Item( 4, 'Reef Salt 5 lb', 1, 120 ) ) ) );
}

/**
 * Box rows as name => "L × W × H" in inches, with the count when there is one.
 *
 * @param array[] $boxes Foam Cuts box rows.
 * @return array
 */
function fh_seam_boxes( array $boxes ) {
	$out = array();
	foreach ( $boxes as $box ) {
		$out[ $box['name'] ] = Units::to_inches( $box['length'] ) . 'x' . Units::to_inches( $box['width'] ) . 'x' . Units::to_inches( $box['height'] ) . ( isset( $box['count'] ) ? ' ×' . $box['count'] : '' );
	}
	return $out;
}

fh_seam_shop();
fh_login( 5, array( 'manage_woocommerce' ) );
$foam = Foam_Settings::defaults();

echo "1. Packing switched off\n";
update_option( Foam_Boxes::SECTIONS_OPTION, array( 'foam-cuts' ) );
check( 'its functions are declared all the same — and answer "unavailable" / nothing', function_exists( 'fishotel_packing_suggest' ) && function_exists( 'fishotel_packing_confirmed_box' ) && false === fishotel_packing_suggest( 700 )['available'] && null === fishotel_packing_confirmed_box( 700 ) );
check( 'there is no fishotel_packing_boxes() function: that name is the filter of its box list', ! function_exists( 'fishotel_packing_boxes' ) );
check( 'Foam Cuts does not take the functions for the section: Packing is not on, no list from it, its own list is used', false === Foam_Boxes::packing_on() && null === Foam_Boxes::from_packing() && Foam_Boxes::SOURCE_OWN === Foam_Boxes::all( $foam )['source'] );
check( '"today\'s orders" says why it cannot be used', false === Foam_Orders::can_ask_packing() && false !== strpos( Foam_Orders::unavailable_reason(), 'The Packing section is not switched on' ) );
check_same( 'and an order has no box from here', array( 'how' => '', 'boxes' => array(), 'unknown' => array() ), Foam_Orders::order_boxes( wc_get_order( 700 ), array() ) );

echo "2. Packing switched on: its box list\n";
update_option( Foam_Boxes::SECTIONS_OPTION, array( 'foam-cuts', 'packing' ) );
Packing::set_enabled( true );
check( 'Foam Cuts sees the section', true === Foam_Boxes::packing_on() && true === Foam_Orders::can_ask_packing() && '' === Foam_Orders::unavailable_reason() );
$list = Foam_Boxes::all( $foam );
check_same( 'the list is Packing\'s own three boxes, read from its classes, in inches → Foam Cuts\' units', array( Foam_Boxes::SOURCE_PACKING, array( 'Cube 8' => '8x8x8', 'Cube 12' => '12x12x12', 'Long 20' => '20x14x12' ) ), array( $list['source'], fh_seam_boxes( $list['boxes'] ) ) );
check_same( 'each filed under its name in lower case — the key Packing files its "how many fit" under', array( 'cube 8', 'cube 12', 'long 20' ), array_column( $list['boxes'], 'key' ) );

echo "3. An order's box: the suggestion, then the confirmed one\n";
$suggest = fishotel_packing_suggest( 700 );
check( 'Packing suggests one Cube 12 for order 700 (asked with the order ID)', true === $suggest['available'] && 'fits' === $suggest['status'] && array( 'Cube 12' ) === array_column( $suggest['boxes'], 'name' ) );
$found = Foam_Orders::order_boxes( wc_get_order( 700 ), $list['boxes'] );
check_same( 'Foam Cuts reads it: suggested, one Cube 12 of its list', array( 'suggested', array( 'Cube 12' => '12x12x12 ×1' ), array( true ), array() ), array( $found['how'], fh_seam_boxes( $found['boxes'] ), array_column( $found['boxes'], 'listed' ), $found['unknown'] ) );

$split = fishotel_packing_suggest( 701 );
$found = Foam_Orders::order_boxes( wc_get_order( 701 ), $list['boxes'] );
$want  = array();
foreach ( $split['boxes'] as $box ) {
	$want[ $box['name'] ] = ( isset( $want[ $box['name'] ] ) ? $want[ $box['name'] ] : 0 ) + 1;
}
check( 'an order Packing splits over several boxes: every box is counted, two of a kind as 2', true === $split['split'] && count( $split['boxes'] ) > 1 && $want === array_column( $found['boxes'], 'count', 'name' ), json_encode( array( $want, array_column( $found['boxes'], 'count', 'name' ) ) ) );

$out = Order_Packing::confirm_request( array( '_wpnonce' => wp_create_nonce( Order_Packing::NONCE_ACTION ), 'order_id' => '700', 'op' => 'confirm', 'choice' => 'long 20', 'count' => '2' ) );
check( 'the owner confirms another box on order 700: two Long 20', 200 === $out['status'] && 2 === fishotel_packing_confirmed_box( 700 )['count'] && array( 'Long 20', 'Long 20' ) === array_column( fishotel_packing_confirmed_box( 700 )['boxes'], 'name' ), json_encode( $out['message'] ?? '' ) );
$found = Foam_Orders::order_boxes( wc_get_order( 700 ), $list['boxes'] );
check_same( 'Foam Cuts now reads the confirmed ones, not the suggestion — and the answer\'s own `count` (2 boxes) does not double them', array( 'confirmed', array( 'Long 20' => '20x14x12 ×2' ) ), array( $found['how'], fh_seam_boxes( $found['boxes'] ) ) );

$found = Foam_Orders::order_boxes( wc_get_order( 702 ), $list['boxes'] );
check_same( 'an order with nothing to bag: no box, no error', array( 'how' => '', 'boxes' => array(), 'unknown' => array() ), $found );
check( '… and Packing was right to say so', 'empty' === fishotel_packing_suggest( 702 )['status'] && array() === fishotel_packing_suggest( 702 )['boxes'] );

echo "4. A box Packing knows and Foam Cuts' list does not\n";
$found = Foam_Orders::order_boxes( wc_get_order( 700 ), array_slice( $list['boxes'], 0, 2 ) );
check_same( 'it is kept by its size as an unlisted box, never dropped', array( array( 'Long 20' => '20x14x12 ×2' ), array( false ) ), array( fh_seam_boxes( $found['boxes'] ), array_column( $found['boxes'], 'listed' ) ) );

Packing::set_enabled( false );

fh_finish();
