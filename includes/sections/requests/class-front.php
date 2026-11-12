<?php
/**
 * Requests front — everything a person who is not the shop can reach:
 *
 *   /?fh_request=<token>             the private request form of a link: a
 *                                    plain page with no theme around it that
 *                                    works logged out — name, e-mail, forum
 *                                    handle, then one or more fish (name with
 *                                    suggestions, a standard size, a quantity,
 *                                    a note). A nonce, a honeypot field and a
 *                                    rate limit per address and per e-mail.
 *   /?fh_request_pay=<token>         the pay link of an invoice (Fees).
 *   /?fh_requests=<token>            the requests of one e-mail address, with
 *                                    Cancel — for people without an account.
 *   /?fh_request_answer=<token>      "keep it" / "cancel it" of a "still want
 *                                    it?" e-mail: the link only opens a page
 *                                    with one button, so a mail scanner that
 *                                    follows links changes nothing.
 *   My Account → Requests            the same list for a site customer (an
 *                                    endpoint that exists only while the
 *                                    section is on).
 *   [fishotel_request_form]          the public form — prints nothing until
 *                                    "Open to the public" is switched on, and
 *                                    then asks for a login.
 *
 * None of the plain pages is linked from a menu or listed in a sitemap,
 * and each sends noindex. Nothing here runs a query unless one of those
 * addresses (or a My Account page) is being asked for.
 *
 * A customer only ever sees or cancels their own requests: by account
 * (the request's user id is theirs) or by personal link (the request's
 * e-mail address is the one the signed link names).
 *
 * @package FisHotel\Misc\Sections\Requests
 */

namespace FisHotel\Misc\Sections\Requests;

defined( 'ABSPATH' ) || exit;

/**
 * Class Front
 */
class Front {

	const NONCE_FORM    = 'fishotel_request_form_';
	const NONCE_PERSON  = 'fishotel_requests_person_';
	const NONCE_ANSWER  = 'fishotel_requests_answer_';
	const NONCE_ACCOUNT = 'fishotel_requests_account';
	const NONCE_PUBLIC  = 'fishotel_requests_public';
	const NONCE_FIELD   = '_fhrq';

	/**
	 * The honeypot: a field people never see and never fill. Its name is
	 * one no browser fills in by itself (a field called "website" or
	 * "url" can be), so a real person is never taken for a robot.
	 *
	 * @var string
	 */
	const HONEYPOT = 'fh_rq_topic';

	/**
	 * The prefix of the transient that counts one visitor address (the
	 * e-mail counter has another, so each has its own allowance).
	 *
	 * @var string
	 */
	const RATE_ADDRESS = 'fhrq_rla_';

	/**
	 * The AJAX action of the name suggestions.
	 *
	 * @var string
	 */
	const SUGGEST_ACTION = 'fishotel_requests_suggest';

	/**
	 * The transient (per user) that carries the public form's result
	 * across its redirect.
	 *
	 * @var string
	 */
	const PUBLIC_FLASH = 'fishotel_requests_public_';

	/**
	 * How many requests a "my requests" page lists.
	 *
	 * @var int
	 */
	const MY_LIMIT = 100;

	/**
	 * Whether the current customer has requests (null = not asked yet).
	 *
	 * @var array{all: int, open: int}|null
	 */
	private static $mine = null;

	/**
	 * Register hooks. template_redirect only looks at $_GET / $_POST; the
	 * My Account hooks only run on account pages.
	 */
	public function init() {
		add_action( 'template_redirect', array( $this, 'route' ), 1 );

		add_filter( 'woocommerce_get_query_vars', array( $this, 'account_query_var' ) );
		add_filter( 'woocommerce_account_menu_items', array( $this, 'account_menu' ), 30 );
		add_action( 'woocommerce_account_' . Urls::ACCOUNT_ENDPOINT . '_endpoint', array( $this, 'account_page' ) );
		add_filter( 'woocommerce_endpoint_' . Urls::ACCOUNT_ENDPOINT . '_title', array( $this, 'account_title' ) );
		add_action( 'woocommerce_account_dashboard', array( $this, 'account_card' ) );

		add_shortcode( 'fishotel_request_form', array( $this, 'shortcode' ) );

		add_action( 'wp_ajax_' . self::SUGGEST_ACTION, array( $this, 'ajax_suggest' ) );
		add_action( 'wp_ajax_nopriv_' . self::SUGGEST_ACTION, array( $this, 'ajax_suggest' ) );
	}

	/* ============================================================== */
	/*  Routing                                                        */
	/* ============================================================== */

	/**
	 * A value of the query string or the posted form as plain text.
	 *
	 * @param array  $source $_GET or $_POST.
	 * @param string $key    Key.
	 * @return string
	 */
	private static function param( array $source, $key ) {
		return isset( $source[ $key ] ) && is_scalar( $source[ $key ] ) ? trim( (string) wp_unslash( $source[ $key ] ) ) : '';
	}

	private static function is_post() {
		return isset( $_SERVER['REQUEST_METHOD'] ) && 'POST' === strtoupper( (string) $_SERVER['REQUEST_METHOD'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
	}

	/**
	 * Serve one of the section's addresses when it is asked for.
	 */
	public function route() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Missing -- only the presence of a key is read here; every handler checks its own nonce or signed token.
		if ( isset( $_GET[ Urls::FORM_VAR ] ) ) {
			$this->form_page( self::param( $_GET, Urls::FORM_VAR ) );
		} elseif ( isset( $_GET[ Urls::PAY_VAR ] ) ) {
			$this->pay_page( self::param( $_GET, Urls::PAY_VAR ) );
		} elseif ( isset( $_GET[ Urls::PERSON_VAR ] ) ) {
			$this->person_page( self::param( $_GET, Urls::PERSON_VAR ) );
		} elseif ( isset( $_GET[ Urls::ANSWER_VAR ] ) ) {
			$this->answer_page( self::param( $_GET, Urls::ANSWER_VAR ), self::param( $_GET, 'do' ) );
		} elseif ( self::is_post() && isset( $_POST['fh_rq_account'] ) ) {
			$this->account_post();
		} elseif ( self::is_post() && isset( $_POST['fh_rq_public'] ) ) {
			$this->public_post();
		}
		// phpcs:enable
	}

	/* ============================================================== */
	/*  The plain page                                                 */
	/* ============================================================== */

	/**
	 * Send one of the section's own pages: no theme, no menu, noindex,
	 * never cached — and stop.
	 *
	 * @param string $title   Page title.
	 * @param string $content The page's HTML (already escaped).
	 * @param int    $status  HTTP status.
	 * @param bool   $script  Whether the form script goes with it.
	 */
	private function send_page( $title, $content, $status = 200, $script = false ) {
		nocache_headers();
		status_header( (int) $status );
		header( 'Content-Type: text/html; charset=utf-8' );
		header( 'X-Robots-Tag: noindex, nofollow' );

		$fh_title   = (string) $title;
		$fh_content = (string) $content;
		$fh_css     = self::asset( 'css/requests-front.css' );
		$fh_js      = $script ? self::asset( 'js/requests-form.js' ) : '';
		$fh_config  = $script ? self::form_config() : null;

		include Requests::path() . 'views/front-page.php';
		exit;
	}

	/**
	 * One of the section's own files, to inline.
	 *
	 * @param string $file Path under the section folder.
	 * @return string
	 */
	private static function asset( $file ) {
		$path = Requests::path() . $file;
		return is_readable( $path ) ? (string) file_get_contents( $path ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- our own file, inlined so the page makes no further requests.
	}

	/**
	 * A page that only says something.
	 *
	 * @param string $title   Title.
	 * @param string $message What to say.
	 * @param int    $status  HTTP status.
	 * @param string $more    More HTML under the message (already escaped).
	 */
	private function message_page( $title, $message, $status = 200, $more = '' ) {
		$this->send_page( $title, '<div class="fh-rq-card"><h1>' . esc_html( $title ) . '</h1><p>' . esc_html( $message ) . '</p>' . $more . '</div>', $status );
	}

	/**
	 * What the form script needs.
	 *
	 * @return array
	 */
	private static function form_config() {
		return array(
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'action'  => self::SUGGEST_ACTION,
			'maxRows' => Rules::MAX_ROWS,
		);
	}

	/* ============================================================== */
	/*  The private form                                               */
	/* ============================================================== */

	/**
	 * The standard sizes when the Clean Room has them (null = free text).
	 *
	 * @return array[]|null
	 */
	private static function sizes() {
		$cleanroom = Cleanroom::make();
		return $cleanroom ? $cleanroom->sizes() : null;
	}

	/**
	 * The logged-in visitor's details, to prefill the form.
	 *
	 * @return array{name: string, email: string, handle: string}
	 */
	private static function prefill() {
		$user = get_current_user_id() > 0 ? Requests::user( get_current_user_id() ) : null;
		return array(
			'name'   => $user ? $user['name'] : '',
			'email'  => $user ? $user['email'] : '',
			'handle' => $user ? $user['handle'] : '',
		);
	}

	/**
	 * The account a sheet from the private form belongs to: the logged-in
	 * visitor's — but only when the e-mail address typed is that account's.
	 * Somebody who sends the form for another address (a friend's, a second
	 * one of their own) is not made the owner of a request whose invoice
	 * goes elsewhere: it joins the account of its address when approved.
	 *
	 * @param int    $user_id The logged-in visitor (0 = nobody).
	 * @param string $account That account's e-mail address.
	 * @param string $typed   The e-mail address typed in the form.
	 * @return int
	 */
	public static function sender_id( $user_id, $account, $typed ) {
		$typed = Rules::email( $typed );
		return (int) $user_id > 0 && '' !== $typed && Rules::email( $account ) === $typed ? (int) $user_id : 0;
	}

	/**
	 * The visitor's address (never a forwarded header: those can be typed).
	 * A site behind a proxy or a CDN sees the proxy here for every visitor;
	 * it can name the header it trusts through the
	 * fishotel_requests_client_address filter (the limit per e-mail
	 * address works either way).
	 *
	 * @return string
	 */
	private static function address() {
		$address = isset( $_SERVER['REMOTE_ADDR'] ) && is_string( $_SERVER['REMOTE_ADDR'] ) ? $_SERVER['REMOTE_ADDR'] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		return self::clean_address( apply_filters( 'fishotel_requests_client_address', $address ) );
	}

	/**
	 * An address as it is counted: digits, hex letters, dots and colons.
	 *
	 * @param mixed $address Raw.
	 * @return string
	 */
	public static function clean_address( $address ) {
		return is_string( $address ) ? substr( (string) preg_replace( '/[^0-9a-fA-F:.]/', '', $address ), 0, 45 ) : '';
	}

	/**
	 * The transient keys a submission is counted under: one per address,
	 * one per e-mail address.
	 *
	 * @param string $address The visitor's address.
	 * @param string $email   The e-mail address typed.
	 * @return string[]
	 */
	public static function rate_keys( $address, $email ) {
		$keys = array();
		if ( '' !== (string) $address ) {
			$keys[] = self::RATE_ADDRESS . md5( 'a|' . $address );
		}
		$email = Rules::email( $email );
		if ( '' !== $email ) {
			$keys[] = 'fhrq_rl_' . md5( 'e|' . $email );
		}
		return $keys;
	}

	/**
	 * Whether one of the keys has used up its submissions: $limit per
	 * e-mail address, Rules::RATE_ADDRESS_FACTOR times that per visitor
	 * address (several people may share one).
	 *
	 * @param string[] $keys  rate_keys().
	 * @param int      $limit Submissions allowed per e-mail address per window.
	 * @return bool
	 */
	public static function rate_limited( array $keys, $limit = Rules::RATE_LIMIT ) {
		if ( function_exists( 'apply_filters' ) ) {
			$limit = (int) apply_filters( 'fishotel_requests_rate_limit', $limit );
		}
		$limit = max( 1, (int) $limit );
		foreach ( $keys as $key ) {
			$allowed = 0 === strpos( (string) $key, self::RATE_ADDRESS ) ? $limit * Rules::RATE_ADDRESS_FACTOR : $limit;
			if ( (int) get_transient( $key ) >= $allowed ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Count one submission under every key.
	 *
	 * @param string[] $keys   rate_keys().
	 * @param int      $window Seconds.
	 */
	public static function rate_hit( array $keys, $window = Rules::RATE_WINDOW ) {
		foreach ( $keys as $key ) {
			set_transient( $key, (int) get_transient( $key ) + 1, (int) $window );
		}
	}

	/**
	 * What a posted sheet says (unslashed; Rules cleans it).
	 *
	 * @return array{person: array, rows: array}
	 */
	private static function posted_sheet() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput -- the caller verified the nonce; Rules::person() / fish_row() clean every value.
		$rows = isset( $_POST['rows'] ) && is_array( $_POST['rows'] ) ? wp_unslash( $_POST['rows'] ) : array();
		$out  = array(
			'person' => array(
				'name'   => self::param( $_POST, 'name' ),
				'email'  => self::param( $_POST, 'email' ),
				'handle' => self::param( $_POST, 'handle' ),
			),
			'rows'   => is_array( $rows ) ? $rows : array(),
		);
		// phpcs:enable
		return $out;
	}

	/**
	 * The private request form of a link.
	 *
	 * @param string $token The link's token.
	 */
	private function form_page( $token ) {
		$title   = __( 'Request a fish', 'fishotel-misc-plugin' );
		$store   = Requests::service()->store;
		$link    = $store->link_by_token( $token );
		$problem = Rules::link_problem( $link, time() );
		if ( '' !== $problem ) {
			$this->message_page( $title, Rules::link_problem_words( $problem ), 'missing' === $problem ? 404 : 410 );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a read-only "thank you" flag after the redirect.
		$sent = isset( $_GET['sent'] ) ? absint( wp_unslash( $_GET['sent'] ) ) : 0;
		if ( $sent > 0 ) {
			$this->thanks_page( $sent );
		}

		$sizes  = self::sizes();
		$person = self::prefill();
		$rows   = array();
		$errors = array();

		if ( self::is_post() ) {
			$sheet  = self::posted_sheet();
			$person = $sheet['person'];
			$rows   = $sheet['rows'];
			$nonce  = self::param( $_POST, self::NONCE_FIELD ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified on the next line.

			if ( ! wp_verify_nonce( $nonce, self::NONCE_FORM . $token ) ) {
				$errors[] = __( 'This page was open for a long time. Please send it once more.', 'fishotel-misc-plugin' );
			} elseif ( '' !== self::param( $_POST, self::HONEYPOT ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
				// A filled honeypot is a robot: it is told "thank you" and nothing is kept.
				wp_safe_redirect( add_query_arg( 'sent', 1, Urls::form( $token ) ) );
				exit;
			} else {
				$keys = self::rate_keys( self::address(), $person['email'] );
				if ( self::rate_limited( $keys ) ) {
					$errors[] = __( 'That is a lot of requests in one hour. Please try again later, or send us an e-mail.', 'fishotel-misc-plugin' );
				} else {
					$result = Requests::service()->submit(
						$person,
						$rows,
						array(
							'source'  => 'link',
							'link'    => $link,
							'user_id' => self::sender_id( get_current_user_id(), self::prefill()['email'], $person['email'] ),
							'fee'     => (float) $link['fee_amount'],
							'sizes'   => $sizes,
						)
					);
					if ( $result['ok'] ) {
						self::rate_hit( $keys );
						wp_safe_redirect( add_query_arg( 'sent', count( $result['ids'] ), Urls::form( $token ) ) );
						exit;
					}
					$errors = $result['errors'];
				}
			}
		}

		ob_start();
		$fh_form = array(
			'action'  => Urls::form( $token ),
			'nonce'   => wp_create_nonce( self::NONCE_FORM . $token ),
			'hidden'  => array(),
			'person'  => $person,
			'rows'    => Rules::form_rows( $rows ),
			'sizes'   => $sizes,
			'fee'     => (float) $link['fee_amount'],
			'errors'  => $errors,
			'locked'  => false,
			'token'   => $token,
			'heading' => $title,
		);
		include Requests::path() . 'views/front-form.php';
		$this->send_page( $title, (string) ob_get_clean(), 200, true );
	}

	/**
	 * "Thank you" after a sheet was sent.
	 *
	 * @param int $count How many fish were on it.
	 */
	private function thanks_page( $count ) {
		$more = '';
		if ( get_current_user_id() > 0 && '' !== Urls::account() ) {
			$more = '<p><a class="fh-rq-button" href="' . esc_url( Urls::account() ) . '">' . esc_html__( 'See my requests', 'fishotel-misc-plugin' ) . '</a></p>';
		}
		$this->message_page(
			__( 'Thank you', 'fishotel-misc-plugin' ),
			/* translators: %d: fish */
			sprintf( _n( 'We have your request for %d fish. We will look at it and e-mail you — nothing to do for now.', 'We have your requests for %d fish. We will look at them and e-mail you — nothing to do for now.', (int) $count, 'fishotel-misc-plugin' ), (int) $count ),
			200,
			$more
		);
	}

	/* ============================================================== */
	/*  The pay link                                                   */
	/* ============================================================== */

	/**
	 * The pay link of an invoice: the fee goes into the cart, and on to
	 * the checkout.
	 *
	 * @param string $token The signed token.
	 */
	private function pay_page( $token ) {
		$title    = __( 'Request fee', 'fishotel-misc-plugin' );
		$decision = Fees::decide( $token, get_current_user_id(), Requests::service(), Fees::status() );

		if ( 'login' === $decision['do'] ) {
			$here = home_url( '/?' . Urls::PAY_VAR . '=' . rawurlencode( $token ) );
			$more = '<p><a class="fh-rq-button" href="' . esc_url( wp_login_url( $here ) ) . '">' . esc_html__( 'Log in', 'fishotel-misc-plugin' ) . '</a></p>';
			if ( function_exists( 'wc_get_page_permalink' ) ) {
				$more .= '<p class="fh-rq-small">' . esc_html__( 'No account yet?', 'fishotel-misc-plugin' ) . ' <a href="' . esc_url( wc_get_page_permalink( 'myaccount' ) ) . '">' . esc_html__( 'Create one here', 'fishotel-misc-plugin' ) . '</a>' . esc_html__( ', then open the link in our e-mail again.', 'fishotel-misc-plugin' ) . '</p>';
			}
			$this->message_page( $title, $decision['message'], 200, $more );
		}

		if ( 'cart' !== $decision['do'] ) {
			$this->message_page( $title, $decision['message'], 200 );
		}

		$cart = function_exists( 'WC' ) && is_object( WC() ) ? WC()->cart : null;
		if ( ! Fees::add_to_cart( $cart, $decision['carrier'], $decision['amount'], $decision['product_id'] ) ) {
			$this->message_page( $title, __( 'The fee could not be put in your cart. Please try the link again in a moment, or contact us.', 'fishotel-misc-plugin' ), 200 );
		}

		nocache_headers();
		wp_safe_redirect( wc_get_checkout_url() );
		exit;
	}

	/* ============================================================== */
	/*  My requests: the personal link                                 */
	/* ============================================================== */

	/**
	 * The requests of one e-mail address — for people without an account.
	 *
	 * @param string $token The signed token.
	 */
	private function person_page( $token ) {
		$title = __( 'My fish requests', 'fishotel-misc-plugin' );
		$email = Tokens::read_person( $token );
		if ( '' === $email ) {
			$this->message_page( $title, __( 'This link is not valid. Please use the link in our e-mail exactly as it is.', 'fishotel-misc-plugin' ), 404 );
		}

		$here  = home_url( '/?' . Urls::PERSON_VAR . '=' . rawurlencode( $token ) );
		$store = Requests::service()->store;
		$flash = '';

		if ( self::is_post() ) {
			$id      = isset( $_POST['cancel'] ) ? absint( wp_unslash( $_POST['cancel'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified below.
			$request = $id > 0 ? $store->get( $id ) : null;
			$nonce   = self::param( $_POST, self::NONCE_FIELD ); // phpcs:ignore WordPress.Security.NonceVerification.Missing

			if ( ! wp_verify_nonce( $nonce, self::NONCE_PERSON . md5( $email ) ) ) {
				$flash = __( 'This page was open for a long time. Please try once more.', 'fishotel-misc-plugin' );
			} elseif ( ! $request || ! Rules::owned_by_email( $request, $email ) ) {
				// Somebody else's request (or none): the same answer as "not there".
				$flash = __( 'That request is not on your list.', 'fishotel-misc-plugin' );
			} else {
				$result = Requests::service()->cancel( $id, Service::customer(), '', true );
				wp_safe_redirect( add_query_arg( 'done', $result['ok'] ? 'cancelled' : 'no', $here ) );
				exit;
			}
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a read-only flag after the redirect.
		$done = self::param( $_GET, 'done' );
		if ( 'cancelled' === $done ) {
			$flash = __( 'The request is cancelled.', 'fishotel-misc-plugin' );
		} elseif ( 'no' === $done ) {
			$flash = __( 'That request could not be cancelled here. Please contact us.', 'fishotel-misc-plugin' );
		}

		$requests = $store->find(
			array(
				'email'   => $email,
				'orderby' => 'id',
				'order'   => 'DESC',
				'limit'   => self::MY_LIMIT,
			)
		);

		ob_start();
		$fh_list = array(
			'heading'  => $title,
			'intro'    => $email,
			'requests' => self::my_rows( $requests, time() ),
			'flash'    => $flash,
			'action'   => $here,
			'nonce'    => wp_create_nonce( self::NONCE_PERSON . md5( $email ) ),
			'hidden'   => array(),
			'plain'    => true,
		);
		include Requests::path() . 'views/front-list.php';
		$this->send_page( $title, (string) ob_get_clean() );
	}

	/**
	 * The rows of a "my requests" page (pure): the open requests, then
	 * the ones closed in the last 90 days, each in plain words.
	 *
	 * @param array[] $requests The person's requests.
	 * @param int     $now      UNIX time.
	 * @return array[] id, fish, state, words, asked, can_cancel, owed, open.
	 */
	public static function my_rows( array $requests, $now ) {
		$open   = array();
		$closed = array();
		foreach ( $requests as $request ) {
			$is_open = Rules::is_open( (string) ( $request['state'] ?? '' ) );
			$created = Rules::ts( $request['created_at'] ?? '' );
			$row     = array(
				'id'         => (int) $request['id'],
				'fish'       => Rules::fish_line( $request ),
				'state'      => (string) $request['state'],
				'words'      => Rules::state_words( $request ),
				'asked'      => (int) $now - $created < 86400
					? __( 'asked today', 'fishotel-misc-plugin' )
					/* translators: %s: how long ago, e.g. "3 weeks" */
					: sprintf( __( 'asked %s ago', 'fishotel-misc-plugin' ), Rules::age_words( $created, (int) $now ) ),
				'can_cancel' => Rules::can_customer_cancel( $request ),
				'owed'       => ! empty( $request['owed'] ),
				'open'       => $is_open,
			);
			if ( $is_open ) {
				$open[] = $row;
			} elseif ( (int) $now - Rules::ts( $request['closed_at'] ?? ( $request['updated_at'] ?? '' ) ) <= 90 * 86400 ) {
				$closed[] = $row;
			}
		}
		return array_merge( $open, $closed );
	}

	/* ============================================================== */
	/*  "Still want it?" — keep / cancel                               */
	/* ============================================================== */

	/**
	 * The page behind a "keep it" / "cancel it" link. Opening it changes
	 * nothing: it shows one button, and only the button (a POST) acts.
	 *
	 * @param string $token The signed token.
	 * @param string $do    keep|cancel.
	 */
	private function answer_page( $token, $do ) {
		$do      = 'cancel' === $do ? 'cancel' : 'keep';
		$title   = 'cancel' === $do ? __( 'Cancel my request', 'fishotel-misc-plugin' ) : __( 'Keep my request', 'fishotel-misc-plugin' );
		$store   = Requests::service()->store;
		$id      = Tokens::answer_id( $token );
		$request = $id > 0 ? $store->get( $id ) : null;

		if ( ! $request || ! Tokens::check_answer( $token, $request ) ) {
			$this->message_page( $title, __( 'This link is not valid. Please use the link in our e-mail exactly as it is.', 'fishotel-misc-plugin' ), 404 );
		}
		if ( ! Rules::is_open( $request['state'] ) ) {
			$this->message_page( $title, __( 'This request is already closed. You are welcome to ask again any time.', 'fishotel-misc-plugin' ) );
		}

		$fish = Rules::fish_line( $request );

		if ( self::is_post() ) {
			$nonce = self::param( $_POST, self::NONCE_FIELD ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified on the next line.
			if ( ! wp_verify_nonce( $nonce, self::NONCE_ANSWER . $id ) ) {
				$this->message_page( $title, __( 'This page was open for a long time. Please open the link in our e-mail once more.', 'fishotel-misc-plugin' ) );
			}
			$service = Requests::service();
			$result  = 'cancel' === $do ? $service->cancel( $id, Service::customer(), __( 'Answer to "still want it?".', 'fishotel-misc-plugin' ), true ) : $service->keep( $id, Service::customer() );
			$said    = $result['message'];
			if ( $result['ok'] && 'cancel' === $do ) {
				$said = __( 'Your request is cancelled. You are welcome to ask again any time.', 'fishotel-misc-plugin' );
			}
			$this->message_page( $title, $said . ' (' . $fish . ')' );
		}

		$question = 'cancel' === $do
			/* translators: %s: the fish */
			? sprintf( __( 'Cancel your request for %s?', 'fishotel-misc-plugin' ), $fish )
			/* translators: %s: the fish */
			: sprintf( __( 'Keep your request for %s on our list?', 'fishotel-misc-plugin' ), $fish );
		$button = 'cancel' === $do ? __( 'Yes, cancel it', 'fishotel-misc-plugin' ) : __( 'Yes, keep it', 'fishotel-misc-plugin' );
		$here   = home_url( '/?' . Urls::ANSWER_VAR . '=' . rawurlencode( $token ) . '&do=' . $do );

		$form = '<form method="post" action="' . esc_url( $here ) . '">'
			. '<input type="hidden" name="' . esc_attr( self::NONCE_FIELD ) . '" value="' . esc_attr( wp_create_nonce( self::NONCE_ANSWER . $id ) ) . '">'
			. '<p><button type="submit" class="fh-rq-button' . ( 'cancel' === $do ? ' fh-rq-button--quiet' : '' ) . '">' . esc_html( $button ) . '</button></p>'
			. '</form>';

		$this->message_page( $title, $question, 200, $form );
	}

	/* ============================================================== */
	/*  My Account → Requests                                          */
	/* ============================================================== */

	/**
	 * Tell WooCommerce about the endpoint (it registers the rewrite rule
	 * and reads the query var itself).
	 *
	 * @param array $vars WooCommerce's query vars.
	 * @return array
	 */
	public function account_query_var( $vars ) {
		if ( is_array( $vars ) ) {
			$vars[ Urls::ACCOUNT_ENDPOINT ] = Urls::ACCOUNT_ENDPOINT;
		}
		return $vars;
	}

	/**
	 * How many requests the logged-in customer has (one query, once, and
	 * only on a My Account page).
	 *
	 * @return array{all: int, open: int}
	 */
	private static function mine() {
		if ( null !== self::$mine ) {
			return self::$mine;
		}
		self::$mine = array(
			'all'  => 0,
			'open' => 0,
		);
		$user_id = get_current_user_id();
		if ( $user_id > 0 ) {
			$store      = Requests::service()->store;
			$requests   = $store->find(
				array(
					'user_id' => $user_id,
					'limit'   => self::MY_LIMIT,
				)
			);
			self::$mine = array(
				'all'  => count( $requests ),
				'open' => count(
					array_filter(
						$requests,
						static function ( $request ) {
							return Rules::is_open( $request['state'] );
						}
					)
				),
			);
		}
		return self::$mine;
	}

	/**
	 * The My Account menu: "Requests" — only for a customer who has some
	 * (or once the form is open to the public), so the tab gives nothing
	 * away while requests are private.
	 *
	 * @param array $items Menu items.
	 * @return array
	 */
	public function account_menu( $items ) {
		if ( ! is_array( $items ) ) {
			return $items;
		}
		$settings = Settings::get();
		if ( empty( $settings['public_open'] ) && self::mine()['all'] <= 0 ) {
			return $items;
		}

		$out = array();
		foreach ( $items as $key => $label ) {
			if ( 'customer-logout' === $key ) {
				$out[ Urls::ACCOUNT_ENDPOINT ] = __( 'Requests', 'fishotel-misc-plugin' );
			}
			$out[ $key ] = $label;
		}
		if ( ! isset( $out[ Urls::ACCOUNT_ENDPOINT ] ) ) {
			$out[ Urls::ACCOUNT_ENDPOINT ] = __( 'Requests', 'fishotel-misc-plugin' );
		}
		return $out;
	}

	/**
	 * The endpoint's page title.
	 *
	 * @return string
	 */
	public function account_title() {
		return __( 'Requests', 'fishotel-misc-plugin' );
	}

	/**
	 * The account's first page: a line that leads to the requests (a
	 * theme that draws its own account menu may not list the tab).
	 */
	public function account_card() {
		$mine = self::mine();
		if ( $mine['all'] <= 0 || '' === Urls::account() ) {
			return;
		}
		echo '<p class="fh-rq-account-card">';
		if ( $mine['open'] > 0 ) {
			/* translators: %d: requests */
			echo esc_html( sprintf( _n( 'You have %d fish request with us.', 'You have %d fish requests with us.', $mine['open'], 'fishotel-misc-plugin' ), $mine['open'] ) );
		} else {
			esc_html_e( 'Your fish requests are all closed.', 'fishotel-misc-plugin' );
		}
		echo ' <a href="' . esc_url( Urls::account() ) . '">' . esc_html__( 'See my requests', 'fishotel-misc-plugin' ) . '</a></p>';
	}

	/**
	 * My Account → Requests.
	 */
	public function account_page() {
		$user_id = get_current_user_id();
		if ( $user_id <= 0 ) {
			return;
		}

		$store    = Requests::service()->store;
		$requests = $store->find(
			array(
				'user_id' => $user_id,
				'orderby' => 'id',
				'order'   => 'DESC',
				'limit'   => self::MY_LIMIT,
			)
		);

		$fh_list = array(
			'heading'  => '',
			'intro'    => '',
			'requests' => self::my_rows( $requests, time() ),
			'flash'    => '',
			'action'   => Urls::account(),
			'nonce'    => wp_create_nonce( self::NONCE_ACCOUNT ),
			'hidden'   => array( 'fh_rq_account' => '1' ),
			'plain'    => false,
		);
		// The theme styles the account page; this only lays the rows out.
		echo '<style>.fh-rq-list--account .fh-rq-mine{list-style:none;margin:0;padding:0}.fh-rq-list--account .fh-rq-mine__item{display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:10px;padding:12px 0;border-top:1px solid rgba(128,128,128,.3)}.fh-rq-list--account .fh-rq-mine__what{display:flex;flex-direction:column;gap:2px}.fh-rq-list--account .fh-rq-mine__item.is-closed{opacity:.6}.fh-rq-list--account .fh-rq-small{font-size:.85em;opacity:.75}.fh-rq-list--account form{margin:0}</style>';
		include Requests::path() . 'views/front-list.php';
	}

	/**
	 * Cancel from My Account → Requests: the request must be the logged-in
	 * customer's own.
	 */
	private function account_post() {
		$user_id = get_current_user_id();
		$back    = '' !== Urls::account() ? Urls::account() : home_url( '/' );
		$nonce   = self::param( $_POST, self::NONCE_FIELD ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified on the next line.
		if ( $user_id <= 0 || ! wp_verify_nonce( $nonce, self::NONCE_ACCOUNT ) ) {
			return;
		}

		$id      = isset( $_POST['cancel'] ) ? absint( wp_unslash( $_POST['cancel'] ) ) : 0;
		$store   = Requests::service()->store;
		$request = $id > 0 ? $store->get( $id ) : null;

		if ( ! $request || ! Rules::owned_by_user( $request, $user_id ) ) {
			$message = __( 'That request is not on your list.', 'fishotel-misc-plugin' );
			$type    = 'error';
		} else {
			$result  = Requests::service()->cancel( $id, Service::customer( $user_id ), '', true );
			$message = $result['ok'] ? __( 'The request is cancelled.', 'fishotel-misc-plugin' ) : $result['message'];
			$type    = $result['ok'] ? 'success' : 'error';
		}

		if ( function_exists( 'wc_add_notice' ) ) {
			wc_add_notice( $message, $type );
		}
		wp_safe_redirect( $back );
		exit;
	}

	/* ============================================================== */
	/*  Later, public: [fishotel_request_form]                         */
	/* ============================================================== */

	/**
	 * The public form. Prints nothing while "Open to the public" is off,
	 * asks for a login, and stops at the cap of open requests.
	 *
	 * @return string
	 */
	public function shortcode() {
		$settings = Settings::get();
		if ( empty( $settings['public_open'] ) ) {
			return '';
		}

		$user_id = get_current_user_id();
		if ( $user_id <= 0 ) {
			$here = function_exists( 'get_permalink' ) ? (string) get_permalink() : home_url( '/' );
			return '<div class="fh-rq-embed"><p>' . esc_html__( 'Please log in to request a fish.', 'fishotel-misc-plugin' ) . ' <a href="' . esc_url( wp_login_url( $here ) ) . '">' . esc_html__( 'Log in', 'fishotel-misc-plugin' ) . '</a></p></div>';
		}

		$flash = get_transient( self::PUBLIC_FLASH . $user_id );
		if ( false !== $flash ) {
			delete_transient( self::PUBLIC_FLASH . $user_id );
		}
		$flash = is_array( $flash ) ? $flash : array();

		$out = '<div class="fh-rq-embed"><style>' . self::asset( 'css/requests-front.css' ) . '</style>';
		if ( ! empty( $flash['sent'] ) ) {
			/* translators: %d: fish */
			$out .= '<p class="fh-rq-flash">' . esc_html( sprintf( _n( 'Thank you — we have your request for %d fish. We will look at it and e-mail you.', 'Thank you — we have your requests for %d fish. We will look at them and e-mail you.', (int) $flash['sent'], 'fishotel-misc-plugin' ), (int) $flash['sent'] ) ) . '</p>';
		}

		$store = Requests::service()->store;
		$open  = $store->count(
			array(
				'user_id' => $user_id,
				'state'   => Rules::OPEN_STATES,
			)
		);
		$cap   = max( 1, (int) $settings['public_cap'] );
		if ( $open >= $cap ) {
			/* translators: %d: requests */
			$out .= '<p>' . esc_html( sprintf( __( 'You have %d open requests with us — the most we take at one time.', 'fishotel-misc-plugin' ), $open ) );
			if ( '' !== Urls::account() ) {
				$out .= ' <a href="' . esc_url( Urls::account() ) . '">' . esc_html__( 'See my requests', 'fishotel-misc-plugin' ) . '</a>';
			}
			return $out . '</p></div>';
		}

		$person = self::prefill();
		if ( ! empty( $flash['person']['handle'] ) ) {
			$person['handle'] = (string) $flash['person']['handle'];
		}

		ob_start();
		$fh_form = array(
			'action'  => function_exists( 'get_permalink' ) ? (string) get_permalink() : home_url( '/' ),
			'nonce'   => wp_create_nonce( self::NONCE_PUBLIC ),
			'hidden'  => array( 'fh_rq_public' => '1' ),
			'person'  => $person,
			'rows'    => isset( $flash['rows'] ) && is_array( $flash['rows'] ) ? Rules::form_rows( $flash['rows'] ) : array(),
			'sizes'   => self::sizes(),
			'fee'     => (float) $settings['default_fee'],
			'errors'  => isset( $flash['errors'] ) && is_array( $flash['errors'] ) ? $flash['errors'] : array(),
			'locked'  => true,
			'token'   => '',
			'heading' => '',
		);
		include Requests::path() . 'views/front-form.php';
		$out .= (string) ob_get_clean();

		$out .= '<script>window.fhRequestsForm=' . wp_json_encode( self::form_config() ) . ';</script><script>' . self::asset( 'js/requests-form.js' ) . '</script></div>';

		return $out;
	}

	/**
	 * The public form was sent.
	 */
	private function public_post() {
		$user_id  = get_current_user_id();
		$settings = Settings::get();
		$nonce    = self::param( $_POST, self::NONCE_FIELD ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified on the next line.
		if ( $user_id <= 0 || empty( $settings['public_open'] ) || ! wp_verify_nonce( $nonce, self::NONCE_PUBLIC ) ) {
			return;
		}

		$back  = wp_get_referer();
		$back  = $back ? $back : home_url( '/' );
		$sheet = self::posted_sheet();
		$user  = Requests::user( $user_id );
		$flash = array(
			'rows'   => $sheet['rows'],
			'person' => array( 'handle' => Rules::text( $sheet['person']['handle'], Rules::MAX_HANDLE ) ),
			'errors' => array(),
		);

		// The account is who asks: only the handle is taken from the form.
		$person = array(
			'name'   => $user ? $user['name'] : '',
			'email'  => $user ? $user['email'] : '',
			'handle' => $flash['person']['handle'],
		);

		if ( '' !== self::param( $_POST, self::HONEYPOT ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$flash = array( 'sent' => 1 );
		} else {
			$store = Requests::service()->store;
			$open  = $store->count(
				array(
					'user_id' => $user_id,
					'state'   => Rules::OPEN_STATES,
				)
			);
			$cap   = max( 1, (int) $settings['public_cap'] );
			$keys  = self::rate_keys( self::address(), $person['email'] );
			$count = count( Rules::form_rows( $sheet['rows'] ) );

			if ( $open + $count > $cap ) {
				/* translators: 1: open requests, 2: the cap */
				$flash['errors'][] = sprintf( __( 'You have %1$d open requests, and we take %2$d at one time — please send fewer fish, or wait for one to be done.', 'fishotel-misc-plugin' ), $open, $cap );
			} elseif ( self::rate_limited( $keys ) ) {
				$flash['errors'][] = __( 'That is a lot of requests in one hour. Please try again later.', 'fishotel-misc-plugin' );
			} else {
				$result = Requests::service()->submit(
					$person,
					$sheet['rows'],
					array(
						'source'  => 'public',
						'user_id' => $user_id,
						'fee'     => (float) $settings['default_fee'],
						'sizes'   => self::sizes(),
					)
				);
				if ( $result['ok'] ) {
					self::rate_hit( $keys );
					$flash = array( 'sent' => count( $result['ids'] ) );
				} else {
					$flash['errors'] = $result['errors'];
				}
			}
		}

		set_transient( self::PUBLIC_FLASH . $user_id, $flash, 5 * MINUTE_IN_SECONDS );
		wp_safe_redirect( $back );
		exit;
	}

	/* ============================================================== */
	/*  Name suggestions                                               */
	/* ============================================================== */

	/**
	 * Names to suggest while a fish is typed: for the shop on its own
	 * screens, for the holder of a private link that still works, and —
	 * once the form is public — for a logged-in customer. Anybody else
	 * gets nothing. Somebody who is not the shop is only shown species
	 * of the list and products that are published.
	 */
	public function ajax_suggest() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- a read-only lookup; access is decided below.
		$term  = Rules::text( isset( $_REQUEST['term'] ) ? wp_unslash( $_REQUEST['term'] ) : '', 60 ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$token = self::param( $_REQUEST, 'token' );
		// phpcs:enable

		$staff   = current_user_can( Requests::CAPABILITY );
		$allowed = $staff;
		if ( ! $allowed && Tokens::is_link( $token ) ) {
			$store   = Requests::service()->store;
			$allowed = '' === Rules::link_problem( $store->link_by_token( $token ), time() );
		}
		if ( ! $allowed && get_current_user_id() > 0 ) {
			$settings = Settings::get();
			$allowed  = ! empty( $settings['public_open'] );
		}
		if ( ! $allowed ) {
			wp_send_json_error( array( 'message' => 'Not allowed.' ), 403 );
		}

		wp_send_json_success( array( 'items' => strlen( $term ) < 2 ? array() : self::suggestions( $term, $staff ) ) );
	}

	/**
	 * Names for what was typed.
	 *
	 * @param string $term  What was typed.
	 * @param bool   $staff Whether the shop is asking (drafts are then named too).
	 * @return array[] name, sci.
	 */
	public static function suggestions( $term, $staff ) {
		$cleanroom = Cleanroom::make();
		if ( $cleanroom ) {
			$is_public = $staff ? null : static function ( $product_id ) {
				return 'publish' === get_post_status( (int) $product_id );
			};
			return $cleanroom->suggest( $term, 8, $is_public );
		}

		// Without the Clean Room: the shop's products by title.
		if ( ! function_exists( 'get_posts' ) || ! post_type_exists( 'product' ) ) {
			return array();
		}
		$posts = get_posts(
			array(
				'post_type'        => 'product',
				'post_status'      => $staff ? array( 'publish', 'private', 'draft' ) : 'publish',
				's'                => $term,
				'posts_per_page'   => 8,
				'orderby'          => 'title',
				'order'            => 'ASC',
				'no_found_rows'    => true,
				'suppress_filters' => true,
			)
		);
		$out   = array();
		foreach ( is_array( $posts ) ? $posts : array() as $post ) {
			$out[] = array(
				'name' => html_entity_decode( (string) $post->post_title, ENT_QUOTES, 'UTF-8' ),
				'sci'  => '',
			);
		}
		return $out;
	}
}
