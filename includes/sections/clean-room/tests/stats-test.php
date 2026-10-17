<?php
/**
 * Stats — the yearly figures over invented orders: the weighted Σ ÷ Σ
 * totals against the sheet-style unweighted averages (a batch that
 * received nothing left out, a batch whose survivors are unknown left
 * out of the survival average only), the groupings by supplier, origin
 * and species group, and the year of an order.
 *
 * Run from the plugin root:  php includes/sections/clean-room/tests/stats-test.php
 */

require_once __DIR__ . '/wp-stubs.php';

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

fh_finish();
