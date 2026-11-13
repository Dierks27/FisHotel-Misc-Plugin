<?php
/**
 * Cases tests — the life of a case on the real Cases class, an in-memory
 * store, stand-in orders and tank groups, WITHOUT the Requests section:
 * a loss the extra covers (a log line, nothing else), a loss that leaves
 * somebody short (who is suggested), what never opens a case, the undo
 * that closes an unsent case, NOTHING SENT WITHOUT THE CLICK, the send
 * (once, on the numbers the owner saw, blocked while the shipping
 * difference is not known), the answer (once), what the owner then has to
 * do, done / reopen / close, the reminder, "send by itself", a case by
 * hand, giving a case to another holder — and the order note at each
 * stage.
 *
 * Run from the plugin root:
 *   php includes/sections/lost-fish/tests/cases-test.php
 *
 * @package FisHotel\Misc\Sections\Lost_Fish
 */

require_once __DIR__ . '/wp-stubs.php';
require_once __DIR__ . '/fakes.php';

use FisHotel\Misc\Sections\Lost_Fish\Cases;
use FisHotel\Misc\Sections\Lost_Fish\Lost_Fish;
use FisHotel\Misc\Sections\Lost_Fish\Settings;
use FisHotel\Misc\Sections\Lost_Fish\Shipping;
use FisHotel\Misc\Sections\Lost_Fish\Store;
use FisHotel\Misc\Sections\Lost_Fish\Token;

/**
 * A small shop: five Lagoon Gobies alive in two tank groups, four owed
 * on three paid orders.
 *
 *   501  paid first   1 goby (60.00) + 2 tangs (460.00) = 520.00, shipping 15.00 — one goby less: 460.00
 *   502  paid last    2 gobies (120.00) + 3 tangs (690.00) = 810.00, shipping 15.00 — stays over
 *   503  paid second  1 goby (60.00), shipping 40.00
 *
 * @return array{0: Cases, 1: Fh_Fake_Store, 2: Fh_Fake_Rooms}
 */
function fh_world() {
	fh_reset();
	$GLOBALS['fh_options'][ Store::DB_VERSION_OPTION ] = Store::DB_VERSION;
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

add_action( 'fishotel_cleanroom_event_recorded', array( Cases::class, 'on_cleanroom_event' ), 10, 4 );

/* ================================================================== */
echo "A loss the extra covers\n";
list( $cases, $store, $rooms ) = fh_world();
fh_loss( $rooms, 1, 9001 );
check_same( 'four alive for four owed: no case', array(), $store->cases() );
check_same( 'one line in the section\'s log', array( 'covered' ), $store->events() );
check_contains( '… saying the fish was replaced from the extra', 'replaced from the extra', $store->log[0]['message'] );
check_contains( '… which fish, where, and the count', '1 × Lagoon Goby (Medium) (tank 521): 4 still alive for 4 owed', $store->log[0]['message'] );
check_same( 'no e-mail', array(), $GLOBALS['fh_mail'] );
check_same( 'no note on any order', array( array(), array(), array() ), array( fh_notes( 501 ), fh_notes( 502 ), fh_notes( 503 ) ) );

/* ================================================================== */
echo "\nA loss that leaves somebody short\n";
fh_loss( $rooms, 1, 9002 );
$open = $store->cases();
check_same( 'three alive for four owed: one case', 1, count( $open ) );
$case = $open[0];
check_same( 'on the most recently PAID order — 502, not the oldest (501) nor the newest id (503)', array( 502, 53, 'i:53', 'product' ), array( $case['order_id'], $case['order_item_id'], $case['line_ref'], $case['line_match'] ) );
check_same( 'it names the fish, the customer and what was paid for one', array( 'Lagoon Goby', 1, 'Blake Sample', 'blake@example.test', 42, 6000, 1 ), array( $case['fish_name'], $case['qty'], $case['customer_name'], $case['customer_email'], $case['customer_id'], $case['unit_paid'], $case['paid_known'] ) );
check_same( 'it remembers the tank group and the loss event it came from', array( 1, 9002, 'auto', 'p:812', 7 ), array( $case['stay_id'], $case['loss_event_id'], $case['origin'], $case['kind_key'], $case['created_by'] ) );
check_same( '… that loss with its number of fish, and when it was', array( ',9002:1', true ), array( $case['loss_events'], null !== $case['loss_at'] ) );
check_same( 'open, nothing sent', array( 'open', null, '' ), array( $case['status'], $case['sent_at'], $case['token_salt'] ) );
check_same( 'the shipping difference is already worked out: 810.00 less 60.00 stays over the threshold', array( Shipping::NONE, 0, 0, 1500 ), array( $case['ship_status'], $case['ship_diff'], $case['ship_manual'], $case['ship_charged'] ) );
check_same( 'NO E-MAIL', array(), $GLOBALS['fh_mail'] );
check_same( 'a private note on that order, and only that order', array( 1, 0, 0 ), array( count( fh_notes( 502 ) ), count( fh_notes( 501 ) ), count( fh_notes( 503 ) ) ) );
check_contains( '… "case opened", naming the fish', 'Lost fish case #1 — Opened: 1 × Lagoon Goby was lost and there is no spare', fh_notes( 502 )[0] );
check_same( '… never a note to the customer', 0, $GLOBALS['fh_orders'][502]->notes[0]['customer'] );
check_same( 'the log: covered, then opened', array( 'covered', 'opened' ), $store->events() );
check_same( 'other sections are told', 1, count( fh_fired( 'fishotel_lostfish_case_opened' ) ) );
check_same( 'the menu count: one case needs the owner', 1, Store::needs_you_count() );
check_same( 'nothing is scheduled to go out by itself — the setting is off', array(), $GLOBALS['fh_cron'] );
check_same( 'the Health log hears about it too', 2, count( fh_fired( 'fishotel_log' ) ) );
check_same( 'each loss was worked out under the lock of its kind of fish, and gave it back', array( 'lock p:812', 'unlock p:812', 'lock p:812', 'unlock p:812' ), $store->locks );

echo "\nMore losses\n";
fh_loss( $rooms, 1, 9003 );
check_same( 'two alive for four owed, and the case of that line was not sent yet: it GROWS to both gobies — one case, one e-mail to come', array( array( 1, 502, 'i:53', 2, 'open' ) ), array_map( static function ( $c ) { return array( $c['id'], $c['order_id'], $c['line_ref'], $c['qty'], $c['status'] ); }, $store->cases() ) );
check_same( '… it remembers both losses, each with its fish', array( ',9002:1,9003:1', array( 9002 => 1, 9003 => 1 ), 9002 ), array( $store->get_case( 1 )['loss_events'], Cases::loss_events( $store->get_case( 1 )['loss_events'] ), $store->get_case( 1 )['loss_event_id'] ) );
check_contains( '… the order is told', 'Lost fish case #1 — 1 more lost with no spare in the tanks: this case now speaks for 2 × Lagoon Goby. Nothing was sent', fh_notes( 502 )[1] );
check_same( '… the log says so, and other sections are told how many more', array( 'grown', 1, 1 ), array( $store->events()[ count( $store->events() ) - 1 ], count( fh_fired( 'fishotel_lostfish_case_grown' ) ), fh_fired( 'fishotel_lostfish_case_grown' )[0][1] ) );
check_same( '… not as a second "opened"', 1, count( fh_fired( 'fishotel_lostfish_case_opened' ) ) );
fh_loss( $rooms, 2, 9004, 2 );
$latest = $store->cases();
check_same( 'two more lost at once (none alive): the next two holders, by paid date — 503, then 501', array( array( 501, 9004, ',9004:1' ), array( 503, 9004, ',9004:1' ) ), array( array( $latest[0]['order_id'], $latest[0]['loss_event_id'], $latest[0]['loss_events'] ), array( $latest[1]['order_id'], $latest[1]['loss_event_id'], $latest[1]['loss_events'] ) ) );
check_same( 'three cases — one per order line — for four fish owed and none alive', array( 3, 4 ), array( count( $latest ), array_sum( array_column( $latest, 'qty' ) ) ) );
fh_loss( $rooms, 1, 9005, 0 );
check_same( 'everybody owed already has a case: nothing new', array( 3, 4 ), array( count( $store->cases() ), array_sum( array_column( $store->cases(), 'qty' ) ) ) );
check_same( 'STILL NO E-MAIL after five losses', array(), $GLOBALS['fh_mail'] );

echo "\nThe shipping difference of each\n";
$by_order = array();
foreach ( $store->cases() as $c ) {
	$by_order[ $c['order_id'] ][] = $cases->refresh( $c );
}
check_same( '501 alone would drop under the threshold: 25.00', array( Shipping::DROP, 2500 ), array( $by_order[501][0]['ship_status'], $by_order[501][0]['ship_diff'] ) );
check_same( '503 was under it already: none', array( Shipping::NONE, 0 ), array( $by_order[503][0]['ship_status'], $by_order[503][0]['ship_diff'] ) );
check_same( '502 has ONE case for both its gobies: 810.00 less 120.00 stays over the threshold — worked out, nothing for the owner to type', array( Shipping::NONE, 0, 0 ), array( $by_order[502][0]['ship_status'], $by_order[502][0]['ship_diff'], $by_order[502][0]['ship_manual'] ) );
check_same( '… and the money of that case is for two fish', 12000, $cases->numbers( $by_order[502][0], $cases->orders()->facts( $GLOBALS['fh_orders'][502] ) )['refund'] );

echo "\nWhat a further loss does NOT join\n";
list( $cases, $store, $rooms ) = fh_world();
fh_loss( $rooms, 1, 9001 );
fh_loss( $rooms, 1, 9002 );
$cases->set_numbers( 1, array( 'ship_diff' => '5.00' ), 5 );
fh_loss( $rooms, 1, 9003 );
$case = $store->get_case( 1 );
check_same( 'a shipping difference typed in for one fish does not stand for two: worked out again, and the case asks for a look', array( 2, 0, Shipping::NONE, 0, 1 ), array( $case['qty'], $case['ship_manual'], $case['ship_status'], $case['ship_diff'], $case['needs_check'] ) );
check_contains( '… saying why', 'The number of fish changed after a shipping difference was typed in', $case['check_note'] );

list( $cases, $store, $rooms ) = fh_world();
fh_loss( $rooms, 1, 9001 );
fh_loss( $rooms, 1, 9002 );
check( 'the first case is sent', $cases->send( 1, 5 )['ok'] );
fh_loss( $rooms, 1, 9003 );
check_same( 'a case that was SENT is never changed behind the customer\'s back: the next loss opens a new one', array( array( 2, 'open', 1, ',9003:1' ), array( 1, 'sent', 1, ',9002:1' ) ), array_map( static function ( $c ) { return array( $c['id'], $c['status'], $c['qty'], $c['loss_events'] ); }, $store->cases() ) );
check_same( '… one e-mail so far, for one fish', 1, count( $GLOBALS['fh_mail'] ) );

list( $cases, $store, $rooms ) = fh_world();
fh_loss( $rooms, 1, 9001 );
fh_loss( $rooms, 1, 9002 );
$cases->send( 1, 5 );
$cases->reopen( 1, 5 );
fh_loss( $rooms, 1, 9003 );
check_same( 'a case the owner REOPENED is the owner\'s: the next loss opens a new one', array( array( 2, 'open', 1 ), array( 1, 'open', 1 ) ), array_map( static function ( $c ) { return array( $c['id'], $c['status'], $c['qty'] ); }, $store->cases() ) );

list( $cases, $store, $rooms ) = fh_world();
$rooms->stays[1]['qty_live'] = 2; // Four alive for four owed.
$cases->open_by_hand( 502, 'i:53', 1, 5 );
fh_loss( $rooms, 1, 9002 );
check_same( 'a case made by hand already speaks for the first fish short: nothing new', 1, count( $store->cases() ) );
fh_loss( $rooms, 1, 9003 );
check_same( 'a case made BY HAND is not grown by the next one either', array( array( 2, 'auto', 1 ), array( 1, 'manual', 1 ) ), array_map( static function ( $c ) { return array( $c['id'], $c['origin'], $c['qty'] ); }, $store->cases() ) );

list( $cases, $store, $rooms ) = fh_world();
fh_loss( $rooms, 1, 9001 );
fh_loss( $rooms, 1, 9002 );
$store->rows[1]['loss_events'] = ',' . implode( ':1,', range( 1000001, 1000025 ) ) . ':1'; // 250 of the column's 255 characters.
fh_loss( $rooms, 1, 9003 );
check_same( 'a case whose list of losses is full: a new case rather than a loss nobody could undo', array( 2, ',9003:1' ), array( count( $store->cases() ), $store->get_case( 2 )['loss_events'] ) );
check_same( 'what is no list of losses reads as none', array( array(), array(), array( 7 => 2 ) ), array( Cases::loss_events( '' ), Cases::loss_events( ',x:1,3:0,-4:1,5' ), Cases::loss_events( ',7:2' ) ) );

/* ================================================================== */
echo "\nWhat never opens a case\n";
list( $cases, $store, $rooms ) = fh_world();
$rooms->stays[1]['qty_live'] = 2; // Four owed, four alive: the next loss would be short.
fh_loss( $rooms, 1, 9100, 1, 'import' );
check_same( 'a loss that came in with a workbook import is history, not news', array( array(), array() ), array( $store->cases(), $store->log ) );
$rooms->stays[1]['qty_live'] = 2;
do_action( 'fishotel_cleanroom_event_recorded', 1, 9101, array( 'type' => 'sold', 'qty' => 1, 'source' => 'qr' ), array() );
do_action( 'fishotel_cleanroom_event_recorded', 1, 9102, array( 'type' => 'count', 'qty' => 0, 'source' => 'qr' ), array() );
do_action( 'fishotel_cleanroom_event_recorded', 1, 9103, array( 'type' => 'move', 'qty' => null, 'source' => 'qr' ), array() );
check_same( 'a sale, a recount and a move are not losses', array(), $store->cases() );
do_action( 'fishotel_cleanroom_event_recorded', 1, 9104, array( 'type' => 'loss', 'qty' => -1, 'note' => 'a correction', 'source' => 'manual' ), array() );
check_same( 'a negative loss that is no undo changes nothing', array(), $store->cases() );
$rooms->add( 3, array( 'product_id' => 0, 'species' => '?? small brown fish', 'qty_live' => 4, 'needs_id' => true ) );
fh_loss( $rooms, 3, 9105 );
check_same( 'a group nobody has identified yet: nobody can be owed it', array(), $store->cases() );
fh_loss( $rooms, 99, 9106 );
check_same( 'a group that does not exist', array(), $store->cases() );
unset( $GLOBALS['fh_options'][ Store::DB_VERSION_OPTION ] );
fh_loss( $rooms, 1, 9107 );
check_same( 'the section\'s tables are not installed: nothing is tried', array( array(), array() ), array( $store->cases(), $store->log ) );
$GLOBALS['fh_options'][ Store::DB_VERSION_OPTION ] = Store::DB_VERSION;
$rooms->available                                  = false;
fh_loss( $rooms, 1, 9108 );
check_same( 'the Clean Room cannot be read: nothing is tried', array(), $store->cases() );

echo "\nTwo phones at once\n";
list( $cases, $store, $rooms ) = fh_world();
$store->fail['lock']           = true;
fh_loss( $rooms, 1, 9001 );
fh_loss( $rooms, 1, 9002 );
check_same( 'the lock cannot be taken (another request holds it past the wait, or the database has none): the loss is still worked out', 1, count( $store->cases() ) );
check_same( '… and a lock that was not taken is not given back', array( 'lock p:812', 'lock p:812' ), $store->locks );

echo "\nA listener must never break the Clean Room\n";
list( $cases, $store, $rooms ) = fh_world();
$store->fail['insert_case']    = true;
$threw                         = false;
try {
	fh_loss( $rooms, 1, 9110 );
	fh_loss( $rooms, 1, 9111 );
} catch ( \Throwable $e ) {
	$threw = true;
}
check( 'a store that cannot save a case: nothing is thrown at the loss', ! $threw && array() === $store->cases() );
Cases::set_instance(
	new Cases(
		$store,
		new class() {
			public function available() {
				throw new \RuntimeException( 'orders are broken' );
			}
		},
		$rooms
	)
);
$threw = false;
try {
	fh_loss( $rooms, 1, 9112 );
} catch ( \Throwable $e ) {
	$threw = true;
}
check( 'something inside throws: it stops here, and the Health log hears it', ! $threw && 'error' === fh_fired( 'fishotel_log' )[ count( fh_fired( 'fishotel_log' ) ) - 1 ][0] );

/* ================================================================== */
echo "\nUndoing the loss\n";
list( $cases, $store, $rooms ) = fh_world();
fh_loss( $rooms, 1, 9001 );
fh_loss( $rooms, 1, 9002 );
fh_loss( $rooms, 1, 9003 );
check_same( 'one open case for two fish, from two losses', array( array( 1, 2, ',9002:1,9003:1' ) ), array_map( static function ( $c ) { return array( $c['id'], $c['qty'], $c['loss_events'] ); }, $store->cases() ) );
fh_undo( $rooms, 1, 9003 );
$case = $store->get_case( 1 );
check_same( 'one of its two losses is undone: the case speaks for one fish again — and stays open for the other', array( 'open', 1, ',9002:1', '' ), array( $case['status'], $case['qty'], $case['loss_events'], $case['close_reason'] ) );
check_contains( 'the order is told', 'A loss of 1 was undone in the Clean Room before anything was sent — this case now speaks for 1 × Lagoon Goby', fh_notes( 502 )[2] );
check_same( 'the log says so', 'undone', $store->events()[ count( $store->events() ) - 1 ] );
check_same( 'nothing closed', array(), fh_fired( 'fishotel_lostfish_case_closed' ) );
check_same( 'no e-mail, of course', array(), $GLOBALS['fh_mail'] );
$cases->loss_undone( 9003, 7 );
check_same( 'the same undo twice takes nothing away twice', array( 3, 1 ), array( count( fh_notes( 502 ) ), $store->get_case( 1 )['qty'] ) );
check_same( 'an undo of a loss that opened no case does nothing', array( 'closed' => array(), 'shrunk' => array(), 'flagged' => array() ), $cases->loss_undone( 9001 ) );
check_same( '… nor does one whose number only looks alike (#900 is not #9002)', array( 'closed' => array(), 'shrunk' => array(), 'flagged' => array() ), $cases->loss_undone( 900 ) );
fh_undo( $rooms, 1, 9002 );
$case = $store->get_case( 1 );
check_same( 'its last loss is undone too: the case closed by itself', array( 'closed', 'loss_undone' ), array( $case['status'], $case['close_reason'] ) );
check_contains( 'the order is told', 'The loss was undone in the Clean Room before anything was sent — the case closed by itself', fh_notes( 502 )[3] );
check_same( 'other sections are told', 'loss_undone', fh_fired( 'fishotel_lostfish_case_closed' )[0][1] );
$cases->loss_undone( 9002, 7 );
check_same( 'the same undo twice closes nothing twice', 4, count( fh_notes( 502 ) ) );

fh_loss( $rooms, 1, 9005 );
$sent = $cases->send( 2, 5 );
check( 'the next loss opens a new case, and that one is sent', $sent['ok'] && 'sent' === $store->get_case( 2 )['status'] );
fh_undo( $rooms, 1, 9005 );
$late = $store->get_case( 2 );
check_same( 'its loss is undone AFTER the e-mail went out: the case is not closed behind the customer\'s back', 'sent', $late['status'] );
check_same( '… it is flagged for the owner', 1, $late['needs_check'] );
check_contains( '… with what to do', 'The loss behind this case was undone in the Clean Room AFTER the e-mail went out', $late['check_note'] );
check_same( '… and logged as a warning', 'undone_late', $store->events()[ count( $store->events() ) - 1 ] );

list( $cases, $store, $rooms ) = fh_world();
fh_loss( $rooms, 1, 9001 );
fh_loss( $rooms, 1, 9002 );
fh_loss( $rooms, 1, 9003 );
$cases->send( 1, 5 );
fh_undo( $rooms, 1, 9003 );
$late = $store->get_case( 1 );
check_same( 'a case for two fish was sent, then ONE of its losses is undone: what the customer was sent stays as it is', array( 'sent', 2, 1 ), array( $late['status'], $late['qty'], $late['needs_check'] ) );
check_contains( '… and the owner is told how many', 'A loss of 1 of the 2 fish of this case was undone in the Clean Room AFTER the e-mail went out', $late['check_note'] );

/* ================================================================== */
echo "\nNothing is sent without the click\n";
list( $cases, $store, $rooms ) = fh_world();
fh_loss( $rooms, 1, 9001 );
fh_loss( $rooms, 1, 9002 );
check_same( 'send by itself is off by default', false, Settings::get()['auto_send'] );
$cases->run_tick( time() + 3600 );
$cases->run_tick( time() + 30 * 86400 );
check_same( 'the cron tick, an hour later and a month later: no e-mail', array(), $GLOBALS['fh_mail'] );
$card = $cases->numbers( $store->get_case( 1 ), $cases->orders()->facts( $GLOBALS['fh_orders'][502] ) );
check_same( 'looking at the case, working out its numbers: no e-mail', array( true, array() ), array( $card['ready'], $GLOBALS['fh_mail'] ) );
$cases->set_numbers( 1, array( 'unit_paid' => '55.00' ), 5 );
check_same( 'saving its numbers: no e-mail', array(), $GLOBALS['fh_mail'] );
check_same( 'the case is still open', 'open', $store->get_case( 1 )['status'] );

echo "\nThe click\n";
$before = time();
$result = $cases->send( 1, 5, array( 'refund' => 5500 ) );
check( 'Send to customer', $result['ok'], $result['message'] );
check_same( 'ONE e-mail', 1, count( $GLOBALS['fh_mail'] ) );
check_same( '… to the address on the order', 'blake@example.test', $GLOBALS['fh_mail'][0]['to'] );
$case = $store->get_case( 1 );
check_same( 'the case is sent', 'sent', $case['status'] );
check_same( 'the numbers are frozen: refund 55.00, cancel the whole order 825.00 (810.00 + 15.00 shipping)', array( 5500, 82500, 1 ), array( $case['amount_refund'], $case['amount_cancel'], $case['offer_cancel'] ) );
check( 'the links got a salt and an expiry 14 days out', 1 === preg_match( '/^[a-f0-9]{32}$/', $case['token_salt'] ) && abs( strtotime( $case['link_expires'] . ' UTC' ) - ( $before + 14 * 86400 ) ) <= 2 );
check_contains( 'the order note: sent, to whom, how much, by hand', 'E-mail sent to blake@example.test with the four choices (refund or credit: $55.00). Sent by hand.', fh_notes( 502 )[1] );
check_same( '… written as the owner\'s note', true, $GLOBALS['fh_orders'][502]->notes[1]['by_user'] );
check_same( 'the log', 'sent', $store->events()[ count( $store->events() ) - 1 ] );
check_same( 'other sections are told', 1, count( fh_fired( 'fishotel_lostfish_case_sent' ) ) );
check_same( 'the menu count: nothing needs the owner while the customer has it', 0, Store::needs_you_count() );

$again = $cases->send( 1, 5 );
check_same( 'a second click sends nothing', array( false, 1 ), array( $again['ok'], count( $GLOBALS['fh_mail'] ) ) );

echo "\nThe click is bound to the numbers on the screen\n";
list( $cases, $store, $rooms ) = fh_world();
fh_loss( $rooms, 1, 9001 );
fh_loss( $rooms, 1, 9002 );
$stale = $cases->send( 1, 5, array( 'refund' => 9999 ) );
check_same( 'the page showed another refund than the one that would go out: nothing is sent', array( false, array(), 'open' ), array( $stale['ok'], $GLOBALS['fh_mail'], $store->get_case( 1 )['status'] ) );
check_contains( '… and the owner is told to look again', 'numbers changed', $stale['message'] );

/* ================================================================== */
echo "\nThe shipping difference blocks the send until it is known\n";
list( $cases, $store, $rooms ) = fh_world();
Shipping::$rater               = static function () {
	return null; // The shipping settings cannot price the order here.
};
fh_loss( $rooms, 1, 9001 );
fh_loss( $rooms, 1, 9002 );
$case = $store->get_case( 1 );
check_same( 'it cannot be worked out: not known — no figure, not zero', array( Shipping::UNKNOWN, null ), array( $case['ship_status'], $case['ship_diff'] ) );
$nums = $cases->numbers( $case, $cases->orders()->facts( $GLOBALS['fh_orders'][502] ) );
check_same( 'the case is not ready and there is no refund figure', array( false, null, array( 'shipping' ) ), array( $nums['ready'], $nums['refund'], array_keys( $nums['blockers'] ) ) );
$blocked = $cases->send( 1, 5 );
check_same( 'Send is refused', array( false, array(), 'open' ), array( $blocked['ok'], $GLOBALS['fh_mail'], $store->get_case( 1 )['status'] ) );
check_contains( '… and says what to do', 'fill it in, or confirm zero', $blocked['message'] );
$cases->run_tick( time() + 86400 );
check_same( 'the tick does not send it either', array(), $GLOBALS['fh_mail'] );

$typed = $cases->set_numbers( 1, array( 'ship_diff' => 'about thirty' ), 5 );
check_same( 'words are not an amount: nothing saved', array( false, null ), array( $typed['ok'], $store->get_case( 1 )['ship_diff'] ) );
$typed = $cases->set_numbers( 1, array( 'ship_diff' => '0' ), 5 );
$case  = $store->get_case( 1 );
check_same( 'the owner confirms zero: now it is known, by hand', array( true, 0, 1, Shipping::NONE ), array( $typed['ok'], $case['ship_diff'], $case['ship_manual'], $case['ship_status'] ) );
check_same( '… logged', 'numbers', $store->events()[ count( $store->events() ) - 1 ] );
$typed = $cases->set_numbers( 1, array( 'ship_diff' => '25.00' ), 5 );
$case  = $store->get_case( 1 );
$nums  = $cases->numbers( $case, $cases->orders()->facts( $GLOBALS['fh_orders'][502] ) );
check_same( 'or types the difference: the refund and the credit come down by it', array( 2500, Shipping::DROP, 3500, 3500, true ), array( $case['ship_diff'], $case['ship_status'], $nums['refund'], $nums['credit'], $nums['ready'] ) );
$ok = $cases->send( 1, 5, array( 'refund' => 3500 ) );
check_same( 'and Send goes through, with that amount frozen', array( true, 1, 3500 ), array( $ok['ok'], count( $GLOBALS['fh_mail'] ), $store->get_case( 1 )['amount_refund'] ) );
$late = $cases->set_numbers( 1, array( 'ship_diff' => '0' ), 5 );
check_same( 'after the send the numbers are fixed', array( false, 2500 ), array( $late['ok'], $store->get_case( 1 )['ship_diff'] ) );

list( $cases, $store, $rooms ) = fh_world();
fh_loss( $rooms, 1, 9001 );
fh_loss( $rooms, 1, 9002 );
$cases->set_numbers( 1, array( 'ship_diff' => '12.00' ), 5 );
check_same( 'a worked-out difference can be overruled by hand', array( 1200, 1 ), array( $store->get_case( 1 )['ship_diff'], $store->get_case( 1 )['ship_manual'] ) );
$cases->set_numbers( 1, array( 'ship_diff' => '' ), 5 );
check_same( '… and emptying the field has it worked out again', array( 0, 0, Shipping::NONE ), array( $store->get_case( 1 )['ship_diff'], $store->get_case( 1 )['ship_manual'], $store->get_case( 1 )['ship_status'] ) );
$same = $cases->set_numbers( 1, array( 'unit_paid' => '60.00', 'ship_diff' => '' ), 5 );
check_same( 'saving what is already there changes and logs nothing', array( true, 'Nothing changed.' ), array( $same['ok'], $same['message'] ) );

echo "\nOther things that block the send\n";
list( $cases, $store, $rooms ) = fh_world();
$GLOBALS['fh_orders'][502]->email = '';
fh_loss( $rooms, 1, 9001 );
fh_loss( $rooms, 1, 9002 );
$blocked = $cases->send( 1, 5 );
check_same( 'the order has no e-mail address', array( false, array() ), array( $blocked['ok'], $GLOBALS['fh_mail'] ) );
$GLOBALS['fh_orders'][502]->email  = 'blake@example.test';
$GLOBALS['fh_orders'][502]->status = 'cancelled';
$blocked                           = $cases->send( 1, 5 );
check_same( 'the order was cancelled meanwhile', array( false, array() ), array( $blocked['ok'], $GLOBALS['fh_mail'] ) );
unset( $GLOBALS['fh_orders'][502] );
$blocked = $cases->send( 1, 5 );
check_same( 'the order is gone', array( false, array() ), array( $blocked['ok'], $GLOBALS['fh_mail'] ) );
check_same( 'a case that does not exist', false, $cases->send( 999, 5 )['ok'] );

echo "\nThe e-mail cannot be sent\n";
list( $cases, $store, $rooms ) = fh_world();
fh_loss( $rooms, 1, 9001 );
fh_loss( $rooms, 1, 9002 );
$GLOBALS['fh_mail_fail'] = true;
$failed                  = $cases->send( 1, 5 );
$case                    = $store->get_case( 1 );
check_same( 'wp_mail refuses: the case is open again, nothing frozen, no dead links', array( false, 'open', null, '', null ), array( $failed['ok'], $case['status'], $case['amount_refund'], $case['token_salt'], $case['sent_at'] ) );
check_same( '… logged as a failure, and no "sent" note on the order', array( 'send_failed', 1 ), array( $store->events()[ count( $store->events() ) - 1 ], count( fh_notes( 502 ) ) ) );
$GLOBALS['fh_mail_fail'] = false;
check( '… and it can be sent again', $cases->send( 1, 5 )['ok'] && 1 === count( $GLOBALS['fh_mail'] ) );

/* ================================================================== */
echo "\nThe answer\n";
list( $cases, $store, $rooms ) = fh_world();
fh_loss( $rooms, 1, 9001 );
fh_loss( $rooms, 1, 9002 );
$early = $cases->answer( 1, 'refund', 'customer' );
check_same( 'a customer cannot answer a case that was never sent', array( false, 'state', 'open' ), array( $early['ok'], $early['code'], $store->get_case( 1 )['status'] ) );
$cases->send( 1, 5 );
$wrong = $cases->answer( 1, 'everything', 'customer' );
check_same( 'something that is not a choice', array( false, 'choice' ), array( $wrong['ok'], $wrong['code'] ) );
$answer = $cases->answer( 1, 'refund', 'customer' );
$case   = $store->get_case( 1 );
check_same( 'refund: recorded, by the customer', array( true, 'answered', 'refund', 'customer' ), array( $answer['ok'], $case['status'], $case['choice'], $case['choice_via'] ) );
check( '… with the moment', null !== $case['answered_at'] );
check_contains( 'the order note: the choice and the amount, and that the site did nothing', 'The customer chose: refund the lost fish — $60.00. Nothing was done by the site', fh_notes( 502 )[2] );
check_same( '… not as the owner\'s note', false, $GLOBALS['fh_orders'][502]->notes[2]['by_user'] );
check_same( 'other sections are told the choice', array( 'refund', 'customer' ), array_slice( fh_fired( 'fishotel_lostfish_case_answered' )[0], 1 ) );
check_same( 'the menu count: the owner has something to do', 1, Store::needs_you_count() );
check_same( 'the order was not touched: its status, its total, what was refunded', array( 'processing', '825', '0' ), array( $GLOBALS['fh_orders'][502]->status, $GLOBALS['fh_orders'][502]->get_total(), $GLOBALS['fh_orders'][502]->get_total_refunded() ) );

$second = $cases->answer( 1, 'cancel', 'customer' );
check_same( 'a second answer changes nothing: one choice per case', array( false, 'taken', 'refund' ), array( $second['ok'], $second['code'], $store->get_case( 1 )['choice'] ) );
check_same( '… and writes no second note', 3, count( fh_notes( 502 ) ) );
check_same( 'the fish is no longer owed from the tanks: three alive for three owed', array( 3, 3, 0 ), array_values( array_intersect_key( $cases->assess_stay( $rooms->stays[1] ), array( 'covered' => 1, 'committed' => 1, 'shortage' => 1 ) ) ) );

echo "\nWhat the owner has to do — the site does none of it\n";
$facts = $cases->orders()->facts( $GLOBALS['fh_orders'][502] );
$todo  = $cases->todo( $store->get_case( 1 ), $facts );
check_same( 'refund: the amount, the order, the link to it', array( 'Refund $60.00 on order #502.', 'Open order #502' ), array( $todo['text'], $todo['link'] ) );
check_contains( '…', 'id=502', $todo['url'] );

$store->update_case( 1, array( 'choice' => 'credit' ) );
$todo = $cases->todo( $store->get_case( 1 ), $facts );
check_same( 'credit: the amount for the wallet', 'Add $60.00 to the customer\'s wallet.', $todo['text'] );
check_contains( '… and, without the Batch Manager here, that its wallets screen is not available', 'wallets screen is not available', $todo['note'] );

$store->update_case( 1, array( 'choice' => 'cancel' ) );
$todo = $cases->todo( $store->get_case( 1 ), $facts );
check_same( 'cancel: the order and everything paid on it', 'Cancel order #502 and refund $825.00.', $todo['text'] );

$store->update_case( 1, array( 'choice' => 'retry' ) );
$todo = $cases->todo( $store->get_case( 1 ), $facts );
check_contains( 'try again: nothing is refunded', 'Nothing is refunded', $todo['text'] );
check_contains( '… and, without the Requests section here, a reminder to note it — the fish, for whom, already paid', 'note it yourself — 1 × Lagoon Goby for Blake Sample, already paid for', $todo['note'] );
$store->update_case( 1, array( 'choice' => 'refund' ) );

echo "\nTry again, without the Requests section\n";
list( $cases, $store, $rooms ) = fh_world();
fh_loss( $rooms, 1, 9001 );
fh_loss( $rooms, 1, 9002 );
$cases->send( 1, 5 );
$answer = $cases->answer( 1, 'retry', 'customer' );
check_same( 'recorded; no request was made, because nothing takes one', array( true, 'retry', '' ), array( $answer['ok'], $store->get_case( 1 )['choice'], $store->get_case( 1 )['request_ref'] ) );
check_contains( 'the order note has no amount for a try again', 'The customer chose: try again. Nothing was done', fh_notes( 502 )[2] );
$manual = $cases->create_request( 1, 5 );
check_same( '"Add to Requests" says the section is not available', array( false, '' ), array( $manual['ok'], $store->get_case( 1 )['request_ref'] ) );

echo "\nCancel the whole order\n";
list( $cases, $store, $rooms ) = fh_world();
$rooms->stays[2]['qty_live'] = 0;
$rooms->stays[2]['status']   = 'closed';
fh_loss( $rooms, 1, 9001 ); // Two alive for four owed: 502's two gobies …
fh_loss( $rooms, 1, 9002 ); // … one alive: 503's goby.
$on_502 = array();
foreach ( $store->cases() as $c ) {
	if ( 502 === $c['order_id'] ) {
		$on_502[] = $c['id'];
	}
}
sort( $on_502 );
check_same( 'one case for both gobies of 502', array( 1, 2 ), array( count( $on_502 ), $store->get_case( $on_502[0] )['qty'] ) );
$hand = $cases->open_by_hand( 502, 'i:54', 1, 5 ); // And a tang of the same order, by hand.
$cases->set_numbers( $on_502[0], array( 'ship_diff' => '0' ), 5 );
$cases->send( $on_502[0], 5 );
$answer = $cases->answer( $on_502[0], 'cancel', 'customer' );
check( 'the customer cancels the whole order on the first', $answer['ok'] );
check_same( 'the order\'s other case, still waiting, closes: there is nothing left to ask', array( 'closed', 'order_cancelled' ), array( $store->get_case( $hand['case']['id'] )['status'], $store->get_case( $hand['case']['id'] )['close_reason'] ) );
check_same( '… a case of ANOTHER order is untouched', 'open', $store->cases( array( 'order_id' => 503 ) )[0]['status'] );
check_same( 'the order itself is not cancelled by the site', 'processing', $GLOBALS['fh_orders'][502]->status );

echo "\nPart of the order has shipped\n";
list( $cases, $store, $rooms ) = fh_world();
$GLOBALS['fh_orders'][502]->items[54]->meta['_fishotel_line_status'] = 'shipped';
fh_loss( $rooms, 1, 9001 );
fh_loss( $rooms, 1, 9002 );
$case = $store->get_case( 1 );
$nums = $cases->numbers( $case, $cases->orders()->facts( $GLOBALS['fh_orders'][502] ) );
check_same( 'cancel the whole order is not offered', array( 0, false, null ), array( $case['offer_cancel'], $nums['offer_cancel'], $nums['cancel'] ) );
$cases->send( 1, 5 );
check_same( '… and cannot be answered', array( false, 'choice' ), array_values( array_intersect_key( $cases->answer( 1, 'cancel', 'customer' ), array( 'ok' => 1, 'code' => 1 ) ) ) );
check_same( '… three links in the e-mail, not four', 3, substr_count( $GLOBALS['fh_mail'][0]['message'], 'fh_lost_fish=' ) );

/* ================================================================== */
echo "\nThe owner records the answer by hand\n";
list( $cases, $store, $rooms ) = fh_world();
fh_loss( $rooms, 1, 9001 );
fh_loss( $rooms, 1, 9002 );
$hand = $cases->answer( 1, 'credit', 'owner', 5 );
$case = $store->get_case( 1 );
check_same( 'from a case that was never sent: recorded, the numbers frozen as a send would', array( true, 'answered', 'credit', 'owner', 6000, 82500 ), array( $hand['ok'], $case['status'], $case['choice'], $case['choice_via'], $case['amount_refund'], $case['amount_cancel'] ) );
check_same( '… without any e-mail', array(), $GLOBALS['fh_mail'] );
check_contains( '… and the note says it was by hand', 'recorded by hand: take a store credit — $60.00', fh_notes( 502 )[1] );

list( $cases, $store, $rooms ) = fh_world();
Shipping::$rater               = static function () {
	return null;
};
fh_loss( $rooms, 1, 9001 );
fh_loss( $rooms, 1, 9002 );
$hand = $cases->answer( 1, 'refund', 'owner', 5 );
check_same( 'not while the shipping difference is unknown: there would be no amount', array( false, 'numbers', 'open' ), array( $hand['ok'], $hand['code'], $store->get_case( 1 )['status'] ) );

/* ================================================================== */
echo "\nDone, reopen, close\n";
list( $cases, $store, $rooms ) = fh_world();
fh_loss( $rooms, 1, 9001 );
fh_loss( $rooms, 1, 9002 );
check_same( 'Done before there is an answer: no', false, $cases->done( 1, '', 5 )['ok'] );
$cases->send( 1, 5 );
check_same( '… nor while the customer has it', false, $cases->done( 1, '', 5 )['ok'] );
$cases->answer( 1, 'refund', 'customer' );
$done = $cases->done( 1, 'Refunded through the card.', 5 );
$case = $store->get_case( 1 );
check_same( 'Done, with a note', array( true, 'done', 'Refunded through the card.' ), array( $done['ok'], $case['status'], $case['done_note'] ) );
check_contains( 'the order note', 'Done: refund the lost fish. Refunded through the card.', fh_notes( 502 )[3] );
check_same( 'the four stages are on the order, in order', array( 'Opened', 'E-mail sent', 'The customer chose', 'Done' ), array_map( static function ( $n ) { return preg_match( '/— (Opened|E-mail sent|The customer chose|Done)/', $n, $m ) ? $m[1] : $n; }, fh_notes( 502 ) ) );
check_same( 'the menu count: nothing left', 0, Store::needs_you_count() );
check_same( 'Done twice: no', false, $cases->done( 1, '', 5 )['ok'] );

$old_salt = $case['token_salt'];
$reopen   = $cases->reopen( 1, 5 );
$case     = $store->get_case( 1 );
check_same( 'Reopen: open again, the answer forgotten, nothing frozen', array( true, 'open', '', null, null, null, null ), array( $reopen['ok'], $case['status'], $case['choice'], $case['answered_at'], $case['done_at'], $case['amount_refund'], $case['sent_at'] ) );
check_same( '… the old links are dead: the salt they were signed with is gone', array( '', true ), array( $case['token_salt'], '' !== $old_salt ) );
check_same( '… the numbers are worked out afresh', array( Shipping::NONE, 0 ), array( $case['ship_status'], $case['ship_diff'] ) );
check_contains( '… the order note says the links no longer work and nothing was sent', 'the links of the earlier e-mail no longer work. Nothing was sent', fh_notes( 502 )[4] );
check_same( '… no e-mail went out', 1, count( $GLOBALS['fh_mail'] ) );
check_same( 'reopening an open case: no', false, $cases->reopen( 1, 5 )['ok'] );
$cases->send( 1, 5 );
check( 'a reopened case is sent again with a NEW salt', $store->get_case( 1 )['token_salt'] !== $old_salt && 2 === count( $GLOBALS['fh_mail'] ) );

$close = $cases->close( 1, 'settled', 5 );
$case  = $store->get_case( 1 );
check_same( 'Close a sent case as settled with the customer', array( true, 'closed', 'settled' ), array( $close['ok'], $case['status'], $case['close_reason'] ) );
check_contains( '… the order note gives the reason', 'Closed by hand (settled with the customer directly)', fh_notes( 502 )[6] );
check_same( '… a settled case released its fish: three alive for three owed', 3, $cases->assess_stay( $rooms->stays[1] )['committed'] );
check_same( 'closing a closed case: no', false, $cases->close( 1, 'other', 5 )['ok'] );
check_same( 'a reason that is not one becomes "another reason"', 'other', $cases->close( $cases->open_by_hand( 503, 'i:55', 1, 5 )['case']['id'], 'because', 5 )['case']['close_reason'] );

/* ================================================================== */
echo "\nThe reminder\n";
list( $cases, $store, $rooms ) = fh_world();
fh_loss( $rooms, 1, 9001 );
fh_loss( $rooms, 1, 9002 );
$cases->send( 1, 5 );
$sent_at = strtotime( $store->get_case( 1 )['sent_at'] . ' UTC' );
check_same( 'the reminder is set to two days', 2, Settings::get()['reminder_days'] );
$tick = $cases->run_tick( $sent_at + 2 * 86400 - 60 );
check_same( 'a minute short of two days: nothing', array( array(), 1 ), array( $tick['reminded'], count( $GLOBALS['fh_mail'] ) ) );
check_same( '… the case is still "waiting"', 'waiting', Cases::bucket( $store->get_case( 1 ), $sent_at + 2 * 86400 - 60 ) );
$tick = $cases->run_tick( $sent_at + 2 * 86400 + 60 );
check_same( 'two days and no answer: ONE reminder', array( array( 1 ), 2 ), array( $tick['reminded'], count( $GLOBALS['fh_mail'] ) ) );
check_same( '… to the same address, marked as a reminder', array( 'blake@example.test', true ), array( $GLOBALS['fh_mail'][1]['to'], 0 === strpos( $GLOBALS['fh_mail'][1]['subject'], 'Reminder: ' ) ) );
check_contains( '… with the choices again', 'fh_lost_fish=', $GLOBALS['fh_mail'][1]['message'] );
$case = $store->get_case( 1 );
check( '… the links are good for 14 days from the reminder', abs( strtotime( $case['link_expires'] . ' UTC' ) - ( $sent_at + 2 * 86400 + 60 + 14 * 86400 ) ) <= 2 );
check_contains( '… the order note', 'No answer yet — one reminder sent to blake@example.test', fh_notes( 502 )[2] );
check_same( 'from here the case is under "Needs you"', array( 'needs', true, 1 ), array( Cases::bucket( $case ), Cases::is_stale( $case ), Store::needs_you_count() ) );
$tick = $cases->run_tick( $sent_at + 10 * 86400 );
check_same( 'never a second reminder', array( array(), 2 ), array( $tick['reminded'], count( $GLOBALS['fh_mail'] ) ) );
check( 'the customer can still answer after the reminder', $cases->answer( 1, 'credit', 'customer' )['ok'] );

list( $cases, $store, $rooms ) = fh_world();
Settings::save( array( 'reminder_days' => 0 ) );
fh_loss( $rooms, 1, 9001 );
fh_loss( $rooms, 1, 9002 );
$cases->send( 1, 5 );
$sent_at = strtotime( $store->get_case( 1 )['sent_at'] . ' UTC' );
$cases->run_tick( $sent_at + 5 * 86400 );
check_same( 'reminder set to 0: none is sent', 1, count( $GLOBALS['fh_mail'] ) );
check_same( '… the case waits, and needs the owner once its links have run out', array( 'waiting', 'needs' ), array( Cases::bucket( $store->get_case( 1 ), $sent_at + 5 * 86400 ), Cases::bucket( $store->get_case( 1 ), $sent_at + 15 * 86400 ) ) );

list( $cases, $store, $rooms ) = fh_world();
fh_loss( $rooms, 1, 9001 );
fh_loss( $rooms, 1, 9002 );
$cases->send( 1, 5 );
$cases->answer( 1, 'retry', 'customer' );
$cases->run_tick( time() + 5 * 86400 );
check_same( 'an answered case is never reminded', 1, count( $GLOBALS['fh_mail'] ) );

$GLOBALS['fh_options'][ Store::COUNTS_OPTION ] = array( 'done' => 3 );
$before                                        = count( $store->log );
Cases::tick();
check_same( 'the cron hook with nothing open or sent returns at once', $before, count( $store->log ) );

/* ================================================================== */
echo "\nSend by itself after the undo window (the setting, off by default)\n";
list( $cases, $store, $rooms ) = fh_world();
Settings::save( array( 'auto_send' => '1' ) );
fh_loss( $rooms, 1, 9001 );
fh_loss( $rooms, 1, 9002 );
check_same( 'switched on, a loss that opens a case schedules one tick just after the window', array( 'single', Lost_Fish::TICK_HOOK ), array( $GLOBALS['fh_cron'][0][0], $GLOBALS['fh_cron'][0][2] ) );
check( '… 15 minutes and a margin from now', abs( $GLOBALS['fh_cron'][0][1] - ( time() + 900 + 60 ) ) <= 2 );
check_same( 'the loss itself still sends nothing', array(), $GLOBALS['fh_mail'] );
$created = strtotime( $store->get_case( 1 )['loss_at'] . ' UTC' );
$tick    = $cases->run_tick( $created + 899 );
check_same( 'inside the window: nothing — the loss can still be undone', array( array(), array() ), array( $tick['sent'], $GLOBALS['fh_mail'] ) );
$tick = $cases->run_tick( $created + 901 );
check_same( 'after it: sent', array( array( 1 ), 1, 'sent' ), array( $tick['sent'], count( $GLOBALS['fh_mail'] ), $store->get_case( 1 )['status'] ) );
check_contains( '… the order note says it went by itself', 'Sent by itself after the undo window.', fh_notes( 502 )[1] );
check_same( '… not as anybody\'s note', false, $GLOBALS['fh_orders'][502]->notes[1]['by_user'] );

list( $cases, $store, $rooms ) = fh_world();
Settings::save( array( 'auto_send' => '1' ) );
fh_loss( $rooms, 1, 9001 );
fh_loss( $rooms, 1, 9002 );
$store->rows[1]['created_at'] = gmdate( 'Y-m-d H:i:s', time() - 3600 ); // The first loss was an hour ago …
$store->rows[1]['loss_at']    = gmdate( 'Y-m-d H:i:s', time() - 3600 );
fh_loss( $rooms, 1, 9003 );                                               // … and one more just now.
$lost = strtotime( $store->get_case( 1 )['loss_at'] . ' UTC' );
$tick = $cases->run_tick( $lost + 899 );
check_same( 'a case that grew waits out the undo window of its LAST loss', array( array(), array(), 2 ), array( $tick['sent'], $GLOBALS['fh_mail'], $store->get_case( 1 )['qty'] ) );
check_same( '… and a tick was scheduled for it', 2, count( $GLOBALS['fh_cron'] ) );
$tick = $cases->run_tick( $lost + 901 );
check_same( '… then goes out once, for both fish', array( array( 1 ), 1 ), array( $tick['sent'], count( $GLOBALS['fh_mail'] ) ) );
check_contains( '…', '2 × Lagoon Goby', $GLOBALS['fh_mail'][0]['message'] );
$cases->reopen( 1, 5 );
$tick = $cases->run_tick( $lost + 40 * DAY_IN_SECONDS );
check_same( 'a case the owner REOPENED never goes out by itself again', array( array(), 1, 'open' ), array( $tick['sent'], count( $GLOBALS['fh_mail'] ), $store->get_case( 1 )['status'] ) );

list( $cases, $store, $rooms ) = fh_world();
Settings::save( array( 'auto_send' => '1' ) );
fh_loss( $rooms, 1, 9001 );
fh_loss( $rooms, 1, 9002 );
fh_undo( $rooms, 1, 9002 );
$cases->run_tick( time() + 5000 );
check_same( 'undone inside the window: the case closed, nothing is ever sent', array( 'closed', array() ), array( $store->get_case( 1 )['status'], $GLOBALS['fh_mail'] ) );

list( $cases, $store, $rooms ) = fh_world();
Settings::save( array( 'auto_send' => '1' ) );
Shipping::$rater = static function () {
	return null;
};
fh_loss( $rooms, 1, 9001 );
fh_loss( $rooms, 1, 9002 );
$cases->run_tick( time() + 5000 );
check_same( 'the shipping difference is not known: it waits for the owner', array( 'open', array() ), array( $store->get_case( 1 )['status'], $GLOBALS['fh_mail'] ) );
$cases->set_numbers( 1, array( 'ship_diff' => '0' ), 5 );
$cases->run_tick( time() + 5000 );
check_same( '… and a case whose numbers were typed by hand waits for the click too', array( 'open', array() ), array( $store->get_case( 1 )['status'], $GLOBALS['fh_mail'] ) );

list( $cases, $store, $rooms ) = fh_world();
Settings::save( array( 'auto_send' => '1' ) );
fh_loss( $rooms, 1, 9001 );
fh_loss( $rooms, 1, 9002 );
$rooms->add( 4, array( 'product_id' => 812, 'species' => 'Lagoon Goby', 'qty_live' => 6 ) ); // New fish arrived.
$cases->run_tick( time() + 5000 );
check_same( 'the tanks are no longer short when the window ends: it waits for the owner', array( 'open', array() ), array( $store->get_case( 1 )['status'], $GLOBALS['fh_mail'] ) );

list( $cases, $store, $rooms ) = fh_world();
Settings::save( array( 'auto_send' => '1' ) );
$GLOBALS['fh_orders'][502]->items[53]->product_id = 0;
$GLOBALS['fh_orders'][502]->items[53]->meta       = array( '_fh_invoice_fish' => 1, '_fh_invoice_product_id' => 813 );
fh_loss( $rooms, 1, 9001 );
fh_loss( $rooms, 1, 9002 );
$case = $store->get_case( 1 );
check_same( 'a line matched by NAME only is marked "check"', array( 'name', 1 ), array( $case['line_match'], $case['needs_check'] ) );
check_contains( '…', 'by NAME only', $case['check_note'] );
$cases->run_tick( time() + 5000 );
check_same( '… and is never sent by itself', array( 'open', array() ), array( $store->get_case( 1 )['status'], $GLOBALS['fh_mail'] ) );
check( '… the owner can still send it with the click', $cases->send( 1, 5 )['ok'] );

list( $cases, $store, $rooms ) = fh_world();
Settings::save( array( 'auto_send' => '1' ) );
$GLOBALS['fh_orders'][502]->meta['_fishotel_shipping_date'] = gmdate( 'Y-m-d', time() + 86400 ); // Delivered tomorrow: it ships today.
fh_loss( $rooms, 1, 9001 );
fh_loss( $rooms, 1, 9002 );
$case = $store->get_case( 1 );
check_same( 'an order due to ship today: its box may already be packed — the case is marked "check"', array( 'product', 1 ), array( $case['line_match'], $case['needs_check'] ) );
check_contains( '… saying why', 'due to ship today or earlier', $case['check_note'] );
$cases->run_tick( time() + 5000 );
check_same( '… and is never sent by itself', array( 'open', array() ), array( $store->get_case( 1 )['status'], $GLOBALS['fh_mail'] ) );

/* ================================================================== */
echo "\nA case by hand, from an order line\n";
list( $cases, $store, $rooms ) = fh_world();
Settings::save( array( 'auto_send' => '1' ) );
$hand = $cases->open_by_hand( 502, 'i:54', 2, 5 );
$case = $hand['case'];
check_same( 'opened for two of the three tangs', array( true, 'manual', 'manual', 2, 'Harbor Tang', 23000, 'p:813', 5 ), array( $hand['ok'], $case['origin'], $case['line_match'], $case['qty'], $case['fish_name'], $case['unit_paid'], $case['kind_key'], $case['created_by'] ) );
check_same( 'its shipping difference: 810.00 less 460.00 is under the threshold', array( Shipping::DROP, 2500 ), array( $case['ship_status'], $case['ship_diff'] ) );
check_contains( 'the order note says it was by hand', 'Opened by hand for 2 × Harbor Tang. Nothing was sent', fh_notes( 502 )[0] );
check_same( 'no e-mail', array(), $GLOBALS['fh_mail'] );
$cases->run_tick( time() + 5000 );
check_same( 'a case by hand is never sent by itself, whatever the setting', array( 'open', array() ), array( $store->get_case( $case['id'] )['status'], $GLOBALS['fh_mail'] ) );
check_same( 'two tangs have a case: only one more can get one', array( false, true ), array( $cases->open_by_hand( 502, 'i:54', 2, 5 )['ok'], $cases->open_by_hand( 502, 'i:54', 1, 5 )['ok'] ) );
check_same( '… and then none', false, $cases->open_by_hand( 502, 'i:54', 1, 5 )['ok'] );
check_same( 'not for zero fish', false, $cases->open_by_hand( 502, 'i:53', 0, 5 )['ok'] );
check_same( 'not for a line that is not on the order', false, $cases->open_by_hand( 502, 'i:999', 1, 5 )['ok'] );
check_same( 'not for an order that does not exist', false, $cases->open_by_hand( 9999, 'i:1', 1, 5 )['ok'] );

/* ================================================================== */
echo "\nGiving a case to another customer\n";
list( $cases, $store, $rooms ) = fh_world();
fh_loss( $rooms, 1, 9001 );
fh_loss( $rooms, 1, 9002 );
$others = $cases->alternatives( $store->get_case( 1 ) );
check_same( 'the other holders of the same fish, most recently paid first', array( 503, 501 ), array_column( $others, 'order_id' ) );
$moved = $cases->reassign( 1, 501, 'i:51', 5 );
$case  = $store->get_case( 1 );
check_same( 'moved to order 501: its line, its customer, what was paid there', array( true, 501, 'i:51', 'Avery Example', 'avery@example.test', 6000 ), array( $moved['ok'], $case['order_id'], $case['line_ref'], $case['customer_name'], $case['customer_email'], $case['unit_paid'] ) );
check_same( '… and the shipping difference of THAT order: it drops under the threshold', array( Shipping::DROP, 2500 ), array( $case['ship_status'], $case['ship_diff'] ) );
check_contains( 'the old order is told it keeps its fish', 'Moved to order #501: this order keeps its fish', fh_notes( 502 )[1] );
check_contains( 'the new order is told', 'Given to this order by hand: 1 × Lagoon Goby was lost', fh_notes( 501 )[0] );
check_same( 'no e-mail', array(), $GLOBALS['fh_mail'] );
check_same( 'not to a line that is not owed the fish', false, $cases->reassign( 1, 502, 'i:54', 5 )['ok'] );
$cases->send( 1, 5 );
check_same( 'not once it was sent', false, $cases->reassign( 1, 503, 'i:55', 5 )['ok'] );
check_same( 'a case by hand has no tank group to find other holders through', array(), $cases->alternatives( $cases->open_by_hand( 503, 'i:55', 1, 5 )['case'] ) );

/* ================================================================== */
echo "\nTwo cases on one order\n";
list( $cases, $store, $rooms ) = fh_world();
$first  = $cases->open_by_hand( 501, 'i:51', 1, 5 )['case']; // The 60.00 goby.
$second = $cases->open_by_hand( 501, 'i:52', 1, 5 )['case']; // One 230.00 tang.
$first  = $cases->refresh( $store->get_case( $first['id'] ) );
check_same( 'while both wait, neither difference is known — the first shows what it would be alone', array( Shipping::UNKNOWN, null, 2500 ), array( $first['ship_status'], $first['ship_diff'], $first['ship_reduced'] ) );
check_same( '… and neither can be sent as it is', false, $cases->send( $first['id'], 5 )['ok'] );
$cases->set_numbers( $first['id'], array( 'ship_diff' => '25.00' ), 5 );
$cases->answer( $first['id'], 'refund', 'owner', 5 );
$second = $cases->refresh( $store->get_case( $second['id'] ) );
check_same( 'the first is answered (refund, 25.00 withheld): the second is worked out on what is left — nothing more to withhold', array( Shipping::NONE, 0 ), array( $second['ship_status'], $second['ship_diff'] ) );
check_same( '… its refund is the whole tang', 23000, $cases->numbers( $second, $cases->orders()->facts( $GLOBALS['fh_orders'][501] ) )['refund'] );

/* ================================================================== */
echo "\nFor other sections\n";
$summary = Cases::summary( $store->get_case( $first['id'] ) );
check_same( 'a case in short: its status, the fish, the choice and the amount', array( 'answered', 'Lagoon Goby', 1, 501, 51, 'refund', 3500 ), array( $summary['status'], $summary['fish'], $summary['qty'], $summary['order_id'], $summary['order_item_id'], $summary['choice'], $summary['amount'] ) );
check_contains( '… and where to find it', 'page=fishotel-misc-lost-fish#fh-lf-case-' . $first['id'], $summary['url'] );
check_same( 'no choice yet: no amount', array( '', null ), array( Cases::summary( $second )['choice'], Cases::summary( $second )['amount'] ) );

add_action(
	'fishotel_lostfish_case_done',
	static function () {
		throw new \RuntimeException( 'a listener that breaks' );
	}
);
$done = $cases->done( $first['id'], '', 5 );
check_same( 'a listener that throws cannot undo what happened', array( true, 'done' ), array( $done['ok'], $store->get_case( $first['id'] )['status'] ) );

fh_finish();
