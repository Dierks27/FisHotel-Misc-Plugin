<?php
/**
 * Shop App settings — two options, neither autoloaded (an ordinary request
 * never reads them):
 *
 *   fishotel_shopapp_settings  session_minutes (30–720, default 120: "do
 *                              not lock me out for two hours"),
 *                              session_mode (last_use — the length counts
 *                              from the last use, the default — or unlock:
 *                              from the PIN), pin_length (4–6, default 4).
 *   fishotel_shopapp_secret    the site secret the PIN hashes and the
 *                              session signatures are made with: 64 random
 *                              hex characters, written once when the
 *                              tables are installed. A wp-config constant
 *                              FISHOTEL_SHOP_APP_SECRET (32 characters or
 *                              more) takes its place when defined, which
 *                              keeps it out of the database — define it
 *                              BEFORE the first PIN is given: changing the
 *                              secret makes every PIN unusable until it is
 *                              set again.
 *
 * @package FisHotel\Misc\Sections\Shop_App
 */

namespace FisHotel\Misc\Sections\Shop_App;

defined( 'ABSPATH' ) || exit;

/**
 * Class Settings
 */
class Settings {

	const OPTION        = 'fishotel_shopapp_settings';
	const SECRET_OPTION = 'fishotel_shopapp_secret';

	const MIN_MINUTES = 30;
	const MAX_MINUTES = 720;

	/**
	 * The settings as stored, memoised for the request.
	 *
	 * @var array|null
	 */
	private static $cache = null;

	/**
	 * The defaults.
	 *
	 * @return array{session_minutes: int, session_mode: string, pin_length: int}
	 */
	public static function defaults() {
		return array(
			'session_minutes' => 120,
			'session_mode'    => Session::MODE_LAST_USE,
			'pin_length'      => Pins::MIN_LENGTH,
		);
	}

	/**
	 * Raw input → valid settings (pure). Anything out of range falls back
	 * to the nearest allowed value or the default.
	 *
	 * @param mixed $raw Stored or posted values.
	 * @return array{session_minutes: int, session_mode: string, pin_length: int}
	 */
	public static function sanitize( $raw ) {
		$raw = is_array( $raw ) ? $raw : array();
		$out = self::defaults();

		if ( isset( $raw['session_minutes'] ) && is_scalar( $raw['session_minutes'] ) && is_numeric( $raw['session_minutes'] ) ) {
			$out['session_minutes'] = (int) max( self::MIN_MINUTES, min( self::MAX_MINUTES, (int) $raw['session_minutes'] ) );
		}
		if ( isset( $raw['session_mode'] ) && Session::MODE_UNLOCK === $raw['session_mode'] ) {
			$out['session_mode'] = Session::MODE_UNLOCK;
		}
		if ( isset( $raw['pin_length'] ) && is_scalar( $raw['pin_length'] ) ) {
			$out['pin_length'] = Pins::length( $raw['pin_length'] );
		}

		return $out;
	}

	/**
	 * The settings.
	 *
	 * @return array{session_minutes: int, session_mode: string, pin_length: int}
	 */
	public static function get() {
		if ( null === self::$cache ) {
			self::$cache = self::sanitize( get_option( self::OPTION, array() ) );
		}
		return self::$cache;
	}

	/**
	 * Store sanitised settings.
	 *
	 * @param mixed $raw Posted values.
	 * @return array The settings as stored.
	 */
	public static function save( $raw ) {
		$clean = self::sanitize( $raw );
		update_option( self::OPTION, $clean, false );
		self::$cache = $clean;
		return $clean;
	}

	/**
	 * Forget the memoised settings (tests, and after a save elsewhere).
	 */
	public static function forget() {
		self::$cache = null;
	}

	/**
	 * Whether a wp-config constant supplies the secret.
	 *
	 * @return bool
	 */
	public static function secret_from_constant() {
		return defined( 'FISHOTEL_SHOP_APP_SECRET' ) && is_string( FISHOTEL_SHOP_APP_SECRET ) && strlen( FISHOTEL_SHOP_APP_SECRET ) >= 32;
	}

	/**
	 * Write the stored secret when there is none yet (install).
	 *
	 * @return bool Whether one is stored now.
	 */
	public static function ensure_secret() {
		$stored = get_option( self::SECRET_OPTION, '' );
		if ( is_string( $stored ) && strlen( $stored ) >= 64 ) {
			return true;
		}

		add_option( self::SECRET_OPTION, bin2hex( random_bytes( 32 ) ), '', false );

		$stored = get_option( self::SECRET_OPTION, '' );
		return is_string( $stored ) && strlen( $stored ) >= 64;
	}

	/**
	 * The site secret: the constant when defined, else the stored one
	 * (written now if the install step never did). '' when none can be
	 * had — callers then refuse to hash or sign.
	 *
	 * @return string
	 */
	public static function secret() {
		if ( self::secret_from_constant() ) {
			return (string) FISHOTEL_SHOP_APP_SECRET;
		}

		$stored = get_option( self::SECRET_OPTION, '' );
		if ( ! is_string( $stored ) || strlen( $stored ) < 64 ) {
			self::ensure_secret();
			$stored = get_option( self::SECRET_OPTION, '' );
		}

		return is_string( $stored ) && strlen( $stored ) >= 64 ? $stored : '';
	}

	/**
	 * The key the session cookies are signed with: the site secret mixed
	 * with WordPress's own auth salt, so changing the WordPress keys ends
	 * every shop session too (the PINs and the enrolled devices survive
	 * it — they do not depend on those keys).
	 *
	 * @return string '' when there is no secret.
	 */
	public static function session_key() {
		$secret = self::secret();
		if ( '' === $secret ) {
			return '';
		}
		return hash_hmac( 'sha256', 'fishotel-shop-app-session', $secret . '|' . wp_salt( 'auth' ) );
	}
}
