<?php
/**
 * What a person who is not the shop can reach: the rate limit of the
 * private form (per address and per e-mail), the form itself as it is
 * printed (the nonce, the honeypot, a standard size list or free text,
 * the fee notice, everything escaped), "my requests" (in plain words,
 * Cancel only where it is allowed), OWNERSHIP — a customer never sees or
 * cancels another's request, by account or by personal link —, who gets
 * name suggestions, the My Account tab that only shows for somebody who
 * has requests, and the public shortcode that prints nothing until it is
 * switched on.
 *
 * Run from the plugin root:  php includes/sections/requests/tests/front-test.php
 */

require_once __DIR__ . '/wp-stubs.php';
require_once FISHOTEL_MISC_PATH . 'includes/sections/requests/class-requests.php';

use FisHotel\Misc\Sections\Requests\Front;
use FisHotel\Misc\Sections\Requests\Requests;
use FisHotel\Misc\Sections\Requests\Rules;
use FisHotel\Misc\Sections\Requests\Service;
use FisHotel\Misc\Sections\Requests\Tokens;
use FisHotel\Misc\Sections\Requests\Urls;

function wp_login_url( $redirect = '' ) {
	return 'https://example.test/wp-login.php?redirect_to=' . rawurlencode( (string) $redirect );
}
function get_permalink( $post = 0 ) {
	return 'https://example.test/request-a-fish/';
}

Requests::load();

$GLOBALS['fh_users'] = array(
	7 => array( 'login' => 'pat', 'email' => 'pat@example.test', 'first' => 'Pat', 'last' => 'Example', 'meta' => array( '_fishotel_humble_username' => 'reef_pat' ) ),
	8 => array( 'login' => 'sam', 'email' => 'sam@example.test', 'first' => 'Sam', 'last' => 'Sample' ),
	1 => array( 'login' => 'shopkeeper', 'email' => 'shop@example.test' ),
);
$GLOBALS['fh_user_caps'] = array( 'manage_woocommerce' => array( 1 ) );

$service = fh_service();
$store   = $service->store;
Requests::use_service( $service );
update_option( 'fishotel_misc_enabled_sections', array( 'requests' ) );

/**
 * Render one of the section's views into a string.
 */
function fh_view( $file, array $vars ) {
	extract( $vars ); // phpcs:ignore WordPress.PHP.DontExtract.extract_extract -- a test helper.
	ob_start();
	include FISHOTEL_MISC_PATH . 'includes/sections/requests/views/' . $file;
	return (string) ob_get_clean();
}

/* ================================================================== */
echo "The rate limit\n";
/* ================================================================== */

$keys = Front::rate_keys( '203.0.113.9', 'Pat@Example.test' );
check_same( 'a submission is counted under its address and its e-mail', 2, count( $keys ) );
check( 'neither key shows the address or the e-mail', false === strpos( implode( ' ', $keys ), '203.0' ) && false === strpos( implode( ' ', $keys ), 'pat' ) );
check_same( 'the e-mail key ignores case', Front::rate_keys( '', 'pat@example.test' ), Front::rate_keys( '', 'PAT@example.test' ) );
check_same( 'no e-mail, no e-mail key', 1, count( Front::rate_keys( '203.0.113.9', 'nope' ) ) );

check( 'nothing sent yet: not limited', ! Front::rate_limited( $keys ) );
for ( $fh_i = 0; $fh_i < Rules::RATE_LIMIT - 1; $fh_i++ ) {
	Front::rate_hit( $keys );
}
check( 'four sheets in the hour: still fine', ! Front::rate_limited( $keys ) );
Front::rate_hit( $keys );
check( 'the fifth uses the hour up', Front::rate_limited( $keys ) );
check( 'the same address with ANOTHER e-mail is limited too', Front::rate_limited( Front::rate_keys( '203.0.113.9', 'other@example.test' ) ) );
check( 'the same e-mail from ANOTHER address is limited too', Front::rate_limited( Front::rate_keys( '198.51.100.7', 'pat@example.test' ) ) );
check( 'somebody else entirely is not', ! Front::rate_limited( Front::rate_keys( '198.51.100.7', 'other@example.test' ) ) );
check_same( 'the window is an hour', Rules::RATE_WINDOW, $GLOBALS['fh_transients'][ $keys[0] ][1] );
add_filter( 'fishotel_requests_rate_limit', static function () { return 50; } );
check( 'the limit can be filtered', ! Front::rate_limited( $keys ) );
$GLOBALS['fh_filters']    = array();
$GLOBALS['fh_transients'] = array();

/* ================================================================== */
echo "\nThe form as it is printed\n";
/* ================================================================== */

$form = array(
	'action'  => Urls::form( 'abc' ),
	'nonce'   => 'nonce-123',
	'hidden'  => array(),
	'person'  => array( 'name' => 'Pat "Reef" <b>Example</b>', 'email' => 'pat@example.test', 'handle' => '' ),
	'rows'    => array( array( 'fish' => 'Blue Test Wrasse"><script>alert(1)</script>', 'qty' => '2', 'size_slug' => '2-3-5', 'note' => '</textarea><script>x</script>' ) ),
	'sizes'   => array( array( 'slug' => '1-2', 'label' => '1 - 2"' ), array( 'slug' => '2-3-5', 'label' => '2 - 3.5"' ) ),
	'fee'     => 12.5,
	'errors'  => array( 'Fish 1: <b>bad</b>' ),
	'locked'  => false,
	'token'   => 'abc',
	'heading' => 'Request a fish',
);
$html = fh_view( 'front-form.php', array( 'fh_form' => $form ) );
check_contains( 'it posts back to the link', 'action="https://example.test/?fh_request=abc"', $html );
check_contains( 'with a nonce', 'name="' . Front::NONCE_FIELD . '" value="nonce-123"', $html );
check_contains( 'and a honeypot nobody tabs into', 'name="' . Front::HONEYPOT . '" value="" tabindex="-1"', $html );
check( 'the honeypot is not called anything a browser fills in by itself', ! in_array( Front::HONEYPOT, array( 'website', 'url', 'email', 'name', 'phone', 'company', 'address' ), true ) );
check_contains( 'name, e-mail and handle', 'name="handle"', $html );
check_same( 'three fish rows at least, so it works without JavaScript', 3, substr_count( $html, '<fieldset class="fh-rq-row">' ) - 1 );
check_contains( 'the standard sizes as a dropdown', '<option value="2-3-5"  selected="selected">2 - 3.5&quot;</option>', $html );
check_contains( 'what was typed comes back', 'value="2"', $html );
check_lacks( 'nothing typed can break out of an attribute or a textarea', '<script>', $html );
check_contains( 'the name is escaped', 'Pat &quot;Reef&quot; &lt;b&gt;Example&lt;/b&gt;', $html );
check_contains( 'the errors are listed, escaped', 'Fish 1: &lt;b&gt;bad&lt;/b&gt;', $html );
check_contains( 'the fee is announced, with where it goes', '$12.50', $html );
check_contains( 'the wallet', 'wallet', $html );
check_contains( 'a template row for "Add another fish"', 'rows[__i__][fish]', $html );

$free = fh_view( 'front-form.php', array( 'fh_form' => array_merge( $form, array( 'sizes' => null, 'fee' => 0.0, 'errors' => array(), 'rows' => array() ) ) ) );
check_contains( 'without a standard list the size is a text field', 'name="rows[0][size_label]"', $free );
check_lacks( 'and no dropdown', 'rows[0][size_slug]', $free );
check_lacks( 'no fee, no fee notice', 'request fee', $free );
check_lacks( 'no errors, no error box', 'fh-rq-errors', $free );

$locked = fh_view( 'front-form.php', array( 'fh_form' => array_merge( $form, array( 'locked' => true, 'hidden' => array( 'fh_rq_public' => '1' ) ) ) ) );
check_contains( 'on the public form the name and e-mail come from the account', 'autocomplete="email" readonly', $locked );
check_contains( 'and its marker is posted', 'name="fh_rq_public" value="1"', $locked );

/* ================================================================== */
echo "\nMy requests\n";
/* ================================================================== */

$link = $store->link( $store->insert_link( array( 'token' => Tokens::link(), 'label' => 'Test batch' ) ) );
$pat1 = $service->create( array( 'user_id' => 7, 'fish' => 'Blue Test Wrasse', 'qty' => 2 ), fh_staff() );
$pat2 = $service->create( array( 'user_id' => 7, 'fish' => 'Green Test Tang', 'source' => 'lost_fish' ) );
$pat3 = $service->create( array( 'user_id' => 7, 'fish' => 'Old Test Fish' ), fh_staff() );
$sam1 = $service->create( array( 'user_id' => 8, 'fish' => 'Red Test Goby' ), fh_staff() );
$lee1 = $service->submit( array( 'name' => 'Lee Local', 'email' => 'lee@example.test' ), array( array( 'fish' => 'Yellow Test Blenny' ) ), array( 'source' => 'link', 'link' => $link ) )['ids'][0];
$noah = $service->submit( array( 'name' => 'Noah Newcomer', 'email' => 'noah@example.test' ), array( array( 'fish' => 'Purple Test Dottyback' ) ), array( 'source' => 'link', 'link' => $link ) )['ids'][0];
$service->fill( $pat3, fh_staff() );
$store->update( $pat3, array( 'closed_at' => Rules::utc( $GLOBALS['fh_clock'] - 100 * 86400 ) ) );
$old = $service->create( array( 'user_id' => 7, 'fish' => 'Recent Test Fish' ), fh_staff() );
$service->cancel( $old, fh_staff() );

$rows = Front::my_rows( $store->find( array( 'user_id' => 7 ) ), $GLOBALS['fh_clock'] );
check_same( 'open requests first, then the ones closed in the last 90 days; older ones are left out', array( $pat1, $pat2, $old ), array_column( $rows, 'id' ) );
check_same( 'each in plain words', 'On our list — we are looking for it', $rows[0]['words'] );
check_same( 'with the fish and how many', '2 × Blue Test Wrasse', $rows[0]['fish'] );
check_same( 'and when it was asked', 'asked today', $rows[0]['asked'] );
check_same( 'an open request can be cancelled; an owed one cannot; a closed one cannot', array( true, false, false ), array_column( $rows, 'can_cancel' ) );

$list = array(
	'heading'  => 'My fish requests',
	'intro'    => 'pat@example.test',
	'requests' => $rows,
	'flash'    => '',
	'action'   => 'https://example.test/?fh_requests=x',
	'nonce'    => 'nonce-9',
	'hidden'   => array(),
	'plain'    => true,
);
$html = fh_view( 'front-list.php', array( 'fh_list' => $list ) );
check_same( 'ONE Cancel button: only where cancelling is allowed', 1, substr_count( $html, 'name="cancel"' ) );
check_contains( 'for the right request', 'name="cancel" value="' . $pat1 . '"', $html );
check_contains( 'the owed one says who to turn to', 'Already paid for', $html );
check_contains( 'an empty list says so', 'no requests here', fh_view( 'front-list.php', array( 'fh_list' => array_merge( $list, array( 'requests' => array() ) ) ) ) );

/* ================================================================== */
echo "\nOwnership: by account\n";
/* ================================================================== */

$mine = array_column( $store->find( array( 'user_id' => 8 ) ), 'id' );
check_same( 'a customer\'s list holds their own requests only', array( $sam1 ), $mine );
check( 'somebody else\'s request is not theirs', ! Rules::owned_by_user( $store->get( $pat1 ), 8 ) );
check( 'a request without an account is no account\'s', ! Rules::owned_by_user( $store->get( $lee1 ), 8 ) && ! Rules::owned_by_user( $store->get( $lee1 ), 0 ) );
check( 'their own is', Rules::owned_by_user( $store->get( $sam1 ), 8 ) );

/**
 * What My Account's cancel does, without the redirect.
 */
function fh_account_cancel( $service, $user_id, $request_id ) {
	$request = $service->store->get( $request_id );
	if ( ! $request || ! Rules::owned_by_user( $request, $user_id ) ) {
		return 'not yours';
	}
	return $service->cancel( $request_id, Service::customer( $user_id ), '', true )['ok'] ? 'cancelled' : 'refused';
}
check_same( 'Sam cannot cancel Pat\'s request', array( 'not yours', 'waiting' ), array( fh_account_cancel( $service, 8, $pat1 ), $store->get( $pat1 )['state'] ) );
check_same( 'nor a request that has no account', array( 'not yours', 'new' ), array( fh_account_cancel( $service, 8, $lee1 ), $store->get( $lee1 )['state'] ) );
check_same( 'nor one that does not exist', 'not yours', fh_account_cancel( $service, 8, 99999 ) );
check_same( 'Pat cannot cancel the owed one (the shop settles that)', array( 'refused', 'waiting' ), array( fh_account_cancel( $service, 7, $pat2 ), $store->get( $pat2 )['state'] ) );
check_same( 'Sam cancels Sam\'s own', array( 'cancelled', 'cancelled' ), array( fh_account_cancel( $service, 8, $sam1 ), $store->get( $sam1 )['state'] ) );

/* ================================================================== */
echo "\nOwnership: by personal link\n";
/* ================================================================== */

$token = Tokens::person( 'lee@example.test' );
$email = Tokens::read_person( $token );
check_same( 'the link names its e-mail address', 'lee@example.test', $email );
check_same( 'the page lists that address\'s requests only', array( $lee1 ), array_column( $store->find( array( 'email' => $email ) ), 'id' ) );
check_same( 'a link made for another address lists the other\'s', array( $noah ), array_column( $store->find( array( 'email' => Tokens::read_person( Tokens::person( 'noah@example.test' ) ) ) ), 'id' ) );
check_same( 'a link whose signature was changed lists nothing: it names nobody', '', Tokens::read_person( substr( $token, 0, -2 ) . '00' ) );
check_same( 'the address of one link with the signature of another names nobody', '', Tokens::read_person( explode( '.', Tokens::person( 'noah@example.test' ) )[0] . '.' . explode( '.', $token )[1] ) );

/**
 * What the personal page's cancel does, without the redirect.
 */
function fh_person_cancel( $service, $email, $request_id ) {
	$request = $service->store->get( $request_id );
	if ( ! $request || ! Rules::owned_by_email( $request, $email ) ) {
		return 'not yours';
	}
	return $service->cancel( $request_id, Service::customer(), '', true )['ok'] ? 'cancelled' : 'refused';
}
check_same( 'Lee cannot cancel Noah\'s request from Lee\'s link', array( 'not yours', 'new' ), array( fh_person_cancel( $service, $email, $noah ), $store->get( $noah )['state'] ) );
check_same( 'nor a customer\'s', array( 'not yours', 'waiting' ), array( fh_person_cancel( $service, $email, $pat1 ), $store->get( $pat1 )['state'] ) );
check_same( 'Lee cancels Lee\'s own', array( 'cancelled', 'cancelled' ), array( fh_person_cancel( $service, $email, $lee1 ), $store->get( $lee1 )['state'] ) );
check_same( 'an empty address owns nothing', 'not yours', fh_person_cancel( $service, '', $noah ) );

echo "\nThe link in an e-mail\n";
check_contains( 'somebody without an account gets their personal link', '?fh_requests=', Urls::view( $store->get( $noah ) ) );
check_same( 'a request without an e-mail address has no link to offer', '', Urls::view( array( 'id' => 5, 'user_id' => 0, 'email' => '' ) ) );

/* ================================================================== */
echo "\nName suggestions: who may ask\n";
/* ================================================================== */

$front = new Front();
$ask   = static function ( array $request, $user ) use ( $front ) {
	$_REQUEST                   = $request;
	$GLOBALS['fh_current_user'] = $user;
	try {
		$front->ajax_suggest();
	} catch ( Fh_Test_Json $reply ) {
		return array( $reply->success, $reply->status );
	}
	return array( null, 0 );
};
check_same( 'a stranger without a link gets nothing', array( false, 403 ), $ask( array( 'term' => 'wrasse' ), 0 ) );
check_same( 'the holder of a link that works may ask', array( true, 200 ), $ask( array( 'term' => 'wrasse', 'token' => $link['token'] ), 0 ) );
check_same( 'a made-up token gets nothing', array( false, 403 ), $ask( array( 'term' => 'wrasse', 'token' => str_repeat( 'a', 32 ) ), 0 ) );
$store->set_link_active( $link['id'], false );
check_same( 'a link that was switched off gets nothing', array( false, 403 ), $ask( array( 'term' => 'wrasse', 'token' => $link['token'] ), 0 ) );
check_same( 'a logged-in customer gets nothing while the form is not public', array( false, 403 ), $ask( array( 'term' => 'wrasse' ), 7 ) );
check_same( 'the shop may always ask', array( true, 200 ), $ask( array( 'term' => 'wrasse' ), 1 ) );
$service->settings['public_open'] = 1;
update_option( 'fishotel_requests_settings', array( 'public_open' => 1 ) );
check_same( 'once the form is public a logged-in customer may ask', array( true, 200 ), $ask( array( 'term' => 'wrasse' ), 7 ) );
check_same( 'a logged-out visitor still may not', array( false, 403 ), $ask( array( 'term' => 'wrasse' ), 0 ) );
update_option( 'fishotel_requests_settings', array() );
$service->settings['public_open'] = 0;
$_REQUEST                         = array();

/* ================================================================== */
echo "\nMy Account → Requests\n";
/* ================================================================== */

check_same( 'WooCommerce is told about the endpoint', array( 'orders' => 'orders', 'fish-requests' => 'fish-requests' ), $front->account_query_var( array( 'orders' => 'orders' ) ) );
check( 'its name is not Batch Manager\'s "my-requests"', 'my-requests' !== Urls::ACCOUNT_ENDPOINT );

$items                      = array( 'dashboard' => 'Dashboard', 'orders' => 'Orders', 'customer-logout' => 'Log out' );
$GLOBALS['fh_current_user'] = 7;
$menu                       = $front->account_menu( $items );
check_same( 'a customer WITH requests gets the tab, before "Log out"', array( 'dashboard', 'orders', 'fish-requests', 'customer-logout' ), array_keys( $menu ) );

/**
 * A fresh Front per customer (it remembers "do I have requests?" for the request).
 */
function fh_menu_for( $user_id, array $items ) {
	$GLOBALS['fh_current_user'] = $user_id;
	$reset                      = new ReflectionProperty( Front::class, 'mine' );
	$reset->setAccessible( true );
	$reset->setValue( null, null );
	return ( new Front() )->account_menu( $items );
}
$newcomer = 9;
$GLOBALS['fh_users'][9] = array( 'login' => 'newcomer', 'email' => 'new@example.test' );
check_same( 'a customer WITHOUT requests does not: the tab gives nothing away', array_keys( $items ), array_keys( fh_menu_for( $newcomer, $items ) ) );
update_option( 'fishotel_requests_settings', array( 'public_open' => 1 ) );
check( 'once the form is public everybody gets it', isset( fh_menu_for( $newcomer, $items )['fish-requests'] ) );
update_option( 'fishotel_requests_settings', array() );

/* ================================================================== */
echo "\nLater, public: [fishotel_request_form]\n";
/* ================================================================== */

fh_menu_for( 7, $items );
check_same( 'while "Open to the public" is off the shortcode prints NOTHING', '', $front->shortcode() );

update_option( 'fishotel_requests_settings', array( 'public_open' => 1, 'default_fee' => 9.0, 'public_cap' => 2 ) );
$GLOBALS['fh_current_user'] = 0;
$out                        = $front->shortcode();
check_contains( 'switched on, a visitor is asked to log in', 'Please log in', $out );
check_lacks( 'and sees no form', '<form', $out );

$GLOBALS['fh_current_user'] = 9;
$out                        = $front->shortcode();
check_contains( 'a logged-in customer sees the form', '<form class="fh-rq-form', $out );
check_contains( 'with their own name and e-mail, not editable', 'value="new@example.test" maxlength="190" autocomplete="email" readonly', $out );
check_contains( 'the default fee announced', '$9.00', $out );
check_contains( 'its own nonce', 'name="' . Front::NONCE_FIELD . '" value="' . wp_create_nonce( Front::NONCE_PUBLIC ) . '"', $out );

$GLOBALS['fh_current_user'] = 7;
$out                        = $front->shortcode();
check_contains( 'a customer at the cap of open requests is told so', 'the most we take at one time', $out );
check_lacks( 'and gets no form', '<form class="fh-rq-form', $out );

$sheet = $service->submit( array( 'name' => 'New Comer', 'email' => 'new@example.test' ), array( array( 'fish' => 'Blue Test Wrasse' ) ), array( 'source' => 'public', 'user_id' => 9, 'fee' => 9.0 ) );
$made  = $store->get( $sheet['ids'][0] );
check_same( 'a public sheet waits for approval like a link\'s, with the default fee proposed', array( 'new', 'public', 9, 9.0, null ), array( $made['state'], $made['source'], $made['user_id'], $made['fee_amount'], $made['fee_ref'] ) );

update_option( 'fishotel_requests_settings', array() );
Requests::use_service( null );
fh_finish();
