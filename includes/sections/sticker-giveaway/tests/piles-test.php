<?php
/**
 * Acceptance tests for the giveaway piles (class-piles.php): the reserve
 * for stickers that go missing, "really there", when a design stops being
 * offered, what a restock and a recount do, the once-per-order-line
 * ledger, reorder, the pace, and the cart arithmetic.
 *
 * Run from the plugin root:
 *   php includes/sections/sticker-giveaway/tests/piles-test.php
 *
 * Invented numbers throughout.
 *
 * @package FisHotel\Misc\Sections\Sticker_Giveaway
 */

require_once __DIR__ . '/wp-stubs.php';

use FisHotel\Misc\Sections\Sticker_Giveaway\Piles;

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

echo "1. The reserve: a share of what went into the pile, rounded up\n";
check( '10% of 200 is 20', 20 === Piles::reserve( 200, 10 ) );
check( '10% of 15 is 2 (1.5 rounded up)', 2 === Piles::reserve( 15, 10 ) );
check( '10% of 30 is 3, never 4 by float noise', 3 === Piles::reserve( 30, 10 ) );
check( '10% of 1 is 1: a pile of one is not trusted', 1 === Piles::reserve( 1, 10 ) );
check( '0% holds nothing back', 0 === Piles::reserve( 200, 0 ) );
check( 'an empty pile has no reserve', 0 === Piles::reserve( 0, 10 ) );
check( 'the percent never counts above 50', Piles::reserve( 200, 80 ) === Piles::reserve( 200, 50 ) && 100 === Piles::reserve( 200, 50 ) );
check( 'a negative basis or percent is 0', 0 === Piles::reserve( -5, 10 ) && 0 === Piles::reserve( 200, -3 ) );

check( 'the setting: default 10', 10 === Piles::loss_percent( array() ) );
check( 'the setting: kept between 0 and 50', 0 === Piles::loss_percent( array( 'loss_percent' => -4 ) ) && 50 === Piles::loss_percent( array( 'loss_percent' => 75 ) ) && 0 === Piles::loss_percent( array( 'loss_percent' => '0' ) ) );
check( 'the setting: blank or junk restores the default', 10 === Piles::loss_percent( array( 'loss_percent' => '' ) ) && 10 === Piles::loss_percent( array( 'loss_percent' => 'lots' ) ) );
check( 'the setting: a typed "7.6" is 8', 8 === Piles::loss_percent( array( 'loss_percent' => '7.6' ) ) );

echo "2. \"P on paper · about P − reserve really there\"\n";
check( '200 on paper, 200 put in, 10%: about 180 really there', 180 === Piles::really_there( 200, 200, 10 ) );
check( '50 left of 200 put in: about 30 really there', 30 === Piles::really_there( 50, 200, 10 ) );
check( 'never below 0', 0 === Piles::really_there( 12, 200, 10 ) && 0 === Piles::really_there( -3, 200, 10 ) );

echo "3. The design stops being offered when P <= reserve\n";
check( 'offered while P is above the reserve (21 of 200)', Piles::offered( 21, 200, 10 ) );
check( 'not offered at the reserve (20 of 200)', ! Piles::offered( 20, 200, 10 ) );
check( 'not offered below it', ! Piles::offered( 5, 200, 10 ) );
check( 'an empty pile is not offered', ! Piles::offered( 0, 0, 10 ) );
check( 'with 0% the last sticker is still offered', Piles::offered( 1, 200, 0 ) && ! Piles::offered( 0, 200, 0 ) );

echo "4. Add stickers, Recount, and an order\n";
$pile = Piles::pile( null );
check( 'a design nobody counted yet is an empty pile', 0 === $pile['paper'] && 0 === $pile['basis'] && false === $pile['alerted'] );

$pile = Piles::after_recount( $pile, 200 );
check( 'Recount 200: paper 200, B 200', 200 === $pile['paper'] && 200 === $pile['basis'] );

$pile = Piles::after_move( $pile, -150 );
check( 'orders took 150: paper 50, B unchanged', 50 === $pile['paper'] && 200 === $pile['basis'] );
check( '... about 30 really there', 30 === Piles::really_there( $pile['paper'], $pile['basis'], 10 ) );

$pile = Piles::after_add( $pile, 100 );
check( 'Add 100: paper 150, B 300 (the counted number plus what was added since)', 150 === $pile['paper'] && 300 === $pile['basis'] );
check( '... reserve 30, about 120 really there', 30 === Piles::reserve( $pile['basis'], 10 ) && 120 === Piles::really_there( $pile['paper'], $pile['basis'], 10 ) );

$pile = Piles::after_recount( $pile, 140 );
check( 'Recount 140: B becomes the typed number', 140 === $pile['paper'] && 140 === $pile['basis'] );
check( '... reserve 14, about 126 really there', 14 === Piles::reserve( $pile['basis'], 10 ) && 126 === Piles::really_there( $pile['paper'], $pile['basis'], 10 ) );

$wrong = Piles::after_recount( $pile, 1400 );
$right = Piles::after_recount( $wrong, 140 );
check( 'a mistyped recount is put right by another recount', $right['paper'] === $pile['paper'] && $right['basis'] === $pile['basis'] );
check( 'Add of 0 or less changes nothing', Piles::after_add( $pile, 0 ) === $pile && Piles::after_add( $pile, -5 ) === $pile );
check( 'a recount never goes below 0', 0 === Piles::after_recount( $pile, -9 )['paper'] );
check( 'an order giving stickers back only moves the paper count', 141 === Piles::after_move( $pile, 1 )['paper'] && 140 === Piles::after_move( $pile, 1 )['basis'] );
check( 'the paper count may go below 0 (two carts held the last sticker) and says so', -1 === Piles::after_move( Piles::after_recount( null, 1 ), -2 )['paper'] );

echo "5. The ledger: once per order line however often the hooks fire\n";
list( $row, $delta ) = Piles::ledger( null, Piles::TAKE, 2 );
check( 'an order line of 2 takes 2', -2 === $delta && Piles::TAKEN === $row['state'] && 2 === $row['qty'] && 0 === $row['back'] );

list( $again, $delta ) = Piles::ledger( $row, Piles::TAKE, 2 );
check( 'the reduce hook firing again takes nothing', 0 === $delta && $again === $row );

list( $back, $delta ) = Piles::ledger( $row, Piles::RETURN );
check( 'a cancelled order gives the 2 back', 2 === $delta && Piles::BACK === $back['state'] );

list( $again, $delta ) = Piles::ledger( $back, Piles::RETURN );
check( 'the restore hook firing again gives nothing', 0 === $delta && $again === $back );

list( $retaken, $delta ) = Piles::ledger( $back, Piles::TAKE, 2 );
check( 'the order paid again after a cancel takes 2 again', -2 === $delta && Piles::TAKEN === $retaken['state'] && 0 === $retaken['back'] );

check( 'a return or a refund on a line that never took anything does nothing', 0 === Piles::ledger( null, Piles::RETURN )[1] && 0 === Piles::ledger( null, Piles::REFUND, 1 )[1] );
check( 'a take of 0 does nothing', 0 === Piles::ledger( null, Piles::TAKE, 0 )[1] && null === Piles::ledger( null, Piles::TAKE, 0 )[0] );
check( 'an unknown event does nothing', 0 === Piles::ledger( $row, 'bogus', 3 )[1] );

list( $line3, $delta )    = Piles::ledger( null, Piles::TAKE, 3 );
list( $refunded, $delta ) = Piles::ledger( $line3, Piles::REFUND, 1 );
check( 'a refund of 1 of 3 with restock gives 1 back', 1 === $delta && Piles::TAKEN === $refunded['state'] && 1 === $refunded['back'] );

list( $refunded2, $delta ) = Piles::ledger( $refunded, Piles::REFUND, 5 );
check( 'a refund never gives back more than the line still holds (2 of the asked 5)', 2 === $delta && 3 === $refunded2['back'] );
check( '... and then nothing more', 0 === Piles::ledger( $refunded2, Piles::REFUND, 1 )[1] );

list( $cancelled, $delta ) = Piles::ledger( $refunded, Piles::RETURN );
check( 'a cancel after a refund of 1 of 3 gives back the other 2, not 3', 2 === $delta && Piles::BACK === $cancelled['state'] );
check( 'a refund on a line that was given back does nothing', 0 === Piles::ledger( $cancelled, Piles::REFUND, 1 )[1] );

// A whole order's life on one pile: the paper count ends where it began.
$pile   = Piles::after_recount( null, 100 );
$ledger = null;
foreach ( array( array( Piles::TAKE, 2 ), array( Piles::TAKE, 2 ), array( Piles::RETURN, 0 ), array( Piles::RETURN, 0 ), array( Piles::TAKE, 2 ), array( Piles::REFUND, 1 ), array( Piles::RETURN, 0 ), array( Piles::RETURN, 0 ) ) as $event ) {
	list( $ledger, $delta ) = Piles::ledger( $ledger, $event[0], $event[1] );
	$pile                   = Piles::after_move( $pile, $delta );
}
check( 'paid, paid again, cancelled twice, paid, 1 refunded, cancelled twice: the pile is back at 100', 100 === $pile['paper'] && 100 === $pile['basis'], json_encode( $pile ) );

echo "6. Reorder: what is left, and what the design reads\n";
check( 'what is left = really there + the shop pile', 52 === Piles::left( 40, 12 ) );
check( 'a product that does not manage stock adds nothing', 40 === Piles::left( 40, null ) );
check( 'a design with no giveaway pile: the shop pile alone', 12 === Piles::left( null, 12 ) );
check( 'neither counted: unknown', null === Piles::left( null, null ) );

check( 'above the reorder number: OK', Piles::STATUS_OK === Piles::status( true, 52, 50 ) );
check( 'at the reorder number: Reorder', Piles::STATUS_REORDER === Piles::status( true, 50, 50 ) );
check( 'below it: Reorder', Piles::STATUS_REORDER === Piles::status( true, 49, 50 ) );
check( 'no longer offered: Out, whatever the number', Piles::STATUS_OUT === Piles::status( false, 12, 50 ) && Piles::STATUS_OUT === Piles::status( false, 12, null ) );
check( 'no reorder number: never Reorder', Piles::STATUS_OK === Piles::status( true, 1, null ) );
check( 'a reorder number of 0 reminds at 0 left', Piles::STATUS_REORDER === Piles::status( true, 0, 0 ) && Piles::STATUS_OK === Piles::status( true, 1, 0 ) );

check( 'a blank reorder box means no reminder', null === Piles::reorder_number( '' ) && null === Piles::reorder_number( '   ' ) && null === Piles::reorder_number( null ) );
check( 'junk or a negative number means no reminder', null === Piles::reorder_number( 'soon' ) && null === Piles::reorder_number( '-5' ) && null === Piles::reorder_number( array( 5 ) ) );
check( 'a typed number is kept as a whole number', 50 === Piles::reorder_number( '50' ) && 0 === Piles::reorder_number( '0' ) && 12 === Piles::reorder_number( '12.9' ) );

echo "7. The one reorder email\n";
check( 'drops to its number, no email yet: send', 'send' === Piles::alert_step( 50, 50, false ) );
check( 'still under it, already written about: nothing', '' === Piles::alert_step( 31, 50, true ) );
check( 'restocked above the number: clear, so the next drop writes again', 'clear' === Piles::alert_step( 151, 50, true ) );
check( 'above the number, nothing sent: nothing', '' === Piles::alert_step( 151, 50, false ) );
check( 'the number removed after an email: clear', 'clear' === Piles::alert_step( 10, null, true ) );
check( 'no number: never send', '' === Piles::alert_step( 0, null, false ) );
check( 'nothing counted: never send', '' === Piles::alert_step( null, 50, false ) );

// One drop, many orders: exactly one "send" until a restock.
$alerted = false;
$sent    = 0;
foreach ( array( 52, 51, 50, 49, 48, 47 ) as $left ) {
	$step = Piles::alert_step( $left, 50, $alerted );
	if ( 'send' === $step ) {
		$sent++;
		$alerted = true;
	} elseif ( 'clear' === $step ) {
		$alerted = false;
	}
}
check( 'six orders through the reorder number write one email', 1 === $sent );
$step    = Piles::alert_step( 147, 50, $alerted );
$alerted = 'clear' === $step ? false : $alerted;
check( 'after a restock the next drop writes again', false === $alerted && 'send' === Piles::alert_step( 50, 50, $alerted ) );

echo "8. The pace\n";
check( '36 in 28 days is 9 a week', 9.0 === Piles::per_week( 36 ) );
check( 'nothing went: no pace, no "weeks left"', 0.0 === Piles::per_week( 0 ) && null === Piles::weeks_left( 180, 0 ) );
check( '180 really there at 9 a week: 20 weeks', 20.0 === Piles::weeks_left( 180, 36 ) );
check( 'nothing there: 0 weeks', 0.0 === Piles::weeks_left( 0, 36 ) );

echo "9. One row of the stock table\n";
$row = Piles::summary(
	array(
		'keeps_pile' => true,
		'paper'      => 50,
		'basis'      => 200,
		'percent'    => 10,
		'shop'       => 12,
		'in_stock'   => false, // the shop says out of stock: not asked
		'went'       => 36,
		'reorder_at' => '50',
	)
);
check( 'giveaway pile: 50 on paper, 20 held back, about 30 really there', 50 === $row['paper'] && 20 === $row['reserve'] && 30 === $row['really_there'] );
check( 'left = 30 + 12 in the shop = 42, at a reorder number of 50: Reorder', 42 === $row['left'] && Piles::STATUS_REORDER === $row['status'] );
check( 'offered by the giveaway pile, whatever the shop says', true === $row['offered'] );
check( 'pace: 36 went, 9 a week, 30 last about 3.3 weeks', 36 === $row['went'] && 9.0 === $row['per_week'] && abs( $row['weeks'] - 30 / 9 ) < 0.0001 );

$row = Piles::summary(
	array(
		'keeps_pile' => true,
		'paper'      => 20,
		'basis'      => 200,
		'percent'    => 10,
		'shop'       => 12,
		'in_stock'   => true,
	)
);
check( 'a pile down to its reserve reads Out even with stickers in the shop', false === $row['offered'] && Piles::STATUS_OUT === $row['status'] && 12 === $row['left'] );

$row = Piles::summary(
	array(
		'keeps_pile' => false,
		'paper'      => 999, // a row left over from an earlier switch-on is not read
		'basis'      => 999,
		'shop'       => 7,
		'in_stock'   => true,
		'went'       => 5,
		'reorder_at' => 10,
	)
);
check( 'a design without a giveaway pile reads the shop pile only', null === $row['really_there'] && 0 === $row['paper'] && 7 === $row['left'] && Piles::STATUS_REORDER === $row['status'] && 0 === $row['went'] && null === $row['weeks'] );

$row = Piles::summary(
	array(
		'keeps_pile' => false,
		'shop'       => null,
		'in_stock'   => true,
		'reorder_at' => 10,
	)
);
check( '... and with stock not managed nothing is counted: OK, never Reorder', null === $row['left'] && Piles::STATUS_OK === $row['status'] );
check( '... out of stock: Out', Piles::STATUS_OUT === Piles::summary( array( 'keeps_pile' => false, 'shop' => 0, 'in_stock' => false ) )['status'] );

echo "10. Cart arithmetic: what the giveaway piles can still hand out\n";
$lines = array(
	'free'   => array( 'design' => 501, 'kind' => 'free', 'qty' => 1 ),
	'extraA' => array( 'design' => 501, 'kind' => 'extra', 'qty' => 5 ),
	'extraB' => array( 'design' => 502, 'kind' => 'extra', 'qty' => 2 ),
);
check( 'enough in both piles: every line as asked', array( 'free' => 1, 'extraA' => 5, 'extraB' => 2 ) === Piles::fit( $lines, array( 501 => 30, 502 => 30 ) ) );
check( '3 left of a design: the free one, then 2 of the 5 add-ons', array( 'free' => 1, 'extraA' => 2, 'extraB' => 2 ) === Piles::fit( $lines, array( 501 => 3, 502 => 30 ) ) );
check( '1 left: the free one, the add-on line is dropped', array( 'free' => 1, 'extraA' => 0, 'extraB' => 2 ) === Piles::fit( $lines, array( 501 => 1, 502 => 30 ) ) );
check( 'none left: both lines of that design are dropped, the other design stays', array( 'free' => 0, 'extraA' => 0, 'extraB' => 2 ) === Piles::fit( $lines, array( 501 => 0, 502 => 30 ) ) );

$extra_first = array(
	'extraA' => array( 'design' => 501, 'kind' => 'extra', 'qty' => 5 ),
	'free'   => array( 'design' => 501, 'kind' => 'free', 'qty' => 1 ),
);
check( 'the free line is served first even when the add-on line comes first in the cart', array( 'extraA' => 1, 'free' => 1 ) === Piles::fit( $extra_first, array( 501 => 2 ) ) );
check( 'a design that keeps no giveaway pile is not limited here', array( 'free' => 1, 'extraA' => 5, 'extraB' => 2 ) === Piles::fit( $lines, array() ) );
check( 'an empty cart fits', array() === Piles::fit( array(), array( 501 => 3 ) ) );

check( 'room for more: what the pile can hand out less what the cart holds of it', 24 === Piles::room( 501, 30, $lines ) && 28 === Piles::room( 502, 30, $lines ) );
check( 'room never goes below 0', 0 === Piles::room( 501, 4, $lines ) );
check( 'another design\'s lines take no room', 30 === Piles::room( 777, 30, $lines ) );

echo "11. Cart arithmetic: the shop pile and a normal purchase in the same cart\n";
// One variation (id 501): a customer buys 2 the normal way, gets 1 free and adds 3 add-ons.
$cart = array(
	array( 'stock_id' => 501, 'qty' => 2, 'pile' => false ), // normal purchase
	array( 'stock_id' => 501, 'qty' => 1, 'pile' => true ),  // free, from the giveaway pile
	array( 'stock_id' => 501, 'qty' => 3, 'pile' => true ),  // add-ons, from the giveaway pile
	array( 'stock_id' => 88, 'qty' => 1, 'pile' => false ),  // a fish
);
$sums = Piles::cart_quantities( $cart );
check( 'the shop pile is asked for the normal 2 only', 2 === $sums[501] );
check( 'the giveaway lines are summed under nobody\'s stock', 4 === $sums[ Piles::STOCK_BUCKET ] && 0 === Piles::STOCK_BUCKET );
check( 'other products are untouched', 1 === $sums[88] );

$shop_pile = 2;
check( 'with 2 in the shop the normal line of 2 passes, exactly as with no giveaway lines', $shop_pile >= $sums[501] );
$cart[0]['qty'] = 3;
check( 'with 2 in the shop a normal line of 3 still fails, exactly as with no giveaway lines', $shop_pile < Piles::cart_quantities( $cart )[501] );
$without = Piles::cart_quantities( array( $cart[0], $cart[3] ) );
check( 'the sum the normal line is checked against is the same with and without the giveaway lines', Piles::cart_quantities( $cart )[501] === $without[501] );

// A variation that takes its stock from its product (stock id = the product, 400).
$parent_stock = array(
	array( 'stock_id' => 400, 'qty' => 1, 'pile' => false ),
	array( 'stock_id' => 400, 'qty' => 1, 'pile' => true ),
);
check( 'a variation on its product\'s stock: the giveaway line is still left out of the product\'s sum', 1 === Piles::cart_quantities( $parent_stock )[400] );

// A design that keeps NO giveaway pile behaves as it always has: all three lines are one product's stock.
$legacy = array(
	array( 'stock_id' => 77, 'qty' => 2, 'pile' => false ),
	array( 'stock_id' => 77, 'qty' => 1, 'pile' => false ),
	array( 'stock_id' => 77, 'qty' => 3, 'pile' => false ),
);
check( 'a design without a giveaway pile: its sticker lines count against the product\'s own stock, as before', array( 77 => 6 ) === Piles::cart_quantities( $legacy ) );

echo "\n" . ( $failures ? "$failures of $checks checks FAILED\n" : "All $checks checks passed\n" );
exit( $failures ? 1 : 0 );
