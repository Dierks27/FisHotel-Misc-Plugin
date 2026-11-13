<?php
/**
 * Lost Fish's line on the Today page and its widget (the Today section's
 * `fishotel_today_lines` filter): "Lost fish: 2 cases need you", with
 * what is waiting beside it — cases to send, answers that came in, cases
 * with no answer after the reminder — and a link to the Cases tab.
 *
 * Shown only while this section is switched on (boot() hooks it, in
 * wp-admin only), for a user who may open the section, and only when the
 * count is above zero. The numbers are the stored counts (an autoloaded
 * option — no query), read once per request.
 *
 * @package FisHotel\Misc\Sections\Lost_Fish
 */

namespace FisHotel\Misc\Sections\Lost_Fish;

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
	const KEY    = 'lost-fish';
	const GROUP  = 'customers';

	/**
	 * Where the counts come from (null = Store::stored_counts()); a test
	 * hands in its own.
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
	 * @param callable|null $source Returns the stored_counts() shape.
	 */
	public static function use_source( $source = null ) {
		self::$source = is_callable( $source ) ? $source : null;
		self::$cached = null;
	}

	/**
	 * The line (pure): null when no case needs the owner.
	 *
	 * @param array  $counts open, answered, stale (stored_counts()).
	 * @param string $url    The Cases tab.
	 * @return array|null
	 */
	public static function line( array $counts, $url ) {
		$open     = max( 0, (int) ( $counts['open'] ?? 0 ) );
		$answered = max( 0, (int) ( $counts['answered'] ?? 0 ) );
		$stale    = max( 0, (int) ( $counts['stale'] ?? 0 ) );
		$needs    = $open + $answered + $stale;
		if ( $needs <= 0 ) {
			return null;
		}

		$parts = array();
		if ( $open > 0 ) {
			/* translators: %d: cases */
			$parts[] = sprintf( _n( '%d to send', '%d to send', $open, 'fishotel-misc-plugin' ), $open );
		}
		if ( $answered > 0 ) {
			/* translators: %d: answers */
			$parts[] = sprintf( _n( '%d answer came in', '%d answers came in', $answered, 'fishotel-misc-plugin' ), $answered );
		}
		if ( $stale > 0 ) {
			/* translators: %d: cases */
			$parts[] = sprintf( _n( '%d with no answer', '%d with no answer', $stale, 'fishotel-misc-plugin' ), $stale );
		}

		return array(
			'key'    => self::KEY,
			'group'  => self::GROUP,
			/* translators: %d: cases */
			'text'   => sprintf( _n( 'Lost fish: %d case needs you', 'Lost fish: %d cases need you', $needs, 'fishotel-misc-plugin' ), $needs ),
			'detail' => implode( ' · ', $parts ),
			'url'    => (string) $url,
			'count'  => $needs,
			'tone'   => $answered > 0 ? 'bad' : 'todo',
		);
	}

	/**
	 * The counts: the stored ones (an autoloaded option — no query).
	 *
	 * @return array
	 */
	private static function counts() {
		return Store::stored_counts();
	}

	/**
	 * Where the line leads: the Cases tab.
	 *
	 * @return string
	 */
	private static function url() {
		return add_query_arg( array( 'page' => Lost_Fish::MENU_SLUG ), admin_url( 'admin.php' ) );
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
		if ( ! function_exists( 'current_user_can' ) || ! current_user_can( Lost_Fish::CAPABILITY ) ) {
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
