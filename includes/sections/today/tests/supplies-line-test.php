<?php
/**
 * ShipTracker's line on the Today page — "Supplies: 2 to reorder": the
 * line itself (pure), and the filter with ShipTracker's supplies on (a
 * stand-in FST_Supplies with invented supplies), empty (nothing low),
 * off (not hooked until this section boots in wp-admin; an older
 * ShipTracker without get_low_supplies() adds nothing) and for a user who
 * may not open ShipTracker's settings.
 *
 * Run from the plugin root:
 *   php includes/sections/today/tests/supplies-line-test.php
 *
 * @package FisHotel\Misc\Sections\Today
 */

require_once __DIR__ . '/wp-stubs.php';

use FisHotel\Misc\Sections\Today\Supplies_Line;

if ( ! function_exists( 'current_user_can' ) ) {
	function current_user_can( $capability ) {
		return in_array( $capability, $GLOBALS['fh_test_caps'] ?? array(), true );
	}
}
if ( ! function_exists( '_n' ) ) {
	function _n( $single, $plural, $number, $domain = null ) {
		return 1 === (int) $number ? $single : $plural;
	}
}

require_once FISHOTEL_MISC_PATH . 'includes/sections/today/class-supplies-line.php';

$GLOBALS['fh_test_caps'] = array( 'manage_woocommerce' );

echo "1. The line\n";
check( 'nothing low: no line', null === Supplies_Line::line( array( 'low' => array() ), 'u' ) && null === Supplies_Line::line( array(), 'u' ) );
$fh_line = Supplies_Line::line( array( 'low' => array( array( 'name' => 'Invented foam sheet' ), array( 'name' => 'Invented heat pack' ) ) ), 'https://example.test/s' );
check( '"Supplies: 2 to reorder", under Shipping, with their names', 'Supplies: 2 to reorder' === $fh_line['text'] && 'shipping' === $fh_line['group'] && 'Invented foam sheet, Invented heat pack' === $fh_line['detail'] && 2 === $fh_line['count'] );
$fh_six = Supplies_Line::line( array( 'low' => array_fill( 0, 6, array( 'name' => 'Bag' ) ) ), '' );
check( 'six: four names "and 2 more"', 'Bag, Bag, Bag, Bag and 2 more' === $fh_six['detail'] );

echo "2. Off\n";
check( 'not hooked until the section boots in wp-admin', array() === apply_filters( 'fishotel_today_lines', array() ) );
Supplies_Line::register();
check( 'ShipTracker is not there (no FST_Supplies): no line', false === Supplies_Line::available() && array() === apply_filters( 'fishotel_today_lines', array() ) );

// A ShipTracker build of the right version, with invented supplies —
// declared only now (inside a block, so PHP does not declare it early).
if ( ! class_exists( 'FST_Supplies' ) ) {
	class FST_Supplies {
		public static $low = array();

		public static function get_low_supplies() {
			return self::$low;
		}
	}
}

echo "3. On\n";
Supplies_Line::use_source();
check( 'ShipTracker\'s build says what is low, and nothing is: no line (empty)', true === Supplies_Line::available() && array() === apply_filters( 'fishotel_today_lines', array() ) );
FST_Supplies::$low = array(
	array(
		'id'             => 'invented-1',
		'name'           => 'Invented box liner',
		'unit'           => 'each',
		'on_hand'        => '3.00',
		'reorder_level'  => '10.00',
		'recount_needed' => false,
	),
);
Supplies_Line::use_source();
$fh_lines = apply_filters( 'fishotel_today_lines', array( array( 'key' => 'other', 'text' => 'Another section' ) ) );
check( 'one supply at or under its level: the line joins the others', 2 === count( $fh_lines ) && 'Supplies: 1 to reorder' === $fh_lines[1]['text'] && 'Invented box liner' === $fh_lines[1]['detail'] );
check( '… and leads to ShipTracker\'s Supplies list', false !== strpos( $fh_lines[1]['url'], 'page=fst-settings' ) && false !== strpos( $fh_lines[1]['url'], 'tab=boxes' ) && '#fst-supplies-list' === substr( $fh_lines[1]['url'], -18 ) );
FST_Supplies::$low = array();
check( 'worked out once per request', 2 === count( apply_filters( 'fishotel_today_lines', array( array( 'text' => 'x' ) ) ) ) );
$GLOBALS['fh_test_caps'] = array();
Supplies_Line::use_source();
check( 'a user who may not open ShipTracker\'s settings: no line', array() === apply_filters( 'fishotel_today_lines', array() ) );

fh_finish();
