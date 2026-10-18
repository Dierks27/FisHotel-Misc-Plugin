<?php
/**
 * The shipped snippets/ folder — every file must be loadable as it is:
 * php -l on each PHP file (by the PHP running the test; CI runs 8.3, and
 * the lint job runs php -l on 7.4 and 8.3 over every tracked file), a
 * header that parses, no clash, no broken file, the direct-access guard
 * in every PHP snippet, and the example switched off by default.
 *
 * Run from the plugin root:
 *   php includes/sections/site-snippets/tests/snippets-test.php
 *
 * @package FisHotel\Misc\Sections\Site_Snippets
 */

require_once __DIR__ . '/wp-stubs.php';

use FisHotel\Misc\Sections\Site_Snippets\Catalog;
use FisHotel\Misc\Sections\Site_Snippets\Header;

$dir   = dirname( __DIR__ ) . '/snippets/';
$files = Catalog::list_files( $dir );
$php   = '' !== PHP_BINARY ? PHP_BINARY : 'php';

echo "1. every file\n";
check( 'the folder has at least the example', in_array( 'example.php', $files, true ) );

foreach ( $files as $file ) {
	$text   = (string) file_get_contents( $dir . $file );
	$parsed = Header::parse( $file, $text );
	check( "$file: header parses", $parsed['ok'], $parsed['error'] );

	if ( '.php' === substr( $file, -4 ) ) {
		$out  = array();
		$code = 1;
		exec( escapeshellarg( $php ) . ' -l ' . escapeshellarg( $dir . $file ) . ' 2>&1', $out, $code );
		check( "$file: php -l", 0 === $code, implode( ' ', $out ) );
		check( "$file: has the defined( 'ABSPATH' ) || exit; guard", 1 === preg_match( "/defined\\(\\s*'ABSPATH'\\s*\\)\\s*\\|\\|\\s*exit\\s*;/", $text ) );
	}
}

echo "2. the folder as an index\n";
$index = Catalog::build(
	array_combine(
		$files,
		array_map(
			static function ( $f ) use ( $dir ) {
				return (string) file_get_contents( $dir . $f );
			},
			$files
		)
	)
);
check( 'no broken file', array() === $index['broken'], json_encode( $index['broken'] ) );
check( 'no clash', array() === $index['conflicts'], json_encode( $index['conflicts'] ) );
check( 'every file is a usable snippet', count( $files ) === count( $index['snippets'] ) );
check( 'the example is off by default and has no Code Snippets origin', isset( $index['snippets']['example'] ) && false === $index['snippets']['example']['default_on'] && 0 === $index['snippets']['example']['cs_id'] );

fh_finish();
