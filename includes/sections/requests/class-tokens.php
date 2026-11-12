<?php
/**
 * Requests tokens — the signed strings the section puts into links:
 *
 *   - the PAY link of an invoice:     "<carrier id>-<amount in cents>-<signature>".
 *     The signature covers the id AND the amount, so a link whose amount
 *     was changed by hand is refused — the customer cannot pick the price.
 *   - the PERSONAL link of someone without an account ("see and cancel
 *     my requests"): "<their e-mail, base64url>.<signature>". It only ever
 *     travels in an e-mail sent to that address.
 *   - the ANSWER link of a "still want it?" e-mail: "<request id>-<signature>",
 *     signed with the request's e-mail address.
 *   - the random token of a private request link.
 *
 * Signatures are HMAC-SHA256 with a key derived from the site's own salt
 * (wp_salt()), cut to 32 hex characters and compared in constant time.
 *
 * @package FisHotel\Misc\Sections\Requests
 */

namespace FisHotel\Misc\Sections\Requests;

defined( 'ABSPATH' ) || exit;

/**
 * Class Tokens
 */
class Tokens {

	const SIG_LENGTH = 32;

	/**
	 * The key of a purpose.
	 *
	 * @param string $purpose pay|person|answer.
	 * @return string
	 */
	private static function key( $purpose ) {
		$salt = function_exists( 'wp_salt' ) ? (string) wp_salt( 'auth' ) : '';
		return hash( 'sha256', 'fishotel_requests|' . $purpose . '|' . $salt );
	}

	/**
	 * Sign a payload for a purpose.
	 *
	 * @param string $purpose Purpose.
	 * @param string $payload Payload.
	 * @return string 32 hex characters.
	 */
	public static function sign( $purpose, $payload ) {
		return substr( hash_hmac( 'sha256', (string) $payload, self::key( $purpose ) ), 0, self::SIG_LENGTH );
	}

	/**
	 * An amount in cents.
	 *
	 * @param float $amount Amount.
	 * @return int
	 */
	public static function cents( $amount ) {
		return (int) round( (float) $amount * 100 );
	}

	/* ============================================================== */
	/*  Pay                                                            */
	/* ============================================================== */

	/**
	 * The signature of an invoice: the carrier request and its amount.
	 *
	 * @param int   $carrier_id The request that carries the invoice.
	 * @param float $amount     The fee.
	 * @return string
	 */
	public static function pay_signature( $carrier_id, $amount ) {
		return self::sign( 'pay', (int) $carrier_id . '|' . self::cents( $amount ) );
	}

	/**
	 * The pay token of an invoice.
	 *
	 * @param int   $carrier_id The request that carries the invoice.
	 * @param float $amount     The fee.
	 * @return string
	 */
	public static function pay( $carrier_id, $amount ) {
		return (int) $carrier_id . '-' . self::cents( $amount ) . '-' . self::pay_signature( $carrier_id, $amount );
	}

	/**
	 * Read a pay token: the carrier id and the amount, or null when the
	 * token is malformed or its signature does not match (a changed amount
	 * or id).
	 *
	 * @param mixed $token The token from the link.
	 * @return array{id: int, amount: float}|null
	 */
	public static function read_pay( $token ) {
		if ( ! is_string( $token ) || 1 !== preg_match( '/^(\d{1,12})-(\d{1,9})-([a-f0-9]{' . self::SIG_LENGTH . '})$/', $token, $m ) ) {
			return null;
		}
		$id     = (int) $m[1];
		$amount = (int) $m[2] / 100;
		if ( $id <= 0 || $amount <= 0 || ! hash_equals( self::pay_signature( $id, $amount ), $m[3] ) ) {
			return null;
		}
		return array(
			'id'     => $id,
			'amount' => round( $amount, 2 ),
		);
	}

	/**
	 * Whether the parts a cart line carries are a genuine invoice.
	 *
	 * @param mixed $carrier_id Carrier id.
	 * @param mixed $amount     Amount.
	 * @param mixed $signature  Signature.
	 * @return bool
	 */
	public static function check_pay( $carrier_id, $amount, $signature ) {
		if ( ! is_scalar( $carrier_id ) || ! is_scalar( $amount ) || ! is_string( $signature ) || (int) $carrier_id <= 0 || (float) $amount <= 0 ) {
			return false;
		}
		return hash_equals( self::pay_signature( (int) $carrier_id, (float) $amount ), $signature );
	}

	/* ============================================================== */
	/*  Personal link                                                  */
	/* ============================================================== */

	/**
	 * The personal token of an e-mail address ('' without one).
	 *
	 * @param string $email E-mail address.
	 * @return string
	 */
	public static function person( $email ) {
		$email = Rules::email( $email );
		if ( '' === $email ) {
			return '';
		}
		return self::b64( $email ) . '.' . self::sign( 'person', $email );
	}

	/**
	 * The e-mail address a personal token is for, or '' when the token is
	 * not genuine.
	 *
	 * @param mixed $token The token from the link.
	 * @return string
	 */
	public static function read_person( $token ) {
		if ( ! is_string( $token ) || 1 !== preg_match( '/^([A-Za-z0-9_\-]{4,400})\.([a-f0-9]{' . self::SIG_LENGTH . '})$/', $token, $m ) ) {
			return '';
		}
		$email = Rules::email( self::unb64( $m[1] ) );
		if ( '' === $email || ! hash_equals( self::sign( 'person', $email ), $m[2] ) ) {
			return '';
		}
		return $email;
	}

	/* ============================================================== */
	/*  "Still want it?" answer                                        */
	/* ============================================================== */

	/**
	 * The answer token of a request.
	 *
	 * @param array $request id, email.
	 * @return string
	 */
	public static function answer( array $request ) {
		$id = (int) ( $request['id'] ?? 0 );
		return $id . '-' . self::sign( 'answer', $id . '|' . Rules::email( $request['email'] ?? '' ) );
	}

	/**
	 * The request id an answer token names (0 when malformed). The
	 * signature is checked by check_answer() once the request is loaded.
	 *
	 * @param mixed $token The token from the link.
	 * @return int
	 */
	public static function answer_id( $token ) {
		if ( ! is_string( $token ) || 1 !== preg_match( '/^(\d{1,12})-[a-f0-9]{' . self::SIG_LENGTH . '}$/', $token, $m ) ) {
			return 0;
		}
		return (int) $m[1];
	}

	/**
	 * Whether an answer token is the one of this request.
	 *
	 * @param mixed $token   The token from the link.
	 * @param array $request The request it names.
	 * @return bool
	 */
	public static function check_answer( $token, array $request ) {
		return is_string( $token ) && '' !== Rules::email( $request['email'] ?? '' ) && hash_equals( self::answer( $request ), $token );
	}

	/* ============================================================== */
	/*  Private links                                                  */
	/* ============================================================== */

	/**
	 * A fresh random token for a private link (32 hex characters).
	 *
	 * @return string
	 */
	public static function link() {
		try {
			return bin2hex( random_bytes( 16 ) );
		} catch ( \Exception $e ) {
			return substr( hash( 'sha256', uniqid( 'fhrq', true ) . mt_rand() ), 0, 32 );
		}
	}

	/**
	 * Whether a string has the shape of a private link token.
	 *
	 * @param mixed $token Token.
	 * @return bool
	 */
	public static function is_link( $token ) {
		return is_string( $token ) && 1 === preg_match( '/^[a-f0-9]{32}$/', $token );
	}

	/**
	 * A fresh key naming the requests of one submitted sheet.
	 *
	 * @return string 16 hex characters.
	 */
	public static function sheet() {
		return substr( self::link(), 0, 16 );
	}

	/* ============================================================== */
	/*  base64url                                                      */
	/* ============================================================== */

	private static function b64( $text ) {
		return rtrim( strtr( base64_encode( (string) $text ), '+/', '-_' ), '=' );
	}

	private static function unb64( $text ) {
		$decoded = base64_decode( strtr( (string) $text, '-_', '+/' ), true );
		return false === $decoded ? '' : $decoded;
	}
}
