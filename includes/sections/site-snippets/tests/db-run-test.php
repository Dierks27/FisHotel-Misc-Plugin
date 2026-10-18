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
require_once dirname( __DIR__ ) . '/class-admin-editor.php';

use FisHotel\Misc\Sections\Site_Snippets\Admin_Editor;
use FisHotel\Misc\Sections\Site_Snippets\Catalog;
use FisHotel\Misc\Sections\Site_Snippets\Db_Snippets;
use FisHotel\Misc\Sections\Site_Snippets\Db_Store;
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

echo "3b. Only what the editor signed runs\n";
$saved  = $GLOBALS['fh_options'][ Db_Snippets::OPTION_ACTIVE ];
$forged = $saved;
// Any administrator can write any option through wp-admin/options.php.
$forged['x']                = array( 'slug' => 'x', 'type' => 'php', 'scope' => 'global', 'code' => "\$GLOBALS['fh_ran'][] = 'injected via option';" );
$forged['db-first']['code'] = "\$GLOBALS['fh_ran'][] = 'tampered';";
$GLOBALS['fh_options'][ Db_Snippets::OPTION_ACTIVE ] = $forged;
fh_request( $dir, false );
check( 'an entry written straight into the option, or a signed one with its code changed: never run; the others are', ! in_array( 'injected via option', $GLOBALS['fh_ran'], true ) && ! in_array( 'tampered', $GLOBALS['fh_ran'], true ) && ! in_array( 'db-first', $GLOBALS['fh_ran'], true ) && in_array( 'db-same', $GLOBALS['fh_ran'], true ) && in_array( 'file-a', $GLOBALS['fh_ran'], true ) );
$GLOBALS['fh_options'][ Db_Snippets::OPTION_ACTIVE ] = $saved;
$GLOBALS['fh_salt']                                  = 'rotated-salts';
fh_request( $dir, false );
do_action( 'wp_enqueue_scripts' );
check( 'new salts: no database snippet runs or prints until the option is rebuilt; the files do', array( 'file-a', 'moved-file', 'file-late' ) === $GLOBALS['fh_ran'] && array( "/* fh-snippet: style */\nbody { color: teal; }\n" ) === $GLOBALS['fh_styles']['fishotel-snippets-front']['inline'] );
unset( $GLOBALS['fh_salt'] );

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

echo "6. The editor's own save request runs the code it saves in the snippet's place\n";
define( 'FISHOTEL_SNIPPETS_EDITORS', 'jeff' );
check( 'the loader knows the editor\'s save / restore actions', array( Editor::SAVE_ACTION, Editor::RESTORE_ACTION ) === Loader::EDIT_ACTIONS );
update_option( Db_Store::DB_VERSION_OPTION, Db_Store::DB_VERSION, true );
Admin_Editor::$halt = static function () {
	throw new FH_Halt( 'exit' );
};

/**
 * A store holding these active-option entries as rows (with version 1 as
 * their first revision), used by the editor's handlers.
 */
function fh_table( array $active ) {
	$store = new Fake_Db_Store();
	foreach ( $active as $entry ) {
		$store->insert( array( 'id' => $entry['id'], 'slug' => $entry['slug'], 'name' => ucfirst( $entry['slug'] ), 'description' => '', 'type' => $entry['type'], 'scope' => $entry['scope'], 'priority' => $entry['priority'], 'active' => 1, 'code' => $entry['code'], 'version' => $entry['version'], 'created_by' => 7, 'created_at' => '', 'updated_by' => 7, 'updated_at' => '' ) );
		$store->add_revision( array( 'snippet_id' => $entry['id'], 'version' => $entry['version'], 'code' => $entry['code'], 'meta' => array( 'name' => ucfirst( $entry['slug'] ), 'type' => $entry['type'], 'scope' => $entry['scope'], 'priority' => $entry['priority'], 'active' => 1 ), 'user_id' => 7, 'created_at' => '' ) );
	}
	Admin_Editor::$store = $store;
	return $store;
}

/** The edit form posted for a snippet. */
function fh_save_post( $id, array $over = array() ) {
	return array_merge(
		array(
			'action'            => Editor::SAVE_ACTION,
			'snippet_id'        => (string) $id,
			Editor::NONCE_FIELD => wp_create_nonce( Editor::NONCE ),
			'base_version'      => '1',
			'name'              => 'Db-same',
			'type'              => 'php',
			'scope'             => 'global',
			'priority'          => '10',
			'active'            => '1',
			'code'              => '',
		),
		$over
	);
}

/** Run an admin-post handler (it ends with a redirect). */
function fh_handler( $method ) {
	try {
		( new Admin_Editor() )->$method();
	} catch ( FH_Halt $e ) {
		return $e;
	}
	return null;
}

$store   = fh_table( $GLOBALS['fh_options'][ Db_Snippets::OPTION_ACTIVE ] );
$same_id = $GLOBALS['fh_options'][ Db_Snippets::OPTION_ACTIVE ]['db-same']['id'];
$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST                     = fh_save_post( $same_id, array( 'code' => "\$GLOBALS['fh_ran'][] = 'db-same-new';" ) );
fh_login( 7, 'jeff', true );
fh_request( $dir, true );
check( 'saving db-same (a named editor, a valid nonce): at plugins_loaded:1 its NEW code runs in its place, the old code does not', array( 'db-first', 'file-a', 'moved-file', 'db-admin', 'db-same-new', 'db-after', 'db-throws', 'file-late' ) === $GLOBALS['fh_ran'] && 'db:db-same' === Loader::state()['editing'], implode( ',', $GLOBALS['fh_ran'] ) );
$draft = Admin_Editor::draft( 7, $same_id );
check( '… after his draft was kept ("testing", the typed code, the version it is for)', is_array( $draft ) && 'testing' === $draft['state'] && "\$GLOBALS['fh_ran'][] = 'db-same-new';" === $draft['fields']['code'] && 2 === $draft['version'] );
$GLOBALS['fh_ran'] = array();
$halt              = fh_handler( 'handle_save' );
check( 'the handler then saves it as version 2, taking that run as its test run (not run twice), and drops the draft', $halt instanceof FH_Halt && 2 === $store->get( $same_id )['version'] && array() === $GLOBALS['fh_ran'] && null === Admin_Editor::draft( 7, $same_id ) && "\$GLOBALS['fh_ran'][] = 'db-same-new';" === get_option( Db_Snippets::OPTION_ACTIVE )['db-same']['code'] );

$_POST = fh_save_post( $same_id, array( 'base_version' => '2', 'code' => "\$GLOBALS['fh_ran'][] = 'db-same-throws';\nthrow new \\LogicException( 'posted boom' );" ) );
fh_request( $dir, true );
check( 'new code that throws: it ran in place, its throw is the save\'s test result — not the site\'s error (not stored, not in the Health log)', in_array( 'db-same-throws', $GLOBALS['fh_ran'], true ) && ! isset( Loader::state()['errors']['db:db-same'] ) && ! isset( Loader::stored_errors()['db:db-same'] ) && 1 === count( fh_logged( 'error' ) ) && 'ok' === Loader::state()['results']['db:db-after'] );
$GLOBALS['fh_ran'] = array();
fh_handler( 'handle_save' );
check( '… the handler refuses the save with it (test_failed), without running it again; his code is kept', 2 === $store->get( $same_id )['version'] && array() === $GLOBALS['fh_ran'] && 'test_failed' === $store->log_rows( array(), 1 )[0]['action'] && false !== strpos( Admin_Editor::draft( 7, $same_id )['message'], 'LogicException: posted boom (line 2)' ) );

$_POST = fh_save_post( $same_id, array( 'base_version' => '2', 'code' => '$broken = ;' ) );
fh_request( $dir, true );
check( 'new code with a syntax error (the save will refuse it before any test run): the old code runs as usual', in_array( 'db-same-new', $GLOBALS['fh_ran'], true ) && 'refused' === Admin_Editor::draft( 7, $same_id )['state'] );
$_POST = fh_save_post( $same_id, array( 'base_version' => '2', 'code' => "function esc_html( \$t ) { return \$t; }\n\$GLOBALS['fh_ran'][] = 'db-same-esc';" ) );
fh_request( $dir, true );
check( 'new code declaring a function another plugin already has: the old code keeps running, the new one never does', in_array( 'db-same-new', $GLOBALS['fh_ran'], true ) && ! in_array( 'db-same-esc', $GLOBALS['fh_ran'], true ) );
fh_handler( 'handle_save' );
check( '… and the handler refuses the save with that reason', 2 === $store->get( $same_id )['version'] && false !== strpos( Admin_Editor::draft( 7, $same_id )['message'], 'function esc_html() already exists' ) );
$_POST = fh_save_post( $same_id, array( 'base_version' => '2', 'name' => '', 'code' => '$x = 1;' ) );
fh_request( $dir, true );
check( 'a form the save will refuse (no name): the old code runs as usual', in_array( 'db-same-new', $GLOBALS['fh_ran'], true ) );
$_POST = fh_save_post( $same_id, array( 'base_version' => '2', 'active' => '', 'code' => '$x = 1;' ) );
fh_request( $dir, true );
check( 'saved switched off (no test run): the old code runs for the rest of this request', in_array( 'db-same-new', $GLOBALS['fh_ran'], true ) );
Admin_Editor::drop_draft( 7, $same_id );

$_POST = array(
	'action'            => Editor::RESTORE_ACTION,
	'snippet_id'        => (string) $same_id,
	'revision_id'       => (string) $store->revisions( $same_id )[1]['id'],
	'base_version'      => '2',
	Editor::NONCE_FIELD => wp_create_nonce( Editor::NONCE ),
);
fh_request( $dir, true );
check( 'a restore from history: version 1\'s code runs in its place at plugins_loaded:1', in_array( 'db-same', $GLOBALS['fh_ran'], true ) && ! in_array( 'db-same-new', $GLOBALS['fh_ran'], true ) && 'testing' === Admin_Editor::draft( 7, $same_id )['state'] );
$GLOBALS['fh_ran'] = array();
fh_handler( 'handle_restore' );
check( '… the handler restores it as version 3 without running it again, and drops its marker', 3 === $store->get( $same_id )['version'] && "\$GLOBALS['fh_ran'][] = 'db-same';" === $store->get( $same_id )['code'] && array() === $GLOBALS['fh_ran'] && null === Admin_Editor::draft( 7, $same_id ) );

$gates = array(
	'a bad nonce'                                            => array( array( Editor::NONCE_FIELD => 'forged' ), 7, 'jeff', true, 'POST' ),
	'an administrator not named in FISHOTEL_SNIPPETS_EDITORS' => array( array(), 8, 'anna', true, 'POST' ),
	'not an admin request'                                   => array( array(), 7, 'jeff', false, 'POST' ),
	'not a POST'                                             => array( array(), 7, 'jeff', true, 'GET' ),
	'another action'                                         => array( array( 'action' => 'something_else' ), 7, 'jeff', true, 'POST' ),
);
foreach ( $gates as $label => $gate ) {
	$_POST                     = fh_save_post( $same_id, array_merge( array( 'base_version' => '3', 'code' => "\$GLOBALS['fh_ran'][] = 'posted';" ), $gate[0] ) );
	$_SERVER['REQUEST_METHOD'] = $gate[4];
	fh_login( $gate[1], $gate[2], true );
	fh_request( $dir, $gate[3] );
	check( $label . ': the stored code runs, the posted code never does', in_array( 'db-same', $GLOBALS['fh_ran'], true ) && ! in_array( 'posted', $GLOBALS['fh_ran'], true ) && null === Admin_Editor::draft( 7, $same_id ) );
}
$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST                     = fh_save_post( $same_id, array( 'base_version' => '3', 'code' => "\$GLOBALS['fh_ran'][] = 'posted';" ) );
fh_login( 7, 'jeff', true );
$GLOBALS['fh_doing_ajax'] = true;
fh_request( $dir, true );
$GLOBALS['fh_doing_ajax'] = false;
check( 'the save action sent to admin-ajax.php (no handler there): the stored code runs', in_array( 'db-same', $GLOBALS['fh_ran'], true ) && ! in_array( 'posted', $GLOBALS['fh_ran'], true ) && '' === Loader::state()['editing'] );

// The reviewer's case: a file calls a database snippet's function on init.
$dep_dir = fh_snippet_dir( array( 'uses-helper.php' => fh_php_snippet( 'Uses helper', "add_action( 'init', function () { \$GLOBALS['fh_note'] = fh_price_note_db(); } );", array( 'Default' => 'on' ) ) ) );
$saved   = $GLOBALS['fh_options'][ Db_Snippets::OPTION_ACTIVE ];
$helpers = fh_db( array( 'a-helpers' => array( 'priority' => 5, 'code' => "function fh_price_note_db() { return 'v1'; }" ) ) );
$store2  = fh_table( $helpers );
$_POST   = fh_save_post( $helpers['a-helpers']['id'], array( 'name' => 'A-helpers', 'priority' => '5', 'code' => "function fh_price_note_db() { return 'v2'; }" ) );
fh_request( $dep_dir, true );
do_action( 'init' ); // WordPress fires init before admin_post_{action}.
$halt = fh_handler( 'handle_save' );
check( 'saving a helper snippet whose function a file calls on init: the request lives to the handler (the new function exists), which saves it', 'v2' === $GLOBALS['fh_note'] && $halt instanceof FH_Halt && 2 === $store2->get( $helpers['a-helpers']['id'] )['version'], json_encode( $store2->log_rows( array(), 1 ) ) );
$GLOBALS['fh_options'][ Db_Snippets::OPTION_ACTIVE ] = $saved;
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

// A compile-stage fatal in the code saved for an active snippet, now at
// plugins_loaded:1 where it runs in the snippet's place: the request dies
// there — after the draft was kept.
$early      = $tmp . '-early.php';
$early_dump = $tmp . '-early.json';
file_put_contents(
	$early,
	"<?php\nrequire " . var_export( __DIR__ . '/wp-stubs.php', true ) . ";\nrequire " . var_export( __DIR__ . '/fake-db-store.php', true ) . ";\nrequire " . var_export( dirname( __DIR__ ) . '/class-admin-editor.php', true ) . ";\n"
	. "use FisHotel\\Misc\\Sections\\Site_Snippets as S;\n"
	. "define( 'FISHOTEL_SNIPPETS_EDITORS', 'jeff' );\n"
	. "update_option( S\\Db_Store::DB_VERSION_OPTION, S\\Db_Store::DB_VERSION, true );\n"
	. "S\\Catalog::use_dir( fh_snippet_dir( array() ) );\n"
	. "\$store = new S\\Tests\\Fake_Db_Store();\n"
	. "\$store->insert( array( 'id' => 9, 'slug' => 'live', 'name' => 'Live', 'description' => '', 'type' => 'php', 'scope' => 'global', 'priority' => 10, 'active' => 1, 'code' => '\$GLOBALS[\"fh_ran\"][] = \"live\";', 'version' => 3, 'created_by' => 7, 'created_at' => '', 'updated_by' => 7, 'updated_at' => '' ) );\n"
	. "update_option( S\\Db_Snippets::OPTION_ACTIVE, S\\Db_Snippets::active_option( \$store->all() )['map'], true );\n"
	. "S\\Admin_Editor::\$store = \$store;\n"
	. "\$GLOBALS['fh_is_admin'] = true;\n"
	. "fh_login( 7, 'jeff', true );\n"
	. "\$_SERVER['REQUEST_METHOD'] = 'POST';\n"
	. "\$_POST = array( 'action' => S\\Editor::SAVE_ACTION, S\\Editor::NONCE_FIELD => wp_create_nonce( S\\Editor::NONCE ), 'snippet_id' => '9', 'base_version' => '3', 'name' => 'Live', 'type' => 'php', 'scope' => 'global', 'priority' => '10', 'active' => '1', 'code' => 'function fh_two_early( \$a, \$a ) { return \$a; }' );\n"
	. "register_shutdown_function( function () use ( \$store ) { \$e = error_get_last(); file_put_contents( " . var_export( $early_dump, true ) . ", json_encode( array( 'fatal' => \$e ? \$e['message'] : '', 'drafts' => get_user_meta( 7, S\\Admin_Editor::DRAFTS_META, true ), 'version' => \$store->get( 9 )['version'], 'log' => count( \$store->log ) ) ) ); } );\n"
	. "S\\Loader::hook();\n"
	. "do_action( 'plugins_loaded' );\n"
	. "echo \"\\nNOT REACHED\\n\";\n"
);
$out = array();
exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $early ) . ' 2>&1', $out, $code );
$out  = implode( "\n", $out );
$dump = is_file( $early_dump ) ? json_decode( (string) file_get_contents( $early_dump ), true ) : array();
check( 'saving an active snippet whose new code has a compile-stage error: the request dies at plugins_loaded:1, where it runs in place', 0 !== $code && false === strpos( $out, 'NOT REACHED' ) && isset( $dump['fatal'] ) && false !== strpos( $dump['fatal'], 'Redefinition of parameter' ), $code . ' ' . $out );
check( '… his code kept as a "testing" draft first (the next page view logs it as test_failed); nothing saved', isset( $dump['drafts'][9] ) && 'testing' === $dump['drafts'][9]['state'] && 'function fh_two_early( $a, $a ) { return $a; }' === $dump['drafts'][9]['fields']['code'] && 3 === $dump['version'] && 0 === $dump['log'] );
$after = new Fake_Db_Store();
$msg   = ( new Editor( $after, array( 'actor' => array( 'user_id' => 7, 'user_login' => 'jeff' ) ) ) )->settle_dead_test( $dump['drafts'][9] );
check( '… which is what settles it on the next view', array( 'test_failed' ) === $after->actions() && 'live' === array_values( $after->log )[0]['slug'] && 0 === strpos( $msg, 'Refused: the test run ended the save request' ) );
foreach ( array( $persist, $draft, $active, $child, $early, $early_dump ) as $f ) {
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
