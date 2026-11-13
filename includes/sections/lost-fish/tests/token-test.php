<?php
/**
 * Token tests — the signed, expiring link token: it verifies only for the
 * case, the choice and the expiry it was made for; anything altered, a
 * token of another case, links retired by a new salt, and things that are
 * not tokens at all are refused; a good signature past its expiry is
 * "expired", not "bad".
 *
 * Run from the plugin root:
 *   php includes/sections/lost-fish/tests/token-test.php
 *
 * @package FisHotel\Misc\Sections\Lost_Fish
 */

require_once __DIR__ . '/wp-stubs.php';

use FisHotel\Misc\Sections\Lost_Fish\Token;

$secret  = Token::secret();
$salt    = Token::new_salt();
$now     = 1800000000;
$expires = $now + 14 * 86400;

echo "Making\n";
$token = Token::make( 12, 'refund', $expires, $salt, $secret );
check( 'a token is the case, the choice, the expiry and a signature', 1 === preg_match( '/^12\.refund\.' . $expires . '\.[a-f0-9]{40}$/', $token ), $token );
check_same( 'the secret comes from the site\'s salt and is never the salt itself', array( 64, false ), array( strlen( $secret ), false !== strpos( $secret, 'test-salt' ) ) );
check( 'a salt is 32 hex characters, new each time', 1 === preg_match( '/^[a-f0-9]{32}$/', $salt ) && $salt !== Token::new_salt() );
check_same( 'no token for something that is not a choice', '', Token::make( 12, 'everything', $expires, $salt, $secret ) );
check_same( 'no token without a salt (a case that was never sent)', '', Token::make( 12, 'refund', $expires, '', $secret ) );
check_same( 'no token without a case', '', Token::make( 0, 'refund', $expires, $salt, $secret ) );
check( 'each choice gets its own token', 4 === count( array_unique( array( $token, Token::make( 12, 'credit', $expires, $salt, $secret ), Token::make( 12, 'retry', $expires, $salt, $secret ), Token::make( 12, 'cancel', $expires, $salt, $secret ) ) ) ) );

echo "\nParsing\n";
$parsed = Token::parse( $token );
check_same( 'the parts come back', array( 12, 'refund', $expires ), array( $parsed['case_id'], $parsed['choice'], $parsed['expires'] ) );
foreach ( array(
	'nothing'                  => '',
	'not a string'             => array( $token ),
	'words'                    => 'refund my fish please',
	'a choice that is not one' => '12.steal.' . $expires . '.' . str_repeat( 'a', 40 ),
	'a signature too short'    => '12.refund.' . $expires . '.abc',
	'a signature not hex'      => '12.refund.' . $expires . '.' . str_repeat( 'z', 40 ),
	'a negative case'          => '-12.refund.' . $expires . '.' . str_repeat( 'a', 40 ),
	'something after it'       => $token . '.more',
	'a script'                 => '<script>alert(1)</script>',
	'far too long'             => str_repeat( '1', 200 ),
) as $label => $bad ) {
	check_same( 'not a token: ' . $label, null, Token::parse( $bad ) );
}

echo "\nVerifying\n";
check_same( 'the token as made verifies', Token::OK, Token::verify( $parsed, $salt, $secret, $now ) );
check_same( 'still good at the very second it expires', Token::OK, Token::verify( $parsed, $salt, $secret, $expires ) );
check_same( 'one second later it is expired — a good signature, too late', Token::EXPIRED, Token::verify( $parsed, $salt, $secret, $expires + 1 ) );

$other_choice = array_merge( $parsed, array( 'choice' => 'cancel' ) );
check_same( 'the same signature under another choice is bad', Token::BAD, Token::verify( $other_choice, $salt, $secret, $now ) );

$other_case = array_merge( $parsed, array( 'case_id' => 13 ) );
check_same( 'the same signature under another case is bad', Token::BAD, Token::verify( $other_case, $salt, $secret, $now ) );

$later = array_merge( $parsed, array( 'expires' => $expires + 86400 * 365 ) );
check_same( 'a later expiry written into the token is bad', Token::BAD, Token::verify( $later, $salt, $secret, $now ) );

check_same( 'a token of case 12 does not verify against the salt of another case', Token::BAD, Token::verify( $parsed, Token::new_salt(), $secret, $now ) );
check_same( 'a case without a salt (reopened: its links are retired) verifies nothing', Token::BAD, Token::verify( $parsed, '', $secret, $now ) );
check_same( 'another site\'s secret verifies nothing', Token::BAD, Token::verify( $parsed, $salt, hash_hmac( 'sha256', 'x', 'y' ), $now ) );
check_same( 'an empty signature verifies nothing', Token::BAD, Token::verify( array_merge( $parsed, array( 'sig' => '' ) ), $salt, $secret, $now ) );

$flipped        = $parsed;
$flipped['sig'] = ( 'a' === $flipped['sig'][0] ? 'b' : 'a' ) . substr( $flipped['sig'], 1 );
check_same( 'one changed character in the signature is bad', Token::BAD, Token::verify( $flipped, $salt, $secret, $now ) );

check_same( 'an expired token with a bad signature is bad, not expired', Token::BAD, Token::verify( $other_case, $salt, $secret, $expires + 1 ) );

fh_finish();
