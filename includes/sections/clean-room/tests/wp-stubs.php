<?php
/**
 * Just enough WordPress to load the Clean Room section's classes headless.
 *
 * Layout, Replay, Parser, Qr, Costing, Stats, Species, Xlsx, Workbook and
 * Import_Map touch no WordPress function at all; Settings needs the
 * translation helpers, wp_parse_args and options; Admin::csv_cell() /
 * csv_rows() need nothing.
 * The Store class is loaded for its constants and pure statics
 * (hydrate_*, site_noon_utc, fold_species_rows) and replaced by
 * fake-store.php where a store is needed — no $wpdb method is ever called
 * in these tests.
 *
 * @package FisHotel\Misc\Sections\Clean_Room
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

function sanitize_textarea_field( $text ) {
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

function wp_create_nonce( $action = -1 ) {
	return 'nonce-' . substr( md5( (string) $action ), 0, 10 );
}

function wp_nonce_url( $url, $action = -1, $name = '_wpnonce' ) {
	return add_query_arg( array( $name => wp_create_nonce( $action ) ), (string) $url );
}

function wp_generate_password( $length = 12, $special_chars = true, $extra_special_chars = false ) {
	return substr( bin2hex( random_bytes( max( 1, (int) $length ) ) ), 0, max( 1, (int) $length ) );
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

function delete_option( $key ) {
	unset( $GLOBALS['fh_options'][ $key ] );
	return true;
}

/**
 * Transients.
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
$GLOBALS['fh_now'] = '2026-04-01 09:00:00';

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
 * Capabilities per user, seeded by a test: $GLOBALS['fh_user_caps'][ cap ] = array( user ids ).
 */
$GLOBALS['fh_user_caps'] = array();

function user_can( $user, $capability ) {
	$id = is_object( $user ) && isset( $user->ID ) ? (int) $user->ID : (int) $user;
	return in_array( $id, $GLOBALS['fh_user_caps'][ $capability ] ?? array(), true );
}

function get_current_user_id() {
	return (int) ( $GLOBALS['fh_current_user'] ?? 0 );
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

function apply_filters( $hook, $value, ...$args ) {
	foreach ( $GLOBALS['fh_filters'][ $hook ] ?? array() as $entry ) {
		$value = call_user_func_array( $entry[0], array_merge( array( $value ), array_slice( $args, 0, max( 0, $entry[1] - 1 ) ) ) );
	}
	return $value;
}

require_once FISHOTEL_MISC_PATH . 'includes/sections/clean-room/class-clean-room.php';
require_once FISHOTEL_MISC_PATH . 'includes/sections/clean-room/class-layout.php';
require_once FISHOTEL_MISC_PATH . 'includes/sections/clean-room/class-replay.php';
require_once FISHOTEL_MISC_PATH . 'includes/sections/clean-room/class-protocol.php';
require_once FISHOTEL_MISC_PATH . 'includes/sections/clean-room/class-parser.php';
require_once FISHOTEL_MISC_PATH . 'includes/sections/clean-room/class-qr.php';
require_once FISHOTEL_MISC_PATH . 'includes/sections/clean-room/class-costing.php';
require_once FISHOTEL_MISC_PATH . 'includes/sections/clean-room/class-stats.php';
require_once FISHOTEL_MISC_PATH . 'includes/sections/clean-room/class-species.php';
require_once FISHOTEL_MISC_PATH . 'includes/sections/clean-room/class-orders.php';
require_once FISHOTEL_MISC_PATH . 'includes/sections/clean-room/class-xlsx.php';
require_once FISHOTEL_MISC_PATH . 'includes/sections/clean-room/class-workbook.php';
require_once FISHOTEL_MISC_PATH . 'includes/sections/clean-room/class-import-map.php';
require_once FISHOTEL_MISC_PATH . 'includes/sections/clean-room/class-import.php';
require_once FISHOTEL_MISC_PATH . 'includes/sections/clean-room/class-store.php';
require_once FISHOTEL_MISC_PATH . 'includes/sections/clean-room/class-settings.php';
require_once FISHOTEL_MISC_PATH . 'includes/sections/clean-room/class-front-desk.php';
require_once FISHOTEL_MISC_PATH . 'includes/sections/clean-room/class-admin.php';
require_once FISHOTEL_MISC_PATH . 'includes/sections/clean-room/class-admin-workbook.php';
require_once FISHOTEL_MISC_PATH . 'includes/sections/clean-room/class-admin-import.php';
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

/**
 * Strict equality check with an automatic detail string (the workbook
 * reader and mapper tests lean on it).
 *
 * @param string $label    Label.
 * @param mixed  $expected Expected.
 * @param mixed  $actual   Actual.
 */
function check_same( $label, $expected, $actual ) {
	check( $label, $expected === $actual, 'expected ' . fh_test_export( $expected ) . ', got ' . fh_test_export( $actual ) );
}

/**
 * Substring check (case-insensitive) over a string or a list of strings.
 *
 * @param string          $label    Label.
 * @param string          $needle   Needle.
 * @param string|string[] $haystack One string or a list.
 */
function check_contains( $label, $needle, $haystack ) {
	$list = is_array( $haystack ) ? $haystack : array( (string) $haystack );
	foreach ( $list as $item ) {
		if ( false !== stripos( (string) $item, $needle ) ) {
			check( $label, true );
			return;
		}
	}
	check( $label, false, '"' . $needle . '" not found in ' . fh_test_export( $list ) );
}

/**
 * Short var_export for failure details.
 *
 * @param mixed $value Value.
 * @return string
 */
function fh_test_export( $value ) {
	$text = var_export( $value, true );
	$text = preg_replace( '/\s+/', ' ', $text );
	return strlen( $text ) > 300 ? substr( $text, 0, 297 ) . '...' : $text;
}

/**
 * A fresh, empty temporary directory for test files (FH_TEST_TMP when
 * set, else the system temp directory).
 *
 * @return string
 */
function fh_test_tmp_dir() {
	$base = getenv( 'FH_TEST_TMP' );
	if ( false === $base || '' === $base ) {
		$base = sys_get_temp_dir();
	}
	$dir = rtrim( $base, '/\\' ) . '/fh-cleanroom-test-' . getmypid() . '-' . substr( md5( uniqid( '', true ) ), 0, 8 );
	if ( ! is_dir( $dir ) && ! mkdir( $dir, 0700, true ) ) {
		fwrite( STDERR, "Cannot create temp dir {$dir}\n" );
		exit( 1 );
	}
	return $dir;
}

/**
 * Remove a test directory and its files.
 *
 * @param string $dir Directory.
 */
function fh_test_rmdir( $dir ) {
	if ( ! is_dir( $dir ) ) {
		return;
	}
	foreach ( scandir( $dir ) as $entry ) {
		if ( '.' === $entry || '..' === $entry ) {
			continue;
		}
		$path = $dir . '/' . $entry;
		if ( is_dir( $path ) ) {
			fh_test_rmdir( $path );
		} else {
			@unlink( $path );
		}
	}
	@rmdir( $dir );
}

/**
 * The seed layout as the flat tank list the Parser receives.
 */
function fh_seed_tanks() {
	$out = array();
	$id  = 0;
	foreach ( \FisHotel\Misc\Sections\Clean_Room\Layout::seed() as $index => $building ) {
		foreach ( $building['tanks'] as $tank ) {
			$id++;
			$out[] = array(
				'id'          => $id,
				'building_id' => $index + 1,
				'number'      => $tank['number'],
				'building'    => $building['number'],
				'area'        => $building['area'],
				'level'       => $tank['level'],
				'position'    => $tank['position'],
				'status'      => $tank['status'],
				'sort'        => $tank['sort'],
			);
		}
	}
	return $out;
}
