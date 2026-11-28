<?php
/**
 * US dates — FisHotel\Misc\Dates, the one place the plugin's PHP writes
 * a date for a person (11-27-26) and reads one a person typed.
 *
 *   - us(): a stored YYYY-MM-DD → MM-DD-YY, by its digits alone — a date
 *     is a calendar day and never goes through a clock or a time zone;
 *   - us_time(): a stored date-time keeps its time part;
 *   - stamp() / day(): a moment (UNIX time) in the site's zone, near
 *     midnight and across both daylight-saving changes;
 *   - parse(): MM-DD-YY, MM/DD/YY, MM-DD-YYYY, M/D/YY and still
 *     YYYY-MM-DD; a two-digit year is 20YY; an impossible date is refused
 *     with one plain sentence, never changed into another day;
 *   - what is stored stays YYYY-MM-DD: us() never changes its argument's
 *     meaning and parse() only ever returns YYYY-MM-DD.
 *
 * tests/fixtures/us-dates.json is read by this test AND by
 * includes/sections/clean-room/tests/us-dates.test.js (the page's twin,
 * admin/js/fishotel-dates.js). Pure: no WordPress. All dates invented.
 *
 * Run from the plugin root:
 *   php tests/dates-test.php
 *
 * @package FisHotel\Misc
 */

define( 'ABSPATH', __DIR__ . '/' );

function __( $text, $domain = null ) {
	return $text;
}

require_once dirname( __DIR__ ) . '/includes/class-dates.php';

use FisHotel\Misc\Dates;

$fh_checks   = 0;
$fh_failures = 0;

function check( $label, $condition, $detail = '' ) {
	global $fh_checks, $fh_failures;
	$fh_checks++;
	if ( $condition ) {
		echo "  ok   $label\n";
		return;
	}
	$fh_failures++;
	echo "  FAIL $label" . ( '' !== $detail ? "\n       $detail" : '' ) . "\n";
}

function check_same( $label, $want, $got ) {
	check( $label, $want === $got, 'want ' . var_export( $want, true ) . ', got ' . var_export( $got, true ) );
}

$fh_fixture = json_decode( (string) file_get_contents( __DIR__ . '/fixtures/us-dates.json' ), true );
check( 'the fixture is read', is_array( $fh_fixture ) && ! empty( $fh_fixture['us'] ) && ! empty( $fh_fixture['parse'] ) && ! empty( $fh_fixture['refuse'] ) );

echo "1. us(): a stored date as a person reads it\n";
foreach ( $fh_fixture['us'] as $fh_row ) {
	check_same( 'us( ' . var_export( $fh_row[0], true ) . ' ) → ' . $fh_row[1], $fh_row[1], Dates::us( $fh_row[0] ) );
}
check_same( 'null reads as nothing', '', Dates::us( null ) );
check_same( 'false reads as nothing', '', Dates::us( false ) );
check_same( 'a second argument is what an empty date reads as', '—', Dates::us( '', '—' ) );
check_same( 'and is not used when there is a date', '11-27-26', Dates::us( '2026-11-27', '—' ) );

echo "2. us(): a calendar day, whatever the clock or the zone\n";
$fh_zone_before = date_default_timezone_get();
foreach ( array( 'UTC', 'America/Chicago', 'Pacific/Kiritimati', 'Pacific/Pago_Pago', 'Asia/Kolkata' ) as $fh_zone ) {
	date_default_timezone_set( $fh_zone );
	check_same( "in $fh_zone: 2026-11-27 is 11-27-26", '11-27-26', Dates::us( '2026-11-27' ) );
	check_same( "in $fh_zone: the day the clocks go forward (2026-03-08)", '03-08-26', Dates::us( '2026-03-08' ) );
	check_same( "in $fh_zone: the day the clocks go back (2026-11-01)", '11-01-26', Dates::us( '2026-11-01' ) );
	check_same( "in $fh_zone: the last day of the year", '12-31-26', Dates::us( '2026-12-31' ) );
	check_same( "in $fh_zone: typed 01-01-27 is read as 2027-01-01", '2027-01-01', Dates::to_ymd( '01-01-27' ) );
}
date_default_timezone_set( $fh_zone_before );
$fh_src = (string) file_get_contents( dirname( __DIR__ ) . '/includes/class-dates.php' );
$fh_us  = substr( $fh_src, (int) strpos( $fh_src, 'public static function us(' ), (int) strpos( $fh_src, 'public static function us_time(' ) - (int) strpos( $fh_src, 'public static function us(' ) );
check( 'us() is found in the source', strlen( $fh_us ) > 100 );
check( 'us() uses no clock: no strtotime, date(), DateTime or timestamp', 0 === preg_match( '/strtotime|gmdate|\bdate\s*\(|DateTime|mktime|wp_date/', $fh_us ) );

echo "3. us_time(): a stored date-time keeps its time part\n";
foreach ( $fh_fixture['usTime'] as $fh_row ) {
	check_same( 'us_time( ' . var_export( $fh_row[0], true ) . ' ) → ' . $fh_row[1], $fh_row[1], Dates::us_time( $fh_row[0] ) );
}
check_same( 'null reads as nothing', '', Dates::us_time( null ) );

echo "4. stamp() and day(): a moment in the site's zone\n";
$fh_chicago = new DateTimeZone( 'America/Chicago' );
$fh_at      = function ( $utc ) {
	return (int) strtotime( $utc . ' UTC' );
};
check_same( 'a morning in November', '11-27-26 09:14', Dates::stamp( $fh_at( '2026-11-27 15:14:03' ), $fh_chicago ) );
check_same( 'one minute before midnight is still that day', '11-27-26 23:59', Dates::stamp( $fh_at( '2026-11-28 05:59:00' ), $fh_chicago ) );
check_same( 'midnight is the next day', '11-28-26 00:00', Dates::stamp( $fh_at( '2026-11-28 06:00:00' ), $fh_chicago ) );
check_same( 'day(): 23:59 site time is that day, though UTC is a day ahead', '11-27-26', Dates::day( $fh_at( '2026-11-28 05:59:59' ), $fh_chicago ) );
check_same( 'day(): 00:00 site time is the next day', '11-28-26', Dates::day( $fh_at( '2026-11-28 06:00:00' ), $fh_chicago ) );
check_same( 'the clocks go forward: 01:59 standard time', '03-08-26 01:59', Dates::stamp( $fh_at( '2026-03-08 07:59:00' ), $fh_chicago ) );
check_same( 'the clocks go forward: one minute later it is 03:00', '03-08-26 03:00', Dates::stamp( $fh_at( '2026-03-08 08:00:00' ), $fh_chicago ) );
check_same( 'the clocks go back: 01:30 the first time', '11-01-26 01:30', Dates::stamp( $fh_at( '2026-11-01 06:30:00' ), $fh_chicago ) );
check_same( 'the clocks go back: 01:30 the second time, same day', '11-01-26 01:30', Dates::stamp( $fh_at( '2026-11-01 07:30:00' ), $fh_chicago ) );
check_same( 'the night the clocks go back, 23:30 the evening before', '10-31-26 23:30', Dates::stamp( $fh_at( '2026-11-01 04:30:00' ), $fh_chicago ) );
check_same( 'New Year: 23:59 on Dec 31', '12-31-26 23:59', Dates::stamp( $fh_at( '2027-01-01 05:59:00' ), $fh_chicago ) );
check_same( 'a zone by name', '11-27-26 09:14', Dates::stamp( $fh_at( '2026-11-27 15:14:00' ), 'America/Chicago' ) );
check_same( 'no zone and no WordPress: UTC', '11-27-26 15:14', Dates::stamp( $fh_at( '2026-11-27 15:14:00' ) ) );
check_same( 'a name that is no zone: UTC, not an error', '11-27-26 15:14', Dates::stamp( $fh_at( '2026-11-27 15:14:00' ), 'Not/AZone' ) );
check_same( 'with seconds when asked', '11-27-26 09:14:03', Dates::stamp( $fh_at( '2026-11-27 15:14:03' ), $fh_chicago, true ) );
check_same( 'no moment: nothing', '', Dates::stamp( 0, $fh_chicago ) );
check_same( 'no moment: nothing (day)', '', Dates::day( 0, $fh_chicago ) );
foreach ( array( 'UTC', 'Pacific/Kiritimati', 'Pacific/Pago_Pago' ) as $fh_zone ) {
	date_default_timezone_set( $fh_zone );
	check_same( "PHP's own zone ($fh_zone) does not move it", '11-27-26 23:59', Dates::stamp( $fh_at( '2026-11-28 05:59:00' ), $fh_chicago ) );
}
date_default_timezone_set( $fh_zone_before );

echo "5. from_utc(): a stored UTC date-time in the site's zone\n";
check_same( 'a stored UTC time', '11-27-26 09:14', Dates::from_utc( '2026-11-27 15:14:03', $fh_chicago ) );
check_same( 'stored after midnight UTC, the evening before here', '11-27-26 23:30', Dates::from_utc( '2026-11-28 05:30:00', $fh_chicago ) );
check_same( 'summer time', '07-04-26 08:00', Dates::from_utc( '2026-07-04 13:00:00', $fh_chicago ) );
check_same( 'nothing stored: nothing', '', Dates::from_utc( '', $fh_chicago ) );
check_same( 'nothing stored: nothing (null)', '', Dates::from_utc( null, $fh_chicago ) );
check_same( 'the zero date: nothing', '', Dates::from_utc( '0000-00-00 00:00:00', $fh_chicago ) );
check_same( 'not a time: shown as it is, never hidden', 'soon', Dates::from_utc( 'soon', $fh_chicago ) );

echo "6. parse(): a date a person typed\n";
foreach ( $fh_fixture['parse'] as $fh_row ) {
	$fh_got = Dates::parse( $fh_row[0] );
	check_same( 'parse( ' . var_export( $fh_row[0], true ) . ' ) → ' . var_export( $fh_row[1], true ), array( 'date' => $fh_row[1], 'error' => '' ), $fh_got );
	check_same( 'to_ymd( ' . var_export( $fh_row[0], true ) . ' )', '' === $fh_row[1] ? null : $fh_row[1], Dates::to_ymd( $fh_row[0] ) );
}
check_same( 'null is nothing typed', array( 'date' => '', 'error' => '' ), Dates::parse( null ) );
check_same( 'an array is nothing typed', array( 'date' => '', 'error' => '' ), Dates::parse( array( '11-27-26' ) ) );

echo "7. parse(): what is refused, in one plain sentence — never changed into another day\n";
foreach ( $fh_fixture['refuse'] as $fh_row ) {
	$fh_got = Dates::parse( $fh_row[0] );
	check_same( 'parse( ' . var_export( $fh_row[0], true ) . ' ) is refused', array( 'date' => '', 'error' => $fh_row[1] ), $fh_got );
	check_same( 'to_ymd( ' . var_export( $fh_row[0], true ) . ' ) is null', null, Dates::to_ymd( $fh_row[0] ) );
	check( 'one sentence: one full stop, at the end', 1 === preg_match( '/\.$/', $fh_row[1] ) && 1 === substr_count( $fh_row[1], '.' ) );
}
check( '02-30 is not rolled over to March 2', '' === Dates::parse( '02-30-26' )['date'] && '2026-03-02' !== Dates::to_ymd( '02-30-26' ) );

echo "8. What is stored stays YYYY-MM-DD\n";
foreach ( $fh_fixture['parse'] as $fh_row ) {
	if ( '' !== $fh_row[1] ) {
		check( 'parse( ' . var_export( $fh_row[0], true ) . ' ) gives YYYY-MM-DD', 1 === preg_match( '/^\d{4}-\d{2}-\d{2}$/', Dates::parse( $fh_row[0] )['date'] ) );
	}
}
$fh_stored = '2026-11-27';
$fh_shown  = Dates::us( $fh_stored );
check_same( 'us() leaves the stored value alone', '2026-11-27', $fh_stored );
check_same( 'what is shown reads back to the stored value', '2026-11-27', Dates::to_ymd( $fh_shown ) );
foreach ( array( '2021-01-01', '2026-02-28', '2028-02-29', '2026-03-08', '2026-11-01', '2026-12-31', '2099-12-31', '1999-12-31', '2100-01-01' ) as $fh_day ) {
	check_same( "round trip of $fh_day", $fh_day, Dates::to_ymd( Dates::us( $fh_day ) ) );
}
check_same( 'the format, in one place: the date', 'm-d-y', Dates::DATE );
check_same( 'the format, in one place: the time beside it', 'H:i', Dates::TIME );
check_same( 'the example a refusal shows', '11-27-26', Dates::us( Dates::EXAMPLE ) );

echo "9. is_ymd(): the strict stored shape\n";
check( 'a stored date', Dates::is_ymd( '2026-11-27' ) );
check( 'not the US form', ! Dates::is_ymd( '11-27-26' ) );
check( 'not an impossible date', ! Dates::is_ymd( '2026-02-30' ) );
check( 'not a date-time', ! Dates::is_ymd( '2026-11-27 09:14' ) );
check( 'not nothing', ! Dates::is_ymd( '' ) && ! Dates::is_ymd( null ) );

echo "\n" . ( $fh_failures ? "$fh_failures FAILED of $fh_checks\n" : "all $fh_checks passed\n" );
exit( $fh_failures ? 1 : 0 );
