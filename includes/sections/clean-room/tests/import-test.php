<?php
/**
 * Import — the workbook into the store, on the synthetic fixture
 * workbook (invented names, prices and dates in the real layout) and the
 * in-memory Fake_Store: the pure preview counts, the species upsert (the
 * sheet's values fill the row, a value the sheet lacks never blanks one
 * the site has, the stock list fills a blank group / reef-safe / max
 * size), the box rates merged into the option, the orders created by ref
 * with their lines and the landed totals cached, the recent batches
 * checked in as stays (source import, the DOA loss) on the chosen Spa
 * building, the treatment logs by system (205 / 100 / Both / blank)
 * including the copper reading, and a second run that creates nothing.
 * Then the pure helpers of the admin panel.
 *
 * Run from the plugin root:  php includes/sections/clean-room/tests/import-test.php
 */

require_once __DIR__ . '/wp-stubs.php';
require_once __DIR__ . '/fixture-builder.php';

use FisHotel\Misc\Sections\Clean_Room\Admin_Import;
use FisHotel\Misc\Sections\Clean_Room\Import;
use FisHotel\Misc\Sections\Clean_Room\Import_Map;
use FisHotel\Misc\Sections\Clean_Room\Layout;
use FisHotel\Misc\Sections\Clean_Room\Settings;
use FisHotel\Misc\Sections\Clean_Room\Species;
use FisHotel\Misc\Sections\Clean_Room\Tests\Fake_Store;
use FisHotel\Misc\Sections\Clean_Room\Workbook;

function near( $a, $b, $eps = 1e-9 ) {
	return null !== $a && null !== $b && abs( (float) $a - (float) $b ) < $eps;
}

$dir  = fh_test_tmp_dir();
$path = fh_fixture_build( $dir . '/FisHotel-2031-fixture.xlsx' );
$wb   = new Workbook();
check( 'the fixture opens', $wb->open( $path ), $wb->last_error() );
$map = Import_Map::map( $wb );
$wb->close();
fh_test_rmdir( $dir );

$today    = '2026-10-17';
$settings = Settings::get();

echo "1. Markers\n";
check( 'marker() / marker_of() / strip_marker()', 'import:2026-1#4' === Import::marker( ' 2026-1 ', 4 ) && 'import:2026-1#4' === Import::marker_of( 'Praziquantel 8 & 20 · import:2026-1#4' ) && '' === Import::marker_of( 'Praziquantel 2 g' ) && 'Praziquantel 8 & 20' === Import::strip_marker( 'Praziquantel 8 & 20 · import:2026-1#4' ) && 'plain' === Import::strip_marker( 'plain' ) && '' === Import::strip_marker( 'import:2026-1#3' ) && 'fine' === Import::strip_marker( 'fine · import:2026-1#3' ) );

echo "2. The preview (pure)\n";
$p = Import::preview( $map, array( 'species' => array(), 'order_refs' => array(), 'box_rates' => array() ), array( 'today' => $today, 'stays_days' => 45 ) );
check( 'an empty site: 5 species to create, 3 orders with 7 lines and 5 log rows, 5 box rates, 4 stock rows, the reader\'s 10 warnings', array( 'create' => 5, 'update' => 0, 'skip' => 0 ) === $p['species'] && array( 'create' => 3, 'skip' => 0, 'lines' => 7, 'logs' => 5 ) === $p['orders'] && array( 'create' => 5, 'update' => 0, 'skip' => 0 ) === $p['box_rates'] && 4 === $p['stock'] && 10 === count( $p['warnings'] ), json_encode( $p ) );
check( 'the stays estimate: the one batch received within 45 days of today, its 2 lines with fish', array( 'orders' => 1, 'lines' => 2 ) === $p['stays'] );
check( 'with 0 days (or no today) no stays are estimated', array( 'orders' => 0, 'lines' => 0 ) === Import::preview( $map, array(), array( 'today' => $today, 'stays_days' => 0 ) )['stays'] && array( 'orders' => 0, 'lines' => 0 ) === Import::preview( $map, array() )['stays'] );
$p = Import::preview( $map, array( 'species' => array( 'blue test WRASSE', array( 'name' => 'Green Test Tang' ) ), 'order_refs' => array( '2026-1', 'x' ), 'box_rates' => array( array( 'supplier' => 'test supplier co', 'origin' => 'TESTLAND' ), 'test supplier two|fakeisland' ) ) );
check( 'what exists updates or skips: 2 species update (any case, names or rows), 1 order skipped by ref, 2 rates update (rows or keys)', array( 'create' => 3, 'update' => 2, 'skip' => 0 ) === $p['species'] && array( 'create' => 2, 'skip' => 1, 'lines' => 3, 'logs' => 1 ) === $p['orders'] && array( 'create' => 3, 'update' => 2, 'skip' => 0 ) === $p['box_rates'], json_encode( $p ) );
$dup            = $map;
$dup['species'][] = array( 'name' => ' BLUE test wrasse ', 'sci_name' => 'x' );
$dup['box_rates'][] = array( 'supplier' => null, 'origin' => 'Nowhere', 'per_box' => 5 );
$dup['box_rates'][] = array( 'supplier' => 'S', 'origin' => 'O', 'per_box' => null );
check( 'a name twice in the sheet is skipped the second time; a rate without a supplier or a numeric per box is skipped', 1 === Import::preview( $dup, array() )['species']['skip'] && 5 === Import::preview( $dup, array() )['species']['create'] && 2 === Import::preview( $dup, array() )['box_rates']['skip'] );

echo "3. The run: species\n";
$store = new Fake_Store( array( 12 => 'Blue Test Wrasse' ) );
$spa5  = null;
$spa6  = null;
foreach ( $store->buildings() as $b ) {
	if ( 500 === (int) $b['number'] ) {
		$spa5 = (int) $b['id'];
	}
	if ( 600 === (int) $b['number'] ) {
		$spa6 = (int) $b['id'];
	}
}
$options = array( 'today' => $today, 'settings' => $settings, 'actor_id' => 3, 'stays_days' => 45, 'stays_building_id' => $spa5, 'dose_logs' => true );
$r       = Import::run( $map, $store, $options );
check( 'species: 5 created, none updated or skipped, no errors', array( 'created' => 5, 'updated' => 0, 'skipped' => 0 ) === $r['species'] && array() === $r['errors'], json_encode( $r ) );
$wrasse = $store->species_by_name( 'Blue Test Wrasse' );
check( 'a species row: name, scientific name, per box 24, my price 30, online 25 / Dr Fake / 2026-07-02', 'Blue Test Wrasse' === $wrasse['name'] && 'Testus caeruleus' === $wrasse['sci_name'] && 24 === $wrasse['per_box'] && near( $wrasse['my_price'], 30 ) && near( $wrasse['online_price'], 25 ) && 'Dr Fake' === $wrasse['online_site'] && '2026-07-02' === $wrasse['online_date'] && null === $wrasse['product_id'], json_encode( $wrasse ) );
check( 'enriched from the stock list by scientific name: group, reef-safe yes, max size 4"', 'Wrasse Test' === $wrasse['group_name'] && 'yes' === $wrasse['reef_safe'] && '4"' === $wrasse['max_size'] );
$tang = $store->species_by_name( 'Green Test Tang' );
$goby = $store->species_by_name( 'Yellow Test Goby' );
check( '"With Caution" → caution, a numeric max size as text; an error-cell price is null; "No" → no', 'caution' === $tang['reef_safe'] && '8' === $tang['max_size'] && 'Tang Test' === $tang['group_name'] && null === $goby['my_price'] && 'no' === $goby['reef_safe'] && 50 === $goby['per_box'] );
$blenny = $store->species_by_name( 'Red Test Blenny' );
$clown  = $store->species_by_name( 'Orange Test Clown' );
check( 'a stock row with a scientific name only still matches; a species without a stock row keeps blanks; per box from the sheet', 'Blenny Test' === $blenny['group_name'] && 'yes' === $blenny['reef_safe'] && 75 === $blenny['per_box'] && '' === $clown['group_name'] && '' === $clown['reef_safe'] && near( $clown['my_price'], 25 ) && null === $clown['online_price'] );

echo "4. The run: box rates\n";
$rates = Settings::box_rates();
check( '5 rows in the option, sorted, the blank re-ox 0', 5 === $r['box_rates']['rows'] && 5 === count( $rates ) && 'Test Supplier Co' === $rates[0]['supplier'] && 'FAKEISLAND' === $rates[0]['origin'] && near( $rates[0]['per_box'], 120.5 ) && near( $rates[0]['reox'], 12 ) && near( Settings::box_rate_for( $rates, 'Test Supplier Co', 'NOWHERE' )['reox'], 0 ) && near( Settings::box_rate_for( $rates, 'Test Supplier Two', 'FAKEISLAND' )['reox'], 0.15 ), json_encode( $rates ) );

echo "5. The run: orders and lines\n";
check( '3 orders created with 7 lines, none skipped', array( 'created' => 3, 'skipped' => 0, 'lines' => 7 ) === $r['orders'] );
$o1 = $store->order_by_ref( '2026-1' );
$o1 = $store->order( $o1['id'] );
check( 'an old batch: closed, box mode, supplier / origin, the fees (a blank tariff 0), the received date from the QT sheet, its schedule, the chosen Spa building, a note naming the sheet', 'closed' === $o1['status'] && 'box' === $o1['cost_mode'] && 'Test Supplier Co' === $o1['supplier'] && 'TESTLAND' === $o1['origin'] && near( $o1['tariffs'], 0 ) && near( $o1['domestic_flight'], 150 ) && near( $o1['import_fees'], 200.25 ) && near( $o1['credit'], 0 ) && '2026-01-14' === $o1['received_date'] && null === $o1['order_date'] && '14 Day CM' === $o1['schedule'] && $spa5 === $o1['building_id'] && false !== strpos( $o1['notes'], 'Recieved (1)' ), json_encode( $o1 ) );
$l = $o1['lines'];
check( 'its 4 lines in sheet order, the species ids by name (an unknown fish has none), the figures as mapped', 4 === count( $l ) && 'Blue Test Wrasse' === $l[0]['species'] && (int) $wrasse['id'] === $l[0]['species_id'] && 'Testus caeruleus' === $l[0]['sci_name'] && 10 === $l[0]['qty_ordered'] && 24 === $l[0]['per_box'] && 10 === $l[0]['qty_received'] && near( $l[0]['unit_price'], 2.5 ) && near( $l[0]['worth'], 30 ) && 9 === $l[0]['qty_survived'] && 1 === $l[0]['qty_doa'] && null === $l[2]['species_id'] && 'Unknown Test Fish' === $l[2]['species'] && null === $l[2]['unit_price'] && 0 === $l[3]['qty_received'] && (int) $blenny['id'] === $l[3]['species_id'] && null === $l[1]['per_box'] );
check( 'the landed totals are cached through the costing (box mode: fees per box over the fractions)', is_float( $l[0]['landed_cached'] ) && $l[0]['landed_cached'] > 25 && is_float( $l[1]['landed_cached'] ) && 0.0 === $l[3]['landed_cached'] );
$o2 = $store->order( $store->order_by_ref( '2026-2' )['id'] );
check( 'the recent batch: received, bag mode, tariffs 12.75, import 300, credit 12.5, no origin, 14 Day CF', 'received' === $o2['status'] && 'bag' === $o2['cost_mode'] && near( $o2['tariffs'], 12.75 ) && near( $o2['import_fees'], 300 ) && near( $o2['credit'], 12.5 ) && '' === $o2['origin'] && 'Test Supplier Two' === $o2['supplier'] && '14 Day CF' === $o2['schedule'] && 2 === count( $o2['lines'] ) && 2 === $o2['lines'][0]['bags'] );
$o4 = $store->order( $store->order_by_ref( '2031-4' )['id'] );
check( 'a batch without a QT date: ordered, no received date, 1 line', 'ordered' === $o4['status'] && null === $o4['received_date'] && 1 === count( $o4['lines'] ) );

echo "6. The run: the recent batch checked in as stays\n";
check( '2 stays from the 1 recent order, none skipped', array( 'created' => 2, 'skipped' => 0, 'orders' => 1 ) === $r['stays'], json_encode( $r['stays'] ) );
$s1 = $store->stay( $o2['lines'][0]['stay_id'], $today, $settings );
$s2 = $store->stay( $o2['lines'][1]['stay_id'], $today, $settings );
check( 'the clown: 6 arrived, 1 DOA → 5 live, checked in on the batch date, in the first empty tank of building 500, source import, the seam fields', 6 === $s1['qty_arrived'] && 5 === $s1['qty_live'] && '2026-10-05' === $s1['checkin_date'] && 511 === $s1['tank_number'] && 'import' === $s1['source'] && 'Test Supplier Two' === $s1['supplier'] && '2026-2' === $s1['order_ref'] && (int) $o2['lines'][0]['id'] === $s1['order_line_id'] && is_float( $s1['landed_cost'] ), json_encode( $s1 ) );
$ev = $store->events( $s1['id'] );
check( 'the arrival and the doa loss carry source import, the actor, the batch date', 2 === count( $ev ) && 'arrival' === $ev[0]['type'] && 'import' === $ev[0]['source'] && 3 === $ev[0]['actor_id'] && 'loss' === $ev[1]['type'] && 'doa' === $ev[1]['note'] && 'import' === $ev[1]['source'] && 1 === $ev[1]['qty'] );
check( 'the wrasse: 3 live in 512, linked to the product by the species row? no — the species row has no product, so none', 3 === $s2['qty_live'] && 512 === $s2['tank_number'] && null === $s2['product_id'] );
check( 'the old batch got no stays', null === $l[0]['stay_id'] && null === $l[1]['stay_id'] );

echo "7. The run: the treatment logs by system\n";
check( '6 logs written (205 → 500, 100 → 600, Both → both), none skipped', array( 'created' => 6, 'skipped' => 0 ) === $r['logs'], json_encode( $r['logs'] ) );
$logs = array_values( $store->logs );
check( 'row 1: Dosed Copper 55 on 205 → a dose on building 500, value 55, note "Copper Power 55 · import:2026-1#1", 09:00 Chicago = 15:00 UTC, source import, tank 0', 'dose' === $logs[0]['kind'] && 0 === $logs[0]['tank_id'] && $spa5 === $logs[0]['building_id'] && 55.0 === $logs[0]['value'] && 'Copper Power 55 · import:2026-1#1' === $logs[0]['note'] && '2026-01-14 15:00:00' === $logs[0]['logged_at'] && 'import' === $logs[0]['source'], json_encode( $logs[0] ) );
check( 'row 2: Dosed Metro "5.125 G" on 100 → building 600, value 5.125, the result appended', $spa6 === $logs[1]['building_id'] && 5.125 === $logs[1]['value'] && 'Metronidazole 5.125 G — ok · import:2026-1#2' === $logs[1]['note'] && '2026-01-15 18:00:00' === $logs[1]['logged_at'] );
check( 'row 3: Tested Copper 2.01 on Both → a copper reading on 500 AND 600, the result in the note', 'copper' === $logs[2]['kind'] && 'copper' === $logs[3]['kind'] && 2.01 === $logs[2]['value'] && 2.01 === $logs[3]['value'] && array( $spa5, $spa6 ) === array( $logs[2]['building_id'], $logs[3]['building_id'] ) && 'fine · import:2026-1#3' === $logs[2]['note'] );
check( 'row 4: Dosed Praziquantel "8 & 20" on 205 → value 8, the text kept, 18:45 Chicago = 00:45 UTC the next day', 'dose' === $logs[4]['kind'] && 8.0 === $logs[4]['value'] && 'Praziquantel 8 & 20 · import:2026-1#4' === $logs[4]['note'] && '2026-01-19 00:45:00' === $logs[4]['logged_at'] && $spa5 === $logs[4]['building_id'] );
check( 'the second batch\'s copper dose on 205 → 500', 'dose' === $logs[5]['kind'] && $spa5 === $logs[5]['building_id'] && 'Copper Power 20 — ok · import:2026-2#1' === $logs[5]['note'] );
check( 'the Board reads the last doses per building from them', isset( $store->latest_doses()[ $spa5 ]['copper'] ) && isset( $store->latest_doses()[ $spa5 ]['prazi'] ) && isset( $store->latest_doses()[ $spa6 ]['metro'] ) );

echo "8. A second run creates nothing\n";
$store->save_species( Species::normalize( array_merge( $goby, array( 'my_price' => 12 ) ) ) );
$store->save_species( Species::normalize( array_merge( $wrasse, array( 'group_name' => 'My Group', 'my_price' => 99 ) ) ) );
$stays_before  = count( $store->stays );
$events_before = $store->event_count();
$logs_before   = count( $store->logs );
$r2            = Import::run( $map, $store, $options );
check( 'species: 5 updated, 0 created; orders 0 created, 3 skipped; stays 0 created, the 2 lines skipped; logs 0 created, 6 skipped; the rates unchanged', array( 'created' => 0, 'updated' => 5, 'skipped' => 0 ) === $r2['species'] && array( 'created' => 0, 'skipped' => 3, 'lines' => 0 ) === $r2['orders'] && array( 'created' => 0, 'skipped' => 2, 'orders' => 1 ) === $r2['stays'] && array( 'created' => 0, 'skipped' => 6 ) === $r2['logs'] && 5 === $r2['box_rates']['rows'], json_encode( $r2 ) );
check( 'nothing new in the store', $stays_before === count( $store->stays ) && $events_before === $store->event_count() && $logs_before === count( $store->logs ) && 3 === count( $store->orders() ) && 5 === count( Settings::box_rates() ) );
$goby   = $store->species_by_name( 'Yellow Test Goby' );
$wrasse = $store->species_by_name( 'Blue Test Wrasse' );
check( 'a value the sheet lacks never blanks the site\'s (the goby keeps its price); the sheet\'s price wins where it has one; a group typed on the site is kept', near( $goby['my_price'], 12 ) && near( $wrasse['my_price'], 30 ) && 'My Group' === $wrasse['group_name'] && 'Testus caeruleus' === $wrasse['sci_name'] );
check( 'an existing order is never overwritten (the closed old batch stays closed)', 'closed' === $store->order_by_ref( '2026-1' )['status'] );
$zero = $map;
foreach ( $zero['species'] as $i => $row ) {
	if ( 'Red Test Blenny' === $row['name'] ) {
		$zero['species'][ $i ]['per_box'] = 0;
	}
}
Import::run( $zero, $store, $options );
check( 'a per box of 0 in the sheet is a value it lacks: the site\'s 75 stays (not the default 24)', 75 === $store->species_by_name( 'Red Test Blenny' )['per_box'] );

echo "9. Options: no stays, no logs, another building\n";
$store2 = new Fake_Store();
$r3     = Import::run( $map, $store2, array( 'today' => $today, 'settings' => $settings, 'stays_days' => 0, 'dose_logs' => false ) );
check( 'stays_days 0 and dose_logs off: orders only, the recent batch closed too? no — without a cutoff every dated batch is history: closed', array( 'created' => 0, 'skipped' => 0, 'orders' => 0 ) === $r3['stays'] && array( 'created' => 0, 'skipped' => 0 ) === $r3['logs'] && 0 === count( $store2->stays ) && 0 === count( $store2->logs ) && 'closed' === $store2->order_by_ref( '2026-2' )['status'] && null === $store2->order_by_ref( '2026-2' )['building_id'] );
$store3 = new Fake_Store();
$r4     = Import::run( $map, $store3, array( 'today' => $today, 'settings' => $settings, 'stays_days' => 400, 'stays_building_id' => $spa6, 'dose_logs' => true ) );
check( 'a 400-day cutoff checks the old batch in too, on building 600: its 3 lines with fish (the unknown fish included) into 611, 621 and then the next empty Spa tank, the line with nothing received skipped, the no-date batch never', array( 'created' => 5, 'skipped' => 1, 'orders' => 2 ) === $r4['stays'] && array( 611, 621, 511 ) === array( $store3->stay( 1 )['tank_number'], $store3->stay( 2 )['tank_number'], $store3->stay( 3 )['tank_number'] ) && $spa6 === $store3->order_by_ref( '2026-1' )['building_id'] && 'received' === $store3->order_by_ref( '2026-1' )['status'], json_encode( $r4['stays'] ) );
$store4 = new Fake_Store();
$r5     = Import::run( $map, $store4, array( 'today' => $today, 'settings' => $settings, 'stays_days' => 45, 'stays_building_id' => 1, 'dose_logs' => false ) );
check( 'a building that is not a Spa one is ignored: the order gets none and the stays still land in the first empty Spa tank', 2 === $r5['stays']['created'] && null === $store4->order_by_ref( '2026-2' )['building_id'] && 511 === $store4->stay( 1 )['tank_number'] && array() === $r5['errors'] );

echo "10. The system → building rule (pure)\n";
$spa = array(
	array( 'id' => 5, 'number' => 500, 'gallons' => 205, 'tanks' => 7 ),
	array( 'id' => 6, 'number' => 600, 'gallons' => 100, 'tanks' => 2 ),
);
check( '205 / 100 by volume, "Both" both, any case', array( 5 ) === Import::buildings_for_system( 205, $spa, null, null ) && array( 6 ) === Import::buildings_for_system( '100', $spa, null, null ) && array( 5, 6 ) === Import::buildings_for_system( 'Both', $spa, null, null ) && array( 5, 6 ) === Import::buildings_for_system( ' both ', $spa, null, null ) );
check( 'blank: the order\'s building, else the chosen one, else the big system', array( 6 ) === Import::buildings_for_system( null, $spa, 6, 5 ) && array( 5 ) === Import::buildings_for_system( '', $spa, null, 5 ) && array( 6 ) === Import::buildings_for_system( '', $spa, null, 6 ) && array( 5 ) === Import::buildings_for_system( null, $spa, 99, 99 ) && array( 5 ) === Import::buildings_for_system( 'tank 3', $spa, null, null ) );
$unknown = array(
	array( 'id' => 5, 'number' => 500, 'gallons' => 0, 'tanks' => 7 ),
	array( 'id' => 6, 'number' => 600, 'gallons' => 0, 'tanks' => 2 ),
);
check( 'no volumes known: 205 → the building with the most tanks, 100 → the other; an odd number by size', array( 5 ) === Import::buildings_for_system( 205, $unknown, null, null ) && array( 6 ) === Import::buildings_for_system( 100, $unknown, null, null ) && array( 5 ) === Import::buildings_for_system( 150, $unknown, null, null ) && array( 6 ) === Import::buildings_for_system( 40, $unknown, null, null ) );
check( 'one Spa building takes everything; none → nothing', array( 6 ) === Import::buildings_for_system( 205, array( $spa[1] ), null, null ) && array( 6 ) === Import::buildings_for_system( 'Both', array( $spa[1] ), null, null ) && array() === Import::buildings_for_system( 205, array(), null, null ) );
check( 'spa_buildings() from the store: 500 (205 gal, 7 tanks) and 600 (100 gal, 2 tanks)', array( array( 'id' => $spa5, 'number' => 500, 'gallons' => 205, 'tanks' => 7 ), array( 'id' => $spa6, 'number' => 600, 'gallons' => 100, 'tanks' => 2 ) ) === Import::spa_buildings( $store ) );

echo "11. A log row → a log entry (pure)\n";
$e = Import::log_entry( array( 'process' => 'Dosed Fenbendazole', 'amount' => '2 g', 'system' => 205, 'date' => '2026-03-02', 'time' => '08:15', 'result' => null ), '2026-3', 2 );
check( 'a dose: the medication by alias, the amount text, the numeric part, the time', array( 'kind' => 'dose', 'value' => 2.0, 'note' => 'Fenbendazole 2 g · import:2026-3#2', 'logged_at' => '2026-03-02 08:15' ) === $e, json_encode( $e ) );
$e = Import::log_entry( array( 'process' => 'Added Salt', 'amount' => 3, 'date' => '2026-03-02', 'time' => 'noon' ), '2026-3', 1 );
check( '"Added X" is a dose too; an unreadable time is noon', 'dose' === $e['kind'] && 3.0 === $e['value'] && 'Salt 3 · import:2026-3#1' === $e['note'] && '2026-03-02 12:00' === $e['logged_at'] );
$e = Import::log_entry( array( 'process' => 'Dosed Kanamycin', 'amount' => null, 'date' => '2026-03-02' ), '2026-3', 3 );
check( 'an unknown medication keeps its name; no amount → no value', 'Kanamycin · import:2026-3#3' === $e['note'] && null === $e['value'] );
$e = Import::log_entry( array( 'process' => 'Lost Fish', 'amount' => 2, 'date' => '2026-03-03', 'time' => '09:00', 'result' => 'one wrasse' ), '2026-3', 4 );
check( 'anything else is a note with the text, the amount and the result', array( 'kind' => 'note', 'value' => null, 'note' => 'Lost Fish 2 — one wrasse · import:2026-3#4', 'logged_at' => '2026-03-03 09:00' ) === $e );
check( '"Tested Copper" / "Copper test" is a copper reading', 'copper' === Import::log_entry( array( 'process' => 'Tested Copper', 'amount' => 2.4, 'date' => '2026-03-03' ), '2026-3', 5 )['kind'] && 'copper' === Import::log_entry( array( 'process' => 'copper test', 'amount' => '2.4', 'date' => '2026-03-03' ), '2026-3', 5 )['kind'] && 2.4 === Import::log_entry( array( 'process' => 'copper test', 'amount' => '2.4', 'date' => '2026-03-03' ), '2026-3', 5 )['value'] );
$e = Import::log_entry( array( 'process' => 'Notes', 'date' => '2026-03-03', 'result' => str_repeat( 'é long test note ', 200 ) ), '2026-3', 7 );
check( 'a note longer than the Store keeps is cut before its marker: at most 2000 bytes, valid UTF-8, the marker found again (a second run skips it)', strlen( $e['note'] ) <= Import::NOTE_BYTES && 1 === preg_match( '//u', $e['note'] ) && 'import:2026-3#7' === Import::marker_of( $e['note'] ) && false !== strpos( $e['note'], '… · import:2026-3#7' ) );
check( 'cut_bytes: short text untouched, never half a character', 'abc' === Import::cut_bytes( 'abc', 10 ) && 'éé…' === Import::cut_bytes( 'éééé', 7 ) && 'aa…' === Import::cut_bytes( 'aaééé', 6 ) );
check( 'no date → null',null === Import::log_entry( array( 'process' => 'Dosed Metro', 'amount' => 5, 'date' => null ), '2026-3', 6 ) && null === Import::log_entry( array( 'process' => 'Dosed Metro', 'date' => 'soon' ), '2026-3', 6 ) );
check( 'amount_text / amount_number', '55' === Import::amount_text( 55 ) && '5.125' === Import::amount_text( 5.125 ) && '5.125 G' === Import::amount_text( ' 5.125  G ' ) && '' === Import::amount_text( null ) && 5.125 === Import::amount_number( '5.125 G' ) && 8.0 === Import::amount_number( '8 & 20' ) && 1500.0 === Import::amount_number( '1,500 ml' ) && null === Import::amount_number( 'some' ) && null === Import::amount_number( null ) && 20.0 === Import::amount_number( 20 ) );
check( 'within_days: today, 45 days back, not 46; junk never', Import::within_days( '2026-10-17', $today, 45 ) && Import::within_days( '2026-09-02', $today, 45 ) && ! Import::within_days( '2026-09-01', $today, 45 ) && Import::within_days( '2026-10-20', $today, 45 ) && ! Import::within_days( '', $today, 45 ) && ! Import::within_days( '2026-10-17', 'x', 45 ) );
check( 'site_to_utc (Chicago, DST and not)', '2026-07-02 17:30:00' === Import::site_to_utc( '2026-07-02 12:30' ) && '2026-01-14 15:00:00' === Import::site_to_utc( '2026-01-14 09:00' ) );
check( 'usable_rates / rate_key', 'test supplier co|testland' === Import::rate_key( ' Test Supplier Co ', 'TESTLAND' ) && array( 'supplier' => 'S', 'origin' => '', 'per_box' => 5.0, 'reox' => 0.0 ) === Import::usable_rates( array( array( 'supplier' => 'S', 'origin' => null, 'per_box' => 5, 'reox' => null ) ) )[0] && null === Import::usable_rates( array( array( 'supplier' => 'S', 'per_box' => 'x' ) ) )[0] );

echo "12. The admin panel's pure helpers\n";
$o = Admin_Import::sanitize_options( array( 'stays_days' => '30', 'stays_building_id' => (string) $spa6, 'dose_logs' => '1' ), $spa );
check( 'options from the form', array( 'stays_days' => 30, 'stays_building_id' => 6, 'dose_logs' => true ) === $o );
$o = Admin_Import::sanitize_options( array( 'stays_days' => '-4', 'stays_building_id' => '99' ), $spa );
check( 'clamped and defaulted: negative days → 0, an unknown building → the 205-gallon one, no checkbox → off; 9999 days → 365', array( 'stays_days' => 0, 'stays_building_id' => 5, 'dose_logs' => false ) === $o && 365 === Admin_Import::sanitize_options( array( 'stays_days' => '9999' ), $spa )['stays_days'] && 45 === Admin_Import::sanitize_options( array( 'stays_days' => 'x' ), $spa )['stays_days'] );
check( 'default_building: the 205-gallon system, else the most tanks, else null', 5 === Admin_Import::default_building( $spa ) && 5 === Admin_Import::default_building( $unknown ) && null === Admin_Import::default_building( array() ) && 6 === Admin_Import::default_building( array( $spa[1] ) ) );
$lines = Admin_Import::summary_lines( $r );
check( 'the result in plain lines', 5 === count( $lines ) && 'Species: 5 added, 0 updated, 0 skipped.' === $lines[0] && 'Orders: 3 created with 7 lines, 0 already on the site (skipped).' === $lines[1] && 'Stays: 2 created from 1 recent orders, 0 lines skipped (already checked in or nothing received).' === $lines[2] && 'Treatment logs: 6 written, 0 skipped (already there, or undated).' === $lines[3] && 'Box rates: 5 rows in Settings now.' === $lines[4], json_encode( $lines ) );
check( 'the .htaccess refuses both Apache generations; the file name is random with the extension', false !== strpos( Admin_Import::htaccess(), 'Require all denied' ) && false !== strpos( Admin_Import::htaccess(), 'Deny from all' ) && 1 === preg_match( '/^workbook-0-[A-Za-z0-9]{16}\.xlsm$/', Admin_Import::file_name( '/tmp', 'FisHotel-2026.xlsm', '.XLSM' ) ) && 20971520 === Admin_Import::MAX_BYTES && array( 'xlsx', 'xlsm' ) === array_keys( Admin_Import::MIMES ) );
check( 'system_label on the panel\'s buildings', '205 gal system' === Layout::system_label( $spa[0]['gallons'] ) );
$xlsx_mime = Admin_Import::MIMES['xlsx'];
$refused   = array(
	'ext'             => false,
	'type'            => false,
	'proper_filename' => false,
);
check( 'check_filetype: an .xlsm that fileinfo calls an .xlsx (what WordPress refuses) gets the .xlsm type back', array( 'ext' => 'xlsm', 'type' => Admin_Import::MIMES['xlsm'], 'proper_filename' => false ) === Admin_Import::check_filetype( $refused, 'FisHotel-2026.XLSM', $xlsx_mime ) );
check( 'check_filetype: a renamed PHP or text file stays refused; an accepted file is left alone', $refused === Admin_Import::check_filetype( $refused, 'FisHotel-2026.xlsm', 'text/x-php' ) && $refused === Admin_Import::check_filetype( $refused, 'notes.txt', $xlsx_mime ) && $refused === Admin_Import::check_filetype( $refused, 'x.xlsm', false ) && array( 'ext' => 'xlsx', 'type' => $xlsx_mime, 'proper_filename' => false ) === Admin_Import::check_filetype( array( 'ext' => 'xlsx', 'type' => $xlsx_mime, 'proper_filename' => false ), 'a.xlsx', $xlsx_mime ) );
check( 'workbook_year: the year in the client\'s name, else the site\'s — never the stored random name or the user id in it', 2025 === Admin_Import::workbook_year( 'FisHotel-2025 (1).xlsm', $today ) && 2026 === Admin_Import::workbook_year( 'fishotel.xlsm', $today ) && 2026 === Admin_Import::workbook_year( 'fishotel.xlsm', '' ) );

fh_finish();
