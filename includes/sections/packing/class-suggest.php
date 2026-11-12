<?php
/**
 * The box suggestion — PURE: no WordPress, no WooCommerce, no settings.
 *
 * The rule, as the owner packs:
 *
 *   - one bag per fish (a line's "fish per bag" can raise that —
 *     bags_for() — the seam for a product-level number later);
 *   - the fill of a box = the sum over the bag sizes of
 *     bags needed ÷ how many of that size fit the box. Six small bags in a
 *     box that takes twelve and two large ones in a box that takes six is
 *     6/12 + 2/6 = 83 %;
 *   - a bag size with no "how many fit" for a box does not go in that box
 *     at all; a bag size that goes in NO box is set aside and named, and
 *     the rest is boxed as usual (the status is then "partial");
 *   - the suggestion is the SMALLEST box (by volume) whose fill is at most
 *     100 %;
 *   - when no box holds it all, it is split: the largest box that takes
 *     any of what is left is filled (bulkiest bags first), and so on —
 *     except that as soon as one box holds everything that is left, the
 *     smallest such box takes it;
 *   - a box is "on the line" when its fill is at or over the threshold
 *     (90 % unless the settings say otherwise): it is the owner's call.
 *
 * Fills are compared after rounding the percent to four places, so 6/12 +
 * 3/6 is exactly 100 % and 9/10 is exactly 90 %, whatever floating point
 * makes of the sums.
 *
 * @package FisHotel\Misc\Sections\Packing
 */

namespace FisHotel\Misc\Sections\Packing;

defined( 'ABSPATH' ) || exit;

/**
 * Class Suggest
 */
class Suggest {

	const STATUS_FITS     = 'fits';     // One box holds every bag.
	const STATUS_SPLIT    = 'split';    // Several boxes hold every bag.
	const STATUS_PARTIAL  = 'partial';  // Boxes for what can be boxed; some bags go in no box.
	const STATUS_NO_FIT   = 'no_fit';   // No bag goes in any box.
	const STATUS_NO_BOXES = 'no_boxes'; // There are no boxes.
	const STATUS_EMPTY    = 'empty';    // No bags are needed.

	/**
	 * A split never runs to more boxes than this.
	 *
	 * @var int
	 */
	const MAX_BOXES = 40;

	/**
	 * How many bags a quantity of fish needs: one each, or — with more
	 * than one fish per bag — as many bags as it takes.
	 *
	 * @param mixed $qty     Fish.
	 * @param mixed $per_bag Fish per bag (anything below 1 counts as 1).
	 * @return int
	 */
	public static function bags_for( $qty, $per_bag = 1 ) {
		$qty     = is_numeric( $qty ) ? (int) $qty : 0;
		$per_bag = is_numeric( $per_bag ) ? (int) $per_bag : 1;
		if ( $qty <= 0 ) {
			return 0;
		}
		if ( $per_bag <= 1 ) {
			return $qty;
		}
		return (int) ceil( $qty / $per_bag );
	}

	/**
	 * Bags needed, cleaned: bag id → a whole number above 0.
	 *
	 * @param array $needs Bag id → bags.
	 * @return array<int, int>
	 */
	public static function needs( array $needs ) {
		$out = array();
		foreach ( $needs as $bag_id => $count ) {
			$bag_id = (int) $bag_id;
			$count  = is_numeric( $count ) ? (int) $count : 0;
			if ( $bag_id > 0 && $count > 0 ) {
				$out[ $bag_id ] = ( $out[ $bag_id ] ?? 0 ) + $count;
			}
		}
		return $out;
	}

	/**
	 * The fill of a box (1.0 = full), or null when a bag size that is
	 * needed does not go in it.
	 *
	 * @param array $needs Bag id → bags needed.
	 * @param array $fits  Bag id → how many fit the box.
	 * @return float|null
	 */
	public static function fill( array $needs, array $fits ) {
		$fill = 0.0;
		foreach ( $needs as $bag_id => $count ) {
			$count = (int) $count;
			if ( $count <= 0 ) {
				continue;
			}
			$fit = isset( $fits[ $bag_id ] ) ? (int) $fits[ $bag_id ] : 0;
			if ( $fit < 1 ) {
				return null;
			}
			$fill += $count / $fit;
		}
		return $fill;
	}

	/**
	 * A fill as a percent, rounded to four places — the number every
	 * comparison uses.
	 *
	 * @param float $fill Fill (1.0 = full).
	 * @return float
	 */
	public static function pct( $fill ) {
		return round( (float) $fill * 100, 4 );
	}

	/**
	 * Whether a fill is on the line: at or over the threshold.
	 *
	 * @param float $fill      Fill (1.0 = full).
	 * @param mixed $threshold Percent.
	 * @return bool
	 */
	public static function on_the_line( $fill, $threshold ) {
		return self::pct( $fill ) >= (float) $threshold;
	}

	/**
	 * The threshold as a number from 1 to 100 (the default otherwise).
	 *
	 * @param mixed $threshold Raw.
	 * @return float
	 */
	public static function threshold( $threshold ) {
		$value = is_numeric( $threshold ) ? (float) $threshold : 0.0;
		return ( $value < 1 || $value > 100 ) ? 90.0 : $value;
	}

	/**
	 * The suggestion.
	 *
	 * @param array   $needs     Bag id → bags needed.
	 * @param array[] $boxes     Boxes::with_fits(): each with key, name, volume and fits (bag id → how many).
	 * @param mixed   $threshold The "on the line" percent.
	 * @param int[]   $bag_order Bag ids in the bag list's order (ties between bags of equal bulk).
	 * @return array{status: string, boxes: array[], split: bool, on_the_line: bool, unplaced: array<int, int>, threshold: float, bag_total: int}
	 */
	public static function suggest( array $needs, array $boxes, $threshold = 90, array $bag_order = array() ) {
		$needs     = self::needs( $needs );
		$threshold = self::threshold( $threshold );
		$boxes     = array_values( array_filter( $boxes, 'is_array' ) );

		$out = array(
			'status'      => self::STATUS_EMPTY,
			'boxes'       => array(),
			'split'       => false,
			'on_the_line' => false,
			'unplaced'    => array(),
			'threshold'   => $threshold,
			'bag_total'   => array_sum( $needs ),
		);

		if ( ! $needs ) {
			return $out;
		}
		if ( ! $boxes ) {
			$out['status']   = self::STATUS_NO_BOXES;
			$out['unplaced'] = $needs;
			return $out;
		}

		// Bag sizes that go in no box at all are set aside: the rest is boxed.
		$never = array();
		foreach ( $needs as $bag_id => $count ) {
			$goes = false;
			foreach ( $boxes as $box ) {
				if ( isset( $box['fits'][ $bag_id ] ) && (int) $box['fits'][ $bag_id ] >= 1 ) {
					$goes = true;
					break;
				}
			}
			if ( ! $goes ) {
				$never[ $bag_id ] = $count;
			}
		}
		$placeable = array_diff_key( $needs, $never );
		if ( ! $placeable ) {
			$out['status']   = self::STATUS_NO_FIT;
			$out['unplaced'] = $never;
			return $out;
		}

		// One box that holds it all: the smallest.
		$single = self::smallest_holding( $placeable, $boxes );
		if ( null !== $single ) {
			$out['status']      = $never ? self::STATUS_PARTIAL : self::STATUS_FITS;
			$out['boxes'][]     = self::placed( $single[0], $placeable, $single[1], $threshold );
			$out['on_the_line'] = $out['boxes'][0]['on_the_line'];
			$out['unplaced']    = $never;
			return $out;
		}

		// None does: split, the largest first.
		$largest_first = Boxes::by_volume( $boxes, true );
		$remaining     = $placeable;

		while ( $remaining && count( $out['boxes'] ) < self::MAX_BOXES ) {
			if ( $out['boxes'] ) {
				$rest = self::smallest_holding( $remaining, $boxes );
				if ( null !== $rest ) {
					$out['boxes'][] = self::placed( $rest[0], $remaining, $rest[1], $threshold );
					$remaining      = array();
					break;
				}
			}

			$took = false;
			foreach ( $largest_first as $box ) {
				$load = self::pack( $remaining, isset( $box['fits'] ) && is_array( $box['fits'] ) ? $box['fits'] : array(), $bag_order );
				if ( ! $load[0] ) {
					continue;
				}
				$out['boxes'][] = self::placed( $box, $load[0], $load[1], $threshold );
				foreach ( $load[0] as $bag_id => $count ) {
					$remaining[ $bag_id ] -= $count;
					if ( $remaining[ $bag_id ] <= 0 ) {
						unset( $remaining[ $bag_id ] );
					}
				}
				$took = true;
				break;
			}
			if ( ! $took ) {
				break;
			}
		}

		// What is left only when the split ran into MAX_BOXES.
		$out['unplaced'] = $never + $remaining;
		$out['status']   = $out['unplaced'] ? self::STATUS_PARTIAL : self::STATUS_SPLIT;
		$out['split']    = count( $out['boxes'] ) > 1;
		foreach ( $out['boxes'] as $box ) {
			if ( $box['on_the_line'] ) {
				$out['on_the_line'] = true;
			}
		}

		return $out;
	}

	/**
	 * Every box against the whole need — what the "choose another box"
	 * list shows: the fill (null when a bag size does not go in the box),
	 * whether it holds it all, whether it is on the line, and the bag
	 * sizes that do not go in it.
	 *
	 * @param array   $needs     Bag id → bags needed.
	 * @param array[] $boxes     Boxes::with_fits().
	 * @param mixed   $threshold The "on the line" percent.
	 * @return array[] The boxes, smallest first, each with fill, fill_pct, holds, on_the_line, missing (bag ids).
	 */
	public static function candidates( array $needs, array $boxes, $threshold = 90 ) {
		$needs     = self::needs( $needs );
		$threshold = self::threshold( $threshold );
		$out       = array();

		foreach ( Boxes::by_volume( array_values( array_filter( $boxes, 'is_array' ) ) ) as $box ) {
			$fits    = isset( $box['fits'] ) && is_array( $box['fits'] ) ? $box['fits'] : array();
			$fill    = self::fill( $needs, $fits );
			$missing = array();
			foreach ( $needs as $bag_id => $count ) {
				if ( ! isset( $fits[ $bag_id ] ) || (int) $fits[ $bag_id ] < 1 ) {
					$missing[] = (int) $bag_id;
				}
			}

			$box['fill']        = null === $fill ? null : round( $fill, 6 );
			$box['fill_pct']    = null === $fill ? null : round( $fill * 100, 1 );
			$box['holds']       = null !== $fill && self::pct( $fill ) <= 100.0;
			$box['on_the_line'] = null !== $fill && self::on_the_line( $fill, $threshold );
			$box['missing']     = $missing;
			$out[]              = $box;
		}

		return $out;
	}

	/**
	 * The smallest box (by volume; of two the same size the emptier, then
	 * by name) whose fill for these bags is at most 100 %.
	 *
	 * @param array   $needs Bag id → bags.
	 * @param array[] $boxes Boxes with fits.
	 * @return array{0: array, 1: float}|null The box and its fill.
	 */
	private static function smallest_holding( array $needs, array $boxes ) {
		$best = null;
		foreach ( $boxes as $box ) {
			$fill = self::fill( $needs, isset( $box['fits'] ) && is_array( $box['fits'] ) ? $box['fits'] : array() );
			if ( null === $fill || self::pct( $fill ) > 100.0 ) {
				continue;
			}
			if ( null === $best ) {
				$best = array( $box, $fill );
				continue;
			}
			$by_volume = (float) $box['volume'] <=> (float) $best[0]['volume'];
			if ( $by_volume < 0
				|| ( 0 === $by_volume && self::pct( $fill ) < self::pct( $best[1] ) )
				|| ( 0 === $by_volume && self::pct( $fill ) === self::pct( $best[1] ) && strnatcasecmp( (string) $box['name'], (string) $best[0]['name'] ) < 0 )
			) {
				$best = array( $box, $fill );
			}
		}
		return $best;
	}

	/**
	 * Fill one box from what is left: the bulkiest bags (those the box
	 * takes fewest of) first, then whatever still goes in.
	 *
	 * @param array $remaining Bag id → bags left.
	 * @param array $fits      Bag id → how many fit the box.
	 * @param int[] $bag_order Bag ids in list order.
	 * @return array{0: array<int, int>, 1: float} What goes in, and the fill.
	 */
	private static function pack( array $remaining, array $fits, array $bag_order ) {
		$position = array_flip( array_map( 'intval', array_values( $bag_order ) ) );
		$ids      = array();
		foreach ( $remaining as $bag_id => $count ) {
			if ( (int) $count > 0 && isset( $fits[ $bag_id ] ) && (int) $fits[ $bag_id ] >= 1 ) {
				$ids[] = (int) $bag_id;
			}
		}

		usort(
			$ids,
			static function ( $a, $b ) use ( $fits, $position ) {
				$by_bulk = (int) $fits[ $a ] <=> (int) $fits[ $b ];
				if ( 0 !== $by_bulk ) {
					return $by_bulk;
				}
				$by_list = ( $position[ $a ] ?? PHP_INT_MAX ) <=> ( $position[ $b ] ?? PHP_INT_MAX );
				return 0 !== $by_list ? $by_list : ( $a <=> $b );
			}
		);

		$load = array();
		$fill = 0.0;
		foreach ( $ids as $bag_id ) {
			$fit  = (int) $fits[ $bag_id ];
			$room = (int) floor( ( 1 - $fill ) * $fit + 1e-7 );
			$take = min( (int) $remaining[ $bag_id ], max( 0, $room ) );
			if ( $take > 0 ) {
				$load[ $bag_id ] = $take;
				$fill           += $take / $fit;
			}
		}

		return array( $load, $fill );
	}

	/**
	 * One box of the suggestion.
	 *
	 * @param array $box       The box.
	 * @param array $load      Bag id → how many go in it.
	 * @param float $fill      Its fill.
	 * @param float $threshold The "on the line" percent.
	 * @return array
	 */
	private static function placed( array $box, array $load, $fill, $threshold ) {
		ksort( $load ); // By bag id, whatever order the bags were taken in.

		return array(
			'key'         => (string) ( $box['key'] ?? '' ),
			'name'        => (string) ( $box['name'] ?? '' ),
			'length'      => (float) ( $box['length'] ?? 0 ),
			'width'       => (float) ( $box['width'] ?? 0 ),
			'height'      => (float) ( $box['height'] ?? 0 ),
			'dims'        => (string) ( $box['dims'] ?? '' ),
			'volume'      => (float) ( $box['volume'] ?? 0 ),
			'source'      => (string) ( $box['source'] ?? '' ),
			'bags'        => $load,
			'fill'        => round( (float) $fill, 6 ),
			'fill_pct'    => round( (float) $fill * 100, 1 ),
			'on_the_line' => self::on_the_line( $fill, $threshold ),
		);
	}
}
