<?php
/**
 * Acceptance tests for designs: what a design is (a simple product, or
 * one variation of a variable product), how it is named, which ones are
 * ticked after a save, which keep a giveaway pile, and when one can be
 * handed out.
 *
 * Two shop shapes are played through, both invented:
 *
 *   A. ONE variable product "Reef Stickers" (#300) with four variations
 *      (#301–#304) on a CUSTOM attribute typed on the product — the
 *      variation stores `attribute_style` => "Blue Tang", capitals and
 *      space as typed.
 *   B. ONE variable product "Logo Stickers" (#400) with variations
 *      (#401–#403) on a GLOBAL attribute — `attribute_pa_colour` =>
 *      "sea-green", a term slug whose name is "Sea Green".
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

echo "5. Which designs keep a giveaway pile\n";
// Before this version: three simple products in the pool, nothing stored about piles.
$old_pool = array( 71, 72, 73 );
check( 'a simple design already in the pool keeps today\'s behaviour (no row was posted for it)', array() === Rules::pile_ids( $old_pool, $old_pool, array(), array(), array() ) );
check( '... also when the page showed its row with the switch off', array() === Rules::pile_ids( $old_pool, $old_pool, array(), array(), $old_pool ) );
check( '... until the owner switches it on', array( 72 ) === Rules::pile_ids( $old_pool, $old_pool, array(), array( 72 ), $old_pool ) );
check( 'a design picked just now keeps a pile', array( 301, 302, 303, 304 ) === Rules::pile_ids( array( 71, 301, 302, 303, 304 ), array( 71 ), array(), array(), array( 71 ) ) );
check( 'a simple product picked just now keeps a pile too', array( 74 ) === Rules::pile_ids( array( 71, 74 ), array( 71 ), array(), array(), array( 71 ) ) );
check( 'switched on before, row not posted: stays on', array( 301 ) === Rules::pile_ids( array( 301, 71 ), array( 301, 71 ), array( 301 ), array(), array() ) );
check( 'switched off on the page: off', array() === Rules::pile_ids( array( 301 ), array( 301 ), array( 301 ), array(), array( 301 ) ) );
check( 'a design that left the pool keeps nothing', array() === Rules::pile_ids( array(), array( 301 ), array( 301 ), array( 301 ), array( 301 ) ) );
check( 'a posted switch for a design outside the pool is ignored', array() === Rules::pile_ids( array( 71 ), array( 71 ), array(), array( 999 ), array( 999 ) ) );

$settings = array_merge( Settings::DEFAULTS, array( 'sticker_product_ids' => array( 71, 301, 302 ), 'pile_ids' => array( '301', 302 ), 'reorder_at' => array( 301 => '50', 302 => '' ) ) );
check( 'keeps_pile() reads the setting', Rules::keeps_pile( 301, $settings ) && Rules::keeps_pile( 302, $settings ) && ! Rules::keeps_pile( 71, $settings ) );
check( 'with nothing stored (the section as it was) no design keeps a pile', ! Rules::keeps_pile( 71, Settings::DEFAULTS ) && array() === Settings::DEFAULTS['pile_ids'] );
check( 'reorder_at() reads the setting; blank is none', 50 === Rules::reorder_at( 301, $settings ) && null === Rules::reorder_at( 302, $settings ) && null === Rules::reorder_at( 71, $settings ) );

echo "6. Reorder numbers after a save\n";
$pool = array( 301, 302, 303 );
check( 'as typed for the rows on the page; blank removes the reminder', array( 301 => 40, 303 => 0 ) === Rules::reorder_numbers( $pool, array( 301 => '40', 302 => '', 303 => '0' ), $pool, array( 302 => 25 ) ) );
check( 'a design whose row was not on the page keeps its number', array( 301 => 40, 302 => 25 ) === Rules::reorder_numbers( $pool, array( 301 => '40' ), array( 301 ), array( 302 => 25 ) ) );
check( 'a design that left the pool loses its number', array() === Rules::reorder_numbers( array(), array( 301 => '40' ), array( 301 ), array( 301 => 40 ) ) );
check( 'junk is no reminder', array() === Rules::reorder_numbers( array( 301 ), array( 301 => 'soon' ), array( 301 ), array( 301 => 40 ) ) );

echo "7. When a design can be handed out\n";
function facts( array $over = array() ) {
	return array_merge(
		array(
			'type'             => 'variation',
			'published'        => true,
			'parent_published' => true,
			'has_price'        => true,
			'purchasable'      => true,
			'fixed'            => true,
			'in_stock'         => true,
			'keeps_pile'       => true,
			'paper'            => 200,
			'basis'            => 200,
		),
		$over
	);
}
check( 'a variation with stickers in its giveaway pile: offered', array() === Rules::blockers( facts(), 10 ) );
check( '... WHATEVER the shop pile says: out of stock in the shop, still offered', array() === Rules::blockers( facts( array( 'in_stock' => false ) ), 10 ) );
check( 'the pile at its reserve (20 of 200 at 10%): not offered', array( 'pile_reserve' ) === Rules::blockers( facts( array( 'paper' => 20 ) ), 10 ) );
check( 'one above the reserve: offered', array() === Rules::blockers( facts( array( 'paper' => 21 ) ), 10 ) );
check( 'an empty pile: not offered, and says "empty" rather than "reserve"', array( 'pile_empty' ) === Rules::blockers( facts( array( 'paper' => 0, 'basis' => 0 ) ), 10 ) );
check( 'in stock in the shop does not make up for an empty giveaway pile', array( 'pile_empty' ) === Rules::blockers( facts( array( 'paper' => 0, 'basis' => 0, 'in_stock' => true ) ), 10 ) );
check( 'the variation not enabled: not offered', array( 'unpublished' ) === Rules::blockers( facts( array( 'published' => false ) ), 10 ) );
check( 'its product not published: not offered', array( 'parent_unpublished' ) === Rules::blockers( facts( array( 'parent_published' => false ) ), 10 ) );
check( 'no price: not offered', array( 'no_price' ) === Rules::blockers( facts( array( 'has_price' => false, 'purchasable' => false ) ), 10 ) );
check( 'a price but not purchasable: not offered', array( 'not_purchasable' ) === Rules::blockers( facts( array( 'purchasable' => false ) ), 10 ) );
check( 'an attribute on "Any": not offered', array( 'any_attribute' ) === Rules::blockers( facts( array( 'fixed' => false ) ), 10 ) );
check( 'every reason is named, not only the first', array( 'unpublished', 'no_price', 'pile_reserve' ) === Rules::blockers( facts( array( 'published' => false, 'has_price' => false, 'purchasable' => false, 'paper' => 5 ) ), 10 ) );
check( 'a variable product itself, or any other kind, is never a design', array( 'type' ) === Rules::blockers( facts( array( 'type' => 'other' ) ), 10 ) );
check( 'the same for a variation on a global attribute (the rule does not care which)', array() === Rules::blockers( facts( array( 'paper' => 3, 'basis' => 3 ) ), 10 ) );

// A simple design that keeps no giveaway pile: exactly the three checks it always had.
$fh_old = static function ( array $f ) {
	return $f['published'] && $f['purchasable'] && $f['in_stock'];
};
$mismatch = array();
foreach ( array( true, false ) as $published ) {
	foreach ( array( true, false ) as $purchasable ) {
		foreach ( array( true, false ) as $in_stock ) {
			foreach ( array( true, false ) as $has_price ) {
				$f = facts(
					array(
						'type'        => 'simple',
						'keeps_pile'  => false,
						'paper'       => 0,
						'basis'       => 0,
						'published'   => $published,
						'purchasable' => $purchasable,
						'in_stock'    => $in_stock,
						'has_price'   => $has_price,
					)
				);
				// A product with no price is never purchasable in WooCommerce; skip the combination that cannot happen.
				if ( ! $has_price && $purchasable ) {
					continue;
				}
				if ( $fh_old( $f ) !== ( array() === Rules::blockers( $f, 10 ) ) ) {
					$mismatch[] = json_encode( $f );
				}
			}
		}
	}
}
check( 'a simple design without a giveaway pile is offered exactly when it was before: published, purchasable, in stock', array() === $mismatch, implode( ' | ', $mismatch ) );
check( '... its own stock decides: out of stock is not offered', array( 'out_of_stock' ) === Rules::blockers( facts( array( 'type' => 'simple', 'keeps_pile' => false, 'in_stock' => false ) ), 10 ) );
check( '... and a giveaway pile row left over from an earlier try is not read', array() === Rules::blockers( facts( array( 'type' => 'simple', 'keeps_pile' => false, 'paper' => 0, 'basis' => 0 ) ), 10 ) );
check( '... a simple product is never asked about attributes or a parent', array() === Rules::blockers( facts( array( 'type' => 'simple', 'keeps_pile' => false, 'fixed' => false, 'parent_published' => false ) ), 10 ) );

echo "8. The picker's selection with variation designs\n";
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

echo "9. Settings: defaults, and a save that says nothing about the new fields\n";
check( 'the loss percent defaults to 10 and the email switch to on', 10 === Settings::DEFAULTS['loss_percent'] && true === Settings::DEFAULTS['reorder_email'] );

$dropped = array();
$before  = array_merge( Settings::DEFAULTS, array( 'loss_percent' => 15, 'reorder_email' => false ) );
$saved   = Settings::sanitize( array( 'upsell_price' => '1.00' ), $dropped, $before );
check( 'a form without the percent (a page opened before this version) keeps the stored percent and switch', 15 === $saved['loss_percent'] && false === $saved['reorder_email'] );

$saved = Settings::sanitize( array( 'loss_percent' => '25', 'reorder_email' => '1' ), $dropped, $before );
check( 'the percent and the switch as posted', 25 === $saved['loss_percent'] && true === $saved['reorder_email'] );
$saved = Settings::sanitize( array( 'loss_percent' => '25' ), $dropped, $before );
check( 'the switch unticked (not posted beside the percent) is off', false === $saved['reorder_email'] );
$saved = Settings::sanitize( array( 'loss_percent' => '' ), $dropped, $before );
check( 'a blank percent restores the default; over 50 is 50', 10 === $saved['loss_percent'] && 50 === Settings::sanitize( array( 'loss_percent' => '90' ), $dropped, $before )['loss_percent'] );
check( 'a save always stores every new key', array() === array_diff( array( 'sticker_parent_ids', 'sticker_seen_ids', 'pile_ids', 'reorder_at', 'loss_percent', 'reorder_email' ), array_keys( $saved ) ) );
check( 'every stored key has a default (so an older stored option is filled in)', array() === array_diff( array_keys( $saved ), array_keys( Settings::DEFAULTS ) ) );

echo "\n" . ( $failures ? "$failures of $checks checks FAILED\n" : "All $checks checks passed\n" );
exit( $failures ? 1 : 0 );
