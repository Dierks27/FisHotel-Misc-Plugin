<?php
/**
 * Just enough WordPress to load the Snippets section's classes headless:
 * in-memory options, an action / filter / shortcode registry, the style
 * and script queue, is_admin() / current_user_can() switches, and a fake
 * $wpdb that answers the Code Snippets guard query and counts queries.
 * error_log() is pointed at a temp file so a test can read it.
 *
 * Exits 0 when run directly (CI runs every file in tests/).
 *
 * @package FisHotel\Misc\Sections\Site_Snippets
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', '/var/www/html/' );
}
define( 'FISHOTEL_MISC_PATH', dirname( __DIR__, 4 ) . '/' );
define( 'FISHOTEL_MISC_URL', 'https://example.test/wp-content/plugins/fishotel-misc-plugin/' );
define( 'FISHOTEL_MISC_VERSION', 'test' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );

$GLOBALS['fh_error_log'] = tempnam( sys_get_temp_dir(), 'fh-snip-log-' );
ini_set( 'error_log', $GLOBALS['fh_error_log'] ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- tests only.
ini_set( 'log_errors', '1' ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- tests only.
register_shutdown_function(
	static function () {
		if ( is_file( $GLOBALS['fh_error_log'] ) ) {
			unlink( $GLOBALS['fh_error_log'] );
		}
	}
);

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

function checked( $value, $current = true ) {
	echo (string) $value === (string) $current ? " checked='checked'" : '';
}

function disabled( $value, $current = true ) {
	echo (string) $value === (string) $current ? " disabled='disabled'" : '';
}

function wp_die( $message = '' ) {
	throw new RuntimeException( 'wp_die: ' . $message );
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

function wp_unslash( $value ) {
	return is_array( $value ) ? array_map( 'wp_unslash', $value ) : stripslashes( (string) $value );
}

function wp_json_encode( $data, $flags = 0, $depth = 512 ) {
	return json_encode( $data, $flags, $depth );
}

function admin_url( $path = '' ) {
	return 'https://example.test/wp-admin/' . ltrim( (string) $path, '/' );
}

function add_query_arg( $args, $url = '' ) {
	$query = http_build_query( (array) $args );
	return $url . ( false === strpos( (string) $url, '?' ) ? '?' : '&' ) . $query;
}

function wpautop( $text ) {
	return '<p>' . trim( (string) $text ) . "</p>\n";
}

/* ------------------------------------------------------------------ */
/*  Options                                                            */
/* ------------------------------------------------------------------ */

$GLOBALS['fh_options']  = array();
$GLOBALS['fh_autoload'] = array();
$GLOBALS['fh_writes']   = array(); // update_option() calls that changed a value.
$GLOBALS['fh_adds']     = array(); // add_option() calls that created one.

function get_option( $name, $default = false ) {
	return array_key_exists( $name, $GLOBALS['fh_options'] ) ? $GLOBALS['fh_options'][ $name ] : $default;
}

function update_option( $name, $value, $autoload = null ) {
	if ( array_key_exists( $name, $GLOBALS['fh_options'] ) && $GLOBALS['fh_options'][ $name ] === $value ) {
		return false;
	}
	$GLOBALS['fh_options'][ $name ]  = $value;
	$GLOBALS['fh_autoload'][ $name ] = $autoload;
	$GLOBALS['fh_writes'][]          = $name;
	return true;
}

function add_option( $name, $value = '', $deprecated = '', $autoload = null ) {
	if ( array_key_exists( $name, $GLOBALS['fh_options'] ) ) {
		return false;
	}
	$GLOBALS['fh_options'][ $name ]  = $value;
	$GLOBALS['fh_autoload'][ $name ] = $autoload;
	$GLOBALS['fh_adds'][]            = $name;
	return true;
}

function delete_option( $name ) {
	unset( $GLOBALS['fh_options'][ $name ] );
	return true;
}

/* ------------------------------------------------------------------ */
/*  Hooks                                                              */
/* ------------------------------------------------------------------ */

$GLOBALS['fh_hooks']   = array();
$GLOBALS['fh_did']     = array();
$GLOBALS['fh_doing']   = array();
$GLOBALS['fh_actions'] = array(); // do_action calls: array( hook, args ).

function add_action( $hook, $callback, $priority = 10, $args = 1 ) {
	$GLOBALS['fh_hooks'][ $hook ][ $priority ][] = array( $callback, $args );
	return true;
}

function add_filter( $hook, $callback, $priority = 10, $args = 1 ) {
	return add_action( $hook, $callback, $priority, $args );
}

function has_action( $hook, $callback = false ) {
	if ( empty( $GLOBALS['fh_hooks'][ $hook ] ) ) {
		return false;
	}
	if ( false === $callback ) {
		return true;
	}
	foreach ( $GLOBALS['fh_hooks'][ $hook ] as $priority => $list ) {
		foreach ( $list as $entry ) {
			if ( $entry[0] === $callback ) {
				return $priority;
			}
		}
	}
	return false;
}

function did_action( $hook ) {
	return isset( $GLOBALS['fh_did'][ $hook ] ) ? $GLOBALS['fh_did'][ $hook ] : 0;
}

function doing_action( $hook = null ) {
	return in_array( $hook, $GLOBALS['fh_doing'], true );
}

function do_action( $hook, ...$args ) {
	$GLOBALS['fh_actions'][] = array( $hook, $args );
	$GLOBALS['fh_did'][ $hook ] = did_action( $hook ) + 1;
	$GLOBALS['fh_doing'][]      = $hook;

	if ( ! empty( $GLOBALS['fh_hooks'][ $hook ] ) ) {
		ksort( $GLOBALS['fh_hooks'][ $hook ] );
		foreach ( $GLOBALS['fh_hooks'][ $hook ] as $list ) {
			foreach ( $list as $entry ) {
				call_user_func_array( $entry[0], array_slice( $args, 0, (int) $entry[1] ) );
			}
		}
	}

	array_pop( $GLOBALS['fh_doing'] );
}

function apply_filters( $hook, $value, ...$args ) {
	return $value;
}

/**
 * Forget every hook (between scenarios).
 */
function fh_reset_hooks() {
	$GLOBALS['fh_hooks']      = array();
	$GLOBALS['fh_did']        = array();
	$GLOBALS['fh_actions']    = array();
	$GLOBALS['fh_shortcodes'] = array();
	$GLOBALS['fh_styles']     = array();
	$GLOBALS['fh_scripts']    = array();
}

/* ------------------------------------------------------------------ */
/*  Shortcodes                                                         */
/* ------------------------------------------------------------------ */

$GLOBALS['fh_shortcodes'] = array();

function add_shortcode( $tag, $callback ) {
	$GLOBALS['fh_shortcodes'][ $tag ] = $callback;
}

function remove_shortcode( $tag ) {
	unset( $GLOBALS['fh_shortcodes'][ $tag ] );
}

function shortcode_exists( $tag ) {
	return isset( $GLOBALS['fh_shortcodes'][ $tag ] );
}

/**
 * Minimal parser: [tag a="b" c=d flag].
 */
function fh_parse_atts( $text ) {
	$atts = array();
	preg_match_all( '/(\w+)\s*=\s*"([^"]*)"|(\w+)\s*=\s*(\S+)|(\S+)/', (string) $text, $m, PREG_SET_ORDER );
	foreach ( $m as $match ) {
		if ( ! empty( $match[1] ) ) {
			$atts[ strtolower( $match[1] ) ] = $match[2];
		} elseif ( ! empty( $match[3] ) ) {
			$atts[ strtolower( $match[3] ) ] = $match[4];
		} elseif ( isset( $match[5] ) && '' !== $match[5] ) {
			$atts[] = $match[5];
		}
	}
	return $atts;
}

function do_shortcode( $content ) {
	return preg_replace_callback(
		'/\[(\w+)([^\]]*)\]/',
		static function ( $m ) {
			if ( ! isset( $GLOBALS['fh_shortcodes'][ $m[1] ] ) ) {
				return $m[0];
			}
			return call_user_func( $GLOBALS['fh_shortcodes'][ $m[1] ], fh_parse_atts( $m[2] ), null, $m[1] );
		},
		(string) $content
	);
}

/* ------------------------------------------------------------------ */
/*  Styles / scripts                                                   */
/* ------------------------------------------------------------------ */

$GLOBALS['fh_styles']  = array();
$GLOBALS['fh_scripts'] = array();

function wp_register_style( $handle, $src, $deps = array(), $ver = false ) {
	if ( ! isset( $GLOBALS['fh_styles'][ $handle ] ) ) {
		$GLOBALS['fh_styles'][ $handle ] = array( 'src' => $src, 'enqueued' => false, 'inline' => array() );
	}
	return true;
}

function wp_enqueue_style( $handle ) {
	$GLOBALS['fh_styles'][ $handle ]['enqueued'] = true;
}

function wp_add_inline_style( $handle, $css ) {
	$GLOBALS['fh_styles'][ $handle ]['inline'][] = $css;
	return true;
}

function wp_register_script( $handle, $src, $deps = array(), $ver = false, $in_footer = false ) {
	if ( ! isset( $GLOBALS['fh_scripts'][ $handle ] ) ) {
		$GLOBALS['fh_scripts'][ $handle ] = array( 'src' => $src, 'footer' => (bool) $in_footer, 'enqueued' => false, 'inline' => array() );
	}
	return true;
}

function wp_enqueue_script( $handle ) {
	$GLOBALS['fh_scripts'][ $handle ]['enqueued'] = true;
}

function wp_add_inline_script( $handle, $js, $position = 'after' ) {
	$GLOBALS['fh_scripts'][ $handle ]['inline'][] = $js;
	return true;
}

/* ------------------------------------------------------------------ */
/*  Request                                                            */
/* ------------------------------------------------------------------ */

$GLOBALS['fh_is_admin']  = false;
$GLOBALS['fh_can']       = false;
$GLOBALS['fh_can_calls'] = 0;

function is_admin() {
	return (bool) $GLOBALS['fh_is_admin'];
}

function current_user_can( $cap ) {
	$GLOBALS['fh_can_calls']++;
	return (bool) $GLOBALS['fh_can'];
}

/* ------------------------------------------------------------------ */
/*  $wpdb                                                              */
/* ------------------------------------------------------------------ */

/**
 * Fake wpdb: {prefix}snippets with an `active` map, a query counter and a
 * switch that makes the table unreadable.
 */
class FH_Fake_WPDB {
	public $prefix     = 'wp_';
	public $last_error = '';
	public $queries    = array();
	public $active     = array(); // Code Snippets id => 1|0.
	public $broken     = false;
	private $suppress  = false;

	public function suppress_errors( $suppress = true ) {
		$old            = $this->suppress;
		$this->suppress = (bool) $suppress;
		return $old;
	}

	public function prepare( $sql, $args ) {
		$args = is_array( $args ) ? $args : array_slice( func_get_args(), 1 );
		foreach ( $args as $arg ) {
			$sql = preg_replace( '/%d/', (string) (int) $arg, $sql, 1 );
		}
		return $sql;
	}

	public function get_col( $sql ) {
		$this->queries[]  = $sql;
		$this->last_error = '';

		if ( $this->broken ) {
			$this->last_error = "Table 'wp_snippets' doesn't exist";
			return array();
		}

		$out = array();
		if ( preg_match( '/FROM wp_snippets WHERE active = 1 AND id IN \(([\d, ]+)\)/', $sql, $m ) ) {
			foreach ( array_map( 'intval', explode( ',', $m[1] ) ) as $id ) {
				if ( ! empty( $this->active[ $id ] ) ) {
					$out[] = (string) $id;
				}
			}
		}
		return $out;
	}
}

$GLOBALS['wpdb'] = new FH_Fake_WPDB();

/* ------------------------------------------------------------------ */
/*  The section                                                        */
/* ------------------------------------------------------------------ */

require_once FISHOTEL_MISC_PATH . 'includes/class-section-manager.php';
foreach ( array( 'site-snippets', 'header', 'symbols', 'rules', 'catalog', 'runner', 'loader', 'output', 'admin' ) as $fh_class ) {
	require_once FISHOTEL_MISC_PATH . 'includes/sections/site-snippets/class-' . $fh_class . '.php';
}

/**
 * A fresh temp folder of snippet files.
 *
 * @param array<string, string> $files base name => text.
 * @return string Folder with a trailing slash.
 */
function fh_snippet_dir( array $files ) {
	$dir = sys_get_temp_dir() . '/fh-snippets-' . bin2hex( random_bytes( 6 ) ) . '/';
	mkdir( $dir );
	foreach ( $files as $name => $text ) {
		file_put_contents( $dir . $name, $text );
	}
	register_shutdown_function(
		static function () use ( $dir ) {
			foreach ( (array) glob( $dir . '*' ) as $file ) {
				unlink( $file );
			}
			rmdir( $dir );
		}
	);
	return $dir;
}

/**
 * A PHP snippet file's text.
 *
 * @param string $name   Snippet name.
 * @param string $code   Code after the header.
 * @param array  $fields Extra header lines (Key => value).
 * @return string
 */
function fh_php_snippet( $name, $code, array $fields = array() ) {
	$lines = array( ' * Snippet: ' . $name );
	foreach ( $fields as $key => $value ) {
		$lines[] = ' * ' . $key . ': ' . $value;
	}
	return "<?php\n/**\n" . implode( "\n", $lines ) . "\n */\n\ndefined( 'ABSPATH' ) || exit;\n\n" . $code . "\n";
}

/* ------------------------------------------------------------------ */
/*  check() / fh_finish()                                              */
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
	echo "  FAIL $label" . ( '' !== $detail ? "\n       $detail" : '' ) . "\n";
}

function fh_finish() {
	$failures = $GLOBALS['fh_failures'];
	$checks   = $GLOBALS['fh_checks'];

	echo "\n" . ( $failures ? "$failures of $checks checks FAILED\n" : "All $checks checks passed\n" );
	exit( $failures ? 1 : 0 );
}
