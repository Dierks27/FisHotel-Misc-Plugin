<?php
/**
 * The product editor — on invented products. The Bag size dropdown: its
 * options (what "not set" means for this variation right now, a removed
 * bag still shown), the rule of a save, who may save and with which nonce,
 * the two save handlers writing ONE hidden meta through the product
 * object, and that a variable product has none of its own. And the
 * "Livestock — packed in a bag" checkbox: its default (the fish
 * categories), a stored yes and no, a Bag size from before the checkbox,
 * the class that hides the Bag size dropdowns while it is off, and its
 * save — tick, untick, left as drawn, not on the form, a bad nonce, no
 * capability — onto the parent product only.
 *
 * Run from the plugin root:  php includes/sections/packing/tests/product-test.php
 */

require_once __DIR__ . '/wp-stubs.php';

use FisHotel\Misc\Sections\Packing\Bags;
use FisHotel\Misc\Sections\Packing\Livestock;
use FisHotel\Misc\Sections\Packing\Packing;
use FisHotel\Misc\Sections\Packing\Product;

$settings = fh_test_settings();
$bags     = $settings['bags'];
$nonce    = wp_create_nonce( Product::NONCE_ACTION );
fh_use_sizes( fh_test_sizes() );

fh_product( new Fh_Fake_Product( 100, 'variable', array( 'pa_size' => array( 'small', 'mid' ) ) ) );
fh_product( new Fh_Fake_Product( 101, 'variation', array( 'pa_size' => 'small' ), array(), 100 ) );
fh_product( new Fh_Fake_Product( 102, 'variation', array( 'pa_size' => 'mid' ), array( '_fh_bag_size' => '3' ), 100 ) );
fh_product( new Fh_Fake_Product( 103, 'variation', array( 'pa_size' => 'show' ), array( '_fh_bag_size' => '77' ), 100 ) );
fh_product( new Fh_Fake_Product( 110, 'simple', array( 'pa_size' => array( 'big' ) ) ) );
// A medication sold by size; a frag plug given a bag before the checkbox existed; two variable
// products outside the fish categories — one with a bag on a variation from back then, one without.
fh_product( new Fh_Fake_Product( 120, 'simple', array( 'pa_size' => array( 'small' ) ) ) );
fh_product( new Fh_Fake_Product( 130, 'simple', array(), array( '_fh_bag_size' => '1' ) ) );
fh_product( new Fh_Fake_Product( 140, 'variable', array( 'pa_size' => array( 'small', 'mid' ) ) ) );
fh_product( new Fh_Fake_Product( 141, 'variation', array( 'pa_size' => 'small' ), array( '_fh_bag_size' => '2' ), 140 ) );
fh_product( new Fh_Fake_Product( 142, 'variation', array( 'pa_size' => 'mid' ), array(), 140 ) );
fh_product( new Fh_Fake_Product( 150, 'variable', array( 'pa_size' => array( 'small' ) ) ) );
fh_product( new Fh_Fake_Product( 151, 'variation', array( 'pa_size' => 'small' ), array(), 150 ) );
$GLOBALS['fh_terms']    = array(
	100 => array( 'quarantined-fish' ),
	110 => array( 'inverts' ),
	120 => array( 'medication' ),
	140 => array( 'dry-goods' ),
	150 => array( 'dry-goods' ),
);
$GLOBALS['fh_term_ids'] = array(
	'quarantined-fish' => 31,
	'inverts'          => 32,
);

$editor = new Product( new Fh_Fake_Store() );

/**
 * The Shipping tab's fields as the editor draws them for a product.
 */
function fh_shipping_fields( Product $editor, $product_id ) {
	$GLOBALS['product_object'] = wc_get_product( $product_id );
	ob_start();
	$editor->render_simple_field();
	return (string) ob_get_clean();
}

/**
 * Whether the checkbox is drawn ticked, and the state posted beside it.
 */
function fh_box( $html ) {
	$ticked = 1 === preg_match( '/<input type="checkbox"[^>]*name="fh_packs_in_bag"[^>]*checked="checked"/', $html );
	$shown  = 1 === preg_match( '/name="fh_packs_in_bag_shown" value="(yes|no)"/', $html, $m ) ? $m[1] : '';
	return ( $ticked ? 'ticked' : 'unticked' ) . '/' . $shown;
}

echo "1. The options of the dropdown\n";
$options = Product::options( $bags, '', array( 'label' => 'Small (4 × 12 in)' ) );
check( '"not set" comes first and says which bag the size map gives right now', array( '', '1', '2', '3', '4' ) === array_map( 'strval', array_keys( $options ) ) && 'Not set — from the size map: Small (4 × 12 in)' === $options[''] );
check( 'then every bag of the list, by its name and dimensions, keyed by its id', 'Small (4 × 12 in)' === $options['1'] && 'Jumbo' === $options['4'] );
check( 'a size the map has no bag for, and a product with no size, say so', 'Not set — the size map has no bag for this size' === Product::options( $bags, '', null, true )[''] && 'Not set' === Product::options( $bags, '', null, false )[''] );
$options = Product::options( $bags, '77', null );
check( 'a stored value that names a removed bag is still an option, so it shows instead of silently changing', isset( $options['77'] ) && false !== strpos( $options['77'], 'removed' ) && 6 === count( $options ) );
check( 'with no bags defined the dropdown has only "not set"', array( '' ) === array_map( 'strval', array_keys( Product::options( array(), '', null, false ) ) ) );

echo "2. The rule of a save\n";
check( 'a bag of the list is set, as its id', array( 'action' => 'set', 'value' => '2' ) === Product::decide( '2', '', $bags ) && array( 'action' => 'set', 'value' => '2' ) === Product::decide( ' 2 ', '3', $bags ) );
check( '"not set" removes the value', array( 'action' => 'delete', 'value' => '' ) === Product::decide( '', '3', $bags ) );
check( 'nothing changed: nothing is written', 'keep' === Product::decide( '3', '3', $bags )['action'] && 'keep' === Product::decide( '', '', $bags )['action'] );
check( 'a removed bag that is posted back unchanged is kept as it is', array( 'action' => 'keep', 'value' => '77' ) === Product::decide( '77', '77', $bags ) );
check( 'a value that is not a bag of the list changes nothing', 'keep' === Product::decide( '99', '3', $bags )['action'] && 'keep' === Product::decide( 'large', '3', $bags )['action'] && 'keep' === Product::decide( '2; DROP', '', $bags )['action'] && 'keep' === Product::decide( '-2', '', $bags )['action'] );
check( 'the field was not on the form, or is not text: nothing changes', 'keep' === Product::decide( null, '3', $bags )['action'] && 'keep' === Product::decide( array( '2' ), '3', $bags )['action'] && 'keep' === Product::decide( true, '3', $bags )['action'] );

echo "3. Who may save\n";
fh_login( 0 );
check( 'nobody logged in: no', false === Product::may_save( array( Product::NONCE_FIELD => $nonce ) ) );
fh_login( 7, array( 'edit_products' ) );
check( 'a user who can edit products but not manage WooCommerce: no', false === Product::may_save( array( Product::NONCE_FIELD => $nonce ) ) );
fh_login( 5, array( 'manage_woocommerce' ) );
check( 'a shop manager with this section\'s nonce: yes', true === Product::may_save( array( Product::NONCE_FIELD => $nonce ) ) );
check( 'without the nonce, with another form\'s, or with one that is not text: no', false === Product::may_save( array() ) && false === Product::may_save( array( Product::NONCE_FIELD => wp_create_nonce( 'woocommerce_save_data' ) ) ) && false === Product::may_save( array( Product::NONCE_FIELD => array( $nonce ) ) ) );

echo "4. The dropdown on a variation\n";
ob_start();
$editor->render_variation_field( 4, array(), (object) array( 'ID' => 101 ) );
$html = (string) ob_get_clean();
check( 'a select named after the variation\'s place on the form', false !== strpos( $html, 'name="fh_bag_size_var[4]"' ) && false !== strpos( $html, 'id="fh_bag_size_var_4"' ) && false !== strpos( $html, '>Bag size</label>' ) );
check( 'nothing is set, and "not set" says what the map gives this variation: the bag of its size', false !== strpos( $html, '<option value="" selected="selected">Not set — from the size map: Small (4 × 12 in)</option>' ), $html );
check( 'it carries this section\'s nonce on every variation (WooCommerce posts only the variations that changed)', false !== strpos( $html, 'name="fh_bag_size_nonce" value="' . $nonce . '"' ) );
ob_start();
$editor->render_variation_field( 0, array(), (object) array( 'ID' => 102 ) );
$html = (string) ob_get_clean();
check( 'a variation with a Bag size of its own shows it selected', false !== strpos( $html, '<option value="3" selected="selected">Large (8 × 18 in)</option>' ) && false !== strpos( $html, 'from the size map: Medium' ) );
ob_start();
$editor->render_variation_field( 1, array(), (object) array( 'ID' => 103 ) );
$html = (string) ob_get_clean();
check( 'a removed bag is shown as such; the map has nothing for this size', false !== strpos( $html, '<option value="77" selected="selected">A bag that was removed (#77)</option>' ) && false !== strpos( $html, 'the size map has no bag for this size' ) );
fh_login( 7 );
ob_start();
$editor->render_variation_field( 4, array(), (object) array( 'ID' => 101 ) );
check( 'a user who cannot manage WooCommerce is not shown the field at all', '' === (string) ob_get_clean() );
fh_login( 5 );

echo "5. Saving a variation\n";
$_POST = array(
	'fh_bag_size_nonce' => $nonce,
	'fh_bag_size_var'   => array( 0 => '2', 3 => '', 5 => '99' ),
);
$editor->save_variation( 101, 0 );
check( 'the posted bag is stored as the hidden meta _fh_bag_size, through update_meta_data() + save_meta_data()', '2' === wc_get_product( 101 )->meta['_fh_bag_size'] && 1 === wc_get_product( 101 )->meta_saves && 0 === wc_get_product( 101 )->full_saves && '_fh_bag_size' === Packing::META_BAG );
check( 'nothing else on the variation changed', array( '_fh_bag_size' => '2' ) === wc_get_product( 101 )->meta && array( 'pa_size' => 'small' ) === wc_get_product( 101 )->attributes );
$editor->save_variation( 102, 3 );
check( '"not set" removes the meta', ! isset( wc_get_product( 102 )->meta['_fh_bag_size'] ) && 1 === wc_get_product( 102 )->meta_saves );
$editor->save_variation( 103, 5 );
check( 'a value that is not a bag of the list leaves the variation alone', '77' === wc_get_product( 103 )->meta['_fh_bag_size'] && 0 === wc_get_product( 103 )->meta_saves );
$editor->save_variation( 103, 9 );
check( 'a variation whose field was not posted is left alone', '77' === wc_get_product( 103 )->meta['_fh_bag_size'] && 0 === wc_get_product( 103 )->meta_saves );
$editor->save_variation( 101, 0 );
check( 'saving the same value again writes nothing', 1 === wc_get_product( 101 )->meta_saves );
$editor->save_variation( 999, 0 );
check( 'a variation that does not exist is not a fatal error', true );

$_POST['fh_bag_size_var'] = array( 0 => '4' );
unset( $_POST['fh_bag_size_nonce'] );
$editor->save_variation( 101, 0 );
check( 'without this section\'s nonce nothing is saved', '2' === wc_get_product( 101 )->meta['_fh_bag_size'] && 1 === wc_get_product( 101 )->meta_saves );
$_POST['fh_bag_size_nonce'] = $nonce;
fh_login( 7 );
$editor->save_variation( 101, 0 );
check( 'nor for a user who cannot manage WooCommerce', '2' === wc_get_product( 101 )->meta['_fh_bag_size'] && 1 === wc_get_product( 101 )->meta_saves );
fh_login( 5 );
$_POST['fh_bag_size_var'] = 'junk';
$editor->save_variation( 101, 0 );
check( 'a mangled field is ignored', '2' === wc_get_product( 101 )->meta['_fh_bag_size'] );

echo "6. A simple product\n";
$html = fh_shipping_fields( $editor, 110 );
check( 'the dropdown sits with the weight and dimensions, shown for simple products only', false !== strpos( $html, 'name="fh_bag_size"' ) && false !== strpos( $html, 'show_if_simple' ) && false !== strpos( $html, 'name="fh_bag_size_nonce"' ) );
check( 'its "not set" says what the map gives the product\'s one size', false !== strpos( $html, 'Not set — from the size map: Large (8 × 18 in)' ) );
$html = fh_shipping_fields( $editor, 100 );
check( 'on a variable product the hidden field promises nothing from the map: its variations carry the bag', false !== strpos( $html, '<option value="" selected="selected">Not set</option>' ) );

$_POST  = array( 'fh_bag_size_nonce' => $nonce, 'fh_bag_size' => '1' );
$simple = wc_get_product( 110 );
$editor->save_product( $simple );
check( 'the bag is put on the product object WooCommerce is about to save — this section does not save it itself', '1' === $simple->get_meta( '_fh_bag_size' ) && 0 === $simple->meta_saves && 0 === $simple->full_saves );
$simple->save();
$_POST['fh_bag_size'] = '';
$editor->save_product( $simple );
$simple->save();
check( '"not set" removes it', ! isset( $simple->meta['_fh_bag_size'] ) );
$_POST['fh_bag_size'] = '2';
$parent               = wc_get_product( 100 );
$editor->save_product( $parent );
$editor->save_product( wc_get_product( 101 ) );
check( 'a variable product gets no Bag size of its own, and a variation is not saved through this path', null === $parent->pending && ! isset( $parent->meta['_fh_bag_size'] ) && '2' === wc_get_product( 101 )->meta['_fh_bag_size'] && null === wc_get_product( 101 )->pending );
unset( $_POST['fh_bag_size_nonce'] );
$editor->save_product( $simple );
check( 'without the nonce nothing is put on the product', null === $simple->pending );
$_POST = array( 'fh_bag_size_nonce' => $nonce );
$editor->save_product( $simple );
check( 'a save that does not post the field (quick edit, another screen) changes nothing', null === $simple->pending );
$editor->save_product( null );
$editor->save_product( 'x' );
check( 'something that is not a product is not a fatal error', true );
$_POST = array();

echo "7. Livestock — packed in a bag: the checkbox and its default\n";
$html = fh_shipping_fields( $editor, 110 );
check( 'a checkbox in the Shipping tab, for simple and for variable products, before the Bag size', 1 === preg_match( '/<p class="form-field fh_packs_in_bag_field show_if_simple show_if_variable">/', $html ) && false !== strpos( $html, '>Livestock — packed in a bag</label>' ) && strpos( $html, 'name="fh_packs_in_bag"' ) < strpos( $html, 'name="fh_bag_size"' ) && false !== strpos( $html, 'value="yes"' ) );
check( 'no stored choice, a product in a fish category: ticked', 'ticked/yes' === fh_box( $html ) && '' === Livestock::stored( wc_get_product( 110 ) ) );
check( 'a variable product in a fish category: ticked too', 'ticked/yes' === fh_box( fh_shipping_fields( $editor, 100 ) ) );
check( 'no stored choice, a product in no fish category — medication, dry goods: unticked', 'unticked/no' === fh_box( fh_shipping_fields( $editor, 120 ) ) && 'unticked/no' === fh_box( fh_shipping_fields( $editor, 150 ) ) );
check( 'it says in plain words what it is for, and that customers never see it', false !== strpos( $html, 'medication, food and dry goods' ) && false !== strpos( $html, 'customers never see this' ) );
check( 'the Bag size dropdown carries the class the stylesheet hides while the box is off', 1 === preg_match( '/<p class="form-field fh_bag_size_field show_if_simple fh-bag-size-field">/', $html ) );
ob_start();
$editor->render_variation_field( 0, array(), (object) array( 'ID' => 101 ) );
check( 'and so does the one on every variation — drawn whatever the box says, so it is there when the box is ticked', 0 === strpos( (string) ob_get_clean(), '<div class="fh-bag-size-field">' ) );
check( 'with no stored choice the box says so, and names the fish categories\' term ids — the script lets it follow them until it is touched', false !== strpos( $html, 'data-fh-follow="1"' ) && false !== strpos( $html, 'data-fh-fish="31,32"' ) && false === strpos( $html, 'data-fh-legacy' ) );

wc_get_product( 110 )->meta['_fh_packs_in_bag'] = 'no';
wc_get_product( 120 )->meta['_fh_packs_in_bag'] = 'yes';
$html = fh_shipping_fields( $editor, 110 );
check( 'a stored "no" on a fish: unticked, whatever its category', 'unticked/no' === fh_box( $html ) && false === strpos( $html, 'data-fh-follow' ) );
check( 'a stored "yes" on a medication: ticked, whatever its category', 'ticked/yes' === fh_box( fh_shipping_fields( $editor, 120 ) ) );
unset( wc_get_product( 110 )->meta['_fh_packs_in_bag'], wc_get_product( 120 )->meta['_fh_packs_in_bag'] );

$html = fh_shipping_fields( $editor, 130 );
check( 'a Bag size from before the checkbox, no stored choice, no fish category: ticked — it counts as it did, and its Bag size shows', 'ticked/yes' === fh_box( $html ) && false !== strpos( $html, 'data-fh-legacy="1"' ) && false !== strpos( $html, '<option value="1" selected="selected">' ) );
check( 'a variable product one of whose variations was given a Bag size back then: ticked as well', 'ticked/yes' === fh_box( fh_shipping_fields( $editor, 140 ) ) );
$note = 'No choice is saved on this product yet: only the variations that were given a Bag size earlier count. To make every variation count, click this box off and on again and update the product.';
check( 'ticked only for that Bag size, the box says what it means under it: no choice is saved, the variation without a Bag size does not count, and how to make it', false !== strpos( fh_shipping_fields( $editor, 140 ), $note ) && null === Bags::for_product( 142, $settings, fh_test_sizes() ) );
check( 'no such note where every variation counts or none does: a fish category (a Bag size on a variation or not), a product nobody gave a bag, a simple product with its own', false === strpos( fh_shipping_fields( $editor, 100 ), 'No choice is saved' ) && false === strpos( fh_shipping_fields( $editor, 110 ), 'No choice is saved' ) && false === strpos( fh_shipping_fields( $editor, 150 ), 'No choice is saved' ) && false === strpos( $html, 'No choice is saved' ) );
foreach ( array( 'yes', 'no' ) as $choice ) {
	wc_get_product( 140 )->meta['_fh_packs_in_bag'] = $choice;
	check( 'nor once the choice is stored: ' . $choice, false === strpos( fh_shipping_fields( $editor, 140 ), 'No choice is saved' ) );
}
unset( wc_get_product( 140 )->meta['_fh_packs_in_bag'] );
wc_get_product( 130 )->meta['_fh_packs_in_bag'] = 'no';
check( 'unticked since: unticked — the Bag size is still stored', 'unticked/no' === fh_box( fh_shipping_fields( $editor, 130 ) ) && '1' === wc_get_product( 130 )->meta['_fh_bag_size'] );
unset( wc_get_product( 130 )->meta['_fh_packs_in_bag'] );
wc_get_product( 130 )->meta['_fh_bag_size'] = '77';
check( 'a stored value that names no bag of the list is no Bag size: the default goes by the category', 'unticked/no' === fh_box( fh_shipping_fields( $editor, 130 ) ) );
wc_get_product( 130 )->meta['_fh_bag_size'] = '1';

$GLOBALS['product_object'] = null;
ob_start();
$editor->render_simple_field();
check( 'with no product on the screen the box is drawn unticked, and nothing breaks', 'unticked/no' === fh_box( (string) ob_get_clean() ) );
fh_login( 7 );
check( 'a user who cannot manage WooCommerce is shown neither field', '' === fh_shipping_fields( $editor, 110 ) );
fh_login( 5 );

echo "8. The Bag size dropdowns show only while the box is ticked\n";
$GLOBALS['post'] = (object) array( 'ID' => 120 );
check( 'the product-data box of a product that is not packed in a bag carries fh-no-bag — from the first paint, and over the variation panels that load later', array( 'postbox', 'fh-no-bag' ) === $editor->product_data_classes( array( 'postbox' ) ) && 'fh-no-bag' === Product::CLASS_OFF );
$GLOBALS['post'] = (object) array( 'ID' => 150 );
check( 'a variable product outside the fish categories: the same', array( 'fh-no-bag' ) === $editor->product_data_classes( array() ) );
foreach ( array( 100, 110, 130, 140 ) as $id ) {
	$GLOBALS['post'] = (object) array( 'ID' => $id );
	if ( array( 'postbox' ) !== $editor->product_data_classes( array( 'postbox' ) ) ) {
		check( 'product ' . $id . ' is packed in a bag: its box does not carry the class', false );
	}
}
check( 'a fish, and a product with a Bag size from before the checkbox: the box is as WordPress drew it', true );
wc_get_product( 100 )->meta['_fh_packs_in_bag'] = 'no';
$GLOBALS['post']                                = (object) array( 'ID' => 100 );
check( 'a fish that was unticked: hidden, though its variations still carry their Bag size', array( 'fh-no-bag' ) === $editor->product_data_classes( array() ) && '2' === wc_get_product( 101 )->meta['_fh_bag_size'] );
unset( wc_get_product( 100 )->meta['_fh_packs_in_bag'] );
wc_get_product( 120 )->meta['_fh_packs_in_bag'] = 'yes';
$GLOBALS['post']                                = (object) array( 'ID' => 120 );
check( 'a medication that was ticked: shown', array() === $editor->product_data_classes( array() ) );
unset( wc_get_product( 120 )->meta['_fh_packs_in_bag'] );
check( 'classes that are not a list are made one', array( 'fh-no-bag' ) === $editor->product_data_classes( '' ) );
fh_login( 7 );
check( 'for a user who is not shown the fields the box is left alone', array( 'postbox' ) === $editor->product_data_classes( array( 'postbox' ) ) );
fh_login( 5 );
$GLOBALS['post'] = null;
$css             = (string) file_get_contents( dirname( __DIR__ ) . '/css/packing-admin.css' );
check( 'the stylesheet hides every Bag size field under that class — whatever WooCommerce\'s show_if_* put on it', 1 === preg_match( '/#woocommerce-product-data\.fh-no-bag \.fh-bag-size-field\s*\{\s*display:\s*none\s*!important;\s*\}/', $css ) );
$js = (string) file_get_contents( dirname( __DIR__ ) . '/js/packing-admin.js' );
check( 'and the script flips the class when the box changes — no reload, and nothing to do per variation panel', 1 === preg_match( '/\$\(\'#woocommerce-product-data\'\)\.toggleClass\(\'fh-no-bag\', !\$packs\.prop\(\'checked\'\)\)/', $js ) && false !== strpos( $js, "\$packs.on('change'" ) );
check( 'a box the owner clicked is marked on the form, so the save stores it', false !== strpos( $js, "\$('#fh_packs_in_bag_shown').val(drawnAsAfterClick(\$packs.prop('checked')));" ) );

echo "9. The rule of saving the checkbox\n";
check( 'ticked by the owner: "yes" is stored', array( 'action' => 'set', 'value' => 'yes' ) === Product::decide_packs( 'no', true, '' ) && array( 'action' => 'set', 'value' => 'yes' ) === Product::decide_packs( 'no', true, 'no' ) );
check( 'unticked by the owner: "no" is stored', array( 'action' => 'set', 'value' => 'no' ) === Product::decide_packs( 'yes', false, '' ) && array( 'action' => 'set', 'value' => 'no' ) === Product::decide_packs( 'yes', false, 'yes' ) );
check( 'left as it was drawn: nothing is stored — a product with no stored choice keeps following the default', array( 'action' => 'keep', 'value' => '' ) === Product::decide_packs( 'yes', true, '' ) && array( 'action' => 'keep', 'value' => '' ) === Product::decide_packs( 'no', false, '' ) && 'keep' === Product::decide_packs( 'yes', true, 'yes' )['action'] && 'keep' === Product::decide_packs( 'no', false, 'no' )['action'] );
check( 'the checkbox was not on the form: nothing changes, ticked or not', 'keep' === Product::decide_packs( null, false, 'yes' )['action'] && 'keep' === Product::decide_packs( '', true, 'no' )['action'] && 'keep' === Product::decide_packs( array( 'yes' ), false, 'yes' )['action'] && 'keep' === Product::decide_packs( 'maybe', true, '' )['action'] );
check( 'a page drawn before the choice was stored cannot store it twice', array( 'action' => 'keep', 'value' => 'no' ) === Product::decide_packs( 'yes', false, 'no' ) );

echo "10. Saving the checkbox\n";
$remedy = wc_get_product( 120 );
$_POST  = array( 'fh_bag_size_nonce' => $nonce, 'fh_packs_in_bag_shown' => 'no', 'fh_packs_in_bag' => 'yes', 'fh_bag_size' => '' );
$editor->save_product( $remedy );
check( 'ticking it puts the hidden meta _fh_packs_in_bag = yes on the product object WooCommerce is about to save — this section does not save it itself', 'yes' === $remedy->get_meta( '_fh_packs_in_bag' ) && array( '_fh_packs_in_bag' => 'yes' ) === $remedy->pending && 0 === $remedy->meta_saves && 0 === $remedy->full_saves && '_fh_packs_in_bag' === Packing::META_PACKS );
$remedy->save();
check( 'from then on the product is packed in a bag, whatever its category: it has the bag of its size', true === Livestock::for_product( $remedy, $settings ) && 1 === Bags::for_product( 120, $settings, fh_test_sizes() )['id'] && 'ticked/yes' === fh_box( fh_shipping_fields( $editor, 120 ) ) );

$fish                       = wc_get_product( 110 );
$fish->meta['_fh_bag_size'] = '3';
$_POST                      = array( 'fh_bag_size_nonce' => $nonce, 'fh_packs_in_bag_shown' => 'yes', 'fh_bag_size' => '3' );
$editor->save_product( $fish );
$fish->save();
check( 'unticking it stores "no" — on a fish too', 'no' === $fish->meta['_fh_packs_in_bag'] && false === Livestock::for_product( $fish, $settings ) && null === Bags::for_product( 110, $settings, fh_test_sizes() ) );
check( 'the Bag size stored earlier is kept, not deleted: it stops counting', '3' === $fish->meta['_fh_bag_size'] );
$_POST = array( 'fh_bag_size_nonce' => $nonce, 'fh_packs_in_bag_shown' => 'no', 'fh_packs_in_bag' => 'yes', 'fh_bag_size' => '3' );
$editor->save_product( $fish );
$fish->save();
check( 'ticked again: "yes", and the Bag size counts again', 'yes' === $fish->meta['_fh_packs_in_bag'] && 3 === Bags::for_product( 110, $settings, fh_test_sizes() )['id'] );
unset( $fish->meta['_fh_packs_in_bag'] );

$_POST = array( 'fh_bag_size_nonce' => $nonce, 'fh_packs_in_bag_shown' => 'yes', 'fh_packs_in_bag' => 'yes', 'fh_bag_size' => '3' );
$editor->save_product( $fish );
check( 'a save that leaves the box as it was drawn stores nothing: the fish keeps following its category', null === $fish->pending && ! isset( $fish->meta['_fh_packs_in_bag'] ) );
$salt  = wc_get_product( 150 );
$_POST = array( 'fh_bag_size_nonce' => $nonce, 'fh_packs_in_bag_shown' => 'no' );
$editor->save_product( $salt );
check( 'nor for a product that was drawn unticked and stays so', null === $salt->pending && ! isset( $salt->meta['_fh_packs_in_bag'] ) );

$_POST = array( 'fh_bag_size_nonce' => $nonce );
$editor->save_product( $remedy );
check( 'a save that does not post the checkbox at all (quick edit, another screen) changes nothing — it does not read as "unticked"', null === $remedy->pending && 'yes' === $remedy->meta['_fh_packs_in_bag'] );
$_POST = array( 'fh_bag_size_nonce' => $nonce, 'fh_packs_in_bag' => 'yes' );
$editor->save_product( $salt );
check( 'nor does a ticked box that does not say how it was drawn', null === $salt->pending );

$_POST = array( 'fh_bag_size_nonce' => 'nonce-of-another-form', 'fh_packs_in_bag_shown' => 'yes' );
$editor->save_product( $remedy );
check( 'with a bad nonce nothing is saved', null === $remedy->pending && 'yes' === $remedy->meta['_fh_packs_in_bag'] );
unset( $_POST['fh_bag_size_nonce'] );
$editor->save_product( $remedy );
check( 'nor without one', null === $remedy->pending );
$_POST['fh_bag_size_nonce'] = $nonce;
fh_login( 7 );
$editor->save_product( $remedy );
check( 'nor for a user who cannot manage WooCommerce, whatever the nonce', null === $remedy->pending && 'yes' === $remedy->meta['_fh_packs_in_bag'] );
fh_login( 5 );

$_POST  = array( 'fh_bag_size_nonce' => $nonce, 'fh_packs_in_bag_shown' => 'yes' );
$parent = wc_get_product( 100 );
$editor->save_product( $parent );
$parent->save();
check( 'on a variable product the choice is stored on the parent', 'no' === $parent->meta['_fh_packs_in_bag'] && array( '_fh_packs_in_bag' => 'no' ) === $parent->meta );
check( 'and read from it for every variation: none has a bag now, and each keeps the Bag size it had', false === Livestock::for_product( wc_get_product( 101 ), $settings ) && null === Bags::for_product( 101, $settings, fh_test_sizes() ) && '2' === wc_get_product( 101 )->meta['_fh_bag_size'] && 'no' === Livestock::stored( wc_get_product( 103 ) ) );
$editor->save_product( wc_get_product( 101 ) );
check( 'a variation is never given a choice of its own', null === wc_get_product( 101 )->pending && ! isset( wc_get_product( 101 )->meta['_fh_packs_in_bag'] ) );
$_POST = array( 'fh_bag_size_nonce' => $nonce, 'fh_packs_in_bag_shown' => 'no', 'fh_packs_in_bag' => 'yes' );
$editor->save_product( $parent );
$parent->save();
check( 'ticked again on the parent: its variations have their bags again', 'yes' === $parent->meta['_fh_packs_in_bag'] && 2 === Bags::for_product( 101, $settings, fh_test_sizes() )['id'] );

$legacy = wc_get_product( 140 );
$_POST  = array( 'fh_bag_size_nonce' => $nonce, 'fh_packs_in_bag_shown' => 'yes', 'fh_packs_in_bag' => 'yes' );
$editor->save_product( $legacy );
check( 'a product with a Bag size from before the checkbox, saved as it was drawn: nothing is stored and it counts as before — the variation that has a Bag size is bagged, the one that has none is not', null === $legacy->pending && 2 === Bags::for_product( 141, $settings, fh_test_sizes() )['id'] && null === Bags::for_product( 142, $settings, fh_test_sizes() ) );
$_POST = array( 'fh_bag_size_nonce' => $nonce, 'fh_packs_in_bag_shown' => 'no', 'fh_packs_in_bag' => 'yes' );
$editor->save_product( $legacy );
$legacy->save();
check( 'the box clicked off and on again (the script says so on the form): "yes" is stored, and now every variation follows it — the one without a Bag size has the bag of its size', 'yes' === $legacy->meta['_fh_packs_in_bag'] && 2 === Bags::for_product( 141, $settings, fh_test_sizes() )['id'] && 2 === Bags::for_product( 142, $settings, fh_test_sizes() )['id'] && 'map' === Bags::for_product( 142, $settings, fh_test_sizes() )['source'] );
$_POST = array( 'fh_bag_size_nonce' => $nonce, 'fh_packs_in_bag_shown' => 'yes' );
$editor->save_product( $legacy );
$legacy->save();
check( 'unticked: neither is, and the Bag size stays where it was', 'no' === $legacy->meta['_fh_packs_in_bag'] && null === Bags::for_product( 141, $settings, fh_test_sizes() ) && '2' === wc_get_product( 141 )->meta['_fh_bag_size'] && array( 'fh-no-bag' ) === ( function () use ( $editor ) {
	$GLOBALS['post'] = (object) array( 'ID' => 140 );
	return $editor->product_data_classes( array() );
} )() );
$_POST           = array();
$GLOBALS['post'] = null;

echo "11. The hooks\n";
fh_reset_hooks();
$editor->init();
check( 'the four product-editor hooks, and the classes of the product-data box — all of them fired in wp-admin only', array( 'woocommerce_product_after_variable_attributes', 'woocommerce_save_product_variation', 'woocommerce_product_options_shipping', 'woocommerce_admin_process_product_object', 'postbox_classes_product_woocommerce-product-data' ) === array_keys( $GLOBALS['fh_hooks'] ) );

fh_finish();
