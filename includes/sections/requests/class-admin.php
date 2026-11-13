<?php
/**
 * Requests admin — FisHotel Tools → Requests:
 *
 *   Requests   the list: a "Needs you" strip (new requests to approve as
 *              sheets, payments overdue, people to tell, owed fish to
 *              send), then every request grouped by fish — "<fish> — 3
 *              waiting · longest 4 months" — or as a flat list; filters by
 *              state, source and where it came from; a search over name,
 *              e-mail, handle and fish; sorting. Each row opens the
 *              request's own page: who, what, the next step, an edit
 *              form and its whole history from the log.
 *   Add        a request by hand.
 *   Links      the private links: create, copy, switch off.
 *   Demand     the most requested fish beside what past supplier orders
 *              ordered per year; who has waited longest.
 *   Settings   the switches, the timers, the "came from" list, the five
 *              e-mails (manage_options).
 *
 * Every write goes through ONE admin-post action with a nonce and
 * manage_woocommerce (Settings: manage_options); perform() does the work
 * and takes its service as a parameter, so the tests drive every
 * operation. On the Clean Room's supplier order page each line reads
 * "Requested by: …" through its fishotel_cleanroom_order_line action.
 *
 * @package FisHotel\Misc\Sections\Requests
 */

namespace FisHotel\Misc\Sections\Requests;

defined( 'ABSPATH' ) || exit;

/**
 * Class Admin
 */
class Admin {

	/**
	 * The admin-post action of every write, and its nonce.
	 */
	const ACTION = 'fishotel_requests_do';
	const NONCE  = 'fishotel_requests_do';

	/**
	 * The AJAX nonce (the customer search).
	 *
	 * @var string
	 */
	const NONCE_AJAX = 'fishotel_requests_ajax';

	/**
	 * Transient (per user) carrying a one-time notice — and a refused
	 * form's values — across the redirect.
	 *
	 * @var string
	 */
	const FLASH = 'fishotel_requests_flash_';

	const TABS     = array( 'list', 'add', 'links', 'demand', 'settings' );
	const PER_PAGE = 100;

	/**
	 * The operations of perform() and whether each needs the Settings
	 * capability.
	 *
	 * @var array<string, bool>
	 */
	const OPS = array(
		'approve'     => false,
		'decline'     => false,
		'cancel'      => false,
		'reopen'      => false,
		'fill'        => false,
		'tell'        => false,
		'told'        => false,
		'link'        => false,
		'unlink'      => false,
		'auto'        => false,
		'resend'      => false,
		'set_fee'     => false,
		'mark_paid'   => false,
		'edit'        => false,
		'note'        => false,
		'add'         => false,
		'link_create' => false,
		'link_toggle' => false,
		'run'         => false,
		'settings'    => true,
	);

	/**
	 * The requests of a supplier order, by line (memoised per order for
	 * the "Requested by" line).
	 *
	 * @var array<int, array<int, array[]>>
	 */
	private static $by_order = array();

	/**
	 * Register hooks.
	 */
	public function init() {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ), 20 );
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle' ) );
		add_action( 'wp_ajax_fishotel_requests_customers', array( $this, 'ajax_customers' ) );
		add_action( 'fishotel_cleanroom_order_line', array( $this, 'requested_by' ) );
	}

	/* ============================================================== */
	/*  URLs and the flash notice                                      */
	/* ============================================================== */

	/**
	 * URL of a tab.
	 *
	 * @param string $tab   list|add|links|demand|settings.
	 * @param array  $extra Extra query args.
	 * @return string
	 */
	public static function page_url( $tab = 'list', array $extra = array() ) {
		$args = array( 'page' => Requests::MENU_SLUG );
		if ( 'list' !== $tab ) {
			$args['tab'] = $tab;
		}
		return add_query_arg( array_merge( $args, $extra ), admin_url( 'admin.php' ) );
	}

	/**
	 * URL of one request's page.
	 *
	 * @param int $id Request id.
	 * @return string
	 */
	public static function request_url( $id ) {
		return self::page_url( 'list', array( 'request' => (int) $id ) );
	}

	/**
	 * The Clean Room's page of a supplier order ('' when the Clean Room
	 * is not there).
	 *
	 * @param int $order_id Supplier order id.
	 * @return string
	 */
	public static function supplier_order_url( $order_id ) {
		$room = ltrim( Cleanroom::NS, '\\' ) . 'Clean_Room';
		if ( (int) $order_id <= 0 || ! class_exists( $room, false ) ) {
			return '';
		}
		return add_query_arg(
			array(
				'page'  => defined( $room . '::MENU_SLUG' ) ? (string) constant( $room . '::MENU_SLUG' ) : 'fishotel-misc-clean-room',
				'tab'   => 'arrivals',
				'order' => (int) $order_id,
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * Keep a notice for the user's next Requests page.
	 *
	 * @param string $message The notice.
	 * @param bool   $ok      Whether it is good news.
	 * @param array  $keep    A refused form's values, to show again.
	 */
	public static function flash( $message, $ok = true, array $keep = array() ) {
		set_transient(
			self::FLASH . (int) get_current_user_id(),
			array(
				'message' => (string) $message,
				'ok'      => (bool) $ok,
				'keep'    => $keep,
			),
			2 * MINUTE_IN_SECONDS
		);
	}

	/**
	 * The pending notice, once.
	 *
	 * @return array{message: string, ok: bool, keep: array}
	 */
	public static function take_flash() {
		$key   = self::FLASH . (int) get_current_user_id();
		$flash = get_transient( $key );
		if ( false !== $flash ) {
			delete_transient( $key );
		}
		return array(
			'message' => is_array( $flash ) && isset( $flash['message'] ) ? (string) $flash['message'] : '',
			'ok'      => is_array( $flash ) && ! empty( $flash['ok'] ),
			'keep'    => is_array( $flash ) && isset( $flash['keep'] ) && is_array( $flash['keep'] ) ? $flash['keep'] : array(),
		);
	}

	/**
	 * Where a handled form goes back to: the address it named when that
	 * is one of this page's, else the list.
	 *
	 * @param mixed $back The posted address.
	 * @return string
	 */
	public static function back_url( $back ) {
		$base = admin_url( 'admin.php?page=' . Requests::MENU_SLUG );
		if ( is_string( $back ) && 0 === strpos( $back, $base ) && false === strpos( $back, "\n" ) && false === strpos( $back, "\r" ) ) {
			$rest = substr( $back, strlen( $base ) );
			if ( '' === $rest || '&' === $rest[0] || '#' === $rest[0] ) {
				return $back;
			}
		}
		return self::page_url();
	}

	/* ============================================================== */
	/*  Assets                                                         */
	/* ============================================================== */

	/**
	 * Enqueue the page's CSS and JS (this page only).
	 *
	 * @param string $hook_suffix The current admin page hook suffix.
	 */
	public function enqueue_assets( $hook_suffix ) {
		if ( false === strpos( (string) $hook_suffix, Requests::MENU_SLUG ) ) {
			return;
		}

		wp_enqueue_style( 'fishotel-requests-admin', Requests::url() . 'css/requests-admin.css', array(), FISHOTEL_MISC_VERSION );
		wp_enqueue_script( 'fishotel-requests-admin', Requests::url() . 'js/requests-admin.js', array(), FISHOTEL_MISC_VERSION, true );
		wp_localize_script(
			'fishotel-requests-admin',
			'fhRequestsAdmin',
			array(
				'ajaxUrl'         => admin_url( 'admin-ajax.php' ),
				'nonce'           => wp_create_nonce( self::NONCE_AJAX ),
				'suggestAction'   => Front::SUGGEST_ACTION,
				'customersAction' => 'fishotel_requests_customers',
				'i18n'            => array(
					'copied'  => __( 'Copied.', 'fishotel-misc-plugin' ),
					'copy'    => __( 'Copy', 'fishotel-misc-plugin' ),
					'nobody'  => __( 'No site customer matches — the request is saved with the name and e-mail you type.', 'fishotel-misc-plugin' ),
					'picked'  => __( 'Site customer:', 'fishotel-misc-plugin' ),
					'unpick'  => __( 'not this customer', 'fishotel-misc-plugin' ),
					'confirm' => __( 'Are you sure?', 'fishotel-misc-plugin' ),
				),
			)
		);
	}

	/* ============================================================== */
	/*  The page                                                       */
	/* ============================================================== */

	/**
	 * What every tab needs.
	 *
	 * @return array
	 */
	private static function context() {
		$user      = wp_get_current_user();
		$cleanroom = Cleanroom::make();

		return array(
			'service'      => Requests::service(),
			'store'        => Requests::service()->store,
			'settings'     => Requests::service()->settings,
			'actor'        => Service::staff( (int) $user->ID, (string) $user->user_login ),
			'cleanroom'    => $cleanroom,
			'sizes'        => $cleanroom ? $cleanroom->sizes() : null,
			'fees'         => Fees::status(),
			'can_settings' => current_user_can( Requests::SETTINGS_CAPABILITY ),
			'tables_ok'    => Store::ready(),
			'now'          => time(),
		);
	}

	/**
	 * Render the section page.
	 */
	public function render_page() {
		if ( ! current_user_can( Requests::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'fishotel-misc-plugin' ) );
		}

		self::ensure_endpoint();

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only tab, filters and paging.
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'list';
		if ( ! in_array( $tab, self::TABS, true ) ) {
			$tab = 'list';
		}

		$ctx   = self::context();
		$flash = self::take_flash();
		$store = $ctx['store'];
		$now   = $ctx['now'];

		$open  = $store->find( array( 'state' => Rules::OPEN_STATES ) );
		$needs = Listing::needs_you( $open, $now, $ctx['settings'] );

		if ( 'settings' === $tab && ! $ctx['can_settings'] ) {
			$tab = 'list';
		}

		if ( 'add' === $tab ) {
			$keep       = $flash['keep'];
			$came_froms = $ctx['settings']['came_from'];
			include Requests::path() . 'views/admin-add.php';
			return;
		}

		if ( 'links' === $tab ) {
			$keep  = $flash['keep'];
			$links = $store->links();
			include Requests::path() . 'views/admin-links.php';
			return;
		}

		if ( 'demand' === $tab ) {
			$ordered = $ctx['cleanroom'] ? $ctx['cleanroom']->ordered_by_year() : array();
			$demand  = Listing::demand( $store->demand(), $ordered );
			$longest = Listing::longest( $open, $now, 25 );
			include Requests::path() . 'views/admin-demand.php';
			return;
		}

		if ( 'settings' === $tab ) {
			$labels = Settings::mail_labels();
			include Requests::path() . 'views/admin-settings.php';
			return;
		}

		// One request's own page.
		$request_id = isset( $_GET['request'] ) ? absint( wp_unslash( $_GET['request'] ) ) : 0;
		if ( $request_id > 0 ) {
			$request = $store->get( $request_id );
			if ( ! $request ) {
				$missing = true;
				include Requests::path() . 'views/admin-request.php';
				return;
			}
			$missing    = false;
			$detail     = self::request_detail( $request, $ctx );
			$came_froms = $ctx['settings']['came_from'];
			include Requests::path() . 'views/admin-request.php';
			return;
		}

		// The list.
		$filters = array(
			'state'     => isset( $_GET['state'] ) ? sanitize_key( wp_unslash( $_GET['state'] ) ) : '',
			'source'    => isset( $_GET['source'] ) ? sanitize_key( wp_unslash( $_GET['source'] ) ) : '',
			'came_from' => isset( $_GET['from'] ) ? Rules::text( wp_unslash( $_GET['from'] ), 60 ) : '', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			'search'    => isset( $_GET['s'] ) ? Rules::text( wp_unslash( $_GET['s'] ), 120 ) : '', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			'line_id'   => isset( $_GET['line'] ) ? absint( wp_unslash( $_GET['line'] ) ) : 0,
		);
		$view    = isset( $_GET['view'] ) && 'list' === sanitize_key( wp_unslash( $_GET['view'] ) ) ? 'list' : 'groups';
		$sort    = isset( $_GET['sort'] ) ? sanitize_key( wp_unslash( $_GET['sort'] ) ) : '';
		$dir     = isset( $_GET['dir'] ) && 'desc' === sanitize_key( wp_unslash( $_GET['dir'] ) ) ? 'desc' : 'asc';
		$paged   = isset( $_GET['paged'] ) ? max( 1, absint( wp_unslash( $_GET['paged'] ) ) ) : 1;
		// phpcs:enable

		$listing = self::listing( $store, $open, $filters, $view, $sort, $dir, $paged, $now );
		$sheets  = Listing::sheets( $needs['approve'] );

		$link_labels = array();
		foreach ( $store->links() as $link ) {
			$link_labels[ $link['id'] ] = $link['label'];
		}

		$came_froms = array_values( array_unique( array_merge( $ctx['settings']['came_from'], $store->came_from_values() ) ) );
		$last_run   = Cron::summary_words( get_option( Cron::LAST_RUN_OPTION ) );
		$last_at    = get_option( Cron::LAST_RUN_OPTION );
		$last_at    = is_array( $last_at ) && ! empty( $last_at['at'] ) ? (int) $last_at['at'] : 0;

		include Requests::path() . 'views/admin-list.php';
	}

	/**
	 * The rows the list shows (the store as a parameter, so it is testable).
	 *
	 * @param object  $store   Store.
	 * @param array[] $open    The open requests (already read for "Needs you").
	 * @param array   $filters state ('' = open, all, closed, or one state), source, came_from, search, line_id.
	 * @param string  $view    groups|list.
	 * @param string  $sort    Listing::SORTS (list) or Listing::GROUP_SORTS (groups).
	 * @param string  $dir     asc|desc.
	 * @param int     $paged   Page (list view).
	 * @param int     $now     UNIX time.
	 * @return array{rows: array[], groups: array[], total: int, pages: int, paged: int, capped: bool, state: string}
	 */
	public static function listing( $store, array $open, array $filters, $view, $sort, $dir, $paged, $now ) {
		$state = (string) ( $filters['state'] ?? '' );
		if ( 'all' === $state ) {
			$rows = $store->find(
				array(
					'orderby' => 'id',
					'order'   => 'DESC',
				)
			);
		} elseif ( 'closed' === $state ) {
			$rows = $store->find(
				array(
					'state'   => Rules::CLOSED_STATES,
					'orderby' => 'id',
					'order'   => 'DESC',
				)
			);
		} elseif ( in_array( $state, Rules::CLOSED_STATES, true ) ) {
			$rows = $store->find(
				array(
					'state'   => $state,
					'orderby' => 'id',
					'order'   => 'DESC',
				)
			);
		} else {
			$rows = $open;
		}
		$capped = count( $rows ) >= Store::FIND_LIMIT;

		$rows = Listing::filter(
			$rows,
			array(
				'state'     => in_array( $state, Rules::STATES, true ) ? $state : '',
				'source'    => in_array( (string) ( $filters['source'] ?? '' ), Rules::SOURCES, true ) ? (string) $filters['source'] : '',
				'came_from' => (string) ( $filters['came_from'] ?? '' ),
				'search'    => (string) ( $filters['search'] ?? '' ),
				'line_id'   => (int) ( $filters['line_id'] ?? 0 ),
			)
		);

		$out = array(
			'rows'   => array(),
			'groups' => array(),
			'total'  => count( $rows ),
			'pages'  => 1,
			'paged'  => 1,
			'capped' => $capped,
			'state'  => $state,
		);

		if ( 'list' === $view ) {
			$rows         = Listing::sort( $rows, in_array( $sort, Listing::SORTS, true ) ? $sort : 'created', $dir );
			$out['pages'] = max( 1, (int) ceil( count( $rows ) / self::PER_PAGE ) );
			$out['paged'] = min( max( 1, (int) $paged ), $out['pages'] );
			$out['rows']  = array_slice( $rows, ( $out['paged'] - 1 ) * self::PER_PAGE, self::PER_PAGE );
		} else {
			$out['groups'] = Listing::groups( $rows, $now, in_array( $sort, Listing::GROUP_SORTS, true ) ? $sort : 'longest' );
		}

		return $out;
	}

	/**
	 * Everything one request's page shows.
	 *
	 * @param array $request The request.
	 * @param array $ctx     context().
	 * @return array log, members, carrier, pay_url, candidates, ref, order_url, link, mates, user,
	 *               from_order_url, blocker.
	 */
	public static function request_detail( array $request, array $ctx ) {
		$store   = $ctx['store'];
		$id      = (int) $request['id'];
		$logs    = $store->logs( array( $id ) );
		$carrier = null;
		$members = array();
		$pay_url = '';

		if ( (int) $request['fee_ref'] > 0 ) {
			$carrier = (int) $request['fee_ref'] === $id ? $request : $store->get( (int) $request['fee_ref'] );
			$members = $ctx['service']->invoice_members( (int) $request['fee_ref'] );
			if ( $carrier && empty( $carrier['fee_paid_at'] ) && (float) $carrier['fee_amount'] > 0 ) {
				$pay_url = Urls::pay( (int) $carrier['id'], (float) $carrier['fee_amount'] );
			}
		}

		$candidates = array();
		$ref        = '';
		if ( $ctx['cleanroom'] ) {
			if ( in_array( $request['state'], array( 'waiting', 'on_order', 'in_quarantine', 'ready' ), true ) ) {
				$found      = $ctx['cleanroom']->candidates( array( $request ) );
				$candidates = isset( $found[ $id ] ) ? $found[ $id ] : array();
			}
			if ( (int) $request['cr_order_id'] > 0 ) {
				$refs = $ctx['cleanroom']->refs( array( (int) $request['cr_order_id'] ) );
				$ref  = isset( $refs[ (int) $request['cr_order_id'] ] ) ? $refs[ (int) $request['cr_order_id'] ] : '';
			}
		}

		$mates = array();
		if ( '' !== $request['sheet'] ) {
			foreach ( $store->find( array( 'sheet' => $request['sheet'] ) ) as $mate ) {
				if ( (int) $mate['id'] !== $id ) {
					$mates[] = $mate;
				}
			}
		}

		$from_order_url = '';
		if ( (int) $request['from_order_id'] > 0 && function_exists( 'wc_get_order' ) ) {
			$order = wc_get_order( (int) $request['from_order_id'] );
			if ( is_object( $order ) && method_exists( $order, 'get_edit_order_url' ) ) {
				$from_order_url = (string) $order->get_edit_order_url();
			}
		}

		return array(
			'log'            => isset( $logs[ $id ] ) ? $logs[ $id ] : array(),
			'members'        => $members,
			'carrier'        => $carrier,
			'pay_url'        => $pay_url,
			'candidates'     => $candidates,
			'ref'            => $ref,
			'order_url'      => self::supplier_order_url( (int) $request['cr_order_id'] ),
			'link'           => (int) $request['link_id'] > 0 ? $store->link( (int) $request['link_id'] ) : null,
			'mates'          => $mates,
			'user'           => (int) $request['user_id'] > 0 ? Requests::user( (int) $request['user_id'] ) : null,
			'from_order_url' => $from_order_url,
			'blocker'        => 'ready' === $request['state'] ? $ctx['service']->offer_blocker( $request ) : '',
		);
	}

	/**
	 * A date and time in the site zone, for a screen.
	 *
	 * @param string $utc A stored UTC datetime.
	 * @return string
	 */
	public static function when( $utc ) {
		$ts = Rules::ts( $utc );
		if ( $ts <= 0 ) {
			return '';
		}
		return function_exists( 'wp_date' ) ? (string) wp_date( 'Y-m-d H:i', $ts ) : gmdate( 'Y-m-d H:i', $ts );
	}

	/**
	 * The state badge of a request.
	 *
	 * @param array $request The request.
	 * @return string HTML (escaped).
	 */
	public static function badge( array $request ) {
		$html = '<span class="fh-rq-badge fh-rq-badge--' . esc_attr( $request['state'] ) . '">' . esc_html( Rules::state_label( $request['state'] ) ) . '</span>';
		if ( ! empty( $request['owed'] ) ) {
			$html .= ' <span class="fh-rq-badge fh-rq-badge--owed" title="' . esc_attr__( 'Already paid for (a lost fish): never a fee.', 'fishotel-misc-plugin' ) . '">' . esc_html__( 'owed', 'fishotel-misc-plugin' ) . '</span>';
		}
		return $html;
	}

	/* ============================================================== */
	/*  Writes                                                         */
	/* ============================================================== */

	/**
	 * The admin-post handler of every write.
	 */
	public function handle() {
		if ( ! current_user_can( Requests::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'fishotel-misc-plugin' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( self::NONCE );

		$post = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- every value is cleaned by Rules / Settings in perform().
		$post = is_array( $post ) ? $post : array();
		$op   = isset( $post['op'] ) && is_scalar( $post['op'] ) ? sanitize_key( (string) $post['op'] ) : '';

		try {
			$result = self::perform( $op, $post, self::context() );
		} catch ( \Throwable $e ) {
			$result = array(
				'ok'      => false,
				'message' => __( 'Something went wrong and nothing was changed.', 'fishotel-misc-plugin' ),
			);
			do_action( 'fishotel_log', 'error', 'requests', sprintf( 'Requests admin action "%1$s" failed: %2$s', $op, $e->getMessage() ), array() );
		}

		self::flash( $result['message'], $result['ok'], isset( $result['keep'] ) && is_array( $result['keep'] ) ? $result['keep'] : array() );

		$back = isset( $result['redirect'] ) ? (string) $result['redirect'] : self::back_url( isset( $post['back'] ) ? $post['back'] : '' );
		wp_safe_redirect( $back );
		exit;
	}

	/**
	 * Do one operation (no capability or nonce check here: handle() did
	 * both; "settings" asks for its own capability through $ctx).
	 *
	 * @param string $op   One of OPS.
	 * @param array  $post The posted form (unslashed).
	 * @param array  $ctx  service, store, settings, actor, cleanroom, sizes, can_settings.
	 * @return array{ok: bool, message: string, keep?: array, redirect?: string}
	 */
	public static function perform( $op, array $post, array $ctx ) {
		$service = $ctx['service'];
		$store   = $ctx['store'];
		$actor   = $ctx['actor'];
		$id      = isset( $post['id'] ) && is_scalar( $post['id'] ) ? absint( $post['id'] ) : 0;
		$text    = static function ( $key ) use ( $post ) {
			return isset( $post[ $key ] ) && is_scalar( $post[ $key ] ) ? (string) $post[ $key ] : '';
		};
		$fail    = static function ( $message, array $keep = array() ) {
			return array(
				'ok'      => false,
				'message' => (string) $message,
				'keep'    => $keep,
			);
		};

		if ( ! isset( self::OPS[ $op ] ) ) {
			return $fail( __( 'That action is not known.', 'fishotel-misc-plugin' ) );
		}
		if ( self::OPS[ $op ] && empty( $ctx['can_settings'] ) ) {
			return $fail( __( 'You do not have permission to change the settings.', 'fishotel-misc-plugin' ) );
		}

		switch ( $op ) {
			case 'approve':
				$ids = isset( $post['ids'] ) && is_array( $post['ids'] ) ? array_filter( array_map( 'absint', $post['ids'] ) ) : array();
				if ( ! $ids && $id > 0 ) {
					$ids = array( $id );
				}
				if ( ! $ids ) {
					return $fail( __( 'Tick at least one request to approve.', 'fishotel-misc-plugin' ) );
				}
				return $service->approve( $ids, $text( 'fee' ), $actor );

			case 'decline':
				return $service->decline( $id, $text( 'note' ), ! empty( $post['send'] ), $actor );

			case 'cancel':
				return $service->cancel( $id, $actor, $text( 'note' ) );

			case 'reopen':
				return $service->reopen( $id, $actor );

			case 'fill':
				return $service->fill( $id, $actor, $text( 'note' ) );

			case 'tell':
				return $service->offer( $id, $actor );

			case 'told':
				return $service->told( $id, $actor );

			case 'resend':
				return $service->resend_invoice( $id, $actor );

			case 'set_fee':
				return $service->set_fee( $id, $text( 'fee' ), $actor );

			case 'mark_paid':
				$request = $store->get( $id );
				if ( ! $request || ! Rules::fee_open( $request ) ) {
					return $fail( __( 'That request is not waiting for a payment.', 'fishotel-misc-plugin' ) );
				}
				return $service->paid( (int) $request['fee_ref'], array( 'actor' => $actor ) );

			case 'auto':
				return $service->set_auto_match( $id, ! empty( $post['on'] ), $actor );

			case 'unlink':
				return $service->unlink( $id, true, $actor );

			case 'link':
				if ( empty( $ctx['cleanroom'] ) ) {
					return $fail( __( 'The Clean Room is not switched on, so there is no supplier order to match to.', 'fishotel-misc-plugin' ) );
				}
				$parts = array_map( 'absint', explode( ':', $text( 'line' ) ) + array( 0, 0 ) );
				$line  = $parts[0] > 0 && $parts[1] > 0 ? $ctx['cleanroom']->line( $parts[0], $parts[1] ) : null;
				if ( ! $line ) {
					return $fail( __( 'Pick a supplier order line to match to.', 'fishotel-misc-plugin' ) );
				}
				$result = $service->link( $id, $line, true, $actor );
				if ( $result['ok'] ) {
					// Settle it at once: the line may already be in a tank, or on sale.
					Follow::run( $service, $ctx['cleanroom'] );
					$now = $store->get( $id );
					if ( $now ) {
						/* translators: %s: state */
						$result['message'] .= ' ' . sprintf( __( 'It is now: %s.', 'fishotel-misc-plugin' ), Rules::state_label( $now['state'] ) );
					}
				}
				return $result;

			case 'edit':
				return $service->edit( $id, $post, $ctx['sizes'], $actor );

			case 'note':
				return $service->note( $id, $text( 'text' ), $actor );

			case 'add':
				$args = array(
					'user_id'    => isset( $post['user_id'] ) && is_scalar( $post['user_id'] ) ? absint( $post['user_id'] ) : 0,
					'name'       => $text( 'name' ),
					'email'      => $text( 'email' ),
					'handle'     => $text( 'handle' ),
					'fish'       => $text( 'fish' ),
					'size_slug'  => $text( 'size_slug' ),
					'size_label' => $text( 'size_label' ),
					'qty'        => $text( 'qty' ),
					'note'       => $text( 'note' ),
					'came_from'  => $text( 'came_from' ),
					'fee'        => $text( 'fee' ),
					'source'     => ! empty( $post['owed'] ) ? 'lost_fish' : 'hand',
				);
				// One more fish for the person just saved, on the invoice that is already out.
				$offered = isset( $post['invoice_of'] ) && is_scalar( $post['invoice_of'] ) ? absint( $post['invoice_of'] ) : 0;
				if ( $offered > 0 && ! empty( $post['same_invoice'] ) && empty( $post['owed'] ) ) {
					$args['invoice_of'] = $offered;
				}
				if ( is_array( $ctx['sizes'] ) ) {
					$args['sizes'] = $ctx['sizes'];
				}
				// The invoice the form may offer for the next fish: one that can still be paid.
				$offer = static function ( $carrier_id ) use ( $service ) {
					$invoice = (int) $carrier_id > 0 ? $service->payable( (int) $carrier_id ) : array( 'ok' => false );
					if ( empty( $invoice['ok'] ) ) {
						return array();
					}
					return array(
						'invoice_of'  => (int) $invoice['carrier']['id'],
						'invoice_fee' => Rules::money( (float) $invoice['carrier']['fee_amount'], Urls::currency_symbol() ),
					);
				};

				$new = $service->create( $args, $actor );
				if ( is_object( $new ) ) {
					return $fail(
						method_exists( $new, 'get_error_message' ) ? (string) $new->get_error_message() : __( 'The request could not be saved.', 'fishotel-misc-plugin' ),
						array_merge(
							array_diff_key( $args, array( 'sizes' => 1, 'invoice_of' => 1 ) ),
							array(
								'owed'         => ! empty( $post['owed'] ) ? 1 : 0,
								'again'        => ! empty( $post['again'] ) ? 1 : 0,
								'same_invoice' => ! empty( $post['same_invoice'] ) ? 1 : 0,
							),
							$offer( $offered )
						)
					);
				}
				$added = $store->get( (int) $new );
				/* translators: 1: request id, 2: state */
				$message = sprintf( __( 'Request #%1$d is saved (%2$s).', 'fishotel-misc-plugin' ), (int) $new, Rules::state_label( $added ? $added['state'] : '' ) );
				$joined  = $added && 'awaiting_payment' === $added['state'] && (int) $added['fee_ref'] !== (int) $added['id'];
				if ( $joined ) {
					/* translators: %d: request id */
					$message .= ' ' . sprintf( __( 'It is on the bill of request #%d — no second bill, no second e-mail.', 'fishotel-misc-plugin' ), (int) $added['fee_ref'] );
				} elseif ( $added && 'awaiting_payment' === $added['state'] ) {
					$message .= ' ' . Service::mail_words( $service->last_mail, __( 'The bill', 'fishotel-misc-plugin' ), true );
				}

				// "Save, and add another fish for this person": the person stays in the
				// form, and an invoice that is not paid yet can take the next fish too.
				$keep = array();
				if ( ! empty( $post['again'] ) && $added ) {
					$keep = array(
						'again'     => 1,
						'user_id'   => (int) $added['user_id'],
						'name'      => (string) $added['name'],
						'email'     => (string) $added['email'],
						'handle'    => (string) $added['handle'],
						'came_from' => (string) $added['came_from'],
						'owed'      => ! empty( $added['owed'] ) ? 1 : 0,
					);
					if ( Rules::fee_open( $added ) ) {
						$keep = array_merge( $keep, $offer( (int) $added['fee_ref'] ) );
					}
				}
				return array(
					'ok'      => true,
					'message' => $message,
					'keep'    => $keep,
				);

			case 'link_create':
				$tz    = function_exists( 'wp_timezone' ) ? wp_timezone() : new \DateTimeZone( 'UTC' );
				$clean = Rules::link( $post, $tz );
				if ( $clean['errors'] ) {
					return $fail(
						implode( ' ', $clean['errors'] ),
						array(
							'label'    => $text( 'label' ),
							'fee'      => $text( 'fee' ),
							'expires'  => $text( 'expires' ),
							'max_uses' => $text( 'max_uses' ),
						)
					);
				}
				$link_id = $store->insert_link(
					array_merge(
						$clean['link'],
						array(
							'token'      => Tokens::link(),
							'created_by' => (int) $actor['id'],
						)
					)
				);
				if ( ! $link_id ) {
					return $fail( __( 'The link could not be saved.', 'fishotel-misc-plugin' ) );
				}
				return array(
					'ok'      => true,
					/* translators: %s: label */
					'message' => sprintf( __( 'The link "%s" is ready — copy it from the list below.', 'fishotel-misc-plugin' ), $clean['link']['label'] ),
				);

			case 'link_toggle':
				$link_id = isset( $post['link_id'] ) && is_scalar( $post['link_id'] ) ? absint( $post['link_id'] ) : 0;
				$on      = ! empty( $post['active'] );
				if ( ! $store->link( $link_id ) || ! $store->set_link_active( $link_id, $on ) ) {
					return $fail( __( 'That link does not exist.', 'fishotel-misc-plugin' ) );
				}
				return array(
					'ok'      => true,
					'message' => $on ? __( 'The link works again.', 'fishotel-misc-plugin' ) : __( 'The link is switched off: its form no longer opens.', 'fishotel-misc-plugin' ),
				);

			case 'run':
				$summary = Cron::run();
				$words   = Cron::summary_words( $summary );
				return array(
					'ok'      => empty( $summary['error'] ),
					/* translators: %s: what the check did */
					'message' => sprintf( __( 'Checked just now: %s.', 'fishotel-misc-plugin' ), $words ),
				);

			case 'settings':
				$raw = isset( $post['settings'] ) && is_array( $post['settings'] ) ? $post['settings'] : array();
				if ( ! Settings::save( Settings::sanitize( $raw ) ) && Settings::get() !== Settings::merge( Settings::sanitize( $raw ) ) ) {
					return $fail( __( 'The settings could not be saved.', 'fishotel-misc-plugin' ) );
				}
				Fees::forget();
				Requests::service( true );
				return array(
					'ok'      => true,
					'message' => __( 'Settings saved.', 'fishotel-misc-plugin' ),
				);
		}

		return $fail( __( 'That action is not known.', 'fishotel-misc-plugin' ) );
	}

	/* ============================================================== */
	/*  The customer search                                            */
	/* ============================================================== */

	/**
	 * Site customers matching what is typed on the Add tab.
	 */
	public function ajax_customers() {
		check_ajax_referer( self::NONCE_AJAX, 'nonce' );
		if ( ! current_user_can( Requests::CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => 'Unauthorized.' ), 403 );
		}

		$term = Rules::text( isset( $_REQUEST['term'] ) ? wp_unslash( $_REQUEST['term'] ) : '', 60 ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( strlen( $term ) < 2 ) {
			wp_send_json_success( array( 'items' => array() ) );
		}

		$users = get_users(
			array(
				'search'         => '*' . $term . '*',
				'search_columns' => array( 'user_login', 'user_email', 'display_name' ),
				'number'         => 8,
				'orderby'        => 'display_name',
				'fields'         => 'ID',
			)
		);

		$items = array();
		foreach ( (array) $users as $user_id ) {
			$user = Requests::user( (int) $user_id );
			if ( $user ) {
				$items[] = $user;
			}
		}

		wp_send_json_success( array( 'items' => $items ) );
	}

	/* ============================================================== */
	/*  The Clean Room's supplier order page                           */
	/* ============================================================== */

	/**
	 * The people who asked for a supplier order line, in words.
	 *
	 * @param array[] $requests The requests matched to the line.
	 * @return string "Pat Example (2), Sam Sample" ('' = nobody).
	 */
	public static function requested_by_words( array $requests ) {
		$names = array();
		foreach ( $requests as $request ) {
			$qty     = max( 1, (int) ( $request['qty'] ?? 1 ) );
			$names[] = Rules::who( $request ) . ( $qty > 1 ? ' (' . $qty . ')' : '' );
		}
		return implode( ', ', $names );
	}

	/**
	 * "Requested by: …" under a line on the Clean Room's supplier order
	 * page (its fishotel_cleanroom_order_line action; one query per order).
	 *
	 * @param mixed $line The Clean Room's order line (id, order_id).
	 */
	public function requested_by( $line ) {
		if ( ! is_array( $line ) || empty( $line['id'] ) || empty( $line['order_id'] ) || ! current_user_can( Requests::CAPABILITY ) ) {
			return;
		}

		$order_id = (int) $line['order_id'];
		if ( ! isset( self::$by_order[ $order_id ] ) ) {
			self::$by_order[ $order_id ] = array();
			try {
				$store = Requests::service()->store;
				foreach ( $store->find(
					array(
						'cr_order_id' => $order_id,
						'state'       => Rules::LINKED_STATES,
					)
				) as $request ) {
					self::$by_order[ $order_id ][ (int) $request['line_id'] ][] = $request;
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}
		}

		$requests = isset( self::$by_order[ $order_id ][ (int) $line['id'] ] ) ? self::$by_order[ $order_id ][ (int) $line['id'] ] : array();
		if ( ! $requests ) {
			return;
		}

		echo '<span class="fh-cr-sub fh-rq-requested-by">' . esc_html__( 'Requested by:', 'fishotel-misc-plugin' ) . ' <a href="' . esc_url( self::page_url( 'list', array( 'view' => 'list', 'line' => (int) $line['id'] ) ) ) . '">' . esc_html( self::requested_by_words( $requests ) ) . '</a></span>';
	}

	/* ============================================================== */
	/*  My Account endpoint                                            */
	/* ============================================================== */

	/**
	 * Whether the rewrite rules know the My Account endpoint (pure).
	 *
	 * @param mixed $rules The rewrite_rules option.
	 * @return bool True when they do — or when there are no rules to know it (plain permalinks).
	 */
	public static function endpoint_known( $rules ) {
		if ( ! is_array( $rules ) || ! $rules ) {
			return true;
		}
		foreach ( array_keys( $rules ) as $rule ) {
			if ( false !== strpos( (string) $rule, '/' . Urls::ACCOUNT_ENDPOINT . '(' ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * My Account → Requests needs its rewrite rule: when the stored rules
	 * do not know the endpoint yet (the section was just switched on),
	 * they are rebuilt — once, when this page is opened.
	 */
	public static function ensure_endpoint() {
		if ( ! function_exists( 'WC' ) || ! function_exists( 'flush_rewrite_rules' ) ) {
			return;
		}
		if ( ! self::endpoint_known( get_option( 'rewrite_rules' ) ) ) {
			flush_rewrite_rules( false );
		}
	}
}
