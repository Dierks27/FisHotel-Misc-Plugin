<?php
/**
 * Just enough WordPress to run the Edit screens section headless: the
 * translation and escaping helpers, capabilities, nonces, the current
 * user, user meta (with every write counted), post meta, terms, a hook
 * registry, meta boxes ($wp_meta_boxes) and a fake WooCommerce product.
 * No database, no network, no real data: every name here is invented.
 *
 * @package FisHotel\Misc\Sections\Edit_Screens
 */

if ( realpath( (string) ( $_SERVER['SCRIPT_FILENAME'] ?? '' ) ) === realpath( __FILE__ ) ) {
	exit( 0 ); // A helper, not a test.
}

error_reporting( E_ALL );
set_error_handler(
	static function ( $severity, $message, $file, $line ) {
		throw new \ErrorException( $message, 0, $severity, $file, $line );
	}
);

define( 'ABSPATH', '/var/www/html/' );
define( 'FISHOTEL_MISC_PATH', dirname( __DIR__, 4 ) . '/' );
define( 'FISHOTEL_MISC_URL', 'https://example.test/wp-content/plugins/fishotel-misc-plugin/' );
define( 'FISHOTEL_MISC_VERSION', 'test' );

function __( $text, $domain = null ) {
	return $text;
}
function _n( $single, $plural, $number, $domain = null ) {
	return 1 === (int) $number ? $single : $plural;
}
function esc_html__( $text, $domain = null ) {
	return htmlspecialchars( $text, ENT_QUOTES );
}
function esc_attr__( $text, $domain = null ) {
	return htmlspecialchars( $text, ENT_QUOTES );
}
function esc_html( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES );
}
function esc_attr( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES );
}
function esc_url( $url ) {
	return htmlspecialchars( (string) $url, ENT_QUOTES );
}
function sanitize_key( $key ) {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
}
function wp_unslash( $value ) {
	return $value;
}
function admin_url( $path = '' ) {
	return 'https://example.test/wp-admin/' . ltrim( $path, '/' );
}
function add_query_arg( $args, $url = '' ) {
	$sep = false === strpos( $url, '?' ) ? '?' : '&';
	return $url . $sep . http_build_query( $args );
}
function remove_query_arg( $key, $url = '' ) {
	return (string) preg_replace( '/[?&]' . preg_quote( $key, '/' ) . '=[^&]*/', '', (string) $url );
}

$GLOBALS['fh_is_admin'] = true;
function is_admin() {
	return ! empty( $GLOBALS['fh_is_admin'] );
}

/* Users, capabilities, nonces. */
$GLOBALS['fh_user'] = 0;
$GLOBALS['fh_caps'] = array();
function get_current_user_id() {
	return (int) $GLOBALS['fh_user'];
}
function fh_login( $id, array $caps = array() ) {
	$GLOBALS['fh_user'] = (int) $id;
	$GLOBALS['fh_caps'] = $caps;
}
function current_user_can( $cap, ...$args ) {
	return $GLOBALS['fh_user'] > 0 && in_array( $cap, $GLOBALS['fh_caps'], true );
}
function wp_create_nonce( $action = -1 ) {
	return 'n-' . substr( md5( $GLOBALS['fh_user'] . '|' . $action ), 0, 10 );
}
function wp_verify_nonce( $nonce, $action = -1 ) {
	return is_string( $nonce ) && hash_equals( wp_create_nonce( $action ), $nonce ) ? 1 : false;
}
function wp_nonce_field( $action = -1, $name = '_wpnonce', $referer = true, $display = true ) {
	$field = '<input type="hidden" name="' . esc_attr( $name ) . '" value="' . esc_attr( wp_create_nonce( $action ) ) . '">';
	if ( $display ) {
		echo $field; // phpcs:ignore
	}
	return $field;
}

/* User meta: user id → key → value; every write counted. */
$GLOBALS['fh_user_meta']   = array();
$GLOBALS['fh_meta_writes'] = 0;
function metadata_exists( $type, $id, $key ) {
	return 'user' === $type && isset( $GLOBALS['fh_user_meta'][ (int) $id ] ) && array_key_exists( $key, $GLOBALS['fh_user_meta'][ (int) $id ] );
}
function get_user_meta( $id, $key = '', $single = false ) {
	$all = $GLOBALS['fh_user_meta'][ (int) $id ] ?? array();
	return array_key_exists( $key, $all ) ? $all[ $key ] : '';
}
function update_user_meta( $id, $key, $value ) {
	$GLOBALS['fh_user_meta'][ (int) $id ][ $key ] = $value;
	$GLOBALS['fh_meta_writes']++;
	return true;
}
function delete_user_meta( $id, $key ) {
	unset( $GLOBALS['fh_user_meta'][ (int) $id ][ $key ] );
	$GLOBALS['fh_meta_writes']++;
	return true;
}

/* $wpdb: only the blog prefix. */
class Fh_Test_Wpdb {
	public $prefix = 'wp_';
	public function get_blog_prefix() {
		return $this->prefix;
	}
}
$GLOBALS['wpdb'] = new Fh_Test_Wpdb();

/* Post meta and terms. */
$GLOBALS['fh_post_meta'] = array();
$GLOBALS['fh_terms']     = array();
$GLOBALS['fh_term_ids']  = array();
function get_post_meta( $id, $key = '', $single = false ) {
	return $GLOBALS['fh_post_meta'][ (int) $id ][ $key ] ?? '';
}
function get_the_terms( $id, $tax ) {
	$out = array();
	foreach ( $GLOBALS['fh_terms'][ (int) $id ] ?? array() as $slug ) {
		$out[] = (object) array(
			'slug'    => $slug,
			'name'    => ucwords( str_replace( '-', ' ', $slug ) ),
			'term_id' => $GLOBALS['fh_term_ids'][ $slug ] ?? 0,
		);
	}
	return $out ? $out : false;
}
function get_term_by( $field, $value, $tax = '' ) {
	return isset( $GLOBALS['fh_term_ids'][ $value ] ) ? (object) array(
		'slug'    => $value,
		'term_id' => $GLOBALS['fh_term_ids'][ $value ],
	) : false;
}

/* Hooks. */
$GLOBALS['fh_hooks'] = array();
function add_action( $hook, $callback, $priority = 10, $args = 1 ) {
	$GLOBALS['fh_hooks'][ $hook ][] = array(
		'callback' => $callback,
		'priority' => (int) $priority,
	);
	return true;
}
function add_filter( $hook, $callback, $priority = 10, $args = 1 ) {
	return add_action( $hook, $callback, $priority, $args );
}
function apply_filters( $hook, $value, ...$args ) {
	foreach ( $GLOBALS['fh_hooks'][ $hook ] ?? array() as $entry ) {
		$value = call_user_func_array( $entry['callback'], array_merge( array( $value ), $args ) );
	}
	return $value;
}
function fh_reset_hooks() {
	$GLOBALS['fh_hooks'] = array();
}

/* Screens, meta boxes, assets. */
$GLOBALS['fh_screen']   = null;
$GLOBALS['wp_meta_boxes'] = array();
$GLOBALS['fh_enqueued'] = array();
function get_current_screen() {
	return $GLOBALS['fh_screen'];
}
function add_meta_box( $id, $title, $callback, $screen = null, $context = 'advanced', $priority = 'default' ) {
	$GLOBALS['wp_meta_boxes'][ $screen ][ $context ][ $priority ][ $id ] = array(
		'id'       => $id,
		'title'    => $title,
		'callback' => $callback,
	);
}
function wp_script_is( $handle, $status = 'enqueued' ) {
	return in_array( 'script:' . $handle, $GLOBALS['fh_enqueued'], true );
}
function wp_enqueue_script( $handle, $src = '', $deps = array(), $ver = false, $footer = false ) {
	$GLOBALS['fh_enqueued'][] = 'script:' . $handle;
}
function wp_enqueue_style( $handle, $src = '', $deps = array(), $ver = false ) {
	$GLOBALS['fh_enqueued'][] = 'style:' . $handle;
}
function wp_localize_script( $handle, $name, $data ) {
	$GLOBALS['fh_enqueued'][] = 'data:' . $name;
	$GLOBALS['fh_localized'][ $name ] = $data;
}

/* A WooCommerce product, as much as this section touches. */
class Fh_Test_Product {
	public $id;
	public $short       = '';
	public $meta        = array();
	public $saves       = 0;
	public function __construct( $id, $short = '', array $meta = array() ) {
		$this->id    = (int) $id;
		$this->short = (string) $short;
		$this->meta  = $meta;
	}
	public function get_id() {
		return $this->id;
	}
	public function set_short_description( $value ) {
		$this->short = (string) $value;
	}
	public function update_meta_data( $key, $value ) {
		$this->meta[ $key ] = $value;
	}
	public function delete_meta_data( $key ) {
		unset( $this->meta[ $key ] );
	}
	public function get_meta( $key, $single = true ) {
		return $this->meta[ $key ] ?? '';
	}
	public function save() {
		$this->saves++;
		return $this->id;
	}
}
$GLOBALS['fh_products'] = array();
function wc_get_product( $id ) {
	return $GLOBALS['fh_products'][ (int) $id ] ?? false;
}
$GLOBALS['fh_orders'] = array();
function wc_get_order( $id ) {
	return $GLOBALS['fh_orders'][ (int) $id ] ?? false;
}

/* Checks. */
$GLOBALS['fh_checks']   = 0;
$GLOBALS['fh_failures'] = 0;
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
	check( $label, $expected === $actual, 'expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) );
}
function fh_finish() {
	echo "\n" . ( $GLOBALS['fh_failures'] ? $GLOBALS['fh_failures'] . ' of ' . $GLOBALS['fh_checks'] . " checks FAILED\n" : 'All ' . $GLOBALS['fh_checks'] . " checks passed\n" );
	exit( $GLOBALS['fh_failures'] ? 1 : 0 );
}

require_once dirname( __DIR__ ) . '/class-edit-screens.php';
\FisHotel\Misc\Sections\Edit_Screens\Edit_Screens::load();
