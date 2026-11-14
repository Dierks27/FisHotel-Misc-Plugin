<?php
/**
 * Label tests — the address laminated on every tank,
 * home_url( '/?fh_front_desk=1&tank=N' ), must keep working forever:
 *   - with a normal WordPress login exactly as before (the page is
 *     compared byte for byte with the one the site made before the Shop
 *     App existed);
 *   - on any device that is not enrolled exactly as before (the login
 *     redirect, "Not allowed");
 *   - on an enrolled device with a live shop session it opens the tank —
 *     the Clean Room's own page, rendered by its own code — without a
 *     WordPress login, with the app's bar added;
 *   - on an enrolled device with nobody unlocked it goes to the keypad,
 *     bound for that tank;
 *   - opened in the app, a tank screen is told to show what that tank's
 *     water needs today with one tap to the full list (the picker, the
 *     list and the calculator are not).
 *
 * The Front Desk ends its page with a real exit, so every request here is
 * played in a process of its own (page-child.php).
 *
 * Run from the plugin root:
 *   php includes/sections/shop-app/tests/labels-test.php
 *
 * @package FisHotel\Misc\Sections\Shop_App
 */

require_once __DIR__ . '/wp-stubs.php';

/**
 * Play one page request in a child process.
 *
 * @param array $scenario See page-child.php.
 * @return array{page: string, result: array}
 */
function fh_page( array $scenario ) {
	$cmd = escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __DIR__ . '/page-child.php' ) . ' ' . escapeshellarg( base64_encode( json_encode( $scenario ) ) ) . ' 2>&1';
	$out = (string) shell_exec( $cmd );
	$cut = strrpos( $out, "\n@@FH-RESULT@@" );
	if ( false === $cut ) {
		return array(
			'page'   => $out,
			'result' => array(),
		);
	}
	return array(
		'page'   => substr( $out, 0, $cut ),
		'result' => (array) json_decode( substr( $out, $cut + strlen( "\n@@FH-RESULT@@" ) ), true ),
	);
}

/**
 * The config object a page hands its script.
 */
function fh_page_config( $page, $name ) {
	if ( ! preg_match( '/window\.' . preg_quote( $name, '/' ) . ' = (\{.*?\});<\/script>/s', $page, $m ) ) {
		return null;
	}
	return json_decode( $m[1], true );
}

$label = '/?fh_front_desk=1&tank=521';

echo "1. A normal WordPress login — exactly as before\n";
$before = fh_page( array( 'shop_app' => false, 'native' => 'manager', 'uri' => $label ) );
$after  = fh_page( array( 'shop_app' => true, 'native' => 'manager', 'uri' => $label ) );
check( 'before the Shop App: the Front Desk renders the tank page for a shop manager', '' === $before['result']['halt'] && 200 === $before['result']['status'] && false !== strpos( $before['page'], 'window.fhFrontDesk = ' ) && false !== strpos( $before['page'], '</html>' ), substr( $before['page'], 0, 300 ) );
check( 'with the Shop App on: the same page, byte for byte', $before['page'] === $after['page'] && strlen( $after['page'] ) > 20000, strlen( $before['page'] ) . ' vs ' . strlen( $after['page'] ) );
check( '… as the same user, never stored, with nothing of the app in it', $after['result']['user'] === $after['result']['users']['manager'] && $after['result']['nocache'] && false === strpos( $after['page'], 'fhShopApp' ) && false === strpos( $after['page'], 'fh-sa-' ) );
check( '… and nothing is said to page caches that was not said before (a WordPress login has its own cookie for that)', false === $after['result']['nocache_c'] && false === $before['result']['nocache_c'] );
check( '… and it cost the Shop App no query, no option read and no cookie', 0 === $after['result']['queries'] && array() === $after['result']['reads'] && array() === $after['result']['cookies'] );

$owner_before = fh_page( array( 'shop_app' => false, 'native' => 'owner', 'uri' => '/?fh_front_desk=1' ) );
$owner_after  = fh_page( array( 'shop_app' => true, 'native' => 'owner', 'uri' => '/?fh_front_desk=1' ) );
check( 'the Front Desk\'s tank picker (no tank) for an administrator: the same bytes too', $owner_before['page'] === $owner_after['page'] && false !== strpos( $owner_after['page'], 'window.fhFrontDesk = ' ) );

$enrolled = fh_page( array( 'shop_app' => true, 'native' => 'manager', 'enrol' => true, 'uri' => $label ) );
check( 'the same login on a device that IS enrolled, nobody unlocked: still the same page — a WordPress login needs no PIN', $before['page'] === $enrolled['page'] && $enrolled['result']['user'] === $enrolled['result']['users']['manager'] );

echo "\n2. Any other device — exactly as before\n";
$before = fh_page( array( 'shop_app' => false, 'uri' => $label ) );
$after  = fh_page( array( 'shop_app' => true, 'uri' => $label ) );
check( 'nobody logged in: sent to the WordPress login, to come back to that tank', 'redirect' === $after['result']['halt'] && 'https://shop.example.test/wp-login.php?redirect_to=' . rawurlencode( 'https://shop.example.test/?fh_front_desk=1&tank=521' ) === $after['result']['location'] );
check( '… the very same redirect as before the Shop App', $before['result']['location'] === $after['result']['location'] && $before['result']['status'] === $after['result']['status'] && $before['page'] === $after['page'] );
check( '… at no cost to the Shop App', 0 === $after['result']['queries'] && array() === $after['result']['reads'] && array() === $after['result']['cookies'] );

$before = fh_page( array( 'shop_app' => false, 'native' => 'customer', 'uri' => $label ) );
$after  = fh_page( array( 'shop_app' => true, 'native' => 'customer', 'uri' => $label ) );
check( 'a customer login: "Not allowed" (403), the same bytes as before', 403 === $after['result']['status'] && false !== strpos( $after['page'], 'Not allowed' ) && $before['page'] === $after['page'] );

$revoked = fh_page( array( 'shop_app' => true, 'enrol' => true, 'unlock' => '4821', 'revoke' => true, 'uri' => $label ) );
check( 'a revoked device, its cookies still in the browser: the WordPress login, as for any stranger', 'redirect' === $revoked['result']['halt'] && 0 === strpos( $revoked['result']['location'], 'https://shop.example.test/wp-login.php?redirect_to=' ) && ! $revoked['result']['logged_in'] );

echo "\n3. An enrolled device with a live shop session — the tank, without a WordPress login\n";
$open = fh_page( array( 'shop_app' => true, 'enrol' => true, 'unlock' => '4821', 'uri' => $label ) );
$fd   = fh_page_config( $open['page'], 'fhFrontDesk' );
$app  = fh_page_config( $open['page'], 'fhShopApp' );
check( 'the label opens the tank page (200) — no redirect to a login', '' === $open['result']['halt'] && 200 === $open['result']['status'] && is_array( $fd ), substr( $open['page'], 0, 300 ) );
check( 'it is the Front Desk\'s own page: its markup, its script, its config', false !== strpos( $open['page'], '<body class="fh-fd">' ) && false !== strpos( $open['page'], 'id="fh-fd-app"' ) && isset( $fd['ajaxUrl'], $fd['nonce'], $fd['screen'], $fd['i18n'] ) );
check( 'rendered as the account Sam\'s work is recorded under (the shared Shop staff account)', $open['result']['logged_in'] && $open['result']['staff'] > 0 && $open['result']['user'] === $open['result']['staff'] );
check( 'its nonce is that account\'s', $fd['nonce'] === $open['result']['nonce'] );
check( 'never stored: the Front Desk\'s own no-cache headers, and page-cache plugins are told DONOTCACHEPAGE (to them this device is an anonymous visitor)', true === $open['result']['nocache'] && true === $open['result']['nocache_c'] );
check( 'the app\'s bar is added: who is unlocked, on which device, the session\'s end', is_array( $app ) && 'frontdesk' === $app['mode'] && 'Sam' === $app['session']['person'] && 'Shop tablet' === $app['device'] && 7200 === $app['session']['expires'] - $app['session']['now'] );
check( '… with what it needs to stand between the page and the network', 'https://shop.example.test/wp-admin/admin-ajax.php' === $app['urls']['ajax'] && $fd['ajaxUrl'] === $app['urls']['ajax'] && 'fishotel_cleanroom_act' === $app['write'] && 'fh_save_id' === $app['saveField'] && in_array( 'fishotel_cleanroom_screen', $app['actions'], true ) );
check( '… added before </body>, after the Front Desk\'s own script, once', 1 === substr_count( $open['page'], 'window.fhShopApp = ' ) && strpos( $open['page'], 'window.fhFrontDesk = ' ) < strpos( $open['page'], 'window.fhShopApp = ' ) && strpos( $open['page'], 'window.fhShopApp = ' ) < strripos( $open['page'], '</body>' ) );
check( '… and the bar holds no PIN, no token, no cookie value and no user id', false === strpos( json_encode( $app ), '4821' ) && ! isset( $app['user_id'] ) && 1 === preg_match( '/^[a-f0-9]{16}$/', $app['session']['account'] ) );
$without_bar = preg_replace( '/<style id="fh-sa-style">.*?<\/script><\/body>/s', '</body>', $open['page'] );
$as_manager  = fh_page( array( 'shop_app' => false, 'native' => 'manager', 'uri' => $label ) );
$strip       = static function ( $page ) {
	return preg_replace( '/"nonce":"[a-f0-9]{10}"/', '"nonce":"-"', $page );
};
check( 'take the bar away and the page is the one a WordPress login gets (but for the nonce): the same code, not a copy', $strip( $without_bar ) === $strip( $as_manager['page'] ) );

$picker = fh_page( array( 'shop_app' => true, 'enrol' => true, 'unlock' => '4821', 'uri' => '/?fh_front_desk=1' ) );
check( 'the Front Desk\'s tank picker opens too, with the bar', 200 === $picker['result']['status'] && is_array( fh_page_config( $picker['page'], 'fhShopApp' ) ) );

echo "\n3b. A label scanned in the app — what that tank's water needs today, then the full list\n";
check( 'on the tank: the bar is told to show what this water needs today, with the full list one tap away', true === $app['water']['show'] && 'https://shop.example.test/?fh_front_desk=1&list=1' === $app['water']['listUrl'] );
$fh_desk_src = (string) file_get_contents( FISHOTEL_MISC_PATH . 'includes/sections/clean-room/class-front-desk.php' );
check( '… from what the Front Desk\'s page already holds — the day\'s list and the tank\'s building and own water, no request of its own', array_key_exists( 'todoList', (array) $fd['screen'] ) && false !== strpos( $fh_desk_src, "\$data['todoList'] = is_array( \$room['todo'] )" ) && false !== strpos( $fh_desk_src, "'buildingId'  => (int) \$current['building_id']," ) && false !== strpos( $fh_desk_src, "'ownWater'    => \$slot," ) );
check( 'the strings are there, in plain words', 'Today for this water' === $app['i18n']['waterTitle'] && 'Full list for today ›' === $app['i18n']['waterAll'] && 'Nothing due today' === $app['i18n']['waterNothing'] );
check( 'the tank picker: no panel', false === fh_page_config( $picker['page'], 'fhShopApp' )['water']['show'] );
$list = fh_page( array( 'shop_app' => true, 'enrol' => true, 'unlock' => '4821', 'uri' => '/?fh_front_desk=1&list=1' ) );
check( 'the full list itself: opens under the PIN, no panel over it', 200 === $list['result']['status'] && false === fh_page_config( $list['page'], 'fhShopApp' )['water']['show'] );
$calc = fh_page( array( 'shop_app' => true, 'enrol' => true, 'unlock' => '4821', 'uri' => '/?fh_front_desk=1&calc=1' ) );
check( 'the dose calculator: no panel', 200 === $calc['result']['status'] && false === fh_page_config( $calc['page'], 'fhShopApp' )['water']['show'] );
$off = fh_page( array( 'shop_app' => false, 'native' => 'manager', 'uri' => $label ) );
check( 'with the Shop App off the label is the Front Desk\'s own page, nothing of the panel in it', false === strpos( $off['page'], 'fh-sa-water' ) && false === strpos( $off['page'], 'waterTitle' ) && false === strpos( $off['page'], 'fhShopApp' ) );

echo "\n3c. Scan a tank — inside the app only\n";
check( 'the tank screen in the app carries the scanner once (Scan a tank in the bar), with the strings it says', 1 === substr_count( $open['page'], 'root.fhShopScanner = api' ) && 1 === substr_count( $open['page'], 'function readTankCode(' ) && 'Scan a tank' === $app['i18n']['scan'] && 'Type the tank number' === $app['i18n']['typeTitle'] && 'That code is for another website. It was not opened.' === $app['i18n']['scanOtherSite'] );
check( '… and the address of the QR reader for browsers without one — not the reader itself: it is loaded only when the scanner opens on such a browser', 1 === preg_match( '#^https://shop\.example\.test/wp-content/plugins/fishotel-misc-plugin/includes/sections/shop-app/js/vendor/jsQR\.js\?ver=[^&]+$#', $app['urls']['qrLib'] ) && false === strpos( $open['page'], 'webpackUniversalModuleDefinition' ) && false === strpos( $open['page'], 'jsQR.js"' ) );
check( '… on the picker, the day\'s list and the calculator too (every screen inside the app)', false !== strpos( $picker['page'], 'root.fhShopScanner = api' ) && false !== strpos( $list['page'], 'root.fhShopScanner = api' ) && false !== strpos( $calc['page'], 'root.fhShopScanner = api' ) );
foreach ( array(
	'with the Shop App off (a WordPress login)' => $off,
	'with the Shop App on, opened with a WordPress login' => $as_manager,
	'with the Shop App on, a device that is not enrolled' => fh_page( array( 'shop_app' => true, 'native' => 'manager', 'uri' => $label ) ),
) as $fh_what => $fh_seen ) {
	check( 'no scanner ' . $fh_what . ': nothing of it on the Front Desk page', false === strpos( $fh_seen['page'], 'fhShopScanner' ) && false === strpos( $fh_seen['page'], 'jsQR' ) && false === strpos( $fh_seen['page'], 'fh-sa-scan' ) && false === strpos( $fh_seen['page'], 'Scan a tank' ) && false === strpos( $fh_seen['page'], 'getUserMedia' ) );
}

$robin = fh_page( array( 'shop_app' => true, 'enrol' => true, 'unlock' => '1379', 'uri' => $label ) );
check( 'Robin, who has an account of their own: rendered as that account', $robin['result']['user'] === $robin['result']['users']['manager'] && 'Robin' === fh_page_config( $robin['page'], 'fhShopApp' )['session']['person'] );

$both = fh_page( array( 'shop_app' => true, 'enrol' => true, 'unlock' => '4821', 'native' => 'customer', 'uri' => $label ) );
check( 'a customer login on the same device does not get in the way: the tank opens as Sam', 200 === $both['result']['status'] && $both['result']['user'] === $both['result']['staff'] && 'Sam' === fh_page_config( $both['page'], 'fhShopApp' )['session']['person'] );
$both = fh_page( array( 'shop_app' => true, 'enrol' => true, 'unlock' => '4821', 'native' => 'owner', 'uri' => $label ) );
check( 'nor does an administrator\'s login: Sam unlocked, so Sam is who is working', $both['result']['user'] === $both['result']['staff'] && 'Sam' === fh_page_config( $both['page'], 'fhShopApp' )['session']['person'] );

$extra = fh_page( array( 'shop_app' => true, 'enrol' => true, 'unlock' => '4821', 'uri' => $label . '&utm_source=scanner' ) );
check( 'a label address with something added by a scanner app: sent on to the plain one', 'redirect' === $extra['result']['halt'] && 'https://shop.example.test/?fh_front_desk=1&tank=521' === $extra['result']['location'] && $extra['result']['nocache'] );
$other = fh_page( array( 'shop_app' => true, 'enrol' => true, 'unlock' => '4821', 'uri' => '/shop/?fh_front_desk=1&tank=521' ) );
check( '… and so is the switch on another path', 'redirect' === $other['result']['halt'] && 'https://shop.example.test/?fh_front_desk=1&tank=521' === $other['result']['location'] );

echo "\n4. An enrolled device with nobody unlocked — the keypad, then that tank\n";
$pad = fh_page( array( 'shop_app' => true, 'enrol' => true, 'uri' => $label ) );
check( 'the label goes to the app\'s keypad, bound for tank 521', 'redirect' === $pad['result']['halt'] && 'https://shop.example.test/?fh_shop_app=1&tank=521' === $pad['result']['location'] && $pad['result']['nocache'] );
check( '… not to the WordPress login, and without rendering anything of the tank', false === strpos( $pad['result']['location'], 'wp-login' ) && false === strpos( $pad['page'], 'fhFrontDesk' ) && ! $pad['result']['logged_in'] );
$pad = fh_page( array( 'shop_app' => true, 'enrol' => true, 'uri' => '/?fh_front_desk=1' ) );
check( 'the label-less address goes to the keypad, bound for the home screen', 'https://shop.example.test/?fh_shop_app=1' === $pad['result']['location'] );
$pad = fh_page( array( 'shop_app' => true, 'enrol' => true, 'uri' => '/?fh_front_desk=1&tank=abc' ) );
check( 'a tank that is not a number is not carried along', 'https://shop.example.test/?fh_shop_app=1' === $pad['result']['location'] );

$expired = fh_page( array( 'shop_app' => true, 'enrol' => true, 'unlock' => '4821', 'wait' => 7201, 'uri' => $label ) );
check( 'two hours and a second after the last use: the keypad again', 'redirect' === $expired['result']['halt'] && 'https://shop.example.test/?fh_shop_app=1&tank=521' === $expired['result']['location'] );
$alive = fh_page( array( 'shop_app' => true, 'enrol' => true, 'unlock' => '4821', 'wait' => 7199, 'uri' => $label ) );
check( 'a second before: still the tank', '' === $alive['result']['halt'] && 200 === $alive['result']['status'] && 'Sam' === fh_page_config( $alive['page'], 'fhShopApp' )['session']['person'] );
$locked = fh_page( array( 'shop_app' => true, 'enrol' => true, 'unlock' => '4821', 'lock' => true, 'uri' => $label ) );
check( 'after Lock: the keypad', 'https://shop.example.test/?fh_shop_app=1&tank=521' === $locked['result']['location'] );
$customer = fh_page( array( 'shop_app' => true, 'enrol' => true, 'native' => 'customer', 'uri' => $label ) );
check( 'a customer login on an enrolled device (staff shop here too): the keypad — not "Not allowed"', 'redirect' === $customer['result']['halt'] && 'https://shop.example.test/?fh_shop_app=1&tank=521' === $customer['result']['location'] );

echo "\n5. Other pages are left alone\n";
$home = fh_page( array( 'shop_app' => true, 'enrol' => true, 'unlock' => '4821', 'uri' => '/' ) );
check( 'the shop front on an unlocked device: nothing rendered by either section, not logged in, no query', 'none' === $home['result']['halt'] && '' === trim( $home['page'] ) && ! $home['result']['logged_in'] && 0 === $home['result']['queries'] );
$off = fh_page( array( 'shop_app' => true, 'enrol' => true, 'unlock' => '4821', 'uri' => '/?fh_front_desk=0' ) );
check( 'the switch set to 0 is no Front Desk address', 'none' === $off['result']['halt'] && '' === trim( $off['page'] ) );

fh_finish();
