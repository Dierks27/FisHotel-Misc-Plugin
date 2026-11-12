<?php
/**
 * Invoices contract tests — the holders WITH the Invoices section's two
 * functions present (stand-ins here, answering in the shapes that section
 * documents): fishotel_invoices_for_order() gives the invoices of an order
 * with their lines, fishotel_invoices_lines_for_product() the lines of one
 * invoice product. A line the section links to the product, to the
 * species and size, or to the tank group counts as owed; a line that is
 * plainly the fish the order already lists gives that line its link
 * instead of counting twice; an invoice made by hand has no ids; a
 * function that throws does not stop the order's own lines being read;
 * and the cover maths end to end over them.
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
$GLOBALS['fh_product_lines']   = array(); // invoice product id => what fishotel_invoices_lines_for_product() answers.
$GLOBALS['fh_invoice_calls']   = array();
$GLOBALS['fh_product_calls']   = array();
$GLOBALS['fh_invoices_throws'] = false;

function fishotel_invoices_for_order( $order_id ) {
	$GLOBALS['fh_invoice_calls'][] = (int) $order_id;
	if ( $GLOBALS['fh_invoices_throws'] ) {
		throw new \RuntimeException( 'the invoices table is gone' );
	}
	return isset( $GLOBALS['fh_invoices'][ (int) $order_id ] ) ? $GLOBALS['fh_invoices'][ (int) $order_id ] : array();
}

function fishotel_invoices_lines_for_product( $product_id ) {
	$GLOBALS['fh_product_calls'][] = (int) $product_id;
	return isset( $GLOBALS['fh_product_lines'][ (int) $product_id ] ) ? $GLOBALS['fh_product_lines'][ (int) $product_id ] : array();
}

/**
 * A line as the Invoices section hands it out.
 */
function fh_inv_line( array $over ) {
	return array_merge(
		array(
			'id'           => 0,
			'invoice_id'   => 0,
			'kind'         => 'fish',
			'name'         => '',
			'qty'          => 1,
			'unit_price'   => null,
			'amount'       => 0.0,
			'product_id'   => null,
			'variation_id' => null,
			'species_id'   => null,
			'stay_id'      => null,
			'size_slug'    => null,
			'size_label'   => null,
			'source'       => 'typed',
			'take_stock'   => false,
			'sort'         => 0,
		),
		$over
	);
}

/**
 * An invoice as fishotel_invoices_for_order() hands it out.
 */
function fh_invoice( $id, $product_id, array $lines ) {
	return array(
		'id'             => $id,
		'product_id'     => $product_id,
		'made_by_hand'   => 0 === $id,
		'handle'         => 'example-reefer',
		'forum_user_id'  => null,
		'customer_id'    => null,
		'customer_name'  => 'Avery Example',
		'customer_email' => 'avery@example.test',
		'state'          => 0 === $id ? 'hand' : 'paid',
		'title'          => 'Example Invoice',
		'total'          => 0.0,
		'flat_total'     => null,
		'shipping'       => '',
		'shipping_slug'  => '',
		'order_id'       => null,
		'url'            => '',
		'lines'          => $lines,
	);
}

/**
 * Forget the orders and what the Invoices section answers.
 */
function fh_fresh() {
	fh_reset();
	$GLOBALS['fh_invoices']      = array();
	$GLOBALS['fh_product_lines'] = array();
	$GLOBALS['fh_invoice_calls'] = array();
	$GLOBALS['fh_product_calls'] = array();
}

$store = new Fh_Fake_Store();
$goby  = Cover::kind_of_stay( array( 'product_id' => 812, 'species' => 'Lagoon Goby', 'size_slug' => 'm', 'size_label' => 'Medium' ), 'Lagoon Goby' );
$brief = static function ( array $holders ) {
	return array_map(
		static function ( $h ) {
			return array( $h['order_id'], $h['ref'], $h['match'], $h['qty'], $h['unit_paid'] );
		},
		$holders
	);
};

check( 'the Invoices section is there in this test', Orders::invoices_available() );

echo "\nAn itemised invoice: the order's own fish lines get their link\n";
fh_fresh();
// The theme made one product-less line per fish; nothing on the order says which product or tank group it is.
fh_order( 101, array( 'paid' => 1000, 'lines' => array( 11 => array( 'name' => 'Goby, lagoon (med)', 'qty' => 2, 'total' => 90.00, 'meta' => array( '_fh_invoice_fish' => 1, '_fh_invoice_product_id' => 7001 ) ), 12 => array( 'name' => 'Sand Shrimp', 'qty' => 1, 'total' => 12.00, 'meta' => array( '_fh_invoice_fish' => 1, '_fh_invoice_product_id' => 7001 ) ) ) ) );
$GLOBALS['fh_invoices'][101] = array(
	fh_invoice(
		31,
		7001,
		array(
			fh_inv_line( array( 'id' => 5, 'invoice_id' => 31, 'name' => 'Goby, lagoon (med)', 'qty' => 2, 'unit_price' => 45.0, 'product_id' => 812, 'stay_id' => 1 ) ),
			fh_inv_line( array( 'id' => 6, 'invoice_id' => 31, 'name' => 'Sand Shrimp', 'qty' => 1, 'unit_price' => 12.0, 'product_id' => 900 ) ),
			fh_inv_line( array( 'id' => 7, 'invoice_id' => 31, 'kind' => 'credit', 'name' => 'Credit: lost shrimp', 'unit_price' => -8.0 ) ),
		)
	),
);
$orders = new Orders( $store );
check_same( 'ONE holder: the order line, linked by the Invoices section — not the line and the invoice line', array( array( 101, 'i:11', 'invoice', 2, 4500 ) ), $brief( $orders->holders( $goby ) ) );
check_same( 'the invoice\'s own product was already answered for: fishotel_invoices_lines_for_product() is not asked', array(), $GLOBALS['fh_product_calls'] );

echo "\nLinked to the tank group only\n";
$GLOBALS['fh_invoices'][101][0]['lines'][0]['product_id'] = null;
$orders = new Orders( $store );
check_same( 'not when the group is none of the kind\'s', array(), $brief( $orders->holders( $goby, array( 2, 3 ) ) ) );
check_same( 'a holder when it is', array( array( 101, 'i:11', 'invoice', 2, 4500 ) ), $brief( $orders->holders( $goby, array( 1, 3 ) ) ) );

echo "\nAn invoice the order keeps as one line\n";
fh_fresh();
fh_product( 7002, array( 'class' => 'premium' ) );
fh_order( 201, array( 'paid' => 2000, 'lines' => array( 21 => array( 'name' => 'Example Invoice (11/2/26)', 'product_id' => 7002, 'total' => 147.00, 'meta' => array( '_fh_invoice_product_id' => 7002 ) ) ) ) );
$GLOBALS['fh_invoices'][201] = array(
	fh_invoice(
		32,
		7002,
		array(
			fh_inv_line( array( 'id' => 8, 'invoice_id' => 32, 'name' => 'Lagoon Goby', 'qty' => 3, 'unit_price' => 45.0, 'product_id' => 812 ) ),
			fh_inv_line( array( 'id' => 9, 'invoice_id' => 32, 'name' => 'Sand Shrimp', 'qty' => 1, 'unit_price' => 12.0, 'product_id' => 900 ) ),
			fh_inv_line( array( 'id' => 10, 'invoice_id' => 32, 'kind' => 'note', 'name' => 'Call before shipping' ) ),
		)
	),
);
$orders = new Orders( $store );
check_same( 'the invoice line itself is the holder, under the invoice\'s and the line\'s ids', array( array( 201, 'inv:32:8', 'invoice', 3, 4500 ) ), $brief( $orders->holders( $goby ) ) );

echo "\nThe same fish, listed by the order and by the Invoices section\n";
fh_fresh();
fh_product( 7009, array( 'class' => 'premium' ) );
fh_order( 251, array( 'paid' => 2000, 'lines' => array( 25 => array( 'name' => 'Example Invoice (11/5/26)', 'product_id' => 7009, 'total' => 135.00, 'meta' => array( '_fh_invoice_product_id' => 7009, '_fh_invoice_lines' => '[{"name":"Lagoon Goby","qty":3,"price":45,"kind":"fish"}]' ) ) ) ) );
$GLOBALS['fh_invoices'][251] = array( fh_invoice( 33, 7009, array( fh_inv_line( array( 'id' => 1, 'invoice_id' => 33, 'name' => 'Lagoon Goby', 'qty' => 3, 'unit_price' => 45.0, 'product_id' => 812 ) ) ) ) );
$orders                      = new Orders( $store );
check_same( 'three gobies are owed, not six: the invoice line gives the order\'s own line its link', array( array( 251, 'i:25:0', 'invoice', 3, 4500 ) ), $brief( $orders->holders( $goby ) ) );
$GLOBALS['fh_invoices'][251][0]['lines'][] = fh_inv_line( array( 'id' => 2, 'invoice_id' => 33, 'name' => 'Lagoon Goby', 'qty' => 1, 'unit_price' => 45.0, 'product_id' => 812 ) );
$orders                                    = new Orders( $store );
check_same( 'a second line of the same fish with another quantity is a second line', array( 'i:25:0', 'inv:33:2' ), array_column( $orders->holders( $goby ), 'ref' ) );

echo "\nfishotel_invoices_lines_for_product(): an invoice the other function did not answer for\n";
fh_fresh();
fh_product( 7003, array( 'class' => 'premium' ) );
fh_order( 301, array( 'paid' => 1000, 'lines' => array( 31 => array( 'name' => 'Example Invoice (10/30/26)', 'product_id' => 7003, 'total' => 110.00, 'meta' => array( '_fh_invoice_product_id' => 7003 ) ) ) ) );
// An invoice made by hand: its lines have no ids at all.
$GLOBALS['fh_product_lines'][7003] = array(
	fh_inv_line( array( 'name' => 'Lagoon Goby', 'qty' => 2, 'unit_price' => 50.0, 'product_id' => 812, 'source' => 'hand' ) ),
	fh_inv_line( array( 'name' => 'Credit: lost shrimp', 'kind' => 'credit', 'unit_price' => -8.0, 'source' => 'hand', 'sort' => 1 ) ),
	fh_inv_line( array( 'name' => 'Sand Shrimp', 'qty' => 1, 'unit_price' => 10.0, 'source' => 'hand', 'sort' => 2 ) ),
);
$orders = new Orders( $store );
check_same( 'its lines are asked for by the invoice\'s product, and named by that product and their place in it', array( array( 301, 'inv:p7003:n0', 'invoice', 2, 5000 ) ), $brief( $orders->holders( $goby ) ) );
check_same( '… asked with the INVOICE\'s product id — never the fish\'s', array( 7003 ), array_values( array_unique( $GLOBALS['fh_product_calls'] ) ) );
$orders->holders( $goby );
$orders->facts( $GLOBALS['fh_orders'][301] );
check_same( '… once per request', 1, count( $GLOBALS['fh_product_calls'] ) );

echo "\nA species without a product\n";
fh_fresh();
$blenny = Cover::kind_of_stay( array( 'product_id' => 0, 'species' => 'Reef Blenny', 'size_slug' => 'm', 'size_label' => 'Medium' ) );
foreach ( array( 401 => 7004, 402 => 7005, 403 => 7006, 404 => 7007 ) as $order_id => $invoice_product ) {
	fh_product( $invoice_product, array( 'class' => 'premium' ) );
	fh_order( $order_id, array( 'paid' => $order_id, 'lines' => array( $order_id - 360 => array( 'name' => 'Example Invoice', 'product_id' => $invoice_product, 'total' => 30.00, 'meta' => array( '_fh_invoice_product_id' => $invoice_product ) ) ) ) );
}
fh_order( 405, array( 'paid' => 5000, 'lines' => array( 45 => array( 'name' => 'Sand Shrimp', 'product_id' => 900, 'total' => 20.00 ) ) ) );
$GLOBALS['fh_invoices'] = array(
	401 => array( fh_invoice( 51, 7004, array( fh_inv_line( array( 'id' => 1, 'invoice_id' => 51, 'name' => 'Reef Blenny', 'size_slug' => 'm', 'unit_price' => 30.0 ) ) ) ) ),
	402 => array( fh_invoice( 52, 7005, array( fh_inv_line( array( 'id' => 1, 'invoice_id' => 52, 'name' => 'Reef Blenny', 'size_slug' => 'l', 'unit_price' => 30.0 ) ) ) ) ),
	403 => array( fh_invoice( 53, 7006, array( fh_inv_line( array( 'id' => 1, 'invoice_id' => 53, 'name' => 'reef blenny', 'unit_price' => 30.0 ) ) ) ) ),
	404 => array( fh_invoice( 54, 7007, array( fh_inv_line( array( 'id' => 1, 'invoice_id' => 54, 'name' => 'Some Other Fish', 'stay_id' => 77, 'unit_price' => 30.0 ) ) ) ) ),
);
$store->prefilter       = array( 401, 402, 403, 404 ); // What the order-item tables name: the orders with a line out of an invoice.
$store->prefilter_calls = array();
$orders                 = new Orders( $store );
$ids                    = array_column( $orders->holders( $blenny, array( 77 ) ), 'order_id' );
sort( $ids );
check_same( 'the same species and size (401), the same species with no size on the line (403), a line linked to the tank group itself (404) — not the same species in another size (402)', array( 401, 403, 404 ), $ids );
check_same( 'the order-item tables were asked for the orders with invoice lines (no product to look for)', array( array( 405, 404, 403, 402, 401 ), array() ), $store->prefilter_calls[0] );
$asked = array_values( array_unique( $GLOBALS['fh_invoice_calls'] ) );
sort( $asked );
check_same( '… and only those orders were put to the Invoices section — not every open order', array( 401, 402, 403, 404 ), $asked );
$store->prefilter = null;

echo "\nThe shapes the functions answer in\n";
$line     = fh_inv_line( array( 'id' => 4, 'invoice_id' => 9, 'name' => 'Lagoon Goby', 'qty' => 2, 'unit_price' => 45.0, 'product_id' => 812, 'variation_id' => 8121, 'stay_id' => 3, 'size_slug' => 'M' ) );
$expected = array( 'inv:9:4', 77, 'Lagoon Goby', 2, 4500, true, array( 'product_id' => 812, 'variation_id' => 8121, 'species' => 'Lagoon Goby', 'size_slug' => 'm', 'stay_id' => 3 ) );
foreach ( array(
	'the invoices of an order'        => array( array( fh_invoice( 9, 7010, array( $line ) ) ), 0 ),
	'one invoice'                     => array( fh_invoice( 9, 7010, array( $line ) ), 0 ),
	'the lines of an invoice product' => array( array( $line ), 7010 ),
	'objects'                         => array( array( (object) array_merge( fh_invoice( 9, 7010, array() ), array( 'lines' => array( (object) $line ) ) ) ), 0 ),
) as $label => $pair ) {
	$lines = Orders::normalize_invoice_lines( $pair[0], 77, $pair[1] );
	check_same( $label, $expected, array( $lines[0]['ref'], $lines[0]['order_id'], $lines[0]['name'], $lines[0]['qty'], $lines[0]['unit_paid'], $lines[0]['paid_known'], $lines[0]['link'] ) );
	check_same( '… and which invoice product it belongs to', 7010, $lines[0]['invoice_product_id'] );
}
$hand = Orders::normalize_invoice_lines( array( fh_invoice( 0, 7011, array( fh_inv_line( array( 'name' => 'Lagoon Goby' ) ), fh_inv_line( array( 'name' => 'Reef Blenny', 'sort' => 1 ) ) ) ), fh_invoice( 0, 7012, array( fh_inv_line( array( 'name' => 'Lagoon Goby' ) ) ) ) ), 77 );
check_same( 'two invoices made by hand in one order: every line still has a name of its own', array( 'inv:p7011:n0', 'inv:p7011:n1', 'inv:p7012:n0' ), array_column( $hand, 'ref' ) );
check_same( 'a line without a price: what was paid is not known', array( 0, false ), array( $hand[0]['unit_paid'], $hand[0]['paid_known'] ) );
check_same( 'a credit, a note and a line of nothing are not fish', array(), Orders::normalize_invoice_lines( array( fh_inv_line( array( 'kind' => 'credit', 'name' => 'Credit', 'unit_price' => -5.0 ) ), fh_inv_line( array( 'kind' => 'note', 'name' => 'Call first' ) ), array( 'name' => 'Lagoon Goby', 'qty' => 0 ) ), 77, 7010 ) );
check_same( 'an invoice without lines', array(), Orders::normalize_invoice_lines( array( fh_invoice( 9, 7010, array() ) ), 77 ) );
foreach ( array( 'null' => null, 'false' => false, 'a string' => 'nothing', 'an empty list' => array(), 'a number' => 7 ) as $label => $junk ) {
	check_same( 'nothing usable: ' . $label, array(), Orders::normalize_invoice_lines( $junk, 77 ) );
}

echo "\nA function that throws\n";
fh_fresh();
fh_order( 501, array( 'paid' => 1000, 'lines' => array( 51 => array( 'name' => 'Lagoon Goby', 'product_id' => 812, 'total' => 60.00 ) ) ) );
$GLOBALS['fh_invoices_throws'] = true;
$orders                        = new Orders( $store );
check_same( 'the order\'s own lines are still read — the product line is a holder', array( array( 501, 'i:51', 'product', 1, 6000 ) ), $brief( $orders->holders( $goby ) ) );
$GLOBALS['fh_invoices_throws'] = false;

echo "\nAn invoice line the section knows only by name\n";
fh_fresh();
fh_product( 7008, array( 'class' => 'premium' ) );
fh_order( 601, array( 'paid' => 1000, 'lines' => array( 61 => array( 'name' => 'Lagoon Goby', 'product_id' => 812, 'qty' => 2, 'total' => 120.00 ) ) ) );
fh_order( 602, array( 'paid' => 3000, 'lines' => array( 62 => array( 'name' => 'Example Invoice', 'product_id' => 7008, 'total' => 135.00, 'meta' => array( '_fh_invoice_product_id' => 7008 ) ) ) ) );
$GLOBALS['fh_invoices'][602] = array( fh_invoice( 61, 7008, array( fh_inv_line( array( 'id' => 1, 'invoice_id' => 61, 'name' => 'Lagoon Goby', 'qty' => 3, 'unit_price' => 45.0 ) ) ) ) );
$orders                      = new Orders( $store );
$named                       = array();
foreach ( $orders->holders( $goby ) as $holder ) {
	$named[ $holder['order_id'] ] = $holder['match'];
}
ksort( $named );
check_same( 'without a product on the line it is matched by NAME (and marked so), like the theme\'s own invoice lines', array( 601 => 'product', 602 => 'name' ), $named );

echo "\nThe cover maths over the contract\n";
$GLOBALS['fh_invoices'][602][0]['lines'][0]['product_id'] = 812;
$orders = new Orders( $store );
$tanks  = array(
	array( 'id' => 1, 'product_id' => 812, 'species' => 'Lagoon Goby', 'size_slug' => 'm', 'qty_live' => 2, 'status' => 'in_qt' ),
	array( 'id' => 2, 'product_id' => 812, 'species' => 'Lagoon Goby', 'size_slug' => 'l', 'qty_live' => 2, 'status' => 'cleared' ),
);
$a = Cover::assess( $goby, $tanks, $orders->holders( $goby ), array() );
check_same( 'a shop line of two and an invoice line of three: five owed, four alive in two groups, short by one', array( 5, 4, 1 ), array( $a['committed'], $a['covered'], $a['shortage'] ) );
check_same( 'the invoice order was paid last: its line is the one suggested', array( 602, 'inv:61:1', 1 ), array( $a['allocations'][0]['holder']['order_id'], $a['allocations'][0]['holder']['ref'], $a['allocations'][0]['qty'] ) );

fh_finish();
