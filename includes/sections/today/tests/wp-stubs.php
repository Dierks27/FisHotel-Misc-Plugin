<?php
/**
 * Just enough WordPress to load the Today section headless.
 *
 * Model is pure; Settings needs the translation helpers, wp_parse_args
 * and options; Collector needs transients, current_time, wp_timezone,
 * the filter registry and — through the Weather section's Checker — the
 * fake order store in fake-order.php. Nothing here talks to a database.
 *
 * @package FisHotel\Misc\Sections\Today
 */

define( 'ABSPATH', '/var/www/html/' );
define( 'FISHOTEL_MISC_PATH', dirname( __DIR__, 4 ) . '/' );
define( 'FISHOTEL_MISC_URL', 'https://example.test/wp-content/plugins/fishotel-misc-plugin/' );
define( 'FISHOTEL_MISC_VERSION', 'test' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );

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
 * "Now" in site time, fixed per test via $GLOBALS['fh_now'] (Y-m-d H:i:s).
 */
$GLOBALS['fh_now'] = '2025-10-13 08:00:00';

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
 * Actions and filters: a flat registry.
 */
$GLOBALS['fh_actions'] = array();
$GLOBALS['fh_filters'] = array();

function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
	$GLOBALS['fh_actions'][ $hook ][] = array( $callback, (int) $accepted_args );
	return true;
}

function do_action( $hook, ...$args ) {
	foreach ( $GLOBALS['fh_actions'][ $hook ] ?? array() as $entry ) {
		call_user_func_array( $entry[0], array_slice( $args, 0, $entry[1] ) );
	}
}

function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
	$GLOBALS['fh_filters'][ $hook ][] = array( $callback, (int) $accepted_args );
	return true;
}

function remove_all_filters( $hook ) {
	unset( $GLOBALS['fh_filters'][ $hook ] );
	return true;
}

function apply_filters( $hook, $value, ...$args ) {
	foreach ( $GLOBALS['fh_filters'][ $hook ] ?? array() as $entry ) {
		$value = call_user_func_array( $entry[0], array_merge( array( $value ), array_slice( $args, 0, max( 0, $entry[1] - 1 ) ) ) );
	}
	return $value;
}

/**
 * WooCommerce bits the Settings and the Collector read.
 */
function wc_get_order_statuses() {
	return array(
		'wc-pending'     => 'Pending payment',
		'wc-processing'  => 'Processing',
		'wc-on-hold'     => 'On hold',
		'wc-completed'   => 'Completed',
		'wc-cancelled'   => 'Cancelled',
		'wc-refunded'    => 'Refunded',
		'wc-failed'      => 'Failed',
		'wc-fulfillment' => 'Fulfillment',
		'wc-shipped'     => 'Shipped',
	);
}

function wc_get_order_status_name( $status ) {
	$statuses = wc_get_order_statuses();
	$status   = 0 === strpos( (string) $status, 'wc-' ) ? (string) $status : 'wc-' . $status;
	return $statuses[ $status ] ?? ucwords( str_replace( array( 'wc-', '-' ), array( '', ' ' ), (string) $status ) );
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

require_once FISHOTEL_MISC_PATH . 'includes/sections/weather/class-note.php';
require_once FISHOTEL_MISC_PATH . 'includes/sections/weather/class-bands.php';
require_once FISHOTEL_MISC_PATH . 'includes/sections/weather/class-checker.php';
require_once FISHOTEL_MISC_PATH . 'includes/sections/today/class-today.php';
require_once FISHOTEL_MISC_PATH . 'includes/sections/today/class-model.php';
require_once FISHOTEL_MISC_PATH . 'includes/sections/today/class-settings.php';
require_once FISHOTEL_MISC_PATH . 'includes/sections/today/class-collector.php';

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
