<?php
/**
 * Lost Fish e-mail — the one e-mail a case sends (and its one reminder):
 * which fish was lost, when the rest of the order ships, and the four
 * choices as links, each with its money beside it.
 *
 * The template is plain text with {placeholders} (Settings; an empty
 * field means the default below): every value is escaped, blank lines
 * make paragraphs, and {choices} becomes the block of links. A template
 * that forgot {choices} still gets the block at its end — an e-mail
 * without the links would ask a question nobody can answer.
 *
 * choice_rows() is the single place the wording and the money of the four
 * choices come from: the e-mail, the preview on the Cases screen and the
 * customer's page all print these rows, so they can never disagree.
 *
 * Sending is wp_mail(), with the store's own sender (WooCommerce's "from"
 * name and address, when set) and, when WooCommerce is there and the
 * setting is on, the store's e-mail header and footer around the message.
 * Nothing here decides WHETHER to send — that is Cases::send(), behind the
 * owner's click.
 *
 * @package FisHotel\Misc\Sections\Lost_Fish
 */

namespace FisHotel\Misc\Sections\Lost_Fish;

defined( 'ABSPATH' ) || exit;

/**
 * Class Email
 */
class Email {

	/**
	 * The placeholders a template may use.
	 *
	 * @var string[]
	 */
	const PLACEHOLDERS = array( 'customer_name', 'fish', 'qty', 'fish_line', 'order_number', 'ship_line', 'ship_date', 'delivery_date', 'choices', 'site_name' );

	/**
	 * The default subject.
	 *
	 * @return string
	 */
	public static function default_subject() {
		return __( 'About your order #{order_number} at {site_name}: a fish we lost', 'fishotel-misc-plugin' );
	}

	/**
	 * The default body.
	 *
	 * @return string
	 */
	public static function default_body() {
		return implode(
			"\n\n",
			array(
				__( 'Hi {customer_name},', 'fishotel-misc-plugin' ),
				__( 'We are sorry to tell you that we lost {fish_line} from your order #{order_number} while it was in our care, and we do not have another one to send in its place right now.', 'fishotel-misc-plugin' ),
				'{ship_line}',
				__( 'Please tell us what you would like us to do. Pick one:', 'fishotel-misc-plugin' ),
				'{choices}',
				__( 'Nothing changes until you pick one and press Confirm on the page that opens. If you have any question, just reply to this e-mail.', 'fishotel-misc-plugin' ),
				__( "Thank you for your patience,\n{site_name}", 'fishotel-misc-plugin' ),
			)
		);
	}

	/**
	 * The subject and body in use: the saved ones, else the defaults.
	 *
	 * @param array $settings Settings::get().
	 * @return array{subject: string, body: string}
	 */
	public static function template( array $settings ) {
		$subject = trim( (string) ( $settings['subject'] ?? '' ) );
		$body    = trim( (string) ( $settings['body'] ?? '' ) );

		return array(
			'subject' => '' !== $subject ? $subject : self::default_subject(),
			'body'    => '' !== $body ? $body : self::default_body(),
		);
	}

	/**
	 * A date for a person ("Tuesday, November 17, 2026").
	 *
	 * @param string $ymd Y-m-d.
	 * @return string '' when it is not a date.
	 */
	public static function date_label( $ymd ) {
		if ( '' === Orders::ymd( $ymd ) ) {
			return '';
		}
		$ts = (int) strtotime( $ymd . ' 12:00:00 UTC' );
		if ( function_exists( 'wp_date' ) ) {
			$label = wp_date( 'l, F j, Y', $ts, new \DateTimeZone( 'UTC' ) );
			if ( is_string( $label ) && '' !== $label ) {
				return $label;
			}
		}
		return gmdate( 'l, F j, Y', $ts );
	}

	/**
	 * The sentence about when the rest of the order ships.
	 *
	 * @param array $facts     Orders::facts() (ship_date, delivery_date).
	 * @param bool  $rest_left Whether anything else is still to ship on the order.
	 * @return string
	 */
	public static function ship_line( array $facts, $rest_left = true ) {
		if ( ! $rest_left ) {
			return __( 'There is nothing else left to ship on this order.', 'fishotel-misc-plugin' );
		}

		$ship     = self::date_label( (string) ( $facts['ship_date'] ?? '' ) );
		$delivery = self::date_label( (string) ( $facts['delivery_date'] ?? '' ) );

		if ( '' !== $ship && '' !== $delivery ) {
			/* translators: 1: ship date, 2: delivery date */
			return sprintf( __( 'The rest of your order is still on its way as planned: it ships on %1$s, for delivery on %2$s.', 'fishotel-misc-plugin' ), $ship, $delivery );
		}
		if ( '' !== $delivery ) {
			/* translators: %s: delivery date */
			return sprintf( __( 'The rest of your order is still on its way as planned, for delivery on %s.', 'fishotel-misc-plugin' ), $delivery );
		}

		return __( 'The rest of your order is not affected. We will confirm your ship date with you.', 'fishotel-misc-plugin' );
	}

	/**
	 * "1 × Blue Tang".
	 *
	 * @param array $case The case.
	 * @return string
	 */
	public static function fish_line( array $case ) {
		$name = trim( (string) ( $case['fish_name'] ?? '' ) );
		if ( '' === $name ) {
			$name = trim( (string) ( $case['species'] ?? '' ) );
		}
		return max( 1, (int) ( $case['qty'] ?? 1 ) ) . ' × ' . ( '' !== $name ? $name : __( 'fish', 'fishotel-misc-plugin' ) );
	}

	/**
	 * The four choices (three when the whole order cannot be cancelled
	 * any more), each with its title, its money and its sentence.
	 *
	 * @param array $case    The case (ship_charged = the shipping paid, tax included).
	 * @param array $numbers Cases::numbers() (fish_paid, ship_diff, refund, credit, cancel, offer_cancel).
	 * @param array $facts   Orders::facts() (number, symbol).
	 * @param array $links   choice => URL ('' or missing = no link).
	 * @return array<int, array{choice: string, title: string, amount: int|null, money: string, text: string, url: string, confirm: string}>
	 */
	public static function choice_rows( array $case, array $numbers, array $facts, array $links = array() ) {
		$symbol = (string) ( $facts['symbol'] ?? '$' );
		$money  = static function ( $cents ) use ( $symbol ) {
			return Money::format( $cents, $symbol );
		};

		$fish      = (int) ( $numbers['fish_paid'] ?? 0 );
		$diff      = isset( $numbers['ship_diff'] ) ? max( 0, (int) $numbers['ship_diff'] ) : 0;
		$refund    = isset( $numbers['refund'] ) ? (int) $numbers['refund'] : null;
		$paid_ship = max( 0, (int) ( $case['ship_charged'] ?? 0 ) );

		$shipping = '';
		if ( $diff > 0 ) {
			$shipping = sprintf(
				/* translators: 1: the order's shipping without the fish, 2: the shipping paid */
				__( 'Without this fish your order is no longer over the amount for reduced shipping, so its shipping is %1$s instead of the %2$s you paid.', 'fishotel-misc-plugin' ),
				$money( $paid_ship + $diff ),
				$money( $paid_ship )
			);
		}

		$back = static function ( $plain, $less, $nothing ) use ( $fish, $diff, $refund, $shipping, $money ) {
			if ( null === $refund ) {
				return '';
			}
			if ( $diff <= 0 ) {
				return sprintf( $plain, $money( $refund ) );
			}
			if ( $refund <= 0 ) {
				return sprintf( $nothing, $money( $fish ), $money( $diff ) ) . ' ' . $shipping;
			}
			return sprintf( $less, $money( $refund ), $money( $fish ), $money( $diff ) ) . ' ' . $shipping;
		};

		$rows = array();

		$rows[] = array(
			'choice'  => 'refund',
			'title'   => __( 'Refund the lost fish', 'fishotel-misc-plugin' ),
			'amount'  => $refund,
			'text'    => $back(
				/* translators: %s: amount */
				__( 'We refund %s — what you paid for this fish — to the way you paid.', 'fishotel-misc-plugin' ),
				/* translators: 1: refund, 2: paid for the fish, 3: shipping difference */
				__( 'We refund %1$s to the way you paid: %2$s for the fish, less %3$s shipping.', 'fishotel-misc-plugin' ),
				/* translators: 1: paid for the fish, 2: shipping difference */
				__( 'There would be nothing to refund: you paid %1$s for the fish, and the shipping difference is %2$s.', 'fishotel-misc-plugin' )
			),
			'confirm' => __( 'Yes, refund the lost fish', 'fishotel-misc-plugin' ),
		);

		$rows[] = array(
			'choice'  => 'credit',
			'title'   => __( 'Take a store credit', 'fishotel-misc-plugin' ),
			'amount'  => $refund,
			'text'    => $back(
				/* translators: %s: amount */
				__( 'We add %s — what you paid for this fish — to your store credit, to use on anything later.', 'fishotel-misc-plugin' ),
				/* translators: 1: credit, 2: paid for the fish, 3: shipping difference */
				__( 'We add %1$s to your store credit: %2$s for the fish, less %3$s shipping.', 'fishotel-misc-plugin' ),
				/* translators: 1: paid for the fish, 2: shipping difference */
				__( 'There would be nothing to credit: you paid %1$s for the fish, and the shipping difference is %2$s.', 'fishotel-misc-plugin' )
			),
			'confirm' => __( 'Yes, give me a store credit', 'fishotel-misc-plugin' ),
		);

		$rows[] = array(
			'choice'  => 'retry',
			'title'   => __( 'Try again', 'fishotel-misc-plugin' ),
			'amount'  => null,
			'text'    => __( 'We keep your payment for this fish and get you another one as soon as we can. Nothing is refunded, and the shipping of your order stays as it is.', 'fishotel-misc-plugin' ),
			'confirm' => __( 'Yes, try again', 'fishotel-misc-plugin' ),
		);

		if ( ! empty( $numbers['offer_cancel'] ) && isset( $numbers['cancel'] ) ) {
			$rows[] = array(
				'choice'  => 'cancel',
				'title'   => __( 'Cancel the whole order', 'fishotel-misc-plugin' ),
				'amount'  => (int) $numbers['cancel'],
				/* translators: 1: order number, 2: amount */
				'text'    => sprintf( __( 'We cancel order #%1$s and refund everything you paid for it: %2$s.', 'fishotel-misc-plugin' ), (string) ( $facts['number'] ?? '' ), $money( (int) $numbers['cancel'] ) ),
				'confirm' => __( 'Yes, cancel the whole order', 'fishotel-misc-plugin' ),
			);
		}

		foreach ( $rows as $index => $row ) {
			$rows[ $index ]['money'] = null !== $row['amount'] ? $money( $row['amount'] ) : '';
			$rows[ $index ]['url']   = isset( $links[ $row['choice'] ] ) ? (string) $links[ $row['choice'] ] : '';
		}

		return $rows;
	}

	/**
	 * One choice row by its choice.
	 *
	 * @param array[] $rows   choice_rows().
	 * @param string  $choice The choice.
	 * @return array|null
	 */
	public static function row_for( array $rows, $choice ) {
		foreach ( $rows as $row ) {
			if ( $row['choice'] === (string) $choice ) {
				return $row;
			}
		}
		return null;
	}

	/**
	 * The values of the placeholders (plain text, not yet escaped).
	 *
	 * @param array $case  The case.
	 * @param array $facts Orders::facts().
	 * @param bool  $rest  Whether anything else is still to ship.
	 * @return array<string, string>
	 */
	public static function values( array $case, array $facts, $rest = true ) {
		$name = trim( (string) ( $facts['first_name'] ?? '' ) );
		if ( '' === $name ) {
			$name = trim( (string) ( $facts['customer_name'] ?? ( $case['customer_name'] ?? '' ) ) );
		}
		if ( '' === $name ) {
			$name = __( 'there', 'fishotel-misc-plugin' );
		}

		$fish = trim( (string) ( $case['fish_name'] ?? '' ) );

		return array(
			'customer_name' => $name,
			'fish'          => '' !== $fish ? $fish : trim( (string) ( $case['species'] ?? '' ) ),
			'qty'           => (string) max( 1, (int) ( $case['qty'] ?? 1 ) ),
			'fish_line'     => self::fish_line( $case ),
			'order_number'  => (string) ( $facts['number'] ?? ( $case['order_id'] ?? '' ) ),
			'ship_line'     => self::ship_line( $facts, $rest ),
			'ship_date'     => self::date_label( (string) ( $facts['ship_date'] ?? '' ) ),
			'delivery_date' => self::date_label( (string) ( $facts['delivery_date'] ?? '' ) ),
			'site_name'     => self::site_name(),
		);
	}

	/**
	 * The site's name as plain text.
	 *
	 * @return string
	 */
	public static function site_name() {
		$name = function_exists( 'get_bloginfo' ) ? (string) get_bloginfo( 'name' ) : (string) get_option( 'blogname', '' );
		return trim( html_entity_decode( $name, ENT_QUOTES, 'UTF-8' ) );
	}

	/**
	 * Put the values into a piece of template. {choices} is left alone.
	 *
	 * @param string $text   Template text.
	 * @param array  $values values().
	 * @param bool   $escape Whether the values are escaped for HTML.
	 * @return string
	 */
	public static function fill( $text, array $values, $escape ) {
		$pairs = array();
		foreach ( $values as $key => $value ) {
			$pairs[ '{' . $key . '}' ] = $escape ? esc_html( (string) $value ) : (string) $value;
		}
		return strtr( (string) $text, $pairs );
	}

	/**
	 * The whole message.
	 *
	 * @param array $case     The case.
	 * @param array $facts    Orders::facts().
	 * @param array $numbers  Cases::numbers().
	 * @param array $links    choice => URL.
	 * @param array $settings Settings::get().
	 * @param array $opts     reminder (bool), rest_left (bool, default true), bare (bool: no shell — the preview's inner HTML).
	 * @return array{to: string, subject: string, html: string, inner: string, headers: string[], rows: array[]}
	 */
	public static function build( array $case, array $facts, array $numbers, array $links, array $settings, array $opts = array() ) {
		$template = self::template( $settings );
		$values   = self::values( $case, $facts, ! array_key_exists( 'rest_left', $opts ) || ! empty( $opts['rest_left'] ) );
		$rows     = self::choice_rows( $case, $numbers, $facts, $links );

		$subject = trim( (string) preg_replace( '/\s+/', ' ', self::fill( str_replace( '{choices}', '', $template['subject'] ), $values, false ) ) );
		$body    = $template['body'];
		if ( ! empty( $opts['reminder'] ) ) {
			/* translators: %s: subject */
			$subject = sprintf( __( 'Reminder: %s', 'fishotel-misc-plugin' ), $subject );
			$body    = __( 'A few days ago we wrote to you about a fish we lost from your order, and we have not heard back yet. Here it is again:', 'fishotel-misc-plugin' ) . "\n\n" . $body;
		}
		if ( false === strpos( $body, '{choices}' ) ) {
			$body .= "\n\n{choices}";
		}

		$inner = '';
		foreach ( preg_split( '/\R\s*\R/', trim( str_replace( "\r\n", "\n", $body ) ) ) as $block ) {
			$block = trim( (string) $block );
			if ( '' === $block ) {
				continue;
			}
			foreach ( explode( '{choices}', $block ) as $index => $piece ) {
				if ( $index > 0 ) {
					$inner .= self::choices_html( $rows );
				}
				$piece = trim( self::fill( esc_html( $piece ), $values, true ) );
				if ( '' !== $piece ) {
					$inner .= '<p style="margin:0 0 16px;">' . nl2br( $piece ) . '</p>';
				}
			}
		}

		/* translators: %s: order number */
		$heading = sprintf( __( 'About your order #%s', 'fishotel-misc-plugin' ), $values['order_number'] );
		$headers = array( 'Content-Type: text/html; charset=UTF-8' );
		$reply   = trim( (string) ( $settings['reply_to'] ?? '' ) );
		if ( '' !== $reply && function_exists( 'is_email' ) && is_email( $reply ) ) {
			$headers[] = 'Reply-To: ' . $reply;
		}

		$message = array(
			'to'      => trim( (string) ( $facts['email'] ?? ( $case['customer_email'] ?? '' ) ) ),
			'subject' => $subject,
			'inner'   => $inner,
			'html'    => ! empty( $opts['bare'] ) ? self::shell( $inner, $heading ) : self::wrap( $inner, $heading, $settings ),
			'headers' => $headers,
			'rows'    => $rows,
		);

		if ( function_exists( 'apply_filters' ) ) {
			$filtered = apply_filters( 'fishotel_lostfish_email', $message, $case, $facts );
			if ( is_array( $filtered ) && isset( $filtered['to'], $filtered['subject'], $filtered['html'] ) ) {
				$message = array_merge( $message, $filtered );
			}
		}

		return $message;
	}

	/**
	 * The block of choices: one bordered box per choice, its title (and
	 * money) as the link.
	 *
	 * @param array[] $rows choice_rows().
	 * @return string
	 */
	public static function choices_html( array $rows ) {
		$html = '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="border-collapse:separate;border-spacing:0 10px;margin:0 0 6px;">';
		foreach ( $rows as $row ) {
			$label = esc_html( $row['title'] ) . ( '' !== $row['money'] ? ' — ' . esc_html( $row['money'] ) : '' );
			$title = '' !== $row['url']
				? '<a href="' . esc_url( $row['url'] ) . '" style="font-size:16px;font-weight:700;color:#0b6bcb;text-decoration:underline;">' . $label . '</a>'
				: '<strong style="font-size:16px;">' . $label . '</strong>';

			$html .= '<tr><td style="padding:14px 16px;border:1px solid #d5dae0;border-radius:6px;background:#fafbfc;">'
				. $title
				. ( '' !== $row['text'] ? '<div style="margin-top:6px;font-size:14px;line-height:1.5;color:#3c434a;">' . esc_html( $row['text'] ) . '</div>' : '' )
				. '</td></tr>';
		}
		return $html . '</table>';
	}

	/**
	 * The message inside the store's own e-mail header and footer when
	 * WooCommerce is there and the setting is on; else inside the plain
	 * shell.
	 *
	 * @param string $inner    The message's HTML.
	 * @param string $heading  The heading.
	 * @param array  $settings Settings::get().
	 * @return string
	 */
	public static function wrap( $inner, $heading, array $settings ) {
		if ( ! empty( $settings['store_design'] ) && function_exists( 'WC' ) ) {
			try {
				$mailer = WC()->mailer();
				if ( is_object( $mailer ) && method_exists( $mailer, 'wrap_message' ) ) {
					$wrapped = $mailer->wrap_message( $heading, $inner );
					if ( class_exists( '\\WC_Email' ) ) {
						$email = new \WC_Email();
						if ( method_exists( $email, 'style_inline' ) ) {
							$wrapped = $email->style_inline( $wrapped );
						}
					}
					if ( is_string( $wrapped ) && '' !== trim( $wrapped ) ) {
						return $wrapped;
					}
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}
		}

		return self::shell( $inner, $heading );
	}

	/**
	 * The plain shell: a white card on a light background.
	 *
	 * @param string $inner   The message's HTML.
	 * @param string $heading The heading.
	 * @return string
	 */
	public static function shell( $inner, $heading ) {
		return '<!DOCTYPE html><html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>' . esc_html( $heading ) . '</title></head>'
			. '<body style="margin:0;padding:0;background:#f3f4f6;">'
			. '<div style="max-width:600px;margin:0 auto;padding:24px 12px;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Helvetica,Arial,sans-serif;font-size:15px;line-height:1.6;color:#1d2327;">'
			. '<div style="background:#ffffff;border:1px solid #e2e5e9;border-radius:8px;padding:24px;">'
			. '<h1 style="margin:0 0 16px;font-size:20px;line-height:1.3;">' . esc_html( $heading ) . '</h1>'
			. $inner
			. '</div></div></body></html>';
	}

	/**
	 * Send a message through wp_mail(), as the store's own sender.
	 *
	 * @param array $message build().
	 * @return bool Whether wp_mail() accepted it.
	 */
	public static function send( array $message ) {
		$to = trim( (string) ( $message['to'] ?? '' ) );
		if ( '' === $to || ! function_exists( 'wp_mail' ) || ( function_exists( 'is_email' ) && ! is_email( $to ) ) ) {
			return false;
		}

		$from_address = static function ( $address ) {
			$store = (string) get_option( 'woocommerce_email_from_address', '' );
			return '' !== $store && function_exists( 'is_email' ) && is_email( $store ) ? $store : $address;
		};
		$from_name    = static function ( $name ) {
			$store = trim( html_entity_decode( (string) get_option( 'woocommerce_email_from_name', '' ), ENT_QUOTES, 'UTF-8' ) );
			return '' !== $store ? $store : $name;
		};

		add_filter( 'wp_mail_from', $from_address, 20 );
		add_filter( 'wp_mail_from_name', $from_name, 20 );
		try {
			$sent = wp_mail( $to, (string) $message['subject'], (string) $message['html'], (array) ( $message['headers'] ?? array() ) );
		} catch ( \Throwable $e ) {
			$sent = false;
		}
		if ( function_exists( 'remove_filter' ) ) {
			remove_filter( 'wp_mail_from', $from_address, 20 );
			remove_filter( 'wp_mail_from_name', $from_name, 20 );
		}

		return (bool) $sent;
	}
}
