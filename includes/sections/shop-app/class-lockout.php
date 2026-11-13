<?php
/**
 * Shop App lockout — PURE: what five wrong PINs do to a device.
 *
 * The rule, as the screens state it: after 5 wrong PINs within 15
 * minutes the device is locked for 5 minutes; every lockout after that
 * doubles (10, 20, 40 … at most 24 hours); the doubling starts over once
 * a day has passed since the last lockout ended. While a device is locked
 * no PIN is even looked at, so a lockout cannot be used to try more PINs.
 *
 * A CORRECT PIN DOES NOT CLEAR THE WRONG ONES. It gives back only the one
 * try it used itself; the wrong PINs before it stay counted until the 15
 * minutes are over. Otherwise anybody with a PIN of their own could try
 * four others, unlock, try four more, and so walk through every PIN in
 * the shop without ever locking the device.
 *
 * The count lives on the device row (fail_count, fail_since — when the
 * count began —, lock_level, locked_until, all UTC). The Store takes one
 * attempt off the budget BEFORE the PIN is compared (reserve_attempt(),
 * one guarded UPDATE that also starts the count over when it lapsed), so
 * a burst of parallel requests gets five tries like anybody else.
 *
 * @package FisHotel\Misc\Sections\Shop_App
 */

namespace FisHotel\Misc\Sections\Shop_App;

defined( 'ABSPATH' ) || exit;

/**
 * Class Lockout
 */
class Lockout {

	/**
	 * Wrong PINs (within FAIL_MEMORY) before a device locks.
	 *
	 * @var int
	 */
	const MAX_ATTEMPTS = 5;

	/**
	 * How long wrong PINs stay counted (seconds): the count starts over
	 * with the first attempt made this long after it began.
	 *
	 * @var int
	 */
	const FAIL_MEMORY = 900;

	/**
	 * The first lockout (seconds).
	 *
	 * @var int
	 */
	const BASE_SECONDS = 300;

	/**
	 * The longest lockout (seconds).
	 *
	 * @var int
	 */
	const CAP_SECONDS = 86400;

	/**
	 * How long after a lockout ended the next one still doubles (seconds).
	 *
	 * @var int
	 */
	const LEVEL_MEMORY = 86400;

	/**
	 * A stored UTC datetime as a timestamp (0 = none).
	 *
	 * @param mixed $value Y-m-d H:i:s, or empty.
	 * @return int
	 */
	public static function ts( $value ) {
		if ( ! is_string( $value ) || '' === $value || 0 === strpos( $value, '0000-00-00' ) ) {
			return 0;
		}
		$ts = strtotime( $value . ' UTC' );
		return false === $ts ? 0 : (int) $ts;
	}

	/**
	 * Whether a device is locked now, and for how long.
	 *
	 * @param array $device A device row (locked_until).
	 * @param int   $now    Timestamp.
	 * @return array{locked: bool, seconds: int, until: int}
	 */
	public static function status( array $device, $now ) {
		$until  = self::ts( $device['locked_until'] ?? '' );
		$locked = $until > (int) $now;

		return array(
			'locked'  => $locked,
			'seconds' => $locked ? $until - (int) $now : 0,
			'until'   => $locked ? $until : 0,
		);
	}

	/**
	 * How long the lockout of a level lasts: 5 minutes, doubling, capped.
	 *
	 * @param int $level How many lockouts came before it.
	 * @return int Seconds.
	 */
	public static function duration( $level ) {
		$level = max( 0, (int) $level );
		// 2^9 × 300 is already past the cap; keep the shift small.
		$seconds = $level >= 9 ? self::CAP_SECONDS : self::BASE_SECONDS * ( 1 << $level );
		return (int) min( self::CAP_SECONDS, $seconds );
	}

	/**
	 * The level the next lockout counts from: the stored one while the
	 * last lockout is recent, zero once a day has passed since it ended.
	 *
	 * @param array $device A device row (lock_level, locked_until).
	 * @param int   $now    Timestamp.
	 * @return int
	 */
	public static function level( array $device, $now ) {
		$level = max( 0, (int) ( $device['lock_level'] ?? 0 ) );
		$until = self::ts( $device['locked_until'] ?? '' );

		if ( $level > 0 && $until > 0 && (int) $now - $until > self::LEVEL_MEMORY ) {
			return 0;
		}

		return $level;
	}

	/**
	 * The wrong PINs that still count: the stored count, or none once the
	 * count began longer ago than FAIL_MEMORY.
	 *
	 * @param array $device A device row (fail_count, fail_since).
	 * @param int   $now    Timestamp.
	 * @return int
	 */
	public static function count( array $device, $now ) {
		$count = max( 0, (int) ( $device['fail_count'] ?? 0 ) );
		$since = self::ts( $device['fail_since'] ?? '' );

		if ( $count > 0 && $since > 0 && (int) $now - $since >= self::FAIL_MEMORY ) {
			return 0;
		}

		return $count;
	}

	/**
	 * How many tries are left before the device locks.
	 *
	 * @param array $device A device row (fail_count).
	 * @return int
	 */
	public static function attempts_left( array $device ) {
		return max( 0, self::MAX_ATTEMPTS - (int) ( $device['fail_count'] ?? 0 ) );
	}

	/**
	 * What a wrong PIN changes. $device is the row AFTER the attempt was
	 * taken off the budget (fail_count already counts it).
	 *
	 * @param array $device A device row.
	 * @param int   $now    Timestamp.
	 * @return array{locked: bool, seconds: int, fields: array}
	 *         fields = the columns to write when it locks (empty otherwise).
	 */
	public static function after_failure( array $device, $now ) {
		if ( (int) ( $device['fail_count'] ?? 0 ) < self::MAX_ATTEMPTS ) {
			return array(
				'locked'  => false,
				'seconds' => 0,
				'fields'  => array(),
			);
		}

		$level   = self::level( $device, $now );
		$seconds = self::duration( $level );

		return array(
			'locked'  => true,
			'seconds' => $seconds,
			'fields'  => array(
				'locked_until' => gmdate( 'Y-m-d H:i:s', (int) $now + $seconds ),
				'lock_level'   => $level + 1,
				'fail_count'   => 0,
			),
		);
	}

	/**
	 * What "Clear lockout" on the Devices list changes.
	 *
	 * @return array
	 */
	public static function cleared() {
		return array(
			'fail_count'   => 0,
			'fail_since'   => null,
			'lock_level'   => 0,
			'locked_until' => null,
		);
	}

	/**
	 * "5 minutes" / "1 hour 20 minutes" / "24 hours" for a lockout.
	 *
	 * @param int $seconds Seconds.
	 * @return string
	 */
	public static function human( $seconds ) {
		$minutes = (int) ceil( max( 0, (int) $seconds ) / 60 );
		$hours   = (int) floor( $minutes / 60 );
		$minutes = $minutes % 60;

		$parts = array();
		if ( $hours > 0 ) {
			/* translators: %d: hours */
			$parts[] = sprintf( _n( '%d hour', '%d hours', $hours, 'fishotel-misc-plugin' ), $hours );
		}
		if ( $minutes > 0 || ! $parts ) {
			/* translators: %d: minutes */
			$parts[] = sprintf( _n( '%d minute', '%d minutes', $minutes, 'fishotel-misc-plugin' ), $minutes );
		}

		return implode( ' ', $parts );
	}
}
