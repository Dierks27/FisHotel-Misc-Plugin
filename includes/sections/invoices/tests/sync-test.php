<?php
/**
 * Paid sync — an order that contains an invoice becomes paid: the
 * invoice is marked paid with that order and `fishotel_invoice_paid`
 * fires, once; the order is cancelled or fails: the invoice is released
 * and `fishotel_invoice_released` fires. Both shapes of "contains" (the
 * invoice kept as one line; the lines the theme made out of it), a
 * fulfilment copy ignored, a pending or on-hold order costing no query,
 * a refund releasing nothing, a second paid order not taking an invoice
 * away from the first, and reconcile() — the same bookkeeping when a
 * screen finds the stored state behind the truth.
 *
 * Only this section's own table is written: no product, no stock, no
 * order.
 *
 * Run from the plugin root:  php includes/sections/invoices/tests/sync-test.php
 */

require_once __DIR__ . '/wp-stubs.php';

use FisHotel\Misc\Sections\Invoices\Invoices;
use FisHotel\Misc\Sections\Invoices\Precheck;
use FisHotel\Misc\Sections\Invoices\Publisher;
use FisHotel\Misc\Sections\Invoices\Settings;
use FisHotel\Misc\Sections\Invoices\Shop;
use FisHotel\Misc\Sections\Invoices\Store;
use FisHotel\Misc\Sections\Invoices\Sync;
use FisHotel\Misc\Sections\Invoices\Tests\Fake_Store;
use FisHotel\Misc\Sections\Invoices\Tests\Fake_Tanks;

const USER  = 8;
const TODAY = '2026-11-03';

/**
 * A fresh shop with published, unpaid invoices of the given handles.
 *
 * @param string[] $handles Handles.
 * @return array{0: Fake_Store, 1: Shop, 2: array[]} Store, shop, and per handle its id and product_id.
 */
function world_with( array $handles ) {
	fh_wc_world();
	$store     = new Fake_Store();
	$shop      = new Shop();
	$publisher = new Publisher( $store, $shop, new Fake_Tanks(), Settings::defaults() );
	$made      = array();
	foreach ( $handles as $handle ) {
		$check           = $publisher->precheck( array( 'handle' => $handle, 'lines' => array( array( 'kind' => 'fish', 'name' => $handle . ' Test Fish', 'qty' => 1, 'unit_price' => '50' ) ) ), USER, TODAY );
		$done            = $publisher->publish( $check['id'], $check['token'], array(), USER, TODAY );
		$made[ $handle ] = array(
			'id'         => (int) $done['id'],
			'product_id' => (int) $done['product_id'],
		);
	}
	$GLOBALS['fh_wc']['writes'] = array();
	$GLOBALS['fh_fired']        = array();
	$store->reads               = 0;
	$store->writes              = 0;
	return array( $store, $shop, $made );
}

function whole_line( $product_id, $title = 'Tester Invoice (11/3/26)' ) {
	return array( 'name' => $title, 'qty' => 1, 'total' => 50, 'product_id' => $product_id );
}

function fish_line( $product_id, $name = 'Tester Test Fish' ) {
	return array( 'name' => $name, 'qty' => 1, 'total' => 50, 'meta' => array( Invoices::ITEM_FISH_META => 1, Invoices::ITEM_PRODUCT_META => $product_id, Invoices::ITEM_TITLE_META => 'Tester Invoice (11/3/26)' ) );
}

echo "1. An order that contains the invoice becomes paid\n";
list( $store, $shop, $made ) = world_with( array( 'Tester' ) );
$id    = $made['Tester']['id'];
$pid   = $made['Tester']['product_id'];
$order = fh_wc_order( array( 'status' => 'pending' ), array( whole_line( $pid ), array( 'name' => 'Test Fish Food', 'qty' => 1, 'total' => 9, 'product_id' => 3001 ) ) );

$r     = Sync::apply( $store, $shop, $shop->order_brief( $order ) );
$asked = array( $store->reads, $store->writes );
check( 'pending payment: nothing recorded, and the store was not even asked', array() === $r['paid'] && array() === $r['released'] && array( 0, 0 ) === $asked && 'published' === $store->invoice( $id )['state'] );
fh_wc_order_set( $order, array( 'status' => 'on-hold' ) );
$store->reads = 0;
$r            = Sync::apply( $store, $shop, $shop->order_brief( $order ) );
check( 'on hold (awaiting payment): the same — not a query', array() === $r['paid'] && 0 === $store->reads && 0 === $store->writes );

fh_wc_order_set( $order, array( 'status' => 'processing', 'date_paid' => true ) );
$store->reads = 0;
$r            = Sync::apply( $store, $shop, $shop->order_brief( $order ) );
$asked        = array( $store->reads, $store->writes );
$invoice      = $store->invoice( $id );
check( 'paid: the invoice is marked paid with the order', array( $id ) === $r['paid'] && 'paid' === $invoice['state'] && $order === $invoice['order_id'] && null !== $invoice['paid_at'] );
check_same( 'the action fired once, with the invoice id and the order id', 1, fh_fired( Invoices::PAID_ACTION, array( $id, $order ) ) );
check_same( 'one read of this section\'s table, one write', array( 1, 1 ), $asked );
check( 'nothing in the shop was written: no product, no stock, no order', array() === $GLOBALS['fh_wc']['writes'] && 1 === $shop->product( $pid )['stock_quantity'] && 0 === $shop->product( $pid )['lock_order_id'] );

$r = Sync::apply( $store, $shop, $shop->order_brief( $order ) );
fh_wc_order_set( $order, array( 'status' => 'completed' ) );
$r2 = Sync::apply( $store, $shop, $shop->order_brief( $order ) );
check( 'the same order again, and again when it is completed: nothing more happens', array() === $r['paid'] && array() === $r2['paid'] && 1 === fh_fired( Invoices::PAID_ACTION ) );

echo "2. The lines the theme made out of an invoice count as containing it\n";
list( $store, $shop, $made ) = world_with( array( 'Tester' ) );
$id    = $made['Tester']['id'];
$pid   = $made['Tester']['product_id'];
$order = fh_wc_order( array( 'status' => 'processing', 'date_paid' => true ), array( fish_line( $pid ), array( 'name' => 'Credit: lost Test Wrasse', 'total' => -5, 'type' => 'fee', 'meta' => array( Invoices::ITEM_CREDIT_META => 1, Invoices::ITEM_PRODUCT_META => $pid ) ) ) );
$r     = Sync::apply( $store, $shop, $shop->order_brief( $order ) );
check( 'no line of the order is the invoice product itself — its `_fh_invoice_product_id` item meta is enough', array( $id ) === $r['paid'] && $order === $store->invoice( $id )['order_id'] && 1 === fh_fired( Invoices::PAID_ACTION, array( $id, $order ) ) );

list( $store, $shop, $made ) = world_with( array( 'Tester' ) );
$order = fh_wc_order( array( 'status' => 'processing', 'date_paid' => true ), array( array( 'name' => 'Credit: lost Test Wrasse', 'total' => -5, 'type' => 'fee', 'meta' => array( Invoices::ITEM_PRODUCT_META => $made['Tester']['product_id'] ) ) ) );
check( 'even a fee line alone carries it', array( $made['Tester']['id'] ) === Sync::apply( $store, $shop, $shop->order_brief( $order ) )['paid'] );

echo "3. Paid is the paid date or is_paid(), never the status name\n";
list( $store, $shop, $made ) = world_with( array( 'Tester' ) );
$id    = $made['Tester']['id'];
$pid   = $made['Tester']['product_id'];
$order = fh_wc_order( array( 'status' => 'shipped', 'date_paid' => true ), array( whole_line( $pid ) ) );
check( 'a status of the site\'s own with a paid date: paid', array( $id ) === Sync::apply( $store, $shop, $shop->order_brief( $order ) )['paid'] );

list( $store, $shop, $made ) = world_with( array( 'Tester' ) );
$order = fh_wc_order( array( 'status' => 'shipped', 'date_paid' => 0 ), array( whole_line( $made['Tester']['product_id'] ) ) );
check( 'the same status without a paid date: not paid', array() === Sync::apply( $store, $shop, $shop->order_brief( $order ) )['paid'] && 'published' === $store->invoice( $made['Tester']['id'] )['state'] );

list( $store, $shop, $made ) = world_with( array( 'Tester' ) );
$order = fh_wc_order( array( 'status' => 'processing', 'date_paid' => 0 ), array( whole_line( $made['Tester']['product_id'] ) ) );
check( 'Processing without a paid date (cash on delivery): WooCommerce calls it paid, so it is', array( $made['Tester']['id'] ) === Sync::apply( $store, $shop, $shop->order_brief( $order ) )['paid'] );

echo "4. Cancelled or failed: released\n";
foreach ( array( 'cancelled', 'failed' ) as $status ) {
	list( $store, $shop, $made ) = world_with( array( 'Tester' ) );
	$id    = $made['Tester']['id'];
	$pid   = $made['Tester']['product_id'];
	$order = fh_wc_order( array( 'status' => 'processing', 'date_paid' => true ), array( fish_line( $pid ) ) );
	Sync::apply( $store, $shop, $shop->order_brief( $order ) );
	fh_wc_order_set( $order, array( 'status' => $status ) );
	$r       = Sync::apply( $store, $shop, $shop->order_brief( $order ) );
	$invoice = $store->invoice( $id );
	check( "the order is $status: the invoice is published again, with no order and no paid date", array( $id ) === $r['released'] && 'published' === $invoice['state'] && null === $invoice['order_id'] && null === $invoice['paid_at'] );
	check( '…and the release action fired once', 1 === fh_fired( Invoices::RELEASED_ACTION, array( $id, $order ) ) && 1 === fh_fired( Invoices::PAID_ACTION ) );
	$r = Sync::apply( $store, $shop, $shop->order_brief( $order ) );
	check( '…a second time nothing happens', array() === $r['released'] && 1 === fh_fired( Invoices::RELEASED_ACTION ) );
	fh_wc_order_set( $order, array( 'status' => 'processing' ) );
	$r = Sync::apply( $store, $shop, $shop->order_brief( $order ) );
	check( '…and when the order is paid after all, the invoice is paid again', array( $id ) === $r['paid'] && 'paid' === $store->invoice( $id )['state'] && 2 === fh_fired( Invoices::PAID_ACTION ) );
}

list( $store, $shop, $made ) = world_with( array( 'Tester' ) );
$id    = $made['Tester']['id'];
$order = fh_wc_order( array( 'status' => 'processing', 'date_paid' => true ), array( fish_line( $made['Tester']['product_id'] ) ) );
Sync::apply( $store, $shop, $shop->order_brief( $order ) );
fh_wc_order_set( $order, array( 'status' => 'refunded' ) );
$r = Sync::apply( $store, $shop, $shop->order_brief( $order ) );
check( 'a refund releases nothing: the invoice stays paid and locked', array() === $r['released'] && 'paid' === $store->invoice( $id )['state'] && 0 === fh_fired( Invoices::RELEASED_ACTION ) );

list( $store, $shop, $made ) = world_with( array( 'Tester' ) );
$id    = $made['Tester']['id'];
$order = fh_wc_order( array( 'status' => 'cancelled' ), array( whole_line( $made['Tester']['product_id'] ) ) );
$r     = Sync::apply( $store, $shop, $shop->order_brief( $order ) );
check( 'an order cancelled before it was ever paid: nothing to release', array() === $r['released'] && 'published' === $store->invoice( $id )['state'] && 0 === $store->writes );

list( $store, $shop, $made ) = world_with( array( 'Tester' ) );
$id    = $made['Tester']['id'];
$pid   = $made['Tester']['product_id'];
$order = fh_wc_order( array( 'status' => 'processing', 'date_paid' => true ), array( fish_line( $pid ) ) );
Sync::apply( $store, $shop, $shop->order_brief( $order ) );
$GLOBALS['fh_wc']['products'][ $pid ]['data']['status'] = 'trash';
fh_wc_order_set( $order, array( 'status' => 'cancelled' ) );
Sync::apply( $store, $shop, $shop->order_brief( $order ) );
check_same( 'released while its product sits in the Trash: "trashed", not "published"', 'trashed', $store->invoice( $id )['state'] );

echo "5. Two orders, two invoices\n";
list( $store, $shop, $made ) = world_with( array( 'Tester', 'Second' ) );
$order = fh_wc_order( array( 'status' => 'completed', 'date_paid' => true ), array( fish_line( $made['Tester']['product_id'] ), whole_line( $made['Second']['product_id'], 'Second Invoice (11/3/26)' ), array( 'name' => 'Test Net', 'qty' => 1, 'total' => 4, 'product_id' => 3002 ) ) );
$r     = Sync::apply( $store, $shop, $shop->order_brief( $order ) );
check( 'one order with two invoices pays both; the action fires for each', array( $made['Tester']['id'], $made['Second']['id'] ) === $r['paid'] && 1 === fh_fired( Invoices::PAID_ACTION, array( $made['Tester']['id'], $order ) ) && 1 === fh_fired( Invoices::PAID_ACTION, array( $made['Second']['id'], $order ) ) );

$second = fh_wc_order( array( 'status' => 'completed', 'date_paid' => true ), array( whole_line( $made['Tester']['product_id'] ) ) );
$r      = Sync::apply( $store, $shop, $shop->order_brief( $second ) );
check( 'a second paid order with an invoice that is already paid does not take it away: the first order keeps it', array() === $r['paid'] && $order === $store->invoice( $made['Tester']['id'] )['order_id'] && 2 === fh_fired( Invoices::PAID_ACTION ) );
check( '…and a warning goes to the Health log', 1 === count( array_filter( $GLOBALS['fh_fired'], static function ( $e ) { return 'fishotel_log' === $e[0] && 'warning' === $e[1][0] && 'invoices' === $e[1][1]; } ) ) );
fh_wc_order_set( $order, array( 'status' => 'cancelled' ) );
$r = Sync::apply( $store, $shop, $shop->order_brief( $second ) );
check( 'unless the first order no longer stands: then the second one pays it', array( $made['Tester']['id'] ) === $r['paid'] && $second === $store->invoice( $made['Tester']['id'] )['order_id'] );
$r = Sync::apply( $store, $shop, $shop->order_brief( $order ) );
check( 'the cancelled first order then releases only what it still holds', array( $made['Second']['id'] ) === $r['released'] && 'paid' === $store->invoice( $made['Tester']['id'] )['state'] && 'published' === $store->invoice( $made['Second']['id'] )['state'] );

echo "6. What is ignored\n";
list( $store, $shop, $made ) = world_with( array( 'Tester' ) );
$id     = $made['Tester']['id'];
$pid    = $made['Tester']['product_id'];
$mirror = fh_wc_order( array( 'status' => 'completed', 'date_paid' => true ), array( fish_line( $pid ) ), array( Invoices::MIRROR_META => '1' ) );
$r      = Sync::apply( $store, $shop, $shop->order_brief( $mirror ) );
$asked  = $store->reads;
check( 'a fulfilment (combined-box) copy of other orders pays for nothing — and costs no query', array() === $r['paid'] && 0 === $asked && 'published' === $store->invoice( $id )['state'] );
$plain        = fh_wc_order( array( 'status' => 'completed', 'date_paid' => true ), array( array( 'name' => 'Test Net', 'qty' => 1, 'total' => 4, 'product_id' => 3002 ) ) );
$store->reads = 0;
$r            = Sync::apply( $store, $shop, $shop->order_brief( $plain ) );
check( 'an ordinary paid order: one indexed read, nothing found, nothing written', array() === $r['paid'] && 1 === $store->reads && 0 === $store->writes );
$empty        = fh_wc_order( array( 'status' => 'completed', 'date_paid' => true ), array() );
$store->reads = 0;
check( 'an order without lines: nothing asked', array() === Sync::apply( $store, $shop, $shop->order_brief( $empty ) )['paid'] && 0 === $store->reads );
check( 'something that is not an order (a refund, a missing id) gives no brief', null === $shop->order_brief( 987654 ) );
$GLOBALS['fh_wc']['refunds'][555] = true;
check( '…a refund is not an order', null === $shop->order_brief( 555 ) && null === $shop->order( 555 ) );

$publisher = new Publisher( $store, $shop, new Fake_Tanks(), Settings::defaults() );
$draft     = $publisher->save_draft( array( 'handle' => 'Drafty', 'lines' => array( array( 'kind' => 'fish', 'name' => 'Draft Test Fish', 'qty' => 1, 'unit_price' => '5' ) ) ), USER );
$store->invoices[ $draft['id'] ]['product_id'] = 4242; // A draft that somehow points at a product.
$order = fh_wc_order( array( 'status' => 'completed', 'date_paid' => true ), array( whole_line( 4242 ) ) );
check( 'a draft is never marked paid', array() === Sync::apply( $store, $shop, $shop->order_brief( $order ) )['paid'] && 'draft' === $store->invoice( $draft['id'] )['state'] );

echo "7. The hooks\n";
$GLOBALS['fh_actions'] = array();
Sync::init();
check_same( 'init() listens to the status change and to payment complete, nothing else', array( 'woocommerce_order_status_changed', 'woocommerce_payment_complete' ), array_keys( $GLOBALS['fh_actions'] ) );
// on_order() uses the real Store: before the tables exist it must do nothing at all.
$GLOBALS['fh_options'] = array();
$order                 = fh_wc_order( array( 'status' => 'completed', 'date_paid' => true ), array( whole_line( $pid ) ) );
do_action( 'woocommerce_order_status_changed', $order, 'pending', 'completed' );
do_action( 'woocommerce_payment_complete', $order );
check( 'before the tables exist the hooks do nothing and raise nothing', ! Store::ready() && 0 === fh_fired( Invoices::PAID_ACTION ) );

echo "8. reconcile(): a screen finds the stored state behind the truth\n";
list( $store, $shop, $made ) = world_with( array( 'Tester' ) );
$id    = $made['Tester']['id'];
$pid   = $made['Tester']['product_id'];
$check = new Precheck( $store, $shop, new Fake_Tanks(), Settings::defaults() );
$same  = Sync::reconcile( $store, $shop, $store->invoice( $id ), $check->facts( $store->invoice( $id ) )['effective'] );
check( 'nothing changed: nothing written', 0 === $store->writes && 'published' === $same['state'] );

// Paid while the section was switched off: no hook ran.
$order = fh_wc_order( array( 'status' => 'completed', 'date_paid' => true ), array( fish_line( $pid ) ) );
$check = new Precheck( $store, $shop, new Fake_Tanks(), Settings::defaults() );
$fresh = Sync::reconcile( $store, $shop, $store->invoice( $id ), $check->facts( $store->invoice( $id ) )['effective'] );
check( 'paid while nobody was listening: marked paid now, and the action fires now', 'paid' === $fresh['state'] && $order === $fresh['order_id'] && 1 === fh_fired( Invoices::PAID_ACTION, array( $id, $order ) ) );
$check = new Precheck( $store, $shop, new Fake_Tanks(), Settings::defaults() );
Sync::reconcile( $store, $shop, $store->invoice( $id ), $check->facts( $store->invoice( $id ) )['effective'] );
check_same( 'looked at again: the action does not fire twice', 1, fh_fired( Invoices::PAID_ACTION ) );

fh_wc_order_set( $order, array( 'status' => 'cancelled' ) );
$check = new Precheck( $store, $shop, new Fake_Tanks(), Settings::defaults() );
$fresh = Sync::reconcile( $store, $shop, $store->invoice( $id ), $check->facts( $store->invoice( $id ) )['effective'] );
check( 'its order cancelled while nobody was listening: released now', 'published' === $fresh['state'] && null === $fresh['order_id'] && 1 === fh_fired( Invoices::RELEASED_ACTION, array( $id, $order ) ) );

$GLOBALS['fh_wc']['products'][ $pid ]['data']['status'] = 'trash';
$check = new Precheck( $store, $shop, new Fake_Tanks(), Settings::defaults() );
$fresh = Sync::reconcile( $store, $shop, $store->invoice( $id ), $check->facts( $store->invoice( $id ) )['effective'] );
check_same( 'its product put in the Trash by hand: "trashed"', 'trashed', $fresh['state'] );
unset( $GLOBALS['fh_wc']['products'][ $pid ] );
$store->invoices[ $id ]['state'] = 'published';
$check = new Precheck( $store, $shop, new Fake_Tanks(), Settings::defaults() );
$fresh = Sync::reconcile( $store, $shop, $store->invoice( $id ), $check->facts( $store->invoice( $id ) )['effective'] );
check_same( 'its product deleted by hand: "withdrawn"', 'cancelled', $fresh['state'] );
check( 'none of this wrote to the shop', array() === $GLOBALS['fh_wc']['writes'] );

fh_finish();
