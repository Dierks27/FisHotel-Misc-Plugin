<?php
/**
 * Sale dates — a group goes on sale on a CHOSEN date: its own (a
 * "sale_ready:" note event), else its batch's (the supplier order's
 * sale_date), else none. On the in-memory Fake_Store: the precedence
 * through a real check-in, the boundary (the date itself counts, in the
 * site zone), closed stays never for sale, the "still in the Spa" warning
 * on every reader, the Replay history unchanged apart from the rule, the
 * schema's new column, the product facts with a null date and the Front
 * Desk's words.
 *
 * Run from the plugin root:  php includes/sections/clean-room/tests/sale-dates-test.php
 */

require_once __DIR__ . '/wp-stubs.php';

use FisHotel\Misc\Sections\Clean_Room\Admin;
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
$r = Front_Desk::perform( 'ready', $ctx, array( 'stay_id' => $w['id'], 'date' => '' ) );
check( 'perform("ready") with nothing typed on a group without an own date: the batch\'s toast with the warning', 'Blue Test Wrasse back to the batch date (2026-10-15) · still in the Spa' === ( $r['message'] ?? '' ), json_encode( $r ) );
$r = Front_Desk::perform( 'ready', $ctx, array( 'stay_id' => $w['id'], 'date' => '2026-12-01' ) );
check( 'perform("ready") with a date: the own date, written as the note event', 'Blue Test Wrasse on sale 2026-12-01' === ( $r['message'] ?? '' ) && 'sale_ready:2026-12-01' === $store->last_event()['note'] && false === $store->stay( $w['id'], $today, $settings )['sale_ready'] );
$i18n = Front_Desk::i18n();
check( 'the screen strings name the chosen date, never "N days after check-in"', 'on sale %s' === $i18n['readyOn'] && isset( $i18n['readyBatch'], $i18n['readyNone'], $i18n['stillInSpa'], $i18n['noSaleDate'] ) && ! isset( $i18n['readyAuto'] ) );

fh_finish();
