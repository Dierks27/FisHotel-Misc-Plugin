<?php
/**
 * "Needs a 3rd bag (sharp spines or a scalpel)" — the plain tick that
 * replaces the word list for every fish with a product page — on invented
 * fish:
 *
 *   - "The word list applies to": every line (the old way, until the owner
 *     ticks the products once), only the lines with no product page, or
 *     none — the rule, the setting, its save;
 *   - on an order: an unticked tang with a product is ×2 ("no 3rd bag
 *     ticked on the product"), a ticked one ×3, a fish with no product page
 *     still found by the words, an invoice fish naming a product follows
 *     that product's tick;
 *   - the one-time helper: which products it lists, the click (who may,
 *     what it writes, that it switches the list to "no product page only");
 *   - the products list: the "3rd bag ticked" view, the tick's state for
 *     quick edit, quick edit and bulk edit;
 *   - the Fish essentials seam: the checkbox and the tick drawn by another
 *     box, and then not again in the Shipping tab.
 *
 * Every fish, product, order and word here is invented.
 *
 * Run from the plugin root:  php includes/sections/packing/tests/third-bag-test.php
 */

require_once __DIR__ . '/wp-stubs.php';

use FisHotel\Misc\Sections\Packing\Layers;
use FisHotel\Misc\Sections\Packing\Order_Box;
use FisHotel\Misc\Sections\Packing\Packing;
use FisHotel\Misc\Sections\Packing\Product;
use FisHotel\Misc\Sections\Packing\Settings;
use FisHotel\Misc\Sections\Packing\Third_Bag;

$words = Layers::default_words();

echo "1. The word list applies to…\n";
check_same( 'three choices, "no product page only" first', array( 'no_product', 'all', 'none' ), array_keys( Layers::scopes() ) );
check( 'anything else is no choice', '' === Layers::scope_in( 'some' ) && '' === Layers::scope_in( true ) && '' === Layers::scope_in( array( 'all' ) ) && 'none' === Layers::scope_in( ' none ' ) );
check_same( 'every line: a tang with a product page is triple-bagged by its word, as before', array( 'layers' => 3, 'why' => 'word:tang' ), Layers::decide( '', $words, 'Invented Yellow Tang', array(), true, 'all' ) );
check_same( 'no product page only: an unticked tang with a product is double-bagged — and the reason says no tick', array( 'layers' => 2, 'why' => 'no_tick' ), Layers::decide( '', $words, 'Invented Yellow Tang', array( 'Tangs' ), true, 'no_product' ) );
check_same( '— a fish with no product page is still found by the words', array( 'layers' => 3, 'why' => 'word:tang' ), Layers::decide( '', $words, 'Invented Yellow Tang', array(), false, 'no_product' ) );
check_same( '— and the tick wins either way', array( 'layers' => 3, 'why' => 'product' ), Layers::decide( '3', $words, 'Invented Goby', array(), true, 'no_product' ) );
check_same( 'none: no word triple-bags anything, a fish without a product is double', array( 'layers' => 2, 'why' => 'default' ), Layers::decide( '', $words, 'Invented Yellow Tang', array(), false, 'none' ) );
check_same( '— nor a fish with one', array( 'layers' => 2, 'why' => 'no_tick' ), Layers::decide( '', $words, 'Invented Yellow Tang', array(), true, 'none' ) );
check( 'a scope that is no choice is read as every line (the old way)', 3 === Layers::decide( '', $words, 'Invented Tang', array(), true, 'nope' )['layers'] );
check( 'decide() without the two new arguments works as it always did', 3 === Layers::decide( '', $words, 'Invented Tang' )['layers'] && 'default' === Layers::decide( '', $words, 'Invented Goby' )['why'] );
check_same( 'the reasons in words', array( 'Triple-bagged: ticked on the product (Needs a 3rd bag)', 'Double-bagged: set to Double on the product', 'Double-bagged: no 3rd bag ticked on the product' ), array( Layers::why_text( 3, 'product' ), Layers::why_text( 2, 'product' ), Layers::why_text( 2, 'no_tick' ) ) );
check( 'ticked() reads 3 only', Layers::ticked( '3' ) && Layers::ticked( 3 ) && ! Layers::ticked( '2' ) && ! Layers::ticked( '' ) && ! Layers::ticked( true ) );

echo "2. The setting\n";
fh_test_settings();
check( 'settings saved before the setting existed read "every line": nothing changes on the update, until the owner ticks the products', 'all' === Settings::get()['triple_scope'] && 'all' === Settings::defaults()['triple_scope'] );
check( 'a stored choice is kept; anything else reads as every line', 'no_product' === Settings::normalize( array( 'triple_scope' => 'no_product' ) )['triple_scope'] && 'all' === Settings::normalize( array( 'triple_scope' => 'x' ) )['triple_scope'] );
$nonce = wp_create_nonce( Settings::NONCE_ACTION );
fh_login( 7, array( 'manage_woocommerce' ) );
$out = Settings::words_request( array( '_wpnonce' => $nonce, 'triple_words' => implode( "\n", $words ), 'triple_scope' => 'sometimes' ) );
check( 'a choice that is not one is refused, and nothing is saved', 400 === $out['status'] && 'all' === Settings::get()['triple_scope'], $out['message'] );
$out = Settings::words_request( array( '_wpnonce' => $nonce, 'triple_words' => implode( "\n", $words ), 'triple_scope' => 'no_product' ) );
check( 'saved with the list, in the one option', 200 === $out['status'] && 'no_product' === Settings::get()['triple_scope'] && $words === Settings::get()['triple_words'] );
check( '— and the answer says the words reach only the fish with no product page', false !== strpos( $out['message'], 'only the fish with no product page' ), $out['message'] );
$out = Settings::words_request( array( '_wpnonce' => $nonce, 'triple_words' => implode( "\n", $words ) ) );
check( 'a save of the list alone (a page drawn before the setting) leaves the choice as it is', 200 === $out['status'] && 'no_product' === Settings::get()['triple_scope'] );
$out = Settings::words_request( array( '_wpnonce' => $nonce, 'triple_words' => 'tang', 'triple_scope' => 'none' ) );
check( '"Nothing": the answer says only the tick triple-bags', 'none' === Settings::get()['triple_scope'] && false !== strpos( $out['message'], 'only when its product has "Needs a 3rd bag" ticked' ), $out['message'] );
fh_test_settings();

echo "3. On an order\n";
fh_reset_shop();
fh_use_sizes( fh_test_sizes() );
$GLOBALS['fh_term_names'] = array( 'quarantined-fish' => 'Quarantined Fish' );
$tang = fh_product( new Fh_Fake_Product( 100, 'variable' ) );
fh_product( new Fh_Fake_Product( 103, 'variation', array( 'pa_size' => 'big' ), array(), 100 ) );
$goby = fh_product( new Fh_Fake_Product( 110, 'variable' ) );
fh_product( new Fh_Fake_Product( 111, 'variation', array( 'pa_size' => 'small' ), array(), 110 ) );
fh_product( new Fh_Fake_Product( 140, 'simple' ) );
$GLOBALS['fh_terms'] = array(
	100 => array( 'quarantined-fish' ),
	110 => array( 'quarantined-fish' ),
	140 => array( 'invoices' ),
);
$snapshot = wp_json_encode(
	array(
		array( 'name' => 'Invented Sailfin Tang', 'qty' => 1, 'kind' => 'fish', 'size' => 'mid' ),
		array( 'name' => 'Invented Yellow Tang', 'qty' => 1, 'kind' => 'fish', 'product_id' => 103 ),
	)
);
$items = array(
	new Fh_Fake_Item( 1, 'Invented Yellow Tang - Big', 1, 100, array(), 103 ),
	new Fh_Fake_Item( 2, 'Invented Clown Goby - Small', 2, 110, array(), 111 ),
	new Fh_Fake_Item( 3, 'Invented Foxface', 1, 0, array( '_fh_invoice_fish' => '1', 'pa_size' => 'mid' ) ),
	new Fh_Fake_Item( 4, 'Invoice for an invented customer', 1, 140, array( '_fh_invoice_lines' => $snapshot ) ),
);
foreach ( $items as $item ) {
	$item->order_id = 900;
}
fh_order( new Fh_Fake_Order( 900, $items ) );
Packing::set_enabled( true );

/**
 * "name → ×layers (why)" of every fish line.
 *
 * @param array $result fishotel_packing_suggest().
 * @return string[]
 */
function fh_third_lines( array $result ) {
	$out = array();
	foreach ( $result['lines'] as $line ) {
		$out[] = $line['name'] . ' → ×' . $line['layers'] . ' (' . $line['layers_why'] . ')';
	}
	return $out;
}

check_same(
	'every line (the old way): the words reach every fish',
	array( 'Invented Yellow Tang - Big → ×3 (word:tang)', 'Invented Clown Goby - Small → ×2 (default)', 'Invented Foxface → ×3 (word:foxface)', 'Invented Sailfin Tang → ×3 (word:tang)', 'Invented Yellow Tang → ×3 (word:tang)' ),
	fh_third_lines( fishotel_packing_suggest( 900 ) )
);
Settings::store( array_merge( Settings::get(), array( 'triple_scope' => 'no_product' ) ) );
check_same(
	'no product page only: an unticked tang with a product is ×2; the fish with no product page (a batch-invoice fish, an invoice row with no product) still by the words; the invoice row naming a product follows that product',
	array( 'Invented Yellow Tang - Big → ×2 (no_tick)', 'Invented Clown Goby - Small → ×2 (no_tick)', 'Invented Foxface → ×3 (word:foxface)', 'Invented Sailfin Tang → ×3 (word:tang)', 'Invented Yellow Tang → ×2 (no_tick)' ),
	fh_third_lines( fishotel_packing_suggest( 900 ) )
);
$tang->meta['_fh_bag_layers'] = '3';
check_same(
	'ticked on the product: ×3, on the order line and on the invoice row that names it',
	array( 'Invented Yellow Tang - Big → ×3 (product)', 'Invented Clown Goby - Small → ×2 (no_tick)', 'Invented Foxface → ×3 (word:foxface)', 'Invented Sailfin Tang → ×3 (word:tang)', 'Invented Yellow Tang → ×3 (product)' ),
	fh_third_lines( fishotel_packing_suggest( 900 ) )
);
unset( $tang->meta['_fh_bag_layers'] );
$html = Order_Box::render_body( wc_get_order( 900 ) );
check( 'the Packing box: an unticked tang is "×2" in plain text, and the hover says why', 1 === preg_match( '/<span class="fh-pack-box__layers" title="Double-bagged: no 3rd bag ticked on the product">(each )?×2<\/span>/', $html ), $html );
check( '— and the box\'s line says 3 bags come from the product', false !== strpos( $html, 'every fish is double-bagged; 3 bags when its product says so' ) );
$tang->meta['_fh_bag_layers'] = '3';
$html = Order_Box::render_body( wc_get_order( 900 ) );
check( 'a ticked tang: "×3 — spines", marked, "ticked on the product"', false !== strpos( $html, 'title="Triple-bagged: ticked on the product (Needs a 3rd bag)">×3 — spines</span>' ) );
unset( $tang->meta['_fh_bag_layers'] );
$plan = fishotel_packing_shipment_plan( 900 );
check( 'the shipment plan carries the same reason', in_array( 'no_tick', array_column( $plan['lines'], 'why' ), true ) );
fh_test_settings();

echo "4. The one-time helper\n";
$rows = array(
	array( 'id' => 1, 'name' => 'Invented Yellow Tang', 'status' => 'publish', 'packs' => '', 'layers' => '', 'categories' => array( 'Quarantined Fish' => 'quarantined-fish' ) ),
	array( 'id' => 2, 'name' => 'Invented Blue Hippo', 'status' => 'draft', 'packs' => '', 'layers' => '', 'categories' => array( 'Quarantined Fish' => 'quarantined-fish', 'Tangs & Surgeonfish' => 'tangs-surgeonfish' ) ),
	array( 'id' => 3, 'name' => 'Invented Lionfish', 'status' => 'publish', 'packs' => '', 'layers' => '3', 'categories' => array( 'Quarantined Fish' => 'quarantined-fish' ) ),
	array( 'id' => 4, 'name' => 'Invented Fox Tang', 'status' => 'publish', 'packs' => '', 'layers' => '2', 'categories' => array( 'Quarantined Fish' => 'quarantined-fish' ) ),
	array( 'id' => 5, 'name' => 'Tang Heaven Invented Food', 'status' => 'publish', 'packs' => '', 'layers' => '', 'categories' => array( 'Fish Food' => 'fish-food' ) ),
	array( 'id' => 6, 'name' => 'Invented Tang Pair', 'status' => 'publish', 'packs' => 'no', 'layers' => '', 'categories' => array( 'Quarantined Fish' => 'quarantined-fish' ) ),
	array( 'id' => 7, 'name' => 'Invented Coral Tang', 'status' => 'publish', 'packs' => 'yes', 'layers' => '', 'categories' => array( 'Corals' => 'corals' ) ),
	array( 'id' => 8, 'name' => 'Invented Tang Invoice', 'status' => 'publish', 'packs' => '', 'layers' => '', 'categories' => array( 'Invoices' => 'invoices', 'Quarantined Fish' => 'quarantined-fish' ) ),
	array( 'id' => 9, 'name' => 'Invented Clown Goby', 'status' => 'publish', 'packs' => '', 'layers' => '', 'categories' => array( 'Quarantined Fish' => 'quarantined-fish' ) ),
	array( 'id' => 10, 'name' => 'Invented Tangerine Goby', 'status' => 'publish', 'packs' => '', 'layers' => '', 'categories' => array( 'Quarantined Fish' => 'quarantined-fish' ) ),
);
$found = Third_Bag::matches( $rows, $words, array( 'quarantined-fish', 'inverts' ) );
check_same( 'it lists the products packed in a bag, with no choice yet, that a word finds — in the name or a category name; a fish ticked Livestock outside the fish categories too', array( 1, 2, 7 ), array_column( $found, 'id' ) );
check_same( '— each with the word that found it', array( 'tang', 'tang', 'tang' ), array_column( $found, 'word' ) );
check( 'left out: ticked already (3), set to Double (2), a food, Livestock unticked, an invoice, a fish no word finds, a word inside a longer word', array() === array_intersect( array( 3, 4, 5, 6, 8, 9, 10 ), array_column( $found, 'id' ) ) );
check( 'an empty list lists nothing', array() === Third_Bag::matches( $rows, array(), array( 'quarantined-fish' ) ) );
check_same( 'the ids a click posts: one comma-separated field, whole numbers, each once', array( 4, 7, 12 ), Third_Bag::ids_in( '4, 7,x,4,-2,12,0' ) );
check( '— a list too; nonsense is none', array( 5 ) === Third_Bag::ids_in( array( '5', 'five', array( 6 ) ) ) && array() === Third_Bag::ids_in( null ) && array() === Third_Bag::ids_in( true ) );
check( 'at most ' . Third_Bag::MAX_TICK . ' in one click', Third_Bag::MAX_TICK === count( Third_Bag::ids_in( implode( ',', range( 1, Third_Bag::MAX_TICK + 50 ) ) ) ) );

$store                   = new Fh_Fake_Store();
$store->third_candidates = $rows;
check_same( 'the review list reads the store and the fish categories', array( 1, 2, 7 ), array_column( ( new Third_Bag( $store ) )->review_list(), 'id' ) );

fh_reset_shop();
$one   = fh_product( new Fh_Fake_Product( 1, 'variable' ) );
$two   = fh_product( new Fh_Fake_Product( 2, 'simple' ) );
$three = fh_product( new Fh_Fake_Product( 3, 'simple', array(), array( '_fh_bag_layers' => '2' ) ) );
fh_product( new Fh_Fake_Product( 11, 'variation', array(), array(), 1 ) );
fh_login( 0 );
$out = Third_Bag::tick_request( array( '_wpnonce' => $nonce, 'ids' => '1,2' ) );
check( 'nobody logged in may not tick', 403 === $out['status'] && '' === $one->get_meta( '_fh_bag_layers' ) );
fh_login( 7, array( 'manage_woocommerce' ) );
$out = Third_Bag::tick_request( array( '_wpnonce' => 'nope', 'ids' => '1,2' ) );
check( 'nor a form with another nonce', 403 === $out['status'] && '' === $one->get_meta( '_fh_bag_layers' ) );
$out = Third_Bag::tick_request( array( '_wpnonce' => $nonce, 'ids' => '1,2,3,11,999' ) );
check( 'the click ticks the rows asked: 3 on each product, saved through the product object', 200 === $out['status'] && '3' === $one->meta['_fh_bag_layers'] && '3' === $two->meta['_fh_bag_layers'] && 1 === $one->meta_saves && 2 === $out['ticked'], fh_test_export( $out ) );
check( '— never a product set to Double since, a variation, or a product that is not there', '2' === $three->meta['_fh_bag_layers'] && array() === wc_get_product( 11 )->meta );
check( '— and the word list now reaches only the fish with no product page, and the answer says so', 'no_product' === Settings::get()['triple_scope'] && false !== strpos( $out['message'], 'Ticked "Needs a 3rd bag" on 2 products.' ) && false !== strpos( $out['message'], 'reaches only the fish with no product page' ), $out['message'] );
Settings::store( array_merge( Settings::get(), array( 'triple_scope' => 'none' ) ) );
$out = Third_Bag::tick_request( array( '_wpnonce' => $nonce, 'ids' => '1' ) );
check( 'a second click: nothing ticked twice, and a list the owner set to "Nothing" stays so', 0 === $out['ticked'] && 'none' === Settings::get()['triple_scope'] && 1 === $one->meta_saves );
fh_test_settings();
fh_reset_hooks();
( new Third_Bag() )->init();
try {
	$_POST = array( '_wpnonce' => $nonce, 'ids' => '2' );
	( new Third_Bag() )->ajax_tick();
	$reply = null;
} catch ( Fh_Test_Json $e ) {
	$reply = $e;
}
$_POST = array();
check( 'the AJAX handler only sends that answer', isset( $GLOBALS['fh_hooks']['wp_ajax_fishotel_packing_tick_third'] ) && $reply instanceof Fh_Test_Json && true === $reply->success && ! isset( $reply->data['ticked'] ) );

echo "5. The products list\n";
$list         = new Third_Bag( $store );
$store->third_count = 0;
check( 'no product ticked: no view', array( 'all' => 'All' ) === $list->add_view( array( 'all' => 'All' ) ) );
$store->third_count = 4;
$views = $list->add_view( array( 'all' => 'All' ) );
check( 'a view "3rd bag ticked (4)" after WooCommerce\'s own', array( 'all', 'fh_third_bag' ) === array_keys( $views ) && false !== strpos( $views['fh_third_bag'], '3rd bag ticked <span class="count">(4)</span>' ) && false !== strpos( $views['fh_third_bag'], 'post_type=product&fh_third_bag=1' ) );
fh_login( 8 );
check( '— not for a user who may not manage the shop', array( 'all' => 'All' ) === $list->add_view( array( 'all' => 'All' ) ) );
fh_login( 7, array( 'manage_woocommerce' ) );
$GLOBALS['fh_is_admin'] = true;
$GLOBALS['pagenow']     = 'edit.php';
$_GET                   = array( 'fh_third_bag' => '1' );
$vars                   = $list->filter_request( array( 'post_type' => 'product' ) );
check_same( 'the view lists the products with the tick', array( array( 'key' => '_fh_bag_layers', 'value' => '3' ) ), $vars['meta_query'] ?? null );
check( 'another list, or no view chosen, is left alone', array( 'post_type' => 'shop_order' ) === $list->filter_request( array( 'post_type' => 'shop_order' ) ) && ( function () use ( $list ) {
	$_GET = array();
	return array( 'post_type' => 'product' ) === $list->filter_request( array( 'post_type' => 'product' ) );
} )() );
$_GET                   = array();
$GLOBALS['fh_is_admin'] = false;

$ui = new Product( new Fh_Fake_Store() );
fh_reset_shop();
$ticked   = fh_product( new Fh_Fake_Product( 50, 'simple', array(), array( '_fh_bag_layers' => '3' ) ) );
$unticked = fh_product( new Fh_Fake_Product( 51, 'variable' ) );
ob_start();
$ui->list_column_data( 'name', 50 );
$ui->list_column_data( 'name', 51 );
$ui->list_column_data( 'price', 50 );
$cells = (string) ob_get_clean();
check( 'the Name cell carries the tick\'s state for quick edit — and no other cell', '<span class="hidden fh-third-bag-data" data-third="1"></span><span class="hidden fh-third-bag-data" data-third="0"></span>' === $cells, $cells );
ob_start();
$ui->render_quick_edit();
$quick = (string) ob_get_clean();
check( 'quick edit has the tick, and a "drawn as" field the script fills', false !== strpos( $quick, 'name="fh_third_bag" value="yes"' ) && false !== strpos( $quick, 'Needs a 3rd bag (sharp spines or a scalpel)' ) && false !== strpos( $quick, 'name="fh_third_bag_shown" value=""' ) );
ob_start();
$ui->render_bulk_edit();
$bulk = (string) ob_get_clean();
check( 'bulk edit: "3rd bag: — No change — / Yes / No"', 1 === preg_match( '/<span class="title">3rd bag<\/span>.*<select name="fh_third_bag_bulk"><option value="">— No change —<\/option><option value="yes">Yes[^<]*<\/option><option value="no">No<\/option><\/select>/', $bulk ), $bulk );

$_REQUEST = array( 'fh_third_bag_shown' => 'no', 'fh_third_bag' => 'yes' );
$ui->save_quick_edit( $unticked );
check( 'quick edit ticks', '3' === $unticked->meta['_fh_bag_layers'] && 1 === $unticked->meta_saves );
$_REQUEST = array( 'fh_third_bag_shown' => 'yes' );
$ui->save_quick_edit( $ticked );
check( 'quick edit unticks', ! isset( $ticked->meta['_fh_bag_layers'] ) );
$_REQUEST = array( 'fh_third_bag_shown' => '' );
$ui->save_quick_edit( $unticked );
check( 'quick edit whose script did not run changes nothing', '3' === $unticked->meta['_fh_bag_layers'] && 1 === $unticked->meta_saves );
$_REQUEST = array( 'fh_third_bag_bulk' => '' );
$ui->save_bulk_edit( $unticked );
check( 'bulk edit "— No change —" changes nothing', '3' === $unticked->meta['_fh_bag_layers'] && 1 === $unticked->meta_saves );
$_REQUEST = array( 'fh_third_bag_bulk' => 'no' );
$ui->save_bulk_edit( $unticked );
$_REQUEST = array( 'fh_third_bag_bulk' => 'yes' );
$ui->save_bulk_edit( $ticked );
check( 'bulk edit "No" unticks, "Yes" ticks', ! isset( $unticked->meta['_fh_bag_layers'] ) && '3' === $ticked->meta['_fh_bag_layers'] );
fh_login( 8 );
$_REQUEST = array( 'fh_third_bag_bulk' => 'no' );
$ui->save_bulk_edit( $ticked );
$_REQUEST = array( 'fh_third_bag_shown' => 'yes' );
$ui->save_quick_edit( $ticked );
check( 'a user who may not manage the shop changes nothing, in either', '3' === $ticked->meta['_fh_bag_layers'] );
ob_start();
$ui->render_quick_edit();
$ui->render_bulk_edit();
$ui->list_column_data( 'name', 50 );
check( '— and is shown none of it', '' === (string) ob_get_clean() );
fh_login( 7, array( 'manage_woocommerce' ) );
$_REQUEST = array();

echo "6. The Fish essentials seam\n";
$GLOBALS['fh_terms'] = array( 51 => array( 'quarantined-fish' ) );
Product::reset_drawn();
ob_start();
$drawn = $ui->render_essentials_fields( $unticked );
$top   = (string) ob_get_clean();
check( 'another box draws the Livestock checkbox and the tick, with this section\'s nonce', true === $drawn && false !== strpos( $top, 'id="fh_packs_in_bag"' ) && false !== strpos( $top, 'id="fh_third_bag"' ) && false !== strpos( $top, 'name="' . Product::NONCE_FIELD . '"' ) && strpos( $top, 'fh_packs_in_bag_field' ) < strpos( $top, 'fh_third_bag_field' ) );
check( '— the tick marked as shown only for a live fish', false !== strpos( $top, 'fh-third-bag-field fh-es-live-only' ) );
$GLOBALS['product_object'] = $unticked;
ob_start();
$ui->render_simple_field();
$tab = (string) ob_get_clean();
check( 'the Shipping tab then leaves the two out — each is on the form once — and keeps the Bag size', false === strpos( $tab, 'id="fh_packs_in_bag"' ) && false === strpos( $tab, 'id="fh_third_bag"' ) && false === strpos( $tab, 'fh-third-bag-top' ) && false !== strpos( $tab, 'name="fh_bag_size"' ) );
Product::reset_drawn();
ob_start();
$ui->render_simple_field();
$tab = (string) ob_get_clean();
check( 'on a screen without that box, the Shipping tab draws both', false !== strpos( $tab, 'id="fh_packs_in_bag"' ) && false !== strpos( $tab, 'id="fh_third_bag"' ) );
fh_login( 8 );
ob_start();
check( 'nothing for a user who may not manage the shop', false === $ui->render_essentials_fields( $unticked ) && '' === (string) ob_get_clean() );
fh_login( 7, array( 'manage_woocommerce' ) );
Product::reset_drawn();

fh_finish();
