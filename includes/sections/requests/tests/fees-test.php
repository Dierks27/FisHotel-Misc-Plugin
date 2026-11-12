<?php
/**
 * The fee through the store's wallet deposit: whether a fee is possible
 * at all (and the words when it is not), the signed pay link (a changed
 * amount is refused; a login is asked for; another account is refused),
 * the cart line (priced at the signed amount — that line only —, always
 * a quantity of 1, never discounted, removed when it is not a payable
 * invoice), the tag on the order line and the order, and the paid order
 * (recorded once; a short payment is not; whether the wallet was credited
 * is logged) — against small stand-ins for WooCommerce's cart, product
 * and order and for Batch Manager.
 *
 * Run from the plugin root:  php includes/sections/requests/tests/fees-test.php
 */

require_once __DIR__ . '/wp-stubs.php';
require_once FISHOTEL_MISC_PATH . 'includes/sections/requests/class-requests.php';

use FisHotel\Misc\Sections\Requests\Fees;
use FisHotel\Misc\Sections\Requests\Requests;
use FisHotel\Misc\Sections\Requests\Tokens;
use FisHotel\Misc\Sections\Requests\Urls;

/* ---- stand-ins --------------------------------------------------- */

class Fh_Fake_Product {
	public $id;
	public $name;
	public $price = 1.0;
	public $status;

	public function __construct( $id, $name = 'Test Wallet Refill', $status = 'publish' ) {
		$this->id     = $id;
		$this->name   = $name;
		$this->status = $status;
	}
	public function get_name() {
		return $this->name;
	}
	public function get_status() {
		return $this->status;
	}
	public function set_price( $price ) {
		$this->price = (float) $price;
	}
}

class Fh_Fake_Cart {
	public $lines = array();

	public function get_cart() {
		return $this->lines;
	}
	public function remove_cart_item( $key ) {
		unset( $this->lines[ $key ] );
		return true;
	}
	public function set_quantity( $key, $quantity, $refresh = true ) {
		$this->lines[ $key ]['quantity'] = (int) $quantity;
		return true;
	}
	public function add_to_cart( $product_id, $quantity = 1, $variation_id = 0, $variation = array(), $cart_item_data = array() ) {
		$key                 = md5( $product_id . wp_json_encode( $cart_item_data ) );
		$this->lines[ $key ] = array_merge(
			$cart_item_data,
			array(
				'product_id' => (int) $product_id,
				'quantity'   => (int) $quantity,
				'data'       => new Fh_Fake_Product( $product_id ),
			)
		);
		return $key;
	}
}

class Fh_Fake_Item {
	public $meta = array();
	public $total;
	public $product_id;

	public function __construct( $product_id, $total, array $meta = array() ) {
		$this->product_id = $product_id;
		$this->total      = $total;
		$this->meta       = $meta;
	}
	public function get_meta( $key, $single = true ) {
		return isset( $this->meta[ $key ] ) ? $this->meta[ $key ] : '';
	}
	public function add_meta_data( $key, $value, $unique = false ) {
		$this->meta[ $key ] = $value;
	}
	public function get_total() {
		return $this->total;
	}
	public function get_product_id() {
		return $this->product_id;
	}
	public function get_variation_id() {
		return 0;
	}
}

class Fh_Fake_Order {
	public $id;
	public $user_id;
	public $email;
	public $items;
	public $meta = array();

	public function __construct( $id, $user_id, $email, array $items ) {
		$this->id      = $id;
		$this->user_id = $user_id;
		$this->email   = $email;
		$this->items   = $items;
	}
	public function get_id() {
		return $this->id;
	}
	public function get_user_id() {
		return $this->user_id;
	}
	public function get_billing_email() {
		return $this->email;
	}
	public function get_items() {
		return $this->items;
	}
	public function get_meta( $key, $single = true ) {
		return isset( $this->meta[ $key ] ) ? $this->meta[ $key ] : '';
	}
	public function update_meta_data( $key, $value ) {
		$this->meta[ $key ] = $value;
	}
	public function get_date_created() {
		return new DateTimeImmutable( '@' . $GLOBALS['fh_clock'] );
	}
}

$GLOBALS['fh_products'] = array();
$GLOBALS['fh_orders']   = array();
$GLOBALS['fh_notices']  = array();

$service = fh_service();
$store   = $service->store;
Requests::use_service( $service );
$GLOBALS['fh_users'] = array(
	7 => array( 'login' => 'pat', 'email' => 'pat@example.test', 'first' => 'Pat', 'last' => 'Example' ),
	8 => array( 'login' => 'sam', 'email' => 'sam@example.test' ),
);

/* ================================================================== */
echo "Is a fee possible?\n";
/* ================================================================== */

$status = Fees::status( $service->settings, true );
check( 'without WooCommerce: no', ! $status['available'] );
check_contains( 'and it says so', 'WooCommerce is not active', $status['reason'] );

// Declared inside a condition, so WooCommerce "arrives" only here.
if ( ! function_exists( 'WC' ) ) {
	function WC() {
		return (object) array( 'cart' => $GLOBALS['fh_cart'] ?? null );
	}
	function wc_get_product( $id ) {
		return isset( $GLOBALS['fh_products'][ (int) $id ] ) ? $GLOBALS['fh_products'][ (int) $id ] : false;
	}
	function wc_get_order( $id ) {
		return isset( $GLOBALS['fh_orders'][ (int) $id ] ) ? $GLOBALS['fh_orders'][ (int) $id ] : false;
	}
	function wc_add_notice( $message, $type = 'success' ) {
		$GLOBALS['fh_notices'][] = array( $message, $type );
	}
	function wc_has_notice( $message, $type = 'success' ) {
		return in_array( array( $message, $type ), $GLOBALS['fh_notices'], true );
	}
}

$status = Fees::status( $service->settings, true );
check( 'with WooCommerce but without Batch Manager: no', ! $status['available'] );
check_contains( 'and it names Batch Manager and its wallet', 'Batch Manager is not active', $status['reason'] );

/**
 * A stand-in for Batch Manager: its wallet credit, and the private getter
 * of its deposit product with a fallback of its own.
 */
if ( ! class_exists( 'FisHotel_Batch_Manager', false ) ) {
	class FisHotel_Batch_Manager {
		public function credit_wallet_on_payment( $order_id ) {
		}
		private function get_deposit_product_id() {
			return (int) get_option( 'fishotel_deposit_product_id', 4242 );
		}
	}
}

$status = Fees::status( $service->settings, true );
check( 'Batch Manager is there but no deposit product is set anywhere: no', ! $status['available'] );
check_contains( 'and it says where to set one', 'no wallet-deposit product is set', $status['reason'] );

$GLOBALS['fishotel_instance'] = new FisHotel_Batch_Manager();
$status                       = Fees::status( $service->settings, true );
check_same( 'Batch Manager\'s own fallback product is asked from its own code', 4242, $status['product_id'] );
check_contains( 'that product does not exist: no, and it says which', '#4242 does not exist', $status['reason'] );

update_option( 'fishotel_deposit_product_id', 9001 );
$status = Fees::status( $service->settings, true );
check_same( 'Batch Manager\'s saved setting wins over its fallback', 9001, $status['product_id'] );
$GLOBALS['fh_products'][9001] = new Fh_Fake_Product( 9001, 'Test Wallet Refill', 'trash' );
check_contains( 'a product in the trash: no', 'in the trash', Fees::status( $service->settings, true )['reason'] );
$GLOBALS['fh_products'][9001] = new Fh_Fake_Product( 9001 );
$status                       = Fees::status( $service->settings, true );
check( 'the product exists: fees are available', $status['available'] && '' === $status['reason'] );
check_same( 'the screen can name the product', 'Test Wallet Refill', $status['product_title'] );

$GLOBALS['fh_products'][9002] = new Fh_Fake_Product( 9002, 'Other Test Product' );
check_same( 'a product typed in Requests → Settings wins over Batch Manager\'s', 9002, Fees::status( array_merge( $service->settings, array( 'deposit_product' => 9002 ) ), true )['product_id'] );
add_filter( 'fishotel_requests_deposit_product_id', static function ( $id ) { return 9001; } );
check_same( 'and a filter has the last word', 9001, Fees::product_id( array( 'deposit_product' => 9002 ) ) );
$GLOBALS['fh_filters'] = array();
$fees                  = Fees::status( $service->settings, true );

/* ================================================================== */
echo "\nThe pay link\n";
/* ================================================================== */

$link = $store->link( $store->insert_link( array( 'token' => Tokens::link(), 'label' => 'Test batch' ) ) );
$ids  = $service->submit( array( 'name' => 'Noah Newcomer', 'email' => 'noah@example.test' ), array( array( 'fish' => 'Blue Test Wrasse', 'qty' => 2 ), array( 'fish' => 'Red Test Goby' ) ), array( 'source' => 'link', 'link' => $link ) )['ids'];
$service->approve( $ids, '12.50', fh_staff() );
$carrier = $ids[0];
$mails   = fh_take_mails();
$token   = Tokens::pay( $carrier, 12.5 );
check_contains( 'the invoice e-mail carries the pay link', '?' . Urls::PAY_VAR . '=' . $token, $mails[0]['alt'] );

$d = Fees::decide( $token, 0, $service, $fees );
check_same( 'not logged in: asked to log in first (the wallet lives on an account)', 'login', $d['do'] );
check_contains( 'and told why', 'wallet', $d['message'] );

$d = Fees::decide( $token, 8, $service, $fees );
check_same( 'logged in: the fee goes to the cart', array( 'cart', $carrier, 12.5, 9001 ), array( $d['do'], $d['carrier'], $d['amount'], $d['product_id'] ) );

$forged = $carrier . '-100-' . explode( '-', $token )[2];
$d      = Fees::decide( $forged, 8, $service, $fees );
check_same( 'a link whose AMOUNT was changed is refused', 'message', $d['do'] );
check_contains( 'as not valid', 'not valid', $d['message'] );
check_same( 'a link for another request with this signature is refused', 'message', Fees::decide( ( $carrier + 1 ) . '-1250-' . explode( '-', $token )[2], 8, $service, $fees )['do'] );
check_same( 'junk is refused', 'message', Fees::decide( 'x', 8, $service, $fees )['do'] );
check_same( 'the genuine link of a request that carries no invoice is refused', 'message', Fees::decide( Tokens::pay( $ids[1], 12.5 ), 8, $service, $fees )['do'] );

$d = Fees::decide( $token, 8, $service, array( 'available' => false, 'reason' => 'x', 'product_id' => 0 ) );
check_same( 'while fees are unavailable the link says so and changes nothing', 'message', $d['do'] );
check_contains( 'kindly', 'your request is safe', $d['message'] );

$store->update( $carrier, array( 'user_id' => 7 ) );
$d = Fees::decide( $token, 8, $service, $fees );
check_same( 'a request that belongs to a site customer cannot be paid from ANOTHER account', 'message', $d['do'] );
check_contains( 'the wrong wallet would be credited — it says so', 'another account', $d['message'] );
check_same( 'its own customer can pay', 'cart', Fees::decide( $token, 7, $service, $fees )['do'] );
$store->update( $carrier, array( 'user_id' => 0 ) );

$service->set_fee( $carrier, '15', fh_staff() );
fh_take_mails();
$d = Fees::decide( $token, 8, $service, $fees );
check_same( 'after the fee was changed the OLD link is refused', 'message', $d['do'] );
check_contains( 'and points at the latest e-mail', 'latest e-mail', $d['message'] );
$token = Tokens::pay( $carrier, 15.0 );
check_same( 'the new link works', array( 'cart', 15.0 ), array( Fees::decide( $token, 8, $service, $fees )['do'], Fees::decide( $token, 8, $service, $fees )['amount'] ) );

/* ================================================================== */
echo "\nThe cart line\n";
/* ================================================================== */

$data = Fees::cart_item_data( $carrier, 15.0 );
check( 'the line carries Batch Manager\'s two keys, so its pricing and its wallet credit apply', true === $data['is_deposit'] && 15.0 === $data['deposit_amount'] );
check_same( 'and this section\'s own: the request, the amount, their signature', array( $carrier, 15.0, Tokens::pay_signature( $carrier, 15.0 ) ), array_values( $data[ Fees::CART_KEY ] ) );

$cart               = new Fh_Fake_Cart();
$GLOBALS['fh_cart'] = $cart;
$fish_key           = $cart->add_to_cart( 501, 2, 0, array(), array() );
$refill_key         = $cart->add_to_cart( 9001, 1, 0, array(), array( 'is_deposit' => true, 'deposit_amount' => 40.0 ) );
$cart->lines[ $fish_key ]['data']->price   = 59.0;
$cart->lines[ $refill_key ]['data']->price = 40.0;

check( 'the fee is put in the cart', Fees::add_to_cart( $cart, $carrier, 15.0, 9001 ) && 3 === count( $cart->lines ) );
check( 'clicking the link again does not add it twice', Fees::add_to_cart( $cart, $carrier, 15.0, 9001 ) && 3 === count( $cart->lines ) );
$fee_key = array_keys( $cart->lines )[2];

$hooks = new Fees();
$cart->lines[ $fee_key ]['quantity'] = 3;
$hooks->cart_lines( $cart );
check_same( 'the fee line is priced at the signed amount', 15.0, $cart->lines[ $fee_key ]['data']->price );
check_same( 'THAT LINE ONLY: the fish and a plain wallet refill keep their prices', array( 59.0, 40.0 ), array( $cart->lines[ $fish_key ]['data']->price, $cart->lines[ $refill_key ]['data']->price ) );
check_same( 'its quantity is always 1', 1, $cart->lines[ $fee_key ]['quantity'] );
check_same( 'the fish keep their quantity', 2, $cart->lines[ $fish_key ]['quantity'] );
check_same( 'the cart page shows a plain 1 for it', '1', $hooks->fixed_quantity( '<input type="number">', $fee_key, $cart->lines[ $fee_key ] ) );
check_same( 'and the usual field for anything else', '<input type="number">', $hooks->fixed_quantity( '<input type="number">', $fish_key, $cart->lines[ $fish_key ] ) );

check_same( 'no coupon takes anything off the fee line', 0, $hooks->no_discount( 3.0, 15.0, $cart->lines[ $fee_key ], false, null ) );
check_same( 'a coupon still works on the fish', 3.0, $hooks->no_discount( 3.0, 59.0, $cart->lines[ $fish_key ], false, null ) );

$shown = $hooks->item_data( array(), $cart->lines[ $fee_key ] );
check_contains( 'the cart says what the fee is for', '2 × Blue Test Wrasse, 1 × Red Test Goby', $shown[0]['value'] );
check_contains( 'and where it goes', 'wallet', $shown[1]['value'] );
check_same( 'nothing is added to another line', array(), $hooks->item_data( array(), $cart->lines[ $fish_key ] ) );

// A tampered line: another amount under the old signature.
$tampered                             = $cart->lines[ $fee_key ];
$tampered[ Fees::CART_KEY ]['amount'] = 1.0;
$tampered['deposit_amount']           = 1.0;
$cart->lines['tampered']              = $tampered;
$hooks->cart_lines( $cart );
check( 'a fee line whose amount does not match its signature is removed', ! isset( $cart->lines['tampered'] ) && isset( $cart->lines[ $fee_key ] ) );
check_contains( 'and the customer is told', 'removed from your cart', $GLOBALS['fh_notices'][0][0] );

$service->cancel( $ids[0], fh_staff() );
$service->cancel( $ids[1], fh_staff() );
Fees::forget();
Fees::status( $service->settings, true );
$hooks->cart_lines( $cart );
check( 'once nothing is left to pay for, the fee leaves the cart', ! isset( $cart->lines[ $fee_key ] ) && 2 === count( $cart->lines ) );
check_same( 'and the link says so', 'message', Fees::decide( $token, 8, $service, $fees )['do'] );

echo "\nThe checkout\n";
$errors                     = new WP_Error();
$cart->lines                = array();
$GLOBALS['fh_current_user'] = 0;
$hooks->checkout_validation( array(), $errors );
check_same( 'a cart without a fee line is nobody\'s business', '', $errors->get_error_code() );

$ids2 = $service->submit( array( 'name' => 'Noah Newcomer', 'email' => 'noah@example.test' ), array( array( 'fish' => 'Green Test Tang' ) ), array( 'source' => 'link', 'link' => $link ) )['ids'];
$service->approve( $ids2, '12', fh_staff() );
fh_take_mails();
$carrier = $ids2[0];
Fees::forget();
Fees::status( $service->settings, true );
Fees::add_to_cart( $cart, $carrier, 12.0, 9001 );
$fee_key = array_keys( $cart->lines )[0];

$hooks->checkout_validation( array(), $errors );
check_same( 'a guest checkout with a fee line is refused: no account, no wallet to credit', 'fh_request_fee', $errors->get_error_code() );
$errors = new WP_Error();
$hooks->checkout_validation( array( 'createaccount' => 1 ), $errors );
check_same( 'creating an account at the checkout is fine', '', $errors->get_error_code() );
$GLOBALS['fh_current_user'] = 8;
$errors                     = new WP_Error();
$hooks->checkout_validation( array(), $errors );
check_same( 'logged in is fine', '', $errors->get_error_code() );

$item  = new Fh_Fake_Item( 9001, 12.0 );
$order = new Fh_Fake_Order( 700, 8, 'sam@example.test', array( $item ) );
$hooks->order_line_item( $item, $fee_key, $cart->lines[ $fee_key ], $order );
check_same( 'the order line is tagged with the request and the fee', array( $carrier, '12.00' ), array( $item->meta[ Fees::ITEM_META ], $item->meta[ Fees::ITEM_AMOUNT_META ] ) );
check_same( 'and the order too', (string) $carrier, $order->meta[ Fees::ORDER_META ] );
$plain = new Fh_Fake_Item( 501, 59.0 );
$hooks->order_line_item( $plain, 'x', array( 'product_id' => 501 ), $order );
check_same( 'another line is not tagged', array(), $plain->meta );
$forged = new Fh_Fake_Item( 9001, 1.0 );
$hooks->order_line_item( $forged, 'x', array( Fees::CART_KEY => array( 'id' => $carrier, 'amount' => 1.0, 'sig' => Tokens::pay_signature( $carrier, 12.0 ) ) ), $order );
check_same( 'a line with a forged amount is not tagged', array(), $forged->meta );

/* ================================================================== */
echo "\nThe paid order\n";
/* ================================================================== */

$GLOBALS['fh_orders'][700] = $order;
$GLOBALS['fh_fired']       = array();

// Batch Manager ran first (priority 10) and credited the wallet.
$order->meta[ Fees::BATCH_CREDITED ] = 1;
$hooks->order_paid( 700 );
$r = $store->get( $carrier );
check_same( 'the request is on the list', 'waiting', $r['state'] );
check( 'the fee is marked paid', null !== $r['fee_paid_at'] );
check_same( 'with the order that paid it', 700, $r['fee_order_id'] );
check_same( 'the request joins the account that paid (whose wallet Batch Manager credited)', 8, $r['user_id'] );
check_contains( 'the log says the wallet was credited by Batch Manager', 'credited by Batch Manager', implode( ' | ', $store->notes( $carrier ) ) );
check_same( 'nothing is reported to the Health log', 0, fh_fired( 'fishotel_log' ) );
check_same( 'no e-mail', array(), fh_take_mails() );

$lines = count( $store->log );
$hooks->order_paid( 700 );
Fees::forget();
$hooks->order_paid( 700 );
check_same( 'the same order reported again (three hooks, a later status change) changes nothing', $lines, count( $store->log ) );

echo "\nA wallet that was not credited\n";
$ids3 = $service->submit( array( 'name' => 'Noah Newcomer', 'email' => 'noah@example.test' ), array( array( 'fish' => 'Yellow Test Blenny' ) ), array( 'source' => 'link', 'link' => $link ) )['ids'];
$service->approve( $ids3, '12', fh_staff() );
fh_take_mails();
$item2                     = new Fh_Fake_Item( 9001, 12.0, array( Fees::ITEM_META => $ids3[0] ) );
$GLOBALS['fh_orders'][701] = new Fh_Fake_Order( 701, 0, 'noah@example.test', array( $item2 ) );
$GLOBALS['fh_fired']       = array();
Fees::forget();
$hooks->order_paid( 701 );
check_same( 'the payment still counts: the request is on the list', 'waiting', $store->get( $ids3[0] )['state'] );
check_contains( 'the log says plainly that no wallet was credited', 'did NOT credit a wallet', implode( ' | ', $store->notes( $ids3[0] ) ) );
check_same( 'and the Health log hears about it', 1, fh_fired( 'fishotel_log' ) );

echo "\nA short payment\n";
$ids4 = $service->submit( array( 'name' => 'Noah Newcomer', 'email' => 'noah@example.test' ), array( array( 'fish' => 'Orange Test Anthias' ) ), array( 'source' => 'link', 'link' => $link ) )['ids'];
$service->approve( $ids4, '12', fh_staff() );
fh_take_mails();
$short  = new Fh_Fake_Order( 702, 8, 'sam@example.test', array( new Fh_Fake_Item( 9001, 6.0, array( Fees::ITEM_META => $ids4[0] ) ) ) );
$result = Fees::record( $short, $service );
check_same( 'an order line that paid less than the fee is NOT recorded as paid', array( array(), array( $ids4[0] ), 'awaiting_payment' ), array( $result['paid'], $result['short'], $store->get( $ids4[0] )['state'] ) );
check_contains( 'and the history says so', 'NOT recorded as paid', implode( ' | ', $store->notes( $ids4[0] ) ) );

echo "\nAn order that has nothing to do with requests\n";
$before = array( count( $store->log ), count( $store->rows ) );
$result = Fees::record( new Fh_Fake_Order( 703, 8, 'sam@example.test', array( new Fh_Fake_Item( 501, 59.0 ) ) ), $service );
check_same( 'nothing is paid, nothing is filled, nothing is written', array( array(), array(), array(), $before ), array( $result['paid'], $result['short'], $result['filled'], array( count( $store->log ), count( $store->rows ) ) ) );

echo "\nBought after the offer, through the order\n";
$store->update( $carrier, array( 'state' => 'offered', 'product_id' => 501, 'offered_at' => gmdate( 'Y-m-d H:i:s', $GLOBALS['fh_clock'] - 3600 ) ) );
$result = Fees::record( new Fh_Fake_Order( 704, 8, 'sam@example.test', array( new Fh_Fake_Item( 501, 59.0 ) ) ), $service );
check_same( 'the customer bought the product they were offered: filled', array( array( $carrier ), 'filled' ), array( $result['filled'], $store->get( $carrier )['state'] ) );

Requests::use_service( null );
fh_finish();
