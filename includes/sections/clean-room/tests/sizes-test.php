<?php
/**
 * Sizes — the one list of fish sizes a supplier order line and a stay
 * are sized with, the same one the product variations use. With invented
 * attributes, terms, fish and numbers on the in-memory Fake_Store: where
 * the list comes from (the product attribute that matches "size", the
 * one Settings picked when several do, the plain list when none exists),
 * the order of its terms, a stored size that is no longer in the list
 * (still shown, flagged, still selectable on its own row; any other
 * unknown slug refused), the read-only "Sizes in use on products"
 * report, the settings keys and the Settings → Sizes save (capability,
 * nonce, invalid input, the Numbers form never wiping them), the size
 * carried from an order line to its stay at check-in and on to a sister
 * stay on a split, the Stays tab's Edit, the Front Desk arrival and
 * rows, the exports, and the voice parser still ignoring size words.
 *
 * Run from the plugin root:  php includes/sections/clean-room/tests/sizes-test.php
 */

require_once __DIR__ . '/wp-stubs.php';

use FisHotel\Misc\Sections\Clean_Room\Admin;
use FisHotel\Misc\Sections\Clean_Room\Admin_Workbook;
use FisHotel\Misc\Sections\Clean_Room\Clean_Room;
use FisHotel\Misc\Sections\Clean_Room\Front_Desk;
use FisHotel\Misc\Sections\Clean_Room\Orders;
use FisHotel\Misc\Sections\Clean_Room\Parser;
use FisHotel\Misc\Sections\Clean_Room\Replay;
use FisHotel\Misc\Sections\Clean_Room\Settings;
use FisHotel\Misc\Sections\Clean_Room\Sizes;
use FisHotel\Misc\Sections\Clean_Room\Store;
use FisHotel\Misc\Sections\Clean_Room\Tests\Fake_Store;

$today = Replay::date_in_zone( time(), wp_timezone() );

/**
 * A store whose shop has one "size" attribute with three terms in a
 * custom (drag-and-drop) order.
 */
function fh_sized_store( array $products = array() ) {
	$store                  = new Fake_Store( $products );
	$store->size_attributes = array(
		array( 'taxonomy' => 'pa_size', 'name' => 'size', 'label' => 'Size', 'orderby' => 'menu_order' ),
	);
	$store->size_terms      = array(
		'pa_size' => array(
			array( 'term_id' => 31, 'name' => '3-4"', 'slug' => '3-4', 'menu_order' => 2 ),
			array( 'term_id' => 30, 'name' => '1-2"', 'slug' => '1-2', 'menu_order' => 0 ),
			array( 'term_id' => 32, 'name' => '2-3"', 'slug' => '2-3', 'menu_order' => 1 ),
		),
	);
	return $store;
}

echo "1. What counts as a size, slugs, a typed list\n";
check( 'an attribute name, slug or variation key that contains "size", whatever the case', Sizes::matches( 'pa_size' ) && Sizes::matches( 'Fish Size' ) && Sizes::matches( 'attribute_pa_fish-sizes' ) && ! Sizes::matches( 'pa_color' ) && ! Sizes::matches( '' ) );
check( 'a label\'s slug reads like a term slug', '2-3-5' === Sizes::slug( ' 2 - 3.5" ' ) && 'x-large' === Sizes::slug( 'X-Large' ) && '' === Sizes::slug( '"' ) );
check( 'a typed list: one per line, trimmed, the inch mark kept, empty and repeated ones (by slug) dropped', array( '1-2"', '2 - 3.5"', 'Show size' ) === Sizes::labels( " 1-2\" \r\n\n2 - 3.5\"\n1-2\"\n\"\nShow   size\n" ) );
check( 'a list given as an array; junk entries skipped; control characters dropped; at most ' . Sizes::MAX_SIZES, array( 'A', 'B c' ) === Sizes::labels( array( 'A', array( 'x' ), true, "B\x07c", '' ) ) && Sizes::MAX_SIZES === count( Sizes::labels( range( 1, Sizes::MAX_SIZES + 10 ) ) ) );
check( 'labels become sizes: slug + label', array( array( 'slug' => '1-2', 'label' => '1-2"' ), array( 'slug' => 'under-1', 'label' => 'under 1"' ) ) === Sizes::from_labels( array( '1-2"', 'under 1"' ) ) );

echo "2. An attribute's terms in the order the attribute is set to\n";
$terms = array(
	array( 'term_id' => 5, 'name' => '10', 'slug' => 'ten', 'menu_order' => 1 ),
	array( 'term_id' => 3, 'name' => '9', 'slug' => 'nine', 'menu_order' => 2 ),
	array( 'term_id' => 4, 'name' => 'Big', 'slug' => 'big', 'menu_order' => 0 ),
);
check( 'the custom order (the default)', array( 'big', 'ten', 'nine' ) === array_column( Sizes::order_terms( $terms ), 'slug' ) && array( 'big', 'ten', 'nine' ) === array_column( Sizes::order_terms( $terms, 'menu_order' ), 'slug' ) );
check( 'by name (natural: 9 before 10)', array( 'nine', 'ten', 'big' ) === array_column( Sizes::order_terms( $terms, 'name' ), 'slug' ) );
check( 'by the name read as a number (a word reads 0)', array( 'big', 'nine', 'ten' ) === array_column( Sizes::order_terms( $terms, 'name_num' ), 'slug' ) );
check( 'by term id', array( 'nine', 'big', 'ten' ) === array_column( Sizes::order_terms( $terms, 'id' ), 'slug' ) );

echo "3. Where the standard list comes from\n";
$store = fh_sized_store();
$std   = Sizes::standard( $store, Settings::get() );
check( 'one attribute matches: its terms, in their stored order, labelled as stored', Sizes::SOURCE_ATTRIBUTE === $std['source'] && 'pa_size' === $std['attribute'] && 'Size' === $std['label'] && array( array( 'slug' => '1-2', 'label' => '1-2"' ), array( 'slug' => '2-3', 'label' => '2-3"' ), array( 'slug' => '3-4', 'label' => '3-4"' ) ) === $std['sizes'] && 1 === count( $std['attributes'] ) );
$store->size_attributes[]        = array( 'taxonomy' => 'pa_shoe-size', 'name' => 'shoe-size', 'label' => 'Shoe size', 'orderby' => 'name' );
$store->size_terms['pa_shoe-size'] = array(
	array( 'term_id' => 41, 'name' => 'Wide', 'slug' => 'wide', 'menu_order' => 0 ),
	array( 'term_id' => 40, 'name' => 'Narrow', 'slug' => 'narrow', 'menu_order' => 5 ),
);
$std = Sizes::standard( $store, Settings::get() );
check( 'several match and nothing was picked: the first one, both offered', 'pa_size' === $std['attribute'] && 2 === count( $std['attributes'] ) && 3 === count( $std['sizes'] ) );
$std = Sizes::standard( $store, Settings::normalize( array( 'size_attribute' => 'pa_shoe-size' ) ) );
check( 'several match and Settings picked one: that one, its terms by name', 'pa_shoe-size' === $std['attribute'] && 'Shoe size' === $std['label'] && array( 'narrow', 'wide' ) === array_column( $std['sizes'], 'slug' ) );
$std = Sizes::standard( $store, Settings::normalize( array( 'size_attribute' => 'pa_gone' ) ) );
check( 'the picked attribute no longer exists: back to the first match', 'pa_size' === $std['attribute'] );
$bare = new Fake_Store();
$std  = Sizes::standard( $bare, Settings::normalize( array( 'size_list' => array( 'Small', 'Medium', 'Large' ) ) ) );
check( 'no attribute matches: the plain list of the settings', Sizes::SOURCE_LIST === $std['source'] && '' === $std['attribute'] && array() === $std['attributes'] && array( 'small', 'medium', 'large' ) === array_column( $std['sizes'], 'slug' ) && array( 'Small', 'Medium', 'Large' ) === array_column( $std['sizes'], 'label' ) );
$std = Sizes::standard( $bare, Settings::get() );
check( 'no attribute and no list: an empty list, and the feature still works', Sizes::SOURCE_LIST === $std['source'] && array() === $std['sizes'] );
$std = Sizes::standard( $store, Settings::normalize( array( 'size_list' => array( 'Small' ) ) ) );
check( 'an attribute wins over a typed list', Sizes::SOURCE_ATTRIBUTE === $std['source'] && ! in_array( 'small', array_column( $std['sizes'], 'slug' ), true ) );
$empty                  = new Fake_Store();
$empty->size_attributes = array( array( 'taxonomy' => 'pa_size', 'name' => 'size', 'label' => 'Size', 'orderby' => 'menu_order' ) );
$std                    = Sizes::standard( $empty, Settings::normalize( array( 'size_list' => array( 'Small' ) ) ) );
check( 'an attribute without terms is still the list (an empty one)', Sizes::SOURCE_ATTRIBUTE === $std['source'] && array() === $std['sizes'] );
check( 'resolve() is pure: a term twice, or without a slug, is one size or none', array( 'a' ) === array_column( Sizes::resolve( array( array( 'taxonomy' => 'pa_size', 'label' => 'Size' ) ), array( array( 'term_id' => 1, 'name' => 'A', 'slug' => 'a' ), array( 'term_id' => 2, 'name' => 'A again', 'slug' => 'a' ), array( 'term_id' => 3, 'name' => 'No slug', 'slug' => '' ) ), array() )['sizes'], 'slug' ) );
check( 'an object that is not a store gives the plain list', Sizes::SOURCE_LIST === Sizes::standard( new \stdClass(), Settings::get() )['source'] );

echo "4. A picked size, and a stored size that is no longer in the list\n";
$sizes = Sizes::standard( fh_sized_store(), Settings::get() )['sizes'];
check( 'nothing picked: no size', array( 'ok' => true, 'slug' => null, 'label' => null ) === Sizes::pick( '', $sizes ) && array( 'ok' => true, 'slug' => null, 'label' => null ) === Sizes::pick( null, $sizes ) );
check( 'a size of the list: its slug with the label as it reads now', array( 'ok' => true, 'slug' => '2-3', 'label' => '2-3"' ) === Sizes::pick( ' 2-3 ', $sizes, array( 'size_slug' => '2-3', 'size_label' => 'an older label' ) ) );
check( 'a slug that is not in the list is refused', array( 'ok' => false, 'slug' => null, 'label' => null ) === Sizes::pick( 'jumbo', $sizes ) && false === Sizes::pick( array( '2-3' ), $sizes )['ok'] );
check( 'the row\'s own stored size stays selectable when it left the list, with its snapshot', array( 'ok' => true, 'slug' => 'jumbo', 'label' => 'Jumbo 8"' ) === Sizes::pick( 'jumbo', $sizes, array( 'size_slug' => 'jumbo', 'size_label' => 'Jumbo 8"' ) ) && false === Sizes::pick( 'jumbo', $sizes, array( 'size_slug' => 'other', 'size_label' => 'Other' ) )['ok'] );
check( 'of(): a row without a size is null; a standard one reads the list\'s label; a stale one keeps its snapshot and is flagged', null === Sizes::of( array( 'size_slug' => null ), $sizes ) && array( 'slug' => '1-2', 'label' => '1-2"', 'standard' => true ) === Sizes::of( array( 'size_slug' => '1-2', 'size_label' => 'old' ), $sizes ) && array( 'slug' => 'jumbo', 'label' => 'Jumbo 8"', 'standard' => false ) === Sizes::of( array( 'size_slug' => 'jumbo', 'size_label' => 'Jumbo 8"' ), $sizes ) );
check( 'of() without a list flags nothing and shows the snapshot', array( 'slug' => 'jumbo', 'label' => 'Jumbo 8"', 'standard' => true ) === Sizes::of( array( 'size_slug' => 'jumbo', 'size_label' => 'Jumbo 8"' ) ) );
check( 'text(): the label, and "(not a standard size)" on a stale one', '' === Sizes::text( array(), $sizes ) && '2-3"' === Sizes::text( array( 'size_slug' => '2-3', 'size_label' => '2-3"' ), $sizes ) && 'Jumbo 8" (not a standard size)' === Sizes::text( array( 'size_slug' => 'jumbo', 'size_label' => 'Jumbo 8"' ), $sizes ) );
$opts = Sizes::options( $sizes, array( 'size_slug' => '2-3', 'size_label' => '2-3"' ) );
check( 'options(): the list, the row\'s size selected', array( '1-2', '2-3', '3-4' ) === array_column( $opts, 'value' ) && array( false, true, false ) === array_column( $opts, 'selected' ) );
$opts = Sizes::options( $sizes, array( 'size_slug' => 'jumbo', 'size_label' => 'Jumbo 8"' ) );
check( 'options(): a stale size is one more option — marked, selected', 4 === count( $opts ) && 'jumbo' === $opts[3]['value'] && 'Jumbo 8" (not a standard size)' === $opts[3]['label'] && true === $opts[3]['selected'] && false === $opts[3]['standard'] );
check( 'options(): a row without a size selects nothing', array( false, false, false ) === array_column( Sizes::options( $sizes ), 'selected' ) );
check( 'size_html(): escaped, the warning only on a stale size, nothing without a size', '' === Admin::size_html( array(), $sizes ) && '<span class="fh-cr-size">2-3&quot;</span>' === Admin::size_html( array( 'size_slug' => '2-3', 'size_label' => '2-3"' ), $sizes ) && false !== strpos( Admin::size_html( array( 'size_slug' => 'x', 'size_label' => '<b>X</b>' ), $sizes ), '&lt;b&gt;X&lt;/b&gt;</span> <span class="fh-cr-warn"' ) && false !== strpos( Admin::size_html( array( 'size_slug' => 'x', 'size_label' => 'X' ), $sizes ), 'not a standard size' ) );

echo "5. The \"Sizes in use on products\" report (read-only)\n";
$store                  = fh_sized_store();
$store->variation_sizes = array(
	array( 'key' => 'pa_size', 'global' => true, 'value' => '2-3', 'label' => '2-3"', 'products' => 14 ),
	array( 'key' => 'pa_size', 'global' => true, 'value' => 'xl', 'label' => 'XL', 'products' => 2 ),
	array( 'key' => 'pa_shoe-size', 'global' => true, 'value' => '2-3', 'label' => '2-3"', 'products' => 1 ),
	array( 'key' => 'size', 'global' => false, 'value' => '1-2"', 'label' => '1-2"', 'products' => 3 ),
	array( 'key' => 'fish-size', 'global' => false, 'value' => 'about 5 inches', 'label' => 'about 5 inches', 'products' => 1 ),
);
$panel                  = Admin::sizes_panel( $store, Settings::get() );
$report                 = $panel['report'];
check( 'every value is listed with how many products use it; the ones to clean up come first', 5 === count( $report ) && array( false, false, false, true, true ) === array_column( $report, 'standard' ) && array( 14, 3 ) === array( $report[4]['products'], $report[3]['products'] ) );
$by = array();
foreach ( $report as $row ) {
	$by[ $row['key'] . '|' . $row['value'] ] = $row;
}
check( 'a term of the attribute in use whose slug is in the list is standard', true === $by['pa_size|2-3']['standard'] && '' === $by['pa_size|2-3']['why'] );
check( 'a term that is not in the list is flagged', false === $by['pa_size|xl']['standard'] && 'not in the standard list' === $by['pa_size|xl']['why'] );
check( 'the same slug on ANOTHER attribute is flagged: it is not the standard attribute', false === $by['pa_shoe-size|2-3']['standard'] && false !== strpos( $by['pa_shoe-size|2-3']['why'], 'pa_shoe-size' ) );
check( 'a value typed on a product that reads like a standard label is standard; any other is flagged', true === $by['size|1-2"']['standard'] && false === $by['fish-size|about 5 inches']['standard'] && 'typed on the product, not in the standard list' === $by['fish-size|about 5 inches']['why'] );
check( 'the panel: the standard list, the links to Products → Attributes and to the attribute\'s terms', 'pa_size' === $panel['standard']['attribute'] && false !== strpos( $panel['attributes_url'], 'page=product_attributes' ) && false !== strpos( $panel['terms_url'], 'taxonomy=pa_size' ) && '' === $panel['list_text'] );
$panel = Admin::sizes_panel( new Fake_Store(), Settings::normalize( array( 'size_list' => array( 'Small', 'Large' ) ) ) );
check( 'without an attribute: the plain list as the textarea holds it, no terms link, an empty report', "Small\nLarge" === $panel['list_text'] && '' === $panel['terms_url'] && array() === $panel['report'] && Sizes::SOURCE_LIST === $panel['standard']['source'] );
check( 'the report never writes: the store\'s values are as they were', 5 === count( $store->variation_sizes ) && 'xl' === $store->variation_sizes[1]['value'] );

echo "6. The settings keys and the Settings → Sizes save\n";
$defaults = Settings::get();
check( 'the two keys exist with empty defaults', '' === $defaults['size_attribute'] && array() === $defaults['size_list'] && array_key_exists( 'size_attribute', Settings::DEFAULTS ) && array_key_exists( 'size_list', Settings::DEFAULTS ) );
check( 'normalize(): the attribute as text, the list as clean labels; junk reads as empty', 'pa_size' === Settings::normalize( array( 'size_attribute' => ' pa_size ' ) )['size_attribute'] && '' === Settings::normalize( array( 'size_attribute' => array( 'x' ) ) )['size_attribute'] && array( 'A', 'B' ) === Settings::normalize( array( 'size_list' => "A\nB\nA" ) )['size_list'] && array() === Settings::normalize( array( 'size_list' => 7 ) )['size_list'] );
$dropped = array();
$numbers = Settings::sanitize( array( 'sale_ready_days' => '30', 'copper_floor' => '2', 'copper_target' => '2.4', 'auto_confirm_seconds' => '3' ), $dropped );
$kept    = Settings::with_sizes( $numbers, array( 'size_attribute' => 'pa_size', 'size_list' => array( 'Small' ) ) );
check( 'the Numbers form posts no size: its save keeps the stored ones (with_sizes())', '' === $numbers['size_attribute'] && 'pa_size' === $kept['size_attribute'] && array( 'Small' ) === $kept['size_list'] && 30 === $kept['sale_ready_days'] && false === $kept['voice_enabled'] );

$GLOBALS['fh_options']      = array();
$GLOBALS['fh_user_caps']    = array( Clean_Room::SETTINGS_CAPABILITY => array( 1 ), Clean_Room::CAPABILITY => array( 1, 2 ) );
$GLOBALS['fh_current_user'] = 2;
$nonce                      = wp_create_nonce( Settings::NONCE_ACTION );
$store                      = fh_sized_store();
$store->size_attributes[]   = array( 'taxonomy' => 'pa_shoe-size', 'name' => 'shoe-size', 'label' => 'Shoe size', 'orderby' => 'name' );
$r                          = Settings::sizes_request( $store, array( '_wpnonce' => $nonce, 'size_attribute' => 'pa_shoe-size' ) );
check( 'a user who may not change settings is refused (403), nothing stored', 403 === $r['status'] && false === $r['ok'] && array() === $GLOBALS['fh_options'] );
$GLOBALS['fh_current_user'] = 1;
$r                          = Settings::sizes_request( $store, array( '_wpnonce' => 'nope', 'size_attribute' => 'pa_shoe-size' ) );
check( 'a wrong nonce is refused (403), nothing stored', 403 === $r['status'] && false === $r['ok'] && array() === $GLOBALS['fh_options'] );
$r = Settings::sizes_request( $store, array( 'size_attribute' => 'pa_shoe-size' ) );
check( 'no nonce at all is refused too', 403 === $r['status'] && array() === $GLOBALS['fh_options'] );
$r = Settings::sizes_request( $store, array( '_wpnonce' => $nonce, 'size_attribute' => 'pa_color' ) );
check( 'an attribute that is not one of the "size" attributes is refused (400), nothing stored', 400 === $r['status'] && false === $r['ok'] && array() === $GLOBALS['fh_options'] );
$r = Settings::sizes_request( $store, array( '_wpnonce' => $nonce, 'size_list' => 12 ) );
check( 'a list that is not text is refused (400)', 400 === $r['status'] && array() === $GLOBALS['fh_options'] );
update_option( Clean_Room::OPTION_KEY, array( 'sale_ready_days' => 35, 'copper_floor' => 2.1, 'voice_enabled' => true ) );
$r = Settings::sizes_request( $store, array( '_wpnonce' => $nonce, 'size_attribute' => 'pa_shoe-size' ) );
check( 'a valid pick is stored, and says which attribute is the list now', 200 === $r['status'] && true === $r['ok'] && 'pa_shoe-size' === Settings::get()['size_attribute'] && false !== strpos( $r['message'], 'Shoe size' ) );
check( 'only the sizes changed: the other settings are as they were', 35 === Settings::get()['sale_ready_days'] && 2.1 === Settings::get()['copper_floor'] && true === Settings::get()['voice_enabled'] );
$r = Settings::sizes_request( $store, array( '_wpnonce' => $nonce, 'size_attribute' => '' ) );
check( 'an empty pick goes back to the first match', 200 === $r['status'] && '' === Settings::get()['size_attribute'] && 'pa_size' === Sizes::standard( $store, Settings::get() )['attribute'] );
$bare = new Fake_Store();
$r    = Settings::sizes_request( $bare, array( '_wpnonce' => $nonce, 'size_list' => "1-2\"\n <1\" \n2-3\"\n\n1-2\"" ) );
check( 'the plain list: one size per line, the inch mark and a "<" kept as typed, repeats dropped', 200 === $r['status'] && array( '1-2"', '<1"', '2-3"' ) === Settings::get()['size_list'] && 'Sizes saved: 3 sizes in the list.' === $r['message'] && array( '1-2', '1', '2-3' ) === array_column( Sizes::standard( $bare, Settings::get() )['sizes'], 'slug' ) );
$r = Settings::sizes_request( $bare, array( '_wpnonce' => $nonce ) );
check( 'a save that posts neither key changes neither', 200 === $r['status'] && 3 === count( Settings::get()['size_list'] ) );
$GLOBALS['fh_options'] = array();

echo "7. The size goes from the order line to its stay, and on to a sister stay\n";
$settings = Settings::get();
$store    = fh_sized_store( array( 21 => 'Blue Test Wrasse' ) );
$saved    = Admin_Workbook::save_order(
	$store,
	array(
		'ref'           => '2026-1',
		'supplier'      => 'Test Supplier One',
		'origin'        => 'Testland',
		'received_date' => $today,
		'status'        => 'ordered',
		'lines'         => array(
			array( 'species' => 'Blue Test Wrasse', 'size_slug' => '2-3', 'size_label' => 'a label the form made up', 'qty_ordered' => 6, 'qty_received' => 6, 'unit_price' => 5 ),
			array( 'species' => 'Red Test Goby', 'size_slug' => '', 'qty_ordered' => 4, 'qty_received' => 4, 'unit_price' => 2 ),
			array( 'species' => 'Green Test Blenny', 'size_slug' => 'jumbo', 'qty_ordered' => 2, 'qty_received' => 2, 'unit_price' => 9 ),
		),
	),
	0,
	$today
);
$order    = $store->order( $saved['order_id'] );
check( 'the line stores the slug with the label the LIST has (never the posted one)', empty( $saved['error'] ) && '2-3' === $order['lines'][0]['size_slug'] && '2-3"' === $order['lines'][0]['size_label'] );
check( 'a line without a size has none', null === $order['lines'][1]['size_slug'] && null === $order['lines'][1]['size_label'] );
check( 'a slug that is not in the list: the order is saved, that line has no size, and the message says so', null === $order['lines'][2]['size_slug'] && 1 === count( $saved['warnings'] ) && false !== strpos( $saved['message'], 'line 3 (Green Test Blenny)' ) && 0 === strpos( $saved['message'], 'Order saved.' ) );
$out   = Orders::check_in( $store, $order, $today, $settings, array( 'actor_id' => 1 ) );
$stays = array_column( $out['created'], 'stay_id' );
$wrasse = $store->stay( $stays[0], $today, $settings );
$goby   = $store->stay( $stays[1], $today, $settings );
check( 'check-in: the stay has the line\'s size (slug + label)', 3 === count( $stays ) && '2-3' === $wrasse['size_slug'] && '2-3"' === $wrasse['size_label'] );
check( 'a line without a size gives a stay without one', null === $goby['size_slug'] && null === $goby['size_label'] );
$split  = $store->split_stay( $wrasse, 2, $store->tank_by_number( 522 ), array( 'actor_id' => 1, 'source' => 'manual' ), $today, $settings, 'pairing' );
$sister = $store->stay( $split['stay_id'], $today, $settings );
check( 'a split: the sister stay is the same fish of the same size', 2 === $sister['qty_live'] && '2-3' === $sister['size_slug'] && '2-3"' === $sister['size_label'] && $wrasse['order_line_id'] === $sister['order_line_id'] && 4 === $store->stay( $stays[0], $today, $settings )['qty_live'] );
// The term is removed from the attribute: the line's stored size is stale now.
$store->size_terms['pa_size'] = array_values( array_filter( $store->size_terms['pa_size'], static function ( $term ) { return '2-3' !== $term['slug']; } ) );
$lines                        = array();
foreach ( $store->order( $saved['order_id'] )['lines'] as $line ) {
	$lines[] = array( 'id' => $line['id'], 'species' => $line['species'], 'size_slug' => (string) $line['size_slug'], 'qty_ordered' => $line['qty_ordered'], 'qty_received' => $line['qty_received'], 'unit_price' => $line['unit_price'] );
}
$again = Admin_Workbook::save_order( $store, array( 'ref' => '2026-1', 'supplier' => 'Test Supplier One', 'origin' => 'Testland', 'received_date' => $today, 'status' => 'received', 'lines' => $lines ), $saved['order_id'], $today );
$order = $store->order( $saved['order_id'] );
check( 'a size that left the list stays on the line that has it, with its snapshot, and no warning', array() === $again['warnings'] && '2-3' === $order['lines'][0]['size_slug'] && '2-3"' === $order['lines'][0]['size_label'] );
$lines[1]['size_slug'] = '2-3';
$again                 = Admin_Workbook::save_order( $store, array( 'ref' => '2026-1', 'supplier' => 'Test Supplier One', 'origin' => 'Testland', 'received_date' => $today, 'status' => 'received', 'lines' => $lines ), $saved['order_id'], $today );
check( '…but it cannot be given to another line any more', 1 === count( $again['warnings'] ) && null === $store->order( $saved['order_id'] )['lines'][1]['size_slug'] );
$sizes = Sizes::standard( $store, $settings )['sizes'];
check( 'the stale size still displays, marked', '2-3" (not a standard size)' === Sizes::text( $store->stay( $stays[0], $today, $settings ), $sizes ) );
$lines[0]['size_slug'] = '';
$lines[1]['size_slug'] = '';
Admin_Workbook::save_order( $store, array( 'ref' => '2026-1', 'supplier' => 'Test Supplier One', 'origin' => 'Testland', 'received_date' => $today, 'status' => 'received', 'lines' => $lines ), $saved['order_id'], $today );
check( 'clearing the size on the line clears slug and label', null === $store->order( $saved['order_id'] )['lines'][0]['size_slug'] && null === $store->order( $saved['order_id'] )['lines'][0]['size_label'] );
check( 'normalize_line(): the size as posted text, no size without a slug', array( 'x', 'X' ) === array( Orders::normalize_line( array( 'species' => 'a', 'size_slug' => ' x ', 'size_label' => 'X' ) )['size_slug'], Orders::normalize_line( array( 'species' => 'a', 'size_slug' => ' x ', 'size_label' => 'X' ) )['size_label'] ) && null === Orders::normalize_line( array( 'species' => 'a', 'size_label' => 'X' ) )['size_label'] && null === Orders::normalize_line( array( 'species' => 'a' ) )['size_slug'] );

echo "8. The Stays tab's Edit\n";
$store   = fh_sized_store( array( 21 => 'Blue Test Wrasse' ) );
$stay_id = $store->create_stay( array( 'species' => 'Blue Test Wrasse', 'checkin_date' => $today, 'tank_id' => $store->tank_by_number( 521 )['id'], 'supplier' => 'Test Supplier One' ) );
$base    = array( 'actor_id' => 1, 'source' => 'manual', 'event_at' => gmdate( 'Y-m-d H:i:s' ) );
$store->record_event( $stay_id, array_merge( $base, array( 'type' => 'arrival', 'qty' => 5, 'to_tank_id' => $store->tank_by_number( 521 )['id'] ) ), $today, $settings );
$edit = static function ( array $raw ) use ( $store, $stay_id, $base, $today, $settings ) {
	return Admin::edit_stay_fields( $store, $store->stay( $stay_id, $today, $settings ), $raw, $base, $today, $settings );
};
$r    = $edit( array( 'species' => 'Blue Test Wrasse', 'supplier' => 'Test Supplier One', 'size_slug' => '3-4' ) );
$s    = $store->stay( $stay_id, $today, $settings );
check( 'a standard size is saved with its label', true === $r['ok'] && 200 === $r['status'] && 'Stay saved.' === $r['message'] && '3-4' === $s['size_slug'] && '3-4"' === $s['size_label'] && 'Test Supplier One' === $s['supplier'] );
$r = $edit( array( 'species' => 'Renamed Test Wrasse', 'supplier' => 'Someone Else', 'size_slug' => 'jumbo' ) );
$s = $store->stay( $stay_id, $today, $settings );
check( 'a size that is not in the list is refused (400) and NOTHING of the edit is written', false === $r['ok'] && 400 === $r['status'] && false !== strpos( $r['message'], 'not in the list' ) && '3-4' === $s['size_slug'] && 'Blue Test Wrasse' === $s['species'] && 'Test Supplier One' === $s['supplier'] );
$r = $edit( array( 'species' => 'Blue Test Wrasse', 'supplier' => 'Test Supplier One' ) );
check( 'an edit that posts no size leaves it alone', true === $r['ok'] && '3-4' === $store->stay( $stay_id, $today, $settings )['size_slug'] );
$store->size_terms['pa_size'] = array_values( array_filter( $store->size_terms['pa_size'], static function ( $term ) { return '3-4' !== $term['slug']; } ) );
$r                            = $edit( array( 'species' => 'Blue Test Wrasse', 'size_slug' => '3-4' ) );
check( 'the stay\'s own size stays as stored once it has left the list', true === $r['ok'] && '3-4' === $store->stay( $stay_id, $today, $settings )['size_slug'] && '3-4"' === $store->stay( $stay_id, $today, $settings )['size_label'] );
$r = $edit( array( 'species' => 'Blue Test Wrasse', 'size_slug' => '' ) );
check( 'an empty size clears it', true === $r['ok'] && null === $store->stay( $stay_id, $today, $settings )['size_slug'] && null === $store->stay( $stay_id, $today, $settings )['size_label'] );
$r = $edit( array( 'product_id' => 999, 'size_slug' => '1-2' ) );
check( 'the older guards still stand: an unknown product is refused before anything is written', false === $r['ok'] && 400 === $r['status'] && 'No product has that ID.' === $r['message'] && null === $store->stay( $stay_id, $today, $settings )['size_slug'] );
$r = $edit( array( 'checkin_date' => Replay::add_days( $today, 1 ), 'size_slug' => '1-2' ) );
check( '…and a check-in in the future', false === $r['ok'] && 'A check-in cannot be in the future.' === $r['message'] && null === $store->stay( $stay_id, $today, $settings )['size_slug'] );
$events = $store->event_count();
$r      = $edit( array( 'product_id' => 21, 'checkin_date' => Replay::add_days( $today, -2 ), 'size_slug' => '1-2', 'landed_cost' => '12.5' ) );
$s      = $store->stay( $stay_id, $today, $settings );
check( 'everything together: the product, the landed cost, the size, and the check-in date as a note event', true === $r['ok'] && 21 === $s['product_id'] && 12.5 === $s['landed_cost'] && '1-2' === $s['size_slug'] && Replay::add_days( $today, -2 ) === $s['checkin_date'] && $events + 1 === $store->event_count() );

echo "9. The Front Desk: the Arrival sheet's size, the rows\n";
$store = fh_sized_store( array( 21 => 'Blue Test Wrasse' ) );
$ctx   = array(
	'store'    => $store,
	'settings' => $settings,
	'today'    => $today,
	'tank'     => $store->tank_by_number( 521 ),
	'base'     => array( 'actor_id' => 8, 'source' => 'qr' ),
);
$r     = Front_Desk::perform( 'arrival', $ctx, array( 'species' => 'Blue Test Wrasse', 'product_id' => 21, 'qty' => 3, 'size' => '2-3' ) );
$s     = $store->stay( 1, $today, $settings );
check( 'an arrival with a size: the stay has it, the toast names it', empty( $r['error'] ) && '2-3' === $s['size_slug'] && '2-3"' === $s['size_label'] && '3 × Blue Test Wrasse · 2-3" in Tank 521' === $r['message'], json_encode( $r ) );
$r = Front_Desk::perform( 'arrival', $ctx, array( 'species' => 'Red Test Goby', 'qty' => 2, 'size' => 'jumbo' ) );
check( 'a size that is not in the list is refused and nothing is checked in', 'That size is not in the list. Pick one, or none.' === ( $r['error'] ?? '' ) && 1 === count( $store->stays ) );
$r = Front_Desk::perform( 'arrival', $ctx, array( 'species' => 'Red Test Goby', 'qty' => 2 ) );
$s = $store->stay( 2, $today, $settings );
check( 'an arrival without a size works as before', empty( $r['error'] ) && null === $s['size_slug'] && '2 × Red Test Goby in Tank 521' === $r['message'] );
$sizes = Sizes::standard( $store, $settings )['sizes'];
$occ   = Front_Desk::occupant( $store->stay( 1, $today, $settings ), $sizes );
check( 'a row carries the size label and that it is standard', '2-3"' === $occ['size'] && '2-3' === $occ['sizeSlug'] && true === $occ['sizeStandard'] );
$occ = Front_Desk::occupant( $store->stay( 2, $today, $settings ), $sizes );
check( 'a row without a size carries an empty one', '' === $occ['size'] && '' === $occ['sizeSlug'] && true === $occ['sizeStandard'] );
$store->update_stay_fields( 1, array( 'size_slug' => 'jumbo', 'size_label' => 'Jumbo 8"' ) );
$occ = Front_Desk::occupant( $store->stay( 1, $today, $settings ), $sizes );
check( 'a stale size is still on the row, flagged', 'Jumbo 8"' === $occ['size'] && false === $occ['sizeStandard'] );
check( 'the page\'s strings name the size', isset( Front_Desk::i18n()['size'], Front_Desk::i18n()['sizeHint'], Front_Desk::i18n()['notStandard'] ) );
$store->size_terms['pa_size'][] = array( 'term_id' => 33, 'name' => '4-5″', 'slug' => '4-5%e2%80%b3', 'menu_order' => 3 );
$r                               = Front_Desk::perform( 'arrival', $ctx, array( 'species' => 'Green Test Blenny', 'qty' => 1, 'size' => '4-5%e2%80%b3' ) );
$s                               = $store->stay( 3, $today, $settings );
$saved                           = Admin_Workbook::save_order( $store, array( 'ref' => '2026-8', 'status' => 'draft', 'lines' => array( array( 'species' => 'Green Test Blenny', 'size_slug' => '4-5%e2%80%b3', 'qty_ordered' => 1 ) ) ), 0, $today );
check( 'a term slug with percent-encoded characters is kept whole (arrival and order line alike)', empty( $r['error'] ) && '4-5%e2%80%b3' === $s['size_slug'] && '4-5″' === $s['size_label'] && '4-5%e2%80%b3' === $store->order( $saved['order_id'] )['lines'][0]['size_slug'] && array() === $saved['warnings'] );

echo "10. The voice parser keeps ignoring size words\n";
$variant = static function ( $text, $kind ) {
	return array( 'text' => $text, 'kind' => $kind );
};
$parsed  = Parser::parse(
	'four medium blue test wrasse in tank 521',
	array(
		'tank'      => 521,
		'tanks'     => fh_seed_tanks(),
		'species'   => array( array( 'product_id' => 21, 'name' => 'Blue Test Wrasse', 'variants' => array( $variant( 'Blue Test Wrasse', 'title' ) ) ) ),
		'occupants' => array(),
	)
);
check( 'a size word in a phrase is dropped: the fish is found, the preview carries no size', 'arrival' === $parsed['intent'] && 4 === $parsed['qty'] && 21 === $parsed['species']['product_id'] && ! array_key_exists( 'size', $parsed ) && ! array_key_exists( 'size_slug', $parsed ) );
$r = Front_Desk::perform( 'arrival', array_merge( $ctx, array( 'base' => array( 'actor_id' => 8, 'source' => 'voice' ) ) ), array( 'species' => $parsed['species']['name'], 'product_id' => $parsed['species']['product_id'], 'qty' => $parsed['qty'] ) );
check( 'so a spoken arrival checks in without a size', empty( $r['error'] ) && 'Blue Test Wrasse' === $store->stay( 4, $today, $settings )['species'] && null === $store->stay( 4, $today, $settings )['size_slug'] );

echo "11. Exports\n";
$stays = $store->open_stays( array(), $today, $settings );
$store->update_stay_fields( 1, array( 'size_slug' => '2-3', 'size_label' => '2-3"' ) );
$stays = $store->open_stays( array(), $today, $settings );
$row   = Admin::csv_row( $stays[0] );
check( 'the stays CSV: "size" is the last column and holds the label', 'size' === Admin::csv_header()[ count( Admin::csv_header() ) - 1 ] && '2-3"' === $row[ count( $row ) - 1 ] && '' === Admin::csv_row( $stays[1] )[ count( $row ) - 1 ] );
$rows = Admin_Workbook::stays_rows( $stays );
check( 'the stays sheet: the same, an empty cell without a size', '2-3"' === $rows[1][ count( $rows[0] ) - 1 ] && null === $rows[2][ count( $rows[0] ) - 1 ] && count( $rows[0] ) === count( $rows[1] ) );
check( 'a size label that starts like a formula is guarded in the CSV', "'=1\"" === Admin::csv_row( array_merge( $stays[0], array( 'size_label' => '=1"' ) ) )[ count( $row ) - 1 ] );
$store2 = fh_sized_store();
$saved  = Admin_Workbook::save_order( $store2, array( 'ref' => '2026-9', 'supplier' => 'Test Supplier One', 'status' => 'draft', 'lines' => array( array( 'species' => 'Blue Test Wrasse', 'size_slug' => '1-2', 'qty_ordered' => 3 ), array( 'species' => 'Red Test Goby', 'qty_ordered' => 2 ) ) ), 0, $today );
$sheets = Admin_Workbook::orders_sheets( array( Admin_Workbook::resolve_order( $store2, $store2->order( $saved['order_id'] ) ) ) );
$first  = array_combine( $sheets['Lines'][0], $sheets['Lines'][1] );
$second = array_combine( $sheets['Lines'][0], $sheets['Lines'][2] );
check( 'the orders workbook: the Lines sheet has a "size" column with the label, empty without one', '1-2"' === $first['size'] && null === $second['size'] && 'Blue Test Wrasse' === $first['species'] );

echo "12. Hydration and the schema\n";
$stay = Store::hydrate_stay( array( 'id' => 1, 'species' => 'x', 'checkin_date' => $today, 'size_slug' => '2-3', 'size_label' => '2-3"' ), $today, $settings );
check( 'a stay row carries size_slug and size_label; an empty slug means no size (and no label)', '2-3' === $stay['size_slug'] && '2-3"' === $stay['size_label'] && null === Store::hydrate_stay( array( 'id' => 2, 'species' => 'x', 'checkin_date' => $today, 'size_slug' => '', 'size_label' => 'left over' ), $today, $settings )['size_label'] );
$line = Store::hydrate_line( array( 'id' => 1, 'order_id' => 1, 'species' => 'x', 'size_slug' => '2-3', 'size_label' => '2-3"' ) );
check( 'so does an order line', '2-3' === $line['size_slug'] && '2-3"' === $line['size_label'] && null === Store::hydrate_line( array( 'id' => 2 ) )['size_slug'] );
$GLOBALS['wpdb'] = (object) array( 'prefix' => 'wp_' );
$sql             = implode( "\n", Store::schema() );
check( 'both tables have the two nullable size columns', 2 === preg_match_all( "/\n\t+size_slug VARCHAR\(100\) NULL,\n\t+size_label VARCHAR\(100\) NULL,\n/", $sql ) && in_array( 'size_slug', Store::REQUIRED_COLUMNS['stays'], true ) && in_array( 'size_label', Store::REQUIRED_COLUMNS['order_lines'], true ) );

fh_finish();
