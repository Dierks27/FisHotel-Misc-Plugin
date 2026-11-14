<?php
/**
 * Review tests — one scenario per thing two reviews found, on the real
 * classes with the shared stand-ins:
 *
 *   - "cancel the whole order" after a store credit or a refund already
 *     went back on the same order (what is LEFT, never the total twice);
 *   - an order whose customer chose to cancel owes no fish from that
 *     answer on;
 *   - undoing an earlier loss the extra had covered closes (or shrinks)
 *     the unsent case a later loss opened;
 *   - a paid holder behind hundreds of newer unpaid orders is still
 *     found, and a look that had to leave orders unread says so;
 *   - a ship date that has gone by is never printed as "it ships on";
 *   - the customer's Confirm reads the order again: closed orders,
 *     "cancel" once part of it shipped;
 *   - two cases on one order never withhold the shipping difference
 *     twice — and need nothing typed when it stays the same either way;
 *   - reopening an answered case says what was chosen, and a line that
 *     was refunded blocks a second Send;
 *   - a page that is behind its case does nothing;
 *   - a case on an unpaid order cannot be sent.
 *
 * (The contract function while the section is off: section-test.php.)
 *
 * Run from the plugin root:
 *   php includes/sections/lost-fish/tests/review-test.php
 *
 * @package FisHotel\Misc\Sections\Lost_Fish
 */

require_once __DIR__ . '/wp-stubs.php';
require_once __DIR__ . '/fakes.php';

use FisHotel\Misc\Sections\Lost_Fish\Admin;
use FisHotel\Misc\Sections\Lost_Fish\Cases;
use FisHotel\Misc\Sections\Lost_Fish\Customer_Page;
use FisHotel\Misc\Sections\Lost_Fish\Email;
use FisHotel\Misc\Sections\Lost_Fish\Lost_Fish;
use FisHotel\Misc\Sections\Lost_Fish\Orders;
use FisHotel\Misc\Sections\Lost_Fish\Shipping;
use FisHotel\Misc\Sections\Lost_Fish\Store;

/**
 * The shop of the cases test: five Lagoon Gobies alive in two tank
 * groups, four owed on three paid orders (502 paid last, then 503, then
 * 501), and user 5 allowed to manage WooCommerce.
 *
 *   501  1 goby (60.00) + 2 tangs (460.00), shipping 15.00 — one goby less drops under the threshold (25.00 more)
 *   502  2 gobies (120.00) + 3 tangs (690.00), shipping 15.00 — stays over
 *   503  1 goby (60.00), shipping 40.00
 *
 * @return array{0: Cases, 1: Fh_Fake_Store, 2: Fh_Fake_Rooms}
 */
function fh_shop() {
	fh_reset();
	$GLOBALS['fh_options'][ Store::DB_VERSION_OPTION ] = Store::DB_VERSION;
	$GLOBALS['fh_user_caps']                           = array( 'manage_woocommerce' => array( 5 ) );
	$GLOBALS['fh_current_user']                        = 5;
	Shipping::$rater                                   = 'fh_rater';

	fh_product( 812, array( 'class' => 'premium' ) );
	fh_product( 813, array( 'class' => 'premium' ) );

	fh_order( 501, array( 'paid' => 1000, 'shipping' => 15.00, 'first_name' => 'Avery', 'last_name' => 'Example', 'email' => 'avery@example.test', 'customer_id' => 41, 'meta' => array( '_fishotel_shipping_date' => '2031-03-12' ), 'lines' => array( 51 => array( 'name' => 'Lagoon Goby', 'product_id' => 812, 'qty' => 1, 'total' => 60.00 ), 52 => array( 'name' => 'Harbor Tang', 'product_id' => 813, 'qty' => 2, 'total' => 460.00 ) ) ) );
	fh_order( 502, array( 'paid' => 3000, 'shipping' => 15.00, 'first_name' => 'Blake', 'last_name' => 'Sample', 'email' => 'blake@example.test', 'customer_id' => 42, 'lines' => array( 53 => array( 'name' => 'Lagoon Goby', 'product_id' => 812, 'qty' => 2, 'total' => 120.00 ), 54 => array( 'name' => 'Harbor Tang', 'product_id' => 813, 'qty' => 3, 'total' => 690.00 ) ) ) );
	fh_order( 503, array( 'paid' => 2000, 'shipping' => 40.00, 'first_name' => 'Casey', 'last_name' => 'Tester', 'email' => 'casey@example.test', 'customer_id' => 0, 'lines' => array( 55 => array( 'name' => 'Lagoon Goby', 'product_id' => 812, 'qty' => 1, 'total' => 60.00 ) ) ) );

	$rooms = new Fh_Fake_Rooms();
	$rooms->add( 1, array( 'product_id' => 812, 'species' => 'Lagoon Goby', 'size_slug' => 'm', 'size_label' => 'Medium', 'qty_live' => 3, 'tank_number' => 521 ) );
	$rooms->add( 2, array( 'product_id' => 812, 'species' => 'Lagoon Goby', 'size_slug' => 'l', 'size_label' => 'Large', 'qty_live' => 2, 'status' => 'cleared', 'tank_number' => 121 ) );
	$rooms->titles[812] = 'Lagoon Goby';

	$store = new Fh_Fake_Store();
	$cases = fh_cases( $store, $rooms );
	Cases::set_instance( $cases );

	return array( $cases, $store, $rooms );
}

/**
 * One more paid order in that shop: 1 goby (60.00) + 3 tangs (690.00),
 * shipping 15.00 — 765.00 in all.
 */
function fh_order_701() {
	return fh_order( 701, array( 'paid' => 500, 'shipping' => 15.00, 'first_name' => 'Devon', 'last_name' => 'Mock', 'email' => 'devon@example.test', 'customer_id' => 47, 'lines' => array( 71 => array( 'name' => 'Lagoon Goby', 'product_id' => 812, 'qty' => 1, 'total' => 60.00 ), 72 => array( 'name' => 'Harbor Tang', 'product_id' => 813, 'qty' => 3, 'total' => 690.00 ) ) ) );
}

/**
 * The link tokens of the latest e-mail, by choice.
 *
 * @return array<string, string>
 */
function fh_last_tokens() {
	$mail   = $GLOBALS['fh_mail'][ count( $GLOBALS['fh_mail'] ) - 1 ];
	$tokens = array();
	preg_match_all( '/fh_lost_fish=(\d+)\.([a-z]+)\.(\d+)\.([a-f0-9]{40})/', $mail['message'], $m, PREG_SET_ORDER );
	foreach ( $m as $hit ) {
		$tokens[ $hit[2] ] = $hit[1] . '.' . $hit[2] . '.' . $hit[3] . '.' . $hit[4];
	}
	return $tokens;
}

/**
 * The facts of an order as they are now.
 */
function fh_facts( Cases $cases, $order_id ) {
	return $cases->orders()->facts( $GLOBALS['fh_orders'][ (int) $order_id ] );
}

/**
 * A request on a case, as its form posts it — "seen" as the page shows
 * the case right now unless another one is handed in.
 */
function fh_post( Cases $cases, $case_id, $op, array $fields = array() ) {
	return array_merge(
		array(
			'case_id'  => (string) $case_id,
			'op'       => $op,
			'_wpnonce' => wp_create_nonce( Admin::NONCE_CASE . $case_id ),
			'seen'     => fh_seen( $cases, $case_id ),
		),
		$fields
	);
}

add_action( 'fishotel_cleanroom_event_recorded', array( Cases::class, 'on_cleanroom_event' ), 10, 4 );
$now = time();

/* ================================================================== */
echo "Cancel the whole order, after a store credit was given on it\n";
list( $cases, $store, $rooms ) = fh_shop();
fh_order_701();
$first = $cases->open_by_hand( 701, 'i:71', 1, 5 )['case']['id'];
$cases->answer( $first, 'credit', 'owner', 5 );
$cases->done( $first, 'Added to the wallet.', 5 );
check_same( 'the first case: 60.00 of store credit, ticked Done', array( 'done', 'credit', 6000 ), array( $store->get_case( $first )['status'], $store->get_case( $first )['choice'], $store->get_case( $first )['amount_refund'] ) );

$second = $cases->open_by_hand( 701, 'i:72', 1, 5 )['case']['id'];
$nums   = $cases->numbers( $store->get_case( $second ), fh_facts( $cases, 701 ) );
check_same( 'a second case on the order: "cancel" is the 765.00 LESS the 60.00 credit, not the whole 765.00 again', array( 70500, 6000 ), array( $nums['cancel'], $nums['settled'] ) );
check_same( 'it is sent', true, $cases->send( $second, 5 )['ok'] );
check_contains( 'the e-mail says so in plain words', 'refund the rest of what you paid for it: $705.00 ($60.00 already went back to you as a refund or a store credit)', $GLOBALS['fh_mail'][0]['message'] );
check_lacks( '… and never offers the 765.00', '$765.00', $GLOBALS['fh_mail'][0]['message'] );
$view = Customer_Page::handle( fh_last_tokens()['cancel'], 'POST', true, $cases, $now );
check_same( 'the customer confirms "cancel": 705.00', array( 'recorded', 70500 ), array( $view['state'], $view['row']['amount'] ) );
$todo = $cases->todo( $store->get_case( $second ), fh_facts( $cases, 701 ) );
check_same( 'what the owner has to do: refund 705.00', 'Cancel order #701 and refund $705.00.', $todo['text'] );
check_contains( '… with the reason beside it', 'The order came to $765.00; $60.00 was already given as store credit (case #' . $first . ').', $todo['detail'] );
check_contains( 'the order note carries the same amount', 'cancel the whole order — $705.00', implode( "\n", fh_notes( 701 ) ) );

/* ================================================================== */
echo "\nCancel, after money was refunded on the order since the e-mail\n";
list( $cases, $store, $rooms ) = fh_shop();
fh_order_701();
$case = $cases->open_by_hand( 701, 'i:72', 1, 5 )['case']['id'];
$cases->send( $case, 5 );
check_same( 'the e-mail offered 765.00 for "cancel"', 76500, $store->get_case( $case )['amount_cancel'] );
$GLOBALS['fh_orders'][701]->refunded = 35.00; // The owner refunded 35.00 on the order since.
$tokens                              = fh_last_tokens();
$view                                = Customer_Page::handle( $tokens['cancel'], 'GET', false, $cases, $now );
check_same( 'the cancel link now shows what is left: 730.00', array( 'confirm', 73000 ), array( $view['state'], $view['row']['amount'] ) );
check_contains( '… and says why', '$35.00 already went back to you', Customer_Page::render( $view ) );
Customer_Page::handle( $tokens['cancel'], 'POST', true, $cases, $now );
$todo = $cases->todo( $store->get_case( $case ), fh_facts( $cases, 701 ) );
check_same( 'the to-do says 730.00 too', 'Cancel order #701 and refund $730.00.', $todo['text'] );
check_contains( '…', '$35.00 was already refunded on the order', $todo['detail'] );
$GLOBALS['fh_orders'][701]->total = 900.00; // Something was added to the order afterwards.
check_same( 'never more than the e-mail said', 76500, $cases->numbers( $store->get_case( $case ), fh_facts( $cases, 701 ) )['cancel'] );
$GLOBALS['fh_orders'][701]->total    = 765.00;
$GLOBALS['fh_orders'][701]->refunded = 765.00;
$cases->done( $case, '', 5 );
check_same( 'once ticked Done the case keeps what was promised — not "0.00 left"', 76500, $cases->numbers( $store->get_case( $case ), fh_facts( $cases, 701 ) )['cancel'] );

/* ================================================================== */
echo "\nCancel, while a refund of another case is still to be made\n";
list( $cases, $store, $rooms ) = fh_shop();
fh_order_701();
$first = $cases->open_by_hand( 701, 'i:71', 1, 5 )['case']['id'];
$cases->answer( $first, 'refund', 'owner', 5 ); // Answered, the 60.00 not refunded yet.
$second = $cases->open_by_hand( 701, 'i:72', 1, 5 )['case']['id'];
$cases->send( $second, 5 );
Customer_Page::handle( fh_last_tokens()['cancel'], 'POST', true, $cases, $now );
$todo = $cases->todo( $store->get_case( $second ), fh_facts( $cases, 701 ) );
check_contains( 'the cancel to-do warns that the other case\'s refund is part of its amount', 'Case #' . $first . ' on this order still asks for a refund of $60.00: that money is PART of this amount', $todo['note'] );

/* ================================================================== */
echo "\nAn order whose customer chose to cancel owes no fish\n";
list( $cases, $store, $rooms ) = fh_shop();
fh_loss( $rooms, 1, 9001, 2 ); // Three alive, four owed.
$case = $store->cases()[0];
check_same( 'one case, on 502 (two gobies on its line)', array( 1, 502, 1 ), array( count( $store->cases() ), $case['order_id'], $case['qty'] ) );
$cases->send( $case['id'], 5 );
check_same( 'the customer picks "cancel the whole order"', 'recorded', Customer_Page::handle( fh_last_tokens()['cancel'], 'POST', true, $cases, $now )['state'] );
$assess = $cases->assess_stay( $rooms->stays[2] );
check_same( 'from that answer 502 owes nothing — before the owner has cancelled it: three alive, two owed', array( 3, 2, 0 ), array( $assess['covered'], $assess['committed'], $assess['shortage'] ) );
$mail = count( $GLOBALS['fh_mail'] );
fh_loss( $rooms, 1, 9002, 1 ); // Two alive.
check_same( 'one more goby lost: covered, no new case', array( 1, 'covered' ), array( count( $store->cases() ), $store->events()[ count( $store->events() ) - 1 ] ) );
fh_loss( $rooms, 2, 9003, 1 ); // One alive, two owed (501, 503).
$newest = $store->cases()[0];
check_same( 'the next shortage goes to the next holder (503) — never back to the order being cancelled', array( 2, 503 ), array( count( $store->cases() ), $newest['order_id'] ) );
check_same( 'the customer of 502 is not written to again', $mail, count( $GLOBALS['fh_mail'] ) );

/* ================================================================== */
echo "\nUndoing an EARLIER loss the extra had covered\n";
list( $cases, $store, $rooms ) = fh_shop();
foreach ( array( 502, 503 ) as $id ) {
	unset( $GLOBALS['fh_orders'][ $id ] );
}
$rooms->stays[1]['qty_live'] = 2;
unset( $rooms->stays[2] ); // Two alive, one owed (501).
fh_loss( $rooms, 1, 9001 );
check_same( 'the first loss is covered', array( array(), array( 'covered' ) ), array( $store->cases(), $store->events() ) );
fh_loss( $rooms, 1, 9002 );
check_same( 'the second opens a case', array( 1, 'open', ',9002:1' ), array( count( $store->cases() ), $store->get_case( 1 )['status'], $store->get_case( 1 )['loss_events'] ) );
fh_undo( $rooms, 1, 9001 ); // One alive again, one owed.
$case = $store->get_case( 1 );
check_same( 'undoing the FIRST loss: the tanks cover the fish again, so the unsent case closes by itself', array( 'closed', 'loss_undone' ), array( $case['status'], $case['close_reason'] ) );
check_contains( '… with a note on the order', 'the tanks cover it again — the case closed by itself', implode( "\n", fh_notes( 501 ) ) );
check_same( '… Send has nothing to send', array( false, array() ), array( $cases->send( 1, 5 )['ok'], $GLOBALS['fh_mail'] ) );
check_same( '… and nothing needs the owner', 0, Store::needs_you_count() );

list( $cases, $store, $rooms ) = fh_shop();
foreach ( array( 501, 503 ) as $id ) {
	unset( $GLOBALS['fh_orders'][ $id ] );
}
$rooms->stays[1]['qty_live'] = 3;
unset( $rooms->stays[2] ); // Three alive, two owed (502's line of two).
fh_loss( $rooms, 1, 9001, 1 );
fh_loss( $rooms, 1, 9002, 2 );
check_same( 'a loss of one is covered, a loss of two more opens a case for two', array( 1, 2, ',9002:2' ), array( count( $store->cases() ), $store->get_case( 1 )['qty'], $store->get_case( 1 )['loss_events'] ) );
fh_undo( $rooms, 1, 9001, 1 );
check_same( 'undoing the first: one alive for two owed — the case speaks for one fish less', array( 'open', 1, ',9002:1' ), array( $store->get_case( 1 )['status'], $store->get_case( 1 )['qty'], $store->get_case( 1 )['loss_events'] ) );
check_contains( '… and the order note says so', 'this case now speaks for 1 × Lagoon Goby', implode( "\n", fh_notes( 502 ) ) );

list( $cases, $store, $rooms ) = fh_shop();
foreach ( array( 502, 503 ) as $id ) {
	unset( $GLOBALS['fh_orders'][ $id ] );
}
$rooms->stays[1]['qty_live'] = 2;
unset( $rooms->stays[2] );
fh_loss( $rooms, 1, 9001 );
fh_loss( $rooms, 1, 9002 );
$cases->send( 1, 5 );
fh_undo( $rooms, 1, 9001 );
check_same( 'a case that was already SENT is never closed behind the customer\'s back', 'sent', $store->get_case( 1 )['status'] );

list( $cases, $store, $rooms ) = fh_shop();
foreach ( array( 502, 503 ) as $id ) {
	unset( $GLOBALS['fh_orders'][ $id ] );
}
$rooms->stays[1]['qty_live'] = 2;
unset( $rooms->stays[2] );
fh_loss( $rooms, 1, 9001 );
$cases->open_by_hand( 501, 'i:51', 1, 5 );
fh_undo( $rooms, 1, 9001 );
check_same( '… nor is a case the owner opened by hand', 'open', $store->get_case( 1 )['status'] );

/* ================================================================== */
echo "\nA paid holder behind hundreds of newer unpaid orders\n";
list( $cases, $store, $rooms ) = fh_shop();
foreach ( array( 502, 503 ) as $id ) {
	unset( $GLOBALS['fh_orders'][ $id ] );
}
$rooms->stays[1]['qty_live'] = 1;
unset( $rooms->stays[2] ); // One alive, one owed (501).
for ( $id = 1001; $id <= 1401; $id++ ) {
	fh_order( $id, array( 'paid' => null, 'status' => 'pending', 'lines' => array( $id * 10 => array( 'name' => 'Harbor Tang', 'product_id' => 813, 'qty' => 1, 'total' => 230.00 ) ) ) );
}
$store->prefilter = array( 501 ); // What the order-item tables answer: only 501 holds the goby.
fh_loss( $rooms, 1, 9001 );
check_same( 'every open order is read, a page at a time', array( 1, 2 ), array( $GLOBALS['fh_wc_queries'][0]['page'], $GLOBALS['fh_wc_queries'][1]['page'] ) );
check_same( '… and all 402 go to the product prefilter', 402, count( $store->prefilter_calls[0][0] ) );
check_same( 'the paid holder gets its case — 401 newer unpaid orders do not hide it', array( 1, 501 ), array( count( $store->cases() ), $store->cases()[0]['order_id'] ) );
check_same( '… and only that order was loaded to be judged', array( 501 ), array_values( array_unique( array_intersect( $GLOBALS['fh_wc_loads'], array_merge( array( 501 ), range( 1001, 1401 ) ) ) ) ) );
check_lacks( 'nothing was left unread, so the log does not say so', 'capped', implode( ' ', $store->events() ) );

list( $cases, $store, $rooms ) = fh_shop();
foreach ( array( 502, 503 ) as $id ) {
	unset( $GLOBALS['fh_orders'][ $id ] );
}
$rooms->stays[1]['qty_live'] = 1;
unset( $rooms->stays[2] );
for ( $id = 1001; $id <= 1401; $id++ ) {
	fh_order( $id, array( 'paid' => null, 'status' => 'pending', 'lines' => array( $id * 10 => array( 'name' => 'Harbor Tang', 'product_id' => 813, 'qty' => 1, 'total' => 230.00 ) ) ) );
}
$store->prefilter = null; // The tables cannot say: the orders have to be loaded.
fh_loss( $rooms, 1, 9001 );
check_same( 'when the tables cannot say, at most ' . Orders::OPEN_LIMIT . ' orders are loaded', Orders::OPEN_LIMIT, count( array_unique( $GLOBALS['fh_wc_loads'] ) ) );
check_same( '… and the log says plainly that the oldest were NOT checked', array( 'capped' ), $store->events() );
check_contains( '…', 'the oldest were NOT checked', $store->log[0]['message'] );
check_contains( '… which fish, and what to do', '1 × Lagoon Goby (Medium) (tank 521)', $store->log[0]['message'] );

/* ================================================================== */
echo "\nA ship date that has gone by\n";
fh_reset();
Lost_Fish::$today = '2026-11-12';
$confirm          = 'The rest of your order is not affected. We will confirm your ship date with you.';
check_same( 'a delivery date years ago: "we will confirm your ship date" — never "it ships on Monday, January 6, 2020"', $confirm, Email::ship_line( array( 'ship_date' => '2020-01-06', 'delivery_date' => '2020-01-07' ) ) );
check_same( 'shipped yesterday, due today: the same', $confirm, Email::ship_line( array( 'ship_date' => '2026-11-11', 'delivery_date' => '2026-11-12' ) ) );
check_same( 'only a delivery date, and it is past: the same', $confirm, Email::ship_line( array( 'ship_date' => '', 'delivery_date' => '2026-11-11' ) ) );
check_same( 'the {ship_date} and {delivery_date} of a template of one\'s own are empty then, not a day that is over', array( '', '' ), array( Email::values( array(), array( 'ship_date' => '2020-01-06', 'delivery_date' => '2020-01-07' ) )['ship_date'], Email::values( array(), array( 'ship_date' => '2020-01-06', 'delivery_date' => '2020-01-07' ) )['delivery_date'] ) );
check_contains( 'ships today: still a date', 'it ships on Thursday, November 12, 2026, for delivery on Friday, November 13, 2026', Email::ship_line( array( 'ship_date' => '2026-11-12', 'delivery_date' => '2026-11-13' ) ) );

list( $cases, $store, $rooms ) = fh_shop();
Lost_Fish::$today              = '2031-03-01';
$case                          = $cases->open_by_hand( 501, 'i:51', 1, 5 )['case']['id'];
$cases->send( $case, 5 );
check_contains( 'the e-mail of a case whose order ships in ten days names the day', 'it ships on Tuesday, March 11, 2031', $GLOBALS['fh_mail'][0]['message'] );
Lost_Fish::$today = '2031-03-14'; // The reminder goes out after the box has left.
$cases->remind( $store->get_case( $case ), $now );
check_same( 'the reminder went out', 2, count( $GLOBALS['fh_mail'] ) );
check_lacks( '… and does not say the order "ships on" a day that is over', 'it ships on', $GLOBALS['fh_mail'][1]['message'] );
check_contains( '…', 'We will confirm your ship date with you', $GLOBALS['fh_mail'][1]['message'] );
Lost_Fish::$today = null;

/* ================================================================== */
echo "\nThe customer's Confirm reads the order again\n";
list( $cases, $store, $rooms ) = fh_shop();
$case                          = $cases->open_by_hand( 501, 'i:51', 1, 5 )['case']['id'];
$cases->send( $case, 5 );
$tokens                             = fh_last_tokens();
$GLOBALS['fh_orders'][501]->status = 'completed'; // The box left since the e-mail.
$notes                              = count( fh_notes( 501 ) );
check_same( 'the order is completed: a link says the request is closed', 'closed', Customer_Page::handle( $tokens['refund'], 'GET', false, $cases, $now )['state'] );
check_same( '… opening it changed nothing', array( 'sent', 0 ), array( $store->get_case( $case )['status'], $store->get_case( $case )['needs_check'] ) );
$view = Customer_Page::handle( $tokens['cancel'], 'POST', true, $cases, $now );
check_same( 'a Confirm on "cancel": closed, 200', array( 'closed', 200 ), array( $view['state'], $view['http'] ) );
check_contains( '…', 'This request is closed', Customer_Page::render( $view ) );
check_same( 'NOTHING was recorded: the case still waits, no choice, no order note', array( 'sent', '', $notes ), array( $store->get_case( $case )['status'], $store->get_case( $case )['choice'], count( fh_notes( 501 ) ) ) );
check_same( 'the owner is told: the case asks for a look', 1, $store->get_case( $case )['needs_check'] );
check_contains( '…', 'The customer tried to answer, but the order is completed by now — nothing was recorded', $store->get_case( $case )['check_note'] );
check_same( '… and the log has it', 'answer_refused', $store->events()[ count( $store->events() ) - 1 ] );
check_same( 'the owner can still record what the customer says on the phone', true, $cases->answer( $case, 'refund', 'owner', 5 )['ok'] );

list( $cases, $store, $rooms ) = fh_shop();
$case                          = $cases->open_by_hand( 502, 'i:53', 1, 5 )['case']['id'];
$cases->send( $case, 5 );
$tokens                             = fh_last_tokens();
$GLOBALS['fh_orders'][502]->status = 'refunded';
check_same( 'the order was refunded in full: a Confirm on "refund" records nothing', array( 'closed', 'sent' ), array( Customer_Page::handle( $tokens['refund'], 'POST', true, $cases, $now )['state'], $store->get_case( $case )['status'] ) );
unset( $GLOBALS['fh_orders'][502] );
check_same( 'the order no longer exists: closed as well', array( 'closed', 'sent' ), array( Customer_Page::handle( $tokens['credit'], 'POST', true, $cases, $now )['state'], $store->get_case( $case )['status'] ) );

list( $cases, $store, $rooms ) = fh_shop();
$case                          = $cases->open_by_hand( 501, 'i:51', 1, 5 )['case']['id'];
$cases->send( $case, 5 );
$tokens                                                              = fh_last_tokens();
$GLOBALS['fh_orders'][501]->items[52]->meta['_fishotel_line_status'] = 'shipped'; // The tangs left since the e-mail.
$view                                                                = Customer_Page::handle( $tokens['cancel'], 'POST', true, $cases, $now );
check_same( 'part of the order shipped: "cancel the whole order" records nothing', array( 'shipped', 200, 'sent', '' ), array( $view['state'], $view['http'], $store->get_case( $case )['status'], $store->get_case( $case )['choice'] ) );
check_same( '… its page lists the three choices that are left, each with its link', array( 'refund', 'credit', 'retry' ), array_column( $view['others'], 'choice' ) );
$html = Customer_Page::render( $view );
check_contains( '… and says why', 'Part of this order has shipped since we wrote to you, so the whole order can no longer be cancelled', $html );
check_lacks( '… without a Confirm button', '<form', $html );
check_contains( '…', 'fh_lost_fish=' . $tokens['refund'], $html );
check_same( 'the other links no longer offer "cancel"', array( 'credit', 'retry' ), array_column( Customer_Page::handle( $tokens['refund'], 'GET', false, $cases, $now )['others'], 'choice' ) );
check_same( 'the owner cannot record "cancel" by hand either', false, $cases->answer( $case, 'cancel', 'owner', 5 )['ok'] );
check_same( 'a Confirm on "refund" still works', array( 'recorded', 'answered', 'refund' ), array( Customer_Page::handle( $tokens['refund'], 'POST', true, $cases, $now )['state'], $store->get_case( $case )['status'], $store->get_case( $case )['choice'] ) );

/* ================================================================== */
echo "\nTwo cases on one order: the shipping difference is withheld once\n";
list( $cases, $store, $rooms ) = fh_shop();
$goby = $cases->open_by_hand( 501, 'i:51', 1, 5 )['case']['id']; // 60.00.
$tang = $cases->open_by_hand( 501, 'i:52', 1, 5 )['case']['id']; // 230.00.
$cases->set_numbers( $goby, array( 'ship_diff' => '25.00' ), 5 );
$cases->set_numbers( $tang, array( 'ship_diff' => '25.00' ), 5 ); // "For this fish alone it would be 25.00" — typed on both.
check_same( 'the first goes out', true, $cases->send( $goby, 5 )['ok'] );
$goby_tokens = fh_last_tokens();
$nums        = $cases->numbers( $store->get_case( $tang ), fh_facts( $cases, 501 ) );
check_same( 'the second cannot, while the first is with the customer', array( false, true ), array( $nums['ready'], isset( $nums['blockers']['sibling'] ) ) );
check_contains( '… and it says which case it waits for', 'Case #' . $goby . ' on this order is with the customer', $nums['blockers']['sibling'] );
$sent = $cases->send( $tang, 5 );
check_same( '… the server refuses it too: one e-mail so far', array( false, 1 ), array( $sent['ok'], count( $GLOBALS['fh_mail'] ) ) );
check_same( '… nor can its answer be recorded by hand on those numbers', false, $cases->answer( $tang, 'refund', 'owner', 5 )['ok'] );
Customer_Page::handle( $goby_tokens['refund'], 'POST', true, $cases, $now );
check_same( 'the first is answered: refund 35.00, 25.00 withheld', array( 'answered', 3500 ), array( $store->get_case( $goby )['status'], $store->get_case( $goby )['amount_refund'] ) );
$second = $store->get_case( $tang );
check_same( 'the figure typed on the second is dropped and worked out on what is left: nothing more to withhold', array( 0, Shipping::NONE, 0, 1 ), array( $second['ship_manual'], $second['ship_status'], $second['ship_diff'], $second['needs_check'] ) );
check_contains( '… and the case says so', 'Case #' . $goby . ' on this order was answered after a shipping difference was typed in here', $second['check_note'] );
check_same( 'now it goes out, with the whole 230.00', array( true, 23000 ), array( $cases->send( $tang, 5 )['ok'], $store->get_case( $tang )['amount_refund'] ) );
Customer_Page::handle( fh_last_tokens()['refund'], 'POST', true, $cases, $now );
check_same( 'both refunded: 25.00 withheld in all — the real difference, once', 2500, ( 6000 + 23000 ) - ( $store->get_case( $goby )['amount_refund'] + $store->get_case( $tang )['amount_refund'] ) );

list( $cases, $store, $rooms ) = fh_shop();
$goby = $cases->open_by_hand( 501, 'i:51', 1, 5 )['case']['id'];
$tang = $cases->open_by_hand( 501, 'i:52', 1, 5 )['case']['id'];
$cases->set_numbers( $goby, array( 'ship_diff' => '25.00' ), 5 );
$cases->send( $goby, 5 );
$nums = $cases->numbers( $cases->refresh( $store->get_case( $tang ) ), fh_facts( $cases, 501 ) );
check_same( 'a second case whose figure was NOT typed yet is not asked for one while the first is with the customer — it is told to wait', array( 'sibling' ), array_keys( $nums['blockers'] ) );

list( $cases, $store, $rooms ) = fh_shop();
$goby = $cases->open_by_hand( 502, 'i:53', 1, 5 )['case']['id']; // 810.00 less 60.00 and less 230.00 is still over the threshold.
$tang = $cases->open_by_hand( 502, 'i:54', 1, 5 )['case']['id'];
$a    = $cases->refresh( $store->get_case( $goby ) );
$b    = $cases->refresh( $store->get_case( $tang ) );
check_same( 'two cases on an order that stays over the threshold either way: no difference, nothing to type', array( Shipping::NONE, 0, Shipping::NONE, 0 ), array( $a['ship_status'], $a['ship_diff'], $b['ship_status'], $b['ship_diff'] ) );
check_same( '… both go out', array( true, true ), array( $cases->send( $goby, 5 )['ok'], $cases->send( $tang, 5 )['ok'] ) );

/* ================================================================== */
echo "\nReopening a case that was answered\n";
list( $cases, $store, $rooms ) = fh_shop();
$case                          = $cases->open_by_hand( 503, 'i:55', 1, 5 )['case']['id'];
$cases->send( $case, 5 );
Customer_Page::handle( fh_last_tokens()['refund'], 'POST', true, $cases, $now );
$GLOBALS['fh_orders'][503]->refunded_qty[55] = 1; // The owner refunded the line in WooCommerce…
$GLOBALS['fh_orders'][503]->refunded         = 60.00;
$cases->done( $case, 'Refunded through the card.', 5 ); // … and ticked Done.
$mail   = count( $GLOBALS['fh_mail'] );
$opened = $cases->reopen( $case, 5 )['case'];
check_same( 'reopened: open, and it asks for a look', array( 'open', 1 ), array( $opened['status'], $opened['needs_check'] ) );
check_contains( '… saying what the customer had chosen, for how much, and that it was ticked Done', 'Reopened after an answer: the customer had chosen to refund the lost fish — $60.00, and it was ticked Done. Make sure that was not already paid out', $opened['check_note'] );
$nums = $cases->numbers( $opened, fh_facts( $cases, 503 ) );
check_same( 'the line was refunded: Send is blocked', array( false, true ), array( $nums['ready'], isset( $nums['blockers']['refunded'] ) ) );
check_contains( '…', 'it looks as if this fish was already refunded', $nums['blockers']['refunded'] );
check_same( '… and the server refuses: no second e-mail offering the same 60.00', array( false, $mail ), array( $cases->send( $case, 5 )['ok'], count( $GLOBALS['fh_mail'] ) ) );

list( $cases, $store, $rooms ) = fh_shop();
$case                          = $cases->open_by_hand( 503, 'i:55', 1, 5 )['case']['id'];
$cases->send( $case, 5 );
$opened = $cases->reopen( $case, 5 )['case'];
check_same( 'reopening a case that had no answer yet flags nothing', array( 0, '' ), array( $opened['needs_check'], $opened['check_note'] ) );
check_same( '… and it can be sent again', true, $cases->send( $case, 5 )['ok'] );

/* ================================================================== */
echo "\nA page that is behind its case\n";
list( $cases, $store, $rooms ) = fh_shop();
fh_loss( $rooms, 1, 9001, 2 ); // One case, on 502 — the refund is 60.00.
$tab_a = fh_seen( $cases, 1 ); // Tab A shows blake@ and 60.00.
$reply = Admin::case_request( $cases, fh_post( $cases, 1, 'reassign', array( 'holder' => '503|i:55' ) ) );
check_same( 'tab B gives the case to order 503 (casey@) — whose refund is 60.00 as well', array( 200, 503, 'casey@example.test' ), array( $reply['status'], $store->get_case( 1 )['order_id'], $store->get_case( 1 )['customer_email'] ) );
$reply = Admin::case_request( $cases, fh_post( $cases, 1, 'send', array( 'shown_refund' => '6000', 'seen' => $tab_a ) ) );
check_same( 'tab A\'s Send — its dialog named blake@ — sends NOTHING', array( 400, false, array(), 'open' ), array( $reply['status'], $reply['ok'], $GLOBALS['fh_mail'], $store->get_case( 1 )['status'] ) );
check_contains( '… and says the case changed', 'This case changed since the page was loaded', $reply['message'] );

Admin::case_request( $cases, fh_post( $cases, 1, 'numbers', array( 'unit_paid' => '55.00', 'ship_diff' => '10' ) ) );
check_same( 'tab B saves 55.00 and a difference of 10.00', array( 5500, 1000, 1 ), array( $store->get_case( 1 )['unit_paid'], $store->get_case( 1 )['ship_diff'], $store->get_case( 1 )['ship_manual'] ) );
$reply = Admin::case_request( $cases, fh_post( $cases, 1, 'numbers', array( 'unit_paid' => '60.00', 'ship_diff' => '', 'seen' => $tab_a ) ) );
check_same( 'tab A\'s "Save numbers" does not write its old 60.00 back', array( 400, 5500, 1000, 1 ), array( $reply['status'], $store->get_case( 1 )['unit_paid'], $store->get_case( 1 )['ship_diff'], $store->get_case( 1 )['ship_manual'] ) );
$reply = Admin::case_request( $cases, fh_post( $cases, 1, 'answer', array( 'choice' => 'refund', 'seen' => $tab_a ) ) );
check_same( '… nor does its "Record the answer" freeze numbers it never showed', array( 400, 'open' ), array( $reply['status'], $store->get_case( 1 )['status'] ) );
$reply = Admin::case_request( $cases, array_diff_key( fh_post( $cases, 1, 'send' ), array( 'seen' => 1 ) ) );
check_same( 'a form without the field (a page from before this) is behind too', array( 400, array() ), array( $reply['status'], $GLOBALS['fh_mail'] ) );
$reply = Admin::case_request( $cases, fh_post( $cases, 1, 'send', array( 'shown_refund' => '4500' ) ) );
check_same( 'the page as it is now sends: one e-mail, to casey@', array( 200, 1, 'casey@example.test' ), array( $reply['status'], count( $GLOBALS['fh_mail'] ), $GLOBALS['fh_mail'][0]['to'] ) );
$reply = Admin::case_request( $cases, fh_post( $cases, 1, 'close', array( 'reason' => 'mistake', 'seen' => $tab_a ) ) );
check_same( 'closing a case needs no fresh page — it depends on nothing the page showed', array( 200, 'closed' ), array( $reply['status'], $store->get_case( 1 )['status'] ) );

list( $cases, $store, $rooms ) = fh_shop();
fh_loss( $rooms, 1, 9001, 2 ); // One case, on 502, for one goby.
$tab_a = fh_seen( $cases, 1 );
fh_loss( $rooms, 2, 9002, 1 ); // One more of the same fish joins it.
check_same( 'a further loss joined the case while the page was open: 2 × now', array( 1, 2 ), array( count( $store->cases() ), $store->get_case( 1 )['qty'] ) );
$reply = Admin::case_request( $cases, fh_post( $cases, 1, 'send', array( 'seen' => $tab_a ) ) );
check_same( '… the page that still shows 1 × sends nothing', array( 400, 'open', array() ), array( $reply['status'], $store->get_case( 1 )['status'], $GLOBALS['fh_mail'] ) );

/* ================================================================== */
echo "\nA case on an order that was never paid\n";
list( $cases, $store, $rooms ) = fh_shop();
fh_order( 504, array( 'paid' => null, 'status' => 'pending', 'shipping' => 40.00, 'email' => 'ellis@example.test', 'lines' => array( 56 => array( 'name' => 'Lagoon Goby', 'product_id' => 812, 'qty' => 1, 'total' => 60.00 ) ) ) );
$opened = $cases->open_by_hand( 504, 'i:56', 1, 5 );
check_same( 'it can be opened by hand (the owner may know more than the order says)', true, $opened['ok'] );
$nums = $cases->numbers( $cases->refresh( $store->get_case( 1 ) ), fh_facts( $cases, 504 ) );
check_same( '… but not sent: nothing was paid, so there is nothing to refund', array( false, true ), array( $nums['ready'], isset( $nums['blockers']['unpaid'] ) ) );
check_same( '… the server refuses, no e-mail', array( false, array() ), array( $cases->send( 1, 5 )['ok'], $GLOBALS['fh_mail'] ) );
check_same( '… and no answer is recorded by hand on it', false, $cases->answer( 1, 'refund', 'owner', 5 )['ok'] );
$GLOBALS['fh_orders'][504]->date_paid = 4000;
$GLOBALS['fh_orders'][504]->status    = 'processing';
check_same( 'once the order is paid, it goes out', true, $cases->send( 1, 5 )['ok'] );

fh_finish();
