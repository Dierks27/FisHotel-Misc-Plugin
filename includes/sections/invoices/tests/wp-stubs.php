<?php
/**
 * Just enough WordPress to load the Invoices section's classes headless
 * and to drive its wp_ajax handlers up to their reply: options (with the
 * autoload flag each was written with), the site clock, capabilities per
 * user, nonces that match wp_create_nonce(), the action and filter
 * registries, and wp_send_json_*() / a failed check_ajax_referer() that
 * throw Fh_Test_Json.
 *
 * WooCommerce is stood in for by wc-stubs.php (products, orders, terms,
 * customers — all in memory), so the section's REAL Shop class runs in
 * these tests; only the Store (the one $wpdb file) is replaced, by
 * fake-store.php. No database, no HTTP, no e-mail.
 *
 * Every PHP warning or notice raised while a test runs counts as a
 * failed check.
 *
 * @package FisHotel\Misc\Sections\Invoices
 */

error_reporting( E_ALL );

define( 'ABSPATH', '/var/www/html/' );
define( 'FISHOTEL_MISC_PATH', dirname( __DIR__, 4 ) . '/' );
define( 'FISHOTEL_MISC_URL', 'https://example.test/wp-content/plugins/fishotel-misc-plugin/' );
define( 'FISHOTEL_MISC_VERSION', 'test' );
if ( ! defined( 'EMPTY_TRASH_DAYS' ) ) {
	define( 'EMPTY_TRASH_DAYS', 30 );
}

function __( $text, $domain = null ) {
	return $text;
}

function _n( $single, $plural, $number, $domain = null ) {
	return 1 === (int) $number ? $single : $plural;
}

function esc_html__( $text, $domain = null ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}

function esc_html_e( $text, $domain = null ) {
	echo htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}

function esc_attr__( $text, $domain = null ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}

function esc_attr_e( $text, $domain = null ) {
	echo htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}

function esc_html( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}

function esc_attr( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}

function esc_textarea( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}

function esc_url( $url ) {
	return (string) $url;
}

function esc_url_raw( $url ) {
	return (string) $url;
}

function selected( $selected, $current = true, $display = true ) {
	$out = (string) $selected === (string) $current ? ' selected="selected"' : '';
	if ( $display ) {
		echo $out;
	}
	return $out;
}

function checked( $checked, $current = true, $display = true ) {
	$out = (string) $checked === (string) $current ? ' checked="checked"' : '';
	if ( $display ) {
		echo $out;
	}
	return $out;
}

function sanitize_key( $key ) {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
}

function sanitize_text_field( $text ) {
	return trim( strip_tags( (string) $text ) );
}

function sanitize_title( $title ) {
	$title = strtolower( trim( strip_tags( (string) $title ) ) );
	$title = preg_replace( '/&[a-z0-9#]+;/', '', $title );
	$title = preg_replace( '/[^a-z0-9\s\-_\/]/', '', $title );
	$title = preg_replace( '/[\s\/_]+/', '-', $title );
	return trim( preg_replace( '/-+/', '-', $title ), '-' );
}

function wp_unslash( $value ) {
	return is_array( $value ) ? array_map( 'wp_unslash', $value ) : ( is_string( $value ) ? stripslashes( $value ) : $value );
}

function absint( $value ) {
	return abs( (int) $value );
}

function wp_json_encode( $data, $flags = 0, $depth = 512 ) {
	return json_encode( $data, $flags, $depth );
}

function admin_url( $path = '' ) {
	return 'https://example.test/wp-admin/' . ltrim( (string) $path, '/' );
}

function home_url( $path = '' ) {
	return 'https://example.test' . ( '' !== (string) $path ? '/' . ltrim( (string) $path, '/' ) : '' );
}

function add_query_arg( $args, $url = '' ) {
	if ( ! is_array( $args ) ) {
		return $url;
	}
	return $url . ( false === strpos( $url, '?' ) ? '?' : '&' ) . http_build_query( $args );
}

function wp_salt( $scheme = 'auth' ) {
	return 'test-salt-' . $scheme;
}

function wp_create_nonce( $action = -1 ) {
	return 'nonce-' . substr( md5( (string) $action ), 0, 10 );
}

function wp_verify_nonce( $nonce, $action = -1 ) {
	return is_string( $nonce ) && '' !== $nonce && hash_equals( wp_create_nonce( $action ), $nonce ) ? 1 : false;
}

function wp_nonce_url( $url, $action = -1, $name = '_wpnonce' ) {
	return add_query_arg( array( $name => wp_create_nonce( $action ) ), (string) $url );
}

function is_wp_error( $thing ) {
	return false;
}

function wp_kses( $html, $allowed ) {
	return strip_tags( (string) $html, '<' . implode( '><', array_keys( (array) $allowed ) ) . '>' );
}

function wp_date( $format, $timestamp = null ) {
	$date = new \DateTime( '@' . (int) $timestamp );
	$date->setTimezone( wp_timezone() );
	return $date->format( $format );
}

/**
 * Styles and scripts a screen enqueued: handle → array( src, data ).
 */
$GLOBALS['fh_enqueued'] = array();

function wp_enqueue_style( $handle, $src = '', $deps = array(), $ver = false ) {
	$GLOBALS['fh_enqueued'][ 'style:' . $handle ] = array( $src, null );
}

function wp_enqueue_script( $handle, $src = '', $deps = array(), $ver = false, $in_footer = false ) {
	$GLOBALS['fh_enqueued'][ 'script:' . $handle ] = array( $src, null );
}

function wp_localize_script( $handle, $name, $data ) {
	$GLOBALS['fh_enqueued'][ 'data:' . $name ] = array( $handle, $data );
}

function wp_enqueue_media() {
	$GLOBALS['fh_enqueued']['media'] = array( '', null );
}

function is_admin() {
	return ! empty( $GLOBALS['fh_is_admin'] );
}

/**
 * Options live in $GLOBALS['fh_options']; the autoload flag each was
 * last written with in $GLOBALS['fh_autoload'].
 */
$GLOBALS['fh_options']  = array();
$GLOBALS['fh_autoload'] = array();

function get_option( $key, $default = false ) {
	return array_key_exists( $key, $GLOBALS['fh_options'] ) ? $GLOBALS['fh_options'][ $key ] : $default;
}

function update_option( $key, $value, $autoload = null ) {
	$GLOBALS['fh_options'][ $key ]  = $value;
	$GLOBALS['fh_autoload'][ $key ] = $autoload;
	return true;
}

function delete_option( $key ) {
	unset( $GLOBALS['fh_options'][ $key ], $GLOBALS['fh_autoload'][ $key ] );
	return true;
}

/**
 * "Now" on the site's clock, fixed per test via $GLOBALS['fh_now'].
 */
$GLOBALS['fh_now'] = '2026-11-03 09:00:00';

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
 * Capabilities per user: $GLOBALS['fh_user_caps'][ cap ] = array( user ids ).
 */
$GLOBALS['fh_user_caps']    = array();
$GLOBALS['fh_current_user'] = 0;

function get_current_user_id() {
	return (int) $GLOBALS['fh_current_user'];
}

function current_user_can( $capability, ...$args ) {
	return in_array( get_current_user_id(), isset( $GLOBALS['fh_user_caps'][ $capability ] ) ? $GLOBALS['fh_user_caps'][ $capability ] : array(), true );
}

/**
 * The AJAX replies and the AJAX nonce check: wp_send_json_*() ends the
 * request in WordPress; here it throws, so a test can call a real
 * wp_ajax handler and read what it would have answered.
 */
class Fh_Test_Json extends \RuntimeException {

	public $success = false;
	public $status  = 200;
	public $data    = null;

	public function __construct( $success, $data, $status ) {
		parent::__construct( is_array( $data ) && isset( $data['message'] ) ? (string) $data['message'] : '' );
		$this->success = (bool) $success;
		$this->data    = $data;
		$this->status  = (int) $status;
	}
}

function wp_send_json_error( $data = null, $status_code = null ) {
	throw new Fh_Test_Json( false, $data, null === $status_code ? 200 : (int) $status_code );
}

function wp_send_json_success( $data = null, $status_code = null ) {
	throw new Fh_Test_Json( true, $data, null === $status_code ? 200 : (int) $status_code );
}

function check_ajax_referer( $action = -1, $query_arg = false, $stop = true ) {
	$nonce = $query_arg && isset( $_REQUEST[ $query_arg ] ) && is_scalar( $_REQUEST[ $query_arg ] ) ? (string) $_REQUEST[ $query_arg ] : '';
	$valid = wp_verify_nonce( $nonce, $action );
	if ( ! $valid && $stop ) {
		throw new Fh_Test_Json( false, array( 'message' => 'bad nonce' ), 403 );
	}
	return $valid;
}

function wp_die( $message = '' ) {
	throw new \RuntimeException( 'wp_die: ' . (string) $message );
}

/**
 * Actions and filters: a flat registry; every do_action() is also logged
 * in $GLOBALS['fh_fired'] as array( hook, args ).
 */
$GLOBALS['fh_actions'] = array();
$GLOBALS['fh_filters'] = array();
$GLOBALS['fh_fired']   = array();

function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
	$GLOBALS['fh_actions'][ $hook ][] = array( $callback, (int) $accepted_args );
	return true;
}

function has_action( $hook, $callback = false ) {
	return ! empty( $GLOBALS['fh_actions'][ $hook ] );
}

function do_action( $hook, ...$args ) {
	$GLOBALS['fh_fired'][] = array( $hook, $args );
	foreach ( isset( $GLOBALS['fh_actions'][ $hook ] ) ? $GLOBALS['fh_actions'][ $hook ] : array() as $entry ) {
		call_user_func_array( $entry[0], array_slice( $args, 0, $entry[1] ) );
	}
}

function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
	$GLOBALS['fh_filters'][ $hook ][] = array( $callback, (int) $accepted_args );
	return true;
}

function apply_filters( $hook, $value, ...$args ) {
	foreach ( isset( $GLOBALS['fh_filters'][ $hook ] ) ? $GLOBALS['fh_filters'][ $hook ] : array() as $entry ) {
		$value = call_user_func_array( $entry[0], array_merge( array( $value ), array_slice( $args, 0, max( 0, $entry[1] - 1 ) ) ) );
	}
	return $value;
}

/**
 * How often an action fired with exactly these arguments.
 *
 * @param string     $hook Hook.
 * @param array|null $args Arguments (null = any).
 * @return int
 */
function fh_fired( $hook, $args = null ) {
	$count = 0;
	foreach ( $GLOBALS['fh_fired'] as $entry ) {
		if ( $entry[0] === $hook && ( null === $args || $entry[1] === $args ) ) {
			$count++;
		}
	}
	return $count;
}

require_once __DIR__ . '/wc-stubs.php';

require_once FISHOTEL_MISC_PATH . 'includes/sections/invoices/class-invoices.php';

\FisHotel\Misc\Sections\Invoices\Invoices::load_admin();

require_once __DIR__ . '/fake-store.php';

/**
 * The check() harness shared by every test file.
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
	echo "  FAIL $label" . ( '' !== (string) $detail ? "\n       $detail" : '' ) . "\n";
}

/**
 * Strict equality with both sides in the failure detail.
 *
 * @param string $label    Label.
 * @param mixed  $expected Expected.
 * @param mixed  $actual   Actual.
 */
function check_same( $label, $expected, $actual ) {
	check( $label, $expected === $actual, 'expected ' . fh_test_export( $expected ) . ', got ' . fh_test_export( $actual ) );
}

/**
 * Substring check.
 *
 * @param string $label    Label.
 * @param string $needle   Needle.
 * @param mixed  $haystack A string, or a list of strings.
 */
function check_contains( $label, $needle, $haystack ) {
	$text = is_array( $haystack ) ? implode( "\n", array_map( 'strval', $haystack ) ) : (string) $haystack;
	check( $label, false !== strpos( $text, (string) $needle ), '"' . $needle . '" not found in ' . fh_test_export( $text ) );
}

function fh_test_export( $value ) {
	$text = var_export( $value, true );
	$text = preg_replace( '/\s+/', ' ', $text );
	return strlen( $text ) > 600 ? substr( $text, 0, 597 ) . '...' : $text;
}

function fh_finish() {
	$failures = $GLOBALS['fh_failures'];
	$checks   = $GLOBALS['fh_checks'];

	echo "\n" . ( $failures ? "$failures of $checks checks FAILED\n" : "All $checks checks passed\n" );
	exit( $failures ? 1 : 0 );
}

// A PHP warning or notice in the code under test is a failure, not noise.
set_error_handler(
	static function ( $severity, $message, $file, $line ) {
		$GLOBALS['fh_failures']++;
		$GLOBALS['fh_checks']++;
		echo "  FAIL PHP notice: $message at " . basename( $file ) . ":$line\n";
		return true;
	}
);
