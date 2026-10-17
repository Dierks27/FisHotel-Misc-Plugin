<?php
/**
 * Orders — the supplier order on the in-memory Fake_Store: the header
 * and lines from a form, create / save (upsert + delete), the check-in
 * that creates the stays and the DOA loss with the seam fields filled, a
 * second check-in that creates nothing, the per-line live figures from
 * the stay's events (undos honoured), the Spa-tank fallback, the order
 * list and the refs.
 *
 * Run from the plugin root:  php includes/sections/clean-room/tests/orders-test.php
 */

require_once __DIR__ . '/wp-stubs.php';

use FisHotel\Misc\Sections\Clean_Room\Costing;
use FisHotel\Misc\Sections\Clean_Room\Orders;
use FisHotel\Misc\Sections\Clean_Room\Replay;
use FisHotel\Misc\Sections\Clean_Room\Settings;
use FisHotel\Misc\Sections\Clean_Room\Species;
use FisHotel\Misc\Sections\Clean_Room\Tests\Fake_Store;

function near( $a, $b, $eps = 1e-9 ) {
	return null !== $a && null !== $b && abs( (float) $a - (float) $b ) < $eps;
}

$today    = Replay::date_in_zone( time(), wp_timezone() );
$settings = Settings::get();
$store    = new Fake_Store( array( 12 => 'Blue Test Wrasse', 13 => 'Orange Test Clown' ) );
$base     = array( 'actor_id' => 8, 'source' => 'manual' );

$wrasse = $store->save_species( Species::normalize( array( 'name' => 'Blue Test Wrasse', 'sci_name' => 'Halichoeres testus', 'per_box' => 8, 'my_price' => 40, 'product_id' => 12, 'group_name' => 'Wrasses' ) ) );
$goby   = $store->save_species( Species::normalize( array( 'name' => 'Red Test Goby', 'per_box' => 24, 'my_price' => 20, 'group_name' => 'Gobies' ) ) );

echo "1. The header from a form\n";
$h = Orders::normalize_order( array( 'id' => '0', 'ref' => '  2026-1 ', 'supplier' => ' Test  Supplier ', 'origin' => 'Testland', 'order_date' => '2026-03-01', 'received_date' => 'soon', 'status' => 'Shipped', 'boxes' => '2', 'cost_mode' => 'BAG', 'tariffs' => '$1,234.5', 'domestic_flight' => '-5', 'import_fees' => 'x', 'credit' => '10', 'markup' => '0', 'worth_threshold' => '', 'schedule' => '14 Day CM', 'building_id' => '5', 'notes' => ' n ' ) );
check( 'trimmed, dated, defaulted: unknown status → draft, mode bag, money read, negatives and junk → 0, markup 0 → 4, threshold blank → 2.75', '2026-1' === $h['ref'] && 'Test Supplier' === $h['supplier'] && '2026-03-01' === $h['order_date'] && null === $h['received_date'] && 'draft' === $h['status'] && 2 === $h['boxes'] && 'bag' === $h['cost_mode'] && near( $h['tariffs'], 1234.5 ) && near( $h['domestic_flight'], 0 ) && near( $h['import_fees'], 0 ) && near( $h['credit'], 10 ) && near( $h['markup'], 4 ) && near( $h['worth_threshold'], 2.75 ) && '14 Day CM' === $h['schedule'] && 5 === $h['building_id'] && 'n' === $h['notes'] );
$h = Orders::normalize_order( array() );
check( 'nothing at all: an empty draft in box mode, boxes null', '' === $h['ref'] && 'draft' === $h['status'] && 'box' === $h['cost_mode'] && null === $h['boxes'] && null === $h['building_id'] && null === $h['order_date'] );

echo "2. The lines from a form\n";
$lines = Orders::normalize_lines(
	array(
		array( 'species' => ' Blue  Test Wrasse ', 'species_id' => $wrasse, 'qty_ordered' => '10', 'qty_received' => '8', 'per_box' => '0', 'bags' => '', 'unit_price' => '$5.1234', 'worth' => '40', 'qty_doa' => '1', 'qty_survived' => '', 'tank_id' => '0', 'notes' => 'first' ),
		array( 'species' => '', 'qty_ordered' => '5' ),
		array( 'species' => 'Red Test Goby', 'species_id' => $goby, 'qty_ordered' => '20', 'qty_received' => '12', 'per_box' => '12', 'unit_price' => '2.5', 'worth' => '20', 'qty_doa' => '-3', 'qty_survived' => '10' ),
		'junk',
		array( 'species' => 'Green Test Blenny', 'qty_ordered' => '5', 'qty_received' => '', 'unit_price' => '10', 'worth' => '30' ),
	)
);
check( 'empty species and junk dropped, sort = position', 3 === count( $lines ) && array( 0, 1, 2 ) === array_column( $lines, 'sort' ) );
check( 'typed: per_box 0 → null, blank received → null, a 4-decimal price, a negative DOA → 0, blank survived → null', 'Blue Test Wrasse' === $lines[0]['species'] && $wrasse === $lines[0]['species_id'] && null === $lines[0]['per_box'] && null === $lines[0]['bags'] && near( $lines[0]['unit_price'], 5.1234 ) && null === $lines[0]['qty_survived'] && null === $lines[0]['tank_id'] && 8 === $lines[0]['qty_received'] && 0 === $lines[1]['qty_doa'] && 12 === $lines[1]['per_box'] && null === $lines[2]['qty_received'] && 'first' === $lines[0]['notes'] );

echo "3. Create an order, save its lines, re-save (upsert + delete)\n";
check( 'next_ref: the highest N of the year + 1, from 1', '2026-11' === Orders::next_ref( array( '2026-3', '2026-10', '2025-4', 'x' ), 2026 ) && '2026-1' === Orders::next_ref( array(), 2026 ) && '2025-5' === Orders::next_ref( array( '2026-3', '2026-10', '2025-4' ), 2025 ) );
$spa500 = null;
foreach ( $store->tanks() as $t ) {
	if ( 500 === (int) $t['building']['number'] ) {
		$spa500 = (int) $t['building']['id'];
		break;
	}
}
$header   = Orders::normalize_order( array( 'ref' => Orders::next_ref( $store->order_refs(), 2026 ), 'supplier' => 'Test Supplier One', 'origin' => 'Testland', 'order_date' => '2026-03-01', 'status' => 'ordered', 'cost_mode' => 'box', 'tariffs' => 30, 'domestic_flight' => 100, 'import_fees' => 200, 'credit' => 10, 'building_id' => $spa500 ) );
$order_id = $store->save_order( $header );
check( 'created with ref 2026-1', 1 === $order_id && '2026-1' === $store->order( 1 )['ref'] && 'ordered' === $store->order( 1 )['status'] && $spa500 === $store->order( 1 )['building_id'] );
check( 'a second order with the same ref is refused', false === $store->save_order( $header ) );
check( 'an order needs a ref', false === $store->save_order( Orders::normalize_order( array( 'supplier' => 'x' ) ) ) );
$saved = $store->save_order_lines( $order_id, $lines );
check( 'three lines inserted', array( 1, 2, 3 ) === $saved['ids'] && 0 === $saved['deleted'] && 3 === count( $store->order( 1 )['lines'] ) && 3 === $store->order( 1 )['line_count'] );
$o = $store->order( 1 );
check( 'the lines carry the species row\'s per box / group / product and no stay yet', 8 === $o['lines'][0]['species_per_box'] && 'Wrasses' === $o['lines'][0]['group_name'] && 12 === $o['lines'][0]['species_product_id'] && null === $o['lines'][0]['stay_id'] && 24 === $o['lines'][1]['species_per_box'] && null === $o['lines'][2]['species_per_box'] );
$again   = $lines;
$again[0]['id']          = 1;
$again[0]['qty_received'] = 9;
unset( $again[1] ); // Line 2 removed.
$again[2]['id'] = 3;
$again[]        = Orders::normalize_line( array( 'species' => 'Yellow Test Goby', 'qty_ordered' => 4, 'qty_received' => 4, 'unit_price' => 1, 'worth' => 10, 'qty_survived' => 4 ) );
$saved          = $store->save_order_lines( $order_id, array_values( $again ) );
check( 're-save: 1 and 3 updated, 2 deleted, 4 inserted, in form order', array( 1, 3, 4 ) === $saved['ids'] && 1 === $saved['deleted'] && array( 1, 3, 4 ) === array_column( $store->order( 1 )['lines'], 'id' ) && 9 === $store->order( 1 )['lines'][0]['qty_received'] && array( 0, 1, 2 ) === array_column( $store->order( 1 )['lines'], 'sort' ) );
$again[3]['id'] = 4;
$saved          = $store->save_order_lines( $order_id, array_merge( array_values( $again ), array( array_merge( $lines[1], array( 'id' => 99 ) ) ) ) );
check( 'a line id that is not this order\'s is not touched: a new row is made instead', array( 1, 3, 4, 5 ) === $saved['ids'] && 0 === $saved['deleted'] );
$saved = $store->save_order_lines( $order_id, array_values( $again ) );
check( 'and dropping it again deletes it, the three stay', array( 1, 3, 4 ) === $saved['ids'] && 1 === $saved['deleted'] );
check( 'the summary of a resolved order before any check-in: 19 ordered, 13 received, typed survivors', 19 === Orders::summary( Orders::resolve( $store->order( 1 ), array() ) )['ordered'] && 13 === Orders::summary( Orders::resolve( $store->order( 1 ), array() ) )['received'] && 4 === Orders::summary( Orders::resolve( $store->order( 1 ), array() ) )['survived'] );

echo "4. Check in the received fish\n";
$order = $store->order( 1 );
$r     = Orders::check_in( $store, $order, $today, $settings, $base );
check( 'two stays created (the blenny received nothing → skipped), none failed', 2 === count( $r['created'] ) && 1 === $r['skipped'] && array() === $r['errors'] && $today === $r['date'], json_encode( $r ) );
$first = $r['created'][0];
$s1    = $store->stay( $first['stay_id'], $today, $settings );
check( 'the wrasse: 9 arrived, 1 DOA → 8 live, in the first empty Spa tank of building 500 (511), today', 9 === $s1['qty_arrived'] && 8 === $s1['qty_live'] && 511 === $s1['tank_number'] && 511 === $first['tank'] && $today === $s1['checkin_date'] && 'in_qt' === $s1['status'] );
check( 'the seam fields: source order, the line, the supplier, the ref, the product from the species row, the landed cost per fish', 'order' === $s1['source'] && 1 === $s1['order_line_id'] && 'Test Supplier One' === $s1['supplier'] && '2026-1' === $s1['order_ref'] && 12 === $s1['product_id'] && 'Blue Test Wrasse' === $s1['species'] && is_float( $s1['landed_cost'] ) && $s1['landed_cost'] > 0 );
$costed = Orders::resolve( $store->order( 1 ), array() );
check( 'that landed cost is the costing\'s landed per fish, to the cent', near( $s1['landed_cost'], Costing::cents( $costed['lines'][0]['landed_per_fish'] ) ) );
$ev = $store->events( $first['stay_id'] );
check( 'two events: the arrival into 511 and a loss of 1 noted doa, both source order', 2 === count( $ev ) && 'arrival' === $ev[0]['type'] && 9 === $ev[0]['qty'] && 511 === $ev[0]['to_tank_number'] && 'order' === $ev[0]['source'] && 'loss' === $ev[1]['type'] && 1 === $ev[1]['qty'] && 'doa' === $ev[1]['note'] && 8 === $ev[1]['actor_id'] );
$second = $r['created'][1];
$s2     = $store->stay( $second['stay_id'], $today, $settings );
check( 'the yellow goby (no species row): 4 live, no product, the next empty tank 512, no DOA loss', 4 === $s2['qty_live'] && null === $s2['product_id'] && 512 === $s2['tank_number'] && 1 === count( $store->events( $second['stay_id'] ) ) );
$o = $store->order( 1 );
check( 'the lines point at their stays and tanks; the order is received, dated today', $first['stay_id'] === $o['lines'][0]['stay_id'] && 511 === $o['lines'][0]['tank_number'] && $second['stay_id'] === $o['lines'][2]['stay_id'] && null === $o['lines'][1]['stay_id'] && 'received' === $o['status'] && $today === $o['received_date'] );
$text = Orders::check_in_message( $r );
check( 'the message', '2 stays created in Tank 511, 512. 1 lines skipped (already checked in or nothing received).' === $text, $text );

echo "5. A second check-in creates nothing\n";
$n = count( $store->stays );
$e = $store->event_count();
$r = Orders::check_in( $store, $store->order( 1 ), $today, $settings, $base );
check( 'every line skipped, no stay, no event', 0 === count( $r['created'] ) && 3 === $r['skipped'] && $n === count( $store->stays ) && $e === $store->event_count() );
check( 'the message says so', 'Nothing to check in: every line with fish already has a stay. 3 lines skipped (already checked in or nothing received).' === Orders::check_in_message( $r ) );

echo "6. Live figures from the stay\n";
$store->record_event( $first['stay_id'], array_merge( $base, array( 'type' => 'loss', 'qty' => 1, 'note' => 'jumped', 'event_at' => gmdate( 'Y-m-d H:i:s' ) ) ), $today, $settings );
$store->record_event( $first['stay_id'], array_merge( $base, array( 'type' => 'sold', 'qty' => 2, 'event_at' => gmdate( 'Y-m-d H:i:s' ) ) ), $today, $settings );
$f = $store->stay_figures( array( $first['stay_id'], $second['stay_id'], 999 ) );
check( 'stay 1: arrived 9, lost 2 (every reason), sold 2, doa 1, live 5, survived 7', array( 'arrived' => 9, 'lost' => 2, 'sold' => 2, 'doa' => 1, 'live' => 5, 'survived' => 7 ) === $f[ $first['stay_id'] ], json_encode( $f ) );
check( 'stay 2: nothing lost; an unknown stay id: zeros', 4 === $f[ $second['stay_id'] ]['survived'] && 0 === $f[ $second['stay_id'] ]['doa'] && 0 === $f[999]['arrived'] );
$o = Orders::resolve( $store->order( 1 ), $f );
check( 'the linked line reads survived 7 / DOA 1 / live 5 / sold 2 from the stay; the unlinked blenny keeps its typed figures', true === $o['lines'][0]['linked'] && 7 === $o['lines'][0]['survived'] && 1 === $o['lines'][0]['doa'] && 5 === $o['lines'][0]['live'] && 2 === $o['lines'][0]['sold'] && 7 === $o['lines'][0]['qty_survived'] && false === $o['lines'][1]['linked'] && null === $o['lines'][1]['live'] );
check( 'the costing follows: survived 11 of (13 − 1), revenue 40 × 7 + 10 × 4', 11 === $o['costing']['survived'] && near( $o['costing']['survival_rate'], 11 / 12 ) && near( $o['costing']['revenue'], 320 ) );
$doa_event = $ev[1];
$store->undo_event( $store->stay( $first['stay_id'], $today, $settings ), $doa_event, $base, $today, $settings );
$f = $store->stay_figures( array( $first['stay_id'] ) );
check( 'undoing the DOA loss: doa 0, lost 1, survived 8', 0 === $f[ $first['stay_id'] ]['doa'] && 1 === $f[ $first['stay_id'] ]['lost'] && 8 === $f[ $first['stay_id'] ]['survived'] && 6 === $f[ $first['stay_id'] ]['live'] );
$again[0]['qty_received'] = 1;
$again[0]['qty_doa']      = 7;
$again[0]['tank_id']      = 99;
$saved                    = $store->save_order_lines( 1, array( $again[0] ) );
$o                        = $store->order( 1 );
check( 'a linked line keeps its received / DOA / tank on re-save, and a linked line left out of the form is kept too', 9 === $o['lines'][0]['qty_received'] && 1 === $o['lines'][0]['qty_doa'] && 511 === $o['lines'][0]['tank_number'] && in_array( 4, $saved['ids'], true ) && 1 === $saved['deleted'] && 2 === count( $o['lines'] ) );
check( 'an order with stays cannot be deleted', 'referenced' === $store->delete_order( 1 ) && null !== $store->order( 1 ) );

echo "7. The tank fallback\n";
$spa = Orders::spa_tanks( $store );
check( 'nine active Spa tanks by number: 511 … 524, 611, 621', array( 511, 512, 513, 521, 522, 523, 524, 611, 621 ) === array_column( $spa, 'number' ) );
$by_number = array();
foreach ( $spa as $t ) {
	$by_number[ (int) $t['number'] ] = (int) $t['id'];
}
$b600 = $spa[7]['building_id'];
check( 'the line\'s own Spa tank wins, even when occupied', 621 === Orders::pick_tank( $spa, array( $by_number[621] ), $spa500, $by_number[621] )['number'] );
check( 'a Clean Room tank on the line is ignored: the first empty tank of the building', 513 === Orders::pick_tank( $spa, array( $by_number[511], $by_number[512] ), $spa500, 7 )['number'] );
check( 'the building full: any empty Spa tank', 611 === Orders::pick_tank( $spa, array( $by_number[511], $by_number[512], $by_number[513], $by_number[521], $by_number[522], $by_number[523], $by_number[524] ), $spa500, null )['number'] );
check( 'everything full: the first tank of the building, else the first Spa tank', 511 === Orders::pick_tank( $spa, array_values( $by_number ), $spa500, null )['number'] && 611 === Orders::pick_tank( $spa, array_values( $by_number ), $b600, null )['number'] && 511 === Orders::pick_tank( $spa, array_values( $by_number ), null, null )['number'] );
check( 'no Spa tanks at all → null', null === Orders::pick_tank( array(), array(), $spa500, null ) );
$big = $store->save_order( Orders::normalize_order( array( 'ref' => '2026-2', 'supplier' => 'Test Supplier Two', 'received_date' => Replay::add_days( $today, -3 ), 'building_id' => $spa500 ) ) );
$rows = array();
for ( $i = 1; $i <= 10; $i++ ) {
	$rows[] = array( 'species' => 'Test Fish ' . $i, 'qty_ordered' => 2, 'qty_received' => 2, 'unit_price' => 1, 'worth' => 5 );
}
$store->save_order_lines( $big, Orders::normalize_lines( $rows ) );
$r = Orders::check_in( $store, $store->order( $big ), $today, $settings, $base );
check( 'ten lines: the five empty tanks of 500, then the two of 600, then back to 511 (511 and 512 hold the first order)', array( 513, 521, 522, 523, 524, 611, 621, 511, 511, 511 ) === array_column( $r['created'], 'tank' ), json_encode( array_column( $r['created'], 'tank' ) ) );
$s = $store->stay( $r['created'][0]['stay_id'], $today, $settings );
check( 'a received date in the past: the stays check in on it (day 4), the arrival dated noon that day', Replay::add_days( $today, -3 ) === $s['checkin_date'] && 4 === $s['day'] && Replay::add_days( $today, -3 ) === Replay::event_date( $store->events( $s['id'] )[0]['event_at'], wp_timezone() ) );
$future = $store->save_order( Orders::normalize_order( array( 'ref' => '2026-3', 'received_date' => Replay::add_days( $today, 5 ) ) ) );
$store->save_order_lines( $future, Orders::normalize_lines( array( array( 'species' => 'Test Fish', 'qty_received' => 1 ) ) ) );
$r = Orders::check_in( $store, $store->order( $future ), $today, $settings, $base );
check( 'a received date in the future is clamped to today', $today === $r['date'] && $today === $store->stay( $r['created'][0]['stay_id'] )['checkin_date'] );

echo "8. Lists, years and refs\n";
check( 'orders newest first by received / order date; the year filter; the supplier filter', array( '2026-3', '2026-1', '2026-2' ) === array_column( $store->orders(), 'ref' ) && 3 === count( $store->orders( array( 'year' => 2026 ) ) ) && 0 === count( $store->orders( array( 'year' => 2025 ) ) ) && array( '2026-2' ) === array_column( $store->orders( array( 'supplier' => 'Test Supplier Two' ) ), 'ref' ) );
check( 'order_years, order_refs, pick lists', array( 2026 ) === $store->order_years() && array( '2026-1', '2026-2', '2026-3' ) === $store->order_refs() && array( 'Test Supplier One', 'Test Supplier Two' ) === $store->order_pick_lists()['suppliers'] );
check( 'status filter and order_by_ref', 3 === count( $store->orders( array( 'status' => 'received' ) ) ) && 2 === $store->order_by_ref( '2026-2' )['id'] && null === $store->order_by_ref( 'nope' ) );
$draft = $store->save_order( Orders::normalize_order( array( 'ref' => '2026-4' ) ) );
$store->save_order_lines( $draft, Orders::normalize_lines( array( array( 'species' => 'Test Fish', 'qty_ordered' => 1 ) ) ) );
check( 'a draft without stays can be deleted, lines and all', true === $store->delete_order( $draft ) && null === $store->order( $draft ) && array() === $store->order_lines( $draft ) );
check( 'update_order_fields changes some header fields and never the ref', $store->update_order_fields( 1, array( 'status' => 'closed', 'ref' => 'hack', 'credit' => 3 ) ) && 'closed' === $store->order( 1 )['status'] && '2026-1' === $store->order( 1 )['ref'] && near( $store->order( 1 )['credit'], 3 ) );

fh_finish();
