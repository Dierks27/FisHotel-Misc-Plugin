<?php
/**
 * Invented medication products full of claims, for the tests (loaded by
 * them; run on its own it only checks that it loads).
 *
 * @package FisHotel\Misc\Sections\Med_Listings
 */

require_once __DIR__ . '/wp-stubs.php';

/**
 * Seed a small invented shop.
 *
 * @return array ids by name
 */
function fh_seed() {
	fh_reset();
	$ids = array();

	$ids['cat_meds']  = fh_term( 'product_cat', 'Medications', 'medications', 0, 'Treatments for ich, velvet and flukes. Everything we use in QT.' );
	$ids['cat_para']  = fh_term( 'product_cat', 'Antiparasitic', 'antiparasitic', $ids['cat_meds'], 'Kills parasites.' );
	$ids['cat_bact']  = fh_term( 'product_cat', 'Antibacterial', 'antibacterial', $ids['cat_meds'] );
	$ids['cat_food']  = fh_term( 'product_cat', 'Medicated Food', 'medicated-food', $ids['cat_meds'] );
	$ids['cat_fish']  = fh_term( 'product_cat', 'Quarantined Fish', 'quarantined-fish' );
	$ids['tag_ich']   = fh_term( 'product_tag', 'Ich' );
	$ids['tag_flukes'] = fh_term( 'product_tag', 'Flukes' );
	$ids['tag_metro'] = fh_term( 'product_tag', 'Metronidazole' );
	$ids['tag_anti']  = fh_term( 'product_tag', 'Anthelmintic' );
	$ids['treat_ich'] = fh_term( 'pa_med_treats', 'Ich' );
	$ids['treat_fl']  = fh_term( 'pa_med_treats', 'Flukes' );

	// 1. A medicated flake food (EA), every claim the spec lists.
	$p = fh_post(
		array(
			'post_title'   => 'Sample Trio Flake',
			'post_name'    => 'sample-trio-flake',
			'post_content' => "<p>Broad-spectrum bacterial + flagellate infections. Treats flukes, internal flagellates and nematodes — broad QT coverage.</p>\n<p>Feed as sole food 7–10 days. See the <a href=\"/medication-dosing/?med=trio\">Dosing Calculator</a> (Misc tab).</p>\n<p>Fulfilled by EverythingAquatic, our trusted medication partner.</p>",
			'post_excerpt' => 'Kills Hexamita and HITH. Great for quarantine.',
		)
	);
	$ids['trio'] = $p;
	wp_set_object_terms( $p, array( $ids['cat_meds'], $ids['cat_food'] ), 'product_cat' );
	wp_set_object_terms( $p, array( $ids['tag_ich'], $ids['tag_metro'], $ids['tag_anti'] ), 'product_tag' );
	update_post_meta( $p, '_fishotel_active_ingredient', 'Kanamycin, Metronidazole, Nitrofurazone' );
	update_post_meta( $p, '_fishotel_fulfillment', 'ea' );
	update_post_meta( $p, '_fishotel_med_eyebrow', 'The Broad-Spectrum Bloat Buster' );
	update_post_meta( $p, '_fishotel_dosing_anchor', 'trio' );
	update_post_meta( $p, '_fishotel_treats', 'Bloat, Hexamita, flukes' );
	update_post_meta( $p, '_fishotel_use_in', 'QT tank, hospital tank' );
	update_post_meta( $p, '_fishotel_best_for', 'Finicky eaters in a hospital tank' );
	update_post_meta( $p, '_yoast_wpseo_metadesc', 'Treats bloat and internal parasites fast.' );
	update_post_meta( $p, '_yoast_wpseo_title', 'Sample Trio Flake | Broad-Spectrum Fish Medication' );
	update_post_meta( $p, '_fishotel_med_extra_ack', 'I will only dose it in a fishless quarantine tank.' );
	update_post_meta( $p, '_purchase_note', 'Dose once daily for 10 days.' );
	fh_attr( $p, 'pa_med_treats', array( $ids['treat_ich'], $ids['treat_fl'] ), false, 1 );
	fh_attr( $p, 'Food base', array( 'Omnivore Flake', 'Herbivore Flake' ), true );
	fh_attr( $p, 'Size', array( '3 lb', '2 oz', '8 oz', '1 lb' ), true );
	$v1 = fh_post( array( 'post_type' => 'product_variation', 'post_parent' => $p, 'post_title' => 'Sample Trio Flake - 2 oz' ) );
	update_post_meta( $v1, '_variation_description', 'Treats one 40 gallon QT for 10 days.' );
	$ids['trio_v1'] = $v1;
	$img = fh_post( array( 'post_type' => 'attachment', 'post_excerpt' => 'Kills flukes fast' ) );
	update_post_meta( $img, '_wp_attachment_image_alt', 'Flake that cures ich' );
	update_post_meta( $p, '_thumbnail_id', $img );
	$ids['trio_img'] = $img;
	$ids['trio_review'] = fh_comment( $p, 'Pat Example', 'Cleared up the ich in my tang in 5 days at one dose a day!' );
	$ids['trio_review_ok'] = fh_comment( $p, 'Sam Example', 'Fast shipping, nice jar.' );

	// 2. A powder with no ingredient field and a claim name.
	$q = fh_post(
		array(
			'post_title'   => 'Fluke Cure Powder',
			'post_name'    => 'fluke-cure-powder',
			'post_content' => 'Praziquantel. Kills gill flukes and tapeworms. Dose 1 tsp per 10 gallons.',
		)
	);
	$ids['cure'] = $q;
	wp_set_object_terms( $q, array( $ids['cat_para'] ), 'product_cat' );
	wp_set_object_terms( $q, array( $ids['tag_flukes'] ), 'product_tag' );
	update_post_meta( $q, '_fishotel_amazon_asin', 'B0TESTTEST' );

	// 3. Not in a med category but names a drug; and a fish that must stay out.
	$r = fh_post( array( 'post_title' => 'Copper Test Strips', 'post_name' => 'copper-test-strips', 'post_content' => 'Reads copper levels.' ) );
	$ids['copper'] = $r;
	$f = fh_post( array( 'post_title' => 'Copperband Test Butterfly', 'post_name' => 'copperband', 'post_content' => 'Eats aiptasia. Raised in copper-free QT.' ) );
	wp_set_object_terms( $f, array( $ids['cat_fish'] ), 'product_cat' );
	$ids['fish'] = $f;

	return $ids;
}
