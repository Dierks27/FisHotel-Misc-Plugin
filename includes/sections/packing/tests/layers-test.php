<?php
/**
 * Bag layers — "double for reg fish and triple for anything with sharp
 * spines or like a tang that has a scalpel" — on invented fish:
 *
 *   - the rule, by the numbers: the product's "Bagging" choice, else a word
 *     of the "Triple-bag these" list found WHOLE in the line's name or in
 *     a category name (any letter case, its plural too, never inside a
 *     longer word), else 2; the filter fishotel_packing_bag_layers;
 *   - the list of words: what it starts with, what a word is, the save
 *     (who may, what is refused, an empty list staying empty);
 *   - the "Bagging" field of the product editor and its save;
 *   - on an order: every fish line with its layers and why — a product, a
 *     fish with no product, the fish of an opened-up Invoice;
 *   - the Packing box in plain words ("×2", "×3 — spines", "14 bags to
 *     pull");
 *   - fishotel_packing_bag_for_item(): `layers` and `layers_label` added,
 *     `name` and `label` exactly as they were (the Batch plugin's pick list
 *     prints `name`).
 *
 * Every fish, product, order and word here is invented.
 *
 * Run from the plugin root:  php includes/sections/packing/tests/layers-test.php
 */

require_once __DIR__ . '/wp-stubs.php';

use FisHotel\Misc\Sections\Packing\Layers;
use FisHotel\Misc\Sections\Packing\Order_Box;
use FisHotel\Misc\Sections\Packing\Order_Packing;
use FisHotel\Misc\Sections\Packing\Packing;
use FisHotel\Misc\Sections\Packing\Product;
use FisHotel\Misc\Sections\Packing\Settings;

$words = Layers::default_words();

echo "1. The rule\n";
check_same( 'the list starts with the fish that have sharp spines or a scalpel', array( 'tang', 'surgeonfish', 'unicornfish', 'rabbitfish', 'foxface', 'lionfish', 'scorpionfish', 'triggerfish', 'angelfish', 'squirrelfish' ), $words );
check_same( 'a regular fish is double-bagged', array( 'layers' => 2, 'why' => 'default' ), Layers::decide( '', $words, 'Invented Clown Goby - Small' ) );
check_same( 'a tang is triple-bagged, and the answer says which word', array( 'layers' => 3, 'why' => 'word:tang' ), Layers::decide( '', $words, 'Invented Yellow Tang - Medium' ) );
check( 'whatever the letter case', 3 === Layers::decide( '', $words, 'INVENTED YELLOW TANG' )['layers'] && 3 === Layers::decide( '', array( 'Tang' ), 'invented yellow tang' )['layers'] );
check( 'the plural counts as its word: "Tangs", "Foxfaces", "Lionfishes"', 'word:tang' === Layers::decide( '', $words, 'Assorted Tangs' )['why'] && 'word:foxface' === Layers::decide( '', $words, 'Two foxfaces' )['why'] && 'word:lionfish' === Layers::decide( '', $words, 'Lionfishes (pair)' )['why'] );
check( 'a word typed as its plural finds the one fish too: "tangs" finds "Yellow Tang", "triggers" finds "Niger Trigger", "foxes" finds "Fox Face"', 'word:tangs' === Layers::decide( '', array( 'tangs' ), 'Invented Yellow Tang' )['why'] && 'word:triggers' === Layers::decide( '', array( 'triggers' ), 'Invented NIGER TRIGGER - Medium' )['why'] && 'word:foxes' === Layers::decide( '', array( 'foxes' ), 'Invented Fox Face' )['why'] && 3 === Layers::decide( '', array( 'blue tangs' ), 'Powder Blue Tang' )['layers'] );
check( '— and still never a longer word: "tangs" leaves Tangerine and Mustang alone', 2 === Layers::decide( '', array( 'tangs' ), 'Invented Tangerine Skunk Clown' )['layers'] && 2 === Layers::decide( '', array( 'tangs' ), 'Mustang Goby' )['layers'] );
check_same( 'how a listed word is looked for: itself, and without the "s" or "es" it was typed with', array( array( 'tang' ), array( 'tangs', 'tang' ), array( 'foxes', 'foxe', 'fox' ), array( 'bass' ), array( 'gas' ), array() ), array( Layers::stems( 'tang' ), Layers::stems( 'tangs' ), Layers::stems( 'foxes' ), Layers::stems( 'bass' ), Layers::stems( 'gas' ), Layers::stems( '' ) ) );
check( 'a word inside a longer word does not: Tangerine, Mustang, Tangaroa', 2 === Layers::decide( '', $words, 'Invented Tangerine Skunk Clown' )['layers'] && 2 === Layers::decide( '', $words, 'Mustang Goby' )['layers'] && 2 === Layers::decide( '', $words, 'Tangaroa Goby' )['layers'] );
check( 'punctuation around a word is no letter: "Tang-Medium", "(tang)", "tang."', 3 === Layers::decide( '', $words, 'Yellow Tang-Medium' )['layers'] && 3 === Layers::decide( '', $words, 'Yellow (tang)' )['layers'] && 3 === Layers::decide( '', $words, 'One tang.' )['layers'] );
check_same( 'a category name is read like the name: a "Tangs & Surgeonfish" fish that does not say so itself', array( 'layers' => 3, 'why' => 'word:tang' ), Layers::decide( '', $words, 'Invented Blue Hippo - Small', array( 'Quarantined Fish', 'Tangs & Surgeonfish' ) ) );
check( 'the first word of the list that is found is the one named', 'word:surgeonfish' === Layers::decide( '', array( 'surgeonfish', 'tang' ), 'Invented Tang (a surgeonfish)' )['why'] );
check_same( 'the product\'s own choice comes first: Triple on a fish no word knows', array( 'layers' => 3, 'why' => 'product' ), Layers::decide( '3', $words, 'Invented Spiny Goby' ) );
check_same( '— and Double on a tang: the owner said so', array( 'layers' => 2, 'why' => 'product' ), Layers::decide( '2', $words, 'Invented Yellow Tang' ) );
check( 'a stored value that is neither is Auto: 1, 4, "triple", true, an array', 'word:tang' === Layers::decide( '1', $words, 'Invented Tang' )['why'] && 'default' === Layers::decide( '4', $words, 'Invented Goby' )['why'] && 'default' === Layers::decide( 'triple', $words, 'Invented Goby' )['why'] && 'default' === Layers::decide( true, $words, 'Invented Goby' )['why'] && 'default' === Layers::decide( array( '3' ), $words, 'Invented Goby' )['why'] );
check( 'an empty list triple-bags nothing by its name', 2 === Layers::decide( '', array(), 'Invented Yellow Tang' )['layers'] );
check( 'a phrase can be a word of the list: "powder blue"', 'word:powder blue' === Layers::decide( '', array( 'powder blue' ), 'Invented Powder Blue - Large' )['why'] && 2 === Layers::decide( '', array( 'powder blue' ), 'Invented Powder Brown' )['layers'] );
check( 'a name that is not text, or not UTF-8, is read without a warning', 2 === Layers::decide( '', $words, null )['layers'] && 3 === Layers::decide( '', $words, "Invented \xFF Tang" )['layers'] && 2 === Layers::decide( '', $words, "Invented \xFF Tangerine" )['layers'] );
check( 'a word with a character that means something in a pattern is taken as typed', 'word:a/b+' === Layers::decide( '', array( 'a/b+' ), 'Size a/b+ fish' )['why'] );

echo "2. The list of words\n";
check_same( 'typed one per line or with commas; capitals, blanks and repeats cleaned away', array( 'tang', 'blue angel', 'foxface' ), Layers::words_in( " Tang \n\nBlue   Angel, foxface ,TANG;\r\n" ) );
check( 'a single letter, a row of dashes and a very long entry are not words', '' === Layers::word_in( 'x' ) && '' === Layers::word_in( '---' ) && '' === Layers::word_in( str_repeat( 'a', 41 ) ) && 'ab' === Layers::word_in( 'AB' ) && '' === Layers::word_in( array( 'tang' ) ) && '' === Layers::word_in( true ) );
check( 'markup is not kept', 'tang' === Layers::word_in( '<b>Tang</b>' ) );
check( 'at most ' . Layers::MAX_WORDS . ' words are kept', Layers::MAX_WORDS === count( Layers::words_in( implode( ',', array_map( static function ( $n ) { return 'word' . $n; }, range( 1, 80 ) ) ) ) ) );

check( 'nothing saved yet: the settings carry the starting list', $words === Settings::get()['triple_words'] && $words === Settings::defaults()['triple_words'] );
$settings = fh_test_settings();
check( 'settings saved before the list existed read with the starting list', $words === $settings['triple_words'] );
check( 'a saved list is kept as saved — an empty one stays empty', array() === Settings::normalize( array( 'triple_words' => array() ) )['triple_words'] && array( 'eel' ) === Settings::normalize( array( 'triple_words' => array( 'Eel', 'eel', 7 ) ) )['triple_words'] && $words === Settings::normalize( array( 'triple_words' => 12 ) )['triple_words'] );

$nonce = wp_create_nonce( Settings::NONCE_ACTION );
$out   = Settings::words_request( array( '_wpnonce' => $nonce, 'triple_words' => 'eel' ) );
check( 'nobody logged in may not save the list', 403 === $out['status'] && $words === Settings::get()['triple_words'] );
fh_login( 7, array( 'manage_woocommerce' ) );
$out = Settings::words_request( array( '_wpnonce' => 'nope', 'triple_words' => 'eel' ) );
check( 'nor a form with another nonce', 403 === $out['status'] && $words === Settings::get()['triple_words'] );
$out = Settings::words_request( array( '_wpnonce' => $nonce, 'triple_words' => "tang\nx\nlionfish" ) );
check( 'one entry that is not a word and nothing is saved — it is named', 400 === $out['status'] && false !== strpos( $out['message'], '"x" is not a word' ) && $words === Settings::get()['triple_words'], $out['message'] );
$out = Settings::words_request( array( '_wpnonce' => $nonce, 'triple_words' => array( 'a' => array( 'b' ) ) ) );
check( 'a list that is not text is refused', 400 === $out['status'] && $words === Settings::get()['triple_words'], $out['message'] );
$writes = count( $GLOBALS['fh_option_writes'] );
$out    = Settings::words_request( array( '_wpnonce' => $nonce, 'triple_words' => "Tang\nLionfish, Invented Eel\n" ) );
check( 'a good list is saved, in the one option, without autoload', 200 === $out['status'] && array( 'tang', 'lionfish', 'invented eel' ) === Settings::get()['triple_words'] && $writes + 1 === count( $GLOBALS['fh_option_writes'] ) && array( Packing::OPTION_KEY, false ) === end( $GLOBALS['fh_option_writes'] ), $out['message'] );
check( '— and the answer says what it does', false !== strpos( $out['message'], '3 words' ) && false !== strpos( $out['message'], 'triple-bagged' ) );
check( 'the bags, the boxes and the numbers are still there', 4 === count( Settings::get()['bags'] ) && 3 === count( Settings::get()['boxes'] ) && 3 === count( Settings::get()['fits'] ) );
$out = Settings::words_request( array( '_wpnonce' => $nonce, 'triple_words' => '' ) );
check( 'an empty box is a list too: nothing is triple-bagged by its name, and it stays that way', 200 === $out['status'] && array() === Settings::get()['triple_words'] && false !== strpos( $out['message'], 'no words' ) );
fh_reset_hooks();
( new Settings() )->init();
try {
	$_POST = array( '_wpnonce' => $nonce, 'triple_words' => implode( "\n", $words ) );
	( new Settings() )->ajax_words();
	$reply = null;
} catch ( Fh_Test_Json $e ) {
	$reply = $e;
}
$_POST = array();
check( 'the AJAX handler only sends that answer', isset( $GLOBALS['fh_hooks']['wp_ajax_fishotel_packing_words'] ) && $reply instanceof Fh_Test_Json && true === $reply->success && $words === Settings::get()['triple_words'] && ! isset( $reply->data['settings'] ) );
fh_reset_hooks();

echo "3. The product editor: Bagging\n";
check_same( 'Triple on a product with no choice is stored', array( 'action' => 'set', 'value' => '3' ), Product::decide_layers( '3', '' ) );
check_same( 'Double too', array( 'action' => 'set', 'value' => '2' ), Product::decide_layers( '2', '' ) );
check_same( 'Auto on a product with a choice removes it: Auto stores nothing', array( 'action' => 'delete', 'value' => '' ), Product::decide_layers( '', '3' ) );
check_same( 'Auto on a product with none changes nothing', array( 'action' => 'keep', 'value' => '' ), Product::decide_layers( '', '' ) );
check_same( 'the choice it already has changes nothing', array( 'action' => 'keep', 'value' => '3' ), Product::decide_layers( '3', '3' ) );
check( 'a save without the field (quick edit, another screen) or with nonsense changes nothing', 'keep' === Product::decide_layers( null, '3' )['action'] && 'keep' === Product::decide_layers( '7', '3' )['action'] && 'keep' === Product::decide_layers( array( '2' ), '' )['action'] && 'keep' === Product::decide_layers( true, '' )['action'] );

fh_reset_shop();
fh_use_sizes( fh_test_sizes() );
$GLOBALS['fh_term_names'] = array(
	'quarantined-fish'  => 'Quarantined Fish',
	'tangs-surgeonfish' => 'Tangs & Surgeonfish',
);
$parent = fh_product( new Fh_Fake_Product( 100, 'variable' ) );
fh_product( new Fh_Fake_Product( 101, 'variation', array( 'pa_size' => 'small' ), array(), 100 ) );
fh_product( new Fh_Fake_Product( 102, 'variation', array( 'pa_size' => 'mid' ), array(), 100 ) );
fh_product( new Fh_Fake_Product( 103, 'variation', array( 'pa_size' => 'big' ), array(), 100 ) );
$hippo = fh_product( new Fh_Fake_Product( 200, 'variable' ) );
fh_product( new Fh_Fake_Product( 201, 'variation', array( 'pa_size' => 'mid' ), array(), 200 ) );
fh_product( new Fh_Fake_Product( 120, 'simple' ) );
fh_product( new Fh_Fake_Product( 140, 'simple' ) );
$GLOBALS['fh_terms'] = array(
	100 => array( 'quarantined-fish' ),
	200 => array( 'quarantined-fish', 'tangs-surgeonfish' ),
	120 => array( 'dry-goods' ),
	140 => array( 'invoices' ),
);

$ui                        = new Product( new Fh_Fake_Store() );
$GLOBALS['product_object'] = $parent;
ob_start();
$ui->render_simple_field();
$html = (string) ob_get_clean();
check( 'the Shipping tab draws "Bagging" with Auto, Double and Triple — Auto chosen while nothing is stored', 1 === preg_match( '/<label for="fh_bag_layers">Bagging<\/label><select id="fh_bag_layers" name="fh_bag_layers"><option value="" selected="selected">Auto[^<]*<\/option><option value="2">Double[^<]*<\/option><option value="3">Triple[^<]*<\/option><\/select>/', $html ), $html );
check( 'Auto says what it comes to for this fish: double here', false !== strpos( $html, '>Auto — double for this fish</option>' ), $html );
$GLOBALS['product_object'] = $hippo;
ob_start();
$ui->render_simple_field();
$hippo_html = (string) ob_get_clean();
check( '— and triple, with the word that says so, for a fish in "Tangs & Surgeonfish"', false !== strpos( $hippo_html, '>Auto — triple for this fish (“tang” is on the list)</option>' ), $hippo_html );
$GLOBALS['product_object'] = new Fh_Fake_Product( 990, 'simple' );
ob_start();
$ui->render_simple_field();
check( 'a product with no name and no category yet: plain "Auto"', false !== strpos( (string) ob_get_clean(), '>Auto</option>' ) );
$GLOBALS['product_object'] = $parent;
check( 'it sits right under "Livestock — packed in a bag", on simple and variable products, and hides with the Bag size fields', strpos( $html, 'fh_packs_in_bag_shown' ) < strpos( $html, 'fh_bag_layers_field' ) && strpos( $html, 'fh_bag_layers_field' ) < strpos( $html, 'fh_bag_size_field' ) && false !== strpos( $html, 'fh_bag_layers_field show_if_simple show_if_variable fh-bag-size-field' ) );
check( 'a user who may not manage the shop is shown none of it', ( function () use ( $ui ) {
	fh_login( 0 );
	ob_start();
	$ui->render_simple_field();
	$out = (string) ob_get_clean();
	fh_login( 7 );
	return '' === $out;
} )() );

$fh_save = static function ( $product, array $post ) use ( $ui ) {
	$_POST = $post;
	$ui->save_product( $product );
	$_POST = array();
};
$pnonce = wp_create_nonce( Product::NONCE_ACTION );
$fh_save( $parent, array( Product::NONCE_FIELD => $pnonce, 'fh_bag_layers' => '3' ) );
check( 'saving Triple writes the one hidden meta on the product object — WooCommerce saves it', '3' === $parent->get_meta( '_fh_bag_layers' ) && '_fh_bag_layers' === Packing::META_LAYERS && 0 === $parent->meta_saves && 0 === $parent->full_saves );
$parent->save();
check( 'the choice is on the parent product, never on a variation', '3' === $parent->meta['_fh_bag_layers'] && array() === wc_get_product( 101 )->meta && '3' === Layers::stored( wc_get_product( 101 ) ) && '3' === Layers::stored( $parent ) );
ob_start();
$ui->render_simple_field();
$html = (string) ob_get_clean();
check( 'the field then shows Triple', false !== strpos( $html, '<option value="3" selected="selected">' ) );
$fh_save( $parent, array( Product::NONCE_FIELD => 'nope', 'fh_bag_layers' => '2' ) );
check( 'another nonce saves nothing', '3' === $parent->get_meta( '_fh_bag_layers' ) );
$fh_save( $parent, array( 'fh_bag_layers' => '2' ) );
check( 'no nonce saves nothing', '3' === $parent->get_meta( '_fh_bag_layers' ) );
$fh_save( $parent, array( Product::NONCE_FIELD => $pnonce ) );
check( 'a save that does not carry the field leaves the choice alone', '3' === $parent->get_meta( '_fh_bag_layers' ) );
fh_login( 8 );
$fh_save( $parent, array( Product::NONCE_FIELD => $pnonce, 'fh_bag_layers' => '2' ) );
check( 'a user who may not manage the shop saves nothing', '3' === $parent->get_meta( '_fh_bag_layers' ) );
fh_login( 7 );
$variation = wc_get_product( 101 );
$fh_save( $variation, array( Product::NONCE_FIELD => $pnonce, 'fh_bag_layers' => '2' ) );
check( 'a variation is never given one', array() === $variation->meta && null === $variation->pending );
$fh_save( $parent, array( Product::NONCE_FIELD => $pnonce, 'fh_bag_layers' => '' ) );
$parent->save();
check( 'back to Auto removes the meta: Auto stores nothing', ! array_key_exists( '_fh_bag_layers', $parent->meta ) && '' === Layers::stored( $parent ) );

echo "4. On an order\n";
/**
 * An order whose lines know it.
 *
 * @param int   $id    Order ID.
 * @param array $items Fh_Fake_Item list.
 * @param array $meta  Order meta.
 * @return Fh_Fake_Order
 */
function fh_layers_order( $id, array $items, array $meta = array() ) {
	foreach ( $items as $item ) {
		$item->order_id = (int) $id;
	}
	return fh_order( new Fh_Fake_Order( $id, $items, $meta ) );
}
/**
 * "name → ×layers (why)" of every fish line.
 *
 * @param array $result fishotel_packing_suggest().
 * @return string[]
 */
function fh_layer_lines( array $result ) {
	$out = array();
	foreach ( $result['lines'] as $line ) {
		$out[] = $line['name'] . ' → ×' . $line['layers'] . ' (' . $line['layers_why'] . ')';
	}
	return $out;
}

Packing::set_enabled( true );
$snapshot = wp_json_encode(
	array(
		array( 'name' => 'Invented Dwarf Lionfish', 'qty' => 1, 'kind' => 'fish', 'size' => 'mid' ),
		array( 'name' => 'Invented Clown Goby', 'qty' => 2, 'kind' => 'fish', 'size' => 'small' ),
		array( 'name' => 'Invented Hippo', 'qty' => 1, 'kind' => 'fish', 'product_id' => 201 ),
		array( 'name' => 'Credit for a lost fish', 'qty' => 1, 'kind' => 'credit' ),
	)
);
fh_layers_order(
	800,
	array(
		new Fh_Fake_Item( 1, 'Invented Yellow Tang - Big', 2, 100, array(), 103 ),
		new Fh_Fake_Item( 2, 'Invented Clown Goby - Small', 3, 100, array(), 101 ),
		new Fh_Fake_Item( 3, 'Invented Blue Hippo - Mid', 1, 200, array(), 201 ),
		new Fh_Fake_Item( 4, 'Reef Salt 5 lb', 1, 120 ),
		new Fh_Fake_Item( 5, 'Invented Foxface', 1, 0, array( '_fh_invoice_fish' => '1', 'pa_size' => 'mid' ) ),
		new Fh_Fake_Item( 6, 'Invoice for an invented customer', 1, 140, array( '_fh_invoice_lines' => $snapshot, 'pa_size' => 'mid' ) ),
	)
);
$result = fishotel_packing_suggest( 800 );
check_same(
	'every fish line says its layers and why: a word in its name, nothing, a word in its category, a fish with no product, the fish of an opened-up Invoice (by their names, and by their product\'s category)',
	array(
		'Invented Yellow Tang - Big → ×3 (word:tang)',
		'Invented Clown Goby - Small → ×2 (default)',
		'Invented Blue Hippo - Mid → ×3 (word:tang)',
		'Invented Foxface → ×3 (word:foxface)',
		'Invented Dwarf Lionfish → ×3 (word:lionfish)',
		'Invented Clown Goby → ×2 (default)',
		'Invented Hippo → ×3 (word:tang)',
	),
	fh_layer_lines( $result )
);
$fh_pull = 0;
foreach ( $result['lines'] as $line ) {
	$fh_pull += $line['bags'] * $line['layers'];
}
check( 'the bags to pull: every bag in its layers — 2×3 + 3×2 + 1×3 + 1×3 + 1×3 + 2×2 + 1×3 = 28, and by size they add up to it', 28 === $fh_pull && 28 === $result['pull_total'] && 28 === array_sum( array_column( $result['bags_pull'], 'count' ) ) && array_column( $result['bags_used'], 'id' ) === array_column( $result['bags_pull'], 'id' ), fh_test_export( $result['bags_pull'] ) );
check( 'each line says where its bags are: one box here, all of the line\'s bags in it', array( 0 ) === array_keys( $result['lines'][0]['in_boxes'] ) && 2 === array_sum( $result['lines'][0]['in_boxes'][0] ) && 3 === array_sum( $result['lines'][1]['in_boxes'][0] ) );
check( 'the unavailable answer has the same keys', array_keys( $result ) === array_keys( Packing::unavailable( 800, 'disabled' ) ) && 0 === Packing::unavailable( 800, 'disabled' )['pull_total'] );

$parent->meta['_fh_bag_layers'] = '2';
wc_get_product( 200 )->meta['_fh_bag_layers'] = '2';
check_same( 'Double set on the product beats the word in its name and in its category — also for the fish an Invoice names by that product', array( 'Invented Yellow Tang - Big → ×2 (product)', 'Invented Clown Goby - Small → ×2 (product)', 'Invented Blue Hippo - Mid → ×2 (product)', 'Invented Foxface → ×3 (word:foxface)', 'Invented Dwarf Lionfish → ×3 (word:lionfish)', 'Invented Clown Goby → ×2 (default)', 'Invented Hippo → ×2 (product)' ), fh_layer_lines( fishotel_packing_suggest( 800 ) ) );
$parent->meta['_fh_bag_layers'] = '3';
unset( wc_get_product( 200 )->meta['_fh_bag_layers'] );
check( 'Triple set on the product reaches every variation of it', array( 'Invented Yellow Tang - Big → ×3 (product)', 'Invented Clown Goby - Small → ×3 (product)' ) === array_slice( fh_layer_lines( fishotel_packing_suggest( 800 ) ), 0, 2 ) );
unset( $parent->meta['_fh_bag_layers'] );

$GLOBALS['fh_term_names'] = array();
check( 'a category with no name to read is read by its slug, the hyphens as spaces', 'Invented Blue Hippo - Mid → ×3 (word:tang)' === fh_layer_lines( fishotel_packing_suggest( 800 ) )[2] );
$GLOBALS['fh_term_names'] = array( 'tangs-surgeonfish' => 'Tangs & Surgeonfish' );

$seen = array();
add_filter(
	'fishotel_packing_bag_layers',
	static function ( $layers, $product, $name, $item ) use ( &$seen ) {
		$seen[] = array( $layers, is_object( $product ) ? $product->get_id() : 0, $name, is_object( $item ) ? $item->get_id() : 0 );
		if ( false !== strpos( $name, 'Clown Goby - Small' ) ) {
			return 3;
		}
		if ( false !== strpos( $name, 'Foxface' ) ) {
			return '2';
		}
		return false !== strpos( $name, 'Hippo' ) ? 9 : $layers;
	},
	10,
	4
);
$lines = fh_layer_lines( fishotel_packing_suggest( 800 ) );
check( 'the filter fishotel_packing_bag_layers overrules, and the line says so: 3 for a goby, 2 for a foxface', 'Invented Clown Goby - Small → ×3 (filter)' === $lines[1] && 'Invented Foxface → ×2 (filter)' === $lines[3], fh_test_export( $lines ) );
check( 'an answer that is neither 2 nor 3 is ignored, and one that changes nothing keeps its reason', 'Invented Blue Hippo - Mid → ×3 (word:tang)' === $lines[2] && 'Invented Yellow Tang - Big → ×3 (word:tang)' === $lines[0] );
check( 'the filter is handed the layers, the product, the name and the order line (none for a fish of an Invoice)', array( 3, 103, 'Invented Yellow Tang - Big', 1 ) === $seen[0] && array( 3, 0, 'Invented Dwarf Lionfish', 0 ) === $seen[4], fh_test_export( $seen ) );
fh_reset_hooks();

echo "5. The Packing box, in plain words\n";
fh_layers_order(
	801,
	array(
		new Fh_Fake_Item( 11, 'Invented Yellow Tang - Big', 2, 100, array(), 103 ),
		new Fh_Fake_Item( 12, 'Invented Clown Goby - Small', 4, 100, array(), 101 ),
	)
);
$html   = Order_Box::render_body( wc_get_order( 801 ) );
$result = fishotel_packing_suggest( 801 );
check( 'a tang\'s line says ×3 — spines, in its own mark, with the reason behind it', 1 === preg_match( '/<span class="fh-pack-box__layers is-triple" title="Triple-bagged: “tang” is on the Triple-bag list \(Packing → Bags\)">×3 — spines<\/span>/', $html ), $html );
check( 'a regular fish\'s line says ×2', 1 === preg_match( '/<span class="fh-pack-box__layers" title="Double-bagged: every fish is, unless it has spines">(each )?×2<\/span>/', $html ) );
check( 'the layers stand right after the bag to use', 1 === preg_match( '/<strong class="fh-pack-box__use">[^<]+<\/strong>\s*<span class="fh-pack-box__layers/', $html ) );
check( 'and the box adds them up: 2 × 3 + 4 × 2 = "14 bags to pull", by bag size', 14 === $result['pull_total'] && false !== strpos( $html, '<strong>14 bags to pull</strong>' ) && false !== strpos( $html, 'every fish is double-bagged, triple-bagged for spines' ), $html );
check( 'the label of a line is ready to print', array( 'Large ×3 — spines' ) === array_values( array_unique( array( Layers::label( 'Large', 3 ) ) ) ) && 'Medium ×2' === Layers::label( 'Medium', 2 ) && '2 × Large + Medium, each ×2' === Layers::label( '2 × Large + Medium', 2, true ) && 'Large + Medium — ×2, ×3 for the spiny ones' === Layers::label( 'Large + Medium', 3, true, true ) );
foreach ( $result['lines'] as $line ) {
	if ( $line['layers_label'] !== Layers::label( $line['use_name'], $line['layers'], count( $line['use'] ) > 1 ) ) {
		check( 'every line carries that label', false, fh_test_export( $line ) );
	}
}
check( 'every line carries that label', '' !== $result['lines'][0]['layers_label'] && false !== strpos( $result['lines'][0]['layers_label'], '×3 — spines' ) );
fh_layers_order( 802, array( new Fh_Fake_Item( 21, 'Reef Salt 5 lb', 2, 120 ) ) );
check( 'an order with nothing to bag pulls no bags and says nothing of it', 0 === fishotel_packing_suggest( 802 )['pull_total'] && false === strpos( Order_Box::render_body( wc_get_order( 802 ) ), 'to pull' ) );
fh_layers_order( 803, array( new Fh_Fake_Item( 31, 'Invented Lionfish <i>Show</i>', 1, 100, array(), 0 ) ) );
$html = Order_Box::render_body( wc_get_order( 803 ) );
check( 'a fish with no bag size has no bag to count — it is still named as that, escaped', false !== strpos( $html, 'No bag size' ) && false === strpos( $html, '<i>Show</i>' ) && false === strpos( $html, 'to pull' ) && 3 === fishotel_packing_suggest( 803 )['lines'][0]['layers'] );

echo "6. fishotel_packing_bag_for_item()\n";
$items = wc_get_order( 801 )->get_items();
$tang  = fishotel_packing_bag_for_item( $items[11] );
$goby  = fishotel_packing_bag_for_item( $items[12] );
check( 'the answer gains layers and layers_label', 3 === $tang['layers'] && $tang['name'] . ' ×3 — spines' === $tang['layers_label'] && 2 === $goby['layers'] && false !== strpos( $goby['layers_label'], '×2' ), fh_test_export( array( $tang, $goby ) ) );
$before = array();
foreach ( fishotel_packing_suggest( 801 )['lines'] as $line ) {
	$before[ $line['item_id'] ] = array( $line['use_name'], Order_Packing::use_name( $line['use'], 'label' ) );
}
check( 'name and label are exactly the bag\'s, as before: no ×, no layers in them (the pick list prints name)', $before[11][0] === $tang['name'] && $before[11][1] === $tang['label'] && $before[12][0] === $goby['name'] && $before[12][1] === $goby['label'] && 0 === preg_match( '/×\d|spines/', $tang['name'] . $tang['label'] . $goby['name'] . $goby['label'] ) );
check( 'every key it had is still there', array() === array_diff( array( 'id', 'name', 'width', 'length', 'label', 'sort', 'source', 'size', 'size_label', 'min', 'upsized', 'chosen_by_hand', 'mixed', 'bags' ), array_keys( $tang ) ) );
$loose = new Fh_Fake_Item( 99, 'Invented Lionfish - Mid', 1, 100, array(), 102 );
$bag   = fishotel_packing_bag_for_item( $loose );
check( 'a line on no order has no plan: the smallest bag, as before, with its layers by the rule', is_array( $bag ) && 'Medium' === $bag['name'] && 'Medium (6 × 14 in)' === $bag['label'] && ! isset( $bag['min'] ) && 3 === $bag['layers'] && 'Medium ×3 — spines' === $bag['layers_label'], fh_test_export( $bag ) );
$salt = fishotel_packing_bag_for_item( wc_get_order( 800 )->get_items()[4] );
check( 'what is not packed in a bag still has none', null === $salt );
$whole = fishotel_packing_bag_for_item( wc_get_order( 800 )->get_items()[6] );
check( 'an Invoice kept as one line (one that carries a size), its fish not all bagged alike: the highest, and the label says both', is_array( $whole ) && 3 === $whole['layers'] && false !== strpos( $whole['layers_label'], '×2, ×3 for the spiny ones' ) && false === strpos( $whole['name'], '×2' ), fh_test_export( $whole ) );
Packing::set_enabled( false );
check( 'while the section is off there is no bag at all', null === fishotel_packing_bag_for_item( $items[11] ) );
Packing::set_enabled( true );

echo "7. Nothing is written\n";
$order_writes = array();
foreach ( array( 800, 801, 802, 803 ) as $id ) {
	$order_writes = array_merge( $order_writes, wc_get_order( $id )->writes() );
	foreach ( wc_get_order( $id )->get_items() as $item ) {
		$order_writes = array_merge( $order_writes, $item->writes );
	}
}
check( 'working the layers out wrote nothing to an order or an order line', array() === $order_writes );
$code = (string) file_get_contents( FISHOTEL_MISC_PATH . 'includes/sections/packing/class-layers.php' );
check( 'the rule\'s file writes nothing and asks no database: no option, no meta write, no $wpdb', 0 === preg_match( '/update_option|update_meta_data|delete_meta_data|->save\s*\(|\$wpdb|add_action|add_filter/', $code ) );

fh_finish();
