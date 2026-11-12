<?php
/**
 * Plan — PURE. Turns "cut for this many of each box" into the cut sheet:
 * every piece of every box handed to the Packer, and its answer written
 * out per source (a leftover's label, or "new sheet 1") as the ordered
 * cuts in plain words, the pieces with their box and name, the new
 * leftovers with the labels to write on them, and the waste.
 *
 * The array build() returns is what is stored, printed, drawn and served
 * over REST — and it is the machine-ready data for a CNC export one day.
 * Its shape (schema "fishotel-foam-cuts/plan@1"), every size in inches:
 *
 *   schema, units ("in")
 *   sheet     { length, width, thickness }
 *   settings  { kerf, rotate, min_leftover { length, width } }
 *   boxes[]   { key, name, length, width, height, count, cut_list
 *               ("saved" | "suggested"), pieces[] { name, length, width, count } }
 *   sources[] { n, kind ("leftover" | "sheet"), label, leftover_id (null
 *               for a sheet), length, width,
 *               pieces[]    { n, box, box_key, name, length, width (as on
 *                             the cut list), x, y, w, h (where it lies:
 *                             x from the left along the source's length,
 *                             y from the top along its width), rotated },
 *               cuts[]      { n, axis ("v" = top to bottom, "h" = left to
 *                             right), at (from the left / top edge of the
 *                             part being cut), x1, y1, x2, y2 (the near
 *                             edge of the cut on the source), kerf (the
 *                             blade takes x1 … x1 + kerf, or y1 … y1 +
 *                             kerf), part { x, y, w, h }, of (the cut that
 *                             made that part, 0 = the whole source), side
 *                             ("near" = left / above, "far" = right /
 *                             below that cut), text },
 *               leftovers[] { label, x, y, w, h },
 *               waste[]     { x, y, w, h },
 *               area, pieces_area, leftover_area, waste_area (square
 *               inches), waste_pct }
 *   unplaced[] { box, name, length, width, reason, text }
 *   summary[]  { box, key, count, pieces[] { name, length, width, per_box,
 *                total, placed, from { source label: how many } } }
 *   totals    { boxes, pieces, placed, sheets, sheets_in_stock,
 *               leftovers_used, leftovers_new, cuts, area, pieces_area,
 *               leftover_area, waste_area, waste_pct }
 *   labels    { from, count } — the L-numbers this plan hands out
 *   warnings[], errors[] (a plan with errors is never saved)
 *
 * @package FisHotel\Misc\Sections\Foam_Cuts
 */

namespace FisHotel\Misc\Sections\Foam_Cuts;

defined( 'ABSPATH' ) || exit;

/**
 * Class Plan
 */
class Plan {

	/**
	 * The shape's name and version.
	 *
	 * @var string
	 */
	const SCHEMA = 'fishotel-foam-cuts/plan@1';

	/**
	 * The most pieces one plan lays out.
	 *
	 * @var int
	 */
	const MAX_PIECES = 3000;

	/**
	 * The most boxes of one kind a plan is asked for.
	 *
	 * @var int
	 */
	const MAX_BOX_COUNT = 500;

	/**
	 * Build a plan.
	 *
	 * @param array $boxes      [ { key, name, length, width, height, count,
	 *                          cut_list, pieces[] { name, length, width, count } } ] — units.
	 * @param array $stock      { sheets, leftovers[] { id, label, length, width } } — units.
	 * @param array $settings   { sheet_length, sheet_width, thickness, kerf,
	 *                          rotate, min_length, min_width } — units.
	 * @param int   $next_label The first L-number free for a new leftover.
	 * @return array See the file header.
	 */
	public static function build( array $boxes, array $stock, array $settings, $next_label = 1 ) {
		$next_label = max( 1, (int) $next_label );
		$opts       = array(
			'sheet_w' => (int) $settings['sheet_length'],
			'sheet_h' => (int) $settings['sheet_width'],
			'kerf'    => (int) $settings['kerf'],
			'rotate'  => ! empty( $settings['rotate'] ),
			'min_w'   => (int) $settings['min_length'],
			'min_h'   => (int) $settings['min_width'],
		);

		$data = array(
			'schema'   => self::SCHEMA,
			'units'    => 'in',
			'sheet'    => array(
				'length'    => Units::to_inches( $opts['sheet_w'] ),
				'width'     => Units::to_inches( $opts['sheet_h'] ),
				'thickness' => Units::to_inches( isset( $settings['thickness'] ) ? (int) $settings['thickness'] : 0 ),
			),
			'settings' => array(
				'kerf'         => Units::to_inches( $opts['kerf'] ),
				'rotate'       => $opts['rotate'],
				'min_leftover' => array(
					'length' => Units::to_inches( $opts['min_w'] ),
					'width'  => Units::to_inches( $opts['min_h'] ),
				),
			),
			'boxes'    => array(),
			'sources'  => array(),
			'unplaced' => array(),
			'summary'  => array(),
			'totals'   => array(),
			'labels'   => array(
				'from'  => $next_label,
				'count' => 0,
			),
			'warnings' => array(),
			'errors'   => array(),
		);

		// Every piece of every box, one entry each.
		$pieces = array();
		$meta   = array();
		$used   = array();
		$total  = 0;

		foreach ( array_values( $boxes ) as $box ) {
			$count = isset( $box['count'] ) ? max( 0, min( self::MAX_BOX_COUNT, (int) $box['count'] ) ) : 0;
			if ( $count < 1 || empty( $box['pieces'] ) || ! is_array( $box['pieces'] ) ) {
				continue;
			}

			$rows = Cut_List::sanitize( $box['pieces'] );
			if ( ! $rows ) {
				continue;
			}

			$b      = count( $used );
			$used[] = array(
				'key'      => (string) $box['key'],
				'name'     => (string) $box['name'],
				'length'   => (int) $box['length'],
				'width'    => (int) $box['width'],
				'height'   => (int) $box['height'],
				'count'    => $count,
				'cut_list' => isset( $box['cut_list'] ) && 'saved' === $box['cut_list'] ? 'saved' : 'suggested',
				'pieces'   => $rows,
			);

			foreach ( $rows as $r => $row ) {
				$copies = $row['count'] * $count;
				$total += $copies;
				if ( $total > self::MAX_PIECES ) {
					break 2;
				}
				for ( $c = 0; $c < $copies; $c++ ) {
					$pieces[] = array(
						'id' => count( $meta ),
						'w'  => $row['length'],
						'h'  => $row['width'],
					);
					$meta[]   = array( $b, $r );
				}
			}
		}

		foreach ( $used as $box ) {
			$data['boxes'][] = array(
				'key'      => $box['key'],
				'name'     => $box['name'],
				'length'   => Units::to_inches( $box['length'] ),
				'width'    => Units::to_inches( $box['width'] ),
				'height'   => Units::to_inches( $box['height'] ),
				'count'    => $box['count'],
				'cut_list' => $box['cut_list'],
				'pieces'   => array_map( array( self::class, 'piece_inches' ), $box['pieces'] ),
			);
		}

		if ( $total > self::MAX_PIECES ) {
			/* translators: %d: the most pieces a plan lays out */
			$data['errors'][] = sprintf( __( 'That is more than %d pieces of foam in one plan. Make the plan in two halves.', 'fishotel-misc-plugin' ), self::MAX_PIECES );
			return $data;
		}

		if ( ! $pieces ) {
			$data['errors'][] = __( 'Nothing to cut: give at least one box a number above 0 (a box needs a cut list with at least one piece).', 'fishotel-misc-plugin' );
			return $data;
		}

		// The leftovers in stock, in label order.
		$leftovers = array();
		foreach ( isset( $stock['leftovers'] ) ? (array) $stock['leftovers'] : array() as $left ) {
			if ( ! is_array( $left ) ) {
				continue;
			}
			$leftovers[] = array(
				'id'    => (int) $left['id'],
				'label' => (string) $left['label'],
				'w'     => (int) $left['length'],
				'h'     => (int) $left['width'],
			);
		}

		$packed   = Packer::pack( $pieces, $leftovers, $opts );
		$problems = Packer::verify( $packed, $opts );

		if ( $problems ) {
			$data['errors'][] = __( 'The layout did not pass its own check, so no cut sheet is shown. Nothing was changed.', 'fishotel-misc-plugin' ) . ' (' . implode( '; ', array_slice( $problems, 0, 3 ) ) . ')';
			return $data;
		}

		// What was placed where, per box and piece (for the summary).
		$from   = array();
		$placed = 0;
		$label  = $next_label;
		$totals = array(
			'area'          => 0,
			'pieces_area'   => 0,
			'leftover_area' => 0,
			'waste_area'    => 0,
			'cuts'          => 0,
			'used'          => 0,
			'new'           => 0,
		);

		foreach ( $packed['sources'] as $s => $source ) {
			$is_sheet = 'sheet' === $source['kind'];
			$stock_at = $is_sheet ? null : $leftovers[ $source['stock'] ];
			$name     = $is_sheet
				/* translators: %d: the number of the new whole sheet in this plan */
				? sprintf( __( 'new sheet %d', 'fishotel-misc-plugin' ), (int) $source['sheet'] )
				: $stock_at['label'];

			$out_pieces = array();
			$labels     = array();
			foreach ( $source['pieces'] as $index => $piece ) {
				list( $b, $r ) = $meta[ $piece['id'] ];
				$row           = $used[ $b ]['pieces'][ $r ];

				$out_pieces[]      = array(
					'n'       => $index + 1,
					'box'     => $used[ $b ]['name'],
					'box_key' => $used[ $b ]['key'],
					'name'    => $row['name'],
					'length'  => Units::to_inches( $row['length'] ),
					'width'   => Units::to_inches( $row['width'] ),
					'x'       => Units::to_inches( $piece['x'] ),
					'y'       => Units::to_inches( $piece['y'] ),
					'w'       => Units::to_inches( $piece['w'] ),
					'h'       => Units::to_inches( $piece['h'] ),
					'rotated' => (bool) $piece['rotated'],
				);
				$labels[ $index ]  = $used[ $b ]['name'] . ' · ' . $row['name'] . ' (' . Units::size( $row['length'], $row['width'] ) . ' in)';
				$from[ $b ][ $r ][ $name ] = isset( $from[ $b ][ $r ][ $name ] ) ? $from[ $b ][ $r ][ $name ] + 1 : 1;
				$placed++;
			}

			$out_left    = array();
			$out_waste   = array();
			$free_labels = array();
			foreach ( $source['free'] as $index => $free ) {
				$rect = array(
					'x' => Units::to_inches( $free['x'] ),
					'y' => Units::to_inches( $free['y'] ),
					'w' => Units::to_inches( $free['w'] ),
					'h' => Units::to_inches( $free['h'] ),
				);
				if ( $free['keep'] ) {
					$free_labels[ $index ] = 'L' . $label;
					$out_left[]            = array_merge( array( 'label' => 'L' . $label ), $rect );
					$label++;
				} else {
					$out_waste[] = $rect;
				}
			}

			$out_cuts = array();
			foreach ( $source['cuts'] as $cut ) {
				$part       = $cut['part'];
				$vertical   = 'v' === $cut['axis'];
				$out_cuts[] = array(
					'n'    => $cut['n'],
					'axis' => $cut['axis'],
					'at'   => Units::to_inches( $cut['at'] ),
					'x1'   => Units::to_inches( $vertical ? $part['x'] + $cut['at'] : $part['x'] ),
					'y1'   => Units::to_inches( $vertical ? $part['y'] : $part['y'] + $cut['at'] ),
					'x2'   => Units::to_inches( $vertical ? $part['x'] + $cut['at'] : $part['x'] + $part['w'] ),
					'y2'   => Units::to_inches( $vertical ? $part['y'] + $part['h'] : $part['y'] + $cut['at'] ),
					'kerf' => Units::to_inches( $opts['kerf'] ),
					'part' => array(
						'x' => Units::to_inches( $part['x'] ),
						'y' => Units::to_inches( $part['y'] ),
						'w' => Units::to_inches( $part['w'] ),
						'h' => Units::to_inches( $part['h'] ),
					),
					'of'   => $cut['of'],
					'side' => $cut['side'],
					'text' => self::cut_text( $cut, $source['cuts'], $is_sheet, $labels, $free_labels ),
				);
			}

			$data['sources'][] = array(
				'n'             => $s + 1,
				'kind'          => $is_sheet ? 'sheet' : 'leftover',
				'label'         => $name,
				'leftover_id'   => $is_sheet ? null : $stock_at['id'],
				'length'        => Units::to_inches( $source['w'] ),
				'width'         => Units::to_inches( $source['h'] ),
				'pieces'        => $out_pieces,
				'cuts'          => $out_cuts,
				'leftovers'     => $out_left,
				'waste'         => $out_waste,
				'area'          => self::square_inches( $source['area'] ),
				'pieces_area'   => self::square_inches( $source['pieces_area'] ),
				'leftover_area' => self::square_inches( $source['leftover_area'] ),
				'waste_area'    => self::square_inches( $source['waste_area'] ),
				'waste_pct'     => Units::percent( $source['waste_area'], $source['area'] ),
			);

			$totals['area']          += $source['area'];
			$totals['pieces_area']   += $source['pieces_area'];
			$totals['leftover_area'] += $source['leftover_area'];
			$totals['waste_area']    += $source['waste_area'];
			$totals['cuts']          += count( $out_cuts );
			$totals['used']          += $is_sheet ? 0 : 1;
			$totals['new']           += count( $out_left );
		}

		// The pieces that could not be placed.
		$missing    = array();
		$missing_at = array();
		foreach ( $packed['unplaced'] as $piece ) {
			list( $b, $r )       = $meta[ $piece['id'] ];
			$row                 = $used[ $b ]['pieces'][ $r ];
			$missing[ $b ][ $r ] = isset( $missing[ $b ][ $r ] ) ? $missing[ $b ][ $r ] + 1 : 1;

			if ( 1 < $missing[ $b ][ $r ] ) {
				continue;
			}

			$text = $opts['rotate']
				/* translators: 1: box, 2: piece, 3: piece size, 4: sheet size */
				? __( '%1$s · %2$s (%3$s in) does not fit a whole sheet (%4$s in).', 'fishotel-misc-plugin' )
				/* translators: 1: box, 2: piece, 3: piece size, 4: sheet size */
				: __( '%1$s · %2$s (%3$s in) does not fit a whole sheet (%4$s in) without being turned, and turning pieces is switched off.', 'fishotel-misc-plugin' );

			$data['unplaced'][] = array(
				'box'    => $used[ $b ]['name'],
				'name'   => $row['name'],
				'length' => Units::to_inches( $row['length'] ),
				'width'  => Units::to_inches( $row['width'] ),
				'count'  => 1,
				'reason' => (string) $piece['reason'],
				'text'   => sprintf( $text, $used[ $b ]['name'], $row['name'], Units::size( $row['length'], $row['width'] ), Units::size( $opts['sheet_w'], $opts['sheet_h'] ) ),
			);
			$missing_at[ $b ][ $r ] = count( $data['unplaced'] ) - 1;
		}
		foreach ( $missing as $b => $rows ) {
			foreach ( $rows as $r => $count ) {
				$data['unplaced'][ $missing_at[ $b ][ $r ] ]['count'] = $count;
			}
		}

		// The pieces per box.
		foreach ( $used as $b => $box ) {
			$rows = array();
			foreach ( $box['pieces'] as $r => $row ) {
				$here   = isset( $from[ $b ][ $r ] ) ? $from[ $b ][ $r ] : array();
				$rows[] = array(
					'name'    => $row['name'],
					'length'  => Units::to_inches( $row['length'] ),
					'width'   => Units::to_inches( $row['width'] ),
					'per_box' => $row['count'],
					'total'   => $row['count'] * $box['count'],
					'placed'  => array_sum( $here ),
					'from'    => $here,
				);
			}
			$data['summary'][] = array(
				'box'    => $box['name'],
				'key'    => $box['key'],
				'count'  => $box['count'],
				'pieces' => $rows,
			);
		}

		$in_stock        = isset( $stock['sheets'] ) ? (int) $stock['sheets'] : 0;
		$boxes_total     = 0;
		foreach ( $used as $box ) {
			$boxes_total += $box['count'];
		}
		$data['totals']  = array(
			'boxes'           => $boxes_total,
			'pieces'          => count( $pieces ),
			'placed'          => $placed,
			'sheets'          => (int) $packed['sheets'],
			'sheets_in_stock' => $in_stock,
			'leftovers_used'  => $totals['used'],
			'leftovers_new'   => $totals['new'],
			'cuts'            => $totals['cuts'],
			'area'            => self::square_inches( $totals['area'] ),
			'pieces_area'     => self::square_inches( $totals['pieces_area'] ),
			'leftover_area'   => self::square_inches( $totals['leftover_area'] ),
			'waste_area'      => self::square_inches( $totals['waste_area'] ),
			'waste_pct'       => Units::percent( $totals['waste_area'], $totals['area'] ),
		);
		$data['labels']['count'] = $label - $next_label;

		if ( $data['unplaced'] ) {
			$data['warnings'][] = __( 'Some pieces are too large for a sheet and are not on this cut sheet — see the list.', 'fishotel-misc-plugin' );
		}
		if ( (int) $packed['sheets'] > max( 0, $in_stock ) ) {
			$data['warnings'][] = sprintf(
				/* translators: 1: sheets the plan takes, 2: sheets in stock */
				__( 'This plan takes %1$d whole sheet(s) and the stock says %2$d. If there are more, log them under Stock ("bought N sheets"); otherwise buy foam first.', 'fishotel-misc-plugin' ),
				(int) $packed['sheets'],
				max( 0, $in_stock )
			);
		}
		foreach ( $boxes as $box ) {
			if ( ! empty( $box['count'] ) && ! empty( $box['changed'] ) ) {
				/* translators: %s: box name */
				$data['warnings'][] = sprintf( __( 'The box "%s" has changed size since its cut list was saved — check the list under Boxes.', 'fishotel-misc-plugin' ), (string) $box['name'] );
			}
		}

		return $data;
	}

	/**
	 * One cut in plain words.
	 *
	 * @param array $cut         A Packer cut (units).
	 * @param array $cuts        All cuts of the source (to say which side of which cut a part lies on).
	 * @param bool  $is_sheet    Whether the source is a whole sheet.
	 * @param array $labels      Piece index → "Box · Piece (size in)".
	 * @param array $free_labels Remainder index → leftover label (kept ones only).
	 * @return string
	 */
	public static function cut_text( array $cut, array $cuts, $is_sheet, array $labels, array $free_labels ) {
		$at       = Units::format( $cut['at'] );
		$vertical = 'v' === $cut['axis'];

		if ( 0 === (int) $cut['of'] ) {
			$subject = $is_sheet ? __( 'the whole sheet', 'fishotel-misc-plugin' ) : __( 'the whole leftover', 'fishotel-misc-plugin' );
			$text    = $vertical
				/* translators: 1: cut number, 2: "the whole sheet" / "the whole leftover", 3: inches */
				? __( 'Cut %1$d: straight across %2$s, top to bottom, %3$s in from the left edge.', 'fishotel-misc-plugin' )
				/* translators: 1: cut number, 2: "the whole sheet" / "the whole leftover", 3: inches */
				: __( 'Cut %1$d: straight across %2$s, left to right, %3$s in from the top edge.', 'fishotel-misc-plugin' );
		} else {
			$parent = $cuts[ (int) $cut['of'] - 1 ];
			if ( 'v' === $parent['axis'] ) {
				$where = 'near' === $cut['side'] ? __( 'left of', 'fishotel-misc-plugin' ) : __( 'right of', 'fishotel-misc-plugin' );
			} else {
				$where = 'near' === $cut['side'] ? __( 'above', 'fishotel-misc-plugin' ) : __( 'below', 'fishotel-misc-plugin' );
			}
			$subject = sprintf(
				/* translators: 1: size of the part, 2: "left of" / "right of" / "above" / "below", 3: cut number */
				__( 'the %1$s in part %2$s cut %3$d', 'fishotel-misc-plugin' ),
				Units::size( $cut['part']['w'], $cut['part']['h'] ),
				$where,
				(int) $cut['of']
			);
			$text = $vertical
				/* translators: 1: cut number, 2: the part being cut, 3: inches */
				? __( 'Cut %1$d: straight across %2$s, top to bottom, %3$s in from its left edge.', 'fishotel-misc-plugin' )
				/* translators: 1: cut number, 2: the part being cut, 3: inches */
				: __( 'Cut %1$d: straight across %2$s, left to right, %3$s in from its top edge.', 'fishotel-misc-plugin' );
		}

		$text = sprintf( $text, (int) $cut['n'], $subject, $at );

		$freed = array();
		foreach ( array( 'near', 'far' ) as $side ) {
			if ( 'piece' === $cut[ $side ]['kind'] && isset( $labels[ $cut[ $side ]['piece'] ] ) ) {
				$freed[] = $labels[ $cut[ $side ]['piece'] ];
			}
		}
		if ( $freed ) {
			/* translators: %s: one or two pieces, e.g. "Small · Top / bottom (10 × 10 in)" */
			$text .= ' ' . sprintf( __( 'That frees %s.', 'fishotel-misc-plugin' ), implode( ' ' . __( 'and', 'fishotel-misc-plugin' ) . ' ', $freed ) );
		}

		$far = $cut['far'];
		if ( 'leftover' === $far['kind'] && isset( $free_labels[ $far['free'] ] ) ) {
			/* translators: 1: leftover label, 2: its size */
			$text .= ' ' . sprintf( __( 'The rest is leftover %1$s (%2$s in).', 'fishotel-misc-plugin' ), $free_labels[ $far['free'] ], Units::size( $far['w'], $far['h'] ) );
		} elseif ( 'waste' === $far['kind'] ) {
			/* translators: %s: size of the offcut */
			$text .= ' ' . sprintf( __( 'The rest (%s in) is waste.', 'fishotel-misc-plugin' ), Units::size( $far['w'], $far['h'] ) );
		}

		return $text;
	}

	/**
	 * A cut-list row in inches.
	 *
	 * @param array $row { name, length, width, count } in units.
	 * @return array
	 */
	public static function piece_inches( array $row ) {
		return array(
			'name'   => (string) $row['name'],
			'length' => Units::to_inches( $row['length'] ),
			'width'  => Units::to_inches( $row['width'] ),
			'count'  => (int) $row['count'],
		);
	}

	/**
	 * Square units → square inches (two decimals).
	 *
	 * @param int $area Square units.
	 * @return float
	 */
	public static function square_inches( $area ) {
		return round( (float) $area / ( Units::PER_INCH * Units::PER_INCH ), 2 );
	}

	/**
	 * "3 × Small, 2 × Medium" — what a plan is for.
	 *
	 * @param array $data Plan data.
	 * @return string
	 */
	public static function title( array $data ) {
		$parts = array();
		foreach ( isset( $data['boxes'] ) ? (array) $data['boxes'] : array() as $box ) {
			$parts[] = (int) $box['count'] . ' × ' . (string) $box['name'];
		}
		return implode( ', ', $parts );
	}

	/**
	 * The ids of the leftovers a plan cuts into.
	 *
	 * @param array $data Plan data.
	 * @return int[]
	 */
	public static function used_leftover_ids( array $data ) {
		$ids = array();
		foreach ( isset( $data['sources'] ) ? (array) $data['sources'] : array() as $source ) {
			if ( 'leftover' === $source['kind'] && ! empty( $source['leftover_id'] ) ) {
				$ids[] = (int) $source['leftover_id'];
			}
		}
		return $ids;
	}

	/**
	 * The leftovers a plan cuts into, as it saw them: id → { label, length, width } in units.
	 *
	 * @param array $data Plan data.
	 * @return array<int, array{label: string, length: int, width: int}>
	 */
	public static function used_leftovers( array $data ) {
		$out = array();
		foreach ( isset( $data['sources'] ) ? (array) $data['sources'] : array() as $source ) {
			if ( 'leftover' === $source['kind'] && ! empty( $source['leftover_id'] ) ) {
				$out[ (int) $source['leftover_id'] ] = array(
					'label'  => (string) $source['label'],
					'length' => (int) Units::from_inches( $source['length'] ),
					'width'  => (int) Units::from_inches( $source['width'] ),
				);
			}
		}
		return $out;
	}

	/**
	 * The leftovers a plan makes: [ { label, length, width, source } ] in
	 * units (length along the source's length).
	 *
	 * @param array $data Plan data.
	 * @return array<int, array{label: string, length: int, width: int, source: string}>
	 */
	public static function new_leftovers( array $data ) {
		$out = array();
		foreach ( isset( $data['sources'] ) ? (array) $data['sources'] : array() as $source ) {
			foreach ( isset( $source['leftovers'] ) ? (array) $source['leftovers'] : array() as $left ) {
				$out[] = array(
					'label'  => (string) $left['label'],
					'length' => (int) Units::from_inches( $left['w'] ),
					'width'  => (int) Units::from_inches( $left['h'] ),
					'source' => (string) $source['label'],
				);
			}
		}
		return $out;
	}

	/**
	 * How many whole sheets a plan takes.
	 *
	 * @param array $data Plan data.
	 * @return int
	 */
	public static function sheets( array $data ) {
		return isset( $data['totals']['sheets'] ) ? max( 0, (int) $data['totals']['sheets'] ) : 0;
	}
}
