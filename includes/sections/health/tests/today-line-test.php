<?php
/**
 * The Health line on the Today page — "Site health: 2 problems found":
 * the line itself (pure), and the filter with the section on, empty
 * (every check fine), off (never hooked: only boot() in wp-admin hooks
 * it) and for a user who may not open the section.
 *
 * Run from the plugin root:
 *   php includes/sections/health/tests/today-line-test.php
 *
 * @package FisHotel\Misc\Sections\Health
 */

require_once __DIR__ . '/wp-stubs.php';

use FisHotel\Misc\Sections\Health\Health;
use FisHotel\Misc\Sections\Health\Today_Line;

// The few WordPress functions this test needs that the shared stubs leave out.
$GLOBALS['fh_filters'] = array();
if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
		$GLOBALS['fh_filters'][ $hook ][] = $callback;
		return true;
	}
}
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $hook, $value ) {
		foreach ( $GLOBALS['fh_filters'][ $hook ] ?? array() as $callback ) {
			$value = call_user_func( $callback, $value );
		}
		return $value;
	}
}
if ( ! function_exists( 'current_user_can' ) ) {
	function current_user_can( $capability ) {
		return in_array( $capability, $GLOBALS['fh_test_caps'] ?? array(), true );
	}
}
if ( ! function_exists( '_n' ) ) {
	function _n( $single, $plural, $number, $domain = null ) {
		return 1 === (int) $number ? $single : $plural;
	}
}

require_once FISHOTEL_MISC_PATH . 'includes/sections/health/class-today-line.php';

$GLOBALS['fh_test_caps'] = array( 'manage_options' );

echo "1. The line\n";
check( 'every check fine: no line', null === Today_Line::line( array( 'errors' => 0, 'warnings' => 0 ), 'u' ) && null === Today_Line::line( array(), 'u' ) );
$fh_line = Today_Line::line( array( 'status' => 'error', 'errors' => 1, 'warnings' => 2 ), 'https://example.test/h' );
check( '"Site health: 3 problems found", under Site', 'Site health: 3 problems found' === $fh_line['text'] && 'site' === $fh_line['group'] && 3 === $fh_line['count'] && 'health' === $fh_line['key'] );
check( '… errors and warnings beside it; an error colours it red', '1 error · 2 warnings' === $fh_line['detail'] && 'bad' === $fh_line['tone'] && 'todo' === Today_Line::line( array( 'warnings' => 1 ), '' )['tone'] && 'Site health: 1 problem found' === Today_Line::line( array( 'warnings' => 1 ), '' )['text'] );

echo "2. Off\n";
check( 'the section never booted in wp-admin: no line is hooked', array() === apply_filters( 'fishotel_today_lines', array() ) );

echo "3. On\n";
Today_Line::register();
Today_Line::use_source( static function () {
	return array( 'status' => 'ok', 'errors' => 0, 'warnings' => 0 );
} );
check( 'every check fine: no line (empty)', array() === apply_filters( 'fishotel_today_lines', array() ) );
Today_Line::use_source( static function () {
	return array( 'status' => 'warn', 'errors' => 0, 'warnings' => 1 );
} );
$fh_lines = apply_filters( 'fishotel_today_lines', array( array( 'key' => 'other', 'text' => 'Another section' ) ) );
check( 'a warning: the line joins the others and leads to the Health overview', 2 === count( $fh_lines ) && 'health' === $fh_lines[1]['key'] && false !== strpos( $fh_lines[1]['url'], 'page=' . Health::MENU_SLUG ) );
$GLOBALS['fh_test_caps'] = array( 'manage_woocommerce' );
check( 'a user without manage_options (the Health screen\'s own rule): no line', array() === apply_filters( 'fishotel_today_lines', array() ) );
$GLOBALS['fh_test_caps'] = array( 'manage_options' );
Today_Line::use_source( static function () {
	throw new \RuntimeException( 'x' );
} );
check( 'a source that fails adds nothing', array() === apply_filters( 'fishotel_today_lines', array() ) );

$fh_boot = (string) file_get_contents( FISHOTEL_MISC_PATH . 'includes/sections/health/class-health.php' );
check( 'boot() hooks it inside the wp-admin branch only', 1 === preg_match( '/if \( is_admin\(\) \) \{[^}]*Today_Line::register\(\);/s', $fh_boot ) );

fh_finish();
