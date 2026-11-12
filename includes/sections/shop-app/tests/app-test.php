<?php
/**
 * App tests — the app's own address: the manifest (valid, with icons that
 * exist and are what it says), the shell (ONE static page: the same bytes
 * whoever asks, nothing personal in it, storable), the service worker's
 * script and the short list of addresses it may keep, the state (never
 * stored; nothing of a tank or a person before the PIN), unlock and lock
 * (a POST with the app's header, or nothing), the buildings and tanks for
 * the picker, the buttons other sections add, and every string the script
 * asks for.
 *
 * Run from the plugin root:
 *   php includes/sections/shop-app/tests/app-test.php
 *
 * @package FisHotel\Misc\Sections\Shop_App
 */

require_once __DIR__ . '/wp-stubs.php';

use FisHotel\Misc\Sections\Shop_App\Access;
use FisHotel\Misc\Sections\Shop_App\App;
use FisHotel\Misc\Sections\Shop_App\Http;
use FisHotel\Misc\Sections\Shop_App\Shop_App;

$store = fh_fresh_store();
fh_boot_section( true );
$dir = dirname( __DIR__ ) . '/';

$owner   = fh_add_user( 'owner', 'administrator', 'Olive Owner' );
$manager = fh_add_user( 'manager', 'shop_manager', 'Morgan Manager' );
fh_request();
$sam    = fh_add_person( 'Sam Shore', '4821' );
$tablet = Access::instance()->enrol_here( 'Shop tablet', $owner );
$jar    = fh_jar( array() );

/**
 * A header of an answer, by name ('' = not sent).
 */
function fh_header( array $response, $name ) {
	foreach ( $response['headers'] as $line ) {
		if ( 0 === stripos( $line, $name . ':' ) ) {
			return trim( substr( $line, strlen( $name ) + 1 ) );
		}
	}
	return '';
}

echo "1. The manifest\n";
fh_request( array( 'uri' => '/?fh_shop_app=manifest' ) );
$response = App::respond( 'manifest', 'GET' );
$manifest = json_decode( $response['body'], true );
check( 'it is JSON, served as a web app manifest', 200 === $response['status'] && is_array( $manifest ) && 'application/manifest+json; charset=utf-8' === fh_header( $response, 'Content-Type' ) && "\xEF\xBB\xBF" !== substr( $response['body'], 0, 3 ) );
check( 'name "FisHotel Shop", and a short name that fits under an icon', 'FisHotel Shop' === $manifest['name'] && '' !== $manifest['short_name'] && strlen( $manifest['short_name'] ) <= 12 );
check( 'standalone display: its own window, no browser bar', 'standalone' === $manifest['display'] );
check( 'a dark theme colour and background', 1 === preg_match( '/^#[0-9a-f]{6}$/', $manifest['theme_color'] ) && $manifest['theme_color'] === $manifest['background_color'] && hexdec( substr( $manifest['theme_color'], 1, 2 ) ) + hexdec( substr( $manifest['theme_color'], 3, 2 ) ) + hexdec( substr( $manifest['theme_color'], 5, 2 ) ) < 120 );
check( 'it starts at the app\'s address, on this site', 'https://shop.example.test/?fh_shop_app=1' === $manifest['start_url'] && $manifest['start_url'] === $manifest['id'] );
check( 'its scope is the site\'s home path, which holds the app and the tank labels alike', 'https://shop.example.test/' === $manifest['scope'] && 0 === strpos( $manifest['start_url'], $manifest['scope'] ) && 0 === strpos( Shop_App::label_url( 5 ), $manifest['scope'] ) );
check( 'a language and a description', 'en-US' === $manifest['lang'] && strlen( $manifest['description'] ) > 10 );

$icons = $manifest['icons'];
$sizes = array();
foreach ( $icons as $icon ) {
	$sizes[] = $icon['sizes'] . ' ' . $icon['purpose'];
	$file    = $dir . 'assets/' . basename( $icon['src'] );
	$bytes   = is_file( $file ) ? (string) file_get_contents( $file ) : '';
	$is_png  = "\x89PNG\r\n\x1a\n" === substr( $bytes, 0, 8 ) && 'IHDR' === substr( $bytes, 12, 4 );
	$dims    = $is_png ? unpack( 'Nw/Nh', substr( $bytes, 16, 8 ) ) : array( 'w' => 0, 'h' => 0 );
	check( basename( $icon['src'] ) . ': a PNG file in assets/, ' . $icon['sizes'] . ' as the manifest says', $is_png && 'image/png' === $icon['type'] && $icon['sizes'] === $dims['w'] . 'x' . $dims['h'] && 0 === strpos( $icon['src'], FISHOTEL_MISC_URL . 'includes/sections/shop-app/assets/' ), $file );
	if ( $is_png && function_exists( 'imagecreatefromstring' ) ) {
		$image  = imagecreatefromstring( $bytes );
		$corner = imagecolorsforindex( $image, imagecolorat( $image, 0, 0 ) );
		$middle = imagecolorsforindex( $image, imagecolorat( $image, (int) ( $dims['w'] / 2 ), (int) ( $dims['h'] * 0.8 ) ) );
		if ( 'maskable' === $icon['purpose'] ) {
			check( '… the maskable one is filled to its corners (a launcher may cut it to any shape)', 0 === $corner['alpha'] && $corner['red'] < 40 );
		} else {
			check( '… with rounded corners (transparent) on the dark background', 127 === $corner['alpha'] && 0 === $middle['alpha'] && $middle['red'] < 40 );
		}
	}
}
check( '192 px and 512 px icons, plus a maskable one', array( '192x192 any', '512x512 any', '512x512 maskable' ) === $sizes );
check( 'the manifest may be stored, and is checked again each time', 'no-cache' === fh_header( $response, 'Cache-Control' ) && '' === fh_header( $response, 'Vary' ) );
check( 'there is nothing personal in it', false === strpos( $response['body'], 'Sam' ) && false === strpos( $response['body'], 'Shop tablet' ) );

echo "\n2. The shell — one static page\n";
fh_request( array( 'uri' => '/?fh_shop_app=1' ) );
$store->queries = 0;
$anonymous      = App::respond( '1', 'GET' );
check( 'it is an HTML page', 200 === $anonymous['status'] && 'text/html; charset=utf-8' === fh_header( $anonymous, 'Content-Type' ) && 0 === strpos( $anonymous['body'], '<!DOCTYPE html>' ) );
check( 'making it asks the database nothing and reads no option that is not autoloaded', 0 === $store->queries && array() === $GLOBALS['fh_option_reads'] && array() === $GLOBALS['wpdb']->queries, json_encode( $GLOBALS['fh_option_reads'] ) );
check( 'it sets no cookie', array() === $GLOBALS['fh_sent']['cookies'] );

fh_request( array( 'uri' => '/?fh_shop_app=1', 'cookies' => $jar ) );
$enrolled = App::respond( '1', 'GET' );
fh_request( array( 'cookies' => $jar ) );
Access::instance()->unlock( '4821' );
$jar = fh_jar( $jar );
fh_request( array( 'uri' => '/?fh_shop_app=1&tank=521', 'cookies' => $jar ) );
$unlocked = App::respond( '1', 'GET' );
fh_request( array( 'uri' => '/?fh_shop_app=1', 'cookies' => array( 'wordpress_logged_in_test' => (string) $owner ) ) );
$admin = App::respond( '1', 'GET' );
check( 'the same bytes for a stranger, an enrolled device, an unlocked device and a logged-in administrator', $anonymous['body'] === $enrolled['body'] && $anonymous['body'] === $unlocked['body'] && $anonymous['body'] === $admin['body'] && strlen( $anonymous['body'] ) > 20000 );
check( 'no name, no device, no tank, no nonce, no token in it', false === strpos( $anonymous['body'], 'Sam Shore' ) && false === strpos( $anonymous['body'], 'Shop tablet' ) && false === strpos( $anonymous['body'], 'Olive' ) && ! preg_match( '/"nonce"\s*:/', $anonymous['body'] ) && false === strpos( $anonymous['body'], $jar[ Http::cookie_name( Http::DEVICE ) ] ) );
check( 'it may be stored (that is what the service worker keeps) and is checked again each time', 'no-cache' === fh_header( $anonymous, 'Cache-Control' ) && '' === fh_header( $anonymous, 'Vary' ) && false === stripos( implode( ' ', $anonymous['headers'] ), 'no-store' ) && false === stripos( implode( ' ', $anonymous['headers'] ), 'private' ) );
check( 'it may not be framed by another site', "frame-ancestors 'none'" === fh_header( $anonymous, 'Content-Security-Policy' ) && 'DENY' === fh_header( $anonymous, 'X-Frame-Options' ) );
check( 'search engines are told to stay out', 'noindex, nofollow' === fh_header( $anonymous, 'X-Robots-Tag' ) && false !== strpos( $anonymous['body'], '<meta name="robots" content="noindex, nofollow">' ) );
check( 'it links the manifest and an icon for the home screen', false !== strpos( $anonymous['body'], '<link rel="manifest" href="https://shop.example.test/?fh_shop_app=manifest">' ) && false !== strpos( $anonymous['body'], '<link rel="apple-touch-icon" href="' . FISHOTEL_MISC_URL . 'includes/sections/shop-app/assets/icon-192.png">' ) );
check( 'a viewport for a phone, the dark theme colour, "FisHotel Shop" as its title', false !== strpos( $anonymous['body'], 'width=device-width' ) && false !== strpos( $anonymous['body'], '<meta name="theme-color" content="#0f1720">' ) && false !== strpos( $anonymous['body'], '<title>FisHotel Shop</title>' ) );
check( 'its styles and script are inlined: it asks for nothing else to draw the keypad', false === strpos( $anonymous['body'], '<script src=' ) && false === strpos( $anonymous['body'], 'rel="stylesheet"' ) && false !== strpos( $anonymous['body'], '.fh-sa-key' ) && false !== strpos( $anonymous['body'], 'function keypad(' ) );
preg_match( '/window\.fhShopApp = (\{.*?\});<\/script>/s', $anonymous['body'], $m );
$config = json_decode( $m[1], true );
check( 'the script is told where to ask — and nothing about who', 'app' === $config['mode'] && array( 'mode', 'urls', 'header', 'labelVar', 'i18n' ) === array_keys( $config ) && 'https://shop.example.test/?fh_shop_app=state' === $config['urls']['state'] && 'https://shop.example.test/?fh_front_desk=1' === $config['urls']['label'] && 'X-FH-Shop-App' === $config['header'] && 'fh_front_desk' === $config['labelVar'] );
check( 'the service worker is registered for the app\'s own address only', 'https://shop.example.test/?fh_shop_app=sw' === $config['urls']['sw'] && 'https://shop.example.test/?fh_shop_app=' === $config['urls']['swScope'] && 0 !== strpos( Shop_App::label_url( 5 ), $config['urls']['swScope'] ) && 0 !== strpos( 'https://shop.example.test/', $config['urls']['swScope'] ) && 0 === strpos( Shop_App::app_url( 5 ), $config['urls']['swScope'] ) );
check( 'an address that is not one of the app\'s routes is the shell too', $anonymous['body'] === App::respond( 'anything', 'GET' )['body'] );

echo "\n3. The service worker's script\n";
fh_request( array( 'uri' => '/?fh_shop_app=sw', 'cookies' => $jar ) );
$sw = App::respond( 'sw', 'GET' );
check( 'it is JavaScript, checked again each time', 200 === $sw['status'] && 'application/javascript; charset=utf-8' === fh_header( $sw, 'Content-Type' ) && 'no-cache' === fh_header( $sw, 'Cache-Control' ) );
preg_match( '/var FH_SW = (\{.*?\});\n/', $sw['body'], $m );
$sw_config = json_decode( $m[1], true );
check( 'it is told a cache name and the shell\'s address', 1 === preg_match( '/^fh-shop-app-[a-f0-9]{12}$/', $sw_config['cache'] ) && 'https://shop.example.test/?fh_shop_app=1' === $sw_config['shell'] );
check( 'the ONLY addresses it may keep: the shell, the manifest, the three icons', array( 'https://shop.example.test/?fh_shop_app=1', 'https://shop.example.test/?fh_shop_app=manifest', FISHOTEL_MISC_URL . 'includes/sections/shop-app/assets/icon-192.png', FISHOTEL_MISC_URL . 'includes/sections/shop-app/assets/icon-512.png', FISHOTEL_MISC_URL . 'includes/sections/shop-app/assets/icon-maskable-512.png' ) === $sw_config['keep'] );
check( '… not the state, not unlock or lock, not a tank screen, not admin-ajax', array() === array_filter( $sw_config['keep'], static function ( $url ) { return preg_match( '/state|unlock|lock|enrol|fh_front_desk|admin-ajax|wp-admin|wp-json/', $url ); } ) );
check( 'the worker\'s own code follows', false !== strpos( $sw['body'], "addEventListener('fetch'" ) && false !== strpos( $sw['body'], "credentials: 'omit'" ) );
check( 'the script is the same whoever asks', $sw['body'] === ( function () {
	fh_request( array( 'uri' => '/?fh_shop_app=sw' ) );
	return App::respond( 'sw', 'GET' )['body'];
} )() );

echo "\n4. The state — never stored, and nothing before the PIN\n";
fh_request( array( 'uri' => '/?fh_shop_app=state' ) );
$response = App::respond( 'state', 'GET' );
$state    = json_decode( $response['body'], true );
check( 'JSON that nothing may store: no-store, private, varies by cookie', 'application/json; charset=utf-8' === fh_header( $response, 'Content-Type' ) && false !== strpos( fh_header( $response, 'Cache-Control' ), 'no-store' ) && false !== strpos( fh_header( $response, 'Cache-Control' ), 'private' ) && 'Cookie' === fh_header( $response, 'Vary' ) && 'nosniff' === fh_header( $response, 'X-Content-Type-Options' ) );
check( 'a device that is not enrolled: "not enrolled" — no device, no person, no tank, not even how many digits a PIN has', false === $state['enrolled'] && null === $state['session'] && ! isset( $state['device'] ) && ! isset( $state['home'] ) && ! isset( $state['hasPeople'] ) && ! isset( $state['lockout'] ) && ! isset( $state['pinLength'] ) && false === strpos( $response['body'], 'Sam' ) && false === strpos( $response['body'], 'Shop tablet' ) );
check( '… and where a manager can set it up', 'https://shop.example.test/wp-admin/admin.php?page=fishotel-misc-shop-app' === $state['adminUrl'] );
check( '… at no query and no option read', 0 === $store->queries && array() === $GLOBALS['fh_option_reads'], json_encode( $GLOBALS['fh_option_reads'] ) );

fh_request( array( 'cookies' => $jar ) );
Access::instance()->lock();
$jar = fh_jar( $jar );
fh_request( array( 'uri' => '/?fh_shop_app=state', 'cookies' => $jar ) );
$state = json_decode( App::respond( 'state', 'GET' )['body'], true );
check( 'enrolled, nobody unlocked: the device\'s name, the PIN length, that somebody can unlock — for the keypad', true === $state['enrolled'] && 'Shop tablet' === $state['device']['name'] && 4 === $state['pinLength'] && true === $state['hasPeople'] && array( 'locked' => false, 'seconds' => 0 ) === $state['lockout'] );
check( '… and still no person and no tank', null === $state['session'] && ! isset( $state['home'], $state['links'] ) && false === strpos( json_encode( $state ), 'Sam' ) );

fh_request( array( 'uri' => '/?fh_shop_app=state', 'cookies' => array_merge( $jar, array( 'wordpress_logged_in_test' => (string) $manager ) ) ) );
$state = json_decode( App::respond( 'state', 'GET' )['body'], true );
check( 'a WordPress login on the device that opens the Front Desk without a PIN is said plainly, with a way to log it out', 'Morgan Manager' === $state['wpLogin']['name'] && 0 === strpos( $state['wpLogin']['logoutUrl'], 'https://shop.example.test/wp-login.php?action=logout&_wpnonce=' ) && false === strpos( $state['wpLogin']['logoutUrl'], '&amp;' ) );

echo "\n5. Unlock and lock — a POST with the app's header, or nothing\n";
fh_request( array( 'uri' => '/?fh_shop_app=unlock', 'cookies' => $jar ) );
$_GET['pin'] = '4821';
$response    = App::respond( 'unlock', 'GET' );
check( 'a GET with the PIN in the address: 400, nothing happens', 400 === $response['status'] && 'bad_request' === json_decode( $response['body'], true )['code'] && 0 === $store->devices[ $tablet['id'] ]['fail_count'] && array() === $GLOBALS['fh_sent']['cookies'] );
fh_request( array( 'method' => 'POST', 'uri' => '/?fh_shop_app=unlock', 'post' => array( 'pin' => '4821' ), 'cookies' => $jar ) );
$response = App::respond( 'unlock', 'POST' );
check( 'a POST without the header (what a form on another site could send): 400, no session, no try used up', 400 === $response['status'] && array() === $GLOBALS['fh_sent']['cookies'] && 0 === $store->devices[ $tablet['id'] ]['fail_count'] && '' === $store->devices[ $tablet['id'] ]['session_id'] );
fh_request( array( 'method' => 'POST', 'uri' => '/?fh_shop_app=unlock', 'post' => array( 'pin' => '0000' ), 'cookies' => $jar, 'app_header' => true ) );
$response = App::respond( 'unlock', 'POST' );
$body     = json_decode( $response['body'], true );
check( 'with the header, a wrong PIN: wrong_pin, the tries left, the state — never stored', 200 === $response['status'] && false === $body['ok'] && 'wrong_pin' === $body['code'] && 4 === $body['left'] && null === $body['state']['session'] && false !== strpos( fh_header( $response, 'Cache-Control' ), 'no-store' ) );
fh_request( array( 'method' => 'POST', 'uri' => '/?fh_shop_app=unlock', 'post' => array( 'pin' => array( '4821' ) ), 'cookies' => $jar, 'app_header' => true ) );
check( 'a PIN sent as an array is a wrong PIN', 'wrong_pin' === json_decode( App::respond( 'unlock', 'POST' )['body'], true )['code'] );
fh_request( array( 'method' => 'POST', 'uri' => '/?fh_shop_app=unlock', 'post' => array( 'pin' => '4821' ), 'cookies' => $jar, 'app_header' => true ) );
$response = App::respond( 'unlock', 'POST' );
$body     = json_decode( $response['body'], true );
$jar      = fh_jar( $jar );
check( 'the right PIN: ok, a session cookie, and the state now says who', true === $body['ok'] && isset( $jar[ Http::cookie_name( Http::SESSION ) ] ) && 'Sam Shore' === $body['state']['session']['person'] && 7200 === $body['state']['session']['expires'] - $body['state']['session']['now'] && 'last_use' === $body['state']['session']['mode'] );
check( 'the answer carries no PIN, no token and no user id', false === strpos( $response['body'], '4821' ) && false === strpos( $response['body'], $jar[ Http::cookie_name( Http::DEVICE ) ] ) && ! isset( $body['state']['session']['user_id'] ) && 1 === preg_match( '/^[a-f0-9]{16}$/', $body['state']['session']['account'] ) );
check( 'in this test the Clean Room has no tables: the app says so instead of showing an empty picker', false === $body['state']['frontDesk']['available'] && false !== strpos( $body['state']['frontDesk']['reason'], 'tables are missing' ) && null === $body['state']['home'] );

fh_request( array( 'method' => 'POST', 'uri' => '/?fh_shop_app=lock', 'cookies' => $jar ) );
check( 'Lock without the header: 400, the session lives', 400 === App::respond( 'lock', 'POST' )['status'] && '' !== $store->devices[ $tablet['id'] ]['session_id'] );
fh_request( array( 'uri' => '/?fh_shop_app=lock', 'cookies' => $jar, 'app_header' => true ) );
check( 'Lock as a GET: 400', 400 === App::respond( 'lock', 'GET' )['status'] && '' !== $store->devices[ $tablet['id'] ]['session_id'] );
fh_request( array( 'method' => 'POST', 'uri' => '/?fh_shop_app=lock', 'cookies' => $jar, 'app_header' => true ) );
$body = json_decode( App::respond( 'lock', 'POST' )['body'], true );
check( 'Lock with the header: the session is over', true === $body['ok'] && null === $body['state']['session'] && '' === $store->devices[ $tablet['id'] ]['session_id'] );

echo "\n6. Where the app's address is answered\n";
foreach ( array(
	'another path'            => array( 'uri' => '/shop/?fh_shop_app=state' ),
	'wp-admin'                => array( 'uri' => '/wp-admin/index.php?fh_shop_app=1', 'admin' => true ),
	'admin-ajax'              => array( 'uri' => '/wp-admin/admin-ajax.php?fh_shop_app=state', 'admin' => true, 'ajax' => true ),
	'no parameter'            => array( 'uri' => '/' ),
	'an empty parameter'      => array( 'uri' => '/?fh_shop_app=' ),
	'the parameter as an array' => array( 'uri' => '/?fh_shop_app[]=1' ),
) as $what => $request ) {
	fh_request( $request );
	ob_start();
	App::route(); // Would print and end the request if it took it.
	$printed = ob_get_clean();
	check( 'not answered: ' . $what, '' === $printed );
}
$cmd = escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __DIR__ . '/page-child.php' ) . ' ';
$out = (string) shell_exec( $cmd . escapeshellarg( base64_encode( json_encode( array( 'shop_app' => true, 'uri' => '/?fh_shop_app=1' ) ) ) ) . ' 2>&1' );
$cut = strrpos( $out, "\n@@FH-RESULT@@" );
$res = json_decode( substr( $out, $cut + 14 ), true );
check( 'the home address with the parameter: answered at wp_loaded, and the request ends there', '' === $res['halt'] && substr( $out, 0, $cut ) === $anonymous['body'] && in_array( 'Cache-Control: no-cache', $res['headers'], true ) );
check( '… the static shell may be kept by a page cache', false === $res['nocache_c'] );
foreach ( array( 'state', 'unlock', 'lock', 'enrol' ) as $private ) {
	$out = (string) shell_exec( $cmd . escapeshellarg( base64_encode( json_encode( array( 'shop_app' => true, 'uri' => '/?fh_shop_app=' . $private ) ) ) ) . ' 2>&1' );
	$res = json_decode( substr( $out, strrpos( $out, "\n@@FH-RESULT@@" ) + 14 ), true );
	if ( true !== $res['nocache_c'] || ! in_array( 'Cache-Control: no-store, private, max-age=0', $res['headers'], true ) ) {
		check( $private . ' is never kept by a page cache', false, json_encode( $res ) );
	}
}
check( 'state, unlock, lock and enrol tell page-cache plugins DONOTCACHEPAGE, besides their no-store headers', true );
$out = (string) shell_exec( $cmd . escapeshellarg( base64_encode( json_encode( array( 'shop_app' => true, 'uri' => '/index.php?fh_shop_app=manifest' ) ) ) ) . ' 2>&1' );
check( '… /index.php works the same', 0 === strpos( $out, '{"id":"https:\/\/shop.example.test\/?fh_shop_app=1"' ) || 0 === strpos( $out, '{"id":"https://shop.example.test/?fh_shop_app=1"' ), substr( $out, 0, 80 ) );

echo "\n7. The picker's buildings and tanks\n";
$grid = array(
	array(
		'area'      => 'clean',
		'label'     => 'Clean Room',
		'buildings' => array(
			array(
				'id'     => 1,
				'number' => 100,
				'label'  => 'Building 100',
				'status' => 'active',
				'inQt'   => 2,
				'tanks'  => array(
					array( 'id' => 9, 'number' => 122, 'level' => 2, 'sizeLabel' => '20G', 'empty' => true, 'count' => 0, 'current' => false, 'url' => 'https://shop.example.test/?fh_front_desk=1&tank=122' ),
					array( 'id' => 7, 'number' => 111, 'level' => 1, 'sizeLabel' => '40G breeder', 'empty' => false, 'count' => 3, 'current' => false, 'url' => 'x' ),
					'not a tank',
					array( 'number' => 0 ),
				),
			),
			array( 'label' => 'Building 400', 'status' => 'coming_soon', 'inQt' => 0, 'tanks' => array() ),
		),
	),
	'not an area',
);
$home = App::home_from_grid( $grid );
check( 'areas, then buildings, then tanks — what the Front Desk\'s own picker shows', 1 === count( $home ) && 'Clean Room' === $home[0]['label'] && 2 === count( $home[0]['buildings'] ) && 'Building 100' === $home[0]['buildings'][0]['label'] && 2 === $home[0]['buildings'][0]['inQt'] && 'coming_soon' === $home[0]['buildings'][1]['status'] );
check( 'the tanks in number order, with just what a tile shows', array( array( 'number' => 111, 'size' => '40G breeder', 'empty' => false, 'count' => 3 ), array( 'number' => 122, 'size' => '20G', 'empty' => true, 'count' => 0 ) ) === $home[0]['buildings'][0]['tanks'] );
check( 'nothing of the Front Desk\'s addresses or ids is passed on: the app builds a tank\'s address itself', false === strpos( json_encode( $home ), 'url' ) && false === strpos( json_encode( $home ), '"id"' ) );
check( 'an empty layout is an empty list', array() === App::home_from_grid( array() ) );

echo "\n8. Buttons other sections add to the home screen\n";
function fh_links( $links ) {
	$links[] = array( 'label' => 'Packing <b>table</b>', 'url' => 'https://shop.example.test/?fh_packing=1' );
	$links[] = array( 'label' => 'Elsewhere', 'url' => 'https://elsewhere.example/?fh_packing=1' );
	$links[] = array( 'label' => 'Script', 'url' => 'javascript:alert(1)' );
	$links[] = array( 'label' => '', 'url' => 'https://shop.example.test/?x=1' );
	$links[] = array( 'label' => '<b></b>', 'url' => 'https://shop.example.test/?y=1' );
	$links[] = 'nonsense';
	return $links;
}
fh_boot_hooks(
	static function () {
		add_filter( 'fishotel_shop_app_home_links', 'fh_links' );
	}
);
fh_request();
check( 'a link of this site is kept, its label as plain text; another site, a script address, an empty label and nonsense are dropped', array( array( 'label' => 'Packing table', 'url' => 'https://shop.example.test/?fh_packing=1' ) ) === App::home_links() );
fh_request( array( 'uri' => '/?fh_shop_app=state', 'cookies' => $jar ) );
check( '… and it is only ever sent to an unlocked device', ! isset( json_decode( App::respond( 'state', 'GET' )['body'], true )['links'] ) );

echo "\n9. Every string the script asks for exists\n";
$script = (string) file_get_contents( $dir . 'js/app.js' );
preg_match_all( "/\\bt\\('([A-Za-z]+)'/", $script, $m );
$asked   = array_values( array_unique( $m[1] ) );
$strings = App::i18n();
$missing = array_values( array_diff( $asked, array_keys( $strings ) ) );
check( 'app.js asks for ' . count( $asked ) . ' strings and the server has each of them', count( $asked ) > 30 && array() === $missing, implode( ', ', $missing ) );
$unused = array_values( array_diff( array_keys( $strings ), $asked ) );
check( '… and sends none the script never uses', array() === $unused, implode( ', ', $unused ) );
check( 'none of them is empty', array() === array_filter( $strings, static function ( $s ) { return '' === trim( (string) $s ); } ) );

echo "\n10. HTTPS\n";
$GLOBALS['fh_is_ssl']          = false;
$GLOBALS['fh_options']['home'] = 'http://shop.example.test';
fh_request( array( 'uri' => '/?fh_shop_app=state' ) );
check( 'on a site without HTTPS the state says so (and the app shows "needs HTTPS")', false === json_decode( App::respond( 'state', 'GET' )['body'], true )['https'] && false === Shop_App::https_ok() );
$GLOBALS['fh_is_ssl']          = true;
$GLOBALS['fh_options']['home'] = 'https://shop.example.test';

fh_finish();
