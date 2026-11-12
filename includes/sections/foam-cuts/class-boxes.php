<?php
/**
 * Boxes — where the list of shipping boxes comes from.
 *
 *   1. ShipTracker, when its build provides standard boxes
 *      (FST_Settings::get_standard_boxes(), probed with method_exists) and
 *      the owner has entered some there;
 *   2. else the Packing section's list, when that section is switched on
 *      (its own list, read from its classes — or fishotel_packing_boxes()
 *      should it ever offer that);
 *   3. else this section's own list (Settings → boxes).
 *
 * Whatever the source, the list can be replaced through the filter
 * fishotel_foam_cuts_boxes ( rows in inches, source ) — each row a name
 * and a length, width and height. Nothing here is required: every probe
 * is guarded and wrapped, and the screen says which source is in use and
 * what was not available.
 *
 * A box here is { key, name, length, width, height } with sizes in units
 * and key = the name in lower case (what a saved cut list is filed under).
 * The sizes are taken as the INSIDE of the box.
 *
 * @package FisHotel\Misc\Sections\Foam_Cuts
 */

namespace FisHotel\Misc\Sections\Foam_Cuts;

defined( 'ABSPATH' ) || exit;

/**
 * Class Boxes
 */
class Boxes {

	const SOURCE_SHIPTRACKER = 'shiptracker';
	const SOURCE_PACKING     = 'packing';
	const SOURCE_OWN         = 'own';

	/**
	 * The filter that can replace the list.
	 *
	 * @var string
	 */
	const FILTER = 'fishotel_foam_cuts_boxes';

	/**
	 * The Misc plugin's option listing the sections that are switched on.
	 *
	 * @var string
	 */
	const SECTIONS_OPTION = 'fishotel_misc_enabled_sections';

	/**
	 * A box name as stored: one plain line.
	 *
	 * @param string $name Raw name.
	 * @return string
	 */
	public static function clean_name( $name ) {
		return Store::clip( $name, Settings::NAME_LENGTH );
	}

	/**
	 * The key a box is filed under: its name in lower case.
	 *
	 * @param string $name Box name.
	 * @return string
	 */
	public static function key( $name ) {
		$name = self::clean_name( $name );
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $name, 'UTF-8' ) : strtolower( $name );
	}

	/**
	 * Clean a list of boxes whose sizes are in units (the stored own
	 * list): a box needs a name and three sizes above 0; a second box with
	 * the same name is dropped.
	 *
	 * @param mixed $rows [ { name, length, width, height } ].
	 * @return array<int, array{key: string, name: string, length: int, width: int, height: int}>
	 */
	public static function clean( $rows ) {
		$out  = array();
		$seen = array();

		foreach ( (array) $rows as $row ) {
			if ( ! is_array( $row ) || count( $out ) >= Settings::MAX_BOXES ) {
				continue;
			}

			$name   = isset( $row['name'] ) && is_scalar( $row['name'] ) ? self::clean_name( (string) $row['name'] ) : '';
			$key    = self::key( $name );
			$length = isset( $row['length'] ) && is_numeric( $row['length'] ) ? (int) $row['length'] : 0;
			$width  = isset( $row['width'] ) && is_numeric( $row['width'] ) ? (int) $row['width'] : 0;
			$height = isset( $row['height'] ) && is_numeric( $row['height'] ) ? (int) $row['height'] : 0;
			$max    = Units::MAX_INCHES * Units::PER_INCH;

			if ( '' === $key || isset( $seen[ $key ] ) || $length <= 0 || $width <= 0 || $height <= 0 || max( $length, $width, $height ) > $max ) {
				continue;
			}
			$seen[ $key ] = true;

			$out[] = array(
				'key'    => $key,
				'name'   => $name,
				'length' => $length,
				'width'  => $width,
				'height' => $height,
			);
		}

		return $out;
	}

	/**
	 * Read a list of boxes whose sizes are in INCHES — a form, another
	 * plugin's list, the filter's answer. A row is an array or an object
	 * with a name (name / label / title) and three sizes (length, width,
	 * height — or l, w, h — or a "dims" / "size" text such as "16x12x10");
	 * a size may be a number or text ("11 3/4").
	 *
	 * @param mixed $rows Rows.
	 * @return array<int, array{key: string, name: string, length: int, width: int, height: int}>
	 */
	public static function read( $rows ) {
		$units = array();

		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			if ( is_object( $row ) ) {
				$row = get_object_vars( $row );
			}
			if ( ! is_array( $row ) ) {
				continue;
			}

			$name = '';
			foreach ( array( 'name', 'label', 'title' ) as $field ) {
				if ( isset( $row[ $field ] ) && is_scalar( $row[ $field ] ) && '' !== trim( (string) $row[ $field ] ) ) {
					$name = (string) $row[ $field ];
					break;
				}
			}

			$sizes = self::read_sizes( $row );
			if ( '' === $name || null === $sizes ) {
				continue;
			}

			$units[] = array(
				'name'   => $name,
				'length' => $sizes[0],
				'width'  => $sizes[1],
				'height' => $sizes[2],
			);
		}

		return self::clean( $units );
	}

	/**
	 * The three sizes of a row in units, or null.
	 *
	 * @param array $row Row.
	 * @return int[]|null [ length, width, height ].
	 */
	public static function read_sizes( array $row ) {
		$sets = array(
			array( 'length', 'width', 'height' ),
			array( 'inner_length', 'inner_width', 'inner_height' ),
			array( 'l', 'w', 'h' ),
		);

		foreach ( $sets as $set ) {
			if ( ! isset( $row[ $set[0] ], $row[ $set[1] ], $row[ $set[2] ] ) ) {
				continue;
			}
			$sizes = array();
			foreach ( $set as $field ) {
				$value   = $row[ $field ];
				$sizes[] = is_scalar( $value ) ? Units::parse( is_string( $value ) ? $value : (float) $value ) : null;
			}
			if ( ! in_array( null, $sizes, true ) && min( $sizes ) > 0 ) {
				return $sizes;
			}
		}

		foreach ( array( 'dims', 'size', 'dimensions' ) as $field ) {
			if ( isset( $row[ $field ] ) && is_string( $row[ $field ] ) ) {
				$sizes = self::parse_dims( $row[ $field ] );
				if ( null !== $sizes ) {
					return $sizes;
				}
			}
		}

		return null;
	}

	/**
	 * "16x12x10", "16 × 12 × 10 in", "16*12*10" → units, or null.
	 *
	 * @param string $text Text.
	 * @return int[]|null
	 */
	public static function parse_dims( $text ) {
		$parts = preg_split( '/\s*[x×*]\s*/iu', trim( (string) $text ) );
		if ( ! is_array( $parts ) || 3 !== count( $parts ) ) {
			return null;
		}

		$sizes = array_map( array( Units::class, 'parse' ), $parts );
		if ( in_array( null, $sizes, true ) || min( $sizes ) <= 0 ) {
			return null;
		}

		return $sizes;
	}

	/**
	 * ShipTracker's standard boxes, or null when its build does not
	 * provide them (plugin absent, or older than the box list).
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

		return is_array( $rows ) ? self::read( $rows ) : null;
	}

	/**
	 * Whether the Packing section is there to be asked: its functions
	 * exist and — being a section of this plugin — it is switched on (the
	 * section framework loads a section's file even while it is off).
	 *
	 * @return bool
	 */
	public static function packing_on() {
		if ( ! function_exists( 'fishotel_packing_suggest' ) && ! function_exists( 'fishotel_packing_confirmed_box' ) && ! function_exists( 'fishotel_packing_boxes' ) ) {
			return false;
		}

		$enabled = get_option( self::SECTIONS_OPTION, null );
		if ( is_array( $enabled ) && class_exists( '\\FisHotel\\Misc\\Sections\\Packing\\Packing', false ) ) {
			return in_array( 'packing', $enabled, true );
		}

		return true;
	}

	/**
	 * The Packing section's box list, or null when it cannot be asked.
	 *
	 * @return array[]|null
	 */
	public static function from_packing() {
		if ( ! self::packing_on() ) {
			return null;
		}

		try {
			$rows = function_exists( 'fishotel_packing_boxes' ) ? \fishotel_packing_boxes() : self::packing_list();
		} catch ( \Throwable $e ) {
			return null;
		}

		return is_array( $rows ) ? self::read( $rows ) : null;
	}

	/**
	 * The Packing section's box list read from its classes — it has no
	 * function for it: Packing\Boxes::current( Packing\Settings::get() )
	 * gives { boxes: [ { name, length, width, height (inches), … } ] }.
	 * Every step is probed; null when any of it is not there.
	 *
	 * @return array[]|null
	 */
	private static function packing_list() {
		$space   = '\\FisHotel\\Misc\\Sections\\Packing\\';
		$section = $space . 'Packing';

		if ( ! class_exists( $section, false ) ) {
			return null;
		}
		if ( method_exists( $section, 'load' ) ) {
			call_user_func( array( $section, 'load' ) );
		}
		if ( ! class_exists( $space . 'Boxes', false ) || ! method_exists( $space . 'Boxes', 'current' ) || ! class_exists( $space . 'Settings', false ) || ! method_exists( $space . 'Settings', 'get' ) ) {
			return null;
		}

		$current = call_user_func( array( $space . 'Boxes', 'current' ), call_user_func( array( $space . 'Settings', 'get' ) ) );

		return is_array( $current ) && isset( $current['boxes'] ) && is_array( $current['boxes'] ) ? $current['boxes'] : null;
	}

	/**
	 * The box list in use, where it comes from, and what was not
	 * available on the way.
	 *
	 * @param array $settings Settings (for the own list).
	 * @return array{source: string, boxes: array[], notes: string[]}
	 */
	public static function all( array $settings ) {
		$notes  = array();
		$source = self::SOURCE_OWN;
		$boxes  = isset( $settings['boxes'] ) ? self::clean( $settings['boxes'] ) : array();

		$shiptracker = self::from_shiptracker();
		if ( $shiptracker ) {
			$source = self::SOURCE_SHIPTRACKER;
			$boxes  = $shiptracker;
		} else {
			$notes[] = null === $shiptracker
				? __( 'ShipTracker\'s standard boxes are not available (the plugin is not active, or its version has no box list yet).', 'fishotel-misc-plugin' )
				: __( 'ShipTracker has no standard boxes yet (ShipTracker → Settings → Boxes & label costs).', 'fishotel-misc-plugin' );

			$packing = self::from_packing();
			if ( $packing ) {
				$source = self::SOURCE_PACKING;
				$boxes  = $packing;
			} else {
				$notes[] = null === $packing
					? __( 'The Packing section\'s box list is not available (the section is not switched on, or has no list to give).', 'fishotel-misc-plugin' )
					: __( 'The Packing section has no boxes yet.', 'fishotel-misc-plugin' );
			}
		}

		if ( function_exists( 'has_filter' ) && has_filter( self::FILTER ) ) {
			$rows = array();
			foreach ( $boxes as $box ) {
				$rows[] = array(
					'name'   => $box['name'],
					'length' => Units::to_inches( $box['length'] ),
					'width'  => Units::to_inches( $box['width'] ),
					'height' => Units::to_inches( $box['height'] ),
				);
			}

			try {
				$filtered = apply_filters( self::FILTER, $rows, $source );
			} catch ( \Throwable $e ) {
				$filtered = $rows;
			}

			if ( is_array( $filtered ) && $filtered !== $rows ) {
				$boxes = self::read( $filtered );
			}
		}

		return array(
			'source' => $source,
			'boxes'  => $boxes,
			'notes'  => $notes,
		);
	}

	/**
	 * The source in words.
	 *
	 * @param string $source One of the SOURCE_ constants.
	 * @return string
	 */
	public static function source_label( $source ) {
		if ( self::SOURCE_SHIPTRACKER === $source ) {
			return __( 'ShipTracker → Settings → Boxes & label costs', 'fishotel-misc-plugin' );
		}
		if ( self::SOURCE_PACKING === $source ) {
			return __( 'the Packing section', 'fishotel-misc-plugin' );
		}
		return __( 'this section\'s own list (below)', 'fishotel-misc-plugin' );
	}

	/**
	 * Every box with the cut list a plan would use for it.
	 *
	 * @param array $boxes    Boxes.
	 * @param array $settings Settings (thickness, cut_lists).
	 * @return array<int, array> Each box + pieces, cut_list ('saved' | 'suggested'), changed, notes.
	 */
	public static function with_cut_lists( array $boxes, array $settings ) {
		$saved = isset( $settings['cut_lists'] ) && is_array( $settings['cut_lists'] ) ? $settings['cut_lists'] : array();
		$out   = array();

		foreach ( $boxes as $box ) {
			$list  = Cut_List::for_box( $box, isset( $saved[ $box['key'] ] ) ? $saved[ $box['key'] ] : null, (int) $settings['thickness'] );
			$out[] = array_merge(
				$box,
				array(
					'pieces'   => $list['pieces'],
					'cut_list' => $list['source'],
					'changed'  => $list['changed'],
					'notes'    => $list['notes'],
				)
			);
		}

		return $out;
	}

	/**
	 * A box by its name or key (case does not matter), or null.
	 *
	 * @param array  $boxes Boxes.
	 * @param string $name  Name or key.
	 * @return array|null
	 */
	public static function find( array $boxes, $name ) {
		$key = self::key( (string) $name );
		if ( '' === $key ) {
			return null;
		}
		foreach ( $boxes as $box ) {
			if ( $box['key'] === $key ) {
				return $box;
			}
		}
		return null;
	}

	/**
	 * A box by its three sizes (length and width either way round), or null.
	 *
	 * @param array $boxes  Boxes.
	 * @param int   $length Units.
	 * @param int   $width  Units.
	 * @param int   $height Units.
	 * @return array|null
	 */
	public static function by_size( array $boxes, $length, $width, $height ) {
		foreach ( $boxes as $box ) {
			if ( (int) $height !== $box['height'] ) {
				continue;
			}
			if ( ( (int) $length === $box['length'] && (int) $width === $box['width'] ) || ( (int) $length === $box['width'] && (int) $width === $box['length'] ) ) {
				return $box;
			}
		}
		return null;
	}
}
