<?php
/**
 * Shop App gate — where a shop session counts, and for how much.
 *
 * A shop session is NOT a WordPress login. It is recognised only on the
 * shop-floor requests — by default exactly these:
 *   - the Clean Room's Front Desk page: a GET of the site's home address
 *     whose query holds nothing but fh_front_desk and an optional tank
 *     number (the address the tank labels encode);
 *   - the Front Desk's AJAX: a POST to wp-admin/admin-ajax.php with no
 *     query string, for one of its four actions (fishotel_cleanroom_act —
 *     the one write —, _parse, _suggest, _screen).
 * Everywhere else — wp-admin, every other admin-ajax action, the REST API
 * (by path or by ?rest_route=), wp-login, the shop front, My Account,
 * checkout, cron, XML-RPC — the request is classified "not ours" from the
 * method, the path and the names of the query parameters alone, before
 * any cookie is believed, and the device is simply not logged in.
 *
 * On a shop-floor request with a live session:
 *   - WordPress's current user is the account the person's work is
 *     recorded under (a determine_current_user filter, re-asserted at
 *     init in case something asked "who is it?" before this section was
 *     loaded), so the Front Desk records the right actor and WordPress
 *     nonces work as for any logged-in user;
 *   - that account can do NOTHING it could not do without a session —
 *     and nothing it could: every capability is refused (map_meta_cap →
 *     do_not_allow, which holds for a multisite super admin too), an
 *     administrator's own account included …
 *   - … except the shop-floor capability (the one the Front Desk checks),
 *     and that only from the moment the screen's own handler starts
 *     (grant(): template_redirect priority 0 for the page, the action's
 *     own hook at priority 0 for AJAX), so nothing that runs earlier in
 *     the request sees a capability at all;
 *   - WooCommerce neither reads the device's shop-front basket cookies
 *     nor sets any, so the two never meet;
 *   - page-cache plugins are told to keep nothing of the request
 *     (DONOTCACHEPAGE): to them a device without a WordPress login cookie
 *     is an anonymous visitor whose page may be kept for the next one.
 *
 * A live shop session takes precedence over a WordPress login on these
 * requests (somebody unlocked with their PIN: they are who is working);
 * without a live session a WordPress login works exactly as before.
 *
 * Other sections add their own shop-floor screens with the filter
 * fishotel_shop_app_allowed_requests (see rules()) and, when a screen
 * checks another capability, fishotel_shop_app_capabilities.
 *
 * classify() is pure, so the tests drive it with every kind of request.
 *
 * @package FisHotel\Misc\Sections\Shop_App
 */

namespace FisHotel\Misc\Sections\Shop_App;

defined( 'ABSPATH' ) || exit;

/**
 * Class Gate
 */
class Gate {

	/**
	 * This request's answer, once it is final: recognised, user_id, rule, person.
	 *
	 * @var array|null
	 */
	private static $state = null;

	/**
	 * Guards against being asked again while the answer is being worked out.
	 *
	 * @var bool
	 */
	private static $resolving = false;

	/**
	 * Whether the shop-floor capability is granted yet.
	 *
	 * @var bool
	 */
	private static $granted = false;

	/**
	 * The shop-floor capabilities, worked out once per request.
	 *
	 * @var string[]|null
	 */
	private static $caps = null;

	/**
	 * Register the two hooks. Neither costs a query unless the request
	 * carries both of this section's cookies AND is a shop-floor request.
	 */
	public static function init() {
		add_filter( 'determine_current_user', array( self::class, 'determine_user' ), 99 );
		add_action( 'init', array( self::class, 'ensure' ), -1 );
	}

	/**
	 * Start over (tests: one process plays many requests).
	 */
	public static function reset() {
		self::$state     = null;
		self::$resolving = false;
		self::$granted   = false;
		self::$caps      = null;
	}

	/* ============================================================== */
	/*  Which requests                                                 */
	/* ============================================================== */

	/**
	 * The shop-floor requests.
	 *
	 * Each rule is one of
	 *   array( 'type' => 'page', 'query_var' => 'fh_front_desk',
	 *          'params' => array( 'tank' => '/^\d{1,9}$/D' ) )
	 *     a GET of the home address whose query holds query_var (not
	 *     empty, not 0) and at most the listed parameters, each matching
	 *     its pattern;
	 *   array( 'type' => 'ajax', 'action' => 'fishotel_cleanroom_act' )
	 *     a POST to admin-ajax.php, nothing in the query string, for
	 *     that action.
	 *
	 * @return array[]
	 */
	public static function rules() {
		$rules = array();
		$desk  = Shop_App::front_desk();

		if ( $desk['available'] ) {
			$rules[] = array(
				'type'      => 'page',
				'query_var' => $desk['query_var'],
				'params'    => array( 'tank' => '/^\d{1,9}$/D' ),
			);
			foreach ( $desk['actions'] as $action ) {
				$rules[] = array(
					'type'   => 'ajax',
					'action' => $action,
				);
			}
		}

		/**
		 * Filters the requests a shop session is recognised on. Add a
		 * rule for a shop-floor screen of your own; the session is then
		 * the mapped WordPress account there, holding only the shop-floor
		 * capabilities (fishotel_shop_app_capabilities) from the moment
		 * the screen's handler starts — template_redirect priority 0 for
		 * a page, the wp_ajax_{action} hook at priority 0 for AJAX. Keep
		 * a rule as narrow as the screen: it is a way in for a four-digit
		 * PIN.
		 *
		 * @param array[] $rules The rules (see Gate::rules()).
		 */
		$rules = apply_filters( 'fishotel_shop_app_allowed_requests', $rules );

		return self::clean_rules( $rules );
	}

	/**
	 * Keep only well-formed rules (pure).
	 *
	 * @param mixed $rules Rules, as filtered.
	 * @return array[]
	 */
	public static function clean_rules( $rules ) {
		$out = array();
		foreach ( is_array( $rules ) ? $rules : array() as $rule ) {
			if ( ! is_array( $rule ) || empty( $rule['type'] ) ) {
				continue;
			}
			if ( 'ajax' === $rule['type'] ) {
				if ( isset( $rule['action'] ) && is_string( $rule['action'] ) && 1 === preg_match( '/^[a-z0-9_]{1,80}$/D', $rule['action'] ) ) {
					$out[] = array(
						'type'   => 'ajax',
						'action' => $rule['action'],
					);
				}
				continue;
			}
			if ( 'page' === $rule['type'] ) {
				if ( ! isset( $rule['query_var'] ) || ! is_string( $rule['query_var'] ) || 1 !== preg_match( '/^[a-z0-9_]{1,40}$/D', $rule['query_var'] ) ) {
					continue;
				}
				$params = array();
				foreach ( isset( $rule['params'] ) && is_array( $rule['params'] ) ? $rule['params'] : array() as $name => $pattern ) {
					if ( is_string( $name ) && 1 === preg_match( '/^[a-z0-9_]{1,40}$/D', $name ) && is_string( $pattern ) && '' !== $pattern ) {
						$params[ $name ] = $pattern;
					}
				}
				$out[] = array(
					'type'      => 'page',
					'query_var' => $rule['query_var'],
					'params'    => $params,
				);
			}
		}
		return $out;
	}

	/**
	 * What this request is, as far as the gate cares.
	 *
	 * @return array{method: string, path: string, home_path: string, ajax_path: string, get: array, post_action: string, is_admin: bool, doing_ajax: bool, special: bool}
	 */
	public static function request_facts() {
		$special = ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST )
			|| ( defined( 'REST_REQUEST' ) && REST_REQUEST )
			|| ( defined( 'DOING_CRON' ) && DOING_CRON )
			|| ( defined( 'WP_CLI' ) && WP_CLI )
			|| ( defined( 'WP_INSTALLING' ) && WP_INSTALLING );

		return array(
			'method'      => Http::method(),
			'path'        => Http::path(),
			'home_path'   => Shop_App::home_path(),
			'ajax_path'   => (string) wp_parse_url( admin_url( 'admin-ajax.php' ), PHP_URL_PATH ),
			'get'         => $_GET, // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only the parameter names and shapes are looked at, to classify the request.
			'post_action' => isset( $_POST['action'] ) && is_string( $_POST['action'] ) ? $_POST['action'] : '', // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput -- compared with a fixed list; the action's own handler verifies its nonce.
			'is_admin'    => (bool) is_admin(),
			'doing_ajax'  => (bool) wp_doing_ajax(),
			'special'     => (bool) $special,
		);
	}

	/**
	 * The rule a request falls under, or null — PURE. Anything that is
	 * not exactly a shop-floor request is null.
	 *
	 * @param array   $facts request_facts().
	 * @param array[] $rules clean_rules().
	 * @return array|null
	 */
	public static function classify( array $facts, array $rules ) {
		if ( ! empty( $facts['special'] ) ) {
			return null;
		}

		$method = isset( $facts['method'] ) ? (string) $facts['method'] : '';
		$path   = isset( $facts['path'] ) ? (string) $facts['path'] : '';
		$get    = isset( $facts['get'] ) && is_array( $facts['get'] ) ? $facts['get'] : array();

		if ( '' === $path ) {
			return null;
		}

		if ( ! empty( $facts['is_admin'] ) || ! empty( $facts['doing_ajax'] ) ) {
			// wp-admin: admin-ajax.php itself and nothing else, a POST whose
			// address carries no parameter, for a listed action.
			if ( empty( $facts['is_admin'] ) || empty( $facts['doing_ajax'] ) ) {
				return null;
			}
			if ( 'POST' !== $method || $get ) {
				return null;
			}
			if ( '' === (string) ( $facts['ajax_path'] ?? '' ) || $path !== (string) $facts['ajax_path'] ) {
				return null;
			}
			$action = isset( $facts['post_action'] ) ? (string) $facts['post_action'] : '';
			foreach ( $rules as $rule ) {
				if ( 'ajax' === $rule['type'] && '' !== $action && $rule['action'] === $action ) {
					return $rule;
				}
			}
			return null;
		}

		if ( 'GET' !== $method && 'HEAD' !== $method ) {
			return null;
		}

		$home = isset( $facts['home_path'] ) ? (string) $facts['home_path'] : '/';
		if ( $path !== $home && $path !== $home . 'index.php' ) {
			return null;
		}

		foreach ( $rules as $rule ) {
			if ( 'page' === $rule['type'] && self::page_matches( $get, $rule ) ) {
				return $rule;
			}
		}

		return null;
	}

	/**
	 * Whether a query string is exactly a page rule's (pure): its switch
	 * is on, and nothing is in it that the rule does not list.
	 *
	 * @param array $get  The query parameters.
	 * @param array $rule A page rule.
	 * @return bool
	 */
	public static function page_matches( array $get, array $rule ) {
		$var = $rule['query_var'];
		if ( ! isset( $get[ $var ] ) || ! is_string( $get[ $var ] ) || '' === $get[ $var ] || '0' === $get[ $var ] ) {
			return false;
		}
		if ( 1 !== preg_match( '/^[A-Za-z0-9_-]{1,40}$/D', $get[ $var ] ) ) {
			return false;
		}

		foreach ( $get as $name => $value ) {
			if ( $name === $var ) {
				continue;
			}
			if ( ! isset( $rule['params'][ $name ] ) || ! is_string( $value ) ) {
				return false;
			}
			if ( 1 !== @preg_match( $rule['params'][ $name ], $value ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a pattern from a filter may be malformed: that is "no match".
				return false;
			}
		}

		return true;
	}

	/* ============================================================== */
	/*  Who                                                            */
	/* ============================================================== */

	/**
	 * Work out whether this request is a shop-floor request made with a
	 * live shop session. Before `init` a "no" is provisional (another
	 * section may still add its rule); from `init` on it is final.
	 *
	 * @param bool $final Whether the answer may be kept even when it is "no".
	 * @return array{recognised: bool, user_id: int, rule: array|null, person: array|null}
	 */
	private static function resolve( $final = false ) {
		if ( null !== self::$state ) {
			return self::$state;
		}

		$none = array(
			'recognised' => false,
			'user_id'    => 0,
			'rule'       => null,
			'person'     => null,
		);

		if ( self::$resolving ) {
			return $none;
		}

		// Without both cookies there is nothing to recognise — and nothing to query.
		if ( '' === Http::read_cookie( Http::DEVICE ) || '' === Http::read_cookie( Http::SESSION ) ) {
			self::$state = $none;
			return $none;
		}

		$final           = $final || did_action( 'init' ) || doing_action( 'init' );
		self::$resolving = true;
		$rule            = null;
		$ctx             = null;
		try {
			$rule = self::classify( self::request_facts(), self::rules() );
			if ( $rule ) {
				$ctx = Access::instance()->context();
			}
		} catch ( \Throwable $e ) {
			$rule = null;
		}
		self::$resolving = false;

		if ( ! $rule || ! $ctx || ! $ctx['device'] || ! $ctx['session'] || (int) $ctx['session']['user_id'] <= 0 ) {
			if ( $final ) {
				self::$state = $none;
			}
			return $none;
		}

		self::$state = array(
			'recognised' => true,
			'user_id'    => (int) $ctx['session']['user_id'],
			'rule'       => $rule,
			'person'     => array(
				'id'        => (int) $ctx['session']['person']['id'],
				'name'      => (string) $ctx['session']['person']['name'],
				'user_id'   => (int) $ctx['session']['user_id'],
				'device_id' => (int) $ctx['device']['id'],
				'device'    => (string) $ctx['device']['name'],
			),
		);

		self::arm( $rule );

		return self::$state;
	}

	/**
	 * A shop session was recognised: clamp the account, keep WooCommerce's
	 * basket cookies out of it, and arrange for the capability to appear
	 * when the screen's own handler starts.
	 *
	 * @param array $rule The rule the request falls under.
	 */
	private static function arm( array $rule ) {
		// A page cache must never keep what is rendered for a shop session.
		Http::never_cache();

		add_filter( 'map_meta_cap', array( self::class, 'filter_map_meta_cap' ), PHP_INT_MAX, 3 );
		add_filter( 'user_has_cap', array( self::class, 'filter_user_has_cap' ), PHP_INT_MAX, 4 );

		// The device's shop-front basket belongs to the shop front: WooCommerce
		// must neither merge it into the staff account nor replace its cookies.
		add_filter( 'woocommerce_set_cookie_enabled', '__return_false', PHP_INT_MAX );
		foreach ( array_keys( $_COOKIE ) as $name ) {
			if ( is_string( $name ) && ( 0 === strpos( $name, 'wp_woocommerce_session_' ) || 0 === strpos( $name, 'woocommerce_items_in_cart' ) || 0 === strpos( $name, 'woocommerce_cart_hash' ) ) ) {
				unset( $_COOKIE[ $name ] );
			}
		}

		if ( 'ajax' === $rule['type'] ) {
			add_action( 'wp_ajax_' . $rule['action'], array( self::class, 'grant' ), 0 );
			Saves::arm( $rule );
		}
		// A page rule is granted by App::intercept() at template_redirect priority 0.
	}

	/**
	 * determine_current_user: the mapped account on a shop-floor request
	 * with a live session, whatever came before; otherwise untouched.
	 *
	 * @param mixed $user_id What WordPress worked out so far.
	 * @return mixed
	 */
	public static function determine_user( $user_id ) {
		$state = self::resolve();
		return $state['recognised'] ? $state['user_id'] : $user_id;
	}

	/**
	 * At init, before anything else: settle the answer, and when a shop
	 * session is recognised make sure WordPress's current user is its
	 * account even if something asked for the user before this section's
	 * filter was in place.
	 */
	public static function ensure() {
		$state = self::resolve( true );
		if ( $state['recognised'] && (int) get_current_user_id() !== $state['user_id'] ) {
			wp_set_current_user( $state['user_id'] );
		}
	}

	/**
	 * Whether this request is a shop-floor request made with a live shop
	 * session.
	 *
	 * @return bool
	 */
	public static function is_shop_session() {
		$state = self::resolve();
		return ! empty( $state['recognised'] );
	}

	/**
	 * The rule this request was recognised under, or null.
	 *
	 * @return array|null
	 */
	public static function rule() {
		$state = self::resolve();
		return $state['recognised'] ? $state['rule'] : null;
	}

	/**
	 * The person unlocked on this request, or null.
	 *
	 * @return array{id: int, name: string, user_id: int, device_id: int, device: string}|null
	 */
	public static function person() {
		$state = self::resolve();
		return $state['recognised'] ? $state['person'] : null;
	}

	/* ============================================================== */
	/*  How much                                                       */
	/* ============================================================== */

	/**
	 * The capabilities a shop session holds while a shop-floor handler
	 * runs: the one the Front Desk checks, plus what other sections'
	 * screens need.
	 *
	 * @return string[]
	 */
	public static function capabilities() {
		if ( null !== self::$caps ) {
			return self::$caps;
		}

		$desk = Shop_App::front_desk();

		/**
		 * Filters the capabilities a shop session holds on a shop-floor
		 * request, once its handler has started. Primitive capabilities
		 * only; keep the list as short as the screens need.
		 *
		 * @param string[] $caps Capabilities.
		 */
		$caps = apply_filters( 'fishotel_shop_app_capabilities', array( $desk['capability'] ) );

		$out = array();
		foreach ( is_array( $caps ) ? $caps : array() as $cap ) {
			if ( is_string( $cap ) && '' !== $cap && 'do_not_allow' !== $cap ) {
				$out[] = $cap;
			}
		}

		self::$caps = array_values( array_unique( $out ) );
		return self::$caps;
	}

	/**
	 * The screen's own handler is about to run: from here on the session
	 * holds the shop-floor capabilities.
	 */
	public static function grant() {
		if ( null !== self::$state && self::$state['recognised'] ) {
			self::$granted = true;
		}
	}

	/**
	 * Whether the capabilities are granted yet.
	 *
	 * @return bool
	 */
	public static function granted() {
		return self::$granted;
	}

	/**
	 * Whether a user id is the account of the recognised session.
	 *
	 * @param mixed $user_id User id.
	 * @return bool
	 */
	private static function is_session_user( $user_id ) {
		return null !== self::$state && self::$state['recognised'] && (int) $user_id > 0 && (int) $user_id === self::$state['user_id'];
	}

	/**
	 * map_meta_cap: for the session's account every capability maps to
	 * do_not_allow — which no role, no other filter and no super-admin
	 * status overrides — except the granted shop-floor ones.
	 *
	 * @param string[] $caps    The primitive capabilities WordPress would require.
	 * @param string   $cap     The capability asked about.
	 * @param int      $user_id The user asked about.
	 * @return string[]
	 */
	public static function filter_map_meta_cap( $caps, $cap = '', $user_id = 0 ) {
		if ( ! self::is_session_user( $user_id ) ) {
			return $caps;
		}
		if ( self::$granted && in_array( (string) $cap, self::capabilities(), true ) ) {
			return array( (string) $cap );
		}
		return array( 'do_not_allow' );
	}

	/**
	 * user_has_cap: the session's account holds the granted shop-floor
	 * capabilities and nothing else — not even what its role gives it.
	 *
	 * @param array $allcaps The capabilities the account has.
	 * @param array $caps    The primitive capabilities required.
	 * @param array $args    0: the capability asked about, 1: the user id.
	 * @param mixed $user    WP_User.
	 * @return array
	 */
	public static function filter_user_has_cap( $allcaps, $caps = array(), $args = array(), $user = null ) {
		$user_id = is_object( $user ) && isset( $user->ID ) ? (int) $user->ID : ( isset( $args[1] ) ? (int) $args[1] : 0 );
		if ( ! self::is_session_user( $user_id ) ) {
			return $allcaps;
		}

		$out = array();
		if ( self::$granted ) {
			foreach ( self::capabilities() as $cap ) {
				$out[ $cap ] = true;
			}
		}
		return $out;
	}
}
