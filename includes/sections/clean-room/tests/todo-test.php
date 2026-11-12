<?php
/**
 * The day's to-do by water system — one line per due step per building
 * however many tanks have fish, groups on different days, the feeding as
 * one line per building, one truth wherever a step is ticked (here, on a
 * tank's own screen, by a system dose, by a copper reading on any tank of
 * the building), the count at the top and the copper flags, a temporary
 * tank beside the buildings, the medication and the dose a line names,
 * and the summary other sections read.
 *
 * Run from the plugin root:  php includes/sections/clean-room/tests/todo-test.php
 */

require_once __DIR__ . '/wp-stubs.php';

use FisHotel\Misc\Sections\Clean_Room\Clean_Room;
use FisHotel\Misc\Sections\Clean_Room\Front_Desk;
use FisHotel\Misc\Sections\Clean_Room\Protocol;
use FisHotel\Misc\Sections\Clean_Room\Replay;
use FisHotel\Misc\Sections\Clean_Room\Settings;
use FisHotel\Misc\Sections\Clean_Room\Store;
use FisHotel\Misc\Sections\Clean_Room\Temp_Tanks;
use FisHotel\Misc\Sections\Clean_Room\Todo;
use FisHotel\Misc\Sections\Clean_Room\Tests\Fake_Store;

$today     = Replay::date_in_zone( time(), wp_timezone() ); // The acts stamp logs with the real clock.
$settings  = Settings::get();
$protocols = Settings::protocols();

function ctx( Fake_Store $store, $tank_number, $actor = 8 ) {
	global $settings, $today;
	return array(
		'store'    => $store,
		'settings' => $settings,
		'today'    => $today,
		'tank'     => $tank_number > 0 ? $store->tank_by_number( $tank_number ) : null,
		'base'     => array(
			'actor_id' => $actor,
			'source'   => 'qr',
		),
	);
}

function act( Fake_Store $store, $tank_number, $act, array $raw ) {
	return Front_Desk::perform( $act, ctx( $store, $tank_number ), $raw );
}

/**
 * A group checked in so that today is its day $day.
 */
function arrive( Fake_Store $store, $tank_number, $species, $qty, $day = 1 ) {
	global $today;
	return act( $store, $tank_number, 'arrival', array( 'species' => $species, 'qty' => $qty, 'date' => Replay::add_days( $today, 1 - $day ) ) );
}

/**
 * A group put straight into any tank (the phone never checks in to the
 * Clean Room; a group gets there by a move — this skips the walk).
 */
function place( Fake_Store $store, $tank_number, $species, $qty, $day = 1 ) {
	global $today, $settings;
	$tank = $store->tank_by_number( $tank_number );
	$date = Replay::add_days( $today, 1 - $day );
	$id   = $store->create_stay( array( 'species' => $species, 'checkin_date' => $date, 'tank_id' => (int) $tank['id'] ) );
	$store->record_event( $id, array( 'type' => 'arrival', 'qty' => $qty, 'to_tank_id' => (int) $tank['id'], 'event_at' => Front_Desk::event_at_for_date( $date, $today ) ), $today, $settings );
	return $id;
}

function room( Fake_Store $store ) {
	global $today, $settings, $protocols;
	return $store->room( $today, $settings, $protocols );
}

/**
 * The block of a building number (or of a slot's tank number) in a list.
 */
function block( array $list, $number ) {
	foreach ( $list['blocks'] as $block ) {
		if ( (int) $block['number'] === (int) $number ) {
			return $block;
		}
	}
	return null;
}

/**
 * label => done of a block's lines.
 */
function lines( array $block ) {
	return array_column( $block['items'], 'done', 'label' );
}

function tank_todo( array $room, $number ) {
	foreach ( $room['tanks'] as $tank ) {
		if ( (int) $tank['number'] === (int) $number ) {
			return array_column( $tank['todo'], 'done', 'label' );
		}
	}
	return null;
}

/* ------------------------------------------------------------------ */
echo "1. Once for the system, never once per tank\n";

$store = new Fake_Store();
arrive( $store, 521, 'Blue Test Wrasse', 4, 2 );
arrive( $store, 522, 'Test Clownfish', 2, 2 );
arrive( $store, 513, 'Test Goby', 1, 2 );
arrive( $store, 513, 'Test Blenny', 3, 2 );
$room = room( $store );
$list = $room['todo'];
$spa  = block( $list, 500 );

check( 'the block is the whole shared system: its name, gallons, schedule and day', 'system' === $spa['type'] && 'Spa · Building 500' === $spa['title'] && 205 === $spa['gallons'] && '14 Day CM' === $spa['schedule'] && array( 2 ) === $spa['days'] && 'day 2' === $spa['day_text'] && 3 === $spa['tanks'] && 10 === $spa['fish'], json_encode( array_diff_key( $spa, array( 'items' => 1 ) ) ) );
check( 'three tanks and four groups on day 2: each due step is ONE line', array( 'Copper Power to 2.4', 'Copper test', 'Metronidazole', 'Feed' ) === array_column( $spa['items'], 'label' ) && array( 'system', 'system', 'system', 'feed' ) === array_column( $spa['items'], 'scope' ) );
$chips = 0;
foreach ( $room['tanks'] as $tank ) {
	if ( 500 === $tank['building']['number'] ) {
		$chips += count( $tank['todo'] );
	}
}
check( 'while the tanks\' own screens carry fifteen chips between them', 15 === $chips, $chips );
check( 'the same due rules as a tank\'s own to-do', array_keys( tank_todo( $room, 522 ) ) === array( 'Feed', 'Copper Power to 2.4', 'Copper test', 'Metronidazole' ) );
check( 'nothing is done yet; four things left', 4 === $spa['left'] && 4 === $list['left'] && 4 === $list['total'] && ! $list['all_done'] );
check( 'every group on the same day: no line needs to say whose it is', array( '', '', '', '' ) === array_column( $spa['items'], 'for' ) );

$feed = $spa['items'][3];
check( 'the feeding is one line: "Feed" for 3 tanks with fish, opening to the tanks', 'feed' === $feed['kind'] && 3 === $feed['count'] && 3 === $feed['left'] && false === $feed['done'] && array( 521, 522, 513 ) === array_column( $feed['tanks'], 'number' ), json_encode( $feed['tanks'] ) );

/* ------------------------------------------------------------------ */
echo "2. Each line says the step, the medication and the dose it names, and its note\n";

$by = array_column( $spa['items'], null, 'label' );
check( 'a dose step naming a medication: the Dose sheet\'s own suggestion for the system\'s gallons', '' === $by['Metronidazole']['medication'] && 'suggested 5.1 g for 205 gal' === $by['Metronidazole']['dose'], json_encode( $by['Metronidazole'] ) );
check( 'copper is dosed to the test: the medication, no amount', 'Copper Power' === $by['Copper Power to 2.4']['medication'] && '' === $by['Copper Power to 2.4']['dose'] );
check( 'a test names neither', '' === $by['Copper test']['medication'] && '' === $by['Copper test']['dose'] );
check( 'the step\'s note rides along', 0 === strpos( $by['Metronidazole']['note'], 'six treatments' ) && 0 === strpos( $by['Feed']['note'], 'every tank with fish' ) );
check( 'a rate in the label wins over the standard one: 40 mg/gal on 205 gal is 8.2 g', '40 mg/gal = 8.2 g for 205 gal' === Todo::dose_text( 'Chloroquine 40 mg/gal', 205 ) && '12 mg/gal = 1.2 g for 100 gal' === Todo::dose_text( 'Chloroquine 12 mg/gal', 100 ) && '6 mg per gallon' !== Todo::dose_text( 'Chloroquine 6 mg per gallon', 0 ) && '6 mg/gal' === Todo::dose_text( 'Chloroquine 6 mg per gallon', 0 ) );
check( 'a numbered step names its medication', array( 'medication' => 'Praziquantel', 'dose' => 'suggested 1.9 g for 205 gal' ) === Todo::names( 'Praziquantel 1', 'dose', 205 ) );
check( 'no gallons, no amount; no medication, nothing', '' === Todo::dose_text( 'Metronidazole', 0 ) && array( 'medication' => '', 'dose' => '' ) === Todo::names( 'Freshwater bath', 'dose', 205 ) && array( 'medication' => '', 'dose' => '' ) === Todo::names( 'Metronidazole', 'other', 205 ) );
check( 'amounts as the Dose sheet rounds them', '5.1' === Todo::amount_text( 5.125 ) && '0.25' === Todo::amount_text( 0.25 ) && '2' === Todo::amount_text( 2.0 ) && '0.1' === Todo::amount_text( 0.1 ) );

/* ------------------------------------------------------------------ */
echo "3. Groups on different days\n";

$store = new Fake_Store();
arrive( $store, 521, 'Blue Test Wrasse', 4, 2 );
arrive( $store, 522, 'Test Clownfish', 2, 8 );
arrive( $store, 523, 'Test Goby', 1, 8 );
$spa = block( room( $store )['todo'], 500 );
$by  = array_column( $spa['items'], null, 'label' );
check( 'the block names both days', array( 2, 8 ) === $spa['days'] && 'days 2 and 8' === $spa['day_text'] );
check( 'a step due for only one day\'s group is listed once and says which', 'day 2 group' === $by['Copper Power to 2.4']['for'] && array( 2 ) === $by['Copper Power to 2.4']['days'] && 'day 8 group' === $by['Praziquantel 1']['for'] && array( 8 ) === $by['Praziquantel 1']['days'] );
check( 'a step due for every group is listed once and needs no such word', '' === $by['Copper test']['for'] && array( 2, 8 ) === $by['Copper test']['days'] && '' === $by['Metronidazole']['for'] );
check( 'five lines in all, each step once', array( 'Copper Power to 2.4', 'Copper test', 'Metronidazole', 'Praziquantel 1', 'Feed' ) === array_column( $spa['items'], 'label' ) );
arrive( $store, 524, 'Test Blenny', 1, 14 );
$spa = block( room( $store )['todo'], 500 );
$by  = array_column( $spa['items'], null, 'label' );
check( 'three days: "days 2, 8 and 14"; a step two of them share names both', 'days 2, 8 and 14' === $spa['day_text'] && 'day 2 and day 8 groups' === $by['Metronidazole']['for'] && 'day 14 group' === $by['Praziquantel 2']['for'] && '' === $by['Copper test']['for'] );
check( 'days_text', 'day 3' === Todo::days_text( array( 3 ) ) && 'days 3 and 9' === Todo::days_text( array( 9, 3, 3 ) ) && 'day 3 group' === Todo::days_text( array( 3 ), ' group' ) && 'day 2, day 5 and day 9 groups' === Todo::days_text( array( 2, 5, 9 ), ' group' ) && '' === Todo::days_text( array() ) );

/* ------------------------------------------------------------------ */
echo "4. One truth, wherever it is ticked\n";

$store = new Fake_Store();
arrive( $store, 521, 'Blue Test Wrasse', 4, 2 );
arrive( $store, 522, 'Test Clownfish', 2, 2 );
arrive( $store, 513, 'Test Goby', 1, 2 );
arrive( $store, 611, 'Test Blenny', 2, 2 ); // The other system: nothing here may reach it.

// (a) Ticked on the list, once for the whole system.
$r = act( $store, 0, 'todo_tick', array( 'scope' => 'system', 'building' => $store->tank_by_number( 521 )['building_id'], 'key' => 'copper power to 2.4', 'kind' => 'dose', 'label' => 'Copper Power to 2.4' ) );
check( 'a tick on the list needs no tank on the page', empty( $r['error'] ) && 'Done: Copper Power to 2.4 · Building 500, whole system' === $r['message'], json_encode( $r ) );
$log = end( $store->logs );
check( 'it is one entry on the building, not one per tank', 0 === $log['tank_id'] && (int) $store->tank_by_number( 521 )['building_id'] === $log['building_id'] && 'dose' === $log['kind'] && 'copper power to 2.4' === $log['note'] && 1 === count( $store->logs ) );
$room = room( $store );
check( 'the list shows it ticked', true === lines( block( $room['todo'], 500 ) )['Copper Power to 2.4'] && 3 === block( $room['todo'], 500 )['left'] );
check( 'and so does every tank of that building', true === tank_todo( $room, 521 )['Copper Power to 2.4'] && true === tank_todo( $room, 522 )['Copper Power to 2.4'] && true === tank_todo( $room, 513 )['Copper Power to 2.4'] );
check( 'the other system is untouched', false === lines( block( $room['todo'], 600 ) )['Copper Power to 2.4'] && false === tank_todo( $room, 611 )['Copper Power to 2.4'] );

// (b) Ticked on one tank's own screen.
$key = null;
foreach ( $room['tanks'] as $tank ) {
	if ( 522 === $tank['number'] ) {
		foreach ( $tank['todo'] as $item ) {
			if ( 'Metronidazole' === $item['label'] ) {
				$key = $item['key'];
			}
		}
	}
}
act( $store, 522, 'tick', array( 'key' => $key, 'kind' => 'dose', 'label' => 'Metronidazole' ) );
$room = room( $store );
check( 'a tick on one tank\'s own screen ticks it for the system', false !== strpos( $key, '#' ) && true === lines( block( $room['todo'], 500 ) )['Metronidazole'] );
check( 'and on the other tanks of the building', true === tank_todo( $room, 521 )['Metronidazole'] && true === tank_todo( $room, 513 )['Metronidazole'] && false === tank_todo( $room, 611 )['Metronidazole'] );

// (c) A copper reading taken on any tank of the building.
act( $store, 513, 'copper', array( 'value' => '2.3' ) );
$room = room( $store );
check( 'a copper reading on ANY tank of the building ticks the copper test for the system', true === lines( block( $room['todo'], 500 ) )['Copper test'] && true === tank_todo( $room, 521 )['Copper test'] && true === tank_todo( $room, 522 )['Copper test'] && false === lines( block( $room['todo'], 600 ) )['Copper test'] );
check( 'and it is the reading of the whole system', 2.3 === block( $room['todo'], 500 )['copper']['value'] && 513 === block( $room['todo'], 500 )['copper']['tank'] && null === block( $room['todo'], 600 )['copper']['value'] );

// (d) A dose logged on the system.
act( $store, 611, 'dose', array( 'medication' => 'metro', 'amount' => '2.5', 'unit' => 'g', 'whole' => '1' ) );
$room = room( $store );
check( 'a dose logged on the system ticks the step naming that medication', true === lines( block( $room['todo'], 600 ) )['Metronidazole'] && true === tank_todo( $room, 611 )['Metronidazole'] && false === lines( block( $room['todo'], 600 ) )['Copper test'] );

// (e) A dose into one tank of a shared system is in the system's water.
$store2 = new Fake_Store();
arrive( $store2, 521, 'Blue Test Wrasse', 4, 2 );
arrive( $store2, 522, 'Test Clownfish', 2, 2 );
act( $store2, 521, 'dose', array( 'medication' => 'metro', 'amount' => '5.1', 'unit' => 'g', 'whole' => '0' ) );
$room2 = room( $store2 );
check( 'a dose into one tank of a shared system is in everybody\'s water', true === lines( block( $room2['todo'], 500 ) )['Metronidazole'] && true === tank_todo( $room2, 522 )['Metronidazole'] );

// The feeding: per tank, or all at once.
act( $store, 521, 'tick', array( 'key' => 'feed', 'kind' => 'feed', 'label' => 'Feed' ) );
$room = room( $store );
$feed = array_column( block( $room['todo'], 500 )['items'], null, 'label' )['Feed'];
check( 'one tank fed: the line stays open and counts what is left', false === $feed['done'] && 3 === $feed['count'] && 2 === $feed['left'] && array( 521 => true, 522 => false, 513 => false ) === array_column( $feed['tanks'], 'done', 'number' ) );
$r = act( $store, 0, 'todo_tick', array( 'scope' => 'tank', 'tick_tank' => 522, 'key' => 'feed', 'kind' => 'feed', 'label' => 'Feed' ) );
check( 'one tank of the line ticked from the list', empty( $r['error'] ) && 'Done: Feed · Tank 522' === $r['message'] && 1 === array_column( block( room( $store )['todo'], 500 )['items'], null, 'label' )['Feed']['left'] );
$r = act( $store, 0, 'todo_tick', array( 'scope' => 'system', 'building' => $store->tank_by_number( 521 )['building_id'], 'key' => 'feed', 'kind' => 'feed', 'label' => 'Feed' ) );
$room = room( $store );
$feed = array_column( block( $room['todo'], 500 )['items'], null, 'label' )['Feed'];
check( 'ticked all at once: every tank with fish is fed', empty( $r['error'] ) && true === $feed['done'] && 0 === $feed['left'] && true === tank_todo( $room, 513 )['Feed'] && true === tank_todo( $room, 522 )['Feed'] && false === tank_todo( $room, 611 )['Feed'] );

/* ------------------------------------------------------------------ */
echo "5. The count at the top\n";

check( 'Spa 500 is all done; Spa 600 has three things left', 0 === block( $room['todo'], 500 )['left'] && 3 === block( $room['todo'], 600 )['left'] && 3 === $room['todo']['left'] && 8 === $room['todo']['total'] && ! $room['todo']['all_done'] );
check( 'the headline', 'Tanks: 3 things to do' === Todo::headline( $room['todo'] ) && 'Tanks: 1 thing to do' === Todo::headline( array( 'left' => 1, 'total' => 4 ) ) );
act( $store, 611, 'copper', array( 'value' => '2.5' ) );
act( $store, 0, 'todo_tick', array( 'scope' => 'system', 'building' => $store->tank_by_number( 611 )['building_id'], 'key' => 'copper power to 2.4', 'kind' => 'dose', 'label' => 'Copper Power to 2.4' ) );
act( $store, 611, 'tick', array( 'key' => 'feed', 'kind' => 'feed', 'label' => 'Feed' ) );
$room = room( $store );
check( '"all done" when it is', 0 === $room['todo']['left'] && $room['todo']['all_done'] && 'Tanks: all done' === Todo::headline( $room['todo'] ) );
$empty = room( new Fake_Store() );
check( 'an empty room: nothing due, not "all done"', 0 === $empty['todo']['total'] && ! $empty['todo']['all_done'] && 'Tanks: nothing due today' === Todo::headline( $empty['todo'] ) );
check( 'every quarantine system still has its block, with fish or without; the Clean Room and the empty slots have none', array( 500, 600 ) === array_column( $empty['todo']['blocks'], 'number' ) && array( 0, 0 ) === array_column( $empty['todo']['blocks'], 'fish' ) && array() === $empty['todo']['blocks'][0]['items'] );
$summary = Todo::summary( $room['todo'], 'https://example.test/wp-admin/admin.php?page=x&tab=todo' );
check( 'the summary other sections read', array( 'left' => 0, 'total' => 8, 'all_done' => true, 'flags' => 0, 'text' => 'Tanks: all done', 'url' => 'https://example.test/wp-admin/admin.php?page=x&tab=todo' ) === $summary );

/* ------------------------------------------------------------------ */
echo "6. Copper readings: low, due, overdue\n";

$store = new Fake_Store();
arrive( $store, 521, 'Blue Test Wrasse', 4, 3 );
arrive( $store, 621, 'Test Goby', 1, 3 );
$list = room( $store )['todo'];
check( 'a copper test due and no reading ever: flagged overdue', 2 === count( $list['flags'] ) && 'Building 500' === $list['flags'][0]['where'] && $list['flags'][0]['due'] && $list['flags'][0]['overdue'] && ! $list['flags'][0]['low'] && null === $list['flags'][0]['days_ago'] );
$store->log_tank( (int) $store->tank_by_number( 522 )['id'], array( 'kind' => 'copper', 'value' => 2.4, 'logged_at' => gmdate( 'Y-m-d H:i:s', time() - 26 * 3600 ) ) );
$store->log_tank( (int) $store->tank_by_number( 621 )['id'], array( 'kind' => 'copper', 'value' => 2.5, 'logged_at' => gmdate( 'Y-m-d H:i:s', time() - 4 * 86400 ) ) );
$list = room( $store )['todo'];
$flag = array_column( $list['flags'], null, 'where' );
check( 'yesterday\'s reading: today\'s test is due, not overdue', $flag['Building 500']['due'] && ! $flag['Building 500']['overdue'] && 1 === $flag['Building 500']['days_ago'] && 2.4 === $flag['Building 500']['value'] );
check( 'a reading four days old: overdue', $flag['Building 600']['due'] && $flag['Building 600']['overdue'] && 4 === $flag['Building 600']['days_ago'] );
act( $store, 521, 'copper', array( 'value' => '2.1' ) );
$list = room( $store )['todo'];
$flag = array_column( $list['flags'], null, 'where' );
check( 'a reading under the floor is flagged low, the way the tank screen flags it — the test itself is ticked', $flag['Building 500']['low'] && ! $flag['Building 500']['due'] && 'low' === block( $list, 500 )['copper']['state'] && true === lines( block( $list, 500 ) )['Copper test'] );
act( $store, 513, 'copper', array( 'value' => '2.5' ) );
$list = room( $store )['todo'];
check( 'a good reading anywhere in the system clears the flag', array( 'Building 600' ) === array_column( $list['flags'], 'where' ) && 'ok' === block( $list, 500 )['copper']['state'] );
check( 'copper_flag: nothing to say, nothing said', null === Todo::copper_flag( 'x', array( 'value' => 2.5, 'logged_at' => gmdate( 'Y-m-d H:i:s' ), 'state' => 'ok' ), false, $today, wp_timezone() ) );

/* ------------------------------------------------------------------ */
echo "7. A temporary tank beside the buildings\n";

$store = new Fake_Store();
arrive( $store, 521, 'Blue Test Wrasse', 4, 2 );
place( $store, 121, 'Test Clownfish', 2, 30 ); // A group that has been in the Clean Room for a while.
$r = act( $store, 0, 'setup', array( 'name' => 'Hospital A', 'purpose' => 'bacterial infection', 'kind' => 'quarantine', 'gallons' => 10, 'template' => 'Trifecta' ) );
arrive( $store, 901, 'Test Goby', 1, 5 );
$room = room( $store );
$list = $room['todo'];
check( 'the blocks: the quarantine systems, then the temporary tank, then a Clean Room building with something due', array( 500, 600, 901 ) === array_slice( array_column( $list['blocks'], 'number' ), 0, 3 ) && array( 'system', 'system', 'temp' ) === array_slice( array_column( $list['blocks'], 'type' ), 0, 3 ), json_encode( array_column( $list['blocks'], 'number' ) ) );
$temp = block( $list, 901 );
check( 'the temporary tank is marked: its slot, name, kind, purpose, gallons, schedule and its own day', 'temp' === $temp['type'] && 'TEMP 1 · 901' === $temp['label'] && 'TEMP 1 · 901 · Hospital A' === $temp['title'] && 'quarantine' === $temp['kind'] && 'Quarantine' === $temp['kind_label'] && 'bacterial infection' === $temp['purpose'] && 10 === $temp['gallons'] && 'Trifecta' === $temp['schedule'] && array( 1 ) === $temp['days'] && 'day 1' === $temp['day_text'] && 1 === $temp['fish'] );
check( 'its lines are its own schedule on its own clock — day 1, whatever day the fish is on', array( 'Feed', 'Metronidazole + Kanamycin + Furan-2' ) === array_column( $temp['items'], 'label' ) && array( 'tank', 'tank' ) === array_column( $temp['items'], 'scope' ) && array( 901, 901 ) === array_column( $temp['items'], 'tank' ) );
check( 'the dose is worked out for the tank\'s own gallons', 'Metronidazole' === $temp['items'][1]['medication'] && 'suggested 0.25 g for 10 gal' === $temp['items'][1]['dose'] );
check( 'the count takes it in', 2 === $temp['left'] && 4 + 2 + 1 === $list['left'], $list['left'] );

$item = $temp['items'][1];
$r    = act( $store, 0, 'todo_tick', array( 'scope' => 'tank', 'tick_tank' => 901, 'key' => $item['key'], 'kind' => $item['kind'], 'label' => $item['label'] ) );
check( 'its line is ticked on the tank', empty( $r['error'] ) && 'Done: Metronidazole + Kanamycin + Furan-2 · TEMP 1 · 901' === $r['message'], json_encode( $r ) );
$log  = end( $store->logs );
$room = room( $store );
check( 'the tick belongs to the setup', (int) $store->tank_by_number( 901 )['id'] === $log['tank_id'] && (int) $store->tank_by_number( 901 )['setup']['id'] === $log['setup_id'] );
check( 'the list and the tank\'s own screen agree', true === lines( block( $room['todo'], 901 ) )['Metronidazole + Kanamycin + Furan-2'] && true === tank_todo( $room, 901 )['Metronidazole + Kanamycin + Furan-2'] && 1 === block( $room['todo'], 901 )['left'] );
check( 'nothing of it reaches a building', false === lines( block( $room['todo'], 500 ) )['Metronidazole'] && 4 === block( $room['todo'], 500 )['left'] );
act( $store, 0, 'todo_tick', array( 'scope' => 'system', 'building' => $store->tank_by_number( 521 )['building_id'], 'key' => 'feed', 'kind' => 'feed', 'label' => 'Feed' ) );
check( 'and a building\'s tick does not reach the temporary tank', false === lines( block( room( $store )['todo'], 901 ) )['Feed'] );
$r = act( $store, 0, 'todo_tick', array( 'scope' => 'system', 'building' => $store->temp_building()['id'], 'key' => 'feed', 'kind' => 'feed' ) );
check( 'the Temporary building is no system to tick', 'A temporary tank is its own water — tick it on that tank.' === ( $r['error'] ?? '' ) );
$r = act( $store, 0, 'todo_tick', array( 'scope' => 'tank', 'tick_tank' => 902, 'key' => 'feed', 'kind' => 'feed' ) );
check( 'a slot with nothing set up has nothing to tick', isset( $r['error'] ) && false !== strpos( $r['error'], 'Nothing is set up in TEMP 2 · 902' ) );

$clean = block( $list, 100 );
check( 'a Clean Room building is listed only because something is due there: its feeding', $clean && 'system' === $clean['type'] && 'Clean Room · Building 100' === $clean['title'] && array( 'Feed' ) === array_column( $clean['items'], 'label' ) && null === block( $list, 200 ) && null === block( $list, 400 ) );

// An empty temporary tank still runs its treatment; the feeding waits for fish.
$r    = act( $store, 0, 'setup', array( 'name' => 'Empty one', 'kind' => 'clean', 'template' => 'Trifecta' ) );
$temp = block( room( $store )['todo'], 902 );
check( 'set up but empty: the treatment is due, the feeding is not', $temp && array( 'Metronidazole + Kanamycin + Furan-2' ) === array_column( $temp['items'], 'label' ) && 0 === $temp['fish'] && 'Clean' === $temp['kind_label'] );
$r    = act( $store, 0, 'setup', array( 'name' => 'Just watching', 'kind' => 'clean' ) );
$temp = block( room( $store )['todo'], 903 );
check( '"no treatment, just watching", no fish yet: a block with nothing due', $temp && array() === $temp['items'] && 'no treatment' === $temp['schedule'] );

/* ------------------------------------------------------------------ */
echo "8. Ticking: the guards\n";

$base = array( 'actor_id' => 8, 'source' => 'manual' );
check( 'nothing to tick without a key', 'Nothing to tick.' === ( Todo::tick( $store, array( 'scope' => 'system', 'building' => 5, 'key' => '   ' ), $base )['error'] ?? '' ) );
check( 'a building that is not there', 'That building is not in the layout.' === ( Todo::tick( $store, array( 'scope' => 'system', 'building' => 99, 'key' => 'feed', 'kind' => 'feed' ), $base )['error'] ?? '' ) );
check( 'a building that is coming soon', isset( Todo::tick( $store, array( 'scope' => 'system', 'building' => 4, 'key' => 'feed', 'kind' => 'feed' ), $base )['error'] ) );
check( 'a tank that is not there', 'That tank is not in the layout.' === ( Todo::tick( $store, array( 'scope' => 'tank', 'tick_tank' => 999, 'key' => 'feed', 'kind' => 'feed' ), $base )['error'] ?? '' ) );
$before = count( $store->logs );
Todo::tick( $store, array( 'scope' => 'system', 'building' => $store->tank_by_number( 521 )['building_id'], 'key' => 'Odd  Step', 'kind' => 'copper' ), $base );
$log = end( $store->logs );
check( 'a tick is never a copper reading or a note: an unknown kind is "other"; the key is the step\'s key', $before + 1 === count( $store->logs ) && 'other' === $log['kind'] && 'odd step' === $log['note'] && null === $log['value'] );
check( 'ticked(): the system key, or one group\'s key on one tank — never a look-alike', Todo::ticked( 'praziquantel 1', array( array( 'note' => 'Praziquantel 1' ) ) ) && Todo::ticked( 'praziquantel 1', array( array( 'note' => 'praziquantel 1 #12' ) ) ) && ! Todo::ticked( 'praziquantel 1', array( array( 'note' => 'praziquantel 10' ), array( 'note' => 'praziquantel 1 #' ), array( 'note' => 'praziquantel 1 #x' ), array( 'note' => 'praziquantel' ) ) ) && ! Todo::ticked( '', array( array( 'note' => '' ) ) ) );
check( 'key_label', 'furan-2' === Todo::key_label( 'furan-2 #0' ) && 'feed' === Todo::key_label( 'feed' ) );

/* ------------------------------------------------------------------ */
echo "9. The pool, the overlay and the screen\n";

$rows = array(
	array( 'tank_id' => 0, 'building_id' => 5, 'note' => 'a' ),
	array( 'tank_id' => 14, 'building_id' => null, 'note' => 'b' ),
	array( 'tank_id' => 20, 'building_id' => null, 'note' => 'c' ),
	array( 'tank_id' => 0, 'building_id' => 6, 'note' => 'd' ),
	array( 'tank_id' => 30, 'building_id' => null, 'setup_id' => 3, 'note' => 'e' ),
	array( 'tank_id' => 30, 'building_id' => null, 'setup_id' => 2, 'note' => 'f' ),
	array( 'tank_id' => 30, 'building_id' => null, 'setup_id' => null, 'note' => 'g' ),
);
check( 'the pool of a system: the building\'s own entries and those on any of its tanks', array( 'a', 'b' ) === array_column( Todo::pool( $rows, 5, array( 14, 15 ) ), 'note' ) && array( 'c', 'd' ) === array_column( Todo::pool( $rows, 6, array( 20 ) ), 'note' ) );
check( 'a setup\'s rows: on its tank, written while it stood there', array( 'e' ) === array_column( Todo::setup_rows( $rows, 30, 3 ), 'note' ) && array() === Todo::setup_rows( $rows, 30, 0 ) && array() === Todo::setup_rows( $rows, 31, 3 ) );
check( 'spread_logs gives a slot only its present setup\'s rows, and never a building-level one', array( 30 => array( $rows[4] ) ) === array_intersect_key( Store::spread_logs( $rows, array( array( 'id' => 30, 'building_id' => 7, 'own_water' => true, 'setup' => array( 'id' => 3 ) ), array( 'id' => 31, 'building_id' => 7, 'own_water' => true, 'setup' => null ) ) ), array( 30 => 1, 31 => 1 ) ) );
check( 'a rack tank still gets its own rows and its building\'s', array( 14 => array( $rows[0], $rows[1] ), 15 => array( $rows[0] ) ) === Store::spread_logs( array( $rows[0], $rows[1] ), array( array( 'id' => 14, 'building_id' => 5 ), array( 'id' => 15, 'building_id' => 5 ) ) ) );

$tanks = array(
	array( 'id' => 1, 'building_id' => 5, 'number' => 521, 'todo' => array( array( 'label' => 'Feed', 'kind' => 'feed', 'done' => false ), array( 'label' => 'Copper test', 'kind' => 'test', 'done' => false ) ) ),
	array( 'id' => 2, 'building_id' => 9, 'number' => 901, 'own_water' => true, 'todo' => array( array( 'label' => 'Copper test', 'kind' => 'test', 'done' => false ) ) ),
);
$over  = Todo::overlay( $tanks, array( 'blocks' => array( array( 'type' => 'system', 'building_id' => 5, 'items' => array( array( 'scope' => 'system', 'key' => 'copper test', 'done' => true ), array( 'scope' => 'feed', 'key' => 'feed', 'done' => true ) ) ), array( 'type' => 'system', 'building_id' => 9, 'items' => array( array( 'scope' => 'system', 'key' => 'copper test', 'done' => true ) ) ) ) ) );
check( 'the overlay marks the system\'s done steps on its tanks — not the feeding, not a slot', true === $over[0]['todo'][1]['done'] && false === $over[0]['todo'][0]['done'] && false === $over[1]['todo'][0]['done'] );
check( 'merge_copper: one water per building; a slot only its present setup\'s', array( 30 => array( 'value' => 1.0, 'logged_at' => '2026-04-01 08:00:00', 'tank_id' => 30 ), 1 => array( 'value' => 2.5, 'logged_at' => '2026-04-02 10:00:00', 'tank_id' => 3 ), 3 => array( 'value' => 2.5, 'logged_at' => '2026-04-02 10:00:00', 'tank_id' => 3 ) ) === Store::merge_copper(
	array(
		array( 'tank_id' => 1, 'building_id' => null, 'setup_id' => null, 'value' => '2.1', 'logged_at' => '2026-04-02 09:00:00' ),
		array( 'tank_id' => 3, 'building_id' => null, 'setup_id' => null, 'value' => '2.5', 'logged_at' => '2026-04-02 10:00:00' ),
		array( 'tank_id' => 0, 'building_id' => 5, 'setup_id' => null, 'value' => '2.2', 'logged_at' => '2026-04-01 10:00:00' ),
		array( 'tank_id' => 30, 'building_id' => null, 'setup_id' => 3, 'value' => '1.0', 'logged_at' => '2026-04-01 08:00:00' ),
		array( 'tank_id' => 30, 'building_id' => null, 'setup_id' => 2, 'value' => '9.9', 'logged_at' => '2026-04-03 08:00:00' ),
		array( 'tank_id' => 0, 'building_id' => 7, 'setup_id' => null, 'value' => '7.7', 'logged_at' => '2026-04-03 09:00:00' ),
	),
	array( array( 'id' => 1, 'building_id' => 5 ), array( 'id' => 3, 'building_id' => 5 ), array( 'id' => 9, 'building_id' => 6 ), array( 'id' => 30, 'building_id' => 7, 'own_water' => true, 'setup' => array( 'id' => 3 ) ), array( 'id' => 31, 'building_id' => 7, 'own_water' => true, 'setup' => null ) )
) );

$screen = Front_Desk::screen_from( $store, 0, $settings, $today );
check( 'the phone gets the same list on the tank picker', is_array( $screen['todoList'] ) && room( $store )['todo']['left'] === $screen['todoList']['left'] && array_column( room( $store )['todo']['blocks'], 'number' ) === array_column( $screen['todoList']['blocks'], 'number' ) && 0 === strpos( $screen['todoList']['text'], 'Tanks: ' ) );
check( 'a temporary tank\'s block links to its own screen', 'https://example.test/?fh_front_desk=1&tank=901' === block( $screen['todoList'], 901 )['url'] && '' === block( $screen['todoList'], 500 )['url'] );
$screen = Front_Desk::screen_from( $store, 521, $settings, $today );
check( 'and it rides along on a tank\'s screen too', is_array( $screen['todoList'] ) && 521 === $screen['tank']['number'] && false === $screen['tank']['ownWater'] && null === $screen['tank']['temp'] );

fh_finish();
