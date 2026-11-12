<?php
/**
 * Stand-ins for WooCommerce, in memory: a product (simple or a variation),
 * an order line, an order, the registries wc_get_product() / wc_get_order()
 * answer from, and a store that answers the fill tool from the fake
 * products. Each class has just the methods the Packing section calls —
 * and counts the writes, so a test can tell exactly what was saved and
 * through which method.
 *
 * Helper, not a test: the CI runs every tests/*.php with php, so this file
 * exits cleanly when it is run by itself.
 *
 * @package FisHotel\Misc\Sections\Packing
 */

if ( ! empty( $_SERVER['SCRIPT_FILENAME'] ) && realpath( $_SERVER['SCRIPT_FILENAME'] ) === __FILE__ ) {
	exit( 0 );
}

/**
 * A product: a simple product, a variable parent or a variation.
 */
class Fh_Fake_Product {

	public $id;
	public $parent_id;
	public $type;
	public $attributes;
	public $description = '';
	public $status      = 'publish';

	/**
	 * Meta as stored / as changed but not saved yet.
	 *
	 * @var array
	 */
	public $meta    = array();
	public $pending = null;

	/**
	 * Counts of the writes.
	 *
	 * @var int
	 */
	public $meta_saves = 0;
	public $full_saves = 0;

	/**
	 * @param int    $id         Product ID.
	 * @param string $type       simple | variable | variation.
	 * @param array  $attributes A variation: key → value. A simple product: key → list of values.
	 * @param array  $meta       Stored meta.
	 * @param int    $parent_id  The parent of a variation.
	 */
	public function __construct( $id, $type = 'simple', array $attributes = array(), array $meta = array(), $parent_id = 0 ) {
		$this->id         = (int) $id;
		$this->type       = (string) $type;
		$this->attributes = $attributes;
		$this->meta       = $meta;
		$this->parent_id  = (int) $parent_id;
	}

	public function get_id() {
		return $this->id;
	}

	public function get_parent_id() {
		return $this->parent_id;
	}

	public function is_type( $type ) {
		return in_array( $this->type, (array) $type, true );
	}

	public function get_description() {
		return $this->description;
	}

	/**
	 * A variation answers key → value; any other product answers attribute
	 * objects, as WooCommerce does.
	 */
	public function get_attributes() {
		if ( 'variation' === $this->type ) {
			return $this->attributes;
		}
		$out = array();
		foreach ( $this->attributes as $key => $values ) {
			$out[ $key ] = new Fh_Fake_Attribute( $key, (array) $values );
		}
		return $out;
	}

	public function get_meta( $key, $single = true ) {
		$meta = null === $this->pending ? $this->meta : $this->pending;
		return array_key_exists( $key, $meta ) ? $meta[ $key ] : '';
	}

	public function update_meta_data( $key, $value ) {
		if ( null === $this->pending ) {
			$this->pending = $this->meta;
		}
		$this->pending[ $key ] = $value;
	}

	public function delete_meta_data( $key ) {
		if ( null === $this->pending ) {
			$this->pending = $this->meta;
		}
		unset( $this->pending[ $key ] );
	}

	public function save_meta_data() {
		if ( null !== $this->pending ) {
			$this->meta    = $this->pending;
			$this->pending = null;
		}
		$this->meta_saves++;
	}

	public function save() {
		$this->save_meta_data();
		$this->meta_saves--;
		$this->full_saves++;
		return $this->id;
	}
}

/**
 * A simple product's attribute.
 */
class Fh_Fake_Attribute {

	private $name;
	private $values;

	public function __construct( $name, array $values ) {
		$this->name   = (string) $name;
		$this->values = $values;
	}

	public function get_name() {
		return $this->name;
	}

	public function is_taxonomy() {
		return 0 === strpos( $this->name, 'pa_' );
	}

	public function get_slugs() {
		return $this->values;
	}

	public function get_options() {
		return $this->values;
	}
}

/**
 * One product line of an order.
 */
class Fh_Fake_Item {

	public $id;
	public $name;
	public $qty;
	public $product_id;
	public $variation_id;
	public $meta;

	/**
	 * Every write to the line (there must be none).
	 *
	 * @var array
	 */
	public $writes = array();

	/**
	 * @param int    $id           Line ID.
	 * @param string $name         Name.
	 * @param int    $qty          Quantity.
	 * @param int    $product_id   Product (0 = none).
	 * @param array  $meta         Meta key → value.
	 * @param int    $variation_id Variation (0 = none).
	 */
	public function __construct( $id, $name, $qty, $product_id = 0, array $meta = array(), $variation_id = 0 ) {
		$this->id           = (int) $id;
		$this->name         = (string) $name;
		$this->qty          = (int) $qty;
		$this->product_id   = (int) $product_id;
		$this->variation_id = (int) $variation_id;
		$this->meta         = $meta;
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

	public function get_product_id() {
		return $this->product_id;
	}

	public function get_variation_id() {
		return $this->variation_id;
	}

	public function get_product() {
		$id = $this->variation_id > 0 ? $this->variation_id : $this->product_id;
		return $id > 0 ? wc_get_product( $id ) : false;
	}

	public function get_meta( $key, $single = true ) {
		return array_key_exists( $key, $this->meta ) ? $this->meta[ $key ] : '';
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

	public function update_meta_data( $key, $value ) {
		$this->writes[] = array( 'update', $key );
	}

	public function add_meta_data( $key, $value, $unique = false ) {
		$this->writes[] = array( 'add', $key );
	}

	public function save() {
		$this->writes[] = array( 'save', '' );
	}
}

/**
 * An order.
 */
class Fh_Fake_Order {

	public $id;
	public $status;
	public $meta;
	public $items    = array();
	public $refunded = array();
	public $notes    = array();

	/**
	 * Counts of the writes, and the log of every meta call.
	 *
	 * @var int
	 */
	public $saves = 0;
	public $calls = array();

	public function __construct( $id, array $items = array(), array $meta = array(), $status = 'processing' ) {
		$this->id     = (int) $id;
		$this->status = (string) $status;
		$this->meta   = $meta;
		foreach ( $items as $item ) {
			$this->items[ $item->get_id() ] = $item;
		}
	}

	public function get_id() {
		return $this->id;
	}

	/**
	 * 'shop_order' unless a test makes it something else (a refund).
	 *
	 * @var string
	 */
	public $type = 'shop_order';

	public function get_type() {
		return $this->type;
	}

	public function get_status() {
		return $this->status;
	}

	public function has_status( $status ) {
		return in_array( $this->status, (array) $status, true );
	}

	public function get_items( $type = 'line_item' ) {
		return 'line_item' === $type ? $this->items : array();
	}

	public function get_qty_refunded_for_item( $item_id ) {
		return -1 * (int) ( $this->refunded[ (int) $item_id ] ?? 0 ); // WooCommerce reports refunds as negatives.
	}

	public function get_meta( $key, $single = true ) {
		$this->calls[] = array( 'get_meta', $key );
		return array_key_exists( $key, $this->meta ) ? $this->meta[ $key ] : '';
	}

	public function update_meta_data( $key, $value ) {
		$this->calls[]      = array( 'update_meta_data', $key );
		$this->meta[ $key ] = $value;
	}

	public function delete_meta_data( $key ) {
		$this->calls[] = array( 'delete_meta_data', $key );
		unset( $this->meta[ $key ] );
	}

	public function save() {
		$this->calls[] = array( 'save', '' );
		$this->saves++;
		return $this->id;
	}

	public function add_order_note( $note, $is_customer_note = 0, $added_by_user = false ) {
		$this->notes[] = (string) $note;
		return count( $this->notes );
	}

	/**
	 * The names of the write calls made so far.
	 *
	 * @return string[]
	 */
	public function writes() {
		$out = array();
		foreach ( $this->calls as $call ) {
			if ( 'get_meta' !== $call[0] ) {
				$out[] = $call[0] . ( '' !== $call[1] ? ':' . $call[1] : '' );
			}
		}
		return $out;
	}
}

/**
 * The registries.
 */
$GLOBALS['fh_products'] = array();
$GLOBALS['fh_orders']   = array();

function fh_product( Fh_Fake_Product $product ) {
	$GLOBALS['fh_products'][ $product->get_id() ] = $product;
	return $product;
}

function fh_order( Fh_Fake_Order $order ) {
	$GLOBALS['fh_orders'][ $order->get_id() ] = $order;
	return $order;
}

function fh_reset_shop() {
	$GLOBALS['fh_products'] = array();
	$GLOBALS['fh_orders']   = array();
	$GLOBALS['fh_terms']    = array();
}

function wc_get_product( $id ) {
	$id = is_object( $id ) && method_exists( $id, 'get_id' ) ? $id->get_id() : (int) $id;
	return isset( $GLOBALS['fh_products'][ $id ] ) ? $GLOBALS['fh_products'][ $id ] : false;
}

function wc_get_order( $id ) {
	$id = (int) $id;
	return isset( $GLOBALS['fh_orders'][ $id ] ) ? $GLOBALS['fh_orders'][ $id ] : false;
}

function wc_get_page_screen_id( $for ) {
	return 'shop-order' === $for ? 'woocommerce_page_wc-orders' : '';
}

/**
 * The store the fill tool and the page read through, answered from the
 * fake products (so a variation that was just given a bag drops out of
 * "without a bag", as it does in the database).
 */
class Fh_Fake_Store {

	public $size_attributes = array();
	public $size_terms      = array();

	/**
	 * How often the scan ran.
	 *
	 * @var int
	 */
	public $scans = 0;

	public function size_attributes() {
		return $this->size_attributes;
	}

	public function size_terms( $taxonomy ) {
		return isset( $this->size_terms[ $taxonomy ] ) ? $this->size_terms[ $taxonomy ] : array();
	}

	public function variations_without_bag( $after_id = 0, $limit = 0 ) {
		$this->scans++;
		$out = array();
		$ids = array_keys( $GLOBALS['fh_products'] );
		sort( $ids );
		foreach ( $ids as $id ) {
			$product = $GLOBALS['fh_products'][ $id ];
			if ( 'variation' !== $product->type || $id <= (int) $after_id || 'trash' === $product->status ) {
				continue;
			}
			if ( '' !== trim( (string) ( $product->meta['_fh_bag_size'] ?? '' ) ) ) {
				continue;
			}
			$attributes = array();
			foreach ( $product->attributes as $key => $value ) {
				if ( '' !== (string) $value && 1 === preg_match( '/size/i', (string) $key ) ) {
					$attributes[ $key ] = (string) $value;
				}
			}
			$out[] = array(
				'id'         => $id,
				'parent'     => $product->parent_id,
				'attributes' => $attributes,
			);
			if ( (int) $limit > 0 && count( $out ) >= (int) $limit ) {
				break;
			}
		}
		return $out;
	}

	/**
	 * How often the checkbox values were read, and for which products.
	 *
	 * @var array
	 */
	public $flag_reads = array();

	public function packs_flags( array $product_ids ) {
		$this->flag_reads[] = array_values( $product_ids );
		$out                = array();
		foreach ( $product_ids as $id ) {
			$value = isset( $GLOBALS['fh_products'][ (int) $id ] ) ? (string) ( $GLOBALS['fh_products'][ (int) $id ]->meta['_fh_packs_in_bag'] ?? '' ) : '';
			if ( 'yes' === $value || 'no' === $value ) {
				$out[ (int) $id ] = $value;
			}
		}
		return $out;
	}

	public function variation_bags( $product_id ) {
		$out = array();
		foreach ( $GLOBALS['fh_products'] as $product ) {
			$value = trim( (string) ( $product->meta['_fh_bag_size'] ?? '' ) );
			if ( 'variation' === $product->type && (int) $product_id === $product->parent_id && 'trash' !== $product->status && '' !== $value && ! in_array( $value, $out, true ) ) {
				$out[] = $value;
			}
		}
		return $out;
	}

	public function variation_counts() {
		$total = 0;
		$with  = 0;
		foreach ( $GLOBALS['fh_products'] as $product ) {
			if ( 'variation' !== $product->type || 'trash' === $product->status ) {
				continue;
			}
			$total++;
			if ( '' !== trim( (string) ( $product->meta['_fh_bag_size'] ?? '' ) ) ) {
				$with++;
			}
		}
		return array(
			'total'    => $total,
			'with_bag' => $with,
		);
	}

	public function bag_usage() {
		$out = array();
		foreach ( $GLOBALS['fh_products'] as $product ) {
			$bag = (int) ( $product->meta['_fh_bag_size'] ?? 0 );
			if ( $bag > 0 ) {
				$out[ $bag ] = ( $out[ $bag ] ?? 0 ) + 1;
			}
		}
		return $out;
	}
}
