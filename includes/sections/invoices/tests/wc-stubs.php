<?php
/**
 * WooCommerce in memory — just enough of its objects and functions for
 * the section's REAL Shop class to run headless: products (simple,
 * variable, variation) with their getters, setters, meta, save() and
 * delete(), orders with line items and fees and their meta, terms,
 * attachments and users.
 *
 * It mirrors the WooCommerce behaviour the section leans on:
 *
 *   - wc_get_product() hands out a fresh object each time; nothing
 *     reaches the registry before save().
 *   - save() runs WooCommerce's stock rule (a managed product is "in
 *     stock" only while its quantity is above the store's out-of-stock
 *     threshold) and gives a new product an id and a slug from its title.
 *   - delete( false ) moves to the Trash — or deletes for good when the
 *     Trash is switched off (EMPTY_TRASH_DAYS = 0), as WordPress does.
 *   - is_paid() is true for the statuses processing and completed; the
 *     paid date is separate.
 *   - every setter called on a product is logged on the object, and
 *     every save / trash / delete in $GLOBALS['fh_wc']['writes'] — a test
 *     can prove what was written and what never was. A setter or getter
 *     the stub does not know throws.
 *
 * Seams: $GLOBALS['fh_wc']['on_save'] (a callable run inside save() —
 * "another plugin changed the product") and ['no_stock_amount'].
 *
 * Every name, price and e-mail in the tests is invented.
 *
 * @package FisHotel\Misc\Sections\Invoices
 */

// Helper, not a test: the CI job runs every tests/*.php with php, so exit
// cleanly when executed directly instead of as a require.
if ( ! empty( $_SERVER['SCRIPT_FILENAME'] ) && realpath( $_SERVER['SCRIPT_FILENAME'] ) === __FILE__ ) {
	exit( 0 );
}

/**
 * Empty the in-memory shop.
 */
function fh_wc_reset() {
	$GLOBALS['fh_wc'] = array(
		'next_id'         => 1000,
		'products'        => array(),
		'orders'          => array(),
		'refunds'         => array(),
		'terms'           => array(
			'product_cat'            => array(),
			'product_shipping_class' => array(),
			'pa_size'                => array(),
		),
		'attachments'     => array(),
		'users'           => array(),
		'no_stock_amount' => 0,
		'on_save'         => null,
		'writes'          => array(),
	);
}
fh_wc_reset();

/**
 * A date object with the one method the section calls.
 */
class Fh_Wc_Date {

	private $ts;

	public function __construct( $ts ) {
		$this->ts = (int) $ts;
	}

	public function getTimestamp() {
		return $this->ts;
	}
}

/* ================================================================== */
/*  Products                                                          */
/* ================================================================== */

class WC_Product {

	protected $type = 'simple';

	public $data = array(
		'id'                 => 0,
		'name'               => '',
		'slug'               => '',
		'status'             => 'publish',
		'description'        => '',
		'short_description'  => '',
		'regular_price'      => '',
		'sale_price'         => '',
		'manage_stock'       => false,
		'stock_quantity'     => null,
		'backorders'         => 'no',
		'stock_status'       => 'instock',
		'catalog_visibility' => 'visible',
		'category_ids'       => array(),
		'shipping_class_id'  => 0,
		'image_id'           => 0,
		'parent_id'          => 0,
		'sku'                => '',
		'children'           => array(),
		'attributes'         => array(),
		'date_created'       => 0,
	);

	public $meta = array();

	/**
	 * Every setter called on this object: array( method, value ).
	 *
	 * @var array
	 */
	public $log = array();

	public function get_id() {
		return (int) $this->data['id'];
	}

	public function get_type() {
		return $this->type;
	}

	public function is_type( $type ) {
		return is_array( $type ) ? in_array( $this->type, $type, true ) : $this->type === $type;
	}

	public function __call( $name, $args ) {
		$key = substr( $name, 4 );
		if ( 0 === strpos( $name, 'get_' ) && array_key_exists( $key, $this->data ) ) {
			return $this->data[ $key ];
		}
		if ( 0 === strpos( $name, 'set_' ) && array_key_exists( $key, $this->data ) && 'sale_price' !== $key ) {
			$this->log[]        = array( $name, $args[0] );
			$this->data[ $key ] = $args[0];
			return null;
		}
		throw new \BadMethodCallException( 'WC_Product::' . $name . '() is not something this section may call' );
	}

	public function get_price() {
		$regular = (string) $this->data['regular_price'];
		$sale    = (string) $this->data['sale_price'];
		return '' !== $sale && '' !== $regular && (float) $sale < (float) $regular ? $sale : $regular;
	}

	public function get_stock_quantity() {
		return $this->data['manage_stock'] && null !== $this->data['stock_quantity'] ? (int) $this->data['stock_quantity'] : null;
	}

	public function managing_stock() {
		return (bool) $this->data['manage_stock'];
	}

	public function backorders_allowed() {
		return 'no' !== $this->data['backorders'];
	}

	public function is_in_stock() {
		return 'outofstock' !== $this->data['stock_status'];
	}

	public function get_shipping_class() {
		foreach ( $GLOBALS['fh_wc']['terms']['product_shipping_class'] as $term ) {
			if ( (int) $term->term_id === (int) $this->data['shipping_class_id'] ) {
				return $term->slug;
			}
		}
		return '';
	}

	public function get_date_created() {
		return $this->data['date_created'] ? new Fh_Wc_Date( $this->data['date_created'] ) : null;
	}

	public function get_meta( $key ) {
		return isset( $this->meta[ $key ] ) ? $this->meta[ $key ] : '';
	}

	public function update_meta_data( $key, $value ) {
		$this->log[]        = array( 'update_meta_data', $key );
		$this->meta[ $key ] = $value;
	}

	public function save() {
		// WC_Product::validate_props(): the stock props follow manage_stock.
		if ( ! $this->data['manage_stock'] ) {
			$this->data['stock_quantity'] = null;
			$this->data['backorders']     = 'no';
		} elseif ( (int) $this->data['stock_quantity'] > (int) $GLOBALS['fh_wc']['no_stock_amount'] ) {
			$this->data['stock_status'] = 'instock';
		} else {
			$this->data['stock_status'] = 'no' !== $this->data['backorders'] ? 'onbackorder' : 'outofstock';
		}

		if ( $this->data['id'] <= 0 ) {
			$this->data['id']           = ++$GLOBALS['fh_wc']['next_id'];
			$this->data['date_created'] = strtotime( $GLOBALS['fh_now'] . ' UTC' );
		}
		if ( '' === (string) $this->data['slug'] ) {
			$slug  = sanitize_title( $this->data['name'] );
			$taken = array();
			foreach ( $GLOBALS['fh_wc']['products'] as $entry ) {
				$taken[] = $entry['data']['slug'];
			}
			$base = $slug;
			$n    = 2;
			while ( in_array( $slug, $taken, true ) ) {
				$slug = $base . '-' . $n++;
			}
			$this->data['slug'] = $slug;
		}

		if ( is_callable( $GLOBALS['fh_wc']['on_save'] ) ) {
			call_user_func( $GLOBALS['fh_wc']['on_save'], $this );
		}

		$GLOBALS['fh_wc']['products'][ $this->data['id'] ] = array(
			'class' => get_class( $this ),
			'data'  => $this->data,
			'meta'  => $this->meta,
		);
		$GLOBALS['fh_wc']['writes'][]                      = array( 'save', (int) $this->data['id'], $this->log );

		return (int) $this->data['id'];
	}

	public function delete( $force = false ) {
		$id = (int) $this->data['id'];
		if ( $force || ! EMPTY_TRASH_DAYS ) {
			unset( $GLOBALS['fh_wc']['products'][ $id ] );
			$GLOBALS['fh_wc']['writes'][] = array( 'delete', $id, array() );
			return true;
		}
		$this->data['status']                               = 'trash';
		$GLOBALS['fh_wc']['products'][ $id ]['data']['status'] = 'trash';
		$GLOBALS['fh_wc']['writes'][]                       = array( 'trash', $id, array() );
		return true;
	}
}

class WC_Product_Simple extends WC_Product {
}

class WC_Product_Variable extends WC_Product {
	protected $type = 'variable';
}

class WC_Product_Variation extends WC_Product {
	protected $type = 'variation';
}

/**
 * Put a product straight into the registry (the way it "already exists").
 *
 * @param array  $data  Fields over the defaults.
 * @param array  $meta  Meta.
 * @param string $class WC_Product_Simple|WC_Product_Variable|WC_Product_Variation.
 * @return int Product id.
 */
function fh_wc_product( array $data, array $meta = array(), $class = 'WC_Product_Simple' ) {
	$product       = new $class();
	$product->data = array_merge( $product->data, $data );
	$product->meta = $meta;
	if ( $product->data['id'] <= 0 ) {
		$product->data['id'] = ++$GLOBALS['fh_wc']['next_id'];
	}
	if ( '' === (string) $product->data['slug'] ) {
		$product->data['slug'] = sanitize_title( $product->data['name'] );
	}
	if ( ! $product->data['date_created'] ) {
		$product->data['date_created'] = strtotime( $GLOBALS['fh_now'] . ' UTC' );
	}
	$GLOBALS['fh_wc']['products'][ $product->data['id'] ] = array(
		'class' => $class,
		'data'  => $product->data,
		'meta'  => $product->meta,
	);
	return (int) $product->data['id'];
}

function wc_get_product( $id ) {
	$id = (int) $id;
	if ( ! isset( $GLOBALS['fh_wc']['products'][ $id ] ) ) {
		return false;
	}
	$entry         = $GLOBALS['fh_wc']['products'][ $id ];
	$product       = new $entry['class']();
	$product->data = $entry['data'];
	$product->meta = $entry['meta'];
	return $product;
}

function wc_get_products( $args ) {
	$statuses = isset( $args['status'] ) ? (array) $args['status'] : array( 'draft', 'pending', 'private', 'publish' );
	$ids      = array_keys( $GLOBALS['fh_wc']['products'] );
	rsort( $ids );

	$out = array();
	foreach ( $ids as $id ) {
		$entry = $GLOBALS['fh_wc']['products'][ $id ];
		if ( 'WC_Product_Variation' === $entry['class'] || ! in_array( $entry['data']['status'], $statuses, true ) ) {
			continue;
		}
		if ( ! empty( $args['category'] ) ) {
			$slugs = array();
			foreach ( $GLOBALS['fh_wc']['terms']['product_cat'] as $term ) {
				if ( in_array( (int) $term->term_id, array_map( 'intval', $entry['data']['category_ids'] ), true ) ) {
					$slugs[] = $term->slug;
				}
			}
			if ( ! array_intersect( (array) $args['category'], $slugs ) ) {
				continue;
			}
		}
		if ( isset( $args['s'] ) && '' !== (string) $args['s'] && false === stripos( $entry['data']['name'] . ' ' . $entry['data']['description'], (string) $args['s'] ) ) {
			continue;
		}
		if ( isset( $args['meta_key'] ) && ( ! isset( $entry['meta'][ $args['meta_key'] ] ) || (string) $entry['meta'][ $args['meta_key'] ] !== (string) $args['meta_value'] ) ) {
			continue;
		}
		$out[] = $id;
	}

	$limit = isset( $args['limit'] ) ? (int) $args['limit'] : 10;
	$page  = isset( $args['page'] ) ? max( 1, (int) $args['page'] ) : 1;
	if ( $limit > 0 ) {
		$out = array_slice( $out, ( $page - 1 ) * $limit, $limit );
	}

	if ( isset( $args['return'] ) && 'ids' === $args['return'] ) {
		return $out;
	}
	return array_map( 'wc_get_product', $out );
}

class WC_Data_Store {

	private $kind;

	public static function load( $kind ) {
		$store       = new self();
		$store->kind = $kind;
		return $store;
	}

	public function search_products( $term, $type = '', $include_variations = false, $all_statuses = false, $limit = null ) {
		$words = array_filter( preg_split( '/\s+/', strtolower( (string) $term ) ), 'strlen' );
		$out   = array();
		foreach ( $GLOBALS['fh_wc']['products'] as $id => $entry ) {
			if ( 'WC_Product_Variation' === $entry['class'] ) {
				continue;
			}
			if ( ! $all_statuses && ! in_array( $entry['data']['status'], array( 'publish', 'private' ), true ) ) {
				continue;
			}
			$text = strtolower( $entry['data']['name'] . ' ' . $entry['data']['description'] . ' ' . $entry['data']['sku'] );
			foreach ( $words as $word ) {
				if ( false === strpos( $text, $word ) ) {
					continue 2;
				}
			}
			$out[] = (int) $id;
		}
		return $limit ? array_slice( $out, 0, (int) $limit ) : $out;
	}

	public function search_customers( $term, $limit = '' ) {
		$out = array();
		foreach ( $GLOBALS['fh_wc']['users'] as $id => $user ) {
			$text = strtolower( implode( ' ', array( $user['user_login'], $user['user_email'], $user['display_name'], $user['first_name'], $user['last_name'], implode( ' ', array_map( 'strval', $user['meta'] ) ) ) ) );
			if ( false !== strpos( $text, strtolower( (string) $term ) ) ) {
				$out[] = (int) $id;
			}
		}
		return $limit ? array_slice( $out, 0, (int) $limit ) : $out;
	}
}

/* ================================================================== */
/*  Orders                                                            */
/* ================================================================== */

class WC_Order_Item {

	public $name  = '';
	public $total = 0.0;
	public $meta  = array();

	public function get_name() {
		return $this->name;
	}

	public function get_total() {
		return $this->total;
	}

	public function get_meta( $key ) {
		return isset( $this->meta[ $key ] ) ? $this->meta[ $key ] : '';
	}
}

class WC_Order_Item_Product extends WC_Order_Item {

	public $product_id   = 0;
	public $variation_id = 0;
	public $quantity     = 1;

	public function get_product_id() {
		return (int) $this->product_id;
	}

	public function get_variation_id() {
		return (int) $this->variation_id;
	}

	public function get_quantity() {
		return (int) $this->quantity;
	}
}

class WC_Order_Item_Fee extends WC_Order_Item {
}

class WC_Order_Refund {
}

class WC_Order {

	public $data = array(
		'id'                   => 0,
		'status'               => 'pending',
		'date_paid'            => 0,
		'date_created'         => 0,
		'total'                => 0.0,
		'customer_id'          => 0,
		'billing_first_name'   => '',
		'billing_last_name'    => '',
		'billing_email'        => '',
		'payment_method_title' => '',
	);

	public $meta  = array();
	public $items = array();

	public function get_id() {
		return (int) $this->data['id'];
	}

	public function get_order_number() {
		return (string) $this->data['id'];
	}

	public function get_status() {
		return (string) $this->data['status'];
	}

	public function has_status( $status ) {
		return in_array( $this->data['status'], (array) $status, true );
	}

	public function get_date_paid() {
		return $this->data['date_paid'] ? new Fh_Wc_Date( $this->data['date_paid'] ) : null;
	}

	public function get_date_created() {
		return $this->data['date_created'] ? new Fh_Wc_Date( $this->data['date_created'] ) : null;
	}

	public function is_paid() {
		return in_array( $this->data['status'], array( 'processing', 'completed' ), true );
	}

	public function get_items( $types = 'line_item' ) {
		$types = (array) $types;
		$out   = array();
		foreach ( $this->items as $id => $item ) {
			$type = $item instanceof WC_Order_Item_Fee ? 'fee' : 'line_item';
			if ( in_array( $type, $types, true ) ) {
				$out[ $id ] = $item;
			}
		}
		return $out;
	}

	public function get_meta( $key ) {
		return isset( $this->meta[ $key ] ) ? $this->meta[ $key ] : '';
	}

	public function get_total() {
		return $this->data['total'];
	}

	public function get_customer_id() {
		return (int) $this->data['customer_id'];
	}

	public function get_billing_first_name() {
		return (string) $this->data['billing_first_name'];
	}

	public function get_billing_last_name() {
		return (string) $this->data['billing_last_name'];
	}

	public function get_billing_email() {
		return (string) $this->data['billing_email'];
	}

	public function get_payment_method_title() {
		return (string) $this->data['payment_method_title'];
	}

	public function get_edit_order_url() {
		return admin_url( 'admin.php?page=wc-orders&action=edit&id=' . (int) $this->data['id'] );
	}
}

/**
 * Put an order into the registry.
 *
 * @param array   $data  status, date_paid (a time, or true = now), total, customer_id, billing_*.
 * @param array[] $items Each: name, qty, total, product_id, variation_id, meta, type (line_item|fee).
 * @param array   $meta  Order meta.
 * @return int Order id.
 */
function fh_wc_order( array $data, array $items = array(), array $meta = array() ) {
	$order       = new WC_Order();
	$order->data = array_merge( $order->data, $data );
	$order->meta = $meta;
	$now         = strtotime( $GLOBALS['fh_now'] . ' UTC' );
	if ( $order->data['id'] <= 0 ) {
		$order->data['id'] = ++$GLOBALS['fh_wc']['next_id'];
	}
	if ( true === $order->data['date_paid'] ) {
		$order->data['date_paid'] = $now;
	}
	if ( ! $order->data['date_created'] ) {
		$order->data['date_created'] = $now;
	}

	foreach ( $items as $row ) {
		$type = isset( $row['type'] ) ? $row['type'] : 'line_item';
		if ( 'fee' === $type ) {
			$item = new WC_Order_Item_Fee();
		} else {
			$item               = new WC_Order_Item_Product();
			$item->product_id   = isset( $row['product_id'] ) ? (int) $row['product_id'] : 0;
			$item->variation_id = isset( $row['variation_id'] ) ? (int) $row['variation_id'] : 0;
			$item->quantity     = isset( $row['qty'] ) ? (int) $row['qty'] : 1;
		}
		$item->name     = isset( $row['name'] ) ? (string) $row['name'] : '';
		$item->total    = isset( $row['total'] ) ? (float) $row['total'] : 0.0;
		$item->meta     = isset( $row['meta'] ) ? (array) $row['meta'] : array();
		$order->items[] = $item;
	}

	$GLOBALS['fh_wc']['orders'][ $order->data['id'] ] = $order;
	return (int) $order->data['id'];
}

/**
 * Change an order in the registry (its status, its paid date…).
 *
 * @param int   $id   Order id.
 * @param array $data Fields.
 */
function fh_wc_order_set( $id, array $data ) {
	$order = $GLOBALS['fh_wc']['orders'][ (int) $id ];
	if ( isset( $data['date_paid'] ) && true === $data['date_paid'] ) {
		$data['date_paid'] = strtotime( $GLOBALS['fh_now'] . ' UTC' );
	}
	$order->data = array_merge( $order->data, $data );
}

function wc_get_order( $id ) {
	$id = (int) $id;
	if ( isset( $GLOBALS['fh_wc']['refunds'][ $id ] ) ) {
		return new WC_Order_Refund();
	}
	return isset( $GLOBALS['fh_wc']['orders'][ $id ] ) ? $GLOBALS['fh_wc']['orders'][ $id ] : false;
}

function wc_get_orders( $args ) {
	$ids = array_keys( $GLOBALS['fh_wc']['orders'] );
	rsort( $ids );

	$out = array();
	foreach ( $ids as $id ) {
		$order = $GLOBALS['fh_wc']['orders'][ $id ];
		if ( 'trash' === $order->data['status'] ) {
			continue;
		}
		if ( isset( $args['customer_id'] ) && (int) $args['customer_id'] !== (int) $order->data['customer_id'] ) {
			continue;
		}
		if ( isset( $args['billing_email'] ) && strtolower( (string) $args['billing_email'] ) !== strtolower( $order->data['billing_email'] ) ) {
			continue;
		}
		$out[] = $id;
	}

	if ( isset( $args['limit'] ) && (int) $args['limit'] > 0 ) {
		$out = array_slice( $out, 0, (int) $args['limit'] );
	}
	return isset( $args['return'] ) && 'ids' === $args['return'] ? $out : array_map( 'wc_get_order', $out );
}

function wc_order_search( $term ) {
	$term = strtolower( (string) $term );
	$out  = array();
	foreach ( $GLOBALS['fh_wc']['orders'] as $id => $order ) {
		$text = strtolower( $order->data['billing_first_name'] . ' ' . $order->data['billing_last_name'] . ' ' . $order->data['billing_email'] );
		foreach ( $order->items as $item ) {
			$text .= ' ' . strtolower( $item->name );
		}
		if ( '' !== $term && false !== strpos( $text, $term ) ) {
			$out[] = (int) $id;
		}
	}
	return $out;
}

function wc_get_order_status_name( $status ) {
	$names = array(
		'pending'    => 'Pending payment',
		'on-hold'    => 'On hold',
		'processing' => 'Processing',
		'completed'  => 'Completed',
		'cancelled'  => 'Cancelled',
		'failed'     => 'Failed',
		'refunded'   => 'Refunded',
	);
	return isset( $names[ $status ] ) ? $names[ $status ] : ucfirst( (string) $status );
}

/* ================================================================== */
/*  Terms, posts, users                                               */
/* ================================================================== */

/**
 * Add a term.
 *
 * @param string $taxonomy Taxonomy.
 * @param string $slug     Slug.
 * @param string $name     Name.
 * @return int Term id.
 */
function fh_wc_term( $taxonomy, $slug, $name ) {
	$id                                       = ++$GLOBALS['fh_wc']['next_id'];
	$GLOBALS['fh_wc']['terms'][ $taxonomy ][] = (object) array(
		'term_id'  => $id,
		'slug'     => $slug,
		'name'     => $name,
		'taxonomy' => $taxonomy,
	);
	return $id;
}

function taxonomy_exists( $taxonomy ) {
	return isset( $GLOBALS['fh_wc']['terms'][ $taxonomy ] );
}

function get_term_by( $field, $value, $taxonomy ) {
	foreach ( isset( $GLOBALS['fh_wc']['terms'][ $taxonomy ] ) ? $GLOBALS['fh_wc']['terms'][ $taxonomy ] : array() as $term ) {
		if ( ( 'slug' === $field && $term->slug === (string) $value ) || ( 'id' === $field && (int) $term->term_id === (int) $value ) ) {
			return $term;
		}
	}
	return false;
}

function get_term( $id, $taxonomy = '' ) {
	foreach ( $GLOBALS['fh_wc']['terms'] as $name => $terms ) {
		if ( '' !== $taxonomy && $name !== $taxonomy ) {
			continue;
		}
		foreach ( $terms as $term ) {
			if ( (int) $term->term_id === (int) $id ) {
				return $term;
			}
		}
	}
	return null;
}

function get_terms( $args ) {
	$taxonomy = isset( $args['taxonomy'] ) ? $args['taxonomy'] : '';
	return isset( $GLOBALS['fh_wc']['terms'][ $taxonomy ] ) ? $GLOBALS['fh_wc']['terms'][ $taxonomy ] : array();
}

function get_the_terms( $post_id, $taxonomy ) {
	if ( ! isset( $GLOBALS['fh_wc']['products'][ (int) $post_id ] ) ) {
		return false;
	}
	$ids = array_map( 'intval', $GLOBALS['fh_wc']['products'][ (int) $post_id ]['data']['category_ids'] );
	$out = array();
	foreach ( isset( $GLOBALS['fh_wc']['terms'][ $taxonomy ] ) ? $GLOBALS['fh_wc']['terms'][ $taxonomy ] : array() as $term ) {
		if ( in_array( (int) $term->term_id, $ids, true ) ) {
			$out[] = $term;
		}
	}
	return $out ? $out : false;
}

function get_post_type( $id ) {
	$id = (int) $id;
	if ( isset( $GLOBALS['fh_wc']['attachments'][ $id ] ) ) {
		return 'attachment';
	}
	if ( isset( $GLOBALS['fh_wc']['products'][ $id ] ) ) {
		return 'WC_Product_Variation' === $GLOBALS['fh_wc']['products'][ $id ]['class'] ? 'product_variation' : 'product';
	}
	return false;
}

function get_post_status( $id ) {
	return isset( $GLOBALS['fh_wc']['products'][ (int) $id ] ) ? $GLOBALS['fh_wc']['products'][ (int) $id ]['data']['status'] : false;
}

function get_the_title( $id ) {
	$id = (int) $id;
	if ( isset( $GLOBALS['fh_wc']['attachments'][ $id ] ) ) {
		return $GLOBALS['fh_wc']['attachments'][ $id ];
	}
	return isset( $GLOBALS['fh_wc']['products'][ $id ] ) ? $GLOBALS['fh_wc']['products'][ $id ]['data']['name'] : '';
}

function wp_get_attachment_image_url( $id, $size = 'thumbnail' ) {
	return isset( $GLOBALS['fh_wc']['attachments'][ (int) $id ] ) ? 'https://example.test/wp-content/uploads/image-' . (int) $id . '.gif' : false;
}

function get_permalink( $id ) {
	$id = (int) $id;
	if ( ! isset( $GLOBALS['fh_wc']['products'][ $id ] ) ) {
		return false;
	}
	$data  = $GLOBALS['fh_wc']['products'][ $id ]['data'];
	$terms = get_the_terms( $id, 'product_cat' );
	return 'https://example.test/shop/' . ( $terms ? $terms[0]->slug . '/' : '' ) . $data['slug'] . '/';
}

/**
 * Add a site user.
 *
 * @param array $user user_login, user_email, display_name, first_name, last_name, meta.
 * @return int User id.
 */
function fh_wc_user( array $user ) {
	$id                               = ++$GLOBALS['fh_wc']['next_id'];
	$GLOBALS['fh_wc']['users'][ $id ] = array_merge(
		array(
			'user_login'   => 'user' . $id,
			'user_email'   => '',
			'display_name' => '',
			'first_name'   => '',
			'last_name'    => '',
			'meta'         => array(),
		),
		$user
	);
	return $id;
}

function get_userdata( $id ) {
	$id = (int) $id;
	if ( ! isset( $GLOBALS['fh_wc']['users'][ $id ] ) ) {
		return false;
	}
	$user = $GLOBALS['fh_wc']['users'][ $id ];
	return (object) array(
		'ID'           => $id,
		'user_login'   => $user['user_login'],
		'user_email'   => $user['user_email'],
		'display_name' => $user['display_name'],
		'first_name'   => $user['first_name'],
		'last_name'    => $user['last_name'],
	);
}

function get_user_meta( $id, $key = '', $single = false ) {
	$id = (int) $id;
	return isset( $GLOBALS['fh_wc']['users'][ $id ]['meta'][ $key ] ) ? $GLOBALS['fh_wc']['users'][ $id ]['meta'][ $key ] : '';
}

function get_users( $args ) {
	return array();
}

/* ================================================================== */
/*  A small shop to test in                                           */
/* ================================================================== */

/**
 * Empty the shop and stock it with what every test needs: the Invoices
 * and a fish category, three shipping classes, two sizes, two images, a
 * customer and a shop keeper. All invented.
 *
 * @return array<string, int> The ids, by name.
 */
function fh_wc_world() {
	fh_wc_reset();
	$GLOBALS['fh_options'] = array();
	$GLOBALS['fh_fired']   = array();

	$world = array(
		'cat_invoices' => fh_wc_term( 'product_cat', 'invoices', 'Invoices' ),
		'cat_fish'     => fh_wc_term( 'product_cat', 'quarantined-fish', 'Quarantined Fish' ),
		'ship_premium' => fh_wc_term( 'product_shipping_class', 'premium', 'Premium' ),
		'ship_free'    => fh_wc_term( 'product_shipping_class', 'free', 'Free' ),
		'ship_quote'   => fh_wc_term( 'product_shipping_class', 'quote', 'Quote' ),
		'size_medium'  => fh_wc_term( 'pa_size', 'medium', 'Medium' ),
		'size_large'   => fh_wc_term( 'pa_size', 'large', 'Large' ),
	);

	$world['img_small']                                    = ++$GLOBALS['fh_wc']['next_id'];
	$GLOBALS['fh_wc']['attachments'][ $world['img_small'] ] = 'Test boxes, small';
	$world['img_big']                                      = ++$GLOBALS['fh_wc']['next_id'];
	$GLOBALS['fh_wc']['attachments'][ $world['img_big'] ]   = 'Test boxes, big';

	$world['customer'] = fh_wc_user(
		array(
			'user_login'   => 'testperson',
			'user_email'   => 'person@example.test',
			'display_name' => 'Test Person',
			'first_name'   => 'Test',
			'last_name'    => 'Person',
			'meta'         => array(
				'billing_first_name' => 'Test',
				'billing_last_name'  => 'Person',
				'billing_email'      => 'billing@example.test',
			),
		)
	);
	$world['keeper']   = fh_wc_user( array( 'display_name' => 'Shop Keeper' ) );

	return $world;
}
