<?php
/**
 * Reader test: hand-written XML parts zipped into a tiny .xlsx.
 *
 * Run from the plugin root:  php includes/sections/clean-room/tests/workbook-test.php
 */

require_once __DIR__ . '/wp-stubs.php';
require_once __DIR__ . '/xlsx-builder.php';

use FisHotel\Misc\Sections\Clean_Room\Workbook;

/**
 * Rewrite the uncompressed size a zip declares for one entry (local
 * header and central directory) — a crafted zip that lies about a part.
 *
 * @param string $path  Zip file.
 * @param string $entry Entry name.
 * @param int    $size  The size to claim.
 * @return bool Whether the entry was found and patched.
 */
function fh_test_patch_zip_size( $path, $entry, $size ) {
	$bin   = file_get_contents( $path );
	$found = false;
	$pos   = strpos( $bin, "PK\x01\x02" );
	for ( ; false !== $pos; $pos = strpos( $bin, "PK\x01\x02", $pos ) ) {
		$name_len = unpack( 'v', substr( $bin, $pos + 28, 2 ) )[1];
		$extra    = unpack( 'v', substr( $bin, $pos + 30, 2 ) )[1];
		$comment  = unpack( 'v', substr( $bin, $pos + 32, 2 ) )[1];
		if ( substr( $bin, $pos + 46, $name_len ) === $entry ) {
			$local = unpack( 'V', substr( $bin, $pos + 42, 4 ) )[1];
			$bin   = substr_replace( $bin, pack( 'V', $size ), $pos + 24, 4 );
			$bin   = substr_replace( $bin, pack( 'V', $size ), $local + 22, 4 );
			$found = true;
		}
		$pos += 46 + $name_len + $extra + $comment;
	}
	return $found && false !== file_put_contents( $path, $bin );
}

$dir  = fh_test_tmp_dir();
$path = fh_test_write_xlsx(
	$dir . '/tiny.xlsx',
	array(
		'Plain'               => array(
			'A1'  => 'hello',
			'B1'  => 'héllo & <world> "quoted"',
			'C1'  => array( 'inline' => 'inline text' ),
			'D1'  => array( 'rich' => array( 'Rich ', 'text ', 'runs' ) ),
			'E1'  => array( 'f' => 'SUM(A2:A3)', 'v' => 5 ),
			'F1'  => true,
			'G1'  => false,
			'H1'  => array( 'f' => 'A9/0', 'e' => '#DIV/0!' ),
			'I1'  => array( 'e' => '#REF!' ),
			'A2'  => 2,
			'B2'  => 2.5,
			'C2'  => array( 'date' => 46234 ),
			'D2'  => array( 'time' => 0.5 ),
			'E2'  => array( 'datetime' => 46234.75 ),
			'F2'  => array( 'custom_date' => 46234 ),
			'G2'  => array( 'custom_time' => 0.354166666667 ),
			'H2'  => array( 'currency' => 1234.5 ),
			'I2'  => array( 'f' => 'A1&"!"', 'str' => 'hello!' ),
			'J2'  => array( 'f' => 'TODAY()', 'v' => null ),
			'A3'  => 3,
			'B3'  => '00123',
			'C3'  => -7,
			'D3'  => 1.5e-3,
			'E3'  => array( 'inline' => '' ),
			'F3'  => 46234,
			'A5'  => 'row five',
			'AB7' => 'wide',
		),
		'Sheet (with) spaces' => array(
			'A1' => 'spaces',
			'B2' => 42,
		),
		'PW'                  => array(
			'A1' => 'dummy secret',
		),
	),
	array(
		'raw_sheets' => array(
			'NoRefs' => '<?xml version="1.0" encoding="UTF-8"?>'
				. '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'
				. '<row><c t="inlineStr"><is><t>a</t></is></c><c/><c><v>3</v></c></row>'
				. '<row><c t="inlineStr"><is><t>b</t></is></c></row>'
				. '<row r="5"><c r="C5" t="inlineStr"><is><r><t>c</t></r><rPh sb="0" eb="1"><t>PHONETIC</t></rPh></is></c></row>'
				. '</sheetData></worksheet>',
		),
	)
);

echo "class-workbook.php\n";

$wb = new Workbook();
check( 'open() succeeds', $wb->open( $path ), $wb->last_error() );
check_same( 'sheet_names() lists sheets in order, PW left out', array( 'Plain', 'Sheet (with) spaces', 'NoRefs' ), $wb->sheet_names() );
check( 'has_sheet() exact', $wb->has_sheet( 'Plain' ) );
check( 'has_sheet() case-insensitive fallback', $wb->has_sheet( 'plain' ) );
check( 'has_sheet() unknown is false', ! $wb->has_sheet( 'Nope' ) );
check( 'has_sheet() PW is false', ! $wb->has_sheet( 'PW' ) );
check( 'is_date1904() false for 1900 system', ! $wb->is_date1904() );

$rows = $wb->rows( 'Plain' );
check( 'rows() returns an array', is_array( $rows ), $wb->last_error() );
check_same( 'row keys are row numbers', array( 1, 2, 3, 5, 7 ), array_keys( $rows ) );
check_same( 'shared string', 'hello', $rows[1]['A'] );
check_same( 'shared string with entities and unicode', 'héllo & <world> "quoted"', $rows[1]['B'] );
check_same( 'inline string', 'inline text', $rows[1]['C'] );
check_same( 'rich text runs concatenated', 'Rich text runs', $rows[1]['D'] );
check_same( 'formula cell returns cached number', 5, $rows[1]['E'] );
check_same( 'boolean true', true, $rows[1]['F'] );
check_same( 'boolean false', false, $rows[1]['G'] );
check_same( 'formula error returned as error string', '#DIV/0!', $rows[1]['H'] );
check_same( 'error cell without formula', '#REF!', $rows[1]['I'] );
check_same( 'integer number', 2, $rows[2]['A'] );
check_same( 'float number', 2.5, $rows[2]['B'] );
check_same( 'date-styled serial (built-in 14)', '2026-07-31', $rows[2]['C'] );
check_same( 'time-styled serial (built-in 20)', '12:00', $rows[2]['D'] );
check_same( 'datetime-styled serial (built-in 22)', '2026-07-31 18:00', $rows[2]['E'] );
check_same( 'custom date format m/d/yyyy', '2026-07-31', $rows[2]['F'] );
check_same( 'custom time format h:mm:ss', '08:30', $rows[2]['G'] );
check_same( 'currency format stays a number', 1234.5, $rows[2]['H'] );
check_same( 'formula with cached string (t="str")', 'hello!', $rows[2]['I'] );
check( 'formula without cached value is absent', ! isset( $rows[2]['J'] ) );
check_same( 'number-like shared string stays a string', '00123', $rows[3]['B'] );
check_same( 'negative integer', -7, $rows[3]['C'] );
check_same( 'small float', 0.0015, $rows[3]['D'] );
check_same( 'empty inline string is an empty string', '', $rows[3]['E'] );
check_same( 'unstyled serial stays a number', 46234, $rows[3]['F'] );
check_same( 'two-letter column', 'wide', $rows[7]['AB'] );

$limited = $wb->rows( 'Plain', 2 );
check_same( 'rows() with max_row stops after that row', array( 1, 2 ), array_keys( $limited ) );
check_same( 'rows() with max_row served from the full cache keeps values', 'hello', $limited[1]['A'] );
$wb->forget( 'Plain' );
$limited = $wb->rows( 'Plain', 3 );
check_same( 'rows() with max_row on a cold cache', array( 1, 2, 3 ), array_keys( $limited ) );
$full = $wb->rows( 'Plain' );
check( 'rows() full read after a limited read re-parses', isset( $full[5] ) && isset( $full[7] ) );

check_same( 'cell() reads a value', 'row five', $wb->cell( 'Plain', 'A5' ) );
check_same( 'cell() with $ anchors', 'row five', $wb->cell( 'Plain', '$A$5' ) );
check_same( 'cell() on an empty cell is null', null, $wb->cell( 'Plain', 'Z99' ) );
check_same( 'cell() on an invalid ref is null', null, $wb->cell( 'Plain', 'bad' ) );
check_contains( 'cell() invalid ref sets last_error', 'Invalid cell reference', $wb->last_error() );

$spaces = $wb->rows( 'Sheet (with) spaces' );
check( 'sheet name with spaces and parentheses', is_array( $spaces ) && 42 === $spaces[2]['B'], $wb->last_error() );
check_same( 'cell() on that sheet', 'spaces', $wb->cell( 'Sheet (with) spaces', 'A1' ) );

check_same( 'unknown sheet returns null', null, $wb->rows( 'Nope' ) );
check_contains( 'unknown sheet sets last_error', 'not found', $wb->last_error() );

check_same( 'PW sheet refused by rows()', null, $wb->rows( 'PW' ) );
check_contains( 'PW refusal names the reason', 'refused', $wb->last_error() );
check_same( 'PW sheet refused case-insensitively and trimmed', null, $wb->rows( ' pw ' ) );
check_same( 'PW sheet refused by cell()', null, $wb->cell( 'PW', 'A1' ) );
check_contains( 'PW cell() refusal sets last_error', 'refused', $wb->last_error() );

$norefs = $wb->rows( 'NoRefs' );
check_same( 'cells without r attributes get positions', array( 'A' => 'a', 'C' => 3 ), isset( $norefs[1] ) ? $norefs[1] : null );
check_same( 'rows without r attributes are numbered', 'b', isset( $norefs[2]['A'] ) ? $norefs[2]['A'] : null );
check_same( 'phonetic rPh runs are skipped', 'c', isset( $norefs[5]['C'] ) ? $norefs[5]['C'] : null );

$wb->close();
check( 'close() drops the sheets', array() === $wb->sheet_names() );
check_same( 'rows() after close() is null', null, $wb->rows( 'Plain' ) );

// Failure modes.
$bad = new Workbook();
check( 'open() missing file fails', ! $bad->open( $dir . '/missing.xlsx' ) );
check_contains( 'missing file error', 'not found', $bad->last_error() );
file_put_contents( $dir . '/text.xlsx', 'this is not a zip file' );
check( 'open() non-zip fails', ! $bad->open( $dir . '/text.xlsx' ) );
check_contains( 'non-zip error', 'zip', $bad->last_error() );
fh_test_write_xlsx( $dir . '/no-workbook.xlsx', array( 'S' => array( 'A1' => 1 ) ), array( 'omit' => array( 'xl/workbook.xml' ) ) );
check( 'open() zip without workbook.xml fails', ! $bad->open( $dir . '/no-workbook.xlsx' ) );
check_contains( 'not-a-spreadsheet error', 'Not a spreadsheet', $bad->last_error() );
check( 'open() a plain string path that is a directory fails', ! $bad->open( $dir ) );

// 1904 date system.
fh_test_write_xlsx( $dir . '/d1904.xlsx', array( 'S' => array( 'A1' => array( 'date' => 44772 ), 'B1' => array( 'datetime' => 0.25 ) ) ), array( 'date1904' => true ) );
$wb1904 = new Workbook();
check( 'open() 1904 workbook', $wb1904->open( $dir . '/d1904.xlsx' ), $wb1904->last_error() );
check( 'is_date1904() true', $wb1904->is_date1904() );
check_same( '1904 serial converts', '2026-07-31', $wb1904->cell( 'S', 'A1' ) );
check_same( '1904 serial 0.25 is 1904-01-01 06:00', '1904-01-01 06:00', $wb1904->cell( 'S', 'B1' ) );

// Zip-bomb guard: a small limit refuses the big sheet but not the small one.
$big = array();
for ( $i = 1; $i <= 120; $i++ ) {
	$big[ 'A' . $i ] = $i;
}
fh_test_write_xlsx( $dir . '/big.xlsx', array( 'Big' => $big, 'Small' => array( 'A1' => 1 ) ) );
$guarded = new Workbook( array( 'max_entry_bytes' => 1500 ) );
check( 'open() with a small entry limit still opens', $guarded->open( $dir . '/big.xlsx' ), $guarded->last_error() );
check_same( 'oversized sheet part is refused', null, $guarded->rows( 'Big' ) );
check_contains( 'oversized refusal message', 'exceeds', $guarded->last_error() );
check_same( 'small sheet part still reads', 1, $guarded->cell( 'Small', 'A1' ) );

// A zip whose directory LIES about a part's size (declares 100 bytes for
// the ~4 KB Big sheet): the read is bounded by the limit, not by the claim,
// and a length that does not match the claim is refused as damaged.
fh_test_write_xlsx( $dir . '/liar.xlsx', array( 'Big' => $big, 'Small' => array( 'A1' => 1 ) ) );
check( 'the declared size of the Big sheet is patched to 100 bytes', fh_test_patch_zip_size( $dir . '/liar.xlsx', 'xl/worksheets/sheet1.xml', 100 ) );
$liar = new Workbook();
check( 'open() a zip with a lying part', $liar->open( $dir . '/liar.xlsx' ), $liar->last_error() );
check_same( 'a part whose bytes do not match the declared size is refused', null, $liar->rows( 'Big' ) );
check_contains( 'the refusal says it is damaged', 'damaged', $liar->last_error() );
check_same( 'the honest part next to it still reads', 1, $liar->cell( 'Small', 'A1' ) );
$liar_guarded = new Workbook( array( 'max_entry_bytes' => 1500 ) );
$liar_guarded->open( $dir . '/liar.xlsx' );
check_same( 'a lying part that inflates past the limit is refused at the limit', null, $liar_guarded->rows( 'Big' ) );
check_contains( 'the refusal names the limit', 'inflates past the 1500 byte limit', $liar_guarded->last_error() );

// The value cap: a sheet (or a shared-strings table) with more values than
// max_cells is refused; a numeric sheet does not need the shared strings.
fh_test_write_xlsx( $dir . '/cells.xlsx', array( 'Big' => $big, 'Words' => array( 'A1' => 'one', 'A2' => 'two', 'A3' => 'three' ), 'Nums' => array( 'A1' => 1, 'B1' => 2 ) ) );
$capped = new Workbook( array( 'max_cells' => 50 ) );
$capped->open( $dir . '/cells.xlsx' );
check_same( 'a sheet with 120 values is refused under a 50-value cap', null, $capped->rows( 'Big' ) );
check_contains( 'the refusal names the cap', 'more than 50 values', $capped->last_error() );
check_same( 'a sheet under the cap reads', array( 1 => array( 'A' => 1, 'B' => 2 ) ), $capped->rows( 'Nums' ) );
$capped = new Workbook( array( 'max_cells' => 2 ) );
$capped->open( $dir . '/cells.xlsx' );
check_same( 'three shared strings under a 2-value cap: the sheet that needs them is refused', null, $capped->rows( 'Words' ) );
check_contains( 'the refusal names the shared strings', 'shared strings', $capped->last_error() );
check_same( 'a numeric sheet still reads after that', array( 1 => array( 'A' => 1, 'B' => 2 ) ), $capped->rows( 'Nums' ) );
check( 'the default cap is generous (300000 values)', 300000 === Workbook::DEFAULT_MAX_CELLS );

// External entities and DTDs are never loaded (sheet, shared strings, styles).
file_put_contents( $dir . '/secret.txt', 'LEAKED-MARKER' );
$xxe_dtd = '<!DOCTYPE x [<!ENTITY xxe SYSTEM "file://' . $dir . '/secret.txt">]>';
fh_test_write_xlsx(
	$dir . '/xxe.xlsx',
	array( 'Shared' => array( 'A1' => 'placeholder' ) ),
	array(
		'raw_sheets' => array(
			'Doctype' => '<?xml version="1.0" encoding="UTF-8"?>' . $xxe_dtd
				. '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'
				. '<row r="1"><c r="A1" t="inlineStr"><is><t>a&xxe;b</t></is></c></row></sheetData></worksheet>',
		),
	)
);
$zip = new ZipArchive();
$zip->open( $dir . '/xxe.xlsx' );
$zip->addFromString( 'xl/sharedStrings.xml', '<?xml version="1.0" encoding="UTF-8"?>' . $xxe_dtd . '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="1" uniqueCount="1"><si><t>c&xxe;d</t></si></sst>' );
$zip->addFromString( 'xl/styles.xml', '<?xml version="1.0" encoding="UTF-8"?>' . $xxe_dtd . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><numFmts count="1"><numFmt numFmtId="164" formatCode="&xxe;"/></numFmts><cellXfs count="1"><xf numFmtId="164"/></cellXfs></styleSheet>' );
$zip->close();
$xxe = new Workbook();
check( 'open() a workbook whose parts declare an external entity', $xxe->open( $dir . '/xxe.xlsx' ), $xxe->last_error() );
check_same( 'the sheet\'s external entity is not resolved', 'ab', $xxe->cell( 'Doctype', 'A1' ) );
check_same( 'the shared string\'s external entity is not resolved', 'cd', $xxe->cell( 'Shared', 'A1' ) );

// Extra refused names.
$refusing = new Workbook( array( 'refuse' => array( 'Small' ) ) );
$refusing->open( $dir . '/big.xlsx' );
check_same( 'extra refused sheet is hidden', array( 'Big' ), $refusing->sheet_names() );
check_same( 'extra refused sheet is not readable', null, $refusing->rows( 'Small' ) );

// Static helpers.
check_same( 'col_letter(1)', 'A', Workbook::col_letter( 1 ) );
check_same( 'col_letter(26)', 'Z', Workbook::col_letter( 26 ) );
check_same( 'col_letter(27)', 'AA', Workbook::col_letter( 27 ) );
check_same( 'col_letter(44)', 'AR', Workbook::col_letter( 44 ) );
check_same( 'col_index(AB)', 28, Workbook::col_index( 'AB' ) );
check_same( 'col_index(AR)', 44, Workbook::col_index( 'AR' ) );
check_same( 'col_index(bad)', 0, Workbook::col_index( '1' ) );
check_same( 'split_ref(C2)', array( 'C', 2 ), Workbook::split_ref( 'C2' ) );
check_same( 'split_ref(invalid)', null, Workbook::split_ref( '2C' ) );
check_same( 'serial 1 is 1900-01-01', '1900-01-01', Workbook::serial_to_date( 1 ) );
check_same( 'serial 59 is 1900-02-28', '1900-02-28', Workbook::serial_to_date( 59 ) );
check_same( 'serial 61 is 1900-03-01', '1900-03-01', Workbook::serial_to_date( 61 ) );
check_same( 'serial 25569 is 1970-01-01', '1970-01-01', Workbook::serial_to_date( 25569 ) );
check_same( 'serial 46036 is 2026-01-14', '2026-01-14', Workbook::serial_to_date( 46036 ) );
check_same( 'serial_to_time(0.75)', '18:00', Workbook::serial_to_time( 0.75 ) );
check_same( 'serial_to_time ignores the day part', '06:00', Workbook::serial_to_time( 46036.25 ) );
check_same( 'format_kind m/d/yyyy', 'date', Workbook::format_kind( 'm/d/yyyy' ) );
check_same( 'format_kind h:mm', 'time', Workbook::format_kind( 'h:mm' ) );
check_same( 'format_kind [$-409]mmm-yy', 'date', Workbook::format_kind( '[$-409]mmm-yy;@' ) );
check_same( 'format_kind [h]:mm:ss', 'time', Workbook::format_kind( '[h]:mm:ss' ) );
check_same( 'format_kind h:mm AM/PM', 'time', Workbook::format_kind( 'h:mm AM/PM' ) );
check_same( 'format_kind m/d/yy h:mm', 'datetime', Workbook::format_kind( 'm/d/yy h:mm' ) );
check_same( 'format_kind mmmm', 'date', Workbook::format_kind( 'mmmm' ) );
check_same( 'format_kind 0.00', null, Workbook::format_kind( '0.00' ) );
check_same( 'format_kind currency', null, Workbook::format_kind( '"$"#,##0.00_);[Red]("$"#,##0.00)' ) );
check_same( 'format_kind General', null, Workbook::format_kind( 'General' ) );
check_same( 'format_kind quoted d', null, Workbook::format_kind( '0" days"' ) );
check_same( 'format_kind text', null, Workbook::format_kind( '@' ) );
check_same( 'format_kind scientific', null, Workbook::format_kind( '0.00E+00' ) );
check_same( 'builtin 14', 'date', Workbook::builtin_format_kind( 14 ) );
check_same( 'builtin 21', 'time', Workbook::builtin_format_kind( 21 ) );
check_same( 'builtin 22', 'datetime', Workbook::builtin_format_kind( 22 ) );
check_same( 'builtin 47', 'time', Workbook::builtin_format_kind( 47 ) );
check_same( 'builtin 0', null, Workbook::builtin_format_kind( 0 ) );
check_same( 'builtin 49', null, Workbook::builtin_format_kind( 49 ) );

fh_test_rmdir( $dir );
fh_finish();
