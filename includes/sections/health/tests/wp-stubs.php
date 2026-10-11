<?php
/**
 * Just enough WordPress to load the Health section's classes headless.
 *
 * Logger and Checks touch no WordPress function at all; Capture needs the
 * action registry, transients and WP_Error; Settings::maybe_alert() needs
 * options, transients and wp_mail; Admin::csv_cell() needs nothing. The
 * Store is replaced by fake-store.php — no $wpdb anywhere in these tests.
 *
 * @package FisHotel\Misc\Sections\Health
 */

define( 'ABSPATH', '/var/www/html/' );
define( 'WP_CONTENT_DIR', '/var/www/html/wp-content' );
define( 'FISHOTEL_MISC_PATH', dirname( __DIR__, 4 ) . '/' );
define( 'FISHOTEL_MISC_URL', 'https://example.test/wp-content/plugins/fishotel-misc-plugin/' );
define( 'FISHOTEL_MISC_VERSION', 'test' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );
// Core's defaults on a stock wp-config: WP_DEBUG off, WP_DEBUG_DISPLAY
// defined true by default-constants.php (and ignored while WP_DEBUG is off).
define( 'WP_DEBUG', false );
define( 'WP_DEBUG_DISPLAY', true );
define( 'WP_MEMORY_LIMIT', '40M' );
define( 'WP_MAX_MEMORY_LIMIT', '256M' );

function __( $text, $domain = null ) {
	return $text;
}

function esc_html__( $text, $domain = null ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}

function esc_html( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}

function esc_attr( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}

function esc_url( $url ) {
	return (string) $url;
}

function sanitize_key( $key ) {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
}

function sanitize_email( $email ) {
	$email = trim( (string) $email );
	return filter_var( $email, FILTER_VALIDATE_EMAIL ) ? $email : '';
}

function sanitize_text_field( $text ) {
	return trim( strip_tags( (string) $text ) );
}

function wp_unslash( $value ) {
	return is_array( $value ) ? array_map( 'wp_unslash', $value ) : stripslashes( (string) $value );
}

function absint( $value ) {
	return abs( (int) $value );
}

function wp_parse_args( $args, $defaults = array() ) {
	return array_merge( $defaults, (array) $args );
}

function wp_parse_url( $url, $component = -1 ) {
	return parse_url( (string) $url, $component );
}

function wp_json_encode( $data, $flags = 0, $depth = 512 ) {
	return json_encode( $data, $flags, $depth );
}

function get_bloginfo( $show = '' ) {
	return 'version' === $show ? '6.6' : 'FisHotel';
}

function admin_url( $path = '' ) {
	return 'https://example.test/wp-admin/' . ltrim( (string) $path, '/' );
}

function add_query_arg( $args, $url = '' ) {
	if ( ! is_array( $args ) ) {
		return $url;
	}
	return $url . ( false === strpos( $url, '?' ) ? '?' : '&' ) . http_build_query( $args );
}

/**
 * Options live in $GLOBALS['fh_options'] so a test can seed and read back.
 */
$GLOBALS['fh_options'] = array();

function get_option( $key, $default = false ) {
	return array_key_exists( $key, $GLOBALS['fh_options'] ) ? $GLOBALS['fh_options'][ $key ] : $default;
}

function update_option( $key, $value, $autoload = null ) {
	$GLOBALS['fh_options'][ $key ] = $value;
	return true;
}

/**
 * Transients, with their expiry so a test can assert the TTL.
 */
$GLOBALS['fh_transients'] = array();

function set_transient( $key, $value, $expiration = 0 ) {
	$GLOBALS['fh_transients'][ $key ] = array( $value, (int) $expiration );
	return true;
}

function get_transient( $key ) {
	return isset( $GLOBALS['fh_transients'][ $key ] ) ? $GLOBALS['fh_transients'][ $key ][0] : false;
}

function delete_transient( $key ) {
	unset( $GLOBALS['fh_transients'][ $key ] );
	return true;
}

/**
 * "Now" fixed per test via $GLOBALS['fh_now'] (Y-m-d H:i:s, UTC).
 */
$GLOBALS['fh_now'] = '2026-04-01 21:00:00';

function current_time( $type, $gmt = 0 ) {
	$ts = strtotime( $GLOBALS['fh_now'] . ' UTC' );
	if ( 'mysql' === $type ) {
		return gmdate( 'Y-m-d H:i:s', $ts );
	}
	if ( 'timestamp' === $type ) {
		return $ts;
	}
	return gmdate( $type, $ts );
}

function wp_timezone() {
	return new \DateTimeZone( 'America/Chicago' );
}

/**
 * WP-Cron, reduced to a list of pending (timestamp, hook) events.
 */
$GLOBALS['fh_cron'] = array();

function wp_schedule_event( $timestamp, $recurrence, $hook, $args = array() ) {
	$GLOBALS['fh_cron'][] = array( (int) $timestamp, (string) $hook, $recurrence );
	return true;
}

function wp_schedule_single_event( $timestamp, $hook, $args = array() ) {
	$GLOBALS['fh_cron'][] = array( (int) $timestamp, (string) $hook, 'single' );
	return true;
}

function wp_clear_scheduled_hook( $hook, $args = array() ) {
	$before             = count( $GLOBALS['fh_cron'] );
	$GLOBALS['fh_cron'] = array_values(
		array_filter(
			$GLOBALS['fh_cron'],
			static function ( $event ) use ( $hook ) {
				return $event[1] !== $hook;
			}
		)
	);
	return $before - count( $GLOBALS['fh_cron'] );
}

function wp_next_scheduled( $hook, $args = array() ) {
	foreach ( $GLOBALS['fh_cron'] as $event ) {
		if ( $event[1] === $hook ) {
			return $event[0];
		}
	}
	return false;
}

/**
 * Actions: a flat registry so Capture's hooks can be fired by do_action().
 */
$GLOBALS['fh_actions'] = array();

function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
	$GLOBALS['fh_actions'][ $hook ][] = array( $callback, (int) $accepted_args );
	return true;
}

function has_action( $hook, $callback = false ) {
	return ! empty( $GLOBALS['fh_actions'][ $hook ] );
}

function do_action( $hook, ...$args ) {
	foreach ( $GLOBALS['fh_actions'][ $hook ] ?? array() as $entry ) {
		call_user_func_array( $entry[0], array_slice( $args, 0, $entry[1] ) );
	}
}

function fh_reset_actions() {
	$GLOBALS['fh_actions'] = array();
}

/**
 * wp_mail: records every call; a test may install a hook that fails it.
 */
$GLOBALS['fh_mails']     = array();
$GLOBALS['fh_mail_fail'] = false;

function wp_mail( $to, $subject, $message, $headers = '', $attachments = array() ) {
	$GLOBALS['fh_mails'][] = compact( 'to', 'subject', 'message', 'headers' );

	if ( $GLOBALS['fh_mail_fail'] ) {
		do_action(
			'wp_mail_failed',
			new WP_Error(
				'wp_mail_failed',
				'SMTP connect() failed.',
				array(
					'to'      => (array) $to,
					'subject' => $subject,
					'message' => $message,
					'headers' => $headers,
				)
			)
		);
		return false;
	}

	return true;
}

class WP_Error {

	private $code;
	private $message;
	private $data;

	public function __construct( $code, $message = '', $data = '' ) {
		$this->code    = $code;
		$this->message = $message;
		$this->data    = $data;
	}

	public function get_error_code() {
		return $this->code;
	}

	public function get_error_message() {
		return $this->message;
	}

	public function get_error_data() {
		return $this->data;
	}
}

function is_wp_error( $thing ) {
	return $thing instanceof WP_Error;
}

require_once FISHOTEL_MISC_PATH . 'includes/sections/health/class-health.php';
require_once FISHOTEL_MISC_PATH . 'includes/sections/health/class-store.php';
require_once FISHOTEL_MISC_PATH . 'includes/sections/health/class-logger.php';
require_once FISHOTEL_MISC_PATH . 'includes/sections/health/class-capture.php';
require_once FISHOTEL_MISC_PATH . 'includes/sections/health/class-checks.php';
require_once FISHOTEL_MISC_PATH . 'includes/sections/health/class-facts.php';
require_once FISHOTEL_MISC_PATH . 'includes/sections/health/class-settings.php';
require_once FISHOTEL_MISC_PATH . 'includes/sections/health/class-cron.php';
require_once FISHOTEL_MISC_PATH . 'includes/sections/health/class-admin.php';
require_once __DIR__ . '/fake-store.php';

/**
 * Shared check() helper for every test file.
 */
$GLOBALS['fh_failures'] = 0;
$GLOBALS['fh_checks']   = 0;

function check( $label, $condition, $detail = '' ) {
	$GLOBALS['fh_checks']++;

	if ( $condition ) {
		echo "  ok   $label\n";
		return;
	}

	$GLOBALS['fh_failures']++;
	echo "  FAIL $label" . ( '' !== $detail ? "\n       $detail" : '' ) . "\n";
}

function fh_finish() {
	$failures = $GLOBALS['fh_failures'];
	$checks   = $GLOBALS['fh_checks'];

	echo "\n" . ( $failures ? "$failures of $checks checks FAILED\n" : "All $checks checks passed\n" );
	exit( $failures ? 1 : 0 );
}
