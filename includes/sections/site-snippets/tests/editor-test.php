<?php
/**
 * Editor pipeline tests — the save path on the in-memory store: a refused
 * save writes nothing (invalid fields, syntax, clashes, slug taken or used
 * by a GitHub file, too big, a failing test run — which writes only its
 * test_failed log row —, a database failure part-way, which rolls back), a
 * good save writes the row + one revision + one log row + the active
 * option, edits with the version lock, the switch, restore from history,
 * delete keeping revisions and log rows, restore after a delete, export,
 * denied, a save whose test run never came back, and the store file itself
 * (no code path updates or deletes a log row).
 *
 * The test run here is a stand-in; db-run-test.php runs the real one.
 *
 * Run from the plugin root:
 *   php includes/sections/site-snippets/tests/editor-test.php
 *
 * @package FisHotel\Misc\Sections\Site_Snippets
 */

require_once __DIR__ . '/wp-stubs.php';
require_once __DIR__ . '/fake-db-store.php';

use FisHotel\Misc\Sections\Site_Snippets\Catalog;
use FisHotel\Misc\Sections\Site_Snippets\Db_Snippets;
use FisHotel\Misc\Sections\Site_Snippets\Editor;
use FisHotel\Misc\Sections\Site_Snippets\Editor_Rules;
use FisHotel\Misc\Sections\Site_Snippets\Header;
use FisHotel\Misc\Sections\Site_Snippets\Tests\Fake_Db_Store;

$GLOBALS['fh_files'] = Catalog::build(
	array(
		'from-github.php' => fh_php_snippet( 'From GitHub', 'function fh_gh_func() {}' ),
		'broken-file.php' => "<?php\n// no header\n",
	)
);

/**
 * A fresh editor on a fresh (or given) store; everything it does is
 * recorded in $GLOBALS['fh_rec'].
 */
function fh_editor( $store = null, array $deps = array() ) {
	$GLOBALS['fh_rec'] = array(
		'tests'  => array(),
		'before' => array(),
		'emit'   => array(),
		'active' => array(),
		'order'  => array(),
	);
	$GLOBALS['fh_clock'] = isset( $GLOBALS['fh_clock'] ) ? $GLOBALS['fh_clock'] : strtotime( '2026-10-18 09:00:00 UTC' );

	return new Editor(
		$store ? $store : new Fake_Db_Store(),
		array_merge(
			array(
				'files'        => static function () {
					return $GLOBALS['fh_files'];
				},
				'test_run'     => static function ( array $entry ) {
					$GLOBALS['fh_rec']['tests'][] = $entry;
					$GLOBALS['fh_rec']['order'][] = 'test';
					return false !== strpos( $entry['code'], 'THROWS' ) ? 'LogicException: boom (line 1)' : '';
				},
				'actor'        => array(
					'user_id'       => 7,
					'user_login'    => 'jeff',
					'remote_addr'   => '203.0.113.9',
					'forwarded_for' => '198.51.100.7',
					'user_agent'    => 'Test browser',
				),
				'now'          => static function () {
					$GLOBALS['fh_clock'] += 60;
					return gmdate( 'Y-m-d H:i:s', $GLOBALS['fh_clock'] );
				},
				'emit'         => static function ( $level, $message, $context ) {
					$GLOBALS['fh_rec']['emit'][] = array( $level, $message, $context );
				},
				'write_active' => static function ( array $map ) {
					$GLOBALS['fh_rec']['active'][] = $map;
					$GLOBALS['fh_rec']['order'][]  = 'active';
				},
				'before_test'  => static function ( array $fields, $id ) {
					$GLOBALS['fh_rec']['before'][] = array( $fields, $id );
					$GLOBALS['fh_rec']['order'][]  = 'draft';
				},
			),
			$deps
		)
	);
}

function fh_input( array $over = array() ) {
	return array_merge(
		array(
			'slug'        => 'hide-bar',
			'name'        => 'Hide the bar',
			'description' => 'For customers.',
			'type'        => 'php',
			'scope'       => 'global',
			'priority'    => '10',
			'active'      => '1',
			'code'        => "<?php\nadd_filter( 'show_admin_bar', '__return_false' );\n",
		),
		$over
	);
}

/** Nothing but (optionally) these log actions was written. */
function fh_nothing_written( Fake_Db_Store $store, array $log_actions = array() ) {
	return array() === $store->snippets && array() === $store->revisions && $log_actions === $store->actions() && array() === $GLOBALS['fh_rec']['active'];
}

echo "1. A good save\n";
$store  = new Fake_Db_Store();
$editor = fh_editor( $store );
$r      = $editor->save( fh_input() );
$row    = $store->get( $r['id'] );
check( 'created, version 1, with a message saying the test run passed', $r['ok'] && 'create' === $r['code'] && 1 === $r['version'] && false !== strpos( $r['message'], 'The test run passed' ), $r['message'] );
check( 'the row: fields, code without <?php, who and when', 'hide-bar' === $row['slug'] && "add_filter( 'show_admin_bar', '__return_false' );\n" === $row['code'] && 1 === $row['active'] && 7 === $row['created_by'] && 7 === $row['updated_by'] && '' !== $row['created_at'] && null === $row['exported_at'] );
$rev = $store->last_revision( $r['id'] );
check( 'one revision: version 1, the code, the meta as JSON', 1 === count( $store->revisions ) && 1 === $rev['version'] && $row['code'] === $rev['code'] && array( 'name' => 'Hide the bar', 'description' => 'For customers.', 'type' => 'php', 'scope' => 'global', 'priority' => 10, 'active' => 1 ) === json_decode( $rev['meta'], true ) && 7 === $rev['user_id'] );
$log = array_values( $store->log );
check( 'one log row: create, with who, from where, and a summary without the code', array( 'create' ) === $store->actions() && 'hide-bar' === $log[0]['slug'] && $r['id'] === $log[0]['snippet_id'] && 'jeff' === $log[0]['user_login'] && 7 === $log[0]['user_id'] && '203.0.113.9' === $log[0]['remote_addr'] && '198.51.100.7' === $log[0]['forwarded_for'] && 'Test browser' === $log[0]['user_agent'] && 0 === strpos( $log[0]['summary'], 'v1 — created: php / global' ) && false === strpos( $log[0]['summary'], 'show_admin_bar' ) );
check( 'the active option is rebuilt with it (written once, after the transaction)', 1 === count( $GLOBALS['fh_rec']['active'] ) && array( 'hide-bar' ) === array_keys( $GLOBALS['fh_rec']['active'][0] ) && $row['code'] === $GLOBALS['fh_rec']['active'][0]['hide-bar']['code'] && array( 'begin', 'commit' ) === $store->transactions );
check( 'tested ONCE, before anything was written, after the draft was kept', 1 === count( $GLOBALS['fh_rec']['tests'] ) && array( 'draft', 'test', 'active' ) === $GLOBALS['fh_rec']['order'] && 'db:hide-bar' === $GLOBALS['fh_rec']['tests'][0]['key'] && $row['code'] === $GLOBALS['fh_rec']['tests'][0]['code'] && 1 === $GLOBALS['fh_rec']['tests'][0]['version'] );
check( 'sent to the Health log (fishotel_log): info, source text and context', 1 === count( $GLOBALS['fh_rec']['emit'] ) && 'info' === $GLOBALS['fh_rec']['emit'][0][0] && 0 === strpos( $GLOBALS['fh_rec']['emit'][0][1], 'Snippet editor: create hide-bar by jeff — v1' ) && 'hide-bar' === $GLOBALS['fh_rec']['emit'][0][2]['slug'] );

echo "2. A refused save writes nothing\n";
$cases = array(
	'invalid fields'           => array( fh_input( array( 'slug' => 'Bad Slug', 'name' => '' ) ), 'invalid', array() ),
	'a syntax error'           => array( fh_input( array( 'code' => "function ( {\n" ) ), 'syntax', array() ),
	'a clash with a file'      => array( fh_input( array( 'code' => 'function fh_gh_func() {}' ) ), 'clash', array() ),
	'the slug of a GitHub file' => array( fh_input( array( 'slug' => 'from-github' ) ), 'slug', array() ),
	'the slug of a broken file' => array( fh_input( array( 'slug' => 'broken-file' ) ), 'slug', array() ),
	'a failing test run'       => array( fh_input( array( 'code' => "// THROWS\n\$x = 1;" ) ), 'test_failed', array( 'test_failed' ) ),
);
foreach ( $cases as $label => $case ) {
	$store  = new Fake_Db_Store();
	$editor = fh_editor( $store );
	$r      = $editor->save( $case[0] );
	check( $label . ': refused (' . $case[1] . '), nothing written' . ( $case[2] ? ' but its test_failed log row' : '' ), false === $r['ok'] && $case[1] === $r['code'] && fh_nothing_written( $store, $case[2] ), $r['code'] . ' ' . $r['message'] . ' ' . json_encode( $store->actions() ) );
}
check( 'invalid fields come back per field', isset( $r['errors'] ) && is_array( $r['errors'] ) );
$r = fh_editor( new Fake_Db_Store() )->save( fh_input( array( 'slug' => 'Bad Slug', 'name' => '' ) ) );
check( '… slug and name marked', isset( $r['errors']['slug'], $r['errors']['name'] ) );
$r = fh_editor( new Fake_Db_Store() )->save( fh_input( array( 'code' => "\n\nfunction ( {\n" ) ) );
check( 'the syntax error names the line of the code as typed (after the stripped tag)', false !== strpos( $r['message'], 'PHP syntax error on line 3' ), $r['message'] );
$store  = new Fake_Db_Store();
$editor = fh_editor( $store );
$editor->save( fh_input( array( 'code' => "// THROWS\n\$secret_marker = 1;" ) ) );
check( 'test_failed: logged with the message, a warning in the Health log, never the code', array( 'test_failed' ) === $store->actions() && false !== strpos( array_values( $store->log )[0]['summary'], 'test run of v1 failed: LogicException: boom (line 1)' ) && false === strpos( array_values( $store->log )[0]['summary'], 'secret_marker' ) && 'warning' === $GLOBALS['fh_rec']['emit'][0][0] );
$store = new Fake_Db_Store();
fh_editor( $store )->save( fh_input( array( 'slug' => 'first', 'code' => 'function fh_shared_db() {}' ) ) );
$r = fh_editor( $store )->save( fh_input( array( 'slug' => 'second', 'code' => 'function fh_shared_db() {}' ) ) );
check( 'a clash with another database snippet: refused, naming it', false === $r['ok'] && 'clash' === $r['code'] && false !== strpos( $r['message'], 'database snippet first' ) && 1 === count( $store->snippets ), $r['message'] );
$r = fh_editor( $store )->save( fh_input( array( 'slug' => 'first', 'name' => 'Again' ) ) );
check( 'a slug already used by a database snippet: refused', false === $r['ok'] && 'slug' === $r['code'] );
$store = new Fake_Db_Store();
foreach ( range( 1, 4 ) as $n ) {
	fh_editor( $store )->save( fh_input( array( 'slug' => 'big-' . $n, 'type' => 'css', 'scope' => 'front-end', 'code' => '/*' . str_repeat( 'x', 60000 ) . '*/' ) ) );
}
$r = fh_editor( $store )->save( fh_input( array( 'slug' => 'big-5', 'type' => 'css', 'scope' => 'front-end', 'code' => '/*' . str_repeat( 'x', 60000 ) . '*/' ) ) );
check( 'all active snippets together over the cap: refused (the option is autoloaded)', false === $r['ok'] && 'too_big' === $r['code'] && 4 === count( $store->snippets ), $r['message'] );
$r = fh_editor( $store )->save( fh_input( array( 'slug' => 'big-5', 'type' => 'css', 'scope' => 'front-end', 'active' => '', 'code' => '/*' . str_repeat( 'x', 60000 ) . '*/' ) ) );
check( '… the same snippet switched off is fine', $r['ok'] );
foreach ( array( 'insert', 'add_revision', 'append_log' ) as $method ) {
	$store                  = new Fake_Db_Store();
	$store->fail[ $method ] = true;
	$r                      = fh_editor( $store )->save( fh_input() );
	check( 'a database failure at ' . $method . ': rolled back, nothing kept, the active option untouched', false === $r['ok'] && 'db' === $r['code'] && array() === $store->snippets && array() === $store->revisions && array() === $store->log && array() === $GLOBALS['fh_rec']['active'] && array( 'begin', 'rollback' ) === $store->transactions, $r['message'] );
}

echo "3. Editing\n";
$store  = new Fake_Db_Store();
$editor = fh_editor( $store );
$id     = $editor->save( fh_input() )['id'];
$r      = fh_editor( $store )->save( fh_input( array( 'name' => 'Hide it', 'scope' => 'admin', 'code' => "add_filter( 'show_admin_bar', '__return_false', 20 );", 'base_version' => '1', 'slug' => 'ignored-on-edit' ) ), $id );
$row    = $store->get( $id );
check( 'saved as version 2; the slug never changes', $r['ok'] && 'update' === $r['code'] && 2 === $row['version'] && 'hide-bar' === $row['slug'] && 'admin' === $row['scope'] );
check( 'a second revision; the first kept', 2 === count( $store->revisions ) && 1 === $store->revisions( $id )[1]['version'] && false !== strpos( $store->revisions( $id )[1]['code'], "'__return_false' );" ) );
$log = $store->log_rows( array(), 1 )[0];
check( 'logged as update: what changed, the code hashes', 'update' === $log['action'] && 0 === strpos( $log['summary'], 'v2 — name: "Hide the bar" -> "Hide it"; scope: global -> admin; code: ' ), $log['summary'] );
check( 'an active snippet is tested again on save', 1 === count( $GLOBALS['fh_rec']['tests'] ) );
$before_log = $store->log;
$r          = fh_editor( $store )->save( fh_input( array( 'name' => 'Hide it', 'scope' => 'admin', 'code' => "add_filter( 'show_admin_bar', '__return_false', 20 );" ) ), $id );
check( 'nothing changed: nothing written', $r['ok'] && 'unchanged' === $r['code'] && 2 === $store->get( $id )['version'] && $before_log === $store->log && array() === $GLOBALS['fh_rec']['tests'] );
$r = fh_editor( $store )->save( fh_input( array( 'name' => 'Stale', 'base_version' => '1' ) ), $id );
check( 'opened at version 1, now version 2: refused as a conflict, nothing written', false === $r['ok'] && 'conflict' === $r['code'] && 'Hide it' === $store->get( $id )['name'] && $before_log === $store->log );
$r = fh_editor( $store )->save( fh_input( array( 'name' => 'Gone' ) ), 999 );
check( 'an unknown id: refused', false === $r['ok'] && 'not_found' === $r['code'] );
$r = fh_editor( $store )->save( fh_input( array( 'name' => 'Off now', 'scope' => 'admin', 'active' => '', 'code' => "// THROWS\n" ) ), $id );
check( 'saved switched off: no test run (it will not run), so even code that throws is kept', $r['ok'] && array() === $GLOBALS['fh_rec']['tests'] && 0 === $store->get( $id )['active'] && array() === $GLOBALS['fh_rec']['active'][0] );
$store2 = new Fake_Db_Store();
$r      = fh_editor( $store2 )->save( fh_input( array( 'slug' => 'teal', 'type' => 'css', 'scope' => 'front-end', 'code' => "<?php\nbody { color: teal; }" ) ) );
check( 'CSS: never test-run, kept as typed (no tag stripping), in the active option', $r['ok'] && array() === $GLOBALS['fh_rec']['tests'] && "<?php\nbody { color: teal; }" === $store2->get( $r['id'] )['code'] && isset( $GLOBALS['fh_rec']['active'][0]['teal'] ) );
$store3                 = new Fake_Db_Store();
$id3                    = fh_editor( $store3 )->save( fh_input() )['id'];
$store3->snippets[ $id3 ]['version'] = 5; // Another save landed between the read and the write.
$probe                  = new class( $store3, $id3 ) {
	private $s;
	private $id;
	public function __construct( $s, $id ) {
		$this->s  = $s;
		$this->id = $id;
	}
	public function __call( $m, $a ) {
		$out = call_user_func_array( array( $this->s, $m ), $a );
		if ( 'get' === $m && is_array( $out ) ) {
			$out['version'] = 1; // What the editor read.
		}
		return $out;
	}
};
$r = fh_editor( $probe )->save( fh_input( array( 'name' => 'Racing' ) ), $id3 );
check( 'the version lock: a row that moved on meanwhile is not overwritten (rolled back)', false === $r['ok'] && 'db' === $r['code'] && 'Hide the bar' === $store3->get( $id3 )['name'] && array( 'create' ) === $store3->actions() );

echo "4. The switch\n";
$store  = new Fake_Db_Store();
$id     = fh_editor( $store )->save( fh_input() )['id'];
$r      = fh_editor( $store )->set_active( $id, false );
check( 'off: deactivate logged, a revision written, the option emptied, no test run', $r['ok'] && 'deactivate' === $r['code'] && 0 === $store->get( $id )['active'] && 2 === count( $store->revisions ) && array( 'create', 'deactivate' ) === $store->actions() && array() === $GLOBALS['fh_rec']['active'][0] && array() === $GLOBALS['fh_rec']['tests'] );
check( 'the summary says so', 'v2 — switched on -> off' === $store->log_rows( array(), 1 )[0]['summary'] );
$r = fh_editor( $store )->set_active( $id, false );
check( 'off again: nothing written', $r['ok'] && 'unchanged' === $r['code'] && 2 === count( $store->log ) );
$r = fh_editor( $store )->set_active( $id, true );
check( 'on: test-run first, then activate logged and the option rebuilt', $r['ok'] && 'activate' === $r['code'] && 1 === count( $GLOBALS['fh_rec']['tests'] ) && isset( $GLOBALS['fh_rec']['active'][0]['hide-bar'] ) && array( 'create', 'deactivate', 'activate' ) === $store->actions() );
$id2 = fh_editor( $store )->save( fh_input( array( 'slug' => 'throws-later', 'active' => '', 'code' => "// THROWS\n" ) ) )['id'];
$r   = fh_editor( $store )->set_active( $id2, true );
check( 'on, but the test run throws: refused, stays off, test_failed logged', false === $r['ok'] && 'test_failed' === $r['code'] && 0 === $store->get( $id2 )['active'] && 'test_failed' === $store->log_rows( array(), 1 )[0]['action'] );
$store->snippets[] = array( 'id' => 50, 'slug' => 'from-github', 'name' => 'Old copy', 'description' => '', 'type' => 'php', 'scope' => 'global', 'priority' => 10, 'active' => 0, 'code' => '$x = 1;', 'version' => 1, 'created_by' => 7, 'created_at' => '', 'updated_by' => 7, 'updated_at' => '', 'exported_at' => '2026-10-17 10:00:00' );
$store->snippets[50] = array_pop( $store->snippets );
$r = fh_editor( $store )->set_active( 50, true );
check( 'on, but a GitHub file supersedes it: refused (it would never run)', false === $r['ok'] && 'superseded' === $r['code'] && false !== strpos( $r['message'], 'from-github.php' ) );
$r = fh_editor( $store )->save( fh_input( array( 'name' => 'Old copy edited', 'active' => '1', 'code' => '$x = 2;' ) ), 50 );
check( 'editing a superseded copy (on) is allowed, never test-run, and says it never runs', $r['ok'] && array() === $GLOBALS['fh_rec']['tests'] && false !== strpos( $r['message'], 'supersedes it' ) );
check( 'superseded copies are left out of the clash scan of others', fh_editor( $store )->save( fh_input( array( 'slug' => 'other', 'code' => 'function fh_from_old_copy() {}' ) ) )['ok'] );

echo "5. History: restore\n";
$store = new Fake_Db_Store();
$id    = fh_editor( $store )->save( fh_input( array( 'code' => "\$v = 1;\n" ) ) )['id'];
fh_editor( $store )->save( fh_input( array( 'name' => 'Second', 'priority' => '3', 'code' => "\$v = 2;\n" ) ), $id );
$v1 = $store->revisions( $id )[1];
$r  = fh_editor( $store )->restore_revision( $id, $v1['id'], 2 );
$row = $store->get( $id );
check( 'restore writes a NEW version with the old code and fields', $r['ok'] && 'restore' === $r['code'] && 3 === $row['version'] && "\$v = 1;\n" === $row['code'] && 'Hide the bar' === $row['name'] && 10 === $row['priority'] && 3 === count( $store->revisions ) );
check( 'logged as restore, naming the version', 0 === strpos( $store->log_rows( array(), 1 )[0]['summary'], 'v3 — restored from v1; name: "Second" -> "Hide the bar"; priority: 3 -> 10; code: ' ) );
check( 'test-run like a save (it is on)', 1 === count( $GLOBALS['fh_rec']['tests'] ) && "\$v = 1;\n" === $GLOBALS['fh_rec']['tests'][0]['code'] );
fh_editor( $store )->set_active( $id, false );
$r = fh_editor( $store )->restore_revision( $id, $store->revisions( $id )[2]['id'] );
check( 'the on / off state is kept: restoring an "on" version of an off snippet leaves it off, untested', $r['ok'] && 0 === $store->get( $id )['active'] && array() === $GLOBALS['fh_rec']['tests'] );
$r = fh_editor( $store )->restore_revision( $id, $store->revisions( $id )[0]['id'] );
check( 'restoring the current version: nothing to do', $r['ok'] && 'unchanged' === $r['code'] );
$r = fh_editor( $store )->restore_revision( $id, $v1['id'], 1 );
check( 'a stale history page: refused', false === $r['ok'] && 'conflict' === $r['code'] );
$other = fh_editor( $store )->save( fh_input( array( 'slug' => 'unrelated', 'code' => '$u = 1;' ) ) )['id'];
check( 'a revision of another snippet: refused', false === fh_editor( $store )->restore_revision( $id, $store->last_revision( $other )['id'] )['ok'] );
$id_t = fh_editor( $store )->save( fh_input( array( 'slug' => 'throw-hist', 'active' => '', 'code' => "// THROWS\n" ) ) )['id'];
fh_editor( $store )->save( fh_input( array( 'slug' => 'throw-hist', 'active' => '1', 'code' => "\$fine = 1;\n" ) ), $id_t );
$r = fh_editor( $store )->restore_revision( $id_t, $store->revisions( $id_t )[1]['id'] );
check( 'restoring a version that throws onto an active snippet: refused by the test run, nothing written', false === $r['ok'] && 'test_failed' === $r['code'] && "\$fine = 1;\n" === $store->get( $id_t )['code'] );

echo "6. Delete, and restore after a delete\n";
$store = new Fake_Db_Store();
$id    = fh_editor( $store )->save( fh_input() )['id'];
fh_editor( $store )->save( fh_input( array( 'name' => 'Edited' ) ), $id );
$last_code  = $store->get( $id )['code'];
$log_before = $store->log;
$r          = fh_editor( $store )->delete( $id );
check( 'the row is gone', $r['ok'] && null === $store->get( $id ) );
check( 'its revisions stay', 2 === count( $store->revisions( $id ) ) );
check( 'its log rows stay, unchanged; the delete is appended with the last code hash', array_slice( $store->log, 0, count( $log_before ), true ) === $log_before && 'delete' === $store->log_rows( array(), 1 )[0]['action'] && 'deleted at v2; last code ' . Editor_Rules::code_label( $last_code ) . '; revisions and log kept' === $store->log_rows( array(), 1 )[0]['summary'] );
check( 'the active option no longer holds it; a Health warning', array() === $GLOBALS['fh_rec']['active'][0] && 'warning' === $GLOBALS['fh_rec']['emit'][0][0] );
check( 'deleting it again: refused', false === fh_editor( $store )->delete( $id )['ok'] );
$delete_log = $store->log_rows( array( 'action' => 'delete' ), 1 )[0]['id'];
$r          = fh_editor( $store )->restore_deleted( $delete_log );
$row        = $store->get( $id );
check( 'restored from its last revision: the same id and slug, switched OFF, version 3', $r['ok'] && is_array( $row ) && 'hide-bar' === $row['slug'] && 'Edited' === $row['name'] && $last_code === $row['code'] && 0 === $row['active'] && 3 === $row['version'] && 3 === count( $store->revisions( $id ) ) );
check( 'logged as restore after a delete, never test-run (it is off)', 0 === strpos( $store->log_rows( array(), 1 )[0]['summary'], 'v3 — restored from v2 after a delete, switched off' ) && array() === $GLOBALS['fh_rec']['tests'] );
check( 'restoring it twice: refused (it exists)', 'exists' === fh_editor( $store )->restore_deleted( $delete_log )['code'] );
fh_editor( $store )->delete( $id );
fh_editor( $store )->save( fh_input( array( 'name' => 'Squatter', 'code' => '$s = 1;' ) ) );
check( 'restoring while another snippet took the slug: refused', 'slug' === fh_editor( $store )->restore_deleted( $store->log_rows( array( 'action' => 'delete' ), 1 )[0]['id'] )['code'] );
check( 'a log row that is not a delete: refused', 'no_delete' === fh_editor( $store )->restore_deleted( $store->log_rows( array( 'action' => 'create' ), 1 )[0]['id'] )['code'] );

echo "7. Export, denied, a test run that never came back\n";
$store = new Fake_Db_Store();
$id    = fh_editor( $store )->save( fh_input() )['id'];
$r     = fh_editor( $store )->export( $id, '2026-10-18' );
check( 'export: the file (header + guard + code) under <slug>.php', $r['ok'] && 'hide-bar.php' === $r['filename'] && Header::parse( 'hide-bar.php', $r['file'] )['ok'] && false !== strpos( $r['file'], "defined( 'ABSPATH' ) || exit;\n\nadd_filter( 'show_admin_bar', '__return_false' );" ) );
check( 'exported_at set and export logged', null !== $store->get( $id )['exported_at'] && 0 === strpos( $store->log_rows( array(), 1 )[0]['summary'], 'exported v1 as hide-bar.php (Default: on)' ) );
check( 'export does not change the snippet\'s version', 1 === $store->get( $id )['version'] && 1 === count( $store->revisions ) );
check( 'export of an unknown id: refused', false === fh_editor( $store )->export( 999, '2026-10-18' )['ok'] );
$editor = fh_editor( $store );
check( 'denied: one log row with what was tried and why, and a Health warning', $editor->deny( 'save', 'hide-bar', $id, 'Your account is not named in FISHOTEL_SNIPPETS_EDITORS in wp-config.php.' ) && 'denied' === $store->log_rows( array(), 1 )[0]['action'] && 'refused save: Your account is not named in FISHOTEL_SNIPPETS_EDITORS in wp-config.php.' === $store->log_rows( array(), 1 )[0]['summary'] && 'warning' === $GLOBALS['fh_rec']['emit'][0][0] );
check( 'safe mode logged', fh_editor( $store )->log_safe_mode( 'query' ) && 'safe_mode' === $store->log_rows( array(), 1 )[0]['action'] && '' === $store->log_rows( array(), 1 )[0]['slug'] && null === $store->log_rows( array(), 1 )[0]['snippet_id'] );
$msg = fh_editor( $store )->settle_dead_test( array( 'id' => $id, 'version' => 2, 'fields' => array_merge( fh_input(), array( 'code' => 'function fh_two( $a, $a ) {}' ) ) ) );
check( 'a save whose test run died: logged as test_failed afterwards, without the code', 'test_failed' === $store->log_rows( array(), 1 )[0]['action'] && false !== strpos( $store->log_rows( array(), 1 )[0]['summary'], 'fatal error PHP cannot catch' ) && false === strpos( $store->log_rows( array(), 1 )[0]['summary'], 'fh_two' ) && 0 === strpos( $msg, 'Refused: the test run ended the save request' ) );

echo "8. The active option is never emptied by an error; the log is append-only\n";
$store = new Fake_Db_Store();
fh_editor( $store )->save( fh_input() );
$store->fail['all'] = true;
check( 'the table cannot be read: rebuild skipped, the option not touched', null === fh_editor( $store )->rebuild_active() && array() === $GLOBALS['fh_rec']['active'] );
$src = (string) file_get_contents( dirname( __DIR__ ) . '/class-db-store.php' );
preg_match_all( '/\$wpdb->(update|delete|replace|query)\(\s*([^;]*?);/s', $src, $calls, PREG_SET_ORDER );
$bad = array();
foreach ( $calls as $call ) {
	if ( 'replace' === $call[1] ) {
		$bad[] = $call[0];
	} elseif ( ( 'update' === $call[1] || 'delete' === $call[1] ) && 0 !== strpos( trim( $call[2] ), "self::table( 'snippets' )" ) && false === strpos( $call[2], "self::table( 'snippets' )," ) ) {
		$bad[] = $call[0];
	} elseif ( 'query' === $call[1] && 0 !== strpos( trim( $call[2] ), '$sql )' ) ) {
		$bad[] = $call[0];
	}
}
check( 'the store updates / deletes only the snippets table; its one raw query runs only fixed statements', array() === $bad && 3 === count( array_filter( $calls, static function ( $c ) { return 'update' === $c[1] || 'delete' === $c[1]; } ) ), json_encode( $bad ) );
preg_match_all( "/this->raw\\(\\s*'([^']+)'\\s*\\)/", $src, $raws );
check( '… which are START TRANSACTION, COMMIT and ROLLBACK', array( 'START TRANSACTION', 'COMMIT', 'ROLLBACK' ) === $raws[1] );
check( 'no SQL text updates or deletes from the log table', 0 === preg_match( '/(UPDATE|DELETE\s+FROM|TRUNCATE|DROP)[^;]{0,80}snippet_log/i', $src ) && 1 === preg_match_all( "/self::table\\( 'snippet_log' \\), \\\$data/", $src ) );
$store = new Fake_Db_Store();
$snap  = array();
$id    = fh_editor( $store )->save( fh_input() )['id'];
$snap  = $store->log;
fh_editor( $store )->save( fh_input( array( 'name' => 'B' ) ), $id );
fh_editor( $store )->set_active( $id, false );
fh_editor( $store )->delete( $id );
check( 'after edits, a switch and a delete every earlier log row is byte-for-byte the same', array_slice( $store->log, 0, count( $snap ), true ) === $snap && array( 'create', 'update', 'deactivate', 'delete' ) === $store->actions() );

fh_finish();
