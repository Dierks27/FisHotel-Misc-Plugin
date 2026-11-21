<?php
/**
 * The cart class played through stand-ins for WooCommerce's cart and
 * products: a variation design goes into the cart as a variation line,
 * and a sticker line gives way to the stock — on a totals pass and just
 * before WooCommerce's own cart check — while a normal purchase is never
 * touched.
 *
 * The stand-ins do what WooCommerce 11.2's own classes do at the calls
 * the section makes (a removed line totals the cart again; quantities
 * are summed per product that holds the stock). They are not
 * WooCommerce: that a real cart behaves the same is proved on a
 * rehearsal site.
 *
 * Invented shop: "Reef Stickers" (#300), variations #301 "Night Glow"
 * and #302 "Coral Pop" on a custom attribute, each with its own stock;
 * "Logo Stickers" (#400) on a global attribute, keeping ONE stock number
 * for #401 and #402; "Round Logo Sticker" (#77), a simple product; a
 * fish (#9).
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
}

class WC_Cart {
	public $cart_contents         = array();
	public $removed_cart_contents = array();
	public $added                 = array();
	public $totalled              = 0;
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

use FisHotel\Misc\Sections\Sticker_Giveaway\Cart;
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

echo "\n" . ( $failures ? "$failures of $checks checks FAILED\n" : "All $checks checks passed\n" );
exit( $failures ? 1 : 0 );
