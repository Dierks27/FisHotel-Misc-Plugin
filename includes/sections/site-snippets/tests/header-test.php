<?php
/**
 * Header tests — every field, the defaults, invalid values, a missing or
 * malformed header, the body after the header, the Code Snippets id.
 *
 * Run from the plugin root:
 *   php includes/sections/site-snippets/tests/header-test.php
 *
 * @package FisHotel\Misc\Sections\Site_Snippets
 */

require_once __DIR__ . '/wp-stubs.php';

use FisHotel\Misc\Sections\Site_Snippets\Header;

/**
 * A comment-headed file.
 */
function fh_file( $ext, array $lines, $body = "\nbody\n" ) {
	if ( 'html' === $ext ) {
		return "<!--\n" . implode( "\n", $lines ) . "\n-->" . $body;
	}
	$inner = "/**\n * " . implode( "\n * ", $lines ) . "\n */" . $body;
	return 'php' === $ext ? "<?php\n" . $inner : $inner;
}

echo "1. every field\n";
$p = Header::parse(
	'free-shipping-note.php',
	fh_file(
		'php',
		array(
			'Snippet: Free shipping note',
			'Description: Adds the free-shipping line',
			'  to the cart totals.',
			'Type: php',
			'Scope: front-end',
			'Priority: 5',
			'Origin: Code Snippets #12, imported 2026-10-20',
			'Default: on',
		)
	)
);
check( 'parses', true === $p['ok'], $p['error'] );
check( 'slug and type from the name', 'free-shipping-note' === $p['slug'] && 'php' === $p['type'] );
check( 'name', 'Free shipping note' === $p['fields']['name'] );
check( 'description with its continuation line', 'Adds the free-shipping line to the cart totals.' === $p['fields']['description'], $p['fields']['description'] );
check( 'scope', 'front-end' === $p['fields']['scope'] );
check( 'priority is an int', 5 === $p['fields']['priority'] );
check( 'origin kept verbatim', 'Code Snippets #12, imported 2026-10-20' === $p['fields']['origin'] );
check( 'Code Snippets id from the origin', 12 === $p['fields']['cs_id'] );
check( 'default on', true === $p['fields']['default_on'] );
check( 'body offset is just past the comment', "\nbody\n" === substr( fh_file( 'php', array( 'Snippet: Free shipping note', 'Description: Adds the free-shipping line', '  to the cart totals.', 'Type: php', 'Scope: front-end', 'Priority: 5', 'Origin: Code Snippets #12, imported 2026-10-20', 'Default: on' ) ), $p['body_offset'] ) );

echo "2. defaults\n";
$p = Header::parse( 'x.php', fh_file( 'php', array( 'Snippet: X' ) ) );
check( 'only Snippet given: parses', $p['ok'], $p['error'] );
check( 'type from the extension', 'php' === $p['fields']['type'] );
check( 'php scope defaults to global', 'global' === $p['fields']['scope'] );
check( 'priority defaults to 10', 10 === $p['fields']['priority'] );
check( 'default defaults to off', false === $p['fields']['default_on'] );
check( 'no origin, no Code Snippets id', '' === $p['fields']['origin'] && 0 === $p['fields']['cs_id'] );
check( 'no description', '' === $p['fields']['description'] );
check( 'css scope defaults to front-end', 'front-end' === Header::parse( 'a.css', fh_file( 'css', array( 'Snippet: A' ) ) )['fields']['scope'] );
check( 'js scope defaults to front-end', 'front-end' === Header::parse( 'a.js', fh_file( 'js', array( 'Snippet: A' ) ) )['fields']['scope'] );
check( 'html scope defaults to shortcode', 'shortcode' === Header::parse( 'a.html', fh_file( 'html', array( 'Snippet: A' ) ) )['fields']['scope'] );
check( 'keys are case-insensitive, values trimmed', 'admin' === Header::parse( 'a.php', fh_file( 'php', array( 'SNIPPET:   A  ', 'scope:  ADMIN ' ) ) )['fields']['scope'] );
check( 'Default OFF in capitals', false === Header::parse( 'a.php', fh_file( 'php', array( 'Snippet: A', 'Default: OFF' ) ) )['fields']['default_on'] );
check( 'negative priority', -5 === Header::parse( 'a.php', fh_file( 'php', array( 'Snippet: A', 'Priority: -5' ) ) )['fields']['priority'] );
check( 'unknown keys are ignored', Header::parse( 'a.php', fh_file( 'php', array( 'Snippet: A', 'Author: Jeff', '@package Foo' ) ) )['ok'] );
check( 'a plain /* */ comment works as well as a docblock', Header::parse( 'a.css', "/* Snippet: A\n   Scope: admin */\nbody{}" )['ok'] );
check( 'leading blank lines before a CSS header are fine', Header::parse( 'a.css', "\n\n/* Snippet: A */\nbody{}" )['ok'] );

echo "3. every scope per type\n";
foreach ( Header::SCOPES as $type => $scopes ) {
	foreach ( $scopes as $scope ) {
		$p = Header::parse( 'a.' . $type, fh_file( $type, array( 'Snippet: A', 'Scope: ' . $scope ) ) );
		check( "$type / $scope", $p['ok'] && $scope === $p['fields']['scope'], $p['error'] );
	}
}

echo "4. invalid values\n";
$bad = array(
	'scope not valid for the type'   => array( 'a.php', array( 'Snippet: A', 'Scope: login' ), 'Scope "login" is not valid for a php snippet' ),
	'css cannot be head'             => array( 'a.css', array( 'Snippet: A', 'Scope: head' ), 'not valid for a css snippet' ),
	'unknown type'                   => array( 'a.php', array( 'Snippet: A', 'Type: python' ), 'is not one of php, css, js, html' ),
	'type does not match extension'  => array( 'a.php', array( 'Snippet: A', 'Type: css' ), 'does not match the .php extension' ),
	'priority not a number'          => array( 'a.php', array( 'Snippet: A', 'Priority: high' ), 'is not a whole number' ),
	'priority a decimal'             => array( 'a.php', array( 'Snippet: A', 'Priority: 1.5' ), 'is not a whole number' ),
	'default yes'                    => array( 'a.php', array( 'Snippet: A', 'Default: yes' ), 'must be on or off' ),
	'no Snippet line'                => array( 'a.php', array( 'Description: nameless' ), 'no "Snippet: <name>" line' ),
	'empty Snippet'                  => array( 'a.php', array( 'Snippet:' ), 'no "Snippet: <name>" line' ),
	'Snippet given twice'            => array( 'a.php', array( 'Snippet: A', 'Snippet: B' ), 'is given twice' ),
	'name too long'                  => array( 'a.php', array( 'Snippet: ' . str_repeat( 'n', 121 ) ), 'longer than 120' ),
	'origin too long'                => array( 'a.php', array( 'Snippet: A', 'Origin: ' . str_repeat( 'o', 201 ) ), 'Origin is longer' ),
);
foreach ( $bad as $label => $case ) {
	$p = Header::parse( $case[0], fh_file( pathinfo( $case[0], PATHINFO_EXTENSION ), $case[1] ) );
	check( $label, ! $p['ok'] && false !== strpos( $p['error'], $case[2] ), $p['error'] );
}
$long = Header::parse( 'a.php', fh_file( 'php', array( 'Snippet: A', 'Description: ' . str_repeat( 'd', 600 ) ) ) );
check( 'a long description is cut, not refused', $long['ok'] && 500 === mb_strlen( $long['fields']['description'] ) );

echo "5. missing or malformed header\n";
$missing = array(
	'php without <?php'                 => array( 'a.php', "/**\n * Snippet: A\n */\n" ),
	'php with code before the header'   => array( 'a.php', "<?php\necho 1;\n/**\n * Snippet: A\n */\n" ),
	'php with no header at all'         => array( 'a.php', "<?php\nadd_action( 'init', 'x' );\n" ),
	'<?phpx is not <?php'               => array( 'a.php', "<?phpx\n/** Snippet: A */\n" ),
	'css with a rule before the header' => array( 'a.css', "body{}\n/* Snippet: A */" ),
	'html with a // comment'            => array( 'a.html', "// Snippet: A\n<p>x</p>" ),
	'header never closed'               => array( 'a.js', "/**\n * Snippet: A\n" ),
	'header closes after 8 KB'          => array( 'a.js', "/**\n * Snippet: A\n" . str_repeat( " *\n", 3000 ) . ' */' ),
	'byte-order mark'                   => array( 'a.php', "\xEF\xBB\xBF<?php\n/** Snippet: A */\n" ),
	'empty file'                        => array( 'a.php', '' ),
);
foreach ( $missing as $label => $case ) {
	$p = Header::parse( $case[0], $case[1] );
	check( $label, ! $p['ok'] && '' !== $p['error'], $p['error'] );
}

echo "6. file names\n";
check( 'uppercase refused', ! Header::parse( 'Hide-Bar.php', fh_file( 'php', array( 'Snippet: A' ) ) )['ok'] );
check( 'underscore refused', ! Header::parse( 'hide_bar.php', fh_file( 'php', array( 'Snippet: A' ) ) )['ok'] );
check( 'double hyphen refused', ! Header::parse( 'hide--bar.php', fh_file( 'php', array( 'Snippet: A' ) ) )['ok'] );
check( '65 characters refused', ! Header::parse( str_repeat( 'a', 65 ) . '.php', fh_file( 'php', array( 'Snippet: A' ) ) )['ok'] );
check( 'other extension refused', false !== strpos( Header::parse( 'a.txt', 'x' )['error'], 'must end in .php' ) );
check( 'a path is reduced to its base name', 'hide-bar' === Header::parse( '/srv/x/snippets/hide-bar.php', fh_file( 'php', array( 'Snippet: A' ) ) )['slug'] );
check( 'digits and hyphens fine', Header::parse( 'cs-12-old-promo.php', fh_file( 'php', array( 'Snippet: A' ) ) )['ok'] );

echo "7. body()\n";
check( 'css body after the header', "body { color: red; }\n" === Header::body( 'a.css', "/* Snippet: A */\nbody { color: red; }\n" ) );
check( 'html body after the header', "<p>Hello</p>\n" === Header::body( 'a.html', "<!--\nSnippet: A\n-->\n<p>Hello</p>\n" ) );
check( 'a broken file has no body', '' === Header::body( 'a.html', "<p>no header</p>\n" ) );

echo "8. code_snippets_id()\n";
check( '"Code Snippets #12"', 12 === Header::code_snippets_id( 'Code Snippets #12, imported 2026-10-20' ) );
check( 'case and spacing', 7 === Header::code_snippets_id( 'from code snippets # 7' ) );
check( 'no id', 0 === Header::code_snippets_id( 'written by hand' ) );
check( 'another plugin is not Code Snippets', 0 === Header::code_snippets_id( 'WPCode #12' ) );

fh_finish();
