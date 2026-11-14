<?php
/**
 * Just enough WordPress to load the Lost Fish section's classes headless
 * and to drive them: options, transients, hooks (recorded), nonces that
 * match wp_create_nonce(), capabilities per user, a wp_mail() that keeps
 * what it was handed (and can be told to refuse), the cron scheduler
 * (recorded), URLs and the escaping helpers.
 *
 * WooCommerce, the Clean Room and the in-memory store are stood in for by
 * fakes.php. Money, Cover and Token touch no WordPress function at all.
 *
 * Every name and number in these tests is invented.
 *
 * @package FisHotel\Misc\Sections\Lost_Fish
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', '/var/www/html/' ); // store-test.php points it at a temporary "WordPress" first.
}
define( 'FISHOTEL_MISC_PATH', dirname( __DIR__, 4 ) . '/' );
define( 'FISHOTEL_MISC_URL', 'https://example.test/wp-content/plugins/fishotel-misc-plugin/' );
define( 'FISHOTEL_MISC_VERSION', 'test' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'ARRAY_A', 'ARRAY_A' );

/* ------------------------------------------------------------------ */
/*  Text                                                               */
/* ------------------------------------------------------------------ */

function __( $text, $domain = null ) {
	return $text;
}

function _n( $single, $plural, $number, $domain = null ) {
	return 1 === (int) $number ? $single : $plural;
}

function esc_html__( $text, $domain = null ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}

function esc_attr__( $text, $domain = null ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}

function esc_html_e( $text, $domain = null ) {
	echo htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
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
	return str_replace( array( '"', "'", '<', '>' ), array( '%22', '%27', '%3C', '%3E' ), (string) $url );
}

function sanitize_key( $key ) {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
}

function sanitize_text_field( $text ) {
	return trim( preg_replace( '/[\r\n\t ]+/', ' ', strip_tags( (string) $text ) ) );
}

function sanitize_textarea_field( $text ) {
	return trim( strip_tags( (string) $text ) );
}

function is_email( $email ) {
	return 1 === preg_match( '/^[^@\s]+@[^@\s]+\.[^@\s]+$/', (string) $email ) ? $email : false;
}

function sanitize_email( $email ) {
	$email = trim( (string) $email );
	return is_email( $email ) ? $email : '';
}

function wp_unslash( $value ) {
	return is_array( $value ) ? array_map( 'wp_unslash', $value ) : stripslashes( (string) $value );
}

function absint( $value ) {
	return abs( (int) $value );
}

/* ------------------------------------------------------------------ */
/*  Options, transients                                                */
/* ------------------------------------------------------------------ */

$GLOBALS['fh_options']       = array( 'blogname' => 'Example Reef Shop' );
$GLOBALS['fh_option_writes'] = 0;

function get_option( $key, $default = false ) {
	return array_key_exists( $key, $GLOBALS['fh_options'] ) ? $GLOBALS['fh_options'][ $key ] : $default;
}

function update_option( $key, $value, $autoload = null ) {
	$GLOBALS['fh_options'][ $key ] = $value;
	$GLOBALS['fh_option_writes']++;
	$GLOBALS['fh_autoload'][ $key ] = $autoload;
	return true;
}

function add_option( $key, $value = '', $deprecated = '', $autoload = 'yes' ) {
	if ( array_key_exists( $key, $GLOBALS['fh_options'] ) ) {
		return false;
	}
	$GLOBALS['fh_options'][ $key ] = $value;
	return true;
}

function delete_option( $key ) {
	unset( $GLOBALS['fh_options'][ $key ] );
	return true;
}

function get_bloginfo( $show = '' ) {
	return 'name' === $show ? (string) get_option( 'blogname', '' ) : '';
}

$GLOBALS['fh_transients'] = array();

function set_transient( $key, $value, $expiration = 0 ) {
	$GLOBALS['fh_transients'][ $key ] = $value;
	return true;
}

function get_transient( $key ) {
	return isset( $GLOBALS['fh_transients'][ $key ] ) ? $GLOBALS['fh_transients'][ $key ] : false;
}

function delete_transient( $key ) {
	unset( $GLOBALS['fh_transients'][ $key ] );
	return true;
}

/* ------------------------------------------------------------------ */
/*  Hooks (a flat registry; every do_action is recorded)               */
/* ------------------------------------------------------------------ */

$GLOBALS['fh_actions'] = array();
$GLOBALS['fh_filters'] = array();
$GLOBALS['fh_fired']   = array();

function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
	$GLOBALS['fh_actions'][ $hook ][] = array( $callback, (int) $accepted_args, (int) $priority );
	return true;
}

function do_action( $hook, ...$args ) {
	$GLOBALS['fh_fired'][] = array( $hook, $args );
	foreach ( $GLOBALS['fh_actions'][ $hook ] ?? array() as $entry ) {
		call_user_func_array( $entry[0], array_slice( $args, 0, $entry[1] ) );
	}
}

function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
	$GLOBALS['fh_filters'][ $hook ][] = array( $callback, (int) $accepted_args, (int) $priority );
	return true;
}

function remove_filter( $hook, $callback, $priority = 10 ) {
	foreach ( $GLOBALS['fh_filters'][ $hook ] ?? array() as $index => $entry ) {
		if ( $entry[0] === $callback ) {
			unset( $GLOBALS['fh_filters'][ $hook ][ $index ] );
			return true;
		}
	}
	return false;
}

function apply_filters( $hook, $value, ...$args ) {
	foreach ( $GLOBALS['fh_filters'][ $hook ] ?? array() as $entry ) {
		$value = call_user_func_array( $entry[0], array_merge( array( $value ), array_slice( $args, 0, max( 0, $entry[1] - 1 ) ) ) );
	}
	return $value;
}

/**
 * The actions fired with a name, in order.
 *
 * @param string $hook Action name.
 * @return array[] Their argument lists.
 */
function fh_fired( $hook ) {
	$out = array();
	foreach ( $GLOBALS['fh_fired'] as $entry ) {
		if ( $entry[0] === $hook ) {
			$out[] = $entry[1];
		}
	}
	return $out;
}

/* ------------------------------------------------------------------ */
/*  Users, nonces                                                      */
/* ------------------------------------------------------------------ */

$GLOBALS['fh_user_caps']    = array();
$GLOBALS['fh_current_user'] = 0;

function get_current_user_id() {
	return (int) $GLOBALS['fh_current_user'];
}

function current_user_can( $capability, ...$args ) {
	return in_array( get_current_user_id(), $GLOBALS['fh_user_caps'][ $capability ] ?? array(), true );
}

function wp_create_nonce( $action = -1 ) {
	return 'nonce-' . substr( md5( $action . '|' . get_current_user_id() ), 0, 10 );
}

function wp_verify_nonce( $nonce, $action = -1 ) {
	return is_string( $nonce ) && '' !== $nonce && hash_equals( wp_create_nonce( $action ), $nonce ) ? 1 : false;
}

function wp_nonce_field( $action = -1, $name = '_wpnonce', $referer = true, $display = true ) {
	$field = '<input type="hidden" name="' . esc_attr( $name ) . '" value="' . esc_attr( wp_create_nonce( $action ) ) . '">';
	if ( $display ) {
		echo $field;
	}
	return $field;
}

function get_userdata( $user_id ) {
	return (object) array(
		'ID'           => (int) $user_id,
		'display_name' => 'User ' . (int) $user_id,
	);
}

/* ------------------------------------------------------------------ */
/*  URLs, forms, time                                                  */
/* ------------------------------------------------------------------ */

function admin_url( $path = '' ) {
	return 'https://example.test/wp-admin/' . ltrim( (string) $path, '/' );
}

function home_url( $path = '' ) {
	return 'https://example.test' . ( '' !== (string) $path ? '/' . ltrim( (string) $path, '/' ) : '' );
}

/**
 * As WordPress: add_query_arg( array, url ) or add_query_arg( key, value, url ).
 */
function add_query_arg( ...$args ) {
	if ( is_array( $args[0] ) ) {
		$pairs = $args[0];
		$url   = isset( $args[1] ) ? (string) $args[1] : '';
	} else {
		$pairs = array( $args[0] => $args[1] );
		$url   = isset( $args[2] ) ? (string) $args[2] : '';
	}
	return $url . ( false === strpos( $url, '?' ) ? '?' : '&' ) . http_build_query( $pairs );
}

function checked( $checked, $current = true, $display = true ) {
	$out = (string) $checked === (string) $current ? ' checked="checked"' : '';
	if ( $display ) {
		echo $out;
	}
	return $out;
}

function disabled( $disabled, $current = true, $display = true ) {
	$out = (string) $disabled === (string) $current ? ' disabled="disabled"' : '';
	if ( $display ) {
		echo $out;
	}
	return $out;
}

function wp_timezone() {
	return new \DateTimeZone( 'America/Chicago' );
}

function is_admin() {
	return ! empty( $GLOBALS['fh_is_admin'] );
}

function wp_salt( $scheme = 'auth' ) {
	return 'test-salt-' . $scheme;
}

/* ------------------------------------------------------------------ */
/*  Mail and cron (recorded)                                           */
/* ------------------------------------------------------------------ */

$GLOBALS['fh_mail']      = array();
$GLOBALS['fh_mail_fail'] = false;
$GLOBALS['fh_cron']      = array();

function wp_mail( $to, $subject, $message, $headers = '', $attachments = array() ) {
	if ( ! empty( $GLOBALS['fh_mail_fail'] ) ) {
		return false;
	}
	$GLOBALS['fh_mail'][] = array(
		'to'        => $to,
		'subject'   => $subject,
		'message'   => $message,
		'headers'   => $headers,
		'from'      => apply_filters( 'wp_mail_from', 'wordpress@example.test' ),
		'from_name' => apply_filters( 'wp_mail_from_name', 'WordPress' ),
	);
	return true;
}

function wp_schedule_single_event( $timestamp, $hook, $args = array() ) {
	$GLOBALS['fh_cron'][] = array( 'single', (int) $timestamp, $hook );
	return true;
}

function wp_schedule_event( $timestamp, $recurrence, $hook, $args = array() ) {
	$GLOBALS['fh_cron'][] = array( $recurrence, (int) $timestamp, $hook );
	return true;
}

function wp_next_scheduled( $hook, $args = array() ) {
	foreach ( $GLOBALS['fh_cron'] as $entry ) {
		if ( $entry[2] === $hook && 'single' !== $entry[0] ) {
			return $entry[1];
		}
	}
	return false;
}

function wp_clear_scheduled_hook( $hook, $args = array() ) {
	foreach ( $GLOBALS['fh_cron'] as $index => $entry ) {
		if ( $entry[2] === $hook ) {
			unset( $GLOBALS['fh_cron'][ $index ] );
		}
	}
	return 1;
}

/* ------------------------------------------------------------------ */
/*  The section                                                        */
/* ------------------------------------------------------------------ */

require_once FISHOTEL_MISC_PATH . 'includes/sections/lost-fish/class-lost-fish.php';
\FisHotel\Misc\Sections\Lost_Fish\Lost_Fish::load();
require_once FISHOTEL_MISC_PATH . 'includes/sections/lost-fish/class-admin.php';

/* ------------------------------------------------------------------ */
/*  check()                                                            */
/* ------------------------------------------------------------------ */

$GLOBALS['fh_failures'] = 0;
$GLOBALS['fh_checks']   = 0;

// A notice or a warning anywhere is a failure, not noise.
set_error_handler(
	static function ( $severity, $message, $file, $line ) {
		$GLOBALS['fh_failures']++;
		$GLOBALS['fh_checks']++;
		echo "  FAIL PHP said: $message (" . basename( $file ) . ":$line)\n";
		return true;
	}
);

function check( $label, $condition, $detail = '' ) {
	$GLOBALS['fh_checks']++;

	if ( $condition ) {
		echo "  ok   $label\n";
		return;
	}

	$GLOBALS['fh_failures']++;
	echo "  FAIL $label" . ( '' !== $detail ? "\n       $detail" : '' ) . "\n";
}

function check_same( $label, $expected, $actual ) {
	check( $label, $expected === $actual, 'expected ' . fh_test_export( $expected ) . ', got ' . fh_test_export( $actual ) );
}

function check_contains( $label, $needle, $haystack ) {
	check( $label, false !== strpos( (string) $haystack, (string) $needle ), '"' . $needle . '" not found in ' . fh_test_export( $haystack ) );
}

function check_lacks( $label, $needle, $haystack ) {
	check( $label, false === strpos( (string) $haystack, (string) $needle ), '"' . $needle . '" found in ' . fh_test_export( $haystack ) );
}

function fh_test_export( $value ) {
	$text = var_export( $value, true );
	$text = preg_replace( '/\s+/', ' ', $text );
	return strlen( $text ) > 400 ? substr( $text, 0, 397 ) . '...' : $text;
}

function fh_finish() {
	$failures = $GLOBALS['fh_failures'];
	$checks   = $GLOBALS['fh_checks'];

	echo "\n" . ( $failures ? "$failures of $checks checks FAILED\n" : "All $checks checks passed\n" );
	exit( $failures ? 1 : 0 );
}
