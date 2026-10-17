<?php
/**
 * The workbook admin — the box rates (sanitised, matched, the
 * import-fees suggestion), the order editor's Save path on the in-memory
 * store (header + lines → figures as display strings, the next ref, a
 * duplicate ref refused, a non-Spa building dropped, the landed-total
 * cache), the pick lists, and the export sheets (orders + lines,
 * species, stats, stays) with the typed cells and the CSV guard.
 *
 * Run from the plugin root:  php includes/sections/clean-room/tests/workbook-admin-test.php
 */

require_once __DIR__ . '/wp-stubs.php';

use FisHotel\Misc\Sections\Clean_Room\Admin;
use FisHotel\Misc\Sections\Clean_Room\Admin_Workbook;
use FisHotel\Misc\Sections\Clean_Room\Orders;
use FisHotel\Misc\Sections\Clean_Room\Replay;
use FisHotel\Misc\Sections\Clean_Room\Settings;
use FisHotel\Misc\Sections\Clean_Room\Species;
use FisHotel\Misc\Sections\Clean_Room\Tests\Fake_Store;

function near( $a, $b, $eps = 1e-9 ) {
	return null !== $a && null !== $b && abs( (float) $a - (float) $b ) < $eps;
}

echo "1. Box rates\n";
$rates = Settings::sanitize_box_rates(
	array(
		array( 'supplier' => ' Test  Supplier ', 'origin' => 'Testland', 'per_box' => '$10.00', 'reox' => '2.5' ),
		array( 'supplier' => 'test supplier', 'origin' => 'TESTLAND', 'per_box' => '11', 'reox' => '2.5' ),
		array( 'supplier' => 'Test Supplier', 'origin' => '', 'per_box' => '7', 'reox' => '-1' ),
		array( 'supplier' => '', 'origin' => '', 'per_box' => '9' ),
		'junk',
		array( 'supplier' => 'Another Supplier', 'origin' => 'Elsewhere', 'per_box' => 'abc', 'reox' => '' ),
	)
);
check( 'trimmed, deduped (the last of a pair wins), the empty row and junk dropped, money ≥ 0, sorted by supplier then origin', 3 === count( $rates ) && array( 'Another Supplier', 'Test Supplier', 'Test Supplier' ) === array_column( $rates, 'supplier' ) && array( 'Elsewhere', '', 'Testland' ) === array_column( $rates, 'origin' ) && near( $rates[2]['per_box'], 11 ) && near( $rates[1]['reox'], 0 ) && near( $rates[0]['per_box'], 0 ), json_encode( $rates ) );
check( 'nothing stored by default', array() === Settings::box_rates() );
update_option( Settings::BOX_RATES_OPTION, $rates, false );
check( 'the option reads back sanitised', $rates === Settings::box_rates() );
check( 'box_rate_for: the exact pair (any case), else the supplier\'s origin-less row, else null', near( Settings::box_rate_for( $rates, 'TEST SUPPLIER', 'testland' )['per_box'], 11 ) && near( Settings::box_rate_for( $rates, 'Test Supplier', 'Nowhere' )['per_box'], 7 ) && null === Settings::box_rate_for( $rates, 'Another Supplier', 'Nowhere' ) && null === Settings::box_rate_for( $rates, 'Nobody', 'Testland' ) );
check( 'suggest_import_fees: 3 × (11 + 2.5) = 40.5; 2 × 7 via the fallback; null without boxes or a rate', near( Settings::suggest_import_fees( $rates, 'Test Supplier', 'Testland', 3 ), 40.5 ) && near( Settings::suggest_import_fees( $rates, 'Test Supplier', 'Nowhere', 2 ), 14 ) && null === Settings::suggest_import_fees( $rates, 'Test Supplier', 'Testland', 0 ) && null === Settings::suggest_import_fees( $rates, 'Test Supplier', 'Testland', null ) && null === Settings::suggest_import_fees( $rates, 'Nobody', 'x', 2 ) );
check( 'the cap', Settings::BOX_RATES_MAX === count( Settings::sanitize_box_rates( array_map( static function ( $i ) { return array( 'supplier' => 's' . $i, 'origin' => 'o', 'per_box' => 1 ); }, range( 1, Settings::BOX_RATES_MAX + 20 ) ) ) ) );

echo "2. The order editor's Save on the store\n";
$today    = Replay::date_in_zone( time(), wp_timezone() );
$settings = Settings::get();
$store    = new Fake_Store( array( 12 => 'Blue Test Wrasse' ) );
$store->save_species( Species::normalize( array( 'name' => 'Blue Test Wrasse', 'per_box' => 8, 'my_price' => 40, 'product_id' => 12, 'group_name' => 'Wrasses' ) ) );
$spa500 = null;
$clean  = null;
foreach ( $store->buildings() as $b ) {
	if ( 500 === (int) $b['number'] ) {
		$spa500 = (int) $b['id'];
	}
	if ( 100 === (int) $b['number'] ) {
		$clean = (int) $b['id'];
	}
}
$blank = Admin_Workbook::blank_order( $store, $today );
check( 'a blank order: the next ref of the year, today, draft, no lines, zero figures', substr( $today, 0, 4 ) . '-1' === $blank['ref'] && $today === $blank['order_date'] && 'draft' === $blank['status'] && array() === $blank['lines'] && 0 === $blank['costing']['lines'] && '0.00' === Admin_Workbook::figures( $blank )['order']['total'] );

$raw = array(
	'ref'             => '',
	'supplier'        => 'Test Supplier',
	'origin'          => 'Testland',
	'order_date'      => '2026-03-01',
	'status'          => 'ordered',
	'cost_mode'       => 'box',
	'tariffs'         => '30',
	'domestic_flight' => '100',
	'import_fees'     => '200',
	'credit'          => '10',
	'building_id'     => (string) $clean,
	'lines'           => array(
		array( 'species' => 'Blue Test Wrasse', 'species_id' => '1', 'qty_ordered' => '10', 'qty_received' => '8', 'per_box' => '8', 'unit_price' => '5', 'worth' => '40', 'qty_survived' => '6', 'qty_doa' => '1' ),
		array( 'species' => '', 'qty_ordered' => '3' ),
		array( 'species' => 'Red Test Goby', 'qty_ordered' => '20', 'qty_received' => '12', 'unit_price' => '2.5', 'worth' => '20', 'qty_survived' => '10', 'qty_doa' => '0' ),
		array( 'species' => 'Green Test Blenny', 'qty_ordered' => '5', 'qty_received' => '0', 'unit_price' => '10', 'worth' => '30' ),
	),
);
$r = Admin_Workbook::save_order( $store, $raw, 0, $today );
check( 'saved as a new order: the ref 2026-1 from the order date, a redirect to its editor, three line ids (the empty species dropped), a check-in offered', empty( $r['error'] ) && 1 === $r['order_id'] && true === $r['is_new'] && '2026-1' === $r['ref'] && false !== strpos( $r['redirect'], 'order=1' ) && array( 1, 2, 3 ) === $r['line_ids'] && true === $r['can_checkin'], json_encode( $r ) );
check( 'a Clean Room building is dropped (only the Spa receives fish)', null === $store->order( 1 )['building_id'] );
$f = $r['figures'];
check( 'the figures are the worked example\'s strings: landed 257.14 / 142.86 / 0.00, suggested 130 / 50 / —, total 400.00, grand 390.00, revenue 440.00, up/down +50.00', '257.14' === $f['lines'][0]['landed_total'] && '142.86' === $f['lines'][1]['landed_total'] && '0.00' === $f['lines'][2]['landed_total'] && '130' === $f['lines'][0]['suggested_price'] && '50' === $f['lines'][1]['suggested_price'] && '—' === $f['lines'][2]['suggested_price'] && '400.00' === $f['order']['total'] && '390.00' === $f['order']['grand_total'] && '440.00' === $f['order']['revenue'] && '+50.00' === $f['order']['up_down'] && 'up' === $f['order']['up_down_flag'], json_encode( $f['order'] ) );
check( 'rates and labels: fill 57.1%, survival 84.2%, DOA 5.0%, fees per box 200.00, boxes 1.5, DOA cost 205.00 / 30.00, margin 24.4%, worth No', '57.1%' === $f['order']['fill_rate'] && '84.2%' === $f['order']['survival_rate'] && '5.0%' === $f['order']['doa_rate'] && '200.00' === $f['order']['fee_rate'] && 'Fees per full box' === $f['order']['fee_label'] && '1.5' === $f['order']['units'] && '205.00' === $f['order']['doa_cost_sheet'] && '30.00' === $f['order']['doa_cost_per_fish'] && '24.4%' === $f['lines'][0]['margin'] && 'No' === $f['lines'][0]['worth_it'] && false === $f['lines'][0]['worth_flag'] && '8' === $f['lines'][0]['per_box_used'] && '24' === $f['lines'][1]['per_box_used'] );
check( 'unlinked lines show no live / sold, the typed survived', '—' === $f['lines'][0]['live'] && '—' === $f['lines'][0]['sold'] && '6' === $f['lines'][0]['survived'] && '1' === $f['lines'][0]['doa'] && false === $f['lines'][0]['linked'] );
check( 'the landed totals are cached on the lines, to the cent', 257.14 === $store->order( 1 )['lines'][0]['landed_cached'] && 142.86 === $store->order( 1 )['lines'][1]['landed_cached'] && 0.0 === $store->order( 1 )['lines'][2]['landed_cached'] );
$r2 = Admin_Workbook::save_order( $store, array_merge( $raw, array( 'ref' => '2026-1' ) ), 0, $today );
check( 'another new order with the same ref is refused', ! empty( $r2['error'] ) && false !== strpos( $r2['error'], '2026-1' ) );
$raw2          = $raw;
$raw2['ref']   = '2026-1';
$raw2['lines'] = array( array_merge( $raw['lines'][0], array( 'id' => '1', 'qty_received' => '9' ) ), array_merge( $raw['lines'][3], array( 'id' => '3' ) ) );
$raw2['building_id'] = (string) $spa500;
$r3 = Admin_Workbook::save_order( $store, $raw2, 1, $today );
check( 're-saving the same order under its own ref: fine, the goby line deleted, the Spa building kept, not new', empty( $r3['error'] ) && false === $r3['is_new'] && '' === $r3['redirect'] && array( 1, 3 ) === $r3['line_ids'] && $spa500 === $store->order( 1 )['building_id'] && 9 === $store->order( 1 )['lines'][0]['qty_received'] );
$r4 = Admin_Workbook::save_order( $store, array( 'ref' => '', 'lines' => array() ), 0, $today );
check( 'a bare new order gets the next ref (2026-2 — the year from today, as it has no dates)', empty( $r4['error'] ) && substr( $today, 0, 4 ) . '-' . ( '2026' === substr( $today, 0, 4 ) ? '2' : '1' ) === $r4['ref'] );
$lists = Admin_Workbook::pick_lists( $store, $rates );
check( 'pick lists: the orders\' suppliers / origins plus the box rates\', sorted', array( 'Another Supplier', 'Test Supplier' ) === $lists['suppliers'] && array( 'Elsewhere', 'Testland' ) === $lists['origins'] );

echo "3. Check in, then the figures read the stay\n";
$outcome = Orders::check_in( $store, $store->order( 1 ), $today, $settings, array( 'actor_id' => 1 ) );
$order   = Admin_Workbook::resolve_order( $store, $store->order( 1 ) );
$f       = Admin_Workbook::figures( $order );
check( 'one stay (the blenny received nothing); the linked line shows live 8, sold 0, survived 8, doa 1', 1 === count( $outcome['created'] ) && true === $f['lines'][0]['linked'] && '8' === $f['lines'][0]['live'] && '0' === $f['lines'][0]['sold'] && '8' === $f['lines'][0]['survived'] && '1' === $f['lines'][0]['doa'] && '8' === $f['order']['survived'] );
$resolved = Admin_Workbook::resolved_orders( $store, array( 'year' => (int) substr( $today, 0, 4 ) ) );
$first    = null;
foreach ( $resolved as $candidate ) {
	if ( '2026-1' === $candidate['ref'] ) {
		$first = $candidate;
	}
}
check( 'resolved_orders: the year\'s orders, each costed, the checked-in one with live figures', 2 === count( $resolved ) && isset( $resolved[0]['costing'] ) && null !== $first && true === $first['lines'][0]['linked'] && 8 === $first['lines'][0]['live'] );
$stats = Admin_Workbook::stats_for_year( $store, (int) substr( $today, 0, 4 ) );
check( 'stats_for_year builds over them', 2 === $stats['totals']['orders'] && 1 === $stats['sheet']['batches'] && 8 === $stats['sheet']['through_qt'] );
$r5 = Admin_Workbook::save_order( $store, $raw2, 1, $today );
check( 'after the check-in a re-save offers no check-in (the wrasse has its stay, the blenny received nothing); can_check_in() is the same rule', empty( $r5['error'] ) && false === $r5['can_checkin'] && false === Admin_Workbook::can_check_in( $store->order( 1 ) ) && true === Admin_Workbook::can_check_in( array( 'lines' => array( array( 'stay_id' => null, 'qty_received' => 3 ) ) ) ) && false === Admin_Workbook::can_check_in( array( 'lines' => array( array( 'stay_id' => 4, 'qty_received' => 3 ), array( 'qty_received' => 0 ) ) ) ) );
check( 'product_titles: one lookup for the page, missing products left out, ids deduped', array( 12 => 'Blue Test Wrasse' ) === Admin_Workbook::product_titles( $store, array( 12, '12', 99, 0, null ) ) && array() === Admin_Workbook::product_titles( $store, array() ) );

echo "4. Export sheets\n";
$sheets = Admin_Workbook::orders_sheets( array( $order ) );
check( 'two sheets: Orders (header + 1) and Lines (header + 2)', array( 'Orders', 'Lines' ) === array_keys( $sheets ) && 2 === count( $sheets['Orders'] ) && 3 === count( $sheets['Lines'] ) );
$o = array_combine( $sheets['Orders'][0], $sheets['Orders'][1] );
check( 'the order row is typed: ints, floats, dates as text, the building number', 1 === $o['id'] && '2026-1' === $o['ref'] && '2026-03-01' === $o['order_date'] && 'received' === $o['status'] && 30.0 === $o['tariffs'] && 500 === $o['building'] && 2 === $o['lines'] && 15 === $o['ordered'] && 9 === $o['received'] && is_float( $o['total'] ) && near( $o['fill_rate'], 9 / 15 ) );
$l = array_combine( $sheets['Lines'][0], $sheets['Lines'][1] );
check( 'the line row: ref, species, the species group, per box used, units, the stay and tank, worth_it as a bool', '2026-1' === $l['order_ref'] && 'Blue Test Wrasse' === $l['species'] && 'Wrasses' === $l['group'] && 8 === $l['per_box'] && near( $l['units'], 9 / 8 ) && 1 === $l['stay_id'] && 511 === $l['tank'] && false === $l['worth_it'] && 8 === $l['live'] && 8 === $l['survived'] );
$sp = Admin_Workbook::species_rows( $store->species_list(), $store->open_stay_counts(), array( 12 => 'Blue Test Wrasse' ) );
check( 'the species sheet: header + 1, the product title and the open stays', 2 === count( $sp ) && 'Blue Test Wrasse' === $sp[1][1] && 'Blue Test Wrasse' === $sp[1][12] && 1 === $sp[1][13] && 40.0 === $sp[1][5] );
check( 'open_stays_of: by product when linked, else by name', 1 === Admin_Workbook::open_stays_of( array( 'product_id' => 12, 'name' => 'x' ), $store->open_stay_counts() ) && 1 === Admin_Workbook::open_stays_of( array( 'product_id' => null, 'name' => 'blue TEST wrasse' ), $store->open_stay_counts() ) && 0 === Admin_Workbook::open_stays_of( array( 'name' => 'nobody' ), $store->open_stay_counts() ) );
$st = Admin_Workbook::stats_sheets( $stats, 2026 );
check( 'the stats workbook: five sheets, the Totals sheet pairing weighted and sheet-style, the bare order under "(none)"', array( 'Orders', 'Totals', 'By supplier', 'By origin', 'By group' ) === array_keys( $st ) && 3 === count( $st['Orders'] ) && 'figure' === $st['Totals'][0][0] && 2026 === $st['Totals'][1][1] && 'survived' === $st['Totals'][5][0] && 8 === $st['Totals'][5][2] && 3 === count( $st['By supplier'] ) && '(none)' === $st['By supplier'][2][0] );
$stays = $store->open_stays( array(), $today, $settings );
$rows  = Admin_Workbook::stays_rows( $stays );
check( 'the stays sheet: the CSV header, typed cells (ids and counts as ints, landed cost a float, the building from the tank)', Admin::csv_header() === $rows[0] && 19 === count( $rows[1] ) && 1 === $rows[1][0] && 9 === $rows[1][3] && 511 === $rows[1][11] && 500 === $rows[1][12] && is_float( $rows[1][17] ) && 'order' === $rows[1][14] );

echo "5. The CSV of typed cells\n";
check( 'csv_value: null → empty, bools, floats as plain decimals, dates, the formula guard on text', '' === Admin_Workbook::csv_value( null ) && '1' === Admin_Workbook::csv_value( true ) && '0' === Admin_Workbook::csv_value( false ) && '12.5' === Admin_Workbook::csv_value( 12.5 ) && '2026-04-05' === Admin_Workbook::csv_value( new DateTime( '2026-04-05' ) ) && "'=SUM(A1)" === Admin_Workbook::csv_value( '=SUM(A1)' ) && '7' === Admin_Workbook::csv_value( 7 ) && "'-2.5" === Admin_Workbook::csv_value( '-2.5' ) );
check( 'a typed negative number is a number, not a guarded string (a −50 profit must not read \'-50); NaN / INF → empty', '-2.5' === Admin_Workbook::csv_value( -2.5 ) && '-50' === Admin_Workbook::csv_value( -50.0 ) && '-2' === Admin_Workbook::csv_value( -2 ) && '' === Admin_Workbook::csv_value( NAN ) && '' === Admin_Workbook::csv_value( INF ) );
$out = fopen( 'php://memory', 'w+' );
$n   = Admin_Workbook::write_csv( $out, array( array( 'a', 'b' ), array( '=x', 1.5, null, true, -3.25, -4 ), 'scalar' ) );
rewind( $out );
$text = stream_get_contents( $out );
fclose( $out );
check( 'write_csv: three rows, the guard applied to text only, a scalar row wrapped', 3 === $n && "a,b\n'=x,1.5,,1,-3.25,-4\nscalar\n" === $text, json_encode( $text ) );

echo "6. URLs and the flash\n";
check( 'export_url carries the action, what, format, extras and a nonce', false !== strpos( Admin_Workbook::export_url( 'stats', 'csv', array( 'year' => 2026 ) ), 'action=fishotel_cleanroom_export' ) && false !== strpos( Admin_Workbook::export_url( 'stats', 'csv', array( 'year' => 2026 ) ), 'what=stats' ) && false !== strpos( Admin_Workbook::export_url( 'stats', 'csv', array( 'year' => 2026 ) ), 'year=2026' ) );
check( 'order_url: the editor of an id, or new', false !== strpos( Admin_Workbook::order_url( 5 ), 'order=5' ) && false !== strpos( Admin_Workbook::order_url( 'new' ), 'order=new' ) );
Admin_Workbook::flash( 'csv instead' );
check( 'a flash is read once', 'csv instead' === Admin_Workbook::take_flash() && '' === Admin_Workbook::take_flash() );

fh_finish();
