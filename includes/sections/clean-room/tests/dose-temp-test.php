<?php
/**
 * The dose calculator on a temporary tank — where the two meet: the Dose
 * sheet of a slot of the own-water building is handed that tank's own
 * water (the setup's gallons, the dose steps of the setup's OWN schedule
 * on its OWN clock, only what was read and dosed while this setup stood
 * there) and no system; a Save posted as "whole system" lands on the
 * tank with the working from the setup's gallons; a spoken dose is never
 * the whole system either; a free slot has no water; and a rack tank's
 * waters are what they were.
 *
 * Run from the plugin root:  php includes/sections/clean-room/tests/dose-temp-test.php
 */

require_once __DIR__ . '/wp-stubs.php';
require_once __DIR__ . '/dose-helpers.php';

use FisHotel\Misc\Sections\Clean_Room\Admin_Temp;
use FisHotel\Misc\Sections\Clean_Room\Dose;
use FisHotel\Misc\Sections\Clean_Room\Front_Desk;
use FisHotel\Misc\Sections\Clean_Room\Medications;
use FisHotel\Misc\Sections\Clean_Room\Replay;
use FisHotel\Misc\Sections\Clean_Room\Settings;
use FisHotel\Misc\Sections\Clean_Room\Tests\Fake_Store;

$today     = Replay::date_in_zone( time(), wp_timezone() );
$settings  = Settings::get();
$protocols = Settings::protocols();

/**
 * Any write from the phone on a tank's page.
 */
function fh_phone( Fake_Store $store, $tank_number, $act, array $raw ) {
	global $settings, $today;
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

echo "1. The waters of a temporary tank\n";
$store = new Fake_Store();
$r     = fh_phone( $store, 0, 'setup', array( 'name' => 'Hospital', 'kind' => 'quarantine', 'gallons' => 10, 'template' => '14 Day CM', 'start_date' => Replay::add_days( $today, -1 ) ) ); // Day 2.
check( 'a quarantine tank of 10 gallons is set up in the first slot', empty( $r['error'] ) && 901 === (int) $r['tank'], json_encode( $r ) );
$t901   = $store->tank_by_number( 901 );
$waters = Dose::waters( $store, $t901, $today, $settings, $protocols );
check( 'its own water: no system — no gallons, nothing read, dosed or due', true === $waters['own_water'] && 0.0 === $waters['system']['gallons'] && null === $waters['system']['reading'] && array() === $waters['system']['doses'] && array() === $waters['system']['items'] );
check( 'the tank is the setup: its 10 gallons', 10.0 === $waters['tank']['gallons'] );
check( 'the dose steps of its OWN schedule on its OWN day — with no fish in it yet', array( 'Copper Power to 2.4', 'Metronidazole' ) === array_column( $waters['tank']['items'], 'label' ) && array( 2, 2 ) === array_column( $waters['tank']['items'], 'day' ), json_encode( $waters['tank']['items'] ) );
$rack = Dose::waters( $store, $store->tank_by_number( 521 ), $today, $settings, $protocols );
check( 'a rack tank is what it was: a system with its gallons and the tank beside it', false === $rack['own_water'] && 205.0 === $rack['system']['gallons'] && $rack['tank']['gallons'] > 0 );

echo "2. What the page is handed\n";
$screen = Front_Desk::screen_from( $store, 901, $settings, $today );
$dose   = $screen['tank']['dose'];
check( 'the screen of a temporary tank carries the dose block and says it is its own water', true === $screen['tank']['ownWater'] && 10 === $screen['tank']['gallons'] && 0.0 === $dose['system']['gallons'] && 10.0 === $dose['tank']['gallons'] );
check( 'copper starts at the level its own schedule names today; metro at its own rate', 2.4 === $dose['tank']['meds']['copper']['target'] && 'schedule' === $dose['tank']['meds']['copper']['targetFrom'] && 25.0 === $dose['tank']['meds']['metro']['rate'], json_encode( $dose['tank']['meds']['copper'] ) );
check( 'the medication list rides along for the sheet and the Calculator, with the day\'s list and the setup sheet', count( $screen['medications'] ) === count( Medications::active( Medications::from_settings( $settings ) ) ) && is_array( $screen['todoList'] ) && is_array( $screen['temp'] ) && ! isset( $screen['tank']['medications'] ) );
$picker = Front_Desk::screen_from( $store, 0, $settings, $today );
check( 'the picker has all three too, and no tank', null === $picker['tank'] && $picker['medications'] === $screen['medications'] && is_array( $picker['todoList'] ) && is_array( $picker['temp'] ) );
$free = Front_Desk::screen_from( $store, 902, $settings, $today );
check( 'a free slot has no water to dose: no gallons, no step of any schedule', true === $free['tank']['temp']['free'] && 0.0 === $free['tank']['dose']['tank']['gallons'] && 0.0 === $free['tank']['dose']['system']['gallons'] && array() === $free['tank']['dose']['tank']['meds']['copper']['options'] && 'settings' === $free['tank']['dose']['tank']['meds']['copper']['targetFrom'] );

echo "3. Save\n";
$n = count( $store->logs );
$r = fh_act( $store, 901, array( 'medication' => 'copper', 'amount' => '1.7', 'unit' => 'ml', 'whole' => 1, 'calc' => 1, 'now' => '2.16', 'target' => '2.4', 'seen' => '' ) );
check( 'posted as "whole system", the sum behind it: filed on the tank, the working from the setup\'s 10 gallons, the reading with it', empty( $r['error'] ) && 'Copper Power 1.7 ml · Tank 901 · 2.16 → 2.40 ppm, 10 gal · copper reading 2.16 logged' === $r['message'], json_encode( $r ) );
$new = array_slice( $store->logs, $n );
check( '…a reading and ONE dose, both on the tank and both this setup\'s', 2 === count( $new ) && array( 'copper', 'dose' ) === array_column( $new, 'kind' ) && array( (int) $t901['id'], (int) $t901['id'] ) === array_map( 'intval', array_column( $new, 'tank_id' ) ) && array( (int) $t901['setup']['id'], (int) $t901['setup']['id'] ) === array_map( 'intval', array_column( $new, 'setup_id' ) ) && 'Copper Power 1.7 ml · 2.16 → 2.40 ppm, 10 gal' === $new[1]['note'], json_encode( $new ) );
$waters = Dose::waters( $store, $store->tank_by_number( 901 ), $today, $settings, $protocols );
check( 'the sheet now knows of that dose and of that reading', isset( $waters['tank']['doses']['copper'] ) && 2.16 === $waters['tank']['reading']['value'] );
$r = fh_act( $store, 901, array( 'medication' => 'copper', 'amount' => '1.7', 'unit' => 'ml', 'calc' => 1, 'now' => '2.16', 'target' => '2.4', 'seen' => '' ) );
check( 'a Save from a sheet that is behind is refused, as on a rack tank', 0 === strpos( (string) ( $r['error'] ?? '' ), 'Copper Power 1.7 ml was logged in this water at' ) && $n + 2 === count( $store->logs ), json_encode( $r ) );
$room = array_column( $store->room( $today, $settings, $protocols )['tanks'], null, 'number' );
$done = array_column( $room[901]['todo'], 'done', 'label' );
check( 'the dose ticks the copper step and the reading the copper test, on this tank\'s own to-do', true === $done['Copper Power to 2.4'] && true === $done['Copper test'] && false === $done['Metronidazole'], json_encode( $done ) );
check( 'no rack tank sees any of it', null === $room[521]['copper'] && null === Dose::waters( $store, $store->tank_by_number( 521 ), $today, $settings, $protocols )['system']['reading'] );
$n = count( $store->logs );
check( 'a free slot takes no dose', '' !== (string) ( fh_act( $store, 902, array( 'medication' => 'metro', 'amount' => '1', 'unit' => 'g' ) )['error'] ?? '' ) && $n === count( $store->logs ) );

echo "4. A spoken dose\n";
$preview = array(
	'intent'     => 'dose',
	'tank'       => 901,
	'tank_known' => true,
	'needs'      => array(),
	'dose'       => array(
		'medication' => 'metro',
		'scope'      => 'system',
	),
);
$p = Front_Desk::dose_preview( $preview, $store, 0, $today, $settings );
check( '"the whole system" said of a temporary tank is that tank: 10 gallons, 0.25 g', false === $p['dose']['whole'] && 'Tank 901' === $p['dose']['place'] && 10.0 === $p['dose']['gallons'] && 'ok' === $p['dose']['plan']['state'] && 0.25 === (float) $p['dose']['plan']['amount'] && 'g' === $p['dose']['plan']['unit'] && '10 gal × 25 mg' === $p['dose']['plan']['working'], json_encode( $p['dose'] ) );
$p = Front_Desk::dose_preview( array_merge( $preview, array( 'tank' => 902 ) ), $store, 0, $today, $settings );
check( 'a free slot is no water: the preview comes back as it was', ! isset( $p['dose']['plan'] ) );
$p = Front_Desk::dose_preview( array_merge( $preview, array( 'tank' => 521 ) ), $store, 0, $today, $settings );
check( 'a rack tank\'s system is still a system', true === $p['dose']['whole'] && 205.0 === $p['dose']['gallons'] );

echo "5. A new setup in the same slot starts clean; the Board's last dose\n";
$board = Admin_Temp::board( $store, $store->room( $today, $settings, $protocols )['tanks'], $protocols, $today );
check( 'the Temporary area\'s "last dose" reads like a building\'s: the amount and its working', 'Copper Power 1.7 ml (2.16 → 2.40 ppm, 10 gal)' === ( $board['doses'][ (int) $t901['setup']['id'] ]['copper']['label'] ?? '' ), json_encode( $board['doses'] ) );
$tank = $store->tank_by_number( 901 );
$r    = fh_phone( $store, 901, 'takedown', array( 'setup_id' => (int) $tank['setup']['id'], 'seen' => \FisHotel\Misc\Sections\Clean_Room\Temp_Tanks::seen( $tank['setup'] ) ) );
check( 'the tank is taken down', empty( $r['error'] ), json_encode( $r ) );
$r = fh_phone( $store, 901, 'setup', array( 'slot' => 901, 'kind' => 'clean', 'gallons' => 20 ) );
check( 'and set up again, clean, 20 gallons, no treatment', empty( $r['error'] ), json_encode( $r ) );
$waters = Dose::waters( $store, $store->tank_by_number( 901 ), $today, $settings, $protocols );
check( 'the new setup\'s water: 20 gallons, nothing read or dosed today, nothing due', 20.0 === $waters['tank']['gallons'] && null === $waters['tank']['reading'] && array() === $waters['tank']['doses'] && array() === $waters['tank']['items'], json_encode( $waters['tank'] ) );

fh_finish();
