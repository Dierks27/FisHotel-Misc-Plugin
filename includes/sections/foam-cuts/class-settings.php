<?php
/**
 * Foam Cuts settings — one option (not autoloaded: it is read only on
 * this section's own screens and its REST route, never on an ordinary
 * request):
 *
 *   sheet_length, sheet_width  the foam sheet as bought (96 × 48 in)
 *   thickness                  its thickness (0.5 in) — what the cut-list
 *                              suggestion takes off the sides
 *   kerf                       the blade's own width, lost at every cut (0)
 *   rotate                     whether a piece may be turned 90 degrees (yes)
 *   min_length, min_width      the smallest leftover worth keeping (4 × 4 in)
 *   boxes                      this section's OWN box list — used only when
 *                              neither ShipTracker nor the Packing section
 *                              provides one: [ { name, length, width, height } ]
 *   cut_lists                  the cut lists the owner saved, by box key:
 *                              { name, box ("LxWxH" when saved), pieces[] }
 *
 * Every size is stored in units (Units::PER_INCH per inch). sanitize() is
 * pure; forms post inches as typed ("11 3/4" is fine).
 *
 * @package FisHotel\Misc\Sections\Foam_Cuts
 */

namespace FisHotel\Misc\Sections\Foam_Cuts;

defined( 'ABSPATH' ) || exit;

/**
 * Class Settings
 */
class Settings {

	/**
	 * Option key.
	 *
	 * @var string
	 */
	const OPTION_KEY = 'fishotel_foam_cuts_settings';

	/**
	 * The most boxes the own list keeps, and the most saved cut lists.
	 *
	 * @var int
	 */
	const MAX_BOXES = 60;

	/**
	 * The longest box name kept.
	 *
	 * @var int
	 */
	const NAME_LENGTH = 40;

	/**
	 * The defaults, in units.
	 *
	 * @return array
	 */
	public static function defaults() {
		$inch = Units::PER_INCH;

		return array(
			'sheet_length' => 96 * $inch,
			'sheet_width'  => 48 * $inch,
			'thickness'    => intdiv( $inch, 2 ),
			'kerf'         => 0,
			'rotate'       => true,
			'min_length'   => 4 * $inch,
			'min_width'    => 4 * $inch,
			'boxes'        => array(),
			'cut_lists'    => array(),
		);
	}

	/**
	 * The stored settings over the defaults.
	 *
	 * @return array
	 */
	public static function get() {
		return self::sanitize( get_option( self::OPTION_KEY, array() ) );
	}

	/**
	 * Store settings (sanitised first).
	 *
	 * @param array $settings Settings in units.
	 * @return array What was stored.
	 */
	public static function save( array $settings ) {
		$clean = self::sanitize( $settings );
		update_option( self::OPTION_KEY, $clean, false );
		return $clean;
	}

	/**
	 * Clean a settings array in units: anything missing or out of range
	 * falls back to its default; the sheet is laid with its longer side as
	 * the length.
	 *
	 * @param mixed $raw Settings.
	 * @return array
	 */
	public static function sanitize( $raw ) {
		$raw = is_array( $raw ) ? $raw : array();
		$out = self::defaults();

		foreach ( array( 'sheet_length', 'sheet_width', 'thickness', 'kerf', 'min_length', 'min_width' ) as $key ) {
			if ( isset( $raw[ $key ] ) && is_numeric( $raw[ $key ] ) && self::accepts( $key, (int) $raw[ $key ] ) ) {
				$out[ $key ] = (int) $raw[ $key ];
			}
		}
		if ( $out['sheet_width'] > $out['sheet_length'] ) {
			list( $out['sheet_length'], $out['sheet_width'] ) = array( $out['sheet_width'], $out['sheet_length'] );
		}
		if ( array_key_exists( 'rotate', $raw ) ) {
			$out['rotate'] = ! empty( $raw['rotate'] );
		}

		$out['boxes']     = Boxes::clean( isset( $raw['boxes'] ) ? $raw['boxes'] : array() );
		$out['cut_lists'] = self::clean_cut_lists( isset( $raw['cut_lists'] ) ? $raw['cut_lists'] : array() );

		return $out;
	}

	/**
	 * Whether a size (units) is one a setting can take: a sheet side of 1
	 * in or more, a thickness above 0 up to 6 in, a blade of 0 to 1 in, a
	 * smallest leftover of 0 or more.
	 *
	 * @param string $key   Setting.
	 * @param int    $units Size.
	 * @return bool
	 */
	public static function accepts( $key, $units ) {
		$inch  = Units::PER_INCH;
		$units = (int) $units;

		switch ( $key ) {
			case 'sheet_length':
			case 'sheet_width':
				return $units >= $inch && $units <= Units::MAX_INCHES * $inch;
			case 'thickness':
				return $units > 0 && $units <= 6 * $inch;
			case 'kerf':
				return $units >= 0 && $units <= $inch;
			case 'min_length':
			case 'min_width':
				return $units >= 0 && $units <= Units::MAX_INCHES * $inch;
		}

		return false;
	}

	/**
	 * The saved cut lists, cleaned: box key → { name, box, pieces }.
	 *
	 * @param mixed $raw Stored value.
	 * @return array<string, array{name: string, box: string, pieces: array[]}>
	 */
	public static function clean_cut_lists( $raw ) {
		$out = array();

		foreach ( (array) $raw as $key => $entry ) {
			if ( ! is_array( $entry ) || count( $out ) >= self::MAX_BOXES ) {
				continue;
			}

			$name   = isset( $entry['name'] ) && is_scalar( $entry['name'] ) ? Boxes::clean_name( (string) $entry['name'] ) : '';
			$key    = '' !== $name ? Boxes::key( $name ) : Boxes::key( (string) $key );
			$pieces = Cut_List::sanitize( isset( $entry['pieces'] ) ? $entry['pieces'] : array() );

			if ( '' === $key || ! $pieces ) {
				continue;
			}

			$out[ $key ] = array(
				'name'   => '' !== $name ? $name : $key,
				'box'    => isset( $entry['box'] ) && is_scalar( $entry['box'] ) && preg_match( '/^\d+x\d+x\d+$/', (string) $entry['box'] ) ? (string) $entry['box'] : '',
				'pieces' => $pieces,
			);
		}

		return $out;
	}

	/**
	 * The general settings from a posted form (inches as typed) merged
	 * into the current settings. A field that does not read as a size is
	 * reported and left as it was.
	 *
	 * @param array $current Current settings (units).
	 * @param array $post    Posted fields: sheet_length, sheet_width, thickness, kerf, rotate, min_length, min_width.
	 * @return array{settings: array, problems: string[]}
	 */
	public static function from_form( array $current, array $post ) {
		$labels = array(
			'sheet_length' => __( 'Sheet length', 'fishotel-misc-plugin' ),
			'sheet_width'  => __( 'Sheet width', 'fishotel-misc-plugin' ),
			'thickness'    => __( 'Thickness', 'fishotel-misc-plugin' ),
			'kerf'         => __( 'Blade width', 'fishotel-misc-plugin' ),
			'min_length'   => __( 'Smallest leftover (length)', 'fishotel-misc-plugin' ),
			'min_width'    => __( 'Smallest leftover (width)', 'fishotel-misc-plugin' ),
		);

		$next     = $current;
		$problems = array();

		foreach ( $labels as $key => $label ) {
			if ( ! isset( $post[ $key ] ) || ! is_scalar( $post[ $key ] ) ) {
				continue;
			}

			$units = Units::parse( (string) $post[ $key ] );

			if ( null === $units || ! self::accepts( $key, $units ) ) {
				/* translators: 1: field label, 2: what was typed */
				$problems[] = sprintf( __( '%1$s: "%2$s" is not a size this setting can take — left as it was.', 'fishotel-misc-plugin' ), $label, Store::clip( (string) $post[ $key ], 30 ) );
				continue;
			}

			$next[ $key ] = $units;
		}

		$next['rotate'] = ! empty( $post['rotate'] );

		return array(
			'settings' => self::sanitize( $next ),
			'problems' => $problems,
		);
	}

	/**
	 * The settings a plan needs (no lists).
	 *
	 * @param array $settings Settings.
	 * @return array{sheet_length: int, sheet_width: int, thickness: int, kerf: int, rotate: bool, min_length: int, min_width: int}
	 */
	public static function for_plan( array $settings ) {
		return array(
			'sheet_length' => (int) $settings['sheet_length'],
			'sheet_width'  => (int) $settings['sheet_width'],
			'thickness'    => (int) $settings['thickness'],
			'kerf'         => (int) $settings['kerf'],
			'rotate'       => ! empty( $settings['rotate'] ),
			'min_length'   => (int) $settings['min_length'],
			'min_width'    => (int) $settings['min_width'],
		);
	}
}
