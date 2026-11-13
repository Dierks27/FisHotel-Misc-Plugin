<?php
/**
 * Checkout alarm — tells the owner within minutes when customers cannot
 * check out.
 *
 * Three signals, all read-only and cheap:
 *   fatal  a NEW PHP fatal since the last look, from what is there:
 *          - WooCommerce's "fatal-errors" log source. WooCommerce 11 writes
 *            it to files by default (wp-content/uploads/wc-logs/
 *            fatal-errors-<date>-<hash>.log, one line per fatal:
 *            "2026-01-02T03:04:05+00:00 CRITICAL <message> CONTEXT: {…}");
 *            only files changed since the last look are opened, and only
 *            their last 64 KB is read. When WooCommerce → Status → Logs
 *            is set to the database handler, the {prefix}woocommerce_log
 *            rows after the last log_id seen are read instead
 *            (Store::wc_fatals_after(), by primary key).
 *          - WordPress's recovery mode: the option
 *            recovery_mode_email_last_sent moves when WordPress pauses a
 *            plugin or the theme after a fatal and e-mails its recovery
 *            link.
 *   stall  at least N checkout orders (placed by a customer on the
 *          checkout page — not batch invoices or orders made in wp-admin)
 *          created in the last M minutes still Pending payment or Failed, while none created in that window went
 *          through (Processing, Completed, any status WooCommerce counts
 *          as paid, or On hold — an offline payment proves the checkout
 *          itself works). Two wc_get_orders() calls with a date and a
 *          limit — never a scan of every order. Defaults 3 / 30.
 *   page   the checkout page answering a loopback GET with a 5xx. OFF by
 *          default: some hosts block loopbacks. A loopback that cannot
 *          connect is shown on the Health screen, never e-mailed.
 *
 * When: its own 5-minute WP-Cron event, plus a one-off look a minute
 * after WooCommerce marks an order Failed, after the classic checkout
 * throws while creating an order, or after WooCommerce's shutdown handler
 * sees a fatal. On the customer's request nothing runs but that
 * scheduling, inside a try / catch — the alarm can never slow or break a
 * checkout.
 *
 * On a finding: ONE plain-text e-mail to the site's admin address (or the
 * address in Health → Settings → Checkout alarm) with what was seen,
 * where to look and how to go back on the last upload; then silence for
 * an hour per signal. A line on the Today page and a card on the Health
 * screen show what is active. "All clear" is never e-mailed.
 *
 * On while the Health section is on (Settings can switch it off); the
 * page check is the only part that starts off.
 *
 * @package FisHotel\Misc\Sections\Health
 */

namespace FisHotel\Misc\Sections\Health;

defined( 'ABSPATH' ) || exit;

/**
 * Class Alarm
 */
class Alarm {

	/**
	 * The 5-minute look, the one-off look after a failed checkout, and
	 * the schedule they use.
	 *
	 * @var string
	 */
	const HOOK      = 'fishotel_health_checkout_watch';
	const SOON_HOOK = 'fishotel_health_checkout_soon';
	const SCHEDULE  = 'fishotel_health_five_minutes';

	/**
	 * What the last look saw, the marks for "new since", what is active
	 * and when each signal last e-mailed. Not autoloaded.
	 *
	 * @var string
	 */
	const STATE_OPTION = 'fishotel_health_checkout_alarm';

	/**
	 * Silence per signal after an e-mail, and how long a fatal stays on
	 * Today and the Health screen.
	 *
	 * @var int
	 */
	const SILENCE = 3600;

	/**
	 * The signals, in the order the e-mail and the screens list them.
	 *
	 * @var string[]
	 */
	const SIGNALS = array( 'fatal', 'stall', 'page' );

	/**
	 * The Today filter, this line's key and its heading there.
	 *
	 * @var string
	 */
	const TODAY_FILTER = 'fishotel_today_lines';
	const TODAY_KEY    = 'checkout-alarm';
	const TODAY_GROUP  = 'site';

	/**
	 * How much of the end of a log file is read.
	 *
	 * @var int
	 */
	const TAIL_BYTES = 65536;

	/**
	 * Stand-ins for the outside world (tests only): fatal, stall, page —
	 * each a callable returning what the real look returns — and now.
	 *
	 * @var array
	 */
	private static $sources = array();

	/**
	 * Register the hooks (boot(), every request while Health is on). The
	 * failure hooks only schedule; the 5-minute event is armed at `init`.
	 */
	public static function init() {
		add_filter( 'cron_schedules', array( self::class, 'add_schedule' ) );
		add_action( self::HOOK, array( self::class, 'run' ), 10, 0 );
		add_action( self::SOON_HOOK, array( self::class, 'run' ), 10, 0 );
		add_action( 'woocommerce_order_status_failed', array( self::class, 'soon' ), 10, 0 );
		add_action( 'woocommerce_checkout_order_exception', array( self::class, 'soon' ), 10, 0 );
		add_action( 'woocommerce_shutdown_error', array( self::class, 'soon' ), 10, 0 );

		// At `init`, not here: scheduling reads the cron schedules, where WooCommerce
		// translates its own names — and loading translations this early (sections
		// boot at plugins_loaded) logs a notice.
		add_action( 'init', array( self::class, 'arm' ), 10, 0 );
	}

	/**
	 * Arm the 5-minute look, or drop it when the alarm is switched off in
	 * Settings (hooked to `init` by init()).
	 */
	public static function arm() {
		try {
			if ( Settings::get()['checkout_alarm'] ) {
				self::ensure_scheduled();
			} elseif ( wp_next_scheduled( self::HOOK ) ) {
				self::clear();
			}
		} catch ( \Throwable $e ) {
			unset( $e );
		}
	}

	/**
	 * The 5-minute schedule.
	 *
	 * @param mixed $schedules Schedules so far.
	 * @return array
	 */
	public static function add_schedule( $schedules ) {
		$schedules = is_array( $schedules ) ? $schedules : array();
		if ( ! isset( $schedules[ self::SCHEDULE ] ) ) {
			$schedules[ self::SCHEDULE ] = array(
				'interval' => 5 * 60,
				'display'  => __( 'Every 5 minutes (FisHotel checkout alarm)', 'fishotel-misc-plugin' ),
			);
		}
		return $schedules;
	}

	/**
	 * Arm the 5-minute look when nothing is pending.
	 */
	public static function ensure_scheduled() {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + 5 * 60, self::SCHEDULE, self::HOOK );
		}
	}

	/**
	 * Drop both events and forget the marks (section off, alarm switched
	 * off, plugin deactivated), so switching back on starts from "now"
	 * and never e-mails about a fatal from while it was off.
	 */
	public static function clear() {
		wp_clear_scheduled_hook( self::HOOK );
		wp_clear_scheduled_hook( self::SOON_HOOK );
		if ( function_exists( 'delete_option' ) ) {
			delete_option( self::STATE_OPTION );
		}
	}

	/**
	 * After a failed or thrown checkout (or a fatal WooCommerce saw at
	 * shutdown): a one-off look a minute from now. This runs on the
	 * customer's request, so it does nothing else and never throws.
	 */
	public static function soon() {
		try {
			if ( ! wp_next_scheduled( self::SOON_HOOK ) ) {
				wp_schedule_single_event( time() + 60, self::SOON_HOOK );
			}
		} catch ( \Throwable $e ) {
			unset( $e );
		}
	}

	/**
	 * Use stand-ins for the looks (tests only).
	 *
	 * @param array $sources fatal, stall, page => callable; now => int.
	 */
	public static function use_sources( array $sources = array() ) {
		self::$sources = $sources;
	}

	/**
	 * Now, or the test's now.
	 *
	 * @return int
	 */
	private static function now() {
		return isset( self::$sources['now'] ) ? (int) self::$sources['now'] : time();
	}

	/* ============================================================== */
	/*  The look                                                       */
	/* ============================================================== */

	/**
	 * The scheduled look: read the signals, decide, e-mail, remember.
	 * Never throws.
	 *
	 * @return array The new state (for tests).
	 */
	public static function run() {
		try {
			$settings = Settings::get();
			if ( empty( $settings['checkout_alarm'] ) ) {
				return array();
			}

			$now   = self::now();
			$state = self::state();

			$findings = array();
			$notes    = array();

			// 1. New fatals.
			$fatal              = self::look( 'fatal', array( $state, $now ) );
			$state['marks']     = $fatal['marks'];
			$findings['fatal']  = $fatal['finding'];

			// 2. Checkout stalling.
			$stall             = self::look( 'stall', array( $settings, $now ) );
			$findings['stall'] = self::stall_finding( (int) $stall['stuck'], (int) $stall['through'], (int) $settings['stall_orders'], (int) $settings['stall_minutes'] );

			// 3. The checkout page (optional).
			if ( ! empty( $settings['checkout_page_check'] ) ) {
				$page             = self::look( 'page', array() );
				$findings['page'] = self::page_finding( $page['code'], $page['error'] );
				if ( '' !== (string) $page['error'] ) {
					$notes['page'] = sprintf(
						/* translators: %s: the error */
						__( 'The check could not reach the checkout page (%s). Some hosts block a site from calling itself; if this stays, switch the page check off.', 'fishotel-misc-plugin' ),
						(string) $page['error']
					);
				}
			}

			$decision = self::decide( $state, $findings, $now );
			$state    = $decision['state'];

			$state['last_look'] = $now;
			$state['notes']     = $notes;

			if ( $decision['mail'] ) {
				// The hour of silence starts even when the e-mail fails, so a
				// broken mail server never means an attempt every 5 minutes;
				// the failure itself lands in the Log tab (source "mail").
				self::send( $decision['mail'], $settings, false );
				foreach ( array_keys( $decision['mail'] ) as $signal ) {
					$state['mailed'][ $signal ] = $now;
				}
			}

			update_option( self::STATE_OPTION, $state, false );

			return $state;
		} catch ( \Throwable $e ) {
			return array();
		}
	}

	/**
	 * One look, through the stand-in when a test gave one.
	 *
	 * @param string $signal fatal|stall|page.
	 * @param array  $args   Arguments.
	 * @return array
	 */
	private static function look( $signal, array $args ) {
		if ( isset( self::$sources[ $signal ] ) && is_callable( self::$sources[ $signal ] ) ) {
			return call_user_func_array( self::$sources[ $signal ], $args );
		}

		if ( 'fatal' === $signal ) {
			return self::look_fatal( $args[0], $args[1] );
		}
		if ( 'stall' === $signal ) {
			return self::look_stall( $args[0], $args[1] );
		}
		return self::look_page();
	}

	/**
	 * The stored state, with every key there.
	 *
	 * @return array
	 */
	public static function state() {
		$state = get_option( self::STATE_OPTION, array() );
		$state = is_array( $state ) ? $state : array();

		return array(
			'last_look' => (int) ( $state['last_look'] ?? 0 ),
			'marks'     => is_array( $state['marks'] ?? null ) ? $state['marks'] : array(),
			'active'    => is_array( $state['active'] ?? null ) ? $state['active'] : array(),
			'mailed'    => is_array( $state['mailed'] ?? null ) ? $state['mailed'] : array(),
			'notes'     => is_array( $state['notes'] ?? null ) ? $state['notes'] : array(),
		);
	}

	/**
	 * What is active and what to e-mail (pure).
	 *
	 * A fatal is an event: it stays active for an hour after it was seen.
	 * A stall or a page error is a state: active while the look finds it,
	 * gone (quietly) when it does not. A finding e-mails when its signal
	 * has not e-mailed in the last hour.
	 *
	 * @param array $state    state().
	 * @param array $findings signal => finding array (what, where) or null.
	 * @param int   $now      Now.
	 * @return array{state: array, mail: array}
	 */
	public static function decide( array $state, array $findings, $now ) {
		$active = is_array( $state['active'] ?? null ) ? $state['active'] : array();
		$mailed = is_array( $state['mailed'] ?? null ) ? $state['mailed'] : array();
		$mail   = array();

		foreach ( self::SIGNALS as $signal ) {
			$finding = $findings[ $signal ] ?? null;

			if ( is_array( $finding ) ) {
				$active[ $signal ] = array_merge( $finding, array( 'seen_at' => (int) $now ) );
				if ( (int) $now - (int) ( $mailed[ $signal ] ?? 0 ) >= self::SILENCE ) {
					$mail[ $signal ] = $active[ $signal ];
				}
				continue;
			}

			if ( 'fatal' === $signal && isset( $active['fatal'] ) && (int) $now - (int) ( $active['fatal']['seen_at'] ?? 0 ) < self::SILENCE ) {
				continue; // A fatal stays on the screens for its hour.
			}

			unset( $active[ $signal ] );
		}

		$state['active'] = $active;
		$state['mailed'] = $mailed;

		return array(
			'state' => $state,
			'mail'  => $mail,
		);
	}

	/* ------------------------------------------------------------------ */
	/*  Signal 1: fatals                                                   */
	/* ------------------------------------------------------------------ */

	/**
	 * New fatals since the last look. The first look only sets the marks.
	 *
	 * @param array $state state().
	 * @param int   $now   Now.
	 * @return array{finding: array|null, marks: array}
	 */
	public static function look_fatal( array $state, $now ) {
		$marks = is_array( $state['marks'] ?? null ) ? $state['marks'] : array();
		$first = ! isset( $marks['since'] );
		$since = $first ? (int) $now : (int) $marks['since'];
		$since = max( $since, (int) $now - DAY_IN_SECONDS ); // Never older than a day.

		$count   = 0;
		$message = '';
		$where   = '';

		// WooCommerce, database handler.
		if ( self::wc_logs_to_database() ) {
			$db               = Store::wc_fatals_after( (int) ( $marks['db_id'] ?? 0 ) );
			$had_mark         = isset( $marks['db_id'] );
			$marks['db_id']   = (int) $db['max_id'];
			if ( $had_mark && $db['count'] > 0 ) {
				$count  += (int) $db['count'];
				$message = (string) $db['message'];
				$where   = __( 'WooCommerce → Status → Logs, source "fatal-errors"', 'fishotel-misc-plugin' );
			}
		} else {
			// WooCommerce, files (the default).
			$dir = self::wc_log_dir();
			if ( ! $first && '' !== $dir && is_dir( $dir ) ) {
				$files = glob( $dir . 'fatal-errors-*.log' );
				foreach ( is_array( $files ) ? $files : array() as $file ) {
					if ( (int) @filemtime( $file ) <= $since ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
						continue;
					}
					$seen = self::parse_fatal_lines( self::tail( $file ), $since );
					if ( $seen['count'] > 0 ) {
						$count  += $seen['count'];
						$message = $seen['message'];
						$where   = sprintf(
							/* translators: %s: file name */
							__( 'WooCommerce → Status → Logs, source "fatal-errors" (file %s)', 'fishotel-misc-plugin' ),
							basename( $file )
						);
					}
				}
			}
		}

		// WordPress recovery mode.
		$recovery = (int) get_option( 'recovery_mode_email_last_sent', 0 );
		if ( ! $first && $recovery > $since ) {
			$count++;
			if ( '' === $message ) {
				$message = __( 'WordPress paused a plugin or the theme after a fatal error and e-mailed its recovery link to the admin address.', 'fishotel-misc-plugin' );
				$where   = __( 'the recovery e-mail from WordPress (subject "Your Site is Experiencing a Technical Issue")', 'fishotel-misc-plugin' );
			}
		}

		$marks['since'] = (int) $now;

		return array(
			'finding' => self::fatal_finding( $count, $message, $where ),
			'marks'   => $marks,
		);
	}

	/**
	 * The fatal finding (pure): null when none is new.
	 *
	 * @param int    $count   New fatals.
	 * @param string $message The newest one's message.
	 * @param string $where   Where it was found.
	 * @return array|null
	 */
	public static function fatal_finding( $count, $message, $where ) {
		if ( (int) $count <= 0 ) {
			return null;
		}

		return array(
			'what'  => sprintf(
				/* translators: 1: number of new fatal errors, 2: the newest one */
				_n( 'A new PHP fatal error: %2$s', '%1$d new PHP fatal errors. The newest: %2$s', (int) $count, 'fishotel-misc-plugin' ),
				(int) $count,
				self::short( $message )
			),
			'where' => '' !== (string) $where
				? sprintf(
					/* translators: %s: where the fatal was found */
					__( 'Where to look: %s, and FisHotel Tools → Health → Log.', 'fishotel-misc-plugin' ),
					$where
				)
				: __( 'Where to look: FisHotel Tools → Health → Log.', 'fishotel-misc-plugin' ),
		);
	}

	/**
	 * Count the fatal lines newer than a time in a WooCommerce log text
	 * (pure). A line: "<ISO 8601 time> <LEVEL> <message> CONTEXT: {…}".
	 *
	 * @param string $text  Log text (may start mid-line).
	 * @param int    $since Only lines after this.
	 * @return array{count: int, message: string, time: int}
	 */
	public static function parse_fatal_lines( $text, $since ) {
		$out = array(
			'count'   => 0,
			'message' => '',
			'time'    => 0,
		);

		foreach ( preg_split( '/\r\n|\n|\r/', (string) $text ) as $line ) {
			if ( ! preg_match( '/^(\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:[+-]\d{2}:?\d{2}|Z)?)\s+(EMERGENCY|ALERT|CRITICAL|ERROR)\s+(.*)$/', $line, $m ) ) {
				continue;
			}
			$ts = strtotime( $m[1] );
			if ( false === $ts || $ts <= (int) $since ) {
				continue;
			}
			$out['count']++;
			if ( $ts >= $out['time'] ) {
				$message        = $m[3];
				$cut            = strpos( $message, ' CONTEXT: ' );
				$out['message'] = trim( false === $cut ? $message : substr( $message, 0, $cut ) );
				$out['time']    = $ts;
			}
		}

		return $out;
	}

	/**
	 * The last TAIL_BYTES of a file.
	 *
	 * @param string $file Path.
	 * @return string
	 */
	private static function tail( $file ) {
		$size = (int) @filesize( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$fh   = @fopen( $file, 'rb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( false === $fh ) {
			return '';
		}
		if ( $size > self::TAIL_BYTES ) {
			fseek( $fh, -self::TAIL_BYTES, SEEK_END );
		}
		$text = (string) fread( $fh, self::TAIL_BYTES ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		return $text;
	}

	/**
	 * Whether WooCommerce writes its logs to the database (WC_LOG_HANDLER,
	 * else the WooCommerce → Status → Logs setting; files are the default).
	 *
	 * @return bool
	 */
	public static function wc_logs_to_database() {
		$handler = defined( 'WC_LOG_HANDLER' ) ? (string) constant( 'WC_LOG_HANDLER' ) : (string) get_option( 'woocommerce_logs_default_handler', '' );
		return 'wc_log_handler_db' === strtolower( ltrim( $handler, '\\' ) );
	}

	/**
	 * WooCommerce's log folder, with a trailing slash ('' if unknown).
	 *
	 * @return string
	 */
	public static function wc_log_dir() {
		$dir      = '';
		$settings = '\\Automattic\\WooCommerce\\Internal\\Admin\\Logging\\Settings';

		try {
			if ( class_exists( $settings ) && method_exists( $settings, 'get_log_directory' ) ) {
				$dir = (string) call_user_func( array( $settings, 'get_log_directory' ), false );
			}
		} catch ( \Throwable $e ) {
			$dir = '';
		}

		if ( '' === $dir && defined( 'WC_LOG_DIR' ) ) {
			$dir = (string) constant( 'WC_LOG_DIR' );
		}
		if ( '' === $dir && function_exists( 'wp_upload_dir' ) ) {
			$upload = wp_upload_dir( null, false );
			$dir    = isset( $upload['basedir'] ) ? $upload['basedir'] . '/wc-logs/' : '';
		}

		return '' === $dir ? '' : rtrim( $dir, '/\\' ) . '/';
	}

	/* ------------------------------------------------------------------ */
	/*  Signal 2: checkout stalling                                        */
	/* ------------------------------------------------------------------ */

	/**
	 * Orders created in the window: how many are stuck (Pending payment,
	 * Failed — up to the threshold) and whether any went through. Two
	 * small queries through WooCommerce's order API, each with a date and
	 * a limit; the second only when the first reaches the threshold.
	 *
	 * @param array $settings Settings.
	 * @param int   $now      Now.
	 * @return array{stuck: int, through: int}
	 */
	public static function look_stall( array $settings, $now ) {
		$out = array(
			'stuck'   => 0,
			'through' => 0,
		);

		if ( ! function_exists( 'wc_get_orders' ) ) {
			return $out;
		}

		// Only orders a customer placed through the checkout count, in both
		// queries: Batch Manager's batch invoices and orders made by hand in
		// wp-admin start as "Pending payment" too, and would raise a false
		// alarm on every invoicing day.
		$after = (int) $now - (int) $settings['stall_minutes'] * 60;
		$base  = array(
			'type'         => 'shop_order',
			'created_via'  => self::checkout_sources(),
			'date_created' => '>' . $after,
			'return'       => 'ids',
		);

		$stuck        = wc_get_orders( array_merge( $base, array( 'status' => array( 'pending', 'failed' ), 'limit' => (int) $settings['stall_orders'] ) ) );
		$out['stuck'] = is_array( $stuck ) ? count( $stuck ) : 0;

		if ( $out['stuck'] >= (int) $settings['stall_orders'] ) {
			$through        = wc_get_orders( array_merge( $base, array( 'status' => self::through_statuses(), 'limit' => 1 ) ) );
			$out['through'] = is_array( $through ) ? count( $through ) : 0;
		}

		return $out;
	}

	/**
	 * Where an order came from when a customer placed it: the classic
	 * checkout page and the block checkout (WooCommerce's Store API).
	 * Batch invoices (made with no source) and orders made in wp-admin
	 * ("admin") are left out.
	 *
	 * @return string[]
	 */
	public static function checkout_sources() {
		return array( 'checkout', 'store-api' );
	}

	/**
	 * The statuses that mean a checkout went through: WooCommerce's paid
	 * statuses (Processing, Completed and any a plugin adds) and On hold.
	 *
	 * @return string[]
	 */
	public static function through_statuses() {
		$paid = function_exists( 'wc_get_is_paid_statuses' ) ? (array) wc_get_is_paid_statuses() : array( 'processing', 'completed' );
		return array_values( array_unique( array_merge( $paid, array( 'on-hold' ) ) ) );
	}

	/**
	 * The stall finding (pure): null unless at least $orders are stuck
	 * and none went through.
	 *
	 * @param int $stuck   Stuck orders in the window.
	 * @param int $through Orders in the window that went through.
	 * @param int $orders  Threshold.
	 * @param int $minutes Window.
	 * @return array|null
	 */
	public static function stall_finding( $stuck, $through, $orders, $minutes ) {
		if ( (int) $stuck < max( 1, (int) $orders ) || (int) $through > 0 ) {
			return null;
		}

		return array(
			'what'  => sprintf(
				/* translators: 1: orders, 2: minutes */
				__( '%1$d or more orders from the last %2$d minutes are still "Pending payment" or "Failed", and none in that time was paid.', 'fishotel-misc-plugin' ),
				(int) $stuck,
				(int) $minutes
			),
			'where' => __( 'Where to look: WooCommerce → Orders, the "Pending payment" and "Failed" tabs (the order notes say why a payment failed), then try a checkout yourself.', 'fishotel-misc-plugin' ),
		);
	}

	/* ------------------------------------------------------------------ */
	/*  Signal 3: the checkout page                                        */
	/* ------------------------------------------------------------------ */

	/**
	 * GET the checkout page from the site itself.
	 *
	 * @return array{code: int, error: string}
	 */
	public static function look_page() {
		$out = array(
			'code'  => 0,
			'error' => '',
		);

		if ( ! function_exists( 'wc_get_checkout_url' ) || ! function_exists( 'wp_remote_get' ) ) {
			return $out;
		}

		$response = wp_remote_get(
			wc_get_checkout_url(),
			array(
				'timeout'     => 10,
				'redirection' => 3,
				'sslverify'   => (bool) apply_filters( 'https_local_ssl_verify', false ),
				'headers'     => array( 'Cache-Control' => 'no-cache' ),
			)
		);

		if ( is_wp_error( $response ) ) {
			$out['error'] = (string) $response->get_error_message();
			return $out;
		}

		$out['code'] = (int) wp_remote_retrieve_response_code( $response );
		return $out;
	}

	/**
	 * The page finding (pure): a 5xx answer. A loopback that could not
	 * connect is not a finding (the host may block it).
	 *
	 * @param int    $code  HTTP status, 0 when none.
	 * @param string $error Connection error, '' when none.
	 * @return array|null
	 */
	public static function page_finding( $code, $error = '' ) {
		if ( (int) $code < 500 ) {
			return null;
		}

		return array(
			'what'  => sprintf(
				/* translators: %d: HTTP status */
				__( 'The checkout page answered with error %d.', 'fishotel-misc-plugin' ),
				(int) $code
			),
			'where' => __( 'Where to look: open the checkout page in a private window; WooCommerce → Status → Logs, source "fatal-errors".', 'fishotel-misc-plugin' ),
		);
	}

	/* ============================================================== */
	/*  The e-mail                                                     */
	/* ============================================================== */

	/**
	 * Who the alarm writes to: the address in Settings, else the site's
	 * admin address.
	 *
	 * @param array $settings Settings.
	 * @return string
	 */
	public static function recipient( array $settings ) {
		$to = (string) ( $settings['checkout_alarm_email'] ?? '' );
		return '' !== $to ? $to : (string) get_option( 'admin_email', '' );
	}

	/**
	 * Subject (pure).
	 *
	 * @param string $site Site name.
	 * @param bool   $test A test from the button.
	 * @return string
	 */
	public static function subject( $site, $test = false ) {
		return $test
			/* translators: %s: site name */
			? sprintf( __( '[%s] Test: checkout alarm', 'fishotel-misc-plugin' ), $site )
			/* translators: %s: site name */
			: sprintf( __( '[%s] Checkout alarm: customers may not be able to check out', 'fishotel-misc-plugin' ), $site );
	}

	/**
	 * The plain-text body (pure).
	 *
	 * @param array  $mail     signal => finding (what, where).
	 * @param string $site     Site name.
	 * @param string $when     When, site time, as text.
	 * @param string $page_url The Health page.
	 * @param bool   $test     A test from the button.
	 * @return string
	 */
	public static function body( array $mail, $site, $when, $page_url, $test = false ) {
		$lines = array();

		if ( $test ) {
			/* translators: %s: site name */
			$lines[] = sprintf( __( 'This is a test of the checkout alarm on %s. Nothing is wrong.', 'fishotel-misc-plugin' ), $site );
			$lines[] = __( 'A real alarm looks like this: what was seen, where to look, and how to go back on the last upload.', 'fishotel-misc-plugin' );
			$lines[] = '';
		} else {
			/* translators: 1: site name, 2: time */
			$lines[] = sprintf( __( 'The checkout alarm on %1$s saw this at %2$s (site time):', 'fishotel-misc-plugin' ), $site, $when );
			$lines[] = '';
			foreach ( self::SIGNALS as $signal ) {
				if ( ! isset( $mail[ $signal ] ) ) {
					continue;
				}
				$lines[] = '- ' . (string) ( $mail[ $signal ]['what'] ?? '' );
				$lines[] = '  ' . (string) ( $mail[ $signal ]['where'] ?? '' );
				$lines[] = '';
			}
		}

		$lines[] = __( 'If this started right after you uploaded a plugin or the theme, go back on that upload:', 'fishotel-misc-plugin' );
		$lines[] = '  ' . __( 'Plugins → Add New Plugin → Upload Plugin → choose the zip of the version you had before → "Replace current with uploaded".', 'fishotel-misc-plugin' );
		$lines[] = '  ' . __( 'For the theme: Appearance → Themes → Add New Theme → Upload Theme, the same way.', 'fishotel-misc-plugin' );
		$lines[] = '';
		$lines[] = __( 'The Health page: ', 'fishotel-misc-plugin' ) . $page_url;
		$lines[] = '';
		$lines[] = __( 'You get at most one e-mail per problem per hour. "All clear" is not e-mailed; the Health page shows it.', 'fishotel-misc-plugin' );

		return implode( "\n", $lines );
	}

	/**
	 * Send the alarm (or the test). Never throws.
	 *
	 * @param array $mail     signal => finding.
	 * @param array $settings Settings.
	 * @param bool  $test     A test from the button.
	 * @return bool Whether wp_mail accepted it.
	 */
	public static function send( array $mail, array $settings, $test = false ) {
		try {
			$to = self::recipient( $settings );
			if ( '' === $to ) {
				return false;
			}

			$site = function_exists( 'get_bloginfo' ) ? (string) get_bloginfo( 'name' ) : 'FisHotel';
			$when = function_exists( 'wp_date' ) ? (string) wp_date( 'Y-m-d H:i', self::now() ) : gmdate( 'Y-m-d H:i', self::now() );
			$url  = function_exists( 'admin_url' ) ? admin_url( 'admin.php?page=' . Health::MENU_SLUG ) : '';

			return (bool) wp_mail(
				$to,
				self::subject( $site, $test ),
				self::body( $mail, $site, $when, $url, $test ),
				array( 'Content-Type: text/plain; charset=UTF-8' )
			);
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	/* ============================================================== */
	/*  Today and the Health screen                                    */
	/* ============================================================== */

	/**
	 * Hook the Today line (boot(), wp-admin only).
	 */
	public static function register_today() {
		add_filter( self::TODAY_FILTER, array( self::class, 'today_filter' ), 15, 1 );
	}

	/**
	 * The active findings, for the screens.
	 *
	 * @param array|null $state state(), or null to read it.
	 * @return array signal => finding.
	 */
	public static function active( $state = null ) {
		$state  = is_array( $state ) ? $state : self::state();
		$active = array();
		foreach ( self::SIGNALS as $signal ) {
			if ( isset( $state['active'][ $signal ] ) && is_array( $state['active'][ $signal ] ) ) {
				$active[ $signal ] = $state['active'][ $signal ];
			}
		}
		return $active;
	}

	/**
	 * The Today line (pure): null when nothing is active.
	 *
	 * @param array  $active active().
	 * @param string $url    The Health page.
	 * @return array|null
	 */
	public static function today_line( array $active, $url ) {
		if ( ! $active ) {
			return null;
		}

		$labels = self::signal_labels();
		$parts  = array();
		foreach ( self::SIGNALS as $signal ) {
			if ( isset( $active[ $signal ] ) ) {
				$parts[] = $labels[ $signal ];
			}
		}

		return array(
			'key'    => self::TODAY_KEY,
			'group'  => self::TODAY_GROUP,
			'text'   => __( 'Checkout alarm: customers may not be able to check out', 'fishotel-misc-plugin' ),
			'detail' => implode( ' · ', $parts ),
			'url'    => (string) $url,
			'count'  => count( $parts ),
			'tone'   => 'bad',
		);
	}

	/**
	 * Short names of the signals, for Today and the Health card.
	 *
	 * @return array
	 */
	public static function signal_labels() {
		return array(
			'fatal' => __( 'a new PHP fatal error', 'fishotel-misc-plugin' ),
			'stall' => __( 'orders stuck at payment', 'fishotel-misc-plugin' ),
			'page'  => __( 'the checkout page answers with an error', 'fishotel-misc-plugin' ),
		);
	}

	/**
	 * Today filter callback: add the line while a signal is active, for a
	 * user who may open Health. Whatever goes wrong adds nothing.
	 *
	 * @param mixed $lines The lines so far.
	 * @return array
	 */
	public static function today_filter( $lines = array() ) {
		$lines = is_array( $lines ) ? $lines : array();
		if ( ! function_exists( 'current_user_can' ) || ! current_user_can( Health::CAPABILITY ) ) {
			return $lines;
		}

		try {
			$line = self::today_line( self::active(), add_query_arg( array( 'page' => Health::MENU_SLUG ), admin_url( 'admin.php' ) ) );
		} catch ( \Throwable $e ) {
			$line = null;
		}

		$out = array();
		foreach ( $lines as $existing ) {
			if ( is_array( $existing ) && self::TODAY_KEY === (string) ( $existing['key'] ?? '' ) ) {
				continue;
			}
			$out[] = $existing;
		}
		if ( is_array( $line ) ) {
			$out[] = $line;
		}
		return $out;
	}

	/**
	 * What the Health screen's card says about each signal: watching or
	 * off, and how (pure apart from the settings and the log facts given).
	 *
	 * @param array  $settings Settings.
	 * @param bool   $to_db    wc_logs_to_database().
	 * @param string $dir      wc_log_dir().
	 * @return array signal => text.
	 */
	public static function watching( array $settings, $to_db, $dir ) {
		return array(
			'fatal' => $to_db
				? __( 'New PHP fatal errors: WooCommerce\'s log in the database (source "fatal-errors") and WordPress\'s recovery mode.', 'fishotel-misc-plugin' )
				: ( '' !== (string) $dir
					/* translators: %s: folder */
					? sprintf( __( 'New PHP fatal errors: WooCommerce\'s log files in %s (source "fatal-errors") and WordPress\'s recovery mode.', 'fishotel-misc-plugin' ), self::short_path( $dir ) )
					: __( 'New PHP fatal errors: WooCommerce\'s log files (source "fatal-errors") and WordPress\'s recovery mode.', 'fishotel-misc-plugin' ) ),
			'stall' => sprintf(
				/* translators: 1: orders, 2: minutes */
				__( 'Orders stuck at payment: %1$d or more "Pending payment" or "Failed" in %2$d minutes with none paid.', 'fishotel-misc-plugin' ),
				(int) $settings['stall_orders'],
				(int) $settings['stall_minutes']
			),
			'page'  => ! empty( $settings['checkout_page_check'] )
				? __( 'The checkout page: on — the site opens it every 5 minutes.', 'fishotel-misc-plugin' )
				: __( 'The checkout page: off (some hosts block a site from calling itself).', 'fishotel-misc-plugin' ),
		);
	}

	/**
	 * AJAX: send a test alarm to the alarm's address. Changes no state.
	 */
	public static function ajax_test() {
		if ( ! current_user_can( Health::CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized.', 'fishotel-misc-plugin' ) ), 403 );
		}

		check_ajax_referer( Admin::NONCE_AJAX, 'nonce' );

		$settings = Settings::get();
		$to       = self::recipient( $settings );
		if ( '' === $to ) {
			wp_send_json_error( array( 'message' => __( 'There is no address to send to. Set one under Settings → Checkout alarm.', 'fishotel-misc-plugin' ) ), 400 );
		}

		if ( ! self::send( array(), $settings, true ) ) {
			wp_send_json_error( array( 'message' => __( 'The test e-mail could not be sent. FisHotel SMTP → Email Logs and the Log tab here say why.', 'fishotel-misc-plugin' ) ), 500 );
		}

		/* translators: %s: e-mail address */
		wp_send_json_success( array( 'message' => sprintf( __( 'Test alarm sent to %s.', 'fishotel-misc-plugin' ), $to ) ) );
	}

	/* ============================================================== */
	/*  Helpers                                                        */
	/* ============================================================== */

	/**
	 * One line, at most 300 characters, the site's own path cut off.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	public static function short( $text ) {
		$text = trim( preg_replace( '/\s+/', ' ', (string) $text ) );
		if ( defined( 'ABSPATH' ) && '' !== ABSPATH ) {
			$text = str_replace( rtrim( ABSPATH, '/' ) . '/', '', $text );
		}
		return strlen( $text ) > 300 ? substr( $text, 0, 297 ) . '…' : $text;
	}

	/**
	 * A folder relative to the site root.
	 *
	 * @param string $dir Folder.
	 * @return string
	 */
	private static function short_path( $dir ) {
		if ( defined( 'ABSPATH' ) && 0 === strpos( (string) $dir, ABSPATH ) ) {
			return substr( (string) $dir, strlen( ABSPATH ) );
		}
		return (string) $dir;
	}
}
