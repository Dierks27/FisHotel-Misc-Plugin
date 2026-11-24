<?php
/**
 * EA-only orders: the sticker is mailed by letter.
 *
 * The decisions (Rules::is_ea_only(), sticker_counts(), mail_letter())
 * headless, then the Letter class played through stand-ins for
 * WooCommerce's cart, products, orders and emails: the $1.00 fee beside
 * the $0.99 add-on lines, the customer's message, the flagged order (meta,
 * private note, emails, orders-list badge) and the Today line.
 *
 * "EA" is asked of the theme's own `fishotel_is_ea_fulfilled_product()`;
 * the stand-in below is that function as the theme 1.27.0 has it
 * (inc/medication-store/helpers.php), reading invented post meta.
 *
 * Invented shop: a fish (#9); "Invented Prazi Powder" (#600), a variable
 * medication in EA mode whose variation #601 carries the EA SKU and #602
 * none; "Invented Copper Test" (#700), a simple EA medication; "Invented
 * Net" (#800), a product FisHotel ships itself (mode "self"); stickers:
 * "Reef Stickers" (#300) with variations #301 "Night Glow" (taxable,
 * reduced rate) and #302 "Coral Pop", and "Round Logo Sticker" (#77, no
 * tax).
 *
 * Run from the plugin root:
 *   php includes/sections/sticker-giveaway/tests/letter-test.php
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
function esc_html( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}
function get_post_status( $id ) {
	return 'publish';
}
function get_ancestors() {
	return array();
}
function get_the_terms( $id ) {
	$term       = new WP_Term();
	$term->slug = 9 === $id ? 'quarantined-fish' : ( in_array( $id, array( 600, 700, 800 ), true ) ? 'medications' : 'merchandise' );
	return array( $term );
}
function get_post_meta( $id, $key, $single = false ) {
	return $GLOBALS['fh_meta'][ $id ][ $key ] ?? '';
}
function wc_get_product( $id ) {
	return $GLOBALS['fh_products'][ $id ] ?? false;
}
function WC() {
	return $GLOBALS['fh_wc'];
}
function wc_get_order( $id ) {
	return $GLOBALS['fh_orders'][ $id ] ?? false;
}
function wc_get_order_statuses() {
	return array(
		'wc-pending'    => 'Pending payment',
		'wc-processing' => 'Processing',
		'wc-on-hold'    => 'On hold',
		'wc-shipped'    => 'Shipped',
		'wc-completed'  => 'Completed',
		'wc-cancelled'  => 'Cancelled',
		'wc-refunded'   => 'Refunded',
		'wc-failed'     => 'Failed',
	);
}
function wc_get_orders( $args ) {
	$GLOBALS['fh_query'] = $args;
	$out                 = array();
	foreach ( $GLOBALS['fh_orders'] ?? array() as $order ) {
		if ( in_array( $order->status, $args['status'], true ) ) {
			$out[] = $order; // Like the posts store, the meta part is not applied here: the class checks again.
		}
	}
	return $out;
}
function admin_url( $path = '' ) {
	return 'https://example.test/wp-admin/' . $path;
}
function current_user_can( $cap ) {
	return ! empty( $GLOBALS['fh_can'] );
}

// The theme's answer, as theme 1.27.0 has it (inc/medication-store/helpers.php).
function fishotel_is_ea_fulfilled_product( $product_id, $variation_id = 0 ) {
	$product_id = (int) $product_id;
	if ( ! $product_id ) {
		return false;
	}
	$raw_mode = get_post_meta( $product_id, '_fishotel_fulfillment', true );
	if ( 'ea' !== $raw_mode ) {
		return false;
	}
	$parent_sku = (string) get_post_meta( $product_id, '_fishotel_ea_sku', true );
	$var_sku    = $variation_id ? (string) get_post_meta( (int) $variation_id, '_fishotel_ea_sku', true ) : '';
	return ( '' !== $parent_sku || '' !== $var_sku );
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
	public $price;
	public $tax_status = 'taxable';
	public $tax_class  = '';
	public function __construct( $id, $type, $price = '4.00', $parent = 0 ) {
		$this->id     = $id;
		$this->type   = $type;
		$this->price  = $price;
		$this->parent = $parent;
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
		return 'publish';
	}
	public function get_price() {
		return $this->price;
	}
	public function set_price( $price ) {
		$this->price = (string) $price;
	}
	public function is_purchasable() {
		return '' !== $this->price;
	}
	public function is_sold_individually() {
		return false;
	}
	public function get_stock_managed_by_id() {
		return $this->id;
	}
	public function managing_stock() {
		return false;
	}
	public function get_stock_quantity() {
		return null;
	}
	public function is_in_stock() {
		return true;
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
		return $this->tax_class;
	}
}

class WC_Cart {
	public $cart_contents         = array();
	public $removed_cart_contents = array();
	public $fees                  = array();
	public function get_cart() {
		return $this->cart_contents;
	}
	public function line( $key, $product_id, $variation_id, $qty, $kind = '' ) {
		$this->cart_contents[ $key ] = array(
			'product_id'   => $product_id,
			'variation_id' => $variation_id,
			'quantity'     => $qty,
			'data'         => clone wc_get_product( $variation_id ? $variation_id : $product_id ),
		) + ( $kind ? array( 'fh_sticker' => $kind ) : array() );
		$this->calculate_totals(); // As WooCommerce does after add_to_cart().
	}
	public function remove_cart_item( $key ) {
		$this->removed_cart_contents[ $key ] = $this->cart_contents[ $key ];
		unset( $this->cart_contents[ $key ] );
		$this->calculate_totals(); // As WooCommerce does on `woocommerce_cart_item_removed`.
	}
	public function calculate_totals() {
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
		return array();
	}
	/** What the sticker lines cost (unit price × quantity). */
	public function sticker_total() {
		$total = 0.0;
		foreach ( $this->cart_contents as $item ) {
			if ( ! empty( $item['fh_sticker'] ) ) {
				$total += (float) $item['data']->get_price() * $item['quantity'];
			}
		}
		return round( $total, 2 );
	}
}

class FH_Item {
	public $product;
	public $variation;
	public $qty;
	public $kind;
	public function __construct( $product_id, $variation_id, $qty, $kind = '' ) {
		$this->product   = $product_id;
		$this->variation = $variation_id;
		$this->qty       = $qty;
		$this->kind      = $kind;
	}
	public function get_product_id() {
		return $this->product;
	}
	public function get_variation_id() {
		return $this->variation;
	}
	public function get_meta( $key ) {
		return '_fh_sticker' === $key ? $this->kind : '';
	}
	public function get_quantity() {
		return $this->qty;
	}
}

class FH_Fee_Item {
	public $meta = array();
	public function add_meta_data( $key, $value, $unique = false ) {
		$this->meta[ $key ] = $value;
	}
}

class WC_Order {
	public $id;
	public $items;
	public $status = 'processing';
	public $meta   = array();
	public $notes  = array();
	public $saved  = 0;
	public function __construct( $id, array $items ) {
		$this->id    = $id;
		$this->items = $items;
	}
	public function get_id() {
		return $this->id;
	}
	public function get_order_number() {
		return (string) $this->id;
	}
	public function get_edit_order_url() {
		return 'https://example.test/wp-admin/admin.php?page=wc-orders&action=edit&id=' . $this->id;
	}
	public function get_items( $type = 'line_item' ) {
		return 'line_item' === $type ? $this->items : array();
	}
	public function has_status( $statuses ) {
		return in_array( $this->status, (array) $statuses, true );
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
	public function save() {
		$this->saved++;
	}
	public function add_order_note( $note, $is_customer_note = 0 ) {
		$this->notes[] = array( $note, $is_customer_note );
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
use FisHotel\Misc\Sections\Sticker_Giveaway\Letter;
use FisHotel\Misc\Sections\Sticker_Giveaway\Rules;
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
 * A fresh shop and an empty cart.
 *
 * @param string[] $categories The categories that get a free sticker.
 */
function shop( array $categories = array( 'quarantined-fish' ) ) {
	$GLOBALS['fh_products'] = array(
		9   => new WC_Product( 9, 'simple', '40.00' ),
		600 => new WC_Product( 600, 'variable', '' ),
		601 => new WC_Product( 601, 'variation', '18.00', 600 ),
		602 => new WC_Product( 602, 'variation', '30.00', 600 ),
		700 => new WC_Product( 700, 'simple', '12.00' ),
		800 => new WC_Product( 800, 'simple', '9.00' ),
		300 => new WC_Product( 300, 'variable', '' ),
		301 => new WC_Product( 301, 'variation', '4.00', 300 ),
		302 => new WC_Product( 302, 'variation', '4.00', 300 ),
		77  => new WC_Product( 77, 'simple', '4.00' ),
	);
	$GLOBALS['fh_products'][301]->tax_class = 'reduced-rate';
	$GLOBALS['fh_products'][77]->tax_status = 'none';

	$GLOBALS['fh_meta'] = array(
		600 => array( '_fishotel_fulfillment' => 'ea' ),
		601 => array( '_fishotel_ea_sku' => 'INV-PRAZI-5G' ),
		700 => array(
			'_fishotel_fulfillment' => 'ea',
			'_fishotel_ea_sku'      => 'INV-CU-TEST',
		),
		800 => array( '_fishotel_fulfillment' => 'self' ),
	);

	$GLOBALS['fh_options'] = array(
		Sticker_Giveaway::OPTION_KEY => array(
			'sticker_product_ids'     => array( 301, 302, 77 ),
			'eligible_category_slugs' => $categories,
			'upsell_enabled'          => true,
			'upsell_price'            => 0.99,
			'upsell_max_qty'          => 5,
			'upsell_for_everyone'     => true,
		),
	);

	$wc               = new stdClass();
	$wc->cart         = new WC_Cart();
	$wc->session      = new FH_Session();
	$GLOBALS['fh_wc'] = $wc;

	return $wc->cart;
}

function fee( WC_Cart $cart ) {
	return $cart->fees['fh-sticker-mailing'] ?? null;
}

function shown( $callback ) {
	ob_start();
	call_user_func( $callback );
	return (string) ob_get_clean();
}

$message = "Heads up: your meds ship straight from our supplier, so your sticker gets mailed to you separately as a letter. You'll get an email with tracking once it's on its way. If you haven't gotten it in 2 weeks, just let us know and we'll send another one.";

echo "1. The decisions (pure)\n";
$l = static function ( $kind, $qty, $is_ea = false ) {
	return array(
		'kind'  => $kind,
		'qty'   => $qty,
		'is_ea' => $is_ea,
	);
};
check( 'EA-only: every non-sticker line is EA, and there is one', Rules::is_ea_only( array( $l( '', 1, true ), $l( '', 2, true ), $l( 'extra', 1 ) ) ) );
check( 'one line FisHotel ships makes it mixed', ! Rules::is_ea_only( array( $l( '', 1, true ), $l( '', 1, false ), $l( 'extra', 1 ) ) ) );
check( 'stickers only, or nothing at all, is not EA-only', ! Rules::is_ea_only( array( $l( 'extra', 2 ) ) ) && ! Rules::is_ea_only( array() ) );
check( 'stickers are counted by kind and quantity', array( 'free' => 1, 'paid' => 5 ) === Rules::sticker_counts( array( $l( 'free', 1 ), $l( 'extra', 3 ), $l( 'extra', 2 ), $l( '', 7, true ) ) ) );
check( 'EA-only + 1 paid: a letter of 1, one fee of 1.00', array( 'letter' => true, 'count' => 1, 'paid' => 1, 'fee' => 1.0 ) === Rules::mail_letter( array( $l( '', 1, true ), $l( 'extra', 1 ) ) ) );
check( 'EA-only + 3 paid: a letter of 3, still ONE fee of 1.00', array( 'letter' => true, 'count' => 3, 'paid' => 3, 'fee' => 1.0 ) === Rules::mail_letter( array( $l( '', 1, true ), $l( 'extra', 3 ) ) ) );
check( 'EA-only + the free sticker only: a letter of 1, no fee', array( 'letter' => true, 'count' => 1, 'paid' => 0, 'fee' => 0.0 ) === Rules::mail_letter( array( $l( '', 1, true ), $l( 'free', 1 ) ) ) );
check( 'EA-only + free + 1 paid: a letter of 2, one fee', array( 'letter' => true, 'count' => 2, 'paid' => 1, 'fee' => 1.0 ) === Rules::mail_letter( array( $l( '', 1, true ), $l( 'free', 1 ), $l( 'extra', 1 ) ) ) );
check( 'mixed, fish-only, sticker-only and EA-only without a sticker: no letter, no fee', ! Rules::mail_letter( array( $l( '', 1, true ), $l( '', 1 ), $l( 'extra', 2 ) ) )['letter'] && 0.0 === Rules::mail_letter( array( $l( '', 1 ), $l( 'extra', 2 ) ) )['fee'] && 0 === Rules::mail_letter( array( $l( 'extra', 2 ) ) )['count'] && ! Rules::mail_letter( array( $l( '', 1, true ) ) )['letter'] );

echo "2. Which lines are EA: the theme's answer\n";
shop();
check( 'a simple medication in EA mode with an EA SKU', Letter::is_ea_line( 700 ) );
check( 'a variation of an EA product, asked as the theme asks it (parent, variation): EA by the variation\'s SKU', Letter::is_ea_line( 600, 601 ) );
check( '... its sister variation without an EA SKU anywhere: not EA (the theme says so)', ! Letter::is_ea_line( 600, 602 ) );
check( '... and a variation id handed over as the product is not EA: the mode lives on the parent', ! Letter::is_ea_line( 601 ) );
check( 'a product FisHotel ships itself ("self"), a fish, a sticker: not EA', ! Letter::is_ea_line( 800 ) && ! Letter::is_ea_line( 9 ) && ! Letter::is_ea_line( 300, 301 ) );

echo "3. The cart: the fee, the price of the stickers, the message\n";
$cart = shop();
$cart->line( 'med', 700, 0, 1 );
$cart->line( 'add', 300, 301, 1, 'extra' );
check( 'EA-only + 1 paid sticker: the sticker line is 0.99 and ONE fee of 1.00 "Sticker mailing (sent separately by letter)"', 0.99 === $cart->sticker_total() && 1 === count( $cart->fees ) && 1.0 === fee( $cart )['amount'] && 'Sticker mailing (sent separately by letter)' === fee( $cart )['name'] );
check( '... the fee is taxed as the sticker product is: taxable, its tax class', true === fee( $cart )['taxable'] && 'reduced-rate' === fee( $cart )['tax_class'] );
check( '... the message is shown in the cart, word for word', '<div class="fh-sticker-letter"><p class="fh-sticker-letter-note">' . esc_html( $message ) . '</p></div>' === shown( array( Letter::class, 'cart_notice' ) ) );
check( '... and on checkout, as a row of the statement under the items', 0 === strpos( shown( array( Letter::class, 'review_notice' ) ), '<tr class="fh-sticker-letter"><td colspan="3"><p class="fh-sticker-letter-note">Heads up: your meds ship straight from our supplier' ) );

$cart = shop();
$cart->line( 'med', 600, 601, 2 );
$cart->line( 'add', 300, 301, 3, 'extra' );
check( 'a variation of an EA product + 3 paid: 2.97 for the stickers and still one 1.00 fee (no averaging into the sticker price)', 2.97 === $cart->sticker_total() && 1 === count( $cart->fees ) && '0.99' === (string) (float) $cart->cart_contents['add']['data']->get_price() );

$cart = shop();
$cart->line( 'med', 700, 0, 1 );
$cart->line( 'add', 77, 0, 2, 'extra' );
check( 'a sticker product with no tax: the fee is not taxed either', false === fee( $cart )['taxable'] && '' === fee( $cart )['tax_class'] );

$cart = shop( array( 'quarantined-fish', 'medications' ) ); // The owner ticked medications for the free sticker.
$cart->line( 'med', 700, 0, 1 );
$cart->line( 'free', 300, 302, 1, 'free' );
check( 'EA-only + the free sticker only: no fee (the shop pays the stamp), the sticker is free', array() === $cart->fees && 0.0 === $cart->sticker_total() );
check( '... and the message is shown', false !== strpos( shown( array( Letter::class, 'cart_notice' ) ), 'Heads up:' ) );
$cart->line( 'add', 300, 301, 1, 'extra' );
check( 'free + 1 paid: one fee', 1 === count( $cart->fees ) && 0.99 === $cart->sticker_total() );

$cart = shop();
$cart->line( 'fish', 9, 0, 1 );
$cart->line( 'med', 700, 0, 1 );
$cart->line( 'add', 300, 301, 2, 'extra' );
check( 'mixed (a fish and an EA medication) + 2 paid: the plain 1.98, no fee, no message', 1.98 === $cart->sticker_total() && array() === $cart->fees && '' === shown( array( Letter::class, 'cart_notice' ) ) . shown( array( Letter::class, 'review_notice' ) ) );

$cart = shop();
$cart->line( 'net', 800, 0, 1 );
$cart->line( 'med', 700, 0, 1 );
$cart->line( 'add', 300, 301, 1, 'extra' );
check( 'an EA medication beside a product FisHotel ships itself: mixed, no fee, no message', array() === $cart->fees && '' === shown( array( Letter::class, 'cart_notice' ) ) );

$cart = shop();
$cart->line( 'fish', 9, 0, 1 );
$cart->line( 'free', 300, 302, 1, 'free' );
$cart->line( 'add', 300, 301, 1, 'extra' );
check( 'fish only: free sticker and a 0.99 add-on as always, no fee, no message', 1 === $cart->cart_contents['free']['quantity'] && 0.99 === $cart->sticker_total() && array() === $cart->fees && '' === shown( array( Letter::class, 'cart_notice' ) ) );

$cart = shop();
$cart->line( 'add', 300, 301, 2, 'extra' );
check( 'stickers only (out of scope, left as today): no fee, no message', array() === $cart->fees && '' === shown( array( Letter::class, 'cart_notice' ) ) );

$cart = shop();
$cart->line( 'med', 700, 0, 1 );
$cart->line( 'fish', 9, 0, 1 );
$cart->line( 'add', 300, 301, 1, 'extra' );
check( 'a fish and a medication with a paid sticker: no fee', array() === $cart->fees );
$cart->remove_cart_item( 'fish' );
check( 'the last fish is removed: the cart is EA-only, the fee and the message appear', 1 === count( $cart->fees ) && '' !== shown( array( Letter::class, 'cart_notice' ) ) );
$cart->line( 'fish2', 9, 0, 1 );
check( 'a fish is added again: both go away', array() === $cart->fees && '' === shown( array( Letter::class, 'cart_notice' ) ) );
$cart->remove_cart_item( 'fish2' );
check( '... and it is removed again: both are back', 1 === count( $cart->fees ) && '' !== shown( array( Letter::class, 'review_notice' ) ) );
$cart->remove_cart_item( 'add' );
check( 'the paid sticker is removed: no sticker, no fee, no message', array() === $cart->fees && '' === shown( array( Letter::class, 'cart_notice' ) ) );

echo "4. The placed order\n";
$order_of = static function ( $id, array $items ) {
	$order                       = new WC_Order( $id, $items );
	$GLOBALS['fh_orders'][ $id ] = $order;
	return $order;
};
shop();
$GLOBALS['fh_orders'] = array();

$order = $order_of( 1234, array( new FH_Item( 600, 601, 1 ), new FH_Item( 300, 302, 1, 'free' ), new FH_Item( 300, 301, 1, 'extra' ) ) );
Letter::flag_order( 1234 );
check( 'free + 1 paid on an EA-only order: meta _fishotel_sticker_mail_letter = 2', 2 === $order->meta['_fishotel_sticker_mail_letter'] && $order->saved > 0 );
check( '... and one private note, word for word', array( array( 'MAIL STICKER BY LETTER: 2 sticker(s) — EA-only order, nothing ships from FisHotel.', 0 ) ) === $order->notes );
Letter::flag_order( $order );
check( 'the same order handed over again (the block checkout hands the order itself): no second note', 1 === count( $order->notes ) );

$order = $order_of( 1240, array( new FH_Item( 700, 0, 1 ), new FH_Item( 300, 302, 1, 'free' ) ) );
Letter::flag_order( 1240 );
check( 'the free sticker only: flagged 1', 1 === $order->meta['_fishotel_sticker_mail_letter'] && 1 === count( $order->notes ) );

$order = $order_of( 1250, array( new FH_Item( 9, 0, 1 ), new FH_Item( 700, 0, 1 ), new FH_Item( 300, 301, 2, 'extra' ) ) );
Letter::flag_order( 1250 );
check( 'a mixed order: no meta, no note', array() === $order->meta && array() === $order->notes );

$order = $order_of( 1251, array( new FH_Item( 700, 0, 1 ) ) );
Letter::flag_order( 1251 );
check( 'an EA-only order without a sticker: no meta, no note', array() === $order->meta && array() === $order->notes );

$order                                         = $order_of( 1252, array( new FH_Item( 9, 0, 1 ), new FH_Item( 700, 0, 1 ), new FH_Item( 300, 301, 1, 'extra' ) ) );
$order->meta['_fishotel_sticker_mail_letter'] = 1; // A first try was EA-only; the customer added a fish and tried again.
Letter::flag_order( 1252 );
check( 'a checkout tried again on the same order, now with a fish: the flag goes', ! isset( $order->meta['_fishotel_sticker_mail_letter'] ) );

$fee_item = new FH_Fee_Item();
Letter::mark_fee_item( $fee_item, 'fh-sticker-mailing', (object) array( 'id' => 'fh-sticker-mailing' ), null );
$other = new FH_Fee_Item();
Letter::mark_fee_item( $other, 'x', (object) array( 'id' => 'gift-wrap' ), null );
check( 'the order\'s fee line is marked as the mailing fee; another fee is not', array( '_fh_sticker_mail_fee' => '1' ) === $fee_item->meta && array() === $other->meta );

$flagged = $GLOBALS['fh_orders'][1234];
$plain   = $GLOBALS['fh_orders'][1250];
$email   = static function ( $order, $id, $plain_text = false ) {
	return shown(
		static function () use ( $order, $id, $plain_text ) {
			Letter::email_line( $order, 'new_order' === $id, $plain_text, (object) array( 'id' => $id ) );
		}
	);
};
check( 'the processing email of a flagged order carries the message', false !== strpos( $email( $flagged, 'customer_processing_order' ), esc_html( $message ) ) );
check( '... the on-hold email too, and the plain-text one as plain text', false !== strpos( $email( $flagged, 'customer_on_hold_order' ), 'Heads up:' ) && $message . "\n\n" === html_entity_decode( $email( $flagged, 'customer_processing_order', true ), ENT_QUOTES ) );
check( '... the admin new-order email has its one line', false !== strpos( $email( $flagged, 'new_order' ), 'MAIL STICKER BY LETTER: 2 sticker(s)' ) );
check( '... the completed email and the invoice do not', '' === $email( $flagged, 'customer_completed_order' ) . $email( $flagged, 'customer_invoice' ) );
check( 'an order that is not flagged: no line in any email', '' === $email( $plain, 'customer_processing_order' ) . $email( $plain, 'new_order' ) );

echo "5. The admin orders list (legacy and HPOS)\n";
$columns = Letter::add_column(
	array(
		'cb'           => '',
		'order_number' => 'Order',
		'order_status' => 'Status',
		'order_total'  => 'Total',
	)
);
check( 'the "Sticker letter" column comes right after Status', array( 'cb', 'order_number', 'order_status', 'fh_sticker_letter', 'order_total' ) === array_keys( $columns ) );
check( 'on a table without a Status column it goes at the end', array( 'a', 'fh_sticker_letter' ) === array_keys( Letter::add_column( array( 'a' => 'A' ) ) ) );
$cell = static function ( $order_or_id, $column = 'fh_sticker_letter' ) {
	return shown(
		static function () use ( $order_or_id, $column ) {
			Letter::render_column( $column, $order_or_id );
		}
	);
};
check( 'HPOS hands the order: an open flagged order shows the badge "Mail sticker (2)"', false !== strpos( $cell( $flagged ), '<mark class="order-status status-on-hold fh-sticker-letter-badge"><span>Mail sticker (2)</span></mark>' ) );
check( 'the legacy table hands the post id: the same badge', $cell( $flagged ) === $cell( 1234 ) );
$GLOBALS['fh_orders'][1240]->status = 'completed';
check( 'a completed flagged order: a quiet "By letter (1)"', false !== strpos( $cell( 1240 ), 'By letter (1)' ) && false === strpos( $cell( 1240 ), '<mark' ) );
check( 'an order without a letter, and another column: nothing', '' === $cell( 1250 ) . $cell( 1234, 'order_total' ) );

echo "6. The Today page\n";
check( 'one order: "Stickers to mail by letter: #1234 (2)"', 'Stickers to mail by letter: #1234 (2)' === Letter::today_line( array( array( 'number' => '1234', 'count' => 2 ) ), 'u' )['text'] );
$many = array();
for ( $n = 1; $n <= 30; $n++ ) {
	$many[] = array(
		'number' => (string) ( 2000 + $n ),
		'count'  => 1,
	);
}
$line = Letter::today_line( $many, 'u' );
check( 'thirty orders: as many as fit 160 characters, then "and N more"; the count is all of them', strlen( $line['text'] ) <= 160 && preg_match( '/and \d+ more$/', $line['text'] ) && 30 === $line['count'] && 'customers' === $line['group'] && 'todo' === $line['tone'] );
check( 'nothing to mail: no line', null === Letter::today_line( array(), 'u' ) );

$GLOBALS['fh_can'] = true;
Letter::forget_today();
$lines = Letter::today_filter( array( array( 'key' => 'stickers', 'text' => 'Stickers to reorder: Night Glow (40 left)' ) ) );
check( 'the filter keeps the other lines and adds this one: only the open flagged order (#1240 is completed, #1250 not flagged)', 2 === count( $lines ) && 'Stickers to mail by letter: #1234 (2)' === $lines[1]['text'] );
check( '... one order links straight to it', 'https://example.test/wp-admin/admin.php?page=wc-orders&action=edit&id=1234' === $lines[1]['url'] );
check( '... the query asks open statuses only (not completed, cancelled, refunded, failed, pending) and the flag', array( 'processing', 'on-hold', 'shipped' ) === array_values( $GLOBALS['fh_query']['status'] ) && '_fishotel_sticker_mail_letter' === $GLOBALS['fh_query']['meta_key'] );
check( 'asked twice in one request: worked out once, never doubled', 2 === count( Letter::today_filter( $lines ) ) );
$GLOBALS['fh_can'] = false;
Letter::forget_today();
check( 'a user who may not run the shop: nothing added', array() === Letter::today_filter( array() ) );

echo "\n" . ( $failures ? "$failures of $checks checks FAILED\n" : "All $checks checks passed\n" );
exit( $failures ? 1 : 0 );
