<?php
/**
 * Units — PURE. Every size in this section is an integer number of
 * ten-thousandths of an inch (10 000 = 1 in), so the packer never adds or
 * compares floats: 1/16 in is exactly 625, half an inch 5000, and two cuts
 * that should meet do meet. Inches only appear at the edges — what the
 * owner types (decimals or tape-measure fractions) and what is shown or
 * exported.
 *
 * @package FisHotel\Misc\Sections\Foam_Cuts
 */

namespace FisHotel\Misc\Sections\Foam_Cuts;

defined( 'ABSPATH' ) || exit;

/**
 * Class Units
 */
class Units {

	/**
	 * Units per inch.
	 *
	 * @var int
	 */
	const PER_INCH = 10000;

	/**
	 * The largest size a field accepts, in inches (a typing mistake guard:
	 * a foam sheet is 96 in long).
	 *
	 * @var int
	 */
	const MAX_INCHES = 600;

	/**
	 * A typed size → units, or null when it does not read as a size.
	 *
	 * Accepts a number (12, 9.5, "11.75", ".5"), a fraction ("3/4"), a
	 * tape-measure reading ("11 3/4", "11-3/4") and an optional trailing
	 * in / inch / inches / ". A comma is read as a decimal point. Zero is a
	 * valid answer (a kerf of 0); a negative or absurd size is not.
	 *
	 * @param mixed $raw What was typed.
	 * @return int|null
	 */
	public static function parse( $raw ) {
		if ( is_int( $raw ) || is_float( $raw ) ) {
			return self::from_inches( $raw );
		}

		if ( ! is_string( $raw ) ) {
			return null;
		}

		$text = strtolower( trim( $raw ) );
		$text = preg_replace( '/\s*(inches|inch|in\.?|"|\x{2033}|\x{201d})$/u', '', $text );
		$text = str_replace( ',', '.', trim( (string) $text ) );

		if ( '' === $text ) {
			return null;
		}

		$inches = null;

		if ( preg_match( '/^(\d+(?:\.\d+)?|\.\d+)$/', $text ) ) {
			$inches = (float) $text;
		} elseif ( preg_match( '/^(\d+)\s*\/\s*(\d+)$/', $text, $m ) ) {
			if ( (int) $m[2] > 0 ) {
				$inches = (int) $m[1] / (int) $m[2];
			}
		} elseif ( preg_match( '/^(\d+)(?:\s+|\s*-\s*)(\d+)\s*\/\s*(\d+)$/', $text, $m ) ) {
			if ( (int) $m[3] > 0 ) {
				$inches = (int) $m[1] + (int) $m[2] / (int) $m[3];
			}
		}

		return null === $inches ? null : self::from_inches( $inches );
	}

	/**
	 * Inches → units (rounded to the nearest ten-thousandth), or null when
	 * negative, not finite or larger than MAX_INCHES.
	 *
	 * @param int|float $inches Inches.
	 * @return int|null
	 */
	public static function from_inches( $inches ) {
		$inches = (float) $inches;

		if ( ! is_finite( $inches ) || $inches < 0 || $inches > self::MAX_INCHES ) {
			return null;
		}

		return (int) round( $inches * self::PER_INCH );
	}

	/**
	 * Units → inches as a number for JSON (at most four decimals; a whole
	 * number of inches is an integer, so 96 is exported as 96, not 96.0).
	 *
	 * @param int $units Units.
	 * @return int|float
	 */
	public static function to_inches( $units ) {
		$units = (int) $units;

		if ( 0 === $units % self::PER_INCH ) {
			return intdiv( $units, self::PER_INCH );
		}

		return round( $units / self::PER_INCH, 4 );
	}

	/**
	 * Units → a decimal string for a form field ("11.75", "12", "0.0625").
	 *
	 * @param int $units Units.
	 * @return string
	 */
	public static function decimal( $units ) {
		$units = (int) $units;
		$sign  = $units < 0 ? '-' : '';
		$units = abs( $units );
		$whole = intdiv( $units, self::PER_INCH );
		$rest  = $units % self::PER_INCH;

		if ( 0 === $rest ) {
			return $sign . $whole;
		}

		return $sign . $whole . '.' . rtrim( str_pad( (string) $rest, 4, '0', STR_PAD_LEFT ), '0' );
	}

	/**
	 * Units → the way a tape measure reads: "12", "9 1/2", "11 3/4",
	 * "1/16". A size that is not a whole number of sixteenths is shown as
	 * a decimal ("10.3").
	 *
	 * @param int $units Units.
	 * @return string
	 */
	public static function format( $units ) {
		$units     = (int) $units;
		$sixteenth = intdiv( self::PER_INCH, 16 );

		if ( $units < 0 || 0 !== $units % $sixteenth ) {
			return self::decimal( $units );
		}

		$whole = intdiv( $units, self::PER_INCH );
		$num   = intdiv( $units % self::PER_INCH, $sixteenth );

		if ( 0 === $num ) {
			return (string) $whole;
		}

		$den = 16;
		while ( 0 === $num % 2 ) {
			$num = intdiv( $num, 2 );
			$den = intdiv( $den, 2 );
		}

		return ( $whole > 0 ? $whole . ' ' : '' ) . $num . '/' . $den;
	}

	/**
	 * "16 × 12" — two sizes as people say them (no unit).
	 *
	 * @param int $a First size, units.
	 * @param int $b Second size, units.
	 * @return string
	 */
	public static function size( $a, $b ) {
		return self::format( $a ) . ' × ' . self::format( $b );
	}

	/**
	 * Square units → square feet (two decimals), for the totals.
	 *
	 * @param int|float $area Area in square units.
	 * @return float
	 */
	public static function square_feet( $area ) {
		return round( (float) $area / ( self::PER_INCH * self::PER_INCH ) / 144, 2 );
	}

	/**
	 * A share as a percentage with one decimal (0 when the whole is 0).
	 *
	 * @param int|float $part  Part.
	 * @param int|float $whole Whole.
	 * @return float
	 */
	public static function percent( $part, $whole ) {
		if ( (float) $whole <= 0 ) {
			return 0.0;
		}
		return round( 100 * (float) $part / (float) $whole, 1 );
	}
}
