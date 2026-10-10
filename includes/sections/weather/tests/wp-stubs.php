<?php
/**
 * Just enough WordPress to load the Weather section's pure classes headless.
 *
 * Bands, Note and Forecast touch no WordPress function at all; Settings and
 * Cron only need the translation helpers, wp_parse_args and get_option at
 * call time; Checker::lookup() needs WP_Error. Nothing here talks to the
 * network or a database — the client is replaced by a fake in the tests.
 *
 * @package FisHotel\Misc\Sections\Weather
 */

define( 'ABSPATH', true );
define( 'FISHOTEL_MISC_PATH', dirname( __DIR__, 4 ) . '/' );
define( 'FISHOTEL_MISC_URL', 'https://example.test/wp-content/plugins/fishotel-misc-plugin/' );
define( 'FISHOTEL_MISC_VERSION', 'test' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );

function __( $text, $domain = null ) {
	return $text;
}

function _n( $single, $plural, $number, $domain = null ) {
	return 1 === $number ? $single : $plural;
}

function esc_html( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}

function esc_attr( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
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

function sanitize_html_class( $class ) {
	return preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $class );
}

function wp_parse_args( $args, $defaults = array() ) {
	return array_merge( $defaults, (array) $args );
}

/**
 * Options live in $GLOBALS['fh_options'] so a test can seed settings and
 * read back what the code wrote (e.g. the last-run summary).
 */
$GLOBALS['fh_options'] = array();

function get_option( $key, $default = false ) {
	if ( 'admin_email' === $key ) {
		return 'jeff@example.test';
	}
	return array_key_exists( $key, $GLOBALS['fh_options'] ) ? $GLOBALS['fh_options'][ $key ] : $default;
}

function update_option( $key, $value, $autoload = null ) {
	$GLOBALS['fh_options'][ $key ] = $value;
	return true;
}

/**
 * "Now" in site time, fixed per test via $GLOBALS['fh_now'] (Y-m-d H:i:s).
 */
$GLOBALS['fh_now'] = '2026-04-01 21:00:00';

function current_time( $type, $gmt = 0 ) {
	$ts = strtotime( $GLOBALS['fh_now'] );
	if ( 'mysql' === $type ) {
		return date( 'Y-m-d H:i:s', $ts );
	}
	if ( 'timestamp' === $type ) {
		return $ts;
	}
	return date( $type, $ts );
}

function wp_timezone() {
	return new \DateTimeZone( 'America/Chicago' );
}

/**
 * WP-Cron, reduced to a list of pending (timestamp, hook, args) events.
 */
$GLOBALS['fh_cron'] = array();

function wp_schedule_single_event( $timestamp, $hook, $args = array() ) {
	$GLOBALS['fh_cron'][] = array( (int) $timestamp, (string) $hook, $args );
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

class WP_Error {

	private $code;
	private $message;

	public function __construct( $code, $message = '' ) {
		$this->code    = $code;
		$this->message = $message;
	}

	public function get_error_code() {
		return $this->code;
	}

	public function get_error_message() {
		return $this->message;
	}
}

function is_wp_error( $thing ) {
	return $thing instanceof WP_Error;
}

require_once FISHOTEL_MISC_PATH . 'includes/sections/weather/class-weather.php';
require_once FISHOTEL_MISC_PATH . 'includes/sections/weather/class-bands.php';
require_once FISHOTEL_MISC_PATH . 'includes/sections/weather/class-note.php';
require_once FISHOTEL_MISC_PATH . 'includes/sections/weather/class-forecast.php';
require_once FISHOTEL_MISC_PATH . 'includes/sections/weather/class-nws-client.php';
require_once FISHOTEL_MISC_PATH . 'includes/sections/weather/class-settings.php';
require_once FISHOTEL_MISC_PATH . 'includes/sections/weather/class-cron.php';
require_once FISHOTEL_MISC_PATH . 'includes/sections/weather/class-checker.php';

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

function fh_fixture( $name ) {
	return file_get_contents( __DIR__ . '/fixtures/' . $name );
}
