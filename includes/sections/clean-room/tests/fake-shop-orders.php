<?php
/**
 * Just enough of WooCommerce's orders to drive Ship_Lines headless — and,
 * through it, the readers it asks (the Weather section's order query, the
 * Lost Fish section's lines, the Today section's fish categories): orders
 * with meta, a status and a creation time; line items with a product, a
 * variation, meta and a refund; products with categories, a parent and
 * attributes; and the registry wc_get_order() / wc_get_orders() /
 * get_the_terms() / get_the_title() answer from. Nothing is persisted.
 * Every name, id and fish is invented.
 *
 * @package FisHotel\Misc\Sections\Clean_Room
 */

// Helper, not a test: the CI job runs every tests/*.php with php, so exit
// cleanly when executed directly instead of as a require.
if ( ! empty( $_SERVER['SCRIPT_FILENAME'] ) && realpath( $_SERVER['SCRIPT_FILENAME'] ) === __FILE__ ) {
	exit( 0 );
}

$GLOBALS['fh_shop_orders']   = array();
$GLOBALS['fh_shop_products'] = array();
$GLOBALS['fh_shop_queries']  = 0;
$GLOBALS['fh_shop_loads']    = 0;
$GLOBALS['fh_shop_items']    = array();

/**
 * A product: its categories live on the parent for a variation, as in
 * WooCommerce; a variation carries its attributes (pa_size => slug).
 */
class Fh_Shop_Product {

	private $id;
	private $parent_id;
	private $title;
	private $attributes;

	public function __construct( $id, $title, array $categories = array(), $parent_id = 0, array $attributes = array() ) {
		$this->id         = (int) $id;
		$this->parent_id  = (int) $parent_id;
		$this->title      = (string) $title;
		$this->attributes = $attributes;

		$GLOBALS['fh_shop_products'][ $this->id ] = array(
			'title'      => $this->title,
			'categories' => $categories,
		);
	}

	public function get_id() {
		return $this->id;
	}

	public function get_parent_id() {
		return $this->parent_id;
	}

	public function get_title() {
		return $this->title;
	}

	public function get_name() {
		return $this->title;
	}

	public function get_attributes() {
		return $this->attributes;
	}
}

/**
 * A line item.
 */
class Fh_Shop_Item {

	private $id;
	private $name;
	private $qty;
	private $product;
	private $meta;

	public function __construct( $id, $name, $qty, $product = null, array $meta = array() ) {
		$this->id      = (int) $id;
		$this->name    = (string) $name;
		$this->qty     = (int) $qty;
		$this->product = $product;
		$this->meta    = $meta;
	}

	public function get_id() {
		return $this->id;
	}

	public function get_name() {
		return $this->name;
	}

	public function get_quantity() {
		return $this->qty;
	}

	public function get_product() {
		return $this->product ? $this->product : false;
	}

	public function get_product_id() {
		if ( ! $this->product ) {
			return 0;
		}
		return $this->product->get_parent_id() > 0 ? $this->product->get_parent_id() : $this->product->get_id();
	}

	public function get_variation_id() {
		return $this->product && $this->product->get_parent_id() > 0 ? $this->product->get_id() : 0;
	}

	public function get_total() {
		return 10 * $this->qty;
	}

	public function get_total_tax() {
		return 0;
	}

	public function get_meta( $key, $single = true ) {
		return array_key_exists( $key, $this->meta ) ? $this->meta[ $key ] : '';
	}

	public function set_meta( $key, $value ) {
		$this->meta[ (string) $key ] = $value;
	}

	public function delete_meta( $key ) {
		unset( $this->meta[ (string) $key ] );
	}

	public function get_meta_data() {
		$out = array();
		foreach ( $this->meta as $key => $value ) {
			$out[] = (object) array(
				'key'   => $key,
				'value' => $value,
			);
		}
		return $out;
	}
}

/**
 * An order. Named WC_Order: the Weather section's query hands back only
 * what is one.
 */
class WC_Order {

	private $id;
	private $status;
	private $meta;
	private $items    = array();
	private $refunded = array();
	private $created;

	public function __construct( $id, $status = 'processing', array $meta = array(), $created = 0 ) {
		$this->id      = (int) $id;
		$this->status  = (string) $status;
		$this->meta    = $meta;
		$this->created = (int) $created;
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

	public function set_status( $status ) {
		$this->status = (string) $status;
	}

	public function has_status( $status ) {
		return in_array( $this->status, (array) $status, true );
	}

	public function get_meta( $key, $single = true ) {
		return array_key_exists( $key, $this->meta ) ? $this->meta[ $key ] : '';
	}

	public function update_meta_data( $key, $value ) {
		$this->meta[ $key ] = $value;
	}

	/**
	 * The customer's account and billing e-mail: the meta "_customer_user" and "_billing_email", as stored.
	 */
	public function get_customer_id() {
		return (int) $this->get_meta( '_customer_user' );
	}

	public function get_billing_email() {
		return (string) $this->get_meta( '_billing_email' );
	}

	public function get_date_created() {
		return $this->created > 0 ? new DateTimeImmutable( '@' . $this->created ) : null;
	}

	/**
	 * Paid when the meta "_paid_date" says when (a stand-in for WooCommerce's own paid date).
	 */
	public function get_date_paid() {
		$paid = $this->get_meta( '_paid_date' );
		return '' !== (string) $paid ? new DateTimeImmutable( '@' . (int) $paid ) : null;
	}

	/**
	 * Add a line.
	 *
	 * @param string               $name     Item name.
	 * @param int                  $qty      Quantity.
	 * @param Fh_Shop_Product|null $product  Product (null = a line without one).
	 * @param array                $meta     Item meta.
	 * @param int                  $refunded Quantity refunded.
	 * @return Fh_Shop_Item
	 */
	public function add_item( $name, $qty, $product = null, array $meta = array(), $refunded = 0 ) {
		$id                 = $this->id * 10 + count( $this->items ) + 1;
		$item               = new Fh_Shop_Item( $id, $name, $qty, $product, $meta );
		$this->items[ $id ] = $item;
		$GLOBALS['fh_shop_items'][ $id ] = $item;
		if ( $refunded ) {
			$this->refunded[ $id ] = -abs( (int) $refunded ); // WooCommerce reports refunds as negatives.
		}
		return $item;
	}

	public function get_items( $type = 'line_item' ) {
		return $this->items;
	}

	/**
	 * Refund some fish of a line (after the order was placed).
	 */
	public function refund_item( $item_id, $qty ) {
		$this->refunded[ (int) $item_id ] = -abs( (int) $qty );
	}

	public function get_qty_refunded_for_item( $item_id ) {
		return isset( $this->refunded[ (int) $item_id ] ) ? $this->refunded[ (int) $item_id ] : 0;
	}
}

/**
 * Item meta written straight onto an order item (the reservation), as
 * WooCommerce's own functions do — without saving the order. Each write
 * fires the item-meta action, as WooCommerce's metadata API does.
 */
function wc_update_order_item_meta( $item_id, $key, $value ) {
	if ( ! isset( $GLOBALS['fh_shop_items'][ (int) $item_id ] ) ) {
		return false;
	}
	$GLOBALS['fh_shop_items'][ (int) $item_id ]->set_meta( $key, $value );
	$GLOBALS['fh_item_meta_writes'] = ( $GLOBALS['fh_item_meta_writes'] ?? 0 ) + 1;
	if ( function_exists( 'do_action' ) ) {
		do_action( 'updated_order_item_meta', 0, (int) $item_id, (string) $key, $value );
	}
	return true;
}

function wc_delete_order_item_meta( $item_id, $key ) {
	if ( ! isset( $GLOBALS['fh_shop_items'][ (int) $item_id ] ) ) {
		return false;
	}
	$GLOBALS['fh_shop_items'][ (int) $item_id ]->delete_meta( $key );
	if ( function_exists( 'do_action' ) ) {
		do_action( 'deleted_order_item_meta', array(), (int) $item_id, (string) $key, '' );
	}
	return true;
}

function fh_shop_order( WC_Order $order ) {
	$GLOBALS['fh_shop_orders'][ $order->get_id() ] = $order;
	return $order;
}

function fh_shop_reset() {
	$GLOBALS['fh_shop_orders']  = array();
	$GLOBALS['fh_shop_queries'] = 0;
	$GLOBALS['fh_shop_loads']   = 0;
}

/**
 * Counts every call, so a test can say "no order was asked for".
 */
function wc_get_order( $id ) {
	$GLOBALS['fh_shop_loads']++;
	$id = (int) $id;
	return isset( $GLOBALS['fh_shop_orders'][ $id ] ) ? $GLOBALS['fh_shop_orders'][ $id ] : false;
}

/**
 * Honours `status` (a list, without "wc-"), as the shop does; the date is
 * checked again by the caller. Counts every call.
 */
function wc_get_orders( $args ) {
	$GLOBALS['fh_shop_queries']++;

	$statuses = isset( $args['status'] ) ? (array) $args['status'] : array();
	$out      = array();
	foreach ( $GLOBALS['fh_shop_orders'] as $order ) {
		if ( $statuses && ! $order->has_status( $statuses ) ) {
			continue;
		}
		$out[] = $order;
	}
	usort(
		$out,
		static function ( $a, $b ) {
			return $a->get_id() <=> $b->get_id();
		}
	);
	// The newest first when asked, and a limit when one is given — as the shop does with orderby ID.
	if ( isset( $args['order'] ) && 'DESC' === strtoupper( (string) $args['order'] ) ) {
		$out = array_reverse( $out );
	}
	if ( isset( $args['limit'] ) && (int) $args['limit'] > 0 ) {
		$out = array_slice( $out, 0, (int) $args['limit'] );
	}
	return $out;
}

function get_the_terms( $post_id, $taxonomy ) {
	if ( ! isset( $GLOBALS['fh_shop_products'][ (int) $post_id ] ) ) {
		return false;
	}
	$terms = array();
	foreach ( $GLOBALS['fh_shop_products'][ (int) $post_id ]['categories'] as $slug ) {
		$terms[] = (object) array( 'slug' => (string) $slug );
	}
	return $terms;
}

function get_the_title( $post_id = 0 ) {
	return isset( $GLOBALS['fh_shop_products'][ (int) $post_id ] ) ? $GLOBALS['fh_shop_products'][ (int) $post_id ]['title'] : '';
}
