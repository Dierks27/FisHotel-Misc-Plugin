<?php
/**
 * Just enough WC_Order to drive Checker headless: meta, notes, status,
 * the shipping / billing address, and a registry that wc_get_order() and
 * wc_get_orders() answer from. Nothing is persisted anywhere.
 *
 * @package FisHotel\Misc\Sections\Weather
 */

class WC_Order {

	private $id;
	private $status;
	private $meta  = array();
	private $notes = array();
	private $shipping;
	private $billing;

	/**
	 * Number of save() calls — a cheap "was anything written" probe.
	 *
	 * @var int
	 */
	public $saves = 0;

	/**
	 * When true every meta read throws, to stand in for a fatal mid-scan.
	 *
	 * @var bool
	 */
	public $poison = false;

	/**
	 * @param int    $id       Order ID.
	 * @param string $status   Status without `wc-`.
	 * @param array  $meta     Initial meta.
	 * @param array  $shipping Shipping address (first_name, address_1, address_2, city, state, postcode, country).
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

	public function set_status( $status ) {
		$this->status = (string) $status;
	}

	public function has_status( $status ) {
		return in_array( $this->status, (array) $status, true );
	}

	public function get_meta( $key, $single = true ) {
		if ( $this->poison ) {
			throw new \RuntimeException( 'poisoned order' );
		}
		return array_key_exists( $key, $this->meta ) ? $this->meta[ $key ] : '';
	}

	public function update_meta_data( $key, $value ) {
		$this->meta[ $key ] = $value;
	}

	public function delete_meta_data( $key ) {
		unset( $this->meta[ $key ] );
	}

	public function has_meta( $key ) {
		return array_key_exists( $key, $this->meta );
	}

	public function all_meta() {
		return $this->meta;
	}

	public function save() {
		$this->saves++;
		return $this->id;
	}

	public function add_order_note( $note, $is_customer_note = false, $added_by_user = false ) {
		$this->notes[] = (string) $note;
		return count( $this->notes );
	}

	public function notes() {
		return $this->notes;
	}

	public function last_note() {
		return $this->notes ? end( $this->notes ) : '';
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
$GLOBALS['fh_orders'] = array();

function fh_register_order( WC_Order $order ) {
	$GLOBALS['fh_orders'][ $order->get_id() ] = $order;
	return $order;
}

function fh_reset_orders() {
	$GLOBALS['fh_orders'] = array();
}

function wc_get_order( $id ) {
	$id = (int) $id;
	return isset( $GLOBALS['fh_orders'][ $id ] ) ? $GLOBALS['fh_orders'][ $id ] : false;
}

/**
 * Honours `status` (list, without `wc-`) and `type`; everything else the
 * real query would do (date meta filter on HPOS) Checker re-checks in PHP.
 */
function wc_get_orders( $args ) {
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
