<?php
/**
 * Which bag a fish goes in — on invented products, sizes and bags: where
 * the list of sizes comes from (this section's own lookup while the Clean
 * Room is not there, the Clean Room's list when it is), the size → bag
 * map, a variation's own Bag size overriding it, a bag that was removed,
 * the bag of an order line (its product, else the size on the line), and
 * the "Fill empty ones from the map" tool: a preview that changes nothing
 * and a run that sets only what has no value, through the product object.
 *
 * Run from the plugin root:  php includes/sections/packing/tests/bags-test.php
 */

require_once __DIR__ . '/wp-stubs.php';

use FisHotel\Misc\Sections\Packing\Bags;
use FisHotel\Misc\Sections\Packing\Fill;
use FisHotel\Misc\Sections\Packing\Packing;
use FisHotel\Misc\Sections\Packing\Settings;
use FisHotel\Misc\Sections\Packing\Size_List;

echo "1. What counts as a size\n";
check( 'an attribute name, slug or key that contains "size", whatever the case', Size_List::matches( 'pa_size' ) && Size_List::matches( 'Fish Size' ) && Size_List::matches( 'attribute_pa_fish-sizes' ) && ! Size_List::matches( 'pa_color' ) && ! Size_List::matches( '' ) && ! Size_List::matches( array( 'size' ) ) );
check( 'a label\'s slug reads like a term slug', '2-3-5' === Size_List::slug( ' 2 - 3.5" ' ) && 'x-large' === Size_List::slug( 'X-Large' ) && '' === Size_List::slug( '"' ) && '' === Size_List::slug( null ) );
$sizes = fh_test_sizes()['sizes'];
check( 'a value means a size by its slug, by its label (any case), or by a text that reads like it', 'mid' === Size_List::match_value( 'mid', $sizes ) && 'mid' === Size_List::match_value( 'MID (2-3")', $sizes ) && 'small' === Size_List::match_value( 'small 1 2', $sizes ) && '' === Size_List::match_value( 'huge', $sizes ) && '' === Size_List::match_value( '', $sizes ) && '' === Size_List::match_value( array( 'mid' ), $sizes ) );
check( 'a set of attributes: the value under the size attribute is the slug', 'mid' === Size_List::size_of( array( 'attribute_pa_color' => 'blue', 'attribute_pa_size' => 'mid' ), fh_test_sizes() ) && 'big' === Size_List::size_of( array( 'pa_size' => 'big' ), fh_test_sizes() ) );
check( 'a size typed on the product ("Size") counts when it means a size of the list', 'big' === Size_List::size_of( array( 'size' => 'Big (3-4")' ), fh_test_sizes() ) && '' === Size_List::size_of( array( 'size' => 'Enormous' ), fh_test_sizes() ) );
check( 'no size attribute, an empty value ("any size"), or a key that is not a size: none', '' === Size_List::size_of( array( 'pa_color' => 'mid' ), fh_test_sizes() ) && '' === Size_List::size_of( array( 'pa_size' => '' ), fh_test_sizes() ) && '' === Size_List::size_of( array(), fh_test_sizes() ) );

echo "2. The list of sizes while the Clean Room is not there\n";
$store                  = new Fh_Fake_Store();
$store->size_attributes = array(
	array( 'taxonomy' => 'pa_size', 'name' => 'size', 'label' => 'Size', 'orderby' => 'menu_order' ),
);
$store->size_terms      = array(
	'pa_size' => array(
		array( 'term_id' => 31, 'name' => 'Big (3-4")', 'slug' => 'big', 'menu_order' => 2 ),
		array( 'term_id' => 30, 'name' => 'Small (1-2")', 'slug' => 'small', 'menu_order' => 0 ),
		array( 'term_id' => 32, 'name' => 'Mid (2-3")', 'slug' => 'mid', 'menu_order' => 1 ),
	),
);
$list = Size_List::standard( $store );
check( 'the attribute whose name contains "size" is found by this section itself', Size_List::SOURCE_ATTRIBUTE === $list['source'] && Size_List::KIND_ATTRIBUTE === $list['kind'] && 'pa_size' === $list['attribute'] && 'Size' === $list['label'] );
check( 'its terms come in the order the attribute is set to', array( 'small', 'mid', 'big' ) === array_column( $list['sizes'], 'slug' ) && 'Small (1-2")' === $list['sizes'][0]['label'] );
$store->size_attributes[]           = array( 'taxonomy' => 'pa_tank-size', 'name' => 'tank-size', 'label' => 'Tank size', 'orderby' => 'name' );
$store->size_terms['pa_tank-size'] = array(
	array( 'term_id' => 41, 'name' => 'Nano', 'slug' => 'nano', 'menu_order' => 0 ),
	array( 'term_id' => 40, 'name' => 'Cube', 'slug' => 'cube', 'menu_order' => 5 ),
);
check( 'two attributes match: the first', 'pa_size' === Size_List::standard( $store )['attribute'] );
add_filter(
	'fishotel_packing_size_attribute',
	static function () {
		return 'pa_tank-size';
	}
);
$list = Size_List::standard( $store );
check( 'the filter fishotel_packing_size_attribute names another; its terms follow its own order (by name)', 'pa_tank-size' === $list['attribute'] && array( 'cube', 'nano' ) === array_column( $list['sizes'], 'slug' ) );
fh_reset_hooks();
$none = Size_List::standard( new Fh_Fake_Store() );
check( 'no such attribute: no list — said plainly, not guessed', Size_List::SOURCE_NONE === $none['source'] && array() === $none['sizes'] && '' === $none['attribute'] );

echo "3. The list of sizes when the Clean Room is there\n";
require_once __DIR__ . '/fake-clean-room.php';
$GLOBALS['fh_clean_room']['attributes'] = array(
	array( 'taxonomy' => 'pa_shoe-size', 'name' => 'shoe-size', 'label' => 'Shoe size', 'orderby' => 'menu_order' ),
	array( 'taxonomy' => 'pa_size', 'name' => 'size', 'label' => 'Size', 'orderby' => 'menu_order' ),
);
$GLOBALS['fh_clean_room']['terms']      = array(
	'pa_size'      => array(
		array( 'term_id' => 30, 'name' => 'Small (1-2")', 'slug' => 'small', 'menu_order' => 0 ),
		array( 'term_id' => 32, 'name' => 'Mid (2-3")', 'slug' => 'mid', 'menu_order' => 1 ),
	),
	'pa_shoe-size' => array(
		array( 'term_id' => 50, 'name' => 'Wide', 'slug' => 'wide', 'menu_order' => 0 ),
	),
);
$GLOBALS['fh_clean_room']['settings']   = array( 'size_attribute' => 'pa_size', 'size_list' => array() );
Size_List::reset();
$list = Size_List::standard( $store );
check( 'the Clean Room resolves the list — this section\'s own lookup is not used', Size_List::SOURCE_CLEAN_ROOM === $list['source'] && Size_List::KIND_ATTRIBUTE === $list['kind'] );
check( 'it is the attribute the Clean Room\'s settings picked, with its terms', 'pa_size' === $list['attribute'] && 'Size' === $list['label'] && array( array( 'slug' => 'small', 'label' => 'Small (1-2")' ), array( 'slug' => 'mid', 'label' => 'Mid (2-3")' ) ) === $list['sizes'], fh_test_export( $list ) );
$GLOBALS['fh_clean_room']['attributes'] = array();
$GLOBALS['fh_clean_room']['settings']   = array( 'size_attribute' => '', 'size_list' => array( 'Under 1"', '1-2"' ) );
$list                                   = Size_List::standard( $store );
check( 'no attribute there: the Clean Room\'s typed list, marked as a list', Size_List::SOURCE_CLEAN_ROOM === $list['source'] && Size_List::KIND_LIST === $list['kind'] && '' === $list['attribute'] && array( 'under-1', '1-2' ) === array_column( $list['sizes'], 'slug' ) );
$GLOBALS['fh_clean_room']['throw'] = true;
$list                              = Size_List::standard( $store );
check( 'the Clean Room failing is not this section failing: it looks the attribute up itself', Size_List::SOURCE_ATTRIBUTE === $list['source'] && 'pa_size' === $list['attribute'] );
$GLOBALS['fh_clean_room']['throw'] = false;
check( 'an answer that is not a list is not used', null === Size_List::from_clean_room( 'nope' ) && null === Size_List::from_clean_room( array( 'sizes' => 'x' ) ) );

echo "4. The rule: the own value, else the map, else none\n";
$settings = fh_test_settings();
$standard = fh_test_sizes();
$own      = Bags::resolve( '3', 'tiny', $settings, $standard['sizes'] );
check( 'a Bag size of its own overrides the map', 3 === $own['bag']['id'] && Bags::SOURCE_OWN === $own['bag']['source'] && false === $own['stale'] );
check( 'the bag comes with its name, dimensions, label and the size it was found for', 'Large' === $own['bag']['name'] && '8' === $own['bag']['width'] && '18' === $own['bag']['length'] && 'Large (8 × 18 in)' === $own['bag']['label'] && 'tiny' === $own['bag']['size'] && 'Tiny (under 1")' === $own['bag']['size_label'] && 30 === $own['bag']['sort'] );
$mapped = Bags::resolve( '', 'mid', $settings, $standard['sizes'] );
check( 'without one, the map gives the bag of the size', 2 === $mapped['bag']['id'] && Bags::SOURCE_MAP === $mapped['bag']['source'] );
$missing = Bags::resolve( '', 'show', $settings, $standard['sizes'] );
check( 'a size the map has no bag for: none', null === $missing['bag'] && false === $missing['stale'] );
check( 'no size and no own value: none', null === Bags::resolve( '', '', $settings )['bag'] && null === Bags::resolve( null, '', $settings )['bag'] );
$stale = Bags::resolve( '77', 'mid', $settings, $standard['sizes'] );
check( 'an own value that names a removed bag falls back to the map, and says so', 2 === $stale['bag']['id'] && Bags::SOURCE_MAP === $stale['bag']['source'] && true === $stale['stale'] );
check( 'a bag without dimensions is labelled by its name alone', 'Jumbo' === Bags::label( Bags::find( $settings['bags'], 4 ) ) && '' === Bags::dims_text( array( 'width' => '', 'length' => '' ) ) && '6 in wide' === Bags::dims_text( array( 'width' => '6', 'length' => '' ) ) );

echo "5. The bag of a product\n";
fh_product( new Fh_Fake_Product( 100, 'variable', array( 'pa_size' => array( 'small', 'mid', 'big' ) ) ) );
fh_product( new Fh_Fake_Product( 101, 'variation', array( 'pa_size' => 'small' ), array(), 100 ) );
fh_product( new Fh_Fake_Product( 102, 'variation', array( 'pa_size' => 'mid' ), array( '_fh_bag_size' => '3' ), 100 ) );
fh_product( new Fh_Fake_Product( 103, 'variation', array( 'pa_size' => 'show' ), array(), 100 ) );
fh_product( new Fh_Fake_Product( 104, 'variation', array( 'pa_size' => '' ), array(), 100 ) );
fh_product( new Fh_Fake_Product( 105, 'variation', array( 'size' => 'Big (3-4")' ), array(), 100 ) );
fh_product( new Fh_Fake_Product( 106, 'variation', array( 'pa_size' => 'mid' ), array( '_fh_bag_size' => '77' ), 100 ) );
fh_product( new Fh_Fake_Product( 110, 'simple', array( 'pa_size' => array( 'big' ) ) ) );
fh_product( new Fh_Fake_Product( 111, 'simple', array( 'pa_size' => array( 'small', 'big' ) ) ) );
fh_product( new Fh_Fake_Product( 112, 'simple', array(), array( '_fh_bag_size' => '1' ) ) );

check( 'a variation with no Bag size of its own: the bag of its size', 1 === Bags::for_product( 101, $settings, $standard )['id'] && Bags::SOURCE_MAP === Bags::for_product( 101, $settings, $standard )['source'] );
check( 'a variation with its own: that one, whatever its size says', 3 === Bags::for_product( 102, $settings, $standard )['id'] && Bags::SOURCE_OWN === Bags::for_product( 102, $settings, $standard )['source'] && 'mid' === Bags::for_product( 102, $settings, $standard )['size'] );
check( 'a size with no bag in the map, "any size", or no size: none', null === Bags::for_product( 103, $settings, $standard ) && null === Bags::for_product( 104, $settings, $standard ) );
check( 'a size typed on the product that reads like a size of the list is matched', 3 === Bags::for_product( 105, $settings, $standard )['id'] );
check( 'a removed bag on the variation: the map answers', 2 === Bags::for_product( 106, $settings, $standard )['id'] );
check( 'a simple product with exactly one size: the bag of that size', 3 === Bags::for_product( 110, $settings, $standard )['id'] );
check( 'a simple product with several sizes has no one size: none', null === Bags::for_product( 111, $settings, $standard ) );
check( 'a simple product with a Bag size of its own', 1 === Bags::for_product( 112, $settings, $standard )['id'] );
check( 'a product object works as well as an id; an id that is no product gives none', 1 === Bags::for_product( wc_get_product( 101 ), $settings, $standard )['id'] && null === Bags::for_product( 99999, $settings, $standard ) && null === Bags::for_product( 'x', $settings, $standard ) );
$no_bags = array_merge( $settings, array( 'bags' => array() ) );
check( 'with no bags defined nothing has a bag', null === Bags::for_product( 102, $no_bags, $standard ) );

echo "6. The bag of an order line\n";
$line = new Fh_Fake_Item( 1, 'Invented Goby', 2, 100, array( 'pa_size' => 'small' ), 101 );
check( 'a line of a variation: the variation\'s bag', 1 === Bags::for_item( $line, $settings, $standard )['id'] );
$line = new Fh_Fake_Item( 2, 'Invented Goby', 1, 100, array( 'pa_size' => 'mid' ), 102 );
check( 'the variation\'s own Bag size wins on the line too', 3 === Bags::for_item( $line, $settings, $standard )['id'] );
$line = new Fh_Fake_Item( 3, 'A fish whose product was deleted', 1, 0, array( 'pa_size' => 'big' ) );
check( 'no product any more: the size WooCommerce copied onto the line, through the map', 3 === Bags::for_item( $line, $settings, $standard )['id'] && Bags::SOURCE_MAP === Bags::for_item( $line, $settings, $standard )['source'] );
$line = new Fh_Fake_Item( 4, 'An invoice fish', 1, 0, array( '_fh_invoice_fish' => '1', 'Size' => 'Mid (2-3")' ) );
check( 'a line that is not a shop product with a size by its label', 2 === Bags::for_item( $line, $settings, $standard )['id'] );
$line = new Fh_Fake_Item( 5, 'An unlisted fish', 3, 0, array( '_fh_invoice_fish' => '1' ) );
check( 'a line with no product and no size: none', null === Bags::for_item( $line, $settings, $standard ) );
add_filter(
	'fishotel_packing_item_size',
	static function ( $size, $item ) {
		return 5 === $item->get_id() ? 'tiny' : $size;
	},
	10,
	2
);
check( 'the filter fishotel_packing_item_size can name the line\'s size', 1 === Bags::for_item( $line, $settings, $standard )['id'] && 'tiny' === Bags::for_item( $line, $settings, $standard )['size'] );
fh_reset_hooks();
check( 'nothing is ever written to the line', array() === $line->writes );
check( 'something that is not a line gives none', null === Bags::for_item( 'x', $settings, $standard ) && null === Bags::for_item( null, $settings, $standard ) );

echo "7. The bag of a size (a fish that is not a shop product)\n";
check( 'by the size\'s slug', 2 === Bags::for_size( 'mid', $settings, $standard )['id'] && Bags::SOURCE_MAP === Bags::for_size( 'mid', $settings, $standard )['source'] );
check( 'by its label', 3 === Bags::for_size( 'Big (3-4")', $settings, $standard )['id'] );
check( 'a size without a bag, or no size: none', null === Bags::for_size( 'show', $settings, $standard ) && null === Bags::for_size( '', $settings, $standard ) && null === Bags::for_size( 'whale', $settings, $standard ) );

echo "8. Fish per bag: one, with a seam\n";
check( 'one fish per bag', 1 === Bags::fish_per_bag( wc_get_product( 101 ), null ) );
add_filter(
	'fishotel_packing_fish_per_bag',
	static function ( $per_bag, $product ) {
		return is_object( $product ) && 101 === $product->get_id() ? 3 : $per_bag;
	},
	10,
	2
);
check( 'the filter fishotel_packing_fish_per_bag can raise it for a product', 3 === Bags::fish_per_bag( wc_get_product( 101 ), null ) && 1 === Bags::fish_per_bag( wc_get_product( 102 ), null ) );
fh_reset_hooks();
add_filter(
	'fishotel_packing_fish_per_bag',
	static function () {
		return 'a few';
	}
);
check( 'an answer that is not a number above one counts as one', 1 === Bags::fish_per_bag( null, null ) );
fh_reset_hooks();

echo "9. Fill empty ones from the map — the preview changes nothing\n";
$store = new Fh_Fake_Store();
$nonce = wp_create_nonce( Fill::NONCE_ACTION );
fh_login( 0 );
check( 'nobody logged in: 403', 403 === Fill::preview_request( array( '_wpnonce' => $nonce ), $store, $standard )['status'] && 403 === Fill::run_request( array( '_wpnonce' => $nonce ), $store, $standard )['status'] );
fh_login( 5, array( 'manage_woocommerce' ) );
check( 'no nonce, or the settings forms\' nonce: 403', 403 === Fill::preview_request( array(), $store, $standard )['status'] && 403 === Fill::run_request( array( '_wpnonce' => wp_create_nonce( Settings::NONCE_ACTION ) ), $store, $standard )['status'] );
check( 'nothing was scanned for a refused request', 0 === $store->scans );

$preview = Fill::preview_request( array( '_wpnonce' => $nonce ), $store, $standard );
check( 'the preview counts: 4 variations have no value; the map would set 2 of them', 200 === $preview['status'] && 4 === $preview['empty'] && 2 === $preview['would_set'], fh_test_export( $preview ) );
check( 'it says which bags, which sizes have no bag, and how many carry no size', false !== strpos( $preview['message'], 'Small: 1' ) && false !== strpos( $preview['message'], 'Large: 1' ) && false !== strpos( $preview['message'], 'Show (5"+): 1' ) && false !== strpos( $preview['message'], '1 carry no size' ), $preview['message'] );
check( 'and how many already have one and are not touched', false !== strpos( $preview['message'], '6 variations in all; 2 already have a Bag size' ), $preview['message'] );
$touched = 0;
foreach ( $GLOBALS['fh_products'] as $product ) {
	$touched += $product->meta_saves + $product->full_saves + ( null === $product->pending ? 0 : 1 );
}
check( 'no product was changed or saved by the preview', 0 === $touched && ! isset( wc_get_product( 101 )->meta['_fh_bag_size'] ) );

$plan = Fill::plan( $store->variations_without_bag(), $settings, $standard );
check( 'the plan: variation → bag, counts by bag, sizes without a bag, variations without a size', array( 101 => 1, 105 => 3 ) === $plan['set'] && array( 1 => 1, 3 => 1 ) === $plan['by_bag'] && array( 'show' => 1 ) === $plan['unmapped'] && 1 === $plan['no_size'] && 4 === $plan['empty'], fh_test_export( $plan ) );
check( 'a variation whose value names a removed bag is NOT empty: it is left alone', ! isset( $plan['set'][106] ) );

echo "10. The run sets only what has no value, through the product object\n";
$run = Fill::run_request( array( '_wpnonce' => $nonce, 'after' => '0' ), $store, $standard );
check( 'two variations set, none failed, the run is done', 200 === $run['status'] && 2 === $run['set'] && 0 === $run['failed'] && true === $run['done'], fh_test_export( $run ) );
check( 'each got the bag of its size, as the id', '1' === wc_get_product( 101 )->meta['_fh_bag_size'] && '3' === wc_get_product( 105 )->meta['_fh_bag_size'] );
check( 'written through update_meta_data() + save_meta_data() — the product itself is not re-saved', 1 === wc_get_product( 101 )->meta_saves && 0 === wc_get_product( 101 )->full_saves );
check( 'a variation that had a value keeps it, untouched', '3' === wc_get_product( 102 )->meta['_fh_bag_size'] && 0 === wc_get_product( 102 )->meta_saves && '77' === wc_get_product( 106 )->meta['_fh_bag_size'] && 0 === wc_get_product( 106 )->meta_saves );
check( 'a variation the map cannot answer for stays empty', ! isset( wc_get_product( 103 )->meta['_fh_bag_size'] ) && ! isset( wc_get_product( 104 )->meta['_fh_bag_size'] ) && 0 === wc_get_product( 103 )->meta_saves );
check( 'simple products and the variable parent are not in the tool\'s reach', ! isset( wc_get_product( 110 )->meta['_fh_bag_size'] ) && ! isset( wc_get_product( 100 )->meta['_fh_bag_size'] ) );
$again = Fill::preview_request( array( '_wpnonce' => $nonce ), $store, $standard );
check( 'run again: nothing left to set', 0 === $again['would_set'] && 2 === $again['empty'] && 0 === Fill::run_request( array( '_wpnonce' => $nonce ), $store, $standard )['set'] );

echo "11. The run goes a batch at a time and cannot loop\n";
fh_reset_shop();
for ( $i = 1; $i <= 7; $i++ ) {
	fh_product( new Fh_Fake_Product( 200 + $i, 'variation', array( 'pa_size' => $i % 2 ? 'small' : 'show' ), array(), 200 ) );
}
$first = Fill::run( $store, $settings, $standard, 0, 3 );
check( 'a batch of three: looks at three, sets the two the map answers for, is not done, and says where it stopped', 3 === $first['looked'] && 2 === $first['set'] && false === $first['done'] && 203 === $first['last_id'], fh_test_export( $first ) );
$second = Fill::run( $store, $settings, $standard, $first['last_id'], 3 );
$third  = Fill::run( $store, $settings, $standard, $second['last_id'], 3 );
check( 'the next batches carry on after the cursor, and the last one says done', 206 === $second['last_id'] && false === $second['done'] && 1 === $second['set'] && true === $third['done'] && 1 === $third['set'] && 207 === $third['last_id'], fh_test_export( array( $second, $third ) ) );
check( 'the variations it could not answer for are passed once, not asked about for ever', 3 === count( $store->variations_without_bag() ) && 0 === Fill::run( $store, $settings, $standard, 207, 3 )['looked'] );

fh_reset_shop();
fh_product( new Fh_Fake_Product( 301, 'variation', array( 'pa_size' => 'small' ), array(), 300 ) );
fh_product( new Fh_Fake_Product( 302, 'variation', array( 'pa_size' => 'small' ), array(), 300 ) );
$rows = $store->variations_without_bag();
wc_get_product( 301 )->meta['_fh_bag_size'] = '4'; // Somebody set it by hand after the list was read.
check( 'a value set since the list was read is not overwritten: the product is asked once more before the write', 'skipped' === Fill::write( 301, 1 ) && '4' === wc_get_product( 301 )->meta['_fh_bag_size'] && 0 === wc_get_product( 301 )->meta_saves );
check( 'a variation that is gone by now counts as failed, not as set', 'failed' === Fill::write( 999, 1 ) && 'set' === Fill::write( 302, 1 ) );
$outcome = Fill::run(
	$store,
	$settings,
	$standard,
	0,
	50,
	static function ( $variation_id, $bag_id ) {
		throw new \RuntimeException( 'nothing should be written' );
	}
);
check( 'with nothing left to set, nothing is written', 0 === $outcome['set'] && 0 === $outcome['failed'] && 0 === $outcome['looked'] && true === $outcome['done'] );
fh_product( new Fh_Fake_Product( 303, 'variation', array( 'pa_size' => 'mid' ), array(), 300 ) );
$outcome = Fill::run(
	$store,
	$settings,
	$standard,
	0,
	50,
	static function ( $variation_id, $bag_id ) {
		return 303 === $variation_id && 2 === $bag_id ? 'failed' : 'set';
	}
);
check( 'a write that fails is counted as failed, and the cursor still moves past it (the writer gets the variation and the bag of its size)', 1 === $outcome['failed'] && 0 === $outcome['set'] && 303 === $outcome['last_id'] && true === $outcome['done'] );

echo "12. When the tool cannot run it says why\n";
Settings::store( array_merge( $settings, array( 'size_map' => array() ) ) );
$blocked = Fill::preview_request( array( '_wpnonce' => $nonce ), $store, $standard );
check( 'an empty map: 409, nothing scanned', 409 === $blocked['status'] && false !== strpos( $blocked['message'], 'map is empty' ) && 409 === Fill::run_request( array( '_wpnonce' => $nonce ), $store, $standard )['status'] );
Settings::store( array_merge( $settings, array( 'bags' => array() ) ) );
check( 'no bag sizes: 409', 409 === Fill::preview_request( array( '_wpnonce' => $nonce ), $store, $standard )['status'] && false !== strpos( Fill::blocked( Settings::get(), $standard ), 'bag sizes first' ) );
Settings::store( $settings );
check( 'no list of sizes: 409', 409 === Fill::preview_request( array( '_wpnonce' => $nonce ), $store, Size_List::none() )['status'] );
check( 'with bags, a map and sizes it can run', '' === Fill::blocked( Settings::get(), $standard ) );

fh_reset_hooks();
$fill = new Fill();
$fill->init();
check( 'the tool is two admin AJAX actions', array( 'wp_ajax_fishotel_packing_fill_preview', 'wp_ajax_fishotel_packing_fill_run' ) === array_keys( $GLOBALS['fh_hooks'] ) );

fh_finish();
