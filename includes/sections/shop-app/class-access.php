<?php
/**
 * Shop App access — devices and sessions: who this device is, how it got
 * enrolled, who unlocked it, and the log of all of it.
 *
 * ENROLLED DEVICES. A PIN works only on a device the shop enrolled:
 *   enrol_here()   a shop manager or administrator, logged in to WordPress
 *                  on that device, names it on the admin page;
 *   create_link()  the admin page on a computer makes a one-time link
 *                  (shown with a QR code) that is good for ten minutes
 *                  and one use; link_info() only reads it — opening the
 *                  link shows a page with one button, and claim_link()
 *                  behind that button uses it up — so a link preview or
 *                  a scanner that fetches the address cannot spend it.
 * Either way the device gets a long random token in a cookie (a year,
 * renewed on use) and the server keeps only its SHA-256. revoke() ends a
 * device at once: the next request finds no device and no session.
 *
 * UNLOCKING. unlock() takes one try off the device's budget before the
 * PIN is compared with every person; a correct PIN starts a shop session
 * (a fresh id stored on the device row and signed into the session
 * cookie) and gives back the one try it took — never the wrong ones
 * before it; a wrong one says only "wrong PIN" — never whose it nearly
 * was —, is logged (never the digits), and the fifth within fifteen
 * minutes locks the device (Lockout). lock() ends the session on the
 * server, so a copy of the cookie is worth nothing. end_sessions_of()
 * ends the sessions of one person everywhere (their PIN or account
 * changed).
 *
 * context() is what every other class asks: the device of this request
 * (or none), its lockout, and its live session (or none). It costs no
 * query at all when the request carries no device cookie.
 *
 * Every event is appended to the access log (and mirrored to the shared
 * Health log through do_action( 'fishotel_log', … ) when that section is
 * on): device enrolled / revoked, a link made, unlocks, wrong PINs, locks,
 * lockouts, a lockout cleared, a person added or changed (never the PIN)
 * — with the device, the person and the time.
 *
 * The store and the clock are parameters, so the tests run all of this
 * against an in-memory store and a clock they move.
 *
 * @package FisHotel\Misc\Sections\Shop_App
 */

namespace FisHotel\Misc\Sections\Shop_App;

defined( 'ABSPATH' ) || exit;

/**
 * Class Access
 */
class Access {

	/**
	 * How long a one-time enrolment link is good for (seconds).
	 *
	 * @var int
	 */
	const ENROL_SECONDS = 600;

	/**
	 * A device's "last used" and its cookie's year are renewed at most
	 * this often (seconds).
	 *
	 * @var int
	 */
	const TOUCH_SECONDS = 300;

	const NAME_MAX = 80;

	/**
	 * The instance the section uses.
	 *
	 * @var Access|null
	 */
	private static $instance = null;

	/**
	 * The store (Store, or a stand-in with the same methods).
	 *
	 * @var object
	 */
	private $store;

	/**
	 * The clock: callable(): int, or null for time().
	 *
	 * @var callable|null
	 */
	private $clock;

	/**
	 * This request's context, memoised.
	 *
	 * @var array|null
	 */
	private $context = null;

	/**
	 * Constructor.
	 *
	 * @param object|null   $store A store (default: the real one).
	 * @param callable|null $clock A clock (default: time()).
	 */
	public function __construct( $store = null, $clock = null ) {
		$this->store = null !== $store ? $store : new Store();
		$this->clock = $clock;
	}

	/**
	 * The instance the section uses.
	 *
	 * @return Access
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Replace the instance (tests).
	 *
	 * @param Access|null $instance An instance, or null to start over.
	 */
	public static function set_instance( $instance ) {
		self::$instance = $instance;
	}

	/**
	 * The store.
	 *
	 * @return object
	 */
	public function store() {
		return $this->store;
	}

	/**
	 * Now.
	 *
	 * @return int
	 */
	public function now() {
		return null !== $this->clock ? (int) call_user_func( $this->clock ) : time();
	}

	/**
	 * Drop the memoised context (after anything that changes it).
	 */
	public function forget() {
		$this->context = null;
	}

	/**
	 * A UTC datetime for a timestamp.
	 *
	 * @param int $ts Timestamp.
	 * @return string
	 */
	private static function utc( $ts ) {
		return gmdate( 'Y-m-d H:i:s', (int) $ts );
	}

	/**
	 * A device name as stored.
	 *
	 * @param mixed  $name     Typed name.
	 * @param string $fallback Used when nothing was typed.
	 * @return string
	 */
	public static function clean_name( $name, $fallback = '' ) {
		$name = is_scalar( $name ) ? trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( (string) $name ) ) ) : '';
		// (string): before PHP 8 substr() of an empty string is false.
		$name = (string) ( function_exists( 'mb_substr' ) ? mb_substr( $name, 0, self::NAME_MAX ) : substr( $name, 0, self::NAME_MAX ) );
		return '' !== $name ? $name : (string) $fallback;
	}

	/* ============================================================== */
	/*  This request                                                   */
	/* ============================================================== */

	/**
	 * The device of this request, its lockout and its live session.
	 *
	 * @return array{device: array|null, session: array|null, lockout: array{locked: bool, seconds: int, until: int}}
	 *         session: person (row), user_id, session_id, started, last, expires, mode, length.
	 */
	public function context() {
		if ( null !== $this->context ) {
			return $this->context;
		}

		$now = $this->now();
		$ctx = array(
			'device'  => null,
			'session' => null,
			'lockout' => array(
				'locked'  => false,
				'seconds' => 0,
				'until'   => 0,
			),
		);

		$token = Http::read_cookie( Http::DEVICE );
		if ( ! Pins::is_token( $token ) ) {
			$this->context = $ctx; // No device cookie: no query.
			return $ctx;
		}

		$device = $this->store->device_by_token_hash( Pins::token_hash( $token ) );
		if ( ! $device ) {
			$this->context = $ctx; // Unknown or revoked: this is no device.
			return $ctx;
		}

		// Renewed on use: the row's last use and the cookie's year.
		if ( $now - Lockout::ts( $device['last_used_at'] ) >= self::TOUCH_SECONDS ) {
			$this->store->update_device( $device['id'], array( 'last_used_at' => self::utc( $now ) ) );
			$device['last_used_at'] = self::utc( $now );
			Http::set_cookie( Http::DEVICE, $token, $now + Http::DEVICE_LIFE );
		}

		$ctx['device']  = $device;
		$ctx['lockout'] = Lockout::status( $device, $now );
		$ctx['session'] = $this->session_of( $device, $now );

		$this->context = $ctx;
		return $ctx;
	}

	/**
	 * The live session of a device on this request, or null. The cookie
	 * must verify AND name the session the device row holds, for a person
	 * who is still switched on.
	 *
	 * @param array $device The device row.
	 * @param int   $now    Timestamp.
	 * @return array|null
	 */
	private function session_of( array $device, $now ) {
		$value = Http::read_cookie( Http::SESSION );
		if ( '' === $value ) {
			return null;
		}

		$settings = Settings::get();
		$key      = Settings::session_key();
		$check    = Session::check( $value, $key, $now, $settings );

		$names_this = $check['device_id'] === (int) $device['id']
			&& '' !== $device['session_id']
			&& hash_equals( $device['session_id'], $check['session_id'] )
			&& (int) $device['session_person_id'] === $check['person_id'];

		if ( ! $check['ok'] || ! $names_this ) {
			if ( 'expired' === $check['reason'] && $names_this ) {
				// It ran out: the device row stops saying somebody is unlocked.
				$this->store->update_device( $device['id'], self::no_session() );
			}
			Http::clear_cookie( Http::SESSION );
			return null;
		}

		$person = $this->store->person( $check['person_id'] );
		if ( ! $person || empty( $person['active'] ) ) {
			$this->store->update_device( $device['id'], self::no_session() );
			Http::clear_cookie( Http::SESSION );
			return null;
		}

		$user_id = People::account_id( $person, true );
		if ( $user_id <= 0 ) {
			return null;
		}

		$last = $check['last'];
		if ( Session::renewal_due( $check, $now, $settings ) ) {
			$last = $now;
			Http::set_cookie(
				Http::SESSION,
				Session::build(
					array(
						'device_id'  => $check['device_id'],
						'person_id'  => $check['person_id'],
						'session_id' => $check['session_id'],
						'started'    => $check['started'],
						'last'       => $now,
					),
					$key
				),
				Session::expires( $check['started'], $now, $settings )
			);
		}

		return array(
			'person'     => $person,
			'user_id'    => $user_id,
			'session_id' => $check['session_id'],
			'started'    => $check['started'],
			'last'       => $last,
			'expires'    => Session::expires( $check['started'], $last, $settings ),
			'mode'       => Session::mode( $settings ),
			'length'     => Session::length( $settings ),
		);
	}

	/**
	 * The device columns of "nobody is unlocked".
	 *
	 * @return array
	 */
	private static function no_session() {
		return array(
			'session_id'         => '',
			'session_person_id'  => 0,
			'session_started_at' => null,
		);
	}

	/* ============================================================== */
	/*  Unlock / lock                                                  */
	/* ============================================================== */

	/**
	 * Try a PIN on this device.
	 *
	 * @param mixed $pin The typed PIN.
	 * @return array{ok: bool, code: string, seconds?: int, left?: int, person?: array}
	 *         code: ok|not_enrolled|locked|wrong_pin|error. A wrong PIN is
	 *         always wrong_pin (or locked), whoever else it may belong to.
	 */
	public function unlock( $pin ) {
		$now = $this->now();
		$ctx = $this->context();

		if ( ! $ctx['device'] ) {
			return array(
				'ok'   => false,
				'code' => 'not_enrolled',
			);
		}
		$device = $ctx['device'];

		if ( $ctx['lockout']['locked'] ) {
			// Locked: no PIN is looked at, right or wrong.
			return array(
				'ok'      => false,
				'code'    => 'locked',
				'seconds' => (int) $ctx['lockout']['seconds'],
			);
		}

		if ( ! $this->store->reserve_attempt( $device['id'], Lockout::MAX_ATTEMPTS, $now, Lockout::FAIL_MEMORY ) ) {
			$fresh  = $this->store->device( $device['id'] );
			$status = $fresh ? Lockout::status( $fresh, $now ) : array(
				'locked'  => false,
				'seconds' => 0,
			);
			if ( $fresh && ! $status['locked'] && Store::STATUS_ACTIVE === $fresh['status'] && Lockout::count( $fresh, $now ) >= Lockout::MAX_ATTEMPTS ) {
				// Every try is used up and no lockout stands (the write of
				// one did not land, or other requests used the tries a
				// moment ago): write it now. Left like this the device
				// would refuse every PIN with nothing on the Devices list.
				return $this->wrong_pin( $device, $now, false );
			}
			return array(
				'ok'      => false,
				'code'    => 'locked',
				'seconds' => max( 5, (int) $status['seconds'] ),
			);
		}

		$settings = Settings::get();
		$secret   = Settings::secret();
		$key      = Settings::session_key();
		$person   = null;
		if ( '' !== $secret && '' !== $key ) {
			$person = Pins::find( $pin, $this->store->people(), $secret, $settings['pin_length'] );
		}
		$user_id = $person ? People::account_id( $person, true ) : 0;

		if ( ! $person || $user_id <= 0 ) {
			return $this->wrong_pin( $device, $now );
		}

		$session_id = Session::new_id();
		$stored     = $this->store->update_device(
			$device['id'],
			array(
				'session_id'         => $session_id,
				'session_person_id'  => (int) $person['id'],
				'session_started_at' => self::utc( $now ),
				'last_used_at'       => self::utc( $now ),
			)
		);
		if ( ! $stored ) {
			return array(
				'ok'   => false,
				'code' => 'error',
			);
		}

		// The try this PIN took is given back — that one only. The wrong
		// PINs before it stay counted (Lockout), so a PIN of one's own is
		// no way to go on guessing somebody else's.
		$this->store->release_attempt( $device['id'] );

		$this->store->update_person( $person['id'], array( 'last_unlock_at' => self::utc( $now ) ) );

		Http::set_cookie(
			Http::SESSION,
			Session::build(
				array(
					'device_id'  => (int) $device['id'],
					'person_id'  => (int) $person['id'],
					'session_id' => $session_id,
					'started'    => $now,
					'last'       => $now,
				),
				$key
			),
			Session::expires( $now, $now, $settings )
		);

		$this->log(
			'unlock',
			array(
				'device_id'   => (int) $device['id'],
				'device_name' => $device['name'],
				'person_id'   => (int) $person['id'],
				'person_name' => $person['name'],
				'user_id'     => $user_id,
			)
		);

		$this->forget();

		$public = array(
			'id'        => (int) $person['id'],
			'name'      => (string) $person['name'],
			'user_id'   => $user_id,
			'device_id' => (int) $device['id'],
			'device'    => (string) $device['name'],
		);

		/**
		 * Fires when a PIN started a shop session.
		 *
		 * @param array $public id, name, user_id, device_id, device.
		 */
		do_action( 'fishotel_shop_app_unlocked', $public );

		return array(
			'ok'     => true,
			'code'   => 'ok',
			'person' => $public,
		);
	}

	/**
	 * A wrong PIN: the same answer whatever was typed, a line in the log
	 * (the device and how many tries that makes — never what was typed,
	 * never a person), and the lockout when it was the fifth.
	 *
	 * @param array $device   The device row as read before the attempt.
	 * @param int   $now      Timestamp.
	 * @param bool  $compared Whether a PIN was compared (false: only the lockout is owed).
	 * @return array
	 */
	private function wrong_pin( array $device, $now, $compared = true ) {
		$fresh = $this->store->device( $device['id'] );
		if ( ! $fresh ) {
			return array(
				'ok'   => false,
				'code' => 'not_enrolled',
			);
		}

		if ( $compared ) {
			$this->log(
				'wrong_pin',
				array(
					'device_id'   => (int) $device['id'],
					'device_name' => $device['name'],
					/* translators: 1: which wrong PIN this is, 2: how many lock the device */
					'note'        => sprintf( __( 'Try %1$d of %2$d', 'fishotel-misc-plugin' ), min( Lockout::MAX_ATTEMPTS, max( 1, (int) $fresh['fail_count'] ) ), Lockout::MAX_ATTEMPTS ),
				)
			);
		}

		$after = Lockout::after_failure( $fresh, $now );
		if ( ! $after['locked'] ) {
			return array(
				'ok'   => false,
				'code' => 'wrong_pin',
				'left' => Lockout::attempts_left( $fresh ),
			);
		}

		if ( $this->store->lock_device( $device['id'], $after['fields'], Lockout::MAX_ATTEMPTS ) ) {
			$this->log(
				'lockout',
				array(
					'device_id'   => (int) $device['id'],
					'device_name' => $device['name'],
					/* translators: 1: wrong PINs, 2: within how long, e.g. "15 minutes", 3: locked for how long, e.g. "5 minutes" */
					'note'        => sprintf( __( '%1$d wrong PINs within %2$s: locked for %3$s', 'fishotel-misc-plugin' ), Lockout::MAX_ATTEMPTS, Lockout::human( Lockout::FAIL_MEMORY ), Lockout::human( $after['seconds'] ) ),
				)
			);
		}

		$this->forget();

		return array(
			'ok'      => false,
			'code'    => 'locked',
			'seconds' => (int) $after['seconds'],
		);
	}

	/**
	 * End the shop session of this device — on the server first, so the
	 * cookie is worth nothing afterwards, then the cookie.
	 *
	 * @return bool Whether a live session was ended.
	 */
	public function lock() {
		$ctx = $this->context();

		Http::clear_cookie( Http::SESSION );

		if ( ! $ctx['device'] ) {
			$this->forget();
			return false;
		}

		$device = $ctx['device'];
		$this->store->update_device( $device['id'], self::no_session() );
		$this->forget();

		if ( ! $ctx['session'] ) {
			return false;
		}

		$person = $ctx['session']['person'];
		$this->log(
			'lock',
			array(
				'device_id'   => (int) $device['id'],
				'device_name' => $device['name'],
				'person_id'   => (int) $person['id'],
				'person_name' => $person['name'],
				'user_id'     => (int) $ctx['session']['user_id'],
			)
		);

		/**
		 * Fires when a shop session was locked by hand.
		 *
		 * @param array $public id, name, user_id, device_id, device.
		 */
		do_action(
			'fishotel_shop_app_locked',
			array(
				'id'        => (int) $person['id'],
				'name'      => (string) $person['name'],
				'user_id'   => (int) $ctx['session']['user_id'],
				'device_id' => (int) $device['id'],
				'device'    => (string) $device['name'],
			)
		);

		return true;
	}

	/**
	 * End every shop session a person holds, on whatever device: their
	 * PIN or their account changed, or they were switched off. A session
	 * started with the old PIN must not outlive it.
	 *
	 * @param int $person_id Person id.
	 * @return int Devices whose session was ended.
	 */
	public function end_sessions_of( $person_id ) {
		$n = $this->store->end_person_sessions( (int) $person_id );
		$this->forget();
		return (int) $n;
	}

	/* ============================================================== */
	/*  Enrolment                                                      */
	/* ============================================================== */

	/**
	 * A browser that already is a device and is being enrolled again:
	 * the old device is revoked, so no device row is left without a
	 * browser that holds its token.
	 *
	 * @param int $by  The WordPress user behind the new enrolment.
	 * @param int $now Timestamp.
	 */
	private function retire_current( $by, $now ) {
		$token = Http::read_cookie( Http::DEVICE );
		if ( ! Pins::is_token( $token ) ) {
			return;
		}
		$old = $this->store->device_by_token_hash( Pins::token_hash( $token ) );
		if ( $old && $this->store->revoke_device( $old['id'], (int) $by, $now ) ) {
			$this->log(
				'device_revoked',
				array(
					'device_id'   => (int) $old['id'],
					'device_name' => $old['name'],
					'user_id'     => (int) $by,
					'note'        => __( 'Replaced: this browser was enrolled again', 'fishotel-misc-plugin' ),
				)
			);
		}
	}

	/**
	 * Enrol the device this request comes from (a manager, logged in to
	 * WordPress on it, pressed "Set up this device").
	 *
	 * @param mixed $name The name typed.
	 * @param int   $by   The WordPress user.
	 * @return array|null The device, or null when it could not be stored.
	 */
	public function enrol_here( $name, $by ) {
		$now = $this->now();

		$this->retire_current( $by, $now );

		$token = Pins::new_token();
		$id    = $this->store->create_device(
			array(
				'name'        => self::clean_name( $name, __( 'Shop device', 'fishotel-misc-plugin' ) ),
				'status'      => Store::STATUS_ACTIVE,
				'token_hash'  => Pins::token_hash( $token ),
				'enrolled_by' => (int) $by,
				'enrolled_at' => self::utc( $now ),
			)
		);
		if ( ! $id ) {
			return null;
		}

		Http::set_cookie( Http::DEVICE, $token, $now + Http::DEVICE_LIFE );
		Http::clear_cookie( Http::SESSION );
		$this->forget();

		$device = $this->store->device( $id );
		$this->log(
			'device_enrolled',
			array(
				'device_id'   => (int) $id,
				'device_name' => $device ? $device['name'] : '',
				'user_id'     => (int) $by,
				'note'        => __( 'Set up on the device itself', 'fishotel-misc-plugin' ),
			)
		);

		return $device;
	}

	/**
	 * Make a one-time enrolment link: a waiting device row holding only
	 * the SHA-256 of the link's token. The token itself exists in this
	 * return value and nowhere else.
	 *
	 * @param mixed $name The name typed.
	 * @param int   $by   The WordPress user.
	 * @return array{token: string, url: string, expires: int, device_id: int, name: string}|null
	 */
	public function create_link( $name, $by ) {
		$now = $this->now();

		$this->store->purge_pending( $now );

		$token = Pins::new_enrol_token();
		$clean = self::clean_name( $name, __( 'Shop device', 'fishotel-misc-plugin' ) );
		$id    = $this->store->create_device(
			array(
				'name'          => $clean,
				'status'        => Store::STATUS_PENDING,
				'enrol_hash'    => Pins::token_hash( $token ),
				'enrol_expires' => self::utc( $now + self::ENROL_SECONDS ),
				'enrolled_by'   => (int) $by,
			)
		);
		if ( ! $id ) {
			return null;
		}

		$this->log(
			'link_created',
			array(
				'device_id'   => (int) $id,
				'device_name' => $clean,
				'user_id'     => (int) $by,
				'note'        => __( 'One-time link, good for 10 minutes', 'fishotel-misc-plugin' ),
			)
		);

		return array(
			'token'     => $token,
			'url'       => Shop_App::route_url( 'enrol' ) . '&k=' . $token,
			'expires'   => $now + self::ENROL_SECONDS,
			'device_id' => (int) $id,
			'name'      => $clean,
		);
	}

	/**
	 * What a one-time link is for, while it is still good. Uses nothing up.
	 *
	 * @param mixed $token The link's token.
	 * @return array{name: string}|null
	 */
	public function link_info( $token ) {
		if ( ! Pins::is_enrol_token( $token ) ) {
			return null;
		}
		$device = $this->store->pending_by_enrol_hash( Pins::token_hash( $token ), $this->now() );
		return $device ? array( 'name' => (string) $device['name'] ) : null;
	}

	/**
	 * Use a one-time link up and enrol the device this request comes from.
	 *
	 * @param mixed $token The link's token.
	 * @return array|null The device, or null when the link expired, was used or never existed.
	 */
	public function claim_link( $token ) {
		if ( ! Pins::is_enrol_token( $token ) ) {
			return null;
		}

		$now    = $this->now();
		$new    = Pins::new_token();
		$device = $this->store->claim_enrolment( Pins::token_hash( $token ), Pins::token_hash( $new ), $now );
		if ( ! $device ) {
			return null;
		}

		$this->retire_current( (int) $device['enrolled_by'], $now );

		Http::set_cookie( Http::DEVICE, $new, $now + Http::DEVICE_LIFE );
		Http::clear_cookie( Http::SESSION );
		$this->forget();

		$this->log(
			'device_enrolled',
			array(
				'device_id'   => (int) $device['id'],
				'device_name' => $device['name'],
				'user_id'     => (int) $device['enrolled_by'],
				'note'        => __( 'By a one-time link', 'fishotel-misc-plugin' ),
			)
		);

		return $device;
	}

	/**
	 * Revoke a device (or cancel a link that was not used yet).
	 *
	 * @param int $device_id Device id.
	 * @param int $by        The WordPress user.
	 * @return bool
	 */
	public function revoke( $device_id, $by ) {
		$device = $this->store->device( (int) $device_id );
		if ( ! $device || Store::STATUS_REVOKED === $device['status'] ) {
			return false;
		}
		if ( ! $this->store->revoke_device( $device['id'], (int) $by, $this->now() ) ) {
			return false;
		}

		$this->forget();
		$this->log(
			'device_revoked',
			array(
				'device_id'   => (int) $device['id'],
				'device_name' => $device['name'],
				'user_id'     => (int) $by,
				'note'        => Store::STATUS_PENDING === $device['status'] ? __( 'The unused link was cancelled', 'fishotel-misc-plugin' ) : '',
			)
		);

		return true;
	}

	/**
	 * Clear a device's lockout (and its doubling) from the Devices list.
	 *
	 * @param int $device_id Device id.
	 * @param int $by        The WordPress user.
	 * @return bool
	 */
	public function clear_lockout( $device_id, $by ) {
		$device = $this->store->device( (int) $device_id );
		if ( ! $device || Store::STATUS_ACTIVE !== $device['status'] ) {
			return false;
		}
		if ( ! $this->store->update_device( $device['id'], Lockout::cleared() ) ) {
			return false;
		}

		$this->forget();
		$this->log(
			'lockout_cleared',
			array(
				'device_id'   => (int) $device['id'],
				'device_name' => $device['name'],
				'user_id'     => (int) $by,
			)
		);

		return true;
	}

	/* ============================================================== */
	/*  The log                                                        */
	/* ============================================================== */

	/**
	 * What an event is called on the Log tab.
	 *
	 * @param string $event Event key.
	 * @return string
	 */
	public static function event_label( $event ) {
		$labels = array(
			'device_enrolled' => __( 'Device enrolled', 'fishotel-misc-plugin' ),
			'device_revoked'  => __( 'Device revoked', 'fishotel-misc-plugin' ),
			'link_created'    => __( 'Enrolment link made', 'fishotel-misc-plugin' ),
			'unlock'          => __( 'Unlocked', 'fishotel-misc-plugin' ),
			'wrong_pin'       => __( 'Wrong PIN', 'fishotel-misc-plugin' ),
			'lock'            => __( 'Locked', 'fishotel-misc-plugin' ),
			'lockout'         => __( 'Locked out', 'fishotel-misc-plugin' ),
			'lockout_cleared' => __( 'Lockout cleared', 'fishotel-misc-plugin' ),
			'person_added'    => __( 'Person added', 'fishotel-misc-plugin' ),
			'person_changed'  => __( 'Person changed', 'fishotel-misc-plugin' ),
		);
		return isset( $labels[ $event ] ) ? $labels[ $event ] : (string) $event;
	}

	/**
	 * Append an event to the access log, and tell the shared Health log.
	 *
	 * @param string $event Event key (see event_label()).
	 * @param array  $row   device_id, device_name, person_id, person_name, user_id, note.
	 * @return int|false The log row's id.
	 */
	public function log( $event, array $row = array() ) {
		$row['event']      = (string) $event;
		$row['created_at'] = self::utc( $this->now() );

		$id = $this->store->append_log( $row );

		$message = self::event_label( $event );
		if ( ! empty( $row['person_name'] ) ) {
			$message .= ' — ' . $row['person_name'];
		}
		if ( ! empty( $row['device_name'] ) ) {
			$message .= ' · ' . $row['device_name'];
		}
		if ( ! empty( $row['note'] ) ) {
			$message .= ' (' . $row['note'] . ')';
		}

		// The Health section's logger when it is on; nothing when it is not.
		do_action(
			'fishotel_log',
			'lockout' === $event ? 'warning' : 'info',
			Shop_App::LOG_SOURCE,
			$message,
			array(
				'event'     => (string) $event,
				'device_id' => (int) ( $row['device_id'] ?? 0 ),
				'person_id' => (int) ( $row['person_id'] ?? 0 ),
				'user_id'   => (int) ( $row['user_id'] ?? 0 ),
			)
		);

		return $id;
	}
}
