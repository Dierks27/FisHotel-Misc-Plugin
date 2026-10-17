<?php
/**
 * Model tests — the pure functions behind the Today page and widget.
 * No WordPress, no database.
 *
 * Run from the plugin root:
 *   php includes/sections/today/tests/model-test.php
 *
 * @package FisHotel\Misc\Sections\Today
 */

require_once __DIR__ . '/wp-stubs.php';

use FisHotel\Misc\Sections\Today\Model;
use FisHotel\Misc\Sections\Today\Settings;
use FisHotel\Misc\Sections\Weather\Checker;

$chicago = new DateTimeZone( 'America/Chicago' );

/**
 * A destination array like the Collector builds.
 */
function fh_dest( $id, array $weather = array(), $invoice = null, $tracking = null, array $extra = array() ) {
	$base = array(
		'order_id'       => (int) $id,
		'number'         => (string) $id,
		'label'          => 'Order #' . $id,
		'edit_url'       => 'https://example.test/wp-admin/post.php?post=' . $id,
		'is_fulfillment' => false,
		'sources'        => array(),
		'customer'       => 'Dana Reed',
		'place'          => 'Carrollton, VA',
		'status'         => 'processing',
		'status_label'   => 'Processing',
		'fish'           => 3,
		'dry'            => 1,
		'weather'        => array_merge(
			array(
				'status'      => 'not_checked',
				'action'      => '',
				'at_line'     => false,
				'high'        => null,
				'low'         => null,
				'summary'     => '',
				'checked_for' => '',
			),
			$weather
		),
		'invoice'        => $invoice,
		'tracking'       => $tracking,
		'has_note'       => false,
	);
	return array_merge( $base, $extra );
}

function fh_checked( $action, $at_line = false, $high = 70 ) {
	return array(
		'status'  => 'checked',
		'action'  => $action,
		'at_line' => $at_line,
		'high'    => $high,
	);
}

function fh_numbers( array $list ) {
	return implode( ',', array_map( static function ( $d ) { return $d['number']; }, $list ) );
}

echo "1. window()\n";
check( 'three days across the March DST change', array( '2026-03-08', '2026-03-09', '2026-03-10' ) === Model::window( '2026-03-07', 3 ), json_encode( Model::window( '2026-03-07', 3 ) ) );
check( 'two days across the November DST change', array( '2026-11-01', '2026-11-02' ) === Model::window( '2026-10-31', 2 ) );
check( 'starts tomorrow, never today', '2025-10-14' === Model::window( '2025-10-13', 21 )[0] && 21 === count( Model::window( '2025-10-13', 21 ) ) );
check( 'ends at today + lookahead', '2025-11-03' === Model::window( '2025-10-13', 21 )[20] );
check( 'crosses a year end', array( '2026-01-01', '2026-01-02' ) === Model::window( '2025-12-31', 2 ) );
check( 'lookahead is clamped to 1–60', 1 === count( Model::window( '2025-10-13', 0 ) ) && 60 === count( Model::window( '2025-10-13', 500 ) ) );
check( 'a bad today yields nothing', array() === Model::window( 'yesterday', 3 ) && array() === Model::window( '2025-02-30', 3 ) );

echo "2. ship_plan()\n";
$plan = Model::ship_plan( '2025-10-13', array( '2025-10-16', '2025-10-14', '2025-10-14' ) );
check( 'a ship day: tomorrow has orders', true === $plan['ships_today'] && '2025-10-14' === $plan['delivery'] && '2025-10-13' === $plan['ship'] );
check( '…and "next" is the following date with orders, ship = the day before', array( 'delivery' => '2025-10-16', 'ship' => '2025-10-15' ) === $plan['next'], json_encode( $plan ) );

$plan = Model::ship_plan( '2025-10-12', array( '2025-10-14', '2025-10-15' ) );
check( 'an idle day: nothing ships today', false === $plan['ships_today'] && null === $plan['delivery'] && null === $plan['ship'] );
check( '…"next" is the first date with orders', array( 'delivery' => '2025-10-14', 'ship' => '2025-10-13' ) === $plan['next'], json_encode( $plan ) );

$plan = Model::ship_plan( '2025-10-13', array() );
check( 'no orders: everything null', false === $plan['ships_today'] && null === $plan['delivery'] && null === $plan['ship'] && null === $plan['next'] );

$plan = Model::ship_plan( '2026-10-31', array( '2026-11-01' ) );
check( 'today the last day of a month: tomorrow is next month', true === $plan['ships_today'] && '2026-11-01' === $plan['delivery'] && '2026-10-31' === $plan['ship'] && null === $plan['next'] );

$plan = Model::ship_plan( '2026-03-08', array( '2026-03-09' ) );
check( 'ship day on the DST change day', true === $plan['ships_today'] && '2026-03-08' === $plan['ship'] );

$plan = Model::ship_plan( '2025-10-13', array( '2025-10-13', '2025-10-10', 'nope', '2025-10-20' ) );
check( 'today, past and malformed dates are ignored', false === $plan['ships_today'] && array( 'delivery' => '2025-10-20', 'ship' => '2025-10-19' ) === $plan['next'] );
check( 'a bad today is handled', false === Model::ship_plan( '', array( '2025-10-14' ) )['ships_today'] );

echo "3. date_label() and ship_date_for() in America/Chicago\n";
check( 'Tue, Oct 14', 'Tue, Oct 14' === Model::date_label( '2025-10-14', $chicago ) );
check( 'other format', 'Mon Oct 13' === Model::date_label( '2025-10-13', $chicago, 'D M j' ) );
check( 'zone as a string works too', 'Tue, Oct 14' === Model::date_label( '2025-10-14', 'America/Chicago' ) );
check( 'not a date → input unchanged', 'soon' === Model::date_label( 'soon', $chicago ) );
check( 'the day after the March change: Mar 9 ships Mar 8', '2026-03-08' === Model::ship_date_for( '2026-03-09', $chicago ) );
check( 'the March change day itself ships the day before', '2026-03-07' === Model::ship_date_for( '2026-03-08', $chicago ) );
check( 'the day after the November change: Nov 2 ships Nov 1', '2026-11-01' === Model::ship_date_for( '2026-11-02', $chicago ) );
check( 'the November change day itself ships Oct 31', '2026-10-31' === Model::ship_date_for( '2026-11-01', $chicago ) );
check( 'first of a month ships the last of the previous', '2025-09-30' === Model::ship_date_for( '2025-10-01', $chicago ) );
check( 'first of the year', '2025-12-31' === Model::ship_date_for( '2026-01-01', $chicago ) );
check( 'invalid → empty', '' === Model::ship_date_for( '2025-13-01', $chicago ) && '' === Model::ship_date_for( '', $chicago ) );
check( 'no zone → UTC, same calendar answer', '2026-03-08' === Model::ship_date_for( '2026-03-09' ) );

echo "4. weather_state()\n";
$meta = array(
	Checker::META_CHECKED_FOR => '2025-10-14',
	Checker::META_ACTION      => 'heat_pack',
	Checker::META_HIGH        => '55',
	Checker::META_LOW         => '40',
	Checker::META_SUMMARY     => 'High 55°F / Low 40°F — Cloudy',
	Checker::META_AT_LINE     => '',
);
$state = Model::weather_state( $meta, '2025-10-14' );
check( 'checked for the date → checked with the action and numbers', 'checked' === $state['status'] && 'heat_pack' === $state['action'] && 55 === $state['high'] && 40 === $state['low'] && false === $state['at_line'] );
check( 'checked for another date → not checked', 'not_checked' === Model::weather_state( $meta, '2025-10-15' )['status'] );
$meta[ Checker::META_AT_LINE ] = '1';
check( 'at the line flag', true === Model::weather_state( $meta, '2025-10-14' )['at_line'] );
$un = array(
	Checker::META_ACTION          => 'unavailable',
	Checker::META_UNAVAILABLE_FOR => '2025-10-14',
	Checker::META_SUMMARY         => 'api.weather.gov returned HTTP 503',
);
check( 'unavailable for the date', 'unavailable' === Model::weather_state( $un, '2025-10-14' )['status'] );
check( 'unavailable for another date → not checked', 'not_checked' === Model::weather_state( $un, '2025-10-15' )['status'] );
check( 'no meta → not checked', 'not_checked' === Model::weather_state( array(), '2025-10-14' )['status'] && '' === Model::weather_state( array(), '2025-10-14' )['action'] );
check( 'no note → no alerts', array() === $state['alerts'] );
$meta[ Checker::META_NOTE ] = 'Weather for Tuesday, October 14 at 23314 (Carrollton, VA): High 55°F / Low 40°F — Cloudy. Alerts: Flood Watch, flood watch, Wind Advisory. Heat pack. Next day: High 60°F / Low 45°F.';
check( 'alerts parsed from the note, upper-cased and de-duplicated', array( 'FLOOD WATCH', 'WIND ADVISORY' ) === Model::weather_state( $meta, '2025-10-14' )['alerts'], json_encode( Model::weather_state( $meta, '2025-10-14' )['alerts'] ) );
check( 'alerts only for a checked result (stale note for another date → none)', array() === Model::weather_state( $meta, '2025-10-15' )['alerts'] );
check( 'parse_alerts(): no sentence, empty, garbage', array() === Model::parse_alerts( 'High 55°F — Cloudy. Heat pack.' ) && array() === Model::parse_alerts( '' ) && array() === Model::parse_alerts( 'Alerts:' ) );
check( 'parse_alerts(): a single alert at the end of the note', array( 'THUNDERSTORM WARNING' ) === Model::parse_alerts( '— Rain. Alerts: Thunderstorm Warning.' ) );
unset( $meta[ Checker::META_NOTE ] );

echo "5. badge()\n";
check( 'heat pack', array( 'key' => 'heat_pack', 'label' => 'HEAT PACK' ) === Model::badge( fh_checked( 'heat_pack' ) ) );
check( 'nothing', 'NOTHING' === Model::badge( fh_checked( 'none' ) )['label'] );
check( 'cooling', 'COOLING' === Model::badge( fh_checked( 'cooling' ) )['label'] );
check( 'UPS Store merges cold + hot', 'ups_store' === Model::badge_key( fh_checked( 'ups_store_cold' ) ) && 'ups_store' === Model::badge_key( fh_checked( 'ups_store_hot' ) ) );
check( 'HOLD merges cold + hot', 'hold' === Model::badge_key( fh_checked( 'hold_cold' ) ) && 'HOLD' === Model::badge( fh_checked( 'hold_hot' ) )['label'] );
check( 'at the line wins over a plain band', 'AT THE LINE' === Model::badge( fh_checked( 'heat_pack', true ) )['label'] );
check( 'at the line does not hide a hold', 'hold' === Model::badge_key( fh_checked( 'hold_cold', true ) ) );
check( 'unavailable', 'UNAVAILABLE' === Model::badge( array( 'status' => 'unavailable', 'action' => 'unavailable' ) )['label'] );
check( 'not checked', 'NOT CHECKED' === Model::badge( array() )['label'] );
check( 'an unknown action reads as not checked', 'not_checked' === Model::badge_key( fh_checked( 'bogus' ) ) );

echo "6. checklist() honours the show_* flags\n";
$dest = fh_dest( 1, fh_checked( 'cooling' ), array( 'done' => false ), array( 'done' => true, 'count' => 2, 'status' => 'in_transit', 'status_label' => 'In Transit' ) );
$all  = array( 'weather' => true, 'invoice' => true, 'tracking' => true );
$list = Model::checklist( $dest, $all );
check( 'three items with every flag on', 3 === count( $list ) && 'weather' === $list[0]['key'] && 'invoice' === $list[1]['key'] && 'tracking' === $list[2]['key'] );
check( 'weather done carries the badge text', true === $list[0]['done'] && 'COOLING' === $list[0]['note'] );
check( 'invoice open', false === $list[1]['done'] && 'no invoice PDF yet' === $list[1]['note'] );
check( 'tracking done: count · status', true === $list[2]['done'] && '2 · In Transit' === $list[2]['note'] );
check( 'weather off → two items', 'invoice,tracking' === implode( ',', array_column( Model::checklist( $dest, array( 'weather' => false, 'invoice' => true, 'tracking' => true ) ), 'key' ) ) );
check( 'invoice off → weather, tracking', 'weather,tracking' === implode( ',', array_column( Model::checklist( $dest, array( 'weather' => true, 'invoice' => false, 'tracking' => true ) ), 'key' ) ) );
check( 'tracking off → weather, invoice', 'weather,invoice' === implode( ',', array_column( Model::checklist( $dest, array( 'weather' => true, 'invoice' => true, 'tracking' => false ) ), 'key' ) ) );
check( 'everything off → nothing', array() === Model::checklist( $dest, array() ) );
$list = Model::checklist( fh_dest( 2, array( 'status' => 'unavailable', 'action' => 'unavailable' ), null, null ), $all );
check( 'unavailable weather is open with the check-manually note', false === $list[0]['done'] && 'unavailable — check manually' === $list[0]['note'] );
check( 'not-checked weather is open', 'not checked' === Model::checklist( fh_dest( 3 ), $all )[0]['note'] && false === Model::checklist( fh_dest( 3 ), $all )[0]['done'] );
check( 'a null invoice / tracking (column hidden) reads as open when the flag is on anyway', false === $list[1]['done'] && false === $list[2]['done'] );

echo "7. chips(), needs_attention() and sort on six destinations incl. a wrapper\n";
$fixture = array(
	fh_dest( 101, fh_checked( 'heat_pack' ), array( 'done' => true ), array( 'done' => true, 'count' => 1, 'status' => 'in_transit', 'status_label' => 'In Transit' ) ), // all done
	fh_dest( 102, fh_checked( 'hold_hot', false, 98 ), array( 'done' => false ), array( 'done' => false, 'count' => 0 ) ),                                                   // hold, missing both
	fh_dest( 103, array( 'status' => 'unavailable', 'action' => 'unavailable' ), array( 'done' => true ), array( 'done' => true, 'count' => 1 ) ),                       // unavailable
	fh_dest( 104, fh_checked( 'ups_store_cold', false, 35 ), array( 'done' => true ), array( 'done' => true, 'count' => 1 ) ),                                             // ups store
	fh_dest( 105, fh_checked( 'none' ), array( 'done' => false ), array( 'done' => true, 'count' => 1 ) ),                                                                // paperwork only
	fh_dest( 200, array(), array( 'done' => true ), array( 'done' => false, 'count' => 1 ), array(                                                                       // wrapper, not checked
		'label'          => 'Order #200 (combined: #201, #202)',
		'is_fulfillment' => true,
		'sources'        => array( '201', '202' ),
		'fish'           => 7,
		'dry'            => 0,
	) ),
);

$chips = Model::chips( $fixture );
check( 'orders = 6 destinations (the wrapper counts once)', 6 === $chips['orders'] );
check( 'fish and dry goods summed (5×3 + 7, 5×1 + 0)', 22 === $chips['fish'] && 5 === $chips['dry'], json_encode( $chips ) );
check( 'needs weather = unavailable + not checked', 2 === $chips['needs_weather'] );
check( 'needs invoice', 2 === $chips['needs_invoice'] );
check( 'needs tracking', 2 === $chips['needs_tracking'] );
check( 'holds / UPS Store / unavailable / at line', 1 === $chips['holds'] && 1 === $chips['ups_store'] && 1 === $chips['unavailable'] && 0 === $chips['at_line'] );
$no_cols = Model::chips( array( fh_dest( 1, fh_checked( 'none' ), null, null ) ) );
check( 'hidden columns (null) are not counted as needs', 0 === $no_cols['needs_invoice'] && 0 === $no_cols['needs_tracking'] );
check( 'empty input', 0 === Model::chips( array() )['orders'] );

$attention = Model::needs_attention( $fixture );
check( 'five destinations need attention (101 is clean)', 5 === count( $attention ) && ! in_array( '101', array_column( $attention, 'number' ), true ) );
check( 'order: hold, UPS Store, unavailable, not checked, then paperwork', '102,104,103,200,105' === fh_numbers( $attention ), fh_numbers( $attention ) );
check( 'the hold entry lists every reason, weather first', array( 'HOLD — do not ship', 'no invoice PDF', 'no tracking' ) === $attention[0]['reasons'] && 'hold' === $attention[0]['kind'] );
check( 'the wrapper carries its combined label', 'Order #200 (combined: #201, #202)' === $attention[3]['label'] && 'not_checked' === $attention[3]['kind'] );
check( 'paperwork-only entry', 'invoice' === $attention[4]['kind'] && array( 'no invoice PDF' ) === $attention[4]['reasons'] );
check( 'edit URL carried through', false !== strpos( $attention[0]['edit_url'], 'post=102' ) );

$sorted = Model::sort_destinations( array_reverse( $fixture ) );
check( 'sort: hold, UPS Store, unavailable, not checked, then the rest by number', '102,104,103,200,101,105' === fh_numbers( $sorted ), fh_numbers( $sorted ) );
$tie = Model::sort_destinations( array( fh_dest( 9, fh_checked( 'none' ) ), fh_dest( 10, fh_checked( 'none' ) ), fh_dest( 100, fh_checked( 'none' ) ) ) );
check( 'order numbers compare numerically (9 < 10 < 100)', '9,10,100' === fh_numbers( $tie ) );
$line = Model::sort_destinations( array( fh_dest( 1, fh_checked( 'heat_pack' ) ), fh_dest( 2, fh_checked( 'cooling', true ) ) ) );
check( 'at the line sorts before a plain band', '2,1' === fh_numbers( $line ) );
check( 'input is not mutated', '101' === $fixture[0]['number'] && 6 === count( $fixture ) );

echo "7b. Active NWS alerts need attention\n";
$alerted = fh_dest( 106, array_merge( fh_checked( 'none', false, 76 ), array( 'alerts' => array( 'FLOOD WATCH' ) ) ), array( 'done' => true ), array( 'done' => true, 'count' => 1 ) );
$att     = Model::needs_attention( array( $alerted ) );
check( 'a NOTHING badge with a Flood Watch is listed, kind alerts, the alert as the reason', 1 === count( $att ) && 'alerts' === $att[0]['kind'] && array( 'FLOOD WATCH' ) === $att[0]['reasons'] && Model::ATTENTION_RANK['alerts'] === $att[0]['rank'] );
$att = Model::needs_attention( array_merge( $fixture, array( $alerted ) ) );
check( 'alerts rank after at the line / UPS Store and before unavailable', '102,104,106,103,200,105' === fh_numbers( $att ), fh_numbers( $att ) );
$hold_alert = fh_dest( 107, array_merge( fh_checked( 'hold_hot', false, 99 ), array( 'alerts' => array( 'EXCESSIVE HEAT WARNING', 'FLOOD WATCH' ) ) ) );
$att        = Model::needs_attention( array( $hold_alert ) );
check( 'a hold with alerts keeps kind hold and lists the alerts after it', 'hold' === $att[0]['kind'] && array( 'HOLD — do not ship', 'EXCESSIVE HEAT WARNING, FLOOD WATCH' ) === $att[0]['reasons'] );
check( 'a stale / absent alerts key is harmless', 0 === count( Model::needs_attention( array( fh_dest( 108, fh_checked( 'none' ) ) ) ) ) );

echo "7c. chips(), needs_attention() and sort honour the Weather toggle\n";
$off = array( 'weather' => false, 'invoice' => true, 'tracking' => true );
$chips_off = Model::chips( $fixture, $off );
check( 'weather chip counts stay 0 with the column off', 0 === $chips_off['needs_weather'] && 0 === $chips_off['holds'] && 0 === $chips_off['ups_store'] && 0 === $chips_off['unavailable'] && 0 === $chips_off['at_line'], json_encode( $chips_off ) );
check( '…while orders / fish / paperwork counts are unchanged', 6 === $chips_off['orders'] && 22 === $chips_off['fish'] && 2 === $chips_off['needs_invoice'] && 2 === $chips_off['needs_tracking'] );
$att_off = Model::needs_attention( array_merge( $fixture, array( $alerted ) ), $off );
check( 'no weather reason (hold, not checked, alerts) with the column off — paperwork only, by number', '102,105,200' === fh_numbers( $att_off ) && array( 'no invoice PDF', 'no tracking' ) === $att_off[0]['reasons'] && 'invoice' === $att_off[0]['kind'], fh_numbers( $att_off ) );
check( 'nothing to do → empty strip, never "weather not checked"', array() === Model::needs_attention( array( fh_dest( 1 ) ), $off ) );
check( 'sort by order number only with the column off', '101,102,103,104,105,200' === fh_numbers( Model::sort_destinations( array_reverse( $fixture ), $off ) ) );
check( 'no flags → weather on (back-compat)', '102,104,103,200,101,105' === fh_numbers( Model::sort_destinations( array_reverse( $fixture ) ) ) && 2 === Model::chips( $fixture )['needs_weather'] );

echo "8. header_line(), date_summary(), order_label()\n";
$chips_by_date = array(
	'2025-10-14' => array( 'orders' => 6, 'fish' => 23 ),
	'2025-10-16' => array( 'orders' => 1, 'fish' => 1 ),
);
$plan = Model::ship_plan( '2025-10-13', array( '2025-10-14', '2025-10-16' ) );
check( 'ship day header', 'Today, Mon Oct 13 — ship day for Tue Oct 14 delivery · 6 orders · 23 fish' === Model::header_line( '2025-10-13', $plan, $chips_by_date, 21, $chicago ), Model::header_line( '2025-10-13', $plan, $chips_by_date, 21, $chicago ) );
$plan = Model::ship_plan( '2025-10-12', array( '2025-10-14', '2025-10-16' ) );
check( 'idle day header', 'Nothing ships today. Next: ship Mon Oct 13 for Tue Oct 14 · 6 orders' === Model::header_line( '2025-10-12', $plan, $chips_by_date, 21, $chicago ), Model::header_line( '2025-10-12', $plan, $chips_by_date, 21, $chicago ) );
check( 'one order is singular', false !== strpos( Model::header_line( '2025-10-15', Model::ship_plan( '2025-10-15', array( '2025-10-16' ) ), $chips_by_date, 21, $chicago ), '· 1 order · 1 fish' ) );
check( 'no orders header names the lookahead', 'Nothing ships today. No open orders deliver in the next 21 days.' === Model::header_line( '2025-10-13', Model::ship_plan( '2025-10-13', array() ), array(), 21, $chicago ) );

$flags = array( 'weather' => true, 'invoice' => true, 'tracking' => true );
$c     = array( 'orders' => 4, 'needs_weather' => 2, 'needs_invoice' => 1, 'needs_tracking' => 0, 'holds' => 1, 'ups_store' => 0 );
check( 'date summary line', 'Wed Oct 15 · 4 orders · 2 need weather · 1 needs invoice · 1 hold' === Model::date_summary( '2025-10-15', $c, $flags, $chicago ), Model::date_summary( '2025-10-15', $c, $flags, $chicago ) );
check( 'hidden columns are left out of the summary', 'Wed Oct 15 · 4 orders · 1 hold' === Model::date_summary( '2025-10-15', $c, array( 'weather' => false, 'invoice' => false ), $chicago ) );
check( 'order label for a wrapper', 'Order #300 (combined: #301, #302)' === Model::order_label( '300', array( '301', '302' ) ) && 'Order #310' === Model::order_label( '310' ) );
check( 'place', 'Carrollton, VA' === Model::place( 'Carrollton', 'VA' ) && 'unknown' === Model::place( '', '' ) );

echo "9. cache_key()\n";
$settings = Settings::get();
$key      = Model::cache_key( '2025-10-13', $settings );
check( 'starts with today', 0 === strpos( $key, '2025-10-13:' ) );
check( 'stable for the same settings', $key === Model::cache_key( '2025-10-13', $settings ) );
check( 'changes with today', $key !== Model::cache_key( '2025-10-14', $settings ) );
$changed = $settings;
$changed['show_tracking'] = false;
check( 'changes when a column toggle changes', $key !== Model::cache_key( '2025-10-13', $changed ) );
$changed = $settings;
$changed['lookahead_days'] = 7;
check( 'changes when the lookahead changes', $key !== Model::cache_key( '2025-10-13', $changed ) );
$changed = $settings;
$changed['statuses'] = array( 'processing' );
check( 'changes when the statuses change', $key !== Model::cache_key( '2025-10-13', $changed ) );
$changed = $settings;
$changed['cache_minutes'] = 60;
check( 'the cache TTL itself does not shape the data, so it is not in the key', $key === Model::cache_key( '2025-10-13', $changed ) );

echo "10. Settings\n";
$defaults = Settings::get();
check( 'defaults (shipped is open so a row survives its own tracking save)', 21 === $defaults['lookahead_days'] && 3 === $defaults['dates_shown'] && array( 'processing', 'on-hold', 'fulfillment', 'shipped' ) === $defaults['statuses'] && $defaults['show_weather'] && $defaults['show_invoice'] && $defaults['show_tracking'] && 5 === $defaults['cache_minutes'] );
$dropped = array();
$saved   = Settings::sanitize( array( 'lookahead_days' => '90', 'dates_shown' => '2', 'cache_minutes' => '0', 'statuses' => array( 'wc-processing', 'bogus', 'processing' ), 'show_weather' => '1' ), $dropped );
check( 'lookahead out of range → default and reported', 21 === $saved['lookahead_days'] && 1 === count( $dropped ) && false !== strpos( $dropped[0], 'lookahead' ) );
check( 'dates shown kept, cache 0 (off) kept', 2 === $saved['dates_shown'] && 0 === $saved['cache_minutes'] );
check( 'statuses: wc- stripped, unknown dropped, deduplicated', array( 'processing' ) === $saved['statuses'] );
check( 'unticked checkboxes are false', true === $saved['show_weather'] && false === $saved['show_invoice'] && false === $saved['show_tracking'] );
$saved = Settings::sanitize( array( 'statuses' => array() ), $dropped );
check( 'no statuses → defaults and reported', array( 'processing', 'on-hold', 'fulfillment', 'shipped' ) === $saved['statuses'] && in_array( 'no statuses ticked (defaults used)', $dropped, true ) );
check( 'clamp', 5 === Settings::clamp( 'x', 0, 10, 5 ) && 5 === Settings::clamp( 11, 0, 10, 5 ) && 7 === Settings::clamp( '7', 0, 10, 5 ) );
update_option( 'fishotel_today_settings', array( 'dates_shown' => 9, 'statuses' => array( 'on-hold' ) ) );
$stored = Settings::get();
check( 'stored values are clamped on read', 3 === $stored['dates_shown'] && array( 'on-hold' ) === $stored['statuses'] && 21 === $stored['lookahead_days'] );
update_option( 'fishotel_today_settings', 'garbage' );
check( 'a corrupt option reads as defaults', 21 === Settings::get()['lookahead_days'] );
update_option( 'fishotel_today_settings', array() );

fh_finish();
