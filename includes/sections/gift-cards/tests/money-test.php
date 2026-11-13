<?php
/**
 * The money rules, on a real SQL database (SQLite through fake-wpdb.php):
 * issuing, adding to an account, paying all / part / none at checkout,
 * a payment across two cards, putting back exactly once (failed,
 * cancelled, refunded — full and partial, in any order, however often
 * the hooks fire), two tabs, two requests at once, a change of order
 * before payment, and the ledger always adding up to every balance.
 *
 * Run from the plugin root:  php includes/sections/gift-cards/tests/money-test.php
 */

require_once __DIR__ . '/wp-stubs.php';

use FisHotel\Misc\Sections\Gift_Cards\Money;
use FisHotel\Misc\Sections\Gift_Cards\Service;
use FisHotel\Misc\Sections\Gift_Cards\Store;

echo "Money (pure)\n";
check_same( '"25" is 2500 cents', 2500, Money::to_cents( '25' ) );
check_same( '"$1,025.5" is 102550 cents', 102550, Money::to_cents( '$1,025.5' ) );
check_same( 'three decimals are refused', null, Money::to_cents( '1.005' ) );
check_same( 'a minus sign is refused', null, Money::to_cents( '-5' ) );
check_same( 'letters are refused', null, Money::to_cents( 'ten' ) );
check_same( 'more than the largest amount is refused', null, Money::to_cents( '5000.01' ) );
check_same( 'a decimal from the database: "12.3400" is 1234', 1234, Money::decimal_to_cents( '12.3400' ) );
check_same( 'a fraction of a cent is not rounded away', null, Money::decimal_to_cents( '12.345' ) );
check_same( 'money words', '$1,234.05', Money::format( 123405 ) );
check_same( 'negative money words', '−$5.00', Money::format( -500 ) );
check_same( 'choose: all of the wallet, up to the order', 3000, Money::choose( 5000, 3000, null ) );
check_same( 'choose: part', 1200, Money::choose( 5000, 3000, 1200 ) );
check_same( 'choose: none', 0, Money::choose( 5000, 3000, 0 ) );
check_same( 'choose: never more than the wallet', 5000, Money::choose( 5000, 9000, 7000 ) );
check_same( 'allocate: oldest card first, then the next', array( 7 => 2000, 9 => 500 ), Money::allocate( array( array( 'id' => 7, 'balance_cents' => 2000 ), array( 'id' => 9, 'balance_cents' => 3000 ) ), 2500 ) );
check_same( 'allocate: not enough is null, not a part', null, Money::allocate( array( array( 'id' => 7, 'balance_cents' => 2000 ) ), 2500 ) );

echo "\nIssuing and the account\n";
$s  = fh_gc_fresh();
$id = fh_gc_card( $s, '50.00' );
$c  = $s->store->card( $id );
check_same( 'a card is issued for its amount', 5000, (int) $c['balance_cents'] );
check( 'its code is long and random (four groups of four)', (bool) preg_match( '/^[A-Z2-9]{4}-[A-Z2-9]{4}-[A-Z2-9]{4}-[A-Z2-9]{4}$/', $c['code'] ) );
check( 'it is in nobody\'s account yet', 0 === (int) $c['owner_user_id'] );
check( 'one "issued" line in its ledger', 1 === count( $s->store->ledger_of_card( $id ) ) && 'issued' === $s->store->ledger_of_card( $id )[0]['type'] );
$again = $s->issue( array( 'amount' => '50', 'delivery' => 'none', 'request_key' => 'samekeysamekey' ), fh_gc_admin_ctx() );
$twice = $s->issue( array( 'amount' => '50', 'delivery' => 'none', 'request_key' => 'samekeysamekey' ), fh_gc_admin_ctx() );
check( 'the same form sent twice (a double click) issues ONE card', $again['ok'] && $twice['ok'] && 'already' === $twice['code'] && 2 === $s->store->count( array( 'filter' => 'all' ) ) );
check( '…and the second answer names the card the first made', $again['card_id'] === $twice['card_id'] );
check( 'an amount of 0.50 is refused', ! $s->issue( array( 'amount' => '0.50', 'delivery' => 'none', 'request_key' => 'k' . str_repeat( 'a', 12 ) ), fh_gc_admin_ctx() )['ok'] );

$r = $s->redeem( strtolower( str_replace( '-', ' ', $c['code'] ) ), 5, fh_gc_customer_ctx( 5 ) );
check( 'a customer adds it to their account (the code typed in lower case with spaces)', $r['ok'] && 5 === (int) $s->store->card( $id )['owner_user_id'] );
check( 'adding it again says it is already there', 'already' === $s->redeem( $c['code'], 5, fh_gc_customer_ctx( 5 ) )['code'] );
$other = $s->redeem( $c['code'], 6, fh_gc_customer_ctx( 6 ) );
$none  = $s->redeem( 'ZZZZ-ZZZZ-ZZZZ-ZZZZ', 6, fh_gc_customer_ctx( 6 ) );
check( 'another customer cannot add it — with the same words as a code that does not exist', ! $other['ok'] && ! $none['ok'] && $other['message'] === $none['message'] );
check( '…and it stays in the first account', 5 === (int) $s->store->card( $id )['owner_user_id'] );
check_same( 'the wallet holds it', 5000, $s->wallet( 5 )['balance'] );
check_same( 'another customer\'s wallet does not', 0, $s->wallet( 6 )['balance'] );

echo "\nPaying at checkout: all, part, none\n";
$s  = fh_gc_fresh();
$a  = fh_gc_wallet_card( $s, 5, '30.00' );
$b  = fh_gc_wallet_card( $s, 5, '20.00' );
$r  = $s->debit_order( 101, 5, array(), 4000, fh_gc_customer_ctx( 5, 'checkout' ) );
check( 'an order takes $40 from two cards', $r['ok'] && array( $a => 3000, $b => 1000 ) === $r['taken'] );
check( 'the oldest card is used up first', 0 === (int) $s->store->card( $a )['balance_cents'] && 1000 === (int) $s->store->card( $b )['balance_cents'] );
check( '…and it knows when it was last used', null !== $s->store->card( $a )['last_used_at'] );
check( 'the same order debited again (a hook fired twice) takes nothing more', 'already' === $s->debit_order( 101, 5, array(), 4000, fh_gc_customer_ctx( 5, 'checkout' ) )['code'] && 1000 === $s->wallet( 5 )['balance'] );
$r = $s->debit_order( 102, 5, array(), 400, fh_gc_customer_ctx( 5, 'checkout' ) );
check( 'part of the wallet: $4 of the $10 left', $r['ok'] && 600 === $s->wallet( 5 )['balance'] );
check( 'none: an amount of 0 takes nothing', $s->debit_order( 103, 5, array(), 0, fh_gc_customer_ctx( 5, 'checkout' ) )['ok'] && 600 === $s->wallet( 5 )['balance'] );
$r = $s->debit_order( 104, 5, array(), 700, fh_gc_customer_ctx( 5, 'checkout' ) );
check( 'more than the wallet holds is refused, and nothing is taken', ! $r['ok'] && 'short' === $r['code'] && 600 === $s->wallet( 5 )['balance'] && array() === $s->store->ledger_of_order( 104 ) );
check( 'the refusal tells the customer what to do', false !== strpos( $r['message'], 'place it again' ) );
check( 'another customer\'s cards are never drawn on', ! $s->debit_order( 105, 6, array( $b ), 100, fh_gc_customer_ctx( 6, 'checkout' ) )['ok'] && 600 === $s->wallet( 5 )['balance'] );
check( 'the ledger adds up to every balance', fh_gc_balanced( $s ) );

echo "\nPutting back exactly once\n";
$back = $s->restore_order( 101, 'failed', fh_gc_customer_ctx( 0, 'order' ) );
check( 'a failed order puts $40 back (onto both cards)', $back['ok'] && 4000 === $back['restored'] && 3000 === (int) $s->store->card( $a )['balance_cents'] );
check( 'the hook firing again puts back nothing', 0 === $s->restore_order( 101, 'failed', fh_gc_customer_ctx( 0, 'order' ) )['restored'] );
check( 'then cancelled, then refunded: still nothing more', 0 === $s->restore_order( 101, 'cancelled', fh_gc_customer_ctx( 0, 'order' ) )['restored'] && 0 === $s->restore_order( 101, 'refunded', fh_gc_customer_ctx( 0, 'order' ) )['restored'] );
check_same( 'the wallet is back to $46', 4600, $s->wallet( 5 )['balance'] );
$sum = $s->order_summary( 101 );
check( 'the order shows $40 used, $40 put back, nothing out', 4000 === $sum['used'] && 4000 === $sum['restored'] && 0 === $sum['outstanding'] );

echo "\nPartial refunds\n";
$r = $s->restore_part( 102, '1.50', 'one fish refunded', 'partkeyone0001', fh_gc_admin_ctx() );
check( 'part of an order put back: $1.50 of $4', $r['ok'] && 4750 === $s->wallet( 5 )['balance'] );
check_same( '…$2.50 still out on it', 250, $s->order_summary( 102 )['outstanding'] );
check( 'the same form again (a double click) puts back nothing more', ! $s->restore_part( 102, '1.50', 'one fish refunded', 'partkeyone0001', fh_gc_admin_ctx() )['ok'] && 250 === $s->order_summary( 102 )['outstanding'] );
$r = $s->restore_part( 102, '3.00', 'too much', 'partkeytwo0002', fh_gc_admin_ctx() );
check( 'more than is still out is refused, with what is left in words', ! $r['ok'] && false !== strpos( $r['message'], '$2.50' ) );
check( 'a partial refund needs a reason', 'reason' === $s->restore_part( 102, '1.00', '', 'partkeythree03', fh_gc_admin_ctx() )['code'] );
$r = $s->restore_order( 102, 'refunded', fh_gc_customer_ctx( 0, 'order' ) );
check( 'then the order is refunded in full: only the $2.50 left comes back', 250 === $r['restored'] && 0 === $s->order_summary( 102 )['outstanding'] );
check( '…and a partial refund after that is refused', ! $s->restore_part( 102, '0.10', 'late', 'partkeyfour004', fh_gc_admin_ctx() )['ok'] );
check( 'the ledger adds up to every balance', fh_gc_balanced( $s ) );

echo "\nThe order changed and placed again\n";
$s = fh_gc_fresh();
$a = fh_gc_wallet_card( $s, 5, '30.00' );
$s->debit_order( 201, 5, array(), 1000, fh_gc_customer_ctx( 5, 'checkout' ) );
$r = $s->debit_order( 201, 5, array(), 2500, fh_gc_customer_ctx( 5, 'checkout' ) );
check( 'the same order with a new amount: the old $10 back, the new $25 taken', $r['ok'] && 500 === $s->wallet( 5 )['balance'] && 2500 === $s->order_summary( 201 )['outstanding'] );
$back = $s->restore_order( 201, 'failed', fh_gc_customer_ctx( 0, 'order' ) );
check( 'failed after that: exactly the $25 comes back', 2500 === $back['restored'] && 3000 === $s->wallet( 5 )['balance'] );
$s->debit_order( 201, 5, array(), 1200, fh_gc_customer_ctx( 5, 'checkout' ) );
check( 'paid again after failing (the customer tried once more): $12 taken', 1800 === $s->wallet( 5 )['balance'] );
check( '…and put back once more when it fails again', 1200 === $s->restore_order( 201, 'failed', fh_gc_customer_ctx( 0, 'order' ) )['restored'] && 3000 === $s->wallet( 5 )['balance'] );
check( 'the ledger adds up to every balance', fh_gc_balanced( $s ) );

echo "\nTwo tabs\n";
$s = fh_gc_fresh();
$a = fh_gc_wallet_card( $s, 5, '50.00' );
$quote_a = Money::choose( $s->wallet( 5 )['balance'], 5000, null );
$quote_b = Money::choose( $s->wallet( 5 )['balance'], 4000, null );
check( 'both tabs see $50 to use', 5000 === $quote_a && 4000 === $quote_b );
$tab_b = $s->debit_order( 302, 5, array(), $quote_b, fh_gc_customer_ctx( 5, 'checkout' ) );
$tab_a = $s->debit_order( 301, 5, array(), $quote_a, fh_gc_customer_ctx( 5, 'checkout' ) );
check( 'tab B places its order first and pays $40', $tab_b['ok'] && 1000 === $s->wallet( 5 )['balance'] );
check( 'tab A is refused — the money is not there any more — and takes nothing', ! $tab_a['ok'] && array() === $s->store->ledger_of_order( 301 ) && 1000 === $s->wallet( 5 )['balance'] );

echo "\nA payment across two cards is all or nothing\n";
$s     = fh_gc_fresh();
$a     = fh_gc_wallet_card( $s, 5, '20.00' );
$b     = fh_gc_wallet_card( $s, 5, '20.00' );
$table = $s->store->table( 'gift_cards' );
$s2    = fh_gc_service(
	$s->store,
	array(),
	array(
		'before_move' => static function ( $card_id, $delta ) use ( $b, $table ) {
			if ( $card_id === $b && $delta < 0 ) {
				// Somebody else spends card B between our two steps.
				$GLOBALS['wpdb']->query( "UPDATE {$table} SET balance_cents = 0 WHERE id = {$b}" );
			}
		},
	)
);
$r = $s2->debit_order( 401, 5, array(), 3000, fh_gc_customer_ctx( 5, 'checkout' ) );
check( 'the second card turns out short: the payment is refused', ! $r['ok'] && 'short' === $r['code'] );
check( '…and the first card was NOT charged either (rolled back)', 2000 === (int) $s->store->card( $a )['balance_cents'] && array() === $s->store->ledger_of_order( 401 ) );

echo "\nTwo requests at the same moment\n";
$file = tempnam( sys_get_temp_dir(), 'fhgc' );
$GLOBALS['wpdb'] = new Fh_Wpdb( $file );
$GLOBALS['fh_options'] = array();
Store::forget();
$store_1 = new Store( $GLOBALS['wpdb'] );
$store_1->install( true );
$one = fh_gc_service( $store_1 );
$c   = fh_gc_wallet_card( $one, 5, '25.00' );
$db2 = new Fh_Wpdb( $file, $GLOBALS['wpdb'] );
$two = fh_gc_service( new Store( $db2 ) );
check( 'the lock is shared by two connections: one request holds it…', $store_1->lock( Service::LOCK, 1 ) );
$r = $two->debit_order( 501, 5, array(), 1000, fh_gc_customer_ctx( 5, 'checkout' ) );
check( '…so the other waits and is turned away, changing nothing', ! $r['ok'] && 'busy' === $r['code'] && 2500 === (int) $store_1->card( $c )['balance_cents'] );
$store_1->unlock( Service::LOCK );
check( 'once it is free, the other goes ahead', $two->debit_order( 501, 5, array(), 1000, fh_gc_customer_ctx( 5, 'checkout' ) )['ok'] && 1500 === (int) $store_1->card( $c )['balance_cents'] );
check( 'the guarded UPDATE alone cannot overdraw: $15 taken, then $10 more refused at $5 left', $store_1->move_balance( $c, -1000, time() ) && ! ( new Store( $db2 ) )->move_balance( $c, -1000, time() ) && 500 === (int) $store_1->card( $c )['balance_cents'] );
@unlink( $file );

echo "\nBalances by hand\n";
$s = fh_gc_fresh();
$a = fh_gc_card( $s, '10.00' );
check( 'a credit needs a reason', 'reason' === $s->adjust( $a, 'credit', '5', '', 'adjkey00000001', fh_gc_admin_ctx() )['code'] );
check( 'a credit of $5 with a reason', $s->adjust( $a, 'credit', '5', 'goodwill for a late box', 'adjkey00000001', fh_gc_admin_ctx() )['ok'] && 1500 === (int) $s->store->card( $a )['balance_cents'] );
check( 'the same form twice credits once', 'already' === $s->adjust( $a, 'credit', '5', 'goodwill for a late box', 'adjkey00000001', fh_gc_admin_ctx() )['code'] && 1500 === (int) $s->store->card( $a )['balance_cents'] );
check( 'a debit below zero is refused', 'short' === $s->adjust( $a, 'debit', '20', 'typo test', 'adjkey00000002', fh_gc_admin_ctx() )['code'] && 1500 === (int) $s->store->card( $a )['balance_cents'] );
check( 'a debit of $15 leaves it used up', $s->adjust( $a, 'debit', '15', 'paid in the shop', 'adjkey00000003', fh_gc_admin_ctx() )['ok'] && 0 === (int) $s->store->card( $a )['balance_cents'] );
$d = fh_gc_wallet_card( $s, 7, '10.00' );
check( 'a disabled card cannot pay', $s->set_status( $d, 'disabled', 'reported lost', fh_gc_admin_ctx() )['ok'] && ! $s->debit_order( 601, 7, array(), 500, fh_gc_customer_ctx( 7, 'checkout' ) )['ok'] );
check( '…but keeps its money', 1000 === (int) $s->store->card( $d )['balance_cents'] );
check( 'the ledger adds up to every balance', fh_gc_balanced( $s ) );

echo "\nNo expiry\n";
$s = fh_gc_fresh();
$a = fh_gc_wallet_card( $s, 5, '10.00' );
fh_gc_days( 365 * 9 );
check( 'nine years later the card still pays', $s->debit_order( 701, 5, array(), 1000, fh_gc_customer_ctx( 5, 'checkout' ) )['ok'] );
check( 'there is no expiry column a new card could be given', ! array_key_exists( 'expires_at', $s->store->card( $a ) ) && array_key_exists( 'legacy_expires_at', $s->store->card( $a ) ) );

check( 'no database error was logged in this whole file', empty( $GLOBALS['fh_db_errors'] ), implode( "\n", (array) ( $GLOBALS['fh_db_errors'] ?? array() ) ) );

fh_finish();
