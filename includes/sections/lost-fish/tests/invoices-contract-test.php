<?php
/**
 * Invoices contract tests — the holders WITH the Invoices section's two
 * functions present (stand-ins here): a line it links to the product, to
 * the species and size, or to the tank group counts as owed; a line tied
 * to an order item gives that item its link instead of counting twice; a
 * species without a product makes every open order be asked about; the
 * shapes the functions may answer in (invoices with lines, one invoice, a
 * flat list; arrays or objects); and the cover maths end to end over them.
 *
 * Run from the plugin root:
 *   php includes/sections/lost-fish/tests/invoices-contract-test.php
 *
 * @package FisHotel\Misc\Sections\Lost_Fish
 */

require_once __DIR__ . '/wp-stubs.php';
require_once __DIR__ . '/fakes.php';

use FisHotel\Misc\Sections\Lost_Fish\Cover;
use FisHotel\Misc\Sections\Lost_Fish\Orders;

$GLOBALS['fh_invoices']        = array(); // order id => what fishotel_invoices_for_order() answers.
$GLOBALS['fh_product_lines']   = array(); // product id => what fishotel_invoices_lines_for_product() answers.
$GLOBALS['fh_invoice_calls']   = array();
$GLOBALS['fh_invoices_throws'] = false;

function fishotel_invoices_for_order( $order_id ) {
	$GLOBALS['fh_invoice_calls'][] = (int) $order_id;
	if ( $GLOBALS['fh_invoices_throws'] ) {
		throw new \RuntimeException( 'the invoices table is gone' );
	}
	return isset( $GLOBALS['fh_invoices'][ (int) $order_id ] ) ? $GLOBALS['fh_invoices'][ (int) $order_id ] : array();
}

function fishotel_invoices_lines_for_product( $product_id ) {
	return isset( $GLOBALS['fh_product_lines'][ (int) $product_id ] ) ? $GLOBALS['fh_product_lines'][ (int) $product_id ] : array();
}

$store = new Fh_Fake_Store();
$goby  = Cover::kind_of_stay( array( 'product_id' => 812, 'species' => 'Lagoon Goby', 'size_slug' => 'm', 'size_label' => 'Medium' ), 'Lagoon Goby' );

check( 'the Invoices section is there in this test', Orders::invoices_available() );

echo "\nA line linked to the product\n";
// The order line is product-less and named differently: only the link says what it is.
fh_order( 101, array( 'paid' => 1000, 'lines' => array( 11 => array( 'name' => 'Goby, lagoon (med)', 'qty' => 2, 'total' => 90.00, 'meta' => array( '_fh_invoice_fish' => 1, '_fh_invoice_product_id' => 7001 ) ) ) ) );
$GLOBALS['fh_invoices'][101] = array(
	array(
		'id'    => 31,
		'lines' => array(
			array( 'id' => 1, 'order_item_id' => 11, 'name' => 'Goby, lagoon (med)', 'product_id' => 812, 'qty' => 2, 'price' => 45 ),
		),
	),
);
$orders = new Orders( $store );
$found  = $orders->holders( $goby );
check_same( 'one holder — the order item, with the link the Invoices section gave it; not the item AND the invoice line', array( array( 101, 'i:11', 'invoice', 2, 4500 ) ), array_map( static function ( $h ) { return array( $h['order_id'], $h['ref'], $h['match'], $h['qty'], $h['unit_paid'] ); }, $found ) );

echo "\nA line that is no order item\n";
fh_reset();
fh_order( 201, array( 'paid' => 2000, 'lines' => array( 21 => array( 'name' => 'Example Invoice (11/2/26)', 'product_id' => 7002, 'total' => 135.00 ) ) ) );
$GLOBALS['fh_invoices'] = array(
	201 => array(
		array(
			'invoice_id' => 32,
			'lines'      => array(
				array( 'line_id' => 'a', 'name' => 'Lagoon Goby', 'product_id' => 812, 'quantity' => 3, 'unit_price' => '45.00' ),
				array( 'line_id' => 'b', 'name' => 'Credit: lost shrimp', 'kind' => 'credit', 'qty' => 1, 'price' => -8 ),
				array( 'line_id' => 'c', 'name' => 'Sand Shrimp', 'product_id' => 900, 'qty' => 1, 'price' => 12 ),
			),
		),
	),
);
$orders = new Orders( $store );
$found  = $orders->holders( $goby );
check_same( 'the invoice line itself is the holder, under its own ref', array( array( 201, 'inv:32:a', 'invoice', 3, 4500, true ) ), array_map( static function ( $h ) { return array( $h['order_id'], $h['ref'], $h['match'], $h['qty'], $h['unit_paid'], $h['paid_known'] ); }, $found ) );
check_same( 'a credit is not a fish, another product is not the kind', 1, count( $found ) );

echo "\nfishotel_invoices_lines_for_product()\n";
fh_reset();
fh_order( 301, array( 'paid' => 1000, 'lines' => array( 31 => array( 'name' => 'Example Invoice', 'product_id' => 7003, 'total' => 50.00 ) ) ) );
fh_order( 302, array( 'paid' => 1000, 'lines' => array( 32 => array( 'name' => 'Sand Shrimp', 'product_id' => 900, 'total' => 20.00 ) ) ) );
$GLOBALS['fh_invoices']      = array();
$GLOBALS['fh_product_lines'] = array(
	812 => array(
		(object) array( 'invoice_id' => 40, 'line_id' => 2, 'order_id' => 301, 'name' => 'Lagoon Goby', 'qty' => 1, 'price_cents' => 5000 ),
		(object) array( 'invoice_id' => 41, 'line_id' => 1, 'order_id' => 0, 'name' => 'Lagoon Goby', 'qty' => 1, 'price' => 50 ),
	),
);
$store->prefilter = array(); // The order-item tables name no order: the invoice is one line with another product.
$orders           = new Orders( $store );
$found            = $orders->holders( $goby );
check_same( 'the order the Invoices section names for the product is looked at, though the order-item tables did not name it', array( array( 301, 'inv:40:2', 'invoice', 5000 ) ), array_map( static function ( $h ) { return array( $h['order_id'], $h['ref'], $h['match'], $h['unit_paid'] ); }, $found ) );
check( 'an invoice nobody has bought yet (no order) holds nothing', 1 === count( $found ) );
$store->prefilter            = null;
$GLOBALS['fh_product_lines'] = array();

echo "\nA species without a product\n";
fh_reset();
$blenny = Cover::kind_of_stay( array( 'product_id' => 0, 'species' => 'Reef Blenny', 'size_slug' => 'm', 'size_label' => 'Medium' ) );
fh_order( 401, array( 'paid' => 1000, 'lines' => array( 41 => array( 'name' => 'Example Invoice A', 'product_id' => 7004, 'total' => 30.00 ) ) ) );
fh_order( 402, array( 'paid' => 2000, 'lines' => array( 42 => array( 'name' => 'Example Invoice B', 'product_id' => 7005, 'total' => 30.00 ) ) ) );
fh_order( 403, array( 'paid' => 3000, 'lines' => array( 43 => array( 'name' => 'Example Invoice C', 'product_id' => 7006, 'total' => 30.00 ) ) ) );
fh_order( 404, array( 'paid' => 4000, 'lines' => array( 44 => array( 'name' => 'Example Invoice D', 'product_id' => 7007, 'total' => 30.00 ) ) ) );
$GLOBALS['fh_invoices'] = array(
	401 => array( array( 'id' => 51, 'lines' => array( array( 'id' => 1, 'species' => 'Reef Blenny', 'size_slug' => 'm', 'qty' => 1, 'price' => 30 ) ) ) ),
	402 => array( array( 'id' => 52, 'lines' => array( array( 'id' => 1, 'species' => 'Reef Blenny', 'size_slug' => 'l', 'qty' => 1, 'price' => 30 ) ) ) ),
	403 => array( array( 'id' => 53, 'lines' => array( array( 'id' => 1, 'species' => 'reef blenny', 'qty' => 1, 'price' => 30 ) ) ) ),
	404 => array( array( 'id' => 54, 'lines' => array( array( 'id' => 1, 'species' => 'Some Other Fish', 'stay_id' => 77, 'qty' => 1, 'price' => 30 ) ) ) ),
);
$store->prefilter         = array(); // Would hide every order — but for a species only the Invoices section knows.
$store->prefilter_calls   = array();
$GLOBALS['fh_invoice_calls'] = array();
$orders                   = new Orders( $store );
$ids                      = array_column( $orders->holders( $blenny, array( 77 ) ), 'order_id' );
sort( $ids );
check_same( 'the same species and size (401), the same species with no size on the line (403), a line linked to the tank group itself (404) — not the same species in another size (402)', array( 401, 403, 404 ), $ids );
$asked = array_values( array_unique( $GLOBALS['fh_invoice_calls'] ) );
sort( $asked );
check_same( 'every open order was asked about, without the prefilter', array( array( 401, 402, 403, 404 ), 0 ), array( $asked, count( $store->prefilter_calls ) ) );
$store->prefilter = null;

echo "\nThe shapes the functions may answer in\n";
$one_invoice = array( 'id' => 9, 'order_id' => 77, 'lines' => array( array( 'id' => 4, 'name' => 'Lagoon Goby', 'product_id' => 812, 'qty' => 2, 'price' => 45 ) ) );
$flat        = array( array( 'invoice_id' => 9, 'line_id' => 4, 'order_id' => 77, 'name' => 'Lagoon Goby', 'product_id' => 812, 'qty' => 2, 'price' => 45 ) );
$objects     = array( (object) array( 'id' => 9, 'order_id' => 77, 'lines' => array( (object) array( 'id' => 4, 'name' => 'Lagoon Goby', 'product_id' => 812, 'qty' => 2, 'price' => 45 ) ) ) );
$expected    = array( 'inv:9:4', 77, 'Lagoon Goby', 2, 4500, 812 );
foreach ( array(
	'a list of invoices'   => array( $one_invoice ),
	'one invoice'          => $one_invoice,
	'a flat list of lines' => $flat,
	'objects'              => $objects,
) as $label => $data ) {
	$lines = Orders::normalize_invoice_lines( $data );
	check_same( $label, $expected, array( $lines[0]['ref'], $lines[0]['order_id'], $lines[0]['name'], $lines[0]['qty'], $lines[0]['unit_paid'], $lines[0]['link']['product_id'] ) );
}
check_same( 'the order asked about fills in for a line that names none', 55, Orders::normalize_invoice_lines( array( array( 'name' => 'Lagoon Goby', 'qty' => 1 ) ), 55 )[0]['order_id'] );
check_same( 'a line without a price: paid is not known', array( 0, false ), array_values( array_intersect_key( Orders::normalize_invoice_lines( array( array( 'name' => 'Lagoon Goby', 'qty' => 1 ) ) )[0], array( 'unit_paid' => 1, 'paid_known' => 1 ) ) ) );
check_same( 'a line of nothing, a note and a credit are not fish', array(), Orders::normalize_invoice_lines( array( array( 'name' => 'Lagoon Goby', 'qty' => 0 ), array( 'name' => 'Call first', 'kind' => 'note' ), array( 'name' => 'Credit', 'kind' => 'credit', 'price' => -5 ) ) ) );
foreach ( array( 'null' => null, 'false' => false, 'a string' => 'nothing', 'an empty list' => array(), 'a number' => 7 ) as $label => $junk ) {
	check_same( 'nothing usable: ' . $label, array(), Orders::normalize_invoice_lines( $junk ) );
}

echo "\nA function that throws\n";
fh_reset();
fh_order( 501, array( 'paid' => 1000, 'lines' => array( 51 => array( 'name' => 'Lagoon Goby', 'product_id' => 812, 'total' => 60.00 ) ) ) );
$GLOBALS['fh_invoices_throws'] = true;
$orders                        = new Orders( $store );
$found                         = $orders->holders( $goby );
check_same( 'the order lines are still read — the product line is a holder', array( array( 501, 'product' ) ), array_map( static function ( $h ) { return array( $h['order_id'], $h['match'] ); }, $found ) );
$GLOBALS['fh_invoices_throws'] = false;

echo "\nThe cover maths over the contract\n";
fh_reset();
fh_order( 601, array( 'paid' => 1000, 'lines' => array( 61 => array( 'name' => 'Lagoon Goby', 'product_id' => 812, 'qty' => 2, 'total' => 120.00 ) ) ) );
fh_order( 602, array( 'paid' => 3000, 'lines' => array( 62 => array( 'name' => 'Example Invoice', 'product_id' => 7008, 'total' => 135.00 ) ) ) );
$GLOBALS['fh_invoices'] = array(
	602 => array( array( 'id' => 61, 'lines' => array( array( 'id' => 1, 'name' => 'Lagoon Goby', 'product_id' => 812, 'qty' => 3, 'price' => 45 ) ) ) ),
);
$orders = new Orders( $store );
$tanks  = array(
	array( 'id' => 1, 'product_id' => 812, 'species' => 'Lagoon Goby', 'size_slug' => 'm', 'qty_live' => 2, 'status' => 'in_qt' ),
	array( 'id' => 2, 'product_id' => 812, 'species' => 'Lagoon Goby', 'size_slug' => 'l', 'qty_live' => 2, 'status' => 'cleared' ),
);
$a = Cover::assess( $goby, $tanks, $orders->holders( $goby ), array() );
check_same( 'a shop line of two and an invoice line of three: five owed, four alive in two groups, short by one', array( 5, 4, 1 ), array( $a['committed'], $a['covered'], $a['shortage'] ) );

// A line the Invoices section names without a product: found by its name, like the theme's own invoice lines.
$GLOBALS['fh_invoices'][602] = array( array( 'id' => 61, 'lines' => array( array( 'id' => 1, 'name' => 'Lagoon Goby', 'qty' => 3, 'price' => 45 ) ) ) );
$orders                      = new Orders( $store );
$named                       = array();
foreach ( $orders->holders( $goby ) as $holder ) {
	$named[ $holder['order_id'] ] = $holder['match'];
}
ksort( $named );
check_same( 'an invoice line without a product link is matched by NAME (and marked so)', array( 601 => 'product', 602 => 'name' ), $named );
$GLOBALS['fh_invoices'][602] = array( array( 'id' => 61, 'lines' => array( array( 'id' => 1, 'name' => 'Lagoon Goby', 'product_id' => 812, 'qty' => 3, 'price' => 45 ) ) ) );
$orders                      = new Orders( $store );
$a                           = Cover::assess( $goby, $tanks, $orders->holders( $goby ), array() );
check_same( 'the invoice order was paid last: its line is the one suggested', array( 602, 'inv:61:1', 1 ), array( $a['allocations'][0]['holder']['order_id'], $a['allocations'][0]['holder']['ref'], $a['allocations'][0]['qty'] ) );

fh_finish();
