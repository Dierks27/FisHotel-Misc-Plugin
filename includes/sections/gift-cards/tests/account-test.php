<?php
/**
 * My Account → Gift Cards (Live): the endpoint and menu entry, the forms
 * (nonce first, then logged in, then whose card it is), a card only
 * added with its code (never by e-mail address alone), the page showing only
 * my cards and my activity — never another customer's — and the template
 * printed with nothing unescaped.
 *
 * Run from the plugin root:  php includes/sections/gift-cards/tests/account-test.php
 */

require_once __DIR__ . '/wp-stubs.php';
require_once __DIR__ . '/wc-stubs.php';

use FisHotel\Misc\Sections\Gift_Cards\Account;

$GLOBALS['fh_users'][5] = array( 'login' => 'pat', 'email' => 'pat@example.test', 'display' => 'Pat Tester' );
$GLOBALS['fh_users'][6] = array( 'login' => 'sam', 'email' => 'sam@example.test', 'display' => 'Sam Example' );

function fh_post( array $fields, $user = 5 ) {
	$GLOBALS['fh_current_user'] = $user;
	return array_merge( array( '_fh_gcard_nonce' => wp_create_nonce( Account::NONCE ) ), $fields );
}

$a = new Account();
check( 'the same address as before: giftcards', array( 'orders' => 'orders', 'giftcards' => 'giftcards' ) === $a->query_vars( array( 'orders' => 'orders' ) ) );
check( 'the menu entry goes before Log out', array( 'orders', 'giftcards', 'customer-logout' ) === array_keys( $a->menu_items( array( 'orders' => 'Orders', 'customer-logout' => 'Log out' ) ) ) );
check( 'its own template name — never the old plugin\'s myaccount/giftcards.php', 'myaccount/fishotel-gift-cards.php' === Account::TEMPLATE && false === strpos( file_get_contents( FISHOTEL_MISC_PATH . 'includes/sections/gift-cards/class-account.php' ), "'myaccount/giftcards.php'" ) );
$src = '';
foreach ( glob( FISHOTEL_MISC_PATH . 'includes/sections/gift-cards/{*.php,views/*.php,views/*/*.php}', GLOB_BRACE ) as $f ) {
	if ( 'class-legacy-map.php' !== basename( $f ) ) { // its gc_id is the old table's column
		$src .= file_get_contents( $f );
	}
}
check( 'the theme\'s old names are not used: fh_gc_action, gc_id, fh_gc_nonce, woocommerce_gc_before_account_giftcards', ! preg_match( '/[\'"](fh_gc_action|gc_id|fh_gc_nonce|woocommerce_gc_before_account_giftcards)[\'"]/', $src ) );

echo "\nThe forms\n";
fh_wc_reset();
$s    = fh_gc_use();
$ctx  = fh_gc_customer_ctx( 5 );
$card = fh_gc_card( $s, '25.00' );
$code = $s->store->card( $card )['code'];
$r = Account::perform( $s, array( 'fh_gcard_action' => 'redeem', 'fh_gcard_code' => $code, '_fh_gcard_nonce' => 'forged' ), 5, 'pat@example.test', $ctx );
check( 'a forged / old nonce is refused first, and nothing changes', 'nonce' === $r['code'] && 0 === (int) $s->store->card( $card )['owner_user_id'] );
$r = Account::perform( $s, fh_post( array( 'fh_gcard_action' => 'redeem', 'fh_gcard_code' => $code ), 0 ), 0, '', fh_gc_customer_ctx( 0 ) );
check( 'logged out: refused', 'login' === $r['code'] );
$r = Account::perform( $s, fh_post( array( 'fh_gcard_action' => 'redeem', 'fh_gcard_code' => $code ) ), 5, 'pat@example.test', $ctx );
check( 'redeem a code: it is in my account', $r['ok'] && 5 === (int) $s->store->card( $card )['owner_user_id'] );
$r = Account::perform( $s, fh_post( array( 'fh_gcard_action' => 'redeem', 'fh_gcard_code' => $code ), 6 ), 6, 'sam@example.test', fh_gc_customer_ctx( 6 ) );
check( 'another customer cannot take it', ! $r['ok'] && 5 === (int) $s->store->card( $card )['owner_user_id'] );

$for_sam = fh_gc_card( $s, '10.00', array( 'recipient_email' => 'Sam@Example.test' ) );
$r = Account::perform( $s, fh_post( array( 'fh_gcard_action' => 'claim', 'card' => $for_sam ), 9 ), 9, 'sam@example.test', fh_gc_customer_ctx( 9 ) );
check( 'an account that only says it has the recipient\'s e-mail address (WooCommerce does not check a changed address) cannot take the card: there is no "add without the code"', ! $r['ok'] && 0 === (int) $s->store->card( $for_sam )['owner_user_id'] );
check( '…nor is the card listed for that account', array() === Account::data( $s, 9, 'sam@example.test' )['cards'] && ! isset( Account::data( $s, 9, 'sam@example.test' )['unclaimed'] ) );
$r = Account::perform( $s, fh_post( array( 'fh_gcard_action' => 'redeem', 'fh_gcard_code' => $s->store->card( $for_sam )['code'] ), 6 ), 6, 'sam@example.test', fh_gc_customer_ctx( 6 ) );
check( 'the recipient adds it with the code from the e-mail', $r['ok'] && 6 === (int) $s->store->card( $for_sam )['owner_user_id'] );
for ( $i = 0; $i < 10; $i++ ) {
	Account::perform( $s, fh_post( array( 'fh_gcard_action' => 'redeem', 'fh_gcard_code' => 'BADC-ODE' . $i . '-AAAA-BBBB' ) ), 5, 'pat@example.test', $ctx );
}
check( 'ten wrong codes, then no more tries this hour', 'limit' === Account::perform( $s, fh_post( array( 'fh_gcard_action' => 'redeem', 'fh_gcard_code' => $code ) ), 5, 'pat@example.test', $ctx )['code'] );

echo "\nThe page shows my cards only\n";
$GLOBALS['fh_transients'] = array();
fh_gc_card( $s, '30.00', array( 'recipient_email' => 'pat@example.test' ) ); // sent to Pat, not added yet
$s->debit_order( 7001, 5, array(), 500, fh_gc_customer_ctx( 5, 'checkout' ) );
$mine   = Account::data( $s, 5, 'pat@example.test' );
$theirs = Account::data( $s, 6, 'sam@example.test' );
check( 'my balance: $20 (the $25 card, $5 used)', 2000 === $mine['balance'] );
check( 'my cards: one, with its code', 1 === count( $mine['cards'] ) && $code === $mine['codes'][ $card ] );
check( 'my activity: issued, added, used on #7001', array( 'used', 'redeemed_to_account', 'issued' ) === array_column( $mine['activity'], 'type' ) && 'Used on order #7001' === Account::activity_words( $mine['activity'][0] ) );
check( 'Sam sees only Sam\'s card — none of mine, nor my code', 1 === count( $theirs['cards'] ) && ! in_array( $code, $theirs['codes'], true ) && array() === array_intersect( array_column( $theirs['activity'], 'card_id' ), array( (string) $card, $card ) ) );

$args = array_merge( $mine, array( 'flash' => array( 'ok' => true, 'message' => 'Gift card added <b>to</b> your account' ), 'page_url' => Account::url(), 'nonce' => wp_create_nonce( Account::NONCE ) ) );
extract( $args ); // phpcs:ignore
ob_start();
include FISHOTEL_MISC_PATH . 'includes/sections/gift-cards/views/' . Account::TEMPLATE;
$html = ob_get_clean();
check( 'the page prints the balance, the redeem form and my code', false !== strpos( $html, '$20.00' ) && false !== strpos( $html, 'name="fh_gcard_code"' ) && false !== strpos( $html, $code ) );
check( 'the flash is escaped', false !== strpos( $html, 'Gift card added &lt;b&gt;to&lt;/b&gt; your account' ) );
check( 'a card sent to my address but not added is not on the page, and there is no "Add to my account"', false === strpos( $html, 'Add to my account' ) && false === strpos( $html, '$30.00' ) );
check( 'the theme\'s classes are used', false !== strpos( $html, 'woocommerce-giftcards-table' ) && false !== strpos( $html, 'fh-giftcard-code' ) );

check( 'no unexpected database error', empty( $GLOBALS['fh_db_errors'] ), implode( "\n", (array) ( $GLOBALS['fh_db_errors'] ?? array() ) ) );

fh_finish();
