<?php
/**
 * US dates in the Clean Room — what a person reads is 11-27-26, what is
 * stored and sent stays 2026-11-27.
 *
 *   - no date is typed any more: the Stays tab's "Cleared…" and "Sale
 *     date…" open the pop-up calendar (no prompt() asks for a date, no
 *     screen says YYYY-MM-DD), and the words of that calendar;
 *   - the phone's acts (Front_Desk::perform): the toast reads 11-27-26,
 *     the event note keeps "sale_ready:2026-…", "cleared:2026-…",
 *     "checkin:2026-…", and the server takes the stored form only — a
 *     US-style date in a payload is refused, never guessed at;
 *   - the Stays tab as it renders: the cells read 11-27-26, every
 *     value="", max="" and data-* date stays YYYY-MM-DD;
 *   - the phone's screen payload, the stays CSV and the XLSX sheet keep
 *     YYYY-MM-DD;
 *   - the sentences: Events tab, sale words, the Fish orders cell, the
 *     temporary tank, the hold note, the date-time label (site time,
 *     America/Chicago in these tests) near midnight and across the
 *     daylight-saving changes.
 *
 * Every fish, name and date here is invented.
 *
 * Run from the plugin root:  php includes/sections/clean-room/tests/us-dates-test.php
 */

require_once __DIR__ . '/wp-stubs.php';

use FisHotel\Misc\Dates;
use FisHotel\Misc\Sections\Clean_Room\Admin;
use FisHotel\Misc\Sections\Clean_Room\Admin_No_Product;
use FisHotel\Misc\Sections\Clean_Room\Admin_Workbook;
use FisHotel\Misc\Sections\Clean_Room\Board_Lines;
use FisHotel\Misc\Sections\Clean_Room\Clean_Room;
use FisHotel\Misc\Sections\Clean_Room\Front_Desk;
use FisHotel\Misc\Sections\Clean_Room\Replay;
use FisHotel\Misc\Sections\Clean_Room\Reserve_Shop;
use FisHotel\Misc\Sections\Clean_Room\Settings;
use FisHotel\Misc\Sections\Clean_Room\Sizes;
use FisHotel\Misc\Sections\Clean_Room\Temp_Tanks;
use FisHotel\Misc\Sections\Clean_Room\Tests\Fake_Store;

// The template helpers the views echo with (wp-stubs.php has the returning ones).
if ( ! function_exists( 'esc_html_e' ) ) {
	function esc_html_e( $text, $domain = null ) {
		echo esc_html( $text );
	}
}
if ( ! function_exists( 'esc_attr_e' ) ) {
	function esc_attr_e( $text, $domain = null ) {
		echo esc_attr( $text );
	}
}
function fh_usd_attribute( $name, $a, $b, $echo ) {
	$out = (string) $a === (string) $b ? ' ' . $name . '="' . $name . '"' : '';
	if ( $echo ) {
		echo $out; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- a fixed attribute.
	}
	return $out;
}
if ( ! function_exists( 'selected' ) ) {
	function selected( $a, $b = true, $echo = true ) {
		return fh_usd_attribute( 'selected', $a, $b, $echo );
	}
}
if ( ! function_exists( 'checked' ) ) {
	function checked( $a, $b = true, $echo = true ) {
		return fh_usd_attribute( 'checked', $a, $b, $echo );
	}
}
if ( ! function_exists( 'disabled' ) ) {
	function disabled( $a, $b = true, $echo = true ) {
		return fh_usd_attribute( 'disabled', $a, $b, $echo );
	}
}

$GLOBALS['fh_current_user'] = 7;
$GLOBALS['fh_user_caps']    = array( 'manage_woocommerce' => array( 7, 8 ) );

$today    = Replay::date_in_zone( time(), wp_timezone() ); // The acts stamp events with the real clock.
$settings = Settings::get();
$store    = new Fake_Store( array( 12 => 'Blue Test Tang', 13 => 'Mock Clownfish' ) );
$iso      = '/^\d{4}-\d{2}-\d{2}$/';
$us       = '/^\d{2}-\d{2}-\d{2}$/';
$section  = dirname( __DIR__ );

function fh_usd_act( Fake_Store $store, $tank_number, $act, array $raw ) {
	global $settings, $today;
	return Front_Desk::perform(
		$act,
		array(
			'store'    => $store,
			'settings' => $settings,
			'today'    => $today,
			'tank'     => $store->tank_by_number( $tank_number ),
			'base'     => array(
				'actor_id' => 8,
				'source'   => 'qr',
			),
		),
		$raw
	);
}

echo "1. No date is typed any more\n";
$js    = (string) file_get_contents( $section . '/js/clean-room-admin.js' );
$code  = (string) preg_replace( '#^\s*(//|\*|/\*).*$#m', '', $js ); // Without its comment lines.
$admin = (string) file_get_contents( $section . '/class-admin.php' );
check( 'no prompt() in the Stays tab asks for a date', 0 === preg_match( '/prompt\([^\n]*(date|YYYY)/i', $code ) );
check( 'the Cleared… and Sale date… buttons open the pop-up calendar instead', false !== strpos( $code, "op === 'clear' || op === 'ready'" ) && false !== strpos( $code, 'dates.ask(ask)' ) );
check( 'what it saves is posted as it always was: fields.date, the date or nothing', false !== strpos( $code, 'fields.date = ymd;' ) && false !== strpos( $code, "fields.date = '';" ) );
check( 'no screen string says YYYY-MM-DD', 0 === preg_match( "/__\(\s*'[^']*YYYY/", $admin ) && 0 === preg_match( "/__\(\s*'[^']*YYYY/", (string) file_get_contents( $section . '/class-front-desk.php' ) ) );
check( 'the page script loads the plugin\'s dates before it', false !== strpos( $admin, "array( 'jquery', Dates::SCRIPT )" ) && false !== strpos( $admin, 'Dates::register_script();' ) );
check( 'the phone\'s page inlines the plugin\'s dates before its own script', 1 === preg_match( "#admin/js/fishotel-dates\.js'.*js/dose-math\.js'.*js/front-desk\.js'#s", (string) file_get_contents( $section . '/class-front-desk.php' ) ) );
$words = Admin::stay_date_words();
check_same( 'the words of the pop-up calendar', array( 'clearedTitle', 'clearedHelp', 'clearedNone', 'saleTitle', 'saleHelpBatch', 'saleHelpNone', 'saleUseBatch', 'saleNone' ), array_keys( $words ) );
check( '… none of them names a way to type a date', 0 === preg_match( '/YYYY|MM-DD|yyyy|Leave empty/i', implode( ' ', $words ) ) );
check( '… "Not cleared" is the "no date" of Cleared…, "Use the batch date (…)" / "No date" of Sale date…', 'Not cleared' === $words['clearedNone'] && 'Use the batch date (%s)' === $words['saleUseBatch'] && 'No date' === $words['saleNone'] );
check( '… the helpful sentence stayed, reworded', 'Pick this group\'s own sale date, or use the batch date (%s).' === $words['saleHelpBatch'] && false !== strpos( $words['saleHelpNone'], 'the batch has no date yet' ) );

echo "2. The server takes the stored form only\n";
check_same( 'a US-style date in a payload is not a date to the server', false, Replay::is_date( '12-04-26' ) );
check_same( 'own_sale_date_check: 12-04-26 and 12/04/2026 are invalid, 2026-12-04 is ok', array( 'invalid', 'invalid', 'ok' ), array( Replay::own_sale_date_check( '12-04-26', null, '2026-11-27' ), Replay::own_sale_date_check( '12/04/2026', null, '2026-11-27' ), Replay::own_sale_date_check( '2026-12-04', null, '2026-11-27' ) ) );
check_same( '… nothing ("no own date") on a group that has none: "same" — nothing is written', 'same', Replay::own_sale_date_check( '', null, '2026-11-27' ) );

echo "3. The phone's acts: the toast reads 11-27-26, the note keeps the stored date\n";
fh_usd_act( $store, 521, 'arrival', array( 'product_id' => 12, 'qty' => 4, 'date' => Replay::add_days( $today, -20 ) ) );
fh_usd_act( $store, 521, 'arrival', array( 'product_id' => 13, 'qty' => 2, 'date' => Replay::add_days( $today, -9 ) ) );
$sale = Replay::add_days( $today, 5 );
$r    = fh_usd_act( $store, 521, 'ready', array( 'stay_id' => 1, 'date' => $sale ) );
check_same( 'a sale date picked: the toast', 'Blue Test Tang on sale ' . Dates::us( $sale ), $r['message'] ?? '' );
check_same( '… the note event', 'sale_ready:' . $sale, $store->last_event()['note'] );
check( '… and the toast carries no year-first date', 0 === preg_match( '/\d{4}-\d{2}-\d{2}/', (string) ( $r['message'] ?? '' ) ) && 1 === preg_match( '/\d{2}-\d{2}-\d{2}$/', (string) ( $r['message'] ?? '' ) ) );
$r = fh_usd_act( $store, 521, 'ready', array( 'stay_id' => 1, 'date' => Dates::us( $sale ) ) );
check_same( 'the same date sent US-style is refused — never guessed at', 'That is not a date.', $r['error'] ?? '' );
check_same( '… and nothing was written', 'sale_ready:' . $sale, $store->last_event()['note'] );
$r = fh_usd_act( $store, 521, 'ready', array( 'stay_id' => 1, 'date' => '1999-12-31' ) );
check_same( 'a date outside the range: the range reads US-style', 'Pick a sale date from ' . Dates::us( Replay::SALE_DATE_MIN ) . ' to ' . Dates::us( Replay::sale_date_max( $today ) ) . '.', $r['error'] ?? '' );
$cleared = Replay::add_days( $today, -2 );
$r       = fh_usd_act( $store, 521, 'clear', array( 'stay_id' => 1, 'date' => $cleared ) );
check_same( 'a cleared date picked: the toast', 'Blue Test Tang cleared ' . Dates::us( $cleared ), $r['message'] ?? '' );
check_same( '… the note event', 'cleared:' . $cleared, $store->last_event()['note'] );
check_same( '… the stay', $cleared, $store->stay( 1, $today, $settings )['cleared_date'] );
$checkin = Replay::add_days( $today, -12 );
$r       = fh_usd_act( $store, 521, 'checkin', array( 'stay_id' => 2, 'date' => $checkin ) );
check_same( 'a check-in date picked: the toast', 'Mock Clownfish checked in ' . Dates::us( $checkin ), $r['message'] ?? '' );
check_same( '… the note event', 'checkin:' . $checkin, $store->last_event()['note'] );
$r = fh_usd_act( $store, 521, 'ready', array( 'stay_id' => 1, 'date' => '' ) );
check_same( '"No date" (an empty date, as before): the own date is removed', 'sale_ready:', $store->last_event()['note'] );
check_same( '… and the toast says so', 'Blue Test Tang: no sale date now — set one on its fish order', $r['message'] ?? '' );
check_same( 'back to the batch date: the toast reads it US-style', 'Blue Test Tang back to the batch date (10-25-26)', Front_Desk::ready_message( 'Blue Test Tang', '', array( 'batch_sale_date' => '2026-10-25' ) ) );
fh_usd_act( $store, 521, 'ready', array( 'stay_id' => 1, 'date' => $sale ) );

echo "4. The phone's screen payload keeps YYYY-MM-DD\n";
$stay1 = $store->stay( 1, $today, $settings );
$stay2 = $store->stay( 2, $today, $settings );
$occ   = Front_Desk::occupant( $stay1 );
check( 'checkinDate, clearedDate, saleReadyDate, saleReadyManual', 1 === preg_match( $iso, (string) $occ['checkinDate'] ) && $cleared === $occ['clearedDate'] && $sale === $occ['saleReadyDate'] && $sale === $occ['saleReadyManual'] );
$occ2 = Front_Desk::occupant( $stay2 );
check( 'the suggestion too', 1 === preg_match( $iso, (string) ( $occ2['saleSuggested'] ?? '' ) ), json_encode( $occ2 ) );
check( 'the phone has its "Use the batch date" / "No date" words', 'Use the batch date (%s)' === Front_Desk::i18n()['useBatchDate'] && 'No date' === Front_Desk::i18n()['noOwnDate'] );

echo "5. The Stays tab as it renders\n";
$tab            = 'stays';
$can_settings   = false;
$tables_ok      = true;
$filters        = Admin::filters_from_request( array() );
$stays          = Admin::narrow_stays( $store->open_stays( $filters, $today, $settings ), $filters );
$closed_stays   = array();
$show_closed    = false;
$csv_url        = 'https://example.test/csv';
$xlsx_url       = 'https://example.test/xlsx';
$flash          = '';
$sizes          = Sizes::standard( $store, $settings )['sizes'];
$fish_tanks     = Admin::existing_tanks( $store->tanks( false ) );
$picks          = array( 'suppliers' => array(), 'origins' => array() );
$product_states = Admin_No_Product::stay_states( $store, $stays );
ob_start();
include FISHOTEL_MISC_PATH . 'includes/sections/clean-room/views/stays.php';
$html = (string) ob_get_clean();
$row  = static function ( $id ) use ( $html ) {
	return 1 === preg_match( '#<tr id="fh-cr-stay-' . (int) $id . '".*?</tr>#s', $html, $m ) ? $m[0] : '';
};
$row1 = $row( 1 );
$row2 = $row( 2 );
check( 'both rows are drawn', '' !== $row1 && '' !== $row2 );
check( 'the Check-in cell reads 11-27-26', false !== strpos( $row1, '<td class="fh-cr-date">' . Dates::us( $stay1['checkin_date'] ) . '</td>' ) && false !== strpos( $row2, '<td class="fh-cr-date">' . Dates::us( $checkin ) . '</td>' ) );
check( 'the Cleared cell reads 11-27-26', false !== strpos( $row1, '<td class="fh-cr-date">' . Dates::us( $cleared ) . '</td>' ) );
check( 'a date cell never breaks at a hyphen (white-space: nowrap)', 1 === preg_match( '/\.fh-cr-date\s*\{\s*white-space:\s*nowrap;/', (string) file_get_contents( $section . '/css/clean-room-admin.css' ) ) );
check( 'the On sale cell reads 11-27-26', 1 === preg_match( '#<td>\s*<span class="fh-cr-date">' . preg_quote( Dates::us( $sale ), '#' ) . '</span>\s*<span class="fh-cr-sub">own date</span>#', $row1 ) );
check( 'a group with no date says so, with the suggestion US-style', false !== strpos( $row2, 'Sale date not set' ) && false !== strpos( $row2, 'suggested ' . Dates::us( $stay2['sale_ready_suggested'] ) ) );
$text = trim( (string) preg_replace( '/\s+/', ' ', strip_tags( (string) preg_replace( '#<(script|style)\b.*?</\1>#s', '', $row1 . $row2 ) ) ) );
check( 'the note under the table tells of the calendar, not of typing', false !== strpos( $html, 'Cleared… opens a calendar for the cleared date (&quot;Not cleared&quot; removes it).' ) && false === strpos( $html, 'with nothing typed' ) && false === strpos( $html, 'empty removes it' ) );
check( 'nothing a person reads in the rows is a year-first date', 0 === preg_match( '/\d{4}-\d{2}-\d{2}/', $text ), $text );
check( 'the Cleared… button carries the stored cleared date for the calendar', 1 === preg_match( '#data-op="clear" aria-haspopup="dialog" data-cleared="' . $cleared . '"#', $row1 ) && 1 === preg_match( '#data-op="clear" aria-haspopup="dialog" data-cleared=""#', $row2 ) );
check( 'the Sale date… button carries the stored own date and batch date', 1 === preg_match( '#data-op="ready" aria-haspopup="dialog" data-default="' . $sale . '" data-batch=""#', $row1 ) );
check( '… on a group with no date: the stored suggestion', 1 === preg_match( '#data-op="ready" aria-haspopup="dialog" data-default="' . $stay2['sale_ready_suggested'] . '"#', $row2 ) );
check( 'the Edit row\'s Check-in calendar keeps value and max in the stored form', 1 === preg_match( '#<input type="date" class="fh-cr-input" name="checkin_date" value="' . $stay1['checkin_date'] . '" max="' . $today . '">#', $html ) );
check( 'every date attribute of the tab is YYYY-MM-DD or empty', 0 === preg_match( '/(?:value|min|max|data-default|data-batch|data-cleared|data-date)="\d{2}-\d{2}-\d{2}"/', $html ) );

echo "6. Exports keep YYYY-MM-DD\n";
$csv = Admin::csv_row( $stay1 );
check_same( 'the stays CSV: check-in, cleared, on sale', array( $stay1['checkin_date'], $cleared, $sale ), array( $csv[5], $csv[8], $csv[9] ) );
check( '… each the stored form', 1 === preg_match( $iso, $csv[5] ) && 1 === preg_match( $iso, $csv[8] ) && 1 === preg_match( $iso, $csv[9] ) );
$xrows = Admin_Workbook::stays_rows( array( $stay1 ) );
check( 'the XLSX stays sheet the same', in_array( $stay1['checkin_date'], $xrows[1], true ) && in_array( $cleared, $xrows[1], true ) && in_array( $sale, $xrows[1], true ) && ! in_array( Dates::us( $sale ), $xrows[1], true ), json_encode( $xrows[1] ) );

echo "7. The sentences a person reads\n";
$words = Admin::sale_words( $stay1 );
check_same( 'sale_words: text for reading, date for the calendar', array( 'on sale ' . Dates::us( $sale ), $sale ), array( $words['text'], $words['date'] ) );
check_same( 'checkin_text', Dates::us( $stay1['checkin_date'] ), Admin::checkin_text( $stay1 ) );
check_same( 'the Events tab: a sale date', 'own sale date set to 05-20-26', Admin::event_text( array( 'type' => 'note', 'note' => 'sale_ready:2026-05-20' ) ) );
check_same( 'the Events tab: a cleared date', 'cleared date set to 04-10-26', Admin::event_text( array( 'type' => 'clear', 'note' => 'cleared:2026-04-10' ) ) );
check_same( 'the Events tab: a check-in date', 'check-in date set to 04-01-26', Admin::event_text( array( 'type' => 'note', 'note' => 'checkin:2026-04-01' ) ) );
check_same( 'the Events tab: a note that only looks like one is left as typed', 'note: seen on 2026-04-01', Admin::event_text( array( 'type' => 'note', 'note' => 'seen on 2026-04-01' ) ) );
$cell = Admin_Workbook::on_sale_cell( array( 'sale_date' => '2026-12-04' ), array() );
check_same( 'the Fish orders "On sale" cell: text for reading, date stored', array( '12-04-26', '2026-12-04' ), array( $cell['text'], $cell['date'] ) );
check_same( 'the line under the batch calendar', 'Applies to 1 group still in tanks — for sale since 10-15-26.', Admin_Workbook::batch_sale_status( array( 'applies' => 1, 'in_spa' => 0, 'open' => 1, 'date' => '2026-10-15' ), '2026-11-27' ) );
check_same( 'a Board line', 'on sale 12-12-26 – 12-19-26', Board_Lines::sale_text( array( 'state' => 'cleared', 'sale_dates' => array( '2026-12-12', '2026-12-19' ), 'no_sale' => 0 ) ) );
check_same( 'a temporary tank that starts later', 'starts 12-01-26', Temp_Tanks::day_text( array( 'start_date' => '2026-12-01' ), '2026-11-27' ) );
$note = Reserve_Shop::hold_note( array( 'at' => strtotime( '2026-11-21 05:30:00 UTC' ), 'who' => 'Ann B.' ), array( 'species' => 'Blue Test Tang', 'size_label' => 'Medium', 'tank_number' => 121 ), 2, 'taken by this order' );
check_same( 'the order note of a hold: the site\'s day (23:30 the evening before in Chicago), month first', 'Clean Room: 2 × Blue Test Tang Medium in tank 121, held by hand for Ann B. since 11-20-26 — taken by this order.', $note );
check_same( '… a hold with no time: "?"', 'Clean Room: 1 × fish in tank 0, held by hand for Ann B. since ? — x.', Reserve_Shop::hold_note( array( 'who' => 'Ann B.' ), null, 1, 'x' ) );

echo "8. A date and time (site time: America/Chicago)\n";
check_same( 'a morning', '11-27-26 09:14', Admin::site_time( '2026-11-27 15:14:03' ) );
check_same( 'one minute before midnight is still that day', '11-27-26 23:59', Admin::site_time( '2026-11-28 05:59:00' ) );
check_same( 'midnight is the next day', '11-28-26 00:00', Admin::site_time( '2026-11-28 06:00:00' ) );
check_same( 'the clocks go forward: 01:59, then 03:00', array( '03-08-26 01:59', '03-08-26 03:00' ), array( Admin::site_time( '2026-03-08 07:59:00' ), Admin::site_time( '2026-03-08 08:00:00' ) ) );
check_same( 'the clocks go back: 01:30 twice, the same day', array( '11-01-26 01:30', '11-01-26 01:30' ), array( Admin::site_time( '2026-11-01 06:30:00' ), Admin::site_time( '2026-11-01 07:30:00' ) ) );
check_same( 'not a time: shown as it is', 'never', Admin::site_time( 'never' ) );

echo "9. Today, on the Board and the To do list\n";
check( 'both print the site date through the helper', false !== strpos( (string) file_get_contents( $section . '/views/board.php' ), 'esc_html( Dates::us( $today ) )' ) && false !== strpos( (string) file_get_contents( $section . '/views/todo.php' ), 'esc_html( Dates::us( $today ) )' ) );
check( 'today itself stays the stored form for every comparison', 1 === preg_match( $iso, Clean_Room::today() ) && 1 === preg_match( $us, Dates::us( Clean_Room::today() ) ) );

fh_finish();
