<?php
/**
 * Device tests — enrolment and the device token: only the SHA-256 of a
 * token is ever stored; the cookie is Secure, HttpOnly and SameSite=Lax,
 * lasts a year and is renewed on use; a revoked device is gone at once
 * (its session with it); a one-time enrolment link is good for ten
 * minutes and one use, opening it uses nothing up, and only its hash is
 * kept; a browser that is enrolled again does not leave a device row
 * behind; a request without a device cookie costs no query.
 *
 * Run from the plugin root:
 *   php includes/sections/shop-app/tests/device-test.php
 *
 * @package FisHotel\Misc\Sections\Shop_App
 */

require_once __DIR__ . '/wp-stubs.php';

use FisHotel\Misc\Sections\Shop_App\Access;
use FisHotel\Misc\Sections\Shop_App\App;
use FisHotel\Misc\Sections\Shop_App\Http;
use FisHotel\Misc\Sections\Shop_App\Pins;
use FisHotel\Misc\Sections\Shop_App\Store;

$device_cookie  = Http::cookie_name( Http::DEVICE );
$session_cookie = Http::cookie_name( Http::SESSION );

echo "1. Tokens\n";
$token = Pins::new_token();
check( 'a device token is 64 hex characters (256 random bits), never twice the same', Pins::is_token( $token ) && $token !== Pins::new_token() );
check( 'what is stored of it is its SHA-256', hash( 'sha256', $token ) === Pins::token_hash( $token ) && $token !== Pins::token_hash( $token ) );
check( 'anything else is not a token', ! Pins::is_token( '' ) && ! Pins::is_token( substr( $token, 1 ) ) && ! Pins::is_token( strtoupper( $token ) ) && ! Pins::is_token( $token . "\n" ) && ! Pins::is_token( array( $token ) ) && ! Pins::is_token( null ) );
$enrol = Pins::new_enrol_token();
check( 'an enrolment token is 32 hex characters (128 random bits)', Pins::is_enrol_token( $enrol ) && ! Pins::is_enrol_token( $token ) && ! Pins::is_token( $enrol ) );
check( 'the cookie names carry the site\'s hash', 'fh_shop_device_abc123' === $device_cookie && 'fh_shop_session_abc123' === $session_cookie );

echo "\n2. Set up this device\n";
$store = fh_fresh_store();
$owner = fh_add_user( 'owner', 'administrator', 'Olive Owner' );
fh_request();
$store->queries = 0;
$ctx            = Access::instance()->context();
check( 'a browser with no device cookie is no device', null === $ctx['device'] && null === $ctx['session'] );
check( '… and finding that out costs no query', 0 === $store->queries );

$device = Access::instance()->enrol_here( '  <b>Shop</b>   tablet  ', $owner );
$sent   = $GLOBALS['fh_sent']['cookies'];
$set    = fh_cookies_set();
$raw    = isset( $set[ $device_cookie ] ) ? $set[ $device_cookie ] : '';
check( 'enrolling answers with the device: named (tags dropped, spaces tidied), active, enrolled by that user', $device && 'Shop tablet' === $device['name'] && Store::STATUS_ACTIVE === $device['status'] && $owner === $device['enrolled_by'] && null !== $device['enrolled_at'] );
check( 'a device token went out in the device cookie', Pins::is_token( $raw ) );

$first = null;
foreach ( $sent as $cookie ) {
	if ( $cookie['name'] === $device_cookie && '' !== $cookie['value'] ) {
		$first = $cookie;
	}
}
check( 'the cookie is Secure', $first && true === $first['options']['secure'] );
check( 'the cookie is HttpOnly', $first && true === $first['options']['httponly'] );
check( 'the cookie is SameSite=Lax', $first && 'Lax' === $first['options']['samesite'] );
check( 'the cookie lasts a year', $first && $GLOBALS['fh_now'] + 365 * 86400 === $first['options']['expires'] );
check( 'the cookie is set on the site\'s path', $first && '/' === $first['options']['path'] );
check( 'every cookie this section ever sets has the same three flags', array() === array_filter( $sent, static function ( $c ) { return true !== $c['options']['secure'] || true !== $c['options']['httponly'] || 'Lax' !== $c['options']['samesite']; } ) && array( 'expires', 'path', 'domain', 'secure', 'httponly', 'samesite' ) === array_keys( Http::cookie_options( 1 ) ) );

$row  = $store->devices[ $device['id'] ];
$dump = json_encode( array( $store->devices, $store->log, $store->people, $GLOBALS['fh_options'], $GLOBALS['fh_transients'] ) );
check( 'the store holds the SHA-256 of the token', hash( 'sha256', $raw ) === $row['token_hash'] );
check( 'the token itself is nowhere on the server: not in a row, the log, an option or a transient', false === strpos( $dump, $raw ) );
check( 'the enrolment is in the log: who, which device', 'device_enrolled' === $store->log[0]['event'] && $owner === $store->log[0]['user_id'] && 'Shop tablet' === $store->log[0]['device_name'] && $device['id'] === $store->log[0]['device_id'] );
check( 'a nameless device still gets a name', 'Shop device' === Access::clean_name( '   ' , 'Shop device' ) && 80 === strlen( Access::clean_name( str_repeat( 'x', 200 ) ) ) );

$jar = fh_jar( array() );
fh_request( array( 'cookies' => $jar ) );
$ctx       = Access::instance()->context();
$first_use = fh_cookies_set();
check( 'the next request with that cookie is that device', $ctx['device'] && $device['id'] === $ctx['device']['id'] );
check( 'a token that differs by one character is no device', ( function () use ( $jar, $device_cookie ) {
	$bent                   = $jar;
	$bent[ $device_cookie ] = substr( $jar[ $device_cookie ], 0, 63 ) . ( 'a' === substr( $jar[ $device_cookie ], -1 ) ? 'b' : 'a' );
	fh_request( array( 'cookies' => $bent ) );
	return null === Access::instance()->context()['device'];
} )() );
check( 'the stored hash sent as the cookie is no device (the hash is not the key)', ( function () use ( $row, $device_cookie ) {
	fh_request( array( 'cookies' => array( $device_cookie => $row['token_hash'] ) ) );
	return null === Access::instance()->context()['device'];
} )() );

echo "\n3. Renewed on use\n";
check( 'the first use stamped "last used" and sent the same cookie again, for a year from then', gmdate( 'Y-m-d H:i:s', $GLOBALS['fh_now'] ) === $store->devices[ $device['id'] ]['last_used_at'] && isset( $first_use[ $device_cookie ] ) && $jar[ $device_cookie ] === $first_use[ $device_cookie ] );
$GLOBALS['fh_now'] += 120;
fh_request( array( 'cookies' => $jar ) );
Access::instance()->context();
check( 'two minutes later: nothing is written and no cookie is sent (at most every five minutes)', array() === $GLOBALS['fh_sent']['cookies'] && gmdate( 'Y-m-d H:i:s', $GLOBALS['fh_now'] - 120 ) === $store->devices[ $device['id'] ]['last_used_at'] );
$GLOBALS['fh_now'] += 86400 * 200;
fh_request( array( 'cookies' => $jar ) );
Access::instance()->context();
$renewed = $GLOBALS['fh_sent']['cookies'];
check( '200 days later: used again, so the year starts again', 1 === count( $renewed ) && $GLOBALS['fh_now'] + 365 * 86400 === $renewed[0]['options']['expires'] && $jar[ $device_cookie ] === $renewed[0]['value'] );

echo "\n4. Revoke takes effect at once\n";
fh_add_person( 'Sam', '4821' );
fh_request( array( 'cookies' => $jar ) );
$unlock = Access::instance()->unlock( '4821' );
$jar    = fh_jar( $jar );
fh_request( array( 'cookies' => $jar ) );
check( 'the device is unlocked: a live session', $unlock['ok'] && null !== Access::instance()->context()['session'] );

fh_request(); // The manager's computer.
check( 'revoke() says it did', Access::instance()->revoke( $device['id'], $owner ) );
$row = $store->devices[ $device['id'] ];
check( 'the row is revoked, by whom and when, and keeps nothing of the token or the session', Store::STATUS_REVOKED === $row['status'] && $owner === $row['revoked_by'] && null !== $row['revoked_at'] && '' === $row['token_hash'] && '' === $row['session_id'] && 0 === $row['session_person_id'] );
$last = end( $store->log );
check( 'the log says so', 'device_revoked' === $last['event'] && $owner === $last['user_id'] && 'Shop tablet' === $last['device_name'] );

fh_request( array( 'cookies' => $jar ) );
$ctx = Access::instance()->context();
check( 'the very next request from that device: no device, no session — with both cookies still in the browser', isset( $jar[ $device_cookie ], $jar[ $session_cookie ] ) && null === $ctx['device'] && null === $ctx['session'] );
fh_request( array( 'cookies' => $jar ) );
check( 'its PIN keypad is gone too: unlocking says "not_enrolled"', 'not_enrolled' === Access::instance()->unlock( '4821' )['code'] );
fh_request();
check( 'revoking twice does nothing the second time', ! Access::instance()->revoke( $device['id'], $owner ) && 1 === count( array_keys( $store->events(), 'device_revoked', true ) ) );
check( 'a device that never existed cannot be revoked', ! Access::instance()->revoke( 4242, $owner ) );

echo "\n5. The one-time enrolment link\n";
$store = fh_fresh_store();
fh_request();
$link = Access::instance()->create_link( 'Back room tablet', $owner );
check( 'a link is made: the app\'s enrol address with a 32-character token', $link && 1 === preg_match( '~^https://shop\.example\.test/\?fh_shop_app=enrol&k=[a-f0-9]{32}$~', $link['url'] ) && Pins::is_enrol_token( $link['token'] ) );
check( 'it is good for ten minutes', 600 === Access::ENROL_SECONDS && $GLOBALS['fh_now'] + 600 === $link['expires'] );
check( 'the address is short enough for a small QR code (under 106 bytes)', strlen( $link['url'] ) < 106, (string) strlen( $link['url'] ) );
$row = $store->devices[ $link['device_id'] ];
check( 'a waiting device row holds the SHA-256 of the token and the expiry', Store::STATUS_PENDING === $row['status'] && hash( 'sha256', $link['token'] ) === $row['enrol_hash'] && gmdate( 'Y-m-d H:i:s', $GLOBALS['fh_now'] + 600 ) === $row['enrol_expires'] && '' === $row['token_hash'] );
check( 'the token itself is not stored anywhere', false === strpos( json_encode( array( $store->devices, $store->log, $GLOBALS['fh_options'], $GLOBALS['fh_transients'] ) ), $link['token'] ) );
check( 'making it is logged, with who made it', 'link_created' === $store->log[0]['event'] && $owner === $store->log[0]['user_id'] );
check( 'a waiting device is not a device: its hash as a cookie opens nothing', null === $store->device_by_token_hash( $row['enrol_hash'] ) );

// The tablet opens the link.
fh_request( array( 'uri' => '/?fh_shop_app=enrol&k=' . $link['token'] ) );
$page = App::respond( 'enrol', 'GET' );
check( 'opening the link shows a page with one button (200)', 200 === $page['status'] && false !== strpos( $page['body'], 'Back room tablet' ) && false !== strpos( $page['body'], '<form method="post"' ) && false !== strpos( $page['body'], 'name="k" value="' . $link['token'] . '"' ) );
check( '… that is never stored and sends no referrer', in_array( 'Cache-Control: no-store, private, max-age=0', $page['headers'], true ) && in_array( 'Referrer-Policy: no-referrer', $page['headers'], true ) );
check( '… and uses nothing up: no cookie, the row still waits', array() === $GLOBALS['fh_sent']['cookies'] && Store::STATUS_PENDING === $store->devices[ $link['device_id'] ]['status'] );
fh_request( array( 'uri' => '/?fh_shop_app=enrol&k=' . $link['token'] ) );
App::respond( 'enrol', 'GET' );
App::respond( 'enrol', 'GET' );
check( 'opening it three times (a preview, a scanner, the person) still uses nothing up', Store::STATUS_PENDING === $store->devices[ $link['device_id'] ]['status'] );

// The button.
fh_request( array( 'method' => 'POST', 'uri' => '/?fh_shop_app=enrol', 'post' => array( 'k' => $link['token'] ) ) );
$done = App::respond( 'enrol', 'POST' );
$jar  = fh_jar( array() );
check( 'the button enrols the tablet and sends it to the app (303)', 303 === $done['status'] && in_array( 'Location: https://shop.example.test/?fh_shop_app=1', $done['headers'], true ) );
check( '… with a device cookie (Secure, HttpOnly, SameSite=Lax, a year)', isset( $jar[ $device_cookie ] ) && Pins::is_token( $jar[ $device_cookie ] ) && true === $GLOBALS['fh_sent']['cookies'][0]['options']['secure'] && true === $GLOBALS['fh_sent']['cookies'][0]['options']['httponly'] && 'Lax' === $GLOBALS['fh_sent']['cookies'][0]['options']['samesite'] );
$row = $store->devices[ $link['device_id'] ];
check( 'the row is an enrolled device now: the new token\'s hash, nothing of the link', Store::STATUS_ACTIVE === $row['status'] && hash( 'sha256', $jar[ $device_cookie ] ) === $row['token_hash'] && '' === $row['enrol_hash'] && null === $row['enrol_expires'] && $owner === $row['enrolled_by'] );
check( 'the device token is not the link\'s token', $jar[ $device_cookie ] !== $link['token'] && false === strpos( $jar[ $device_cookie ], $link['token'] ) );
$last = end( $store->log );
check( 'the log: device enrolled, by a one-time link, on behalf of who made it', 'device_enrolled' === $last['event'] && $owner === $last['user_id'] && false !== strpos( $last['note'], 'one-time link' ) );
fh_request( array( 'cookies' => $jar ) );
check( 'the tablet is a device from its next request on', $link['device_id'] === ( Access::instance()->context()['device']['id'] ?? 0 ) );

// The same link again.
fh_request( array( 'method' => 'POST', 'uri' => '/?fh_shop_app=enrol', 'post' => array( 'k' => $link['token'] ) ) );
$again = App::respond( 'enrol', 'POST' );
check( 'the same link a second time: refused (410), no cookie, "no longer works"', 410 === $again['status'] && array() === $GLOBALS['fh_sent']['cookies'] && false !== strpos( $again['body'], 'no longer works' ) );
fh_request( array( 'uri' => '/?fh_shop_app=enrol&k=' . $link['token'] ) );
check( '… and opening it again shows the same, with no button', 410 === App::respond( 'enrol', 'GET' )['status'] && false === strpos( App::respond( 'enrol', 'GET' )['body'], '<form' ) );
check( 'still exactly one device', 1 === count( $store->devices ) );

// A link that runs out.
fh_request();
$late = Access::instance()->create_link( 'Late tablet', $owner );
$GLOBALS['fh_now'] += 599;
check( 'one second before the ten minutes are over the link is still good', array( 'name' => 'Late tablet' ) === Access::instance()->link_info( $late['token'] ) );
$GLOBALS['fh_now'] += 1;
check( 'at ten minutes it is not', null === Access::instance()->link_info( $late['token'] ) );
fh_request( array( 'method' => 'POST', 'uri' => '/?fh_shop_app=enrol', 'post' => array( 'k' => $late['token'] ) ) );
$expired = App::respond( 'enrol', 'POST' );
check( '… the button does nothing: 410, no cookie, the row never became a device', 410 === $expired['status'] && array() === $GLOBALS['fh_sent']['cookies'] && Store::STATUS_PENDING === $store->devices[ $late['device_id'] ]['status'] );
fh_request();
Access::instance()->create_link( 'Third tablet', $owner );
check( 'making the next link clears the expired one away', ! isset( $store->devices[ $late['device_id'] ] ) );

// Made-up tokens.
fh_request( array( 'method' => 'POST', 'uri' => '/?fh_shop_app=enrol', 'post' => array( 'k' => str_repeat( 'a', 32 ) ) ) );
check( 'a made-up token: 410', 410 === App::respond( 'enrol', 'POST' )['status'] );
$store->queries = 0;
fh_request( array( 'method' => 'POST', 'uri' => '/?fh_shop_app=enrol', 'post' => array( 'k' => "x' OR 1=1 --" ) ) );
$junk = App::respond( 'enrol', 'POST' );
check( 'something that is not a token: 410, never looked up, never printed back', 410 === $junk['status'] && 0 === $store->queries && false === strpos( $junk['body'], 'OR 1=1' ) );
fh_request( array( 'method' => 'POST', 'uri' => '/?fh_shop_app=enrol', 'post' => array( 'k' => array( 'a' ) ) ) );
check( 'an array for a token: 410', 410 === App::respond( 'enrol', 'POST' )['status'] );

// A link cancelled before it was used.
fh_request();
$cancel = Access::instance()->create_link( 'Cancelled tablet', $owner );
check( 'an unused link can be cancelled from the list', Access::instance()->revoke( $cancel['device_id'], $owner ) && Store::STATUS_REVOKED === $store->devices[ $cancel['device_id'] ]['status'] );
check( '… and then it opens nothing', null === Access::instance()->link_info( $cancel['token'] ) && null === Access::instance()->claim_link( $cancel['token'] ) );

echo "\n6. A browser that is enrolled again\n";
fh_request( array( 'cookies' => $jar ) );
$second = Access::instance()->enrol_here( 'Same tablet, new name', $owner );
$jar2   = fh_jar( $jar );
check( 'it becomes the new device', $second && $jar2[ $device_cookie ] !== $jar[ $device_cookie ] );
check( 'the old device row is revoked — no row is left that no browser holds the token of', Store::STATUS_REVOKED === $store->devices[ $link['device_id'] ]['status'] );
$events = $store->events();
check( 'the log has the replacement before the new enrolment', 'device_revoked' === $events[ count( $events ) - 2 ] && 'device_enrolled' === $events[ count( $events ) - 1 ] && false !== strpos( $store->log[ count( $store->log ) - 2 ]['note'], 'enrolled again' ) );
fh_request( array( 'cookies' => $jar ) );
check( 'the old cookie is no device any more', null === Access::instance()->context()['device'] );

echo "\n7. HTTPS\n";
$GLOBALS['fh_is_ssl']          = false;
$GLOBALS['fh_options']['home'] = 'http://shop.example.test';
fh_request();
$http = Access::instance()->create_link( 'Plain tablet', $owner );
fh_request( array( 'method' => 'POST', 'uri' => '/?fh_shop_app=enrol', 'post' => array( 'k' => $http['token'] ) ) );
$plain = App::respond( 'enrol', 'POST' );
check( 'without HTTPS the link\'s page says it needs HTTPS and enrols nothing', 200 === $plain['status'] && false !== strpos( $plain['body'], 'needs HTTPS' ) && array() === $GLOBALS['fh_sent']['cookies'] && Store::STATUS_PENDING === $store->devices[ $http['device_id'] ]['status'] );
check( 'the admin page\'s "Set up this device" refuses too', 'https' === \FisHotel\Misc\Sections\Shop_App\Admin::enrol_here_request( array( 'name' => 'x' ) ) );
$GLOBALS['fh_is_ssl']          = false;
$GLOBALS['fh_options']['home'] = 'https://shop.example.test';
check( 'a site whose address is https counts as HTTPS even when PHP cannot see it (a proxy in front)', \FisHotel\Misc\Sections\Shop_App\Shop_App::https_ok() );
$GLOBALS['fh_is_ssl'] = true;

fh_finish();
