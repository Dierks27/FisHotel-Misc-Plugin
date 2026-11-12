<?php
/**
 * Packer — PURE. Lays rectangles of foam out on leftovers and whole sheets
 * using straight edge-to-edge (guillotine) cuts only, the way a sheet is
 * cut by hand with a straightedge.
 *
 * How a piece is placed: it goes into the top-left corner of a free
 * rectangle, and that rectangle is divided by at most two cuts — one that
 * runs the full way across the free rectangle (it separates a strip from
 * the rest), then one that runs the full way across that strip (it
 * separates the piece from the rest of the strip). What is left over are
 * two smaller free rectangles; the next pieces go into those. Because
 * every cut spans the whole of the part it divides, the plan can always
 * be cut in the order it is written down.
 *
 * Which free rectangle: the SMALLEST one the piece fits in — leftovers in
 * stock and the offcuts of the sources already being cut — so the piece
 * wastes as little as possible and the big rectangles stay big. A new
 * whole sheet is taken only when nothing else fits.
 *
 * How it is divided: the way that loses the least foam to scrap, then the
 * way that keeps the larger of the two remainders as large as possible.
 *
 * A greedy rule is only as good as the order it is fed, so the same rule
 * is run over a few orders and a few variations (a deterministic list —
 * no randomness anywhere) and the best result is kept: fewest pieces that
 * could not be placed, fewest new sheets, least foam wasted, the largest
 * remainders, fewest cuts.
 *
 * The saw cut's own width (kerf) is taken out of the remainder at every
 * cut, never out of a piece. A piece is turned 90 degrees only when
 * turning is allowed.
 *
 * Sizes are integers (Units); x runs along a source's length, y along its
 * width, from its top-left corner.
 *
 * @package FisHotel\Misc\Sections\Foam_Cuts
 */

namespace FisHotel\Misc\Sections\Foam_Cuts;

defined( 'ABSPATH' ) || exit;

/**
 * Class Packer
 */
class Packer {

	/**
	 * The orders the pieces are tried in.
	 *
	 * @var string[]
	 */
	const SORTS = array( 'area', 'long', 'short' );

	/**
	 * The variations of the dividing rule.
	 *
	 * @var string[]
	 */
	const MODES = array( 'max', 'fill', 'short', 'long' );

	/**
	 * Above this many pieces only a part of the search is run, so a very
	 * large plan still answers at once.
	 *
	 * @var int
	 */
	const FULL_SEARCH_PIECES = 900;

	/**
	 * Lay the pieces out.
	 *
	 * @param array $pieces    [ { id, w, h } ] — w along the length, h along the width.
	 * @param array $leftovers [ { id, label, w, h } ] — the leftovers in stock.
	 * @param array $opts      { sheet_w, sheet_h, kerf, rotate, min_w, min_h }.
	 * @return array{
	 *   sources: array<int, array{
	 *     kind: string, stock: int|null, sheet: int, w: int, h: int,
	 *     pieces: array<int, array{id: mixed, x: int, y: int, w: int, h: int, rotated: bool}>,
	 *     cuts: array<int, array>, free: array<int, array{x: int, y: int, w: int, h: int, keep: bool}>,
	 *     area: int, pieces_area: int, leftover_area: int, waste_area: int
	 *   }>,
	 *   unplaced: array<int, array{id: mixed, w: int, h: int, reason: string}>,
	 *   sheets: int, strategy: string
	 * }
	 */
	public static function pack( array $pieces, array $leftovers, array $opts ) {
		$o        = self::options( $opts );
		$stock    = self::clean_leftovers( $leftovers );
		$list     = array();
		$rejected = array();

		foreach ( array_values( $pieces ) as $seq => $piece ) {
			$w  = isset( $piece['w'] ) ? (int) $piece['w'] : 0;
			$h  = isset( $piece['h'] ) ? (int) $piece['h'] : 0;
			$id = isset( $piece['id'] ) ? $piece['id'] : $seq;

			if ( $w <= 0 || $h <= 0 ) {
				$rejected[] = array(
					'id'     => $id,
					'w'      => $w,
					'h'      => $h,
					'reason' => 'no_size',
				);
				continue;
			}

			$list[] = array(
				'id'  => $id,
				'w'   => $w,
				'h'   => $h,
				'seq' => $seq,
			);
		}

		if ( ! $list ) {
			return array(
				'sources'  => array(),
				'unplaced' => $rejected,
				'sheets'   => 0,
				'strategy' => '',
			);
		}

		$best       = null;
		$best_score = null;
		$best_name  = '';

		foreach ( self::strategies( count( $list ) ) as $index => $strategy ) {
			$run   = self::run( self::sort_pieces( $list, $strategy[0] ), $stock, $o, $strategy[1] );
			$score = self::objective( $run, $index );

			if ( null === $best || self::less( $score, $best_score ) ) {
				$best       = $run;
				$best_score = $score;
				$best_name  = $strategy[0] . '/' . $strategy[1];
			}
		}

		$out             = self::finish( $best, $o );
		$out['unplaced'] = array_merge( $rejected, $out['unplaced'] );
		$out['strategy'] = $best_name;

		return $out;
	}

	/**
	 * The options with their defaults: a 96 × 48 in sheet, no kerf,
	 * turning allowed, a 4 × 4 in smallest leftover.
	 *
	 * @param array $opts Raw options.
	 * @return array{sheet_w: int, sheet_h: int, kerf: int, rotate: bool, min_w: int, min_h: int}
	 */
	public static function options( array $opts ) {
		$inch = Units::PER_INCH;

		return array(
			'sheet_w' => isset( $opts['sheet_w'] ) && (int) $opts['sheet_w'] > 0 ? (int) $opts['sheet_w'] : 96 * $inch,
			'sheet_h' => isset( $opts['sheet_h'] ) && (int) $opts['sheet_h'] > 0 ? (int) $opts['sheet_h'] : 48 * $inch,
			'kerf'    => isset( $opts['kerf'] ) ? max( 0, (int) $opts['kerf'] ) : 0,
			'rotate'  => ! isset( $opts['rotate'] ) || ! empty( $opts['rotate'] ),
			'min_w'   => isset( $opts['min_w'] ) ? max( 0, (int) $opts['min_w'] ) : 4 * $inch,
			'min_h'   => isset( $opts['min_h'] ) ? max( 0, (int) $opts['min_h'] ) : 4 * $inch,
		);
	}

	/**
	 * The longest cut that is still easy with a straightedge: the short
	 * side of a whole sheet. A longer cut is only made when a piece is
	 * that long itself.
	 *
	 * @param array $o Options.
	 * @return int
	 */
	public static function easy_cut( array $o ) {
		return min( $o['sheet_w'], $o['sheet_h'] );
	}

	/**
	 * Whether a w × h rectangle fits in a W × H one.
	 *
	 * @param int  $w      Piece size along x.
	 * @param int  $h      Piece size along y.
	 * @param int  $big_w  Space along x.
	 * @param int  $big_h  Space along y.
	 * @param bool $rotate Whether the piece may be turned.
	 * @return bool
	 */
	public static function fits( $w, $h, $big_w, $big_h, $rotate ) {
		if ( $w <= $big_w && $h <= $big_h ) {
			return true;
		}
		return $rotate && $h <= $big_w && $w <= $big_h;
	}

	/**
	 * Whether a remainder is worth keeping as a leftover: it holds the
	 * smallest leftover either way round.
	 *
	 * @param int   $w Remainder size along x.
	 * @param int   $h Remainder size along y.
	 * @param array $o Options.
	 * @return bool
	 */
	public static function keepable( $w, $h, array $o ) {
		if ( $w <= 0 || $h <= 0 ) {
			return false;
		}
		return ( $w >= $o['min_w'] && $h >= $o['min_h'] ) || ( $w >= $o['min_h'] && $h >= $o['min_w'] );
	}

	/* ============================================================== */
	/*  The search                                                     */
	/* ============================================================== */

	/**
	 * The (order, variation) pairs to run for a number of pieces.
	 *
	 * @param int $count Pieces.
	 * @return array<int, array{0: string, 1: string}>
	 */
	public static function strategies( $count ) {
		if ( $count > self::FULL_SEARCH_PIECES ) {
			return array(
				array( 'area', 'max' ),
				array( 'area', 'fill' ),
				array( 'long', 'max' ),
			);
		}

		$out = array();
		foreach ( self::SORTS as $sort ) {
			foreach ( self::MODES as $mode ) {
				$out[] = array( $sort, $mode );
			}
		}
		return $out;
	}

	/**
	 * The pieces in one of the orders, largest first; equal pieces keep
	 * the order they were given in (the sequence number breaks every tie,
	 * so the result does not depend on the sort being stable).
	 *
	 * @param array  $list Pieces { id, w, h, seq }.
	 * @param string $sort area|long|short.
	 * @return array
	 */
	public static function sort_pieces( array $list, $sort ) {
		usort(
			$list,
			static function ( $a, $b ) use ( $sort ) {
				$a_long  = max( $a['w'], $a['h'] );
				$b_long  = max( $b['w'], $b['h'] );
				$a_short = min( $a['w'], $a['h'] );
				$b_short = min( $b['w'], $b['h'] );

				if ( 'long' === $sort ) {
					$keys = array( $b_long - $a_long, $b_short - $a_short );
				} elseif ( 'short' === $sort ) {
					$keys = array( $b_short - $a_short, $b_long - $a_long );
				} else {
					$keys = array( $b['w'] * $b['h'] - $a['w'] * $a['h'], $b_long - $a_long );
				}

				foreach ( $keys as $key ) {
					if ( 0 !== $key ) {
						return $key < 0 ? -1 : 1;
					}
				}

				// The same size turned the other way stays together.
				if ( $a['w'] !== $b['w'] ) {
					return $b['w'] < $a['w'] ? -1 : 1;
				}

				return $a['seq'] < $b['seq'] ? -1 : 1;
			}
		);

		return $list;
	}

	/**
	 * Lexicographic "a is better than b" over two score lists.
	 *
	 * @param array $a Score.
	 * @param array $b Score.
	 * @return bool
	 */
	private static function less( array $a, array $b ) {
		foreach ( $a as $i => $value ) {
			if ( $value < $b[ $i ] ) {
				return true;
			}
			if ( $value > $b[ $i ] ) {
				return false;
			}
		}
		return false;
	}

	/**
	 * What makes one run better than another, smallest first.
	 *
	 * @param array $run   A finished run.
	 * @param int   $index Its place in the strategy list (the last tie-break).
	 * @return array
	 */
	private static function objective( array $run, $index ) {
		$source_area   = 0;
		$pieces_area   = 0;
		$leftover_area = 0;
		$largeness     = 0.0;
		$o             = $run['o'];

		foreach ( $run['sources'] as $source ) {
			if ( empty( $source['opened'] ) ) {
				continue;
			}
			$source_area += $source['w'] * $source['h'];
			$pieces_area += $source['pieces_area'];
		}

		foreach ( $run['nodes'] as $node ) {
			if ( $node['piece'] >= 0 || empty( $run['sources'][ $node['src'] ]['opened'] ) ) {
				continue;
			}
			if ( self::keepable( $node['w'], $node['h'], $o ) ) {
				$area           = $node['w'] * $node['h'];
				$leftover_area += $area;
				$largeness     += (float) $area * (float) $area;
			}
		}

		$easy   = self::easy_cut( $o );
		$long   = 0;
		$length = 0;
		foreach ( $run['cuts'] as $cut ) {
			$span    = 'v' === $cut['axis'] ? $cut['part'][3] : $cut['part'][2];
			$length += $span;
			if ( $span > $easy ) {
				$long++;
			}
		}

		return array(
			count( $run['unplaced'] ),
			$run['sheets'],
			$long,
			$source_area - $pieces_area - $leftover_area,
			-$largeness,
			count( $run['cuts'] ),
			$length,
			$index,
		);
	}

	/**
	 * For every position in the sorted list, the smallest sizes among the
	 * pieces from there on (what a remainder must hold to be of any use)
	 * and how many pieces of the same size follow, itself included.
	 *
	 * @param array $list   Sorted pieces.
	 * @param bool  $rotate Whether pieces may be turned.
	 * @return array{short: int[], long: int[], w: int[], h: int[], same: int[], n: int}
	 */
	private static function tail( array $list, $rotate ) {
		$n    = count( $list );
		$tail = array(
			'short' => array(),
			'long'  => array(),
			'w'     => array(),
			'h'     => array(),
			'same'  => array(),
			'n'     => $n,
		);

		$min_short = PHP_INT_MAX;
		$min_long  = PHP_INT_MAX;
		$min_w     = PHP_INT_MAX;
		$min_h     = PHP_INT_MAX;
		$seen      = array();

		for ( $i = $n - 1; $i >= 0; $i-- ) {
			$w = $list[ $i ]['w'];
			$h = $list[ $i ]['h'];

			$min_short = min( $min_short, min( $w, $h ) );
			$min_long  = min( $min_long, max( $w, $h ) );
			$min_w     = min( $min_w, $w );
			$min_h     = min( $min_h, $h );

			$key          = $rotate ? min( $w, $h ) . 'x' . max( $w, $h ) : $w . 'x' . $h;
			$seen[ $key ] = isset( $seen[ $key ] ) ? $seen[ $key ] + 1 : 1;

			$tail['short'][ $i ] = $min_short;
			$tail['long'][ $i ]  = $min_long;
			$tail['w'][ $i ]     = $min_w;
			$tail['h'][ $i ]     = $min_h;
			$tail['same'][ $i ]  = $seen[ $key ];
		}

		return $tail;
	}

	/**
	 * Whether a rectangle could hold at least one of the pieces from a
	 * position on (a quick "no" — a "yes" only means "perhaps").
	 *
	 * @param int   $w      Size along x.
	 * @param int   $h      Size along y.
	 * @param array $tail   self::tail().
	 * @param int   $from   Position.
	 * @param bool  $rotate Whether pieces may be turned.
	 * @return bool
	 */
	private static function may_fit( $w, $h, array $tail, $from, $rotate ) {
		if ( $from >= $tail['n'] || $w <= 0 || $h <= 0 ) {
			return false;
		}

		if ( $rotate ) {
			return min( $w, $h ) >= $tail['short'][ $from ] && max( $w, $h ) >= $tail['long'][ $from ];
		}

		return $w >= $tail['w'][ $from ] && $h >= $tail['h'][ $from ];
	}

	/**
	 * One greedy run over a sorted list.
	 *
	 * @param array  $list  Sorted pieces.
	 * @param array  $stock Leftovers in stock { id, label, w, h }.
	 * @param array  $o     Options.
	 * @param string $mode  max|fill|short|long.
	 * @return array The run: nodes, cuts, sources, pieces, unplaced, sheets, o.
	 */
	private static function run( array $list, array $stock, array $o, $mode ) {
		$n      = count( $list );
		$k      = $o['kerf'];
		$rotate = $o['rotate'];
		$tail   = self::tail( $list, $rotate );

		$nodes    = array();
		$cuts     = array();
		$sources  = array();
		$active   = array();
		$unplaced = array();
		$sheets   = 0;

		foreach ( $stock as $index => $left ) {
			$sources[] = array(
				'kind'        => 'leftover',
				'stock'       => $index,
				'sheet'       => 0,
				'w'           => $left['w'],
				'h'           => $left['h'],
				'root'        => count( $nodes ),
				'opened'      => false,
				'pieces_area' => 0,
			);
			$nodes[]   = self::node( count( $sources ) - 1, 0, 0, $left['w'], $left['h'], -1 );
			$active[]  = count( $nodes ) - 1;
		}

		for ( $i = 0; $i < $n; $i++ ) {
			$p          = $list[ $i ];
			$pick       = null;
			$pick_score = null;
			$pick_node  = -1;

			foreach ( $active as $nid ) {
				$cand = self::candidate( $nodes[ $nid ], $p, $o, $mode, $tail, $i );
				if ( null === $cand ) {
					continue;
				}

				$score = array(
					$nodes[ $nid ]['w'] * $nodes[ $nid ]['h'],
					empty( $sources[ $nodes[ $nid ]['src'] ]['opened'] ) ? 1 : 0,
					$cand[0],
					$cand[1],
					$cand[2],
					$cand[3],
					$cand[4],
					$cand[5],
					$nid,
				);

				if ( null === $pick || self::less( $score, $pick_score ) ) {
					$pick       = $cand;
					$pick_score = $score;
					$pick_node  = $nid;
				}
			}

			if ( null === $pick ) {
				if ( ! self::fits( $p['w'], $p['h'], $o['sheet_w'], $o['sheet_h'], $rotate ) ) {
					$unplaced[] = array(
						'id'     => $p['id'],
						'w'      => $p['w'],
						'h'      => $p['h'],
						'reason' => 'too_big',
					);
					continue;
				}

				$sheets++;
				$sources[] = array(
					'kind'        => 'sheet',
					'stock'       => null,
					'sheet'       => $sheets,
					'w'           => $o['sheet_w'],
					'h'           => $o['sheet_h'],
					'root'        => count( $nodes ),
					'opened'      => false,
					'pieces_area' => 0,
				);
				$nodes[]   = self::node( count( $sources ) - 1, 0, 0, $o['sheet_w'], $o['sheet_h'], -1 );
				$pick_node = count( $nodes ) - 1;
				$pick      = self::candidate( $nodes[ $pick_node ], $p, $o, $mode, $tail, $i );
			}

			// Place the piece in the top-left corner of the chosen rectangle.
			$node    = $nodes[ $pick_node ];
			$pw      = $pick[6];
			$ph      = $pick[7];
			$x       = $node['x'];
			$y       = $node['y'];
			$rw      = $node['w'];
			$rh      = $node['h'];
			$src     = $node['src'];
			$created = array();
			$f1      = -1;
			$f2      = -1;
			$own     = array();

			$of      = $node['from'];
			$of_side = $of >= 0 ? 'far' : '';

			if ( 0 === $pick[5] ) {
				// A cut from top to bottom first: the strip on the left holds the piece.
				$strip = array( $x, $y, $pw, $rh );

				if ( $pw < $rw ) {
					$cut_id = count( $cuts );
					if ( $rw - $pw - $k > 0 ) {
						$f1        = count( $nodes );
						$nodes[]   = self::node( $src, $x + $pw + $k, $y, $rw - $pw - $k, $rh, $cut_id );
						$created[] = $f1;
					}
					$cuts[]  = array(
						'src'        => $src,
						'axis'       => 'v',
						'at'         => $pw,
						'part'       => array( $x, $y, $rw, $rh ),
						'of'         => $of,
						'side'       => $of_side,
						'near'       => $strip,
						'near_piece' => $ph === $rh,
						'far'        => $f1,
						'node'       => $pick_node,
					);
					$own[]   = $cut_id;
					$of      = $cut_id;
					$of_side = 'near';
				}

				if ( $ph < $rh ) {
					$cut_id = count( $cuts );
					if ( $rh - $ph - $k > 0 ) {
						$f2        = count( $nodes );
						$nodes[]   = self::node( $src, $x, $y + $ph + $k, $pw, $rh - $ph - $k, $cut_id );
						$created[] = $f2;
					}
					$cuts[] = array(
						'src'        => $src,
						'axis'       => 'h',
						'at'         => $ph,
						'part'       => $strip,
						'of'         => $of,
						'side'       => $of_side,
						'near'       => array( $x, $y, $pw, $ph ),
						'near_piece' => true,
						'far'        => $f2,
						'node'       => $pick_node,
					);
					$own[]  = $cut_id;
				}
			} else {
				// A cut from left to right first: the strip on top holds the piece.
				$strip = array( $x, $y, $rw, $ph );

				if ( $ph < $rh ) {
					$cut_id = count( $cuts );
					if ( $rh - $ph - $k > 0 ) {
						$f1        = count( $nodes );
						$nodes[]   = self::node( $src, $x, $y + $ph + $k, $rw, $rh - $ph - $k, $cut_id );
						$created[] = $f1;
					}
					$cuts[]  = array(
						'src'        => $src,
						'axis'       => 'h',
						'at'         => $ph,
						'part'       => array( $x, $y, $rw, $rh ),
						'of'         => $of,
						'side'       => $of_side,
						'near'       => $strip,
						'near_piece' => $pw === $rw,
						'far'        => $f1,
						'node'       => $pick_node,
					);
					$own[]   = $cut_id;
					$of      = $cut_id;
					$of_side = 'near';
				}

				if ( $pw < $rw ) {
					$cut_id = count( $cuts );
					if ( $rw - $pw - $k > 0 ) {
						$f2        = count( $nodes );
						$nodes[]   = self::node( $src, $x + $pw + $k, $y, $rw - $pw - $k, $ph, $cut_id );
						$created[] = $f2;
					}
					$cuts[] = array(
						'src'        => $src,
						'axis'       => 'v',
						'at'         => $pw,
						'part'       => $strip,
						'of'         => $of,
						'side'       => $of_side,
						'near'       => array( $x, $y, $pw, $ph ),
						'near_piece' => true,
						'far'        => $f2,
						'node'       => $pick_node,
					);
					$own[]  = $cut_id;
				}
			}

			$nodes[ $pick_node ]['piece'] = $i;
			$nodes[ $pick_node ]['pw']    = $pw;
			$nodes[ $pick_node ]['ph']    = $ph;
			$nodes[ $pick_node ]['rot']   = 1 === $pick[4];
			$nodes[ $pick_node ]['cuts']  = $own;
			$nodes[ $pick_node ]['f1']    = $f1;
			$nodes[ $pick_node ]['f2']    = $f2;

			$sources[ $src ]['opened']       = true;
			$sources[ $src ]['pieces_area'] += $pw * $ph;

			// Keep only the free rectangles a later piece could still go in.
			$next   = $i + 1;
			$remain = array();
			foreach ( $active as $nid ) {
				if ( $nid !== $pick_node && self::may_fit( $nodes[ $nid ]['w'], $nodes[ $nid ]['h'], $tail, $next, $rotate ) ) {
					$remain[] = $nid;
				}
			}
			foreach ( $created as $nid ) {
				if ( self::may_fit( $nodes[ $nid ]['w'], $nodes[ $nid ]['h'], $tail, $next, $rotate ) ) {
					$remain[] = $nid;
				}
			}
			$active = $remain;
		}

		return array(
			'nodes'    => $nodes,
			'cuts'     => $cuts,
			'sources'  => $sources,
			'pieces'   => $list,
			'unplaced' => $unplaced,
			'sheets'   => $sheets,
			'o'        => $o,
		);
	}

	/**
	 * A free rectangle.
	 *
	 * @param int $src  Source index.
	 * @param int $x    Left.
	 * @param int $y    Top.
	 * @param int $w    Size along x.
	 * @param int $h    Size along y.
	 * @param int $from The cut that made it (−1 for a whole source).
	 * @return array
	 */
	private static function node( $src, $x, $y, $w, $h, $from ) {
		return array(
			'src'   => $src,
			'x'     => $x,
			'y'     => $y,
			'w'     => $w,
			'h'     => $h,
			'from'  => $from,
			'piece' => -1,
			'pw'    => 0,
			'ph'    => 0,
			'rot'   => false,
			'cuts'  => array(),
			'f1'    => -1,
			'f2'    => -1,
		);
	}

	/**
	 * The best way to put a piece into one free rectangle, or null when it
	 * does not fit: [ cuts longer than an easy cut, scrap, rule score,
	 * −larger remainder, turned (0|1), first cut (0 = top to bottom, 1 =
	 * left to right), w, h ].
	 *
	 * @param array  $node Free rectangle.
	 * @param array  $p    Piece.
	 * @param array  $o    Options.
	 * @param string $mode max|fill|short|long.
	 * @param array  $tail self::tail().
	 * @param int    $i    The piece's position in the sorted list.
	 * @return array|null
	 */
	private static function candidate( array $node, array $p, array $o, $mode, array $tail, $i ) {
		$rw   = $node['w'];
		$rh   = $node['h'];
		$k    = $o['kerf'];
		$easy = self::easy_cut( $o );
		$best = null;

		$turns = array( array( $p['w'], $p['h'], 0 ) );
		if ( $o['rotate'] && $p['w'] !== $p['h'] ) {
			$turns[] = array( $p['h'], $p['w'], 1 );
		}

		foreach ( $turns as $turn ) {
			$pw = $turn[0];
			$ph = $turn[1];

			if ( $pw > $rw || $ph > $rh ) {
				continue;
			}

			$dw     = $rw - $pw;
			$dh     = $rh - $ph;
			$splits = ( 0 === $dw || 0 === $dh ) ? array( 0 ) : array( 0, 1 );

			foreach ( $splits as $split ) {
				if ( 0 === $split ) {
					$f1w = $dw - $k;
					$f1h = $rh;
					$f2w = $pw;
					$f2h = $dh - $k;
				} else {
					$f1w = $rw;
					$f1h = $dh - $k;
					$f2w = $dw - $k;
					$f2h = $ph;
				}

				$a1     = ( $f1w > 0 && $f1h > 0 ) ? $f1w * $f1h : 0;
				$a2     = ( $f2w > 0 && $f2h > 0 ) ? $f2w * $f2h : 0;
				$useful = 0;

				if ( $a1 > 0 && ( self::keepable( $f1w, $f1h, $o ) || self::may_fit( $f1w, $f1h, $tail, $i + 1, $o['rotate'] ) ) ) {
					$useful += $a1;
				}
				if ( $a2 > 0 && ( self::keepable( $f2w, $f2h, $o ) || self::may_fit( $f2w, $f2h, $tail, $i + 1, $o['rotate'] ) ) ) {
					$useful += $a2;
				}

				$rule = 0;

				if ( 0 !== $dw && 0 !== $dh ) {
					if ( 'short' === $mode ) {
						$rule = ( ( $dw <= $dh ) ? 1 : 0 ) === $split ? 0 : 1;
					} elseif ( 'long' === $mode ) {
						$rule = ( ( $dw > $dh ) ? 1 : 0 ) === $split ? 0 : 1;
					} elseif ( 'fill' === $mode ) {
						// How much of the strip this cut opens is lost at its end
						// when it is filled with the pieces of this size still to come.
						$run   = 0 === $split ? $rh : $rw;
						$step  = 0 === $split ? $ph : $pw;
						$wide  = 0 === $split ? $pw : $ph;
						$same  = $tail['same'][ $i ];
						$count = min( $same, intdiv( $run + $k, $step + $k ) );
						$end   = max( 0, $run - $count * $step - $count * $k );
						$rule  = $count < $same ? intdiv( $wide * $end * 1000, $count * $pw * $ph ) : 0;
					}
				}

				// The first cut crosses the whole free rectangle, the second
				// only the strip the piece is in.
				if ( 0 === $split ) {
					$long = ( $dw > 0 && $rh > $easy ? 1 : 0 ) + ( $dh > 0 && $pw > $easy ? 1 : 0 );
				} else {
					$long = ( $dh > 0 && $rw > $easy ? 1 : 0 ) + ( $dw > 0 && $ph > $easy ? 1 : 0 );
				}

				$score = array( $long, $rw * $rh - $pw * $ph - $useful, $rule, -max( $a1, $a2 ), $turn[2], $split, $pw, $ph );

				if ( null === $best || self::less( $score, $best ) ) {
					$best = $score;
				}
			}
		}

		return $best;
	}

	/* ============================================================== */
	/*  The result                                                     */
	/* ============================================================== */

	/**
	 * A run as the result pack() returns: per source that was cut into,
	 * the pieces, the cuts in the order they are made (a strip is finished
	 * before the rest of the sheet is touched), and what is left.
	 *
	 * @param array $run A finished run.
	 * @param array $o   Options.
	 * @return array
	 */
	private static function finish( array $run, array $o ) {
		$nodes   = $run['nodes'];
		$cuts    = $run['cuts'];
		$pieces  = $run['pieces'];
		$sources = array();

		foreach ( $run['sources'] as $source ) {
			if ( empty( $source['opened'] ) ) {
				continue;
			}

			// Walk the cuts depth first: a rectangle's own cuts, then the
			// rest of its strip, then the rest of the rectangle.
			$number = array();
			$order  = array();
			$placed = array();
			$free   = array();
			$stack  = array( $source['root'] );

			while ( $stack ) {
				$nid  = array_pop( $stack );
				$node = $nodes[ $nid ];

				if ( $node['piece'] < 0 ) {
					$free[] = $nid;
					continue;
				}

				foreach ( $node['cuts'] as $cut_id ) {
					$order[]           = $cut_id;
					$number[ $cut_id ] = count( $order );
				}
				$placed[] = $nid;

				if ( $node['f1'] >= 0 ) {
					$stack[] = $node['f1'];
				}
				if ( $node['f2'] >= 0 ) {
					$stack[] = $node['f2'];
				}
			}

			// What is left, top to bottom then left to right.
			usort(
				$free,
				static function ( $a, $b ) use ( $nodes ) {
					if ( $nodes[ $a ]['y'] !== $nodes[ $b ]['y'] ) {
						return $nodes[ $a ]['y'] < $nodes[ $b ]['y'] ? -1 : 1;
					}
					if ( $nodes[ $a ]['x'] !== $nodes[ $b ]['x'] ) {
						return $nodes[ $a ]['x'] < $nodes[ $b ]['x'] ? -1 : 1;
					}
					return $a < $b ? -1 : 1;
				}
			);

			$free_index    = array();
			$free_out      = array();
			$leftover_area = 0;
			foreach ( $free as $index => $nid ) {
				$keep               = self::keepable( $nodes[ $nid ]['w'], $nodes[ $nid ]['h'], $o );
				$free_index[ $nid ] = $index;
				$free_out[]         = array(
					'x'    => $nodes[ $nid ]['x'],
					'y'    => $nodes[ $nid ]['y'],
					'w'    => $nodes[ $nid ]['w'],
					'h'    => $nodes[ $nid ]['h'],
					'keep' => $keep,
				);
				if ( $keep ) {
					$leftover_area += $nodes[ $nid ]['w'] * $nodes[ $nid ]['h'];
				}
			}

			$piece_index = array();
			$piece_out   = array();
			foreach ( $placed as $index => $nid ) {
				$node                = $nodes[ $nid ];
				$piece_index[ $nid ] = $index;
				$piece_out[]         = array(
					'id'      => $pieces[ $node['piece'] ]['id'],
					'x'       => $node['x'],
					'y'       => $node['y'],
					'w'       => $node['pw'],
					'h'       => $node['ph'],
					'rotated' => (bool) $node['rot'],
				);
			}

			$cut_out = array();
			foreach ( $order as $cut_id ) {
				$cut  = $cuts[ $cut_id ];
				$near = array(
					'kind'  => $cut['near_piece'] ? 'piece' : 'more',
					'x'     => $cut['near'][0],
					'y'     => $cut['near'][1],
					'w'     => $cut['near'][2],
					'h'     => $cut['near'][3],
					'piece' => $cut['near_piece'] ? $piece_index[ $cut['node'] ] : null,
					'free'  => null,
				);

				$far = array(
					'kind'  => 'none',
					'x'     => 0,
					'y'     => 0,
					'w'     => 0,
					'h'     => 0,
					'piece' => null,
					'free'  => null,
				);
				if ( $cut['far'] >= 0 ) {
					$node     = $nodes[ $cut['far'] ];
					$far['x'] = $node['x'];
					$far['y'] = $node['y'];
					$far['w'] = $node['w'];
					$far['h'] = $node['h'];

					if ( $node['piece'] >= 0 ) {
						$far['kind'] = $node['cuts'] ? 'more' : 'piece';
						if ( ! $node['cuts'] ) {
							$far['piece'] = $piece_index[ $cut['far'] ];
						}
					} else {
						$far['free'] = $free_index[ $cut['far'] ];
						$far['kind'] = $free_out[ $far['free'] ]['keep'] ? 'leftover' : 'waste';
					}
				}

				$cut_out[] = array(
					'n'    => $number[ $cut_id ],
					'axis' => $cut['axis'],
					'at'   => $cut['at'],
					'part' => array(
						'x' => $cut['part'][0],
						'y' => $cut['part'][1],
						'w' => $cut['part'][2],
						'h' => $cut['part'][3],
					),
					'of'   => $cut['of'] >= 0 ? $number[ $cut['of'] ] : 0,
					'side' => $cut['side'],
					'near' => $near,
					'far'  => $far,
				);
			}

			$area      = $source['w'] * $source['h'];
			$sources[] = array(
				'kind'          => $source['kind'],
				'stock'         => $source['stock'],
				'sheet'         => $source['sheet'],
				'w'             => $source['w'],
				'h'             => $source['h'],
				'pieces'        => $piece_out,
				'cuts'          => $cut_out,
				'free'          => $free_out,
				'area'          => $area,
				'pieces_area'   => $source['pieces_area'],
				'leftover_area' => $leftover_area,
				'waste_area'    => $area - $source['pieces_area'] - $leftover_area,
			);
		}

		return array(
			'sources'  => $sources,
			'unplaced' => $run['unplaced'],
			'sheets'   => $run['sheets'],
		);
	}

	/**
	 * The leftovers as the packer wants them: a positive size, in the
	 * order given.
	 *
	 * @param array $leftovers [ { id, label, w, h } ].
	 * @return array
	 */
	private static function clean_leftovers( array $leftovers ) {
		$out = array();
		foreach ( array_values( $leftovers ) as $left ) {
			$w = isset( $left['w'] ) ? (int) $left['w'] : 0;
			$h = isset( $left['h'] ) ? (int) $left['h'] : 0;
			if ( $w > 0 && $h > 0 ) {
				$left['w'] = $w;
				$left['h'] = $h;
				$out[]     = $left;
			}
		}
		return $out;
	}

	/* ============================================================== */
	/*  Checking a result                                              */
	/* ============================================================== */

	/**
	 * Check a result the hard way, independently of how it was made: every
	 * piece inside its source, no two pieces (or a piece and a remainder)
	 * overlapping, and the cuts replayed one by one — each must run edge to
	 * edge across a part that exists at that moment, and when all are made
	 * the parts on the table must be exactly the pieces and the remainders.
	 * A plan that fails is never shown as a cut sheet.
	 *
	 * @param array $result pack() result.
	 * @param array $opts   The options it was made with.
	 * @return string[] Problems (empty = sound).
	 */
	public static function verify( array $result, array $opts ) {
		$o        = self::options( $opts );
		$k        = $o['kerf'];
		$problems = array();

		foreach ( $result['sources'] as $s => $source ) {
			$name  = 'source ' . ( $s + 1 );
			$rects = array();

			foreach ( $source['pieces'] as $index => $piece ) {
				if ( $piece['w'] <= 0 || $piece['h'] <= 0 || $piece['x'] < 0 || $piece['y'] < 0 || $piece['x'] + $piece['w'] > $source['w'] || $piece['y'] + $piece['h'] > $source['h'] ) {
					$problems[] = $name . ': piece ' . ( $index + 1 ) . ' is outside the source';
				}
				$rects[] = array( $piece['x'], $piece['y'], $piece['w'], $piece['h'] );
			}
			foreach ( $source['free'] as $index => $free ) {
				if ( $free['w'] <= 0 || $free['h'] <= 0 || $free['x'] < 0 || $free['y'] < 0 || $free['x'] + $free['w'] > $source['w'] || $free['y'] + $free['h'] > $source['h'] ) {
					$problems[] = $name . ': remainder ' . ( $index + 1 ) . ' is outside the source';
				}
				$rects[] = array( $free['x'], $free['y'], $free['w'], $free['h'] );
			}

			$count = count( $rects );
			for ( $a = 0; $a < $count; $a++ ) {
				for ( $b = $a + 1; $b < $count; $b++ ) {
					if ( $rects[ $a ][0] < $rects[ $b ][0] + $rects[ $b ][2] && $rects[ $b ][0] < $rects[ $a ][0] + $rects[ $a ][2]
						&& $rects[ $a ][1] < $rects[ $b ][1] + $rects[ $b ][3] && $rects[ $b ][1] < $rects[ $a ][1] + $rects[ $a ][3] ) {
						$problems[] = $name . ': two parts overlap';
					}
				}
			}

			// Replay the cuts.
			$parts = array( '0,0,' . $source['w'] . ',' . $source['h'] => true );
			foreach ( $source['cuts'] as $index => $cut ) {
				$part = $cut['part'];
				$key  = $part['x'] . ',' . $part['y'] . ',' . $part['w'] . ',' . $part['h'];

				if ( $cut['n'] !== $index + 1 ) {
					$problems[] = $name . ': the cuts are not numbered in order';
				}
				if ( ! isset( $parts[ $key ] ) ) {
					$problems[] = $name . ': cut ' . $cut['n'] . ' is made on a part that does not exist yet';
					continue;
				}
				unset( $parts[ $key ] );

				$span = 'v' === $cut['axis'] ? $part['w'] : $part['h'];
				if ( $cut['at'] <= 0 || $cut['at'] >= $span ) {
					$problems[] = $name . ': cut ' . $cut['n'] . ' does not cross its part';
					continue;
				}

				if ( 'v' === $cut['axis'] ) {
					$near = array( $part['x'], $part['y'], $cut['at'], $part['h'] );
					$rest = $part['w'] - $cut['at'] - $k;
					$far  = $rest > 0 ? array( $part['x'] + $cut['at'] + $k, $part['y'], $rest, $part['h'] ) : null;
				} else {
					$near = array( $part['x'], $part['y'], $part['w'], $cut['at'] );
					$rest = $part['h'] - $cut['at'] - $k;
					$far  = $rest > 0 ? array( $part['x'], $part['y'] + $cut['at'] + $k, $part['w'], $rest ) : null;
				}

				if ( array( $cut['near']['x'], $cut['near']['y'], $cut['near']['w'], $cut['near']['h'] ) !== $near ) {
					$problems[] = $name . ': cut ' . $cut['n'] . ' does not give the part it says it gives';
				}
				if ( null === $far ) {
					if ( 'none' !== $cut['far']['kind'] ) {
						$problems[] = $name . ': cut ' . $cut['n'] . ' names a far part the kerf has eaten';
					}
				} elseif ( 'none' === $cut['far']['kind'] || array( $cut['far']['x'], $cut['far']['y'], $cut['far']['w'], $cut['far']['h'] ) !== $far ) {
					$problems[] = $name . ': cut ' . $cut['n'] . ' does not leave the part it says it leaves';
				}

				$parts[ implode( ',', $near ) ] = true;
				if ( null !== $far ) {
					$parts[ implode( ',', $far ) ] = true;
				}
			}

			$expected = array();
			foreach ( $rects as $rect ) {
				$expected[ implode( ',', $rect ) ] = true;
			}
			ksort( $parts );
			ksort( $expected );
			if ( array_keys( $parts ) !== array_keys( $expected ) ) {
				$problems[] = $name . ': after the last cut the parts are not exactly the pieces and the remainders';
			}

			$pieces_area = 0;
			foreach ( $source['pieces'] as $piece ) {
				$pieces_area += $piece['w'] * $piece['h'];
			}
			if ( $pieces_area !== $source['pieces_area'] || $source['waste_area'] < 0 ) {
				$problems[] = $name . ': the areas do not add up';
			}
		}

		return $problems;
	}
}
