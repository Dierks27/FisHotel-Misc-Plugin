<?php
/**
 * Shop App section — "FisHotel Shop", an installable web app for the wet
 * room: a phone or a tablet at a tank opens on a keypad with very large
 * buttons, a PIN starts a two-hour shop session for that person on that
 * device, and the Clean Room's Front Desk tank screens open inside it —
 * the same code, not a copy.
 *
 * WHY this exists: the tank work is done standing at a tank with wet
 * hands. A WordPress login (a username, a long password, a keyboard) is
 * the wrong lock for that; a four-digit PIN on a device the shop has
 * enrolled is the right one. And a shop session is NOT a WordPress login:
 * it opens the shop-floor screens and nothing else.
 *
 * It is not a native app from a store. On Android the browser's "Install
 * app" gives it its own icon and a full-screen window; on an iPhone "Add
 * to Home Screen" does the same; it updates with the site.
 *
 * The app's address works with any permalink setting:
 *   home_url( '/?fh_shop_app=1' )         the app (a static shell)
 *   home_url( '/?fh_shop_app=manifest' )  the web app manifest
 *   home_url( '/?fh_shop_app=sw' )        the service worker (scope: the
 *                                         app's own address, never the
 *                                         shop front or a tank screen)
 *   home_url( '/?fh_shop_app=state' )     who / what this device is (JSON)
 *   home_url( '/?fh_shop_app=unlock' )    POST a PIN
 *   home_url( '/?fh_shop_app=lock' )      POST: end the shop session
 *   home_url( '/?fh_shop_app=enrol&k=…' ) the one-time enrolment link
 *
 * Architecture:
 *   - class-store.php     the ONLY file that touches $wpdb: devices, people,
 *                         the append-only access log and the remembered
 *                         saves, via dbDelta gated by the autoloaded
 *                         fishotel_shopapp_db_version.
 *   - class-pins.php      PURE: PIN format, hashing (per-person salt + site
 *                         secret), the match that never stops early, the
 *                         uniqueness check, the device / enrolment tokens.
 *   - class-lockout.php   PURE: five wrong PINs, five minutes, doubling.
 *   - class-session.php   PURE: the signed session cookie, both ways of
 *                         counting its length, when to renew it.
 *   - class-settings.php  the options (session length and mode, PIN
 *                         length) and the site secret.
 *   - class-http.php      cookies (Secure, HttpOnly, SameSite=Lax) and
 *                         headers behind senders a test can replace.
 *   - class-people.php    the person form's rules, the account a person's
 *                         work is recorded under, the "Shop staff" account
 *                         and its role without wp-admin access.
 *   - class-access.php    devices and sessions: who this device is, enrol
 *                         (here, or by a one-time link), revoke, unlock,
 *                         lock, lockouts, the access log.
 *   - class-gate.php      THE GATE: a shop session is recognised only on
 *                         the shop-floor requests, with exactly the
 *                         Front Desk's capability, only while its handler
 *                         runs.
 *   - class-saves.php     a Front Desk write that is sent twice is written
 *                         once (the seam an offline queue will need).
 *   - class-app.php       the app's address, the label address on an
 *                         enrolled device, the bar a tank screen gets
 *                         inside the app.
 *   - class-admin.php     FisHotel Tools → Shop App: Devices, People,
 *                         Settings, Log, Install.
 *
 * The file uses bracketed namespaces because the helper other sections
 * may call lives in the global namespace.
 *
 * @package FisHotel\Misc\Sections\Shop_App
 */

namespace FisHotel\Misc\Sections\Shop_App {

	defined( 'ABSPATH' ) || exit;

	/**
	 * Class Shop_App
	 */
	class Shop_App {

		/**
		 * Admin submenu slug.
		 *
		 * @var string
		 */
		const MENU_SLUG = 'fishotel-misc-shop-app';

		/**
		 * Capability for the admin page and every write on it (a shop
		 * manager or an administrator).
		 *
		 * @var string
		 */
		const CAPABILITY = 'manage_woocommerce';

		/**
		 * The query parameter of the app's address.
		 *
		 * @var string
		 */
		const QUERY_VAR = 'fh_shop_app';

		/**
		 * The role of the shared "Shop staff" account: no capability at
		 * all, so no wp-admin.
		 *
		 * @var string
		 */
		const ROLE = 'fishotel_shop_staff';

		/**
		 * The login of the shared "Shop staff" account.
		 *
		 * @var string
		 */
		const STAFF_LOGIN = 'fishotel-shop-staff';

		/**
		 * The source name in the shared Health log.
		 *
		 * @var string
		 */
		const LOG_SOURCE = 'shop-app';

		/**
		 * The header every POST of the app carries. A form on another
		 * site cannot send it, so the app's own endpoints need no nonce.
		 *
		 * @var string
		 */
		const HEADER = 'X-FH-Shop-App';

		/**
		 * Section base path.
		 *
		 * @return string
		 */
		public static function path() {
			return __DIR__ . '/';
		}

		/**
		 * Section base URL.
		 *
		 * @return string
		 */
		public static function url() {
			return FISHOTEL_MISC_URL . 'includes/sections/shop-app/';
		}

		/**
		 * Section metadata for the Section Manager.
		 *
		 * @return array{name: string, description: string, icon: string}
		 */
		public static function get_section_info() {
			return array(
				'name'        => __( 'Shop App', 'fishotel-misc-plugin' ),
				'description' => __( 'FisHotel Shop — an installable web app for the wet room: enrolled phones and tablets open on a keypad with very large buttons, a PIN starts a two-hour shop session for that person, and the Clean Room tank screens open inside it. A shop session is not a WordPress login: it reaches the shop-floor screens and nothing else. Devices, people and PINs, lockouts and an access log under FisHotel Tools → Shop App.', 'fishotel-misc-plugin' ),
				'icon'        => 'dashicons-smartphone',
			);
		}

		/**
		 * Load every class file explicitly — nothing here depends on the
		 * autoloader's directory mapping.
		 */
		public static function load() {
			require_once __DIR__ . '/class-pins.php';
			require_once __DIR__ . '/class-lockout.php';
			require_once __DIR__ . '/class-session.php';
			require_once __DIR__ . '/class-settings.php';
			require_once __DIR__ . '/class-http.php';
			require_once __DIR__ . '/class-store.php';
			require_once __DIR__ . '/class-people.php';
			require_once __DIR__ . '/class-access.php';
			require_once __DIR__ . '/class-gate.php';
			require_once __DIR__ . '/class-saves.php';
			require_once __DIR__ . '/class-app.php';
		}

		/**
		 * Boot the section when it is enabled. The tables install on the
		 * first boot only — the version gate is an autoloaded option, so
		 * every later request pays no query here — and an ordinary
		 * front-end request costs nothing more than a few hook
		 * registrations: every callback leaves at once unless the request
		 * carries this section's cookies or its address.
		 */
		public static function boot() {
			self::load();

			try {
				Store::maybe_install( is_admin() );
			} catch ( \Throwable $e ) {
				unset( $e );
			}

			Gate::init();
			App::init();
			People::init();

			if ( is_admin() ) {
				require_once __DIR__ . '/class-admin.php';

				$admin = new Admin();
				$admin->init();
			}
		}

		/**
		 * Register the admin submenu.
		 */
		public static function register_menu() {
			self::load();
			require_once __DIR__ . '/class-admin.php';

			add_submenu_page(
				'fishotel-misc',
				__( 'Shop App', 'fishotel-misc-plugin' ),
				__( 'Shop App', 'fishotel-misc-plugin' ),
				self::CAPABILITY,
				self::MENU_SLUG,
				array( new Admin(), 'render_page' )
			);
		}

		/**
		 * Plugin-activation callback: install the tables (forced past the
		 * version gate so a deactivate / reactivate recreates a dropped
		 * one).
		 */
		public static function activate() {
			self::load();

			try {
				Store::install( true );
			} catch ( \Throwable $e ) {
				unset( $e );
			}
		}

		/**
		 * Plugin-deactivation callback: nothing to tear down — no cron, no
		 * cache. Devices, people, the log and the settings are kept.
		 */
		public static function deactivate() {
		}

		/* ========================================================== */
		/*  Addresses                                                  */
		/* ========================================================== */

		/**
		 * The app's address, optionally bound for a tank after the PIN.
		 *
		 * @param int $tank_number Tank number (0 = the app's home).
		 * @return string
		 */
		public static function app_url( $tank_number = 0 ) {
			$query = self::QUERY_VAR . '=1';
			if ( (int) $tank_number > 0 ) {
				$query .= '&tank=' . (int) $tank_number;
			}
			return home_url( '/?' . $query );
		}

		/**
		 * One of the app's own endpoints.
		 *
		 * @param string $route manifest|sw|state|unlock|lock|enrol.
		 * @return string
		 */
		public static function route_url( $route ) {
			return home_url( '/?' . self::QUERY_VAR . '=' . rawurlencode( (string) $route ) );
		}

		/**
		 * The service worker's scope: the app's own address and nothing
		 * else (a scope is a prefix of the whole address, so the shop
		 * front and the tank screens are outside it).
		 *
		 * @return string
		 */
		public static function sw_scope() {
			return home_url( '/?' . self::QUERY_VAR . '=' );
		}

		/**
		 * The admin page.
		 *
		 * @param array $args Extra query args.
		 * @return string
		 */
		public static function admin_page_url( array $args = array() ) {
			return add_query_arg( array_merge( array( 'page' => self::MENU_SLUG ), $args ), admin_url( 'admin.php' ) );
		}

		/**
		 * The path of the site's home address ("/" or "/shop/").
		 *
		 * @return string
		 */
		public static function home_path() {
			$path = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );
			return '' === $path ? '/' : $path;
		}

		/**
		 * Whether the site is served over HTTPS. The cookies are Secure,
		 * so without it no device can be enrolled; behind a proxy that
		 * terminates TLS is_ssl() may be false although the address is
		 * https, hence the second test.
		 *
		 * @return bool
		 */
		public static function https_ok() {
			$ok = is_ssl() || 'https' === wp_parse_url( home_url( '/' ), PHP_URL_SCHEME );

			/**
			 * Filters whether the Shop App treats the site as served over
			 * HTTPS (for a development site on localhost).
			 *
			 * @param bool $ok Whether HTTPS is in use.
			 */
			return (bool) apply_filters( 'fishotel_shop_app_https_ok', $ok );
		}

		/* ========================================================== */
		/*  The Clean Room's Front Desk, when it is there              */
		/* ========================================================== */

		/**
		 * What this section knows of the Clean Room's Front Desk, reached
		 * only through class_exists / method_exists — the Clean Room may
		 * be absent, older or switched off, and then the app says so.
		 *
		 * @return array{available: bool, reason: string, query_var: string, capability: string, nonce: string, actions: string[], write: string}
		 */
		public static function front_desk() {
			$room = '\\FisHotel\\Misc\\Sections\\Clean_Room\\Clean_Room';
			$desk = '\\FisHotel\\Misc\\Sections\\Clean_Room\\Front_Desk';

			$enabled = in_array( 'clean-room', (array) get_option( 'fishotel_misc_enabled_sections', array() ), true );
			$loaded  = class_exists( $room, false ) && class_exists( $desk, false )
				&& method_exists( $desk, 'screen_data' ) && method_exists( $desk, 'maybe_render' );

			$reason = '';
			if ( ! $enabled ) {
				$reason = __( 'The Clean Room section is switched off, so there are no tank screens to open. Switch it on under FisHotel Tools.', 'fishotel-misc-plugin' );
			} elseif ( ! $loaded ) {
				$reason = __( 'The Clean Room section on this site has no Front Desk this app can open.', 'fishotel-misc-plugin' );
			}

			return array(
				'available'  => $enabled && $loaded,
				'reason'     => $reason,
				'query_var'  => class_exists( $room, false ) && defined( $room . '::QUERY_VAR' ) ? (string) constant( $room . '::QUERY_VAR' ) : 'fh_front_desk',
				'capability' => class_exists( $room, false ) && defined( $room . '::CAPABILITY' ) ? (string) constant( $room . '::CAPABILITY' ) : 'manage_woocommerce',
				'nonce'      => class_exists( $desk, false ) && defined( $desk . '::NONCE' ) ? (string) constant( $desk . '::NONCE' ) : 'fishotel_cleanroom_front',
				// The tank page's AJAX: one action for every write, three that only read.
				'actions'    => array( 'fishotel_cleanroom_act', 'fishotel_cleanroom_parse', 'fishotel_cleanroom_suggest', 'fishotel_cleanroom_screen' ),
				'write'      => 'fishotel_cleanroom_act',
			);
		}

		/**
		 * The address the tank labels encode — the Clean Room's own
		 * helper when it is there, the same address otherwise.
		 *
		 * @param int $tank_number Tank number (0 = the Front Desk's tank picker).
		 * @return string
		 */
		public static function label_url( $tank_number = 0 ) {
			$room = '\\FisHotel\\Misc\\Sections\\Clean_Room\\Clean_Room';
			if ( class_exists( $room, false ) && method_exists( $room, 'front_desk_url' ) ) {
				return (string) call_user_func( array( $room, 'front_desk_url' ), (int) $tank_number );
			}

			$desk  = self::front_desk();
			$query = $desk['query_var'] . '=1';
			if ( (int) $tank_number > 0 ) {
				$query .= '&tank=' . (int) $tank_number;
			}
			return home_url( '/?' . $query );
		}

		/**
		 * The person unlocked on this request, or null: only inside a
		 * shop-floor request made with a live shop session (see
		 * fishotel_shop_app_person()).
		 *
		 * @return array{id: int, name: string, user_id: int, device_id: int, device: string}|null
		 */
		public static function person() {
			if ( ! class_exists( Gate::class, false ) ) {
				return null;
			}
			return Gate::person();
		}
	}
}

namespace {

	if ( ! function_exists( 'fishotel_shop_app_person' ) ) {
		/**
		 * Who is working on this request through the Shop App: the person
		 * whose PIN unlocked the device, the WordPress account their work
		 * is recorded under and the device — or null when the request was
		 * not made with a shop session (a normal WordPress login, a
		 * visitor, cron).
		 *
		 * WordPress's current user is that account for the length of the
		 * request, so get_current_user_id() already records the right
		 * actor; this adds the person's own name, which matters when
		 * several people share the "Shop staff" account.
		 *
		 * This function exists only while the Misc plugin is active, so
		 * guard it with function_exists(); while the Shop App section is
		 * switched off it answers null.
		 *
		 * @return array{id: int, name: string, user_id: int, device_id: int, device: string}|null
		 */
		function fishotel_shop_app_person() {
			return \FisHotel\Misc\Sections\Shop_App\Shop_App::person();
		}
	}
}
