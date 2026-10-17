<?php
/**
 * Maps the FisHotel-YEAR.xlsm workbook layout onto plain PHP arrays.
 *
 * Only the sheets it knows by name are opened: Fishlist, Data,
 * FishSheetStock, Recieved (N) and QT Tanks (N). Columns are found by
 * header text, never by letter, and the right-hand label block of a batch
 * sheet is found by label text so a row or column shift does not matter.
 *
 * @package FisHotel\Misc\Sections\Clean_Room
 */

namespace FisHotel\Misc\Sections\Clean_Room;

defined( 'ABSPATH' ) || exit;

class Import_Map {

	const FALLBACK_YEAR = 2026;

	const SHEET_SPECIES  = 'Fishlist';
	const SHEET_DATA     = 'Data';
	const SHEET_STOCK    = 'FishSheetStock';
	const BATCH_TEMPLATE = 'Recieved';
	const BATCH_PATTERN  = '/^Recieved \((\d+)\)$/i';
	const QT_TEMPLATE    = 'QT Tanks';
	const QT_PATTERN     = '/^QT Tanks \((\d+)\)$/i';

	/** Rows searched for the header row of a batch / QT sheet. */
	const HEADER_SEARCH_ROWS = 10;

	/** Rows searched for the right-hand label block of a batch sheet. */
	const LABEL_SEARCH_ROWS = 60;

	/** Batch sheet column headers (normalised) => output key. First match from the left wins. */
	private static $batch_headers = array(
		'name'         => array( 'name' ),
		'sci_name'     => array( 'sci name' ),
		'qty_ordered'  => array( 'order' ),
		'per_box'      => array( 'per box' ),
		'bags'         => array( 'bags' ),
		'qty_received' => array( "rcv'd" ),
		'unit_price'   => array( 'price' ),
		'total'        => array( 'total' ),
		'worth'        => array( 'worth $' ),
		'qty_survived' => array( "srv'd" ),
		'qty_doa'      => array( 'doa' ),
	);

	/** Species sheet headers. */
	private static $species_headers = array(
		'name'         => array( 'fish' ),
		'sci_name'     => array( 'scientific name' ),
		'my_price'     => array( 'mine' ),
		'online_price' => array( 'online' ),
		'online_site'  => array( 'site' ),
		'size'         => array( 'size' ),
		'online_date'  => array( 'date' ),
		'per_box'      => array( 'per box' ),
	);

	/** Stock sheet headers. */
	private static $stock_headers = array(
		'group'       => array( 'group' ),
		'common_name' => array( 'common name' ),
		'sci_name'    => array( 'taxonomy', 'scientific name' ),
		'reef_safe'   => array( 'reef safe?', 'reef safe' ),
		'max_size'    => array( 'max size' ),
	);

	/** QT log headers. */
	private static $qt_headers = array(
		'process' => array( 'process' ),
		'amount'  => array( '@', 'amount' ),
		'system'  => array( 'tanks', 'system' ),
		'date'    => array( 'date' ),
		'time'    => array( 'time' ),
		'result'  => array( 'results', 'result' ),
	);

	/** Row-2 labels of a batch sheet (normalised, exact or prefix) => output key. */
	private static $batch_row2_labels = array(
		'sheet_total'          => array( 'total:' ),
		'tariffs'              => array( 'tariffs:', 'tariff:', 'tarrifs:' ),
		'domestic_flight'      => array( 'domestic flight:', 'domestic flight' ),
		'import_fees'          => array( 'import & reox fees:', 'import & re-ox fees:', 'import and reox fees:', 'import fees:' ),
		'sheet_total_critters' => array( 'total critters', 'total critters:' ),
	);

	/** Right-hand block labels of a batch sheet. */
	private static $batch_block_labels = array(
		'from'   => array( 'from:', 'from' ),
		'credit' => array( 'credit', 'credit:' ),
	);

	/* ------------------------------------------------------------------ */
	/* Public API                                                          */
	/* ------------------------------------------------------------------ */

	/**
	 * Map an open workbook.
	 *
	 * @param Workbook $wb           Open workbook.
	 * @param int|null $default_year Year for the order ref when a batch has no QT start date.
	 *                               Defaults to a 4-digit year in the file name, else 2026.
	 * @return array species[], orders[], box_rates[], suppliers[], origins[], stock[], warnings[].
	 */
	public static function map( Workbook $wb, $default_year = null ) {
		$out = array(
			'species'   => array(),
			'orders'    => array(),
			'box_rates' => array(),
			'suppliers' => array(),
			'origins'   => array(),
			'stock'     => array(),
			'warnings'  => array(),
		);
		if ( ! $wb->is_open() ) {
			$out['warnings'][] = 'No workbook is open.';
			return $out;
		}
		$year = self::default_year( $wb, $default_year );

		self::map_species( $wb, $out );
		self::map_data( $wb, $out );
		self::map_stock( $wb, $out );
		self::map_orders( $wb, $out, $year );

		return $out;
	}

	/**
	 * Batch sheets present: batch number => sheet name, sorted by number.
	 *
	 * @param Workbook $wb
	 * @return array
	 */
	public static function batch_sheets( Workbook $wb ) {
		return self::numbered_sheets( $wb, self::BATCH_PATTERN );
	}

	/**
	 * QT Tanks sheets present: batch number => sheet name, sorted by number.
	 *
	 * @param Workbook $wb
	 * @return array
	 */
	public static function qt_sheets( Workbook $wb ) {
		return self::numbered_sheets( $wb, self::QT_PATTERN );
	}

	/**
	 * Read one QT Tanks sheet: start date, schedule name and the treatment log.
	 *
	 * @param Workbook $wb
	 * @param string   $sheet    Sheet name, e.g. 'QT Tanks (3)'.
	 * @param array    $warnings Appended to.
	 * @return array|null array( 'start_date' => 'Y-m-d'|null, 'schedule' => string|null, 'log' => array ), null when unreadable.
	 */
	public static function read_qt( Workbook $wb, $sheet, &$warnings ) {
		$rows = $wb->rows( $sheet );
		if ( null === $rows ) {
			$warnings[] = $sheet . ': could not be read (' . $wb->last_error() . ').';
			return null;
		}
		ksort( $rows );
		$date1904 = $wb->is_date1904();

		// Start date (I2) and schedule (I4): by label when the labels exist, else by position.
		$start_raw    = self::cell_at( $rows, 2, 'I' );
		$schedule_raw = self::cell_at( $rows, 4, 'I' );
		$start_label  = self::find_label( $rows, 1, 6, array( 'start date:', 'start date' ) );
		if ( null !== $start_label ) {
			$start_raw = self::cell_right_of( $rows, $start_label[0], $start_label[1], 1 );
		}
		$schedule_label = self::find_label( $rows, 1, 6, array( 'schedule:', 'schedule' ) );
		if ( null !== $schedule_label ) {
			$schedule_raw = self::cell_right_of( $rows, $schedule_label[0], $schedule_label[1], 1 );
		}
		$start_date = self::to_ymd( $start_raw, $date1904 );
		if ( null === $start_date && null !== self::text( $start_raw ) ) {
			$warnings[] = $sheet . ': start date "' . self::text( $start_raw ) . '" is not a date.';
		}
		$schedule = self::text( $schedule_raw );

		// Log header row (K4:P4 normally).
		$header_row = null;
		$cols       = array();
		for ( $r = 1; $r <= self::HEADER_SEARCH_ROWS; $r++ ) {
			if ( ! isset( $rows[ $r ] ) ) {
				continue;
			}
			$found = self::find_headers( $rows[ $r ], self::$qt_headers );
			if ( isset( $found['process'] ) && isset( $found['date'] ) ) {
				$header_row = $r;
				$cols       = $found;
				break;
			}
		}
		if ( null === $header_row ) {
			$warnings[] = $sheet . ': log header row (Process … Results) not found, using K4:P4.';
			$header_row = 4;
			$cols       = array(
				'process' => 'K',
				'amount'  => 'L',
				'system'  => 'M',
				'date'    => 'N',
				'time'    => 'O',
				'result'  => 'P',
			);
		} else {
			foreach ( array_keys( self::$qt_headers ) as $key ) {
				if ( ! isset( $cols[ $key ] ) ) {
					$warnings[] = $sheet . ': log header "' . $key . '" not found.';
				}
			}
		}

		$log     = array();
		$max_row = empty( $rows ) ? 0 : max( array_keys( $rows ) );
		for ( $r = $header_row + 1; $r <= $max_row; $r++ ) {
			$process = self::text( self::cell_at( $rows, $r, self::col_of( $cols, 'process' ) ) );
			if ( null === $process ) {
				break; // the log is contiguous
			}
			if ( preg_match( '/^(20|40)\s*Gallon/i', $process ) ) {
				continue; // tank label, not a log row
			}
			$log[] = array(
				'process' => $process,
				'amount'  => self::scalar( self::cell_at( $rows, $r, self::col_of( $cols, 'amount' ) ) ),
				'system'  => self::scalar( self::cell_at( $rows, $r, self::col_of( $cols, 'system' ) ) ),
				'date'    => self::to_ymd( self::cell_at( $rows, $r, self::col_of( $cols, 'date' ) ), $date1904 ),
				'time'    => self::to_hm( self::cell_at( $rows, $r, self::col_of( $cols, 'time' ) ) ),
				'result'  => self::text( self::cell_at( $rows, $r, self::col_of( $cols, 'result' ) ) ),
			);
		}

		return array(
			'start_date' => $start_date,
			'schedule'   => $schedule,
			'log'        => $log,
		);
	}

	/* ------------------------------------------------------------------ */
	/* Species (Fishlist)                                                  */
	/* ------------------------------------------------------------------ */

	/**
	 * @param Workbook $wb
	 * @param array    $out
	 */
	private static function map_species( Workbook $wb, &$out ) {
		if ( ! $wb->has_sheet( self::SHEET_SPECIES ) ) {
			$out['warnings'][] = 'Sheet "' . self::SHEET_SPECIES . '" not found: no species mapped.';
			return;
		}
		$rows = $wb->rows( self::SHEET_SPECIES );
		if ( null === $rows ) {
			$out['warnings'][] = self::SHEET_SPECIES . ': could not be read (' . $wb->last_error() . ').';
			return;
		}
		ksort( $rows );
		$date1904 = $wb->is_date1904();

		$cols = isset( $rows[1] ) ? self::find_headers( $rows[1], self::$species_headers ) : array();
		if ( ! isset( $cols['name'] ) ) {
			$out['warnings'][] = self::SHEET_SPECIES . ': header "Fish" not found in row 1, using column A.';
			$cols['name'] = 'A';
		}
		foreach ( array_keys( self::$species_headers ) as $key ) {
			if ( ! isset( $cols[ $key ] ) && 'name' !== $key ) {
				$out['warnings'][] = self::SHEET_SPECIES . ': header for "' . $key . '" not found in row 1; the field is left null.';
			}
		}

		$max_row = empty( $rows ) ? 0 : max( array_keys( $rows ) );
		for ( $r = 2; $r <= $max_row; $r++ ) {
			$name = self::text( self::cell_at( $rows, $r, $cols['name'] ) );
			if ( null === $name ) {
				break; // rows 2… until the name column is blank
			}
			$out['species'][] = array(
				'name'         => $name,
				'sci_name'     => self::text( self::cell_at( $rows, $r, self::col_of( $cols, 'sci_name' ) ) ),
				'my_price'     => self::num( self::cell_at( $rows, $r, self::col_of( $cols, 'my_price' ) ) ),
				'online_price' => self::num( self::cell_at( $rows, $r, self::col_of( $cols, 'online_price' ) ) ),
				'online_site'  => self::text( self::cell_at( $rows, $r, self::col_of( $cols, 'online_site' ) ) ),
				'online_date'  => self::to_ymd( self::cell_at( $rows, $r, self::col_of( $cols, 'online_date' ) ), $date1904 ),
				'per_box'      => self::qty( self::cell_at( $rows, $r, self::col_of( $cols, 'per_box' ) ) ),
				'size'         => self::text( self::cell_at( $rows, $r, self::col_of( $cols, 'size' ) ) ),
			);
		}
		if ( empty( $out['species'] ) ) {
			$out['warnings'][] = self::SHEET_SPECIES . ': no species rows found.';
		}
	}

	/* ------------------------------------------------------------------ */
	/* Data (suppliers, origins, box rates)                                */
	/* ------------------------------------------------------------------ */

	/**
	 * @param Workbook $wb
	 * @param array    $out
	 */
	private static function map_data( Workbook $wb, &$out ) {
		if ( ! $wb->has_sheet( self::SHEET_DATA ) ) {
			$out['warnings'][] = 'Sheet "' . self::SHEET_DATA . '" not found: no suppliers, origins or box rates mapped.';
			return;
		}
		$rows = $wb->rows( self::SHEET_DATA );
		if ( null === $rows ) {
			$out['warnings'][] = self::SHEET_DATA . ': could not be read (' . $wb->last_error() . ').';
			return;
		}
		ksort( $rows );
		$max_row = empty( $rows ) ? 0 : max( array_keys( $rows ) );

		// Suppliers: the column under the "Vendor" header.
		$vendor = self::find_label( $rows, 1, 3, array( 'vendor', 'vendors' ) );
		if ( null === $vendor ) {
			$out['warnings'][] = self::SHEET_DATA . ': header "Vendor" not found, using D2 down.';
			$vendor = array( 1, 'D' );
		}
		for ( $r = $vendor[0] + 1; $r <= $max_row; $r++ ) {
			$name = self::text( self::cell_at( $rows, $r, $vendor[1] ) );
			if ( null === $name ) {
				break;
			}
			$out['suppliers'][] = $name;
		}

		// Box rates: the "Location · Per Box · Re-Ox" header block(s); the
		// supplier is the lone text cell above each block.
		$rate_header = null;
		$origin_cols = array();
		for ( $r = 1; $r <= 3 && isset( $rows[ $r ] ); $r++ ) {
			foreach ( self::sorted_cells( $rows[ $r ] ) as $pair ) {
				if ( 'location' !== self::norm( $pair[1] ) ) {
					continue;
				}
				$index     = Workbook::col_index( $pair[0] );
				$next_norm = self::norm( self::cell_at( $rows, $r, Workbook::col_letter( $index + 1 ) ) );
				if ( 'per box' === $next_norm ) {
					if ( null === $rate_header ) {
						$rate_header = array( $r, $pair[0] );
					}
				} else {
					$origin_cols[] = array( $r, $pair[0] );
				}
			}
		}
		if ( null === $rate_header ) {
			$out['warnings'][] = self::SHEET_DATA . ': box-rate header "Location · Per Box · Re-Ox" not found, using F:H.';
			$rate_header = array( 2, 'F' );
		}
		$origin_col  = $rate_header[1];
		$per_box_col = Workbook::col_letter( Workbook::col_index( $origin_col ) + 1 );
		$reox_col    = Workbook::col_letter( Workbook::col_index( $origin_col ) + 2 );
		$supplier    = null;
		for ( $r = 1; $r <= $max_row; $r++ ) {
			$origin = self::text( self::cell_at( $rows, $r, $origin_col ) );
			if ( null === $origin ) {
				continue;
			}
			$per_box_raw = self::cell_at( $rows, $r, $per_box_col );
			$reox_raw    = self::cell_at( $rows, $r, $reox_col );
			if ( 'location' === self::norm( $origin ) && 'per box' === self::norm( $per_box_raw ) ) {
				continue; // a header row
			}
			$per_box = self::num( $per_box_raw );
			$reox    = self::num( $reox_raw );
			if ( null === $per_box && null === $reox && null === self::text( $per_box_raw ) && null === self::text( $reox_raw ) ) {
				$supplier = $origin; // a supplier name row
				continue;
			}
			if ( null === $supplier ) {
				$out['warnings'][] = self::SHEET_DATA . ' row ' . $r . ': box rate "' . $origin . '" has no supplier name above it.';
			}
			if ( null === $per_box ) {
				$out['warnings'][] = self::SHEET_DATA . ' row ' . $r . ': box rate "' . $origin . '" has no numeric Per Box value.';
			}
			$out['box_rates'][] = array(
				'supplier' => $supplier,
				'origin'   => $origin,
				'per_box'  => $per_box,
				'reox'     => $reox,
			);
		}

		// Origins: the rightmost "Location" header that is not a box-rate header.
		if ( empty( $origin_cols ) ) {
			$out['warnings'][] = self::SHEET_DATA . ': origins header "Location" not found, using J3 down.';
			$origin_cols[] = array( 2, 'J' );
		}
		$origin_header = end( $origin_cols );
		for ( $r = $origin_header[0] + 1; $r <= $max_row; $r++ ) {
			$origin = self::text( self::cell_at( $rows, $r, $origin_header[1] ) );
			if ( null === $origin ) {
				break;
			}
			$out['origins'][] = $origin;
		}
	}

	/* ------------------------------------------------------------------ */
	/* Stock (FishSheetStock)                                              */
	/* ------------------------------------------------------------------ */

	/**
	 * @param Workbook $wb
	 * @param array    $out
	 */
	private static function map_stock( Workbook $wb, &$out ) {
		if ( ! $wb->has_sheet( self::SHEET_STOCK ) ) {
			$out['warnings'][] = 'Sheet "' . self::SHEET_STOCK . '" not found: no stock mapped.';
			return;
		}
		$rows = $wb->rows( self::SHEET_STOCK );
		if ( null === $rows ) {
			$out['warnings'][] = self::SHEET_STOCK . ': could not be read (' . $wb->last_error() . ').';
			return;
		}
		ksort( $rows );

		$cols = isset( $rows[1] ) ? self::find_headers( $rows[1], self::$stock_headers ) : array();
		if ( ! isset( $cols['common_name'] ) && ! isset( $cols['sci_name'] ) ) {
			$out['warnings'][] = self::SHEET_STOCK . ': headers "Common Name" / "Taxonomy" not found in row 1: no stock mapped.';
			return;
		}
		foreach ( array_keys( self::$stock_headers ) as $key ) {
			if ( ! isset( $cols[ $key ] ) ) {
				$out['warnings'][] = self::SHEET_STOCK . ': header for "' . $key . '" not found in row 1; the field is left null.';
			}
		}

		$max_row = empty( $rows ) ? 0 : max( array_keys( $rows ) );
		for ( $r = 2; $r <= $max_row; $r++ ) {
			$common = self::text( self::cell_at( $rows, $r, self::col_of( $cols, 'common_name' ) ) );
			$sci    = self::text( self::cell_at( $rows, $r, self::col_of( $cols, 'sci_name' ) ) );
			if ( null === $common && null === $sci ) {
				continue;
			}
			$out['stock'][] = array(
				'group'       => self::text( self::cell_at( $rows, $r, self::col_of( $cols, 'group' ) ) ),
				'common_name' => $common,
				'sci_name'    => $sci,
				'reef_safe'   => self::text( self::cell_at( $rows, $r, self::col_of( $cols, 'reef_safe' ) ) ),
				'max_size'    => self::text( self::cell_at( $rows, $r, self::col_of( $cols, 'max_size' ) ) ),
			);
		}
	}

	/* ------------------------------------------------------------------ */
	/* Orders (Recieved (N) + QT Tanks (N))                                */
	/* ------------------------------------------------------------------ */

	/**
	 * @param Workbook $wb
	 * @param array    $out
	 * @param int      $year
	 */
	private static function map_orders( Workbook $wb, &$out, $year ) {
		$species_index = array();
		foreach ( $out['species'] as $species ) {
			$species_index[ self::norm( $species['name'] ) ] = true;
		}
		$supplier_index = array();
		foreach ( $out['suppliers'] as $supplier ) {
			$supplier_index[ self::norm( $supplier ) ] = true;
		}
		$origin_index = array();
		foreach ( $out['origins'] as $origin ) {
			$origin_index[ self::norm( $origin ) ] = true;
		}

		foreach ( $wb->sheet_names() as $name ) {
			if ( 0 === strcasecmp( trim( $name ), self::BATCH_TEMPLATE ) ) {
				$out['warnings'][] = '"' . $name . '" skipped: it is the empty batch template.';
			}
		}

		foreach ( self::batch_sheets( $wb ) as $batch_no => $sheet ) {
			$order = self::map_batch( $wb, $sheet, $batch_no, $year, $species_index, $supplier_index, $origin_index, $out['warnings'] );
			if ( null !== $order ) {
				$out['orders'][] = $order;
			}
		}
	}

	/**
	 * Map one batch sheet (and its QT Tanks sheet).
	 *
	 * @param Workbook $wb
	 * @param string   $sheet
	 * @param int      $batch_no
	 * @param int      $year
	 * @param array    $species_index
	 * @param array    $supplier_index
	 * @param array    $origin_index
	 * @param array    $warnings
	 * @return array|null Null when the batch is skipped (a warning says why).
	 */
	private static function map_batch( Workbook $wb, $sheet, $batch_no, $year, $species_index, $supplier_index, $origin_index, &$warnings ) {
		$rows = $wb->rows( $sheet );
		if ( null === $rows ) {
			$warnings[] = $sheet . ' skipped: could not be read (' . $wb->last_error() . ').';
			return null;
		}
		ksort( $rows );

		// Header row: the first row holding both "Name" and "Rcv'd".
		$header_row = null;
		$cols       = array();
		for ( $r = 1; $r <= self::HEADER_SEARCH_ROWS; $r++ ) {
			if ( ! isset( $rows[ $r ] ) ) {
				continue;
			}
			$found = self::find_headers( $rows[ $r ], self::$batch_headers );
			if ( isset( $found['name'] ) && isset( $found['qty_received'] ) ) {
				$header_row = $r;
				$cols       = $found;
				break;
			}
		}
		if ( null === $header_row ) {
			$warnings[] = $sheet . ' skipped: header row (Name … Rcv\'d) not found in rows 1-' . self::HEADER_SEARCH_ROWS . '.';
			return null;
		}

		// Lines: every row below the header with a non-empty Name.
		$lines = array();
		foreach ( $rows as $r => $cells ) {
			if ( $r <= $header_row ) {
				continue;
			}
			$name = self::text( self::cell_at( $rows, $r, $cols['name'] ) );
			if ( null === $name ) {
				continue;
			}
			$lines[] = array(
				'row'          => $r,
				'name'         => $name,
				'sci_name'     => self::sci_name( self::cell_at( $rows, $r, self::col_of( $cols, 'sci_name' ) ) ),
				'qty_ordered'  => self::qty( self::cell_at( $rows, $r, self::col_of( $cols, 'qty_ordered' ) ) ),
				'per_box'      => self::qty( self::cell_at( $rows, $r, self::col_of( $cols, 'per_box' ) ) ),
				'bags'         => self::qty( self::cell_at( $rows, $r, self::col_of( $cols, 'bags' ) ) ),
				'qty_received' => self::qty( self::cell_at( $rows, $r, self::col_of( $cols, 'qty_received' ) ) ),
				'unit_price'   => self::num( self::cell_at( $rows, $r, self::col_of( $cols, 'unit_price' ) ) ),
				'worth'        => self::num( self::cell_at( $rows, $r, self::col_of( $cols, 'worth' ) ) ),
				'qty_survived' => self::qty( self::cell_at( $rows, $r, self::col_of( $cols, 'qty_survived' ) ) ),
				'qty_doa'      => self::qty( self::cell_at( $rows, $r, self::col_of( $cols, 'qty_doa' ) ) ),
				'sheet_total'  => self::num( self::cell_at( $rows, $r, self::col_of( $cols, 'total' ) ) ),
			);
		}
		if ( empty( $lines ) ) {
			$warnings[] = $sheet . ' skipped: no line rows (no row below the header has a Name).';
			return null;
		}

		// Header warnings only for batches that are kept.
		foreach ( self::$batch_headers as $key => $texts ) {
			if ( isset( $cols[ $key ] ) || 'per_box' === $key || 'bags' === $key ) {
				continue;
			}
			$warnings[] = $sheet . ': header "' . $texts[0] . '" not found in row ' . $header_row . '; the field is left null.';
		}

		// Cost mode from the Per Box / Bags header.
		if ( isset( $cols['per_box'] ) && isset( $cols['bags'] ) ) {
			$cost_mode  = ( Workbook::col_index( $cols['per_box'] ) <= Workbook::col_index( $cols['bags'] ) ) ? 'box' : 'bag';
			$warnings[] = $sheet . ': both "Per Box" and "Bags" headers found; using the leftmost (' . $cost_mode . ' mode).';
		} elseif ( isset( $cols['per_box'] ) ) {
			$cost_mode = 'box';
		} elseif ( isset( $cols['bags'] ) ) {
			$cost_mode = 'bag';
		} else {
			$cost_mode  = 'bag';
			$warnings[] = $sheet . ': neither "Per Box" nor "Bags" header found; assuming bag mode.';
		}

		// Line-level checks.
		foreach ( $lines as $line ) {
			$where = $sheet . ' row ' . $line['row'];
			if ( ! isset( $species_index[ self::norm( $line['name'] ) ] ) ) {
				$warnings[] = $where . ': species "' . $line['name'] . '" is not in ' . self::SHEET_SPECIES . '.';
			}
			$received = (float) $line['qty_received'];
			if ( $received > 0 && null === $line['unit_price'] ) {
				$warnings[] = $where . ': ' . $line['qty_received'] . ' received but no price.';
			}
			if ( null === $line['sheet_total'] ) {
				$raw = self::raw_text( self::cell_at( $rows, $line['row'], self::col_of( $cols, 'total' ) ) );
				if ( null !== $raw || $received > 0 ) {
					$warnings[] = $where . ': cached Total is not a number (' . ( null === $raw ? 'blank' : $raw ) . ').';
				}
			}
		}

		// Row-2 totals and fees: the first numeric cell right of each label.
		$row2 = array(
			'sheet_total'          => null,
			'tariffs'              => null,
			'domestic_flight'      => null,
			'import_fees'          => null,
			'sheet_total_critters' => null,
		);
		$totals_row = null;
		for ( $r = 1; $r < $header_row; $r++ ) {
			if ( isset( $rows[ $r ] ) && null !== self::find_headers_in_row( $rows[ $r ], array( 'total:' ) ) ) {
				$totals_row = $r;
				break;
			}
		}
		if ( null === $totals_row ) {
			$warnings[] = $sheet . ': "Total:" label not found above the header row; totals and fees are left null.';
		} else {
			$cells = self::sorted_cells( $rows[ $totals_row ] );
			$count = count( $cells );
			for ( $i = 0; $i < $count; $i++ ) {
				$key = self::match_label( self::norm( $cells[ $i ][1] ), self::$batch_row2_labels );
				if ( null === $key ) {
					continue;
				}
				for ( $j = $i + 1; $j < $count; $j++ ) {
					if ( is_string( $cells[ $j ][1] ) && self::is_label_text( $cells[ $j ][1] ) ) {
						break;
					}
					$number = self::num( $cells[ $j ][1] );
					if ( null !== $number ) {
						$row2[ $key ] = $number;
						break;
					}
				}
			}
		}

		// Right-hand label block: searched by text right of the first DOA column.
		$supplier = null;
		$origin   = null;
		$credit   = null;
		$min_col  = isset( $cols['qty_doa'] ) ? Workbook::col_index( $cols['qty_doa'] ) : ( isset( $cols['qty_survived'] ) ? Workbook::col_index( $cols['qty_survived'] ) : 0 );
		$labels   = array();
		for ( $r = 1; $r <= self::LABEL_SEARCH_ROWS; $r++ ) {
			if ( ! isset( $rows[ $r ] ) ) {
				continue;
			}
			foreach ( self::sorted_cells( $rows[ $r ] ) as $pair ) {
				if ( ! is_string( $pair[1] ) || Workbook::col_index( $pair[0] ) <= $min_col ) {
					continue;
				}
				$key = self::match_label( self::norm( $pair[1] ), self::$batch_block_labels );
				if ( null !== $key && ! isset( $labels[ $key ] ) ) {
					$labels[ $key ] = array( $r, $pair[0] );
				}
			}
		}
		if ( isset( $labels['from'] ) ) {
			$origin   = self::text( self::cell_right_of( $rows, $labels['from'][0], $labels['from'][1], 1 ) );
			$supplier = self::text( self::cell_right_of( $rows, $labels['from'][0], $labels['from'][1], 5 ) );
			if ( null !== $supplier && ! empty( $supplier_index ) && ! isset( $supplier_index[ self::norm( $supplier ) ] ) ) {
				$warnings[] = $sheet . ': supplier "' . $supplier . '" is not in the ' . self::SHEET_DATA . ' vendor list.';
			}
			if ( null !== $origin && ! empty( $origin_index ) && ! isset( $origin_index[ self::norm( $origin ) ] ) ) {
				$warnings[] = $sheet . ': origin "' . $origin . '" is not in the ' . self::SHEET_DATA . ' location list.';
			}
		} else {
			$warnings[] = $sheet . ': "From:" label not found; supplier and origin are left null.';
		}
		if ( isset( $labels['credit'] ) ) {
			$credit = self::num( self::cell_at( $rows, $labels['credit'][0] + 2, $labels['credit'][1] ) );
		} else {
			$warnings[] = $sheet . ': "Credit" label not found; credit is left null.';
		}

		// Paired QT Tanks sheet.
		$qt_sheet = 'QT Tanks (' . $batch_no . ')';
		$qt       = null;
		if ( $wb->has_sheet( $qt_sheet ) ) {
			$qt = self::read_qt( $wb, $qt_sheet, $warnings );
		} else {
			$warnings[] = $sheet . ': no paired "' . $qt_sheet . '" sheet; received date, schedule and log are empty.';
		}
		$received_date = ( null !== $qt ) ? $qt['start_date'] : null;
		$ref_year      = ( null !== $received_date ) ? (int) substr( $received_date, 0, 4 ) : $year;

		return array(
			'sheet'                => $sheet,
			'batch_no'             => $batch_no,
			'ref'                  => $ref_year . '-' . $batch_no,
			'supplier'             => $supplier,
			'origin'               => $origin,
			'cost_mode'            => $cost_mode,
			'tariffs'              => $row2['tariffs'],
			'domestic_flight'      => $row2['domestic_flight'],
			'import_fees'          => $row2['import_fees'],
			'credit'               => $credit,
			'received_date'        => $received_date,
			'schedule'             => ( null !== $qt ) ? $qt['schedule'] : null,
			'sheet_total'          => $row2['sheet_total'],
			'sheet_total_critters' => $row2['sheet_total_critters'],
			'lines'                => $lines,
			'log'                  => ( null !== $qt ) ? $qt['log'] : array(),
		);
	}

	/* ------------------------------------------------------------------ */
	/* Value helpers (public: the harness and tests use them too)          */
	/* ------------------------------------------------------------------ */

	/**
	 * Normalise a header / label: lower-case, straight quotes, single spaces.
	 *
	 * @param mixed $value
	 * @return string
	 */
	public static function norm( $value ) {
		if ( null === $value || is_bool( $value ) ) {
			return '';
		}
		if ( is_int( $value ) || is_float( $value ) ) {
			return (string) $value;
		}
		$text = (string) $value;
		$text = str_replace( array( "\xE2\x80\x98", "\xE2\x80\x99", "\xE2\x80\x9B", '`', "\xC2\xB4" ), "'", $text );
		$text = str_replace( array( "\xE2\x80\x9C", "\xE2\x80\x9D" ), '"', $text );
		$text = str_replace( "\xC2\xA0", ' ', $text );
		$text = preg_replace( '/\s+/u', ' ', $text );
		return strtolower( trim( $text ) );
	}

	/**
	 * Trimmed text or null (blank, boolean and Excel error values give null).
	 *
	 * @param mixed $value
	 * @return string|null
	 */
	public static function text( $value ) {
		$text = self::raw_text( $value );
		if ( null === $text || self::is_error( $text ) ) {
			return null;
		}
		return $text;
	}

	/**
	 * Trimmed text or null (blank and booleans give null; error strings are kept).
	 *
	 * @param mixed $value
	 * @return string|null
	 */
	public static function raw_text( $value ) {
		if ( null === $value || is_bool( $value ) ) {
			return null;
		}
		if ( is_int( $value ) || is_float( $value ) ) {
			return (string) $value;
		}
		$text = trim( str_replace( "\xC2\xA0", ' ', (string) $value ) );
		return ( '' === $text ) ? null : $text;
	}

	/**
	 * Whether a string is an Excel error value such as #REF! or #N/A.
	 *
	 * @param mixed $value
	 * @return bool
	 */
	public static function is_error( $value ) {
		return is_string( $value ) && 1 === preg_match( '/^#(REF!|N\/A|DIV\/0!|VALUE!|NAME\?|NUM!|NULL!|SPILL!|CALC!|GETTING_DATA)$/i', trim( $value ) );
	}

	/**
	 * A number from a cell: numbers as-is, numeric text ("$1,234.50", "12%",
	 * "(5)") parsed, anything else null.
	 *
	 * @param mixed $value
	 * @return int|float|null
	 */
	public static function num( $value ) {
		if ( is_int( $value ) || is_float( $value ) ) {
			return $value;
		}
		if ( null === $value || is_bool( $value ) ) {
			return null;
		}
		$text = str_replace( array( '$', ',', ' ', "\xC2\xA0" ), '', trim( (string) $value ) );
		if ( '' === $text ) {
			return null;
		}
		if ( preg_match( '/^\((.+)\)$/', $text, $m ) ) {
			$text = '-' . $m[1];
		}
		$percent = false;
		if ( '%' === substr( $text, -1 ) ) {
			$percent = true;
			$text    = substr( $text, 0, -1 );
		}
		if ( ! is_numeric( $text ) ) {
			return null;
		}
		if ( $percent ) {
			return (float) $text / 100;
		}
		if ( preg_match( '/^-?[0-9]{1,18}$/', $text ) ) {
			return (int) $text;
		}
		return (float) $text;
	}

	/**
	 * A quantity: like num() but integral values come back as int.
	 *
	 * @param mixed $value
	 * @return int|float|null
	 */
	public static function qty( $value ) {
		$number = self::num( $value );
		if ( is_float( $number ) && floor( $number ) === $number && abs( $number ) < 9.0e15 ) {
			return (int) $number;
		}
		return $number;
	}

	/**
	 * A free-form cell: numbers as-is, text trimmed, blank null.
	 *
	 * @param mixed $value
	 * @return int|float|string|null
	 */
	public static function scalar( $value ) {
		if ( is_int( $value ) || is_float( $value ) ) {
			return $value;
		}
		return self::text( $value );
	}

	/**
	 * The cached Sci Name: null for blank, "N/A" and error values.
	 *
	 * @param mixed $value
	 * @return string|null
	 */
	public static function sci_name( $value ) {
		$text = self::text( $value );
		if ( null === $text ) {
			return null;
		}
		$norm = self::norm( $text );
		return ( 'n/a' === $norm || 'na' === $norm || '-' === $norm ) ? null : $text;
	}

	/**
	 * Any date-like cell => 'Y-m-d' or null.
	 *
	 * Accepts the reader's 'Y-m-d' / 'Y-m-d H:i' strings, typed text such as
	 * 7/2/2026 or 7/2/26, and a raw serial number.
	 *
	 * @param mixed $value
	 * @param bool  $date1904
	 * @return string|null
	 */
	public static function to_ymd( $value, $date1904 = false ) {
		if ( null === $value || is_bool( $value ) ) {
			return null;
		}
		if ( is_int( $value ) || is_float( $value ) ) {
			return ( $value >= 1 ) ? Workbook::serial_to_date( $value, $date1904 ) : null;
		}
		$text = trim( (string) $value );
		if ( preg_match( '/^(\d{4})-(\d{1,2})-(\d{1,2})(?:[ T].*)?$/', $text, $m ) ) {
			return checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ? sprintf( '%04d-%02d-%02d', $m[1], $m[2], $m[3] ) : null;
		}
		if ( preg_match( '#^(\d{1,2})[/\-.](\d{1,2})[/\-.](\d{2,4})(?:\s.*)?$#', $text, $m ) ) {
			$year = (int) $m[3];
			if ( $year < 100 ) {
				$year += 2000;
			}
			return checkdate( (int) $m[1], (int) $m[2], $year ) ? sprintf( '%04d-%02d-%02d', $year, $m[1], $m[2] ) : null;
		}
		if ( is_numeric( $text ) ) {
			return ( (float) $text >= 1 ) ? Workbook::serial_to_date( (float) $text, $date1904 ) : null;
		}
		return null;
	}

	/**
	 * Any time-like cell => 'H:i'; unparseable text is returned as typed.
	 *
	 * @param mixed $value
	 * @return string|null
	 */
	public static function to_hm( $value ) {
		if ( null === $value || is_bool( $value ) ) {
			return null;
		}
		if ( is_int( $value ) || is_float( $value ) ) {
			return Workbook::serial_to_time( $value );
		}
		$text = trim( (string) $value );
		if ( '' === $text ) {
			return null;
		}
		if ( preg_match( '/^(?:\d{4}-\d{2}-\d{2}[ T])?(\d{1,2}):(\d{2})(?::\d{2}(?:\.\d+)?)?\s*([AaPp])?\.?[Mm]?\.?$/', $text, $m ) ) {
			$hour   = (int) $m[1];
			$minute = (int) $m[2];
			if ( ! empty( $m[3] ) ) {
				$pm = ( 'p' === strtolower( $m[3] ) );
				if ( $pm && $hour < 12 ) {
					$hour += 12;
				} elseif ( ! $pm && 12 === $hour ) {
					$hour = 0;
				}
			}
			if ( $hour < 24 && $minute < 60 ) {
				return sprintf( '%02d:%02d', $hour, $minute );
			}
		}
		return $text;
	}

	/* ------------------------------------------------------------------ */
	/* Sheet helpers                                                       */
	/* ------------------------------------------------------------------ */

	/**
	 * @param Workbook $wb
	 * @param int|null $default_year
	 * @return int
	 */
	private static function default_year( Workbook $wb, $default_year ) {
		if ( null !== $default_year && (int) $default_year > 0 ) {
			return (int) $default_year;
		}
		if ( preg_match( '/(20[0-9]{2})/', basename( $wb->path() ), $m ) ) {
			return (int) $m[1];
		}
		return self::FALLBACK_YEAR;
	}

	/**
	 * Sheets whose name matches a numbered pattern: number => sheet name, sorted.
	 *
	 * @param Workbook $wb
	 * @param string   $pattern
	 * @return array
	 */
	private static function numbered_sheets( Workbook $wb, $pattern ) {
		$found = array();
		foreach ( $wb->sheet_names() as $name ) {
			if ( preg_match( $pattern, trim( $name ), $m ) ) {
				$found[ (int) $m[1] ] = $name;
			}
		}
		ksort( $found );
		return $found;
	}

	/**
	 * Cells of a row as array( array( letter, value ), … ) sorted by column.
	 *
	 * @param array $cells Column letter => value.
	 * @return array
	 */
	private static function sorted_cells( $cells ) {
		$sorted = array();
		foreach ( $cells as $letter => $value ) {
			$sorted[ Workbook::col_index( $letter ) ] = array( $letter, $value );
		}
		ksort( $sorted );
		return array_values( $sorted );
	}

	/**
	 * Find columns by header text: key => column letter (first match from the left).
	 *
	 * @param array $cells  Column letter => value of the header row.
	 * @param array $wanted key => array of accepted normalised texts.
	 * @return array
	 */
	private static function find_headers( $cells, $wanted ) {
		$found = array();
		foreach ( self::sorted_cells( $cells ) as $pair ) {
			$norm = self::norm( $pair[1] );
			if ( '' === $norm ) {
				continue;
			}
			foreach ( $wanted as $key => $texts ) {
				if ( ! isset( $found[ $key ] ) && in_array( $norm, $texts, true ) ) {
					$found[ $key ] = $pair[0];
				}
			}
		}
		return $found;
	}

	/**
	 * Column letter of the first cell in a row matching one of the texts, or null.
	 *
	 * @param array $cells
	 * @param array $texts Normalised texts.
	 * @return string|null
	 */
	private static function find_headers_in_row( $cells, $texts ) {
		foreach ( self::sorted_cells( $cells ) as $pair ) {
			if ( in_array( self::norm( $pair[1] ), $texts, true ) ) {
				return $pair[0];
			}
		}
		return null;
	}

	/**
	 * First cell in a row range whose text matches: array( row, letter ) or null.
	 *
	 * @param array $rows
	 * @param int   $from_row
	 * @param int   $to_row
	 * @param array $texts Normalised texts.
	 * @return array|null
	 */
	private static function find_label( $rows, $from_row, $to_row, $texts ) {
		for ( $r = $from_row; $r <= $to_row; $r++ ) {
			if ( ! isset( $rows[ $r ] ) ) {
				continue;
			}
			$letter = self::find_headers_in_row( $rows[ $r ], $texts );
			if ( null !== $letter ) {
				return array( $r, $letter );
			}
		}
		return null;
	}

	/**
	 * Match a normalised label against alias lists (exact match).
	 *
	 * @param string $norm
	 * @param array  $labels key => array of normalised texts.
	 * @return string|null
	 */
	private static function match_label( $norm, $labels ) {
		if ( '' === $norm ) {
			return null;
		}
		foreach ( $labels as $key => $texts ) {
			if ( in_array( $norm, $texts, true ) ) {
				return $key;
			}
		}
		return null;
	}

	/**
	 * Whether a row-2 string is a label rather than a value (ends with ':' or is a known mode label).
	 *
	 * @param string $text
	 * @return bool
	 */
	private static function is_label_text( $text ) {
		$norm = self::norm( $text );
		if ( '' === $norm || null !== self::num( $text ) ) {
			return false;
		}
		if ( ':' === substr( $norm, -1 ) ) {
			return true;
		}
		return null !== self::match_label( $norm, self::$batch_row2_labels )
			|| in_array( $norm, array( 'fees per full box', 'shipping per bag' ), true );
	}

	/**
	 * @param array       $rows
	 * @param int         $row
	 * @param string|null $letter
	 * @return mixed Null when the cell is empty or the column unknown.
	 */
	private static function cell_at( $rows, $row, $letter ) {
		if ( null === $letter || '' === $letter ) {
			return null;
		}
		return isset( $rows[ $row ][ $letter ] ) ? $rows[ $row ][ $letter ] : null;
	}

	/**
	 * @param array  $rows
	 * @param int    $row
	 * @param string $letter
	 * @param int    $offset Columns to the right.
	 * @return mixed
	 */
	private static function cell_right_of( $rows, $row, $letter, $offset ) {
		return self::cell_at( $rows, $row, Workbook::col_letter( Workbook::col_index( $letter ) + $offset ) );
	}

	/**
	 * @param array  $cols
	 * @param string $key
	 * @return string|null
	 */
	private static function col_of( $cols, $key ) {
		return isset( $cols[ $key ] ) ? $cols[ $key ] : null;
	}
}
