<?php
/**
 * CSV — the cell guard: a leading = + - @ tab or CR gets a single quote
 * so a species name can never run as a spreadsheet formula; the row
 * layout; write_csv(); the Board / Stays filters and totals; the event
 * and log wording; the release timestamp.
 *
 * Run from the plugin root:  php includes/sections/clean-room/tests/csv-test.php
 */

require_once __DIR__ . '/wp-stubs.php';

use FisHotel\Misc\Sections\Clean_Room\Admin;

echo "1. csv_cell()\n";
check( '=SUM(A1) is prefixed', "'=SUM(A1)" === Admin::csv_cell( '=SUM(A1)' ) );
check( '+1 is prefixed', "'+1" === Admin::csv_cell( '+1' ) );
check( '-1 is prefixed', "'-1" === Admin::csv_cell( '-1' ) );
check( '@cmd is prefixed', "'@cmd" === Admin::csv_cell( '@cmd' ) );
check( 'leading tab is prefixed', "'\t=1" === Admin::csv_cell( "\t=1" ) );
check( 'leading CR is prefixed', "'\r=1" === Admin::csv_cell( "\r=1" ) );
check( 'plain text untouched', 'Pajama Cardinalfish' === Admin::csv_cell( 'Pajama Cardinalfish' ) );
check( 'a = later in the string is fine', 'a=b' === Admin::csv_cell( 'a=b' ) );
check( 'empty stays empty', '' === Admin::csv_cell( '' ) && '' === Admin::csv_cell( null ) );
check( 'numbers become text', '5' === Admin::csv_cell( 5 ) && '0' === Admin::csv_cell( 0 ) );
check( 'a negative number is a formula to a spreadsheet, so it is prefixed too', "'-5" === Admin::csv_cell( -5 ) );
check( 'arrays are JSON-encoded then guarded', '{"a":1}' === Admin::csv_cell( array( 'a' => 1 ) ) );
check( 'a leading space is not prefixed', ' =1' === Admin::csv_cell( ' =1' ) );

echo "2. csv_row()\n";
$stay = array(
	'id'                  => 7,
	'species'             => '=HYPERLINK("http://x","click")',
	'product_id'          => 12,
	'qty_arrived'         => 5,
	'qty_live'            => 3,
	'checkin_date'        => '2026-04-01',
	'day'                 => 14,
	'status'              => 'cleared',
	'state_label'         => 'cleared',
	'cleared_date'        => '2026-04-14',
	'sale_ready_date'     => '2026-04-28',
	'closed_date'         => null,
	'tank_number'         => 121,
	'area'                => 'clean',
	'source'              => 'voice',
	'supplier'            => '-Golden Ina',
	'order_ref'           => '@PO-7',
	'landed_cost'         => 12.5,
	'notes'               => '',
);
$row = Admin::csv_row( $stay );
check( 'nineteen columns, matching the header', 19 === count( $row ) && 19 === count( Admin::csv_header() ) );
check( 'species, supplier and order ref are guarded', "'=HYPERLINK(\"http://x\",\"click\")" === $row[1] && "'-Golden Ina" === $row[15] && "'@PO-7" === $row[16] );
check( 'numbers and dates pass through as text', array( '7', '12', '5', '3', '2026-04-01', '14' ) === array( $row[0], $row[2], $row[3], $row[4], $row[5], $row[6] ) );
check( 'state, cleared, sale-ready, closed', array( 'cleared', '2026-04-14', '2026-04-28', '' ) === array( $row[7], $row[8], $row[9], $row[10] ) );
check( 'tank and its building (the hundreds)', '121' === $row[11] && '100' === $row[12] && 'clean' === $row[13] );
check( 'landed cost with two decimals', '12.50' === $row[17] );
$row = Admin::csv_row( array( 'species' => 'x' ) );
check( 'missing keys → empty cells and zero counts', '' === $row[0] && '0' === $row[3] && '' === $row[6] && '' === $row[11] && '' === $row[12] && '' === $row[17] );

echo "3. write_csv()\n";
$out = fopen( 'php://memory', 'w+' );
$n   = Admin::write_csv( $out, array( $stay, array( 'id' => 8, 'species' => 'Yellow Tang', 'qty_arrived' => 2, 'qty_live' => 2 ) ) );
rewind( $out );
$lines = array_values( array_filter( explode( "\n", stream_get_contents( $out ) ), 'strlen' ) );
fclose( $out );
check( 'two rows written, header first', 2 === $n && 3 === count( $lines ) && 0 === strpos( $lines[0], 'id,species,product_id,qty_arrived,qty_live,checkin_date,day,state,cleared_date,sale_ready_date,closed_date,tank,building,area,source,supplier,order_ref,landed_cost,notes' ) );
check( 'the guarded species is quoted in the file', false !== strpos( $lines[1], "\"'=HYPERLINK" ) );
$out = fopen( 'php://memory', 'w+' );
check( 'an empty list writes just the header', 0 === Admin::write_csv( $out, array() ) );
fclose( $out );

echo "4. Filters and totals\n";
$filters = Admin::filters_from_request( array( 'area' => 'SPA', 's' => ' <b>cardinal</b> ' ) );
check( 'area is validated (lower-cased), species sanitised', 'spa' === $filters['area'] && 'cardinal' === $filters['species'] );
check( 'unknown area → both', '' === Admin::filters_from_request( array( 'area' => 'lab' ) )['area'] );
check( 'nothing → empty filters', array( 'area' => '', 'species' => '', 'ready' => false ) === Admin::filters_from_request( null ) );
check( 'ready=1 → only the groups for sale today', true === Admin::filters_from_request( array( 'ready' => '1' ) )['ready'] && false === Admin::filters_from_request( array( 'ready' => 'yes' ) )['ready'] );
$tanks = array(
	array( 'status' => 'active', 'occupants' => array( array( 'qty_live' => 4, 'status' => 'in_qt', 'sale_ready' => false, 'sale_ready_date' => null ), array( 'qty_live' => 2, 'status' => 'cleared', 'sale_ready' => false, 'sale_ready_date' => '2026-05-01' ) ) ),
	array( 'status' => 'active', 'occupants' => array( array( 'qty_live' => 3, 'status' => 'cleared', 'sale_ready' => true, 'sale_ready_date' => '2026-04-20' ) ) ),
	array( 'status' => 'active', 'occupants' => array( array( 'qty_live' => 1, 'status' => 'in_qt', 'sale_ready' => true, 'ready_in_spa' => true, 'sale_ready_date' => '2026-04-20' ) ) ),
	array( 'status' => 'active', 'occupants' => array() ),
	array( 'status' => 'offline', 'occupants' => array() ),
);
$totals = Admin::totals( $tanks );
check( 'totals: 1 in QT, 1 cleared, 2 for sale (one still in the Spa), 10 alive, 1 of 4 tanks empty (offline skipped), 1 group without a sale date', array( 'in_qt' => 1, 'cleared' => 1, 'ready' => 2, 'live' => 10, 'tanks' => 4, 'empty' => 1, 'ready_in_spa' => 1, 'no_sale_date' => 1 ) === $totals, json_encode( $totals ) );
check( 'matches_species is case-insensitive and empty matches all', Admin::matches_species( array( 'species' => 'Pajama Cardinalfish' ), 'CARDINAL' ) && Admin::matches_species( array( 'species' => 'x' ), '' ) && ! Admin::matches_species( array( 'species' => 'Yellow Tang' ), 'cardinal' ) );

echo "5. Plain words for the Events tab\n";
check( 'arrival', '5 arrived in Tank 521' === Admin::event_text( array( 'type' => 'arrival', 'qty' => 5, 'to_tank_number' => 521 ) ) );
check( 'loss with a reason', '2 lost (doa)' === Admin::event_text( array( 'type' => 'loss', 'qty' => 2, 'note' => 'doa' ) ) );
check( 'an undone loss', '2 lost (undo)' === Admin::event_text( array( 'type' => 'loss', 'qty' => -2, 'note' => 'undo of #4' ) ) );
check( 'shipped', '3 shipped' === Admin::event_text( array( 'type' => 'sold', 'qty' => 3 ) ) );
check( 'a whole move', 'moved Tank 521 → Tank 121' === Admin::event_text( array( 'type' => 'move', 'qty' => null, 'from_tank_number' => 521, 'to_tank_number' => 121 ) ) );
check( 'a move with a reason', 'moved Tank 521 → Tank 121 (clear building)' === Admin::event_text( array( 'type' => 'move', 'qty' => null, 'from_tank_number' => 521, 'to_tank_number' => 121, 'note' => 'clear_building' ) ) );
check( 'a split', '2 split off Tank 521 → Tank 121' === Admin::event_text( array( 'type' => 'move', 'qty' => 2, 'from_tank_number' => 521, 'to_tank_number' => 121, 'note' => 'split to #5' ) ) );
check( 'a split with a reason', '2 split off Tank 521 → Tank 121 (bullying)' === Admin::event_text( array( 'type' => 'move', 'qty' => 2, 'from_tank_number' => 521, 'to_tank_number' => 121, 'note' => 'split to #5 · bullying' ) ) );
check( 'an undone move shows no reason', 'moved Tank 121 → Tank 521 (undo)' === Admin::event_text( array( 'type' => 'move', 'qty' => null, 'from_tank_number' => 121, 'to_tank_number' => 521, 'note' => 'undo of #7' ) ) );
check( 'move_reason', 'pairing' === Admin::move_reason( 'pairing' ) && 'bullying' === Admin::move_reason( 'split to #5 · bullying' ) && '' === Admin::move_reason( 'split from #5' ) && '' === Admin::move_reason( 'undo of #3' ) && '' === Admin::move_reason( '' ) );
check( 'clear / unclear', 'cleared' === Admin::event_text( array( 'type' => 'clear' ) ) && 'cleared date removed (undo)' === Admin::event_text( array( 'type' => 'clear', 'qty' => -1, 'note' => 'undo of #9' ) ) );
check( 'a clear with the date in its note', 'cleared date set to 2026-04-10' === Admin::event_text( array( 'type' => 'clear', 'note' => 'cleared:2026-04-10' ) ) );
check( 'recount', 'recount: 4' === Admin::event_text( array( 'type' => 'count', 'qty' => 4 ) ) );
check( 'a sale-date note', 'own sale date set to 2026-05-20' === Admin::event_text( array( 'type' => 'note', 'note' => 'sale_ready:2026-05-20' ) ) && 'own sale date removed (the batch date applies)' === Admin::event_text( array( 'type' => 'note', 'note' => 'sale_ready:' ) ) );
check( 'a check-in note', 'check-in date set to 2026-04-01' === Admin::event_text( array( 'type' => 'note', 'note' => 'checkin:2026-04-01' ) ) && 'check-in date back to the arrival' === Admin::event_text( array( 'type' => 'note', 'note' => 'checkin:' ) ) );
check( 'a plain note', 'note: looking thin' === Admin::event_text( array( 'type' => 'note', 'note' => 'looking thin' ) ) );
check( 'tank log: copper, tick, note', 'copper 2.4' === Admin::log_text( array( 'kind' => 'copper', 'value' => 2.4 ) ) && 'water change: copper off #7' === Admin::log_text( array( 'kind' => 'water_change', 'note' => 'copper off #7' ) ) && 'note: skimmer cleaned' === Admin::log_text( array( 'kind' => 'note', 'note' => 'skimmer cleaned' ) ) );

echo "6. The Coming Soon release moment (09:00 site time, America/Chicago)\n";
check( '2026-04-28 09:00 CDT = 14:00 UTC', '2026-04-28 14:00:00' === gmdate( 'Y-m-d H:i:s', Admin::release_timestamp( '2026-04-28' ) ) );
check( 'a January date is CST (15:00 UTC)', '2026-01-10 15:00:00' === gmdate( 'Y-m-d H:i:s', Admin::release_timestamp( '2026-01-10' ) ) );
check( 'not a date → 0', 0 === Admin::release_timestamp( 'soon' ) && 0 === Admin::release_timestamp( '' ) );

fh_finish();
