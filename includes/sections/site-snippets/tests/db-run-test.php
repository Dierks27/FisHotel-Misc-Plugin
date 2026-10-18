<?php
/**
 * Database snippets at run time — whole requests with snippets written in
 * wp-admin beside invented files: the eval() runner (locals local, output
 * discarded, a throw caught with the stored code's line), the merged order
 * with the files, scopes, superseded by a GitHub file, a clash with a file
 * deployed later, the exists-now preflight, what an ordinary request costs
 * (no query, no write; nothing changes without database snippets), CSS /
 * JS / HTML printing and [fh_snippet], safe mode, the editor's own save
 * request not running the snippet it saves, the real test run on save, and
 * — in child PHP processes — a compile-stage fatal on save that leaves no
 * row (then logged as test_failed from the kept draft) and on the AJAX
 * switch (answered as JSON). Safe mode by constant last.
 *
 * Run from the plugin root:
 *   php includes/sections/site-snippets/tests/db-run-test.php
 *
 * @package FisHotel\Misc\Sections\Site_Snippets
 */

require_once __DIR__ . '/wp-stubs.php';
require_once __DIR__ . '/fake-db-store.php';

use FisHotel\Misc\Sections\Site_Snippets\Catalog;
use FisHotel\Misc\Sections\Site_Snippets\Db_Snippets;
use FisHotel\Misc\Sections\Site_Snippets\Editor;
use FisHotel\Misc\Sections\Site_Snippets\Loader;
use FisHotel\Misc\Sections\Site_Snippets\Output;
use FisHotel\Misc\Sections\Site_Snippets\Runner;
use FisHotel\Misc\Sections\Site_Snippets\Tests\Fake_Db_Store;

/**
 * One request (see loader-test.php).
 */
function fh_request( $dir, $is_admin = false ) {
	fh_reset_hooks();
	Loader::reset();
	Catalog::use_dir( $dir );
	$GLOBALS['fh_is_admin']   = $is_admin;
	$GLOBALS['fh_ran']        = array();
	$GLOBALS['wpdb']->queries = array();
	$GLOBALS['fh_writes']     = array();

	Loader::hook();
	add_action( 'plugins_loaded', array( Output::class, 'init' ), 10 );

	ob_start();
	do_action( 'plugins_loaded' );
	return ob_get_clean();
}

/**
 * Store rows as the editor would, and the active option built from them.
 */
function fh_db( array $rows ) {
	$full = array();
	$id   = 100;
	foreach ( $rows as $slug => $row ) {
		$full[] = array_merge(
			array(
				'id'       => ++$id,
				'slug'     => $slug,
				'type'     => 'php',
				'scope'    => 'global',
				'priority' => 10,
				'active'   => 1,
				'version'  => 1,
			),
			$row
		);
	}
	$GLOBALS['fh_options'][ Db_Snippets::OPTION_ACTIVE ] = Db_Snippets::active_option( $full )['map'];
	return $GLOBALS['fh_options'][ Db_Snippets::OPTION_ACTIVE ];
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
		'file-a.php'    => fh_php_snippet( 'File A', "\$GLOBALS['fh_ran'][] = 'file-a';", array( 'Default' => 'on' ) ),
		'file-late.php' => fh_php_snippet( 'File late', "\$GLOBALS['fh_ran'][] = 'file-late';", array( 'Priority' => '20', 'Default' => 'on' ) ),
		'moved.php'     => fh_php_snippet( 'Moved to GitHub', "\$GLOBALS['fh_ran'][] = 'moved-file';", array( 'Default' => 'on' ) ),
		// Off: its function would otherwise exist from the first simulated request on.
		'declares.php'  => fh_php_snippet( 'Declares', "function fh_file_declared_xyz() {}\n\$GLOBALS['fh_ran'][] = 'declares';" ),
		'style.css'     => "/**\n * Snippet: File style\n * Default: on\n */\nbody { color: teal; }\n",
		'banner.html'   => "<!--\nSnippet: File banner\nDefault: on\n-->\n<div>file banner</div>\n",
	)
);

echo "1. The runner eval()s stored code\n";
$GLOBALS['fh_ran'] = array();
$errors            = array();
$level             = ob_get_level();
ob_start();
$results = Runner::run(
	array(
		array( 'slug' => 'db:one', 'code' => "echo 'printed';\n\$local_one = 1;\n\$GLOBALS['fh_ran'][] = 'one';\n\$GLOBALS['fh_vars'] = array_keys( get_defined_vars() );" ),
		array( 'slug' => 'db:throws', 'code' => "\n\nthrow new \\DomainException( 'db boom' );" ),
		array( 'slug' => 'db:parse', 'code' => 'function ( {' ),
		array( 'slug' => 'db:returns', 'code' => "\$GLOBALS['fh_ran'][] = 'returns';\nreturn;\n\$GLOBALS['fh_ran'][] = 'never';" ),
		array( 'slug' => 'db:empty', 'code' => '' ),
	),
	static function ( $slug, $e ) use ( &$errors ) {
		$errors[ $slug ] = $e;
	}
);
$printed = ob_get_clean();
check( 'stored code runs; a throw and a ParseError are caught, the next one still runs', array( 'db:one' => 'ok', 'db:throws' => 'error', 'db:parse' => 'error', 'db:returns' => 'ok', 'db:empty' => 'ok' ) === $results && array( 'one', 'returns' ) === $GLOBALS['fh_ran'], json_encode( $results ) );
check( 'its output is discarded, its variables stay local (it sees only its own)', '' === $printed && $level === ob_get_level() && array( 'local_one' ) === $GLOBALS['fh_vars'] && ! isset( $GLOBALS['local_one'] ), json_encode( $GLOBALS['fh_vars'] ) );
check( 'the exception carries the stored code\'s line', 3 === $errors['db:throws']->getLine() && false !== strpos( $errors['db:throws']->getFile(), "eval()'d code" ) );

echo "2. A request with database snippets\n";
fh_db(
	array(
		'db-first'  => array( 'priority' => 1, 'code' => "\$GLOBALS['fh_ran'][] = 'db-first';" ),
		'db-same'   => array( 'code' => "\$GLOBALS['fh_ran'][] = 'db-same';" ),
		'db-admin'  => array( 'scope' => 'admin', 'code' => "\$GLOBALS['fh_ran'][] = 'db-admin';" ),
		'db-front'  => array( 'scope' => 'front-end', 'code' => "\$GLOBALS['fh_ran'][] = 'db-front';" ),
		'db-throws' => array( 'priority' => 15, 'code' => "\$GLOBALS['fh_ran'][] = 'db-throws';\n\nthrow new \\LogicException( 'stored boom' );" ),
		'db-after'  => array( 'priority' => 15, 'code' => "\$GLOBALS['fh_ran'][] = 'db-after';" ),
		'moved'     => array( 'code' => "\$GLOBALS['fh_ran'][] = 'moved-db';" ),
		'db-clash'  => array( 'code' => "function fh_file_declared_xyz() {}\n\$GLOBALS['fh_ran'][] = 'db-clash';" ),
		'db-exists' => array( 'code' => "function wp_unslash( \$v ) { return \$v; }\n\$GLOBALS['fh_ran'][] = 'db-exists';" ),
		'db-css'    => array( 'type' => 'css', 'scope' => 'front-end', 'code' => "p { margin: 0; }\n" ),
		'db-js'     => array( 'type' => 'js', 'scope' => 'head', 'code' => "window.fhDb = 1;\n" ),
		'db-foot'   => array( 'type' => 'html', 'scope' => 'footer', 'code' => "<i>db footer</i>\n" ),
		'db-banner' => array( 'type' => 'html', 'scope' => 'shortcode', 'code' => "<b>db banner</b>\n" ),
		'banner'    => array( 'type' => 'html', 'scope' => 'shortcode', 'code' => "<b>db copy of banner</b>\n" ),
	)
);
fh_request( $dir, false );
$state = Loader::state();
check( 'merged order at plugins_loaded:1: priority, files before database snippets, slug; front-end scope here, admin not', array( 'db-first', 'file-a', 'moved-file', 'db-front', 'db-same', 'db-after', 'db-throws', 'file-late' ) === $GLOBALS['fh_ran'], implode( ',', $GLOBALS['fh_ran'] ) );
check( 'superseded by the GitHub file: the database copy never runs, the file does', ! in_array( 'moved-db', $GLOBALS['fh_ran'], true ) && in_array( 'moved-file', $GLOBALS['fh_ran'], true ) );
check( 'a clash with a file (switched on or not): the database snippet is refused, the file untouched', ! in_array( 'db-clash', $GLOBALS['fh_ran'], true ) && false !== strpos( Loader::snippets( Catalog::get() )['db:db-clash']['refused'], 'also by the file declares.php' ) && '' === Catalog::get()['snippets']['declares']['refused'] );
check( 'a name that already exists: not run (no fatal), the reason kept under db:<slug>', ! in_array( 'db-exists', $GLOBALS['fh_ran'], true ) && false !== strpos( $state['skipped']['db:db-exists'], 'function wp_unslash() already exists' ) );
check( 'the skip names the database snippet in the Health log', 1 === count( fh_logged( 'warning' ) ) && 0 === strpos( fh_logged( 'warning' )[0][2], 'Snippet database snippet db-exists was not loaded' ) );
check( 'a throw: recorded under db:<slug> with its stored code\'s line, the next one still ran', false !== strpos( $state['errors']['db:db-throws'], 'Snippet db:db-throws threw LogicException: stored boom in its stored code, line 3' ) && 'ok' === $state['results']['db:db-after'], json_encode( $state['errors'] ) );
$logged = fh_logged( 'error' );
check( 'handed to fishotel_log and stored for the page (not autoloaded) under db:<slug>', 1 === count( $logged ) && 'db:db-throws' === $logged[0][3]['snippet'] && isset( Loader::stored_errors()['db:db-throws'] ) && false === $GLOBALS['fh_autoload'][ Loader::OPTION_ERRORS ] );
check( 'no query', array() === $GLOBALS['wpdb']->queries );

echo "3. What an ordinary request costs\n";
fh_request( $dir, false );
check( 'the second request: no query, no option write', array() === $GLOBALS['wpdb']->queries && array() === $GLOBALS['fh_writes'], implode( ',', $GLOBALS['fh_writes'] ) );
fh_request( $dir, true );
check( 'admin: global + admin scopes', in_array( 'db-admin', $GLOBALS['fh_ran'], true ) && ! in_array( 'db-front', $GLOBALS['fh_ran'], true ) );
$index = Catalog::get();
check( 'merged map memoised for the request', Loader::snippets( $index ) === Loader::snippets( $index ) && isset( Loader::snippets( $index )['db:db-first'] ) );
$saved = $GLOBALS['fh_options'][ Db_Snippets::OPTION_ACTIVE ];
$GLOBALS['fh_options'][ Db_Snippets::OPTION_ACTIVE ] = array();
fh_request( $dir, false );
check( 'no database snippet: the files run exactly as before, the map is the index\'s own', array( 'file-a', 'moved-file', 'file-late' ) === $GLOBALS['fh_ran'] && Catalog::get()['snippets'] === Loader::snippets( Catalog::get() ) );
$GLOBALS['fh_options'][ Db_Snippets::OPTION_ACTIVE ] = $saved;

echo "4. CSS / JS / HTML and [fh_snippet]\n";
fh_request( $dir, false );
do_action( 'wp_enqueue_scripts' );
check( 'database CSS after the file CSS on the same handle, labelled db:<slug>', array( "/* fh-snippet: style */\nbody { color: teal; }\n", "/* fh-snippet: db:db-css */\np { margin: 0; }\n" ) === $GLOBALS['fh_styles']['fishotel-snippets-front']['inline'], json_encode( $GLOBALS['fh_styles']['fishotel-snippets-front']['inline'] ) );
check( 'database JS in the head', array( "/* fh-snippet: db:db-js */\nwindow.fhDb = 1;\n" ) === $GLOBALS['fh_scripts']['fishotel-snippets-head']['inline'] );
ob_start();
do_action( 'wp_footer' );
check( 'database footer HTML echoed', "\n<i>db footer</i>\n\n" === ob_get_clean() );
check( '[fh_snippet] renders a database shortcode snippet', '<b>db banner</b>' === do_shortcode( '[fh_snippet slug="db-banner"]' ) );
check( '… but a file of the same slug wins (the copy is superseded)', '<div>file banner</div>' === do_shortcode( '[fh_snippet slug="banner"]' ) );

echo "5. Safe mode by query skips database snippets too\n";
$_GET[ Loader::SAFE_MODE_QUERY ] = '1';
fh_login( 1, 'admin', true );
fh_request( $dir, true );
check( 'nothing runs, nothing prints, nothing renders', array() === $GLOBALS['fh_ran'] && false === has_action( 'admin_enqueue_scripts' ) && '' === do_shortcode( '[fh_snippet slug="db-banner"]' ) );
unset( $_GET[ Loader::SAFE_MODE_QUERY ] );
fh_login( 0, '', false );

echo "6. The editor's own save request does not run the snippet it saves\n";
define( 'FISHOTEL_SNIPPETS_EDITORS', 'jeff' );
check( 'the loader knows the editor\'s save / restore actions', array( Editor::SAVE_ACTION, Editor::RESTORE_ACTION ) === Loader::EDIT_ACTIONS );
$same_id                    = $GLOBALS['fh_options'][ Db_Snippets::OPTION_ACTIVE ]['db-same']['id'];
$_SERVER['REQUEST_METHOD']  = 'POST';
$_POST                      = array(
	'action'           => Editor::SAVE_ACTION,
	'snippet_id'       => (string) $same_id,
	Editor::NONCE_FIELD => wp_create_nonce( Editor::NONCE ),
);
fh_login( 7, 'jeff', true );
fh_request( $dir, true );
check( 'an editor saving db-same (valid nonce, admin-post): db-same is not run at plugins_loaded:1, the rest is', ! in_array( 'db-same', $GLOBALS['fh_ran'], true ) && in_array( 'db-first', $GLOBALS['fh_ran'], true ) && 'db:db-same' === Loader::state()['editing'] );
$_POST['action'] = Editor::RESTORE_ACTION;
fh_request( $dir, true );
check( '… the same for a restore from history', ! in_array( 'db-same', $GLOBALS['fh_ran'], true ) );
$_POST['action'] = Editor::SAVE_ACTION;
$_POST[ Editor::NONCE_FIELD ] = 'forged';
fh_request( $dir, true );
check( 'a bad nonce: it runs as usual', in_array( 'db-same', $GLOBALS['fh_ran'], true ) );
$_POST[ Editor::NONCE_FIELD ] = wp_create_nonce( Editor::NONCE );
fh_login( 8, 'anna', true );
fh_request( $dir, true );
check( 'an administrator not named in FISHOTEL_SNIPPETS_EDITORS: it runs as usual', in_array( 'db-same', $GLOBALS['fh_ran'], true ) );
fh_login( 7, 'jeff', true );
fh_request( $dir, false );
check( 'not an admin request: it runs', in_array( 'db-same', $GLOBALS['fh_ran'], true ) );
$_SERVER['REQUEST_METHOD'] = 'GET';
fh_request( $dir, true );
check( 'not a POST: it runs', in_array( 'db-same', $GLOBALS['fh_ran'], true ) );
$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST['action']           = 'something_else';
fh_request( $dir, true );
check( 'another action: it runs, and the editor classes are not even needed', in_array( 'db-same', $GLOBALS['fh_ran'], true ) && '' === Loader::state()['editing'] );
$_POST = array();
unset( $_SERVER['REQUEST_METHOD'] );

echo "7. The real test run on save\n";
fh_request( $dir, true );
$store  = new Fake_Db_Store();
$editor = new Editor(
	$store,
	array(
		'files'        => static function () {
			return Catalog::get( null, true );
		},
		'test_run'     => array( Loader::class, 'test_run' ),
		'actor'        => array( 'user_id' => 7, 'user_login' => 'jeff' ),
		'emit'         => static function ( $level, $message, $context ) {
			do_action( 'fishotel_log', $level, Loader::LOG_SOURCE, $message, $context );
		},
		'write_active' => static function ( array $map ) {
			update_option( Db_Snippets::OPTION_ACTIVE, $map, true );
		},
	)
);
$GLOBALS['fh_ran'] = array();
$r = $editor->save( array( 'slug' => 'fresh', 'name' => 'Fresh', 'type' => 'php', 'scope' => 'global', 'active' => '1', 'code' => "function fh_fresh_db_xyz() {}\n\$GLOBALS['fh_ran'][] = 'fresh';" ) );
check( 'a good snippet: run once in the save request, then stored, autoloaded', $r['ok'] && array( 'fresh' ) === $GLOBALS['fh_ran'] && function_exists( 'fh_fresh_db_xyz' ) && isset( get_option( Db_Snippets::OPTION_ACTIVE )['fresh'] ) && true === $GLOBALS['fh_autoload'][ Db_Snippets::OPTION_ACTIVE ], $r['message'] );
$r = $editor->save( array( 'slug' => 'thrower', 'name' => 'Thrower', 'type' => 'php', 'scope' => 'global', 'active' => '1', 'code' => "\n\nthrow new \\RuntimeException( 'save-time boom' );" ) );
check( 'a snippet that throws: refused with the exception and its line, nothing stored but the test_failed row', false === $r['ok'] && false !== strpos( $r['message'], 'RuntimeException: save-time boom (line 3)' ) && null === $store->get_by_slug( 'thrower' ) && 'test_failed' === $store->log_rows( array(), 1 )[0]['action'], $r['message'] );
check( '… and the warning reached the Health log', 1 <= count( fh_logged( 'warning' ) ) && false !== strpos( end( $GLOBALS['fh_actions'] )[1][2], 'Snippet editor: test_failed thrower by jeff' ) );
$r = $editor->save( array( 'slug' => 'redeclares', 'name' => 'Redeclares', 'type' => 'php', 'scope' => 'global', 'active' => '1', 'code' => 'function esc_attr( $t ) { return $t; }' ) );
check( 'a function another plugin already declared: refused before it runs (no fatal)', false === $r['ok'] && false !== strpos( $r['message'], 'function esc_attr() already exists' ), $r['message'] );
$r = $editor->save( array( 'slug' => 'quiet-off', 'name' => 'Quiet', 'type' => 'php', 'scope' => 'global', 'active' => '', 'code' => "throw new \\RuntimeException( 'never run' );" ) );
check( 'saved off: not run (so code that would throw is kept), not in the active option', $r['ok'] && false === strpos( $r['message'], 'test run' ) && ! isset( get_option( Db_Snippets::OPTION_ACTIVE )['quiet-off'] ) && 0 === $store->get_by_slug( 'quiet-off' )['active'], $r['message'] );

echo "8. A compile-stage fatal in a save's test run (child processes)\n";
$tmp     = sys_get_temp_dir() . '/fh-db-child-' . bin2hex( random_bytes( 4 ) );
$persist = $tmp . '-store.json';
$draft   = $tmp . '-draft.json';
$active  = $tmp . '-active.json';
$child   = $tmp . '.php';
$script  = "<?php\nrequire " . var_export( __DIR__ . '/wp-stubs.php', true ) . ";\nrequire " . var_export( __DIR__ . '/fake-db-store.php', true ) . ";\n"
	. "\$GLOBALS['fh_doing_ajax'] = getenv( 'FH_AJAX' ) === '1';\n"
	. "register_shutdown_function( function () { \$e = error_get_last(); fwrite( STDERR, \"\\nFATAL=\" . ( \$e ? \$e['message'] : '' ) . \"\\n\" ); } );\n"
	. "\$store = new FisHotel\\Misc\\Sections\\Site_Snippets\\Tests\\Fake_Db_Store();\n"
	. "\$store->persist = " . var_export( $persist, true ) . ";\n"
	. "if ( is_file( \$store->persist ) ) { \$st = json_decode( file_get_contents( \$store->persist ), true ); foreach ( \$st['snippets'] as \$r ) { \$store->snippets[ \$r['id'] ] = \$r; } \$store->next = \$st['next']; }\n"
	. "\$editor = new FisHotel\\Misc\\Sections\\Site_Snippets\\Editor( \$store, array(\n"
	. "\t'files' => function () { return array( 'snippets' => array(), 'broken' => array() ); },\n"
	. "\t'test_run' => array( 'FisHotel\\\\Misc\\\\Sections\\\\Site_Snippets\\\\Loader', 'test_run' ),\n"
	. "\t'actor' => array( 'user_id' => 7, 'user_login' => 'jeff' ),\n"
	. "\t'write_active' => function ( \$map ) { file_put_contents( " . var_export( $active, true ) . ", json_encode( \$map ) ); },\n"
	. "\t'before_test' => function ( \$fields, \$id ) { file_put_contents( " . var_export( $draft, true ) . ", json_encode( array( 'id' => \$id, 'fields' => \$fields, 'state' => 'testing' ) ) ); },\n"
	. ") );\n"
	. "if ( getenv( 'FH_AJAX' ) === '1' ) { \$editor->set_active( (int) getenv( 'FH_ID' ), true ); } else { \$editor->save( array( 'slug' => 'compile', 'name' => 'Compile error', 'type' => 'php', 'scope' => 'global', 'active' => '1', 'code' => 'function fh_two( \$a, \$a ) { return \$a; }' ) ); }\n"
	. "echo \"\\nNOT REACHED\\n\";\n";
file_put_contents( $child, $script );
exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $child ) . ' 2>&1', $out, $code );
$out = implode( "\n", $out );
check( 'the fatal ends the save request', 0 !== $code && false === strpos( $out, 'NOT REACHED' ) && false !== strpos( $out, 'Redefinition of parameter $a' ), $code . ' ' . $out );
$state = is_file( $persist ) ? json_decode( (string) file_get_contents( $persist ), true ) : array( 'snippets' => array(), 'revisions' => array(), 'log' => array() );
check( '… before any row, revision or log row was written, and before the active option', array() === $state['snippets'] && array() === $state['revisions'] && array() === $state['log'] && ! is_file( $active ) );
$kept = is_file( $draft ) ? json_decode( (string) file_get_contents( $draft ), true ) : array();
check( '… the typed code was kept as a draft first (never lose his work)', isset( $kept['fields']['code'] ) && 'function fh_two( $a, $a ) { return $a; }' === $kept['fields']['code'] && 'testing' === $kept['state'] );
$after = new Fake_Db_Store();
$msg   = ( new Editor( $after, array( 'actor' => array( 'user_id' => 7, 'user_login' => 'jeff' ) ) ) )->settle_dead_test( $kept );
check( 'the next page load logs it as test_failed from the draft', array( 'test_failed' ) === $after->actions() && 'compile' === array_values( $after->log )[0]['slug'] && 0 === strpos( $msg, 'Refused: the test run ended the save request' ) );

// The AJAX switch: an off snippet with that code, switched on.
file_put_contents(
	$persist,
	json_encode(
		array(
			'snippets'  => array(
				array( 'id' => 5, 'slug' => 'compile-off', 'name' => 'Off', 'description' => '', 'type' => 'php', 'scope' => 'global', 'priority' => 10, 'active' => 0, 'code' => 'function fh_three( $b, $b ) {}', 'version' => 1, 'created_by' => 7, 'created_at' => '', 'updated_by' => 7, 'updated_at' => '', 'exported_at' => null ),
			),
			'revisions' => array(),
			'log'       => array(),
			'next'      => array( 'snippets' => 6, 'revisions' => 1, 'log' => 1 ),
		)
	)
);
$out = array();
exec( 'FH_AJAX=1 FH_ID=5 ' . escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $child ) . ' 2>&1', $out, $code );
$out   = implode( "\n", $out );
$state = json_decode( (string) file_get_contents( $persist ), true );
check( 'the AJAX switch: the fatal ends the request, answered as JSON naming the database snippet', 0 !== $code && false === strpos( $out, 'NOT REACHED' ) && false !== strpos( $out, '"success":false' ) && false !== strpos( $out, 'the test run of database snippet compile-off hit a fatal error' ), $out );
check( '… and it stays off, nothing logged as switched on', 0 === (int) $state['snippets'][0]['active'] && array() === $state['log'] && ! is_file( $active ) );
foreach ( array( $persist, $draft, $active, $child ) as $f ) {
	if ( is_file( $f ) ) {
		unlink( $f );
	}
}

echo "9. Safe mode by constant (last: it cannot be undone)\n";
define( 'FISHOTEL_SNIPPETS_SAFE_MODE', true );
fh_request( $dir, false );
check( 'no database snippet runs or prints', array() === $GLOBALS['fh_ran'] && false === has_action( 'wp_enqueue_scripts' ) && false === has_action( 'wp_footer' ) );

Catalog::use_dir( null );
fh_finish();
