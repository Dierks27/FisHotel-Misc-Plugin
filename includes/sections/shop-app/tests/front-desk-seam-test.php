<?php
/**
 * THE SEAM WITH THE CLEAN ROOM — what a PIN may do on the Front Desk now
 * that it carries the dose calculator, the temporary tanks and the day's
 * list, and what stays wp-admin only.
 *
 * Both sections are real: the Shop App as it boots, the Clean Room's own
 * classes (Front_Desk, Temp_Tanks, Medications_Admin, Admin_Temp …) as its
 * Section Manager loads them. The lists below are read from the Clean
 * Room's source, so an AJAX action or an act added to it later makes this
 * test say where it belongs before it ships:
 *   - the page: a tank, the day's list (list=1) and the calculator
 *     (calc=1) open under a PIN and are rendered by the Front Desk's own
 *     handler; anything else in the address is not a shop-floor request;
 *   - the AJAX: every action the phone page posts (front-desk.js) is on
 *     the Gate's list; every other wp_ajax_ action of the Clean Room — the
 *     Board's temporary-tank writes, the medications list, the layout, the
 *     settings — is not, and a shop session is a visitor there;
 *   - every act of the one write (readings, doses, ticks, arrivals,
 *     losses, moves, setting up and taking down a temporary tank, the
 *     day's list's ticks) gets past the Front Desk's guard under a PIN;
 *   - inside that handler the session holds the Front Desk's capability
 *     and not the Settings one: the medications editor refuses it even
 *     when called from there;
 *   - nothing on the act path asks for a capability of its own.
 *
 * Run from the plugin root:
 *   php includes/sections/shop-app/tests/front-desk-seam-test.php
 *
 * @package FisHotel\Misc\Sections\Shop_App
 */

require_once __DIR__ . '/wp-stubs.php';

use FisHotel\Misc\Sections\Clean_Room\Admin_Temp;
use FisHotel\Misc\Sections\Clean_Room\Clean_Room;
use FisHotel\Misc\Sections\Clean_Room\Front_Desk;
use FisHotel\Misc\Sections\Clean_Room\Medications_Admin;
use FisHotel\Misc\Sections\Clean_Room\Temp_Tanks;
use FisHotel\Misc\Sections\Shop_App\Access;
use FisHotel\Misc\Sections\Shop_App\App;
use FisHotel\Misc\Sections\Shop_App\Gate;
use FisHotel\Misc\Sections\Shop_App\Http;
use FisHotel\Misc\Sections\Shop_App\People;
use FisHotel\Misc\Sections\Shop_App\Shop_App;

$store = fh_fresh_store();
fh_boot_section( true );

$room_dir = FISHOTEL_MISC_PATH . 'includes/sections/clean-room/';

// On an admin-ajax.php request is_admin() is true, so the Clean Room also
// loads its wp-admin classes and registers their AJAX handlers (as
// Clean_Room::boot() does): they are here for the shop session to be
// refused by, not left out.
fh_boot_hooks(
	static function () use ( $room_dir ) {
		foreach ( array( 'class-admin.php', 'class-admin-workbook.php', 'class-admin-stats.php', 'class-admin-import.php', 'class-admin-temp.php' ) as $file ) {
			require_once $room_dir . $file;
		}
		( new \FisHotel\Misc\Sections\Clean_Room\Settings() )->init();
		( new \FisHotel\Misc\Sections\Clean_Room\Admin() )->init();
		( new Admin_Temp() )->init();
		( new \FisHotel\Misc\Sections\Clean_Room\Admin_Workbook() )->init();
	}
);

/* ------------------------------------------------------------------ */
/*  What the Clean Room has, read from its source                      */
/* ------------------------------------------------------------------ */

// Every wp_ajax_ action it registers: written out, or through a class's ACTION.
$room_actions = array();
foreach ( glob( $room_dir . '*.php' ) as $file ) {
	$src = (string) file_get_contents( $file );
	preg_match_all( "/'wp_ajax_([a-z0-9_]+)'/", $src, $m );
	$room_actions = array_merge( $room_actions, $m[1] );
	if ( false !== strpos( $src, "'wp_ajax_' . self::ACTION" ) && preg_match( "/const ACTION = '([a-z0-9_]+)';/", $src, $c ) ) {
		$room_actions[] = $c[1];
	}
}
$room_actions = array_values( array_unique( $room_actions ) );
sort( $room_actions );

// Every action the phone page posts.
$page_js = (string) file_get_contents( $room_dir . 'js/front-desk.js' );
preg_match_all( "/post\\('([a-z0-9_]+)'/", $page_js, $m );
$page_actions = array_values( array_unique( $m[1] ) );
sort( $page_actions );

// Every act of the one write: the cases of Front_Desk::perform() and the temporary tanks' own.
$desk_src = (string) file_get_contents( $room_dir . 'class-front-desk.php' );
$from     = strpos( $desk_src, 'public static function perform(' );
$to       = strpos( $desk_src, 'private static function targets(' );
preg_match_all( "/case '([a-z_]+)':/", substr( $desk_src, (int) $from, (int) $to - (int) $from ), $m );
$acts = array_values( array_unique( array_merge( $m[1], Temp_Tanks::ACTS ) ) );

// What stays wp-admin: the Board's, the Settings' and the order pages' AJAX.
$admin_only = array(
	'fishotel_cleanroom_alias_delete' => 'Settings: forget a spoken name',
	'fishotel_cleanroom_box_rates'    => 'Settings: box rates',
	'fishotel_cleanroom_box_share'    => 'Settings: a remembered box share',
	'fishotel_cleanroom_layout'       => 'Settings: the layout',
	'fishotel_cleanroom_medications'  => 'Settings: the medications list',
	'fishotel_cleanroom_order'        => 'Arrivals: a supplier order',
	'fishotel_cleanroom_protocol'     => 'Settings: the treatment schedules',
	'fishotel_cleanroom_release'      => 'Board: release a group',
	'fishotel_cleanroom_save'         => 'Settings: the settings',
	'fishotel_cleanroom_sizes'        => 'Settings: the sizes',
	'fishotel_cleanroom_species'      => 'Species: the species list',
	'fishotel_cleanroom_stay'         => 'Stays: edit a group',
	'fishotel_cleanroom_temp'         => 'Board: temporary tanks and the To do tab (the phone posts the same acts through fishotel_cleanroom_act)',
);

echo "1. What the Clean Room has (read from its source)\n";
$desk = Shop_App::front_desk();
check( 'the Front Desk is available to the app (both sections real and on)', true === $desk['available'] );
check( 'the phone page posts exactly the four actions on the Gate\'s list', $desk['actions'] === array_values( array_intersect( $desk['actions'], $page_actions ) ) && count( $page_actions ) === count( $desk['actions'] ), implode( ', ', $page_actions ) );
$unsorted = array_values( array_diff( $room_actions, $desk['actions'], array_keys( $admin_only ) ) );
check( 'every other AJAX action of the Clean Room is named here as wp-admin only — none is left unsorted', array() === $unsorted, implode( ', ', $unsorted ) );
$gone = array_values( array_diff( array_keys( $admin_only ), $room_actions ) );
check( '… and every name here is still one of its actions', array() === $gone, implode( ', ', $gone ) );
check( 'the acts of the one write include the readings, doses, ticks, arrivals, losses, moves and the temporary tanks', array() === array_diff( array( 'arrival', 'identify', 'loss', 'sold', 'move', 'clear', 'checkin', 'ready', 'count', 'note', 'copper', 'dose', 'tick', 'undo', 'alias', 'setup', 'setup_edit', 'setup_steps', 'takedown', 'todo_tick' ), $acts ), implode( ', ', $acts ) );

echo "\n2. Which addresses are shop-floor requests (pure)\n";
$rules = Gate::rules();
$is    = static function ( $method, $uri, $post_action = '' ) use ( $rules ) {
	parse_str( (string) parse_url( $uri, PHP_URL_QUERY ), $get );
	$path = (string) parse_url( $uri, PHP_URL_PATH );
	$rule = Gate::classify(
		array(
			'method'      => $method,
			'path'        => $path,
			'home_path'   => '/',
			'ajax_path'   => '/wp-admin/admin-ajax.php',
			'get'         => $get,
			'post_action' => $post_action,
			'is_admin'    => 0 === strpos( $path, '/wp-admin/' ),
			'doing_ajax'  => '/wp-admin/admin-ajax.php' === $path,
			'special'     => false,
		),
		$rules
	);
	return null !== $rule;
};
foreach ( array(
	'a tank (the label)'               => '/?fh_front_desk=1&tank=521',
	'the tank picker'                  => '/?fh_front_desk=1',
	'the day\'s list (list=1)'         => '/?fh_front_desk=1&list=1',
	'the dose calculator (calc=1)'     => '/?fh_front_desk=1&calc=1',
	'a temporary tank\'s slot'         => '/?fh_front_desk=1&tank=901',
	'a tank and the day\'s list'       => '/?fh_front_desk=1&tank=521&list=1',
	'the calculator with a tank'       => '/?fh_front_desk=1&tank=521&calc=1',
) as $what => $uri ) {
	check( 'yes: ' . $what, $is( 'GET', $uri ) );
}
foreach ( array(
	'list=2'                       => '/?fh_front_desk=1&list=2',
	'list=yes'                     => '/?fh_front_desk=1&list=yes',
	'list as an array'             => '/?fh_front_desk=1&list[]=1',
	'calc=1 with a line break'     => '/?fh_front_desk=1&calc=1%0A',
	'list=1 and something else'    => '/?fh_front_desk=1&list=1&preview=true',
	'calc=1 and the REST API'      => '/?fh_front_desk=1&calc=1&rest_route=/wp/v2/users/me',
	'list=1 on My Account'         => '/my-account/?fh_front_desk=1&list=1',
	'the To do tab in wp-admin'    => '/wp-admin/admin.php?page=fishotel-misc-clean-room&tab=todo',
	'Settings → Medications'       => '/wp-admin/admin.php?page=fishotel-misc-clean-room&tab=settings',
) as $what => $uri ) {
	check( 'no: ' . $what, ! $is( 'GET', $uri ) );
}
foreach ( $desk['actions'] as $action ) {
	check( 'yes: admin-ajax ' . $action, $is( 'POST', '/wp-admin/admin-ajax.php', $action ) );
}
foreach ( $admin_only as $action => $what ) {
	check( 'no: admin-ajax ' . $action . ' — ' . $what, ! $is( 'POST', '/wp-admin/admin-ajax.php', $action ) );
}

echo "\n3. A tablet with a live shop session\n";
$owner = fh_add_user( 'owner', 'administrator', 'Olive Owner' );
fh_request();
$sam    = fh_add_person( 'Sam', '4821' );
$tablet = Access::instance()->enrol_here( 'Shop tablet', $owner );
$jar    = fh_jar( array() );
fh_request( array( 'cookies' => $jar ) );
Access::instance()->unlock( '4821' );
$jar   = fh_jar( $jar );
$staff = People::staff_user_id( false );
check( 'Sam unlocked the tablet', $staff > 0 && isset( $jar[ Http::cookie_name( Http::SESSION ) ] ) );

/**
 * Open a page of the Front Desk on an enrolled tablet Sam unlocked, in a
 * process of its own (the Front Desk ends its page with a real exit;
 * see page-child.php).
 *
 * @return array{page: string, result: array, config: array|null}
 */
function fh_seam_page( $uri ) {
	$scenario = array(
		'shop_app' => true,
		'enrol'    => true,
		'unlock'   => '4821',
		'uri'      => $uri,
	);
	$out = (string) shell_exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __DIR__ . '/page-child.php' ) . ' ' . escapeshellarg( base64_encode( json_encode( $scenario ) ) ) . ' 2>&1' );
	$cut = strrpos( $out, "\n@@FH-RESULT@@" );
	$page = false === $cut ? $out : substr( $out, 0, $cut );
	$config = preg_match( '/window\.fhFrontDesk = (\{.*?\});<\/script>/s', $page, $m ) ? json_decode( $m[1], true ) : null;
	return array(
		'page'   => $page,
		'result' => false === $cut ? array() : (array) json_decode( substr( $out, $cut + strlen( "\n@@FH-RESULT@@" ) ), true ),
		'config' => $config,
	);
}

foreach ( array(
	'a tank'                         => array( '/?fh_front_desk=1&tank=521', '' ),
	'the day\'s list (list=1)'       => array( '/?fh_front_desk=1&list=1', '' ),
	'the dose calculator (calc=1)'   => array( '/?fh_front_desk=1&calc=1', 'calc' ),
	'the calculator from a tank'     => array( '/?fh_front_desk=1&tank=521&calc=1', 'calc' ),
) as $what => $case ) {
	$p = fh_seam_page( $case[0] );
	check(
		$what . ': rendered by the Front Desk under the PIN, as the session\'s account, with the app\'s bar' . ( $case[1] ? ', on the calculator' : '' ),
		'none' !== ( $p['result']['halt'] ?? '' ) && '' === ( $p['result']['location'] ?? 'x' ) && $p['result']['staff'] === $p['result']['user'] && is_array( $p['config'] ) && $case[1] === $p['config']['view'] && '' !== (string) $p['config']['nonce'] && false !== strpos( $p['page'], 'window.fhShopApp' ),
		array( $p['result'], substr( $p['page'], 0, 200 ) )
	);
}
$p = fh_seam_page( '/?fh_front_desk=1&list=1&preview=true' );
check( 'with anything else in the address it is not a shop-floor request: sent to the plain address the Gate knows', 'redirect' === ( $p['result']['halt'] ?? '' ) && false !== strpos( $p['result']['location'], 'fh_front_desk=1' ) && false === strpos( $p['result']['location'], 'preview' ), $p['result'] );

// The page's nonce, made in this process as the Front Desk makes it for the session's account.
fh_request( array( 'uri' => '/?fh_front_desk=1&tank=521', 'cookies' => $jar ) );
do_action( 'init' );
App::intercept();
while ( ob_get_level() > 0 && in_array( 'FisHotel\Misc\Sections\Shop_App\App::add_bar', ob_list_handlers(), true ) ) {
	ob_end_clean();
}
$page_nonce = wp_create_nonce( Front_Desk::NONCE );
check( 'the page\'s nonce is the session account\'s', $staff === get_current_user_id() && 1 === wp_verify_nonce( $page_nonce, Front_Desk::NONCE ) );

fh_request( array( 'uri' => '/?fh_shop_app=state', 'cookies' => $jar ) );
$state = json_decode( App::respond( 'state', 'GET' )['body'], true );
check( 'the app\'s home has a button for each: Today\'s list and Dose calculator, both on the Gate\'s list', isset( $state['links'][0], $state['links'][1] ) && 'Today\'s list' === $state['links'][0]['label'] && 'Dose calculator' === $state['links'][1]['label'] && $is( 'GET', (string) parse_url( $state['links'][0]['url'], PHP_URL_PATH ) . '?' . parse_url( $state['links'][0]['url'], PHP_URL_QUERY ) ) && $is( 'GET', '/?' . parse_url( $state['links'][1]['url'], PHP_URL_QUERY ) ), isset( $state['links'] ) ? $state['links'] : 'no links' );

echo "\n4. The one write: every act gets past the Front Desk's guard\n";

$GLOBALS['fh_seam_inside'] = array();
/**
 * Inside the Front Desk's write, before its own handler: what the
 * session may do there — and whether the Settings-level handlers would
 * take it if something called them from there.
 */
function fh_seam_look_inside() {
	$GLOBALS['fh_seam_inside'] = array(
		'user'        => get_current_user_id(),
		'front_desk'  => current_user_can( Clean_Room::CAPABILITY ),
		'settings'    => current_user_can( Clean_Room::SETTINGS_CAPABILITY ),
		'medications' => Medications_Admin::request( array( '_wpnonce' => wp_create_nonce( \FisHotel\Misc\Sections\Clean_Room\Settings::NONCE_ACTION ), 'op' => 'add', 'name' => 'Invented cure' ) )['status'],
	);
}
fh_boot_hooks(
	static function () {
		add_action( 'wp_ajax_fishotel_cleanroom_act', 'fh_seam_look_inside', 5 );
	}
);

/**
 * Play admin-ajax.php for the tablet: init, admin_init, then the hook
 * WordPress picks for the visitor or the logged-in user.
 */
function fh_seam_ajax( array $jar, array $post ) {
	fh_request( array( 'method' => 'POST', 'uri' => '/wp-admin/admin-ajax.php', 'admin' => true, 'ajax' => true, 'post' => $post, 'cookies' => $jar ) );
	$seen = array();
	$run  = fh_run(
		static function () use ( &$seen ) {
			do_action( 'init' );
			do_action( 'admin_init' );
			$seen['logged_in'] = is_user_logged_in();
			$hook              = ( is_user_logged_in() ? 'wp_ajax_' : 'wp_ajax_nopriv_' ) . ( isset( $_REQUEST['action'] ) ? (string) $_REQUEST['action'] : '' );
			if ( has_action( $hook ) ) {
				do_action( $hook );
			}
			throw new FH_Halt( 'die', '0', 400 );
		}
	);
	$seen['kind']   = $run['halt'] ? $run['halt']->kind : '';
	$seen['status'] = $run['halt'] ? (int) $run['halt']->status : 0;
	$seen['data']   = $run['halt'] ? $run['halt']->data : null;
	return $seen;
}

$past = array();
$held = array();
foreach ( $acts as $act ) {
	$GLOBALS['fh_seam_inside'] = array();
	$r                         = fh_seam_ajax( $jar, array( 'action' => 'fishotel_cleanroom_act', 'nonce' => $page_nonce, 'act' => $act, 'tank' => '521' ) );
	// This test has no Clean Room tables: an act that got past the guard stops right there.
	if ( 'json' === $r['kind'] && 500 === $r['status'] && false !== strpos( (string) $r['data']['data']['message'], 'tables are missing' ) ) {
		$past[] = $act;
	} else {
		$held[] = $act . ' (' . $r['kind'] . ' ' . $r['status'] . ')';
	}
}
check( 'all ' . count( $acts ) . ' acts reach the Front Desk\'s own work under the PIN (capability and nonce passed)', array() === $held && count( $past ) === count( $acts ), implode( ', ', $held ) );
check( '… the work is the session\'s account: the Front Desk\'s capability and not the Settings one', array( $staff, true, false ) === array( $GLOBALS['fh_seam_inside']['user'], $GLOBALS['fh_seam_inside']['front_desk'], $GLOBALS['fh_seam_inside']['settings'] ), $GLOBALS['fh_seam_inside'] );
check( '… the medications editor refuses it even when called from there (403: it asks for the Settings capability)', 403 === $GLOBALS['fh_seam_inside']['medications'], $GLOBALS['fh_seam_inside'] );
// The Board's own temporary-tank write (fishotel_cleanroom_temp) asks for the
// same capability as the Front Desk: what keeps it wp-admin is the Gate's
// list (section 5) — the phone does the same acts through its own write.
$r = fh_seam_ajax( $jar, array( 'action' => 'fishotel_cleanroom_act', 'nonce' => 'not-a-nonce', 'act' => 'dose', 'tank' => '521' ) );
check( 'a dose with a wrong nonce: refused by the Front Desk as ever', 'die' === $r['kind'] && 403 === $r['status'] );

foreach ( array( 'fishotel_cleanroom_parse' => array( 'text' => 'dosed copper', 'tank' => '521' ), 'fishotel_cleanroom_screen' => array( 'tank' => '901' ), 'fishotel_cleanroom_suggest' => array( 'term' => 'go' ) ) as $action => $post ) {
	$r = fh_seam_ajax( $jar, array_merge( array( 'action' => $action, 'nonce' => $page_nonce ), $post ) );
	check( $action . ': answered for the session', $r['logged_in'] || 'json' === $r['kind'] ? ( 403 !== $r['status'] && '0' !== $r['data'] ) : false, $r['kind'] . ' ' . $r['status'] );
}

echo "\n5. Settings-level things stay wp-admin\n";
foreach ( $admin_only as $action => $what ) {
	$r = fh_seam_ajax( $jar, array( 'action' => $action, 'nonce' => $page_nonce, '_wpnonce' => $page_nonce, 'op' => 'add', 'act' => 'takedown', 'tank' => '901' ) );
	check( 'a visitor there: ' . $what, false === $r['logged_in'] && 'die' === $r['kind'] && '0' === $r['data'], $r['kind'] . ' ' . $r['status'] );
}

echo "\n6. Nothing on the act path asks for a capability of its own\n";
// Where the Clean Room asks for a capability outside its wp-admin screens:
// the Front Desk's page and its guard (the one the session holds), the
// remember-me cookie of a WordPress login, and the Today section's line.
$asks = array();
foreach ( glob( $room_dir . 'class-*.php' ) as $file ) {
	$name = basename( $file );
	if ( 0 === strpos( $name, 'class-admin' ) || in_array( $name, array( 'class-settings.php', 'class-medications-admin.php' ), true ) ) {
		continue; // The wp-admin screens: never reached from a shop-floor request.
	}
	foreach ( file( $file ) as $n => $line ) {
		if ( preg_match( '/\b(?:current_user_can|user_can)\((.*)$/', $line, $c ) ) {
			$asks[] = $name . ':' . ( preg_match( '/([A-Za-z_]+::[A-Z_]+|\'[a-z_]+\'|\$[a-z_]+)\s*\)/', $c[1], $cap ) ? $cap[1] : trim( $c[1] ) );
		}
	}
}
sort( $asks );
check(
	'outside the wp-admin screens only the Front Desk\'s own capability is asked for',
	array(
		'class-clean-room.php:self::CAPABILITY',
		'class-front-desk.php:Clean_Room::CAPABILITY',
		'class-front-desk.php:Clean_Room::CAPABILITY',
		'class-front-desk.php:Clean_Room::CAPABILITY',
	) === $asks,
	implode( ' | ', $asks )
);

fh_finish();
