<?php
/**
 * Edit screens — the pure rules, on invented products and people:
 *
 *   - the layouts: every box of the product screen and the order screens
 *     named once, in its band; closed and hidden; what is never named;
 *   - the switch, once per person and screen: backup ("absent" too, and
 *     the copy under the site's prefix), the layout, the version, the
 *     notice; their own drags kept after it; the undo exactly; the reset;
 *     a new version switching once more; given back while the section is
 *     off and tidied again when it is on;
 *   - the kind of a product, and which boxes each kind shows;
 *   - the scientific name: what the field shows, when the two places
 *     differ, what a save writes;
 *   - the product data tabs, the box classes, a folded order's boxes.
 *
 * Run from the plugin root:  php includes/sections/edit-screens/tests/rules-test.php
 */

require_once __DIR__ . '/wp-stubs.php';

use FisHotel\Misc\Sections\Edit_Screens\Essentials;
use FisHotel\Misc\Sections\Edit_Screens\Kinds;
use FisHotel\Misc\Sections\Edit_Screens\Layouts;
use FisHotel\Misc\Sections\Edit_Screens\Order_Screen;
use FisHotel\Misc\Sections\Edit_Screens\Product_Screen;
use FisHotel\Misc\Sections\Edit_Screens\Switcher;
use FisHotel\Misc\Sections\Edit_Screens\User_Store;

/**
 * The ids of a column.
 *
 * @param array  $layout Layouts::product() or ::order().
 * @param string $column normal | side.
 * @return string[]
 */
function fh_col( array $layout, $column ) {
	return explode( ',', $layout['order'][ $column ] );
}

echo "1. The product screen's layout\n";
$product = Layouts::product();
$normal  = fh_col( $product, 'normal' );
$side    = fh_col( $product, 'side' );
check_same( 'the columns WordPress stores, and no column of its own (a site without this section draws it as it is)', array( 'side', 'normal', 'advanced' ), array_keys( $product['order'] ) );
check_same( 'first in the main column — shown under the title: Fish essentials, then "Price, stock & sizes"', array( 'fishotel-essentials', 'woocommerce-product-data' ), array_slice( $normal, 0, 2 ) );
check( 'then the fish facts and the short description (band 2), then staff notes, Yoast, reviews, custom fields, slug (band 3)', array( 'fishotel_hotel_data', 'postexcerpt' ) === array_slice( $normal, 2, 2 ) && array_search( 'wpseo_meta', $normal, true ) > array_search( 'postexcerpt', $normal, true ) && 'slugdiv' === end( $normal ) );
check_same( 'the side: Publish, categories (they decide the kind), the photo; gallery, tags, compatibility, Coming Soon, Clean room; brands', array( 'submitdiv', 'product_catdiv', 'postimagediv', 'woocommerce-product-images', 'tagsdiv-product_tag', 'fishotel-compat-category', 'fishotel_coming_soon', 'fishotel_cleanroom', 'product_branddiv' ), $side );
check( 'every box named once', count( array_merge( $normal, $side ) ) === count( array_unique( array_merge( $normal, $side ) ) ) );
check( 'band 3 closed: the staff notes (whichever id the theme gives them), Yoast, reviews', array() === array_diff( array( 'wpseo_meta', 'commentsdiv', 'fishotel_staff_notes' ), $product['closed'] ) && ! in_array( 'woocommerce-product-data', $product['closed'], true ) && ! in_array( 'fishotel-essentials', $product['closed'], true ) );
check_same( 'hidden (Screen Options brings them back): custom fields, slug, brands', array( 'postcustom', 'slugdiv', 'product_branddiv' ), $product['hidden'] );
check( 'every closed or hidden box is one the layout places', array() === array_diff( array_merge( $product['closed'], $product['hidden'] ), array_merge( $normal, $side ) ) );
check( 'no ShipTracker box on the product screen (it has none)', 0 === count( preg_grep( '/^fst-/', array_merge( $normal, $side ) ) ) );

echo "2. The order screens' layout\n";
$order  = Layouts::order();
$normal = fh_col( $order, 'normal' );
$side   = fh_col( $order, 'side' );
check_same( 'main: the warnings, Order data, Items, Packing, Shipments, Custom Fields (both kinds), downloads', array( 'fishotel-shipping-flag', 'fishotel-combine-skipped', 'woocommerce-order-data', 'woocommerce-order-items', 'fishotel-packing', 'fishotel-shipments', 'postcustom', 'order_custom', 'woocommerce-order-downloads' ), $normal );
check( 'side: Order actions first, then ShipTracker\'s Pickup (by its id only), Fulfillment, Weather, the EA slip and med terms, notes, Combine Orders', array( 'woocommerce-order-actions', 'fst-pickup', 'fishotel-fulfillment-delete', 'fishotel-weather', 'fishotel-med-packing-slip', 'fishotel-med-terms', 'woocommerce-order-notes', 'fishotel-combine-orders' ) === array_slice( $side, 0, 8 ) );
check_same( 'closed: custom fields, Date Change Override, Order attribution, Customer history', array( 'postcustom', 'order_custom', 'fishotel-date-change-unlock', 'woocommerce-order-source-data', 'woocommerce-customer-history' ), $order['closed'] );
check( 'the rare three are the last of the side', array( 'fishotel-date-change-unlock', 'woocommerce-order-source-data', 'woocommerce-customer-history' ) === array_slice( $side, -3 ) );
check( 'nothing hidden: what a person had hidden on an order shows again once', array() === $order['hidden'] );
check( 'the same layout on the legacy screen and on HPOS; none on another screen', Layouts::for_screen( 'shop_order' ) === Layouts::for_screen( 'woocommerce_page_wc-orders' ) && null === Layouts::for_screen( 'post' ) && null === Layouts::for_screen( array() ) );
check_same( 'the screens', array( 'product', 'shop_order', 'woocommerce_page_wc-orders' ), Layouts::screens() );
check_same( 'on a folded source order the boxes that only point to the combined box are closed', array( 'fishotel-packing', 'fishotel-weather', 'fishotel-med-packing-slip', 'fishotel-combine-orders' ), Layouts::folded_closed() );
check( '— the class "closed" added on a folded order only, once', array( 'x', 'closed' ) === Order_Screen::classes_for( array( 'x' ), true ) && array( 'closed' ) === Order_Screen::classes_for( array( 'closed' ), true ) && array( 'x' ) === Order_Screen::classes_for( array( 'x' ), false ) && array() === Order_Screen::classes_for( 'x', false ) );

echo "3. The switch, once per person\n";
$store    = new User_Store();
$switcher = new Switcher( $store );
$GLOBALS['fh_user_meta'][7] = array(
	'meta-box-order_product'   => array( 'side' => 'submitdiv,postimagediv', 'normal' => 'woocommerce-product-data,postcustom', 'advanced' => '' ),
	'closedpostboxes_product'  => array( 'postimagediv' ),
	'wp_metaboxhidden_product' => array( 'postexcerpt' ),
);
$before = $GLOBALS['fh_user_meta'][7];
check( 'never switched: due', Switcher::due( null ) && Switcher::due( array() ) );
check( 'switched to this version: not due; an older version or given back while off: due', ! Switcher::due( array( 'v' => Layouts::VERSION ) ) && Switcher::due( array( 'v' => Layouts::VERSION - 1 ) ) && Switcher::due( array( 'v' => Layouts::VERSION, 'off' => 1 ) ) );
check( 'nobody logged in, or another screen: nothing', false === $switcher->maybe_switch( 0, 'product' ) && false === $switcher->maybe_switch( 7, 'post' ) && $before === $GLOBALS['fh_user_meta'][7] );
check( 'the first visit switches', true === $switcher->maybe_switch( 7, 'product' ) );
$meta = $GLOBALS['fh_user_meta'][7];
check( 'the FisHotel layout is written where WordPress reads it', $product['order'] === $meta['meta-box-order_product'] && $product['closed'] === $meta['closedpostboxes_product'] && $product['hidden'] === $meta['metaboxhidden_product'] );
check( '— the copy under the site\'s prefix removed, so it cannot shadow it', ! array_key_exists( 'wp_metaboxhidden_product', $meta ) );
$backup = $switcher->backup( 7, 'product' );
check( 'the backup holds what they had, part by part — a part they never had as "absent"', $before['meta-box-order_product'] === $backup['order']['plain'] && null === $backup['order']['prefixed'] && $before['closedpostboxes_product'] === $backup['closed']['plain'] && null === $backup['hidden']['plain'] && array( 'postexcerpt' ) === $backup['hidden']['prefixed'] );
check( 'the version and the notice are stored', array( 'v' => Layouts::VERSION, 'notice' => 1 ) === $switcher->entry( 7, 'product' ) && $switcher->notice_due( 7, 'product' ) );
$GLOBALS['fh_user_meta'][7]['meta-box-order_product'] = array( 'side' => 'product_catdiv,submitdiv', 'normal' => 'fishotel_hotel_data', 'advanced' => '' );
$writes = $GLOBALS['fh_meta_writes'];
check( 'the next visit writes nothing: their own drags are kept', false === $switcher->maybe_switch( 7, 'product' ) && $writes === $GLOBALS['fh_meta_writes'] && 'product_catdiv,submitdiv' === $GLOBALS['fh_user_meta'][7]['meta-box-order_product']['side'] );
check( 'each screen on its own: the order screen is still due', null === $switcher->entry( 7, 'shop_order' ) && true === $switcher->maybe_switch( 7, 'shop_order' ) && $order['order'] === $GLOBALS['fh_user_meta'][7]['meta-box-order_shop_order'] );
check( 'dismiss hides the notice, nothing else', $switcher->dismiss( 7, 'product' ) && ! $switcher->notice_due( 7, 'product' ) && Layouts::VERSION === $switcher->entry( 7, 'product' )['v'] && false === $switcher->dismiss( 9, 'product' ) );

check( '"Put my old layout back": exactly what they had — the prefixed copy back, the part they never had gone again', true === $switcher->undo( 7, 'product' ) && $before['meta-box-order_product'] === $GLOBALS['fh_user_meta'][7]['meta-box-order_product'] && $before['closedpostboxes_product'] === $GLOBALS['fh_user_meta'][7]['closedpostboxes_product'] && ! array_key_exists( 'metaboxhidden_product', $GLOBALS['fh_user_meta'][7] ) && array( 'postexcerpt' ) === $GLOBALS['fh_user_meta'][7]['wp_metaboxhidden_product'] );
check( '— and the switch is not done again for this version, the notice gone', false === $switcher->maybe_switch( 7, 'product' ) && ! $switcher->notice_due( 7, 'product' ) && $before['meta-box-order_product'] === $GLOBALS['fh_user_meta'][7]['meta-box-order_product'] );
check( 'no backup, no undo', false === $switcher->undo( 8, 'product' ) && false === $switcher->undo( 7, 'post' ) );

$GLOBALS['fh_user_meta'][7]['meta-box-order_product'] = array( 'side' => 'my,own', 'normal' => '', 'advanced' => '' );
check( '"Reset to the FisHotel layout": the layout again, with the notice', true === $switcher->reset( 7, 'product' ) && $product['order'] === $GLOBALS['fh_user_meta'][7]['meta-box-order_product'] && $switcher->notice_due( 7, 'product' ) );
check( '— what they had just before is the backup now, so the undo puts it back', true === $switcher->undo( 7, 'product' ) && array( 'side' => 'my,own', 'normal' => '', 'advanced' => '' ) === $GLOBALS['fh_user_meta'][7]['meta-box-order_product'] );
check( 'reset on another screen, or for nobody: nothing', false === $switcher->reset( 0, 'product' ) && false === $switcher->reset( 7, 'edit-product' ) );

echo "4. Switched off, switched on, a new version\n";
$GLOBALS['fh_user_meta'][5] = array( 'meta-box-order_shop_order' => array( 'side' => 'woocommerce-order-notes', 'normal' => '', 'advanced' => '' ) );
$switcher->maybe_switch( 5, 'shop_order' );
$GLOBALS['fh_user_meta'][5]['closedpostboxes_shop_order'] = array( 'fishotel-weather' ); // A box they closed since.
check( 'switched off: the next visit gives their own layout back', true === $switcher->restore_off( 5, 'shop_order' ) && array( 'side' => 'woocommerce-order-notes', 'normal' => '', 'advanced' => '' ) === $GLOBALS['fh_user_meta'][5]['meta-box-order_shop_order'] && ! array_key_exists( 'closedpostboxes_shop_order', $GLOBALS['fh_user_meta'][5] ) );
$writes = $GLOBALS['fh_meta_writes'];
check( '— once', false === $switcher->restore_off( 5, 'shop_order' ) && $writes === $GLOBALS['fh_meta_writes'] );
check( '— a person who was never tidied: nothing', false === $switcher->restore_off( 6, 'shop_order' ) );
check( 'switched on again: tidied once more, with the notice', true === $switcher->maybe_switch( 5, 'shop_order' ) && $order['order'] === $GLOBALS['fh_user_meta'][5]['meta-box-order_shop_order'] && $switcher->notice_due( 5, 'shop_order' ) );
$switcher->undo( 5, 'shop_order' );
$GLOBALS['fh_user_meta'][5]['meta-box-order_shop_order'] = array( 'side' => 'mine', 'normal' => '', 'advanced' => '' );
check( 'someone who had put their old layout back keeps what they have when the section is switched off', true === $switcher->restore_off( 5, 'shop_order' ) && array( 'side' => 'mine', 'normal' => '', 'advanced' => '' ) === $GLOBALS['fh_user_meta'][5]['meta-box-order_shop_order'] );
$state                     = $switcher->state( 7 );
$state['product']['v']     = Layouts::VERSION - 1;
$GLOBALS['fh_user_meta'][7][ Switcher::STATE ] = $state;
check( 'a new layout version: switched once more', true === $switcher->maybe_switch( 7, 'product' ) && $product['order'] === $GLOBALS['fh_user_meta'][7]['meta-box-order_product'] );

echo "5. The kind of a product\n";
$fish = array( 'quarantined-fish', 'inverts' );
check_same( 'a fish category, with no stored choice: a live fish', 'fish', Kinds::kind( array( 'quarantined-fish' ), '', '', $fish ) );
check_same( 'an invert too (the Batch plugin\'s list)', 'fish', Kinds::kind( array( 'inverts' ), '', '', $fish ) );
check_same( 'Livestock ticked outside the fish categories (a coral, one day): a live fish', 'fish', Kinds::kind( array( 'corals' ), 'yes', '', $fish ) );
check_same( 'Livestock unticked in a fish category: not a live fish', 'dry', Kinds::kind( array( 'quarantined-fish' ), 'no', '', $fish ) );
check_same( 'a medication category', 'med', Kinds::kind( array( 'antiparasitic' ), '', '', $fish ) );
check_same( 'a food category', 'med', Kinds::kind( array( 'freeze-dried-foods' ), '', 'none', $fish ) );
check_same( '"Who ships it" set: medication', 'med', Kinds::kind( array( 'equipment' ), '', 'ea', $fish ) );
check_same( 'an invoice is never a fish', 'invoice', Kinds::kind( array( 'invoices', 'quarantined-fish' ), 'yes', '', $fish ) );
check_same( 'anything else: a dry good', 'dry', Kinds::kind( array( 'gift-cards' ), '', '', $fish ) );
check_same( 'no category yet', 'none', Kinds::kind( array(), '', '', $fish ) );
check( 'capitals and spaces in a slug, nonsense in the flags', 'fish' === Kinds::kind( array( ' Quarantined-Fish ' ), null, array(), $fish ) && 'dry' === Kinds::kind( array( 'x' ), true, false, $fish ) );
check( 'the fish categories: the default and the Batch plugin\'s filter', array( 'quarantined-fish', 'inverts' ) === Kinds::fish_categories() && ( function () {
	add_filter(
		'fishotel_ship_day_fish_categories',
		static function ( $cats ) {
			return array_merge( $cats, array( 'Corals', 'corals', '' ) );
		}
	);
	$out = Kinds::fish_categories();
	fh_reset_hooks();
	return array( 'quarantined-fish', 'inverts', 'corals' ) === $out;
} )() );
check( 'a live fish shows the facts, compatibility and Clean room boxes and not the short description', Kinds::shows( 'fishotel_hotel_data', 'fish' ) && Kinds::shows( 'fishotel_cleanroom', 'fish' ) && ! Kinds::shows( 'postexcerpt', 'fish' ) );
check( 'a dry good, a medication, an invoice: the reverse', ! Kinds::shows( 'fishotel_hotel_data', 'dry' ) && ! Kinds::shows( 'fishotel-compat-category', 'med' ) && ! Kinds::shows( 'fishotel_cleanroom', 'invoice' ) && Kinds::shows( 'postexcerpt', 'dry' ) );
check( 'no category yet: everything shows', Kinds::shows( 'fishotel_hotel_data', 'none' ) && Kinds::shows( 'postexcerpt', 'none' ) );
check( 'every other box shows for every kind (Coming Soon, staff notes, the product data)', Kinds::shows( 'fishotel_coming_soon', 'dry' ) && Kinds::shows( 'fishotel_staff_notes', 'med' ) && Kinds::shows( 'woocommerce-product-data', 'fish' ) );
check( 'the box classes carry it', array( 'x', 'fh-es-live-only' ) === Product_Screen::box_classes( 'fishotel_hotel_data', array( 'x' ) ) && array( 'fh-es-not-live' ) === Product_Screen::box_classes( 'postexcerpt', null ) && array( 'x' ) === Product_Screen::box_classes( 'submitdiv', array( 'x' ) ) );

echo "6. The scientific name\n";
check_same( 'the table\'s name first, the subtitle\'s when the table has none', array( 'Paracanthurus hepatus', 'Inventus hepatus' ), array( Essentials::sci_view( 'Inventus hepatus', 'Paracanthurus hepatus' )['value'], Essentials::sci_view( '<em>Inventus hepatus</em>', '' )['value'] ) );
check( 'the two differ: both are shown', true === Essentials::sci_view( 'Inventus hepatus', 'Paracanthurus hepatus' )['differ'] );
check( 'markup, capitals and spaces do not make a difference', false === Essentials::sci_view( '<p><em>Paracanthurus  HEPATUS</em></p>', 'paracanthurus hepatus' )['differ'] && false === Essentials::sci_view( 'Fishus&nbsp;inventus', 'Fishus inventus' )['differ'] );
check( 'one empty: no difference to show', false === Essentials::sci_view( '', 'Fishus inventus' )['differ'] && false === Essentials::sci_view( 'Fishus inventus', '' )['differ'] );
check_same( 'a changed name is written', 'Fishus inventus', Essentials::decide_sci( ' Fishus  <b>inventus</b> ', 'Fishus other' ) );
check( 'as it was drawn: nothing is written', null === Essentials::decide_sci( 'Fishus inventus', 'Fishus inventus' ) && null === Essentials::decide_sci( ' Fishus inventus ', 'Fishus inventus' ) );
check( 'emptied: both places are cleared', '' === Essentials::decide_sci( '', 'Fishus inventus' ) );
check( 'not on the form, or nonsense: nothing', null === Essentials::decide_sci( null, 'x' ) && null === Essentials::decide_sci( array( 'x' ), 'y' ) && null === Essentials::decide_sci( 'x', null ) && null === Essentials::decide_sci( true, 'x' ) );
check( 'a name is kept to ' . Essentials::MAX . ' characters', Essentials::MAX === strlen( Essentials::sci_plain( str_repeat( 'a', 300 ) ) ) );

echo "7. Names and tabs\n";
check_same( 'the four new titles', array( 'Price, stock & sizes', 'Fish facts — the table on the product page', 'Compatibility guide', 'Coming Soon — release date' ), array_values( Product_Screen::titles() ) );
$tabs = Product_Screen::tabs(
	array(
		'general'      => array( 'priority' => 10 ),
		'inventory'    => array( 'priority' => 20 ),
		'shipping'     => array( 'priority' => 30 ),
		'linked'       => array( 'priority' => 40 ),
		'attribute'    => array( 'priority' => 50 ),
		'variations'   => array( 'priority' => 60 ),
		'fishotel_med' => array( 'priority' => 65 ),
		'advanced'     => array( 'priority' => 70 ),
	)
);
uasort(
	$tabs,
	static function ( $a, $b ) {
		return $a['priority'] <=> $b['priority'];
	}
);
check_same( 'tabs: General, Attributes, Variations, the medication tab, Inventory, Shipping, Linked, Advanced', array( 'general', 'attribute', 'variations', 'fishotel_med', 'inventory', 'shipping', 'linked', 'advanced' ), array_keys( $tabs ) );
check( 'a tab that is not there is not added; nonsense is handed back', array( 'general' => array( 'priority' => 10 ) ) === Product_Screen::tabs( array( 'general' => array( 'priority' => 10 ) ) ) && 'x' === Product_Screen::tabs( 'x' ) );

fh_finish();
