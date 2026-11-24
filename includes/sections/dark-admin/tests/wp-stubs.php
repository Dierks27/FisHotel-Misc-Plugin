<?php
/**
 * Just enough WordPress to run the Dark admin section headless: hooks,
 * user meta, the current user and screen, the colour-scheme registry and
 * the enqueue calls are in-memory arrays a test seeds and reads back.
 *
 * @package FisHotel\Misc\Sections\Dark_Admin
 */

define( 'ABSPATH', true );
define( 'FISHOTEL_MISC_PATH', dirname( __DIR__, 4 ) . '/' );
define( 'FISHOTEL_MISC_URL', 'https://example.test/wp-content/plugins/fishotel-misc-plugin/' );
define( 'FISHOTEL_MISC_VERSION', '9.9.9' );

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

function fh_done() {
	echo "\n{$GLOBALS['fh_checks']} checks, {$GLOBALS['fh_failures']} failures\n";
	exit( $GLOBALS['fh_failures'] > 0 ? 1 : 0 );
}

function __( $text, $domain = null ) {
	return $text;
}

function esc_html_e( $text, $domain = null ) {
	echo htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}

function esc_attr( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}

function checked( $checked, $current = true, $echo = true ) {
	$out = (string) $checked === (string) $current ? " checked='checked'" : '';
	if ( $echo ) {
		echo $out;
	}
	return $out;
}

function wp_unslash( $value ) {
	return is_array( $value ) ? array_map( 'wp_unslash', $value ) : stripslashes( (string) $value );
}

/* Hooks. */
$GLOBALS['fh_hooks'] = array(); // hook => list of array( callback, priority, args )

function add_action( $hook, $callback, $priority = 10, $args = 1 ) {
	$GLOBALS['fh_hooks'][ $hook ][] = array( $callback, $priority, $args );
	return true;
}

function add_filter( $hook, $callback, $priority = 10, $args = 1 ) {
	return add_action( $hook, $callback, $priority, $args );
}

function fh_run( $hook, ...$args ) {
	$list = $GLOBALS['fh_hooks'][ $hook ] ?? array();
	usort( $list, function ( $a, $b ) {
		return $a[1] <=> $b[1];
	} );
	$value = $args[0] ?? null;
	foreach ( $list as $h ) {
		$args[0] = $value;
		$out     = call_user_func_array( $h[0], array_slice( $args, 0, $h[2] ) );
		if ( null !== $out ) {
			$value = $out;
		}
	}
	return $value;
}

/* The request. */
$GLOBALS['fh_is_admin']    = true;
$GLOBALS['fh_user']        = 7;
$GLOBALS['fh_can']         = true;
$GLOBALS['fh_screen']      = null;
$GLOBALS['fh_customizing'] = false;
$GLOBALS['pagenow']        = 'index.php';

function is_admin() {
	return $GLOBALS['fh_is_admin'];
}

function get_current_user_id() {
	return $GLOBALS['fh_user'];
}

function current_user_can( $cap, ...$args ) {
	return $GLOBALS['fh_can'];
}

function is_customize_preview() {
	return $GLOBALS['fh_customizing'];
}

class FH_Screen {
	public $block;
	public function __construct( $block = false ) {
		$this->block = $block;
	}
	public function is_block_editor() {
		return $this->block;
	}
}

function get_current_screen() {
	return $GLOBALS['fh_screen'];
}

/* User meta. */
$GLOBALS['fh_meta'] = array(); // user id => key => value

function get_user_meta( $user_id, $key, $single = false ) {
	return $GLOBALS['fh_meta'][ $user_id ][ $key ] ?? '';
}

function update_user_meta( $user_id, $key, $value ) {
	$GLOBALS['fh_meta'][ $user_id ][ $key ] = $value;
	return true;
}

/* Colour schemes and files. */
$GLOBALS['fh_schemes'] = array();
$GLOBALS['fh_styles']  = array();
$GLOBALS['fh_scripts'] = array();

function wp_admin_css_color( $key, $name, $url, $colors = array(), $icons = array() ) {
	$GLOBALS['fh_schemes'][ $key ] = compact( 'name', 'url', 'colors', 'icons' );
}

function wp_enqueue_style( $handle, $src = '', $deps = array(), $ver = false, $media = 'all' ) {
	$GLOBALS['fh_styles'][ $handle ] = $src;
	$GLOBALS['fh_media'][ $handle ]  = $media;
}

function wp_enqueue_script( $handle, $src = '', $deps = array(), $ver = false, $footer = false ) {
	$GLOBALS['fh_scripts'][ $handle ] = array( $src, $footer );
}
