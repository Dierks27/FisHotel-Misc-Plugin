<?php
/**
 * Helper for labels-test.php — NOT a test. The Clean Room's Front Desk
 * ends its page with a real exit, so each page request is played in a
 * process of its own: this script sets a pretend site up from a scenario
 * (which sections are on, who is logged in to WordPress, whether the
 * device is enrolled, who unlocked it), plays the request through init,
 * wp_loaded and template_redirect, and — wherever the request ends —
 * prints what happened after the page, behind a marker line.
 *
 * Usage: php page-child.php <base64 of a JSON scenario>
 *   shop_app  bool    whether the Shop App section is switched on
 *   native    string  owner|manager|customer: logged in to WordPress as …
 *   enrol     bool    the device is an enrolled shop device
 *   unlock    string  a PIN typed on it (4821 = Sam, 1379 = Robin)
 *   wait      int     seconds that pass after the unlock
 *   revoke    bool    the device is revoked afterwards
 *   lock      bool    Lock is pressed afterwards
 *   uri       string  the address then asked for
 *
 * Exits 0 at once when run without a scenario (CI runs every file in tests/).
 *
 * @package FisHotel\Misc\Sections\Shop_App
 */

if ( empty( $argv[1] ) ) {
	exit( 0 );
}

require_once __DIR__ . '/wp-stubs.php';

use FisHotel\Misc\Sections\Clean_Room\Front_Desk;
use FisHotel\Misc\Sections\Shop_App\Access;
use FisHotel\Misc\Sections\Shop_App\People;

$scenario = json_decode( (string) base64_decode( $argv[1] ), true );
$scenario = is_array( $scenario ) ? $scenario : array();
$shop_app = ! empty( $scenario['shop_app'] );

fh_fresh_store();
fh_boot_section( true, $shop_app );

$users = array(
	'owner'    => fh_add_user( 'owner', 'administrator', 'Olive Owner' ),
	'manager'  => fh_add_user( 'manager', 'shop_manager', 'Morgan Manager' ),
	'customer' => fh_add_user( 'customer', 'customer', 'Casey Customer' ),
);

$jar = array();
fh_request();
fh_add_person( 'Sam', '4821' );
fh_add_person( 'Robin', '1379', $users['manager'] );

if ( ! empty( $scenario['enrol'] ) ) {
	$device = Access::instance()->enrol_here( 'Shop tablet', $users['owner'] );
	$jar    = fh_jar( $jar );
	if ( ! empty( $scenario['unlock'] ) ) {
		fh_request( array( 'cookies' => $jar ) );
		Access::instance()->unlock( (string) $scenario['unlock'] );
		$jar = fh_jar( $jar );
	}
	if ( ! empty( $scenario['wait'] ) ) {
		$GLOBALS['fh_now'] += (int) $scenario['wait'];
	}
	if ( ! empty( $scenario['lock'] ) ) {
		$kept = $jar; // The page stays open in the browser; the cookies it sends are what Lock left.
		fh_request( array( 'cookies' => $jar ) );
		Access::instance()->lock();
		$jar = fh_jar( $kept );
	}
	if ( ! empty( $scenario['revoke'] ) ) {
		fh_request();
		Access::instance()->revoke( $device['id'], $users['owner'] );
	}
}
if ( ! empty( $scenario['native'] ) && isset( $users[ $scenario['native'] ] ) ) {
	$jar['wordpress_logged_in_test'] = (string) $users[ $scenario['native'] ];
}

$GLOBALS['fh_child'] = array(
	'halt'     => '',
	'location' => '',
);

register_shutdown_function(
	static function () use ( $users, $shop_app ) {
		// What the request itself cost this section — taken before this function asks anything.
		$queries = fh_store()->queries;
		$reads   = array_values(
			array_filter(
				$GLOBALS['fh_option_reads'],
				static function ( $name ) {
					return 0 === strpos( $name, 'fishotel_shopapp' );
				}
			)
		);

		$result = array(
			'halt'      => $GLOBALS['fh_child']['halt'],
			'location'  => $GLOBALS['fh_child']['location'],
			'status'    => (int) $GLOBALS['fh_sent']['status'],
			'nocache'   => (bool) $GLOBALS['fh_sent']['nocache'],
			'logged_in' => is_user_logged_in(),
			'user'      => get_current_user_id(),
			'users'     => $users,
			'staff'     => $shop_app ? People::staff_user_id( false ) : 0,
			'nonce'     => wp_create_nonce( Front_Desk::NONCE ),
			'cookies'   => array_keys( fh_cookies_set() ),
			'headers'   => $GLOBALS['fh_sent']['headers'],
			'nocache_c' => defined( 'DONOTCACHEPAGE' ) && DONOTCACHEPAGE,
			'queries'   => $queries,
			'reads'     => $reads,
		);
		echo "\n@@FH-RESULT@@" . json_encode( $result );
	}
);

fh_request(
	array(
		'uri'     => isset( $scenario['uri'] ) ? (string) $scenario['uri'] : '/',
		'cookies' => $jar,
	)
);

try {
	do_action( 'init' );
	do_action( 'wp_loaded' );
	do_action( 'template_redirect' ); // The Front Desk renders here and ends the request itself.
	$GLOBALS['fh_child']['halt'] = 'none';
} catch ( FH_Halt $e ) {
	$GLOBALS['fh_child']['halt']     = $e->kind;
	$GLOBALS['fh_child']['location'] = 'redirect' === $e->kind ? (string) $e->data : '';
	if ( 'redirect' === $e->kind ) {
		$GLOBALS['fh_sent']['status'] = (int) $e->status;
	}
}
