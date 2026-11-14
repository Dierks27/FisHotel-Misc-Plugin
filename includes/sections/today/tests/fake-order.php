<?php
/**
 * Just enough WC_Order to drive the Collector headless: meta, status, the
 * shipping / billing address, line items with products and categories, the
 * customer note, the edit URL, and a registry that wc_get_order() /
 * wc_get_orders() / get_the_terms() answer from. Nothing is persisted.
 *
 * @package FisHotel\Misc\Sections\Today
 */

// Helper, not a test: the CI lint job runs every tests/*.php with php, so
// exit cleanly when executed directly instead of as a require.
if ( ! empty( $_SERVER['SCRIPT_FILENAME'] ) && realpath( $_SERVER['SCRIPT_FILENAME'] ) === __FILE__ ) {
	exit( 0 );
}

/**
 * A product with category slugs (a variation points at its parent, which
 * carries the categories — as in WooCommerce).
 */
class WC_Product {

	private $id;
	private $parent_id;

	public function __construct( $id, array $category_slugs = array(), $parent_id = 0 ) {
		$this->id        = (int) $id;
		$this->parent_id = (int) $parent_id;

		$GLOBALS['fh_product_cats'][ $this->id ] = $category_slugs;
	}

	public function get_id() {
		return $this->id;
	}

	public function get_parent_id() {
		return $this->parent_id;
	}
}

/**
 * A line item.
 */
class WC_Order_Item_Product {

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
		return $this->product;
	}

	public function get_meta( $key, $single = true ) {
		return array_key_exists( $key, $this->meta ) ? $this->meta[ $key ] : '';
	}
}

class WC_Order {

	private $id;
	private $status;
	private $meta  = array();
	private $items = array();
	private $shipping;
	private $billing;
	private $refunded = array();

	/**
	 * Customer note, as typed at checkout.
	 *
	 * @var string
	 */
	public $customer_note = '';

	/**
	 * @param int    $id       Order ID.
	 * @param string $status   Status without `wc-`.
	 * @param array  $meta     Initial meta.
	 * @param array  $shipping Shipping address (first_name, last_name, address_1, address_2, city, state, postcode, country).
	 * @param array  $billing  Billing address (same keys).
	 */
	public function __construct( $id, $status = 'processing', array $meta = array(), ?array $shipping = null, ?array $billing = null ) {
		$this->id       = (int) $id;
		$this->status   = (string) $status;
		$this->meta     = $meta;
		$this->shipping = null === $shipping ? self::default_address() : $shipping;
		$this->billing  = null === $billing ? $this->shipping : $billing;
	}

	public static function default_address() {
		return array(
			'first_name' => 'Dana',
			'last_name'  => 'Reed',
			'address_1'  => '14000 Carrollton Blvd',
			'address_2'  => '',
			'city'       => 'Carrollton',
			'state'      => 'VA',
			'postcode'   => '23314',
			'country'    => 'US',
		);
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

	public function has_status( $status ) {
		return in_array( $this->status, (array) $status, true );
	}

	public function get_meta( $key, $single = true ) {
		return array_key_exists( $key, $this->meta ) ? $this->meta[ $key ] : '';
	}

	public function update_meta_data( $key, $value ) {
		$this->meta[ $key ] = $value;
	}

	public function delete_meta_data( $key ) {
		unset( $this->meta[ $key ] );
	}

	public function get_edit_order_url() {
		return 'https://example.test/wp-admin/admin.php?page=wc-orders&action=edit&id=' . $this->id;
	}

	public function get_customer_note() {
		return $this->customer_note;
	}

	public function get_formatted_shipping_full_name() {
		return trim( $this->shipping['first_name'] . ' ' . ( $this->shipping['last_name'] ?? '' ) );
	}

	public function get_formatted_billing_full_name() {
		return trim( $this->billing['first_name'] . ' ' . ( $this->billing['last_name'] ?? '' ) );
	}

	/**
	 * Add a line item.
	 *
	 * @param string          $name    Item name.
	 * @param int             $qty     Quantity.
	 * @param WC_Product|null $product Product.
	 * @param array           $meta    Item meta (e.g. _fishotel_line_status).
	 * @param int             $refunded Quantity refunded (positive).
	 * @return WC_Order_Item_Product
	 */
	public function add_item( $name, $qty, $product = null, array $meta = array(), $refunded = 0 ) {
		$id            = 1000 + count( $this->items ) + 1 + $this->id * 10;
		$item          = new WC_Order_Item_Product( $id, $name, $qty, $product, $meta );
		$this->items[ $id ] = $item;
		if ( $refunded ) {
			$this->refunded[ $id ] = -abs( (int) $refunded ); // WC reports refunds as negatives.
		}
		return $item;
	}

	public function get_items( $type = 'line_item' ) {
		return $this->items;
	}

	public function get_qty_refunded_for_item( $item_id ) {
		return $this->refunded[ (int) $item_id ] ?? 0;
	}

	/**
	 * get_shipping_* / get_billing_* getters.
	 */
	public function __call( $name, $args ) {
		if ( 1 === preg_match( '/^get_(shipping|billing)_(.+)$/', $name, $m ) ) {
			$address = 'shipping' === $m[1] ? $this->shipping : $this->billing;
			return (string) ( $address[ $m[2] ] ?? '' );
		}
		throw new \BadMethodCallException( "WC_Order fake has no $name()" );
	}
}

/**
 * The registry wc_get_order() / wc_get_orders() answer from.
 */
$GLOBALS['fh_orders']       = array();
$GLOBALS['fh_product_cats'] = array();

function fh_register_order( WC_Order $order ) {
	$GLOBALS['fh_orders'][ $order->get_id() ] = $order;
	return $order;
}

function fh_reset_orders() {
	$GLOBALS['fh_orders']       = array();
	$GLOBALS['fh_product_cats'] = array();
}

function wc_get_order( $id ) {
	$id = (int) $id;
	return isset( $GLOBALS['fh_orders'][ $id ] ) ? $GLOBALS['fh_orders'][ $id ] : false;
}

/**
 * Counts every call so a test can assert "one query for the window".
 */
$GLOBALS['fh_order_queries'] = 0;

/**
 * Honours `status` (list, without `wc-`); the date filter the real HPOS
 * query would apply is re-checked in PHP by Checker::open_orders().
 */
function wc_get_orders( $args ) {
	$GLOBALS['fh_order_queries']++;

	$statuses = isset( $args['status'] ) ? (array) $args['status'] : array();
	$out      = array();

	foreach ( $GLOBALS['fh_orders'] as $order ) {
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

	return $out;
}

/**
 * Product categories, from the product registry.
 */
function get_the_terms( $post_id, $taxonomy ) {
	$slugs = $GLOBALS['fh_product_cats'][ (int) $post_id ] ?? null;
	if ( ! is_array( $slugs ) ) {
		return false;
	}
	$terms = array();
	foreach ( $slugs as $slug ) {
		$terms[] = (object) array( 'slug' => (string) $slug );
	}
	return $terms;
}
