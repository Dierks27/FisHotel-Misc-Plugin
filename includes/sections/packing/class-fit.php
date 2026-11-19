<?php
/**
 * The fit model — how many bags of a size stand on a box's floor, worked
 * out from the bag's flat size and the box's inside size. PURE: no
 * WordPress, no settings read (Boxes::model() hands the two numbers in).
 *
 * The owner, in their words: "the bags don't take up all of the bag size…
 * figure out the circumference of the bag, then compare that to the floor
 * space of the box … as I never stack my fish on top of each other. I also
 * only use about 70% of the bag for water and oxygen, the other 30% is used
 * for tying off the bags."
 *
 *   - A bag is stored flat: width W and length L in inches (the Bags tab).
 *   - Filled and tied it stands upright as a round column. Its
 *     circumference is the flat bag's perimeter at the mouth, 2 × W, so
 *     its diameter is D = 2W / π.
 *   - It stands L × the filled share tall (a setting, 70 % unless changed;
 *     a bag may carry its own share, such as 50 %).
 *   - Bags are never stacked: only the box's FLOOR counts. The usable floor
 *     is the inside length and width minus the foam on both sides; the
 *     usable height is the inside height minus the foam top and bottom.
 *   - A bag goes in a box when D fits the floor's shorter side.
 *   - Height never refuses a bag: when the bag stands taller than the
 *     usable height it is tied off shorter, and the note says at about
 *     what height ("I can of course adjust this to whatever I need for the
 *     height of the box").
 *   - Each bag takes a D × D square of floor — round columns in rows, a
 *     deliberately simple ballpark. Its share of the box is D² ÷ the
 *     usable floor; how many of one size fit is the smaller of
 *     floor(area ÷ D²) and floor(length ÷ D) × floor(width ÷ D).
 *   - Bags of different sizes are also laid out in rows (rows()): the
 *     widest first, each row as deep as its first bag, along the floor's
 *     length. When the rows run past the floor's width the bags do not
 *     stand side by side, whatever the shares add up to — so two big
 *     bags that each fit alone are never put in one box together.
 *
 * A "how many fit" number the owner typed for a box and a bag always wins
 * over the formula (Boxes::with_fits()).
 *
 * @package FisHotel\Misc\Sections\Packing
 */

namespace FisHotel\Misc\Sections\Packing;

defined( 'ABSPATH' ) || exit;

/**
 * Class Fit
 */
class Fit {

	/**
	 * The share of a bag's length that is water and oxygen, in percent
	 * (the rest is the tie-off), unless the settings or the bag say other.
	 */
	const SHARE_DEFAULT = 70;
	const SHARE_MIN     = 10;
	const SHARE_MAX     = 100;

	/**
	 * The foam on each side of a box, in inches, while the Foam Cuts
	 * section does not say — and the most a setting may hold.
	 */
	const FOAM_DEFAULT = 0.5;
	const FOAM_MAX     = 6;

	/**
	 * Slack for floating point when a length is divided into whole bags.
	 */
	const EPSILON = 1e-9;

	/**
	 * The diameter of a filled bag: its circumference is 2 × the flat
	 * width, so D = 2W / π. 0 for no width.
	 *
	 * @param mixed $width Flat width, inches.
	 * @return float
	 */
	public static function diameter( $width ) {
		$width = is_numeric( $width ) ? (float) $width : 0.0;
		return $width > 0 ? 2 * $width / M_PI : 0.0;
	}

	/**
	 * The filled share of a bag, in percent: its own when it has one, else
	 * the setting.
	 *
	 * @param array $bag     The bag (share: '' or a whole percent).
	 * @param mixed $default The setting.
	 * @return int
	 */
	public static function share_of( array $bag, $default ) {
		$own = $bag['share'] ?? '';
		if ( is_numeric( $own ) && (int) $own >= self::SHARE_MIN && (int) $own <= self::SHARE_MAX ) {
			return (int) $own;
		}
		return self::share( $default );
	}

	/**
	 * A filled share as a percent from SHARE_MIN to SHARE_MAX (the default
	 * otherwise).
	 *
	 * @param mixed $share Raw.
	 * @return int
	 */
	public static function share( $share ) {
		$value = is_numeric( $share ) ? (int) $share : 0;
		return ( $value < self::SHARE_MIN || $value > self::SHARE_MAX ) ? self::SHARE_DEFAULT : $value;
	}

	/**
	 * A foam thickness in inches from 0 to FOAM_MAX (the default otherwise).
	 *
	 * @param mixed $foam Raw.
	 * @return float
	 */
	public static function foam( $foam ) {
		if ( ! is_numeric( $foam ) ) {
			return self::FOAM_DEFAULT;
		}
		$value = (float) $foam;
		return ( $value < 0 || $value > self::FOAM_MAX ) ? self::FOAM_DEFAULT : $value;
	}

	/**
	 * Whether a bag carries both of its flat sizes — what the formula needs.
	 *
	 * @param array $bag width, length.
	 * @return bool
	 */
	public static function has_size( array $bag ) {
		return is_numeric( $bag['width'] ?? '' ) && (float) $bag['width'] > 0
			&& is_numeric( $bag['length'] ?? '' ) && (float) $bag['length'] > 0;
	}

	/**
	 * How tall a filled bag stands: length × filled share.
	 *
	 * @param array $bag     width, length, share.
	 * @param mixed $default The filled share setting.
	 * @return float 0 without a length.
	 */
	public static function standing( array $bag, $default ) {
		$length = is_numeric( $bag['length'] ?? '' ) ? (float) $bag['length'] : 0.0;
		return $length > 0 ? $length * self::share_of( $bag, $default ) / 100 : 0.0;
	}

	/**
	 * The room a box leaves for bags: its inside size less the foam on
	 * every side, the floor's longer side as its length.
	 *
	 * @param array $box  length, width, height (inches, inside).
	 * @param mixed $foam Foam thickness, inches.
	 * @return array{length: float, width: float, height: float, area: float}
	 */
	public static function usable( array $box, $foam ) {
		$foam   = self::foam( $foam );
		$length = max( 0.0, (float) ( $box['length'] ?? 0 ) - 2 * $foam );
		$width  = max( 0.0, (float) ( $box['width'] ?? 0 ) - 2 * $foam );
		$height = max( 0.0, (float) ( $box['height'] ?? 0 ) - 2 * $foam );
		if ( $width > $length ) {
			list( $length, $width ) = array( $width, $length );
		}
		return array(
			'length' => $length,
			'width'  => $width,
			'height' => $height,
			'area'   => $length * $width,
		);
	}

	/**
	 * One bag in one box, by the formula. Null when the bag has no width
	 * and length (it then goes by the typed numbers alone).
	 *
	 * @param array $bag     width, length, share.
	 * @param array $box     length, width, height.
	 * @param mixed $foam    Foam thickness, inches.
	 * @param mixed $default The filled share setting.
	 * @return array{diameter: float, standing: float, count: int, by_area: int, by_rows: int, share: float, tie_off: float|null}|null
	 *         count 0 = the bag is too wide for the floor; share = D² ÷ the
	 *         usable floor; tie_off = the usable height when the bag stands
	 *         taller than that (null when it does not).
	 */
	public static function bag_in_box( array $bag, array $box, $foam, $default ) {
		if ( ! self::has_size( $bag ) ) {
			return null;
		}

		$diameter = self::diameter( $bag['width'] );
		$standing = self::standing( $bag, $default );
		$room     = self::usable( $box, $foam );

		$by_area = 0;
		$by_rows = 0;
		if ( $diameter > 0 && $diameter <= $room['width'] + self::EPSILON ) {
			$by_area = (int) floor( $room['area'] / ( $diameter * $diameter ) + self::EPSILON );
			$by_rows = (int) floor( $room['length'] / $diameter + self::EPSILON ) * (int) floor( $room['width'] / $diameter + self::EPSILON );
		}
		$count = min( $by_area, $by_rows );

		return array(
			'diameter' => $diameter,
			'standing' => $standing,
			'count'    => max( 0, $count ),
			'by_area'  => $by_area,
			'by_rows'  => $by_rows,
			'share'    => $count > 0 && $room['area'] > 0 ? ( $diameter * $diameter ) / $room['area'] : 0.0,
			'tie_off'  => $standing > $room['height'] + self::EPSILON ? $room['height'] : null,
		);
	}

	/**
	 * Every bag in one box: the typed number where there is one ("your
	 * number"), the formula otherwise.
	 *
	 * @param array   $box     length, width, height.
	 * @param array[] $bags    The bag list.
	 * @param array   $typed   Bag id → the typed "how many fit".
	 * @param mixed   $foam    Foam thickness, inches.
	 * @param mixed   $default The filled share setting.
	 * @return array{fits: array<int, int>, shares: array<int, float>, from: array<int, string>, computed: array<int, int>, tie_off: array<int, float>, diameters: array<int, float>}
	 *         fits: bag id → how many (only bags that go in); shares: bag id →
	 *         its part of the box per bag; from: 'typed' | 'computed';
	 *         computed: bag id → the formula's count (0 = too wide), for
	 *         every bag with a size; tie_off: bag id → the usable height, for
	 *         the bags that stand taller than it; diameters: bag id → D, for
	 *         the bags the formula placed (their rows are checked).
	 */
	public static function box( array $box, array $bags, array $typed, $foam, $default ) {
		$out = array(
			'fits'      => array(),
			'shares'    => array(),
			'from'      => array(),
			'computed'  => array(),
			'tie_off'   => array(),
			'diameters' => array(),
		);

		foreach ( $bags as $bag ) {
			if ( ! is_array( $bag ) || empty( $bag['id'] ) ) {
				continue;
			}
			$id   = (int) $bag['id'];
			$calc = self::bag_in_box( $bag, $box, $foam, $default );

			if ( null !== $calc ) {
				$out['computed'][ $id ] = $calc['count'];
				if ( null !== $calc['tie_off'] ) {
					$out['tie_off'][ $id ] = round( $calc['tie_off'], 2 );
				}
			}

			$mine = isset( $typed[ $id ] ) ? (int) $typed[ $id ] : 0;
			if ( $mine >= 1 ) {
				$out['fits'][ $id ]   = $mine;
				$out['shares'][ $id ] = 1 / $mine;
				$out['from'][ $id ]   = 'typed';
			} elseif ( null !== $calc && $calc['count'] >= 1 ) {
				$out['fits'][ $id ]      = $calc['count'];
				$out['shares'][ $id ]    = $calc['share'];
				$out['from'][ $id ]      = 'computed';
				$out['diameters'][ $id ] = $calc['diameter'];
			}
		}

		return $out;
	}

	/**
	 * How deep a set of round bags runs when laid out in rows on a floor
	 * (pure): the widest first; each bag goes in the first row that still
	 * has room along the floor's length and is at least as deep as the bag,
	 * else it starts a new row as deep as itself.
	 *
	 * @param float[] $diameters One D per bag.
	 * @param float   $length    The floor's longer side.
	 * @return float The rows' depth added up (compare with the floor's width).
	 */
	public static function rows( array $diameters, $length ) {
		$diameters = array_values( array_filter( array_map( 'floatval', $diameters ) ) );
		rsort( $diameters );

		$rows = array(); // Each: depth, used.
		foreach ( $diameters as $d ) {
			$placed = false;
			foreach ( $rows as $i => $row ) {
				if ( $row['depth'] + self::EPSILON >= $d && $row['used'] + $d <= (float) $length + self::EPSILON ) {
					$rows[ $i ]['used'] += $d;
					$placed              = true;
					break;
				}
			}
			if ( ! $placed ) {
				$rows[] = array(
					'depth' => $d,
					'used'  => $d,
				);
			}
		}

		$depth = 0.0;
		foreach ( $rows as $row ) {
			$depth += $row['depth'];
		}
		return $depth;
	}
}
