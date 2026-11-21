<?php
/**
 * Acceptance tests for designs: what a design is (a simple product, or
 * one variation of a variable product), how it is named, which ones are
 * ticked after a save, and what a save keeps of the "Reorder at" numbers.
 * (When a design can be handed out is in stock-test.php.)
 *
 * Three shop shapes are played through, all invented:
 *
 *   A. ONE variable product "Reef Stickers" (#300) with four variations
 *      (#301–#304) on a CUSTOM attribute typed on the product — the
 *      variation stores `attribute_style` => "Blue Tang", capitals and
 *      space as typed.
 *   B. ONE variable product "Logo Stickers" (#400) with variations
 *      (#401–#403) on a GLOBAL attribute — `attribute_pa_colour` =>
 *      "sea-green", a term slug whose name is "Sea Green".
 *   C. A simple product "Round Logo Sticker" (#77).
 *
 * Run from the plugin root:
 *   php includes/sections/sticker-giveaway/tests/designs-test.php
 *
 * @package FisHotel\Misc\Sections\Sticker_Giveaway
 */

require_once __DIR__ . '/wp-stubs.php';

use FisHotel\Misc\Sections\Sticker_Giveaway\Rules;
use FisHotel\Misc\Sections\Sticker_Giveaway\Settings;

$failures = 0;
$checks   = 0;

function check( $label, $condition, $detail = '' ) {
	global $failures, $checks;
	$checks++;

	if ( $condition ) {
		echo "  ok   $label\n";
		return;
	}

	$failures++;
	echo "  FAIL $label" . ( '' !== (string) $detail ? "\n       $detail" : '' ) . "\n";
}

echo "1. The design of a cart line\n";
$custom_line = array(
	'product_id'   => 300,
	'variation_id' => 302,
	'variation'    => array( 'attribute_style' => 'Blue Tang' ),
	'quantity'     => 1,
	'fh_sticker'   => 'free',
);
$global_line = array(
	'product_id'   => 400,
	'variation_id' => 403,
	'variation'    => array( 'attribute_pa_colour' => 'sea-green' ),
	'quantity'     => 2,
	'fh_sticker'   => 'extra',
);
$simple_line = array(
	'product_id'   => 77,
	'variation_id' => 0,
	'quantity'     => 1,
	'fh_sticker'   => 'free',
);
check( 'a variation on a custom attribute: the design is the variation, not the product', 302 === Rules::design_id( $custom_line ) );
check( 'a variation on a global attribute: the same', 403 === Rules::design_id( $global_line ) );
check( 'a simple product: the design is the product', 77 === Rules::design_id( $simple_line ) );
check( 'a line with no variation_id key (an older cart): the product', 77 === Rules::design_id( array( 'product_id' => 77 ) ) );
check( 'something that is not a line: no design', 0 === Rules::design_id( null ) && 0 === Rules::design_id( array() ) );

echo "2. A design's name on the picker\n";
check( 'custom attribute: the value as the owner typed it', 'Blue Tang' === Rules::design_name( array( 'Blue Tang' ), 'Reef Stickers - Blue Tang' ) );
check( 'never "Product - Value"', false === strpos( Rules::design_name( array( 'Blue Tang' ), 'Reef Stickers - Blue Tang' ), 'Reef Stickers' ) );
check( 'global attribute: the term\'s name the caller resolved, not its slug', 'Sea Green' === Rules::design_name( array( 'Sea Green' ), 'Logo Stickers - sea-green' ) );
check( 'two attributes: both values', 'Blue Tang, Large' === Rules::design_name( array( 'Blue Tang', 'Large' ), 'x' ) );
check( 'blank values are left out', 'Large' === Rules::design_name( array( '', '  ', 'Large' ), 'x' ) );
check( 'a simple product keeps its own name', 'Round Logo Sticker' === Rules::design_name( array(), 'Round Logo Sticker' ) );

echo "3. A variation that leaves an attribute on \"Any\" cannot be a design\n";
check( 'custom attribute with a value: can be a design', Rules::variation_is_fixed( array( 'attribute_style' ), array( 'attribute_style' => 'Blue Tang' ) ) );
check( 'global attribute with a value: can be a design', Rules::variation_is_fixed( array( 'attribute_pa_colour' ), array( 'attribute_pa_colour' => 'sea-green' ) ) );
check( 'the attribute on "Any" (stored empty): cannot', ! Rules::variation_is_fixed( array( 'attribute_style' ), array( 'attribute_style' => '' ) ) );
check( 'the attribute not stored at all: cannot', ! Rules::variation_is_fixed( array( 'attribute_style' ), array() ) );
check( 'two attributes, one on "Any": cannot', ! Rules::variation_is_fixed( array( 'attribute_style', 'attribute_pa_size' ), array( 'attribute_style' => 'Blue Tang', 'attribute_pa_size' => '' ) ) );
check( 'a value of "0" is a value', Rules::variation_is_fixed( array( 'attribute_style' ), array( 'attribute_style' => '0' ) ) );

echo "4. Which variations are designs after a save\n";
$children = array( 301, 302, 303, 304 );

$first = Rules::variation_ticks( $children, $children, false, array(), array(), false, array(), array() );
check( 'picked just now, saved before the list was drawn: all four ticked, all four shown', $children === $first['ticked'] && $children === $first['seen'] );

$any = Rules::variation_ticks( $children, array( 301, 302, 303 ), false, array(), array(), false, array(), array() );
check( 'picked just now with one variation on "Any": that one is never ticked', array( 301, 302, 303 ) === $any['ticked'] && $children === $any['seen'] );

$listed = Rules::variation_ticks( $children, $children, true, array( 301, 303 ), $children, true, $children, $children );
check( 'the list was on the page: only what is ticked', array( 301, 303 ) === $listed['ticked'] && $children === $listed['seen'] );

$none = Rules::variation_ticks( $children, $children, true, array(), $children, true, $children, $children );
check( 'the list was on the page with nothing ticked: no designs', array() === $none['ticked'] && $children === $none['seen'] );

$forged = Rules::variation_ticks( $children, array( 301, 302, 303 ), true, array( 304, 999, 301 ), $children, true, array(), array() );
check( 'a posted tick for an "Any" variation or for another product\'s is ignored', array( 301 ) === $forged['ticked'] );

// Later the owner adds a fifth variation (#305) on the product page.
$grown = array( 301, 302, 303, 304, 305 );
$later = Rules::variation_ticks( $grown, $grown, false, array(), array(), true, array( 301, 302, 303, 304 ), array( 301, 302, 303, 304 ) );
check( 'a variation added later is not ticked by a save that did not show it', array( 301, 302, 303, 304 ) === $later['ticked'] );
check( '... and stays "not shown", so the page marks it new', ! in_array( 305, $later['seen'], true ) );

$shown_unticked = Rules::variation_ticks( $grown, $grown, true, array( 301, 302, 303, 304 ), $grown, true, array( 301, 302, 303, 304 ), array( 301, 302, 303, 304 ) );
check( 'shown and left unticked: still not a design, and no longer new', ! in_array( 305, $shown_unticked['ticked'], true ) && in_array( 305, $shown_unticked['seen'], true ) );

$ticked_new = Rules::variation_ticks( $grown, $grown, true, $grown, $grown, true, array( 301, 302, 303, 304 ), array( 301, 302, 303, 304 ) );
check( 'shown and ticked: a design', in_array( 305, $ticked_new['ticked'], true ) );

$raced = Rules::variation_ticks( $grown, $grown, true, array( 301, 302 ), array( 301, 302, 303, 304 ), true, array( 301, 302, 303, 304 ), array( 301, 302, 303, 304 ) );
check( 'added between drawing the page and saving it: not ticked, still new', ! in_array( 305, $raced['ticked'], true ) && ! in_array( 305, $raced['seen'], true ) );

$removed = Rules::variation_ticks( array( 301, 302 ), array( 301, 302 ), true, array( 301, 303 ), array( 301, 302, 303, 304 ), true, $children, $children );
check( 'a variation deleted from the product leaves the pool and the shown list', array( 301 ) === $removed['ticked'] && array( 301, 302 ) === $removed['seen'] );

echo "5. \"Reorder at\" numbers after a save\n";
$pool = array( 301, 302, 303 );
check( 'as typed for the rows on the page; blank removes the reminder', array( 301 => 40, 303 => 0 ) === Rules::reorder_numbers( $pool, array( 301 => '40', 302 => '', 303 => '0' ), $pool, array( 302 => 25 ) ) );
check( 'a count whose row was not on the page keeps its number', array( 301 => 40, 302 => 25 ) === Rules::reorder_numbers( $pool, array( 301 => '40' ), array( 301 ), array( 302 => 25 ) ) );
check( 'a design that left the pool loses its number', array() === Rules::reorder_numbers( array(), array( 301 => '40' ), array( 301 ), array( 301 => 40 ) ) );
check( 'junk and a minus are no reminder; a decimal is rounded down', array( 303 => 12 ) === Rules::reorder_numbers( $pool, array( 301 => 'soon', 302 => '-5', 303 => '12.9' ), $pool, array( 301 => 40 ) ) );
check( 'four variations whose product keeps ONE stock number: one number, on the product', array( 300 => 40 ) === Rules::reorder_numbers( array( 300, 300, 300, 300 ), array( 300 => '40' ), array( 300 ), array() ) );
check( 'a simple-product design has a number too', array( 77 => 15 ) === Rules::reorder_numbers( array( 77 ), array( 77 => '15' ), array( 77 ), array() ) );

$settings = array_merge( Settings::DEFAULTS, array( 'reorder_at' => array( 301 => '50', 302 => '' ) ) );
check( 'reorder_at() reads the setting; blank or absent is none', 50 === Rules::reorder_at( 301, $settings ) && null === Rules::reorder_at( 302, $settings ) && null === Rules::reorder_at( 71, $settings ) );

echo "6. The picker's selection with variation designs\n";
$settings = array_merge(
	Settings::DEFAULTS,
	array(
		'sticker_product_ids' => array( 301, 302, 303, 304, 77 ),
		'upsell_max_qty'      => 5,
	)
);
$sel = Rules::normalise_selection( 302, array( 301 => 2, 77 => 1, 300 => 4 ), $settings );
check( 'a variation is accepted as the free pick', 302 === $sel['free'] );
check( 'add-ons: variations and a simple product side by side; the variable product itself is not a design', array( 301 => 2, 77 => 1 ) === $sel['extras'], json_encode( $sel['extras'] ) );
check( 'the variable product\'s own id cannot be the free pick', 0 === Rules::normalise_selection( 300, array(), $settings )['free'] );
check( 'in_pool() is asked with the design id', Rules::in_pool( Rules::design_id( $custom_line ), $settings ) && ! Rules::in_pool( $custom_line['product_id'], $settings ) );

echo "7. Settings: defaults, and a save from a page that did not show the stock table\n";
check( 'no "Reorder at" number and the email switch on by default', array() === Settings::DEFAULTS['reorder_at'] && true === Settings::DEFAULTS['reorder_email'] );

$dropped = array();
$before  = array_merge( Settings::DEFAULTS, array( 'sticker_product_ids' => array( 77 ), 'reorder_at' => array( 77 => 15 ), 'reorder_email' => false ) );
$saved   = Settings::sanitize( array( 'upsell_price' => '1.00' ), $dropped, $before );
check( 'a form without the stock table (a page opened before this version) keeps the email switch as it was', false === $saved['reorder_email'] );
$saved = Settings::sanitize( array( 'stock_table' => '1', 'reorder_email' => '1' ), $dropped, $before );
check( 'the switch as posted beside the table: on', true === $saved['reorder_email'] );
$saved = Settings::sanitize( array( 'stock_table' => '1' ), $dropped, array_merge( $before, array( 'reorder_email' => true ) ) );
check( '... and unticked: off', false === $saved['reorder_email'] );
check( 'a save stores every key of this version and nothing without a default', array() === array_diff( array( 'sticker_parent_ids', 'sticker_seen_ids', 'reorder_at', 'reorder_email' ), array_keys( $saved ) ) && array() === array_diff( array_keys( $saved ), array_keys( Settings::DEFAULTS ) ) );

echo "\n" . ( $failures ? "$failures of $checks checks FAILED\n" : "All $checks checks passed\n" );
exit( $failures ? 1 : 0 );
