<?php
/**
 * Boot test — switching the section on must not ask WooCommerce for its
 * order statuses before `init`. Their names are translated, and a
 * translation loaded at `plugins_loaded` (where the sections boot) logs
 * "Translation loading for the woocommerce domain was triggered too early"
 * on every request. The nightly event is still armed, at `init`.
 *
 * Run from the plugin root:
 *   php includes/sections/weather/tests/boot-test.php
 *
 * @package FisHotel\Misc\Sections\Weather
 */

// What the shared stubs leave out: the action registry, WooCommerce itself.
$GLOBALS['fh_hooks']          = array(); // hook => callbacks, in the order they were added
$GLOBALS['fh_status_calls']   = array(); // 'early' | 'init or later', one per wc_get_order_statuses() call
$GLOBALS['fh_running_action'] = '';

function add_action( $hook, $callback, $priority = 10, $args = 1 ) {
	$GLOBALS['fh_hooks'][ $hook ][] = $callback;
	return true;
}

function fh_do_action( $hook ) {
	$GLOBALS['fh_running_action'] = $hook;
	foreach ( $GLOBALS['fh_hooks'][ $hook ] ?? array() as $callback ) {
		call_user_func( $callback );
	}
}

function is_admin() {
	return false;
}

function wc_get_order_statuses() {
	$GLOBALS['fh_status_calls'][] = 'init' === $GLOBALS['fh_running_action'] ? 'init or later' : 'early';
	return array(
		'wc-processing'  => 'Processing',
		'wc-on-hold'     => 'On hold',
		'wc-completed'   => 'Completed',
		'wc-fulfillment' => 'Fulfillment',
	);
}

class WooCommerce {}

require_once __DIR__ . '/wp-stubs.php';

use FisHotel\Misc\Sections\Weather\Cron;
use FisHotel\Misc\Sections\Weather\Weather;

echo "1. Booting (plugins_loaded)\n";
$GLOBALS['fh_running_action'] = 'plugins_loaded';
Weather::boot();
check( 'WooCommerce is not asked for its order statuses yet', array() === $GLOBALS['fh_status_calls'], implode( ',', $GLOBALS['fh_status_calls'] ) );
check( 'nothing is scheduled yet', false === wp_next_scheduled( Cron::HOOK ) );
check( 'the nightly job listens for its event', in_array( array( Cron::class, 'run' ), $GLOBALS['fh_hooks'][ Cron::HOOK ] ?? array(), true ) );
check( 'arming waits for init', in_array( array( Weather::class, 'arm' ), $GLOBALS['fh_hooks']['init'] ?? array(), true ) );

echo "2. init\n";
fh_do_action( 'init' );
check( 'the settings are read now — the statuses asked for at init, not before', array( 'init or later' ) === array_unique( $GLOBALS['fh_status_calls'] ), implode( ',', $GLOBALS['fh_status_calls'] ) );
$armed = wp_next_scheduled( Cron::HOOK );
check( 'the nightly event is armed', is_int( $armed ) && $armed > time() - 60 );
fh_do_action( 'init' );
check( 'a later request leaves the armed event alone', $armed === wp_next_scheduled( Cron::HOOK ) );

echo "3. Switched off in its settings\n";
wp_clear_scheduled_hook( Cron::HOOK );
update_option( Weather::OPTION_KEY, array( 'enabled' => false ) );
fh_do_action( 'init' );
check( 'nothing is armed', false === wp_next_scheduled( Cron::HOOK ) );

fh_finish();
