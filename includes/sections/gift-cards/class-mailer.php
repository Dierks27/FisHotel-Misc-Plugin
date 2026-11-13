<?php
/**
 * Gift Cards mailer — the one e-mail: "you have a gift card", with the
 * full code, to the recipient (or to the buyer when no recipient address
 * was given). Sent through wp_mail(), wrapped in WooCommerce's e-mail
 * template when WooCommerce is there, so it looks like the shop's other
 * e-mails. compose() is pure.
 *
 * @package FisHotel\Misc\Sections\Gift_Cards
 */

namespace FisHotel\Misc\Sections\Gift_Cards;

defined( 'ABSPATH' ) || exit;

/**
 * Class Mailer
 */
class Mailer {

	/**
	 * Settings.
	 *
	 * @var array
	 */
	private $settings;

	/**
	 * The shop's name and links: shop, shop_url, account_url.
	 *
	 * @var array
	 */
	private $site;

	/**
	 * Build.
	 *
	 * @param array $settings Settings::get().
	 * @param array $site     shop, shop_url, account_url (defaults read from WordPress).
	 */
	public function __construct( array $settings, array $site = array() ) {
		$this->settings = $settings;
		$this->site     = array_merge( self::site(), $site );
	}

	/**
	 * The shop's name and links from WordPress.
	 *
	 * @return array{shop: string, shop_url: string, account_url: string}
	 */
	public static function site() {
		$shop    = function_exists( 'get_bloginfo' ) ? wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ) : 'the shop';
		$home    = function_exists( 'home_url' ) ? home_url( '/' ) : '';
		$account = function_exists( 'wc_get_account_endpoint_url' ) ? (string) wc_get_account_endpoint_url( Account::ENDPOINT ) : $home;
		return array(
			'shop'        => $shop,
			'shop_url'    => $home,
			'account_url' => $account,
		);
	}

	/**
	 * Where a card's e-mail goes: the recipient, else the buyer.
	 *
	 * @param array $card Card row.
	 * @return string '' when there is no address.
	 */
	public static function address( array $card ) {
		foreach ( array( 'recipient_email', 'sender_email' ) as $key ) {
			$email = trim( (string) ( $card[ $key ] ?? '' ) );
			if ( '' !== $email && is_email( $email ) ) {
				return $email;
			}
		}
		return '';
	}

	/**
	 * The e-mail of a card (pure).
	 *
	 * @param array  $card Card row (with its full code).
	 * @param string $to   Address ('' = address()).
	 * @return array{to: string, subject: string, heading: string, text: string, html: string}
	 */
	public function compose( array $card, $to = '' ) {
		$to        = '' !== (string) $to ? (string) $to : self::address( $card );
		$recipient = trim( (string) $card['recipient_name'] );
		$sender    = trim( (string) $card['sender_name'] );
		$message   = trim( (string) $card['message'] );
		$values    = array(
			'{amount}'      => Money::words( (int) $card['balance_cents'] > 0 ? (int) $card['balance_cents'] : (int) $card['amount_cents'] ),
			'{code}'        => (string) $card['code'],
			'{recipient}'   => '' !== $recipient ? $recipient : __( 'there', 'fishotel-misc-plugin' ),
			'{sender}'      => '' !== $sender ? $sender : $this->site['shop'],
			'{message}'     => $message,
			'{shop}'        => $this->site['shop'],
			'{account_url}' => $this->site['account_url'],
			'{shop_url}'    => $this->site['shop_url'],
		);
		$subject   = strtr( (string) $this->settings['email_subject'], $values );
		$heading   = strtr( (string) $this->settings['email_heading'], $values );
		$text      = preg_replace( "/\n{3,}/", "\n\n", strtr( (string) $this->settings['email_body'], $values ) );

		// HTML: every value escaped, the code set apart so it can be copied.
		$escaped           = array_map( 'esc_html', $values );
		$escaped['{code}'] = '<strong style="font-family:monospace;font-size:20px;letter-spacing:2px;">' . esc_html( (string) $card['code'] ) . '</strong>';
		$html_body         = strtr( esc_html( (string) $this->settings['email_body'] ), array_combine( array_map( 'esc_html', array_keys( $values ) ), $escaped ) );
		$html_body         = preg_replace( '/(<br>\s*){3,}/', '<br><br>', nl2br( $html_body, false ) );

		return array(
			'to'      => $to,
			'subject' => $subject,
			'heading' => $heading,
			'text'    => $text,
			'html'    => '<p>' . $html_body . '</p>',
		);
	}

	/**
	 * Send a card's e-mail.
	 *
	 * @param array  $card Card row.
	 * @param string $to   Address ('' = address()).
	 * @return bool
	 */
	public function send( array $card, $to = '' ) {
		$mail = $this->compose( $card, $to );
		if ( '' === $mail['to'] || ! function_exists( 'wp_mail' ) ) {
			return false;
		}

		$html = $mail['html'];
		if ( function_exists( 'WC' ) && is_object( WC() ) && method_exists( WC(), 'mailer' ) ) {
			try {
				$html = WC()->mailer()->wrap_message( $mail['heading'], $html );
			} catch ( \Throwable $e ) {
				$html = $mail['html'];
			}
		}

		$text = $mail['text'];
		$alt  = static function ( $phpmailer ) use ( $text ) {
			if ( is_object( $phpmailer ) ) {
				$phpmailer->AltBody = $text; // phpcs:ignore WordPress.NamingConventions.ValidVariableName
			}
		};
		add_action( 'phpmailer_init', $alt );
		try {
			$ok = (bool) wp_mail( $mail['to'], $mail['subject'], $html, array( 'Content-Type: text/html; charset=UTF-8' ) );
		} catch ( \Throwable $e ) {
			$ok = false;
		}
		remove_action( 'phpmailer_init', $alt );

		return $ok;
	}
}
