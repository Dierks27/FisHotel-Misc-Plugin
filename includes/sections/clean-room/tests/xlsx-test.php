<?php
/**
 * Xlsx — the dependency-free workbook writer: build → a real zip →
 * ZipArchive opens it → the sheet XML parses → the cells are what went
 * in (numbers as numbers, text as inline strings, escaped, control
 * characters stripped, a string starting with "=" still a string, null an
 * empty cell), the sheet names cut to 31 characters and made legal, the
 * column letters.
 *
 * Run from the plugin root:  php includes/sections/clean-room/tests/xlsx-test.php
 */

require_once __DIR__ . '/wp-stubs.php';

use FisHotel\Misc\Sections\Clean_Room\Xlsx;

echo "1. Column letters and sheet names\n";
check( 'col_ref: 1 → A, 26 → Z, 27 → AA, 52 → AZ, 53 → BA, 703 → AAA, 0 → A', 'A' === Xlsx::col_ref( 1 ) && 'Z' === Xlsx::col_ref( 26 ) && 'AA' === Xlsx::col_ref( 27 ) && 'AZ' === Xlsx::col_ref( 52 ) && 'BA' === Xlsx::col_ref( 53 ) && 'AAA' === Xlsx::col_ref( 703 ) && 'A' === Xlsx::col_ref( 0 ) );
$long = 'A very long sheet name that exceeds thirty-one characters [x]/y';
$names = Xlsx::sheet_names( array( 'Orders', $long, 'a/b:c?d*e', '', 'Data', 'data', "'quoted'" ) );
check( 'a long name is cut to 31 characters', 'A very long sheet name that exc' === $names[1] && 31 === strlen( $names[1] ) );
check( 'forbidden characters become dashes, an empty name is numbered, quotes trimmed', 'Orders' === $names[0] && 'a-b-c-d-e' === $names[2] && 'Sheet 4' === $names[3] && 'quoted' === $names[6] );
check( 'a duplicate (case-insensitive) gets a suffix', 'Data' === $names[4] && 'data (2)' === $names[5] );
$dup = Xlsx::sheet_names( array( str_repeat( 'x', 40 ), str_repeat( 'x', 40 ) ) );
check( 'a suffixed duplicate still fits in 31', 31 === strlen( $dup[1] ) && ' (2)' === substr( $dup[1], -4 ) );

echo "2. Cells\n";
check( 'null → no cell', '' === Xlsx::cell_xml( 'A1', null ) );
check( 'an int and a float are numbers', '<c r="B2"><v>3</v></c>' === Xlsx::cell_xml( 'B2', 3 ) && '<c r="C2"><v>12.5</v></c>' === Xlsx::cell_xml( 'C2', 12.5 ) );
check( 'a bool is a boolean cell', '<c r="E2" t="b"><v>1</v></c>' === Xlsx::cell_xml( 'E2', true ) && '<c r="E3" t="b"><v>0</v></c>' === Xlsx::cell_xml( 'E3', false ) );
check( 'text is an inline string, never a formula', '<c r="A3" t="inlineStr"><is><t>=SUM(A1)</t></is></c>' === Xlsx::cell_xml( 'A3', '=SUM(A1)' ) && false === strpos( Xlsx::cell_xml( 'A3', '=SUM(A1)' ), '<f>' ) );
check( 'a numeric string stays text ("2026-1" must not become a date or a number)', '<c r="A4" t="inlineStr"><is><t>2026-1</t></is></c>' === Xlsx::cell_xml( 'A4', '2026-1' ) && false !== strpos( Xlsx::cell_xml( 'A5', '007' ), 'inlineStr' ) );
check( 'XML characters are escaped', '<c r="A6" t="inlineStr"><is><t>a&lt;b&gt;&amp;&quot;c&apos;</t></is></c>' === Xlsx::cell_xml( 'A6', 'a<b>&"c\'' ) );
check( 'control characters are stripped, tab / newline kept', '<c r="A7" t="inlineStr"><is><t>badtext	ok</t></is></c>' === Xlsx::cell_xml( 'A7', "bad\x01\x1Ftext\tok" ) );
check( 'leading / trailing spaces are preserved', '<c r="A8" t="inlineStr"><is><t xml:space="preserve"> padded </t></is></c>' === Xlsx::cell_xml( 'A8', ' padded ' ) );
check( 'a DateTime becomes its Y-m-d as text', '<c r="D2" t="inlineStr"><is><t>2026-04-05</t></is></c>' === Xlsx::cell_xml( 'D2', new DateTime( '2026-04-05 13:00:00' ) ) );
check( 'NaN / INF → no cell; an array → JSON text', '' === Xlsx::cell_xml( 'A9', NAN ) && '' === Xlsx::cell_xml( 'A9', INF ) && false !== strpos( Xlsx::cell_xml( 'A9', array( 'a' => 1 ) ), '{&quot;a&quot;:1}' ) );
check( 'number(): locale-free decimals, 15 significant digits, no trailing zeros', '0.1' === Xlsx::number( 0.1 ) && '2' === Xlsx::number( 2.0 ) && '-3.25' === Xlsx::number( -3.25 ) && '1.0E+20' === Xlsx::number( 1e20 ) && '0.3' === Xlsx::number( 0.1 + 0.2 ) && '257.142857142857' === Xlsx::number( 40 * 10 / 7 + 200 ) );

echo "3. A workbook on disk\n";
check( 'the zip extension is here (CI has it; otherwise build() returns \'\')', Xlsx::available() );
$sheets = array(
	'Orders' => array(
		array( 'ref', 'count', 'price', 'when', 'flag' ),
		array( '2026-1', 3, 12.5, '2026-03-01', true ),
		array( '=SUM(A1)', null, 0.1, new DateTime( '2026-04-05' ), false ),
		array( 'a<b>&"c\'', -2, 100.0, " padded ", "bad\x01text" ),
	),
	$long    => array( array( 'x' ) ),
);
$bytes = Xlsx::build( $sheets );
check( 'build() returns bytes starting with the zip signature', '' !== $bytes && 'PK' === substr( $bytes, 0, 2 ) );
$tmp = tempnam( sys_get_temp_dir(), 'fhxlsx' );
file_put_contents( $tmp, $bytes );
$zip = new ZipArchive();
check( 'ZipArchive opens it', true === $zip->open( $tmp ) );
$entries = array();
for ( $i = 0; $i < $zip->numFiles; $i++ ) {
	$entries[] = $zip->getNameIndex( $i );
}
sort( $entries );
check( 'the minimal parts are all there', array( '[Content_Types].xml', '_rels/.rels', 'xl/_rels/workbook.xml.rels', 'xl/styles.xml', 'xl/workbook.xml', 'xl/worksheets/sheet1.xml', 'xl/worksheets/sheet2.xml' ) === $entries, json_encode( $entries ) );
$types = (string) $zip->getFromName( '[Content_Types].xml' );
check( '[Content_Types] names the workbook, the styles and both sheets', false !== strpos( $types, '/xl/workbook.xml' ) && false !== strpos( $types, '/xl/styles.xml' ) && false !== strpos( $types, '/xl/worksheets/sheet2.xml' ) );
$workbook = simplexml_load_string( (string) $zip->getFromName( 'xl/workbook.xml' ) );
$sheet_names = array();
foreach ( $workbook->children( Xlsx::NS_MAIN )->sheets->sheet as $sheet ) {
	$sheet_names[] = (string) $sheet->attributes()->name; // Attributes carry no namespace: attributes(), not ['name'].
}
check( 'the workbook lists the two sheets, the long name cut to 31', array( 'Orders', 'A very long sheet name that exc' ) === $sheet_names, json_encode( $sheet_names ) );
$rels = (string) $zip->getFromName( 'xl/_rels/workbook.xml.rels' );
check( 'the relationships point at both sheets and the styles', false !== strpos( $rels, 'Target="worksheets/sheet1.xml"' ) && false !== strpos( $rels, 'Target="worksheets/sheet2.xml"' ) && false !== strpos( $rels, 'Target="styles.xml"' ) );

$xml = simplexml_load_string( (string) $zip->getFromName( 'xl/worksheets/sheet1.xml' ) );
check( 'sheet1 parses', $xml instanceof SimpleXMLElement );
$cells = array();
foreach ( $xml->children( Xlsx::NS_MAIN )->sheetData->row as $row ) {
	foreach ( $row->children( Xlsx::NS_MAIN )->c as $c ) {
		$ref  = (string) $c->attributes()->r;
		$type = (string) $c->attributes()->t;
		$m    = $c->children( Xlsx::NS_MAIN );
		if ( 'inlineStr' === $type ) {
			$cells[ $ref ] = array( 's', (string) $m->is->t );
		} elseif ( 'b' === $type ) {
			$cells[ $ref ] = array( 'b', (string) $m->v );
		} else {
			$cells[ $ref ] = array( 'n', (string) $m->v );
		}
	}
}
check( 'the header row is text', array( 's', 'ref' ) === $cells['A1'] && array( 's', 'flag' ) === $cells['E1'] );
check( 'row 2: text, int, float, date text, boolean', array( 's', '2026-1' ) === $cells['A2'] && array( 'n', '3' ) === $cells['B2'] && array( 'n', '12.5' ) === $cells['C2'] && array( 's', '2026-03-01' ) === $cells['D2'] && array( 'b', '1' ) === $cells['E2'] );
check( 'row 3: "=SUM(A1)" is still a string, the null left no B3, the DateTime is text', array( 's', '=SUM(A1)' ) === $cells['A3'] && ! isset( $cells['B3'] ) && array( 'n', '0.1' ) === $cells['C3'] && array( 's', '2026-04-05' ) === $cells['D3'] && array( 'b', '0' ) === $cells['E3'] );
check( 'row 4: the escaped text reads back as typed, the negative int, the whole float, the padding, the stripped control char', array( 's', 'a<b>&"c\'' ) === $cells['A4'] && array( 'n', '-2' ) === $cells['B4'] && array( 'n', '100' ) === $cells['C4'] && array( 's', ' padded ' ) === $cells['D4'] && array( 's', 'badtext' ) === $cells['E4'] );
check( 'no formula element anywhere', false === strpos( (string) $zip->getFromName( 'xl/worksheets/sheet1.xml' ), '<f>' ) );
$xml2 = simplexml_load_string( (string) $zip->getFromName( 'xl/worksheets/sheet2.xml' ) );
check( 'sheet2 holds its one cell', 'x' === (string) $xml2->children( Xlsx::NS_MAIN )->sheetData->row[0]->children( Xlsx::NS_MAIN )->c[0]->children( Xlsx::NS_MAIN )->is->t );
$zip->close();
unlink( $tmp );

echo "4. Edge cases\n";
$parts = Xlsx::parts( array() );
check( 'no sheets at all: one empty Sheet1', isset( $parts['xl/worksheets/sheet1.xml'] ) && false !== strpos( $parts['xl/workbook.xml'], 'name="Sheet1"' ) );
$parts = Xlsx::parts( array( 'S' => array( 'not a row', array() ) ) );
check( 'a scalar row is wrapped, an empty row is an empty <row>', false !== strpos( $parts['xl/worksheets/sheet1.xml'], '<row r="1"><c r="A1" t="inlineStr"><is><t>not a row</t></is></c></row><row r="2"></row>' ) );
check( 'escape() substitutes invalid UTF-8 instead of dropping the cell', '' !== Xlsx::escape( "ok \xB1 bad" ) && false !== strpos( Xlsx::escape( "ok \xB1 bad" ), 'ok' ) );

fh_finish();
