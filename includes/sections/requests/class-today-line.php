<?php
/**
 * The Requests line on the Today page and its widget (the Today
 * section's `fishotel_today_lines` filter): "Requests: 3 need you" —
 * the "Needs you" strip in one line: new requests to approve, payments
 * overdue, people to tell, owed fish to send — with a link to the list.
 *
 * Shown only while this section is switched on (boot() hooks it, in
 * wp-admin only), for a user who may open the section, and only when the
 * count is above zero. One query (the requests in the four states the
 * strip looks at), once per request.
 *
 * @package FisHotel\Misc\Sections\Requests
 */

namespace FisHotel\Misc\Sections\Requests;

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
	const KEY    = 'requests';
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
	 * The line (pure): null when nothing needs the owner.
	 *
	 * @param array  $needs approve, overdue, tell, owed — lists or numbers (Listing::needs_you()).
	 * @param string $url   The Requests list.
	 * @return array|null
	 */
	public static function line( array $needs, $url ) {
		$n = array();
		foreach ( array( 'approve', 'overdue', 'tell', 'owed' ) as $kind ) {
			$value      = $needs[ $kind ] ?? 0;
			$n[ $kind ] = is_array( $value ) ? count( $value ) : max( 0, (int) $value );
		}
		$total = array_sum( $n );
		if ( $total <= 0 ) {
			return null;
		}

		$parts = array();
		if ( $n['approve'] > 0 ) {
			/* translators: %d: requests */
			$parts[] = sprintf( _n( '%d to approve', '%d to approve', $n['approve'], 'fishotel-misc-plugin' ), $n['approve'] );
		}
		if ( $n['overdue'] > 0 ) {
			/* translators: %d: payments */
			$parts[] = sprintf( _n( '%d payment overdue', '%d payments overdue', $n['overdue'], 'fishotel-misc-plugin' ), $n['overdue'] );
		}
		if ( $n['tell'] > 0 ) {
			/* translators: %d: people */
			$parts[] = sprintf( _n( '%d person to tell', '%d people to tell', $n['tell'], 'fishotel-misc-plugin' ), $n['tell'] );
		}
		if ( $n['owed'] > 0 ) {
			/* translators: %d: fish */
			$parts[] = sprintf( _n( '%d owed fish to send', '%d owed fish to send', $n['owed'], 'fishotel-misc-plugin' ), $n['owed'] );
		}

		return array(
			'key'    => self::KEY,
			'group'  => self::GROUP,
			/* translators: %d: requests */
			'text'   => sprintf( _n( 'Requests: %d needs you', 'Requests: %d need you', $total, 'fishotel-misc-plugin' ), $total ),
			'detail' => implode( ' · ', $parts ),
			'url'    => (string) $url,
			'count'  => $total,
			'tone'   => $n['overdue'] > 0 ? 'bad' : 'todo',
		);
	}

	/**
	 * The counts: the "Needs you" strip over the open requests in the
	 * four states it looks at (one query).
	 *
	 * @return array
	 */
	private static function counts() {
		$service = Requests::service();
		$open    = $service->store->find( array( 'state' => array( 'new', 'awaiting_payment', 'ready', 'offered' ) ) );
		return Listing::needs_you( is_array( $open ) ? $open : array(), (int) $service->now(), (array) $service->settings );
	}

	/**
	 * Where the line leads: the Requests list (the strip is at its top).
	 *
	 * @return string
	 */
	private static function url() {
		return add_query_arg( array( 'page' => Requests::MENU_SLUG ), admin_url( 'admin.php' ) );
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
		if ( ! function_exists( 'current_user_can' ) || ! current_user_can( Requests::CAPABILITY ) ) {
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
