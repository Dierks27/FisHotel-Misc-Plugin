<?php
/**
 * Session tests — the signed cookie a correct PIN earns: its shape and
 * signature (nothing in it can be changed, another key makes none), its
 * end by both ways of counting the two hours (from the last use, renewed
 * on use at most once a minute; from the unlock, used or not), the length
 * setting applying to live sessions, Lock (the server forgets the session,
 * so a copy of the cookie is worth nothing), a session bound to its device
 * and its person, a new unlock ending the earlier session, the cookie's
 * flags, and unlock / lock in the log.
 *
 * Run from the plugin root:
 *   php includes/sections/shop-app/tests/session-test.php
 *
 * @package FisHotel\Misc\Sections\Shop_App
 */

require_once __DIR__ . '/wp-stubs.php';

use FisHotel\Misc\Sections\Shop_App\Access;
use FisHotel\Misc\Sections\Shop_App\Http;
use FisHotel\Misc\Sections\Shop_App\Session;
use FisHotel\Misc\Sections\Shop_App\Settings;

$device_cookie  = Http::cookie_name( Http::DEVICE );
$session_cookie = Http::cookie_name( Http::SESSION );
$two_hours      = array( 'session_minutes' => 120, 'session_mode' => Session::MODE_LAST_USE, 'pin_length' => 4 );
$from_unlock    = array( 'session_minutes' => 120, 'session_mode' => Session::MODE_UNLOCK, 'pin_length' => 4 );
$key            = str_repeat( 'k', 64 );
$t0             = 1800000000;

echo "1. The cookie (pure)\n";
$fields = array( 'device_id' => 7, 'person_id' => 3, 'session_id' => str_repeat( 'ab', 16 ), 'started' => $t0, 'last' => $t0 );
$value  = Session::build( $fields, $key );
check( 'its value is v1.device.person.session.unlocked.last-used.signature', 1 === preg_match( '/^v1\.7\.3\.(ab){16}\.1800000000\.1800000000\.[a-f0-9]{64}$/', $value ), $value );
check( 'only digits, hex and dots: nothing a cookie header could choke on', 1 === preg_match( '/^[a-z0-9.]+$/', $value ) );
$ok = Session::check( $value, $key, $t0 + 10, $two_hours );
check( 'it verifies, and says who and when', $ok['ok'] && '' === $ok['reason'] && 7 === $ok['device_id'] && 3 === $ok['person_id'] && str_repeat( 'ab', 16 ) === $ok['session_id'] && $t0 === $ok['started'] && $t0 === $ok['last'] );
check( 'a session id is 32 hex characters, never twice the same', 1 === preg_match( '/^[a-f0-9]{32}$/', Session::new_id() ) && Session::new_id() !== Session::new_id() );

echo "\n2. The signature\n";
check( 'another key: refused', 'signature' === Session::check( $value, str_repeat( 'x', 64 ), $t0, $two_hours )['reason'] );
check( 'no key at all: refused (a site without its secret signs nothing)', ! Session::check( Session::build( $fields, '' ), '', $t0, $two_hours )['ok'] );
foreach ( array(
	'another person'         => str_replace( 'v1.7.3.', 'v1.7.4.', $value ),
	'another device'         => str_replace( 'v1.7.3.', 'v1.8.3.', $value ),
	'a later "last used"'    => str_replace( '.1800000000.1800000000.', '.1800000000.1800003000.', $value ),
	'a later "unlocked at"'  => str_replace( '.1800000000.1800000000.', '.1800003000.1800003000.', $value ),
	'another session id'     => str_replace( str_repeat( 'ab', 16 ), str_repeat( 'cd', 16 ), $value ),
) as $what => $bent ) {
	$r = Session::check( $bent, $key, $t0 + 10, $two_hours );
	check( 'changed to ' . $what . ': the signature no longer fits', $bent !== $value && ! $r['ok'] && 'signature' === $r['reason'] && 0 === $r['person_id'] );
}
$sig = substr( $value, -64 );
check( 'a signature with one character changed: refused', 'signature' === Session::check( substr( $value, 0, -1 ) . ( 'a' === substr( $sig, -1 ) ? 'b' : 'a' ), $key, $t0, $two_hours )['reason'] );
foreach ( array( '', 'v1', $value . 'x', $value . "\n", 'v2' . substr( $value, 2 ), str_replace( '.', '|', $value ), substr( $value, 0, -65 ), array( $value ), null, str_repeat( 'a', 5000 ) ) as $junk ) {
	$r = Session::check( $junk, $key, $t0, $two_hours );
	if ( $r['ok'] || 'malformed' !== $r['reason'] ) {
		check( 'junk is malformed', false, json_encode( $junk ) );
	}
}
check( 'anything that is not such a value is malformed (ten kinds tried)', true );
check( 'the signature is an HMAC over everything before it — not a plain hash anyone could make', $sig === hash_hmac( 'sha256', substr( $value, 0, -65 ), $key ) && $sig !== hash( 'sha256', substr( $value, 0, -65 ) ) );

echo "\n3. Two hours since the last use (the default)\n";
check( 'the default: 120 minutes, counted from the last use', 7200 === Session::length( Settings::defaults() ) && Session::MODE_LAST_USE === Session::mode( Settings::defaults() ) );
check( 'just unlocked: live, ends in two hours', Session::check( $value, $key, $t0, $two_hours )['ok'] && $t0 + 7200 === Session::check( $value, $key, $t0, $two_hours )['expires'] );
check( 'two hours later to the second: still live', Session::check( $value, $key, $t0 + 7200, $two_hours )['ok'] );
check( 'one second more: expired', 'expired' === Session::check( $value, $key, $t0 + 7201, $two_hours )['reason'] );
$used = Session::build( array_merge( $fields, array( 'last' => $t0 + 7000 ) ), $key );
check( 'used after 1 h 56 min: the two hours start again from then', Session::check( $used, $key, $t0 + 14200, $two_hours )['ok'] && $t0 + 14200 === Session::check( $used, $key, $t0 + 14200, $two_hours )['expires'] );
check( '… so a device in use all day never locks, and one left alone locks two hours after', 'expired' === Session::check( $used, $key, $t0 + 14201, $two_hours )['reason'] );
check( 'a "last used" from the future is refused (beyond two minutes of clock difference)', 'times' === Session::check( Session::build( array_merge( $fields, array( 'last' => $t0 + 500 ) ), $key ), $key, $t0, $two_hours )['reason'] && Session::check( Session::build( array_merge( $fields, array( 'last' => $t0 + 100 ) ), $key ), $key, $t0, $two_hours )['ok'] );
check( 'a "last used" before the unlock is refused', 'times' === Session::check( Session::build( array_merge( $fields, array( 'last' => $t0 - 5 ) ), $key ), $key, $t0, $two_hours )['reason'] );
check( 'the cookie is written again on use — but not within a minute of the last time', ! Session::renewal_due( Session::check( $value, $key, $t0 + 59, $two_hours ), $t0 + 59, $two_hours ) && Session::renewal_due( Session::check( $value, $key, $t0 + 60, $two_hours ), $t0 + 60, $two_hours ) );
check( 'a session that does not verify is never renewed', ! Session::renewal_due( Session::check( $value, 'other', $t0 + 600, $two_hours ), $t0 + 600, $two_hours ) );

echo "\n4. Two hours since the unlock\n";
check( 'just unlocked: ends in two hours', $t0 + 7200 === Session::check( $value, $key, $t0, $from_unlock )['expires'] );
check( 'used after 1 h 56 min: it still ends two hours after the unlock', Session::check( $used, $key, $t0 + 7200, $from_unlock )['ok'] && $t0 + 7200 === Session::check( $used, $key, $t0 + 7200, $from_unlock )['expires'] && 'expired' === Session::check( $used, $key, $t0 + 7201, $from_unlock )['reason'] );
check( 'in this mode the cookie is never written again', ! Session::renewal_due( Session::check( $used, $key, $t0 + 7100, $from_unlock ), $t0 + 7100, $from_unlock ) );

echo "\n5. The length setting\n";
check( '30 minutes', 'expired' === Session::check( $value, $key, $t0 + 1801, array( 'session_minutes' => 30 ) )['reason'] && Session::check( $value, $key, $t0 + 1800, array( 'session_minutes' => 30 ) )['ok'] );
check( '12 hours', Session::check( $value, $key, $t0 + 43200, array( 'session_minutes' => 720 ) )['ok'] && 'expired' === Session::check( $value, $key, $t0 + 43201, array( 'session_minutes' => 720 ) )['reason'] );
check( 'out of range is held to 30 minutes – 12 hours', 1800 === Session::length( array( 'session_minutes' => 1 ) ) && 43200 === Session::length( array( 'session_minutes' => 100000 ) ) && 7200 === Session::length( array() ) );
check( 'shortening the setting ends a live session that is older than the new length', Session::check( $value, $key, $t0 + 3600, $two_hours )['ok'] && 'expired' === Session::check( $value, $key, $t0 + 3600, array( 'session_minutes' => 30 ) )['reason'] );

echo "\n6. On a device\n";
$store = fh_fresh_store();
$owner = fh_add_user( 'owner', 'administrator', 'Olive Owner' );
fh_request();
$sam    = fh_add_person( 'Sam', '4821' );
$robin  = fh_add_person( 'Robin', '1379' );
$tablet = Access::instance()->enrol_here( 'Shop tablet', $owner );
$jar    = fh_jar( array() );

fh_request( array( 'cookies' => $jar ) );
check( 'an enrolled device that nobody unlocked has no session', null === Access::instance()->context()['session'] );

fh_request( array( 'cookies' => $jar ) );
$unlock = Access::instance()->unlock( '4821' );
$sent   = $GLOBALS['fh_sent']['cookies'];
$jar    = fh_jar( $jar );
$cookie = null;
foreach ( $sent as $c ) {
	if ( $c['name'] === $session_cookie && '' !== $c['value'] ) {
		$cookie = $c;
	}
}
check( 'a correct PIN answers with the person', $unlock['ok'] && 'Sam' === $unlock['person']['name'] && $tablet['id'] === $unlock['person']['device_id'] );
check( 'the session cookie is Secure, HttpOnly and SameSite=Lax', $cookie && true === $cookie['options']['secure'] && true === $cookie['options']['httponly'] && 'Lax' === $cookie['options']['samesite'] );
check( 'it ends in the browser when the session does: two hours from now', $cookie && $GLOBALS['fh_now'] + 7200 === $cookie['options']['expires'] );
$parsed = Session::parse( $jar[ $session_cookie ] );
check( 'it names this device and this person', $parsed && $tablet['id'] === $parsed['device_id'] && $sam === $parsed['person_id'] );
check( 'it carries no name and no PIN', false === strpos( $jar[ $session_cookie ], 'Sam' ) && false === strpos( $jar[ $session_cookie ], '4821' ) );
check( 'the device row holds the same session id, the person and the time', $parsed['session_id'] === $store->devices[ $tablet['id'] ]['session_id'] && $sam === $store->devices[ $tablet['id'] ]['session_person_id'] && gmdate( 'Y-m-d H:i:s', $GLOBALS['fh_now'] ) === $store->devices[ $tablet['id'] ]['session_started_at'] );
$last = end( $store->log );
check( 'the unlock is in the log: the device, the person, the account', 'unlock' === $last['event'] && 'Shop tablet' === $last['device_name'] && 'Sam' === $last['person_name'] && $last['user_id'] > 0 );
check( 'the person\'s "last unlock" is stamped', gmdate( 'Y-m-d H:i:s', $GLOBALS['fh_now'] ) === $store->people[ $sam ]['last_unlock_at'] );

fh_request( array( 'cookies' => $jar ) );
$ctx = Access::instance()->context();
check( 'the next request is that person\'s session', $ctx['session'] && 'Sam' === $ctx['session']['person']['name'] && $ctx['session']['user_id'] > 0 && $GLOBALS['fh_now'] + 7200 === $ctx['session']['expires'] );
check( 'within a minute nothing is sent again', array() === $GLOBALS['fh_sent']['cookies'] );

$GLOBALS['fh_now'] += 3600;
fh_request( array( 'cookies' => $jar ) );
$ctx  = Access::instance()->context();
$sent = fh_cookies_set();
check( 'an hour later, used: the cookie comes again with "last used" = now, ending two hours from now', isset( $sent[ $session_cookie ] ) && $GLOBALS['fh_now'] === Session::parse( $sent[ $session_cookie ] )['last'] && $GLOBALS['fh_now'] - 3600 === Session::parse( $sent[ $session_cookie ] )['started'] && $GLOBALS['fh_now'] + 7200 === $ctx['session']['expires'] );
$jar = fh_jar( $jar );

$GLOBALS['fh_now'] += 7000;
fh_request( array( 'cookies' => $jar ) );
check( '1 h 56 min after that use (almost three hours after the PIN): still unlocked', null !== Access::instance()->context()['session'] );
$jar = fh_jar( $jar );

$GLOBALS['fh_now'] += 7201;
fh_request( array( 'cookies' => $jar ) );
$ctx = Access::instance()->context();
check( 'left alone for two hours and a second: locked', null === $ctx['session'] && null !== $ctx['device'] );
check( '… the stale cookie is taken away', '' === ( fh_cookies_set()[ $session_cookie ] ?? 'kept' ) );
check( '… and the device row no longer says somebody is unlocked', '' === $store->devices[ $tablet['id'] ]['session_id'] && 0 === $store->devices[ $tablet['id'] ]['session_person_id'] );
check( 'nothing is logged for running out (only for a Lock)', ! in_array( 'lock', $store->events(), true ) );

echo "\n7. Counted from the unlock, on a device\n";
Settings::save( array( 'session_minutes' => 120, 'session_mode' => 'unlock', 'pin_length' => 4 ) );
$jar = fh_jar( $jar );
fh_request( array( 'cookies' => $jar ) );
Access::instance()->unlock( '4821' );
$jar = fh_jar( $jar );
$GLOBALS['fh_now'] += 7000;
fh_request( array( 'cookies' => $jar ) );
check( 'used at 1 h 56 min: live, and no new cookie', null !== Access::instance()->context()['session'] && ! isset( fh_cookies_set()[ $session_cookie ] ) );
$GLOBALS['fh_now'] += 201;
fh_request( array( 'cookies' => $jar ) );
check( 'two hours and a second after the PIN: locked, though it was used five minutes ago', null === Access::instance()->context()['session'] );
Settings::save( Settings::defaults() );

echo "\n8. Lock\n";
$jar = fh_jar( $jar );
fh_request( array( 'cookies' => $jar ) );
Access::instance()->unlock( '1379' );
$jar  = fh_jar( $jar );
$copy = $jar; // Somebody kept a copy of both cookies.
fh_request( array( 'cookies' => $jar ) );
check( 'Robin is unlocked', 'Robin' === Access::instance()->context()['session']['person']['name'] );

fh_request( array( 'cookies' => $jar ) );
check( 'lock() says it ended a session', true === Access::instance()->lock() );
check( 'the session cookie is deleted in the browser', '' === ( fh_cookies_set()[ $session_cookie ] ?? 'kept' ) );
$jar = fh_jar( $jar );
check( 'the device cookie stays: it is still a shop device', isset( $jar[ $device_cookie ] ) && ! isset( $jar[ $session_cookie ] ) );
check( 'the device row holds no session', '' === $store->devices[ $tablet['id'] ]['session_id'] );
$last = end( $store->log );
check( 'the lock is in the log: the device and who was unlocked', 'lock' === $last['event'] && 'Robin' === $last['person_name'] && 'Shop tablet' === $last['device_name'] );

fh_request( array( 'cookies' => $jar ) );
check( 'after Lock the device has no session', null === Access::instance()->context()['session'] );
fh_request( array( 'cookies' => $copy ) );
$ctx = Access::instance()->context();
check( 'the COPY of the cookies — signature intact, two hours not over — is no session either: Lock is on the server', Session::check( $copy[ $session_cookie ], Settings::session_key(), $GLOBALS['fh_now'], Settings::get() )['ok'] && null === $ctx['session'] );
fh_request( array( 'cookies' => $jar ) );
check( 'locking a device that is already locked ends nothing and logs nothing', false === Access::instance()->lock() && 1 === count( array_keys( $store->events(), 'lock', true ) ) );

echo "\n9. Bound to its device, its person and the newest unlock\n";
fh_request();
$phone   = Access::instance()->enrol_here( 'Phone', $owner );
$jar_two = fh_jar( array() );
fh_request( array( 'cookies' => $jar ) );
Access::instance()->unlock( '4821' );
$jar = fh_jar( $jar );

$mixed                    = $jar_two;
$mixed[ $session_cookie ] = $jar[ $session_cookie ];
fh_request( array( 'cookies' => $mixed ) );
check( 'the tablet\'s session cookie on the phone is no session', null === Access::instance()->context()['session'] );

// A cookie forged with the right key for another person on this device.
$forged                    = $jar;
$forged[ $session_cookie ] = Session::build( array( 'device_id' => $tablet['id'], 'person_id' => $robin, 'session_id' => $store->devices[ $tablet['id'] ]['session_id'], 'started' => $GLOBALS['fh_now'], 'last' => $GLOBALS['fh_now'] ), Settings::session_key() );
fh_request( array( 'cookies' => $forged ) );
check( 'even a correctly signed cookie naming another person than the device row holds is no session', null === Access::instance()->context()['session'] );

$older = $jar;
fh_request( array( 'cookies' => $jar ) );
Access::instance()->unlock( '1379' );
$jar = fh_jar( $jar );
fh_request( array( 'cookies' => $older ) );
check( 'a new unlock on the device ends the earlier session: its cookie is dead', null === Access::instance()->context()['session'] );
fh_request( array( 'cookies' => $jar ) );
check( '… and the new one is live', 'Robin' === ( Access::instance()->context()['session']['person']['name'] ?? '' ) );

echo "\n10. A person switched off, a missing account\n";
$store->update_person( $robin, array( 'active' => 0, 'pin_hash' => '', 'pin_salt' => '', 'pin_length' => 0 ) );
fh_request( array( 'cookies' => $jar ) );
check( 'switching a person off ends their session at its next request', null === Access::instance()->context()['session'] && '' === $store->devices[ $tablet['id'] ]['session_id'] );

$jar = fh_jar( $jar );
fh_request( array( 'cookies' => $jar ) );
$r       = Access::instance()->unlock( '4821' );
$jar     = fh_jar( $jar );
$staff   = (int) $GLOBALS['fh_options'][ \FisHotel\Misc\Sections\Shop_App\People::STAFF_OPTION ];
$account = get_userdata( $staff );
check( 'a person without an account of their own is recorded under the shared "Shop staff" account', $r['ok'] && $staff > 0 && $staff === $r['person']['user_id'] && 'fishotel-shop-staff' === $account->user_login );
check( '… which holds a role with no capability at all', array( 'fishotel_shop_staff' ) === $account->roles && array() === $GLOBALS['fh_roles']['fishotel_shop_staff'] && ! $account->has_cap( 'read' ) && ! $account->has_cap( 'manage_woocommerce' ) );
check( '… and has no e-mail address: nothing can ever be sent to it', '' === $GLOBALS['fh_user_emails'][ $staff ] );
check( '… and is made once', $staff === \FisHotel\Misc\Sections\Shop_App\People::staff_user_id( true ) && 1 === count( array_filter( $GLOBALS['fh_users'], static function ( $u ) { return 'fishotel-shop-staff' === $u['login']; } ) ) );

$own = fh_add_user( 'sam', 'shop_manager', 'Sam Shore' );
$store->update_person( $sam, array( 'user_id' => $own ) );
fh_request( array( 'cookies' => $jar ) );
check( 'tied to their own account, the session is that account from its next request', $own === Access::instance()->context()['session']['user_id'] );
unset( $GLOBALS['fh_users'][ $own ] );
fh_request( array( 'cookies' => $jar ) );
check( 'when that account is deleted the work goes to the shared account again', $staff === Access::instance()->context()['session']['user_id'] );
check( '… and the People list says so', true === \FisHotel\Misc\Sections\Shop_App\People::account_label( $store->person( $sam ) )['missing'] );

echo "\n11. New WordPress keys\n";
fh_request( array( 'cookies' => $jar ) );
check( 'the session is live', null !== Access::instance()->context()['session'] );
$GLOBALS['fh_salt'] = 'rotated-';
fh_request( array( 'cookies' => $jar ) );
$ctx = Access::instance()->context();
check( 'after the WordPress keys change every shop session is over', null === $ctx['session'] );
check( '… the device is still enrolled', null !== $ctx['device'] );
$jar = fh_jar( $jar );
fh_request( array( 'cookies' => $jar ) );
check( '… and the same PIN unlocks it again', Access::instance()->unlock( '4821' )['ok'] );
unset( $GLOBALS['fh_salt'] );

fh_finish();
