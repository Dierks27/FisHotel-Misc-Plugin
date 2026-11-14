<?php
/**
 * Note formatter tests — byte-equal against the skill's example.
 *
 * Run from the plugin root:
 *   php includes/sections/weather/tests/note-test.php
 *
 * fixtures/note-example.txt is the example note from the order-workflow
 * skill with the 70–80°F band ending appended per the chart. A note
 * written by the cron must be indistinguishable from one written by hand.
 *
 * @package FisHotel\Misc\Sections\Weather
 */

require_once __DIR__ . '/wp-stubs.php';

use FisHotel\Misc\Sections\Weather\Bands;
use FisHotel\Misc\Sections\Weather\Note;

$fixture = rtrim( fh_fixture( 'note-example.txt' ), "\r\n" );

$example = array(
	'delivery_date' => '2026-04-01',
	'zip'           => '23314',
	'city'          => 'Carrollton',
	'state'         => 'VA',
	'high'          => 76,
	'low'           => 57,
	'conditions'    => Note::conditions( 'Sunny', 10 ),
	'warnings'      => array(),
	'ending'        => Bands::classify( 76 )['ending'],
	'next_day_line' => null,
);

echo "1. The skill example, byte for byte\n";
$note = Note::format( $example );
check( 'note equals the fixture', $fixture === $note, "expected: $fixture\n       got:      $note" );
check( 'fixture is non-empty and looks like a note', 0 === strpos( $fixture, 'Weather for ' ) );
check( 'the em dash and degree sign survive as UTF-8', false !== strpos( $note, '°F — ' ) );

echo "2. Dates\n";
check( 'long_date() → "April 1, 2026"', 'April 1, 2026' === Note::long_date( '2026-04-01' ) );
check( 'long_date() → "December 25, 2026"', 'December 25, 2026' === Note::long_date( '2026-12-25' ) );
check( 'short_day() → "Wed"', 'Wed' === Note::short_day( '2026-04-01' ) );
check( 'date() rejects a malformed date', null === Note::date( '2026-4-1' ) && null === Note::date( '2026-02-30' ) && null === Note::date( 'tomorrow' ) );
check( 'add_days() crosses a month', '2026-05-01' === Note::add_days( '2026-04-30', 1 ) );
check( 'add_days() crosses a year', '2027-01-02' === Note::add_days( '2026-12-31', 2 ) );
check( 'add_days() negative', '2026-03-31' === Note::add_days( '2026-04-01', -1 ) );

echo "3. Conditions\n";
check( 'pop null → conditions only', 'Sunny' === Note::conditions( 'Sunny', null ) );
check( 'pop 0 → conditions only', 'Sunny' === Note::conditions( 'Sunny', 0 ) );
check( 'pop 10 → "Sunny, 10% chance of rain"', 'Sunny, 10% chance of rain' === Note::conditions( 'Sunny', 10 ) );
check( 'wintry forecast says snow', 'Snow Showers Likely, 60% chance of snow' === Note::conditions( 'Snow Showers Likely', 60 ) );
check( 'trailing period and extra whitespace are cleaned', 'Partly  Cloudy' !== Note::conditions( 'Partly  Cloudy.', null ) && 'Partly Cloudy' === Note::conditions( 'Partly  Cloudy.', null ) );
check( 'empty forecast gets a placeholder', 'Conditions not reported' === Note::conditions( '', null ) );

echo "4. Variations keep the skill's shape\n";
$no_low = Note::format( array_merge( $example, array( 'low' => null ) ) );
check( 'no low → "High 76°F — …" with no Low part', 'Weather for April 1, 2026 at 23314 (Carrollton, VA): High 76°F — Sunny, 10% chance of rain. Nothing needed — great shipping conditions.' === $no_low, $no_low );

$with_warning = Note::format( array_merge( $example, array( 'warnings' => array( 'Flood Watch', 'Wind Advisory' ) ) ) );
check( 'warnings sit between conditions and the ending', 'Weather for April 1, 2026 at 23314 (Carrollton, VA): High 76°F / Low 57°F — Sunny, 10% chance of rain. Alerts: Flood Watch, Wind Advisory. Nothing needed — great shipping conditions.' === $with_warning, $with_warning );

$heat = Note::format( array_merge( $example, array( 'high' => 55, 'low' => 40, 'conditions' => Note::conditions( 'Mostly Cloudy', null ), 'ending' => Bands::classify( 55 )['ending'] ) ) );
check( 'heat pack note', 'Weather for April 1, 2026 at 23314 (Carrollton, VA): High 55°F / Low 40°F — Mostly Cloudy. Heat pack.' === $heat, $heat );

$ups = Note::format( array_merge( $example, array( 'high' => 93, 'low' => 75, 'conditions' => 'Sunny', 'ending' => Bands::classify( 93 )['ending'] ) ) );
check( 'UPS Store (hot) note', 'Weather for April 1, 2026 at 23314 (Carrollton, VA): High 93°F / Low 75°F — Sunny. UPS STORE (90-95°F): ship to local UPS Store for customer pickup. Email drafted for customer.' === $ups, $ups );

$hold = Note::format( array_merge( $example, array( 'high' => 22, 'low' => 10, 'conditions' => Note::conditions( 'Snow', 80 ), 'ending' => Bands::classify( 22 )['ending'] ) ) );
check( 'HOLD (cold) note', 'Weather for April 1, 2026 at 23314 (Carrollton, VA): High 22°F / Low 10°F — Snow, 80% chance of snow. HOLD: below 30°F — wait to ship.' === $hold, $hold );

$line = Note::format( array_merge( $example, array( 'high' => 90, 'low' => 72, 'conditions' => 'Sunny', 'ending' => Bands::classify( 90 )['ending'] ) ) );
check( 'AT THE LINE note', 'Weather for April 1, 2026 at 23314 (Carrollton, VA): High 90°F / Low 72°F — Sunny. AT THE LINE (90°F) — Jeff\'s call: Cooling packs (80-90°F) or UPS STORE (90-95°F).' === $line, $line );

echo "5. Next-day extremes\n";
check( 'next day 98 → "Thu hits 98°F — do not slip delivery."', 'Thu hits 98°F — do not slip delivery.' === Note::next_day_line( '2026-04-02', 98 ) );
check( 'next day 28 → "Thu drops to 28°F — do not slip delivery."', 'Thu drops to 28°F — do not slip delivery.' === Note::next_day_line( '2026-04-02', 28 ) );
check( 'next day 91 (UPS band) is surfaced', null !== Note::next_day_line( '2026-04-02', 91 ) );
check( 'next day 85 (cooling) is not surfaced', null === Note::next_day_line( '2026-04-02', 85 ) );
check( 'next day 45 (heat pack) is not surfaced', null === Note::next_day_line( '2026-04-02', 45 ) );
check( 'next day unknown is not surfaced', null === Note::next_day_line( '2026-04-02', null ) );
$with_next = Note::format( array_merge( $example, array( 'next_day_line' => Note::next_day_line( '2026-04-02', 98 ) ) ) );
check( 'the line is appended after the ending', $fixture . ' Thu hits 98°F — do not slip delivery.' === $with_next, $with_next );

echo "6. Unavailable\n";
$unavailable = Note::unavailable( array_merge( $example, array( 'reason' => 'geocode failed: ZIP 00000 not found.' ) ) );
check( 'unavailable note says check manually and carries the reason', 'Weather for April 1, 2026 at 23314 (Carrollton, VA): weather unavailable — check manually (geocode failed: ZIP 00000 not found).' === $unavailable, $unavailable );
check( 'unavailable note never contains a band ending', false === strpos( $unavailable, 'Heat pack' ) && false === strpos( $unavailable, 'HOLD' ) && false === strpos( $unavailable, 'UPS' ) );
check( 'unavailable without a reason', 'Weather for April 1, 2026 at 23314 (Carrollton, VA): weather unavailable — check manually.' === Note::unavailable( $example ) );

echo "7. Missing place parts\n";
check( 'no state → "(Carrollton)"', 'Carrollton' === Note::place( 'Carrollton', '' ) );
check( 'nothing → "unknown"', 'unknown' === Note::place( '', '' ) );
$no_zip = Note::format( array_merge( $example, array( 'zip' => '' ) ) );
check( 'missing ZIP keeps the shape with "?"', 0 === strpos( $no_zip, 'Weather for April 1, 2026 at ? (Carrollton, VA): ' ) );

echo "8. UPS Store email draft\n";
$draft = Note::email_draft(
	array(
		'order_number'  => '33241',
		'first_name'    => 'Dana',
		'city'          => 'Carrollton',
		'state'         => 'VA',
		'delivery_date' => '2026-04-01',
		'high'          => 93,
		'action'        => 'ups_store_hot',
	)
);
check( 'subject matches the skill', 'Your FisHotel Order #33241 — Weather Shipping Change' === $draft['subject'], $draft['subject'] );
check( 'body greets by first name', 0 === strpos( $draft['body'], "Hi Dana,\n\n" ) );
check( 'body names the forecast high and says too hot', false !== strpos( $draft['body'], 'hits 93°F, which is too hot for a box of fish to sit on a porch' ) );
check( 'body asks for a UPS Store and mentions closest-to-address', false !== strpos( $draft['body'], 'preferred UPS Store location' ) && false !== strpos( $draft['body'], 'closest to your address' ) );
check( 'body says tracking follows on ship day', false !== strpos( $draft['body'], 'Tracking will follow on ship day' ) );
check( 'body is signed Jeff — FisHotel', '' !== $draft['body'] && 'Jeff — FisHotel' === substr( $draft['body'], -strlen( 'Jeff — FisHotel' ) ) );

$cold_draft = Note::email_draft( array( 'order_number' => '7', 'first_name' => '', 'city' => 'Fargo', 'state' => 'ND', 'delivery_date' => '2026-01-12', 'high' => 34, 'action' => 'ups_store_cold' ) );
check( 'cold draft says too cold', false !== strpos( $cold_draft['body'], 'hits 34°F, which is too cold' ) );
check( 'no first name → "Hi there,"', 0 === strpos( $cold_draft['body'], "Hi there,\n" ) );
check( 'draft never says "sent"', false === stripos( $draft['body'], 'sent' ) );

fh_finish();
