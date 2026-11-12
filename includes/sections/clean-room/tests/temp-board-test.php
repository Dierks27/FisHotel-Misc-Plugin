<?php
/**
 * Temporary tanks and the day's list — the Board's side and the seams:
 * the one AJAX write behind the Board (Admin_Temp::request(): the
 * capability and the nonce first, then the same switch the phone runs),
 * the Board's Temporary area as data, a slot with nothing set up taking
 * no reading, dose, tick or note, the slots in Settings → Layout (one
 * more, retiring one), "Used for" saved, renamed and deleted with its
 * template, the list's lines in words against fixtures/todo-lines.json
 * (the pages read the same file), a temporary tank spoken of by its
 * number, and the count other screens read: the
 * fishotel_cleanroom_todo filter and the Today section's line.
 *
 * Run from the plugin root:  php includes/sections/clean-room/tests/temp-board-test.php
 */

require_once __DIR__ . '/wp-stubs.php';

use FisHotel\Misc\Sections\Clean_Room\Admin;
use FisHotel\Misc\Sections\Clean_Room\Admin_Temp;
use FisHotel\Misc\Sections\Clean_Room\Clean_Room;
use FisHotel\Misc\Sections\Clean_Room\Front_Desk;
use FisHotel\Misc\Sections\Clean_Room\Layout;
use FisHotel\Misc\Sections\Clean_Room\Parser;
use FisHotel\Misc\Sections\Clean_Room\Replay;
use FisHotel\Misc\Sections\Clean_Room\Settings;
use FisHotel\Misc\Sections\Clean_Room\Temp_Tanks;
use FisHotel\Misc\Sections\Clean_Room\Todo;
use FisHotel\Misc\Sections\Clean_Room\Tests\Fake_Store;

$today     = Replay::date_in_zone( time(), wp_timezone() ); // The acts stamp events and logs with the real clock.
$settings  = Settings::get();
$protocols = Settings::protocols();
$fixture   = json_decode( (string) file_get_contents( __DIR__ . '/fixtures/todo-lines.json' ), true );

// User 8 may run the room; user 9 may not.
$GLOBALS['fh_user_caps'][ Clean_Room::CAPABILITY ] = array( 8 );
$GLOBALS['fh_current_user']                        = 8;

/**
 * One write from the Board, as its page posts it.
 */
function board( Fake_Store $store, array $raw, $nonce = null ) {
	global $today, $settings;
	return Admin_Temp::request( $store, array_merge( array( 'nonce' => null === $nonce ? wp_create_nonce( Admin::NONCE_AJAX ) : $nonce ), $raw ), $today, $settings );
}

/**
 * One write from the phone on a tank's page.
 */
function phone( Fake_Store $store, $tank_number, $act, array $raw ) {
	global $today, $settings;
	return Front_Desk::perform(
		$act,
		array(
			'store'    => $store,
			'settings' => $settings,
			'today'    => $today,
			'tank'     => $tank_number > 0 ? $store->tank_by_number( $tank_number ) : null,
			'base'     => array(
				'actor_id' => 8,
				'source'   => 'qr',
			),
		),
		$raw
	);
}

/* ------------------------------------------------------------------ */
echo "1. The Board's write: capability and nonce first\n";

$store = new Fake_Store( array( 12 => 'Blue Test Wrasse' ) );
$good  = array( 'act' => 'setup', 'name' => 'Test Hospital', 'purpose' => 'bacterial infection', 'kind' => 'quarantine', 'gallons' => '10', 'template' => 'Trifecta' );

$GLOBALS['fh_current_user'] = 9;
$r                          = board( $store, $good );
check( 'without manage_woocommerce: 403, nothing written', 403 === $r['status'] && ! $r['ok'] && array() === $store->open_setups() );
$GLOBALS['fh_current_user'] = 0;
$r                          = board( $store, $good );
check( 'logged out: 403', 403 === $r['status'] && ! $r['ok'] );
$GLOBALS['fh_current_user'] = 8;
$r                          = board( $store, $good, 'stale' );
check( 'a stale nonce: 403, the page says to reload, nothing written', 403 === $r['status'] && false !== strpos( $r['message'], 'expired' ) && array() === $store->open_setups() );
$r = board( $store, array( 'act' => 'setup' ) + array( 'nonce' => array( 'x' ) ) );
check( 'a nonce that is not text: 403', 403 === $r['status'] );
$r = board( $store, array( 'act' => 'arrival', 'tank' => 521, 'species' => 'Blue Test Wrasse', 'qty' => 2 ) );
check( 'only the temporary-tank and to-do acts run here: anything else is 400', 400 === $r['status'] && 'Unknown action.' === $r['message'] && 0 === count( $store->stays ) );
$r = board( $store, array_merge( $good, array( 'kind' => '' ) ) );
check( 'a setup without its kind: 400 with the reason', 400 === $r['status'] && false !== strpos( $r['message'], 'Pick one' ) && array() === $store->open_setups() );

$r    = board( $store, $good );
$open = $store->open_setups();
$a    = reset( $open );
check( 'set up from the Board: the first free slot, by the user who posted it', 200 === $r['status'] && $r['ok'] && 901 === $r['tank'] && 1 === count( $open ) && 'Test Hospital' === $a['name'] && 8 === (int) $a['created_by'] && false !== strpos( $r['message'], 'TEMP 1 · 901' ) );
$r = board( $store, array( 'act' => 'setup_edit', 'tank' => 901, 'gallons' => '12', 'note' => 'by the sink' ) );
check( 'Edit posts only what changed; the rest stays', 200 === $r['status'] && 12 === $store->setup( $a['id'] )['gallons'] && 'by the sink' === $store->setup( $a['id'] )['note'] && 'Test Hospital' === $store->setup( $a['id'] )['name'] && 'bacterial infection' === $store->setup( $a['id'] )['purpose'] );
$r = board( $store, array( 'act' => 'setup_edit', 'tank' => 901, 'start_date' => 'soon' ) );
check( 'a start date that is not a date: 400, the clock stays', 400 === $r['status'] && $today === $store->setup( $a['id'] )['start_date'] );
$r = board( $store, array( 'act' => 'setup_steps', 'tank' => 901, 'op' => 'save', 'steps' => array( array( 'label' => 'Feed', 'kind' => 'feed', 'day' => 1, 'every' => 1 ), array( 'label' => 'Kanamycin', 'kind' => 'dose', 'day' => 2 ) ) ) );
check( 'the schedule editor saves this tank\'s own list', 200 === $r['status'] && array( 'Feed', 'Kanamycin' ) === array_column( $store->setup( $a['id'] )['steps'], 'label' ) && $protocols === Settings::protocols() );
$r = board( $store, array( 'act' => 'setup_edit', 'tank' => 904, 'name' => 'x' ) );
check( 'a slot with nothing set up has nothing to edit: 400', 400 === $r['status'] && false !== strpos( $r['message'], 'Nothing is set up there' ) );

phone( $store, 901, 'arrival', array( 'product_id' => 12, 'qty' => 2 ) );
$r = board( $store, array( 'act' => 'takedown', 'tank' => 901 ) );
check( 'take down with fish in it: 400, "move them out first"', 400 === $r['status'] && false !== strpos( $r['message'], 'Move them out first' ) && 1 === count( $store->open_setups() ) );
phone( $store, 901, 'move', array( 'to_tank' => 521, 'all' => 1, 'reason' => 'hospital' ) );
$r = board( $store, array( 'act' => 'takedown', 'tank' => 901 ) );
check( 'empty: taken down, the slot is free, the history has it', 200 === $r['status'] && array() === $store->open_setups() && 1 === count( $store->closed_setups() ) && false !== strpos( $r['message'], 'Its history is kept' ) );
$r = board( $store, array( 'act' => 'takedown', 'setup_id' => $a['id'] ) );
check( 'a second take down of the same setup: 400, nothing changes', 400 === $r['status'] && 1 === count( $store->closed_setups() ) );

$building = (int) $store->tank_by_number( 521 )['building_id'];
$before   = count( $store->logs );
$r        = board( $store, array( 'act' => 'todo_tick', 'scope' => 'system', 'building' => $building, 'key' => 'copper power to 2.0', 'kind' => 'dose', 'label' => 'Copper Power to 2.0' ) );
$log      = end( $store->logs );
check( 'a tick on the To do tab is one entry on the building, by that user', 200 === $r['status'] && $before + 1 === count( $store->logs ) && 0 === (int) $log['tank_id'] && $building === (int) $log['building_id'] && 'dose' === $log['kind'] && 'copper power to 2.0' === $log['note'] && null === $log['value'] && 8 === (int) $log['actor_id'] && 'manual' === $log['source'] );
$room = $store->room( $today, $settings, $protocols );
$todo = array();
foreach ( $room['tanks'] as $tank ) {
	if ( 521 === (int) $tank['number'] ) {
		$todo = array_column( $tank['todo'], 'done', 'label' );
	}
}
check( 'and tank 521\'s own screen shows it ticked', true === ( $todo['Copper Power to 2.0'] ?? null ) && false === ( $todo['Feed'] ?? null ), json_encode( $todo ) );

/* ------------------------------------------------------------------ */
echo "2. The Board's Temporary area\n";

$store = new Fake_Store( array( 12 => 'Blue Test Wrasse' ) );
board( $store, $good );
board( $store, array( 'act' => 'setup', 'name' => 'Test Holding', 'kind' => 'clean', 'gallons' => '20' ) );
phone( $store, 901, 'arrival', array( 'product_id' => 12, 'qty' => 3 ) );
phone( $store, 901, 'dose', array( 'medication' => 'metro', 'amount' => '0.25', 'unit' => 'g', 'whole' => '1' ) );
$room  = $store->room( $today, $settings, $protocols );
$board = Admin_Temp::board( $store, $room['tanks'], $protocols, $today );
check( 'the building, every slot in slot order, two of them set up', 900 === (int) $board['building']['number'] && array( 901, 902, 903, 904, 905, 906 ) === array_column( $board['slots'], 'number' ) && 'Test Hospital' === $board['slots'][0]['setup']['name'] && 'clean' === $board['slots'][1]['setup']['kind'] && null === $board['slots'][2]['setup'] );
check( 'the set-up form offers the free slots, the tags that exist and every template with what it does', array( 903, 904, 905, 906 ) === array_column( $board['sheet']['free'], 'number' ) && 'TEMP 3 · 903' === $board['sheet']['free'][0]['label'] && array( 'bacterial infection' ) === $board['sheet']['tags'] && array_keys( $protocols['templates'] ) === array_column( $board['sheet']['templates'], 'name' ) && 6 === $board['sheet']['slots'] );
$trifecta = null;
foreach ( $board['sheet']['templates'] as $template ) {
	if ( 'Trifecta' === $template['name'] ) {
		$trifecta = $template;
	}
}
check( 'Trifecta carries its tag and its preview: the medications and the days, the feeding left out', array( 'bacterial infection' ) === $trifecta['tags'] && 3 === count( $trifecta['preview'] ) && 'day 1' === $trifecta['preview'][0]['when'] && 'Metronidazole' === $trifecta['preview'][0]['medication'] );
check( 'the last dose of a setup is that setup\'s own', 1 === count( $board['doses'] ) && isset( $board['doses'][ (int) $board['slots'][0]['setup']['id'] ] ) && array() === $store->latest_doses() );
check( 'nothing is in the history yet', 0 === $board['closed'] );
check( 'the dose went into the tank\'s own water, whatever the sheet posted', 1 === count( $store->logs ) && (int) $store->tank_by_number( 901 )['id'] === (int) end( $store->logs )['tank_id'] && (int) $board['slots'][0]['setup']['id'] === (int) end( $store->logs )['setup_id'] );
$plain = new Fake_Store( array(), false );
$none  = Admin_Temp::board( $plain, $plain->room( $today, $settings, $protocols )['tanks'], $protocols, $today );
check( 'a layout without the building: said plainly (no building, no slots, nothing to set up)', null === $none['building'] && array() === $none['slots'] && array() === $none['sheet']['free'] && 0 === $none['sheet']['slots'] );
$r = board( $plain, $good );
check( 'and a setup there is refused with the reason', 400 === $r['status'] && false !== strpos( $r['message'], 'no temporary slots' ) );
check( 'the To do tab and the history have their own URLs', false !== strpos( Admin_Temp::todo_url(), 'tab=todo' ) && false !== strpos( Admin_Temp::history_url(), 'temp_history=1' ) && false !== strpos( Admin_Temp::history_url( 7 ), 'setup=7' ) && Admin_Temp::todo_url() === Clean_Room::todo_url() );

/* ------------------------------------------------------------------ */
echo "3. A slot with nothing set up is a place, not a tank\n";

$store  = new Fake_Store( array( 12 => 'Blue Test Wrasse' ) );
$before = count( $store->logs );
$said   = 'Nothing is set up in TEMP 3 · 903 — set it up first.';
check( 'no copper reading', $said === ( phone( $store, 903, 'copper', array( 'value' => '2.4' ) )['error'] ?? '' ) );
check( 'no dose', $said === ( phone( $store, 903, 'dose', array( 'medication' => 'metro', 'amount' => '1', 'unit' => 'g' ) )['error'] ?? '' ) );
check( 'no tick', $said === ( phone( $store, 903, 'tick', array( 'key' => 'feed', 'kind' => 'feed', 'label' => 'Feed' ) )['error'] ?? '' ) );
check( 'no tank note', $said === ( phone( $store, 903, 'note', array( 'text' => 'looks fine' ) )['error'] ?? '' ) );
check( 'no check-in and no move into it', isset( phone( $store, 903, 'arrival', array( 'product_id' => 12, 'qty' => 1 ) )['error'] ) && 0 === count( $store->stays ) );
check( 'nothing was logged', $before === count( $store->logs ) );
check( 'free_slot_error: only the acts that log on the tank, only on a free slot', '' === Temp_Tanks::free_slot_error( 'setup', $store->tank_by_number( 903 ) ) && '' === Temp_Tanks::free_slot_error( 'copper', $store->tank_by_number( 521 ) ) && '' === Temp_Tanks::free_slot_error( 'copper', null ) && $said === Temp_Tanks::free_slot_error( 'dose', $store->tank_by_number( 903 ) ) );
board( $store, array( 'act' => 'setup', 'slot' => 903, 'kind' => 'quarantine', 'template' => '14 Day CM' ) );
check( 'once it is set up the same reading is taken — and is that setup\'s', empty( phone( $store, 903, 'copper', array( 'value' => '2.4' ) )['error'] ) && (int) $store->open_setups()[ (int) $store->tank_by_number( 903 )['id'] ]['id'] === (int) end( $store->logs )['setup_id'] );
check( 'a rack tank is as it was', empty( phone( $store, 521, 'copper', array( 'value' => '2.4' ) )['error'] ) && null === end( $store->logs )['setup_id'] );

/* ------------------------------------------------------------------ */
echo "4. Settings → Layout: the slots\n";

$store = new Fake_Store();
$r     = Settings::add_slot( $store );
check( 'one more slot: TEMP 7 · 907, and where to print its label', 907 === ( $r['number'] ?? 0 ) && false !== strpos( $r['message'], 'TEMP 7 · 907' ) && false !== strpos( $r['message'], 'Print tank labels' ) && 7 === count( Temp_Tanks::slots( $store->tanks() ) ) );
Settings::add_slot( $store );
Settings::add_slot( $store );
$r = Settings::add_slot( $store );
check( 'nine at most: the ones digit is the slot', isset( $r['errors'] ) && false !== strpos( $r['errors'][0], '9 temporary slots at most' ) && 9 === count( Temp_Tanks::slots( $store->tanks() ) ) );
$new = $store->tank_by_number( 909 );
check( 'a new slot is a slot like the seeded ones: no rack level, no size, free', $new['own_water'] && 0 === (int) $new['level'] && 9 === (int) $new['slot'] && null === $new['setup'] && 'TEMP 9 · 909' === Layout::tank_label( $new ) );
$r = Settings::save_slot( $store, $store->tank_by_number( 909 ), array( 'status' => 'offline' ) );
check( 'a free slot can be retired', empty( $r['errors'] ) && 'offline' === $store->tank_by_number( 909 )['status'] && ! in_array( 909, array_column( Temp_Tanks::free_slots( $store->tanks() ), 'number' ), true ) );
$r = Settings::save_slot( $store, $store->tank_by_number( 909 ), array( 'status' => 'bogus' ) );
check( 'an unknown status is refused', isset( $r['errors'] ) && 'offline' === $store->tank_by_number( 909 )['status'] );
board( $store, array( 'act' => 'setup', 'slot' => 902, 'kind' => 'clean', 'name' => 'Test Holding' ) );
$r = Settings::save_slot( $store, $store->tank_by_number( 902 ), array( 'status' => 'offline' ) );
check( 'a slot that is set up cannot be retired: take it down first', isset( $r['errors'] ) && false !== strpos( $r['errors'][0], 'TEMP 2 · 902 is set up' ) && 'active' === $store->tank_by_number( 902 )['status'] );
$r = board( $store, array( 'act' => 'setup', 'slot' => 909, 'kind' => 'clean' ) );
check( 'a retired slot takes no setup', 400 === $r['status'] && false !== strpos( $r['message'], 'not a temporary slot in use' ) );
$r = Settings::add_slot( new Fake_Store( array(), false ) );
check( 'no building for them: said, nothing added', isset( $r['errors'] ) && false !== strpos( $r['errors'][0], 'no building for temporary tanks' ) );

/* ------------------------------------------------------------------ */
echo "5. \"Used for\" follows its template\n";

$GLOBALS['fh_options'] = array();
$protocols             = Settings::protocols();
Settings::follow_template_tags( 'save', array( 'template' => 'CQ' ), array( 'used_for' => 'velvet, Ich' ), $protocols );
check( 'saved with the template\'s steps', array( 'velvet', 'Ich' ) === Temp_Tanks::tags( $protocols )['CQ'] && array( 'bacterial infection' ) === Temp_Tanks::tags( $protocols )['Trifecta'] );
Settings::follow_template_tags( 'save', array( 'template' => 'CQ' ), array(), $protocols );
check( 'a save that posts no "Used for" field leaves the tags alone', array( 'velvet', 'Ich' ) === Temp_Tanks::tags( $protocols )['CQ'] );
Settings::follow_template_tags( 'make_default', array( 'template' => 'CQ' ), array( 'used_for' => 'x' ), $protocols );
check( 'only a save writes them', array( 'velvet', 'Ich' ) === Temp_Tanks::tags( $protocols )['CQ'] );
$renamed                                = $protocols;
$renamed['templates']['CQ long']        = $renamed['templates']['CQ'];
unset( $renamed['templates']['CQ'] );
Settings::follow_template_tags( 'rename', array( 'template' => 'CQ long', 'renamed' => array( 'CQ', 'CQ long' ) ), array(), $renamed );
check( 'a rename carries them to the new name', array( 'velvet', 'Ich' ) === Temp_Tanks::tags( $renamed )['CQ long'] && ! isset( Temp_Tanks::stored_tags()['CQ'] ) );
check( 'the setup sheet offers the tags as they now stand, A–Z', array( 'bacterial infection', 'Ich', 'velvet' ) === Front_Desk::temp_sheet( array(), $renamed, $today )['tags'] );
Settings::follow_template_tags( 'delete', array( 'template' => 'Trifecta', 'renamed' => array( 'CQ long', '' ) ), array(), $protocols );
check( 'a delete drops them', ! isset( Temp_Tanks::stored_tags()['CQ long'] ) );
$GLOBALS['fh_options'] = array();
$protocols             = Settings::protocols();

/* ------------------------------------------------------------------ */
echo "6. The list's lines in words (fixtures/todo-lines.json — the pages read it too)\n";

check( 'the fixture loads', is_array( $fixture ) && 4 === count( $fixture['details'] ) && 5 === count( $fixture['feeds'] ) && 5 === count( $fixture['flags'] ) && 7 === count( $fixture['offers']['cases'] ) );
foreach ( $fixture['details'] as $i => $case ) {
	check_same( 'line_detail #' . ( $i + 1 ) . ': "' . $case['text'] . '"', $case['text'], Admin_Temp::line_detail( $case['item'] ) );
}
foreach ( $fixture['feeds'] as $i => $case ) {
	check_same( 'feed_text #' . ( $i + 1 ) . ': "' . $case['text'] . '"', $case['text'], Admin_Temp::feed_text( $case['item'] ) );
}
foreach ( $fixture['flags'] as $i => $case ) {
	check_same( 'flag_text #' . ( $i + 1 ) . ': "' . $case['text'] . '"', $case['text'], Admin_Temp::flag_text( $case['flag'] ) );
}
$map = array();
foreach ( $fixture['offers']['templates'] as $template ) {
	$map[ $template['name'] ] = $template['tags'];
}
foreach ( $fixture['offers']['cases'] as $case ) {
	check_same( 'templates_for "' . $case['tag'] . '"', $case['matching'], Temp_Tanks::templates_for( $case['tag'], $map ) );
}
check( 'copper_text: the reading, the tank it was taken on, its age', 'Cu 2.4 · tank 513 · 3 h ago' === Admin_Temp::copper_text( array( 'value' => 2.4, 'tank' => 513, 'ago' => '3 h' ) ) && 'Cu 2' === Admin_Temp::copper_text( array( 'value' => 2.0, 'tank' => 0, 'ago' => '' ) ) && '' === Admin_Temp::copper_text( array( 'value' => null ) ) );

/* ------------------------------------------------------------------ */
echo "7. By voice: a temporary tank is spoken of by its number\n";

// The tanks the phone hands the parser: a slot that is set up, with the area it behaves as; a free slot is not in the list.
$heard   = fh_seed_tanks();
$heard[] = array( 'id' => 23, 'building_id' => 7, 'number' => 901, 'building' => 900, 'area' => Temp_Tanks::kind_area( 'quarantine' ), 'level' => 0, 'position' => 1, 'status' => 'active', 'sort' => 1 );
$heard[] = array( 'id' => 24, 'building_id' => 7, 'number' => 902, 'building' => 900, 'area' => Temp_Tanks::kind_area( 'clean' ), 'level' => 0, 'position' => 2, 'status' => 'active', 'sort' => 2 );
$said    = static function ( $text ) use ( $heard ) {
	return Parser::parse(
		$text,
		array(
			'tank'      => 521,
			'tanks'     => $heard,
			'species'   => array(
				array(
					'product_id' => 12,
					'name'       => 'Blue Test Wrasse',
					'variants'   => array(
						array(
							'text' => 'Blue Test Wrasse',
							'kind' => 'title',
						),
					),
				),
			),
			'occupants' => array(
				array(
					'stay_id'    => 7,
					'species'    => 'Blue Test Wrasse',
					'product_id' => 12,
					'qty_live'   => 4,
				),
			),
		)
	);
};
$r = $said( 'four blue test wrasse in tank 901' );
check( 'an arrival into a quarantine temporary tank is taken as said', 'arrival' === $r['intent'] && 901 === $r['tank'] && ! in_array( 'tank', $r['needs'], true ) );
$r = $said( 'four blue test wrasse in tank 902' );
check( 'into a clean one it asks for a quarantine tank, as for the Clean Room', 'arrival' === $r['intent'] && in_array( 'tank', $r['needs'], true ) );
$r = $said( 'four blue test wrasse in tank 903' );
check( 'a slot with nothing set up is no tank to check in to', 'arrival' === $r['intent'] && in_array( 'tank', $r['needs'], true ) );
$r = $said( 'move the wrasse to 901' );
check( 'a move names it as its destination', 'move' === $r['intent'] && 901 === $r['to_tank'] );
$r = $said( '901 copper 2.4' );
check( 'a reading on it', 'copper' === $r['intent'] && 901 === $r['tank'] && 2.4 === (float) $r['value'] );

/* ------------------------------------------------------------------ */
echo "8. The count other screens read\n";

$store = new Fake_Store( array( 12 => 'Blue Test Wrasse' ) );
phone( $store, 521, 'arrival', array( 'product_id' => 12, 'qty' => 4 ) );
$list    = $store->room( $today, $settings, $protocols )['todo'];
$summary = Todo::summary( $list, Clean_Room::todo_url() );
check( 'the summary: what is left, in all, the flags, one sentence, where the list is', $list['left'] === $summary['left'] && $summary['left'] > 0 && $list['total'] === $summary['total'] && ! $summary['all_done'] && count( $list['flags'] ) === $summary['flags'] && 'Tanks: ' . $summary['left'] . ' things to do' === $summary['text'] && 'https://example.test/wp-admin/admin.php?page=fishotel-misc-clean-room&tab=todo' === $summary['url'] );

$line = Todo::today_line( $summary );
check( 'the Today section\'s line: "Tanks: N things to do", linked to the To do tab', 'clean-room-todo' === $line['key'] && $summary['text'] === $line['text'] && $summary['url'] === $line['url'] && $summary['left'] === $line['count'] );
$flagged = Todo::today_line( array( 'left' => 3, 'total' => 5, 'flags' => 1, 'text' => 'Tanks: 3 things to do', 'url' => '' ) );
check( 'a copper flag is said beside it, and colours it', '1 copper flag' === $flagged['detail'] && 'bad' === $flagged['tone'] && '2 copper flags' === Todo::today_line( array( 'left' => 3, 'total' => 5, 'flags' => 2, 'text' => 'x', 'url' => '' ) )['detail'] );
check( 'no flag: "todo" while something is left, "done" once all is ticked', 'todo' === Todo::today_line( array( 'left' => 3, 'total' => 5, 'flags' => 0, 'text' => 'Tanks: 3 things to do', 'url' => '' ) )['tone'] && 'done' === Todo::today_line( array( 'left' => 0, 'total' => 5, 'flags' => 0, 'text' => 'Tanks: all done', 'url' => '' ) )['tone'] && '' === Todo::today_line( array( 'left' => 0, 'total' => 5, 'flags' => 0, 'text' => 'Tanks: all done', 'url' => '' ) )['detail'] );
check( 'a day with nothing due adds no line', null === Todo::today_line( Todo::summary( ( new Fake_Store() )->room( $today, $settings, $protocols )['todo'] ) ) && null === Todo::today_line( array() ) );

$others = array( array( 'key' => 'other-section', 'text' => 'Something else' ) );
check( 'the line joins the lines that are there', array( $others[0], $line ) === Todo::with_today_line( $others, $line ) );
check( 'never twice: a line under the same key is replaced', array( $others[0], $line ) === Todo::with_today_line( array( array( 'key' => 'clean-room-todo', 'text' => 'stale' ), $others[0] ), $line ) );
check( 'no line: the others pass through untouched; junk in the filter is an empty list', $others === Todo::with_today_line( $others, null ) && array( $line ) === Todo::with_today_line( 'junk', $line ) && array() === Todo::with_today_line( null, null ) );

check( 'the filter names', 'fishotel_cleanroom_todo' === Todo::SUMMARY_FILTER && 'fishotel_today_lines' === Todo::TODAY_FILTER );
$answered = array( 'left' => 1 );
check( 'fishotel_cleanroom_todo: an answer that is already there stands', $answered === Clean_Room::filter_todo( $answered ) );
check( 'the global function is there while the section is loaded', function_exists( 'fishotel_cleanroom_todo' ) );
// A database without the section's tables: both answer nothing — and never throw.
$GLOBALS['wpdb'] = new class() {
	public $prefix = 'wp_';
	public function prepare( $query, ...$args ) {
		return $query;
	}
	public function get_var( $query ) {
		return null;
	}
};
check( 'without the tables: null from the function and from the filter, never an exception', null === fishotel_cleanroom_todo() && null === Clean_Room::filter_todo( null ) );
check( 'and the Today section\'s lines pass through untouched', $others === Clean_Room::filter_today_lines( $others ) && array() === Clean_Room::filter_today_lines( 'junk' ) );
$GLOBALS['fh_current_user'] = 9;
check( 'a user who may not open the room gets no line (and costs no query)', $others === Clean_Room::filter_today_lines( $others ) );
$GLOBALS['fh_current_user'] = 8;

fh_finish();
