<?php
/**
 * The pre-check — "read before you touch": this customer's orders, found
 * by the linked site customer, by the billing e-mail, by the name and by
 * a line named "<handle> Invoice" (also the lines the theme made out of
 * one), each with its status and whether it is PAID — from the paid date
 * / is_paid(), never from the status name; the customer's existing
 * invoices — built here, made by hand, or only remembered by an order —
 * with their lines and paid or not; the fish of the new invoice that
 * already appear on one of them; and what is worth knowing about each
 * line (shop stock below the quantity, a tank group with too few alive).
 *
 * Run from the plugin root:  php includes/sections/invoices/tests/precheck-test.php
 */

require_once __DIR__ . '/wp-stubs.php';

use FisHotel\Misc\Sections\Invoices\Invoices;
use FisHotel\Misc\Sections\Invoices\Lines;
use FisHotel\Misc\Sections\Invoices\Precheck;
use FisHotel\Misc\Sections\Invoices\Publisher;
use FisHotel\Misc\Sections\Invoices\Settings;
use FisHotel\Misc\Sections\Invoices\Shop;
use FisHotel\Misc\Sections\Invoices\Tests\Fake_Store;
use FisHotel\Misc\Sections\Invoices\Tests\Fake_Tanks;

const USER  = 8;
const TODAY = '2026-11-03';

$world    = fh_wc_world();
$store    = new Fake_Store();
$shop     = new Shop();
$tanks    = new Fake_Tanks();
$settings = array_merge( Settings::defaults(), array( 'image_under' => $world['img_small'], 'image_over' => $world['img_big'] ) );

/**
 * The invoice being built, and its lines.
 */
function building( array $more = array() ) {
	return array_merge(
		array(
			'id'             => 0,
			'handle'         => 'Tester',
			'customer_id'    => null,
			'customer_name'  => '',
			'customer_email' => '',
			'shipping'       => 'premium',
			'shipping_slug'  => '',
		),
		$more
	);
}

function fish_lines( array $names ) {
	$rows = array();
	foreach ( $names as $name ) {
		$rows[] = array( 'kind' => 'fish', 'name' => $name, 'qty' => 1, 'unit_price' => '20' );
	}
	return Lines::normalize( $rows );
}

function by_ref( array $entries, $ref ) {
	foreach ( $entries as $entry ) {
		if ( $entry['ref'] === $ref ) {
			return $entry;
		}
	}
	return null;
}

function order_ids( array $report ) {
	return array_column( $report['orders'], 'id' );
}

echo "1. A customer nobody has seen before\n";
$check  = new Precheck( $store, $shop, $tanks, $settings );
$report = $check->report( building(), fish_lines( array( 'Blue Test Tang' ) ), TODAY );
check( 'no orders, no invoices, nothing to confirm, no error', array() === $report['orders'] && array() === $report['invoices'] && array() === $report['dup_keys'] && array() === $report['errors'] );
check( 'it says what could not be searched: only the handle is known', 1 === count( preg_grep( '/Only the handle is known/', $report['order_notes'] ) ) );
check( 'what will be written', 'Tester Invoice (11/3/26)' === $report['writes']['title'] && 'Tester Only' === $report['writes']['excerpt'] && '20.00' === $report['writes']['regular_price'] && 'premium' === $report['writes']['shipping_slug'] && $world['ship_premium'] === $report['writes']['shipping_class']['id'] && $world['cat_invoices'] === $report['writes']['category']['id'] && $world['img_small'] === $report['writes']['image_id'] && false !== strpos( $report['writes']['description'], '<li>Blue Test Tang x1' ) );

echo "2. This customer's orders, and whether each is PAID\n";
$world = fh_wc_world();
$store = new Fake_Store();
$check = new Precheck( $store, $shop, $tanks, $settings );

$by_customer = fh_wc_order( array( 'status' => 'completed', 'date_paid' => true, 'total' => 50, 'customer_id' => $world['customer'], 'billing_first_name' => 'Test', 'billing_last_name' => 'Person', 'billing_email' => 'billing@example.test', 'payment_method_title' => 'Test Pay' ), array( array( 'name' => 'Test Fish Food', 'qty' => 1, 'total' => 50, 'product_id' => 3001 ) ) );
$by_email    = fh_wc_order( array( 'status' => 'processing', 'date_paid' => true, 'total' => 20, 'billing_first_name' => 'T', 'billing_last_name' => 'P', 'billing_email' => 'PERSON@example.test' ), array( array( 'name' => 'Test Net', 'qty' => 2, 'total' => 20, 'product_id' => 3002 ) ) );
$by_name     = fh_wc_order( array( 'status' => 'on-hold', 'total' => 30, 'billing_first_name' => 'Test', 'billing_last_name' => 'Person', 'billing_email' => 'another@example.test' ), array( array( 'name' => 'Test Salt', 'qty' => 1, 'total' => 30, 'product_id' => 3003 ) ) );
$old_shape   = fh_wc_order( array( 'status' => 'completed', 'date_paid' => true, 'total' => 82, 'billing_first_name' => 'Somebody', 'billing_last_name' => 'Else', 'billing_email' => 'else@example.test' ), array( array( 'name' => 'Tester Invoice (7/3/26)', 'qty' => 1, 'total' => 82, 'product_id' => 4001 ) ) );
$new_shape   = fh_wc_order( array( 'status' => 'shipped', 'date_paid' => true, 'total' => 135, 'billing_first_name' => 'Somebody', 'billing_last_name' => 'Else' ), array(
	array( 'name' => 'Yellow Test Wrasse', 'qty' => 1, 'total' => 89, 'meta' => array( Invoices::ITEM_FISH_META => 1, Invoices::ITEM_PRODUCT_META => 4002, Invoices::ITEM_TITLE_META => 'Tester Invoice (8/4/26)' ) ),
	array( 'name' => 'Orange Test Clown', 'qty' => 2, 'total' => 58, 'meta' => array( Invoices::ITEM_FISH_META => 1, Invoices::ITEM_PRODUCT_META => 4002, Invoices::ITEM_TITLE_META => 'Tester Invoice (8/4/26)' ) ),
	array( 'name' => 'Credit: lost Test Firefish', 'total' => -12, 'type' => 'fee', 'meta' => array( Invoices::ITEM_CREDIT_META => 1, Invoices::ITEM_PRODUCT_META => 4002 ) ),
) );
$processing  = fh_wc_order( array( 'status' => 'processing', 'date_paid' => 0, 'total' => 10, 'customer_id' => $world['customer'] ), array( array( 'name' => 'Test Thing', 'qty' => 1, 'total' => 10, 'product_id' => 3004 ) ) );
$cancelled   = fh_wc_order( array( 'status' => 'cancelled', 'date_paid' => true, 'total' => 10, 'customer_id' => $world['customer'] ), array( array( 'name' => 'Test Thing', 'qty' => 1, 'total' => 10, 'product_id' => 3004 ) ) );
$stranger    = fh_wc_order( array( 'status' => 'completed', 'date_paid' => true, 'total' => 99, 'billing_first_name' => 'Nobody', 'billing_last_name' => 'Known', 'billing_email' => 'nobody@example.test' ), array( array( 'name' => 'Tester Two Invoice (7/3/26)', 'qty' => 1, 'total' => 99, 'product_id' => 4003 ) ) );
$mirror      = fh_wc_order( array( 'status' => 'fulfillment', 'total' => 135 ), array( array( 'name' => 'Yellow Test Wrasse', 'qty' => 1, 'total' => 89, 'meta' => array( Invoices::ITEM_FISH_META => 1, Invoices::ITEM_PRODUCT_META => 4002, Invoices::ITEM_TITLE_META => 'Tester Invoice (8/4/26)' ) ) ), array( Invoices::MIRROR_META => '1' ) );

$invoice = building( array( 'customer_id' => $world['customer'], 'customer_name' => 'Test Person', 'customer_email' => 'person@example.test' ) );
$report  = $check->report( $invoice, fish_lines( array( 'Blue Test Tang' ) ), TODAY );
$orders  = array();
foreach ( $report['orders'] as $order ) {
	$orders[ $order['id'] ] = $order;
}

check( 'found by the linked site customer', isset( $orders[ $by_customer ] ) && in_array( 'customer', $orders[ $by_customer ]['found_by'], true ) );
check( 'found by the billing e-mail of the linked customer too', in_array( 'email', $orders[ $by_customer ]['found_by'], true ) );
check( 'found by the e-mail typed on the invoice, whatever its case', isset( $orders[ $by_email ] ) && array( 'email' ) === $orders[ $by_email ]['found_by'] );
check( 'found by the name', isset( $orders[ $by_name ] ) && in_array( 'name', $orders[ $by_name ]['found_by'], true ) );
check( 'found by a line named "<handle> Invoice", although the billing name is somebody else\'s', isset( $orders[ $old_shape ] ) && array( 'invoice' ) === $orders[ $old_shape ]['found_by'] );
check( 'found by the lines the theme made out of such an invoice (their `_fh_invoice_title`)', isset( $orders[ $new_shape ] ) && array( 'invoice' ) === $orders[ $new_shape ]['found_by'] );
check( 'an invoice of a longer handle that starts the same is not this customer\'s', ! isset( $orders[ $stranger ] ) );
check( 'a fulfilment copy of other orders is left out', ! isset( $orders[ $mirror ] ) );
check_same( 'newest first', array( $cancelled, $processing, $new_shape, $old_shape, $by_name, $by_email, $by_customer ), order_ids( $report ) );

check( 'PAID: a Completed order with a paid date', true === $orders[ $by_customer ]['paid'] && 'Test Pay' === $orders[ $by_customer ]['payment_method'] && '' !== $orders[ $by_customer ]['date_paid'] );
check( 'PAID: a Processing order with a paid date — status is not payment', true === $orders[ $by_email ]['paid'] );
check( 'PAID: an order with a status of the site\'s own ("shipped") and a paid date — never read from the status name', true === $orders[ $new_shape ]['paid'] && 'shipped' === $orders[ $new_shape ]['status'] );
check( 'NOT paid: On hold, no paid date', false === $orders[ $by_name ]['paid'] && 'On hold' === $orders[ $by_name ]['status_label'] );
check( 'PAID: Processing without a paid date — WooCommerce itself calls it paid', true === $orders[ $processing ]['paid'] && '' === $orders[ $processing ]['date_paid'] );
check( 'a cancelled order keeps its paid date and is listed as cancelled', true === $orders[ $cancelled ]['paid'] && 'cancelled' === $orders[ $cancelled ]['status'] );
check( 'each order comes with its lines', 1 === count( $orders[ $by_customer ]['lines'] ) && 'Test Fish Food' === $orders[ $by_customer ]['lines'][0]['name'] && 3 === count( $orders[ $new_shape ]['lines'] ) && true === $orders[ $new_shape ]['lines'][0]['is_fish'] && true === $orders[ $new_shape ]['lines'][2]['is_credit'] && true === $orders[ $old_shape ]['lines'][0]['is_invoice'] );
check( 'nothing is noted as not searched', array() === $report['order_notes'] );

$report = $check->report( building( array( 'customer_email' => 'person@example.test' ) ), fish_lines( array( 'Blue Test Tang' ) ), TODAY );
check( 'without a name: said so; the orders by e-mail and by invoice line are still there', 1 === count( preg_grep( '/not searched by name/', $report['order_notes'] ) ) && array( $new_shape, $old_shape, $by_email ) === order_ids( $report ) );

$store->orders_unreadable = true;
$report                   = ( new Precheck( $store, $shop, $tanks, $settings ) )->report( building(), fish_lines( array( 'Blue Test Tang' ) ), TODAY );
check( 'the order lines cannot be read: said so in plain words', 1 === count( preg_grep( '/could not be searched for "Tester Invoice"/', $report['order_notes'] ) ) );
$store->orders_unreadable = false;

$many_store = new Fake_Store();
for ( $i = 0; $i < Precheck::ORDER_LIMIT + 5; $i++ ) {
	fh_wc_order( array( 'status' => 'completed', 'date_paid' => true, 'billing_email' => 'many@example.test' ), array( array( 'name' => 'Test Thing', 'qty' => 1, 'total' => 1 ) ) );
}
$report = ( new Precheck( $many_store, $shop, $tanks, $settings ) )->report( building( array( 'customer_email' => 'many@example.test' ) ), fish_lines( array( 'A' ) ), TODAY );
check( 'a customer with very many orders: the newest ' . Precheck::ORDER_LIMIT . ', and a note that says so', Precheck::ORDER_LIMIT === count( $report['orders'] ) && 1 === count( preg_grep( '/Only the newest/', $report['order_notes'] ) ) );

echo "3. This customer's invoices: built here, made by hand, remembered by an order\n";
$world     = fh_wc_world();
$store     = new Fake_Store();
$publisher = new Publisher( $store, $shop, $tanks, $settings );

// Built here: one paid, one published and unpaid, one draft, one trashed, one withdrawn.
$make = static function ( array $form ) use ( $publisher ) {
	$check = $publisher->precheck( array_merge( array( 'handle' => 'Tester', 'shipping' => 'premium' ), $form ), USER, TODAY );
	return $publisher->publish( $check['id'], $check['token'], $check['report']['dup_keys'], USER, TODAY );
};
$paid      = $make( array( 'lines' => array( array( 'kind' => 'fish', 'name' => 'Paid Test Angel', 'qty' => 1, 'unit_price' => '100' ) ) ) );
$paid_by   = fh_wc_order( array( 'status' => 'completed', 'date_paid' => true, 'total' => 100 ), array( array( 'name' => 'Paid Test Angel', 'qty' => 1, 'total' => 100, 'meta' => array( Invoices::ITEM_FISH_META => 1, Invoices::ITEM_PRODUCT_META => $paid['product_id'], Invoices::ITEM_TITLE_META => 'Tester Invoice (11/3/26)' ) ) ) );
$unpaid    = $make( array( 'lines' => array( array( 'kind' => 'fish', 'name' => 'Unpaid Test Basslet', 'qty' => 2, 'unit_price' => '40' ) ) ) );
$draft     = $publisher->save_draft( array( 'handle' => 'tester', 'lines' => array( array( 'kind' => 'fish', 'name' => 'Draft Test Cardinal', 'qty' => 1, 'unit_price' => '15' ) ) ), USER );
$trashed   = $make( array( 'lines' => array( array( 'kind' => 'fish', 'name' => 'Trashed Test Damsel', 'qty' => 1, 'unit_price' => '9' ) ) ) );
$publisher->trash( $trashed['id'], USER );
$withdrawn = $publisher->save_draft( array( 'handle' => 'Tester', 'lines' => array( array( 'kind' => 'fish', 'name' => 'Withdrawn Test Eel', 'qty' => 1, 'unit_price' => '70' ) ) ), USER );
$publisher->discard( $withdrawn['id'], USER );
// The same person under another handle, linked to the same site customer; and a stranger.
$other_handle = $publisher->save_draft( array( 'handle' => 'ReefTester', 'customer_id' => $world['customer'], 'lines' => array( array( 'kind' => 'fish', 'name' => 'Linked Test Filefish', 'qty' => 1, 'unit_price' => '25' ) ) ), USER );
$stranger     = $publisher->save_draft( array( 'handle' => 'Stranger', 'lines' => array( array( 'kind' => 'fish', 'name' => 'Blue Test Tang', 'qty' => 1, 'unit_price' => '45' ) ) ), USER );

// Made by hand: one paid through the theme's lock, one unpaid with JSON lines, one of another handle.
$hand_paid  = fh_wc_product( array( 'name' => 'Tester Invoice (7/3/26)', 'description' => "<ul>\n<li>Hand Test Goby x2 — \$39</li>\n<li>Credit: lost Hand Test Wrasse — -\$10</li>\n</ul>", 'regular_price' => '68', 'category_ids' => array( $world['cat_invoices'] ), 'manage_stock' => true, 'stock_quantity' => 0, 'stock_status' => 'outofstock' ) );
$hand_order = fh_wc_order( array( 'status' => 'completed', 'date_paid' => true, 'total' => 68 ), array( array( 'name' => 'Tester Invoice (7/3/26)', 'qty' => 1, 'total' => 68, 'product_id' => $hand_paid ) ) );
$GLOBALS['fh_wc']['products'][ $hand_paid ]['meta'][ Invoices::PRODUCT_ORDER_META ] = $hand_order;
$hand_open  = fh_wc_product( array( 'name' => 'TESTER invoice (9/9/26)', 'description' => '<ul><li>Ignored Description Fish x1 — $1</li></ul>', 'regular_price' => '55', 'category_ids' => array( $world['cat_invoices'] ) ), array( Invoices::PRODUCT_LINES_META => '[{"name":"Json Test Hawkfish","qty":1,"price":55,"kind":"fish"}]' ) );
$hand_other = fh_wc_product( array( 'name' => 'Tester Two Invoice (9/9/26)', 'description' => '<ul><li>Blue Test Tang x1 — $45</li></ul>', 'regular_price' => '45', 'category_ids' => array( $world['cat_invoices'] ) ) );
$not_invoice = fh_wc_product( array( 'name' => 'Tester Invoice (1/1/26)', 'description' => '<ul><li>Blue Test Tang x1 — $45</li></ul>', 'regular_price' => '45', 'category_ids' => array( $world['cat_fish'] ) ) );

// Only an order still remembers it: the product of that invoice is gone.
$remembered = fh_wc_order( array( 'status' => 'completed', 'date_paid' => true, 'total' => 60, 'billing_email' => 'person@example.test' ), array( array( 'name' => 'Remembered Test Jawfish', 'qty' => 3, 'total' => 60, 'meta' => array( Invoices::ITEM_FISH_META => 1, Invoices::ITEM_PRODUCT_META => 9999, Invoices::ITEM_TITLE_META => 'Tester Invoice (1/2/25)' ) ) ) );
$kept_whole = fh_wc_order( array( 'status' => 'pending', 'total' => 33, 'billing_email' => 'person@example.test' ), array( array( 'name' => 'Tester Invoice (2/2/25)', 'qty' => 1, 'total' => 33, 'product_id' => 9998, 'meta' => array( Invoices::ITEM_LINES_META => '[{"name":"Snapshot Test Lionfish","qty":1,"price":33,"kind":"fish"}]', Invoices::ITEM_PRODUCT_META => 9998 ) ) ) );

$new    = Lines::normalize(
	array(
		array( 'kind' => 'fish', 'name' => 'Paid Test Angels', 'qty' => 1, 'unit_price' => '100' ),
		array( 'kind' => 'fish', 'name' => 'unpaid test basslet (Large)', 'qty' => 1, 'unit_price' => '40' ),
		array( 'kind' => 'fish', 'name' => 'Draft Test Cardinal', 'qty' => 1, 'unit_price' => '15' ),
		array( 'kind' => 'fish', 'name' => 'Trashed Test Damsel', 'qty' => 1, 'unit_price' => '9' ),
		array( 'kind' => 'fish', 'name' => 'Withdrawn Test Eel', 'qty' => 1, 'unit_price' => '70' ),
		array( 'kind' => 'fish', 'name' => 'Linked Test Filefish', 'qty' => 1, 'unit_price' => '25' ),
		array( 'kind' => 'fish', 'name' => 'Hand Test Goby', 'qty' => 1, 'unit_price' => '39' ),
		array( 'kind' => 'fish', 'name' => 'Json Test Hawkfish', 'qty' => 1, 'unit_price' => '55' ),
		array( 'kind' => 'fish', 'name' => 'Ignored Description Fish', 'qty' => 1, 'unit_price' => '1' ),
		array( 'kind' => 'fish', 'name' => 'Remembered Test Jawfish', 'qty' => 1, 'unit_price' => '20' ),
		array( 'kind' => 'fish', 'name' => 'Snapshot Test Lionfish', 'qty' => 1, 'unit_price' => '33' ),
		array( 'kind' => 'fish', 'name' => 'Blue Test Tang', 'qty' => 1, 'unit_price' => '45' ),
		array( 'kind' => 'fish', 'name' => 'Hand Test Wrasse', 'qty' => 1, 'unit_price' => '10' ),
	)
);
$building = building( array( 'customer_id' => $world['customer'], 'customer_email' => 'person@example.test' ) );
$check    = new Precheck( $store, $shop, $tanks, $settings );
$report   = $check->report( $building, $new, TODAY );
$entries  = $report['invoices'];

$e = by_ref( $entries, 'invoice:' . $paid['id'] );
check( 'built here and paid: PAID, with its order, its lines and its link', $e && 'section' === $e['kind'] && true === $e['paid'] && 'paid' === $e['state'] && $paid_by === $e['order']['id'] && false !== strpos( $e['state_label'], '#' . $paid_by ) && 1 === count( $e['lines'] ) && 'Paid Test Angel' === $e['lines'][0]['name'] && $paid['url'] === $e['url'] && 100.0 === $e['total'] );
check( '…and the stored invoice was brought up to date: paid, by that order', 'paid' === $store->invoice( $paid['id'] )['state'] && $paid_by === $store->invoice( $paid['id'] )['order_id'] && 1 === fh_fired( Invoices::PAID_ACTION, array( $paid['id'], $paid_by ) ) );
$e = by_ref( $entries, 'invoice:' . $unpaid['id'] );
check( 'built here, published, not paid', $e && false === $e['paid'] && 'published' === $e['state'] && null === $e['order'] && true === $e['counted'] );
$e = by_ref( $entries, 'invoice:' . $draft['id'] );
check( 'a draft of the same handle (typed in another case) is listed and counts', $e && 'draft' === $e['state'] && true === $e['counted'] && true === $e['by_handle'] && '' === $e['url'] );
$e = by_ref( $entries, 'invoice:' . $trashed['id'] );
check( 'a trashed one is listed but does not count: the customer passed on those fish', $e && 'trashed' === $e['state'] && false === $e['counted'] && '' === $e['url'] );
$e = by_ref( $entries, 'invoice:' . $withdrawn['id'] );
check( 'a withdrawn draft likewise', $e && 'cancelled' === $e['state'] && false === $e['counted'] );
$e = by_ref( $entries, 'invoice:' . $other_handle['id'] );
check( 'another handle linked to the same site customer is the same customer', $e && false === $e['by_handle'] && true === $e['counted'] );
check( 'a stranger\'s invoice is not listed', null === by_ref( $entries, 'invoice:' . $stranger['id'] ) );

$e = by_ref( $entries, 'product:' . $hand_paid );
check( 'made by hand and paid (the theme\'s lock on the product): PAID, with its order and the lines of its description', $e && 'hand' === $e['kind'] && true === $e['paid'] && $hand_order === $e['order']['id'] && false !== strpos( $e['state_label'], 'PAID on order #' . $hand_order ) && array( 'Hand Test Goby', 'Credit: lost Hand Test Wrasse' ) === array_column( $e['lines'], 'name' ) && 68.0 === $e['total'] );
check_same( '…its lines as text', array( "Hand Test Goby x2 \u{2014} \$39", "Credit: lost Hand Test Wrasse \u{2014} -\$10" ), array_column( $e['lines'], 'text' ) );
$e = by_ref( $entries, 'product:' . $hand_open );
check( 'made by hand, not paid, titled in another case: its lines come from the JSON, which wins over the description', $e && false === $e['paid'] && false !== strpos( $e['state_label'], 'not paid' ) && array( 'Json Test Hawkfish' ) === array_column( $e['lines'], 'name' ) );
check( 'a hand-made invoice of a longer handle, and a product outside the Invoices category, are not listed', null === by_ref( $entries, 'product:' . $hand_other ) && null === by_ref( $entries, 'product:' . $not_invoice ) );
check( 'the products built here are not listed a second time as "made by hand"', null === by_ref( $entries, 'product:' . $paid['product_id'] ) && null === by_ref( $entries, 'product:' . $unpaid['product_id'] ) );

$e = by_ref( $entries, 'order:' . $remembered . ':9999' );
check( 'an invoice only an order remembers (its product is gone): listed from the order\'s lines, paid with the order', $e && 'order' === $e['kind'] && true === $e['paid'] && 'Tester Invoice (1/2/25)' === $e['label'] && array( 'Remembered Test Jawfish' ) === array_column( $e['lines'], 'name' ) && 3 === $e['lines'][0]['qty'] );
$e = by_ref( $entries, 'order:' . $kept_whole . ':9998' );
check( 'one kept as a single line: its fish come from the snapshot on that line; the order is not paid', $e && false === $e['paid'] && array( 'Snapshot Test Lionfish' ) === array_column( $e['lines'], 'name' ) );
check( 'an order line of an invoice that IS listed is not listed again', null === by_ref( $entries, 'order:' . $paid_by . ':' . $paid['product_id'] ) && null === by_ref( $entries, 'order:' . $hand_order . ':' . $hand_paid ) );
check( 'the orders that contain the customer\'s invoices are in the order list, although nothing else ties them to the customer', in_array( $paid_by, order_ids( $report ), true ) && in_array( $hand_order, order_ids( $report ), true ) );

echo "4. The fish that already appear on another invoice of this customer\n";
$flagged = array();
foreach ( $report['duplicates'] as $dup ) {
	$flagged[ $dup['name'] ] = array_column( $dup['matches'], 'ref' );
}
check_same( 'on a paid invoice built here (a plural matched)', array( 'invoice:' . $paid['id'] ), $flagged['Paid Test Angels'] );
check_same( 'on an unpaid one (case and a size in brackets ignored)', array( 'invoice:' . $unpaid['id'] ), $flagged['unpaid test basslet (Large)'] );
check_same( 'on a draft', array( 'invoice:' . $draft['id'] ), $flagged['Draft Test Cardinal'] );
check( 'NOT on a trashed or a withdrawn one', ! isset( $flagged['Trashed Test Damsel'] ) && ! isset( $flagged['Withdrawn Test Eel'] ) );
check_same( 'on the invoice of the customer\'s other handle', array( 'invoice:' . $other_handle['id'] ), $flagged['Linked Test Filefish'] );
check_same( 'on a paid hand-made invoice', array( 'product:' . $hand_paid ), $flagged['Hand Test Goby'] );
check_same( 'on an unpaid hand-made one, by its JSON lines', array( 'product:' . $hand_open ), $flagged['Json Test Hawkfish'] );
check( 'NOT by the description the JSON overrides', ! isset( $flagged['Ignored Description Fish'] ) );
check_same( 'on an invoice only an order remembers', array( 'order:' . $remembered . ':9999' ), $flagged['Remembered Test Jawfish'] );
check_same( 'on one kept whole on a pending order', array( 'order:' . $kept_whole . ':9998' ), $flagged['Snapshot Test Lionfish'] );
check( 'NOT a fish only a stranger or a longer handle has, and NOT one named only in a credit', ! isset( $flagged['Blue Test Tang'] ) && ! isset( $flagged['Hand Test Wrasse'] ) );
check_same( 'one key to confirm per match: eight fish, eight keys', 8, count( $report['dup_keys'] ) );
check( 'each match says whether that invoice is paid', true === $report['duplicates'][0]['matches'][0]['paid'] && false === $report['duplicates'][1]['matches'][0]['paid'] );
check( 'a warning counts the other invoices that are not paid yet', 1 === count( preg_grep( '/other invoices that are not paid yet/', $report['warnings'] ) ) );

echo "5. The invoice being built is never compared with itself\n";
$own    = $store->invoice( $unpaid['id'] );
$report = ( new Precheck( $store, $shop, $tanks, $settings ) )->report( $own, $store->lines( $unpaid['id'] ), TODAY, 'update' );
check( 'an edit of the unpaid invoice: its own row and its own product are not among the others', null === by_ref( $report['invoices'], 'invoice:' . $unpaid['id'] ) && null === by_ref( $report['invoices'], 'product:' . $unpaid['product_id'] ) && array() === $report['dup_keys'] );
check_same( 'and it keeps the title it was published under', 'Tester Invoice (11/3/26)', $report['writes']['title'] );

echo "6. What is worth knowing about each line\n";
$world  = fh_wc_world();
$store  = new Fake_Store();
$tracked   = fh_wc_product( array( 'name' => 'Tracked Test Tang', 'regular_price' => '45', 'manage_stock' => true, 'stock_quantity' => 2, 'category_ids' => array( $world['cat_fish'] ) ) );
$untracked = fh_wc_product( array( 'name' => 'Untracked Test Goby', 'regular_price' => '12', 'category_ids' => array( $world['cat_fish'] ) ) );
$tanks         = new Fake_Tanks();
$tanks->groups = array(
	array( 'stay_id' => 21, 'species' => 'Tank Test Blenny', 'size_slug' => null, 'size_label' => null, 'alive' => 3, 'tank' => 121, 'order_ref' => '2026-3', 'product_id' => null, 'needs_id' => false, 'open' => true ),
	array( 'stay_id' => 22, 'species' => 'Empty Test Chromis', 'size_slug' => null, 'size_label' => null, 'alive' => 0, 'tank' => 122, 'order_ref' => '2026-3', 'product_id' => null, 'needs_id' => false, 'open' => false ),
);
// Another open invoice already holds two of the three blennies.
$store->save_invoice( 0, array( 'handle' => 'Other', 'handle_key' => 'other', 'state' => 'published' ), Lines::normalize( array( array( 'name' => 'Tank Test Blenny', 'qty' => 2, 'source' => 'tank', 'stay_id' => 21 ) ) ), null, USER );

$lines = Lines::normalize(
	array(
		array( 'name' => 'Tracked Test Tang', 'qty' => 3, 'unit_price' => '45', 'source' => 'product', 'product_id' => $tracked, 'take_stock' => true ),
		array( 'name' => 'Tracked Test Tang', 'qty' => 3, 'unit_price' => '45', 'source' => 'product', 'product_id' => $tracked, 'take_stock' => false ),
		array( 'name' => 'Tracked Test Tang', 'qty' => 2, 'unit_price' => '45', 'source' => 'product', 'product_id' => $tracked, 'take_stock' => true ),
		array( 'name' => 'Untracked Test Goby', 'qty' => 1, 'unit_price' => '12', 'source' => 'product', 'product_id' => $untracked, 'take_stock' => true ),
		array( 'name' => 'Gone Test Fish', 'qty' => 1, 'unit_price' => '5', 'source' => 'product', 'product_id' => 77777, 'take_stock' => true ),
		array( 'name' => 'Tank Test Blenny', 'qty' => 4, 'unit_price' => '30', 'source' => 'tank', 'stay_id' => 21 ),
		array( 'name' => 'Tank Test Blenny', 'qty' => 2, 'unit_price' => '30', 'source' => 'tank', 'stay_id' => 21 ),
		array( 'name' => 'Tank Test Blenny', 'qty' => 1, 'unit_price' => '30', 'source' => 'tank', 'stay_id' => 21 ),
		array( 'name' => 'Empty Test Chromis', 'qty' => 1, 'unit_price' => '8', 'source' => 'tank', 'stay_id' => 22 ),
		array( 'name' => 'Vanished Test Group', 'qty' => 1, 'unit_price' => '8', 'source' => 'tank', 'stay_id' => 404 ),
		array( 'name' => 'Typed Test Fish', 'qty' => 50, 'unit_price' => '1' ),
	)
);
$notes = ( new Precheck( $store, $shop, $tanks, $settings ) )->line_notes( building(), $lines );
check( 'a line that takes from shop stock, with less in stock than on the invoice: a warning with both numbers', isset( $notes[0] ) && false !== strpos( $notes[0][0], 'Only 2 in shop stock, 3 on this invoice' ) );
check( 'the same line unticked: nothing — shop stock is not touched', ! isset( $notes[1] ) );
check( 'enough in stock: nothing', ! isset( $notes[2] ) );
check( 'ticked, but the product does not track stock: said so', isset( $notes[3] ) && false !== strpos( $notes[3][0], 'does not track stock' ) );
check( 'the shop product is gone: said so', isset( $notes[4] ) && false !== strpos( $notes[4][0], 'no longer exists' ) );
check( 'more fish than are alive in the tank group', isset( $notes[5] ) && false !== strpos( $notes[5][0], 'Only 3 alive in this tank group, 4 on this invoice' ) );
check( 'enough alive, but not once the other open invoices are counted', isset( $notes[6] ) && false !== strpos( $notes[6][0], '3 alive in this tank group, 2 already on other open invoices, 2 on this one' ) );
check( 'enough for everyone: nothing', ! isset( $notes[7] ) );
check( 'a tank group with nothing alive, and one that no longer exists', isset( $notes[8] ) && false !== strpos( $notes[8][0], 'Nothing is alive' ) && isset( $notes[9] ) && false !== strpos( $notes[9][0], 'no longer exists' ) );
check( 'a typed fish: nothing to know', ! isset( $notes[10] ) );
$tanks->on = false;
$notes     = ( new Precheck( $store, $shop, $tanks, $settings ) )->line_notes( building(), $lines );
check( 'the Clean Room switched off: the tank lines are simply not judged', ! isset( $notes[5] ) && ! isset( $notes[8] ) && isset( $notes[0] ) );

echo "7. Facts of one invoice, read once\n";
$world     = fh_wc_world();
$store     = new Fake_Store();
$publisher = new Publisher( $store, $shop, new Fake_Tanks(), $settings );
$check     = $publisher->precheck( array( 'handle' => 'Tester', 'lines' => array( array( 'kind' => 'fish', 'name' => 'A Test Fish', 'qty' => 1, 'unit_price' => '5' ) ) ), USER, TODAY );
$a         = $publisher->publish( $check['id'], $check['token'], array(), USER, TODAY );
$check     = $publisher->precheck( array( 'handle' => 'Second', 'lines' => array( array( 'kind' => 'fish', 'name' => 'B Test Fish', 'qty' => 1, 'unit_price' => '5' ) ) ), USER, TODAY );
$b         = $publisher->publish( $check['id'], $check['token'], array(), USER, TODAY );
fh_wc_order( array( 'status' => 'completed', 'date_paid' => true ), array( array( 'name' => 'A Test Fish', 'qty' => 1, 'total' => 5, 'meta' => array( Invoices::ITEM_PRODUCT_META => $a['product_id'], Invoices::ITEM_FISH_META => 1 ) ) ) );

$pre          = new Precheck( $store, $shop, new Fake_Tanks(), $settings );
$store->reads = 0;
$pre->prime( array( $a['product_id'], $b['product_id'] ) );
check_same( 'prime(): one read of the order lines for a whole page of products', 1, $store->reads );
$facts_a = $pre->facts( $store->invoice( $a['id'] ) );
$facts_b = $pre->facts( $store->invoice( $b['id'] ) );
check( 'facts(): the product, its orders and what follows', 'paid' === $facts_a['effective']['state'] && 1 === count( $facts_a['orders'] ) && $a['product_id'] === $facts_a['product']['id'] && 'published' === $facts_b['effective']['state'] && array() === $facts_b['orders'] && $facts_b['effective']['can_trash'] );
check( 'a draft has no product and no orders to read', null === $pre->facts( array( 'id' => 9, 'state' => 'draft', 'product_id' => null, 'order_id' => null ) )['product'] );
check( 'is_ours(): a product with the mark of an invoice that exists', $pre->is_ours( $shop->product( $a['product_id'] ) ) && ! $pre->is_ours( array( 'marker' => 0 ) ) && ! $pre->is_ours( array( 'marker' => 987654 ) ) );

fh_finish();
