<?php
/**
 * Lockout tests — five wrong PINs within fifteen minutes lock a device
 * for five minutes, each lockout after that doubles (to a day at most),
 * the doubling starts over a day after the last lockout ended; a correct
 * PIN gives back the one try it took and NOT the wrong ones before it —
 * so a PIN of one's own is no way to walk through everybody else's —
 * and those lapse fifteen minutes after the count began; while a device
 * is locked not even the right PIN works; a wrong PIN gives the same
 * answer whoever else it may belong to and is logged without the digits;
 * every lockout is logged once; a burst of requests gets five tries like
 * anybody else; a device whose tries are used up with no lockout written
 * gets its lockout on the next try instead of refusing every PIN for
 * good.
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
check( 'five wrong PINs within fifteen minutes, five minutes, a day at most', 5 === Lockout::MAX_ATTEMPTS && 900 === Lockout::FAIL_MEMORY && 300 === Lockout::BASE_SECONDS && 86400 === Lockout::CAP_SECONDS );
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
check( 'nothing in the rule clears the count for a correct PIN', ! method_exists( Lockout::class, 'after_success' ) );
$since = gmdate( 'Y-m-d H:i:s', $now - 899 );
check( 'wrong PINs count for fifteen minutes from the first of them', 3 === Lockout::count( array( 'fail_count' => 3, 'fail_since' => $since ), $now ) && 0 === Lockout::count( array( 'fail_count' => 3, 'fail_since' => $since ), $now + 1 ) && 0 === Lockout::count( array( 'fail_count' => 0, 'fail_since' => null ), $now ) && 2 === Lockout::count( array( 'fail_count' => 2 ), $now ) );
check( 'Clear lockout clears the count, when it began, the level and the lockout', array( 'fail_count' => 0, 'fail_since' => null, 'lock_level' => 0, 'locked_until' => null ) === Lockout::cleared() );
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
check( 'the count is on the device row, with when it began', 3 === $store->devices[ $id ]['fail_count'] && gmdate( 'Y-m-d H:i:s', $GLOBALS['fh_now'] ) === $store->devices[ $id ]['fail_since'] );
check( 'every wrong PIN is in the log', array( 'device_enrolled', 'wrong_pin', 'wrong_pin', 'wrong_pin' ) === $store->events() );
$logged = array_slice( $store->log, 1 );
check( '… with the device and which try it was — and neither the digits typed nor a person', array( 'Try 1 of 5', 'Try 2 of 5', 'Try 3 of 5' ) === array_column( $logged, 'note' ) && array( $id, $id, $id ) === array_column( $logged, 'device_id' ) && array( 0, 0, 0 ) === array_column( $logged, 'person_id' ) && false === strpos( json_encode( $logged ), '0000' ) && false === strpos( json_encode( $logged ), '6666' ) && false === strpos( json_encode( $logged ), 'abcd' ) && false === strpos( json_encode( $logged ), 'Jo' ) );

$good = fh_try( $jar, '4821' );
check( 'the right PIN unlocks', $good['ok'] && 'Sam' === $good['person']['name'] );
check( '… and gives back the one try it took — the three wrong ones before it still count', 3 === $store->devices[ $id ]['fail_count'] );
Access::instance()->lock();
$jar = fh_jar( $jar );
$r   = fh_try( $jar, '0004' );
check( 'so the next wrong PIN is the fourth, not the first: one try left', 'wrong_pin' === $r['code'] && 1 === $r['left'] );
$good = fh_try( $jar, '4821' );
check( 'the right PIN still works with one try left — and leaves it at one', $good['ok'] && 4 === $store->devices[ $id ]['fail_count'] );
Access::instance()->lock();
$jar                = fh_jar( $jar );
$GLOBALS['fh_now'] += 899;
$r                  = fh_try( $jar, '4821' );
check( 'a second before the fifteen minutes are over they still count', $r['ok'] && 4 === $store->devices[ $id ]['fail_count'] );
Access::instance()->lock();
$jar                = fh_jar( $jar );
$GLOBALS['fh_now'] += 1;
$r                  = fh_try( $jar, '0000' );
check( 'fifteen minutes after the first of them the count starts over: a wrong PIN is the first again', 'wrong_pin' === $r['code'] && 4 === $r['left'] && 1 === $store->devices[ $id ]['fail_count'] && gmdate( 'Y-m-d H:i:s', $GLOBALS['fh_now'] ) === $store->devices[ $id ]['fail_since'] );

for ( $i = 2; $i <= 4; $i++ ) {
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
check( '… the lockout cleared the count of wrong PINs, the level is kept', 0 === $store->devices[ $id ]['fail_count'] && 1 === $store->devices[ $id ]['lock_level'] );
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
check( 'the log says how many, within how long, and for how long', false !== strpos( end( $store->log )['note'], '5 wrong PINs within 15 minutes: locked for 20 minutes' ) );

$GLOBALS['fh_now'] += 1200 + 86401;
for ( $i = 1; $i <= 5; $i++ ) {
	$r = fh_try( $jar, '300' . $i );
}
check( 'more than a day after the last lockout ended: back to 5 minutes', 'locked' === $r['code'] && 300 === $r['seconds'] && 1 === $store->devices[ $id ]['lock_level'] );

echo "\n3. Clearing a lockout from the Devices list\n";
check( 'the device is locked', Lockout::status( $store->devices[ $id ], $GLOBALS['fh_now'] )['locked'] );
fh_request();
check( 'clear_lockout() says it did', Access::instance()->clear_lockout( $id, $admin ) );
check( '… the lockout, the level and the count are gone', null === $store->devices[ $id ]['locked_until'] && 0 === $store->devices[ $id ]['lock_level'] && 0 === $store->devices[ $id ]['fail_count'] && null === $store->devices[ $id ]['fail_since'] );
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
	if ( $store->reserve_attempt( $id, Lockout::MAX_ATTEMPTS, $GLOBALS['fh_now'], Lockout::FAIL_MEMORY ) ) {
		++$took;
	}
}
check( 'of twelve at once, five may compare a PIN', 5 === $took );
check( '… the sixth is refused before any PIN is compared, though no lockout is written yet', null === $store->devices[ $id ]['locked_until'] && ! $store->reserve_attempt( $id, Lockout::MAX_ATTEMPTS, $GLOBALS['fh_now'], Lockout::FAIL_MEMORY ) );
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
check( 'a revoked device cannot reserve a try at all', $store->revoke_device( $id, $admin, $GLOBALS['fh_now'] ) && ! $store->reserve_attempt( $id, Lockout::MAX_ATTEMPTS, $GLOBALS['fh_now'] + 100000, Lockout::FAIL_MEMORY ) );

echo "\n5. A PIN of one's own is no way to find somebody else's\n";
$store = fh_fresh_store();
fh_request();
fh_add_person( 'Sam', '4821' );
fh_add_person( 'Olive', '9042', $admin );
$device = Access::instance()->enrol_here( 'Shop tablet', $admin );
$id     = (int) $device['id'];
$jar    = fh_jar( array() );
$tried  = 0;
$found  = '';
$ended  = '';
// Sam knows 4821 and hunts for another PIN: four guesses, then the own PIN "to clear the count", and again.
for ( $guess = 9020; $guess < 9100 && '' === $ended && '' === $found; $guess += 4 ) {
	for ( $pin = $guess; $pin < $guess + 4; $pin++ ) {
		$r = fh_try( $jar, (string) $pin );
		++$tried;
		if ( $r['ok'] ) {
			$found = (string) $pin;
			break;
		}
		if ( 'wrong_pin' !== $r['code'] ) {
			$ended = $r['code'];
			break;
		}
	}
	if ( '' === $ended && '' === $found ) {
		$own = fh_try( $jar, '4821' );
		if ( ! $own['ok'] ) {
			$ended = 'own:' . $own['code'];
			break;
		}
		Access::instance()->lock();
		$jar = fh_jar( $jar );
	}
}
check( 'four guesses, the own PIN, four more …: the FIFTH guess locks the device — the own PIN in between cleared nothing', 'locked' === $ended && 5 === $tried && '' === $found, $ended . ' after ' . $tried );
check( '… the lockout is on the device row and in the log, with every guess before it', Lockout::status( $store->devices[ $id ], $GLOBALS['fh_now'] )['locked'] && 5 === count( array_keys( $store->events(), 'wrong_pin', true ) ) && 1 === count( array_keys( $store->events(), 'lockout', true ) ) );
check( '… and the own PIN does not open it now', 'locked' === fh_try( $jar, '4821' )['code'] );
$guesses = 0;
$locks   = 0;
$clock0  = $GLOBALS['fh_now'];
// An hour of it, as fast as the rule allows: guess whenever a guess is taken, wait when locked.
// (Stopped at 200: were the count cleared by the own PIN again, this would never lock — and never end.)
while ( $GLOBALS['fh_now'] - $clock0 < 3600 && $guesses < 200 ) {
	$r = fh_try( $jar, (string) ( 5000 + $guesses ) );
	if ( 'locked' === $r['code'] && ! isset( $r['left'] ) ) {
		$GLOBALS['fh_now'] += max( 1, (int) $r['seconds'] );
		++$locks;
		continue;
	}
	++$guesses;
	fh_try( $jar, '4821' );
}
check( 'an hour of guessing with the own PIN in between gets a few dozen tries at most — before the fix it was thousands', $guesses <= 30, $guesses . ' guesses, ' . $locks . ' waits' );

echo "\n6. Every try used up and no lockout written\n";
$store = fh_fresh_store();
fh_request();
fh_add_person( 'Sam', '4821' );
$device = Access::instance()->enrol_here( 'Shop tablet', $admin );
$id     = (int) $device['id'];
$jar    = fh_jar( array() );
for ( $i = 1; $i <= 4; $i++ ) {
	fh_try( $jar, '000' . $i );
}
$store->fail['lock_device'] = true; // The fifth wrong PIN's lockout does not land.
$r                          = fh_try( $jar, '0005' );
unset( $store->fail['lock_device'] );
check( 'the lockout write failed: the count stands at five and nothing is locked', 5 === $store->devices[ $id ]['fail_count'] && null === $store->devices[ $id ]['locked_until'] && 0 === count( array_keys( $store->events(), 'lockout', true ) ) );
$r = fh_try( $jar, '4821' );
check( 'the next try — even the right PIN — writes the lockout that was owed: locked for 5 minutes', 'locked' === $r['code'] && 300 === $r['seconds'] );
check( '… on the device row and in the log, so the Devices list shows it', gmdate( 'Y-m-d H:i:s', $GLOBALS['fh_now'] + 300 ) === $store->devices[ $id ]['locked_until'] && 1 === $store->devices[ $id ]['lock_level'] && 0 === $store->devices[ $id ]['fail_count'] && 1 === count( array_keys( $store->events(), 'lockout', true ) ) );
check( '… without a "wrong PIN" line for a PIN nobody compared', 5 === count( array_keys( $store->events(), 'wrong_pin', true ) ) );
$GLOBALS['fh_now'] += 300;
check( 'and five minutes later the right PIN unlocks — the device is not stuck', fh_try( $jar, '4821' )['ok'] );

$store = fh_fresh_store();
fh_request();
fh_add_person( 'Sam', '4821' );
$device                             = Access::instance()->enrol_here( 'Shop tablet', $admin );
$id                                 = (int) $device['id'];
$jar                                = fh_jar( array() );
$store->devices[ $id ]['fail_count'] = 5;
$store->devices[ $id ]['fail_since'] = gmdate( 'Y-m-d H:i:s', $GLOBALS['fh_now'] );
$store->fail['lock_device']          = true; // … and it keeps failing.
$r                                   = fh_try( $jar, '4821' );
check( 'while the lockout cannot be written the device says "locked"', 'locked' === $r['code'] );
$GLOBALS['fh_now'] += 900;
$r                  = fh_try( $jar, '4821' );
check( '… and once the fifteen minutes are over the right PIN works all the same: never locked for good', $r['ok'] && 0 === $store->devices[ $id ]['fail_count'] );

echo "\n7. A device that is not enrolled never gets to try\n";
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
