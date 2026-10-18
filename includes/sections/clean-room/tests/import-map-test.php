<?php
/**
 * Mapper test against the synthetic fixture workbook.
 *
 * Run from the plugin root:  php includes/sections/clean-room/tests/import-map-test.php
 */

require_once __DIR__ . '/wp-stubs.php';
require_once __DIR__ . '/fixture-builder.php';

use FisHotel\Misc\Sections\Clean_Room\Workbook;
use FisHotel\Misc\Sections\Clean_Room\Import_Map;

/**
 * Records every sheet the mapper asks for.
 */
class Spy_Workbook extends Workbook {
	public $opened = array();

	public function rows( $name, $max_row = null ) {
		$this->opened[ (string) $name ] = true;
		return parent::rows( $name, $max_row );
	}
}

$dir  = fh_test_tmp_dir();
$path = fh_fixture_build( $dir . '/FisHotel-2031-fixture.xlsx' );

echo "class-import-map.php\n";

$wb = new Spy_Workbook();
check( 'fixture opens', $wb->open( $path ), $wb->last_error() );
$map = Import_Map::map( $wb );

check_same( 'map() keys', array( 'species', 'orders', 'box_rates', 'suppliers', 'origins', 'stock', 'warnings' ), array_keys( $map ) );
check_same( 'only known sheets are opened', array( 'Fishlist', 'Data', 'FishSheetStock', 'Recieved (1)', 'QT Tanks (1)', 'Recieved (2)', 'QT Tanks (2)', 'Recieved (3)', 'Recieved (4)' ), array_keys( $wb->opened ) );
check( 'PW is never opened', ! isset( $wb->opened['PW'] ) );
check_same( 'batch_sheets()', array( 1 => 'Recieved (1)', 2 => 'Recieved (2)', 3 => 'Recieved (3)', 4 => 'Recieved (4)' ), Import_Map::batch_sheets( $wb ) );
check_same( 'qt_sheets()', array( 1 => 'QT Tanks (1)', 2 => 'QT Tanks (2)', 3 => 'QT Tanks (3)' ), Import_Map::qt_sheets( $wb ) );

// Species.
$species = $map['species'];
check_same( 'species count stops at the first blank Fish cell', 5, count( $species ) );
check_same(
	'species[0] fields',
	array(
		'name'         => 'Blue Test Wrasse',
		'sci_name'     => 'Testus caeruleus',
		'my_price'     => 30,
		'online_price' => 25,
		'online_site'  => 'Dr Fake',
		'online_date'  => '2026-07-02',
		'per_box'      => 24,
		'size'         => 'Med',
	),
	$species[0]
);
check_same( 'species[1] date-styled serial becomes Y-m-d', '2026-07-02', $species[1]['online_date'] );
check_same( 'species[1] my_price', 45, $species[1]['my_price'] );
check_same( 'species[2] error price is null', null, $species[2]['my_price'] );
check_same( 'species[2] blank online fields are null', array( null, null, null, null ), array( $species[2]['online_price'], $species[2]['online_site'], $species[2]['online_date'], $species[2]['size'] ) );
check_same( 'species[3] typed price', 15, $species[3]['my_price'] );
check_same( 'species[3] invalid date text is null', null, $species[3]['online_date'] );
check_same( 'species[4] name', 'Orange Test Clown', $species[4]['name'] );
$names = array();
foreach ( $species as $row ) {
	$names[] = $row['name'];
}
check( 'stale row after the blank is not a species', ! in_array( 'Stale Test Fish', $names, true ) );

// Orders.
$orders = $map['orders'];
check_same( 'orders count (template, empty batch skipped)', 3, count( $orders ) );
$o1 = isset( $orders[0] ) ? $orders[0] : array();
$o2 = isset( $orders[1] ) ? $orders[1] : array();
$o4 = isset( $orders[2] ) ? $orders[2] : array();
check_same( 'order keys', array( 'sheet', 'batch_no', 'ref', 'supplier', 'origin', 'cost_mode', 'tariffs', 'domestic_flight', 'import_fees', 'credit', 'received_date', 'schedule', 'sheet_total', 'sheet_total_critters', 'lines', 'log' ), array_keys( $o1 ) );

check_same( 'order 1 sheet / batch_no / ref', array( 'Recieved (1)', 1, '2026-1' ), array( $o1['sheet'], $o1['batch_no'], $o1['ref'] ) );
check_same( 'order 1 supplier five columns right of From:', 'Test Supplier Co', $o1['supplier'] );
check_same( 'order 1 origin one column right of From:', 'TESTLAND', $o1['origin'] );
check_same( 'order 1 box mode from the Per Box header', 'box', $o1['cost_mode'] );
check_same( 'order 1 blank tariffs is null (label of the next field is not a value)', null, $o1['tariffs'] );
check_same( 'order 1 domestic flight', 150, $o1['domestic_flight'] );
check_same( 'order 1 import fees', 200.25, $o1['import_fees'] );
check_same( 'order 1 blank credit is null', null, $o1['credit'] );
check_same( 'order 1 received date from QT Tanks (1)', '2026-01-14', $o1['received_date'] );
check_same( 'order 1 schedule', '14 Day CM', $o1['schedule'] );
check_same( 'order 1 sheet total (C2)', 1234.5, $o1['sheet_total'] );
check_same( 'order 1 total critters (U2)', 17, $o1['sheet_total_critters'] );
check_same( 'order 1 line count (blank Name rows skipped)', 4, count( $o1['lines'] ) );
check_same(
	'order 1 line 1',
	array(
		'row'          => 5,
		'name'         => 'Blue Test Wrasse',
		'sci_name'     => 'Testus caeruleus',
		'qty_ordered'  => 10,
		'per_box'      => 24,
		'bags'         => null,
		'qty_received' => 10,
		'unit_price'   => 2.5,
		'worth'        => 30,
		'qty_survived' => 9,
		'qty_doa'      => 1,
		'sheet_total'  => 30.75,
	),
	$o1['lines'][0]
);
check_same( 'order 1 line 2 blank per_box', null, $o1['lines'][1]['per_box'] );
check_same( 'order 1 line 2 values', array( 6, 4, 8, 45, 4, 0, 40.5 ), array( $o1['lines'][1]['row'], $o1['lines'][1]['qty_received'], $o1['lines'][1]['unit_price'], $o1['lines'][1]['worth'], $o1['lines'][1]['qty_survived'], $o1['lines'][1]['qty_doa'], $o1['lines'][1]['sheet_total'] ) );
check_same( 'order 1 line 3 N/A sci name is null', null, $o1['lines'][2]['sci_name'] );
check_same( 'order 1 line 3 blank price / #REF! total are null', array( null, null, null ), array( $o1['lines'][2]['unit_price'], $o1['lines'][2]['sheet_total'], $o1['lines'][2]['worth'] ) );
check_same( 'order 1 line 4 row 9 with zero received', array( 9, 0, 1.25, 0 ), array( $o1['lines'][3]['row'], $o1['lines'][3]['qty_received'], $o1['lines'][3]['unit_price'], $o1['lines'][3]['sheet_total'] ) );
check_same( 'order 1 log count (tank label skipped, stops at blank)', 4, count( $o1['log'] ) );
check_same(
	'order 1 log entry 1',
	array(
		'process' => 'Dosed Copper',
		'amount'  => 55,
		'system'  => 205,
		'date'    => '2026-01-14',
		'time'    => '09:00',
		'result'  => null,
	),
	$o1['log'][0]
);
check_same( 'order 1 log entry 2 text amount', array( '5.125 G', 100, '2026-01-15', '12:00', 'ok' ), array( $o1['log'][1]['amount'], $o1['log'][1]['system'], $o1['log'][1]['date'], $o1['log'][1]['time'], $o1['log'][1]['result'] ) );
check_same( 'order 1 log entry 3 Both system + custom time format', array( 2.01, 'Both', '10:00' ), array( $o1['log'][2]['amount'], $o1['log'][2]['system'], $o1['log'][2]['time'] ) );
check_same( 'order 1 log entry 4 typed date / time text', array( 'Dosed Praziquantel', '8 & 20', '2026-01-18', '18:45' ), array( $o1['log'][3]['process'], $o1['log'][3]['amount'], $o1['log'][3]['date'], $o1['log'][3]['time'] ) );

check_same( 'order 2 sheet / batch_no / ref', array( 'Recieved (2)', 2, '2026-2' ), array( $o2['sheet'], $o2['batch_no'], $o2['ref'] ) );
check_same( 'order 2 bag mode from the Bags header', 'bag', $o2['cost_mode'] );
check_same( 'order 2 supplier found with the block shifted down and right', 'Test Supplier Two', $o2['supplier'] );
check_same( 'order 2 blank origin is null', null, $o2['origin'] );
check_same( 'order 2 tariffs', 12.75, $o2['tariffs'] );
check_same( 'order 2 blank domestic flight', null, $o2['domestic_flight'] );
check_same( 'order 2 import fees', 300, $o2['import_fees'] );
check_same( 'order 2 credit two rows under the label', 12.5, $o2['credit'] );
check_same( 'order 2 total critters in V2', 9, $o2['sheet_total_critters'] );
check_same( 'order 2 received date / schedule', array( '2026-10-05', '14 Day CF' ), array( $o2['received_date'], $o2['schedule'] ) );
check_same( 'order 2 line count', 2, count( $o2['lines'] ) );
check_same( 'order 2 line 1 bags / per_box', array( 2, null ), array( $o2['lines'][0]['bags'], $o2['lines'][0]['per_box'] ) );
check_same( 'order 2 line 1 survived / doa past the extra column', array( 5, 1 ), array( $o2['lines'][0]['qty_survived'], $o2['lines'][0]['qty_doa'] ) );
check_same( 'order 2 line 2 values', array( 'Blue Test Wrasse', 4, null, 3, 2, 30, 3, 0, 9.5 ), array( $o2['lines'][1]['name'], $o2['lines'][1]['qty_ordered'], $o2['lines'][1]['bags'], $o2['lines'][1]['qty_received'], $o2['lines'][1]['unit_price'], $o2['lines'][1]['worth'], $o2['lines'][1]['qty_survived'], $o2['lines'][1]['qty_doa'], $o2['lines'][1]['sheet_total'] ) );
check_same( 'order 2 log', array( array( 'process' => 'Dosed Copper', 'amount' => 20, 'system' => 205, 'date' => '2026-10-05', 'time' => '10:30', 'result' => 'ok' ) ), $o2['log'] );

check_same( 'order 4 ref falls back to the file-name year', '2031-4', $o4['ref'] );
check_same( 'order 4 no block, no QT', array( null, null, null, null, array() ), array( $o4['supplier'], $o4['origin'], $o4['received_date'], $o4['schedule'], $o4['log'] ) );
check_same( 'order 4 line with missing headers', array( 'Green Test Tang', 1, 1, null, null, null, 1 ), array( $o4['lines'][0]['name'], $o4['lines'][0]['qty_received'], $o4['lines'][0]['unit_price'], $o4['lines'][0]['worth'], $o4['lines'][0]['qty_survived'], $o4['lines'][0]['qty_doa'], $o4['lines'][0]['sheet_total'] ) );

// Data.
check_same( 'suppliers', array( 'Test Supplier Co', 'Test Supplier Two', 'Other' ), $map['suppliers'] );
check_same( 'origins', array( 'TESTLAND', 'FAKEISLAND', 'NOWHERE' ), $map['origins'] );
check_same( 'box rate count', 5, count( $map['box_rates'] ) );
check_same( 'box rate 1', array( 'supplier' => 'Test Supplier Co', 'origin' => 'TESTLAND', 'per_box' => 100, 'reox' => 10 ), $map['box_rates'][0] );
check_same( 'box rate 2 float per box', 120.5, $map['box_rates'][1]['per_box'] );
check_same( 'box rate 3 blank reox', array( 'NOWHERE', 90, null ), array( $map['box_rates'][2]['origin'], $map['box_rates'][2]['per_box'], $map['box_rates'][2]['reox'] ) );
check_same( 'box rate 4 second supplier block', array( 'supplier' => 'Test Supplier Two', 'origin' => 'TESTLAND', 'per_box' => 95, 'reox' => 8 ), $map['box_rates'][3] );
check_same( 'box rate 5', array( 'Test Supplier Two', 'FAKEISLAND', 110, 0.15 ), array_values( $map['box_rates'][4] ) );

// Stock.
check_same( 'stock count (blank row skipped, taxonomy-only row kept)', 4, count( $map['stock'] ) );
check_same( 'stock 1', array( 'group' => 'Wrasse Test', 'common_name' => 'Blue Test Wrasse', 'sci_name' => 'Testus caeruleus', 'reef_safe' => 'Yes', 'max_size' => '4"' ), $map['stock'][0] );
check_same( 'stock 2 numeric max size as text', '8', $map['stock'][1]['max_size'] );
check_same( 'stock 4 taxonomy-only row', array( 'Blenny Test', null, 'Testus ruber' ), array( $map['stock'][3]['group'], $map['stock'][3]['common_name'], $map['stock'][3]['sci_name'] ) );

// Warnings.
$warnings = $map['warnings'];
check_same( 'warning count', 10, count( $warnings ) );
check_contains( 'warning: template skipped', '"Recieved" skipped: it is the empty batch template', $warnings );
check_contains( 'warning: empty batch skipped', 'Recieved (3) skipped: no line rows', $warnings );
check_contains( 'warning: species missing from Fishlist', 'Recieved (1) row 7: species "Unknown Test Fish" is not in Fishlist', $warnings );
check_contains( 'warning: received but no price', 'Recieved (1) row 7: 3 received but no price', $warnings );
check_contains( 'warning: cached Total not a number', 'Recieved (1) row 7: cached Total is not a number (#REF!)', $warnings );
check_contains( 'warning: header Worth $ not found', 'Recieved (4): header "worth $" not found', $warnings );
check_contains( 'warning: header Srv\'d not found', 'Recieved (4): header "srv\'d" not found', $warnings );
check_contains( 'warning: From: label not found', 'Recieved (4): "From:" label not found', $warnings );
check_contains( 'warning: Credit label not found', 'Recieved (4): "Credit" label not found', $warnings );
check_contains( 'warning: no paired QT sheet', 'Recieved (4): no paired "QT Tanks (4)" sheet', $warnings );
$mentions_pw = false;
$row9        = false;
foreach ( $warnings as $warning ) {
	if ( false !== stripos( $warning, 'PW' ) ) {
		$mentions_pw = true;
	}
	if ( false !== strpos( $warning, 'Recieved (1) row 9' ) ) {
		$row9 = true;
	}
}
check( 'no warning mentions PW', ! $mentions_pw );
check( 'no price warning for a line with nothing received', ! $row9 );

// read_qt() on its own (used by the harness for batches without an order).
$qt_warnings = array();
$qt3         = Import_Map::read_qt( $wb, 'QT Tanks (3)', $qt_warnings );
check_same( 'read_qt() on an unused sheet', array( 'start_date' => null, 'schedule' => '14 Day CF', 'log' => array() ), $qt3 );
check_same( 'read_qt() no warnings for an unused sheet', array(), $qt_warnings );

// Explicit default year.
$map2031 = Import_Map::map( $wb, 1999 );
check_same( 'explicit default year is used for batches without a QT date', '1999-4', $map2031['orders'][2]['ref'] );
check_same( 'explicit default year does not override a QT date year', '2026-1', $map2031['orders'][0]['ref'] );

// Value helpers.
check_same( 'num($1,234.50)', 1234.5, Import_Map::num( '$1,234.50' ) );
check_same( 'num(12%)', 0.12, Import_Map::num( '12%' ) );
check_same( 'num((5))', -5, Import_Map::num( '(5)' ) );
check_same( 'num(abc)', null, Import_Map::num( 'abc' ) );
check_same( 'num(#REF!)', null, Import_Map::num( '#REF!' ) );
check_same( 'num(true)', null, Import_Map::num( true ) );
check_same( 'num(7)', 7, Import_Map::num( 7 ) );
check_same( 'qty(3.0)', 3, Import_Map::qty( 3.0 ) );
check_same( 'qty("4")', 4, Import_Map::qty( '4' ) );
check_same( 'qty(2.5)', 2.5, Import_Map::qty( 2.5 ) );
check_same( 'to_ymd(7/2/2026)', '2026-07-02', Import_Map::to_ymd( '7/2/2026' ) );
check_same( 'to_ymd(7/2/26)', '2026-07-02', Import_Map::to_ymd( '7/2/26' ) );
check_same( 'to_ymd(Y-m-d H:i)', '2026-07-02', Import_Map::to_ymd( '2026-07-02 18:00' ) );
check_same( 'to_ymd(serial)', '2026-01-14', Import_Map::to_ymd( 46036 ) );
check_same( 'to_ymd(nonsense)', null, Import_Map::to_ymd( 'nonsense' ) );
check_same( 'to_ymd(0.5)', null, Import_Map::to_ymd( 0.5 ) );
check_same( 'to_hm(6:45 PM)', '18:45', Import_Map::to_hm( '6:45 PM' ) );
check_same( 'to_hm(12:05 am)', '00:05', Import_Map::to_hm( '12:05 am' ) );
check_same( 'to_hm(12:30 PM)', '12:30', Import_Map::to_hm( '12:30 PM' ) );
check_same( 'to_hm(0.75)', '18:00', Import_Map::to_hm( 0.75 ) );
check_same( 'to_hm(Y-m-d H:i)', '08:15', Import_Map::to_hm( '2026-01-01 08:15' ) );
check_same( 'to_hm(H:i:s)', '08:15', Import_Map::to_hm( '8:15:30' ) );
check_same( 'to_hm(noon) keeps text', 'noon', Import_Map::to_hm( 'noon' ) );
check_same( 'norm curly apostrophe', "rcv'd", Import_Map::norm( "Rcv\xE2\x80\x99d" ) );
check_same( 'norm number', '4', Import_Map::norm( 4 ) );
check_same( 'norm spacing', 'worth $', Import_Map::norm( "  Worth \xC2\xA0 $ " ) );
check_same( 'sci_name(N/A)', null, Import_Map::sci_name( 'N/A' ) );
check_same( 'sci_name(#N/A)', null, Import_Map::sci_name( '#N/A' ) );
check_same( 'sci_name(text)', 'Testus caeruleus', Import_Map::sci_name( ' Testus caeruleus ' ) );
check_same( 'is_error(#REF!)', true, Import_Map::is_error( '#REF!' ) );
check_same( 'is_error(#hashtag)', false, Import_Map::is_error( '#hashtag' ) );
check_same( 'text(#N/A) is null', null, Import_Map::text( '#N/A' ) );
check_same( 'raw_text(#N/A) is kept', '#N/A', Import_Map::raw_text( '#N/A' ) );
check_same( 'text(blank)', null, Import_Map::text( '   ' ) );
check_same( 'scalar(number)', 2.01, Import_Map::scalar( 2.01 ) );

// A closed workbook maps to a single warning.
$closed = new Workbook();
$empty  = Import_Map::map( $closed );
check_same( 'closed workbook gives one warning', 1, count( $empty['warnings'] ) );
check_same( 'closed workbook gives no data', array( array(), array(), array() ), array( $empty['species'], $empty['orders'], $empty['stock'] ) );

$wb->close();
fh_test_rmdir( $dir );
fh_finish();
