<?php
/**
 * Section off is silent, and each mode starts only what it should:
 *
 *   loading every class file (as the Section Manager does, on or off)
 *   registers no hook and sends no query; switched off, boot_disabled()
 *   only takes a left-over job off the schedule; mode Off, Shadow, Live
 *   and "Live but the old plugin is back" register exactly their hooks on
 *   a front-end request — and none of them sends a query while booting.
 *
 * Run from the plugin root:  php includes/sections/gift-cards/tests/boot-test.php
 */

require_once __DIR__ . '/wp-stubs.php';

use FisHotel\Misc\Sections\Gift_Cards\Cron;
use FisHotel\Misc\Sections\Gift_Cards\Gift_Cards;
use FisHotel\Misc\Sections\Gift_Cards\Modes;
use FisHotel\Misc\Sections\Gift_Cards\Store;

/**
 * Every hook name registered so far.
 */
function fh_hooks() {
	$out = array();
	foreach ( array( 'fh_actions', 'fh_filters' ) as $reg ) {
		foreach ( $GLOBALS[ $reg ] as $hook => $list ) {
			if ( $list ) {
				$out[] = $hook;
			}
		}
	}
	sort( $out );
	return $out;
}

function fh_clear_hooks() {
	$GLOBALS['fh_actions'] = array();
	$GLOBALS['fh_filters'] = array();
}

$before_hooks   = fh_hooks();
$before_queries = count( $GLOBALS['wpdb']->queries );
require_once __DIR__ . '/wc-stubs.php'; // loads class-gift-cards.php and the rest
require_once FISHOTEL_MISC_PATH . 'includes/sections/gift-cards/class-admin.php';
Gift_Cards::load();

echo "Section off\n";
check( 'loading every class file registers no hook', $before_hooks === fh_hooks() );
check( '…and sends no query', $before_queries === count( $GLOBALS['wpdb']->queries ) );
check( 'the section class file runs nothing when it is read (no call at the top level)', ! preg_match( '/^(add_action|add_filter|do_action|\$wpdb|new\s)/m', file_get_contents( FISHOTEL_MISC_PATH . 'includes/sections/gift-cards/class-gift-cards.php' ) ) );
$opts = $GLOBALS['fh_options'];
Gift_Cards::boot_disabled();
check( 'switched off with nothing scheduled: nothing registered, written or queried', $before_hooks === fh_hooks() && $opts === $GLOBALS['fh_options'] && $before_queries === count( $GLOBALS['wpdb']->queries ) );
$GLOBALS['fh_cron'][ Cron::HOOK ] = time() + 60;
Gift_Cards::boot_disabled();
check( 'a job left on the schedule is taken off', ! isset( $GLOBALS['fh_cron'][ Cron::HOOK ] ) );
check( 'the section arrives off: not in the enabled list by default', ! Gift_Cards::enabled() );
check( 'its card says it starts nothing until a mode is chosen', false !== strpos( \FisHotel\Misc\Sections\Gift_Cards\Gift_Cards::get_section_info()['description'], 'Starts in mode Off' ) );

// The tables exist (as after the first admin visit); count from here.
fh_gc_fresh();
Gift_Cards::use_service( null );

$shop_hooks = array( 'woocommerce_cart_calculate_fees', 'woocommerce_checkout_order_created', 'woocommerce_order_status_failed', 'woocommerce_account_giftcards_endpoint', 'woocommerce_before_add_to_cart_button', 'woocommerce_payment_complete' );

/**
 * Boot on a front-end request in a mode; the hooks it registered and
 * the queries it sent.
 */
function fh_boot( $mode, $admin = false, array $plugins = array() ) {
	fh_clear_hooks();
	update_option( Modes::OPTION, $mode );
	update_option( 'active_plugins', $plugins );
	$GLOBALS['fh_is_admin']   = $admin;
	$GLOBALS['wpdb']->queries = array();
	Gift_Cards::boot();
	$out = array(
		'hooks'   => fh_hooks(),
		'queries' => $GLOBALS['wpdb']->queries,
	);
	$GLOBALS['fh_is_admin'] = false;
	return $out;
}

echo "\nMode Off (section on)\n";
$b = fh_boot( 'off' );
check( 'a front-end request: no hook at all', array() === $b['hooks'] );
check( '…and no query', array() === $b['queries'] );
$b = fh_boot( 'off', true );
check( 'wp-admin: only the screen\'s own hooks (its form handler, its styles, its notice)', array( 'admin_enqueue_scripts', 'admin_notices', 'admin_post_fishotel_gift_cards_do' ) === $b['hooks'] );
check( '…and no query (the tables are installed, so the version gate is shut)', array() === $b['queries'] );

echo "\nMode Shadow\n";
$b = fh_boot( 'shadow', false, array( 'woocommerce-gift-cards/woocommerce-gift-cards.php' ) );
check( 'a front-end request: only the job\'s clean-up — nothing on checkout, My Account or products', array( Cron::HOOK, 'init' ) === $b['hooks'] && array() === array_intersect( $shop_hooks, $b['hooks'] ) );
check( '…and no query', array() === $b['queries'] );
check( 'at init it takes the job off the schedule, never puts it on', array( Cron::class, 'clear' ) === $GLOBALS['fh_actions']['init'][0][0] );

echo "\nMode Live\n";
$b = fh_boot( 'live' );
check( 'checkout, products and My Account are hooked', array() === array_diff( $shop_hooks, $b['hooks'] ) );
check( '…the job is armed at init (not at boot)', array( Cron::class, 'ensure_scheduled' ) === $GLOBALS['fh_actions']['init'][0][0] && ! isset( $GLOBALS['fh_cron'][ Cron::HOOK ] ) );
check( '…and booting sent no query', array() === $b['queries'] );
$b = fh_boot( 'live', true );
check( 'in wp-admin the order screen gets its Gift cards box', in_array( 'add_meta_boxes', $b['hooks'], true ) );

echo "\nLive, but the old plugin is switched on again\n";
$b = fh_boot( 'live', false, array( 'woocommerce-gift-cards/woocommerce-gift-cards.php' ) );
$pay_hooks = array( 'woocommerce_cart_calculate_fees', 'woocommerce_checkout_order_created', 'woocommerce_account_giftcards_endpoint', 'woocommerce_before_add_to_cart_button', 'woocommerce_payment_complete', 'woocommerce_order_status_processing' );
check( 'it behaves as Shadow by itself: nothing pays, nothing on My Account or products', array() === array_intersect( $pay_hooks, $b['hooks'] ) );
check( '…but money these cards took still goes back: failed, cancelled, refunded, trashed and deleted stay hooked', array() === array_diff( array( 'woocommerce_order_status_failed', 'woocommerce_order_status_cancelled', 'woocommerce_order_status_refunded', 'woocommerce_order_refunded', 'woocommerce_trash_order', 'woocommerce_before_delete_order', 'wp_trash_post', 'before_delete_post' ), $b['hooks'] ) );
$undo = array_filter( $GLOBALS['fh_actions']['woocommerce_order_status_refunded'] ?? array(), static function ( $e ) { return is_array( $e[0] ) && $e[0][0] instanceof \FisHotel\Misc\Sections\Gift_Cards\Product && 'undone_note' === $e[0][1]; } );
check( '…and so does holding back a refunded purchase\'s card e-mail', 1 === count( $undo ) );
check( '…and booting sent no query', array() === $b['queries'] );
check( 'Modes says why', array( 'stored' => 'live', 'effective' => 'shadow', 'legacy' => true, 'fallback' => true ) === Modes::current() );
$b = fh_boot( 'live', true, array( 'woocommerce-gift-cards/woocommerce-gift-cards.php' ) );
$GLOBALS['fh_user_caps']['manage_woocommerce'] = array( 1 );
$GLOBALS['fh_current_user']                   = 1;
ob_start();
foreach ( $GLOBALS['fh_actions']['admin_notices'] as $entry ) {
	call_user_func( $entry[0] );
}
check( 'and wp-admin shows a notice saying so', false !== strpos( ob_get_clean(), 'switched on again' ) );
check( 'the hourly job does nothing outside Live', array() === Cron::run() );

echo "\nThe job\n";
update_option( 'active_plugins', array() );
update_option( Modes::OPTION, 'live' );
Cron::ensure_scheduled();
check( 'Live: the job is put on the schedule once', isset( $GLOBALS['fh_cron'][ Cron::HOOK ] ) );
Cron::clear();
check( 'and taken off', ! isset( $GLOBALS['fh_cron'][ Cron::HOOK ] ) );
Gift_Cards::use_service( fh_gc_service( new Store() ) );
$run = Cron::run();
check( 'a run in Live records what it did', isset( $run['sent'], $run['at'] ) && is_array( get_option( Cron::LAST_RUN_OPTION ) ) );

echo "\nInstall\n";
$GLOBALS['wpdb']       = new Fh_Wpdb();
$GLOBALS['fh_options'] = array();
Store::forget();
check( 'a front-end request never installs', false === Store::maybe_install( false ) && array() === $GLOBALS['wpdb']->queries );
check( 'wp-admin installs once, and the gate opens', true === Store::maybe_install( true ) && Store::installed() );
$GLOBALS['wpdb']->queries = array();
check( 'then it is never tried again', false === Store::maybe_install( true ) && array() === $GLOBALS['wpdb']->queries );
update_option( Store::DB_VERSION_OPTION, 'failed:1.0:' . time() );
check( 'a failed install waits an hour before wp-admin tries again', false === Store::maybe_install( true ) );

fh_finish();
