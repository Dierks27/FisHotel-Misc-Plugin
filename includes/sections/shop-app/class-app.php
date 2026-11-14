<?php
/**
 * Shop App — the app's own address, and the tank screens inside it.
 *
 * THE APP'S ADDRESS (home_url( '/?fh_shop_app=…' ), handled at wp_loaded
 * so no page query runs for it):
 *   =1         the shell: ONE static page (its CSS and JS inlined, no
 *              name, no tank, no nonce in it — the same bytes for every
 *              visitor), which asks =state what this device is and draws
 *              the keypad or the home screen from the answer. Being
 *              static is what lets the service worker keep a copy.
 *   =manifest  the web app manifest ("FisHotel Shop", standalone, dark).
 *   =sw        the service worker.
 *   =state     JSON, never stored: enrolled or not, locked out or not,
 *              who is unlocked, and — only with a live shop session —
 *              the buildings and tanks for the picker.
 *   =unlock    POST a PIN.        } both need the X-FH-Shop-App header,
 *   =lock      POST: lock.        } which a form on another site cannot send.
 *   =enrol     the one-time enrolment link: a page with one button; the
 *              button (a POST) uses the link up and sets the device cookie.
 *
 * THE LABEL ADDRESS (home_url( '/?fh_front_desk=1&tank=N' ), laminated on
 * every tank): intercept() runs at template_redirect priority 0, just
 * before the Front Desk's own handler, and decides by the device:
 *   - not an enrolled device → it does nothing: exactly as before (a
 *     WordPress login opens the tank, anyone else is sent to log in);
 *   - enrolled, a live shop session → the Gate has made the session's
 *     account the current user; the shop-floor capability is granted now
 *     and the Front Desk renders the tank — its own code — with this
 *     app's bar added to the page (who is unlocked, Lock, back to the
 *     tanks, and the "not saved — Retry" sheet);
 *   - enrolled, no session, a WordPress login that may use the Front
 *     Desk → nothing: exactly as before;
 *   - enrolled, no session → the keypad (the app's address with the tank
 *     number), and after the PIN that tank.
 *
 * The bar is added by an output buffer around the Front Desk's page, so
 * nothing in the Clean Room is changed.
 *
 * respond() builds every answer as an array (status, headers, body), so
 * the tests read them without a server.
 *
 * @package FisHotel\Misc\Sections\Shop_App
 */

namespace FisHotel\Misc\Sections\Shop_App;

defined( 'ABSPATH' ) || exit;

/**
 * Class App
 */
class App {

	const THEME_COLOR = '#0f1720';

	/**
	 * The icons shipped in assets/ (file, pixels, purpose).
	 *
	 * @var array[]
	 */
	const ICONS = array(
		array( 'icon-192.png', 192, 'any' ),
		array( 'icon-512.png', 512, 'any' ),
		array( 'icon-maskable-512.png', 512, 'maskable' ),
	);

	/**
	 * The routes whose answer depends on the device asking.
	 *
	 * @var string[]
	 */
	const PRIVATE_ROUTES = array( 'state', 'unlock', 'lock', 'enrol' );

	/**
	 * What the output buffer adds before </body> ('' = nothing).
	 *
	 * @var string
	 */
	private static $injection = '';

	/**
	 * Register the two hooks. Both leave at once unless the request is
	 * the app's address or a Front Desk address.
	 */
	public static function init() {
		add_action( 'wp_loaded', array( self::class, 'route' ), 20 );
		add_action( 'template_redirect', array( self::class, 'intercept' ), 0 );
	}

	/* ============================================================== */
	/*  The app's address                                              */
	/* ============================================================== */

	/**
	 * Serve the app's address.
	 */
	public static function route() {
		if ( ! isset( $_GET[ Shop_App::QUERY_VAR ] ) || ! is_string( $_GET[ Shop_App::QUERY_VAR ] ) || '' === $_GET[ Shop_App::QUERY_VAR ] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- the app's address; its POSTs are guarded by a header instead of a nonce (there is no login to tie one to).
			return;
		}
		if ( is_admin() || wp_doing_ajax() || ( defined( 'DOING_CRON' ) && DOING_CRON ) || ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) ) {
			return;
		}

		$path = Http::path();
		$home = Shop_App::home_path();
		if ( $path !== $home && $path !== $home . 'index.php' ) {
			return;
		}

		$route = sanitize_key( wp_unslash( $_GET[ Shop_App::QUERY_VAR ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		// Everything but the static shell, the manifest and the worker is
		// about one device: no page cache may keep it.
		if ( in_array( $route, self::PRIVATE_ROUTES, true ) ) {
			Http::never_cache();
		}

		self::send( self::respond( $route, Http::method() ) );
		exit;
	}

	/**
	 * The answer to one of the app's routes.
	 *
	 * @param string $route  manifest|sw|state|unlock|lock|enrol, anything else = the shell.
	 * @param string $method The request method.
	 * @return array{status: int, headers: string[], body: string}
	 */
	public static function respond( $route, $method ) {
		switch ( (string) $route ) {
			case 'manifest':
				return self::manifest_response();
			case 'sw':
				return self::sw_response();
			case 'state':
				return self::json( self::state() );
			case 'unlock':
				return self::unlock_response( $method );
			case 'lock':
				return self::lock_response( $method );
			case 'enrol':
				return self::enrol_response( $method );
			default:
				return self::shell_response();
		}
	}

	/**
	 * Send an answer.
	 *
	 * @param array $response status, headers, body.
	 */
	public static function send( array $response ) {
		status_header( (int) $response['status'] );
		foreach ( (array) $response['headers'] as $line ) {
			Http::header( $line );
		}
		echo $response['body']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built by this class: JSON, or HTML whose every value was escaped in its view.
	}

	/**
	 * The headers of an answer that must never be stored anywhere.
	 *
	 * @param string $type Content type.
	 * @return string[]
	 */
	private static function private_headers( $type ) {
		return array(
			'Content-Type: ' . $type,
			'Cache-Control: no-store, private, max-age=0',
			'Pragma: no-cache',
			'Vary: Cookie',
			'X-Content-Type-Options: nosniff',
			'X-Robots-Tag: noindex, nofollow',
			'X-Frame-Options: DENY',
			'Referrer-Policy: no-referrer',
		);
	}

	/**
	 * The headers of a static answer: revalidated every time, storable.
	 *
	 * @param string $type Content type.
	 * @return string[]
	 */
	private static function static_headers( $type ) {
		return array(
			'Content-Type: ' . $type,
			'Cache-Control: no-cache',
			'X-Content-Type-Options: nosniff',
			'X-Robots-Tag: noindex, nofollow',
			'X-Frame-Options: DENY',
			'Referrer-Policy: same-origin',
		);
	}

	/**
	 * A JSON answer (never stored).
	 *
	 * @param array $data   The data.
	 * @param int   $status HTTP status.
	 * @return array
	 */
	private static function json( array $data, $status = 200 ) {
		return array(
			'status'  => (int) $status,
			'headers' => self::private_headers( 'application/json; charset=utf-8' ),
			'body'    => (string) wp_json_encode( $data ),
		);
	}

	/* -------------------------------------------------------------- */
	/*  The shell, the manifest, the service worker                    */
	/* -------------------------------------------------------------- */

	/**
	 * An inlined file of this section.
	 *
	 * @param string $relative css/app.css, js/app.js, js/scanner.js, js/sw.js.
	 * @return string
	 */
	private static function asset( $relative ) {
		$file = Shop_App::path() . $relative;
		return is_readable( $file ) ? (string) file_get_contents( $file ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- our own file, inlined so the page makes no further requests.
	}

	/**
	 * The app's script as it is inlined: the scanner (js/scanner.js) in
	 * front of js/app.js, which uses it. The QR reader for browsers without
	 * one of their own (js/vendor/jsQR.js) is not in it: the scanner loads
	 * it from its address (urls.qrLib) the first time it is needed.
	 *
	 * @return string
	 */
	public static function script() {
		return self::asset( 'js/scanner.js' ) . "\n" . self::asset( 'js/app.js' );
	}

	/**
	 * A view rendered to a string.
	 *
	 * @param string $view View name.
	 * @param array  $vars Variables for it.
	 * @return string
	 */
	private static function view( $view, array $vars ) {
		// phpcs:ignore WordPress.PHP.DontExtract.extract_extract -- a fixed list of view variables built by this class.
		extract( $vars, EXTR_SKIP );
		ob_start();
		include Shop_App::path() . 'views/' . $view . '.php';
		return (string) ob_get_clean();
	}

	/**
	 * The addresses the scripts use (the same for every visitor).
	 *
	 * @return array<string, string>
	 */
	public static function urls() {
		return array(
			'app'     => Shop_App::app_url(),
			'state'   => Shop_App::route_url( 'state' ),
			'unlock'  => Shop_App::route_url( 'unlock' ),
			'lock'    => Shop_App::route_url( 'lock' ),
			'sw'      => Shop_App::route_url( 'sw' ),
			'swScope' => Shop_App::sw_scope(),
			'label'   => Shop_App::label_url( 0 ),
			'ajax'    => admin_url( 'admin-ajax.php' ),
			'admin'   => admin_url(),
			// Loaded by the scanner only on a browser without a QR reader of its own.
			'qrLib'   => Shop_App::url() . 'js/vendor/jsQR.js?ver=' . rawurlencode( FISHOTEL_MISC_VERSION ),
		);
	}

	/**
	 * The shell's config: nothing in it depends on who asks.
	 *
	 * @return array
	 */
	public static function shell_config() {
		$desk = Shop_App::front_desk();

		return array(
			'mode'     => 'app',
			'urls'     => self::urls(),
			'header'   => Shop_App::HEADER,
			'labelVar' => $desk['query_var'],
			'i18n'     => self::i18n(),
		);
	}

	/**
	 * The shell page.
	 *
	 * @return array
	 */
	public static function shell_response() {
		$body = self::view(
			'shell',
			array(
				'config'   => self::shell_config(),
				'css'      => self::asset( 'css/app.css' ),
				'js'       => self::script(),
				'manifest' => Shop_App::route_url( 'manifest' ),
				'icon'     => Shop_App::url() . 'assets/icon-192.png',
			)
		);

		$headers   = self::static_headers( 'text/html; charset=utf-8' );
		$headers[] = "Content-Security-Policy: frame-ancestors 'none'";

		return array(
			'status'  => 200,
			'headers' => $headers,
			'body'    => $body,
		);
	}

	/**
	 * The web app manifest as an array.
	 *
	 * @return array
	 */
	public static function manifest() {
		$icons = array();
		foreach ( self::ICONS as $icon ) {
			$icons[] = array(
				'src'     => Shop_App::url() . 'assets/' . $icon[0],
				'sizes'   => $icon[1] . 'x' . $icon[1],
				'type'    => 'image/png',
				'purpose' => $icon[2],
			);
		}

		return array(
			'id'               => Shop_App::app_url(),
			'name'             => 'FisHotel Shop',
			'short_name'       => 'FH Shop',
			'description'      => __( 'The fish-room screens of FisHotel: pick or scan a tank, record what happened.', 'fishotel-misc-plugin' ),
			'start_url'        => Shop_App::app_url(),
			// A scope is a path: the app's address and the tank labels both
			// live on the home path, so the scope is the site's home.
			'scope'            => home_url( '/' ),
			'display'          => 'standalone',
			'background_color' => self::THEME_COLOR,
			'theme_color'      => self::THEME_COLOR,
			'lang'             => str_replace( '_', '-', (string) get_locale() ),
			'dir'              => 'ltr',
			'icons'            => $icons,
		);
	}

	/**
	 * The manifest answer.
	 *
	 * @return array
	 */
	public static function manifest_response() {
		return array(
			'status'  => 200,
			'headers' => self::static_headers( 'application/manifest+json; charset=utf-8' ),
			'body'    => (string) wp_json_encode( self::manifest(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ),
		);
	}

	/**
	 * What the service worker is told: the cache's name and the static
	 * shell — the ONLY addresses it may ever keep.
	 *
	 * @return array{cache: string, shell: string, keep: string[]}
	 */
	public static function sw_config() {
		$keep = array( Shop_App::app_url(), Shop_App::route_url( 'manifest' ) );
		foreach ( self::ICONS as $icon ) {
			$keep[] = Shop_App::url() . 'assets/' . $icon[0];
		}

		$stamp = FISHOTEL_MISC_VERSION;
		foreach ( array( 'js/app.js', 'js/scanner.js', 'css/app.css', 'js/sw.js', 'views/shell.php' ) as $file ) {
			$stamp .= '|' . (string) @filemtime( Shop_App::path() . $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a missing file is just an empty stamp.
		}

		return array(
			'cache' => 'fh-shop-app-' . substr( md5( $stamp . '|' . home_url( '/' ) ), 0, 12 ),
			'shell' => Shop_App::app_url(),
			'keep'  => $keep,
		);
	}

	/**
	 * The service worker answer.
	 *
	 * @return array
	 */
	public static function sw_response() {
		$body = '/* FisHotel Shop — service worker. */' . "\n"
			. 'var FH_SW = ' . wp_json_encode( self::sw_config(), JSON_UNESCAPED_SLASHES ) . ";\n"
			. self::asset( 'js/sw.js' );

		return array(
			'status'  => 200,
			'headers' => self::static_headers( 'application/javascript; charset=utf-8' ),
			'body'    => $body,
		);
	}

	/* -------------------------------------------------------------- */
	/*  State                                                          */
	/* -------------------------------------------------------------- */

	/**
	 * An opaque mark of a WordPress account, so a page can tell whether
	 * the account changed between two unlocks without being told which.
	 *
	 * @param int $user_id User id.
	 * @return string
	 */
	public static function account_mark( $user_id ) {
		return substr( hash_hmac( 'sha256', 'account|' . (int) $user_id, Settings::session_key() ), 0, 16 );
	}

	/**
	 * A live session as the scripts see it.
	 *
	 * @param array $session Access::context()['session'].
	 * @param int   $now     Timestamp.
	 * @return array
	 */
	private static function session_public( array $session, $now ) {
		return array(
			'person'  => (string) $session['person']['name'],
			'account' => self::account_mark( $session['user_id'] ),
			'started' => (int) $session['started'],
			'expires' => (int) $session['expires'],
			'mode'    => (string) $session['mode'],
			'length'  => (int) $session['length'],
			'now'     => (int) $now,
		);
	}

	/**
	 * What this device is, for the app (never stored anywhere).
	 *
	 * @return array
	 */
	public static function state() {
		$access = Access::instance();
		$now    = $access->now();
		$ctx    = $access->context();
		$desk   = Shop_App::front_desk();

		$state = array(
			'ok'        => true,
			'now'       => $now,
			'https'     => Shop_App::https_ok(),
			'enrolled'  => (bool) $ctx['device'],
			'frontDesk' => array(
				'available' => (bool) $desk['available'],
				'reason'    => (string) $desk['reason'],
			),
			'adminUrl'  => Shop_App::admin_page_url(),
			'session'   => null,
		);

		// A WordPress login on this device that opens the Front Desk without any PIN: said plainly.
		$state['wpLogin'] = null;
		if ( is_user_logged_in() && current_user_can( $desk['capability'] ) ) {
			$user             = wp_get_current_user();
			$state['wpLogin'] = array(
				'name'      => (string) $user->display_name,
				'logoutUrl' => html_entity_decode( wp_logout_url( Shop_App::app_url() ), ENT_QUOTES ),
				'canManage' => current_user_can( Shop_App::CAPABILITY ),
			);
		}

		// A device that is not enrolled is told nothing more — not even how
		// many digits a PIN has — and costs no query.
		if ( ! $ctx['device'] ) {
			return $state;
		}

		$settings = Settings::get();
		$usable   = 0;
		foreach ( $access->store()->people() as $person ) {
			if ( Pins::usable( $person, $settings['pin_length'] ) ) {
				++$usable;
			}
		}

		$state['device']    = array( 'name' => (string) $ctx['device']['name'] );
		$state['pinLength'] = (int) $settings['pin_length'];
		$state['hasPeople'] = $usable > 0;
		$state['lockout']   = array(
			'locked'  => (bool) $ctx['lockout']['locked'],
			'seconds' => (int) $ctx['lockout']['seconds'],
		);

		if ( ! $ctx['session'] ) {
			return $state;
		}

		$state['session'] = self::session_public( $ctx['session'], $now );
		$state['home']    = $desk['available'] ? self::home() : null;
		$state['links']   = self::home_links();

		if ( $desk['available'] && null === $state['home'] ) {
			$state['frontDesk'] = array(
				'available' => false,
				'reason'    => __( 'The Clean Room tables are missing — deactivate and reactivate the plugin.', 'fishotel-misc-plugin' ),
			);
		}

		return $state;
	}

	/**
	 * The buildings and tanks for the picker — what the Front Desk's own
	 * tank picker shows, read through its screen data. Null when it
	 * cannot be read.
	 *
	 * @return array[]|null
	 */
	public static function home() {
		$desk = '\\FisHotel\\Misc\\Sections\\Clean_Room\\Front_Desk';
		if ( ! class_exists( $desk, false ) || ! method_exists( $desk, 'screen_data' ) ) {
			return null;
		}

		try {
			$screen = call_user_func( array( $desk, 'screen_data' ), 0 );
		} catch ( \Throwable $e ) {
			return null;
		}
		if ( ! is_array( $screen ) || empty( $screen['tablesOk'] ) ) {
			return null;
		}

		return self::home_from_grid( isset( $screen['grid'] ) && is_array( $screen['grid'] ) ? $screen['grid'] : array() );
	}

	/**
	 * The Front Desk's grid (area → buildings → tanks) cut down to what
	 * the picker draws (pure). The tanks are put in number order — the
	 * Front Desk lists empty tanks first, for a move; a picker is read
	 * like the shelves.
	 *
	 * @param array[] $grid Front_Desk::grid().
	 * @return array[]
	 */
	public static function home_from_grid( array $grid ) {
		$areas = array();
		foreach ( $grid as $area ) {
			if ( ! is_array( $area ) ) {
				continue;
			}
			$buildings = array();
			foreach ( isset( $area['buildings'] ) && is_array( $area['buildings'] ) ? $area['buildings'] : array() as $building ) {
				if ( ! is_array( $building ) ) {
					continue;
				}
				$tanks = array();
				foreach ( isset( $building['tanks'] ) && is_array( $building['tanks'] ) ? $building['tanks'] : array() as $tank ) {
					if ( ! is_array( $tank ) || empty( $tank['number'] ) ) {
						continue;
					}
					$tanks[] = array(
						'number' => (int) $tank['number'],
						'size'   => (string) ( $tank['sizeLabel'] ?? '' ),
						'empty'  => ! empty( $tank['empty'] ),
						'count'  => (int) ( $tank['count'] ?? 0 ),
					);
				}
				usort(
					$tanks,
					static function ( $a, $b ) {
						return $a['number'] <=> $b['number'];
					}
				);
				$buildings[] = array(
					'label'  => (string) ( $building['label'] ?? '' ),
					'status' => (string) ( $building['status'] ?? '' ),
					'inQt'   => (int) ( $building['inQt'] ?? 0 ),
					'tanks'  => $tanks,
				);
			}
			$areas[] = array(
				'label'     => (string) ( $area['label'] ?? '' ),
				'buildings' => $buildings,
			);
		}
		return $areas;
	}

	/**
	 * The buttons under the tanks on the app's home screen: the Front
	 * Desk's day's list and dose calculator, then other sections' screens.
	 *
	 * @return array[] label, url.
	 */
	public static function home_links() {
		// The Front Desk's own two screens that belong to no one tank: the
		// day's list and the dose calculator (their addresses are on the
		// Gate's list, so they open with the PIN).
		$links = array();
		$desk  = Shop_App::front_desk();
		if ( $desk['available'] ) {
			$links[] = array(
				'label' => __( 'Today\'s list', 'fishotel-misc-plugin' ),
				'url'   => Shop_App::label_url( 0 ) . '&list=1',
			);
			$links[] = array(
				'label' => __( 'Dose calculator', 'fishotel-misc-plugin' ),
				'url'   => Shop_App::label_url( 0 ) . '&calc=1',
			);
		}

		/**
		 * Filters the extra buttons on the Shop App's home screen: a
		 * section that adds a shop-floor screen
		 * (fishotel_shop_app_allowed_requests) lists it here. Each entry
		 * is array( 'label' => 'Packing', 'url' => home_url( '/?…' ) );
		 * an address outside this site is dropped.
		 *
		 * @param array[] $links The buttons (the day's list and the dose calculator first).
		 */
		$links = apply_filters( 'fishotel_shop_app_home_links', $links );

		$out  = array();
		$home = home_url( '/' );
		foreach ( is_array( $links ) ? $links : array() as $link ) {
			if ( ! is_array( $link ) || empty( $link['label'] ) || empty( $link['url'] ) || ! is_scalar( $link['label'] ) || ! is_string( $link['url'] ) ) {
				continue;
			}
			if ( 0 !== strpos( $link['url'], $home ) ) {
				continue;
			}
			$label = (string) substr( sanitize_text_field( (string) $link['label'] ), 0, 40 );
			if ( '' === $label ) {
				continue;
			}
			$out[] = array(
				'label' => $label,
				'url'   => esc_url_raw( $link['url'] ),
			);
		}
		return $out;
	}

	/* -------------------------------------------------------------- */
	/*  Unlock, lock                                                   */
	/* -------------------------------------------------------------- */

	/**
	 * A POST of the app must be a POST and carry the app's header.
	 *
	 * @param string $method The request method.
	 * @return array|null A refusal, or null when it may go on.
	 */
	private static function refuse_post( $method ) {
		if ( 'POST' !== $method || ! Http::has_app_header() ) {
			return self::json(
				array(
					'ok'   => false,
					'code' => 'bad_request',
				),
				400
			);
		}
		return null;
	}

	/**
	 * POST a PIN.
	 *
	 * @param string $method The request method.
	 * @return array
	 */
	public static function unlock_response( $method ) {
		$refusal = self::refuse_post( $method );
		if ( $refusal ) {
			return $refusal;
		}

		$pin    = isset( $_POST['pin'] ) && is_string( $_POST['pin'] ) ? wp_unslash( $_POST['pin'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- guarded by the app header; only ever compared, never stored or shown.
		$result = Access::instance()->unlock( $pin );

		$out = array(
			'ok'   => (bool) $result['ok'],
			'code' => (string) $result['code'],
		);
		if ( isset( $result['seconds'] ) ) {
			$out['seconds'] = (int) $result['seconds'];
		}
		if ( isset( $result['left'] ) ) {
			$out['left'] = (int) $result['left'];
		}
		$out['state'] = self::state();

		return self::json( $out );
	}

	/**
	 * POST: lock.
	 *
	 * @param string $method The request method.
	 * @return array
	 */
	public static function lock_response( $method ) {
		$refusal = self::refuse_post( $method );
		if ( $refusal ) {
			return $refusal;
		}

		Access::instance()->lock();

		return self::json(
			array(
				'ok'    => true,
				'code'  => 'ok',
				'state' => self::state(),
			)
		);
	}

	/* -------------------------------------------------------------- */
	/*  The one-time enrolment link                                    */
	/* -------------------------------------------------------------- */

	/**
	 * The enrolment link: GET shows one button and uses nothing up; the
	 * button's POST uses the link up, sets the device cookie and goes to
	 * the app.
	 *
	 * @param string $method The request method.
	 * @return array
	 */
	public static function enrol_response( $method ) {
		// phpcs:disable WordPress.Security.NonceVerification -- a one-time token IS the credential here; there is no login to tie a nonce to.
		$source = 'POST' === $method ? $_POST : $_GET;
		$token  = isset( $source['k'] ) && is_string( $source['k'] ) ? wp_unslash( $source['k'] ) : '';
		// phpcs:enable WordPress.Security.NonceVerification

		$headers = self::private_headers( 'text/html; charset=utf-8' );
		$access  = Access::instance();
		$vars    = array(
			'css'    => self::asset( 'css/app.css' ),
			'action' => Shop_App::route_url( 'enrol' ),
			'token'  => Pins::is_enrol_token( $token ) ? $token : '',
			'app'    => Shop_App::app_url(),
			'state'  => 'expired',
			'name'   => '',
		);

		if ( ! Shop_App::https_ok() ) {
			$vars['state'] = 'https';
			return array(
				'status'  => 200,
				'headers' => $headers,
				'body'    => self::view( 'enrol', $vars ),
			);
		}

		if ( 'POST' === $method ) {
			$device = $access->claim_link( $token );
			if ( $device ) {
				$headers[] = 'Location: ' . Shop_App::app_url();
				return array(
					'status'  => 303,
					'headers' => $headers,
					'body'    => '',
				);
			}
			return array(
				'status'  => 410,
				'headers' => $headers,
				'body'    => self::view( 'enrol', $vars ),
			);
		}

		$info = $access->link_info( $token );
		if ( $info ) {
			$vars['state'] = 'confirm';
			$vars['name']  = $info['name'];
		}

		return array(
			'status'  => $info ? 200 : 410,
			'headers' => $headers,
			'body'    => self::view( 'enrol', $vars ),
		);
	}

	/* ============================================================== */
	/*  The label address                                              */
	/* ============================================================== */

	/**
	 * Whether the request's address is exactly this one.
	 *
	 * @param string $url An address of this site.
	 * @return bool
	 */
	private static function is_current_url( $url ) {
		$parts = wp_parse_url( $url );
		$want  = ( isset( $parts['path'] ) ? $parts['path'] : '/' ) . ( isset( $parts['query'] ) ? '?' . $parts['query'] : '' );
		return isset( $_SERVER['REQUEST_URI'] ) && is_string( $_SERVER['REQUEST_URI'] ) && $_SERVER['REQUEST_URI'] === $want;
	}

	/**
	 * template_redirect, priority 0 — just before the Front Desk's own
	 * handler (priority 1). See the file header for the cases.
	 */
	public static function intercept() {
		// A shop-floor page opened with a live shop session: its handler is next.
		if ( Gate::is_shop_session() ) {
			$rule   = Gate::rule();
			$person = Gate::person();
			if ( $rule && 'page' === $rule['type'] && $person && (int) get_current_user_id() === (int) $person['user_id'] ) {
				Gate::grant();
				self::start_bar();
			}
			return;
		}

		$desk = Shop_App::front_desk();
		if ( ! $desk['available'] ) {
			return;
		}

		// The Front Desk's own test for "this is my page".
		$var  = $desk['query_var'];
		$flag = (string) get_query_var( $var, '' );
		if ( '' === $flag && isset( $_GET[ $var ] ) && is_scalar( $_GET[ $var ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a read-only page switch.
			$flag = sanitize_text_field( wp_unslash( (string) $_GET[ $var ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
		if ( '' === $flag || '0' === $flag ) {
			return;
		}

		// No device cookie → no query, and nothing changes: exactly as before.
		$ctx = Access::instance()->context();
		if ( ! $ctx['device'] ) {
			return;
		}

		$tank = isset( $_GET['tank'] ) && is_scalar( $_GET['tank'] ) ? absint( wp_unslash( $_GET['tank'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( $ctx['session'] ) {
			// Unlocked, but this is not the plain label address (something
			// was added to it): go to the plain one, which the Gate knows.
			$plain = Shop_App::label_url( $tank );
			if ( 'GET' === Http::method() && ! self::is_current_url( $plain ) ) {
				nocache_headers();
				wp_safe_redirect( $plain );
				exit;
			}
			return;
		}

		// A normal WordPress login that may use the Front Desk: exactly as before.
		if ( is_user_logged_in() && current_user_can( $desk['capability'] ) ) {
			return;
		}

		// An enrolled device and nobody unlocked: the keypad, then that tank.
		nocache_headers();
		wp_safe_redirect( Shop_App::app_url( $tank ) );
		exit;
	}

	/**
	 * Wrap the page that is about to be rendered so this app's bar is
	 * added to it. The addition is built now — an output handler must not
	 * do more than paste it in.
	 */
	private static function start_bar() {
		$ctx = Access::instance()->context();
		if ( ! $ctx['device'] || ! $ctx['session'] ) {
			return;
		}

		$desk     = Shop_App::front_desk();
		$settings = Settings::get();
		$config   = array(
			'mode'      => 'frontdesk',
			'urls'      => self::urls(),
			'header'    => Shop_App::HEADER,
			'labelVar'  => $desk['query_var'],
			'device'    => (string) $ctx['device']['name'],
			'session'   => self::session_public( $ctx['session'], Access::instance()->now() ),
			'pinLength' => (int) $settings['pin_length'],
			'write'     => (string) $desk['write'],
			'actions'   => array_values( $desk['actions'] ),
			'saveField' => Saves::FIELD,
			'water'     => self::water_config(),
			'i18n'      => self::i18n(),
		);

		self::$injection = '<style id="fh-sa-style">' . str_replace( '</style', '<\/style', self::asset( 'css/app.css' ) ) . '</style>'
			. '<script>window.fhShopApp = ' . wp_json_encode( $config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ) . ';</script>'
			. '<script>' . str_replace( '</script', '<\/script', self::script() ) . '</script>';

		ob_start( array( self::class, 'add_bar' ) );
	}

	/**
	 * What the bar needs to show, at the top of a tank screen, what that
	 * tank's water needs today: on a tank screen (a label scan, or a tank
	 * picked in the app) the script cuts the Front Desk's own day's list
	 * (screen.todoList, already on the page) down to the tank's building —
	 * one shared water system — or, for a temporary tank, to that tank,
	 * and links to the full list. Not on the picker, the list itself or
	 * the calculator.
	 *
	 * @return array{show: bool, listUrl: string}
	 */
	public static function water_config() {
		$tank = isset( $_GET['tank'] ) && is_scalar( $_GET['tank'] ) ? absint( wp_unslash( $_GET['tank'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a read-only page switch.
		$list = isset( $_GET['list'] ) && '1' === $_GET['list']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput -- compared with a literal.
		$calc = isset( $_GET['calc'] ) && '1' === $_GET['calc']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput -- compared with a literal.

		return array(
			'show'    => $tank > 0 && ! $list && ! $calc,
			'listUrl' => Shop_App::label_url( 0 ) . '&list=1',
		);
	}

	/**
	 * Output handler: paste the bar in before the last </body>.
	 *
	 * @param string $buffer The page.
	 * @return string
	 */
	public static function add_bar( $buffer ) {
		if ( '' === self::$injection ) {
			return $buffer;
		}
		$at = strripos( (string) $buffer, '</body>' );
		if ( false === $at ) {
			return $buffer;
		}
		return substr( $buffer, 0, $at ) . self::$injection . substr( $buffer, $at );
	}

	/**
	 * Start over (tests).
	 */
	public static function reset() {
		self::$injection = '';
	}

	/* ============================================================== */
	/*  Strings                                                        */
	/* ============================================================== */

	/**
	 * The strings the scripts show.
	 *
	 * @return array<string, string>
	 */
	public static function i18n() {
		return array(
			'title'         => 'FisHotel Shop',
			'enterPin'      => __( 'Enter your PIN', 'fishotel-misc-plugin' ),
			'wrongPin'      => __( 'Wrong PIN', 'fishotel-misc-plugin' ),
			/* translators: %d: tries left */
			'triesLeft'     => __( '%d tries left', 'fishotel-misc-plugin' ),
			'oneTryLeft'    => __( '1 try left', 'fishotel-misc-plugin' ),
			/* translators: %s: time left, like 4:59 */
			'lockedFor'     => __( 'This device is locked. Try again in %s.', 'fishotel-misc-plugin' ),
			'checking'      => __( 'Checking…', 'fishotel-misc-plugin' ),
			'delete'        => __( 'Delete', 'fishotel-misc-plugin' ),
			'clear'         => __( 'Clear', 'fishotel-misc-plugin' ),
			'lock'          => __( 'Lock', 'fishotel-misc-plugin' ),
			'tanks'         => __( 'Tanks', 'fishotel-misc-plugin' ),
			'back'          => __( 'Back', 'fishotel-misc-plugin' ),
			'pickBuilding'  => __( 'Pick a building', 'fishotel-misc-plugin' ),
			'pickTank'      => __( 'Pick a tank', 'fishotel-misc-plugin' ),
			'scan'          => __( 'Scan a tank', 'fishotel-misc-plugin' ),
			'scanStarting'  => __( 'Starting the camera…', 'fishotel-misc-plugin' ),
			'scanHint'      => __( 'Hold the tank\'s label inside the square.', 'fishotel-misc-plugin' ),
			'scanNotLabel'  => __( 'That is not a FisHotel tank label.', 'fishotel-misc-plugin' ),
			'scanOtherSite' => __( 'That code is for another website. It was not opened.', 'fishotel-misc-plugin' ),
			'scanBadTank'   => __( 'That label\'s tank number is not one the shop uses.', 'fishotel-misc-plugin' ),
			'scanNoCamera'  => __( 'The camera could not be opened. Type the tank number instead.', 'fishotel-misc-plugin' ),
			'scanDenied'    => __( 'The camera is not allowed for this app. Type the tank number instead — or allow the camera in the browser\'s settings.', 'fishotel-misc-plugin' ),
			'scanNoReader'  => __( 'The code reader could not be loaded. Type the tank number instead.', 'fishotel-misc-plugin' ),
			'typeTitle'     => __( 'Type the tank number', 'fishotel-misc-plugin' ),
			'typeHint'      => __( 'It opens at the third digit.', 'fishotel-misc-plugin' ),
			'typeBad'       => __( 'Tank numbers have three digits, like 121 or 901.', 'fishotel-misc-plugin' ),
			'cancel'        => __( 'Cancel', 'fishotel-misc-plugin' ),
			'empty'         => __( 'empty', 'fishotel-misc-plugin' ),
			'comingSoon'    => __( 'coming soon', 'fishotel-misc-plugin' ),
			/* translators: %d: number of tanks */
			'tankCount'     => __( '%d tanks', 'fishotel-misc-plugin' ),
			/* translators: %d: groups in quarantine */
			'inQt'          => __( 'QT %d', 'fishotel-misc-plugin' ),
			'install'       => __( 'Install app', 'fishotel-misc-plugin' ),
			'needsHttps'    => __( 'The Shop App needs HTTPS. Open it at an https:// address.', 'fishotel-misc-plugin' ),
			'notEnrolled'   => __( 'This device is not set up for the Shop App.', 'fishotel-misc-plugin' ),
			'notEnrolledHow' => __( 'A shop manager sets it up under FisHotel Tools → Shop App: on this device with "Set up this device", or with a one-time link from a computer.', 'fishotel-misc-plugin' ),
			'setUpHere'     => __( 'Set up with a manager login', 'fishotel-misc-plugin' ),
			'openFrontDesk' => __( 'Open the Front Desk with a WordPress login', 'fishotel-misc-plugin' ),
			'noPeople'      => __( 'Nobody has a PIN yet. A shop manager adds people under FisHotel Tools → Shop App → People.', 'fishotel-misc-plugin' ),
			/* translators: %s: a WordPress user's name */
			'wpLogin'       => __( 'This device is also logged in to WordPress as %s, so the tank labels open without a PIN.', 'fishotel-misc-plugin' ),
			'wpLogout'      => __( 'Log out of WordPress here', 'fishotel-misc-plugin' ),
			'offline'       => __( 'No connection.', 'fishotel-misc-plugin' ),
			'retry'         => __( 'Retry', 'fishotel-misc-plugin' ),
			'error'         => __( 'Something went wrong. Try again.', 'fishotel-misc-plugin' ),
			'noFrontDesk'   => __( 'There are no tank screens to open.', 'fishotel-misc-plugin' ),
			'noTanks'       => __( 'No buildings or tanks yet. The layout is set under FisHotel Tools → Clean Room → Settings.', 'fishotel-misc-plugin' ),
			'notSaved'      => __( 'MAY NOT BE SAVED', 'fishotel-misc-plugin' ),
			'notSavedWhy'   => __( 'No answer came back — the connection may be down — so it may not have been saved. What you entered is still here. Retry sends the same entry again; it is written once.', 'fishotel-misc-plugin' ),
			'notSavedServer' => __( 'The site had a problem and may not have saved it. What you entered is still here. Retry sends the same entry again; it is written once.', 'fishotel-misc-plugin' ),
			'notSavedClosed' => __( 'Not confirmed as saved. Check the connection, then save again — the same entry is written once.', 'fishotel-misc-plugin' ),
			'lostTitle'     => __( 'NOT SAVED', 'fishotel-misc-plugin' ),
			/* translators: %s: the person who unlocked */
			'lostWhy'       => __( 'The entry that was waiting was made before %s unlocked. It was not saved — enter it again.', 'fishotel-misc-plugin' ),
			'lostOk'        => __( 'OK — enter it again', 'fishotel-misc-plugin' ),
			'keepEditing'   => __( 'Back to the entry', 'fishotel-misc-plugin' ),
			'saving'        => __( 'Saving…', 'fishotel-misc-plugin' ),
			'sessionEnded'  => __( 'Locked. Enter your PIN to go on.', 'fishotel-misc-plugin' ),
			'waterTitle'    => __( 'Today for this water', 'fishotel-misc-plugin' ),
			'waterTempTitle' => __( 'Today for this tank', 'fishotel-misc-plugin' ),
			/* translators: 1: things left, 2: things in all */
			'waterLeft'     => __( '%1$d of %2$d still to do', 'fishotel-misc-plugin' ),
			'waterDone'     => __( 'All done for today', 'fishotel-misc-plugin' ),
			'waterNothing'  => __( 'Nothing due today', 'fishotel-misc-plugin' ),
			'waterAll'      => __( 'Full list for today ›', 'fishotel-misc-plugin' ),
			/* translators: %s: the person whose entry is waiting */
			'sessionEndedSave' => __( 'Locked before it was saved. %s: enter your PIN and it is saved.', 'fishotel-misc-plugin' ),
		);
	}
}
