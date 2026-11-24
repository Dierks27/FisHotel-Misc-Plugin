<?php
/**
 * Just enough WordPress and WooCommerce to run the Product checks section
 * headless: translation and escaping, capabilities, nonces, options and
 * post meta (with the writes counted), terms with parents, a hook
 * registry, WP-cron, and fake products and variations. Every product,
 * name and number is invented.
 *
 * @package FisHotel\Misc\Sections\Product_Checks
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
define( 'HOUR_IN_SECONDS', 3600 );

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
function esc_sql( $text ) {
	return addslashes( (string) $text );
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
function remove_query_arg( $keys, $url = '' ) {
	foreach ( (array) $keys as $key ) {
		$url = (string) preg_replace( '/[?&]' . preg_quote( $key, '/' ) . '=[^&]*/', '', (string) $url );
	}
	return $url;
}
function checked( $checked, $current = true, $display = true ) {
	$out = (string) $checked === (string) $current ? " checked='checked'" : '';
	if ( $display ) {
		echo $out; // phpcs:ignore
	}
	return $out;
}

$GLOBALS['fh_is_admin'] = true;
function is_admin() {
	return ! empty( $GLOBALS['fh_is_admin'] );
}

/* Users, capabilities, nonces. */
$GLOBALS['fh_user'] = 0;
$GLOBALS['fh_caps'] = array();
function fh_login( $id, array $caps = array() ) {
	$GLOBALS['fh_user'] = (int) $id;
	$GLOBALS['fh_caps'] = $caps;
}
function get_current_user_id() {
	return (int) $GLOBALS['fh_user'];
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
	echo '<input type="hidden" name="' . esc_attr( $name ) . '" value="' . esc_attr( wp_create_nonce( $action ) ) . '">'; // phpcs:ignore
}

/* Options, with the writes counted. */
$GLOBALS['fh_options']       = array();
$GLOBALS['fh_option_writes'] = array();
function get_option( $key, $default = false ) {
	return array_key_exists( $key, $GLOBALS['fh_options'] ) ? $GLOBALS['fh_options'][ $key ] : $default;
}
function update_option( $key, $value, $autoload = null ) {
	$GLOBALS['fh_options'][ $key ] = $value;
	$GLOBALS['fh_option_writes'][] = array( $key, $autoload );
	return true;
}
function delete_option( $key ) {
	unset( $GLOBALS['fh_options'][ $key ] );
	$GLOBALS['fh_option_writes'][] = array( $key, 'delete' );
	return true;
}

/* Post meta, with the writes counted. */
$GLOBALS['fh_post_meta']   = array();
$GLOBALS['fh_meta_writes'] = 0;
function get_post_meta( $id, $key = '', $single = false ) {
	return $GLOBALS['fh_post_meta'][ (int) $id ][ $key ] ?? '';
}
function update_post_meta( $id, $key, $value ) {
	$GLOBALS['fh_post_meta'][ (int) $id ][ $key ] = $value;
	$GLOBALS['fh_meta_writes']++;
	return true;
}
function delete_post_meta( $id, $key ) {
	unset( $GLOBALS['fh_post_meta'][ (int) $id ][ $key ] );
	$GLOBALS['fh_meta_writes']++;
	return true;
}

/* Posts: type by id. */
$GLOBALS['fh_post_types'] = array();
function get_post_type( $id ) {
	return $GLOBALS['fh_post_types'][ (int) $id ] ?? false;
}
function get_attached_file( $id ) {
	return $GLOBALS['fh_files'][ (int) $id ] ?? '';
}
function wp_get_post_parent_id( $id ) {
	$p = $GLOBALS['fh_products'][ (int) $id ] ?? null;
	return is_object( $p ) ? $p->parent : 0;
}

/* Terms: slug → id and parent. */
$GLOBALS['fh_term_tree'] = array(); // slug => array( id, parent slug ).
$GLOBALS['fh_terms']     = array(); // product id => slugs.
function fh_term( $slug ) {
	if ( ! isset( $GLOBALS['fh_term_tree'][ $slug ] ) ) {
		return false;
	}
	return (object) array(
		'term_id' => $GLOBALS['fh_term_tree'][ $slug ][0],
		'slug'    => $slug,
		'name'    => ucwords( str_replace( '-', ' ', $slug ) ),
	);
}
function get_term_by( $field, $value, $tax = '' ) {
	return 'product_cat' === $tax ? fh_term( (string) $value ) : false;
}
function get_the_terms( $id, $tax ) {
	$out = array();
	foreach ( $GLOBALS['fh_terms'][ (int) $id ] ?? array() as $slug ) {
		$out[] = fh_term( $slug );
	}
	return $out ? $out : false;
}
function term_is_ancestor_of( $ancestor, $child, $tax ) {
	$slug = null;
	foreach ( $GLOBALS['fh_term_tree'] as $s => $row ) {
		if ( (int) $row[0] === (int) $child ) {
			$slug = $s;
		}
	}
	while ( null !== $slug && '' !== $GLOBALS['fh_term_tree'][ $slug ][1] ) {
		$slug = $GLOBALS['fh_term_tree'][ $slug ][1];
		if ( (int) $GLOBALS['fh_term_tree'][ $slug ][0] === (int) $ancestor ) {
			return true;
		}
	}
	return false;
}
function get_terms( $args = array() ) {
	$out = array();
	foreach ( array_keys( $GLOBALS['fh_term_tree'] ) as $slug ) {
		$out[] = fh_term( $slug );
	}
	return $out;
}

/* Hooks. */
$GLOBALS['fh_hooks'] = array();
function add_action( $hook, $callback, $priority = 10, $args = 1 ) {
	$GLOBALS['fh_hooks'][ $hook ][] = $callback;
	return true;
}
function add_filter( $hook, $callback, $priority = 10, $args = 1 ) {
	return add_action( $hook, $callback, $priority, $args );
}
function apply_filters( $hook, $value, ...$args ) {
	foreach ( $GLOBALS['fh_hooks'][ $hook ] ?? array() as $callback ) {
		$value = call_user_func_array( $callback, array_merge( array( $value ), $args ) );
	}
	return $value;
}
function fh_reset_hooks() {
	$GLOBALS['fh_hooks'] = array();
}

/* WP-cron. */
$GLOBALS['fh_cron'] = array();
function wp_next_scheduled( $hook ) {
	return $GLOBALS['fh_cron'][ $hook ] ?? false;
}
function wp_schedule_single_event( $time, $hook, $args = array() ) {
	$GLOBALS['fh_cron'][ $hook ] = $time;
	return true;
}
function wp_schedule_event( $time, $recurrence, $hook, $args = array() ) {
	$GLOBALS['fh_cron'][ $hook ] = $time;
	$GLOBALS['fh_recurrence'][ $hook ] = $recurrence;
	return true;
}
function wp_clear_scheduled_hook( $hook ) {
	unset( $GLOBALS['fh_cron'][ $hook ] );
}

/* Screens, assets. */
$GLOBALS['fh_screen']   = null;
$GLOBALS['fh_enqueued'] = array();
function get_current_screen() {
	return $GLOBALS['fh_screen'];
}
function wp_enqueue_style( $handle, $src = '', $deps = array(), $ver = false ) {
	$GLOBALS['fh_enqueued'][] = 'style:' . $handle;
}

/* A WooCommerce product or variation. */
class Fh_Test_Product {
	public $id;
	public $type;
	public $status   = 'publish';
	public $parent   = 0;
	public $children = array();
	public $in_stock = true;
	public $price    = '';
	public $desc     = '';
	public $short    = '';
	public $attrs    = array();
	public function __construct( $id, $type = 'simple', array $props = array() ) {
		$this->id   = (int) $id;
		$this->type = (string) $type;
		foreach ( $props as $key => $value ) {
			$this->$key = $value;
		}
		$GLOBALS['fh_products'][ $this->id ]   = $this;
		$GLOBALS['fh_post_types'][ $this->id ] = 'variation' === $type ? 'product_variation' : 'product';
	}
	public function get_id() {
		return $this->id;
	}
	public function is_type( $type ) {
		return in_array( $this->type, (array) $type, true );
	}
	public function get_status() {
		return $this->status;
	}
	public function get_children() {
		return $this->children;
	}
	public function is_in_stock() {
		return $this->in_stock;
	}
	public function get_regular_price() {
		return $this->price;
	}
	public function get_description() {
		return $this->desc;
	}
	public function get_short_description() {
		return $this->short;
	}
	public function get_attributes() {
		return $this->attrs;
	}
}
$GLOBALS['fh_products'] = array();
function wc_get_product( $id ) {
	return $GLOBALS['fh_products'][ is_object( $id ) ? $id->get_id() : (int) $id ] ?? false;
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

/**
 * A store with no database: the flags written through post meta (the
 * real write()), the reads answered from what a test hands in.
 */
require_once dirname( __DIR__ ) . '/class-product-checks.php';
\FisHotel\Misc\Sections\Product_Checks\Product_Checks::load();

class Fh_Test_Store extends \FisHotel\Misc\Sections\Product_Checks\Store {
	public $counts_answer = array();
	public $shared_ids    = array();
	public $batches       = array();
	public $forgot        = 0;
	public $refreshed     = 0;
	public function counts() {
		return $this->counts_answer;
	}
	public function forget_counts() {
		$this->forgot++;
	}
	public function refresh_shared() {
		$this->refreshed++;
		return $this->shared_ids;
	}
	public function shared() {
		return $this->shared_ids;
	}
	public function batch( $after, $limit ) {
		$out = array();
		foreach ( $this->batches as $id ) {
			if ( $id > $after && count( $out ) < $limit ) {
				$out[] = $id;
			}
		}
		return $out;
	}
}
