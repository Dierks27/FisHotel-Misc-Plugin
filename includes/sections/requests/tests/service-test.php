<?php
/**
 * The Service on an in-memory store: a request by hand, a sheet from a
 * private link (fishotel_requests_create() has its own file,
 * create-test.php), the approve flow with and without a fee (one invoice for several
 * requests), Decline with and without an e-mail, the ONE reminder and the
 * expiry, the payment (once, however often it is reported), a changed
 * fee, cancel / reopen / fill, "still want it?" with keep and expiry, an
 * edit, what each e-mail contains and when it goes out, and the log.
 *
 * Run from the plugin root:  php includes/sections/requests/tests/service-test.php
 */

require_once __DIR__ . '/wp-stubs.php';

use FisHotel\Misc\Sections\Requests\Rules;
use FisHotel\Misc\Sections\Requests\Service;
use FisHotel\Misc\Sections\Requests\Tokens;
use FisHotel\Misc\Sections\Requests\Urls;

$GLOBALS['fh_users'] = array(
	7 => array( 'login' => 'pat', 'email' => 'pat@example.test', 'first' => 'Pat', 'last' => 'Example', 'meta' => array( '_fishotel_humble_username' => 'reef_pat' ) ),
	8 => array( 'login' => 'sam', 'email' => 'sam@example.test', 'display' => 'Sam Sample' ),
);
$sizes = array(
	array( 'slug' => '1-2', 'label' => '1 - 2"' ),
	array( 'slug' => '2-3-5', 'label' => '2 - 3.5"' ),
);

/**
 * A live private link on a service's store.
 */
function fh_link( $service, array $more = array() ) {
	$id = $service->store->insert_link( array_merge( array( 'token' => Tokens::link(), 'label' => 'Test batch' ), $more ) );
	return $service->store->link( $id );
}

/* ================================================================== */
echo "By hand\n";
/* ================================================================== */

$service = fh_service();
$store   = $service->store;
fh_take_mails();

$id = $service->create( array( 'name' => 'Lee Local', 'fish' => 'Blue Test Wrasse', 'qty' => 2, 'came_from' => 'in person', 'sizes' => $sizes, 'size_slug' => '1-2' ), fh_staff() );
check( 'a request by hand is created', is_int( $id ) && $id > 0 );
$r = $store->get( $id );
check_same( 'approved as it is saved: on the list', 'waiting', $r['state'] );
check( 'with its approval time', null !== $r['approved_at'] );
check_same( 'source and where it came from', array( 'hand', 'in person' ), array( $r['source'], $r['came_from'] ) );
check_same( 'the size and its label', array( '1-2', '1 - 2"' ), array( $r['size_slug'], $r['size_label'] ) );
check_same( 'it groups under its name', 't:blue test wrasse', $r['fish_key'] );
check_same( 'NOTHING is e-mailed for a request by hand without a fee', array(), fh_take_mails() );
check_same( 'the log says what happened and who did it', array( 'created', 'shopkeeper', 1 ), array( $store->log[0]['action'], $store->log[0]['actor'], $store->log[0]['user_id'] ) );
check_contains( 'in words', 'approved as saved, no fee', $store->log[0]['note'] );

check( 'a "came from" that is not in the list is not kept', '' === $store->get( $service->create( array( 'name' => 'Lee', 'fish' => 'X', 'came_from' => 'telepathy' ) ) )['came_from'] );

$error = $service->create( array( 'fish' => 'Blue Test Wrasse' ), fh_staff() );
check( 'no name: a WP_Error, nothing saved', is_wp_error( $error ) && 2 === count( $store->rows ) );
check_same( 'with a code other code can test', 'fishotel_requests_invalid', is_wp_error( $error ) ? $error->get_error_code() : '' );
check( 'no fish: refused', is_wp_error( $service->create( array( 'name' => 'Lee' ) ) ) );
check( 'a bad quantity: refused', is_wp_error( $service->create( array( 'name' => 'Lee', 'fish' => 'X', 'qty' => 0 ) ) ) );
check( 'a size outside the list: refused', is_wp_error( $service->create( array( 'name' => 'Lee', 'fish' => 'X', 'sizes' => $sizes, 'size_slug' => 'huge' ) ) ) );
check( 'a customer that does not exist: refused', is_wp_error( $service->create( array( 'user_id' => 999, 'fish' => 'X' ) ) ) );

$id = $service->create( array( 'user_id' => 7, 'fish' => 'Red Test Goby' ), fh_staff() );
$r  = $store->get( $id );
check_same( 'a picked site customer lends name, e-mail and handle', array( 7, 'Pat Example', 'pat@example.test', 'reef_pat' ), array( $r['user_id'], $r['name'], $r['email'], $r['handle'] ) );

$id = $service->create( array( 'name' => 'Sam', 'email' => 'SAM@example.test', 'fish' => 'Red Test Goby' ), fh_staff() );
check_same( 'an address the shop typed joins the account that has it', 8, $store->get( $id )['user_id'] );

$GLOBALS['fh_known_fish'] = array( 'green test tang' => array( 'species_id' => 31, 'product_id' => 501 ) );
$id                       = $service->create( array( 'name' => 'Lee', 'fish' => 'Green Test Tang' ), fh_staff() );
$r                        = $store->get( $id );
check_same( 'a name that IS a species-list fish is matched to it', array( 31, 501, 's:31' ), array( $r['species_id'], $r['product_id'], $r['fish_key'] ) );

echo "\nBy hand, with a fee\n";
fh_take_mails();
$id = $service->create( array( 'name' => 'Lee Local', 'email' => 'lee@example.test', 'fish' => 'Blue Test Wrasse', 'fee' => '12.50' ), fh_staff() );
$r  = $store->get( $id );
check_same( 'it waits for its payment', 'awaiting_payment', $r['state'] );
check_same( 'it carries its own invoice', array( $id, 12.5 ), array( $r['fee_ref'], $r['fee_amount'] ) );
$mails = fh_take_mails();
check_same( 'ONE e-mail goes out: the invoice', 1, count( $mails ) );
check_same( 'to the person', 'lee@example.test', $mails[0]['to'] );
check_contains( 'it names the fish', '1 × Blue Test Wrasse', $mails[0]['alt'] );
check_contains( 'the amount', '$12.50', $mails[0]['alt'] );
check_contains( 'the signed pay link', Urls::pay( $id, 12.5 ), $mails[0]['alt'] );
check_contains( 'and where the money goes', 'wallet', $mails[0]['alt'] );
check_contains( 'the HTML part links the pay link', 'href="' . Urls::pay( $id, 12.5 ) . '"', $mails[0]['message'] );
check_contains( 'the HTML header', 'text/html', implode( ' ', (array) $mails[0]['headers'] ) );
check_contains( 'a personal link to see and cancel (no account)', Urls::person( 'lee@example.test' ), $mails[0]['alt'] );
check_contains( 'the send is logged on the request', 'Invoice for the fee', implode( ' | ', $store->notes( $id ) ) );

check( 'a fee without an e-mail address: refused', is_wp_error( $service->create( array( 'name' => 'Lee', 'fish' => 'X', 'fee' => 5 ), fh_staff() ) ) );
$GLOBALS['fh_fees'] = array( 'available' => false, 'reason' => 'Batch Manager is not active' );
$error              = $service->create( array( 'name' => 'Lee', 'email' => 'lee@example.test', 'fish' => 'X', 'fee' => 5 ), fh_staff() );
check( 'a fee while fees are unavailable: refused', is_wp_error( $error ) );
check_contains( 'and it says why', 'Batch Manager is not active', is_wp_error( $error ) ? $error->get_error_message() : '' );
check( 'without a fee the same request is fine', is_int( $service->create( array( 'name' => 'Lee', 'email' => 'lee@example.test', 'fish' => 'X' ), fh_staff() ) ) );

/* ================================================================== */
echo "\nA sheet from a private link\n";
/* ================================================================== */

$service = fh_service();
$store   = $service->store;
$link    = fh_link( $service, array( 'fee_amount' => 12.0, 'max_uses' => 2 ) );
fh_take_mails();

$person = array( 'name' => 'Pat Example', 'email' => 'pat@example.test', 'handle' => 'reef_pat' );
$sheet  = array(
	array( 'fish' => 'Blue Test Wrasse', 'size_slug' => '2-3-5', 'qty' => '2', 'note' => 'A pair.' ),
	array( 'fish' => '', 'note' => '' ),
	array( 'fish' => 'Red Test Goby', 'qty' => '1' ),
);
$result = $service->submit( $person, $sheet, array( 'source' => 'link', 'link' => $link, 'user_id' => 0, 'fee' => 12.0, 'sizes' => $sizes ) );
check( 'the sheet is taken', $result['ok'] && 2 === count( $result['ids'] ) );
list( $a, $b ) = $result['ids'];
check_same( 'each fish is a request in state "new"', array( 'new', 'new' ), array( $store->get( $a )['state'], $store->get( $b )['state'] ) );
check_same( 'from the link', array( 'link', $link['id'] ), array( $store->get( $a )['source'], $store->get( $a )['link_id'] ) );
check( 'both carry the same sheet key', '' !== $store->get( $a )['sheet'] && $store->get( $a )['sheet'] === $store->get( $b )['sheet'] );
check_same( 'the link\'s fee is only the proposal: nothing is invoiced yet', array( 12.0, null ), array( $store->get( $a )['fee_amount'], $store->get( $a )['fee_ref'] ) );
check_same( 'an address typed by a visitor joins no account by itself', 0, $store->get( $a )['user_id'] );
check_same( 'the link counted ONE use for the sheet', 1, $store->link( $link['id'] )['uses'] );
check_same( 'no e-mail goes out on a new request', array(), fh_take_mails() );
check_same( 'written by the customer', 'customer', $store->log[0]['actor'] );

$bad = $service->submit( array( 'name' => 'Pat', 'email' => 'nope' ), array( array( 'fish' => 'A', 'size_slug' => 'huge' ), array( 'fish' => '', 'note' => 'what?' ) ), array( 'source' => 'link', 'link' => $store->link( $link['id'] ), 'sizes' => $sizes ) );
check( 'a sheet with mistakes creates NOTHING', ! $bad['ok'] && 2 === count( $store->rows ) );
check( 'and names each mistake', count( $bad['errors'] ) >= 3 );
check_contains( 'by row', 'Fish 1:', implode( ' ', $bad['errors'] ) );
check_same( 'and does not count a use', 1, $store->link( $link['id'] )['uses'] );
check( 'an empty sheet is refused', ! $service->submit( $person, array(), array( 'source' => 'link', 'link' => $store->link( $link['id'] ) ) )['ok'] );
check( 'more than ten fish are refused', ! $service->submit( $person, array_fill( 0, 11, array( 'fish' => 'A' ) ), array( 'source' => 'link', 'link' => $store->link( $link['id'] ) ) )['ok'] );

check( 'the second use is taken', $service->submit( $person, array( array( 'fish' => 'Green Test Tang' ) ), array( 'source' => 'link', 'link' => $store->link( $link['id'] ), 'sizes' => $sizes ) )['ok'] );
$full = $service->submit( $person, array( array( 'fish' => 'Green Test Tang' ) ), array( 'source' => 'link', 'link' => $store->link( $link['id'] ), 'sizes' => $sizes ) );
check( 'the link is used up after its maximum uses', ! $full['ok'] && 3 === count( $store->rows ) );
check_contains( 'and says so', 'used as often', implode( ' ', $full['errors'] ) );

$stale = $store->link( $link['id'] );
$store->links[ $link['id'] ]['max_uses'] = 3;
$store->links[ $link['id'] ]['uses']     = 3;
check( 'a use somebody else took between the page and the click is noticed', ! $service->submit( $person, array( array( 'fish' => 'A' ) ), array( 'source' => 'link', 'link' => array_merge( $stale, array( 'max_uses' => 3, 'uses' => 2 ) ) ) )['ok'] );

$expired = fh_link( $service, array( 'expires_at' => Rules::utc( $GLOBALS['fh_clock'] - 60 ) ) );
check_contains( 'an expired link refuses the sheet', 'expired', implode( ' ', $service->submit( $person, array( array( 'fish' => 'A' ) ), array( 'source' => 'link', 'link' => $expired ) )['errors'] ) );
$off = fh_link( $service );
$store->set_link_active( $off['id'], false );
check_contains( 'a link that was switched off refuses the sheet', 'switched off', implode( ' ', $service->submit( $person, array( array( 'fish' => 'A' ) ), array( 'source' => 'link', 'link' => $store->link( $off['id'] ) ) )['errors'] ) );

$GLOBALS['fh_current_user'] = 7;
$mine                       = $service->submit( $person, array( array( 'fish' => 'Yellow Test Blenny' ) ), array( 'source' => 'link', 'link' => fh_link( $service ), 'user_id' => 7 ) );
check_same( 'a logged-in visitor\'s request belongs to their account', 7, $store->get( $mine['ids'][0] )['user_id'] );
$GLOBALS['fh_current_user'] = 0;

/* ================================================================== */
echo "\nApprove without a fee\n";
/* ================================================================== */

fh_take_mails();
$result = $service->approve( array( $a ), '', fh_staff() );
check( 'approved', $result['ok'] );
$r = $store->get( $a );
check_same( 'on the list', 'waiting', $r['state'] );
check_same( 'no fee on it', array( 0.0, null ), array( $r['fee_amount'], $r['fee_ref'] ) );
check_same( 'approving vouches for the request: it joins the account of its address', 7, $r['user_id'] );
check_same( 'no e-mail', array(), fh_take_mails() );
check( 'approving it again does nothing', ! $service->approve( array( $a ), '', fh_staff() )['ok'] );
check_contains( 'the log', 'Approved without a fee', implode( ' | ', $store->notes( $a ) ) );

/* ================================================================== */
echo "\nApprove with a fee — one invoice for a sheet\n";
/* ================================================================== */

$service = fh_service();
$store   = $service->store;
$link    = fh_link( $service, array( 'fee_amount' => 12.0 ) );
$result  = $service->submit( $person, array( array( 'fish' => 'Blue Test Wrasse', 'qty' => 2 ), array( 'fish' => 'Red Test Goby' ), array( 'fish' => 'Green Test Tang' ) ), array( 'source' => 'link', 'link' => $link, 'fee' => 12.0 ) );
list( $a, $b, $c ) = $result['ids'];
fh_take_mails();

check( 'a bad fee approves nothing', ! $service->approve( array( $a, $b ), 'lots', fh_staff() )['ok'] && 'new' === $store->get( $a )['state'] );
$GLOBALS['fh_fees'] = array( 'available' => false, 'reason' => 'Batch Manager is not active' );
$refused            = $service->approve( array( $a, $b ), '12', fh_staff() );
check( 'a fee while fees are unavailable approves nothing', ! $refused['ok'] && 'new' === $store->get( $a )['state'] );
check_contains( 'and says why', 'Batch Manager is not active', $refused['message'] );
$GLOBALS['fh_fees'] = array( 'available' => true, 'reason' => '', 'product_id' => 9001 );

$other = $service->submit( array( 'name' => 'Sam', 'email' => 'sam@example.test' ), array( array( 'fish' => 'Red Test Goby' ) ), array( 'source' => 'link', 'link' => $link ) );
check( 'one invoice cannot go to two people', ! $service->approve( array( $a, $other['ids'][0] ), '12', fh_staff() )['ok'] && 'new' === $store->get( $a )['state'] );

$result = $service->approve( array( $b, $a ), '18.50', fh_staff() );
check( 'two requests approved with ONE fee', $result['ok'] && 2 === count( $result['ids'] ) );
check_same( 'both wait for the payment', array( 'awaiting_payment', 'awaiting_payment' ), array( $store->get( $a )['state'], $store->get( $b )['state'] ) );
check_same( 'the oldest carries the invoice (the fee can be set at approval)', array( $a, 18.5 ), array( $store->get( $a )['fee_ref'], $store->get( $a )['fee_amount'] ) );
check_same( 'the other points at it and carries no amount', array( $a, 0.0 ), array( $store->get( $b )['fee_ref'], $store->get( $b )['fee_amount'] ) );
check_same( 'the third request is untouched', 'new', $store->get( $c )['state'] );
$mails = fh_take_mails();
check_same( 'ONE invoice e-mail', 1, count( $mails ) );
check_contains( 'listing both fish', "2 × Blue Test Wrasse\n1 × Red Test Goby", $mails[0]['alt'] );
check_contains( 'for the fee that was set', '$18.50', $mails[0]['alt'] );
check_contains( 'with the pay link of the carrier', Urls::pay( $a, 18.5 ), $mails[0]['alt'] );
check_contains( 'due within the expiry days', 'within 7 days', $mails[0]['alt'] );
check_contains( 'a customer with an account is sent to their own list', 'https://example.test/?fh_requests=', $mails[0]['alt'] );
check_same( 'the state change is announced', true, fh_fired( 'fishotel_requests_state_changed' ) >= 2 );

$state = $service->payable( $a, 18.5 );
check( 'the invoice can be paid', $state['ok'] && 2 === count( $state['members'] ) );
check_same( 'not with another amount', 'changed', $service->payable( $a, 12.0 )['why'] );
check_same( 'a request that carries nothing is no invoice', 'missing', $service->payable( $b )['why'] );

echo "\nThe reminder and the expiry\n";
fh_days_pass( 2 );
$timers = $service->timers();
check_same( 'nothing after two days', array( 0, 0 ), array( $timers['reminded'], $timers['expired'] ) );
check_same( 'and no e-mail', array(), fh_take_mails() );

fh_days_pass( 1 );
$timers = $service->timers();
check_same( 'ONE reminder after three days', 1, $timers['reminded'] );
$mails = fh_take_mails();
check_same( 'one e-mail for the invoice, not one per request', 1, count( $mails ) );
check_contains( 'it is the reminder', 'reminder', $mails[0]['subject'] );
check_contains( 'with the same pay link', Urls::pay( $a, 18.5 ), $mails[0]['alt'] );
check_contains( 'and the days that are left', 'within 4 more days', $mails[0]['alt'] );
check( 'the reminder is recorded', null !== $store->get( $a )['reminded_at'] );
check_contains( 'and logged', 'Fee reminder', implode( ' | ', $store->notes( $a ) ) );

fh_days_pass( 1 );
check_same( 'no second reminder the next day', 0, $service->timers()['reminded'] );
check_same( 'no e-mail', array(), fh_take_mails() );

fh_days_pass( 3 );
$timers = $service->timers();
check_same( 'after seven days both requests expire', 2, $timers['expired'] );
check_same( 'state', array( 'expired', 'expired' ), array( $store->get( $a )['state'], $store->get( $b )['state'] ) );
check_contains( 'logged with the reason', 'not paid within 7 days', implode( ' | ', $store->notes( $a ) ) );
check_same( 'nobody is e-mailed about an expiry', array(), fh_take_mails() );
check_same( 'the pay link no longer works', 'closed', $service->payable( $a, 18.5 )['why'] );

echo "\nPaid late: the payment brings them back\n";
$paid = $service->paid( $a, array( 'order_id' => 900, 'amount' => 18.5, 'user_id' => 7, 'wallet' => true ) );
check( 'a payment that arrives after the expiry is honoured', $paid['ok'] && 2 === count( $paid['ids'] ) );
check_same( 'both are on the list', array( 'waiting', 'waiting' ), array( $store->get( $a )['state'], $store->get( $b )['state'] ) );
check_contains( 'and the log says so', 'run out of time', implode( ' | ', $store->notes( $b ) ) );

/* ================================================================== */
echo "\nPaid\n";
/* ================================================================== */

$service = fh_service();
$store   = $service->store;
$link    = fh_link( $service );
$result  = $service->submit( array( 'name' => 'Noah Newcomer', 'email' => 'noah@example.test' ), array( array( 'fish' => 'Blue Test Wrasse' ), array( 'fish' => 'Red Test Goby' ) ), array( 'source' => 'link', 'link' => $link ) );
list( $a, $b ) = $result['ids'];
$service->approve( array( $a, $b ), '12', fh_staff() );
fh_take_mails();
$GLOBALS['fh_fired'] = array();

$paid = $service->paid( $a, array( 'order_id' => 555, 'amount' => 12.0, 'user_id' => 8, 'wallet' => true ) );
check( 'the payment is recorded', $paid['ok'] );
check_same( 'both requests of the invoice are on the list', array( 'waiting', 'waiting' ), array( $store->get( $a )['state'], $store->get( $b )['state'] ) );
check( 'the fee is marked paid on the carrier', null !== $store->get( $a )['fee_paid_at'] );
check_same( 'with its order', 555, $store->get( $a )['fee_order_id'] );
check_same( 'a request without an account joins the account that paid', array( 8, 8 ), array( $store->get( $a )['user_id'], $store->get( $b )['user_id'] ) );
check_contains( 'the log names the order and the wallet', 'order #555', implode( ' | ', $store->notes( $a ) ) );
check_contains( 'credited by Batch Manager', 'credited by Batch Manager', implode( ' | ', $store->notes( $a ) ) );
check_contains( 'the second request says what covered it', 'Covered by the fee of request #' . $a, implode( ' | ', $store->notes( $b ) ) );
check_same( 'the paid hook fired once', 1, fh_fired( 'fishotel_requests_fee_paid' ) );
check_same( 'no e-mail for a payment', array(), fh_take_mails() );

$again = $service->paid( $a, array( 'order_id' => 555, 'amount' => 12.0, 'user_id' => 8, 'wallet' => true ) );
check( 'reported a second time (another payment hook): nothing happens twice', $again['ok'] && array() === $again['ids'] && 1 === fh_fired( 'fishotel_requests_fee_paid' ) );
check_same( 'the log has ONE paid line', 1, count( array_keys( $store->actions( $a ), 'paid' ) ) );
check( 'a request that carries no invoice cannot be paid', ! $service->paid( $b )['ok'] );
check_same( 'a paid invoice cannot be paid again', 'paid', $service->payable( $a, 12.0 )['why'] );

$id = $service->create( array( 'name' => 'Lee', 'email' => 'lee@example.test', 'fish' => 'X', 'fee' => 9 ), fh_staff() );
$service->paid( $id, array( 'order_id' => 556, 'amount' => 9.0, 'user_id' => 0, 'wallet' => false ) );
check_contains( 'a wallet that was NOT credited is said plainly', 'did NOT credit a wallet', implode( ' | ', $store->notes( $id ) ) );
$id = $service->create( array( 'name' => 'Lee', 'email' => 'lee@example.test', 'fish' => 'Y', 'fee' => 9 ), fh_staff() );
$service->paid( $id, array( 'actor' => fh_staff() ) );
check_contains( 'marked paid by hand says nothing went to a wallet', 'by hand', implode( ' | ', $store->notes( $id ) ) );
check_same( 'and it is on the list', 'waiting', $store->get( $id )['state'] );

echo "\nA changed fee\n";
$id = $service->create( array( 'name' => 'Lee', 'email' => 'lee@example.test', 'fish' => 'Z', 'fee' => 9 ), fh_staff() );
fh_take_mails();
fh_days_pass( 2 );
$result = $service->set_fee( $id, '15', fh_staff() );
check( 'the fee is changed', $result['ok'] && 15.0 === $store->get( $id )['fee_amount'] );
$mails = fh_take_mails();
check_same( 'the invoice goes out again', 1, count( $mails ) );
check_contains( 'with the new amount', '$15.00', $mails[0]['alt'] );
check_contains( 'and a new pay link', Urls::pay( $id, 15.0 ), $mails[0]['alt'] );
check_same( 'the OLD pay link is refused', 'changed', $service->payable( $id, 9.0 )['why'] );
check_same( 'the clock starts again', Rules::utc( $GLOBALS['fh_clock'] ), $store->get( $id )['approved_at'] );
check( 'a bad fee changes nothing', ! $service->set_fee( $id, 'x', fh_staff() )['ok'] && 15.0 === $store->get( $id )['fee_amount'] );
$result = $service->set_fee( $id, '0', fh_staff() );
check( 'a fee of 0 removes it: on the list', $result['ok'] && 'waiting' === $store->get( $id )['state'] && null === $store->get( $id )['fee_ref'] );
check( 'the fee of a request that is on the list cannot be changed', ! $service->set_fee( $id, '5', fh_staff() )['ok'] );

echo "\nThe invoice e-mail switched off, or failing\n";
$service = fh_service( array( 'mail' => array( 'invoice' => array( 'on' => 0 ) ) ) );
$store   = $service->store;
fh_take_mails();
$id = $service->create( array( 'name' => 'Lee', 'email' => 'lee@example.test', 'fish' => 'X', 'fee' => 9 ), fh_staff() );
check_same( 'switched off: nothing is sent', array(), fh_take_mails() );
check_same( 'the request still waits for its payment', 'awaiting_payment', $store->get( $id )['state'] );
check_same( 'and the service says the e-mail is off', 'off', $service->last_mail );
check_contains( 'in words the owner reads', 'switched off', Service::mail_words( 'off', 'The invoice', true ) );

$service                  = fh_service();
$store                    = $service->store;
$GLOBALS['fh_mail_fails'] = true;
$id                       = $service->create( array( 'name' => 'Lee', 'email' => 'lee@example.test', 'fish' => 'X', 'fee' => 9 ), fh_staff() );
check_same( 'a send the site refuses is reported', 'failed', $service->last_mail );
check_contains( 'and logged on the request', 'could NOT be sent', implode( ' | ', $store->notes( $id ) ) );
$GLOBALS['fh_mail_fails'] = false;
fh_take_mails();

/* ================================================================== */
echo "\nDecline\n";
/* ================================================================== */

$service = fh_service();
$store   = $service->store;
$link    = fh_link( $service );
$ids     = $service->submit( $person, array( array( 'fish' => 'Blue Test Wrasse' ), array( 'fish' => 'Red Test Goby' ) ), array( 'source' => 'link', 'link' => $link ) )['ids'];
fh_take_mails();

$result = $service->decline( $ids[0], 'We cannot get this one.', false, fh_staff() );
check( 'declined', $result['ok'] && 'cancelled' === $store->get( $ids[0] )['state'] );
check_same( 'NO e-mail unless it is ticked', array(), fh_take_mails() );
check_contains( 'the log keeps the reason', 'We cannot get this one.', implode( ' | ', $store->notes( $ids[0] ) ) );
check_same( 'as a "declined" line', 'declined', $store->actions( $ids[0] )[1] );

$result = $service->decline( $ids[1], 'Not available to us.', true, fh_staff() );
$mails  = fh_take_mails();
check_same( 'ticked: one e-mail', 1, count( $mails ) );
check_contains( 'with the note', 'Not available to us.', $mails[0]['alt'] );
check_contains( 'and the fish', 'Red Test Goby', $mails[0]['alt'] );
check( 'only a new request can be declined', ! $service->decline( $ids[1], '', false, fh_staff() )['ok'] );

/* ================================================================== */
echo "\nCancel, reopen, fill\n";
/* ================================================================== */

$service = fh_service();
$store   = $service->store;
$id      = $service->create( array( 'user_id' => 7, 'fish' => 'Blue Test Wrasse' ), fh_staff() );
$store->update( $id, array( 'line_id' => 5, 'cr_order_id' => 2, 'state' => 'on_order' ) );

check( 'the person cancels their own request', $service->cancel( $id, Service::customer( 7 ), '', true )['ok'] );
$r = $store->get( $id );
check_same( 'cancelled, and off its supplier order', array( 'cancelled', null, null ), array( $r['state'], $r['line_id'], $r['cr_order_id'] ) );
check_contains( 'the log says who', 'by the person who asked', implode( ' | ', $store->notes( $id ) ) );
check( 'a closed request cannot be cancelled again', ! $service->cancel( $id, Service::customer( 7 ), '', true )['ok'] );

check( 'reopened', $service->reopen( $id, fh_staff() )['ok'] && 'waiting' === $store->get( $id )['state'] );
check( 'an open request cannot be reopened', ! $service->reopen( $id, fh_staff() )['ok'] );

$owed = $service->create( array( 'user_id' => 7, 'fish' => 'Red Test Goby', 'source' => 'lost_fish' ) );
$no   = $service->cancel( $owed, Service::customer( 7 ), '', true );
check( 'the person cannot cancel an OWED request themselves', ! $no['ok'] && 'waiting' === $store->get( $owed )['state'] );
check_contains( 'they are told to get in touch', 'contact us', $no['message'] );
check( 'the shop can', $service->cancel( $owed, fh_staff(), 'Refunded instead.' )['ok'] && 'cancelled' === $store->get( $owed )['state'] );

check( 'filled by the owner', $service->fill( $id, fh_staff(), 'Picked up in person.' )['ok'] && 'filled' === $store->get( $id )['state'] );
check( 'with its closing time', null !== $store->get( $id )['closed_at'] );
check( 'a new request cannot be filled', ! $service->fill( $service->submit( $person, array( array( 'fish' => 'A' ) ), array( 'source' => 'link', 'link' => fh_link( $service ) ) )['ids'][0], fh_staff() )['ok'] );

$unpaid = $service->create( array( 'name' => 'Lee', 'email' => 'lee@example.test', 'fish' => 'X', 'fee' => 9 ), fh_staff() );
$service->cancel( $unpaid, fh_staff() );
$service->reopen( $unpaid, fh_staff() );
check_same( 'reopening a request whose fee was never paid drops the fee', array( 'waiting', null, 0.0 ), array( $store->get( $unpaid )['state'], $store->get( $unpaid )['fee_ref'], $store->get( $unpaid )['fee_amount'] ) );

/* ================================================================== */
echo "\nA sheet whose invoice-carrying fish is cancelled\n";
/* ================================================================== */

$service = fh_service();
$store   = $service->store;
$ids     = $service->submit( $person, array( array( 'fish' => 'Blue Test Wrasse' ), array( 'fish' => 'Red Test Goby' ) ), array( 'source' => 'link', 'link' => fh_link( $service ) ) )['ids'];
$service->approve( $ids, '10', fh_staff() );
fh_take_mails();
check( 'the person cancels the fish that carries the invoice', $service->cancel( $ids[0], Service::customer( 7 ), '', true )['ok'] );
check( 'the invoice can still be paid for the other fish', $service->payable( $ids[0], 10.0 )['ok'] );
fh_days_pass( 4 );
$timers = $service->timers();
$mails  = fh_take_mails();
check_same( 'the ONE reminder still goes out', array( 1, 1 ), array( $timers['reminded'], count( $mails ) ) );
check_contains( 'for the fish that still waits', 'Red Test Goby', $mails[0]['alt'] );
check_lacks( 'not for the cancelled one', 'Blue Test Wrasse', $mails[0]['alt'] );
check_same( 'and only once', array( 0, 0 ), array( $service->timers()['reminded'], count( fh_take_mails() ) ) );

$back = $service->reopen( $ids[0], fh_staff() );
check_same( 'reopened while its invoice is still open: it goes back ON the invoice and waits for the payment', array( true, 'awaiting_payment', $ids[0], 10.0 ), array( $back['ok'], $store->get( $ids[0] )['state'], $store->get( $ids[0] )['fee_ref'], $store->get( $ids[0] )['fee_amount'] ) );
check_contains( 'the owner is told so', 'waits for the payment', $back['message'] );
check_same( 'it runs out of time with the other', $store->get( $ids[1] )['approved_at'], $store->get( $ids[0] )['approved_at'] );
check( 'the other fish is not left on an invoice nobody can pay', $service->payable( $ids[0], 10.0 )['ok'] && 2 === count( $service->payable( $ids[0] )['members'] ) );
check( 'the invoice can be sent again', $service->resend_invoice( $ids[1], fh_staff() )['ok'] );
fh_take_mails();
$service->cancel( $ids[1], Service::customer( 7 ), '', true );
check_same( 'a fish that does NOT carry the invoice comes back onto it the same way', 'awaiting_payment', $service->reopen( $ids[1], fh_staff() )['ok'] ? $store->get( $ids[1] )['state'] : 'refused' );
$service->paid( $ids[0], array( 'order_id' => 90, 'amount' => 10.0, 'user_id' => 7, 'wallet' => true ) );
check_same( 'paid: both are on the list', array( 'waiting', 'waiting' ), array( $store->get( $ids[0] )['state'], $store->get( $ids[1] )['state'] ) );

$service = fh_service();
$store   = $service->store;
$ids     = $service->submit( $person, array( array( 'fish' => 'Blue Test Wrasse' ), array( 'fish' => 'Red Test Goby' ) ), array( 'source' => 'link', 'link' => fh_link( $service ) ) )['ids'];
$service->approve( $ids, '10', fh_staff() );
$service->cancel( $ids[0], fh_staff() );
fh_days_pass( 4 );
$service->timers();
check( 'a changed fee starts the reminder clock again, cancelled carrier or not', $service->set_fee( $ids[1], '15', fh_staff() )['ok'] && null === $store->get( $ids[0] )['reminded_at'] );
fh_take_mails();
fh_days_pass( 3 );
check_same( 'so the new invoice gets its one reminder too', 1, $service->timers()['reminded'] );
fh_days_pass( 5 );
$service->timers();
check_same( 'the invoice ran out of time: everything on it is closed', array( 'cancelled', 'expired' ), array( $store->get( $ids[0] )['state'], $store->get( $ids[1] )['state'] ) );
$service->reopen( $ids[0], fh_staff() );
check_same( 'reopened after that, the fee is dropped as before', array( 'waiting', null, 0.0 ), array( $store->get( $ids[0] )['state'], $store->get( $ids[0] )['fee_ref'], $store->get( $ids[0] )['fee_amount'] ) );
fh_take_mails();

/* ================================================================== */
echo "\nBy hand: several fish for one person, ONE invoice\n";
/* ================================================================== */

$service = fh_service();
$store   = $service->store;
$first   = $service->create( array( 'user_id' => 7, 'fish' => 'Blue Test Wrasse', 'fee' => '25' ), fh_staff() );
check_same( 'the first fish carries the invoice, and it is e-mailed', 1, count( fh_take_mails() ) );
fh_days_pass( 1 );
$second = $service->create( array( 'user_id' => 7, 'fish' => 'Red Test Goby', 'fee' => '25', 'invoice_of' => $first ), fh_staff() );
$r      = $store->get( $second );
check_same( 'the second fish goes on that invoice: no fee of its own', array( 'awaiting_payment', $first, 0.0 ), array( $r['state'], $r['fee_ref'], $r['fee_amount'] ) );
check_same( 'nothing is e-mailed again', 0, count( fh_take_mails() ) );
check_same( 'it runs out of time with the first', $store->get( $first )['approved_at'], $r['approved_at'] );
check_contains( 'its history says whose invoice', 'on the invoice of request #' . $first . ' ($25.00, not paid yet)', implode( ' | ', $store->notes( $second ) ) );
check_same( 'the invoice names both fish', 2, count( $service->payable( $first, 25.0 )['members'] ) );
$other = $service->create( array( 'user_id' => 8, 'fish' => 'Green Test Tang', 'invoice_of' => $first ), fh_staff() );
check( 'a fish for ANOTHER person is refused on that invoice', is_wp_error( $other ) );
check_contains( 'and it says why', 'One invoice goes to one person', is_wp_error( $other ) ? $other->get_error_message() : '' );
$service->paid( $first, array( 'order_id' => 91, 'amount' => 25.0, 'user_id' => 7, 'wallet' => true ) );
check_same( 'one payment puts both on the list', array( 'waiting', 'waiting' ), array( $store->get( $first )['state'], $store->get( $second )['state'] ) );
$late = $service->create( array( 'user_id' => 7, 'fish' => 'Green Test Tang', 'invoice_of' => $first ), fh_staff() );
check( 'a paid invoice takes no more fish, and nothing is saved', is_wp_error( $late ) && 2 === count( $store->rows ) );
$owed = $service->create( array( 'user_id' => 7, 'fish' => 'Green Test Tang', 'source' => 'lost_fish', 'invoice_of' => $first ) );
check_same( 'an owed fish never goes on an invoice', array( 'waiting', null ), array( $store->get( $owed )['state'], $store->get( $owed )['fee_ref'] ) );
fh_take_mails();

/* ================================================================== */
echo "\nStill want it?\n";
/* ================================================================== */

$service = fh_service();
$store   = $service->store;
$link    = fh_link( $service );
$ids     = $service->submit( $person, array( array( 'fish' => 'Blue Test Wrasse' ) ), array( 'source' => 'link', 'link' => $link ) )['ids'];
$asked   = $ids[0];
$service->approve( array( $asked ), '', fh_staff() );
$hand = $service->create( array( 'name' => 'Lee', 'email' => 'lee@example.test', 'fish' => 'Red Test Goby' ), fh_staff() );
$lost = $service->create( array( 'user_id' => 7, 'fish' => 'Green Test Tang', 'source' => 'lost_fish' ) );
fh_take_mails();

fh_days_pass( 100 );
check_same( 'nobody is asked after three months', 0, $service->timers()['asked'] );
fh_days_pass( 25 );
$timers = $service->timers();
check_same( 'after four months the link request is asked', 1, $timers['asked'] );
$mails = fh_take_mails();
check_same( 'ONE e-mail: nothing for the request by hand, nothing for the owed fish', 1, count( $mails ) );
check_same( 'to the link request\'s person', 'pat@example.test', $mails[0]['to'] );
check_contains( 'with the fish', 'Blue Test Wrasse', $mails[0]['alt'] );
check_contains( 'a keep link', Urls::answer( $store->get( $asked ), 'keep' ), $mails[0]['alt'] );
check_contains( 'a cancel link', Urls::answer( $store->get( $asked ), 'cancel' ), $mails[0]['alt'] );
check_contains( 'and the 14 days', '14 days', $mails[0]['alt'] );
check( 'the asking is recorded', null !== $store->get( $asked )['asked_at'] );
check_same( 'and logged', 'asked', $store->actions( $asked )[ count( $store->actions( $asked ) ) - 1 ] );

fh_days_pass( 5 );
check_same( 'not asked a second time', 0, $service->timers()['asked'] );
check( 'the person says "keep it"', $service->keep( $asked, Service::customer() )['ok'] );
check_same( 'the question is answered', null, $store->get( $asked )['asked_at'] );
fh_days_pass( 30 );
$timers = $service->timers();
check_same( 'kept: it does not expire', array( 0, 'waiting' ), array( $timers['ask_expired'], $store->get( $asked )['state'] ) );

fh_days_pass( 100 );
check_same( 'four months after "keep it" the person is asked again', 1, $service->timers()['asked'] );
fh_take_mails();
fh_days_pass( 13 );
check_same( 'no answer for 13 days: still waiting', 0, $service->timers()['ask_expired'] );
fh_days_pass( 1 );
$timers = $service->timers();
check_same( 'no answer in 14 days: expired', array( 1, 'expired' ), array( $timers['ask_expired'], $store->get( $asked )['state'] ) );
check_contains( 'logged with the reason', 'No answer', implode( ' | ', $store->notes( $asked ) ) );
check_same( 'the request by hand and the owed fish are still waiting', array( 'waiting', 'waiting' ), array( $store->get( $hand )['state'], $store->get( $lost )['state'] ) );
check_same( 'and nobody is e-mailed about the expiry', array(), fh_take_mails() );
check( '"keep it" on a closed request is refused kindly', ! $service->keep( $asked, Service::customer() )['ok'] );

$service = fh_service( array( 'mail' => array( 'still_want' => array( 'on' => 0 ) ) ) );
$ids     = $service->submit( $person, array( array( 'fish' => 'Blue Test Wrasse' ) ), array( 'source' => 'link', 'link' => fh_link( $service ) ) )['ids'];
$service->approve( $ids, '', fh_staff() );
fh_days_pass( 400 );
$timers = $service->timers();
check_same( 'with that e-mail switched off nobody is asked and nothing expires', array( 0, 0, 'waiting' ), array( $timers['asked'], $timers['ask_expired'], $service->store->get( $ids[0] )['state'] ) );
check_same( 'and nothing is sent', array(), fh_take_mails() );

/* ================================================================== */
echo "\nThe \"ready\" e-mail\n";
/* ================================================================== */

$service = fh_service();
$store   = $service->store;
$id      = $service->create( array( 'user_id' => 7, 'fish' => 'Blue Test Wrasse', 'product_id' => 501 ), fh_staff() );
check( 'a request that is not ready cannot be offered', ! $service->offer( $id, fh_staff() )['ok'] );
$store->update( $id, array( 'state' => 'ready' ) );
fh_take_mails();
$result = $service->offer( $id, Service::system() );
check( 'offered', $result['ok'] && 'offered' === $store->get( $id )['state'] && null !== $store->get( $id )['offered_at'] );
$mails = fh_take_mails();
check_same( 'one e-mail', 1, count( $mails ) );
check_contains( 'it says the fish is ready', 'ready', $mails[0]['subject'] );
check_contains( 'with the product link', 'https://example.test/product/501/', $mails[0]['alt'] );
check_contains( 'logged as told by itself', 'by itself', implode( ' | ', $store->notes( $id ) ) );
check( 'offering twice sends nothing twice', ! $service->offer( $id, Service::system() )['ok'] && array() === fh_take_mails() );

$id = $service->create( array( 'user_id' => 7, 'fish' => 'Unlisted Test Fish' ), fh_staff() );
$store->update( $id, array( 'state' => 'ready' ) );
$service->offer( $id, fh_staff() );
$mails = fh_take_mails();
check_contains( 'without a product the person is asked to reply', 'Reply to this e-mail', $mails[0]['alt'] );
check_lacks( 'and no empty link is printed', 'buy it here', $mails[0]['alt'] );

$id = $service->create( array( 'user_id' => 7, 'fish' => 'Green Test Tang', 'product_id' => 501, 'source' => 'lost_fish' ) );
$store->update( $id, array( 'state' => 'ready' ) );
$service->offer( $id, Service::system() );
$mails = fh_take_mails();
check_contains( 'an OWED fish: already paid for, nothing to pay', 'already paid for', $mails[0]['alt'] );
check_lacks( 'and no link to buy it', '/product/501/', $mails[0]['alt'] );

$id = $service->create( array( 'name' => 'Lee Local', 'fish' => 'Blue Test Wrasse' ), fh_staff() );
$store->update( $id, array( 'state' => 'ready' ) );
$result = $service->offer( $id, Service::system() );
check( 'no e-mail address: not offered, it stays ready for the owner', ! $result['ok'] && 'ready' === $store->get( $id )['state'] );
check_same( 'and nothing is written to the log for that', 1, count( $store->actions( $id ) ) );
check( 'the owner tells them and clicks "Told them"', $service->told( $id, fh_staff() )['ok'] && 'offered' === $store->get( $id )['state'] );
check_same( 'without an e-mail', array(), fh_take_mails() );

$id = $service->create( array( 'user_id' => 7, 'fish' => 'Blue Test Wrasse' ), fh_staff() );
$store->update( $id, array( 'state' => 'ready' ) );
$GLOBALS['fh_mail_fails'] = true;
$result                   = $service->offer( $id, Service::system() );
$GLOBALS['fh_mail_fails'] = false;
fh_take_mails();
check( 'a send that fails leaves the request ready', ! $result['ok'] && 'ready' === $store->get( $id )['state'] );
check( 'with the time of the attempt, so the job does not try every day', null !== $store->get( $id )['offered_at'] );
check( 'the owner\'s click still sends it', $service->offer( $id, fh_staff() )['ok'] && 1 === count( fh_take_mails() ) );

/* ================================================================== */
echo "\nEdit and note\n";
/* ================================================================== */

$service = fh_service();
$store   = $service->store;
$id      = $service->create( array( 'name' => 'Lee Local', 'fish' => 'Blue Test Wrasse', 'qty' => 1 ), fh_staff() );
$before  = count( $store->log );

check( 'saving without a change writes nothing', $service->edit( $id, array( 'name' => 'Lee Local', 'fish' => 'Blue Test Wrasse', 'qty' => 1 ), $sizes, fh_staff() )['ok'] && count( $store->log ) === $before );
$result = $service->edit( $id, array( 'name' => 'Lee Local', 'email' => 'sam@example.test', 'fish' => 'Green Test Tang', 'qty' => 3, 'size_slug' => '2-3-5', 'came_from' => 'phone', 'note' => 'Called again.' ), $sizes, fh_staff() );
$r      = $store->get( $id );
check( 'the edit is saved', $result['ok'] );
check_same( 'fish, quantity, size', array( 'Green Test Tang', 3, '2-3-5' ), array( $r['fish'], $r['qty'], $r['size_slug'] ) );
check_same( 'a new name is matched afresh', array( 31, 's:31' ), array( $r['species_id'], $r['fish_key'] ) );
check_same( 'the address joins its account', 8, $r['user_id'] );
check_contains( 'the log names what changed', 'qty', implode( ' | ', $store->notes( $id ) ) );
check( 'a bad edit changes nothing', ! $service->edit( $id, array( 'name' => '', 'fish' => '' ), $sizes, fh_staff() )['ok'] && 'Green Test Tang' === $store->get( $id )['fish'] );
check( 'a note is added to the history', $service->note( $id, 'Prefers a small one.', fh_staff() )['ok'] && 'note' === $store->actions( $id )[ count( $store->actions( $id ) ) - 1 ] );

echo "\nA page that was opened before another correction\n";
$form  = array( 'name' => 'Lee Local', 'email' => 'sam@example.test', 'fish' => 'Green Test Tang', 'qty' => 3, 'size_slug' => '2-3-5', 'came_from' => 'phone', 'note' => 'Called again.' );
$tab_a = Rules::seen( $store->get( $id ) );
$tab_b = $tab_a;
check( 'tab A saves a note', $service->edit( $id, array_merge( $form, array( 'note' => 'IMPORTANT: wants a male, call first', 'seen' => $tab_a ) ), $sizes, fh_staff() )['ok'] );
$stale = $service->edit( $id, array_merge( $form, array( 'qty' => 5, 'seen' => $tab_b ) ), $sizes, fh_staff() );
check( 'tab B, opened before that, is refused', ! $stale['ok'] );
check_contains( 'and told why', 'corrected somewhere else', $stale['message'] );
check_same( 'the newer note is still there, the quantity untouched', array( 'IMPORTANT: wants a male, call first', 3 ), array( $store->get( $id )['note'], $store->get( $id )['qty'] ) );
check( 'with the page as it is now, the same change is saved', $service->edit( $id, array_merge( $form, array( 'note' => 'IMPORTANT: wants a male, call first', 'qty' => 5, 'seen' => Rules::seen( $store->get( $id ) ) ) ), $sizes, fh_staff() )['ok'] && 5 === $store->get( $id )['qty'] );
$before_state = Rules::seen( $store->get( $id ) );
$store->update( $id, array( 'state' => 'on_order', 'line_id' => 4 ) );
check_same( 'the daily job moving the request along does not make an open page stale', $before_state, Rules::seen( $store->get( $id ) ) );

echo "\nThe account follows the e-mail address\n";
$picked = $service->create( array( 'user_id' => 7, 'fish' => 'Blue Test Wrasse' ), fh_staff() );
$base   = array( 'name' => 'Pat Example', 'handle' => 'reef_pat', 'fish' => 'Blue Test Wrasse', 'qty' => 1 );
$service->edit( $picked, array_merge( $base, array( 'email' => 'pat@example.test', 'qty' => 2 ) ), $sizes, fh_staff() );
check_same( 'the address unchanged: the account stays', 7, $store->get( $picked )['user_id'] );
$moved = $service->edit( $picked, array_merge( $base, array( 'email' => 'sam@example.test', 'qty' => 2 ) ), $sizes, fh_staff() );
check_same( 'the address corrected to another customer\'s: the request moves to that account', 8, $store->get( $picked )['user_id'] );
check_contains( 'the owner is told', 'now belongs to site customer #8', $moved['message'] );
check_contains( 'and so is the history', 'now belongs to site customer #8', implode( ' | ', $store->notes( $picked ) ) );
$moved = $service->edit( $picked, array_merge( $base, array( 'email' => 'nobody@example.test', 'qty' => 2 ) ), $sizes, fh_staff() );
check_same( 'an address that is nobody\'s account: the request belongs to no account', 0, $store->get( $picked )['user_id'] );
check_contains( 'said plainly', 'no longer belongs to a site account', $moved['message'] );
$kept = $service->create( array( 'user_id' => 7, 'fish' => 'Red Test Goby' ), fh_staff() );
$service->edit( $kept, array( 'name' => 'Pat Example', 'email' => '', 'fish' => 'Red Test Goby', 'qty' => 1 ), $sizes, fh_staff() );
check_same( 'an address that is only cleared leaves the account alone', 7, $store->get( $kept )['user_id'] );

echo "\nLinks in an e-mail\n";
$html = \FisHotel\Misc\Sections\Requests\Mailer::html( "Hello Pat pay at https://evil.example/pay now,\n\nPay here: https://example.test/?fh_request_pay=abc.def\n\nSee http://EXAMPLE.test/product/501/." );
check_contains( 'a link to the shop\'s own site is clickable', '<a href="https://example.test/?fh_request_pay=abc.def"', $html );
check_contains( 'whatever the case of the host', '<a href="http://EXAMPLE.test/product/501/"', $html );
check_lacks( 'an address somebody typed into a name or a note is not', 'href="https://evil.example', $html );
check_contains( 'it stays as plain text', 'pay at https://evil.example/pay now', $html );
$bad = $service->create( array( 'name' => 'Pat pay at https://evil.example/pay', 'email' => 'lee@example.test', 'fish' => 'Blue Test Wrasse', 'fee' => '5' ), fh_staff() );
$mail = fh_take_mails();
check( 'the invoice of such a request links the pay link and nothing else', is_int( $bad ) && 1 === count( $mail ) && false === strpos( $mail[0]['message'], 'href="https://evil.example' ) && false !== strpos( $mail[0]['message'], 'href="https://example.test/?' ) );
check( 'an empty note is not', ! $service->note( $id, '   ', fh_staff() )['ok'] );

/* ================================================================== */
echo "\nThe log is the whole story\n";
/* ================================================================== */

$service = fh_service();
$store   = $service->store;
$ids     = $service->submit( $person, array( array( 'fish' => 'Blue Test Wrasse' ) ), array( 'source' => 'link', 'link' => fh_link( $service ) ) )['ids'];
$id      = $ids[0];
$service->approve( array( $id ), '12', fh_staff() );
$service->paid( $id, array( 'order_id' => 77, 'amount' => 12.0, 'user_id' => 7, 'wallet' => true ) );
$service->link( $id, array( 'id' => 5, 'order_id' => 2, 'ref' => '2026-3', 'species' => 'Blue Test Wrasse' ), true, fh_staff() );
$service->unlink( $id, true, fh_staff() );
$service->fill( $id, fh_staff() );
fh_take_mails();
check_same( 'every step left a line, in order', array( 'created', 'approved', 'mail', 'paid', 'on_list', 'on_order', 'waiting', 'filled' ), $store->actions( $id ) );
check_same( 'a hand unlink switches matching by itself off', 0, $store->get( $id )['auto_match'] );
check( 'each line has a time and somebody behind it', array() === array_filter( $store->log, static function ( $line ) { return '' === $line['created_at'] || '' === $line['actor']; } ) );

fh_finish();
