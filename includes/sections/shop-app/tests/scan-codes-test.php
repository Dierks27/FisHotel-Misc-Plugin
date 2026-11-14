<?php
/**
 * Scan codes — the QR codes the scanner tests read, made by the Clean
 * Room's OWN label encoder (Qr::encode(), the one that prints the
 * laminated labels), and every tank number the Clean Room's layout gives
 * a label to. tests/scanner.test.js draws these codes as camera frames
 * and reads them with the vendored jsQR, so a label the shop prints and
 * the reader the app loads are tested against each other.
 *
 * The codes live in scan-codes.json (node has no PHP to ask); this test
 * says the file is still what the encoder and the layout make. After a
 * change to either:
 *
 *   php includes/sections/shop-app/tests/scan-codes-test.php --write
 *
 * Also checked here: the vendored jsQR is the file it says it is, its
 * licence is beside it, and nothing outside this section's App class
 * names it.
 *
 * Run from the plugin root:
 *   php includes/sections/shop-app/tests/scan-codes-test.php
 *
 * @package FisHotel\Misc\Sections\Shop_App
 */

define( 'ABSPATH', __DIR__ . '/' );

$fh_root = dirname( __DIR__, 4 ) . '/';
require_once $fh_root . 'includes/sections/clean-room/class-qr.php';
require_once $fh_root . 'includes/sections/clean-room/class-layout.php';

use FisHotel\Misc\Sections\Clean_Room\Qr;
use FisHotel\Misc\Sections\Clean_Room\Layout;

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
	echo "  FAIL $label" . ( '' !== $detail ? "\n       " . ( is_string( $detail ) ? $detail : json_encode( $detail ) ) : '' ) . "\n";
}

/* Invented addresses: this pretend site's labels, a product page of it, another site. */
$fh_texts = array(
	'label121' => 'https://shop.example.test/?fh_front_desk=1&tank=121',
	'label901' => 'https://shop.example.test/?fh_front_desk=1&tank=901',
	'product'  => 'https://shop.example.test/product/invented-goby/',
	'other'    => 'https://elsewhere.example/?fh_front_desk=1&tank=121',
);

$fh_codes = array();
foreach ( $fh_texts as $fh_key => $fh_text ) {
	$fh_qr  = Qr::encode( $fh_text );
	$fh_row = array();
	foreach ( $fh_qr['modules'] as $fh_line ) {
		$fh_row[] = implode( '', array_map( static function ( $b ) { return $b ? '1' : '0'; }, $fh_line ) );
	}
	$fh_codes[ $fh_key ] = array(
		'text' => $fh_text,
		'size' => $fh_qr['size'],
		'rows' => $fh_row,
	);
}

/* Every tank a label is printed for: the seeded buildings and all nine temporary slots. */
$fh_tanks = array();
foreach ( Layout::seed() as $fh_building ) {
	foreach ( $fh_building['tanks'] as $fh_tank ) {
		$fh_tanks[] = (int) $fh_tank['number'];
	}
}
$fh_temp = Layout::temp_seed( Layout::TEMP_BUILDING, Layout::MAX_SLOTS );
foreach ( $fh_temp['tanks'] as $fh_tank ) {
	$fh_tanks[] = (int) $fh_tank['number'];
}
sort( $fh_tanks );

$fh_want = json_encode(
	array(
		'note'  => 'Made by scan-codes-test.php --write from the Clean Room\'s Qr::encode() and Layout. Invented addresses.',
		'codes' => $fh_codes,
		'tanks' => $fh_tanks,
	),
	JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
) . "\n";

$fh_file = __DIR__ . '/scan-codes.json';
if ( in_array( '--write', (array) ( $argv ?? array() ), true ) ) {
	file_put_contents( $fh_file, $fh_want );
	echo "wrote $fh_file\n";
}

echo "1. The codes the scanner tests read\n";
check( 'scan-codes.json is what the label encoder and the layout make now (else: --write)', is_readable( $fh_file ) && file_get_contents( $fh_file ) === $fh_want );
check( 'the label of a tank is the label address: home + ?fh_front_desk=1&tank=N', 0 === strpos( $fh_texts['label121'], 'https://shop.example.test/?fh_front_desk=1&tank=' ) );
check( 'the tanks: the seeded buildings and nine temporary slots — three digits each, none ending in 0', count( $fh_tanks ) > 20 && in_array( 121, $fh_tanks, true ) && in_array( 901, $fh_tanks, true ) && in_array( 909, $fh_tanks, true ) && array() === array_filter( $fh_tanks, static function ( $n ) { return $n < 101 || $n > 999 || 0 === $n % 10; } ) );
check( 'the highest building the layout allows is 900, so no tank number goes past 999', 900 === Layout::MAX_BUILDING && 9 === Layout::MAX_POSITION && 9 === Layout::MAX_SLOTS );

echo "\n2. The vendored reader\n";
$fh_dir = dirname( __DIR__ ) . '/js/vendor/';
$fh_lib = (string) @file_get_contents( $fh_dir . 'jsQR.js' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
check( 'js/vendor/jsQR.js is jsQR 1.4.0 as published on npm, unchanged', 'bc40c8a15196236b2314db0856f72ca0b49980cd5413b8c852a7349f5fee0859' === hash( 'sha256', $fh_lib ) );
$fh_licence = (string) @file_get_contents( $fh_dir . 'jsQR-LICENSE.txt' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
check( 'its licence is beside it: the Apache License 2.0', false !== strpos( $fh_licence, 'Apache License' ) && false !== strpos( $fh_licence, 'Version 2.0, January 2004' ) );
check( 'it loads from this site only: no address of another site in it to fetch from', ! preg_match( '#(src|href)\s*=\s*["\']https?://#i', $fh_lib ) && false === strpos( $fh_lib, 'importScripts' ) && false === strpos( $fh_lib, 'XMLHttpRequest' ) && false === strpos( $fh_lib, 'fetch(' ) );

$fh_named = array();
$fh_iter  = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $fh_root . 'includes', FilesystemIterator::SKIP_DOTS ) );
foreach ( $fh_iter as $fh_found ) {
	$fh_path = str_replace( '\\', '/', (string) $fh_found );
	if ( ! preg_match( '/\.(php|js|css)$/', $fh_path ) || false !== strpos( $fh_path, '/js/vendor/' ) || false !== strpos( $fh_path, '/tests/' ) ) {
		continue;
	}
	if ( false !== stripos( (string) file_get_contents( $fh_path ), 'jsQR.js' ) ) {
		$fh_named[] = substr( $fh_path, strlen( $fh_root ) );
	}
}
sort( $fh_named );
check( 'only the Shop App names it — App (its address) and the scanner (which loads it on need): no other page or section loads it', array( 'includes/sections/shop-app/class-app.php', 'includes/sections/shop-app/js/scanner.js' ) === $fh_named, $fh_named );

echo "\n" . ( $fh_failures ? "$fh_failures of $fh_checks checks FAILED" : "All $fh_checks checks passed" ) . "\n";
exit( $fh_failures ? 1 : 0 );
