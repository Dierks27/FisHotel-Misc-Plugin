<?php
/**
 * Dose — the sum behind the Dose sheet, the Calculator and the dose
 * phrases, and what the room knows that fills it in.
 *
 * The first half runs Dose against fixtures/dose-maths.json, the same
 * file tests/dose-math.test.js runs js/dose-math.js against, so the page
 * and the server give the same amount, result line and working: the
 * rounding, the sensible unit of a weight, every way of dosing (per
 * gallon, to a level, typed), named doses, a schedule step's rate, the
 * top-up after a water change, zero and negative differences, unknown
 * gallons. The second half is the server's alone: what a schedule step
 * names, what is due today, today's copper reading (from today, from
 * yesterday, none), what "Now" and "Bring it to" start with (day 1, day
 * 2, a day with no copper step), the two waters of a tank's page against
 * the in-memory store, what a Save's post turns into, the reading a
 * copper dose brings along, and the Board's "last dose".
 *
 * Run from the plugin root:  php includes/sections/clean-room/tests/dose-test.php
 */

require_once __DIR__ . '/wp-stubs.php';

use FisHotel\Misc\Sections\Clean_Room\Dose;
use FisHotel\Misc\Sections\Clean_Room\Medications;
use FisHotel\Misc\Sections\Clean_Room\Protocol;
use FisHotel\Misc\Sections\Clean_Room\Replay;
use FisHotel\Misc\Sections\Clean_Room\Settings;
use FisHotel\Misc\Sections\Clean_Room\Store;
use FisHotel\Misc\Sections\Clean_Room\Tests\Fake_Store;

$fixture = json_decode( (string) file_get_contents( __DIR__ . '/fixtures/dose-maths.json' ), true );
if ( ! is_array( $fixture ) || empty( $fixture['cases'] ) ) {
	fwrite( STDERR, "Cannot read fixtures/dose-maths.json\n" );
	exit( 1 );
}

echo "1. Rounding and units (the fixture the page's JS reads too)\n";
foreach ( $fixture['round_amount'] as $row ) {
	check( 'round_amount(' . $row[0] . ') = ' . $row[1], fh_json_same( $row[1], Dose::round_amount( $row[0] ) ), (string) Dose::round_amount( $row[0] ) );
}
foreach ( $fixture['display'] as $row ) {
	$shown = Dose::display( $row[0], $row[1] );
	check( 'display(' . $row[0] . ' ' . $row[1] . ') = ' . $row[2] . ' ' . $row[3], fh_json_same( $row[2], $shown['amount'] ) && $row[3] === $shown['unit'], json_encode( $shown ) );
}
check( 'convert(): mg and g go together, nothing else does', 2500.0 === Dose::convert( 2.5, 'g', 'mg' ) && 0.25 === Dose::convert( 250, 'mg', 'g' ) && 3.0 === Dose::convert( 3, 'ml', 'ml' ) && null === Dose::convert( 1, 'ml', 'g' ) && null === Dose::convert( 1, 'tsp', 'ml' ) && Dose::compatible( 'g', 'mg' ) && ! Dose::compatible( 'drops', 'ml' ) );

echo "2. Words\n";
foreach ( $fixture['level_text'] as $row ) {
	check( 'level_text(' . $row[0] . ') = "' . $row[1] . '"', $row[1] === Dose::level_text( $row[0] ), Dose::level_text( $row[0] ) );
}
foreach ( $fixture['num'] as $row ) {
	check( 'num(' . $row[0] . ', ' . $row[1] . ') = "' . $row[2] . '"', $row[2] === Dose::num( $row[0], $row[1] ), Dose::num( $row[0], $row[1] ) );
}
foreach ( $fixture['rate_text'] as $row ) {
	check( 'rate_text(' . $row[0] . ' ' . $row[1] . ' per ' . $row[2] . ') = "' . $row[3] . '"', $row[3] === Dose::rate_text( $row[0], $row[1], $row[2] ), Dose::rate_text( $row[0], $row[1], $row[2] ) );
}
foreach ( $fixture['plural'] as $row ) {
	check( 'plural("' . $row[0] . '", ' . $row[1] . ') = "' . $row[2] . '"', $row[2] === Dose::plural( $row[0], $row[1] ), Dose::plural( $row[0], $row[1] ) );
}
check( 'amount_text: a unit, or "×" for pieces', '2.5 g' === Dose::amount_text( 2.5, 'g' ) && '4 ×' === Dose::amount_text( 4, 'each' ) && '16.6 ml' === Dose::amount_text( 16.6, 'ml' ) );
check( 'level(): a number from 0 to 999, else null', 2.16 === Dose::level( '2.16' ) && 0.0 === Dose::level( 0 ) && null === Dose::level( '' ) && null === Dose::level( null ) && null === Dose::level( 'abc' ) && null === Dose::level( -1 ) && null === Dose::level( 1000 ) && null === Dose::level( true ) && null === Dose::level( array( 2 ) ) );
check( 'the words are the JS file\'s, key for key', array_keys( Dose::strings() ) === array( 'add', 'nothing', 'atOrAbove', 'typed', 'needsNow', 'needsTarget', 'needsChanged', 'tooMuch', 'noGallons', 'perGallon', 'perGallons', 'gal', 'ofWater', 'topup', 'topupPlain', 'measure' ) );
$js = (string) file_get_contents( dirname( __DIR__ ) . '/js/dose-math.js' );
$drift = array();
foreach ( Dose::strings() as $key => $text ) {
	if ( false === strpos( $js, $key . ": '" . $text . "'" ) ) {
		$drift[] = $key;
	}
}
check( '…and say the same English (dose-math.js carries each as its fallback)', array() === $drift, implode( ', ', $drift ) );

echo "3. The sums\n";
foreach ( $fixture['cases'] as $case ) {
	$got = Dose::plan( $fixture['medications'][ $case['medication'] ], $case['args'] );
	$bad = array();
	foreach ( array( 'state', 'amount', 'unit', 'line', 'working', 'note', 'measure' ) as $key ) {
		if ( ! fh_json_same( $case['expect'][ $key ], $got[ $key ] ) ) {
			$bad[] = $key . ': expected ' . json_encode( $case['expect'][ $key ], JSON_UNESCAPED_UNICODE ) . ', got ' . json_encode( $got[ $key ], JSON_UNESCAPED_UNICODE );
		}
	}
	check( $case['name'], array() === $bad, implode( '; ', $bad ) );
}
check( 'the plan has exactly the seven fields the page reads', array( 'state', 'amount', 'unit', 'line', 'working', 'note', 'measure' ) === array_keys( Dose::plan( $fixture['medications']['metro'], array( 'gallons' => 100 ) ) ) );
check( 'a medication that is not one: typed, never a throw', 'typed' === Dose::plan( array() )['state'] && 'needs_now' === Dose::plan( $fixture['medications']['copper'] )['state'] && 'no_gallons' === Dose::plan( $fixture['medications']['metro'] )['state'] );

echo "4. The seeded list gives the same sums (the fixed list's suggestions, amount and unit)\n";
$seed = Medications::seed();
check( 'the copper example on the seeded copper product: 16.6 ml', 16.6 === Dose::plan( $seed['copper'], array( 'gallons' => 100, 'now' => 2.16, 'target' => 2.4 ) )['amount'] && 'Add 16.6 ml Copper Power' === Dose::plan( $seed['copper'], array( 'gallons' => 100, 'now' => 2.16, 'target' => 2.4 ) )['line'] );
check( '100 gallons of the seeded Metronidazole: 2.5 g', array( 2.5, 'g', '100 gal × 25 mg' ) === array( Dose::plan( $seed['metro'], array( 'gallons' => 100 ) )['amount'], Dose::plan( $seed['metro'], array( 'gallons' => 100 ) )['unit'], Dose::plan( $seed['metro'], array( 'gallons' => 100 ) )['working'] ) );
$old   = array();
$small = array();
foreach ( Protocol::DOSE_RATES as $key => $rate ) {
	for ( $gallons = 1; $gallons <= 4000; $gallons++ ) {
		// What the fixed list suggested, as its code had it: gallons × the rate in the fixed unit, one decimal from 1 up, two below.
		$amount = $gallons * $rate;
		$amount = round( $amount, $amount >= 1 ? 1 : 2 );
		$unit   = Protocol::MEDICATIONS[ $key ]['unit'];
		$now    = Protocol::dose_suggestion( $key, $gallons );
		if ( 'g' === $unit && $gallons * $rate < 0.0995 ) {
			// Under 0.1 g two decimals of a gram said too little ("0.05 g"): milligrams now, the same weight.
			if ( ! $now || 'mg' !== $now['unit'] || abs( $now['amount'] - $gallons * $rate * 1000 ) > 0.05 + 1e-9 ) {
				$small[] = $key . '@' . $gallons . ': ' . json_encode( $now );
			}
			continue;
		}
		if ( array( 'amount' => $amount, 'unit' => $unit ) !== $now ) {
			$old[] = $key . '@' . $gallons . ': ' . $amount . ' ' . $unit . ' vs ' . json_encode( $now );
		}
	}
}
check( 'every medication of the fixed list, on every water from 1 to 4000 gal: exactly the amount AND the unit the fixed list suggested (prazi on 100 gal is 0.95 g, on 55 gal 0.52 g) — nothing changes until the list is edited', array() === $old, implode( ', ', array_slice( $old, 0, 8 ) ) );
check( '…except under 0.1 g (a bucket), where the same weight now reads in milligrams', array() === $small && array( 'amount' => 47.5, 'unit' => 'mg' ) === Protocol::dose_suggestion( 'prazi', 5 ), implode( ', ', array_slice( $small, 0, 8 ) ) );
check( 'Protocol::dose_suggestion() still answers: metro 5.1 g on 205, formalin 20.5 ml, nothing for copper / salt / an unknown key / no volume', array( 'amount' => 5.1, 'unit' => 'g' ) === Protocol::dose_suggestion( 'metro', 205 ) && array( 'amount' => 20.5, 'unit' => 'ml' ) === Protocol::dose_suggestion( 'formalin', 205 ) && null === Protocol::dose_suggestion( 'copper', 205 ) && null === Protocol::dose_suggestion( 'salt', 205 ) && null === Protocol::dose_suggestion( 'x', 205 ) && null === Protocol::dose_suggestion( 'metro', 0 ) );

echo "5. What a schedule step names\n";
$quine = $seed['chloroquine'];
check( '"Chloroquine 40 mg/gal" → 40 (mg per gallon)', 40.0 === Dose::step_rate( 'Chloroquine 40 mg/gal', $quine ) && 12.0 === Dose::step_rate( 'Chloroquine 12 mg/gal', $quine ) && 6.0 === Dose::step_rate( 'Chloroquine 6 mg/gal', $quine ) );
check( 'spelled out, with spaces, per N gallons', 25.0 === Dose::step_rate( 'Metro 25 mg per gallon', $seed['metro'] ) && 25.0 === Dose::step_rate( 'Metro 25 mg / gal', $seed['metro'] ) && 25.0 === Dose::step_rate( 'Metro 2.5 g per 100 gallons', $seed['metro'] ) );
check( 'into the medication\'s own unit and gallons: "1 ml per 10 gal" is 1 for Formalin (ml per 10 gal), "0.2 ml/gal" is 2', 1.0 === Dose::step_rate( 'Formalin 1 ml per 10 gal', $seed['formalin'] ) && 2.0 === Dose::step_rate( 'Formalin 0.2 ml/gal', $seed['formalin'] ) && 1.0 === Dose::step_rate( 'Fenben 10 mg/gal', $seed['fenbendazole'] ) );
check( 'no rate in the label, or one in a unit that does not convert: null', null === Dose::step_rate( 'Metronidazole', $seed['metro'] ) && null === Dose::step_rate( 'Praziquantel 2', $seed['prazi'] ) && null === Dose::step_rate( 'Metro 1 ml/gal', $seed['metro'] ) && null === Dose::step_rate( '25 % water change, then the combo', $seed['metro'] ) && null === Dose::step_rate( 'Metro 0 mg/gal', $seed['metro'] ) );
check( '"Copper Power to 2.0" → 2.0, "to 2.4" → 2.4', 2.0 === Dose::step_level( 'Copper Power to 2.0' ) && 2.4 === Dose::step_level( 'Copper Power to 2.4' ) && 2.25 === Dose::step_level( 'copper TO 2.25 ppm' ) );
check( 'no level in the label: null', null === Dose::step_level( 'Copper test' ) && null === Dose::step_level( 'Bath, then move to the Clean Room' ) && null === Dose::step_level( 'Copper Power' ) && null === Dose::step_level( 'Photo 2.0' ) );

echo "6. What is due today, per medication\n";
$today = '2026-04-10';
$cm    = Protocol::seed();
$cq    = Protocol::templates()['CQ'];
$stay  = static function ( $id, $day, $qty = 3 ) {
	return array( 'id' => $id, 'species' => 'Test Fish ' . $id, 'qty_live' => $qty, 'day' => $day, 'checkin_date' => '2026-04-01' );
};
$items = Dose::due_items( array( $stay( 1, 1 ) ), $cm, $today, 'spa' );
check( 'day 1 on the seeded schedule: the dose steps only — Copper Power to 2.0', array( array( 'label' => 'Copper Power to 2.0', 'kind' => 'dose', 'day' => 1 ) ) === $items, json_encode( $items ) );
$items = Dose::due_items( array( $stay( 1, 2 ) ), $cm, $today, 'spa' );
check( 'day 2: Copper Power to 2.4 and Metronidazole (the copper test is no dose)', array( 'Copper Power to 2.4', 'Metronidazole' ) === array_column( $items, 'label' ) );
check( 'a group with no live fish, or in the Clean Room, has no Spa steps', array() === Dose::due_items( array( $stay( 1, 2, 0 ) ), $cm, $today, 'spa' ) && array() === Dose::due_items( array( $stay( 1, 2 ) ), $cm, $today, 'clean' ) );
check( 'the day comes from the check-in when the stay carries none (Apr 10 − Apr 9 = day 2)', array( 'Copper Power to 2.4', 'Metronidazole' ) === array_column( Dose::due_items( array( array( 'id' => 5, 'qty_live' => 1, 'checkin_date' => '2026-04-09' ) ), $cm, $today, 'spa' ), 'label' ) );
$opts = Dose::due_options( $seed['copper'], Dose::due_items( array( $stay( 1, 1 ) ), $cm, $today, 'spa' ), $seed );
check( 'copper on day 1: the level 2.0, from "Copper Power to 2.0"', array( array( 'value' => 2.0, 'label' => 'Copper Power to 2.0', 'day' => 1 ) ) === $opts, json_encode( $opts ) );
$opts = Dose::due_options( $seed['copper'], Dose::due_items( array( $stay( 1, 2 ) ), $cm, $today, 'spa' ), $seed );
check( 'copper on day 2: 2.4', 1 === count( $opts ) && 2.4 === $opts[0]['value'] && 2 === $opts[0]['day'] );
check( 'copper on day 5: no copper dose step, so nothing', array() === Dose::due_options( $seed['copper'], Dose::due_items( array( $stay( 1, 5 ) ), $cm, $today, 'spa' ), $seed ) );
$opts = Dose::due_options( $seed['copper'], Dose::due_items( array( $stay( 1, 2 ), $stay( 2, 1 ), $stay( 3, 2 ) ), $cm, $today, 'spa' ), $seed );
check( 'two groups in one water on days 1 and 2: both levels once, the lowest first', array( 2.0, 2.4 ) === array_column( $opts, 'value' ) );
check( 'Metronidazole on day 2 names no rate: nothing (the medication\'s own rate stands)', array() === Dose::due_options( $seed['metro'], Dose::due_items( array( $stay( 1, 2 ) ), $cm, $today, 'spa' ), $seed ) );
$opts = Dose::due_options( $quine, Dose::due_items( array( $stay( 1, 3 ) ), $cq, $today, 'spa' ), $seed );
check( 'Chloroquine on CQ day 3: the step\'s 40 mg per gallon', array( array( 'value' => 40.0, 'label' => 'Chloroquine 40 mg/gal', 'day' => 3 ) ) === $opts, json_encode( $opts ) );
check( '…day 6: 12, day 14: 6, day 4: none', array( 12.0 ) === array_column( Dose::due_options( $quine, Dose::due_items( array( $stay( 1, 6 ) ), $cq, $today, 'spa' ), $seed ), 'value' ) && array( 6.0 ) === array_column( Dose::due_options( $quine, Dose::due_items( array( $stay( 1, 14 ) ), $cq, $today, 'spa' ), $seed ), 'value' ) && array() === Dose::due_options( $quine, Dose::due_items( array( $stay( 1, 4 ) ), $cq, $today, 'spa' ), $seed ) );
check( 'a step is the FIRST medication it names: "Metronidazole + Kanamycin + Furan-2" is metro, a copper test is not a dose', array() === Dose::due_options( $seed['copper'], array( array( 'label' => 'Metro 25 mg/gal after Copper Power to 2.4', 'kind' => 'dose', 'day' => 2 ), array( 'label' => 'Copper test to 2.4', 'kind' => 'test', 'day' => 2 ) ), $seed ) && array( 25.0 ) === array_column( Dose::due_options( $seed['metro'], array( array( 'label' => 'Metro 25 mg/gal after Copper Power to 2.4', 'kind' => 'dose', 'day' => 2 ) ), $seed ), 'value' ) );
check( 'a typed medication has no options', array() === Dose::due_options( $seed['salt'], array( array( 'label' => 'Salt 1 tsp/gal', 'kind' => 'dose', 'day' => 1 ) ), $seed ) );

echo "7. Today's copper reading\n";
list( $day_start, $day_end ) = Store::site_day_bounds( $today ); // The stub's site zone is America/Chicago: 05:00 UTC to 04:59:59 UTC the next day.
$rows = array(
	array( 'kind' => 'copper', 'value' => 2.1, 'logged_at' => '2026-04-10 13:00:00' ),
	array( 'kind' => 'dose', 'value' => 5.1, 'logged_at' => '2026-04-10 15:30:00', 'note' => 'Metronidazole 5.1 g' ),
	array( 'kind' => 'copper', 'value' => 2.16, 'logged_at' => '2026-04-10 14:14:00' ),
	array( 'kind' => 'copper', 'value' => null, 'logged_at' => '2026-04-10 16:00:00' ),
);
$r = Dose::reading_today( $rows, $today );
check( 'the latest reading of today, with its site time (14:14 UTC is 09:14 in Chicago)', is_array( $r ) && 2.16 === $r['value'] && '2026-04-10 14:14:00' === $r['logged_at'] && '09:14' === $r['at'], json_encode( $r ) );
check( 'a dose, a reading without a value: not readings', 2.1 === Dose::reading_today( array( $rows[0], $rows[1], $rows[3] ), $today )['value'] );
check( 'a reading from yesterday does not count: none', null === Dose::reading_today( array( array( 'kind' => 'copper', 'value' => 2.3, 'logged_at' => '2026-04-09 20:00:00' ) ), $today ) );
check( 'no reading at all: none', null === Dose::reading_today( array(), $today ) && null === Dose::reading_today( $rows, 'not a date' ) );
check( 'the site day, not the UTC day: 03:00 UTC on the 11th is still the evening of the 10th in Chicago', 2.3 === Dose::reading_today( array( array( 'kind' => 'copper', 'value' => 2.3, 'logged_at' => '2026-04-11 03:00:00' ) ), $today )['value'] && null === Dose::reading_today( array( array( 'kind' => 'copper', 'value' => 2.3, 'logged_at' => '2026-04-10 03:00:00' ) ), $today ) && '2026-04-10 05:00:00' === $day_start && '2026-04-11 04:59:59' === $day_end );
check( 'two readings in the same second: the later row', 2.2 === Dose::reading_today( array( array( 'kind' => 'copper', 'value' => 2.1, 'logged_at' => '2026-04-10 13:00:00' ), array( 'kind' => 'copper', 'value' => 2.2, 'logged_at' => '2026-04-10 13:00:00' ) ), $today )['value'] );

echo "8. What \"Now\" and \"Bring it to\" start with\n";
$settings = Settings::get();
$water    = static function ( $reading, array $items ) {
	return array( 'gallons' => 100.0, 'reading' => $reading, 'items' => $items );
};
$day1 = Dose::due_items( array( $stay( 1, 1 ) ), $cm, $today, 'spa' );
$day2 = Dose::due_items( array( $stay( 1, 2 ) ), $cm, $today, 'spa' );
$day5 = Dose::due_items( array( $stay( 1, 5 ) ), $cm, $today, 'spa' );
$d    = Dose::defaults( $seed['copper'], $water( $r, $day2 ), $settings, $seed );
check( 'copper, a reading from today, day 2: Now 2.16 (09:14), Bring it to 2.4 from the schedule', 2.16 === $d['now'] && '09:14' === $d['now_at'] && 2.4 === $d['target'] && 'schedule' === $d['target_from'] && null === $d['rate'], json_encode( $d ) );
$d = Dose::defaults( $seed['copper'], $water( null, $day1 ), $settings, $seed );
check( 'copper, no reading today (yesterday\'s does not count), day 1: Now empty, Bring it to 2.0', null === $d['now'] && '' === $d['now_at'] && 2.0 === $d['target'] && 'schedule' === $d['target_from'] );
$d = Dose::defaults( $seed['copper'], $water( null, $day5 ), $settings, $seed );
check( 'copper on a day with no copper step: Bring it to the copper target of Settings (2.5)', 2.5 === $d['target'] && 'settings' === $d['target_from'] && array() === $d['options'] );
$d = Dose::defaults( $seed['copper'], $water( null, $day5 ), array_merge( $settings, array( 'copper_target' => 2.3 ) ), $seed );
check( '…whatever it is set to', 2.3 === $d['target'] );
$d = Dose::defaults( $seed['copper'], $water( $r, array_merge( $day2, $day1 ) ), $settings, $seed );
check( 'two groups on days 1 and 2 in one water: the lower level (2.0) first, both offered', 2.0 === $d['target'] && array( 2.0, 2.4 ) === array_column( $d['options'], 'value' ) );
$ramp = Medications::normalize_row( array( 'key' => 'ramp', 'label' => 'Test Ramp Liquid', 'unit' => 'ml', 'mode' => 'to_level', 'rate' => 2, 'per' => 5 ) );
$d    = Dose::defaults( $ramp, $water( $r, $day2 ), $settings, array_merge( $seed, array( 'ramp' => $ramp ) ) );
check( 'another medication dosed to a level: Now and Bring it to both empty (the copper log is the copper product\'s)', null === $d['now'] && null === $d['target'] && '' === $d['target_from'] );
$typed_copper = array_merge( $seed['copper'], array( 'mode' => 'typed' ) );
check( 'the copper row switched to typed is no copper product: nothing is filled', array( null, null, null ) === array( Dose::defaults( $typed_copper, $water( $r, $day2 ), $settings, $seed )['now'], Dose::defaults( $typed_copper, $water( $r, $day2 ), $settings, $seed )['target'], Dose::defaults( $typed_copper, $water( $r, $day2 ), $settings, $seed )['rate'] ) );
$d = Dose::defaults( $seed['metro'], $water( $r, $day2 ), $settings, $seed );
check( 'Metronidazole: its own rate (25), no levels', 25.0 === $d['rate'] && '' === $d['rate_from'] && null === $d['now'] && null === $d['target'] );
$d = Dose::defaults( $quine, $water( null, Dose::due_items( array( $stay( 1, 3 ) ), $cq, $today, 'spa' ) ), $settings, $seed );
check( 'Chloroquine on CQ day 3: the step\'s rate (40) instead of its own (10)', 40.0 === $d['rate'] && 'schedule' === $d['rate_from'] );
$d = Dose::defaults( $quine, $water( null, Dose::due_items( array( $stay( 1, 3 ), $stay( 2, 6 ) ), $cq, $today, 'spa' ) ), $settings, $seed );
check( '…two groups on days 3 and 6: the lower rate (12) first, both offered', 12.0 === $d['rate'] && array( 12.0, 40.0 ) === array_column( $d['options'], 'value' ) );

echo "9. The two waters of a tank's page (the in-memory store)\n";
$store   = new Fake_Store();
$now_day = Replay::date_in_zone( time(), wp_timezone() );
$t521    = $store->tank_by_number( 521 );
$t523    = $store->tank_by_number( 523 );
$t621    = $store->tank_by_number( 621 );
$t121    = $store->tank_by_number( 121 );
$w       = Dose::waters( $store, $t521, $now_day, $settings );
check( 'tank 521: the system is Building 500\'s 205 gal, the tank its own 20 gal; no reading, nothing due', 205.0 === $w['system']['gallons'] && 20.0 === $w['tank']['gallons'] && null === $w['system']['reading'] && null === $w['tank']['reading'] && array() === $w['system']['items'] && (int) $t521['building_id'] === $w['building_id'], json_encode( $w ) );
check( 'tank 621: the 100 gal system, a 40 gal tank', 100.0 === Dose::waters( $store, $t621, $now_day, $settings )['system']['gallons'] && 40.0 === Dose::waters( $store, $t621, $now_day, $settings )['tank']['gallons'] );
check( 'tank 121 (no system figure): the sum of the building\'s tanks', 140.0 === Dose::waters( $store, $t121, $now_day, $settings )['system']['gallons'] );
$store->log_tank( (int) $t523['id'], array( 'kind' => 'copper', 'value' => 2.1 ) );
$w = Dose::waters( $store, $t521, $now_day, $settings );
check( 'a reading on 523 reads the system\'s water — not tank 521\'s own', is_array( $w['system']['reading'] ) && 2.1 === $w['system']['reading']['value'] && null === $w['tank']['reading'] );
check( '…and tank 523\'s own, and nothing in the other building', 2.1 === Dose::waters( $store, $t523, $now_day, $settings )['tank']['reading']['value'] && null === Dose::waters( $store, $t621, $now_day, $settings )['system']['reading'] );
$store->log_building( (int) $t521['building_id'], array( 'kind' => 'copper', 'value' => 2.2, 'logged_at' => gmdate( 'Y-m-d H:i:s', time() + 1 ) ) );
$w = Dose::waters( $store, $t521, $now_day, $settings );
check( 'a newer reading on the building reads both the system and every tank of it', 2.2 === $w['system']['reading']['value'] && 2.2 === $w['tank']['reading']['value'] );
$store->log_tank( (int) $t521['id'], array( 'kind' => 'copper', 'value' => 1.9, 'logged_at' => gmdate( 'Y-m-d H:i:s', time() - 86400 * 2 ) ) );
check( 'a reading two days old is not today\'s', 2.2 === Dose::waters( $store, $t521, $now_day, $settings )['tank']['reading']['value'] );
$stay_id = $store->create_stay( array( 'species' => 'Test Tang', 'checkin_date' => Replay::add_days( $now_day, -1 ), 'tank_id' => (int) $t523['id'], 'source' => 'qr' ) );
$store->record_event( $stay_id, array( 'type' => 'arrival', 'qty' => 2, 'to_tank_id' => (int) $t523['id'], 'event_at' => Store::site_noon_utc( Replay::add_days( $now_day, -1 ) ) ), $now_day, $settings );
$w = Dose::waters( $store, $t521, $now_day, $settings );
check( 'a day-2 group in 523: its dose steps are due in the system, not in tank 521 alone', array( 'Copper Power to 2.4', 'Metronidazole' ) === array_column( $w['system']['items'], 'label' ) && array() === $w['tank']['items'] && array( 'Copper Power to 2.4', 'Metronidazole' ) === array_column( Dose::waters( $store, $t523, $now_day, $settings )['tank']['items'], 'label' ), json_encode( $w['system']['items'] ) );
check( '…and not in the other building', array() === Dose::waters( $store, $t621, $now_day, $settings )['system']['items'] );
$sheet = Dose::sheet_data( Medications::active( $seed ), $w, $settings, $seed );
check( 'the sheet\'s data: per water the gallons, today\'s reading and each medication\'s start (a typed one has none to start from)', 205.0 === $sheet['system']['gallons'] && array( 'value' => 2.2, 'at' => $w['system']['reading']['at'] ) === $sheet['system']['reading'] && 2.2 === $sheet['system']['meds']['copper']['now'] && 2.4 === $sheet['system']['meds']['copper']['target'] && 'schedule' === $sheet['system']['meds']['copper']['targetFrom'] && 2.5 === $sheet['tank']['meds']['copper']['target'] && 'settings' === $sheet['tank']['meds']['copper']['targetFrom'] && 25.0 === $sheet['system']['meds']['metro']['rate'] && null === $sheet['system']['meds']['salt']['rate'] && null === $sheet['system']['meds']['salt']['dosed'] && null === $sheet['system']['meds']['copper']['dosed'] && 20.0 === $sheet['tank']['gallons'], json_encode( $sheet['system']['meds']['copper'] ) );

echo "9b. What went in today already\n";
$b500 = (int) $t521['building_id'];
check( 'no dose logged today: none to tell of', array() === $w['system']['doses'] && array() === $w['tank']['doses'] );
// The building's 2.2 reading above was stamped a second ahead; this dose goes in with it, as a Save writes both.
$store->log_building( $b500, array( 'kind' => 'dose', 'value' => 16.6, 'note' => 'Copper Power 16.6 ml · 2.20 → 2.40 ppm, 205 gal', 'logged_at' => gmdate( 'Y-m-d H:i:s', time() + 1 ) ) );
$store->log_dose( 0, (int) $t523['id'], 'metro', 500, 'mg', '25 mg per gallon, 20 gal' );
$store->log_tank( (int) $t523['id'], array( 'kind' => 'dose', 'value' => 9.9, 'note' => 'Copper Power 9.9 ml', 'logged_at' => gmdate( 'Y-m-d H:i:s', time() - 86400 * 2 ) ) );
$w = Dose::waters( $store, $t521, $now_day, $settings );
check( 'a dose for the whole system is told of in the system and in each tank of it: the dose as logged, with its time', 'Copper Power 16.6 ml' === ( $w['system']['doses']['copper']['text'] ?? '' ) && 'Copper Power 16.6 ml' === ( $w['tank']['doses']['copper']['text'] ?? '' ) && Dose::site_clock( $w['system']['doses']['copper']['logged_at'] ) === $w['system']['doses']['copper']['at'] && '' !== $w['system']['doses']['copper']['at'], json_encode( $w['system']['doses'] ) );
check( 'a dose in tank 523 alone: in the system and in 523, not in tank 521', 'Metronidazole 500 mg' === ( $w['system']['doses']['metro']['text'] ?? '' ) && ! isset( $w['tank']['doses']['metro'] ) && isset( Dose::waters( $store, $t523, $now_day, $settings )['tank']['doses']['metro'] ) );
check( '…and none of it in the other building; a dose two days old is not today\'s', array() === Dose::waters( $store, $t621, $now_day, $settings )['system']['doses'] );
$d = Dose::defaults( $seed['metro'], $w['system'], $settings, $seed );
check( 'the sheet starts with it: "Metronidazole 500 mg" and its time; no "read again" for a medication with no level', is_array( $d['dosed'] ) && 'Metronidazole 500 mg' === $d['dosed']['text'] && false === $d['dosed']['after'] && null === Dose::defaults( $seed['metro'], $w['tank'], $settings, $seed )['dosed'], json_encode( $d['dosed'] ) );
$d = Dose::defaults( $seed['copper'], $w['system'], $settings, $seed );
check( 'copper dosed in the same second as the reading (a Save logs both): the reading is older than the dose — so "Now" is left empty, a fresh reading is asked for, and the same sum is not offered again', true === $d['dosed']['after'] && null === $d['now'] && '' === $d['now_at'] && 2.2 === $w['system']['reading']['value'] && 'needs_now' === Dose::plan( $seed['copper'], array( 'gallons' => 205, 'now' => $d['now'], 'target' => $d['target'] ) )['state'] && $w['system']['doses']['copper']['logged_at'] === $d['dosed']['logged_at'], json_encode( $d ) );
check( 'a typed medication is told of too', null === Dose::defaults( $seed['salt'], $w['system'], $settings, $seed )['dosed'] && 'Salt 2 each' === Dose::defaults( $seed['salt'], array( 'reading' => null, 'items' => array(), 'doses' => Dose::doses_today( array( array( 'kind' => 'dose', 'note' => 'Salt 2 each', 'logged_at' => gmdate( 'Y-m-d H:i:s' ) ) ), $now_day, $seed ) ), $settings, $seed )['dosed']['text'] );
$store->log_building( $b500, array( 'kind' => 'copper', 'value' => 2.38, 'logged_at' => gmdate( 'Y-m-d H:i:s', time() + 5 ) ) );
$w = Dose::waters( $store, $t521, $now_day, $settings );
$d = Dose::defaults( $seed['copper'], $w['system'], $settings, $seed );
check( 'a reading taken after the dose: "Now" is that reading, and nothing asks to read again', 2.38 === $d['now'] && false === $d['dosed']['after'] && 'Copper Power 16.6 ml' === $d['dosed']['text'] );
$store->log_building( $b500, array( 'kind' => 'dose', 'value' => 1.4, 'note' => 'Copper Power 1.4 ml · 2.38 → 2.40 ppm, 205 gal', 'logged_at' => gmdate( 'Y-m-d H:i:s', time() + 6 ) ) );
$rows = array(
	array( 'kind' => 'dose', 'note' => 'Copper Power 16.6 ml · 2.20 → 2.40 ppm, 205 gal', 'logged_at' => gmdate( 'Y-m-d H:i:s', time() - 60 ) ),
	array( 'kind' => 'dose', 'note' => 'Copper Power 1.4 ml', 'logged_at' => gmdate( 'Y-m-d H:i:s' ) ),
	array( 'kind' => 'copper', 'value' => 2.4, 'note' => 'Copper Power', 'logged_at' => gmdate( 'Y-m-d H:i:s' ) ),
	array( 'kind' => 'dose', 'note' => 'Something not on the list 3 ml', 'logged_at' => gmdate( 'Y-m-d H:i:s' ) ),
);
$t = Dose::doses_today( $rows, $now_day, $seed );
check( 'several doses of one medication: the latest; a reading or a dose of nothing on the list is no dose of it', array( 'copper' ) === array_keys( $t ) && 'Copper Power 1.4 ml' === $t['copper']['text'], json_encode( $t ) );
check( 'no date, no rows: nothing', array() === Dose::doses_today( $rows, '', $seed ) && array() === Dose::doses_today( array(), $now_day, $seed ) );
$sheet = Dose::sheet_data( Medications::active( $seed ), Dose::waters( $store, $t521, $now_day, $settings ), $settings, $seed );
check( 'the sheet\'s data carries it per water and medication — with its time, which a Save posts back as what the sheet knew', 'Copper Power 1.4 ml' === $sheet['system']['meds']['copper']['dosed']['text'] && true === $sheet['system']['meds']['copper']['dosed']['after'] && null === $sheet['system']['meds']['copper']['now'] && 1 === preg_match( '/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/', $sheet['system']['meds']['copper']['dosed']['logged_at'] ) && 'Metronidazole 500 mg' === $sheet['system']['meds']['metro']['dosed']['text'] && null === $sheet['tank']['meds']['metro']['dosed'] && null === $sheet['system']['meds']['salt']['dosed'], json_encode( $sheet['system']['meds'] ) );

echo "10. What a Save's post turns into\n";
$a = Dose::args_from_request( $seed['copper'], array( 'calc' => '1', 'now' => '2.16', 'target' => '2.4', 'rate' => '99' ), 100 );
check( 'to a level: now and target; the gallons are the store\'s, never the post\'s', true === $a['calc'] && 2.16 === $a['now'] && 2.4 === $a['target'] && 100.0 === $a['gallons'] && ! isset( $a['rate'] ) && false === $a['topup'] );
check( '…and the note is the working', '2.16 → 2.40 ppm, 100 gal' === Dose::working_note( $seed['copper'], $a ) );
$a = Dose::args_from_request( $seed['metro'], array( 'calc' => '1', 'rate' => '25' ), 100 );
check( 'per gallon: "25 mg per gallon, 100 gal"', '25 mg per gallon, 100 gal' === Dose::working_note( $seed['metro'], $a ) );
$a = Dose::args_from_request( $quine, array( 'calc' => '1', 'rate' => '40', 'dose_label' => 'loading' ), 100 );
check( 'a named dose is named: "Loading: 40 mg per gallon, 100 gal"', 'Loading' === $a['rate_label'] && 'Loading: 40 mg per gallon, 100 gal' === Dose::working_note( $quine, $a ) );
$a = Dose::args_from_request( $quine, array( 'calc' => '1', 'rate' => '40', 'dose_label' => '<b>Anything</b> typed' ), 100 );
check( 'a label that is not one of its named doses (or not at that rate) is dropped, the rate kept', ! isset( $a['rate_label'] ) && '40 mg per gallon, 100 gal' === Dose::working_note( $quine, $a ) && ! isset( Dose::args_from_request( $quine, array( 'calc' => '1', 'rate' => '41', 'dose_label' => 'Loading' ), 100 )['rate_label'] ) );
$a = Dose::args_from_request( $seed['metro'], array( 'calc' => '1', 'rate' => 'abc' ), 100 );
check( 'a rate that is no number: the medication\'s own', ! isset( $a['rate'] ) && '25 mg per gallon, 100 gal' === Dose::working_note( $seed['metro'], $a ) );
$a = Dose::args_from_request( $seed['metro'], array( 'calc' => '1', 'rate' => '25', 'topup' => '1', 'changed' => '25', 'changed_unit' => 'pct' ), 100 );
check( 'a top-up: "25 mg per gallon, 25 gal · top-up after a 25 % water change"', '25 mg per gallon, 25 gal · top-up after a 25 % water change' === Dose::working_note( $seed['metro'], $a ) );
check( 'no calc flag (an amount typed with no sum): no note — a dose as it always was', '' === Dose::working_note( $seed['metro'], Dose::args_from_request( $seed['metro'], array( 'rate' => '25', 'now' => '2' ), 100 ) ) && '' === Dose::working_note( $seed['copper'], Dose::args_from_request( $seed['copper'], array( 'now' => '2.16', 'target' => '2.4' ), 100 ) ) && '' === Dose::working_note( $seed['metro'], Dose::args_from_request( $seed['metro'], array( 'calc' => '0' ), 100 ) ) );
check( '…but a top-up is still said: "top-up after a 30 gal water change"', 'top-up after a 30 gal water change' === Dose::working_note( $seed['salt'], Dose::args_from_request( $seed['salt'], array( 'topup' => '1', 'changed' => '30', 'changed_unit' => 'gal' ), 100 ) ) && 'top-up after a water change' === Dose::working_note( $seed['metro'], Dose::args_from_request( $seed['metro'], array( 'topup' => '1' ), 100 ) ) );
check( 'unknown gallons: the readings alone for a level, nothing for a rate', '2.16 → 2.40 ppm' === Dose::working_note( $seed['copper'], Dose::args_from_request( $seed['copper'], array( 'calc' => '1', 'now' => '2.16', 'target' => '2.4' ), 0 ) ) && '' === Dose::working_note( $seed['metro'], Dose::args_from_request( $seed['metro'], array( 'calc' => '1' ), 0 ) ) );

echo "11. The reading a copper dose brings along\n";
$store2 = new Fake_Store();
$tank   = $store2->tank_by_number( 621 );
$base   = array( 'actor_id' => 8, 'source' => 'qr' );
$before = count( $store2->logs );
check( 'no reading today: logged, on the building for the whole system', 'logged' === Dose::save_reading( $store2, $tank, true, 2.16, null, $base ) && $before + 1 === count( $store2->logs ) && 'copper' === end( $store2->logs )['kind'] && 2.16 === end( $store2->logs )['value'] && 0 === end( $store2->logs )['tank_id'] && (int) $tank['building_id'] === end( $store2->logs )['building_id'] && 8 === end( $store2->logs )['actor_id'] && 'qr' === end( $store2->logs )['source'] );
$latest = Dose::waters( $store2, $tank, $now_day, $settings )['system']['reading'];
check( 'the same value is today\'s latest already: not logged again', 'already' === Dose::save_reading( $store2, $tank, true, 2.16, $latest, $base ) && 'already' === Dose::save_reading( $store2, $tank, true, '2.1600', $latest, $base ) && $before + 1 === count( $store2->logs ) );
check( 'another value: logged', 'logged' === Dose::save_reading( $store2, $tank, true, 2.2, $latest, $base ) && $before + 2 === count( $store2->logs ) );
check( 'this tank only: logged on the tank', 'logged' === Dose::save_reading( $store2, $tank, false, 2.3, null, $base ) && (int) $tank['id'] === end( $store2->logs )['tank_id'] && null === end( $store2->logs )['building_id'] );
check( 'a store that cannot write: failed, never a throw', 'failed' === Dose::save_reading( $store2, array( 'id' => 9999, 'building_id' => 9999 ), false, 2.3, null, $base ) && 'failed' === Dose::save_reading( $store2, array( 'id' => 9999, 'building' => array( 'id' => 9999 ) ), true, 2.3, null, $base ) );
check( 'place(): "Building 600, whole system" / "Tank 621"', 'Building 600, whole system' === Dose::place( $tank, true ) && 'Tank 621' === Dose::place( $tank, false ) );

echo "12. Afterwards: the Board's \"last dose\"\n";
check( 'a dose with its working: the working in brackets', 'Copper Power 16.6 ml (2.16 → 2.40 ppm, 100 gal)' === Dose::last_dose_text( array( 'label' => 'Copper Power', 'note' => 'Copper Power 16.6 ml · 2.16 → 2.40 ppm, 100 gal' ) ) );
check( 'a top-up keeps both parts', 'Metronidazole 625 mg (25 mg per gallon, 25 gal · top-up after a 25 % water change)' === Dose::last_dose_text( array( 'label' => 'Metronidazole', 'note' => 'Metronidazole 625 mg · 25 mg per gallon, 25 gal · top-up after a 25 % water change' ) ) );
check( 'an older dose without one: as it was logged', 'Metronidazole 5.1 g' === Dose::last_dose_text( array( 'label' => 'Metronidazole', 'note' => 'Metronidazole 5.1 g' ) ) );
check( 'an imported one: its text, the import marker never', 'Praziquantel 2.05 — Cu 2.3 before' === Dose::last_dose_text( array( 'label' => 'Praziquantel', 'note' => 'Praziquantel 2.05 — Cu 2.3 before · import:2026-9#4' ) ) );
check( 'no note at all: the medication', 'Formalin' === Dose::last_dose_text( array( 'label' => 'Formalin', 'note' => '' ) ) && 'Formalin' === Dose::last_dose_text( array( 'label' => 'Formalin' ) ) );
check( 'a very long note is cut', 120 === mb_strlen( Dose::last_dose_text( array( 'label' => 'X', 'note' => 'Metronidazole 5 g · ' . str_repeat( 'long ', 60 ) ) ) ) );
$board = Dose::board_doses( array( 5 => array( 'copper' => array( 'label' => 'Copper Power', 'note' => 'Copper Power 16.6 ml · 2.16 → 2.40 ppm, 100 gal', 'logged_at' => '2026-04-10 14:20:00' ), 'metro' => array( 'label' => 'Metronidazole', 'note' => 'Metronidazole 2.5 g', 'logged_at' => '2026-04-09 14:20:00' ) ) ) );
check( 'board_doses(): the label becomes what the Board prints; the time and the note stay', 'Copper Power 16.6 ml (2.16 → 2.40 ppm, 100 gal)' === $board[5]['copper']['label'] && 'Metronidazole 2.5 g' === $board[5]['metro']['label'] && '2026-04-10 14:20:00' === $board[5]['copper']['logged_at'] && 'Copper Power 16.6 ml · 2.16 → 2.40 ppm, 100 gal' === $board[5]['copper']['note'] && array() === Dose::board_doses( array() ) );

fh_finish();
