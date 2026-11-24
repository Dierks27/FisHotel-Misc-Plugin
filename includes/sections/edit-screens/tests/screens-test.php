<?php
/**
 * Edit screens — the screens themselves, headless, on invented products,
 * orders and people:
 *
 *   - the section's hooks: none outside wp-admin; switched on; switched off
 *     (only the give-back);
 *   - when the switch runs (add_meta_boxes at 50, after WooCommerce wrote
 *     its own product layout at 40), the notice, Screen Options, the links
 *     (who may, which nonce, each op), the give-back while off;
 *   - the product screen: Fish essentials registered first in the main
 *     column, the four titles, the empty area under the title the script
 *     fills, the kind on <body>, the assets with the kind's categories;
 *   - Fish essentials drawn (the name, the two places when they differ,
 *     the Packing fields through its contract, the Still-missing chips,
 *     escaped) and saved (both places, only a changed name, the nonce, who
 *     may);
 *   - an order folded into a combined box (the theme's answer, else its
 *     meta).
 *
 * Run from the plugin root:  php includes/sections/edit-screens/tests/screens-test.php
 */

require_once __DIR__ . '/wp-stubs.php';

use FisHotel\Misc\Sections\Edit_Screens\Edit_Screens;
use FisHotel\Misc\Sections\Edit_Screens\Essentials;
use FisHotel\Misc\Sections\Edit_Screens\Layouts;
use FisHotel\Misc\Sections\Edit_Screens\Order_Screen;
use FisHotel\Misc\Sections\Edit_Screens\Product_Screen;
use FisHotel\Misc\Sections\Edit_Screens\Switcher;
use FisHotel\Misc\Sections\Edit_Screens\Tidy;
use FisHotel\Misc\Sections\Edit_Screens\User_Store;

echo "1. The section's hooks\n";
$GLOBALS['fh_is_admin'] = false;
Edit_Screens::boot();
Edit_Screens::boot_disabled();
check( 'outside wp-admin: no hook at all, on or off', array() === $GLOBALS['fh_hooks'] );
$GLOBALS['fh_is_admin'] = true;
Edit_Screens::boot();
$hooks = array_keys( $GLOBALS['fh_hooks'] );
sort( $hooks );
check_same(
	'switched on: the switch and its links, the product screen, the folded order\'s boxes',
	array(
		'add_meta_boxes',
		'add_meta_boxes_product',
		'admin_body_class',
		'admin_enqueue_scripts',
		'admin_notices',
		'admin_post_fishotel_edit_screens',
		'edit_form_after_title',
		'fishotel_fish_facts_box_fields',
		'postbox_classes_product_fishotel-compat-category',
		'postbox_classes_product_fishotel_cleanroom',
		'postbox_classes_product_fishotel_hotel_data',
		'postbox_classes_product_postexcerpt',
		'postbox_classes_shop_order_fishotel-combine-orders',
		'postbox_classes_shop_order_fishotel-med-packing-slip',
		'postbox_classes_shop_order_fishotel-packing',
		'postbox_classes_shop_order_fishotel-weather',
		'postbox_classes_woocommerce_page_wc-orders_fishotel-combine-orders',
		'postbox_classes_woocommerce_page_wc-orders_fishotel-med-packing-slip',
		'postbox_classes_woocommerce_page_wc-orders_fishotel-packing',
		'postbox_classes_woocommerce_page_wc-orders_fishotel-weather',
		'screen_settings',
		'woocommerce_process_product_meta',
		'woocommerce_product_data_tabs',
		'wp_insert_post_data',
	),
	$hooks
);
check( 'the short description is written with the post itself (wp_insert_post_data), so save_post — the Batch plugin\'s Master Fish sync — reads the new name', array( Essentials::class, 'insert_data' ) === $GLOBALS['fh_hooks']['wp_insert_post_data'][0]['callback'] && 2 === $GLOBALS['fh_hooks']['wp_insert_post_data'][0]['args'] );
check( 'the switch runs at 50 on add_meta_boxes: after WooCommerce writes its own product layout (40), before a box is drawn', 50 === $GLOBALS['fh_hooks']['add_meta_boxes'][0]['priority'] );
check( 'the name is saved after WooCommerce and the theme saved theirs (50)', 50 === $GLOBALS['fh_hooks']['woocommerce_process_product_meta'][0]['priority'] && 99 === $GLOBALS['fh_hooks']['woocommerce_product_data_tabs'][0]['priority'] && 99 === $GLOBALS['fh_hooks']['add_meta_boxes_product'][0]['priority'] );
fh_reset_hooks();
Edit_Screens::boot_disabled();
check( 'switched off: only the give-back, on add_meta_boxes', array( 'add_meta_boxes' ) === array_keys( $GLOBALS['fh_hooks'] ) && array( Edit_Screens::class, 'give_back' ) === $GLOBALS['fh_hooks']['add_meta_boxes'][0]['callback'] );
fh_reset_hooks();
check( 'the section says what it is', 'Edit screens' === Edit_Screens::get_section_info()['name'] && 'dashicons-layout' === Edit_Screens::get_section_info()['icon'] );

echo "2. The switch on the screens\n";
fh_login( 7, array( 'edit_posts' ) );
$GLOBALS['fh_user_meta'][7] = array( 'meta-box-order_product' => array( 'side' => 'submitdiv', 'normal' => 'woocommerce-product-data', 'advanced' => '' ) );
$tidy = new Tidy();
$tidy->on_screen( 'post' );
check( 'another screen: nothing', ! isset( $GLOBALS['fh_user_meta'][7][ Switcher::STATE ] ) );
$tidy->on_screen( 'product' );
check( 'the product screen (the post type add_meta_boxes carries): switched', Layouts::product()['order'] === $GLOBALS['fh_user_meta'][7]['meta-box-order_product'] );
$tidy->on_screen( 'woocommerce_page_wc-orders' );
check( 'the HPOS order screen (its screen id): switched', Layouts::order()['order'] === $GLOBALS['fh_user_meta'][7]['meta-box-order_woocommerce_page_wc-orders'] );
check( 'a WP_Screen is read by its id', 'shop_order' === Tidy::screen_id( (object) array( 'id' => 'shop_order' ) ) && '' === Tidy::screen_id( (object) array( 'id' => 'edit-product' ) ) && '' === Tidy::screen_id( null ) );

$GLOBALS['fh_screen'] = (object) array( 'id' => 'product' );
ob_start();
$tidy->notice();
$html = (string) ob_get_clean();
check( 'the notice says what changed and how to undo it', false !== strpos( $html, 'This screen was tidied:' ) && false !== strpos( $html, 'what a fish needs filled first is at the top' ) && false !== strpos( $html, 'your own changes are kept from now on' ) && false !== strpos( $html, '>Put my old layout back</a>' ) && false !== strpos( $html, '>Dismiss</a>' ) );
check( '— its links go to admin-post with the op, the screen and a nonce of their own', false !== strpos( $html, 'admin-post.php?action=fishotel_edit_screens&amp;op=undo&amp;screen=product&amp;_wpnonce=' . wp_create_nonce( 'fishotel_edit_screens|undo|product' ) ) );
$GLOBALS['fh_screen'] = (object) array( 'id' => 'shop_order' );
ob_start();
$tidy->notice();
check( 'a screen not switched yet: no notice', '' === (string) ob_get_clean() );
$GLOBALS['fh_screen'] = (object) array( 'id' => 'woocommerce_page_wc-orders' );
ob_start();
$tidy->notice();
check( 'HPOS: the orders LIST shares the order screen\'s id — no notice there', '' === (string) ob_get_clean() );
check( '— nor the FisHotel layout links in its Screen Options', 'x' === $tidy->screen_settings( 'x', (object) array( 'id' => 'woocommerce_page_wc-orders' ) ) );
$_GET = array( 'page' => 'wc-orders', 'action' => 'edit', 'id' => '12' );
ob_start();
$tidy->notice();
check( 'the order screen\'s notice (HPOS: action=edit) speaks of an order\'s life', false !== strpos( (string) ob_get_clean(), 'follow an order&#039;s life' ) );
check( '— and its Screen Options has the links', false !== strpos( $tidy->screen_settings( '', (object) array( 'id' => 'woocommerce_page_wc-orders' ) ), 'Reset to the FisHotel layout' ) );
$_GET = array();
check( 'the edit view: the legacy screens always; HPOS only on edit and new', Tidy::is_edit_view( 'product', array() ) && Tidy::is_edit_view( 'shop_order', array() ) && Tidy::is_edit_view( 'woocommerce_page_wc-orders', array( 'action' => 'new' ) ) && ! Tidy::is_edit_view( 'woocommerce_page_wc-orders', array( 'status' => 'wc-processing' ) ) && ! Tidy::is_edit_view( 'woocommerce_page_wc-orders', array( 'action' => array( 'edit' ) ) ) && ! Tidy::is_edit_view( '', array() ) );

$options = $tidy->screen_settings( '<p>theirs</p>', (object) array( 'id' => 'product' ) );
check( 'Screen Options keeps its own and adds the two links', 0 === strpos( $options, '<p>theirs</p>' ) && false !== strpos( $options, '>Reset to the FisHotel layout</a>' ) && false !== strpos( $options, '>Put my old layout back</a>' ) && false !== strpos( $options, '<legend>FisHotel layout</legend>' ) );
check( '— not on another screen', 'x' === $tidy->screen_settings( 'x', (object) array( 'id' => 'dashboard' ) ) );
fh_login( 8 );
check( '— a person with no backup gets the reset only', false === strpos( $tidy->screen_settings( '', (object) array( 'id' => 'product' ) ), 'Put my old layout back' ) );
fh_login( 7, array( 'edit_posts' ) );

$link = static function ( $op, $screen ) {
	return array(
		'op'       => $op,
		'screen'   => $screen,
		'_wpnonce' => wp_create_nonce( 'fishotel_edit_screens|' . $op . '|' . $screen ),
	);
};
check( 'a link with another op\'s nonce, another screen\'s, none, or for nobody: refused', '' === $tidy->run( array( 'op' => 'undo', 'screen' => 'product', '_wpnonce' => wp_create_nonce( 'fishotel_edit_screens|reset|product' ) ), 7 ) && '' === $tidy->run( array( 'op' => 'undo', 'screen' => 'shop_order', '_wpnonce' => wp_create_nonce( 'fishotel_edit_screens|undo|product' ) ), 7 ) && '' === $tidy->run( array( 'op' => 'undo', 'screen' => 'product' ), 7 ) && '' === $tidy->run( $link( 'undo', 'product' ), 0 ) && '' === $tidy->run( $link( 'delete', 'product' ), 7 ) && '' === $tidy->run( $link( 'undo', 'post' ), 7 ) );
check( 'dismiss', 'dismiss' === $tidy->run( $link( 'dismiss', 'product' ), 7 ) && ( function () use ( $tidy ) {
	$GLOBALS['fh_screen'] = (object) array( 'id' => 'product' );
	ob_start();
	$tidy->notice();
	return '' === (string) ob_get_clean();
} )() );
check( 'undo: the person\'s old layout back', 'undo' === $tidy->run( $link( 'undo', 'product' ), 7 ) && array( 'side' => 'submitdiv', 'normal' => 'woocommerce-product-data', 'advanced' => '' ) === $GLOBALS['fh_user_meta'][7]['meta-box-order_product'] );
$_GET = array( Tidy::DONE => 'undo' );
ob_start();
$tidy->notice();
$html = (string) ob_get_clean();
$_GET = array();
check( '— and the screen says so, with where to tidy it again', false !== strpos( $html, 'Your old layout of this screen is back' ) && false !== strpos( $html, 'Reset to the FisHotel layout' ) );
check( 'reset: the FisHotel layout again', 'reset' === $tidy->run( $link( 'reset', 'product' ), 7 ) && Layouts::product()['order'] === $GLOBALS['fh_user_meta'][7]['meta-box-order_product'] );
check( 'the links only ever touch the person who clicks: another person\'s meta is as it was', ! isset( $GLOBALS['fh_user_meta'][8] ) );

$GLOBALS['fh_user_meta'][7]['meta-box-order_product'] = array( 'side' => 'dragged', 'normal' => '', 'advanced' => '' );
Edit_Screens::give_back( 'product' );
check( 'switched off: the next visit gives the old layout back (the one before the reset)', array( 'side' => 'submitdiv', 'normal' => 'woocommerce-product-data', 'advanced' => '' ) === $GLOBALS['fh_user_meta'][7]['meta-box-order_product'] );
Edit_Screens::give_back( 'dashboard' );
check( '— another screen: nothing', true );

echo "3. The product screen\n";
$GLOBALS['wp_meta_boxes'] = array();
add_meta_box( 'woocommerce-product-data', 'Product data', 'x', 'product', 'normal', 'high' );
add_meta_box( 'fishotel_hotel_data', 'FisHotel — Product Data', 'x', 'product', 'normal', 'high' );
add_meta_box( 'fishotel-compat-category', 'Compatibility Category', 'x', 'product', 'side', 'default' );
add_meta_box( 'fishotel_coming_soon', 'FisHotel Coming Soon', 'x', 'product', 'side', 'default' );
add_meta_box( 'wpseo_meta', 'Yoast SEO', 'x', 'product', 'normal', 'high' );
$screen = new Product_Screen();
$screen->boxes();
$boxes = $GLOBALS['wp_meta_boxes']['product'];
check( 'Fish essentials is a box of the main column, "high" — first when a person\'s layout does not name it', isset( $boxes['normal']['high']['fishotel-essentials'] ) && 'Fish essentials' === $boxes['normal']['high']['fishotel-essentials']['title'] && array( Essentials::class, 'render' ) === $boxes['normal']['high']['fishotel-essentials']['callback'] );
check( 'the four titles changed, in whichever column and priority', 'Price, stock & sizes' === $boxes['normal']['high']['woocommerce-product-data']['title'] && 'Fish facts — the table on the product page' === $boxes['normal']['high']['fishotel_hotel_data']['title'] && 'Compatibility guide' === $boxes['side']['default']['fishotel-compat-category']['title'] && 'Coming Soon — release date' === $boxes['side']['default']['fishotel_coming_soon']['title'] );
check( '— titles only: the callbacks are their owners\'; Yoast as it was', 'x' === $boxes['normal']['high']['woocommerce-product-data']['callback'] && 'Yoast SEO' === $boxes['normal']['high']['wpseo_meta']['title'] );
ob_start();
$screen->top_area( (object) array( 'ID' => 5, 'post_type' => 'product' ) );
$top = (string) ob_get_clean();
check( 'under the title: an empty area that names the two boxes the script moves into it', '<div id="fh-es-top" class="fh-es-top" data-boxes="fishotel-essentials,woocommerce-product-data"></div>' === $top, $top );
ob_start();
$screen->top_area( (object) array( 'ID' => 5, 'post_type' => 'post' ) );
check( '— on a product only', '' === (string) ob_get_clean() );

$GLOBALS['fh_term_ids'] = array( 'quarantined-fish' => 15, 'inverts' => 16, 'antiparasitic' => 30, 'invoices' => 40 );
$GLOBALS['fh_terms']    = array( 5 => array( 'quarantined-fish' ), 6 => array( 'antiparasitic' ), 9 => array( 'gift-cards' ) );
$GLOBALS['fh_post_meta'][9]['_fishotel_fulfillment'] = 'ea';
check_same( 'the kind of the product on the screen', array( 'fish', 'med', 'med', 'none' ), array( Product_Screen::kind_of( 5 ), Product_Screen::kind_of( 6 ), Product_Screen::kind_of( 9 ), Product_Screen::kind_of( 0 ) ) );
$GLOBALS['fh_screen'] = (object) array( 'id' => 'product' );
$GLOBALS['post']      = (object) array( 'ID' => 5 );
check_same( 'as a class on the page', 'wp-admin fh-es-on fh-es-kind-fish', $screen->body_class( 'wp-admin' ) );
$GLOBALS['fh_screen'] = (object) array( 'id' => 'edit-product' );
check( '— not on another screen', 'wp-admin' === $screen->body_class( 'wp-admin' ) );
$screen->assets();
check( 'no assets on another screen', array() === $GLOBALS['fh_enqueued'] );
$GLOBALS['fh_screen'] = (object) array( 'id' => 'product' );
$GLOBALS['fh_term_reads'] = 0;
$screen->assets();
check( 'the categories of each kind: ONE read for every slug (not one per category)', 1 === $GLOBALS['fh_term_reads'] );
$screen->assets();
check( 'on the product screen: the stylesheet, the script, once — and the categories of each kind for it', array( 'style:fishotel-edit-screens', 'script:fishotel-edit-screens', 'data:fhEditScreens', 'data:fhEditScreens' ) === $GLOBALS['fh_enqueued'] && array( 'fish' => array( 15, 16 ), 'med' => array( 30 ), 'invoice' => array( 40 ) ) === $GLOBALS['fh_localized']['fhEditScreens']['kindTerms'] );
$GLOBALS['fh_term_ids']['corals']   = 17;
$GLOBALS['fh_terms'][10]            = array( 'corals' );
$GLOBALS['fh_options']['fishotel_product_checks'] = array( 'live_cats' => array( 'corals' ) );
check( 'a category ticked as live in Product checks (Corals, one day) is a live fish here too — and a fish category left unticked still is', 'fish' === Product_Screen::kind_of( 10 ) && 'fish' === Product_Screen::kind_of( 5 ) && array( 15, 16, 17 ) === Product_Screen::kind_terms()['fish'] );
$GLOBALS['fh_options'] = array();
check( '— nothing ticked there: corals is a dry good', 'dry' === Product_Screen::kind_of( 10 ) );

echo "4. Fish essentials\n";
$GLOBALS['fh_post_meta'][5]['_fh_scientific_name'] = 'Fishus inventus';
$GLOBALS['fh_products'][5]                         = new Fh_Test_Product( 5, 'Fishus other', array( '_fh_scientific_name' => 'Fishus inventus' ) );
add_filter(
	'fishotel_edit_screens_missing',
	static function ( $chips, $id ) {
		return 5 === $id ? array( array( 'label' => 'Photo', 'href' => '#postimagediv' ), array( 'label' => 'Price: <Large>' ), 'nonsense' ) : $chips;
	},
	10,
	2
);
ob_start();
Essentials::render( (object) array( 'ID' => 5, 'post_excerpt' => '<em>Fishus other</em>' ) );
$html = (string) ob_get_clean();
check( 'the name field, with the name it was drawn with beside it, and the nonce', false !== strpos( $html, 'name="fh_es_sci" value="Fishus inventus"' ) && false !== strpos( $html, 'name="fh_es_sci_shown" value="Fishus inventus"' ) && false !== strpos( $html, 'name="fh_es_nonce" value="' . wp_create_nonce( 'fishotel_edit_screens_essentials' ) . '"' ) );
check( 'it says it is the line under the name AND the facts table', false !== strpos( $html, 'The italic line under the fish&#039;s name on its page, AND the first row of its facts table' ) );
check( 'the two places differ: both shown, each with "Use this one"', false !== strpos( $html, 'say different things today' ) && 2 === substr_count( $html, 'class="button-link fh-es-use"' ) && false !== strpos( $html, 'data-value="Fishus other"' ) );
check( 'the name is for a live fish only (the page hides it for the other kinds)', false !== strpos( $html, 'fh-es-sci fh-es-live-only' ) );
check( 'Still missing: each chip a link to its spot, escaped; nonsense left out', false !== strpos( $html, '<a class="fh-es-chip" href="#postimagediv">Photo</a>' ) && false !== strpos( $html, '<span class="fh-es-chip">Price: &lt;Large&gt;</span>' ) && 2 === substr_count( $html, 'fh-es-chip' ) );
check( 'with the Packing section off, no Livestock and no tick (and nothing breaks)', false === strpos( $html, 'fh_packs_in_bag' ) );
fh_reset_hooks();

/**
 * The Packing section's contract, as it answers while that section is on.
 *
 * @param mixed $product WC_Product.
 * @return bool
 */
function fishotel_packing_product_fields( $product ) {
	if ( empty( $GLOBALS['fh_packing_on'] ) ) {
		return false; // The section is off: the contract draws nothing.
	}
	echo '<p class="form-field fh_packs_in_bag_field">packed for ' . (int) ( is_object( $product ) ? $product->get_id() : 0 ) . '</p>';
	return true;
}
$GLOBALS['fh_packing_on'] = true;
ob_start();
Essentials::render( (object) array( 'ID' => 5, 'post_excerpt' => 'Fishus inventus' ) );
$html = (string) ob_get_clean();
check( 'with Packing on, its two fields come through its contract, for this product', false !== strpos( $html, 'packed for 5' ) );
check( 'the same name in both places: no choice to make; nothing missing: no chips', false === strpos( $html, 'fh-es-use' ) && false === strpos( $html, 'Still missing' ) );

$nonce = wp_create_nonce( 'fishotel_edit_screens_essentials' );
/*
 * One Update of the edit form, as WordPress runs it: wp_insert_post_data
 * (the post row, the short description in it), then save_post (where the
 * Batch plugin's Master Fish sync reads the short description), then
 * WooCommerce's process_product_meta (the facts table's meta).
 */
$update = static function ( array $post, $excerpt_posted = null ) {
	$p     = $GLOBALS['fh_products'][5];
	$_POST = $post + array( 'post_ID' => '5' );
	$data  = Essentials::insert_data(
		array(
			'post_type'    => 'product',
			'post_excerpt' => wp_slash( null === $excerpt_posted ? $p->short : $excerpt_posted ),
		),
		array( 'ID' => 5 )
	);
	$p->short                  = stripslashes( $data['post_excerpt'] );
	$GLOBALS['fh_master_seen'] = $p->short; // save_post_product: what the Master Fish sync reads.
	Essentials::save( 5 );
	$_POST = array();
	return $p;
};
fh_login( 7, array( 'edit_post' ) );
$p = $update( array( 'fh_es_nonce' => $nonce, 'fh_es_sci' => 'Fishus inventus', 'fh_es_sci_shown' => 'Fishus inventus' ) );
check( 'saved as it was drawn: nothing is written', 0 === $p->saves && 'Fishus other' === $p->short );
$p = $update( array( 'fh_es_nonce' => $nonce, 'fh_es_sci' => 'Fishus novus', 'fh_es_sci_shown' => 'Fishus inventus' ) );
check( 'a changed name: the short description (with the post) AND the facts table', 1 === $p->saves && 'Fishus novus' === $p->short && 'Fishus novus' === $p->meta['_fh_scientific_name'] );
check( '— the short description is already the new name when save_post runs: the Master Fish gets the new name in the same Update, not one save later', 'Fishus novus' === $GLOBALS['fh_master_seen'] );
$p = $update( array( 'fh_es_nonce' => $nonce, 'fh_es_sci' => '', 'fh_es_sci_shown' => 'Fishus novus' ) );
check( 'emptied: both cleared', 2 === $p->saves && '' === $p->short && ! isset( $p->meta['_fh_scientific_name'] ) );
$p = $update( array( 'fh_es_nonce' => $nonce, 'fh_es_sci' => "Fishus o'brieni", 'fh_es_sci_shown' => '' ) );
check( 'a name with a quote is saved as typed (slashed for WordPress, unslashed in the row)', "Fishus o'brieni" === $p->short );
$p->short = '';
$update( array( 'fh_es_nonce' => 'nope', 'fh_es_sci' => 'X', 'fh_es_sci_shown' => '' ) );
$update( array( 'fh_es_sci' => 'X', 'fh_es_sci_shown' => '' ) );
fh_login( 8 );
$update( array( 'fh_es_nonce' => wp_create_nonce( 'fishotel_edit_screens_essentials' ), 'fh_es_sci' => 'X', 'fh_es_sci_shown' => '' ) );
check( 'another nonce, none, or a person who may not edit the product: nothing', 3 === $p->saves && '' === $p->short );
fh_login( 7, array( 'edit_post' ) );
$_POST = array( 'fh_es_nonce' => $nonce, 'fh_es_sci' => 'X', 'fh_es_sci_shown' => '', 'post_ID' => '6' );
check( 'another post saved in the same request: its short description is not touched', 'keep' === Essentials::insert_data( array( 'post_type' => 'product', 'post_excerpt' => 'keep' ), array( 'ID' => 5 ) )['post_excerpt'] );
$_POST = array( 'fh_es_nonce' => $nonce, 'fh_es_sci' => 'X', 'fh_es_sci_shown' => '', 'post_ID' => '5' );
check( '— nor a post that is not a product', 'keep' === Essentials::insert_data( array( 'post_type' => 'post', 'post_excerpt' => 'keep' ), array( 'ID' => 5 ) )['post_excerpt'] );
$_POST = array();

echo "4b. A short description that holds more than the name\n";
$extra = "<p><em>Fishus inventus</em></p>\n<p>Reef safe: with caution</p>";
check( 'more than the name: a second line, a colon, a link, a list, very long', Essentials::excerpt_extra( $extra ) && Essentials::excerpt_extra( 'Fishus inventus<br>Grows to 6"' ) && Essentials::excerpt_extra( 'Reef safe: yes' ) && Essentials::excerpt_extra( '<a href="/care">Fishus inventus</a>' ) && Essentials::excerpt_extra( '<ul><li>Fishus</li></ul>' ) && Essentials::excerpt_extra( str_repeat( 'a', 201 ) ) );
check( 'just the name — plain, in italics, in a paragraph, or empty', ! Essentials::excerpt_extra( 'Fishus inventus' ) && ! Essentials::excerpt_extra( '<p><em>Fishus inventus</em></p>' ) && ! Essentials::excerpt_extra( "<strong>Fishus  cf. inventus</strong>\n" ) && ! Essentials::excerpt_extra( '' ) && ! Essentials::excerpt_extra( null ) );
check( 'the field offers the first line of such a text, not all of it', 'Fishus inventus' === Essentials::sci_view( $extra, '' )['value'] && 'Fishus inventus' === Essentials::first_line( $extra ) );
$p        = $GLOBALS['fh_products'][5];
$p->short = $extra;
$p->meta  = array( '_fh_scientific_name' => 'Fishus inventus' );
$p        = $update( array( 'fh_es_nonce' => $nonce, 'fh_es_sci' => 'Fishus novus', 'fh_es_sci_shown' => 'Fishus inventus' ) );
check( 'a changed name: the facts table only — the short description and its extra lines are kept, never overwritten', $extra === $p->short && 'Fishus novus' === $p->meta['_fh_scientific_name'] );
$p = $update( array( 'fh_es_nonce' => $nonce, 'fh_es_sci' => 'Fishus novus', 'fh_es_sci_shown' => 'Fishus novus' ), 'Fishus novus<br>Edited in its own box' );
check( 'what the owner types in the short description box is saved as typed', 'Fishus novus<br>Edited in its own box' === $p->short );
ob_start();
Essentials::render( (object) array( 'ID' => 5, 'post_excerpt' => $extra ) );
$html = (string) ob_get_clean();
check( 'the box says the short description stays in its own box and is not changed from here', false !== strpos( $html, 'class="fh-es-extra"' ) && false !== strpos( $html, 'never changed from here' ) );
$GLOBALS['post'] = (object) array( 'ID' => 5, 'post_excerpt' => $extra );
check( '— and that box stays on the screen of a live fish (it is not given the class that hides it)', array( 'x' ) === $screen->classes_not_live( array( 'x' ) ) );
$GLOBALS['post'] = (object) array( 'ID' => 5, 'post_excerpt' => '<em>Fishus inventus</em>' );
check( '— a short description that is just the name: hidden for a live fish, as before', array( 'x', 'fh-es-not-live' ) === $screen->classes_not_live( array( 'x' ) ) );
ob_start();
Essentials::render( (object) array( 'ID' => 5, 'post_excerpt' => 'Fishus inventus' ) );
check( '— and no such line', false === strpos( (string) ob_get_clean(), 'fh-es-extra' ) );

echo "5. An order folded into a combined box\n";
$GLOBALS['fh_orders'][300] = new Fh_Test_Product( 300, '', array( '_fishotel_fulfilled_by_order' => '301' ) );
$GLOBALS['fh_orders'][302] = new Fh_Test_Product( 302 );
check( 'folded: by its meta (the theme\'s function answers from it too)', true === Order_Screen::is_folded( 300 ) && false === Order_Screen::is_folded( 302 ) && false === Order_Screen::is_folded( 0 ) && false === Order_Screen::is_folded( 999 ) );
$GLOBALS['post'] = (object) array( 'ID' => 300, 'post_type' => 'shop_order' );
check( 'the legacy screen\'s order is its post', 300 === Order_Screen::current_order_id() );
$GLOBALS['post'] = null;
$_GET            = array( 'id' => '302' );
check( 'HPOS: its id', 302 === Order_Screen::current_order_id() );
$_GET = array();
$GLOBALS['post'] = (object) array( 'ID' => 300, 'post_type' => 'shop_order' );
$os              = new Order_Screen();
check( 'the boxes that only point to the combined box are drawn closed on it', array( '', 'closed' ) === $os->folded_classes( array( '' ) ) );

/**
 * The theme's own answer.
 *
 * @param mixed $order WC_Order.
 * @return int|null
 */
function fishotel_order_get_fulfillment( $order ) {
	if ( empty( $GLOBALS['fh_theme_on'] ) ) {
		return (int) $order->get_meta( '_fishotel_fulfilled_by_order', true ); // As the theme answers from the same meta.
	}
	return 302 === $order->get_id() ? 777 : null;
}
$GLOBALS['fh_theme_on'] = true;
check( 'the theme\'s answer wins when it is there', true === Order_Screen::is_folded( 302 ) && false === Order_Screen::is_folded( 300 ) );

echo "6. Nothing for a visitor, nothing outside these screens\n";
$code = '';
foreach ( glob( dirname( __DIR__ ) . '/*.php' ) as $file ) {
	$code .= file_get_contents( $file );
}
check( 'no front-end hook, no REST route, no cron, no option written', 0 === preg_match( '/wp_enqueue_scripts|register_rest_route|wp_schedule|update_option|add_option/', $code ) );
check( 'user meta is written by the user store only', 1 === count( array_filter( glob( dirname( __DIR__ ) . '/*.php' ), static function ( $file ) {
	return 1 === preg_match( '/update_user_meta|delete_user_meta/', (string) file_get_contents( $file ) );
} ) ) );
check( 'no box of another plugin is removed or re-added: only titles change', 0 === preg_match( '/remove_meta_box/', $code ) && 1 === substr_count( $code, 'add_meta_box(' ) );

fh_finish();
