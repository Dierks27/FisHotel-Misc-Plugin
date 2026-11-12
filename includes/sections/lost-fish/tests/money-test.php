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
check_same( 'a float that is not exact in binary still rounds to the cent', 1999, Money::cents( 19.99 ) );
check_same( 'not a number is nothing', 0, Money::cents( 'abc' ) );
check_same( 'cents as a field value', '30.99', Money::decimal( 3099 ) );
check_same( 'no cents, no field value', '', Money::decimal( null ) );
check_same( 'money for a person', '$1,250.50', Money::format( 125050 ) );
check_same( 'a negative amount keeps its sign in front', '-$5.00', Money::format( -500 ) );
check_same( 'another symbol', '€12.00', Money::format( 1200, '€' ) );
check_same( 'nothing to format', '', Money::format( null ) );

echo "\nWhat a person typed\n";
check_same( '30.99', 3099, Money::typed_cents( '30.99' ) );
check_same( '$30.99', 3099, Money::typed_cents( '$30.99' ) );
check_same( '30', 3000, Money::typed_cents( '30' ) );
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
check_same( 'no shipping tax on the order: the difference as it is', 3099, Money::with_shipping_tax( 3099, 1900, 0 ) );
check_same( 'the order\'s own shipping tax rate (10%) on top', 3409, Money::with_shipping_tax( 3099, 1900, 190 ) );
check_same( 'an order charged no shipping has no rate to apply', 4999, Money::with_shipping_tax( 4999, 0, 0 ) );
check_same( 'never negative', 0, Money::with_shipping_tax( -100, 1900, 190 ) );

echo "\nThe four choices\n";
$drop = Money::choices(
	array(
		'unit_paid'      => 6000,
		'qty'            => 1,
		'ship_diff'      => 3099,
		'order_total'    => 53900,
		'order_refunded' => 0,
	)
);
check_same( 'drops under the threshold: refund = the fish minus the shipping difference', 2901, $drop['refund'] );
check_same( '… a credit is the same amount — a credit does not keep the cheaper shipping', 2901, $drop['credit'] );
check_same( '… try again moves nothing', 0, $drop['retry'] );
check_same( '… cancel the whole order = everything paid', 53900, $drop['cancel'] );
check_same( '… the fish and the difference are reported', array( 6000, 3099, 0 ), array( $drop['fish_paid'], $drop['ship_diff'], $drop['shortfall'] ) );

$stays = Money::choices(
	array(
		'unit_paid'   => 6000,
		'qty'         => 1,
		'ship_diff'   => 0,
		'order_total' => 71900,
	)
);
check_same( 'stays over the threshold: the whole price of the fish', 6000, $stays['refund'] );
check_same( '… and the same as a credit', 6000, $stays['credit'] );

$two = Money::choices(
	array(
		'unit_paid'   => 4500,
		'qty'         => 2,
		'ship_diff'   => 3099,
		'order_total' => 60000,
	)
);
check_same( 'two fish of one line: both prices, the difference once', 5901, $two['refund'] );

$unknown = Money::choices(
	array(
		'unit_paid'   => 6000,
		'qty'         => 1,
		'ship_diff'   => null,
		'order_total' => 53900,
	)
);
check_same( 'the difference is not known: no refund figure at all', null, $unknown['refund'] );
check_same( '… and no credit figure', null, $unknown['credit'] );
check_same( '… the cancel figure does not depend on it', 53900, $unknown['cancel'] );

$unpaid = Money::choices(
	array(
		'unit_paid'   => 0,
		'paid_known'  => false,
		'qty'         => 1,
		'ship_diff'   => 0,
		'order_total' => 53900,
	)
);
check_same( 'what was paid is not known: no refund figure', null, $unpaid['refund'] );

$more = Money::choices(
	array(
		'unit_paid'   => 1500,
		'qty'         => 1,
		'ship_diff'   => 3099,
		'order_total' => 51900,
	)
);
check_same( 'the difference is more than the fish: the refund is zero, never negative', 0, $more['refund'] );
check_same( '… and the rest is reported, not collected', 1599, $more['shortfall'] );

$part = Money::choices(
	array(
		'unit_paid'      => 6000,
		'qty'            => 1,
		'ship_diff'      => 0,
		'order_total'    => 53900,
		'order_refunded' => 10000,
	)
);
check_same( 'cancel: what was refunded already is not refunded again', 43900, $part['cancel'] );

$shipped = Money::choices(
	array(
		'unit_paid'    => 6000,
		'qty'          => 1,
		'ship_diff'    => 0,
		'order_total'  => 53900,
		'offer_cancel' => false,
	)
);
check_same( 'part of the order shipped: cancel is not offered', array( false, null ), array( $shipped['offer_cancel'], $shipped['cancel'] ) );

echo "\nThe amount of a choice\n";
check_same( 'refund', 2901, Money::amount_for( 'refund', $drop ) );
check_same( 'credit', 2901, Money::amount_for( 'credit', $drop ) );
check_same( 'try again', 0, Money::amount_for( 'retry', $drop ) );
check_same( 'cancel', 53900, Money::amount_for( 'cancel', $drop ) );
check_same( 'cancel when it is not offered', null, Money::amount_for( 'cancel', $shipped ) );
check_same( 'not a choice', null, Money::amount_for( 'everything', $drop ) );
check_same( 'the four choices, in the e-mail\'s order', array( 'refund', 'credit', 'retry', 'cancel' ), Money::CHOICES );

fh_finish();
