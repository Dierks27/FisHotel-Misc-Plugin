<?php
/**
 * Saved import layouts — "Is there a way to save it once it is mapped so
 * I don't have to map it every time. Also will it be able to tell that it
 * was imported from a different company so a different set of mapping
 * rules can apply?" Yes to both.
 *
 * A layout is what the owner told the line importer about one supplier's
 * sheet, kept for the next time:
 *
 *   supplier, origin   whose sheet it is (the origin when the order has one);
 *   moment             ordered (the order sheet) or arrived (the invoice) —
 *                      a supplier usually has one of each;
 *   header_row         the row the header stands on (1 = the first row);
 *                      0 for a sheet without a header;
 *   columns            each column's role, keyed by its heading's TEXT
 *                      (Order_Import::header_key()) — or, for a sheet
 *                      without a header, by position, with the column count;
 *   sheet              the sheet's name, for a workbook file;
 *   junk               the texts of the rows the owner said to leave out
 *                      every time (a heading the reader did not know);
 *   saved, used        dates.
 *
 * Recognising (recognise()), in this order: a layout of the order's
 * supplier for this moment whose headings fit the file → applied; else a
 * layout of ANOTHER supplier that fits the file's headings clearly better
 * than any of this supplier's → offered, never applied silently; else
 * nothing (the importer falls back on its own header finder, then on the
 * dropdowns). A layout that fits only partly — the sheet gained or lost a
 * column — is applied to the columns that still fit and says which did
 * not. A sheet without headings can only be recognised through the
 * order's supplier: positions say nothing about who sent it.
 *
 * Stored in one option beside the section's other settings
 * (fishotel_cleanroom_import_layouts, not autoloaded), bounded, sanitised
 * on the way in and on the way out. Nothing is seeded.
 *
 * @package FisHotel\Misc\Sections\Clean_Room
 */

namespace FisHotel\Misc\Sections\Clean_Room;

defined( 'ABSPATH' ) || exit;

/**
 * Class Import_Layouts
 */
class Import_Layouts {

	const OPTION = 'fishotel_cleanroom_import_layouts';

	const MAX_LAYOUTS = 40;
	const MAX_JUNK    = 30;

	/** A layout of the order's own supplier is applied from this fit up (below 1: partly). */
	const PARTIAL_MIN = 0.5;

	/** Another supplier's layout is offered from this fit up — and only this much ahead of the own. */
	const OFFER_MIN = 0.8;
	const OFFER_GAP = 0.3;

	/* ============================================================== */
	/*  The option                                                     */
	/* ============================================================== */

	/**
	 * Every saved layout, the last used first.
	 *
	 * @return array[]
	 */
	public static function all() {
		return self::sanitize( get_option( self::OPTION, array() ) );
	}

	/**
	 * Store the layouts (sanitised, capped).
	 *
	 * @param array[] $layouts Layouts.
	 * @return array[] As stored.
	 */
	public static function store( array $layouts ) {
		$layouts = self::sanitize( $layouts );
		update_option( self::OPTION, $layouts, false );
		return $layouts;
	}

	/**
	 * What identifies a layout: the supplier, the origin and the moment.
	 *
	 * @param string $supplier Supplier.
	 * @param string $origin   Origin.
	 * @param string $moment   ordered|arrived.
	 * @return string 12 hex.
	 */
	public static function id( $supplier, $origin, $moment ) {
		return substr( md5( Species::key( $supplier ) . '|' . Species::key( $origin ) . '|' . ( 'arrived' === $moment ? 'arrived' : 'ordered' ) ), 0, 12 );
	}

	/**
	 * Clean a list of layouts: junk dropped, one per supplier + origin +
	 * moment (the first wins), at most MAX_LAYOUTS, the last used first.
	 *
	 * @param mixed $raw Layouts.
	 * @return array[]
	 */
	public static function sanitize( $raw ) {
		$out = array();
		foreach ( is_array( $raw ) ? $raw : array() as $row ) {
			$layout = is_array( $row ) ? self::sanitize_one( $row ) : null;
			if ( null === $layout || isset( $out[ $layout['id'] ] ) ) {
				continue;
			}
			$out[ $layout['id'] ] = $layout;
		}
		$out = array_values( $out );
		usort(
			$out,
			static function ( $a, $b ) {
				return strcmp( $b['used'], $a['used'] ) ? strcmp( $b['used'], $a['used'] ) : strcmp( $a['supplier'] . $a['moment'], $b['supplier'] . $b['moment'] );
			}
		);
		return array_slice( $out, 0, self::MAX_LAYOUTS );
	}

	/**
	 * Clean one layout; null when it says nothing usable (no supplier, or
	 * no fish and quantity column).
	 *
	 * @param array $row A layout.
	 * @return array|null
	 */
	public static function sanitize_one( array $row ) {
		$text = static function ( $key, $max ) use ( $row ) {
			return isset( $row[ $key ] ) && is_scalar( $row[ $key ] ) && ! is_bool( $row[ $key ] ) ? Order_Import::cut( Order_Import::cell( $row[ $key ] ), $max ) : '';
		};
		$supplier = $text( 'supplier', 80 );
		if ( '' === $supplier ) {
			return null;
		}
		$moment = isset( $row['moment'] ) && 'arrived' === $row['moment'] ? 'arrived' : 'ordered';
		$header = ! isset( $row['header'] ) || ! empty( $row['header'] );
		$roles  = Order_Import::roles();

		$columns = array();
		$used    = array();
		foreach ( isset( $row['columns'] ) && is_array( $row['columns'] ) ? $row['columns'] : array() as $column ) {
			if ( ! is_array( $column ) ) {
				continue;
			}
			$role  = isset( $column['role'] ) && is_string( $column['role'] ) && isset( $roles[ $column['role'] ] ) && ! isset( $used[ $column['role'] ] ) ? $column['role'] : '';
			$label = isset( $column['text'] ) && is_scalar( $column['text'] ) ? Order_Import::header_key( Order_Import::cell( $column['text'] ) ) : '';
			if ( $header && '' === $label ) {
				continue; // A column without a heading cannot be found again by its text.
			}
			if ( '' !== $role ) {
				$used[ $role ] = true;
			}
			$columns[] = array(
				'text' => $header ? $label : '',
				'role' => $role,
			);
			if ( count( $columns ) >= Order_Import::MAX_COLS ) {
				break;
			}
		}
		if ( ! Order_Import::map_complete( array_column( $columns, 'role' ) ) ) {
			return null;
		}

		$junk = array();
		foreach ( isset( $row['junk'] ) && is_array( $row['junk'] ) ? $row['junk'] : array() as $word ) {
			$word = is_scalar( $word ) ? Order_Import::cut( Species::key( Order_Import::cell( $word ) ), 80 ) : '';
			if ( '' !== $word && ! in_array( $word, $junk, true ) ) {
				$junk[] = $word;
			}
			if ( count( $junk ) >= self::MAX_JUNK ) {
				break;
			}
		}

		$date = static function ( $key ) use ( $row ) {
			return isset( $row[ $key ] ) && is_string( $row[ $key ] ) && Replay::is_date( $row[ $key ] ) ? $row[ $key ] : '';
		};
		$origin = $text( 'origin', 80 );

		return array(
			'id'         => self::id( $supplier, $origin, $moment ),
			'supplier'   => $supplier,
			'origin'     => $origin,
			'moment'     => $moment,
			'header'     => $header,
			'header_row' => $header ? max( 1, min( Order_Import::HEADER_SEARCH_ROWS, isset( $row['header_row'] ) && is_numeric( $row['header_row'] ) ? (int) $row['header_row'] : 1 ) ) : 0,
			'columns'    => $columns,
			'sheet'      => $text( 'sheet', Xlsx::SHEET_NAME_MAX ),
			'junk'       => $junk,
			'saved'      => $date( 'saved' ),
			'used'       => $date( 'used' ),
		);
	}

	/* ============================================================== */
	/*  Saving and forgetting                                          */
	/* ============================================================== */

	/**
	 * A layout from a mapping the owner set (pure).
	 *
	 * @param array $args supplier, origin, moment, grid, header_row (int|null: the grid row the
	 *                    header stands on), map (column → role), sheet, junk (string[]), today.
	 * @return array|null Null when there is nothing worth keeping (no supplier, no fish column).
	 */
	public static function build( array $args ) {
		$grid   = isset( $args['grid'] ) && is_array( $args['grid'] ) ? $args['grid'] : array();
		$map    = isset( $args['map'] ) && is_array( $args['map'] ) ? $args['map'] : array();
		$row    = isset( $args['header_row'] ) && null !== $args['header_row'] ? (int) $args['header_row'] : null;
		$header = null !== $row && isset( $grid[ $row ] );
		$width  = $grid ? count( reset( $grid ) ) : 0;

		$columns = array();
		for ( $index = 0; $index < $width; $index++ ) {
			$columns[] = array(
				'text' => $header ? (string) ( $grid[ $row ][ $index ] ?? '' ) : '',
				'role' => isset( $map[ $index ] ) ? (string) $map[ $index ] : '',
			);
		}
		$today = isset( $args['today'] ) ? (string) $args['today'] : '';

		return self::sanitize_one(
			array(
				'supplier'   => $args['supplier'] ?? '',
				'origin'     => $args['origin'] ?? '',
				'moment'     => $args['moment'] ?? 'ordered',
				'header'     => $header,
				'header_row' => $header ? $row + 1 : 0,
				'columns'    => $columns,
				'sheet'      => $args['sheet'] ?? '',
				'junk'       => $args['junk'] ?? array(),
				'saved'      => $today,
				'used'       => $today,
			)
		);
	}

	/**
	 * Keep a layout: it replaces the one of the same supplier, origin and
	 * moment (whose learned junk words it inherits).
	 *
	 * @param array $layout A layout (build()).
	 * @return array[] The stored list.
	 */
	public static function save( array $layout ) {
		$layout = self::sanitize_one( $layout );
		if ( null === $layout ) {
			return self::all();
		}
		$rest = array();
		foreach ( self::all() as $other ) {
			if ( $other['id'] === $layout['id'] ) {
				$layout['junk'] = array_slice( array_values( array_unique( array_merge( $layout['junk'], $other['junk'] ) ) ), 0, self::MAX_JUNK );
				continue;
			}
			$rest[] = $other;
		}
		array_unshift( $rest, $layout );
		return self::store( $rest );
	}

	/**
	 * A layout was used: its date moves, and the rows the owner said to
	 * leave out every time join its junk words.
	 *
	 * @param string   $id    Layout id.
	 * @param string   $today Site date.
	 * @param string[] $junk  Texts to leave out from now on.
	 * @return bool Whether the layout exists.
	 */
	public static function touch( $id, $today, array $junk = array() ) {
		$layouts = self::all();
		foreach ( $layouts as $index => $layout ) {
			if ( $layout['id'] !== (string) $id ) {
				continue;
			}
			$layouts[ $index ]['used'] = (string) $today;
			$layouts[ $index ]['junk'] = array_merge( $layout['junk'], $junk );
			self::store( $layouts );
			return true;
		}
		return false;
	}

	/**
	 * Forget a layout.
	 *
	 * @param string $id Layout id.
	 * @return bool Whether one was removed.
	 */
	public static function forget( $id ) {
		$layouts = self::all();
		$kept    = array();
		foreach ( $layouts as $layout ) {
			if ( $layout['id'] !== (string) $id ) {
				$kept[] = $layout;
			}
		}
		if ( count( $kept ) === count( $layouts ) ) {
			return false;
		}
		self::store( $kept );
		return true;
	}

	/**
	 * The layout with an id, or null.
	 *
	 * @param array[] $layouts Layouts.
	 * @param string  $id      Id.
	 * @return array|null
	 */
	public static function find( array $layouts, $id ) {
		foreach ( $layouts as $layout ) {
			if ( $layout['id'] === (string) $id ) {
				return $layout;
			}
		}
		return null;
	}

	/* ============================================================== */
	/*  Recognising                                                    */
	/* ============================================================== */

	/**
	 * How well a layout fits a grid (pure).
	 *
	 * With headings: the row within the first rows that holds the most of
	 * the layout's headings is its header row; score = the role columns
	 * found ÷ the role columns saved. 'missing' names the saved columns
	 * the sheet lost, 'extra' the headings the layout has never seen — a
	 * new column the importer's own header finder recognises takes that
	 * role when it is still free.
	 *
	 * Without headings: the layout fits a grid of the same width, by
	 * position — unless that grid has a header row of its own that the
	 * importer reads (a fish and a quantity heading): then it is no sheet
	 * without headings, and the layout does not fit it.
	 *
	 * @param array      $layout A layout.
	 * @param string[][] $grid   The grid.
	 * @return array{score: float, row: int|null, map: array<int, string>, missing: string[], extra: string[]}
	 */
	public static function fit( array $layout, array $grid ) {
		$none = array(
			'score'   => 0.0,
			'row'     => null,
			'map'     => array(),
			'missing' => array(),
			'extra'   => array(),
		);
		if ( ! $grid ) {
			return $none;
		}

		if ( empty( $layout['header'] ) ) {
			// By position — for a sheet WITHOUT headings only. A sheet of the same width whose own
			// headings already say which column is the fish and which the quantity is read by them.
			if ( count( reset( $grid ) ) !== count( $layout['columns'] ) || Order_Import::map_complete( Order_Import::find_header( $grid )['map'] ) ) {
				return $none;
			}
			$map = array();
			foreach ( $layout['columns'] as $index => $column ) {
				if ( '' !== $column['role'] ) {
					$map[ $index ] = $column['role'];
				}
			}
			return array_merge(
				$none,
				array(
					'score' => 1.0,
					'map'   => $map,
				)
			);
		}

		$wanted = array();
		$known  = array();
		foreach ( $layout['columns'] as $column ) {
			$known[ $column['text'] ] = true;
			if ( '' !== $column['role'] ) {
				$wanted[] = $column;
			}
		}
		if ( ! $wanted ) {
			return $none;
		}

		$best = $none;
		$hits = 0;
		foreach ( array_slice( $grid, 0, Order_Import::HEADER_SEARCH_ROWS, true ) as $n => $row ) {
			$keys = array_map( array( Order_Import::class, 'header_key' ), $row );
			$map  = array();
			$lost = array();
			$free = $keys;
			foreach ( $wanted as $column ) {
				$at = array_search( $column['text'], $free, true );
				if ( false === $at ) {
					$lost[] = $column['text'];
					continue;
				}
				$map[ (int) $at ] = $column['role'];
				$free[ $at ]      = null; // Two columns with the same heading: one each, from the left.
			}
			if ( count( $map ) <= $hits ) {
				continue;
			}
			$hits  = count( $map );
			$extra = array();
			foreach ( $keys as $index => $key ) {
				if ( '' === $key || isset( $map[ $index ] ) || isset( $known[ $key ] ) ) {
					continue;
				}
				$extra[] = $key;
				$role    = Order_Import::header_role( $key );
				if ( '' !== $role && ! in_array( $role, $map, true ) && ! in_array( $role, array_column( $wanted, 'role' ), true ) ) {
					$map[ $index ] = $role;
				}
			}
			ksort( $map );
			$best = array(
				'score'   => $hits / count( $wanted ),
				'row'     => (int) $n,
				'map'     => $map,
				'missing' => $lost,
				'extra'   => $extra,
			);
		}
		// One heading in common is a coincidence, not a sheet — unless the layout only has two.
		if ( $hits < min( 2, count( $wanted ) ) ) {
			return $none;
		}
		return $best;
	}

	/**
	 * Recognise a grid (pure): which saved layout to apply, or to offer.
	 *
	 * @param array[]    $layouts  The saved layouts.
	 * @param string[][] $grid     The grid.
	 * @param string     $supplier The order's supplier ('' on a new order without one yet).
	 * @param string     $origin   The order's origin.
	 * @param string     $moment   ordered|arrived.
	 * @return array{applied: array|null, fit: array|null, partial: bool, offer: array|null}
	 */
	public static function recognise( array $layouts, array $grid, $supplier, $origin, $moment ) {
		$moment = 'arrived' === $moment ? 'arrived' : 'ordered';
		$key    = Species::key( $supplier );
		$place  = Species::key( $origin );
		$out    = array(
			'applied' => null,
			'fit'     => null,
			'partial' => false,
			'offer'   => null,
		);

		$own       = null;
		$own_fit   = null;
		$other     = null;
		$other_fit = null;
		foreach ( $layouts as $layout ) {
			if ( $layout['moment'] !== $moment ) {
				continue;
			}
			$fit = self::fit( $layout, $grid );
			if ( $fit['score'] <= 0 || ! Order_Import::map_complete( $fit['map'] ) ) {
				continue;
			}
			if ( '' !== $key && Species::key( $layout['supplier'] ) === $key ) {
				// The origin's own layout first, then the supplier's for any origin.
				$rank = $fit['score'] + ( Species::key( $layout['origin'] ) === $place ? 0.001 : 0 );
				if ( null === $own_fit || $rank > $own_fit['rank'] ) {
					$own            = $layout;
					$own_fit        = $fit;
					$own_fit['rank'] = $rank;
				}
				continue;
			}
			if ( ! empty( $layout['header'] ) && ( null === $other_fit || $fit['score'] > $other_fit['score'] ) ) {
				$other     = $layout;
				$other_fit = $fit;
			}
		}

		if ( $own && $own_fit['score'] >= self::PARTIAL_MIN ) {
			unset( $own_fit['rank'] );
			$out['applied'] = $own;
			$out['fit']     = $own_fit;
			$out['partial'] = $own_fit['score'] < 1 || (bool) $own_fit['extra'];
			return $out;
		}
		if ( $other && $other_fit['score'] >= self::OFFER_MIN && $other_fit['score'] - ( $own_fit ? $own_fit['score'] : 0 ) >= self::OFFER_GAP ) {
			$out['offer'] = $other;
		}
		return $out;
	}

	/* ============================================================== */
	/*  Words                                                          */
	/* ============================================================== */

	/**
	 * A layout's columns in words: "english name → the fish · order →
	 * ordered"; by position without headings ("column 1 → the fish").
	 * Plain text — escape it where it is shown.
	 *
	 * @param array $layout A layout.
	 * @return string
	 */
	public static function describe( array $layout ) {
		$roles = Order_Import::roles();
		$parts = array();
		foreach ( (array) ( $layout['columns'] ?? array() ) as $index => $column ) {
			if ( '' === $column['role'] || ! isset( $roles[ $column['role'] ] ) ) {
				continue;
			}
			/* translators: %d: column number */
			$name    = '' !== $column['text'] ? $column['text'] : sprintf( __( 'column %d', 'fishotel-misc-plugin' ), $index + 1 );
			$parts[] = $name . ' → ' . $roles[ $column['role'] ];
		}
		return implode( ' · ', $parts );
	}

	/**
	 * The moment in words.
	 *
	 * @param string $moment ordered|arrived.
	 * @return string
	 */
	public static function moment_text( $moment ) {
		return 'arrived' === $moment ? __( 'arrived (the invoice)', 'fishotel-misc-plugin' ) : __( 'ordered (the order sheet)', 'fishotel-misc-plugin' );
	}

	/**
	 * Whose layout it is, in words: the supplier, and the origin when it has one.
	 *
	 * @param array $layout A layout.
	 * @return string
	 */
	public static function owner_text( array $layout ) {
		return '' !== (string) ( $layout['origin'] ?? '' ) ? $layout['supplier'] . ' (' . $layout['origin'] . ')' : (string) ( $layout['supplier'] ?? '' );
	}
}
