<?php
/**
 * PIN tests — the format, the hash (a per-person salt and the site secret;
 * never the PIN), the match that looks at everybody before it answers,
 * uniqueness among active people without saying whose PIN it is, the
 * person form's rules (a PIN needed, a clash, an account that may not be
 * chosen, switching off destroys the PIN), the PIN length setting, and
 * the settings' bounds.
 *
 * Run from the plugin root:
 *   php includes/sections/shop-app/tests/pins-test.php
 *
 * @package FisHotel\Misc\Sections\Shop_App
 */

require_once __DIR__ . '/wp-stubs.php';

use FisHotel\Misc\Sections\Shop_App\People;
use FisHotel\Misc\Sections\Shop_App\Pins;
use FisHotel\Misc\Sections\Shop_App\Session;
use FisHotel\Misc\Sections\Shop_App\Settings;

$secret = str_repeat( 'cd', 32 );

/**
 * A people row with a PIN.
 */
function fh_person( $id, $name, $pin, $secret, array $extra = array() ) {
	$salt = Pins::new_salt();
	return array_merge(
		array(
			'id'         => $id,
			'name'       => $name,
			'pin_salt'   => $salt,
			'pin_hash'   => Pins::hash( $pin, $salt, $secret ),
			'pin_length' => strlen( $pin ),
			'user_id'    => 0,
			'active'     => 1,
		),
		$extra
	);
}

echo "1. What a PIN is\n";
check( 'four digits are a PIN when the shop uses four', Pins::is_pin( '4821', 4 ) );
check( 'leading zeros count: 0042 is a PIN', Pins::is_pin( '0042', 4 ) );
check( 'three digits are not', ! Pins::is_pin( '482', 4 ) );
check( 'five digits are not when the shop uses four', ! Pins::is_pin( '48213', 4 ) );
check( 'six digits are, when the shop uses six', Pins::is_pin( '482135', 6 ) && ! Pins::is_pin( '4821', 6 ) );
check( 'letters, spaces, signs and other digit scripts are not', ! Pins::is_pin( '48a1', 4 ) && ! Pins::is_pin( ' 482', 4 ) && ! Pins::is_pin( '-482', 4 ) && ! Pins::is_pin( '４８２１', 4 ) && ! Pins::is_pin( "482\n", 4 ) );
check( 'a number is not a PIN (4821 as an integer would lose 0042)', ! Pins::is_pin( 4821, 4 ) && ! Pins::is_pin( null, 4 ) && ! Pins::is_pin( array( '4821' ), 4 ) );
check( 'the length setting is held to 4–6', 4 === Pins::length( 3 ) && 4 === Pins::length( 7 ) && 5 === Pins::length( '5' ) && 6 === Pins::length( 6 ) && 4 === Pins::length( 'x' ) );

echo "\n2. The hash\n";
$salt_a = Pins::new_salt();
$salt_b = Pins::new_salt();
$hash   = Pins::hash( '4821', $salt_a, $secret );
check( 'a salt is 32 hex characters and never twice the same', 1 === preg_match( '/^[a-f0-9]{32}$/', $salt_a ) && $salt_a !== $salt_b );
check( 'the hash is 64 hex characters', 1 === preg_match( '/^[a-f0-9]{64}$/', $hash ) );
check( 'a four-digit and a six-digit PIN give hashes of the same length: the hash does not show how long the PIN is', strlen( $hash ) === strlen( Pins::hash( '482100', $salt_a, $secret ) ) );
check( 'the same PIN, salt and secret give the same hash', $hash === Pins::hash( '4821', $salt_a, $secret ) );
check( 'another salt gives another hash: two people with one PIN do not look alike', $hash !== Pins::hash( '4821', $salt_b, $secret ) );
check( 'another site secret gives another hash: the table alone opens nothing', $hash !== Pins::hash( '4821', $salt_a, str_repeat( 'ef', 32 ) ) );
check( 'another PIN gives another hash', $hash !== Pins::hash( '4822', $salt_a, $secret ) );
check( 'it is not a bare SHA-256 or HMAC of the PIN', $hash !== hash( 'sha256', '4821' ) && $hash !== hash_hmac( 'sha256', '4821', $secret ) && $hash !== hash( 'sha256', $salt_a . '4821' ) );

$sam = fh_person( 1, 'Sam', '4821', $secret );
check( 'matches(): the right PIN', Pins::matches( '4821', $sam, $secret ) );
check( 'matches(): a wrong PIN', ! Pins::matches( '4820', $sam, $secret ) );
check( 'matches(): the right PIN under another secret', ! Pins::matches( '4821', $sam, 'other-secret-other-secret-other-secret' ) );
check( 'matches(): a person without a PIN matches nothing — not even an empty one', ! Pins::matches( '', array( 'pin_hash' => '', 'pin_salt' => '' ), $secret ) && ! Pins::matches( '4821', array( 'pin_hash' => '', 'pin_salt' => $salt_a ), $secret ) );

echo "\n3. Whose PIN is it — everybody is compared, every time\n";
$people = array(
	fh_person( 1, 'Sam', '4821', $secret ),
	fh_person( 2, 'Robin', '1379', $secret ),
	fh_person( 3, 'Alex', '9050', $secret ),
	fh_person( 4, 'Jo (off)', '6666', $secret, array( 'active' => 0 ) ),
	fh_person( 5, 'Kim (six digits)', '246810', $secret ),
);
$found = Pins::find( '4821', $people, $secret, 4 );
check( 'the first person\'s PIN finds them', $found && 1 === $found['id'] );
check( '… after comparing with all five', 5 === Pins::$last_work, (string) Pins::$last_work );
$found = Pins::find( '9050', $people, $secret, 4 );
check( 'the third person\'s PIN finds them, after all five', $found && 3 === $found['id'] && 5 === Pins::$last_work );
check( 'a PIN that is nobody\'s finds nobody, after all five', null === Pins::find( '0000', $people, $secret, 4 ) && 5 === Pins::$last_work );
check( 'the PIN of someone switched off finds nobody — the same answer as any wrong PIN', null === Pins::find( '6666', $people, $secret, 4 ) && 5 === Pins::$last_work );
check( 'a PIN of another length than the shop\'s finds nobody', null === Pins::find( '246810', $people, $secret, 4 ) && 5 === Pins::$last_work );
check( '… and finds its person once the shop uses six digits', 5 === ( Pins::find( '246810', $people, $secret, 6 )['id'] ?? 0 ) );
check( 'something that is not a PIN finds nobody, and still costs the same work', null === Pins::find( 'abcd', $people, $secret, 4 ) && 5 === Pins::$last_work && null === Pins::find( '', $people, $secret, 4 ) && 5 === Pins::$last_work && null === Pins::find( null, $people, $secret, 4 ) && 5 === Pins::$last_work );
check( 'nobody at all: nobody', null === Pins::find( '4821', array(), $secret, 4 ) && 0 === Pins::$last_work );
check( 'usable(): on, with a PIN of the shop\'s length', Pins::usable( $people[0], 4 ) && ! Pins::usable( $people[3], 4 ) && ! Pins::usable( $people[4], 4 ) && Pins::usable( $people[4], 6 ) && ! Pins::usable( array( 'active' => 1, 'pin_hash' => '', 'pin_length' => 4 ), 4 ) );

echo "\n4. Unique among active people, without saying whose\n";
check( 'a PIN somebody active has clashes', Pins::clashes( '1379', $people, $secret ) );
check( '… after comparing with every active person (four of the five)', 4 === Pins::$last_work, (string) Pins::$last_work );
check( 'a free PIN does not clash, after the same work', ! Pins::clashes( '5555', $people, $secret ) && 4 === Pins::$last_work );
check( 'the PIN of someone switched off is free', ! Pins::clashes( '6666', $people, $secret ) );
check( 'a person keeps their own PIN: no clash with themselves', ! Pins::clashes( '1379', $people, $secret, 2 ) && Pins::clashes( '1379', $people, $secret, 3 ) );
check( 'the message is "Choose a different PIN." and nothing else', 'Choose a different PIN.' === Pins::clash_message() );

echo "\n5. The person form\n";
$settings = Settings::defaults();
$ok       = People::validate( array( 'name' => '  Sam   the  second ', 'pin' => '7001', 'user_id' => 0, 'active' => 1 ), null, $people, $settings, $secret, array() );
check( 'a new person with a free PIN is accepted', $ok['ok'] && array() === $ok['errors'] );
check( 'the name is trimmed and its spaces collapsed', 'Sam the second' === $ok['fields']['name'] );
check( 'the PIN is stored as a hash, a fresh salt and its length — never itself', $ok['pin_changed'] && 64 === strlen( $ok['fields']['pin_hash'] ) && 32 === strlen( $ok['fields']['pin_salt'] ) && 4 === $ok['fields']['pin_length'] && false === strpos( json_encode( $ok['fields'] ), '7001' ) );
check( '… and that hash is the PIN\'s', Pins::matches( '7001', $ok['fields'], $secret ) );

$clash = People::validate( array( 'name' => 'Pat', 'pin' => '1379', 'user_id' => 0, 'active' => 1 ), null, $people, $settings, $secret, array() );
check( 'a PIN that is somebody else\'s is refused', ! $clash['ok'] );
check( '… with exactly "Choose a different PIN." — no name, no hint', array( 'Choose a different PIN.' ) === $clash['errors'] );
$text = json_encode( $clash );
check( '… and nothing in the answer names the other person or their row', false === strpos( $text, 'Robin' ) && ! isset( $clash['fields']['pin_hash'] ) );
$clash_off = People::validate( array( 'name' => 'Pat', 'pin' => '6666', 'user_id' => 0, 'active' => 1 ), null, $people, $settings, $secret, array() );
check( 'the PIN of someone switched off may be given again', $clash_off['ok'] );

check( 'no name: refused', ! People::validate( array( 'name' => '  ', 'pin' => '7001', 'active' => 1 ), null, $people, $settings, $secret, array() )['ok'] );
check( 'a tag in a name is dropped, not stored', 'Sam' === People::validate( array( 'name' => '<b>Sam</b><script>x()</script>', 'pin' => '7001', 'active' => 1 ), null, $people, $settings, $secret, array() )['fields']['name'] );
check( 'no PIN for a new person: refused, and the message says how many digits', false !== strpos( implode( ' ', People::validate( array( 'name' => 'Pat', 'pin' => '', 'active' => 1 ), null, $people, $settings, $secret, array() )['errors'] ), '4 digits' ) );
check( 'a PIN of the wrong length or with a letter: refused', ! People::validate( array( 'name' => 'Pat', 'pin' => '70011', 'active' => 1 ), null, $people, $settings, $secret, array() )['ok'] && ! People::validate( array( 'name' => 'Pat', 'pin' => '70a1', 'active' => 1 ), null, $people, $settings, $secret, array() )['ok'] );
check( 'without a site secret no PIN is stored', ! People::validate( array( 'name' => 'Pat', 'pin' => '7001', 'active' => 1 ), null, $people, $settings, '', array() )['ok'] );

$edit = People::validate( array( 'name' => 'Robin R.', 'pin' => '', 'user_id' => 0, 'active' => 1 ), $people[1], $people, $settings, $secret, array() );
check( 'editing with the PIN left empty keeps the PIN: no PIN column is written', $edit['ok'] && ! $edit['pin_changed'] && ! isset( $edit['fields']['pin_hash'] ) && 'Robin R.' === $edit['fields']['name'] );
$edit = People::validate( array( 'name' => 'Robin', 'pin' => '1379', 'user_id' => 0, 'active' => 1 ), $people[1], $people, $settings, $secret, array() );
check( 'typing their own PIN again is no clash', $edit['ok'] && $edit['pin_changed'] );
$edit = People::validate( array( 'name' => 'Robin', 'pin' => '4821', 'user_id' => 0, 'active' => 1 ), $people[1], $people, $settings, $secret, array() );
check( 'changing to somebody else\'s PIN: "Choose a different PIN."', ! $edit['ok'] && array( 'Choose a different PIN.' ) === $edit['errors'] );

$off = People::validate( array( 'name' => 'Robin', 'pin' => '1379', 'user_id' => 0, 'active' => 0 ), $people[1], $people, $settings, $secret, array() );
check( 'switching a person off destroys the PIN, whatever was typed', $off['ok'] && '' === $off['fields']['pin_hash'] && '' === $off['fields']['pin_salt'] && 0 === $off['fields']['pin_length'] && 0 === $off['fields']['active'] );
$back = People::validate( array( 'name' => 'Jo', 'pin' => '', 'user_id' => 0, 'active' => 1 ), $people[3], $people, $settings, $secret, array() );
check( 'switching a person back on needs a new PIN (their old one still stored or not)', ! $back['ok'] );
$back = People::validate( array( 'name' => 'Jo', 'pin' => '4821', 'user_id' => 0, 'active' => 1 ), $people[3], $people, $settings, $secret, array() );
check( '… and that PIN is checked against everybody active', ! $back['ok'] && array( 'Choose a different PIN.' ) === $back['errors'] );

$acct = People::validate( array( 'name' => 'Pat', 'pin' => '7001', 'user_id' => 9, 'active' => 1 ), null, $people, $settings, $secret, array( 7, 8 ) );
check( 'an account the form did not offer is refused', ! $acct['ok'] );
$acct = People::validate( array( 'name' => 'Pat', 'pin' => '7001', 'user_id' => 8, 'active' => 1 ), null, $people, $settings, $secret, array( 7, 8 ) );
check( 'an offered account is accepted', $acct['ok'] && 8 === $acct['fields']['user_id'] );
$with_own = $people;
$with_own[0]['user_id'] = 8;
$acct = People::validate( array( 'name' => 'Pat', 'pin' => '7001', 'user_id' => 8, 'active' => 1 ), null, $with_own, $settings, $secret, array( 7, 8 ) );
check( 'an account that already belongs to another active person is refused', ! $acct['ok'] );
$acct = People::validate( array( 'name' => 'Sam', 'pin' => '', 'user_id' => 8, 'active' => 1 ), $with_own[0], $with_own, $settings, $secret, array() );
check( 'a person keeps the account they have, offered or not', $acct['ok'] && 8 === $acct['fields']['user_id'] );

echo "\n6. The PIN length setting\n";
$six = array_merge( $settings, array( 'pin_length' => 6 ) );
check( 'after the shop moves to six digits a four-digit PIN is "needs a new PIN"', 'stale' === People::pin_state( $people[0], $six )['state'] && 'ok' === People::pin_state( $people[0], $settings )['state'] && 'none' === People::pin_state( $people[3], $settings )['state'] );
check( '… a new PIN must then have six digits', ! People::validate( array( 'name' => 'Pat', 'pin' => '7001', 'active' => 1 ), null, $people, $six, $secret, array() )['ok'] && People::validate( array( 'name' => 'Pat', 'pin' => '700100', 'active' => 1 ), null, $people, $six, $secret, array() )['ok'] );
check( '… and still may not be the six-digit PIN somebody already has', array( 'Choose a different PIN.' ) === People::validate( array( 'name' => 'Pat', 'pin' => '246810', 'active' => 1 ), null, $people, $six, $secret, array() )['errors'] );

echo "\n7. The settings\n";
$d = Settings::defaults();
check( 'by default a PIN unlocks for two hours, counted from the last use, with four digits', 120 === $d['session_minutes'] && Session::MODE_LAST_USE === $d['session_mode'] && 4 === $d['pin_length'] );
check( 'the length is held between 30 minutes and 12 hours', 30 === Settings::sanitize( array( 'session_minutes' => 5 ) )['session_minutes'] && 720 === Settings::sanitize( array( 'session_minutes' => 100000 ) )['session_minutes'] && 45 === Settings::sanitize( array( 'session_minutes' => '45' ) )['session_minutes'] );
check( 'nonsense for the length is the default', 120 === Settings::sanitize( array( 'session_minutes' => 'soon' ) )['session_minutes'] && 120 === Settings::sanitize( array( 'session_minutes' => array( 1 ) ) )['session_minutes'] && 120 === Settings::sanitize( 'x' )['session_minutes'] );
check( 'the count starts at the last use unless the unlock is chosen', Session::MODE_UNLOCK === Settings::sanitize( array( 'session_mode' => 'unlock' ) )['session_mode'] && Session::MODE_LAST_USE === Settings::sanitize( array( 'session_mode' => 'forever' ) )['session_mode'] );
check( 'the PIN length is 4, 5 or 6', 5 === Settings::sanitize( array( 'pin_length' => '5' ) )['pin_length'] && 4 === Settings::sanitize( array( 'pin_length' => 12 ) )['pin_length'] );
Settings::forget();
Settings::save( array( 'session_minutes' => '240', 'session_mode' => 'unlock', 'pin_length' => '6', 'anything_else' => 'x' ) );
check( 'saved settings are stored clean and NOT autoloaded', array( 'session_minutes' => 240, 'session_mode' => 'unlock', 'pin_length' => 6 ) === $GLOBALS['fh_options'][ Settings::OPTION ] && false === $GLOBALS['fh_autoload'][ Settings::OPTION ] );
check( '… and read back', 240 === Settings::get()['session_minutes'] );

echo "\n8. The site secret\n";
unset( $GLOBALS['fh_options'][ Settings::SECRET_OPTION ] );
$first = Settings::secret();
check( 'a secret is made on first need: 64 hex characters, stored, not autoloaded', 1 === preg_match( '/^[a-f0-9]{64}$/', $first ) && $first === $GLOBALS['fh_options'][ Settings::SECRET_OPTION ] && false === $GLOBALS['fh_autoload'][ Settings::SECRET_OPTION ] );
check( 'it stays the same', $first === Settings::secret() && Settings::ensure_secret() && $first === Settings::secret() );
$key = Settings::session_key();
check( 'the session key is derived from it — not the secret itself', 64 === strlen( $key ) && $key !== $first );
$GLOBALS['fh_salt'] = 'rotated-';
check( 'new WordPress keys give a new session key (every shop session ends) …', $key !== Settings::session_key() );
check( '… but the same PIN secret: the PINs and the devices survive', $first === Settings::secret() );
unset( $GLOBALS['fh_salt'] );

fh_finish();
