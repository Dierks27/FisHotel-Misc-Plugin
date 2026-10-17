<?php
/**
 * Protocol — the editable treatment template: the seed, normalisation,
 * which steps are due on which day, where they apply, and the daily
 * to-do a tank gets from its stays (with tick-offs).
 *
 * Run from the plugin root:  php includes/sections/clean-room/tests/protocol-test.php
 */

require_once __DIR__ . '/wp-stubs.php';

use FisHotel\Misc\Sections\Clean_Room\Protocol;
use FisHotel\Misc\Sections\Clean_Room\Settings;

$seed = Protocol::seed();

echo "1. The seed (the workbook's 14 Day CM schedule, site day = sheet day + 1)\n";
check( 'eight steps (no "observation ends" chip: sale-ready is computed, a chip that changes nothing is a burden)', 8 === count( $seed ) && ! in_array( 'Observation ends', array_column( $seed, 'label' ), true ) );
check( 'normalising the seed changes nothing', $seed === Protocol::normalize( $seed ) );
check( 'feed is daily, anywhere, open-ended', 'feed' === $seed[0]['kind'] && 1 === $seed[0]['every'] && null === $seed[0]['until'] && 'any' === $seed[0]['where'] );
check( 'copper to 2.0 on the check-in day, to 2.4 the day after (sheet days 0 and 1), Spa only', 'Copper Power to 2.0' === $seed[1]['label'] && 1 === $seed[1]['day'] && 0 === $seed[1]['every'] && 'spa' === $seed[1]['where'] && 'Copper Power to 2.4' === $seed[2]['label'] && 2 === $seed[2]['day'] && 'spa' === $seed[2]['where'] );
check( 'copper test runs days 2–15 daily in the Spa (sheet days 1–14)', 'Copper test' === $seed[3]['label'] && 2 === $seed[3]['day'] && 1 === $seed[3]['every'] && 15 === $seed[3]['until'] && 'spa' === $seed[3]['where'] );
check( 'metronidazole: days 2–12 every other day = six treatments (sheet days 1, 3, 5, 7, 9, 11)', 'Metronidazole' === $seed[4]['label'] && 2 === $seed[4]['day'] && 2 === $seed[4]['every'] && 12 === $seed[4]['until'] && 'spa' === $seed[4]['where'] );
check( 'two praziquantel treatments on days 8 and 14 (sheet days 7 and 13), wherever the fish is', 'Praziquantel 1' === $seed[5]['label'] && 8 === $seed[5]['day'] && 'Praziquantel 2' === $seed[6]['label'] && 14 === $seed[6]['day'] && 'any' === $seed[5]['where'] && 'any' === $seed[6]['where'] );
check( 'the bath and the move to the Clean Room on day 16 (sheet day 15) end the schedule', 'Bath, then move to the Clean Room' === $seed[7]['label'] && 'water_change' === $seed[7]['kind'] && 16 === $seed[7]['day'] && 16 === max( array_column( $seed, 'day' ) ) );

echo "2. is_due()\n";
$feed   = $seed[0];
$copper = $seed[3];
$metro  = $seed[4];
$prazi1 = $seed[5];
check( 'feed on days 1, 2, 50', Protocol::is_due( $feed, 1 ) && Protocol::is_due( $feed, 2 ) && Protocol::is_due( $feed, 50 ) );
check( 'nothing on day 0', ! Protocol::is_due( $feed, 0 ) && ! Protocol::is_due( $metro, 0 ) );
check( 'copper test: not day 1, yes 2…15, not 16', ! Protocol::is_due( $copper, 1 ) && Protocol::is_due( $copper, 2 ) && Protocol::is_due( $copper, 9 ) && Protocol::is_due( $copper, 15 ) && ! Protocol::is_due( $copper, 16 ) );
$metro_days = array();
for ( $d = 1; $d <= 30; $d++ ) {
	if ( Protocol::is_due( $metro, $d ) ) {
		$metro_days[] = $d;
	}
}
check( 'metronidazole exactly on 2, 4, 6, 8, 10, 12', array( 2, 4, 6, 8, 10, 12 ) === $metro_days, implode( ',', $metro_days ) );
check( 'a one-off step is due on its day only', Protocol::is_due( $prazi1, 8 ) && ! Protocol::is_due( $prazi1, 9 ) && ! Protocol::is_due( $prazi1, 7 ) );
check( 'an open-ended repeat every 3 days from day 4', Protocol::is_due( Protocol::step( 'x', 'test', 4, 3 ), 10 ) && ! Protocol::is_due( Protocol::step( 'x', 'test', 4, 3 ), 11 ) );

echo "3. due() and where\n";
$due = Protocol::due( $seed, 1, 'spa' );
check( 'day 1 in the Spa: feed, copper to 2.0', array( 'Feed', 'Copper Power to 2.0' ) === array_column( $due, 'label' ), implode( ',', array_column( $due, 'label' ) ) );
$due = Protocol::due( $seed, 2, 'spa' );
check( 'day 2 in the Spa: feed, copper to 2.4, copper test, metronidazole', array( 'Feed', 'Copper Power to 2.4', 'Copper test', 'Metronidazole' ) === array_column( $due, 'label' ), implode( ',', array_column( $due, 'label' ) ) );
$due = Protocol::due( $seed, 1, 'clean' );
check( 'day 1 in the Clean Room: only feed (the Spa steps do not apply)', array( 'Feed' ) === array_column( $due, 'label' ) );
$due = Protocol::due( $seed, 14, 'spa' );
check( 'day 14 in the Spa: feed, copper test, praziquantel 2', array( 'Feed', 'Copper test', 'Praziquantel 2' ) === array_column( $due, 'label' ), implode( ',', array_column( $due, 'label' ) ) );
$due = Protocol::due( $seed, 16, 'spa' );
check( 'day 16 in the Spa: feed, the bath and the move', array( 'Feed', 'Bath, then move to the Clean Room' ) === array_column( $due, 'label' ) );
$due = Protocol::due( $seed, 8, 'clean' );
check( 'day 8 in the Clean Room (moved early): feed, praziquantel 1 — no copper', array( 'Feed', 'Praziquantel 1' ) === array_column( $due, 'label' ) );
$due = Protocol::due( $seed, 28, 'clean' );
check( 'day 28 in the Clean Room: only feed', array( 'Feed' ) === array_column( $due, 'label' ) );
check( 'no area → everything due: day 14 has feed, copper test, praziquantel 2; day 1 feed, copper 2.0; day 16 feed, bath', 3 === count( Protocol::due( $seed, 14, '' ) ) && 2 === count( Protocol::due( $seed, 1, '' ) ) && 2 === count( Protocol::due( $seed, 16, '' ) ) );
check( 'applies()', Protocol::applies( $copper, 'spa' ) && ! Protocol::applies( $copper, 'clean' ) && Protocol::applies( $feed, 'clean' ) );

echo "4. normalize() from a settings form\n";
$raw = array(
	array( 'label' => '  Copper test ', 'kind' => 'test', 'day' => '2', 'every' => '1', 'until' => '14', 'where' => 'spa', 'note' => 'hold 2.25+' ),
	array( 'label' => '', 'kind' => 'dose', 'day' => 3 ),
	array( 'label' => 'Once', 'kind' => 'bogus', 'day' => '0', 'every' => '0', 'until' => '20', 'where' => 'moon' ),
	array( 'label' => 'Late', 'kind' => 'other', 'day' => '500', 'every' => '99', 'until' => '2' ),
	'garbage',
);
$steps = Protocol::normalize( $raw );
check( 'empty labels and non-arrays dropped', 3 === count( $steps ) );
check( 'typed and trimmed', 'Copper test' === $steps[0]['label'] && 2 === $steps[0]['day'] && 1 === $steps[0]['every'] && 14 === $steps[0]['until'] && 'spa' === $steps[0]['where'] && 'hold 2.25+' === $steps[0]['note'] );
check( 'unknown kind → other, unknown where → any, day 0 → 1, until without a repeat → null', 'other' === $steps[1]['kind'] && 'any' === $steps[1]['where'] && 1 === $steps[1]['day'] && null === $steps[1]['until'] );
check( 'clamped: day 120, every 60, until never before day', 120 === $steps[2]['day'] && 60 === $steps[2]['every'] && 120 === $steps[2]['until'] );
check( 'long labels cut to 80', 80 === strlen( Protocol::normalize( array( array( 'label' => str_repeat( 'a', 100 ) ) ) )[0]['label'] ) );
check( 'at most 60 steps', 60 === count( Protocol::normalize( array_fill( 0, 70, array( 'label' => 'x' ) ) ) ) );
check( 'nothing → nothing', array() === Protocol::normalize( null ) && array() === Protocol::normalize( 'x' ) );

echo "5. The daily to-do of a tank\n";
$stays = array(
	array( 'id' => 7, 'species' => 'Pajama Cardinalfish', 'qty_live' => 4, 'checkin_date' => '2026-04-01', 'day' => 3 ),
	array( 'id' => 8, 'species' => 'Ocellaris Clownfish', 'qty_live' => 2, 'checkin_date' => '2026-03-27', 'day' => 8 ),
	array( 'id' => 9, 'species' => 'Dead group', 'qty_live' => 0, 'checkin_date' => '2026-04-01', 'day' => 3 ),
);
$todo = Protocol::todo( $seed, $stays, '2026-04-03', 'spa' );
$labels = array_map( static function ( $i ) { return $i['label'] . ( $i['stay_id'] ? ' #' . $i['stay_id'] : '' ); }, $todo );
check( 'feed once for the tank, then the due steps per live stay', array( 'Feed', 'Copper test #7', 'Copper test #8', 'Metronidazole #8', 'Praziquantel 1 #8' ) === $labels, implode( ', ', $labels ) );
check( 'items carry kind, species, day, note and a key', 'test' === $todo[1]['kind'] && 'Pajama Cardinalfish' === $todo[1]['species'] && 3 === $todo[1]['day'] && '' !== $todo[1]['note'] && 'copper test #7' === $todo[1]['key'] );
check( 'nothing is done yet', 0 === count( array_filter( array_column( $todo, 'done' ) ) ) );
$done = array( array( 'note' => 'feed' ), array( 'note' => 'Copper test #7' ) );
$todo = Protocol::todo( $seed, $stays, '2026-04-03', 'spa', $done );
check( 'today\'s tank logs tick items off by their key', true === $todo[0]['done'] && true === $todo[1]['done'] && false === $todo[2]['done'] && false === $todo[3]['done'] );
check( 'a tank with no live fish has no to-do, not even feeding', array() === Protocol::todo( $seed, array( $stays[2] ), '2026-04-03', 'spa' ) );
$todo = Protocol::todo( $seed, array( $stays[1] ), '2026-04-03', 'clean' );
check( 'in the Clean Room on day 8 (moved early): feed and praziquantel 1 (no copper, no metronidazole)', array( 'Feed', 'Praziquantel 1' ) === array_column( $todo, 'label' ) );
$todo = Protocol::todo( $seed, array( array( 'id' => 10, 'species' => 'X', 'qty_live' => 1, 'checkin_date' => '2026-04-01' ) ), '2026-04-04', 'spa' );
check( 'the day is computed from the check-in when missing (Apr 4 = day 4: feed, copper test, metronidazole)', array( 'Feed', 'Copper test', 'Metronidazole' ) === array_column( $todo, 'label' ) );
check( 'tick_key normalises case and spaces', 'copper test #7' === Protocol::tick_key( '  Copper   TEST #7 ' ) );
$todo = Protocol::todo( $seed, array( $stays[1] ), '2026-04-03', 'spa', array( array( 'kind' => 'copper', 'value' => 2.4, 'note' => '' ) ) );
check( 'a copper reading logged today ticks the copper test by itself ("521 copper 2.4" closes the chip)', true === $todo[1]['done'] && 'Copper test' === $todo[1]['label'] && false === $todo[0]['done'] && false === $todo[2]['done'] && 'Metronidazole' === $todo[2]['label'] );
$ph = array( Protocol::step( 'pH test', 'test', 1, 1, null, 'spa' ), Protocol::step( 'Cu check', 'test', 1, 1, null, 'spa' ), Protocol::step( 'Copper off', 'water_change', 1, 1, null, 'spa' ) );
$todo = Protocol::todo( $ph, array( $stays[0] ), '2026-04-03', 'spa', array( array( 'kind' => 'copper', 'value' => 2.4 ) ) );
check( 'only a test step that names copper / Cu is ticked by a reading; a pH test and a water change are not', false === $todo[0]['done'] && true === $todo[1]['done'] && false === $todo[2]['done'] );
check( 'is_copper_test', Protocol::is_copper_test( $copper ) && ! Protocol::is_copper_test( $seed[1] ) && ! Protocol::is_copper_test( Protocol::step( 'Cumin test', 'test', 1 ) ) );

echo "6. Copper state and describe()\n";
check( 'copper_state', 'ok' === Protocol::copper_state( 2.5, 2.25 ) && 'ok' === Protocol::copper_state( 2.25, 2.25 ) && 'low' === Protocol::copper_state( 2.1, 2.25 ) && 'none' === Protocol::copper_state( null, 2.25 ) );
check( 'describe a repeating step', 'Copper test · test · days 2–15, daily · Spa' === Protocol::describe( $copper ), Protocol::describe( $copper ) );
check( 'describe a one-off', 'Praziquantel 1 · dose · day 8 · anywhere' === Protocol::describe( $prazi1 ) );
check( 'describe an open-ended repeat', 'Feed · feed · days 1–…, daily · anywhere' === Protocol::describe( $feed ) );
check( 'describe every 2 days', 'Metronidazole · dose · days 2–12, every 2 days · Spa' === Protocol::describe( $metro ) );

echo "7. The five named templates (site day = sheet day + 1)\n";
$templates = Protocol::templates();
check( 'seeded in order: 14 Day CM, 14 Day CF, Xtra 14 Day CF, CQ, Trifecta', Protocol::TEMPLATE_NAMES === array_keys( $templates ) && '14 Day CM' === Protocol::DEFAULT_TEMPLATE );
check( '14 Day CM is the seed', $seed === $templates['14 Day CM'] );
$every_feeds = true;
foreach ( $templates as $name => $steps ) {
	if ( ! $steps || 'Feed' !== $steps[0]['label'] || 'feed' !== $steps[0]['kind'] || 1 !== $steps[0]['every'] || $steps !== Protocol::normalize( $steps ) ) {
		$every_feeds = false;
	}
}
check( 'every template starts with the daily feeding and survives normalize()', $every_feeds );

/**
 * The days a step labelled $label is due in a template, 1–40.
 */
function fh_due_days( array $steps, $label, $area = 'spa' ) {
	$days = array();
	for ( $d = 1; $d <= 40; $d++ ) {
		foreach ( Protocol::due( $steps, $d, $area ) as $step ) {
			if ( $step['label'] === $label ) {
				$days[] = $d;
			}
		}
	}
	return $days;
}
$cf = $templates['14 Day CF'];
check( '14 Day CF: copper 2.0 day 1, 2.4 day 2, the test daily 2–15', array( 1 ) === fh_due_days( $cf, 'Copper Power to 2.0' ) && array( 2 ) === fh_due_days( $cf, 'Copper Power to 2.4' ) && range( 2, 15 ) === fh_due_days( $cf, 'Copper test' ) );
check( '14 Day CF: formalin daily days 5–14 (sheet 4–13), praziquantel 7 / 12 / 14 (sheet 6 / 11 / 13), the move day 15 (sheet 14)', range( 5, 14 ) === fh_due_days( $cf, 'Formalin' ) && array( 7 ) === fh_due_days( $cf, 'Praziquantel 1', '' ) && array( 12 ) === fh_due_days( $cf, 'Praziquantel 2', '' ) && array( 14 ) === fh_due_days( $cf, 'Praziquantel 3', '' ) && array( 15 ) === fh_due_days( $cf, 'Move to the Clean Room' ) );
$xcf = $templates['Xtra 14 Day CF'];
check( 'Xtra 14 Day CF: formalin daily days 1–14 (sheet 0–13), praziquantel 6 / 11 / 13, copper as CF, the move day 15', range( 1, 14 ) === fh_due_days( $xcf, 'Formalin' ) && array( 6 ) === fh_due_days( $xcf, 'Praziquantel 1', '' ) && array( 11 ) === fh_due_days( $xcf, 'Praziquantel 2', '' ) && array( 13 ) === fh_due_days( $xcf, 'Praziquantel 3', '' ) && array( 1 ) === fh_due_days( $xcf, 'Copper Power to 2.0' ) && range( 2, 15 ) === fh_due_days( $xcf, 'Copper test' ) && array( 15 ) === fh_due_days( $xcf, 'Move to the Clean Room' ) );
$cq = $templates['CQ'];
check( 'CQ: chloroquine 40 mg/gal day 3 (sheet 2), 12 mg/gal days 6, 8, 10, 12 (sheet 5–11), 6 mg/gal day 14 (sheet 13)', array( 3 ) === fh_due_days( $cq, 'Chloroquine 40 mg/gal' ) && array( 6, 8, 10, 12 ) === fh_due_days( $cq, 'Chloroquine 12 mg/gal' ) && array( 14 ) === fh_due_days( $cq, 'Chloroquine 6 mg/gal' ) );
check( 'CQ: praziquantel 7 / 11 / 13 (sheet 6 / 10 / 12), copper as CF, the freshwater bath and move day 16 (sheet 15)', array( 7 ) === fh_due_days( $cq, 'Praziquantel 1', '' ) && array( 11 ) === fh_due_days( $cq, 'Praziquantel 2', '' ) && array( 13 ) === fh_due_days( $cq, 'Praziquantel 3', '' ) && array( 1 ) === fh_due_days( $cq, 'Copper Power to 2.0' ) && array( 16 ) === fh_due_days( $cq, 'Freshwater bath, then move to the Clean Room' ) && 'water_change' === end( $cq )['kind'] );
$tri = $templates['Trifecta'];
check( 'Trifecta: the combo day 1, Furan-2 on the even days to 14, the water change + combo on the odd days to 15, anywhere', array( 1 ) === fh_due_days( $tri, 'Metronidazole + Kanamycin + Furan-2', 'clean' ) && array( 2, 4, 6, 8, 10, 12, 14 ) === fh_due_days( $tri, 'Furan-2', 'clean' ) && array( 3, 5, 7, 9, 11, 13, 15 ) === fh_due_days( $tri, '25 % water change, then the combo', 'clean' ) && array( 'any', 'any', 'any', 'any' ) === array_column( $tri, 'where' ) );
check( 'seed_template() by name (trimmed), null for a custom name', $cq === Protocol::seed_template( ' CQ ' ) && null === Protocol::seed_template( 'Mine' ) );
check( 'template_name(): trimmed, single spaces, tags out, cut to 40', 'My Plan' === Protocol::template_name( "  My   <b>Plan</b> " ) && 40 === strlen( Protocol::template_name( str_repeat( 'x', 50 ) ) ) && '' === Protocol::template_name( array() ) );

echo "8. The option: migration and the current shape\n";
$opt = Protocol::normalize_option( null );
check( 'nothing stored → the five seeds, 14 Day CM the default', Protocol::TEMPLATE_NAMES === array_keys( $opt['templates'] ) && '14 Day CM' === $opt['default'] );
$old = $seed;
$old[4]['until'] = 10;
$opt = Protocol::normalize_option( $old );
check( 'an old bare steps array becomes the 14 Day CM template (the edit kept) next to the other four seeds', Protocol::TEMPLATE_NAMES === array_keys( $opt['templates'] ) && 10 === $opt['templates']['14 Day CM'][4]['until'] && '14 Day CM' === $opt['default'] && $templates['CQ'] === $opt['templates']['CQ'] );
check( 'an old empty list stays an empty 14 Day CM (no to-do), as it was', array() === Protocol::normalize_option( array() )['templates']['14 Day CM'] );
$opt = Protocol::normalize_option( array( 'default' => 'Mine', 'templates' => array( 'Mine' => array( array( 'label' => 'Feed', 'kind' => 'feed', 'day' => 1, 'every' => 1 ) ), '' => $seed, ' CQ ' => $cq ) ) );
check( 'the new shape: names cleaned, a nameless template dropped, steps normalised, the default honoured', array( 'Mine', 'CQ' ) === array_keys( $opt['templates'] ) && 'Mine' === $opt['default'] && 'Feed' === $opt['templates']['Mine'][0]['label'] && 'any' === $opt['templates']['Mine'][0]['where'] );
check( 'an unknown default falls back to 14 Day CM when present, else the first template', 'CQ' === Protocol::normalize_option( array( 'default' => 'Gone', 'templates' => array( 'CQ' => $cq, 'Mine' => $seed ) ) )['default'] && '14 Day CM' === Protocol::normalize_option( array( 'default' => 'Gone', 'templates' => array( 'CQ' => $cq, '14 Day CM' => $seed ) ) )['default'] );
check( 'an empty templates map → the seeds; at most 20 templates', Protocol::TEMPLATE_NAMES === array_keys( Protocol::normalize_option( array( 'default' => 'x', 'templates' => array() ) )['templates'] ) && 20 === count( Protocol::normalize_option( array( 'templates' => array_fill_keys( array_map( static function ( $i ) { return 't' . $i; }, range( 1, 25 ) ), $seed ) ) )['templates'] ) );

echo "9. steps_for(): the building's template, else the default\n";
$opt = Protocol::normalize_option( array( 'default' => '14 Day CM', 'templates' => array( '14 Day CM' => $seed, 'CQ' => $cq ) ) );
check( 'a building on CQ runs CQ', 'CQ' === Protocol::template_for( array( 'schedule' => 'CQ' ), $opt ) && $cq === Protocol::steps_for( array( 'schedule' => 'CQ' ), $opt ) );
check( 'no schedule, or one that no longer exists, runs the default', '14 Day CM' === Protocol::template_for( array( 'schedule' => '' ), $opt ) && $seed === Protocol::steps_for( array( 'schedule' => 'Trifecta' ), $opt ) && $seed === Protocol::steps_for( array(), $opt ) );
update_option( Protocol::OPTION_KEY, $opt, false );
check( 'without the option passed, Settings reads it', $cq === Protocol::steps_for( array( 'schedule' => 'CQ' ) ) && $seed === Settings::protocol() && $cq === Settings::steps_for( array( 'schedule' => 'CQ' ) ) );
delete_option( Protocol::OPTION_KEY );
check( 'Settings::protocols() with nothing stored is the seed set', Protocol::TEMPLATE_NAMES === array_keys( Settings::protocols()['templates'] ) );

echo "10. Medications: aliases, suggestions, the dose text\n";
check( 'medication_key by alias, whole words, any case', 'copper' === Protocol::medication_key( 'Copper Power to 2.0' ) && 'copper' === Protocol::medication_key( 'Dosed Copper' ) && 'copper' === Protocol::medication_key( 'cu 2.4' ) && 'metro' === Protocol::medication_key( 'Dosed Metro' ) && 'metro' === Protocol::medication_key( 'Metronidazole + Kanamycin + Furan-2' ) && 'prazi' === Protocol::medication_key( 'Praziquantel 2' ) && 'prazi' === Protocol::medication_key( 'prazi' ) && 'chloroquine' === Protocol::medication_key( 'Chloroquine 12 mg/gal' ) && 'chloroquine' === Protocol::medication_key( 'Dosed CP' ) && 'fenbendazole' === Protocol::medication_key( 'fenben 2 g' ) && 'salt' === Protocol::medication_key( 'Added Salt' ) && 'formalin' === Protocol::medication_key( 'FORMALIN 20 ml' ) );
check( 'no medication: null (Cumin is not Cu, Furan-2 has no key, blank)', null === Protocol::medication_key( 'Cumin test' ) && null === Protocol::medication_key( 'Furan-2' ) && null === Protocol::medication_key( '' ) && null === Protocol::medication_key( 'Feed' ) );
check( 'labels', 'Metronidazole' === Protocol::medication_label( 'metro' ) && 'Copper Power' === Protocol::medication_label( 'copper' ) && '' === Protocol::medication_label( 'x' ) );
check( 'dose_suggestion: metro 25 mg/gal → 5.1 g on 205, 2.5 g on 100', array( 'amount' => 5.1, 'unit' => 'g' ) === Protocol::dose_suggestion( 'metro', 205 ) && array( 'amount' => 2.5, 'unit' => 'g' ) === Protocol::dose_suggestion( 'metro', 100 ) );
check( 'prazi 9.5 mg/gal → 1.9 g on 205; formalin 1 ml / 10 gal → 20.5 ml; chloroquine 10 mg/gal → 1 g on 100; fenbendazole 1 g / 100 gal → 0.4 g on 40', 1.9 === Protocol::dose_suggestion( 'prazi', 205 )['amount'] && array( 'amount' => 20.5, 'unit' => 'ml' ) === Protocol::dose_suggestion( 'formalin', 205 ) && 1.0 === Protocol::dose_suggestion( 'chloroquine', 100 )['amount'] && 0.4 === Protocol::dose_suggestion( 'fenbendazole', 40 )['amount'] );
check( 'below one gram: two decimals (fenbendazole on 20 gal = 0.2, chloroquine on 20 = 0.2, prazi on 20 = 0.19)', 0.2 === Protocol::dose_suggestion( 'fenbendazole', 20 )['amount'] && 0.19 === Protocol::dose_suggestion( 'prazi', 20 )['amount'] );
check( 'no suggestion for copper (dosed to the test), salt, an unknown key or no volume', null === Protocol::dose_suggestion( 'copper', 205 ) && null === Protocol::dose_suggestion( 'salt', 205 ) && null === Protocol::dose_suggestion( 'x', 205 ) && null === Protocol::dose_suggestion( 'metro', 0 ) );
check( 'dose_text', 'Metronidazole 5.1 g' === Protocol::dose_text( 'metro', 5.1, 'g' ) && 'Formalin 20 ml' === Protocol::dose_text( 'formalin', 20.0, 'ml' ) && 'Copper Power 55 ml' === Protocol::dose_text( 'copper', 55, 'ml' ) && 'Kanamycin 2 g' === Protocol::dose_text( 'Kanamycin', 2, 'g' ) && 'Praziquantel 2.05' === Protocol::dose_text( 'prazi', 2.05, '' ) );

echo "11. A dose logged today ticks the dose step that names the medication\n";
$todo = Protocol::todo( $seed, array( $stays[1] ), '2026-04-03', 'spa', array( array( 'kind' => 'dose', 'value' => 5.1, 'note' => 'Metronidazole 5.1 g' ) ) );
$done = array();
foreach ( $todo as $item ) {
	$done[ $item['label'] ] = $item['done'];
}
check( 'day 8 in the Spa: a metro dose ticks Metronidazole, not the copper test nor Praziquantel 1', true === $done['Metronidazole'] && false === $done['Copper test'] && false === $done['Praziquantel 1'] && false === $done['Feed'], json_encode( $done ) );
$todo = Protocol::todo( $seed, array( $stays[1] ), '2026-04-03', 'spa', array( array( 'kind' => 'dose', 'value' => 2, 'note' => 'prazi 2 g · import:2026-1#4' ), array( 'kind' => 'note', 'note' => 'Metronidazole 5.1 g' ) ) );
$done = array();
foreach ( $todo as $item ) {
	$done[ $item['label'] ] = $item['done'];
}
check( 'the alias in a note with a marker ticks Praziquantel 1; a plain note naming metro ticks nothing', true === $done['Praziquantel 1'] && false === $done['Metronidazole'] );
$todo = Protocol::todo( $seed, array( $stays[0] ), '2026-04-03', 'spa', array( array( 'kind' => 'dose', 'note' => 'Copper Power 55 ml' ) ) );
check( 'a copper dose ticks a copper dose step (day 3: none due) but never the copper test', false === $todo[1]['done'] && 'Copper test' === $todo[1]['label'] );
$todo = Protocol::todo( $seed, array( array( 'id' => 11, 'species' => 'X', 'qty_live' => 2, 'checkin_date' => '2026-04-03', 'day' => 1 ) ), '2026-04-03', 'spa', array( array( 'kind' => 'dose', 'note' => 'Copper Power 55 ml' ) ) );
check( 'day 1: Copper Power to 2.0 is ticked by a copper dose', 'Copper Power to 2.0' === $todo[1]['label'] && true === $todo[1]['done'] );
$todo = Protocol::todo( $templates['Trifecta'], array( $stays[0] ), '2026-04-03', 'clean', array( array( 'kind' => 'dose', 'note' => 'Metronidazole 2 g' ) ) );
check( 'Trifecta day 3: a metro dose does not tick the water change (not a dose step)', '25 % water change, then the combo' === $todo[1]['label'] && false === $todo[1]['done'] );

echo "12. Settings → Protocol operations (pure)\n";
$base = Protocol::normalize_option( null );
$r    = Settings::apply_protocol_op( $base, 'save', array( 'template' => 'CQ', 'steps' => array( array( 'label' => 'Feed', 'kind' => 'feed', 'day' => 1, 'every' => 1 ), array( 'label' => 'Only step', 'kind' => 'other', 'day' => 2 ) ) ) );
check( 'save: the named template\'s steps replaced, the others untouched', empty( $r['error'] ) && 'CQ' === $r['template'] && 2 === count( $r['protocols']['templates']['CQ'] ) && 'Only step' === $r['protocols']['templates']['CQ'][1]['label'] && $seed === $r['protocols']['templates']['14 Day CM'] && false !== strpos( $r['message'], '"CQ" saved: 2 steps' ), json_encode( $r ) );
$r = Settings::apply_protocol_op( $base, 'save', array( 'steps' => array() ) );
check( 'save without a template name: the default; an empty list is allowed', empty( $r['error'] ) && '14 Day CM' === $r['template'] && array() === $r['protocols']['templates']['14 Day CM'] );
check( 'save to an unknown template: refused', ! empty( Settings::apply_protocol_op( $base, 'save', array( 'template' => 'Nope', 'steps' => array() ) )['error'] ) );
$r = Settings::apply_protocol_op( $base, 'add', array( 'name' => ' Mine ' ) );
check( 'add: a new template with the daily feeding alone, selected', empty( $r['error'] ) && 'Mine' === $r['template'] && array( Protocol::feed_step() ) === $r['protocols']['templates']['Mine'] && 6 === count( $r['protocols']['templates'] ) );
$no_cq = $base;
unset( $no_cq['templates']['CQ'] );
$r = Settings::apply_protocol_op( $no_cq, 'add', array( 'name' => 'CQ' ) );
check( 'add: a seeded name brings its schedule back', empty( $r['error'] ) && $cq === $r['protocols']['templates']['CQ'] );
check( 'add: a blank or taken name, or the 21st template, is refused', ! empty( Settings::apply_protocol_op( $base, 'add', array( 'name' => '' ) )['error'] ) && ! empty( Settings::apply_protocol_op( $base, 'add', array( 'name' => 'CQ' ) )['error'] ) && ! empty( Settings::apply_protocol_op( Protocol::normalize_option( array( 'templates' => array_fill_keys( array_map( static function ( $i ) { return 't' . $i; }, range( 1, 20 ) ), $seed ) ) ), 'add', array( 'name' => 'one more' ) )['error'] ) );
$r = Settings::apply_protocol_op( $base, 'rename', array( 'template' => '14 Day CM', 'name' => 'Copper + Metro' ) );
check( 'rename: the key moves in place, the default follows, the buildings are told', empty( $r['error'] ) && array( 'Copper + Metro', '14 Day CF', 'Xtra 14 Day CF', 'CQ', 'Trifecta' ) === array_keys( $r['protocols']['templates'] ) && 'Copper + Metro' === $r['protocols']['default'] && array( '14 Day CM', 'Copper + Metro' ) === $r['renamed'] && 'Copper + Metro' === $r['template'] );
check( 'rename: onto a taken name refused, onto its own name fine', ! empty( Settings::apply_protocol_op( $base, 'rename', array( 'template' => 'CQ', 'name' => 'Trifecta' ) )['error'] ) && empty( Settings::apply_protocol_op( $base, 'rename', array( 'template' => 'CQ', 'name' => 'CQ' ) )['error'] ) );
$r = Settings::apply_protocol_op( $base, 'delete', array( 'template' => '14 Day CM' ) );
check( 'delete the default: gone, the first remaining is the default, the buildings are cleared', empty( $r['error'] ) && ! isset( $r['protocols']['templates']['14 Day CM'] ) && '14 Day CF' === $r['protocols']['default'] && array( '14 Day CM', '' ) === $r['renamed'] && '14 Day CF' === $r['template'] );
$one = Protocol::normalize_option( array( 'templates' => array( 'Only' => $seed ) ) );
check( 'delete: the last template stays', ! empty( Settings::apply_protocol_op( $one, 'delete', array( 'template' => 'Only' ) )['error'] ) );
$r = Settings::apply_protocol_op( $base, 'make_default', array( 'template' => 'CQ' ) );
check( 'make default', empty( $r['error'] ) && 'CQ' === $r['protocols']['default'] && ! isset( $r['renamed'] ) );
$edited = $base;
$edited['templates']['CQ'] = array( Protocol::feed_step() );
$edited['templates']['Mine'] = array( Protocol::feed_step(), $seed[1] );
$r = Settings::apply_protocol_op( $edited, 'reset', array( 'template' => 'CQ' ) );
check( 'reset a seeded template: its workbook schedule is back', empty( $r['error'] ) && $cq === $r['protocols']['templates']['CQ'] );
$r = Settings::apply_protocol_op( $edited, 'reset', array( 'template' => 'Mine' ) );
check( 'reset a custom template: the daily feeding alone', empty( $r['error'] ) && array( Protocol::feed_step() ) === $r['protocols']['templates']['Mine'] );
check( 'an unknown op', ! empty( Settings::apply_protocol_op( $base, 'explode', array() )['error'] ) );

fh_finish();
