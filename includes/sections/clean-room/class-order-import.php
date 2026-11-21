<?php
/**
 * Import lines — the pure half of the order page's line importer.
 *
 * Two moments, one reader:
 *
 *   ordered  "This is what I ORDERED": the owner's own order sheet — often
 *            the supplier's whole price list with an ORDER column filled
 *            in. Only the rows with an order quantity above 0 become lines;
 *            received is left empty.
 *   arrived  "This is what ARRIVED": the supplier's invoice or packing
 *            list (pasted, uploaded, or the block a chat assistant made
 *            from the PDF). Every row is paired with a line already on the
 *            order — by the supplier's code first, then by fish and size —
 *            and the result is a reconciliation in four groups: came as
 *            ordered, came short / over, did not come, extra / substituted.
 *
 * What is here, top to bottom:
 *
 *   - the reader: pasted text (tab-, comma- or semicolon-separated, with
 *     quoted cells) or the rows of a workbook sheet → one bounded grid of
 *     clean text cells. Nothing is ever evaluated: a cell that looks like
 *     a formula ("=…", "+…", "@…") is text, and never a number;
 *   - the column finder: a heading is recognised by its TEXT, never by its
 *     position (header_role()) — the labels of the workbook's own
 *     "Recieved (N)" sheet come from Import_Map::batch_headers(), kept in
 *     one place — and, without a header row, a guess from the cells
 *     (guess_columns());
 *   - the numbers (currency signs, thousands separators and "ea"
 *     tolerated; a decimal comma is NOT assumed) and the sizes inside a
 *     name ("(L)", "- S", "S/M"), lifted out only when they are one of
 *     the standard sizes;
 *   - the matcher: the same scoring the phone's Arrival search and the
 *     voice parser use (Parser::token_score()), over an index so a
 *     150-line order against a 2,000-fish catalogue stays quick. A weak or
 *     ambiguous match is flagged and never chosen silently;
 *   - analyse(): the grid → rows, the rows left out (blank lines, repeated
 *     headers, totals, section headings, fee lines) and the fees;
 *   - reconcile(): the arrived rows against the order's lines;
 *   - the instruction text the owner copies into a chat assistant
 *     together with the invoice, and the block format it asks for.
 *
 * Nothing here reads or writes the database: Admin_Order_Import feeds it.
 *
 * @package FisHotel\Misc\Sections\Clean_Room
 */

namespace FisHotel\Misc\Sections\Clean_Room;

defined( 'ABSPATH' ) || exit;

/**
 * Class Order_Import
 */
class Order_Import {

	const MODES = array( 'ordered', 'arrived' );

	/** The most text a paste or a CSV file may hold. */
	const MAX_TEXT_BYTES = 2097152;

	/** Rows READ from a file or a paste — a 2,000-row price list fits comfortably. */
	const MAX_ROWS = 6000;
	const MAX_COLS = 40;
	const MAX_CELL = 200;

	/** Rows searched for the header row. */
	const HEADER_SEARCH_ROWS = 30;

	/** How many candidates a row's dropdown offers. */
	const MAX_CANDIDATES = 5;

	/** The scores of a confident match: the parser's own (Parser::parse()). */
	const SCORE_SURE = 0.75;
	const SCORE_GAP  = 0.12;

	/**
	 * The header of the block a chat assistant is asked for — and what the
	 * README documents. One test reads all three.
	 *
	 * @var string[]
	 */
	const BLOCK_HEADER = array( 'fish', 'scientific name', 'size', 'ordered', 'received', 'price', 'DOA', 'box', 'percent', 'label', 'code', 'origin', 'check' );

	/** The header of the block's second, small part: the fee lines. */
	const FEE_HEADER = array( 'fee', 'amount', 'origin' );

	/** The note tags an imported line may carry. */
	const NOTE_CODE    = '[code ';
	const NOTE_WRITTEN = '[as written: ';
	const NOTE_SIZE    = '[size as written: ';
	const NOTE_BOXES   = '[boxes ';
	const NOTE_INSTEAD = '[came instead of: ';
	const NOTE_SUBBED  = '[substituted by: ';

	/* ============================================================== */
	/*  Cells and the grid                                             */
	/* ============================================================== */

	/**
	 * One cell as clean text: control characters dropped, spaces
	 * collapsed, trimmed, at most MAX_CELL characters. Numbers come from
	 * a workbook as numbers: a whole float reads without its ".0".
	 *
	 * @param mixed $value A cell.
	 * @return string
	 */
	public static function cell( $value ) {
		if ( null === $value || is_bool( $value ) || ! is_scalar( $value ) ) {
			return '';
		}
		if ( is_float( $value ) ) {
			if ( is_nan( $value ) || is_infinite( $value ) ) {
				return '';
			}
			$value = floor( $value ) === $value && abs( $value ) < 1.0e15 ? (string) (int) $value : rtrim( rtrim( sprintf( '%.10F', $value ), '0' ), '.' );
		}
		$text = (string) $value;
		if ( function_exists( 'mb_check_encoding' ) && ! mb_check_encoding( $text, 'UTF-8' ) ) {
			$text = (string) preg_replace( '/[^\x20-\x7E]/', '', $text );
		}
		$text = str_replace( "\xC2\xA0", ' ', $text );
		$text = (string) preg_replace( '/[\x00-\x1F\x7F]+/', ' ', $text );
		$text = trim( (string) preg_replace( '/\s+/u', ' ', $text ) );
		return self::cut( $text, self::MAX_CELL );
	}

	/**
	 * The first $max characters of a text (never half a character).
	 *
	 * @param string $text Text.
	 * @param int    $max  Characters.
	 * @return string
	 */
	public static function cut( $text, $max ) {
		$text = (string) $text;
		if ( function_exists( 'mb_substr' ) ) {
			return (string) mb_substr( $text, 0, (int) $max, 'UTF-8' );
		}
		return (string) substr( $text, 0, (int) $max );
	}

	/**
	 * Whether a cell looks like a spreadsheet formula. Such a cell is
	 * text: shown as typed (escaped), never read as a number.
	 *
	 * @param string $text A cell.
	 * @return bool
	 */
	public static function is_formula( $text ) {
		$text = ltrim( (string) $text );
		return '' !== $text && in_array( $text[0], array( '=', '+', '@' ), true );
	}

	/**
	 * Pasted or uploaded text → a grid. Rows copied out of a spreadsheet
	 * are tab-separated; otherwise the delimiter is the comma or the
	 * semicolon, whichever most lines have. Quoted cells may hold the
	 * delimiter, a doubled quote and a line break.
	 *
	 * @param mixed $text The text.
	 * @return array{grid: string[][], truncated: bool, delimiter: string}
	 */
	public static function grid_from_text( $text ) {
		$text = is_scalar( $text ) && ! is_bool( $text ) ? (string) $text : '';
		if ( strlen( $text ) > self::MAX_TEXT_BYTES ) {
			$text = substr( $text, 0, self::MAX_TEXT_BYTES );
		}
		if ( 0 === strpos( $text, "\xEF\xBB\xBF" ) ) {
			$text = substr( $text, 3 );
		}
		$text = str_replace( array( "\r\n", "\r" ), "\n", $text );
		$text = self::untable( $text );

		$delimiter = self::delimiter_of( $text );
		$rows      = array();
		$row       = array();
		$cell      = '';
		$quoted    = false;
		$length    = strlen( $text );
		$truncated = false;

		for ( $i = 0; $i < $length; $i++ ) {
			$char = $text[ $i ];
			if ( $quoted ) {
				if ( '"' === $char ) {
					if ( $i + 1 < $length && '"' === $text[ $i + 1 ] ) {
						$cell .= '"';
						$i++;
					} else {
						$quoted = false;
					}
				} else {
					$cell .= $char;
				}
				continue;
			}
			if ( '"' === $char && '' === trim( $cell ) ) {
				$quoted = true;
				$cell   = '';
				continue;
			}
			if ( $char === $delimiter ) {
				$row[] = $cell;
				$cell  = '';
				continue;
			}
			if ( "\n" === $char ) {
				$row[]  = $cell;
				$rows[] = $row;
				$row    = array();
				$cell   = '';
				if ( count( $rows ) >= self::MAX_ROWS ) {
					$truncated = $i + 1 < $length && '' !== trim( substr( $text, $i + 1 ) );
					break;
				}
				continue;
			}
			$cell .= $char;
		}
		if ( count( $rows ) < self::MAX_ROWS && ( '' !== $cell || $row ) ) {
			$row[]  = $cell;
			$rows[] = $row;
		}

		$clean = self::clean_grid( $rows );
		return array(
			'grid'      => $clean['grid'],
			'truncated' => $truncated || $clean['truncated'],
			'delimiter' => $delimiter,
		);
	}

	/**
	 * What a chat assistant's answer does to a table, undone — the text
	 * comes back tab-separated when it safely can:
	 *
	 *   - the lines of a code fence ("```") go;
	 *   - a Markdown table ("| fish | qty |" with its "|---|---|" line)
	 *     is read cell by cell;
	 *   - columns set apart by runs of two or more spaces (tabs that were
	 *     turned into spaces) are read ONLY when every row has as many
	 *     cells as the header — a blank cell leaves no trace in such a
	 *     text, and a number must never slide into its neighbour's column.
	 *     Otherwise the text is left as it is, and the preview says the
	 *     columns ran together (run_together()).
	 *
	 * A text that has a tab anywhere is taken as it stands.
	 *
	 * @param string $text Text with "\n" line ends.
	 * @return string
	 */
	public static function untable( $text ) {
		$lines = array();
		foreach ( explode( "\n", (string) $text ) as $line ) {
			if ( 1 !== preg_match( '/^\s*(```|~~~)[\w-]*\s*$/', $line ) ) {
				$lines[] = $line;
			}
		}
		$text = implode( "\n", $lines );
		if ( false !== strpos( $text, "\t" ) ) {
			return $text;
		}

		// A Markdown table.
		$filled = 0;
		$piped  = 0;
		foreach ( $lines as $line ) {
			if ( '' === trim( $line ) ) {
				continue;
			}
			$filled++;
			if ( substr_count( $line, '|' ) >= 2 ) {
				$piped++;
			}
		}
		if ( $piped >= 2 && $piped * 2 >= $filled ) {
			$out = array();
			foreach ( $lines as $line ) {
				$bare = trim( $line );
				if ( substr_count( $bare, '|' ) < 2 ) {
					$out[] = $line;
					continue;
				}
				if ( 1 === preg_match( '/^[\s|:-]+$/', $bare ) && false !== strpos( $bare, '-' ) ) {
					continue; // The "|---|---|" line under the header.
				}
				$bare  = (string) preg_replace( '/^\||\|$/', '', $bare );
				$cells = array();
				foreach ( (array) preg_split( '/(?<!\\\\)\|/', $bare ) as $cell ) {
					$cells[] = str_replace( '\\|', '|', trim( (string) $cell ) );
				}
				$out[] = implode( "\t", $cells );
			}
			return implode( "\n", $out );
		}

		// Columns set apart by runs of spaces: only when no cell can have gone missing.
		$split  = static function ( $line ) {
			return (array) preg_split( '/ {2,}/', trim( $line ) );
		};
		$width  = 0;
		$head   = null;
		$seen   = 0;
		foreach ( $lines as $i => $line ) {
			if ( '' === trim( $line ) ) {
				continue;
			}
			if ( ++$seen > self::HEADER_SEARCH_ROWS ) {
				break;
			}
			$cells = $split( $line );
			if ( count( $cells ) >= 3 && count( self::roles_of_row( $cells ) ) >= 2 && count( self::roles_of_row( explode( ',', $line ) ) ) < 2 ) {
				$head  = $i;
				$width = count( $cells );
				break;
			}
		}
		if ( null === $head ) {
			return $text;
		}
		$out  = $lines;
		$fees = false;
		foreach ( $lines as $i => $line ) {
			if ( $i < $head || '' === trim( $line ) ) {
				continue;
			}
			$cells = $split( $line );
			if ( count( $cells ) < 2 ) {
				continue;
			}
			$keys = array_map( array( self::class, 'header_key' ), $cells );
			$fees = $fees || ( in_array( 'fee', $keys, true ) && in_array( 'amount', $keys, true ) );
			if ( ! $fees && count( $cells ) !== $width ) {
				return $text; // A cell is missing somewhere: not read by guessing.
			}
			$out[ $i ] = implode( "\t", $cells );
		}
		return implode( "\n", $out );
	}

	/**
	 * Whether the columns of a text ran together: a row whose one cell
	 * holds four or more column headings in a row ("fish scientific name
	 * size ordered received price …") — a block whose tabs were lost on
	 * the way. Nothing can be read from that safely.
	 *
	 * @param string[][] $grid The grid.
	 * @return bool
	 */
	public static function run_together( array $grid ) {
		foreach ( array_slice( $grid, 0, self::HEADER_SEARCH_ROWS ) as $row ) {
			$filled = array_values( array_filter( $row, 'strlen' ) );
			if ( 1 !== count( $filled ) ) {
				continue;
			}
			$roles = array();
			foreach ( (array) preg_split( '/\s+/', $filled[0] ) as $word ) {
				$role = self::header_role( $word );
				if ( '' !== $role ) {
					$roles[ $role ] = true;
				}
			}
			if ( count( $roles ) >= 4 ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * The delimiter of a pasted text: a tab when any line has one (rows
	 * copied out of a spreadsheet), else the comma or the semicolon —
	 * whichever more lines carry outside quotes.
	 *
	 * @param string $text Text with "\n" line ends.
	 * @return string
	 */
	public static function delimiter_of( $text ) {
		if ( false !== strpos( $text, "\t" ) ) {
			return "\t";
		}
		$counts = array(
			',' => 0,
			';' => 0,
		);
		foreach ( array_slice( explode( "\n", $text ), 0, 200 ) as $line ) {
			$bare = (string) preg_replace( '/"[^"]*"/', '', $line );
			foreach ( $counts as $char => $n ) {
				if ( false !== strpos( $bare, $char ) ) {
					$counts[ $char ]++;
				}
			}
		}
		return $counts[';'] > $counts[','] ? ';' : ',';
	}

	/**
	 * The rows of a workbook sheet (Workbook::rows(): row number → column
	 * letter → value) → a grid. Empty rows are kept as one blank row each
	 * gap at most, so "the row the header stands on" still means something.
	 *
	 * @param array $rows Workbook::rows().
	 * @return array{grid: string[][], truncated: bool}
	 */
	public static function grid_from_rows( array $rows ) {
		ksort( $rows );
		$out = array();
		foreach ( $rows as $cells ) {
			if ( ! is_array( $cells ) ) {
				continue;
			}
			$row = array();
			foreach ( $cells as $letter => $value ) {
				$index = Workbook::col_index( (string) $letter );
				if ( null === $index || $index < 1 || $index > self::MAX_COLS ) {
					continue;
				}
				$row[ $index - 1 ] = $value;
			}
			if ( ! $row ) {
				continue;
			}
			$full = array_fill( 0, max( array_keys( $row ) ) + 1, '' );
			foreach ( $row as $index => $value ) {
				$full[ $index ] = $value;
			}
			$out[] = $full;
			if ( count( $out ) > self::MAX_ROWS ) {
				break;
			}
		}
		return self::clean_grid( $out );
	}

	/**
	 * Any list of rows → the grid the importer works on: at most MAX_ROWS
	 * rows of at most MAX_COLS clean cells (cell()), every row as wide as
	 * the widest, blank rows dropped, columns that are empty all the way
	 * down dropped from the right. Also what a posted grid goes through
	 * on its way back in.
	 *
	 * @param mixed $raw Rows of cells.
	 * @return array{grid: string[][], truncated: bool}
	 */
	public static function clean_grid( $raw ) {
		$grid      = array();
		$width     = 0;
		$truncated = false;
		foreach ( is_array( $raw ) ? $raw : array() as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			if ( count( $grid ) >= self::MAX_ROWS ) {
				$truncated = true;
				break;
			}
			$cells = array();
			$last  = -1;
			foreach ( array_slice( array_values( $row ), 0, self::MAX_COLS ) as $index => $value ) {
				$cells[ $index ] = self::cell( $value );
				if ( '' !== $cells[ $index ] ) {
					$last = $index;
				}
			}
			if ( $last < 0 ) {
				continue;
			}
			$grid[] = array_slice( $cells, 0, $last + 1 );
			$width  = max( $width, $last + 1 );
		}
		foreach ( $grid as $index => $row ) {
			if ( count( $row ) < $width ) {
				$grid[ $index ] = array_pad( $row, $width, '' );
			}
		}
		return array(
			'grid'      => $grid,
			'truncated' => $truncated,
		);
	}

	/* ============================================================== */
	/*  Columns                                                        */
	/* ============================================================== */

	/**
	 * What a column can be, with the words the dropdown and the Settings
	 * list show.
	 *
	 * @return array<string, string>
	 */
	public static function roles() {
		return array(
			'fish'     => __( 'the fish', 'fishotel-misc-plugin' ),
			'sci'      => __( 'scientific name', 'fishotel-misc-plugin' ),
			'size'     => __( 'size', 'fishotel-misc-plugin' ),
			'ordered'  => __( 'ordered', 'fishotel-misc-plugin' ),
			'received' => __( 'received', 'fishotel-misc-plugin' ),
			'qty'      => __( 'quantity', 'fishotel-misc-plugin' ),
			'price'    => __( 'price each', 'fishotel-misc-plugin' ),
			'amount'   => __( 'line total', 'fishotel-misc-plugin' ),
			'doa'      => __( 'DOA', 'fishotel-misc-plugin' ),
			'per_box'  => __( 'per box', 'fishotel-misc-plugin' ),
			'bags'     => __( 'bags', 'fishotel-misc-plugin' ),
			'box'      => __( 'box number', 'fishotel-misc-plugin' ),
			'pct'      => __( 'percent of a box', 'fishotel-misc-plugin' ),
			'tank'     => __( 'tank', 'fishotel-misc-plugin' ),
			'label'    => __( 'what the label said', 'fishotel-misc-plugin' ),
			'code'     => __( 'the supplier\'s code', 'fishotel-misc-plugin' ),
			'origin'   => __( 'origin / section', 'fishotel-misc-plugin' ),
			'worth'    => __( 'worth (FisHotel price)', 'fishotel-misc-plugin' ),
			'survived' => __( 'survived', 'fishotel-misc-plugin' ),
			'stock'    => __( 'the supplier\'s stock (never a quantity)', 'fishotel-misc-plugin' ),
			'check'    => __( 'check ("?" = look at this row)', 'fishotel-misc-plugin' ),
		);
	}

	/** The roles a quantity can come from. */
	const QTY_ROLES = array( 'ordered', 'received', 'qty' );

	/**
	 * A heading as it is compared: Import_Map::norm() (lower case,
	 * straight quotes, single spaces) without a trailing colon or dot.
	 *
	 * @param mixed $text A heading.
	 * @return string
	 */
	public static function header_key( $text ) {
		$key = Import_Map::norm( is_scalar( $text ) ? $text : '' );
		$key = trim( (string) preg_replace( '/[\s:.]+$/', '', $key ) );
		return self::cut( $key, 80 );
	}

	/**
	 * The headings each role answers to: the exact texts, then a looser
	 * pattern. The order matters for the patterns only ("latin name" is
	 * the scientific name before "name" is the fish; "qty/box" is the
	 * per-box count before "qty" is a quantity; "stock" is never one).
	 * The labels of the workbook's own "Recieved (N)" sheet are read from
	 * Import_Map, which already knows them.
	 *
	 * @return array<string, array{0: string[], 1: string}>
	 */
	public static function header_rules() {
		static $rules = null;
		if ( null !== $rules ) {
			return $rules;
		}
		$rules = array(
			'sci'      => array( array( 'scientific name', 'sci name', 'scientific', 'latin name', 'latin', 'taxonomy', 'species name', 'scientific / latin name' ), '/\b(scientific|latin)\b/' ),
			'per_box'  => array( array( 'per box', 'qty/box', 'qty / box', 'qty per box', 'pcs/box', 'pcs per box', 'box qty', 'fish per box' ), '/\b(qty|pcs|pieces|fish)\s*(\/|per)\s*(box|bx)\b|\bper box\b/' ),
			'pct'      => array( array( 'percent of a box', 'percent of box', '% of box', '% of a box', 'percent', 'box %', 'pct' ), '/percent|%\s*(of\s*)?(a\s*)?box/' ),
			'label'    => array( array( 'what the label said', 'label', 'labeled', 'labelled', 'label name', 'as labeled', 'arrived labeled' ), '/\blabel/' ),
			'stock'    => array( array( 'stock', 'in stock', 'available', 'avail', 'availability', 'on hand' ), '/\b(stock|avail)/' ),
			'ordered'  => array( array( 'ordered', 'order', 'order qty', 'qty ordered', 'your order', 'order quantity', 'ord' ), '/\border(ed)?\b/' ),
			'received' => array( array( 'received', 'recieved', 'shipped', 'filled', 'arrived', 'rcvd', 'qty shipped', 'qty received', 'shipped qty', 'ship qty', 'sent' ), '/\b(received|recieved|shipped|filled|arrived)\b/' ),
			'doa'      => array( array( 'doa', 'dead', 'd.o.a', 'doas' ), '/\b(doa|dead)\b/' ),
			'amount'   => array( array( 'total', 'amount', 'amt', 'ext', 'extended', 'line total', 'ext price', 'total price', 'total us$', 'total usd' ), '/\b(total|amount|amt)\b/' ),
			'price'    => array( array( 'price', 'cost', 'unit price', 'each', 'unit', 'unit cost', 'price each', 'ea', 'price us$', 'price usd', 'us$', 'usd' ), '/\b(price|cost|each)\b/' ),
			'qty'      => array( array( 'qty', 'quantity', 'pcs', 'pieces', 'count', 'number', "q'ty", 'qnty' ), '/\b(qty|quantity|pcs|pieces)\b/' ),
			'bags'     => array( array( 'bags', 'bag' ), '' ),
			'box'      => array( array( 'box', 'box no', 'box #', 'box number', 'bx', 'carton' ), '/^box\b/' ),
			'tank'     => array( array( 'tank', 'tank #', 'tank no', 'tank number' ), '' ),
			'code'     => array( array( 'code', 'item code', 'sku', 'item no', 'item #', 'item number', 'cat no', 'product code' ), '/\b(code|sku)\b/' ),
			'origin'   => array( array( 'origin', 'section', 'country', 'origin / section', 'origin/section', 'box/section', 'box / section' ), '/\b(origin|section|country)\b/' ),
			'check'    => array( array( 'check', 'check?' ), '' ),
			'size'     => array( array( 'size', 'sizes', 'sz' ), '/^size\b/' ),
			'worth'    => array( array( 'worth', 'my price' ), '' ),
			'survived' => array( array( 'survived' ), '' ),
			'fish'     => array( array( 'fish', 'species', 'name', 'common name', 'description', 'item', 'english name', 'common', 'item name', 'item description', 'fish name', 'trade name', 'desc' ), '/\bname\b|\bdescription\b/' ),
		);

		// The "Recieved (N)" sheet's own labels, from the file that maps that sheet.
		$batch = array(
			'name'         => 'fish',
			'sci_name'     => 'sci',
			'qty_ordered'  => 'ordered',
			'per_box'      => 'per_box',
			'bags'         => 'bags',
			'qty_received' => 'received',
			'unit_price'   => 'price',
			'total'        => 'amount',
			'worth'        => 'worth',
			'qty_survived' => 'survived',
			'qty_doa'      => 'doa',
		);
		foreach ( Import_Map::batch_headers() as $key => $texts ) {
			if ( ! isset( $batch[ $key ] ) ) {
				continue;
			}
			foreach ( (array) $texts as $text ) {
				$text = self::header_key( $text );
				if ( '' !== $text && ! in_array( $text, $rules[ $batch[ $key ] ][0], true ) ) {
					$rules[ $batch[ $key ] ][0][] = $text;
				}
			}
		}
		return $rules;
	}

	/**
	 * The role a heading names — '' when nobody recognises it. Found by
	 * the heading's text alone.
	 *
	 * @param mixed $text A heading.
	 * @return string
	 */
	public static function header_role( $text ) {
		$key = self::header_key( $text );
		if ( '' === $key || strlen( $key ) > 40 || self::is_formula( $key ) || is_numeric( $key ) || self::is_letterhead( $key ) ) {
			return '';
		}
		$rules = self::header_rules();
		foreach ( $rules as $role => $rule ) {
			if ( in_array( $key, $rule[0], true ) ) {
				return $role;
			}
		}
		foreach ( $rules as $role => $rule ) {
			if ( '' !== $rule[1] && 1 === preg_match( $rule[1], $key ) ) {
				return $role;
			}
		}
		return '';
	}

	/**
	 * A label of a sheet's letterhead — "Customer Name:", "Order No:",
	 * "Ship to", "Date" — which names the paper, not a column of fish.
	 *
	 * @param string $key header_key().
	 * @return bool
	 */
	public static function is_letterhead( $key ) {
		return 1 === preg_match( '/\b(customer|client|company|contact|consignee|shipper|buyer|attn|attention|address|phone|tel|fax|e-?mail)\b|\b(supplier|vendor|your|my)(\'s)? name\b|\b(ship|bill|sold|deliver)(ped)? to\b|\b(order|invoice|po|p\.o|awb|ref|reference|account|acct)\s*(no|number|nr|num|#|id|date|ref)(\b|$)/', (string) $key );
	}

	/**
	 * The roles of a row read as a header: column → role, each role once
	 * (the first from the left wins).
	 *
	 * @param string[] $row A grid row.
	 * @return array<int, string>
	 */
	public static function roles_of_row( array $row ) {
		$map  = array();
		$used = array();
		foreach ( array_values( $row ) as $index => $text ) {
			$role = self::header_role( $text );
			if ( '' === $role || isset( $used[ $role ] ) ) {
				continue;
			}
			$map[ $index ] = $role;
			$used[ $role ] = true;
		}
		return $map;
	}

	/**
	 * Find the header row within the first HEADER_SEARCH_ROWS — a price
	 * list has one to five title and address rows above it, and a
	 * letterhead row ("Customer Name: … Order No: …") can look like two
	 * headings. So it is not the first row with two recognised headings
	 * but the BEST one: a row the importer can save from (a fish and a
	 * quantity) before one it cannot, then the row with the most
	 * headings, then the one higher up.
	 *
	 * @param string[][] $grid The grid.
	 * @return array{row: int|null, map: array<int, string>}
	 */
	public static function find_header( array $grid ) {
		$best = array(
			'row' => null,
			'map' => array(),
		);
		$rank = array( -1, 0 );
		foreach ( array_slice( $grid, 0, self::HEADER_SEARCH_ROWS, true ) as $index => $row ) {
			$map = self::roles_of_row( $row );
			if ( count( $map ) < 2 ) {
				continue;
			}
			$mine = array( self::map_complete( $map ) ? 1 : 0, count( $map ) );
			if ( $mine[0] > $rank[0] || ( $mine[0] === $rank[0] && $mine[1] > $rank[1] ) ) {
				$rank = $mine;
				$best = array(
					'row' => (int) $index,
					'map' => $map,
				);
			}
		}
		return $best;
	}

	/**
	 * Without a header: the wordy column is the fish, the first
	 * whole-number column the quantity, a decimal column the price. A
	 * guess — the preview shows the dropdowns so the owner sees it.
	 *
	 * @param string[][] $grid The grid.
	 * @return array<int, string>
	 */
	public static function guess_columns( array $grid ) {
		$stats = array();
		foreach ( array_slice( $grid, 0, 60 ) as $row ) {
			foreach ( $row as $index => $text ) {
				if ( ! isset( $stats[ $index ] ) ) {
					$stats[ $index ] = array(
						'filled' => 0,
						'whole'  => 0,
						'number' => 0,
						'dec'    => 0,
						'words'  => 0,
						'length' => 0,
						'last'   => null,
						'steps'  => 0,
					);
				}
				if ( '' === $text ) {
					continue;
				}
				$stats[ $index ]['filled']++;
				$number = self::number( $text );
				if ( 'ok' === $number['state'] ) {
					// A column that counts 1, 2, 3 … is the sheet's own row number, never a quantity.
					if ( null !== $stats[ $index ]['last'] && $number['value'] === $stats[ $index ]['last'] + 1 ) {
						$stats[ $index ]['steps']++;
					}
					$stats[ $index ]['last'] = $number['value'];
					$stats[ $index ]['number']++;
					if ( false !== strpos( $text, '.' ) || 1 === preg_match( '/[$€£]/u', $text ) ) {
						$stats[ $index ]['dec']++;
					} elseif ( floor( $number['value'] ) === $number['value'] ) {
						$stats[ $index ]['whole']++;
					}
				} elseif ( 1 === preg_match( '/\p{L}{2,}/u', $text ) ) {
					$stats[ $index ]['words']++;
					$stats[ $index ]['length'] += strlen( $text );
				}
			}
		}

		$map  = array();
		$fish = null;
		foreach ( $stats as $index => $s ) {
			if ( $s['filled'] < 1 || $s['words'] * 2 < $s['filled'] ) {
				continue;
			}
			if ( null === $fish || $s['length'] > $stats[ $fish ]['length'] ) {
				$fish = $index;
			}
		}
		if ( null !== $fish ) {
			$map[ $fish ] = 'fish';
		}
		foreach ( $stats as $index => $s ) {
			if ( isset( $map[ $index ] ) || $s['filled'] < 1 ) {
				continue;
			}
			if ( $s['filled'] >= 3 && $s['steps'] === $s['filled'] - 1 ) {
				continue;
			}
			if ( ! in_array( 'qty', $map, true ) && $s['whole'] * 10 >= $s['filled'] * 6 ) {
				$map[ $index ] = 'qty';
			} elseif ( ! in_array( 'price', $map, true ) && $s['dec'] > 0 && $s['number'] * 10 >= $s['filled'] * 6 && $s['dec'] * 10 >= $s['filled'] * 3 ) {
				$map[ $index ] = 'price';
			}
		}
		ksort( $map );
		return $map;
	}

	/**
	 * A posted column map, cleaned: column index → a known role, each
	 * role once ('' = ignore).
	 *
	 * @param mixed $raw   column => role.
	 * @param int   $width The grid's width.
	 * @return array<int, string>
	 */
	public static function clean_map( $raw, $width ) {
		$roles = self::roles();
		$map   = array();
		$used  = array();
		foreach ( is_array( $raw ) ? $raw : array() as $index => $role ) {
			if ( ! is_numeric( $index ) || ! is_string( $role ) || (int) $index < 0 || (int) $index >= (int) $width || ! isset( $roles[ $role ] ) || isset( $used[ $role ] ) ) {
				continue;
			}
			$map[ (int) $index ] = $role;
			$used[ $role ]       = true;
		}
		ksort( $map );
		return $map;
	}

	/**
	 * Whether a map can be saved from: a fish column and one quantity. A
	 * packing list that names its fish by scientific name alone has its
	 * fish column there: the scientific name stands in.
	 *
	 * @param array<int, string> $map column → role.
	 * @return bool
	 */
	public static function map_complete( array $map ) {
		return ( in_array( 'fish', $map, true ) || in_array( 'sci', $map, true ) ) && (bool) array_intersect( self::QTY_ROLES, $map );
	}

	/* ============================================================== */
	/*  Numbers                                                        */
	/* ============================================================== */

	/**
	 * A number as an invoice writes it: currency signs, thousands
	 * separators ("1,234.50") and a trailing "ea" / "each" tolerated. A
	 * decimal comma is NOT assumed — "6,50" is flagged, not read as 6.5 or
	 * 650 — and a cell that looks like a formula is never a number.
	 *
	 * @param mixed $text A cell.
	 * @return array{state: string, value: float|null} state: blank | ok | bad.
	 */
	public static function number( $text ) {
		$text = trim( is_scalar( $text ) && ! is_bool( $text ) ? (string) $text : '' );
		if ( '' === $text || in_array( strtolower( $text ), array( '-', '--', "\xE2\x80\x94", "\xE2\x80\x93", 'n/a', 'na' ), true ) ) {
			return array(
				'state' => 'blank',
				'value' => null,
			);
		}
		$bad = array(
			'state' => 'bad',
			'value' => null,
		);
		if ( self::is_formula( $text ) ) {
			return $bad;
		}
		$plain = strtolower( $text );
		$plain = (string) preg_replace( '/\s*(\/\s*)?(ea|each|pcs?|pc)\.?$/', '', $plain );
		$plain = str_replace( array( 'us$', 'usd', '$', "\xE2\x82\xAC", "\xC2\xA3", ' ' ), '', $plain );
		if ( 1 === preg_match( '/^\d{1,3}(,\d{3})+(\.\d+)?$/', $plain ) ) {
			$plain = str_replace( ',', '', $plain );
		}
		if ( 1 !== preg_match( '/^(\d+(\.\d*)?|\.\d+)$/', $plain ) ) {
			return $bad;
		}
		$value = (float) $plain;
		if ( is_nan( $value ) || is_infinite( $value ) || $value > 99999999 ) {
			return $bad;
		}
		return array(
			'state' => 'ok',
			'value' => $value,
		);
	}

	/**
	 * A count: a whole number from 0 to Orders::MAX_QTY.
	 *
	 * @param mixed $text A cell.
	 * @return array{state: string, value: int|null}
	 */
	public static function quantity( $text ) {
		$number = self::number( $text );
		if ( 'ok' !== $number['state'] ) {
			return $number;
		}
		if ( floor( $number['value'] ) !== $number['value'] || $number['value'] > Orders::MAX_QTY ) {
			return array(
				'state' => 'bad',
				'value' => null,
			);
		}
		return array(
			'state' => 'ok',
			'value' => (int) $number['value'],
		);
	}

	/* ============================================================== */
	/*  Sizes inside names                                             */
	/* ============================================================== */

	/**
	 * A size word in its long form, so "LG", "L" and "Large" compare
	 * equal; a pair ("S/M") part by part. '' when the text is no size
	 * word at all.
	 *
	 * @param string $text A size as written.
	 * @return string
	 */
	public static function size_word( $text ) {
		static $words = array(
			'xs'          => 'x-small',
			'x-small'     => 'x-small',
			'xsmall'      => 'x-small',
			'extra small' => 'x-small',
			'tiny'        => 'tiny',
			's'           => 'small',
			'sm'          => 'small',
			'sml'         => 'small',
			'small'       => 'small',
			'm'           => 'medium',
			'md'          => 'medium',
			'med'         => 'medium',
			'medium'      => 'medium',
			'ml'          => 'medium/large',
			'l'           => 'large',
			'lg'          => 'large',
			'lrg'         => 'large',
			'large'       => 'large',
			'xl'          => 'x-large',
			'x-large'     => 'x-large',
			'xlarge'      => 'x-large',
			'extra large' => 'x-large',
			'xxl'         => 'xx-large',
			'jumbo'       => 'jumbo',
			'show'        => 'show',
			'juv'         => 'juvenile',
			'juvenile'    => 'juvenile',
			'adult'       => 'adult',
		);
		$text = strtolower( trim( (string) $text, " \t.()" ) );
		if ( '' === $text ) {
			return '';
		}
		$parts = array();
		foreach ( (array) preg_split( '#\s*[/&]\s*|\s+to\s+#', $text ) as $part ) {
			$part = trim( (string) $part, ' .' );
			if ( ! isset( $words[ $part ] ) ) {
				return '';
			}
			$parts[] = $words[ $part ];
		}
		return implode( '/', $parts );
	}

	/**
	 * The ONE standard size a text names, or null. A text that reads as
	 * a size's own label does (size_plain(): the inch mark, spaces and
	 * capitals aside) is that size — a list may hold both 3-5" and 3.5"+,
	 * and those two share a slug. Only then: the same slug as a size of
	 * the list (a label in inches or centimetres as it stands), or the
	 * same size word ("LG" for a list that says "Large"). Two sizes that
	 * would both fit are no clean match, and a bare number is no size —
	 * "5" is a count or a measure nobody named, never 5"+ — unless the
	 * list holds a size written exactly so.
	 *
	 * @param string  $text  A size as written.
	 * @param array[] $sizes The standard list (slug, label).
	 * @return array{slug: string, label: string}|null
	 */
	public static function standard_size( $text, array $sizes ) {
		$text = trim( (string) $text, " \t()" );
		if ( '' === $text ) {
			return null;
		}
		$plain = self::size_plain( $text );
		$bare  = 1 === preg_match( '/^\d+$/', $text );
		$slug  = $bare ? '' : Sizes::slug( $text );
		$word  = self::size_word( $text );
		$exact = array();
		$found = array();
		foreach ( $sizes as $size ) {
			$label = (string) ( $size['label'] ?? '' );
			$own   = (string) ( $size['slug'] ?? '' );
			if ( '' === $own ) {
				continue;
			}
			$one = array(
				'slug'  => $own,
				'label' => '' !== $label ? $label : $own,
			);
			if ( '' !== $plain && self::size_plain( $label ) === $plain && ( ! $bare || trim( $label ) === $text ) ) {
				$exact[ $own ] = $one;
			}
			if ( ( '' !== $slug && ( $own === $slug || Sizes::slug( $label ) === $slug ) ) || ( '' !== $word && self::size_word( $label ) === $word ) ) {
				$found[ $own ] = $one;
			}
		}
		if ( $exact ) {
			return 1 === count( $exact ) ? reset( $exact ) : null;
		}
		return 1 === count( $found ) ? reset( $found ) : null;
	}

	/**
	 * A size as it reads once the inch mark, spaces and capitals are set
	 * aside: '3-5"', '3 - 5"' and "3-5" read alike, and unlike '3.5"+'.
	 *
	 * @param string $text A size as written, or a label.
	 * @return string
	 */
	private static function size_plain( $text ) {
		$text = strtolower( trim( (string) $text ) );
		$text = str_replace( array( "\xE2\x80\x9D", "\xE2\x80\xB3", "''" ), '"', $text );
		return (string) preg_replace( '/[\s"]+/', '', $text );
	}

	/**
	 * Lift a size out of a name. Looked at, in turn: the size column's
	 * own cell; a parenthesis in the name ("(L)", "(10-14 CM)"); a size
	 * word standing at the end of the name or right before a parenthesis
	 * ("- S", "S/M", "LG/XL", "M (6-9,5 cm)"); a trailing measure
	 * ('2-3"'). It is lifted only when it is one of the standard sizes —
	 * otherwise the name stays exactly as typed and the size as written
	 * is handed back for the note. "Mated Pair", "male", "female", "NEW",
	 * "RARE" and "(INV)" are no sizes and stay in the name.
	 *
	 * @param string  $name      The fish as typed.
	 * @param string  $size_cell The size column's cell ('' without one).
	 * @param array[] $sizes     The standard list.
	 * @return array{name: string, slug: string|null, label: string|null, written: string, lifted: bool, cell: bool}
	 *         `cell`: the size stood in a column of its own, so it is nowhere in the name.
	 */
	public static function lift_size( $name, $size_cell, array $sizes ) {
		$name = trim( (string) $name );
		$out  = array(
			'name'    => $name,
			'slug'    => null,
			'label'   => null,
			'written' => '',
			'lifted'  => false,
			'cell'    => false,
		);

		$size_cell = trim( (string) $size_cell );
		if ( '' !== $size_cell ) {
			$out['written'] = $size_cell;
			$out['cell']    = true;
			$found          = self::standard_size( $size_cell, $sizes );
			if ( $found ) {
				$out['slug']  = $found['slug'];
				$out['label'] = $found['label'];
			}
			return $out;
		}

		// 1. A parenthesis that is a size.
		if ( preg_match_all( '/\(([^()]{1,24})\)/', $name, $groups, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
			foreach ( $groups as $group ) {
				if ( self::is_name_word( $group[1][0] ) ) {
					continue;
				}
				$found = self::standard_size( $group[1][0], $sizes );
				if ( $found ) {
					return self::lifted( $name, (int) $group[0][1], strlen( $group[0][0] ), $found, $group[1][0] );
				}
				if ( '' === $out['written'] && ( '' !== self::size_word( $group[1][0] ) || self::is_measure( $group[1][0] ) ) ) {
					$out['written'] = trim( $group[1][0] );
				}
			}
		}

		// 2. A size word at the end of the name, or right before a parenthesis — the LAST one that is a
		// size: in "Opal Mock Clam (INV) M" the word before "(INV)" is "Clam", and the size stands after it.
		if ( preg_match_all( '#(?:\s+[-\x{2013}\x{2014}]\s*|,\s*|\s+)([A-Za-z]{1,8}(?:\s*/\s*[A-Za-z]{1,8})?)\s*(?=\(|$)#u', $name, $words, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
			foreach ( array_reverse( $words ) as $m ) {
				$word = $m[1][0];
				if ( '' === self::size_word( $word ) || self::is_name_word( $word ) ) {
					continue;
				}
				$found = self::standard_size( $word, $sizes );
				if ( $found ) {
					return self::lifted( $name, (int) $m[0][1], strlen( $m[0][0] ), $found, $word );
				}
				if ( '' === $out['written'] || self::is_measure( $out['written'] ) ) {
					$out['written'] = trim( $word );
				}
				break;
			}
		}

		// 3. A trailing measure: 2-3", 2 - 3.5 in.
		if ( 1 === preg_match( '/(?:\s+[-\x{2013}]\s*|,\s*|\s+)(\d+(?:[.,]\d+)?(?:\s*-\s*\d+(?:[.,]\d+)?)?\s*(?:"|\x{201D}|in\.?|inch(?:es)?|cm))\s*$/iu', $name, $m, PREG_OFFSET_CAPTURE ) ) {
			$found = self::standard_size( $m[1][0], $sizes );
			if ( $found ) {
				return self::lifted( $name, (int) $m[0][1], strlen( $m[0][0] ), $found, $m[1][0] );
			}
			if ( '' === $out['written'] ) {
				$out['written'] = trim( $m[1][0] );
			}
		}

		return $out;
	}

	/**
	 * The result of lift_size() once a standard size was found: the name
	 * without it — and without a measure in parentheses beside it, which
	 * says the same thing again.
	 *
	 * @param string $name    The name as typed.
	 * @param int    $offset  Where the piece of the name that is the size starts (bytes).
	 * @param int    $length  How long it is.
	 * @param array  $size    slug, label.
	 * @param string $written The size as written.
	 * @return array
	 */
	private static function lifted( $name, $offset, $length, array $size, $written ) {
		$clean = substr( $name, 0, $offset ) . ' ' . substr( $name, $offset + $length );
		$clean = (string) preg_replace_callback(
			'/\(([^()]{1,24})\)/',
			static function ( $m ) {
				return self::is_measure( $m[1] ) ? ' ' : $m[0];
			},
			$clean
		);
		$clean = trim( (string) preg_replace( '/\s+/', ' ', $clean ), " \t-,\xE2\x80\x93\xE2\x80\x94" );
		return array(
			'name'    => '' !== $clean ? $clean : $name,
			'slug'    => (string) $size['slug'],
			'label'   => (string) $size['label'],
			'written' => trim( (string) $written ),
			'lifted'  => true,
			'cell'    => false,
		);
	}

	/**
	 * Words that look like a size but belong to the name.
	 *
	 * @param string $text A word or a parenthesis' content.
	 * @return bool
	 */
	public static function is_name_word( $text ) {
		return in_array( strtolower( trim( (string) $text, " \t.()" ) ), array( 'inv', 'invert', 'new', 'rare', 'male', 'female', 'mated pair', 'pair', 'bonded pair', 'm/f', 'f/m' ), true );
	}

	/**
	 * Whether a text is a measure: "10-14 CM", "6-9,5 cm", '2-3"'.
	 *
	 * @param string $text Text.
	 * @return bool
	 */
	public static function is_measure( $text ) {
		return 1 === preg_match( '/^\s*\d+(?:[.,]\d+)?(?:\s*-\s*\d+(?:[.,]\d+)?)?\s*(?:"|\x{201D}|in\.?|inch(?:es)?|cm|mm)\s*$/iu', (string) $text );
	}

	/**
	 * A name as the matcher reads it: parentheses, size words at its end
	 * and the catalogue's shouting ("NEW", "RARE") left aside. Never
	 * stored — the line keeps the name as typed.
	 *
	 * @param string $name The fish as typed.
	 * @return string
	 */
	public static function match_text( $name ) {
		$text = (string) preg_replace( '/\([^()]*\)/', ' ', (string) $name );
		$text = (string) preg_replace( '/\b(new|rare)\b!?/i', ' ', $text );
		for ( $i = 0; $i < 2; $i++ ) {
			if ( 1 === preg_match( '#^(.*\S)(?:\s+[-\x{2013}\x{2014}]\s*|,\s*|\s+)([A-Za-z]{1,8}(?:\s*/\s*[A-Za-z]{1,8})?)\s*$#u', trim( $text ), $m ) && '' !== self::size_word( $m[2] ) && ! self::is_name_word( $m[2] ) ) {
				$text = $m[1];
			}
		}
		return trim( (string) preg_replace( '/\s+/', ' ', $text ), " \t-,\xE2\x80\x93\xE2\x80\x94" );
	}

	/* ============================================================== */
	/*  The matcher                                                    */
	/* ============================================================== */

	/**
	 * Index the candidates (Store::species_candidates(): products, the
	 * species list, scientific names, learned aliases) once per import.
	 *
	 * @param array[] $candidates product_id, name, variants[] {text, kind}.
	 * @return array items, vocab (token → variants), exact (name key → item, kind), sci (key → items).
	 */
	public static function index_candidates( array $candidates ) {
		$index = array(
			'items' => array(),
			'vocab' => array(),
			'exact' => array(),
			'sci'   => array(),
		);
		foreach ( array_values( $candidates ) as $i => $candidate ) {
			$variants = isset( $candidate['variants'] ) && is_array( $candidate['variants'] ) ? $candidate['variants'] : array();
			if ( ! $variants && isset( $candidate['name'] ) ) {
				$variants[] = array(
					'text' => (string) $candidate['name'],
					'kind' => 'title',
				);
			}
			$kept = array();
			foreach ( $variants as $variant ) {
				$text = (string) ( $variant['text'] ?? '' );
				$kind = (string) ( $variant['kind'] ?? 'title' );
				$key  = Species::key( $text );
				if ( '' === $key ) {
					continue;
				}
				if ( 'sci' === $kind ) {
					$index['sci'][ $key ][ $i ] = true;
				}
				if ( ! isset( $index['exact'][ $key ][ $i ] ) || 'alias' === $kind ) {
					$index['exact'][ $key ][ $i ] = $kind;
				}
				$tokens = Parser::norm_tokens( Parser::tokenize( $text ) );
				if ( ! $tokens ) {
					continue;
				}
				$n      = count( $kept );
				$kept[] = array(
					'tokens' => $tokens,
					'phrase' => implode( ' ', $tokens ),
					'kind'   => $kind,
				);
				foreach ( $tokens as $token ) {
					$index['vocab'][ $token ][ $i . ':' . $n ] = array( $i, $n );
				}
			}
			$index['items'][ $i ] = array(
				'name'       => (string) ( $candidate['name'] ?? '' ),
				'product_id' => isset( $candidate['product_id'] ) && (int) $candidate['product_id'] > 0 ? (int) $candidate['product_id'] : null,
				'variants'   => $kept,
			);
		}
		return $index;
	}

	/**
	 * Score a typed name against the index, best first — the scores
	 * Parser::match_species() gives, reached through the vocabulary so
	 * only the candidates that share a word (or a near word) are scored.
	 *
	 * @param string $text  The name as the matcher reads it.
	 * @param array  $index index_candidates().
	 * @return array[] index, score, kind, name, product_id.
	 */
	public static function score( $text, array $index ) {
		$spoken = Parser::norm_tokens( array_slice( Parser::tokenize( $text ), 0, Parser::MAX_SPECIES_WORDS ) );
		if ( ! $spoken ) {
			return array();
		}
		$phrase = implode( ' ', $spoken );
		$probe  = $spoken;
		for ( $i = 0; $i + 1 < count( $spoken ); $i++ ) {
			$probe[] = $spoken[ $i ] . $spoken[ $i + 1 ];
		}

		$hits = array();
		foreach ( $index['vocab'] as $token => $where ) {
			foreach ( $probe as $word ) {
				if ( Parser::token_sim( $word, (string) $token ) > 0 ) {
					$hits += $where;
					break;
				}
			}
		}

		$best = array();
		foreach ( $hits as $pair ) {
			list( $i, $n ) = $pair;
			$variant       = $index['items'][ $i ]['variants'][ $n ];
			$weight        = isset( Parser::KIND_WEIGHTS[ $variant['kind'] ] ) ? Parser::KIND_WEIGHTS[ $variant['kind'] ] : 0.95;
			$score         = 'alias' === $variant['kind'] && $variant['phrase'] === $phrase ? 1.0 : Parser::token_score( $spoken, $variant['tokens'] ) * $weight;
			if ( ! isset( $best[ $i ] ) || $score > $best[ $i ]['score'] ) {
				$best[ $i ] = array(
					'index'      => $i,
					'score'      => $score,
					'kind'       => $variant['kind'],
					'name'       => $index['items'][ $i ]['name'],
					'product_id' => $index['items'][ $i ]['product_id'],
				);
			}
		}
		$scored = array();
		foreach ( $best as $row ) {
			if ( $row['score'] > 0.2 ) {
				$scored[] = $row;
			}
		}
		usort(
			$scored,
			static function ( $a, $b ) {
				if ( abs( $a['score'] - $b['score'] ) > 0.0001 ) {
					return $b['score'] <=> $a['score'];
				}
				$rank = array(
					'alias'  => 0,
					'stay'   => 1,
					'title'  => 2,
					'common' => 3,
					'sci'    => 4,
				);
				$ra   = isset( $rank[ $a['kind'] ] ) ? $rank[ $a['kind'] ] : 5;
				$rb   = isset( $rank[ $b['kind'] ] ) ? $rank[ $b['kind'] ] : 5;
				return $ra <=> $rb ? $ra <=> $rb : $a['index'] <=> $b['index'];
			}
		);
		return $scored;
	}

	/**
	 * Match one typed fish.
	 *
	 *   exact      the text is a name the lists know (a title, a common
	 *              name, a scientific name) → matched;
	 *   alias      the text is a learned alias → matched;
	 *   close      one candidate clearly ahead (a typo within two letters,
	 *              a word order) → matched;
	 *   sci        the fish column matched nothing surely, the scientific
	 *              name is exactly one candidate's → matched;
	 *   weak       the best candidate is not good enough → flagged, NOT
	 *              chosen: the line is the text as typed until the owner
	 *              picks;
	 *   ambiguous  two candidates as good as each other → flagged, not
	 *              chosen;
	 *   none       nothing in the lists comes near → as typed, an
	 *              unlisted fish;
	 *   unknown    no name at all, or a name typed "??…" → an unknown
	 *              fish.
	 *
	 * @param string $name  The fish as typed (after lift_size()).
	 * @param string $sci   The scientific name, when the file has one.
	 * @param array  $index index_candidates().
	 * @return array{kind: string, flag: bool, pick: int|null, candidates: array[]}
	 */
	public static function match( $name, $sci, array $index ) {
		$out = array(
			'kind'       => 'none',
			'flag'       => false,
			'pick'       => null,
			'candidates' => array(),
		);
		$name = trim( (string) $name );
		if ( '' === $name || Identify::is_unknown_name( $name ) ) {
			$out['kind'] = 'unknown';
			$out['flag'] = true;
			return $out;
		}

		$text  = self::match_text( $name );
		$exact = array();
		foreach ( array_unique( array( Species::key( $name ), Species::key( $text ) ) ) as $key ) {
			if ( '' !== $key && isset( $index['exact'][ $key ] ) ) {
				$exact += $index['exact'][ $key ];
			}
		}
		$scored = self::score( '' !== $text ? $text : $name, $index );

		$list = array();
		$add  = static function ( $i ) use ( &$list, $index ) {
			if ( isset( $index['items'][ $i ] ) && ! isset( $list[ $i ] ) && count( $list ) < self::MAX_CANDIDATES ) {
				$list[ $i ] = array(
					'name'       => $index['items'][ $i ]['name'],
					'product_id' => $index['items'][ $i ]['product_id'],
				);
			}
		};
		foreach ( array_keys( $exact ) as $i ) {
			$add( $i );
		}
		foreach ( $scored as $row ) {
			$add( $row['index'] );
		}

		$by_sci = array();
		$sci    = Species::key( (string) $sci );
		if ( '' !== $sci && isset( $index['sci'][ $sci ] ) ) {
			$by_sci = array_keys( $index['sci'][ $sci ] );
			foreach ( $by_sci as $i ) {
				$add( $i );
			}
		}

		$positions = array_keys( $list );
		$pick      = static function ( $i ) use ( $positions ) {
			$at = array_search( $i, $positions, true );
			return false === $at ? null : (int) $at;
		};

		if ( 1 === count( $exact ) ) {
			$i           = (int) key( $exact );
			$out['kind'] = 'alias' === $exact[ $i ] ? 'alias' : 'exact';
			$out['pick'] = $pick( $i );
		} elseif ( count( $exact ) > 1 ) {
			$out['kind'] = 'ambiguous';
			$out['flag'] = true;
		} elseif ( $scored && $scored[0]['score'] >= self::SCORE_SURE && ( ! isset( $scored[1] ) || $scored[0]['score'] - $scored[1]['score'] >= self::SCORE_GAP ) ) {
			$out['kind'] = 'close';
			$out['pick'] = $pick( $scored[0]['index'] );
		} elseif ( 1 === count( $by_sci ) ) {
			$out['kind'] = 'sci';
			$out['pick'] = $pick( $by_sci[0] );
		} elseif ( $scored ) {
			$out['kind'] = $scored[0]['score'] >= self::SCORE_SURE ? 'ambiguous' : 'weak';
			$out['flag'] = true;
		}
		if ( null === $out['pick'] && in_array( $out['kind'], array( 'exact', 'alias', 'close', 'sci' ), true ) ) {
			$out['kind'] = 'weak'; // Never a match nobody can see in the dropdown.
			$out['flag'] = true;
		}

		$out['candidates'] = array_values( $list );
		return $out;
	}

	/* ============================================================== */
	/*  Rows                                                           */
	/* ============================================================== */

	/**
	 * Whether a text is a fee line of an invoice — packing, freight,
	 * clearance, re-oxygen, heat packs, a surcharge, a tariff — and which
	 * of the order's two fee fields it belongs to: 'tariffs', or 'import'
	 * for everything else. Null for a fish. Words a fish's own name may
	 * carry ("CITES", "Pallet") are no fee words; and analyse() never takes
	 * a row with a supplier's code or a scientific name for a fee.
	 *
	 * @param string $text A row's text.
	 * @return string|null
	 */
	public static function fee_kind( $text ) {
		$text = strtolower( (string) $text );
		if ( '' === trim( $text ) ) {
			return null;
		}
		if ( 1 === preg_match( '/\b(tariffs?|dut(y|ies)|customs duty)\b/', $text ) ) {
			return 'tariffs';
		}
		if ( 1 === preg_match( '/\b(packing|box charges?|boxes? fee|carton charges?|freight|air ?freight|clearance|handling|re-?ox(ygen(ation)?)?|re-?water|heat ?packs?|ice ?packs?|cool ?packs?|fuel|surcharge|documentation|documents? fee|health cert(ificate)?|inspection|broker(age)?|awb|air waybill|service fee|bank charges?|wire fee|styro(foam)?)\b/', $text ) ) {
			return 'import';
		}
		return null;
	}

	/**
	 * Whether a text is a totals line, and which: 'subtotal' (the
	 * livestock subtotal the rows are checked against) or 'total'.
	 *
	 * @param string $text A row's text.
	 * @return string|null
	 */
	public static function total_kind( $text ) {
		$text = strtolower( trim( (string) $text ) );
		if ( 1 === preg_match( '/^(live ?stock|fish|goods|merchandise)\s+(sub[\s-]*)?total\b|^sub[\s-]*total\b|^total\s+(live ?stock|fish|goods)\b/', $text ) ) {
			return 'subtotal';
		}
		if ( 1 === preg_match( '/^(grand |invoice |order |net |final )?total\b|^(amount|balance) due\b|^total$/', $text ) ) {
			return 'total';
		}
		return null;
	}

	/**
	 * A box heading of an invoice — "BOX NO 3 — Somewhere", "Box 2" — as
	 * the box number and the origin it names, or null.
	 *
	 * @param string $text A row's text.
	 * @return array{box: int, origin: string}|null
	 */
	public static function box_heading( $text ) {
		if ( 1 !== preg_match( '/^\s*box\s*(?:no\.?|number|nr\.?|#)?\s*:?\s*(\d{1,3})\s*(?:[-\x{2013}\x{2014}:,]\s*(.*))?$/iu', (string) $text, $m ) ) {
			return null;
		}
		return array(
			'box'    => (int) $m[1],
			'origin' => isset( $m[2] ) ? trim( $m[2] ) : '',
		);
	}

	/**
	 * Read the grid with a column map.
	 *
	 * Left out, each counted and named: blank rows, a repeated header
	 * row, a totals line, a section heading ("FISH", "INVERTS", "BOX NO 3
	 * — Somewhere": a row with a text and no number), a fee line (kept
	 * apart — a fee is NEVER a fish line), a row the owner taught the
	 * layout to leave out ($opts['junk']), and — in the ordered mode — a
	 * price-list row without an order quantity (counted, not listed).
	 *
	 * A box number or an origin that is filled only on a box's first row,
	 * or stands in a heading above it, is carried down.
	 *
	 * @param string[][] $grid The grid.
	 * @param array      $opts mode, map (column → role), header_row (int|null), junk (string[]),
	 *                         sizes (the standard list).
	 * @return array rows, left_out, fees, subtotal, total, sections, data_rows, with_qty, received.
	 */
	public static function analyse( array $grid, array $opts ) {
		$mode   = isset( $opts['mode'] ) && 'arrived' === $opts['mode'] ? 'arrived' : 'ordered';
		$map    = isset( $opts['map'] ) && is_array( $opts['map'] ) ? $opts['map'] : array();
		$header = isset( $opts['header_row'] ) && null !== $opts['header_row'] ? (int) $opts['header_row'] : null;
		$sizes  = isset( $opts['sizes'] ) && is_array( $opts['sizes'] ) ? $opts['sizes'] : array();
		$junk   = array();
		foreach ( isset( $opts['junk'] ) && is_array( $opts['junk'] ) ? $opts['junk'] : array() as $word ) {
			$junk[ Species::key( (string) $word ) ] = true;
		}
		$col = array_flip( $map );
		$get = static function ( array $row, $role ) use ( $col ) {
			return isset( $col[ $role ], $row[ $col[ $role ] ] ) ? (string) $row[ $col[ $role ] ] : '';
		};

		$out = array(
			'rows'      => array(),
			'left_out'  => array(),
			'fees'      => array(),
			'subtotal'  => null,
			'total'     => null,
			'sections'  => array(),
			'data_rows' => 0,
			'with_qty'  => 0,
			'received'  => 0, // Rows with a received count above 0 — a sheet of what ARRIVED has those.
		);

		$box     = '';
		$origin  = '';
		$fee_map = null; // Set once the block's second part (fee, amount, origin) starts.

		foreach ( $grid as $n => $row ) {
			if ( null !== $header && $n <= $header ) {
				continue;
			}
			$filled = array();
			foreach ( $row as $index => $text ) {
				if ( '' !== $text ) {
					$filled[ $index ] = $text;
				}
			}
			if ( ! $filled ) {
				continue;
			}
			$first = (string) reset( $filled );
			$fish  = $get( $row, 'fish' );
			$sci   = $get( $row, 'sci' );
			$code  = $get( $row, 'code' );
			$text  = '' !== $fish ? $fish : $first;
			$leave = static function ( $reason ) use ( &$out, $n, &$text ) {
				$out['left_out'][] = array(
					'n'      => (int) $n,
					'reason' => $reason,
					'text'   => $text,
				);
			};
			$last_number = static function () use ( $filled ) {
				foreach ( array_reverse( $filled ) as $value ) {
					$number = self::number( $value );
					if ( 'ok' === $number['state'] ) {
						return $number['value'];
					}
				}
				return null;
			};

			// The block's second part: "fee, amount, origin".
			$keys = array_map( array( self::class, 'header_key' ), $filled );
			if ( in_array( 'fee', $keys, true ) && in_array( 'amount', $keys, true ) ) {
				$fee_map = array();
				foreach ( $keys as $index => $key ) {
					if ( 'fee' === $key || 'amount' === $key ) {
						$fee_map[ $key ] = $index;
					} elseif ( in_array( self::header_role( $key ), array( 'origin', 'box' ), true ) ) {
						$fee_map['origin'] = $index;
					}
				}
				$leave( 'header' );
				continue;
			}
			if ( null !== $fee_map ) {
				$name   = isset( $row[ $fee_map['fee'] ] ) ? (string) $row[ $fee_map['fee'] ] : '';
				$amount = self::number( isset( $row[ $fee_map['amount'] ] ) ? $row[ $fee_map['amount'] ] : '' );
				$total  = self::total_kind( $name );
				if ( null !== $total ) {
					if ( 'ok' === $amount['state'] ) {
						$out[ $total ] = $amount['value'];
					}
					$text = $name;
					$leave( 'total' );
					continue;
				}
				if ( '' === $name ) {
					continue;
				}
				$text = $name;
				if ( 'ok' !== $amount['state'] && null === self::fee_kind( $name ) ) {
					// Neither an amount nor a fee's words: whatever was written after the table.
					$leave( 'other' );
					continue;
				}
				$out['fees'][] = array(
					'n'      => (int) $n,
					'name'   => $name,
					'amount' => 'ok' === $amount['state'] ? $amount['value'] : null,
					'kind'   => 'tariffs' === self::fee_kind( $name ) ? 'tariffs' : 'import',
					'origin' => isset( $fee_map['origin'], $row[ $fee_map['origin'] ] ) ? (string) $row[ $fee_map['origin'] ] : '',
					'check'  => 'ok' !== $amount['state'] || 1 === preg_match( '/\?|\bcheck\b/i', $name ),
				);
				$leave( 'fee' );
				continue;
			}

			// A repeated header row (a second page of the invoice).
			$again = self::roles_of_row( $row );
			if ( count( $again ) >= 2 && count( array_intersect_assoc( $again, $map ) ) >= 2 ) {
				$leave( 'header' );
				continue;
			}

			// "BOX NO 3 — Somewhere": the rows under it are in that box, from there.
			$heading = count( $filled ) <= 2 ? self::box_heading( $first ) : null;
			if ( $heading ) {
				$box = (string) $heading['box'];
				if ( '' !== $heading['origin'] ) {
					$origin = $heading['origin'];
				}
				$text = $first;
				$leave( 'heading' );
				continue;
			}

			$total = self::total_kind( $text );
			if ( null === $total && '' === $fish ) {
				foreach ( $filled as $value ) {
					$total = self::total_kind( $value );
					if ( null !== $total ) {
						$text = $value;
						break;
					}
				}
			}
			if ( null !== $total ) {
				$value = $last_number();
				if ( null !== $value ) {
					$out[ $total ] = $value;
				}
				$leave( 'total' );
				continue;
			}

			// A fee line: its words stand in the fish column — or, on a row without a fish, in whichever
			// cell holds text (an invoice writes "Freight (kg)" under any heading). A row that carries the
			// supplier's code, or a scientific name beside its fish, is a fish whatever its name says
			// ("… (CITES)"): it stays, with a flag.
			$fee      = null;
			$fee_word = false;
			foreach ( '' !== $fish ? array( $fish ) : $filled as $value ) {
				if ( 'ok' === self::number( $value )['state'] ) {
					continue;
				}
				$fee = self::fee_kind( $value );
				if ( null !== $fee ) {
					$text = '' !== $fish ? $fish : (string) $value;
					break;
				}
			}
			if ( null !== $fee && ( '' !== $code || ( '' !== $fish && '' !== $sci && null === self::fee_kind( $sci ) ) ) ) {
				$fee      = null;
				$fee_word = true;
				$text     = '' !== $fish ? $fish : $first;
			}
			if ( null !== $fee ) {
				$own = $get( $row, 'origin' );

				// The amount: the line total; else price × quantity; else the last number that is not a
				// count, a box or a percent.
				$amount = self::number( $get( $row, 'amount' ) );
				$value  = 'ok' === $amount['state'] ? $amount['value'] : null;
				if ( null === $value ) {
					$price = self::number( $get( $row, 'price' ) );
					if ( 'ok' === $price['state'] ) {
						$times = 1;
						foreach ( self::QTY_ROLES as $role ) {
							$read = self::quantity( $get( $row, $role ) );
							if ( 'ok' === $read['state'] && $read['value'] > 0 ) {
								$times = $read['value'];
								break;
							}
						}
						$value = round( $price['value'] * $times, 2 );
					}
				}
				if ( null === $value ) {
					foreach ( array_reverse( $filled, true ) as $index => $cell ) {
						if ( isset( $map[ $index ] ) && ! in_array( $map[ $index ], array( 'fish', 'sci', 'label' ), true ) ) {
							continue;
						}
						$number = self::number( $cell );
						if ( 'ok' === $number['state'] ) {
							$value = $number['value'];
							break;
						}
					}
				}

				$out['fees'][] = array(
					'n'      => (int) $n,
					'name'   => $text,
					'amount' => $value,
					'kind'   => $fee,
					'origin' => '' !== $own ? $own : $origin,
					'check'  => null === $value,
				);
				$leave( 'fee' );
				continue;
			}

			// A packing list that names its fish by scientific name alone: that name is the fish.
			$stood_in = false;
			if ( '' === $fish && '' !== $sci ) {
				$fish     = $sci;
				$text     = $sci;
				$stood_in = true;
			}

			if ( '' !== $fish && isset( $junk[ Species::key( $fish ) ] ) ) {
				$leave( 'learned' );
				continue;
			}

			// The numbers of the row.
			$cells = array();
			$flags = array();
			foreach ( array( 'ordered', 'received', 'qty', 'doa', 'per_box', 'bags', 'survived' ) as $role ) {
				if ( ! isset( $col[ $role ] ) ) {
					continue;
				}
				$read = self::quantity( $get( $row, $role ) );
				if ( 'bad' === $read['state'] ) {
					$flags[ $role ] = true;
				}
				$cells[ $role ] = $read['value'];
			}
			foreach ( array( 'price', 'amount', 'worth' ) as $role ) {
				if ( ! isset( $col[ $role ] ) ) {
					continue;
				}
				$read = self::number( $get( $row, $role ) );
				if ( 'bad' === $read['state'] ) {
					$flags[ $role ] = true;
				}
				$cells[ $role ] = $read['value'];
			}

			$quantity_typed = false;
			foreach ( self::QTY_ROLES as $role ) {
				if ( '' !== $get( $row, $role ) ) {
					$quantity_typed = true;
				}
			}
			$others = '' !== $code || ( '' !== $sci && ! $stood_in ) || isset( $cells['price'] ) || isset( $cells['amount'] );

			// A text and nothing else: a section heading ("FISH", "INVERTS").
			if ( ! $quantity_typed && ! $others ) {
				if ( '' !== $fish || 1 === count( $filled ) ) {
					$leave( 'heading' );
				}
				continue;
			}

			$out['data_rows']++;
			if ( isset( $cells['received'] ) && $cells['received'] >= 1 ) {
				$out['received']++;
			}

			// An "order" column, when the sheet has one, is the only ordered count — a "qty" beside it may
			// be anything (the supplier's stock as a number); likewise "received" for what arrived.
			$pick     = static function ( array $roles ) use ( $col, $cells ) {
				foreach ( $roles as $role ) {
					if ( isset( $col[ $role ] ) ) {
						return isset( $cells[ $role ] ) ? $cells[ $role ] : null;
					}
				}
				return null;
			};
			$ordered  = $pick( array( 'ordered', 'qty', 'received' ) );
			$received = isset( $cells['received'] ) ? $cells['received'] : null;
			$arrived  = $pick( array( 'received', 'qty', 'ordered' ) );
			$counted  = 'arrived' === $mode ? $arrived : $ordered;
			$bad_qty  = ! empty( $flags['ordered'] ) || ! empty( $flags['qty'] ) || ! empty( $flags['received'] );

			if ( ( null === $counted || $counted < 1 ) && ! $bad_qty ) {
				// A price-list row nobody ordered (counted, not listed) — or, on an invoice, a fish
				// with no count: left out, and named so the owner can look at the invoice.
				if ( 'arrived' === $mode && '' !== $fish ) {
					$leave( 'zero' );
				}
				continue;
			}
			$out['with_qty']++;

			// The box and the origin: the row's own, else the one above.
			$own_box = $get( $row, 'box' );
			if ( '' !== $own_box ) {
				$named = self::box_heading( $own_box );
				$box   = $named ? (string) $named['box'] : $own_box;
				if ( $named && '' !== $named['origin'] ) {
					$origin = $named['origin'];
				}
			}
			$own_origin = $get( $row, 'origin' );
			if ( '' !== $own_origin ) {
				$origin = $own_origin;
			}
			$box_no = Costing::box_no_in( $box );
			if ( '' !== $own_box && null === $box_no ) {
				$flags['box'] = true;
			}

			$pct_text = $get( $row, 'pct' );
			$pct      = '' !== $pct_text ? Costing::box_pct_in( self::is_formula( $pct_text ) ? '' : $pct_text ) : null;
			if ( '' !== $pct_text && null === $pct && ! in_array( $pct_text, array( '0', '0%' ), true ) ) {
				$flags['pct'] = true;
			}

			$size = self::lift_size( $fish, $get( $row, 'size' ), $sizes );
			if ( '' !== $origin ) {
				$out['sections'][ $origin ] = isset( $out['sections'][ $origin ] ) ? $out['sections'][ $origin ] + 1 : 1;
			}

			$check = $get( $row, 'check' );

			$out['rows'][] = array(
				'n'        => (int) $n,
				'typed'    => $fish,
				'name'     => $size['name'],
				'size'     => $size,
				'sci'      => self::cut( $sci, 120 ),
				'code'     => self::cut( $code, 40 ),
				'ordered'  => null !== $ordered ? (int) $ordered : null,
				'received' => null !== $received ? (int) $received : null,
				'arrived'  => null !== $arrived ? (int) $arrived : null,
				'price'    => isset( $cells['price'] ) ? $cells['price'] : null,
				'amount'   => isset( $cells['amount'] ) ? $cells['amount'] : null,
				'worth'    => isset( $cells['worth'] ) ? $cells['worth'] : null,
				'doa'      => isset( $cells['doa'] ) ? (int) $cells['doa'] : null,
				'survived' => isset( $cells['survived'] ) ? (int) $cells['survived'] : null,
				'per_box'  => isset( $cells['per_box'] ) && $cells['per_box'] >= 1 ? min( Species::MAX_PER_BOX, (int) $cells['per_box'] ) : null,
				'bags'     => isset( $cells['bags'] ) && $cells['bags'] >= 1 ? (int) $cells['bags'] : null,
				'box'      => $box_no,
				'pct'      => $pct,
				'tank'     => $get( $row, 'tank' ),
				'label'    => self::cut( $get( $row, 'label' ), Identify::MAX_NAME ),
				'origin'   => $origin,
				'check'    => '' !== $check && 1 === preg_match( '/\?|check|unsure|uncertain/i', $check ),
				'fee_word' => $fee_word,
				'flags'    => array_keys( $flags ),
				'bad_qty'  => $bad_qty,
			);
		}

		return $out;
	}

	/**
	 * The words of a left-out reason.
	 *
	 * @param string $reason analyse()'s reason.
	 * @return string
	 */
	public static function reason_text( $reason ) {
		switch ( $reason ) {
			case 'header':
				return __( 'a header row', 'fishotel-misc-plugin' );
			case 'total':
				return __( 'a totals line', 'fishotel-misc-plugin' );
			case 'heading':
				return __( 'a heading', 'fishotel-misc-plugin' );
			case 'learned':
				return __( 'left out as last time', 'fishotel-misc-plugin' );
			case 'zero':
				return __( 'no count', 'fishotel-misc-plugin' );
			case 'fee':
				return __( 'a fee line, not a fish', 'fishotel-misc-plugin' );
			case 'section':
				return __( 'another section', 'fishotel-misc-plugin' );
			case 'other':
				return __( 'not part of the table', 'fishotel-misc-plugin' );
		}
		return __( 'left out', 'fishotel-misc-plugin' );
	}

	/* ============================================================== */
	/*  Notes                                                          */
	/* ============================================================== */

	/**
	 * The supplier's code a line carries in its notes ("[code AB-12]"), or ''.
	 *
	 * @param string $notes A line's notes.
	 * @return string
	 */
	public static function code_of( $notes ) {
		return 1 === preg_match( '/' . preg_quote( self::NOTE_CODE, '/' ) . '([^\]]{1,40})\]/', (string) $notes, $m ) ? trim( $m[1] ) : '';
	}

	/**
	 * A supplier's code as it is compared: lower case, without spaces and
	 * without leading zeros — a spreadsheet turns "0059" into 59 on its
	 * own, and both are the same code.
	 *
	 * @param mixed $code A code as written.
	 * @return string
	 */
	public static function code_key( $code ) {
		$key  = strtolower( (string) preg_replace( '/\s+/', '', is_scalar( $code ) ? (string) $code : '' ) );
		$bare = ltrim( $key, '0' );
		return '' !== $bare ? $bare : $key;
	}

	/**
	 * What a line's notes say it was written as ("[as written: …]"), or ''.
	 *
	 * @param string $notes A line's notes.
	 * @return string
	 */
	public static function written_of( $notes ) {
		return 1 === preg_match( '/' . preg_quote( self::NOTE_WRITTEN, '/' ) . '([^\]]{1,200})\]/', (string) $notes, $m ) ? trim( $m[1] ) : '';
	}

	/**
	 * Notes with one tag set (an older one of the same kind replaced), at
	 * most 500 characters — the tags first, so a long free note never
	 * pushes the code out.
	 *
	 * @param string $notes The notes.
	 * @param string $open  The tag's opening, e.g. NOTE_CODE.
	 * @param string $value Its value ('' removes the tag).
	 * @return string
	 */
	public static function note_with( $notes, $open, $value ) {
		$notes = trim( (string) preg_replace( '/\s*' . preg_quote( $open, '/' ) . '[^\]]*\]/', '', (string) $notes ) );
		$value = trim( str_replace( array( '[', ']' ), array( '(', ')' ), (string) $value ) );
		if ( '' !== $value ) {
			$notes = trim( $open . $value . '] ' . $notes );
		}
		return self::cut( $notes, 500 );
	}

	/* ============================================================== */
	/*  Arrived: the reconciliation                                    */
	/* ============================================================== */

	/**
	 * Pair the arrived rows with the lines already on the order.
	 *
	 * A row is paired, in turn: as the owner said (a pairing made by hand
	 * wins); by the supplier's code when the row and a line both carry
	 * one (code_key(): "0059" and "59" are one code) — unless the row's
	 * fish IS another line of the order, which then takes it, said so;
	 * by fish — the same name, the line's species-list fish or what the
	 * line was written as — and then by size when both have one. A row
	 * without a size against lines of two sizes is paired with neither:
	 * it waits among the extras for the owner to say which. A line that
	 * already became a stay is never changed: a row that pairs with it is
	 * set aside and said so.
	 *
	 * The same fish in two boxes is two rows: summed onto the one ordered
	 * line (the boxes noted) unless $opts['same_fish'] is 'separate' — the
	 * first row then fills the line and the others are extras.
	 *
	 * The groups:
	 *   same     came as ordered;
	 *   diff     came short or over ("ordered 12 · arrived 8");
	 *   missing  ordered lines no row matched — received becomes 0 and
	 *            the line STAYS, so the fill rate and the supplier's
	 *            record are true;
	 *   extra    rows no ordered line matched — a new line with ordered 0,
	 *            or "this came instead of …" a line the owner names (that
	 *            line stays at 0 received; both carry a note);
	 *   stay     rows whose line is already checked in — left alone.
	 *
	 * @param array[] $rows  analyse() rows, each with 'keys' (the names it may go by, as
	 *                       Species::key()), 'species_id' (its match's, or null) and 'on'.
	 * @param array[] $lines The order's lines: key, species, species_id, size_slug, qty_ordered,
	 *                       qty_received, unit_price, qty_doa, box_no, box_pct, stay_id, notes.
	 * @param array   $opts  pairs (row n → 'new' | 'line:KEY' | 'instead:KEY'), same_fish
	 *                       (sum|separate), lines (line key → 'zero' | 'keep' for a line no row
	 *                       matched; by default a line that already holds a received count, or
	 *                       was never ordered, is kept as it is and said so).
	 * @return array pairs, groups, foot.
	 */
	public static function reconcile( array $rows, array $lines, array $opts = array() ) {
		$pairs    = isset( $opts['pairs'] ) && is_array( $opts['pairs'] ) ? $opts['pairs'] : array();
		$separate = isset( $opts['same_fish'] ) && 'separate' === $opts['same_fish'];
		$said_of  = isset( $opts['lines'] ) && is_array( $opts['lines'] ) ? $opts['lines'] : array();

		$by_key  = array();
		$by_code = array();
		foreach ( $lines as $line ) {
			$key            = (string) $line['key'];
			$by_key[ $key ] = $line;
			$code           = self::code_key( self::code_of( (string) ( $line['notes'] ?? '' ) ) );
			if ( '' !== $code ) {
				$by_code[ $code ][] = $key;
			}
		}

		$taken   = array(); // line key → rows paired with it.
		$extra   = array(); // rows no line matched.
		$instead = array(); // row n → line key.
		$how     = array();
		$clash   = array(); // row n → true: paired by its fish although its code is another line's.
		$could   = array(); // row n → the lines it could be (a row without a size, lines of two sizes).

		foreach ( $rows as $row ) {
			if ( empty( $row['on'] ) ) {
				continue;
			}
			$n    = (int) $row['n'];
			$said = isset( $pairs[ $n ] ) ? (string) $pairs[ $n ] : '';
			if ( 'new' === $said ) {
				$extra[] = $n;
				continue;
			}
			if ( 0 === strpos( $said, 'instead:' ) && isset( $by_key[ substr( $said, 8 ) ] ) && empty( $by_key[ substr( $said, 8 ) ]['stay_id'] ) ) {
				$extra[]       = $n;
				$instead[ $n ] = substr( $said, 8 );
				continue;
			}
			$found = null;
			if ( 0 === strpos( $said, 'line:' ) && isset( $by_key[ substr( $said, 5 ) ] ) ) {
				$found     = substr( $said, 5 );
				$how[ $n ] = 'hand';
			}
			if ( null === $found ) {
				$same = array();
				foreach ( $lines as $line ) {
					if ( self::same_fish( $row, $line ) ) {
						$same[] = (string) $line['key'];
					}
				}
				$code  = self::code_key( $row['code'] ?? '' );
				$coded = '' !== $code && isset( $by_code[ $code ] ) ? $by_code[ $code ] : array();
				$both  = array_values( array_intersect( $coded, $same ) );
				$among = array();
				if ( $coded && ( $both || ! $same ) ) {
					// The supplier's code — and nothing on the row says another line.
					$among     = $both ? $both : $coded;
					$how[ $n ] = 'code';
				} elseif ( $same ) {
					// By fish. A row that IS another line's fish goes to that line, whatever its code says.
					$among     = $same;
					$how[ $n ] = 'fish';
					if ( $coded ) {
						$clash[ $n ] = true;
					}
				}
				if ( $among ) {
					// A row without a size: the line without a size is its line. Against lines of two
					// sizes and none without, nobody can say which — the owner does.
					$slugs = array();
					foreach ( $among as $key ) {
						$slugs[ (string) ( $by_key[ $key ]['size_slug'] ?? '' ) ][] = $key;
					}
					if ( '' === (string) ( $row['size']['slug'] ?? '' ) && count( $slugs ) > 1 ) {
						$among = isset( $slugs[''] ) ? $slugs[''] : array();
						if ( ! $among ) {
							$could[ $n ] = call_user_func_array( 'array_merge', array_values( $slugs ) );
						}
					}
					if ( $among ) {
						$found = self::best_line( $row, $among, $by_key, $taken, $separate );
					}
				}
			}
			if ( null === $found || ( $separate && ! empty( $taken[ $found ] ) ) ) {
				$extra[] = $n;
				unset( $how[ $n ], $clash[ $n ] );
				continue;
			}
			$taken[ $found ][] = $n;
		}

		$row_of = array();
		foreach ( $rows as $row ) {
			$row_of[ (int) $row['n'] ] = $row;
		}

		$groups = array(
			'same'    => array(),
			'diff'    => array(),
			'missing' => array(),
			'extra'   => array(),
			'stay'    => array(),
		);
		$result  = array();
		$ordered = 0;
		$arrived = 0;
		$kinds   = 0;

		foreach ( $lines as $line ) {
			$key   = (string) $line['key'];
			$stay  = ! empty( $line['stay_id'] );
			$mine  = isset( $taken[ $key ] ) ? $taken[ $key ] : array();
			$want  = (int) ( $line['qty_ordered'] ?? 0 );
			$have  = isset( $line['qty_received'] ) && null !== $line['qty_received'] && '' !== $line['qty_received'] ? (int) $line['qty_received'] : null;
			$entry = array(
				'line'    => $key,
				'rows'    => $mine,
				'ordered' => $want,
				'was'     => $have,
				'how'     => $mine && isset( $how[ $mine[0] ] ) ? $how[ $mine[0] ] : '',
				'clash'   => (bool) array_intersect_key( $clash, array_flip( $mine ) ),
			);
			$ordered += $want;

			if ( $stay ) {
				$arrived += (int) $have;
				if ( $mine ) {
					$groups['stay'][] = $entry;
				}
				continue;
			}
			if ( ! $mine ) {
				// Nothing matched this line. Ordered and still empty: it did not come — received becomes 0.
				// A line that already holds a received count (an earlier part of the same delivery), or
				// that was never ordered, is kept as it is unless the owner says otherwise.
				$keep = isset( $said_of[ $key ] ) ? 'keep' === $said_of[ $key ] : ( (int) $have > 0 || $want < 1 );
				if ( $keep ) {
					$arrived      += (int) $have;
					$entry['keep'] = true;
				} else {
					$entry['arrived'] = 0;
					$result[ $key ]   = $entry;
					$kinds           += $want > 0 ? 1 : 0;
				}
				if ( $want > 0 ) {
					$groups['missing'][] = $entry;
				}
				continue;
			}

			$sum   = 0;
			$boxes = array();
			foreach ( $mine as $n ) {
				$sum += (int) ( $row_of[ $n ]['arrived'] ?? 0 );
				if ( ! empty( $row_of[ $n ]['box'] ) ) {
					$boxes[ (int) $row_of[ $n ]['box'] ] = true;
				}
			}
			$entry['arrived'] = min( Orders::MAX_QTY, $sum );
			$entry['boxes']   = array_keys( $boxes );
			$arrived         += $entry['arrived'];
			$result[ $key ]   = $entry;

			$groups[ $entry['arrived'] === $want ? 'same' : 'diff' ][] = $entry;
		}

		foreach ( $extra as $n ) {
			$entry = array(
				'row'     => $n,
				'arrived' => (int) ( $row_of[ $n ]['arrived'] ?? 0 ),
				'instead' => isset( $instead[ $n ] ) ? $instead[ $n ] : '',
				'could'   => isset( $could[ $n ] ) ? $could[ $n ] : array(),
			);
			$arrived          += $entry['arrived'];
			$groups['extra'][] = $entry;
		}

		return array(
			'lines'  => $result,
			'groups' => $groups,
			'extra'  => $extra,
			'foot'   => array(
				'ordered' => $ordered,
				'arrived' => $arrived,
				'filled'  => $ordered > 0 ? $arrived / $ordered : null,
				'missing' => $kinds,
			),
		);
	}

	/**
	 * Whether an arrived row and an order line are the same fish: one of
	 * the row's names is the line's name or what the line was written as,
	 * or both name the same species-list fish — and, when both have a
	 * size, the same size.
	 *
	 * @param array $row  keys, species_id, size.
	 * @param array $line species, species_id, size_slug, notes.
	 * @return bool
	 */
	public static function same_fish( array $row, array $line ) {
		$row_size  = isset( $row['size']['slug'] ) ? (string) $row['size']['slug'] : '';
		$line_size = (string) ( $line['size_slug'] ?? '' );
		if ( '' !== $row_size && '' !== $line_size && $row_size !== $line_size ) {
			return false;
		}
		if ( ! empty( $row['species_id'] ) && ! empty( $line['species_id'] ) && (int) $row['species_id'] === (int) $line['species_id'] ) {
			return true;
		}
		$names = array( Species::key( (string) ( $line['species'] ?? '' ) ) );
		$as    = self::written_of( (string) ( $line['notes'] ?? '' ) );
		if ( '' !== $as ) {
			$names[] = Species::key( $as );
			$names[] = Species::key( self::match_text( $as ) );
		}
		foreach ( isset( $row['keys'] ) && is_array( $row['keys'] ) ? $row['keys'] : array() as $key ) {
			if ( '' !== $key && in_array( $key, $names, true ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Among the lines a row may pair with: the one of its size, then one
	 * no other row has taken yet, then the first.
	 *
	 * @param array    $row      The row.
	 * @param string[] $keys     Candidate line keys.
	 * @param array[]  $by_key   The lines.
	 * @param array    $taken    line key → rows.
	 * @param bool     $separate Whether a line takes one row only.
	 * @return string|null
	 */
	private static function best_line( array $row, array $keys, array $by_key, array $taken, $separate ) {
		$size = isset( $row['size']['slug'] ) ? (string) $row['size']['slug'] : '';
		$best = null;
		$rank = -1;
		foreach ( $keys as $key ) {
			$line  = $by_key[ $key ];
			$score = 0;
			if ( '' !== $size && (string) ( $line['size_slug'] ?? '' ) === $size ) {
				$score += 4;
			} elseif ( '' !== $size && '' !== (string) ( $line['size_slug'] ?? '' ) ) {
				continue; // Another size of the same fish: another line.
			}
			if ( empty( $taken[ $key ] ) ) {
				$score += 2;
			}
			if ( empty( $line['stay_id'] ) ) {
				$score += 1;
			}
			if ( $score > $rank ) {
				$rank = $score;
				$best = $key;
			}
		}
		if ( null !== $best && $separate && ! empty( $taken[ $best ] ) ) {
			return null;
		}
		return $best;
	}

	/**
	 * The foot of the arrived preview, said the way the owner says it:
	 * "Ordered 150 · arrived 104 · 69% filled · 11 kinds did not come".
	 *
	 * @param array $foot reconcile()['foot'].
	 * @return string
	 */
	public static function foot_text( array $foot ) {
		/* translators: 1: fish ordered, 2: fish arrived */
		$text = sprintf( __( 'Ordered %1$s · arrived %2$s', 'fishotel-misc-plugin' ), number_format( (int) $foot['ordered'] ), number_format( (int) $foot['arrived'] ) );
		if ( null !== $foot['filled'] ) {
			/* translators: %d: percent of the order that arrived */
			$text .= ' · ' . sprintf( __( '%d%% filled', 'fishotel-misc-plugin' ), (int) round( $foot['filled'] * 100 ) );
		}
		if ( (int) $foot['missing'] > 0 ) {
			/* translators: %d: order lines */
			$text .= ' · ' . sprintf( _n( '%d kind did not come', '%d kinds did not come', (int) $foot['missing'], 'fishotel-misc-plugin' ), (int) $foot['missing'] );
		}
		return $text;
	}

	/* ============================================================== */
	/*  Fees                                                           */
	/* ============================================================== */

	/**
	 * The fees of the chosen sections, added up: the tariffs, and
	 * everything else (packing, freight, clearance, re-oxygen, heat
	 * packs, surcharges) for the order's "Import & re-ox fees". A fee
	 * that names no section belongs to the whole invoice and is counted.
	 *
	 * @param array[]       $fees     analyse()['fees'].
	 * @param string[]|null $sections The chosen sections (null = all).
	 * @return array{tariffs: float, import: float, count: int, left_out: int, check: int}
	 */
	public static function fee_sums( array $fees, $sections = null ) {
		$chosen = null;
		if ( is_array( $sections ) ) {
			$chosen = array();
			foreach ( $sections as $section ) {
				$chosen[ Species::key( (string) $section ) ] = true;
			}
		}
		$out = array(
			'tariffs'  => 0.0,
			'import'   => 0.0,
			'count'    => 0,
			'left_out' => 0,
			'check'    => 0,
		);
		foreach ( $fees as $fee ) {
			$origin = Species::key( (string) ( $fee['origin'] ?? '' ) );
			if ( null !== $chosen && '' !== $origin && ! isset( $chosen[ $origin ] ) ) {
				$out['left_out']++;
				continue;
			}
			$out['count']++;
			if ( null === $fee['amount'] ) {
				$out['check']++;
				continue;
			}
			$out[ 'tariffs' === $fee['kind'] ? 'tariffs' : 'import' ] += (float) $fee['amount'];
		}
		$out['tariffs'] = round( $out['tariffs'], 2 );
		$out['import']  = round( $out['import'], 2 );
		return $out;
	}

	/**
	 * What ticking "put these on the order" does to one fee field: an
	 * empty field (0) takes the invoice's amount; a field that holds a
	 * typed amount keeps it — unless the second tick says to replace it.
	 * Nothing is ever added to what is there.
	 *
	 * @param mixed $current The field as it is.
	 * @param float $amount  The invoice's amount.
	 * @param bool  $apply   The first tick.
	 * @param bool  $replace The second tick.
	 * @return float|null The new value, or null to leave the field alone.
	 */
	public static function fee_value( $current, $amount, $apply, $replace ) {
		$amount = round( (float) $amount, 2 );
		if ( ! $apply || $amount <= 0 ) {
			return null;
		}
		$now = Costing::money_in( $current );
		if ( $now > 0 && ! $replace ) {
			return null;
		}
		return min( Orders::MAX_MONEY, $amount );
	}

	/* ============================================================== */
	/*  The block a chat assistant is asked for                        */
	/* ============================================================== */

	/**
	 * The header line of the block, tab-separated.
	 *
	 * @return string
	 */
	public static function block_header() {
		return implode( "\t", self::BLOCK_HEADER );
	}

	/**
	 * The header line of the fee part, tab-separated.
	 *
	 * @return string
	 */
	public static function fee_header() {
		return implode( "\t", self::FEE_HEADER );
	}

	/**
	 * The instruction text the owner copies into a chat assistant
	 * together with the supplier's invoice (a PDF, a photo, an e-mail).
	 * Plain words; it names no product.
	 *
	 * @return string
	 */
	public static function instructions() {
		$lines = array(
			__( 'I am attaching a supplier\'s invoice or packing list for live fish. Please read it and give me back ONE block of plain text I can paste into my order system. Put the whole block inside one code block, so the tabs between the columns survive when I copy it. Nothing else: no explanation before or after it.', 'fishotel-misc-plugin' ),
			'',
			__( 'PART 1 — the fish. Tab-separated, one row per fish kind per box, with exactly this header row:', 'fishotel-misc-plugin' ),
			'',
			self::block_header(),
			'',
			__( 'What goes in each column:', 'fishotel-misc-plugin' ),
			__( '- fish: the common name exactly as the invoice writes it, with any size or note that is part of the name. Do not tidy it up or translate it. Blank when the invoice gives only a scientific name.', 'fishotel-misc-plugin' ),
			__( '- scientific name: as the invoice writes it; blank when it gives none.', 'fishotel-misc-plugin' ),
			__( '- size: only when the invoice has a size column of its own (S, M, M/L …); otherwise blank.', 'fishotel-misc-plugin' ),
			__( '- ordered: only when the invoice shows an ordered quantity next to the shipped one; otherwise blank.', 'fishotel-misc-plugin' ),
			__( '- received: the quantity shipped (the invoice\'s QTY column).', 'fishotel-misc-plugin' ),
			__( '- price: the price for ONE fish (the unit price), as a plain number with a decimal point. Not the line total.', 'fishotel-misc-plugin' ),
			__( '- DOA: only when the invoice lists dead-on-arrival fish; otherwise blank.', 'fishotel-misc-plugin' ),
			__( '- box: the box number the row is in. Invoices often print it only once, in a heading such as "BOX NO 3" or on the first row of the box: repeat it on every row of that box.', 'fishotel-misc-plugin' ),
			__( '- percent: leave blank.', 'fishotel-misc-plugin' ),
			__( '- label: leave blank.', 'fishotel-misc-plugin' ),
			__( '- code: the supplier\'s item code for the row, exactly as printed; blank when there is none.', 'fishotel-misc-plugin' ),
			__( '- origin: the country, station or section the box comes from when the invoice says so (often in the box heading, such as "BOX NO 3 — <origin>"); repeat it on every row of that box. Blank when the invoice does not say.', 'fishotel-misc-plugin' ),
			__( '- check: put a ? here on every row you are not sure about; otherwise blank.', 'fishotel-misc-plugin' ),
			'',
			__( 'Rules for part 1:', 'fishotel-misc-plugin' ),
			__( '- One row per fish kind per box. The same fish in two boxes is two rows.', 'fishotel-misc-plugin' ),
			__( '- Leave a cell blank when the invoice does not say. Never invent a name, a number or a price.', 'fishotel-misc-plugin' ),
			__( '- No totals, no subtotals, no headings and no fee lines in this part.', 'fishotel-misc-plugin' ),
			__( '- Real tabs between the columns, also for a blank cell — not spaces, and not a table drawn with | characters.', 'fishotel-misc-plugin' ),
			__( '- Skip pages that are not the invoice itself, such as an air waybill.', 'fishotel-misc-plugin' ),
			__( '- Read hand-written amounts too, and mark those rows with ? in the check column.', 'fishotel-misc-plugin' ),
			__( '- When a price or a total ran into the cell next to it and cannot be read for certain, put your best reading and a ? in the check column rather than guessing silently.', 'fishotel-misc-plugin' ),
			'',
			__( 'PART 2 — the fees. After one empty line, a second small table for everything on the invoice that is not a fish, with exactly this header row:', 'fishotel-misc-plugin' ),
			'',
			self::fee_header(),
			'',
			__( '- One row per fee line: packing or box charge, freight, clearance or handling, re-oxygen or re-water, heat or ice packs, a fuel surcharge (sometimes hand-written on a cover page), a tariff or duty line.', 'fishotel-misc-plugin' ),
			__( '- fee: the words of the line as printed. amount: a plain number. origin: the box or section the fee belongs to, when the invoice says; otherwise blank.', 'fishotel-misc-plugin' ),
			__( '- Then, when the invoice prints them, one row "Livestock subtotal" with the subtotal of the fish alone, and one row "Invoice total" with the invoice\'s total.', 'fishotel-misc-plugin' ),
			__( '- A hand-written amount: write it, and add "(check)" to the fee\'s words.', 'fishotel-misc-plugin' ),
		);
		return implode( "\n", $lines );
	}
}
