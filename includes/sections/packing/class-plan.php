<?php
/**
 * The bag every fish of an order goes in — PURE: the box (or boxes) is
 * already chosen; this puts the fish in them and upsizes inside each box.
 *
 *   - The boxes are the CONFIRMED ones when the order has them (confirming
 *     a different box works the bags out again for that box), else the
 *     suggestion's.
 *   - Each box takes the fish the suggestion put in it (by their minimum
 *     bag, in the order of the lines). A confirmed box that is not the
 *     suggestion takes every fish whose minimum bag goes in it; several of
 *     them are filled one after the other, the bulkiest bags first, and
 *     what is left goes in the last one that takes its bag. A fish whose
 *     minimum bag none of the confirmed boxes takes is left out of them
 *     (said under left_out when another box on the list would take it).
 *   - Inside each box, Upsize moves the fish up (the round robin).
 *   - A line whose bag was chosen by hand (an override on the order) keeps
 *     that bag; it takes its space first.
 *
 * Fish that no box takes keep their minimum bag (or the hand-chosen one).
 *
 * @package FisHotel\Misc\Sections\Packing
 */

namespace FisHotel\Misc\Sections\Packing;

defined( 'ABSPATH' ) || exit;

/**
 * Class Plan
 */
class Plan {

	const FROM_SUGGESTION = 'suggestion';
	const FROM_CONFIRMED  = 'confirmed';

	/**
	 * Work the bags out.
	 *
	 * @param array[]      $lines      The order's fish lines (key, bag, bags).
	 * @param array        $suggestion Suggest::suggest(): status, boxes (key, name, bags = the load).
	 * @param array[]      $fitted     The boxes in use with their fits (Boxes::with_fits() with the bags).
	 * @param int[]        $ladder     Bag ids, smallest first.
	 * @param array        $overrides  Line key → bag id chosen by hand.
	 * @param array[]|null $confirmed  The confirmed boxes with their fits (null = none).
	 * @param bool         $same       Whether the confirmed boxes are the suggestion's.
	 * @return array{from: string, boxes: array[], lines: array[], moved: int, over: bool, chosen: int, left_out: array} left_out: bag id → fish
	 *         left out of the confirmed boxes because only another box takes that bag. Each of `lines` (by the
	 *         line's index): use (bag id → bags), boxes (the boxes holding some of it), in_box (box → bag id →
	 *         bags of the line in that box), moved, chosen.
	 */
	public static function build( array $lines, array $suggestion, array $fitted, array $ladder, array $overrides = array(), $confirmed = null, $same = false ) {
		$known = array_flip( array_map( 'intval', $ladder ) );

		// One entry per bag of every line that has a bag.
		$fish     = array();
		$by_min   = array();
		$position = 0;
		foreach ( $lines as $index => $line ) {
			if ( empty( $line['bag'] ) || (int) ( $line['bags'] ?? 0 ) < 1 ) {
				continue;
			}
			$min   = (int) $line['bag']['id'];
			$key   = (string) ( $line['key'] ?? '' );
			$fixed = isset( $overrides[ $key ] ) && isset( $known[ (int) $overrides[ $key ] ] ) ? (int) $overrides[ $key ] : 0;
			for ( $n = 0; $n < (int) $line['bags']; $n++ ) {
				$id          = count( $fish );
				$fish[ $id ] = array(
					'line'  => $index,
					'min'   => $min,
					'fixed' => $fixed,
					'pos'   => $position++,
				);
				$by_min[ $min ][] = $id;
			}
		}

		$out = array(
			'from'     => '',
			'boxes'    => array(),
			'lines'    => array(),
			'moved'    => 0,
			'over'     => false,
			'chosen'   => 0,
			'left_out' => array(),
		);

		// The boxes, and which fish go in each.
		$boxes  = array();
		$groups = array();
		if ( is_array( $confirmed ) && $confirmed && ! $same ) {
			$out['from'] = self::FROM_CONFIRMED;
			$boxes       = array_values( $confirmed );

			// A fish whose smallest bag goes in none of the confirmed boxes
			// is left out of them (it would leave the box with no fill, and
			// nobody would move up): a bag no box on the list takes stays
			// "No box takes"; one that only another box takes is said.
			$placeable = array();
			foreach ( $by_min as $bag_id => $ids ) {
				if ( self::goes_in( (int) $bag_id, $boxes ) ) {
					$placeable[ $bag_id ] = $ids;
				} elseif ( $ids && self::goes_in( (int) $bag_id, $fitted ) ) {
					$out['left_out'][ (int) $bag_id ] = count( $ids );
				}
			}
			$groups = self::spread( $placeable, $boxes, $ladder );
		} elseif ( ! empty( $suggestion['boxes'] ) ) {
			$out['from'] = is_array( $confirmed ) && $confirmed ? self::FROM_CONFIRMED : self::FROM_SUGGESTION;
			foreach ( $suggestion['boxes'] as $placed ) {
				$box = Boxes::find( $fitted, $placed['key'] ?? '' );
				if ( null === $box ) {
					$box = $placed + array(
						'fits'   => array(),
						'shares' => array(),
					);
				}
				$boxes[]  = $box;
				$taken    = array();
				$load     = isset( $placed['bags'] ) && is_array( $placed['bags'] ) ? $placed['bags'] : array();
				foreach ( $load as $bag_id => $count ) {
					for ( $n = 0; $n < (int) $count && ! empty( $by_min[ (int) $bag_id ] ); $n++ ) {
						$taken[] = array_shift( $by_min[ (int) $bag_id ] );
					}
				}
				sort( $taken );
				$groups[] = $taken;
			}
		}

		// Every fish starts in its minimum bag (or the one chosen by hand).
		$use = array();
		foreach ( $fish as $id => $one ) {
			$use[ $id ] = $one['fixed'] > 0 ? $one['fixed'] : $one['min'];
		}
		$in_box = array();

		foreach ( $boxes as $b => $box ) {
			$members = array();
			foreach ( $groups[ $b ] ?? array() as $id ) {
				$members[ $id ] = $fish[ $id ];
				$in_box[ $id ]  = $b;
			}
			$done = Upsize::box( $members, $box, $ladder );
			foreach ( $done['bags'] as $id => $bag_id ) {
				$use[ $id ] = (int) $bag_id;
			}

			$load = array();
			foreach ( $done['bags'] as $bag_id ) {
				$load[ (int) $bag_id ] = ( $load[ (int) $bag_id ] ?? 0 ) + 1;
			}
			ksort( $load );

			$out['boxes'][] = array(
				'key'          => (string) ( $box['key'] ?? '' ),
				'name'         => (string) ( $box['name'] ?? '' ),
				'length'       => (float) ( $box['length'] ?? 0 ),
				'width'        => (float) ( $box['width'] ?? 0 ),
				'height'       => (float) ( $box['height'] ?? 0 ),
				'bags'         => $load,
				'fill_min'     => null === $done['fill_min'] ? null : round( $done['fill_min'], 6 ),
				'fill_min_pct' => null === $done['fill_min'] ? null : round( $done['fill_min'] * 100, 1 ),
				'fill'         => null === $done['fill'] ? null : round( $done['fill'], 6 ),
				'fill_pct'     => null === $done['fill'] ? null : round( $done['fill'] * 100, 1 ),
				'over'         => $done['over'] && (bool) $members,
				'moved'        => $done['moved'],
				'tie_off'      => isset( $box['tie_off'] ) && is_array( $box['tie_off'] ) ? $box['tie_off'] : array(),
				'missing'      => self::missing( $load, $box ),
			);
			$out['moved'] += $done['moved'];
			if ( $done['over'] && $members ) {
				$out['over'] = true;
			}
		}

		// Per line: the bags to use, and where they came from.
		foreach ( $lines as $index => $line ) {
			$out['lines'][ $index ] = array(
				'use'    => array(),
				'boxes'  => array(),
				'in_box' => array(),
				'moved'  => 0,
				'chosen' => 0,
			);
		}
		foreach ( $fish as $id => $one ) {
			$row                       = &$out['lines'][ $one['line'] ];
			$row['use'][ $use[ $id ] ] = ( $row['use'][ $use[ $id ] ] ?? 0 ) + 1;
			if ( isset( $in_box[ $id ] ) && ! in_array( $in_box[ $id ], $row['boxes'], true ) ) {
				$row['boxes'][] = $in_box[ $id ];
			}
			if ( isset( $in_box[ $id ] ) ) {
				// Which box holds which of the line's bags: what a shipment of that box is made of.
				$row['in_box'][ $in_box[ $id ] ][ $use[ $id ] ] = ( $row['in_box'][ $in_box[ $id ] ][ $use[ $id ] ] ?? 0 ) + 1;
			}
			if ( $one['fixed'] > 0 ) {
				$row['chosen'] = $one['fixed'];
			} elseif ( $use[ $id ] !== $one['min'] ) {
				$row['moved']++;
			}
			unset( $row );
		}
		foreach ( $out['lines'] as $row ) {
			if ( $row['chosen'] > 0 ) {
				$out['chosen']++;
			}
		}

		return $out;
	}

	/**
	 * Whether any of the boxes takes a bag size.
	 *
	 * @param int     $bag_id The bag.
	 * @param array[] $boxes  Boxes with their fits.
	 * @return bool
	 */
	private static function goes_in( $bag_id, array $boxes ) {
		foreach ( $boxes as $box ) {
			if ( is_array( $box ) && isset( $box['fits'][ $bag_id ] ) && (int) $box['fits'][ $bag_id ] >= 1 ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * The bag sizes of a load that do not go in a box at all.
	 *
	 * @param array $load Bag id → bags.
	 * @param array $box  The box (fits).
	 * @return int[]
	 */
	private static function missing( array $load, array $box ) {
		$out = array();
		foreach ( $load as $bag_id => $count ) {
			if ( $count > 0 && ( ! isset( $box['fits'][ $bag_id ] ) || (int) $box['fits'][ $bag_id ] < 1 ) ) {
				$out[] = (int) $bag_id;
			}
		}
		return $out;
	}

	/**
	 * The fish spread over boxes chosen by hand: one box takes them all;
	 * several are filled one after the other (the bulkiest bags first, by
	 * Suggest::pack()), and what is left goes in the last one that takes
	 * its bag (every fish given here has one).
	 *
	 * @param array   $by_min Bag id → fish ids, in the order of the lines.
	 * @param array[] $boxes  The boxes with their fits.
	 * @param int[]   $ladder Bag ids in list order.
	 * @return array[] Per box, the fish ids in it.
	 */
	private static function spread( array $by_min, array $boxes, array $ladder ) {
		$groups = array_fill( 0, count( $boxes ), array() );
		if ( ! $boxes ) {
			return $groups;
		}
		$last = count( $boxes ) - 1;

		foreach ( $boxes as $b => $box ) {
			if ( $b === $last ) {
				break;
			}
			$remaining = array();
			foreach ( $by_min as $bag_id => $ids ) {
				if ( $ids ) {
					$remaining[ (int) $bag_id ] = count( $ids );
				}
			}
			if ( ! $remaining ) {
				break;
			}
			$load = Suggest::pack( $remaining, $box, $ladder );
			foreach ( $load[0] as $bag_id => $count ) {
				for ( $n = 0; $n < $count && ! empty( $by_min[ $bag_id ] ); $n++ ) {
					$groups[ $b ][] = array_shift( $by_min[ $bag_id ] );
				}
			}
		}

		foreach ( $by_min as $bag_id => $ids ) {
			$into = $last;
			for ( $b = $last; $b >= 0; $b-- ) {
				if ( self::goes_in( (int) $bag_id, array( $boxes[ $b ] ) ) ) {
					$into = $b;
					break;
				}
			}
			foreach ( $ids as $id ) {
				$groups[ $into ][] = $id;
			}
		}
		foreach ( $groups as $b => $ids ) {
			sort( $ids );
			$groups[ $b ] = $ids;
		}
		return $groups;
	}
}
