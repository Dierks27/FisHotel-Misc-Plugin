<?php
/**
 * Money tests — cents in and out, what one fish of a line was paid, the
 * shipping tax on a difference, and the money for each of the four
 * choices: an order that drops under the reduced-shipping threshold, one
 * that stays over it, a difference that is not known, a difference larger
 * than the fish, and the whole-order cancel.
 *
 * Run from the plugin root:
 *   php includes/sections/lost-fish/tests/money-test.php
 *
 * @package FisHotel\Misc\Sections\Lost_Fish
 */

require_once __DIR__ . '/wp-stubs.php';

use FisHotel\Misc\Sections\Lost_Fish\Money;

echo "Cents\n";
check_same( 'dollars to cents', 6000, Money::cents( '60.00' ) );
check_same( 'a float that is not exact in binary still rounds to the cent', 1235, Money::cents( 12.35 ) );
check_same( 'not a number is nothing', 0, Money::cents( 'abc' ) );
check_same( 'cents as a field value', '12.50', Money::decimal( 1250 ) );
check_same( 'no cents, no field value', '', Money::decimal( null ) );
check_same( 'money for a person', '$1,250.50', Money::format( 125050 ) );
check_same( 'a negative amount keeps its sign in front', '-$5.00', Money::format( -500 ) );
check_same( 'another symbol', '€12.00', Money::format( 1200, '€' ) );
check_same( 'nothing to format', '', Money::format( null ) );

echo "\nWhat a person typed\n";
check_same( '12.50', 1250, Money::typed_cents( '12.50' ) );
check_same( '$12.50', 1250, Money::typed_cents( '$12.50' ) );
check_same( '12', 1200, Money::typed_cents( '12' ) );
check_same( '1,250.50', 125050, Money::typed_cents( '1,250.50' ) );
check_same( '0 confirms zero', 0, Money::typed_cents( '0' ) );
check_same( 'an empty field is not an amount', null, Money::typed_cents( '' ) );
check_same( 'a negative number is not an amount', null, Money::typed_cents( '-5' ) );
check_same( 'words are not an amount', null, Money::typed_cents( 'about ten' ) );
check_same( 'three decimals are not an amount', null, Money::typed_cents( '1.234' ) );
check_same( 'an array is not an amount', null, Money::typed_cents( array( '5' ) ) );
check_same( 'more than the cap is not an amount', null, Money::typed_cents( '100000.01' ) );

echo "\nOne fish of a line\n";
check_same( 'a line of one', 6000, Money::unit_paid( '60.00', '0', 1 ) );
check_same( 'a line of three, with tax', 4330, Money::unit_paid( '120.00', '9.90', 3 ) );
check_same( 'a line of nothing', 0, Money::unit_paid( '60.00', '0', 0 ) );

echo "\nShipping tax on a difference\n";
check_same( 'no shipping tax on the order: the difference as it is', 2500, Money::with_shipping_tax( 2500, 1500, 0 ) );
check_same( 'the order\'s own shipping tax rate (10%) on top', 2750, Money::with_shipping_tax( 2500, 1500, 150 ) );
check_same( 'an order charged no shipping has no rate to apply', 4000, Money::with_shipping_tax( 4000, 0, 0 ) );
check_same( 'never negative', 0, Money::with_shipping_tax( -100, 1500, 150 ) );

echo "\nThe four choices\n";
$drop = Money::choices(
	array(
		'unit_paid'      => 6000,
		'qty'            => 1,
		'ship_diff'      => 2500,
		'order_total'    => 53500,
		'order_refunded' => 0,
	)
);
check_same( 'drops under the threshold: refund = the fish minus the shipping difference', 3500, $drop['refund'] );
check_same( '… a credit is the same amount — a credit does not keep the cheaper shipping', 3500, $drop['credit'] );
check_same( '… try again moves nothing', 0, $drop['retry'] );
check_same( '… cancel the whole order = everything paid', 53500, $drop['cancel'] );
check_same( '… the fish and the difference are reported', array( 6000, 2500, 0 ), array( $drop['fish_paid'], $drop['ship_diff'], $drop['shortfall'] ) );

$stays = Money::choices(
	array(
		'unit_paid'   => 6000,
		'qty'         => 1,
		'ship_diff'   => 0,
		'order_total' => 71500,
	)
);
check_same( 'stays over the threshold: the whole price of the fish', 6000, $stays['refund'] );
check_same( '… and the same as a credit', 6000, $stays['credit'] );

$two = Money::choices(
	array(
		'unit_paid'   => 4500,
		'qty'         => 2,
		'ship_diff'   => 2500,
		'order_total' => 60000,
	)
);
check_same( 'two fish of one line: both prices, the difference once', 6500, $two['refund'] );

$unknown = Money::choices(
	array(
		'unit_paid'   => 6000,
		'qty'         => 1,
		'ship_diff'   => null,
		'order_total' => 53500,
	)
);
check_same( 'the difference is not known: no refund figure at all', null, $unknown['refund'] );
check_same( '… and no credit figure', null, $unknown['credit'] );
check_same( '… the cancel figure does not depend on it', 53500, $unknown['cancel'] );

$unpaid = Money::choices(
	array(
		'unit_paid'   => 0,
		'paid_known'  => false,
		'qty'         => 1,
		'ship_diff'   => 0,
		'order_total' => 53500,
	)
);
check_same( 'what was paid is not known: no refund figure', null, $unpaid['refund'] );

$more = Money::choices(
	array(
		'unit_paid'   => 1000,
		'qty'         => 1,
		'ship_diff'   => 2500,
		'order_total' => 51500,
	)
);
check_same( 'the difference is more than the fish: the refund is zero, never negative', 0, $more['refund'] );
check_same( '… and the rest is reported, not collected', 1500, $more['shortfall'] );

$part = Money::choices(
	array(
		'unit_paid'      => 6000,
		'qty'            => 1,
		'ship_diff'      => 0,
		'order_total'    => 53500,
		'order_refunded' => 10000,
	)
);
check_same( 'cancel: what was refunded already is not refunded again', 43500, $part['cancel'] );

$shipped = Money::choices(
	array(
		'unit_paid'    => 6000,
		'qty'          => 1,
		'ship_diff'    => 0,
		'order_total'  => 53500,
		'offer_cancel' => false,
	)
);
check_same( 'part of the order shipped: cancel is not offered', array( false, null ), array( $shipped['offer_cancel'], $shipped['cancel'] ) );
$credited = Money::choices( array( 'unit_paid' => 23000, 'qty' => 1, 'ship_diff' => 0, 'order_total' => 76500, 'order_refunded' => 3500, 'order_credited' => 6000 ) );
check_same( 'cancel after 35.00 was refunded and 60.00 given as store credit on the order: what is left, and how much went back', array( 67000, 9500 ), array( $credited['cancel'], $credited['settled'] ) );
check_same( 'nothing went back: nothing to say', 0, $drop['settled'] );

echo "\nThe amount of a choice\n";
check_same( 'refund', 3500, Money::amount_for( 'refund', $drop ) );
check_same( 'credit', 3500, Money::amount_for( 'credit', $drop ) );
check_same( 'try again', 0, Money::amount_for( 'retry', $drop ) );
check_same( 'cancel', 53500, Money::amount_for( 'cancel', $drop ) );
check_same( 'cancel when it is not offered', null, Money::amount_for( 'cancel', $shipped ) );
check_same( 'not a choice', null, Money::amount_for( 'everything', $drop ) );
check_same( 'the four choices, in the e-mail\'s order', array( 'refund', 'credit', 'retry', 'cancel' ), Money::CHOICES );

fh_finish();
