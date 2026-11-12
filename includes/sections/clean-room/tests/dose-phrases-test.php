<?php
/**
 * Dose phrases: what the parser hears, the preview the Front Desk makes
 * of it (Front_Desk::dose_preview() against the in-memory Fake_Store) and
 * that Confirm is the Dose sheet's Save.
 *
 * "copper 2.16 to 2.4", "bring copper from 2.16 to 2.4", "raise copper to
 * 2.4" (today's reading is Now, asked when there is none), "dose metro" /
 * "metro for the system" / "prazi this tank", a named dose, a schedule
 * step's rate, "the 100 gallon system", a tank that is spoken, a dose
 * spoken with its amount — each previewed with its result line and
 * working, then confirmed into the same Save —; and every phrase that is
 * not a dose phrase read as it always was: "521 copper 2.16" and its
 * relatives stay copper readings (parser-test.php, unchanged, holds the
 * rest).
 *
 * Run from the plugin root:  php includes/sections/clean-room/tests/dose-phrases-test.php
 */

require_once __DIR__ . '/wp-stubs.php';
require_once __DIR__ . '/dose-helpers.php';

use FisHotel\Misc\Sections\Clean_Room\Dose;
use FisHotel\Misc\Sections\Clean_Room\Front_Desk;
use FisHotel\Misc\Sections\Clean_Room\Medications;
use FisHotel\Misc\Sections\Clean_Room\Parser;
use FisHotel\Misc\Sections\Clean_Room\Replay;
use FisHotel\Misc\Sections\Clean_Room\Settings;
use FisHotel\Misc\Sections\Clean_Room\Tests\Fake_Store;

$today    = Replay::date_in_zone( time(), wp_timezone() );
$settings = Settings::get();
$store    = new Fake_Store();
$list     = Medications::apply_op( Medications::seed(), 'add', array( 'label' => 'Test Kanamycin', 'short' => 'Kana', 'unit' => 'mg', 'mode' => 'per_gallon', 'rate' => '30' ) )['list'];
$list     = Medications::apply_op( $list, 'retire', array( 'key' => 'formalin' ) )['list'];

/**
 * A room whose Building 100 has no known volume.
 */
$dry  = new Fake_Store();
$d121 = $dry->tank_by_number( 121 );
foreach ( $dry->tanks as $id => $row ) {
	if ( (int) $row['building_id'] === (int) $d121['building_id'] ) {
		$dry->tanks[ $id ]['gallons'] = 0;
	}
}

echo "1. The phrases (pure parser)\n";
$tanks   = fh_seed_tanks();
$systems = Front_Desk::parser_systems( $store );
check( 'the systems the parser is handed: each building\'s gallons and its first tank', array( array( 'building' => 100, 'gallons' => 140, 'tank' => 111 ), array( 'building' => 200, 'gallons' => 120, 'tank' => 211 ), array( 'building' => 300, 'gallons' => 120, 'tank' => 311 ), array( 'building' => 500, 'gallons' => 205, 'tank' => 511 ), array( 'building' => 600, 'gallons' => 100, 'tank' => 611 ) ) === $systems, json_encode( $systems ) );
$ctx = array( 'tank' => 621, 'tanks' => $tanks, 'species' => array(), 'occupants' => array( array( 'stay_id' => 7, 'species' => 'Test Goby', 'product_id' => null, 'qty_live' => 2 ) ), 'systems' => $systems );
function fh_p( $text, array $over = array() ) {
	global $ctx;
	return Parser::parse( $text, array_merge( $ctx, $over ) );
}
function fh_dose( $text, array $over = array() ) {
	$r = fh_p( $text, $over );
	return 'dose' === $r['intent'] ? $r['dose'] : array( 'intent' => $r['intent'] );
}
$d = fh_dose( 'copper 2.16 to 2.4' );
check( '"copper 2.16 to 2.4": a dose of the copper product, now 2.16, target 2.4, the water not said, no amount', array( 'medication' => 'copper', 'label' => 'Copper Power', 'mode' => 'to_level', 'level_unit' => 'ppm', 'scope' => '', 'now' => 2.16, 'target' => 2.4, 'amount' => null, 'unit' => '', 'dose_label' => '' ) === $d, json_encode( $d ) );
$r = fh_p( 'copper 2.16 to 2.4' );
check( '…on the page\'s tank, nothing more needed, in plain words', 621 === $r['tank'] && ! $r['tank_spoken'] && array() === $r['needs'] && 'Dose: Copper Power, 2.16 → 2.40 ppm · Tank 621' === $r['summary'] && in_array( 'dose', Parser::INTENTS, true ), $r['summary'] );
$same = true;
foreach ( array( 'bring copper from 2.16 to 2.4', 'bring my copper up from 2.16 to 2.4', 'raise the copper from 2.16 to 2.40', 'cu 2.16 to 2.4', 'Copper Power 2.16 ppm to 2.4 ppm', 'copper two point one six to two point four', 'dose copper 2.16 to 2.4' ) as $phrase ) {
	$d = fh_dose( $phrase );
	if ( 'copper' !== ( $d['medication'] ?? '' ) || 2.16 !== ( $d['now'] ?? null ) || 2.4 !== ( $d['target'] ?? null ) ) {
		$same = false;
		echo "       not understood: $phrase → " . json_encode( $d ) . "\n";
	}
}
check( '"bring copper from 2.16 to 2.4" and its relatives say the same', $same );
$d = fh_dose( 'raise copper to 2.4' );
check( '"raise copper to 2.4": the target alone — Now is the room\'s to fill', array_key_exists( 'now', $d ) && null === $d['now'] && 2.4 === $d['target'] && 'Dose: Copper Power, to 2.40 ppm · Tank 621' === fh_p( 'raise copper to 2.4' )['summary'] );
check( '…"bump / top up / bring … to", "dose copper to 2.4"', 2.4 === fh_dose( 'bump copper to 2.4' )['target'] && 2.4 === fh_dose( 'top up the copper to 2.4' )['target'] && 2.5 === fh_dose( 'bring copper up to two point five' )['target'] && 2.4 === fh_dose( 'dose copper to 2.4' )['target'] );
$d = fh_dose( 'dose copper' );
check( '"dose copper": both levels left to the room', 'copper' === ( $d['medication'] ?? '' ) && null === $d['now'] && null === $d['target'] && 'Dose: Copper Power · Tank 621' === fh_p( 'dose copper' )['summary'] );
check( '"raise copper from 2.16": Now alone', array( 2.16, null ) === array( fh_dose( 'raise copper from 2.16' )['now'], fh_dose( 'raise copper from 2.16' )['target'] ) );
$d = fh_dose( 'dose metro' );
check( '"dose metro": Metronidazole, no water said, no amount', array( 'metro', 'per_gallon', '', null, '' ) === array( $d['medication'], $d['mode'], $d['scope'], $d['amount'], $d['dose_label'] ) && 'Dose: Metronidazole · Tank 621' === fh_p( 'dose metro' )['summary'] );
check( '"metro for the system": the whole system', 'system' === fh_dose( 'metro for the system' )['scope'] && 'Dose: Metronidazole · whole system · Tank 621' === fh_p( 'metro for the system' )['summary'] && 'system' === fh_dose( 'dosed the whole system with metronidazole' )['scope'] && 'system' === fh_dose( 'metro for the building' )['scope'] );
check( '"prazi this tank": this tank only', array( 'prazi', 'tank' ) === array( fh_dose( 'prazi this tank' )['medication'], fh_dose( 'prazi this tank' )['scope'] ) && 'Dose: Praziquantel · this tank only · Tank 621' === fh_p( 'prazi this tank' )['summary'] && 'tank' === fh_dose( 'praziquantel tank only' )['scope'] && 'tank' === fh_dose( 'dose prazi just this tank' )['scope'] );
check( 'a name alone is a dose of it ("metro", "formalin") — but never "copper", which stays a reading waiting for its value', 'metro' === ( fh_dose( 'metro' )['medication'] ?? '' ) && 'formalin' === ( fh_dose( 'formalin' )['medication'] ?? '' ) && array( 'intent' => 'copper' ) === fh_dose( 'copper' ) && in_array( 'value', fh_p( 'copper' )['needs'], true ) );
$d = fh_dose( 'cq loading' );
check( 'a named dose is heard: "cq loading", "chloroquine top up"', array( 'chloroquine', 'Loading' ) === array( $d['medication'], $d['dose_label'] ) && 'Top-up' === fh_dose( 'chloroquine top up' )['dose_label'] && 'Last' === fh_dose( 'dose the last chloroquine' )['dose_label'] && 'Dose: Chloroquine (Loading) · Tank 621' === fh_p( 'cq loading' )['summary'] );
$r = fh_p( 'prazi in 522' );
check( 'a tank that is spoken is the tank: "prazi in 522", "521 copper 2.16 to 2.4"', 'dose' === $r['intent'] && 522 === $r['tank'] && $r['tank_spoken'] && $r['tank_known'] && 521 === fh_p( '521 copper 2.16 to 2.4' )['tank'] && 2.16 === fh_p( '521 copper 2.16 to 2.4' )['dose']['now'] && 521 === fh_p( 'copper 2.16 to 2.4 tank five two one' )['tank'] );
$r = fh_p( 'bring copper from 2.16 to 2.4 in the 100 gallon system', array( 'tank' => 521 ) );
check( '"… in the 100 gallon system" from a tank of the 205 gallon one: Building 600\'s water, through its first tank', 'dose' === $r['intent'] && 611 === $r['tank'] && $r['tank_spoken'] && 'system' === $r['dose']['scope'] && 2.4 === $r['dose']['target'] && array() === $r['needs'], json_encode( array( $r['tank'], $r['needs'], $r['dose'] ) ) );
$r = fh_p( 'metro for the 100 gallon system' );
check( '…said on a tank of that system: the page\'s own tank stays', 621 === $r['tank'] && ! $r['tank_spoken'] && 'system' === $r['dose']['scope'] && array() === $r['needs'] );
check( 'a size no system has, or two have (200 and 300 hold 120 each): which tank?', array( 'tank' ) === fh_p( 'metro for the 55 gallon system' )['needs'] && array( 'tank' ) === fh_p( 'metro for the 120 gallon system' )['needs'] && array() === fh_p( 'metro for the 120 gallon system', array( 'tank' => 211 ) )['needs'] );
check( 'a tank that is not in the layout: the phrase is still a dose, and asks which tank', array( 'tank' ) === fh_p( 'dose metro in 999' )['needs'] && 'dose' === fh_p( 'dose metro in 999' )['intent'] && false === fh_p( 'dose metro in 999' )['tank_known'] && 'Dose: Metronidazole · Tank 999' === fh_p( 'dose metro in 999' )['summary'] && array( 'tank' ) === fh_p( 'metro tank 999' )['needs'] );
$d = fh_dose( 'metro 5 g' );
check( 'a dose spoken with its amount is that amount, not a sum: "metro 5 g", "dosed 5 grams of metro", "copper power 55 ml", "formalin 20.5 mils", "metro 500mg"', array( 5.0, 'g' ) === array( $d['amount'], $d['unit'] ) && array( 5.0, 'g' ) === array( fh_dose( 'dosed 5 grams of metro' )['amount'], fh_dose( 'dosed 5 grams of metro' )['unit'] ) && array( 55.0, 'ml', null ) === array( fh_dose( 'copper power 55 ml' )['amount'], fh_dose( 'copper power 55 ml' )['unit'], fh_dose( 'copper power 55 ml' )['now'] ) && array( 20.5, 'ml' ) === array( fh_dose( 'formalin 20.5 mils' )['amount'], fh_dose( 'formalin 20.5 mils' )['unit'] ) && array( 500.0, 'mg' ) === array( fh_dose( 'metro 500mg' )['amount'], fh_dose( 'metro 500mg' )['unit'] ) && 'Dose: Metronidazole 5 g · whole system · Tank 621' === fh_p( 'metro 5 g whole system' )['summary'] );
check( '…a number right before a unit is an amount even when it looks like a tank ("metro 212 mg")', array( 212.0, 'mg', 621 ) === array( fh_dose( 'metro 212 mg' )['amount'], fh_dose( 'metro 212 mg' )['unit'], fh_p( 'metro 212 mg' )['tank'] ) );
$unchanged = array(
	'521 copper 2.16'       => array( 'copper', 2.16, 521 ),
	'copper 2.4'            => array( 'copper', 2.4, 621 ),
	'copper two point four' => array( 'copper', 2.4, 621 ),
	'cu 2'                  => array( 'copper', 2.0, 621 ),
	'copper 2.25 in 513'    => array( 'copper', 2.25, 513 ),
	'copper to 2.4'         => array( 'copper', 2.4, 621 ),
	'copper 2.16 2.4'       => array( 'copper', 2.16, 621 ),
);
$bad = array();
foreach ( $unchanged as $phrase => $want ) {
	$r = fh_p( $phrase );
	if ( array( $r['intent'], $r['value'], $r['tank'] ) !== $want || null !== $r['dose'] ) {
		$bad[] = $phrase . ' → ' . json_encode( array( $r['intent'], $r['value'], $r['tank'] ) );
	}
}
check( 'a reading is still a reading: "521 copper 2.16", "copper 2.4", "copper two point four", "cu 2", "copper 2.25 in 513", and "copper to 2.4" without a raise verb', array() === $bad, implode( ' | ', $bad ) );
$other = array(
	'lost one goby'                   => 'loss',
	'note dosed metro 5 g'            => 'note',
	'four copper sweepers in 521'     => 'copper',
	'move the goby to 522'            => 'move',
	'metro goby in 521'               => 'arrival',
	'metro and prazi'                 => 'arrival',
	'metro 5'                         => 'arrival',
	'count two gobies'                => 'count',
	'shipped a goby'                  => 'sold',
	'salt water change done'          => 'clear',
);
$bad = array();
foreach ( $other as $phrase => $intent ) {
	if ( $intent !== fh_p( $phrase )['intent'] ) {
		$bad[] = $phrase . ' → ' . fh_p( $phrase )['intent'];
	}
}
check( 'a phrase with anything but dose words in it is read as it always was (a fish, a note, two medications, a number without a unit)', array() === $bad, implode( ' | ', $bad ) );
$phrases = json_decode( (string) file_get_contents( __DIR__ . '/fixtures/dose-phrases.json' ), true );
$bad     = array();
foreach ( $phrases['sentences'] as $pair ) {
	if ( Parser::summary( $pair[0] ) !== $pair[1] ) {
		$bad[] = Parser::summary( $pair[0] ) . ' ≠ ' . $pair[1];
	}
}
check( 'a dose phrase in plain words — the sentences the page builds too (fixtures/dose-phrases.json, ' . count( $phrases['sentences'] ) . ' of them)', 14 === count( $phrases['sentences'] ) && array() === $bad, implode( ' | ', $bad ) );
check( 'without a medication list the seed is used; with one, only its medications are heard', 'dose' === Parser::parse( 'dose metro', array( 'tank' => 621, 'tanks' => $tanks ) )['intent'] && 'dose' !== Parser::parse( 'dose metro', array( 'tank' => 621, 'tanks' => $tanks, 'medications' => array() ) )['intent'] && 'test-kanamycin' === Parser::parse( 'dose kana', array( 'tank' => 621, 'tanks' => $tanks, 'medications' => array_values( Medications::active( $list ) ) ) )['dose']['medication'] && 'dose' !== Parser::parse( 'dose formalin', array( 'tank' => 621, 'tanks' => $tanks, 'medications' => array_values( Medications::active( $list ) ) ) )['intent'] );

echo "2. The preview: the result line and the working, then Confirm does the same Save\n";
/**
 * What the page's Confirm posts for a dose preview (front-desk.js confirmPreview()).
 */
function fh_confirm_fields( array $preview ) {
	$d      = $preview['dose'];
	$plan   = $d['plan'];
	$fields = array( 'medication' => $d['medication'], 'amount' => $plan['amount'], 'unit' => $plan['unit'], 'whole' => $d['whole'] ? 1 : 0 );
	if ( 'ok' === $plan['state'] ) {
		$fields['calc'] = 1;
		if ( 'to_level' === $d['mode'] ) {
			$fields['now']    = $d['now'];
			$fields['target'] = $d['target'];
		} else {
			$fields['rate'] = $d['rate'];
			if ( '' !== $d['rate_label'] ) {
				$fields['dose_label'] = $d['rate_label'];
			}
		}
	}
	return $fields;
}
function fh_preview( Fake_Store $store, $text, $page = 621, $forced = 0 ) {
	global $ctx, $settings, $today;
	$parsed = Parser::parse( $text, array_merge( $ctx, array( 'tank' => $page, 'systems' => Front_Desk::parser_systems( $store ) ) ) );
	return Front_Desk::dose_preview( $parsed, $store, $forced, $today, $settings );
}
$room = new Fake_Store();
$r621 = $room->tank_by_number( 621 );
$rb   = (int) $r621['building_id'];
$p    = fh_preview( $room, 'copper 2.16 to 2.4' );
$d    = $p['dose'];
check( '"copper 2.16 to 2.4" on 621: "Add 16.6 ml Copper Power", the working, the whole 100 gal system — ready', 'ok' === $d['plan']['state'] && 16.6 === $d['plan']['amount'] && 'ml' === $d['plan']['unit'] && 'Add 16.6 ml Copper Power' === $d['plan']['line'] && '100 gal × 0.24 ppm × 0.692' === $d['plan']['working'] && true === $d['whole'] && 100.0 === $d['gallons'] && 'Building 600, whole system' === $d['place'] && array() === $p['needs'] && 'Add 16.6 ml Copper Power · Building 600, whole system' === $p['summary'], json_encode( $d ) );
$n = count( $room->logs );
$r = fh_act( $room, 621, fh_confirm_fields( $p ), 8, 'voice' );
check( 'Confirm: the same Save — the reading and the dose with its working, by voice', 'Copper Power 16.6 ml · Building 600, whole system · 2.16 → 2.40 ppm, 100 gal · copper reading 2.16 logged' === ( $r['message'] ?? '' ) && array( 'copper|0|' . $rb . '|2.16|', 'dose|0|' . $rb . '|16.6|Copper Power 16.6 ml · 2.16 → 2.40 ppm, 100 gal' ) === fh_new_logs( $room, $n ) && 'voice' === end( $room->logs )['source'], json_encode( $r ) );
$p = fh_preview( $room, 'raise copper to 2.4' );
check( '"raise copper to 2.4": Now is today\'s latest reading (2.16, with its time) — 16.6 ml again', 2.16 === $p['dose']['now'] && 1 === preg_match( '/^\d\d:\d\d$/', $p['dose']['now_at'] ) && 16.6 === $p['dose']['plan']['amount'] && array() === $p['needs'] );
$n = count( $room->logs );
fh_act( $room, 621, fh_confirm_fields( $p ), 8, 'voice' );
check( '…confirmed: only the dose — the reading is logged already', 1 === count( fh_new_logs( $room, $n ) ) && 'dose' === end( $room->logs )['kind'] );
$empty = new Fake_Store();
$p     = fh_preview( $empty, 'raise copper to 2.4' );
check( '…with no reading today it asks for Now, and is not ready', array( 'now' ) === $p['needs'] && 'needs_now' === $p['dose']['plan']['state'] && null === $p['dose']['now'] && 2.4 === $p['dose']['target'] && 'Type the reading now to get the amount.' === $p['dose']['plan']['line'] );
$p = fh_preview( $empty, 'dose copper' );
check( '"dose copper" on a tank with nothing due: Bring it to the copper target (2.5); Now asked', 2.5 === $p['dose']['target'] && array( 'now' ) === $p['needs'] );
fh_checkin( $empty, 621, 0, 'Test Goby' );
$p = fh_preview( $empty, 'dose copper' );
check( '…a group on day 1 in the system: Bring it to 2.0 ("Copper Power to 2.0")', 2.0 === $p['dose']['target'] );
$day2 = new Fake_Store();
fh_checkin( $day2, 611, 1, 'Test Goby' );
Front_Desk::perform( 'copper', array( 'store' => $day2, 'settings' => $settings, 'today' => $today, 'tank' => $day2->tank_by_number( 611 ), 'base' => array( 'actor_id' => 8, 'source' => 'qr' ) ), array( 'value' => '2.16' ) );
$p = fh_preview( $day2, 'dose copper' );
check( '…a group on day 2 (in 611, the same water) and 2.16 read there today: 2.16 → 2.4, 16.6 ml, ready', 2.16 === $p['dose']['now'] && 2.4 === $p['dose']['target'] && 16.6 === $p['dose']['plan']['amount'] && array() === $p['needs'] );
$p = fh_preview( $day2, 'dose copper this tank' );
check( '…"this tank" (621 alone): its own 40 gal, nothing due in it, no reading of its own', false === $p['dose']['whole'] && 40.0 === $p['dose']['gallons'] && 'Tank 621' === $p['dose']['place'] && 2.5 === $p['dose']['target'] && null === $p['dose']['now'] && array( 'now' ) === $p['needs'] );
$p = fh_preview( $room, 'copper 2.5 to 2.4' );
check( 'at or above the target: "Nothing to add" — not ready to confirm', 'nothing' === $p['dose']['plan']['state'] && 'Nothing to add' === $p['dose']['plan']['line'] && null === $p['dose']['plan']['amount'] && array() === $p['needs'] );
$p = fh_preview( $room, 'dose metro' );
check( '"dose metro": "Add 2.5 g Metronidazole", "100 gal × 25 mg", the whole system', 'Add 2.5 g Metronidazole' === $p['dose']['plan']['line'] && '100 gal × 25 mg' === $p['dose']['plan']['working'] && true === $p['dose']['whole'] && 25.0 === $p['dose']['rate'] && 'Add 2.5 g Metronidazole · Building 600, whole system' === $p['summary'] );
$n = count( $room->logs );
$r = fh_act( $room, 621, fh_confirm_fields( $p ), 8, 'voice' );
check( '…confirmed: "Metronidazole 2.5 g · 25 mg per gallon, 100 gal", once', array( 'dose|0|' . $rb . '|2.5|Metronidazole 2.5 g · 25 mg per gallon, 100 gal' ) === fh_new_logs( $room, $n ) );
$p = fh_preview( $room, 'prazi this tank' );
check( '"prazi this tank": the tank\'s 40 gal — 380 mg', false === $p['dose']['whole'] && 'Add 380 mg Praziquantel' === $p['dose']['plan']['line'] && '40 gal × 9.5 mg' === $p['dose']['plan']['working'] && 'Tank 621' === $p['dose']['place'] );
$n = count( $room->logs );
fh_act( $room, 621, fh_confirm_fields( $p ), 8, 'voice' );
check( '…confirmed: on the tank', array( 'dose|' . $r621['id'] . '|-|380|Praziquantel 380 mg · 9.5 mg per gallon, 40 gal' ) === fh_new_logs( $room, $n ) );
$p = fh_preview( $room, 'metro for the system', 521 );
check( '"metro for the system" on 521: the 205 gal system — 5.1 g', 'Add 5.1 g Metronidazole' === $p['dose']['plan']['line'] && 'Building 500, whole system' === $p['dose']['place'] && 205.0 === $p['dose']['gallons'] );
$p = fh_preview( $room, 'cq loading' );
check( 'a named dose: Loading, 40 mg per gallon — 4 g', 40.0 === $p['dose']['rate'] && 'Loading' === $p['dose']['rate_label'] && 'Add 4 g Chloroquine' === $p['dose']['plan']['line'] && 'Loading: 40 mg per gallon, 100 gal' === $p['dose']['plan']['note'] );
$n = count( $room->logs );
fh_act( $room, 621, fh_confirm_fields( $p ), 8, 'voice' );
check( '…confirmed with its name', 'Chloroquine 4 g · Loading: 40 mg per gallon, 100 gal' === end( $room->logs )['note'] );
$cq = new Fake_Store();
foreach ( $cq->buildings as $id => $row ) {
	$cq->buildings[ $id ]['schedule'] = 'CQ';
}
fh_checkin( $cq, 621, 2, 'Test Goby' );
$p = fh_preview( $cq, 'dose chloroquine' );
check( 'a building on the CQ schedule, a group on day 3: the step\'s "Chloroquine 40 mg/gal" picks the rate', 40.0 === $p['dose']['rate'] && '' === $p['dose']['rate_label'] && 'Add 4 g Chloroquine' === $p['dose']['plan']['line'] && '100 gal × 40 mg' === $p['dose']['plan']['working'] );
check( '…and without a due step, its own 10 mg per gallon', 10.0 === fh_preview( $room, 'dose chloroquine' )['dose']['rate'] && 'Add 1 g Chloroquine' === fh_preview( $room, 'dose chloroquine' )['dose']['plan']['line'] );
$p = fh_preview( $room, 'bring copper from 2.16 to 2.4 in the 100 gallon system', 521 );
check( 'said on 521, "… in the 100 gallon system": Building 600\'s water — 16.6 ml', 611 === $p['tank'] && 'Building 600, whole system' === $p['dose']['place'] && 16.6 === $p['dose']['plan']['amount'] && '100 gal × 0.24 ppm × 0.692' === $p['dose']['plan']['working'] );
$p = fh_preview( $room, 'metro 5 g' );
check( 'a dose spoken with its amount: "Metronidazole 5 g", no sum, no working', 'spoken' === $p['dose']['plan']['state'] && 5.0 === $p['dose']['plan']['amount'] && 'g' === $p['dose']['plan']['unit'] && 'Metronidazole 5 g' === $p['dose']['plan']['line'] && '' === $p['dose']['plan']['working'] && 'Metronidazole 5 g · Building 600, whole system' === $p['summary'] );
$n = count( $room->logs );
$r = fh_act( $room, 621, fh_confirm_fields( $p ), 8, 'voice' );
check( '…confirmed: the plain dose', array( 'dose|0|' . $rb . '|5|Metronidazole 5 g' ) === fh_new_logs( $room, $n ) && 'Metronidazole 5 g · Building 600, whole system' === $r['message'] );
$p = fh_preview( $room, 'copper power 55 ml this tank' );
check( '"copper power 55 ml this tank": a dose of 55 ml on the tank — not a reading of 55', 'spoken' === $p['dose']['plan']['state'] && 55.0 === $p['dose']['plan']['amount'] && false === $p['dose']['whole'] && null === $p['dose']['now'] );
$p = fh_preview( $room, 'salt' );
check( 'a typed medication: no sum to confirm', 'typed' === $p['dose']['plan']['state'] && 'No sum for Salt — type the amount.' === $p['dose']['plan']['line'] );
$p = fh_preview( $room, 'dose metro in 999' );
check( 'an unknown tank: the preview waits for one', array( 'tank' ) === $p['needs'] && ! isset( $p['dose']['plan'] ) );
$p = fh_preview( $room, 'dose metro in 999', 621, 521 );
check( '…a tank picked on the page stands in: Building 500\'s system', array() === $p['needs'] && 521 === $p['tank'] && 'Building 500, whole system' === $p['dose']['place'] && 'Add 5.1 g Metronidazole' === $p['dose']['plan']['line'] );
$off = new Fake_Store();
foreach ( $off->tanks as $id => $row ) {
	if ( 621 === (int) $row['number'] ) {
		$off->tanks[ $id ]['status'] = 'offline';
	}
}
check( 'an offline tank is no water to dose', ! isset( fh_preview( $off, 'dose metro' )['dose']['plan'] ) );
$p = fh_preview( $dry, 'dose metro', 121 );
check( 'a water of unknown size: the preview says so, and falls to this tank — nothing to confirm', 'no_gallons' === $p['dose']['plan']['state'] && false === $p['dose']['whole'] && 'The gallons of this water are not known — type the amount.' === $p['dose']['plan']['line'] );

fh_finish();
