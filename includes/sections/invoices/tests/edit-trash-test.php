<?php
/**
 * Edit and Trash — the lock rules on a real (in-memory) shop.
 *
 * "Edit this unpaid invoice": only a published invoice of this section
 * that is unpaid and in no live order; what the edit writes (the
 * description, the price, the shipping class, the lines — never the
 * title, the link, the stock or a sale price); the re-check at save — a
 * payment that arrives between the pre-check and the save, and one that
 * arrives between the save's own check and its write, both refuse it.
 * A paid invoice is locked for good, whichever way it is known to be
 * paid. A product this section did not create is never changed.
 *
 * The Trash: offered and done only when not one order contains the
 * product; the product goes to the Trash and is never deleted.
 *
 * Run from the plugin root:  php includes/sections/invoices/tests/edit-trash-test.php
 */

require_once __DIR__ . '/wp-stubs.php';

use FisHotel\Misc\Sections\Invoices\Invoices;
use FisHotel\Misc\Sections\Invoices\Publisher;
use FisHotel\Misc\Sections\Invoices\Settings;
use FisHotel\Misc\Sections\Invoices\Shop;
use FisHotel\Misc\Sections\Invoices\Tests\Fake_Store;
use FisHotel\Misc\Sections\Invoices\Tests\Fake_Tanks;

const USER  = 8;
const TODAY = '2026-11-03';
const LATER = '2026-11-20';

function form( array $more = array() ) {
	return array_merge(
		array(
			'handle'         => 'Tester',
			'customer_name'  => 'Test Person',
			'customer_email' => 'person@example.test',
			'shipping'       => 'premium',
			'flat_on'        => false,
			'lines'          => array(
				array( 'kind' => 'fish', 'name' => 'Blue Test Tang', 'qty' => 2, 'unit_price' => '45' ),
				array( 'kind' => 'fish', 'name' => 'Red Test Goby', 'qty' => 1, 'unit_price' => '12.50' ),
			),
		),
		$more
	);
}

/**
 * A fresh shop with one published, unpaid invoice.
 *
 * @return array{0: Publisher, 1: Fake_Store, 2: Shop, 3: array, 4: int, 5: int} Publisher, store, shop, world, invoice id, product id.
 */
function published() {
	$world     = fh_wc_world();
	$store     = new Fake_Store();
	$shop      = new Shop();
	$settings  = array_merge( Settings::defaults(), array( 'image_under' => $world['img_small'], 'image_over' => $world['img_big'] ) );
	$publisher = new Publisher( $store, $shop, new Fake_Tanks(), $settings );
	$check     = $publisher->precheck( form(), USER, TODAY );
	$done      = $publisher->publish( $check['id'], $check['token'], array(), USER, TODAY );
	$GLOBALS['fh_wc']['writes'] = array();
	return array( $publisher, $store, $shop, $world, (int) $done['id'], (int) $done['product_id'] );
}

/**
 * The form of the edit: one fish more, a flat total, free shipping.
 *
 * @param Fake_Store $store Store.
 * @param int        $id    Invoice id.
 * @return array
 */
function edited( $store, $id ) {
	$lines   = $store->lines( $id );
	$form    = form(
		array(
			'id'         => $id,
			'version'    => $store->invoice( $id )['version'],
			'mode'       => 'update',
			'handle'     => 'Someone Else',
			'shipping'   => 'free',
			'flat_on'    => true,
			'flat_total' => '120',
		)
	);
	$form['lines'] = array(
		array( 'id' => $lines[0]['id'], 'kind' => 'fish', 'name' => 'Blue Test Tang', 'qty' => 2, 'unit_price' => '45' ),
		array( 'id' => $lines[1]['id'], 'kind' => 'fish', 'name' => 'Red Test Goby', 'qty' => 1, 'unit_price' => '12.50' ),
		array( 'kind' => 'fish', 'name' => 'Green Test Blenny', 'qty' => 1, 'unit_price' => '30' ),
	);
	return $form;
}

/**
 * An order that contains the invoice product as one line.
 *
 * @param int    $product_id The invoice product.
 * @param string $status     Order status.
 * @param bool   $paid       Whether it has a paid date.
 * @return int Order id.
 */
function order_with( $product_id, $status, $paid ) {
	return fh_wc_order(
		array(
			'status'    => $status,
			'date_paid' => $paid ? true : 0,
			'total'     => 102.5,
		),
		array( array( 'name' => 'Tester Invoice (11/3/26)', 'qty' => 1, 'total' => 102.5, 'product_id' => $product_id ) )
	);
}

function snapshot( $product_id ) {
	return json_encode( $GLOBALS['fh_wc']['products'][ $product_id ] );
}

echo "1. Edit this unpaid invoice\n";
list( $publisher, $store, $shop, $world, $id, $pid ) = published();
$before = $shop->product( $pid );
$form   = edited( $store, $id );
$check  = $publisher->precheck_update( $id, $form, USER, LATER );
check( 'the pre-check of the changes: a report and a token, and nothing saved', $check['ok'] && 40 === strlen( $check['token'] ) && 'update' === $check['report']['mode'] && array() === $GLOBALS['fh_wc']['writes'] && 2 === count( $store->lines( $id ) ) );
check_same( 'the title stays the one it was published under — not today\'s date', 'Tester Invoice (11/3/26)', $check['report']['writes']['title'] );
$r = $publisher->update( $id, $form, '', array(), USER, LATER );
check( 'without the token of that pre-check: refused, nothing written', ! $r['ok'] && 'precheck' === $r['code'] && array() === $GLOBALS['fh_wc']['writes'] );
$other          = $form;
$other['lines'][2]['unit_price'] = '31';
$r              = $publisher->update( $id, $other, $check['token'], array(), USER, LATER );
check( 'with other content than was checked: refused', ! $r['ok'] && 'precheck' === $r['code'] && array() === $GLOBALS['fh_wc']['writes'] );
$r = $publisher->update( $id, $form, $check['token'], array(), USER, LATER );
check( 'with it: saved', $r['ok'] && 'updated' === $r['code'], json_encode( $r ) );

$after = $shop->product( $pid );
check_same( 'the description is the new list', "<ul>\n<li>Blue Test Tang x2 \u{2014} \$45</li>\n<li>Red Test Goby x1 \u{2014} \$12.50</li>\n<li>Green Test Blenny x1 \u{2014} \$30</li>\n<li>Discount \u{2014} -\$12.50</li>\n</ul>", $after['description'] );
check( 'the price, the shipping class and the lines for the theme are the new ones', '120.00' === $after['regular_price'] && $world['ship_free'] === $after['shipping_class_id'] && 4 === count( json_decode( $after['lines_json'], true ) ) );
check( 'the title, the link and the short description did not change — the customer has that link', $before['name'] === $after['name'] && $before['slug'] === $after['slug'] && $before['permalink'] === $after['permalink'] && $before['short_description'] === $after['short_description'] && $before['permalink'] === $r['url'] );
check( 'the stock, the visibility, the category and the status were not touched', $before['stock_quantity'] === $after['stock_quantity'] && $before['manage_stock'] === $after['manage_stock'] && $before['catalog_visibility'] === $after['catalog_visibility'] && $before['category_ids'] === $after['category_ids'] && 'publish' === $after['status'] );
$setters = array_column( $GLOBALS['fh_wc']['writes'][0][2], 0 );
check_same( 'exactly what an edit writes', array( 'set_description', 'set_regular_price', 'set_shipping_class_id', 'set_image_id', 'update_meta_data' ), $setters );
check( 'one save of that one product', 1 === count( $GLOBALS['fh_wc']['writes'] ) && $pid === $GLOBALS['fh_wc']['writes'][0][1] && 1 === count( $GLOBALS['fh_wc']['products'] ) );

$invoice = $store->invoice( $id );
$lines   = $store->lines( $id );
check( 'the record follows: lines, flat total, total, shipping — still published, still the same product', 4 === count( $lines ) && 120.0 === $invoice['flat_total'] && 120.0 === $invoice['total'] && 'free' === $invoice['shipping'] && 'free' === $invoice['shipping_slug'] && 'published' === $invoice['state'] && $pid === $invoice['product_id'] );
check( 'the handle cannot be changed by an edit: it is in the title', 'Tester' === $invoice['handle'] && 'tester' === $invoice['handle_key'] );
check( 'the lines that stayed kept their rows', $form['lines'][0]['id'] === $lines[0]['id'] && $form['lines'][1]['id'] === $lines[1]['id'] );

$r = $publisher->update( $id, $form, $check['token'], array(), USER, LATER );
check( 'the page that saved is now behind (its version is old): a second save from it is refused', ! $r['ok'] && 'conflict' === $r['code'] );

echo "2. A sale price somebody set by hand is never cleared and never changed\n";
list( $publisher, $store, $shop, $world, $id, $pid ) = published();
$GLOBALS['fh_wc']['products'][ $pid ]['data']['sale_price'] = '80';
$form  = edited( $store, $id );
$check = $publisher->precheck_update( $id, $form, USER, LATER );
check( 'the pre-check warns: the customer pays the sale price', 1 === count( preg_grep( '/sale price \(80\)/', $check['report']['warnings'] ) ) );
$r = $publisher->update( $id, $form, $check['token'], array(), USER, LATER );
check( 'the edit goes through, the sale price is exactly as it was, and the result says so', $r['ok'] && '80' === $shop->product( $pid )['sale_price'] && '120.00' === $shop->product( $pid )['regular_price'] && 1 === count( preg_grep( '/sale price/', $r['warnings'] ) ) );

echo "3. Stock or a category changed by hand is reported, not \"repaired\"\n";
list( $publisher, $store, $shop, $world, $id, $pid ) = published();
$GLOBALS['fh_wc']['products'][ $pid ]['data']['category_ids'][] = $world['cat_fish'];
$form  = edited( $store, $id );
$check = $publisher->precheck_update( $id, $form, USER, LATER );
$r     = $publisher->update( $id, $form, $check['token'], array(), USER, LATER );
check( 'a second category added by hand stays, with a warning', $r['ok'] && 2 === count( $shop->product( $pid )['category_ids'] ) && 1 === count( preg_grep( '/Invoices category alone/', $r['warnings'] ) ) );

echo "4. A PAID invoice is locked for good\n";
foreach ( array( 'processing', 'completed', 'shipped', 'refunded' ) as $status ) {
	list( $publisher, $store, $shop, $world, $id, $pid ) = published();
	$order = order_with( $pid, $status, true );
	$was   = snapshot( $pid );
	$form  = edited( $store, $id );
	$r     = $publisher->precheck_update( $id, $form, USER, LATER );
	check( "a paid order ($status) contains it: the editor does not even open", ! $r['ok'] && 'locked' === $r['code'] && false !== strpos( $r['message'], '#' . $order ) );
	$r = $publisher->update( $id, $form, Publisher::token( USER, $id, 'x', 'update' ), array(), USER, LATER );
	check( '…a save is refused and the product is untouched', ! $r['ok'] && 'locked' === $r['code'] && $was === snapshot( $pid ) && array() === $GLOBALS['fh_wc']['writes'] );
	check( '…and the invoice now knows it is paid, by that order', 'paid' === $store->invoice( $id )['state'] && $order === $store->invoice( $id )['order_id'] );
}

list( $publisher, $store, $shop, $world, $id, $pid ) = published();
$order = fh_wc_order( array( 'status' => 'processing', 'date_paid' => true ), array( array( 'name' => 'Blue Test Tang', 'qty' => 2, 'total' => 90, 'meta' => array( Invoices::ITEM_FISH_META => 1, Invoices::ITEM_PRODUCT_META => $pid, Invoices::ITEM_TITLE_META => 'Tester Invoice (11/3/26)' ) ) ) );
$GLOBALS['fh_wc']['products'][ $pid ]['meta'][ Invoices::PRODUCT_ORDER_META ] = $order;
$r = $publisher->precheck_update( $id, edited( $store, $id ), USER, LATER );
check( 'the theme turned it into fish lines and locked the product (`_fh_invoice_order_id`): locked here too', ! $r['ok'] && 'locked' === $r['code'] && 'paid' === $store->invoice( $id )['state'] );

list( $publisher, $store, $shop, $world, $id, $pid ) = published();
$order = fh_wc_order( array( 'status' => 'processing', 'date_paid' => 0 ), array( array( 'name' => 'Tester Invoice (11/3/26)', 'qty' => 1, 'total' => 102.5, 'product_id' => $pid ) ) );
$r     = $publisher->precheck_update( $id, edited( $store, $id ), USER, LATER );
check( 'a Processing order without a paid date still counts: WooCommerce itself calls it paid', ! $r['ok'] && 'paid' === $store->invoice( $id )['state'] );

echo "5. Unpaid, but the customer has checked out\n";
foreach ( array( 'pending', 'on-hold' ) as $status ) {
	list( $publisher, $store, $shop, $world, $id, $pid ) = published();
	$order = order_with( $pid, $status, false );
	$was   = snapshot( $pid );
	$r     = $publisher->precheck_update( $id, edited( $store, $id ), USER, LATER );
	check( "an unpaid order ($status) contains it: locked, although not paid", ! $r['ok'] && 'locked' === $r['code'] && false !== strpos( $r['message'], 'checked out' ) && 'published' === $store->invoice( $id )['state'] && $was === snapshot( $pid ) );
}
foreach ( array( 'cancelled', 'failed' ) as $status ) {
	list( $publisher, $store, $shop, $world, $id, $pid ) = published();
	order_with( $pid, $status, false );
	$form  = edited( $store, $id );
	$check = $publisher->precheck_update( $id, $form, USER, LATER );
	$r     = $check['ok'] ? $publisher->update( $id, $form, $check['token'], array(), USER, LATER ) : $check;
	check( "an order that was $status no longer stands in the way of an edit", $r['ok'] );
}

echo "6. The re-check at save\n";
list( $publisher, $store, $shop, $world, $id, $pid ) = published();
$form  = edited( $store, $id );
$check = $publisher->precheck_update( $id, $form, USER, LATER );
check( 'unpaid when the pre-check was shown', $check['ok'] );
$order = order_with( $pid, 'processing', true );
$was   = snapshot( $pid );
$r     = $publisher->update( $id, $form, $check['token'], array(), USER, LATER );
check( 'paid before Save was pressed: refused, the product untouched, the invoice marked paid', ! $r['ok'] && 'locked' === $r['code'] && $was === snapshot( $pid ) && array() === $GLOBALS['fh_wc']['writes'] && 'paid' === $store->invoice( $id )['state'] && $order === $store->invoice( $id )['order_id'] );
check( 'the message says nothing was changed and where extra fish go', false !== strpos( $r['message'], 'Nothing was changed' ) && false !== strpos( $r['message'], 'new invoice' ) );

list( $publisher, $store, $shop, $world, $id, $pid ) = published();
$form  = edited( $store, $id );
$check = $publisher->precheck_update( $id, $form, USER, LATER );
// The payment arrives in the instant between the save's own check and its write.
$store->after_save = static function () use ( $pid, $store ) {
	$store->after_save = null;
	order_with( $pid, 'processing', true );
};
$was               = snapshot( $pid );
$r                 = $publisher->update( $id, $form, $check['token'], array(), USER, LATER );
check( 'paid between the save\'s check and its write: checked once more right before the product is touched — refused', ! $r['ok'] && 'locked' === $r['code'] && $was === snapshot( $pid ) && array() === $GLOBALS['fh_wc']['writes'] && 2 === count( $store->lines( $id ) ) );

echo "7. What cannot be checked is not changed\n";
list( $publisher, $store, $shop, $world, $id, $pid ) = published();
$store->orders_unreadable = true;
$was                      = snapshot( $pid );
$r                        = $publisher->precheck_update( $id, edited( $store, $id ), USER, LATER );
check( 'the orders cannot be read: no edit', ! $r['ok'] && 'locked' === $r['code'] && false !== strpos( $r['message'], 'could not be read' ) );
$r = $publisher->trash( $id, USER );
check( '…and no Trash', ! $r['ok'] && $was === snapshot( $pid ) && 'published' === $store->invoice( $id )['state'] );

echo "8. A product this section did not create is never changed\n";
list( $publisher, $store, $shop, $world, $id, $pid ) = published();
$hand = fh_wc_product( array( 'name' => 'Tester Invoice (10/1/26)', 'description' => '<ul><li>Old Test Fish x1 — $45</li></ul>', 'regular_price' => '45', 'category_ids' => array( $world['cat_invoices'] ), 'manage_stock' => true, 'stock_quantity' => 1 ) );
// A row that points at the hand-made product, as if someone had tampered with the table.
$store->invoices[ $id ]['product_id'] = $hand;
$was                                  = snapshot( $hand );
$r                                    = $publisher->precheck_update( $id, edited( $store, $id ), USER, LATER );
check( 'an invoice row pointing at a hand-made product: no edit', ! $r['ok'] && 'locked' === $r['code'] && false !== strpos( $r['message'], 'not created by this section' ) );
$r = $publisher->trash( $id, USER );
check( '…no Trash, and the product is exactly as it was', ! $r['ok'] && $was === snapshot( $hand ) );
$direct = $shop->update_invoice_product( $hand, array( 'description' => 'x', 'regular_price' => '1.00', 'shipping_class_id' => 0, 'image_id' => 0, 'lines_json' => '[]', 'marker' => $id ) );
check( 'the Shop itself refuses to update a product without this invoice\'s mark', ! $direct['ok'] && $was === snapshot( $hand ) );
check( '…to adopt it, to take it offline, or to trash it', ! $shop->adopt_invoice_product( $hand, array( 'name' => 'x', 'short_description' => '', 'description' => '', 'regular_price' => '1', 'category_id' => $world['cat_invoices'], 'shipping_class_id' => 0, 'image_id' => 0, 'lines_json' => '[]', 'marker' => $id ) )['ok'] && ! $shop->unpublish_product( $hand, $id ) && ! $shop->trash_product( $hand, $id )['ok'] && $was === snapshot( $hand ) );
$marked = fh_wc_product( array( 'name' => 'Other Invoice (10/2/26)', 'category_ids' => array( $world['cat_invoices'] ) ), array( Invoices::PRODUCT_MARKER_META => $id + 50 ) );
check( 'the mark of ANOTHER invoice does not open a product either', ! $shop->update_invoice_product( $marked, array( 'description' => 'x', 'regular_price' => '1.00', 'shipping_class_id' => 0, 'image_id' => 0, 'lines_json' => '[]', 'marker' => $id ) )['ok'] && ! $shop->trash_product( $marked, $id )['ok'] );

echo "9. Only a published invoice is edited this way\n";
list( $publisher, $store, $shop, $world, $id, $pid ) = published();
$draft = $publisher->save_draft( form( array( 'handle' => 'Second' ) ), USER );
$r     = $publisher->precheck_update( $draft['id'], form( array( 'handle' => 'Second' ) ), USER, LATER );
check( 'a draft: refused (a draft is simply saved)', ! $r['ok'] );
$r = $publisher->precheck_update( 999, form(), USER, LATER );
check( 'an invoice that does not exist: refused', ! $r['ok'] && 'missing' === $r['code'] );
$GLOBALS['fh_wc']['products'][ $pid ]['data']['status'] = 'draft';
$r = $publisher->precheck_update( $id, edited( $store, $id ), USER, LATER );
check( 'its product unpublished by hand: refused', ! $r['ok'] && 'locked' === $r['code'] );
unset( $GLOBALS['fh_wc']['products'][ $pid ] );
$r = $publisher->precheck_update( $id, edited( $store, $id ), USER, LATER );
check( 'its product deleted by hand: refused, and the invoice is "withdrawn"', ! $r['ok'] && 'cancelled' === $store->invoice( $id )['state'] );

echo "10. Duplicates are asked about on an edit too\n";
list( $publisher, $store, $shop, $world, $id, $pid ) = published();
fh_wc_product( array( 'name' => 'Tester Invoice (10/1/26)', 'description' => '<ul><li>Green Test Blenny x1 — $30</li></ul>', 'regular_price' => '30', 'category_ids' => array( $world['cat_invoices'] ) ) );
$form  = edited( $store, $id );
$check = $publisher->precheck_update( $id, $form, USER, LATER );
check( 'the fish added by the edit is already on a hand-made invoice: flagged; the invoice\'s own product is not compared with itself', 1 === count( $check['report']['dup_keys'] ) && 2 === $check['report']['duplicates'][0]['index'] );
$r = $publisher->update( $id, $form, $check['token'], array(), USER, LATER );
check( 'not confirmed: refused, nothing written', ! $r['ok'] && 'confirm' === $r['code'] && array() === $GLOBALS['fh_wc']['writes'] );
$r = $publisher->update( $id, $form, $check['token'], $check['report']['dup_keys'], USER, LATER );
check( 'confirmed: saved', $r['ok'] );

echo "11. The Trash: only when not one order contains the product\n";
list( $publisher, $store, $shop, $world, $id, $pid ) = published();
$r = $publisher->trash( $id, USER );
check( 'no order contains it: moved to the Trash', $r['ok'] && 'trashed' === $r['code'] );
check( 'the product still exists, with the status "trash" — it was not deleted', isset( $GLOBALS['fh_wc']['products'][ $pid ] ) && 'trash' === $shop->product( $pid )['status'] && array( array( 'trash', $pid, array() ) ) === $GLOBALS['fh_wc']['writes'] );
check( 'the invoice is "trashed"; its lines are kept', 'trashed' === $store->invoice( $id )['state'] && 2 === count( $store->lines( $id ) ) );
$r = $publisher->trash( $id, USER );
check( 'a second time: refused', ! $r['ok'] );
$r = $publisher->precheck_update( $id, edited( $store, $id ), USER, LATER );
check( 'a trashed invoice cannot be edited', ! $r['ok'] && 'locked' === $r['code'] );

foreach ( array( 'pending' => false, 'on-hold' => false, 'processing' => true, 'completed' => true, 'refunded' => true, 'cancelled' => false, 'failed' => false, 'trash' => false ) as $status => $paid ) {
	list( $publisher, $store, $shop, $world, $id, $pid ) = published();
	$order = order_with( $pid, $status, $paid );
	$was   = snapshot( $pid );
	$r     = $publisher->trash( $id, USER );
	check( "an order with the status \"$status\" contains it: not trashed, the product untouched", ! $r['ok'] && 'locked' === $r['code'] && $was === snapshot( $pid ) && array() === $GLOBALS['fh_wc']['writes'] && 'publish' === $shop->product( $pid )['status'] );
}

list( $publisher, $store, $shop, $world, $id, $pid ) = published();
fh_wc_order( array( 'status' => 'cancelled' ), array( array( 'name' => 'Blue Test Tang', 'qty' => 2, 'total' => 90, 'meta' => array( Invoices::ITEM_FISH_META => 1, Invoices::ITEM_PRODUCT_META => $pid ) ) ) );
$r = $publisher->trash( $id, USER );
check( 'an order whose lines the theme made out of it counts as containing it', ! $r['ok'] && false !== strpos( $r['message'], 'An order contains this invoice' ) );

list( $publisher, $store, $shop, $world, $id, $pid ) = published();
$GLOBALS['fh_wc']['products'][ $pid ]['meta'][ Invoices::PRODUCT_ORDER_META ] = 4711;
$r = $publisher->trash( $id, USER );
check( 'the theme\'s lock names an order that no longer exists: still not trashed', ! $r['ok'] && 'publish' === $shop->product( $pid )['status'] );

list( $publisher, $store, $shop, $world, $id, $pid ) = published();
$draft = $publisher->save_draft( form( array( 'handle' => 'Second' ) ), USER );
check( 'a draft has nothing to move to the Trash', ! $publisher->trash( $draft['id'], USER )['ok'] && ! $publisher->trash( 999, USER )['ok'] );
$store->fail_saves = 1;
$r                 = $publisher->trash( $id, USER );
check( 'the product is in the Trash but the record could not follow: said so — and the next look at it catches up', ! $r['ok'] && 'db' === $r['code'] && 'trash' === $shop->product( $pid )['status'] );
$publisher->trash( $id, USER );
check_same( '…caught up', 'trashed', $store->invoice( $id )['state'] );

echo "12. Deleting is something this section cannot do\n";
$deletes = 0;
foreach ( glob( dirname( __DIR__ ) . '/*.php' ) as $file ) {
	$tokens = token_get_all( (string) file_get_contents( $file ) );
	foreach ( $tokens as $index => $token ) {
		if ( ! is_array( $token ) || T_STRING !== $token[0] ) {
			continue;
		}
		$name = strtolower( $token[1] );
		if ( in_array( $name, array( 'wp_delete_post', 'wp_delete_object', 'wp_trash_post', 'wc_delete_product' ), true ) ) {
			$deletes++;
		}
		// ->delete( … ): the only call allowed is ->delete( false ) — to the Trash.
		if ( 'delete' === $name ) {
			$rest = '';
			for ( $i = $index + 1; $i < $index + 6 && isset( $tokens[ $i ] ); $i++ ) {
				$rest .= is_array( $tokens[ $i ] ) ? $tokens[ $i ][1] : $tokens[ $i ];
			}
			if ( 0 !== strpos( preg_replace( '/\s+/', '', $rest ), '(false)' ) ) {
				$deletes++;
			}
		}
	}
}
check_same( 'the only delete call in the section is ->delete( false ): to the Trash', 0, $deletes );

fh_finish();
