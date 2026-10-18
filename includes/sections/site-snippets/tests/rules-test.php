<?php
/**
 * Rules tests — the enabled state with defaults, scope decisions (PHP on
 * front end / admin, CSS / JS / HTML screens), ordering by priority, the
 * PHP and output plans, safe mode, the Code Snippets duplicate guard, the
 * [code_snippet id=N] mapping and the shortcode flags.
 *
 * Run from the plugin root:
 *   php includes/sections/site-snippets/tests/rules-test.php
 *
 * @package FisHotel\Misc\Sections\Site_Snippets
 */

require_once __DIR__ . '/wp-stubs.php';

use FisHotel\Misc\Sections\Site_Snippets\Rules;

/**
 * An index entry.
 */
function fh_entry( $slug, $type, $scope, $priority = 10, array $extra = array() ) {
	return array_merge(
		array(
			'slug'        => $slug,
			'file'        => $slug . '.' . $type,
			'type'        => $type,
			'scope'       => $scope,
			'priority'    => $priority,
			'name'        => ucfirst( $slug ),
			'description' => '',
			'origin'      => '',
			'cs_id'       => 0,
			'default_on'  => false,
			'declares'    => array(),
			'refused'     => '',
		),
		$extra
	);
}

function fh_slugs( array $list ) {
	return implode( ',', array_map( static function ( $s ) { return $s['slug']; }, $list ) );
}

echo "1. is_enabled() — stored choice, else the file's Default\n";
check( 'absent + Default off → off', false === Rules::is_enabled( 'a', false, array() ) );
check( 'absent + Default on → on', true === Rules::is_enabled( 'a', true, array() ) );
check( 'stored true beats Default off', true === Rules::is_enabled( 'a', false, array( 'a' => true ) ) );
check( 'stored false beats Default on', false === Rules::is_enabled( 'a', true, array( 'a' => false ) ) );
check( 'another slug stored does not count', true === Rules::is_enabled( 'a', true, array( 'b' => false ) ) );
check( 'a non-array option falls back to Default', true === Rules::is_enabled( 'a', true, 'garbage' ) && false === Rules::is_enabled( 'a', false, false ) );
check( 'stored "1" / 1 read as on, "0" / null as off', Rules::is_enabled( 'a', false, array( 'a' => '1' ) ) && Rules::is_enabled( 'a', false, array( 'a' => 1 ) ) && ! Rules::is_enabled( 'a', true, array( 'a' => '0' ) ) && ! Rules::is_enabled( 'a', true, array( 'a' => null ) ) );

echo "2. php_runs_here() — Code Snippets' rule\n";
check( 'global on the front end', Rules::php_runs_here( 'global', false ) );
check( 'global in admin', Rules::php_runs_here( 'global', true ) );
check( 'front-end on the front end', Rules::php_runs_here( 'front-end', false ) );
check( 'front-end not in admin (admin-ajax included)', ! Rules::php_runs_here( 'front-end', true ) );
check( 'admin in admin', Rules::php_runs_here( 'admin', true ) );
check( 'admin not on the front end', ! Rules::php_runs_here( 'admin', false ) );
check( 'an unknown scope never runs', ! Rules::php_runs_here( 'single-use', false ) && ! Rules::php_runs_here( 'single-use', true ) );

echo "3. slot() / prints_on() — CSS / JS / HTML screens\n";
check( 'css front-end → front style', Rules::SLOT_FRONT_STYLE === Rules::slot( 'css', 'front-end' ) );
check( 'css admin → admin style', Rules::SLOT_ADMIN_STYLE === Rules::slot( 'css', 'admin' ) );
check( 'css login → login style', Rules::SLOT_LOGIN_STYLE === Rules::slot( 'css', 'login' ) );
check( 'js head → front head', Rules::SLOT_FRONT_HEAD === Rules::slot( 'js', 'head' ) );
check( 'js footer and js front-end → front footer', Rules::SLOT_FRONT_FOOT === Rules::slot( 'js', 'footer' ) && Rules::SLOT_FRONT_FOOT === Rules::slot( 'js', 'front-end' ) );
check( 'js admin / login', Rules::SLOT_ADMIN_SCRIPT === Rules::slot( 'js', 'admin' ) && Rules::SLOT_LOGIN_SCRIPT === Rules::slot( 'js', 'login' ) );
check( 'html head / body / footer', Rules::SLOT_HTML_HEAD === Rules::slot( 'html', 'head' ) && Rules::SLOT_HTML_BODY === Rules::slot( 'html', 'body' ) && Rules::SLOT_HTML_FOOTER === Rules::slot( 'html', 'footer' ) );
check( 'html shortcode and php have no slot', '' === Rules::slot( 'html', 'shortcode' ) && '' === Rules::slot( 'php', 'global' ) );
check( 'front-end CSS prints on the site only', Rules::prints_on( 'css', 'front-end', 'front' ) && ! Rules::prints_on( 'css', 'front-end', 'admin' ) && ! Rules::prints_on( 'css', 'front-end', 'login' ) );
check( 'admin CSS prints in admin only', Rules::prints_on( 'css', 'admin', 'admin' ) && ! Rules::prints_on( 'css', 'admin', 'front' ) );
check( 'login JS prints on wp-login only', Rules::prints_on( 'js', 'login', 'login' ) && ! Rules::prints_on( 'js', 'login', 'front' ) && ! Rules::prints_on( 'js', 'login', 'admin' ) );
check( 'head JS prints on the site only', Rules::prints_on( 'js', 'head', 'front' ) && ! Rules::prints_on( 'js', 'head', 'admin' ) );
check( 'html footer prints on the site only', Rules::prints_on( 'html', 'footer', 'front' ) && ! Rules::prints_on( 'html', 'footer', 'admin' ) );
check( 'an unknown screen prints nothing', ! Rules::prints_on( 'css', 'front-end', 'cli' ) );

echo "4. sort() — priority, then slug\n";
$list = array( fh_entry( 'zeta', 'php', 'global', 10 ), fh_entry( 'alpha', 'php', 'global', 10 ), fh_entry( 'late', 'php', 'global', 99 ), fh_entry( 'first', 'php', 'global', -1 ), fh_entry( 'five', 'php', 'global', 5 ) );
check( 'ordered', 'first,five,alpha,zeta,late' === fh_slugs( Rules::sort( $list ) ), fh_slugs( Rules::sort( $list ) ) );
check( 'a slug-keyed map sorts to a list', 'first,five,alpha,zeta,late' === fh_slugs( Rules::sort( array( 'zeta' => $list[0], 'alpha' => $list[1], 'late' => $list[2], 'first' => $list[3], 'five' => $list[4] ) ) ) );
check( 'priority "5" from a string compares as a number', 'b,a' === fh_slugs( Rules::sort( array( fh_entry( 'a', 'php', 'global', '10' ), fh_entry( 'b', 'php', 'global', '9' ) ) ) ) );

echo "5. php_plan()\n";
$snippets = array(
	'everywhere' => fh_entry( 'everywhere', 'php', 'global', 20, array( 'default_on' => true ) ),
	'site-only'  => fh_entry( 'site-only', 'php', 'front-end', 10, array( 'default_on' => true ) ),
	'admin-only' => fh_entry( 'admin-only', 'php', 'admin', 5, array( 'default_on' => true ) ),
	'off'        => fh_entry( 'off', 'php', 'global' ),
	'switched'   => fh_entry( 'switched', 'php', 'global', 1 ),
	'refused'    => fh_entry( 'refused', 'php', 'global', 1, array( 'default_on' => true, 'refused' => 'function x() is declared also in y.php' ) ),
	'held'       => fh_entry( 'held', 'php', 'global', 1, array( 'default_on' => true, 'cs_id' => 4 ) ),
	'styles'     => fh_entry( 'styles', 'css', 'front-end', 1, array( 'default_on' => true ) ),
);
$enabled = array( 'switched' => true, 'everywhere' => true );
$held    = array( 'held' => 'Code Snippets #4 is still active' );
check( 'front end: switched, site-only, everywhere (in priority order)', 'switched,site-only,everywhere' === fh_slugs( Rules::php_plan( $snippets, $enabled, false, $held ) ), fh_slugs( Rules::php_plan( $snippets, $enabled, false, $held ) ) );
check( 'admin: switched, admin-only, everywhere', 'switched,admin-only,everywhere' === fh_slugs( Rules::php_plan( $snippets, $enabled, true, $held ) ) );
check( 'switching everywhere off removes it', 'switched,site-only' === fh_slugs( Rules::php_plan( $snippets, array( 'switched' => true, 'everywhere' => false ), false, $held ) ) );
check( 'without the guard the held one runs', false !== strpos( fh_slugs( Rules::php_plan( $snippets, $enabled, false, array() ) ), 'held' ) );

echo "6. output_plan()\n";
$out = Rules::output_plan(
	array(
		'b-style'  => fh_entry( 'b-style', 'css', 'front-end', 10, array( 'default_on' => true ) ),
		'a-style'  => fh_entry( 'a-style', 'css', 'front-end', 10, array( 'default_on' => true ) ),
		'early'    => fh_entry( 'early', 'css', 'front-end', 1, array( 'default_on' => true ) ),
		'admin-js' => fh_entry( 'admin-js', 'js', 'admin', 10, array( 'default_on' => true ) ),
		'head-js'  => fh_entry( 'head-js', 'js', 'head', 10 ),
		'banner'   => fh_entry( 'banner', 'html', 'shortcode', 10, array( 'default_on' => true ) ),
		'pixel'    => fh_entry( 'pixel', 'html', 'footer', 10, array( 'default_on' => true, 'cs_id' => 9 ) ),
		'php'      => fh_entry( 'php', 'php', 'global', 10, array( 'default_on' => true ) ),
	),
	array( 'head-js' => true ),
	array( 'pixel' => 'Code Snippets #9 is still active' )
);
check( 'front styles in priority then slug order', 'early,a-style,b-style' === fh_slugs( $out[ Rules::SLOT_FRONT_STYLE ] ) );
check( 'admin script', 'admin-js' === fh_slugs( $out[ Rules::SLOT_ADMIN_SCRIPT ] ) );
check( 'head script switched on by the option', 'head-js' === fh_slugs( $out[ Rules::SLOT_FRONT_HEAD ] ) );
$slots = array_keys( $out );
sort( $slots );
$want = array( Rules::SLOT_ADMIN_SCRIPT, Rules::SLOT_FRONT_HEAD, Rules::SLOT_FRONT_STYLE );
sort( $want );
check( 'shortcode and php snippets have no slot; the held footer HTML is left out', $want === $slots, json_encode( $slots ) );

echo "7. safe_mode()\n";
$calls = 0;
$yes   = static function () use ( &$calls ) {
	$calls++;
	return true;
};
$no    = static function () use ( &$calls ) {
	$calls++;
	return false;
};
$s = Rules::safe_mode( true, false, $no );
check( 'constant → on, reason constant, no capability check', true === $s['active'] && 'constant' === $s['reason'] && 0 === $calls );
$s = Rules::safe_mode( false, true, $yes );
check( 'query flag + manage_options → on, reason query', true === $s['active'] && 'query' === $s['reason'] && 1 === $calls );
$s = Rules::safe_mode( false, true, $no );
check( 'query flag for a visitor → off', false === $s['active'] && '' === $s['reason'] );
$calls = 0;
$s     = Rules::safe_mode( false, false, $yes );
check( 'no flag → off and the user is never resolved', false === $s['active'] && 0 === $calls );
check( 'a non-callable capability check → off', false === Rules::safe_mode( false, true, 'not-a-function-xyz' )['active'] );

echo "8. guard() — the Code Snippets duplicate guard\n";
$cand = array(
	'free-ship' => 12,
	'promo-bar' => 15,
	'old-fix'   => 20,
);
check( 'Code Snippets not active → nothing held', array() === Rules::guard( $cand, false, null ) );
check( 'no candidates → nothing held', array() === Rules::guard( array(), true, array( 12 ) ) );
$held = Rules::guard( $cand, true, array( 12, 20, 99 ) );
check( 'active ids 12 and 20 hold free-ship and old-fix', array( 'free-ship', 'old-fix' ) === array_keys( $held ) );
check( 'the reason names the id', false !== strpos( $held['free-ship'], 'Code Snippets #12 is still active' ) );
check( 'none active → nothing held', array() === Rules::guard( $cand, true, array() ) );
check( 'ids as strings from the database match', array( 'promo-bar' ) === array_keys( Rules::guard( $cand, true, array( '15' ) ) ) );
$unknown = Rules::guard( $cand, true, null );
check( 'table unreadable → every candidate held (fail closed)', array( 'free-ship', 'promo-bar', 'old-fix' ) === array_keys( $unknown ) && false !== strpos( $unknown['promo-bar'], 'could not be read' ) );

echo "9. guard_candidates()\n";
$c = Rules::guard_candidates(
	array(
		'on-cs'       => fh_entry( 'on-cs', 'php', 'global', 10, array( 'default_on' => true, 'cs_id' => 3 ) ),
		'off-cs'      => fh_entry( 'off-cs', 'php', 'global', 10, array( 'cs_id' => 4 ) ),
		'hand'        => fh_entry( 'hand', 'php', 'global', 10, array( 'default_on' => true ) ),
		'css-cs'      => fh_entry( 'css-cs', 'css', 'admin', 10, array( 'cs_id' => 5 ) ),
		'shortcode'   => fh_entry( 'shortcode', 'html', 'shortcode', 10, array( 'default_on' => true, 'cs_id' => 6 ) ),
		'head-html'   => fh_entry( 'head-html', 'html', 'head', 10, array( 'default_on' => true, 'cs_id' => 7 ) ),
		'refused-cs'  => fh_entry( 'refused-cs', 'php', 'global', 10, array( 'default_on' => true, 'cs_id' => 8, 'refused' => 'clash' ) ),
	),
	array( 'css-cs' => true )
);
check( 'switched-on Code Snippets imports of every kind but shortcodes', array( 'on-cs' => 3, 'css-cs' => 5, 'head-html' => 7 ) === $c, json_encode( $c ) );

echo "10. shortcode_map()\n";
$m = Rules::shortcode_map(
	array(
		'banner'  => fh_entry( 'banner', 'html', 'shortcode', 10, array( 'cs_id' => 31 ) ),
		'table'   => fh_entry( 'table', 'html', 'shortcode', 10, array( 'cs_id' => 7 ) ),
		'dup-a'   => fh_entry( 'dup-a', 'html', 'shortcode', 10, array( 'cs_id' => 40 ) ),
		'dup-b'   => fh_entry( 'dup-b', 'html', 'shortcode', 10, array( 'cs_id' => 40 ) ),
		'head'    => fh_entry( 'head', 'html', 'head', 10, array( 'cs_id' => 50 ) ),
		'php'     => fh_entry( 'php', 'php', 'global', 10, array( 'cs_id' => 51 ) ),
		'by-hand' => fh_entry( 'by-hand', 'html', 'shortcode' ),
	)
);
check( 'ids map to their shortcode snippet, sorted by id', array( 7 => 'table', 31 => 'banner' ) === $m['map'], json_encode( $m['map'] ) );
check( 'two files claiming one id: ambiguous, neither answers', array( 40 => array( 'dup-a', 'dup-b' ) ) === $m['ambiguous'] && ! isset( $m['map'][40] ) );
check( 'head HTML and PHP never answer [code_snippet]', ! isset( $m['map'][50] ) && ! isset( $m['map'][51] ) );
check( 'empty input', array( 'map' => array(), 'ambiguous' => array() ) === Rules::shortcode_map( array() ) );

echo "11. shortcode_flags()\n";
$f = Rules::shortcode_flags( array( 'id' => '3', 0 => 'format', 1 => 'shortcodes' ), array( 'format', 'shortcodes', 'php' ) );
check( 'bare flags become true', true === $f['format'] && true === $f['shortcodes'] && false === $f['php'] && '3' === $f['id'] );
$f = Rules::shortcode_flags( array( 'format' => 'false', 'shortcodes' => '1' ), array( 'format', 'shortcodes' ) );
check( 'explicit values', false === $f['format'] && true === $f['shortcodes'] );
check( 'a non-array is empty with flags false', array( 'format' => false ) === Rules::shortcode_flags( '', array( 'format' ) ) );

fh_finish();
