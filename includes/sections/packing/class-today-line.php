<?php
/**
 * The Packing line on the Today page and its widget (the Today section's
 * `fishotel_today_lines` filter): "Packing: 3 orders ship today or
 * tomorrow with no box confirmed", the order numbers beside it, and a
 * link to the order (one) or to the orders list (several).
 *
 * An order ships the day before its delivery date (the Batch plugin's
 * `_fishotel_shipping_date`), so "today or tomorrow" is the orders that
 * deliver tomorrow or the day after, in the statuses that still have to
 * be boxed (processing, fulfillment — the same as Foam Cuts). An order
 * folded into a combined box is left out: its wrapper carries the box.
 *
 * Shown only while this section is switched on (boot() hooks it, in
 * wp-admin only), for a user who may open the section, and only when the
 * count is above zero. One query (Store::unconfirmed_orders()), once per
 * request.
 *
 * @package FisHotel\Misc\Sections\Packing
 */

namespace FisHotel\Misc\Sections\Packing;

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
	const KEY    = 'packing';
	const GROUP  = 'shipping';

	/**
	 * Where the orders come from (null = counts()); a test hands in its
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
	 * The statuses of an order that still has to be boxed.
	 *
	 * @var string[]
	 */
	const STATUSES = array( 'processing', 'fulfillment' );

	/**
	 * How many order numbers the line names before "and N more".
	 *
	 * @var int
	 */
	const NAMED = 5;

	/**
	 * The line (pure): null when every order has its box.
	 *
	 * @param array  $counts orders: ids without a confirmed box; edit: the edit link of the first one ('' = none).
	 * @param string $url    The orders list.
	 * @return array|null
	 */
	public static function line( array $counts, $url ) {
		$ids   = array_values( array_filter( array_map( 'intval', (array) ( $counts['orders'] ?? array() ) ) ) );
		$count = count( $ids );
		if ( $count <= 0 ) {
			return null;
		}

		$named  = array_slice( $ids, 0, self::NAMED );
		$detail = '#' . implode( ', #', $named );
		if ( $count > count( $named ) ) {
			/* translators: %d: orders */
			$detail .= ' ' . sprintf( __( 'and %d more', 'fishotel-misc-plugin' ), $count - count( $named ) );
		}
		$edit = (string) ( $counts['edit'] ?? '' );

		return array(
			'key'    => self::KEY,
			'group'  => self::GROUP,
			/* translators: %d: orders */
			'text'   => sprintf( _n( 'Packing: %d order ships today or tomorrow with no box confirmed', 'Packing: %d orders ship today or tomorrow with no box confirmed', $count, 'fishotel-misc-plugin' ), $count ),
			'detail' => $detail,
			'url'    => 1 === $count && '' !== $edit ? $edit : (string) $url,
			'count'  => $count,
			'tone'   => 'todo',
		);
	}

	/**
	 * The two delivery dates that ship today and tomorrow (pure).
	 *
	 * @param string $today Y-m-d.
	 * @return string[]
	 */
	public static function delivery_dates( $today ) {
		$day = \DateTimeImmutable::createFromFormat( '!Y-m-d', (string) $today, new \DateTimeZone( 'UTC' ) );
		if ( ! $day ) {
			return array();
		}
		return array( $day->modify( '+1 day' )->format( 'Y-m-d' ), $day->modify( '+2 days' )->format( 'Y-m-d' ) );
	}

	/**
	 * Today's date in the site zone (the Batch plugin's delivery rules
	 * when present, else current_time()).
	 *
	 * @return string Y-m-d
	 */
	public static function today() {
		if ( function_exists( 'fishotel_delivery_rules' ) ) {
			try {
				$rules = fishotel_delivery_rules();
				$today = is_object( $rules ) && method_exists( $rules, 'today' ) ? $rules->today() : null;
				if ( $today instanceof \DateTimeInterface ) {
					return $today->format( 'Y-m-d' );
				}
				if ( is_string( $today ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $today ) ) {
					return $today;
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}
		}
		return (string) current_time( 'Y-m-d' );
	}

	/**
	 * True when WooCommerce's own order tables (HPOS) are in use.
	 *
	 * @return bool
	 */
	public static function hpos() {
		$util = '\Automattic\WooCommerce\Utilities\OrderUtil';
		return class_exists( $util )
			&& is_callable( array( $util, 'custom_orders_table_usage_is_enabled' ) )
			&& (bool) call_user_func( array( $util, 'custom_orders_table_usage_is_enabled' ) );
	}

	/**
	 * The orders: those shipping today or tomorrow with no confirmed box
	 * (one query), and the edit link of the first.
	 *
	 * @return array
	 */
	private static function counts() {
		$hpos = self::hpos();
		$ids  = ( new Store() )->unconfirmed_orders( self::delivery_dates( self::today() ), self::STATUSES, $hpos );

		return array(
			'orders' => $ids,
			'edit'   => $ids ? ( $hpos ? add_query_arg( array( 'page' => 'wc-orders', 'action' => 'edit', 'id' => $ids[0] ), admin_url( 'admin.php' ) ) : add_query_arg( array( 'post' => $ids[0], 'action' => 'edit' ), admin_url( 'post.php' ) ) ) : '',
		);
	}

	/**
	 * Where the line leads with several orders: the orders list.
	 *
	 * @return string
	 */
	private static function url() {
		return self::hpos()
			? add_query_arg( array( 'page' => 'wc-orders', 'status' => 'wc-processing' ), admin_url( 'admin.php' ) )
			: add_query_arg( array( 'post_type' => 'shop_order', 'post_status' => 'wc-processing' ), admin_url( 'edit.php' ) );
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
		if ( ! function_exists( 'current_user_can' ) || ! current_user_can( Packing::CAPABILITY ) ) {
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
