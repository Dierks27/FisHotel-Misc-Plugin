<?php
/**
 * Settings, the box list and "today's orders": the settings and what a
 * form may put in them; where the boxes come from (ShipTracker when it
 * has some, else the Packing section, else this section's own list — and
 * the filter over all of them), with the other plugin absent, older or
 * empty; each box's cut list; the orders that ship on a day read through
 * the WooCommerce order API and the Packing section's answers in every
 * shape they may come in. Invented boxes, sizes and order numbers.
 *
 * Run from the plugin root:  php includes/sections/foam-cuts/tests/boxes-orders-test.php
 */

require_once __DIR__ . '/wp-stubs.php';

use FisHotel\Misc\Sections\Foam_Cuts\Boxes;
use FisHotel\Misc\Sections\Foam_Cuts\Orders;
use FisHotel\Misc\Sections\Foam_Cuts\Settings;
use FisHotel\Misc\Sections\Foam_Cuts\Units;

echo "1. Settings\n";
$defaults = Settings::get();
check_same( 'nothing stored: a 96 × 48 in sheet, half an inch thick', array( fh_in( 96 ), fh_in( 48 ), fh_in( 0.5 ) ), array( $defaults['sheet_length'], $defaults['sheet_width'], $defaults['thickness'] ) );
check_same( 'no kerf, turning allowed, a 4 × 4 in smallest leftover', array( 0, true, fh_in( 4 ), fh_in( 4 ) ), array( $defaults['kerf'], $defaults['rotate'], $defaults['min_length'], $defaults['min_width'] ) );
check_same( 'no boxes and no saved cut lists', array( array(), array() ), array( $defaults['boxes'], $defaults['cut_lists'] ) );

$form = Settings::from_form(
	$defaults,
	array( 'sheet_length' => '48', 'sheet_width' => '96 in', 'thickness' => '3/4', 'kerf' => '1/8', 'min_length' => '6', 'min_width' => '10.5' )
);
check_same( 'a form in inches, fractions welcome; no problems', array(), $form['problems'] );
check_same( 'the sheet is laid with its longer side as the length', array( fh_in( 96 ), fh_in( 48 ) ), array( $form['settings']['sheet_length'], $form['settings']['sheet_width'] ) );
check_same( 'thickness, kerf, smallest leftover', array( fh_in( 0.75 ), fh_in( 0.125 ), fh_in( 6 ), fh_in( 10.5 ) ), array( $form['settings']['thickness'], $form['settings']['kerf'], $form['settings']['min_length'], $form['settings']['min_width'] ) );
check_same( 'an unticked "may be turned" box is a no', false, $form['settings']['rotate'] );
check_same( 'ticked is a yes', true, Settings::from_form( $defaults, array( 'rotate' => '1' ) )['settings']['rotate'] );

$bad = Settings::from_form( $defaults, array( 'sheet_length' => 'long', 'thickness' => '0', 'kerf' => '3', 'min_width' => '-1', 'sheet_width' => '40' ) );
check_same( 'what does not read as a size the setting can take is reported, each once', 4, count( $bad['problems'] ) );
check( '…naming the field and what was typed', false !== strpos( $bad['problems'][0], 'Sheet length' ) && false !== strpos( $bad['problems'][0], '"long"' ), $bad['problems'][0] );
check_same( '…and left as it was, while the good field is taken', array( fh_in( 96 ), fh_in( 40 ), fh_in( 0.5 ), 0, fh_in( 4 ) ), array( $bad['settings']['sheet_length'], $bad['settings']['sheet_width'], $bad['settings']['thickness'], $bad['settings']['kerf'], $bad['settings']['min_width'] ) );

Settings::save( array_merge( $form['settings'], array( 'boxes' => array( array( 'name' => 'Cube', 'length' => fh_in( 12 ), 'width' => fh_in( 12 ), 'height' => fh_in( 12 ) ) ) ) ) );
check( 'saved and read back', Settings::get() === Settings::sanitize( $GLOBALS['fh_options'][ Settings::OPTION_KEY ] ) && 'Cube' === Settings::get()['boxes'][0]['name'] && fh_in( 0.75 ) === Settings::get()['thickness'] );
check_same( 'the option is not autoloaded — it is read on this section\'s screens only', false, $GLOBALS['fh_autoload'][ Settings::OPTION_KEY ] );
check_same( 'garbage in the option gives the defaults', $defaults, Settings::sanitize( 'broken' ) );
check_same( 'so do sizes out of range', $defaults, Settings::sanitize( array( 'sheet_length' => -5, 'sheet_width' => 0, 'thickness' => 99999999, 'kerf' => -1, 'min_length' => 'x' ) ) );
delete_option( Settings::OPTION_KEY );

$lists = Settings::clean_cut_lists(
	array(
		'CUBE'  => array( 'name' => 'Cube', 'box' => '120000x120000x120000', 'pieces' => array( array( 'name' => 'Lid', 'length' => fh_in( 12 ), 'width' => fh_in( 12 ), 'count' => 2 ) ) ),
		'empty' => array( 'name' => 'Empty', 'pieces' => array() ),
		'junk'  => 'nothing',
		'odd'   => array( 'name' => 'Odd', 'box' => 'DROP TABLE', 'pieces' => array( array( 'name' => 'A', 'length' => 10000, 'width' => 10000, 'count' => 1 ) ) ),
	)
);
check_same( 'saved cut lists are filed under the box name in lower case; empty ones are dropped', array( 'cube', 'odd' ), array_keys( $lists ) );
check_same( 'a box signature that is not one is forgotten', '', $lists['odd']['box'] );

echo "2. Reading a list of boxes\n";
$read = Boxes::read(
	array(
		array( 'name' => 'Cube', 'length' => '12', 'width' => '12', 'height' => '12' ),
		array( 'name' => 'Flat', 'length' => 16, 'width' => 12.5, 'height' => '9 3/4' ),
		(object) array( 'label' => 'Tall', 'l' => 8, 'w' => 8, 'h' => 20 ),
		array( 'title' => 'By text', 'dims' => '18 x 14 × 12 in' ),
		array( 'name' => 'cube', 'length' => 99, 'width' => 99, 'height' => 99 ),
		array( 'name' => 'No height', 'length' => 5, 'width' => 5 ),
		array( 'name' => '', 'length' => 5, 'width' => 5, 'height' => 5 ),
		array( 'name' => 'Zero', 'length' => 0, 'width' => 5, 'height' => 5 ),
		'text',
	)
);
check_same( 'names and three sizes, however they are spelled', array( 'Cube', 'Flat', 'Tall', 'By text' ), array_column( $read, 'name' ) );
check_same( 'sizes become units', array( fh_in( 16 ), fh_in( 12.5 ), fh_in( 9.75 ) ), array( $read[1]['length'], $read[1]['width'], $read[1]['height'] ) );
check_same( '"18 x 14 × 12 in" is read', array( fh_in( 18 ), fh_in( 14 ), fh_in( 12 ) ), array( $read[3]['length'], $read[3]['width'], $read[3]['height'] ) );
check_same( 'the key is the name in lower case', array( 'cube', 'flat', 'tall', 'by text' ), array_column( $read, 'key' ) );
check( 'a second box of the same name, a box without a name or with a missing or zero size are dropped', 4 === count( $read ) );
check_same( 'no list at all', array(), Boxes::read( null ) );
check( 'found by name whatever the case, or by size either way round', 'Flat' === Boxes::find( $read, 'FLAT' )['name'] && null === Boxes::find( $read, 'nope' ) && 'Flat' === Boxes::by_size( $read, fh_in( 12.5 ), fh_in( 16 ), fh_in( 9.75 ) )['name'] && null === Boxes::by_size( $read, fh_in( 16 ), fh_in( 12.5 ), fh_in( 10 ) ) );
check( 'sizes that are not three sizes', null === Boxes::parse_dims( '12x12' ) && null === Boxes::parse_dims( '12x0x4' ) && null === Boxes::parse_dims( 'Small' ) );

echo "3. Where the boxes come from\n";
$own      = array( 'boxes' => array( array( 'name' => 'Own box', 'length' => fh_in( 10 ), 'width' => fh_in( 8 ), 'height' => fh_in( 6 ) ) ), 'thickness' => fh_in( 0.5 ), 'cut_lists' => array() );
$list     = Boxes::all( $own );
check_same( 'neither ShipTracker nor the Packing section: this section\'s own list', array( 'own', array( 'Own box' ) ), array( $list['source'], array_column( $list['boxes'], 'name' ) ) );
check( 'and the screen is told what was not available, in words', 2 === count( $list['notes'] ) && false !== strpos( $list['notes'][0], 'ShipTracker' ) && false !== strpos( $list['notes'][0], 'not available' ) && false !== strpos( $list['notes'][1], 'Packing section' ) );
check_same( 'ShipTracker absent → null (not "empty")', null, Boxes::from_shiptracker() );
check_same( 'the Packing section absent → null', null, Boxes::from_packing() );
check_same( '…and it cannot be asked', false, Boxes::packing_on() );

// An older ShipTracker: the class is there, the box list is not.
$GLOBALS['fh_st_boxes'] = array();
if ( ! class_exists( 'FST_Settings' ) ) {
	eval( 'class FST_Settings { public static function get_standard_boxes() { if ( "throw" === $GLOBALS["fh_st_boxes"] ) { throw new RuntimeException( "boom" ); } return $GLOBALS["fh_st_boxes"]; } }' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- a stand-in for another plugin, declared mid-test on purpose.
}
$list = Boxes::all( $own );
check( 'ShipTracker there but without boxes yet: still the own list, and the note says where to add them', 'own' === $list['source'] && false !== strpos( $list['notes'][0], 'has no standard boxes yet' ) );
$GLOBALS['fh_st_boxes'] = array(
	array( 'name' => 'Small', 'length' => '10', 'width' => '10', 'height' => '10', 'dims' => '10x10x10', 'foam_cost' => '1.10' ),
	array( 'name' => 'Medium', 'length' => '16', 'width' => '12.5', 'height' => '10', 'dims' => '16x12.5x10', 'foam_cost' => '0.00' ),
);
$list = Boxes::all( $own );
check_same( 'ShipTracker with boxes: its list wins, in its order', array( 'shiptracker', array( 'Small', 'Medium' ) ), array( $list['source'], array_column( $list['boxes'], 'name' ) ) );
check( 'number strings become units; nothing else of the row is kept', array( 'key', 'name', 'length', 'width', 'height' ) === array_keys( $list['boxes'][1] ) && fh_in( 12.5 ) === $list['boxes'][1]['width'] );
check_same( 'nothing was unavailable', array(), $list['notes'] );
$GLOBALS['fh_st_boxes'] = 'throw';
check( 'a ShipTracker that throws is treated as absent — the own list again', 'own' === Boxes::all( $own )['source'] && null === Boxes::from_shiptracker() );
$GLOBALS['fh_st_boxes'] = array();

// The Packing section: its functions exist from here on; what they answer is set per scenario.
$GLOBALS['fh_packing_boxes'] = array();
$GLOBALS['fh_confirmed']     = null;
$GLOBALS['fh_suggest']       = null;
if ( ! function_exists( 'fishotel_packing_boxes' ) ) { // Inside an if: declared when this line is reached, not when the file is read.
	function fishotel_packing_boxes() {
		return $GLOBALS['fh_packing_boxes'];
	}
	function fishotel_packing_confirmed_box( $order ) {
		return is_callable( $GLOBALS['fh_confirmed'] ) ? call_user_func( $GLOBALS['fh_confirmed'], $order ) : null;
	}
	function fishotel_packing_suggest( $order ) {
		return is_callable( $GLOBALS['fh_suggest'] ) ? call_user_func( $GLOBALS['fh_suggest'], $order ) : null;
	}
}

check_same( 'the Packing section can be asked', true, Boxes::packing_on() );
$list = Boxes::all( $own );
check( 'the Packing section without boxes: the own list, with a note', 'own' === $list['source'] && false !== strpos( $list['notes'][1], 'has no boxes yet' ) );
$GLOBALS['fh_packing_boxes'] = array( array( 'name' => 'P-Small', 'length' => 9, 'width' => 9, 'height' => 9 ), array( 'name' => 'P-Long', 'dims' => '24x10x8' ) );
$list                        = Boxes::all( $own );
check_same( 'the Packing section with boxes (ShipTracker empty): its list', array( 'packing', array( 'P-Small', 'P-Long' ) ), array( $list['source'], array_column( $list['boxes'], 'name' ) ) );
$GLOBALS['fh_st_boxes'] = array( array( 'name' => 'Small', 'length' => '10', 'width' => '10', 'height' => '10' ) );
check_same( 'ShipTracker with boxes comes before the Packing section', 'shiptracker', Boxes::all( $own )['source'] );
$GLOBALS['fh_st_boxes'] = array();

// A section of this plugin that is switched off still has its file loaded: do not ask it.
eval( 'namespace FisHotel\Misc\Sections\Packing; class Packing {}' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- a stand-in for the sibling section's class.
update_option( Boxes::SECTIONS_OPTION, array( 'weather', 'foam-cuts' ) );
check( 'the Packing section present but switched off: not asked', false === Boxes::packing_on() && null === Boxes::from_packing() && 'own' === Boxes::all( $own )['source'] );
update_option( Boxes::SECTIONS_OPTION, array( 'weather', 'packing', 'foam-cuts' ) );
check( 'switched on: asked', true === Boxes::packing_on() && 'packing' === Boxes::all( $own )['source'] );

add_filter(
	Boxes::FILTER,
	static function ( $rows, $source ) {
		$GLOBALS['fh_filter_saw'] = array( $rows, $source );
		$rows[]                   = array( 'name' => 'From a filter', 'length' => 20, 'width' => '14 1/2', 'height' => 12 );
		return $rows;
	},
	10,
	2
);
$list = Boxes::all( $own );
check( 'the filter is handed the rows in inches and the source', 'packing' === $GLOBALS['fh_filter_saw'][1] && array( 'name' => 'P-Small', 'length' => 9, 'width' => 9, 'height' => 9 ) === $GLOBALS['fh_filter_saw'][0][0] );
check( 'and what it returns is the list', array( 'P-Small', 'P-Long', 'From a filter' ) === array_column( $list['boxes'], 'name' ) && fh_in( 14.5 ) === $list['boxes'][2]['width'] );
fh_reset_hooks();
add_filter(
	Boxes::FILTER,
	static function () {
		throw new RuntimeException( 'a broken filter' );
	}
);
check_same( 'a filter that throws changes nothing', array( 'P-Small', 'P-Long' ), array_column( Boxes::all( $own )['boxes'], 'name' ) );
fh_reset_hooks();
$GLOBALS['fh_packing_boxes'] = array();

echo "4. A box and its cut list\n";
$boxes = Boxes::read( array( array( 'name' => 'Cube', 'length' => 12, 'width' => 12, 'height' => 12 ), array( 'name' => 'Flat', 'length' => 16, 'width' => 12, 'height' => 10 ) ) );
$saved = array(
	'thickness' => fh_in( 0.5 ),
	'cut_lists' => array(
		'flat' => array( 'name' => 'Flat', 'box' => '160000x120000x90000', 'pieces' => array( array( 'name' => 'Wrap', 'length' => fh_in( 40 ), 'width' => fh_in( 9 ), 'count' => 1 ) ) ),
	),
);
$with = Boxes::with_cut_lists( $boxes, $saved );
check( 'a box without a saved list uses the six suggested pieces', 'suggested' === $with[0]['cut_list'] && 3 === count( $with[0]['pieces'] ) && false === $with[0]['changed'] );
check( 'a box with a saved list uses that', 'saved' === $with[1]['cut_list'] && 'Wrap' === $with[1]['pieces'][0]['name'] );
check_same( '…and is flagged when the box has changed size since (it was 9 in high when the list was saved)', true, $with[1]['changed'] );
check_same( 'the source in words', 'ShipTracker → Settings → Boxes & label costs', Boxes::source_label( 'shiptracker' ) );

echo "5. The orders that ship on a day\n";
check_same( 'the day after is the delivery date', '2026-03-11', Orders::delivery_for( '2026-03-10' ) );
check_same( '…across a month end and a leap day', array( '2026-04-01', '2028-02-29' ), array( Orders::delivery_for( '2026-03-31' ), Orders::delivery_for( '2028-02-28' ) ) );
check_same( 'not a date', array( '', '' ), array( Orders::delivery_for( '2026-02-30' ), Orders::delivery_for( 'tomorrow' ) ) );
check_same( 'today is the site\'s date', '2026-03-10', Orders::today() );
check_same( 'the statuses of an order still to be boxed', array( 'processing', 'fulfillment' ), Orders::statuses() );

$boxes = Boxes::read( array( array( 'name' => 'Small', 'length' => 10, 'width' => 10, 'height' => 10 ), array( 'name' => 'Medium', 'length' => 16, 'width' => 12, 'height' => 10 ), array( 'name' => 'Long', 'length' => 24, 'width' => 10, 'height' => 8 ) ) );

$GLOBALS['fh_orders'] = array(
	101 => new WC_Order( 101, 'processing', array( '_fishotel_shipping_date' => '2026-03-11' ) ),
	102 => new WC_Order( 102, 'processing', array( '_fishotel_shipping_date' => '2026-03-11' ) ),
	103 => new WC_Order( 103, 'fulfillment', array( '_fishotel_shipping_date' => '2026-03-11' ) ),
	104 => new WC_Order( 104, 'processing', array( '_fishotel_shipping_date' => '2026-03-11', '_fishotel_fulfilled_by_order' => '103' ) ),
	105 => new WC_Order( 105, 'processing', array( '_fishotel_shipping_date' => '2026-03-12' ) ),
	106 => new WC_Order( 106, 'completed', array( '_fishotel_shipping_date' => '2026-03-11' ) ),
	107 => new WC_Order( 107, 'on-hold', array( '_fishotel_shipping_date' => '2026-03-11' ) ),
	108 => new WC_Order( 108, 'processing', array() ),
	109 => new WC_Order( 109, 'processing', array( '_fishotel_shipping_date' => '2026-03-11' ) ),
);

$GLOBALS['fh_confirmed'] = static function ( $order ) {
	$map = array( 101 => 'Small', 103 => array( 'name' => 'Medium', 'length' => 16, 'width' => 12, 'height' => 10 ) );
	return isset( $map[ $order->get_id() ] ) ? $map[ $order->get_id() ] : null;
};
$GLOBALS['fh_suggest'] = static function ( $order ) {
	$map = array( 101 => 'Long', 102 => array( 'box' => 'medium', 'bags' => 6 ) );
	return isset( $map[ $order->get_id() ] ) ? $map[ $order->get_id() ] : null;
};

check_same( 'with WooCommerce and the Packing section there: usable', '', Orders::unavailable_reason() );
$found = Orders::for_ship_date( '2026-03-10', $boxes );
check( 'answered, for the day after', true === $found['ok'] && '2026-03-11' === $found['delivery_date'] );
check_same( 'the orders: open, delivering that day, not folded into a combined box', array( 101, 102, 103, 109 ), array_column( $found['orders'], 'id' ) );
check_same( 'a confirmed box is used before a suggested one; a suggested one where nothing is confirmed', array( 'confirmed', 'suggested', 'confirmed', '' ), array_column( $found['orders'], 'how' ) );
check_same( 'how many of each box', array( 'small' => 1, 'medium' => 2 ), $found['counts'] );
check_same( 'the order nobody knows a box for is listed, not guessed', array( 109 ), $found['without_box'] );
$query = end( $GLOBALS['fh_order_queries'] );
check( 'one query through wc_get_orders(), by status, as objects', 1 === count( $GLOBALS['fh_order_queries'] ) && array( 'processing', 'fulfillment' ) === $query['status'] && 'objects' === $query['return'] && -1 === $query['limit'] );
check( 'no meta query is handed to a store that would drop it (HPOS off) — every order is checked through get_meta() instead', ! isset( $query['meta_query'] ) && $GLOBALS['fh_orders'][105]->meta_reads >= 1 );
check_same( 'another day: other orders', array( 105 ), array_column( Orders::for_ship_date( '2026-03-11', $boxes )['orders'], 'id' ) );
check( 'a day nothing ships', true === Orders::for_ship_date( '2026-03-20', $boxes )['ok'] && array() === Orders::for_ship_date( '2026-03-20', $boxes )['orders'] );
check( 'not a date: refused', false === Orders::for_ship_date( 'soon', $boxes )['ok'] );

add_filter(
	'fishotel_foam_cuts_order_statuses',
	static function ( $statuses ) {
		$statuses[] = 'wc-on-hold';
		return $statuses;
	}
);
check_same( 'the statuses can be widened by a filter (wc- prefix or not)', array( 101, 102, 103, 107, 109 ), array_column( Orders::for_ship_date( '2026-03-10', $boxes )['orders'], 'id' ) );
fh_reset_hooks();

echo "6. What the Packing section may answer\n";
$read = static function ( $answer ) use ( $boxes ) {
	$out = array();
	$got = Orders::read_answer( $answer, $boxes );
	foreach ( $got['boxes'] as $box ) {
		$out[] = $box['count'] . ' × ' . $box['name'] . ( $box['listed'] ? '' : ' (unlisted)' );
	}
	foreach ( $got['unknown'] as $name ) {
		$out[] = '? ' . $name;
	}
	return $out;
};
check_same( 'a box name', array( '1 × Small' ), $read( 'small' ) );
check_same( 'a size on the list', array( '1 × Medium' ), $read( '16x12x10' ) );
check_same( 'a size on the list, length and width the other way round', array( '1 × Medium' ), $read( '12 x 16 x 10' ) );
check_same( 'a box row', array( '1 × Long' ), $read( array( 'name' => 'Long', 'length' => 24, 'width' => 10, 'height' => 8 ) ) );
check_same( 'a box row with a count', array( '3 × Small' ), $read( array( 'name' => 'Small', 'qty' => 3 ) ) );
check_same( '{ box: name }', array( '1 × Small' ), $read( array( 'box' => 'Small', 'confidence' => 0.9 ) ) );
check_same( '{ box: row, count }', array( '2 × Medium' ), $read( array( 'box' => array( 'name' => 'Medium' ), 'count' => 2 ) ) );
check_same( '{ boxes: [ … ] }', array( '2 × Small', '1 × Long' ), $read( array( 'boxes' => array( 'Small', array( 'name' => 'Long' ), 'small' ) ) ) );
check_same( 'a list of names', array( '1 × Small', '1 × Medium' ), $read( array( 'Small', 'Medium' ) ) );
check_same( 'an object', array( '1 × Long' ), $read( (object) array( 'name' => 'Long' ) ) );
check_same( 'a row known only by its size: kept as an unlisted box', array( '1 × 14 × 9 × 7 (unlisted)' ), $read( array( 'length' => 14, 'width' => 9, 'height' => 7 ) ) );
check_same( 'an unlisted box with a name of its own', array( '2 × Odd one (unlisted)' ), $read( array( 'name' => 'Odd one', 'dims' => '14x9x7', 'quantity' => 2 ) ) );
check_same( 'a name nobody knows is reported, never guessed', array( '? Jumbo' ), $read( 'Jumbo' ) );
check_same( 'nothing', array(), $read( null ) );
check_same( 'false, an empty string, an empty list', array( array(), array(), array() ), array( $read( false ), $read( '' ), $read( array() ) ) );
check_same( 'a row of things that are not boxes', array(), $read( array( 'bags' => 4, 'weight' => 11 ) ) );

$GLOBALS['fh_confirmed'] = static function ( $order ) {
	throw new RuntimeException( 'the Packing section is having a bad day' );
};
$found = Orders::for_ship_date( '2026-03-10', $boxes );
check( 'a confirmed-box function that throws: the suggestion is used, nothing breaks', true === $found['ok'] && array( 'long' => 1, 'medium' => 1 ) === $found['counts'], fh_export( $found['counts'] ) );
$GLOBALS['fh_confirmed'] = static function ( $order ) {
	if ( is_object( $order ) ) {
		throw new TypeError( 'expects an order id' );
	}
	return 101 === $order ? 'Small' : null;
};
check_same( 'a function that only takes an order id is asked again with the id', array( 'small' => 1, 'medium' => 1 ), Orders::for_ship_date( '2026-03-10', $boxes )['counts'] );
$GLOBALS['fh_confirmed'] = null;
$GLOBALS['fh_suggest']   = static function ( $order ) {
	$map = array(
		101 => array( 'boxes' => array( array( 'name' => 'Small', 'count' => 2 ), '14x9x7' ) ),
		102 => 'Jumbo',
		103 => array( 'length' => 14, 'width' => 9, 'height' => 7 ),
	);
	return isset( $map[ $order->get_id() ] ) ? $map[ $order->get_id() ] : null;
};
$found = Orders::for_ship_date( '2026-03-10', $boxes );
check_same( 'two boxes for one order, one of them not on the list', array( 'small' => 2 ), $found['counts'] );
check( 'the unlisted size is counted across orders, with its size', 1 === count( $found['extra'] ) && 2 === $found['extra'][0]['count'] && fh_in( 14 ) === $found['extra'][0]['length'] && false === $found['extra'][0]['listed'] );
check_same( 'the unknown name is reported', array( 'Jumbo' ), $found['unknown'] );
check_same( 'and its order counts as "no box yet"', array( 102, 109 ), $found['without_box'] );

add_filter(
	Orders::FILTER,
	static function ( $rows, $order ) {
		if ( 109 === $order->get_id() ) {
			$rows[] = array( 'name' => 'Long', 'count' => 1 );
		}
		return $rows;
	},
	10,
	2
);
$found = Orders::for_ship_date( '2026-03-10', $boxes );
check( 'the filter can supply the box of an order nobody else knows', array( 'small' => 2, 'long' => 1 ) === $found['counts'] && array( 102 ) === $found['without_box'] );
$hows = array_column( $found['orders'], 'how', 'id' );
check_same( 'and that order says where its box came from', 'filter', $hows[109] );
fh_reset_hooks();

echo "7. Without the Packing section\n";
update_option( Boxes::SECTIONS_OPTION, array( 'weather', 'foam-cuts' ) );
check( 'switched off: today\'s orders says plainly why not, and to type the numbers', false !== strpos( Orders::unavailable_reason(), 'Packing section is not switched on' ) && false !== strpos( Orders::unavailable_reason(), 'Type the number of each box' ) );
$queries = count( $GLOBALS['fh_order_queries'] );
$found   = Orders::for_ship_date( '2026-03-10', $boxes );
check( 'no order is read at all', false === $found['ok'] && $queries === count( $GLOBALS['fh_order_queries'] ) && '' !== $found['message'] );
add_filter( Orders::FILTER, '__return_empty_array' );
check_same( 'with only the filter answering it is usable again', '', Orders::unavailable_reason() );
fh_reset_hooks();
update_option( Boxes::SECTIONS_OPTION, array( 'weather', 'packing', 'foam-cuts' ) );

$writes = 0;
foreach ( get_class_methods( 'WC_Order' ) as $method ) {
	$writes += (int) preg_match( '/^(set_|update_|add_|delete_|save)/', $method );
}
check_same( 'the order double has no way to be written to — and nothing here tried', 0, $writes );
check_same( 'Units are what everything above was measured in', 10000, Units::PER_INCH );

fh_finish();
