<?php
/**
 * Requests URLs — every address the section hands out:
 *
 *   /?fh_request=<token>                 the private request form of a link
 *   /?fh_request_pay=<signed token>      the pay link of an invoice
 *   /?fh_requests=<signed token>         someone's own requests (no account)
 *   /?fh_request_answer=<token>&do=…     "keep it" / "cancel it" of a
 *                                        "still want it?" e-mail
 *   My Account → Requests                the same list for a site customer
 *
 * None of them is linked from a menu, listed in a sitemap or indexable:
 * they exist only for whoever was given one.
 *
 * @package FisHotel\Misc\Sections\Requests
 */

namespace FisHotel\Misc\Sections\Requests;

defined( 'ABSPATH' ) || exit;

/**
 * Class Urls
 */
class Urls {

	const FORM_VAR   = 'fh_request';
	const PAY_VAR    = 'fh_request_pay';
	const PERSON_VAR = 'fh_requests';
	const ANSWER_VAR = 'fh_request_answer';

	/**
	 * The My Account endpoint ("my-requests" is Batch Manager's Custom
	 * Orders tab, so this one has its own name).
	 *
	 * @var string
	 */
	const ACCOUNT_ENDPOINT = 'fish-requests';

	/**
	 * The private form of a link.
	 *
	 * @param string $token The link's token.
	 * @return string
	 */
	public static function form( $token ) {
		return home_url( '/?' . self::FORM_VAR . '=' . rawurlencode( (string) $token ) );
	}

	/**
	 * The pay link of an invoice.
	 *
	 * @param int   $carrier_id The request that carries the invoice.
	 * @param float $amount     The fee.
	 * @return string
	 */
	public static function pay( $carrier_id, $amount ) {
		return home_url( '/?' . self::PAY_VAR . '=' . rawurlencode( Tokens::pay( $carrier_id, $amount ) ) );
	}

	/**
	 * The personal page of an e-mail address ('' without one).
	 *
	 * @param string $email E-mail address.
	 * @return string
	 */
	public static function person( $email ) {
		$token = Tokens::person( $email );
		return '' === $token ? '' : home_url( '/?' . self::PERSON_VAR . '=' . rawurlencode( $token ) );
	}

	/**
	 * A "keep it" / "cancel it" link of a request ('' without an e-mail
	 * address to sign it with).
	 *
	 * @param array  $request The request.
	 * @param string $do      keep|cancel.
	 * @return string
	 */
	public static function answer( array $request, $do ) {
		if ( (int) ( $request['id'] ?? 0 ) <= 0 || '' === Rules::email( $request['email'] ?? '' ) ) {
			return '';
		}
		return home_url( '/?' . self::ANSWER_VAR . '=' . rawurlencode( Tokens::answer( $request ) ) . '&do=' . ( 'cancel' === $do ? 'cancel' : 'keep' ) );
	}

	/**
	 * My Account → Requests ('' when WooCommerce has no account page).
	 *
	 * @return string
	 */
	public static function account() {
		if ( ! function_exists( 'wc_get_account_endpoint_url' ) || ! function_exists( 'wc_get_page_id' ) ) {
			return '';
		}
		if ( (int) wc_get_page_id( 'myaccount' ) <= 0 ) {
			return '';
		}
		return (string) wc_get_account_endpoint_url( self::ACCOUNT_ENDPOINT );
	}

	/**
	 * Where the person of a request can see and cancel it: My Account for
	 * a site customer, the personal link for everybody else.
	 *
	 * @param array $request The request.
	 * @return string
	 */
	public static function view( array $request ) {
		if ( (int) ( $request['user_id'] ?? 0 ) > 0 ) {
			$account = self::account();
			if ( '' !== $account ) {
				return $account;
			}
		}
		return self::person( $request['email'] ?? '' );
	}

	/**
	 * The site's name, as plain text.
	 *
	 * @return string
	 */
	public static function site_name() {
		$name = function_exists( 'get_bloginfo' ) ? (string) get_bloginfo( 'name' ) : '';
		$name = function_exists( 'wp_specialchars_decode' ) ? wp_specialchars_decode( $name, ENT_QUOTES ) : $name;
		return '' !== trim( $name ) ? trim( $name ) : 'FisHotel';
	}

	/**
	 * The store's currency symbol, as plain text ("$" without WooCommerce).
	 *
	 * @return string
	 */
	public static function currency_symbol() {
		if ( function_exists( 'get_woocommerce_currency_symbol' ) ) {
			$symbol = html_entity_decode( (string) get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' );
			if ( '' !== $symbol ) {
				return $symbol;
			}
		}
		return '$';
	}
}
