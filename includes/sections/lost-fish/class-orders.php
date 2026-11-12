<?php
/**
 * Lost Fish orders — WooCommerce, read and written through the order API
 * only (wc_get_orders(), wc_get_order(), the order's own getters and
 * add_order_note()), so it works the same with HPOS on or off.
 *
 * What it answers:
 *
 *   holders( $kind )  the order lines that are OWED a fish of a kind:
 *       - the order is PAID — it has a paid date, or is_paid() says so;
 *         never the status name;
 *       - the order is not completed / shipped / cancelled / refunded /
 *         failed, and is not a combined-box wrapper (its lines are copies
 *         of the source orders' lines, which are read instead);
 *       - the line is not shipped: no ShipTracker shipment names it
 *         (FST_Shipment::get_by_order() and its line_item_ids, when that
 *         plugin is active — a shipment without a list covers nothing, as
 *         ShipTracker itself counts), and the theme's line status is not
 *         "shipped" / "na";
 *       - the line is of the kind: its product or variation is the tank
 *         group's product; or the Invoices section links it to that
 *         product / species and size / tank group — the invoices of the
 *         order with their lines from fishotel_invoices_for_order(), and,
 *         for an invoice product of the order that function did not
 *         answer for, its lines from fishotel_invoices_lines_for_product()
 *         (both only when present); or it is a product-less fish line out
 *         of an invoice whose NAME is the group's species or product
 *         title — matched by name, so such a case is marked "check" and is
 *         never sent by itself.
 *
 *   lines( $order )   every line of one order in the same shape (the list
 *                     a case is opened from by hand).
 *   facts( $order )   who the customer is, what was paid and refunded, the
 *                     shipping charged, the delivery date the checkout
 *                     stored (_fishotel_shipping_date, through the theme's
 *                     fishotel_get_effective_delivery_date() when it
 *                     exists) and the ship date = the day before it.
 *
 * Nothing here needs a WooCommerce CLASS at load time: orders, items and
 * products are used through their methods, so the tests drive this very
 * class with stand-ins.
 *
 * @package FisHotel\Misc\Sections\Lost_Fish
 */

namespace FisHotel\Misc\Sections\Lost_Fish;

defined( 'ABSPATH' ) || exit;

/**
 * Class Orders
 */
class Orders {

	const META_DELIVERY    = '_fishotel_shipping_date';
	const META_FULFILLED   = '_fishotel_fulfilled_by_order';
	const META_WRAPPER     = '_fishotel_is_fulfillment';
	const META_LINE_STATUS = '_fishotel_line_status';
	const META_INV_FISH    = '_fh_invoice_fish';
	const META_INV_PRODUCT = '_fh_invoice_product_id';
	const META_INV_LINES   = '_fh_invoice_lines';
	const META_INV_CREDIT  = '_fh_invoice_credit';
	const META_PIGGYBACK   = '_fishotel_piggyback_of';

	/**
	 * Order statuses (without the wc- prefix) that owe nothing any more.
	 * "shipped" is ShipTracker's: it sets it once EVERY line of the order
	 * is in a box. Filter: fishotel_lostfish_closed_statuses.
	 *
	 * @var string[]
	 */
	const CLOSED_STATUSES = array( 'completed', 'shipped', 'cancelled', 'refunded', 'failed', 'checkout-draft', 'draft', 'auto-draft', 'trash' );

	/**
	 * The most open orders one loss looks at.
	 *
	 * @var int
	 */
	const OPEN_LIMIT = 400;

	/**
	 * The section's store (for the open-order prefilter), or null.
	 *
	 * @var object|null
	 */
	private $store;

	/**
	 * The ids of the open orders, once per request.
	 *
	 * @var int[]|null
	 */
	private $open_ids = null;

	/**
	 * The lines of an invoice product, as the Invoices section gives them,
	 * once per request.
	 *
	 * @var array<int, array[]>
	 */
	private $invoice_lines = array();

	/**
	 * The shipped line ids per order, once per request.
	 *
	 * @var array<int, array<int, true>>
	 */
	private $shipped = array();

	/**
	 * Constructor.
	 *
	 * @param object|null $store Store (or the tests' stand-in) with orders_with_lines().
	 */
	public function __construct( $store = null ) {
		$this->store = $store;
	}

	/**
	 * Whether WooCommerce's order API is there.
	 *
	 * @return bool
	 */
	public function available() {
		return function_exists( 'wc_get_order' ) && function_exists( 'wc_get_orders' );
	}

	/**
	 * Whether the Invoices section's contract is there.
	 *
	 * @return bool
	 */
	public static function invoices_available() {
		return function_exists( 'fishotel_invoices_for_order' ) || function_exists( 'fishotel_invoices_lines_for_product' );
	}

	/**
	 * Whether ShipTracker's shipments can be read.
	 *
	 * @return bool
	 */
	public static function shipments_available() {
		return class_exists( '\\FST_Shipment' ) && method_exists( '\\FST_Shipment', 'get_by_order' );
	}

	/**
	 * An order.
	 *
	 * @param int $order_id Order id.
	 * @return object|null
	 */
	public function get( $order_id ) {
		if ( (int) $order_id <= 0 || ! $this->available() ) {
			return null;
		}
		try {
			$order = wc_get_order( (int) $order_id );
		} catch ( \Throwable $e ) {
			return null;
		}
		return is_object( $order ) && method_exists( $order, 'get_items' ) && method_exists( $order, 'get_status' ) ? $order : null;
	}

	/* ============================================================== */
	/*  One order                                                      */
	/* ============================================================== */

	/**
	 * Paid: the order has a paid date, or WooCommerce says it is paid.
	 *
	 * @param object $order Order.
	 * @return bool
	 */
	public function is_paid( $order ) {
		try {
			if ( method_exists( $order, 'get_date_paid' ) && $order->get_date_paid() ) {
				return true;
			}
			return method_exists( $order, 'is_paid' ) && (bool) $order->is_paid();
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	/**
	 * When the order was paid (its paid date, else its creation date).
	 *
	 * @param object $order Order.
	 * @return int UNIX timestamp (0 = unknown).
	 */
	public function paid_at( $order ) {
		foreach ( array( 'get_date_paid', 'get_date_created' ) as $getter ) {
			try {
				$date = method_exists( $order, $getter ) ? $order->$getter() : null;
			} catch ( \Throwable $e ) {
				$date = null;
			}
			if ( is_object( $date ) && method_exists( $date, 'getTimestamp' ) ) {
				return (int) $date->getTimestamp();
			}
		}
		return 0;
	}

	/**
	 * Whether the order owes nothing any more by its status.
	 *
	 * @param object $order Order.
	 * @return bool
	 */
	public function is_closed( $order ) {
		$closed = self::CLOSED_STATUSES;
		if ( function_exists( 'apply_filters' ) ) {
			$closed = (array) apply_filters( 'fishotel_lostfish_closed_statuses', $closed );
		}
		$status = preg_replace( '/^wc-/', '', (string) $order->get_status() );

		return in_array( $status, $closed, true );
	}

	/**
	 * Whether the order is a combined-box wrapper.
	 *
	 * @param object $order Order.
	 * @return bool
	 */
	public function is_wrapper( $order ) {
		return '1' === self::meta_string( $order, self::META_WRAPPER );
	}

	/**
	 * The order line ids a ShipTracker shipment names.
	 *
	 * @param object $order Order.
	 * @return array<int, true>
	 */
	public function shipped_item_ids( $order ) {
		$out = array();
		if ( ! self::shipments_available() ) {
			return $out;
		}
		$order_id = (int) $order->get_id();
		if ( isset( $this->shipped[ $order_id ] ) ) {
			return $this->shipped[ $order_id ];
		}
		try {
			$rows = \FST_Shipment::get_by_order( $order_id );
		} catch ( \Throwable $e ) {
			return $out;
		}
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$list = is_object( $row ) && isset( $row->line_item_ids ) ? $row->line_item_ids : ( is_array( $row ) && isset( $row['line_item_ids'] ) ? $row['line_item_ids'] : '' );
			$ids  = is_string( $list ) && '' !== $list ? json_decode( $list, true ) : ( is_array( $list ) ? $list : null );
			foreach ( is_array( $ids ) ? $ids : array() as $id ) {
				if ( is_scalar( $id ) && (int) $id > 0 ) {
					$out[ (int) $id ] = true;
				}
			}
		}
		$this->shipped[ $order_id ] = $out;

		return $out;
	}

	/**
	 * Every line of an order.
	 *
	 * @param object $order Order.
	 * @return array<int, array{ref: string, item_id: int, name: string, qty: int, qty_refunded: int, shipped: bool,
	 *               unit_paid: int, paid_known: bool, product_id: int, variation_id: int, type: string, link: array}>
	 */
	public function lines( $order ) {
		$shipped  = $this->shipped_item_ids( $order );
		$rows     = array();
		$invoices = array(); // The invoice products this order's lines came out of.

		try {
			$items = (array) $order->get_items( 'line_item' );
		} catch ( \Throwable $e ) {
			$items = array();
		}

		foreach ( $items as $key => $item ) {
			if ( ! is_object( $item ) || ! method_exists( $item, 'get_quantity' ) ) {
				continue;
			}
			$item_id    = method_exists( $item, 'get_id' ) ? (int) $item->get_id() : (int) $key;
			$qty        = (int) $item->get_quantity();
			$status     = self::meta_string( $item, self::META_LINE_STATUS );
			$is_shipped = isset( $shipped[ $item_id ] ) || in_array( $status, array( 'shipped', 'na' ), true );
			$refunded   = method_exists( $order, 'get_qty_refunded_for_item' ) ? abs( (int) $order->get_qty_refunded_for_item( $item_id ) ) : 0;
			$is_fish    = self::truthy( self::meta( $item, self::META_INV_FISH ) );
			$sublines   = $is_fish ? null : self::invoice_sublines( self::meta( $item, self::META_INV_LINES ) );
			$invoice_id = (int) self::meta_string( $item, self::META_INV_PRODUCT );
			if ( $invoice_id > 0 ) {
				$invoices[ $invoice_id ] = true;
			}

			if ( null !== $sublines ) {
				// An invoice kept as ONE line: each fish of its snapshot is a line of its own.
				foreach ( $sublines as $index => $line ) {
					$rows[] = array(
						'ref'          => 'i:' . $item_id . ':' . (int) $index,
						'item_id'      => $item_id,
						'name'         => $line['name'],
						'qty'          => $line['qty'] * max( 1, $qty ),
						'qty_refunded' => $refunded >= max( 1, $qty ) ? $line['qty'] * max( 1, $qty ) : 0,
						'shipped'      => $is_shipped,
						'unit_paid'    => null !== $line['price'] ? Money::cents( $line['price'] ) : 0,
						'paid_known'   => null !== $line['price'],
						'product_id'   => $line['product_id'],
						'variation_id' => 0,
						'type'         => 'invoice_line',
						'link'         => self::no_link(),
					);
				}
				continue;
			}

			$rows[] = array(
				'ref'          => 'i:' . $item_id,
				'item_id'      => $item_id,
				'name'         => method_exists( $item, 'get_name' ) ? trim( (string) $item->get_name() ) : '',
				'qty'          => $qty,
				'qty_refunded' => $refunded,
				'shipped'      => $is_shipped,
				'unit_paid'    => Money::unit_paid( method_exists( $item, 'get_total' ) ? $item->get_total() : 0, method_exists( $item, 'get_total_tax' ) ? $item->get_total_tax() : 0, $qty ),
				'paid_known'   => $qty > 0,
				'product_id'   => method_exists( $item, 'get_product_id' ) ? (int) $item->get_product_id() : 0,
				'variation_id' => method_exists( $item, 'get_variation_id' ) ? (int) $item->get_variation_id() : 0,
				'type'         => $is_fish ? 'invoice_fish' : 'product',
				'link'         => self::no_link(),
			);
		}

		return $this->with_invoice_lines( $rows, (int) $order->get_id(), array_keys( $invoices ) );
	}

	/**
	 * One line of an order by its ref.
	 *
	 * @param object $order Order.
	 * @param string $ref   The line's ref.
	 * @return array|null
	 */
	public function line( $order, $ref ) {
		foreach ( $this->lines( $order ) as $row ) {
			if ( $row['ref'] === (string) $ref ) {
				return $row;
			}
		}
		return null;
	}

	/**
	 * Whether anything else is still to ship on the order once a line
	 * gives up some fish.
	 *
	 * @param object $order Order.
	 * @param string $ref   The line that loses fish.
	 * @param int    $qty   How many.
	 * @return bool
	 */
	public function rest_left( $order, $ref, $qty ) {
		foreach ( $this->lines( $order ) as $row ) {
			if ( $row['shipped'] ) {
				continue;
			}
			$left = $row['qty'] - $row['qty_refunded'] - ( $row['ref'] === (string) $ref ? max( 0, (int) $qty ) : 0 );
			if ( $left > 0 ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * The fish lines a kept-whole invoice line carries (the theme's
	 * _fh_invoice_lines JSON: name, qty, price per fish, kind).
	 *
	 * @param mixed $json The item meta.
	 * @return array<int, array{name: string, qty: int, price: float|null, product_id: int}>|null Null when there is no readable snapshot.
	 */
	public static function invoice_sublines( $json ) {
		if ( is_string( $json ) && '' !== trim( $json ) ) {
			$json = json_decode( $json, true );
		}
		if ( ! is_array( $json ) || ! $json ) {
			return null;
		}

		$out = array();
		foreach ( array_values( $json ) as $index => $line ) {
			if ( ! is_array( $line ) || 'fish' !== (string) ( $line['kind'] ?? 'fish' ) ) {
				continue;
			}
			$name = trim( (string) ( $line['name'] ?? '' ) );
			if ( '' === $name ) {
				continue;
			}
			$out[ $index ] = array(
				'name'       => $name,
				'qty'        => max( 1, (int) ( $line['qty'] ?? 1 ) ),
				'price'      => isset( $line['price'] ) && is_numeric( $line['price'] ) ? (float) $line['price'] : null,
				'product_id' => isset( $line['product_id'] ) ? max( 0, (int) $line['product_id'] ) : 0,
			);
		}

		return $out ? $out : null;
	}

	/**
	 * What the screens and the e-mail need to know about an order.
	 *
	 * @param object $order Order.
	 * @return array{id: int, number: string, status: string, paid: bool, paid_at: int, closed: bool, wrapper: bool,
	 *               customer_id: int, customer_name: string, first_name: string, email: string, currency: string,
	 *               symbol: string, total: int, refunded: int, ship_charged: int, ship_tax: int, delivery_date: string,
	 *               ship_date: string, any_shipped: bool, edit_url: string}
	 */
	public function facts( $order ) {
		$first = self::call( $order, 'get_billing_first_name' );
		$last  = self::call( $order, 'get_billing_last_name' );
		$name  = trim( $first . ' ' . $last );
		if ( '' === $name ) {
			$name = trim( self::call( $order, 'get_shipping_first_name' ) . ' ' . self::call( $order, 'get_shipping_last_name' ) );
		}

		$currency = self::call( $order, 'get_currency' );
		$symbol   = '$';
		if ( function_exists( 'get_woocommerce_currency_symbol' ) ) {
			$decoded = html_entity_decode( (string) get_woocommerce_currency_symbol( $currency ), ENT_QUOTES, 'UTF-8' );
			$symbol  = '' !== $decoded ? $decoded : '$';
		}

		$any_shipped = false;
		foreach ( $this->lines( $order ) as $row ) {
			if ( $row['shipped'] ) {
				$any_shipped = true;
				break;
			}
		}

		$delivery = $this->delivery_date( $order );
		$id       = (int) $order->get_id();

		if ( method_exists( $order, 'get_edit_order_url' ) ) {
			$edit_url = (string) $order->get_edit_order_url();
		} else {
			$edit_url = admin_url( 'post.php?post=' . $id . '&action=edit' );
		}

		return array(
			'id'            => $id,
			'number'        => method_exists( $order, 'get_order_number' ) ? (string) $order->get_order_number() : (string) $id,
			'status'        => preg_replace( '/^wc-/', '', (string) $order->get_status() ),
			'paid'          => $this->is_paid( $order ),
			'paid_at'       => $this->paid_at( $order ),
			'closed'        => $this->is_closed( $order ),
			'wrapper'       => $this->is_wrapper( $order ),
			'customer_id'   => method_exists( $order, 'get_customer_id' ) ? (int) $order->get_customer_id() : 0,
			'customer_name' => $name,
			'first_name'    => trim( $first ),
			'email'         => trim( self::call( $order, 'get_billing_email' ) ),
			'currency'      => $currency,
			'symbol'        => $symbol,
			'total'         => Money::cents( method_exists( $order, 'get_total' ) ? $order->get_total() : 0 ),
			'refunded'      => Money::cents( method_exists( $order, 'get_total_refunded' ) ? $order->get_total_refunded() : 0 ),
			'ship_charged'  => Money::cents( method_exists( $order, 'get_shipping_total' ) ? $order->get_shipping_total() : 0 ),
			'ship_tax'      => Money::cents( method_exists( $order, 'get_shipping_tax' ) ? $order->get_shipping_tax() : 0 ),
			'delivery_date' => $delivery,
			'ship_date'     => self::ship_date_for( $delivery, $order ),
			'any_shipped'   => $any_shipped,
			'edit_url'      => $edit_url,
		);
	}

	/**
	 * The delivery date the checkout stored on the order (the theme's
	 * helper when it exists — it also looks at the combined box the order
	 * was folded into — else the same two meta reads).
	 *
	 * @param object $order Order.
	 * @return string Y-m-d, or '' when the order has none.
	 */
	public function delivery_date( $order ) {
		$date = '';
		try {
			if ( function_exists( 'fishotel_get_effective_delivery_date' ) ) {
				$date = (string) fishotel_get_effective_delivery_date( $order );
			} else {
				$date = self::meta_string( $order, self::META_DELIVERY );
				if ( '' === $date ) {
					$wrapper = $this->get( (int) self::meta_string( $order, self::META_FULFILLED ) );
					$date    = $wrapper ? self::meta_string( $wrapper, self::META_DELIVERY ) : '';
				}
			}
		} catch ( \Throwable $e ) {
			$date = '';
		}

		return self::ymd( $date );
	}

	/**
	 * The ship date of a delivery date: the day before (livestock ships
	 * overnight) — the Batch Manager's own rule when it is there.
	 *
	 * @param string      $delivery Y-m-d.
	 * @param object|null $order    The order (for the filter).
	 * @return string Y-m-d, or ''.
	 */
	public static function ship_date_for( $delivery, $order = null ) {
		$delivery = self::ymd( $delivery );
		$ship     = '';
		if ( '' !== $delivery ) {
			try {
				if ( class_exists( '\\FisHotel_Ship_Day' ) && method_exists( '\\FisHotel_Ship_Day', 'ship_date_for' ) ) {
					$ship = self::ymd( (string) \FisHotel_Ship_Day::ship_date_for( $delivery ) );
				}
			} catch ( \Throwable $e ) {
				$ship = '';
			}
			if ( '' === $ship ) {
				$ship = gmdate( 'Y-m-d', (int) strtotime( $delivery . ' 00:00:00 UTC' ) - 86400 );
			}
		}
		if ( function_exists( 'apply_filters' ) ) {
			$ship = self::ymd( (string) apply_filters( 'fishotel_lostfish_ship_date', $ship, $delivery, $order ) );
		}
		return $ship;
	}

	/**
	 * A real Y-m-d, or ''.
	 *
	 * @param mixed $date A date.
	 * @return string
	 */
	public static function ymd( $date ) {
		if ( ! is_string( $date ) || 1 !== preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', trim( $date ), $m ) ) {
			return '';
		}
		return checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ? trim( $date ) : '';
	}

	/**
	 * Add a private note to an order (never a note to the customer).
	 *
	 * @param int    $order_id Order id.
	 * @param string $text     The note.
	 * @param bool   $by_user  Whether the current user is its author.
	 * @return bool
	 */
	public function note( $order_id, $text, $by_user = false ) {
		$order = $this->get( $order_id );
		if ( ! $order || ! method_exists( $order, 'add_order_note' ) ) {
			return false;
		}
		try {
			return (bool) $order->add_order_note( (string) $text, 0, (bool) $by_user );
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	/* ============================================================== */
	/*  The holders of a kind                                          */
	/* ============================================================== */

	/**
	 * The order lines owed a fish of a kind (see the file header).
	 *
	 * @param array $kind     Cover::kind_of_stay().
	 * @param int[] $stay_ids The tank groups of the kind (a line the Invoices section links to one of them counts).
	 * @return array[] Lines (as lines()) with order_id, order_number, paid_at, customer_id, customer_name,
	 *                 customer_email, currency and match added.
	 */
	public function holders( array $kind, array $stay_ids = array() ) {
		if ( ! $this->available() || '' === (string) ( $kind['key'] ?? '' ) ) {
			return array();
		}

		$stay_ids = array_values( array_filter( array_map( 'intval', $stay_ids ) ) );
		$out      = array();

		foreach ( $this->candidate_ids( $kind ) as $order_id ) {
			$order = $this->get( $order_id );
			if ( ! $order || $this->is_closed( $order ) || $this->is_wrapper( $order ) || ! $this->is_paid( $order ) ) {
				continue;
			}

			$facts = null;
			foreach ( $this->lines( $order ) as $row ) {
				if ( $row['shipped'] || $row['qty'] <= 0 ) {
					continue;
				}
				$match = self::match_line( $row, $kind, $stay_ids );
				if ( '' === $match ) {
					continue;
				}
				if ( null === $facts ) {
					$facts = $this->facts( $order );
				}
				$out[] = array_merge(
					$row,
					array(
						'order_id'       => $facts['id'],
						'order_number'   => $facts['number'],
						'paid_at'        => $facts['paid_at'],
						'customer_id'    => $facts['customer_id'],
						'customer_name'  => $facts['customer_name'],
						'customer_email' => $facts['email'],
						'currency'       => $facts['currency'],
						'match'          => $match,
					)
				);
			}
		}

		if ( function_exists( 'apply_filters' ) ) {
			$out = (array) apply_filters( 'fishotel_lostfish_holders', $out, $kind );
		}

		return $out;
	}

	/**
	 * How a line is of a kind: 'product' / 'variation' (its product is the
	 * tank group's), 'invoice' (the Invoices section links it), 'name' (a
	 * product-less fish line out of an invoice with the same name) — or ''.
	 *
	 * @param array $row      A line (lines()).
	 * @param array $kind     Cover::kind_of_stay().
	 * @param int[] $stay_ids The tank groups of the kind.
	 * @return string
	 */
	public static function match_line( array $row, array $kind, array $stay_ids = array() ) {
		$product_id = (int) ( $kind['product_id'] ?? 0 );
		$link       = isset( $row['link'] ) && is_array( $row['link'] ) ? array_merge( self::no_link(), $row['link'] ) : self::no_link();

		if ( $product_id > 0 ) {
			if ( (int) $row['product_id'] === $product_id ) {
				return 'product';
			}
			if ( (int) $row['variation_id'] === $product_id ) {
				return 'variation';
			}
			if ( (int) $link['product_id'] === $product_id || (int) $link['variation_id'] === $product_id ) {
				return 'invoice';
			}
		}

		if ( (int) $link['stay_id'] > 0 && in_array( (int) $link['stay_id'], $stay_ids, true ) ) {
			return 'invoice';
		}

		if ( $product_id <= 0 && '' !== Cover::norm( $link['species'] ) && Cover::norm( $link['species'] ) === Cover::norm( $kind['species'] ?? '' ) ) {
			$size = strtolower( trim( (string) $link['size_slug'] ) );
			if ( '' === $size || $size === strtolower( (string) ( $kind['size_slug'] ?? '' ) ) ) {
				return 'invoice';
			}
		}

		// By name: only a fish line out of an invoice that names no product — and never against a size the
		// Invoices section spelled out as another one.
		$nameable = in_array( (string) $row['type'], array( 'invoice_fish', 'invoice_line', 'invoice' ), true );
		if ( $product_id <= 0 && '' !== (string) $link['size_slug'] && strtolower( (string) $link['size_slug'] ) !== strtolower( (string) ( $kind['size_slug'] ?? '' ) ) ) {
			$nameable = false;
		}
		if ( $nameable && (int) $row['product_id'] <= 0 && (int) $row['variation_id'] <= 0 && (int) $link['product_id'] <= 0 ) {
			$name = Cover::norm( $row['name'] );
			if ( '' !== $name && in_array( $name, (array) ( $kind['names'] ?? array() ), true ) ) {
				return 'name';
			}
		}

		return '';
	}

	/**
	 * The open orders that may hold the kind: the ones the order-item
	 * tables name — a line of the product, or a line out of an invoice —
	 * or every open order when that read cannot say.
	 *
	 * @param array $kind Cover::kind_of_stay().
	 * @return int[]
	 */
	private function candidate_ids( array $kind ) {
		$open = $this->open_order_ids();
		if ( ! $open ) {
			return array();
		}
		if ( ! is_object( $this->store ) || ! method_exists( $this->store, 'orders_with_lines' ) ) {
			return $open;
		}

		$product_id = (int) ( $kind['product_id'] ?? 0 );
		$found      = $this->store->orders_with_lines( $open, $product_id > 0 ? array( $product_id ) : array() );
		if ( ! is_array( $found ) ) {
			return $open;
		}

		return array_values( array_intersect( $open, array_map( 'intval', $found ) ) );
	}

	/**
	 * The ids of the orders that may still owe something, newest first:
	 * every order status except the closed ones.
	 *
	 * @return int[]
	 */
	public function open_order_ids() {
		if ( null !== $this->open_ids ) {
			return $this->open_ids;
		}
		$this->open_ids = array();
		if ( ! $this->available() ) {
			return $this->open_ids;
		}

		$closed = self::CLOSED_STATUSES;
		if ( function_exists( 'apply_filters' ) ) {
			$closed = (array) apply_filters( 'fishotel_lostfish_closed_statuses', $closed );
		}

		$statuses = function_exists( 'wc_get_order_statuses' ) ? array_keys( (array) wc_get_order_statuses() ) : array();
		if ( function_exists( 'get_post_stati' ) ) {
			foreach ( (array) get_post_stati() as $status ) {
				if ( is_string( $status ) && 0 === strpos( $status, 'wc-' ) ) {
					$statuses[] = $status;
				}
			}
		}

		$open = array();
		foreach ( array_unique( array_map( 'strval', $statuses ) ) as $status ) {
			if ( ! in_array( preg_replace( '/^wc-/', '', $status ), $closed, true ) ) {
				$open[] = $status;
			}
		}
		if ( ! $open ) {
			return $this->open_ids;
		}

		try {
			$ids = wc_get_orders(
				array(
					'type'    => 'shop_order',
					'status'  => $open,
					'limit'   => self::OPEN_LIMIT,
					'orderby' => 'date',
					'order'   => 'DESC',
					'return'  => 'ids',
				)
			);
		} catch ( \Throwable $e ) {
			$ids = array();
		}

		$this->open_ids = array_values( array_unique( array_filter( array_map( 'intval', is_array( $ids ) ? $ids : array() ) ) ) );

		return $this->open_ids;
	}

	/* ============================================================== */
	/*  The Invoices section's contract                                */
	/* ============================================================== */

	/**
	 * Add what the Invoices section knows to an order's lines. A line it
	 * ties to an order item, or that is plainly the same fish as an
	 * invoice line the order already lists (same name, same quantity),
	 * gives that line its link (product, variation, species, size, tank
	 * group) instead of counting twice; any other line becomes a line of
	 * its own.
	 *
	 * @param array[] $rows        lines() so far.
	 * @param int     $order_id    Order id.
	 * @param int[]   $invoice_ids The invoice products the order's lines came out of.
	 * @return array[]
	 */
	private function with_invoice_lines( array $rows, $order_id, array $invoice_ids ) {
		$extra = $this->order_invoice_lines( $order_id );

		// An invoice of the order that fishotel_invoices_for_order() did not answer for.
		$answered = array();
		foreach ( $extra as $line ) {
			$answered[ $line['invoice_product_id'] ] = true;
		}
		foreach ( $invoice_ids as $invoice_id ) {
			if ( ! isset( $answered[ (int) $invoice_id ] ) ) {
				$extra = array_merge( $extra, $this->invoice_product_lines( (int) $invoice_id, $order_id ) );
			}
		}
		if ( ! $extra ) {
			return $rows;
		}

		$by_item = array();
		$by_name = array();
		foreach ( $rows as $index => $row ) {
			if ( 'i:' . $row['item_id'] === $row['ref'] ) {
				$by_item[ $row['item_id'] ] = $index;
			}
			if ( 'product' !== $row['type'] ) {
				$by_name[ Cover::norm( $row['name'] ) ][] = $index;
			}
		}

		$seen = array();
		foreach ( $extra as $line ) {
			if ( $line['item_id'] > 0 && isset( $by_item[ $line['item_id'] ] ) ) {
				$index                  = $by_item[ $line['item_id'] ];
				$rows[ $index ]['link'] = self::merge_link( $rows[ $index ]['link'], $line['link'] );
				continue;
			}
			if ( isset( $seen[ $line['ref'] ] ) ) {
				$rows[ $seen[ $line['ref'] ] ]['link'] = self::merge_link( $rows[ $seen[ $line['ref'] ] ]['link'], $line['link'] );
				continue;
			}
			// The same fish the order already lists as an invoice line of its own (same name, same
			// quantity, not yet linked): it is that line, not a second fish.
			$name = Cover::norm( $line['name'] );
			$twin = null;
			foreach ( '' !== $name && isset( $by_name[ $name ] ) ? $by_name[ $name ] : array() as $index ) {
				if ( (int) $rows[ $index ]['qty'] === (int) $line['qty'] && self::no_link() === $rows[ $index ]['link'] ) {
					$twin = $index;
					break;
				}
			}
			if ( null !== $twin ) {
				$rows[ $twin ]['link'] = self::merge_link( $rows[ $twin ]['link'], $line['link'] );
				$seen[ $line['ref'] ]  = $twin;
				continue;
			}
			$seen[ $line['ref'] ] = count( $rows );
			$rows[]               = array(
				'ref'          => $line['ref'],
				'item_id'      => 0,
				'name'         => $line['name'],
				'qty'          => $line['qty'],
				'qty_refunded' => 0,
				'shipped'      => $line['shipped'],
				'unit_paid'    => $line['unit_paid'],
				'paid_known'   => $line['paid_known'],
				'product_id'   => 0,
				'variation_id' => 0,
				'type'         => 'invoice',
				'link'         => $line['link'],
			);
		}

		return $rows;
	}

	/**
	 * The invoice lines of an order, from fishotel_invoices_for_order().
	 *
	 * @param int $order_id Order id.
	 * @return array[] Normalised lines.
	 */
	private function order_invoice_lines( $order_id ) {
		if ( ! function_exists( 'fishotel_invoices_for_order' ) ) {
			return array();
		}
		try {
			$invoices = fishotel_invoices_for_order( (int) $order_id );
		} catch ( \Throwable $e ) {
			return array();
		}
		return self::normalize_invoice_lines( $invoices, (int) $order_id );
	}

	/**
	 * The lines of one invoice product, from
	 * fishotel_invoices_lines_for_product() (asked once per request).
	 *
	 * @param int $invoice_product_id The invoice's product.
	 * @param int $order_id           The order they are on.
	 * @return array[] Normalised lines.
	 */
	private function invoice_product_lines( $invoice_product_id, $order_id ) {
		$invoice_product_id = (int) $invoice_product_id;
		if ( $invoice_product_id <= 0 || ! function_exists( 'fishotel_invoices_lines_for_product' ) ) {
			return array();
		}
		if ( ! isset( $this->invoice_lines[ $invoice_product_id ] ) ) {
			try {
				$lines = fishotel_invoices_lines_for_product( $invoice_product_id );
			} catch ( \Throwable $e ) {
				$lines = array();
			}
			$this->invoice_lines[ $invoice_product_id ] = is_array( $lines ) ? $lines : array();
		}
		return self::normalize_invoice_lines( $this->invoice_lines[ $invoice_product_id ], (int) $order_id, $invoice_product_id );
	}

	/**
	 * What the Invoices section returned, as flat lines on one order.
	 * Accepted: the invoices of an order, each with `id`, `product_id`
	 * (the invoice's own product) and `lines` — or one such invoice, or a
	 * flat list of lines; arrays or objects. Per line, as that section
	 * documents them: `id`, `invoice_id`, `kind` (anything but "fish" is
	 * skipped), `name`, `qty`, `unit_price` (dollars, or null),
	 * `product_id`, `variation_id`, `stay_id` (the tank group),
	 * `size_slug`; and, should a line carry them, `order_item_id` and
	 * `shipped`.
	 *
	 * @param mixed $data               What the function returned.
	 * @param int   $order_id           The order the lines are on.
	 * @param int   $invoice_product_id The invoice product, when the data is the lines of one.
	 * @return array[]
	 */
	public static function normalize_invoice_lines( $data, $order_id = 0, $invoice_product_id = 0 ) {
		$data = self::to_array( $data );
		if ( ! $data ) {
			return array();
		}
		if ( array_key_exists( 'lines', $data ) ) {
			$data = array( $data ); // One invoice.
		}

		$flat = array();
		foreach ( $data as $entry ) {
			$entry = self::to_array( $entry );
			if ( ! $entry ) {
				continue;
			}
			if ( array_key_exists( 'lines', $entry ) ) {
				$invoice_id = (int) self::pick( $entry, array( 'id', 'invoice_id' ), 0 );
				$product_id = (int) self::pick( $entry, array( 'product_id' ), 0 );
				foreach ( array_values( self::to_array( $entry['lines'] ) ) as $position => $line ) {
					$line = self::to_array( $line );
					if ( $line ) {
						$flat[] = array( $line, $invoice_id, $product_id, $position );
					}
				}
			} else {
				$flat[] = array( $entry, 0, (int) $invoice_product_id, count( $flat ) );
			}
		}

		$out = array();
		foreach ( $flat as $tuple ) {
			list( $line, $invoice_id, $product_id, $position ) = $tuple;

			if ( 'fish' !== (string) self::pick( $line, array( 'kind' ), 'fish' ) ) {
				continue;
			}
			$qty = (int) self::pick( $line, array( 'qty', 'quantity' ), 1 );
			if ( $qty <= 0 ) {
				continue;
			}
			if ( $invoice_id <= 0 ) {
				$invoice_id = (int) self::pick( $line, array( 'invoice_id' ), 0 );
			}

			$price     = self::pick( $line, array( 'unit_price', 'price' ), null );
			$unit_paid = is_numeric( $price ) ? Money::cents( $price ) : null;
			$name      = trim( (string) self::pick( $line, array( 'name', 'species', 'fish', 'title' ), '' ) );
			$line_id   = (int) self::pick( $line, array( 'id', 'line_id' ), 0 );

			// An invoice built in the Invoices section has ids; one made by hand has none: its product and the
			// line's place in it name the line instead.
			$ref = 'inv:' . ( $invoice_id > 0 ? $invoice_id : 'p' . $product_id ) . ':' . ( $line_id > 0 ? $line_id : 'n' . (int) $position );

			$out[] = array(
				'ref'                => substr( $ref, 0, 64 ),
				'order_id'           => (int) $order_id,
				'invoice_product_id' => (int) $product_id,
				'item_id'            => (int) self::pick( $line, array( 'order_item_id', 'item_id' ), 0 ),
				'name'               => $name,
				'qty'                => $qty,
				'shipped'            => self::truthy( self::pick( $line, array( 'shipped' ), false ) ),
				'unit_paid'          => null !== $unit_paid ? max( 0, $unit_paid ) : 0,
				'paid_known'         => null !== $unit_paid,
				'link'               => array(
					'product_id'   => max( 0, (int) self::pick( $line, array( 'product_id' ), 0 ) ),
					'variation_id' => max( 0, (int) self::pick( $line, array( 'variation_id' ), 0 ) ),
					'species'      => $name,
					'size_slug'    => strtolower( trim( (string) self::pick( $line, array( 'size_slug', 'size' ), '' ) ) ),
					'stay_id'      => max( 0, (int) self::pick( $line, array( 'stay_id', 'group_id' ), 0 ) ),
				),
			);
		}

		return $out;
	}

	/* ============================================================== */
	/*  Small helpers                                                  */
	/* ============================================================== */

	/**
	 * An empty link.
	 *
	 * @return array{product_id: int, variation_id: int, species: string, size_slug: string, stay_id: int}
	 */
	public static function no_link() {
		return array(
			'product_id'   => 0,
			'variation_id' => 0,
			'species'      => '',
			'size_slug'    => '',
			'stay_id'      => 0,
		);
	}

	/**
	 * Two links as one: what the first does not say, the second may.
	 *
	 * @param array $a One link.
	 * @param array $b Another.
	 * @return array
	 */
	private static function merge_link( array $a, array $b ) {
		$out = array_merge( self::no_link(), $a );
		foreach ( self::no_link() as $key => $empty ) {
			if ( $out[ $key ] === $empty && isset( $b[ $key ] ) ) {
				$out[ $key ] = $b[ $key ];
			}
		}
		return $out;
	}

	/**
	 * The first of several keys a row carries.
	 *
	 * @param array    $row     Row.
	 * @param string[] $keys    Keys, in order of preference.
	 * @param mixed    $default When none is there.
	 * @return mixed
	 */
	private static function pick( array $row, array $keys, $default ) {
		foreach ( $keys as $key ) {
			if ( isset( $row[ $key ] ) && ( is_scalar( $row[ $key ] ) ) && '' !== $row[ $key ] ) {
				return $row[ $key ];
			}
		}
		return $default;
	}

	/**
	 * An array from an array or an object's public properties.
	 *
	 * @param mixed $value Value.
	 * @return array
	 */
	private static function to_array( $value ) {
		if ( is_array( $value ) ) {
			return $value;
		}
		if ( is_object( $value ) ) {
			return get_object_vars( $value );
		}
		return array();
	}

	/**
	 * A meta value of an order or an item.
	 *
	 * @param object $object Order or item.
	 * @param string $key    Meta key.
	 * @return mixed '' when absent.
	 */
	private static function meta( $object, $key ) {
		try {
			return is_object( $object ) && method_exists( $object, 'get_meta' ) ? $object->get_meta( $key ) : '';
		} catch ( \Throwable $e ) {
			return '';
		}
	}

	/**
	 * A meta value as a string ('' when absent or not a scalar).
	 *
	 * @param object $object Order or item.
	 * @param string $key    Meta key.
	 * @return string
	 */
	public static function meta_string( $object, $key ) {
		$value = self::meta( $object, $key );
		return is_scalar( $value ) ? trim( (string) $value ) : '';
	}

	/**
	 * Whether a meta flag is set (1, "1", "yes", true).
	 *
	 * @param mixed $value Meta value.
	 * @return bool
	 */
	public static function truthy( $value ) {
		if ( is_bool( $value ) ) {
			return $value;
		}
		if ( ! is_scalar( $value ) ) {
			return false;
		}
		return in_array( strtolower( trim( (string) $value ) ), array( '1', 'yes', 'true', 'on' ), true );
	}

	/**
	 * A getter's string, '' when the method is not there.
	 *
	 * @param object $object Object.
	 * @param string $method Getter.
	 * @return string
	 */
	private static function call( $object, $method ) {
		try {
			return method_exists( $object, $method ) ? trim( (string) $object->$method() ) : '';
		} catch ( \Throwable $e ) {
			return '';
		}
	}
}
