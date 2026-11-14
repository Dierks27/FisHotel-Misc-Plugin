<?php
/**
 * Clean Room XLSX — a dependency-free .xlsx writer: a zip (ZipArchive)
 * of the minimal SpreadsheetML parts — [Content_Types].xml, _rels/.rels,
 * xl/workbook.xml, xl/_rels/workbook.xml.rels, a bare xl/styles.xml and
 * one xl/worksheets/sheetN.xml per sheet.
 *
 * Cells: null → an empty cell; int / float → a number; bool → a boolean;
 * a DateTime → its Y-m-d as text; everything else → text as an INLINE
 * STRING (<c t="inlineStr"><is><t>…</t></is></c>), XML-escaped with the
 * control characters stripped — never a formula, whatever the text starts
 * with, so a species name of "=HYPERLINK(…)" opens as the text it is (the
 * CSV's formula guard, built in). Sheet names are cut to Excel's 31
 * characters with its forbidden characters replaced and made unique.
 *
 * Without the zip extension build() returns '' and the caller falls
 * back to CSV with a notice.
 *
 * @package FisHotel\Misc\Sections\Clean_Room
 */

namespace FisHotel\Misc\Sections\Clean_Room;

defined( 'ABSPATH' ) || exit;

/**
 * Class Xlsx
 */
class Xlsx {

	const NS_MAIN = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
	const NS_REL  = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

	/**
	 * Excel's limit on a sheet name.
	 *
	 * @var int
	 */
	const SHEET_NAME_MAX = 31;

	/**
	 * Whether the writer can work here.
	 *
	 * @return bool
	 */
	public static function available() {
		return class_exists( '\ZipArchive' );
	}

	/**
	 * Build a workbook.
	 *
	 * @param array $sheets 'Sheet name' => rows, each row a list of cells.
	 * @return string The .xlsx bytes, or '' when the zip extension is missing or the write failed.
	 */
	public static function build( array $sheets ) {
		if ( ! self::available() ) {
			return '';
		}

		$parts = self::parts( $sheets );

		$tmp = tempnam( sys_get_temp_dir(), 'fhx' );
		if ( false === $tmp ) {
			return '';
		}

		$zip = new \ZipArchive();
		if ( true !== $zip->open( $tmp, \ZipArchive::OVERWRITE ) ) {
			@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			return '';
		}
		foreach ( $parts as $name => $xml ) {
			$zip->addFromString( $name, $xml );
		}
		$zip->close();

		$bytes = file_get_contents( $tmp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- our own temp file.
		@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		return false === $bytes ? '' : $bytes;
	}

	/**
	 * The package parts (pure): part name => XML.
	 *
	 * @param array $sheets 'Sheet name' => rows.
	 * @return array<string, string>
	 */
	public static function parts( array $sheets ) {
		$names = self::sheet_names( array_keys( $sheets ) );
		if ( ! $names ) {
			$names  = array( 'Sheet1' );
			$sheets = array( 'Sheet1' => array() );
		}
		$rows_list = array_values( $sheets );

		$types     = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
			. '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
			. '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
			. '<Default Extension="xml" ContentType="application/xml"/>'
			. '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
			. '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>';
		$workbook  = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
			. '<workbook xmlns="' . self::NS_MAIN . '" xmlns:r="' . self::NS_REL . '"><sheets>';
		$rels      = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
			. '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
		$parts     = array();

		foreach ( $names as $index => $name ) {
			$n         = $index + 1;
			$types    .= '<Override PartName="/xl/worksheets/sheet' . $n . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
			$workbook .= '<sheet name="' . self::escape( $name ) . '" sheetId="' . $n . '" r:id="rId' . $n . '"/>';
			$rels     .= '<Relationship Id="rId' . $n . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $n . '.xml"/>';

			$parts[ 'xl/worksheets/sheet' . $n . '.xml' ] = self::sheet_xml( is_array( $rows_list[ $index ] ) ? $rows_list[ $index ] : array() );
		}

		$types    .= '</Types>';
		$workbook .= '</sheets></workbook>';
		$rels     .= '<Relationship Id="rId' . ( count( $names ) + 1 ) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>';

		$parts['[Content_Types].xml']        = $types;
		$parts['_rels/.rels']                = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
			. '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
			. '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
			. '</Relationships>';
		$parts['xl/workbook.xml']            = $workbook;
		$parts['xl/_rels/workbook.xml.rels'] = $rels;
		$parts['xl/styles.xml']              = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
			. '<styleSheet xmlns="' . self::NS_MAIN . '">'
			. '<fonts count="1"><font><sz val="11"/><name val="Calibri"/></font></fonts>'
			. '<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>'
			. '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
			. '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
			. '<cellXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/></cellXfs>'
			. '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
			. '</styleSheet>';

		return $parts;
	}

	/**
	 * One worksheet part (pure).
	 *
	 * @param array $rows Rows of cells.
	 * @return string
	 */
	public static function sheet_xml( array $rows ) {
		$xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
			. '<worksheet xmlns="' . self::NS_MAIN . '"><sheetData>';

		$r = 0;
		foreach ( $rows as $row ) {
			$r++;
			if ( ! is_array( $row ) ) {
				$row = array( $row );
			}
			$xml .= '<row r="' . $r . '">';
			$c    = 0;
			foreach ( array_values( $row ) as $value ) {
				$c++;
				$xml .= self::cell_xml( self::col_ref( $c ) . $r, $value );
			}
			$xml .= '</row>';
		}

		return $xml . '</sheetData></worksheet>';
	}

	/**
	 * One cell (pure): '' for null, a number, a boolean, or an inline
	 * string.
	 *
	 * @param string $ref   A1-style reference.
	 * @param mixed  $value The value.
	 * @return string
	 */
	public static function cell_xml( $ref, $value ) {
		if ( null === $value ) {
			return '';
		}
		if ( is_bool( $value ) ) {
			return '<c r="' . $ref . '" t="b"><v>' . ( $value ? '1' : '0' ) . '</v></c>';
		}
		if ( is_int( $value ) ) {
			return '<c r="' . $ref . '"><v>' . $value . '</v></c>';
		}
		if ( is_float( $value ) ) {
			if ( is_nan( $value ) || is_infinite( $value ) ) {
				return '';
			}
			return '<c r="' . $ref . '"><v>' . self::number( $value ) . '</v></c>';
		}
		if ( $value instanceof \DateTimeInterface ) {
			$value = $value->format( 'Y-m-d' );
		} elseif ( ! is_scalar( $value ) ) {
			$value = (string) json_encode( $value );
		}

		$text = self::escape( self::clean( (string) $value ) );
		$keep = $text !== trim( $text ) ? ' xml:space="preserve"' : '';

		return '<c r="' . $ref . '" t="inlineStr"><is><t' . $keep . '>' . $text . '</t></is></c>';
	}

	/**
	 * A float as a locale-independent decimal string: 15 significant
	 * digits (what Excel keeps), trailing zeros dropped, E notation for
	 * the very large or small (%G is not locale-aware; %g and %f are).
	 *
	 * @param float $value Value.
	 * @return string
	 */
	public static function number( $value ) {
		return sprintf( '%.15G', (float) $value );
	}

	/**
	 * Column index (1-based) → letters: 1 → A, 26 → Z, 27 → AA.
	 *
	 * @param int $index Column number.
	 * @return string
	 */
	public static function col_ref( $index ) {
		$index = max( 1, (int) $index );
		$out   = '';
		while ( $index > 0 ) {
			$index--;
			$out   = chr( 65 + ( $index % 26 ) ) . $out;
			$index = intdiv( $index, 26 );
		}
		return $out;
	}

	/**
	 * Legal, unique sheet names: Excel's forbidden characters replaced,
	 * cut to 31 characters, an empty name made "Sheet N", a duplicate
	 * suffixed.
	 *
	 * @param string[] $names Wanted names.
	 * @return string[]
	 */
	public static function sheet_names( array $names ) {
		$out  = array();
		$seen = array();
		foreach ( array_values( $names ) as $index => $name ) {
			$name = trim( (string) preg_replace( '/[\\\\\/\?\*\[\]:]/', '-', (string) $name ) );
			$name = trim( $name, "'" );
			if ( '' === $name ) {
				$name = 'Sheet ' . ( $index + 1 );
			}
			$name = self::cut( $name, self::SHEET_NAME_MAX );

			$base = $name;
			$n    = 2;
			while ( isset( $seen[ strtolower( $name ) ] ) ) {
				$suffix = ' (' . $n . ')';
				$name   = self::cut( $base, self::SHEET_NAME_MAX - strlen( $suffix ) ) . $suffix;
				$n++;
			}
			$seen[ strtolower( $name ) ] = true;
			$out[]                       = $name;
		}
		return $out;
	}

	/**
	 * Cut a string to N characters (multibyte-safe when mbstring is there).
	 *
	 * @param string $text Text.
	 * @param int    $max  Characters.
	 * @return string
	 */
	private static function cut( $text, $max ) {
		if ( function_exists( 'mb_substr' ) ) {
			return mb_substr( (string) $text, 0, (int) $max, 'UTF-8' );
		}
		return substr( (string) $text, 0, (int) $max );
	}

	/**
	 * Strip the control characters XML 1.0 forbids (tab, LF and CR stay).
	 *
	 * @param string $text Text.
	 * @return string
	 */
	public static function clean( $text ) {
		return (string) preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', (string) $text );
	}

	/**
	 * XML-escape text (invalid UTF-8 substituted, never dropped whole).
	 *
	 * @param string $text Text.
	 * @return string
	 */
	public static function escape( $text ) {
		return htmlspecialchars( (string) $text, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
	}
}
