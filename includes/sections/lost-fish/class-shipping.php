<?php
/**
 * Lost Fish shipping — the shipping difference: what the order's shipping
 * costs MORE once the lost fish is out of it, because the order dropped
 * under the reduced-shipping threshold.
 *
 * No rate and no threshold is written here. The answer comes from
 * WooCommerce's own shipping settings for the order's destination:
 *
 *   1. The order's lines are turned back into the shipping package the
 *      checkout priced: its products, quantities and what was paid for
 *      them (the fish of an itemised invoice go back to being that one
 *      invoice product, with its shipping class), going to the order's
 *      shipping address.
 *   2. The zone that matches the package is asked for the shipping method
 *      the order used (its method id AND instance id), and that method
 *      prices the package AS IT WAS PLACED. Only when that gives what the
 *      order was really charged, to the cent, is the method trusted with
 *      the next question — otherwise the settings changed since, or the
 *      checkout priced it from something this cannot see.
 *   3. The same method prices the package WITHOUT the lost fish. The
 *      difference to what was charged (plus what earlier answered cases
 *      of the same order already withheld) is the shipping difference,
 *      with the order's own shipping tax rate on top.
 *
 * The answer is one of:
 *   none     worked out: no difference (the order stays over the
 *            threshold, or nothing else is left to ship);
 *   drop     worked out: the difference, in cents;
 *   free     the order shipped free ON PURPOSE — every line that ships is
 *            in a "free" shipping class (an invoice given free shipping),
 *            or it rides in another order's box — so there is none;
 *   unknown  it cannot be worked out with confidence, and the note says
 *            why. Nothing is guessed: the owner fills the difference in,
 *            or confirms zero, before the case can be sent. Another case
 *            still waiting for an answer on the same order is one such
 *            reason — the difference depends on what the customer picks
 *            there (`alone` then carries the figure for this fish alone).
 *
 * While the method is asked, WooCommerce's session is a throw-away
 * in-memory one and its cart is hidden, so a method can only price the
 * package it is handed — never the cart of whoever happens to be logged
 * in — and whatever it would have put into a session is dropped.
 *
 * `rater` (in $opts, or the static $rater) replaces step 2's method with a
 * callable — that is how the tests price packages with invented rates.
 *
 * @package FisHotel\Misc\Sections\Lost_Fish
 */

namespace FisHotel\Misc\Sections\Lost_Fish;

defined( 'ABSPATH' ) || exit;

/**
 * Class Shipping
 */
class Shipping {

	const NONE    = 'none';
	const DROP    = 'drop';
	const FREE    = 'free';
	const UNKNOWN = 'unknown';

	/**
	 * A stand-in for the shipping method: callable( $order, array $rows )
	 * returning cents, null, or array{cents: int|null, error: string}.
	 *
	 * @var callable|null
	 */
	public static $rater = null;

	/**
	 * The shipping difference of taking fish out of an order.
	 *
	 * @param object $order  The WooCommerce order.
	 * @param array  $remove The fish to take out now: a list of array{ref: string, qty: int, unit_paid: int}.
	 * @param array  $ledger Earlier cases of the same order: removed (the same list, for answered refunds /
	 *                       credits), withheld (cents, tax included, they already withheld), pending (how many
	 *                       other cases still wait for an answer).
	 * @param array  $opts   free_classes (string[]), rater (callable).
	 * @return array{status: string, charged: int, charged_tax: int, reduced: int|null, diff: int|null,
	 *               alone: int|null, goods: int|null, goods_after: int|null, note: string}
	 */
	public static function difference( $order, array $remove, array $ledger = array(), array $opts = array() ) {
		$charged = Money::cents( method_exists( $order, 'get_shipping_total' ) ? $order->get_shipping_total() : 0 );
		$tax     = Money::cents( method_exists( $order, 'get_shipping_tax' ) ? $order->get_shipping_tax() : 0 );

		$out = array(
			'status'      => self::UNKNOWN,
			'charged'     => $charged,
			'charged_tax' => $tax,
			'reduced'     => null,
			'diff'        => null,
			'alone'       => null,
			'goods'       => null,
			'goods_after' => null,
			'note'        => '',
		);

		$pending = max( 0, (int) ( $ledger['pending'] ?? 0 ) );

		// Rides in another order's box: it never paid shipping of its own.
		$piggyback = (int) Orders::meta_string( $order, Orders::META_PIGGYBACK );
		if ( $piggyback > 0 && 0 === $charged ) {
			/* translators: %d: order id */
			return self::settle( $out, self::FREE, 0, sprintf( __( 'This order rides in the box of order #%d and paid no shipping of its own.', 'fishotel-misc-plugin' ), $piggyback ), 0 );
		}

		$model = self::model( $order );
		if ( '' !== $model['error'] ) {
			$out['note'] = $model['error'];
			return $out;
		}
		$rows         = $model['rows'];
		$out['goods'] = self::goods( $rows );

		// Free on purpose: nothing was charged and every line that ships is in a free class.
		$free_classes = array_map( 'strval', (array) ( $opts['free_classes'] ?? Settings::DEFAULTS['free_classes'] ) );
		$ships        = self::shipping_rows( $rows );
		if ( 0 === $charged && $ships && $free_classes ) {
			$all_free = true;
			foreach ( $ships as $row ) {
				if ( ! in_array( (string) $row['class'], $free_classes, true ) ) {
					$all_free = false;
					break;
				}
			}
			if ( $all_free ) {
				return self::settle( $out, self::FREE, 0, __( 'This order was given free shipping on purpose (its shipping class), so there is no shipping difference.', 'fishotel-misc-plugin' ), 0 );
			}
		}

		$before = self::without( $rows, (array) ( $ledger['removed'] ?? array() ) );
		$after  = null !== $before ? self::without( $before, $remove ) : null;
		if ( null === $after ) {
			$out['note'] = __( 'This fish is not on an order line whose shipping can be worked out.', 'fishotel-misc-plugin' );
			return $out;
		}
		$out['goods_after'] = self::goods( $after );

		if ( ! self::shipping_rows( $after ) ) {
			return self::settle( $out, self::NONE, 0, __( 'Nothing else is left to ship on this order.', 'fishotel-misc-plugin' ), $pending );
		}

		$placed = self::rate( $order, $rows, $opts );
		if ( null === $placed['cents'] ) {
			$out['note'] = $placed['error'];
			return $out;
		}
		if ( abs( $placed['cents'] - $charged ) > 1 ) {
			$out['note'] = sprintf(
				/* translators: 1: what the settings give, 2: what was charged */
				__( 'The shipping settings give %1$s for this order as it was placed, but %2$s was charged — the shipping difference cannot be worked out from them.', 'fishotel-misc-plugin' ),
				Money::format( $placed['cents'] ),
				Money::format( $charged )
			);
			return $out;
		}

		$reduced = self::rate( $order, $after, $opts );
		if ( null === $reduced['cents'] ) {
			$out['note'] = $reduced['error'];
			return $out;
		}

		// What earlier answered cases already withheld, before tax.
		$withheld = max( 0, (int) ( $ledger['withheld'] ?? 0 ) );
		if ( $withheld > 0 && $tax > 0 && $charged > 0 ) {
			$withheld = (int) round( $withheld * $charged / ( $charged + $tax ) );
		}

		$diff_ex        = max( 0, $reduced['cents'] - $charged - $withheld );
		$diff           = Money::with_shipping_tax( $diff_ex, $charged, $tax );
		$out['reduced'] = $reduced['cents'];

		if ( $diff > 0 ) {
			$note = sprintf(
				/* translators: 1: shipping without the fish, 2: shipping charged */
				__( 'Without this fish the order\'s shipping is %1$s instead of the %2$s that was charged.', 'fishotel-misc-plugin' ),
				Money::format( $reduced['cents'] ),
				Money::format( $charged )
			);
			return self::settle( $out, self::DROP, $diff, $note, $pending );
		}

		return self::settle( $out, self::NONE, 0, __( 'The order\'s shipping stays the same without this fish.', 'fishotel-misc-plugin' ), $pending );
	}

	/**
	 * Finish an answer — unless another case of the same order still
	 * waits for the customer: then the figure is only what this fish
	 * would cost alone, and the owner decides.
	 *
	 * @param array  $out     The answer so far.
	 * @param string $status  NONE | DROP | FREE.
	 * @param int    $diff    The difference (cents).
	 * @param string $note    Why.
	 * @param int    $pending Other cases of the order still waiting.
	 * @return array
	 */
	private static function settle( array $out, $status, $diff, $note, $pending ) {
		if ( $pending > 0 ) {
			$out['status'] = self::UNKNOWN;
			$out['alone']  = (int) $diff;
			$out['note']   = sprintf(
				/* translators: 1: number of other cases, 2: the figure for this fish alone */
				_n(
					'%1$d other lost-fish case on this order still waits for an answer: the shipping difference depends on what the customer picks there. For this fish alone it would be %2$s.',
					'%1$d other lost-fish cases on this order still wait for an answer: the shipping difference depends on what the customer picks there. For this fish alone it would be %2$s.',
					$pending,
					'fishotel-misc-plugin'
				),
				$pending,
				Money::format( (int) $diff )
			);
			return $out;
		}

		$out['status'] = $status;
		$out['diff']   = (int) $diff;
		$out['note']   = (string) $note;

		return $out;
	}

	/* ============================================================== */
	/*  The order as a shipping package                                */
	/* ============================================================== */

	/**
	 * The order's lines as the rows the checkout priced. An ordinary line
	 * is one row. The fish lines of an itemised invoice (and its credit
	 * fees) are ONE row again — the invoice product, quantity 1, at the
	 * invoice's total — because that is what was in the cart.
	 *
	 * @param object $order Order.
	 * @return array{rows: array<string, array>, error: string}
	 */
	public static function model( $order ) {
		$rows = array();

		try {
			$items = (array) $order->get_items( 'line_item' );
		} catch ( \Throwable $e ) {
			$items = array();
		}

		foreach ( $items as $key => $item ) {
			if ( ! is_object( $item ) || ! method_exists( $item, 'get_quantity' ) ) {
				continue;
			}
			$item_id  = method_exists( $item, 'get_id' ) ? (int) $item->get_id() : (int) $key;
			$qty      = (int) $item->get_quantity();
			$subtotal = Money::cents( method_exists( $item, 'get_subtotal' ) ? $item->get_subtotal() : 0 );
			$total    = Money::cents( method_exists( $item, 'get_total' ) ? $item->get_total() : 0 );
			if ( $qty <= 0 ) {
				continue;
			}

			$invoice_id = (int) Orders::meta_string( $item, Orders::META_INV_PRODUCT );
			if ( $invoice_id > 0 && Orders::truthy( $item->get_meta( Orders::META_INV_FISH ) ) ) {
				$row_key = 'inv:' . $invoice_id;
				if ( ! isset( $rows[ $row_key ] ) ) {
					$product = function_exists( 'wc_get_product' ) ? wc_get_product( $invoice_id ) : null;
					if ( ! is_object( $product ) ) {
						return array(
							'rows'  => array(),
							'error' => __( 'The invoice this order paid no longer exists as a product, so its shipping cannot be worked out.', 'fishotel-misc-plugin' ),
						);
					}
					$rows[ $row_key ] = self::row( $row_key, $product, 1, 0, 0, true );
				}
				$rows[ $row_key ]['subtotal']         += $subtotal;
				$rows[ $row_key ]['total']            += $total;
				$rows[ $row_key ]['items'][ $item_id ] = array(
					'qty'           => $qty,
					'unit_subtotal' => (int) round( $subtotal / $qty ),
					'unit_total'    => (int) round( $total / $qty ),
				);
				continue;
			}

			$product = method_exists( $item, 'get_product' ) ? $item->get_product() : null;
			if ( ! is_object( $product ) ) {
				return array(
					'rows'  => array(),
					'error' => sprintf(
						/* translators: %s: line name */
						__( 'The product of the line "%s" no longer exists, so the order\'s shipping cannot be worked out.', 'fishotel-misc-plugin' ),
						method_exists( $item, 'get_name' ) ? (string) $item->get_name() : '#' . $item_id
					),
				);
			}

			$row_key                              = 'i:' . $item_id;
			$rows[ $row_key ]                     = self::row( $row_key, $product, $qty, $subtotal, $total, false );
			$rows[ $row_key ]['items'][ $item_id ] = array(
				'qty'           => $qty,
				'unit_subtotal' => (int) round( $subtotal / $qty ),
				'unit_total'    => (int) round( $total / $qty ),
			);
		}

		// An invoice's credits were part of its price.
		try {
			$fees = (array) $order->get_items( 'fee' );
		} catch ( \Throwable $e ) {
			$fees = array();
		}
		foreach ( $fees as $fee ) {
			if ( ! is_object( $fee ) || ! method_exists( $fee, 'get_meta' ) || ! Orders::truthy( $fee->get_meta( Orders::META_INV_CREDIT ) ) ) {
				continue;
			}
			$row_key = 'inv:' . (int) Orders::meta_string( $fee, Orders::META_INV_PRODUCT );
			if ( isset( $rows[ $row_key ] ) && method_exists( $fee, 'get_total' ) ) {
				$amount                        = Money::cents( $fee->get_total() );
				$rows[ $row_key ]['subtotal'] += $amount;
				$rows[ $row_key ]['total']    += $amount;
			}
		}

		return array(
			'rows'  => $rows,
			'error' => '',
		);
	}

	/**
	 * One row of the model.
	 *
	 * @param string $key      Row key.
	 * @param object $product  The product.
	 * @param int    $qty      Quantity.
	 * @param int    $subtotal Before discounts (cents).
	 * @param int    $total    After discounts (cents).
	 * @param bool   $group    Whether the row is an invoice put back together.
	 * @return array
	 */
	private static function row( $key, $product, $qty, $subtotal, $total, $group ) {
		$ships = true;
		$class = '';
		try {
			$ships = method_exists( $product, 'needs_shipping' ) ? (bool) $product->needs_shipping() : true;
			$class = method_exists( $product, 'get_shipping_class' ) ? (string) $product->get_shipping_class() : '';
		} catch ( \Throwable $e ) {
			unset( $e );
		}

		return array(
			'key'      => (string) $key,
			'product'  => $product,
			'qty'      => (int) $qty,
			'subtotal' => (int) $subtotal,
			'total'    => (int) $total,
			'class'    => $class,
			'ships'    => $ships,
			'group'    => (bool) $group,
			'items'    => array(),
		);
	}

	/**
	 * The rows without some fish. A ref "i:<item>" takes the quantity off
	 * that order line (off the invoice's price, for an itemised invoice);
	 * "i:<item>:<n>" is a fish inside an invoice kept as one line and
	 * takes its price off that line. A row left with nothing is gone.
	 *
	 * @param array $rows   model() rows.
	 * @param array $remove A list of array{ref: string, qty: int, unit_paid: int}.
	 * @return array|null Null when a ref is not a line of the model.
	 */
	public static function without( array $rows, array $remove ) {
		foreach ( $remove as $entry ) {
			if ( ! is_array( $entry ) || 1 !== preg_match( '/^i:(\d+)(?::(\d+))?$/', (string) ( $entry['ref'] ?? '' ), $m ) ) {
				return null;
			}
			$item_id = (int) $m[1];
			$qty     = max( 0, (int) ( $entry['qty'] ?? 0 ) );
			$found   = false;

			foreach ( $rows as $key => $row ) {
				if ( ! isset( $row['items'][ $item_id ] ) ) {
					continue;
				}
				$found = true;
				$item  = $row['items'][ $item_id ];

				if ( isset( $m[2] ) && '' !== $m[2] ) {
					// A fish inside a kept-whole invoice line: only its price leaves.
					$amount                    = max( 0, (int) ( $entry['unit_paid'] ?? 0 ) ) * $qty;
					$rows[ $key ]['subtotal'] -= $amount;
					$rows[ $key ]['total']    -= $amount;
					if ( $rows[ $key ]['total'] <= 0 ) {
						unset( $rows[ $key ] );
					}
					break;
				}

				$take                                    = min( $qty, (int) $item['qty'] );
				$rows[ $key ]['subtotal']               -= $take * (int) $item['unit_subtotal'];
				$rows[ $key ]['total']                  -= $take * (int) $item['unit_total'];
				$rows[ $key ]['items'][ $item_id ]['qty'] = (int) $item['qty'] - $take;

				if ( $row['group'] ) {
					$left = 0;
					foreach ( $rows[ $key ]['items'] as $other ) {
						$left += (int) $other['qty'];
					}
					if ( $left <= 0 ) {
						unset( $rows[ $key ] );
					}
				} else {
					$rows[ $key ]['qty'] = (int) $row['qty'] - $take;
					if ( $rows[ $key ]['qty'] <= 0 ) {
						unset( $rows[ $key ] );
					}
				}
				break;
			}

			if ( ! $found ) {
				return null;
			}
		}

		return $rows;
	}

	/**
	 * The rows that ship.
	 *
	 * @param array $rows Rows.
	 * @return array
	 */
	public static function shipping_rows( array $rows ) {
		$out = array();
		foreach ( $rows as $row ) {
			if ( ! empty( $row['ships'] ) ) {
				$out[] = $row;
			}
		}
		return $out;
	}

	/**
	 * What the goods that ship were paid (cents, after discounts).
	 *
	 * @param array $rows Rows.
	 * @return int
	 */
	public static function goods( array $rows ) {
		$sum = 0;
		foreach ( self::shipping_rows( $rows ) as $row ) {
			$sum += (int) $row['total'];
		}
		return $sum;
	}

	/* ============================================================== */
	/*  Pricing a package                                              */
	/* ============================================================== */

	/**
	 * What shipping costs for a set of rows.
	 *
	 * @param object $order Order.
	 * @param array  $rows  Rows.
	 * @param array  $opts  rater.
	 * @return array{cents: int|null, error: string}
	 */
	private static function rate( $order, array $rows, array $opts ) {
		$ships = self::shipping_rows( $rows );
		$rater = isset( $opts['rater'] ) && is_callable( $opts['rater'] ) ? $opts['rater'] : self::$rater;

		try {
			$result = is_callable( $rater ) ? call_user_func( $rater, $order, $ships ) : self::wc_rate( $order, $ships );
		} catch ( \Throwable $e ) {
			$result = array(
				'cents' => null,
				'error' => self::cannot( $e->getMessage() ),
			);
		}

		if ( is_array( $result ) ) {
			$cents = isset( $result['cents'] ) && is_numeric( $result['cents'] ) ? (int) $result['cents'] : null;
			return array(
				'cents' => $cents,
				'error' => null === $cents ? ( '' !== (string) ( $result['error'] ?? '' ) ? (string) $result['error'] : self::cannot( '' ) ) : '',
			);
		}
		if ( is_int( $result ) ) {
			return array(
				'cents' => $result,
				'error' => '',
			);
		}

		return array(
			'cents' => null,
			'error' => self::cannot( '' ),
		);
	}

	/**
	 * "The shipping settings could not price this order" with a reason.
	 *
	 * @param string $why The reason ('' = none known).
	 * @return string
	 */
	private static function cannot( $why ) {
		$text = __( 'The shipping settings could not price this order here.', 'fishotel-misc-plugin' );
		$why  = trim( (string) $why );
		return '' !== $why ? $text . ' (' . substr( $why, 0, 120 ) . ')' : $text;
	}

	/**
	 * Ask WooCommerce: the zone matching the package, the method the
	 * order used, that method's rate for the rows.
	 *
	 * @param object $order Order.
	 * @param array  $rows  The rows that ship.
	 * @return array{cents: int|null, error: string}
	 */
	private static function wc_rate( $order, array $rows ) {
		$fail = static function ( $message ) {
			return array(
				'cents' => null,
				'error' => (string) $message,
			);
		};

		if ( ! class_exists( '\\WC_Shipping_Zones' ) || ! method_exists( $order, 'get_items' ) ) {
			return $fail( __( 'WooCommerce\'s shipping zones are not available.', 'fishotel-misc-plugin' ) );
		}

		$lines = array_values( (array) $order->get_items( 'shipping' ) );
		if ( 1 !== count( $lines ) ) {
			return $fail( $lines ? __( 'This order has more than one shipping line.', 'fishotel-misc-plugin' ) : __( 'This order has no shipping line.', 'fishotel-misc-plugin' ) );
		}
		$line        = $lines[0];
		$method_id   = method_exists( $line, 'get_method_id' ) ? (string) $line->get_method_id() : '';
		$instance_id = method_exists( $line, 'get_instance_id' ) ? (int) $line->get_instance_id() : 0;
		if ( '' === $method_id || $instance_id <= 0 ) {
			return $fail( __( 'The order\'s shipping line does not say which shipping method of which zone it came from.', 'fishotel-misc-plugin' ) );
		}

		$package = self::package( $order, $rows );
		$zone    = \WC_Shipping_Zones::get_zone_matching_package( $package );
		$method  = null;
		foreach ( (array) $zone->get_shipping_methods( true ) as $candidate ) {
			if ( is_object( $candidate ) && (int) $candidate->instance_id === $instance_id && (string) $candidate->id === $method_id ) {
				$method = $candidate;
				break;
			}
		}
		if ( ! $method ) {
			return $fail( __( 'The shipping method this order used is no longer in the shipping zone of its address.', 'fishotel-misc-plugin' ) );
		}

		$rates = self::isolated(
			static function () use ( $method, $package ) {
				if ( method_exists( $method, 'get_rates_for_package' ) ) {
					return $method->get_rates_for_package( $package );
				}
				$method->rates = array();
				$method->calculate_shipping( $package );
				return $method->rates;
			}
		);
		$rates = array_values( array_filter( is_array( $rates ) ? $rates : array(), 'is_object' ) );

		if ( ! $rates ) {
			return $fail( __( 'The shipping method gives no rate for this order.', 'fishotel-misc-plugin' ) );
		}
		if ( count( $rates ) > 1 ) {
			$label   = method_exists( $line, 'get_name' ) ? (string) $line->get_name() : '';
			$matches = array();
			foreach ( $rates as $rate ) {
				$rate_label = method_exists( $rate, 'get_label' ) ? (string) $rate->get_label() : (string) ( $rate->label ?? '' );
				if ( '' !== $label && $rate_label === $label ) {
					$matches[] = $rate;
				}
			}
			if ( 1 !== count( $matches ) ) {
				return $fail( __( 'The shipping method gives more than one rate for this order, and none can be told apart as the one that was charged.', 'fishotel-misc-plugin' ) );
			}
			$rates = $matches;
		}

		$cost = method_exists( $rates[0], 'get_cost' ) ? $rates[0]->get_cost() : ( $rates[0]->cost ?? null );
		if ( ! is_numeric( $cost ) ) {
			return $fail( __( 'The shipping method gives a rate without a cost.', 'fishotel-misc-plugin' ) );
		}

		return array(
			'cents' => Money::cents( $cost ),
			'error' => '',
		);
	}

	/**
	 * The shipping package of some rows, as WooCommerce's cart builds one.
	 * Each product is a copy priced at what the order paid for it, so a
	 * method that reads the product's price sees the price of that day.
	 *
	 * @param object $order Order.
	 * @param array  $rows  The rows that ship.
	 * @return array
	 */
	public static function package( $order, array $rows ) {
		$contents = array();
		$cost     = 0;
		$subtotal = 0;

		foreach ( $rows as $row ) {
			$qty     = max( 1, (int) $row['qty'] );
			$product = is_object( $row['product'] ) ? clone $row['product'] : $row['product'];
			try {
				if ( is_object( $product ) && method_exists( $product, 'set_price' ) ) {
					$product->set_price( (string) ( $row['subtotal'] / 100 / $qty ) );
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}

			$parent_id    = is_object( $product ) && method_exists( $product, 'get_parent_id' ) ? (int) $product->get_parent_id() : 0;
			$own_id       = is_object( $product ) && method_exists( $product, 'get_id' ) ? (int) $product->get_id() : 0;
			$cost        += (int) $row['total'];
			$subtotal    += (int) $row['subtotal'];
			$contents[ $row['key'] ] = array(
				'key'               => $row['key'],
				'product_id'        => $parent_id > 0 ? $parent_id : $own_id,
				'variation_id'      => $parent_id > 0 ? $own_id : 0,
				'variation'         => array(),
				'quantity'          => $qty,
				'data'              => $product,
				'line_subtotal'     => $row['subtotal'] / 100,
				'line_subtotal_tax' => 0,
				'line_total'        => $row['total'] / 100,
				'line_tax'          => 0,
			);
		}

		// The shipping address as a whole, else the billing address as a whole — never a mix.
		$prefix = '' !== ( method_exists( $order, 'get_shipping_country' ) ? trim( (string) $order->get_shipping_country() ) : '' ) ? 'get_shipping_' : 'get_billing_';
		$side   = static function ( $field ) use ( $order, $prefix ) {
			$getter = $prefix . $field;
			return method_exists( $order, $getter ) ? trim( (string) $order->$getter() ) : '';
		};

		return array(
			'contents'        => $contents,
			'contents_cost'   => $cost / 100,
			'applied_coupons' => method_exists( $order, 'get_coupon_codes' ) ? (array) $order->get_coupon_codes() : array(),
			'user'            => array( 'ID' => method_exists( $order, 'get_customer_id' ) ? (int) $order->get_customer_id() : 0 ),
			'destination'     => array(
				'country'   => $side( 'country' ),
				'state'     => $side( 'state' ),
				'postcode'  => $side( 'postcode' ),
				'city'      => $side( 'city' ),
				'address'   => $side( 'address_1' ),
				'address_1' => $side( 'address_1' ),
				'address_2' => $side( 'address_2' ),
			),
			'cart_subtotal'   => $subtotal / 100,
			'rates'           => array(),
		);
	}

	/**
	 * Run something with WooCommerce's session replaced by a throw-away
	 * in-memory one and its cart hidden; both are put back whatever
	 * happens.
	 *
	 * @param callable $work The work.
	 * @return mixed What the work returned.
	 */
	private static function isolated( $work ) {
		$wc = function_exists( 'WC' ) ? WC() : null;
		if ( ! is_object( $wc ) ) {
			return call_user_func( $work );
		}

		$session = isset( $wc->session ) ? $wc->session : null;
		$cart    = isset( $wc->cart ) ? $wc->cart : null;

		try {
			$wc->session = class_exists( '\\WC_Session' ) ? new class() extends \WC_Session {
			} : null;
			$wc->cart    = null;

			return call_user_func( $work );
		} finally {
			$wc->session = $session;
			$wc->cart    = $cart;
		}
	}
}
