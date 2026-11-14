<?php
/**
 * Contracts — what other sections and plugins can rely on:
 *
 *   fishotel_invoices_lines_for_product( $product_id )  the lines of the
 *       invoice a product is, each with every id it kept — whether or not
 *       it takes from shop stock; for an invoice made by hand, its lines
 *       as the theme reads them.
 *   fishotel_invoices_for_order( $order_id )  the invoices in an order
 *       with their lines.
 *   do_action( 'fishotel_invoice_paid', $invoice_id, $order_id )
 *   do_action( 'fishotel_invoice_released', $invoice_id, $order_id )
 *
 * and that the two functions answer nothing while the section is
 * switched off.
 *
 * Run from the plugin root:  php includes/sections/invoices/tests/contracts-test.php
 */

require_once __DIR__ . '/wp-stubs.php';

use FisHotel\Misc\Sections\Invoices\Invoices;
use FisHotel\Misc\Sections\Invoices\Publisher;
use FisHotel\Misc\Sections\Invoices\Settings;
use FisHotel\Misc\Sections\Invoices\Shop;
use FisHotel\Misc\Sections\Invoices\Sync;
use FisHotel\Misc\Sections\Invoices\Tests\Fake_Store;
use FisHotel\Misc\Sections\Invoices\Tests\Fake_Tanks;

const USER  = 8;
const TODAY = '2026-11-03';

$world     = fh_wc_world();
$store     = new Fake_Store();
$shop      = new Shop();
$publisher = new Publisher( $store, $shop, new Fake_Tanks(), Settings::defaults() );

$check = $publisher->precheck(
	array(
		'handle'         => 'Tester',
		'forum_user_id'  => 12456,
		'customer_id'    => $world['customer'],
		'customer_name'  => 'Test Person',
		'customer_email' => 'person@example.test',
		'shipping'       => 'free',
		'flat_on'        => true,
		'flat_total'     => '150',
		'lines'          => array(
			array( 'kind' => 'fish', 'name' => 'Blue Test Tang (Medium)', 'qty' => 2, 'unit_price' => '45', 'source' => 'product', 'product_id' => 501, 'variation_id' => 777, 'size_slug' => 'medium', 'size_label' => 'Medium', 'take_stock' => true ),
			array( 'kind' => 'fish', 'name' => 'Red Test Goby', 'qty' => 1, 'unit_price' => '12.50', 'source' => 'tank', 'stay_id' => 9, 'product_id' => 502 ),
			array( 'kind' => 'fish', 'name' => 'Pink Test Anthias', 'qty' => 1, 'unit_price' => '70', 'source' => 'species', 'species_id' => 31 ),
			array( 'kind' => 'credit', 'name' => 'lost Test Cleaner Wrasse', 'unit_price' => '10' ),
			array( 'kind' => 'note', 'name' => 'Ships Tuesday' ),
		),
	),
	USER,
	TODAY
);
$done  = $publisher->publish( $check['id'], $check['token'], array(), USER, TODAY );
$id    = (int) $done['id'];
$pid   = (int) $done['product_id'];

echo "1. The functions exist\n";
check( 'both, in the global namespace', function_exists( 'fishotel_invoices_lines_for_product' ) && function_exists( 'fishotel_invoices_for_order' ) );
check( 'the section is switched off (boot() did not run): they answer an empty array, without touching anything', false === Invoices::is_on() && array() === fishotel_invoices_lines_for_product( $pid ) && array() === fishotel_invoices_for_order( 1 ) );
check( 'no id: an empty array', array() === Invoices::lines_for_product( 0, $store, $shop ) && array() === Invoices::for_order( 0, $store, $shop ) && array() === fishotel_invoices_lines_for_product( 'abc' ) );

echo "2. fishotel_invoices_lines_for_product()\n";
$lines = Invoices::lines_for_product( $pid, $store, $shop );
check_same( 'every line of the invoice, the Discount line included, in order', array( 'fish', 'fish', 'fish', 'credit', 'note', 'discount' ), array_column( $lines, 'kind' ) );
check_same( 'the keys of a line', array( 'id', 'invoice_id', 'kind', 'name', 'qty', 'unit_price', 'amount', 'product_id', 'variation_id', 'species_id', 'stay_id', 'size_slug', 'size_label', 'source', 'take_stock', 'sort' ), array_keys( $lines[0] ) );
check( 'a shop line: the product, the variation, the size, the stock tick', 501 === $lines[0]['product_id'] && 777 === $lines[0]['variation_id'] && 'medium' === $lines[0]['size_slug'] && 'Medium' === $lines[0]['size_label'] && 'product' === $lines[0]['source'] && true === $lines[0]['take_stock'] && 2 === $lines[0]['qty'] && 45.0 === $lines[0]['unit_price'] && 90.0 === $lines[0]['amount'] );
check( 'a tank line: its tank group AND its product — although it does not take from shop stock and the theme\'s JSON carries no id for it', 9 === $lines[1]['stay_id'] && 502 === $lines[1]['product_id'] && 'tank' === $lines[1]['source'] && false === $lines[1]['take_stock'] && null === $lines[1]['variation_id'] );
check( 'a species-list line: its species id', 31 === $lines[2]['species_id'] && 'species' === $lines[2]['source'] && null === $lines[2]['product_id'] );
check( 'a credit: "Credit: reason", negative; a note: no money; the Discount: negative', 'Credit: lost Test Cleaner Wrasse' === $lines[3]['name'] && -10.0 === $lines[3]['amount'] && 0.0 === $lines[4]['amount'] && null === $lines[4]['unit_price'] && 'Discount' === $lines[5]['name'] && -12.5 === $lines[5]['amount'] );
check( 'each line carries its row id and the invoice id', $id === $lines[0]['invoice_id'] && $lines[0]['id'] > 0 && 6 === count( array_unique( array_column( $lines, 'id' ) ) ) );
check_same( 'the amounts add up to the invoice\'s total', 150.0, array_sum( array_column( $lines, 'amount' ) ) );

$hand = fh_wc_product( array( 'name' => 'Tester Invoice (7/3/26)', 'description' => "<ul>\n<li>Hand Test Goby x2 — \$39</li>\n<li>Credit: lost Hand Test Wrasse — -\$10</li>\n<li>Ships Tuesday</li>\n</ul>", 'regular_price' => '68', 'category_ids' => array( $world['cat_invoices'] ) ) );
$lines = Invoices::lines_for_product( $hand, $store, $shop );
check( 'an invoice made by hand: its lines as the theme reads them, source "hand", no ids', 3 === count( $lines ) && array( 'hand', 'hand', 'hand' ) === array_column( $lines, 'source' ) && 'Hand Test Goby' === $lines[0]['name'] && 2 === $lines[0]['qty'] && 39.0 === $lines[0]['unit_price'] && 78.0 === $lines[0]['amount'] && 'credit' === $lines[1]['kind'] && -10.0 === $lines[1]['amount'] && 'note' === $lines[2]['kind'] && 0 === $lines[0]['id'] && null === $lines[0]['product_id'] && null === $lines[0]['stay_id'] && false === $lines[0]['take_stock'] );
$json_hand = fh_wc_product( array( 'name' => 'Other Invoice (7/4/26)', 'description' => '<ul><li>Ignored x1 — $1</li></ul>', 'regular_price' => '45', 'category_ids' => array( $world['cat_invoices'] ) ), array( Invoices::PRODUCT_LINES_META => '[{"name":"Json Test Hawkfish","qty":1,"price":45,"kind":"fish","product_id":888}]' ) );
$lines     = Invoices::lines_for_product( $json_hand, $store, $shop );
check( 'a hand-made one with the theme\'s JSON: those lines, and the product id the JSON names (which is what takes shop stock)', 1 === count( $lines ) && 'Json Test Hawkfish' === $lines[0]['name'] && 888 === $lines[0]['product_id'] && true === $lines[0]['take_stock'] );
$fish = fh_wc_product( array( 'name' => 'Blue Test Tang', 'description' => '<ul><li>Looks like a line x1 — $5</li></ul>', 'regular_price' => '45', 'category_ids' => array( $world['cat_fish'] ) ) );
check( 'a product that is not an invoice, and one that does not exist: an empty array', array() === Invoices::lines_for_product( $fish, $store, $shop ) && array() === Invoices::lines_for_product( 987654, $store, $shop ) );

echo "3. fishotel_invoices_for_order()\n";
$order = fh_wc_order(
	array( 'status' => 'processing', 'date_paid' => true, 'total' => 218, 'customer_id' => $world['customer'], 'billing_first_name' => 'Test', 'billing_last_name' => 'Person', 'billing_email' => 'billing@example.test' ),
	array(
		array( 'name' => 'Blue Test Tang (Medium)', 'qty' => 2, 'total' => 90, 'product_id' => 501, 'variation_id' => 777, 'meta' => array( Invoices::ITEM_FISH_META => 1, Invoices::ITEM_PRODUCT_META => $pid, Invoices::ITEM_TITLE_META => 'Tester Invoice (11/3/26)' ) ),
		array( 'name' => 'Red Test Goby', 'qty' => 1, 'total' => 12.5, 'meta' => array( Invoices::ITEM_FISH_META => 1, Invoices::ITEM_PRODUCT_META => $pid, Invoices::ITEM_TITLE_META => 'Tester Invoice (11/3/26)' ) ),
		array( 'name' => 'Tester Invoice (7/3/26)', 'qty' => 1, 'total' => 68, 'product_id' => $hand ),
		array( 'name' => 'Test Fish Food', 'qty' => 1, 'total' => 9, 'product_id' => 3001 ),
	)
);
Sync::apply( $store, $shop, $shop->order_brief( $order ) );

$found = Invoices::for_order( $order, $store, $shop );
check_same( 'the two invoices in the order — the shop product beside them is not one', array( $pid, $hand ), array_column( $found, 'product_id' ) );
check_same( 'the keys of an entry', array( 'id', 'product_id', 'made_by_hand', 'handle', 'forum_user_id', 'customer_id', 'customer_name', 'customer_email', 'state', 'title', 'total', 'flat_total', 'shipping', 'shipping_slug', 'order_id', 'url', 'lines' ), array_keys( $found[0] ) );
check( 'the one built here: its id, the customer, paid by this order, its lines with every id', $id === $found[0]['id'] && false === $found[0]['made_by_hand'] && 'Tester' === $found[0]['handle'] && 12456 === $found[0]['forum_user_id'] && $world['customer'] === $found[0]['customer_id'] && 'paid' === $found[0]['state'] && $order === $found[0]['order_id'] && 'Tester Invoice (11/3/26)' === $found[0]['title'] && 150.0 === $found[0]['total'] && 150.0 === $found[0]['flat_total'] && 'free' === $found[0]['shipping'] && 'free' === $found[0]['shipping_slug'] && 6 === count( $found[0]['lines'] ) && 9 === $found[0]['lines'][1]['stay_id'] && 'https://example.test/shop/invoices/tester-invoice-11-3-26/' === $found[0]['url'] );
check( 'the one made by hand: id 0, the handle read from its title, the lines of its description, the customer of the order', 0 === $found[1]['id'] && true === $found[1]['made_by_hand'] && 'hand' === $found[1]['state'] && 'Tester' === $found[1]['handle'] && 68.0 === $found[1]['total'] && 3 === count( $found[1]['lines'] ) && 'Test Person' === $found[1]['customer_name'] && 'billing@example.test' === $found[1]['customer_email'] && null === $found[1]['order_id'] );
check_same( 'the paid action told a listener exactly these two ids — and for_order() gives it the rest', 1, fh_fired( Invoices::PAID_ACTION, array( $id, $order ) ) );

$gone = fh_wc_order(
	array( 'status' => 'completed', 'date_paid' => true, 'total' => 33, 'billing_first_name' => 'Test', 'billing_last_name' => 'Person' ),
	array( array( 'name' => 'Gone Invoice (2/2/25)', 'qty' => 1, 'total' => 33, 'product_id' => 9998, 'meta' => array( Invoices::ITEM_LINES_META => '[{"name":"Snapshot Test Lionfish","qty":1,"price":33,"kind":"fish"}]', Invoices::ITEM_PRODUCT_META => 9998 ) ) )
);
$found = Invoices::for_order( $gone, $store, $shop );
check( 'a hand-made invoice whose product is gone: what the order line kept — the title and the lines snapshot', 1 === count( $found ) && 9998 === $found[0]['product_id'] && 'Gone Invoice (2/2/25)' === $found[0]['title'] && 'Gone' === $found[0]['handle'] && array( 'Snapshot Test Lionfish' ) === array_column( $found[0]['lines'], 'name' ) && '' === $found[0]['url'] );
$plain = fh_wc_order( array( 'status' => 'completed', 'date_paid' => true ), array( array( 'name' => 'Test Fish Food', 'qty' => 1, 'total' => 9, 'product_id' => 3001 ) ) );
check( 'an order without an invoice, and an order that does not exist: an empty array', array() === Invoices::for_order( $plain, $store, $shop ) && array() === Invoices::for_order( 987654, $store, $shop ) );

echo "4. The actions\n";
check_same( 'the names', array( 'fishotel_invoice_paid', 'fishotel_invoice_released' ), array( Invoices::PAID_ACTION, Invoices::RELEASED_ACTION ) );
$heard = array();
add_action(
	Invoices::PAID_ACTION,
	static function ( $invoice_id, $order_id ) use ( &$heard ) {
		$heard[] = array( 'paid', $invoice_id, $order_id );
	},
	10,
	2
);
add_action(
	Invoices::RELEASED_ACTION,
	static function ( $invoice_id, $order_id ) use ( &$heard ) {
		$heard[] = array( 'released', $invoice_id, $order_id );
	},
	10,
	2
);
fh_wc_order_set( $order, array( 'status' => 'cancelled' ) );
Sync::apply( $store, $shop, $shop->order_brief( $order ) );
fh_wc_order_set( $order, array( 'status' => 'completed' ) );
Sync::apply( $store, $shop, $shop->order_brief( $order ) );
check_same( 'a listener hears the release and the payment, each with two ints: the invoice id (this section\'s), then the order id', array( array( 'released', $id, $order ), array( 'paid', $id, $order ) ), $heard );

echo "5. The meta keys\n";
check_same( 'this section\'s mark on a product it created', '_fh_invoice_id', Invoices::PRODUCT_MARKER_META );
check_same( 'the theme\'s keys, read here (and the lines JSON written)', array( '_fh_invoice_lines', '_fh_invoice_order_id', '_fh_invoice_product_id', '_fh_invoice_title', '_fh_invoice_fish', '_fh_invoice_credit', '_fh_invoice_lines' ), array( Invoices::PRODUCT_LINES_META, Invoices::PRODUCT_ORDER_META, Invoices::ITEM_PRODUCT_META, Invoices::ITEM_TITLE_META, Invoices::ITEM_FISH_META, Invoices::ITEM_CREDIT_META, Invoices::ITEM_LINES_META ) );

echo "6. The section, as the Section Manager sees it\n";
$info = Invoices::get_section_info();
check( 'a name, a description and an icon', 'Invoices' === $info['name'] && strlen( $info['description'] ) > 50 && 0 === strpos( $info['icon'], 'dashicons-' ) );
foreach ( array( 'get_section_info', 'load', 'boot', 'register_menu', 'activate', 'deactivate' ) as $method ) {
	check( "a static $method()", method_exists( Invoices::class, $method ) && ( new ReflectionMethod( Invoices::class, $method ) )->isStatic() );
}
check_same( 'the menu slug holds "fishotel-misc", so the plugin\'s dark styles load on the page', 0, strpos( Invoices::MENU_SLUG, 'fishotel-misc' ) );
check_same( 'every screen and every write asks for manage_woocommerce', 'manage_woocommerce', Invoices::CAPABILITY );

fh_finish();
