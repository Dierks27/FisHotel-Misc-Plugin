<?php
/**
 * Shop App HTTP — the two cookies and the response headers, behind senders
 * a test can replace.
 *
 * The cookies:
 *   fh_shop_device_<COOKIEHASH>   the device token: a year, renewed on use.
 *   fh_shop_session_<COOKIEHASH>  the shop session: until it runs out.
 * Both are always Secure, HttpOnly and SameSite=Lax — no script can read
 * them, they never travel over plain HTTP, and another site's form or
 * script request never carries them. They are sent on the site's home
 * path (and on the WordPress path as well when the two differ, so
 * admin-ajax.php sees them wherever WordPress is installed).
 *
 * @package FisHotel\Misc\Sections\Shop_App
 */

namespace FisHotel\Misc\Sections\Shop_App;

defined( 'ABSPATH' ) || exit;

/**
 * Class Http
 */
class Http {

	const DEVICE  = 'device';
	const SESSION = 'session';

	/**
	 * How long the device cookie lives (seconds): a year.
	 *
	 * @var int
	 */
	const DEVICE_LIFE = 31536000;

	/**
	 * What sends a cookie: callable( string $name, string $value, array
	 * $options ), or null for PHP's setcookie().
	 *
	 * @var callable|null
	 */
	public static $cookie_sender = null;

	/**
	 * What sends a header line: callable( string $line ), or null for
	 * PHP's header().
	 *
	 * @var callable|null
	 */
	public static $header_sender = null;

	/**
	 * The full name of one of the two cookies.
	 *
	 * @param string $which DEVICE|SESSION.
	 * @return string
	 */
	public static function cookie_name( $which ) {
		$hash = defined( 'COOKIEHASH' ) ? COOKIEHASH : md5( (string) get_option( 'siteurl' ) );
		return 'fh_shop_' . ( self::SESSION === $which ? 'session' : 'device' ) . '_' . $hash;
	}

	/**
	 * The value of one of the two cookies on this request ('' = none).
	 *
	 * @param string $which DEVICE|SESSION.
	 * @return string
	 */
	public static function read_cookie( $which ) {
		$name = self::cookie_name( $which );
		return isset( $_COOKIE[ $name ] ) && is_string( $_COOKIE[ $name ] ) ? (string) $_COOKIE[ $name ] : '';
	}

	/**
	 * The paths a cookie is set on.
	 *
	 * @return string[]
	 */
	public static function cookie_paths() {
		$home  = defined( 'COOKIEPATH' ) && '' !== (string) COOKIEPATH ? (string) COOKIEPATH : '/';
		$site  = defined( 'SITECOOKIEPATH' ) && '' !== (string) SITECOOKIEPATH ? (string) SITECOOKIEPATH : $home;
		$paths = array( $home );
		if ( $site !== $home ) {
			$paths[] = $site;
		}
		return $paths;
	}

	/**
	 * The options every cookie of this section is sent with (pure).
	 *
	 * @param int    $expires Expiry timestamp (in the past = delete).
	 * @param string $path    Cookie path.
	 * @return array{expires: int, path: string, domain: string, secure: bool, httponly: bool, samesite: string}
	 */
	public static function cookie_options( $expires, $path = '/' ) {
		return array(
			'expires'  => (int) $expires,
			'path'     => (string) $path,
			'domain'   => defined( 'COOKIE_DOMAIN' ) && COOKIE_DOMAIN ? (string) COOKIE_DOMAIN : '',
			'secure'   => true,
			'httponly' => true,
			'samesite' => 'Lax',
		);
	}

	/**
	 * Send one of the two cookies, and make the rest of this request see
	 * it. Nothing is sent once the headers are out.
	 *
	 * @param string $which   DEVICE|SESSION.
	 * @param string $value   Cookie value.
	 * @param int    $expires Expiry timestamp.
	 * @return bool Whether it was sent.
	 */
	public static function set_cookie( $which, $value, $expires ) {
		$name = self::cookie_name( $which );

		if ( null === self::$cookie_sender && headers_sent() ) {
			return false;
		}

		foreach ( self::cookie_paths() as $path ) {
			$options = self::cookie_options( $expires, $path );
			if ( null !== self::$cookie_sender ) {
				call_user_func( self::$cookie_sender, $name, (string) $value, $options );
			} else {
				setcookie( $name, (string) $value, $options );
			}
		}

		if ( '' === (string) $value || (int) $expires < time() ) {
			unset( $_COOKIE[ $name ] );
		} else {
			$_COOKIE[ $name ] = (string) $value;
		}

		return true;
	}

	/**
	 * Delete one of the two cookies.
	 *
	 * @param string $which DEVICE|SESSION.
	 * @return bool
	 */
	public static function clear_cookie( $which ) {
		return self::set_cookie( $which, '', time() - 31536000 );
	}

	/**
	 * Send a header line.
	 *
	 * @param string $line "Name: value".
	 */
	public static function header( $line ) {
		if ( null !== self::$header_sender ) {
			call_user_func( self::$header_sender, (string) $line );
			return;
		}
		if ( ! headers_sent() ) {
			header( (string) $line );
		}
	}

	/**
	 * Whether this request carries the header every POST of the app sends.
	 *
	 * @return bool
	 */
	public static function has_app_header() {
		$key = 'HTTP_' . strtoupper( str_replace( '-', '_', Shop_App::HEADER ) );
		return isset( $_SERVER[ $key ] ) && '1' === (string) $_SERVER[ $key ];
	}

	/**
	 * The request method, upper case.
	 *
	 * @return string
	 */
	public static function method() {
		return isset( $_SERVER['REQUEST_METHOD'] ) && is_string( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( $_SERVER['REQUEST_METHOD'] ) : 'GET';
	}

	/**
	 * The path of the request, without its query string ('' when the
	 * server gave none or something that is not a plain path).
	 *
	 * @return string
	 */
	public static function path() {
		$uri = isset( $_SERVER['REQUEST_URI'] ) && is_string( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : '';
		if ( '' === $uri || '/' !== $uri[0] ) {
			return '';
		}
		$cut = strpos( $uri, '?' );
		return false === $cut ? $uri : (string) substr( $uri, 0, $cut );
	}
}
