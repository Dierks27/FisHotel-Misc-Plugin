<?php
/**
 * Helpers the two dose tests share (dose-save-test.php,
 * dose-phrases-test.php): a dose through Front_Desk::perform(), the logs
 * written since a count, a group checked in so many days ago. They read
 * the test's own $settings and $today.
 *
 * @package FisHotel\Misc\Sections\Clean_Room
 */

use FisHotel\Misc\Sections\Clean_Room\Front_Desk;
use FisHotel\Misc\Sections\Clean_Room\Replay;
use FisHotel\Misc\Sections\Clean_Room\Store;
use FisHotel\Misc\Sections\Clean_Room\Tests\Fake_Store;

// Helper, not a test: the CI job runs every tests/*.php with php, so exit
// cleanly when executed directly instead of as a require.
if ( ! empty( $_SERVER['SCRIPT_FILENAME'] ) && realpath( $_SERVER['SCRIPT_FILENAME'] ) === __FILE__ ) {
	exit( 0 );
}

/**
 * One dose through the Front Desk's action, on a tank's page.
 */
function fh_act( Fake_Store $store, $tank_number, array $raw, $actor = 8, $source = 'qr' ) {
	global $settings, $today;
	return Front_Desk::perform(
		'dose',
		array(
			'store'    => $store,
			'settings' => $settings,
			'today'    => $today,
			'tank'     => $store->tank_by_number( $tank_number ),
			'base'     => array(
				'actor_id' => $actor,
				'source'   => $source,
			),
		),
		$raw
	);
}

/**
 * The logs written since a count, as "kind|tank|building|value|note".
 */
function fh_new_logs( Fake_Store $store, $before ) {
	$out = array();
	foreach ( array_slice( $store->logs, $before, null, true ) as $log ) {
		$out[] = $log['kind'] . '|' . $log['tank_id'] . '|' . ( null === $log['building_id'] ? '-' : $log['building_id'] ) . '|' . $log['value'] . '|' . $log['note'];
	}
	return $out;
}

/**
 * A group checked in so many days ago (day 1 = today), 2 fish.
 */
function fh_checkin( Fake_Store $store, $tank_number, $days_ago, $species = 'Test Tang' ) {
	global $settings, $today;
	$tank = $store->tank_by_number( $tank_number );
	$date = Replay::add_days( $today, -$days_ago );
	$id   = $store->create_stay( array( 'species' => $species, 'checkin_date' => $date, 'tank_id' => (int) $tank['id'], 'source' => 'qr' ) );
	$store->record_event( $id, array( 'type' => 'arrival', 'qty' => 2, 'to_tank_id' => (int) $tank['id'], 'event_at' => 0 === $days_ago ? gmdate( 'Y-m-d H:i:s' ) : Store::site_noon_utc( $date ) ), $today, $settings );
	return $id;
}

