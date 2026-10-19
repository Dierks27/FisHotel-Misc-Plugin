<?php
/**
 * Sale dates — a group goes on sale on a CHOSEN date: its own (a
 * "sale_ready:" note event), else its batch's (the supplier order's
 * sale_date), else none. On the in-memory Fake_Store: the precedence
 * through a real check-in, the boundary (the date itself counts, in the
 * site zone), closed stays never for sale, the "still in the Spa" warning
 * on every reader, the Replay history unchanged apart from the rule, the
 * schema's new column, the product facts with a null date, the Front
 * Desk's words, and the batch calendar's save handler (capability,
 * nonce, valid / invalid / clear, the log entry) with the order page's
 * and the Arrivals list's helpers, and the product page's sale dates
 * saved with the product (only changed stays get an event, no nonce = no
 * work, the capabilities, invalid dates reported after the redirect,
 * "Use the batch date", a failing store never stopping the save).
 *
 * Run from the plugin root:  php includes/sections/clean-room/tests/sale-dates-test.php
 */

require_once __DIR__ . '/wp-stubs.php';

use FisHotel\Misc\Sections\Clean_Room\Admin;
use FisHotel\Misc\Sections\Clean_Room\Admin_Workbook;
use FisHotel\Misc\Sections\Clean_Room\Clean_Room;
use FisHotel\Misc\Sections\Clean_Room\Front_Desk;
use FisHotel\Misc\Sections\Clean_Room\Orders;
use FisHotel\Misc\Sections\Clean_Room\Replay;
use FisHotel\Misc\Sections\Clean_Room\Settings;
use FisHotel\Misc\Sections\Clean_Room\Store;
use FisHotel\Misc\Sections\Clean_Room\Tests\Fake_Store;

$today    = '2026-10-19';
$settings = Settings::get();

/**
 * A received supplier order with two lines, checked in on $date: two stays.
 *
 * @return array{store: Fake_Store, order_id: int, stays: int[]}
 */
function fh_batch( $date, $sale_date = null ) {
	global $today, $settings;
	$store    = new Fake_Store( array( 21 => 'Blue Test Wrasse', 22 => 'Red Test Goby' ) );
	$order_id = $store->save_order( Orders::normalize_order( array( 'ref' => '2026-7', 'supplier' => 'Test Supplier One', 'received_date' => $date, 'status' => 'ordered' ) ) );
	$store->save_order_lines(
		$order_id,
		Orders::normalize_lines(
			array(
				array( 'species' => 'Blue Test Wrasse', 'qty_ordered' => 6, 'qty_received' => 6 ),
				array( 'species' => 'Red Test Goby', 'qty_ordered' => 4, 'qty_received' => 4 ),
			)
		)
	);
	$out = Orders::check_in( $store, $store->order( $order_id ), $today, $settings, array( 'actor_id' => 1 ) );
	if ( null !== $sale_date ) {
		$store->update_order_fields( $order_id, array( 'sale_date' => $sale_date ) );
	}
	return array(
		'store'    => $store,
		'order_id' => $order_id,
		'stays'    => array_column( $out['created'], 'stay_id' ),
	);
}

echo "1. Precedence: own > batch > none\n";
$b     = fh_batch( '2026-10-01' );
$store = $b['store'];
$s     = $store->stay( $b['stays'][0], $today, $settings );
check( 'checked in, the batch has no sale date: none, not for sale, "suggested" = check-in + 28 only', 2 === count( $b['stays'] ) && null === $s['sale_ready_date'] && '' === $s['sale_date_source'] && false === $s['sale_ready'] && '2026-10-29' === $s['sale_ready_suggested'] && $b['order_id'] === $s['batch_order_id'] && '2026-7' === $s['batch_order_ref'], json_encode( $s ) );
$store->update_order_fields( $b['order_id'], array( 'sale_date' => '2026-10-25' ) );
$s = $store->stay( $b['stays'][0], $today, $settings );
check( 'the batch date set on the order: every group of the batch reads it, from the batch', '2026-10-25' === $s['sale_ready_date'] && Replay::SALE_BATCH === $s['sale_date_source'] && '2026-10-25' === $s['batch_sale_date'] && '2026-10-25' === $store->stay( $b['stays'][1], $today, $settings )['sale_ready_date'] );
$store->record_event( $b['stays'][0], array( 'type' => 'note', 'note' => Replay::NOTE_SALE_READY . '2026-11-02', 'event_at' => gmdate( 'Y-m-d H:i:s' ) ), $today, $settings );
$s = $store->stay( $b['stays'][0], $today, $settings );
check( 'a group\'s own date wins over the batch\'s; the other group keeps the batch\'s', '2026-11-02' === $s['sale_ready_date'] && Replay::SALE_OWN === $s['sale_date_source'] && '2026-11-02' === $s['sale_ready_override'] && '2026-10-25' === $store->stay( $b['stays'][1], $today, $settings )['sale_ready_date'] );
check( 'the own date is cached on the row (sale_ready_date column), the batch\'s is not', '2026-11-02' === $store->stays[ $b['stays'][0] ]['sale_ready_date'] && null === $store->stays[ $b['stays'][1] ]['sale_ready_date'] );
$store->record_event( $b['stays'][0], array( 'type' => 'note', 'note' => Replay::NOTE_SALE_READY, 'event_at' => gmdate( 'Y-m-d H:i:s' ) ), $today, $settings );
check( 'removing the own date goes back to the batch\'s', '2026-10-25' === $store->stay( $b['stays'][0], $today, $settings )['sale_ready_date'] );
$store->update_order_fields( $b['order_id'], array( 'sale_date' => null ) );
check( 'clearing the batch date: back to none', null === $store->stay( $b['stays'][0], $today, $settings )['sale_ready_date'] );
$loose = $store->create_stay( array( 'species' => 'Yellow Test Tang', 'checkin_date' => '2026-10-10', 'tank_id' => $store->tank_by_number( 523 )['id'] ) );
$store->record_event( $loose, array( 'type' => 'arrival', 'qty' => 2, 'to_tank_id' => $store->tank_by_number( 523 )['id'], 'event_at' => '2026-10-10 17:00:00' ), $today, $settings );
$s = $store->stay( $loose, $today, $settings );
check( 'a group checked in on the phone (no supplier order): no batch, no date until one is chosen', null === $s['batch_order_id'] && null === $s['batch_sale_date'] && null === $s['sale_ready_date'] );

echo "2. Splits keep the batch\n";
$store->update_order_fields( $b['order_id'], array( 'sale_date' => '2026-10-15' ) ); // Past: the groups are for sale from here on.
$wrasse = $store->stay( $b['stays'][0], $today, $settings );
$split  = $store->split_stay( $wrasse, 2, $store->tank_by_number( 121 ), array( 'actor_id' => 1, 'source' => 'manual' ), $today, $settings );
$sister = $store->stay( $split['stay_id'], $today, $settings );
check( 'a split-off group belongs to the same batch and reads its date', $b['order_id'] === $sister['batch_order_id'] && '2026-10-15' === $sister['sale_ready_date'] );

echo "3. The boundary: the date itself counts, in the site zone\n";
$row = array( 'status' => 'cleared', 'checkin_date' => '2026-10-01', 'cleared_date' => '2026-10-15', 'batch_sale_date' => '2026-10-25' );
check( 'the day before: not for sale; the date itself: for sale; after: for sale', false === Replay::effective( $row, '2026-10-24' )['sale_ready'] && true === Replay::effective( $row, '2026-10-25' )['sale_ready'] && true === Replay::effective( $row, '2026-11-30' )['sale_ready'] );
$GLOBALS['fh_now'] = '2026-10-24 23:30:00'; // Site time (Chicago): already Oct 25 in UTC.
check( 'at 23:30 site time on the eve the site date is still the 24th: not for sale yet (hydration with no date given uses the site date)', '2026-10-24' === Clean_Room::today() && false === Store::hydrate_stay( array_merge( $row, array( 'id' => 1, 'sale_ready_date' => null ) ) )['sale_ready'] );
$GLOBALS['fh_now'] = '2026-10-25 00:05:00';
check( 'five past midnight site time: for sale', '2026-10-25' === Clean_Room::today() && true === Store::hydrate_stay( array_merge( $row, array( 'id' => 1, 'sale_ready_date' => null ) ) )['sale_ready'] );
$GLOBALS['fh_now'] = '2026-04-01 09:00:00';
check( 'is_sale_ready: junk dates are never ready', false === Replay::is_sale_ready( 'soon', '2026-10-25', 'cleared' ) && false === Replay::is_sale_ready( '2026-10-25', '', 'cleared' ) && false === Replay::is_sale_ready( null, '2026-10-25', 'cleared' ) );

echo "4. Closed stays are never for sale\n";
$goby = $store->stay( $b['stays'][1], $today, $settings );
check( 'the goby group, its batch date past: for sale', true === $goby['sale_ready'] );
$store->record_event( $b['stays'][1], array( 'type' => 'sold', 'qty' => 4, 'event_at' => gmdate( 'Y-m-d H:i:s' ) ), $today, $settings );
$goby = $store->stay( $b['stays'][1], $today, $settings );
check( 'all four shipped: closed, not for sale, no warning, though its date has passed', 'closed' === $goby['status'] && false === $goby['sale_ready'] && false === $goby['ready_in_spa'] && 'closed' === $goby['state_label'] && '2026-10-15' === $goby['sale_ready_date'] );
check( 'Replay::state agrees: closed is never ready', false === $store->replay( $b['stays'][1], $today, $settings )['sale_ready'] );
check( 'the "for sale" filter of the stays list leaves it out (and the open ones in)', ! in_array( $b['stays'][1], array_column( $store->open_stays( array( 'ready' => true, 'include_closed' => true ), $today, $settings ), 'id' ), true ) && in_array( $b['stays'][0], array_column( $store->open_stays( array( 'ready' => true ), $today, $settings ), 'id' ), true ) );

echo "5. For sale but still in the Spa: the warning everywhere\n";
$w = $store->stay( $b['stays'][0], $today, $settings );
check( 'the wrasse, never moved out of the Spa, past its batch date: for sale, labelled ready, flagged ready_in_spa', 'in_qt' === $w['status'] && true === $w['sale_ready'] && 'ready' === $w['state_label'] && true === $w['ready_in_spa'] );
check( 'Admin::badge_html: the ready badge and "still in the Spa"', false !== strpos( Admin::badge_html( $w ), 'fh-cr-badge--ready' ) && false !== strpos( Admin::badge_html( $w ), 'still in the Spa' ) );
check( 'the split-off group in the Clean Room: cleared, for sale, no warning', 'cleared' === $sister['status'] && true === $sister['sale_ready'] && false === $sister['ready_in_spa'] && false === strpos( Admin::badge_html( $sister ), 'still in the Spa' ) );
$o = Front_Desk::occupant( $w );
check( 'the Front Desk occupant carries the effective date, its source, the suggestion and the warning', '2026-10-15' === $o['saleReadyDate'] && 'batch' === $o['saleSource'] && '2026-10-15' === $o['batchSaleDate'] && '2026-10-29' === $o['saleSuggested'] && true === $o['saleReady'] && true === $o['readyInSpa'] );
$totals = Admin::totals( array( array( 'status' => 'active', 'occupants' => $store->open_stays( array(), $today, $settings ) ) ) );
check( 'the Board totals: the warning counted, the phone-only group without a date counted', 1 === $totals['ready_in_spa'] && 1 === $totals['no_sale_date'] && 2 === $totals['ready'], json_encode( $totals ) );
$words = Admin::sale_words( $w );
check( 'sale_words: "on sale <date>", "from the batch", the warning', 'on sale 2026-10-15' === $words['text'] && 'from the batch' === $words['source'] && 'still in the Spa' === $words['warning'] && '' === $words['hint'] );
$words = Admin::sale_words( $store->stay( $loose, $today, $settings ) );
check( 'sale_words without a date: "Sale date not set" and the suggestion', 'Sale date not set' === $words['text'] && 'suggested 2026-11-07' === $words['hint'] && '' === $words['date'] && '' === $words['warning'] );

echo "6. The Replay history is unchanged apart from the rule\n";
$areas  = array( 10 => 'spa', 20 => 'clean' );
$opts   = array( 'sale_ready_days' => 28, 'tz' => new DateTimeZone( 'America/Chicago' ), 'tank_areas' => $areas );
$events = array(
	array( 'id' => 1, 'type' => 'arrival', 'qty' => 6, 'to_tank_id' => 10, 'event_at' => '2026-04-01 15:00:00' ),
	array( 'id' => 2, 'type' => 'loss', 'qty' => 1, 'note' => 'doa', 'event_at' => '2026-04-01 15:00:00' ),
	array( 'id' => 3, 'type' => 'note', 'note' => 'checkin:2026-03-31', 'event_at' => '2026-04-02 10:00:00' ),
	array( 'id' => 4, 'type' => 'move', 'from_tank_id' => 10, 'to_tank_id' => 20, 'note' => 'clear_building', 'event_at' => '2026-04-15 14:00:00' ),
	array( 'id' => 5, 'type' => 'count', 'qty' => 4, 'event_at' => '2026-04-16 14:00:00' ),
	array( 'id' => 6, 'type' => 'sold', 'qty' => 1, 'event_at' => '2026-05-02 14:00:00' ),
	array( 'id' => 7, 'type' => 'note', 'note' => 'sale_ready:2026-05-01', 'event_at' => '2026-04-20 14:00:00' ),
);
$without = Replay::state( array(), $events, '2026-05-03', $opts );
$with    = Replay::state( array( 'batch_sale_date' => '2026-04-25' ), $events, '2026-05-03', $opts );
$history = array( 'qty_arrived', 'qty_live', 'qty_lost', 'qty_sold', 'overdrawn', 'checkin_date', 'cleared_date', 'closed_date', 'sale_ready_override', 'tank_id', 'status', 'stored_status', 'day', 'last_event_at' );
$same    = true;
foreach ( $history as $key ) {
	$same = $same && array_key_exists( $key, $without ) && $without[ $key ] === $with[ $key ];
}
check( 'the same events with or without a batch date: every history field identical', $same, json_encode( array_diff_assoc( array_map( 'json_encode', $without ), array_map( 'json_encode', $with ) ) ) );
check( 'the history itself: 6 arrived, 1 lost, 1 sold, 3 live, check-in Mar 31, cleared Apr 15, tank 20, day 34, own date May 1', 6 === $with['qty_arrived'] && 1 === $with['qty_lost'] && 1 === $with['qty_sold'] && 3 === $with['qty_live'] && '2026-03-31' === $with['checkin_date'] && '2026-04-15' === $with['cleared_date'] && 20 === $with['tank_id'] && 'cleared' === $with['status'] && 34 === $with['day'] && '2026-05-01' === $with['sale_ready_override'] && null === $with['closed_date'] );
check( 'only the rule differs: the own date rules in both, the batch\'s is reported beside it', '2026-05-01' === $without['sale_ready_date'] && '2026-05-01' === $with['sale_ready_date'] && null === $without['batch_sale_date'] && '2026-04-25' === $with['batch_sale_date'] && true === $with['sale_ready'] );
$before = Replay::state( array(), array_slice( $events, 0, 6 ), '2026-05-03', $opts );
check( 'the undo of the sale-date note is still the previous own date (none here)', 'sale_ready:' === Replay::compensating( $events[6], $before )['note'] );

echo "7. The schema carries the batch's sale date\n";
$GLOBALS['wpdb'] = (object) array( 'prefix' => 'wp_' );
$orders_sql      = '';
foreach ( Store::schema( 'DEFAULT CHARSET=utf8mb4' ) as $statement ) {
	if ( false !== strpos( $statement, 'CREATE TABLE wp_fh_orders (' ) ) {
		$orders_sql = $statement;
	}
}
check( 'nine CREATE TABLE statements; fh_orders has "sale_date DATE NULL," on its own line after received_date', 9 === count( Store::schema() ) && 1 === preg_match( "/\n\t+received_date DATE NULL,\n\t+sale_date DATE NULL,\n/", $orders_sql ) );
check( 'the schema version moved to 1.3 (the install gate opens once on every site)', '1.3' === Store::DB_VERSION );
check( 'hydrate_order reads it (a zero date is none)', '2026-10-25' === Store::hydrate_order( array( 'sale_date' => '2026-10-25' ) )['sale_date'] && null === Store::hydrate_order( array( 'sale_date' => '0000-00-00' ) )['sale_date'] && null === Store::hydrate_order( array() )['sale_date'] );
unset( $GLOBALS['wpdb'] );

echo "8. Product facts with a null date\n";
$fstore = new Fake_Store( array( 30 => 'Green Test Chromis' ) );
$t521   = $fstore->tank_by_number( 521 )['id'];
foreach ( array( '2026-10-01', '2026-10-05' ) as $checkin ) {
	$id = $fstore->create_stay( array( 'product_id' => 30, 'species' => 'Green Test Chromis', 'checkin_date' => $checkin, 'tank_id' => $t521 ) );
	$fstore->record_event( $id, array( 'type' => 'arrival', 'qty' => 3, 'to_tank_id' => $t521, 'event_at' => Store::site_noon_utc( $checkin ) ), $today, $settings );
}
$facts = $fstore->product_facts( 30, $today, $settings );
check( 'no date anywhere: every sale_ready_date and source null, nothing for sale, next = the earliest check-in with a null date', 6 === $facts['live_total'] && 0 === $facts['ready_total'] && null === $facts['in_qt'][0]['sale_ready_date'] && null === $facts['in_qt'][0]['sale_date_source'] && false === $facts['in_qt'][1]['sale_ready'] && null === $facts['next']['sale_ready_date'] && '2026-10-01' === $facts['next']['checkin_date'], json_encode( $facts ) );
$fstore->record_event( 2, array( 'type' => 'note', 'note' => Replay::NOTE_SALE_READY . '2026-11-10', 'event_at' => gmdate( 'Y-m-d H:i:s' ) ), $today, $settings );
$facts = $fstore->product_facts( 30, $today, $settings );
check( 'one group dated: it is next (a dated stay sorts before an undated one), with its source', '2026-11-10' === $facts['next']['sale_ready_date'] && 'own' === $facts['next']['sale_date_source'] && '2026-10-05' === $facts['next']['checkin_date'] && null === $facts['in_qt'][0]['sale_ready_date'] );
$fstore->record_event( 1, array( 'type' => 'note', 'note' => Replay::NOTE_SALE_READY . '2026-10-19', 'event_at' => gmdate( 'Y-m-d H:i:s' ) ), $today, $settings );
$facts = $fstore->product_facts( 30, $today, $settings );
check( 'a group whose date is today, still in the Spa: for sale, counted in ready_total, next', 3 === $facts['ready_total'] && true === $facts['next']['sale_ready'] && '2026-10-01' === $facts['next']['checkin_date'] );
check( 'facts_from_stays skips closed and empty stays; nothing left → the zeros the filter turns into null', null === Clean_Room::facts_or_null( Store::facts_from_stays( array( array( 'id' => 9, 'status' => 'closed', 'qty_live' => 0 ) ) ) ) );

echo "9. The Front Desk's words\n";
check( 'a date set: "<group> on sale <date>"', 'Blue Test Wrasse on sale 2026-11-02' === Front_Desk::ready_message( 'Blue Test Wrasse', '2026-11-02', array() ) );
check( 'removed with a batch date: back to it', 'Blue Test Wrasse back to the batch date (2026-10-25)' === Front_Desk::ready_message( 'Blue Test Wrasse', '', array( 'batch_sale_date' => '2026-10-25' ) ) );
check( 'removed without one: no sale date and where to set it', 'Blue Test Wrasse: no sale date now — set one on its supplier order' === Front_Desk::ready_message( 'Blue Test Wrasse', '', array( 'batch_sale_date' => null ) ) );
check( 'the date has come before the group was cleared: " · still in the Spa"', 'Blue Test Wrasse back to the batch date (2026-10-25) · still in the Spa' === Front_Desk::ready_message( 'Blue Test Wrasse', '', array( 'batch_sale_date' => '2026-10-25', 'ready_in_spa' => true ) ) );
$ctx = array(
	'store'    => $store,
	'settings' => $settings,
	'today'    => $today,
	'tank'     => $store->tank( $w['tank_id'] ),
	'base'     => array( 'actor_id' => 4, 'source' => 'qr' ),
);
$n = $store->event_count();
$r = Front_Desk::perform( 'ready', $ctx, array( 'stay_id' => $w['id'], 'date' => '' ) );
check( 'perform("ready") with nothing typed on a group without an own date (Save with nothing changed): "nothing changed", no event', 'Blue Test Wrasse: nothing changed' === ( $r['message'] ?? '' ) && array() === $r['event_ids'] && $n === $store->event_count(), json_encode( $r ) );
$r = Front_Desk::perform( 'ready', $ctx, array( 'stay_id' => $w['id'], 'date' => '2026-12-01' ) );
check( 'perform("ready") with a date: the own date, written as the note event', 'Blue Test Wrasse on sale 2026-12-01' === ( $r['message'] ?? '' ) && 'sale_ready:2026-12-01' === $store->last_event()['note'] && false === $store->stay( $w['id'], $today, $settings )['sale_ready'] );
$n = $store->event_count();
$r = Front_Desk::perform( 'ready', $ctx, array( 'stay_id' => $w['id'], 'date' => '2026-12-01' ) );
check( '… the same date again: "nothing changed", no event', 'Blue Test Wrasse: nothing changed' === ( $r['message'] ?? '' ) && $n === $store->event_count() );
$r = Front_Desk::perform( 'ready', $ctx, array( 'stay_id' => $w['id'], 'date' => '' ) );
check( 'emptied: the own date removed, back to the batch\'s, the toast with the warning', 'Blue Test Wrasse back to the batch date (2026-10-15) · still in the Spa' === ( $r['message'] ?? '' ) && 'sale_ready:' === $store->last_event()['note'] && $n + 1 === $store->event_count(), json_encode( $r ) );
$n   = $store->event_count();
$bad = 0;
foreach ( array( '0026-11-01', '1999-12-31', '2028-10-20', '9999-12-31' ) as $far ) {
	$r = Front_Desk::perform( 'ready', $ctx, array( 'stay_id' => $w['id'], 'date' => $far ) );
	if ( 'Pick a sale date from 2000-01-01 to 2028-10-19.' === ( $r['error'] ?? '' ) ) {
		$bad++;
	}
}
check( 'a date outside 2000-01-01 – two years after today (a typed "26" lands in 0026): refused, no event', 4 === $bad && $n === $store->event_count() && true === $store->stay( $w['id'], $today, $settings )['sale_ready'] );
$r = Front_Desk::perform( 'ready', $ctx, array( 'stay_id' => $w['id'], 'date' => '2028-10-19' ) );
check( '… two years after today itself is accepted', 'Blue Test Wrasse on sale 2028-10-19' === ( $r['message'] ?? '' ) && $n + 1 === $store->event_count() );
Front_Desk::perform( 'ready', $ctx, array( 'stay_id' => $w['id'], 'date' => '' ) );
$i18n = Front_Desk::i18n();
check( 'the screen strings name the chosen date, never "N days after check-in"', 'on sale %s' === $i18n['readyOn'] && isset( $i18n['readyBatch'], $i18n['readyNone'], $i18n['stillInSpa'], $i18n['noSaleDate'] ) && ! isset( $i18n['readyAuto'] ) );

echo "10. The batch calendar: the save handler\n";
$GLOBALS['fh_log'] = array();
add_action(
	'fishotel_log',
	static function ( $level, $source, $message, $context = array() ) {
		$GLOBALS['fh_log'][] = array( $level, $source, $message, $context );
	},
	10,
	4
);
$b     = fh_batch( '2026-10-01' );
$store = $b['store'];
$oid   = $b['order_id'];
$good  = array(
	'order_id'  => (string) $oid,
	'_wpnonce'  => wp_create_nonce( Admin_Workbook::NONCE_SALE_DATE . $oid ),
	'op'        => 'save',
	'sale_date' => '2026-10-30',
);
$GLOBALS['fh_current_user'] = 5;
$GLOBALS['fh_user_logins']  = array( 5 => 'crew' );
$GLOBALS['fh_user_caps']    = array();
$r = Admin_Workbook::sale_date_request( $store, $good );
check( 'without manage_woocommerce: 403, nothing written, nothing logged', 403 === $r['status'] && false === $r['ok'] && null === $store->order( $oid )['sale_date'] && array() === $GLOBALS['fh_log'] );
$GLOBALS['fh_user_caps']['manage_woocommerce'] = array( 5 );
$r = Admin_Workbook::sale_date_request( $store, array_merge( $good, array( '_wpnonce' => 'nonce-wrong' ) ) );
check( 'a wrong nonce: 403 "expired", nothing written', 403 === $r['status'] && false !== strpos( $r['message'], 'expired' ) && null === $store->order( $oid )['sale_date'] );
$r = Admin_Workbook::sale_date_request( $store, array_merge( $good, array( '_wpnonce' => wp_create_nonce( Admin_Workbook::NONCE_SALE_DATE . ( $oid + 1 ) ) ) ) );
check( 'another order\'s nonce: 403 (the nonce is per order)', 403 === $r['status'] && null === $store->order( $oid )['sale_date'] );
$r = Admin_Workbook::sale_date_request( $store, array_merge( $good, array( 'order_id' => '99', '_wpnonce' => wp_create_nonce( Admin_Workbook::NONCE_SALE_DATE . '99' ) ) ) );
check( 'an order that no longer exists: 404, back to the Arrivals list', 404 === $r['status'] && false !== strpos( $r['redirect'], 'tab=arrivals' ) && false === strpos( $r['redirect'], 'order=' ) );
$r = Admin_Workbook::sale_date_request( $store, $good );
check( 'a valid date: saved on the order, 200, back to the order with the message', 200 === $r['status'] && true === $r['ok'] && '2026-10-30' === $store->order( $oid )['sale_date'] && null === $r['old'] && '2026-10-30' === $r['new'] && false !== strpos( $r['redirect'], 'order=' . $oid ) && 'Order 2026-7 goes on sale 2026-10-30 (was not set).' === $r['message'], json_encode( $r ) );
check( 'logged once through fishotel_log: info, clean-room, who and old → new, the order in the context', 1 === count( $GLOBALS['fh_log'] ) && 'info' === $GLOBALS['fh_log'][0][0] && 'clean-room' === $GLOBALS['fh_log'][0][1] && 'Sale date of supplier order 2026-7 changed by crew: not set → 2026-10-30' === $GLOBALS['fh_log'][0][2] && $oid === $GLOBALS['fh_log'][0][3]['order_id'] && 5 === $GLOBALS['fh_log'][0][3]['user_id'] && null === $GLOBALS['fh_log'][0][3]['old'] && '2026-10-30' === $GLOBALS['fh_log'][0][3]['new'], json_encode( $GLOBALS['fh_log'] ) );
$resave = Admin_Workbook::save_order( $store, array( 'ref' => '2026-7', 'supplier' => 'Test Supplier One', 'received_date' => '2026-10-01', 'status' => 'received', 'lines' => array() ), $oid, $today );
check( 'the order editor\'s Save (header + lines) never touches the sale date', empty( $resave['error'] ) && '2026-10-30' === $store->order( $oid )['sale_date'] );
check( 'its groups read the date at once', '2026-10-30' === $store->stay( $b['stays'][0], $today, $settings )['sale_ready_date'] && '2026-10-30' === $store->stay( $b['stays'][1], $today, $settings )['sale_ready_date'] );
$r = Admin_Workbook::sale_date_request( $store, $good );
check( 'the same date again: fine, nothing written, nothing logged', 200 === $r['status'] && true === $r['ok'] && false !== strpos( $r['message'], 'nothing changed' ) && 1 === count( $GLOBALS['fh_log'] ) );
$bad = 0;
foreach ( array( '2026-02-30', 'tomorrow', '10/30/2026', '2026-10-30x', '' ) as $junk ) {
	$r = Admin_Workbook::sale_date_request( $store, array_merge( $good, array( 'sale_date' => $junk ) ) );
	if ( 400 === $r['status'] && false === $r['ok'] && false !== strpos( $r['message'], 'Nothing was changed' ) && '2026-10-30' === $store->order( $oid )['sale_date'] ) {
		$bad++;
	}
}
check( 'an invalid date (Feb 30, words, US order, junk after it, nothing): 400 each, the date kept, nothing logged', 5 === $bad && 1 === count( $GLOBALS['fh_log'] ) );
$r = Admin_Workbook::sale_date_request( $store, array_merge( $good, array( 'sale_date' => '2026-11-04' ) ) );
check( 'changed to another date: old → new in the log', '2026-11-04' === $store->order( $oid )['sale_date'] && 'Sale date of supplier order 2026-7 changed by crew: 2026-10-30 → 2026-11-04' === end( $GLOBALS['fh_log'] )[2] );
$r = Admin_Workbook::sale_date_request( $store, array_merge( $good, array( 'op' => 'clear', 'sale_date' => '2026-12-01' ) ) );
check( 'Clear: the date gone (whatever the picker held), logged, the groups back to none', 200 === $r['status'] && null === $store->order( $oid )['sale_date'] && 'Sale date of supplier order 2026-7 changed by crew: 2026-11-04 → not set' === end( $GLOBALS['fh_log'] )[2] && null === $store->stay( $b['stays'][0], $today, $settings )['sale_ready_date'] && 3 === count( $GLOBALS['fh_log'] ) );
$r = Admin_Workbook::sale_date_request( $store, array_merge( $good, array( 'op' => 'clear' ) ) );
check( 'Clear with no date: nothing written, nothing logged', 200 === $r['status'] && 3 === count( $GLOBALS['fh_log'] ) );

echo "11. The order page and the Arrivals list\n";
Admin_Workbook::sale_date_request( $store, $good );
$store->update_stay_fields( $b['stays'][1], array( 'product_id' => 22 ) );
$store->record_event( $b['stays'][1], array( 'type' => 'note', 'note' => Replay::NOTE_SALE_READY . '2026-11-15', 'event_at' => gmdate( 'Y-m-d H:i:s' ) ), $today, $settings );
$stays = $store->open_stays( array( 'order_id' => $oid ), $today, $settings );
$panel = Admin_Workbook::batch_sale_panel( $store->order( $oid ), $stays, Admin_Workbook::product_titles( $store, array_column( $stays, 'product_id' ) ), $settings );
check( 'the card: the date, the suggestion (check-in + 28), 2 groups in tanks, the date applies to 1, the other listed with its own date, product and edit link', '2026-10-30' === $panel['date'] && '2026-10-29' === $panel['suggested'] && 2 === $panel['open'] && 1 === $panel['applies'] && 1 === count( $panel['own'] ) && 'Red Test Goby' === $panel['own'][0]['product'] && '2026-11-15' === $panel['own'][0]['date'] && false !== strpos( $panel['own'][0]['edit_url'], 'post=22' ) && 511 <= (int) $panel['own'][0]['tank'] && Admin_Workbook::NONCE_SALE_DATE . $oid === $panel['nonce_action'], json_encode( $panel ) );
check( 'the line under the picker: before the date, on and after it', 'Applies to 1 group still in tanks.' === Admin_Workbook::batch_sale_status( $panel, '2026-10-29' ) && 'Applies to 1 group still in tanks — for sale since 2026-10-30.' === Admin_Workbook::batch_sale_status( $panel, '2026-10-30' ) );
check( '… with no date, with no group in a tank, with every group dated', 'Not set: 3 groups of this batch still in tanks are not for sale until a date is chosen.' === Admin_Workbook::batch_sale_status( array( 'applies' => 3, 'open' => 3, 'date' => '' ), $today ) && 'No group of this batch is in a tank now.' === Admin_Workbook::batch_sale_status( array( 'applies' => 0, 'open' => 0, 'date' => '' ), $today ) && 0 === strpos( Admin_Workbook::batch_sale_status( array( 'applies' => 0, 'open' => 2, 'date' => '2026-10-30' ), $today ), 'Every group' ) );
check( 'the suggestion before any check-in: from the received date, else none', '2026-11-28' === Admin_Workbook::suggested_batch_date( array( 'received_date' => '2026-10-31' ), array(), $settings ) && '' === Admin_Workbook::suggested_batch_date( array(), array(), $settings ) );
$counts = $store->batch_sale_counts();
$cell   = Admin_Workbook::on_sale_cell( $store->order( $oid ), $counts[ $oid ] ?? array() );
check( 'the Arrivals "On sale" cell: the date and "1 of 2 groups has its own date"', array( 'open' => 2, 'own' => 1 ) === $counts[ $oid ] && '2026-10-30' === $cell['text'] && '1 of 2 groups has its own date' === $cell['note'], json_encode( $cell ) );
check( '… "not set" without a date, no note without own dates', array( 'date' => '', 'text' => 'not set', 'note' => '' ) === Admin_Workbook::on_sale_cell( array(), array( 'open' => 3, 'own' => 0 ) ) );
$sheets = Admin_Workbook::orders_sheets( array( Admin_Workbook::resolve_order( $store, $store->order( $oid ) ) ) );
$row    = array_combine( $sheets['Orders'][0], $sheets['Orders'][1] );
check( 'the orders export carries the batch\'s sale date', '2026-10-30' === $row['sale_date'] && count( $sheets['Orders'][0] ) === count( $sheets['Orders'][1] ) );

echo "12. The product page: sale dates saved with the product\n";
$p     = fh_batch( '2026-10-01', '2026-10-25' );
$store = $p['store'];
list( $s1, $s2 ) = $p['stays'];
$store->update_stay_fields( $s1, array( 'product_id' => 40 ) );
$store->update_stay_fields( $s2, array( 'product_id' => 40 ) );
$store->record_event( $s2, array( 'type' => 'note', 'note' => Replay::NOTE_SALE_READY . '2026-11-15', 'event_at' => gmdate( 'Y-m-d H:i:s' ) ), $today, $settings );
$other = $store->create_stay( array( 'product_id' => 41, 'species' => 'Elsewhere Test Fish', 'checkin_date' => '2026-10-01', 'tank_id' => $store->tank_by_number( 524 )['id'] ) );
$store->record_event( $other, array( 'type' => 'arrival', 'qty' => 1, 'to_tank_id' => $store->tank_by_number( 524 )['id'], 'event_at' => '2026-10-01 17:00:00' ), $today, $settings );
$admin = new Admin();
$GLOBALS['fh_current_user'] = 6;
$GLOBALS['fh_user_caps']    = array(
	'edit_post'          => array( 6 ),
	'manage_woocommerce' => array( 6 ),
);
$events = $store->event_count();
$form   = static function ( array $rows, $product = 40 ) {
	return array(
		Admin::NONCE_PRODUCT_FIELD => wp_create_nonce( Admin::NONCE_PRODUCT . $product ),
		Admin::SALE_FIELD          => $rows,
	);
};
$untouched = array(
	$s1 => array( 'date' => '2026-10-25', 'shown' => '2026-10-25' ),
	$s2 => array( 'date' => '2026-11-15', 'shown' => '2026-11-15' ),
);

$_POST = array( Admin::SALE_FIELD => array( $s1 => array( 'date' => '2026-12-01', 'shown' => '2026-10-25' ) ) );
check( 'no nonce field (autosave, quick edit, bulk edit, REST): no work at all', false === $admin->save_meta_box( 40, null, true, $store ) && $events === $store->event_count() );
$_POST = array_merge( $form( array( $s1 => array( 'date' => '2026-12-01', 'shown' => '2026-10-25' ) ) ), array( Admin::NONCE_PRODUCT_FIELD => wp_create_nonce( Admin::NONCE_PRODUCT . 41 ) ) );
check( 'another product\'s nonce: nothing', false === $admin->save_meta_box( 40, null, true, $store ) && $events === $store->event_count() );
$_POST = $form( array( $s1 => array( 'date' => '2026-12-01', 'shown' => '2026-10-25' ) ) );
$GLOBALS['fh_user_caps']['edit_post'] = array();
check( 'without edit_post on the product: nothing', false === $admin->save_meta_box( 40, null, true, $store ) && $events === $store->event_count() );
$GLOBALS['fh_user_caps']['edit_post']          = array( 6 );
$GLOBALS['fh_user_caps']['manage_woocommerce'] = array();
check( 'without the section\'s capability: nothing', false === $admin->save_meta_box( 40, null, true, $store ) && $events === $store->event_count() );
$GLOBALS['fh_user_caps']['manage_woocommerce'] = array( 6 );

$_POST = $form( $untouched );
$r     = $admin->save_meta_box( 40, null, true, $store );
check( 'Update with both pickers untouched (one showing the batch date): no event — the batch\'s date is never copied into an own date', is_array( $r ) && 0 === $r['events'] && $events === $store->event_count() && null === $store->stay( $s1, $today, $settings )['sale_ready_override'] && false === get_transient( Admin::SALE_NOTICE . 6 ) );
$r = $admin->save_meta_box( 40, null, true, $store );
check( 'the hook firing again in the same request (WooCommerce saves the product twice): skipped', false === $r );

$store3 = fh_batch( '2026-10-01', '2026-10-25' );
$st     = $store3['store'];
list( $a1, $a2 ) = $store3['stays'];
$st->update_stay_fields( $a1, array( 'product_id' => 42 ) );
$st->update_stay_fields( $a2, array( 'product_id' => 42 ) );
$st->record_event( $a2, array( 'type' => 'note', 'note' => Replay::NOTE_SALE_READY . '2026-11-15', 'event_at' => gmdate( 'Y-m-d H:i:s' ) ), $today, $settings );
$before = $st->event_count();
$_POST  = $form(
	array(
		$a1    => array( 'date' => '2026-12-01', 'shown' => '2026-10-25' ),
		$a2    => array( 'date' => '2026-11-15', 'shown' => '2026-11-15' ),
		$other => array( 'date' => '2026-12-24', 'shown' => '' ),
	),
	42
);
$r = $admin->save_meta_box( 42, null, true, $st );
check( 'one picker changed: exactly one event, on that stay, the own-date note with the actor; the untouched one and a foreign stay id get nothing', 1 === $r['events'] && array( $a1 ) === $r['changed'] && $before + 1 === $st->event_count() && 'sale_ready:2026-12-01' === $st->last_event()['note'] && $a1 === $st->last_event()['stay_id'] && 6 === $st->last_event()['actor_id'] && '2026-12-01' === $st->stay( $a1, $today, $settings )['sale_ready_date'] && 'own' === $st->stay( $a1, $today, $settings )['sale_date_source'], json_encode( $r ) );
$notice = get_transient( Admin::SALE_NOTICE . 6 );
check( 'a success notice waits for the edit screen after the redirect', is_array( $notice ) && 'success' === $notice['type'] && 'Clean room: the sale date of 1 group was saved.' === $notice['lines'][0] );
ob_start();
$admin->sale_date_notices();
$html = ob_get_clean();
check( 'admin_notices prints it once (escaped) and drops it', false !== strpos( $html, 'notice-success' ) && false !== strpos( $html, 'the sale date of 1 group was saved' ) && false === get_transient( Admin::SALE_NOTICE . 6 ) );
ob_start();
$admin->sale_date_notices();
check( '… and prints nothing the next time', '' === ob_get_clean() );

$st4 = fh_batch( '2026-10-01', '2026-10-25' );
$s4  = $st4['store'];
list( $b1, $b2 ) = $st4['stays'];
$s4->update_stay_fields( $b1, array( 'product_id' => 43 ) );
$s4->update_stay_fields( $b2, array( 'product_id' => 43 ) );
$s4->record_event( $b1, array( 'type' => 'note', 'note' => Replay::NOTE_SALE_READY . '2026-11-15', 'event_at' => gmdate( 'Y-m-d H:i:s' ) ), $today, $settings );
$s4->record_event( $b2, array( 'type' => 'note', 'note' => Replay::NOTE_SALE_READY . '2026-11-20', 'event_at' => gmdate( 'Y-m-d H:i:s' ) ), $today, $settings );
$before = $s4->event_count();
$_POST  = $form(
	array(
		$b1 => array( 'date' => '2026-11-15', 'shown' => '2026-11-15', 'batch' => '1' ),
		$b2 => array( 'date' => '2026-02-30', 'shown' => '2026-11-20' ),
	),
	43
);
$r = $admin->save_meta_box( 43, null, true, $s4 );
check( '"Use the batch date" ticked: the own date cleared by a "sale_ready:" event, the group back to the batch\'s date', 1 === $r['events'] && array( $b1 ) === $r['changed'] && 'sale_ready:' === $s4->event( $before + 1 )['note'] && '2026-10-25' === $s4->stay( $b1, $today, $settings )['sale_ready_date'] && 'batch' === $s4->stay( $b1, $today, $settings )['sale_date_source'] );
check( 'an invalid date (Feb 30): ignored, the group keeps its own date, reported', 1 === count( $r['invalid'] ) && false !== strpos( $r['invalid'][0], '2026-02-30' ) && '2026-11-20' === $s4->stay( $b2, $today, $settings )['sale_ready_date'] && $before + 1 === $s4->event_count() );
$notice = get_transient( Admin::SALE_NOTICE . 6 );
check( 'the notice after the redirect names it as a warning, with the dates a sale can be set to', 'warning' === $notice['type'] && 2 === count( $notice['lines'] ) && false !== strpos( $notice['lines'][1], 'is not a date from 2000-01-01 to ' . Replay::sale_date_max( Clean_Room::today() ) . ' — ignored' ), json_encode( $notice ) );
delete_transient( Admin::SALE_NOTICE . 6 );

$st5 = fh_batch( '2026-10-01' );
$s5  = $st5['store'];
list( $c1, $c2 ) = $st5['stays'];
$s5->update_stay_fields( $c1, array( 'product_id' => 44 ) );
$s5->record_event( $c1, array( 'type' => 'note', 'note' => Replay::NOTE_SALE_READY . '2026-11-15', 'event_at' => gmdate( 'Y-m-d H:i:s' ) ), $today, $settings );
$r = Admin::save_product_sale_dates( $s5, 44, array( $c1 => array( 'date' => '', 'shown' => '2026-11-15' ) ), $today, $settings, 6 );
check( 'the picker emptied: the own date removed; with no batch date the group has none', 1 === $r['events'] && null === $s5->stay( $c1, $today, $settings )['sale_ready_date'] );
$r = Admin::save_product_sale_dates( $s5, 44, array( $c1 => array( 'date' => '', 'shown' => '', 'batch' => '1' ) ), $today, $settings, 6 );
check( '"Use the batch date" on a group without an own date: no event', 0 === $r['events'] );
$s5->record_event( $c1, array( 'type' => 'sold', 'qty' => 6, 'event_at' => gmdate( 'Y-m-d H:i:s' ) ), $today, $settings );
$r = Admin::save_product_sale_dates( $s5, 44, array( $c1 => array( 'date' => '2026-12-01', 'shown' => '' ) ), $today, $settings, 6 );
check( 'a closed group is never touched', 0 === $r['events'] && null === $s5->stay( $c1, $today, $settings )['sale_ready_override'] );

$st6 = fh_batch( '2026-10-01', '2026-10-15' );
$s6  = $st6['store'];
list( $d1, $d2 ) = $st6['stays'];
$s6->update_stay_fields( $d1, array( 'product_id' => 47 ) );
$s6->update_stay_fields( $d2, array( 'product_id' => 47 ) );
$before = $s6->event_count();
$r      = Admin::save_product_sale_dates( $s6, 47, array( $d1 => array( 'date' => '0026-11-01', 'shown' => '2026-10-15' ) ), $today, $settings, 6 );
check( 'a two-digit year typed (0026-11-01): reported as invalid, no event, the group keeps the batch date', 0 === $r['events'] && 1 === count( $r['invalid'] ) && false !== strpos( $r['invalid'][0], '0026-11-01' ) && $before === $s6->event_count() && 'batch' === $s6->stay( $d1, $today, $settings )['sale_date_source'] );
$r = Admin::save_product_sale_dates( $s6, 47, array( $d1 => array( 'date' => '2028-10-20', 'shown' => '2026-10-15' ) ), $today, $settings, 6 );
check( '… the day after two years from today: refused too; the last day allowed is taken', 1 === count( $r['invalid'] ) && 0 === $r['events'] && 1 === Admin::save_product_sale_dates( $s6, 47, array( $d1 => array( 'date' => '2028-10-19', 'shown' => '2026-10-15' ) ), $today, $settings, 6 )['events'] );
Admin::save_product_sale_dates( $s6, 47, array( $d1 => array( 'date' => '', 'shown' => '2028-10-19' ) ), $today, $settings, 6 );
$before = $s6->event_count();
$tank1  = (int) $s6->stay( $d1, $today, $settings )['tank_number'];
$_POST  = $form( array( $d1 => array( 'date' => '', 'shown' => '2026-10-15' ) ), 47 );
$r      = $admin->save_meta_box( 47, null, true, $s6 );
check( 'a picker showing the batch date, emptied: no event, reported with the batch date and its supplier order', 0 === $r['events'] && $before === $s6->event_count() && array( array( 'label' => 'Blue Test Wrasse (Tank ' . $tank1 . ')', 'date' => '2026-10-15', 'ref' => '2026-7' ) ) === $r['follows_batch'], json_encode( $r ) );
$notice = get_transient( Admin::SALE_NOTICE . 6 );
check( '… the notice after the redirect says where that date lives', is_array( $notice ) && 'warning' === $notice['type'] && array( 'Clean room: Blue Test Wrasse (Tank ' . $tank1 . ') follows the batch date (2026-10-15) — change or clear it on supplier order 2026-7.' ) === $notice['lines'], json_encode( $notice ) );
delete_transient( Admin::SALE_NOTICE . 6 );
$r = Admin::save_product_sale_dates( $s6, 47, array( $d2 => array( 'date' => '', 'shown' => '' ) ), $today, $settings, 6 );
check( '… an empty picker left empty is untouched: no report', array() === $r['follows_batch'] && 0 === $r['events'] );

/**
 * A store that fails on read, as a database error would.
 */
class Broken_Store {
	public function stays_for_product() {
		throw new \RuntimeException( 'database gone' );
	}
}
$GLOBALS['fh_log'] = array();
$_POST             = $form( array( 1 => array( 'date' => '2026-12-01', 'shown' => '' ) ), 45 );
$r                 = $admin->save_meta_box( 45, null, true, new Broken_Store() );
check( 'a failing store never stops the product save: caught, false, logged as an error naming the product, an error notice queued', false === $r && 1 === count( $GLOBALS['fh_log'] ) && 'error' === $GLOBALS['fh_log'][0][0] && false !== strpos( $GLOBALS['fh_log'][0][2], 'product #45' ) && 'error' === get_transient( Admin::SALE_NOTICE . 6 )['type'] );
delete_transient( Admin::SALE_NOTICE . 6 );
define( 'DOING_AUTOSAVE', true );
$_POST = $form( array( $b2 => array( 'date' => '2026-12-05', 'shown' => '2026-11-20' ) ), 46 );
check( 'an autosave with the field present: nothing', false === $admin->save_meta_box( 46, null, true, $s4 ) );
$_POST = array();

echo "13. Chosen dates stay sensible, unchanged dates write nothing, the warning everywhere\n";
check( 'sale_date_max: two years after today; Feb 29 → Feb 28; no today, no bound', '2028-10-19' === Replay::sale_date_max( '2026-10-19' ) && '2030-02-28' === Replay::sale_date_max( '2028-02-29' ) && '' === Replay::sale_date_max( '' ) );
check( 'is_sale_date_in_range: 2000-01-01 and the max itself count; 0026, 1999, the day after the max, 9999 and Feb 30 do not', Replay::is_sale_date_in_range( '2000-01-01', $today ) && Replay::is_sale_date_in_range( '2028-10-19', $today ) && ! Replay::is_sale_date_in_range( '0026-10-30', $today ) && ! Replay::is_sale_date_in_range( '1999-12-31', $today ) && ! Replay::is_sale_date_in_range( '2028-10-20', $today ) && ! Replay::is_sale_date_in_range( '9999-12-31', $today ) && ! Replay::is_sale_date_in_range( '2026-02-30', $today ) );
check( 'own_sale_date_check (the Stays tab and the phone): invalid / range / same (\'\' = no own date) / ok', 'invalid' === Replay::own_sale_date_check( 'soon', null, $today ) && 'range' === Replay::own_sale_date_check( '0026-11-01', null, $today ) && 'same' === Replay::own_sale_date_check( '2026-11-02', '2026-11-02', $today ) && 'same' === Replay::own_sale_date_check( '', null, $today ) && 'ok' === Replay::own_sale_date_check( '', '2026-11-02', $today ) && 'ok' === Replay::own_sale_date_check( '2026-11-03', '2026-11-02', $today ) );

$q   = fh_batch( '2026-10-01', '2026-10-15' );
$qs  = $q['store'];
$qid = $q['order_id'];
list( $q1, $q2 ) = $q['stays'];
$GLOBALS['fh_current_user']                    = 5;
$GLOBALS['fh_user_caps']['manage_woocommerce'] = array( 5 );
$GLOBALS['fh_log']                             = array();
$qraw = static function ( $date ) use ( $qid ) {
	return array(
		'order_id'  => (string) $qid,
		'_wpnonce'  => wp_create_nonce( Admin_Workbook::NONCE_SALE_DATE . $qid ),
		'op'        => 'save',
		'sale_date' => $date,
	);
};
$far = 0;
foreach ( array( '0026-10-30', '1999-12-31', '2028-10-20', '9999-12-31' ) as $date ) {
	$r = Admin_Workbook::sale_date_request( $qs, $qraw( $date ), $today );
	if ( 400 === $r['status'] && $date . ' is outside the dates a sale can be set to (2000-01-01 to 2028-10-19). Nothing was changed.' === $r['message'] && '2026-10-15' === $qs->order( $qid )['sale_date'] ) {
		$far++;
	}
}
check( 'the batch calendar refuses a date outside 2000-01-01 – two years after today: 400 each, the date kept, nothing logged', 4 === $far && array() === $GLOBALS['fh_log'] );
$r = Admin_Workbook::sale_date_request( $qs, $qraw( '2028-10-19' ), $today );
check( '… and takes the last day allowed (logged)', 200 === $r['status'] && '2028-10-19' === $qs->order( $qid )['sale_date'] && 1 === count( $GLOBALS['fh_log'] ) );
$r = Admin_Workbook::sale_date_request( $qs, $qraw( '0026-10-30' ) );
check( 'without a date passed the handler bounds by the site date', 400 === $r['status'] && false !== strpos( $r['message'], 'to ' . Replay::sale_date_max( Clean_Room::today() ) . ')' ) );
$qs->update_order_fields( $qid, array( 'sale_date' => '2026-10-15' ) );

$qa = $qs->stay( $q1, $today, $settings );
check( 'the Stays tab prompt on a group with a batch date and no own date opens empty (OK keeps the batch date)', '' === Admin::sale_prompt_default( $qa ) && '2026-10-29' === $qa['sale_ready_suggested'] );
$qs->record_event( $q2, array( 'type' => 'note', 'note' => Replay::NOTE_SALE_READY . '2026-10-16', 'event_at' => gmdate( 'Y-m-d H:i:s' ) ), $today, $settings );
$qb = $qs->stay( $q2, $today, $settings );
check( '… with its own date: that date; with no date at all: the suggestion', '2026-10-16' === Admin::sale_prompt_default( $qb ) && '2026-10-29' === Admin::sale_prompt_default( array_merge( $qa, array( 'batch_sale_date' => null ) ) ) && '' === Admin::sale_prompt_default( array() ) );
check( 'the Stays tab with the prompt pre-filled and OK pressed: own_sale_date_check says "same" (nothing written)', 'same' === Replay::own_sale_date_check( Admin::sale_prompt_default( $qb ), $qb['sale_ready_override'], $today ) && 'same' === Replay::own_sale_date_check( Admin::sale_prompt_default( $qa ), $qa['sale_ready_override'], $today ) );

check( 'both groups still in the Spa and on sale: ready_in_spa', true === $qa['ready_in_spa'] && true === $qb['ready_in_spa'] );
check( 'the stays CSV state cell: "ready (still in the Spa)"; a cleared group for sale stays "ready"', 'ready (still in the Spa)' === Admin::csv_row( $qa )[7] && 'ready' === Admin::csv_row( array_merge( $qa, array( 'ready_in_spa' => false ) ) )[7] && 'in QT' === Admin::state_cell( array( 'state_label' => 'in QT' ) ) );
$xrows = Admin_Workbook::stays_rows( array( $qa ) );
check( '… and the XLSX stays sheet the same', 'state' === $xrows[0][7] && 'ready (still in the Spa)' === $xrows[1][7], json_encode( $xrows ) );

$qstays = $qs->open_stays( array( 'order_id' => $qid ), $today, $settings );
$qpanel = Admin_Workbook::batch_sale_panel( $qs->order( $qid ), $qstays, array(), $settings );
check( 'the order page card counts the batch-dated groups still in the Spa and flags each own-dated one', 1 === $qpanel['applies'] && 1 === $qpanel['in_spa'] && 1 === count( $qpanel['own'] ) && true === $qpanel['own'][0]['in_spa'] && true === $qpanel['own'][0]['ready'], json_encode( $qpanel ) );
check( '… and the line under the picker says so', 'Applies to 1 group still in tanks — for sale since 2026-10-15. 1 of them is still in the Spa.' === Admin_Workbook::batch_sale_status( $qpanel, $today ) && 'Applies to 2 groups still in tanks — for sale since 2026-10-15. 2 of them are still in the Spa.' === Admin_Workbook::batch_sale_status( array( 'applies' => 2, 'in_spa' => 2, 'open' => 2, 'date' => '2026-10-15' ), $today ) && 'Applies to 1 group still in tanks — for sale since 2026-10-15.' === Admin_Workbook::batch_sale_status( array( 'applies' => 1, 'in_spa' => 0, 'open' => 1, 'date' => '2026-10-15' ), $today ) );

$qs->record_event( $q2, array( 'type' => 'note', 'note' => Replay::NOTE_SALE_READY . '2026-11-30', 'event_at' => gmdate( 'Y-m-d H:i:s' ) ), $today, $settings );
$building = array(
	array( 'status' => 'active', 'occupants' => array( $qs->stay( $q1, $today, $settings ) ) ),
	array( 'status' => 'active', 'occupants' => array( $qs->stay( $q2, $today, $settings ) ) ),
);
$bcount = Admin::totals( $building );
check( 'the Board\'s building line (Admin::totals over its tanks, as the summary): two groups in the Spa, one past its batch date → 1 in QT, 1 for sale', 1 === $bcount['in_qt'] && 1 === $bcount['ready'] && 1 === $bcount['ready_in_spa'], json_encode( $bcount ) );

fh_finish();
