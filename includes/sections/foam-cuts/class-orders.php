<?php
/**
 * Orders — "today's orders" as a number of each box.
 *
 * The orders that ship on a day are the open orders whose delivery date
 * (the Batch plugin's `_fishotel_shipping_date` order meta, Y-m-d) is the
 * day after — the same rule the Today section uses. They are read through
 * wc_get_orders() and $order->get_meta(), so it makes no difference
 * whether HPOS is on (the meta filter is handed to the query only on
 * HPOS, and every order is checked again in PHP either way). An order
 * folded into a combined box is skipped: its wrapper order carries the box.
 *
 * Which box an order goes in is the Packing section's knowledge:
 * fishotel_packing_confirmed_box( $order ) when a box was confirmed, else
 * fishotel_packing_suggest( $order ). Their answers are read loosely — a
 * box name, a box row, { box: … }, { boxes: [ … ] } or a list of those,
 * with an optional count / qty — and can be supplied or corrected through
 * the filter fishotel_foam_cuts_order_boxes ( rows, order ). Without the
 * Packing section (and without that filter) this class says so and the
 * owner types the numbers instead.
 *
 * Nothing is written to an order here, ever.
 *
 * @package FisHotel\Misc\Sections\Foam_Cuts
 */

namespace FisHotel\Misc\Sections\Foam_Cuts;

defined( 'ABSPATH' ) || exit;

/**
 * Class Orders
 */
class Orders {

	/**
	 * The order meta holding the delivery date (Y-m-d).
	 *
	 * @var string
	 */
	const META_DELIVERY_DATE = '_fishotel_shipping_date';

	/**
	 * The order meta of an order folded into a combined box (the wrapper's id).
	 *
	 * @var string
	 */
	const META_FULFILLED_BY = '_fishotel_fulfilled_by_order';

	/**
	 * The statuses of an order that still has to be boxed (filter:
	 * fishotel_foam_cuts_order_statuses).
	 *
	 * @var string[]
	 */
	const STATUSES = array( 'processing', 'fulfillment' );

	/**
	 * The filter that supplies or corrects the boxes of one order.
	 *
	 * @var string
	 */
	const FILTER = 'fishotel_foam_cuts_order_boxes';

	/**
	 * Why "today's orders" cannot be used right now ('' = it can).
	 *
	 * @return string
	 */
	public static function unavailable_reason() {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return __( 'WooCommerce is not active, so there are no orders to read.', 'fishotel-misc-plugin' );
		}

		if ( ! self::can_ask_packing() && ! ( function_exists( 'has_filter' ) && has_filter( self::FILTER ) ) ) {
			return __( 'The Packing section is not switched on, and it is the part that knows which box an order goes in. Type the number of each box instead.', 'fishotel-misc-plugin' );
		}

		return '';
	}

	/**
	 * Whether the Packing section can be asked for an order's box.
	 *
	 * @return bool
	 */
	public static function can_ask_packing() {
		return Boxes::packing_on() && ( function_exists( 'fishotel_packing_confirmed_box' ) || function_exists( 'fishotel_packing_suggest' ) );
	}

	/**
	 * Today's calendar date in the site zone (the Batch plugin's delivery
	 * rules when present, else current_time()).
	 *
	 * @return string Y-m-d
	 */
	public static function today() {
		if ( function_exists( 'fishotel_delivery_rules' ) ) {
			try {
				$rules = fishotel_delivery_rules();
				if ( is_object( $rules ) && method_exists( $rules, 'today' ) ) {
					$today = $rules->today();
					if ( $today instanceof \DateTimeInterface ) {
						return $today->format( 'Y-m-d' );
					}
					if ( is_string( $today ) && self::is_date( $today ) ) {
						return $today;
					}
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}
		}

		return (string) current_time( 'Y-m-d' );
	}

	/**
	 * Whether a string is a real Y-m-d date.
	 *
	 * @param mixed $ymd Date.
	 * @return bool
	 */
	public static function is_date( $ymd ) {
		if ( ! is_string( $ymd ) || ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $ymd, $m ) ) {
			return false;
		}
		return checkdate( (int) $m[2], (int) $m[3], (int) $m[1] );
	}

	/**
	 * The delivery date of what ships on a day: the day after.
	 *
	 * @param string $ship_ymd Y-m-d.
	 * @return string Y-m-d ('' when the date is not one).
	 */
	public static function delivery_for( $ship_ymd ) {
		if ( ! self::is_date( $ship_ymd ) ) {
			return '';
		}

		$date = \DateTimeImmutable::createFromFormat( '!Y-m-d', $ship_ymd, new \DateTimeZone( 'UTC' ) );

		return $date ? $date->modify( '+1 day' )->format( 'Y-m-d' ) : '';
	}

	/**
	 * The statuses that count as "still to be boxed".
	 *
	 * @return string[]
	 */
	public static function statuses() {
		$statuses = self::STATUSES;
		if ( function_exists( 'apply_filters' ) ) {
			$statuses = apply_filters( 'fishotel_foam_cuts_order_statuses', $statuses );
		}

		$out = array();
		foreach ( (array) $statuses as $status ) {
			$status = is_scalar( $status ) ? preg_replace( '/^wc-/', '', sanitize_key( (string) $status ) ) : '';
			if ( '' !== $status && ! in_array( $status, $out, true ) ) {
				$out[] = $status;
			}
		}
		return $out;
	}

	/**
	 * True when WooCommerce's HPOS orders table is the active order store.
	 *
	 * @return bool
	 */
	public static function hpos_enabled() {
		$util = '\Automattic\WooCommerce\Utilities\OrderUtil';

		return class_exists( $util )
			&& is_callable( array( $util, 'custom_orders_table_usage_is_enabled' ) )
			&& (bool) call_user_func( array( $util, 'custom_orders_table_usage_is_enabled' ) );
	}

	/**
	 * The open orders delivering on a date, by id, folded sources left out.
	 *
	 * @param string $delivery_ymd Y-m-d.
	 * @return object[] WC_Order objects.
	 */
	public static function delivering_on( $delivery_ymd ) {
		$statuses = self::statuses();
		if ( ! function_exists( 'wc_get_orders' ) || ! $statuses || ! self::is_date( $delivery_ymd ) ) {
			return array();
		}

		$args = array(
			'type'    => 'shop_order',
			'status'  => $statuses,
			'limit'   => -1,
			'orderby' => 'ID',
			'order'   => 'ASC',
			'return'  => 'objects',
		);

		if ( self::hpos_enabled() ) {
			$args['meta_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- HPOS honours it; the CPT store is filtered in PHP below.
				array(
					'key'   => self::META_DELIVERY_DATE,
					'value' => $delivery_ymd,
				),
			);
		}

		try {
			$found = wc_get_orders( $args );
		} catch ( \Throwable $e ) {
			return array();
		}

		$out = array();
		foreach ( (array) $found as $order ) {
			if ( ! is_object( $order ) || ! method_exists( $order, 'get_meta' ) || ! method_exists( $order, 'get_id' ) ) {
				continue;
			}
			if ( trim( (string) $order->get_meta( self::META_DELIVERY_DATE ) ) !== $delivery_ymd ) {
				continue;
			}
			if ( '' !== trim( (string) $order->get_meta( self::META_FULFILLED_BY ) ) ) {
				continue;
			}
			$out[] = $order;
		}

		return $out;
	}

	/**
	 * The orders that ship on a day and the boxes they come to.
	 *
	 * @param string $ship_ymd Y-m-d (the day the boxes are packed).
	 * @param array  $boxes    The box list (Boxes::all()['boxes']).
	 * @return array{
	 *   ok: bool, message: string, ship_date: string, delivery_date: string,
	 *   orders: array<int, array{id: int, number: string, how: string, boxes: array[], unknown: string[]}>,
	 *   counts: array<string, int>, extra: array[], without_box: int[], unknown: string[]
	 * }
	 *         counts is box key → how many; extra lists the boxes that are
	 *         not on the list (known only by their size) with their count.
	 */
	public static function for_ship_date( $ship_ymd, array $boxes ) {
		$out = array(
			'ok'            => false,
			'message'       => '',
			'ship_date'     => (string) $ship_ymd,
			'delivery_date' => self::delivery_for( $ship_ymd ),
			'orders'        => array(),
			'counts'        => array(),
			'extra'         => array(),
			'without_box'   => array(),
			'unknown'       => array(),
		);

		$reason = self::unavailable_reason();
		if ( '' !== $reason ) {
			$out['message'] = $reason;
			return $out;
		}
		if ( '' === $out['delivery_date'] ) {
			$out['message'] = __( 'That is not a date.', 'fishotel-misc-plugin' );
			return $out;
		}

		$out['ok'] = true;

		foreach ( self::delivering_on( $out['delivery_date'] ) as $order ) {
			$answer = self::order_boxes( $order, $boxes );
			$id     = (int) $order->get_id();

			$out['orders'][] = array(
				'id'      => $id,
				'number'  => method_exists( $order, 'get_order_number' ) ? (string) $order->get_order_number() : (string) $id,
				'how'     => $answer['how'],
				'boxes'   => $answer['boxes'],
				'unknown' => $answer['unknown'],
			);

			if ( ! $answer['boxes'] ) {
				$out['without_box'][] = $id;
			}
			foreach ( $answer['unknown'] as $name ) {
				if ( ! in_array( $name, $out['unknown'], true ) ) {
					$out['unknown'][] = $name;
				}
			}

			foreach ( $answer['boxes'] as $box ) {
				if ( ! empty( $box['listed'] ) ) {
					$out['counts'][ $box['key'] ] = ( isset( $out['counts'][ $box['key'] ] ) ? $out['counts'][ $box['key'] ] : 0 ) + $box['count'];
					continue;
				}
				if ( ! isset( $out['extra'][ $box['key'] ] ) ) {
					$out['extra'][ $box['key'] ] = array_merge( $box, array( 'count' => 0 ) );
				}
				$out['extra'][ $box['key'] ]['count'] += $box['count'];
			}
		}

		$out['extra'] = array_values( $out['extra'] );

		return $out;
	}

	/**
	 * The box (or boxes) of one order: the confirmed one, else the
	 * suggested one, then the filter's say.
	 *
	 * @param object $order WC_Order.
	 * @param array  $boxes The box list.
	 * @return array{how: string, boxes: array[], unknown: string[]}
	 *         how is 'confirmed', 'suggested', 'filter' or '' (nothing known).
	 */
	public static function order_boxes( $order, array $boxes ) {
		$how     = '';
		$found   = array(
			'boxes'   => array(),
			'unknown' => array(),
		);

		if ( self::can_ask_packing() ) {
			foreach ( array( 'confirmed' => 'fishotel_packing_confirmed_box', 'suggested' => 'fishotel_packing_suggest' ) as $label => $function ) {
				$answer = self::read_answer( self::ask( $function, $order ), $boxes );
				if ( $answer['boxes'] || $answer['unknown'] ) {
					$how   = $label;
					$found = $answer;
					break;
				}
			}
		}

		if ( function_exists( 'has_filter' ) && has_filter( self::FILTER ) ) {
			$rows = array();
			foreach ( $found['boxes'] as $box ) {
				$rows[] = array(
					'name'   => $box['name'],
					'length' => Units::to_inches( $box['length'] ),
					'width'  => Units::to_inches( $box['width'] ),
					'height' => Units::to_inches( $box['height'] ),
					'count'  => $box['count'],
				);
			}

			try {
				$filtered = apply_filters( self::FILTER, $rows, $order );
			} catch ( \Throwable $e ) {
				$filtered = $rows;
			}

			if ( $filtered !== $rows ) {
				$found = self::read_answer( $filtered, $boxes );
				$how   = $found['boxes'] || $found['unknown'] ? 'filter' : '';
			}
		}

		return array(
			'how'     => $how,
			'boxes'   => $found['boxes'],
			'unknown' => $found['unknown'],
		);
	}

	/**
	 * Call one of the Packing section's functions for an order: with the
	 * order object, and when that is refused, with its id.
	 *
	 * @param string $function Function name.
	 * @param object $order    WC_Order.
	 * @return mixed Null when the function is absent or throws both ways.
	 */
	private static function ask( $function, $order ) {
		if ( ! function_exists( $function ) ) {
			return null;
		}

		try {
			return $function( $order );
		} catch ( \Throwable $e ) {
			try {
				return $function( (int) $order->get_id() );
			} catch ( \Throwable $e2 ) {
				return null;
			}
		}
	}

	/**
	 * Read an answer about an order's boxes into box rows with a count.
	 *
	 * Understood: a box name ("Small"), a size ("16x12x10"), a box row
	 * ({ name, length, width, height } or { dims }), { box: … },
	 * { boxes: [ … ] }, and a list of any of those; count / qty / quantity
	 * beside a box says how many (1 when absent). A box on the list is
	 * matched by its name, else by its size; a box that is only a size is
	 * kept as an unlisted box; a name nobody knows is reported.
	 *
	 * @param mixed $answer The answer.
	 * @param array $boxes  The box list.
	 * @return array{boxes: array[], unknown: string[]}
	 *         Each box: key, name, length, width, height (units), count, listed.
	 */
	public static function read_answer( $answer, array $boxes ) {
		$out = array(
			'boxes'   => array(),
			'unknown' => array(),
		);

		foreach ( self::flatten( $answer, 0 ) as $entry ) {
			$count = max( 1, min( Plan::MAX_BOX_COUNT, (int) $entry['count'] ) );
			$box   = null;

			if ( '' !== $entry['name'] ) {
				$box = Boxes::find( $boxes, $entry['name'] );
			}
			if ( null === $box && null !== $entry['sizes'] ) {
				$box = Boxes::by_size( $boxes, $entry['sizes'][0], $entry['sizes'][1], $entry['sizes'][2] );
			}

			if ( null !== $box ) {
				$row = array_merge(
					array_intersect_key( $box, array_flip( array( 'key', 'name', 'length', 'width', 'height' ) ) ),
					array(
						'count'  => $count,
						'listed' => true,
					)
				);
			} elseif ( null !== $entry['sizes'] ) {
				$name = '' !== $entry['name'] ? Boxes::clean_name( $entry['name'] ) : Units::format( $entry['sizes'][0] ) . ' × ' . Units::format( $entry['sizes'][1] ) . ' × ' . Units::format( $entry['sizes'][2] );
				$row  = array(
					'key'    => Boxes::key( $name ),
					'name'   => $name,
					'length' => $entry['sizes'][0],
					'width'  => $entry['sizes'][1],
					'height' => $entry['sizes'][2],
					'count'  => $count,
					'listed' => false,
				);
			} else {
				if ( '' !== $entry['name'] && ! in_array( $entry['name'], $out['unknown'], true ) ) {
					$out['unknown'][] = Boxes::clean_name( $entry['name'] );
				}
				continue;
			}

			$merged = false;
			foreach ( $out['boxes'] as $i => $have ) {
				if ( $have['key'] === $row['key'] && $have['listed'] === $row['listed'] ) {
					$out['boxes'][ $i ]['count'] += $row['count'];
					$merged                       = true;
				}
			}
			if ( ! $merged ) {
				$out['boxes'][] = $row;
			}
		}

		return $out;
	}

	/**
	 * An answer of any of the understood shapes as a flat list of
	 * { name, sizes (units, or null), count }.
	 *
	 * @param mixed $answer The answer.
	 * @param int   $depth  Nesting so far (an answer nested deeper than a few levels is not followed).
	 * @return array<int, array{name: string, sizes: int[]|null, count: int}>
	 */
	private static function flatten( $answer, $depth ) {
		if ( $depth > 4 || null === $answer || false === $answer || '' === $answer || array() === $answer ) {
			return array();
		}

		if ( is_object( $answer ) ) {
			$answer = get_object_vars( $answer );
		}

		if ( is_string( $answer ) || is_int( $answer ) || is_float( $answer ) ) {
			$text  = trim( (string) $answer );
			$sizes = Boxes::parse_dims( $text );
			return '' === $text ? array() : array(
				array(
					'name'  => null === $sizes ? $text : '',
					'sizes' => $sizes,
					'count' => 1,
				),
			);
		}

		if ( ! is_array( $answer ) ) {
			return array();
		}

		$count = 1;
		foreach ( array( 'count', 'qty', 'quantity' ) as $field ) {
			if ( isset( $answer[ $field ] ) && is_numeric( $answer[ $field ] ) ) {
				$count = (int) $answer[ $field ];
				break;
			}
		}

		// { boxes: [ … ] } or { box: … }: follow it.
		foreach ( array( 'boxes', 'box' ) as $field ) {
			if ( isset( $answer[ $field ] ) && ( is_array( $answer[ $field ] ) || is_object( $answer[ $field ] ) || is_string( $answer[ $field ] ) ) ) {
				$inner = self::flatten( $answer[ $field ], $depth + 1 );
				if ( 'box' === $field && 1 !== $count ) {
					foreach ( $inner as $i => $entry ) {
						$inner[ $i ]['count'] = $entry['count'] * $count;
					}
				}
				return $inner;
			}
		}

		// A box row.
		$name = '';
		foreach ( array( 'name', 'label', 'title' ) as $field ) {
			if ( isset( $answer[ $field ] ) && is_scalar( $answer[ $field ] ) && '' !== trim( (string) $answer[ $field ] ) ) {
				$name = trim( (string) $answer[ $field ] );
				break;
			}
		}
		$sizes = Boxes::read_sizes( $answer );

		if ( '' !== $name || null !== $sizes ) {
			return array(
				array(
					'name'  => $name,
					'sizes' => $sizes,
					'count' => $count,
				),
			);
		}

		// A list of answers.
		$out = array();
		foreach ( $answer as $key => $entry ) {
			if ( is_int( $key ) ) {
				$out = array_merge( $out, self::flatten( $entry, $depth + 1 ) );
			}
		}
		return $out;
	}
}
