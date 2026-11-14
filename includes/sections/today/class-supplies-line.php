<?php
/**
 * ShipTracker's line on the Today page and its widget (this section's
 * own `fishotel_today_lines` filter): "Supplies: 2 to reorder" — the
 * counted supplies at or under their reorder level in ShipTracker →
 * Settings → Boxes & label costs → Supplies — with their names beside it
 * and a link to that list.
 *
 * ShipTracker is another plugin: the line is asked only when its
 * FST_Supplies::get_low_supplies() exists (an older ShipTracker, or none,
 * adds nothing). Hooked by this section's boot(), in wp-admin only, for
 * a user who may open ShipTracker's settings (manage_woocommerce), and
 * shown only when something is low. Worked out once per request.
 *
 * @package FisHotel\Misc\Sections\Today
 */

namespace FisHotel\Misc\Sections\Today;

defined( 'ABSPATH' ) || exit;

/**
 * Class Supplies_Line
 */
class Supplies_Line {

	/**
	 * The Today section's filter, this line's key and its heading there.
	 *
	 * @var string
	 */
	const FILTER = 'fishotel_today_lines';
	const KEY    = 'shiptracker-supplies';
	const GROUP  = 'shipping';

	/**
	 * Where the low supplies come from (null = counts()); a test hands in
	 * its own.
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
	 * ShipTracker's supplies class and the method this line reads.
	 *
	 * @var string
	 */
	const SUPPLIES_CLASS  = 'FST_Supplies';
	const SUPPLIES_METHOD = 'get_low_supplies';

	/**
	 * How many names the line gives before "and N more".
	 *
	 * @var int
	 */
	const NAMED = 4;

	/**
	 * The line (pure): null when nothing is low.
	 *
	 * @param array  $counts low: get_low_supplies() rows (name, recount_needed).
	 * @param string $url    ShipTracker's Supplies list.
	 * @return array|null
	 */
	public static function line( array $counts, $url ) {
		$low   = array_values( array_filter( (array) ( $counts['low'] ?? array() ), 'is_array' ) );
		$count = count( $low );
		if ( $count <= 0 ) {
			return null;
		}

		$names = array();
		foreach ( array_slice( $low, 0, self::NAMED ) as $supply ) {
			$name = trim( (string) ( $supply['name'] ?? '' ) );
			if ( '' !== $name ) {
				$names[] = $name;
			}
		}
		$detail = implode( ', ', $names );
		if ( $count > self::NAMED ) {
			/* translators: %d: supplies */
			$detail .= ' ' . sprintf( __( 'and %d more', 'fishotel-misc-plugin' ), $count - self::NAMED );
		}

		return array(
			'key'    => self::KEY,
			'group'  => self::GROUP,
			/* translators: %d: supplies */
			'text'   => sprintf( _n( 'Supplies: %d to reorder', 'Supplies: %d to reorder', $count, 'fishotel-misc-plugin' ), $count ),
			'detail' => trim( $detail ),
			'url'    => (string) $url,
			'count'  => $count,
			'tone'   => 'todo',
		);
	}

	/**
	 * Whether the ShipTracker build that is active can say what is low.
	 *
	 * @return bool
	 */
	public static function available() {
		return class_exists( self::SUPPLIES_CLASS ) && method_exists( self::SUPPLIES_CLASS, self::SUPPLIES_METHOD );
	}

	/**
	 * The low supplies, from ShipTracker (none when its build cannot say).
	 *
	 * @return array
	 */
	private static function counts() {
		if ( ! self::available() ) {
			return array( 'low' => array() );
		}
		$low = call_user_func( array( self::SUPPLIES_CLASS, self::SUPPLIES_METHOD ) );
		return array( 'low' => is_array( $low ) ? $low : array() );
	}

	/**
	 * Where the line leads: ShipTracker's Supplies list.
	 *
	 * @return string
	 */
	private static function url() {
		return add_query_arg(
			array(
				'page' => 'fst-settings',
				'tab'  => 'boxes',
			),
			admin_url( 'admin.php' )
		) . '#fst-supplies-list';
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
		if ( ! function_exists( 'current_user_can' ) || ! current_user_can( 'manage_woocommerce' ) ) {
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
