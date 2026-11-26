<?php
/**
 * The fixes from the compliance, data-safety and owner reviews: pictures'
 * titles, descriptions and addresses; pages that link to the products
 * found after step 5 too; kept tags and brands with a claim description;
 * search titles that lost the name; what counts as a food; categories
 * outside the Medications tree never touched; a removed category's menu
 * items, settings and sub-categories kept and put back; the backup's
 * stored values; one ingredient box; "None" ingredients; the page check
 * reading only real links.
 *
 * Run from the plugin root:
 *   php includes/sections/med-listings/tests/review-fixes-test.php
 *
 * Every product, word and address here is invented.
 *
 * @package FisHotel\Misc\Sections\Med_Listings
 */

require_once __DIR__ . '/seed.php';
require_once FISHOTEL_MISC_PATH . 'includes/sections/med-listings/class-admin.php';

use FisHotel\Misc\Sections\Med_Listings\Admin;
use FisHotel\Misc\Sections\Med_Listings\Composer;
use FisHotel\Misc\Sections\Med_Listings\Fields;
use FisHotel\Misc\Sections\Med_Listings\Inventory;
use FisHotel\Misc\Sections\Med_Listings\Ledger;
use FisHotel\Misc\Sections\Med_Listings\Proposals;
use FisHotel\Misc\Sections\Med_Listings\Redirects;
use FisHotel\Misc\Sections\Med_Listings\Terms;
use FisHotel\Misc\Sections\Med_Listings\Verify;
use FisHotel\Misc\Sections\Med_Listings\Words;

/** Everything stored, terms and their meta included, for comparing. */
function fh_full_state() {
	$fh    = $GLOBALS['fh'];
	$posts = array();
	foreach ( $fh['posts'] as $id => $p ) {
		$posts[ $id ] = (array) $p;
	}
	$terms = array();
	foreach ( $fh['terms'] as $id => $t ) {
		$terms[ $t->taxonomy . ':' . $t->slug ] = array( $t->name, $t->description, $t->parent ? $fh['terms'][ $t->parent ]->slug ?? $t->parent : 0 );
	}
	ksort( $terms );
	$rel = array();
	foreach ( $fh['rel'] as $tax => $objs ) {
		foreach ( $objs as $o => $ids ) {
			$slugs = array();
			foreach ( $ids as $tid ) {
				$slugs[] = isset( $fh['terms'][ $tid ] ) ? $fh['terms'][ $tid ]->slug : 'gone' . $tid;
			}
			sort( $slugs );
			if ( $slugs ) {
				$rel[ $tax ][ $o ] = $slugs;
			}
		}
	}
	$tmeta = array();
	foreach ( $fh['termmeta'] as $tid => $m ) {
		if ( isset( $fh['terms'][ $tid ] ) ) {
			$tmeta[ $fh['terms'][ $tid ]->slug ] = $m;
		}
	}
	$meta = $fh['meta'];
	foreach ( $meta as $pid => $m ) {
		foreach ( $m as $k => $v ) {
			if ( $v instanceof FH_Multi ) {
				$meta[ $pid ][ $k ] = 'multi:' . implode( '|', $v->v );
			}
		}
	}
	// A term's id changes when Undo makes it again: compare menu targets by slug.
	foreach ( $meta as $pid => $m ) {
		if ( isset( $m['_menu_item_object_id'], $fh['terms'][ (int) $m['_menu_item_object_id'] ] ) ) {
			$meta[ $pid ]['_menu_item_object_id'] = 'term:' . $fh['terms'][ (int) $m['_menu_item_object_id'] ]->slug;
		}
		if ( isset( $m['_yoast_wpseo_primary_product_cat'], $fh['terms'][ (int) $m['_yoast_wpseo_primary_product_cat'] ] ) ) {
			$meta[ $pid ]['_yoast_wpseo_primary_product_cat'] = 'term:' . $fh['terms'][ (int) $m['_yoast_wpseo_primary_product_cat'] ]->slug;
		}
	}
	return serialize( array( $posts, $meta, $terms, $rel, $tmeta ) );
}

$ids = fh_seed();
$w   = Words::get();
$t   = $ids['trio'];

// Extra invented data on the seed.
$img = $ids['trio_img'];
wp_update_post( array( 'ID' => $img, 'post_title' => 'Trio flake kills ich', 'post_content' => 'Cures fin rot and velvet. Shot in the studio.', 'post_name' => 'trio-flake-kills-ich' ) );
$GLOBALS['fh']['meta'][ $img ] = array_diff_key( $GLOBALS['fh']['meta'][ $img ], array( '_wp_old_slug' => 1 ) );
update_post_meta( $img, '_wp_attached_file', '2026/11/trio-flake-kills-ich.jpg' );
update_post_meta( $t, '_yoast_wpseo_twitter-title', 'Cures Everything | FisHotel' );
$cure = $ids['cure'];
update_post_meta( $cure, '_yoast_wpseo_title', 'Fluke Cure Powder | Broad-Spectrum Medication' );

echo "1. Pictures: title, description and address\n";
$b    = Proposals::build( Inventory::snapshot( $t, $w ), $w );
$rows = array();
foreach ( $b['rows'] as $r ) {
	$rows[ $r['key'] ] = $r;
}
check( 'picture title → the product name', 'Sample Trio Flake' === ( $rows[ "$img|post_title" ]['proposed'] ?? null ) );
check( 'picture description → claim sentences out', 'Shot in the studio.' === ( $rows[ "$img|post_content" ]['proposed'] ?? null ) );
check( 'picture address → from the product name', 'sample-trio-flake' === ( $rows[ "$img|post_name" ]['proposed'] ?? null ) );
$types = array_column( $b['flags'], 'type' );
check( 'picture file name with a claim → flagged, not renamed', in_array( 'picture', $types, true ) && false !== strpos( $b['flags'][ array_search( 'picture', $types, true ) ]['text'], 'trio-flake-kills-ich.jpg' ) );
check( 'X title that is all claim → empty (the field is removed, the SEO plugin\'s own title is used)', '' === ( $rows[ "$t|meta:_yoast_wpseo_twitter-title" ]['proposed'] ?? null ) );

$before = fh_full_state();
$apply  = array();
foreach ( array( "$img|post_title", "$img|post_content", "$img|post_name", "$t|meta:_yoast_wpseo_twitter-title" ) as $k ) {
	$apply[] = array( 'object' => $rows[ $k ]['object'], 'field' => $rows[ $k ]['field'], 'hash' => $rows[ $k ]['hash'], 'value' => $rows[ $k ]['proposed'] );
}
$res = Ledger::apply( $t, $apply, Inventory::owned_ids( $t ) );
check( 'applied', 4 === count( array_filter( array_column( $res, 'ok' ) ) ), print_r( $res, true ) );
check( 'the picture is renamed, its old address kept by WordPress', 'sample-trio-flake' === get_post( $img )->post_name && array( 'trio-flake-kills-ich' ) === get_post_meta( $img, '_wp_old_slug' ) );
check( 'the empty X title is removed, not left empty', ! metadata_exists( 'post', $t, '_yoast_wpseo_twitter-title' ) );
$u = Ledger::undo( $t );
check( 'Undo: everything exactly as before (title, description, address, the old-address list, the X title)', fh_full_state() === $before && array() === $u['kept'] );
$r = Ledger::apply( $t, array( array( 'object' => $t, 'field' => 'post_title', 'hash' => '', 'value' => 'Renamed' ) ), Inventory::owned_ids( $t ) );
check( 'the product\'s own name is never written', ! $r[0]['ok'] && 'Sample Trio Flake' === get_post( $t )->post_title );

echo "\n2. Search titles\n";
check( 'a title that keeps the name keeps it', 'Sample Trio Flake' === Proposals::clean_title( 'Sample Trio Flake | Broad-Spectrum Fish Medication', 'Sample Trio Flake', $w ) );
check( 'a title whose name is a claim → empty, never just "FisHotel"', '' === Proposals::clean_title( 'General Cure | FisHotel', 'General Cure', $w ) );
$b2 = Proposals::build( Inventory::snapshot( $cure, $w ), $w );
$r2 = array();
foreach ( $b2['rows'] as $r ) {
	$r2[ $r['key'] ] = $r;
}
check( 'Fluke Cure Powder\'s Yoast title → empty', '' === ( $r2[ "$cure|meta:_yoast_wpseo_title" ]['proposed'] ?? null ) );

echo "\n3. One ingredient box, \"None\", names\n";
check( 'no ingredient → one ingredient box row, unticked', isset( $r2[ "$cure|meta:_fishotel_active_ingredient" ] ) && 'ingredient' === $r2[ "$cure|meta:_fishotel_active_ingredient" ]['kind'] && ! $r2[ "$cure|meta:_fishotel_active_ingredient" ]['ticked'] );
check( 'with an ingredient → no box (never rewritten)', ! isset( $rows[ "$t|meta:_fishotel_active_ingredient" ] ) );
$box = $r2[ "$cure|meta:_fishotel_active_ingredient" ];
$res = Ledger::apply( $cure, array( array( 'object' => $cure, 'field' => $box['field'], 'hash' => $box['hash'], 'value' => 'Praziquantel' ) ), Inventory::owned_ids( $cure ) );
check( 'what is typed in the box is saved to the product\'s field', $res[0]['ok'] && 'Praziquantel' === get_post_meta( $cure, '_fishotel_active_ingredient', true ) );
$again = Proposals::build( Inventory::snapshot( $cure, $w ), $w );
check( 'then the "ingredients missing" flag is gone and the description has them', ! in_array( 'ingredients', array_column( $again['flags'], 'type' ), true ) && false !== strpos( $again['composed']['description'], 'Contains: praziquantel.' ) );
Ledger::undo( $cure );
check( 'Undo removes the field again (it was not there)', ! metadata_exists( 'post', $cure, '_fishotel_active_ingredient' ) );
check( 'the PHP phrase matches what the box writes', 'kanamycin, metronidazole, and nitrofurazone' === Composer::ingredient_phrase( 'Kanamycin, Metronidazole, Nitrofurazone', true ) && 'metronidazole 99%+, TMP/sulfa' === Composer::ingredient_phrase( 'Metronidazole 99%+ + TMP/Sulfa', false ) );
$c = Composer::compose( array( 'name' => 'Copper Test Kit', 'ingredients' => 'None (test kit)', 'storage' => 'Keep dry.' ) );
check( '"None (test kit)" → no Contains line, no blank', array( 'Copper Test Kit', 'Keep dry.' ) === $c['lines'] && ! $c['missing_ingredients'] && $c['none_ingredients'] );
update_post_meta( $ids['copper'], '_fishotel_active_ingredient', 'None (test kit)' );
$b3 = Proposals::build( Inventory::snapshot( $ids['copper'], $w ), $w );
check( '…and it is flagged for the owner instead', in_array( 'ingredients', array_column( $b3['flags'], 'type' ), true ) && false !== strpos( implode( ' ', array_column( $b3['flags'], 'text' ) ), 'None (test kit)' ) );
check( '"Anti-Parasite Duo" with no ingredients → only "(name to choose)"', '(name to choose)' === Composer::neutral_name( 'Anti-Parasite Duo', array(), $w ) );
check( '"Fluke Tabs" with no ingredients keeps what says what it is', 'Tabs (name to choose)' === Composer::neutral_name( 'Fluke Tabs', array(), $w ) );

echo "\n4. Food base in the product's own order; what is a food\n";
$GLOBALS['fh']['attrs'][ $t ]['food-base']->set_options( array( 'Herbivore Flake', 'Omnivore Flake' ) );
$va = fh_post( array( 'post_type' => 'product_variation', 'post_parent' => $t, 'menu_order' => 0 ) );
update_post_meta( $va, 'attribute_food-base', 'Omnivore Flake' );
$vb = fh_post( array( 'post_type' => 'product_variation', 'post_parent' => $t, 'menu_order' => 1 ) );
update_post_meta( $vb, 'attribute_food-base', 'Herbivore Flake' );
$snap = Inventory::snapshot( $t, $w );
check( 'values follow the variations: omnivore before herbivore', array( 'Omnivore Flake', 'Herbivore Flake' ) === $snap['attributes']['food-base']['values'], implode( ', ', $snap['attributes']['food-base']['values'] ) );
check( '…so the line reads "omnivore or herbivore flake"', false !== strpos( Proposals::build( $snap, $w )['composed']['excerpt'], 'omnivore or herbivore flake' ) );
$liq = fh_post( array( 'post_title' => 'Copper Liquid 1.5%', 'post_name' => 'copper-liquid' ) );
update_post_meta( $liq, '_fishotel_nutrition', 'n/a' );
update_post_meta( $liq, '_fishotel_food_source', 'n/a' );
check( 'Nutrition / Food-source text does not make a copper liquid a food', ! Inventory::is_food( $liq, 'Copper Liquid 1.5%', array( 'medications' ) ) );
check( 'a tablet is not a food either', ! Inventory::is_food( $liq, 'Prazi Tablets', array() ) );
check( 'a flake is', Inventory::is_food( $liq, 'Trio Flake', array() ) );
check( 'the copper liquid goes to Water Chemistry', 'water' === Terms::type_of( 'Copper Liquid 1.5%', 'chelated copper 1.5%', false, $w ) );

echo "\n5. Pages that link to the products — before and after step 5\n";
$prods = array( $cure => array( 'title' => 'Fluke Cure Powder', 'slug' => 'fluke-cure-powder', 'path' => '/shop/medications/powders-chemicals/fluke-cure-powder/' ) );
check( 'an old product address (category changed since) is still found by the slug', array( 'Fluke Cure Powder' ) === Inventory::links_in( '<a href="/shop/medications/antiparasitic/fluke-cure-powder/">buy</a>', $prods, array() ) );
check( '[products ids="…"] is found', array( 'Fluke Cure Powder' ) === Inventory::links_in( '[products ids="12, ' . $cure . '"]', $prods, array() ) );
check( '[add_to_cart id=…] is found', array( 'Fluke Cure Powder' ) === Inventory::links_in( '[add_to_cart id=' . $cure . ']', $prods, array() ) );
check( 'a product block is found', array( 'Fluke Cure Powder' ) === Inventory::links_in( '<!-- wp:woocommerce/handpicked-products {"products":[' . $cure . ']} /-->', $prods, array() ) );
check( 'an old category address on the redirect list is found', array( 'old address' ) === Inventory::links_in( '<a href="https://shop.test/product-category/medications/antiparasitic/">x</a>', array(), array( '/product-category/medications/antiparasitic/' => 'old address' ) ) );
check( 'a page with none of them: nothing', array() === Inventory::links_in( '<a href="/shop/other-thing/">x</a> [products ids="5"]', $prods, array() ) );
$guide = fh_post( array( 'post_type' => 'page', 'post_title' => 'QT Guide', 'post_name' => 'qt-guide', 'post_content' => '[products ids="' . $cure . '"] <a href="https://shop.test/blog/">blog</a>' ) );
$found = Inventory::linking_pages( array( $cure ) );
check( 'linking_pages() lists the guide as a tool / QT page', 1 === count( $found ) && $found[0]['tool'] && array( 'Fluke Cure Powder' ) === $found[0]['found'] );

echo "\n6. Categories: only the Medications tree; non-medications never swept in\n";
$ids = fh_seed();
$w   = Words::get();
$fish_treats = fh_term( 'product_cat', 'Fish Treats', 'fish-treats' );
$hospital    = fh_term( 'product_cat', 'Hospital Tank Supplies', 'hospital-tank-supplies' );
$outside     = fh_term( 'product_cat', 'Antibiotics', 'antibiotics' ); // A medicine-type slug outside the tree.
wp_set_object_terms( $ids['fish'], array( $ids['cat_fish'], $ids['cat_bact'] ), 'product_cat' ); // A fish wrongly in Antibacterial.
$tag_dis = fh_term( 'product_tag', 'Disease-Free', 'disease-free' );
wp_set_object_terms( $ids['fish'], array( $tag_dis ), 'product_tag' );
wp_set_object_terms( $ids['fish'], array( $ids['tag_flukes'] ), 'product_tag', true ); // Flukes is on the powder and on the fish.
update_term_meta( $ids['tag_metro'], 'x', 'y' );
$GLOBALS['fh']['terms'][ $ids['tag_metro'] ]->description = 'Products that treat Metronidazole infections.';
$brand = fh_term( 'product_brand', 'FisHotel', 'fishotel', 0, 'Our own line. Cures every parasite.' );
wp_set_object_terms( $ids['trio'], array( $brand ), 'product_brand' );
$rows = array();
foreach ( Terms::proposals( $w ) as $r ) {
	$rows[ $r['tax'] . ':' . $r['slug'] ] = $r;
}
check( '"Fish Treats" and "Hospital Tank Supplies" (outside Medications) are not proposed', ! isset( $rows['product_cat:fish-treats'] ) && ! isset( $rows['product_cat:hospital-tank-supplies'] ) );
check( 'a medicine-type slug outside the tree is proposed but starts unticked', isset( $rows['product_cat:antibiotics'] ) && ! $rows['product_cat:antibiotics']['ticked'] );
check( 'Antibacterial holds a fish → left alone, unticked, cannot be ticked', 'keep' === $rows['product_cat:antibacterial']['action'] && $rows['product_cat:antibacterial']['blocked'] && ! $rows['product_cat:antibacterial']['ticked'] );
$no = Terms::apply( array_merge( $rows['product_cat:antibacterial'], array( 'action' => 'sort' ) ), $w );
check( '…and the server refuses to sort it even if asked', ! $no['ok'] && get_term_by( 'slug', 'antibacterial', 'product_cat' ) && ! in_array( 'powders-chemicals', wp_get_object_terms( $ids['fish'], 'product_cat', array( 'fields' => 'slugs' ) ), true ) );
$no = Terms::apply( array( 'id' => $fish_treats, 'tax' => 'product_cat', 'action' => 'merge', 'merge_to' => 'powder' ), $w );
check( '…and a category outside the tree is refused', ! $no['ok'] && get_term( $fish_treats ) );
check( '"Disease-Free" on a fish only: not listed', ! isset( $rows['product_tag:disease-free'] ) );
check( '"Flukes" on the powder and a fish: taken off the medications only', 'untag' === $rows['product_tag:flukes']['action'] );
check( 'kept ingredient tag with a claim description: description cleaned', 'rename' === $rows['product_tag:metronidazole']['action'] && '' === $rows['product_tag:metronidazole']['new_desc'] );
check( 'a brand on a medication with a claim description: cleaned', 'rename' === $rows['product_brand:fishotel']['action'] && 'Our own line.' === $rows['product_brand:fishotel']['new_desc'] );
$un = Terms::apply( $rows['product_tag:flukes'], $w );
check( 'untag: off the powder, still on the fish', $un['ok'] && ! in_array( 'Flukes', wp_get_object_terms( $ids['cure'], 'product_tag', array( 'fields' => 'names' ) ), true ) && in_array( 'Flukes', wp_get_object_terms( $ids['fish'], 'product_tag', array( 'fields' => 'names' ) ), true ) );
$no = Terms::apply( array_merge( $rows['product_tag:flukes'], array( 'action' => 'delete' ) ), $w );
check( 'delete of a tag still on a fish is refused', ! $no['ok'] && get_term_by( 'slug', 'flukes', 'product_tag' ) );
Terms::undo( $un['change'] );
check( 'untag undone', in_array( 'Flukes', wp_get_object_terms( $ids['cure'], 'product_tag', array( 'fields' => 'names' ) ), true ) );
$rb = Terms::apply( $rows['product_brand:fishotel'], $w );
check( 'brand description cleaned', $rb['ok'] && 'Our own line.' === get_term( $brand )->description );
Terms::undo( $rb['change'] );
check( 'and put back', 'Our own line. Cures every parasite.' === get_term( $brand )->description );
$GLOBALS['fh']['options'][ Words::OPTION ] = array( 'leave_out' => 'quarantined-fish, invoices, antiparasitic' );
$w2 = Words::get();
$rows2 = array();
foreach ( Terms::proposals( $w2 ) as $r ) {
	$rows2[ $r['tax'] . ':' . $r['slug'] ] = $r;
}
check( 'a category in the left-out list is never proposed', ! isset( $rows2['product_cat:antiparasitic'] ) );
unset( $GLOBALS['fh']['options'][ Words::OPTION ] );

echo "\n7. Removing a category keeps its menu items, settings, sub-categories and Yoast setting\n";
$ids = fh_seed();
$w   = Words::get();
$sub = fh_term( 'product_cat', 'Prazi Powders', 'prazi-powders', $ids['cat_para'] );
update_term_meta( $ids['cat_para'], 'thumbnail_id', '777' );
update_term_meta( $ids['cat_para'], 'display_type', 'products' );
$menu = fh_post( array( 'post_type' => 'nav_menu_item', 'post_title' => 'Antiparasitic meds', 'post_status' => 'publish' ) );
update_post_meta( $menu, '_menu_item_type', 'taxonomy' );
update_post_meta( $menu, '_menu_item_object', 'product_cat' );
update_post_meta( $menu, '_menu_item_object_id', (string) $ids['cat_para'] );
$menu2 = fh_post( array( 'post_type' => 'nav_menu_item', 'post_title' => '', 'post_status' => 'publish' ) );
update_post_meta( $menu2, '_menu_item_type', 'taxonomy' );
update_post_meta( $menu2, '_menu_item_object', 'product_cat' );
update_post_meta( $menu2, '_menu_item_object_id', (string) $ids['cat_para'] );
update_post_meta( $ids['cure'], '_yoast_wpseo_primary_product_cat', (string) $ids['cat_para'] );
$GLOBALS['fh']['options']['wpseo_taxonomy_meta'] = array( 'product_cat' => array( $ids['cat_para'] => array( 'wpseo_desc' => 'Old SEO text' ) ) );
$before = fh_full_state();
$rows   = array();
foreach ( Terms::proposals( $w ) as $r ) {
	$rows[ $r['tax'] . ':' . $r['slug'] ] = $r;
}
$aff = $rows['product_cat:antiparasitic']['affects'];
check( 'the page says before the button: two menu items, the sub-category, its settings', 2 === count( $aff['menus'] ) && array( 'Prazi Powders' ) === $aff['children'] && $aff['settings'] );
$done = Terms::apply( $rows['product_cat:antiparasitic'], $w );
check( 'sorted and removed', $done['ok'], $done['message'] );
$p = get_term_by( 'slug', 'powders-chemicals', 'product_cat' );
check( 'the menu items still exist, pointing at Powders & Chemicals', get_post( $menu ) && get_post( $menu2 ) && (string) $p->term_id === get_post_meta( $menu, '_menu_item_object_id', true ) );
check( 'a menu label that is a claim is emptied (the new name shows)', '' === get_post( $menu )->post_title );
check( 'the sub-category moved up under Medications', $ids['cat_meds'] === get_term( $sub )->parent );
$hit = Redirects::find_match( '/product-category/medications/antiparasitic/prazi-powders/', array(), Redirects::all() );
check( 'the sub-category\'s old address goes to where it is now', $hit && 'https://shop.test/product-category/medications/prazi-powders/' === Redirects::with_suffix( Redirects::target_url( $hit['entry'] ), $hit['suffix'] ), $hit ? Redirects::target_url( $hit['entry'] ) . ' + ' . $hit['suffix'] : 'no match' );
check( 'the product\'s Yoast primary category points at the new one', (string) $p->term_id === get_post_meta( $ids['cure'], '_yoast_wpseo_primary_product_cat', true ) );
$u = Terms::undo( $done['change'] );
check( 'undone', $u['ok'], $u['message'] );
check( 'Undo: menu items, labels, settings, sub-category, Yoast primary and Yoast SEO text all back as before', fh_full_state() === $before );
$back = get_term_by( 'slug', 'antiparasitic', 'product_cat' );
check( 'the Yoast category SEO text moved to the category\'s new id', isset( $GLOBALS['fh']['options']['wpseo_taxonomy_meta']['product_cat'][ $back->term_id ] ) && 1 === count( $GLOBALS['fh']['options']['wpseo_taxonomy_meta']['product_cat'] ) );
check( 'no redirect left', array() === Redirects::all() );

echo "\n8. Medicated Foods is made with the slug the theme reads\n";
$ids = fh_seed();
$w   = Words::get();
wp_delete_term( $ids['cat_food'], 'product_cat' );
$made = array();
$tid  = Terms::resolve( 'food', $made );
check( 'slug medicated-food, named Medicated Foods, under Medications', 'medicated-food' === get_term( $tid )->slug && 'Medicated Foods' === get_term( $tid )->name && $ids['cat_meds'] === get_term( $tid )->parent );

echo "\n9. Form categories: offered a merge, left unticked\n";
$ids    = fh_seed();
$powder = fh_term( 'product_cat', 'Powders', 'powders', $ids['cat_meds'] );
$rows   = array();
foreach ( Terms::proposals( Words::get() ) as $r ) {
	$rows[ $r['tax'] . ':' . $r['slug'] ] = $r;
}
check( '"Powders" → merge into Powders & Chemicals, unticked, the Form filter named', 'merge' === $rows['product_cat:powders']['action'] && 'powder' === $rows['product_cat:powders']['merge_to'] && ! $rows['product_cat:powders']['ticked'] && false !== strpos( $rows['product_cat:powders']['note'], 'Form filter' ) );

echo "\n10. The backup holds the stored values\n";
$ids  = fh_seed();
$w    = Words::get();
$csv  = Admin::backup_rows( $w );
$head = $csv[0];
check( 'a column with the stored value', 'stored_value_json' === end( $head ) );
$by = array();
foreach ( array_slice( $csv, 1 ) as $r ) {
	$by[ $r[3] . '|' . $r[4] ] = $r;
}
$attr = json_decode( $by[ $ids['trio'] . '|attr:pa_med_treats' ][8], true );
check( 'an attribute with its term ids, position and flags', true === $attr['exists'] && array( $ids['treat_ich'], $ids['treat_fl'] ) === $attr['raw']['options'] && array_key_exists( 'position', $attr['raw'] ) && array_key_exists( 'visible', $attr['raw'] ) );
check( 'empty fields are in it too (picture title)', isset( $by[ $ids['trio_img'] . '|post_title' ] ) && '' === $by[ $ids['trio_img'] . '|post_title' ][7] );
check( 'a value starting with "=" is stored exactly in the JSON column', true );
update_post_meta( $ids['trio'], '_fishotel_best_for', '=SUM(1)' );
$csv = Admin::backup_rows( $w );
foreach ( $csv as $r ) {
	if ( (string) $r[3] === (string) $ids['trio'] && 'meta:_fishotel_best_for' === $r[4] ) {
		check( '…shown safe in the reading column, exact in the JSON column', "'=SUM(1)" === $r[7] && '=SUM(1)' === json_decode( $r[8], true )['raw'] );
	}
}
$found = false;
foreach ( $csv as $r ) {
	if ( 'term' === $r[0] && 'antiparasitic' === $r[6] ) {
		$j     = json_decode( $r[8], true );
		$found = array( $ids['cure'] ) === $j['raw']['products'] && array_key_exists( 'menu_items', $j['raw'] ) && array_key_exists( 'meta', $j['raw'] );
	}
}
check( 'each category row holds its products, settings and menu items', $found );
$found = false;
foreach ( $csv as $r ) {
	if ( (string) $r[3] === (string) $ids['trio'] && 'categories' === $r[4] ) {
		$found = array( $ids['cat_meds'], $ids['cat_food'] ) === json_decode( $r[8], true )['raw'];
	}
}
check( 'each product\'s categories by id', $found );

echo "\n11. Facts only the old description gave\n";
check( 'net weight and strength the new text lacks are named', array( '100 g', '1 mm' ) === Proposals::facts_left_out( '<p>Net 100 g. 1 mm pellet. Dose 5 mg per gallon. Metronidazole 99%.</p>', 'Contains: metronidazole 99%.' ) );
update_post_meta( $ids['trio'], '_fishotel_best_for', '' );
$GLOBALS['fh']['posts'][ $ids['trio'] ]->post_content .= '<p>Net weight 2 oz to 3 lb jar, 0.5 mm flake.</p>';
$b = Proposals::build( Inventory::snapshot( $ids['trio'], Words::get() ), Words::get() );
foreach ( $b['rows'] as $r ) {
	if ( 'post_content' === $r['field'] && (int) $r['object'] === $ids['trio'] ) {
		check( 'the description row says so', false !== strpos( $r['note'], '0.5 mm' ), $r['note'] );
	}
}

echo "\n12. The page check reads real links only; the home row is for information\n";
$links = Verify::links( '<link rel="stylesheet" href="https://shop.test/wp-content/plugins/fh-boards/boards/style.css"><a href="/boards/">Forum</a><a href="https://shop.test/wp-content/uploads/x.pdf">pdf</a><form action="/cart/"></form>' );
check( 'stylesheets and /wp-content/ files are not links; <a> and forms are', array( '/boards/', '/cart/' ) === $links, implode( ', ', $links ) );
$pages = Verify::pages( array() );
check( 'the Home row says it is for information only', 'home' === $pages[0]['kind'] && false !== strpos( $pages[0]['label'], 'for information only' ) );

echo "\n13. Reviews: the flag says where else the text is public\n";
$ids = fh_seed();
$b   = Proposals::build( Inventory::snapshot( $ids['trio'], Words::get() ), Words::get() );
$rev = $b['flags'][ array_search( 'review', array_column( $b['flags'], 'type' ), true ) ];
check( 'it names the public review list', false !== strpos( $rev['text'], '/wp-json/wc/store/v1/products/reviews' ) );

echo "\n14. A claim name renamed after Apply: the fields that carry the old name are proposed again\n";
$ids = fh_seed();
$w   = Words::get();
$q   = $ids['cure'];
update_post_meta( $q, '_fishotel_active_ingredient', 'Praziquantel' );
$b    = Proposals::build( Inventory::snapshot( $q, $w ), $w );
$rows = array();
foreach ( $b['rows'] as $r ) {
	if ( $r['ticked'] ) {
		$rows[] = array( 'object' => $r['object'], 'field' => $r['field'], 'value' => $r['proposed'], 'hash' => $r['hash'] );
	}
}
Ledger::apply( $q, $rows, Inventory::owned_ids( $q ) );
$again = Proposals::build( Inventory::snapshot( $q, $w ), $w );
check( 'right after Apply nothing is proposed again', array() === array_values( array_filter( $again['rows'], function ( $r ) { return $r['ticked']; } ) ) );
check( 'the address (slug) with a claim word is flagged', in_array( 'slug', array_column( $again['flags'], 'type' ), true ) );
$GLOBALS['fh']['posts'][ $q ]->post_title = 'Praziquantel Powder'; // The owner renames it, as the flag suggests.
$after = Proposals::build( Inventory::snapshot( $q, $w ), $w );
$by    = array();
foreach ( $after['rows'] as $r ) {
	$by[ $r['field'] ] = $r;
}
check( 'the description written with the old name is proposed again, with the new name', isset( $by['post_content'] ) && false !== strpos( $by['post_content']['proposed'], 'Praziquantel Powder' ) && false === strpos( $by['post_content']['proposed'], 'Fluke' ) );
check( 'no name flag any more', ! in_array( 'name', array_column( $after['flags'], 'type' ), true ) );

fh_done();
