<?php
/**
 * Acceptance tests for the ONE count: a design's count is its product's
 * own WooCommerce stock, and the arithmetic the section does on it —
 * whether a design is offered, what a sticker line may keep beside the
 * other lines of the same count, how the count reads in the stock table,
 * and when the reorder email is written. Pure: plain numbers in, plain
 * answers out.
 *
 * The shop played through is invented: ONE variable product "Reef
 * Stickers" (#300) with four variations (#301–#304) on a custom
 * attribute, each managing its OWN stock; a variable product "Logo
 * Stickers" (#400) on a global attribute that keeps ONE stock number for
 * its variations (#401–#403); and a simple product "Round Logo Sticker"
 * (#77).
 *
 * Run from the plugin root:
 *   php includes/sections/sticker-giveaway/tests/stock-test.php
 *
 * @package FisHotel\Misc\Sections\Sticker_Giveaway
 */

require_once __DIR__ . '/wp-stubs.php';

use FisHotel\Misc\Sections\Sticker_Giveaway\Rules;

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
			'stock'            => 120,
		),
		$over
	);
}

echo "1. Is there a sticker to hand out?\n";
check( 'stock tracked, 120 there: yes', Rules::has_stock( true, 120 ) );
check( 'stock tracked, 1 there: yes', Rules::has_stock( true, 1 ) );
check( 'stock tracked, 0 there: no', ! Rules::has_stock( false, 0 ) );
check( 'backorders allowed and 0 there (WooCommerce says "in stock"): still no — never a sticker that is not there', ! Rules::has_stock( true, 0 ) );
check( 'backorders allowed and the stock below 0: no', ! Rules::has_stock( true, -3 ) );
check( 'stock tracked and some there, but WooCommerce says out of stock (its own threshold): no', ! Rules::has_stock( false, 2 ) );
check( 'stock not tracked: as WooCommerce says — in stock', Rules::has_stock( true, null ) );
check( '... or out of stock', ! Rules::has_stock( false, null ) );

echo "2. When a design can be handed out\n";
check( 'a variation with its own stock: offered', array() === Rules::blockers( facts() ) );
check( 'its stock at 0: not offered', array( 'out_of_stock' ) === Rules::blockers( facts( array( 'stock' => 0, 'in_stock' => false ) ) ) );
check( 'its stock at 0 with backorders allowed: not offered', array( 'out_of_stock' ) === Rules::blockers( facts( array( 'stock' => 0 ) ) ) );
check( 'a variation whose product keeps the stock (the facts carry the product\'s number): the same rule', array() === Rules::blockers( facts( array( 'stock' => 5 ) ) ) && array( 'out_of_stock' ) === Rules::blockers( facts( array( 'stock' => 0 ) ) ) );
check( 'stock not tracked: offered while WooCommerce says in stock', array() === Rules::blockers( facts( array( 'stock' => null ) ) ) && array( 'out_of_stock' ) === Rules::blockers( facts( array( 'stock' => null, 'in_stock' => false ) ) ) );
check( 'the variation not enabled: not offered', array( 'unpublished' ) === Rules::blockers( facts( array( 'published' => false ) ) ) );
check( 'its product not published: not offered', array( 'parent_unpublished' ) === Rules::blockers( facts( array( 'parent_published' => false ) ) ) );
check( 'no price: not offered', array( 'no_price' ) === Rules::blockers( facts( array( 'has_price' => false, 'purchasable' => false ) ) ) );
check( 'a price but not purchasable: not offered', array( 'not_purchasable' ) === Rules::blockers( facts( array( 'purchasable' => false ) ) ) );
check( 'an attribute on "Any": not offered', array( 'any_attribute' ) === Rules::blockers( facts( array( 'fixed' => false ) ) ) );
check( 'every reason is named, not only the first', array( 'unpublished', 'no_price', 'out_of_stock' ) === Rules::blockers( facts( array( 'published' => false, 'has_price' => false, 'purchasable' => false, 'stock' => 0 ) ) ) );
check( 'a variable product itself, or any other kind, is never a design', array( 'type' ) === Rules::blockers( facts( array( 'type' => 'other' ) ) ) );

// A simple design whose product does not allow backorders: exactly the three checks it had before.
$mismatch = array();
foreach ( array( true, false ) as $published ) {
	foreach ( array( true, false ) as $purchasable ) {
		foreach ( array( 5, 0, null ) as $stock ) {
			foreach ( array( true, false ) as $in_stock ) {
				// WooCommerce keeps "in stock" and a tracked stock number in step when backorders are off.
				if ( null !== $stock && ( $stock > 0 ) !== $in_stock ) {
					continue;
				}
				$f = facts( array( 'type' => 'simple', 'published' => $published, 'purchasable' => $purchasable, 'in_stock' => $in_stock, 'stock' => $stock, 'fixed' => false, 'parent_published' => false ) );
				if ( ( $published && $purchasable && $in_stock ) !== ( array() === Rules::blockers( $f ) ) ) {
					$mismatch[] = json_encode( $f );
				}
			}
		}
	}
}
check( 'a simple design is offered exactly when it was before: published, purchasable, in stock (and never asked about attributes or a parent)', array() === $mismatch, implode( ' | ', $mismatch ) );

echo "3. What fits: the sticker line gives way first\n";
// Night Glow (#301) has 3 left.
$fit = Rules::fit(
	array(
		'free' => array( 'count' => 301, 'kind' => 'free', 'qty' => 1 ),
		'add'  => array( 'count' => 301, 'kind' => 'extra', 'qty' => 2 ),
	),
	array( 301 => 3 )
);
check( 'a free sticker and two add-ons of a design with 3 left: all fit', array( 'free' => 1, 'add' => 2 ) === $fit );

$fit = Rules::fit(
	array(
		'add'  => array( 'count' => 301, 'kind' => 'extra', 'qty' => 5 ),
		'free' => array( 'count' => 301, 'kind' => 'free', 'qty' => 1 ),
	),
	array( 301 => 3 )
);
check( 'five add-ons and the free one with 3 left: the free sticker first, the add-ons cut to 2 — whatever the order of the lines', array( 'free' => 1, 'add' => 2 ) === $fit, json_encode( $fit ) );

$fit = Rules::fit(
	array(
		'shop' => array( 'count' => 301, 'kind' => '', 'qty' => 3 ),
		'free' => array( 'count' => 301, 'kind' => 'free', 'qty' => 1 ),
		'add'  => array( 'count' => 301, 'kind' => 'extra', 'qty' => 2 ),
	),
	array( 301 => 3 )
);
check( 'the customer also BUYS 3 of that design: the purchase keeps all 3, both sticker lines go', array( 'free' => 0, 'add' => 0 ) === $fit, json_encode( $fit ) );
check( '... and the answer never names the normal purchase: the section does not touch it', ! array_key_exists( 'shop', $fit ) );

$fit = Rules::fit(
	array(
		'shop' => array( 'count' => 301, 'kind' => '', 'qty' => 2 ),
		'free' => array( 'count' => 301, 'kind' => 'free', 'qty' => 1 ),
		'add'  => array( 'count' => 301, 'kind' => 'extra', 'qty' => 2 ),
	),
	array( 301 => 3 )
);
check( 'buys 2 of 3: the free sticker takes the last one, the add-on goes', array( 'free' => 1, 'add' => 0 ) === $fit, json_encode( $fit ) );

$fit = Rules::fit(
	array(
		'shop' => array( 'count' => 301, 'kind' => '', 'qty' => 9 ),
		'free' => array( 'count' => 301, 'kind' => 'free', 'qty' => 1 ),
	),
	array( 301 => 3 )
);
check( 'buys more than there is: the sticker goes; what WooCommerce says about the purchase is WooCommerce\'s', array( 'free' => 0 ) === $fit );

$fit = Rules::fit(
	array(
		'free' => array( 'count' => 301, 'kind' => 'free', 'qty' => 1 ),
		'add'  => array( 'count' => 302, 'kind' => 'extra', 'qty' => 4 ),
	),
	array( 301 => 0, 302 => 4 )
);
check( 'it ran out between the picker and Place Order (0 left): the line is dropped; another design is not touched', array( 'free' => 0, 'add' => 4 ) === $fit );

$fit = Rules::fit(
	array(
		'free' => array( 'count' => 400, 'kind' => 'free', 'qty' => 1 ),
		'add1' => array( 'count' => 400, 'kind' => 'extra', 'qty' => 2 ),
		'add2' => array( 'count' => 400, 'kind' => 'extra', 'qty' => 2 ),
		'shop' => array( 'count' => 400, 'kind' => '', 'qty' => 1 ),
	),
	array( 400 => 4 )
);
check( 'three designs of a product that keeps ONE stock number (4 left) and a purchase: they share the 4', array( 'free' => 1, 'add1' => 2, 'add2' => 0 ) === $fit, json_encode( $fit ) );

$fit = Rules::fit(
	array(
		'free' => array( 'count' => 77, 'kind' => 'free', 'qty' => 1 ),
		'add'  => array( 'count' => 77, 'kind' => 'extra', 'qty' => 50 ),
	),
	array()
);
check( 'a simple product WooCommerce does not track: nothing limits its lines', array( 'free' => 1, 'add' => 50 ) === $fit );
check( 'stock held for other customers\' unpaid orders is taken off before the lines are fitted (3 in stock, 2 held)', array( 'free' => 1, 'add' => 0 ) === Rules::fit( array( 'free' => array( 'count' => 77, 'kind' => 'free', 'qty' => 1 ), 'add' => array( 'count' => 77, 'kind' => 'extra', 'qty' => 2 ) ), array( 77 => 3 - 2 ) ) );
check( 'more held than in stock: nothing fits, nothing goes below 0', array( 'free' => 0 ) === Rules::fit( array( 'free' => array( 'count' => 77, 'kind' => 'free', 'qty' => 1 ) ), array( 77 => -2 ) ) );
check( 'a cart without sticker lines: nothing to say', array() === Rules::fit( array( 'shop' => array( 'count' => 301, 'kind' => '', 'qty' => 2 ) ), array() ) );

echo "4. How a count reads in the stock table\n";
check( '120 in stock, reorder at 40: OK', Rules::STATUS_OK === Rules::status( 120, 40 ) );
check( '41: still OK', Rules::STATUS_OK === Rules::status( 41, 40 ) );
check( '40: Reorder', Rules::STATUS_REORDER === Rules::status( 40, 40 ) );
check( '7: Reorder', Rules::STATUS_REORDER === Rules::status( 7, 40 ) );
check( '0: Out', Rules::STATUS_OUT === Rules::status( 0, 40 ) );
check( 'no "Reorder at" number: OK until it is Out', Rules::STATUS_OK === Rules::status( 3, null ) && Rules::STATUS_OUT === Rules::status( 0, null ) );
check( 'reorder at 0 means "tell me when it is out"', Rules::STATUS_OK === Rules::status( 1, 0 ) && Rules::STATUS_OUT === Rules::status( 0, 0 ) );
check( 'stock not tracked: no status at all', '' === Rules::status( null, 40 ) );

echo "5. The one email: armed, sent, armed again\n";
// Night Glow, reorder at 40, as orders come in and the owner restocks.
$sent  = false;
$story = array();
foreach ( array( 120, 41, 40, 39, 12, 0, 40, 41, 30, 200, 40 ) as $stock ) {
	$step = Rules::reorder_step( $stock, 40, $sent );
	if ( 'send' === $step ) {
		$sent = true;
	} elseif ( 'clear' === $step ) {
		$sent = false;
	}
	$story[] = $stock . ':' . ( '' === $step ? '-' : $step );
}
check(
	'one email at the first drop to 40, none while it stays at or below, a new one only after the stock was back ABOVE 40',
	'120:- 41:- 40:send 39:- 12:- 0:- 40:- 41:clear 30:send 200:clear 40:send' === implode( ' ', $story ),
	implode( ' ', $story )
);
check( 'straight from above the number to 0: one email', 'send' === Rules::reorder_step( 0, 40, false ) );
check( 'a recount that leaves it at the number does not arm it again', '' === Rules::reorder_step( 40, 40, true ) );
check( 'a recount that puts it above does', 'clear' === Rules::reorder_step( 55, 40, true ) );
check( 'no "Reorder at" number: never an email', '' === Rules::reorder_step( 0, null, false ) );
check( 'the number taken away after an email went: forgotten, so a new number starts fresh', 'clear' === Rules::reorder_step( 10, null, true ) );
check( 'stock not tracked: nothing to compare, nothing sent, nothing forgotten', '' === Rules::reorder_step( null, 40, false ) && '' === Rules::reorder_step( null, 40, true ) );
check( 'reorder at 0: the email goes when it is out', '' === Rules::reorder_step( 1, 0, false ) && 'send' === Rules::reorder_step( 0, 0, false ) );

echo "\n" . ( $failures ? "$failures of $checks checks FAILED\n" : "All $checks checks passed\n" );
exit( $failures ? 1 : 0 );
