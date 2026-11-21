<?php
/**
 * WooCommerce must not get in the way of a giveaway line, and must not be
 * fooled by one.
 *
 * Part 1 loads the real giveaway-line classes (class-pile-products.php)
 * on top of two tiny stand-ins for WooCommerce's product classes, and
 * plays WooCommerce's own cart checks over a cart — the three questions
 * WC_Cart::get_cart_item_quantities(), ::check_cart_item_stock() and
 * wc_reduce_stock_levels() ask a line's product, written out here as
 * WooCommerce 11.2 asks them. The stand-ins are not WooCommerce: this
 * shows that the answers the giveaway-line object gives lead where they
 * should, not that WooCommerce asks nothing else. That is proved on a
 * rehearsal site.
 *
 * Part 2 reads the section's source for the promises that are about what
 * is NOT there: nothing is ever saved on a product, the stock answers
 * given during our own add are taken away again, and the section still
 * does nothing while switched off.
 *
 * Run from the plugin root:
 *   php includes/sections/sticker-giveaway/tests/woocommerce-seams-test.php
 *
 * @package FisHotel\Misc\Sections\Sticker_Giveaway
 */

// phpcs:disable Generic.Files.OneObjectStructurePerFile, Squiz.Commenting

/**
 * Stand-in for WC_Product: only what the stock checks read.
 */
class WC_Product {
	public $id;
	public $stock;
	public $status;
	public $manage;
	public $parent_manages = false;
	public $parent_id      = 0;

	public function __construct( $id = 0 ) {
		$source = is_object( $id ) ? $id : ( $GLOBALS['fh_products'][ (int) $id ] ?? null );
		if ( ! $source ) {
			throw new Exception( 'Invalid product.' );
		}
		foreach ( get_object_vars( $source ) as $key => $value ) {
			$this->$key = $value;
		}
	}

	public function get_id() {
		return $this->id;
	}
	public function get_parent_id() {
		return $this->parent_id;
	}
	public function get_stock_quantity() {
		return $this->stock;
	}
	public function is_in_stock() {
		return 'outofstock' !== $this->status;
	}
	public function managing_stock() {
		return $this->manage || $this->parent_manages;
	}
	public function backorders_allowed() {
		return false;
	}
	public function has_enough_stock( $quantity ) {
		return ! $this->managing_stock() || $this->backorders_allowed() || $this->get_stock_quantity() >= $quantity;
	}
	public function get_stock_managed_by_id() {
		return $this->parent_manages ? $this->parent_id : $this->id;
	}
	public function is_type( $type ) {
		return $type === $this->type();
	}
	public function type() {
		return 'simple';
	}
}
class WC_Product_Simple extends WC_Product {}
class WC_Product_Variation extends WC_Product {
	public function type() {
		return 'variation';
	}
}

function fh_seed( $class, $id, $stock, $status = 'instock', $manage = true, $parent_id = 0, $parent_manages = false ) {
	$seed                 = new stdClass();
	$seed->id             = $id;
	$seed->stock          = $stock;
	$seed->status         = $status;
	$seed->manage         = $manage;
	$seed->parent_id      = $parent_id;
	$seed->parent_manages = $parent_manages;

	$GLOBALS['fh_products'][ $id ] = $seed;

	return new $class( $id );
}
function wc_get_product( $id ) {
	if ( ! isset( $GLOBALS['fh_products'][ (int) $id ] ) ) {
		return false;
	}
	$seed  = $GLOBALS['fh_products'][ (int) $id ];
	$class = $seed->parent_id ? 'WC_Product_Variation' : 'WC_Product_Simple';
	return new $class( (int) $id );
}

require_once __DIR__ . '/wp-stubs.php';
require_once FISHOTEL_MISC_PATH . 'includes/sections/sticker-giveaway/class-store.php';
require_once FISHOTEL_MISC_PATH . 'includes/sections/sticker-giveaway/class-designs.php';

use FisHotel\Misc\Sections\Sticker_Giveaway\Designs;
use FisHotel\Misc\Sections\Sticker_Giveaway\Piles;

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
 * WC_Cart::get_cart_item_quantities(), as WooCommerce 11.2 has it.
 */
function wc_quantities( array $cart ) {
	$quantities = array();
	foreach ( $cart as $values ) {
		$managed_by_id                = $values['data']->get_stock_managed_by_id();
		$quantities[ $managed_by_id ] = $values['quantity'] + ( $quantities[ $managed_by_id ] ?? 0 );
	}
	return $quantities;
}

/**
 * WC_Cart::check_cart_item_stock(), as WooCommerce 11.2 has it (held
 * stock of other customers' pending orders passed in per stock id).
 *
 * @return string '' when the cart passes, else which line refused it.
 */
function wc_check_stock( array $cart, array $held = array() ) {
	$in_cart = wc_quantities( $cart );
	foreach ( $cart as $key => $values ) {
		$product = $values['data'];
		if ( ! $product->is_in_stock() ) {
			return "$key: not in stock";
		}
		if ( ! $product->managing_stock() || $product->backorders_allowed() ) {
			continue;
		}
		$held_stock = $held[ $product->get_stock_managed_by_id() ] ?? 0;
		$required   = $in_cart[ $product->get_stock_managed_by_id() ];
		if ( $product->get_stock_quantity() < $held_stock + $required ) {
			return "$key: not enough";
		}
	}
	return '';
}

/**
 * wc_reduce_stock_levels() and ReserveStock, as far as they decide which
 * lines touch stock: the stock ids they would reduce / hold, with
 * quantities.
 */
function wc_would_reduce( array $cart ) {
	$out = array();
	foreach ( $cart as $values ) {
		$product = $values['data'];
		if ( ! $product->managing_stock() ) {
			continue;
		}
		$id         = $product->get_stock_managed_by_id();
		$out[ $id ] = ( $out[ $id ] ?? 0 ) + $values['quantity'];
	}
	return $out;
}

function line( $product, $qty, $sticker = '' ) {
	$line = array(
		'data'     => $product,
		'quantity' => $qty,
	);
	if ( '' !== $sticker ) {
		$line['fh_sticker'] = $sticker;
	}
	return $line;
}

echo "1. The giveaway-line object\n";
// Shape A — a variation that manages its own stock: 2 in the shop.
$shop_blue = fh_seed( 'WC_Product_Variation', 302, 2, 'instock', true, 300 );
$pile_blue = Designs::pile_product( $shop_blue );

check( 'it is built for a variation, and is still a variation of the same id', $pile_blue instanceof WC_Product_Variation && 302 === $pile_blue->get_id() && $pile_blue->is_type( 'variation' ) );
check( 'it is a new object: the product WooCommerce handed out is not changed', $pile_blue !== $shop_blue && true === $shop_blue->managing_stock() && 302 === $shop_blue->get_stock_managed_by_id() );
check( 'in stock, not managing stock, nobody\'s stock', true === $pile_blue->is_in_stock() && false === $pile_blue->managing_stock() && Piles::STOCK_BUCKET === $pile_blue->get_stock_managed_by_id() );
check( 'never short and never on backorder', true === $pile_blue->has_enough_stock( 999 ) && false === $pile_blue->is_on_backorder( 5 ) );
check( 'the shop\'s number can still be read off it', 2 === $pile_blue->get_stock_quantity() );
check( 'Designs knows one from the other', Designs::is_pile_product( $pile_blue ) && ! Designs::is_pile_product( $shop_blue ) && ! Designs::is_pile_product( null ) );
check( 'asking again for one that already is one hands back the same object', $pile_blue === Designs::pile_product( $pile_blue ) );

$shop_round = fh_seed( 'WC_Product_Simple', 77, 5 );
$pile_round = Designs::pile_product( $shop_round );
check( 'a simple product gets the simple kind', $pile_round instanceof WC_Product_Simple && ! $pile_round instanceof WC_Product_Variation && false === $pile_round->managing_stock() );
check( 'a product that cannot be read gives nothing (the line keeps WooCommerce\'s object)', null === Designs::pile_product( 424242 ) );

echo "2. The shop pile at 0 / out of stock does not refuse a giveaway line\n";
$shop_out = fh_seed( 'WC_Product_Variation', 303, 0, 'outofstock', true, 300 );
$fish     = fh_seed( 'WC_Product_Simple', 11, 4 );
$cart     = array(
	'fish' => line( $fish, 1 ),
	'free' => line( Designs::pile_product( $shop_out ), 1, 'free' ),
	'more' => line( Designs::pile_product( $shop_out ), 3, 'extra' ),
);
check( 'cart page, checkout and Place Order: the cart passes', '' === wc_check_stock( $cart ) );
check( '... also with every sticker of the shop pile held by other customers\' unpaid orders', '' === wc_check_stock( $cart, array( 303 => 50 ) ) );
check( 'with WooCommerce\'s own object on the line it would have been refused (the object is what makes the difference)', 'free: not in stock' === wc_check_stock( array( 'fish' => line( $fish, 1 ), 'free' => line( $shop_out, 1, 'free' ) ) ) );

echo "3. Paying the order never reduces the shop pile for a giveaway line\n";
check( 'only the fish would be reduced / held', array( 11 => 1 ) === wc_would_reduce( $cart ) );

echo "4. A normal purchase of the same variation in the same cart\n";
$normal_2 = array(
	'buy'  => line( $shop_blue, 2 ),
	'free' => line( $pile_blue, 1, 'free' ),
	'more' => line( Designs::pile_product( $shop_blue ), 3, 'extra' ),
);
check( 'WooCommerce sums the normal line alone under the variation\'s stock', 2 === wc_quantities( $normal_2 )[302] && 4 === wc_quantities( $normal_2 )[ Piles::STOCK_BUCKET ] );
check( '2 in the shop, 2 bought the normal way (+1 free, +3 add-ons): passes, as it would without the giveaway lines', '' === wc_check_stock( $normal_2 ) && '' === wc_check_stock( array( 'buy' => line( $shop_blue, 2 ) ) ) );

$normal_3        = $normal_2;
$normal_3['buy'] = line( $shop_blue, 3 );
check( '3 bought the normal way with 2 in the shop: refused, as it would be without the giveaway lines', 'buy: not enough' === wc_check_stock( $normal_3 ) && 'buy: not enough' === wc_check_stock( array( 'buy' => line( $shop_blue, 3 ) ) ) );
check( 'the giveaway lines do not make the normal line pass wrongly: 1 held elsewhere, 2 wanted, 2 in the shop is refused either way', 'buy: not enough' === wc_check_stock( $normal_2, array( 302 => 1 ) ) && 'buy: not enough' === wc_check_stock( array( 'buy' => line( $shop_blue, 2 ) ), array( 302 => 1 ) ) );
check( 'paying reduces the shop pile by the normal 2 only', array( 302 => 2 ) === wc_would_reduce( $normal_2 ) );

// Without the giveaway-line object (what would happen if the lines carried WooCommerce's own object): the shop pile would be asked for 6.
$fooled = array(
	'buy'  => line( $shop_blue, 2 ),
	'free' => line( $shop_blue, 1, 'free' ),
	'more' => line( $shop_blue, 3, 'extra' ),
);
check( '(for contrast) with WooCommerce\'s own object on the giveaway lines the normal 2 would be refused and 6 reduced', 'buy: not enough' === wc_check_stock( $fooled ) && array( 302 => 6 ) === wc_would_reduce( $fooled ) );

echo "5. A variation that takes its stock from its product\n";
$from_parent = fh_seed( 'WC_Product_Variation', 403, 1, 'instock', false, 400, true );
check( 'WooCommerce\'s own object: managed by the product (#400)', 400 === $from_parent->get_stock_managed_by_id() && true === $from_parent->managing_stock() );
$pile_parent = Designs::pile_product( $from_parent );
check( 'the giveaway-line object is still nobody\'s stock and not managing', Piles::STOCK_BUCKET === $pile_parent->get_stock_managed_by_id() && false === $pile_parent->managing_stock() );
$cart = array(
	'buy'  => line( $from_parent, 1 ),
	'free' => line( $pile_parent, 1, 'free' ),
);
check( '1 in the product\'s stock, 1 bought + 1 free: passes; the product\'s stock is reduced by 1', '' === wc_check_stock( $cart ) && array( 400 => 1 ) === wc_would_reduce( $cart ) );

echo "6. What the giveaway-line classes change, and nothing else\n";
$source = file_get_contents( FISHOTEL_MISC_PATH . 'includes/sections/sticker-giveaway/class-pile-products.php' );
preg_match_all( '/public function (\w+)\(/', $source, $m );
sort( $m[1] );
check( 'six methods: the three answers, two that follow from them, and the mark', array( 'get_stock_managed_by_id', 'has_enough_stock', 'is_giveaway_pile_line', 'is_in_stock', 'is_on_backorder', 'managing_stock' ) === $m[1], implode( ', ', $m[1] ) );
check( 'no setter, no save', ! preg_match( '/->set_|->save\(|update_post_meta|wc_update_product_stock/', $source ) );

echo "7. Nothing is ever saved on a product\n";
$dir     = FISHOTEL_MISC_PATH . 'includes/sections/sticker-giveaway/';
$sources = array();
foreach ( array_merge( glob( $dir . '*.php' ), glob( $dir . 'views/*.php' ) ) as $file ) {
	$sources[ basename( $file ) ] = file_get_contents( $file );
}
$writers = array(
	'a stock status or quantity set'  => '/->set_stock_status\(|->set_stock_quantity\(|->set_manage_stock\(|->set_backorders\(/',
	'WooCommerce\'s stock functions'  => '/\bwc_update_product_stock\(|\bwc_update_product_stock_status\(|\bwc_reduce_stock_levels\(|\bwc_increase_stock_levels\(/',
	'post meta written'               => '/\b(update|add|delete)_post_meta\(/',
	'a post updated'                  => '/\bwp_update_post\(|\bwp_insert_post\(/',
);
foreach ( $writers as $what => $pattern ) {
	$hits = array();
	foreach ( $sources as $name => $text ) {
		if ( preg_match( $pattern, $text ) ) {
			$hits[] = $name;
		}
	}
	check( "no $what anywhere in the section", array() === $hits, implode( ', ', $hits ) );
}
$saves = array();
foreach ( $sources as $name => $text ) {
	if ( preg_match_all( '/(\$\w+)->save\(/', $text, $mm ) ) {
		foreach ( $mm[1] as $var ) {
			$saves[] = "$name:$var";
		}
	}
}
check( 'the only objects saved are order lines, in class-orders.php', array( 'class-orders.php:$item' ) === array_values( array_unique( $saves ) ), implode( ', ', $saves ) );
check( 'set_price() is the one thing set on a line\'s product, and it is never saved', 1 === count( array_filter( $sources, static function ( $text ) { return false !== strpos( $text, '->set_price(' ); } ) ) && false !== strpos( $sources['class-cart.php'], '->set_price(' ) );

echo "8. The stock answers given during our own add are taken away again\n";
$cart_src = $sources['class-cart.php'];
check( 'two filters are added, both by begin_pile_add() only', 2 === preg_match_all( "/add_filter\( 'woocommerce_product_(is_in_stock|backorders_allowed)'/", $cart_src ) );
check( '... and the same two removed', 2 === preg_match_all( "/remove_filter\( 'woocommerce_product_(is_in_stock|backorders_allowed)'/", $cart_src ) );
preg_match( '/public function init\(\) \{(.*?)\n\t\}/s', $cart_src, $init );
check( 'neither is registered in init() (they are not on while a customer shops)', isset( $init[1] ) && false !== strpos( $init[1], 'woocommerce_before_calculate_totals' ) && false === strpos( $init[1], 'woocommerce_product_is_in_stock' ) && false === strpos( $init[1], 'woocommerce_product_backorders_allowed' ) );
check( 'both answer only for the one design being added', 2 === preg_match_all( '/self::\$adding_pile && .*=== self::\$adding_pile/', $cart_src ) );
check( 'end_pile_add() runs when the line exists and again after add_to_cart() returns', preg_match_all( '/self::end_pile_add\(\);/', $cart_src ) >= 2 );
check( 'the giveaway line is a normal variation line: product, quantity, variation id, WooCommerce fills the attributes', (bool) preg_match( '/add_to_cart\(\s*\$is_variation \? \$product->get_parent_id\(\) : \$product->get_id\(\),\s*\$quantity,\s*\$is_variation \? \$product->get_id\(\) : 0,\s*array\(\),\s*array\( Sticker_Giveaway::ITEM_KEY => \$kind \)/', $cart_src ) );
check( 'the line gets its giveaway-line object when it is made, when the cart is read back, and before WooCommerce\'s own check', false !== strpos( $cart_src, "add_filter( 'woocommerce_add_cart_item', array( \$this, 'pile_line' )" ) && false !== strpos( $cart_src, "add_filter( 'woocommerce_get_cart_item_from_session', array( \$this, 'pile_line' )" ) && false !== strpos( $cart_src, "add_action( 'woocommerce_check_cart_items', array( \$this, 'guard_pile_lines' ), 0 )" ) );

echo "9. The moments\n";
$orders_src = $sources['class-orders.php'];
check( 'the pile goes down on WooCommerce\'s own reduce and comes back on its own restore', false !== strpos( $orders_src, "add_action( 'woocommerce_reduce_order_stock', array( \$this, 'take' )" ) && false !== strpos( $orders_src, "add_action( 'woocommerce_restore_order_stock', array( \$this, 'give_back' )" ) );
check( 'a refund is listened for, and only acts with "restock" ticked', false !== strpos( $orders_src, "add_action( 'woocommerce_refund_created', array( \$this, 'refund' ), 10, 2 )" ) && false !== strpos( $orders_src, "empty( \$args['restock_items'] )" ) );
check( 'an order line asked for its product answers with the giveaway-line object only when it is stamped', false !== strpos( $orders_src, "add_filter( 'woocommerce_order_item_product', array( \$this, 'pile_product' ), 20, 2 )" ) && false !== strpos( $orders_src, '! self::pile_design( $item )' ) );
check( 'no order status is hooked directly (the pile follows WooCommerce, it does not second-guess it)', ! preg_match( "/woocommerce_order_status_|woocommerce_payment_complete/", preg_replace( '#/\*.*?\*/#s', '', $orders_src ) ) );

echo "10. Switched off, the section still does nothing but drop its lines\n";
$main = $sources['class-sticker-giveaway.php'];
preg_match( '/public static function boot_disabled\(\) \{(.*?)\n\t\}/s', $main, $off );
check( 'boot_disabled() adds one hook and loads nothing', isset( $off[1] ) && 1 === substr_count( $off[1], 'add_action(' ) && 0 === substr_count( $off[1], 'add_filter(' ) && false === strpos( $off[1], 'require' ) && false === strpos( $off[1], 'Store::' ) );
check( 'the tables are only made by boot() (switched on) or on activation', 1 === substr_count( $main, 'Store::maybe_install(' ) && 1 === substr_count( $main, 'Store::install(' ) );
check( 'the class file itself, which is loaded even while off, declares no hook at file level', ! preg_match( '/^\s*add_(action|filter)\(/m', preg_replace( '/class Sticker_Giveaway \{.*/s', '', $main ) ) );

echo "\n" . ( $failures ? "$failures of $checks checks FAILED\n" : "All $checks checks passed\n" );
exit( $failures ? 1 : 0 );
