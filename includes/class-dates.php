<?php
/**
 * US dates — the one place this plugin's PHP writes a date for a person
 * to read, and reads a date a person typed.
 *
 * A person reads a date as MM-DD-YY (11-27-26). What is STORED never
 * changes: the database, event notes ("sale_ready:2026-05-20"), REST and
 * AJAX payloads, exports, file names and every value="" attribute stay
 * YYYY-MM-DD. Call us() at the last moment, where the date is printed.
 *
 *   - us( '2026-11-27' )                → '11-27-26'
 *   - us_time( '2026-11-27 09:14' )     → '11-27-26 09:14'
 *   - stamp( $unix_time )               → '11-27-26 09:14' in the site zone
 *   - day( $unix_time )                 → '11-27-26' in the site zone
 *   - from_utc( '2026-11-27 15:14:03' ) → '11-27-26 09:14' in the site zone
 *   - parse( '11/27/26' )               → [ 'date' => '2026-11-27', 'error' => '' ]
 *
 * To change how every date reads, change DATE (and the two sprintf()
 * lines of us(), which write the same shape without a clock) — and
 * admin/js/fishotel-dates.js, the page's twin. Both are run against
 * tests/fixtures/us-dates.json.
 *
 * us() works on the digits alone: a date is a calendar day, so it is
 * never passed through a time stamp or a time zone, where it could slip
 * to the day before.
 *
 * @package FisHotel\Misc
 */

namespace FisHotel\Misc;

defined( 'ABSPATH' ) || exit;

/**
 * Class Dates
 */
class Dates {

	/**
	 * A date as a person reads it — PHP date() letters (11-27-26).
	 *
	 * @var string
	 */
	const DATE = 'm-d-y';

	/**
	 * The time beside a date (09:14).
	 *
	 * @var string
	 */
	const TIME = 'H:i';

	/**
	 * The day a refusal shows as its example (stored form).
	 *
	 * @var string
	 */
	const EXAMPLE = '2026-11-27';

	/**
	 * The script handle of admin/js/fishotel-dates.js.
	 *
	 * @var string
	 */
	const SCRIPT = 'fishotel-dates';

	/**
	 * Whether a value is a stored date: YYYY-MM-DD and a real day.
	 *
	 * @param mixed $ymd A value.
	 * @return bool
	 */
	public static function is_ymd( $ymd ) {
		if ( ! is_string( $ymd ) || 1 !== preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $ymd, $m ) ) {
			return false;
		}
		return checkdate( (int) $m[2], (int) $m[3], (int) $m[1] );
	}

	/**
	 * A stored date as a person reads it: 2026-11-27 → 11-27-26.
	 *
	 * A date-time gives its date alone. A year outside 2000–2099 is
	 * written in full, so it cannot be read as another century. Anything
	 * that is not a stored date comes back as it was — nothing is hidden.
	 *
	 * @param mixed  $ymd   YYYY-MM-DD (or a date-time starting with one).
	 * @param string $empty What nothing reads as.
	 * @return string
	 */
	public static function us( $ymd, $empty = '' ) {
		if ( null === $ymd || false === $ymd || ! is_scalar( $ymd ) || '' === trim( (string) $ymd ) ) {
			return (string) $empty;
		}
		$text = trim( (string) $ymd );
		if ( 1 !== preg_match( '/^(\d{4})-(\d{2})-(\d{2})(?:[ T].*)?$/', $text, $m ) || ! checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) {
			return $text;
		}
		$year = (int) $m[1];
		if ( $year < 2000 || $year > 2099 ) {
			return sprintf( '%s-%s-%04d', $m[2], $m[3], $year );
		}
		return sprintf( '%s-%s-%02d', $m[2], $m[3], $year % 100 );
	}

	/**
	 * A stored date-time as a person reads it, its time part kept:
	 * 2026-11-27 09:14 → 11-27-26 09:14.
	 *
	 * @param mixed  $value YYYY-MM-DD HH:MM[:SS] (or a date alone).
	 * @param string $empty What nothing reads as.
	 * @return string
	 */
	public static function us_time( $value, $empty = '' ) {
		if ( null === $value || false === $value || ! is_scalar( $value ) || '' === trim( (string) $value ) ) {
			return (string) $empty;
		}
		$text = trim( (string) $value );
		if ( 1 !== preg_match( '/^(\d{4}-\d{2}-\d{2})(?:[ T](.*))?$/', $text, $m ) || ! self::is_ymd( $m[1] ) ) {
			return $text;
		}
		$rest = isset( $m[2] ) ? trim( $m[2] ) : '';
		return self::us( $m[1] ) . ( '' !== $rest ? ' ' . $rest : '' );
	}

	/**
	 * A moment in the site's zone: 11-27-26 09:14.
	 *
	 * @param int                       $timestamp UNIX time.
	 * @param \DateTimeZone|string|null $tz        A zone (null = the site's, UTC without WordPress).
	 * @param bool                      $seconds   With the seconds.
	 * @return string '' when there is no moment.
	 */
	public static function stamp( $timestamp, $tz = null, $seconds = false ) {
		return self::moment( $timestamp, $tz, self::DATE . ' ' . self::TIME . ( $seconds ? ':s' : '' ) );
	}

	/**
	 * The calendar day of a moment in the site's zone: 11-27-26.
	 *
	 * @param int                       $timestamp UNIX time.
	 * @param \DateTimeZone|string|null $tz        A zone (null = the site's).
	 * @return string '' when there is no moment.
	 */
	public static function day( $timestamp, $tz = null ) {
		return self::moment( $timestamp, $tz, self::DATE );
	}

	/**
	 * A stored UTC date-time ('Y-m-d H:i:s') in the site's zone:
	 * 11-27-26 09:14. Text that is no time comes back as it was.
	 *
	 * @param mixed                     $utc A stored UTC date-time.
	 * @param \DateTimeZone|string|null $tz  A zone (null = the site's).
	 * @return string
	 */
	public static function from_utc( $utc, $tz = null ) {
		if ( null === $utc || ! is_scalar( $utc ) || '' === trim( (string) $utc ) || 0 === strpos( (string) $utc, '0000-00-00' ) ) {
			return '';
		}
		$ts = strtotime( trim( (string) $utc ) . ' UTC' );
		if ( false === $ts || $ts <= 0 ) {
			return (string) $utc;
		}
		return self::stamp( $ts, $tz );
	}

	/**
	 * A moment written with date() letters in a zone.
	 *
	 * @param int                       $timestamp UNIX time.
	 * @param \DateTimeZone|string|null $tz        A zone.
	 * @param string                    $format    date() letters.
	 * @return string
	 */
	private static function moment( $timestamp, $tz, $format ) {
		$timestamp = (int) $timestamp;
		if ( $timestamp <= 0 ) {
			return '';
		}
		try {
			$date = new \DateTimeImmutable( '@' . $timestamp );
			return $date->setTimezone( self::zone( $tz ) )->format( $format );
		} catch ( \Exception $e ) {
			return gmdate( $format, $timestamp );
		}
	}

	/**
	 * The zone to write a moment in.
	 *
	 * @param \DateTimeZone|string|null $tz A zone, a zone's name, or null for the site's.
	 * @return \DateTimeZone
	 */
	private static function zone( $tz ) {
		if ( $tz instanceof \DateTimeZone ) {
			return $tz;
		}
		if ( is_string( $tz ) && '' !== $tz ) {
			try {
				return new \DateTimeZone( $tz );
			} catch ( \Exception $e ) {
				return new \DateTimeZone( 'UTC' );
			}
		}
		if ( function_exists( 'wp_timezone' ) ) {
			$site = wp_timezone();
			if ( $site instanceof \DateTimeZone ) {
				return $site;
			}
		}
		return new \DateTimeZone( 'UTC' );
	}

	/**
	 * A date a person typed, read: month first — MM-DD-YY, MM/DD/YY,
	 * MM-DD-YYYY, M/D/YY (dots too) — or the stored YYYY-MM-DD. A year of
	 * two digits is 20YY. A date that does not exist (02-30-26, month 13)
	 * is refused with one plain sentence; it is never moved to another
	 * day. Nothing typed is no date and no error.
	 *
	 * @param mixed $typed What was typed.
	 * @return array{date:string,error:string} date is YYYY-MM-DD or ''.
	 */
	public static function parse( $typed ) {
		$out  = array( 'date' => '', 'error' => '' );
		$text = is_scalar( $typed ) ? trim( (string) $typed ) : '';
		if ( '' === $text ) {
			return $out;
		}

		if ( 1 === preg_match( '/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $text, $m ) ) {
			$year  = (int) $m[1];
			$month = (int) $m[2];
			$day   = (int) $m[3];
		} elseif ( 1 === preg_match( '#^(\d{1,2})[/\-.](\d{1,2})[/\-.](\d{2}|\d{4})$#', $text, $m ) ) {
			$month = (int) $m[1];
			$day   = (int) $m[2];
			$year  = 2 === strlen( $m[3] ) ? 2000 + (int) $m[3] : (int) $m[3];
		} else {
			/* translators: 1: what was typed, 2: an example date */
			$out['error'] = sprintf( __( '"%1$s" is not a date — type the month, the day and the year, like %2$s.', 'fishotel-misc-plugin' ), $text, self::us( self::EXAMPLE ) );
			return $out;
		}

		if ( $year < 1 || ! checkdate( $month, $day, $year ) ) {
			/* translators: %s: what was typed */
			$out['error'] = sprintf( __( '%s is not a day on the calendar.', 'fishotel-misc-plugin' ), $text );
			return $out;
		}

		$out['date'] = sprintf( '%04d-%02d-%02d', $year, $month, $day );
		return $out;
	}

	/**
	 * A typed date as YYYY-MM-DD, or null when there is none or it is
	 * refused (parse() says why).
	 *
	 * @param mixed $typed What was typed.
	 * @return string|null
	 */
	public static function to_ymd( $typed ) {
		$read = self::parse( $typed );
		return '' !== $read['date'] ? $read['date'] : null;
	}

	/**
	 * The page's twin: registers admin/js/fishotel-dates.js (the same
	 * format and parse, and the pop-up calendar that replaces a typed
	 * date) so a section's script can name it as a dependency. Safe to
	 * call more than once.
	 */
	public static function register_script() {
		if ( ! function_exists( 'wp_register_script' ) || ! defined( 'FISHOTEL_MISC_URL' ) ) {
			return;
		}
		if ( function_exists( 'wp_script_is' ) && wp_script_is( self::SCRIPT, 'registered' ) ) {
			return;
		}
		wp_register_script( self::SCRIPT, FISHOTEL_MISC_URL . 'admin/js/fishotel-dates.js', array(), FISHOTEL_MISC_VERSION, true );
		wp_localize_script( self::SCRIPT, 'fhDatesI18n', self::script_words() );
	}

	/**
	 * The words of the pop-up calendar and of the page's refusals.
	 *
	 * @return array<string,string>
	 */
	public static function script_words() {
		return array(
			'save'     => __( 'Save', 'fishotel-misc-plugin' ),
			'cancel'   => __( 'Cancel', 'fishotel-misc-plugin' ),
			'clear'    => __( 'No date', 'fishotel-misc-plugin' ),
			'date'     => __( 'Date', 'fishotel-misc-plugin' ),
			'pick'     => __( 'Pick a date first.', 'fishotel-misc-plugin' ),
			/* translators: %s: the words on the button that saves no date */
			'pickOr'   => __( 'Pick a date first, or choose "%s".', 'fishotel-misc-plugin' ),
			/* translators: 1: earliest date, 2: latest date */
			'range'    => __( 'Pick a date from %1$s to %2$s.', 'fishotel-misc-plugin' ),
			/* translators: %s: earliest date */
			'tooEarly' => __( 'Pick a date on or after %s.', 'fishotel-misc-plugin' ),
			/* translators: %s: latest date */
			'tooLate'  => __( 'Pick a date on or before %s.', 'fishotel-misc-plugin' ),
			/* translators: 1: what was typed, 2: an example date */
			'notDate'  => __( '"%1$s" is not a date — type the month, the day and the year, like %2$s.', 'fishotel-misc-plugin' ),
			/* translators: %s: what was typed */
			'noDay'    => __( '%s is not a day on the calendar.', 'fishotel-misc-plugin' ),
			'example'  => self::us( self::EXAMPLE ),
		);
	}
}
