<?php
/**
 * Editor rules tests — the pure half of the wp-admin snippet editor: who
 * may write (the constant, by login, by id, not listed, no manage_options,
 * DISALLOW_FILE_EDIT, multisite super admin), the field and slug
 * validation, the opening-tag stripping, the parse check with stored-code
 * line numbers, clashes between database and file snippets, superseded by
 * a file, the merged run order, the active option (only active ones, its
 * size), the history / audit summaries (never the code), the CSV guard,
 * the request fields, and the export file (header + guard + code, read back
 * by the Header parser and the Symbols scan, php -l clean).
 *
 * Run from the plugin root:
 *   php includes/sections/site-snippets/tests/editor-rules-test.php
 *
 * @package FisHotel\Misc\Sections\Site_Snippets
 */

require_once __DIR__ . '/wp-stubs.php';

use FisHotel\Misc\Sections\Site_Snippets\Db_Snippets;
use FisHotel\Misc\Sections\Site_Snippets\Editor_Rules;
use FisHotel\Misc\Sections\Site_Snippets\Export;
use FisHotel\Misc\Sections\Site_Snippets\Header;
use FisHotel\Misc\Sections\Site_Snippets\Rules;
use FisHotel\Misc\Sections\Site_Snippets\Catalog;
use FisHotel\Misc\Sections\Site_Snippets\Symbols;

/**
 * The permission decision with defaults for an allowed administrator.
 */
function fh_perm( array $who ) {
	return Editor_Rules::permission(
		array_merge(
			array(
				'constant'           => 'jeff',
				'user_id'            => 7,
				'user_login'         => 'jeff',
				'can_manage_options' => true,
				'multisite'          => false,
				'super_admin'        => false,
				'disallow_file_edit' => false,
			),
			$who
		)
	);
}

function fh_valid( array $input, $current = null ) {
	return Editor_Rules::validate(
		array_merge(
			array(
				'slug'        => 'hide-bar',
				'name'        => 'Hide the bar',
				'description' => 'For customers.',
				'type'        => 'php',
				'scope'       => 'global',
				'priority'    => '10',
				'active'      => '1',
				'code'        => "add_filter( 'show_admin_bar', '__return_false' );",
			),
			$input
		),
		$current
	);
}

function fh_decl_names( array $decls ) {
	return implode(
		',',
		array_map(
			static function ( $d ) {
				return $d['kind'] . ':' . $d['display'] . ( $d['guarded'] ? '?' : '' );
			},
			$decls
		)
	);
}

echo "1. Who may write\n";
check( 'a listed administrator (by login) may', true === fh_perm( array() )['allowed'] && 'ok' === fh_perm( array() )['reason'] );
check( 'the constant missing: nobody, and the message names it', 'no_constant' === fh_perm( array( 'constant' => null ) )['reason'] && false !== strpos( fh_perm( array( 'constant' => null ) )['message'], 'FISHOTEL_SNIPPETS_EDITORS' ) );
check( 'an empty / comma-only constant: nobody', 'empty_constant' === fh_perm( array( 'constant' => '' ) )['reason'] && 'empty_constant' === fh_perm( array( 'constant' => ' , ,' ) )['reason'] );
check( 'listed by user ID', fh_perm( array( 'constant' => 'anna, 7' ) )['allowed'] );
check( 'listed by login, any case, spaces around', fh_perm( array( 'constant' => ' Anna ,  JEFF ' ) )['allowed'] );
check( 'an entry of digits is an ID, never a login', 'not_listed' === fh_perm( array( 'constant' => '12', 'user_login' => '12', 'user_id' => 7 ) )['reason'] );
check( 'not listed: refused', 'not_listed' === fh_perm( array( 'constant' => 'anna,8' ) )['reason'] );
check( 'listed but without manage_options: refused', 'no_capability' === fh_perm( array( 'can_manage_options' => false ) )['reason'] );
check( 'DISALLOW_FILE_EDIT: refused, even for a listed administrator', 'file_edit' === fh_perm( array( 'disallow_file_edit' => true ) )['reason'] );
check( 'DISALLOW_FILE_EDIT wins over a missing constant', 'file_edit' === fh_perm( array( 'disallow_file_edit' => true, 'constant' => null ) )['reason'] );
check( 'logged out: refused', 'logged_out' === fh_perm( array( 'user_id' => 0, 'user_login' => '' ) )['reason'] );
check( 'multisite: a listed super admin may', fh_perm( array( 'multisite' => true, 'super_admin' => true ) )['allowed'] );
check( 'multisite: a listed site administrator who is not super admin may not', 'no_capability' === fh_perm( array( 'multisite' => true, 'super_admin' => false, 'can_manage_options' => true ) )['reason'] );
check( 'parse_editors: ids and lower-cased logins, deduplicated, 0 ignored', array( 'ids' => array( 7 ), 'logins' => array( 'jeff', 'anna' ) ) === Editor_Rules::parse_editors( 'Jeff, 7, anna,7, jeff, 0' ) );
check( 'parse_editors: a non-string constant names nobody', array( 'ids' => array(), 'logins' => array() ) === Editor_Rules::parse_editors( array( 'jeff' ) ) );
$fh_nobody = array(
	'ids'    => array(),
	'logins' => array(),
);
check( 'parse_editors: true, false, a float or an object name nobody (cast to a string they would be user #1 or a login)', $fh_nobody === Editor_Rules::parse_editors( true ) && $fh_nobody === Editor_Rules::parse_editors( false ) && $fh_nobody === Editor_Rules::parse_editors( 7.0 ) && $fh_nobody === Editor_Rules::parse_editors( (object) array() ) );
check( 'parse_editors: a whole number is one user ID; 0 or less names nobody', array( 'ids' => array( 7 ), 'logins' => array() ) === Editor_Rules::parse_editors( 7 ) && $fh_nobody === Editor_Rules::parse_editors( 0 ) && $fh_nobody === Editor_Rules::parse_editors( -3 ) );
check( 'define( …, array( \'jeff\' ) ): nobody — not the login "array", not jeff', 'empty_constant' === fh_perm( array( 'constant' => array( 'jeff' ), 'user_login' => 'array', 'user_id' => 50 ) )['reason'] && 'empty_constant' === fh_perm( array( 'constant' => array( 'jeff' ) ) )['reason'] );
check( 'define( …, true ): nobody — not user #1; the message says what the constant must be', 'empty_constant' === fh_perm( array( 'constant' => true, 'user_id' => 1, 'user_login' => 'admin' ) )['reason'] && false !== strpos( fh_perm( array( 'constant' => true ) )['message'], "it must be text such as 'jeff'" ) );
check( 'define( …, 7 ): user #7', fh_perm( array( 'constant' => 7, 'user_login' => 'someone' ) )['allowed'] && 'not_listed' === fh_perm( array( 'constant' => 7, 'user_id' => 8 ) )['reason'] );

echo "2. Fields\n";
$v = fh_valid( array() );
check( 'a good snippet validates', $v['ok'] && 'hide-bar' === $v['fields']['slug'] && 1 === $v['fields']['active'] && 10 === $v['fields']['priority'], json_encode( $v['errors'] ) );
foreach ( array( 'Hide-Bar', 'hide--bar', '-hide', 'hide_bar', 'hide bar', 'héllo', str_repeat( 'a', 65 ) ) as $bad ) {
	check( 'slug "' . $bad . '" refused', isset( fh_valid( array( 'slug' => $bad === 'Hide-Bar' ? 'Hide Bar' : $bad ) )['errors']['slug'] ) );
}
check( 'slug: upper case is lower-cased (same rule as a file name)', fh_valid( array( 'slug' => 'Hide-Bar' ) )['ok'] && 'hide-bar' === fh_valid( array( 'slug' => 'Hide-Bar' ) )['fields']['slug'] );
check( 'slug: empty refused', isset( fh_valid( array( 'slug' => '' ) )['errors']['slug'] ) );
check( 'slug: 64 characters fine', fh_valid( array( 'slug' => str_repeat( 'a', 64 ) ) )['ok'] );
check( 'editing: the slug is kept from the row, whatever is posted', 'kept-slug' === fh_valid( array( 'slug' => 'NOPE nope' ), array( 'slug' => 'kept-slug' ) )['fields']['slug'] && fh_valid( array( 'slug' => 'NOPE nope' ), array( 'slug' => 'kept-slug' ) )['ok'] );
check( 'name required', isset( fh_valid( array( 'name' => "  \n " ) )['errors']['name'] ) );
check( 'name: line breaks and runs of spaces become one space', 'Two lines' === fh_valid( array( 'name' => "Two\r\n  lines" ) )['fields']['name'] );
check( 'name longer than 120 refused', isset( fh_valid( array( 'name' => str_repeat( 'n', 121 ) ) )['errors']['name'] ) && fh_valid( array( 'name' => str_repeat( 'é', 120 ) ) )['ok'] );
check( 'name / description cannot close the header (*/ or -->)', isset( fh_valid( array( 'name' => 'a */ b' ) )['errors']['name'] ) && isset( fh_valid( array( 'description' => 'x --> y' ) )['errors']['description'] ) );
check( 'description longer than 500 refused (not cut silently)', isset( fh_valid( array( 'description' => str_repeat( 'd', 501 ) ) )['errors']['description'] ) );
check( 'type must be php / css / js / html', isset( fh_valid( array( 'type' => 'sh' ) )['errors']['type'] ) && fh_valid( array( 'type' => 'CSS', 'scope' => 'admin', 'code' => 'a{}' ) )['ok'] );
check( 'scope must fit the type', isset( fh_valid( array( 'type' => 'css', 'scope' => 'global', 'code' => 'a{}' ) )['errors']['scope'] ) && isset( fh_valid( array( 'type' => 'html', 'scope' => 'admin', 'code' => 'x' ) )['errors']['scope'] ) );
check( 'an empty scope takes the type\'s default', 'front-end' === fh_valid( array( 'type' => 'js', 'scope' => '', 'code' => 'x()' ) )['fields']['scope'] && 'shortcode' === fh_valid( array( 'type' => 'html', 'scope' => '', 'code' => 'x' ) )['fields']['scope'] );
check( 'every scope of every type is accepted', array_reduce( array_keys( Header::SCOPES ), static function ( $ok, $type ) { foreach ( Header::SCOPES[ $type ] as $scope ) { $ok = $ok && fh_valid( array( 'type' => $type, 'scope' => $scope, 'code' => 'x' ) )['ok']; } return $ok; }, true ) );
check( 'priority: whole numbers only, default 10, negative fine', isset( fh_valid( array( 'priority' => '1.5' ) )['errors']['priority'] ) && isset( fh_valid( array( 'priority' => '1234567' ) )['errors']['priority'] ) && 10 === fh_valid( array( 'priority' => '' ) )['fields']['priority'] && -5 === fh_valid( array( 'priority' => '-5' ) )['fields']['priority'] );
check( 'active: 1 / on / true = on, anything else off', 1 === fh_valid( array( 'active' => 'on' ) )['fields']['active'] && 0 === fh_valid( array( 'active' => '' ) )['fields']['active'] && 0 === fh_valid( array( 'active' => '2' ) )['fields']['active'] && 0 === fh_valid( array( 'active' => array( 1 ) ) )['fields']['active'] );
check( 'code required', isset( fh_valid( array( 'code' => " \n\t" ) )['errors']['code'] ) );
check( 'code over 64 KB refused', isset( fh_valid( array( 'code' => '//' . str_repeat( 'x', Editor_Rules::MAX_CODE_BYTES ) ) )['errors']['code'] ) );
check( 'several errors at once, each on its field', array( 'slug', 'name', 'priority' ) === array_keys( fh_valid( array( 'slug' => 'A B', 'name' => '', 'priority' => 'x' ) )['errors'] ) );

echo "3. Opening tag, line breaks\n";
check( 'a leading <?php and its line break are stripped', "echo 1;\n" === Editor_Rules::strip_open_tag( "<?php\necho 1;\n" ) );
check( '… after blank lines, in any case, with trailing spaces', "echo 1;" === Editor_Rules::strip_open_tag( "\n  <?PHP   \necho 1;" ) );
check( '<?php on the same line as code', "echo 1;" === Editor_Rules::strip_open_tag( '<?php echo 1;' ) );
check( 'only the leading tag; a later one stays', "echo 1; ?>x<?php echo 2;" === Editor_Rules::strip_open_tag( "<?php\necho 1; ?>x<?php echo 2;" ) );
check( '<?phpx is not an opening tag', '<?phpx' === Editor_Rules::strip_open_tag( '<?phpx' ) );
check( 'normalise: BOM gone, CRLF → LF, tag stripped for PHP only', "a();\nb();" === Editor_Rules::normalise_code( 'php', "\xEF\xBB\xBF<?php\r\na();\r\nb();" ) && "<?php\nbody{}" === Editor_Rules::normalise_code( 'css', "<?php\r\nbody{}" ) );
check( 'validate() stores the PHP without its tag', "echo 1;\n" === fh_valid( array( 'code' => "<?php\r\necho 1;\r\n" ) )['fields']['code'] );

echo "4. Parse check, declarations\n";
check( 'clean code parses', '' === Db_Snippets::syntax_error( "function fh_ok() {}\n" )['error'] );
$se = Db_Snippets::syntax_error( "\$a = 1;\nfunction ( {\n" );
check( 'a syntax error is reported with the stored code\'s line number', '' !== $se['error'] && 2 === $se['line'], json_encode( $se ) );
check( 'a ParseError is caught, never thrown', '' !== Db_Snippets::syntax_error( 'if (' )['error'] );
$d = Db_Snippets::declared( "\nfunction fh_a() {}\nif ( ! function_exists( 'fh_b' ) ) { function fh_b() {} }\nclass FH_C {}\n" );
check( 'declarations of stored code, guarded flag, lines of the stored code', 'function:fh_a,function:fh_b?,class:FH_C' === fh_decl_names( $d ) && 2 === $d[0]['line'] && 4 === $d[2]['line'], fh_decl_names( $d ) );

echo "5. Clashes between database and file snippets\n";
$files = Catalog::build(
	array(
		'helpers.php' => fh_php_snippet( 'Helpers', "function fh_helper() {}\nif ( ! function_exists( 'fh_soft' ) ) { function fh_soft() {} }" ),
		'mine.php'    => fh_php_snippet( 'Mine', 'function fh_mine() {}' ),
		'style.css'   => "/**\n * Snippet: Style\n */\nbody{}\n",
	)
);
$c = Editor_Rules::clash( 'new-one', Db_Snippets::declared( 'function fh_helper() {}' ), $files['snippets'], array() );
check( 'a function a file declares: refused, naming the file', false !== strpos( $c, 'function fh_helper() is declared also by the file helpers.php' ), $c );
$c = Editor_Rules::clash( 'new-one', Db_Snippets::declared( 'class FH_Shared {}' ), array(), array( 'other' => Db_Snippets::declared( 'class FH_Shared {}' ) ) );
check( 'a class another database snippet declares: refused, naming it', false !== strpos( $c, 'class FH_Shared is declared also by database snippet other' ), $c );
check( 'guarded on both sides: allowed (a warning for files, not a refusal)', '' === Editor_Rules::clash( 'new-one', Db_Snippets::declared( "if ( ! function_exists( 'fh_soft' ) ) { function fh_soft() {} }" ), $files['snippets'], array() ) );
check( 'guarded here but not there: refused', '' !== Editor_Rules::clash( 'new-one', Db_Snippets::declared( "if ( ! function_exists( 'fh_helper' ) ) { function fh_helper() {} }" ), $files['snippets'], array() ) );
check( 'the file of the SAME slug is left out (it supersedes this copy)', '' === Editor_Rules::clash( 'mine', Db_Snippets::declared( 'function fh_mine() {}' ), $files['snippets'], array() ) );
check( 'the snippet\'s own earlier version is not "another" one', '' === Editor_Rules::clash( 'self', Db_Snippets::declared( 'function fh_self() {}' ), array(), array( 'self' => Db_Snippets::declared( 'function fh_self() {}' ) ) ) );
check( 'twice in its own code: refused', false !== strpos( Editor_Rules::clash( 'twice', Db_Snippets::declared( "function fh_tw() {}\nif ( true ) { function fh_tw() {} }" ), array(), array() ), 'twice in this snippet' ) );
check( 'case-insensitive, as PHP', '' !== Editor_Rules::clash( 'x', Db_Snippets::declared( 'function FH_HELPER() {}' ), $files['snippets'], array() ) );
check( 'nothing declared: nothing to clash', '' === Editor_Rules::clash( 'x', array(), $files['snippets'], array() ) );

echo "6. Superseded, merged order\n";
$index = Catalog::build(
	array(
		'moved.php'  => fh_php_snippet( 'Moved', '$x = 1;' ),
		'broken.php' => "<?php\n\$no = 'header';\n",
		'early.php'  => fh_php_snippet( 'Early', '$x = 1;', array( 'Priority' => '5' ) ),
		'cs.php'     => fh_php_snippet( 'CS', '$x = 1;', array( 'Origin' => 'Code Snippets #3' ) ),
		'zfile.php'  => fh_php_snippet( 'Z file', 'function fh_z_file() {}' ),
		'style.css'  => "/**\n * Snippet: Style\n */\nbody{}\n",
	)
);
check( 'superseded by a usable file of the slug', 'moved.php' === Db_Snippets::superseded_by( 'moved', $index ) );
check( 'superseded by a broken file of the slug too', 'broken.php' === Db_Snippets::superseded_by( 'broken', $index ) );
check( 'not superseded', '' === Db_Snippets::superseded_by( 'fresh', $index ) );
$active = Db_Snippets::active_option(
	array(
		array( 'id' => 1, 'slug' => 'moved', 'type' => 'php', 'scope' => 'global', 'priority' => 10, 'active' => 1, 'code' => '$m = 1;', 'version' => 2 ),
		array( 'id' => 2, 'slug' => 'aaa', 'type' => 'php', 'scope' => 'global', 'priority' => 10, 'active' => 1, 'code' => '$a = 1;', 'version' => 1 ),
		array( 'id' => 3, 'slug' => 'first', 'type' => 'php', 'scope' => 'global', 'priority' => 1, 'active' => 1, 'code' => '$f = 1;', 'version' => 1 ),
		array( 'id' => 4, 'slug' => 'clashes', 'type' => 'php', 'scope' => 'global', 'priority' => 10, 'active' => 1, 'code' => 'function fh_z_file() {}', 'version' => 1 ),
		array( 'id' => 5, 'slug' => 'zz-css', 'type' => 'css', 'scope' => 'front-end', 'priority' => 10, 'active' => 1, 'code' => 'p{}', 'version' => 1 ),
		array( 'id' => 6, 'slug' => 'off', 'type' => 'php', 'scope' => 'global', 'priority' => 10, 'active' => 0, 'code' => '$o = 1;', 'version' => 1 ),
	)
)['map'];
$merged = Db_Snippets::merge( $index, $active );
check( 'merge: database snippets keyed db:<slug> beside the files', isset( $merged['db:aaa'], $merged['moved'], $merged['db:moved'] ) && 'db' === $merged['db:aaa']['source'] && 'aaa' === $merged['db:aaa']['slug'] && true === $merged['db:aaa']['default_on'] );
check( 'merge: a copy superseded by its GitHub file is refused (never runs) and says so', 'moved.php' === $merged['db:moved']['superseded'] && false !== strpos( $merged['db:moved']['refused'], 'Superseded by the GitHub file moved.php' ) );
check( 'merge: a file deployed later that declares the same name refuses the database copy, not the file', false !== strpos( $merged['db:clashes']['refused'], 'function fh_z_file() is declared also by the file zfile.php' ) && '' === $merged['zfile']['refused'], $merged['db:clashes']['refused'] );
$twin = Db_Snippets::merge(
	$index,
	Db_Snippets::active_option(
		array(
			array( 'id' => 20, 'slug' => 'twin-a', 'type' => 'php', 'scope' => 'global', 'priority' => 10, 'active' => 1, 'code' => 'function fh_twin() {}', 'version' => 1 ),
			array( 'id' => 21, 'slug' => 'twin-b', 'type' => 'php', 'scope' => 'global', 'priority' => 10, 'active' => 1, 'code' => 'function FH_TWIN() {}', 'version' => 1 ),
			array( 'id' => 22, 'slug' => 'loner', 'type' => 'php', 'scope' => 'global', 'priority' => 10, 'active' => 1, 'code' => 'function fh_loner() {}', 'version' => 1 ),
		)
	)['map']
);
check( 'merge: two database snippets declaring one name are both refused; an unrelated one is not', false !== strpos( $twin['db:twin-a']['refused'], 'also by database snippet twin-b' ) && false !== strpos( $twin['db:twin-b']['refused'], 'also by database snippet twin-a' ) && '' === $twin['db:loner']['refused'] );
check( 'merge: without database snippets the file map is returned untouched', $index['snippets'] === Db_Snippets::merge( $index, array() ) && $index['snippets'] === Db_Snippets::merge( $index, 'garbage' ) );
$plan = Rules::php_plan( $merged, array( 'aaa' => false, 'first' => false, 'early' => true, 'cs' => true ), false );
$order = array_map(
	static function ( $s ) {
		return isset( $s['key'] ) ? $s['key'] : $s['slug'];
	},
	$plan
);
check( 'merged order: priority, then Code Snippets imports, then files before database snippets, then slug; refused / off ones out', array( 'db:first', 'early', 'cs', 'db:aaa' ) === $order, implode( ',', $order ) );
check( '… a stale switch-map entry of a gone file never switches a database snippet of that slug off', in_array( 'db:aaa', $order, true ) );
$out_plan = Rules::output_plan( $merged, array( 'zz-css' => false, 'style' => true ) );
check( 'output plan: a database CSS snippet prints on its slot, whatever the file switch map holds for its slug', isset( $out_plan[ Rules::SLOT_FRONT_STYLE ] ) && 'db:zz-css' === end( $out_plan[ Rules::SLOT_FRONT_STYLE ] )['key'] && 2 === count( $out_plan[ Rules::SLOT_FRONT_STYLE ] ) );

echo "7. The active option\n";
$built = Db_Snippets::active_option(
	array(
		array( 'id' => 9, 'slug' => 'b-on', 'name' => 'Secret name', 'description' => 'Long text', 'type' => 'php', 'scope' => 'admin', 'priority' => '3', 'active' => '1', 'code' => "function fh_b_on() {}\n", 'version' => '4' ),
		array( 'id' => 8, 'slug' => 'a-off', 'name' => 'Off', 'type' => 'php', 'scope' => 'global', 'priority' => 10, 'active' => 0, 'code' => 'x();', 'version' => 1 ),
		array( 'id' => 7, 'slug' => 'a-css', 'name' => 'Css', 'type' => 'css', 'scope' => 'admin', 'priority' => 10, 'active' => 1, 'code' => 'a{}', 'version' => 2 ),
	)
);
check( 'only the active ones, by slug', array( 'a-css', 'b-on' ) === array_keys( $built['map'] ) );
check( 'only what the loader needs (no name, no description), typed, signed', array( 'id', 'slug', 'type', 'scope', 'priority', 'code', 'version', 'declares', 'mac' ) === array_keys( $built['map']['b-on'] ) && 3 === $built['map']['b-on']['priority'] && 4 === $built['map']['b-on']['version'] && 64 === strlen( $built['map']['b-on']['mac'] ) );
check( 'PHP carries its declarations (the preflight needs no tokenizer per request); CSS none', 'function:fh_b_on' === fh_decl_names( $built['map']['b-on']['declares'] ) && array() === $built['map']['a-css']['declares'] );
check( 'its size is the serialized size WordPress stores', strlen( serialize( $built['map'] ) ) === $built['bytes'] );
check( 'nothing active: an empty map', array() === Db_Snippets::active_option( array() )['map'] );

echo "7b. The active option is signed: only what the editor wrote runs\n";
$entry = $built['map']['b-on'];
check( 'an entry the editor built verifies', Db_Snippets::verified( $entry ) && Db_Snippets::verified( $built['map']['a-css'] ) );
$forged = array(
	'x' => array( 'slug' => 'x', 'type' => 'php', 'scope' => 'global', 'code' => "\$GLOBALS['fh_ran'][] = 'injected via option';" ),
);
check( 'an entry written straight into the option (wp-admin/options.php): no signature, not verified', ! Db_Snippets::verified( $forged['x'] ) );
$tampered = array();
foreach ( array( 'code' => 'evil();', 'scope' => 'front-end', 'type' => 'css', 'slug' => 'other', 'priority' => 1, 'id' => 99, 'version' => 9, 'declares' => array() ) as $field => $value ) {
	$tampered[ $field ] = Db_Snippets::verified( array_merge( $entry, array( $field => $value ) ) );
}
check( 'any field changed after signing (code, scope, type, slug, priority, id, version, declarations): not verified', array() === array_filter( $tampered ), json_encode( $tampered ) );
check( 'a signature made up, empty or missing: not verified', ! Db_Snippets::verified( array_merge( $entry, array( 'mac' => str_repeat( 'a', 64 ) ) ) ) && ! Db_Snippets::verified( array_merge( $entry, array( 'mac' => '' ) ) ) && ! Db_Snippets::verified( array_diff_key( $entry, array( 'mac' => 1 ) ) ) && ! Db_Snippets::verified( 'garbage' ) );
$GLOBALS['fh_salt'] = 'rotated-salts';
check( 'new salts: every entry unsigned until the option is rebuilt', ! Db_Snippets::verified( $entry ) && Db_Snippets::verified( Db_Snippets::active_option( array( array_merge( $entry, array( 'active' => 1 ) ) ) )['map']['b-on'] ) );
unset( $GLOBALS['fh_salt'] );
$with_forged = Db_Snippets::merge( $index, array_merge( $active, $forged, array( 'aaa' => array_merge( $active['aaa'], array( 'code' => 'evil();' ) ) ) ) );
check( 'merge: a forged or tampered entry is left out; the signed ones stay', ! isset( $with_forged['db:x'] ) && ! isset( $with_forged['db:aaa'] ) && isset( $with_forged['db:first'], $with_forged['db:moved'] ) );
check( 'merge: an entry filed under another slug than its own is left out', ! isset( Db_Snippets::merge( $index, array( 'other' => $active['aaa'] ) )['db:aaa'] ) );
check( 'merge: only forged entries = the files untouched', $index['snippets'] === Db_Snippets::merge( $index, $forged ) );

echo "7c. Drift between the stored option and the table\n";
$rows_now = array(
	array( 'id' => 1, 'slug' => 'one', 'type' => 'php', 'scope' => 'global', 'priority' => 10, 'active' => 1, 'code' => '$o = 1;', 'version' => 2 ),
	array( 'id' => 2, 'slug' => 'two', 'type' => 'css', 'scope' => 'front-end', 'priority' => 10, 'active' => 1, 'code' => 'p{}', 'version' => 1 ),
);
$expected = Db_Snippets::active_option( $rows_now )['map'];
check( 'the same: no drift', '' === Db_Snippets::drift( $expected, $expected ) );
check( 'an empty option: both missing', 'switched on but missing: one, two' === Db_Snippets::drift( $expected, array() ) && 'switched on but missing: one, two' === Db_Snippets::drift( $expected, false ) );
$older = Db_Snippets::active_option( array( array_merge( $rows_now[0], array( 'version' => 1, 'code' => '$o = 0;' ) ), $rows_now[1] ) )['map'];
check( 'an older version still listed', 'an older version listed: one' === Db_Snippets::drift( $expected, $older ) );
$gone = Db_Snippets::active_option( array( $rows_now[0], $rows_now[1], array( 'id' => 3, 'slug' => 'gone', 'type' => 'php', 'scope' => 'global', 'priority' => 10, 'active' => 1, 'code' => '$g = 1;', 'version' => 1 ) ) )['map'];
check( 'off or deleted but still listed', 'off or deleted but still listed: gone' === Db_Snippets::drift( $expected, $gone ) );
check( 'a forged or tampered entry', 'not written by the editor (or the salts changed): one, x' === Db_Snippets::drift( $expected, array_merge( $expected, $forged, array( 'one' => array_merge( $expected['one'], array( 'code' => 'evil();' ) ) ) ) ) );
check( 'the cap on everything active together is below WordPress\'s large-option warning zone and above one full snippet', Editor_Rules::MAX_ACTIVE_BYTES > Editor_Rules::MAX_CODE_BYTES && Editor_Rules::MAX_ACTIVE_BYTES <= 262144 );

echo "8. History and audit text — never the code\n";
$secret = 'fh_very_secret_token_123';
$before = array( 'name' => 'Old name', 'description' => 'd', 'type' => 'php', 'scope' => 'global', 'priority' => 10, 'active' => 0, 'code' => "\$k = '$secret';\n" );
$after  = array( 'name' => 'New name', 'description' => 'd', 'type' => 'php', 'scope' => 'admin', 'priority' => 5, 'active' => 1, 'code' => "\$k = '$secret';\n\$x = 2;\n" );
$sum    = Editor_Rules::change_summary( $before, $before['code'], $after, $after['code'] );
$hb     = Editor_Rules::code_stats( $before['code'] );
$ha     = Editor_Rules::code_stats( $after['code'] );
check( 'fields old -> new, the switch, the code hash and line counts', 'name: "Old name" -> "New name"; scope: global -> admin; priority: 10 -> 5; switched off -> on; code: ' . $hb['hash'] . ' (1 lines) -> ' . $ha['hash'] . ' (2 lines)' === $sum, $sum );
check( 'the code itself is never in it', false === strpos( $sum, $secret ) );
check( 'no change', 'no change' === Editor_Rules::change_summary( $before, $before['code'], $before, $before['code'] ) );
check( 'created', 0 === strpos( Editor_Rules::change_summary( null, null, $after, $after['code'] ), 'created: php / admin, priority 5, on; code ' . $ha['hash'] . ' (2 lines)' ) );
check( 'code stats: hash of 12 hex, lines with or without a final newline, 0 for empty', 1 === preg_match( '/^[0-9a-f]{12}$/', $ha['hash'] ) && 2 === Editor_Rules::code_stats( "a\nb" )['lines'] && 2 === Editor_Rules::code_stats( "a\nb\n" )['lines'] && 0 === Editor_Rules::code_stats( '' )['lines'] );
check( 'a long name is cut in the summary', false !== strpos( Editor_Rules::change_summary( $before, '', array_merge( $before, array( 'name' => str_repeat( 'n', 100 ) ) ), '' ), str_repeat( 'n', 59 ) . '…"' ) );
$texts = array(
	'create'      => Editor_Rules::audit_summary( 'create', array( 'version' => 1, 'after' => $after ) ),
	'update'      => Editor_Rules::audit_summary( 'update', array( 'version' => 3, 'before' => $before, 'after' => $after ) ),
	'activate'    => Editor_Rules::audit_summary( 'activate', array( 'version' => 4, 'before' => $before, 'after' => array_merge( $before, array( 'active' => 1 ) ) ) ),
	'restore'     => Editor_Rules::audit_summary( 'restore', array( 'version' => 5, 'from_version' => 2, 'before' => $after, 'after' => $before ) ),
	'undelete'    => Editor_Rules::audit_summary( 'restore', array( 'version' => 6, 'from_version' => 5, 'after' => $before ) ),
	'delete'      => Editor_Rules::audit_summary( 'delete', array( 'version' => 6, 'before' => $after ) ),
	'export'      => Editor_Rules::audit_summary( 'export', array( 'version' => 6, 'after' => $after, 'file' => 'hide-bar.php' ) ),
	'test_failed' => Editor_Rules::audit_summary( 'test_failed', array( 'version' => 7, 'after' => $after, 'message' => "LogicException: boom\n(line 3)" ) ),
	'denied'      => Editor_Rules::audit_summary( 'denied', array( 'attempt' => 'save', 'message' => 'Your account is not named in FISHOTEL_SNIPPETS_EDITORS.' ) ),
	'safe_mode'   => Editor_Rules::audit_summary( 'safe_mode', array( 'reason' => 'query' ) ),
);
check( 'create', 0 === strpos( $texts['create'], 'v1 — created: php / admin' ), $texts['create'] );
check( 'update', 0 === strpos( $texts['update'], 'v3 — name: "Old name" -> "New name"' ), $texts['update'] );
check( 'activate', 'v4 — switched off -> on' === $texts['activate'], $texts['activate'] );
check( 'restore names the version restored', 0 === strpos( $texts['restore'], 'v5 — restored from v2; name: "New name" -> "Old name"' ), $texts['restore'] );
check( 'restore after a delete says it is off', 0 === strpos( $texts['undelete'], 'v6 — restored from v5 after a delete, switched off; code ' . $hb['hash'] ), $texts['undelete'] );
check( 'delete keeps the last code hash', 'deleted at v6; last code ' . $ha['hash'] . ' (2 lines); revisions and log kept' === $texts['delete'], $texts['delete'] );
check( 'export names the file and Default', 0 === strpos( $texts['export'], 'exported v6 as hide-bar.php (Default: on); code ' . $ha['hash'] ), $texts['export'] );
check( 'test_failed: the message on one line', 0 === strpos( $texts['test_failed'], 'test run of v7 failed: LogicException: boom (line 3); nothing saved; code ' ), $texts['test_failed'] );
check( 'denied: what was tried and why', 'refused save: Your account is not named in FISHOTEL_SNIPPETS_EDITORS.' === $texts['denied'] );
check( 'safe mode: how', false !== strpos( $texts['safe_mode'], '?fishotel-snippets-safe-mode=1' ) && false !== strpos( Editor_Rules::audit_summary( 'safe_mode', array( 'reason' => 'constant' ) ), 'wp-config.php' ) );
check( 'no summary carries the code', array() === array_filter( $texts, static function ( $t ) use ( $secret ) { return false !== strpos( $t, $secret ); } ) );
check( 'a summary is at most 2000 characters', 2000 === mb_strlen( Editor_Rules::audit_summary( 'denied', array( 'attempt' => 'x', 'message' => str_repeat( 'y', 5000 ) ) ) ) );

echo "9. CSV guard, request fields\n";
check( 'formula starters get an apostrophe', "'=HYPERLINK(\"x\")" === Editor_Rules::csv_cell( '=HYPERLINK("x")' ) && "'+1" === Editor_Rules::csv_cell( '+1' ) && "'-2" === Editor_Rules::csv_cell( '-2' ) && "'@a" === Editor_Rules::csv_cell( '@a' ) && "'\tx" === Editor_Rules::csv_cell( "\tx" ) && "'\rx" === Editor_Rules::csv_cell( "\rx" ) );
check( 'plain text, numbers and empty values pass', 'abc' === Editor_Rules::csv_cell( 'abc' ) && '12' === Editor_Rules::csv_cell( 12 ) && '' === Editor_Rules::csv_cell( null ) && 'a=b' === Editor_Rules::csv_cell( 'a=b' ) );
$row = Editor_Rules::csv_row( array( 'id' => 3, 'created_at' => '2026-10-18 10:00:00', 'action' => 'denied', 'slug' => 'x', 'snippet_id' => null, 'user_id' => 2, 'user_login' => '=cmd', 'remote_addr' => '1.2.3.4', 'forwarded_for' => '@evil', 'user_agent' => '-ua', 'summary' => '+sum' ) );
check( 'a log row: every column in header order, every cell guarded', count( Editor_Rules::csv_header() ) === count( $row ) && array( '3', '2026-10-18 10:00:00', 'denied', 'x', '', '2', "'=cmd", '1.2.3.4', "'@evil", "'-ua", "'+sum" ) === $row, json_encode( $row ) );
$client = Editor_Rules::client( array( 'REMOTE_ADDR' => '203.0.113.9', 'HTTP_X_FORWARDED_FOR' => ' 198.51.100.7 , 10.0.0.1', 'HTTP_USER_AGENT' => str_repeat( 'U', 300 ) . "\n" ) );
check( 'REMOTE_ADDR kept, the FIRST X-Forwarded-For value only, user agent cut to 255', '203.0.113.9' === $client['remote_addr'] && '198.51.100.7' === $client['forwarded_for'] && 255 === strlen( $client['user_agent'] ) );
$client = Editor_Rules::client( array( 'REMOTE_ADDR' => 'not an ip', 'HTTP_X_FORWARDED_FOR' => "<script>\x01" . str_repeat( 'x', 200 ) ) );
check( 'an invalid REMOTE_ADDR is dropped; X-Forwarded-For is printable and at most 100 characters (stored as untrusted)', '' === $client['remote_addr'] && 100 === strlen( $client['forwarded_for'] ) && false === strpos( $client['forwarded_for'], "\x01" ) );
check( 'nothing sent: empty strings', array( 'remote_addr' => '', 'forwarded_for' => '', 'user_agent' => '' ) === Editor_Rules::client( array() ) );

echo "10. Export — a file the folder accepts\n";
$php_row = array( 'slug' => 'hide-bar', 'name' => 'Hide the bar', 'description' => 'For customers only.', 'type' => 'php', 'scope' => 'admin', 'priority' => 7, 'active' => 1, 'code' => "function fh_exported() {}\nadd_action( 'init', 'fh_exported' );\n" );
$file    = Export::build( $php_row, 'jeff', '2026-10-18' );
$parsed  = Header::parse( 'hide-bar.php', $file );
check( 'the file name is <slug>.<type>', 'hide-bar.php' === Export::filename( $php_row ) );
check( 'the header parses with every field', $parsed['ok'] && 'Hide the bar' === $parsed['fields']['name'] && 'For customers only.' === $parsed['fields']['description'] && 'admin' === $parsed['fields']['scope'] && 7 === $parsed['fields']['priority'] && true === $parsed['fields']['default_on'], json_encode( $parsed ) );
check( 'Origin: written in wp-admin by <login>, exported <date>', 'written in wp-admin by jeff, exported 2026-10-18' === $parsed['fields']['origin'] && 0 === $parsed['fields']['cs_id'] );
check( 'Default follows the current state', false === Header::parse( 'hide-bar.php', Export::build( array_merge( $php_row, array( 'active' => 0 ) ), 'jeff', '2026-10-18' ) )['fields']['default_on'] );
check( 'the ABSPATH guard the folder test insists on', 1 === preg_match( "/defined\\(\\s*'ABSPATH'\\s*\\)\\s*\\|\\|\\s*exit\\s*;/", $file ) );
check( 'the Symbols scan of the file finds what the stored code declares', fh_decl_names( Symbols::declared( $file ) ) === fh_decl_names( Db_Snippets::declared( $php_row['code'] ) ) && '' === Symbols::syntax_error( $file )['error'] );
check( 'a Catalog of the file has no broken entry and no clash', array() === Catalog::build( array( 'hide-bar.php' => $file ) )['broken'] );
$tmp = sys_get_temp_dir() . '/fh-export-' . bin2hex( random_bytes( 4 ) ) . '.php';
file_put_contents( $tmp, $file );
exec( escapeshellarg( PHP_BINARY ) . ' -l ' . escapeshellarg( $tmp ) . ' 2>&1', $lint, $lint_code );
unlink( $tmp );
check( 'php -l passes', 0 === $lint_code, implode( ' ', $lint ) );
check( 'the code follows the guard as written', false !== strpos( $file, "defined( 'ABSPATH' ) || exit;\n\nfunction fh_exported() {}\nadd_action( 'init', 'fh_exported' );\n" ) && "\n" === substr( $file, -1 ) && "\n\n" !== substr( $file, -2 ) );
$ns      = "namespace FisHotel\\Mine;\n\nfunction helper() {}\n";
$ns_file = Export::build( array_merge( $php_row, array( 'code' => $ns ) ), 'jeff', '2026-10-18' );
check( 'namespaced code: the guard goes after the namespace line (PHP allows nothing before it)', false !== strpos( $ns_file, "namespace FisHotel\\Mine;\n\ndefined( 'ABSPATH' ) || exit;\n\nfunction helper() {}" ) && '' === Symbols::syntax_error( $ns_file )['error'] && Header::parse( 'x.php', $ns_file )['ok'], $ns_file );
$tmp = sys_get_temp_dir() . '/fh-export-' . bin2hex( random_bytes( 4 ) ) . '.php';
file_put_contents( $tmp, $ns_file );
exec( escapeshellarg( PHP_BINARY ) . ' -l ' . escapeshellarg( $tmp ) . ' 2>&1', $lint, $lint_code );
unlink( $tmp );
check( '… and php -l passes on it', 0 === $lint_code );
$dc_file = Export::build( array_merge( $php_row, array( 'code' => "declare(strict_types=1);\nnamespace A;\nfunction f(): int { return 1; }\n" ) ), 'jeff', '2026-10-18' );
check( 'declare(strict_types=1) then a namespace: the guard after both', false !== strpos( $dc_file, "declare(strict_types=1);\nnamespace A;\n\ndefined( 'ABSPATH' ) || exit;" ) && '' === Symbols::syntax_error( $dc_file )['error'], $dc_file );
$br_file = Export::build( array_merge( $php_row, array( 'code' => "namespace B {\n\tfunction g() {}\n}\n" ) ), 'jeff', '2026-10-18' );
check( 'a braced namespace: the guard just inside its brace', false !== strpos( $br_file, "namespace B {\n\ndefined( 'ABSPATH' ) || exit;\n\n\tfunction g() {}" ) && '' === Symbols::syntax_error( $br_file )['error'], $br_file );
check( 'namespace\\foo() is a call: the guard stays first', 0 === Export::guard_offset( "namespace\\foo();\n" ) && 0 === Export::guard_offset( "/* c */\n\$x = 1;" ) );
foreach ( array(
	'css'  => array( 'scope' => 'login', 'code' => "body { color: teal; }\n" ),
	'js'   => array( 'scope' => 'head', 'code' => "window.fh = 1;\n" ),
	'html' => array( 'scope' => 'footer', 'code' => "<div class=\"x\">Hi</div>\n" ),
) as $type => $extra ) {
	$row  = array_merge( $php_row, array( 'slug' => 'my-' . $type, 'type' => $type, 'active' => 0 ), $extra );
	$text = Export::build( $row, 'jeff', '2026-10-18' );
	$p    = Header::parse( 'my-' . $type . '.' . $type, $text );
	check( $type . ': header parses, scope kept, off, body = the code', $p['ok'] && $extra['scope'] === $p['fields']['scope'] && false === $p['fields']['default_on'] && $extra['code'] === Header::body( 'my-' . $type . '.' . $type, $text ), json_encode( array( $p, $text ) ) );
}
check( 'a login cannot break the Origin line', 'written in wp-admin by a b, exported 2026-10-18' === Export::origin( "a*/,b\n", '2026-10-18' ) );

fh_finish();
