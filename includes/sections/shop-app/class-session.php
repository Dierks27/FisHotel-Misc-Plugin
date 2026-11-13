<?php
/**
 * Shop App session — PURE: the signed cookie a correct PIN earns.
 *
 * The cookie's value is
 *   v1.<device id>.<person id>.<session id>.<unlocked at>.<last used at>.<signature>
 * — digits, hex and dots only. The signature is an HMAC-SHA256 over
 * everything before it, so the two times cannot be moved by the device.
 * What it names is checked against the server on every use (Access): the
 * device row must carry the same session id for the same person, so Lock,
 * a revoked device, a person switched off or a new unlock end a session
 * at once, whatever a copy of the cookie says.
 *
 * How long it lasts is a setting (30 minutes to 12 hours, two hours by
 * default) and so is where the count starts:
 *   last_use  from the last use (the default): every use moves it on;
 *   unlock    from the PIN: it ends that long after the unlock, used or not.
 * The check uses the settings of the moment, so shortening the length
 * takes effect on live sessions too.
 *
 * In last_use mode the cookie is written again with the new time on use —
 * but at most once a minute (RENEW_AFTER), so a burst of saves is one
 * Set-Cookie, and the length a person gets is never short by more than
 * that minute.
 *
 * @package FisHotel\Misc\Sections\Shop_App
 */

namespace FisHotel\Misc\Sections\Shop_App;

defined( 'ABSPATH' ) || exit;

/**
 * Class Session
 */
class Session {

	const VERSION = 'v1';

	const MODE_LAST_USE = 'last_use';
	const MODE_UNLOCK   = 'unlock';

	/**
	 * In last_use mode the cookie is rewritten at most this often (seconds).
	 *
	 * @var int
	 */
	const RENEW_AFTER = 60;

	/**
	 * A cookie dated further into the future than this is refused (seconds).
	 *
	 * @var int
	 */
	const CLOCK_SKEW = 120;

	/**
	 * A fresh session id.
	 *
	 * @return string 32 hex characters.
	 */
	public static function new_id() {
		return bin2hex( random_bytes( 16 ) );
	}

	/**
	 * The session length in seconds, from the settings.
	 *
	 * @param array $settings Settings (session_minutes).
	 * @return int
	 */
	public static function length( array $settings ) {
		$minutes = isset( $settings['session_minutes'] ) ? (int) $settings['session_minutes'] : 120;
		return max( 30, min( 720, $minutes ) ) * 60;
	}

	/**
	 * Where the count starts, from the settings.
	 *
	 * @param array $settings Settings (session_mode).
	 * @return string MODE_LAST_USE|MODE_UNLOCK
	 */
	public static function mode( array $settings ) {
		return isset( $settings['session_mode'] ) && self::MODE_UNLOCK === $settings['session_mode'] ? self::MODE_UNLOCK : self::MODE_LAST_USE;
	}

	/**
	 * The signature of a payload.
	 *
	 * @param string $payload Everything before the signature.
	 * @param string $key     The signing key.
	 * @return string 64 hex characters.
	 */
	public static function sign( $payload, $key ) {
		return hash_hmac( 'sha256', (string) $payload, (string) $key );
	}

	/**
	 * The cookie value for a session.
	 *
	 * @param array  $fields device_id, person_id, session_id, started, last.
	 * @param string $key    The signing key.
	 * @return string
	 */
	public static function build( array $fields, $key ) {
		$payload = implode(
			'.',
			array(
				self::VERSION,
				(int) $fields['device_id'],
				(int) $fields['person_id'],
				(string) $fields['session_id'],
				(int) $fields['started'],
				(int) $fields['last'],
			)
		);

		return $payload . '.' . self::sign( $payload, $key );
	}

	/**
	 * A cookie value taken apart — its shape only, nothing verified.
	 *
	 * @param mixed $value Cookie value.
	 * @return array{payload: string, signature: string, device_id: int, person_id: int, session_id: string, started: int, last: int}|null
	 */
	public static function parse( $value ) {
		if ( ! is_string( $value ) || strlen( $value ) > 200 ) {
			return null;
		}
		if ( 1 !== preg_match( '/^(v1\.(\d{1,12})\.(\d{1,12})\.([a-f0-9]{32})\.(\d{9,11})\.(\d{9,11}))\.([a-f0-9]{64})$/D', $value, $m ) ) {
			return null;
		}

		return array(
			'payload'    => $m[1],
			'signature'  => $m[7],
			'device_id'  => (int) $m[2],
			'person_id'  => (int) $m[3],
			'session_id' => $m[4],
			'started'    => (int) $m[5],
			'last'       => (int) $m[6],
		);
	}

	/**
	 * When a session ends.
	 *
	 * @param int   $started  Unlocked at.
	 * @param int   $last     Last used at.
	 * @param array $settings Settings.
	 * @return int Timestamp.
	 */
	public static function expires( $started, $last, array $settings ) {
		$from = self::MODE_UNLOCK === self::mode( $settings ) ? (int) $started : (int) $last;
		return $from + self::length( $settings );
	}

	/**
	 * Verify a cookie value: its shape, its signature, its times.
	 *
	 * @param mixed  $value    Cookie value.
	 * @param string $key      The signing key.
	 * @param int    $now      Timestamp.
	 * @param array  $settings Settings.
	 * @return array{ok: bool, reason: string, device_id: int, person_id: int, session_id: string, started: int, last: int, expires: int}
	 *         reason: ''|malformed|signature|times|expired.
	 */
	public static function check( $value, $key, $now, array $settings ) {
		$out = array(
			'ok'         => false,
			'reason'     => 'malformed',
			'device_id'  => 0,
			'person_id'  => 0,
			'session_id' => '',
			'started'    => 0,
			'last'       => 0,
			'expires'    => 0,
		);

		$parsed = self::parse( $value );
		if ( null === $parsed ) {
			return $out;
		}

		if ( '' === (string) $key || ! hash_equals( self::sign( $parsed['payload'], $key ), $parsed['signature'] ) ) {
			$out['reason'] = 'signature';
			return $out;
		}

		$out['device_id']  = $parsed['device_id'];
		$out['person_id']  = $parsed['person_id'];
		$out['session_id'] = $parsed['session_id'];
		$out['started']    = $parsed['started'];
		$out['last']       = $parsed['last'];
		$out['expires']    = self::expires( $parsed['started'], $parsed['last'], $settings );

		if ( $parsed['last'] < $parsed['started'] || $parsed['last'] > (int) $now + self::CLOCK_SKEW ) {
			$out['reason'] = 'times';
			return $out;
		}

		if ( (int) $now > $out['expires'] ) {
			$out['reason'] = 'expired';
			return $out;
		}

		$out['ok']     = true;
		$out['reason'] = '';
		return $out;
	}

	/**
	 * Whether a verified session's cookie should be written again now
	 * (last_use mode only, at most once a minute).
	 *
	 * @param array $check    The result of check() (ok).
	 * @param int   $now      Timestamp.
	 * @param array $settings Settings.
	 * @return bool
	 */
	public static function renewal_due( array $check, $now, array $settings ) {
		if ( empty( $check['ok'] ) || self::MODE_UNLOCK === self::mode( $settings ) ) {
			return false;
		}
		return (int) $now - (int) $check['last'] >= self::RENEW_AFTER;
	}
}
