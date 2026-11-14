<?php
/**
 * Medications — the editable list: the seed IS the old fixed list (same
 * keys in the same order, names, other names and rates; copper dosed to a
 * level at 0.692 ml per gallon per 1.0 ppm; Salt typed; Chloroquine's
 * three named doses are the seeded CQ schedule's), a medication found by
 * its names in a label or a log note, the lenient reader of what is
 * stored, the strict Settings → Medications operations (add, edit —
 * keeping the key and the old names, so old logs still read —, retire,
 * bring back, every refusal), where the list is stored and that no other
 * settings save drops it, that the Protocol's helpers, the to-do and the
 * store's "last dose" follow an edited list, and the store's public
 * calculator's copper figure beside ours (present, absent, differing,
 * unreadable).
 *
 * Run from the plugin root:  php includes/sections/clean-room/tests/medications-test.php
 */

require_once __DIR__ . '/wp-stubs.php';

use FisHotel\Misc\Sections\Clean_Room\Clean_Room;
use FisHotel\Misc\Sections\Clean_Room\Dose;
use FisHotel\Misc\Sections\Clean_Room\Medications;
use FisHotel\Misc\Sections\Clean_Room\Protocol;
use FisHotel\Misc\Sections\Clean_Room\Settings;
use FisHotel\Misc\Sections\Clean_Room\Tests\Fake_Store;

$seed = Medications::seed();

echo "1. The seed is the old fixed list\n";
check( 'the same keys in the same order', array_keys( Protocol::MEDICATIONS ) === array_keys( $seed ) && array( 'copper', 'metro', 'prazi', 'formalin', 'chloroquine', 'fenbendazole', 'salt' ) === array_keys( $seed ) );
$same = true;
foreach ( Protocol::MEDICATIONS as $key => $fixed ) {
	$names = Medications::names( $seed[ $key ] );
	$old   = $fixed['aliases'];
	sort( $names );
	sort( $old );
	if ( $seed[ $key ]['key'] !== $key || $seed[ $key ]['label'] !== $fixed['label'] || $seed[ $key ]['short'] !== $fixed['short'] || $names !== $old || ! Dose::compatible( $seed[ $key ]['unit'], $fixed['unit'] ) || ! empty( $seed[ $key ]['retired'] ) ) {
		$same = false;
	}
}
check( 'each with its name, its chip, the same names to answer to and a unit of the same kind; none retired', $same );
$rates = array();
foreach ( Protocol::DOSE_RATES as $key => $rate ) {
	// The seed says "25 mg per gallon" / "1 ml per 10 gal"; the fixed list said grams or millilitres per gallon.
	$mine = Dose::convert( $seed[ $key ]['rate'], $seed[ $key ]['unit'], Protocol::MEDICATIONS[ $key ]['unit'] ) / $seed[ $key ]['per'];
	if ( 'per_gallon' !== $seed[ $key ]['mode'] || abs( $mine - $rate ) > 1e-9 ) {
		$rates[] = $key;
	}
}
check( 'the five rates are the fixed list\'s: 25 mg, 9.5 mg, 1 ml per 10 gal, 10 mg, 1 g per 100 gal', array() === $rates && array( 25.0, 'mg', 1.0 ) === array( $seed['metro']['rate'], $seed['metro']['unit'], $seed['metro']['per'] ) && array( 9.5, 'mg' ) === array( $seed['prazi']['rate'], $seed['prazi']['unit'] ) && array( 1.0, 'ml', 10.0 ) === array( $seed['formalin']['rate'], $seed['formalin']['unit'], $seed['formalin']['per'] ) && array( 10.0, 'mg' ) === array( $seed['chloroquine']['rate'], $seed['chloroquine']['unit'] ) && array( 1.0, 'g', 100.0 ) === array( $seed['fenbendazole']['rate'], $seed['fenbendazole']['unit'], $seed['fenbendazole']['per'] ), implode( ', ', $rates ) );
check( 'copper is dosed to a level: 0.692 ml per gallon per 1.0 ppm — the copper log\'s product', 'to_level' === $seed['copper']['mode'] && 0.692 === $seed['copper']['rate'] && 1.0 === $seed['copper']['per'] && 'ml' === $seed['copper']['unit'] && 'ppm' === $seed['copper']['level_unit'] && Medications::is_copper( $seed['copper'] ) && 'copper' === Medications::copper( $seed )['key'] && 0.692 === Medications::COPPER_RATE );
check( 'Salt is typed: no sum, as before', 'typed' === $seed['salt']['mode'] && 0.0 === $seed['salt']['rate'] && 'tsp' === $seed['salt']['unit'] && ! Medications::is_copper( $seed['salt'] ) );
check( 'Chloroquine has the three named doses: Loading 40, Top-up 12, Last 6 mg per gallon', array( array( 'label' => 'Loading', 'rate' => 40.0 ), array( 'label' => 'Top-up', 'rate' => 12.0 ), array( 'label' => 'Last', 'rate' => 6.0 ) ) === $seed['chloroquine']['doses'] && array() === $seed['metro']['doses'] );
$cq_rates = array();
foreach ( Protocol::templates()['CQ'] as $step ) {
	$rate = Dose::step_rate( $step['label'], $seed['chloroquine'] );
	if ( null !== $rate ) {
		$cq_rates[] = $rate;
	}
}
check( '…the three the seeded CQ schedule uses', array( 40.0, 12.0, 6.0 ) === $cq_rates && array( 40.0, 12.0, 6.0 ) === array_column( $seed['chloroquine']['doses'], 'rate' ) );
check( 'normalising the seed changes nothing; no measure is seeded', $seed === Medications::normalize( $seed ) && null === $seed['metro']['measure'] && $seed === Medications::normalize( array_values( $seed ) ) );
check( 'nothing stored: the list in use is the seed', $seed === Medications::current() && $seed === Settings::medications() && $seed === Medications::from_settings( Settings::get() ) && null === Settings::get()['medications'] && array_key_exists( 'medications', Settings::DEFAULTS ) );
check( 'the units and the ways of dosing', array( 'mg', 'g', 'ml', 'tsp', 'drops', 'each' ) === Medications::UNITS && Medications::UNITS === Protocol::UNITS && array( 'per_gallon', 'to_level', 'typed' ) === Medications::MODES );

echo "2. A medication by its names\n";
check( 'by any name, whole words, any case (the old checks)', 'copper' === Medications::key_for( 'Copper Power to 2.0', $seed ) && 'copper' === Medications::key_for( 'cu 2.4', $seed ) && 'metro' === Medications::key_for( 'Dosed Metro', $seed ) && 'prazi' === Medications::key_for( 'Praziquantel 2', $seed ) && 'chloroquine' === Medications::key_for( 'Dosed CP', $seed ) && 'fenbendazole' === Medications::key_for( 'fenben 2 g', $seed ) && 'formalin' === Medications::key_for( 'FORMALIN 20 ml', $seed ) && 'salt' === Medications::key_for( 'Added Salt', $seed ) );
check( 'the one named first wins, wherever it stands in the list', 'metro' === Medications::key_for( 'Metronidazole 5.125 G — Cu 2.3 before', $seed ) && 'copper' === Medications::key_for( 'Copper Power 55 ml, metro tomorrow', $seed ) );
check( 'a dose log with its working still names its medication first', 'copper' === Medications::key_for( 'Copper Power 16.6 ml · 2.16 → 2.40 ppm, 100 gal', $seed ) && 'metro' === Medications::key_for( 'Metronidazole 2.5 g · 25 mg per gallon, 100 gal', $seed ) && 'chloroquine' === Medications::key_for( 'Chloroquine 4 g · Loading: 40 mg per gallon, 100 gal', $seed ) );
check( 'not inside another word, no medication, nothing', null === Medications::key_for( 'Cumin test', $seed ) && null === Medications::key_for( 'Copperband Butterflyfish', $seed ) && null === Medications::key_for( 'Furan-2', $seed ) && null === Medications::key_for( '', $seed ) && null === Medications::key_for( 'metro', array() ) );
check( 'a key itself is found', 'fenbendazole' === Medications::key_for( 'fenbendazole', $seed ) && 'metro' === Medications::key_for( ' METRO ', $seed ) );
$two = Medications::normalize( array( array( 'key' => 'a', 'label' => 'Test Cure' ), array( 'key' => 'b', 'label' => 'Test Cure Plus' ) ) );
check( 'two names starting at the same place: the longer one', 'b' === Medications::key_for( 'Test Cure Plus 5 g', $two ) && 'a' === Medications::key_for( 'Test Cure 5 g', $two ) && 'a' === Medications::key_for( 'Test Cure, then Test Cure Plus', $two ) );
check( 'label() and get()', 'Metronidazole' === Medications::label( 'metro', $seed ) && '' === Medications::label( 'x', $seed ) && '' === Medications::label( '', $seed ) && null === Medications::get( 'x', $seed ) && 'Salt' === Medications::get( 'salt', $seed )['label'] );
check( 'names(): the name, the chip and the other names, lower-case, once each', array( 'copper power', 'copper', 'cu' ) === Medications::names( $seed['copper'] ) && array( 'salt' ) === Medications::names( $seed['salt'] ) && array( 'cu' ) === $seed['copper']['aliases'] && array( 'cp', 'cq' ) === $seed['chloroquine']['aliases'] );

echo "3. Reading what is stored (lenient)\n";
$row = Medications::normalize_row( array( 'key' => ' Kana-1 ', 'label' => "  Test   <b>Kana</b> ", 'unit' => 'g', 'mode' => 'per_gallon', 'rate' => '0.05', 'aliases' => 'KANA, test kana ; k ; 12 ; kana' ) );
check( 'typed and cleaned: key, name without tags, chip from the name, per 1, other names lower-case once each (a single letter, a number, the name itself dropped)', 'kana-1' === $row['key'] && 'Test Kana' === $row['label'] && 'Test Kana' === $row['short'] && 0.05 === $row['rate'] && 1.0 === $row['per'] && array( 'kana' ) === $row['aliases'] && false === $row['retired'] && '' === $row['level_unit'] && null === $row['measure'], json_encode( $row ) );
check( 'no key or no name: dropped', null === Medications::normalize_row( array( 'label' => 'X' ) ) && null === Medications::normalize_row( array( 'key' => 'x', 'label' => '  ' ) ) && null === Medications::normalize_row( array( 'key' => array( 'x' ), 'label' => 'X' ) ) );
$row = Medications::normalize_row( array( 'key' => 'x', 'label' => 'X Cure', 'unit' => 'cups', 'mode' => 'per_gallon', 'rate' => 'abc', 'doses' => array( array( 'label' => 'Big', 'rate' => 3 ) ) ) );
check( 'an unknown unit reads as g; a rate that is no number above 0 makes it typed (a sum is never guessed) with no named doses', 'g' === $row['unit'] && 'typed' === $row['mode'] && 0.0 === $row['rate'] && array() === $row['doses'] );
check( '…as do a rate of 0, a negative one and one beyond the cap', 'typed' === Medications::normalize_row( array( 'key' => 'x', 'label' => 'X', 'mode' => 'per_gallon', 'rate' => 0 ) )['mode'] && 'typed' === Medications::normalize_row( array( 'key' => 'x', 'label' => 'X', 'mode' => 'to_level', 'rate' => -1 ) )['mode'] && 'typed' === Medications::normalize_row( array( 'key' => 'x', 'label' => 'X', 'mode' => 'per_gallon', 'rate' => Medications::MAX_RATE + 1 ) )['mode'] );
$row = Medications::normalize_row( array( 'key' => 'x', 'label' => 'X', 'unit' => 'ml', 'mode' => 'to_level', 'rate' => 0.4, 'per' => 'x', 'level_unit' => '', 'doses' => array( array( 'label' => 'Big', 'rate' => 3 ) ) ) );
check( 'to a level: the level\'s unit defaults to ppm, a bad "per" to 1, named doses are a per-gallon thing', 'ppm' === $row['level_unit'] && 1.0 === $row['per'] && array() === $row['doses'] && 'to_level' === $row['mode'] );
$row = Medications::normalize_row( array( 'key' => 'x', 'label' => 'X', 'unit' => 'mg', 'mode' => 'per_gallon', 'rate' => 5, 'doses' => array( array( 'label' => 'Big', 'rate' => 30 ), array( 'label' => 'big', 'rate' => 31 ), array( 'label' => '', 'rate' => 3 ), array( 'label' => 'None', 'rate' => 0 ), 'junk', array( 'label' => 'Small', 'rate' => '2.5' ) ), 'measure' => array( 'label' => 'scoop', 'amount' => '0.5', 'unit' => 'g' ) ) );
check( 'named doses: a name and a rate above 0, each name once; a measure in a unit of the same kind', array( array( 'label' => 'Big', 'rate' => 30.0 ), array( 'label' => 'Small', 'rate' => 2.5 ) ) === $row['doses'] && array( 'label' => 'scoop', 'amount' => 0.5, 'unit' => 'g' ) === $row['measure'], json_encode( $row['doses'] ) );
check( 'a measure in a unit that does not convert, or without an amount, is dropped; without a unit it is the medication\'s', null === Medications::normalize_row( array( 'key' => 'x', 'label' => 'X', 'unit' => 'mg', 'mode' => 'typed', 'measure' => array( 'label' => 'cap', 'amount' => 5, 'unit' => 'ml' ) ) )['measure'] && null === Medications::normalize_row( array( 'key' => 'x', 'label' => 'X', 'measure' => array( 'label' => 'cap', 'amount' => 0 ) ) )['measure'] && 'ml' === Medications::normalize_row( array( 'key' => 'x', 'label' => 'X', 'unit' => 'ml', 'measure' => array( 'label' => 'cap', 'amount' => 5 ) ) )['measure']['unit'] );
$many = array();
for ( $i = 1; $i <= 50; $i++ ) {
	$many[] = array( 'key' => 'm' . $i, 'label' => 'Med ' . $i );
}
check( 'a map by key, a second row with a key already seen, junk rows; at most 40', array( 'a', 'b' ) === array_keys( Medications::normalize( array( 'a' => array( 'label' => 'A Cure' ), 'b' => array( 'key' => 'b', 'label' => 'B Cure' ), 'c' => 'junk', array( 'key' => 'a', 'label' => 'Again' ), array( 'label' => 'No key' ) ) ) ) && Medications::MAX === count( Medications::normalize( $many ) ) && 40 === Medications::MAX && array() === Medications::normalize( null ) && array() === Medications::normalize( 'x' ) );
check( 'clean_aliases(): a list or typed text, at most 12', array( 'aa', 'bb' ) === Medications::clean_aliases( array( 'AA', ' bb ', 'aa', array( 'x' ), '' ) ) && array( 'aa', 'bb' ) === Medications::clean_aliases( "aa\nbb; aa" ) && 12 === count( Medications::clean_aliases( implode( ',', array_map( static function ( $i ) { return 'name' . chr( 96 + $i ); }, range( 1, 20 ) ) ) ) ) );

echo "4. Settings → Medications: add\n";
$form = array( 'label' => 'Test Kanamycin', 'short' => 'Kana', 'aliases' => 'kanaplex, kan', 'unit' => 'mg', 'mode' => 'per_gallon', 'rate' => '30', 'per' => '', 'doses' => array( array( 'label' => 'Double', 'rate' => '60' ), array( 'label' => '', 'rate' => '' ) ), 'measure' => array( 'label' => 'scoop', 'amount' => '0.25', 'unit' => 'g' ) );
$r    = Medications::apply_op( $seed, 'add', $form );
check( 'a new medication is added last, under a key made from its name', empty( $r['error'] ) && 'test-kanamycin' === $r['key'] && 8 === count( $r['list'] ) && 'test-kanamycin' === array_key_last( $r['list'] ) && '"Test Kanamycin" added.' === $r['message'], json_encode( $r ) );
$kana = $r['list']['test-kanamycin'];
check( '…with everything typed: chip, other names, 30 mg per gallon, a named dose (the empty form row skipped), a measure', 'Kana' === $kana['short'] && array( 'kanaplex', 'kan' ) === $kana['aliases'] && array( 30.0, 'mg', 1.0, 'per_gallon' ) === array( $kana['rate'], $kana['unit'], $kana['per'], $kana['mode'] ) && array( array( 'label' => 'Double', 'rate' => 60.0 ) ) === $kana['doses'] && array( 'label' => 'scoop', 'amount' => 0.25, 'unit' => 'g' ) === $kana['measure'] && false === $kana['retired'] );
check( 'what was added survives the stored round trip unchanged', $r['list'] === Medications::normalize( $r['list'] ) );
check( 'the seven seeded ones are untouched', $seed === array_slice( $r['list'], 0, 7, true ) );
$with = $r['list'];
$r    = Medications::apply_op( $with, 'add', array_merge( $form, array( 'label' => 'Test Kanamycin 2', 'short' => 'Kana 2', 'aliases' => '' ) ) );
check( 'a second one with a similar name gets its own key', empty( $r['error'] ) && 'test-kanamycin-2' === $r['key'] );
check( 'new_key(): a slug, never taken, never digits only', 'furan-2' === Medications::new_key( 'Furan-2', $seed ) && 'm-21' === Medications::new_key( '21', $seed ) && 'copper-2' === Medications::new_key( 'Copper', $seed ) && 'medication' === Medications::new_key( '!!!', $seed ) && strlen( Medications::new_key( str_repeat( 'long name ', 10 ), $seed ) ) <= Medications::MAX_KEY );
$errors = array(
	'no name'                    => array( array( 'label' => '' ), 'Name the medication.' ),
	'a name without a letter'    => array( array( 'label' => '123' ), 'The name needs a letter in it.' ),
	'a name too long'            => array( array( 'label' => str_repeat( 'x', 41 ) ), 'The name is longer than 40 characters.' ),
	'a chip too long'            => array( array( 'short' => str_repeat( 'x', 15 ) ), 'The chip (short name) is longer than 14 characters.' ),
	'no unit'                    => array( array( 'unit' => 'cups' ), 'Pick a unit: mg, g, ml, tsp, drops or each.' ),
	'no way of dosing'           => array( array( 'mode' => 'magic' ), 'Pick how it is dosed: per gallon, to a level, or typed.' ),
	'per gallon without a rate'  => array( array( 'rate' => '' ), 'Type the amount per gallon (above 0).' ),
	'a rate of 0'                => array( array( 'rate' => '0' ), 'Type the amount per gallon (above 0).' ),
	'a negative rate'            => array( array( 'rate' => '-5' ), 'Type the amount per gallon (above 0).' ),
	'to a level without a rate'  => array( array( 'mode' => 'to_level', 'rate' => 'x' ), 'Type the amount that raises the level by 1.0 (above 0).' ),
	'gallons of 0'               => array( array( 'per' => '0' ), 'The gallons the amount is for must be above 0 and at most 10000.' ),
	'a named dose without rate'  => array( array( 'doses' => array( array( 'label' => 'Half', 'rate' => '' ) ) ), 'A named dose needs a name and an amount above 0.' ),
	'a named dose without name'  => array( array( 'doses' => array( array( 'label' => '', 'rate' => '5' ) ) ), 'A named dose needs a name and an amount above 0.' ),
	'two named doses, one name'  => array( array( 'doses' => array( array( 'label' => 'Half', 'rate' => '5' ), array( 'label' => 'half', 'rate' => '6' ) ) ), 'Two named doses are called "half".' ),
	'seven named doses'          => array( array( 'doses' => array_map( static function ( $i ) { return array( 'label' => 'D' . $i, 'rate' => $i ); }, range( 1, 7 ) ) ), 'At most 6 named doses.' ),
	'a measure without amount'   => array( array( 'measure' => array( 'label' => 'scoop', 'amount' => '' ) ), 'A measure needs a name and an amount above 0 — "one scoop = 0.5 g".' ),
	'a measure in another unit'  => array( array( 'measure' => array( 'label' => 'cap', 'amount' => '5', 'unit' => 'ml' ) ), 'The measure has to be in the medication\'s kind of unit (mg; mg and g go together).' ),
	'a level unit too long'      => array( array( 'mode' => 'to_level', 'level_unit' => 'parts per million' ), 'The level\'s unit is longer than 8 characters.' ),
	'thirteen other names'       => array( array( 'aliases' => implode( ',', array_map( static function ( $i ) { return 'name' . chr( 96 + $i ); }, range( 1, 13 ) ) ) ), 'At most 12 other names.' ),
);
$wrong = array();
foreach ( $errors as $what => $pair ) {
	$r = Medications::apply_op( $seed, 'add', array_merge( $form, array( 'label' => 'Test Other', 'short' => 'Other', 'aliases' => '' ), $pair[0] ) );
	if ( ! isset( $r['error'] ) || false === strpos( $r['error'], $pair[1] ) || isset( $r['list'] ) ) {
		$wrong[] = $what . ' → ' . ( $r['error'] ?? 'accepted' );
	}
}
check( 'every refusal says what is wrong and stores nothing (' . count( $errors ) . ' of them)', array() === $wrong, implode( ' | ', $wrong ) );
$r = Medications::apply_op( $seed, 'add', array_merge( $form, array( 'label' => '', 'unit' => 'cups', 'rate' => '0' ) ) );
check( 'several problems are all named at once', isset( $r['error'] ) && false !== strpos( $r['error'], 'Name the medication.' ) && false !== strpos( $r['error'], 'Pick a unit' ) && false !== strpos( $r['error'], 'Type the amount per gallon' ) );
$r = Medications::apply_op( $seed, 'add', array_merge( $form, array( 'label' => 'Metro', 'short' => '', 'aliases' => '' ) ) );
check( 'a name another medication answers to is refused, naming it', '"metro" is already a name of Metronidazole.' === ( $r['error'] ?? '' ), json_encode( $r ) );
$r = Medications::apply_op( $seed, 'add', array_merge( $form, array( 'label' => 'Test Other', 'short' => 'Other', 'aliases' => 'CU, something' ) ) );
check( '…an other name too', '"cu" is already a name of Copper Power.' === ( $r['error'] ?? '' ) );
$full = Medications::normalize( $many );
check( 'the forty-first is refused', 'At most 40 medications. Retired ones count too.' === ( Medications::apply_op( $full, 'add', $form )['error'] ?? '' ) );
$r = Medications::apply_op( $seed, 'add', array( 'label' => 'Test Dip', 'unit' => 'ml', 'mode' => 'typed', 'rate' => 'ignored', 'doses' => array( array( 'label' => 'x', 'rate' => 1 ) ) ) );
check( 'a typed one needs no rate; the chip is the name; named doses posted with it are left out', empty( $r['error'] ) && 'typed' === $r['list']['test-dip']['mode'] && 0.0 === $r['list']['test-dip']['rate'] && 'Test Dip' === $r['list']['test-dip']['short'] && array() === $r['list']['test-dip']['doses'] );
$r = Medications::apply_op( $seed, 'add', array( 'label' => 'Test Ramp', 'unit' => 'ml', 'mode' => 'to_level', 'rate' => '2', 'per' => '5', 'level_unit' => '' ) );
check( 'to a level: the level\'s unit left empty is ppm; it is not the copper product', empty( $r['error'] ) && 'ppm' === $r['list']['test-ramp']['level_unit'] && 5.0 === $r['list']['test-ramp']['per'] && ! Medications::is_copper( $r['list']['test-ramp'] ) );

echo "5. Settings → Medications: edit\n";
$r = Medications::apply_op( $seed, 'save', array( 'key' => 'metro', 'label' => 'Metro Powder', 'short' => 'MP', 'aliases' => 'flagyl', 'unit' => 'mg', 'mode' => 'per_gallon', 'rate' => '30', 'per' => '1' ) );
check( 'saved in place under the same key', empty( $r['error'] ) && 'metro' === $r['key'] && array_keys( $seed ) === array_keys( $r['list'] ) && 'Metro Powder' === $r['list']['metro']['label'] && 30.0 === $r['list']['metro']['rate'] && '"Metro Powder" saved.' === $r['message'] );
check( 'the old name and the old chip stay as other names', array( 'flagyl', 'metronidazole', 'metro' ) === $r['list']['metro']['aliases'], json_encode( $r['list']['metro']['aliases'] ) );
$renamed = $r['list'];
check( 'so a log written under the old name still reads as this medication', 'metro' === Medications::key_for( 'Metronidazole 5.1 g', $renamed ) && 'metro' === Medications::key_for( 'Dosed Metro', $renamed ) && 'metro' === Medications::key_for( 'Metro Powder 2.5 g · 30 mg per gallon, 100 gal', $renamed ) && 'Metro Powder' === Medications::label( 'metro', $renamed ) );
$r = Medications::apply_op( $renamed, 'save', array( 'key' => 'metro', 'label' => 'Metro Powder', 'short' => 'MP', 'aliases' => 'flagyl', 'unit' => 'mg', 'mode' => 'per_gallon', 'rate' => '30' ) );
check( 'an other name taken out on purpose stays out (only a name that changes in this save is kept)', empty( $r['error'] ) && array( 'flagyl' ) === $r['list']['metro']['aliases'] );
$r = Medications::apply_op( $seed, 'save', array( 'key' => 'copper', 'label' => 'Test Copper Two', 'short' => 'Copper', 'aliases' => 'cu', 'unit' => 'ml', 'mode' => 'to_level', 'rate' => '0.4', 'per' => '1', 'level_unit' => 'ppm' ) );
check( 'another copper product is the copper row renamed and re-rated: still the copper log\'s', empty( $r['error'] ) && Medications::is_copper( $r['list']['copper'] ) && 0.4 === $r['list']['copper']['rate'] && 'copper' === Medications::key_for( 'Copper Power 55 ml', $r['list'] ) && in_array( 'copper power', $r['list']['copper']['aliases'], true ) );
$r = Medications::apply_op( $seed, 'save', array( 'key' => 'copper', 'label' => 'Copper Power', 'short' => 'Copper', 'aliases' => 'cu', 'unit' => 'ml', 'mode' => 'typed' ) );
check( 'the copper row made typed is no copper product any more', empty( $r['error'] ) && ! Medications::is_copper( $r['list']['copper'] ) && null === Medications::copper( $r['list'] ) );
check( 'a name of another medication is refused on an edit too; its own names are fine', '"prazi" is already a name of Praziquantel.' === ( Medications::apply_op( $seed, 'save', array( 'key' => 'metro', 'label' => 'Metronidazole', 'short' => 'Prazi', 'unit' => 'mg', 'mode' => 'per_gallon', 'rate' => '25' ) )['error'] ?? '' ) && empty( Medications::apply_op( $seed, 'save', array( 'key' => 'metro', 'label' => 'Metronidazole', 'short' => 'Metro', 'aliases' => 'metro, metronidazole', 'unit' => 'mg', 'mode' => 'per_gallon', 'rate' => '25' ) )['error'] ) );
check( 'an unknown key, or none: refused', 'That medication no longer exists — reload the page.' === ( Medications::apply_op( $seed, 'save', array( 'key' => 'gone', 'label' => 'X' ) )['error'] ?? '' ) && isset( Medications::apply_op( $seed, 'save', array( 'label' => 'X' ) )['error'] ) );
$twelve = $seed;
$twelve['metro']['aliases'] = array_map( static function ( $i ) { return 'name' . chr( 96 + $i ); }, range( 1, 12 ) );
$r = Medications::apply_op( $twelve, 'save', array( 'key' => 'metro', 'label' => 'Renamed Powder', 'short' => 'Metro', 'aliases' => implode( ',', $twelve['metro']['aliases'] ), 'unit' => 'mg', 'mode' => 'per_gallon', 'rate' => '25' ) );
check( 'a rename with twelve other names already: refused rather than losing the old name', isset( $r['error'] ) && false !== strpos( $r['error'], 'The old name "metronidazole" has to stay as an other name' ) );
check( 'an unknown operation', 'Unknown medication action.' === ( Medications::apply_op( $seed, 'delete', array( 'key' => 'metro' ) )['error'] ?? '' ) );

echo "6. Retire and bring back\n";
$r = Medications::apply_op( $with, 'retire', array( 'key' => 'test-kanamycin' ) );
check( 'retired: still in the list, no longer offered', empty( $r['error'] ) && true === $r['list']['test-kanamycin']['retired'] && 8 === count( $r['list'] ) && ! isset( Medications::active( $r['list'] )['test-kanamycin'] ) && 7 === count( Medications::active( $r['list'] ) ) && false !== strpos( $r['message'], 'retired' ) );
$retired = $r['list'];
check( 'a log that names it still reads, with its name', 'test-kanamycin' === Medications::key_for( 'Test Kanamycin 3 g · 30 mg per gallon, 100 gal', $retired ) && 'Test Kanamycin' === Medications::label( 'test-kanamycin', $retired ) );
$r = Medications::apply_op( $retired, 'save', array_merge( $form, array( 'key' => 'test-kanamycin', 'rate' => '35' ) ) );
check( 'editing a retired one keeps it retired', empty( $r['error'] ) && true === $r['list']['test-kanamycin']['retired'] && 35.0 === $r['list']['test-kanamycin']['rate'] );
check( 'its names stay taken', isset( Medications::apply_op( $retired, 'add', array_merge( $form, array( 'label' => 'Kana', 'short' => '', 'aliases' => '' ) ) )['error'] ) );
$r = Medications::apply_op( $retired, 'restore', array( 'key' => 'test-kanamycin' ) );
check( 'brought back: offered again', empty( $r['error'] ) && false === $r['list']['test-kanamycin']['retired'] && isset( Medications::active( $r['list'] )['test-kanamycin'] ) );
$r = Medications::apply_op( $seed, 'retire', array( 'key' => 'copper' ) );
check( 'a retired copper row is no copper product; an unknown key cannot be retired', empty( $r['error'] ) && null === Medications::copper( $r['list'] ) && isset( Medications::apply_op( $seed, 'retire', array( 'key' => 'gone' ) )['error'] ) );

echo "7. Where the list is kept\n";
$GLOBALS['fh_options'] = array();
update_option( Clean_Room::OPTION_KEY, array( 'sale_ready_days' => 35, 'copper_floor' => 2.1, 'copper_target' => 2.4, 'voice_enabled' => true, 'size_list' => array( 'Small' ) ) );
$stored = Settings::save_medications( $retired );
check( 'saved into the settings option under "medications"; the other settings are as they were', $retired === $stored && $retired === Settings::get()['medications'] && 35 === Settings::get()['sale_ready_days'] && 2.4 === Settings::get()['copper_target'] && array( 'Small' ) === Settings::get()['size_list'] && array( 'fishotel_cleanroom_settings' ) === array_keys( $GLOBALS['fh_options'] ) );
check( 'the list in use is the stored one, everywhere', $retired === Medications::current() && $retired === Settings::medications() && $retired === Medications::from_settings( Settings::get() ) && 'Test Kanamycin' === Protocol::medication_label( 'test-kanamycin' ) );
$dropped = array();
$numbers = Settings::with_sizes( Settings::sanitize( array( 'sale_ready_days' => '30', 'copper_floor' => '2', 'copper_target' => '2.4', 'auto_confirm_seconds' => '3' ), $dropped ), Settings::get() );
check( 'the Numbers form posts no medications: its save keeps the list (and the sizes)', $retired === $numbers['medications'] && array( 'Small' ) === $numbers['size_list'] && 30 === $numbers['sale_ready_days'] && null === Settings::sanitize( array(), $dropped )['medications'] );
$GLOBALS['fh_user_caps']    = array( Clean_Room::SETTINGS_CAPABILITY => array( 1 ) );
$GLOBALS['fh_current_user'] = 1;
$r                          = Settings::sizes_request( new Fake_Store(), array( '_wpnonce' => wp_create_nonce( Settings::NONCE_ACTION ), 'size_list' => "Small\nLarge" ) );
check( 'Settings → Sizes saves its two keys: the list is still there', 200 === $r['status'] && $retired === Settings::get()['medications'] && array( 'Small', 'Large' ) === Settings::get()['size_list'] );
$edited                         = Settings::get();
$edited['medications']['metro'] = array_merge( $edited['medications']['metro'], array( 'label' => 'Metro Powder' ) );
update_option( Clean_Room::OPTION_KEY, $edited );
check( 'the list in use follows the option within one request (no stale copy)', 'Metro Powder' === Medications::current()['metro']['label'] && 'Metro Powder' === Protocol::medication_label( 'metro' ) );
update_option( Clean_Room::OPTION_KEY, array( 'medications' => 'garbage' ) );
check( 'something stored that is no list: the seed', $seed === Medications::current() && null === Settings::get()['medications'] );
update_option( Clean_Room::OPTION_KEY, array( 'medications' => array( array( 'nothing' => 'useful' ) ) ) );
check( 'a list with no usable row: the seed', $seed === Medications::current() && $seed === Medications::from_settings( Settings::get() ) );
check( 'saving an empty list stores "never edited"', $seed === Settings::save_medications( array() ) && null === Settings::get()['medications'] );
$GLOBALS['fh_options'] = array();
check( 'nothing stored again: the seed', $seed === Medications::current() );

echo "8. The protocol, the to-do and the last dose follow an edited list\n";
Settings::save_medications( $renamed );
check( 'Protocol::medication_key() / medication_label() / dose_text() answer from the list in use', 'metro' === Protocol::medication_key( 'Metronidazole 5.1 g' ) && 'metro' === Protocol::medication_key( 'Metro Powder 2 g' ) && 'Metro Powder' === Protocol::medication_label( 'metro' ) && 'Metro Powder 2.5 g' === Protocol::dose_text( 'metro', 2.5, 'g' ) && 'Kanamycin 2 g' === Protocol::dose_text( 'Kanamycin', 2, 'g' ) );
check( 'Protocol::dose_suggestion() uses the edited rate (30 mg per gallon → 3 g on 100)', array( 'amount' => 3.0, 'unit' => 'g' ) === Protocol::dose_suggestion( 'metro', 100 ) );
$stays = array( array( 'id' => 8, 'species' => 'Test Clown', 'qty_live' => 2, 'checkin_date' => '2026-03-27', 'day' => 8 ) );
$todo  = Protocol::todo( Protocol::seed(), $stays, '2026-04-03', 'spa', array( array( 'kind' => 'dose', 'value' => 2.5, 'note' => 'Metro Powder 2.5 g · 30 mg per gallon, 100 gal' ) ) );
$done  = array();
foreach ( $todo as $item ) {
	$done[ $item['label'] ] = $item['done'];
}
check( 'a dose logged under the NEW name ticks the schedule step that still says "Metronidazole"', true === $done['Metronidazole'] && false === $done['Praziquantel 1'], json_encode( $done ) );
$store = new Fake_Store();
$tank  = $store->tank_by_number( 521 );
$store->log_dose( (int) $tank['building_id'], 0, 'metro', 5.1, 'g', '', 8, 'qr' );
$store->logs[ count( $store->logs ) ]['note'] = 'Metronidazole 5.1 g'; // As it was written before the rename.
$doses = $store->latest_doses();
check( 'the store\'s "last dose" still finds an old log under the old name, and shows the new one', isset( $doses[ (int) $tank['building_id'] ]['metro'] ) && 'Metro Powder' === $doses[ (int) $tank['building_id'] ]['metro']['label'] && 'Metronidazole 5.1 g' === $doses[ (int) $tank['building_id'] ]['metro']['note'] );
Settings::save_medications( Medications::apply_op( $renamed, 'retire', array( 'key' => 'metro' ) )['list'] );
check( 'retired: old logs still read; no suggestion is made for it any more', 'metro' === Protocol::medication_key( 'Metronidazole 5.1 g' ) && 'Metro Powder' === Protocol::medication_label( 'metro' ) && null === Protocol::dose_suggestion( 'metro', 100 ) && isset( $store->latest_doses()[ (int) $tank['building_id'] ]['metro'] ) );
$GLOBALS['fh_options'] = array();

echo "9. The store's public calculator's copper figure beside ours\n";
$theme = array(
	'medications' => array(
		array( 'med_id' => 'test_antibiotic', 'name_generic' => 'Test Antibiotic', 'dose_pure_low_mg_per_gal' => 20 ),
		array( 'med_id' => 'copper_sulfate', 'name_generic' => 'Copper sulfate (test)', 'dispensing_formula' => array( 'type' => 'powder_mg_per_gal', 'mg_per_gal_per_ppm' => 14.9 ) ),
		array( 'med_id' => 'copper_power', 'name_generic' => 'Copper Power (ionic/chelated copper)', 'dispensing_formula' => array( 'type' => 'liquid_ml_per_gal', 'ml_per_gal_per_ppm' => 0.692 ), 'brand_equivalents' => array( array( 'brand_name' => 'Copper Power Blue (Saltwater)' ) ) ),
		array( 'med_id' => 'test_copper_two', 'name_generic' => 'Test Copper Two (maker)', 'dispensing_formula' => array( 'type' => 'liquid_ml_per_gal', 'ml_per_gal_per_ppm' => 0.4 ), 'brand_equivalents' => array( array( 'brand_name' => 'Brand Two Liquid' ) ) ),
	),
);
$f = Medications::theme_figure( $seed['copper'], $theme );
check( 'present: 0.692 ml per gallon per 1.0 ppm for "Copper Power (…)", the same as ours', is_array( $f ) && 0.692 === $f['rate'] && true === $f['same'] && 'Copper Power (ionic/chelated copper)' === $f['name'], json_encode( $f ) );
$f = Medications::theme_figure( array_merge( $seed['copper'], array( 'rate' => 0.7 ) ), $theme );
check( 'ours edited to 0.7: the theme\'s figure, and that the two differ', 0.692 === $f['rate'] && false === $f['same'] );
$f = Medications::theme_figure( array_merge( $seed['copper'], array( 'rate' => 6.92, 'per' => 10.0 ) ), $theme );
check( 'ours typed for 10 gallons (6.92 ml per 10 gal): the same figure', true === $f['same'] );
check( 'absent: no theme data, an empty list, something that is no list', null === Medications::theme_figure( $seed['copper'], null ) && null === Medications::theme_figure( $seed['copper'], array() ) && null === Medications::theme_figure( $seed['copper'], array( 'medications' => 'x' ) ) && null === Medications::theme_figure( $seed['copper'], 'x' ) );
check( 'the theme has no entry of that name, or one without the figure: none', null === Medications::theme_figure( array_merge( $seed['copper'], array( 'label' => 'Unlisted Copper' ) ), $theme ) && null === Medications::theme_figure( array_merge( $seed['copper'], array( 'label' => 'Copper sulfate' ) ), $theme ) && null === Medications::theme_figure( array_merge( $seed['copper'], array( 'label' => 'Copper' ) ), array( 'medications' => array( array( 'med_id' => 'copperband', 'name_generic' => 'Copperband', 'dispensing_formula' => array( 'ml_per_gal_per_ppm' => 1 ) ) ) ) ) );
$f = Medications::theme_figure( array_merge( $seed['copper'], array( 'label' => 'Test Copper Two', 'rate' => 0.4 ) ), $theme );
check( 'the copper row renamed to another product: that product\'s figure (by its id, its name or a brand name)', 0.4 === $f['rate'] && true === $f['same'] && 0.4 === Medications::theme_figure( array_merge( $seed['copper'], array( 'label' => 'Brand Two Liquid' ) ), $theme )['rate'] );
$f = Medications::theme_figure( array_merge( $seed['copper'], array( 'unit' => 'drops' ) ), $theme );
check( 'ours in another unit: the figure is shown, not compared', 0.692 === $f['rate'] && null === $f['same'] && null === Medications::theme_figure( array_merge( $seed['copper'], array( 'level_unit' => 'mg/L' ) ), $theme )['same'] );
check( 'only a medication dosed to a level has such a figure', null === Medications::theme_figure( $seed['metro'], $theme ) && null === Medications::theme_figure( array_merge( $seed['copper'], array( 'mode' => 'typed' ) ), $theme ) );
check( 'junk inside the data is stepped over', 0.692 === Medications::theme_figure( $seed['copper'], array( 'medications' => array( 'junk', array( 'med_id' => array(), 'dispensing_formula' => 'x' ), array( 'med_id' => 'copper_power', 'dispensing_formula' => array( 'ml_per_gal_per_ppm' => 'abc' ) ), array( 'med_id' => 'copper_power', 'dispensing_formula' => array( 'ml_per_gal_per_ppm' => '0.692' ) ) ) ) )['rate'] );
check( 'theme_data(): nothing answers here, so it is not available', null === Medications::theme_data() );
add_filter( 'fishotel_cleanroom_theme_medication_data', static function ( $data ) use ( $theme ) { return $theme; } );
check( '…through the filter it is', $theme === Medications::theme_data() && 0.692 === Medications::theme_figure( $seed['copper'], Medications::theme_data() )['rate'] );
$GLOBALS['fh_filters'] = array();
add_filter( 'fishotel_cleanroom_theme_medication_data', static function ( $data ) { throw new \RuntimeException( 'theme broke' ); } );
check( 'a filter that throws, or answers with something else: not available — never an error', null === Medications::theme_data() );
$GLOBALS['fh_filters'] = array();
/**
 * The theme's class as the live site has it: a static get_all().
 */
class FisHotel_Medication_Data { // phpcs:ignore
	public static $data = array();
	public static function get_all() {
		return self::$data;
	}
}
FisHotel_Medication_Data::$data = $theme;
check( 'the theme\'s own class, when it is loaded, is read', $theme === Medications::theme_data() );
FisHotel_Medication_Data::$data = array( 'medications' => array() );
check( '…and an empty answer from it is "not available"', null === Medications::theme_data() );

fh_finish();
