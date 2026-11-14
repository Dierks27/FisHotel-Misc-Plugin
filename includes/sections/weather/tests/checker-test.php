<?php
/**
 * Checker tests — persistence and the nightly scan against a fake order
 * store and a fake NWS client. No WordPress, no database, no network.
 *
 * Run from the plugin root:
 *   php includes/sections/weather/tests/checker-test.php
 *
 * The fixture forecast starts the night of 2026-03-31 and covers April 1
 * (76°F, nothing needed) … April 5 (90°F); "today" is pinned to March 31
 * so the scan window is March 31 … April 3.
 *
 * @package FisHotel\Misc\Sections\Weather
 */

require_once __DIR__ . '/wp-stubs.php';
require_once __DIR__ . '/fake-client.php';
require_once __DIR__ . '/fake-order.php';

use FisHotel\Misc\Sections\Weather\Checker;
use FisHotel\Misc\Sections\Weather\Cron;
use FisHotel\Misc\Sections\Weather\Settings;
use FisHotel\Misc\Sections\Weather\Weather;

$GLOBALS['fh_now'] = '2026-03-31 21:00:00';

$client = new Fake_Client();

/**
 * The note the fixture yields for a date — what a fresh check must write.
 */
function fh_expected_note( WC_Order $order, $ymd, Fake_Client $client ) {
	return Checker::lookup( Checker::destination( $order ), $ymd, $client, Settings::get()['thresholds'] )['note'];
}

echo "1. A failed re-check after a success drops the success marker\n";
fh_reset_orders();
$order = fh_register_order( new WC_Order( 100, 'processing', array( Checker::META_DELIVERY_DATE => '2026-04-01' ) ) );

$result = Checker::check_order( $order, true, $client );
check( 'first check succeeds: noted, 76°F → none', $result['ok'] && 'noted' === $result['note_status'] && 'none' === $result['action'] );
check( 'checked_for = delivery date, one private note', '2026-04-01' === $order->get_meta( Checker::META_CHECKED_FOR ) && 1 === count( $order->notes() ) );
check( 'the note is the fixture note', fh_expected_note( $order, '2026-04-01', $client ) === $order->last_note(), $order->last_note() );
check( 'note_status() reads noted', 'noted' === Checker::note_status( $order, '2026-04-01' ) );

$client->fail = 'point';
$result       = Checker::check_order( $order, true, $client );
check( 'a forced re-check while NWS is down → unavailable', ! $result['ok'] && 'unavailable' === $result['note_status'] && 'unavailable' === $result['action'] );
check( 'checked_for is removed, not kept', ! $order->has_meta( Checker::META_CHECKED_FOR ) );
check( 'unavailable_for = delivery date; high/low cleared; action unavailable', '2026-04-01' === $order->get_meta( Checker::META_UNAVAILABLE_FOR ) && '' === $order->get_meta( Checker::META_HIGH ) && '' === $order->get_meta( Checker::META_LOW ) && 'unavailable' === $order->get_meta( Checker::META_ACTION ) );
check( 'note_status() reads unavailable, not noted', 'unavailable' === Checker::note_status( $order, '2026-04-01' ) );
check( 'stored() reports not ok', false === Checker::stored( $order )['ok'] );
check( 'a check-manually note names the reason', 2 === count( $order->notes() ) && false !== strpos( $order->last_note(), 'weather unavailable — check manually (api.weather.gov returned HTTP 503)' ), $order->last_note() );

$client->fail = '';
$result       = Checker::check_order( $order, false, $client ); // The nightly path (no force).
check( 'the nightly path retries instead of returning the stored failure', 'noted' === $result['note_status'] && $result['ok'] );
check( 'success marker back, failure marker gone', '2026-04-01' === $order->get_meta( Checker::META_CHECKED_FOR ) && ! $order->has_meta( Checker::META_UNAVAILABLE_FOR ) );
check( 'the good note is written again, after the failure note', 3 === count( $order->notes() ) && fh_expected_note( $order, '2026-04-01', $client ) === $order->last_note() );

echo "2. One failure note per delivery date, whatever the reason says\n";
$order = fh_register_order( new WC_Order( 101, 'processing', array( Checker::META_DELIVERY_DATE => '2026-04-01' ) ) );

$client->fail = 'point';
check( 'first failure writes a note', 'unavailable' === Checker::check_order( $order, true, $client )['note_status'] && 1 === count( $order->notes() ) );
$client->fail = 'forecast';
check( 'a different reason the next night is a repeat (no second note)', 'unavailable_repeat' === Checker::check_order( $order, true, $client )['note_status'] && 1 === count( $order->notes() ) );
check( '…but the latest reason is kept in the summary meta', 'api.weather.gov returned HTTP 500' === $order->get_meta( Checker::META_SUMMARY ), (string) $order->get_meta( Checker::META_SUMMARY ) );
$client->fail = 'geocode';
check( 'a third failure is still a repeat', 'unavailable_repeat' === Checker::check_order( $order, true, $client )['note_status'] && 1 === count( $order->notes() ) );
$client->fail = '';
check( 'then a success is noted', 'noted' === Checker::check_order( $order, true, $client )['note_status'] && 2 === count( $order->notes() ) );
$client->fail = 'point';
check( 'a failure after that success is a new failure note', 'unavailable' === Checker::check_order( $order, true, $client )['note_status'] && 3 === count( $order->notes() ) );
$client->fail = '';

echo "3. The UPS Store draft comes and goes with the result\n";
$order = fh_register_order( new WC_Order( 102, 'processing', array( Checker::META_DELIVERY_DATE => '2026-04-04' ) ) );
$result = Checker::check_order( $order, true, $client );
check( 'April 4 (36°F) → ups_store_cold with a draft', 'ups_store_cold' === $result['action'] && is_array( $result['email'] ) );
check( 'the draft is stored and names this order', 'Your FisHotel Order #102 — Weather Shipping Change' === $order->get_meta( Checker::META_EMAIL_DRAFT )['subject'] );
$client->fail = 'point';
Checker::check_order( $order, true, $client );
check( 'a failed re-check removes the draft', ! $order->has_meta( Checker::META_EMAIL_DRAFT ) );
$client->fail = '';

echo "4. The nightly scan\n";
fh_reset_orders();
$GLOBALS['fh_now'] = '2026-03-31 21:00:00';
$settings          = Settings::get();
$settings['enabled'] = false; // The toggle is off: the on-demand scan must still do the work.

$stale_note = 'Weather for April 1, 2026 at 23314 (Carrollton, VA): High 55°F / Low 40°F — Mostly Cloudy. Heat pack.';
$o201       = fh_register_order( new WC_Order( 201, 'processing', array( Checker::META_DELIVERY_DATE => '2026-04-01', Checker::META_CHECKED_FOR => '2026-04-01', Checker::META_ACTION => 'heat_pack', Checker::META_HIGH => 55, Checker::META_LOW => 40, Checker::META_NOTE => $stale_note, Checker::META_CHECKED_AT => '2026-03-30 21:00:05' ) ) ); // Tomorrow, noted last night from an older forecast.
$o202       = fh_register_order( new WC_Order( 202, 'processing', array( Checker::META_DELIVERY_DATE => '2026-04-03', Checker::META_CHECKED_FOR => '2026-04-03', Checker::META_ACTION => 'heat_pack', Checker::META_HIGH => 60, Checker::META_NOTE => 'old', Checker::META_CHECKED_AT => '2026-03-30 21:00:06' ) ) ); // +3, already noted.
$o203       = fh_register_order( new WC_Order( 203, 'on-hold', array( Checker::META_DELIVERY_DATE => '2026-04-02' ) ) ); // +2, never checked.
$o204       = fh_register_order( new WC_Order( 204, 'processing', array( Checker::META_DELIVERY_DATE => '2026-03-31' ) ) ); // Today, never checked; the fixture has no daytime period.
$o205       = fh_register_order( new WC_Order( 205, 'processing', array( Checker::META_DELIVERY_DATE => '2026-04-05' ) ) ); // +5, outside the window.
$o206       = fh_register_order( new WC_Order( 206, 'completed', array( Checker::META_DELIVERY_DATE => '2026-04-01' ) ) ); // Not a scanned status.
$o207       = fh_register_order( new WC_Order( 207, 'processing', array( Checker::META_DELIVERY_DATE => '2026-04-01', Checker::META_FULFILLED_BY => '300' ) ) ); // Folded into a box.
$o208       = fh_register_order( new WC_Order( 208, 'processing', array() ) ); // No delivery date.

$summary = Checker::run_nightly( $settings, $client );
check( 'the window is today through +3', array( '2026-03-31', '2026-04-01', '2026-04-02', '2026-04-03' ) === $summary['dates'], json_encode( $summary['dates'] ) );
check( 'with the toggle off the scan still checks orders (3)', 3 === $summary['checked'], json_encode( $summary ) );
check( 'noted 2 (201 refreshed, 203 first time), unavailable 1 (204: no daytime period yet)', 2 === $summary['noted'] && 1 === $summary['unavailable'], json_encode( $summary ) );
check( 'skipped 2 (202 at +3 already noted, 207 folded into a box)', 2 === $summary['skipped'], json_encode( $summary ) );
check( 'no errors', 0 === $summary['errors'] );
check( '201 (tomorrow) was refreshed: 76°F, none, a new note', 76 === (int) $o201->get_meta( Checker::META_HIGH ) && 'none' === $o201->get_meta( Checker::META_ACTION ) && 1 === count( $o201->notes() ) );
check( '…with the fresh note text', fh_expected_note( $o201, '2026-04-01', $client ) === $o201->last_note(), $o201->last_note() );
check( '202 (+3, already noted) was left alone', 'old' === $o202->get_meta( Checker::META_NOTE ) && 0 === $o202->saves && 0 === count( $o202->notes() ) );
check( '203 (+2) got its first note: 98°F → hold_hot', 'hold_hot' === $o203->get_meta( Checker::META_ACTION ) && '2026-04-02' === $o203->get_meta( Checker::META_CHECKED_FOR ) && 1 === count( $o203->notes() ) );
check( '204 (today) is unavailable, never a guessed band', 'unavailable' === $o204->get_meta( Checker::META_ACTION ) && '2026-03-31' === $o204->get_meta( Checker::META_UNAVAILABLE_FOR ) && ! $o204->has_meta( Checker::META_CHECKED_FOR ) );
check( '205 (+5), 206 (completed), 207 (folded) and 208 (no date) were not touched', 0 === $o205->saves && 0 === $o206->saves && 0 === $o207->saves && 0 === $o208->saves );
check( 'the last-run summary is stored', $summary === get_option( Weather::LAST_RUN_OPTION ) );

$summary = Checker::run_nightly( $settings, $client );
check( 'a repeat run re-checks the near dates again (3 checked)', 3 === $summary['checked'] && 2 === $summary['skipped'], json_encode( $summary ) );
check( '…but adds no note when nothing changed', 1 === count( $o201->notes() ) && 1 === count( $o203->notes() ) );
check( '…and no second failure note for 204', 1 === count( $o204->notes() ) );

$GLOBALS['fh_now'] = '2026-04-01 00:30:00'; // The 21:00 event fired late, after midnight.
$o209              = fh_register_order( new WC_Order( 209, 'processing', array( Checker::META_DELIVERY_DATE => '2026-04-01' ) ) ); // Placed yesterday afternoon.
$summary           = Checker::run_nightly( $settings, $client );
check( 'an event that fires after midnight still covers orders delivering that day', '2026-04-01' === $summary['dates'][0] && '2026-04-01' === $o209->get_meta( Checker::META_CHECKED_FOR ) && 1 === count( $o209->notes() ), json_encode( $summary ) );
check( '202 (now +2) is refreshed once it comes within two days', 'old' !== $o202->get_meta( Checker::META_NOTE ) && 1 === count( $o202->notes() ) && 68 === (int) $o202->get_meta( Checker::META_HIGH ) );
check( '204 (yesterday) is out of the window now', 1 === count( $o204->notes() ) && 0 === $summary['errors'] );

echo "5. Fan-out to a combined box skips cancelled / refunded sources\n";
fh_reset_orders();
$GLOBALS['fh_now'] = '2026-03-31 21:00:00';
$wrapper = fh_register_order( new WC_Order( 300, 'fulfillment', array( Checker::META_DELIVERY_DATE => '2026-04-04', Checker::META_IS_FULFILLMENT => '1', Checker::META_SOURCES => '301, 302,303,300' ) ) );
$s301    = fh_register_order( new WC_Order( 301, 'processing', array( Checker::META_FULFILLED_BY => '300' ) ) );
$s302    = fh_register_order( new WC_Order( 302, 'refunded', array( Checker::META_FULFILLED_BY => '300' ) ) );
$s303    = fh_register_order( new WC_Order( 303, 'cancelled', array( Checker::META_FULFILLED_BY => '300' ) ) );

check( 'source_ids() lists all three, without the wrapper itself', array( 301, 302, 303 ) === Checker::source_ids( $wrapper ) );
$live = Checker::live_sources( $wrapper );
check( 'live_sources() keeps only the processing one', 1 === count( $live ) && 301 === $live[0]->get_id() );
check( 'live_sources() of a plain order is empty', array() === Checker::live_sources( $s301 ) );

$result = Checker::check_order( $wrapper, true, $client );
check( 'wrapper: April 4 (36°F) → ups_store_cold with a draft', 'ups_store_cold' === $result['action'] && is_array( $result['email'] ) );
check( 'sources_noted counts only the live source', 1 === $result['sources_noted'] );
check( '301 carries the note, the meta and a draft addressed with its own number', '2026-04-04' === $s301->get_meta( Checker::META_CHECKED_FOR ) && 1 === count( $s301->notes() ) && 'Your FisHotel Order #301 — Weather Shipping Change' === $s301->get_meta( Checker::META_EMAIL_DRAFT )['subject'] );
check( '302 (refunded) got nothing', 0 === $s302->saves && 0 === count( $s302->notes() ) && ! $s302->has_meta( Checker::META_ACTION ) );
check( '303 (cancelled) got nothing', 0 === $s303->saves && 0 === count( $s303->notes() ) && ! $s303->has_meta( Checker::META_ACTION ) );

$settings                 = Settings::get();
$settings['note_sources'] = false;
$GLOBALS['fh_options'][ Weather::OPTION_KEY ] = $settings;
$result = Checker::check_order( $wrapper, true, $client );
check( 'with note_sources off nothing fans out', 0 === $result['sources_noted'] && 1 === count( $s301->notes() ) );
unset( $GLOBALS['fh_options'][ Weather::OPTION_KEY ] );

echo "6. Cron::run() honours the toggle; the next run is armed before the scan\n";
fh_reset_orders();
$GLOBALS['fh_cron'] = array();
unset( $GLOBALS['fh_options'][ Weather::LAST_RUN_OPTION ] );

$GLOBALS['fh_options'][ Weather::OPTION_KEY ] = array( 'enabled' => false );
Cron::run();
check( 'disabled: Cron::run() neither scans nor arms', false === get_option( Weather::LAST_RUN_OPTION ) && false === wp_next_scheduled( Cron::HOOK ) );

$GLOBALS['fh_options'][ Weather::OPTION_KEY ] = array( 'enabled' => true );
Cron::run();
check( 'enabled: Cron::run() scans and arms the next run in the future', is_array( get_option( Weather::LAST_RUN_OPTION ) ) && wp_next_scheduled( Cron::HOOK ) > time() );
check( 'exactly one pending event', 1 === count( $GLOBALS['fh_cron'] ) );

$GLOBALS['fh_cron'] = array();
$poison             = fh_register_order( new WC_Order( 400, 'processing', array( Checker::META_DELIVERY_DATE => '2026-04-01' ) ) );
$poison->poison     = true;
$threw              = false;
try {
	Cron::run();
} catch ( \RuntimeException $e ) {
	$threw = true;
}
check( 'a scan that dies mid-way', $threw );
check( '…had already armed tomorrow\'s run', false !== wp_next_scheduled( Cron::HOOK ) );

fh_finish();
