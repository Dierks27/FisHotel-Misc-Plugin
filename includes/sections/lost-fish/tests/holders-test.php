<?php
/**
 * Holders tests — which order lines are OWED a fish of a kind, with
 * ShipTracker present and WITHOUT the Invoices section: paid means a paid
 * date or is_paid() (never the status name), closed orders and combined-box
 * wrappers owe nothing, a line a shipment names has shipped (a shipment
 * without a list covers nothing), the product versus the variation, the
 * product-less fish lines of an invoice matched by name, the fish inside
 * an invoice kept as one line, the open-order prefilter, and an order's
 * facts (customer, money, delivery and ship date).
 *
 * Run from the plugin root:
 *   php includes/sections/lost-fish/tests/holders-test.php
 *
 * @package FisHotel\Misc\Sections\Lost_Fish
 */

require_once __DIR__ . '/wp-stubs.php';
require_once __DIR__ . '/fakes.php';

use FisHotel\Misc\Sections\Lost_Fish\Cover;
use FisHotel\Misc\Sections\Lost_Fish\Orders;

/**
 * ShipTracker's shipment model, as far as it is read: rows with a JSON
 * list of the order line ids in the box (or NULL).
 */
class FST_Shipment {

	public static $rows  = array();
	public static $calls = 0;

	public static function get_by_order( $order_id ) {
		self::$calls++;
		return isset( self::$rows[ (int) $order_id ] ) ? self::$rows[ (int) $order_id ] : array();
	}
}

$goby  = Cover::kind_of_stay(
	array(
		'product_id' => 812,
		'species'    => 'Lagoon Goby',
		'size_slug'  => 'm',
		'size_label' => 'Medium',
	),
	'Lagoon Goby'
);
$store = new Fh_Fake_Store();

check( 'the Invoices section is not there in this test', ! Orders::invoices_available() );
check( 'ShipTracker is', Orders::shipments_available() );

echo "\nPaid, not closed, not a wrapper\n";
fh_order( 101, array( 'paid' => 1000, 'status' => 'processing', 'lines' => array( 11 => array( 'name' => 'Lagoon Goby', 'product_id' => 812, 'qty' => 2, 'total' => 120.00 ) ) ) );
fh_order( 102, array( 'paid' => null, 'status' => 'processing', 'lines' => array( 12 => array( 'name' => 'Lagoon Goby', 'product_id' => 812, 'total' => 60.00 ) ) ) );
fh_order( 103, array( 'paid' => null, 'paid_flag' => true, 'date_created' => 900, 'status' => 'on-hold', 'lines' => array( 13 => array( 'name' => 'Lagoon Goby', 'product_id' => 812, 'total' => 60.00 ) ) ) );
fh_order( 104, array( 'paid' => 2000, 'status' => 'pending', 'lines' => array( 14 => array( 'name' => 'Lagoon Goby', 'product_id' => 812, 'total' => 60.00 ) ) ) );
fh_order( 105, array( 'paid' => 3000, 'status' => 'completed', 'lines' => array( 15 => array( 'name' => 'Lagoon Goby', 'product_id' => 812, 'total' => 60.00 ) ) ) );
fh_order( 106, array( 'paid' => 3000, 'status' => 'cancelled', 'lines' => array( 16 => array( 'name' => 'Lagoon Goby', 'product_id' => 812, 'total' => 60.00 ) ) ) );
fh_order( 107, array( 'paid' => 3000, 'status' => 'refunded', 'lines' => array( 17 => array( 'name' => 'Lagoon Goby', 'product_id' => 812, 'total' => 60.00 ) ) ) );
fh_order( 108, array( 'paid' => 3000, 'status' => 'fulfillment', 'meta' => array( '_fishotel_is_fulfillment' => '1' ), 'lines' => array( 18 => array( 'name' => 'Lagoon Goby', 'product_id' => 812, 'total' => 60.00 ) ) ) );
fh_order( 109, array( 'paid' => 3000, 'status' => 'processing', 'lines' => array( 19 => array( 'name' => 'Sand Shrimp', 'product_id' => 900, 'total' => 20.00 ) ) ) );

$orders  = new Orders( $store );
$holders = $orders->holders( $goby );
$ids     = array_column( $holders, 'order_id' );
sort( $ids );
check_same( 'the holders: a paid date (101), is_paid() without one (103), a paid date under the status "pending" (104)', array( 101, 103, 104 ), $ids );
check( 'no paid date and not is_paid(): not a holder, though its status is "processing"', ! in_array( 102, $ids, true ) );
check( 'completed, cancelled and refunded orders owe nothing', ! array_intersect( array( 105, 106, 107 ), $ids ) );
check( 'a combined-box wrapper is skipped — its lines are copies', ! in_array( 108, $ids, true ) );
check( 'another product is not the kind', ! in_array( 109, $ids, true ) );

$query = $GLOBALS['fh_wc_queries'][0];
check_same( 'the open orders are asked for through wc_get_orders(), ids only', array( 'shop_order', 'ids' ), array( $query['type'], $query['return'] ) );
check( '… every status except the closed ones', in_array( 'wc-processing', $query['status'], true ) && in_array( 'wc-shipped', $query['status'], true ) && in_array( 'wc-pending', $query['status'], true ) && ! array_intersect( array( 'wc-completed', 'wc-cancelled', 'wc-refunded', 'wc-failed' ), $query['status'] ) );
$orders->holders( $goby );
check_same( '… once per request, however many times a kind is asked about', 1, count( $GLOBALS['fh_wc_queries'] ) );

$by_order = array();
foreach ( $holders as $holder ) {
	$by_order[ $holder['order_id'] ] = $holder;
}
check_same( 'a holder names its line, its fish, its quantity and what one was paid', array( 'i:11', 11, 'Lagoon Goby', 2, 6000, true, 'product' ), array( $by_order[101]['ref'], $by_order[101]['item_id'], $by_order[101]['name'], $by_order[101]['qty'], $by_order[101]['unit_paid'], $by_order[101]['paid_known'], $by_order[101]['match'] ) );
check_same( '… and its customer', array( 'Avery Example', 'avery@example.test', '101' ), array( $by_order[101]['customer_name'], $by_order[101]['customer_email'], $by_order[101]['order_number'] ) );
check_same( 'paid at: the paid date', 1000, $by_order[101]['paid_at'] );
check_same( 'paid at, without a paid date: the day the order was made', 900, $by_order[103]['paid_at'] );

echo "\nShipped lines\n";
fh_reset();
fh_order( 201, array( 'paid' => 1000, 'lines' => array( 21 => array( 'name' => 'Lagoon Goby', 'product_id' => 812, 'total' => 60.00 ), 22 => array( 'name' => 'Sand Shrimp', 'product_id' => 900, 'total' => 20.00 ) ) ) );
fh_order( 202, array( 'paid' => 1000, 'lines' => array( 23 => array( 'name' => 'Lagoon Goby', 'product_id' => 812, 'total' => 60.00 ), 24 => array( 'name' => 'Sand Shrimp', 'product_id' => 900, 'total' => 20.00 ) ) ) );
fh_order( 203, array( 'paid' => 1000, 'lines' => array( 25 => array( 'name' => 'Lagoon Goby', 'product_id' => 812, 'total' => 60.00 ) ) ) );
fh_order( 204, array( 'paid' => 1000, 'lines' => array( 26 => array( 'name' => 'Lagoon Goby', 'product_id' => 812, 'total' => 60.00, 'meta' => array( '_fishotel_line_status' => 'shipped' ) ) ) ) );
fh_order( 205, array( 'paid' => 1000, 'lines' => array( 27 => array( 'name' => 'Lagoon Goby', 'product_id' => 812, 'total' => 60.00, 'meta' => array( '_fishotel_line_status' => 'backordered' ) ) ) ) );
FST_Shipment::$rows = array(
	201 => array( (object) array( 'line_item_ids' => '[21]' ) ),
	202 => array( (object) array( 'line_item_ids' => '[24]' ) ),
	203 => array( (object) array( 'line_item_ids' => null ) ),
);
$orders = new Orders( $store );
$ids    = array_column( $orders->holders( $goby ), 'order_id' );
sort( $ids );
check_same( 'a line a shipment names has shipped (201); another line of the order shipping does not ship it (202); a shipment without a list covers nothing (203); line status "shipped" has shipped (204), "backordered" has not (205)', array( 202, 203, 205 ), $ids );
check_same( 'the shipped line ids of an order', array( 21 => true ), $orders->shipped_item_ids( $GLOBALS['fh_orders'][201] ) );
$calls = FST_Shipment::$calls;
$orders->facts( $GLOBALS['fh_orders'][201] );
check_same( 'ShipTracker is asked once per order in a request', $calls, FST_Shipment::$calls );
FST_Shipment::$rows = array();

echo "\nRefunds, quantities\n";
fh_reset();
fh_order( 301, array( 'paid' => 1000, 'refunded_qty' => array( 31 => 1 ), 'lines' => array( 31 => array( 'name' => 'Lagoon Goby', 'product_id' => 812, 'qty' => 3, 'total' => 150.00, 'tax' => 12.00 ) ) ) );
$orders = new Orders( $store );
$holder = $orders->holders( $goby )[0];
check_same( 'the line\'s quantity and what a refund already took off it', array( 3, 1 ), array( $holder['qty'], $holder['qty_refunded'] ) );
check_same( 'what one fish was paid: the line total and its tax, per fish', 5400, $holder['unit_paid'] );

echo "\nProduct and variation\n";
fh_reset();
fh_order( 401, array( 'paid' => 1000, 'lines' => array( 41 => array( 'name' => 'Lagoon Goby - Medium', 'product_id' => 812, 'variation_id' => 8121, 'total' => 60.00 ) ) ) );
fh_order( 402, array( 'paid' => 2000, 'lines' => array( 42 => array( 'name' => 'Lagoon Goby - Large', 'product_id' => 700, 'variation_id' => 812, 'total' => 80.00 ) ) ) );
$orders = new Orders( $store );
$found  = array();
foreach ( $orders->holders( $goby ) as $holder ) {
	$found[ $holder['order_id'] ] = $holder['match'];
}
ksort( $found );
check_same( 'the group\'s product is the line\'s product, or the line\'s variation', array( 401 => 'product', 402 => 'variation' ), $found );

echo "\nInvoice lines, without the Invoices section\n";
fh_reset();
// An itemised invoice: product-less fish lines carrying the theme's meta.
fh_order(
	501,
	array(
		'paid'  => 1000,
		'lines' => array(
			51 => array( 'name' => 'Lagoon Goby', 'qty' => 2, 'total' => 90.00, 'meta' => array( '_fh_invoice_fish' => 1, '_fh_invoice_product_id' => 7001 ) ),
			52 => array( 'name' => 'Sand Shrimp', 'qty' => 1, 'total' => 12.00, 'meta' => array( '_fh_invoice_fish' => 1, '_fh_invoice_product_id' => 7001 ) ),
		),
	)
);
// The same name on an ordinary product-less line (a deleted product): not an invoice fish.
fh_order( 502, array( 'paid' => 1000, 'lines' => array( 53 => array( 'name' => 'Lagoon Goby', 'total' => 60.00 ) ) ) );
// An invoice kept as ONE line, its fish in the snapshot.
fh_product( 7002, array( 'class' => 'premium' ) );
fh_order(
	503,
	array(
		'paid'  => 3000,
		'lines' => array(
			54 => array(
				'name'       => 'Example Invoice (11/2/26)',
				'product_id' => 7002,
				'total'      => 172.00,
				'meta'       => array( '_fh_invoice_lines' => '[{"name":"Lagoon Goby (Medium)","qty":2,"price":45,"kind":"fish"},{"name":"Credit: lost shrimp","qty":1,"price":-8,"kind":"credit"},{"name":"Reef Blenny","qty":1,"price":null,"kind":"fish"}]' ),
			),
		),
	)
);
// An itemised fish line the theme resolved to a real product.
fh_order( 504, array( 'paid' => 4000, 'lines' => array( 55 => array( 'name' => 'Goby (lagoon)', 'product_id' => 812, 'total' => 50.00, 'meta' => array( '_fh_invoice_fish' => 1, '_fh_invoice_product_id' => 7003 ) ) ) ) );

$orders = new Orders( $store );
$found  = array();
foreach ( $orders->holders( $goby ) as $holder ) {
	$found[ $holder['order_id'] . '/' . $holder['ref'] ] = array( $holder['match'], $holder['qty'], $holder['unit_paid'], $holder['paid_known'] );
}
ksort( $found );
check_same(
	'a product-less invoice fish line is matched by NAME; a fish inside a kept-whole invoice too (its size in the name); a line with the product needs no name',
	array(
		'501/i:51'   => array( 'name', 2, 4500, true ),
		'503/i:54:0' => array( 'name', 2, 4500, true ),
		'504/i:55'   => array( 'product', 1, 5000, true ),
	),
	$found
);
check( 'an ordinary product-less line with the same name is NOT matched — only invoice fish are matched by name', ! isset( $found['502/i:53'] ) );

$blenny = Cover::kind_of_stay( array( 'product_id' => 0, 'species' => 'Reef Blenny', 'size_slug' => '', 'size_label' => '' ) );
$holder = $orders->holders( $blenny )[0];
check_same( 'a species without a product: the fish inside the invoice, by name — its price is not on the line', array( 503, 'i:54:2', 'name', 0, false ), array( $holder['order_id'], $holder['ref'], $holder['match'], $holder['unit_paid'], $holder['paid_known'] ) );
check_same( 'a credit line of an invoice is never a fish', 2, count( Orders::invoice_sublines( $GLOBALS['fh_orders'][503]->items[54]->meta['_fh_invoice_lines'] ) ) );
check_same( 'a snapshot that is not JSON is no snapshot', null, Orders::invoice_sublines( '{not json' ) );

echo "\nThe open-order prefilter\n";
fh_reset();
fh_order( 601, array( 'paid' => 1000, 'lines' => array( 61 => array( 'name' => 'Lagoon Goby', 'product_id' => 812, 'total' => 60.00 ) ) ) );
fh_order( 602, array( 'paid' => 1000, 'lines' => array( 62 => array( 'name' => 'Lagoon Goby', 'product_id' => 812, 'total' => 60.00 ) ) ) );
fh_order( 603, array( 'paid' => 1000, 'lines' => array( 63 => array( 'name' => 'Sand Shrimp', 'product_id' => 900, 'total' => 20.00 ) ) ) );
$store->prefilter       = array( 601, 602 );
$store->prefilter_calls = array();
$orders                 = new Orders( $store );
$ids                    = array_column( $orders->holders( $goby ), 'order_id' );
sort( $ids );
check_same( 'the store is asked which open orders hold the product', array( array( 603, 602, 601 ), array( 812 ) ), $store->prefilter_calls[0] );
$loads = array_values( array_unique( $GLOBALS['fh_wc_loads'] ) );
sort( $loads );
check_same( '… and only the orders it names are loaded', array( array( 601, 602 ), array( 601, 602 ) ), array( $ids, $loads ) );

$store->prefilter       = null;
$GLOBALS['fh_wc_loads'] = array();
$orders                 = new Orders( $store );
$orders->holders( $goby );
$loads = array_values( array_unique( $GLOBALS['fh_wc_loads'] ) );
sort( $loads );
check_same( 'when the store cannot say, every open order is looked at through the order API', array( 601, 602, 603 ), $loads );

$store->prefilter = array( 999 );
$orders           = new Orders( $store );
check_same( 'an order the store names that is not open is not looked at', array(), $orders->holders( $goby ) );
$store->prefilter = null;

$orders = new Orders( null );
check_same( 'without a store there is no prefilter and it still works', 2, count( $orders->holders( $goby ) ) );

echo "\nAn order's facts\n";
fh_reset();
$order = fh_order(
	701,
	array(
		'paid'         => 5000,
		'shipping'     => 19.00,
		'shipping_tax' => 1.90,
		'refunded'     => 10.00,
		'customer_id'  => 44,
		'meta'         => array( '_fishotel_shipping_date' => '2026-11-18' ),
		'lines'        => array(
			71 => array( 'name' => 'Lagoon Goby', 'product_id' => 812, 'qty' => 1, 'total' => 60.00 ),
			72 => array( 'name' => 'Sand Shrimp', 'product_id' => 900, 'qty' => 2, 'total' => 40.00 ),
		),
	)
);
$orders = new Orders( $store );
$facts  = $orders->facts( $order );
check_same( 'who', array( 701, '701', 'Avery Example', 'Avery', 'avery@example.test', 44 ), array( $facts['id'], $facts['number'], $facts['customer_name'], $facts['first_name'], $facts['email'], $facts['customer_id'] ) );
check_same( 'the money, in cents: total, refunded, shipping, shipping tax', array( 12090, 1000, 1900, 190 ), array( $facts['total'], $facts['refunded'], $facts['ship_charged'], $facts['ship_tax'] ) );
check_same( 'the currency symbol as plain text', '$', $facts['symbol'] );
check_same( 'paid, open, not a wrapper, nothing shipped', array( true, false, false, false ), array( $facts['paid'], $facts['closed'], $facts['wrapper'], $facts['any_shipped'] ) );
check_same( 'the delivery date the checkout stored, and the ship date: the day before', array( '2026-11-18', '2026-11-17' ), array( $facts['delivery_date'], $facts['ship_date'] ) );
check( 'the link to the order', false !== strpos( $facts['edit_url'], '701' ) );

$order->meta = array();
check_same( 'an order without a date has none — nothing is made up', array( '', '' ), array( $orders->delivery_date( $order ), $orders->facts( $order )['ship_date'] ) );

fh_order( 702, array( 'paid' => 1, 'status' => 'fulfillment', 'meta' => array( '_fishotel_is_fulfillment' => '1', '_fishotel_shipping_date' => '2026-12-02' ) ) );
$order->meta = array( '_fishotel_fulfilled_by_order' => '702' );
check_same( 'an order folded into a combined box takes the box\'s date', '2026-12-02', $orders->delivery_date( $order ) );

$order->meta = array( '_fishotel_shipping_date' => 'next tuesday' );
check_same( 'something that is not a date is no date', '', $orders->delivery_date( $order ) );
check_same( 'the ship date of the first of a month is the last of the month before', '2026-02-28', Orders::ship_date_for( '2026-03-01' ) );
check_same( 'no delivery date, no ship date', '', Orders::ship_date_for( '' ) );

check_same( 'the rest of the order: other lines are still to ship', true, $orders->rest_left( $order, 'i:71', 1 ) );
$order->items = array( 71 => $order->items[71] );
check_same( '… nothing else once the fish was the only line', false, $orders->rest_left( $order, 'i:71', 1 ) );
$order->items[71]->qty = 2;
check_same( '… the other fish of the same line is still to ship', true, $orders->rest_left( $order, 'i:71', 1 ) );

echo "\nNotes\n";
check( 'a note is written through the order', $orders->note( 701, 'Lost fish case #1 — test', true ) );
check_same( '… as a PRIVATE note, never a note to the customer', array( 'Lost fish case #1 — test', 0, true ), array_values( $order->notes[0] ) );
check_same( 'no order, no note', false, $orders->note( 99999, 'x' ) );

echo "\nOne line by its ref\n";
check_same( 'found', 'Lagoon Goby', $orders->line( $order, 'i:71' )['name'] );
check_same( 'not found', null, $orders->line( $order, 'i:999' ) );

fh_finish();
