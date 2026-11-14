<?php
/**
 * The Foam Cuts line on the Today page and its widget (the Today
 * section's `fishotel_today_lines` filter): "Foam: today's cut plan is
 * not marked as cut yet", with a link to that plan (or to the Plan tab
 * when there are several).
 *
 * Shown only while this section is switched on (boot() hooks it, in
 * wp-admin only), for a user who may open the section, and only when a
 * plan for today's ship day is still a draft. One small query, once per
 * request.
 *
 * @package FisHotel\Misc\Sections\Foam_Cuts
 */

namespace FisHotel\Misc\Sections\Foam_Cuts;

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
	const KEY    = 'foam-cuts';
	const GROUP  = 'shipping';

	/**
	 * Where the plans come from (null = counts()); a test hands in its
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
	 * The line (pure): null when no plan for today waits to be cut.
	 *
	 * @param array  $counts plans: the drafts for today (id, title).
	 * @param string $url    The Plan tab (a plan's id is added when there is one).
	 * @return array|null
	 */
	public static function line( array $counts, $url ) {
		$plans = array_values( array_filter( (array) ( $counts['plans'] ?? array() ), 'is_array' ) );
		$count = count( $plans );
		if ( $count <= 0 ) {
			return null;
		}

		$url = (string) $url;
		if ( 1 === $count && '' !== $url && (int) ( $plans[0]['id'] ?? 0 ) > 0 ) {
			$url .= ( false === strpos( $url, '?' ) ? '?' : '&' ) . 'plan=' . (int) $plans[0]['id'];
		}

		return array(
			'key'    => self::KEY,
			'group'  => self::GROUP,
			'text'   => 1 === $count
				? __( 'Foam: today\'s cut plan is not marked as cut yet', 'fishotel-misc-plugin' )
				/* translators: %d: plans */
				: sprintf( _n( 'Foam: %d cut plan for today is not marked as cut yet', 'Foam: %d cut plans for today are not marked as cut yet', $count, 'fishotel-misc-plugin' ), $count ),
			'detail' => 1 === $count ? trim( (string) ( $plans[0]['title'] ?? '' ) ) : '',
			'url'    => $url,
			'count'  => $count,
			'tone'   => 'todo',
		);
	}

	/**
	 * The plans: the drafts for today's ship day (one small query).
	 *
	 * @return array
	 */
	private static function counts() {
		self::load_for_line();
		return array( 'plans' => ( new Store() )->drafts_for_day( Orders::today() ) );
	}

	/**
	 * The two classes counts() reads (no side effects).
	 */
	private static function load_for_line() {
		require_once __DIR__ . '/class-store.php';
		require_once __DIR__ . '/class-orders.php';
	}

	/**
	 * Where the line leads: the Plan tab.
	 *
	 * @return string
	 */
	private static function url() {
		return add_query_arg( array( 'page' => Foam_Cuts::MENU_SLUG ), admin_url( 'admin.php' ) );
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
		if ( ! function_exists( 'current_user_can' ) || ! current_user_can( Foam_Cuts::CAPABILITY ) ) {
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
