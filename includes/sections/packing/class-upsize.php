<?php
/**
 * Upsizing — PURE: the fish of one box go into the largest bags the box
 * still has room for.
 *
 * The owner, in their words: "We will put the fish in the largest bags
 * that fit into the box … the more water the less it swings so ideally we
 * always fill our boxes. … give it to every fish in a round robin starting
 * with the largest fish."
 *
 *   - Every fish starts in its MINIMUM bag (its own Bag size, or the size →
 *     bag map) — the bags the box was chosen for. A fish whose bag was
 *     chosen by hand keeps that bag and takes its space first.
 *   - The others are put in a list, the largest minimum bag first (a tie
 *     keeps the order line's position), one entry per bag.
 *   - Round after round, each fish of the list moves up ONE bag size — the
 *     next bag of the bag list that goes in this box at all (one with
 *     neither a size nor a typed number for the box is passed over) — when
 *     the box's fill with that change stays at or under 100 %. The rounds
 *     stop when a whole round moves nobody. A fish never goes past the
 *     last bag of the list.
 *   - When the minimum bags with the hand-chosen ones are already over
 *     100 % (or a hand-chosen bag does not go in the box at all), nobody
 *     moves up and the box is "over": a warning, never a refusal.
 *
 * The bag list's order (the Bags tab's sort numbers) is the ladder: the
 * list runs from the smallest bag to the largest.
 *
 * @package FisHotel\Misc\Sections\Packing
 */

namespace FisHotel\Misc\Sections\Packing;

defined( 'ABSPATH' ) || exit;

/**
 * Class Upsize
 */
class Upsize {

	/**
	 * A guard on the rounds: every round that goes on moves at least one
	 * fish one rung up, so this is never reached on real numbers.
	 *
	 * @var int
	 */
	const MAX_ROUNDS = 1000;

	/**
	 * Bags needed by size for a list of bags.
	 *
	 * @param int[] $bags Fish → bag id.
	 * @return array<int, int>
	 */
	public static function needs( array $bags ) {
		$out = array();
		foreach ( $bags as $bag_id ) {
			$bag_id = (int) $bag_id;
			if ( $bag_id > 0 ) {
				$out[ $bag_id ] = ( $out[ $bag_id ] ?? 0 ) + 1;
			}
		}
		return $out;
	}

	/**
	 * The next bag up the ladder that goes in this box, or 0.
	 *
	 * @param int   $bag_id The bag the fish is in.
	 * @param int[] $ladder Bag ids, smallest first.
	 * @param array $fits   Bag id → how many fit the box.
	 * @return int
	 */
	public static function next_bag( $bag_id, array $ladder, array $fits ) {
		$at = array_search( (int) $bag_id, $ladder, true );
		if ( false === $at ) {
			return 0;
		}
		$count = count( $ladder );
		for ( $i = $at + 1; $i < $count; $i++ ) {
			$next = (int) $ladder[ $i ];
			if ( isset( $fits[ $next ] ) && (int) $fits[ $next ] >= 1 ) {
				return $next;
			}
		}
		return 0;
	}

	/**
	 * Upsize the fish of one box.
	 *
	 * @param array[] $fish   The fish in the box, each: min (bag id), fixed
	 *                        (bag id chosen by hand, 0 = none), pos (the
	 *                        order of the lines, for ties). Keys are kept.
	 * @param array   $box    The box: fits, shares (Boxes::with_fits()).
	 * @param int[]   $ladder Bag ids, smallest first (the bag list).
	 * @return array{bags: array, fill_min: float|null, fill: float|null, over: bool, moved: int, rounds: int}
	 *         bags: the fish's key → the bag to use; fill_min: the fill with
	 *         every fish in its minimum bag; fill: the fill with the bags to
	 *         use (null when a bag does not go in the box); over: the bags
	 *         to use are more than the box holds; moved: how many fish were
	 *         moved up.
	 */
	public static function box( array $fish, array $box, array $ladder ) {
		$ladder = array_values( array_map( 'intval', $ladder ) );
		$fits   = isset( $box['fits'] ) && is_array( $box['fits'] ) ? $box['fits'] : array();
		$rank   = array_flip( $ladder );

		$bags    = array();
		$minimum = array();
		$free    = array();
		foreach ( $fish as $key => $one ) {
			$min   = (int) ( $one['min'] ?? 0 );
			$fixed = (int) ( $one['fixed'] ?? 0 );

			$minimum[ $key ] = $min;
			$bags[ $key ]    = $fixed > 0 ? $fixed : $min;
			if ( $fixed <= 0 ) {
				$free[] = $key;
			}
		}

		$fill_min = Suggest::fill_of( self::needs( $minimum ), $box );
		$fill     = Suggest::fill_of( self::needs( $bags ), $box );
		$out      = array(
			'bags'     => $bags,
			'fill_min' => $fill_min,
			'fill'     => $fill,
			'over'     => null === $fill || Suggest::pct( $fill ) > 100.0,
			'moved'    => 0,
			'rounds'   => 0,
		);
		if ( $out['over'] || ! $free ) {
			return $out;
		}

		// The largest minimum bag first; a tie keeps the order of the lines.
		usort(
			$free,
			static function ( $a, $b ) use ( $fish, $rank ) {
				$by_bag = ( $rank[ (int) $fish[ $b ]['min'] ] ?? -1 ) <=> ( $rank[ (int) $fish[ $a ]['min'] ] ?? -1 );
				if ( 0 !== $by_bag ) {
					return $by_bag;
				}
				return (int) ( $fish[ $a ]['pos'] ?? 0 ) <=> (int) ( $fish[ $b ]['pos'] ?? 0 );
			}
		);

		do {
			$moved_now = false;
			foreach ( $free as $key ) {
				$next = self::next_bag( $bags[ $key ], $ladder, $fits );
				if ( $next <= 0 ) {
					continue;
				}
				$try         = $bags;
				$try[ $key ] = $next;
				$with        = Suggest::fill_of( self::needs( $try ), $box );
				if ( null === $with || Suggest::pct( $with ) > 100.0 ) {
					continue;
				}
				$bags      = $try;
				$fill      = $with;
				$moved_now = true;
			}
			$out['rounds']++;
		} while ( $moved_now && $out['rounds'] < self::MAX_ROUNDS );

		foreach ( $free as $key ) {
			if ( $bags[ $key ] !== $minimum[ $key ] ) {
				$out['moved']++;
			}
		}
		$out['bags'] = $bags;
		$out['fill'] = $fill;

		return $out;
	}
}
