<?php
/**
 * Costing — the landed-cost maths of a supplier order in both modes,
 * worked by hand with invented fish and invented numbers; the suggested
 * price to the nearest 5; every zero / blank guard; the display helpers.
 *
 * Run from the plugin root:  php includes/sections/clean-room/tests/costing-test.php
 */

require_once __DIR__ . '/wp-stubs.php';

use FisHotel\Misc\Sections\Clean_Room\Costing;

function near( $a, $b, $eps = 1e-9 ) {
	return null !== $a && null !== $b && abs( (float) $a - (float) $b ) < $eps;
}

echo "1. Box mode — a worked example\n";
/*
 * Order: tariffs 30, domestic flight 100, import & re-ox fees 200, credit 10,
 * markup 4, worth threshold 2.75.
 *
 * Line 1 Blue Test Wrasse:  ordered 10, received 8, per box 8 (typed), price 5,   worth 40, survived 6, DOA 1
 * Line 2 Red Test Goby:     ordered 20, received 12, per box — (species 24), price 2.5, worth 20, survived 10, DOA 0
 * Line 3 Green Test Blenny: ordered 5,  received 0,  price 10, worth 30, survived — , DOA 0
 *
 * fraction:  8 ÷ 8 = 1 · 12 ÷ 24 = 0.5 · 0 (nothing received) → Σ 1.5
 * fees per box = (100 + 200) ÷ 1.5 = 200
 * goods = 8 × 5 + 12 × 2.5 = 70 → tariff factor = 1 + 30 ÷ 70 = 10 ÷ 7
 * landed 1 = 40 × 10/7 + 1 × 200   = 57.142857… + 200 = 257.142857…
 * landed 2 = 30 × 10/7 + 0.5 × 200 = 42.857142… + 100 = 142.857142…
 * landed 3 = 0
 * total = 400 (= goods + tariffs + flight + import), grand total = 390
 * per fish 1 = 32.142857… × 4 = 128.57 → 130 ; per fish 2 = 11.904761… × 4 = 47.62 → 50 ; line 3 undefined
 * profit 1 = 320 − 257.14 = 62.857142… margin 0.2444 (not worth it) ; profit 2 = 240 − 142.86 = 97.142857… margin 0.68
 * revenue = 40 × 6 + 20 × 10 + 30 × 0 = 440 ; up/down = 440 − 390 = 50 ; ratio = 440 ÷ 390
 * fill = 20 ÷ 35 ; survival = 16 ÷ (20 − 1) ; DOA rate = 1 ÷ 20
 * DOA cost (sheet) = 1 × 5 + 1 × 200 = 205 (a whole box's fees per fish — the sheet's quirk)
 * DOA cost per fish = 1 × (5 + 200 ÷ 8) = 30
 */
$order = array(
	'cost_mode'       => 'box',
	'tariffs'         => 30,
	'domestic_flight' => 100,
	'import_fees'     => 200,
	'credit'          => 10,
	'markup'          => 4,
	'worth_threshold' => 2.75,
);
$lines = array(
	array( 'species' => 'Blue Test Wrasse', 'qty_ordered' => 10, 'qty_received' => 8, 'per_box' => 8, 'unit_price' => 5, 'worth' => 40, 'qty_survived' => 6, 'qty_doa' => 1 ),
	array( 'species' => 'Red Test Goby', 'qty_ordered' => 20, 'qty_received' => 12, 'per_box' => null, 'species_per_box' => 24, 'unit_price' => 2.5, 'worth' => 20, 'qty_survived' => 10, 'qty_doa' => 0 ),
	array( 'species' => 'Green Test Blenny', 'qty_ordered' => 5, 'qty_received' => 0, 'unit_price' => 10, 'worth' => 30, 'qty_survived' => null, 'qty_doa' => 0 ),
);
$c = Costing::compute( $order, $lines );
$l = $c['lines'];
$o = $c['order'];
check( 'mode and the per-box fallback (line, species, default)', 'box' === $c['mode'] && 8 === $l[0]['per_box'] && 24 === $l[1]['per_box'] && 24 === $l[2]['per_box'] );
check( 'box fractions 1 / 0.5 / 0 and Σ 1.5', near( $l[0]['fraction'], 1 ) && near( $l[1]['fraction'], 0.5 ) && near( $l[2]['fraction'], 0 ) && near( $o['boxes'], 1.5 ) );
check( 'fees per box 200, tariff factor 10/7', near( $o['fees_per_box'], 200 ) && near( $o['fee_rate'], 200 ) && null === $o['per_bag'] && near( $o['tariff_factor'], 10 / 7 ) );
check( 'landed totals 257.142857 / 142.857142 / 0', near( $l[0]['landed_total'], 40 * 10 / 7 + 200 ) && near( $l[1]['landed_total'], 30 * 10 / 7 + 100 ) && near( $l[2]['landed_total'], 0 ) );
check( 'total 400, grand total 390', near( $o['total'], 400 ) && near( $o['grand_total'], 390 ) );
check( 'landed per fish, and null when nothing was received', near( $l[0]['landed_per_fish'], ( 40 * 10 / 7 + 200 ) / 8 ) && near( $l[1]['landed_per_fish'], ( 30 * 10 / 7 + 100 ) / 12 ) && null === $l[2]['landed_per_fish'] );
check( 'suggested prices 130 / 50 / —', near( $l[0]['suggested_price'], 130 ) && near( $l[1]['suggested_price'], 50 ) && null === $l[2]['suggested_price'] );
check( 'profit and margin', near( $l[0]['profit'], 320 - ( 40 * 10 / 7 + 200 ) ) && near( $l[0]['margin'], ( 320 - ( 40 * 10 / 7 + 200 ) ) / ( 40 * 10 / 7 + 200 ) ) && near( $l[1]['profit'], 240 - ( 30 * 10 / 7 + 100 ) ) && near( $l[1]['margin'], 0.68 ) );
check( 'margin undefined on a 0 landed line, nothing worth it at 2.75', null === $l[2]['margin'] && false === $l[0]['worth_it'] && false === $l[1]['worth_it'] && false === $l[2]['worth_it'] );
check( 'revenue per line (an unknown survived counts 0) and 440 in all', near( $l[0]['revenue'], 240 ) && near( $l[1]['revenue'], 200 ) && near( $l[2]['revenue'], 0 ) && near( $o['revenue'], 440 ) );
check( 'up/down 50, ratio 440/390', near( $o['up_down'], 50 ) && near( $o['ratio'], 440 / 390 ) );
check( 'totals: 35 ordered, 20 received, 16 survived (known), 1 DOA, 3 lines', 35 === $o['ordered'] && 20 === $o['received'] && 16 === $o['survived'] && true === $o['survived_known'] && 1 === $o['doa'] && 3 === $o['lines'] );
check( 'fill 20/35, survival 16/19, DOA 1/20', near( $o['fill_rate'], 20 / 35 ) && near( $o['survival_rate'], 16 / 19 ) && near( $o['doa_rate'], 0.05 ) );
check( 'DOA cost: the sheet\'s 205 (a whole box\'s fees), per fish 30', near( $o['doa_cost_sheet'], 205 ) && near( $o['doa_cost_lines'], 5 ) && near( $o['doa_cost_per_fish'], 30 ) && near( $l[0]['doa_cost'], 5 ) && near( $l[0]['doa_cost_per_fish'], 30 ) );
check( 'the inputs echo back (markup, threshold, credit)', near( $o['markup'], 4 ) && near( $o['worth_threshold'], 2.75 ) && near( $o['credit'], 10 ) && near( $o['goods'], 70 ) );

echo "2. Box mode — a line worth it\n";
$c = Costing::compute( array( 'cost_mode' => 'box', 'tariffs' => 0, 'domestic_flight' => 0, 'import_fees' => 0 ), array( array( 'qty_received' => 10, 'per_box' => 10, 'unit_price' => 2, 'worth' => 10, 'qty_survived' => 10 ) ) );
check( 'landed 20, profit 80, margin 4 > 2.75 → worth it, suggested 10 (2 × 4 = 8 → 10)', near( $c['lines'][0]['landed_total'], 20 ) && near( $c['lines'][0]['profit'], 80 ) && near( $c['lines'][0]['margin'], 4 ) && true === $c['lines'][0]['worth_it'] && near( $c['lines'][0]['suggested_price'], 10 ) );
$c = Costing::compute( array( 'cost_mode' => 'box', 'worth_threshold' => 5 ), array( array( 'qty_received' => 10, 'per_box' => 10, 'unit_price' => 2, 'worth' => 10, 'qty_survived' => 10 ) ) );
check( 'a higher threshold: the same line is not worth it; blank fees read as 0', false === $c['lines'][0]['worth_it'] && near( $c['order']['total'], 20 ) );

echo "3. Bag mode — a worked example\n";
/*
 * Order: tariffs 20, flight 50, import 30 → everything per bag: (50 + 30 + 20) ÷ Σ units.
 * Line 1: ordered 12, received 10, bags 2 (typed), price 15 (per bag), worth 40, survived 9, DOA 1 → units 2
 * Line 2: ordered 6,  received 6,  bags —,          price 3,            worth 25, survived 6, DOA 0 → units 6 (= received)
 * per bag = 100 ÷ 8 = 12.5
 * landed 1 = 2 × 15 + 2 × 12.5 = 55 ; landed 2 = 6 × 3 + 6 × 12.5 = 93 ; total 148
 * per fish 1 = 5.5 × 4 = 22 → 20 ; per fish 2 = 15.5 × 4 = 62 → 60
 * profit 1 = 400 − 55 = 345, margin 6.27 → worth it ; profit 2 = 150 − 93 = 57, margin 0.61
 * revenue = 40 × 9 + 25 × 6 = 510 ; up/down = 510 − 148 = 362
 * fill = 16 ÷ 18 ; survival = 15 ÷ (16 − 1) = 1 ; DOA rate = 1 ÷ 16
 * DOA cost (sheet) = 1 × 15 + 1 × 12.5 = 27.5 ; per fish the same in bag mode
 */
$c = Costing::compute(
	array( 'cost_mode' => 'bag', 'tariffs' => 20, 'domestic_flight' => 50, 'import_fees' => 30 ),
	array(
		array( 'qty_ordered' => 12, 'qty_received' => 10, 'bags' => 2, 'unit_price' => 15, 'worth' => 40, 'qty_survived' => 9, 'qty_doa' => 1 ),
		array( 'qty_ordered' => 6, 'qty_received' => 6, 'bags' => null, 'unit_price' => 3, 'worth' => 25, 'qty_survived' => 6, 'qty_doa' => 0 ),
	)
);
$l = $c['lines'];
$o = $c['order'];
check( 'units: the bags when typed, else the received count', 'bag' === $c['mode'] && near( $l[0]['units'], 2 ) && near( $l[1]['units'], 6 ) && null === $l[0]['fraction'] && null === $o['boxes'] );
check( 'per bag 12.5 (tariffs included), no fees per box', near( $o['per_bag'], 12.5 ) && near( $o['fee_rate'], 12.5 ) && null === $o['fees_per_box'] && near( $o['tariff_factor'], 1 ) );
check( 'landed 55 / 93, total 148', near( $l[0]['landed_total'], 55 ) && near( $l[1]['landed_total'], 93 ) && near( $o['total'], 148 ) && near( $o['grand_total'], 148 ) );
check( 'suggested 20 / 60', near( $l[0]['suggested_price'], 20 ) && near( $l[1]['suggested_price'], 60 ) );
check( 'worth it only on the first line', true === $l[0]['worth_it'] && near( $l[0]['margin'], 345 / 55 ) && false === $l[1]['worth_it'] );
check( 'revenue 510, up/down 362', near( $o['revenue'], 510 ) && near( $o['up_down'], 362 ) && near( $o['ratio'], 510 / 148 ) );
check( 'fill 16/18, survival 1, DOA 1/16', near( $o['fill_rate'], 16 / 18 ) && near( $o['survival_rate'], 1 ) && near( $o['doa_rate'], 1 / 16 ) );
check( 'DOA cost 27.5 both ways', near( $o['doa_cost_sheet'], 27.5 ) && near( $o['doa_cost_per_fish'], 27.5 ) );

echo "4. Guards — nothing divides by zero\n";
$c = Costing::compute( array( 'cost_mode' => 'box', 'tariffs' => 10, 'domestic_flight' => 20, 'import_fees' => 30, 'credit' => 5 ), array() );
$o = $c['order'];
check( 'no lines: sums 0, every rate null, grand total = −credit', array() === $c['lines'] && 0 === $o['lines'] && near( $o['total'], 0 ) && near( $o['grand_total'], -5 ) && null === $o['fill_rate'] && null === $o['survival_rate'] && null === $o['doa_rate'] && near( $o['fee_rate'], 0 ) && near( $o['tariff_factor'], 1 ) && false === $o['survived_known'] );
check( 'ratio = revenue ÷ grand total: defined on a negative grand total, null at 0', near( $o['ratio'], 0 ) && null === Costing::compute( array(), array() )['order']['ratio'] );
$c = Costing::compute( array( 'cost_mode' => 'box', 'tariffs' => 10, 'domestic_flight' => 20, 'import_fees' => 30 ), array( array( 'qty_ordered' => 5, 'qty_received' => 0, 'unit_price' => 9, 'worth' => 30, 'qty_doa' => 0 ) ) );
check( 'nothing received: fraction 0, fees 0, landed 0, per-fish / suggested / margin null, fill 0, survival and DOA null', near( $c['lines'][0]['fraction'], 0 ) && near( $c['order']['fee_rate'], 0 ) && near( $c['lines'][0]['landed_total'], 0 ) && null === $c['lines'][0]['landed_per_fish'] && null === $c['lines'][0]['suggested_price'] && null === $c['lines'][0]['margin'] && near( $c['order']['fill_rate'], 0 ) && null === $c['order']['survival_rate'] && null === $c['order']['doa_rate'] );
$c = Costing::compute( array( 'cost_mode' => 'bag', 'tariffs' => 10 ), array( array( 'qty_received' => 0, 'bags' => 0, 'unit_price' => 1, 'qty_survived' => 0 ) ) );
check( 'bag mode with no units: per bag 0', near( $c['order']['per_bag'], 0 ) && near( $c['lines'][0]['landed_total'], 0 ) );
$c = Costing::compute( array( 'cost_mode' => 'box' ), array( array( 'qty_received' => 4, 'qty_doa' => 4, 'unit_price' => 2, 'qty_survived' => 0 ) ) );
check( 'everything DOA: survival null (received − DOA = 0), DOA rate 1', null === $c['order']['survival_rate'] && near( $c['order']['doa_rate'], 1 ) );
$c = Costing::compute( array( 'cost_mode' => 'box' ), array( array( 'qty_received' => 4, 'unit_price' => 2, 'qty_survived' => null ), array( 'qty_received' => 2, 'unit_price' => 2, 'qty_survived' => '' ) ) );
check( 'survivors not typed on any line: survival null, not 0', null === $c['order']['survival_rate'] && false === $c['order']['survived_known'] && 0 === $c['order']['survived'] );
$c = Costing::compute( array( 'cost_mode' => 'box', 'tariffs' => '', 'domestic_flight' => 'abc', 'import_fees' => null, 'markup' => '', 'worth_threshold' => 'x' ), array( array( 'qty_received' => '3', 'per_box' => '0', 'unit_price' => '$1,000.50', 'worth' => '', 'qty_survived' => '2', 'qty_doa' => '' ) ) );
check( 'blanks and junk: fees 0, markup 4 and threshold 2.75 by default, per box 24, a formatted price read', near( $c['order']['markup'], 4 ) && near( $c['order']['worth_threshold'], 2.75 ) && 24 === $c['lines'][0]['per_box'] && near( $c['lines'][0]['landed_total'], 3001.5 ) && 2 === $c['order']['survived'] && 0 === $c['order']['doa'] );
check( 'a negative per-box default falls back to 24', 24 === Costing::compute( array(), array( array( 'qty_received' => 1 ) ), -3 )['lines'][0]['per_box'] );
check( 'an unknown mode is box', 'box' === Costing::compute( array( 'cost_mode' => 'crate' ), array() )['mode'] );

echo "5. Rounding to the nearest 5 (MROUND)\n";
check( '12.5 → 15 (halves away from zero), 12.4 → 10, 17.5 → 20, 2.4 → 0, 2.5 → 5', near( Costing::round_to( 12.5, 5 ), 15 ) && near( Costing::round_to( 12.4, 5 ), 10 ) && near( Costing::round_to( 17.5, 5 ), 20 ) && near( Costing::round_to( 2.4, 5 ), 0 ) && near( Costing::round_to( 2.5, 5 ), 5 ) );
check( '128.57 → 130, 47.62 → 50, 0 → 0, −2.5 → −5', near( Costing::round_to( 128.57, 5 ), 130 ) && near( Costing::round_to( 47.62, 5 ), 50 ) && near( Costing::round_to( 0, 5 ), 0 ) && near( Costing::round_to( -2.5, 5 ), -5 ) );
check( 'a step of 0 leaves the value alone', near( Costing::round_to( 12.34, 0 ), 12.34 ) );

echo "6. money_in and the display helpers\n";
check( 'money_in reads $ and thousands separators, blanks and junk fall back', near( Costing::money_in( '$1,234.50' ), 1234.5 ) && near( Costing::money_in( '' , 7 ), 7 ) && near( Costing::money_in( 'abc', 2.75 ), 2.75 ) && near( Costing::money_in( null, 4 ), 4 ) && near( Costing::money_in( true, 1 ), 1 ) && near( Costing::money_in( ' 12 ' ), 12 ) );
check( 'money(): two decimals with thousands, the blank for null', '1,234.50' === Costing::money( 1234.5 ) && '0.00' === Costing::money( 0 ) && '—' === Costing::money( null ) && '' === Costing::money( null, '' ) && '257.14' === Costing::money( 40 * 10 / 7 + 200 ) );
check( 'pct(): one decimal, the blank for null', '80.7%' === Costing::pct( 0.80714 ) && '100.0%' === Costing::pct( 1 ) && '—' === Costing::pct( null ) && '57.14%' === Costing::pct( 20 / 35, '—', 2 ) );
check( 'num(): no trailing zeros, no −0', '2.75' === Costing::num( 2.75 ) && '3' === Costing::num( 3.0 ) && '0' === Costing::num( -0.001 ) && '1.5' === Costing::num( 1.5 ) && '—' === Costing::num( null ) );
check( 'cents(): rounds for storage, null stays null', 32.14 === Costing::cents( 32.142857 ) && null === Costing::cents( null ) );

fh_finish();
