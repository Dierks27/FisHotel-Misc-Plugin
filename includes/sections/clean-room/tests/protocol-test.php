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

$seed = Protocol::seed();

echo "1. The seed (rough — the workbook replaces it)\n";
check( 'seven steps (no "observation ends" chip: sale-ready is computed, a chip that changes nothing is a burden)', 7 === count( $seed ) && ! in_array( 'Observation ends', array_column( $seed, 'label' ), true ) );
check( 'normalising the seed changes nothing', $seed === Protocol::normalize( $seed ) );
check( 'feed is daily, anywhere, open-ended', 'feed' === $seed[0]['kind'] && 1 === $seed[0]['every'] && null === $seed[0]['until'] && 'any' === $seed[0]['where'] );
check( 'copper test runs days 2–14 daily in the Spa', 'Copper test' === $seed[2]['label'] && 2 === $seed[2]['day'] && 1 === $seed[2]['every'] && 14 === $seed[2]['until'] && 'spa' === $seed[2]['where'] );
check( 'metronidazole: days 1–11 every other day = six treatments', 'Metronidazole' === $seed[3]['label'] && 1 === $seed[3]['day'] && 2 === $seed[3]['every'] && 11 === $seed[3]['until'] );
check( 'two praziquantel treatments', 'Praziquantel 1' === $seed[5]['label'] && 15 === $seed[5]['day'] && 'Praziquantel 2' === $seed[6]['label'] && 22 === $seed[6]['day'] );
check( 'no step beyond day 22', 22 === max( array_column( $seed, 'day' ) ) );

echo "2. is_due()\n";
$feed   = $seed[0];
$copper = $seed[2];
$metro  = $seed[3];
$prazi1 = $seed[5];
check( 'feed on days 1, 2, 50', Protocol::is_due( $feed, 1 ) && Protocol::is_due( $feed, 2 ) && Protocol::is_due( $feed, 50 ) );
check( 'nothing on day 0', ! Protocol::is_due( $feed, 0 ) && ! Protocol::is_due( $metro, 0 ) );
check( 'copper test: not day 1, yes 2…14, not 15', ! Protocol::is_due( $copper, 1 ) && Protocol::is_due( $copper, 2 ) && Protocol::is_due( $copper, 9 ) && Protocol::is_due( $copper, 14 ) && ! Protocol::is_due( $copper, 15 ) );
$metro_days = array();
for ( $d = 1; $d <= 30; $d++ ) {
	if ( Protocol::is_due( $metro, $d ) ) {
		$metro_days[] = $d;
	}
}
check( 'metronidazole exactly on 1, 3, 5, 7, 9, 11', array( 1, 3, 5, 7, 9, 11 ) === $metro_days, implode( ',', $metro_days ) );
check( 'a one-off step is due on its day only', Protocol::is_due( $prazi1, 15 ) && ! Protocol::is_due( $prazi1, 16 ) && ! Protocol::is_due( $prazi1, 14 ) );
check( 'an open-ended repeat every 3 days from day 4', Protocol::is_due( Protocol::step( 'x', 'test', 4, 3 ), 10 ) && ! Protocol::is_due( Protocol::step( 'x', 'test', 4, 3 ), 11 ) );

echo "3. due() and where\n";
$due = Protocol::due( $seed, 1, 'spa' );
check( 'day 1 in the Spa: feed, start copper, metronidazole', array( 'Feed', 'Start copper', 'Metronidazole' ) === array_column( $due, 'label' ), implode( ',', array_column( $due, 'label' ) ) );
$due = Protocol::due( $seed, 1, 'clean' );
check( 'day 1 in the Clean Room: only feed (the Spa steps do not apply)', array( 'Feed' ) === array_column( $due, 'label' ) );
$due = Protocol::due( $seed, 14, 'spa' );
check( 'day 14 in the Spa: feed, copper test, copper off', array( 'Feed', 'Copper test', 'Copper off — water change' ) === array_column( $due, 'label' ) );
$due = Protocol::due( $seed, 22, 'clean' );
check( 'day 22 in the Clean Room: feed, praziquantel 2', array( 'Feed', 'Praziquantel 2' ) === array_column( $due, 'label' ) );
$due = Protocol::due( $seed, 28, 'clean' );
check( 'day 28 in the Clean Room: only feed', array( 'Feed' ) === array_column( $due, 'label' ) );
check( 'no area → everything due: day 14 has feed, copper test, copper off; day 1 feed, start copper, metronidazole', 3 === count( Protocol::due( $seed, 14, '' ) ) && 3 === count( Protocol::due( $seed, 1, '' ) ) && 2 === count( Protocol::due( $seed, 15, '' ) ) );
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
	array( 'id' => 8, 'species' => 'Ocellaris Clownfish', 'qty_live' => 2, 'checkin_date' => '2026-03-20', 'day' => 15 ),
	array( 'id' => 9, 'species' => 'Dead group', 'qty_live' => 0, 'checkin_date' => '2026-04-01', 'day' => 3 ),
);
$todo = Protocol::todo( $seed, $stays, '2026-04-03', 'spa' );
$labels = array_map( static function ( $i ) { return $i['label'] . ( $i['stay_id'] ? ' #' . $i['stay_id'] : '' ); }, $todo );
check( 'feed once for the tank, then the due steps per live stay', array( 'Feed', 'Copper test #7', 'Metronidazole #7', 'Praziquantel 1 #8' ) === $labels, implode( ', ', $labels ) );
check( 'items carry kind, species, day, note and a key', 'test' === $todo[1]['kind'] && 'Pajama Cardinalfish' === $todo[1]['species'] && 3 === $todo[1]['day'] && '' !== $todo[1]['note'] && 'copper test #7' === $todo[1]['key'] );
check( 'nothing is done yet', 0 === count( array_filter( array_column( $todo, 'done' ) ) ) );
$done = array( array( 'note' => 'feed' ), array( 'note' => 'Copper test #7' ) );
$todo = Protocol::todo( $seed, $stays, '2026-04-03', 'spa', $done );
check( 'today\'s tank logs tick items off by their key', true === $todo[0]['done'] && true === $todo[1]['done'] && false === $todo[2]['done'] );
check( 'a tank with no live fish has no to-do, not even feeding', array() === Protocol::todo( $seed, array( $stays[2] ), '2026-04-03', 'spa' ) );
$todo = Protocol::todo( $seed, array( $stays[1] ), '2026-04-03', 'clean' );
check( 'in the Clean Room on day 15: feed and praziquantel 1 (no copper)', array( 'Feed', 'Praziquantel 1' ) === array_column( $todo, 'label' ) );
$todo = Protocol::todo( $seed, array( array( 'id' => 10, 'species' => 'X', 'qty_live' => 1, 'checkin_date' => '2026-04-01' ) ), '2026-04-05', 'spa' );
check( 'the day is computed from the check-in when missing (Apr 5 = day 5: feed, copper test, metronidazole)', array( 'Feed', 'Copper test', 'Metronidazole' ) === array_column( $todo, 'label' ) );
check( 'tick_key normalises case and spaces', 'copper test #7' === Protocol::tick_key( '  Copper   TEST #7 ' ) );
$todo = Protocol::todo( $seed, $stays, '2026-04-03', 'spa', array( array( 'kind' => 'copper', 'value' => 2.4, 'note' => '' ) ) );
check( 'a copper reading logged today ticks the copper test by itself ("521 copper 2.4" closes the chip)', true === $todo[1]['done'] && 'Copper test' === $todo[1]['label'] && false === $todo[0]['done'] && false === $todo[2]['done'] );
$ph = array( Protocol::step( 'pH test', 'test', 1, 1, null, 'spa' ), Protocol::step( 'Cu check', 'test', 1, 1, null, 'spa' ), Protocol::step( 'Copper off', 'water_change', 1, 1, null, 'spa' ) );
$todo = Protocol::todo( $ph, array( $stays[0] ), '2026-04-03', 'spa', array( array( 'kind' => 'copper', 'value' => 2.4 ) ) );
check( 'only a test step that names copper / Cu is ticked by a reading; a pH test and a water change are not', false === $todo[0]['done'] && true === $todo[1]['done'] && false === $todo[2]['done'] );
check( 'is_copper_test', Protocol::is_copper_test( $copper ) && ! Protocol::is_copper_test( $seed[1] ) && ! Protocol::is_copper_test( Protocol::step( 'Cumin test', 'test', 1 ) ) );

echo "6. Copper state and describe()\n";
check( 'copper_state', 'ok' === Protocol::copper_state( 2.5, 2.25 ) && 'ok' === Protocol::copper_state( 2.25, 2.25 ) && 'low' === Protocol::copper_state( 2.1, 2.25 ) && 'none' === Protocol::copper_state( null, 2.25 ) );
check( 'describe a repeating step', 'Copper test · test · days 2–14, daily · Spa' === Protocol::describe( $copper ), Protocol::describe( $copper ) );
check( 'describe a one-off', 'Praziquantel 1 · dose · day 15 · anywhere' === Protocol::describe( $prazi1 ) );
check( 'describe an open-ended repeat', 'Feed · feed · days 1–…, daily · anywhere' === Protocol::describe( $feed ) );
check( 'describe every 2 days', 'Metronidazole · dose · days 1–11, every 2 days · Spa' === Protocol::describe( $metro ) );

fh_finish();
