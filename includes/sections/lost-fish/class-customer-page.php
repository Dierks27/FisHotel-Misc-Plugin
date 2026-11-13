<?php
/**
 * Lost Fish customer page — what opens behind each link of the e-mail:
 * home_url( '/?fh_lost_fish=<token>' ). No login; the signed, expiring
 * token (Token) is what lets the visitor in, and it names one case and
 * one choice.
 *
 *   GET   shows the choice the link stands for, with the same words and
 *         the same money as the e-mail (Email::choice_rows()), a Confirm
 *         button, and the other choices as links. It changes NOTHING —
 *         mail scanners and link previews open links.
 *   POST  with the Confirm field records the choice (Cases::answer()) —
 *         once per case. A second Confirm, on any of the four links,
 *         only shows what was chosen.
 *
 * The e-mail may be days old, so THE ORDER IS READ AGAIN on every
 * request: once it is completed, shipped, cancelled, refunded or gone,
 * every link of a case that still waits says the request is closed and a
 * Confirm records nothing (the case is flagged for the owner); once part
 * of the order has shipped, "cancel the whole order" is no longer offered
 * — its link says so and lists the other choices; and the cancel amount
 * is what is left to give back now (a refund or a store credit made since
 * the e-mail comes off it).
 *
 * After the choice every link of the case says what was chosen. A link
 * whose case was closed or reopened, a link past its expiry and a token
 * that does not verify each get their own plain sentence; a token that
 * does not verify is a 404 and reveals nothing about any case.
 *
 * The page is self-contained (no theme, no scripts, no external request),
 * never cached and never indexed. handle() decides and render() draws,
 * both without output, so the tests drive them directly.
 *
 * @package FisHotel\Misc\Sections\Lost_Fish
 */

namespace FisHotel\Misc\Sections\Lost_Fish;

defined( 'ABSPATH' ) || exit;

/**
 * Class Customer_Page
 */
class Customer_Page {

	/**
	 * The field only the Confirm button's form carries.
	 *
	 * @var string
	 */
	const FIELD_CONFIRM = 'fh_lf_confirm';

	/**
	 * template_redirect (priority 0): answer a request that carries a
	 * token; every other request returns after one isset().
	 */
	public static function maybe_render() {
		// phpcs:disable WordPress.Security.NonceVerification -- no login and no nonce: the signed token is the key.
		if ( ! isset( $_GET[ Lost_Fish::QUERY_ARG ] ) && ! isset( $_POST[ Lost_Fish::QUERY_ARG ] ) ) {
			return;
		}

		$method  = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( (string) $_SERVER['REQUEST_METHOD'] ) : 'GET';
		$is_post = 'POST' === $method;
		$source  = $is_post && isset( $_POST[ Lost_Fish::QUERY_ARG ] ) ? $_POST : $_GET;
		$token   = isset( $source[ Lost_Fish::QUERY_ARG ] ) && is_string( $source[ Lost_Fish::QUERY_ARG ] ) ? wp_unslash( $source[ Lost_Fish::QUERY_ARG ] ) : '';
		$confirm = $is_post && isset( $_POST[ self::FIELD_CONFIRM ], $_POST[ Lost_Fish::QUERY_ARG ] ) && '1' === (string) wp_unslash( $_POST[ self::FIELD_CONFIRM ] );
		// phpcs:enable WordPress.Security.NonceVerification

		try {
			$view = self::handle( (string) $token, $method, $confirm, Cases::instance(), time() );
		} catch ( \Throwable $e ) {
			$view = self::view( 'error' );
		}

		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
		if ( ! headers_sent() ) {
			nocache_headers();
			status_header( (int) $view['http'] );
			header( 'Content-Type: text/html; charset=UTF-8' );
			header( 'X-Robots-Tag: noindex, nofollow' );
			header( 'Referrer-Policy: no-referrer' );
			header( 'X-Frame-Options: DENY' );
		}

		echo self::render( $view ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- render() escapes every value.
		exit;
	}

	/**
	 * Decide what a request gets, and — only for a POST carrying the
	 * Confirm field on a good link of a case that still waits — record the
	 * choice.
	 *
	 * @param string $token   The token as it came in.
	 * @param string $method  The HTTP method.
	 * @param bool   $confirm Whether the Confirm field came with a POST.
	 * @param Cases  $cases   The cases.
	 * @param int    $now     UNIX timestamp.
	 * @return array{state: string, http: int, case: array|null, facts: array|null, row: array|null, others: array[], token: string, values: array}
	 */
	public static function handle( $token, $method, $confirm, Cases $cases, $now ) {
		$parsed = Token::parse( $token );
		if ( ! $parsed ) {
			return self::view( 'invalid' );
		}

		$case = $cases->store()->get_case( $parsed['case_id'] );
		if ( ! $case ) {
			return self::view( 'invalid' );
		}

		$verdict = Token::verify( $parsed, $case['token_salt'], Token::secret(), (int) $now );
		if ( Token::BAD === $verdict ) {
			return self::view( 'invalid' );
		}

		// The signature is good from here on: the visitor holds a link of this case.
		$answered = in_array( $case['status'], Cover::RELEASED, true );
		if ( ! $answered && 'sent' !== $case['status'] ) {
			return self::view( 'closed' );
		}
		if ( ! $answered && Token::EXPIRED === $verdict ) {
			return self::view( 'expired' );
		}
		if ( ! $answered && 'cancel' === $parsed['choice'] && empty( $case['offer_cancel'] ) ) {
			return self::view( 'invalid' );
		}

		$state   = $answered ? 'chosen' : 'confirm';
		$posting = ! $answered && 'POST' === strtoupper( (string) $method ) && $confirm;

		// The order as it is NOW, not as it was when the e-mail went out.
		$order = $cases->orders()->get( (int) $case['order_id'] );
		$live  = $order ? $cases->orders()->facts( $order ) : null;
		if ( ! $answered && ( ! $live || $live['closed'] ) ) {
			if ( $posting ) {
				$cases->answer( (int) $case['id'], $parsed['choice'], 'customer', 0 ); // Refused there — and the owner is told.
			}
			return self::view( 'closed' );
		}
		if ( ! $answered && 'cancel' === $parsed['choice'] && empty( $cases->numbers( $case, $live )['offer_cancel'] ) ) {
			$state = 'shipped'; // Part of the order left since the e-mail: no Confirm, the other choices.
		}

		if ( $posting && 'confirm' === $state ) {
			$result = $cases->answer( (int) $case['id'], $parsed['choice'], 'customer', 0 );
			$case   = is_array( $result['case'] ) ? $result['case'] : $case;
			if ( $result['ok'] ) {
				$state = 'recorded';
			} elseif ( in_array( $case['status'], Cover::RELEASED, true ) ) {
				$state = 'chosen'; // Somebody confirmed a moment earlier: show what was chosen.
			} elseif ( 'closed' === $result['code'] ) {
				return self::view( 'closed' );
			} else {
				return self::view( 'error' );
			}
		}

		$facts   = $live ? $live : self::bare_facts( $case );
		$numbers = $cases->numbers( $case, $live );
		$open    = in_array( $state, array( 'confirm', 'shipped' ), true );
		$links   = $open ? Cases::links( $case, (int) $parsed['expires'], ! empty( $numbers['offer_cancel'] ) ) : array();
		$rest    = $order ? $cases->orders()->rest_left( $order, $case['line_ref'], (int) $case['qty'] ) : true;
		$rows    = Email::choice_rows( $case, $numbers, $facts, $links, $rest );
		$choice  = $open ? $parsed['choice'] : $case['choice'];
		$others  = array();
		foreach ( $rows as $row ) {
			if ( $row['choice'] !== $choice && $open ) {
				$others[] = $row;
			}
		}

		$view           = self::view( $state );
		$view['case']   = $case;
		$view['facts']  = $facts;
		$view['row']    = Email::row_for( $rows, $choice );
		$view['others'] = $others;
		$view['token']  = (string) $token;
		$view['values'] = Email::values( $case, $facts, $rest );

		return $view;
	}

	/**
	 * An empty view of a state.
	 *
	 * @param string $state invalid | expired | closed | confirm | shipped | recorded | chosen | error.
	 * @return array
	 */
	private static function view( $state ) {
		return array(
			'state'  => (string) $state,
			'http'   => 'invalid' === $state ? 404 : ( 'error' === $state ? 500 : 200 ),
			'case'   => null,
			'facts'  => null,
			'row'    => null,
			'others' => array(),
			'token'  => '',
			'values' => array(),
		);
	}

	/**
	 * The little that is known about an order that can no longer be read.
	 *
	 * @param array $case The case.
	 * @return array
	 */
	private static function bare_facts( array $case ) {
		return array(
			'number'        => (string) $case['order_id'],
			'symbol'        => '$',
			'first_name'    => '',
			'customer_name' => (string) $case['customer_name'],
			'email'         => (string) $case['customer_email'],
			'ship_date'     => '',
			'delivery_date' => '',
		);
	}

	/**
	 * The page.
	 *
	 * @param array $view handle().
	 * @return string HTML.
	 */
	public static function render( array $view ) {
		$site  = Email::site_name();
		$state = (string) $view['state'];
		$row   = is_array( $view['row'] ) ? $view['row'] : null;
		$vals  = is_array( $view['values'] ) ? $view['values'] : array();

		$heading = __( 'About your order', 'fishotel-misc-plugin' );
		if ( ! empty( $vals['order_number'] ) ) {
			/* translators: %s: order number */
			$heading = sprintf( __( 'About your order #%s', 'fishotel-misc-plugin' ), $vals['order_number'] );
		}

		$body = '';

		if ( 'confirm' === $state && $row ) {
			$body .= '<p>' . esc_html( sprintf( /* translators: %s: 1 × fish */ __( 'We lost %s from your order while it was in our care, and we are sorry. You picked:', 'fishotel-misc-plugin' ), $vals['fish_line'] ?? '' ) ) . '</p>';
			$body .= self::choice_box( $row );
			if ( 'cancel' !== $row['choice'] && ! empty( $vals['ship_line'] ) ) {
				$body .= '<p class="muted">' . esc_html( $vals['ship_line'] ) . '</p>';
			}
			$body .= '<form method="post" action="' . esc_url( home_url( '/' ) ) . '">'
				. '<input type="hidden" name="' . esc_attr( Lost_Fish::QUERY_ARG ) . '" value="' . esc_attr( $view['token'] ) . '">'
				. '<input type="hidden" name="' . esc_attr( self::FIELD_CONFIRM ) . '" value="1">'
				. '<button type="submit" class="confirm">' . esc_html( $row['confirm'] ) . '</button>'
				. '</form>';
			$body .= '<p class="muted">' . esc_html__( 'Nothing changes until you press the button. You can pick only once.', 'fishotel-misc-plugin' ) . '</p>';
			$body .= self::others_list( $view['others'], __( 'Or pick something else', 'fishotel-misc-plugin' ) );
		} elseif ( 'shipped' === $state ) {
			$body .= '<p>' . esc_html__( 'Part of this order has shipped since we wrote to you, so the whole order can no longer be cancelled. Nothing was changed.', 'fishotel-misc-plugin' ) . '</p>';
			$body .= self::others_list( $view['others'], __( 'What you can still pick', 'fishotel-misc-plugin' ) );
			$body .= '<p class="muted">' . esc_html__( 'If you have a question, just reply to our e-mail.', 'fishotel-misc-plugin' ) . '</p>';
		} elseif ( ( 'recorded' === $state || 'chosen' === $state ) && $row ) {
			$body .= '<p class="ok">' . esc_html( 'recorded' === $state ? __( 'Thank you — your choice is recorded.', 'fishotel-misc-plugin' ) : __( 'A choice was already made for this fish:', 'fishotel-misc-plugin' ) ) . '</p>';
			$body .= self::choice_box( $row );
			$when  = is_array( $view['case'] ) ? Lost_Fish::site_time( $view['case']['answered_at'], 'F j, Y' ) : '';
			if ( '' !== $when ) {
				/* translators: %s: date */
				$body .= '<p class="muted">' . esc_html( sprintf( __( 'Chosen on %s.', 'fishotel-misc-plugin' ), $when ) ) . '</p>';
			}
			$body .= '<p>' . esc_html__( 'We will take care of it from here — there is nothing more for you to do. If you change your mind, reply to our e-mail.', 'fishotel-misc-plugin' ) . '</p>';
		} elseif ( 'expired' === $state ) {
			$body .= '<p>' . esc_html__( 'This link has expired. Reply to our e-mail and we will sort it out with you.', 'fishotel-misc-plugin' ) . '</p>';
		} elseif ( 'closed' === $state ) {
			$body .= '<p>' . esc_html__( 'This request is closed — there is nothing for you to do here. If we wrote to you again since, use the links in that newer e-mail; if you have a question, just reply to it.', 'fishotel-misc-plugin' ) . '</p>';
		} elseif ( 'error' === $state ) {
			$body .= '<p>' . esc_html__( 'Something went wrong on our side and your choice was NOT recorded. Please try the link again in a moment, or reply to our e-mail.', 'fishotel-misc-plugin' ) . '</p>';
		} else {
			$heading = __( 'This link is not valid', 'fishotel-misc-plugin' );
			$body   .= '<p>' . esc_html__( 'Please use the links in our most recent e-mail, or reply to it and we will help.', 'fishotel-misc-plugin' ) . '</p>';
		}

		return '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">'
			. '<meta name="viewport" content="width=device-width, initial-scale=1">'
			. '<meta name="robots" content="noindex, nofollow">'
			. '<title>' . esc_html( $heading . ( '' !== $site ? ' — ' . $site : '' ) ) . '</title>'
			. '<style>'
			. 'body{margin:0;background:#f3f4f6;color:#1d2327;font:16px/1.6 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif}'
			. '.card{max-width:560px;margin:32px auto;padding:24px;background:#fff;border:1px solid #e2e5e9;border-radius:10px}'
			. '@media(max-width:600px){.card{margin:0;border:0;border-radius:0;min-height:100vh;box-sizing:border-box}}'
			. '.site{margin:0 0 4px;font-size:13px;letter-spacing:.08em;text-transform:uppercase;color:#646970}'
			. 'h1{margin:0 0 16px;font-size:22px;line-height:1.3}h2{margin:28px 0 8px;font-size:15px;color:#50575e}'
			. '.choice{margin:16px 0;padding:14px 16px;border:1px solid #d5dae0;border-radius:8px;background:#fafbfc}'
			. '.choice strong{display:block;font-size:17px}.choice span{display:block;margin-top:6px;font-size:14px;color:#3c434a}'
			. '.confirm{display:block;width:100%;margin:8px 0;padding:14px 16px;border:0;border-radius:8px;background:#0b6bcb;color:#fff;font:inherit;font-weight:700;cursor:pointer}'
			. '.confirm:hover,.confirm:focus{background:#0958a8}'
			. '.muted{font-size:14px;color:#646970}.ok{font-weight:700;color:#1a7f37}'
			. '.others{margin:0;padding-left:20px}.others li{margin:6px 0}a{color:#0b6bcb}'
			. '</style></head><body><main class="card">'
			. ( '' !== $site ? '<p class="site">' . esc_html( $site ) . '</p>' : '' )
			. '<h1>' . esc_html( $heading ) . '</h1>'
			. $body
			. '</main></body></html>';
	}

	/**
	 * The other choices as a list of links.
	 *
	 * @param array[] $others  Email::choice_rows() rows.
	 * @param string  $heading The heading above them.
	 * @return string HTML ('' when there are none).
	 */
	private static function others_list( array $others, $heading ) {
		if ( ! $others ) {
			return '';
		}
		$html = '<h2>' . esc_html( $heading ) . '</h2><ul class="others">';
		foreach ( $others as $other ) {
			$label = $other['title'] . ( '' !== $other['money'] ? ' — ' . $other['money'] : '' );
			$html .= '<li>' . ( '' !== $other['url'] ? '<a href="' . esc_url( $other['url'] ) . '">' . esc_html( $label ) . '</a>' : esc_html( $label ) ) . '</li>';
		}
		return $html . '</ul>';
	}

	/**
	 * One choice as a box: its title with its money, and its sentence.
	 *
	 * @param array $row Email::choice_rows() row.
	 * @return string HTML.
	 */
	private static function choice_box( array $row ) {
		return '<div class="choice"><strong>' . esc_html( $row['title'] . ( '' !== $row['money'] ? ' — ' . $row['money'] : '' ) ) . '</strong>'
			. ( '' !== $row['text'] ? '<span>' . esc_html( $row['text'] ) . '</span>' : '' )
			. '</div>';
	}
}
