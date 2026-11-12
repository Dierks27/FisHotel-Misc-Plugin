<?php
/**
 * Just enough WordPress to load the Foam Cuts section headless: in-memory
 * options (the autoload flag and every read recorded), actions and
 * filters that really run, users with capabilities, nonces, transients,
 * redirects / wp_die() / JSON replies that stop a handler by throwing,
 * the REST classes a route needs, a WooCommerce order double with
 * wc_get_orders(), and a $wpdb that only answers what Store::install()
 * asks and counts everything else. The Store itself is replaced by
 * fake-store.php where a store is needed.
 *
 * Exits 0 when run directly (CI runs every tests/*.php).
 *
 * @package FisHotel\Misc\Sections\Foam_Cuts
 */

if ( ! empty( $_SERVER['SCRIPT_FILENAME'] ) && realpath( $_SERVER['SCRIPT_FILENAME'] ) === __FILE__ ) {
	exit( 0 );
}

// ABSPATH points at a temp "WordPress" whose wp-admin/includes/upgrade.php
// is empty: Store::install() requires that file before it calls dbDelta()
// (which is a stub below).
if ( ! defined( 'ABSPATH' ) ) {
	$fh_root = rtrim( sys_get_temp_dir(), '/\\' ) . '/fh-foam-wp-' . getmypid() . '/';
	if ( ! is_dir( $fh_root . 'wp-admin/includes' ) ) {
		mkdir( $fh_root . 'wp-admin/includes', 0700, true );
	}
	file_put_contents( $fh_root . 'wp-admin/includes/upgrade.php', "<?php\n" );
	define( 'ABSPATH', $fh_root );
	register_shutdown_function(
		static function () use ( $fh_root ) {
			@unlink( $fh_root . 'wp-admin/includes/upgrade.php' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- test cleanup.
			@rmdir( $fh_root . 'wp-admin/includes' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			@rmdir( $fh_root . 'wp-admin' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			@rmdir( $fh_root ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
	);
	unset( $fh_root );
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

function esc_html( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}

function esc_html__( $text, $domain = null ) {
	return esc_html( $text );
}

function esc_html_e( $text, $domain = null ) {
	echo esc_html( $text );
}

function esc_attr( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}

function esc_attr__( $text, $domain = null ) {
	return esc_attr( $text );
}

function esc_attr_e( $text, $domain = null ) {
	echo esc_attr( $text );
}

function esc_url( $url ) {
	return str_replace( array( '"', "'", '<', '>' ), array( '%22', '%27', '%3C', '%3E' ), (string) $url );
}

function esc_url_raw( $url ) {
	return (string) $url;
}

function esc_textarea( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}

function sanitize_key( $key ) {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
}

function sanitize_text_field( $text ) {
	return trim( preg_replace( '/[\r\n\t ]+/', ' ', strip_tags( (string) $text ) ) );
}

function sanitize_title( $text ) {
	return trim( preg_replace( '/[^a-z0-9]+/', '-', strtolower( (string) $text ) ), '-' );
}

function wp_unslash( $value ) {
	return is_array( $value ) ? array_map( 'wp_unslash', $value ) : ( is_string( $value ) ? stripslashes( $value ) : $value );
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

function checked( $value, $current = true, $echo = true ) {
	$out = (string) $value === (string) $current ? " checked='checked'" : '';
	if ( $echo ) {
		echo $out; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- test stub.
	}
	return $out;
}

function selected( $value, $current = true, $echo = true ) {
	$out = (string) $value === (string) $current ? " selected='selected'" : '';
	if ( $echo ) {
		echo $out; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- test stub.
	}
	return $out;
}

function number_format_i18n( $number, $decimals = 0 ) {
	return number_format( (float) $number, (int) $decimals );
}

function get_bloginfo( $show = '' ) {
	return 'language' === $show ? 'en-US' : 'Example Fish Shop';
}

function date_i18n( $format, $timestamp = false ) {
	return gmdate( $format, false === $timestamp ? time() : (int) $timestamp );
}

/* ------------------------------------------------------------------ */
/*  URLs                                                               */
/* ------------------------------------------------------------------ */

function admin_url( $path = '' ) {
	return 'https://example.test/wp-admin/' . ltrim( (string) $path, '/' );
}

function home_url( $path = '' ) {
	return 'https://example.test' . ( '' !== (string) $path ? '/' . ltrim( (string) $path, '/' ) : '' );
}

function rest_url( $path = '' ) {
	return 'https://example.test/wp-json/' . ltrim( (string) $path, '/' );
}

function add_query_arg( $args, $url = '' ) {
	if ( ! is_array( $args ) ) { // add_query_arg( key, value, url ).
		$args = array( (string) $args => $url );
		$url  = func_num_args() > 2 ? func_get_arg( 2 ) : '';
	}
	return $url . ( false === strpos( (string) $url, '?' ) ? '?' : '&' ) . http_build_query( (array) $args );
}

/* ------------------------------------------------------------------ */
/*  Options                                                            */
/* ------------------------------------------------------------------ */

$GLOBALS['fh_options']      = array();
$GLOBALS['fh_autoload']     = array();
$GLOBALS['fh_option_reads'] = array(); // Every get_option() name, in order.

function get_option( $name, $default = false ) {
	$GLOBALS['fh_option_reads'][] = $name;
	return array_key_exists( $name, $GLOBALS['fh_options'] ) ? $GLOBALS['fh_options'][ $name ] : $default;
}

function update_option( $name, $value, $autoload = null ) {
	$GLOBALS['fh_options'][ $name ] = $value;
	if ( null !== $autoload || ! array_key_exists( $name, $GLOBALS['fh_autoload'] ) ) {
		$GLOBALS['fh_autoload'][ $name ] = $autoload;
	}
	return true;
}

function add_option( $name, $value = '', $deprecated = '', $autoload = null ) {
	if ( array_key_exists( $name, $GLOBALS['fh_options'] ) ) {
		return false;
	}
	$GLOBALS['fh_options'][ $name ]  = $value;
	$GLOBALS['fh_autoload'][ $name ] = $autoload;
	return true;
}

function delete_option( $name ) {
	unset( $GLOBALS['fh_options'][ $name ], $GLOBALS['fh_autoload'][ $name ] );
	return true;
}

/* ------------------------------------------------------------------ */
/*  Transients                                                         */
/* ------------------------------------------------------------------ */

$GLOBALS['fh_transients'] = array();

function set_transient( $key, $value, $expiration = 0 ) {
	$GLOBALS['fh_transients'][ $key ] = $value;
	return true;
}

function get_transient( $key ) {
	return array_key_exists( $key, $GLOBALS['fh_transients'] ) ? $GLOBALS['fh_transients'][ $key ] : false;
}

function delete_transient( $key ) {
	unset( $GLOBALS['fh_transients'][ $key ] );
	return true;
}

/* ------------------------------------------------------------------ */
/*  Hooks                                                              */
/* ------------------------------------------------------------------ */

$GLOBALS['fh_hooks'] = array();

function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
	$GLOBALS['fh_hooks'][ $hook ][ (int) $priority ][] = array( $callback, (int) $accepted_args );
	return true;
}

function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
	return add_action( $hook, $callback, $priority, $accepted_args );
}

function has_action( $hook, $callback = false ) {
	return ! empty( $GLOBALS['fh_hooks'][ $hook ] );
}

function has_filter( $hook, $callback = false ) {
	return has_action( $hook, $callback );
}

function do_action( $hook, ...$args ) {
	if ( empty( $GLOBALS['fh_hooks'][ $hook ] ) ) {
		return;
	}
	ksort( $GLOBALS['fh_hooks'][ $hook ] );
	foreach ( $GLOBALS['fh_hooks'][ $hook ] as $list ) {
		foreach ( $list as $entry ) {
			call_user_func_array( $entry[0], array_slice( $args, 0, $entry[1] ) );
		}
	}
}

function apply_filters( $hook, $value, ...$args ) {
	if ( empty( $GLOBALS['fh_hooks'][ $hook ] ) ) {
		return $value;
	}
	ksort( $GLOBALS['fh_hooks'][ $hook ] );
	foreach ( $GLOBALS['fh_hooks'][ $hook ] as $list ) {
		foreach ( $list as $entry ) {
			$value = call_user_func_array( $entry[0], array_slice( array_merge( array( $value ), $args ), 0, max( 1, $entry[1] ) ) );
		}
	}
	return $value;
}

/**
 * Forget every hook (between scenarios).
 */
function fh_reset_hooks() {
	$GLOBALS['fh_hooks'] = array();
}

/* ------------------------------------------------------------------ */
/*  Request, users, nonces                                             */
/* ------------------------------------------------------------------ */

$GLOBALS['fh_is_admin']     = false;
$GLOBALS['fh_current_user'] = 0;
$GLOBALS['fh_user_caps']    = array(); // cap => user ids.
$GLOBALS['fh_now']          = '2026-03-10 09:00:00'; // Site time.

function is_admin() {
	return (bool) $GLOBALS['fh_is_admin'];
}

/**
 * Log in as a user for the next calls.
 *
 * @param int      $id   User id (0 = logged out).
 * @param string[] $caps The capabilities that user has.
 */
function fh_login( $id, array $caps = array() ) {
	$GLOBALS['fh_current_user'] = (int) $id;
	foreach ( $GLOBALS['fh_user_caps'] as $cap => $ids ) {
		$GLOBALS['fh_user_caps'][ $cap ] = array_values( array_diff( $ids, array( (int) $id ) ) );
	}
	foreach ( $caps as $cap ) {
		$GLOBALS['fh_user_caps'][ $cap ][] = (int) $id;
	}
}

function get_current_user_id() {
	return (int) $GLOBALS['fh_current_user'];
}

function is_user_logged_in() {
	return get_current_user_id() > 0;
}

function user_can( $user, $capability ) {
	$id = is_object( $user ) && isset( $user->ID ) ? (int) $user->ID : (int) $user;
	return $id > 0 && in_array( $id, isset( $GLOBALS['fh_user_caps'][ $capability ] ) ? $GLOBALS['fh_user_caps'][ $capability ] : array(), true );
}

function current_user_can( $capability, ...$args ) {
	return user_can( get_current_user_id(), $capability );
}

function wp_get_current_user() {
	$id = get_current_user_id();
	return (object) array(
		'ID'           => $id,
		'user_login'   => $id > 0 ? 'user' . $id : '',
		'display_name' => $id > 0 ? 'User ' . $id : '',
	);
}

function get_userdata( $id ) {
	return (int) $id > 0 ? (object) array(
		'ID'           => (int) $id,
		'user_login'   => 'user' . (int) $id,
		'display_name' => 'User ' . (int) $id,
	) : false;
}

/**
 * A nonce is bound to the action AND the user, as in WordPress.
 */
function wp_create_nonce( $action = -1 ) {
	return 'n' . substr( md5( $action . '|' . get_current_user_id() ), 0, 9 );
}

function wp_verify_nonce( $nonce, $action = -1 ) {
	return is_string( $nonce ) && '' !== $nonce && hash_equals( wp_create_nonce( $action ), $nonce ) ? 1 : false;
}

function wp_nonce_url( $url, $action = -1, $name = '_wpnonce' ) {
	return add_query_arg( array( $name => wp_create_nonce( $action ) ), (string) $url );
}

function wp_nonce_field( $action = -1, $name = '_wpnonce', $referer = true, $echo = true ) {
	$field = '<input type="hidden" name="' . esc_attr( $name ) . '" value="' . esc_attr( wp_create_nonce( $action ) ) . '" />';
	if ( $echo ) {
		echo $field; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- test stub.
	}
	return $field;
}

function wp_nonce_tick( $action = -1 ) {
	return isset( $GLOBALS['fh_nonce_tick'] ) ? (int) $GLOBALS['fh_nonce_tick'] : 1000;
}

function wp_hash( $data, $scheme = 'auth' ) {
	return hash_hmac( 'md5', (string) $data, 'test-salt-' . $scheme );
}

/**
 * Where WordPress would stop the request: a redirect, wp_die(), a JSON
 * reply, the end of a printed page.
 */
class FH_Halt extends Exception {
	public $kind;
	public $data;
	public $status;

	public function __construct( $kind, $data = null, $status = 200 ) {
		parent::__construct( $kind . ( is_string( $data ) ? ': ' . $data : '' ) );
		$this->kind   = $kind;
		$this->data   = $data;
		$this->status = (int) $status;
	}
}

function wp_die( $message = '', $title = '', $args = array() ) {
	$status = is_array( $args ) && isset( $args['response'] ) ? (int) $args['response'] : ( is_int( $args ) ? $args : 500 );
	throw new FH_Halt( 'die', (string) $message, $status );
}

function wp_safe_redirect( $location, $status = 302 ) {
	throw new FH_Halt( 'redirect', (string) $location, $status );
}

function wp_send_json_success( $data = null, $status = null ) {
	throw new FH_Halt( 'json', array( 'success' => true, 'data' => $data ), null === $status ? 200 : $status );
}

function wp_send_json_error( $data = null, $status = null ) {
	throw new FH_Halt( 'json', array( 'success' => false, 'data' => $data ), null === $status ? 200 : $status );
}

/**
 * As WordPress: the nonce is read from $_REQUEST[ $query_arg ]; a wrong
 * one ends the request with 403.
 */
function check_admin_referer( $action = -1, $query_arg = '_wpnonce' ) {
	$nonce = isset( $_REQUEST[ $query_arg ] ) && is_scalar( $_REQUEST[ $query_arg ] ) ? (string) $_REQUEST[ $query_arg ] : '';
	if ( ! wp_verify_nonce( $nonce, $action ) ) {
		throw new FH_Halt( 'die', 'The link you followed has expired.', 403 );
	}
	return 1;
}

function nocache_headers() {
}

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
	return new DateTimeZone( 'UTC' );
}

function get_date_from_gmt( $date, $format = 'Y-m-d H:i:s' ) {
	return gmdate( $format, (int) strtotime( $date . ' UTC' ) );
}

/* ------------------------------------------------------------------ */
/*  Admin pages, styles                                                */
/* ------------------------------------------------------------------ */

$GLOBALS['fh_submenus'] = array();
$GLOBALS['fh_styles']   = array();

function add_submenu_page( $parent, $page_title, $menu_title, $capability, $slug, $callback = '' ) {
	$GLOBALS['fh_submenus'][ $slug ] = array(
		'parent'     => $parent,
		'title'      => $menu_title,
		'capability' => $capability,
		'callback'   => $callback,
	);
	return 'fishotel-tools_page_' . $slug;
}

function wp_enqueue_style( $handle, $src = '', $deps = array(), $ver = false ) {
	$GLOBALS['fh_styles'][ $handle ] = $src;
}

/* ------------------------------------------------------------------ */
/*  REST                                                               */
/* ------------------------------------------------------------------ */

$GLOBALS['fh_rest_routes'] = array();

function register_rest_route( $namespace, $route, $args = array() ) {
	$GLOBALS['fh_rest_routes'][ $namespace . $route ] = $args;
	return true;
}

class WP_Error {
	public $code;
	public $message;
	public $data;

	public function __construct( $code = '', $message = '', $data = '' ) {
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

class WP_REST_Request implements ArrayAccess {
	private $params;
	private $method;

	public function __construct( $method = 'GET', array $params = array() ) {
		$this->method = $method;
		$this->params = $params;
	}

	public function get_method() {
		return $this->method;
	}

	public function get_param( $key ) {
		return isset( $this->params[ $key ] ) ? $this->params[ $key ] : null;
	}

	public function get_params() {
		return $this->params;
	}

	#[\ReturnTypeWillChange]
	public function offsetExists( $offset ) {
		return isset( $this->params[ $offset ] );
	}

	#[\ReturnTypeWillChange]
	public function offsetGet( $offset ) {
		return $this->get_param( $offset );
	}

	#[\ReturnTypeWillChange]
	public function offsetSet( $offset, $value ) {
		$this->params[ $offset ] = $value;
	}

	#[\ReturnTypeWillChange]
	public function offsetUnset( $offset ) {
		unset( $this->params[ $offset ] );
	}
}

class WP_REST_Response {
	public $data;
	public $status;

	public function __construct( $data = null, $status = 200 ) {
		$this->data   = $data;
		$this->status = (int) $status;
	}

	public function get_data() {
		return $this->data;
	}

	public function get_status() {
		return $this->status;
	}
}

function rest_ensure_response( $response ) {
	return $response instanceof WP_REST_Response || $response instanceof WP_Error ? $response : new WP_REST_Response( $response );
}

/* ------------------------------------------------------------------ */
/*  WooCommerce orders                                                 */
/* ------------------------------------------------------------------ */

/**
 * An order double: an id, a status and meta read through get_meta() —
 * the only way the section reads an order.
 */
class WC_Order {
	private $id;
	private $status;
	private $meta;
	public $meta_reads = 0;

	public function __construct( $id, $status = 'processing', array $meta = array() ) {
		$this->id     = (int) $id;
		$this->status = (string) $status;
		$this->meta   = $meta;
	}

	public function get_id() {
		return $this->id;
	}

	public function get_order_number() {
		return (string) $this->id;
	}

	public function get_status() {
		return $this->status;
	}

	public function has_status( $status ) {
		return in_array( $this->status, (array) $status, true );
	}

	public function get_meta( $key = '', $single = true, $context = 'view' ) {
		$this->meta_reads++;
		return isset( $this->meta[ $key ] ) ? $this->meta[ $key ] : '';
	}
}

$GLOBALS['fh_orders']        = array(); // id => WC_Order.
$GLOBALS['fh_order_queries'] = array(); // Every wc_get_orders() argument list.

/**
 * wc_get_orders(): filters the doubles by status only — like the CPT
 * store, it ignores a meta_query, so the caller must re-check the meta.
 */
function wc_get_orders( $args = array() ) {
	$GLOBALS['fh_order_queries'][] = $args;
	$statuses                      = isset( $args['status'] ) ? (array) $args['status'] : array();
	$out                           = array();
	foreach ( $GLOBALS['fh_orders'] as $order ) {
		if ( ! $statuses || in_array( $order->get_status(), $statuses, true ) ) {
			$out[] = $order;
		}
	}
	return $out;
}

function wc_get_order( $id ) {
	if ( $id instanceof WC_Order ) {
		return $id;
	}
	return isset( $GLOBALS['fh_orders'][ (int) $id ] ) ? $GLOBALS['fh_orders'][ (int) $id ] : false;
}

/* ------------------------------------------------------------------ */
/*  $wpdb + dbDelta                                                    */
/* ------------------------------------------------------------------ */

/**
 * A wpdb that knows which tables "exist" and counts every call; the
 * section's reads and writes go through fake-store.php instead.
 */
class FH_Fake_WPDB {
	public $prefix     = 'wp_';
	public $last_error = '';
	public $insert_id  = 0;
	public $tables     = array(); // Tables that exist (SHOW TABLES).
	public $queries    = array(); // Every SQL string seen.
	public $create_ok  = true;    // Whether dbDelta() creates what it is asked to.

	public function prepare( $sql, ...$args ) {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) {
			$args = $args[0];
		}
		foreach ( $args as $arg ) {
			$sql = preg_replace_callback(
				'/%[dsf]/',
				static function ( $m ) use ( $arg ) {
					return '%d' === $m[0] ? (string) (int) $arg : "'" . addslashes( (string) $arg ) . "'";
				},
				$sql,
				1
			);
		}
		return $sql;
	}

	public function get_charset_collate() {
		return 'DEFAULT CHARACTER SET utf8mb4';
	}

	public function get_var( $sql ) {
		$this->queries[] = $sql;
		if ( preg_match( "/^SHOW TABLES LIKE '(.+)'$/", $sql, $m ) ) {
			return in_array( stripslashes( $m[1] ), $this->tables, true ) ? stripslashes( $m[1] ) : null;
		}
		return null;
	}

	public function __call( $name, $args ) {
		$this->queries[] = $name . ': ' . ( isset( $args[0] ) && is_string( $args[0] ) ? $args[0] : '' );
		return null;
	}
}

$GLOBALS['wpdb']       = new FH_Fake_WPDB();
$GLOBALS['fh_dbdelta'] = array(); // Every statement dbDelta() was given.

/**
 * dbDelta(): records the statement and, when the fake database is
 * willing, makes the table exist.
 */
function dbDelta( $sql ) {
	$GLOBALS['fh_dbdelta'][] = $sql;
	if ( $GLOBALS['wpdb']->create_ok && preg_match( '/CREATE TABLE (\S+)/', (string) $sql, $m ) ) {
		$GLOBALS['wpdb']->tables[] = $m[1];
	}
	return array();
}

/* ------------------------------------------------------------------ */
/*  The section                                                        */
/* ------------------------------------------------------------------ */

foreach ( array( 'foam-cuts', 'units', 'cut-list', 'packer', 'plan', 'svg', 'store', 'settings', 'boxes', 'stock', 'orders', 'planner', 'rest', 'admin' ) as $fh_class ) {
	$fh_file = FISHOTEL_MISC_PATH . 'includes/sections/foam-cuts/class-' . $fh_class . '.php';
	if ( is_file( $fh_file ) ) {
		require_once $fh_file;
	}
}
unset( $fh_class, $fh_file );

if ( is_file( __DIR__ . '/fake-store.php' ) ) {
	require_once __DIR__ . '/fake-store.php';
}

/* ------------------------------------------------------------------ */
/*  check() / fh_finish() and the geometry helpers                     */
/* ------------------------------------------------------------------ */

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

function check_same( $label, $expected, $actual ) {
	check( $label, $expected === $actual, 'expected ' . fh_export( $expected ) . ', got ' . fh_export( $actual ) );
}

function fh_export( $value ) {
	$text = preg_replace( '/\s+/', ' ', var_export( $value, true ) );
	return strlen( $text ) > 400 ? substr( $text, 0, 397 ) . '...' : $text;
}

function fh_finish() {
	$failures = $GLOBALS['fh_failures'];
	$checks   = $GLOBALS['fh_checks'];

	echo "\n" . ( $failures ? "$failures of $checks checks FAILED\n" : "All $checks checks passed\n" );
	exit( $failures ? 1 : 0 );
}

/**
 * Inches → units.
 *
 * @param int|float $inches Inches.
 * @return int
 */
function fh_in( $inches ) {
	return (int) round( $inches * \FisHotel\Misc\Sections\Foam_Cuts\Units::PER_INCH );
}

/**
 * Whether two rectangles ( x, y, w, h ) overlap (touching edges do not).
 */
function fh_overlap( array $a, array $b ) {
	return $a[0] < $b[0] + $b[2] && $b[0] < $a[0] + $a[2] && $a[1] < $b[1] + $b[3] && $b[1] < $a[1] + $a[3];
}

/**
 * Whether a set of rectangles can be separated one from another by
 * straight edge-to-edge cuts alone — worked out from the rectangles, not
 * from the cuts a plan lists: find a line that crosses no rectangle and
 * has rectangles on both sides, then ask the same of each side.
 *
 * @param array[] $rects [ x, y, w, h ].
 * @return bool
 */
function fh_guillotine( array $rects ) {
	if ( count( $rects ) <= 1 ) {
		return true;
	}

	foreach ( array( 0, 1 ) as $axis ) {
		$size  = $axis + 2;
		$edges = array();
		foreach ( $rects as $rect ) {
			$edges[ $rect[ $axis ] + $rect[ $size ] ] = true;
		}
		ksort( $edges );

		foreach ( array_keys( $edges ) as $line ) {
			$near = array();
			$far  = array();
			foreach ( $rects as $rect ) {
				if ( $rect[ $axis ] + $rect[ $size ] <= $line ) {
					$near[] = $rect;
				} elseif ( $rect[ $axis ] >= $line ) {
					$far[] = $rect;
				} else {
					continue 2;
				}
			}
			if ( $near && $far ) {
				return fh_guillotine( $near ) && fh_guillotine( $far );
			}
		}
	}

	return false;
}
