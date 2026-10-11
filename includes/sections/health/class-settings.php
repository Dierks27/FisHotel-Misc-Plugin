<?php
/**
 * Health settings — option storage, defaults, the sanitised AJAX save
 * (nonce + manage_options), and the alert email.
 *
 * The alert: when alert_email is set, a NEW error/critical row from source
 * php, mail or updater sends ONE plain-text wp_mail per cooldown window
 * (a transient holds the window). A failing alert mail fires
 * wp_mail_failed → Capture stores it as a 'mail' row (so a rejected alert
 * address is visible) → maybe_alert() runs again for that row and bails on
 * the static flag, so it never alerts about its own failure or loops.
 *
 * @package FisHotel\Misc\Sections\Health
 */

namespace FisHotel\Misc\Sections\Health;

defined( 'ABSPATH' ) || exit;

/**
 * Class Settings
 */
class Settings {

	/**
	 * Nonce action for the settings form.
	 *
	 * @var string
	 */
	const NONCE_ACTION = 'fishotel_health_settings';

	/**
	 * Transient marking an alert cooldown window.
	 *
	 * @var string
	 */
	const ALERT_TRANSIENT = 'fishotel_health_alert_sent';

	/**
	 * Sources that may trigger an alert email.
	 *
	 * @var string[]
	 */
	const ALERT_SOURCES = array( 'php', 'mail', 'updater' );

	/**
	 * Levels that may trigger an alert email.
	 *
	 * @var string[]
	 */
	const ALERT_LEVELS = array( 'error', 'critical' );

	/**
	 * Default values.
	 *
	 * @var array
	 */
	const DEFAULTS = array(
		'retention_days'         => 30,
		'max_rows'               => 5000,
		'store_debug'            => false,
		'capture_fatals'         => true,
		'capture_http'           => true,
		'extra_hosts'            => array(),
		'alert_email'            => '',
		'alert_cooldown_minutes' => 60,
	);

	const RETENTION_MIN = 1;
	const RETENTION_MAX = 365;
	const MAX_ROWS_MIN  = 100;
	const MAX_ROWS_MAX  = 100000;
	const COOLDOWN_MIN  = 5;
	const COOLDOWN_MAX  = 1440;

	/**
	 * True while an alert is being sent.
	 *
	 * @var bool
	 */
	private static $sending = false;

	/**
	 * Register hooks.
	 */
	public function init() {
		add_action( 'wp_ajax_fishotel_health_save', array( $this, 'save_settings' ) );
	}

	/**
	 * Current settings, merged with defaults and normalised.
	 *
	 * @return array
	 */
	public static function get() {
		$stored = get_option( Health::OPTION_KEY, array() );

		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		return self::normalize( wp_parse_args( $stored, self::DEFAULTS ) );
	}

	/**
	 * Clamp and type every key.
	 *
	 * @param array $settings Raw settings.
	 * @return array
	 */
	public static function normalize( array $settings ) {
		return array(
			'retention_days'         => self::clamp( $settings['retention_days'] ?? self::DEFAULTS['retention_days'], self::RETENTION_MIN, self::RETENTION_MAX, self::DEFAULTS['retention_days'] ),
			'max_rows'               => self::clamp( $settings['max_rows'] ?? self::DEFAULTS['max_rows'], self::MAX_ROWS_MIN, self::MAX_ROWS_MAX, self::DEFAULTS['max_rows'] ),
			'store_debug'            => ! empty( $settings['store_debug'] ),
			'capture_fatals'         => ! empty( $settings['capture_fatals'] ),
			'capture_http'           => ! empty( $settings['capture_http'] ),
			'extra_hosts'            => self::clean_hosts( $settings['extra_hosts'] ?? array() ),
			'alert_email'            => self::clean_email( $settings['alert_email'] ?? '' ),
			'alert_cooldown_minutes' => self::clamp( $settings['alert_cooldown_minutes'] ?? self::DEFAULTS['alert_cooldown_minutes'], self::COOLDOWN_MIN, self::COOLDOWN_MAX, self::DEFAULTS['alert_cooldown_minutes'] ),
		);
	}

	/**
	 * AJAX handler — save settings and re-arm the daily event.
	 */
	public function save_settings() {
		if ( ! current_user_can( Health::CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized.', 'fishotel-misc-plugin' ) ), 403 );
		}

		check_ajax_referer( self::NONCE_ACTION, '_wpnonce' );

		$dropped  = array();
		$settings = self::sanitize( wp_unslash( $_POST ), $dropped ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above.

		update_option( Health::OPTION_KEY, $settings );

		Cron::schedule();

		$message = __( 'Settings saved.', 'fishotel-misc-plugin' );
		if ( $dropped ) {
			/* translators: %s: semicolon-separated list of what was not saved */
			$message .= ' ' . sprintf( __( 'Not saved: %s.', 'fishotel-misc-plugin' ), implode( '; ', $dropped ) );
		}

		wp_send_json_success(
			array(
				'message'  => $message,
				'settings' => $settings,
				'dropped'  => $dropped,
			)
		);
	}

	/**
	 * Sanitise a raw (unslashed) POST payload into a settings array.
	 *
	 * Checkboxes are absent when unticked, so a save always carries the
	 * full form: every boolean comes from the payload, never the stored
	 * value.
	 *
	 * @param array    $raw     Raw request data.
	 * @param string[] $dropped Filled with reasons for anything that fell back.
	 * @return array
	 */
	public static function sanitize( array $raw, &$dropped = array() ) {
		$dropped = array();

		$retention = isset( $raw['retention_days'] ) && is_scalar( $raw['retention_days'] ) ? (int) $raw['retention_days'] : self::DEFAULTS['retention_days'];
		if ( $retention < self::RETENTION_MIN || $retention > self::RETENTION_MAX ) {
			$dropped[] = __( 'retention must be 1–365 days (default 30 used)', 'fishotel-misc-plugin' );
			$retention = self::DEFAULTS['retention_days'];
		}

		$max_rows = isset( $raw['max_rows'] ) && is_scalar( $raw['max_rows'] ) ? (int) $raw['max_rows'] : self::DEFAULTS['max_rows'];
		if ( $max_rows < self::MAX_ROWS_MIN || $max_rows > self::MAX_ROWS_MAX ) {
			$dropped[] = __( 'row cap must be 100–100000 (default 5000 used)', 'fishotel-misc-plugin' );
			$max_rows  = self::DEFAULTS['max_rows'];
		}

		$email_raw = isset( $raw['alert_email'] ) && is_scalar( $raw['alert_email'] ) ? trim( (string) $raw['alert_email'] ) : '';
		$email     = self::clean_email( $email_raw );
		if ( '' !== $email_raw && '' === $email ) {
			$dropped[] = __( 'alert email is not a valid address (alerts off)', 'fishotel-misc-plugin' );
		}

		$cooldown = isset( $raw['alert_cooldown_minutes'] ) && is_scalar( $raw['alert_cooldown_minutes'] ) ? (int) $raw['alert_cooldown_minutes'] : self::DEFAULTS['alert_cooldown_minutes'];
		if ( $cooldown < self::COOLDOWN_MIN || $cooldown > self::COOLDOWN_MAX ) {
			$dropped[] = __( 'alert cooldown must be 5–1440 minutes (default 60 used)', 'fishotel-misc-plugin' );
			$cooldown  = self::DEFAULTS['alert_cooldown_minutes'];
		}

		$hosts_raw = $raw['extra_hosts'] ?? array();
		if ( is_string( $hosts_raw ) ) {
			$hosts_raw = preg_split( '/[\s,]+/', $hosts_raw );
		}
		$hosts = self::clean_hosts( $hosts_raw );

		return self::normalize(
			array(
				'retention_days'         => $retention,
				'max_rows'               => $max_rows,
				'store_debug'            => ! empty( $raw['store_debug'] ),
				'capture_fatals'         => ! empty( $raw['capture_fatals'] ),
				'capture_http'           => ! empty( $raw['capture_http'] ),
				'extra_hosts'            => $hosts,
				'alert_email'            => $email,
				'alert_cooldown_minutes' => $cooldown,
			)
		);
	}

	/**
	 * An int within [min, max], else the default.
	 *
	 * @param mixed $value   Raw.
	 * @param int   $min     Min.
	 * @param int   $max     Max.
	 * @param int   $default Default.
	 * @return int
	 */
	public static function clamp( $value, $min, $max, $default ) {
		if ( ! is_scalar( $value ) || ! is_numeric( $value ) ) {
			return (int) $default;
		}

		$value = (int) $value;

		return ( $value < $min || $value > $max ) ? (int) $default : $value;
	}

	/**
	 * A valid email or ''.
	 *
	 * @param mixed $email Raw.
	 * @return string
	 */
	public static function clean_email( $email ) {
		$email = is_scalar( $email ) ? trim( (string) $email ) : '';

		if ( '' === $email ) {
			return '';
		}

		if ( function_exists( 'sanitize_email' ) ) {
			return (string) sanitize_email( $email );
		}

		return filter_var( $email, FILTER_VALIDATE_EMAIL ) ? $email : '';
	}

	/**
	 * Lowercase hostnames only, deduplicated, max 20.
	 *
	 * @param mixed $hosts Raw list.
	 * @return string[]
	 */
	public static function clean_hosts( $hosts ) {
		$out = array();

		foreach ( (array) $hosts as $host ) {
			$host = is_scalar( $host ) ? strtolower( trim( (string) $host ) ) : '';
			$host = preg_replace( '#^[a-z]+://#', '', $host );
			$host = (string) preg_replace( '#[/?\#].*$#', '', (string) $host );

			if ( '' === $host || 1 !== preg_match( '/^[a-z0-9.-]+\.[a-z]{2,}$/', $host ) ) {
				continue;
			}

			if ( ! in_array( $host, $out, true ) ) {
				$out[] = $host;
			}

			if ( count( $out ) >= 20 ) {
				break;
			}
		}

		return $out;
	}

	/* ============================================================== */
	/*  Alert email                                                    */
	/* ============================================================== */

	/**
	 * Whether a row qualifies for an alert (pure).
	 *
	 * @param array $row      Stored row (level, source).
	 * @param array $settings Settings.
	 * @return bool
	 */
	public static function should_alert( array $row, array $settings ) {
		if ( '' === (string) ( $settings['alert_email'] ?? '' ) ) {
			return false;
		}

		if ( ! in_array( (string) ( $row['level'] ?? '' ), self::ALERT_LEVELS, true ) ) {
			return false;
		}

		return in_array( (string) ( $row['source'] ?? '' ), self::ALERT_SOURCES, true );
	}

	/**
	 * The plain-text alert body (pure).
	 *
	 * @param array  $row       Stored row.
	 * @param string $site_name Site name.
	 * @param string $page_url  Health page URL.
	 * @return string
	 */
	public static function alert_body( array $row, $site_name, $page_url ) {
		$lines = array(
			sprintf( '%s logged a %s from "%s":', $site_name, (string) ( $row['level'] ?? 'error' ), (string) ( $row['source'] ?? 'app' ) ),
			'',
			(string) ( $row['message'] ?? '' ),
			'',
			'When (UTC): ' . (string) ( $row['logged_at'] ?? '' ),
			'',
			'Open the Health page: ' . $page_url,
			'',
			'You get at most one of these per cooldown window; the log has the rest.',
		);

		return implode( "\n", $lines );
	}

	/**
	 * Logger after-insert callback: send one alert per cooldown window.
	 *
	 * @param array $row Stored row.
	 */
	public static function maybe_alert( array $row ) {
		if ( self::$sending ) {
			return;
		}

		$settings = self::get();

		if ( ! self::should_alert( $row, $settings ) ) {
			return;
		}

		if ( get_transient( self::ALERT_TRANSIENT ) ) {
			return;
		}

		set_transient( self::ALERT_TRANSIENT, time(), (int) $settings['alert_cooldown_minutes'] * MINUTE_IN_SECONDS );

		self::$sending = true;

		try {
			$site = function_exists( 'get_bloginfo' ) ? (string) get_bloginfo( 'name' ) : 'FisHotel';
			$url  = function_exists( 'admin_url' ) ? admin_url( 'admin.php?page=' . Health::MENU_SLUG ) : '';

			wp_mail(
				$settings['alert_email'],
				sprintf( '[%s] Health alert: %s (%s)', $site, (string) ( $row['level'] ?? 'error' ), (string) ( $row['source'] ?? 'app' ) ),
				self::alert_body( $row, $site, $url ),
				array( 'Content-Type: text/plain; charset=UTF-8' )
			);
		} catch ( \Throwable $e ) {
			unset( $e );
		}

		self::$sending = false;
	}
}
