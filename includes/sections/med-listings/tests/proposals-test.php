<?php
/**
 * Find and review: which products are found, what is proposed for each
 * field, and what is only flagged for the owner.
 *
 * Run from the plugin root:
 *   php includes/sections/med-listings/tests/proposals-test.php
 *
 * @package FisHotel\Misc\Sections\Med_Listings
 */

require_once __DIR__ . '/seed.php';

use FisHotel\Misc\Sections\Med_Listings\Composer;
use FisHotel\Misc\Sections\Med_Listings\Finder;
use FisHotel\Misc\Sections\Med_Listings\Inventory;
use FisHotel\Misc\Sections\Med_Listings\Proposals;
use FisHotel\Misc\Sections\Med_Listings\Words;

$ids = fh_seed();
$w   = Words::get();

echo "1. Find\n";
$found = Inventory::find( $w );
check( 'the med-category products are found', isset( $found[ $ids['trio'] ], $found[ $ids['cure'] ] ) );
check( 'a product outside the categories that names a drug is found, with the word', isset( $found[ $ids['copper'] ] ) && false !== strpos( implode( ' ', $found[ $ids['copper'] ] ), 'copper' ) );
check( 'a fish in a left-out category is not, though its text says copper', ! isset( $found[ $ids['fish'] ] ) );
check( 'in name order', array( $ids['copper'], $ids['cure'], $ids['trio'] ) === array_keys( $found ), implode( ',', array_keys( $found ) ) );

echo "\n2. Proposals for the flake food\n";
$snap = Inventory::snapshot( $ids['trio'], $w );
$b    = Proposals::build( $snap, $w );
$rows = array();
foreach ( $b['rows'] as $r ) {
	$rows[ $r['object'] . '|' . $r['field'] ] = $r;
}
$t = $ids['trio'];
check( 'facts from the product: food, EA, bases and sizes from the variations', $b['facts']['is_food'] && $b['facts']['is_ea'] && array( 'Omnivore Flake', 'Herbivore Flake' ) === $b['facts']['bases'] && 4 === count( $b['facts']['sizes'] ) );
check( 'description: the composed plain listing', isset( $rows[ "$t|post_content" ] ) && $rows[ "$t|post_content" ]['proposed'] === $b['composed']['description'] && false !== strpos( $b['composed']['description'], 'Ships direct from our supplier.' ) );
check( 'the current description is marked where it claims', $rows[ "$t|post_content" ]['hits'] && in_array( 'diseases', array_column( $rows[ "$t|post_content" ]['hits'], 'kind' ), true ) );
check( 'short description: composed', 'Flake food containing kanamycin, metronidazole, and nitrofurazone. Available in omnivore or herbivore flake, 2 oz to 3 lb.' === $rows[ "$t|post_excerpt" ]['proposed'] );
foreach ( array( '_fishotel_med_eyebrow', '_fishotel_dosing_anchor', '_fishotel_treats', '_fishotel_use_in' ) as $k ) {
	check( "$k: emptied, ticked", isset( $rows[ "$t|meta:$k" ] ) && '' === $rows[ "$t|meta:$k" ]['proposed'] && $rows[ "$t|meta:$k" ]['ticked'] );
}
check( '"Best for": its claim taken out (nothing left → empty)', '' === $rows[ "$t|meta:_fishotel_best_for" ]['proposed'] );
check( 'Yoast description: the composed search description', $b['composed']['meta'] === $rows[ "$t|meta:_yoast_wpseo_metadesc" ]['proposed'] );
check( 'Yoast title: the claim part cut', 'Sample Trio Flake' === $rows[ "$t|meta:_yoast_wpseo_title" ]['proposed'] );
check( 'purchase note (in order e-mails): dosing out', '' === $rows[ "$t|meta:_purchase_note" ]['proposed'] );
check( 'extra checkout tick: proposed empty but NOT ticked (owner decides)', '' === $rows[ "$t|meta:_fishotel_med_extra_ack" ]['proposed'] && ! $rows[ "$t|meta:_fishotel_med_extra_ack" ]['ticked'] );
check( 'tags: disease and medicine-type tags off, ingredient tag kept', 'Metronidazole' === $rows[ "$t|tags" ]['proposed'] );
check( 'Treats attribute: taken off, not editable', isset( $rows[ "$t|attr:pa_med_treats" ] ) && ! $rows[ "$t|attr:pa_med_treats" ]['editable'] );
check( 'food base and size attributes stay', ! isset( $rows[ "$t|attr:food-base" ] ) && ! isset( $rows[ "$t|attr:size" ] ) );
check( 'variation description: cleaned', isset( $rows[ $ids['trio_v1'] . '|meta:_variation_description' ] ) && '' === $rows[ $ids['trio_v1'] . '|meta:_variation_description' ]['proposed'] );
check( 'picture alt text: the product name', 'Sample Trio Flake' === $rows[ $ids['trio_img'] . '|meta:_wp_attachment_image_alt' ]['proposed'] );
check( 'picture caption: cleaned', '' === $rows[ $ids['trio_img'] . '|post_excerpt' ]['proposed'] );
check( 'active ingredient is never proposed for change', ! isset( $rows[ "$t|meta:_fishotel_active_ingredient" ] ) );
check( 'every row carries the fingerprint of what it saw', count( array_filter( array_column( $b['rows'], 'hash' ) ) ) === count( $b['rows'] ) );

$types = array_column( $b['flags'], 'type' );
check( 'flag: the review naming ich and dosing (the clean one is not)', 1 === count( array_keys( $types, 'review', true ) ) && false !== strpos( $b['flags'][ array_search( 'review', $types, true ) ]['text'], 'Pat Example' ) );
check( 'no name flag: "Sample Trio Flake" is plain', ! in_array( 'name', $types, true ) );

echo "\n3. The powder with a claim name and no ingredients\n";
$b2    = Proposals::build( Inventory::snapshot( $ids['cure'], $w ), $w );
$types = array_column( $b2['flags'], 'type' );
check( 'flag: the name is a claim, with a suggestion', in_array( 'name', $types, true ) && '' !== $b2['flags'][ array_search( 'name', $types, true ) ]['suggest'] );
check( 'flag: ingredients missing', in_array( 'ingredients', $types, true ) );
check( 'flag: sold through Amazon', in_array( 'brand', $types, true ) );
$desc = null;
foreach ( $b2['rows'] as $r ) {
	if ( 'post_content' === $r['field'] ) {
		$desc = $r;
	}
}
check( 'its description has the blank and starts unticked', false !== strpos( $desc['proposed'], Composer::BLANK ) && ! $desc['ticked'] && '' !== $desc['note'] );
check( 'the composer never takes an ingredient from the old text', false === stripos( $desc['proposed'], 'praziquantel' ) );

echo "\n4. A field the tool already changed is not proposed again\n";
$snap['ledger'] = array( "$t|meta:_fishotel_treats" => 'Bloat, Hexamita, flukes' );
$again          = Proposals::build( $snap, $w );
check( 'left alone when it still holds what the tool wrote', ! in_array( "$t|meta:_fishotel_treats", array_column( $again['rows'], 'key' ), true ) );

echo "\n5. Claim attributes\n";
check( 'by name: "Use In", "Dosage", "Treats"', Proposals::is_claim_attribute( 'pa_med_use_in', array( 'label' => 'Use In', 'values' => array( 'Display' ) ), $w ) && Proposals::is_claim_attribute( 'dosage', array( 'label' => 'Dosage', 'values' => array( '1 g' ) ), $w ) );
check( 'by value: "Good against: velvet"', Proposals::is_claim_attribute( 'notes', array( 'label' => 'Notes', 'values' => array( 'velvet' ) ), $w ) );
check( 'not: Size, Food base, Form', ! Proposals::is_claim_attribute( 'size', array( 'label' => 'Size', 'values' => array( '2 oz' ) ), $w ) && ! Proposals::is_claim_attribute( 'pa_med_form', array( 'label' => 'Form', 'values' => array( 'Powder' ) ), $w ) );

fh_done();
