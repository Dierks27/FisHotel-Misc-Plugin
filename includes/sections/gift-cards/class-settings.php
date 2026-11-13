<?php
/**
 * Gift Cards settings — one option (not autoloaded: it is read on the
 * checkout, the account page, a gift card product and in wp-admin only).
 *
 * Every choice the owner has not made yet is a setting here, set to the
 * safer answer (see DECISIONS in the section README):
 *
 *   guest_codes            may someone who is not logged in type a code at
 *                          checkout? ('no': they log in and add it first)
 *   cancel_paid_restores   when an order that was already paid is cancelled,
 *                          does the gift card part go back on the card?
 *                          ('yes': the requirements say cancelled = put back)
 *   wallet_pays_gift_cards may the balance pay for a gift card in the cart?
 *                          ('yes': as today — the theme forces it)
 *   books_include_disabled does "the number for the books" count the money
 *                          on disabled cards? ('yes': it is still owed)
 *   editor_capability      who may issue cards and change balances
 *                          ('manage_options': administrators only)
 *   send_imported_pending  send imported cards whose delivery date is still
 *                          ahead? ('no': listed by the dry run instead)
 *   recalc_guard           keep an old order's gift card deduction when
 *                          Recalculate is pressed ('yes')
 *   custom_min / custom_max  the default limits of a custom amount (cents)
 *   redeem_tries           wrong codes allowed per person per hour
 *   email_subject / email_heading / email_body  the gift card e-mail
 *
 * sanitize() is pure, so the rules are tested without WordPress.
 *
 * @package FisHotel\Misc\Sections\Gift_Cards
 */

namespace FisHotel\Misc\Sections\Gift_Cards;

defined( 'ABSPATH' ) || exit;

/**
 * Class Settings
 */
class Settings {

	const OPTION_KEY = 'fishotel_gift_cards_settings';

	const YES_NO = array( 'guest_codes', 'cancel_paid_restores', 'wallet_pays_gift_cards', 'books_include_disabled', 'send_imported_pending', 'recalc_guard' );

	const EDITOR_CAPABILITIES = array( 'manage_options', 'manage_woocommerce' );

	/**
	 * The e-mail placeholders.
	 *
	 * @var string[]
	 */
	const PLACEHOLDERS = array( '{amount}', '{code}', '{recipient}', '{sender}', '{message}', '{shop}', '{account_url}', '{shop_url}' );

	/**
	 * The defaults.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'guest_codes'            => 'no',
			'cancel_paid_restores'   => 'yes',
			'wallet_pays_gift_cards' => 'yes',
			'books_include_disabled' => 'yes',
			'send_imported_pending'  => 'no',
			'recalc_guard'           => 'yes',
			'editor_capability'      => 'manage_options',
			'custom_min'             => 500,
			'custom_max'             => 50000,
			'redeem_tries'           => 10,
			'email_subject'          => __( 'A {amount} gift card from {shop}', 'fishotel-misc-plugin' ),
			'email_heading'          => __( 'You have a gift card', 'fishotel-misc-plugin' ),
			'email_body'             => __( "Hello {recipient},\n\n{sender} sent you a {amount} gift card for {shop}.\n\n{message}\n\nYour code: {code}\n\nTo use it, log in at {account_url}, open Gift Cards and add the code to your account — or type it at checkout. It never expires.\n\n{shop_url}", 'fishotel-misc-plugin' ),
		);
	}

	/**
	 * The saved settings over the defaults.
	 *
	 * @return array
	 */
	public static function get() {
		$saved = function_exists( 'get_option' ) ? get_option( self::OPTION_KEY, array() ) : array();
		return self::merge( is_array( $saved ) ? $saved : array() );
	}

	/**
	 * Settings over the defaults, each cleaned.
	 *
	 * @param array $saved Saved values.
	 * @return array
	 */
	public static function merge( array $saved ) {
		return self::sanitize( array_merge( self::defaults(), $saved ) );
	}

	/**
	 * Clean a posted or stored settings array (pure).
	 *
	 * @param array $in Values.
	 * @return array
	 */
	public static function sanitize( array $in ) {
		$d   = self::defaults();
		$out = array();
		foreach ( self::YES_NO as $key ) {
			$value       = isset( $in[ $key ] ) ? $in[ $key ] : $d[ $key ];
			$out[ $key ] = ( 'yes' === $value || '1' === (string) $value || true === $value ) ? 'yes' : 'no';
		}
		$cap                      = isset( $in['editor_capability'] ) ? (string) $in['editor_capability'] : $d['editor_capability'];
		$out['editor_capability'] = in_array( $cap, self::EDITOR_CAPABILITIES, true ) ? $cap : $d['editor_capability'];

		foreach ( array( 'custom_min', 'custom_max' ) as $key ) {
			$value = isset( $in[ $key ] ) ? $in[ $key ] : $d[ $key ];
			if ( is_string( $value ) && false !== strpos( $value, '.' ) ) {
				$value = Money::to_cents( $value );
			}
			$out[ $key ] = null === $value ? $d[ $key ] : max( 100, min( Money::MAX_CENTS, (int) $value ) );
		}
		if ( $out['custom_max'] < $out['custom_min'] ) {
			$out['custom_max'] = $out['custom_min'];
		}
		$out['redeem_tries'] = max( 3, min( 50, (int) ( $in['redeem_tries'] ?? $d['redeem_tries'] ) ) );

		foreach ( array( 'email_subject', 'email_heading' ) as $key ) {
			$value       = isset( $in[ $key ] ) && is_scalar( $in[ $key ] ) ? trim( preg_replace( '/[\r\n]+/', ' ', strip_tags( (string) $in[ $key ] ) ) ) : '';
			$out[ $key ] = '' !== $value ? substr( $value, 0, 200 ) : $d[ $key ];
		}
		$body               = isset( $in['email_body'] ) && is_scalar( $in['email_body'] ) ? trim( strip_tags( str_replace( "\r", '', (string) $in['email_body'] ) ) ) : '';
		$out['email_body']  = '' !== $body ? substr( $body, 0, 4000 ) : $d['email_body'];
		if ( false === strpos( $out['email_body'], '{code}' ) ) {
			// The e-mail is useless without the code: put it back.
			$out['email_body'] .= "\n\n{code}";
		}

		return $out;
	}

	/**
	 * Whether a yes/no setting is on.
	 *
	 * @param array  $settings Settings.
	 * @param string $key      A YES_NO key.
	 * @return bool
	 */
	public static function on( array $settings, $key ) {
		return isset( $settings[ $key ] ) && 'yes' === $settings[ $key ];
	}

	/**
	 * The labels of the settings in words (the Settings tab and the audit log).
	 *
	 * @return array<string, string>
	 */
	public static function labels() {
		return array(
			'guest_codes'            => __( 'Someone who is not logged in may type a gift card code at checkout', 'fishotel-misc-plugin' ),
			'cancel_paid_restores'   => __( 'When an order that was already paid is cancelled, put its gift card money back on the card', 'fishotel-misc-plugin' ),
			'wallet_pays_gift_cards' => __( 'The gift card balance may pay for a gift card in the cart', 'fishotel-misc-plugin' ),
			'books_include_disabled' => __( '"Still owed on all cards" (the number for the books) counts the money on disabled cards', 'fishotel-misc-plugin' ),
			'send_imported_pending'  => __( 'Send the e-mail of imported cards whose delivery date is still ahead', 'fishotel-misc-plugin' ),
			'recalc_guard'           => __( 'On old orders, keep the gift card deduction when Recalculate is pressed', 'fishotel-misc-plugin' ),
			'editor_capability'      => __( 'Who may issue cards and change balances', 'fishotel-misc-plugin' ),
			'custom_min'             => __( 'Smallest custom amount', 'fishotel-misc-plugin' ),
			'custom_max'             => __( 'Largest custom amount', 'fishotel-misc-plugin' ),
			'redeem_tries'           => __( 'Wrong codes allowed per person per hour', 'fishotel-misc-plugin' ),
			'email_subject'          => __( 'E-mail subject', 'fishotel-misc-plugin' ),
			'email_heading'          => __( 'E-mail heading', 'fishotel-misc-plugin' ),
			'email_body'             => __( 'E-mail text', 'fishotel-misc-plugin' ),
		);
	}
}
