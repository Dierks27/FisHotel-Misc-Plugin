<?php
/**
 * Synthetic FisHotel-YEAR.xlsm-style workbook for the mapper test.
 *
 * Every name, price and date in here is invented. The layout mirrors the
 * real workbook: header row 4 starting in column B, the row-2 totals, the
 * right-hand label block in W/X with its values in X and AB, a second
 * unused "DOA" block to the right, QT Tanks logs in K:P, the Data sheet
 * with vendors / origins / two box-rate blocks, FishSheetStock and a PW
 * sheet that must never be read.
 *
 * Usage (from a test): require_once __DIR__ . '/fixture-builder.php'; $path = fh_fixture_build( $dir . '/FisHotel-2031-fixture.xlsx' );
 *
 * Not a test: exits 0 when run directly (the CI lint job runs every tests/*.php).
 */

// Helper, not a test: the CI lint job runs every tests/*.php with php, so
// exit cleanly when executed directly instead of as a require.
if ( ! empty( $_SERVER['SCRIPT_FILENAME'] ) && realpath( $_SERVER['SCRIPT_FILENAME'] ) === __FILE__ ) {
	exit( 0 );
}

require_once __DIR__ . '/xlsx-builder.php';

/**
 * A formula cell with a cached number.
 *
 * @param int|float $value
 * @return array
 */
function fh_fx_num( $value ) {
	return array( 'f' => 'SUM(1)', 'v' => $value );
}

/**
 * A formula cell with a cached string.
 *
 * @param string $value
 * @return array
 */
function fh_fx_str( $value ) {
	return array( 'f' => 'IF(1,"x","")', 'str' => $value );
}

/**
 * The shared headers of a batch sheet.
 *
 * @param array $columns key => column letter for name, sci, order, mode, rcvd, price, total, worth, markup, profit, margin, worthq, srvd, doa, block (first column of the unused DOA block).
 * @param string $mode_header 'Per Box' or 'Bags'.
 * @return array cells
 */
function fh_fixture_batch_headers( $columns, $mode_header ) {
	$cells = array(
		$columns['name'] . '4'   => 'Name',
		$columns['sci'] . '4'    => 'Sci Name',
		$columns['order'] . '4'  => 'Order',
		$columns['mode'] . '4'   => $mode_header,
		$columns['rcvd'] . '4'   => "Rcv'd",
		$columns['price'] . '4'  => 'Price',
		$columns['total'] . '4'  => 'Total',
		$columns['worth'] . '4'  => 'Worth $',
		$columns['markup'] . '4' => 4,
		$columns['profit'] . '4' => 'Profit',
		$columns['margin'] . '4' => 'Margin',
		$columns['worthq'] . '4' => 'Worth?',
		$columns['srvd'] . '4'   => "Srv'd",
		$columns['doa'] . '4'    => 'DOA',
	);
	$block = fh_test_col_index( $columns['block'] );
	$texts = array( 'DOA', 'Sci Name', 'Common Name', "Ord'd", 'Received', 'Diff', '101', '102', '103', '201', '202', '203', '204' );
	foreach ( $texts as $i => $text ) {
		$cells[ fh_test_col_letter( $block + $i ) . '4' ] = $text;
	}
	for ( $i = 6; $i < 13; $i++ ) {
		$cells[ fh_test_col_letter( $block + $i ) . '2' ] = fh_fx_num( 0 );
	}
	return $cells;
}

/**
 * Write the fixture workbook.
 *
 * @param string $path Output path; a 4-digit year in the file name is the fallback ref year.
 * @return string
 */
function fh_fixture_build( $path ) {
	$sheets = array();

	// Something the mapper must not open.
	$sheets['Controller & Stats'] = array(
		'A1' => 'Fill Rate',
		'B1' => fh_fx_num( 0.8 ),
	);

	// Species master.
	$sheets['Fishlist'] = array(
		'A1' => 'Fish',
		'B1' => 'Scientific Name',
		'C1' => 'Sm',
		'D1' => 'Med',
		'E1' => 'Lrg',
		'F1' => 'Male',
		'G1' => 'Mine',
		'H1' => 'Online',
		'I1' => 'Site',
		'J1' => 'Size',
		'K1' => 'Date',
		'L1' => 'Markup',
		'M1' => 1.5,
		'N1' => 'Per Box',

		'A2' => 'Blue Test Wrasse',
		'B2' => 'Testus caeruleus',
		'C2' => 20,
		'D2' => 30,
		'E2' => 40,
		'G2' => fh_fx_num( 30 ),
		'H2' => 25,
		'I2' => 'Dr Fake',
		'J2' => 'Med',
		'K2' => '7/2/2026',
		'L2' => fh_fx_num( 1.2 ),
		'M2' => fh_fx_num( 37.5 ),
		'N2' => 24,

		'A3' => 'Green Test Tang',
		'B3' => 'Testus viridis',
		'D3' => 45,
		'G3' => fh_fx_num( 45 ),
		'H3' => 40,
		'I3' => 'Dr Fake',
		'J3' => 'Med',
		'K3' => array( 'date' => 46205 ),
		'L3' => fh_fx_num( 1.125 ),
		'M3' => fh_fx_num( 60 ),
		'N3' => 12,

		'A4' => 'Yellow Test Goby',
		'B4' => 'Testus flavus',
		'G4' => array( 'f' => 'ROUNDUP(AVERAGE(C4:F4),0)', 'e' => '#DIV/0!' ),
		'L4' => array( 'f' => 'G4/H4', 'e' => '#DIV/0!' ),
		'M4' => fh_fx_num( 0 ),
		'N4' => 50,

		'A5' => 'Red Test Blenny',
		'B5' => 'Testus ruber',
		'C5' => 15,
		'G5' => 15,
		'H5' => 10,
		'I5' => 'Dr Fake',
		'J5' => 'Sm',
		'K5' => '13/45/2026',
		'N5' => 75,

		'A6' => 'Orange Test Clown',
		'B6' => 'Testus aurantius',
		'G6' => fh_fx_num( 25 ),
		'N6' => 24,

		// Row 7 has no name: the species list stops here.
		'G7' => fh_fx_num( 0 ),
		'A8' => 'Stale Test Fish',
		'B8' => 'Testus obsoletus',
		'N8' => 6,
	);

	// Batch 1: box mode, template column layout (header row 4 from column B).
	$box_columns = array(
		'name'   => 'B',
		'sci'    => 'C',
		'order'  => 'D',
		'mode'   => 'E',
		'rcvd'   => 'F',
		'price'  => 'G',
		'total'  => 'I',
		'worth'  => 'K',
		'markup' => 'M',
		'profit' => 'N',
		'margin' => 'O',
		'worthq' => 'Q',
		'srvd'   => 'S',
		'doa'    => 'U',
		'block'  => 'AF',
	);
	$batch1 = fh_fixture_batch_headers( $box_columns, 'Per Box' );
	$batch1 += array(
		'B2'   => 'Total:',
		'C2'   => fh_fx_num( 1234.5 ),
		'D2'   => 'Tariffs:',
		'I2'   => 'Domestic Flight:',
		'K2'   => array( 'currency' => 150 ),
		'M2'   => 'Import & ReOx Fees:',
		'O2'   => array( 'currency' => 200.25 ),
		'Q2'   => 'Total Critters',
		'U2'   => fh_fx_num( 17 ),
		'V2'   => fh_fx_num( 20.6 ),
		'X2'   => 'Fees Per Full Box',
		'Q3'   => 2.75,
		'W3'   => 'Grand Total:',
		'X3'   => fh_fx_num( 1234.5 ),
		'AB3'  => fh_fx_num( 1.1 ),
		'W8'   => 'Up or Down:',
		'X8'   => fh_fx_num( -100 ),
		'AB8'  => 'Revenue:',
		'AB10' => fh_fx_num( 1134.5 ),
		'W14'  => 'From:',
		'X14'  => 'TESTLAND',
		'AB14' => 'Test Supplier Co',
		'W17'  => 'Fill Rate:',
		'X17'  => fh_fx_num( 0.85 ),
		'AC17' => fh_fx_str( '/' ),
		'W22'  => 'Survival Rate:',
		'X22'  => fh_fx_num( 0.76 ),
		'W27'  => 'DOA:',
		'X27'  => fh_fx_num( 0.06 ),
		'W32'  => 'DOA Cost:',
		'X32'  => fh_fx_num( 2.5 ),
		'AB32' => 'Credit',
		'W100' => array( 'f' => '#REF!*#REF!', 'e' => '#REF!' ),

		'B5'   => 'Blue Test Wrasse',
		'C5'   => fh_fx_str( 'Testus caeruleus' ),
		'D5'   => 10,
		'E5'   => 24,
		'F5'   => 10,
		'G5'   => 2.5,
		'H5'   => fh_fx_num( 0.4167 ),
		'I5'   => fh_fx_num( 30.75 ),
		'K5'   => fh_fx_num( 30 ),
		'M5'   => fh_fx_num( 15 ),
		'N5'   => fh_fx_num( 269.25 ),
		'O5'   => fh_fx_num( 8.76 ),
		'Q5'   => fh_fx_str( 'Yes' ),
		'S5'   => 9,
		'U5'   => 1,

		'B6'   => 'Green Test Tang',
		'C6'   => fh_fx_str( 'Testus viridis' ),
		'D6'   => 5,
		'F6'   => 4,
		'G6'   => 8,
		'I6'   => fh_fx_num( 40.5 ),
		'K6'   => fh_fx_num( 45 ),
		'Q6'   => fh_fx_str( 'Yes' ),
		'S6'   => 4,
		'U6'   => 0,

		'B7'   => 'Unknown Test Fish',
		'C7'   => fh_fx_str( 'N/A' ),
		'D7'   => 3,
		'F7'   => 3,
		'I7'   => array( 'f' => 'F7*G7', 'e' => '#REF!' ),
		'K7'   => fh_fx_str( '' ),

		'C8'   => fh_fx_str( '' ),
		'I8'   => fh_fx_str( '' ),

		'B9'   => 'Red Test Blenny',
		'C9'   => fh_fx_str( 'Testus ruber' ),
		'D9'   => 2,
		'F9'   => 0,
		'G9'   => 1.25,
		'I9'   => fh_fx_num( 0 ),
		'K9'   => fh_fx_num( 15 ),
	);
	$sheets['Recieved (1)'] = $batch1;

	// Batch 2: bag mode, one extra unlabeled column (R) before Srv'd, and the
	// right-hand block one row lower and one column further right.
	$bag_columns = array(
		'name'   => 'B',
		'sci'    => 'C',
		'order'  => 'D',
		'mode'   => 'E',
		'rcvd'   => 'F',
		'price'  => 'G',
		'total'  => 'I',
		'worth'  => 'K',
		'markup' => 'M',
		'profit' => 'N',
		'margin' => 'O',
		'worthq' => 'Q',
		'srvd'   => 'T',
		'doa'    => 'V',
		'block'  => 'AG',
	);
	$batch2 = fh_fixture_batch_headers( $bag_columns, 'Bags' );
	$batch2 += array(
		'B2'   => 'Total:',
		'C2'   => fh_fx_num( 500 ),
		'D2'   => 'Tariffs:',
		'F2'   => array( 'currency' => 12.75 ),
		'I2'   => 'Domestic Flight:',
		'M2'   => 'Import & ReOx Fees:',
		'O2'   => 300,
		'Q2'   => 'Total Critters',
		'V2'   => fh_fx_num( 9 ),
		'W2'   => fh_fx_num( 34.75 ),
		'Y2'   => 'Shipping Per Bag',
		'X4'   => 'Grand Total:',
		'Y4'   => fh_fx_num( 500 ),
		'X9'   => 'Up or Down:',
		'AC9'  => 'Revenue:',
		'X15'  => 'From:',
		'AC15' => 'Test Supplier Two',
		'X18'  => 'Fill Rate:',
		'X23'  => 'Survival Rate:',
		'X28'  => 'DOA:',
		'X33'  => 'DOA Cost:',
		'AC33' => 'Credit',
		'AC35' => array( 'currency' => 12.5 ),

		'B5'   => 'Orange Test Clown',
		'C5'   => fh_fx_str( 'Testus aurantius' ),
		'D5'   => 6,
		'E5'   => 2,
		'F5'   => 6,
		'G5'   => 3,
		'H5'   => fh_fx_num( 2 ),
		'I5'   => fh_fx_num( 24 ),
		'K5'   => fh_fx_num( 25 ),
		'R5'   => fh_fx_num( 3 ),
		'T5'   => 5,
		'V5'   => 1,

		'B6'   => 'Blue Test Wrasse',
		'C6'   => fh_fx_str( 'Testus caeruleus' ),
		'D6'   => 4,
		'F6'   => 3,
		'G6'   => 2,
		'H6'   => fh_fx_num( 3 ),
		'I6'   => fh_fx_num( 9.5 ),
		'K6'   => fh_fx_num( 30 ),
		'R6'   => fh_fx_num( 0 ),
		'T6'   => 3,
		'V6'   => 0,
	);
	$sheets['Recieved (2)'] = $batch2;

	// Batch 3: the layout but no line rows (like an unused batch).
	$batch3 = fh_fixture_batch_headers( $box_columns, 'Per Box' );
	$batch3 += array(
		'B2'  => 'Total:',
		'C2'  => fh_fx_str( '' ),
		'Q2'  => 'Total Critters',
		'U2'  => fh_fx_num( 0 ),
		'W14' => 'From:',
		'C5'  => fh_fx_str( '' ),
	);
	$sheets['Recieved (3)'] = $batch3;

	// Batch 4: bag mode with missing headers, no label block and no QT sheet.
	$sheets['Recieved (4)'] = array(
		'B2' => 'Total:',
		'C2' => fh_fx_num( 1 ),
		'B4' => 'Name',
		'C4' => 'Sci Name',
		'D4' => 'Order',
		'E4' => 'Bags',
		'F4' => "Rcv'd",
		'G4' => 'Price',
		'I4' => 'Total',
		'U4' => 'DOA',
		'B5' => 'Green Test Tang',
		'C5' => fh_fx_str( 'Testus viridis' ),
		'D5' => 1,
		'F5' => 1,
		'G5' => 1,
		'I5' => fh_fx_num( 1 ),
	);

	// The empty batch template.
	$template = fh_fixture_batch_headers( $box_columns, 'Per Box' );
	$template += array(
		'B2'  => 'Total:',
		'Q2'  => 'Total Critters',
		'W14' => 'From:',
	);
	$sheets['Recieved'] = $template;

	// QT Tanks sheets.
	$qt_headers = array(
		'B2' => '20 Gallon (201)',
		'H2' => 'Start Date:',
		'H3' => 'On Day:',
		'H4' => 'Schedule:',
		'K2' => 'Todays Stuff:',
		'K4' => 'Process',
		'L4' => '@',
		'M4' => 'Tanks',
		'N4' => 'Date',
		'O4' => 'Time',
		'P4' => 'Results',
		'H5' => 'Keep Tanks 77-79 Degrees',
	);
	$sheets['QT Tanks (1)'] = $qt_headers + array(
		'I2'  => array( 'date' => 46036 ),
		'I3'  => fh_fx_num( 50 ),
		'I4'  => '14 Day CM',
		'K3'  => fh_fx_str( 'Metro' ),
		'K5'  => 'Dosed Copper',
		'L5'  => 55,
		'M5'  => 205,
		'N5'  => array( 'date' => 46036 ),
		'O5'  => array( 'time' => 0.375 ),
		'K6'  => 'Dosed Metro',
		'L6'  => '5.125 G',
		'M6'  => 100,
		'N6'  => array( 'date' => 46037 ),
		'O6'  => array( 'time' => 0.5 ),
		'P6'  => 'ok',
		'K7'  => 'Tested Copper',
		'L7'  => 2.01,
		'M7'  => 'Both',
		'N7'  => array( 'date' => 46038 ),
		'O7'  => array( 'custom_time' => 0.4166666667 ),
		'P7'  => 'fine',
		'K8'  => '20 Gallon (201-204)',
		'K9'  => 'Dosed Praziquantel',
		'L9'  => '8 & 20',
		'M9'  => 205,
		'N9'  => '1/18/2026',
		'O9'  => '6:45 PM',
		'K11' => 'Dosed Formalin',
		'L11' => 20,
		'K46' => '40 Gallon (202)',
	);
	$sheets['QT Tanks (2)'] = $qt_headers + array(
		'I2' => array( 'date' => 46300 ),
		'I3' => fh_fx_num( 12 ),
		'I4' => '14 Day CF',
		'K5' => 'Dosed Copper',
		'L5' => 20,
		'M5' => 205,
		'N5' => array( 'date' => 46300 ),
		'O5' => array( 'time' => 0.4375 ),
		'P5' => 'ok',
	);
	$sheets['QT Tanks (3)'] = $qt_headers + array(
		'I4' => '14 Day CF',
	);
	$sheets['QT Tanks']     = $qt_headers + array(
		'I4' => '14 Day CF',
	);

	// Data: vendors, origins, two box-rate blocks.
	$sheets['Data'] = array(
		'B1'  => 'Enter Website Below to add to list',
		'D1'  => 'Vendor',
		'F1'  => 'Test Supplier Co',
		'B2'  => 'FakeReef',
		'D2'  => 'Test Supplier Co',
		'F2'  => 'Location',
		'G2'  => 'Per Box',
		'H2'  => 'Re-Ox',
		'J2'  => 'Location',
		'N2'  => 'Volume (or Liquid Measure)',
		'B3'  => 'FakeZoo',
		'D3'  => 'Test Supplier Two',
		'J3'  => 'TESTLAND',
		'N3'  => 'Code',
		'O3'  => 'Description',
		'D4'  => 'Other',
		'F4'  => 'TESTLAND',
		'G4'  => 100,
		'H4'  => 10,
		'J4'  => 'FAKEISLAND',
		'N4'  => 'cup',
		'O4'  => 'Cup',
		'F5'  => 'FAKEISLAND',
		'G5'  => 120.5,
		'H5'  => 12,
		'J5'  => 'NOWHERE',
		'F6'  => 'NOWHERE',
		'G6'  => 90,
		'F8'  => 'Test Supplier Two',
		'F9'  => 'Location',
		'G9'  => 'Per Box',
		'H9'  => 'Re-Ox',
		'F10' => 'TESTLAND',
		'G10' => 95,
		'H10' => 8,
		'F11' => 'FAKEISLAND',
		'G11' => 110,
		'H11' => 0.15,
	);

	// Stock reference list.
	$sheets['FishSheetStock'] = array(
		'B1' => 'Group',
		'C1' => 'Common Name',
		'D1' => 'Taxonomy',
		'E1' => 'Reef Safe?',
		'F1' => 'Description',
		'G1' => 'Max Size',
		'B2' => 'Wrasse Test',
		'C2' => 'Blue Test Wrasse',
		'D2' => 'Testus caeruleus',
		'E2' => 'Yes',
		'F2' => 'An invented wrasse.',
		'G2' => '4"',
		'B3' => 'Tang Test',
		'C3' => 'Green Test Tang',
		'D3' => 'Testus viridis',
		'E3' => 'With Caution',
		'G3' => 8,
		'B4' => 'Goby Test',
		'C4' => 'Yellow Test Goby',
		'D4' => 'Testus flavus',
		'E4' => 'No',
		'G4' => '3 in',
		'B6' => 'Blenny Test',
		'D6' => 'Testus ruber',
		'E6' => 'Yes',
	);

	// Never read.
	$sheets['PW'] = array(
		'A1' => 'dummy secret text',
		'B2' => 'never read',
	);

	return fh_test_write_xlsx( $path, $sheets );
}
