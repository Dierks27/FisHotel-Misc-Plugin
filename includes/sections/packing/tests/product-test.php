<?php
/**
 * The Bag size dropdown in the product editor — on invented products: its
 * options (what "not set" means for this variation right now, a removed
 * bag still shown), the rule of a save, who may save and with which nonce,
 * the two save handlers writing ONE hidden meta through the product
 * object, and that a variable product has none of its own.
 *
 * Run from the plugin root:  php includes/sections/packing/tests/product-test.php
 */

require_once __DIR__ . '/wp-stubs.php';

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

$editor = new Product();

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
$GLOBALS['product_object'] = wc_get_product( 110 );
ob_start();
$editor->render_simple_field();
$html = (string) ob_get_clean();
check( 'the dropdown sits with the weight and dimensions, shown for simple products only', false !== strpos( $html, 'name="fh_bag_size"' ) && false !== strpos( $html, 'show_if_simple' ) && false !== strpos( $html, 'name="fh_bag_size_nonce"' ) );
check( 'its "not set" says what the map gives the product\'s one size', false !== strpos( $html, 'Not set — from the size map: Large (8 × 18 in)' ) );
$GLOBALS['product_object'] = wc_get_product( 100 );
ob_start();
$editor->render_simple_field();
$html = (string) ob_get_clean();
check( 'on a variable product the hidden field promises nothing from the map: its variations carry the bag', false !== strpos( $html, '<option value="" selected="selected">Not set</option>' ) );

$_POST  = array( 'fh_bag_size_nonce' => $nonce, 'fh_bag_size' => '1' );
$simple = wc_get_product( 110 );
$editor->save_simple( $simple );
check( 'the bag is put on the product object WooCommerce is about to save — this section does not save it itself', '1' === $simple->get_meta( '_fh_bag_size' ) && 0 === $simple->meta_saves && 0 === $simple->full_saves );
$simple->save();
$_POST['fh_bag_size'] = '';
$editor->save_simple( $simple );
$simple->save();
check( '"not set" removes it', ! isset( $simple->meta['_fh_bag_size'] ) );
$_POST['fh_bag_size'] = '2';
$parent               = wc_get_product( 100 );
$editor->save_simple( $parent );
$editor->save_simple( wc_get_product( 101 ) );
check( 'a variable product gets no Bag size of its own, and a variation is not saved through this path', null === $parent->pending && ! isset( $parent->meta['_fh_bag_size'] ) && '2' === wc_get_product( 101 )->meta['_fh_bag_size'] && null === wc_get_product( 101 )->pending );
unset( $_POST['fh_bag_size_nonce'] );
$editor->save_simple( $simple );
check( 'without the nonce nothing is put on the product', null === $simple->pending );
$_POST = array( 'fh_bag_size_nonce' => $nonce );
$editor->save_simple( $simple );
check( 'a save that does not post the field (quick edit, another screen) changes nothing', null === $simple->pending );
$_POST = array();

echo "7. The hooks\n";
fh_reset_hooks();
$editor->init();
check( 'four product-editor hooks, all of them fired by WooCommerce in wp-admin only', array( 'woocommerce_product_after_variable_attributes', 'woocommerce_save_product_variation', 'woocommerce_product_options_shipping', 'woocommerce_admin_process_product_object' ) === array_keys( $GLOBALS['fh_hooks'] ) );

fh_finish();
