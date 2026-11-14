<?php
/**
 * Weather bands — pure functions, no WordPress calls.
 *
 * WHY pure: the band is the one decision that costs fish if it goes wrong
 * (a heat pack in a 95°F box, or no pack at 35°F). Keeping it free of
 * get_option()/wc_* means it runs headless under tests/bands-test.php
 * with the exact highs from the skill's chart.
 *
 * The chart (fishotel-order-workflow SKILL.md, "Temperature chart"):
 * every band goes by the DELIVERY-DAY HIGH at the destination, cold side
 * included. With the default thresholds:
 *
 *   below 30°F   HOLD — wait to ship                 hold_cold
 *   30–40°F      UPS Store pickup + customer email   ups_store_cold
 *   40–70°F      Heat pack                           heat_pack
 *   70–80°F      Nothing needed                      none
 *   80–90°F      Cooling packs                       cooling
 *   90–95°F      UPS Store pickup + customer email   ups_store_hot
 *   above 95°F   HOLD — no ship                      hold_hot
 *
 * A high EXACTLY on a line (30, 40, 70, 80, 90, 95) is flagged
 * "AT THE LINE (X°F) — Jeff's call: [band below] or [band above]" and the
 * stored action is the more cautious of the two (the one further from
 * "nothing needed"), so the overview never under-packs while Jeff decides.
 *
 * @package FisHotel\Misc\Sections\Weather
 */

namespace FisHotel\Misc\Sections\Weather;

defined( 'ABSPATH' ) || exit;

/**
 * Class Bands
 */
class Bands {

	const ACTION_HOLD_COLD      = 'hold_cold';
	const ACTION_UPS_STORE_COLD = 'ups_store_cold';
	const ACTION_HEAT_PACK      = 'heat_pack';
	const ACTION_NONE           = 'none';
	const ACTION_COOLING        = 'cooling';
	const ACTION_UPS_STORE_HOT  = 'ups_store_hot';
	const ACTION_HOLD_HOT       = 'hold_hot';
	const ACTION_UNAVAILABLE    = 'unavailable';

	/**
	 * Bands from coldest to hottest. The index distance from `none` is how
	 * "cautious" a band is, which decides the stored action on a line.
	 *
	 * @var string[]
	 */
	const ORDER = array(
		self::ACTION_HOLD_COLD,
		self::ACTION_UPS_STORE_COLD,
		self::ACTION_HEAT_PACK,
		self::ACTION_NONE,
		self::ACTION_COOLING,
		self::ACTION_UPS_STORE_HOT,
		self::ACTION_HOLD_HOT,
	);

	/**
	 * Threshold keys, coldest line first. Each is the line BETWEEN
	 * ORDER[i] and ORDER[i+1].
	 *
	 * @var string[]
	 */
	const THRESHOLD_KEYS = array(
		'cold_hold_below',
		'cold_ups_to',
		'heat_pack_to',
		'nothing_to',
		'cooling_to',
		'hot_ups_to',
	);

	/**
	 * The skill's chart.
	 *
	 * @var array<string, int>
	 */
	const DEFAULT_THRESHOLDS = array(
		'cold_hold_below' => 30,
		'cold_ups_to'     => 40,
		'heat_pack_to'    => 70,
		'nothing_to'      => 80,
		'cooling_to'      => 90,
		'hot_ups_to'      => 95,
	);

	/**
	 * Sanity bounds for a threshold (°F). Anything outside is a typo.
	 *
	 * @var int
	 */
	const THRESHOLD_MIN = -40;
	const THRESHOLD_MAX = 130;

	/**
	 * Every action, in the order the overview totals show them.
	 *
	 * @return string[]
	 */
	public static function actions() {
		$actions   = self::ORDER;
		$actions[] = self::ACTION_UNAVAILABLE;
		return $actions;
	}

	/**
	 * Coerce a raw thresholds array into six strictly increasing integers.
	 *
	 * Any missing / non-numeric / out-of-range value, or a set that is not
	 * strictly increasing, returns the DEFAULTS wholesale — a half-valid
	 * chart would silently shift every band.
	 *
	 * @param mixed $raw Raw thresholds (array keyed by THRESHOLD_KEYS).
	 * @return array<string, int>
	 */
	public static function normalise_thresholds( $raw ) {
		if ( ! is_array( $raw ) ) {
			return self::DEFAULT_THRESHOLDS;
		}

		$out  = array();
		$prev = null;

		foreach ( self::THRESHOLD_KEYS as $key ) {
			if ( ! isset( $raw[ $key ] ) || ! is_scalar( $raw[ $key ] ) ) {
				return self::DEFAULT_THRESHOLDS;
			}

			$value = trim( (string) $raw[ $key ] );
			if ( '' === $value || ! is_numeric( $value ) ) {
				return self::DEFAULT_THRESHOLDS;
			}

			$value = (int) round( (float) $value );
			if ( $value < self::THRESHOLD_MIN || $value > self::THRESHOLD_MAX ) {
				return self::DEFAULT_THRESHOLDS;
			}

			if ( null !== $prev && $value <= $prev ) {
				return self::DEFAULT_THRESHOLDS;
			}

			$out[ $key ] = $value;
			$prev        = $value;
		}

		return $out;
	}

	/**
	 * Whether a raw thresholds array is usable as-is (strictly increasing,
	 * in range). Lets the settings save say "reset to defaults" out loud.
	 *
	 * @param mixed $raw Raw thresholds.
	 * @return bool
	 */
	public static function thresholds_valid( $raw ) {
		if ( ! is_array( $raw ) ) {
			return false;
		}

		$normalised = self::normalise_thresholds( $raw );

		foreach ( self::THRESHOLD_KEYS as $key ) {
			if ( ! isset( $raw[ $key ] ) || ! is_numeric( $raw[ $key ] ) || (int) round( (float) $raw[ $key ] ) !== $normalised[ $key ] ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Classify a delivery-day high.
	 *
	 * @param mixed      $high       Forecast high in °F (int/float/numeric string); null = unknown.
	 * @param array|null $thresholds Thresholds; null = defaults.
	 * @return array{
	 *   action: string,
	 *   high: int|null,
	 *   at_line: bool,
	 *   below: string|null,
	 *   above: string|null,
	 *   ending: string
	 * }
	 */
	public static function classify( $high, $thresholds = null ) {
		$t = self::normalise_thresholds( null === $thresholds ? self::DEFAULT_THRESHOLDS : $thresholds );

		if ( null === $high || '' === $high || ! is_numeric( $high ) ) {
			return array(
				'action'  => self::ACTION_UNAVAILABLE,
				'high'    => null,
				'at_line' => false,
				'below'   => null,
				'above'   => null,
				'ending'  => '',
			);
		}

		$high  = (int) round( (float) $high );
		$lines = array_values( $t );

		// Exactly on a line → both neighbours, stored action = more cautious.
		foreach ( $lines as $i => $line ) {
			if ( $high === $line ) {
				$below  = self::ORDER[ $i ];
				$above  = self::ORDER[ $i + 1 ];
				$action = self::more_cautious( $below, $above );

				return array(
					'action'  => $action,
					'high'    => $high,
					'at_line' => true,
					'below'   => $below,
					'above'   => $above,
					'ending'  => self::line_ending( $high, $below, $above, $t ),
				);
			}
		}

		$action = self::ORDER[0];
		foreach ( $lines as $i => $line ) {
			if ( $high > $line ) {
				$action = self::ORDER[ $i + 1 ];
			}
		}

		return array(
			'action'  => $action,
			'high'    => $high,
			'at_line' => false,
			'below'   => null,
			'above'   => null,
			'ending'  => self::ending( $action, $t ),
		);
	}

	/**
	 * Of two adjacent bands, the one further from "nothing needed".
	 *
	 * @param string $a Action.
	 * @param string $b Action.
	 * @return string
	 */
	public static function more_cautious( $a, $b ) {
		$none = array_search( self::ACTION_NONE, self::ORDER, true );
		$da   = abs( (int) array_search( $a, self::ORDER, true ) - $none );
		$db   = abs( (int) array_search( $b, self::ORDER, true ) - $none );

		return $db > $da ? $b : $a;
	}

	/**
	 * The note ending for a band, verbatim from the skill.
	 *
	 * @param string     $action     Action.
	 * @param array|null $thresholds Thresholds; null = defaults.
	 * @return string
	 */
	public static function ending( $action, $thresholds = null ) {
		$t = self::normalise_thresholds( null === $thresholds ? self::DEFAULT_THRESHOLDS : $thresholds );

		switch ( $action ) {
			case self::ACTION_HEAT_PACK:
				return 'Heat pack.';
			case self::ACTION_NONE:
				return 'Nothing needed — great shipping conditions.';
			case self::ACTION_COOLING:
				return 'Cooling packs.';
			case self::ACTION_UPS_STORE_COLD:
				return sprintf( 'UPS STORE (%d-%d°F): ship to local UPS Store for customer pickup. Email drafted for customer.', $t['cold_hold_below'], $t['cold_ups_to'] );
			case self::ACTION_UPS_STORE_HOT:
				return sprintf( 'UPS STORE (%d-%d°F): ship to local UPS Store for customer pickup. Email drafted for customer.', $t['cooling_to'], $t['hot_ups_to'] );
			case self::ACTION_HOLD_COLD:
				return sprintf( 'HOLD: below %d°F — wait to ship.', $t['cold_hold_below'] );
			case self::ACTION_HOLD_HOT:
				return sprintf( 'HOLD: above %d°F — no ship.', $t['hot_ups_to'] );
		}

		return '';
	}

	/**
	 * The "AT THE LINE" flag ending.
	 *
	 * @param int    $high  The high (equal to a line).
	 * @param string $below Band below the line.
	 * @param string $above Band above the line.
	 * @param array  $t     Normalised thresholds.
	 * @return string
	 */
	private static function line_ending( $high, $below, $above, array $t ) {
		return sprintf(
			'AT THE LINE (%d°F) — Jeff\'s call: %s or %s.',
			$high,
			self::band_label( $below, $t ),
			self::band_label( $above, $t )
		);
	}

	/**
	 * A band's name with its range, for the AT THE LINE flag and the UI.
	 *
	 * @param string     $action     Action.
	 * @param array|null $thresholds Thresholds; null = defaults.
	 * @return string
	 */
	public static function band_label( $action, $thresholds = null ) {
		$t = self::normalise_thresholds( null === $thresholds ? self::DEFAULT_THRESHOLDS : $thresholds );

		switch ( $action ) {
			case self::ACTION_HOLD_COLD:
				return sprintf( 'HOLD (below %d°F)', $t['cold_hold_below'] );
			case self::ACTION_UPS_STORE_COLD:
				return sprintf( 'UPS STORE (%d-%d°F)', $t['cold_hold_below'], $t['cold_ups_to'] );
			case self::ACTION_HEAT_PACK:
				return sprintf( 'Heat pack (%d-%d°F)', $t['cold_ups_to'], $t['heat_pack_to'] );
			case self::ACTION_NONE:
				return sprintf( 'Nothing needed (%d-%d°F)', $t['heat_pack_to'], $t['nothing_to'] );
			case self::ACTION_COOLING:
				return sprintf( 'Cooling packs (%d-%d°F)', $t['nothing_to'], $t['cooling_to'] );
			case self::ACTION_UPS_STORE_HOT:
				return sprintf( 'UPS STORE (%d-%d°F)', $t['cooling_to'], $t['hot_ups_to'] );
			case self::ACTION_HOLD_HOT:
				return sprintf( 'HOLD (above %d°F)', $t['hot_ups_to'] );
			case self::ACTION_UNAVAILABLE:
				return 'Weather unavailable';
		}

		return '';
	}

	/**
	 * Short badge label for the column / overview.
	 *
	 * @param string $action Action.
	 * @return string
	 */
	public static function short_label( $action ) {
		switch ( $action ) {
			case self::ACTION_HOLD_COLD:
				return 'HOLD (cold)';
			case self::ACTION_UPS_STORE_COLD:
				return 'UPS Store (cold)';
			case self::ACTION_HEAT_PACK:
				return 'Heat pack';
			case self::ACTION_NONE:
				return 'Nothing needed';
			case self::ACTION_COOLING:
				return 'Cooling packs';
			case self::ACTION_UPS_STORE_HOT:
				return 'UPS Store (hot)';
			case self::ACTION_HOLD_HOT:
				return 'HOLD (hot)';
			case self::ACTION_UNAVAILABLE:
				return 'Unavailable';
		}

		return '';
	}

	/**
	 * Whether an action is a known band (not unavailable / unknown).
	 *
	 * @param string $action Action.
	 * @return bool
	 */
	public static function is_band( $action ) {
		return in_array( $action, self::ORDER, true );
	}

	/**
	 * Whether the band wants the UPS Store customer email drafted.
	 *
	 * @param string $action Action.
	 * @return bool
	 */
	public static function is_ups_store( $action ) {
		return self::ACTION_UPS_STORE_COLD === $action || self::ACTION_UPS_STORE_HOT === $action;
	}

	/**
	 * Whether the band is a hold.
	 *
	 * @param string $action Action.
	 * @return bool
	 */
	public static function is_hold( $action ) {
		return self::ACTION_HOLD_COLD === $action || self::ACTION_HOLD_HOT === $action;
	}

	/**
	 * Whether the band is on the cold side of "nothing needed".
	 *
	 * @param string $action Action.
	 * @return bool
	 */
	public static function is_cold( $action ) {
		$idx = array_search( $action, self::ORDER, true );
		return false !== $idx && $idx < (int) array_search( self::ACTION_NONE, self::ORDER, true );
	}

	/**
	 * Whether a band counts as a next-day extreme worth surfacing in the
	 * note (UPS Store or hold on either side).
	 *
	 * @param string $action Action.
	 * @return bool
	 */
	public static function is_extreme( $action ) {
		return self::is_ups_store( $action ) || self::is_hold( $action );
	}
}
