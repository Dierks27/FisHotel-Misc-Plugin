<?php
/**
 * Lost Fish link token — PURE: the signed, expiring token inside each of
 * the four links of the customer's e-mail.
 *
 * A token is "<case id>.<choice>.<expires>.<signature>": it names ONE case
 * and ONE choice, and is good until the expiry it carries. The signature
 * is an HMAC-SHA256 over those three parts and the case's own salt (a
 * random string stored with the case when the e-mail is sent, replaced
 * when the owner reopens the case — which is what kills the old links),
 * keyed with a secret derived from the site's own salt. Changing any part
 * of a token — another case, another choice, a later expiry — breaks the
 * signature.
 *
 * A token only OPENS the page. It never records anything: that takes the
 * Confirm button's POST (Customer_Page), because mail scanners open links.
 *
 * @package FisHotel\Misc\Sections\Lost_Fish
 */

namespace FisHotel\Misc\Sections\Lost_Fish;

defined( 'ABSPATH' ) || exit;

/**
 * Class Token
 */
class Token {

	const OK      = 'ok';
	const BAD     = 'bad';
	const EXPIRED = 'expired';

	/**
	 * Signature length in hex characters (160 bits).
	 *
	 * @var int
	 */
	const SIG_LENGTH = 40;

	/**
	 * The secret the signatures are keyed with: derived from the site's
	 * salt, so it never has to be stored.
	 *
	 * @return string
	 */
	public static function secret() {
		$salt = function_exists( 'wp_salt' ) ? (string) wp_salt( 'auth' ) : '';
		return hash_hmac( 'sha256', 'fishotel-lost-fish-links', $salt );
	}

	/**
	 * A fresh salt for a case.
	 *
	 * @return string 32 hex characters.
	 */
	public static function new_salt() {
		try {
			return bin2hex( random_bytes( 16 ) );
		} catch ( \Exception $e ) {
			return md5( uniqid( (string) mt_rand(), true ) );
		}
	}

	/**
	 * The signature of a case, a choice and an expiry.
	 *
	 * @param int    $case_id Case id.
	 * @param string $choice  refund | credit | retry | cancel.
	 * @param int    $expires UNIX timestamp.
	 * @param string $salt    The case's salt.
	 * @param string $secret  The site secret.
	 * @return string
	 */
	public static function sign( $case_id, $choice, $expires, $salt, $secret ) {
		$payload = (int) $case_id . '.' . (string) $choice . '.' . (int) $expires;
		return substr( hash_hmac( 'sha256', $payload . '|' . (string) $salt, (string) $secret ), 0, self::SIG_LENGTH );
	}

	/**
	 * A token.
	 *
	 * @param int    $case_id Case id.
	 * @param string $choice  refund | credit | retry | cancel.
	 * @param int    $expires UNIX timestamp.
	 * @param string $salt    The case's salt.
	 * @param string $secret  The site secret.
	 * @return string '' when the choice is not one of the four or the salt is empty.
	 */
	public static function make( $case_id, $choice, $expires, $salt, $secret ) {
		if ( (int) $case_id <= 0 || ! in_array( (string) $choice, Money::CHOICES, true ) || '' === (string) $salt ) {
			return '';
		}
		return (int) $case_id . '.' . (string) $choice . '.' . (int) $expires . '.' . self::sign( $case_id, $choice, $expires, $salt, $secret );
	}

	/**
	 * A token's parts — no judgement yet.
	 *
	 * @param mixed $token The token as it came in.
	 * @return array{case_id: int, choice: string, expires: int, sig: string}|null Null when it does not have the shape of a token.
	 */
	public static function parse( $token ) {
		if ( ! is_string( $token ) || strlen( $token ) > 120 ) {
			return null;
		}
		if ( 1 !== preg_match( '/^([1-9]\d{0,17})\.([a-z]{1,10})\.([1-9]\d{0,11})\.([a-f0-9]{' . self::SIG_LENGTH . '})$/', $token, $m ) ) {
			return null;
		}
		if ( ! in_array( $m[2], Money::CHOICES, true ) ) {
			return null;
		}

		return array(
			'case_id' => (int) $m[1],
			'choice'  => $m[2],
			'expires' => (int) $m[3],
			'sig'     => $m[4],
		);
	}

	/**
	 * Judge a parsed token against the case it names: OK, BAD (the
	 * signature does not match — a forged or altered token, a token of
	 * another case, or links the owner retired by reopening the case) or
	 * EXPIRED (a good signature, too late).
	 *
	 * @param array  $parsed parse().
	 * @param string $salt   The salt of the case the token names.
	 * @param string $secret The site secret.
	 * @param int    $now    UNIX timestamp.
	 * @return string OK | BAD | EXPIRED.
	 */
	public static function verify( array $parsed, $salt, $secret, $now ) {
		if ( '' === (string) $salt || empty( $parsed['sig'] ) ) {
			return self::BAD;
		}
		$expected = self::sign( $parsed['case_id'] ?? 0, $parsed['choice'] ?? '', $parsed['expires'] ?? 0, $salt, $secret );
		if ( ! hash_equals( $expected, (string) $parsed['sig'] ) ) {
			return self::BAD;
		}
		return (int) $parsed['expires'] >= (int) $now ? self::OK : self::EXPIRED;
	}
}
