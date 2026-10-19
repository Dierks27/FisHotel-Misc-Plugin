<?php
/**
 * Replay — events → stay state: alive = received − losses − sold with a
 * recount override, cleared by the first move into the Clean Room (or
 * by hand), the chosen sale date (the stay's own note, else its batch's)
 * with the suggestion beside it, closing at zero, the
 * day math across both DST changes in America/Chicago, splits, and the
 * compensating (undo) events.
 *
 * Run from the plugin root:  php includes/sections/clean-room/tests/replay-test.php
 */

require_once __DIR__ . '/wp-stubs.php';

use FisHotel\Misc\Sections\Clean_Room\Replay;

$chicago = new DateTimeZone( 'America/Chicago' );
$areas   = array( 10 => 'spa', 11 => 'spa', 20 => 'clean', 21 => 'clean' );
$opts    = array(
	'sale_ready_days' => 28,
	'tz'              => $chicago,
	'tank_areas'      => $areas,
);

$fh_id = 0;
function ev( $type, $at, $qty = null, $to = null, $from = null, $note = '' ) {
	global $fh_id;
	$fh_id++;
	return array(
		'id'           => $fh_id,
		'type'         => $type,
		'qty'          => $qty,
		'from_tank_id' => $from,
		'to_tank_id'   => $to,
		'note'         => $note,
		'event_at'     => $at,
	);
}

echo "1. Dates\n";
check( 'is_date', Replay::is_date( '2026-04-01' ) && ! Replay::is_date( '2026-02-30' ) && ! Replay::is_date( '2026-4-1' ) && ! Replay::is_date( 'today' ) && ! Replay::is_date( null ) );
check( 'day 1 is the check-in day', 1 === Replay::day_number( '2026-04-01', '2026-04-01' ) );
check( 'the next day is day 2', 2 === Replay::day_number( '2026-04-01', '2026-04-02' ) );
check( 'day 28 is check-in + 27', 28 === Replay::day_number( '2026-04-01', '2026-04-28' ) );
check( 'across the March DST change (Mar 7 → Mar 9 is day 3, not 2)', 3 === Replay::day_number( '2026-03-07', '2026-03-09' ) );
check( 'across the November DST change (Oct 31 → Nov 2 is day 3)', 3 === Replay::day_number( '2026-10-31', '2026-11-02' ) );
check( 'a whole year of DST changes still counts calendar days', 366 === Replay::day_number( '2026-01-01', '2027-01-01' ) );
check( 'a check-in in the future is day 0', 0 === Replay::day_number( '2026-04-02', '2026-04-01' ) );
check( 'bad dates → null', null === Replay::day_number( '', '2026-04-01' ) && null === Replay::day_number( '2026-04-01', 'soon' ) );
check( 'add_days across the March change', '2026-03-09' === Replay::add_days( '2026-03-07', 2 ) && '' === Replay::add_days( 'x', 2 ) );
check( 'the SUGGESTED sale date = 28 days after check-in (the full four weeks, so day 29)', '2026-04-29' === Replay::suggested_sale_date( '2026-04-01', 28 ) && 29 === Replay::day_number( '2026-04-01', '2026-04-29' ) );
check( 'suggested with 14 days; no check-in → no suggestion', '2026-04-15' === Replay::suggested_sale_date( '2026-04-01', 14 ) && '' === Replay::suggested_sale_date( '', 28 ) );
check( 'note_date', '2026-04-10' === Replay::note_date( 'cleared:2026-04-10', Replay::NOTE_CLEARED ) && '2026-04-10' === Replay::note_date( 'checkin: 2026-04-10 ', Replay::NOTE_CHECKIN ) && '' === Replay::note_date( 'cleared:2026-02-30', Replay::NOTE_CLEARED ) && '' === Replay::note_date( 'cleared:', Replay::NOTE_CLEARED ) && '' === Replay::note_date( 'checkin:2026-04-10', Replay::NOTE_CLEARED ) );
check( 'the stay\'s own date wins over its batch\'s', '2026-05-20' === Replay::sale_date( '2026-05-20', '2026-05-01' ) && Replay::SALE_OWN === Replay::sale_date_source( '2026-05-20', '2026-05-01' ) );
check( 'no own date → the batch\'s; neither → null, never a computed date', '2026-05-01' === Replay::sale_date( null, '2026-05-01' ) && Replay::SALE_BATCH === Replay::sale_date_source( '', '2026-05-01' ) && null === Replay::sale_date( null, null ) && '' === Replay::sale_date_source( null, null ) && null === Replay::sale_date( '2026-02-30', 'soon' ) );
check( 'event date in Chicago, CST: 05:30 UTC on Mar 8 is still Mar 7', '2026-03-07' === Replay::event_date( '2026-03-08 05:30:00', $chicago ) );
check( 'event date in Chicago, the DST morning: 08:30 UTC on Mar 8 is Mar 8', '2026-03-08' === Replay::event_date( '2026-03-08 08:30:00', $chicago ) );
check( 'event date in Chicago, the November change: 06:30 UTC and 07:30 UTC on Nov 1 are both Nov 1', '2026-11-01' === Replay::event_date( '2026-11-01 06:30:00', $chicago ) && '2026-11-01' === Replay::event_date( '2026-11-01 07:30:00', $chicago ) );
check( 'event date, UTC by default', '2026-03-08' === Replay::event_date( '2026-03-08 05:30:00' ) );
check( 'event date of garbage is empty', '' === Replay::event_date( 'never', $chicago ) );

echo "2. A stay's life: arrival → losses → sold → recount → move into the Clean Room → sale-ready\n";
$events = array( ev( 'arrival', '2026-04-01 15:00:00', 5, 10 ) );
$s      = Replay::state( array(), $events, '2026-04-03', $opts );
check( 'arrival 5 into a Spa tank: received 5, alive 5, checkin from the event (site date), tank 10, in QT', 5 === $s['qty_arrived'] && 5 === $s['qty_live'] && '2026-04-01' === $s['checkin_date'] && 10 === $s['tank_id'] && 'in_qt' === $s['status'] && 'in QT' === $s['state_label'] && 3 === $s['day'] );
check( 'not cleared, no sale date chosen (none, the suggestion Apr 29 = 28 days after check-in), not for sale', null === $s['cleared_date'] && null === $s['sale_ready_date'] && '' === $s['sale_date_source'] && '2026-04-29' === $s['sale_ready_suggested'] && false === $s['sale_ready'] && null === $s['closed_date'] && false === $s['overdrawn'] );

$events[] = ev( 'loss', '2026-04-02 12:00:00', 2 );
$s        = Replay::state( array(), $events, '2026-04-03', $opts );
check( 'loss 2: alive 3, lost 2 (survival stats)', 3 === $s['qty_live'] && 2 === $s['qty_lost'] && 0 === $s['qty_sold'] );

$events[] = ev( 'sold', '2026-04-03 12:00:00', 1 );
$s        = Replay::state( array(), $events, '2026-04-03', $opts );
check( 'sold 1: alive 2, sold 1, lost still 2', 2 === $s['qty_live'] && 1 === $s['qty_sold'] && 2 === $s['qty_lost'] );

$events[] = ev( 'count', '2026-04-04 12:00:00', 4 );
$s        = Replay::state( array(), $events, '2026-04-04', $opts );
check( 'a recount overrides: alive 4, the stats untouched', 4 === $s['qty_live'] && 2 === $s['qty_lost'] && 1 === $s['qty_sold'] );

$events[] = ev( 'move', '2026-04-15 14:00:00', null, 20, 10 );
$s        = Replay::state( array(), $events, '2026-04-20', $opts );
check( 'the first move into a Clean Room tank clears the stay on that date', 20 === $s['tank_id'] && '2026-04-15' === $s['cleared_date'] && 'cleared' === $s['status'] && 'cleared' === $s['state_label'] );
check( 'day 20: cleared but not for sale', 20 === $s['day'] && false === $s['sale_ready'] );
$s = Replay::state( array(), $events, '2026-04-29', $opts );
check( 'Apr 29 (the suggested date) with no date chosen: still not for sale — the suggestion never puts a group on sale', false === $s['sale_ready'] && 'cleared' === $s['state_label'] && 29 === $s['day'] );
$batch = array( 'batch_sale_date' => '2026-04-29' );
$s     = Replay::state( $batch, $events, '2026-04-29', $opts );
check( 'with the batch going on sale Apr 29: for sale on Apr 29 (the date itself counts), from the batch', true === $s['sale_ready'] && 'ready' === $s['state_label'] && 'cleared' === $s['status'] && '2026-04-29' === $s['sale_ready_date'] && Replay::SALE_BATCH === $s['sale_date_source'] && false === $s['ready_in_spa'] );
$s = Replay::state( $batch, $events, '2026-04-28', $opts );
check( 'Apr 28: not yet', false === $s['sale_ready'] && 'cleared' === $s['state_label'] );

$events[] = ev( 'move', '2026-04-21 14:00:00', null, 21, 20 );
$s        = Replay::state( array(), $events, '2026-04-28', $opts );
check( 'a later move inside the Clean Room keeps the first cleared date', 21 === $s['tank_id'] && '2026-04-15' === $s['cleared_date'] );

$events[] = ev( 'clear', '2026-04-16 18:00:00' );
$s        = Replay::state( array(), $events, '2026-04-28', $opts );
check( 'an old-shape clear event (no note) dates the clearing by its event_at', '2026-04-16' === $s['cleared_date'] );
$events[] = ev( 'clear', '2026-04-22 18:00:00', -1 );
$s        = Replay::state( array(), $events, '2026-04-28', $opts );
check( 'a clear with qty −1 unsets the date: back in QT, not ready', null === $s['cleared_date'] && 'in_qt' === $s['status'] && false === $s['sale_ready'] );
$events[] = ev( 'clear', '2026-04-23 09:00:00', null, null, null, 'cleared:2026-04-12' );
$s        = Replay::state( array(), $events, '2026-04-28', $opts );
check( 'a clear recorded today with the date in its note: that date, even after the unset and even earlier than everything', '2026-04-12' === $s['cleared_date'] && 'cleared' === $s['status'] );
$events[] = ev( 'clear', '2026-04-23 09:05:00', null, null, null, 'cleared:2026-04-10' );
$s        = Replay::state( array(), $events, '2026-04-28', $opts );
check( 'corrected to an EARLIER date five minutes later: the later correction wins', '2026-04-10' === $s['cleared_date'] );
$events[] = ev( 'clear', '2026-04-23 09:10:00', null, null, null, 'cleared:2026-02-30' );
$s        = Replay::state( array(), $events, '2026-04-28', $opts );
check( 'a clear whose note has no valid date falls back to its event date', '2026-04-23' === $s['cleared_date'] );

echo "3. Spa-to-Spa moves never clear; moving back later does not matter; only a move OUT of the Spa clears\n";
$e = array( ev( 'arrival', '2026-04-01 15:00:00', 3, 10 ), ev( 'move', '2026-04-05 10:00:00', null, 11, 10 ) );
$s = Replay::state( array(), $e, '2026-04-10', $opts );
check( 'Spa 10 → Spa 11: tank 11, still in QT', 11 === $s['tank_id'] && null === $s['cleared_date'] && 'in_qt' === $s['status'] );
$e[] = ev( 'move', '2026-04-14 10:00:00', null, 20, 11 );
$e[] = ev( 'move', '2026-04-18 10:00:00', null, 10, 20 );
$s   = Replay::state( array(), $e, '2026-04-20', $opts );
check( 'cleared on the Clean Room move, kept after a move back to the Spa', '2026-04-14' === $s['cleared_date'] && 10 === $s['tank_id'] && 'cleared' === $s['status'] );
$s = Replay::state( array(), array( ev( 'arrival', '2026-04-01 15:00:00', 3, 10 ), ev( 'move', '2026-04-05 10:00:00', null, 20, 10 ) ), '2026-04-10', array( 'sale_ready_days' => 28, 'tz' => $chicago ) );
check( 'without the tank areas no move can clear (the Store always passes them)', null === $s['cleared_date'] );
$e = array( ev( 'arrival', '2026-04-01 15:00:00', 3, 10 ), ev( 'move', '2026-04-14 10:00:00', null, 20, 10 ), ev( 'clear', '2026-04-15 10:00:00', -1 ), ev( 'move', '2026-04-16 10:00:00', null, 21, 20 ) );
$s = Replay::state( array(), $e, '2026-04-20', $opts );
check( 'after a hand unset, a Clean → Clean move does NOT re-clear', null === $s['cleared_date'] && 'in_qt' === $s['status'] && 21 === $s['tank_id'] );
$e[] = ev( 'move', '2026-04-17 10:00:00', null, 10, 21 );
$e[] = ev( 'move', '2026-04-18 10:00:00', null, 20, 10 );
$s   = Replay::state( array(), $e, '2026-04-20', $opts );
check( '…but back through the Spa into the Clean Room clears again, on that day', '2026-04-18' === $s['cleared_date'] && 'cleared' === $s['status'] );
$s = Replay::state( array(), array( ev( 'arrival', '2026-04-01 15:00:00', 3, 10 ), ev( 'move', '2026-04-14 10:00:00', null, 20, null ) ), '2026-04-20', $opts );
check( 'a move from an unknown source tank (no from_tank_id) into the Clean Room clears', '2026-04-14' === $s['cleared_date'] );
$s = Replay::state( array(), array( ev( 'arrival', '2026-04-01 15:00:00', 3, 10 ), ev( 'move', '2026-04-14 10:00:00', null, 20, 99 ) ), '2026-04-20', $opts );
check( 'a source tank the layout no longer knows counts as unknown: clears', '2026-04-14' === $s['cleared_date'] );

echo "4. The sale-ready override (a note event)\n";
$e = array( ev( 'arrival', '2026-04-01 15:00:00', 3, 10 ), ev( 'move', '2026-04-14 10:00:00', null, 20, 10 ) );
$e[] = ev( 'note', '2026-04-15 10:00:00', null, null, null, 'sale_ready:2026-05-20' );
$s   = Replay::state( array( 'batch_sale_date' => '2026-04-25' ), $e, '2026-05-01', $opts );
check( 'the own date is read from the note and wins over the batch\'s (Apr 25)', '2026-05-20' === $s['sale_ready_override'] && '2026-05-20' === $s['sale_ready_date'] && Replay::SALE_OWN === $s['sale_date_source'] && '2026-04-25' === $s['batch_sale_date'] && false === $s['sale_ready'] );
$s = Replay::state( array(), $e, '2026-05-20', $opts );
check( 'for sale on the own date', true === $s['sale_ready'] );
$e[] = ev( 'note', '2026-04-16 10:00:00', null, null, null, 'sale_ready:' );
$s   = Replay::state( array(), $e, '2026-05-01', $opts );
check( 'an empty note clears the own date: without a batch date, no date at all and not for sale (the suggestion Apr 29 is only a hint)', null === $s['sale_ready_override'] && null === $s['sale_ready_date'] && '2026-04-29' === $s['sale_ready_suggested'] && false === $s['sale_ready'] );
$s = Replay::state( array( 'batch_sale_date' => '2026-04-25' ), $e, '2026-05-01', $opts );
check( '…and with one, back to the batch\'s date: for sale since Apr 25', '2026-04-25' === $s['sale_ready_date'] && Replay::SALE_BATCH === $s['sale_date_source'] && true === $s['sale_ready'] );
$e[] = ev( 'note', '2026-04-17 10:00:00', null, null, null, 'looking thin' );
$s   = Replay::state( array(), $e, '2026-05-01', $opts );
check( 'a plain note changes nothing', null === $s['sale_ready_override'] && 3 === $s['qty_live'] );
$e[] = ev( 'note', '2026-04-18 10:00:00', null, null, null, 'sale_ready:2026-02-30' );
$s   = Replay::state( array(), $e, '2026-05-01', $opts );
check( 'an invalid override date reads as none', null === $s['sale_ready_override'] );

echo "4b. The check-in date corrected by a note\n";
$e = array( ev( 'arrival', '2026-04-05 15:00:00', 3, 10 ) );
$e[] = ev( 'note', '2026-04-20 10:00:00', null, null, null, 'checkin:2026-04-01' );
$s   = Replay::state( array(), $e, '2026-04-20', $opts );
check( '"checkin:2026-04-01" moves the check-in back: day 20, the suggestion Apr 29 (no date chosen)', '2026-04-01' === $s['checkin_date'] && 20 === $s['day'] && '2026-04-29' === $s['sale_ready_suggested'] && null === $s['sale_ready_date'] );
$e[] = ev( 'arrival', '2026-04-21 10:00:00', 1, 10 );
$s   = Replay::state( array(), $e, '2026-04-21', $opts );
check( 'a later arrival never overrides the corrected date', '2026-04-01' === $s['checkin_date'] && 4 === $s['qty_live'] );
$e[] = ev( 'note', '2026-04-22 10:00:00', null, null, null, 'checkin:' );
$s   = Replay::state( array(), $e, '2026-04-22', $opts );
check( 'an empty "checkin:" goes back to the first arrival\'s date', '2026-04-05' === $s['checkin_date'] );
$e[] = ev( 'note', '2026-04-22 10:05:00', null, null, null, 'checkin:2026-02-30' );
$s   = Replay::state( array(), $e, '2026-04-22', $opts );
check( 'an invalid date is ignored like an empty one', '2026-04-05' === $s['checkin_date'] );
$c = Replay::compensating( ev( 'note', 'x', null, null, null, 'checkin:2026-04-01' ), array( 'checkin_date' => '2026-04-05' ) );
check( 'the undo of a check-in note restores the previous date', 'note' === $c['type'] && 'checkin:2026-04-05' === $c['note'] );

echo "5. Closing at zero and re-opening\n";
$e = array( ev( 'arrival', '2026-04-01 15:00:00', 3, 10 ), ev( 'loss', '2026-04-03 15:00:00', 3 ) );
$s = Replay::state( array(), $e, '2026-04-10', $opts );
check( 'all three lost: alive 0, closed on the loss date', 0 === $s['qty_live'] && 'closed' === $s['status'] && '2026-04-03' === $s['closed_date'] && 'closed' === $s['state_label'] );
$e[] = ev( 'loss', '2026-04-03 15:05:00', -3, null, null, 'undo of #2' );
$s   = Replay::state( array(), $e, '2026-04-10', $opts );
check( 'the undo (loss −3) re-opens it: alive 3, lost 0', 3 === $s['qty_live'] && 0 === $s['qty_lost'] && null === $s['closed_date'] && 'in_qt' === $s['status'] );
$e[] = ev( 'count', '2026-04-05 09:00:00', 0 );
$s   = Replay::state( array(), $e, '2026-04-10', $opts );
check( 'a recount of 0 closes it on that date', 'closed' === $s['status'] && '2026-04-05' === $s['closed_date'] );
$e[] = ev( 'sold', '2026-04-06 09:00:00', 2 );
$s   = Replay::state( array(), $e, '2026-04-10', $opts );
check( 'alive never goes below zero in the result — but the state says it is overdrawn', 0 === $s['qty_live'] && 2 === $s['qty_sold'] && true === $s['overdrawn'] );
$s = Replay::state( array(), array( ev( 'arrival', '2026-04-01 15:00:00', 1, 10 ), ev( 'loss', '2026-04-02 15:00:00', 1 ), ev( 'loss', '2026-04-02 15:00:01', 1 ) ), '2026-04-10', $opts );
check( 'two phones, one fish, "−1 lost" on both: 0 live, closed, overdrawn', 0 === $s['qty_live'] && 'closed' === $s['status'] && true === $s['overdrawn'] );
$s = Replay::state( array(), array( ev( 'arrival', '2026-04-01 15:00:00', 1, 10 ), ev( 'loss', '2026-04-02 15:00:00', 1 ), ev( 'loss', '2026-04-02 15:00:01', 1 ), ev( 'loss', '2026-04-02 15:05:00', -1, null, null, 'undo of #2' ) ), '2026-04-10', $opts );
check( 'the undo of one of them clears the overdraw', 0 === $s['qty_live'] && false === $s['overdrawn'] );
$s = Replay::state( array(), array( ev( 'arrival', '2026-04-01 15:00:00', 1, 10 ), ev( 'loss', '2026-04-02 15:00:00', 1 ), ev( 'loss', '2026-04-02 15:00:01', 1 ), ev( 'count', '2026-04-02 15:05:00', 0 ) ), '2026-04-10', $opts );
check( 'so does a recount', 0 === $s['qty_live'] && false === $s['overdrawn'] );
$s = Replay::state( array(), array( ev( 'arrival', '2026-04-01 15:00:00', 2, 10 ), ev( 'sold', '2026-04-20 15:00:00', 2 ) ), '2026-04-21', $opts );
check( 'all sold: closed too, lost 0', 'closed' === $s['status'] && 0 === $s['qty_lost'] && 2 === $s['qty_sold'] );
$s = Replay::state( array(), array( ev( 'loss', '2026-04-01 15:00:00', 1 ) ), '2026-04-21', $opts );
check( 'a loss before any arrival does not close a stay that never opened', null === $s['closed_date'] && 0 === $s['qty_live'] );

echo "6. Splits (a move with a quantity smaller than the group)\n";
$e = array( ev( 'arrival', '2026-04-01 15:00:00', 5, 10 ), ev( 'move', '2026-04-10 15:00:00', 2, 20, 10 ) );
$s = Replay::state( array(), $e, '2026-04-12', $opts );
check( 'move 2 of 5: alive 3, still in tank 10, not cleared', 3 === $s['qty_live'] && 10 === $s['tank_id'] && null === $s['cleared_date'] && 5 === $s['qty_arrived'] );
$e[] = ev( 'move', '2026-04-10 15:05:00', -2, 10, 20, 'undo of #2' );
$s   = Replay::state( array(), $e, '2026-04-12', $opts );
check( 'the undo gives them back: alive 5', 5 === $s['qty_live'] && 10 === $s['tank_id'] );
$s = Replay::state( array(), array( ev( 'arrival', '2026-04-01 15:00:00', 3, 10 ), ev( 'move', '2026-04-10 15:00:00', 3, 20, 10 ) ), '2026-04-12', $opts );
check( 'a move of the whole count is a whole move: tank 20, cleared', 3 === $s['qty_live'] && 20 === $s['tank_id'] && '2026-04-10' === $s['cleared_date'] );
check( 'is_whole_move', Replay::is_whole_move( ev( 'move', 'x', null, 20, 10 ) ) && ! Replay::is_whole_move( ev( 'move', 'x', 2, 20, 10 ) ) );

echo "7. Order of events and the checkin fallback\n";
$e = array( ev( 'loss', '2026-04-05 10:00:00', 1 ), ev( 'arrival', '2026-04-01 10:00:00', 4, 10 ) );
$s = Replay::state( array(), $e, '2026-04-06', $opts );
check( 'events are sorted by time: arrival first', 3 === $s['qty_live'] && '2026-04-01' === $s['checkin_date'] && 6 === $s['day'] );
$sorted = Replay::sort_events( array( array( 'id' => 2, 'event_at' => '2026-04-01 10:00:00' ), array( 'id' => 1, 'event_at' => '2026-04-01 10:00:00' ), array( 'id' => 3, 'event_at' => '2026-03-31 10:00:00' ) ) );
check( 'same time → by id', array( 3, 1, 2 ) === array_column( $sorted, 'id' ) );
$s = Replay::state( array( 'checkin_date' => '2026-03-20', 'tank_id' => 11 ), array(), '2026-03-25', $opts );
check( 'no events: the row\'s checkin and tank are the fallback, nothing arrived', '2026-03-20' === $s['checkin_date'] && 11 === $s['tank_id'] && 0 === $s['qty_arrived'] && 'in_qt' === $s['status'] && 6 === $s['day'] );
$s = Replay::state( array(), array( ev( 'arrival', '2026-04-01 03:30:00', 2, 10 ) ), '2026-04-01', $opts );
check( 'the check-in is the SITE date of the arrival (03:30 UTC Apr 1 is Mar 31 in Chicago)', '2026-03-31' === $s['checkin_date'] && 2 === $s['day'] );

echo "8. effective() on a cached row\n";
$row = array( 'status' => 'cleared', 'checkin_date' => '2026-04-01', 'cleared_date' => '2026-04-14', 'sale_ready_date' => null );
$e   = Replay::effective( $row, '2026-04-29', array( 'sale_ready_days' => 28 ) );
check( 'a cleared row 28 days after check-in without a chosen date: not for sale, "suggested Apr 29" only', 'cleared' === $e['status'] && 29 === $e['day'] && false === $e['sale_ready'] && 'cleared' === $e['state_label'] && null === $e['sale_ready_date'] && '2026-04-29' === $e['sale_ready_suggested'] );
$row['batch_sale_date'] = '2026-04-29';
$e                      = Replay::effective( $row, '2026-04-29', array( 'sale_ready_days' => 28 ) );
check( 'its batch on sale Apr 29: for sale that day', true === $e['sale_ready'] && 'ready' === $e['state_label'] && '2026-04-29' === $e['sale_ready_date'] && Replay::SALE_BATCH === $e['sale_date_source'] );
check( 'the day before: not yet', false === Replay::effective( $row, '2026-04-28', array( 'sale_ready_days' => 28 ) )['sale_ready'] );
$e = Replay::effective( array_merge( $row, array( 'sale_ready_date' => '2026-05-05' ) ), '2026-04-29', array( 'sale_ready_days' => 28 ) );
check( 'the stored own date holds it back past the batch\'s', false === $e['sale_ready'] && '2026-05-05' === $e['sale_ready_date'] && Replay::SALE_OWN === $e['sale_date_source'] );
$e = Replay::effective( array( 'status' => 'in_qt', 'checkin_date' => '2026-04-01', 'cleared_date' => null ), '2026-05-10', array( 'sale_ready_days' => 28 ) );
check( 'still in the Spa at day 40 with no date: in QT, not for sale', 'in_qt' === $e['status'] && 40 === $e['day'] && false === $e['sale_ready'] && 'in QT' === $e['state_label'] && false === $e['ready_in_spa'] );
$e = Replay::effective( array( 'status' => 'in_qt', 'checkin_date' => '2026-04-01', 'cleared_date' => null, 'batch_sale_date' => '2026-05-01' ), '2026-05-10', array( 'sale_ready_days' => 28 ) );
check( 'still in the Spa past its batch\'s date: for sale (the chosen date rules) and flagged "still in the Spa"', 'in_qt' === $e['status'] && true === $e['sale_ready'] && 'ready' === $e['state_label'] && true === $e['ready_in_spa'] );
$e = Replay::effective( array( 'status' => 'closed', 'checkin_date' => '2026-04-01', 'cleared_date' => '2026-04-14', 'batch_sale_date' => '2026-04-20' ), '2026-05-10' );
check( 'closed stays closed, never for sale, even past its date', 'closed' === $e['status'] && 'closed' === $e['state_label'] && false === $e['sale_ready'] && false === $e['ready_in_spa'] );
$e = Replay::effective( array( 'status' => 'in_qt', 'checkin_date' => '2026-04-01', 'cleared_date' => '2026-04-14' ), '2026-04-20' );
check( 'a stored in_qt with a cleared date reads as cleared', 'cleared' === $e['status'] );

echo "9. Compensating (undo) events\n";
$c = Replay::compensating( ev( 'arrival', 'x', 4, 10 ) );
check( 'arrival 4 → arrival −4', 'arrival' === $c['type'] && -4 === $c['qty'] && 0 === strpos( $c['note'], 'undo of #' ) );
check( 'loss 2 → loss −2', -2 === Replay::compensating( ev( 'loss', 'x', 2 ) )['qty'] );
check( 'a loss without qty counts as 1', -1 === Replay::compensating( ev( 'loss', 'x' ) )['qty'] );
check( 'sold 3 → sold −3', 'sold' === Replay::compensating( ev( 'sold', 'x', 3 ) )['type'] && -3 === Replay::compensating( ev( 'sold', 'x', 3 ) )['qty'] );
$c = Replay::compensating( ev( 'move', 'x', null, 20, 10 ) );
check( 'a whole move A→B → move B→A', 'move' === $c['type'] && null === $c['qty'] && 20 === $c['from_tank_id'] && 10 === $c['to_tank_id'] );
$c = Replay::compensating( ev( 'move', 'x', 2, 20, 10 ) );
check( 'a split of 2 → move −2 the other way', -2 === $c['qty'] && 20 === $c['from_tank_id'] && 10 === $c['to_tank_id'] );
check( 'clear → clear −1', -1 === Replay::compensating( ev( 'clear', 'x' ) )['qty'] );
check( 'an un-clear cannot be undone', null === Replay::compensating( ev( 'clear', 'x', -1 ) ) );
check( 'count → count back to what it was', 4 === Replay::compensating( ev( 'count', 'x', 1 ), array( 'qty_live' => 4 ) )['qty'] );
check( 'a plain note has nothing to undo', null === Replay::compensating( ev( 'note', 'x', null, null, null, 'hello' ) ) );
$c = Replay::compensating( ev( 'note', 'x', null, null, null, 'sale_ready:2026-05-20' ), array( 'sale_ready_override' => '2026-05-01' ) );
check( 'a sale-date note → the previous own date', 'note' === $c['type'] && 'sale_ready:2026-05-01' === $c['note'] );
$c = Replay::compensating( ev( 'note', 'x', null, null, null, 'sale_ready:2026-05-20' ), array( 'sale_ready_override' => null ) );
check( '…or none', 'sale_ready:' === $c['note'] );
check( 'arrival 0 → nothing', null === Replay::compensating( ev( 'arrival', 'x', 0 ) ) );
check( 'is_reversal', Replay::is_reversal( ev( 'loss', 'x', -1 ) ) && Replay::is_reversal( ev( 'move', 'x', null, 1, 2, 'undo of #3' ) ) && ! Replay::is_reversal( ev( 'loss', 'x', 1 ) ) );
$list = array( ev( 'loss', '2026-04-02 15:00:00', 1 ), ev( 'loss', '2026-04-02 15:05:00', -1, null, null, 'undo of #' . $fh_id ) );
check( 'is_undone: the compensating note names the event', Replay::is_undone( $list, $list[0]['id'] ) && ! Replay::is_undone( $list, $list[1]['id'] ) && ! Replay::is_undone( $list, 0 ) && ! Replay::is_undone( array( $list[0] ), $list[0]['id'] ) );
check( 'undone_ids over a list', array( $list[0]['id'] ) === Replay::undone_ids( $list ) && array() === Replay::undone_ids( array( ev( 'note', 'x', null, null, null, 'undo of #abc' ), ev( 'note', 'x', null, null, null, 'undone' ) ) ) );

echo "10. The undo round trip really restores the state\n";
$e = array( ev( 'arrival', '2026-04-01 15:00:00', 5, 10 ), ev( 'move', '2026-04-14 15:00:00', null, 20, 10 ) );
$before = Replay::state( array(), $e, '2026-04-20', $opts );
$e[]    = ev( 'count', '2026-04-15 15:00:00', 2 );
$e[]    = array_merge( Replay::compensating( end( $e ), $before ), array( 'id' => 99, 'event_at' => '2026-04-15 15:01:00' ) );
$after  = Replay::state( array(), $e, '2026-04-20', $opts );
check( 'count 2 then its undo → alive 5 again', 5 === $after['qty_live'] && $before['qty_live'] === $after['qty_live'] );
$e[] = ev( 'clear', '2026-04-16 15:00:00' );
$e[] = array_merge( Replay::compensating( end( $e ) ), array( 'id' => 100, 'event_at' => '2026-04-16 15:01:00' ) );
$after = Replay::state( array(), $e, '2026-04-20', $opts );
check( 'a clear then its undo leaves NO cleared date (the move date is not restored — the history shows both)', null === $after['cleared_date'] && 'in_qt' === $after['status'] );

fh_finish();
