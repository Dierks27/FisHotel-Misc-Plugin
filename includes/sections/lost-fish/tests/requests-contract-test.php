<?php
/**
 * Requests contract tests — WITH the Requests section's
 * fishotel_requests_create() present (a stand-in here): a "try again"
 * hands it the fish as an owed request — source lost_fish, the fish, its
 * size, the customer, the order — exactly once; no other choice calls it;
 * when it says no, throws or answers something unusable the case shows
 * the reminder and the owner's "Add to Requests" can try again; and what
 * it may answer with (an id, a row, an object, true).
 *
 * Run from the plugin root:
 *   php includes/sections/lost-fish/tests/requests-contract-test.php
 *
 * @package FisHotel\Misc\Sections\Lost_Fish
 */

require_once __DIR__ . '/wp-stubs.php';
require_once __DIR__ . '/fakes.php';

use FisHotel\Misc\Sections\Lost_Fish\Cases;
use FisHotel\Misc\Sections\Lost_Fish\Shipping;
use FisHotel\Misc\Sections\Lost_Fish\Store;

$GLOBALS['fh_requests']       = array();
$GLOBALS['fh_request_answer'] = 310;

function fishotel_requests_create( $args ) {
	$GLOBALS['fh_requests'][] = $args;
	if ( 'throw' === $GLOBALS['fh_request_answer'] ) {
		throw new \RuntimeException( 'the requests table is gone' );
	}
	return $GLOBALS['fh_request_answer'];
}

/**
 * One sent case: a Medium Lagoon Goby lost, order 502 short of it.
 *
 * @return array{0: Cases, 1: Fh_Fake_Store}
 */
function fh_sent_case() {
	fh_reset();
	$GLOBALS['fh_requests']                            = array();
	$GLOBALS['fh_options'][ Store::DB_VERSION_OPTION ] = Store::DB_VERSION;
	Shipping::$rater                                   = 'fh_rater';

	fh_product( 812, array( 'class' => 'premium' ) );
	fh_order( 502, array( 'paid' => 3000, 'shipping' => 49.99, 'first_name' => 'Blake', 'last_name' => 'Sample', 'email' => 'blake@example.test', 'customer_id' => 42, 'lines' => array( 53 => array( 'name' => 'Lagoon Goby', 'product_id' => 812, 'qty' => 2, 'total' => 120.00 ) ) ) );

	$rooms = new Fh_Fake_Rooms();
	$rooms->add( 1, array( 'product_id' => 812, 'species' => 'Lagoon Goby', 'size_slug' => 'm', 'size_label' => 'Medium', 'qty_live' => 2 ) );
	$store = new Fh_Fake_Store();
	$cases = fh_cases( $store, $rooms );

	$rooms->lose( 1, 1 );
	$cases->loss_recorded( 1, 9001, 1, 7 );
	$cases->send( 1, 5 );

	return array( $cases, $store );
}

echo "Try again\n";
list( $cases, $store ) = fh_sent_case();
check_same( 'nothing is handed over before the customer has answered', array(), $GLOBALS['fh_requests'] );
$answer = $cases->answer( 1, 'retry', 'customer' );
check( 'the customer chooses to try again', $answer['ok'] );
check_same( 'the Requests section is called once', 1, count( $GLOBALS['fh_requests'] ) );
$args = $GLOBALS['fh_requests'][0];
check_same( 'source lost_fish, owed', array( 'lost_fish', 'owed', true ), array( $args['source'], $args['status'], $args['owed'] ) );
check_same( 'the fish and its size', array( 'Lagoon Goby', 'Lagoon Goby', 812, 'Medium', 'm', 'Medium', 1 ), array( $args['fish'], $args['species'], $args['product_id'], $args['size'], $args['size_slug'], $args['size_label'], $args['qty'] ) );
check_same( 'the customer', array( 42, 'Blake Sample', 'blake@example.test' ), array( $args['customer_id'], $args['customer_name'], $args['customer_email'] ) );
check_same( 'the order and its line', array( 502, 53 ), array( $args['order_id'], $args['order_item_id'] ) );
check_same( 'the case it came from, and a note that it is already paid for', array( 1, true ), array( $args['lost_fish_case'], false !== strpos( $args['note'], 'already paid for' ) ) );
$case = $store->get_case( 1 );
check_same( 'the case keeps the request\'s id', '310', $case['request_ref'] );
check_contains( 'the order is told', 'A request for a new fish was created (#310): owed, already paid for.', fh_notes( 502 )[3] );
check_same( 'the log', 'request', $store->events()[ count( $store->events() ) - 1 ] );
check_contains( 'what the owner has to do names the request', 'A request was created in Requests (#310)', $cases->todo( $case, $cases->orders()->facts( $GLOBALS['fh_orders'][502] ) )['note'] );
check_same( 'no money figure and no second e-mail', array( 0, 1 ), array( Cases::summary( $case )['amount'], count( $GLOBALS['fh_mail'] ) ) );

$again = $cases->create_request( 1, 5 );
check_same( '"Add to Requests" on a case that has one: nothing is created twice', array( true, 1 ), array( $again['ok'], count( $GLOBALS['fh_requests'] ) ) );

$cases->reopen( 1, 5 );
check_contains( 'reopening the case leaves the request alone and says so', 'The request made for the earlier "try again" (310) is still in Requests', fh_notes( 502 )[4] );
$cases->answer( 1, 'retry', 'owner', 5 );
check_same( 'the same answer again makes no second request', 1, count( $GLOBALS['fh_requests'] ) );

echo "\nThe other choices\n";
foreach ( array( 'refund', 'credit', 'cancel' ) as $choice ) {
	list( $cases, $store ) = fh_sent_case();
	$cases->answer( 1, $choice, 'customer' );
	check_same( $choice . ': the Requests section is not called', array(), $GLOBALS['fh_requests'] );
	check_same( '… and "Add to Requests" refuses', false, $cases->create_request( 1, 5 )['ok'] );
}

echo "\nWhen the Requests section does not take it\n";
foreach ( array(
	'false'        => false,
	'null'         => null,
	'zero'         => 0,
	'an empty row' => array(),
	'an error'     => new \RuntimeException( 'no' ),
	'throws'       => 'throw',
) as $label => $reply ) {
	list( $cases, $store )        = fh_sent_case();
	$GLOBALS['fh_request_answer'] = $reply;
	$answer                       = $cases->answer( 1, 'retry', 'customer' );
	$case                         = $store->get_case( 1 );
	check_same( $label . ': the choice is recorded all the same, without a request', array( true, 'answered', 'retry', '' ), array( $answer['ok'], $case['status'], $case['choice'], $case['request_ref'] ) );
}
check_same( '… logged as a warning', 'request_failed', $store->events()[ count( $store->events() ) - 1 ] );
check_contains( '… the owner is pointed at "Add to Requests"', 'use "Add to Requests"', $cases->todo( $store->get_case( 1 ), $cases->orders()->facts( $GLOBALS['fh_orders'][502] ) )['note'] );
$GLOBALS['fh_request_answer'] = 311;
$retry                        = $cases->create_request( 1, 5 );
check_same( '… which works once the section answers', array( true, '311' ), array( $retry['ok'], $store->get_case( 1 )['request_ref'] ) );

echo "\nWhat it may answer with\n";
foreach ( array(
	'an id'                   => array( 312, '312' ),
	'an id as text'           => array( 'REQ-313', 'REQ-313' ),
	'a row with an id'        => array( array( 'id' => 314 ), '314' ),
	'an object with an id'    => array( (object) array( 'id' => 315 ), '315' ),
	'true (made, no id told)' => array( true, 'created' ),
) as $label => $pair ) {
	list( $cases, $store )        = fh_sent_case();
	$GLOBALS['fh_request_answer'] = $pair[0];
	$cases->answer( 1, 'retry', 'customer' );
	check_same( $label, $pair[1], $store->get_case( 1 )['request_ref'] );
}
$GLOBALS['fh_request_answer'] = 310;

echo "\nThe arguments can be shaped\n";
list( $cases, $store ) = fh_sent_case();
add_filter(
	'fishotel_lostfish_request_args',
	static function ( $args, $case ) {
		$args['priority'] = 'high';
		$args['case_qty'] = $case['qty'];
		return $args;
	},
	10,
	2
);
$cases->answer( 1, 'retry', 'customer' );
check_same( 'through the fishotel_lostfish_request_args filter', array( 'high', 1 ), array( $GLOBALS['fh_requests'][0]['priority'], $GLOBALS['fh_requests'][0]['case_qty'] ) );

fh_finish();
