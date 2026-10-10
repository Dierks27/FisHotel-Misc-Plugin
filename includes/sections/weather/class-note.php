<?php
/**
 * Weather note formatter — pure functions, no WordPress calls.
 *
 * Produces the private order note in the exact shape the order-workflow
 * skill prescribes (so a note written by the cron and one written by hand
 * from the skill are indistinguishable), the UPS Store customer email
 * draft, and the "weather unavailable" note.
 *
 * Skill format:
 *   Weather for [DELIVERY DATE] at [ZIP] ([City, State]): High [X]°F / Low [Y]°F — [Conditions]. [Any warnings if applicable.] [Band ending.]
 *
 * Example from the skill (76°F → "Nothing needed" band):
 *   Weather for April 1, 2026 at 23314 (Carrollton, VA): High 76°F / Low 57°F — Sunny, 10% chance of rain. Nothing needed — great shipping conditions.
 *
 * When only the high is known the "/ Low Y°F" part is left out rather than
 * a number invented (skill: "include just the high and skip the low").
 *
 * @package FisHotel\Misc\Sections\Weather
 */

namespace FisHotel\Misc\Sections\Weather;

defined( 'ABSPATH' ) || exit;

/**
 * Class Note
 */
class Note {

	/**
	 * Subject line for the UPS Store email, verbatim from the skill.
	 *
	 * @var string
	 */
	const EMAIL_SUBJECT = 'Your FisHotel Order #%s — Weather Shipping Change';

	/**
	 * "April 1, 2026" for a Y-m-d string. Pure (no locale, no site timezone:
	 * a calendar date has none).
	 *
	 * @param string $ymd Y-m-d.
	 * @return string The long date, or the input unchanged when it is not Y-m-d.
	 */
	public static function long_date( $ymd ) {
		$dt = self::date( $ymd );
		return $dt ? $dt->format( 'F j, Y' ) : (string) $ymd;
	}

	/**
	 * "Wed" for a Y-m-d string.
	 *
	 * @param string $ymd Y-m-d.
	 * @return string
	 */
	public static function short_day( $ymd ) {
		$dt = self::date( $ymd );
		return $dt ? $dt->format( 'D' ) : (string) $ymd;
	}

	/**
	 * Parse a strict Y-m-d calendar date.
	 *
	 * @param string $ymd Y-m-d.
	 * @return \DateTimeImmutable|null
	 */
	public static function date( $ymd ) {
		if ( ! is_string( $ymd ) || 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}$/', $ymd ) ) {
			return null;
		}

		$dt = \DateTimeImmutable::createFromFormat( '!Y-m-d', $ymd, new \DateTimeZone( 'UTC' ) );

		return ( $dt && $dt->format( 'Y-m-d' ) === $ymd ) ? $dt : null;
	}

	/**
	 * Y-m-d plus N days.
	 *
	 * @param string $ymd  Y-m-d.
	 * @param int    $days Days to add (may be negative).
	 * @return string
	 */
	public static function add_days( $ymd, $days ) {
		$dt = self::date( $ymd );
		if ( ! $dt ) {
			return (string) $ymd;
		}

		$days = (int) $days;

		return $dt->modify( ( $days >= 0 ? '+' : '' ) . $days . ' days' )->format( 'Y-m-d' );
	}

	/**
	 * "Sunny, 10% chance of rain" from an NWS short forecast and a
	 * probability of precipitation (null / 0 → conditions only).
	 *
	 * Wording follows the skill's example; "snow" is used instead of "rain"
	 * when the forecast itself is wintry.
	 *
	 * @param string   $short_forecast NWS shortForecast.
	 * @param int|null $pop            Probability of precipitation, percent.
	 * @return string
	 */
	public static function conditions( $short_forecast, $pop = null ) {
		$text = trim( preg_replace( '/\s+/', ' ', (string) $short_forecast ) );
		$text = rtrim( $text, '.' );

		if ( '' === $text ) {
			$text = 'Conditions not reported';
		}

		if ( null !== $pop && '' !== $pop && is_numeric( $pop ) && (int) $pop > 0 ) {
			$kind  = 1 === preg_match( '/snow|sleet|flurr|wintry|ice|freezing/i', $text ) ? 'snow' : 'rain';
			$text .= sprintf( ', %d%% chance of %s', (int) $pop, $kind );
		}

		return $text;
	}

	/**
	 * Build the note.
	 *
	 * @param array $d {
	 *   @type string      $delivery_date Y-m-d.
	 *   @type string      $zip           Destination ZIP.
	 *   @type string      $city          Destination city.
	 *   @type string      $state         Destination state (2-letter).
	 *   @type int         $high          Delivery-day high, °F.
	 *   @type int|null    $low           Delivery-day low, °F, or null when unknown.
	 *   @type string      $conditions    From self::conditions().
	 *   @type string[]    $warnings      Active NWS alert names (may be empty).
	 *   @type string      $ending        From Bands::classify()['ending'].
	 *   @type string|null $next_day_line From self::next_day_line(), or null.
	 * }
	 * @return string
	 */
	public static function format( array $d ) {
		$note = sprintf(
			'Weather for %s at %s (%s): High %d°F',
			self::long_date( $d['delivery_date'] ?? '' ),
			self::place_zip( $d['zip'] ?? '' ),
			self::place( $d['city'] ?? '', $d['state'] ?? '' ),
			(int) round( (float) ( $d['high'] ?? 0 ) )
		);

		if ( isset( $d['low'] ) && null !== $d['low'] && '' !== $d['low'] && is_numeric( $d['low'] ) ) {
			$note .= sprintf( ' / Low %d°F', (int) round( (float) $d['low'] ) );
		}

		$note .= ' — ' . rtrim( (string) ( $d['conditions'] ?? '' ), '.' ) . '.';

		$warnings = array_values( array_filter( array_map( 'trim', (array) ( $d['warnings'] ?? array() ) ), 'strlen' ) );
		if ( $warnings ) {
			$note .= ' Alerts: ' . implode( ', ', $warnings ) . '.';
		}

		$ending = trim( (string) ( $d['ending'] ?? '' ) );
		if ( '' !== $ending ) {
			$note .= ' ' . $ending;
		}

		$next = trim( (string) ( $d['next_day_line'] ?? '' ) );
		if ( '' !== $next ) {
			$note .= ' ' . $next;
		}

		return $note;
	}

	/**
	 * The next-day extremes sentence, or null when the next day is not
	 * extreme (skill: "surface next-day extremes in the note when relevant,
	 * e.g. 'Wed hits 98 — do not slip delivery'").
	 *
	 * @param string $next_ymd  Y-m-d of the day after delivery.
	 * @param mixed  $next_high Its forecast high, or null.
	 * @param array  $thresholds Thresholds.
	 * @return string|null
	 */
	public static function next_day_line( $next_ymd, $next_high, $thresholds = null ) {
		if ( null === $next_high || ! is_numeric( $next_high ) ) {
			return null;
		}

		$band = Bands::classify( $next_high, $thresholds );
		if ( ! Bands::is_extreme( $band['action'] ) ) {
			return null;
		}

		$verb = Bands::is_cold( $band['action'] ) ? 'drops to' : 'hits';

		return sprintf( '%s %s %d°F — do not slip delivery.', self::short_day( $next_ymd ), $verb, $band['high'] );
	}

	/**
	 * The note written when the forecast could not be fetched. Same prefix
	 * as a real note so it sorts and reads the same, and it never carries a
	 * band — "check manually" is the whole point.
	 *
	 * @param array $d {
	 *   @type string $delivery_date Y-m-d.
	 *   @type string $zip
	 *   @type string $city
	 *   @type string $state
	 *   @type string $reason        Short reason (no trailing period).
	 * }
	 * @return string
	 */
	public static function unavailable( array $d ) {
		$reason = rtrim( trim( (string) ( $d['reason'] ?? '' ) ), '.' );

		return sprintf(
			'Weather for %s at %s (%s): weather unavailable — check manually%s.',
			self::long_date( $d['delivery_date'] ?? '' ),
			self::place_zip( $d['zip'] ?? '' ),
			self::place( $d['city'] ?? '', $d['state'] ?? '' ),
			'' !== $reason ? ' (' . $reason . ')' : ''
		);
	}

	/**
	 * The UPS Store customer email draft, per the skill: destination hits
	 * X°F on delivery day (too hot / too cold for a box on the porch),
	 * shipping to a UPS Store near them for pickup so the fish stay
	 * climate-controlled, ask for a preferred UPS Store (or closest to their
	 * address), tracking follows on ship day, signed "Jeff — FisHotel".
	 *
	 * Never sent by this plugin. Jeff sends emails himself.
	 *
	 * @param array $d {
	 *   @type string $order_number  Order number as displayed.
	 *   @type string $first_name    Customer first name ('' → "there").
	 *   @type string $city
	 *   @type string $state
	 *   @type string $delivery_date Y-m-d.
	 *   @type int    $high          Delivery-day high.
	 *   @type string $action        ups_store_cold | ups_store_hot.
	 * }
	 * @return array{subject: string, body: string}
	 */
	public static function email_draft( array $d ) {
		$number = trim( (string) ( $d['order_number'] ?? '' ) );
		$name   = trim( (string) ( $d['first_name'] ?? '' ) );
		$cold   = Bands::is_cold( (string) ( $d['action'] ?? '' ) );

		$body = sprintf(
			"Hi %s,\n\n" .
			"Quick heads-up on your FisHotel order #%s: the forecast for %s on %s hits %d°F, which is too %s for a box of fish to sit on a porch. To keep them climate-controlled, I'd like to ship your box to a UPS Store near you for pickup instead of to your door.\n\n" .
			"Could you reply with your preferred UPS Store location? If you don't have one in mind, I'll use the store closest to your address. Tracking will follow on ship day as usual.\n\n" .
			"Thanks,\nJeff — FisHotel",
			'' !== $name ? $name : 'there',
			$number,
			self::place( $d['city'] ?? '', $d['state'] ?? '' ),
			self::long_date( $d['delivery_date'] ?? '' ),
			(int) round( (float) ( $d['high'] ?? 0 ) ),
			$cold ? 'cold' : 'hot'
		);

		return array(
			'subject' => sprintf( self::EMAIL_SUBJECT, $number ),
			'body'    => $body,
		);
	}

	/**
	 * "Carrollton, VA" — whichever parts are known.
	 *
	 * @param string $city  City.
	 * @param string $state State.
	 * @return string
	 */
	public static function place( $city, $state ) {
		$parts = array_values( array_filter( array( trim( (string) $city ), trim( (string) $state ) ), 'strlen' ) );
		return $parts ? implode( ', ', $parts ) : 'unknown';
	}

	/**
	 * ZIP for the note; "?" when missing so the shape is kept.
	 *
	 * @param string $zip ZIP.
	 * @return string
	 */
	private static function place_zip( $zip ) {
		$zip = trim( (string) $zip );
		return '' !== $zip ? $zip : '?';
	}
}
