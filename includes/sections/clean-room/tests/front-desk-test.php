<?php
/**
 * Front Desk — the server guards behind the phone's one write action,
 * driven through Front_Desk::perform() against the in-memory Fake_Store:
 * Spa-only arrivals, the same-tank move, move reasons, the overdrawn
 * flag when two phones record against the same count, undo ownership /
 * the 15-minute window / idempotence, the cleared and check-in dates by
 * hand, the copper reading that ticks the copper test, the scanned-label
 * cookie life, and the facts filter answering null for a product with no
 * stay.
 *
 * Run from the plugin root:  php includes/sections/clean-room/tests/front-desk-test.php
 */

require_once __DIR__ . '/wp-stubs.php';

use FisHotel\Misc\Sections\Clean_Room\Admin;
use FisHotel\Misc\Sections\Clean_Room\Clean_Room;
use FisHotel\Misc\Sections\Clean_Room\Front_Desk;
use FisHotel\Misc\Sections\Clean_Room\Protocol;
use FisHotel\Misc\Sections\Clean_Room\Replay;
use FisHotel\Misc\Sections\Clean_Room\Settings;
use FisHotel\Misc\Sections\Clean_Room\Tests\Fake_Store;

$today    = Replay::date_in_zone( time(), wp_timezone() ); // The site date right now: the acts stamp events with the real clock.
$settings = Settings::get();
$store    = new Fake_Store( array( 12 => 'Pajama Cardinalfish', 13 => 'Ocellaris Clownfish' ) );

/**
 * A perform() context on a tank's page.
 */
function ctx( Fake_Store $store, $tank_number, $actor = 8, $source = 'qr' ) {
	global $settings, $today;
	return array(
		'store'    => $store,
		'settings' => $settings,
		'today'    => $today,
		'tank'     => $store->tank_by_number( $tank_number ),
		'base'     => array(
			'actor_id' => $actor,
			'source'   => $source,
		),
	);
}

function act( Fake_Store $store, $tank_number, $act, array $raw, $actor = 8 ) {
	return Front_Desk::perform( $act, ctx( $store, $tank_number, $actor ), $raw );
}

$spa   = $store->tank_by_number( 521 );
$clean = $store->tank_by_number( 121 );

echo "1. Arrivals go into a Spa tank\n";
$r = act( $store, 121, 'arrival', array( 'species' => 'Pajama Cardinalfish', 'qty' => 4 ) );
check( 'from a Clean Room tank\'s page: refused, offered the Spa', 'Arrivals go into a Spa tank. Pick one.' === ( $r['error'] ?? '' ), json_encode( $r ) );
$r = act( $store, 121, 'arrival', array( 'species' => 'Pajama Cardinalfish', 'qty' => 4, 'to_tank' => 122 ) );
check( 'to_tank naming another Clean Room tank: still refused', isset( $r['error'] ) );
check( 'nothing was written', 0 === count( $store->stays ) && 0 === $store->event_count() );
$r = act( $store, 121, 'arrival', array( 'product_id' => 12, 'qty' => 4, 'to_tank' => 521 ) );
check( 'to_tank naming a Spa tank: checked in there, species from the product', empty( $r['error'] ) && '4 × Pajama Cardinalfish in Tank 521' === $r['message'] && 521 === $r['tank'] && array( 1 ) === $r['event_ids'], json_encode( $r ) );
$s = $store->stay( 1, $today, $settings );
check( 'the stay: 4 live, in QT, tank 521, product 12, check-in today, day 1', 4 === $s['qty_live'] && 'in_qt' === $s['status'] && (int) $spa['id'] === $s['tank_id'] && 12 === $s['product_id'] && $today === $s['checkin_date'] && 1 === $s['day'] );
$r = act( $store, 521, 'arrival', array( 'product_id' => 99, 'qty' => 2 ) );
check( 'an unknown product id is never assumed: no species → Which fish?', 'Which fish? Type or pick a species.' === ( $r['error'] ?? '' ) );
$r = act( $store, 521, 'arrival', array( 'species' => 'Yellow Tang', 'qty' => 0 ) );
check( 'qty 0 refused', 'How many? 1–999.' === ( $r['error'] ?? '' ) );
$r = act( $store, 521, 'arrival', array( 'species' => 'Ocellaris Clownfish', 'product_id' => 13, 'qty' => 2, 'date' => Replay::add_days( $today, -4 ) ) );
$s = $store->stay( 2, $today, $settings );
check( 'a backdated arrival: check-in that date, day 5', empty( $r['error'] ) && Replay::add_days( $today, -4 ) === $s['checkin_date'] && 5 === $s['day'] );

echo "2. Move guards and reasons\n";
$r = act( $store, 521, 'move', array( 'to_tank' => 521, 'stay_id' => 1 ) );
check( 'to the same tank: refused', 'That is this tank.' === ( $r['error'] ?? '' ) );
$r = act( $store, 521, 'move', array( 'to_tank' => 999, 'stay_id' => 1 ) );
check( 'to an unknown tank: refused', 'Where to? Pick a tank.' === ( $r['error'] ?? '' ) );
$r = act( $store, 522, 'move', array( 'to_tank' => 121, 'stay_id' => 1 ) );
check( 'a stay not in this tank: Which fish?', 'Which fish? Pick one in this tank.' === ( $r['error'] ?? '' ) );
$r = act( $store, 521, 'move', array( 'to_tank' => 121, 'stay_id' => 1, 'qty' => 9 ) );
check( 'more than are here: refused', 'Only 4 here.' === ( $r['error'] ?? '' ) );
$r = act( $store, 521, 'move', array( 'to_tank' => 121, 'stay_id' => 1, 'reason' => 'bullying' ) );
check( 'Spa → Clean Room with a reason: moved, cleared today, the reason in the toast', empty( $r['error'] ) && 'Pajama Cardinalfish moved to Tank 121 · cleared today · bullying' === $r['message'], json_encode( $r ) );
$e = $store->last_event();
$s = $store->stay( 1, $today, $settings );
check( 'the move event carries the reason; the stay is cleared today in 121', 'move' === $e['type'] && 'bullying' === $e['note'] && 521 === $e['from_tank_number'] && 121 === $e['to_tank_number'] && $today === $s['cleared_date'] && 'cleared' === $s['status'] && (int) $clean['id'] === $s['tank_id'] );
$r = act( $store, 121, 'move', array( 'to_tank' => 122, 'stay_id' => 1, 'reason' => 'hungry' ) );
check( 'an unknown reason is dropped, not stored', empty( $r['error'] ) && '' === $store->last_event()['note'] && 'Pajama Cardinalfish moved to Tank 122' === $r['message'] );
$r = act( $store, 122, 'clear', array( 'stay_id' => 1, 'unset' => 1 ) );
check( 'the cleared date unset by hand', empty( $r['error'] ) && null === $store->stay( 1 )['cleared_date'] );
$r = act( $store, 122, 'move', array( 'to_tank' => 123, 'stay_id' => 1 ) );
check( 'after the unset a Clean → Clean move does NOT re-clear', empty( $r['error'] ) && null === $store->stay( 1 )['cleared_date'] && false === strpos( $r['message'], 'cleared' ), json_encode( $r ) );
act( $store, 123, 'move', array( 'to_tank' => 521, 'stay_id' => 1 ) );
$r = act( $store, 521, 'move', array( 'to_tank' => 121, 'stay_id' => 1, 'reason' => 'clear_building' ) );
check( 'back through the Spa into the Clean Room clears again', $today === $store->stay( 1 )['cleared_date'] && 'Pajama Cardinalfish moved to Tank 121 · cleared today · clear building' === $r['message'], json_encode( $r ) );
$r = act( $store, 121, 'move', array( 'to_tank' => 122, 'stay_id' => 1, 'qty' => 1, 'reason' => 'pairing' ) );
check( 'a split with a reason', empty( $r['error'] ) && '1 × Pajama Cardinalfish moved to Tank 122 (the rest stay here) · pairing' === $r['message'] && 3 === count( $r['event_ids'] ), json_encode( $r ) );
$orig   = $store->stay( 1, $today, $settings );
$sister = $store->stay( 3, $today, $settings );
check( 'the original keeps 3, the sister has 1 in 122 with the same check-in and cleared date', 3 === $orig['qty_live'] && 1 === $sister['qty_live'] && 122 === $sister['tank_number'] && $orig['checkin_date'] === $sister['checkin_date'] && $orig['cleared_date'] === $sister['cleared_date'] );
check( 'both move events name the reason', 'split to #3 · pairing' === $store->event( $r['event_ids'][0] )['note'] && 'pairing' === $store->event( $r['event_ids'][2] )['note'] );
$r = act( $store, 121, 'move', array( 'to_tank' => 122, 'all' => 1 ) );
check( 'move all: every open group here', empty( $r['error'] ) && 1 === count( $r['event_ids'] ) && 122 === $store->stay( 1 )['tank_number'] );

echo "3. Loss — the guard, and the overdrawn flag when two phones race\n";
$r = act( $store, 122, 'loss', array( 'stay_id' => 1, 'qty' => 9 ) );
check( 'more than are alive: refused', 'How many? 1–3.' === ( $r['error'] ?? '' ) );
$r = act( $store, 122, 'loss', array( 'stay_id' => 1, 'qty' => 1, 'reason' => 'jumped' ) );
check( '−1 lost (jumped): 2 live, no overdraw', empty( $r['error'] ) && '1 × Pajama Cardinalfish lost (jumped)' === $r['message'] && empty( $r['overdrawn'] ) && 2 === $store->stay( 1 )['qty_live'] );
// The other phone's loss of 2 lands after this phone read "2 live" and before it writes.
$store->append_only( 1, array( 'type' => 'loss', 'qty' => 2, 'actor_id' => 7, 'event_at' => gmdate( 'Y-m-d H:i:s' ) ) );
$r = act( $store, 122, 'loss', array( 'stay_id' => 1, 'qty' => 2 ) );
check( 'the entry is kept, flagged overdrawn, and the toast asks for a recount', empty( $r['error'] ) && true === $r['overdrawn'] && false !== strpos( $r['message'], 'Tank 122 now shows fewer fish than were taken out — recount it' ), json_encode( $r ) );
$s = $store->stay( 1, $today, $settings );
check( 'the stay reads 0 live and closed, never negative', 0 === $s['qty_live'] && 'closed' === $s['status'] );
$dup = $store->last_event();
$u   = $store->undo_event( $s, $dup, array( 'actor_id' => 1, 'source' => 'manual' ), $today, $settings );
check( 'undoing the duplicate (as the admin does) clears the overdraw: the two really died, 0 live, closed, no longer overdrawn', $u && false === $u['overdrawn'] && 0 === $store->stay( 1 )['qty_live'] && 'closed' === $store->stay( 1 )['status'] && false === $store->stay( 1, $today, $settings )['sale_ready'] );

echo "4. Undo: ownership, the window, idempotence (the clownfish, 2 live in 521)\n";
$before = $store->event_count();
$r      = act( $store, 521, 'loss', array( 'stay_id' => 2, 'qty' => 1 ) );
$loss   = $r['event_ids'][0];
$r      = act( $store, 521, 'undo', array( 'event_ids' => array( $loss ) ), 9 );
check( 'someone else\'s event: refused, nothing written', 'That can no longer be undone.' === ( $r['error'] ?? '' ) && $before + 1 === $store->event_count() && 1 === $store->stay( 2 )['qty_live'] );
$old = $store->record_event( 2, array( 'type' => 'loss', 'qty' => 1, 'actor_id' => 8, 'source' => 'qr', 'event_at' => gmdate( 'Y-m-d H:i:s' ), 'created_at' => gmdate( 'Y-m-d H:i:s', time() - Front_Desk::UNDO_WINDOW - 60 ) ), $today, $settings );
$r   = act( $store, 521, 'undo', array( 'event_ids' => array( $old['event_id'] ) ) );
check( 'an event older than the window (15 min): refused', 'That can no longer be undone.' === ( $r['error'] ?? '' ) && 0 === $store->stay( 2 )['qty_live'] && 'closed' === $store->stay( 2 )['status'] );
$r = act( $store, 521, 'undo', array( 'event_ids' => array( $loss ) ) );
$c = $store->last_event();
check( 'this user\'s fresh loss: undone with a compensating event, the group re-opens', empty( $r['error'] ) && 'Undone' === $r['message'] && 'loss' === $c['type'] && -1 === $c['qty'] && 'undo of #' . $loss === $c['note'] && 1 === $store->stay( 2 )['qty_live'] && 'closed' !== $store->stay( 2 )['status'] );
$n = $store->event_count();
$r = act( $store, 521, 'undo', array( 'event_ids' => array( $loss ) ) );
check( 'the same id posted again: "Already undone", nothing written twice', empty( $r['error'] ) && 'Already undone' === $r['message'] && $n === $store->event_count() && 1 === $store->stay( 2 )['qty_live'], json_encode( $r ) );
$r = act( $store, 521, 'undo', array( 'event_ids' => array( $loss, $loss, 0, 'x' ) ) );
check( 'duplicates and junk in the list change nothing either', 'Already undone' === ( $r['message'] ?? '' ) && $n === $store->event_count() );
$r    = act( $store, 521, 'move', array( 'to_tank' => 523, 'stay_id' => 2 ) );
$move = $r['event_ids'][0];
$r    = act( $store, 523, 'undo', array( 'event_ids' => array( $move ) ) );
check( 'undoing a whole move sends the screen back to the source tank', empty( $r['error'] ) && 521 === ( $r['tank'] ?? 0 ) && 521 === $store->stay( 2 )['tank_number'] );
check( 'is_undone sees it; a plain note has nothing to undo', Replay::is_undone( $store->events( 2 ), $move ) && null === $store->undo_event( $store->stay( 2, $today, $settings ), $store->event( $store->record_event( 2, array( 'type' => 'note', 'note' => 'looking thin', 'event_at' => gmdate( 'Y-m-d H:i:s' ) ), $today, $settings )['event_id'] ), array(), $today, $settings ) );
$r = act( $store, 521, 'undo', array( 'event_ids' => array() ) );
check( 'no ids → nothing to undo', 'Nothing to undo.' === ( $r['error'] ?? '' ) );

echo "5. The cleared and check-in dates by hand\n";
$r = act( $store, 521, 'clear', array( 'stay_id' => 2, 'date' => Replay::add_days( $today, -2 ) ) );
check( 'a cleared date typed: recorded now with the date in the note', empty( $r['error'] ) && 'cleared:' . Replay::add_days( $today, -2 ) === $store->last_event()['note'] && Replay::add_days( $today, -2 ) === $store->stay( 2 )['cleared_date'], json_encode( $r ) );
$r = act( $store, 521, 'clear', array( 'stay_id' => 2, 'date' => Replay::add_days( $today, -3 ) ) );
check( 'corrected to an EARLIER date: honoured', Replay::add_days( $today, -3 ) === $store->stay( 2 )['cleared_date'] );
act( $store, 521, 'clear', array( 'stay_id' => 2, 'unset' => 1 ) );
$r = act( $store, 521, 'clear', array( 'stay_id' => 2, 'date' => Replay::add_days( $today, -1 ) ) );
check( 'set again after an unset: honoured', Replay::add_days( $today, -1 ) === $store->stay( 2 )['cleared_date'] );
$r = act( $store, 521, 'clear', array( 'stay_id' => 2 ) );
check( 'no date → today, the toast says so', empty( $r['error'] ) && 'Ocellaris Clownfish cleared today' === $r['message'] && $today === $store->stay( 2 )['cleared_date'] );
$r = act( $store, 521, 'checkin', array( 'stay_id' => 2, 'date' => Replay::add_days( $today, 1 ) ) );
check( 'a check-in in the future: refused', 'A check-in cannot be in the future.' === ( $r['error'] ?? '' ) );
$r = act( $store, 521, 'checkin', array( 'stay_id' => 2, 'date' => 'soon' ) );
check( 'not a date: refused', 'That is not a date.' === ( $r['error'] ?? '' ) );
$r = act( $store, 521, 'checkin', array( 'stay_id' => 2, 'date' => Replay::add_days( $today, -10 ) ) );
$s = $store->stay( 2, $today, $settings );
check( 'a check-in corrected: the note, the date, day 11, the SUGGESTED sale date 28 days after it (no date chosen, so none and not for sale)', empty( $r['error'] ) && 'checkin:' . Replay::add_days( $today, -10 ) === $store->last_event()['note'] && Replay::add_days( $today, -10 ) === $s['checkin_date'] && 11 === $s['day'] && Replay::add_days( $today, 18 ) === $s['sale_ready_suggested'] && null === $s['sale_ready_date'] && false === $s['sale_ready'], json_encode( $s ) );
$r = act( $store, 521, 'undo', array( 'event_ids' => $r['event_ids'] ) );
check( 'undone: back to the arrival\'s date (day 5)', empty( $r['error'] ) && 5 === $store->stay( 2, $today, $settings )['day'] );
$r = act( $store, 521, 'ready', array( 'stay_id' => 2, 'date' => Replay::add_days( $today, 3 ) ) );
check( 'an own sale date set: "on sale <date>", the note event, not for sale yet', 'Ocellaris Clownfish on sale ' . Replay::add_days( $today, 3 ) === ( $r['message'] ?? '' ) && 'sale_ready:' . Replay::add_days( $today, 3 ) === $store->last_event()['note'] && false === $store->stay( 2, $today, $settings )['sale_ready'], json_encode( $r ) );
$r = act( $store, 521, 'ready', array( 'stay_id' => 2, 'date' => '' ) );
check( 'the own date removed with no batch date: the toast says there is no sale date now and where to set one', 'Ocellaris Clownfish: no sale date now — set one on its supplier order' === ( $r['message'] ?? '' ) && null === $store->stay( 2, $today, $settings )['sale_ready_date'], json_encode( $r ) );
$r = act( $store, 521, 'ready', array( 'stay_id' => 2, 'date' => 'soon' ) );
check( 'a sale date that is not a date: refused', 'That is not a date.' === ( $r['error'] ?? '' ) );

echo "6. A copper reading ticks the copper test\n";
$r = act( $store, 521, 'copper', array( 'value' => '2.4' ) );
check( 'logged on the tank', empty( $r['error'] ) && 'Copper 2.4 in Tank 521' === $r['message'] && 'copper' === end( $store->logs )['kind'] && 2.4 === end( $store->logs )['value'] );
$todo = Protocol::todo( Protocol::seed(), array( array( 'id' => 2, 'species' => 'Ocellaris Clownfish', 'qty_live' => 2, 'checkin_date' => $today, 'day' => 4 ) ), $today, 'spa', array( end( $store->logs ) ) );
$done = array();
foreach ( $todo as $item ) {
	$done[ $item['label'] ] = $item['done'];
}
check( 'today\'s to-do: the copper test is done, feed and metronidazole are not', true === $done['Copper test'] && false === $done['Feed'] && false === $done['Metronidazole'], json_encode( $done ) );
$r = act( $store, 521, 'copper', array( 'value' => '2.1' ) );
check( 'below the floor is said', false !== strpos( $r['message'], 'below the floor' ) );
$r = act( $store, 521, 'tick', array( 'key' => 'Feed', 'kind' => 'feed', 'label' => 'Feed' ) );
check( 'a tick-off is a tank log with the key', empty( $r['error'] ) && 'feed' === end( $store->logs )['kind'] && 'feed' === end( $store->logs )['note'] );
$r = act( $store, 521, 'bogus', array() );
check( 'an unknown action', 'Unknown action.' === ( $r['error'] ?? '' ) );

echo "6b. A dose on the whole system or on the tank — no undo\n";
$r = act( $store, 521, 'dose', array( 'medication' => 'aspirin', 'amount' => '5', 'unit' => 'g', 'whole' => 1 ) );
check( 'an unknown medication: refused', 'Which medication? Pick one.' === ( $r['error'] ?? '' ) );
$bad = 0;
foreach ( array( '0', '-1', '10000', 'abc', '' ) as $amount ) {
	$r = act( $store, 521, 'dose', array( 'medication' => 'metro', 'amount' => $amount, 'unit' => 'g', 'whole' => 1 ) );
	if ( 'How much? An amount above 0 and at most 9999.' === ( $r['error'] ?? '' ) ) {
		$bad++;
	}
}
check( 'an amount of 0, below 0, above 9999, junk or blank: refused', 5 === $bad );
$r = act( $store, 521, 'dose', array( 'medication' => 'metro', 'amount' => '5', 'unit' => 'cups', 'whole' => 1 ) );
check( 'a unit other than g / ml / tsp: refused', 'Which unit? g, ml or tsp.' === ( $r['error'] ?? '' ) );
$logs_before = count( $store->logs );
$r = act( $store, 521, 'dose', array( 'medication' => 'metro', 'amount' => '5.1', 'unit' => 'g', 'whole' => 1 ) );
$log = end( $store->logs );
check( 'whole system: the toast "Metronidazole 5.1 g · Building 500, whole system", nothing to undo', empty( $r['error'] ) && 'Metronidazole 5.1 g · Building 500, whole system' === $r['message'] && array() === $r['event_ids'], json_encode( $r ) );
check( 'the log: kind dose, tank 0, the building of 521, value 5.1, the note "Metronidazole 5.1 g", source qr, actor 8', $logs_before + 1 === count( $store->logs ) && 'dose' === $log['kind'] && 0 === $log['tank_id'] && (int) $spa['building_id'] === $log['building_id'] && 5.1 === $log['value'] && 'Metronidazole 5.1 g' === $log['note'] && 'qr' === $log['source'] && 8 === $log['actor_id'], json_encode( $log ) );
$r = act( $store, 521, 'dose', array( 'medication' => 'copper', 'amount' => '55', 'unit' => 'ml', 'whole' => 0 ) );
$log = end( $store->logs );
check( 'this tank only: "Copper Power 55 ml · Tank 521", the log on the tank with no building', 'Copper Power 55 ml · Tank 521' === ( $r['message'] ?? '' ) && (int) $spa['id'] === $log['tank_id'] && null === $log['building_id'] && 55.0 === $log['value'] && 'Copper Power 55 ml' === $log['note'] );
$r = act( $store, 521, 'dose', array( 'medication' => 'formalin', 'amount' => '20.5', 'unit' => 'ml' ) );
check( 'no whole flag = this tank; a decimal amount reads back trimmed', 'Formalin 20.5 ml · Tank 521' === ( $r['message'] ?? '' ) );

// A day-2 group in 523 (same system) and one in 621 (the other system).
act( $store, 523, 'arrival', array( 'species' => 'Test Tang', 'qty' => 2, 'date' => Replay::add_days( $today, -1 ) ) );
$tang = $store->stay( count( $store->stays ), $today, $settings );
act( $store, 621, 'arrival', array( 'species' => 'Test Goby', 'qty' => 2, 'date' => Replay::add_days( $today, -1 ) ) );
$goby = $store->stay( count( $store->stays ), $today, $settings );
$done = $store->logs_on_date( $today );
check( 'today\'s logs for 523 include the building-level dose, not the tank-level ones of 521; 621 has none of them', 1 === count( array_filter( $done[ (int) $tang['tank_id'] ] ?? array(), static function ( $l ) { return 'dose' === $l['kind']; } ) ) && empty( $done[ (int) $goby['tank_id'] ] ) && 3 === count( array_filter( $done[ (int) $spa['id'] ] ?? array(), static function ( $l ) { return 'dose' === $l['kind']; } ) ) );
$todo = Protocol::todo( Protocol::seed(), array( $tang ), $today, 'spa', $done[ (int) $tang['tank_id'] ] ?? array() );
$by   = array();
foreach ( $todo as $item ) {
	$by[ $item['label'] ] = $item['done'];
}
check( 'day 2 in 523: Metronidazole ticked by the system dose, Copper Power to 2.4 and the copper test not (those were on 521 alone)', 2 === $tang['day'] && true === $by['Metronidazole'] && false === $by['Copper Power to 2.4'] && false === $by['Copper test'], json_encode( $by ) );
$todo = Protocol::todo( Protocol::seed(), array( $goby ), $today, 'spa', $done[ (int) $goby['tank_id'] ] ?? array() );
$by   = array();
foreach ( $todo as $item ) {
	$by[ $item['label'] ] = $item['done'];
}
check( 'day 2 in 621 (the other system): nothing ticked', false === $by['Metronidazole'] && false === $by['Copper Power to 2.4'] );
$doses = $store->latest_doses();
check( 'the last dose per medication per building: metro, copper and formalin on 500 (the tank doses count for their building), nothing on 600', array( 'formalin', 'copper', 'metro' ) === array_keys( $doses[ (int) $spa['building_id'] ] ) && 'Metronidazole' === $doses[ (int) $spa['building_id'] ]['metro']['label'] && ! isset( $doses[ (int) $goby['building_id'] ] ) );
$latest = $store->tank_logs( 0, 3 );
check( 'the Events tab: the dose rows read like the toast, the system one named by its building', 'Formalin 20.5 ml' === Admin::log_text( $latest[0] ) && 'Copper Power 55 ml' === Admin::log_text( $latest[1] ) && 'Metronidazole 5.1 g · Building 500, whole system' === Admin::log_text( $latest[2] ) && 500 === $latest[2]['building_number'] && null === $latest[2]['tank_number'] );
check( 'a building-level copper reading or note says so too; an import marker is kept out of sight', 'copper 2.01 · Building 600, whole system' === Admin::log_text( array( 'kind' => 'copper', 'value' => 2.01, 'tank_id' => 0, 'building_id' => 6, 'building_number' => 600 ) ) && 'Praziquantel 2.05 · Building 600, whole system' === Admin::log_text( array( 'kind' => 'dose', 'note' => 'Praziquantel 2.05 · import:2026-9#4', 'tank_id' => 0, 'building_id' => 6, 'building_number' => 600 ) ) && 'note: Lost Fish 2' === Admin::log_text( array( 'kind' => 'note', 'note' => 'Lost Fish 2 · import:2026-9#5', 'tank_id' => 7 ) ) );
check( 'the medications offered with their suggestions: 205 gal → metro 5.1 g, copper none; a 20-gal tank → metro 0.5 g', 5.1 === Front_Desk::medications( 205, 20 )[1]['system'] && 0.5 === Front_Desk::medications( 205, 20 )[1]['tank'] && null === Front_Desk::medications( 205, 20 )[0]['system'] && 'copper' === Front_Desk::medications( 205, 20 )[0]['key'] && 'ml' === Front_Desk::medications( 205, 20 )[0]['unit'] );

echo "7. A scanned label keeps the phone signed in\n";
$front                                  = new Front_Desk();
$GLOBALS['fh_user_caps']['manage_woocommerce'] = array( 8 );
$_REQUEST['redirect_to']                = 'https://example.test/?fh_front_desk=1&tank=521';
check( 'a login bound for the Front Desk by a crew member: about a year', 365 * 86400 === $front->extend_cookie_life( 2 * 86400, 8, false ) );
check( 'a user without the capability: WordPress\'s own two days', 2 * 86400 === $front->extend_cookie_life( 2 * 86400, 9, false ) );
check( 'a longer life (remember me, filtered elsewhere) is never shortened', 400 * 86400 === $front->extend_cookie_life( 400 * 86400, 8, true ) );
$_REQUEST['redirect_to'] = 'https://example.test/wp-admin/';
check( 'a login bound for the dashboard: untouched', 2 * 86400 === $front->extend_cookie_life( 2 * 86400, 8, false ) );
unset( $_REQUEST['redirect_to'] );
check( 'no redirect at all: untouched', 14 * 86400 === $front->extend_cookie_life( 14 * 86400, 8, true ) );

echo "8. The facts filter: null for a product with no stay\n";
$zeros = array( 'live_total' => 0, 'ready_total' => 0, 'in_qt' => array(), 'cleared' => array(), 'next' => null );
check( 'facts_or_null: zeros → null', null === Clean_Room::facts_or_null( $zeros ) );
$some = array_merge( $zeros, array( 'live_total' => 3, 'in_qt' => array( array( 'stay_id' => 1 ) ) ) );
check( 'facts_or_null: a stay → the facts', $some === Clean_Room::facts_or_null( $some ) );
check( 'a cleared stay with 0 in QT is still facts', is_array( Clean_Room::facts_or_null( array_merge( $zeros, array( 'cleared' => array( array( 'stay_id' => 2 ) ) ) ) ) ) );

/**
 * A database that is not there: every query throws, as a dead connection would.
 */
class Dead_Wpdb {
	public $prefix = 'wp_';
	public $posts = 'wp_posts';
	public $postmeta = 'wp_postmeta';
	public $terms = 'wp_terms';
	public $term_taxonomy = 'wp_term_taxonomy';
	public $term_relationships = 'wp_term_relationships';
	public function __call( $name, $args ) {
		throw new \RuntimeException( 'no database' );
	}
}
$GLOBALS['wpdb'] = new Dead_Wpdb();
add_filter( Clean_Room::FACTS_FILTER, array( Clean_Room::class, 'filter_facts' ), 10, 2 );
check( 'apply_filters( fishotel_product_qt_facts, null, id ) with no stay → null (the theme falls back to product meta)', null === apply_filters( Clean_Room::FACTS_FILTER, null, 5 ) );
check( 'fishotel_cleanroom_product_facts() agrees', null === fishotel_cleanroom_product_facts( 5 ) );
check( 'an answer given earlier in the chain is passed through', array( 'live_total' => 1 ) === apply_filters( Clean_Room::FACTS_FILTER, array( 'live_total' => 1 ), 5 ) );

fh_finish();
