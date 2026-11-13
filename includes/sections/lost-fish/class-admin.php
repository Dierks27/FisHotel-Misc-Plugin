<?php
/**
 * Lost Fish admin — FisHotel Tools → Lost Fish: the Cases screen (Needs
 * you / Waiting for the customer / Finished), the form that opens a case
 * by hand from an order line, the Log and the Settings; and one small
 * link under every line of the WooCommerce order screen.
 *
 * Every write comes in through a nonce'd admin-post handler and needs
 * manage_woocommerce: one handler for everything done to a case
 * (case_request(): send, numbers, answer by hand, add to Requests, done,
 * reopen, close, give to another customer), one that opens a case by hand
 * (open_request()) and one for the settings (settings_request()). Each
 * *_request() checks the capability and the nonce itself and answers with
 * a status, so the tests call them directly; the handlers only keep the
 * message for the next page and redirect.
 *
 * "Send to customer" is the ONE button that sends an e-mail. Its form
 * carries the refund the page showed, and Cases::send() refuses when the
 * number is no longer that.
 *
 * @package FisHotel\Misc\Sections\Lost_Fish
 */

namespace FisHotel\Misc\Sections\Lost_Fish;

defined( 'ABSPATH' ) || exit;

/**
 * Class Admin
 */
class Admin {

	const ACTION_CASE     = 'fishotel_lostfish_case';
	const ACTION_OPEN     = 'fishotel_lostfish_open';
	const ACTION_SETTINGS = 'fishotel_lostfish_settings';

	/**
	 * Nonce actions: per case (every operation on it), opening by hand,
	 * the settings.
	 */
	const NONCE_CASE     = 'fishotel_lostfish_case_';
	const NONCE_OPEN     = 'fishotel_lostfish_open';
	const NONCE_SETTINGS = 'fishotel_lostfish_settings';

	/**
	 * The per-user notice carried across the redirect.
	 *
	 * @var string
	 */
	const FLASH = 'fishotel_lostfish_flash_';

	/**
	 * The operations that act on what the page showed of a case, and so
	 * carry its stamp (Cases::stamp()) in the "seen" field.
	 *
	 * @var string[]
	 */
	const SEEN_OPS = array( 'send', 'numbers', 'answer', 'reassign' );

	const TABS = array( 'cases', 'new', 'log', 'settings' );

	const FINISHED_LIMIT = 50;

	/**
	 * The cases of the order on screen, once per request (the order
	 * screen's line links).
	 *
	 * @var array<int, array[]>
	 */
	private static $order_cases = array();

	/**
	 * Register hooks.
	 */
	public function init() {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ), 20 );
		add_action( 'admin_post_' . self::ACTION_CASE, array( $this, 'handle_case' ) );
		add_action( 'admin_post_' . self::ACTION_OPEN, array( $this, 'handle_open' ) );
		add_action( 'admin_post_' . self::ACTION_SETTINGS, array( $this, 'handle_settings' ) );
		add_action( 'woocommerce_after_order_itemmeta', array( $this, 'order_line_link' ), 20, 3 );
	}

	/**
	 * URL of a tab.
	 *
	 * @param string $tab   cases | new | log | settings.
	 * @param array  $extra Extra query args.
	 * @return string
	 */
	public static function page_url( $tab = 'cases', array $extra = array() ) {
		$args = array( 'page' => Lost_Fish::MENU_SLUG );
		if ( 'cases' !== $tab ) {
			$args['tab'] = $tab;
		}
		return add_query_arg( array_merge( $args, $extra ), admin_url( 'admin.php' ) );
	}

	/* ============================================================== */
	/*  Page                                                           */
	/* ============================================================== */

	/**
	 * Render the section page.
	 */
	public function render_page() {
		if ( ! current_user_can( Lost_Fish::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'fishotel-misc-plugin' ) );
		}

		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'cases'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only tab switch.
		if ( ! in_array( $tab, self::TABS, true ) ) {
			$tab = 'cases';
		}

		$cases     = Cases::instance();
		$settings  = Settings::get();
		$flash     = self::take_flash();
		$tables_ok = Store::tables_exist();
		$status    = self::connections( $cases );

		if ( 'settings' === $tab ) {
			$template = Email::template( $settings );
			$sample   = self::sample_message( $settings );

			include Lost_Fish::path() . 'views/settings.php';
			return;
		}

		if ( 'log' === $tab ) {
			$rows = $tables_ok ? $cases->store()->log_rows( Store::LOG_LIMIT ) : array();

			include Lost_Fish::path() . 'views/log.php';
			return;
		}

		if ( 'new' === $tab ) {
			$order_id = isset( $_GET['order'] ) ? absint( wp_unslash( $_GET['order'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only lookup.
			$picked   = isset( $_GET['line'] ) ? sanitize_text_field( wp_unslash( $_GET['line'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$lookup   = self::order_lookup( $cases, $order_id );

			include Lost_Fish::path() . 'views/new.php';
			return;
		}

		$now    = time();
		$groups = array(
			'needs'    => array(),
			'waiting'  => array(),
			'finished' => array(),
		);
		if ( $tables_ok ) {
			$counts = $cases->store()->refresh_counts();
			foreach ( $cases->store()->cases( array( 'status' => array( 'open', 'sent', 'answered' ) ) ) as $case ) {
				$card                                               = self::card( $cases, $case, $settings );
				$groups[ Cases::bucket( $card['case'], $now ) ][] = $card;
			}
			foreach ( $cases->store()->cases( array( 'status' => array( 'done', 'closed' ), 'limit' => self::FINISHED_LIMIT ) ) as $case ) {
				$groups['finished'][] = self::card( $cases, $case, $settings );
			}
		} else {
			$counts = Store::stored_counts();
		}

		include Lost_Fish::path() . 'views/page.php';
	}

	/**
	 * What is connected: WooCommerce, the Clean Room, ShipTracker, the
	 * Invoices and Requests sections, the wallets screen — so the screen
	 * can say plainly what is unavailable.
	 *
	 * @param Cases $cases The cases.
	 * @return array<string, array{ok: bool, label: string, note: string}>
	 */
	public static function connections( Cases $cases ) {
		$rooms = $cases->rooms()->available();

		return array(
			'woocommerce' => array(
				'ok'    => $cases->orders()->available(),
				'label' => __( 'WooCommerce orders', 'fishotel-misc-plugin' ),
				'note'  => __( 'Without WooCommerce there are no orders to read: no case can be opened.', 'fishotel-misc-plugin' ),
			),
			'cleanroom'   => array(
				'ok'    => $rooms,
				'label' => __( 'Clean Room', 'fishotel-misc-plugin' ),
				'note'  => __( 'The Clean Room section is off (or its tables are missing): a loss cannot open a case by itself. Cases opened by hand still work.', 'fishotel-misc-plugin' ),
			),
			'shiptracker' => array(
				'ok'    => Orders::shipments_available(),
				'label' => __( 'ShipTracker', 'fishotel-misc-plugin' ),
				'note'  => __( 'ShipTracker is not active: a line counts as shipped only by its line status or once the order is completed.', 'fishotel-misc-plugin' ),
			),
			'invoices'    => array(
				'ok'    => Orders::invoices_available(),
				'label' => __( 'Invoices section', 'fishotel-misc-plugin' ),
				'note'  => __( 'The Invoices section is not available: invoice fish are found by the product on their order line, or by name (marked "check").', 'fishotel-misc-plugin' ),
			),
			'requests'    => array(
				'ok'    => Cases::requests_available(),
				'label' => __( 'Requests section', 'fishotel-misc-plugin' ),
				'note'  => __( 'The Requests section is not available: a "try again" shows a reminder to note it yourself.', 'fishotel-misc-plugin' ),
			),
			'wallets'     => array(
				'ok'    => class_exists( '\\FisHotel_Batch_Manager' ),
				'label' => __( 'Wallets (Batch Manager)', 'fishotel-misc-plugin' ),
				'note'  => __( 'The Batch Manager is not active: a credit has no wallets screen to link to.', 'fishotel-misc-plugin' ),
			),
		);
	}

	/**
	 * Everything one case card shows. An open case is brought up to date
	 * with its order first (Cases::refresh()).
	 *
	 * @param Cases $cases    The cases.
	 * @param array $case     The case.
	 * @param array $settings Settings::get().
	 * @return array{case: array, facts: array|null, numbers: array, rows: array[], rest_left: bool, preview: string,
	 *               subject: string, alternatives: array[], todo: array|null, stale: bool}
	 */
	public static function card( Cases $cases, array $case, array $settings ) {
		$case    = $cases->refresh( $case );
		$order   = $cases->orders()->get( (int) $case['order_id'] );
		$facts   = $order ? $cases->orders()->facts( $order ) : null;
		$numbers = $cases->numbers( $case, $facts );
		$rest    = $order ? $cases->orders()->rest_left( $order, $case['line_ref'], (int) $case['qty'] ) : true;
		$shown   = is_array( $facts ) ? $facts : array(
			'number' => (string) $case['order_id'],
			'symbol' => '$',
		);

		$preview = '';
		$subject = '';
		if ( 'open' === $case['status'] && is_array( $facts ) ) {
			$links = array();
			foreach ( Money::CHOICES as $choice ) {
				$links[ $choice ] = '#';
			}
			$message = Email::build( $case, $facts, $numbers, $links, $settings, array( 'rest_left' => $rest ) );
			$preview = $message['html'];
			$subject = $message['subject'];
		}

		return array(
			'case'         => $case,
			'facts'        => $facts,
			'numbers'      => $numbers,
			'rows'         => Email::choice_rows( $case, $numbers, $shown ),
			'rest_left'    => $rest,
			'preview'      => $preview,
			'subject'      => $subject,
			'alternatives' => 'open' === $case['status'] ? $cases->alternatives( $case ) : array(),
			'todo'         => 'answered' === $case['status'] ? $cases->todo( $case, $facts ) : null,
			'stale'        => Cases::is_stale( $case ),
		);
	}

	/**
	 * An order and its lines for the "open by hand" form.
	 *
	 * @param Cases $cases    The cases.
	 * @param int   $order_id Order id (0 = none asked for).
	 * @return array{asked: bool, facts: array|null, lines: array[], cases: array[]}
	 */
	public static function order_lookup( Cases $cases, $order_id ) {
		$out = array(
			'asked' => (int) $order_id > 0,
			'facts' => null,
			'lines' => array(),
			'cases' => array(),
		);
		$order = $cases->orders()->get( (int) $order_id );
		if ( ! $order ) {
			return $out;
		}

		$out['facts'] = $cases->orders()->facts( $order );
		$out['lines'] = $cases->orders()->lines( $order );
		$out['cases'] = $cases->store()->cases( array( 'order_id' => (int) $order_id ) );

		return $out;
	}

	/**
	 * The e-mail with invented sample data, for the Settings preview.
	 *
	 * @param array $settings Settings::get().
	 * @return array Email::build().
	 */
	public static function sample_message( array $settings ) {
		$case = array(
			'id'           => 1,
			'status'       => 'open',
			'fish_name'    => 'Sample Reef Fish',
			'species'      => 'Sample Reef Fish',
			'qty'          => 1,
			'order_id'     => 1001,
			'ship_charged' => 2000,
			'unit_paid'    => 6000,
		);
		$facts = array(
			'number'        => '1001',
			'symbol'        => '$',
			'first_name'    => 'Sam',
			'customer_name' => 'Sam Sample',
			'email'         => 'sam@example.test',
			'ship_date'     => gmdate( 'Y-m-d', time() + 6 * DAY_IN_SECONDS ),
			'delivery_date' => gmdate( 'Y-m-d', time() + 7 * DAY_IN_SECONDS ),
		);
		$numbers = Money::choices(
			array(
				'unit_paid'      => 6000,
				'qty'            => 1,
				'ship_diff'      => 3000,
				'order_total'    => 52000,
				'order_refunded' => 0,
			)
		);
		$links = array();
		foreach ( Money::CHOICES as $choice ) {
			$links[ $choice ] = '#';
		}

		return Email::build( $case, $facts, $numbers, $links, $settings );
	}

	/* ============================================================== */
	/*  Assets                                                         */
	/* ============================================================== */

	/**
	 * Enqueue the CSS / JS on the section page only.
	 *
	 * @param string $hook_suffix Admin page hook.
	 */
	public function enqueue_assets( $hook_suffix ) {
		if ( false === strpos( (string) $hook_suffix, Lost_Fish::MENU_SLUG ) ) {
			return;
		}

		wp_enqueue_style( 'fishotel-lostfish-admin', Lost_Fish::url() . 'css/lost-fish-admin.css', array(), FISHOTEL_MISC_VERSION );
		wp_enqueue_script( 'fishotel-lostfish-admin', Lost_Fish::url() . 'js/lost-fish-admin.js', array(), FISHOTEL_MISC_VERSION, true );
	}

	/* ============================================================== */
	/*  The order screen                                               */
	/* ============================================================== */

	/**
	 * Under each line of the order screen: its lost-fish case when it has
	 * one, else the link that opens one by hand.
	 *
	 * @param int    $item_id The order item id.
	 * @param object $item    The order item.
	 * @param mixed  $product Its product (unused).
	 */
	public function order_line_link( $item_id, $item = null, $product = null ) {
		unset( $product );

		try {
			if ( ! current_user_can( Lost_Fish::CAPABILITY ) || ! is_object( $item ) || ! method_exists( $item, 'get_order_id' ) ) {
				return;
			}
			if ( method_exists( $item, 'is_type' ) && ! $item->is_type( 'line_item' ) ) {
				return;
			}
			if ( ! Store::installed() ) {
				return;
			}

			$order_id = (int) $item->get_order_id();
			if ( $order_id <= 0 ) {
				return;
			}
			if ( ! isset( self::$order_cases[ $order_id ] ) ) {
				$store                          = new Store();
				self::$order_cases[ $order_id ] = $store->cases( array( 'order_id' => $order_id ) );
			}

			echo '<div class="fh-lf-order-line" style="margin-top:4px;font-size:12px;">';
			$any = false;
			foreach ( self::$order_cases[ $order_id ] as $case ) {
				if ( (int) $case['order_item_id'] !== (int) $item_id ) {
					continue;
				}
				$any = true;
				printf(
					'<a href="%1$s">%2$s</a> ',
					esc_url( Cases::case_url( (int) $case['id'] ) ),
					/* translators: 1: case id, 2: status */
					esc_html( sprintf( __( 'Lost fish case #%1$d (%2$s)', 'fishotel-misc-plugin' ), (int) $case['id'], self::status_label( $case ) ) )
				);
			}
			if ( ! $any ) {
				printf(
					'<a href="%1$s">%2$s</a>',
					esc_url(
						self::page_url(
							'new',
							array(
								'order' => $order_id,
								'line'  => 'i:' . (int) $item_id,
							)
						)
					),
					esc_html__( 'Lost this fish? Open a case…', 'fishotel-misc-plugin' )
				);
			}
			echo '</div>';
		} catch ( \Throwable $e ) {
			unset( $e );
		}
	}

	/**
	 * A case's status for a person.
	 *
	 * @param array $case The case.
	 * @return string
	 */
	public static function status_label( array $case ) {
		switch ( $case['status'] ) {
			case 'open':
				return __( 'not sent yet', 'fishotel-misc-plugin' );
			case 'sent':
				return Cases::is_stale( $case ) ? __( 'no answer', 'fishotel-misc-plugin' ) : __( 'waiting for the customer', 'fishotel-misc-plugin' );
			case 'answered':
				return __( 'answered — to do', 'fishotel-misc-plugin' );
			case 'done':
				return __( 'done', 'fishotel-misc-plugin' );
			default:
				return __( 'closed', 'fishotel-misc-plugin' );
		}
	}

	/* ============================================================== */
	/*  Writes                                                         */
	/* ============================================================== */

	/**
	 * admin-post: something done to a case.
	 */
	public function handle_case() {
		$raw    = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in case_request().
		$result = self::case_request( Cases::instance(), is_array( $raw ) ? $raw : array() );

		if ( 403 === $result['status'] ) {
			wp_die( esc_html( $result['message'] ), '', array( 'response' => 403 ) );
		}

		self::flash( $result['message'], $result['ok'] ? 'success' : 'error' );
		wp_safe_redirect( self::page_url( 'cases' ) . ( $result['case_id'] > 0 ? '#fh-lf-case-' . (int) $result['case_id'] : '' ) );
		exit;
	}

	/**
	 * Something done to a case: capability, the case's nonce, then the
	 * operation.
	 *
	 * @param Cases $cases The cases.
	 * @param array $raw   Unslashed POST: case_id, op, _wpnonce and the operation's own fields.
	 * @return array{status: int, ok: bool, message: string, case_id: int}
	 */
	public static function case_request( Cases $cases, array $raw ) {
		$case_id = isset( $raw['case_id'] ) && is_scalar( $raw['case_id'] ) ? absint( $raw['case_id'] ) : 0;
		$denied  = array(
			'status'  => 403,
			'ok'      => false,
			'message' => __( 'You are not allowed to do that, or the page was open for too long. Reload it and try again.', 'fishotel-misc-plugin' ),
			'case_id' => $case_id,
		);

		if ( ! current_user_can( Lost_Fish::CAPABILITY ) ) {
			return $denied;
		}
		$nonce = isset( $raw['_wpnonce'] ) && is_scalar( $raw['_wpnonce'] ) ? (string) $raw['_wpnonce'] : '';
		if ( $case_id <= 0 || ! wp_verify_nonce( $nonce, self::NONCE_CASE . $case_id ) ) {
			return $denied;
		}

		$op    = isset( $raw['op'] ) && is_scalar( $raw['op'] ) ? sanitize_key( (string) $raw['op'] ) : '';
		$actor = (int) get_current_user_id();
		$text  = static function ( $key ) use ( $raw ) {
			return isset( $raw[ $key ] ) && is_scalar( $raw[ $key ] ) ? trim( (string) $raw[ $key ] ) : '';
		};

		// What these four do depends on what the page showed (who is written to, which numbers): a page
		// that is behind the case — another tab changed it, a further loss joined it — does nothing.
		if ( in_array( $op, self::SEEN_OPS, true ) ) {
			$now = $cases->store()->get_case( $case_id );
			if ( $now && ! hash_equals( Cases::stamp( $now ), $text( 'seen' ) ) ) {
				return array(
					'status'  => 400,
					'ok'      => false,
					'message' => __( 'This case changed since the page was loaded (another tab, or another loss). Nothing was done — check it as it is now, then try again.', 'fishotel-misc-plugin' ),
					'case_id' => $case_id,
				);
			}
		}

		switch ( $op ) {
			case 'send':
				$expect = array();
				if ( '' !== $text( 'shown_refund' ) && ctype_digit( $text( 'shown_refund' ) ) ) {
					$expect['refund'] = (int) $text( 'shown_refund' );
				}
				$result = $cases->send( $case_id, $actor, $expect );
				break;

			case 'numbers':
				$numbers = array();
				foreach ( array( 'unit_paid', 'ship_diff' ) as $key ) {
					if ( array_key_exists( $key, $raw ) ) {
						$numbers[ $key ] = $text( $key );
					}
				}
				$result = $cases->set_numbers( $case_id, $numbers, $actor );
				break;

			case 'answer':
				$result = $cases->answer( $case_id, sanitize_key( $text( 'choice' ) ), 'owner', $actor );
				break;

			case 'request':
				$result = $cases->create_request( $case_id, $actor );
				break;

			case 'done':
				$result = $cases->done( $case_id, sanitize_textarea_field( $text( 'note' ) ), $actor );
				break;

			case 'reopen':
				$result = $cases->reopen( $case_id, $actor );
				break;

			case 'close':
				$result = $cases->close( $case_id, sanitize_key( $text( 'reason' ) ), $actor );
				break;

			case 'reassign':
				$target = explode( '|', $text( 'holder' ), 2 );
				$result = $cases->reassign( $case_id, isset( $target[0] ) ? absint( $target[0] ) : 0, isset( $target[1] ) ? sanitize_text_field( $target[1] ) : '', $actor );
				break;

			default:
				$result = array(
					'ok'      => false,
					'message' => __( 'Unknown action.', 'fishotel-misc-plugin' ),
				);
		}

		return array(
			'status'  => $result['ok'] ? 200 : 400,
			'ok'      => (bool) $result['ok'],
			'message' => (string) $result['message'],
			'case_id' => $case_id,
		);
	}

	/**
	 * admin-post: open a case by hand.
	 */
	public function handle_open() {
		$raw    = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in open_request().
		$result = self::open_request( Cases::instance(), is_array( $raw ) ? $raw : array() );

		if ( 403 === $result['status'] ) {
			wp_die( esc_html( $result['message'] ), '', array( 'response' => 403 ) );
		}

		self::flash( $result['message'], $result['ok'] ? 'success' : 'error' );
		if ( $result['ok'] ) {
			wp_safe_redirect( self::page_url( 'cases' ) . '#fh-lf-case-' . (int) $result['case_id'] );
		} else {
			wp_safe_redirect( self::page_url( 'new', array( 'order' => (int) $result['order_id'] ) ) );
		}
		exit;
	}

	/**
	 * Open a case by hand: capability, nonce, then Cases::open_by_hand().
	 *
	 * @param Cases $cases The cases.
	 * @param array $raw   Unslashed POST: order_id, line, qty, _wpnonce.
	 * @return array{status: int, ok: bool, message: string, case_id: int, order_id: int}
	 */
	public static function open_request( Cases $cases, array $raw ) {
		$order_id = isset( $raw['order_id'] ) && is_scalar( $raw['order_id'] ) ? absint( $raw['order_id'] ) : 0;
		$nonce    = isset( $raw['_wpnonce'] ) && is_scalar( $raw['_wpnonce'] ) ? (string) $raw['_wpnonce'] : '';

		if ( ! current_user_can( Lost_Fish::CAPABILITY ) || ! wp_verify_nonce( $nonce, self::NONCE_OPEN ) ) {
			return array(
				'status'   => 403,
				'ok'       => false,
				'message'  => __( 'You are not allowed to do that, or the page was open for too long. Reload it and try again.', 'fishotel-misc-plugin' ),
				'case_id'  => 0,
				'order_id' => $order_id,
			);
		}

		$line   = isset( $raw['line'] ) && is_scalar( $raw['line'] ) ? sanitize_text_field( (string) $raw['line'] ) : '';
		$qty    = isset( $raw['qty'] ) && is_scalar( $raw['qty'] ) ? (int) $raw['qty'] : 1;
		$result = $cases->open_by_hand( $order_id, $line, $qty, (int) get_current_user_id() );

		return array(
			'status'   => $result['ok'] ? 200 : 400,
			'ok'       => (bool) $result['ok'],
			'message'  => (string) $result['message'],
			'case_id'  => is_array( $result['case'] ) ? (int) $result['case']['id'] : 0,
			'order_id' => $order_id,
		);
	}

	/**
	 * admin-post: save the settings.
	 */
	public function handle_settings() {
		$raw    = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in settings_request().
		$result = self::settings_request( is_array( $raw ) ? $raw : array() );

		if ( 403 === $result['status'] ) {
			wp_die( esc_html( $result['message'] ), '', array( 'response' => 403 ) );
		}

		self::flash( $result['message'], 'success' );
		wp_safe_redirect( self::page_url( 'settings' ) );
		exit;
	}

	/**
	 * Save the settings: capability, nonce, then Settings::save(). A
	 * template field that still holds the default text is stored empty,
	 * so the default keeps following the code; "reset" empties both.
	 *
	 * @param array $raw Unslashed POST: fh_lf (the fields), reset, _wpnonce.
	 * @return array{status: int, ok: bool, message: string, settings: array|null}
	 */
	public static function settings_request( array $raw ) {
		$nonce = isset( $raw['_wpnonce'] ) && is_scalar( $raw['_wpnonce'] ) ? (string) $raw['_wpnonce'] : '';
		if ( ! current_user_can( Lost_Fish::CAPABILITY ) || ! wp_verify_nonce( $nonce, self::NONCE_SETTINGS ) ) {
			return array(
				'status'   => 403,
				'ok'       => false,
				'message'  => __( 'You are not allowed to do that, or the page was open for too long. Reload it and try again.', 'fishotel-misc-plugin' ),
				'settings' => null,
			);
		}

		$fields = isset( $raw['fh_lf'] ) && is_array( $raw['fh_lf'] ) ? $raw['fh_lf'] : array();
		$same   = static function ( $a, $b ) {
			return trim( str_replace( "\r\n", "\n", (string) $a ) ) === trim( str_replace( "\r\n", "\n", (string) $b ) );
		};

		if ( ! empty( $raw['reset'] ) ) {
			$fields['subject'] = '';
			$fields['body']    = '';
		} else {
			if ( isset( $fields['subject'] ) && $same( $fields['subject'], Email::default_subject() ) ) {
				$fields['subject'] = '';
			}
			if ( isset( $fields['body'] ) && $same( $fields['body'], Email::default_body() ) ) {
				$fields['body'] = '';
			}
		}

		$saved = Settings::save( $fields );

		return array(
			'status'   => 200,
			'ok'       => true,
			'message'  => ! empty( $raw['reset'] ) ? __( 'The e-mail is back to the built-in text.', 'fishotel-misc-plugin' ) : __( 'Settings saved.', 'fishotel-misc-plugin' ),
			'settings' => $saved,
		);
	}

	/* ============================================================== */
	/*  Flash                                                          */
	/* ============================================================== */

	/**
	 * Keep a message for this user's next page.
	 *
	 * @param string $message The message.
	 * @param string $type    success | error.
	 */
	public static function flash( $message, $type = 'success' ) {
		set_transient(
			self::FLASH . (int) get_current_user_id(),
			array(
				'message' => (string) $message,
				'type'    => 'error' === $type ? 'error' : 'success',
			),
			2 * MINUTE_IN_SECONDS
		);
	}

	/**
	 * The message kept for this user, once.
	 *
	 * @return array{message: string, type: string}|null
	 */
	public static function take_flash() {
		$key   = self::FLASH . (int) get_current_user_id();
		$flash = get_transient( $key );
		if ( ! is_array( $flash ) || empty( $flash['message'] ) ) {
			return null;
		}
		delete_transient( $key );

		return array(
			'message' => (string) $flash['message'],
			'type'    => 'error' === ( $flash['type'] ?? '' ) ? 'error' : 'success',
		);
	}
}
