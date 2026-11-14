<?php
/**
 * Shop App PINs — PURE: no WordPress, no database.
 *
 * A PIN is 4 to 6 digits (one length for the whole shop, a setting, so the
 * keypad can send it the moment the last digit is pressed). It is never
 * stored: the people table holds a hash made from the PIN, a salt of that
 * person's own and the site secret, so two people with the same PIN would
 * not show the same hash, and the table alone gives no PIN away.
 *
 * A PIN is the whole credential — there is no name to type — so it must
 * point at exactly one active person. clashes() is the uniqueness check;
 * the only thing the screens ever say about a clash is CLASH_MESSAGE, and
 * find() compares the typed PIN with every person before it answers, so
 * neither the reply nor its timing says whose PIN a wrong one came close
 * to, or whether it belongs to someone who is switched off.
 *
 * The device and enrolment tokens are made here too: long random strings
 * of which the server keeps only the SHA-256.
 *
 * @package FisHotel\Misc\Sections\Shop_App
 */

namespace FisHotel\Misc\Sections\Shop_App;

defined( 'ABSPATH' ) || exit;

/**
 * Class Pins
 */
class Pins {

	const MIN_LENGTH = 4;
	const MAX_LENGTH = 6;

	/**
	 * PBKDF2 rounds. A PIN has too few digits for any number of rounds
	 * to protect it once the site secret is known; this only makes one
	 * guess cost about a millisecond.
	 *
	 * @var int
	 */
	const ITERATIONS = 2000;

	/**
	 * How many hashes find() / clashes() computed in the last call (a
	 * test reads it to see that neither stops early).
	 *
	 * @var int
	 */
	public static $last_work = 0;

	/**
	 * A PIN length the settings accept, else the default.
	 *
	 * @param mixed $length Length.
	 * @return int
	 */
	public static function length( $length ) {
		$length = (int) $length;
		return $length >= self::MIN_LENGTH && $length <= self::MAX_LENGTH ? $length : self::MIN_LENGTH;
	}

	/**
	 * Whether a value is a PIN of exactly that many digits.
	 *
	 * @param mixed $pin    The typed PIN.
	 * @param int   $length The shop's PIN length.
	 * @return bool
	 */
	public static function is_pin( $pin, $length ) {
		return is_string( $pin ) && strlen( $pin ) === self::length( $length ) && ctype_digit( $pin );
	}

	/**
	 * A fresh per-person salt.
	 *
	 * @return string 32 hex characters.
	 */
	public static function new_salt() {
		return bin2hex( random_bytes( 16 ) );
	}

	/**
	 * The stored form of a PIN.
	 *
	 * @param string $pin    The PIN.
	 * @param string $salt   The person's salt.
	 * @param string $secret The site secret.
	 * @return string 64 hex characters.
	 */
	public static function hash( $pin, $salt, $secret ) {
		return hash_pbkdf2( 'sha256', (string) $pin, (string) $salt . '|' . (string) $secret, self::ITERATIONS, 64 );
	}

	/**
	 * Whether a PIN is this person's.
	 *
	 * @param string $pin    The typed PIN.
	 * @param array  $person A people row (pin_hash, pin_salt).
	 * @param string $secret The site secret.
	 * @return bool
	 */
	public static function matches( $pin, array $person, $secret ) {
		$stored = isset( $person['pin_hash'] ) ? (string) $person['pin_hash'] : '';
		$salt   = isset( $person['pin_salt'] ) ? (string) $person['pin_salt'] : '';

		// A person without a PIN still costs a hash, so the time a reply
		// takes says nothing about who has one.
		$hash = self::hash( $pin, '' !== $salt ? $salt : 'no-salt', $secret );

		return '' !== $stored && '' !== $salt && hash_equals( $stored, $hash );
	}

	/**
	 * Whether a person can unlock at all: switched on, with a PIN of the
	 * shop's current length.
	 *
	 * @param array $person A people row.
	 * @param int   $length The shop's PIN length.
	 * @return bool
	 */
	public static function usable( array $person, $length ) {
		return ! empty( $person['active'] )
			&& '' !== (string) ( $person['pin_hash'] ?? '' )
			&& (int) ( $person['pin_length'] ?? 0 ) === self::length( $length );
	}

	/**
	 * The person a typed PIN belongs to. Every person is compared before
	 * the answer is given — a match never ends the loop — and only one who
	 * can unlock (usable()) is ever returned.
	 *
	 * @param mixed   $pin    The typed PIN.
	 * @param array[] $people People rows.
	 * @param string  $secret The site secret.
	 * @param int     $length The shop's PIN length.
	 * @return array|null
	 */
	public static function find( $pin, array $people, $secret, $length ) {
		self::$last_work = 0;

		$typed = self::is_pin( $pin, $length ) ? (string) $pin : str_repeat( 'x', self::length( $length ) );
		$valid = self::is_pin( $pin, $length );
		$found = null;

		foreach ( $people as $person ) {
			if ( ! is_array( $person ) ) {
				continue;
			}
			++self::$last_work;
			$match = self::matches( $typed, $person, $secret );
			if ( $match && $valid && null === $found && self::usable( $person, $length ) ) {
				$found = $person;
			}
		}

		return $found;
	}

	/**
	 * Whether a PIN is already someone else's among the active people.
	 * Every active person is compared; nothing about who is returned.
	 *
	 * @param string  $pin       The PIN to be given.
	 * @param array[] $people    People rows.
	 * @param string  $secret    The site secret.
	 * @param int     $except_id The person being edited (their own PIN is no clash).
	 * @return bool
	 */
	public static function clashes( $pin, array $people, $secret, $except_id = 0 ) {
		self::$last_work = 0;

		$clash = false;
		foreach ( $people as $person ) {
			if ( ! is_array( $person ) || empty( $person['active'] ) || (int) ( $person['id'] ?? 0 ) === (int) $except_id ) {
				continue;
			}
			++self::$last_work;
			if ( self::matches( $pin, $person, $secret ) ) {
				$clash = true;
			}
		}

		return $clash;
	}

	/**
	 * The one thing a screen says when a PIN is taken.
	 *
	 * @return string
	 */
	public static function clash_message() {
		return __( 'Choose a different PIN.', 'fishotel-misc-plugin' );
	}

	/* ============================================================== */
	/*  Tokens                                                         */
	/* ============================================================== */

	/**
	 * A fresh device token: 256 random bits. Only token_hash() of it is
	 * ever stored.
	 *
	 * @return string 64 hex characters.
	 */
	public static function new_token() {
		return bin2hex( random_bytes( 32 ) );
	}

	/**
	 * Whether a cookie value has the shape of a device token.
	 *
	 * @param mixed $token Cookie value.
	 * @return bool
	 */
	public static function is_token( $token ) {
		return is_string( $token ) && 1 === preg_match( '/^[a-f0-9]{64}$/D', $token );
	}

	/**
	 * A fresh one-time enrolment token: 128 random bits (short enough for
	 * a small QR code, valid for ten minutes and one use).
	 *
	 * @return string 32 hex characters.
	 */
	public static function new_enrol_token() {
		return bin2hex( random_bytes( 16 ) );
	}

	/**
	 * Whether a value has the shape of an enrolment token.
	 *
	 * @param mixed $token Link parameter.
	 * @return bool
	 */
	public static function is_enrol_token( $token ) {
		return is_string( $token ) && 1 === preg_match( '/^[a-f0-9]{32}$/D', $token );
	}

	/**
	 * What the server keeps of a token.
	 *
	 * @param string $token Device or enrolment token.
	 * @return string 64 hex characters.
	 */
	public static function token_hash( $token ) {
		return hash( 'sha256', (string) $token );
	}
}
