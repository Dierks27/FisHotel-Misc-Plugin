<?php
/**
 * Checkout (Live): the choice (all / part / none), the "Gift card" fee
 * (never more than the wallet or the order, not taxable), paying when the
 * order is created, two tabs at checkout, the paid / failed / cancelled /
 * refunded / trashed hooks (each puts back exactly once), a paid order's
 * cancel setting, codes typed at checkout (guests only when allowed, a
 * wrong code counted, somebody else's card never), the theme's marker
 * and checkbox id, old orders' deduction and Recalculate.
 *
 * Run from the plugin root:  php includes/sections/gift-cards/tests/checkout-test.php
 */

require_once __DIR__ . '/wp-stubs.php';
require_once __DIR__ . '/wc-stubs.php';

use FisHotel\Misc\Sections\Gift_Cards\Checkout;
use FisHotel\Misc\Sections\Gift_Cards\Money;

function fh_fee( $order ) {
	return Checkout::fee_cents( $order );
}

$fh_checkout = new Checkout();
$fh_checkout->init(); // the hooks, once (WooCommerce fires them below)

echo "The choice\n";
check( 'nothing ticked: none', array( 'use' => false, 'amount' => null ) === Checkout::choice_from_post( array() ) );
check( 'ticked, amount left as it was: all', array( 'use' => true, 'amount' => null ) === Checkout::choice_from_post( array( 'fh_gcard_use' => '1', 'fh_gcard_amount' => '' ) ) );
check( 'ticked with $12.50: part', array( 'use' => true, 'amount' => 1250 ) === Checkout::choice_from_post( array( 'fh_gcard_use' => '1', 'fh_gcard_amount' => '12.50' ) ) );
$c = new Checkout();
$c->read_review_post( 'billing_first_name=Pat&fh_gcard_use=1&fh_gcard_amount=7' );
check( 'the checkout\'s update request is read into the session', array( 'use' => true, 'amount' => 700 ) === Checkout::choice() );

echo "\nThe fee\n";
fh_wc_reset();
$s = fh_gc_use();
$GLOBALS['fh_current_user'] = 5;
$a = fh_gc_wallet_card( $s, 5, '30.00' );
$c = new Checkout();
WC()->cart->contents_total = 50.00;
Checkout::set_choice( array( 'use' => false, 'amount' => null ) );
WC()->cart->calculate_fees();
check( 'not ticked: no fee', array() === WC()->cart->get_fees() );
Checkout::set_choice( array( 'use' => true, 'amount' => null ) );
WC()->cart->calculate_fees();
$fee = WC()->cart->get_fees()['fh-gift-card'] ?? null;
check( 'all: a "Gift card" fee of −$30.00, not taxable', $fee && 'Gift card' === $fee->name && -30.0 === (float) $fee->amount && false === $fee->taxable );
Checkout::set_choice( array( 'use' => true, 'amount' => 1250 ) );
WC()->cart->calculate_fees();
check( 'part: −$12.50', -12.5 === (float) WC()->cart->get_fees()['fh-gift-card']->amount );
WC()->cart->contents_total = 8.00;
Checkout::set_choice( array( 'use' => true, 'amount' => null ) );
WC()->cart->calculate_fees();
check( 'never more than the order: −$8.00 on an $8 order', -8.0 === (float) WC()->cart->get_fees()['fh-gift-card']->amount );
WC()->cart->contents_total = 8.00;
WC()->cart->shipping_total = 12.00;
WC()->cart->calculate_fees();
check( 'shipping can be paid with it too: −$20.00', -20.0 === (float) WC()->cart->get_fees()['fh-gift-card']->amount );
WC()->cart->shipping_total = 0;
WC()->cart->contents_total = 25.00;
WC()->cart->items = array( 'k' => array( 'data' => fh_product( 77, 25, array( '_fh_gift_card' => 'yes' ) ), 'line_total' => 25.00 ) );
WC()->cart->calculate_fees();
check( 'a gift card in the cart can be paid with the balance (as today)', -25.0 === (float) WC()->cart->get_fees()['fh-gift-card']->amount );
fh_gc_service( $s->store, array( 'wallet_pays_gift_cards' => 'no' ) );
\FisHotel\Misc\Sections\Gift_Cards\Gift_Cards::use_service( fh_gc_service( $s->store, array( 'wallet_pays_gift_cards' => 'no' ) ) );
WC()->cart->calculate_fees();
check( '…unless Settings say no: then there is nothing it may pay here', array() === WC()->cart->get_fees() );
\FisHotel\Misc\Sections\Gift_Cards\Gift_Cards::use_service( $s );
WC()->cart->items = array();

echo "\nPaying when the order is created\n";
Checkout::set_choice( array( 'use' => true, 'amount' => 2000 ) );
$o = fh_checkout( $c, 1001, 5, 50.00 );
check( 'the order is created with the −$20 fee, and $20 is taken from the card', is_object( $o ) && 2000 === fh_fee( $o ) && 1000 === (int) $s->store->card( $a )['balance_cents'] );
check( 'the order total is net of the card ($30 to pay)', 30.0 === (float) $o->total );
$c->ensure_debited( 1001 );
$o->update_status( 'processing' );
check( 'paid / processing: nothing more is taken', 1000 === (int) $s->store->card( $a )['balance_cents'] );

$ff = fh_order( 1009, array( 'status' => 'fulfillment', 'meta' => array( '_fishotel_fulfillment_sources' => array( 1001 ) ) ) );
$ff->fees[] = new Fh_Fee_Item( 'fh-gift-card', -20.0, array( Checkout::FEE_META => 'yes' ) );
$c->ensure_debited( 1009 );
check( 'the theme\'s combined-box Fulfillment order is never charged (its source orders were)', 1000 === (int) $s->store->card( $a )['balance_cents'] && 'fulfillment' === $ff->status );

echo "\nTwo tabs at checkout\n";
Checkout::set_choice( array( 'use' => true, 'amount' => null ) );
WC()->cart->contents_total = 40.00;
WC()->cart->calculate_fees();
$tab_a_fee = WC()->cart->get_fees()['fh-gift-card']->amount; // tab A was drawn with $10 to use
$s->debit_order( 1003, 5, array(), 1000, fh_gc_customer_ctx( 5, 'checkout' ) ); // tab B pays first
$order = fh_order( 1002, array( 'customer_id' => 5, 'total' => 30.0 ) );
$item  = new Fh_Fee_Item( 'fh-gift-card', $tab_a_fee );
$c->tag_fee( $item, 'fh-gift-card', (object) array( 'id' => 'fh-gift-card' ), $order );
$order->fees[] = $item;
$msg = '';
try {
	$c->debit( $order );
} catch ( \Exception $e ) {
	$msg = $e->getMessage();
}
check( 'tab A\'s Place order stops with a message, and nothing is taken', false !== strpos( $msg, 'please check your order and place it again' ) && 0 === $s->order_summary( 1002 )['outstanding'] && 0 === (int) $s->store->card( $a )['balance_cents'] );
$c->order_exception( $order );
check( '…the left-behind order says why', false !== strpos( implode( ' ', $order->notes ), 'nothing was taken from a gift card' ) );
check( '…and the next update recalculates from what is left', null === Checkout::choice()['amount'] );
$order->update_status( 'processing' );
check( 'if that order is paid anyway, it is put on hold with a note', 'on-hold' === $order->status && false !== strpos( implode( ' ', $order->notes ), 'could NOT be taken' ) );

echo "\nPutting back\n";
fh_wc_reset();
$s = fh_gc_use();
$GLOBALS['fh_current_user'] = 5;
$a = fh_gc_wallet_card( $s, 5, '50.00' );
$c = new Checkout();
Checkout::set_choice( array( 'use' => true, 'amount' => 3000 ) );
$o = fh_checkout( $c, 2001, 5, 60.00 );
$o->update_status( 'failed' );
check( 'failed: the $30 goes back', 5000 === (int) $s->store->card( $a )['balance_cents'] && false !== strpos( implode( ' ', $o->notes ), '$30.00 was put back' ) );
$o->update_status( 'failed' );
$o->update_status( 'cancelled' );
check( 'failed again, then cancelled: nothing more', 5000 === (int) $s->store->card( $a )['balance_cents'] );
$o->status = 'pending';
$o->update_status( 'processing' );
check( 'the customer pays it after all (pay-for-order): the $30 is taken again', 2000 === (int) $s->store->card( $a )['balance_cents'] );
fh_refund_items( $o, array( 91 => 1 ) );
$o->update_status( 'refunded' );
check( 'marked Refunded when only part of the items were refunded (the other money was all given back): nothing back by itself, a note says so', 2000 === (int) $s->store->card( $a )['balance_cents'] && false !== strpos( end( $o->notes ), 'was NOT put back by itself' ) );
$o->status = 'processing';
fh_refund_items( $o );
$o->update_status( 'refunded' );
check( 'refunded in full (every item): the $30 is back', 5000 === (int) $s->store->card( $a )['balance_cents'] );
$o->update_status( 'refunded' );
check( '…once', 5000 === (int) $s->store->card( $a )['balance_cents'] && fh_gc_balanced( $s ) );

Checkout::set_choice( array( 'use' => true, 'amount' => 1000 ) );
$p = fh_checkout( $c, 2002, 5, 60.00 );
$p->update_status( 'processing' );
$c->on_partial_refund( 2002, 1 );
check( 'a partial refund puts nothing back by itself, and says where to do it', 4000 === (int) $s->store->card( $a )['balance_cents'] && false !== strpos( end( $p->notes ), 'use the Gift cards box' ) );
$p->update_status( 'cancelled' );
check( 'a PAID order cancelled: put back (Settings default)', 5000 === (int) $s->store->card( $a )['balance_cents'] );
\FisHotel\Misc\Sections\Gift_Cards\Gift_Cards::use_service( fh_gc_service( $s->store, array( 'cancel_paid_restores' => 'no' ) ) );
Checkout::set_choice( array( 'use' => true, 'amount' => 1000 ) );
$q = fh_checkout( $c, 2003, 5, 60.00 );
$q->update_status( 'processing' );
$q->update_status( 'cancelled' );
check( '…not when Settings say no — with a note', 4000 === (int) $s->store->card( $a )['balance_cents'] && false !== strpos( end( $q->notes ), 'was NOT put back' ) );
\FisHotel\Misc\Sections\Gift_Cards\Gift_Cards::use_service( $s );
Checkout::set_choice( array( 'use' => true, 'amount' => 500 ) );
$t = fh_checkout( $c, 2004, 5, 60.00 );
do_action( 'wp_trash_post', 2004 );
check( 'an unpaid order trashed: put back', 4000 === (int) $s->store->card( $a )['balance_cents'] );
Checkout::set_choice( array( 'use' => true, 'amount' => 500 ) );
$u = fh_checkout( $c, 2005, 5, 60.00 );
$u->update_status( 'completed' );
do_action( 'woocommerce_trash_order', 2005 );
check( 'a paid order trashed: NOT put back (it was paid for)', 3500 === (int) $s->store->card( $a )['balance_cents'] );
check( 'the ledger adds up', fh_gc_balanced( $s ) );

echo "\nPaid after money was put back by hand\n";
fh_wc_reset();
$s = fh_gc_use();
$GLOBALS['fh_current_user'] = 5;
$a = fh_gc_wallet_card( $s, 5, '30.00' );
$c = new Checkout();
Checkout::set_choice( array( 'use' => true, 'amount' => 1000 ) );
$o = fh_checkout( $c, 2101, 5, 50.00 );
$o->update_status( 'processing' );
$s->restore_part( 2101, '4.00', 'one fish refunded', 'partkeytest0001', fh_gc_admin_ctx() );
$o->update_status( 'completed' );
check( 'a $10 card payment, $4 put back with the Gift cards box, then Completed: the card keeps the $4 ($24), nothing is taken again', 2400 === (int) $s->store->card( $a )['balance_cents'] && 600 === $s->order_summary( 2101 )['outstanding'] && 'completed' === $o->status );
$s->restore_part( 2101, '6.00', 'the rest refunded', 'partkeytest0002', fh_gc_admin_ctx() );
$o->status = 'processing';
$o->update_status( 'completed' );
check( '…all of it put back by hand, then Completed again: still nothing taken again', 3000 === (int) $s->store->card( $a )['balance_cents'] && fh_gc_balanced( $s ) );
Checkout::set_choice( array( 'use' => true, 'amount' => 500 ) );
WC()->cart->contents_total = 20.00;
WC()->cart->calculate_fees();
$blk = fh_order( 2102, array( 'customer_id' => 5, 'total' => 15.0 ) );
$item = new Fh_Fee_Item( 'fh-gift-card', -5.0 );
$c->tag_fee( $item, 'fh-gift-card', (object) array( 'id' => 'fh-gift-card' ), $blk );
$blk->fees[] = $item;
$blk->update_status( 'processing' );
check( 'an order whose card part was never taken (block checkout) is charged when it is paid', 2500 === (int) $s->store->card( $a )['balance_cents'] && 500 === $s->order_summary( 2102 )['outstanding'] );
check( 'should_take (pure): nothing yet → yes; taken → no; put back when it failed → yes; put back by hand → no', Checkout::should_take( array() ) && ! Checkout::should_take( array( array( 'card_id' => 1, 'type' => 'used', 'amount_cents' => -500, 'idempotency_key' => 'use:1:1:1' ) ) ) && Checkout::should_take( array( array( 'card_id' => 1, 'type' => 'used', 'amount_cents' => -500, 'idempotency_key' => 'use:1:1:1' ), array( 'card_id' => 1, 'type' => 'restored', 'amount_cents' => 500, 'idempotency_key' => 'restore:1:1:1' ) ) ) && ! Checkout::should_take( array( array( 'card_id' => 1, 'type' => 'used', 'amount_cents' => -500, 'idempotency_key' => 'use:1:1:1' ), array( 'card_id' => 1, 'type' => 'restored', 'amount_cents' => 500, 'idempotency_key' => 'part:1:1:abc' ) ) ) );

echo "\nRefunded: only every item brings the card part back\n";
Checkout::set_choice( array( 'use' => true, 'amount' => 2500 ) );
$r = fh_checkout( $c, 2103, 5, 100.00 );
$r->items = array( 81 => new Fh_Line_Item( 601, 1 ), 82 => new Fh_Line_Item( 602, 1 ) );
$r->update_status( 'processing' );
fh_refund_items( $r, array( 81 => 1 ) );
$r->update_status( 'refunded' );
check( 'two items, the card paid most of it; one item returned and its other-money part refunded, so WooCommerce marks it Refunded: the card part is NOT put back by itself', 0 === (int) $s->store->card( $a )['balance_cents'] && 2500 === $s->order_summary( 2103 )['outstanding'] && false !== strpos( end( $r->notes ), 'use the Gift cards box' ) );
$c->on_partial_refund( 2103, 9 );
check( '…and no second note from the refund hook', false !== strpos( end( $r->notes ), 'NOT put back by itself' ) );
check( 'all_items_refunded (pure apart from the order): none, part, all', ! Checkout::all_items_refunded( fh_order( 2199 ) ) && ! Checkout::all_items_refunded( $r ) && ( fh_refund_items( $r ) || Checkout::all_items_refunded( $r ) ) );

echo "\nTax\n";
$c   = new Checkout();
$fee = (object) array( 'object' => (object) array( 'id' => 'fh-gift-card' ) );
check( 'the "Gift card" fee gets no tax share at checkout (it is a payment, not a discount)', array() === $c->no_fee_tax( array( 1 => -400.0 ), $fee ) );
check( '…any other fee keeps its tax', array( 1 => -400.0 ) === $c->no_fee_tax( array( 1 => -400.0 ), (object) array( 'object' => (object) array( 'id' => 'other-fee' ) ) ) );
$taxed = new class() extends Fh_Fee_Item {
	public $taxes = array( 'total' => array( 1 => -4.0 ) );
	public function __construct() {
		parent::__construct( 'fh-gift-card', -50.0, array( Checkout::FEE_META => 'yes' ) );
	}
	public function set_taxes( $t ) {
		$this->taxes = $t;
	}
};
$c->no_item_fee_tax( $taxed );
$other = clone $taxed;
$other->meta = array();
$other->taxes = array( 'total' => array( 1 => -4.0 ) );
$c->no_item_fee_tax( $other );
check( 'Recalculate on the order screen: our fee line\'s tax is taken off, another fee\'s is not', false === $taxed->taxes && array( 'total' => array( 1 => -4.0 ) ) === $other->taxes );

echo "\nThe wrong-code count\n";
check( 'a customer is counted by account', 'user:5' === Checkout::who( 5, array( 'REMOTE_ADDR' => '198.51.100.7' ) ) );
check( 'a guest by the connecting address only — a forwarded-for header changes nothing', 'ip:198.51.100.7' === Checkout::who( 0, array( 'REMOTE_ADDR' => '198.51.100.7', 'HTTP_X_FORWARDED_FOR' => '203.0.113.9' ) ) && 'ip:198.51.100.7' === Checkout::who( 0, array( 'REMOTE_ADDR' => '198.51.100.7', 'HTTP_X_FORWARDED_FOR' => '203.0.113.10' ) ) );
$g = fh_gc_service( $s->store, array( 'guest_codes' => 'yes' ) );
$GLOBALS['fh_transients'] = array();
$refused = 0;
for ( $i = 0; $i < 40; $i++ ) {
	$who = Checkout::who( 0, array( 'REMOTE_ADDR' => '198.51.100.7', 'HTTP_X_FORWARDED_FOR' => '203.0.113.' . intdiv( $i, 9 ) ) );
	if ( 'limit' === Checkout::apply_code( $g, 'WRNG-CODE-' . $i . 'AAA-BBBB', 0, $who )['code'] ) {
		$refused++;
	}
}
check( '40 wrong codes from one address, a new forwarded-for every 9: 30 refused', 30 === $refused );
$GLOBALS['fh_transients'] = array();

echo "\nCodes typed at checkout\n";
fh_wc_reset();
$s   = fh_gc_use();
$own = fh_gc_card( $s, '15.00' );
$GLOBALS['fh_current_user'] = 0;
$r = Checkout::apply_code( $s, $s->store->card( $own )['code'], 0, 'ip:198.51.100.1' );
check( 'a guest is asked to log in (Settings default)', ! $r['ok'] && 'login' === $r['code'] );
$g = fh_gc_service( $s->store, array( 'guest_codes' => 'yes' ) );
\FisHotel\Misc\Sections\Gift_Cards\Gift_Cards::use_service( $g );
$r = Checkout::apply_code( $g, $s->store->card( $own )['code'], 0, 'ip:198.51.100.1' );
check( 'with guests allowed, the card is used for this order only (not put in an account)', $r['ok'] && array( $own ) === Checkout::session_codes() && 0 === (int) $s->store->card( $own )['owner_user_id'] );
$c = new Checkout();
$o = fh_checkout( $c, 3001, 0, 40.00 );
check( 'the guest\'s order takes the $15 from that card', is_object( $o ) && 0 === (int) $s->store->card( $own )['balance_cents'] && array( $own ) === $o->meta[ Checkout::CODES_META ] );
fh_wc_reset();
\FisHotel\Misc\Sections\Gift_Cards\Gift_Cards::use_service( $s );
$theirs = fh_gc_wallet_card( $s, 6, '20.00' );
$GLOBALS['fh_current_user'] = 5;
$r = Checkout::apply_code( $s, $s->store->card( $theirs )['code'], 5, 'user:5' );
check( 'a logged-in customer cannot use somebody else\'s card', ! $r['ok'] && 6 === (int) $s->store->card( $theirs )['owner_user_id'] );
$g->find_for_checkout( $s->store->card( $theirs )['code'], 0 );
check( '…nor can a guest', ! $g->find_for_checkout( $s->store->card( $theirs )['code'], 0 )['ok'] );
for ( $i = 0; $i < 12; $i++ ) {
	Checkout::apply_code( $s, 'WRNG-CODE-' . $i . 'AAA-BBBB', 5, 'user:5' );
}
check( 'after 10 wrong codes in an hour, no more tries', 'limit' === Checkout::apply_code( $s, 'ANYC-ODEA-AAAA-BBBB', 5, 'user:5' )['code'] );
fh_gc_days( 1 );
$GLOBALS['fh_transients'] = array();
$mine = fh_gc_card( $s, '5.00' );
$r    = Checkout::apply_code( $s, $s->store->card( $mine )['code'], 5, 'user:5' );
check( 'a logged-in customer\'s code goes into their account, and "use" is ticked', $r['ok'] && 5 === (int) $s->store->card( $mine )['owner_user_id'] && Checkout::choice()['use'] );
$GLOBALS['fh_current_user'] = 0;
$_REQUEST = array( 'nonce' => 'wrong' );
try {
	( new Checkout() )->ajax_apply();
	$answer = null;
} catch ( Fh_Test_Json $j ) {
	$answer = $j;
}
check( 'the AJAX Apply checks its nonce first', $answer && ! $answer->success && 403 === $answer->status );
$_REQUEST = array();

echo "\nWhat the customer sees\n";
fh_wc_reset();
$s = fh_gc_use();
$GLOBALS['fh_current_user'] = 5;
fh_gc_wallet_card( $s, 5, '30.00' );
WC()->cart->contents_total = 50.00;
Checkout::set_choice( array( 'use' => true, 'amount' => null ) );
ob_start();
( new Checkout() )->render_control();
$html = ob_get_clean();
check( 'the box uses the theme\'s checkbox id and shows what is there', false !== strpos( $html, 'id="use_gift_card_balance"' ) && false !== strpos( $html, '$30.00' ) && false !== strpos( $html, 'name="fh_gcard_amount" value="30.00"' ) );
check( 'the checkbox is ticked when chosen', false !== strpos( $html, 'checked="checked"' ) );
WC()->cart->calculate_fees();
ob_start();
( new Checkout() )->render_marker();
check( 'with the fee on, the theme\'s hidden .fh-gc-applied marker is in the totals', false !== strpos( ob_get_clean(), 'class="fh-gc-applied"' ) );
$GLOBALS['fh_current_user'] = 6;
ob_start();
( new Checkout() )->render_control();
check( 'a customer with no balance sees no box', '' === trim( ob_get_clean() ) );
$GLOBALS['fh_current_user'] = 0;
ob_start();
( new Checkout() )->render_code_field();
check( 'a guest sees no code field (Settings default)', '' === trim( ob_get_clean() ) );
$GLOBALS['fh_current_user'] = 5;
ob_start();
( new Checkout() )->render_code_field();
$html = ob_get_clean();
check( 'a customer sees "Have a gift card?", whose field is never sent with the order', false !== strpos( $html, 'Have a gift card?' ) && false === strpos( $html, 'name="' ) );

echo "\nOld orders\n";
fh_wc_reset();
$s    = fh_gc_use();
$card = fh_gc_wallet_card( $s, 5, '40.00' );
$s->store->insert_ledger( array( 'card_id' => $card, 'type' => 'used', 'amount_cents' => -1500, 'order_id' => 4001, 'idempotency_key' => 'legacy:77', 'legacy_activity_id' => 77, 'note' => 'old', 'created_at' => '2025-06-01 12:00:00' ) );
$s->store->move_balance( $card, -1500, time() );
$old = fh_order( 4001, array( 'customer_id' => 5, 'total' => 45.0, 'status' => 'completed', 'paid' => '2025-06-01' ) );
$c   = new Checkout();
$rows = $c->order_totals( array( 'cart_subtotal' => array( 'label' => 'Subtotal:', 'value' => '$60.00' ), 'order_total' => array( 'label' => 'Total:', 'value' => '$45.00' ) ), $old );
check( 'an imported order shows "Gift card: −$15.00" just before its total', array( 'cart_subtotal', 'fh_gift_card', 'order_total' ) === array_keys( $rows ) && '−$15.00' === $rows['fh_gift_card']['value'] );
ob_start();
$c->admin_totals_row( 4001 );
check( '…and on the admin order screen', false !== strpos( ob_get_clean(), '−$15.00' ) );
$old->total = 60.0; // what WooCommerce makes of it on Recalculate
$c->keep_old_deduction( true, $old );
check( 'Recalculate keeps the deduction ($45, not $60)', 45.0 === (float) $old->total );
$c->ensure_debited( 4001 );
check( 'an old order changing status takes nothing and puts nothing back', 2500 === (int) $s->store->card( $card )['balance_cents'] );
fh_refund_items( $old );
$old->update_status( 'refunded' );
$c->on_refunded( 4001 );
check( 'refunding it puts the old $15 back on the card', 4000 === (int) $s->store->card( $card )['balance_cents'] );
$new = fh_order( 4002, array( 'total' => 20.0 ) );
check( 'a new order has no extra line', array( 'order_total' => array() ) === $c->order_totals( array( 'order_total' => array() ), $new ) );

check( 'no unexpected database error', empty( $GLOBALS['fh_db_errors'] ), implode( "\n", (array) ( $GLOBALS['fh_db_errors'] ?? array() ) ) );

fh_finish();
