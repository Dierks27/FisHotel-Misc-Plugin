<?php
/**
 * Stats — the yearly figures over invented orders: the weighted Σ ÷ Σ
 * totals against the sheet-style unweighted averages (a batch that
 * received nothing left out, a batch whose survivors are unknown left
 * out of the survival average only), the groupings by supplier, origin
 * and species group, and the year of an order; then two or more years
 * side by side (the change between consecutive years — absolute, percent
 * where one is defined, rates in points — and no figure, never a zero,
 * for a supplier, origin or group with no order in a year), one row per
 * fish and year (adding up to the year's totals), and the sort and the
 * search the report views use.
 *
 * Run from the plugin root:  php includes/sections/clean-room/tests/stats-test.php
 */

require_once __DIR__ . '/wp-stubs.php';

use FisHotel\Misc\Sections\Clean_Room\Admin_Stats;
use FisHotel\Misc\Sections\Clean_Room\Stats;

function near( $a, $b, $eps = 1e-9 ) {
	return null !== $a && null !== $b && abs( (float) $a - (float) $b ) < $eps;
}

/*
 * No fees anywhere, so landed = received × price and the figures stay round.
 *
 * A  2026, Test Supplier One, Testland, received 2026-03-01
 *    Wrasses:    ordered 10, received 8,  DOA 1, survived 6,  price 5, worth 40 → landed 40, revenue 240
 *    (no group): ordered 10, received 10, DOA 0, survived 10, price 2, worth 20 → landed 20, revenue 200
 *    → ordered 20, received 18, DOA 1, survived 16; fill 0.9, survival 16/17, DOA 1/18; total 60, revenue 440
 * B  2026, Test Supplier Two, Testland, ordered 2026-05-01 (not received yet — the year comes from the order date)
 *    Wrasses:    ordered 20, received 10, DOA 2, survived 4, price 4, worth 30 → landed 40, revenue 120
 *    → fill 0.5, survival 4/8 = 0.5, DOA 0.2
 * C  2026, Test Supplier One, Elsewhere, received 2026-07-01
 *    Blennies:   ordered 10, received 0 → nothing landed; fill 0, survival and DOA undefined
 * D  2025, Test Supplier Two, Testland, received 2025-11-11
 *    Gobies:     ordered 5, received 5, DOA 0, survived — (not typed), price 1, worth 10 → landed 5, revenue 0
 *    → fill 1, survival unknown, DOA 0
 */
$orders = array(
	array(
		'id' => 1, 'ref' => '2026-1', 'supplier' => 'Test Supplier One', 'origin' => 'Testland', 'received_date' => '2026-03-01', 'order_date' => '2026-02-20', 'status' => 'received', 'cost_mode' => 'box',
		'lines' => array(
			array( 'species' => 'Blue Test Wrasse', 'group_name' => 'Wrasses', 'qty_ordered' => 10, 'qty_received' => 8, 'qty_doa' => 1, 'qty_survived' => 6, 'unit_price' => 5, 'worth' => 40, 'per_box' => 8 ),
			array( 'species' => 'Red Test Goby', 'group_name' => '', 'qty_ordered' => 10, 'qty_received' => 10, 'qty_doa' => 0, 'qty_survived' => 10, 'unit_price' => 2, 'worth' => 20, 'per_box' => 10 ),
		),
	),
	array(
		'id' => 2, 'ref' => '2026-2', 'supplier' => 'Test Supplier Two', 'origin' => 'testland', 'received_date' => null, 'order_date' => '2026-05-01', 'status' => 'ordered', 'cost_mode' => 'bag',
		'lines' => array(
			array( 'species' => 'Blue Test Wrasse', 'group_name' => 'Wrasses', 'qty_ordered' => 20, 'qty_received' => 10, 'qty_doa' => 2, 'qty_survived' => 4, 'unit_price' => 4, 'worth' => 30 ),
		),
	),
	array(
		'id' => 3, 'ref' => '2026-3', 'supplier' => 'Test Supplier One', 'origin' => 'Elsewhere', 'received_date' => '2026-07-01', 'status' => 'received', 'cost_mode' => 'box',
		'lines' => array(
			array( 'species' => 'Green Test Blenny', 'group_name' => 'Blennies', 'qty_ordered' => 10, 'qty_received' => 0, 'qty_doa' => 0, 'qty_survived' => null, 'unit_price' => 3, 'worth' => 30 ),
		),
	),
	array(
		'id' => 4, 'ref' => '2025-9', 'supplier' => 'Test Supplier Two', 'origin' => 'Testland', 'received_date' => '2025-11-11', 'status' => 'closed', 'cost_mode' => 'box',
		'lines' => array(
			array( 'species' => 'Yellow Test Goby', 'group_name' => 'Gobies', 'qty_ordered' => 5, 'qty_received' => 5, 'qty_doa' => 0, 'qty_survived' => null, 'unit_price' => 1, 'worth' => 10 ),
		),
	),
);

echo "1. The year of an order\n";
check( 'received date first, then the order date, then created_at, else null', 2026 === Stats::order_year( array( 'received_date' => '2026-03-01', 'order_date' => '2025-12-30' ) ) && 2026 === Stats::order_year( array( 'received_date' => null, 'order_date' => '2026-05-01' ) ) && 2024 === Stats::order_year( array( 'created_at' => '2024-01-02 03:04:05' ) ) && null === Stats::order_year( array() ) && null === Stats::order_year( array( 'received_date' => '0000-00-00' ) ) );

echo "2. Per-order rows\n";
$s    = Stats::build( $orders );
$rows = $s['orders'];
check( 'four rows, the years newest first', 4 === count( $rows ) && array( 2026, 2025 ) === $s['years'] );
$a = $rows[0];
check( 'A: 20 ordered, 18 received, 16 survived, 1 DOA, fill 0.9, survival 16/17, DOA 1/18, total 60, revenue 440, up/down 380', '2026-1' === $a['ref'] && 2026 === $a['year'] && 20 === $a['ordered'] && 18 === $a['received'] && 16 === $a['survived'] && 1 === $a['doa'] && near( $a['fill_rate'], 0.9 ) && near( $a['survival_rate'], 16 / 17 ) && near( $a['doa_rate'], 1 / 18 ) && near( $a['total'], 60 ) && near( $a['revenue'], 440 ) && near( $a['up_down'], 380 ) && 2 === $a['lines'] && true === $a['survived_known'] );
$b = $rows[1];
check( 'B (bag mode, year from the order date): fill 0.5, survival 0.5, DOA 0.2', 2026 === $b['year'] && 'bag' === $b['mode'] && near( $b['fill_rate'], 0.5 ) && near( $b['survival_rate'], 0.5 ) && near( $b['doa_rate'], 0.2 ) && near( $b['total'], 40 ) && near( $b['revenue'], 120 ) );
$c = $rows[2];
check( 'C (nothing received): fill 0, survival and DOA undefined, 0 landed', near( $c['fill_rate'], 0 ) && null === $c['survival_rate'] && null === $c['doa_rate'] && near( $c['total'], 0 ) && 0 === $c['received'] );
$d = $rows[3];
check( 'D (survivors not typed): survival unknown, fill 1, DOA 0, revenue 0', 2025 === $d['year'] && null === $d['survival_rate'] && false === $d['survived_known'] && near( $d['fill_rate'], 1 ) && near( $d['doa_rate'], 0 ) && near( $d['revenue'], 0 ) && near( $d['total'], 5 ) );

echo "3. Weighted totals (Σ ÷ Σ)\n";
$t = $s['totals'];
check( '4 orders, 5 lines, 55 ordered, 33 received, 3 DOA', 4 === $t['orders'] && 5 === $t['lines'] && 55 === $t['ordered'] && 33 === $t['received'] && 3 === $t['doa'] );
check( 'fill 33/55, DOA 3/33', near( $t['fill_rate'], 33 / 55 ) && near( $t['doa_rate'], 3 / 33 ) );
check( 'survival over the orders whose survivors are known only: (16 + 4) ÷ ((18 + 10) − (1 + 2)) = 0.8', near( $t['survival_rate'], 0.8 ) && 20 === $t['survived'] && true === $t['survived_known'] );
check( 'total 105, revenue 560, up/down 455, ratio 560/105', near( $t['total'], 105 ) && near( $t['revenue'], 560 ) && near( $t['up_down'], 455 ) && near( $t['ratio'], 560 / 105 ) );

echo "4. Sheet-style (as the spreadsheet averaged them)\n";
$sh = $s['sheet'];
check( '3 batches received anything (C is out)', 3 === $sh['batches'] );
check( 'fill = (0.9 + 0.5 + 1) ÷ 3 = 0.8 over 3', near( $sh['fill_rate'], 0.8 ) && 3 === $sh['fill_n'] );
check( 'survival = (16/17 + 0.5) ÷ 2 — D is not in the formula yet', near( $sh['survival_rate'], ( 16 / 17 + 0.5 ) / 2 ) && 2 === $sh['survival_n'] );
check( 'DOA = (1/18 + 0.2 + 0) ÷ 3', near( $sh['doa_rate'], ( 1 / 18 + 0.2 ) / 3 ) && 3 === $sh['doa_n'] );
check( 'total fish through QT = Σ survived = 20', 20 === $sh['through_qt'] );
check( 'the weighted and the sheet-style survival differ (0.8 vs 0.72)', ! near( $t['survival_rate'], $sh['survival_rate'] ) );
$empty = Stats::build( array() );
check( 'no orders: empty rows, null rates, 0 through QT', array() === $empty['orders'] && null === $empty['sheet']['fill_rate'] && 0 === $empty['sheet']['through_qt'] && null === $empty['totals']['fill_rate'] && array() === $empty['by_group'] );

echo "5. By supplier / origin / species group\n";
$sup = $s['by_supplier'];
check( 'two suppliers, most fish received first', 2 === count( $sup ) && 'Test Supplier One' === $sup[0]['name'] && 'Test Supplier Two' === $sup[1]['name'] );
check( 'One: A + C → 2 orders, 30 ordered, 18 received, 16 survived, fill 0.6, survival 16/17', 2 === $sup[0]['orders'] && 30 === $sup[0]['ordered'] && 18 === $sup[0]['received'] && 16 === $sup[0]['survived'] && near( $sup[0]['fill_rate'], 0.6 ) && near( $sup[0]['survival_rate'], 16 / 17 ) && near( $sup[0]['total'], 60 ) && near( $sup[0]['revenue'], 440 ) );
check( 'Two: B + D → 25 ordered, 15 received, survival 4/8 (D unknown, left out)', 25 === $sup[1]['ordered'] && 15 === $sup[1]['received'] && near( $sup[1]['survival_rate'], 0.5 ) && 4 === $sup[1]['survived'] && near( $sup[1]['doa_rate'], 2 / 15 ) && near( $sup[1]['total'], 45 ) );
$org = $s['by_origin'];
check( 'origins merge case-insensitively: Testland (A + B + D) then Elsewhere', 2 === count( $org ) && 'Testland' === $org[0]['name'] && 33 === $org[0]['received'] && 3 === $org[0]['orders'] && 'Elsewhere' === $org[1]['name'] && 0 === $org[1]['received'] );
$grp = $s['by_group'];
check( 'groups: Wrasses, Gobies, Blennies, then (no group) last', array( 'Wrasses', 'Gobies', 'Blennies', '(no group)' ) === array_column( $grp, 'name' ), json_encode( array_column( $grp, 'name' ) ) );
check( 'Wrasses: A1 + B → 2 lines, 30 ordered, 18 received, 3 DOA, 10 survived, survival 10/15, landed 80, revenue 360', 2 === $grp[0]['lines'] && 30 === $grp[0]['ordered'] && 18 === $grp[0]['received'] && 3 === $grp[0]['doa'] && 10 === $grp[0]['survived'] && near( $grp[0]['survival_rate'], 10 / 15 ) && near( $grp[0]['total'], 80 ) && near( $grp[0]['revenue'], 360 ) && near( $grp[0]['up_down'], 280 ) );
check( 'Gobies: survivors unknown → survival null; (no group): A2', null === $grp[1]['survival_rate'] && 5 === $grp[1]['received'] && 10 === $grp[3]['received'] && near( $grp[3]['survival_rate'], 1 ) && near( $grp[3]['revenue'], 200 ) );
check( 'Blennies: nothing received, fill 0', 0 === $grp[2]['received'] && near( $grp[2]['fill_rate'], 0 ) && null === $grp[2]['doa_rate'] );
check( 'a group counts the orders with a line in it, once each: Wrasses 2 (A, B), Gobies 1, Blennies 1, (no group) 1', array( 2, 1, 1, 1 ) === array_column( $grp, 'orders' ), json_encode( array_column( $grp, 'orders' ) ) );
$twice = Stats::build( array( array_merge( $orders[0], array( 'lines' => array( $orders[0]['lines'][0], $orders[0]['lines'][0] ) ) ) ) );
check( 'two lines of one group in one order: 2 lines, 1 order', 2 === $twice['by_group'][0]['lines'] && 1 === $twice['by_group'][0]['orders'] );
$one_name = Stats::group_rows( $rows, 'supplier' );
check( 'group_rows() is what by_supplier used', $one_name === $sup );
check( 'an empty key groups as (none)', '(none)' === Stats::group_rows( array( array_merge( $rows[0], array( 'origin' => '' ) ) ), 'origin' )[0]['name'] );

echo "6. One year at a time\n";
$y2026 = array();
foreach ( $orders as $order ) {
	if ( 2026 === Stats::order_year( $order ) ) {
		$y2026[] = $order;
	}
}
$s26 = Stats::build( $y2026 );
check( '2026 only: 3 orders, 50 ordered, 28 received, through QT 20, sheet fill (0.9 + 0.5) ÷ 2', 3 === $s26['totals']['orders'] && 50 === $s26['totals']['ordered'] && 28 === $s26['totals']['received'] && 20 === $s26['sheet']['through_qt'] && near( $s26['sheet']['fill_rate'], 0.7 ) && 2 === $s26['sheet']['batches'] && array( 2026 ) === $s26['years'] );

echo "7. Year by year: the figures of each year\n";
$by_year = array();
foreach ( Stats::orders_by_year( $orders ) as $year => $list ) {
	$by_year[ $year ] = Stats::build( $list );
}
check( 'orders_by_year: newest year first, each order in its year (B by its order date)', array( 2026, 2025 ) === array_keys( Stats::orders_by_year( $orders ) ) && 3 === count( Stats::orders_by_year( $orders )[2026] ) && 1 === count( Stats::orders_by_year( $orders )[2025] ) );
check( 'order_date: received, else ordered, else the day it was created, else none', '2026-03-01' === Stats::order_date( $orders[0] ) && '2026-05-01' === Stats::order_date( $orders[1] ) && '2024-01-02' === Stats::order_date( array( 'created_at' => '2024-01-02 03:04:05' ) ) && '' === Stats::order_date( array() ) );
$c = Stats::compare( $by_year );
check( 'the years run oldest to newest whatever order they were given in', array( 2025, 2026 ) === $c['years'] && array( 2025, 2026 ) === Stats::compare( array( 2026 => $by_year[2026], 2025 => $by_year[2025] ) )['years'] );
$t = $c['totals'];
check( 'totals 2025: 1 order, 5 ordered, 5 received, fill 1, DOA 0, landed 5 — survivors and survival unknown (null, not 0)', 1 === $t['values']['orders'][2025] && 5 === $t['values']['ordered'][2025] && 5 === $t['values']['received'][2025] && near( $t['values']['fill_rate'][2025], 1 ) && null === $t['values']['survived'][2025] && null === $t['values']['survival_rate'][2025] && 0 === $t['values']['doa'][2025] && near( $t['values']['doa_rate'][2025], 0 ) && near( $t['values']['total'][2025], 5 ) && near( $t['values']['revenue'][2025], 0 ) && near( $t['values']['up_down'][2025], -5 ) );
check( 'totals 2026: 3 orders, 50 ordered, 28 received, fill 0.56, 20 survived, survival 0.8, 3 DOA, landed 100, revenue 560, up 460', 3 === $t['values']['orders'][2026] && 50 === $t['values']['ordered'][2026] && 28 === $t['values']['received'][2026] && near( $t['values']['fill_rate'][2026], 0.56 ) && 20 === $t['values']['survived'][2026] && near( $t['values']['survival_rate'][2026], 0.8 ) && 3 === $t['values']['doa'][2026] && near( $t['values']['total'][2026], 100 ) && near( $t['values']['revenue'][2026], 560 ) && near( $t['values']['up_down'][2026], 460 ) );
check( 'every figure has one change per pair of consecutive years, from → to', array( 2025, 2026 ) === array( $t['changes']['orders'][0]['from'], $t['changes']['orders'][0]['to'] ) && 1 === count( $t['changes']['revenue'] ) && array_keys( Stats::COMPARE_FIGURES ) === array_keys( $t['changes'] ) );

echo "8. Year by year: the change between consecutive years\n";
$ch = static function ( $key ) use ( $t ) {
	return $t['changes'][ $key ][0];
};
check( 'a count: absolute and percent — orders +2 (+200%), ordered +45 (+900%), received +23 (+460%)', 2 === $ch( 'orders' )['abs'] && near( $ch( 'orders' )['pct'], 2.0 ) && 45 === $ch( 'ordered' )['abs'] && near( $ch( 'ordered' )['pct'], 9.0 ) && 23 === $ch( 'received' )['abs'] && near( $ch( 'received' )['pct'], 4.6 ) );
check( 'a count from zero has no percent: DOA 0 → 3 is +3, percent undefined', 3 === $ch( 'doa' )['abs'] && null === $ch( 'doa' )['pct'] );
check( 'a rate changes in POINTS, never in percent: fill 100% → 56% is −44 points, DOA rate 0 → 3/28 is +10.71', near( $ch( 'fill_rate' )['abs'], -44.0 ) && null === $ch( 'fill_rate' )['pct'] && near( $ch( 'doa_rate' )['abs'], 300 / 28 ) && null === $ch( 'doa_rate' )['pct'] );
check( 'no figure in one of the two years: no change at all (survivors unknown in 2025)', null === $ch( 'survived' )['abs'] && null === $ch( 'survived' )['pct'] && null === $ch( 'survival_rate' )['abs'] );
check( 'money: landed 5 → 100 is +95 (+1900%); revenue 0 → 560 is +560 with no percent', near( $ch( 'total' )['abs'], 95 ) && near( $ch( 'total' )['pct'], 19.0 ) && near( $ch( 'revenue' )['abs'], 560 ) && null === $ch( 'revenue' )['pct'] );
check( 'up / down can cross zero, so it changes in money only: −5 → 460 is +465', near( $ch( 'up_down' )['abs'], 465 ) && null === $ch( 'up_down' )['pct'] );
check( 'change(): a count going down, money going down, a rate going up', array( 'abs' => -3, 'pct' => -0.25 ) === Stats::change( 12, 9, 'count' ) && near( Stats::change( 200.0, 150.0, 'money' )['abs'], -50 ) && near( Stats::change( 200.0, 150.0, 'money' )['pct'], -0.25 ) && near( Stats::change( 0.8, 0.832, 'rate' )['abs'], 3.2 ) );
check( 'change(): nothing to compare → null, null (either side, any kind); no change is 0, not null', array( 'abs' => null, 'pct' => null ) === Stats::change( null, 4, 'count' ) && array( 'abs' => null, 'pct' => null ) === Stats::change( 4.0, null, 'money' ) && array( 'abs' => null, 'pct' => null ) === Stats::change( null, null, 'rate' ) && 0 === Stats::change( 7, 7, 'count' )['abs'] && near( Stats::change( 7, 7, 'count' )['pct'], 0.0 ) );

echo "9. Year by year: a supplier, origin or group missing in a year\n";
$sup = array();
foreach ( $c['by_supplier'] as $row ) {
	$sup[ $row['name'] ] = $row;
}
check( 'two suppliers, the one with more fish received first', array( 'Test Supplier One', 'Test Supplier Two' ) === array_column( $c['by_supplier'], 'name' ) );
$one = $sup['Test Supplier One'];
check( 'Supplier One had no order in 2025: it is not present there', false === $one['present'][2025] && true === $one['present'][2026] );
$all_null = true;
foreach ( array_keys( Stats::COMPARE_FIGURES ) as $key ) {
	$all_null = $all_null && null === $one['values'][ $key ][2025];
}
check( '… and every figure of that year is null — a dash, never a zero (orders, received, landed, revenue included)', $all_null && null === $one['values']['orders'][2025] && null === $one['values']['total'][2025] );
$no_change = true;
foreach ( array_keys( Stats::COMPARE_FIGURES ) as $key ) {
	$no_change = $no_change && null === $one['changes'][ $key ][0]['abs'] && null === $one['changes'][ $key ][0]['pct'];
}
check( '… and no change is shown into or out of the missing year', $no_change );
check( '… while 2026 holds its real figures (A + C: 2 orders, 30 ordered, 18 received)', 2 === $one['values']['orders'][2026] && 30 === $one['values']['ordered'][2026] && 18 === $one['values']['received'][2026] );
$two = $sup['Test Supplier Two'];
check( 'Supplier Two is in both years (D, then B): orders 1 → 1 is a change of 0 (0%), not a dash', true === $two['present'][2025] && 0 === $two['changes']['orders'][0]['abs'] && near( $two['changes']['orders'][0]['pct'], 0.0 ) );
check( '… ordered 5 → 20 (+15, +300%), fill 100% → 50% (−50 points), landed 5 → 40 (+35, +700%), up / down −5 → 80 (+85)', 15 === $two['changes']['ordered'][0]['abs'] && near( $two['changes']['ordered'][0]['pct'], 3.0 ) && near( $two['changes']['fill_rate'][0]['abs'], -50 ) && near( $two['changes']['total'][0]['abs'], 35 ) && near( $two['changes']['total'][0]['pct'], 7.0 ) && near( $two['changes']['up_down'][0]['abs'], 85 ) );
$org = array();
foreach ( $c['by_origin'] as $row ) {
	$org[ $row['name'] ] = $row;
}
check( 'origins merge without regard to case across the years: Testland in both, Elsewhere missing in 2025', array( 'Testland', 'Elsewhere' ) === array_keys( $org ) && true === $org['Testland']['present'][2025] && 28 === $org['Testland']['values']['received'][2026] && false === $org['Elsewhere']['present'][2025] && null === $org['Elsewhere']['values']['received'][2025] && 0 === $org['Elsewhere']['values']['received'][2026] );
$grp = array();
foreach ( $c['by_group'] as $row ) {
	$grp[ $row['name'] ] = $row;
}
check( 'groups: Gobies only in 2025 (a dash in 2026), Wrasses and Blennies only in 2026, "(no group)" last', array( 'Wrasses', 'Gobies', 'Blennies', '(no group)' ) === array_keys( $grp ) && true === $grp['Gobies']['present'][2025] && false === $grp['Gobies']['present'][2026] && null === $grp['Gobies']['values']['received'][2026] && null === $grp['Gobies']['changes']['received'][0]['abs'] && false === $grp['Wrasses']['present'][2025] );
$cased = Stats::compare(
	array(
		2026 => array(
			'totals'      => $by_year[2026]['totals'],
			'by_supplier' => array( array_merge( $by_year[2026]['by_supplier'][0], array( 'name' => 'TEST supplier one' ) ) ),
			'by_origin'   => array(),
			'by_group'    => array( array_merge( $by_year[2026]['by_group'][0], array( 'name' => 'wrasses' ) ), $by_year[2026]['by_group'][0] ),
		),
		2025 => array(
			'totals'      => $by_year[2025]['totals'],
			'by_supplier' => array( array_merge( $by_year[2025]['by_supplier'][0], array( 'name' => 'Test Supplier One' ) ) ),
			'by_origin'   => array(),
			'by_group'    => array(),
		),
	)
);
check( 'a supplier is one name across the years whatever its case (the latest year\'s spelling shows); two species groups that differ only in case stay two rows, as on the By year view', 1 === count( $cased['by_supplier'] ) && 'TEST supplier one' === $cased['by_supplier'][0]['name'] && array( 2025 => true, 2026 => true ) === $cased['by_supplier'][0]['present'] && 2 === count( $cased['by_group'] ) );
check( 'a year in which a group received nothing is still a real 0 — only a missing year is null', 0 === $grp['Blennies']['values']['received'][2026] && near( $grp['Blennies']['values']['fill_rate'][2026], 0 ) && null === $grp['Blennies']['values']['doa_rate'][2026] );

echo "10. Year by year: three years, one of them skipped\n";
$older = array(
	'id' => 9, 'ref' => '2024-1', 'supplier' => 'Test Supplier One', 'origin' => 'Testland', 'received_date' => '2024-06-01', 'status' => 'closed', 'cost_mode' => 'box',
	'lines' => array(
		array( 'species' => 'Blue Test Wrasse', 'group_name' => 'Wrasses', 'qty_ordered' => 10, 'qty_received' => 9, 'qty_doa' => 0, 'qty_survived' => 9, 'unit_price' => 5, 'worth' => 40, 'per_box' => 9 ),
	),
);
$three   = array();
foreach ( Stats::orders_by_year( array_merge( $orders, array( $older ) ) ) as $year => $list ) {
	$three[ $year ] = Stats::build( $list );
}
$c3 = Stats::compare( $three );
check( 'three years: two changes per figure, 2024 → 2025 and 2025 → 2026', array( 2024, 2025, 2026 ) === $c3['years'] && 2 === count( $c3['totals']['changes']['received'] ) && 2024 === $c3['totals']['changes']['received'][0]['from'] && 2026 === $c3['totals']['changes']['received'][1]['to'] && -4 === $c3['totals']['changes']['received'][0]['abs'] && 23 === $c3['totals']['changes']['received'][1]['abs'] );
$one3 = null;
foreach ( $c3['by_supplier'] as $row ) {
	if ( 'Test Supplier One' === $row['name'] ) {
		$one3 = $row;
	}
}
check( 'Supplier One in 2024 and 2026 but not 2025: both changes are dashes — a change never jumps a missing year', array( 2024 => true, 2025 => false, 2026 => true ) === $one3['present'] && null === $one3['changes']['received'][0]['abs'] && null === $one3['changes']['received'][1]['abs'] && 9 === $one3['values']['received'][2024] && 18 === $one3['values']['received'][2026] );
$solo = Stats::compare( array( 2026 => $by_year[2026] ) );
check( 'one year alone: its figures, no change columns; no year at all: empty', array( 2026 ) === $solo['years'] && array() === $solo['totals']['changes']['orders'] && 28 === $solo['totals']['values']['received'][2026] && array() === Stats::compare( array() )['years'] && array() === Stats::compare( array() )['by_supplier'] );
$none = Stats::compare( array( 2026 => $by_year[2026], 2030 => Stats::build( array() ) ) );
check( 'the totals of a year without orders are real zeros (0 orders), with undefined rates', 0 === $none['totals']['values']['orders'][2030] && 0 === $none['totals']['values']['received'][2030] && null === $none['totals']['values']['fill_rate'][2030] && -3 === $none['totals']['changes']['orders'][0]['abs'] && null === $none['totals']['changes']['fill_rate'][0]['abs'] );

echo "11. Year by year: how the screen prints it\n";
check( 'a figure: a count, a rate, money, signed money; nothing → a dash', '28' === Admin_Stats::figure_text( 28, 'count' ) && '56.0%' === Admin_Stats::figure_text( 0.56, 'rate' ) && '1,234.50' === Admin_Stats::figure_text( 1234.5, 'money' ) && '+460.00' === Admin_Stats::figure_text( 460.0, 'signed' ) && '-5.00' === Admin_Stats::figure_text( -5.0, 'signed' ) && '—' === Admin_Stats::figure_text( null, 'count' ) && '0' === Admin_Stats::figure_text( 0, 'count' ) );
check( 'a change: "+2 (+200.0%)", "+3" from zero, "-44.0 pts", "+95.00 (+1,900.0%)", "+465.00", a dash when there is none', '+2 (+200.0%)' === Admin_Stats::change_text( $ch( 'orders' ), 'count' ) && '+3' === Admin_Stats::change_text( $ch( 'doa' ), 'count' ) && '-44.0 pts' === Admin_Stats::change_text( $ch( 'fill_rate' ), 'rate' ) && '+95.00 (+1,900.0%)' === Admin_Stats::change_text( $ch( 'total' ), 'money' ) && '+465.00' === Admin_Stats::change_text( $ch( 'up_down' ), 'signed' ) && '—' === Admin_Stats::change_text( $ch( 'survived' ), 'count' ), Admin_Stats::change_text( $ch( 'orders' ), 'count' ) . ' | ' . Admin_Stats::change_text( $ch( 'fill_rate' ), 'rate' ) . ' | ' . Admin_Stats::change_text( $ch( 'total' ), 'money' ) );
check( 'no change prints as 0 (0.0%) and 0.0 pts, not as a dash; a drop carries its minus', '0 (0.0%)' === Admin_Stats::change_text( Stats::change( 7, 7, 'count' ), 'count' ) && '0.0 pts' === Admin_Stats::change_text( Stats::change( 0.5, 0.5, 'rate' ), 'rate' ) && '-3 (-25.0%)' === Admin_Stats::change_text( Stats::change( 12, 9, 'count' ), 'count' ) && '+3.2 pts' === Admin_Stats::change_text( Stats::change( 0.8, 0.832, 'rate' ), 'rate' ) );
check( 'good and bad news: more survivors / fill / revenue up is good, more DOA is bad, more ordered is neither', 'fh-cr-up' === Admin_Stats::change_class( 'revenue', array( 'abs' => 5.0 ) ) && 'fh-cr-down' === Admin_Stats::change_class( 'fill_rate', array( 'abs' => -44.0 ) ) && 'fh-cr-down' === Admin_Stats::change_class( 'doa', array( 'abs' => 3 ) ) && 'fh-cr-up' === Admin_Stats::change_class( 'doa_rate', array( 'abs' => -1.5 ) ) && '' === Admin_Stats::change_class( 'ordered', array( 'abs' => 45 ) ) && '' === Admin_Stats::change_class( 'revenue', array( 'abs' => null ) ) && '' === Admin_Stats::change_class( 'revenue', array( 'abs' => 0.0 ) ) );

echo "12. Per species, per year\n";
$sp = Stats::by_species( $orders );
check( 'one row per fish and year, newest year first, then most fish received', array( 'Blue Test Wrasse', 'Red Test Goby', 'Green Test Blenny', 'Yellow Test Goby' ) === array_column( $sp, 'name' ) && array( 2026, 2026, 2026, 2025 ) === array_column( $sp, 'year' ) );
$w = $sp[0];
check( 'the wrasse of 2026 (A1 + B): 2 orders, 2 lines, 30 ordered, 18 received, 3 DOA, 10 survived', 'n:blue test wrasse' === $w['key'] && null === $w['species_id'] && 'Wrasses' === $w['group'] && 2 === $w['orders'] && 2 === $w['lines'] && 30 === $w['ordered'] && 18 === $w['received'] && 3 === $w['doa'] && 10 === $w['survived'] && true === $w['survived_known'] );
check( '… landed 80, estimated revenue 360 (survivors × the price on the line), profit 280, margin 3.5, landed per fish 80 ÷ 18', near( $w['total'], 80 ) && near( $w['revenue'], 360 ) && near( $w['profit'], 280 ) && near( $w['margin'], 3.5 ) && near( $w['landed_per_fish'], 80 / 18 ) && near( $w['doa_rate'], 3 / 18 ) && near( $w['survival_rate'], 10 / 15 ) && 0 === $w['unknown_lines'] );
check( 'the goby: 10 received, landed 20, revenue 200, profit 180, margin 9; no group', near( $sp[1]['total'], 20 ) && near( $sp[1]['revenue'], 200 ) && near( $sp[1]['profit'], 180 ) && near( $sp[1]['margin'], 9 ) && '' === $sp[1]['group'] );
check( 'the blenny received nothing: 0 landed, no margin and no landed cost per fish (null, not 0)', 0 === $sp[2]['received'] && near( $sp[2]['total'], 0 ) && null === $sp[2]['margin'] && null === $sp[2]['landed_per_fish'] && false === $sp[2]['survived_known'] && 0 === $sp[2]['unknown_lines'] );
check( 'the 2025 goby: survivors not known — one line counted as outside the estimate', false === $sp[3]['survived_known'] && 1 === $sp[3]['unknown_lines'] && near( $sp[3]['total'], 5 ) && near( $sp[3]['landed_per_fish'], 1 ) );

echo "13. Per species: the rows of a year add up to the year's totals\n";
$sp26 = Stats::by_species( $y2026 );
$sum  = Stats::species_totals( $sp26 );
$tot  = $s26['totals'];
check( 'three fish in 2026; ordered, received, DOA, survived, landed and revenue equal the weighted totals of the year', 3 === $sum['rows'] && $tot['ordered'] === $sum['ordered'] && $tot['received'] === $sum['received'] && $tot['doa'] === $sum['doa'] && $tot['survived'] === $sum['survived'] && near( $sum['total'], $tot['total'] ) && near( $sum['revenue'], $tot['revenue'] ) && near( $sum['profit'], $tot['up_down'] ) && $tot['lines'] === $sum['lines'] );
check( '… 50 ordered, 28 received, 3 DOA, 20 survived, landed 100, revenue 560, profit 460, margin 4.6', 50 === $sum['ordered'] && 28 === $sum['received'] && 3 === $sum['doa'] && 20 === $sum['survived'] && near( $sum['total'], 100 ) && near( $sum['revenue'], 560 ) && near( $sum['profit'], 460 ) && near( $sum['margin'], 4.6 ) );
$all_sum = Stats::species_totals( $sp );
check( 'and over every year they equal the all-years totals (105 landed, 560 revenue)', near( $all_sum['total'], $s['totals']['total'] ) && near( $all_sum['revenue'], $s['totals']['revenue'] ) && $s['totals']['received'] === $all_sum['received'] && 1 === $all_sum['unknown_lines'] );
check( 'no rows: zeros and no margin', 0 === Stats::species_totals( array() )['rows'] && null === Stats::species_totals( array() )['margin'] && false === Stats::species_totals( array() )['survived_known'] );

echo "14. Per species: which fish a line is\n";
$list = array(
	array( 'id' => 7, 'name' => 'blue test WRASSE', 'group_name' => 'Listed Wrasses', 'product_id' => null, 'my_price' => 40.0 ),
	array( 'id' => 8, 'name' => 'Spotted Test Eel', 'group_name' => 'Eels', 'product_id' => null, 'my_price' => null ),
);
$idx = Stats::species_index( $list );
check( 'species_index: lower-cased name → id, id → row', array( 'blue test wrasse' => 7, 'spotted test eel' => 8 ) === $idx['names'] && 'Eels' === $idx['rows'][8]['group_name'] );
check( 'fish_key: the line\'s species id; else the row of that exact name; else the name; "?" while unknown', 's:8' === Stats::fish_key( array( 'species_id' => 8, 'species' => 'anything' ), $idx['names'] ) && 's:7' === Stats::fish_key( array( 'species' => '  Blue  Test wrasse ' ), $idx['names'] ) && 'n:blue test wrasse' === Stats::fish_key( array( 'species' => 'Blue Test Wrasse' ) ) && 'n:blue test wrass' === Stats::fish_key( array( 'species' => 'Blue Test Wrass' ), $idx['names'] ) && Stats::UNKNOWN_KEY === Stats::fish_key( array( 'species_id' => 8, 'species' => '?? small eel', 'needs_id' => true ), $idx['names'] ) );
$mixed = array(
	array(
		'id' => 1, 'ref' => '2026-1', 'received_date' => '2026-03-01', 'cost_mode' => 'box',
		'lines' => array(
			array( 'species' => 'Blue Test Wrasse', 'group_name' => 'Wrasses', 'qty_ordered' => 4, 'qty_received' => 4, 'qty_survived' => 4, 'unit_price' => 5, 'worth' => 40 ),
			array( 'species_id' => 7, 'species' => 'Wrasse, blue', 'qty_ordered' => 2, 'qty_received' => 2, 'qty_survived' => 1, 'unit_price' => 5, 'worth' => 40 ),
			array( 'species' => '?? small eel', 'needs_id' => true, 'qty_ordered' => 1, 'qty_received' => 1, 'qty_survived' => 1, 'unit_price' => 9, 'worth' => 0 ),
			array( 'species' => '?? grey fish', 'needs_id' => true, 'qty_ordered' => 1, 'qty_received' => 1, 'qty_survived' => null, 'unit_price' => 3, 'worth' => 0 ),
			array( 'species' => 'Typed Test Fish', 'qty_ordered' => 1, 'qty_received' => 1, 'qty_survived' => 1, 'unit_price' => 2, 'worth' => 10 ),
		),
	),
);
$m = Stats::by_species( $mixed, $list );
$k = array();
foreach ( $m as $row ) {
	$k[ $row['key'] ] = $row;
}
check( 'a line typed with the exact name of a list row and a line that names the row are ONE fish, under the row\'s name and group', array( 's:7', Stats::UNKNOWN_KEY, 'n:typed test fish' ) === array_keys( $k ) && 'blue test WRASSE' === $k['s:7']['name'] && 'Listed Wrasses' === $k['s:7']['group'] && 7 === $k['s:7']['species_id'] && 6 === $k['s:7']['received'] && 2 === $k['s:7']['lines'] && 1 === $k['s:7']['orders'] );
check( 'the fish nobody has identified yet are one row — "(not identified yet)" — with their landed cost', Stats::UNKNOWN_NAME === $k['?']['name'] && 2 === $k['?']['received'] && 2 === $k['?']['lines'] && near( $k['?']['total'], 12 ) && 1 === $k['?']['unknown_lines'] && null === $k['?']['species_id'] );
check( 'a typed fish that is in no list keeps its name as typed', 'Typed Test Fish' === $k['n:typed test fish']['name'] && null === $k['n:typed test fish']['species_id'] );
check( 'and the rows still add up to the order (9 received, landed 6 × 5 + 9 + 3 + 2 = 44)', 9 === Stats::species_totals( $m )['received'] && near( Stats::species_totals( $m )['total'], 44 ) && near( Stats::build( $mixed )['totals']['total'], 44 ) );

echo "15. Sorting and searching a report\n";
check( 'sort_rows: most landed first; least first; text A to Z without regard to case', array( 'Blue Test Wrasse', 'Red Test Goby', 'Yellow Test Goby', 'Green Test Blenny' ) === array_column( Stats::sort_rows( $sp, 'total', 'desc' ), 'name' ) && array( 'Green Test Blenny', 'Yellow Test Goby', 'Red Test Goby', 'Blue Test Wrasse' ) === array_column( Stats::sort_rows( $sp, 'total', 'asc' ), 'name' ) && array( 'Blue Test Wrasse', 'Green Test Blenny', 'Red Test Goby', 'Yellow Test Goby' ) === array_column( Stats::sort_rows( $sp, 'name', 'asc' ), 'name' ) );
check( 'a row without a value in the sorted column goes last whichever way the sort runs (the blenny has no margin)', 'Green Test Blenny' === Stats::sort_rows( $sp, 'margin', 'desc' )[3]['name'] && 'Green Test Blenny' === Stats::sort_rows( $sp, 'margin', 'asc' )[3]['name'] && 'Red Test Goby' === Stats::sort_rows( $sp, 'margin', 'desc' )[0]['name'] && 'Yellow Test Goby' === Stats::sort_rows( $sp, 'margin', 'asc' )[0]['name'] );
check( 'the sort is stable: equal rows keep their order; an unknown column changes nothing', array_column( $sp, 'name' ) === array_column( Stats::sort_rows( $sp, 'no_such_column', 'desc' ), 'name' ) && array( 'Blue Test Wrasse', 'Red Test Goby', 'Green Test Blenny', 'Yellow Test Goby' ) === array_column( Stats::sort_rows( $sp, 'year', 'desc' ), 'name' ) );
check( 'search_rows: any case, part of a word, every word must match, the name or the group', array( 'Red Test Goby', 'Yellow Test Goby' ) === array_column( Stats::search_rows( $sp, 'GOBY' ), 'name' ) && array( 'Blue Test Wrasse' ) === array_column( Stats::search_rows( $sp, 'wrasses' ), 'name' ) && array( 'Yellow Test Goby' ) === array_column( Stats::search_rows( $sp, 'yel gob' ), 'name' ) && array() === Stats::search_rows( $sp, 'goby zebra' ) && 4 === count( Stats::search_rows( $sp, '   ' ) ) );

fh_finish();
