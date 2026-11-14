<?php
/**
 * The Invoices line on the Today page and its widget (the Today
 * section's `fishotel_today_lines` filter): "Invoices: 2 published and
 * not paid for 7 days or more", with how long the oldest has waited and a
 * link to the list of published invoices. The number of days is a setting
 * (Invoices → Settings → On the Today page, default 7).
 *
 * Shown only while this section is switched on (boot() hooks it, in
 * wp-admin only), for a user who may open the section, and only when the
 * count is above zero. One COUNT query, once per request.
 *
 * @package FisHotel\Misc\Sections\Invoices
 */

namespace FisHotel\Misc\Sections\Invoices;

defined( 'ABSPATH' ) || exit;

/**
 * Class Today_Line
 */
class Today_Line {

	/**
	 * The Today section's filter, this line's key and its heading there.
	 *
	 * @var string
	 */
	const FILTER = 'fishotel_today_lines';
	const KEY    = 'invoices';
	const GROUP  = 'customers';

	/**
	 * Where the counts come from (null = counts()); a test hands in its
	 * own.
	 *
	 * @var callable|null
	 */
	private static $source = null;

	/**
	 * The line worked out once for this request (array( line|null )).
	 *
	 * @var array|null
	 */
	private static $cached = null;

	/**
	 * Hook the line (boot(), wp-admin only).
	 */
	public static function register() {
		add_filter( self::FILTER, array( self::class, 'filter' ), 20, 1 );
	}

	/**
	 * Use another source for the counts, and forget the cached line.
	 *
	 * @param callable|null $source Returns what counts() returns.
	 */
	public static function use_source( $source = null ) {
		self::$source = is_callable( $source ) ? $source : null;
		self::$cached = null;
	}

	/**
	 * The line (pure): null when no invoice has waited that long.
	 *
	 * @param array  $counts count, days (the setting), oldest_days.
	 * @param string $url    The list of published invoices.
	 * @return array|null
	 */
	public static function line( array $counts, $url ) {
		$count = max( 0, (int) ( $counts['count'] ?? 0 ) );
		if ( $count <= 0 ) {
			return null;
		}
		$days   = max( 1, (int) ( $counts['days'] ?? Settings::DEFAULT_UNPAID_DAYS ) );
		$oldest = max( 0, (int) ( $counts['oldest_days'] ?? 0 ) );

		return array(
			'key'    => self::KEY,
			'group'  => self::GROUP,
			'text'   => sprintf(
				/* translators: 1: invoices, 2: days */
				_n( 'Invoices: %1$d published and not paid for %2$d days or more', 'Invoices: %1$d published and not paid for %2$d days or more', $count, 'fishotel-misc-plugin' ),
				$count,
				$days
			),
			/* translators: %d: days */
			'detail' => $oldest > $days ? sprintf( _n( 'the oldest for %d day', 'the oldest for %d days', $oldest, 'fishotel-misc-plugin' ), $oldest ) : '',
			'url'    => (string) $url,
			'count'  => $count,
			'tone'   => 'todo',
		);
	}

	/**
	 * The counts: published invoices not paid since before the setting's
	 * number of days (one COUNT query).
	 *
	 * @return array
	 */
	private static function counts() {
		$days   = (int) Settings::get()['unpaid_days'];
		$now    = time();
		$found  = ( new Store() )->unpaid_since( gmdate( 'Y-m-d H:i:s', $now - $days * DAY_IN_SECONDS ) );
		$oldest = '' !== $found['oldest'] ? strtotime( $found['oldest'] . ' UTC' ) : false;

		return array(
			'count'       => (int) $found['count'],
			'days'        => $days,
			'oldest_days' => false !== $oldest ? (int) floor( ( $now - $oldest ) / DAY_IN_SECONDS ) : 0,
		);
	}

	/**
	 * Where the line leads: the list, showing the published invoices.
	 *
	 * @return string
	 */
	private static function url() {
		return add_query_arg(
			array(
				'page'  => Invoices::MENU_SLUG,
				'state' => 'published',
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * The lines with this one added (pure): a line under the same key is
	 * replaced, never doubled; no line leaves the others untouched.
	 *
	 * @param mixed      $lines What the filter carries so far.
	 * @param array|null $line  line().
	 * @return array[]
	 */
	public static function with_line( $lines, $line ) {
		$out = array();
		foreach ( is_array( $lines ) ? $lines : array() as $existing ) {
			if ( is_array( $existing ) && self::KEY === (string) ( $existing['key'] ?? '' ) ) {
				continue;
			}
			$out[] = $existing;
		}
		if ( is_array( $line ) ) {
			$out[] = $line;
		}
		return $out;
	}

	/**
	 * Filter callback: the Today lines gain this section's one, for a
	 * user who may open it. Whatever goes wrong adds nothing.
	 *
	 * @param mixed $lines The lines so far.
	 * @return array[]
	 */
	public static function filter( $lines = array() ) {
		$lines = is_array( $lines ) ? $lines : array();
		if ( ! function_exists( 'current_user_can' ) || ! current_user_can( Invoices::CAPABILITY ) ) {
			return $lines;
		}

		if ( null === self::$cached ) {
			try {
				$counts       = null !== self::$source ? call_user_func( self::$source ) : self::counts();
				self::$cached = array( self::line( is_array( $counts ) ? $counts : array(), self::url() ) );
			} catch ( \Throwable $e ) {
				self::$cached = array( null );
			}
		}

		return self::with_line( $lines, self::$cached[0] );
	}
}
