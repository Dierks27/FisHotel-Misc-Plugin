<?php
/**
 * The standard boxes an order can be packed in, and how many bags of each
 * size fit each of them.
 *
 * Where the boxes come from (current()):
 *   - ShipTracker, when the build that is active provides them:
 *     FST_Settings::get_standard_boxes(), reached through class_exists() /
 *     method_exists() and never trusted to not throw. It is the list under
 *     ShipTracker → Settings → Boxes & label costs (name, length, width,
 *     height in inches) — one list for the label costs and for packing;
 *   - otherwise — ShipTracker is not active, its build has no such method
 *     yet, or its list is still empty — this section's own list;
 *   - the filter fishotel_packing_boxes may replace either.
 *
 * "How many fit" is typed per box and bag and stored under the box's
 * lower-cased name, with the size the box had. A box whose name has no
 * numbers yet takes over those of a box that is no longer in the list and
 * has exactly the same size (with_fits()) — so a box renamed in
 * ShipTracker, or the move from the own list to ShipTracker's, keeps what
 * was typed.
 *
 * Everything here is pure except from_shiptracker() and the filter in
 * current().
 *
 * @package FisHotel\Misc\Sections\Packing
 */

namespace FisHotel\Misc\Sections\Packing;

defined( 'ABSPATH' ) || exit;

/**
 * Class Boxes
 */
class Boxes {

	const SOURCE_SHIPTRACKER = 'shiptracker';
	const SOURCE_OWN         = 'own';

	/**
	 * What ShipTracker had to say.
	 */
	const SHIPTRACKER_USED   = 'used';   // It provides boxes: they are the list.
	const SHIPTRACKER_EMPTY  = 'empty';  // It has the list, with nothing in it yet.
	const SHIPTRACKER_ABSENT = 'absent'; // Not active, or a build without standard boxes.

	/**
	 * The key a box is known by: its name, lower-cased, whitespace
	 * collapsed.
	 *
	 * @param mixed $name Name.
	 * @return string
	 */
	public static function key( $name ) {
		if ( ! is_scalar( $name ) || is_bool( $name ) ) {
			return '';
		}
		$name = trim( (string) preg_replace( '/\s+/', ' ', (string) $name ) );
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $name ) : strtolower( $name );
	}

	/**
	 * A size in inches: a number above 0 and at most Settings::MAX_DIM,
	 * rounded to two places. Null for anything else.
	 *
	 * @param mixed $raw Raw value.
	 * @return float|null
	 */
	public static function number( $raw ) {
		if ( ! is_scalar( $raw ) || is_bool( $raw ) ) {
			return null;
		}
		$text = trim( (string) $raw );
		if ( '' === $text || ! is_numeric( $text ) ) {
			return null;
		}
		$value = round( (float) $text, 2 );
		if ( $value <= 0 || $value > Settings::MAX_DIM || is_nan( $value ) || is_infinite( $value ) ) {
			return null;
		}
		return $value;
	}

	/**
	 * A number as text without trailing zeros (10, 12.5).
	 *
	 * @param mixed $value Number.
	 * @param int   $places Decimal places kept at most.
	 * @return string
	 */
	public static function num_text( $value, $places = 2 ) {
		$text = number_format( (float) $value, max( 0, (int) $places ), '.', '' );
		if ( false !== strpos( $text, '.' ) ) {
			$text = rtrim( rtrim( $text, '0' ), '.' );
		}
		return '' === $text || '-0' === $text ? '0' : $text;
	}

	/**
	 * "LxWxH" — the form ShipTracker stores a box size in.
	 *
	 * @param mixed $length Length.
	 * @param mixed $width  Width.
	 * @param mixed $height Height.
	 * @return string
	 */
	public static function dims( $length, $width, $height ) {
		return self::num_text( $length ) . 'x' . self::num_text( $width ) . 'x' . self::num_text( $height );
	}

	/**
	 * One box in the shape the rest of the section uses, or null when the
	 * row is not a box (no name, a size that is not above 0).
	 *
	 * @param mixed  $row    name, length, width, height.
	 * @param string $source SOURCE_*.
	 * @return array|null key, name, length, width, height, dims, volume, source.
	 */
	public static function row( $row, $source ) {
		if ( ! is_array( $row ) ) {
			return null;
		}
		$name   = Settings::name_in( $row['name'] ?? '' );
		$key    = self::key( $name );
		$length = self::number( $row['length'] ?? '' );
		$width  = self::number( $row['width'] ?? '' );
		$height = self::number( $row['height'] ?? '' );
		if ( '' === $key || null === $length || null === $width || null === $height ) {
			return null;
		}

		return array(
			'key'    => $key,
			'name'   => $name,
			'length' => $length,
			'width'  => $width,
			'height' => $height,
			'dims'   => self::dims( $length, $width, $height ),
			'volume' => round( $length * $width * $height, 4 ),
			'source' => (string) $source,
		);
	}

	/**
	 * A list of rows as boxes: the rows that are not boxes and a second box
	 * with the same name dropped, the smallest (by volume) first.
	 *
	 * @param mixed  $rows   Rows.
	 * @param string $source SOURCE_*.
	 * @return array[]
	 */
	public static function rows( $rows, $source ) {
		$out  = array();
		$seen = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$box = self::row( $row, $source );
			if ( null === $box || isset( $seen[ $box['key'] ] ) ) {
				continue;
			}
			$seen[ $box['key'] ] = true;
			$out[]               = $box;
			if ( count( $out ) >= Settings::MAX_BOXES ) {
				break;
			}
		}
		return self::by_volume( $out );
	}

	/**
	 * Boxes by volume, the smallest first (the largest first with $desc);
	 * equal volumes by name.
	 *
	 * @param array[] $boxes Boxes.
	 * @param bool    $desc  Largest first.
	 * @return array[]
	 */
	public static function by_volume( array $boxes, $desc = false ) {
		$boxes = array_values( $boxes );
		usort(
			$boxes,
			static function ( $a, $b ) use ( $desc ) {
				$by_volume = (float) $a['volume'] <=> (float) $b['volume'];
				if ( 0 !== $by_volume ) {
					return $desc ? -$by_volume : $by_volume;
				}
				return strnatcasecmp( (string) $a['name'], (string) $b['name'] );
			}
		);
		return $boxes;
	}

	/**
	 * ShipTracker's standard boxes, or null when the active build does not
	 * provide them (the plugin is not active, the method does not exist
	 * yet, or it failed).
	 *
	 * @return array[]|null
	 */
	public static function from_shiptracker() {
		if ( ! class_exists( 'FST_Settings' ) || ! method_exists( 'FST_Settings', 'get_standard_boxes' ) ) {
			return null;
		}

		try {
			$rows = \FST_Settings::get_standard_boxes();
		} catch ( \Throwable $e ) {
			return null;
		}

		return is_array( $rows ) ? self::rows( $rows, self::SOURCE_SHIPTRACKER ) : null;
	}

	/**
	 * The boxes in use and where they come from.
	 *
	 * @param array      $settings     Settings (its `boxes` is the own list).
	 * @param array|null $shiptracker  What ShipTracker provides (from_shiptracker()); omit to ask it.
	 * @param bool       $ask          False to take $shiptracker as given (null = it provides none).
	 * @return array{source: string, shiptracker: string, boxes: array[], own: array[]}
	 */
	public static function current( array $settings, $shiptracker = null, $ask = true ) {
		if ( $ask && null === $shiptracker ) {
			$shiptracker = self::from_shiptracker();
		}

		$own = self::rows( $settings['boxes'] ?? array(), self::SOURCE_OWN );

		if ( is_array( $shiptracker ) && $shiptracker ) {
			$source = self::SOURCE_SHIPTRACKER;
			$state  = self::SHIPTRACKER_USED;
			$boxes  = $shiptracker;
		} else {
			$source = self::SOURCE_OWN;
			$state  = is_array( $shiptracker ) ? self::SHIPTRACKER_EMPTY : self::SHIPTRACKER_ABSENT;
			$boxes  = $own;
		}

		if ( function_exists( 'apply_filters' ) ) {
			$filtered = apply_filters( 'fishotel_packing_boxes', $boxes, $source );
			if ( is_array( $filtered ) && $filtered !== $boxes ) {
				$boxes = self::rows( $filtered, $source );
			}
		}

		return array(
			'source'      => $source,
			'shiptracker' => $state,
			'boxes'       => $boxes,
			'own'         => $own,
		);
	}

	/**
	 * Each box with its "how many fit" (pure): `fits` (bag id → how many)
	 * and `fits_from` (the key the numbers are stored under; '' without
	 * any). A box with no numbers under its own name takes over those of an
	 * entry that belongs to no box of the list and was typed for exactly
	 * the same size — each such entry is taken once.
	 *
	 * @param array[] $boxes Boxes (rows()).
	 * @param array   $fits  Settings' fits: key → name, dims, bags.
	 * @return array[]
	 */
	public static function with_fits( array $boxes, array $fits ) {
		$in_list = array();
		foreach ( $boxes as $box ) {
			$in_list[ (string) $box['key'] ] = true;
		}

		$taken = array();
		foreach ( $boxes as $index => $box ) {
			$key    = (string) $box['key'];
			$counts = array();
			$from   = '';

			if ( isset( $fits[ $key ]['bags'] ) && is_array( $fits[ $key ]['bags'] ) ) {
				$counts = $fits[ $key ]['bags'];
				$from   = $key;
			} else {
				foreach ( $fits as $other => $entry ) {
					$other = (string) $other;
					if ( isset( $in_list[ $other ] ) || isset( $taken[ $other ] ) || ! is_array( $entry ) ) {
						continue;
					}
					if ( '' !== (string) ( $entry['dims'] ?? '' ) && (string) $entry['dims'] === (string) $box['dims'] && ! empty( $entry['bags'] ) && is_array( $entry['bags'] ) ) {
						$counts          = $entry['bags'];
						$from            = $other;
						$taken[ $other ] = true;
						break;
					}
				}
			}

			$clean = array();
			foreach ( $counts as $bag_id => $count ) {
				if ( (int) $count >= 1 ) {
					$clean[ (int) $bag_id ] = (int) $count;
				}
			}

			$boxes[ $index ]['fits']      = $clean;
			$boxes[ $index ]['fits_from'] = $clean ? $from : '';
		}

		return $boxes;
	}

	/**
	 * The box with a key, from a list.
	 *
	 * @param array[] $boxes Boxes.
	 * @param mixed   $key   Key (or a name).
	 * @return array|null
	 */
	public static function find( array $boxes, $key ) {
		$key = self::key( $key );
		if ( '' === $key ) {
			return null;
		}
		foreach ( $boxes as $box ) {
			if ( (string) $box['key'] === $key ) {
				return $box;
			}
		}
		return null;
	}

	/**
	 * A box's size for a person: "12 × 10 × 8 in".
	 *
	 * @param array $box length, width, height.
	 * @return string
	 */
	public static function size_text( array $box ) {
		return self::num_text( $box['length'] ?? 0 ) . ' × ' . self::num_text( $box['width'] ?? 0 ) . ' × ' . self::num_text( $box['height'] ?? 0 ) . ' in';
	}
}
