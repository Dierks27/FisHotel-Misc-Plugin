<?php
/**
 * Customer page tests — what happens behind a link of the e-mail: a GET
 * changes NOTHING (mail scanners open links), neither does a POST without
 * the Confirm field; the Confirm records the choice once, and a second
 * Confirm on any link only shows what was chosen; a token for another
 * case or another choice, an altered one, an expired one, the links of a
 * reopened or closed case are each turned away; and the page shows the
 * same words and the same money as the e-mail.
 *
 * Run from the plugin root:
 *   php includes/sections/lost-fish/tests/customer-page-test.php
 *
 * @package FisHotel\Misc\Sections\Lost_Fish
 */

require_once __DIR__ . '/wp-stubs.php';
require_once __DIR__ . '/fakes.php';

use FisHotel\Misc\Sections\Lost_Fish\Cases;
use FisHotel\Misc\Sections\Lost_Fish\Customer_Page;
use FisHotel\Misc\Sections\Lost_Fish\Shipping;
use FisHotel\Misc\Sections\Lost_Fish\Store;
use FisHotel\Misc\Sections\Lost_Fish\Token;

/**
 * Two sent cases on two orders. Case 1: order 501 drops under the
 * threshold (60.00 fish, 25.00 shipping difference). Case 2: order 503.
 *
 * @return array{0: Cases, 1: Fh_Fake_Store, 2: array<string, string>, 3: array<string, string>} The cases, the store, the
 *         tokens of case 1 and of case 2 by choice (read out of the e-mails that went out).
 */
function fh_two_sent() {
	fh_reset();
	$GLOBALS['fh_options'][ Store::DB_VERSION_OPTION ] = Store::DB_VERSION;
	Shipping::$rater                                   = 'fh_rater';

	fh_product( 812, array( 'class' => 'premium' ) );
	fh_product( 813, array( 'class' => 'premium' ) );
	fh_order( 501, array( 'paid' => 1000, 'shipping' => 15.00, 'email' => 'avery@example.test', 'meta' => array( '_fishotel_shipping_date' => '2031-03-12' ), 'lines' => array( 51 => array( 'name' => 'Lagoon Goby', 'product_id' => 812, 'qty' => 1, 'total' => 60.00 ), 52 => array( 'name' => 'Harbor Tang', 'product_id' => 813, 'qty' => 2, 'total' => 460.00 ) ) ) );
	fh_order( 503, array( 'paid' => 2000, 'shipping' => 40.00, 'first_name' => 'Casey', 'email' => 'casey@example.test', 'lines' => array( 55 => array( 'name' => 'Lagoon Goby', 'product_id' => 812, 'qty' => 1, 'total' => 60.00 ) ) ) );

	$store = new Fh_Fake_Store();
	$cases = fh_cases( $store, new Fh_Fake_Rooms() );
	$cases->open_by_hand( 501, 'i:51', 1, 5 );
	$cases->open_by_hand( 503, 'i:55', 1, 5 );
	$cases->send( 1, 5 );
	$cases->send( 2, 5 );

	$tokens = array();
	foreach ( $GLOBALS['fh_mail'] as $index => $mail ) {
		preg_match_all( '/fh_lost_fish=(\d+)\.([a-z]+)\.(\d+)\.([a-f0-9]{40})/', $mail['message'], $m, PREG_SET_ORDER );
		foreach ( $m as $hit ) {
			$tokens[ $index ][ $hit[2] ] = $hit[1] . '.' . $hit[2] . '.' . $hit[3] . '.' . $hit[4];
		}
	}

	return array( $cases, $store, $tokens[0], $tokens[1] );
}

/**
 * Everything about the store and the orders that an answer would change.
 */
function fh_state( Fh_Fake_Store $store ) {
	return array( $store->rows, count( $store->log ), count( $GLOBALS['fh_mail'] ), fh_notes( 501 ), fh_notes( 503 ), $GLOBALS['fh_orders'][501]->status );
}

$now = time();

echo "A link, opened\n";
list( $cases, $store, $t1, $t2 ) = fh_two_sent();
check_same( 'the e-mail carried four links for the case', array( 'refund', 'credit', 'retry', 'cancel' ), array_keys( $t1 ) );
$before = fh_state( $store );
$view   = Customer_Page::handle( $t1['refund'], 'GET', false, $cases, $now );
check_same( 'the refund link opens the confirm page', array( 'confirm', 200, 'refund' ), array( $view['state'], $view['http'], $view['row']['choice'] ) );
check_same( 'OPENING THE LINK CHANGED NOTHING: the case, the log, the mail, the order notes, the order', $before, fh_state( $store ) );
foreach ( array( 'GET', 'HEAD', 'OPTIONS', 'get' ) as $method ) {
	Customer_Page::handle( $t1['cancel'], $method, false, $cases, $now );
	Customer_Page::handle( $t1['cancel'], $method, true, $cases, $now ); // Even carrying the Confirm field.
}
check_same( 'a scanner opening every link, any way but a POST, any number of times: still nothing', $before, fh_state( $store ) );
Customer_Page::handle( $t1['cancel'], 'POST', false, $cases, $now );
check_same( 'a POST without the Confirm field: still nothing', $before, fh_state( $store ) );

echo "\nThe same numbers as the e-mail\n";
$html = Customer_Page::render( $view );
check_same( 'the money', array( 3500, '$35.00' ), array( $view['row']['amount'], $view['row']['money'] ) );
check_contains( 'the page shows the choice with its amount', 'Refund the lost fish — $35.00', $html );
check_contains( '… and the very sentence of the e-mail', '$60.00 for the fish, less $25.00 shipping. Without this fish your order is no longer over the amount for reduced shipping, so its shipping is $40.00 instead of the $15.00 you paid.', $html );
check_contains( '…', 'less $25.00 shipping. Without this fish your order is no longer over the amount for reduced shipping, so its shipping is $40.00 instead of the $15.00 you paid.', $GLOBALS['fh_mail'][0]['message'] );
check_contains( 'which fish, which order', '1 × Lagoon Goby', $html );
check_contains( '…', 'About your order #501', $html );
check_contains( 'when the rest ships', 'it ships on Tuesday, March 11, 2031', $html );
check_contains( 'a Confirm button, in a form that POSTs', '<form method="post"', $html );
check_contains( '… carrying the token and the Confirm field', 'name="fh_lost_fish" value="' . $t1['refund'] . '"', $html );
check_contains( '…', 'name="fh_lf_confirm" value="1"', $html );
check_contains( '… labelled with the choice', 'Yes, refund the lost fish', $html );
check_contains( 'it says nothing changes until the button is pressed', 'Nothing changes until you press the button', $html );
check_same( 'the other three choices are offered as links, each its own token', array( 'credit', 'retry', 'cancel' ), array_column( $view['others'], 'choice' ) );
foreach ( $view['others'] as $other ) {
	check_contains( 'the ' . $other['choice'] . ' link is the one from the e-mail', 'fh_lost_fish=' . $t1[ $other['choice'] ], $other['url'] );
}
check_contains( 'no index, no script', 'noindex', $html );
check_lacks( '…', '<script', $html );

$view = Customer_Page::handle( $t1['cancel'], 'GET', false, $cases, $now );
check_same( 'the cancel link shows everything paid on the order', array( 'cancel', 53500 ), array( $view['row']['choice'], $view['row']['amount'] ) );
check_lacks( '… and no line about the rest of the order shipping', 'it ships on', Customer_Page::render( $view ) );

echo "\nConfirm\n";
$view = Customer_Page::handle( $t1['credit'], 'POST', true, $cases, $now );
$case = $store->get_case( 1 );
check_same( 'the Confirm on the credit link records: take a store credit', array( 'recorded', 200, 'answered', 'credit', 'customer' ), array( $view['state'], $view['http'], $case['status'], $case['choice'], $case['choice_via'] ) );
$html = Customer_Page::render( $view );
check_contains( 'the page thanks and says what was chosen', 'Thank you — your choice is recorded.', $html );
check_contains( '…', 'Take a store credit — $35.00', $html );
check_lacks( '… with no button left', '<form', $html );
check_contains( 'the order note', 'The customer chose: take a store credit — $35.00', fh_notes( 501 )[2] );
check_same( 'case 2 is untouched', 'sent', $store->get_case( 2 )['status'] );

echo "\nOne choice per case\n";
$before = fh_state( $store );
$view   = Customer_Page::handle( $t1['cancel'], 'POST', true, $cases, $now );
check_same( 'a second Confirm, on another link of the same case: nothing changes', array( $before, 'credit' ), array( fh_state( $store ), $store->get_case( 1 )['choice'] ) );
check_same( '… the page says what WAS chosen', array( 'chosen', 'credit' ), array( $view['state'], $view['row']['choice'] ) );
check_contains( '…', 'A choice was already made for this fish', Customer_Page::render( $view ) );
$view = Customer_Page::handle( $t1['refund'], 'GET', false, $cases, $now );
check_same( 'opening any link afterwards shows the same', array( 'chosen', 'credit', array() ), array( $view['state'], $view['row']['choice'], $view['others'] ) );
$view = Customer_Page::handle( $t1['refund'], 'GET', false, $cases, $now + 400 * 86400 );
check_same( '… even long after the links ran out', 'chosen', $view['state'] );

echo "\nThe wrong token\n";
list( $cases, $store, $t1, $t2 ) = fh_two_sent();
$before                          = fh_state( $store );
$p1                              = Token::parse( $t1['refund'] );
$p2                              = Token::parse( $t2['refund'] );
$wrong                           = array(
	'the signature of case 1 under case 2'       => '2.refund.' . $p1['expires'] . '.' . $p1['sig'],
	'the signature of case 2 under case 1'       => '1.refund.' . $p2['expires'] . '.' . $p2['sig'],
	'the refund signature under another choice'  => '1.cancel.' . $p1['expires'] . '.' . $p1['sig'],
	'a later expiry written in'                  => '1.refund.' . ( $p1['expires'] + 86400 ) . '.' . $p1['sig'],
	'a made-up signature'                        => '1.refund.' . $p1['expires'] . '.' . str_repeat( 'a', 40 ),
	'a case that does not exist'                 => '999.refund.' . $p1['expires'] . '.' . $p1['sig'],
	'not a token'                                => 'refund-me',
	'nothing'                                    => '',
);
foreach ( $wrong as $label => $token ) {
	$view = Customer_Page::handle( $token, 'POST', true, $cases, $now );
	check_same( $label . ': not valid, a 404, and nothing about any case', array( 'invalid', 404, null, null ), array( $view['state'], $view['http'], $view['case'], $view['row'] ) );
}
check_same( '… and none of them, confirmed, changed anything', $before, fh_state( $store ) );
$html = Customer_Page::render( Customer_Page::handle( 'refund-me', 'GET', false, $cases, $now ) );
check_contains( 'the page for a bad link says only that', 'This link is not valid', $html );
check_lacks( '… no fish, no order, no amount', 'Lagoon', $html );
check_lacks( '…', '501', $html );
check( 'a good token of case 2 still only ever answers case 2', 'recorded' === Customer_Page::handle( $t2['retry'], 'POST', true, $cases, $now )['state'] && 'retry' === $store->get_case( 2 )['choice'] && 'sent' === $store->get_case( 1 )['status'] );

echo "\nExpired\n";
list( $cases, $store, $t1, $t2 ) = fh_two_sent();
$before                          = fh_state( $store );
$late                            = Token::parse( $t1['refund'] )['expires'] + 1;
$view                            = Customer_Page::handle( $t1['refund'], 'GET', false, $cases, $late );
check_same( 'a second past the expiry: expired', array( 'expired', 200, null ), array( $view['state'], $view['http'], $view['row'] ) );
$view = Customer_Page::handle( $t1['refund'], 'POST', true, $cases, $late );
check_same( '… and a Confirm records nothing', array( 'expired', $before ), array( $view['state'], fh_state( $store ) ) );
check_contains( 'the page says to reply to the e-mail', 'This link has expired', Customer_Page::render( $view ) );
check_same( 'at the very second of the expiry it is still good', 'confirm', Customer_Page::handle( $t1['refund'], 'GET', false, $cases, $late - 1 )['state'] );

echo "\nReopened, closed\n";
$cases->reopen( 1, 5 );
$before = fh_state( $store );
$view   = Customer_Page::handle( $t1['refund'], 'POST', true, $cases, $now );
check_same( 'the owner reopened the case: its old links are not valid any more', array( 'invalid', $before ), array( $view['state'], fh_state( $store ) ) );
$cases->send( 1, 5 );
preg_match( '/fh_lost_fish=(1\.refund\.\d+\.[a-f0-9]{40})/', $GLOBALS['fh_mail'][2]['message'], $m );
check( 'the new e-mail carries new links', $m[1] !== $t1['refund'] && 'confirm' === Customer_Page::handle( $m[1], 'GET', false, $cases, $now )['state'] );
check_same( '… and the old ones stay dead', 'invalid', Customer_Page::handle( $t1['refund'], 'GET', false, $cases, $now )['state'] );

$cases->close( 2, 'mistake', 5 );
$before = fh_state( $store );
$view   = Customer_Page::handle( $t2['refund'], 'POST', true, $cases, $now );
check_same( 'the owner closed the case: its links say so, and record nothing', array( 'closed', $before ), array( $view['state'], fh_state( $store ) ) );
check_contains( '…', 'This request is closed', Customer_Page::render( $view ) );

echo "\nCancel, once part of the order shipped\n";
list( $cases, $store, $t1, $t2 ) = fh_two_sent();
$store->update_case( 1, array( 'offer_cancel' => 0 ) );
check_same( 'a cancel link of a case that does not offer it is not valid', 'invalid', Customer_Page::handle( $t1['cancel'], 'POST', true, $cases, $now )['state'] );
check_same( '… and is not listed among the other choices', array( 'credit', 'retry' ), array_column( Customer_Page::handle( $t1['refund'], 'GET', false, $cases, $now )['others'], 'choice' ) );

echo "\nWhen something breaks\n";
list( $cases, $store, $t1, $t2 ) = fh_two_sent();
$store->fail['transition']       = true;
$view                            = Customer_Page::handle( $t1['refund'], 'POST', true, $cases, $now );
check_same( 'the choice could not be saved: the page says it was NOT recorded', array( 'error', 500 ), array( $view['state'], $view['http'] ) );
check_contains( '…', 'your choice was NOT recorded', Customer_Page::render( $view ) );
$store->fail = array();

Customer_Page::handle( $t2['credit'], 'POST', true, $cases, $now );
unset( $GLOBALS['fh_orders'][503] );
$view = Customer_Page::handle( $t2['refund'], 'GET', false, $cases, $now );
check_same( 'the order of an ANSWERED case is gone: its links still say what was chosen, with the amount the e-mail promised', array( 'chosen', 'credit', 6000 ), array( $view['state'], $view['row']['choice'], $view['row']['amount'] ) );
check( '… and the page renders', false !== strpos( Customer_Page::render( $view ), 'Take a store credit — $60.00' ) );

echo "\nAn ordinary request\n";
$_GET  = array();
$_POST = array();
ob_start();
Customer_Page::maybe_render();
check_same( 'without the query argument the hook returns at once: no output, no exit', '', ob_get_clean() );

fh_finish();
