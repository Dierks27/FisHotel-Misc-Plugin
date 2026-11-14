<?php
/**
 * The section switches (includes/class-section-manager.php) — two switches
 * clicked close together must both be saved.
 *
 * Each click is a request of its own, and each request reads the list of
 * enabled sections when it starts. This test plays several requests against
 * one in-memory "database": every request has its own copy of the options
 * (what WordPress cached for it at the start), exactly the state in which
 * the last save used to wipe the one before it.
 *
 * It lives with the Health tests because the manager is not a section and
 * CI runs the tests under includes/sections/<slug>/tests/. It brings its own
 * stubs and three throw-away sections in a temporary folder, so nothing of
 * the real sections is loaded.
 *
 * Run from the plugin root:  php includes/sections/health/tests/section-switches-test.php
 */

if ( 'cli' !== PHP_SAPI ) {
	exit;
}

define( 'ABSPATH', __DIR__ . '/' );

$fh_passes   = 0;
$fh_failures = 0;
function check( $label, $cond, $detail = '' ) {
	global $fh_passes, $fh_failures;
	if ( $cond ) {
		$fh_passes++;
		echo "  ok   - {$label}\n";
	} else {
		$fh_failures++;
		echo "  FAIL - {$label}" . ( '' !== $detail ? " [{$detail}]" : '' ) . "\n";
	}
}

/* ---- The database, and what each request cached of it ---------------- */

$GLOBALS['fh_db']      = array(); // option name => value: the one truth
$GLOBALS['fh_caches']  = array(); // request name => its cached copy of the options
$GLOBALS['fh_request'] = '';      // the request that is running now
$GLOBALS['fh_trace']   = array(); // what the running code did, in order
$GLOBALS['fh_writes']  = 0;

/** Switch to a request; a new one caches the options as they are now. */
function fh_request( $name ) {
	if ( ! isset( $GLOBALS['fh_caches'][ $name ] ) ) {
		$GLOBALS['fh_caches'][ $name ] = $GLOBALS['fh_db'];
	}
	$GLOBALS['fh_request'] = $name;
}

function get_option( $key, $default = false ) {
	$cache = &$GLOBALS['fh_caches'][ $GLOBALS['fh_request'] ];
	if ( null === $cache ) {
		// The cache was dropped: WordPress reads the options again.
		$GLOBALS['fh_trace'][] = 'read';
		$cache                 = $GLOBALS['fh_db'];
	}
	return array_key_exists( $key, $cache ) ? $cache[ $key ] : $default;
}

/** As WordPress: nothing is written when the value is what this request believes is stored. */
function update_option( $key, $value ) {
	if ( $value === get_option( $key ) ) {
		return false;
	}
	$GLOBALS['fh_trace'][] = 'write';
	$GLOBALS['fh_writes']++;
	$GLOBALS['fh_db'][ $key ]                                    = $value;
	$GLOBALS['fh_caches'][ $GLOBALS['fh_request'] ][ $key ] = $value;
	return true;
}

function wp_cache_delete( $key, $group = '' ) {
	if ( 'options' === $group && 'alloptions' === $key ) {
		$GLOBALS['fh_caches'][ $GLOBALS['fh_request'] ] = null;
	}
	return true;
}

function add_action() {
	return true;
}

/** $wpdb, as far as the lock goes. */
class Fh_Switch_Db {
	public $prefix = 'wp_';
	public $grants = '1'; // what GET_LOCK answers
	public $held   = array();

	public function prepare( $sql, ...$args ) {
		return vsprintf( str_replace( '%s', "'%s'", $sql ), $args );
	}

	public function get_var( $sql ) {
		if ( preg_match( "/^SELECT GET_LOCK\('([^']+)', (\d+)\)$/", $sql, $m ) ) {
			$GLOBALS['fh_trace'][] = 'lock';
			if ( '1' === $this->grants ) {
				$this->held[ $m[1] ] = true;
			}
			return $this->grants;
		}
		return null;
	}

	public function query( $sql ) {
		if ( preg_match( "/^SELECT RELEASE_LOCK\('([^']+)'\)$/", $sql, $m ) ) {
			$GLOBALS['fh_trace'][] = 'unlock';
			unset( $this->held[ $m[1] ] );
		}
		return 1;
	}
}
$GLOBALS['wpdb'] = new Fh_Switch_Db();

/* ---- Three throw-away sections -------------------------------------- */

$fh_root = sys_get_temp_dir() . '/fh-switches-' . getmypid() . '/';
foreach ( array( 'alpha', 'beta', 'gamma' ) as $fh_slug ) {
	$fh_dir = $fh_root . 'includes/sections/' . $fh_slug;
	mkdir( $fh_dir, 0777, true );
	$fh_class = ucfirst( $fh_slug );
	file_put_contents(
		$fh_dir . '/class-' . $fh_slug . '.php',
		"<?php\nnamespace FisHotel\\Misc\\Sections\\{$fh_class};\nclass {$fh_class} {\n\tpublic static function get_section_info() {\n\t\treturn array( 'name' => '{$fh_class}' );\n\t}\n}\n"
	);
}
define( 'FISHOTEL_MISC_PATH', $fh_root );
register_shutdown_function(
	static function () use ( $fh_root ) {
		foreach ( array( 'alpha', 'beta', 'gamma' ) as $slug ) {
			@unlink( $fh_root . 'includes/sections/' . $slug . '/class-' . $slug . '.php' );
			@rmdir( $fh_root . 'includes/sections/' . $slug );
		}
		@rmdir( $fh_root . 'includes/sections' );
		@rmdir( $fh_root . 'includes' );
		@rmdir( $fh_root );
	}
);

require_once dirname( __DIR__, 3 ) . '/class-section-manager.php';

use FisHotel\Misc\Section_Manager;

const FH_KEY = Section_Manager::OPTION_KEY;

/** A request that has started: its manager read the list as it was then. */
function fh_start( $name ) {
	fh_request( $name );
	$manager = new Section_Manager();
	$manager->init();
	return $manager;
}

/** One click, answered inside the request that carries it. */
function fh_click( $name, Section_Manager $manager, $slug, $on ) {
	fh_request( $name );
	$GLOBALS['fh_trace'] = array();
	return $manager->set_section_state( $slug, $on );
}

function fh_stored() {
	return array_key_exists( FH_KEY, $GLOBALS['fh_db'] ) ? $GLOBALS['fh_db'][ FH_KEY ] : null;
}

echo "1. Two switches on, both requests started before either saved\n";
$a = fh_start( 'a' );
$b = fh_start( 'b' );
check( 'three sections found, none on', 3 === count( $a->get_registered_sections() ) && ! $a->is_enabled( 'alpha' ) && ! $b->is_enabled( 'beta' ) );
check( 'the first click is saved', true === fh_click( 'a', $a, 'alpha', true ) && array( 'alpha' ) === fh_stored() );
check( 'the list is read again under the lock, written, and the lock given back', array( 'lock', 'read', 'write', 'unlock' ) === $GLOBALS['fh_trace'], implode( ',', $GLOBALS['fh_trace'] ) );
check( 'the second click keeps the first one', true === fh_click( 'b', $b, 'beta', true ) && array( 'alpha', 'beta' ) === fh_stored(), json_encode( fh_stored() ) );
check( 'the second request now knows both are on', $b->is_enabled( 'alpha' ) && $b->is_enabled( 'beta' ) );
check( 'no lock is left held', array() === $GLOBALS['wpdb']->held );

echo "2. Two switches off the same way\n";
$c = fh_start( 'c' );
$d = fh_start( 'd' );
check( 'the first off is saved', true === fh_click( 'c', $c, 'alpha', false ) && array( 'beta' ) === fh_stored() );
check( 'the second off does not switch the first back on', true === fh_click( 'd', $d, 'beta', false ) && array() === fh_stored(), json_encode( fh_stored() ) );

echo "3. A request that started before a section was switched on switches it off\n";
$e = fh_start( 'e' ); // read: nothing is on
$f = fh_start( 'f' );
fh_click( 'f', $f, 'gamma', true );
check( 'it is really switched off, not "nothing changed"', true === fh_click( 'e', $e, 'gamma', false ) && array() === fh_stored() && ! $e->is_enabled( 'gamma' ), json_encode( fh_stored() ) );

echo "4. Seventeen clicks at once would be seventeen of these: every one is kept\n";
$GLOBALS['fh_db'][ FH_KEY ] = array();
$requests                   = array();
foreach ( array( 'alpha', 'beta', 'gamma' ) as $slug ) {
	$requests[ $slug ] = fh_start( 'many-' . $slug );
}
$answers = array();
foreach ( array( 'gamma', 'alpha', 'beta' ) as $slug ) {
	$answers[] = fh_click( 'many-' . $slug, $requests[ $slug ], $slug, true );
}
check( 'all three answered saved and all three are stored', array( true, true, true ) === $answers && array( 'gamma', 'alpha', 'beta' ) === fh_stored(), json_encode( fh_stored() ) );

echo "5. A click that changes nothing\n";
$g      = fh_start( 'g' );
$writes = $GLOBALS['fh_writes'];
check( 'on, already on: answered as saved, nothing written', true === fh_click( 'g', $g, 'alpha', true ) && $writes === $GLOBALS['fh_writes'] && array( 'lock', 'read', 'unlock' ) === $GLOBALS['fh_trace'], implode( ',', $GLOBALS['fh_trace'] ) );
$GLOBALS['fh_db'][ FH_KEY ] = array( 'alpha' );
$h                          = fh_start( 'h' );
check( 'off, already off: the same', true === fh_click( 'h', $h, 'beta', false ) && array( 'alpha' ) === fh_stored() );
check( 'a section that does not exist: refused before anything is read or locked', false === fh_click( 'h', $h, 'delta', true ) && array() === $GLOBALS['fh_trace'] && array( 'alpha' ) === fh_stored() );

echo "6. Without the lock the save still goes ahead, on the list as stored now\n";
$GLOBALS['wpdb']->grants = '0'; // the database would not give it in time
$i                       = fh_start( 'i' );
$j                       = fh_start( 'j' );
fh_click( 'j', $j, 'beta', true );
check( 'not granted: saved all the same, and nothing is given back', true === fh_click( 'i', $i, 'gamma', true ) && array( 'alpha', 'beta', 'gamma' ) === fh_stored() && array( 'lock', 'read', 'write' ) === $GLOBALS['fh_trace'], implode( ',', $GLOBALS['fh_trace'] ) );
$GLOBALS['wpdb'] = null; // no database object at all
$k               = fh_start( 'k' );
check( 'no database object: saved, no error', true === fh_click( 'k', $k, 'gamma', false ) && array( 'alpha', 'beta' ) === fh_stored() && array( 'read', 'write' ) === $GLOBALS['fh_trace'] );
$GLOBALS['wpdb'] = new Fh_Switch_Db();

echo "7. The stored value is no list by the time of the click\n";
$GLOBALS['fh_db'][ FH_KEY ] = array();
$l                          = fh_start( 'l' );
$GLOBALS['fh_db'][ FH_KEY ] = 'garbage';
check( 'read as nothing on; the click is saved as a clean list', true === fh_click( 'l', $l, 'beta', true ) && array( 'beta' ) === fh_stored(), json_encode( fh_stored() ) );
$m                          = fh_start( 'm' );
$GLOBALS['fh_db'][ FH_KEY ] = array( 3 => 'alpha', 7 => 'beta' );
check( 'a list with gaps in its keys is written back without them', true === fh_click( 'm', $m, 'gamma', true ) && array( 'alpha', 'beta', 'gamma' ) === fh_stored() );

echo "\n{$fh_passes} passed, {$fh_failures} failed.\n";
exit( $fh_failures > 0 ? 1 : 0 );
