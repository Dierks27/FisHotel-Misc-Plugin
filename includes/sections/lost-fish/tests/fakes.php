<?php
/**
 * Stand-ins the Lost Fish tests share: WooCommerce's order API as far as
 * the section uses it (orders, line items, fees, shipping lines, products,
 * wc_get_order / wc_get_orders / wc_get_product), an in-memory Store with
 * the real one's rules (the same column types through Store::clean_fields()
 * and hydrate_case(), the same conditional transition()), and the Clean
 * Room as a list of tank groups.
 *
 * Every customer, fish, price and rate here is invented.
 *
 * @package FisHotel\Misc\Sections\Lost_Fish
 */

// Helper, not a test: the CI runs every tests/*.php with php, so exit
// cleanly when executed directly instead of as a require.
if ( ! empty( $_SERVER['SCRIPT_FILENAME'] ) && realpath( $_SERVER['SCRIPT_FILENAME'] ) === __FILE__ ) {
	exit( 0 );
}

use FisHotel\Misc\Sections\Lost_Fish\Cases;
use FisHotel\Misc\Sections\Lost_Fish\Orders;
use FisHotel\Misc\Sections\Lost_Fish\Store;

/* ------------------------------------------------------------------ */
/*  WooCommerce                                                        */
/* ------------------------------------------------------------------ */

$GLOBALS['fh_orders']     = array();
$GLOBALS['fh_products']   = array();
$GLOBALS['fh_wc_queries'] = array();
$GLOBALS['fh_wc_loads']   = array();

class Fh_Date {

	private $ts;

	public function __construct( $ts ) {
		$this->ts = (int) $ts;
	}

	public function getTimestamp() {
		return $this->ts;
	}
}

class Fh_Product {

	public $id;
	public $parent_id = 0;
	public $class     = '';
	public $ships     = true;
	public $price     = 0.0;

	public function __construct( $id, array $args = array() ) {
		$this->id = (int) $id;
		foreach ( $args as $key => $value ) {
			$this->$key = $value;
		}
	}

	public function get_id() {
		return $this->id;
	}

	public function get_parent_id() {
		return (int) $this->parent_id;
	}

	public function get_shipping_class() {
		return (string) $this->class;
	}

	public function needs_shipping() {
		return (bool) $this->ships;
	}

	public function set_price( $price ) {
		$this->price = (float) $price;
	}

	public function get_price() {
		return $this->price;
	}
}

class Fh_Item {

	public $id;
	public $order_id     = 0;
	public $name         = '';
	public $qty          = 1;
	public $subtotal     = 0.0;
	public $total        = 0.0;
	public $tax          = 0.0;
	public $product_id   = 0;
	public $variation_id = 0;
	public $meta         = array();
	public $type         = 'line_item';

	public function __construct( $id, array $args = array() ) {
		$this->id = (int) $id;
		foreach ( $args as $key => $value ) {
			$this->$key = $value;
		}
		if ( ! array_key_exists( 'subtotal', $args ) ) {
			$this->subtotal = $this->total;
		}
	}

	public function get_id() {
		return $this->id;
	}

	public function get_order_id() {
		return (int) $this->order_id;
	}

	public function get_name() {
		return $this->name;
	}

	public function get_quantity() {
		return (int) $this->qty;
	}

	public function get_subtotal() {
		return (string) $this->subtotal;
	}

	public function get_total() {
		return (string) $this->total;
	}

	public function get_total_tax() {
		return (string) $this->tax;
	}

	public function get_product_id() {
		return (int) $this->product_id;
	}

	public function get_variation_id() {
		return (int) $this->variation_id;
	}

	public function get_meta( $key ) {
		return array_key_exists( $key, $this->meta ) ? $this->meta[ $key ] : '';
	}

	public function get_product() {
		$id = $this->variation_id > 0 ? $this->variation_id : $this->product_id;
		return $id > 0 && isset( $GLOBALS['fh_products'][ $id ] ) ? $GLOBALS['fh_products'][ $id ] : false;
	}

	public function is_type( $type ) {
		return $this->type === $type;
	}
}

class Fh_Ship_Line {

	public $method_id;
	public $instance_id;
	public $name;

	public function __construct( $method_id = 'table_rate', $instance_id = 4, $name = 'Overnight' ) {
		$this->method_id   = $method_id;
		$this->instance_id = $instance_id;
		$this->name        = $name;
	}

	public function get_method_id() {
		return $this->method_id;
	}

	public function get_instance_id() {
		return $this->instance_id;
	}

	public function get_name() {
		return $this->name;
	}
}

class Fh_Order {

	public $id;
	public $status         = 'processing';
	public $date_paid      = null;
	public $date_created   = 0;
	public $paid_flag      = false;
	public $meta           = array();
	public $items          = array();
	public $fees           = array();
	public $ship_lines     = array();
	public $shipping_total = 0.0;
	public $shipping_tax   = 0.0;
	public $total          = 0.0;
	public $refunded       = 0.0;
	public $refunded_qty   = array();
	public $first_name     = 'Avery';
	public $last_name      = 'Example';
	public $email          = 'avery@example.test';
	public $customer_id    = 0;
	public $country        = 'US';
	public $state          = 'IL';
	public $postcode       = '60000';
	public $coupons        = array();
	public $notes          = array();

	public function __construct( $id ) {
		$this->id = (int) $id;
	}

	public function get_id() {
		return $this->id;
	}

	public function get_order_number() {
		return (string) $this->id;
	}

	public function get_status() {
		return $this->status;
	}

	public function get_date_paid() {
		return null !== $this->date_paid ? new Fh_Date( $this->date_paid ) : null;
	}

	public function get_date_created() {
		return new Fh_Date( $this->date_created );
	}

	public function is_paid() {
		return (bool) $this->paid_flag;
	}

	public function get_meta( $key ) {
		return array_key_exists( $key, $this->meta ) ? $this->meta[ $key ] : '';
	}

	public function get_items( $type = 'line_item' ) {
		if ( 'fee' === $type ) {
			return $this->fees;
		}
		if ( 'shipping' === $type ) {
			return $this->ship_lines;
		}
		return $this->items;
	}

	public function get_qty_refunded_for_item( $item_id ) {
		return -1 * (int) ( $this->refunded_qty[ $item_id ] ?? 0 ); // WooCommerce reports refunds as negatives.
	}

	public function get_billing_first_name() {
		return $this->first_name;
	}

	public function get_billing_last_name() {
		return $this->last_name;
	}

	public function get_billing_email() {
		return $this->email;
	}

	public function get_customer_id() {
		return (int) $this->customer_id;
	}

	public function get_currency() {
		return 'USD';
	}

	public function get_total() {
		return (string) $this->total;
	}

	public function get_total_refunded() {
		return (string) $this->refunded;
	}

	public function get_shipping_total() {
		return (string) $this->shipping_total;
	}

	public function get_shipping_tax() {
		return (string) $this->shipping_tax;
	}

	public function get_edit_order_url() {
		return 'https://example.test/wp-admin/admin.php?page=wc-orders&action=edit&id=' . $this->id;
	}

	public function get_coupon_codes() {
		return $this->coupons;
	}

	public function get_shipping_country() {
		return $this->country;
	}

	public function get_shipping_state() {
		return $this->state;
	}

	public function get_shipping_postcode() {
		return $this->postcode;
	}

	public function get_shipping_city() {
		return 'Sampleville';
	}

	public function get_shipping_address_1() {
		return '1 Sample Street';
	}

	public function get_shipping_address_2() {
		return '';
	}

	public function add_order_note( $note, $is_customer_note = 0, $added_by_user = false ) {
		$this->notes[] = array(
			'note'     => (string) $note,
			'customer' => (int) $is_customer_note,
			'by_user'  => (bool) $added_by_user,
		);
		return count( $this->notes );
	}
}

function wc_get_order( $id ) {
	$GLOBALS['fh_wc_loads'][] = (int) $id;
	return isset( $GLOBALS['fh_orders'][ (int) $id ] ) ? $GLOBALS['fh_orders'][ (int) $id ] : false;
}

function wc_get_orders( $args = array() ) {
	$GLOBALS['fh_wc_queries'][] = $args;

	$statuses = array();
	foreach ( (array) ( $args['status'] ?? array() ) as $status ) {
		$statuses[] = preg_replace( '/^wc-/', '', (string) $status );
	}

	$found = array();
	foreach ( $GLOBALS['fh_orders'] as $order ) {
		if ( $statuses && ! in_array( $order->get_status(), $statuses, true ) ) {
			continue;
		}
		$found[] = $order;
	}
	usort(
		$found,
		static function ( $a, $b ) {
			return $b->get_id() <=> $a->get_id();
		}
	);
	if ( isset( $args['limit'] ) && (int) $args['limit'] > 0 ) {
		$found = array_slice( $found, ( max( 1, (int) ( $args['page'] ?? 1 ) ) - 1 ) * (int) $args['limit'], (int) $args['limit'] );
	}

	if ( 'ids' === ( $args['return'] ?? '' ) ) {
		return array_map(
			static function ( $order ) {
				return $order->get_id();
			},
			$found
		);
	}
	return $found;
}

function wc_get_order_statuses() {
	return array(
		'wc-pending'     => 'Pending payment',
		'wc-processing'  => 'Processing',
		'wc-on-hold'     => 'On hold',
		'wc-shipped'     => 'Shipped',
		'wc-fulfillment' => 'Fulfillment',
		'wc-completed'   => 'Completed',
		'wc-cancelled'   => 'Cancelled',
		'wc-refunded'    => 'Refunded',
		'wc-failed'      => 'Failed',
	);
}

function wc_get_product( $id ) {
	return isset( $GLOBALS['fh_products'][ (int) $id ] ) ? $GLOBALS['fh_products'][ (int) $id ] : false;
}

function get_woocommerce_currency_symbol( $currency = '' ) {
	return '&#36;';
}

/**
 * Register a product.
 *
 * @param int   $id   Product id.
 * @param array $args class, ships, parent_id.
 * @return Fh_Product
 */
function fh_product( $id, array $args = array() ) {
	$GLOBALS['fh_products'][ (int) $id ] = new Fh_Product( $id, $args );
	return $GLOBALS['fh_products'][ (int) $id ];
}

/**
 * Register an order.
 *
 * @param int   $id   Order id.
 * @param array $args Any public property of Fh_Order; `lines` = a list of Fh_Item argument arrays keyed by item id;
 *                    `paid` = the paid timestamp (shorthand for date_paid); `shipping` = shipping_total.
 * @return Fh_Order
 */
function fh_order( $id, array $args = array() ) {
	$order = new Fh_Order( $id );

	if ( array_key_exists( 'paid', $args ) ) {
		$order->date_paid = $args['paid'];
		unset( $args['paid'] );
	}
	if ( array_key_exists( 'shipping', $args ) ) {
		$order->shipping_total = $args['shipping'];
		$order->ship_lines     = array( 1 => new Fh_Ship_Line() );
		unset( $args['shipping'] );
	}
	foreach ( (array) ( $args['lines'] ?? array() ) as $item_id => $line ) {
		$order->items[ (int) $item_id ] = new Fh_Item( $item_id, array_merge( array( 'order_id' => (int) $id ), $line ) );
	}
	unset( $args['lines'] );
	foreach ( $args as $key => $value ) {
		$order->$key = $value;
	}

	if ( ! array_key_exists( 'total', $args ) ) {
		$sum = (float) $order->shipping_total + (float) $order->shipping_tax;
		foreach ( $order->items as $item ) {
			$sum += (float) $item->total + (float) $item->tax;
		}
		$order->total = $sum;
	}

	$GLOBALS['fh_orders'][ (int) $id ] = $order;
	return $order;
}

/**
 * The notes of an order, as plain strings.
 *
 * @param int $order_id Order id.
 * @return string[]
 */
function fh_notes( $order_id ) {
	$out = array();
	foreach ( $GLOBALS['fh_orders'][ (int) $order_id ]->notes as $note ) {
		$out[] = $note['note'];
	}
	return $out;
}

/* ------------------------------------------------------------------ */
/*  The store, in memory                                               */
/* ------------------------------------------------------------------ */

class Fh_Fake_Store {

	/** @var array id => case row (as the database would hold it) */
	public $rows = array();

	/** @var array[] log lines */
	public $log = array();

	/** @var int */
	public $next = 1;

	/** @var int[]|null What orders_with_lines() answers (null = "cannot say"). */
	public $prefilter = null;

	/** @var array[] The calls to orders_with_lines(). */
	public $prefilter_calls = array();

	/** @var array method => true makes that method fail. */
	public $fail = array();

	private static function defaults() {
		$row = array();
		foreach ( Store::CASE_COLUMNS as $column => $type ) {
			$row[ $column ] = '?' === $type[0] ? null : ( 'd' === substr( $type, -1 ) ? 0 : '' );
		}
		$row['status']       = 'open';
		$row['origin']       = 'auto';
		$row['qty']          = 1;
		$row['paid_known']   = 1;
		$row['ship_status']  = 'unknown';
		$row['offer_cancel'] = 1;
		return $row;
	}

	public function insert_case( array $fields ) {
		if ( ! empty( $this->fail['insert_case'] ) ) {
			return false;
		}
		$now  = gmdate( 'Y-m-d H:i:s' );
		$data = Store::clean_fields(
			array_merge(
				array(
					'created_at' => $now,
					'updated_at' => $now,
				),
				$fields
			)
		);
		$id                = $this->next++;
		$this->rows[ $id ] = array_merge( array( 'id' => $id ), self::defaults(), $data );
		return $id;
	}

	public function get_case( $id ) {
		return isset( $this->rows[ (int) $id ] ) ? Store::hydrate_case( $this->rows[ (int) $id ] ) : null;
	}

	public function cases( array $filters = array() ) {
		$statuses = ! empty( $filters['status'] ) ? array_map( 'strval', (array) $filters['status'] ) : null;
		$out      = array();
		foreach ( array_reverse( $this->rows, true ) as $row ) {
			if ( null !== $statuses && ! in_array( $row['status'], $statuses, true ) ) {
				continue;
			}
			if ( ! empty( $filters['order_id'] ) && (int) $row['order_id'] !== (int) $filters['order_id'] ) {
				continue;
			}
			if ( isset( $filters['order_ids'] ) && ! in_array( (int) $row['order_id'], array_map( 'intval', (array) $filters['order_ids'] ), true ) ) {
				continue;
			}
			if ( isset( $filters['kind_key'] ) && '' !== (string) $filters['kind_key'] && $row['kind_key'] !== (string) $filters['kind_key'] ) {
				continue;
			}
			if ( ! empty( $filters['loss_event_id'] ) && (int) $row['loss_event_id'] !== (int) $filters['loss_event_id'] ) {
				continue;
			}
			if ( ! empty( $filters['loss_event'] ) && false === strpos( (string) $row['loss_events'], ',' . (int) $filters['loss_event'] . ':' ) ) {
				continue;
			}
			$out[] = Store::hydrate_case( $row );
		}
		return isset( $filters['limit'] ) ? array_slice( $out, 0, (int) $filters['limit'] ) : $out;
	}

	public function update_case( $id, array $fields ) {
		unset( $fields['status'] );
		if ( ! isset( $this->rows[ (int) $id ] ) ) {
			return false;
		}
		$this->rows[ (int) $id ] = array_merge( $this->rows[ (int) $id ], Store::clean_fields( $fields ), array( 'updated_at' => gmdate( 'Y-m-d H:i:s' ) ) );
		return true;
	}

	public function transition( $id, array $from, $to, array $fields = array() ) {
		if ( ! empty( $this->fail['transition'] ) || ! isset( $this->rows[ (int) $id ] ) || ! in_array( $this->rows[ (int) $id ]['status'], $from, true ) ) {
			return false;
		}
		$fields['status']        = (string) $to;
		$this->rows[ (int) $id ] = array_merge( $this->rows[ (int) $id ], Store::clean_fields( $fields ), array( 'updated_at' => gmdate( 'Y-m-d H:i:s' ) ) );
		return true;
	}

	public function mark_reminded( $id, $link_expires ) {
		if ( ! isset( $this->rows[ (int) $id ] ) || 'sent' !== $this->rows[ (int) $id ]['status'] || null !== $this->rows[ (int) $id ]['reminded_at'] ) {
			return false;
		}
		$this->rows[ (int) $id ]['reminded_at']  = gmdate( 'Y-m-d H:i:s' );
		$this->rows[ (int) $id ]['link_expires'] = (string) $link_expires;
		return true;
	}

	public function cases_to_remind( $sent_before ) {
		$out = array();
		foreach ( $this->rows as $row ) {
			if ( 'sent' === $row['status'] && null === $row['reminded_at'] && null !== $row['sent_at'] && strcmp( $row['sent_at'], (string) $sent_before ) <= 0 ) {
				$out[] = Store::hydrate_case( $row );
			}
		}
		return $out;
	}

	public function cases_to_autosend( $created_before ) {
		$out = array();
		foreach ( $this->rows as $row ) {
			if ( 'open' === $row['status'] && 'auto' === $row['origin'] && null !== $row['loss_at'] && strcmp( $row['loss_at'], (string) $created_before ) <= 0 ) {
				$out[] = Store::hydrate_case( $row );
			}
		}
		return $out;
	}

	public function refresh_counts() {
		$counts = array(
			'open'     => 0,
			'sent'     => 0,
			'answered' => 0,
			'done'     => 0,
			'closed'   => 0,
			'stale'    => 0,
		);
		foreach ( $this->rows as $row ) {
			$counts[ $row['status'] ]++;
			if ( 'sent' === $row['status'] && ( null !== $row['reminded_at'] || ( null !== $row['link_expires'] && strcmp( $row['link_expires'], gmdate( 'Y-m-d H:i:s' ) ) < 0 ) ) ) {
				$counts['stale']++;
			}
		}
		update_option( Store::COUNTS_OPTION, $counts, true );
		return $counts;
	}

	public function add_log( $event, $message, $case_id = 0, $order_id = 0, $user_id = 0 ) {
		$this->log[] = array(
			'id'         => count( $this->log ) + 1,
			'case_id'    => (int) $case_id,
			'order_id'   => (int) $order_id,
			'event'      => (string) $event,
			'message'    => (string) $message,
			'user_id'    => (int) $user_id,
			'created_at' => gmdate( 'Y-m-d H:i:s' ),
		);
		return true;
	}

	public function log_rows( $limit = 200, $case_id = 0 ) {
		$out = array();
		foreach ( array_reverse( $this->log ) as $row ) {
			if ( (int) $case_id > 0 && $row['case_id'] !== (int) $case_id ) {
				continue;
			}
			$out[] = $row;
		}
		return array_slice( $out, 0, (int) $limit );
	}

	public function orders_with_lines( array $order_ids, array $product_ids ) {
		$this->prefilter_calls[] = array( $order_ids, $product_ids );
		return $this->prefilter;
	}

	/** @var string[] "lock <name>" / "unlock <name>", in order. */
	public $locks = array();

	public function lock( $name, $seconds = 3 ) {
		$this->locks[] = 'lock ' . $name;
		return empty( $this->fail['lock'] );
	}

	public function unlock( $name ) {
		$this->locks[] = 'unlock ' . $name;
	}

	/**
	 * The log's events, oldest first.
	 *
	 * @return string[]
	 */
	public function events() {
		$out = array();
		foreach ( $this->log as $row ) {
			$out[] = $row['event'];
		}
		return $out;
	}
}

/* ------------------------------------------------------------------ */
/*  The Clean Room, as a list of tank groups                           */
/* ------------------------------------------------------------------ */

class Fh_Fake_Rooms {

	/** @var array id => stay */
	public $stays = array();

	/** @var bool */
	public $available = true;

	/** @var int */
	public $window = 900;

	/** @var array product id => title */
	public $titles = array();

	public function available() {
		return $this->available;
	}

	public function undo_window() {
		return $this->window;
	}

	public function stay( $stay_id ) {
		return $this->available && isset( $this->stays[ (int) $stay_id ] ) ? $this->stays[ (int) $stay_id ] : null;
	}

	/**
	 * As the real one: a product's open groups, or the open groups whose
	 * species contains the name — the exact rule is Cover's.
	 */
	public function open_stays( array $kind ) {
		$out = array();
		foreach ( $this->available ? $this->stays : array() as $stay ) {
			if ( 'closed' === $stay['status'] ) {
				continue;
			}
			if ( (int) $kind['product_id'] > 0 ) {
				if ( (int) $stay['product_id'] === (int) $kind['product_id'] ) {
					$out[] = $stay;
				}
			} elseif ( false !== stripos( (string) $stay['species'], (string) $kind['species'] ) ) {
				$out[] = $stay;
			}
		}
		return $out;
	}

	public function product_title( $product_id ) {
		return isset( $this->titles[ (int) $product_id ] ) ? $this->titles[ (int) $product_id ] : '';
	}

	public function stays_url( array $stay ) {
		return 'https://example.test/wp-admin/admin.php?page=fishotel-misc-clean-room&tab=stays';
	}

	/**
	 * Add a tank group.
	 *
	 * @param int   $id   Stay id.
	 * @param array $args product_id, species, size_slug, size_label, qty_live, status, needs_id, tank_number.
	 * @return array
	 */
	public function add( $id, array $args ) {
		$this->stays[ (int) $id ] = array_merge(
			array(
				'id'          => (int) $id,
				'product_id'  => 0,
				'species'     => '',
				'size_slug'   => '',
				'size_label'  => '',
				'qty_live'    => 0,
				'status'      => 'in_qt',
				'needs_id'    => false,
				'tank_number' => 521,
			),
			$args
		);
		return $this->stays[ (int) $id ];
	}

	/**
	 * A loss on a group: that many fewer alive (closing it at zero).
	 *
	 * @param int $id  Stay id.
	 * @param int $qty Fish lost.
	 */
	public function lose( $id, $qty = 1 ) {
		if ( ! isset( $this->stays[ (int) $id ] ) ) {
			return;
		}
		$this->stays[ (int) $id ]['qty_live'] = max( 0, (int) $this->stays[ (int) $id ]['qty_live'] - (int) $qty );
		if ( 0 === $this->stays[ (int) $id ]['qty_live'] ) {
			$this->stays[ (int) $id ]['status'] = 'closed';
		}
	}
}

/**
 * The Clean Room records a loss: the group has that many fewer, and the
 * action fires as Store::record_event() fires it.
 */
function fh_loss( Fh_Fake_Rooms $rooms, $stay_id, $event_id, $qty = 1, $source = 'qr' ) {
	$rooms->lose( $stay_id, $qty );
	do_action(
		'fishotel_cleanroom_event_recorded',
		$stay_id,
		$event_id,
		array(
			'type'     => 'loss',
			'qty'      => $qty,
			'note'     => 'disease',
			'source'   => $source,
			'actor_id' => 7,
		),
		array()
	);
}

/**
 * The Clean Room undoes that loss: the fish are back, and the
 * compensating event fires.
 */
function fh_undo( Fh_Fake_Rooms $rooms, $stay_id, $event_id, $qty = 1 ) {
	$rooms->stays[ $stay_id ]['qty_live'] += $qty;
	$rooms->stays[ $stay_id ]['status']    = 'in_qt';
	do_action(
		'fishotel_cleanroom_event_recorded',
		$stay_id,
		$event_id + 1000,
		array(
			'type'     => 'loss',
			'qty'      => -$qty,
			'note'     => 'undo of #' . $event_id,
			'source'   => 'qr',
			'actor_id' => 7,
		),
		array()
	);
}

/**
 * What the Cases screen posts with a form of a case: the case as the
 * page shows it (Admin::card() brings an open case up to date first).
 *
 * @param Cases $cases   The cases.
 * @param int   $case_id Case id.
 * @return string The "seen" field.
 */
function fh_seen( Cases $cases, $case_id ) {
	$card = \FisHotel\Misc\Sections\Lost_Fish\Admin::card( $cases, $cases->store()->get_case( (int) $case_id ), \FisHotel\Misc\Sections\Lost_Fish\Settings::get() );
	return Cases::stamp( $card['case'] );
}

/**
 * The real Cases on the in-memory store, the real Orders over the
 * WooCommerce stand-ins and the tank groups given.
 *
 * @param Fh_Fake_Store|null $store Store.
 * @param Fh_Fake_Rooms|null $rooms Rooms.
 * @return Cases
 */
function fh_cases( $store = null, $rooms = null ) {
	$store = $store ? $store : new Fh_Fake_Store();
	return new Cases( $store, new Orders( $store ), $rooms ? $rooms : new Fh_Fake_Rooms() );
}

/**
 * Forget everything between two scenarios: orders, products, mail, cron,
 * fired actions, options.
 */
function fh_reset() {
	$GLOBALS['fh_orders']       = array();
	$GLOBALS['fh_products']     = array();
	$GLOBALS['fh_wc_queries']   = array();
	$GLOBALS['fh_wc_loads']     = array();
	$GLOBALS['fh_mail']         = array();
	$GLOBALS['fh_mail_fail']    = false;
	$GLOBALS['fh_cron']         = array();
	$GLOBALS['fh_fired']        = array();
	$GLOBALS['fh_transients']   = array();
	$GLOBALS['fh_options']      = array( 'blogname' => 'Example Reef Shop' );
	$GLOBALS['fh_current_user'] = 0;
	\FisHotel\Misc\Sections\Lost_Fish\Shipping::$rater = null;
	\FisHotel\Misc\Sections\Lost_Fish\Lost_Fish::$today = null;
}

/**
 * The invented shipping rule the tests price with: goods that ship in the
 * "premium" class cost 40.00, or 15.00 once they come to 480.00 or more;
 * a package of "free" class goods ships for nothing.
 *
 * @param object $order Order (unused).
 * @param array  $rows  Shipping::model() rows that ship.
 * @return int Cents.
 */
function fh_rater( $order, array $rows ) {
	$premium = 0;
	$any     = false;
	foreach ( $rows as $row ) {
		if ( 'free' !== $row['class'] ) {
			$any      = true;
			$premium += (int) $row['total'];
		}
	}
	if ( ! $any ) {
		return 0;
	}
	return $premium >= 48000 ? 1500 : 4000;
}
