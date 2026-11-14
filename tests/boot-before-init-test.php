<?php
/**
 * Boot before init — what a section does when it boots (boot(), and the
 * init() / boot_disabled() of the classes it starts) runs at
 * plugins_loaded, before WordPress's `init`. Static: the PHP tokenizer
 * reads the files, nothing is loaded.
 *
 * Run from the plugin root:
 *   php tests/boot-before-init-test.php
 *
 * Why: since WordPress 6.7 a translation loaded before `init` logs
 * "Translation loading for the woocommerce domain was triggered too
 * early". Scheduling a recurring event reads the cron schedules, and
 * WooCommerce translates its own schedule names in that filter; asking
 * WooCommerce for its order statuses translates them too. Health,
 * Requests, Lost Fish and Visitor Stats each logged the notice the first
 * time they were switched on (0.46 rehearsal). That work belongs on the
 * `init` hook (add_action( 'init', … )), as Weather already does it.
 *
 * What is checked: in every PHP file under includes/ (tests folders
 * skipped), the bodies of methods named boot, boot_disabled and init call
 * none of FH_TOO_EARLY directly. A name inside a callback array
 * ( array( self::class, 'ensure_scheduled' ) ) is a string, not a call,
 * and is fine. The first block proves a planted call is caught.
 *
 * @package FisHotel\Misc
 */

if ( PHP_SAPI !== 'cli' ) {
	exit( 1 );
}

$fh_checks   = 0;
$fh_failures = 0;

function check( $label, $condition, $detail = '' ) {
	global $fh_checks, $fh_failures;
	$fh_checks++;
	if ( $condition ) {
		echo "  ok   $label\n";
		return;
	}
	$fh_failures++;
	echo "  FAIL $label" . ( '' !== $detail ? "\n       $detail" : '' ) . "\n";
}

/**
 * Calls that read something WooCommerce translates (directly, or through
 * the cron_schedules filter), or this plugin's own wrappers around them.
 */
const FH_TOO_EARLY = array( 'wp_schedule_event', 'wp_reschedule_event', 'wp_get_schedules', 'wc_get_order_statuses', 'ensure_scheduled', 'ensure_tick' );

/**
 * Methods that run while the sections boot.
 */
const FH_BOOT_METHODS = array( 'boot', 'boot_disabled', 'init' );

/**
 * Every too-early call inside a boot-time method of one PHP source.
 *
 * @param string $src PHP source.
 * @return string[] "method(): call() on line N".
 */
function fh_early_calls( $src ) {
	$tokens = array();
	foreach ( token_get_all( $src ) as $t ) {
		if ( is_array( $t ) && in_array( $t[0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
			continue;
		}
		$tokens[] = $t;
	}

	$found = array();
	$count = count( $tokens );
	for ( $i = 0; $i < $count - 2; $i++ ) {
		if ( ! is_array( $tokens[ $i ] ) || T_FUNCTION !== $tokens[ $i ][0] || ! is_array( $tokens[ $i + 1 ] ) || ! in_array( strtolower( $tokens[ $i + 1 ][1] ), FH_BOOT_METHODS, true ) ) {
			continue;
		}
		$method = $tokens[ $i + 1 ][1];

		// Skip to the body's opening brace (an abstract / interface method has none).
		$j = $i + 2;
		while ( $j < $count && '{' !== $tokens[ $j ] && ';' !== $tokens[ $j ] ) {
			$j++;
		}
		if ( $j >= $count || ';' === $tokens[ $j ] ) {
			continue;
		}

		$depth = 0;
		for ( ; $j < $count; $j++ ) {
			$t    = $tokens[ $j ];
			$text = is_array( $t ) ? $t[1] : $t;
			if ( '{' === $text || ( is_array( $t ) && in_array( $t[0], array( T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES ), true ) ) ) {
				$depth++;
			} elseif ( '}' === $text ) {
				$depth--;
				if ( 0 === $depth ) {
					break;
				}
			} elseif ( is_array( $t ) && T_STRING === $t[0] && in_array( strtolower( $t[1] ), FH_TOO_EARLY, true ) && isset( $tokens[ $j + 1 ] ) && '(' === $tokens[ $j + 1 ] ) {
				$found[] = $method . '(): ' . $t[1] . '() on line ' . $t[2];
			}
		}
		$i = $j;
	}

	return $found;
}

echo "1. The check itself\n";
$fh_planted = '<?php class A { public static function boot() { add_action( "init", array( self::class, "ensure_scheduled" ) ); if ( ! wp_next_scheduled( "h" ) ) { wp_schedule_event( time(), "daily", "h" ); } } public static function arm() { wp_schedule_event( time(), "daily", "h" ); } }';
check( 'a wp_schedule_event() in boot() is caught', array( 'boot(): wp_schedule_event() on line 1' ) === fh_early_calls( $fh_planted ), implode( ' | ', fh_early_calls( $fh_planted ) ) );
check( '… a helper called in init() too', 1 === count( fh_early_calls( '<?php class B { public static function init() { add_action( "x", "y" ); self::ensure_scheduled(); } }' ) ) );
check( '… and Cron::ensure_tick() / wc_get_order_statuses()', 2 === count( fh_early_calls( '<?php class C { public function init() { Cron::ensure_tick(); $s = wc_get_order_statuses(); } }' ) ) );
check( 'a callback naming the method, or a call in a method hooked to init, is fine', array() === fh_early_calls( '<?php class D { public static function boot() { add_action( "init", array( self::class, "ensure_scheduled" ), 10, 0 ); } public static function ensure_scheduled() { wp_schedule_event( 1, "daily", "h" ); } }' ) );

echo "2. Every boot-time method of the plugin\n";
$fh_root  = dirname( __DIR__ );
$fh_files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $fh_root . '/includes', FilesystemIterator::SKIP_DOTS ) );
$fh_seen  = 0;
$fh_bad   = array();
foreach ( $fh_files as $fh_file ) {
	$fh_path = str_replace( '\\', '/', $fh_file->getPathname() );
	if ( '.php' !== substr( $fh_path, -4 ) || false !== strpos( $fh_path, '/tests/' ) ) {
		continue;
	}
	$fh_seen++;
	foreach ( fh_early_calls( (string) file_get_contents( $fh_path ) ) as $fh_hit ) {
		$fh_bad[] = substr( $fh_path, strlen( $fh_root ) + 1 ) . ' ' . $fh_hit;
	}
}
check( 'files read: ' . $fh_seen, $fh_seen > 50 );
check( 'no boot(), boot_disabled() or init() schedules a recurring event or asks WooCommerce for its order statuses before init', array() === $fh_bad, implode( "\n       ", $fh_bad ) );

$fh_src = (string) file_get_contents( $fh_root . '/includes/sections/visitor-stats/class-visitor-stats.php' );
check( 'Visitor Stats arms its daily prune at init', false !== strpos( $fh_src, "add_action( 'init', array( self::class, 'ensure_scheduled' ), 10, 0 );" ) );

echo "\n" . ( $fh_failures ? "$fh_failures FAILED of $fh_checks\n" : "all $fh_checks passed\n" );
exit( $fh_failures ? 1 : 0 );
