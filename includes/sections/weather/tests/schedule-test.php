<?php
/**
 * Scheduling and settings tests — the nightly run time in the site
 * timezone (DST included), the scan window, and settings sanitising.
 *
 * Run from the plugin root:
 *   php includes/sections/weather/tests/schedule-test.php
 *
 * @package FisHotel\Misc\Sections\Weather
 */

require_once __DIR__ . '/wp-stubs.php';

use FisHotel\Misc\Sections\Weather\Bands;
use FisHotel\Misc\Sections\Weather\Checker;
use FisHotel\Misc\Sections\Weather\Cron;
use FisHotel\Misc\Sections\Weather\Settings;

$chicago = new \DateTimeZone( 'America/Chicago' );

function fh_local( $ts, \DateTimeZone $tz ) {
	return ( new \DateTimeImmutable( '@' . $ts ) )->setTimezone( $tz )->format( 'Y-m-d H:i' );
}

function fh_ts( $local, \DateTimeZone $tz ) {
	return ( new \DateTimeImmutable( $local, $tz ) )->getTimestamp();
}

echo "1. Next run time\n";
$now = fh_ts( '2026-04-01 10:00', $chicago );
check( 'before 21:00 → tonight 21:00', '2026-04-01 21:00' === fh_local( Cron::next_run_timestamp( '21:00', $chicago, $now ), $chicago ) );

$now = fh_ts( '2026-04-01 21:00', $chicago );
check( 'exactly 21:00 → tomorrow 21:00 (strictly after now)', '2026-04-02 21:00' === fh_local( Cron::next_run_timestamp( '21:00', $chicago, $now ), $chicago ) );

$now = fh_ts( '2026-04-01 23:30', $chicago );
check( 'after 21:00 → tomorrow 21:00', '2026-04-02 21:00' === fh_local( Cron::next_run_timestamp( '21:00', $chicago, $now ), $chicago ) );

$now = fh_ts( '2026-03-07 22:00', $chicago ); // DST starts Sunday 2026-03-08 in the US.
$next = Cron::next_run_timestamp( '21:00', $chicago, $now );
check( 'across the spring-forward night it is still 21:00 local', '2026-03-08 21:00' === fh_local( $next, $chicago ) );
check( '…which is 23 hours later, not 24', 23 * 3600 === $next - fh_ts( '2026-03-07 21:00', $chicago ) );

$now = fh_ts( '2026-10-31 22:00', $chicago ); // DST ends Sunday 2026-11-01.
check( 'across the fall-back night it is still 21:00 local', '2026-11-01 21:00' === fh_local( Cron::next_run_timestamp( '21:00', $chicago, $now ), $chicago ) );

$now = fh_ts( '2026-04-01 10:00', $chicago );
check( 'a bad run time falls back to 21:00', '2026-04-01 21:00' === fh_local( Cron::next_run_timestamp( '25:99', $chicago, $now ), $chicago ) );
check( '06:30 works', '2026-04-02 06:30' === fh_local( Cron::next_run_timestamp( '06:30', $chicago, $now ), $chicago ) );
check( 'a different zone is honoured', '2026-04-01 21:00' === fh_local( Cron::next_run_timestamp( '21:00', new \DateTimeZone( 'America/Los_Angeles' ), $now ), new \DateTimeZone( 'America/Los_Angeles' ) ) );

echo "2. Scan window\n";
check( 'target_dates() is today through +3 (a late-firing event must not lose the nearest date)', array( '2026-04-01', '2026-04-02', '2026-04-03', '2026-04-04' ) === Checker::target_dates( '2026-04-01' ) );
check( 'target_dates() crosses a month end', array( '2026-04-30', '2026-05-01', '2026-05-02', '2026-05-03' ) === Checker::target_dates( '2026-04-30' ) );
check( 'DAYS_AHEAD is 3', 3 === Checker::DAYS_AHEAD );
check( 'REFRESH_DAYS is 2', 2 === Checker::REFRESH_DAYS );
check( 'today, +1 and +2 are refreshed every night', Checker::should_refresh( '2026-04-01', '2026-04-01' ) && Checker::should_refresh( '2026-04-02', '2026-04-01' ) && Checker::should_refresh( '2026-04-03', '2026-04-01' ) );
check( '+3 is checked once, then left until it comes within two days', ! Checker::should_refresh( '2026-04-04', '2026-04-01' ) );
check( 'the refresh rule crosses a month end', Checker::should_refresh( '2026-05-02', '2026-04-30' ) && ! Checker::should_refresh( '2026-05-03', '2026-04-30' ) );

echo "3. Time cleaning\n";
check( '"21:00" ok', '21:00' === Settings::clean_time( '21:00' ) );
check( '"9:5" → "09:05"', '09:05' === Settings::clean_time( '9:5' ) );
check( '"21:00:00" (browser with seconds) → "21:00"', '21:00' === Settings::clean_time( '21:00:00' ) );
check( '"24:00" → default', '21:00' === Settings::clean_time( '24:00' ) );
check( '"" → default', '21:00' === Settings::clean_time( '' ) );
check( 'junk → default', '21:00' === Settings::clean_time( 'nine pm' ) );

echo "4. Settings sanitising\n";
$dropped = array();
$saved   = Settings::sanitize(
	array(
		'enabled'       => '1',
		'contact_email' => 'jeff@fishotel.test',
		'run_time'      => '20:30',
		'statuses'      => array( 'wc-processing', 'on-hold', 'fulfillment', 'processing' ),
		'thresholds'    => array_map( 'strval', Bands::DEFAULT_THRESHOLDS ),
		'note_sources'  => '1',
	),
	$dropped
);
check( 'a clean payload saves as-is', true === $saved['enabled'] && 'jeff@fishotel.test' === $saved['contact_email'] && '20:30' === $saved['run_time'] && true === $saved['note_sources'] );
check( 'statuses lose the wc- prefix and dedupe (no WC loaded → every slug kept)', array( 'processing', 'on-hold', 'fulfillment' ) === $saved['statuses'], json_encode( $saved['statuses'] ) );
check( 'thresholds are stored as ints', Bands::DEFAULT_THRESHOLDS === $saved['thresholds'] );
check( 'nothing dropped', array() === $dropped );

$saved = Settings::sanitize(
	array(
		'contact_email' => 'not an email',
		'run_time'      => '99:99',
		'statuses'      => array(),
		'thresholds'    => array_merge( Bands::DEFAULT_THRESHOLDS, array( 'cooling_to' => 70 ) ),
	),
	$dropped
);
check( 'unticked toggles save as false', false === $saved['enabled'] && false === $saved['note_sources'] );
check( 'a bad email is dropped (blank → admin email at runtime)', '' === $saved['contact_email'] );
check( 'a bad run time falls back', '21:00' === $saved['run_time'] );
check( 'no statuses → defaults', Settings::DEFAULTS['statuses'] === $saved['statuses'] );
check( 'bad thresholds → defaults', Bands::DEFAULT_THRESHOLDS === $saved['thresholds'] );
check( 'each fallback is reported', 4 === count( $dropped ), json_encode( $dropped ) );

echo "5. Settings::get() always yields a usable shape\n";
$settings = Settings::get();
check( 'defaults fill in', true === $settings['enabled'] && '21:00' === $settings['run_time'] && Bands::DEFAULT_THRESHOLDS === $settings['thresholds'] );
check( 'contact_email() falls back to the admin email', 'jeff@example.test' === Settings::contact_email() );

fh_finish();
