<?php
/**
 * Gift card codes — PURE apart from the random source.
 *
 * A new code is 16 characters from a 31-character alphabet with no
 * look-alikes (no 0 / O, no 1 / I / L), in four groups:
 * "K7QM-2XRT-9WHC-NB4E". That is about 79 random bits from PHP's
 * cryptographically secure random_int(): nobody can guess one, and the
 * redeem form is also rate limited.
 *
 * A code is looked up by its KEY — upper case, letters and digits only —
 * so "k7qm 2xrt 9whc nb4e" finds the same card. Imported codes keep
 * their old look; their key is made the same way.
 *
 * A code is shown in full only to the shop owner (and logged when it is
 * shown) and to the person whose card it is; everywhere else it is masked
 * to its last four characters.
 *
 * @package FisHotel\Misc\Sections\Gift_Cards
 */

namespace FisHotel\Misc\Sections\Gift_Cards;

defined( 'ABSPATH' ) || exit;

/**
 * Class Codes
 */
class Codes {

	const ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
	const LENGTH   = 16;
	const GROUP    = 4;

	/**
	 * The shortest and longest key a typed code may have (old codes too).
	 */
	const MIN_KEY = 8;
	const MAX_KEY = 40;

	/**
	 * A new random code.
	 *
	 * @param callable|null $random fn( int $min, int $max ): int — random_int() unless a test hands one in.
	 * @return string
	 */
	public static function generate( $random = null ) {
		$random   = is_callable( $random ) ? $random : 'random_int';
		$alphabet = self::ALPHABET;
		$last     = strlen( $alphabet ) - 1;
		$chars    = '';
		for ( $i = 0; $i < self::LENGTH; $i++ ) {
			$chars .= $alphabet[ (int) call_user_func( $random, 0, $last ) ];
		}
		return implode( '-', str_split( $chars, self::GROUP ) );
	}

	/**
	 * The lookup key of a code: upper case, letters and digits only.
	 *
	 * @param mixed $code What was typed.
	 * @return string '' when it cannot be a code.
	 */
	public static function key( $code ) {
		if ( ! is_scalar( $code ) ) {
			return '';
		}
		$key = strtoupper( preg_replace( '/[^A-Za-z0-9]/', '', (string) $code ) );
		$len = strlen( $key );
		return $len >= self::MIN_KEY && $len <= self::MAX_KEY ? $key : '';
	}

	/**
	 * A code masked to its last four characters: "••••-••••-••••-NB4E".
	 *
	 * @param string $code The code.
	 * @return string
	 */
	public static function mask( $code ) {
		$key = strtoupper( preg_replace( '/[^A-Za-z0-9]/', '', (string) $code ) );
		if ( strlen( $key ) <= 4 ) {
			return '••••';
		}
		return '••••-••••-••••-' . substr( $key, -4 );
	}

	/**
	 * The last four characters of a code (what staff search by).
	 *
	 * @param string $code The code.
	 * @return string
	 */
	public static function last4( $code ) {
		return substr( strtoupper( preg_replace( '/[^A-Za-z0-9]/', '', (string) $code ) ), -4 );
	}
}
