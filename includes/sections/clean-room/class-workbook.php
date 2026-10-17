<?php
/**
 * Dependency-free reader for Excel .xlsx / .xlsm workbooks.
 *
 * Reads cached cell values only (formulas are never evaluated). The zip
 * container is handled by ZipArchive, the big parts (worksheets, shared
 * strings) are streamed with XMLReader and the small parts (workbook,
 * relationships, styles) are parsed with SimpleXML. No WordPress functions
 * are used so the class can be unit-tested headless.
 *
 * Values come back as PHP scalars: string, int, float, bool or null.
 * Serial numbers in cells styled with a date / time number format are
 * converted to 'Y-m-d', 'H:i' or 'Y-m-d H:i' strings.
 *
 * A sheet named "PW" (case-insensitive) is never opened: it is hidden from
 * sheet_names(), has_sheet() returns false for it and rows() / cell()
 * refuse it with an error.
 *
 * @package FisHotel\Misc\Sections\Clean_Room
 */

namespace FisHotel\Misc\Sections\Clean_Room;

defined( 'ABSPATH' ) || exit;

class Workbook {

	/** Relationship type suffixes used to find the package parts. */
	const REL_WORKSHEET     = '/worksheet';
	const REL_SHARED_STRING = '/sharedStrings';
	const REL_STYLES        = '/styles';

	/** Refuse any zip entry larger than this many bytes when uncompressed (zip-bomb guard). */
	const DEFAULT_MAX_ENTRY_BYTES = 52428800;

	/** Serial number of 1970-01-01 in the 1900 date system. */
	const UNIX_EPOCH_SERIAL = 25569;

	/** @var string Absolute path of the open file. */
	private $path = '';

	/** @var \ZipArchive|null */
	private $zip = null;

	/** @var string Last error message, '' when the last call succeeded. */
	private $last_error = '';

	/** @var int */
	private $max_entry_bytes = self::DEFAULT_MAX_ENTRY_BYTES;

	/** @var string[] Sheet names that are never opened (compared case-insensitively, trimmed). */
	private $refused = array( 'PW' );

	/** @var array Sheet name => array( 'entry' => zip entry, 'sheet_id' => string, 'state' => string ), in workbook order. */
	private $sheets = array();

	/** @var array Lower-cased sheet name => sheet name. */
	private $sheet_index = array();

	/** @var bool Workbook uses the 1904 date system. */
	private $date1904 = false;

	/** @var string Zip entry of the shared strings part. */
	private $strings_entry = 'xl/sharedStrings.xml';

	/** @var string Zip entry of the styles part. */
	private $styles_entry = 'xl/styles.xml';

	/** @var string[]|null Shared strings, loaded on first use. */
	private $strings = null;

	/** @var array|null cellXfs index => 'date' | 'time' | 'datetime' | null, loaded on first use. */
	private $style_kinds = null;

	/** @var array Sheet name => array( 'max_row' => int|null, 'rows' => array ). */
	private $cache = array();

	/**
	 * @param array $options Optional: 'max_entry_bytes' (int), 'refuse' (string[] of extra sheet names to refuse).
	 */
	public function __construct( $options = array() ) {
		if ( isset( $options['max_entry_bytes'] ) && (int) $options['max_entry_bytes'] > 0 ) {
			$this->max_entry_bytes = (int) $options['max_entry_bytes'];
		}
		if ( isset( $options['refuse'] ) && is_array( $options['refuse'] ) ) {
			foreach ( $options['refuse'] as $name ) {
				$this->refused[] = trim( (string) $name );
			}
		}
	}

	public function __destruct() {
		$this->close();
	}

	/* ------------------------------------------------------------------ */
	/* Public API                                                          */
	/* ------------------------------------------------------------------ */

	/**
	 * Open a workbook. Returns false (see last_error()) when the file is not
	 * a readable OOXML spreadsheet.
	 *
	 * @param string $path
	 * @return bool
	 */
	public function open( $path ) {
		$this->close();
		$this->last_error = '';

		if ( ! is_string( $path ) || '' === $path ) {
			return $this->fail( 'No file path given.' );
		}
		if ( ! is_file( $path ) || ! is_readable( $path ) ) {
			return $this->fail( 'File not found or not readable: ' . $path );
		}
		$real = realpath( $path );
		if ( false !== $real ) {
			$path = $real;
		}

		$zip  = new \ZipArchive();
		$code = $zip->open( $path );
		if ( true !== $code ) {
			return $this->fail( 'Cannot open zip container: ' . self::zip_error_message( $code ) );
		}
		$this->zip  = $zip;
		$this->path = $path;

		$workbook_xml = $this->read_entry( 'xl/workbook.xml' );
		if ( null === $workbook_xml ) {
			$this->close();
			$this->last_error = 'Not a spreadsheet: ' . $this->last_error;
			return false;
		}
		$workbook = $this->parse_xml( $workbook_xml );
		if ( ! $workbook ) {
			$this->close();
			return $this->fail( 'Cannot parse xl/workbook.xml.' );
		}

		if ( isset( $workbook->workbookPr ) && isset( $workbook->workbookPr['date1904'] ) ) {
			$flag           = strtolower( trim( (string) $workbook->workbookPr['date1904'] ) );
			$this->date1904 = ( '1' === $flag || 'true' === $flag );
		}

		$rels = $this->read_relationships( 'xl/_rels/workbook.xml.rels', 'xl/' );
		foreach ( $rels as $rel ) {
			if ( self::ends_with( $rel['type'], self::REL_SHARED_STRING ) ) {
				$this->strings_entry = $rel['target'];
			} elseif ( self::ends_with( $rel['type'], self::REL_STYLES ) ) {
				$this->styles_entry = $rel['target'];
			}
		}

		$namespaces = $workbook->getDocNamespaces( true );
		if ( isset( $workbook->sheets ) ) {
			foreach ( $workbook->sheets->sheet as $sheet ) {
				$name = (string) $sheet['name'];
				if ( '' === $name || $this->is_refused( $name ) ) {
					continue;
				}
				$rid = '';
				foreach ( $namespaces as $uri ) {
					$attrs = $sheet->attributes( $uri );
					if ( isset( $attrs->id ) ) {
						$rid = (string) $attrs->id;
						break;
					}
				}
				$entry = ( '' !== $rid && isset( $rels[ $rid ] ) ) ? $rels[ $rid ]['target'] : '';
				$this->sheets[ $name ]                        = array(
					'entry'    => $entry,
					'sheet_id' => (string) $sheet['sheetId'],
					'state'    => isset( $sheet['state'] ) ? (string) $sheet['state'] : 'visible',
				);
				$this->sheet_index[ strtolower( trim( $name ) ) ] = $name;
			}
		}

		return true;
	}

	/**
	 * Close the file and drop every cache.
	 */
	public function close() {
		if ( $this->zip instanceof \ZipArchive ) {
			// Nothing was modified, so close() only releases the handle.
			@$this->zip->close();
		}
		$this->zip         = null;
		$this->path        = '';
		$this->sheets      = array();
		$this->sheet_index = array();
		$this->strings     = null;
		$this->style_kinds = null;
		$this->cache       = array();
		$this->date1904    = false;
	}

	/** @return string The last error message ('' if none). */
	public function last_error() {
		return $this->last_error;
	}

	/** @return string Absolute path of the open file ('' when closed). */
	public function path() {
		return $this->path;
	}

	/** @return bool */
	public function is_open() {
		return $this->zip instanceof \ZipArchive;
	}

	/** @return bool Whether the workbook uses the 1904 date system. */
	public function is_date1904() {
		return $this->date1904;
	}

	/**
	 * Sheet names in workbook order, refused sheets left out.
	 *
	 * @return string[]
	 */
	public function sheet_names() {
		return array_keys( $this->sheets );
	}

	/**
	 * @param string $name Sheet name (exact, or case-insensitive fallback).
	 * @return bool
	 */
	public function has_sheet( $name ) {
		return null !== $this->resolve_sheet_name( $name );
	}

	/**
	 * Read a sheet.
	 *
	 * @param string   $name    Sheet name.
	 * @param int|null $max_row Stop after this row number (1-based) when given.
	 * @return array|null Row number => array( column letter => value ). Only
	 *                    cells with a value are present; rows without any
	 *                    value are left out. Null on error (see last_error()).
	 */
	public function rows( $name, $max_row = null ) {
		$this->last_error = '';
		$max_row          = ( null === $max_row ) ? null : max( 0, (int) $max_row );

		if ( ! $this->is_open() ) {
			$this->fail( 'No workbook is open.' );
			return null;
		}
		if ( $this->is_refused( $name ) ) {
			$this->fail( 'Sheet "' . trim( (string) $name ) . '" is refused: it is never read.' );
			return null;
		}
		$real_name = $this->resolve_sheet_name( $name );
		if ( null === $real_name ) {
			$this->fail( 'Sheet "' . (string) $name . '" not found.' );
			return null;
		}

		if ( isset( $this->cache[ $real_name ] ) ) {
			$cached = $this->cache[ $real_name ];
			if ( null === $cached['max_row'] || ( null !== $max_row && $max_row <= $cached['max_row'] ) ) {
				return $this->limit_rows( $cached['rows'], $max_row );
			}
		}

		$entry = $this->sheets[ $real_name ]['entry'];
		if ( '' === $entry || false === $this->zip->locateName( $entry ) ) {
			$this->fail( 'Sheet "' . $real_name . '" has no worksheet part in the file.' );
			return null;
		}
		if ( ! $this->entry_size_ok( $entry ) ) {
			return null;
		}

		$rows = $this->parse_sheet( $entry, $max_row );
		if ( null === $rows ) {
			return null;
		}
		$this->cache[ $real_name ] = array(
			'max_row' => $max_row,
			'rows'    => $rows,
		);
		return $rows;
	}

	/**
	 * Read one cell, e.g. cell( 'Data', 'C2' ).
	 *
	 * @param string $name Sheet name.
	 * @param string $ref  A1-style reference.
	 * @return mixed Null when the cell is empty, the sheet is unknown or refused (see last_error()).
	 */
	public function cell( $name, $ref ) {
		$split = self::split_ref( $ref );
		if ( null === $split ) {
			$this->fail( 'Invalid cell reference: ' . (string) $ref );
			return null;
		}
		$rows = $this->rows( $name );
		if ( null === $rows ) {
			return null;
		}
		list( $col, $row ) = $split;
		return isset( $rows[ $row ][ $col ] ) ? $rows[ $row ][ $col ] : null;
	}

	/**
	 * Drop the cached rows of one sheet (or of every sheet).
	 *
	 * @param string|null $name
	 */
	public function forget( $name = null ) {
		if ( null === $name ) {
			$this->cache = array();
			return;
		}
		$real_name = $this->resolve_sheet_name( $name );
		if ( null !== $real_name ) {
			unset( $this->cache[ $real_name ] );
		}
	}

	/* ------------------------------------------------------------------ */
	/* Static helpers (also used by the mapper)                            */
	/* ------------------------------------------------------------------ */

	/**
	 * Column letters of a 1-based column index: 1 => 'A', 27 => 'AA'.
	 *
	 * @param int $index
	 * @return string
	 */
	public static function col_letter( $index ) {
		$index   = (int) $index;
		$letters = '';
		while ( $index > 0 ) {
			$index--;
			$letters = chr( 65 + ( $index % 26 ) ) . $letters;
			$index   = intdiv( $index, 26 );
		}
		return $letters;
	}

	/**
	 * 1-based column index of column letters: 'A' => 1, 'AA' => 27.
	 *
	 * @param string $letters
	 * @return int 0 when the letters are not a column.
	 */
	public static function col_index( $letters ) {
		$letters = strtoupper( trim( (string) $letters ) );
		if ( '' === $letters || ! preg_match( '/^[A-Z]+$/', $letters ) ) {
			return 0;
		}
		$index = 0;
		$len   = strlen( $letters );
		for ( $i = 0; $i < $len; $i++ ) {
			$index = $index * 26 + ( ord( $letters[ $i ] ) - 64 );
		}
		return $index;
	}

	/**
	 * Split an A1 reference into column letters and row number.
	 *
	 * @param string $ref
	 * @return array|null array( 'C', 2 ) or null when invalid.
	 */
	public static function split_ref( $ref ) {
		if ( ! is_string( $ref ) || ! preg_match( '/^\$?([A-Za-z]{1,3})\$?([0-9]{1,7})$/', trim( $ref ), $m ) ) {
			return null;
		}
		return array( strtoupper( $m[1] ), (int) $m[2] );
	}

	/**
	 * Excel serial number => 'Y-m-d'.
	 *
	 * @param int|float $serial
	 * @param bool      $date1904
	 * @return string
	 */
	public static function serial_to_date( $serial, $date1904 = false ) {
		$days = (int) floor( (float) $serial );
		if ( $date1904 ) {
			$days += 1462; // 1904-01-01 is serial 1462 in the 1900 system.
		} elseif ( $days < 61 ) {
			$days += 1; // Before the phantom 1900-02-29 the 1900 system is one day behind.
		}
		return gmdate( 'Y-m-d', ( $days - self::UNIX_EPOCH_SERIAL ) * 86400 );
	}

	/**
	 * Fractional part of an Excel serial number => 'H:i'.
	 *
	 * @param int|float $serial
	 * @return string
	 */
	public static function serial_to_time( $serial ) {
		$serial  = (float) $serial;
		$seconds = (int) round( ( $serial - floor( $serial ) ) * 86400 );
		if ( $seconds >= 86400 ) {
			$seconds = 0;
		}
		return sprintf( '%02d:%02d', intdiv( $seconds, 3600 ), intdiv( $seconds % 3600, 60 ) );
	}

	/**
	 * Excel serial number => 'Y-m-d H:i'.
	 *
	 * @param int|float $serial
	 * @param bool      $date1904
	 * @return string
	 */
	public static function serial_to_datetime( $serial, $date1904 = false ) {
		return self::serial_to_date( $serial, $date1904 ) . ' ' . self::serial_to_time( $serial );
	}

	/**
	 * Classify a number format code: 'date', 'time', 'datetime' or null.
	 *
	 * @param string $code Format code such as 'm/d/yyyy' or '[$-409]h:mm AM/PM'.
	 * @return string|null
	 */
	public static function format_kind( $code ) {
		$code = strtolower( trim( (string) $code ) );
		if ( '' === $code || 'general' === $code || '@' === $code ) {
			return null;
		}
		$code = preg_replace( '/"[^"]*"/', '', $code );        // quoted literals
		$code = preg_replace( '/\\\\./', '', $code );          // escaped characters
		$code = preg_replace_callback(                         // [Red], [$-409], [>100]… but keep elapsed [h] [mm] [ss]
			'/\[([^\]]*)\]/',
			function ( $m ) {
				return preg_match( '/^[hms]+$/', $m[1] ) ? $m[1] : '';
			},
			$code
		);
		$code = str_replace( array( 'am/pm', 'a/p' ), '', $code );

		$has_ymd = ( false !== strpos( $code, 'y' ) || false !== strpos( $code, 'd' ) );
		$has_hs  = ( false !== strpos( $code, 'h' ) || false !== strpos( $code, 's' ) );
		$has_m   = ( false !== strpos( $code, 'm' ) );

		if ( $has_ymd && $has_hs ) {
			return 'datetime';
		}
		if ( $has_ymd ) {
			return 'date';
		}
		if ( $has_hs ) {
			return 'time';
		}
		return $has_m ? 'date' : null; // "mmm" / "mmmm": month names
	}

	/**
	 * Kind of a built-in number format id.
	 *
	 * @param int $id
	 * @return string|null
	 */
	public static function builtin_format_kind( $id ) {
		$id = (int) $id;
		if ( ( $id >= 14 && $id <= 17 ) || ( $id >= 27 && $id <= 36 ) || ( $id >= 50 && $id <= 58 ) ) {
			return 'date';
		}
		if ( ( $id >= 18 && $id <= 21 ) || ( $id >= 45 && $id <= 47 ) ) {
			return 'time';
		}
		if ( 22 === $id ) {
			return 'datetime';
		}
		return null;
	}

	/* ------------------------------------------------------------------ */
	/* Internals                                                           */
	/* ------------------------------------------------------------------ */

	/**
	 * Record an error and return false.
	 *
	 * @param string $message
	 * @return bool
	 */
	private function fail( $message ) {
		$this->last_error = $message;
		return false;
	}

	/**
	 * @param string $name
	 * @return bool
	 */
	private function is_refused( $name ) {
		$name = trim( (string) $name );
		foreach ( $this->refused as $refused ) {
			if ( 0 === strcasecmp( $name, $refused ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Exact, then case-insensitive, sheet name lookup.
	 *
	 * @param string $name
	 * @return string|null
	 */
	private function resolve_sheet_name( $name ) {
		if ( ! is_string( $name ) && ! is_numeric( $name ) ) {
			return null;
		}
		$name = (string) $name;
		if ( $this->is_refused( $name ) ) {
			return null;
		}
		if ( isset( $this->sheets[ $name ] ) ) {
			return $name;
		}
		$key = strtolower( trim( $name ) );
		return isset( $this->sheet_index[ $key ] ) ? $this->sheet_index[ $key ] : null;
	}

	/**
	 * @param array    $rows
	 * @param int|null $max_row
	 * @return array
	 */
	private function limit_rows( $rows, $max_row ) {
		if ( null === $max_row ) {
			return $rows;
		}
		$out = array();
		foreach ( $rows as $r => $cells ) {
			if ( $r <= $max_row ) {
				$out[ $r ] = $cells;
			}
		}
		return $out;
	}

	/**
	 * Zip-bomb guard: refuse oversized entries.
	 *
	 * @param string $entry
	 * @return bool
	 */
	private function entry_size_ok( $entry ) {
		$stat = $this->zip->statName( $entry );
		if ( false === $stat ) {
			return $this->fail( 'Missing part: ' . $entry );
		}
		if ( isset( $stat['size'] ) && $stat['size'] > $this->max_entry_bytes ) {
			return $this->fail( sprintf( 'Refusing part %s: %d bytes uncompressed exceeds the %d byte limit.', $entry, $stat['size'], $this->max_entry_bytes ) );
		}
		return true;
	}

	/**
	 * Read a (small) zip entry into a string.
	 *
	 * @param string $entry
	 * @return string|null
	 */
	private function read_entry( $entry ) {
		$index = $this->zip->locateName( $entry );
		if ( false === $index ) {
			$index = $this->zip->locateName( $entry, \ZipArchive::FL_NOCASE );
		}
		if ( false === $index ) {
			$this->fail( 'Missing part: ' . $entry );
			return null;
		}
		$stat = $this->zip->statIndex( $index );
		if ( false !== $stat && isset( $stat['size'] ) && $stat['size'] > $this->max_entry_bytes ) {
			$this->fail( sprintf( 'Refusing part %s: %d bytes uncompressed exceeds the %d byte limit.', $entry, $stat['size'], $this->max_entry_bytes ) );
			return null;
		}
		$data = $this->zip->getFromIndex( $index );
		if ( false === $data ) {
			$this->fail( 'Cannot read part: ' . $entry );
			return null;
		}
		return $data;
	}

	/**
	 * Parse a small XML part with SimpleXML (no network, no external entities).
	 *
	 * @param string $xml
	 * @return \SimpleXMLElement|null
	 */
	private function parse_xml( $xml ) {
		$previous = libxml_use_internal_errors( true );
		$loader   = null;
		if ( PHP_VERSION_ID < 80000 ) {
			$loader = libxml_disable_entity_loader( true );
		}
		$element = simplexml_load_string( $xml, 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOCDATA );
		if ( null !== $loader ) {
			libxml_disable_entity_loader( $loader );
		}
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );
		return ( false === $element ) ? null : $element;
	}

	/**
	 * Read a .rels part.
	 *
	 * @param string $entry    e.g. 'xl/_rels/workbook.xml.rels'.
	 * @param string $base_dir Directory the targets are relative to, e.g. 'xl/'.
	 * @return array Id => array( 'type' => ..., 'target' => zip entry ).
	 */
	private function read_relationships( $entry, $base_dir ) {
		$out = array();
		$xml = $this->read_entry( $entry );
		if ( null === $xml ) {
			return $out;
		}
		$rels = $this->parse_xml( $xml );
		if ( ! $rels ) {
			return $out;
		}
		foreach ( $rels->Relationship as $rel ) {
			$id = (string) $rel['Id'];
			if ( '' === $id ) {
				continue;
			}
			$mode = isset( $rel['TargetMode'] ) ? (string) $rel['TargetMode'] : '';
			if ( 'External' === $mode ) {
				continue;
			}
			$out[ $id ] = array(
				'type'   => (string) $rel['Type'],
				'target' => self::resolve_target( (string) $rel['Target'], $base_dir ),
			);
		}
		return $out;
	}

	/**
	 * Resolve a relationship target to a zip entry name.
	 *
	 * @param string $target
	 * @param string $base_dir
	 * @return string
	 */
	private static function resolve_target( $target, $base_dir ) {
		$target = str_replace( '\\', '/', trim( $target ) );
		$path   = ( '' !== $target && '/' === $target[0] ) ? substr( $target, 1 ) : $base_dir . $target;
		$parts  = array();
		foreach ( explode( '/', $path ) as $segment ) {
			if ( '' === $segment || '.' === $segment ) {
				continue;
			}
			if ( '..' === $segment ) {
				array_pop( $parts );
				continue;
			}
			$parts[] = $segment;
		}
		return implode( '/', $parts );
	}

	/**
	 * Open an XMLReader on a zip entry, streaming through the zip:// wrapper
	 * when possible and falling back to an in-memory string otherwise.
	 *
	 * @param string $entry
	 * @return \XMLReader|null
	 */
	private function open_reader( $entry ) {
		if ( ! $this->entry_size_ok( $entry ) ) {
			return null;
		}
		$reader = new \XMLReader();
		$opened = false;
		if ( false === strpos( $this->path, '#' ) ) {
			$opened = @$reader->open( 'zip://' . $this->path . '#' . $entry, null, LIBXML_NONET );
		}
		if ( ! $opened ) {
			$xml = $this->read_entry( $entry );
			if ( null === $xml ) {
				return null;
			}
			$reader = new \XMLReader();
			if ( ! @$reader->XML( $xml, null, LIBXML_NONET ) ) {
				$this->fail( 'Cannot parse part: ' . $entry );
				return null;
			}
		}
		$reader->setParserProperty( \XMLReader::SUBST_ENTITIES, false );
		$reader->setParserProperty( \XMLReader::LOADDTD, false );
		return $reader;
	}

	/**
	 * Whether a node type carries text.
	 *
	 * @param int $type
	 * @return bool
	 */
	private static function is_text_node( $type ) {
		return \XMLReader::TEXT === $type
			|| \XMLReader::CDATA === $type
			|| \XMLReader::SIGNIFICANT_WHITESPACE === $type
			|| \XMLReader::WHITESPACE === $type;
	}

	/**
	 * Collect the text of every <t> below the current element (which the
	 * reader is positioned on), skipping phonetic <rPh> runs. Leaves the
	 * reader on the element's end tag.
	 *
	 * @param \XMLReader $reader
	 * @return string
	 */
	private static function read_rich_text( $reader ) {
		if ( $reader->isEmptyElement ) {
			return '';
		}
		$depth     = $reader->depth;
		$text      = '';
		$in_t      = false;
		$rph_depth = null;
		while ( $reader->read() ) {
			$type = $reader->nodeType;
			if ( \XMLReader::END_ELEMENT === $type ) {
				if ( $reader->depth === $depth ) {
					break;
				}
				if ( 't' === $reader->localName ) {
					$in_t = false;
				} elseif ( null !== $rph_depth && 'rPh' === $reader->localName && $reader->depth === $rph_depth ) {
					$rph_depth = null;
				}
			} elseif ( \XMLReader::ELEMENT === $type ) {
				$local = $reader->localName;
				if ( 'rPh' === $local ) {
					if ( ! $reader->isEmptyElement ) {
						$rph_depth = $reader->depth;
					}
				} elseif ( 't' === $local && null === $rph_depth && ! $reader->isEmptyElement ) {
					$in_t = true;
				}
			} elseif ( $in_t && self::is_text_node( $type ) ) {
				$text .= $reader->value;
			}
		}
		return $text;
	}

	/**
	 * Load the shared strings table.
	 */
	private function load_shared_strings() {
		$this->strings = array();
		if ( false === $this->zip->locateName( $this->strings_entry ) ) {
			return;
		}
		$reader = $this->open_reader( $this->strings_entry );
		if ( null === $reader ) {
			return;
		}
		$previous = libxml_use_internal_errors( true );
		while ( $reader->read() ) {
			if ( \XMLReader::ELEMENT === $reader->nodeType && 'si' === $reader->localName ) {
				$this->strings[] = self::read_rich_text( $reader );
			}
		}
		$reader->close();
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );
	}

	/**
	 * Load the cellXfs => date-kind table from styles.xml.
	 */
	private function load_styles() {
		$this->style_kinds = array();
		if ( false === $this->zip->locateName( $this->styles_entry ) ) {
			return;
		}
		$xml = $this->read_entry( $this->styles_entry );
		if ( null === $xml ) {
			return;
		}
		$styles = $this->parse_xml( $xml );
		if ( ! $styles ) {
			return;
		}
		$custom = array();
		if ( isset( $styles->numFmts ) ) {
			foreach ( $styles->numFmts->numFmt as $fmt ) {
				$custom[ (int) $fmt['numFmtId'] ] = self::format_kind( (string) $fmt['formatCode'] );
			}
		}
		if ( isset( $styles->cellXfs ) ) {
			$i = 0;
			foreach ( $styles->cellXfs->xf as $xf ) {
				$id                      = isset( $xf['numFmtId'] ) ? (int) $xf['numFmtId'] : 0;
				$this->style_kinds[ $i ] = array_key_exists( $id, $custom ) ? $custom[ $id ] : self::builtin_format_kind( $id );
				$i++;
			}
		}
	}

	/**
	 * Date kind of a cell style index.
	 *
	 * @param string|null $s The cell's s attribute.
	 * @return string|null
	 */
	private function style_kind( $s ) {
		if ( null === $s || '' === $s ) {
			return null;
		}
		if ( null === $this->style_kinds ) {
			$this->load_styles();
		}
		$index = (int) $s;
		return isset( $this->style_kinds[ $index ] ) ? $this->style_kinds[ $index ] : null;
	}

	/**
	 * Stream a worksheet part into rows.
	 *
	 * @param string   $entry
	 * @param int|null $max_row
	 * @return array|null
	 */
	private function parse_sheet( $entry, $max_row ) {
		$reader = $this->open_reader( $entry );
		if ( null === $reader ) {
			return null;
		}
		$previous = libxml_use_internal_errors( true );
		$rows     = array();
		$row_num  = 0;
		$col_idx  = 0;
		$in_data  = false;

		while ( $reader->read() ) {
			$type = $reader->nodeType;
			if ( \XMLReader::ELEMENT !== $type ) {
				if ( \XMLReader::END_ELEMENT === $type && 'sheetData' === $reader->localName ) {
					break;
				}
				continue;
			}
			$local = $reader->localName;
			if ( 'sheetData' === $local ) {
				$in_data = ! $reader->isEmptyElement;
				continue;
			}
			if ( ! $in_data ) {
				continue;
			}
			if ( 'row' === $local ) {
				$r       = $reader->getAttribute( 'r' );
				$row_num = ( null !== $r && '' !== $r ) ? (int) $r : $row_num + 1;
				$col_idx = 0;
				if ( null !== $max_row && $row_num > $max_row ) {
					break;
				}
				continue;
			}
			if ( 'c' !== $local ) {
				continue;
			}

			$ref = $reader->getAttribute( 'r' );
			if ( null !== $ref && preg_match( '/^([A-Z]+)([0-9]+)$/', $ref, $m ) ) {
				$col_idx = self::col_index( $m[1] );
				if ( 0 === $row_num ) {
					$row_num = (int) $m[2];
				}
			} else {
				$col_idx++;
			}
			$t = $reader->getAttribute( 't' );
			$s = $reader->getAttribute( 's' );
			if ( $reader->isEmptyElement ) {
				continue;
			}

			// Walk the children of <c>: only a direct child <v> or <is> carries
			// the value; <f> (formula text) and <extLst> are ignored.
			$depth   = $reader->depth;
			$v       = null;
			$is_text = null;
			$in_v    = false;
			while ( $reader->read() ) {
				$ctype = $reader->nodeType;
				if ( \XMLReader::END_ELEMENT === $ctype ) {
					if ( $reader->depth === $depth ) {
						break;
					}
					$in_v = false;
				} elseif ( \XMLReader::ELEMENT === $ctype ) {
					if ( $reader->depth !== $depth + 1 ) {
						continue;
					}
					$clocal = $reader->localName;
					if ( 'v' === $clocal ) {
						$v    = '';
						$in_v = ! $reader->isEmptyElement;
					} elseif ( 'is' === $clocal ) {
						$is_text = self::read_rich_text( $reader );
					}
				} elseif ( $in_v && self::is_text_node( $ctype ) ) {
					$v .= $reader->value;
				}
			}

			$value = $this->cell_value( $t, $s, $v, $is_text );
			if ( null !== $value && $row_num > 0 && $col_idx > 0 ) {
				$rows[ $row_num ][ self::col_letter( $col_idx ) ] = $value;
			}
		}
		$reader->close();
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );
		return $rows;
	}

	/**
	 * Convert the raw parts of a <c> element into a PHP value.
	 *
	 * @param string|null $t       Cell type attribute.
	 * @param string|null $s       Style index attribute.
	 * @param string|null $v       Text of <v>, null when absent.
	 * @param string|null $is_text Text of <is>, null when absent.
	 * @return mixed
	 */
	private function cell_value( $t, $s, $v, $is_text ) {
		$t = ( null === $t ) ? 'n' : $t;
		switch ( $t ) {
			case 's':
				if ( null === $v || '' === trim( $v ) ) {
					return null;
				}
				if ( null === $this->strings ) {
					$this->load_shared_strings();
				}
				$index = (int) trim( $v );
				return isset( $this->strings[ $index ] ) ? $this->strings[ $index ] : '';

			case 'inlineStr':
				return ( null === $is_text ) ? ( null === $v ? null : (string) $v ) : $is_text;

			case 'b':
				if ( null === $v ) {
					return null;
				}
				$flag = strtolower( trim( $v ) );
				return ( '1' === $flag || 'true' === $flag );

			case 'e':
			case 'str':
			case 'd':
				return ( null === $v ) ? null : (string) $v;

			default:
				if ( null === $v ) {
					return null;
				}
				$text = trim( $v );
				if ( '' === $text ) {
					return null;
				}
				if ( ! is_numeric( $text ) ) {
					return (string) $v;
				}
				$number = self::to_number( $text );
				$kind   = $this->style_kind( $s );
				if ( null !== $kind && $number >= 0 ) {
					if ( 'date' === $kind ) {
						return self::serial_to_date( $number, $this->date1904 );
					}
					if ( 'time' === $kind ) {
						return self::serial_to_time( $number );
					}
					return self::serial_to_datetime( $number, $this->date1904 );
				}
				return $number;
		}
	}

	/**
	 * Numeric string => int when integral and in range, else float.
	 *
	 * @param string $text
	 * @return int|float
	 */
	private static function to_number( $text ) {
		if ( preg_match( '/^-?[0-9]{1,18}$/', $text ) ) {
			return (int) $text;
		}
		$float = (float) $text;
		if ( floor( $float ) === $float && abs( $float ) < 9.0e15 ) {
			return (int) $float;
		}
		return $float;
	}

	/**
	 * @param string $haystack
	 * @param string $needle
	 * @return bool
	 */
	private static function ends_with( $haystack, $needle ) {
		$len = strlen( $needle );
		return 0 === $len || substr( $haystack, -$len ) === $needle;
	}

	/**
	 * Human-readable ZipArchive::open() error.
	 *
	 * @param int $code
	 * @return string
	 */
	private static function zip_error_message( $code ) {
		$messages = array(
			\ZipArchive::ER_EXISTS => 'file already exists',
			\ZipArchive::ER_INCONS => 'zip archive inconsistent',
			\ZipArchive::ER_INVAL  => 'invalid argument',
			\ZipArchive::ER_MEMORY => 'out of memory',
			\ZipArchive::ER_NOENT  => 'no such file',
			\ZipArchive::ER_NOZIP  => 'not a zip archive',
			\ZipArchive::ER_OPEN   => 'cannot open file',
			\ZipArchive::ER_READ   => 'read error',
			\ZipArchive::ER_SEEK   => 'seek error',
		);
		return isset( $messages[ $code ] ) ? $messages[ $code ] : 'error ' . (string) $code;
	}
}
