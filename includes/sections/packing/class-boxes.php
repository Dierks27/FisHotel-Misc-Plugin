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
				$by_name = strnatcasecmp( (string) $a['name'], (string) $b['name'] );
				return 0 !== $by_name ? $by_name : strcmp( (string) $a['key'], (string) $b['key'] );
			}
		);
		return $boxes;
	}

	/**
	 * ShipTracker's standard boxes, or null when the active build does not
	 * provide them (the plugin is not active, the method does not exist
	 * yet, or it failed).
	 *
	 * @param string $class ShipTracker's settings class (a parameter so a test can stand in for it).
	 * @return array[]|null
	 */
	public static function from_shiptracker( $class = 'FST_Settings' ) {
		if ( ! is_string( $class ) || ! class_exists( $class ) || ! method_exists( $class, 'get_standard_boxes' ) ) {
			return null;
		}

		try {
			$rows = call_user_func( array( $class, 'get_standard_boxes' ) );
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
	 * The two numbers of the fit model (Fit): the foam on each side and the
	 * filled share. The foam is the Foam Cuts section's thickness while that
	 * section is switched on, else this section's own setting.
	 *
	 * @param array $settings Settings (foam, fill_share).
	 * @return array{foam: float, share: int, foam_from: string}
	 */
	public static function model( array $settings ) {
		$foam = Fit::foam( $settings['foam'] ?? Fit::FOAM_DEFAULT );
		$from = 'packing';

		$cuts = self::foam_cuts_thickness();
		if ( null !== $cuts ) {
			$foam = $cuts;
			$from = 'foam-cuts';
		}

		return array(
			'foam'      => $foam,
			'share'     => Fit::share( $settings['fill_share'] ?? Fit::SHARE_DEFAULT ),
			'foam_from' => $from,
		);
	}

	/**
	 * The Foam Cuts section's foam thickness in inches, while that section
	 * is switched on — null otherwise (it is off or not there, its classes
	 * cannot be read, or the number is out of range). Reached through
	 * class_exists() / is_callable() only, inside a try, and never written.
	 *
	 * @return float|null
	 */
	public static function foam_cuts_thickness() {
		$section  = 'FisHotel\\Misc\\Sections\\Foam_Cuts\\Foam_Cuts';
		$settings = 'FisHotel\\Misc\\Sections\\Foam_Cuts\\Settings';
		$units    = 'FisHotel\\Misc\\Sections\\Foam_Cuts\\Units';

		try {
			if ( ! class_exists( $section ) || ! is_callable( array( $section, 'enabled' ) ) || ! call_user_func( array( $section, 'enabled' ) ) ) {
				return null;
			}
			if ( ! class_exists( $settings ) && is_callable( array( $section, 'load' ) ) ) {
				call_user_func( array( $section, 'load' ) );
			}
			if ( ! class_exists( $settings ) || ! is_callable( array( $settings, 'get' ) ) || ! class_exists( $units ) || ! defined( $units . '::PER_INCH' ) ) {
				return null;
			}
			$stored   = call_user_func( array( $settings, 'get' ) );
			$per_inch = (int) constant( $units . '::PER_INCH' );
			if ( ! is_array( $stored ) || ! isset( $stored['thickness'] ) || ! is_numeric( $stored['thickness'] ) || $per_inch < 1 ) {
				return null;
			}
			$inches = round( (int) $stored['thickness'] / $per_inch, 4 );
		} catch ( \Throwable $e ) {
			return null;
		}

		return ( $inches >= 0 && $inches <= Fit::FOAM_MAX ) ? $inches : null;
	}

	/**
	 * The boxes in use with how many of each bag fit — the typed numbers
	 * and the fit model together (with_fits() with the bags and the model).
	 *
	 * @param array[]    $boxes    Boxes (rows()).
	 * @param array      $settings Settings.
	 * @param array|null $model    model() (null = work it out).
	 * @return array[]
	 */
	public static function fitted( array $boxes, array $settings, $model = null ) {
		if ( ! is_array( $model ) ) {
			$model = self::model( $settings );
		}
		return self::with_fits( $boxes, $settings['fits'] ?? array(), $settings['bags'] ?? array(), $model );
	}

	/**
	 * Each box with its "how many fit" (pure): `fits` (bag id → how many)
	 * and `fits_from` (the key the numbers are stored under; '' without
	 * any). A box with no numbers under its own name takes over those of an
	 * entry that belongs to no box of the list and was typed for exactly
	 * the same size — each such entry is taken once.
	 *
	 * With the bags and the model (Fit), a bag that has no typed number for
	 * a box gets the formula's: `fits` is then what is used, `fits_typed`
	 * only what was typed, `fits_computed` the formula's count for every
	 * bag with a size (0 = too wide), `fits_by` 'typed' | 'computed',
	 * `shares` each bag's part of the box, and `tie_off` the usable height
	 * for the bags that stand taller than it. Without them (the saves of
	 * the Boxes tab) only the typed numbers are read.
	 *
	 * @param array[]    $boxes Boxes (rows()).
	 * @param array      $fits  Settings' fits: key → name, dims, bags.
	 * @param array|null $bags  The bag list (null = typed numbers only).
	 * @param array|null $model model(): foam, share.
	 * @return array[]
	 */
	public static function with_fits( array $boxes, array $fits, $bags = null, $model = null ) {
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

			$boxes[ $index ]['fits']       = $clean;
			$boxes[ $index ]['fits_from']  = $clean ? $from : '';
			$boxes[ $index ]['fits_typed'] = $clean;

			if ( is_array( $bags ) ) {
				$model = is_array( $model ) ? $model : array();
				$fit   = Fit::box( $box, $bags, $clean, $model['foam'] ?? Fit::FOAM_DEFAULT, $model['share'] ?? Fit::SHARE_DEFAULT );

				$boxes[ $index ]['fits']          = $fit['fits'];
				$boxes[ $index ]['shares']        = $fit['shares'];
				$boxes[ $index ]['fits_by']       = $fit['from'];
				$boxes[ $index ]['fits_computed'] = $fit['computed'];
				$boxes[ $index ]['tie_off']       = $fit['tie_off'];
				$boxes[ $index ]['diameters']     = $fit['diameters'];
				$boxes[ $index ]['usable']        = Fit::usable( $box, $model['foam'] ?? Fit::FOAM_DEFAULT );
			}
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
