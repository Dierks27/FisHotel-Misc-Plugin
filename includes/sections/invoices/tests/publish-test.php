<?php
/**
 * Publish — a draft into the two tables and no product; the pre-check
 * that cannot be skipped (no token, no publish; the token is bound to
 * the user, the invoice and exactly the content that was checked); the
 * FULL field set of the product Publish creates, read back from the
 * shop; the title with today's site date; the image by threshold; the
 * shipping class; the fish-on-two-invoices confirmation; a product that
 * does not come out as written is taken offline again; a half-finished
 * Publish never makes a second product.
 *
 * The section's real Shop runs here on the in-memory WooCommerce of
 * wc-stubs.php; only the Store is the in-memory twin.
 *
 * Run from the plugin root:  php includes/sections/invoices/tests/publish-test.php
 */

require_once __DIR__ . '/wp-stubs.php';

use FisHotel\Misc\Sections\Invoices\Grammar;
use FisHotel\Misc\Sections\Invoices\Invoices;
use FisHotel\Misc\Sections\Invoices\Publisher;
use FisHotel\Misc\Sections\Invoices\Rules;
use FisHotel\Misc\Sections\Invoices\Settings;
use FisHotel\Misc\Sections\Invoices\Shop;
use FisHotel\Misc\Sections\Invoices\Tests\Fake_Store;
use FisHotel\Misc\Sections\Invoices\Tests\Fake_Tanks;

const USER  = 8;
const TODAY = '2026-11-03';

/**
 * A fresh shop, store and publisher.
 *
 * @param array $settings Settings over the defaults.
 * @return array{0: Publisher, 1: Fake_Store, 2: Shop, 3: array, 4: array} Publisher, store, shop, world ids, settings.
 */
function setup( array $settings = array() ) {
	$world    = fh_wc_world();
	$store    = new Fake_Store();
	$shop     = new Shop();
	$settings = array_merge(
		Settings::defaults(),
		array(
			'image_under' => $world['img_small'],
			'image_over'  => $world['img_big'],
		),
		$settings
	);
	return array( new Publisher( $store, $shop, new Fake_Tanks(), $settings ), $store, $shop, $world, $settings );
}

/**
 * The form of a small invoice.
 *
 * @param array $more Fields over it.
 * @return array
 */
function form( array $more = array() ) {
	return array_merge(
		array(
			'handle'         => 'Tester',
			'forum_user_id'  => '',
			'customer_id'    => 0,
			'customer_name'  => 'Test Person',
			'customer_email' => 'person@example.test',
			'shipping'       => 'premium',
			'flat_on'        => false,
			'flat_total'     => '',
			'notes'          => 'internal note',
			'lines'          => array(
				array( 'kind' => 'fish', 'name' => 'Blue Test Tang', 'qty' => 2, 'unit_price' => '45' ),
				array( 'kind' => 'fish', 'name' => 'Red Test Goby', 'qty' => 1, 'unit_price' => '12.50' ),
			),
		),
		$more
	);
}

/**
 * Pre-check and publish in one go.
 *
 * @param Publisher $publisher Publisher.
 * @param array     $form      The form.
 * @return array The publish result.
 */
function go( $publisher, array $form ) {
	$check = $publisher->precheck( $form, USER, TODAY );
	if ( empty( $check['ok'] ) ) {
		return $check;
	}
	return $publisher->publish( $check['id'], $check['token'], $check['report']['dup_keys'], USER, TODAY );
}

function product_count() {
	return count( $GLOBALS['fh_wc']['products'] );
}

echo "1. A draft is two rows in the tables and nothing in the shop\n";
list( $publisher, $store, $shop, $world ) = setup();
$r = $publisher->save_draft( form( array( 'handle' => '  ' ) ), USER );
check( 'no handle: refused, nothing written', ! $r['ok'] && 'input' === $r['code'] && array() === $store->invoices );
$r = $publisher->save_draft( form(), USER );
check( 'saved as a draft with its lines and its total', $r['ok'] && 1 === $r['id'] && 'draft' === $r['invoice']['state'] && 2 === count( $r['lines'] ) && 102.5 === $r['invoice']['total'] && null === $r['invoice']['flat_total'] && null === $r['invoice']['product_id'] && 'tester' === $r['invoice']['handle_key'] );
check( 'who and when', USER === $r['invoice']['created_by'] && USER === $r['invoice']['updated_by'] && '' !== $r['invoice']['created_at'] && 1 === $r['invoice']['version'] );
check( 'the shop was not written to: a draft is not a product', array() === $GLOBALS['fh_wc']['writes'] && 0 === product_count() );
$ids = array_column( $r['lines'], 'id' );

$again          = form( array( 'id' => 1, 'version' => 1 ) );
$again['lines'] = array(
	array( 'id' => $ids[1], 'kind' => 'fish', 'name' => 'Red Test Goby', 'qty' => 3, 'unit_price' => '12.50' ),
	array( 'kind' => 'credit', 'name' => 'lost Test Wrasse', 'unit_price' => '10' ),
);
$r = $publisher->save_draft( $again, USER );
check( 'saved again: the version moves on, the kept line keeps its row, the dropped one is gone, the new one is added', $r['ok'] && 2 === $r['invoice']['version'] && 2 === count( $r['lines'] ) && $ids[1] === $r['lines'][0]['id'] && 3 === $r['lines'][0]['qty'] && 'credit' === $r['lines'][1]['kind'] && ! in_array( $ids[0], array_column( $r['lines'], 'id' ), true ) && 27.5 === $r['invoice']['total'] );
$r = $publisher->save_draft( form( array( 'id' => 1, 'version' => 1 ) ), USER );
check( 'a window that is behind (an older version) is refused and writes nothing', ! $r['ok'] && 'conflict' === $r['code'] && 2 === $store->invoice( 1 )['version'] && 27.5 === $store->invoice( 1 )['total'] );
$r = $publisher->save_draft( form( array( 'id' => 99, 'version' => 1 ) ), USER );
check( 'an invoice that does not exist: refused', ! $r['ok'] && 'missing' === $r['code'] );
$r = $publisher->save_draft( form( array( 'flat_on' => true, 'flat_total' => 'abc' ) ), USER );
check( 'a flat total that is not an amount: refused', ! $r['ok'] && 'input' === $r['code'] );
$r = $publisher->save_draft( form( array( 'flat_on' => true, 'flat_total' => '' ) ), USER );
check( 'the flat-total box ticked but empty: refused', ! $r['ok'] && 'input' === $r['code'] );
$r = $publisher->save_draft( form( array( 'flat_on' => true, 'flat_total' => '-5' ) ), USER );
check( 'a negative flat total: refused', ! $r['ok'] );
$r = $publisher->save_draft( form( array( 'customer_email' => 'not an address' ) ), USER );
check( 'an e-mail that is not an address: refused', ! $r['ok'] && 'input' === $r['code'] );
$r = $publisher->save_draft( form( array( 'flat_on' => true, 'flat_total' => '100' ) ), USER );
check( 'a flat total: stored, with the Discount line that makes the lines add up', $r['ok'] && 100.0 === $r['invoice']['flat_total'] && 100.0 === $r['invoice']['total'] && 3 === count( $r['lines'] ) && 'discount' === $r['lines'][2]['kind'] && -2.5 === $r['lines'][2]['unit_price'] );
$r2 = $publisher->save_draft( form( array( 'id' => $r['id'], 'version' => 1, 'flat_on' => false ) ), USER );
check( 'the flat total unticked: the Discount line goes', $r2['ok'] && null === $r2['invoice']['flat_total'] && 2 === count( $r2['lines'] ) && 102.5 === $r2['invoice']['total'] );
$r = $publisher->save_draft( form( array( 'shipping' => 'teleport', 'shipping_slug' => 'quote', 'forum_user_id' => '12456', 'customer_id' => (string) $world['customer'], 'notes' => "<b>line one</b>\r\nline two" ) ), USER );
check( 'an unknown shipping choice is Premium (and keeps no slug); ids typed; notes plain with their line break', 'premium' === $r['invoice']['shipping'] && '' === $r['invoice']['shipping_slug'] && 12456 === $r['invoice']['forum_user_id'] && $world['customer'] === $r['invoice']['customer_id'] && "line one\nline two" === $r['invoice']['notes'] );
$r = $publisher->save_draft( form( array( 'shipping' => 'other', 'shipping_slug' => ' Quote ' ) ), USER );
check( 'another class keeps its slug', 'other' === $r['invoice']['shipping'] && 'quote' === $r['invoice']['shipping_slug'] );

echo "2. The pre-check cannot be skipped\n";
list( $publisher, $store, $shop, $world ) = setup();
$saved = $publisher->save_draft( form(), USER );
$r     = $publisher->publish( $saved['id'], '', array(), USER, TODAY );
check( 'no token: refused, no product', ! $r['ok'] && 'precheck' === $r['code'] && 0 === product_count() );
$r = $publisher->publish( $saved['id'], str_repeat( 'a', 40 ), array(), USER, TODAY );
check( 'a made-up token: refused', ! $r['ok'] && 'precheck' === $r['code'] && 0 === product_count() );
$r = $publisher->publish( $saved['id'], array( 'x' ), array(), USER, TODAY );
check( 'something that is not a token: refused', ! $r['ok'] && 'precheck' === $r['code'] );

$check = $publisher->precheck( form( array( 'id' => $saved['id'], 'version' => 1 ) ), USER, TODAY );
check( 'the pre-check saves the draft, reports, and hands out a token', $check['ok'] && is_array( $check['report'] ) && 40 === strlen( $check['token'] ) && 2 === $store->invoice( $saved['id'] )['version'] );
$hash = Rules::content_hash( $store->invoice( $saved['id'] ), $store->lines( $saved['id'] ) );
check( 'the token is for this user, this invoice, this content, a publish', Publisher::token_ok( $check['token'], USER, $saved['id'], $hash, 'publish' ) && ! Publisher::token_ok( $check['token'], USER + 1, $saved['id'], $hash, 'publish' ) && ! Publisher::token_ok( $check['token'], USER, $saved['id'] + 1, $hash, 'publish' ) && ! Publisher::token_ok( $check['token'], USER, $saved['id'], md5( 'other' ), 'publish' ) && ! Publisher::token_ok( $check['token'], USER, $saved['id'], $hash, 'update' ) );
$tick = (int) floor( time() / Publisher::TOKEN_SECONDS );
check( 'a token of the time slot before still counts; an older one does not', Publisher::token_ok( Publisher::token( USER, 5, 'h', 'publish', $tick - 1 ), USER, 5, 'h', 'publish' ) && ! Publisher::token_ok( Publisher::token( USER, 5, 'h', 'publish', $tick - 2 ), USER, 5, 'h', 'publish' ) );

$r = $publisher->publish( $saved['id'], $check['token'], array(), USER + 1, TODAY );
check( 'another user cannot publish with it', ! $r['ok'] && 'precheck' === $r['code'] && 0 === product_count() );

$changed          = form( array( 'id' => $saved['id'], 'version' => 2 ) );
$changed['lines'][] = array( 'kind' => 'fish', 'name' => 'Green Test Blenny', 'qty' => 1, 'unit_price' => '30' );
$publisher->save_draft( $changed, USER );
$r = $publisher->publish( $saved['id'], $check['token'], array(), USER, TODAY );
check( 'the draft was changed after the pre-check: the old token no longer publishes', ! $r['ok'] && 'precheck' === $r['code'] && 0 === product_count() );

$check = $publisher->precheck( array_merge( $changed, array( 'version' => 3 ) ), USER, TODAY );
$r     = $publisher->publish( $saved['id'], $check['token'], array(), USER, TODAY );
check( 'checked again, it publishes — exactly the content that was checked', $r['ok'] && 1 === product_count() && 132.5 === $r['total'] );
$r2 = $publisher->publish( $saved['id'], $check['token'], array(), USER, TODAY );
check( 'published twice: the second is refused, names the link, and makes no second product', ! $r2['ok'] && 'state' === $r2['code'] && $r['url'] === $r2['url'] && 1 === product_count() );

echo "3. The product Publish creates — the whole field set, read back from the shop\n";
list( $publisher, $store, $shop, $world ) = setup();
$form          = form( array( 'forum_user_id' => 12456, 'customer_id' => $world['customer'] ) );
$form['lines'] = array(
	array( 'kind' => 'fish', 'name' => 'Blue Test Tang (Medium)', 'qty' => 2, 'unit_price' => '45', 'source' => 'product', 'product_id' => 501, 'variation_id' => 777, 'size_slug' => 'medium', 'size_label' => 'Medium', 'take_stock' => true ),
	array( 'kind' => 'fish', 'name' => 'Red Test Goby', 'qty' => 1, 'unit_price' => '12.50', 'source' => 'tank', 'stay_id' => 9, 'product_id' => 502 ),
	array( 'kind' => 'credit', 'name' => 'lost Test Cleaner Wrasse', 'unit_price' => '82' ),
	array( 'kind' => 'note', 'name' => 'Ships Tuesday' ),
);
$r       = go( $publisher, $form );
$product = $shop->product( $r['product_id'] );
check( 'published', $r['ok'] && 'published' === $r['code'] && array() === $r['warnings'], json_encode( $r ) );
check_same( 'title: the handle and TODAY\'s site date as n/j/y', 'Tester Invoice (11/3/26)', $product['name'] );
check_same( 'short description', 'Tester Only', $product['short_description'] );
check_same( 'description: a <ul>, one <li> per line, the price PER FISH, the credit negative', "<ul>\n<li>Blue Test Tang (Medium) x2 \u{2014} \$45</li>\n<li>Red Test Goby x1 \u{2014} \$12.50</li>\n<li>Credit: lost Test Cleaner Wrasse \u{2014} -\$82</li>\n<li>Ships Tuesday</li>\n</ul>", $product['description'] );
check_same( 'regular price = the total', '20.50', $product['regular_price'] );
check_same( 'NO sale price', '', $product['sale_price'] );
check( 'stock: managed, quantity 1, backorders not allowed, in stock', true === $product['manage_stock'] && 1 === $product['stock_quantity'] && 'no' === $product['backorders'] && 'instock' === $product['stock_status'] );
check_same( 'catalog visibility: hidden', 'hidden', $product['catalog_visibility'] );
check_same( 'the Invoices category ONLY', array( array( $world['cat_invoices'] ), array( 'invoices' ) ), array( $product['category_ids'], $product['category_slugs'] ) );
check_same( 'shipping class: Premium by default', array( $world['ship_premium'], 'premium' ), array( $product['shipping_class_id'], $product['shipping_class'] ) );
check_same( 'featured image: the "under" image for a total under the threshold', $world['img_small'], $product['image_id'] );
check_same( 'status: published; a simple product', array( 'publish', 'simple' ), array( $product['status'], $product['type'] ) );
check_same( 'this section\'s mark: the invoice id', $r['id'], $product['marker'] );
check_same( 'not locked by any order', 0, $product['lock_order_id'] );
$json = json_decode( $product['lines_json'], true );
check_same(
	'`_fh_invoice_lines`: the JSON the theme expects — product_id (the VARIATION id) only on the ticked shop line, none on the tank line',
	array(
		array( 'name' => 'Blue Test Tang (Medium)', 'qty' => 2, 'price' => 45, 'kind' => 'fish', 'product_id' => 777 ),
		array( 'name' => 'Red Test Goby', 'qty' => 1, 'price' => 12.5, 'kind' => 'fish' ),
		array( 'name' => 'Credit: lost Test Cleaner Wrasse', 'qty' => 1, 'price' => -82, 'kind' => 'credit' ),
		array( 'name' => 'Ships Tuesday', 'qty' => 1, 'price' => null, 'kind' => 'note' ),
	),
	$json
);
check( 'the meta is stored as a JSON string, as the theme reads it', is_string( $GLOBALS['fh_wc']['products'][ $r['product_id'] ]['meta'][ Invoices::PRODUCT_LINES_META ] ) );
check_same( 'the theme adds the lines up to the price, so the order gets one line per fish', 20.5, Grammar::sum( Grammar::product_lines( $product['lines_json'], $product['description'] ) ) );
check_same( 'the link: under the Invoices category, the slug from the title', 'https://example.test/shop/invoices/tester-invoice-11-3-26/', $r['url'] );
check_same( 'the product meta: the theme\'s lines and this section\'s mark, nothing else', array( Invoices::PRODUCT_LINES_META, Invoices::PRODUCT_MARKER_META ), array_keys( $GLOBALS['fh_wc']['products'][ $r['product_id'] ]['meta'] ) );

$writes = $GLOBALS['fh_wc']['writes'];
check( 'one save, of one new product — nothing else in the shop was written', 1 === count( $writes ) && 'save' === $writes[0][0] && 1 === product_count() );
$setters = array_column( $writes[0][2], 0 );
check( 'a sale price was never set or cleared', ! in_array( 'set_sale_price', $setters, true ) );
check_same( 'the setters called, in order', array( 'set_name', 'set_status', 'set_catalog_visibility', 'set_short_description', 'set_description', 'set_regular_price', 'set_manage_stock', 'set_stock_quantity', 'set_backorders', 'set_stock_status', 'set_category_ids', 'set_shipping_class_id', 'set_image_id', 'update_meta_data', 'update_meta_data' ), $setters );

$invoice = $store->invoice( $r['id'] );
check( 'the invoice: published, its product, the title, the class it resolved to, who and when', 'published' === $invoice['state'] && $r['product_id'] === $invoice['product_id'] && 'Tester Invoice (11/3/26)' === $invoice['title'] && 'premium' === $invoice['shipping_slug'] && 20.5 === $invoice['total'] && USER === $invoice['published_by'] && null !== $invoice['published_at'] && null === $invoice['order_id'] );
check( 'the links are kept in this section\'s own table: the tank line knows its group and product, the shop line its variation', 9 === $store->lines( $r['id'] )[1]['stay_id'] && 502 === $store->lines( $r['id'] )[1]['product_id'] && 777 === $store->lines( $r['id'] )[0]['variation_id'] );
check( 'no order was written, no e-mail sent, no stock of another product touched', array() === $GLOBALS['fh_wc']['orders'] && 0 === fh_fired( 'wp_mail' ) );

echo "4. The title's date, the image, the shipping class, the flat total\n";
list( $publisher, $store, $shop, $world ) = setup();
$r = $publisher->precheck( form(), USER, '2027-01-09' );
$r = $publisher->publish( $r['id'], $r['token'], array(), USER, '2027-01-09' );
check_same( 'the date is the one handed in — the site\'s today', 'Tester Invoice (1/9/27)', $shop->product( $r['product_id'] )['name'] );
check_same( 'a second invoice of the same handle on the same day gets its own link', 'https://example.test/shop/invoices/tester-invoice-1-9-27-2/', go_dated( $publisher, form(), '2027-01-09' )['url'] );

function go_dated( $publisher, array $form, $today ) {
	$check = $publisher->precheck( $form, USER, $today );
	return $publisher->publish( $check['id'], $check['token'], $check['report']['dup_keys'], USER, $today );
}

list( $publisher, $store, $shop, $world ) = setup();
$r = go( $publisher, form( array( 'flat_on' => true, 'flat_total' => '399.99' ) ) );
check( 'a flat total of 399.99: regular price 399.99, the "under" image', '399.99' === $shop->product( $r['product_id'] )['regular_price'] && $world['img_small'] === $shop->product( $r['product_id'] )['image_id'] );
$json = json_decode( $shop->product( $r['product_id'] )['lines_json'], true );
check( '…and an Adjustment line sent to the theme as a one-off fish line, so the lines add up to it', 'Adjustment' === $json[2]['name'] && 'fish' === $json[2]['kind'] && 297.49 === $json[2]['price'] && 399.99 === Grammar::sum( Grammar::lines_from_json( $json ) ) );
check( '…with the warning that says how it will appear', 1 === count( preg_grep( '/Adjustment/', $publisher->precheck( form( array( 'handle' => 'Other', 'flat_on' => true, 'flat_total' => '399.99' ) ), USER, TODAY )['report']['warnings'] ) ) );
$r = go( $publisher, form( array( 'handle' => 'Second', 'flat_on' => true, 'flat_total' => '400' ) ) );
check( 'a flat total of exactly 400: the "at or over" image', $world['img_big'] === $shop->product( $r['product_id'] )['image_id'] && '400.00' === $shop->product( $r['product_id'] )['regular_price'] );
$r = go( $publisher, form( array( 'handle' => 'Third', 'flat_on' => true, 'flat_total' => '80' ) ) );
$p = $shop->product( $r['product_id'] );
check( 'a flat total below the sum: the price is the flat total, a Discount line in the description and in the JSON', '80.00' === $p['regular_price'] && false !== strpos( $p['description'], "<li>Discount \u{2014} -\$22.50</li>" ) && 80.0 === Grammar::sum( Grammar::lines_from_json( $p['lines_json'] ) ) && '' === $p['sale_price'] );

list( $publisher, $store, $shop, $world ) = setup( array( 'image_under' => 0, 'image_over' => 0 ) );
$check = $publisher->precheck( form(), USER, TODAY );
$r     = $publisher->publish( $check['id'], $check['token'], array(), USER, TODAY );
check( 'no image set in Settings: the invoice gets none, and the pre-check says so', $r['ok'] && 0 === $shop->product( $r['product_id'] )['image_id'] && 1 === count( preg_grep( '/No image is set/', $check['report']['warnings'] ) ) && ! in_array( 'set_image_id', array_column( $GLOBALS['fh_wc']['writes'][0][2], 0 ), true ) );
list( $publisher, $store, $shop, $world ) = setup( array( 'image_under' => 999999 ) );
$check = $publisher->precheck( form(), USER, TODAY );
$r     = $publisher->publish( $check['id'], $check['token'], array(), USER, TODAY );
check( 'an image that is not in the media library any more: none, with a warning', $r['ok'] && 0 === $shop->product( $r['product_id'] )['image_id'] && 1 === count( preg_grep( '/#999999/', $check['report']['warnings'] ) ) );

list( $publisher, $store, $shop, $world ) = setup();
$r = go( $publisher, form( array( 'shipping' => 'free' ) ) );
check_same( 'Free: the class Settings maps it to', $world['ship_free'], $shop->product( $r['product_id'] )['shipping_class_id'] );
$r = go( $publisher, form( array( 'handle' => 'Second', 'shipping' => 'other', 'shipping_slug' => 'quote' ) ) );
check( 'another class: that class', $world['ship_quote'] === $shop->product( $r['product_id'] )['shipping_class_id'] && 'quote' === $store->invoice( $r['id'] )['shipping_slug'] );

echo "5. What stops a Publish\n";
list( $publisher, $store, $shop, $world ) = setup( array( 'shipping' => array( 'premium' => 'overnight', 'free' => '' ) ) );
$check = $publisher->precheck( form(), USER, TODAY );
$r     = $publisher->publish( $check['id'], $check['token'], array(), USER, TODAY );
check( 'Premium mapped to a class that does not exist: an error in the pre-check, and Publish refuses', 1 === count( preg_grep( '/"overnight", which does not exist/', $check['report']['errors'] ) ) && ! $r['ok'] && 'errors' === $r['code'] && 0 === product_count() );
$check = $publisher->precheck( form( array( 'handle' => 'Second', 'shipping' => 'free' ) ), USER, TODAY );
check( 'Free not mapped at all: an error that says where to set it', 1 === count( preg_grep( '/No shipping class is set for "Free"/', $check['report']['errors'] ) ) );
$check = $publisher->precheck( form( array( 'handle' => 'Third', 'shipping' => 'other', 'shipping_slug' => '' ) ), USER, TODAY );
check( 'another class without a class: an error', 1 === count( preg_grep( '/Choose the shipping class/', $check['report']['errors'] ) ) );

list( $publisher, $store, $shop, $world ) = setup( array( 'category_slug' => 'bills' ) );
$check = $publisher->precheck( form(), USER, TODAY );
$r     = $publisher->publish( $check['id'], $check['token'], array(), USER, TODAY );
check( 'the Invoices category does not exist: refused — it is never created silently, and the store\'s default category is never used instead', ! $r['ok'] && 'errors' === $r['code'] && 0 === product_count() && 1 === count( preg_grep( '/"bills" does not exist/', $check['report']['errors'] ) ) && 2 === count( $GLOBALS['fh_wc']['terms']['product_cat'] ) );

list( $publisher, $store, $shop, $world ) = setup();
$bad          = form();
$bad['lines'] = array( array( 'kind' => 'note', 'name' => 'Only a note' ) );
$check        = $publisher->precheck( $bad, USER, TODAY );
$r            = $publisher->publish( $check['id'], $check['token'], array(), USER, TODAY );
check( 'no fish: the draft is saved, the pre-check runs, Publish refuses', $check['ok'] && ! $r['ok'] && 'errors' === $r['code'] && 0 === product_count() );
$bad['lines'] = array( array( 'kind' => 'fish', 'name' => 'Blue Test Tang', 'qty' => 1, 'unit_price' => '10' ), array( 'kind' => 'credit', 'name' => 'lost Test Wrasse', 'unit_price' => '82' ) );
$check        = $publisher->precheck( array_merge( $bad, array( 'handle' => 'Second' ) ), USER, TODAY );
$r            = $publisher->publish( $check['id'], $check['token'], array(), USER, TODAY );
check( 'a total below zero: refused', ! $r['ok'] && 'errors' === $r['code'] && 0 === product_count() );
$bad['lines'] = array( array( 'kind' => 'fish', 'name' => 'Blue Test Tang', 'qty' => 1, 'unit_price' => '10' ), array( 'kind' => 'note', 'name' => 'Box x2' ) );
$check        = $publisher->precheck( array_merge( $bad, array( 'handle' => 'Third' ) ), USER, TODAY );
check( 'a note that would be read as a fish: an error on that line', array( 1 ) === array_keys( $check['report']['line_errors'] ) && ! $publisher->publish( $check['id'], $check['token'], array(), USER, TODAY )['ok'] );

list( $publisher, $store, $shop, $world )       = setup();
$GLOBALS['fh_options']['woocommerce_manage_stock'] = 'no';
$check = $publisher->precheck( form(), USER, TODAY );
$r     = $publisher->publish( $check['id'], $check['token'], array(), USER, TODAY );
check( 'the store\'s own "Enable stock management" is off — "stock 1" would not be enforced and the invoice could be paid twice: an error, and Publish refuses', 1 === count( preg_grep( '/Enable stock management/', $check['report']['errors'] ) ) && ! $r['ok'] && 'errors' === $r['code'] && 0 === product_count() );
$GLOBALS['fh_options']['woocommerce_manage_stock'] = 'yes';
$check = $publisher->precheck( form( array( 'id' => $check['id'], 'version' => $store->invoice( $check['id'] )['version'] ) ), USER, TODAY );
check( 'switched on again: no such error, and it publishes', array() === preg_grep( '/Enable stock management/', $check['report']['errors'] ) && $publisher->publish( $check['id'], $check['token'], array(), USER, TODAY )['ok'] && 1 === product_count() );

echo "6. A fish that already appears on another invoice of this customer\n";
list( $publisher, $store, $shop, $world ) = setup();
$hand = fh_wc_product(
	array(
		'name'          => 'Tester Invoice (10/1/26)',
		'description'   => "<ul>\n<li>Blue Test Tangs x1 — \$45</li>\n</ul>",
		'regular_price' => '45',
		'category_ids'  => array( $world['cat_invoices'] ),
	)
);
$check = $publisher->precheck( form(), USER, TODAY );
check( 'the pre-check flags it on its line, with the hand-made invoice it was found on', 1 === count( $check['report']['duplicates'] ) && 0 === $check['report']['duplicates'][0]['index'] && 'product:' . $hand === $check['report']['duplicates'][0]['matches'][0]['ref'] && 1 === count( $check['report']['dup_keys'] ) );
$r = $publisher->publish( $check['id'], $check['token'], array(), USER, TODAY );
check( 'Publish without the confirmation: refused, with the report again', ! $r['ok'] && 'confirm' === $r['code'] && isset( $r['report'] ) && 1 === product_count() );
$r = $publisher->publish( $check['id'], $check['token'], array( 'not-the-key' ), USER, TODAY );
check( 'confirming something else: refused', ! $r['ok'] && 'confirm' === $r['code'] );
$r = $publisher->publish( $check['id'], $check['token'], $check['report']['dup_keys'], USER, TODAY );
check( 'each one confirmed: published', $r['ok'] && 2 === product_count() );

// Two drafts of the same customer with the same fish.
list( $publisher, $store, $shop, $world ) = setup();
$first  = $publisher->precheck( form(), USER, TODAY );
$second = $publisher->precheck( form( array( 'handle' => 'TESTER', 'lines' => array( array( 'kind' => 'fish', 'name' => 'Red Test Goby', 'qty' => 1, 'unit_price' => '12.50' ) ) ) ), USER, TODAY );
check( 'the first draft was checked alone; the second (the same handle in another case) is flagged against the first, a draft', array() === $first['report']['dup_keys'] && 1 === count( $second['report']['dup_keys'] ) && 'invoice:' . $first['id'] === $second['report']['duplicates'][0]['matches'][0]['ref'] );
$r = $publisher->publish( $second['id'], $second['token'], $second['report']['dup_keys'], USER, TODAY );
check( 'the second is confirmed and published', $r['ok'] );
$r = $publisher->publish( $first['id'], $first['token'], $first['report']['dup_keys'], USER, TODAY );
check( 'the first now has a fish on a published invoice: the pre-check is run AGAIN at Publish and asks', ! $r['ok'] && 'confirm' === $r['code'] && 'invoice:' . $second['id'] === $r['report']['duplicates'][0]['matches'][0]['ref'] && 1 === product_count() );

list( $publisher, $store, $shop, $world ) = setup();
$first = $publisher->precheck( form(), USER, TODAY );
check_same( 'nothing to confirm when it was shown', array(), $first['report']['dup_keys'] );
fh_wc_product(
	array(
		'name'          => 'Tester Invoice (11/2/26)',
		'description'   => '<ul><li>Red Test Goby x1 — $12</li></ul>',
		'regular_price' => '12',
		'category_ids'  => array( $world['cat_invoices'] ),
	)
);
$r = $publisher->publish( $first['id'], $first['token'], array(), USER, TODAY );
check( 'an invoice with the same fish appeared since: Publish refuses and shows the new pre-check', ! $r['ok'] && 'confirm' === $r['code'] && 1 === count( $r['report']['dup_keys'] ) && 1 === product_count() );
$r = $publisher->publish( $first['id'], $r['token'], $r['report']['dup_keys'], USER, TODAY );
check( 'confirmed: published', $r['ok'] );

echo "7. A product that does not come out as written is taken offline again\n";
$tamper = array(
	'the regular price changed by another plugin'  => static function ( $product ) {
		$product->data['regular_price'] = '1.00';
	},
	'a sale price added by another plugin'         => static function ( $product ) {
		$product->data['sale_price'] = '5.00';
	},
	'the store\'s default category added'          => static function ( $product ) {
		$product->data['category_ids'][] = 12345;
	},
	'the catalog visibility reset'                 => static function ( $product ) {
		$product->data['catalog_visibility'] = 'visible';
	},
	'backorders switched on'                       => static function ( $product ) {
		$product->data['backorders'] = 'notify';
	},
	'the stock quantity changed'                   => static function ( $product ) {
		$product->data['stock_quantity'] = 5;
	},
	'the shipping class changed'                   => static function ( $product ) {
		$product->data['shipping_class_id'] = 4242;
	},
	'the lines JSON emptied'                       => static function ( $product ) {
		$product->meta[ Invoices::PRODUCT_LINES_META ] = '[]';
	},
	'a line of the description changed'            => static function ( $product ) {
		$product->data['description'] = str_replace( '$45', '$40', $product->data['description'] );
	},
	'the title changed'                            => static function ( $product ) {
		$product->data['name'] = 'Something Else';
	},
);
foreach ( $tamper as $label => $callback ) {
	list( $publisher, $store, $shop, $world ) = setup();
	$check                       = $publisher->precheck( form(), USER, TODAY );
	$GLOBALS['fh_wc']['on_save'] = static function ( $product ) use ( $callback ) {
		// Only the first save — the Publish — is tampered with; taking the product offline must go through.
		if ( 0 === count( $GLOBALS['fh_wc']['writes'] ) ) {
			$callback( $product );
		}
	};
	$r                           = $publisher->publish( $check['id'], $check['token'], array(), USER, TODAY );
	$products                    = array_values( $GLOBALS['fh_wc']['products'] );
	check( "$label: refused, the product is a draft again, the invoice is still a draft", ! $r['ok'] && 'verify' === $r['code'] && 1 === count( $products ) && 'draft' === $products[0]['data']['status'] && 'draft' === $store->invoice( $check['id'] )['state'] && null === $store->invoice( $check['id'] )['product_id'], json_encode( $r ) );
}
check( 'the message names the product and says it was taken offline', false !== strpos( $r['message'], 'taken offline' ) && false !== strpos( $r['message'], '#' . $r['product_id'] ) );

list( $publisher, $store, $shop, $world ) = setup();
$check                                = $publisher->precheck( form(), USER, TODAY );
$GLOBALS['fh_wc']['on_save']          = static function ( $product ) {
	if ( 0 === count( $GLOBALS['fh_wc']['writes'] ) ) {
		$product->data['sale_price'] = '5.00';
	}
};
$r                                    = $publisher->publish( $check['id'], $check['token'], array(), USER, TODAY );
check_same( 'a sale price somebody else set is left exactly as it is (never cleared)', '5.00', $shop->product( $r['product_id'] )['sale_price'] );
$GLOBALS['fh_wc']['on_save'] = null;
$check2                      = $publisher->precheck( form( array( 'id' => $check['id'], 'version' => $store->invoice( $check['id'] )['version'] ) ), USER, TODAY );
$r2                          = $publisher->publish( $check2['id'], $check2['token'], array(), USER, TODAY );
check( 'the next attempt reuses that product — and still refuses, because the sale price is still there', ! $r2['ok'] && 'verify' === $r2['code'] && $r['product_id'] === $r2['product_id'] && 1 === product_count() && '5.00' === $shop->product( $r['product_id'] )['sale_price'] );

list( $publisher, $store, $shop, $world ) = setup();
$GLOBALS['fh_wc']['no_stock_amount']  = 1;
$r                                    = go( $publisher, form() );
check( 'the store\'s out-of-stock threshold makes a stock of 1 "out of stock": published, with a warning that it cannot be bought', $r['ok'] && 'outofstock' === $shop->product( $r['product_id'] )['stock_status'] && 1 === count( preg_grep( '/out of stock/', $r['warnings'] ) ) );

echo "8. A Publish that stops half-way never makes a second product\n";
list( $publisher, $store, $shop, $world ) = setup();
$check             = $publisher->precheck( form(), USER, TODAY );
$store->fail_saves = 1; // The product is created; the "published" write fails.
$before            = $store->invoice( $check['id'] )['version'];
$r                 = $publisher->publish( $check['id'], $check['token'], array(), USER, TODAY );
check( 'the product was created but the invoice could not be marked published: said so, with the product', ! $r['ok'] && 'db' === $r['code'] && $r['product_id'] > 0 && 1 === product_count() && 'draft' === $store->invoice( $check['id'] )['state'] );
$orphan = $r['product_id'];
check_same( 'a refused Publish does not move the invoice\'s version on: the page that asked is not behind', $before, $store->invoice( $check['id'] )['version'] );
check_same( 'the product carries the invoice\'s mark, so it can be found again', $orphan, $shop->product_by_marker( $check['id'] ) );
$r = $publisher->discard( $check['id'], USER );
check( 'such a draft cannot be withdrawn while that product exists', ! $r['ok'] && 'orphan' === $r['code'] && 'draft' === $store->invoice( $check['id'] )['state'] );
$check2 = $publisher->precheck( form( array( 'id' => $check['id'], 'version' => $store->invoice( $check['id'] )['version'] ) ), USER, TODAY );
$r      = $publisher->publish( $check2['id'], $check2['token'], array(), USER, TODAY );
check( 'published again: the SAME product is used, there is still only one', $r['ok'] && $orphan === $r['product_id'] && 1 === product_count() && 'published' === $store->invoice( $check['id'] )['state'] && 'publish' === $shop->product( $orphan )['status'] );

echo "8b. One writer per invoice: a double submit makes ONE product\n";
list( $publisher, $store, $shop, $world ) = setup();
$check = $publisher->precheck( form(), USER, TODAY );
// A second Publish with the same token arrives while the first is creating its product.
$second                      = null;
$GLOBALS['fh_wc']['on_save'] = static function () use ( &$second, $publisher, $check ) {
	$GLOBALS['fh_wc']['on_save'] = null;
	$second                      = $publisher->publish( $check['id'], $check['token'], array(), USER, TODAY );
};
$first                       = $publisher->publish( $check['id'], $check['token'], array(), USER, TODAY );
check( 'two Publish requests at the same moment: the first publishes, the second is refused as busy (it used to make a second live product)', $first['ok'] && is_array( $second ) && ! $second['ok'] && 'busy' === $second['code'] );
check( 'ONE product, and it is the invoice\'s', 1 === product_count() && $first['product_id'] === $store->invoice( $check['id'] )['product_id'] && 'published' === $store->invoice( $check['id'] )['state'] );
$third = $publisher->publish( $check['id'], $check['token'], array(), USER, TODAY );
check( 'a third one afterwards: already published, still one product', ! $third['ok'] && 'state' === $third['code'] && 1 === product_count() );
check( 'every lock taken was given back', array() === $store->locks && $store->lock_calls[0] === $store->lock_calls[1] && $store->lock_calls[0] > 0 );

list( $publisher, $store, $shop, $world ) = setup();
$check                         = $publisher->precheck( form(), USER, TODAY );
$store->locks[ $check['id'] ] = true; // Another request holds this invoice.
$r                             = $publisher->publish( $check['id'], $check['token'], array(), USER, TODAY );
check( 'while another request holds the invoice: Publish is refused, nothing is created, nothing is written', ! $r['ok'] && 'busy' === $r['code'] && 0 === product_count() && 'draft' === $store->invoice( $check['id'] )['state'] );
$v = $store->invoice( $check['id'] )['version'];
check( '…and so are a draft save, a withdrawal and — of a published one — an edit and the Trash', 'busy' === $publisher->save_draft( form( array( 'id' => $check['id'], 'version' => $v ) ), USER )['code'] && 'busy' === $publisher->discard( $check['id'], USER )['code'] && 'busy' === $publisher->update( $check['id'], form(), 'x', array(), USER, TODAY )['code'] && 'busy' === $publisher->trash( $check['id'], USER )['code'] && $v === $store->invoice( $check['id'] )['version'] );
unset( $store->locks[ $check['id'] ] );
check( 'the lock given back: it publishes', $publisher->publish( $check['id'], $check['token'], array(), USER, TODAY )['ok'] && 1 === product_count() );
check( 'an invoice that does not exist is still "missing", not "busy"', 'missing' === $publisher->publish( 0, 'x', array(), USER, TODAY )['code'] && 'missing' === $publisher->publish( 999, 'x', array(), USER, TODAY )['code'] && array() === $store->locks );

list( $publisher, $store, $shop, $world ) = setup();
$check                       = $publisher->precheck( form(), USER, TODAY );
$GLOBALS['fh_wc']['on_save'] = static function () {
	throw new \RuntimeException( 'the shop is down' );
};
$r                           = $publisher->publish( $check['id'], $check['token'], array(), USER, TODAY );
check( 'the shop throws: refused with its reason, nothing created, the invoice still a draft', ! $r['ok'] && 'shop' === $r['code'] && false !== strpos( $r['message'], 'the shop is down' ) && 0 === product_count() && 'draft' === $store->invoice( $check['id'] )['state'] );

echo "9. Withdrawing a draft\n";
list( $publisher, $store, $shop, $world ) = setup();
$saved = $publisher->save_draft( form(), USER );
$r     = $publisher->discard( $saved['id'], USER );
check( 'a draft is withdrawn: the row is kept, as "cancelled"', $r['ok'] && 'cancelled' === $store->invoice( $saved['id'] )['state'] && 1 === count( $store->invoices ) );
$r = $publisher->save_draft( form( array( 'id' => $saved['id'], 'version' => 2 ) ), USER );
check( 'it can no longer be saved as a draft', ! $r['ok'] && 'state' === $r['code'] );
$r = $publisher->publish( $saved['id'], Publisher::token( USER, $saved['id'], Rules::content_hash( $store->invoice( $saved['id'] ), $store->lines( $saved['id'] ) ), 'publish' ), array(), USER, TODAY );
check( '…nor published', ! $r['ok'] && 'state' === $r['code'] && 0 === product_count() );
$done = go( $publisher, form( array( 'handle' => 'Second' ) ) );
$r    = $publisher->discard( $done['id'], USER );
check( 'a published invoice is not "withdrawn" — that is what the Trash is for', ! $r['ok'] && 'state' === $r['code'] && 'published' === $store->invoice( $done['id'] )['state'] );
check( 'an invoice that does not exist', ! $publisher->discard( 999, USER )['ok'] );

echo "10. This section never writes a sale price\n";
$calls = 0;
foreach ( glob( dirname( __DIR__ ) . '/*.php' ) as $file ) {
	foreach ( token_get_all( (string) file_get_contents( $file ) ) as $token ) {
		if ( is_array( $token ) && T_STRING === $token[0] && in_array( strtolower( $token[1] ), array( 'set_sale_price', 'set_date_on_sale_from', 'set_date_on_sale_to' ), true ) ) {
			$calls++;
		}
	}
}
check_same( 'no code of the section calls set_sale_price() or sets sale dates', 0, $calls );

fh_finish();
