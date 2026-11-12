<?php
/**
 * Lockout tests — five wrong PINs lock a device for five minutes, each
 * lockout after that doubles (to a day at most), a correct PIN clears the
 * count of wrong ones but never the doubling, the doubling starts over a
 * day after the last lockout ended; while a device is locked not even the
 * right PIN works; a wrong PIN gives the same answer whoever else it may
 * belong to; every lockout is logged once; a burst of requests gets five
 * tries like anybody else.
 *
 * Run from the plugin root:
 *   php includes/sections/shop-app/tests/lockout-test.php
 *
 * @package FisHotel\Misc\Sections\Shop_App
 */

require_once __DIR__ . '/wp-stubs.php';

use FisHotel\Misc\Sections\Shop_App\Access;
use FisHotel\Misc\Sections\Shop_App\Http;
use FisHotel\Misc\Sections\Shop_App\Lockout;
use FisHotel\Misc\Sections\Shop_App\Store;

echo "1. The rule (pure)\n";
check( 'five wrong PINs, five minutes, a day at most', 5 === Lockout::MAX_ATTEMPTS && 300 === Lockout::BASE_SECONDS && 86400 === Lockout::CAP_SECONDS );
check( 'the first lockout is 5 minutes', 300 === Lockout::duration( 0 ) );
check( 'then 10, 20, 40, 80 minutes', array( 600, 1200, 2400, 4800 ) === array( Lockout::duration( 1 ), Lockout::duration( 2 ), Lockout::duration( 3 ), Lockout::duration( 4 ) ) );
check( 'it never passes 24 hours', 86400 === Lockout::duration( 9 ) && 86400 === Lockout::duration( 30 ) && 86400 === Lockout::duration( 5000 ) && 76800 === Lockout::duration( 8 ) );
check( 'a negative level is the first lockout', 300 === Lockout::duration( -3 ) );

$now = 1800000000;
$row = array( 'fail_count' => 0, 'lock_level' => 0, 'locked_until' => null );
check( 'a device with no lockout is not locked', ! Lockout::status( $row, $now )['locked'] && 0 === Lockout::status( $row, $now )['seconds'] );
check( 'a lockout in the future: locked, with the seconds left', Lockout::status( array( 'locked_until' => gmdate( 'Y-m-d H:i:s', $now + 90 ) ), $now )['locked'] && 90 === Lockout::status( array( 'locked_until' => gmdate( 'Y-m-d H:i:s', $now + 90 ) ), $now )['seconds'] );
check( 'a lockout that ended (even this second): not locked', ! Lockout::status( array( 'locked_until' => gmdate( 'Y-m-d H:i:s', $now ) ), $now )['locked'] && ! Lockout::status( array( 'locked_until' => gmdate( 'Y-m-d H:i:s', $now - 1 ) ), $now )['locked'] );
check( 'an empty or zero date is no lockout', ! Lockout::status( array( 'locked_until' => '' ), $now )['locked'] && ! Lockout::status( array( 'locked_until' => '0000-00-00 00:00:00' ), $now )['locked'] && ! Lockout::status( array(), $now )['locked'] );

check( 'four wrong PINs change nothing', array() === Lockout::after_failure( array( 'fail_count' => 4 ), $now )['fields'] && ! Lockout::after_failure( array( 'fail_count' => 4 ), $now )['locked'] );
$fifth = Lockout::after_failure( array( 'fail_count' => 5, 'lock_level' => 0, 'locked_until' => null ), $now );
check( 'the fifth locks for 5 minutes, raises the level and clears the count', $fifth['locked'] && 300 === $fifth['seconds'] && gmdate( 'Y-m-d H:i:s', $now + 300 ) === $fifth['fields']['locked_until'] && 1 === $fifth['fields']['lock_level'] && 0 === $fifth['fields']['fail_count'] );
$again = Lockout::after_failure( array( 'fail_count' => 5, 'lock_level' => 1, 'locked_until' => gmdate( 'Y-m-d H:i:s', $now - 60 ) ), $now );
check( 'five more soon after: 10 minutes', 600 === $again['seconds'] && 2 === $again['fields']['lock_level'] );
$later = Lockout::after_failure( array( 'fail_count' => 5, 'lock_level' => 4, 'locked_until' => gmdate( 'Y-m-d H:i:s', $now - 86401 ) ), $now );
check( 'more than a day after the last lockout ended the doubling starts over: 5 minutes', 300 === $later['seconds'] && 1 === $later['fields']['lock_level'] );
$edge = Lockout::after_failure( array( 'fail_count' => 5, 'lock_level' => 4, 'locked_until' => gmdate( 'Y-m-d H:i:s', $now - 86400 ) ), $now );
check( '… exactly a day after is still doubling', 4800 === $edge['seconds'] );
check( 'a correct PIN clears the count and leaves the level alone', array( 'fail_count' => 0 ) === Lockout::after_success() );
check( 'tries left', 5 === Lockout::attempts_left( array( 'fail_count' => 0 ) ) && 1 === Lockout::attempts_left( array( 'fail_count' => 4 ) ) && 0 === Lockout::attempts_left( array( 'fail_count' => 9 ) ) );
check( 'in words', '5 minutes' === Lockout::human( 300 ) && '1 hour 20 minutes' === Lockout::human( 4800 ) && '24 hours' === Lockout::human( 86400 ) && '1 minute' === Lockout::human( 30 ) && '2 hours' === Lockout::human( 7200 ) );

echo "\n2. On a device\n";
$store = fh_fresh_store();
fh_request();
$sam   = fh_add_person( 'Sam', '4821' );
$robin = fh_add_person( 'Robin', '1379' );
$jo    = fh_add_person( 'Jo (off)', '6666', 0, false );
$admin = fh_add_user( 'owner', 'administrator', 'Olive Owner' );

$device = Access::instance()->enrol_here( 'Shop tablet', $admin );
$jar    = fh_jar( array() );
$id     = (int) $device['id'];

/**
 * One unlock attempt as a request of its own.
 */
function fh_try( array &$jar, $pin ) {
	fh_request( array( 'cookies' => $jar ) );
	$result = Access::instance()->unlock( $pin );
	$jar    = fh_jar( $jar );
	return $result;
}

$wrong = fh_try( $jar, '0000' );
check( 'a wrong PIN: "wrong_pin", four tries left', ! $wrong['ok'] && 'wrong_pin' === $wrong['code'] && 4 === $wrong['left'] );
$other = fh_try( $jar, '6666' );
check( 'the PIN of somebody switched off gives exactly the same kind of answer', array( 'ok', 'code', 'left' ) === array_keys( $other ) && 'wrong_pin' === $other['code'] && 3 === $other['left'] );
$bad = fh_try( $jar, 'abcd' );
check( 'something that is not a PIN counts as a wrong PIN too', 'wrong_pin' === $bad['code'] && 2 === $bad['left'] );
$keys_wrong = array_keys( $wrong );
check( 'no answer to a wrong PIN carries a person', ! isset( $wrong['person'] ) && ! isset( $other['person'] ) && ! isset( $bad['person'] ) && $keys_wrong === array_keys( $bad ) );
check( 'the count is on the device row', 3 === $store->devices[ $id ]['fail_count'] );
check( 'nothing is logged for a wrong PIN', array( 'device_enrolled' ) === $store->events() );

$good = fh_try( $jar, '4821' );
check( 'the right PIN unlocks', $good['ok'] && 'Sam' === $good['person']['name'] );
check( '… and clears the count of wrong ones', 0 === $store->devices[ $id ]['fail_count'] );
Access::instance()->lock();
$jar = fh_jar( $jar );

for ( $i = 1; $i <= 4; $i++ ) {
	$r = fh_try( $jar, '000' . $i );
}
check( 'four wrong in a row: still not locked, one try left', 'wrong_pin' === $r['code'] && 1 === $r['left'] && null === $store->devices[ $id ]['locked_until'] );
$r = fh_try( $jar, '0005' );
check( 'the fifth locks the device for 5 minutes', 'locked' === $r['code'] && 300 === $r['seconds'] );
check( '… on the device row: until now + 5 minutes, level 1, count 0', gmdate( 'Y-m-d H:i:s', $GLOBALS['fh_now'] + 300 ) === $store->devices[ $id ]['locked_until'] && 1 === $store->devices[ $id ]['lock_level'] && 0 === $store->devices[ $id ]['fail_count'] );
$last = end( $store->log );
check( '… and in the log: a lockout, with the device and how long', 'lockout' === $last['event'] && $id === $last['device_id'] && 'Shop tablet' === $last['device_name'] && false !== strpos( $last['note'], '5 minutes' ) );
check( '… once', 1 === count( array_keys( $store->events(), 'lockout', true ) ) );

$store->queries = 0;
$r              = fh_try( $jar, '4821' );
check( 'while locked the RIGHT PIN is refused too: "locked", with the time left', ! $r['ok'] && 'locked' === $r['code'] && 300 === $r['seconds'] );
check( '… without looking at anybody\'s PIN (the people are not even read)', 1 === $store->queries, (string) $store->queries );
check( '… and without using up tries', 0 === $store->devices[ $id ]['fail_count'] );
$GLOBALS['fh_now'] += 299;
check( 'a second before it ends: still locked, 1 second left', 1 === fh_try( $jar, '4821' )['seconds'] );
$GLOBALS['fh_now'] += 1;
$r = fh_try( $jar, '4821' );
check( 'when the five minutes are over the right PIN unlocks', $r['ok'] );
check( '… the count of wrong PINs is clear, the level is kept', 0 === $store->devices[ $id ]['fail_count'] && 1 === $store->devices[ $id ]['lock_level'] );
Access::instance()->lock();
$jar = fh_jar( $jar );

for ( $i = 1; $i <= 5; $i++ ) {
	$r = fh_try( $jar, '100' . $i );
}
check( 'five more wrong PINs: locked for 10 minutes — a correct PIN in between did not reset the doubling', 'locked' === $r['code'] && 600 === $r['seconds'] && 2 === $store->devices[ $id ]['lock_level'] );
$GLOBALS['fh_now'] += 600;
for ( $i = 1; $i <= 5; $i++ ) {
	$r = fh_try( $jar, '200' . $i );
}
check( 'and five more: 20 minutes', 600 * 2 === $r['seconds'] );
check( 'three lockouts, three log entries', 3 === count( array_keys( $store->events(), 'lockout', true ) ) );

$GLOBALS['fh_now'] += 1200 + 86401;
for ( $i = 1; $i <= 5; $i++ ) {
	$r = fh_try( $jar, '300' . $i );
}
check( 'more than a day after the last lockout ended: back to 5 minutes', 'locked' === $r['code'] && 300 === $r['seconds'] && 1 === $store->devices[ $id ]['lock_level'] );

echo "\n3. Clearing a lockout from the Devices list\n";
check( 'the device is locked', Lockout::status( $store->devices[ $id ], $GLOBALS['fh_now'] )['locked'] );
fh_request();
check( 'clear_lockout() says it did', Access::instance()->clear_lockout( $id, $admin ) );
check( '… the lockout, the level and the count are gone', null === $store->devices[ $id ]['locked_until'] && 0 === $store->devices[ $id ]['lock_level'] && 0 === $store->devices[ $id ]['fail_count'] );
$last = end( $store->log );
check( '… and it is logged with who cleared it', 'lockout_cleared' === $last['event'] && $admin === $last['user_id'] );
check( 'the right PIN works at once', fh_try( $jar, '1379' )['ok'] );
check( 'a device that is not enrolled has no lockout to clear', ! Access::instance()->clear_lockout( 999, $admin ) );

echo "\n4. A burst of requests gets five tries, not more\n";
$store  = fh_fresh_store();
fh_request();
fh_add_person( 'Sam', '4821' );
$device = Access::instance()->enrol_here( 'Phone', $admin );
$id     = (int) $device['id'];
$took   = 0;
for ( $i = 0; $i < 12; $i++ ) {
	// Twelve requests that all read the device before any of them wrote.
	if ( $store->reserve_attempt( $id, Lockout::MAX_ATTEMPTS, $GLOBALS['fh_now'] ) ) {
		++$took;
	}
}
check( 'of twelve at once, five may compare a PIN', 5 === $took );
check( '… the sixth is refused before any PIN is compared, though no lockout is written yet', null === $store->devices[ $id ]['locked_until'] && ! $store->reserve_attempt( $id, Lockout::MAX_ATTEMPTS, $GLOBALS['fh_now'] ) );
$locks = 0;
for ( $i = 0; $i < 5; $i++ ) {
	$after = Lockout::after_failure( $store->devices[ $id ], $GLOBALS['fh_now'] );
	if ( $after['locked'] && $store->lock_device( $id, $after['fields'], Lockout::MAX_ATTEMPTS ) ) {
		++$locks;
	}
}
check( '… and of the five that failed exactly one writes the lockout', 1 === $locks && 1 === $store->devices[ $id ]['lock_level'] );
$jar = fh_jar( array() );
fh_request( array( 'cookies' => $jar ) );
$r = Access::instance()->unlock( '4821' );
check( 'a request arriving in that state is told "locked"', 'locked' === $r['code'] && $r['seconds'] >= 5 );
check( 'a revoked device cannot reserve a try at all', $store->revoke_device( $id, $admin, $GLOBALS['fh_now'] ) && ! $store->reserve_attempt( $id, Lockout::MAX_ATTEMPTS, $GLOBALS['fh_now'] + 100000 ) );

echo "\n5. A device that is not enrolled never gets to try\n";
$store = fh_fresh_store();
fh_request();
fh_add_person( 'Sam', '4821' );
$store->queries = 0;
$r              = Access::instance()->unlock( '4821' );
check( 'the right PIN from a browser without a device cookie: "not_enrolled"', ! $r['ok'] && 'not_enrolled' === $r['code'] );
check( '… and not one query was made for it', 0 === $store->queries, (string) $store->queries );
fh_request( array( 'cookies' => array( Http::cookie_name( Http::DEVICE ) => str_repeat( 'a', 64 ) ) ) );
$r = Access::instance()->unlock( '4821' );
check( 'the right PIN with a made-up device cookie: "not_enrolled"', 'not_enrolled' === $r['code'] );
check( 'no session cookie was handed out either way', array() === array_filter( fh_cookies_set() ) );

fh_finish();
