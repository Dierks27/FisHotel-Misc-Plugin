<?php
/**
 * Dose, the Save: what the Dose sheet's Save writes, driven through
 * Front_Desk::perform() against the in-memory Fake_Store.
 *
 * A plain typed dose logs exactly what it always did; with the sheet's
 * sum the dose log carries the working as its note (per gallon, a named
 * dose, the top-up, to a level), from the STORE's gallons — the whole
 * system or one tank, never the post's —; the copper product's "Now" is
 * logged as a copper reading on the same water, once, and not again when
 * it is today's latest reading there already; the day's matching to-do
 * steps are ticked; the amount posted is what is logged; unknown gallons;
 * a medication added in Settings, a retired or unknown one; a store that
 * fails; the Events line and the Board's "last dose" afterwards; and what
 * the page is handed. The phrases are dose-phrases-test.php.
 *
 * Run from the plugin root:  php includes/sections/clean-room/tests/dose-save-test.php
 */

require_once __DIR__ . '/wp-stubs.php';
require_once __DIR__ . '/dose-helpers.php';

use FisHotel\Misc\Sections\Clean_Room\Admin;
use FisHotel\Misc\Sections\Clean_Room\Dose;
use FisHotel\Misc\Sections\Clean_Room\Front_Desk;
use FisHotel\Misc\Sections\Clean_Room\Medications;
use FisHotel\Misc\Sections\Clean_Room\Protocol;
use FisHotel\Misc\Sections\Clean_Room\Replay;
use FisHotel\Misc\Sections\Clean_Room\Settings;
use FisHotel\Misc\Sections\Clean_Room\Store;
use FisHotel\Misc\Sections\Clean_Room\Tests\Fake_Store;

$today    = Replay::date_in_zone( time(), wp_timezone() );
$settings = Settings::get();
$store    = new Fake_Store();

$t521 = $store->tank_by_number( 521 );
$t621 = $store->tank_by_number( 621 );
$b500 = (int) $t521['building_id'];
$b600 = (int) $t621['building_id'];

echo "1. A plain typed dose is what it always was\n";
$n = count( $store->logs );
$r = fh_act( $store, 521, array( 'medication' => 'metro', 'amount' => '5.1', 'unit' => 'g', 'whole' => 1 ) );
check( 'no sum behind it: the same toast, one dose log with the same note, nothing else read or written', empty( $r['error'] ) && 'Metronidazole 5.1 g · Building 500, whole system' === $r['message'] && array() === $r['event_ids'] && array( 'dose|0|' . $b500 . '|5.1|Metronidazole 5.1 g' ) === fh_new_logs( $store, $n ), json_encode( fh_new_logs( $store, $n ) ) );
$n = count( $store->logs );
$r = fh_act( $store, 521, array( 'medication' => 'copper', 'amount' => '55', 'unit' => 'ml' ) );
check( 'copper typed without the sum: a dose on the tank, no reading', 'Copper Power 55 ml · Tank 521' === ( $r['message'] ?? '' ) && array( 'dose|' . $t521['id'] . '|-|55|Copper Power 55 ml' ) === fh_new_logs( $store, $n ) && '' === $r['reading'] );
$n = count( $store->logs );
$r = fh_act( $store, 521, array( 'medication' => 'metro', 'amount' => '500', 'unit' => 'mg', 'whole' => 1 ) );
check( 'the new units are accepted: mg (and drops, each)', 'Metronidazole 500 mg · Building 500, whole system' === ( $r['message'] ?? '' ) && empty( fh_act( $store, 521, array( 'medication' => 'salt', 'amount' => '3', 'unit' => 'drops' ) )['error'] ) && empty( fh_act( $store, 521, array( 'medication' => 'salt', 'amount' => '2', 'unit' => 'each' ) )['error'] ) );
check( 'another unit, no medication, an unknown one, a bad amount: refused as before, nothing written', 'Which unit? mg, g, ml, tsp, drops or each.' === ( fh_act( $store, 521, array( 'medication' => 'metro', 'amount' => '5', 'unit' => 'cups' ) )['error'] ?? '' ) && 'Which medication? Pick one.' === ( fh_act( $store, 521, array( 'amount' => '5', 'unit' => 'g' ) )['error'] ?? '' ) && 'Which medication? Pick one.' === ( fh_act( $store, 521, array( 'medication' => array( 'metro' ), 'amount' => '5', 'unit' => 'g' ) )['error'] ?? '' ) && 'How much? An amount above 0 and at most 9999.' === ( fh_act( $store, 521, array( 'medication' => 'metro', 'amount' => '0', 'unit' => 'g', 'calc' => 1 ) )['error'] ?? '' ) && $n + 3 === count( $store->logs ) );

echo "2. Per gallon, with the sheet's sum: the working goes into the log\n";
$n = count( $store->logs );
$r = fh_act( $store, 621, array( 'medication' => 'metro', 'amount' => '2.5', 'unit' => 'g', 'whole' => 1, 'calc' => 1, 'rate' => '25' ) );
check( '100 gallons of Metronidazole: "Metronidazole 2.5 g · Building 600, whole system · 25 mg per gallon, 100 gal"', 'Metronidazole 2.5 g · Building 600, whole system · 25 mg per gallon, 100 gal' === ( $r['message'] ?? '' ), json_encode( $r ) );
check( '…ONE log: a dose on the building, value 2.5, the note "Metronidazole 2.5 g · 25 mg per gallon, 100 gal"', array( 'dose|0|' . $b600 . '|2.5|Metronidazole 2.5 g · 25 mg per gallon, 100 gal' ) === fh_new_logs( $store, $n ), json_encode( fh_new_logs( $store, $n ) ) );
$n = count( $store->logs );
$r = fh_act( $store, 621, array( 'medication' => 'metro', 'amount' => '1', 'unit' => 'g', 'whole' => 0, 'calc' => 1, 'rate' => '25' ) );
check( 'this tank only: the tank\'s own 40 gallons, the log on the tank', 'Metronidazole 1 g · Tank 621 · 25 mg per gallon, 40 gal' === ( $r['message'] ?? '' ) && array( 'dose|' . $t621['id'] . '|-|1|Metronidazole 1 g · 25 mg per gallon, 40 gal' ) === fh_new_logs( $store, $n ) );
$n = count( $store->logs );
$r = fh_act( $store, 521, array( 'medication' => 'metro', 'amount' => '5.1', 'unit' => 'g', 'whole' => 1, 'calc' => 1, 'rate' => '25', 'gallons' => '999' ) );
check( 'the gallons are the store\'s (Building 500 = 205), whatever is posted', 'Metronidazole 5.1 g · 25 mg per gallon, 205 gal' === end( $store->logs )['note'] );
$r = fh_act( $store, 621, array( 'medication' => 'chloroquine', 'amount' => '4', 'unit' => 'g', 'whole' => 1, 'calc' => 1, 'rate' => '40', 'dose_label' => 'Loading' ) );
check( 'a named dose: "Chloroquine 4 g · Loading: 40 mg per gallon, 100 gal"', 'Chloroquine 4 g · Loading: 40 mg per gallon, 100 gal' === end( $store->logs )['note'] && false !== strpos( $r['message'], 'Loading: 40 mg per gallon, 100 gal' ) );
$r = fh_act( $store, 621, array( 'medication' => 'chloroquine', 'amount' => '1.2', 'unit' => 'g', 'whole' => 1, 'calc' => 1, 'rate' => '12' ) );
check( 'a schedule step\'s rate: "12 mg per gallon, 100 gal"', 'Chloroquine 1.2 g · 12 mg per gallon, 100 gal' === end( $store->logs )['note'] );
$r = fh_act( $store, 621, array( 'medication' => 'metro', 'amount' => '625', 'unit' => 'mg', 'whole' => 1, 'calc' => 1, 'rate' => '25', 'topup' => 1, 'changed' => '25', 'changed_unit' => 'pct' ) );
check( 'a top-up after a 25 % water change: the water that was replaced', 'Metronidazole 625 mg · 25 mg per gallon, 25 gal · top-up after a 25 % water change' === end( $store->logs )['note'] && 'Metronidazole 625 mg · Building 600, whole system · 25 mg per gallon, 25 gal · top-up after a 25 % water change' === $r['message'], end( $store->logs )['note'] );
$r = fh_act( $store, 621, array( 'medication' => 'metro', 'amount' => '750', 'unit' => 'mg', 'whole' => 1, 'calc' => 1, 'rate' => '25', 'topup' => 1, 'changed' => '30', 'changed_unit' => 'gal' ) );
check( '…typed in gallons', 'Metronidazole 750 mg · 25 mg per gallon, 30 gal · top-up after a 30 gal water change' === end( $store->logs )['note'] );
$r = fh_act( $store, 621, array( 'medication' => 'salt', 'amount' => '6', 'unit' => 'tsp', 'whole' => 1, 'topup' => 1, 'changed' => '30', 'changed_unit' => 'pct' ) );
check( 'a typed medication after a water change: the change is said, there is no sum', 'Salt 6 tsp · top-up after a 30 % water change' === end( $store->logs )['note'] );
$n = count( $store->logs );
$r = fh_act( $store, 621, array( 'medication' => 'metro', 'amount' => '3', 'unit' => 'g', 'whole' => 1, 'calc' => 1, 'rate' => '25' ) );
check( 'the amount is what was really added: 3 g typed over the 2.5 g of the sum is what is logged, with the working', array( 'dose|0|' . $b600 . '|3|Metronidazole 3 g · 25 mg per gallon, 100 gal' ) === fh_new_logs( $store, $n ) );

echo "3. To a level: the copper example on the 100 gallon system\n";
$copper = new Fake_Store();
$c621   = $copper->tank_by_number( 621 );
$c611   = $copper->tank_by_number( 611 );
$cb600  = (int) $c621['building_id'];
fh_checkin( $copper, 621, 1, 'Test Goby' ); // Day 2: "Copper Power to 2.4", the copper test and Metronidazole are due.
$post = array( 'medication' => 'copper', 'amount' => '16.6', 'unit' => 'ml', 'whole' => 1, 'calc' => 1, 'now' => '2.16', 'target' => '2.4' );
$n    = count( $copper->logs );
$r    = fh_act( $copper, 621, $post );
check( 'the toast says it plainly: the dose, the water, the working, the reading', 'Copper Power 16.6 ml · Building 600, whole system · 2.16 → 2.40 ppm, 100 gal · copper reading 2.16 logged' === ( $r['message'] ?? '' ) && array() === $r['event_ids'] && 'logged' === $r['reading'], json_encode( $r ) );
check( 'two logs, each once: the reading 2.16 on the building, then the dose with its working', array( 'copper|0|' . $cb600 . '|2.16|', 'dose|0|' . $cb600 . '|16.6|Copper Power 16.6 ml · 2.16 → 2.40 ppm, 100 gal' ) === fh_new_logs( $copper, $n ), json_encode( fh_new_logs( $copper, $n ) ) );
check( 'both carry who and how', 8 === end( $copper->logs )['actor_id'] && 'qr' === end( $copper->logs )['source'] && 8 === $copper->logs[ $n + 1 ]['actor_id'] && 'qr' === $copper->logs[ $n + 1 ]['source'] );
$stay = $copper->open_stays( array(), $today, $settings )[0];
$done = $copper->logs_on_date( $today );
$todo = Protocol::todo( Protocol::seed(), array( $stay ), $today, 'spa', $done[ (int) $c621['id'] ] );
$by   = array();
foreach ( $todo as $item ) {
	$by[ $item['label'] ] = $item['done'];
}
check( 'the day\'s to-do in 621: "Copper Power to 2.4" ticked by the dose, the copper test by the reading; Metronidazole still open', 2 === $stay['day'] && true === $by['Copper Power to 2.4'] && true === $by['Copper test'] && false === $by['Metronidazole'] && false === $by['Feed'], json_encode( $by ) );
check( '…and in 611 too: it is one water', array( 'copper', 'dose' ) === array_column( $done[ (int) $c611['id'] ], 'kind' ) );
$n = count( $copper->logs );
$r = fh_act( $copper, 621, array_merge( $post, array( 'amount' => '5' ) ) );
check( 'saved again with the same Now: the dose once more, NO second reading — and the toast says so', array( 'dose|0|' . $cb600 . '|5|Copper Power 5 ml · 2.16 → 2.40 ppm, 100 gal' ) === fh_new_logs( $copper, $n ) && 'already' === $r['reading'] && false !== strpos( $r['message'], 'copper reading 2.16 was logged already' ), json_encode( fh_new_logs( $copper, $n ) ) );
$n = count( $copper->logs );
$r = fh_act( $copper, 621, array_merge( $post, array( 'now' => '2.3', 'amount' => '6.9' ) ) );
check( 'another Now: a new reading, once', array( 'copper|0|' . $cb600 . '|2.3|', 'dose|0|' . $cb600 . '|6.9|Copper Power 6.9 ml · 2.30 → 2.40 ppm, 100 gal' ) === fh_new_logs( $copper, $n ) );

$plain = new Fake_Store();
$p621  = $plain->tank_by_number( 621 );
Front_Desk::perform( 'copper', array( 'store' => $plain, 'settings' => $settings, 'today' => $today, 'tank' => $plain->tank_by_number( 611 ), 'base' => array( 'actor_id' => 8, 'source' => 'voice' ) ), array( 'value' => '2.16' ) );
$n = count( $plain->logs );
$r = fh_act( $plain, 621, array_merge( $post, array( 'whole' => 0 ) ) );
check( 'the reading was logged as "611 copper 2.16" before — this tank only: 611\'s is not 621\'s, so the reading goes on the tank; 40 gal', array( 'copper|' . $p621['id'] . '|-|2.16|', 'dose|' . $p621['id'] . '|-|16.6|Copper Power 16.6 ml · 2.16 → 2.40 ppm, 40 gal' ) === fh_new_logs( $plain, $n ), json_encode( fh_new_logs( $plain, $n ) ) );
$n = count( $plain->logs );
$r = fh_act( $plain, 621, $post );
check( '…and the whole system from 621\'s page: 621 has the reading itself, only the dose is written', array( 'dose|0|' . (int) $p621['building_id'] . '|16.6|Copper Power 16.6 ml · 2.16 → 2.40 ppm, 100 gal' ) === fh_new_logs( $plain, $n ) && 'already' === $r['reading'] );
$sib  = new Fake_Store();
$s621 = $sib->tank_by_number( 621 );
$sb   = (int) $s621['building_id'];
fh_checkin( $sib, 621, 1, 'Test Goby' );
Front_Desk::perform( 'copper', array( 'store' => $sib, 'settings' => $settings, 'today' => $today, 'tank' => $sib->tank_by_number( 611 ), 'base' => array( 'actor_id' => 8, 'source' => 'voice' ) ), array( 'value' => '2.16' ) );
check( 'a reading on the sibling tank 611 fills "Now" for the whole system on 621\'s page (one water) — and not for 621 alone', 2.16 === Dose::waters( $sib, $s621, $today, $settings )['system']['reading']['value'] && null === Dose::waters( $sib, $s621, $today, $settings )['tank']['reading'] );
$n = count( $sib->logs );
$r = fh_act( $sib, 621, $post );
check( '…but it is not the system\'s reading yet: the whole-system Save logs 2.16 on the building, then the dose', array( 'copper|0|' . $sb . '|2.16|', 'dose|0|' . $sb . '|16.6|Copper Power 16.6 ml · 2.16 → 2.40 ppm, 100 gal' ) === fh_new_logs( $sib, $n ) && 'logged' === $r['reading'] && false !== strpos( $r['message'], 'copper reading 2.16 logged' ), json_encode( fh_new_logs( $sib, $n ) ) );
$sdone = $sib->logs_on_date( $today );
$sby   = array();
foreach ( Protocol::todo( Protocol::seed(), array( $sib->open_stays( array(), $today, $settings )[0] ), $today, 'spa', $sdone[ (int) $s621['id'] ] ) as $item ) {
	$sby[ $item['label'] ] = $item['done'];
}
check( '…so 621\'s own "Copper test" is ticked, and its copper chip has the reading', true === $sby['Copper test'] && true === $sby['Copper Power to 2.4'] && 2.16 === Dose::waters( $sib, $s621, $today, $settings )['tank']['reading']['value'], json_encode( $sby ) );
$n = count( $sib->logs );
$r = fh_act( $sib, 621, array_merge( $post, array( 'amount' => '3' ) ) );
check( '…once: the building has it now, a second Save writes no second reading', array( 'dose|0|' . $sb . '|3|Copper Power 3 ml · 2.16 → 2.40 ppm, 100 gal' ) === fh_new_logs( $sib, $n ) && 'already' === $r['reading'] );
$old = new Fake_Store();
$old->log_building( (int) $old->tank_by_number( 621 )['building_id'], array( 'kind' => 'copper', 'value' => 2.16, 'logged_at' => gmdate( 'Y-m-d H:i:s', time() - 2 * 86400 ) ) );
$n = count( $old->logs );
fh_act( $old, 621, $post );
check( 'the same value read two days ago is not today\'s: logged', 2 === count( fh_new_logs( $old, $n ) ) && 'copper' === $old->logs[ $n + 1 ]['kind'] );
$n = count( $copper->logs );
$r = fh_act( $copper, 621, array( 'medication' => 'copper', 'amount' => '4', 'unit' => 'ml', 'whole' => 1, 'calc' => 1, 'now' => '2.5', 'target' => '2.4' ) );
check( 'at or above the target the sum says "nothing to add", but what was typed and saved is logged, with the readings', array( 'copper|0|' . $cb600 . '|2.5|', 'dose|0|' . $cb600 . '|4|Copper Power 4 ml · 2.50 → 2.40 ppm, 100 gal' ) === fh_new_logs( $copper, $n ) );
$n = count( $copper->logs );
$r = fh_act( $copper, 621, array( 'medication' => 'copper', 'amount' => '4', 'unit' => 'ml', 'whole' => 1, 'calc' => 1, 'now' => 'abc', 'target' => '2.4' ) );
check( 'a Now that is no number: a plain dose, no reading, no working', array( 'dose|0|' . $cb600 . '|4|Copper Power 4 ml' ) === fh_new_logs( $copper, $n ) && '' === $r['reading'] );
$n = count( $copper->logs );
$r = fh_act( $copper, 621, array( 'medication' => 'copper', 'amount' => '20', 'unit' => 'ml', 'whole' => 1, 'calc' => 1, 'now' => '2', 'target' => '2.4', 'topup' => 1, 'changed' => '25' ) );
check( 'a top-up of a medication dosed to a level stays Now → Bring it to; the water change is said', 'Copper Power 20 ml · 2.00 → 2.40 ppm, 100 gal · top-up after a 25 % water change' === end( $copper->logs )['note'] && 2 === count( fh_new_logs( $copper, $n ) ) );

echo "3b. Because a dose has no undo: a sheet that is behind, a slipped decimal, the reading alone\n";
$guard = new Fake_Store();
$g621  = $guard->tank_by_number( 621 );
$gb    = (int) $g621['building_id'];
$sheet = array_merge( $post, array( 'seen' => '' ) ); // The sheet knew of no copper dose today.
$n     = count( $guard->logs );
$r     = fh_act( $guard, 621, $sheet );
check( 'a sheet that knew of no dose, and there is none: saved — the reading and the dose', empty( $r['error'] ) && 2 === count( fh_new_logs( $guard, $n ) ) );
$n = count( $guard->logs );
$r = fh_act( $guard, 621, $sheet );
check( 'the SAME post again (a second tap, a retry after a reply that never came, a second phone): refused — no second dose, no second reading', 1 === preg_match( '/^Copper Power 16\.6 ml was logged in this water at \d\d:\d\d, after this sheet was opened\. Check it — saving again logs another dose\.$/', (string) ( $r['error'] ?? '' ) ) && $n === count( $guard->logs ), json_encode( $r ) );
check( '…the refusal carries what went in, as the refreshed sheet will show it', 'Copper Power 16.6 ml' === $r['stale']['text'] && true === $r['stale']['after'] && 1 === preg_match( '/^\d\d:\d\d$/', $r['stale']['at'] ) && end( $guard->logs )['logged_at'] === $r['stale']['logged_at'] );
$n  = count( $guard->logs );
$r2 = fh_act( $guard, 621, array_merge( $sheet, array( 'seen' => $r['stale']['logged_at'], 'now' => '2.3', 'amount' => '6.9' ) ) );
check( '…saving again from the refreshed sheet (it knows of that dose now) goes through', empty( $r2['error'] ) && array( 'copper|0|' . $gb . '|2.3|', 'dose|0|' . $gb . '|6.9|Copper Power 6.9 ml · 2.30 → 2.40 ppm, 100 gal' ) === fh_new_logs( $guard, $n ), json_encode( $r2 ) );
$n = count( $guard->logs );
$r = fh_act( $guard, 621, array( 'medication' => 'metro', 'amount' => '2.5', 'unit' => 'g', 'whole' => 1, 'calc' => 1, 'rate' => '25', 'seen' => '' ) );
check( 'another medication is another matter: Metronidazole from a sheet that knew of no Metronidazole is saved', empty( $r['error'] ) && 1 === count( fh_new_logs( $guard, $n ) ) );
$n = count( $guard->logs );
$r = fh_act( $guard, 611, array( 'medication' => 'metro', 'amount' => '1', 'unit' => 'g', 'whole' => 0, 'seen' => 'yesterday' ) );
check( '…a whole-system dose is in each tank\'s water: "this tank only" on 611 from a sheet that is behind is refused too, typed amount or not', false !== strpos( (string) ( $r['error'] ?? '' ), 'Metronidazole 2.5 g was logged in this water' ) && $n === count( $guard->logs ) );
$r = fh_act( $guard, 611, array( 'medication' => 'metro', 'amount' => '1', 'unit' => 'g', 'whole' => 0 ) );
check( '…and a post that says nothing of what it knew (no sheet behind it) is the plain dose it always was', empty( $r['error'] ) && 'Metronidazole 1 g · Tank 611' === $r['message'] );
check( 'Dose::newer_dose(): none logged, the one the sheet knew, a newer one, a malformed "seen"', null === Dose::newer_dose( array( 'doses' => array() ), 'metro', '' ) && null === Dose::newer_dose( array( 'doses' => array( 'metro' => array( 'text' => 'x', 'at' => '09:00', 'logged_at' => '2026-03-02 15:00:00' ) ) ), 'metro', '2026-03-02 15:00:00' ) && is_array( Dose::newer_dose( array( 'doses' => array( 'metro' => array( 'text' => 'x', 'at' => '09:00', 'logged_at' => '2026-03-02 15:00:01' ) ) ), 'metro', '2026-03-02 15:00:00' ) ) && is_array( Dose::newer_dose( array( 'doses' => array( 'metro' => array( 'text' => 'x', 'at' => '09:00', 'logged_at' => '2026-03-02 15:00:01' ) ) ), 'metro', array( 'zzzz' ) ) ) );

$slip = new Fake_Store();
$sb6  = (int) $slip->tank_by_number( 621 )['building_id'];
$n    = count( $slip->logs );
$r    = fh_act( $slip, 621, array_merge( $post, array( 'now' => '216', 'amount' => '5' ) ) );
check( '"Now" 216 (a slip for 2.16): refused like a plain copper reading of 216 — nothing written, not even the dose', 'A copper level is a number like 2.4 — at most 99. Check "Now" and "Bring it to".' === ( $r['error'] ?? '' ) && $n === count( $slip->logs ) && ! empty( Front_Desk::perform( 'copper', array( 'store' => $slip, 'settings' => $settings, 'today' => $today, 'tank' => $slip->tank_by_number( 621 ), 'base' => array( 'actor_id' => 8, 'source' => 'qr' ) ), array( 'value' => '216' ) )['error'] ) && $n === count( $slip->logs ), json_encode( $r ) );
$r = fh_act( $slip, 621, array_merge( $post, array( 'target' => '240', 'amount' => '5' ) ) );
check( '…"Bring it to" 240 the same; 99 is still a level', 'A copper level is a number like 2.4 — at most 99. Check "Now" and "Bring it to".' === ( $r['error'] ?? '' ) && $n === count( $slip->logs ) && Dose::MAX_COPPER === 99 );
$r = fh_act( $slip, 621, array_merge( $post, array( 'target' => '24', 'amount' => '1511.3' ) ) );
check( '"Bring it to" 24 (a slip for 2.4; the copper target is 2.5): asked about first — nothing written', '"Bring it to" 24.00 is far above the copper target (2.50). If that is right, save again.' === ( $r['error'] ?? '' ) && 24.0 === $r['confirm'] && $n === count( $slip->logs ), json_encode( $r ) );
$r = fh_act( $slip, 621, array_merge( $post, array( 'target' => '24', 'amount' => '1511.3', 'sure' => 1 ) ) );
check( '…saved again, it is what was meant: written', empty( $r['error'] ) && array( 'copper|0|' . $sb6 . '|2.16|', 'dose|0|' . $sb6 . '|1511.3|Copper Power 1511.3 ml · 2.16 → 24.00 ppm, 100 gal' ) === fh_new_logs( $slip, $n ), json_encode( fh_new_logs( $slip, $n ) ) );
check( 'far above is twice the copper target or more — 4.9 is not, 5 is; never without a target in the settings, never for an empty level', false === Dose::far_above( 4.9, $settings ) && true === Dose::far_above( 5.0, $settings ) && false === Dose::far_above( 2.4, $settings ) && false === Dose::far_above( 24, array() ) && false === Dose::far_above( null, $settings ) );
$n = count( $slip->logs );
$r = fh_act( $slip, 621, array( 'medication' => 'copper', 'amount' => '30', 'unit' => 'ml', 'whole' => 1 ) );
check( 'a copper dose typed without the sum has no levels to check', empty( $r['error'] ) && 1 === count( fh_new_logs( $slip, $n ) ) );

$above = new Fake_Store();
$a621  = $above->tank_by_number( 621 );
$ab    = (int) $a621['building_id'];
$only  = array( 'medication' => 'copper', 'amount' => '', 'unit' => 'ml', 'whole' => 1, 'calc' => 1, 'now' => '2.45', 'target' => '2.4', 'seen' => '' );
$n     = count( $above->logs );
$r     = fh_act( $above, 621, $only );
check( 'at or above the target with no amount ("Nothing to add"): Save keeps its word — the reading alone, on that water, and the toast says so', 'Copper 2.45 logged · Building 600, whole system · nothing to add' === ( $r['message'] ?? '' ) && array( 'copper|0|' . $ab . '|2.45|' ) === fh_new_logs( $above, $n ) && 'logged' === $r['reading'] && array() === $r['event_ids'], json_encode( $r ) );
$n = count( $above->logs );
$r = fh_act( $above, 621, $only );
check( '…once: saved again, nothing more is written', 'Copper 2.45 was logged already · Building 600, whole system · nothing to add' === ( $r['message'] ?? '' ) && $n === count( $above->logs ) && 'already' === $r['reading'] );
$n = count( $above->logs );
$r = fh_act( $above, 611, array_merge( $only, array( 'whole' => 0, 'now' => '2.6' ) ) );
check( '…this tank only: on the tank', 'Copper 2.60 logged · Tank 611 · nothing to add' === ( $r['message'] ?? '' ) && array( 'copper|' . $above->tank_by_number( 611 )['id'] . '|-|2.6|' ) === fh_new_logs( $above, $n ) );
$n = count( $above->logs );
check( 'no amount BELOW the target, for another medication, without the sum, with a level that is none or above 99: refused as before, nothing written', 'How much? An amount above 0 and at most 9999.' === ( fh_act( $above, 621, array_merge( $only, array( 'now' => '2.1' ) ) )['error'] ?? '' ) && 'How much? An amount above 0 and at most 9999.' === ( fh_act( $above, 621, array( 'medication' => 'metro', 'amount' => '', 'unit' => 'g', 'whole' => 1, 'calc' => 1, 'rate' => '25' ) )['error'] ?? '' ) && 'How much? An amount above 0 and at most 9999.' === ( fh_act( $above, 621, array( 'medication' => 'copper', 'amount' => '', 'unit' => 'ml', 'whole' => 1 ) )['error'] ?? '' ) && 'How much? An amount above 0 and at most 9999.' === ( fh_act( $above, 621, array_merge( $only, array( 'now' => 'abc' ) ) )['error'] ?? '' ) && 'A copper level is a number like 2.4 — at most 99. Check "Now" and "Bring it to".' === ( fh_act( $above, 621, array_merge( $only, array( 'now' => '245' ) ) )['error'] ?? '' ) && $n === count( $above->logs ) );

echo "4. Unknown gallons, another medication dosed to a level, a failing store\n";
$dry = new Fake_Store();
$d121 = $dry->tank_by_number( 121 );
foreach ( $dry->tanks as $id => $row ) {
	if ( (int) $row['building_id'] === (int) $d121['building_id'] ) {
		$dry->tanks[ $id ]['gallons'] = 0;
	}
}
$n = count( $dry->logs );
$r = fh_act( $dry, 121, array( 'medication' => 'metro', 'amount' => '2', 'unit' => 'g', 'whole' => 1, 'calc' => 1, 'rate' => '25' ) );
check( 'per gallon in a water of unknown size: the amount typed is logged, no working is invented', array( 'dose|0|' . $d121['building_id'] . '|2|Metronidazole 2 g' ) === fh_new_logs( $dry, $n ) && 0.0 === Dose::waters( $dry, $dry->tank_by_number( 121 ), $today, $settings )['system']['gallons'] );
$n = count( $dry->logs );
$r = fh_act( $dry, 121, array( 'medication' => 'copper', 'amount' => '9', 'unit' => 'ml', 'whole' => 1, 'calc' => 1, 'now' => '2.16', 'target' => '2.4' ) );
check( 'to a level in a water of unknown size: the readings are kept, and the reading logged', array( 'copper|0|' . $d121['building_id'] . '|2.16|', 'dose|0|' . $d121['building_id'] . '|9|Copper Power 9 ml · 2.16 → 2.40 ppm' ) === fh_new_logs( $dry, $n ) );
$list = Medications::apply_op( Medications::seed(), 'add', array( 'label' => 'Test Ramp Liquid', 'short' => 'Ramp', 'unit' => 'ml', 'mode' => 'to_level', 'rate' => '2', 'per' => '5', 'level_unit' => 'ppm' ) )['list'];
$list = Medications::apply_op( $list, 'retire', array( 'key' => 'formalin' ) )['list'];
$list = Medications::apply_op( $list, 'add', array( 'label' => 'Test Kanamycin', 'short' => 'Kana', 'unit' => 'mg', 'mode' => 'per_gallon', 'rate' => '30' ) )['list'];
Settings::save_medications( $list );
$settings = Settings::get();
$n        = count( $store->logs );
$r        = fh_act( $store, 621, array( 'medication' => 'test-ramp-liquid', 'amount' => '10', 'unit' => 'ml', 'whole' => 1, 'calc' => 1, 'now' => '0.25', 'target' => '0.5' ) );
check( 'a medication added in Settings, dosed to a level: its working, and NO copper reading — the copper log is the copper product\'s', array( 'dose|0|' . $b600 . '|10|Test Ramp Liquid 10 ml · 0.25 → 0.50 ppm, 100 gal' ) === fh_new_logs( $store, $n ) && '' === $r['reading'], json_encode( fh_new_logs( $store, $n ) ) );
$r = fh_act( $store, 621, array( 'medication' => 'test-kanamycin', 'amount' => '3', 'unit' => 'g', 'whole' => 1, 'calc' => 1, 'rate' => '30' ) );
check( '…and one dosed per gallon', 'Test Kanamycin 3 g · Building 600, whole system · 30 mg per gallon, 100 gal' === ( $r['message'] ?? '' ) && 'test-kanamycin' === Protocol::medication_key( end( $store->logs )['note'] ) );
check( 'a retired medication is refused', 'Which medication? Pick one.' === ( fh_act( $store, 621, array( 'medication' => 'formalin', 'amount' => '10', 'unit' => 'ml' ) )['error'] ?? '' ) );
check( '…and not offered to the page; the two new ones are, last', array( 'copper', 'metro', 'prazi', 'chloroquine', 'fenbendazole', 'salt', 'test-ramp-liquid', 'test-kanamycin' ) === array_column( Front_Desk::medications(), 'key' ) && false === Front_Desk::medications()[6]['copper'] && 'ppm' === Front_Desk::medications()[6]['levelUnit'] );
$GLOBALS['fh_options'] = array();
$settings              = Settings::get();

/**
 * A store whose dose log fails after the reading went in.
 */
class Fh_Dose_Fails extends Fake_Store {
	public function log_dose( $building_id, $tank_id, $medication, $amount, $unit, $note = '', $actor_id = 0, $source = 'manual' ) {
		return false;
	}
}
$broken = new Fh_Dose_Fails();
$r      = fh_act( $broken, 621, $post );
check( 'the dose cannot be saved: an error — which says the reading did go in', 'The dose could not be saved (the copper reading 2.16 was logged).' === ( $r['error'] ?? '' ) && 1 === count( $broken->logs ) && 'copper' === end( $broken->logs )['kind'] );
check( '…and without a reading, the plain error', 'The dose could not be saved.' === ( fh_act( $broken, 621, array( 'medication' => 'metro', 'amount' => '5', 'unit' => 'g' ) )['error'] ?? '' ) );

echo "5. Afterwards: the Events line and the Board's last dose\n";
$latest = $copper->tank_logs( 0, 12 );
$lines  = array_map( array( Admin::class, 'log_text' ), $latest );
check( 'the Events tab line of a dose shows the working', in_array( 'Copper Power 16.6 ml · 2.16 → 2.40 ppm, 100 gal · Building 600, whole system', $lines, true ) && in_array( 'copper 2.16 · Building 600, whole system', $lines, true ), json_encode( $lines ) );
$board = Dose::board_doses( $copper->latest_doses() );
check( 'the Board\'s last dose for the building shows the amount and the working', 'Copper Power 20 ml (2.00 → 2.40 ppm, 100 gal · top-up after a 25 % water change)' === $board[ $cb600 ]['copper']['label'] && 1 === count( $board[ $cb600 ] ), json_encode( $board ) );
$board = Dose::board_doses( $store->latest_doses() );
check( '…per medication, the latest; an older dose without a working as it was logged', 'Metronidazole 3 g (25 mg per gallon, 100 gal)' === $board[ $b600 ]['metro']['label'] && 'Metronidazole 5.1 g (25 mg per gallon, 205 gal)' === $board[ $b500 ]['metro']['label'] && 'Copper Power 55 ml' === $board[ $b500 ]['copper']['label'] && 'Salt 6 tsp (top-up after a 30 % water change)' === $board[ $b600 ]['salt']['label'], json_encode( $board[ $b600 ] ) );
check( 'the note is the log\'s own: anything that lists the tank log lists the working with it', 'Copper Power 16.6 ml · 2.16 → 2.40 ppm, 100 gal' === $copper->tank_logs( 0, 100 )[ count( $copper->tank_logs( 0, 100 ) ) - 2 ]['note'] );

echo "6. What the page is handed\n";
$i18n = Front_Desk::i18n();
check( 'the words of the sum, and the new labels', Dose::strings() === $i18n['doseStrings'] && 'Calculator' === $i18n['calculator'] && 'Now' === $i18n['nowLabel'] && 'Bring it to' === $i18n['targetLabel'] && 'A dose cannot be undone — note a correction instead.' === $i18n['noUndoDose'] && isset( $i18n['topupLabel'], $i18n['changedLabel'], $i18n['calcHint'], $i18n['noMeds'], $i18n['alsoReading'] ) );
$js = (string) file_get_contents( dirname( __DIR__ ) . '/js/front-desk.js' );
preg_match_all( "/\\bt\\('([A-Za-z]+)'/", $js, $m );
$missing = array_values( array_diff( array_unique( $m[1] ), array_keys( $i18n ) ) );
check( 'every label the page asks for is one the server sends', array() === $missing, implode( ', ', $missing ) );

fh_finish();
