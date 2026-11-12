<?php
/**
 * Shop App admin — FisHotel Tools → Shop App.
 *
 * Tabs:
 *   Devices   set up this device (while logged in to WordPress on it), or
 *             make a one-time link with a QR code for another one; the
 *             list of devices (name, enrolled by, last used, who is
 *             unlocked, locked out or not) with Revoke and Clear lockout.
 *   People    a name, a PIN, the account their work is recorded under,
 *             on or off.
 *   Settings  how long a shop session lasts, where the count starts, how
 *             many digits a PIN has.
 *   Log       the append-only access log.
 *   Install   the app's address and how to install it on Android and on
 *             an iPhone.
 *
 * Every write is an admin-post handler (the one-time link: admin-ajax)
 * that checks a nonce and manage_woocommerce — a shop manager or an
 * administrator — and refuses a shop session outright: PINs, devices and
 * settings can only ever be changed with a WordPress login. Each handler
 * calls a *_request() method that takes its input as an array and answers
 * with an array, so the tests run them without a server.
 *
 * The views escape every value; a PIN is never shown, stored or put into
 * a URL.
 *
 * @package FisHotel\Misc\Sections\Shop_App
 */

namespace FisHotel\Misc\Sections\Shop_App;

defined( 'ABSPATH' ) || exit;

/**
 * Class Admin
 */
class Admin {

	/**
	 * Nonce action of every form on the page and of the link AJAX.
	 *
	 * @var string
	 */
	const NONCE = 'fishotel_shop_app_admin';

	const TABS = array( 'devices', 'people', 'settings', 'log', 'install' );

	const LOG_PER_PAGE = 50;

	/**
	 * Register hooks.
	 */
	public function init() {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ), 20 );

		add_action( 'admin_post_fishotel_shop_app_enrol_here', array( $this, 'handle_enrol_here' ) );
		add_action( 'admin_post_fishotel_shop_app_revoke', array( $this, 'handle_revoke' ) );
		add_action( 'admin_post_fishotel_shop_app_clear_lockout', array( $this, 'handle_clear_lockout' ) );
		add_action( 'admin_post_fishotel_shop_app_person', array( $this, 'handle_person' ) );
		add_action( 'admin_post_fishotel_shop_app_person_off', array( $this, 'handle_person_off' ) );
		add_action( 'admin_post_fishotel_shop_app_settings', array( $this, 'handle_settings' ) );
		add_action( 'wp_ajax_fishotel_shop_app_link', array( $this, 'ajax_link' ) );
	}

	/**
	 * The page URL.
	 *
	 * @param string $tab   Tab.
	 * @param array  $extra Extra query args.
	 * @return string
	 */
	public static function page_url( $tab = 'devices', array $extra = array() ) {
		$args = array();
		if ( 'devices' !== $tab && in_array( $tab, self::TABS, true ) ) {
			$args['tab'] = $tab;
		}
		return Shop_App::admin_page_url( array_merge( $args, $extra ) );
	}

	/**
	 * Enqueue the page's styles and script.
	 *
	 * @param string $hook_suffix Admin page hook.
	 */
	public function enqueue_assets( $hook_suffix ) {
		if ( false === strpos( (string) $hook_suffix, Shop_App::MENU_SLUG ) ) {
			return;
		}

		wp_enqueue_style( 'fishotel-shop-app-admin', Shop_App::url() . 'css/admin.css', array( 'fishotel-misc-admin' ), FISHOTEL_MISC_VERSION );
		wp_enqueue_script( 'fishotel-shop-app-admin', Shop_App::url() . 'js/admin.js', array(), FISHOTEL_MISC_VERSION, true );
		wp_localize_script(
			'fishotel-shop-app-admin',
			'fhShopAppAdmin',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( self::NONCE ),
				'i18n'    => array(
					'making'  => __( 'Making the link…', 'fishotel-misc-plugin' ),
					'failed'  => __( 'The link could not be made. Reload the page and try again.', 'fishotel-misc-plugin' ),
					'copied'  => __( 'Copied', 'fishotel-misc-plugin' ),
					'copy'    => __( 'Copy the link', 'fishotel-misc-plugin' ),
					/* translators: %s: time left, like 9:41 */
					'goodFor' => __( 'Good for %s more, and for one use.', 'fishotel-misc-plugin' ),
					'expired' => __( 'This link has run out. Make a new one.', 'fishotel-misc-plugin' ),
					'confirm' => __( 'Revoke this device? It stops working at once and has to be set up again.', 'fishotel-misc-plugin' ),
					'off'     => __( 'Switch this person off? Their PIN is destroyed; switching them back on needs a new one.', 'fishotel-misc-plugin' ),
				),
			)
		);
	}

	/* ============================================================== */
	/*  Guards                                                         */
	/* ============================================================== */

	/**
	 * Whether the current request may change anything here: a WordPress
	 * login with the capability — never a shop session.
	 *
	 * @return bool
	 */
	public static function may_write() {
		return ! Gate::is_shop_session() && current_user_can( Shop_App::CAPABILITY );
	}

	/**
	 * Capability + nonce for an admin-post handler, or die.
	 */
	private static function guard() {
		if ( ! self::may_write() ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'fishotel-misc-plugin' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( self::NONCE );
	}

	/**
	 * Redirect back to the page with a one-word notice.
	 *
	 * @param string $tab    Tab.
	 * @param string $notice Notice key (see notices()).
	 * @param array  $extra  Extra query args.
	 */
	private static function back( $tab, $notice, array $extra = array() ) {
		wp_safe_redirect( self::page_url( $tab, array_merge( array( 'fh_notice' => $notice ), $extra ) ) );
		exit;
	}

	/**
	 * The notices a redirect can name.
	 *
	 * @return array<string, array{0: string, 1: string}> key => kind (success|error), text.
	 */
	public static function notices() {
		return array(
			'enrolled'        => array( 'success', __( 'This device is set up. Open the app on it and unlock with a PIN.', 'fishotel-misc-plugin' ) ),
			'enrol_failed'    => array( 'error', __( 'The device could not be set up. Try again.', 'fishotel-misc-plugin' ) ),
			'https'           => array( 'error', __( 'The Shop App needs HTTPS: a device can only be set up at an https:// address.', 'fishotel-misc-plugin' ) ),
			'revoked'         => array( 'success', __( 'Revoked. That device is no longer a shop device.', 'fishotel-misc-plugin' ) ),
			'revoke_failed'   => array( 'error', __( 'That device could not be revoked (it may be revoked already).', 'fishotel-misc-plugin' ) ),
			'lockout_cleared' => array( 'success', __( 'The lockout is cleared.', 'fishotel-misc-plugin' ) ),
			'lockout_failed'  => array( 'error', __( 'The lockout could not be cleared.', 'fishotel-misc-plugin' ) ),
			'person_saved'    => array( 'success', __( 'Saved.', 'fishotel-misc-plugin' ) ),
			'person_off'      => array( 'success', __( 'Switched off. Their PIN is destroyed.', 'fishotel-misc-plugin' ) ),
			'person_failed'   => array( 'error', __( 'Not saved — see below.', 'fishotel-misc-plugin' ) ),
			'settings_saved'  => array( 'success', __( 'Settings saved.', 'fishotel-misc-plugin' ) ),
		);
	}

	/* ============================================================== */
	/*  Devices                                                        */
	/* ============================================================== */

	/**
	 * Set up the device this request comes from.
	 *
	 * @param array $post The unslashed form.
	 * @return string Notice key.
	 */
	public static function enrol_here_request( array $post ) {
		if ( ! Shop_App::https_ok() ) {
			return 'https';
		}
		$device = Access::instance()->enrol_here( isset( $post['name'] ) ? $post['name'] : '', (int) get_current_user_id() );
		return $device ? 'enrolled' : 'enrol_failed';
	}

	/**
	 * Admin-post: set up this device.
	 */
	public function handle_enrol_here() {
		self::guard();
		self::back( 'devices', self::enrol_here_request( wp_unslash( $_POST ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in guard().
	}

	/**
	 * Admin-post: revoke a device.
	 */
	public function handle_revoke() {
		self::guard();
		$id = isset( $_POST['device'] ) ? absint( wp_unslash( $_POST['device'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in guard().
		self::back( 'devices', Access::instance()->revoke( $id, (int) get_current_user_id() ) ? 'revoked' : 'revoke_failed' );
	}

	/**
	 * Admin-post: clear a device's lockout.
	 */
	public function handle_clear_lockout() {
		self::guard();
		$id = isset( $_POST['device'] ) ? absint( wp_unslash( $_POST['device'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in guard().
		self::back( 'devices', Access::instance()->clear_lockout( $id, (int) get_current_user_id() ) ? 'lockout_cleared' : 'lockout_failed' );
	}

	/**
	 * Make a one-time enrolment link.
	 *
	 * @param array $post The unslashed request.
	 * @return array{ok: bool, status: int, data: array}
	 */
	public static function link_request( array $post ) {
		if ( ! Shop_App::https_ok() ) {
			return array(
				'ok'     => false,
				'status' => 400,
				'data'   => array( 'message' => __( 'The Shop App needs HTTPS: a device can only be set up at an https:// address.', 'fishotel-misc-plugin' ) ),
			);
		}

		$link = Access::instance()->create_link( isset( $post['name'] ) ? $post['name'] : '', (int) get_current_user_id() );
		if ( ! $link ) {
			return array(
				'ok'     => false,
				'status' => 500,
				'data'   => array( 'message' => __( 'The link could not be made. Reload the page and try again.', 'fishotel-misc-plugin' ) ),
			);
		}

		$qr = self::qr_data_uri( $link['url'] );

		return array(
			'ok'     => true,
			'status' => 200,
			'data'   => array(
				'url'     => $link['url'],
				'name'    => $link['name'],
				'seconds' => Access::ENROL_SECONDS,
				'qr'      => $qr,
				'qrNote'  => '' === $qr ? __( 'No QR code: the Clean Room section (which draws them) is switched off, or the address is too long for one. Send the link to the device instead.', 'fishotel-misc-plugin' ) : '',
			),
		);
	}

	/**
	 * A QR code for an address as a data: URI, drawn by the Clean Room's
	 * encoder when that section is loaded; '' when it is not, or when the
	 * address does not fit.
	 *
	 * @param string $url The address.
	 * @return string
	 */
	public static function qr_data_uri( $url ) {
		$qr = '\\FisHotel\\Misc\\Sections\\Clean_Room\\Qr';
		if ( ! class_exists( $qr, false ) || ! method_exists( $qr, 'svg' ) ) {
			return '';
		}
		try {
			$svg = (string) call_user_func( array( $qr, 'svg' ), (string) $url, 6, 4 );
		} catch ( \Throwable $e ) {
			return '';
		}
		return '' === $svg ? '' : 'data:image/svg+xml;base64,' . base64_encode( $svg ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- an image for an <img> tag.
	}

	/**
	 * AJAX: make a one-time enrolment link.
	 */
	public function ajax_link() {
		if ( ! self::may_write() ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized.', 'fishotel-misc-plugin' ) ), 403 );
		}
		check_ajax_referer( self::NONCE, 'nonce' );

		$result = self::link_request( wp_unslash( $_POST ) );
		if ( $result['ok'] ) {
			wp_send_json_success( $result['data'] );
		}
		wp_send_json_error( $result['data'], $result['status'] );
	}

	/**
	 * Who a device says is unlocked — but only while that can still be
	 * true: the row keeps the session until the device is next heard
	 * from, so a session that must have run out by now is not shown.
	 *
	 * @param array   $device   A device row.
	 * @param array[] $people   People by id.
	 * @param array   $settings Settings.
	 * @param int     $now      Timestamp.
	 * @return array{name: string, since: string}|null
	 */
	public static function unlocked_by( array $device, array $people, array $settings, $now ) {
		if ( Store::STATUS_ACTIVE !== $device['status'] || '' === $device['session_id'] || $device['session_person_id'] <= 0 ) {
			return null;
		}

		$started = Lockout::ts( $device['session_started_at'] );
		$length  = Session::length( $settings );
		if ( Session::MODE_UNLOCK === Session::mode( $settings ) ) {
			$ends = $started + $length;
		} else {
			$ends = max( $started, Lockout::ts( $device['last_used_at'] ) ) + $length + Access::TOUCH_SECONDS;
		}
		if ( (int) $now > $ends ) {
			return null;
		}

		$person = isset( $people[ $device['session_person_id'] ] ) ? $people[ $device['session_person_id'] ] : null;

		return array(
			'name'  => $person ? (string) $person['name'] : '#' . (int) $device['session_person_id'],
			'since' => (string) $device['session_started_at'],
		);
	}

	/* ============================================================== */
	/*  People                                                         */
	/* ============================================================== */

	/**
	 * Add or change a person.
	 *
	 * @param array $post The unslashed form: person (id, 0 = new), name, pin, user_id, active.
	 * @return array{ok: bool, id: int, errors: string[], input: array}
	 */
	public static function person_request( array $post ) {
		$access   = Access::instance();
		$store    = $access->store();
		$id       = isset( $post['person'] ) && is_scalar( $post['person'] ) ? max( 0, (int) $post['person'] ) : 0;
		$current  = $id > 0 ? $store->person( $id ) : null;
		$input    = array(
			'name'    => isset( $post['name'] ) && is_scalar( $post['name'] ) ? (string) $post['name'] : '',
			'pin'     => isset( $post['pin'] ) && is_scalar( $post['pin'] ) ? (string) $post['pin'] : '',
			'user_id' => isset( $post['user_id'] ) && is_scalar( $post['user_id'] ) ? (int) $post['user_id'] : 0,
			'active'  => 1, // The form only ever saves a person who is on; "Switch off" is its own button.
		);
		$kept     = array(
			'name'    => $input['name'],
			'user_id' => $input['user_id'],
		);

		if ( $id > 0 && ! $current ) {
			return array(
				'ok'     => false,
				'id'     => $id,
				'errors' => array( __( 'That person no longer exists.', 'fishotel-misc-plugin' ) ),
				'input'  => $kept,
			);
		}

		$settings = Settings::get();
		$accounts = array_keys( People::account_choices( $current ? (int) $current['user_id'] : 0 ) );
		$checked  = People::validate( $input, $current, $store->people(), $settings, Settings::secret(), $accounts );

		if ( ! $checked['ok'] ) {
			return array(
				'ok'     => false,
				'id'     => $id,
				'errors' => $checked['errors'],
				'input'  => $kept,
			);
		}

		$fields = $checked['fields'];
		$by     = (int) get_current_user_id();

		if ( $current ) {
			$saved = $store->update_person( $id, $fields );
			$note  = array();
			if ( $checked['pin_changed'] ) {
				$note[] = __( 'PIN changed', 'fishotel-misc-plugin' );
			}
			if ( empty( $current['active'] ) ) {
				$note[] = __( 'switched on', 'fishotel-misc-plugin' );
			}
			if ( (int) $current['user_id'] !== (int) $fields['user_id'] ) {
				$note[] = __( 'account changed', 'fishotel-misc-plugin' );
			}
			if ( (string) $current['name'] !== (string) $fields['name'] ) {
				/* translators: %s: the person's earlier name */
				$note[] = sprintf( __( 'was "%s"', 'fishotel-misc-plugin' ), $current['name'] );
			}
			$event = 'person_changed';
		} else {
			$fields['created_by'] = $by;
			$saved                = $store->insert_person( $fields );
			$id                   = $saved ? (int) $saved : 0;
			$note                 = array();
			$event                = 'person_added';
		}

		if ( ! $saved ) {
			return array(
				'ok'     => false,
				'id'     => $id,
				'errors' => array( __( 'The person could not be saved. Try again.', 'fishotel-misc-plugin' ) ),
				'input'  => $kept,
			);
		}

		// The shared account is made the moment somebody needs it.
		if ( 0 === (int) $fields['user_id'] ) {
			People::staff_user_id( true );
		}

		$access->log(
			$event,
			array(
				'person_id'   => $id,
				'person_name' => $fields['name'],
				'user_id'     => $by,
				'note'        => implode( ', ', $note ),
			)
		);

		return array(
			'ok'     => true,
			'id'     => $id,
			'errors' => array(),
			'input'  => array(),
		);
	}

	/**
	 * Admin-post: add or change a person.
	 */
	public function handle_person() {
		self::guard();

		$result = self::person_request( wp_unslash( $_POST ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in guard().
		if ( $result['ok'] ) {
			self::back( 'people', 'person_saved' );
		}

		// What was typed comes back (never the PIN), with the reasons.
		set_transient(
			'fishotel_shopapp_form_' . (int) get_current_user_id(),
			array(
				'id'     => (int) $result['id'],
				'errors' => $result['errors'],
				'input'  => $result['input'],
			),
			120
		);
		self::back( 'people', 'person_failed', $result['id'] > 0 ? array( 'edit' => (int) $result['id'] ) : array() );
	}

	/**
	 * Switch a person off: their PIN is destroyed and a session they hold
	 * ends at its next request.
	 *
	 * @param int $id Person id.
	 * @return bool
	 */
	public static function person_off_request( $id ) {
		$access = Access::instance();
		$store  = $access->store();
		$person = $store->person( (int) $id );
		if ( ! $person || empty( $person['active'] ) ) {
			return false;
		}

		$saved = $store->update_person(
			$person['id'],
			array(
				'active'     => 0,
				'pin_hash'   => '',
				'pin_salt'   => '',
				'pin_length' => 0,
			)
		);
		if ( ! $saved ) {
			return false;
		}

		$access->log(
			'person_changed',
			array(
				'person_id'   => (int) $person['id'],
				'person_name' => $person['name'],
				'user_id'     => (int) get_current_user_id(),
				'note'        => __( 'switched off, PIN destroyed', 'fishotel-misc-plugin' ),
			)
		);

		return true;
	}

	/**
	 * Admin-post: switch a person off.
	 */
	public function handle_person_off() {
		self::guard();
		$id = isset( $_POST['person'] ) ? absint( wp_unslash( $_POST['person'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in guard().
		self::back( 'people', self::person_off_request( $id ) ? 'person_off' : 'person_failed' );
	}

	/* ============================================================== */
	/*  Settings                                                       */
	/* ============================================================== */

	/**
	 * Admin-post: save the settings.
	 */
	public function handle_settings() {
		self::guard();
		Settings::save( wp_unslash( $_POST ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in guard().
		self::back( 'settings', 'settings_saved' );
	}

	/* ============================================================== */
	/*  The page                                                       */
	/* ============================================================== */

	/**
	 * A stored UTC datetime in the site's zone and formats ("—" when none).
	 *
	 * @param mixed $utc Y-m-d H:i:s (UTC), or empty.
	 * @return string
	 */
	public static function when( $utc ) {
		$ts = Lockout::ts( $utc );
		if ( $ts <= 0 ) {
			return '—';
		}
		$format = (string) get_option( 'date_format', 'Y-m-d' ) . ' ' . (string) get_option( 'time_format', 'H:i' );
		return function_exists( 'wp_date' ) ? (string) wp_date( $format, $ts ) : gmdate( 'Y-m-d H:i', $ts ) . ' UTC';
	}

	/**
	 * A WordPress user's name for a list ("—" for none).
	 *
	 * @param int $user_id User id.
	 * @return string
	 */
	public static function user_name( $user_id ) {
		$user_id = (int) $user_id;
		if ( $user_id <= 0 ) {
			return '—';
		}
		$user = get_userdata( $user_id );
		return $user ? (string) $user->display_name : '#' . $user_id;
	}

	/**
	 * Render the page.
	 */
	public function render_page() {
		if ( ! current_user_can( Shop_App::CAPABILITY ) || Gate::is_shop_session() ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'fishotel-misc-plugin' ) );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only tab switch, notice key and page number.
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'devices';
		if ( ! in_array( $tab, self::TABS, true ) ) {
			$tab = 'devices';
		}
		$notice_key = isset( $_GET['fh_notice'] ) ? sanitize_key( wp_unslash( $_GET['fh_notice'] ) ) : '';
		$edit_id    = isset( $_GET['edit'] ) ? absint( wp_unslash( $_GET['edit'] ) ) : 0;
		$paged      = isset( $_GET['paged'] ) ? max( 1, absint( wp_unslash( $_GET['paged'] ) ) ) : 1;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$notices   = self::notices();
		$notice    = isset( $notices[ $notice_key ] ) ? $notices[ $notice_key ] : null;
		$access    = Access::instance();
		$store     = $access->store();
		$now       = $access->now();
		$settings  = Settings::get();
		$tables_ok = Store::tables_exist();
		$https_ok  = Shop_App::https_ok();
		$desk      = Shop_App::front_desk();
		$app_url   = Shop_App::app_url();

		$people_by_id = array();
		$people       = $tables_ok ? $store->people() : array();
		foreach ( $people as $person ) {
			$people_by_id[ (int) $person['id'] ] = $person;
		}
		$usable = 0;
		foreach ( $people as $person ) {
			if ( Pins::usable( $person, $settings['pin_length'] ) ) {
				++$usable;
			}
		}

		$view = array(
			'tab'       => $tab,
			'notice'    => $notice,
			'tables_ok' => $tables_ok,
			'https_ok'  => $https_ok,
			'desk'      => $desk,
			'app_url'   => $app_url,
			'settings'  => $settings,
			'usable'    => $usable,
			'now'       => $now,
		);

		if ( 'devices' === $tab ) {
			if ( $tables_ok ) {
				$store->purge_pending( $now );
			}
			$ctx             = $access->context();
			$view['devices'] = $tables_ok ? $store->devices() : array();
			$view['people']  = $people_by_id;
			$view['this_id'] = $ctx['device'] ? (int) $ctx['device']['id'] : 0;
			$view['me']      = wp_get_current_user();
			$view['logout']  = wp_logout_url( $app_url );
		} elseif ( 'people' === $tab ) {
			$form = get_transient( 'fishotel_shopapp_form_' . (int) get_current_user_id() );
			if ( is_array( $form ) ) {
				delete_transient( 'fishotel_shopapp_form_' . (int) get_current_user_id() );
			} else {
				$form = null;
			}
			$editing          = $edit_id > 0 && isset( $people_by_id[ $edit_id ] ) ? $people_by_id[ $edit_id ] : null;
			$view['people']   = $people;
			$view['editing']  = $editing;
			$view['form']     = $form;
			$view['accounts'] = People::account_choices( $editing ? (int) $editing['user_id'] : 0 );
		} elseif ( 'log' === $tab ) {
			$total         = $tables_ok ? $store->log_count() : 0;
			$view['rows']  = $tables_ok ? $store->log_rows( self::LOG_PER_PAGE, ( $paged - 1 ) * self::LOG_PER_PAGE ) : array();
			$view['total'] = $total;
			$view['paged'] = $paged;
			$view['pages'] = max( 1, (int) ceil( $total / self::LOG_PER_PAGE ) );
		}

		self::render( $view );
	}

	/**
	 * Print the page from its data (so a test can render any tab).
	 *
	 * @param array $view The view's variables.
	 */
	public static function render( array $view ) {
		// phpcs:ignore WordPress.PHP.DontExtract.extract_extract -- a fixed list of view variables built by render_page().
		extract( $view, EXTR_SKIP );
		include Shop_App::path() . 'views/admin.php';
	}
}
