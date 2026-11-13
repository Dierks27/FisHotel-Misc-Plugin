<?php
/**
 * FisHotel versions card — one row per FisHotel build, its version,
 * folder and state, and the warnings: the same plugin in two folders, a
 * FisHotel plugin switched off, a theme that is not FisHotel or a second
 * copy of it. Pure: no WordPress.
 *
 * Run from the plugin root:
 *   php tests/versions-test.php
 *
 * @package FisHotel\Misc
 */

define( 'ABSPATH', __DIR__ . '/' );

function __( $text, $domain = null ) {
	return $text;
}
function esc_html__( $text, $domain = null ) {
	return htmlspecialchars( $text, ENT_QUOTES );
}
function esc_html( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES );
}
function esc_attr( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES );
}
function _n( $single, $plural, $number, $domain = null ) {
	return 1 === (int) $number ? $single : $plural;
}

require_once dirname( __DIR__ ) . '/includes/class-versions.php';

use FisHotel\Misc\Versions;

$fh_checks   = 0;
$fh_failures = 0;

function check( $label, $condition, $detail = '' ) {
	global $fh_checks, $fh_failures;
	$fh_checks++;
	if ( $condition ) {
		echo "  ok   $label\n";
		return;
	}
	$fh_failures++;
	echo "  FAIL $label" . ( '' !== $detail ? "\n       $detail" : '' ) . "\n";
}

function fh_row( array $rows, $key ) {
	foreach ( $rows as $row ) {
		if ( $key === $row['key'] ) {
			return $row;
		}
	}
	return null;
}

// A site as the final set leaves it (invented versions).
$fh_plugins = array(
	'fh-boards/fh-boards.php'                            => array( 'Name' => 'FH Boards', 'Version' => '9.1.1' ),
	'fishotel-smtp/fishotel-smtp.php'                    => array( 'Name' => 'FisHotel SMTP', 'Version' => '9.2.2' ),
	'fishotel-shiptracker/fishotel-shiptracker.php'      => array( 'Name' => 'FisHotel ShipTracker', 'Version' => '9.3.3' ),
	'fishotel-batch-manager/fishotel-batch-manager.php'  => array( 'Name' => 'FisHotel Batch Manager', 'Version' => '9.4' ),
	'FisHotel-Misc-Plugin-main/fishotel-misc-plugin.php' => array( 'Name' => 'FisHotel Misc Plugin', 'Version' => '9.5' ),
	'woocommerce/woocommerce.php'                        => array( 'Name' => 'WooCommerce', 'Version' => '11.2.0' ),
);
$fh_active = array_keys( $fh_plugins );
$fh_theme  = array( 'name' => 'FisHotel', 'version' => '9.6.0', 'folder' => 'fishotel-theme' );
$fh_themes = array( 'fishotel-theme' => 'FisHotel', 'twentytwentyfive' => 'Twenty Twenty-Five' );

echo "1. All well\n";
$fh_rows = Versions::rows( $fh_plugins, $fh_active, $fh_theme, $fh_themes );
check( 'one row per FisHotel build, in a fixed order, the theme last; no WPIT row when it is not there; WooCommerce is not listed', array( 'boards', 'smtp', 'shiptracker', 'batch', 'misc', 'theme' ) === array_column( $fh_rows, 'key' ) );
$fh_misc = fh_row( $fh_rows, 'misc' );
check( 'each row: version, folder, on', '9.5' === $fh_misc['version'] && 'FisHotel-Misc-Plugin-main' === $fh_misc['folder'] && true === $fh_misc['active'] && 'ok' === $fh_misc['status'] && array() === $fh_misc['notes'] );
check( 'the theme row: name, version, folder', 'Theme: FisHotel' === fh_row( $fh_rows, 'theme' )['label'] && '9.6.0' === fh_row( $fh_rows, 'theme' )['version'] && 'fishotel-theme' === fh_row( $fh_rows, 'theme' )['folder'] && 'ok' === fh_row( $fh_rows, 'theme' )['status'] );
check( 'nothing needs a look', 0 === Versions::warnings( $fh_rows ) );

echo "2. The same plugin in two folders (a bad upload)\n";
$fh_p2 = $fh_plugins + array( 'fishotel-batch-plugin-main/fishotel-batch-manager.php' => array( 'Name' => 'FisHotel Batch Manager', 'Version' => '9.5' ) );
$fh_rows = Versions::rows( $fh_p2, $fh_active, $fh_theme, $fh_themes );
$fh_b    = fh_row( $fh_rows, 'batch' );
check( 'one row, showing the copy that is on', 1 === count( array_keys( array_column( $fh_rows, 'key' ), 'batch', true ) ) && 'fishotel-batch-manager' === $fh_b['folder'] && '9.4' === $fh_b['version'] && $fh_b['active'] );
check( 'a warning naming both folders, their versions and which is on, and what to do', 'warn' === $fh_b['status'] && false !== strpos( $fh_b['notes'][0], 'Installed 2 times: fishotel-batch-manager 9.4 — on; fishotel-batch-plugin-main 9.5 — off.' ) && false !== strpos( $fh_b['notes'][0], 'Keep the one that is on' ) );
$fh_p3 = $fh_plugins + array( 'fh-boards-renamed/boards.php' => array( 'Name' => 'FH Boards', 'Version' => '9.0' ) );
check( 'a copy whose main file has another name is still found by its plugin name', 'warn' === fh_row( Versions::rows( $fh_p3, $fh_active, $fh_theme ), 'boards' )['status'] );

echo "3. Switched off, missing\n";
$fh_rows = Versions::rows( $fh_plugins, array_diff( $fh_active, array( 'fishotel-smtp/fishotel-smtp.php' ) ), $fh_theme );
$fh_s    = fh_row( $fh_rows, 'smtp' );
check( 'a FisHotel plugin switched off: a warning that says what to do', 'warn' === $fh_s['status'] && false === $fh_s['active'] && 'Installed but switched off. Switch it on under Plugins if this site should run it.' === $fh_s['notes'][0] );
$fh_p4 = $fh_plugins;
unset( $fh_p4['fh-boards/fh-boards.php'] );
check( 'a FisHotel plugin not installed: a "Not installed" row', 'missing' === fh_row( Versions::rows( $fh_p4, $fh_active, $fh_theme ), 'boards' )['status'] );
$fh_p5   = $fh_plugins + array( 'wpit-updater/wpit-updater.php' => array( 'Name' => 'WPIT Updater', 'Version' => '9.7.1' ) );
$fh_rows = Versions::rows( $fh_p5, $fh_active, $fh_theme );
$fh_w    = fh_row( $fh_rows, 'wpit' );
check( 'WPIT Updater when it is there: a row; switched off is no warning (live keeps it off)', null !== $fh_w && 'off' === $fh_w['status'] && array() === $fh_w['notes'] && 0 === Versions::warnings( $fh_rows ) );

echo "4. The theme\n";
$fh_rows = Versions::rows( $fh_plugins, $fh_active, array( 'name' => 'Twenty Twenty-Five', 'version' => '1.0', 'folder' => 'twentytwentyfive' ), $fh_themes );
check( 'an active theme that is not FisHotel: a warning', 'warn' === fh_row( $fh_rows, 'theme' )['status'] && false !== strpos( fh_row( $fh_rows, 'theme' )['notes'][0], 'not FisHotel' ) );
$fh_rows = Versions::rows( $fh_plugins, $fh_active, $fh_theme, $fh_themes + array( 'FisHotel-Theme-main' => 'FisHotel' ) );
check( 'a second copy of the FisHotel theme in another folder: a warning naming it', 'warn' === fh_row( $fh_rows, 'theme' )['status'] && false !== strpos( fh_row( $fh_rows, 'theme' )['notes'][0], 'FisHotel-Theme-main' ) );

echo "5. The card\n";
$fh_html = Versions::card_html( Versions::rows( $fh_p2, $fh_active, $fh_theme, $fh_themes ) );
check( 'titled "FisHotel versions", with a count of lines that need a look', false !== strpos( $fh_html, 'FisHotel versions' ) && false !== strpos( $fh_html, '1 line needs a look.' ) );
check( 'a column for the build, the version, the folder and the state', false !== strpos( $fh_html, '<th>Build</th><th>Version</th><th>Folder</th><th>State</th>' ) );
$fh_html = Versions::card_html( Versions::rows( array( 'x/fh-boards.php' => array( 'Name' => '<b>FH Boards</b>', 'Version' => '<script>1</script>' ) ), array(), $fh_theme ) );
check( 'what a plugin header says is escaped', false === strpos( $fh_html, '<script>' ) && false !== strpos( $fh_html, '&lt;script&gt;' ) );
check( 'all well: one plain sentence', false !== strpos( Versions::card_html( Versions::rows( $fh_plugins, $fh_active, $fh_theme, $fh_themes ) ), 'Every FisHotel build is installed once and switched on.' ) );

echo "6. Local reads only, on both screens\n";
$fh_src = (string) file_get_contents( dirname( __DIR__ ) . '/includes/class-versions.php' );
check( 'no outside call anywhere in the class', 0 === preg_match( '/wp_remote_|wp_safe_remote|curl_|file_get_contents\s*\(\s*[\'"]https?:|fsockopen|get_site_transient\(\s*[\'"]update_/', $fh_src ) );
check( 'on the FisHotel Tools Dashboard', false !== strpos( (string) file_get_contents( dirname( __DIR__ ) . '/admin/views/dashboard.php' ), 'Versions::card_html( \\FisHotel\\Misc\\Versions::collect() )' ) );
check( 'on the Health screen', false !== strpos( (string) file_get_contents( dirname( __DIR__ ) . '/includes/sections/health/views/overview.php' ), 'Versions::card_html( \\FisHotel\\Misc\\Versions::collect() )' ) );

echo "\n" . ( $fh_failures ? "$fh_failures FAILED of $fh_checks\n" : "all $fh_checks passed\n" );
exit( $fh_failures ? 1 : 0 );
