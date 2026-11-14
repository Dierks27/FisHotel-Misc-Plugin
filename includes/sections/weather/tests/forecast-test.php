<?php
/**
 * Forecast parsing tests — picking the delivery-day period out of a real
 * NWS JSON shape, plus the whole lookup against a fake client.
 *
 * Run from the plugin root:
 *   php includes/sections/weather/tests/forecast-test.php
 *
 * @package FisHotel\Misc\Sections\Weather
 */

require_once __DIR__ . '/wp-stubs.php';

use FisHotel\Misc\Sections\Weather\Bands;
use FisHotel\Misc\Sections\Weather\Checker;
use FisHotel\Misc\Sections\Weather\Forecast;
use FisHotel\Misc\Sections\Weather\Nws_Client;

$forecast_json = fh_fixture( 'nws-forecast.json' );
$periods       = Forecast::periods( $forecast_json );

echo "1. Periods\n";
check( 'periods() reads properties.periods from the JSON string', is_array( $periods ) && 10 === count( $periods ) );
check( 'periods() accepts a decoded array too', 10 === count( Forecast::periods( json_decode( $forecast_json, true ) ) ) );
check( 'periods() rejects junk', null === Forecast::periods( 'not json' ) && null === Forecast::periods( array( 'properties' => array() ) ) );
check( 'period_date() uses the local date in startTime', '2026-04-01' === Forecast::period_date( $periods[1] ) );

echo "2. Picking the delivery day\n";
$day = Forecast::pick_day( $periods, '2026-04-01' );
check( 'the daytime period for April 1 is "Wednesday"', $day && 'Wednesday' === $day['name'] );
check( '…with the high 76', 76 === Forecast::temperature_f( $day ) );
check( '"Tonight" (night, March 31) is never picked as a day', null === Forecast::pick_day( $periods, '2026-03-31' ) );
check( 'the night period for April 1 is "Wednesday Night"', 'Wednesday Night' === Forecast::pick_night( $periods, '2026-04-01' )['name'] );
check( 'a date beyond the forecast has no period', null === Forecast::pick_day( $periods, '2026-04-10' ) );
check( 'a date before the forecast has no period', null === Forecast::pick_day( $periods, '2026-03-30' ) );

echo "3. Day summary\n";
$summary = Forecast::day_summary( $periods, '2026-04-01' );
check( 'summary high/low for April 1', 76 === $summary['high'] && 57 === $summary['low'] );
check( 'summary short forecast and pop', 'Sunny' === $summary['short'] && 10 === $summary['pop'] );
check( 'a day with a C unit converts to F (20°C → 68°F)', 68 === Forecast::day_summary( $periods, '2026-04-03' )['high'] );
check( 'a night with a C unit converts too (5°C → 41°F)', 41 === Forecast::day_summary( $periods, '2026-04-03' )['low'] );
check( 'a day with no night period has low null', null === Forecast::day_summary( $periods, '2026-04-04' )['low'] );
check( 'a night with a non-numeric temperature yields low null', null === Forecast::day_summary( $periods, '2026-04-05' )['low'] );
check( 'pop null stays null', null === Forecast::day_summary( $periods, '2026-04-02' )['pop'] );
check( 'no daytime period → null summary', null === Forecast::day_summary( $periods, '2026-04-10' ) );

echo "4. Points, geocoder and ZIP responses\n";
$point = Forecast::point( fh_fixture( 'nws-points.json' ) );
check( 'point() finds the forecast URL', 'https://api.weather.gov/gridpoints/AKQ/58,62/forecast' === $point['forecast_url'] );
check( 'point() builds the grid key', 'AKQ/58,62' === $point['grid_key'] );
check( 'point() reads the relative city/state', 'Carrollton' === $point['city'] && 'VA' === $point['state'] );
check( 'point() rejects a forecast URL off api.weather.gov', null === Forecast::point( array( 'properties' => array( 'forecast' => 'https://evil.example/x' ) ) ) );
check( 'point() rejects a response without properties', null === Forecast::point( '{"title":"Not Found"}' ) );

$census = Forecast::census_match( fh_fixture( 'census-geocode.json' ) );
check( 'census_match() returns lat/lon (y = lat, x = lon)', $census && abs( $census['lat'] - 36.9397456 ) < 1e-6 && abs( $census['lon'] + 76.5600123 ) < 1e-6 );
check( 'census_match() with no matches → null', null === Forecast::census_match( '{"result":{"addressMatches":[]}}' ) );

$zip = Forecast::zip_match( '{"post code":"23314","places":[{"place name":"Carrollton","latitude":"36.9397","longitude":"-76.5600","state abbreviation":"VA"}]}' );
check( 'zip_match() returns the centroid', $zip && 36.9397 === $zip['lat'] && -76.56 === $zip['lon'] && 'Carrollton, VA' === $zip['matched'] );
check( 'zip_match() with no places → null', null === Forecast::zip_match( '{}' ) );

echo "5. Alerts\n";
$alerts = fh_fixture( 'nws-alerts.json' );
check( 'April 1 is covered by the Flood Watch only, listed once', array( 'Flood Watch' ) === Forecast::alerts_for_day( $alerts, '2026-04-01' ) );
check( 'April 3 is covered by the Wind Advisory only', array( 'Wind Advisory' ) === Forecast::alerts_for_day( $alerts, '2026-04-03' ) );
check( 'April 2 catches the open-ended duplicate Flood Watch', array( 'Flood Watch' ) === Forecast::alerts_for_day( $alerts, '2026-04-02' ) );
check( 'April 6 has nothing', array() === Forecast::alerts_for_day( $alerts, '2026-04-06' ) );
check( 'junk input → nothing', array() === Forecast::alerts_for_day( 'nope', '2026-04-01' ) );

echo "6. Address normalisation\n";
check( 'normalise_address() lowercases and strips punctuation', '14000 carrollton blvd carrollton va 23314' === Nws_Client::normalise_address( array( 'address_1' => '14000 Carrollton Blvd.', 'city' => 'Carrollton', 'state' => 'VA', 'postcode' => '23314' ) ) );
check( 'normalise_address() is stable across spacing/case', Nws_Client::normalise_address( array( 'address_1' => ' 14000  CARROLLTON BLVD ', 'city' => 'carrollton', 'state' => 'va', 'postcode' => '23314' ) ) === Nws_Client::normalise_address( array( 'address_1' => '14000 Carrollton Blvd', 'city' => 'Carrollton', 'state' => 'VA', 'postcode' => '23314' ) ) );

// A client that never touches the network (shared with checker-test.php).
require_once __DIR__ . '/fake-client.php';

$destination = array(
	'address_1'  => '14000 Carrollton Blvd',
	'address_2'  => '',
	'city'       => 'Carrollton',
	'state'      => 'VA',
	'postcode'   => '23314',
	'country'    => 'US',
	'first_name' => 'Dana',
);

$client = new Fake_Client();
$client->fail = 'alerts';

echo "7. Whole lookup against the fixtures\n";
$result = Checker::lookup( $destination, '2026-04-01', $client, Bands::DEFAULT_THRESHOLDS );
check( 'lookup succeeds', $result['ok'] );
check( 'lookup high/low', 76 === $result['high'] && 57 === $result['low'] );
check( 'lookup action is none (76°F)', 'none' === $result['action'] && ! $result['at_line'] );
check( 'lookup summary', 'High 76°F / Low 57°F — Sunny, 10% chance of rain' === $result['summary'], $result['summary'] );
$fixture_note = rtrim( fh_fixture( 'note-example.txt' ), "\r\n" );
check( 'lookup note = skill example + next-day extreme (Thu 98)', $fixture_note . ' Thu hits 98°F — do not slip delivery.' === $result['note'], $result['note'] );
check( 'next-day data is carried', '2026-04-02' === $result['next_day']['date'] && 98 === $result['next_day']['high'] );

$client->fail = '';
$result = Checker::lookup( $destination, '2026-04-01', $client, Bands::DEFAULT_THRESHOLDS );
check( 'active alerts land in the note', false !== strpos( $result['note'], ' Alerts: Flood Watch. ' ), $result['note'] );
check( 'warnings array matches', array( 'Flood Watch' ) === $result['warnings'] );

$result = Checker::lookup( $destination, '2026-04-02', $client, Bands::DEFAULT_THRESHOLDS );
check( 'April 2 (98°F) → hold_hot', 'hold_hot' === $result['action'] );
check( 'April 2 note ends with the HOLD ending (April 3 is 68°F, no extremes line)', 'HOLD: above 95°F — no ship.' === substr( $result['note'], -strlen( 'HOLD: above 95°F — no ship.' ) ), $result['note'] );

$result = Checker::lookup( $destination, '2026-04-05', $client, Bands::DEFAULT_THRESHOLDS );
check( 'April 5 (90°F) is AT THE LINE, stored as ups_store_hot', $result['at_line'] && 'ups_store_hot' === $result['action'] );
check( 'April 5 has no night period → no Low in the note', false !== strpos( $result['note'], 'High 90°F — Sunny.' ), $result['note'] );

$result = Checker::lookup( $destination, '2026-04-04', $client, Bands::DEFAULT_THRESHOLDS );
check( 'April 4 (36°F) → ups_store_cold with a snow pop', 'ups_store_cold' === $result['action'] && false !== strpos( $result['note'], '60% chance of snow' ), $result['note'] );

echo "8. Every failure is 'unavailable', never a band\n";
$result = Checker::lookup( $destination, '2026-04-10', $client, Bands::DEFAULT_THRESHOLDS );
check( 'a date beyond 7 days is unavailable', ! $result['ok'] && 'unavailable' === $result['action'] );
check( '…with a reason naming the date', false !== strpos( $result['reason'], 'April 10, 2026' ), $result['reason'] );
check( '…and the check-manually note', 0 === strpos( $result['note'], 'Weather for April 10, 2026 at 23314 (Carrollton, VA): weather unavailable — check manually (' ), $result['note'] );

foreach ( array( 'geocode', 'point', 'forecast' ) as $stage ) {
	$client->fail = $stage;
	$result       = Checker::lookup( $destination, '2026-04-01', $client, Bands::DEFAULT_THRESHOLDS );
	check( "$stage failure → unavailable", ! $result['ok'] && 'unavailable' === $result['action'] && null === $result['high'] );
	check( "$stage failure reason is carried", '' !== $result['reason'] && false !== strpos( $result['note'], $result['reason'] ) );
}
$client->fail = '';

$result = Checker::lookup( array_merge( $destination, array( 'country' => 'CA' ) ), '2026-04-01', $client, Bands::DEFAULT_THRESHOLDS );
check( 'a non-US address is unavailable without any network call', ! $result['ok'] && false !== strpos( $result['reason'], 'US addresses only' ) );

$result = Checker::lookup( array_merge( $destination, array( 'country' => '' ) ), '2026-04-01', $client, Bands::DEFAULT_THRESHOLDS );
check( 'a blank country is treated as US', $result['ok'] );

fh_finish();
