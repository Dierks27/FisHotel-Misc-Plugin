<?php
/**
 * The daily job is armed at `init`, not when the section boots
 * (plugins_loaded). wp_schedule_event() reads the cron schedules, and
 * WooCommerce translates its own schedule names in that filter — loaded
 * before init, WordPress logs "Translation loading for the woocommerce
 * domain was triggered too early" the first time the section is switched
 * on. The run time and what the job does are unchanged.
 *
 * Run from the plugin root:
 *   php includes/sections/requests/tests/cron-boot-test.php
 *
 * @package FisHotel\Misc\Sections\Requests
 */

// WP-Cron, reduced to a list of pending events; each notes whether init had run.
$GLOBALS['fh_cron']     = array();
$GLOBALS['fh_init_ran'] = false;

function wp_schedule_event( $timestamp, $recurrence, $hook, $args = array() ) {
	$GLOBALS['fh_cron'][] = array( (int) $timestamp, (string) $hook, $recurrence, $GLOBALS['fh_init_ran'] ? 'init or later' : 'early' );
	return true;
}

function wp_next_scheduled( $hook, $args = array() ) {
	foreach ( $GLOBALS['fh_cron'] as $event ) {
		if ( $event[1] === $hook ) {
			return $event[0];
		}
	}
	return false;
}

require_once __DIR__ . '/wp-stubs.php';
require_once FISHOTEL_MISC_PATH . 'includes/sections/requests/class-cron.php';

use FisHotel\Misc\Sections\Requests\Cron;

echo "Booting (plugins_loaded)\n";
$GLOBALS['fh_actions'] = array();
Cron::init();
check_same( 'nothing is scheduled yet', array(), $GLOBALS['fh_cron'] );
check( 'the job listens for its event', isset( $GLOBALS['fh_actions'][ Cron::HOOK ] ) );
check( 'arming waits for init', isset( $GLOBALS['fh_actions']['init'] ) && array( Cron::class, 'ensure_scheduled' ) === $GLOBALS['fh_actions']['init'][0][0] );

echo "init\n";
$GLOBALS['fh_init_ran'] = true;
$fh_before              = time();
do_action( 'init' );
check_same( 'one daily event, armed at init', array( array( Cron::HOOK, 'daily', 'init or later' ) ), array_map( static function ( $e ) { return array( $e[1], $e[2], $e[3] ); }, $GLOBALS['fh_cron'] ) );
check_same( '… for the next 09:15 site time, as before', Cron::next_run( Cron::RUN_TIME, wp_timezone(), $fh_before ), $GLOBALS['fh_cron'][0][0] );

echo "The next request\n";
$GLOBALS['fh_actions'] = array();
Cron::init();
do_action( 'init' );
check_same( 'leaves the armed event alone', 1, count( $GLOBALS['fh_cron'] ) );

fh_finish();
