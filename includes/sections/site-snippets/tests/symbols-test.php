<?php
/**
 * Symbols + Catalog tests — the declarations token_get_all finds, the
 * syntax check, duplicate function / class detection across files (both
 * refused, guarded pairs only warned — guarded meaning an exists check on
 * that exact name, not any `if`), exists-now, and the index built
 * from a folder: broken files, a slug shared by two files, the cached
 * signature.
 *
 * Run from the plugin root:
 *   php includes/sections/site-snippets/tests/symbols-test.php
 *
 * @package FisHotel\Misc\Sections\Site_Snippets
 */

require_once __DIR__ . '/wp-stubs.php';

use FisHotel\Misc\Sections\Site_Snippets\Catalog;
use FisHotel\Misc\Sections\Site_Snippets\Symbols;

function fh_names( array $decls ) {
	return implode(
		',',
		array_map(
			static function ( $d ) {
				// "?" guarded (an exists check names it), "~" nested in braces but unguarded.
				return $d['kind'] . ':' . $d['display'] . ( $d['guarded'] ? '?' : ( $d['nested'] ? '~' : '' ) );
			},
			$decls
		)
	);
}

echo "1. declared()\n";
$code = <<<'PHP'
<?php
/** Snippet: Mixed */
function fh_one() {}
function &fh_by_ref() { static $a = array(); return $a; }
function FH_Mixed_Case() {
	$closure = function () use ( $x ) { return "{$x}"; };
	$arrow   = fn( $y ) => $y * 2;
	$name    = Some_Class::class;
	$anon    = new class() { public function inside() {} };
	return array( $closure, $arrow, $name, $anon );
}
if ( ! function_exists( 'fh_guarded' ) ) {
	function fh_guarded() {}
}
class FH_Box {
	public function method() {}
	public static function make() { return function () {}; }
}
interface FH_Shape {}
trait FH_Helper { function helper() {} }
add_action( 'init', function () { echo 'hi'; } );
function fh_after_class() {}
PHP;
check( 'functions, a reference function, classes, interfaces, traits; methods / closures / ::class / anonymous classes skipped', 'function:fh_one,function:fh_by_ref,function:FH_Mixed_Case,function:fh_guarded?,class:FH_Box,class:FH_Shape,class:FH_Helper,function:fh_after_class' === fh_names( Symbols::declared( $code ) ), fh_names( Symbols::declared( $code ) ) );
check( 'names are lower-cased keys, display keeps the case', 'fh_mixed_case' === Symbols::declared( $code )[2]['name'] && 'FH_Mixed_Case' === Symbols::declared( $code )[2]['display'] );
check( 'line numbers', 3 === Symbols::declared( $code )[0]['line'] );

$ns = "<?php\nnamespace FisHotel\\Snips;\nuse function strlen;\nuse Foo\\Bar;\nfunction helper() {}\nclass Thing {}\n";
check( 'namespaced: names carry the namespace; `use function` is not a declaration', 'function:FisHotel\\Snips\\helper,class:FisHotel\\Snips\\Thing' === fh_names( Symbols::declared( $ns ) ), fh_names( Symbols::declared( $ns ) ) );
$braced = "<?php\nnamespace A { function f() {} }\nnamespace B { function f() {} class C {} }\nnamespace { function g() {} }\n";
check( 'braced namespaces are not "nested"', 'function:A\\f,function:B\\f,class:B\\C,function:g' === fh_names( Symbols::declared( $braced ) ), fh_names( Symbols::declared( $braced ) ) );
check( 'namespace\\foo() is a call, not a namespace change', 'function:x' === fh_names( Symbols::declared( "<?php\nnamespace\\foo();\nfunction x() {}\n" ) ) );
check( 'a function inside a function is nested, not guarded', 'function:outer,function:inner~' === fh_names( Symbols::declared( "<?php\nfunction outer() { function inner() {} }\n" ) ) );
check( 'a class inside class_exists( its name ) is guarded', 'class:Maybe?' === fh_names( Symbols::declared( "<?php\nif ( ! class_exists( 'Maybe' ) ) { class Maybe {} }\n" ) ) );
check( 'any other if guards nothing: `if ( true )`, `if ( ! is_admin() )`', 'function:fh_dup~,function:fh_theme_helper~' === fh_names( Symbols::declared( "<?php\nif ( true ) { function fh_dup() {} }\nif ( ! is_admin() ) {\n\tfunction fh_theme_helper() {}\n}\n" ) ) );
check( 'an exists check on another name guards nothing', 'function:fh_mine~' === fh_names( Symbols::declared( "<?php\nif ( ! function_exists( 'fh_other' ) ) { function fh_mine() {} }\n" ) ) );
check( 'class_exists does not guard a function, function_exists not a class', 'function:Thing~' === fh_names( Symbols::declared( "<?php\nif ( ! class_exists( 'Thing' ) ) { function Thing() {} }\n" ) ) && 'class:Thing~' === fh_names( Symbols::declared( "<?php\nif ( ! function_exists( 'Thing' ) ) { class Thing {} }\n" ) ) );
check( 'the exact name, any case, \\function_exists, double quotes, interface_exists', 'function:FH_Case?,class:FH_Iface?' === fh_names( Symbols::declared( "<?php\nif ( ! \\function_exists( \"fh_case\" ) ) { function FH_Case() {} }\nif ( ! interface_exists( 'FH_Iface', false ) ) { interface FH_Iface {} }\n" ) ) );
check( 'namespaced: the qualified literal or __NAMESPACE__ . the name; a bare short name guards nothing', 'function:Demo\\Shop\\a?,function:Demo\\Shop\\b?,function:Demo\\Shop\\c~' === fh_names( Symbols::declared( "<?php\nnamespace Demo\\Shop;\nif ( ! function_exists( 'Demo\\\\Shop\\\\a' ) ) { function a() {} }\nif ( ! function_exists( __NAMESPACE__ . '\\\\b' ) ) { function b() {} }\nif ( ! function_exists( 'c' ) ) { function c() {} }\n" ) ), fh_names( Symbols::declared( "<?php\nnamespace Demo\\Shop;\nif ( ! function_exists( 'Demo\\\\Shop\\\\a' ) ) { function a() {} }\nif ( ! function_exists( __NAMESPACE__ . '\\\\b' ) ) { function b() {} }\nif ( ! function_exists( 'c' ) ) { function c() {} }\n" ) ) );
check( 'a top-level declaration is never guarded (PHP binds it before any check runs)', 'function:fh_top' === fh_names( Symbols::declared( "<?php\nif ( function_exists( 'fh_top' ) ) { return; }\nfunction fh_top() {}\n" ) ) );
check( 'a method named function_exists is not a check', 'function:fh_m~' === fh_names( Symbols::declared( "<?php\nif ( ! \$o->function_exists( 'fh_m' ) && ! X::function_exists( 'fh_m' ) ) { function fh_m() {} }\n" ) ) );
check( 'heredoc interpolation braces do not confuse depth', 'function:a,function:b' === fh_names( Symbols::declared( "<?php\nfunction a() { \$s = <<<TXT\n{\$x['k']} \${y}\nTXT;\n}\nfunction b() {}\n" ) ) );
check( 'an empty / non-PHP file declares nothing', array() === Symbols::declared( '' ) && array() === Symbols::declared( "/* css */ body { }" ) );

echo "2. syntax_error()\n";
check( 'valid code parses', '' === Symbols::syntax_error( $code )['error'] );
$e = Symbols::syntax_error( "<?php\n/** Snippet: Bad */\nfunction broken( {\n" );
check( 'a syntax error is reported with its line', '' !== $e['error'] && 3 === $e['line'], json_encode( $e ) );
check( 'never executes the code', '' === Symbols::syntax_error( "<?php\nexit( 1 );\n" )['error'] );

echo "3. conflicts()\n";
$a = Symbols::declared( "<?php\nfunction fh_shared() {}\nfunction fh_only_a() {}\nclass FH_Same {}\n" );
$b = Symbols::declared( "<?php\nfunction FH_SHARED() {}\nclass fh_same {}\n" );
$c = Symbols::declared( "<?php\nif ( ! function_exists( 'fh_soft' ) ) { function fh_soft() {} }\n" );
$d = Symbols::declared( "<?php\nif ( ! function_exists( 'fh_soft' ) ) { function fh_soft() {} }\n" );
$e = Symbols::declared( "<?php\nfunction fh_soft() {}\n" );
$conf = Symbols::conflicts( array( 'b-file' => $b, 'a-file' => $a ) );
check( 'same function, different case, two files: refused', 2 === count( $conf ) && 'function' === $conf[0]['kind'] && array( 'a-file', 'b-file' ) === $conf[0]['slugs'] && true === $conf[0]['refused'], json_encode( $conf ) );
check( 'same class in two files: refused', 'class' === $conf[1]['kind'] && true === $conf[1]['refused'] );
$soft = Symbols::conflicts( array( 'c' => $c, 'd' => $d ) );
check( 'both guarded: a warning, not refused', 1 === count( $soft ) && false === $soft[0]['refused'] );
$mixed = Symbols::conflicts( array( 'c' => $c, 'e' => $e ) );
check( 'one guarded + one unconditional: refused (whichever runs second fatals)', 1 === count( $mixed ) && true === $mixed[0]['refused'] );
$iftrue = Symbols::declared( "<?php\nif ( true ) { function fh_dup() {} }\n" );
$nested = Symbols::conflicts( array( 'aaa' => $iftrue, 'bbb' => $iftrue ) );
check( 'the same name inside `if ( true )` in two files: refused (both run)', 1 === count( $nested ) && true === $nested[0]['refused'], json_encode( $nested ) );
$topnest = Symbols::conflicts( array( 'one' => Symbols::declared( "<?php\nfunction fh_tn() {}\nif ( PHP_OS ) { function fh_tn() {} }\n" ) ) );
check( 'one file, top level + again unguarded in an if: refused', 1 === count( $topnest ) && true === $topnest[0]['refused'] );
check( 'one file, top level + again behind its own exists check: fine', array() === Symbols::conflicts( array( 'one' => Symbols::declared( "<?php\nfunction fh_tg() {}\nif ( ! function_exists( 'fh_tg' ) ) { function fh_tg() {} }\n" ) ) ) );
$twice = Symbols::conflicts( array( 'one' => Symbols::declared( "<?php\nfunction fh_dup() {}\nfunction fh_dup() {}\n" ) ) );
check( 'twice in one file: refused', 1 === count( $twice ) && array( 'one' ) === $twice[0]['slugs'] && $twice[0]['refused'] );
check( 'if / else branches in one file: fine', array() === Symbols::conflicts( array( 'one' => Symbols::declared( "<?php\nif ( PHP_OS ) { function v() {} } else { function v() {} }\n" ) ) ) );
check( 'function and class of the same name do not clash', array() === Symbols::conflicts( array( 'x' => Symbols::declared( "<?php\nfunction thing() {}\n" ), 'y' => Symbols::declared( "<?php\nclass Thing {}\n" ) ) ) );
check( 'same short name in two namespaces does not clash', array() === Symbols::conflicts( array( 'x' => Symbols::declared( "<?php\nnamespace A;\nfunction f() {}\n" ), 'y' => Symbols::declared( "<?php\nnamespace B;\nfunction f() {}\n" ) ) ) );

echo "4. exists_now()\n";
check( 'a core function exists', Symbols::exists_now( array( 'kind' => 'function', 'display' => 'strlen' ) ) );
check( 'a stub function exists', Symbols::exists_now( array( 'kind' => 'function', 'display' => 'get_option' ) ) );
check( 'a fresh name does not', ! Symbols::exists_now( array( 'kind' => 'function', 'display' => 'fh_not_declared_anywhere' ) ) );
check( 'a loaded class exists', Symbols::exists_now( array( 'kind' => 'class', 'display' => 'FH_Fake_WPDB' ) ) );
check( 'a namespaced section class exists', Symbols::exists_now( array( 'kind' => 'class', 'display' => 'FisHotel\\Misc\\Sections\\Site_Snippets\\Rules' ) ) );
check( 'an unloaded class does not (no autoload)', ! Symbols::exists_now( array( 'kind' => 'class', 'display' => 'FH_Nope' ) ) );

echo "5. Catalog::build()\n";
$index = Catalog::build(
	array(
		'good.php'       => fh_php_snippet( 'Good', 'function fh_good() {}', array( 'Priority' => '3' ) ),
		'clash-a.php'    => fh_php_snippet( 'Clash A', 'function fh_clash() {}' ),
		'clash-b.php'    => fh_php_snippet( 'Clash B', 'function fh_clash() {}' ),
		'soft-a.php'     => fh_php_snippet( 'Soft A', "if ( ! function_exists( 'fh_soft' ) ) { function fh_soft() {} }" ),
		'soft-b.php'     => fh_php_snippet( 'Soft B', "if ( ! function_exists( 'fh_soft' ) ) { function fh_soft() {} }" ),
		'syntax.php'     => fh_php_snippet( 'Syntax', 'function ( {' ),
		'no-header.php'  => "<?php\nadd_action( 'init', 'x' );\n",
		'twin.php'       => fh_php_snippet( 'Twin PHP', '' ),
		'twin.css'       => "/* Snippet: Twin CSS */\nbody{}",
		'style.css'      => "/* Snippet: Style\n   Scope: admin */\n#wpadminbar{}",
		'Bad_Name.js'    => "/* Snippet: Bad */\n",
		'huge.css'       => "/* Snippet: Huge */\n" . str_repeat( 'a', Catalog::MAX_FILE_BYTES ),
	)
);
check( 'usable snippets', array( 'clash-a', 'clash-b', 'good', 'soft-a', 'soft-b', 'style' ) === array_keys( $index['snippets'] ), implode( ',', array_keys( $index['snippets'] ) ) );
check( 'good: fields + declarations', 3 === $index['snippets']['good']['priority'] && 'good.php' === $index['snippets']['good']['file'] && 'fh_good' === $index['snippets']['good']['declares'][0]['name'] && '' === $index['snippets']['good']['refused'] );
check( 'both clashing files refused, each naming the other', false !== strpos( $index['snippets']['clash-a']['refused'], 'function fh_clash() is declared also in clash-b.php' ) && false !== strpos( $index['snippets']['clash-b']['refused'], 'also in clash-a.php' ), $index['snippets']['clash-a']['refused'] );
check( 'guarded pair not refused, but listed as a conflict', '' === $index['snippets']['soft-a']['refused'] && 2 === count( $index['conflicts'] ) );
$broken = array();
foreach ( $index['broken'] as $row ) {
	$broken[ $row['file'] ] = $row['reason'];
}
check( 'broken: syntax error with its line', isset( $broken['syntax.php'] ) && 0 === strpos( $broken['syntax.php'], 'PHP syntax error on line 8' ), isset( $broken['syntax.php'] ) ? $broken['syntax.php'] : '' );
check( 'broken: missing header', isset( $broken['no-header.php'] ) && false !== strpos( $broken['no-header.php'], 'Missing header' ) );
check( 'broken: twin.php and twin.css share a slug, both out', isset( $broken['twin.php'], $broken['twin.css'] ) && ! isset( $index['snippets']['twin'] ) && false !== strpos( $broken['twin.css'], 'twin.css, twin.php' ) );
check( 'broken: bad file name', isset( $broken['Bad_Name.js'] ) );
check( 'broken: too large', isset( $broken['huge.css'] ) && false !== strpos( $broken['huge.css'], 'larger than' ) );
check( 'css has no declarations', array() === $index['snippets']['style']['declares'] && 'admin' === $index['snippets']['style']['scope'] );
$iftrue = "if ( true ) {\n\tfunction fh_iftrue() { return 1; }\n}";
$index2 = Catalog::build( array( 'aaa.php' => fh_php_snippet( 'A', $iftrue ), 'bbb.php' => fh_php_snippet( 'B', $iftrue ) ) );
check( 'two files declaring one name inside `if ( true )`: both refused, the clash listed as refused', '' !== $index2['snippets']['aaa']['refused'] && '' !== $index2['snippets']['bbb']['refused'] && true === $index2['conflicts'][0]['refused'] );

echo "6. Catalog::get() — the cached index\n";
$dir = fh_snippet_dir(
	array(
		'one.php'   => fh_php_snippet( 'One', '' ),
		'two.css'   => "/* Snippet: Two */\nbody{}",
		'notes.txt' => 'ignored',
		'.hidden.php' => 'ignored',
	)
);
$GLOBALS['fh_writes'] = array();
$first                = Catalog::get( $dir );
check( 'only .php/.css/.js/.html (not hidden) are read', array( 'one', 'two' ) === array_keys( $first['snippets'] ) && array() === $first['broken'] );
check( 'the index is stored, autoloaded', array( Catalog::OPTION_INDEX ) === $GLOBALS['fh_writes'] && true === $GLOBALS['fh_autoload'][ Catalog::OPTION_INDEX ] );
$enabled_key = FisHotel\Misc\Sections\Site_Snippets\Loader::OPTION_ENABLED;
check( 'the switch map is created empty and autoloaded with it (a missing option would cost a SELECT per request)', array( $enabled_key ) === $GLOBALS['fh_adds'] && array() === get_option( $enabled_key ) && true === $GLOBALS['fh_autoload'][ $enabled_key ] );
check( 'with a signature and a build time', 32 === strlen( $first['sig'] ) && $first['built'] > 0 );
Catalog::use_dir( null ); // Drops the memo.
$GLOBALS['fh_writes'] = array();
$second               = Catalog::get( $dir );
check( 'unchanged folder: served from the option, no write', array() === $GLOBALS['fh_writes'] && $first['sig'] === $second['sig'] );
Catalog::use_dir( null );
$fresh = Catalog::get( $dir, true );
check( 'a fresh read of an unchanged folder writes nothing', array() === $GLOBALS['fh_writes'] && $first['built'] === $fresh['built'] );
file_put_contents( $dir . 'three.js', "/* Snippet: Three\n   Scope: head */\nconsole.log(3);\n" );
Catalog::use_dir( null );
$third = Catalog::get( $dir );
check( 'a new file changes the signature and rebuilds once', $third['sig'] !== $first['sig'] && isset( $third['snippets']['three'] ) && array( Catalog::OPTION_INDEX ) === $GLOBALS['fh_writes'] );
check( 'an existing switch map is left alone', array( $enabled_key ) === $GLOBALS['fh_adds'] );
$GLOBALS['fh_options'][ Catalog::OPTION_INDEX ]['snippets']['one']['name'] = 'Tampered';
Catalog::use_dir( null );
check( 'while the signature matches, the stored copy is trusted (no file read)', 'Tampered' === Catalog::get( $dir )['snippets']['one']['name'] );
Catalog::use_dir( null );
check( 'a fresh read repairs it', 'One' === Catalog::get( $dir, true )['snippets']['one']['name'] );
$GLOBALS['fh_options'][ Catalog::OPTION_INDEX ]['sig'] = 'other';
Catalog::use_dir( null );
check( 'a stored index with another signature is rebuilt', 'One' === Catalog::get( $dir )['snippets']['one']['name'] );
Catalog::forget();
check( 'forget() drops the option', false === get_option( Catalog::OPTION_INDEX ) );

fh_finish();
