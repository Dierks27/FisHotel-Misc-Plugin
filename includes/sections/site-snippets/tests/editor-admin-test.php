<?php
/**
 * Editor admin tests — the My snippets / Log tabs and the only ways in:
 * the screens without FISHOTEL_SNIPPETS_EDITORS (read only, how to add
 * yourself, every write refused and logged as denied), with it (listed by
 * login, not listed, multisite super admin), the admin-post handlers (save,
 * a refused save keeping the typed code, a test run that throws, a stale
 * nonce, discard, restore, delete, restore after a delete, export as a
 * download, the log CSV) and the AJAX switch, the denied-row budget, a
 * save whose test run died settled on the next page view, safe mode
 * logged once an hour, nothing printed unescaped, the code editor
 * assets, no REST route, and the tables' install gate (no SHOW TABLES or
 * dbDelta once installed; a failed install waits). DISALLOW_FILE_EDIT
 * last (a constant cannot be undefined).
 *
 * Run from the plugin root:
 *   php includes/sections/site-snippets/tests/editor-admin-test.php
 *
 * @package FisHotel\Misc\Sections\Site_Snippets
 */

// ABSPATH points at a temp "WordPress" whose wp-admin/includes/upgrade.php
// holds a dbDelta() that records statements and "creates" the tables.
$fh_root = sys_get_temp_dir() . '/fh-wp-' . bin2hex( random_bytes( 4 ) ) . '/';
mkdir( $fh_root . 'wp-admin/includes', 0777, true );
file_put_contents(
	$fh_root . 'wp-admin/includes/upgrade.php',
	"<?php\nfunction dbDelta( \$sql ) {\n\t\$GLOBALS['fh_dbdelta'][] = \$sql;\n\tif ( empty( \$GLOBALS['fh_dbdelta_fail'] ) && preg_match( '/CREATE TABLE (\\S+)/', \$sql, \$m ) ) {\n\t\t\$GLOBALS['wpdb']->tables[] = \$m[1];\n\t}\n\treturn array();\n}\n"
);
define( 'ABSPATH', $fh_root );
register_shutdown_function(
	static function () use ( $fh_root ) {
		unlink( $fh_root . 'wp-admin/includes/upgrade.php' );
		rmdir( $fh_root . 'wp-admin/includes' );
		rmdir( $fh_root . 'wp-admin' );
		rmdir( $fh_root );
	}
);

require_once __DIR__ . '/wp-stubs.php';
require_once __DIR__ . '/fake-db-store.php';
require_once dirname( __DIR__ ) . '/class-admin-editor.php';

use FisHotel\Misc\Sections\Site_Snippets\Admin;
use FisHotel\Misc\Sections\Site_Snippets\Admin_Editor;
use FisHotel\Misc\Sections\Site_Snippets\Catalog;
use FisHotel\Misc\Sections\Site_Snippets\Db_Snippets;
use FisHotel\Misc\Sections\Site_Snippets\Db_Store;
use FisHotel\Misc\Sections\Site_Snippets\Editor;
use FisHotel\Misc\Sections\Site_Snippets\Loader;
use FisHotel\Misc\Sections\Site_Snippets\Site_Snippets;
use FisHotel\Misc\Sections\Site_Snippets\Tests\Fake_Db_Store;

$GLOBALS['fh_dbdelta'] = array();
$dir                   = fh_snippet_dir(
	array(
		'from-github.php' => fh_php_snippet( 'From GitHub', '$g = 1;' ),
	)
);
Catalog::use_dir( $dir );

$store               = new Fake_Db_Store();
Admin_Editor::$store = $store;
Admin_Editor::$halt  = static function () {
	throw new FH_Halt( 'exit' );
};
$_SERVER['REMOTE_ADDR']          = '203.0.113.5';
$_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.20, 10.0.0.1';
$_SERVER['HTTP_USER_AGENT']      = 'Test browser';
$GLOBALS['fh_is_admin']          = true;
update_option( Db_Store::DB_VERSION_OPTION, Db_Store::DB_VERSION, true );

/** Call a handler; return the FH_Halt / wp_die it ended with. */
function fh_call( $method, array $post = array() ) {
	$_POST                     = $post;
	$_SERVER['REQUEST_METHOD'] = 'POST';
	Loader::reset();
	$caught = null;
	ob_start();
	try {
		$editor = new Admin_Editor();
		$editor->$method();
	} catch ( FH_Halt $e ) {
		$caught = $e;
	} catch ( RuntimeException $e ) {
		$caught = $e;
	}
	$GLOBALS['fh_printed'] = ob_get_clean();
	$_POST                 = array();
	return $caught;
}

/** Render a tab; return the HTML. */
function fh_page( array $get ) {
	$_GET = $get;
	Loader::reset();
	ob_start();
	( new Admin() )->render_page();
	$html = ob_get_clean();
	$_GET = array();
	return $html;
}

function fh_nonce() {
	return wp_create_nonce( Editor::NONCE );
}

function fh_form( array $over = array() ) {
	return array_merge(
		array(
			'action'            => Editor::SAVE_ACTION,
			'snippet_id'        => '0',
			Editor::NONCE_FIELD => fh_nonce(),
			'slug'              => 'hide-bar',
			'name'              => 'Hide the bar',
			'description'       => 'For customers.',
			'type'              => 'php',
			'scope'             => 'global',
			'priority'          => '10',
			'active'            => '1',
			'code'              => "\$GLOBALS['fh_admin_ran'][] = 'hide-bar';",
		),
		$over
	);
}

echo "1. Without FISHOTEL_SNIPPETS_EDITORS\n";
fh_login( 7, 'jeff', true );
$html = fh_page( array( 'tab' => 'mine' ) );
check( 'My snippets is read only and shows how to add the constant', false !== strpos( $html, 'Read only.' ) && false !== strpos( $html, 'FISHOTEL_SNIPPETS_EDITORS is not defined' ) && false !== strpos( $html, "define( 'FISHOTEL_SNIPPETS_EDITORS', 'jeff' );" ) && false !== strpos( $html, 'Your username is jeff' ) );
check( 'no Add new button', false === strpos( $html, 'Add new' ) );
$halt = fh_call( 'handle_save', fh_form() );
check( 'a save is refused (403) and nothing is written', $halt instanceof RuntimeException && false !== strpos( $halt->getMessage(), 'Refused: Nobody may edit snippets yet' ) && array() === $store->snippets );
check( '… and logged as denied, with who and from where', array( 'denied' ) === $store->actions() && 'refused save: Nobody may edit snippets yet: FISHOTEL_SNIPPETS_EDITORS is not defined in wp-config.php.' === $store->log_rows( array(), 1 )[0]['summary'] && 'jeff' === $store->log_rows( array(), 1 )[0]['user_login'] && '203.0.113.5' === $store->log_rows( array(), 1 )[0]['remote_addr'] && '198.51.100.20' === $store->log_rows( array(), 1 )[0]['forwarded_for'] );
$halt = fh_call( 'ajax_toggle', array( 'snippet_id' => '1', 'enabled' => '1', Editor::NONCE_FIELD => fh_nonce() ) );
check( 'the AJAX switch: a JSON refusal (403), logged', $halt instanceof FH_Halt && 'json' === $halt->kind && 403 === $halt->status && false === $halt->data['success'] && 2 === count( $store->log ) );
foreach ( array( 'handle_delete', 'handle_restore', 'handle_undelete', 'handle_export', 'handle_discard' ) as $method ) {
	fh_call( $method, array( 'snippet_id' => '1', Editor::NONCE_FIELD => fh_nonce() ) );
}
check( 'delete, restore, restore after delete, export and discard: each refused and logged', 7 === count( $store->log ) && array( 'denied' ) === array_values( array_unique( $store->actions() ) ) );

echo "2. With the constant — who may write\n";
define( 'FISHOTEL_SNIPPETS_EDITORS', 'jeff, 12' );
fh_login( 8, 'anna', true );
$html = fh_page( array( 'tab' => 'mine' ) );
check( 'an administrator not named: read only, told so, shown the line', false !== strpos( $html, 'Your account is not named in FISHOTEL_SNIPPETS_EDITORS' ) && false !== strpos( $html, "define( 'FISHOTEL_SNIPPETS_EDITORS', 'jeff' );" ) );
$before = count( $store->log );
$halt   = fh_call( 'handle_save', fh_form() );
check( '… a save by them is refused and logged (the posted code is not shown back)', $before + 1 === count( $store->log ) && array() === $store->snippets && $halt instanceof RuntimeException && false === strpos( $halt->getMessage(), '<textarea' ) );
fh_login( 12, 'by-id', true );
check( 'named by user ID: may write', Editor::permission_now()['allowed'] );
fh_login( 7, 'jeff', false );
check( 'named but without manage_options: may not', 'no_capability' === Editor::permission_now()['reason'] );
$GLOBALS['fh_multisite'] = true;
fh_login( 7, 'jeff', true );
check( 'multisite: a named site administrator who is not super admin may not', 'no_capability' === Editor::permission_now()['reason'] );
$GLOBALS['fh_super_admin'] = true;
check( 'multisite: a named super admin may', Editor::permission_now()['allowed'] );
$GLOBALS['fh_multisite']   = false;
$GLOBALS['fh_super_admin'] = false;
fh_login( 7, 'jeff', true );
$html = fh_page( array( 'tab' => 'mine' ) );
check( 'jeff: no read-only box, an Add new button', false === strpos( $html, 'Read only.' ) && false !== strpos( $html, 'Add new' ) );
$before = count( $store->log );
$stale  = "\$late_night = '</textarea><script>x()</script>';";
$halt   = fh_call( 'handle_save', fh_form( array( Editor::NONCE_FIELD => 'stale', 'code' => $stale ) ) );
check( 'a stale nonce: refused, logged as denied with the reason', $halt instanceof RuntimeException && $before + 1 === count( $store->log ) && false !== strpos( $store->log_rows( array(), 1 )[0]['summary'], 'security token was missing or expired' ) );
check( '… nothing kept for them (a forged request must not plant a draft), but the code comes back read only, escaped, to copy', array() === Admin_Editor::drafts( 7 ) && false !== strpos( $halt->getMessage(), '<textarea readonly' ) && false !== strpos( $halt->getMessage(), esc_textarea( $stale ) . '</textarea>' ) && false === strpos( $halt->getMessage(), '<script>' ) );

echo "3. Saving\n";
$GLOBALS['fh_admin_ran'] = array();
$halt                    = fh_call( 'handle_save', fh_form() );
$row                     = $store->get_by_slug( 'hide-bar' );
check( 'created: redirected to its edit screen', $halt instanceof FH_Halt && 'redirect' === $halt->kind && false !== strpos( $halt->data, 'view=edit' ) && false !== strpos( $halt->data, 'id=' . $row['id'] ) );
check( 'test-run once in the save request, then written: row, revision, log, the active option (autoloaded)', array( 'hide-bar' ) === $GLOBALS['fh_admin_ran'] && 1 === $row['version'] && 1 === count( $store->revisions ) && 'create' === $store->log_rows( array(), 1 )[0]['action'] && isset( get_option( Db_Snippets::OPTION_ACTIVE )['hide-bar'] ) && true === $GLOBALS['fh_autoload'][ Db_Snippets::OPTION_ACTIVE ] );
check( 'the log row has the user agent and the first X-Forwarded-For value', 'Test browser' === $store->log_rows( array(), 1 )[0]['user_agent'] && '198.51.100.20' === $store->log_rows( array(), 1 )[0]['forwarded_for'] );
check( 'no draft left', array() === Admin_Editor::drafts( 7 ) );
$html = fh_page( array( 'tab' => 'mine', 'view' => 'edit', 'id' => (string) $row['id'] ) );
check( 'the notice says it was created and tested', false !== strpos( $html, 'Created — version 1. The test run passed' ) );
check( 'the edit form: fixed slug, base version, nonce, the code in the textarea (escaped)', false !== strpos( $html, 'fh-snip-slug-fixed' ) && false !== strpos( $html, 'name="base_version" value="1"' ) && false !== strpos( $html, 'name="' . Editor::NONCE_FIELD . '" value="' . fh_nonce() . '"' ) && false !== strpos( $html, "\$GLOBALS[&#039;fh_admin_ran&#039;][] = &#039;hide-bar&#039;;</textarea>" ) );
check( 'the notice is shown once', false === strpos( fh_page( array( 'tab' => 'mine', 'view' => 'edit', 'id' => (string) $row['id'] ) ), 'The test run passed' ) );

$typed = "function fh_typed() {\n\t\$a = ;\n}";
$halt  = fh_call( 'handle_save', fh_form( array( 'snippet_id' => (string) $row['id'], 'base_version' => '1', 'name' => 'Renamed', 'code' => $typed ) ) );
check( 'a syntax error: refused, back to the edit screen, nothing written', $halt instanceof FH_Halt && false !== strpos( $halt->data, 'view=edit' ) && 1 === $store->get( $row['id'] )['version'] && 'Hide the bar' === $store->get( $row['id'] )['name'] );
$draft = Admin_Editor::draft( 7, $row['id'] );
check( 'what was typed is kept as the draft, with the reason', is_array( $draft ) && $typed === $draft['fields']['code'] && 'Renamed' === $draft['fields']['name'] && 'refused' === $draft['state'] && false !== strpos( $draft['message'], 'PHP syntax error on line 2' ) );
$html = fh_page( array( 'tab' => 'mine', 'view' => 'edit', 'id' => (string) $row['id'] ) );
check( 'the edit screen shows the typed code and name, the reason, and a discard button', false !== strpos( $html, esc_textarea( $typed ) . '</textarea>' ) && false !== strpos( $html, 'value="Renamed"' ) && false !== strpos( $html, 'PHP syntax error on line 2' ) && false !== strpos( $html, Admin_Editor::DISCARD_ACTION ) );
$list = fh_page( array( 'tab' => 'mine' ) );
check( 'the list points at the unsaved changes', false !== strpos( $list, 'You have unsaved changes to this snippet' ) );
fh_call( 'handle_discard', array( 'snippet_id' => (string) $row['id'], Editor::NONCE_FIELD => fh_nonce() ) );
check( 'discard: the draft is gone, the saved code shows again', null === Admin_Editor::draft( 7, $row['id'] ) && false !== strpos( fh_page( array( 'tab' => 'mine', 'view' => 'edit', 'id' => (string) $row['id'] ) ), 'value="Hide the bar"' ) );

$halt = fh_call( 'handle_save', fh_form( array( 'slug' => 'thrower', 'code' => "throw new \\RuntimeException( 'admin boom' );" ) ) );
check( 'a new snippet whose test run throws: refused, back to "new" with the code kept, test_failed logged', $halt instanceof FH_Halt && false !== strpos( $halt->data, 'view=new' ) && null === $store->get_by_slug( 'thrower' ) && 'test_failed' === $store->log_rows( array(), 1 )[0]['action'] && false !== strpos( Admin_Editor::draft( 7, 0 )['message'], 'RuntimeException: admin boom (line 1)' ) );
$html = fh_page( array( 'tab' => 'mine', 'view' => 'new' ) );
check( 'the new screen shows the kept code and slug', false !== strpos( $html, 'admin boom' ) && false !== strpos( $html, 'value="thrower"' ) );
fh_call( 'handle_discard', array( 'snippet_id' => '0', Editor::NONCE_FIELD => fh_nonce() ) );

echo "4. A save whose test run never came back\n";
Admin_Editor::keep_draft( 7, $row['id'], array( 'slug' => 'hide-bar', 'name' => 'Hide the bar', 'type' => 'php', 'scope' => 'global', 'priority' => '10', 'active' => '1', 'code' => 'function fh_two( $a, $a ) {}' ), 'testing', '', array(), 2 );
$before = count( $store->log );
$html   = fh_page( array( 'tab' => 'mine' ) );
check( 'the next page view logs test_failed for it, once', $before + 1 === count( $store->log ) && 'test_failed' === $store->log_rows( array(), 1 )[0]['action'] && false !== strpos( $store->log_rows( array(), 1 )[0]['summary'], 'fatal error PHP cannot catch' ) );
fh_page( array( 'tab' => 'mine' ) );
check( '… not twice', $before + 1 === count( $store->log ) );
$html = fh_page( array( 'tab' => 'mine', 'view' => 'edit', 'id' => (string) $row['id'] ) );
check( '… and the edit screen shows the code with the reason', false !== strpos( $html, 'function fh_two( $a, $a ) {}</textarea>' ) && false !== strpos( $html, 'Refused: the test run ended the save request' ) );
fh_call( 'handle_discard', array( 'snippet_id' => (string) $row['id'], Editor::NONCE_FIELD => fh_nonce() ) );

echo "5. The switch, history, delete, restore\n";
$halt = fh_call( 'ajax_toggle', array( 'snippet_id' => (string) $row['id'], 'enabled' => '0', Editor::NONCE_FIELD => fh_nonce() ) );
check( 'switched off by AJAX: success, its status, the active option emptied', $halt instanceof FH_Halt && true === $halt->data['success'] && false === $halt->data['data']['enabled'] && 'off' === $halt->data['data']['status']['key'] && array() === get_option( Db_Snippets::OPTION_ACTIVE ) );
$GLOBALS['fh_admin_ran'] = array();
$halt                    = fh_call( 'ajax_toggle', array( 'snippet_id' => (string) $row['id'], 'enabled' => '1', Editor::NONCE_FIELD => fh_nonce() ) );
check( 'switched on: test-run first, then on', true === $halt->data['success'] && 'on' === $halt->data['data']['status']['key'] && array( 'hide-bar' ) === $GLOBALS['fh_admin_ran'] && false !== strpos( $halt->data['data']['status']['label'], 'Everywhere' ) );
$id_t = $store->insert( array( 'slug' => 'throws-off', 'name' => 'Throws', 'description' => '', 'type' => 'php', 'scope' => 'global', 'priority' => 10, 'active' => 0, 'code' => "throw new \\LogicException( 'toggle boom' );", 'version' => 1, 'created_by' => 7, 'created_at' => '2026-10-18 09:00:00', 'updated_by' => 7, 'updated_at' => '2026-10-18 09:00:00' ) );
$halt = fh_call( 'ajax_toggle', array( 'snippet_id' => (string) $id_t, 'enabled' => '1', Editor::NONCE_FIELD => fh_nonce() ) );
check( 'switching on a snippet that throws: refused with the reason (400), stays off', false === $halt->data['success'] && 400 === $halt->status && false !== strpos( $halt->data['data']['message'], 'LogicException: toggle boom' ) && 0 === $store->get( $id_t )['active'] );
Admin_Editor::keep_draft( 7, $row['id'], array( 'name' => 'Typed but unsaved', 'code' => '$unsaved = 1;' ), 'refused', 'Not saved — kept.', array() );
fh_call( 'ajax_toggle', array( 'snippet_id' => (string) $row['id'], 'enabled' => '0', Editor::NONCE_FIELD => fh_nonce() ) );
fh_call( 'ajax_toggle', array( 'snippet_id' => (string) $row['id'], 'enabled' => '1', Editor::NONCE_FIELD => fh_nonce() ) );
check( 'the switch never touches an unsaved draft of the snippet (and leaves no marker of its own)', '$unsaved = 1;' === Admin_Editor::draft( 7, $row['id'] )['fields']['code'] && 'refused' === Admin_Editor::draft( 7, $row['id'] )['state'] );
fh_call( 'handle_discard', array( 'snippet_id' => (string) $row['id'], Editor::NONCE_FIELD => fh_nonce() ) );
fh_call( 'ajax_toggle', array( 'snippet_id' => (string) $row['id'], 'enabled' => '0', Editor::NONCE_FIELD => fh_nonce() ) );
fh_call( 'ajax_toggle', array( 'snippet_id' => (string) $row['id'], 'enabled' => '1', Editor::NONCE_FIELD => fh_nonce() ) );
check( '… and without a draft, a switch that came back leaves none', null === Admin_Editor::draft( 7, $row['id'] ) );
fh_call( 'handle_save', fh_form( array( 'snippet_id' => (string) $row['id'], 'base_version' => (string) $store->get( $row['id'] )['version'], 'name' => 'Second name', 'code' => "\$GLOBALS['fh_admin_ran'][] = 'v-new';" ) ) );
$revs = $store->revisions( $row['id'] );
$html = fh_page( array( 'tab' => 'mine', 'view' => 'history', 'id' => (string) $row['id'], 'rev' => (string) $revs[ count( $revs ) - 1 ]['id'] ) );
check( 'history: every version with who and what changed', count( $revs ) === substr_count( $html, '<td>jeff</td>' ) && false !== strpos( $html, 'name: &quot;Hide the bar&quot; -&gt; &quot;Second name&quot;' ) && false !== strpos( $html, 'switched on -&gt; off' ) );
check( 'the diff against the current code (wp_text_diff), and Restore buttons for older versions', false !== strpos( $html, '<table class="diff">' ) && false !== strpos( $html, 'Version 1 against the current code' ) && count( $revs ) - 1 === substr_count( $html, 'name="revision_id"' ) );
$GLOBALS['fh_admin_ran'] = array();
$halt                    = fh_call( 'handle_restore', array( 'snippet_id' => (string) $row['id'], 'revision_id' => (string) $revs[ count( $revs ) - 1 ]['id'], 'base_version' => (string) $store->get( $row['id'] )['version'], Editor::NONCE_FIELD => fh_nonce() ) );
check( 'restore leaves no draft behind', null === Admin_Editor::draft( 7, $row['id'] ) );
check( 'restore: a new version with version 1\'s code, test-run (it is on), back to the history', $halt instanceof FH_Halt && false !== strpos( $halt->data, 'view=history' ) && "\$GLOBALS['fh_admin_ran'][] = 'hide-bar';" === $store->get( $row['id'] )['code'] && array( 'hide-bar' ) === $GLOBALS['fh_admin_ran'] && 'restore' === $store->log_rows( array(), 1 )[0]['action'] );
fh_call( 'handle_delete', array( 'snippet_id' => (string) $row['id'], Editor::NONCE_FIELD => fh_nonce() ) );
check( 'delete: gone from the table and the active option; revisions kept', null === $store->get( $row['id'] ) && ! isset( get_option( Db_Snippets::OPTION_ACTIVE )['hide-bar'] ) && count( $store->revisions( $row['id'] ) ) >= 4 );
$log = fh_page( array( 'tab' => 'log' ) );
check( 'the Log tab lists it with a Restore button on the delete row (one)', 1 === substr_count( $log, 'name="action" value="' . Editor::UNDELETE_ACTION . '"' ) && false !== strpos( $log, 'deleted at v' ) );
check( '… shows who, where (X-Forwarded-For marked untrusted) and the CSV link with a nonce', false !== strpos( $log, 'jeff (#7)' ) && false !== strpos( $log, 'X-Forwarded-For 198.51.100.20 (untrusted)' ) && false !== strpos( $log, 'action=' . Editor::CSV_ACTION ) && false !== strpos( $log, '_wpnonce=' . wp_create_nonce( Admin_Editor::CSV_NONCE ) ) );
check( '… and has no way to delete a log row', false === stripos( $log, 'delete log' ) && false === strpos( $log, 'Clear log' ) );
$delete_row = $store->log_rows( array( 'action' => 'delete' ), 1 )[0];
$halt       = fh_call( 'handle_undelete', array( 'log_id' => (string) $delete_row['id'], Editor::NONCE_FIELD => fh_nonce() ) );
check( 'restore after a delete: back, switched off, under the same id', is_array( $store->get( $row['id'] ) ) && 0 === $store->get( $row['id'] )['active'] && false !== strpos( $halt->data, 'view=edit' ) );
check( '… no Restore button any more', 0 === substr_count( fh_page( array( 'tab' => 'log' ) ), 'name="action" value="' . Editor::UNDELETE_ACTION . '"' ) );
$filtered = fh_page( array( 'tab' => 'log', 'log_action' => 'denied' ) );
check( 'the log filters by action', false !== strpos( $filtered, 'refused save' ) && false === strpos( $filtered, 'deleted at v' ) );


echo "5b. Other administrators never see the code\n";
$secret_row = $store->get( $row['id'] );
fh_login( 8, 'anna', true );
$views = array(
	'edit'    => fh_page( array( 'tab' => 'mine', 'view' => 'edit', 'id' => (string) $row['id'] ) ),
	'history' => fh_page( array( 'tab' => 'mine', 'view' => 'history', 'id' => (string) $row['id'] ) ),
	'diff'    => fh_page( array( 'tab' => 'mine', 'view' => 'history', 'id' => (string) $row['id'], 'rev' => (string) $store->revisions( $row['id'] )[1]['id'] ) ),
	'new'     => fh_page( array( 'tab' => 'mine', 'view' => 'new' ) ),
	'list'    => fh_page( array( 'tab' => 'mine' ) ),
);
$leaks = array();
foreach ( $views as $name => $html ) {
	if ( false !== strpos( $html, 'fh_admin_ran' ) || false !== strpos( $html, '<textarea' ) || false !== strpos( $html, 'class="diff"' ) ) {
		$leaks[] = $name;
	}
}
check( 'an administrator not named: no code on the edit, history, diff or new screen — only the list', array() === $leaks && false !== strpos( $views['edit'], 'Only the people named in FISHOTEL_SNIPPETS_EDITORS can open a snippet&#039;s code' ) && false !== strpos( $views['edit'], 'fh-snip-db-table' ), implode( ',', $leaks ) );
check( '… the list keeps the name and status but has no Edit / View / History links', false !== strpos( $views['list'], esc_html( $secret_row['name'] ) ) && false === strpos( $views['list'], 'view=edit' ) && false === strpos( $views['list'], 'view=history' ) );
$GLOBALS['fh_multisite'] = true;
fh_login( 7, 'jeff', true );
check( 'multisite: a named site administrator who is not super admin sees no code either', false === strpos( fh_page( array( 'tab' => 'mine', 'view' => 'edit', 'id' => (string) $row['id'] ) ), 'fh_admin_ran' ) );
$GLOBALS['fh_multisite'] = false;
check( 'jeff still sees it', false !== strpos( fh_page( array( 'tab' => 'mine', 'view' => 'edit', 'id' => (string) $row['id'] ) ), 'fh_admin_ran' ) );

echo "5c. Never lose typed code\n";
fh_call( 'handle_save', fh_form( array( 'slug' => 'fix-me', 'name' => 'Fix me', 'code' => '$GLOBALS[\'fh_admin_ran\'][] = \'fix-me\';' ) ) );
$fix  = $store->get_by_slug( 'fix-me' );
fh_call( 'handle_save', fh_form( array( 'snippet_id' => (string) $fix['id'], 'base_version' => '1', 'name' => 'Fix me', 'code' => "throw new \\RuntimeException( 'oops' );" ) ) );
fh_call( 'ajax_toggle', array( 'snippet_id' => (string) $fix['id'], 'enabled' => '0', Editor::NONCE_FIELD => fh_nonce() ) );
$html = fh_page( array( 'tab' => 'mine', 'view' => 'edit', 'id' => (string) $fix['id'] ) );
preg_match( '/name="base_version" value="([^"]*)"/', $html, $bv );
fh_call( 'handle_save', fh_form( array( 'snippet_id' => (string) $fix['id'], 'base_version' => $bv[1], 'name' => 'Fix me', 'active' => '', 'code' => '$fixed = 1;' ) ) );
check( 'a refused save, then the editor switches it off in the list, then saves the fixed code from the form: saved (an on / off change is no conflict)', '$fixed = 1;' === $store->get( $fix['id'] )['code'] && null === Admin_Editor::draft( 7, $fix['id'] ), json_encode( Admin_Editor::draft( 7, $fix['id'] ) ) );
fh_call( 'handle_save', fh_form( array( 'snippet_id' => (string) $fix['id'], 'base_version' => (string) $store->get( $fix['id'] )['version'], 'name' => 'Renamed in another tab', 'active' => '', 'code' => '$fixed = 1;' ) ) );
fh_call( 'handle_save', fh_form( array( 'snippet_id' => (string) $fix['id'], 'base_version' => $bv[1], 'name' => 'Fix me', 'active' => '', 'code' => '$mine = 2;' ) ) );
$draft = Admin_Editor::draft( 7, $fix['id'] );
check( 'a real conflict: refused, the code kept — the draft now based on the current version', false !== strpos( $draft['message'], 'changed since you opened it' ) && '$mine = 2;' === $draft['fields']['code'] && (string) $store->get( $fix['id'] )['version'] === $draft['fields']['base_version'] );
$html = fh_page( array( 'tab' => 'mine', 'view' => 'edit', 'id' => (string) $fix['id'] ) );
preg_match( '/name="base_version" value="([^"]*)"/', $html, $bv2 );
fh_call( 'handle_save', fh_form( array( 'snippet_id' => (string) $fix['id'], 'base_version' => $bv2[1], 'name' => 'Fix me', 'active' => '', 'code' => '$mine = 2;' ) ) );
check( '… so saving again from that form goes through (not "changed since" forever)', '$mine = 2;' === $store->get( $fix['id'] )['code'] && null === Admin_Editor::draft( 7, $fix['id'] ) );

fh_call( 'handle_save', fh_form( array( 'slug' => 'gone', 'name' => 'Gone', 'active' => '', 'code' => '$g = 1;' ) ) );
$gone = $store->get_by_slug( 'gone' );
$html = fh_page( array( 'tab' => 'mine', 'view' => 'edit', 'id' => (string) $gone['id'] ) );
check( 'the edit form carries the slug (never read on save), so a draft keeps it', false !== strpos( $html, '<input type="hidden" name="slug" value="gone">' ) );
fh_call( 'handle_delete', array( 'snippet_id' => (string) $gone['id'], Editor::NONCE_FIELD => fh_nonce() ) ); // In another tab.
Admin_Editor::take_notice();
$halt = fh_call( 'handle_save', fh_form( array( 'snippet_id' => (string) $gone['id'], 'base_version' => '1', 'slug' => 'gone', 'name' => 'Gone', 'active' => '', 'code' => '$an_hour_of_work = 42;' ) ) );
$html = fh_page( array( 'tab' => 'mine', 'view' => 'edit', 'id' => (string) $gone['id'] ) );
check( 'saving a snippet deleted meanwhile: back to its address, where the code is offered as a new snippet (slug editable, filled)', $halt instanceof FH_Halt && false !== strpos( $halt->data, 'id=' . $gone['id'] ) && false !== strpos( $html, '$an_hour_of_work = 42;</textarea>' ) && false !== strpos( $html, 'was deleted (in another tab?)' ) && false !== strpos( $html, 'name="slug" value="gone"' ) && false !== strpos( $html, 'name="draft_id" value="' . $gone['id'] . '"' ) && false !== strpos( $html, 'name="snippet_id" value="0"' ) );
check( '… and the list links to it', false !== strpos( fh_page( array( 'tab' => 'mine' ) ), 'You have unsaved code of snippet #' . $gone['id'] . ', which was deleted' ) );
fh_call( 'handle_save', fh_form( array( 'snippet_id' => '0', 'draft_id' => (string) $gone['id'], 'slug' => 'gone', 'name' => 'Gone', 'active' => '', 'code' => '$an_hour_of_work = 42;' ) ) );
check( '… created from there: a new snippet with the code, the draft gone', '$an_hour_of_work = 42;' === $store->get_by_slug( 'gone' )['code'] && (int) $store->get_by_slug( 'gone' )['id'] !== (int) $gone['id'] && null === Admin_Editor::draft( 7, $gone['id'] ) && false === strpos( fh_page( array( 'tab' => 'mine' ) ), 'which was deleted' ) );

echo "5d. The list the site runs follows the table\n";
$GLOBALS['fh_option_fail'][ Db_Snippets::OPTION_ACTIVE ] = true;
$on_row = $store->get_by_slug( 'fix-me' );
$halt   = fh_call( 'ajax_toggle', array( 'snippet_id' => (string) $on_row['id'], 'enabled' => '1', Editor::NONCE_FIELD => fh_nonce() ) );
check( 'switched on, but the option write fails: the answer says so (stale), the site does not run it yet', true === $halt->data['success'] && true === $halt->data['data']['stale'] && false !== strpos( $halt->data['data']['message'], 'BUT the list' ) && 'pending' === $halt->data['data']['status']['key'] && ! isset( get_option( Db_Snippets::OPTION_ACTIVE )['fix-me'] ) );
$halt = fh_call( 'handle_save', fh_form( array( 'snippet_id' => (string) $on_row['id'], 'base_version' => (string) $store->get( $on_row['id'] )['version'], 'name' => 'Fix me', 'code' => '$mine = 3;' ) ) );
$html = fh_page( array( 'tab' => 'mine', 'view' => 'edit', 'id' => (string) $on_row['id'] ) );
check( 'a save whose option write fails: an error notice, not "Saved"', false !== strpos( $html, 'is-error' ) && false !== strpos( $html, 'BUT the list of snippets the site runs could not be updated' ) );
fh_login( 8, 'anna', true );
$html = fh_page( array( 'tab' => 'mine' ) );
check( 'anna\'s list: the drift is shown, the row says the site does not run it, nothing rebuilt', false !== strpos( $html, 'does not match this table (switched on but missing: fix-me)' ) && false !== strpos( $html, 'not in the list the site runs' ) && ! isset( get_option( Db_Snippets::OPTION_ACTIVE )['fix-me'] ) );
unset( $GLOBALS['fh_option_fail'][ Db_Snippets::OPTION_ACTIVE ] );
update_option( Db_Snippets::OPTION_ACTIVE, array_merge( get_option( Db_Snippets::OPTION_ACTIVE ), array( 'x' => array( 'slug' => 'x', 'type' => 'php', 'scope' => 'global', 'code' => "\$GLOBALS['fh_admin_ran'][] = 'injected';" ) ) ), true ); // Anna, through wp-admin/options.php.
fh_login( 7, 'jeff', true );
$GLOBALS['fh_actions'] = array();
$html = fh_page( array( 'tab' => 'mine' ) );
$want = Db_Snippets::active_option( $store->all() )['map'];
check( 'jeff\'s next view of the list rebuilds it from the table: the snippet listed, the injected entry gone, a notice and a Health warning', $want === get_option( Db_Snippets::OPTION_ACTIVE ) && isset( $want['fix-me'] ) && ! isset( get_option( Db_Snippets::OPTION_ACTIVE )['x'] ) && false !== strpos( $html, 'It was rebuilt from the table just now' ) && false !== strpos( $html, 'switched on but missing: fix-me; not written by the editor (or the salts changed): x' ) && 1 === count( array_filter( $GLOBALS['fh_actions'], static function ( $a ) { return 'fishotel_log' === $a[0] && 'warning' === $a[1][0] && false !== strpos( $a[1][2], 'rebuilt from the table' ); } ) ) );
check( '… and the view after that is quiet', false === strpos( fh_page( array( 'tab' => 'mine' ) ), 'did not match' ) );

echo "6. Export and the CSV\n";
$halt = fh_call( 'handle_export', array( 'snippet_id' => (string) $row['id'], Editor::NONCE_FIELD => fh_nonce() ) );
check( 'export: the file is sent as a download and the request ends', $halt instanceof FH_Halt && 'exit' === $halt->kind && 0 === strpos( $GLOBALS['fh_printed'], "<?php\n/**\n * Snippet: Hide the bar\n" ) && false !== strpos( $GLOBALS['fh_printed'], 'Origin: written in wp-admin by jeff, exported ' ) && false !== strpos( $GLOBALS['fh_printed'], 'Default: off' ) );
check( 'exported_at set, export logged', null !== $store->get( $row['id'] )['exported_at'] && 'export' === $store->log_rows( array(), 1 )[0]['action'] );
$store->append_log( array( 'action' => 'denied', 'slug' => '', 'user_id' => 9, 'user_login' => '=cmd|calc', 'remote_addr' => '', 'forwarded_for' => '@SUM(1)', 'user_agent' => '-x', 'summary' => '+1', 'created_at' => '2026-10-18 10:00:00' ) );
$_GET = array( '_wpnonce' => wp_create_nonce( Admin_Editor::CSV_NONCE ) );
$halt = fh_call( 'handle_log_csv' );
$_GET = array();
$csv  = array_map( 'str_getcsv', array_filter( explode( "\n", $GLOBALS['fh_printed'] ) ) );
check( 'the CSV: the header and every row, oldest first', $halt instanceof FH_Halt && 'id' === $csv[0][0] && count( $store->log ) + 1 === count( $csv ) && '1' === $csv[1][0] );
check( 'formula-guarded cells', in_array( "'=cmd|calc", end( $csv ), true ) && in_array( "'@SUM(1)", end( $csv ), true ) && in_array( "'+1", end( $csv ), true ) );
$halt = fh_call( 'handle_log_csv' );
check( 'without its nonce: refused', $halt instanceof RuntimeException );
fh_login( 7, 'jeff', false );
$_GET = array( '_wpnonce' => wp_create_nonce( Admin_Editor::CSV_NONCE ) );
$halt = fh_call( 'handle_log_csv' );
$_GET = array();
check( 'without manage_options: refused', $halt instanceof RuntimeException && false !== strpos( $halt->getMessage(), 'permission' ) );
fh_login( 7, 'jeff', true );

echo "7. Nothing printed unescaped\n";
$evil_code = "// </textarea><script>alert('x')</script>\n\$e = '<img src=x onerror=alert(1)>';";
$evil      = fh_call( 'handle_save', fh_form( array( 'slug' => 'evil', 'name' => 'Evil <b onmouseover=alert(1)>name</b>', 'description' => '<script>d()</script>', 'active' => '', 'code' => $evil_code ) ) );
$evil_row  = $store->get_by_slug( 'evil' );
fh_call( 'handle_save', fh_form( array( 'snippet_id' => (string) $evil_row['id'], 'base_version' => '1', 'slug' => 'evil', 'name' => 'Evil <b onmouseover=alert(1)>name</b>', 'description' => '<script>d()</script>', 'active' => '', 'code' => $evil_code . "\n// <script>second()</script>" ) ) );
$pages = array(
	'list'    => fh_page( array( 'tab' => 'mine' ) ),
	'edit'    => fh_page( array( 'tab' => 'mine', 'view' => 'edit', 'id' => (string) $evil_row['id'] ) ),
	'history' => fh_page( array( 'tab' => 'mine', 'view' => 'history', 'id' => (string) $evil_row['id'], 'rev' => (string) $store->revisions( $evil_row['id'] )[1]['id'] ) ),
	'log'     => fh_page( array( 'tab' => 'log' ) ),
	'files'   => fh_page( array() ),
);
foreach ( $pages as $name => $html ) {
	check( $name . ': no raw <script>, <img> or </textarea> from the snippet', false === strpos( $html, '<script>' ) && false === strpos( $html, '<img src=x' ) && false === strpos( $html, '<b onmouseover' ) && 1 >= substr_count( $html, '</textarea>' ) );
}
check( 'edit: the code is in the textarea escaped, intact', false !== strpos( $pages['edit'], esc_textarea( $evil_code ) ) );
check( 'history: the old code in <pre>, escaped', false !== strpos( $pages['history'], '<pre>' . esc_html( $evil_code ) . '</pre>' ) );

echo "8. The denied budget, safe mode logged once an hour\n";
fh_login( 30, 'customer', false );
$before = count( $store->log );
for ( $n = 0; $n < 40; $n++ ) {
	fh_call( 'handle_save', fh_form() );
}
check( 'a logged-in customer hammering the save: refused every time, at most 30 denied rows an hour', $before + Admin_Editor::DENIED_PER_HOUR === count( $store->log ) );
foreach ( array( 31, 32, 33, 34 ) as $customer ) {
	fh_login( $customer, 'customer' . $customer, false );
	for ( $n = 0; $n < 40; $n++ ) {
		fh_call( 'handle_save', fh_form() );
	}
}
check( 'five customer accounts: at most 100 denied rows an hour for all of them together', $before + Admin_Editor::DENIED_SITE_PER_HOUR === count( $store->log ) );
fh_login( 8, 'anna', true );
fh_call( 'handle_save', fh_form() );
check( '… an administrator\'s refusal is still logged (the customers cannot crowd it out)', $before + Admin_Editor::DENIED_SITE_PER_HOUR + 1 === count( $store->log ) && 'anna' === $store->log_rows( array(), 1 )[0]['user_login'] );
$site = get_transient( Admin_Editor::DENIED_TRANSIENT . 'site' );
set_transient( Admin_Editor::DENIED_TRANSIENT . 'site', array( 'start' => $site['start'] - HOUR_IN_SECONDS, 'n' => $site['n'] ), 1 );
set_transient( Admin_Editor::DENIED_TRANSIENT . '35', array( 'start' => time() - HOUR_IN_SECONDS, 'n' => Admin_Editor::DENIED_PER_HOUR ), 1 );
fh_login( 35, 'customer35', false );
fh_call( 'handle_save', fh_form() );
check( 'the hour is a fixed window: once it is over, refusals are logged again', $before + Admin_Editor::DENIED_SITE_PER_HOUR + 2 === count( $store->log ) && 1 === get_transient( Admin_Editor::DENIED_TRANSIENT . 'site' )['n'] );
fh_login( 7, 'jeff', true );
$_GET[ Loader::SAFE_MODE_QUERY ] = '1';
Loader::reset();
$before = count( $store->log );
( new Admin_Editor() )->maybe_log_safe_mode();
Loader::reset();
( new Admin_Editor() )->maybe_log_safe_mode();
check( 'safe mode by query: one safe_mode row for the hour', $before + 1 === count( $store->log ) && 'safe_mode' === $store->log_rows( array(), 1 )[0]['action'] );
check( 'the editor\'s forms post to admin-post with the flag kept', false !== strpos( Admin_Editor::post_url(), Loader::SAFE_MODE_QUERY . '=1' ) );
unset( $_GET[ Loader::SAFE_MODE_QUERY ] );
Loader::reset();

echo "9. Assets\n";
$_GET = array( 'page' => Site_Snippets::MENU_SLUG, 'tab' => 'mine', 'view' => 'edit', 'id' => (string) $evil_row['id'] );
( new Admin_Editor() )->enqueue_assets( 'fishotel-tools_page_' . Site_Snippets::MENU_SLUG );
check( 'the edit screen enqueues WordPress\'s code editor for the type (PHP)', 'text/x-php' === $GLOBALS['fh_code_editor_args']['type'] && true === $GLOBALS['fh_code_editor_args']['codemirror']['indentWithTabs'] );
$loc = $GLOBALS['fh_scripts']['fishotel-snippets-editor']['localized']['fhSnippetsEditor'];
check( 'the script gets the nonce, the switch action and the code editor settings', fh_nonce() === $loc['nonce'] && Editor::TOGGLE_ACTION === $loc['action'] && is_array( $loc['codeEditor'] ) );
$css_id = $store->insert( array( 'slug' => 'teal', 'name' => 'Teal', 'description' => '', 'type' => 'css', 'scope' => 'front-end', 'priority' => 10, 'active' => 0, 'code' => 'a{}', 'version' => 1, 'created_by' => 7, 'created_at' => '', 'updated_by' => 7, 'updated_at' => '' ) );
$_GET['id']                = (string) $css_id;
$GLOBALS['fh_code_editor'] = false; // "Disable syntax highlighting" ticked in the profile.
( new Admin_Editor() )->enqueue_assets( 'fishotel-tools_page_' . Site_Snippets::MENU_SLUG );
check( 'a CSS snippet gets the CSS mode; with highlighting disabled the plain textarea stays (false)', 'text/css' === $GLOBALS['fh_code_editor_args']['type'] && false === $GLOBALS['fh_scripts']['fishotel-snippets-editor']['localized']['fhSnippetsEditor']['codeEditor'] );
$_GET = array();
$src  = '';
foreach ( glob( dirname( __DIR__ ) . '/*.php' ) as $file ) {
	$src .= (string) file_get_contents( $file );
}
check( 'no REST route anywhere in the section', false === strpos( $src, 'register_rest_route' ) && false === strpos( $src, 'rest_api_init' ) );

echo "10. The tables' install gate\n";
$GLOBALS['wpdb']->queries = array();
$GLOBALS['wpdb']->calls   = array();
$GLOBALS['fh_dbdelta']    = array();
check( 'installed: no install, no query, no dbDelta (front end or admin)', false === Db_Store::maybe_install( false ) && false === Db_Store::maybe_install( true ) && array() === $GLOBALS['wpdb']->queries && array() === $GLOBALS['wpdb']->calls && array() === $GLOBALS['fh_dbdelta'] );
fh_reset_hooks();
Site_Snippets::boot();
check( 'section boot (admin): no install query either, and the editor\'s handlers are hooked', array() === $GLOBALS['fh_dbdelta'] && false !== has_action( 'admin_post_' . Editor::SAVE_ACTION ) && false !== has_action( 'wp_ajax_' . Editor::TOGGLE_ACTION ) && false !== has_action( 'admin_post_' . Editor::UNDELETE_ACTION ) && false !== has_action( 'admin_post_' . Editor::CSV_ACTION ) );
delete_option( Db_Store::DB_VERSION_OPTION );
delete_option( Db_Snippets::OPTION_ACTIVE );
check( 'not installed: the first boot installs the three tables', true === Db_Store::maybe_install( false ) && 3 === count( $GLOBALS['fh_dbdelta'] ) && Db_Store::installed() && true === $GLOBALS['fh_autoload'][ Db_Store::DB_VERSION_OPTION ] );
check( '… with the slug unique, the revision version unique per snippet, the log keyed for its filters', false !== strpos( $GLOBALS['fh_dbdelta'][0], 'UNIQUE KEY slug (slug)' ) && false !== strpos( $GLOBALS['fh_dbdelta'][1], 'UNIQUE KEY snippet_version (snippet_id,version)' ) && false !== strpos( $GLOBALS['fh_dbdelta'][2], 'KEY action (action)' ) && false !== strpos( $GLOBALS['fh_dbdelta'][0], 'code longtext NOT NULL' ) );
$types = array();
foreach ( $GLOBALS['fh_dbdelta'] as $statement ) {
	preg_match( '|\((.*)\)|ms', $statement, $body );
	foreach ( explode( "\n", trim( $body[1] ) ) as $line ) {
		$line = trim( $line, " \t\n\r\0\x0B," );
		// dbDelta's own reading of a column line, then its comparison with DESCRIBE: Type !== $fieldtype.
		if ( preg_match( '|^`?(\w+)`? ([^ ]*( unsigned)?)|i', $line, $m ) && ! in_array( strtolower( $m[1] ), array( 'primary', 'unique', 'key', 'index' ), true ) ) {
			$types[] = $m[2];
		}
	}
}
$describe = array( 'bigint(20) unsigned', 'varchar(64)', 'varchar(191)', 'varchar(8)', 'varchar(20)', 'varchar(60)', 'varchar(45)', 'varchar(100)', 'varchar(255)', 'text', 'longtext', 'int(11)', 'int(10) unsigned', 'tinyint(1)', 'datetime' );
check( '… every column type exactly as MySQL 5.7 / MariaDB report it (dbDelta compares case-sensitively: anything else ALTERs the column on every install)', 33 === count( $types ) && array() === array_diff( $types, $describe ), json_encode( array_diff( $types, $describe ) ) );
check( '… and the active option exists, empty and autoloaded (the loader reads it on every request)', array() === get_option( Db_Snippets::OPTION_ACTIVE ) && true === $GLOBALS['fh_autoload'][ Db_Snippets::OPTION_ACTIVE ] );
$GLOBALS['wpdb']->tables     = array();
$GLOBALS['fh_dbdelta_fail']  = true;
$GLOBALS['fh_dbdelta']       = array();
check( 'a failed install records a marker', false === Db_Store::install( true ) && 0 === strpos( (string) get_option( Db_Store::DB_VERSION_OPTION ), 'failed:' . Db_Store::DB_VERSION . ':' ) );
$GLOBALS['fh_dbdelta'] = array();
check( '… and is not retried on the front end, nor in wp-admin within the hour', false === Db_Store::maybe_install( false ) && false === Db_Store::maybe_install( true ) && array() === $GLOBALS['fh_dbdelta'] );
update_option( Db_Store::DB_VERSION_OPTION, 'failed:' . Db_Store::DB_VERSION . ':' . ( time() - 7200 ), true );
check( '… an hour later, in wp-admin only', false === Db_Store::maybe_install( false ) && true === Db_Store::maybe_install( true ) && 3 === count( $GLOBALS['fh_dbdelta'] ) );
$html = fh_page( array( 'tab' => 'mine' ) );
check( 'My snippets says the tables are missing and why', false !== strpos( $html, 'The snippet tables are not installed.' ) && false !== strpos( $html, 'Creating them failed' ) );
$GLOBALS['fh_dbdelta_fail'] = false;
Site_Snippets::activate();
check( 'plugin activation installs past the gate', Db_Store::installed() );

echo "11. DISALLOW_FILE_EDIT (last: it cannot be undone)\n";
define( 'DISALLOW_FILE_EDIT', true );
$html = fh_page( array( 'tab' => 'mine' ) );
check( 'the screens are read only and say why', false !== strpos( $html, 'DISALLOW_FILE_EDIT is true' ) && false === strpos( $html, 'Add new' ) );
$before = count( $store->log );
$halt   = fh_call( 'handle_save', fh_form( array( 'slug' => 'late' ) ) );
check( 'a save by the named editor is refused and logged', $halt instanceof RuntimeException && null === $store->get_by_slug( 'late' ) && $before + 1 === count( $store->log ) && false !== strpos( $store->log_rows( array(), 1 )[0]['summary'], 'DISALLOW_FILE_EDIT' ) );

Catalog::use_dir( null );
fh_finish();
