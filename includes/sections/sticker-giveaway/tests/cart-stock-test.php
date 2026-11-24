<?php
/**
 * The cart class played through stand-ins for WooCommerce's cart,
 * products and orders: a variation design goes into the cart as a
 * variation line, and a sticker line gives way to the stock — on a
 * totals pass, just before WooCommerce's own cart check, when a normal
 * purchase is added from the shop, and on the pay page of an order whose
 * payment failed — while a normal purchase is never touched.
 *
 * The stand-ins do what WooCommerce 11.2's own classes do at the calls
 * the section makes (a removed line totals the cart again; quantities
 * are summed per product that holds the stock). They are not
 * WooCommerce: the same cases were also played on a throwaway WordPress
 * with WooCommerce 11.2 (the section README says which).
 *
 * Invented shop: "Reef Stickers" (#300), variations #301 "Night Glow"
 * and #302 "Coral Pop" on a custom attribute, each with its own stock;
 * "Logo Stickers" (#400) on a global attribute, keeping ONE stock number
 * for #401 and #402; "Round Logo Sticker" (#77), a simple product; a
 * fish (#9). Section 7 adds a medication the supplier drop-ships
 * (#600, "EA") for the mailing fee of an EA-only order.
 *
 * Run from the plugin root:
 *   php includes/sections/sticker-giveaway/tests/cart-stock-test.php
 *
 * @package FisHotel\Misc\Sections\Sticker_Giveaway
 */

require_once __DIR__ . '/wp-stubs.php';

function add_action() {}
function add_filter() {}
function is_admin() {
	return false;
}
function wp_doing_ajax() {
	return false;
}
function absint( $n ) {
	return abs( (int) $n );
}
function sanitize_title( $text ) {
	return strtolower( str_replace( ' ', '-', $text ) );
}
function get_post_status( $id ) {
	return $GLOBALS['fh_products'][ $id ]->status;
}
function get_ancestors() {
	return array();
}
function get_the_terms( $id ) {
	$term       = new WP_Term();
	$term->slug = 9 === $id ? 'quarantined-fish' : 'merchandise';
	return array( $term );
}
function wc_get_product( $id ) {
	return $GLOBALS['fh_products'][ $id ] ?? false;
}
function wc_get_held_stock_quantity( $product, $exclude_order_id = 0 ) {
	return $GLOBALS['fh_held'][ $product->get_stock_managed_by_id() ] ?? 0;
}
function WC() {
	return $GLOBALS['fh_wc'];
}
function wc_get_order( $id ) {
	return $GLOBALS['fh_orders'][ $id ] ?? false;
}
function wc_get_is_pending_statuses() {
	return array( 'pending' );
}
function wc_clean( $text ) {
	return $text;
}
function wp_unslash( $text ) {
	return $text;
}
// The theme's answer (inc/medication-store/helpers.php), for the invented ids in $GLOBALS['fh_ea'].
function fishotel_is_ea_fulfilled_product( $product_id, $variation_id = 0 ) {
	return in_array( (int) $product_id, $GLOBALS['fh_ea'] ?? array(), true );
}

class WP_Term {
	public $slug    = '';
	public $term_id = 1;
}

class FH_Attribute {
	public function get_variation() {
		return true;
	}
	public function get_name() {
		return 'Style';
	}
}

class WC_Product {
	public $id;
	public $type;
	public $parent;
	public $stock;
	public $holder;
	public $backorders = false;
	public $status     = 'publish';
	public $price      = '4.00';
	public $tax_status = 'taxable';
	public function __construct( $id, $type, $stock, $parent = 0, $holder = 0 ) {
		$this->id     = $id;
		$this->type   = $type;
		$this->stock  = $stock;
		$this->parent = $parent;
		$this->holder = $holder ? $holder : $id;
	}
	public function get_id() {
		return $this->id;
	}
	public function get_parent_id() {
		return $this->parent;
	}
	public function is_type( $type ) {
		return $type === $this->type;
	}
	public function get_status() {
		return $this->status;
	}
	public function get_price() {
		return $this->price;
	}
	public function set_price( $price ) {
		$this->price = (string) $price;
	}
	public function is_purchasable() {
		return 'publish' === $this->status && '' !== $this->price;
	}
	public function is_sold_individually() {
		return false;
	}
	public function get_stock_managed_by_id() {
		return $this->holder;
	}
	public function managing_stock() {
		return null !== $this->get_stock_quantity();
	}
	public function get_stock_quantity() {
		return $this->holder === $this->id ? $this->stock : $GLOBALS['fh_products'][ $this->holder ]->stock;
	}
	public function is_in_stock() {
		return ! $this->managing_stock() || $this->backorders || $this->get_stock_quantity() > 0;
	}
	public function get_variation_attributes() {
		return array( 'attribute_style' => 'Night Glow' );
	}
	public function get_attributes() {
		return array( new FH_Attribute() );
	}
	public function get_tax_status() {
		return $this->tax_status;
	}
	public function get_tax_class() {
		return '';
	}
}

class WC_Cart {
	public $cart_contents         = array();
	public $removed_cart_contents = array();
	public $added                 = array();
	public $totalled              = 0;
	public $fees                  = array();
	public function get_cart() {
		return $this->cart_contents;
	}
	public function line( $key, $product_id, $variation_id, $qty, $kind = '' ) {
		$this->cart_contents[ $key ] = array(
			'product_id'   => $product_id,
			'variation_id' => $variation_id,
			'quantity'     => $qty,
			'data'         => wc_get_product( $variation_id ? $variation_id : $product_id ),
		) + ( $kind ? array( 'fh_sticker' => $kind ) : array() );
	}
	public function add_to_cart( $product_id, $qty, $variation_id, $variation, $data ) {
		$this->added[] = array( $product_id, $qty, $variation_id, $variation, $data );
		$this->line( 'added' . count( $this->added ), $product_id, $variation_id, $qty, $data['fh_sticker'] );
		return 'added' . count( $this->added );
	}
	public function remove_cart_item( $key ) {
		$this->removed_cart_contents[ $key ] = $this->cart_contents[ $key ];
		unset( $this->cart_contents[ $key ] );
		$this->calculate_totals(); // As WooCommerce does on `woocommerce_cart_item_removed`.
	}
	public function set_quantity( $key, $qty ) {
		$this->cart_contents[ $key ]['quantity'] = $qty;
	}
	public function calculate_totals() {
		$this->totalled++;
		( new \FisHotel\Misc\Sections\Sticker_Giveaway\Cart() )->apply_pricing( $this ); // `woocommerce_before_calculate_totals`.
		$this->fees = array(); // WooCommerce clears the fees, then asks `woocommerce_cart_calculate_fees`.
		\FisHotel\Misc\Sections\Sticker_Giveaway\Letter::add_fee( $this );
	}
	public function fees_api() {
		return $this;
	}
	public function add_fee( $fee ) {
		$this->fees[ $fee['id'] ] = $fee;
	}
	public function get_cart_item_quantities() {
		$out = array();
		foreach ( $this->cart_contents as $item ) {
			$id         = $item['data']->get_stock_managed_by_id();
			$out[ $id ] = $item['quantity'] + ( $out[ $id ] ?? 0 );
		}
		return $out;
	}
}

class FH_Item {
	public $product;
	public $qty;
	public $total;
	public $kind;
	public function __construct( $product_id, $qty, $total, $kind = '' ) {
		$this->product = $product_id;
		$this->qty     = $qty;
		$this->total   = $total;
		$this->kind    = $kind;
	}
	public function get_product() {
		return wc_get_product( $this->product );
	}
	public function get_product_id() {
		$parent = wc_get_product( $this->product )->get_parent_id();
		return $parent ? $parent : $this->product;
	}
	public function get_variation_id() {
		return wc_get_product( $this->product )->get_parent_id() ? $this->product : 0;
	}
	public function get_meta( $key ) {
		return '_fh_sticker' === $key ? $this->kind : '';
	}
	public function get_name() {
		return 'Sticker #' . $this->product;
	}
	public function get_quantity() {
		return $this->qty;
	}
	public function set_quantity( $qty ) {
		$this->qty = $qty;
	}
	public function get_subtotal() {
		return $this->total;
	}
	public function set_subtotal( $total ) {
	}
	public function get_total() {
		return $this->total;
	}
	public function set_total( $total ) {
		$this->total = $total;
	}
}

class FH_Fee {
	public $name;
	public $meta;
	public function __construct( $name, $meta = '' ) {
		$this->name = $name;
		$this->meta = $meta;
	}
	public function get_meta( $key ) {
		return '_fh_sticker_mail_fee' === $key ? $this->meta : '';
	}
	public function get_name() {
		return $this->name;
	}
}

class WC_Order {
	public $items;
	public $fees          = array();
	public $meta          = array();
	public $status        = 'failed';
	public $stock_reduced = false;
	public $totalled      = 0;
	public $notes         = array();
	public function __construct( array $items ) {
		$this->items = $items;
	}
	public function get_id() {
		return 500;
	}
	public function get_order_key() {
		return 'wc_order_invented';
	}
	public function needs_payment() {
		return in_array( $this->status, array( 'pending', 'failed' ), true );
	}
	public function has_status( $statuses ) {
		return in_array( $this->status, (array) $statuses, true );
	}
	public function get_data_store() {
		return $this;
	}
	public function get_stock_reduced() {
		return $this->stock_reduced;
	}
	public function get_items( $type = 'line_item' ) {
		return 'fee' === $type ? $this->fees : $this->items;
	}
	public function remove_item( $id ) {
		unset( $this->items[ $id ], $this->fees[ $id ] );
	}
	public function get_meta( $key ) {
		return $this->meta[ $key ] ?? '';
	}
	public function update_meta_data( $key, $value ) {
		$this->meta[ $key ] = $value;
	}
	public function delete_meta_data( $key ) {
		unset( $this->meta[ $key ] );
	}
	public function calculate_totals() {
		$this->totalled++;
	}
	public function add_order_note( $note ) {
		$this->notes[] = $note;
	}
}

class FH_Session {
	public $data = array();
	public function get( $key, $default = null ) {
		return $this->data[ $key ] ?? $default;
	}
	public function set( $key, $value ) {
		$this->data[ $key ] = $value;
	}
}

require_once FISHOTEL_MISC_PATH . 'includes/sections/sticker-giveaway/class-cart.php';
require_once FISHOTEL_MISC_PATH . 'includes/sections/sticker-giveaway/class-frontend.php';

use FisHotel\Misc\Sections\Sticker_Giveaway\Cart;
use FisHotel\Misc\Sections\Sticker_Giveaway\Frontend;
use FisHotel\Misc\Sections\Sticker_Giveaway\Sticker_Giveaway;

$failures = 0;
$checks   = 0;

function check( $label, $condition, $detail = '' ) {
	global $failures, $checks;
	$checks++;

	if ( $condition ) {
		echo "  ok   $label\n";
		return;
	}

	$failures++;
	echo "  FAIL $label" . ( '' !== (string) $detail ? "\n       $detail" : '' ) . "\n";
}

/**
 * A fresh shop and a fresh cart holding one fish.
 */
function shop() {
	$GLOBALS['fh_products'] = array(
		9   => new WC_Product( 9, 'simple', null ),
		300 => new WC_Product( 300, 'variable', null ),
		301 => new WC_Product( 301, 'variation', 3, 300 ),
		302 => new WC_Product( 302, 'variation', 50, 300 ),
		400 => new WC_Product( 400, 'variable', 4 ),
		401 => new WC_Product( 401, 'variation', null, 400, 400 ),
		402 => new WC_Product( 402, 'variation', null, 400, 400 ),
		77  => new WC_Product( 77, 'simple', null ),
	);
	$GLOBALS['fh_held']    = array();
	$GLOBALS['fh_options'] = array(
		Sticker_Giveaway::OPTION_KEY => array(
			'sticker_product_ids' => array( 301, 302, 401, 402, 77 ),
			'upsell_max_qty'      => 5,
		),
	);

	$wc          = new stdClass();
	$wc->cart    = new WC_Cart();
	$wc->session = new FH_Session();
	$wc->cart->line( 'fish', 9, 0, 1 );
	$GLOBALS['fh_wc'] = $wc;

	return $wc->cart;
}

function qty( WC_Cart $cart, $key ) {
	return isset( $cart->cart_contents[ $key ] ) ? $cart->cart_contents[ $key ]['quantity'] : 0;
}

echo "1. The picker's choice becomes cart lines\n";
$cart = shop();
Cart::sync( 301, array( 302 => 2, 77 => 1 ) );
check( 'a variation design is added as a variation line: the product, the quantity, the variation, no attributes of our own, the sticker mark', array( 300, 1, 301, array(), array( 'fh_sticker' => 'free' ) ) === $cart->added[0], json_encode( $cart->added[0] ) );
check( 'an add-on of another variation, and of a simple product (no variation id)', array( 300, 2, 302 ) === array_slice( $cart->added[1], 0, 3 ) && array( 77, 1, 0 ) === array_slice( $cart->added[2], 0, 3 ) );
check( 'the cart is read back by design: the variation ids, not the product\'s', array( 'free' => 301, 'extras' => array( 302 => 2, 77 => 1 ) ) === Cart::current_selection() );

$cart = shop();
Cart::sync( 301, array( 301 => 5 ) );
check( 'five add-ons of a design with 3 in stock, one of them already the free sticker: 2 are added, not 5', array( 300, 2, 301 ) === array_slice( $cart->added[1], 0, 3 ), json_encode( $cart->added ) );

$cart = shop();
$cart->line( 'shop', 300, 301, 3 );
Cart::sync( 301, array() );
check( 'the customer is buying the last 3 of a design: no sticker line of it is added', array() === $cart->added );

$cart                              = shop();
$GLOBALS['fh_products'][301]->stock      = 0;
$GLOBALS['fh_products'][301]->backorders = true;
Cart::sync( 301, array() );
check( 'a design at 0 that allows backorders: not added — never a sticker that is not there', array() === $cart->added );

echo "2. A totals pass: the sticker line gives way\n";
$cart = shop();
$cart->line( 'shop', 300, 301, 2 );
$cart->line( 'free', 300, 301, 1, 'free' );
$cart->line( 'add', 300, 301, 2, 'extra' );
$cart->calculate_totals();
check( 'buys 2 of 3, free sticker and two add-ons of the same design: the purchase keeps 2, the free sticker the last one, the add-on goes', 2 === qty( $cart, 'shop' ) && 1 === qty( $cart, 'free' ) && 0 === qty( $cart, 'add' ) );
check( 'the free line is priced at 0; the purchase keeps its price object untouched', '0' === (string) (float) $cart->cart_contents['free']['data']->get_price() );

$cart = shop();
$cart->line( 'free', 400, 401, 1, 'free' );
$cart->line( 'add', 400, 402, 5, 'extra' );
$cart->calculate_totals();
check( 'two designs of a product that keeps ONE stock number (4): the free sticker and 3 add-ons', 1 === qty( $cart, 'free' ) && 3 === qty( $cart, 'add' ) );

$cart = shop();
$cart->line( 'free', 77, 0, 1, 'free' );
$cart->line( 'add', 77, 0, 5, 'extra' );
$cart->calculate_totals();
check( 'a simple product WooCommerce does not track: its lines are left as they are', 1 === qty( $cart, 'free' ) && 5 === qty( $cart, 'add' ) );

echo "3. Just before WooCommerce's own cart check\n";
$guard = new Cart();

$cart = shop();
$cart->line( 'free', 300, 301, 1, 'free' );
$cart->line( 'add', 300, 302, 2, 'extra' );
$GLOBALS['fh_products'][301]->stock = 0; // Another customer took the last ones after the picker was shown.
$guard->guard_lines();
check( 'the design ran out between the picker and Place Order: its sticker line is gone, the fish and the other design stay', 0 === qty( $cart, 'free' ) && 1 === qty( $cart, 'fish' ) && 2 === qty( $cart, 'add' ) );
check( '... and the cart was totalled again, so the order is made from what is in it', $cart->totalled > 0 );

$cart = shop();
$cart->line( 'free', 300, 301, 1, 'free' );
$cart->line( 'add', 300, 301, 2, 'extra' );
$GLOBALS['fh_held'][301] = 1; // One of the 3 is held for another customer's unpaid order.
$cart->totalled          = 0;
$guard->guard_lines();
check( 'stock held for an unpaid order is not given away twice: the add-on is cut from 2 to 1', 1 === qty( $cart, 'free' ) && 1 === qty( $cart, 'add' ) );
check( '... and a quantity cut outside a totals pass totals the cart again', 1 === $cart->totalled );

$cart = shop();
$cart->line( 'shop', 300, 301, 9 );
$cart->line( 'free', 300, 301, 1, 'free' );
$guard->guard_lines();
check( 'a normal purchase of more than there is: the sticker goes, the purchase is left for WooCommerce to answer', 0 === qty( $cart, 'free' ) && 9 === qty( $cart, 'shop' ) );

$cart           = shop();
$cart->totalled = 0;
$cart->line( 'shop', 300, 301, 9 );
$guard->guard_lines();
check( 'a cart without sticker lines: nothing is touched and nothing is totalled', 9 === qty( $cart, 'shop' ) && 0 === $cart->totalled );

echo "4. The customer adds a normal purchase from the shop\n";
// What WooCommerce's add-to-cart handlers do: ask the validation filter, then add.
$shop_adds = static function ( WC_Cart $cart, $product_id, $qty, $variation_id = 0 ) use ( $guard ) {
	return $guard->make_room( true, $product_id, $qty, $variation_id );
};

$cart = shop();
$cart->line( 'free', 300, 301, 1, 'free' );
$shop_adds( $cart, 300, 3, 301 );
check( 'the free sticker is in the cart and the customer adds the last 3 of that design: the sticker steps back, so WooCommerce finds room', 0 === qty( $cart, 'free' ) && 1 === qty( $cart, 'fish' ) );

$cart = shop();
$GLOBALS['fh_products'][301]->stock = 6;
$cart->line( 'free', 300, 301, 1, 'free' );
$cart->line( 'add', 300, 301, 5, 'extra' );
$cart->totalled = 0;
$shop_adds( $cart, 300, 2, 301 );
check( '6 in stock, the free sticker and 5 add-ons, the customer adds 2: the free sticker stays, the add-ons go down to 3', 1 === qty( $cart, 'free' ) && 3 === qty( $cart, 'add' ) );
check( '... and the cart is totalled again', $cart->totalled > 0 );

$cart = shop();
$GLOBALS['fh_products'][400]->stock = 1;
$cart->line( 'free', 400, 401, 1, 'free' );
$shop_adds( $cart, 400, 1, 402 );
check( 'one stock number for two designs, 1 left, a free sticker of one: buying the other is not blocked by it', 0 === qty( $cart, 'free' ) );

$cart = shop();
$cart->line( 'free', 300, 301, 1, 'free' );
$shop_adds( $cart, 301, 2 );
check( 'an archive button hands over the variation itself: 2 of 3 are added and the free sticker keeps the last one', 1 === qty( $cart, 'free' ) );

$cart = shop();
$cart->line( 'free', 300, 301, 1, 'free' );
$cart->line( 'add', 300, 301, 2, 'extra' );
$shop_adds( $cart, 300, 5, 301 );
check( 'the customer asks for 5 of a design with 3: that refusal is WooCommerce\'s own — the sticker lines are left alone', 1 === qty( $cart, 'free' ) && 2 === qty( $cart, 'add' ) );

$cart = shop();
$cart->line( 'free', 300, 301, 1, 'free' );
check( 'the answer of the validation is never changed', true === $guard->make_room( true, 300, 3, 301 ) && false === $guard->make_room( false, 300, 3, 301 ) );

$cart = shop();
$cart->line( 'free', 300, 301, 1, 'free' );
$guard->make_room( false, 300, 3, 301 );
check( 'an add another plugin already refused: nothing is touched', 1 === qty( $cart, 'free' ) );

echo "5. The picker offers what can be added\n";
$settings = $GLOBALS['fh_options'][ Sticker_Giveaway::OPTION_KEY ];

$cart = shop();
check( 'every design with a sticker to give is offered', array( 301, 302, 401, 402, 77 ) === array_keys( Frontend::available_pool( $settings ) ) );

$cart->line( 'shop', 300, 301, 3 );
check( 'the customer is buying the last 3 of a design: it is not offered (it could be picked but never added)', array( 302, 401, 402, 77 ) === array_keys( Frontend::available_pool( $settings ) ) );

$cart = shop();
$cart->line( 'shop', 300, 301, 2 );
check( 'buying 2 of 3: still offered — one is left to give', isset( Frontend::available_pool( $settings )[301] ) );

$cart = shop();
$cart->line( 'shop', 400, 401, 4 );
check( 'one stock number for two designs, all 4 in the cart as a purchase of one: neither is offered', array( 301, 302, 77 ) === array_keys( Frontend::available_pool( $settings ) ) );

$cart = shop();
$cart->line( 'free', 300, 301, 1, 'free' );
$cart->line( 'add', 300, 301, 2, 'extra' );
check( 'a design whose stock is all in the cart as STICKER lines stays on the picker (they are its own lines)', isset( Frontend::available_pool( $settings )[301] ) );

echo "6. An order whose payment failed comes back to be paid\n";
$pay = static function ( array $items, $key = 'wc_order_invented' ) use ( $guard ) {
	$order                    = new WC_Order( $items );
	$GLOBALS['fh_orders']     = array( 500 => $order );
	$GLOBALS['wp']            = new stdClass();
	$GLOBALS['wp']->query_vars = array( 'order-pay' => 500 );
	$_GET['key']              = $key;
	return $order;
};
$order_items = static function () {
	return array(
		1 => new FH_Item( 9, 1, 40.0 ),
		2 => new FH_Item( 301, 1, 0.0, 'free' ),
		3 => new FH_Item( 301, 4, 4.0, 'extra' ),
	);
};

shop();
$GLOBALS['fh_products'][301]->stock = 0;
$order = $pay( $order_items() );
$guard->guard_order();
check( 'the design ran out since: its sticker lines are taken off the order, the fish stays', array( 1 ) === array_keys( $order->items ) );
check( '... the order is totalled again and a note says what happened', 1 === $order->totalled && 1 === count( $order->notes ) && false !== strpos( $order->notes[0], 'Sticker #301' ) );

shop();
$order = $pay( $order_items() );
$guard->guard_order();
check( '3 left for a free sticker and 4 add-ons: the free one stays, the add-ons go down to 2 and their price with them', 1 === $order->items[2]->qty && 2 === $order->items[3]->qty && 2.0 === $order->items[3]->total );

shop();
$order = $pay( array( 1 => new FH_Item( 301, 3, 12.0 ), 2 => new FH_Item( 301, 1, 0.0, 'free' ) ) );
$guard->guard_order();
check( 'the order buys the last 3 and has a free one of the same design: the sticker gives way, the purchase keeps its 3', array( 1 ) === array_keys( $order->items ) && 3 === $order->items[1]->qty );

shop();
$GLOBALS['fh_products'][301]->stock = 9;
$order = $pay( $order_items() );
$guard->guard_order();
check( 'everything still in stock: the order is not touched, not totalled, no note', 3 === count( $order->items ) && 4 === $order->items[3]->qty && 0 === $order->totalled && array() === $order->notes );

shop();
$GLOBALS['fh_products'][301]->stock = 0;
$order = $pay( array( 1 => new FH_Item( 9, 1, 40.0 ), 2 => new FH_Item( 301, 5, 20.0 ) ) );
$guard->guard_order();
check( 'a NORMAL purchase that ran out is never touched: that answer is WooCommerce\'s', 5 === $order->items[2]->qty && 0 === $order->totalled );

shop();
$GLOBALS['fh_products'][301]->stock = 0;
$order = $pay( $order_items(), 'somebody-elses-key' );
$guard->guard_order();
check( 'without the order\'s own key nothing is touched', 3 === count( $order->items ) );

$order         = $pay( $order_items() );
$order->status = 'pending';
$guard->guard_order();
check( 'an order still pending (WooCommerce holds its stock and does not check): not touched', 3 === count( $order->items ) );

$order                = $pay( $order_items() );
$order->stock_reduced = true;
$guard->guard_order();
check( 'an order whose stock was already taken: not touched', 3 === count( $order->items ) );

echo "7. An EA-only order: the mailing fee follows the sticker lines when they step back\n";
/**
 * A cart that holds only a medication the supplier drop-ships (#600).
 */
function ea_shop() {
	$cart                   = shop();
	$GLOBALS['fh_ea']       = array( 600 );
	$GLOBALS['fh_products'][600] = new WC_Product( 600, 'simple', null );
	unset( $cart->cart_contents['fish'] );
	$cart->line( 'med', 600, 0, 1 );
	return $cart;
}

$cart = ea_shop();
$cart->line( 'add', 300, 301, 2, 'extra' );
$cart->calculate_totals();
check( 'an EA medication and 2 add-ons: one mailing fee of 1.00', array( 'fh-sticker-mailing' ) === array_keys( $cart->fees ) && 1.0 === $cart->fees['fh-sticker-mailing']['amount'] );
$shop_adds( $cart, 300, 3, 301 );
check( 'the customer adds the last 3 of that design from the shop: the add-ons step back (make_room) and the fee goes with them', 0 === qty( $cart, 'add' ) && array() === $cart->fees );

$cart = ea_shop();
$cart->line( 'add', 300, 301, 1, 'extra' );
$cart->line( 'add2', 300, 302, 2, 'extra' );
$cart->calculate_totals();
$shop_adds( $cart, 300, 3, 301 );
check( 'one add-on steps back, another design is still a paid sticker: still ONE fee', 0 === qty( $cart, 'add' ) && 2 === qty( $cart, 'add2' ) && 1 === count( $cart->fees ) );

$cart                                   = ea_shop();
$cart->line( 'add', 300, 301, 2, 'extra' );
$GLOBALS['fh_products'][301]->stock = 0;
$guard->guard_lines();
check( 'the design ran out before Place Order (guard_lines): the add-on goes, and no fee is left', 0 === qty( $cart, 'add' ) && array() === $cart->fees );

$ea_order = static function ( array $items ) use ( $pay ) {
	$order                                            = $pay( $items );
	$order->fees                                      = array( 9 => new FH_Fee( 'Sticker mailing (sent separately by letter)', '1' ) );
	$order->meta['_fishotel_sticker_mail_letter'] = 3;
	return $order;
};

ea_shop();
$GLOBALS['fh_products'][301]->stock = 0;
$order = $ea_order( array( 1 => new FH_Item( 600, 1, 20.0 ), 3 => new FH_Item( 301, 3, 2.97, 'extra' ) ) );
$guard->guard_order();
check( 'pay page of a failed EA-only order, the paid design ran out (guard_order): the stickers, the fee and the flag go', array( 1 ) === array_keys( $order->items ) && array() === $order->fees && ! isset( $order->meta['_fishotel_sticker_mail_letter'] ) && 1 === $order->totalled );
check( '... and a note says no sticker is left to mail (the first "MAIL STICKER BY LETTER" note is out of date)', 1 === count( preg_grep( '/^Sticker Giveaway: No sticker is left to mail by letter/', $order->notes ) ) );

ea_shop();
$GLOBALS['fh_products'][301]->stock = 0;
$order = $ea_order( array( 1 => new FH_Item( 600, 1, 20.0 ), 2 => new FH_Item( 302, 1, 0.0, 'free' ), 3 => new FH_Item( 301, 2, 1.98, 'extra' ) ) );
$guard->guard_order();
check( '... the free sticker is left, no paid one: no fee, 1 sticker still to mail', array( 1, 2 ) === array_keys( $order->items ) && array() === $order->fees && 1 === $order->meta['_fishotel_sticker_mail_letter'] );
check( '... and a fresh note with the new number', in_array( 'MAIL STICKER BY LETTER: 1 sticker(s) — EA-only order, nothing ships from FisHotel.', $order->notes, true ) );

ea_shop();
$GLOBALS['fh_products'][301]->stock = 2;
$order = $ea_order( array( 1 => new FH_Item( 600, 1, 20.0 ), 3 => new FH_Item( 301, 4, 3.96, 'extra' ) ) );
$guard->guard_order();
check( '... the paid stickers are cut from 4 to 2: the fee stays (one), 2 to mail', 2 === $order->items[3]->qty && 1 === count( $order->fees ) && 2 === $order->meta['_fishotel_sticker_mail_letter'] );
check( '... with a fresh note: 2', in_array( 'MAIL STICKER BY LETTER: 2 sticker(s) — EA-only order, nothing ships from FisHotel.', $order->notes, true ) );

shop();
$GLOBALS['fh_ea']                   = array( 600 );
$GLOBALS['fh_products'][600]        = new WC_Product( 600, 'simple', null );
$GLOBALS['fh_products'][301]->stock = 0;
$order                              = $pay( array( 1 => new FH_Item( 9, 1, 40.0 ), 2 => new FH_Item( 600, 1, 20.0 ), 3 => new FH_Item( 301, 2, 1.98, 'extra' ) ) );
$guard->guard_order();
check( 'a mixed order (fish and a medication) loses a sticker: nothing about letters is written', array() === $order->meta && array() === $order->fees && array() === preg_grep( '/letter/i', $order->notes ) );

echo "\n" . ( $failures ? "$failures of $checks checks FAILED\n" : "All $checks checks passed\n" );
exit( $failures ? 1 : 0 );
