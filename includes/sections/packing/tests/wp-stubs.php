<?php
/**
 * Just enough WordPress to load the Packing section headless: the
 * translation and escaping helpers, options (with a count of the reads, so
 * a test can prove a front-end request reads none), capabilities and
 * nonces, the AJAX replies (wp_send_json_*() throw Fh_Test_Json), and a
 * hook registry that remembers every add_action() / add_filter() so the
 * storefront test can look at exactly what the section hooks.
 *
 * WooCommerce is replaced by fakes.php: an order, an order line, a product
 * and the registries wc_get_order() / wc_get_product() answer from. No
 * database, no network, no real data — every name and number in these
 * tests is invented.
 *
 * @package FisHotel\Misc\Sections\Packing
 */

// A warning, a notice or a deprecation is a failure: it stops the test.
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

function esc_url( $url ) {
	return (string) $url;
}

function sanitize_key( $key ) {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
}

function absint( $value ) {
	return abs( (int) $value );
}

function wp_unslash( $value ) {
	return is_array( $value ) ? array_map( 'wp_unslash', $value ) : ( is_string( $value ) ? stripslashes( $value ) : $value );
}

function wp_json_encode( $data, $flags = 0, $depth = 512 ) {
	return json_encode( $data, $flags, $depth );
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

function selected( $selected, $current = true, $display = true ) {
	$out = (string) $selected === (string) $current ? ' selected="selected"' : '';
	if ( $display ) {
		echo $out;
	}
	return $out;
}

function wp_die( $message = '' ) {
	throw new \RuntimeException( 'wp_die: ' . (string) $message );
}

function get_date_from_gmt( $date, $format = 'Y-m-d H:i:s' ) {
	return gmdate( $format, (int) strtotime( $date . ' UTC' ) );
}

/**
 * Whether the request is in wp-admin: a test flips $GLOBALS['fh_is_admin'].
 */
$GLOBALS['fh_is_admin'] = false;

function is_admin() {
	return ! empty( $GLOBALS['fh_is_admin'] );
}

/**
 * Options live in $GLOBALS['fh_options']; every read and write is counted.
 */
$GLOBALS['fh_options']       = array();
$GLOBALS['fh_option_reads']  = 0;
$GLOBALS['fh_option_writes'] = array();

function get_option( $key, $default = false ) {
	$GLOBALS['fh_option_reads']++;
	return array_key_exists( $key, $GLOBALS['fh_options'] ) ? $GLOBALS['fh_options'][ $key ] : $default;
}

function update_option( $key, $value, $autoload = null ) {
	$GLOBALS['fh_options'][ $key ]  = $value;
	$GLOBALS['fh_option_writes'][] = array( $key, $autoload );
	return true;
}

function delete_option( $key ) {
	unset( $GLOBALS['fh_options'][ $key ] );
	return true;
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
	return in_array( get_current_user_id(), $GLOBALS['fh_user_caps'][ $capability ] ?? array(), true );
}

function get_userdata( $id ) {
	$id = (int) $id;
	return $id > 0 ? (object) array(
		'ID'           => $id,
		'display_name' => 'User ' . $id,
	) : false;
}

/**
 * Log a user in with a set of capabilities (0 = nobody).
 *
 * @param int      $id   User ID.
 * @param string[] $caps Capabilities.
 */
function fh_login( $id, array $caps = array() ) {
	$GLOBALS['fh_current_user'] = (int) $id;
	foreach ( $caps as $cap ) {
		$GLOBALS['fh_user_caps'][ $cap ][] = (int) $id;
	}
}

/**
 * A nonce is valid when it is exactly what wp_create_nonce() gives for the action.
 */
function wp_create_nonce( $action = -1 ) {
	return 'nonce-' . substr( md5( (string) $action ), 0, 10 );
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

/**
 * The AJAX replies end the request in WordPress; here they throw, so a
 * test can call a real handler and read what it would have answered.
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

/**
 * Hooks: everything registered is remembered, in order.
 */
$GLOBALS['fh_hooks']   = array(); // hook => list of array( callback, accepted args, 'action'|'filter' ).
$GLOBALS['fh_did']     = array(); // list of array( hook, args ) for do_action().

function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
	$GLOBALS['fh_hooks'][ $hook ][] = array( $callback, (int) $accepted_args, 'action' );
	return true;
}

function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
	$GLOBALS['fh_hooks'][ $hook ][] = array( $callback, (int) $accepted_args, 'filter' );
	return true;
}

function has_action( $hook, $callback = false ) {
	return ! empty( $GLOBALS['fh_hooks'][ $hook ] );
}

function has_filter( $hook, $callback = false ) {
	return ! empty( $GLOBALS['fh_hooks'][ $hook ] );
}

function do_action( $hook, ...$args ) {
	$GLOBALS['fh_did'][] = array( $hook, $args );
	foreach ( $GLOBALS['fh_hooks'][ $hook ] ?? array() as $entry ) {
		call_user_func_array( $entry[0], array_slice( $args, 0, $entry[1] ) );
	}
}

function apply_filters( $hook, $value, ...$args ) {
	foreach ( $GLOBALS['fh_hooks'][ $hook ] ?? array() as $entry ) {
		$value = call_user_func_array( $entry[0], array_merge( array( $value ), array_slice( $args, 0, max( 0, $entry[1] - 1 ) ) ) );
	}
	return $value;
}

/**
 * Forget every hook and every action that ran.
 */
function fh_reset_hooks() {
	$GLOBALS['fh_hooks'] = array();
	$GLOBALS['fh_did']   = array();
}

/**
 * The names of the actions that ran.
 *
 * @return string[]
 */
function fh_actions_done() {
	return array_map(
		static function ( $entry ) {
			return $entry[0];
		},
		$GLOBALS['fh_did']
	);
}

/**
 * Admin screens, menus, meta boxes and assets: recorded, nothing drawn.
 */
$GLOBALS['fh_menus']      = array();
$GLOBALS['fh_meta_boxes'] = array();
$GLOBALS['fh_enqueued']   = array();
$GLOBALS['fh_screen']     = null;

function add_submenu_page( $parent, $page_title, $menu_title, $capability, $slug, $callback = '' ) {
	$GLOBALS['fh_menus'][] = compact( 'parent', 'page_title', 'menu_title', 'capability', 'slug' );
	return 'fishotel-tools_page_' . $slug;
}

function add_meta_box( $id, $title, $callback, $screen = null, $context = 'advanced', $priority = 'default' ) {
	$GLOBALS['fh_meta_boxes'][] = compact( 'id', 'title', 'callback', 'screen', 'context', 'priority' );
}

function get_current_screen() {
	return $GLOBALS['fh_screen'];
}

function wp_script_is( $handle, $status = 'enqueued' ) {
	return in_array( 'script:' . $handle, $GLOBALS['fh_enqueued'], true );
}

function wp_enqueue_script( $handle, $src = '', $deps = array(), $ver = false, $in_footer = false ) {
	$GLOBALS['fh_enqueued'][] = 'script:' . $handle;
}

function wp_enqueue_style( $handle, $src = '', $deps = array(), $ver = false ) {
	$GLOBALS['fh_enqueued'][] = 'style:' . $handle;
}

function wp_localize_script( $handle, $name, $data ) {
	$GLOBALS['fh_enqueued'][] = 'data:' . $name;
	return true;
}

/**
 * WooCommerce's select field of the product editor, reduced to its markup.
 */
function woocommerce_wp_select( $field ) {
	echo '<p class="form-field ' . esc_attr( $field['id'] ) . '_field ' . esc_attr( $field['wrapper_class'] ?? '' ) . '">';
	echo '<label for="' . esc_attr( $field['id'] ) . '">' . esc_html( $field['label'] ) . '</label>';
	echo '<select id="' . esc_attr( $field['id'] ) . '" name="' . esc_attr( $field['name'] ?? $field['id'] ) . '">';
	foreach ( $field['options'] as $value => $label ) {
		echo '<option value="' . esc_attr( $value ) . '"' . ( (string) $value === (string) $field['value'] ? ' selected="selected"' : '' ) . '>' . esc_html( $label ) . '</option>';
	}
	echo '</select></p>';
}

/**
 * Product categories: $GLOBALS['fh_terms'][ product id ] = array( slugs ).
 */
$GLOBALS['fh_terms'] = array();

function get_the_terms( $post_id, $taxonomy ) {
	$slugs = $GLOBALS['fh_terms'][ (int) $post_id ] ?? array();
	if ( ! $slugs ) {
		return false;
	}
	return array_map(
		static function ( $slug ) {
			return (object) array( 'slug' => $slug );
		},
		$slugs
	);
}

// The section's own file is all WordPress loads before boot(). A test that
// wants to see what a request loads by itself defines FH_PACKING_NO_LOAD
// first; every other test gets every class.
require_once FISHOTEL_MISC_PATH . 'includes/sections/packing/class-packing.php';
if ( ! defined( 'FH_PACKING_NO_LOAD' ) ) {
	\FisHotel\Misc\Sections\Packing\Packing::load_admin();
}
require_once __DIR__ . '/fakes.php';

/**
 * Make the list of sizes of this request a given one (as if it had been
 * resolved already), so nothing goes looking for a database.
 *
 * @param array $list Size_List::standard() shape.
 */
function fh_use_sizes( array $list ) {
	$memo = new \ReflectionProperty( \FisHotel\Misc\Sections\Packing\Size_List::class, 'memo' );
	if ( PHP_VERSION_ID < 80100 ) {
		$memo->setAccessible( true ); // Needed before PHP 8.1, a no-op since.
	}
	$memo->setValue( null, $list );
}

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

function fh_test_export( $value ) {
	$text = (string) preg_replace( '/\s+/', ' ', var_export( $value, true ) );
	return strlen( $text ) > 400 ? substr( $text, 0, 397 ) . '...' : $text;
}

function fh_finish() {
	$failures = $GLOBALS['fh_failures'];
	$checks   = $GLOBALS['fh_checks'];

	echo "\n" . ( $failures ? "$failures of $checks checks FAILED\n" : "All $checks checks passed\n" );
	exit( $failures ? 1 : 0 );
}

/**
 * The settings every test starts from: four invented bags, three invented
 * boxes of the section's own list, how many fit, and a size → bag map over
 * the invented sizes of fh_test_sizes().
 *
 * @return array The stored (normalised) settings.
 */
function fh_test_settings() {
	return \FisHotel\Misc\Sections\Packing\Settings::store(
		array(
			'bags'        => array(
				array( 'id' => 1, 'name' => 'Small', 'width' => '4', 'length' => '12', 'sort' => 10 ),
				array( 'id' => 2, 'name' => 'Medium', 'width' => '6', 'length' => '14', 'sort' => 20 ),
				array( 'id' => 3, 'name' => 'Large', 'width' => '8', 'length' => '18', 'sort' => 30 ),
				array( 'id' => 4, 'name' => 'Jumbo', 'width' => '', 'length' => '', 'sort' => 40 ),
			),
			'last_bag_id' => 4,
			'boxes'       => array(
				array( 'name' => 'Cube 8', 'length' => '8', 'width' => '8', 'height' => '8' ),
				array( 'name' => 'Cube 12', 'length' => '12', 'width' => '12', 'height' => '12' ),
				array( 'name' => 'Long 20', 'length' => '20', 'width' => '14', 'height' => '12' ),
			),
			'fits'        => array(
				'cube 8'  => array( 'name' => 'Cube 8', 'dims' => '8x8x8', 'bags' => array( 1 => 6, 2 => 3 ) ),
				'cube 12' => array( 'name' => 'Cube 12', 'dims' => '12x12x12', 'bags' => array( 1 => 12, 2 => 8, 3 => 4 ) ),
				'long 20' => array( 'name' => 'Long 20', 'dims' => '20x14x12', 'bags' => array( 1 => 30, 2 => 18, 3 => 10, 4 => 2 ) ),
			),
			'size_map'    => array(
				'tiny'  => 1,
				'small' => 1,
				'mid'   => 2,
				'big'   => 3,
			),
			'threshold'   => 90,
		)
	);
}

/**
 * The invented list of sizes: the terms of an attribute "pa_size".
 *
 * @return array Size_List::standard() shape.
 */
function fh_test_sizes() {
	return array(
		'source'    => 'attribute',
		'kind'      => 'attribute',
		'attribute' => 'pa_size',
		'label'     => 'Size',
		'sizes'     => array(
			array( 'slug' => 'tiny', 'label' => 'Tiny (under 1")' ),
			array( 'slug' => 'small', 'label' => 'Small (1-2")' ),
			array( 'slug' => 'mid', 'label' => 'Mid (2-3")' ),
			array( 'slug' => 'big', 'label' => 'Big (3-4")' ),
			array( 'slug' => 'show', 'label' => 'Show (5"+)' ),
		),
	);
}
