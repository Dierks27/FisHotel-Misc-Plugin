<?php
/**
 * Just enough WooCommerce for the Gift Cards checkout, product and account
 * tests: WC() with a session and a cart (fees through a fees API, the
 * totals a test sets), orders in memory (fees with meta, line items,
 * notes, statuses that fire the status hooks), products with meta, and
 * the helpers the section calls. Loaded after wp-stubs.php. Every name,
 * amount and order here is invented. Exits at once when run directly.
 *
 * @package FisHotel\Misc\Sections\Gift_Cards
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( 0 );
}

class Fh_Session {
	public $data = array();
	public function get( $k, $d = null ) {
		return array_key_exists( $k, $this->data ) ? $this->data[ $k ] : $d;
	}
	public function set( $k, $v ) {
		$this->data[ $k ] = $v;
	}
}

class Fh_Fees_Api {
	public $fees = array();
	public function add_fee( $args ) {
		$fee                    = (object) $args;
		$this->fees[ $fee->id ] = $fee;
		return $fee;
	}
}

class Fh_Cart {
	public $contents_total = 0.0;
	public $shipping_total = 0.0;
	public $items          = array();
	public $api;
	public function __construct() {
		$this->api = new Fh_Fees_Api();
	}
	public function fees_api() {
		return $this->api;
	}
	public function get_fees() {
		return $this->api->fees;
	}
	public function get_cart_contents_total() {
		return $this->contents_total;
	}
	public function get_shipping_total() {
		return $this->shipping_total;
	}
	public function get_cart() {
		return $this->items;
	}
	/** WooCommerce's calculate_fees: start empty, ask the hook. */
	public function calculate_fees() {
		$this->api->fees = array();
		do_action( 'woocommerce_cart_calculate_fees', $this );
	}
}

class Fh_WC {
	public $session;
	public $cart;
	public function __construct() {
		$this->session = new Fh_Session();
		$this->cart    = new Fh_Cart();
	}
}

$GLOBALS['fh_wc_main'] = new Fh_WC();

function WC() { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName
	return $GLOBALS['fh_wc_main'];
}

function fh_wc_reset() {
	$GLOBALS['fh_wc_main'] = new Fh_WC();
	$GLOBALS['fh_orders']  = array();
	$GLOBALS['fh_notices'] = array();
	$GLOBALS['fh_post_meta'] = array();
	$GLOBALS['fh_products']  = array();
}

/**
 * A fee item on an order.
 */
class Fh_Fee_Item {
	public $id;
	public $total;
	public $meta = array();
	public function __construct( $id, $total, array $meta = array() ) {
		$this->id    = $id;
		$this->total = $total;
		$this->meta  = $meta;
	}
	public function get_total() {
		return $this->total;
	}
	public function get_meta( $k ) {
		return $this->meta[ $k ] ?? '';
	}
	public function add_meta_data( $k, $v, $unique = false ) {
		$this->meta[ $k ] = $v;
	}
}

/**
 * A product line on an order.
 */
class Fh_Line_Item {
	public $meta = array();
	public $qty;
	public $product_id;
	public function __construct( $product_id, $qty, array $meta = array() ) {
		$this->product_id = $product_id;
		$this->qty        = $qty;
		$this->meta       = $meta;
	}
	public function get_meta( $k ) {
		return $this->meta[ $k ] ?? '';
	}
	public function add_meta_data( $k, $v, $unique = false ) {
		$this->meta[ $k ] = $v;
	}
	public function get_quantity() {
		return $this->qty;
	}
	public function get_product_id() {
		return $this->product_id;
	}
}

/**
 * A refund of an order (only its id is read).
 */
class Fh_Refund {
	public $id;
	public function __construct( $id ) {
		$this->id = $id;
	}
	public function get_id() {
		return $this->id;
	}
}

class Fh_Order {
	public $id;
	public $status      = 'pending';
	public $customer_id = 0;
	public $total       = 0.0;
	public $fees        = array();
	public $items       = array();
	public $meta        = array();
	public $notes       = array();
	public $paid        = null;
	public $billing     = array( 'first' => 'Pat', 'last' => 'Tester', 'email' => 'pat@example.test' );
	public function get_id() {
		return $this->id;
	}
	public function get_fees() {
		return $this->fees;
	}
	public function get_items( $type = 'line_item' ) {
		return $this->items;
	}
	/** Item id => quantity refunded (WooCommerce gives it negative). */
	public $refunded_qty = array();
	public function get_qty_refunded_for_item( $item_id, $type = 'line_item' ) {
		return -abs( (int) ( $this->refunded_qty[ $item_id ] ?? 0 ) );
	}
	/** Its refunds, newest first (as WooCommerce gives them). */
	public $refunds = array();
	public function get_refunds() {
		return $this->refunds;
	}
	public function get_meta( $k ) {
		return $this->meta[ $k ] ?? '';
	}
	public function update_meta_data( $k, $v ) {
		$this->meta[ $k ] = $v;
	}
	public function get_customer_id() {
		return $this->customer_id;
	}
	public function get_status() {
		return $this->status;
	}
	public function get_date_paid() {
		return $this->paid;
	}
	public function is_paid() {
		return in_array( $this->status, array( 'processing', 'completed' ), true );
	}
	public function get_total() {
		return $this->total;
	}
	public function set_total( $t ) {
		$this->total = (float) $t;
	}
	public function get_currency() {
		return 'USD';
	}
	public function get_billing_first_name() {
		return $this->billing['first'];
	}
	public function get_billing_last_name() {
		return $this->billing['last'];
	}
	public function get_billing_email() {
		return $this->billing['email'];
	}
	public function add_order_note( $note ) {
		$this->notes[] = $note;
	}
	/** Like WooCommerce: change, then fire the status hook. */
	public function update_status( $to, $note = '' ) {
		if ( '' !== $note ) {
			$this->notes[] = $note;
		}
		$this->status = $to;
		if ( in_array( $to, array( 'processing', 'completed' ), true ) && ! $this->paid ) {
			$this->paid = '2026-03-02 15:00:00';
		}
		do_action( 'woocommerce_order_status_' . $to, $this->id, $this );
	}
}

$GLOBALS['fh_orders'] = array();

function fh_order( $id, array $props = array() ) {
	$o     = new Fh_Order();
	$o->id = (int) $id;
	foreach ( $props as $k => $v ) {
		$o->$k = $v;
	}
	$GLOBALS['fh_orders'][ (int) $id ] = $o;
	return $o;
}

/**
 * Give an order line items (two of $qty if it has none) and refund the
 * given quantities (item id => qty; null = every item in full).
 */
function fh_refund_items( $order, $qty = null ) {
	if ( ! $order->items ) {
		$order->items = array(
			91 => new Fh_Line_Item( 501, 2 ),
			92 => new Fh_Line_Item( 502, 1 ),
		);
	}
	foreach ( $order->items as $id => $item ) {
		$order->refunded_qty[ $id ] = null === $qty ? $item->get_quantity() : (int) ( $qty[ $id ] ?? 0 );
	}
	array_unshift( $order->refunds, new Fh_Refund( 7000 + $order->id * 10 + count( $order->refunds ) ) );
	return false;
}

function wc_get_order( $id ) {
	return $GLOBALS['fh_orders'][ (int) $id ] ?? false;
}

function wc_price( $amount, $args = array() ) {
	return '$' . number_format( (float) $amount, 2 );
}

$GLOBALS['fh_notices'] = array();

function wc_add_notice( $message, $type = 'success' ) {
	$GLOBALS['fh_notices'][] = array( $type, $message );
}

$GLOBALS['fh_post_meta'] = array();

function get_post_meta( $id, $key = '', $single = false ) {
	return $GLOBALS['fh_post_meta'][ (int) $id ][ $key ] ?? '';
}

function get_post_type( $id ) {
	return isset( $GLOBALS['fh_orders'][ (int) $id ] ) ? 'shop_order' : 'post';
}

function get_permalink( $id ) {
	return 'https://example.test/?p=' . (int) $id;
}

class Fh_Product {
	public $id;
	public $parent = 0;
	public $price;
	public function __construct( $id, $price, $parent = 0 ) {
		$this->id     = $id;
		$this->price  = $price;
		$this->parent = $parent;
	}
	public function get_id() {
		return $this->id;
	}
	public function get_parent_id() {
		return $this->parent;
	}
	public function get_price() {
		return $this->price;
	}
	public function set_price( $p ) {
		$this->price = $p;
	}
	public function update_meta_data( $k, $v ) {
		$GLOBALS['fh_post_meta'][ $this->id ][ $k ] = $v;
	}
}

$GLOBALS['fh_products'] = array();

function fh_product( $id, $price, array $meta = array(), $parent = 0 ) {
	$p                                   = new Fh_Product( $id, $price, $parent );
	$GLOBALS['fh_products'][ (int) $id ] = $p;
	foreach ( $meta as $k => $v ) {
		$GLOBALS['fh_post_meta'][ (int) $id ][ $k ] = $v;
	}
	return $p;
}

function wc_get_product( $id ) {
	return $GLOBALS['fh_products'][ (int) $id ] ?? false;
}

function is_checkout() {
	return true;
}

function is_account_page() {
	return true;
}

function is_product() {
	return false;
}

function wc_get_account_endpoint_url( $endpoint ) {
	return 'https://example.test/my-account/' . $endpoint . '/';
}

function set_transient_reset() {
	$GLOBALS['fh_transients'] = array();
}

foreach ( array( 'cron', 'checkout', 'product', 'account', 'gift-cards' ) as $fh_file ) {
	require_once FISHOTEL_MISC_PATH . 'includes/sections/gift-cards/class-' . $fh_file . '.php';
}

/**
 * A fresh service that the section's classes use (Gift_Cards::service()).
 */
function fh_gc_use( array $settings = array(), array $world = array() ) {
	$s = fh_gc_fresh( $settings, $world );
	\FisHotel\Misc\Sections\Gift_Cards\Gift_Cards::use_service( $s );
	return $s;
}

/**
 * Put an order through WooCommerce's checkout as the section sees it:
 * the cart's fees are calculated, the order gets the cart's gift card fee
 * (tagged), then woocommerce_checkout_order_created fires.
 *
 * @return Fh_Order|string The order, or the checkout's error message.
 */
function fh_checkout( $checkout, $order_id, $customer, $goods ) {
	$cart                 = WC()->cart;
	$cart->contents_total = $goods;
	$cart->calculate_fees();
	$order = fh_order( $order_id, array( 'customer_id' => $customer, 'total' => $goods ) );
	foreach ( $cart->get_fees() as $fee ) {
		$item = new Fh_Fee_Item( $fee->id, $fee->amount );
		$checkout->tag_fee( $item, $fee->id, $fee, $order );
		$order->fees[] = $item;
		$order->total += $fee->amount;
	}
	$checkout->remember_codes( $order, array() );
	try {
		$checkout->debit( $order );
	} catch ( \Exception $e ) {
		return $e->getMessage();
	}
	return $order;
}
