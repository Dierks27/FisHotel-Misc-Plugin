<?php
/**
 * Gift card products (Live): which products are gift cards (the new flag
 * and the old plugin's, on a variation's parent too), the purchase fields
 * (fixed amount, a custom amount inside its limits, the recipient's
 * address, the delivery date), the cart line, the order line, and issuing
 * when paid — one card per unit, exactly once however many "paid" hooks
 * fire, its e-mail now or on its day (the hourly job), with an order note
 * naming the masked codes.
 *
 * Run from the plugin root:  php includes/sections/gift-cards/tests/product-test.php
 */

require_once __DIR__ . '/wp-stubs.php';
require_once __DIR__ . '/wc-stubs.php';

use FisHotel\Misc\Sections\Gift_Cards\Product;
use FisHotel\Misc\Sections\Gift_Cards\Settings;

$product = new Product();
$product->init();

echo "Which products\n";
fh_wc_reset();
$s = fh_gc_use();
fh_product( 10, '25.00', array( '_fh_gift_card' => 'yes' ) );
fh_product( 11, '50.00', array( '_gift_card' => 'yes' ) );
fh_product( 12, '9.99' );
fh_product( 13, '100.00', array(), 11 );
check( 'the new flag', Product::is_gift( 10 ) );
check( 'the old plugin\'s flag, read only', Product::is_gift( 11 ) );
check( 'a variation of a gift card product', Product::is_gift( wc_get_product( 13 ) ) );
check( 'not an ordinary product', ! Product::is_gift( 12 ) );

echo "\nThe purchase fields\n";
$today = '2026-03-02';
$r     = Product::read_purchase( array( 'fh_gcard_to_name' => 'Sam', 'fh_gcard_to_email' => 'SAM@example.test', 'fh_gcard_message' => 'For the new tank' ), 2500, null, $today );
check( 'a fixed amount: the price', $r['ok'] && 2500 === $r['data']['amount_cents'] && 'sam@example.test' === $r['data']['recipient_email'] );
$limits = Product::custom_limits( 10, Settings::merge( array() ) );
check( 'no custom amount unless the product allows it', null === $limits );
$GLOBALS['fh_post_meta'][10]['_fh_gift_card_custom'] = 'yes';
$GLOBALS['fh_post_meta'][10]['_fh_gift_card_min']    = '10.00';
$limits = Product::custom_limits( 10, Settings::merge( array() ) );
check( 'its limits: the product\'s smallest, the Settings\' largest', array( 'min' => 1000, 'max' => 50000 ) === $limits );
check( 'a custom amount inside them', 4200 === Product::read_purchase( array( 'fh_gcard_amount' => '42' ), 2500, $limits, $today )['data']['amount_cents'] );
check( 'below them is refused, naming the range', false !== strpos( implode( ' ', Product::read_purchase( array( 'fh_gcard_amount' => '5' ), 2500, $limits, $today )['errors'] ), '$10.00 and $500.00' ) );
check( 'left empty: the price', 2500 === Product::read_purchase( array( 'fh_gcard_amount' => '' ), 2500, $limits, $today )['data']['amount_cents'] );
check( 'a product with no price and no custom amount is refused', ! Product::read_purchase( array(), 0, null, $today )['ok'] );
check( 'a bad e-mail address is refused', ! Product::read_purchase( array( 'fh_gcard_to_email' => 'not-an-address' ), 2500, null, $today )['ok'] );
check( 'a delivery date in the past is refused', ! Product::read_purchase( array( 'fh_gcard_date' => '2026-03-01' ), 2500, null, $today )['ok'] );
check( '…more than a year ahead too', ! Product::read_purchase( array( 'fh_gcard_date' => '2027-06-01' ), 2500, null, $today )['ok'] );
check( '…a date ahead is kept', '2026-12-24' === Product::read_purchase( array( 'fh_gcard_date' => '2026-12-24' ), 2500, null, $today )['data']['deliver_on'] );
check( 'HTML in a message is dropped', 'Hi there' === Product::read_purchase( array( 'fh_gcard_message' => '<b>Hi</b> there' ), 2500, null, $today )['data']['message'] );

echo "\nAdd to cart, the cart line, the order line\n";
$_POST = array( 'fh_gcard_amount' => '5' );
check( 'Add to cart with a bad amount is stopped with a notice', false === $product->validate_add( true, 10, 1, 0 ) && 'error' === end( $GLOBALS['fh_notices'] )[0] );
$_POST = array( 'fh_gcard_amount' => '42', 'fh_gcard_to_name' => 'Sam', 'fh_gcard_to_email' => 'sam@example.test', 'fh_gcard_date' => '' );
check( 'with a good one it passes', true === $product->validate_add( true, 10, 1, 0 ) );
$data = $product->cart_item_data( array(), 10, 0 );
check( 'the line keeps the details, and never merges with another card\'s line', 4200 === $data['fh_gcard']['amount_cents'] && ! empty( $data['fh_gcard_unique'] ) );
check( 'an ordinary product\'s line is left alone', array() === $product->cart_item_data( array(), 12, 0 ) );
$_POST = array();
$cart_line = array_merge( $data, array( 'data' => fh_product( 10, '25.00', array( '_fh_gift_card' => 'yes', '_fh_gift_card_custom' => 'yes' ) ) ) );
WC()->cart->items = array( 'k' => $cart_line );
$product->set_prices( WC()->cart );
check( 'the chosen amount is the line\'s price', 42.0 === (float) $cart_line['data']->get_price() );
$rows = $product->item_data( array(), $cart_line );
check( 'the cart shows who it is for and when it goes', 'Gift card for' === $rows[0]['key'] && 'Sam <sam@example.test>' === $rows[0]['value'] && 'when the order is paid' === $rows[1]['value'] );
$item = new Fh_Line_Item( 10, 2 );
$product->line_item( $item, 'k', $cart_line, null );
check( 'the order line keeps the amount and the details', 4200 === $item->meta[ Product::ITEM_AMOUNT ] && 'Sam' === $item->meta[ Product::ITEM_DATA ]['recipient_name'] && isset( $item->meta['Gift card for'] ) );
check( 'the section\'s own line meta is hidden on order screens', in_array( Product::ITEM_DATA, $product->hidden_meta( array() ), true ) );

echo "\nIssuing when paid\n";
$order        = fh_order( 501, array( 'customer_id' => 5, 'status' => 'pending' ) );
$order->items = array( 901 => $item );
$order->update_status( 'on-hold' );
check( 'not paid yet: no card', 0 === $s->store->count( array( 'filter' => 'all' ) ) );
$order->update_status( 'processing' );
$cards = $s->store->cards_of_order( 501 );
check( 'paid: one card per unit (2 × $42)', 2 === count( $cards ) && 4200 === (int) $cards[0]['balance_cents'] && 4200 === (int) $cards[1]['balance_cents'] );
check( '…for the recipient, from the buyer, bought on that line', 'sam@example.test' === $cards[0]['recipient_email'] && 'pat@example.test' === $cards[0]['sender_email'] && 'Sam' === $cards[0]['recipient_name'] && 901 === (int) $cards[0]['order_item_id'] && 'order' === $cards[0]['source'] && 5 === (int) $cards[0]['buyer_user_id'] );
check( '…each e-mailed with its own full code', 2 === count( $GLOBALS['fh_mails'] ) && false !== strpos( $GLOBALS['fh_mails'][0]['message'], $cards[0]['code'] ) && 'sam@example.test' === $GLOBALS['fh_mails'][0]['to'] );
check( '…and the order note names them masked, never in full', false !== strpos( implode( ' ', $order->notes ), '••••' ) && false === strpos( implode( ' ', $order->notes ), $cards[0]['code'] ) );
do_action( 'woocommerce_payment_complete', 501 );
$order->update_status( 'completed' );
check( 'payment complete and completed fire too: still exactly 2 cards, 2 e-mails', 2 === $s->store->count( array( 'filter' => 'all' ) ) && 2 === count( $GLOBALS['fh_mails'] ) );
ob_start();
$product->issued_note( 901, $item, null );
$note = ob_get_clean();
check( 'the order screen links each card, masked', 2 === substr_count( $note, '••••-••••-••••-' ) && false === strpos( $note, $cards[0]['code'] ) );
$order->update_status( 'refunded' );
check( 'refunding an order that issued cards leaves a note naming them (they are not switched off by themselves)', false !== strpos( end( $order->notes ), 'still work' ) && 'active' === $s->store->card( (int) $cards[0]['id'] )['status'] );

echo "\nA delivery date ahead\n";
$GLOBALS['fh_mails'] = array();
$later               = new Fh_Line_Item( 10, 1, array( Product::ITEM_AMOUNT => 2500, Product::ITEM_DATA => array( 'recipient_name' => 'Ari', 'recipient_email' => 'ari@example.test', 'sender_name' => '', 'message' => '', 'deliver_on' => '2026-03-10' ) ) );
$o2                  = fh_order( 502, array( 'customer_id' => 5 ) );
$o2->items           = array( 902 => $later );
$o2->update_status( 'processing' );
check( 'no e-mail yet', array() === $GLOBALS['fh_mails'] && 'pending' === $s->store->cards_of_order( 502 )[0]['email_state'] );
check( 'the sender defaults to the buyer\'s billing name', 'Pat Tester' === $s->store->cards_of_order( 502 )[0]['sender_name'] );
fh_gc_days( 5 );
$s->deliver_due( fh_gc_customer_ctx( 0, 'cron' ) );
check( 'five days on, the hourly job still waits', array() === $GLOBALS['fh_mails'] );
fh_gc_days( 3 );
$r = $s->deliver_due( fh_gc_customer_ctx( 0, 'cron' ) );
check( 'on its day (9 am site time) it goes, once', 1 === $r['sent'] && 1 === count( $GLOBALS['fh_mails'] ) && 'sent' === $s->store->cards_of_order( 502 )[0]['email_state'] );
check( 'a second run sends nothing', 0 === $s->deliver_due( fh_gc_customer_ctx( 0, 'cron' ) )['sent'] && 1 === count( $GLOBALS['fh_mails'] ) );
$GLOBALS['fh_mail_fails'] = true;
$id = fh_gc_card( $s, '5.00', array( 'delivery' => 'now' ) );
check( 'an e-mail that fails is marked so (and can be sent again from its page)', 'failed' === $s->store->card( $id )['email_state'] );
$GLOBALS['fh_mail_fails'] = false;

echo "\nA purchase refunded before its card is sent\n";
$GLOBALS['fh_mails'] = array();
$gift      = new Fh_Line_Item( 10, 2, array( Product::ITEM_AMOUNT => 5000, Product::ITEM_DATA => array( 'recipient_name' => 'Ari', 'recipient_email' => 'ari@example.test', 'sender_name' => '', 'message' => '', 'deliver_on' => '2026-03-30' ) ) );
$o3        = fh_order( 503, array( 'customer_id' => 5 ) );
$o3->items = array( 903 => $gift );
$o3->update_status( 'processing' );
$two = $s->store->cards_of_order( 503 );
$s->redeem( $two[1]['code'], 8, fh_gc_customer_ctx( 8 ) ); // the second one was already added to an account
$o3->update_status( 'refunded' );
check( 'refunded: the e-mail of the card nobody has used or added is held back', 'held' === $s->store->card( (int) $two[0]['id'] )['email_state'] && 'pending' === $s->store->card( (int) $two[1]['id'] )['email_state'] );
check( '…and the note says so, masked (the added one is listed as still working)', false !== strpos( implode( ' ', $o3->notes ), 'e-mails are held back' ) && false !== strpos( end( $o3->notes ), 'still work' ) && false === strpos( implode( ' ', $o3->notes ), $two[0]['code'] ) );
fh_gc_days( 30 );
$r = $s->deliver_due( fh_gc_customer_ctx( 0, 'cron' ) );
check( 'on the delivery date the held card is not sent (the one in an account is)', 1 === $r['sent'] && 1 === count( $GLOBALS['fh_mails'] ) && false !== strpos( $GLOBALS['fh_mails'][0]['message'], $two[1]['code'] ) && false === strpos( $GLOBALS['fh_mails'][0]['message'], $two[0]['code'] ) );
check( '…it stays held', 'held' === $s->store->card( (int) $two[0]['id'] )['email_state'] );
$held_rows = $GLOBALS['wpdb']->pdo->query( 'SELECT action, field, old_value, new_value, source FROM ' . $s->store->table( 'gift_card_audit' ) . " WHERE card_id = " . (int) $two[0]['id'] . " AND new_value = 'held'" )->fetchAll( PDO::FETCH_ASSOC );
check( 'holding it is in the audit log', array( array( 'action' => 'details', 'field' => 'email_state', 'old_value' => 'pending', 'new_value' => 'held', 'source' => 'order' ) ) === $held_rows );
$GLOBALS['fh_mails'] = array();
$r = $s->resend( (int) $two[0]['id'], '', fh_gc_admin_ctx() );
check( 'the card\'s Send button still sends it, once asked', $r['ok'] && 'sent' === $s->store->card( (int) $two[0]['id'] )['email_state'] && 1 === count( $GLOBALS['fh_mails'] ) );
$o3->update_status( 'cancelled' );
check( 'a card already sent is not held again', 'sent' === $s->store->card( (int) $two[0]['id'] )['email_state'] );
check( 'the ledger adds up and every movement has its audit row', fh_gc_balanced( $s ) );

check( 'no unexpected database error', empty( $GLOBALS['fh_db_errors'] ), implode( "\n", (array) ( $GLOBALS['fh_db_errors'] ?? array() ) ) );

fh_finish();
