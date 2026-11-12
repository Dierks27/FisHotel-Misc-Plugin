<?php
/**
 * Shipping tests — the shipping difference: an order that drops under the
 * reduced-shipping threshold, one that stays over, free shipping on
 * purpose, a box that rides in another order's box, and every way it
 * "cannot tell" (the settings do not give what was charged, a product is
 * gone, no rate, another case still waiting) — which must come back as
 * unknown, never as a guess.
 *
 * The rates here are invented (fakes.php: 49.99, or 19.00 from 500.00 of
 * "premium" goods; "free" goods ship for nothing). The second half prices
 * through stand-ins for WooCommerce's zone and method, to prove which
 * method is asked, with which package, and that the session and the cart
 * are out of its reach while it works.
 *
 * Run from the plugin root:
 *   php includes/sections/lost-fish/tests/shipping-test.php
 *
 * @package FisHotel\Misc\Sections\Lost_Fish
 */

require_once __DIR__ . '/wp-stubs.php';
require_once __DIR__ . '/fakes.php';

use FisHotel\Misc\Sections\Lost_Fish\Shipping;

fh_product( 812, array( 'class' => 'premium' ) );
fh_product( 813, array( 'class' => 'premium' ) );
fh_product( 900, array( 'class' => 'premium' ) );
fh_product( 950, array( 'class' => '', 'ships' => false ) );
fh_product( 7001, array( 'class' => 'premium' ) );
fh_product( 7002, array( 'class' => 'free' ) );

$opts = array( 'rater' => 'fh_rater' );
$fish = array(
	array(
		'ref'       => 'i:11',
		'qty'       => 1,
		'unit_paid' => 6000,
	),
);

echo "Drops under the threshold\n";
$order = fh_order(
	101,
	array(
		'paid'     => 1000,
		'shipping' => 19.00,
		'lines'    => array(
			11 => array( 'name' => 'Lagoon Goby', 'product_id' => 812, 'qty' => 1, 'total' => 60.00 ),
			12 => array( 'name' => 'Harbor Tang', 'product_id' => 813, 'qty' => 2, 'total' => 460.00 ),
		),
	)
);
$d = Shipping::difference( $order, $fish, array(), $opts );
check_same( '520.00 of fish at 19.00 shipping; without the 60.00 fish it is 460.00 and ships at 49.99: the difference is 30.99', array( Shipping::DROP, 3099, 1900, 4999 ), array( $d['status'], $d['diff'], $d['charged'], $d['reduced'] ) );
check_same( 'the goods that ship, before and after', array( 52000, 46000 ), array( $d['goods'], $d['goods_after'] ) );
check_contains( 'the note says both rates', '$49.99', $d['note'] );
check_contains( '…', '$19.00', $d['note'] );

echo "\nStays over the threshold\n";
$order = fh_order(
	102,
	array(
		'paid'     => 1000,
		'shipping' => 19.00,
		'lines'    => array(
			11 => array( 'name' => 'Lagoon Goby', 'product_id' => 812, 'qty' => 1, 'total' => 60.00 ),
			12 => array( 'name' => 'Harbor Tang', 'product_id' => 813, 'qty' => 3, 'total' => 690.00 ),
		),
	)
);
$d = Shipping::difference( $order, $fish, array(), $opts );
check_same( '750.00 of fish; without the 60.00 fish still 690.00: no difference', array( Shipping::NONE, 0, 1900 ), array( $d['status'], $d['diff'], $d['reduced'] ) );

$order = fh_order(
	103,
	array(
		'paid'     => 1000,
		'shipping' => 49.99,
		'lines'    => array(
			11 => array( 'name' => 'Lagoon Goby', 'product_id' => 812, 'qty' => 1, 'total' => 60.00 ),
			12 => array( 'name' => 'Sand Shrimp', 'product_id' => 900, 'qty' => 1, 'total' => 20.00 ),
		),
	)
);
$d = Shipping::difference( $order, $fish, array(), $opts );
check_same( 'an order already under the threshold: nothing to lose', array( Shipping::NONE, 0 ), array( $d['status'], $d['diff'] ) );

echo "\nPart of a line\n";
$order = fh_order(
	104,
	array(
		'paid'     => 1000,
		'shipping' => 19.00,
		'lines'    => array(
			11 => array( 'name' => 'Lagoon Goby', 'product_id' => 812, 'qty' => 9, 'total' => 540.00 ),
		),
	)
);
$d = Shipping::difference( $order, $fish, array(), $opts );
check_same( 'one of nine at 60.00: 480.00 are left, under the threshold', array( Shipping::DROP, 3099, 48000 ), array( $d['status'], $d['diff'], $d['goods_after'] ) );

echo "\nThe order's own shipping tax\n";
$order = fh_order(
	105,
	array(
		'paid'         => 1000,
		'shipping'     => 19.00,
		'shipping_tax' => 1.90,
		'lines'        => array(
			11 => array( 'name' => 'Lagoon Goby', 'product_id' => 812, 'qty' => 1, 'total' => 60.00 ),
			12 => array( 'name' => 'Harbor Tang', 'product_id' => 813, 'qty' => 2, 'total' => 460.00 ),
		),
	)
);
$d = Shipping::difference( $order, $fish, array(), $opts );
check_same( 'shipping was taxed at 10%: so is the difference', array( 3409, 190 ), array( $d['diff'], $d['charged_tax'] ) );

echo "\nFree on purpose\n";
$order = fh_order(
	201,
	array(
		'paid'     => 1000,
		'shipping' => 0,
		'lines'    => array(
			11 => array( 'name' => 'Lagoon Goby', 'qty' => 1, 'total' => 60.00, 'meta' => array( '_fh_invoice_fish' => 1, '_fh_invoice_product_id' => 7002 ) ),
			12 => array( 'name' => 'Harbor Tang', 'qty' => 1, 'total' => 230.00, 'meta' => array( '_fh_invoice_fish' => 1, '_fh_invoice_product_id' => 7002 ) ),
		),
	)
);
$asked = 0;
$d     = Shipping::difference(
	$order,
	$fish,
	array(),
	array(
		'rater' => static function () use ( &$asked ) {
			$asked++;
			return 4999;
		},
	)
);
check_same( 'an invoice in the free shipping class, charged nothing: no difference', array( Shipping::FREE, 0 ), array( $d['status'], $d['diff'] ) );
check_same( '… and the shipping settings are not even asked', 0, $asked );

$d = Shipping::difference( $order, $fish, array(), array( 'rater' => 'fh_rater', 'free_classes' => array( 'gratis' ) ) );
check_same( 'the free classes are a setting: under another name, the settings are asked instead — and agree here', Shipping::NONE, $d['status'] );

$order = fh_order(
	202,
	array(
		'paid'     => 1000,
		'shipping' => 0,
		'meta'     => array( '_fishotel_piggyback_of' => 101 ),
		'lines'    => array( 11 => array( 'name' => 'Lagoon Goby', 'product_id' => 812, 'qty' => 1, 'total' => 60.00 ) ),
	)
);
$d = Shipping::difference( $order, $fish, array(), $opts );
check_same( 'an order that rides in another order\'s box paid no shipping of its own: no difference', array( Shipping::FREE, 0 ), array( $d['status'], $d['diff'] ) );
check_contains( '… and says whose box', '#101', $d['note'] );

echo "\nAn itemised invoice is priced as the invoice it was\n";
$order = fh_order(
	301,
	array(
		'paid'     => 1000,
		'shipping' => 19.00,
		'lines'    => array(
			11 => array( 'name' => 'Lagoon Goby', 'qty' => 2, 'total' => 120.00, 'meta' => array( '_fh_invoice_fish' => 1, '_fh_invoice_product_id' => 7001 ) ),
			12 => array( 'name' => 'Harbor Tang', 'qty' => 2, 'total' => 460.00, 'meta' => array( '_fh_invoice_fish' => 1, '_fh_invoice_product_id' => 7001 ) ),
		),
	)
);
$fee                = new Fh_Item( 13, array( 'total' => -50.00, 'meta' => array( '_fh_invoice_credit' => 1, '_fh_invoice_product_id' => 7001 ) ) );
$order->fees        = array( 13 => $fee );
$model              = Shipping::model( $order );
check_same( 'two fish lines and a credit are ONE row again: the invoice product, once, at the invoice\'s total', array( array( 'inv:7001' ), 1, 53000, true ), array( array_keys( $model['rows'] ), $model['rows']['inv:7001']['qty'], $model['rows']['inv:7001']['total'], $model['rows']['inv:7001']['group'] ) );
$d = Shipping::difference( $order, $fish, array(), $opts );
check_same( '530.00 at 19.00; without one 60.00 goby 470.00: the difference', array( Shipping::DROP, 3099, 47000 ), array( $d['status'], $d['diff'], $d['goods_after'] ) );

echo "\nA fish inside an invoice kept as one line\n";
$order = fh_order(
	302,
	array(
		'paid'     => 1000,
		'shipping' => 19.00,
		'lines'    => array( 11 => array( 'name' => 'Example Invoice', 'product_id' => 7001, 'qty' => 1, 'total' => 520.00 ) ),
	)
);
$d = Shipping::difference( $order, array( array( 'ref' => 'i:11:0', 'qty' => 1, 'unit_paid' => 6000 ) ), array(), $opts );
check_same( 'its price leaves the invoice line: 460.00, under the threshold', array( Shipping::DROP, 3099 ), array( $d['status'], $d['diff'] ) );

echo "\nNothing else to ship\n";
$order = fh_order(
	303,
	array(
		'paid'     => 1000,
		'shipping' => 49.99,
		'lines'    => array(
			11 => array( 'name' => 'Lagoon Goby', 'product_id' => 812, 'qty' => 1, 'total' => 60.00 ),
			12 => array( 'name' => 'Gift Card', 'product_id' => 950, 'qty' => 1, 'total' => 25.00 ),
		),
	)
);
$d = Shipping::difference( $order, $fish, array(), $opts );
check_same( 'the fish was the only thing that ships: no difference — nothing is priced', array( Shipping::NONE, 0, 0 ), array( $d['status'], $d['diff'], $d['goods_after'] ) );

echo "\nCannot tell — never a guess\n";
$order = fh_order(
	401,
	array(
		'paid'     => 1000,
		'shipping' => 25.00,
		'lines'    => array(
			11 => array( 'name' => 'Lagoon Goby', 'product_id' => 812, 'qty' => 1, 'total' => 60.00 ),
			12 => array( 'name' => 'Harbor Tang', 'product_id' => 813, 'qty' => 2, 'total' => 460.00 ),
		),
	)
);
$d = Shipping::difference( $order, $fish, array(), $opts );
check_same( 'the settings give 19.00 for the order as placed, but 25.00 was charged: unknown, no figure', array( Shipping::UNKNOWN, null, null ), array( $d['status'], $d['diff'], $d['alone'] ) );
check_contains( '… and the note says why', '$19.00', $d['note'] );

$order->shipping_total = 19.00;
$d                     = Shipping::difference( $order, $fish, array(), array( 'rater' => static function () { return null; } ) );
check_same( 'the settings give no rate: unknown', array( Shipping::UNKNOWN, null ), array( $d['status'], $d['diff'] ) );

$d = Shipping::difference( $order, $fish, array(), array( 'rater' => static function () { throw new \RuntimeException( 'Call to a member function get() on null' ); } ) );
check_same( 'the shipping method throws: unknown', array( Shipping::UNKNOWN, null ), array( $d['status'], $d['diff'] ) );
check_contains( '… with what it said', 'member function', $d['note'] );

$calls = 0;
$d     = Shipping::difference(
	$order,
	$fish,
	array(),
	array(
		'rater' => static function ( $o, $rows ) use ( &$calls ) {
			$calls++;
			return 1 === $calls ? 1900 : array( 'cents' => null, 'error' => 'no rate for the smaller order' );
		},
	)
);
check_same( 'a rate for the order as placed but none without the fish: unknown', array( Shipping::UNKNOWN, null, 'no rate for the smaller order' ), array( $d['status'], $d['diff'], $d['note'] ) );

unset( $GLOBALS['fh_products'][813] );
$d = Shipping::difference( $order, $fish, array(), $opts );
check_same( 'a product of the order no longer exists: unknown', array( Shipping::UNKNOWN, null ), array( $d['status'], $d['diff'] ) );
check_contains( '… naming the line', 'Harbor Tang', $d['note'] );
fh_product( 813, array( 'class' => 'premium' ) );

$d = Shipping::difference( $order, array( array( 'ref' => 'inv:9:1', 'qty' => 1, 'unit_paid' => 6000 ) ), array(), $opts );
check_same( 'a fish that is no order line (an invoice line of the Invoices section): unknown', array( Shipping::UNKNOWN, null ), array( $d['status'], $d['diff'] ) );

$d = Shipping::difference( $order, array( array( 'ref' => 'i:999', 'qty' => 1, 'unit_paid' => 6000 ) ), array(), $opts );
check_same( 'a line that is not on the order: unknown', Shipping::UNKNOWN, $d['status'] );

$order = fh_order(
	402,
	array(
		'paid'     => 1000,
		'shipping' => 0,
		'lines'    => array( 11 => array( 'name' => 'Lagoon Goby', 'qty' => 1, 'total' => 60.00, 'meta' => array( '_fh_invoice_fish' => 1, '_fh_invoice_product_id' => 7999 ) ) ),
	)
);
$d = Shipping::difference( $order, $fish, array(), $opts );
check_same( 'the invoice the order paid is gone as a product: unknown', array( Shipping::UNKNOWN, null ), array( $d['status'], $d['diff'] ) );

$d = Shipping::difference( $GLOBALS['fh_orders'][101], $fish, array(), array() );
check_same( 'without WooCommerce\'s shipping zones (and no stand-in): unknown', array( Shipping::UNKNOWN, null ), array( $d['status'], $d['diff'] ) );

echo "\nOther cases of the same order\n";
$order = $GLOBALS['fh_orders'][101]; // 520.00 at 19.00: a 60.00 goby (line 11) and two 230.00 tangs (line 12).
$d     = Shipping::difference( $order, $fish, array( 'pending' => 1 ), $opts );
check_same( 'another case still waits for an answer: unknown — the figure for this fish alone is only a hint', array( Shipping::UNKNOWN, null, 3099 ), array( $d['status'], $d['diff'], $d['alone'] ) );
check_contains( '… and the note says so', 'alone', $d['note'] );

$tang = array( array( 'ref' => 'i:12', 'qty' => 1, 'unit_paid' => 23000 ) );
$d    = Shipping::difference(
	$order,
	$tang,
	array(
		'removed'  => $fish,
		'withheld' => 3099,
	),
	$opts
);
check_same( 'an earlier refund already took the order under the threshold and withheld the 30.99: this one withholds nothing more', array( Shipping::NONE, 0 ), array( $d['status'], $d['diff'] ) );

$order = fh_order(
	501,
	array(
		'paid'     => 1000,
		'shipping' => 19.00,
		'lines'    => array(
			11 => array( 'name' => 'Lagoon Goby', 'product_id' => 812, 'qty' => 1, 'total' => 40.00 ),
			12 => array( 'name' => 'Harbor Tang', 'product_id' => 813, 'qty' => 13, 'total' => 520.00 ),
		),
	)
);
$one = array( array( 'ref' => 'i:11', 'qty' => 1, 'unit_paid' => 4000 ) );
$two = array( array( 'ref' => 'i:12', 'qty' => 1, 'unit_paid' => 4000 ) );
check_same( 'each alone leaves the order over the threshold (560.00 → 520.00)', array( Shipping::NONE, Shipping::NONE ), array( Shipping::difference( $order, $one, array(), $opts )['status'], Shipping::difference( $order, $two, array(), $opts )['status'] ) );
$d = Shipping::difference( $order, $two, array( 'removed' => $one, 'withheld' => 0 ), $opts );
check_same( '… but after the first was refunded, the second takes it under (480.00): the difference lands on the second', array( Shipping::DROP, 3099 ), array( $d['status'], $d['diff'] ) );

$order->shipping_tax = 1.90;
$d                   = Shipping::difference( $GLOBALS['fh_orders'][105], $tang, array( 'removed' => $fish, 'withheld' => 3409 ), $opts );
check_same( 'what was withheld with tax is taken off before tax', array( Shipping::NONE, 0 ), array( $d['status'], $d['diff'] ) );

echo "\nThrough WooCommerce's zone and method (stand-ins)\n";

/**
 * A session: WooCommerce's is abstract without abstract methods.
 */
abstract class WC_Session {

	public $data = array();

	public function set( $key, $value ) {
		$this->data[ $key ] = $value;
	}

	public function get( $key, $default = null ) {
		return isset( $this->data[ $key ] ) ? $this->data[ $key ] : $default;
	}
}

class Fh_Woo {

	public $session = 'the session of whoever is logged in';
	public $cart    = 'the cart of whoever is logged in';
}

function WC() {
	static $wc = null;
	if ( null === $wc ) {
		$wc = new Fh_Woo();
	}
	return $wc;
}

class Fh_Rate {

	public $cost;
	public $label;

	public function __construct( $cost, $label ) {
		$this->cost  = $cost;
		$this->label = $label;
	}

	public function get_cost() {
		return $this->cost;
	}

	public function get_label() {
		return $this->label;
	}
}

class Fh_Method {

	public $id;
	public $instance_id;
	public $rates    = array();
	public $seen     = array();
	public $extra    = false;
	public $throws   = false;
	public $no_rates = false;

	public function __construct( $id, $instance_id ) {
		$this->id          = $id;
		$this->instance_id = $instance_id;
	}

	public function get_rates_for_package( $package ) {
		$this->seen[] = array(
			'package' => $package,
			'session' => WC()->session,
			'cart'    => WC()->cart,
		);
		WC()->session->set( 'left behind by the method', 'x' ); // What a table-rate method does with its notices.
		if ( $this->throws ) {
			throw new \Error( 'Call to a member function get_cart() on null' );
		}
		if ( $this->no_rates ) {
			return array();
		}

		$sum = 0;
		foreach ( $package['contents'] as $line ) {
			$sum += $line['data']->get_price() * $line['quantity'];
		}
		$rates = array( 'r1' => new Fh_Rate( $sum >= 500 ? '19.00' : '49.99', 'Overnight' ) );
		if ( $this->extra ) {
			$rates['r2'] = new Fh_Rate( '5.00', 'Pickup' );
		}
		return $rates;
	}
}

class Fh_Zone {

	public $methods = array();

	public function get_shipping_methods( $enabled_only = false ) {
		return $this->methods;
	}
}

class WC_Shipping_Zones {

	public static $zone;
	public static $packages = array();

	public static function get_zone_matching_package( $package ) {
		self::$packages[] = $package;
		return self::$zone;
	}
}

$overnight                 = new Fh_Method( 'table_rate', 4 );
$other                     = new Fh_Method( 'table_rate', 9 );
WC_Shipping_Zones::$zone   = new Fh_Zone();
WC_Shipping_Zones::$zone->methods = array( 9 => $other, 4 => $overnight );

$order = $GLOBALS['fh_orders'][101];
$d     = Shipping::difference( $order, $fish, array(), array() );
check_same( 'the order\'s own method prices it as placed (19.00) and without the fish (49.99)', array( Shipping::DROP, 3099 ), array( $d['status'], $d['diff'] ) );
check_same( '… the method of the order\'s instance, not another instance of the same method', array( 2, 0 ), array( count( $overnight->seen ), count( $other->seen ) ) );

$package = $overnight->seen[0]['package'];
check_same( 'the package as placed: both lines, their quantities and what was paid', array( array( 'i:11', 'i:12' ), 1, 2, 520.0 ), array( array_keys( $package['contents'] ), $package['contents']['i:11']['quantity'], $package['contents']['i:12']['quantity'], (float) $package['contents_cost'] ) );
check_same( '… going to the order\'s shipping address', array( 'US', 'IL', '60000' ), array( $package['destination']['country'], $package['destination']['state'], $package['destination']['postcode'] ) );
check_same( '… each product priced at what the order paid for it (230.00 a tang), on a copy — the real product is untouched', array( 230.0, 0.0 ), array( $package['contents']['i:12']['data']->get_price(), $GLOBALS['fh_products'][813]->get_price() ) );
check_same( 'the package without the fish: one line left', array( 'i:12' ), array_keys( $overnight->seen[1]['package']['contents'] ) );

check( 'while the method worked, the session was a throw-away one and the cart was out of reach', $overnight->seen[0]['session'] instanceof WC_Session && null === $overnight->seen[0]['cart'] );
check_same( '… and both are back afterwards, with nothing the method left behind', array( 'the session of whoever is logged in', 'the cart of whoever is logged in' ), array( WC()->session, WC()->cart ) );

$overnight->throws = true;
$d                 = Shipping::difference( $order, $fish, array(), array() );
check_same( 'a method that needs a cart throws: unknown', array( Shipping::UNKNOWN, null ), array( $d['status'], $d['diff'] ) );
check_same( '… and the session and the cart are back all the same', array( 'the session of whoever is logged in', 'the cart of whoever is logged in' ), array( WC()->session, WC()->cart ) );
$overnight->throws = false;

$overnight->no_rates = true;
check_same( 'a method that gives no rate: unknown', Shipping::UNKNOWN, Shipping::difference( $order, $fish, array(), array() )['status'] );
$overnight->no_rates = false;

$overnight->extra = true;
check_same( 'two rates, one with the label the order was charged under: that one', array( Shipping::DROP, 3099 ), array_values( array_intersect_key( Shipping::difference( $order, $fish, array(), array() ), array( 'status' => 1, 'diff' => 1 ) ) ) );
$order->ship_lines[1]->name = 'Something else';
check_same( 'two rates and none can be told apart as the one charged: unknown', Shipping::UNKNOWN, Shipping::difference( $order, $fish, array(), array() )['status'] );
$order->ship_lines[1]->name = 'Overnight';
$overnight->extra           = false;

$order->ship_lines[1]->instance_id = 5;
check_same( 'the order\'s method is no longer in the zone: unknown', Shipping::UNKNOWN, Shipping::difference( $order, $fish, array(), array() )['status'] );
$order->ship_lines[1]->instance_id = 0;
check_same( 'a shipping line that names no instance: unknown', Shipping::UNKNOWN, Shipping::difference( $order, $fish, array(), array() )['status'] );
$order->ship_lines[1]->instance_id = 4;

$order->ship_lines[2] = new Fh_Ship_Line();
check_same( 'two shipping lines: unknown', Shipping::UNKNOWN, Shipping::difference( $order, $fish, array(), array() )['status'] );
unset( $order->ship_lines[2] );

$order->ship_lines = array();
check_same( 'no shipping line: unknown', Shipping::UNKNOWN, Shipping::difference( $order, $fish, array(), array() )['status'] );

fh_finish();
