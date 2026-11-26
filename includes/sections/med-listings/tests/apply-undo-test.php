<?php
/**
 * Apply and Undo: a round trip puts back exactly what was there — text,
 * meta that did not exist, tags, a whole attribute with its terms, a
 * review's status, a category with its products — and Undo never wipes
 * a change someone made after the Apply.
 *
 * Run from the plugin root:
 *   php includes/sections/med-listings/tests/apply-undo-test.php
 *
 * @package FisHotel\Misc\Sections\Med_Listings
 */

require_once __DIR__ . '/seed.php';

use FisHotel\Misc\Sections\Med_Listings\Fields;
use FisHotel\Misc\Sections\Med_Listings\Inventory;
use FisHotel\Misc\Sections\Med_Listings\Ledger;
use FisHotel\Misc\Sections\Med_Listings\Proposals;
use FisHotel\Misc\Sections\Med_Listings\Redirects;
use FisHotel\Misc\Sections\Med_Listings\Terms;
use FisHotel\Misc\Sections\Med_Listings\Words;

/** Everything stored that the cleanup may touch, for comparing. */
function fh_state() {
	$fh = $GLOBALS['fh'];
	$a  = array();
	foreach ( $fh['attrs'] as $pid => $list ) {
		foreach ( $list as $k => $o ) {
			$a[ $pid ][ $k ] = Fields::export_attribute( $o );
		}
		ksort( $a[ $pid ] ); // WooCommerce orders attributes by their position, not by key order.
	}
	$posts = array();
	foreach ( $fh['posts'] as $id => $p ) {
		$posts[ $id ] = (array) $p;
	}
	$rel = $fh['rel'];
	foreach ( $rel as $tax => $objs ) {
		foreach ( $objs as $o => $ids ) {
			sort( $ids );
			$rel[ $tax ][ $o ] = $ids;
		}
	}
	$comments = array();
	foreach ( $fh['comments'] as $id => $c ) {
		$comments[ $id ] = $c->status;
	}
	return serialize( array( $posts, $fh['meta'], $rel, $a, $comments ) );
}

$ids = fh_seed();
$w   = Words::get();
$t   = $ids['trio'];

echo "1. Apply every proposal on the flake food, then Undo\n";
$before = fh_state();
$b      = Proposals::build( Inventory::snapshot( $t, $w ), $w );
$rows   = array();
foreach ( $b['rows'] as $r ) {
	$rows[] = array(
		'object' => $r['object'],
		'field'  => $r['field'],
		'hash'   => $r['hash'],
		'value'  => 'post_content' === $r['field'] ? $r['proposed'] . "\n<p>Owner's own line with a backslash \\ and 'quotes'.</p>" : $r['proposed'],
	);
}
$res = Ledger::apply( $t, $rows, Inventory::owned_ids( $t ) );
check( 'every row applied', count( $rows ) === count( array_filter( array_column( $res, 'ok' ) ) ), print_r( array_filter( $res, function ( $r ) { return ! $r['ok']; } ), true ) );
check( 'the description is the new text, the owner\'s edit and its backslash kept', false !== strpos( get_post( $t )->post_content, "backslash \\ and 'quotes'" ) && false !== strpos( get_post( $t )->post_content, 'Flake food containing' ) );
check( 'meta emptied', '' === get_post_meta( $t, '_fishotel_treats', true ) && metadata_exists( 'post', $t, '_fishotel_treats' ) );
check( 'tags now just the ingredient', array( 'Metronidazole' ) === wp_get_object_terms( $t, 'product_tag', array( 'fields' => 'names' ) ) );
check( 'the Treats attribute is off the product and its terms with it', ! isset( $GLOBALS['fh']['attrs'][ $t ]['pa_med_treats'] ) && array() === ( $GLOBALS['fh']['rel']['pa_med_treats'][ $t ] ?? array() ) );
check( 'the food base and size attributes are still there', isset( $GLOBALS['fh']['attrs'][ $t ]['food_base'] ) || isset( $GLOBALS['fh']['attrs'][ $t ]['food-base'] ) );
check( 'the alt text is the name', 'Sample Trio Flake' === get_post_meta( $ids['trio_img'], '_wp_attachment_image_alt', true ) );
check( 'the record holds one entry per field', count( Ledger::for_product( $t ) ) === count( $rows ) );

$again = Proposals::build( Inventory::snapshot( $t, $w ), $w );
check( 'after Apply nothing is proposed again for those fields', array() === array_intersect( array_column( $again['rows'], 'key' ), array_column( $b['rows'], 'key' ) ), implode( ', ', array_column( $again['rows'], 'key' ) ) );

$u = Ledger::undo( $t );
check( 'Undo restored every field', count( $rows ) === $u['restored'] && array() === $u['kept'] );
check( 'the shop is exactly as before (text, meta, tags, attribute and its terms)', fh_state() === $before );
check( 'the record for the product is gone', array() === Ledger::for_product( $t ) );

echo "\n2. Meta that was not there at all\n";
$p = $ids['copper'];
$h = Fields::hash( Fields::read( $p, 'meta:_yoast_wpseo_metadesc' ) );
Ledger::apply( $p, array( array( 'object' => $p, 'field' => 'meta:_yoast_wpseo_metadesc', 'value' => 'Copper test strips.', 'hash' => $h ) ), Inventory::owned_ids( $p ) );
check( 'written', 'Copper test strips.' === get_post_meta( $p, '_yoast_wpseo_metadesc', true ) );
Ledger::undo( $p );
check( 'Undo removes it again (not just empties it)', ! metadata_exists( 'post', $p, '_yoast_wpseo_metadesc' ) );

echo "\n3. What Apply refuses\n";
$r = Ledger::apply( $t, array( array( 'object' => $t, 'field' => 'post_excerpt', 'value' => 'New', 'hash' => 'not-what-is-there' ) ), Inventory::owned_ids( $t ) );
check( 'a field changed since the page was opened', ! $r[0]['ok'] && false !== strpos( $r[0]['message'], 'Changed since' ) && 'Kills Hexamita and HITH. Great for quarantine.' === get_post( $t )->post_excerpt );
$r = Ledger::apply( $t, array( array( 'object' => $t, 'field' => 'post_content', 'value' => 'Contains: [[fill in]].', 'hash' => '' ) ), Inventory::owned_ids( $t ) );
check( 'a [[ blank ]] still in the text', ! $r[0]['ok'] );
$r = Ledger::apply( $t, array( array( 'object' => $ids['cure'], 'field' => 'post_content', 'value' => 'x', 'hash' => '' ) ), Inventory::owned_ids( $t ) );
check( 'another product\'s post', ! $r[0]['ok'] && 'Praziquantel. Kills gill flukes and tapeworms. Dose 1 tsp per 10 gallons.' === get_post( $ids['cure'] )->post_content );
$r = Ledger::apply( $t, array( array( 'object' => $t, 'field' => 'meta:_price', 'value' => '0', 'hash' => '' ) ), Inventory::owned_ids( $t ) );
check( 'a field this tool does not write (the price)', ! $r[0]['ok'] && ! metadata_exists( 'post', $t, '_price' ) );
check( 'none of the refusals left a record', array() === Ledger::for_product( $t ) );

echo "\n4. Undo never wipes a later change\n";
$h = Fields::hash( Fields::read( $t, 'meta:_fishotel_use_in' ) );
$h2 = Fields::hash( Fields::read( $t, 'meta:_fishotel_med_eyebrow' ) );
Ledger::apply(
	$t,
	array(
		array( 'object' => $t, 'field' => 'meta:_fishotel_use_in', 'value' => '', 'hash' => $h ),
		array( 'object' => $t, 'field' => 'meta:_fishotel_med_eyebrow', 'value' => '', 'hash' => $h2 ),
	),
	Inventory::owned_ids( $t )
);
update_post_meta( $t, '_fishotel_use_in', 'Typed later by the owner' );
$u = Ledger::undo( $t );
check( 'the field changed later is kept and named', array( "$t|meta:_fishotel_use_in" ) === $u['kept'] && 'Typed later by the owner' === get_post_meta( $t, '_fishotel_use_in', true ) );
check( 'the other one is put back', 'The Broad-Spectrum Bloat Buster' === get_post_meta( $t, '_fishotel_med_eyebrow', true ) );
check( 'the kept one stays in the record', array( "$t|meta:_fishotel_use_in" ) === array_keys( Ledger::for_product( $t ) ) );

echo "\n5. A second Apply keeps the first old value\n";
$ids = fh_seed();
$t   = $ids['trio'];
$h   = Fields::hash( Fields::read( $t, 'post_excerpt' ) );
Ledger::apply( $t, array( array( 'object' => $t, 'field' => 'post_excerpt', 'value' => 'First.', 'hash' => $h ) ), Inventory::owned_ids( $t ) );
$h = Fields::hash( Fields::read( $t, 'post_excerpt' ) );
Ledger::apply( $t, array( array( 'object' => $t, 'field' => 'post_excerpt', 'value' => 'Second.', 'hash' => $h ) ), Inventory::owned_ids( $t ) );
Ledger::undo( $t );
check( 'Undo goes back to the original, not to "First."', 'Kills Hexamita and HITH. Great for quarantine.' === get_post( $t )->post_excerpt );

echo "\n6. Reviews: hide and show again\n";
$c = $ids['trio_review'];
Ledger::review( $c, true );
check( 'hidden (held for moderation)', 'unapproved' === wp_get_comment_status( $c ) );
Ledger::review( $c, false );
check( 'shown again as it was', 'approved' === wp_get_comment_status( $c ) );

echo "\n7. Categories: sort, rename, remove a tag — and Undo each\n";
$ids    = fh_seed();
$before = fh_state();
$terms_before = serialize( $GLOBALS['fh']['terms'] );
$rows   = array();
foreach ( Terms::proposals( $w ) as $r ) {
	$rows[ $r['tax'] . ':' . $r['slug'] ] = $r;
}
check( 'Antiparasitic and Antibacterial: sort by type', 'sort' === $rows['product_cat:antiparasitic']['action'] && 'sort' === $rows['product_cat:antibacterial']['action'] );
check( 'Medicated Food: renamed "Medicated Foods", slug kept', 'rename' === $rows['product_cat:medicated-food']['action'] && 'Medicated Foods' === $rows['product_cat:medicated-food']['new_name'] && 'medicated-food' === $rows['product_cat:medicated-food']['new_slug'] );
check( 'Medications: name and slug kept, description cleaned', 'rename' === $rows['product_cat:medications']['action'] && 'medications' === $rows['product_cat:medications']['new_slug'] && '' === $rows['product_cat:medications']['new_desc'] );
check( 'the fish category is not in the table', ! isset( $rows['product_cat:quarantined-fish'] ) );
check( 'disease and medicine-type tags: remove; the ingredient tag is not listed', 'delete' === $rows['product_tag:ich']['action'] && 'delete' === $rows['product_tag:anthelmintic']['action'] && ! isset( $rows['product_tag:metronidazole'] ) );
check( 'Treats attribute: one row, left alone while a product still uses it', 'keep' === $rows['attribute:pa_med_treats']['action'] && $rows['attribute:pa_med_treats']['blocked'] && ! isset( $rows['pa_med_treats:ich'] ) );

$sort = Terms::apply( $rows['product_cat:antiparasitic'], $w );
check( 'sort done', $sort['ok'], $sort['message'] );
$cure_cats = wp_get_object_terms( $ids['cure'], 'product_cat', array( 'fields' => 'slugs' ) );
sort( $cure_cats );
check( 'the powder went to Powders & Chemicals AND Medications (the theme still finds it)', array( 'medications', 'powders-chemicals' ) === $cure_cats, implode( ',', $cure_cats ) );
check( 'Powders & Chemicals was made under Medications', get_term_by( 'slug', 'powders-chemicals', 'product_cat' )->parent === $ids['cat_meds'] );
check( 'Antiparasitic is gone', false === get_term_by( 'slug', 'antiparasitic', 'product_cat' ) );
$list = Redirects::all();
$froms = array_column( $list, 'from' );
check( 'its full and short addresses redirect', in_array( '/product-category/medications/antiparasitic/', $froms, true ) && in_array( '/product-category/antiparasitic/', $froms, true ) );
check( '…to Powders & Chemicals', 'https://shop.test/product-category/medications/powders-chemicals/' === Redirects::target_url( $list[0] ) );

$ren = Terms::apply( array_merge( $rows['product_cat:medicated-food'], array( 'new_slug' => 'medicated-foods' ) ), $w );
check( 'rename with a new slug done', $ren['ok'] && 'Medicated Foods' === get_term( $ids['cat_food'] )->name );
$hit = Redirects::find_match( '/product-category/medications/medicated-food/page/2/', array(), Redirects::all() );
check( 'its old address (page 2) goes to the new one', $hit && 'https://shop.test/product-category/medications/medicated-foods/page/2/' === Redirects::with_suffix( Redirects::target_url( $hit['entry'] ), $hit['suffix'] ) );

$del = Terms::apply( $rows['product_tag:ich'], $w );
check( 'the Ich tag is removed from the product and the shop', $del['ok'] && false === get_term_by( 'slug', 'ich', 'product_tag' ) && ! in_array( 'Ich', wp_get_object_terms( $ids['trio'], 'product_tag', array( 'fields' => 'names' ) ), true ) );

$parent = Terms::apply( array_merge( $rows['product_cat:medications'], array( 'action' => 'sort' ) ), $w );
check( 'Medications itself can never be removed', ! $parent['ok'] && get_term( $ids['cat_meds'] ) );

// Oldest first: the neutral category made by the first change is still removed at the end.
foreach ( array_keys( Ledger::all()['terms'] ) as $change ) {
	$u = Terms::undo( $change );
	check( "undo $change", $u['ok'], $u['message'] );
}
$tag = get_term_by( 'slug', 'ich', 'product_tag' );
check( 'the Ich tag is back on the product', $tag && in_array( $tag->term_id, $GLOBALS['fh']['rel']['product_tag'][ $ids['trio'] ], true ) );
$para = get_term_by( 'slug', 'antiparasitic', 'product_cat' );
check( 'Antiparasitic is back, under Medications, with its description, and the powder in it', $para && $ids['cat_meds'] === $para->parent && 'Kills parasites.' === $para->description && array( 'antiparasitic' ) === wp_get_object_terms( $ids['cure'], 'product_cat', array( 'fields' => 'slugs' ) ) );
check( 'Powders & Chemicals (made by the step, empty again) is gone', false === get_term_by( 'slug', 'powders-chemicals', 'product_cat' ) );
check( 'Medicated Food has its old name and slug', 'Medicated Food' === get_term( $ids['cat_food'] )->name && 'medicated-food' === get_term( $ids['cat_food'] )->slug );
check( 'no redirect left', array() === Redirects::all() );
check( 'nothing left to undo', array() === Ledger::all()['terms'] );

echo "\n8. A Treats attribute taken off, its values removed in step 5 — Undo in either order\n";
foreach ( array( 'product first', 'categories first' ) as $order ) {
	$ids    = fh_seed();
	$t      = $ids['trio'];
	$before = fh_state();
	$read   = Fields::read( $t, 'attr:pa_med_treats' );
	Ledger::apply( $t, array( array( 'object' => $t, 'field' => 'attr:pa_med_treats', 'value' => '', 'hash' => Fields::hash( $read ) ) ), Inventory::owned_ids( $t ) );
	foreach ( Terms::proposals( $w ) as $r ) {
		if ( 'attribute' === $r['tax'] && 'pa_med_treats' === $r['slug'] ) {
			check( "$order: once off the product, the attribute row is ready", 'delete_attribute' === $r['action'] && $r['ticked'] );
			$done = Terms::apply( $r, $w );
			check( "$order: attribute removed", $done['ok'], $done['message'] );
		}
	}
	check( "$order: the attribute and its values are gone from the shop", ! taxonomy_exists( 'pa_med_treats' ) && array() === get_terms( array( 'taxonomy' => 'pa_med_treats' ) ) && ! wc_attribute_taxonomy_id_by_name( 'pa_med_treats' ) );
	if ( 'product first' === $order ) {
		Ledger::undo( $t );
	}
	foreach ( array_keys( Ledger::all()['terms'] ) as $change ) {
		Terms::undo( $change );
	}
	if ( 'categories first' === $order ) {
		Ledger::undo( $t );
	}
	$slugs = wp_get_object_terms( $t, 'pa_med_treats', array( 'fields' => 'slugs' ) );
	sort( $slugs );
	check( "$order: the attribute is back on the product with Ich and Flukes", array( 'flukes', 'ich' ) === $slugs && isset( $GLOBALS['fh']['attrs'][ $t ]['pa_med_treats'] ) );
	check( "$order: each value exists once", 2 === count( get_terms( array( 'taxonomy' => 'pa_med_treats' ) ) ) );
}

echo "\n9. Attributes taken off one by one come back in their old order (ties on position keep the order shown on the page)\n";
$ids  = fh_seed();
$t    = $ids['trio'];
$keys = array();
foreach ( array( 'Treats' => array( 0, 'pa_med_treats' ), 'Directions' => array( 0, 'directions' ), 'Use' => array( 0, 'use' ), 'Shelf' => array( 1, 'shelf' ) ) as $label => $pk ) {
	if ( ! isset( $GLOBALS['fh']['attrs'][ $t ][ $pk[1] ] ) ) {
		$a = new WC_Product_Attribute();
		$a->set_name( $label );
		$a->set_options( array( 'Dose every 48 hours' ) );
		$a->set_position( $pk[0] );
		$GLOBALS['fh']['attrs'][ $t ][ $pk[1] ] = $a;
	}
}
// Put a fixed order on the product: the medication attributes first, then the rest as they were.
$was   = $GLOBALS['fh']['attrs'][ $t ];
$order = array_merge( array( 'pa_med_treats', 'directions', 'use', 'shelf' ), array_diff( array_keys( $was ), array( 'pa_med_treats', 'directions', 'use', 'shelf' ) ) );
$GLOBALS['fh']['attrs'][ $t ] = array();
foreach ( $order as $k ) {
	$GLOBALS['fh']['attrs'][ $t ][ $k ] = $was[ $k ];
}
$GLOBALS['fh']['attrs'][ $t ] = fh_stable_by_position( $GLOBALS['fh']['attrs'][ $t ] ); // WooCommerce stores them sorted by position.
$before_keys = array_keys( $GLOBALS['fh']['attrs'][ $t ] );
$rows        = array();
foreach ( array( 'attr:pa_med_treats', 'attr:directions', 'attr:use' ) as $f ) {
	$rows[] = array( 'object' => $t, 'field' => $f, 'value' => '', 'hash' => Fields::hash( Fields::read( $t, $f ) ) );
}
$res = Ledger::apply( $t, $rows, Inventory::owned_ids( $t ) );
check( 'three attributes taken off in one Apply', 3 === count( array_filter( wp_list_pluck( $res, 'ok' ) ) ) && ! isset( $GLOBALS['fh']['attrs'][ $t ]['use'] ) );
Ledger::undo( $t );
check( 'after Undo the attributes are in the same order as before', $before_keys === array_keys( $GLOBALS['fh']['attrs'][ $t ] ), implode( ',', array_keys( $GLOBALS['fh']['attrs'][ $t ] ) ) );

echo "\n10. A sub-category removed before its parent comes back under its parent, whichever is undone first\n";
foreach ( array( 'oldest first', 'newest first' ) as $undo_order ) {
	$ids = fh_seed();
	$sub = fh_term( 'product_cat', 'Gram Negative', 'gram-negative', $ids['cat_bact'], 'For gram-negative infections.' );
	add_term_meta( $ids['cat_para'], 'display_type', 'products' );
	add_term_meta( $ids['cat_para'], 'product_count_product_cat', '1' );
	$rows = array();
	foreach ( Terms::proposals( $w ) as $r ) {
		$rows[ $r['tax'] . ':' . $r['slug'] ] = $r;
	}
	check( "$undo_order: the sub-category is proposed", isset( $rows['product_cat:gram-negative'] ) && 'keep' !== $rows['product_cat:gram-negative']['action'] );
	$a1 = Terms::apply( $rows['product_cat:gram-negative'], $w );
	$a2 = Terms::apply( $rows['product_cat:antibacterial'], $w );
	$a3 = Terms::apply( $rows['product_cat:antiparasitic'], $w );
	check( "$undo_order: sub-category, its parent and Antiparasitic removed", $a1['ok'] && $a2['ok'] && $a3['ok'] && false === get_term_by( 'slug', 'gram-negative', 'product_cat' ) && false === get_term_by( 'slug', 'antibacterial', 'product_cat' ) );
	$changes = array_keys( Ledger::all()['terms'] );
	if ( 'newest first' === $undo_order ) {
		$changes = array_reverse( $changes );
	}
	foreach ( $changes as $change ) {
		Terms::undo( $change );
	}
	$g = get_term_by( 'slug', 'gram-negative', 'product_cat' );
	$b = get_term_by( 'slug', 'antibacterial', 'product_cat' );
	check( "$undo_order: Gram Negative is back under Antibacterial", $g && $b && (int) $g->parent === (int) $b->term_id, $g ? 'parent ' . $g->parent : 'missing' );
	$p = get_term_by( 'slug', 'antiparasitic', 'product_cat' );
	check( "$undo_order: Antiparasitic's own settings are back", $p && 'products' === get_term_meta( $p->term_id, 'display_type', true ) );
	check( "$undo_order: WooCommerce's product count is not put back from the record (it was 0 when recorded; WooCommerce counts it again)", $p && array() === get_term_meta( $p->term_id, 'product_count_product_cat' ) );
}

fh_done();
