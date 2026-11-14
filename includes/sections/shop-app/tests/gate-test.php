<?php
/**
 * THE GATE — a shop session is not a WordPress login.
 *
 * With the Clean Room's real Front Desk loaded and WordPress's own
 * "who is logged in" machinery standing in (see wp-stubs.php), this plays
 * request after request from a device with a live shop session and
 * checks:
 *   - which requests count as shop-floor requests at all (classify(), by
 *     method, path and parameter names — before any cookie is believed);
 *   - that on wp-admin, every other admin-ajax action, admin-post, the
 *     REST API (by path and by ?rest_route=), wp-login, the shop front,
 *     My Account and checkout the device is not logged in, and that the
 *     session costs those requests no query;
 *   - that on the Front Desk page and its AJAX the device is the account
 *     the person's work is recorded under, WordPress nonces made on the
 *     page verify on the AJAX call, and the Front Desk's own handlers
 *     accept the call;
 *   - that an AJAX call is NOBODY'S until its action starts: whatever
 *     runs on init, wp_loaded or admin_init and acts for "the current
 *     user" on a field somebody added to the POST (a basket's
 *     add-to-cart) acts for a visitor, never for the account;
 *   - that the account holds exactly the Front Desk's capability, and
 *     only once the handler starts — an administrator's own account, a
 *     multisite super admin and the shared "Shop staff" account alike;
 *   - precedence (a live session over a WordPress login on shop-floor
 *     requests, a WordPress login untouched everywhere else), a current
 *     user worked out before this section loaded, a filter that asks who
 *     the user is while the answer is being worked out;
 *   - the filters other sections add screens with;
 *   - that nothing on the admin side accepts a shop session.
 *
 * Run from the plugin root:
 *   php includes/sections/shop-app/tests/gate-test.php
 *
 * @package FisHotel\Misc\Sections\Shop_App
 */

require_once __DIR__ . '/wp-stubs.php';

use FisHotel\Misc\Sections\Clean_Room\Front_Desk;
use FisHotel\Misc\Sections\Shop_App\Access;
use FisHotel\Misc\Sections\Shop_App\Admin;
use FisHotel\Misc\Sections\Shop_App\App;
use FisHotel\Misc\Sections\Shop_App\Gate;
use FisHotel\Misc\Sections\Shop_App\Http;
use FisHotel\Misc\Sections\Shop_App\People;
use FisHotel\Misc\Sections\Shop_App\Shop_App;

$store = fh_fresh_store();
fh_boot_section( true );

$wc_cookie = 'wp_woocommerce_session_abc123';

echo "1. Which requests are shop-floor requests (pure)\n";
$rules = Gate::rules();
check( 'by default: the Front Desk page (a tank, the day\'s list, the calculator) and its four AJAX actions, nothing else', 5 === count( $rules ) && 'page' === $rules[0]['type'] && 'fh_front_desk' === $rules[0]['query_var'] && array( 'tank', 'list', 'calc' ) === array_keys( $rules[0]['params'] ) && array( 'fishotel_cleanroom_act', 'fishotel_cleanroom_parse', 'fishotel_cleanroom_suggest', 'fishotel_cleanroom_screen' ) === array_column( array_slice( $rules, 1 ), 'action' ) );

/**
 * The facts of a request, for classify().
 */
function fh_facts( $method, $uri, array $extra = array() ) {
	parse_str( (string) parse_url( $uri, PHP_URL_QUERY ), $get );
	$path = (string) parse_url( $uri, PHP_URL_PATH );
	return array_merge(
		array(
			'method'      => $method,
			'path'        => $path,
			'home_path'   => '/',
			'ajax_path'   => '/wp-admin/admin-ajax.php',
			'get'         => $get,
			'post_action' => '',
			'is_admin'    => 0 === strpos( $path, '/wp-admin/' ),
			'doing_ajax'  => '/wp-admin/admin-ajax.php' === $path,
			'special'     => false,
		),
		$extra
	);
}

$is = static function ( $method, $uri, array $extra = array() ) use ( $rules ) {
	$rule = Gate::classify( fh_facts( $method, $uri, $extra ), $rules );
	return $rule ? $rule['type'] . ':' . ( 'ajax' === $rule['type'] ? $rule['action'] : $rule['query_var'] ) : '';
};

check( 'GET /?fh_front_desk=1&tank=521 — the label address', 'page:fh_front_desk' === $is( 'GET', '/?fh_front_desk=1&tank=521' ) );
check( 'GET /?fh_front_desk=1 — the Front Desk\'s own tank picker', 'page:fh_front_desk' === $is( 'GET', '/?fh_front_desk=1' ) );
check( 'the parameters in the other order, /index.php, a HEAD', 'page:fh_front_desk' === $is( 'GET', '/?tank=521&fh_front_desk=1' ) && 'page:fh_front_desk' === $is( 'GET', '/index.php?fh_front_desk=1&tank=521' ) && 'page:fh_front_desk' === $is( 'HEAD', '/?fh_front_desk=1' ) );
check( 'a site in a folder: its own home path, not the server\'s', 'page:fh_front_desk' === $is( 'GET', '/fish/?fh_front_desk=1', array( 'home_path' => '/fish/' ) ) && '' === $is( 'GET', '/?fh_front_desk=1', array( 'home_path' => '/fish/' ) ) );

foreach ( array(
	'a POST of the page'                                 => array( 'POST', '/?fh_front_desk=1&tank=521' ),
	'the switch off (0)'                                 => array( 'GET', '/?fh_front_desk=0&tank=521' ),
	'the switch empty'                                   => array( 'GET', '/?fh_front_desk=&tank=521' ),
	'the switch as an array'                             => array( 'GET', '/?fh_front_desk[]=1' ),
	'a tank that is not a number'                        => array( 'GET', '/?fh_front_desk=1&tank=521abc' ),
	'a tank with a line break after it'                  => array( 'GET', '/?fh_front_desk=1&tank=521%0A' ),
	'a tank as an array'                                 => array( 'GET', '/?fh_front_desk=1&tank[]=521' ),
	'any other parameter beside it'                      => array( 'GET', '/?fh_front_desk=1&tank=521&preview=true' ),
	'the REST API smuggled in beside it'                 => array( 'GET', '/?fh_front_desk=1&rest_route=/wp/v2/users/me' ),
	'a post id beside it'                                => array( 'GET', '/?fh_front_desk=1&p=1' ),
	'WooCommerce\'s AJAX beside it'                      => array( 'GET', '/?fh_front_desk=1&wc-ajax=get_refreshed_fragments' ),
	'add-to-cart beside it'                              => array( 'GET', '/?fh_front_desk=1&add-to-cart=7' ),
	'the shop front'                                     => array( 'GET', '/' ),
	'a product page'                                     => array( 'GET', '/product/invented-goby/' ),
	'another path with the switch'                       => array( 'GET', '/shop/?fh_front_desk=1' ),
	'My Account with the switch'                         => array( 'GET', '/my-account/?fh_front_desk=1' ),
	'checkout with the switch'                           => array( 'GET', '/checkout/?fh_front_desk=1' ),
	'the REST API by path'                               => array( 'GET', '/wp-json/wp/v2/users/me' ),
	'the REST API by path, with the switch'              => array( 'GET', '/wp-json/wc/v3/orders?fh_front_desk=1' ),
	'the REST API by parameter'                          => array( 'GET', '/?rest_route=/wc/v3/customers' ),
	'wp-login with the switch'                           => array( 'GET', '/wp-login.php?fh_front_desk=1' ),
	'wp-cron with the switch'                            => array( 'GET', '/wp-cron.php?fh_front_desk=1' ),
	'xmlrpc.php'                                         => array( 'POST', '/xmlrpc.php' ),
	'a doubled slash'                                    => array( 'GET', '//?fh_front_desk=1' ),
	'the app\'s own address (it reads the cookies itself)' => array( 'GET', '/?fh_shop_app=state' ),
	'wp-admin'                                           => array( 'GET', '/wp-admin/' ),
	'wp-admin with the switch'                           => array( 'GET', '/wp-admin/index.php?fh_front_desk=1' ),
	'the Clean Room board'                               => array( 'GET', '/wp-admin/admin.php?page=fishotel-misc-clean-room' ),
	'admin-post'                                         => array( 'POST', '/wp-admin/admin-post.php', array( 'post_action' => 'fishotel_cleanroom_act' ) ),
	'admin-ajax as a GET'                                => array( 'GET', '/wp-admin/admin-ajax.php', array( 'post_action' => 'fishotel_cleanroom_act' ) ),
	'admin-ajax with the action in the address'          => array( 'POST', '/wp-admin/admin-ajax.php?action=fishotel_cleanroom_act' ),
	'admin-ajax with anything in the address'            => array( 'POST', '/wp-admin/admin-ajax.php?export=orders', array( 'post_action' => 'fishotel_cleanroom_act' ) ),
	'admin-ajax, another action'                         => array( 'POST', '/wp-admin/admin-ajax.php', array( 'post_action' => 'woocommerce_json_search_customers' ) ),
	'admin-ajax, the Clean Room\'s admin action'         => array( 'POST', '/wp-admin/admin-ajax.php', array( 'post_action' => 'fishotel_cleanroom_stay' ) ),
	'admin-ajax, a Front Desk action with a letter added' => array( 'POST', '/wp-admin/admin-ajax.php', array( 'post_action' => 'fishotel_cleanroom_acts' ) ),
	'admin-ajax, the heartbeat'                          => array( 'POST', '/wp-admin/admin-ajax.php', array( 'post_action' => 'heartbeat' ) ),
	'admin-ajax, no action'                              => array( 'POST', '/wp-admin/admin-ajax.php' ),
	'something that only claims to be AJAX, on the front' => array( 'POST', '/', array( 'post_action' => 'fishotel_cleanroom_act', 'doing_ajax' => true ) ),
	'a Front Desk action posted to another admin script' => array( 'POST', '/wp-admin/async-upload.php', array( 'post_action' => 'fishotel_cleanroom_act', 'doing_ajax' => true ) ),
	'no path at all'                                     => array( 'GET', '', array( 'path' => '', 'get' => array( 'fh_front_desk' => '1' ) ) ),
	'cron, XML-RPC, REST, WP-CLI, the installer'         => array( 'GET', '/?fh_front_desk=1', array( 'special' => true ) ),
) as $what => $r ) {
	check( 'not one: ' . $what, '' === $is( $r[0], $r[1], isset( $r[2] ) ? $r[2] : array() ) );
}

foreach ( array( 'fishotel_cleanroom_act', 'fishotel_cleanroom_parse', 'fishotel_cleanroom_suggest', 'fishotel_cleanroom_screen' ) as $action ) {
	check( 'POST admin-ajax.php, action ' . $action, 'ajax:' . $action === $is( 'POST', '/wp-admin/admin-ajax.php', array( 'post_action' => $action ) ) );
}
check( 'malformed rules are dropped', array() === Gate::clean_rules( array( 'x', array(), array( 'type' => 'ajax' ), array( 'type' => 'ajax', 'action' => 'Bad Action' ), array( 'type' => 'page', 'query_var' => '' ), array( 'type' => 'page' ), array( 'type' => 'everything' ) ) ) && array() === Gate::clean_rules( 'nonsense' ) );
check( 'with no rules nothing is a shop-floor request', null === Gate::classify( fh_facts( 'GET', '/?fh_front_desk=1' ), array() ) );

echo "\n2. A tablet with a live shop session\n";
$owner    = fh_add_user( 'owner', 'administrator', 'Olive Owner' );
$manager  = fh_add_user( 'manager', 'shop_manager', 'Morgan Manager' );
$customer = fh_add_user( 'customer', 'customer', 'Casey Customer' );
fh_request();
$sam    = fh_add_person( 'Sam', '4821' );            // The shared "Shop staff" account.
$olive  = fh_add_person( 'Olive', '7007', $owner );  // An administrator's own account.
$tablet = Access::instance()->enrol_here( 'Shop tablet', $owner );
$jar    = fh_jar( array() );
fh_request( array( 'cookies' => $jar ) );
Access::instance()->unlock( '4821' );
$jar   = fh_jar( $jar );
$staff = People::staff_user_id( false );
check( 'Sam unlocked the tablet; the work is recorded under the shared account', $staff > 0 && isset( $jar[ Http::cookie_name( Http::SESSION ) ] ) );

/**
 * Play a request up to the point where WordPress has decided who is
 * logged in (init), and say what it decided.
 */
function fh_visit( array $request ) {
	fh_request( $request );
	do_action( 'init' );
	if ( ! isset( $_GET['fh_shop_app'] ) ) {
		do_action( 'wp_loaded' ); // The app's own address is answered there and the request ends (see manifest-test.php).
	}
	return array(
		'logged_in' => is_user_logged_in(),
		'user'      => get_current_user_id(),
		'can'       => current_user_can( 'manage_woocommerce' ),
		'session'   => Gate::is_shop_session(),
		'queries'   => fh_store()->queries,
		'reads'     => $GLOBALS['fh_option_reads'],
	);
}

$elsewhere = array(
	'wp-admin'                              => array( 'uri' => '/wp-admin/index.php', 'admin' => true ),
	'the Shop App admin page'               => array( 'uri' => '/wp-admin/admin.php?page=fishotel-misc-shop-app', 'admin' => true ),
	'the Clean Room board'                  => array( 'uri' => '/wp-admin/admin.php?page=fishotel-misc-clean-room', 'admin' => true ),
	'the orders list'                       => array( 'uri' => '/wp-admin/admin.php?page=wc-orders', 'admin' => true ),
	'admin-post (add a person)'             => array( 'method' => 'POST', 'uri' => '/wp-admin/admin-post.php', 'admin' => true, 'post' => array( 'action' => 'fishotel_shop_app_person' ) ),
	'admin-ajax: make an enrolment link'    => array( 'method' => 'POST', 'uri' => '/wp-admin/admin-ajax.php', 'admin' => true, 'ajax' => true, 'post' => array( 'action' => 'fishotel_shop_app_link' ) ),
	'admin-ajax: the Clean Room admin AJAX' => array( 'method' => 'POST', 'uri' => '/wp-admin/admin-ajax.php', 'admin' => true, 'ajax' => true, 'post' => array( 'action' => 'fishotel_cleanroom_stay' ) ),
	'admin-ajax: switch a section on'       => array( 'method' => 'POST', 'uri' => '/wp-admin/admin-ajax.php', 'admin' => true, 'ajax' => true, 'post' => array( 'action' => 'fishotel_misc_toggle_section' ) ),
	'admin-ajax: WooCommerce customer search' => array( 'method' => 'POST', 'uri' => '/wp-admin/admin-ajax.php', 'admin' => true, 'ajax' => true, 'post' => array( 'action' => 'woocommerce_json_search_customers' ) ),
	'admin-ajax: a Front Desk action as a GET' => array( 'uri' => '/wp-admin/admin-ajax.php?action=fishotel_cleanroom_act', 'admin' => true, 'ajax' => true ),
	'admin-ajax: a Front Desk action with something in the address' => array( 'method' => 'POST', 'uri' => '/wp-admin/admin-ajax.php?x=1', 'admin' => true, 'ajax' => true, 'post' => array( 'action' => 'fishotel_cleanroom_act' ) ),
	'the REST API: /wp-json/wp/v2/users/me' => array( 'uri' => '/wp-json/wp/v2/users/me' ),
	'the REST API: /wp-json/wc/v3/orders'   => array( 'uri' => '/wp-json/wc/v3/orders' ),
	'the REST API: ?rest_route='            => array( 'uri' => '/?rest_route=/wc/v3/customers' ),
	'the REST API with the Front Desk switch beside it' => array( 'uri' => '/?rest_route=/wp/v2/users&fh_front_desk=1' ),
	'the shop front'                        => array( 'uri' => '/' ),
	'the shop page'                         => array( 'uri' => '/shop/' ),
	'a product'                             => array( 'uri' => '/product/invented-goby/' ),
	'the basket'                            => array( 'uri' => '/cart/' ),
	'adding to the basket'                  => array( 'uri' => '/?add-to-cart=7' ),
	'My Account'                            => array( 'uri' => '/my-account/' ),
	'My Account → Orders'                   => array( 'uri' => '/my-account/orders/' ),
	'My Account with the switch'            => array( 'uri' => '/my-account/?fh_front_desk=1' ),
	'checkout'                              => array( 'uri' => '/checkout/' ),
	'placing an order'                      => array( 'method' => 'POST', 'uri' => '/?wc-ajax=checkout' ),
	'wp-login'                              => array( 'uri' => '/wp-login.php' ),
	'a search'                              => array( 'uri' => '/?s=tang' ),
	'the app\'s own state address'          => array( 'uri' => '/?fh_shop_app=state' ),
);
foreach ( $elsewhere as $what => $request ) {
	$seen = fh_visit( array_merge( $request, array( 'cookies' => $jar ) ) );
	check( 'not logged in: ' . $what, false === $seen['logged_in'] && 0 === $seen['user'] && false === $seen['can'] && false === $seen['session'], $seen );
	if ( 0 !== $seen['queries'] || array() !== $seen['reads'] ) {
		check( '… and it cost no query: ' . $what, false, $seen );
	}
}
check( '… and none of those requests cost this section a query or an option read', true );

echo "\n3. The Front Desk page\n";
$seen = fh_visit( array( 'uri' => '/?fh_front_desk=1&tank=521', 'cookies' => $jar ) );
check( 'the label address: logged in as the session\'s account', true === $seen['logged_in'] && $staff === $seen['user'] && true === $seen['session'] );
check( 'who is working is known to other sections: fishotel_shop_app_person()', array( 'id' => $sam, 'name' => 'Sam', 'user_id' => $staff, 'device_id' => (int) $tablet['id'], 'device' => 'Shop tablet' ) === fishotel_shop_app_person() );
check( 'before the page\'s own handler starts the account can do NOTHING — not even the Front Desk\'s capability', false === $seen['can'] && ! current_user_can( 'read' ) && ! current_user_can( 'edit_posts' ) && ! current_user_can( 'manage_options' ) );
App::intercept(); // template_redirect, priority 0 — just before the Front Desk's handler.
check( 'when the page\'s handler starts it holds the Front Desk\'s capability', current_user_can( 'manage_woocommerce' ) && Gate::granted() );
check( '… and nothing else', ! current_user_can( 'manage_options' ) && ! current_user_can( 'edit_posts' ) && ! current_user_can( 'edit_users' ) && ! current_user_can( 'read' ) && ! current_user_can( 'edit_shop_orders' ) && ! current_user_can( 'edit_user', $staff ) && ! current_user_can( 'delete_users' ) );
check( 'the Front Desk\'s own two tests pass: logged in, and its capability', is_user_logged_in() && current_user_can( \FisHotel\Misc\Sections\Clean_Room\Clean_Room::CAPABILITY ) );
$page_nonce = wp_create_nonce( Front_Desk::NONCE );
check( 'a WordPress nonce can be made for the page', 10 === strlen( $page_nonce ) && 1 === wp_verify_nonce( $page_nonce, Front_Desk::NONCE ) );
while ( ob_get_level() > 0 && in_array( 'FisHotel\Misc\Sections\Shop_App\App::add_bar', ob_list_handlers(), true ) ) {
	ob_end_clean();
}

// The two filters that hold the account down, each on its own (either would do; both are there).
fh_visit( array( 'uri' => '/?fh_front_desk=1&tank=521', 'cookies' => $jar ) );
$rich = array( 'manage_options' => true, 'manage_woocommerce' => true, 'edit_posts' => true, 'read' => true );
check( 'before the handler — map_meta_cap: every capability, the Front Desk\'s included, maps to do_not_allow', array( 'do_not_allow' ) === Gate::filter_map_meta_cap( array( 'manage_woocommerce' ), 'manage_woocommerce', $staff ) && array( 'do_not_allow' ) === Gate::filter_map_meta_cap( array( 'read' ), 'read', $staff ) && array( 'do_not_allow' ) === Gate::filter_map_meta_cap( array(), 'edit_user', $staff ) );
check( 'before the handler — user_has_cap: whatever the account\'s role gives it, it holds nothing', array() === Gate::filter_user_has_cap( $rich, array( 'manage_options' ), array( 'manage_options', $staff ), get_userdata( $staff ) ) );
Gate::grant();
check( 'once granted — map_meta_cap: the Front Desk\'s capability passes, everything else is still do_not_allow', array( 'manage_woocommerce' ) === Gate::filter_map_meta_cap( array( 'manage_woocommerce' ), 'manage_woocommerce', $staff ) && array( 'do_not_allow' ) === Gate::filter_map_meta_cap( array( 'manage_options' ), 'manage_options', $staff ) && array( 'do_not_allow' ) === Gate::filter_map_meta_cap( array( 'edit_posts' ), 'edit_post', $staff ) );
check( 'once granted — user_has_cap: exactly the Front Desk\'s capability, none of the role\'s', array( 'manage_woocommerce' => true ) === Gate::filter_user_has_cap( $rich, array( 'manage_options' ), array( 'manage_options', $staff ), get_userdata( $staff ) ) );
check( 'both filters leave every other account exactly as it is', array( 'manage_options' ) === Gate::filter_map_meta_cap( array( 'manage_options' ), 'manage_options', $owner ) && $rich === Gate::filter_user_has_cap( $rich, array( 'manage_options' ), array( 'manage_options', $owner ), get_userdata( $owner ) ) && array( 'x' ) === Gate::filter_map_meta_cap( array( 'x' ), 'x', 0 ) );
fh_request( array( 'uri' => '/', 'cookies' => $jar ) );
check( 'outside a shop-floor request neither filter does anything, not even for that account', array( 'manage_options' ) === Gate::filter_map_meta_cap( array( 'manage_options' ), 'manage_options', $staff ) && $rich === Gate::filter_user_has_cap( $rich, array(), array( 'x', $staff ), get_userdata( $staff ) ) );

$picker = fh_visit( array( 'uri' => '/?fh_front_desk=1', 'cookies' => $jar ) );
check( 'the Front Desk\'s tank picker (no tank): logged in too', $picker['logged_in'] && $staff === $picker['user'] );

echo "\n4. The Front Desk's AJAX — its own handlers, with the nonce from the page\n";

/**
 * Play admin-ajax.php: init, admin_init, then the action's hook — for
 * logged-in users or for visitors, as WordPress chooses — and "0" when
 * nothing answered.
 */
function fh_ajax( array $jar, array $post, array $extra = array() ) {
	fh_request( array_merge( array( 'method' => 'POST', 'uri' => '/wp-admin/admin-ajax.php', 'admin' => true, 'ajax' => true, 'post' => $post, 'cookies' => $jar ), $extra ) );
	$seen = array();
	$run  = fh_run(
		static function () use ( &$seen ) {
			do_action( 'init' );
			do_action( 'admin_init' );
			$seen['logged_in']  = is_user_logged_in();
			$seen['user']       = get_current_user_id();
			$seen['can_before'] = current_user_can( 'manage_woocommerce' );
			$action             = isset( $_REQUEST['action'] ) ? (string) $_REQUEST['action'] : '';
			$hook               = ( is_user_logged_in() ? 'wp_ajax_' : 'wp_ajax_nopriv_' ) . $action;
			if ( ! has_action( $hook ) ) {
				throw new FH_Halt( 'die', '0', 400 );
			}
			do_action( $hook );
			throw new FH_Halt( 'die', '0', 400 );
		}
	);
	do_action( 'shutdown' );
	$seen['halt']   = $run['halt'];
	$seen['kind']   = $run['halt'] ? $run['halt']->kind : '';
	$seen['status'] = $run['halt'] ? (int) $run['halt']->status : 0;
	$seen['data']   = $run['halt'] ? $run['halt']->data : null;
	$seen['output'] = $run['output'];
	return $seen;
}

// What the session's account can do while the Front Desk's handler runs.
$GLOBALS['fh_inside'] = array();
function fh_look_inside() {
	$GLOBALS['fh_inside'] = array(
		'user'               => get_current_user_id(),
		'manage_woocommerce' => current_user_can( 'manage_woocommerce' ),
		'manage_options'     => current_user_can( 'manage_options' ),
		'edit_posts'         => current_user_can( 'edit_posts' ),
		'edit_users'         => current_user_can( 'edit_users' ),
		'edit_shop_orders'   => current_user_can( 'edit_shop_orders' ),
		'read'               => current_user_can( 'read' ),
		'user_can'           => user_can( get_current_user_id(), 'manage_options' ),
	);
}
fh_boot_hooks(
	static function () {
		add_action( 'wp_ajax_fishotel_cleanroom_screen', 'fh_look_inside', 5 );
	}
);

$r = fh_ajax( $jar, array( 'action' => 'fishotel_cleanroom_screen', 'nonce' => $page_nonce, 'tank' => '521' ) );
check( 'screen: until the action starts NOBODY is logged in — init and admin_init see a visitor', false === $r['logged_in'] && 0 === $r['user'] );
check( '… and no capability at all', false === $r['can_before'] );
check( '… inside the handler: the Front Desk\'s capability and nothing else', array( 'user' => $staff, 'manage_woocommerce' => true, 'manage_options' => false, 'edit_posts' => false, 'edit_users' => false, 'edit_shop_orders' => false, 'read' => false, 'user_can' => false ) === $GLOBALS['fh_inside'], $GLOBALS['fh_inside'] );
check( '… the Front Desk\'s own handler answers with its screen (capability and nonce both passed)', 'json' === $r['kind'] && true === $r['data']['success'] && array_key_exists( 'screen', $r['data']['data'] ) && array_key_exists( 'tablesOk', $r['data']['data']['screen'] ), $r['kind'] . ' ' . $r['status'] );

$r = fh_ajax( $jar, array( 'action' => 'fishotel_cleanroom_suggest', 'nonce' => $page_nonce, 'term' => 'go' ) );
check( 'suggest: answered by the Front Desk', 'json' === $r['kind'] && true === $r['data']['success'] && array_key_exists( 'items', $r['data']['data'] ) );
$r = fh_ajax( $jar, array( 'action' => 'fishotel_cleanroom_act', 'nonce' => $page_nonce, 'act' => 'note', 'tank' => '521', 'note' => 'x' ) );
check( 'act (the one write): past the Front Desk\'s guard — it gets as far as its tables (which this test has none of)', 'json' === $r['kind'] && 500 === $r['status'] && false !== strpos( $r['data']['data']['message'], 'tables are missing' ), $r['kind'] . ' ' . $r['status'] );
$r = fh_ajax( $jar, array( 'action' => 'fishotel_cleanroom_parse', 'nonce' => $page_nonce, 'text' => 'two dead', 'tank' => '521' ) );
check( 'parse: past the guard too', 403 !== $r['status'] && 400 !== $r['status'] && ! ( 'die' === $r['kind'] && '-1' === $r['data'] ) );

$r = fh_ajax( $jar, array( 'action' => 'fishotel_cleanroom_screen', 'nonce' => 'not-a-nonce', 'tank' => '521' ) );
check( 'a wrong nonce: refused by the Front Desk as ever (403, -1)', 'die' === $r['kind'] && 403 === $r['status'] && '-1' === $r['data'] );
$r = fh_ajax( $jar, array( 'action' => 'fishotel_cleanroom_screen', 'tank' => '521' ) );
check( 'no nonce: refused (403)', 403 === $r['status'] );
$r = fh_ajax( $jar, array( 'action' => 'fishotel_cleanroom_screen', 'nonce' => $page_nonce ), array( 'uri' => '/wp-admin/admin-ajax.php?tank=521' ) );
check( 'the same call with a parameter in the address: not a shop-floor request — "0", not logged in', ! $r['logged_in'] && 400 === $r['status'] && '0' === $r['data'] );
$r = fh_ajax( $jar, array( 'action' => 'fishotel_cleanroom_stay', 'nonce' => $page_nonce ) );
check( 'the Clean Room\'s admin AJAX with the Front Desk\'s nonce: "0", not logged in', ! $r['logged_in'] && 400 === $r['status'] );
$r = fh_ajax( array(), array( 'action' => 'fishotel_cleanroom_screen', 'nonce' => $page_nonce, 'tank' => '521' ) );
check( 'the right nonce without the cookies: "0", not logged in', ! $r['logged_in'] && 400 === $r['status'] && '0' === $r['data'] );
$only_device = $jar;
unset( $only_device[ Http::cookie_name( Http::SESSION ) ] );
$r = fh_ajax( $only_device, array( 'action' => 'fishotel_cleanroom_screen', 'nonce' => $page_nonce, 'tank' => '521' ) );
check( 'the device cookie alone (nobody unlocked): "0", not logged in', ! $r['logged_in'] && 400 === $r['status'] );
$only_session = $jar;
unset( $only_session[ Http::cookie_name( Http::DEVICE ) ] );
$r = fh_ajax( $only_session, array( 'action' => 'fishotel_cleanroom_screen', 'nonce' => $page_nonce, 'tank' => '521' ) );
check( 'the session cookie alone (not this device): "0", not logged in', ! $r['logged_in'] && 400 === $r['status'] );

echo "\n5. Nonces belong to the account and the page\n";
fh_request( array( 'cookies' => array( 'wordpress_logged_in_test' => (string) $manager ) ) );
$manager_nonce = wp_create_nonce( Front_Desk::NONCE );
$r             = fh_ajax( $jar, array( 'action' => 'fishotel_cleanroom_screen', 'nonce' => $manager_nonce, 'tank' => '521' ) );
check( 'a nonce made for a WordPress login does not work for the shop session', 403 === $r['status'] && $manager_nonce !== $page_nonce );
$r = fh_ajax( array( 'wordpress_logged_in_test' => (string) $manager ), array( 'action' => 'fishotel_cleanroom_screen', 'nonce' => $page_nonce, 'tank' => '521' ) );
check( '… nor the shop session\'s nonce for a WordPress login', 403 === $r['status'] );

fh_request( array( 'cookies' => $jar ) );
Access::instance()->lock();
$locked = fh_jar( $jar );
$r      = fh_ajax( $locked, array( 'action' => 'fishotel_cleanroom_screen', 'nonce' => $page_nonce, 'tank' => '521' ) );
check( 'after Lock the open page\'s calls are not logged in', ! $r['logged_in'] && 400 === $r['status'] );
$r = fh_ajax( $jar, array( 'action' => 'fishotel_cleanroom_screen', 'nonce' => $page_nonce, 'tank' => '521' ) );
check( '… and a kept copy of the cookies is not either', ! $r['logged_in'] && 400 === $r['status'] );
fh_request( array( 'cookies' => $locked ) );
Access::instance()->unlock( '4821' );
$jar = fh_jar( $locked );
$r   = fh_ajax( $jar, array( 'action' => 'fishotel_cleanroom_screen', 'nonce' => $page_nonce, 'tank' => '521' ) );
check( 'the same person unlocks again: the page that was open can go on (what was typed can still be saved)', 'json' === $r['kind'] && true === $r['data']['success'] );

fh_request( array( 'cookies' => $jar ) );
Access::instance()->lock();
$jar = fh_jar( $jar );
fh_request( array( 'cookies' => $jar ) );
Access::instance()->unlock( '7007' );
$jar = fh_jar( $jar );
$r   = fh_ajax( $jar, array( 'action' => 'fishotel_cleanroom_screen', 'nonce' => $page_nonce, 'tank' => '521' ) );
check( 'somebody with another account unlocks: Sam\'s open page is no longer good (403) — nothing of Sam\'s is saved as Olive', $owner === $GLOBALS['fh_inside']['user'] && 403 === $r['status'] );

echo "\n5b. An AJAX call is nobody's until its action starts\n";
// A stand-in for every handler that sits on init / wp_loaded / admin_init
// and acts for the current user when it finds its field in the request —
// a basket's add-to-cart, a profile form, any plugin's "save my settings".
$GLOBALS['fh_early'] = array();
function fh_early_handler() {
	if ( isset( $_REQUEST['add-to-cart'] ) ) {
		$GLOBALS['fh_early'][] = array( fh_current_hook(), get_current_user_id(), is_user_logged_in(), current_user_can( 'manage_woocommerce' ), current_user_can( 'read' ) );
	}
}
function fh_current_hook() {
	return (string) end( $GLOBALS['fh_doing'] );
}
fh_boot_hooks(
	static function () {
		add_action( 'init', 'fh_early_handler', 10 );
		add_action( 'init', 'fh_early_handler', PHP_INT_MAX );
		add_action( 'admin_init', 'fh_early_handler', 10 );
	}
);
foreach ( array( 'the shared account (Sam)' => array( '4821', $staff ), 'an administrator\'s own account (Olive)' => array( '7007', $owner ) ) as $whose => $case ) {
	fh_request( array( 'cookies' => $jar ) );
	Access::instance()->lock();
	$jar = fh_jar( $jar );
	fh_request( array( 'cookies' => $jar ) );
	Access::instance()->unlock( $case[0] );
	$jar = fh_jar( $jar );
	fh_visit( array( 'uri' => '/?fh_front_desk=1&tank=521', 'cookies' => $jar ) );
	$nonce               = wp_create_nonce( Front_Desk::NONCE );
	$GLOBALS['fh_early'] = array();
	$GLOBALS['fh_inside'] = array();
	$r                   = fh_ajax( $jar, array( 'action' => 'fishotel_cleanroom_screen', 'nonce' => $nonce, 'tank' => '521', 'add-to-cart' => '11', 'quantity' => '7' ) );
	check( 'a field added to a Front Desk call (add-to-cart) is seen by the early handlers as a VISITOR\'s — ' . $whose, 3 === count( $GLOBALS['fh_early'] ) && array( array( 0, false, false, false ) ) === array_values( array_unique( array_map( static function ( $seen ) { return array_slice( $seen, 1 ); }, $GLOBALS['fh_early'] ), SORT_REGULAR ) ), json_encode( $GLOBALS['fh_early'] ) );
	check( '… while the Front Desk\'s own handler still runs as the account and answers', $case[1] === $GLOBALS['fh_inside']['user'] && true === $GLOBALS['fh_inside']['manage_woocommerce'] && 'json' === $r['kind'] && true === $r['data']['success'], $r['kind'] . ' ' . $r['status'] );
}
$GLOBALS['fh_early'] = array();
$r                   = fh_ajax( array_merge( $jar, array( 'wordpress_logged_in_test' => (string) $manager ) ), array( 'action' => 'fishotel_cleanroom_screen', 'nonce' => $nonce, 'tank' => '521', 'add-to-cart' => '11' ) );
check( 'with a WordPress login beside the session the early handlers see nobody either — not the login, not the session', array( array( 0, false, false, false ) ) === array_values( array_unique( array_map( static function ( $seen ) { return array_slice( $seen, 1 ); }, $GLOBALS['fh_early'] ), SORT_REGULAR ) ) && $owner === $GLOBALS['fh_inside']['user'] );
$GLOBALS['fh_early'] = array();
fh_ajax( array( 'wordpress_logged_in_test' => (string) $manager ), array( 'action' => 'fishotel_cleanroom_screen', 'nonce' => 'x', 'add-to-cart' => '11' ) );
check( 'a WordPress login WITHOUT a shop session is what it always was: the early handlers see the manager', array( array( $manager, true, true, true ) ) === array_values( array_unique( array_map( static function ( $seen ) { return array_slice( $seen, 1 ); }, $GLOBALS['fh_early'] ), SORT_REGULAR ) ) );
fh_request( array( 'method' => 'POST', 'uri' => '/wp-admin/admin-ajax.php', 'admin' => true, 'ajax' => true, 'post' => array( 'action' => 'fishotel_cleanroom_screen', 'add-to-cart' => '11' ), 'cookies' => $jar ) );
check( 'asked before init (a plugin on plugins_loaded, a theme\'s setup): nobody there either — the account is never the answer before the action', 0 === get_current_user_id() && ! is_user_logged_in() && ! current_user_can( 'manage_woocommerce' ) );
fh_request( array( 'method' => 'POST', 'uri' => '/wp-admin/admin-ajax.php', 'admin' => true, 'ajax' => true, 'post' => array( 'action' => 'fishotel_cleanroom_screen' ), 'cookies' => $jar ) );
do_action( 'init' );
check( 'WordPress is sent down the visitor\'s path, where the gate waits at priority 0 to hand over to the real action', ! is_user_logged_in() && 0 === has_action( 'wp_ajax_nopriv_fishotel_cleanroom_screen', array( Gate::class, 'enter_ajax' ) ) && 0 === has_action( 'wp_ajax_fishotel_cleanroom_screen', array( Gate::class, 'enter' ) ) );
check( '… the account\'s saved basket is neither read nor written on a shop-floor request', false === apply_filters( 'woocommerce_persistent_cart_enabled', true ) );
fh_request( array( 'method' => 'POST', 'uri' => '/wp-admin/admin-ajax.php', 'admin' => true, 'ajax' => true, 'post' => array( 'action' => 'heartbeat' ), 'cookies' => $jar ) );
do_action( 'init' );
check( '… and on any other action nothing of this is hooked', false === has_action( 'wp_ajax_nopriv_heartbeat', array( Gate::class, 'enter_ajax' ) ) && true === apply_filters( 'woocommerce_persistent_cart_enabled', true ) );
fh_boot_hooks(
	static function () {
		remove_action( 'init', 'fh_early_handler', 10 );
		remove_action( 'init', 'fh_early_handler', PHP_INT_MAX );
		remove_action( 'admin_init', 'fh_early_handler', 10 );
	}
);

echo "\n6. An administrator's own account in a shop session is no administrator\n";
$seen = fh_visit( array( 'uri' => '/?fh_front_desk=1&tank=521', 'cookies' => $jar ) );
check( 'Olive (an administrator, by PIN): logged in as the administrator\'s own account on the Front Desk', $seen['logged_in'] && $owner === $seen['user'] );
check( '… with none of that account\'s capabilities before the handler', ! current_user_can( 'manage_options' ) && ! current_user_can( 'manage_woocommerce' ) && ! current_user_can( 'edit_users' ) && ! user_can( $owner, 'manage_options' ) );
$olive_nonce = wp_create_nonce( Front_Desk::NONCE );
$r           = fh_ajax( $jar, array( 'action' => 'fishotel_cleanroom_screen', 'nonce' => $olive_nonce, 'tank' => '521' ) );
check( '… and inside it only the Front Desk\'s', array( 'user' => $owner, 'manage_woocommerce' => true, 'manage_options' => false, 'edit_posts' => false, 'edit_users' => false, 'edit_shop_orders' => false, 'read' => false, 'user_can' => false ) === $GLOBALS['fh_inside'], $GLOBALS['fh_inside'] );
check( '… while the same request leaves every other account as it is', user_can( $manager, 'manage_woocommerce' ) && user_can( $manager, 'edit_posts' ) && ! user_can( $customer, 'manage_woocommerce' ) );

$GLOBALS['fh_multisite']   = true;
$GLOBALS['fh_super_admin'] = array( $owner );
$r                         = fh_ajax( $jar, array( 'action' => 'fishotel_cleanroom_screen', 'nonce' => $olive_nonce, 'tank' => '521' ) );
check( 'a multisite super admin (who "has every capability") is clamped the same way', array( 'user' => $owner, 'manage_woocommerce' => true, 'manage_options' => false, 'edit_posts' => false, 'edit_users' => false, 'edit_shop_orders' => false, 'read' => false, 'user_can' => false ) === $GLOBALS['fh_inside'], $GLOBALS['fh_inside'] );
fh_request( array( 'cookies' => array( 'wordpress_logged_in_test' => (string) $owner ), 'uri' => '/wp-admin/index.php', 'admin' => true ) );
check( '… and is a super admin again on a request made with a WordPress login', current_user_can( 'manage_options' ) && current_user_can( 'anything_at_all' ) );
$GLOBALS['fh_multisite']   = false;
$GLOBALS['fh_super_admin'] = array();

$seen = fh_visit( array( 'uri' => '/wp-admin/index.php', 'admin' => true, 'cookies' => array_merge( $jar, array( 'wordpress_logged_in_test' => (string) $owner ) ) ) );
check( 'the same person\'s WordPress login on wp-admin, with the shop session\'s cookies beside it: an administrator, untouched', $seen['logged_in'] && $owner === $seen['user'] && current_user_can( 'manage_options' ) && ! $seen['session'] );

echo "\n7. A WordPress login and a shop session on one device\n";
fh_request( array( 'cookies' => $jar ) );
Access::instance()->lock();
$jar = fh_jar( $jar );
fh_request( array( 'cookies' => $jar ) );
Access::instance()->unlock( '4821' );
$jar  = fh_jar( $jar );
$both = array_merge( $jar, array( 'wordpress_logged_in_test' => (string) $manager ) );

$seen = fh_visit( array( 'uri' => '/?fh_front_desk=1&tank=521', 'cookies' => $both ) );
check( 'on the Front Desk a live shop session comes first: the person who unlocked is who is working', $seen['logged_in'] && $staff === $seen['user'] && $seen['session'] );
$seen = fh_visit( array( 'uri' => '/wp-admin/index.php', 'admin' => true, 'cookies' => $both ) );
check( 'on wp-admin the WordPress login is the manager, as ever', $manager === $seen['user'] && $seen['can'] && ! $seen['session'] );
$seen = fh_visit( array( 'uri' => '/my-account/', 'cookies' => $both ) );
check( 'on My Account too', $manager === $seen['user'] && ! $seen['session'] );
$seen = fh_visit( array( 'uri' => '/?fh_front_desk=1&tank=521', 'cookies' => array_merge( $locked, array( 'wordpress_logged_in_test' => (string) $manager ) ) ) );
check( 'with nobody unlocked the WordPress login opens the Front Desk exactly as before: the manager, with the manager\'s own capabilities, no clamp', $manager === $seen['user'] && $seen['can'] && current_user_can( 'edit_posts' ) && ! $seen['session'] );
$seen = fh_visit( array( 'uri' => '/?fh_front_desk=1&tank=521', 'cookies' => array( 'wordpress_logged_in_test' => (string) $manager ) ) );
check( 'and so it does on a device that was never enrolled — at no cost to this section', $manager === $seen['user'] && $seen['can'] && 0 === $seen['queries'] && array() === $seen['reads'] );

$with_customer = array_merge( $jar, array( 'wordpress_logged_in_test' => (string) $customer ) );
$seen          = fh_visit( array( 'uri' => '/?fh_front_desk=1&tank=521', 'cookies' => $with_customer ) );
check( 'a customer login beside a live shop session: the Front Desk is the session\'s', $staff === $seen['user'] );
$seen = fh_visit( array( 'uri' => '/my-account/', 'cookies' => $with_customer ) );
check( '… and My Account is the customer\'s own', $customer === $seen['user'] && ! $seen['can'] );

echo "\n8. Asked too early, asked while answering\n";
fh_request( array( 'uri' => '/?fh_front_desk=1&tank=521', 'cookies' => $jar ) );
$GLOBALS['current_user'] = new WP_User( 0 ); // Something asked "who is it?" before this section's filter existed.
check( 'a current user worked out before the section loaded says "nobody"', ! is_user_logged_in() );
do_action( 'init' );
check( '… and at init the session\'s account is put in place all the same', is_user_logged_in() && $staff === get_current_user_id() );

fh_request( array( 'method' => 'POST', 'uri' => '/wp-admin/admin-ajax.php', 'admin' => true, 'ajax' => true, 'post' => array( 'action' => 'fishotel_cleanroom_screen' ), 'cookies' => $both ) );
$GLOBALS['current_user'] = new WP_User( $manager ); // Worked out early as the WordPress login.
do_action( 'init' );
check( 'on an AJAX call an early answer that was a WordPress login is taken back at init: nobody, until the action', 0 === get_current_user_id() && ! is_user_logged_in() );
Gate::enter();
check( '… and when the action starts it is the session\'s account', $staff === get_current_user_id() && Gate::granted() );

$GLOBALS['fh_asked'] = 0;
function fh_nosy_filter( $rules ) {
	++$GLOBALS['fh_asked'];
	current_user_can( 'manage_options' ); // Asks who the user is while the gate is working that out.
	return $rules;
}
fh_boot_hooks(
	static function () {
		add_filter( 'fishotel_shop_app_allowed_requests', 'fh_nosy_filter' );
	}
);
$seen = fh_visit( array( 'uri' => '/?fh_front_desk=1&tank=521', 'cookies' => $jar ) );
check( 'a rules filter that asks who the user is does not send the gate in circles', $GLOBALS['fh_asked'] >= 1 && $seen['logged_in'] && $staff === $seen['user'] );
fh_boot_hooks(
	static function () {
		remove_filter( 'fishotel_shop_app_allowed_requests', 'fh_nosy_filter' );
	}
);

echo "\n9. WooCommerce's basket cookies\n";
$shopping = array_merge( $jar, array( $wc_cookie => 't_guest||1||2||hash', 'woocommerce_items_in_cart' => '1', 'woocommerce_cart_hash' => 'abc' ) );
fh_visit( array( 'uri' => '/?fh_front_desk=1&tank=521', 'cookies' => $shopping ) );
check( 'on a shop-floor request the device\'s basket cookies are out of WooCommerce\'s sight', ! isset( $_COOKIE[ $wc_cookie ] ) && ! isset( $_COOKIE['woocommerce_items_in_cart'] ) && ! isset( $_COOKIE['woocommerce_cart_hash'] ) );
check( '… and WooCommerce is told to set no cookie', false === apply_filters( 'woocommerce_set_cookie_enabled', true, 'wp_woocommerce_session_x', 'v', 0, false ) );
check( '… this section\'s own cookies are still there', isset( $_COOKIE[ Http::cookie_name( Http::DEVICE ) ], $_COOKIE[ Http::cookie_name( Http::SESSION ) ] ) );
fh_visit( array( 'uri' => '/cart/', 'cookies' => $shopping ) );
check( 'on the shop front nothing is hidden and WooCommerce sets what it likes', isset( $_COOKIE[ $wc_cookie ], $_COOKIE['woocommerce_items_in_cart'] ) && true === apply_filters( 'woocommerce_set_cookie_enabled', true, 'x', 'v', 0, false ) );

echo "\n10. An ordinary visitor costs nothing\n";
foreach ( array( '/', '/shop/', '/product/invented-goby/', '/checkout/', '/?s=tang' ) as $uri ) {
	fh_request( array( 'uri' => $uri ) );
	do_action( 'init' );
	do_action( 'wp_loaded' );
	is_user_logged_in();
	do_action( 'template_redirect' );
	if ( 0 !== fh_store()->queries || array() !== $GLOBALS['fh_option_reads'] || array() !== $GLOBALS['wpdb']->queries || array() !== $GLOBALS['fh_sent']['cookies'] ) {
		check( 'no query, no option read, no cookie: ' . $uri, false, json_encode( array( fh_store()->queries, $GLOBALS['fh_option_reads'], $GLOBALS['wpdb']->queries ) ) );
	}
}
check( 'a visitor without this section\'s cookies: no query, no option read that is not autoloaded, no cookie — on five kinds of page', true );
fh_request( array( 'uri' => '/shop/', 'cookies' => $jar ) );
do_action( 'init' );
do_action( 'wp_loaded' );
is_user_logged_in();
do_action( 'template_redirect' );
check( 'an enrolled, unlocked tablet browsing the shop front: no query either, and its session is not renewed by it', 0 === fh_store()->queries && array() === $GLOBALS['fh_option_reads'] && array() === $GLOBALS['fh_sent']['cookies'] );

echo "\n11. Screens other sections add\n";
function fh_packing_rules( $rules ) {
	$rules[] = array( 'type' => 'page', 'query_var' => 'fh_packing', 'params' => array( 'box' => '/^\d{1,4}$/D' ) );
	$rules[] = array( 'type' => 'ajax', 'action' => 'fh_packing_save' );
	$rules[] = array( 'type' => 'page', 'query_var' => 'Not Valid' );
	return $rules;
}
function fh_packing_caps( $caps ) {
	$caps[] = 'fh_pack_boxes';
	$caps[] = 'do_not_allow';
	return $caps;
}
$GLOBALS['fh_packing_saw'] = null;
function fh_packing_handler() {
	$GLOBALS['fh_packing_saw'] = array( get_current_user_id(), current_user_can( 'fh_pack_boxes' ), current_user_can( 'manage_woocommerce' ), current_user_can( 'manage_options' ) );
}
fh_boot_hooks(
	static function () {
		add_filter( 'fishotel_shop_app_allowed_requests', 'fh_packing_rules' );
		add_filter( 'fishotel_shop_app_capabilities', 'fh_packing_caps' );
		add_action( 'wp_ajax_fh_packing_save', 'fh_packing_handler' );
	}
);

check( 'the filter adds a page and an AJAX action (a malformed rule is dropped)', 7 === count( Gate::rules() ) );
$seen = fh_visit( array( 'uri' => '/?fh_packing=1&box=12', 'cookies' => $jar ) );
check( 'the added page is a shop-floor request for a shop session', $seen['logged_in'] && $staff === $seen['user'] && ! current_user_can( 'fh_pack_boxes' ) );
App::intercept();
check( '… granted at template_redirect: the Front Desk\'s capability and the one the filter added — and never do_not_allow', current_user_can( 'fh_pack_boxes' ) && current_user_can( 'manage_woocommerce' ) && ! current_user_can( 'manage_options' ) && ! current_user_can( 'do_not_allow' ) );
while ( ob_get_level() > 0 && in_array( 'FisHotel\Misc\Sections\Shop_App\App::add_bar', ob_list_handlers(), true ) ) {
	ob_end_clean();
}
$seen = fh_visit( array( 'uri' => '/?fh_packing=1&box=12&order=5', 'cookies' => $jar ) );
check( '… but not with a parameter the rule does not list', ! $seen['logged_in'] );
$r = fh_ajax( $jar, array( 'action' => 'fh_packing_save' ) );
check( 'the added AJAX action runs as the session\'s account with the shop-floor capabilities', array( $staff, true, true, false ) === $GLOBALS['fh_packing_saw'], $GLOBALS['fh_packing_saw'] );
$seen = fh_visit( array( 'uri' => '/?fh_packing=1', 'cookies' => $locked ) );
check( 'without a live session the added page is not logged in', ! $seen['logged_in'] );
fh_boot_hooks(
	static function () {
		remove_filter( 'fishotel_shop_app_allowed_requests', 'fh_packing_rules' );
		remove_filter( 'fishotel_shop_app_capabilities', 'fh_packing_caps' );
	}
);

echo "\n12. Nothing on the admin side accepts a shop session\n";
fh_visit( array( 'uri' => '/?fh_front_desk=1&tank=521', 'cookies' => $jar ) );
App::intercept();
check( 'inside a shop-floor request the account holds manage_woocommerce …', current_user_can( Shop_App::CAPABILITY ) );
check( '… and still may not write anything on the Shop App admin side', false === Admin::may_write() );
while ( ob_get_level() > 0 && in_array( 'FisHotel\Misc\Sections\Shop_App\App::add_bar', ob_list_handlers(), true ) ) {
	ob_end_clean();
}
$people_before  = count( $store->people );
$devices_before = count( $store->devices );
foreach ( array( 'handle_enrol_here', 'handle_revoke', 'handle_clear_lockout', 'handle_person', 'handle_person_off', 'handle_settings' ) as $handler ) {
	fh_visit( array( 'uri' => '/?fh_front_desk=1&tank=521', 'cookies' => $jar ) );
	Gate::grant();
	$_POST    = array( 'name' => 'Evil', 'pin' => '1111', 'person' => (string) $sam, 'device' => (string) $tablet['id'], 'session_minutes' => '720', '_wpnonce' => wp_create_nonce( Admin::NONCE ) );
	$_REQUEST = $_POST;
	$admin    = new Admin();
	$run      = fh_run( array( $admin, $handler ) );
	if ( ! $run['halt'] || 'die' !== $run['halt']->kind || 403 !== (int) $run['halt']->status ) {
		check( $handler . ' refuses a shop session', false, $run['halt'] ? $run['halt']->kind . ' ' . $run['halt']->status : 'no halt' );
	}
}
check( 'every admin-post handler refuses a shop session with 403 — even holding a valid nonce and the capability', true );
fh_visit( array( 'uri' => '/?fh_front_desk=1&tank=521', 'cookies' => $jar ) );
Gate::grant();
$_POST    = array( 'name' => 'Evil tablet', 'nonce' => wp_create_nonce( Admin::NONCE ) );
$_REQUEST = $_POST;
$admin    = new Admin();
$run      = fh_run( array( $admin, 'ajax_link' ) );
check( 'the enrolment-link AJAX refuses it too (403)', $run['halt'] && 'json' === $run['halt']->kind && 403 === (int) $run['halt']->status && false === $run['halt']->data['success'] );
check( 'nothing was added, revoked or changed', $people_before === count( $store->people ) && $devices_before === count( $store->devices ) && 1 === $store->people[ $sam ]['active'] && 'active' === $store->devices[ $tablet['id'] ]['status'] && ! isset( $GLOBALS['fh_options'][ \FisHotel\Misc\Sections\Shop_App\Settings::OPTION ] ) );
$run = fh_run(
	static function () {
		$admin = new Admin();
		$admin->render_page();
	}
);
check( 'the admin page itself does not render for a shop session', $run['halt'] && 'die' === $run['halt']->kind && false === strpos( $run['output'], 'Set up this device' ) );

echo "\n13. The shared account never logs in\n";
$account = get_userdata( $staff );
$refused = People::refuse_password_login( $account );
check( 'wp_authenticate_user refuses it (whatever the password)', $refused instanceof WP_Error && false !== strpos( $refused->get_error_message(), 'cannot log in' ) );
check( '… and lets every other account through', get_userdata( $manager ) == People::refuse_password_login( get_userdata( $manager ) ) );
check( 'no password reset for it', false === People::refuse_password_reset( true, $staff ) && true === People::refuse_password_reset( true, $manager ) );
check( 'no application password for it', false === People::refuse_application_passwords( true, $account ) && true === People::refuse_application_passwords( true, get_userdata( $manager ) ) );
check( 'the three filters are in place whenever the section is on', false !== has_filter( 'wp_authenticate_user', array( People::class, 'refuse_password_login' ) ) && false !== has_filter( 'allow_password_reset', array( People::class, 'refuse_password_reset' ) ) && false !== has_filter( 'wp_is_application_passwords_available_for_user', array( People::class, 'refuse_application_passwords' ) ) );
$squatter = fh_add_user( 'someone-else', 'customer' );
check( 'only an account with the shop-staff role is treated so — a customer account is not', ! People::is_staff_account( get_userdata( $squatter ) ) && People::is_staff_account( $account ) && ! People::is_staff_account( false ) );

fh_finish();
