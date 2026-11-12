<?php
/**
 * Requests mailer — the five e-mails (invoice for the fee, fee reminder,
 * the fish is ready, still want it?, declined), each built from its
 * editable subject and body in Settings, sent through wp_mail() as simple
 * HTML with a plain-text part, and written to the request's log.
 *
 * Nothing else in the section sends e-mail. send() answers:
 *
 *   'sent'      wp_mail() accepted it
 *   'off'       that kind of e-mail is switched off in Settings
 *   'no_email'  the request has no e-mail address
 *   'failed'    wp_mail() refused it (logged on the request)
 *
 * render() and html() are pure, so what each e-mail contains is testable.
 *
 * @package FisHotel\Misc\Sections\Requests
 */

namespace FisHotel\Misc\Sections\Requests;

defined( 'ABSPATH' ) || exit;

/**
 * Class Mailer
 */
class Mailer {

	/**
	 * The store the log is written to.
	 *
	 * @var object
	 */
	private $store;

	/**
	 * Settings (Settings::get()).
	 *
	 * @var array
	 */
	private $settings;

	/**
	 * Constructor.
	 *
	 * @param object $store    Store (log()).
	 * @param array  $settings Settings.
	 */
	public function __construct( $store, array $settings ) {
		$this->store    = $store;
		$this->settings = $settings;
	}

	/**
	 * Whether a kind of e-mail is switched on.
	 *
	 * @param string $kind One of Settings::MAIL_KINDS.
	 * @return bool
	 */
	public function is_on( $kind ) {
		return ! empty( $this->settings['mail'][ $kind ]['on'] );
	}

	/**
	 * Send one e-mail about a request and log it.
	 *
	 * @param string $kind    One of Settings::MAIL_KINDS.
	 * @param array  $request The request (the carrier, for an invoice).
	 * @param array  $extra   requests (array[] — every request the e-mail is about), pay_url,
	 *                        product_url, note, days.
	 * @return string sent|off|no_email|failed.
	 */
	public function send( $kind, array $request, array $extra = array() ) {
		$kind = (string) $kind;
		if ( ! in_array( $kind, Settings::MAIL_KINDS, true ) || ! $this->is_on( $kind ) ) {
			return 'off';
		}

		$to = Rules::email( $request['email'] ?? '' );
		if ( '' === $to ) {
			return 'no_email';
		}

		$mail = self::render( $kind, $request, $this->settings, $extra );
		$args = array(
			'to'      => $to,
			'subject' => $mail['subject'],
			'html'    => $mail['html'],
			'text'    => $mail['text'],
			'headers' => array( 'Content-Type: text/html; charset=UTF-8' ),
		);
		if ( function_exists( 'apply_filters' ) ) {
			$filtered = apply_filters( 'fishotel_requests_mail', $args, $kind, $request );
			if ( is_array( $filtered ) && ! empty( $filtered['to'] ) ) {
				$args = array_merge( $args, $filtered );
			}
		}

		$text = (string) $args['text'];
		$alt  = static function ( $phpmailer ) use ( $text ) {
			if ( is_object( $phpmailer ) ) {
				$phpmailer->AltBody = $text; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- PHPMailer's own property.
			}
		};

		$ok = false;
		try {
			add_action( 'phpmailer_init', $alt );
			$ok = (bool) wp_mail( $args['to'], $args['subject'], $args['html'], $args['headers'] );
		} catch ( \Throwable $e ) {
			$ok = false;
		}
		if ( function_exists( 'remove_action' ) ) {
			remove_action( 'phpmailer_init', $alt );
		}

		$labels = Settings::mail_labels();
		$label  = isset( $labels[ $kind ] ) ? $labels[ $kind ]['label'] : $kind;
		$this->store->log(
			(int) ( $request['id'] ?? 0 ),
			$ok ? 'mail' : 'mail_failed',
			$ok
				/* translators: 1: which e-mail, 2: address */
				? sprintf( __( 'E-mail "%1$s" sent to %2$s.', 'fishotel-misc-plugin' ), $label, $to )
				/* translators: 1: which e-mail, 2: address */
				: sprintf( __( 'E-mail "%1$s" to %2$s could NOT be sent.', 'fishotel-misc-plugin' ), $label, $to ),
			0,
			'system'
		);

		return $ok ? 'sent' : 'failed';
	}

	/**
	 * Build one e-mail (pure): the subject, the plain text and the HTML.
	 *
	 * @param string $kind     One of Settings::MAIL_KINDS.
	 * @param array  $request  The request.
	 * @param array  $settings Settings.
	 * @param array  $extra    As send().
	 * @return array{subject: string, text: string, html: string}
	 */
	public static function render( $kind, array $request, array $settings, array $extra = array() ) {
		$defaults = Settings::mail_defaults();
		$template = isset( $settings['mail'][ $kind ] ) && is_array( $settings['mail'][ $kind ] ) ? $settings['mail'][ $kind ] : array();
		$subject  = isset( $template['subject'] ) && '' !== trim( (string) $template['subject'] ) ? (string) $template['subject'] : (string) ( $defaults[ $kind ]['subject'] ?? '' );
		$body     = isset( $template['body'] ) && '' !== trim( (string) $template['body'] ) ? (string) $template['body'] : (string) ( $defaults[ $kind ]['body'] ?? '' );
		$vars     = self::vars( $kind, $request, $settings, $extra );

		$subject = Rules::text( self::fill( $subject, $vars ), 250 );
		$text    = self::tidy( self::fill( $body, $vars ) );

		return array(
			'subject' => $subject,
			'text'    => $text,
			'html'    => self::html( $text ),
		);
	}

	/**
	 * The placeholders of an e-mail.
	 *
	 * @param string $kind     Kind.
	 * @param array  $request  The request.
	 * @param array  $settings Settings.
	 * @param array  $extra    As send().
	 * @return array<string, string>
	 */
	public static function vars( $kind, array $request, array $settings, array $extra = array() ) {
		$all  = isset( $extra['requests'] ) && is_array( $extra['requests'] ) && $extra['requests'] ? $extra['requests'] : array( $request );
		$fish = array();
		foreach ( $all as $row ) {
			if ( is_array( $row ) ) {
				$fish[] = Rules::fish_line( $row );
			}
		}

		$name = trim( (string) ( $request['name'] ?? '' ) );
		if ( '' === $name ) {
			$name = trim( (string) ( $request['handle'] ?? '' ) );
		}
		if ( '' === $name ) {
			$name = __( 'there', 'fishotel-misc-plugin' );
		}

		$product_url = isset( $extra['product_url'] ) ? (string) $extra['product_url'] : '';
		if ( ! empty( $request['owed'] ) ) {
			$ready_line  = __( 'This one is already paid for — there is nothing to pay. We will be in touch to arrange shipping.', 'fishotel-misc-plugin' );
			$product_url = '';
		} elseif ( '' !== $product_url ) {
			/* translators: %s: product link */
			$ready_line = sprintf( __( 'You can buy it here: %s', 'fishotel-misc-plugin' ), $product_url );
		} else {
			$ready_line = __( 'Reply to this e-mail and we will set it aside for you.', 'fishotel-misc-plugin' );
		}

		$days = isset( $extra['days'] ) ? (int) $extra['days'] : 0;
		if ( $days <= 0 ) {
			$days = 'still_want' === $kind ? Rules::ASK_ANSWER_DAYS : (int) ( $settings['expire_days'] ?? 7 );
		}

		return array(
			'{name}'        => $name,
			'{fish}'        => implode( "\n", $fish ),
			'{size}'        => isset( $request['size_label'] ) && null !== $request['size_label'] ? (string) $request['size_label'] : '',
			'{quantity}'    => (string) max( 1, (int) ( $request['qty'] ?? 1 ) ),
			'{fee}'         => Rules::money( (float) ( $request['fee_amount'] ?? 0 ), Urls::currency_symbol() ),
			'{pay_url}'     => isset( $extra['pay_url'] ) ? (string) $extra['pay_url'] : '',
			'{view_url}'    => Urls::view( $request ),
			'{product_url}' => $product_url,
			'{ready_line}'  => $ready_line,
			'{keep_url}'    => Urls::answer( $request, 'keep' ),
			'{cancel_url}'  => Urls::answer( $request, 'cancel' ),
			'{note}'        => isset( $extra['note'] ) ? Rules::lines( $extra['note'], Rules::MAX_NOTE ) : '',
			'{days}'        => (string) $days,
			'{site}'        => Urls::site_name(),
		);
	}

	/**
	 * Fill the placeholders of a template.
	 *
	 * @param string                $template Text with {placeholders}.
	 * @param array<string, string> $vars     Placeholder → value.
	 * @return string
	 */
	public static function fill( $template, array $vars ) {
		return strtr( (string) $template, $vars );
	}

	/**
	 * Tidy a filled body: a line that an empty placeholder left blank does
	 * not leave three empty lines behind.
	 *
	 * @param string $text Filled body.
	 * @return string
	 */
	public static function tidy( $text ) {
		$text = str_replace( array( "\r\n", "\r" ), "\n", (string) $text );
		$text = (string) preg_replace( '/[ \t]+\n/', "\n", $text );
		return trim( (string) preg_replace( '/\n{3,}/', "\n\n", $text ) );
	}

	/**
	 * A plain-text body as simple HTML: paragraphs, line breaks, links —
	 * everything else escaped.
	 *
	 * @param string $text Plain text.
	 * @return string
	 */
	public static function html( $text ) {
		$out = '';
		foreach ( (array) preg_split( '/\n{2,}/', trim( (string) $text ) ) as $paragraph ) {
			if ( '' === trim( (string) $paragraph ) ) {
				continue;
			}
			$parts = preg_split( '~(https?://[^\s<>"\']+[^\s<>"\'.,;:!?)])~', (string) $paragraph, -1, PREG_SPLIT_DELIM_CAPTURE );
			$html  = '';
			foreach ( (array) $parts as $index => $part ) {
				if ( 1 === $index % 2 ) {
					$html .= '<a href="' . esc_url( $part ) . '" style="color:#0b6fa4">' . esc_html( $part ) . '</a>';
				} else {
					$html .= nl2br( esc_html( $part ) );
				}
			}
			$out .= '<p style="margin:0 0 14px">' . $html . '</p>';
		}

		return '<div style="font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Arial,sans-serif;font-size:15px;line-height:1.55;color:#222;max-width:560px">' . $out . '</div>';
	}
}
