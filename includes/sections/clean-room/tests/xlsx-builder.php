<?php
/**
 * Minimal .xlsx writer for the tests: hand-written XML parts assembled with
 * ZipArchive. Only what the reader tests and the synthetic fixture need.
 *
 * Cell specs (sheet => array( 'A1' => spec )):
 *   string                                  shared string
 *   int|float                               number
 *   bool                                    boolean
 *   array( 'inline' => 'text' )             inline string
 *   array( 'rich' => array( 'a', 'b' ) )    rich-text shared string (runs)
 *   array( 'f' => 'A1+1', 'v' => 5 )        formula with a cached number
 *   array( 'f' => 'A1&"x"', 'str' => 's' )  formula with a cached string
 *   array( 'f' => '1/0', 'e' => '#DIV/0!' ) formula with a cached error ('f' optional)
 *   array( 'date' => 46234 )                serial styled with built-in format 14
 *   array( 'time' => 0.5 )                  serial styled with built-in format 20
 *   array( 'datetime' => 46234.5 )          serial styled with built-in format 22
 *   array( 'custom_date' => 46234 )         serial styled with custom 'm/d/yyyy'
 *   array( 'custom_time' => 0.5 )           serial styled with custom 'h:mm:ss'
 *   array( 'currency' => 12.5 )             number styled with a custom currency format
 *   array( 'n' => 5, 's' => 3 )             number with an explicit style index
 *   array( 'raw' => '<c r="A1">…</c>' )     verbatim cell XML
 *
 * Options: 'date1904' => bool, 'raw_sheets' => array( name => worksheet XML ),
 *          'omit' => array( entry names to leave out ).
 */

// Helper, not a test: the CI lint job runs every tests/*.php with php, so
// exit cleanly when executed directly instead of as a require.
if ( ! empty( $_SERVER['SCRIPT_FILENAME'] ) && realpath( $_SERVER['SCRIPT_FILENAME'] ) === __FILE__ ) {
	exit( 0 );
}

/**
 * @param string $path
 * @param array  $sheets
 * @param array  $options
 * @return string The path written.
 */
function fh_test_write_xlsx( $path, $sheets, $options = array() ) {
	$strings   = array();
	$string_ix = array();
	$add_string = function ( $xml_inner ) use ( &$strings, &$string_ix ) {
		if ( ! isset( $string_ix[ $xml_inner ] ) ) {
			$string_ix[ $xml_inner ] = count( $strings );
			$strings[]               = $xml_inner;
		}
		return $string_ix[ $xml_inner ];
	};

	$sheet_xml = array();
	$names     = array();
	foreach ( $sheets as $name => $cells ) {
		$names[]     = $name;
		$sheet_xml[] = fh_test_sheet_xml( $cells, $add_string );
	}
	if ( ! empty( $options['raw_sheets'] ) ) {
		foreach ( $options['raw_sheets'] as $name => $xml ) {
			$names[]     = $name;
			$sheet_xml[] = $xml;
		}
	}

	$main = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
	$rel  = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

	$workbook = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
		. '<workbook xmlns="' . $main . '" xmlns:r="' . $rel . '">'
		. ( ! empty( $options['date1904'] ) ? '<workbookPr date1904="1"/>' : '<workbookPr/>' )
		. '<sheets>';
	$rels     = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
		. '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
	$types    = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
		. '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
		. '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
		. '<Default Extension="xml" ContentType="application/xml"/>'
		. '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
		. '<Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/>'
		. '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>';

	foreach ( $names as $i => $name ) {
		$n         = $i + 1;
		$workbook .= '<sheet name="' . fh_test_xml( $name ) . '" sheetId="' . $n . '" r:id="rId' . $n . '"/>';
		$rels     .= '<Relationship Id="rId' . $n . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $n . '.xml"/>';
		$types    .= '<Override PartName="/xl/worksheets/sheet' . $n . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
	}
	$workbook .= '</sheets></workbook>';
	$rels     .= '<Relationship Id="rIdS" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/>'
		. '<Relationship Id="rIdT" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
		. '</Relationships>';
	$types    .= '</Types>';

	$sst = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
		. '<sst xmlns="' . $main . '" count="' . count( $strings ) . '" uniqueCount="' . count( $strings ) . '">';
	foreach ( $strings as $inner ) {
		$sst .= '<si>' . $inner . '</si>';
	}
	$sst .= '</sst>';

	$styles = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
		. '<styleSheet xmlns="' . $main . '">'
		. '<numFmts count="3">'
		. '<numFmt numFmtId="164" formatCode="m/d/yyyy"/>'
		. '<numFmt numFmtId="165" formatCode="h:mm:ss"/>'
		. '<numFmt numFmtId="166" formatCode="&quot;$&quot;#,##0.00"/>'
		. '</numFmts>'
		. '<fonts count="1"><font><sz val="11"/><name val="Calibri"/></font></fonts>'
		. '<fills count="1"><fill><patternFill patternType="none"/></fill></fills>'
		. '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
		. '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
		. '<cellXfs count="7">'
		. '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'                            // 0 general
		. '<xf numFmtId="14" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'     // 1 date
		. '<xf numFmtId="20" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'     // 2 time
		. '<xf numFmtId="22" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'     // 3 datetime
		. '<xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'    // 4 custom date
		. '<xf numFmtId="165" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'    // 5 custom time
		. '<xf numFmtId="166" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'    // 6 currency
		. '</cellXfs>'
		. '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
		. '</styleSheet>';

	$root_rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
		. '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
		. '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
		. '</Relationships>';

	$entries = array(
		'[Content_Types].xml'        => $types,
		'_rels/.rels'                => $root_rels,
		'xl/workbook.xml'            => $workbook,
		'xl/_rels/workbook.xml.rels' => $rels,
		'xl/sharedStrings.xml'       => $sst,
		'xl/styles.xml'              => $styles,
	);
	foreach ( $sheet_xml as $i => $xml ) {
		$entries[ 'xl/worksheets/sheet' . ( $i + 1 ) . '.xml' ] = $xml;
	}
	if ( ! empty( $options['omit'] ) ) {
		foreach ( $options['omit'] as $entry ) {
			unset( $entries[ $entry ] );
		}
	}

	$zip = new ZipArchive();
	if ( true !== $zip->open( $path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
		fwrite( STDERR, "Cannot create {$path}\n" );
		exit( 1 );
	}
	foreach ( $entries as $entry => $content ) {
		$zip->addFromString( $entry, $content );
	}
	$zip->close();
	return $path;
}

/**
 * Worksheet XML for a cell map.
 *
 * @param array    $cells      'A1' => spec.
 * @param callable $add_string Registers a shared string, returns its index.
 * @return string
 */
function fh_test_sheet_xml( $cells, $add_string ) {
	$by_row = array();
	foreach ( $cells as $ref => $spec ) {
		if ( ! preg_match( '/^([A-Z]+)([0-9]+)$/', $ref, $m ) ) {
			fwrite( STDERR, "Bad cell ref {$ref}\n" );
			exit( 1 );
		}
		$by_row[ (int) $m[2] ][ fh_test_col_index( $m[1] ) ] = array( $ref, $spec );
	}
	ksort( $by_row );
	$xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
		. '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
		. '<sheetData>';
	foreach ( $by_row as $r => $row_cells ) {
		ksort( $row_cells );
		$xml .= '<row r="' . $r . '">';
		foreach ( $row_cells as $pair ) {
			$xml .= fh_test_cell_xml( $pair[0], $pair[1], $add_string );
		}
		$xml .= '</row>';
	}
	$xml .= '</sheetData></worksheet>';
	return $xml;
}

/**
 * XML of one <c> element.
 *
 * @param string   $ref
 * @param mixed    $spec
 * @param callable $add_string
 * @return string
 */
function fh_test_cell_xml( $ref, $spec, $add_string ) {
	if ( is_bool( $spec ) ) {
		return '<c r="' . $ref . '" t="b"><v>' . ( $spec ? '1' : '0' ) . '</v></c>';
	}
	if ( is_int( $spec ) || is_float( $spec ) ) {
		return '<c r="' . $ref . '"><v>' . fh_test_num( $spec ) . '</v></c>';
	}
	if ( is_string( $spec ) ) {
		$index = $add_string( '<t xml:space="preserve">' . fh_test_xml( $spec ) . '</t>' );
		return '<c r="' . $ref . '" t="s"><v>' . $index . '</v></c>';
	}
	if ( null === $spec ) {
		return '<c r="' . $ref . '"/>';
	}
	if ( isset( $spec['raw'] ) ) {
		return $spec['raw'];
	}
	if ( isset( $spec['inline'] ) ) {
		return '<c r="' . $ref . '" t="inlineStr"><is><t xml:space="preserve">' . fh_test_xml( $spec['inline'] ) . '</t></is></c>';
	}
	if ( isset( $spec['rich'] ) ) {
		$inner = '';
		foreach ( $spec['rich'] as $run ) {
			$inner .= '<r><rPr><b/></rPr><t xml:space="preserve">' . fh_test_xml( $run ) . '</t></r>';
		}
		$index = $add_string( $inner );
		return '<c r="' . $ref . '" t="s"><v>' . $index . '</v></c>';
	}
	$formula = isset( $spec['f'] ) ? '<f>' . fh_test_xml( $spec['f'] ) . '</f>' : '';
	if ( array_key_exists( 'e', $spec ) ) {
		return '<c r="' . $ref . '" t="e">' . $formula . '<v>' . fh_test_xml( $spec['e'] ) . '</v></c>';
	}
	if ( array_key_exists( 'str', $spec ) ) {
		return '<c r="' . $ref . '" t="str">' . $formula . '<v>' . fh_test_xml( $spec['str'] ) . '</v></c>';
	}
	if ( array_key_exists( 'v', $spec ) ) {
		if ( null === $spec['v'] ) {
			return '<c r="' . $ref . '">' . $formula . '</c>';
		}
		$style = isset( $spec['s'] ) ? ' s="' . (int) $spec['s'] . '"' : '';
		return '<c r="' . $ref . '"' . $style . '>' . $formula . '<v>' . fh_test_num( $spec['v'] ) . '</v></c>';
	}
	$styled = array(
		'date'        => 1,
		'time'        => 2,
		'datetime'    => 3,
		'custom_date' => 4,
		'custom_time' => 5,
		'currency'    => 6,
	);
	foreach ( $styled as $key => $style ) {
		if ( array_key_exists( $key, $spec ) ) {
			return '<c r="' . $ref . '" s="' . $style . '"><v>' . fh_test_num( $spec[ $key ] ) . '</v></c>';
		}
	}
	if ( array_key_exists( 'n', $spec ) ) {
		return '<c r="' . $ref . '" s="' . (int) $spec['s'] . '"><v>' . fh_test_num( $spec['n'] ) . '</v></c>';
	}
	fwrite( STDERR, "Unknown cell spec for {$ref}\n" );
	exit( 1 );
}

/**
 * @param string $text
 * @return string
 */
function fh_test_xml( $text ) {
	return htmlspecialchars( (string) $text, ENT_XML1 | ENT_QUOTES, 'UTF-8' );
}

/**
 * @param int|float $number
 * @return string
 */
function fh_test_num( $number ) {
	if ( is_int( $number ) ) {
		return (string) $number;
	}
	return rtrim( rtrim( sprintf( '%.12F', $number ), '0' ), '.' );
}

/**
 * @param string $letters
 * @return int
 */
function fh_test_col_index( $letters ) {
	$index = 0;
	$len   = strlen( $letters );
	for ( $i = 0; $i < $len; $i++ ) {
		$index = $index * 26 + ( ord( $letters[ $i ] ) - 64 );
	}
	return $index;
}

/**
 * @param int $index 1-based.
 * @return string
 */
function fh_test_col_letter( $index ) {
	$letters = '';
	while ( $index > 0 ) {
		$index--;
		$letters = chr( 65 + ( $index % 26 ) ) . $letters;
		$index   = intdiv( $index, 26 );
	}
	return $letters;
}
