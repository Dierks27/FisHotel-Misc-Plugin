<?php
/**
 * Loader / Runner / Output / switch tests — the early loader's hook, then
 * whole requests on a temp
 * folder of invented snippets: the run at plugins_loaded:1 in priority
 * order, a snippet that throws recorded (error_log, the fishotel_log
 * action, the stored error) while the next one still runs, output
 * discarded and locals kept local, front end vs admin, what an ordinary
 * request costs (no query, no write), CSS / JS / HTML printing, the
 * shortcodes and the [code_snippet] mapping, safe mode by query, the
 * switch refusing broken and clashing files, the exists-now preflight,
 * the Code Snippets duplicate guard (one query, fail closed), and safe
 * mode by constant last (a constant cannot be undefined).
 *
 * Run from the plugin root:
 *   php includes/sections/site-snippets/tests/loader-test.php
 *
 * @package FisHotel\Misc\Sections\Site_Snippets
 */

require_once __DIR__ . '/wp-stubs.php';

use FisHotel\Misc\Sections\Site_Snippets\Admin;
use FisHotel\Misc\Sections\Site_Snippets\Catalog;
use FisHotel\Misc\Sections\Site_Snippets\Loader;
use FisHotel\Misc\Sections\Site_Snippets\Output;
use FisHotel\Misc\Sections\Site_Snippets\Runner;

/**
 * Simulate one request: fresh per-request state, the early hooks, then
 * plugins_loaded (priority 1 runs the PHP snippets, 10 boots Output,
 * 20 flushes the log). Returns what the request printed.
 */
function fh_request( $dir, $is_admin = false ) {
	fh_reset_hooks();
	Loader::reset();
	Catalog::use_dir( $dir );
	$GLOBALS['fh_is_admin'] = $is_admin;
	$GLOBALS['fh_ran']      = array();
	$GLOBALS['wpdb']->queries = array();
	$GLOBALS['fh_writes']     = array();

	Loader::hook();
	add_action( 'plugins_loaded', array( Output::class, 'init' ), 10 );

	ob_start();
	do_action( 'plugins_loaded' );
	return ob_get_clean();
}

function fh_log_text() {
	clearstatcache();
	return (string) file_get_contents( $GLOBALS['fh_error_log'] );
}

function fh_logged( $level ) {
	$out = array();
	foreach ( $GLOBALS['fh_actions'] as $call ) {
		if ( 'fishotel_log' === $call[0] && $level === $call[1][0] ) {
			$out[] = $call[1];
		}
	}
	return $out;
}

$dir = fh_snippet_dir(
	array(
		'a-global.php'  => fh_php_snippet( 'A global', "\$GLOBALS['fh_ran'][] = 'a-global';", array( 'Default' => 'on' ) ),
		'b-throws.php'  => fh_php_snippet( 'B throws', "\$GLOBALS['fh_ran'][] = 'b-throws';\nthrow new \\LogicException( 'boom from b' );", array( 'Scope' => 'front-end', 'Priority' => '5', 'Default' => 'on' ) ),
		'c-admin.php'   => fh_php_snippet( 'C admin', "\$GLOBALS['fh_ran'][] = 'c-admin';", array( 'Scope' => 'admin', 'Default' => 'on' ) ),
		'd-late.php'    => fh_php_snippet( 'D late', "echo 'printed by d';\n\$local_to_d = 1;\n\$GLOBALS['fh_ran'][] = 'd-late';\n\$GLOBALS['fh_d_vars'] = array_keys( get_defined_vars() );", array( 'Priority' => '20', 'Default' => 'on' ) ),
		'e-off.php'     => fh_php_snippet( 'E off', "\$GLOBALS['fh_ran'][] = 'e-off';" ),
		'style.css'     => "/**\n * Snippet: Style\n * Default: on\n */\nbody { color: teal; }\n",
		'admin.css'     => "/**\n * Snippet: Admin style\n * Scope: admin\n * Default: on\n */\n#wpadminbar { background: #123; }\n",
		'head.js'       => "/**\n * Snippet: Head script\n * Scope: head\n * Default: on\n */\nwindow.fhHead = 1;\n",
		'foot.js'       => "/**\n * Snippet: Foot script\n * Default: on\n */\nwindow.fhFoot = 1;\n",
		'login.js'      => "/**\n * Snippet: Login script\n * Scope: login\n */\nwindow.fhLogin = 1;\n",
		'banner.html'   => "<!--\nSnippet: Banner\nOrigin: Code Snippets #31, imported 2026-10-20\nDefault: on\n-->\n<div class=\"banner\">Spring sale</div>\n",
		'quiet.html'    => "<!--\nSnippet: Quiet\nOrigin: Code Snippets #32\n-->\n<p>off by default</p>\n",
		'pixel.html'    => "<!--\nSnippet: Pixel\nScope: footer\nDefault: on\n-->\n<img src=\"/pixel.gif\" alt=\"\">\n",
		'nested.html'   => "<!--\nSnippet: Nested\nDefault: on\n-->\nBefore [fh_snippet slug=\"banner\"] [fh_snippet slug=\"nested\" shortcodes]\n",
		'no-header.php' => "<?php\n\$GLOBALS['fh_ran'][] = 'no-header';\n",
		'clash-a.php'   => fh_php_snippet( 'Clash A', 'function fh_test_clash() {}' ),
		'clash-b.php'   => fh_php_snippet( 'Clash B', 'function fh_test_clash() {}' ),
	)
);

echo "0. The early loader\n";
fh_reset_hooks();
$GLOBALS['fh_options'][ FisHotel\Misc\Section_Manager::OPTION_KEY ] = array( 'health', 'today' );
require_once FISHOTEL_MISC_PATH . 'includes/sections/site-snippets/early-loader.php';
check( 'section off: nothing hooked', false === has_action( 'plugins_loaded' ) );
$GLOBALS['fh_options'][ FisHotel\Misc\Section_Manager::OPTION_KEY ] = array( 'health', 'site-snippets' );
FisHotel\Misc\Sections\Site_Snippets\early_load();
check( 'section on (the Section Manager\'s option): the PHP run at plugins_loaded 1, the log flush at 20', 1 === has_action( 'plugins_loaded', array( Loader::class, 'run_php' ) ) && 20 === has_action( 'plugins_loaded', array( Loader::class, 'flush_log' ) ) );
$GLOBALS['fh_options'][ FisHotel\Misc\Section_Manager::OPTION_KEY ] = 'garbage';
fh_reset_hooks();
FisHotel\Misc\Sections\Site_Snippets\early_load();
check( 'a malformed option hooks nothing', false === has_action( 'plugins_loaded' ) );
unset( $GLOBALS['fh_options'][ FisHotel\Misc\Section_Manager::OPTION_KEY ] );
check( 'the main plugin file includes the early loader', false !== strpos( (string) file_get_contents( FISHOTEL_MISC_PATH . 'fishotel-misc-plugin.php' ), "require_once FISHOTEL_MISC_PATH . 'includes/sections/site-snippets/early-loader.php';" ) );

echo "1. Runner\n";
$GLOBALS['fh_ran'] = array();
$errors            = array();
$level             = ob_get_level();
ob_start();
$results = Runner::run(
	array(
		array( 'slug' => 'b-throws', 'path' => $dir . 'b-throws.php' ),
		array( 'slug' => 'gone', 'path' => $dir . 'gone.php' ),
		array( 'slug' => 'a-global', 'path' => $dir . 'a-global.php' ),
		array( 'slug' => 'skip-me', 'path' => $dir . 'a-global.php' ),
		array( 'slug' => 'd-late', 'path' => $dir . 'd-late.php' ),
	),
	static function ( $slug, $e ) use ( &$errors ) {
		$errors[ $slug ] = get_class( $e ) . ': ' . $e->getMessage();
	},
	static function ( $item ) {
		return 'skip-me' === $item['slug'] ? 'skipped by the preflight' : '';
	}
);
$printed = ob_get_clean();
check( 'a throwing snippet is recorded and the next ones still run', array( 'b-throws', 'a-global', 'd-late' ) === $GLOBALS['fh_ran'], implode( ',', $GLOBALS['fh_ran'] ) );
check( 'results per slug', array( 'b-throws' => 'error', 'gone' => 'missing', 'a-global' => 'ok', 'skip-me' => 'skipped', 'd-late' => 'ok' ) === $results, json_encode( $results ) );
check( 'the exception reaches the callback', 'LogicException: boom from b' === $errors['b-throws'] && 0 === strpos( $errors['gone'], 'RuntimeException: Snippet file is missing' ) );
check( 'what a snippet prints is discarded (as Code Snippets does)', '' === $printed && $level === ob_get_level() );
check( 'a snippet\'s variables stay local: it sees only its own', array( 'local_to_d' ) === $GLOBALS['fh_d_vars'] && ! isset( $GLOBALS['local_to_d'] ), json_encode( $GLOBALS['fh_d_vars'] ) );
$bad = fh_snippet_dir( array( 'parse.php' => "<?php\nfunction ( {\n", 'buffer.php' => "<?php\nob_start();\necho 'left open';\n" ) );
$res = Runner::run( array( array( 'slug' => 'parse', 'path' => $bad . 'parse.php' ), array( 'slug' => 'buffer', 'path' => $bad . 'buffer.php' ) ), null );
check( 'a ParseError is caught too; a buffer left open is closed', 'error' === $res['parse'] && 'ok' === $res['buffer'] && $level === ob_get_level() );
$res = Runner::run( array( array( 'slug' => 'a', 'path' => $dir . 'b-throws.php' ) ), static function () { throw new \RuntimeException( 'callback failed' ); } );
check( 'a failing error callback does not stop the runner', array( 'a' => 'error' ) === $res );

echo "2. A front-end request\n";
$printed = fh_request( $dir, false );
$state   = Loader::state();
check( 'priority order: b (5) threw, a (10), d (20); admin-only, off, broken and clashing ones skipped', array( 'b-throws', 'a-global', 'd-late' ) === $GLOBALS['fh_ran'], implode( ',', $GLOBALS['fh_ran'] ) );
check( 'the error is in the request state', false !== strpos( $state['errors']['b-throws'], 'Snippet b-throws threw LogicException: boom from b in ' ) );
check( 'error_log got the line', false !== strpos( fh_log_text(), 'FisHotel Snippets: Snippet b-throws threw LogicException: boom from b' ) );
$logged = fh_logged( 'error' );
check( 'handed to fishotel_log at plugins_loaded:20 (source snippets, context)', 1 === count( $logged ) && 'snippets' === $logged[0][1] && 'b-throws' === $logged[0][3]['snippet'] && 'LogicException' === $logged[0][3]['exception'] );
$stored = Loader::stored_errors();
check( 'the newest error is stored per snippet, not autoloaded', isset( $stored['b-throws']['message'] ) && false === $GLOBALS['fh_autoload'][ Loader::OPTION_ERRORS ] );
check( 'no query on a request without Code Snippets', array() === $GLOBALS['wpdb']->queries );
check( 'nothing printed by the PHP snippets', '' === $printed );
check( 'the capability is never checked without the safe-mode flag', 0 === $GLOBALS['fh_can_calls'] );

echo "3. What an ordinary (second) request costs\n";
fh_request( $dir, false );
check( 'same snippets run', array( 'b-throws', 'a-global', 'd-late' ) === $GLOBALS['fh_ran'] );
check( 'no option write (index unchanged, same error within the hour)', array() === $GLOBALS['fh_writes'], implode( ',', $GLOBALS['fh_writes'] ) );
check( 'no query', array() === $GLOBALS['wpdb']->queries );

echo "4. An admin request\n";
fh_request( $dir, true );
check( 'global + admin snippets, not front-end ones', array( 'a-global', 'c-admin', 'd-late' ) === $GLOBALS['fh_ran'], implode( ',', $GLOBALS['fh_ran'] ) );

echo "5. CSS / JS / HTML output\n";
fh_request( $dir, false );
do_action( 'wp_enqueue_scripts' );
$front = $GLOBALS['fh_styles']['fishotel-snippets-front'];
check( 'front CSS inline on an empty registered handle, header stripped', false === $front['src'] && $front['enqueued'] && array( "/* fh-snippet: style */\nbody { color: teal; }\n" ) === $front['inline'], json_encode( $front ) );
check( 'admin CSS not on the front end', ! isset( $GLOBALS['fh_styles']['fishotel-snippets-admin'] ) );
check( 'head JS in <head>', false === $GLOBALS['fh_scripts']['fishotel-snippets-head']['footer'] && array( "/* fh-snippet: head */\nwindow.fhHead = 1;\n" ) === $GLOBALS['fh_scripts']['fishotel-snippets-head']['inline'] );
check( 'front-end JS in the footer', true === $GLOBALS['fh_scripts']['fishotel-snippets-footer']['footer'] && 1 === count( $GLOBALS['fh_scripts']['fishotel-snippets-footer']['inline'] ) );
check( 'an off login script registers no login hook', false === has_action( 'login_enqueue_scripts' ) );
ob_start();
do_action( 'wp_footer' );
$footer = ob_get_clean();
check( 'footer HTML echoed at wp_footer', "\n<img src=\"/pixel.gif\" alt=\"\">\n\n" === $footer, json_encode( $footer ) );
check( 'no head / body HTML hooks without such snippets', false === has_action( 'wp_head' ) && false === has_action( 'wp_body_open' ) );
fh_request( $dir, true );
do_action( 'admin_enqueue_scripts' );
check( 'admin CSS in admin', array( "/* fh-snippet: admin */\n#wpadminbar { background: #123; }\n" ) === $GLOBALS['fh_styles']['fishotel-snippets-admin']['inline'] );
check( 'no front-end hooks registered for an admin screen are needed — front CSS is not printed in admin', ! isset( $GLOBALS['fh_styles']['fishotel-snippets-front'] ) );

echo "6. Shortcodes\n";
fh_request( $dir, false );
check( '[fh_snippet slug="banner"] — the file text without its final newline (Code Snippets returns the code as is)', "<div class=\"banner\">Spring sale</div>" === do_shortcode( '[fh_snippet slug="banner"]' ) );
check( 'format', "<p><div class=\"banner\">Spring sale</div></p>\n" === do_shortcode( '[fh_snippet slug="banner" format]' ) );
check( 'an off snippet renders nothing', '' === do_shortcode( '[fh_snippet slug="quiet"]' ) );
check( 'an unknown slug, a PHP snippet and a footer snippet render nothing', '' === do_shortcode( '[fh_snippet slug="nope"]' ) && '' === do_shortcode( '[fh_snippet slug="a-global"]' ) && '' === do_shortcode( '[fh_snippet slug="pixel"]' ) );
$nested = do_shortcode( '[fh_snippet slug="nested" shortcodes]' );
check( 'shortcodes: inner shortcodes run, our own tag is off meanwhile (no recursion), then back', "Before [fh_snippet slug=\"banner\"] [fh_snippet slug=\"nested\" shortcodes]" === $nested && shortcode_exists( 'fh_snippet' ), json_encode( $nested ) );
check( 'without the flag, inner shortcodes stay text', false !== strpos( do_shortcode( '[fh_snippet slug="nested"]' ), '[fh_snippet slug="banner"]' ) );
do_action( 'init' );
check( '[code_snippet] registered at init while Code Snippets is not loaded', shortcode_exists( 'code_snippet' ) );
check( '[code_snippet id=31] → the file whose Origin is Code Snippets #31', "<div class=\"banner\">Spring sale</div>" === do_shortcode( '[code_snippet id=31]' ) );
check( 'snippet_id= works too', "<div class=\"banner\">Spring sale</div>" === do_shortcode( '[code_snippet snippet_id="31"]' ) );
check( 'inline in a sentence: no newline before the full stop', "Sale: <div class=\"banner\">Spring sale</div>." === do_shortcode( 'Sale: [code_snippet id=31].' ), json_encode( do_shortcode( 'Sale: [code_snippet id=31].' ) ) );
check( 'format=false formats, as Code Snippets reads it', "<p><div class=\"banner\">Spring sale</div></p>\n" === do_shortcode( '[code_snippet id=31 format=false]' ) );
check( 'an off one (#32) and an unknown id render nothing', '' === do_shortcode( '[code_snippet id=32]' ) && '' === do_shortcode( '[code_snippet id=99]' ) );
$plain = fh_snippet_dir( array( 'x.html' => "<!--\nSnippet: X\nDefault: on\n-->\nx\n" ) );
fh_request( $plain, false );
do_action( 'init' );
check( 'no [code_snippet] when no file names a Code Snippets id', ! shortcode_exists( 'code_snippet' ) );

echo "7. Safe mode by query\n";
$_GET[ Loader::SAFE_MODE_QUERY ] = '1';
$GLOBALS['fh_can']               = false;
fh_request( $dir, false );
check( 'a visitor with the flag: snippets still run', array( 'b-throws', 'a-global', 'd-late' ) === $GLOBALS['fh_ran'] );
$GLOBALS['fh_can'] = true;
fh_request( $dir, true );
check( 'an administrator with the flag: no PHP snippet runs', array() === $GLOBALS['fh_ran'] && 'query' === Loader::safe_mode()['reason'] );
check( 'no CSS / JS / HTML hooks either', false === has_action( 'admin_enqueue_scripts' ) && false === has_action( 'wp_footer' ) );
check( 'shortcodes render nothing', '' === do_shortcode( '[fh_snippet slug="banner"]' ) );
check( 'the page link keeps the flag', false !== strpos( Admin::page_url(), Loader::SAFE_MODE_QUERY . '=1' ) );
unset( $_GET[ Loader::SAFE_MODE_QUERY ] );
$GLOBALS['fh_can'] = false;

echo "8. The switch\n";
fh_request( $dir, true );
$GLOBALS['fh_writes'] = array();
$r = Admin::switch_snippet( 'no-header', true );
check( 'refuses a file whose header does not parse', false === $r['ok'] && false !== strpos( $r['message'], 'Missing header' ) && array() === $GLOBALS['fh_writes'], $r['message'] );
$r = Admin::switch_snippet( 'clash-a', true );
check( 'refuses a file that clashes with another', false === $r['ok'] && false !== strpos( $r['message'], 'fh_test_clash() is declared also in clash-b.php' ), $r['message'] );
$r = Admin::switch_snippet( 'missing-file', true );
check( 'refuses an unknown slug', false === $r['ok'] );
check( 'refuses an empty slug', false === Admin::switch_snippet( '', true )['ok'] );
$r = Admin::switch_snippet( 'e-off', true );
check( 'switches on: autoloaded option slug => true', $r['ok'] && true === get_option( Loader::OPTION_ENABLED )['e-off'] && true === $GLOBALS['fh_autoload'][ Loader::OPTION_ENABLED ] && 'on' === $r['status']['key'] );
check( 'a PHP snippet is test-run once before it is stored as on', 'e-off' === end( $GLOBALS['fh_ran'] ), implode( ',', $GLOBALS['fh_ran'] ) );
fh_request( $dir, false );
check( 'and it runs on the next request', in_array( 'e-off', $GLOBALS['fh_ran'], true ) );
$r = Admin::switch_snippet( 'a-global', false );
check( 'switching off a Default-on snippet stores false', $r['ok'] && false === get_option( Loader::OPTION_ENABLED )['a-global'] && 'off' === $r['status']['key'] );
check( 'switching off is allowed even for a broken file', Admin::switch_snippet( 'no-header', false )['ok'] );
$GLOBALS['fh_writes'] = array();
$r                    = Admin::switch_snippet( 'nope-unknown', false );
check( 'switching off an unknown slug is refused and stores nothing', false === $r['ok'] && array() === $GLOBALS['fh_writes'] && ! array_key_exists( 'nope-unknown', get_option( Loader::OPTION_ENABLED ) ) );
update_option( Loader::OPTION_ENABLED, array_merge( get_option( Loader::OPTION_ENABLED ), array( 'deleted-file' => true ) ), true );
check( 'a stored slug whose file is gone can still be switched off', Admin::switch_snippet( 'deleted-file', false )['ok'] && false === get_option( Loader::OPTION_ENABLED )['deleted-file'] );
$r = Admin::switch_snippet( 'b-throws', false );
check( 'switching clears the stored error', ! isset( Loader::stored_errors()['b-throws'] ) );
fh_request( $dir, false );
check( 'next request: a-global and b-throws off, e-off on', array( 'e-off', 'd-late' ) === $GLOBALS['fh_ran'], implode( ',', $GLOBALS['fh_ran'] ) );
$index = Catalog::get();
check( 'status: refused label', 'refused' === Admin::status( $index['snippets']['clash-a'], array(), array(), array( 'active' => false ) )['key'] );
check( 'status: off / on / held / safe', 'off' === Admin::status( $index['snippets']['quiet'], array(), array(), array( 'active' => false ) )['key']
	&& 'on' === Admin::status( $index['snippets']['banner'], array(), array(), array( 'active' => false ) )['key']
	&& 'held' === Admin::status( $index['snippets']['banner'], array(), array( 'banner' => 'Code Snippets #31 is still active' ), array( 'active' => false ) )['key']
	&& 'safe' === Admin::status( $index['snippets']['banner'], array(), array(), array( 'active' => true ) )['key'] );
check( 'status: a refused file that is on can still be switched off (locked only against on)', true === Admin::status( $index['snippets']['clash-a'], array( 'clash-a' => true ), array(), array( 'active' => false ) )['on'] );

echo "8b. The switch's test run\n";
$trial = fh_snippet_dir(
	array(
		'throws-on.php'  => fh_php_snippet( 'Throws', "throw new \\DomainException( 'trial boom' );" ),
		'exists-on.php'  => fh_php_snippet( 'Exists', 'function wp_unslash( $v ) { return $v; }' ),
		'not-guard.php'  => fh_php_snippet( 'Not a guard', "if ( ! is_admin() ) {\n\tfunction get_option() {}\n}" ),
		'already-on.php' => fh_php_snippet( 'Already on', "function fh_already_on_xyz() {}\n\$GLOBALS['fh_ran'][] = 'already-on';", array( 'Default' => 'on' ) ),
		'trial-css.css'  => "/**\n * Snippet: Trial CSS\n */\nbody{}\n",
		'compile.php'    => fh_php_snippet( 'Compile error', 'function fh_two( $a, $a ) { return $a; }' ),
	)
);
fh_request( $trial, true );
check( 'a compile-stage error passes the parse check (only running it shows it)', isset( Catalog::get()['snippets']['compile'] ) && '' === Catalog::get()['snippets']['compile']['refused'] );
$GLOBALS['fh_writes'] = array();
$r                    = Admin::switch_snippet( 'throws-on', true );
check( 'a snippet that throws when run is refused, with the exception, and not stored', false === $r['ok'] && false !== strpos( $r['message'], 'DomainException: trial boom' ) && array() === $GLOBALS['fh_writes'], $r['message'] );
$r = Admin::switch_snippet( 'exists-on', true );
check( 'a snippet declaring a name that already exists is refused', false === $r['ok'] && false !== strpos( $r['message'], 'function wp_unslash() already exists' ), $r['message'] );
$r = Admin::switch_snippet( 'not-guard', true );
check( '… also inside an if that is not an exists check on that name', false === $r['ok'] && false !== strpos( $r['message'], 'function get_option() already exists' ), $r['message'] );
$r = Admin::switch_snippet( 'already-on', true );
check( 'an already-on snippet is not run a second time (it ran at plugins_loaded:1)', $r['ok'] && array( 'already-on' ) === $GLOBALS['fh_ran'], implode( ',', $GLOBALS['fh_ran'] ) );
check( 'a CSS snippet is never run', Admin::switch_snippet( 'trial-css', true )['ok'] );
$child = sys_get_temp_dir() . '/fh-snip-child-' . bin2hex( random_bytes( 4 ) ) . '.php';
file_put_contents(
	$child,
	"<?php\nfunction wp_doing_ajax() { return true; }\nrequire " . var_export( __DIR__ . '/wp-stubs.php', true ) . ";\n"
	. 'FisHotel\\Misc\\Sections\\Site_Snippets\\Catalog::use_dir( ' . var_export( $trial, true ) . " );\n"
	. "register_shutdown_function( function () { fwrite( STDERR, \"\\nOPTION=\" . json_encode( get_option( 'fishotel_snippets_enabled' ) ) . \"\\n\" ); } );\n"
	. "FisHotel\\Misc\\Sections\\Site_Snippets\\Admin::switch_snippet( 'compile', true );\n"
	. "echo \"\\nNOT REACHED\\n\";\n"
);
$child_out = array();
exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $child ) . ' 2>&1', $child_out, $child_code );
unlink( $child );
$child_out = implode( "\n", $child_out );
check( 'a compile-stage error: the fatal ends the switch request before the option is written', 0 !== $child_code && false === strpos( $child_out, 'NOT REACHED' ) && false !== strpos( $child_out, 'OPTION=[]' ), $child_code . ' ' . $child_out );
check( '… and the AJAX answer says why, as JSON', false !== strpos( $child_out, '{"success":false,"data":{"message":"Refused: the test run of compile hit a fatal error \u2014 Redefinition of parameter $a (line ' ) || false !== strpos( $child_out, '"Refused: the test run of compile hit a fatal error — Redefinition of parameter $a (line ' ), $child_out );

echo "9. Exists-now preflight\n";
$exists = fh_snippet_dir(
	array(
		'redeclare.php' => fh_php_snippet( 'Redeclare', "function wp_unslash( \$v ) { return \$v; }\n\$GLOBALS['fh_ran'][] = 'redeclare';", array( 'Default' => 'on' ) ),
		'guarded.php'   => fh_php_snippet( 'Guarded', "if ( ! function_exists( 'get_option' ) ) { function get_option() {} }\n\$GLOBALS['fh_ran'][] = 'guarded';", array( 'Default' => 'on' ) ),
		'if-only.php'   => fh_php_snippet( 'If only', "if ( ! is_admin() ) {\n\tfunction esc_html() {}\n}\n\$GLOBALS['fh_ran'][] = 'if-only';", array( 'Default' => 'on' ) ),
		'fresh.php'     => fh_php_snippet( 'Fresh', "function fh_fresh_name_xyz() {}\n\$GLOBALS['fh_ran'][] = 'fresh';", array( 'Default' => 'on' ) ),
	)
);
fh_request( $exists, false );
$state = Loader::state();
check( 'a snippet declaring an existing function is not included (no fatal), nor one declaring it inside a plain if; a guarded one runs', array( 'fresh', 'guarded' ) === $GLOBALS['fh_ran'], implode( ',', $GLOBALS['fh_ran'] ) );
check( 'the reasons are kept and logged as warnings', false !== strpos( $state['skipped']['redeclare'], 'function wp_unslash() already exists' ) && false !== strpos( $state['skipped']['if-only'], 'function esc_html() already exists' ) && 2 === count( fh_logged( 'warning' ) ) );

echo "10. The Code Snippets duplicate guard\n";
define( 'CODE_SNIPPETS_FILE', '/var/www/html/wp-content/plugins/code-snippets/code-snippets.php' );
$guard = fh_snippet_dir(
	array(
		'cs-twelve.php'   => fh_php_snippet( 'Twelve', "\$GLOBALS['fh_ran'][] = 'cs-twelve';", array( 'Origin' => 'Code Snippets #12, imported 2026-10-20', 'Default' => 'on' ) ),
		'cs-thirteen.php' => fh_php_snippet( 'Thirteen', "\$GLOBALS['fh_ran'][] = 'cs-thirteen';", array( 'Origin' => 'Code Snippets #13, imported 2026-10-20', 'Default' => 'on' ) ),
		'cs-off.php'      => fh_php_snippet( 'Off', "\$GLOBALS['fh_ran'][] = 'cs-off';", array( 'Origin' => 'Code Snippets #14' ) ),
		'hand.php'        => fh_php_snippet( 'By hand', "\$GLOBALS['fh_ran'][] = 'hand';", array( 'Default' => 'on' ) ),
		'cs-style.css'    => "/**\n * Snippet: CS style\n * Origin: Code Snippets #15\n * Default: on\n */\nbody{}\n",
		'cs-short.html'   => "<!--\nSnippet: CS short\nOrigin: Code Snippets #16\nDefault: on\n-->\nshort\n",
	)
);
$GLOBALS['wpdb']->active = array( 12 => 1, 13 => 0, 14 => 1, 15 => 1, 16 => 1 );
fh_request( $guard, false );
$held = Loader::held( Catalog::get() );
check( '#12 active in Code Snippets: ours waits; #13 inactive: ours runs', array( 'cs-thirteen', 'hand' ) === $GLOBALS['fh_ran'], implode( ',', $GLOBALS['fh_ran'] ) );
check( 'exactly one prepared query for the whole request, switched-on ids only', 1 === count( $GLOBALS['wpdb']->queries ) && false !== strpos( $GLOBALS['wpdb']->queries[0], 'id IN (12, 13, 15)' ), json_encode( $GLOBALS['wpdb']->queries ) );
check( 'held: #12 and the CSS from #15; never the shortcode (#16)', array( 'cs-style', 'cs-twelve' ) === array_keys( $held ) );
do_action( 'wp_enqueue_scripts' );
check( 'the held CSS is not printed', ! isset( $GLOBALS['fh_styles']['fishotel-snippets-front'] ) );
do_action( 'init' );
check( '[code_snippet] is left to Code Snippets while it is loaded', ! shortcode_exists( 'code_snippet' ) );
check( 'our [fh_snippet] still renders a Code Snippets import', "short" === do_shortcode( '[fh_snippet slug="cs-short"]' ) );
$r = Admin::switch_snippet( 'cs-off', true );
check( 'switching on an import whose original is active: allowed, with the warning', $r['ok'] && 'held' === $r['status']['key'] && false !== strpos( $r['message'], 'Code Snippets #14 is still active' ), $r['message'] );
ob_start();
( new Admin() )->notices();
check( 'no notice for a user without manage_options', '' === ob_get_clean() );
$GLOBALS['fh_can'] = true;
ob_start();
( new Admin() )->notices();
$notice = ob_get_clean();
check( 'an admin notice names the held snippets', false !== strpos( $notice, 'cs-twelve (Code Snippets #12 is still active' ) );
$GLOBALS['fh_can'] = false;
$GLOBALS['wpdb']->active[12] = 0;
fh_request( $guard, false );
check( 'once #12 is deactivated in Code Snippets, ours runs — equal priority in Code Snippets id order, the file without an id last', array( 'cs-twelve', 'cs-thirteen', 'hand' ) === $GLOBALS['fh_ran'], implode( ',', $GLOBALS['fh_ran'] ) );
$GLOBALS['wpdb']->broken = true;
fh_request( $guard, false );
check( 'the table cannot be read: every import waits (fail closed), the rest run', array( 'hand' ) === $GLOBALS['fh_ran'] );
$GLOBALS['wpdb']->broken = false;
fh_request( $exists, false );
check( 'Code Snippets active but no switched-on import: no query', array() === $GLOBALS['wpdb']->queries );

echo "11. The admin page renders\n";
$GLOBALS['fh_can'] = true;
$page_dir          = fh_snippet_dir(
	array(
		'cs-twelve.php' => fh_php_snippet( 'Twelve <b>bold</b>', '$x = 1;', array( 'Origin' => 'Code Snippets #12', 'Default' => 'on' ) ),
		'banner.html'   => "<!--\nSnippet: Banner\nOrigin: Code Snippets #31\nDefault: on\n-->\n<div>x</div>\n",
		'no-header.php' => "<?php\necho 1;\n",
		'clash-a.php'   => fh_php_snippet( 'Clash A', 'function fh_page_clash() {}' ),
		'clash-b.php'   => fh_php_snippet( 'Clash B', 'function fh_page_clash() {}' ),
	)
);
$GLOBALS['wpdb']->active = array( 12 => 1 );
fh_request( $page_dir, true );
ob_start();
( new Admin() )->render_page();
$page = ob_get_clean();
check( 'one row per usable snippet (clashing ones included) with its switch', 4 === substr_count( $page, 'data-snippet="' ) );
check( 'names are escaped', false !== strpos( $page, 'Twelve &lt;b&gt;bold&lt;/b&gt;' ) && false === strpos( $page, '<b>bold</b>' ) );
check( 'the held snippet says why', false !== strpos( $page, 'Waiting — Code Snippets #12 is still active' ) );
check( 'the broken file is listed with its reason', false !== strpos( $page, 'Broken files' ) && false !== strpos( $page, 'no-header.php' ) );
check( 'the clash is listed, its switches locked', false !== strpos( $page, 'fh_page_clash()' ) && 2 === substr_count( $page, " disabled='disabled'" ) );
check( 'the [code_snippet] mapping is shown', false !== strpos( $page, '[code_snippet id=31]' ) );
check( 'the code is shown read-only, escaped', false !== strpos( $page, '&lt;?php' ) );
$GLOBALS['fh_can'] = false;
$threw             = '';
try {
	( new Admin() )->render_page();
} catch ( RuntimeException $e ) {
	$threw = $e->getMessage();
}
check( 'refused without manage_options', 0 === strpos( $threw, 'wp_die:' ) );

echo "12. Safe mode by constant (last: it cannot be undone)\n";
define( 'FISHOTEL_SNIPPETS_SAFE_MODE', true );
fh_request( $dir, false );
check( 'nothing runs', array() === $GLOBALS['fh_ran'] && 'constant' === Loader::safe_mode()['reason'] );
check( 'no output hooks', false === has_action( 'wp_enqueue_scripts' ) && false === has_action( 'wp_footer' ) );
$GLOBALS['fh_can'] = true;
ob_start();
( new Admin() )->notices();
$notice = ob_get_clean();
check( 'the admin notice says so', false !== strpos( $notice, 'safe mode is on' ) );

Catalog::use_dir( null );
fh_finish();
