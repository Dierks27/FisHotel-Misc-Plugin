<?php
/**
 * Invoice formats — PURE: the title and the short description of the
 * invoice product, which featured image a total gets, the forum list,
 * and the UTC bounds of a site day.
 *
 * The title is "<Handle> Invoice (M/D/YY)" — the customer's forum handle
 * and the SITE date without leading zeros (n/j/y), from a format kept in
 * Settings ({handle} and {date}); the short description is "<Handle>
 * Only". The forum list is the plain block pasted into the thread: one
 * line per invoice, "@Handle $Total https://…link", with no bold and no
 * dash added; a handle with a forum user id is written as the BBCode
 * mention [USER=id]Handle[/USER], which tags where a typed @mention does
 * not.
 *
 * @package FisHotel\Misc\Sections\Invoices
 */

namespace FisHotel\Misc\Sections\Invoices;

defined( 'ABSPATH' ) || exit;

/**
 * Class Format
 */
class Format {

	const DEFAULT_TITLE   = '{handle} Invoice ({date})';
	const DEFAULT_EXCERPT = '{handle} Only';

	/**
	 * A Y-m-d date as the titles write it: month / day / two-digit year,
	 * no leading zeros ("11/3/26"). Empty for anything that is not a date.
	 *
	 * @param string $ymd Y-m-d.
	 * @return string
	 */
	public static function date_njy( $ymd ) {
		if ( ! is_string( $ymd ) || ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $ymd, $m ) || ! checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) {
			return '';
		}
		return (int) $m[2] . '/' . (int) $m[3] . '/' . substr( $m[1], 2 );
	}

	/**
	 * Fill a format's {handle} and {date}.
	 *
	 * @param string $format Format.
	 * @param string $handle Handle.
	 * @param string $date   The date text ('' for a format without one).
	 * @return string
	 */
	private static function fill( $format, $handle, $date ) {
		$text = strtr(
			(string) $format,
			array(
				'{handle}' => (string) $handle,
				'{date}'   => (string) $date,
			)
		);
		return trim( (string) preg_replace( '/\s+/u', ' ', $text ) );
	}

	/**
	 * The product title: "<Handle> Invoice (11/3/26)".
	 *
	 * @param string $format The title format ('' = the default).
	 * @param string $handle Handle.
	 * @param string $ymd    The site date, Y-m-d.
	 * @return string
	 */
	public static function title( $format, $handle, $ymd ) {
		$format = self::usable( $format ) ? (string) $format : self::DEFAULT_TITLE;
		return self::fill( $format, $handle, self::date_njy( $ymd ) );
	}

	/**
	 * The short description: "<Handle> Only".
	 *
	 * @param string $format The excerpt format ('' = the default).
	 * @param string $handle Handle.
	 * @return string
	 */
	public static function excerpt( $format, $handle ) {
		$format = self::usable( $format ) ? (string) $format : self::DEFAULT_EXCERPT;
		return self::fill( $format, $handle, '' );
	}

	/**
	 * Whether a format can be used: it names the handle.
	 *
	 * @param mixed $format Format.
	 * @return bool
	 */
	public static function usable( $format ) {
		return is_string( $format ) && false !== strpos( $format, '{handle}' );
	}

	/**
	 * The featured image a total gets: the "under" image below the
	 * threshold, the "at or over" image from the threshold up. 0 (no
	 * image) when that image is not set.
	 *
	 * @param mixed $total     The invoice total.
	 * @param mixed $threshold The threshold.
	 * @param int   $under_id  Attachment id for totals under it.
	 * @param int   $over_id   Attachment id for totals at or over it.
	 * @return int
	 */
	public static function image_for_total( $total, $threshold, $under_id, $over_id ) {
		$id = Lines::cents( $total ) >= Lines::cents( $threshold ) ? (int) $over_id : (int) $under_id;
		return $id > 0 ? $id : 0;
	}

	/**
	 * How a handle is addressed in the forum list: the BBCode mention when
	 * its forum user id is known, else the typed @mention.
	 *
	 * @param string   $handle        Handle.
	 * @param int|null $forum_user_id Forum user id.
	 * @return string
	 */
	public static function forum_mention( $handle, $forum_user_id ) {
		$handle = trim( (string) $handle );
		if ( (int) $forum_user_id > 0 ) {
			return '[USER=' . (int) $forum_user_id . ']' . $handle . '[/USER]';
		}
		return '@' . $handle;
	}

	/**
	 * One line of the forum list: "@Handle $Total https://…link".
	 *
	 * @param array $row handle, forum_user_id, total, url.
	 * @return string
	 */
	public static function forum_line( array $row ) {
		$parts = array(
			self::forum_mention( isset( $row['handle'] ) ? $row['handle'] : '', isset( $row['forum_user_id'] ) ? $row['forum_user_id'] : 0 ),
			'$' . Lines::money_text( isset( $row['total'] ) ? $row['total'] : 0 ),
		);
		$url   = trim( (string) ( isset( $row['url'] ) ? $row['url'] : '' ) );
		if ( '' !== $url ) {
			$parts[] = $url;
		}
		return implode( ' ', $parts );
	}

	/**
	 * The forum list: one line per invoice, in the order given. Rows
	 * without a handle are left out.
	 *
	 * @param array[] $rows Rows of forum_line().
	 * @return string
	 */
	public static function forum_list( array $rows ) {
		$lines = array();
		foreach ( $rows as $row ) {
			if ( is_array( $row ) && '' !== trim( (string) ( isset( $row['handle'] ) ? $row['handle'] : '' ) ) ) {
				$lines[] = self::forum_line( $row );
			}
		}
		return implode( "\n", $lines );
	}

	/**
	 * The UTC moments a site day starts and ends at (the stored times are
	 * UTC; "published today" means today on the site's clock).
	 *
	 * @param string        $ymd  Site date, Y-m-d.
	 * @param \DateTimeZone $zone The site zone.
	 * @return array{0: string, 1: string}|null Y-m-d H:i:s start (inclusive) and end (exclusive).
	 */
	public static function day_bounds_utc( $ymd, \DateTimeZone $zone ) {
		if ( '' === self::date_njy( $ymd ) ) {
			return null;
		}
		try {
			$utc   = new \DateTimeZone( 'UTC' );
			$start = new \DateTimeImmutable( $ymd . ' 00:00:00', $zone );
			$end   = $start->modify( '+1 day' );
		} catch ( \Exception $e ) {
			return null;
		}

		return array(
			$start->setTimezone( $utc )->format( 'Y-m-d H:i:s' ),
			$end->setTimezone( $utc )->format( 'Y-m-d H:i:s' ),
		);
	}
}
