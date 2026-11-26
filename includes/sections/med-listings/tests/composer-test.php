<?php
/**
 * The composer and the claim finder.
 *
 * Run from the plugin root:
 *   php includes/sections/med-listings/tests/composer-test.php
 *
 * @package FisHotel\Misc\Sections\Med_Listings
 */

require_once __DIR__ . '/wp-stubs.php';

use FisHotel\Misc\Sections\Med_Listings\Composer;
use FisHotel\Misc\Sections\Med_Listings\Finder;
use FisHotel\Misc\Sections\Med_Listings\Words;

$w = Words::defaults();

echo "1. The owner's example (General Trio Flake)\n";
$c = Composer::compose(
	array(
		'name'        => 'General Trio Flake',
		'ingredients' => 'Kanamycin, Metronidazole, Nitrofurazone',
		'is_food'     => true,
		'bases'       => array( 'Omnivore Flake', 'Herbivore Flake' ),
		'sizes'       => array( '3 lb', '2 oz', '8 oz', '1 lb' ),
		'is_ea'       => true,
		'storage'     => $w['storage'],
		'supplier'    => $w['supplier'],
	)
);
check(
	'the five lines, word for word',
	array(
		'General Trio Flake',
		'Flake food containing kanamycin, metronidazole, and nitrofurazone.',
		'Available in omnivore or herbivore flake, 2 oz to 3 lb.',
		'Keep sealed, store cool and dry. Not for human consumption. Keep out of reach of children.',
		'Ships direct from our supplier.',
	) === $c['lines'],
	print_r( $c['lines'], true )
);
check( 'the description is one paragraph per line', 5 === substr_count( $c['description'], '<p>' ) );
check( 'the short description is the contents and sizes', 'Flake food containing kanamycin, metronidazole, and nitrofurazone. Available in omnivore or herbivore flake, 2 oz to 3 lb.' === $c['excerpt'], $c['excerpt'] );
check( 'the search description starts with the name', 0 === strpos( $c['meta'], 'General Trio Flake. Flake food' ) );
check( 'the listing itself carries no claim', ! Finder::has( $c['description'], $w ) );
check( 'ingredients were there', false === $c['missing_ingredients'] );

echo "\n2. Not a food, not shipped by the supplier\n";
$c = Composer::compose( array( 'name' => 'Metro Powder', 'ingredients' => 'Metronidazole 99%+', 'sizes' => array( '100 g', '10 g', '25 g' ), 'storage' => 'Keep sealed.' ) );
check( '"Contains:" line, the percentage kept', 'Contains: metronidazole 99%+.' === $c['lines'][1], $c['lines'][1] );
check( 'sizes smallest to largest', 'Available in 10 g to 100 g.' === $c['lines'][2], $c['lines'][2] );
check( 'no supplier line', 4 === count( $c['lines'] ) );

echo "\n3. Ingredients missing: a blank the owner fills\n";
$c = Composer::compose( array( 'name' => 'Mystery Pellet', 'is_food' => true, 'bases' => array( '1 mm Pellet', '2 mm Pellet' ) ) );
check( 'the blank is in the line', false !== strpos( $c['lines'][1], Composer::BLANK ) && 0 === strpos( $c['lines'][1], 'Pellet food containing' ), $c['lines'][1] );
check( 'and says where to look', false !== strpos( Composer::BLANK, 'confirm against the EA product' ) );
check( 'flagged as missing', true === $c['missing_ingredients'] );
check( 'pellet sizes as the food base', 'Available in 1 mm or 2 mm pellet.' === $c['lines'][2], $c['lines'][2] );

echo "\n4. The pieces\n";
check( 'split on commas, plus, "and"', array( 'Praziquantel', 'Metronidazole', 'Levamisole' ) === Composer::split_ingredients( 'Praziquantel + Metronidazole and Levamisole' ) );
check( 'abbreviations keep their capitals', 'TMP/sulfa' === Composer::ingredient_words( 'TMP/Sulfa' ) );
check( 'two sizes: "or"', '8 oz or 1 lb' === Composer::sizes_phrase( array( '1 lb', '8 oz' ) ) );
check( 'one size', '4 fl oz' === Composer::sizes_phrase( array( '4 fl oz' ) ) );
check( 'not a size is left out', '' === Composer::sizes_phrase( array( 'Omnivore', 'Large' ) ) );
check( 'a jar size is a size', Composer::is_size( '2 oz jar' ) && ! Composer::is_size( 'Herbivore' ) );
check( 'form from the name', 'powder' === Composer::form_of( array(), 'Prazi Powder 10g' ) );
check( 'no bases, no sizes: no line', '' === Composer::available_line( array(), '', array() ) );

echo "\n5. Neutral names for names that are claims\n";
check( '"General Cure" → from the ingredients', 'Metronidazole + Praziquantel Powder' === Composer::neutral_name( 'General Cure', array( 'ingredients' => 'metronidazole, praziquantel', 'form' => 'powder' ), $w ) );
check( '"Anti-Parasite Duo" (a food)', 'Praziquantel + Levamisole Pellet Food' === Composer::neutral_name( 'Anti-Parasite Duo', array( 'ingredients' => 'Praziquantel, Levamisole', 'is_food' => true, 'bases' => array( 'Pellet' ) ), $w ) );
check( 'enough left after the claim: keep it', 'Reef Copper Concentrate' === Composer::neutral_name( 'Reef Copper Ich Concentrate', array( 'ingredients' => 'copper' ), $w ), Composer::neutral_name( 'Reef Copper Ich Concentrate', array(), $w ) );
check( 'no ingredients: says a name is to choose', false !== strpos( Composer::neutral_name( 'Fluke Tabs', array(), $w ), 'name to choose' ) );
check( 'a plain name: nothing to suggest', '' === Composer::neutral_name( 'General Trio Flake', array(), $w ) );

echo "\n6. The finder\n";
$hits = Finder::find( 'Treats Hexamita & HITH; feed as sole food 7–10 days. See /qt-scheduler/', $w );
$kinds = array_column( $hits, 'kind' );
check( 'disease, claim, dosing and link words, each by its list', array( 'claims', 'diseases', 'diseases', 'dosing', 'dosing', 'links' ) === $kinds, implode( ',', $kinds ) );
check( 'whole words only: "which" is not ich, Copperband is not copper', ! Finder::has( 'Which fish? Copperband.', $w ) && ! Finder::has( 'Copperband', $w, array( 'drugs' ) ) );
check( 'capitals ignored, space = hyphen', Finder::has( 'BROAD SPECTRUM', $w ) && Finder::has( 'broad-spectrum', $w ) );
check( 'a pattern line works ("for 14 days")', Finder::has( 'Use for 14 days.', $w, array( 'dosing' ) ) );
check( 'longest wins where two overlap ("gill flukes" not "flukes")', 'gill flukes' === strtolower( Finder::find( 'Gill flukes', $w )[0]['match'] ) );
check( 'highlight escapes the text', '&lt;b&gt;<mark class="fhml-hit fhml-hit--diseases" title="diseases">ich</mark>' === Finder::highlight( '<b>ich', Finder::find( '<b>ich', $w ) ) );
check( 'clean: claim sentences out, the rest kept', 'Keep sealed.' === Finder::clean( 'Great for QT. Dose 1 tsp per 10 gallons for 7-10 days. Keep sealed.', $w ) );
check( 'clean: a title loses its claim part', 'General Trio Flake' === Finder::clean( 'General Trio Flake | Broad-Spectrum Fish Medication', $w ) );
check( 'clean: nothing clean left → empty', '' === Finder::clean( 'Kills ich.', $w ) );
check( 'clean: a question left alone goes too', '' === Finder::clean( 'Dose once daily. Questions? Use our Dosing Calculator.', $w ) );
check( 'clean: no claim → the same text', 'Omnivore flake.' === Finder::clean( 'Omnivore flake.', $w ) );

echo "\n7. The word lists\n";
$in = Words::from_input( array( 'diseases' => "ich\n\nich\n/(unclosed/\nvelvet" ) );
check( 'one per line, repeats and blanks dropped, a broken pattern left out', array( 'ich', 'velvet' ) === $in['saved']['diseases'] && array( '/(unclosed/' ) === $in['bad'] );
$GLOBALS['fh']['options'][ Words::OPTION ] = array( 'diseases' => array( 'zebra rot' ) );
$got = Words::get();
check( 'a saved list replaces that list only', array( 'zebra rot' ) === $got['diseases'] && $got['claims'] === $w['claims'] );

fh_done();
