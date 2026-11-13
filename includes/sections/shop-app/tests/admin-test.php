<?php
/**
 * Admin tests — FisHotel Tools → Shop App: who may open it and write
 * (manage_woocommerce and a nonce on every handler), adding and changing
 * people (the shared account made on first need, accounts a user may not
 * speak for, switching off), the one-time link's answer (with a QR code
 * from the Clean Room's encoder when it is loaded, and plain words when
 * it is not), who a device says is unlocked, the settings handler, and
 * every tab rendered with hostile names — nothing printed unescaped, no
 * PIN and no token anywhere.
 *
 * Run from the plugin root:
 *   php includes/sections/shop-app/tests/admin-test.php
 *
 * @package FisHotel\Misc\Sections\Shop_App
 */

require_once __DIR__ . '/wp-stubs.php';

use FisHotel\Misc\Sections\Shop_App\Access;
use FisHotel\Misc\Sections\Shop_App\Admin;
use FisHotel\Misc\Sections\Shop_App\People;
use FisHotel\Misc\Sections\Shop_App\Pins;
use FisHotel\Misc\Sections\Shop_App\Settings;
use FisHotel\Misc\Sections\Shop_App\Shop_App;
use FisHotel\Misc\Sections\Shop_App\Store;

$store = fh_fresh_store();
fh_boot_section( true );
$GLOBALS['wpdb']->tables = array( 'wp_fh_shopapp_devices', 'wp_fh_shopapp_people', 'wp_fh_shopapp_log', 'wp_fh_shopapp_saves' );

$owner    = fh_add_user( 'owner', 'administrator', 'Olive Owner' );
$manager  = fh_add_user( 'manager', 'shop_manager', 'Morgan Manager' );
$second   = fh_add_user( 'second', 'shop_manager', 'Sasha Second' );
$customer = fh_add_user( 'customer', 'customer', 'Casey Customer' );

/**
 * A pretend wp-admin request by a logged-in user.
 */
function fh_admin_as( $user_id, array $post = array(), $uri = '/wp-admin/admin-post.php' ) {
	fh_request( array( 'method' => $post ? 'POST' : 'GET', 'uri' => $uri, 'admin' => true, 'post' => $post, 'cookies' => $user_id ? array( 'wordpress_logged_in_test' => (string) $user_id ) : array() ) );
}

echo "1. Who may write\n";
fh_admin_as( $owner );
check( 'an administrator may', Admin::may_write() );
fh_admin_as( $manager );
check( 'a shop manager may', Admin::may_write() );
fh_admin_as( $customer );
check( 'a customer may not', ! Admin::may_write() );
fh_admin_as( 0 );
check( 'a visitor may not', ! Admin::may_write() );

$handlers = array(
	'handle_enrol_here'    => array( 'name' => 'Evil device' ),
	'handle_revoke'        => array( 'device' => '1' ),
	'handle_clear_lockout' => array( 'device' => '1' ),
	'handle_person'        => array( 'person' => '0', 'name' => 'Evil', 'pin' => '1111' ),
	'handle_person_off'    => array( 'person' => '1' ),
	'handle_settings'      => array( 'session_minutes' => '720' ),
);
$admin = new Admin();
foreach ( $handlers as $handler => $post ) {
	fh_admin_as( $customer, $post );
	$_POST['_wpnonce']    = wp_create_nonce( Admin::NONCE );
	$_REQUEST['_wpnonce'] = $_POST['_wpnonce'];
	$run                  = fh_run( array( $admin, $handler ) );
	if ( ! $run['halt'] || 'die' !== $run['halt']->kind || 403 !== (int) $run['halt']->status ) {
		check( $handler . ': a customer with a valid nonce is refused (403)', false );
	}
	fh_admin_as( $owner, $post );
	$run = fh_run( array( $admin, $handler ) );
	if ( ! $run['halt'] || 'die' !== $run['halt']->kind || 403 !== (int) $run['halt']->status ) {
		check( $handler . ': an administrator without the nonce is refused (403)', false );
	}
	fh_admin_as( $owner, array_merge( $post, array( '_wpnonce' => 'made-up' ) ) );
	$run = fh_run( array( $admin, $handler ) );
	if ( ! $run['halt'] || 'die' !== $run['halt']->kind || 403 !== (int) $run['halt']->status ) {
		check( $handler . ': a made-up nonce is refused (403)', false );
	}
}
check( 'all six admin-post handlers refuse a user without the capability, a missing nonce and a made-up nonce', true );
check( '… and nothing was written by any of those eighteen attempts', array() === $store->devices && array() === $store->people && array() === $store->log && ! isset( $GLOBALS['fh_options'][ Settings::OPTION ] ) );

fh_admin_as( $customer, array( 'name' => 'x' ), '/wp-admin/admin-ajax.php' );
$_POST['nonce']    = wp_create_nonce( Admin::NONCE );
$_REQUEST['nonce'] = $_POST['nonce'];
$run               = fh_run( array( $admin, 'ajax_link' ) );
check( 'the link AJAX refuses a customer (403)', $run['halt'] && 403 === (int) $run['halt']->status && array() === $store->devices );
fh_admin_as( $owner, array( 'name' => 'x', 'nonce' => 'made-up' ), '/wp-admin/admin-ajax.php' );
$run = fh_run( array( $admin, 'ajax_link' ) );
check( '… and a made-up nonce (403)', $run['halt'] && 'die' === $run['halt']->kind && 403 === (int) $run['halt']->status && array() === $store->devices );
fh_admin_as( $customer, array(), '/wp-admin/admin.php?page=fishotel-misc-shop-app' );
$run = fh_run( array( $admin, 'render_page' ) );
check( 'the page itself does not render for a customer', $run['halt'] && 'die' === $run['halt']->kind && false === strpos( $run['output'], 'Set up this device' ) );

echo "\n2. People\n";
fh_admin_as( $owner );
$r = Admin::person_request( array( 'person' => '0', 'name' => 'Sam Shore', 'pin' => '4821', 'user_id' => '0' ) );
check( 'a person is added', $r['ok'] && $r['id'] > 0 && 1 === count( $store->people ) );
$sam = $r['id'];
$row = $store->people[ $sam ];
check( 'stored: the name, a hash, a salt, the length, on — and no PIN', 'Sam Shore' === $row['name'] && 64 === strlen( $row['pin_hash'] ) && 32 === strlen( $row['pin_salt'] ) && 4 === $row['pin_length'] && 1 === $row['active'] && $owner === $row['created_by'] && false === strpos( json_encode( $store->people ), '4821' ) );
$staff = (int) $GLOBALS['fh_options'][ People::STAFF_OPTION ];
check( 'the shared "Shop staff" account is made the moment somebody needs it', $staff > 0 && 'fishotel-shop-staff' === $GLOBALS['fh_users'][ $staff ]['login'] && array( 'fishotel_shop_staff' ) === $GLOBALS['fh_users'][ $staff ]['roles'] && false === $GLOBALS['fh_autoload'][ People::STAFF_OPTION ] );
$last = end( $store->log );
check( 'the log: person added, by whom — and not the PIN', 'person_added' === $last['event'] && 'Sam Shore' === $last['person_name'] && $owner === $last['user_id'] && false === strpos( json_encode( $store->log ), '4821' ) );

$r = Admin::person_request( array( 'person' => '0', 'name' => 'Robin Reef', 'pin' => '4821', 'user_id' => '0' ) );
check( 'a second person with the same PIN: "Choose a different PIN." and nobody added', ! $r['ok'] && array( 'Choose a different PIN.' ) === $r['errors'] && 1 === count( $store->people ) );
check( '… the typed name comes back for the form, the PIN does not', array( 'name' => 'Robin Reef', 'user_id' => 0 ) === $r['input'] );
$r = Admin::person_request( array( 'person' => '0', 'name' => 'Robin Reef', 'pin' => '1379', 'user_id' => (string) $manager ) );
check( 'with another PIN, tied to the manager\'s own account', $r['ok'] && $manager === $store->people[ $r['id'] ]['user_id'] );
$robin = $r['id'];

$r = Admin::person_request( array( 'person' => (string) $sam, 'name' => 'Sam S.', 'pin' => '', 'user_id' => '0' ) );
check( 'a name is changed; the PIN, left empty, stays', $r['ok'] && 'Sam S.' === $store->people[ $sam ]['name'] && $row['pin_hash'] === $store->people[ $sam ]['pin_hash'] );
$last = end( $store->log );
check( '… logged as "person changed", with what the name was', 'person_changed' === $last['event'] && false !== strpos( $last['note'], 'Sam Shore' ) && false === strpos( $last['note'], 'PIN' ) );
$r = Admin::person_request( array( 'person' => (string) $sam, 'name' => 'Sam S.', 'pin' => '9050', 'user_id' => '0' ) );
check( 'a new PIN replaces the old one (new salt, new hash)', $r['ok'] && $row['pin_hash'] !== $store->people[ $sam ]['pin_hash'] && $row['pin_salt'] !== $store->people[ $sam ]['pin_salt'] && Pins::matches( '9050', $store->people[ $sam ], Settings::secret() ) && ! Pins::matches( '4821', $store->people[ $sam ], Settings::secret() ) );
$last = end( $store->log );
check( '… logged as "PIN changed" — without the PIN', 'person_changed' === $last['event'] && 'PIN changed' === $last['note'] );
$r = Admin::person_request( array( 'person' => '999', 'name' => 'Ghost', 'pin' => '2222' ) );
check( 'changing a person who does not exist is refused', ! $r['ok'] && 2 === count( $store->people ) );

check( 'switching a person off destroys their PIN', Admin::person_off_request( $robin ) && 0 === $store->people[ $robin ]['active'] && '' === $store->people[ $robin ]['pin_hash'] && '' === $store->people[ $robin ]['pin_salt'] );
$last = end( $store->log );
check( '… and is logged', 'person_changed' === $last['event'] && false !== strpos( $last['note'], 'switched off' ) );
check( 'switching off twice does nothing', ! Admin::person_off_request( $robin ) && ! Admin::person_off_request( 999 ) );
$r = Admin::person_request( array( 'person' => (string) $robin, 'name' => 'Robin Reef', 'pin' => '', 'user_id' => (string) $manager ) );
check( 'switching back on without a PIN is refused', ! $r['ok'] && 0 === $store->people[ $robin ]['active'] );
$r = Admin::person_request( array( 'person' => (string) $robin, 'name' => 'Robin Reef', 'pin' => '1379', 'user_id' => (string) $manager ) );
check( 'with a new PIN the person is on again', $r['ok'] && 1 === $store->people[ $robin ]['active'] && false !== strpos( end( $store->log )['note'], 'switched on' ) );

echo "\n3. Whose account a person may be given\n";
fh_admin_as( $owner );
$choices = People::account_choices();
check( 'an administrator is offered the staff accounts: administrators and shop managers', array( $owner, $manager, $second ) === array_keys( $choices ) && false !== strpos( $choices[ $manager ], 'Morgan Manager' ) );
check( '… never a customer\'s, never the shared account', ! isset( $choices[ $customer ] ) && ! isset( $choices[ $staff ] ) );
fh_admin_as( $manager );
$choices = People::account_choices();
check( 'a shop manager is offered only their own (they cannot speak for the owner or a colleague)', array( $manager ) === array_keys( $choices ) );
$r = Admin::person_request( array( 'person' => '0', 'name' => 'Pat Pier', 'pin' => '7007', 'user_id' => (string) $owner ) );
check( '… and a crafted form naming the owner\'s account is refused', ! $r['ok'] && false !== strpos( implode( ' ', $r['errors'] ), 'cannot be chosen' ) );
$r = Admin::person_request( array( 'person' => '0', 'name' => 'Pat Pier', 'pin' => '7007', 'user_id' => (string) $manager ) );
check( 'their own account is already Robin\'s: refused too', ! $r['ok'] && false !== strpos( implode( ' ', $r['errors'] ), 'already belongs' ) );
$r = Admin::person_request( array( 'person' => '0', 'name' => 'Pat Pier', 'pin' => '7007', 'user_id' => '424242' ) );
check( 'an account that does not exist is refused', ! $r['ok'] );
$r = Admin::person_request( array( 'person' => (string) $robin, 'name' => 'Robin R.', 'pin' => '', 'user_id' => (string) $manager ) );
check( 'a shop manager may still edit a person who keeps the account they have', $r['ok'] && 'Robin R.' === $store->people[ $robin ]['name'] );
check( 'the People list names the account', 'Morgan Manager' === People::account_label( $store->people[ $robin ] )['label'] && true === People::account_label( $store->people[ $sam ] )['shared'] );

echo "\n4. The one-time link's answer\n";
fh_admin_as( $owner );
$r = Admin::link_request( array( 'name' => 'Back room <i>tablet</i>' ) );
check( 'a link: the address, the name (tags dropped), ten minutes', $r['ok'] && 200 === $r['status'] && 1 === preg_match( '~^https://shop\.example\.test/\?fh_shop_app=enrol&k=[a-f0-9]{32}$~', $r['data']['url'] ) && 'Back room tablet' === $r['data']['name'] && 600 === $r['data']['seconds'] );
$svg = (string) base64_decode( substr( $r['data']['qr'], strlen( 'data:image/svg+xml;base64,' ) ) );
check( 'with a QR code drawn by the Clean Room\'s encoder, as an image', 0 === strpos( $r['data']['qr'], 'data:image/svg+xml;base64,' ) && 0 === strpos( $svg, '<svg ' ) && '' === $r['data']['qrNote'] );
check( 'an address too long for that encoder gets no code and a plain note instead', '' === Admin::qr_data_uri( 'https://shop.example.test/?' . str_repeat( 'x', 200 ) ) );
$GLOBALS['fh_is_ssl']          = false;
$GLOBALS['fh_options']['home'] = 'http://shop.example.test';
$count                         = count( $store->devices );
$r                             = Admin::link_request( array( 'name' => 'Plain' ) );
check( 'without HTTPS no link is made', ! $r['ok'] && 400 === $r['status'] && false !== strpos( $r['data']['message'], 'HTTPS' ) && $count === count( $store->devices ) );
$GLOBALS['fh_is_ssl']          = true;
$GLOBALS['fh_options']['home'] = 'https://shop.example.test';
$store->fail['create_device']  = true;
$r                             = Admin::link_request( array( 'name' => 'Broken' ) );
check( 'when the database refuses, the answer says so', ! $r['ok'] && 500 === $r['status'] );
$store->fail = array();

echo "\n5. Devices: who is unlocked\n";
fh_request();
$tablet = Access::instance()->enrol_here( 'Shop tablet', $owner );
$jar    = fh_jar( array() );
fh_request( array( 'cookies' => $jar ) );
Access::instance()->unlock( '9050' );
$people   = array( $sam => $store->people[ $sam ], $robin => $store->people[ $robin ] );
$settings = Settings::get();
$now      = $GLOBALS['fh_now'];
$device   = $store->device( $tablet['id'] );
check( 'a device that was just unlocked says by whom and since when', array( 'name' => 'Sam S.', 'since' => gmdate( 'Y-m-d H:i:s', $now ) ) === Admin::unlocked_by( $device, $people, $settings, $now ) );
check( 'two hours and a few minutes after it was last heard from, nobody is shown — the session must have run out', null !== Admin::unlocked_by( $device, $people, $settings, $now + 7200 ) && null === Admin::unlocked_by( $device, $people, $settings, $now + 7200 + Access::TOUCH_SECONDS + 1 ) );
check( 'counted from the unlock: nobody is shown two hours after the PIN', null === Admin::unlocked_by( $device, $people, array_merge( $settings, array( 'session_mode' => 'unlock' ) ), $now + 7201 ) );
check( 'a locked, a waiting and a revoked device show nobody', null === Admin::unlocked_by( array_merge( $device, array( 'session_id' => '', 'session_person_id' => 0 ) ), $people, $settings, $now ) && null === Admin::unlocked_by( array_merge( $device, array( 'status' => Store::STATUS_REVOKED ) ), $people, $settings, $now ) );
check( 'dates are shown in the site\'s formats; none is a dash', '2026-11-12 09:30' === Admin::when( '2026-11-12 09:30:00' ) && '—' === Admin::when( null ) && '—' === Admin::when( '' ) );
check( 'a user\'s name, a number for one who is gone, a dash for nobody', 'Olive Owner' === Admin::user_name( $owner ) && '#4242' === Admin::user_name( 4242 ) && '—' === Admin::user_name( 0 ) );

echo "\n6. The handlers, done right\n";
fh_admin_as( $owner, array( 'session_minutes' => '45', 'session_mode' => 'unlock', 'pin_length' => '5' ) );
$_POST['_wpnonce']    = wp_create_nonce( Admin::NONCE );
$_REQUEST['_wpnonce'] = $_POST['_wpnonce'];
$run                  = fh_run( array( $admin, 'handle_settings' ) );
check( 'settings: saved, then back to the Settings tab with a notice', $run['halt'] && 'redirect' === $run['halt']->kind && false !== strpos( $run['halt']->data, 'tab=settings' ) && false !== strpos( $run['halt']->data, 'fh_notice=settings_saved' ) && array( 'session_minutes' => 45, 'session_mode' => 'unlock', 'pin_length' => 5 ) === $GLOBALS['fh_options'][ Settings::OPTION ] );
Settings::save( Settings::defaults() );

fh_admin_as( $manager, array( 'person' => '0', 'name' => 'Pat Pier', 'pin' => '9050', 'user_id' => '0' ) );
$_POST['_wpnonce']    = wp_create_nonce( Admin::NONCE );
$_REQUEST['_wpnonce'] = $_POST['_wpnonce'];
$run                  = fh_run( array( $admin, 'handle_person' ) );
$kept                 = get_transient( 'fishotel_shopapp_form_' . $manager );
check( 'a refused person: back to People with "not saved"', $run['halt'] && 'redirect' === $run['halt']->kind && false !== strpos( $run['halt']->data, 'fh_notice=person_failed' ) );
check( '… the reason and the typed name wait for the form; the PIN is not kept anywhere', is_array( $kept ) && array( 'Choose a different PIN.' ) === $kept['errors'] && 'Pat Pier' === $kept['input']['name'] && false === strpos( json_encode( $GLOBALS['fh_transients'] ), '9050' ) && false === strpos( $run['halt']->data, '9050' ) );

fh_admin_as( $owner, array( 'device' => (string) $tablet['id'] ) );
$_POST['_wpnonce']    = wp_create_nonce( Admin::NONCE );
$_REQUEST['_wpnonce'] = $_POST['_wpnonce'];
$run                  = fh_run( array( $admin, 'handle_revoke' ) );
check( 'revoke: done, then back to Devices with a notice', false !== strpos( $run['halt']->data, 'fh_notice=revoked' ) && Store::STATUS_REVOKED === $store->devices[ $tablet['id'] ]['status'] );
$notices = Admin::notices();
$named   = array();
preg_match_all( "/'(enrolled|enrol_failed|https|revoked|revoke_failed|lockout_cleared|lockout_failed|person_saved|person_off|person_failed|settings_saved)'/", (string) file_get_contents( dirname( __DIR__ ) . '/class-admin.php' ), $m );
check( 'every notice a handler can name has its text', array() === array_diff( array_unique( $m[1] ), array_keys( $notices ) ) && 11 === count( $notices ) );

echo "\n7. Every tab, with hostile names\n";
$evil = '<script>alert("x")</script>"\'><img src=x onerror=alert(1)>';
fh_admin_as( $owner );
// Straight into the store, past the form's cleaning: the views must hold on their own.
$store->insert_person( array( 'name' => $evil, 'pin_hash' => str_repeat( 'a', 64 ), 'pin_salt' => str_repeat( 'b', 32 ), 'pin_length' => 4, 'user_id' => 0, 'active' => 1 ) );
$bad_device = $store->create_device( array( 'name' => $evil, 'status' => Store::STATUS_ACTIVE, 'token_hash' => str_repeat( 'c', 64 ), 'enrolled_by' => $owner, 'enrolled_at' => gmdate( 'Y-m-d H:i:s', $GLOBALS['fh_now'] ) ) );
$store->update_device( $bad_device, array( 'locked_until' => gmdate( 'Y-m-d H:i:s', $GLOBALS['fh_now'] + 240 ), 'lock_level' => 1 ) );
$link = Access::instance()->create_link( $evil, $owner );
$store->append_log( array( 'event' => $evil, 'device_name' => $evil, 'person_name' => $evil, 'note' => $evil ) );
set_transient( 'fishotel_shopapp_form_' . $owner, array( 'id' => 0, 'errors' => array( $evil ), 'input' => array( 'name' => $evil, 'user_id' => 0 ) ), 60 );

$pages = array();
foreach ( Admin::TABS as $tab ) {
	fh_admin_as( $owner, array(), '/wp-admin/admin.php?page=fishotel-misc-shop-app&tab=' . $tab . '&fh_notice=person_failed' );
	$run           = fh_run( array( $admin, 'render_page' ) );
	$pages[ $tab ] = $run['output'];
	check( 'the ' . $tab . ' tab renders', null === $run['halt'] && false !== strpos( $run['output'], 'fh-sa-wrap' ) && false !== strpos( $run['output'], 'Shop App' ), $run['halt'] ? $run['halt']->kind . ': ' . $run['halt']->data : '' );
}
$all = implode( "\n", $pages );
check( 'nothing hostile is printed as markup on any tab', false === strpos( $all, '<script>alert' ) && false === strpos( $all, '<img src=x' ) && false === strpos( $all, 'onerror=alert(1)>' ) );
check( '… it is there, escaped', false !== strpos( $pages['devices'], '&lt;script&gt;alert' ) && false !== strpos( $pages['people'], '&lt;script&gt;alert' ) && false !== strpos( $pages['log'], '&lt;script&gt;alert' ) );
check( 'no PIN hash, no salt, no token hash and no link token on any tab', false === strpos( $all, str_repeat( 'a', 64 ) ) && false === strpos( $all, str_repeat( 'b', 32 ) ) && false === strpos( $all, str_repeat( 'c', 64 ) ) && false === strpos( $all, $link['token'] ) && false === strpos( $all, Settings::secret() ) );
check( 'Devices: set up this device, a one-time link, the list with the lockout, Clear lockout and Revoke', false !== strpos( $pages['devices'], 'Set up this device' ) && false !== strpos( $pages['devices'], 'Make a one-time link' ) && false !== strpos( $pages['devices'], 'Yes — 4 minutes more' ) && false !== strpos( $pages['devices'], 'fishotel_shop_app_clear_lockout' ) && false !== strpos( $pages['devices'], 'fishotel_shop_app_revoke' ) && false !== strpos( $pages['devices'], 'waiting for its link' ) && false !== strpos( $pages['devices'], 'Cancel the link' ) );
check( 'Devices: who enrolled each, and the revoked one shown as revoked, by whom', false !== strpos( $pages['devices'], 'Olive Owner' ) && false !== strpos( $pages['devices'], 'revoked ' ) );
check( 'People: the form (the PIN in plain digits — it is typed once, so it must be seen —, numeric, never pre-filled, not remembered by the browser), the accounts, the list with Switch off', false !== strpos( $pages['people'], '<input type="text" name="pin"' ) && false === strpos( $pages['people'], 'type="password"' ) && 1 === preg_match( '/<input type="text" name="pin"[^>]*inputmode="numeric"[^>]*autocomplete="off"/', $pages['people'] ) && false === strpos( $pages['people'], 'name="pin" value' ) && 0 === preg_match( '/name="pin"[^>]*\svalue=/', $pages['people'] ) && false !== strpos( $pages['people'], 'Shop staff (the shared account)' ) && false !== strpos( $pages['people'], 'fishotel_shop_app_person_off' ) && false !== strpos( $pages['people'], 'Set · 4 digits' ) );
check( 'People: what a refused save gave back is shown once', false !== strpos( $pages['people'], 'role="alert"' ) && false === get_transient( 'fishotel_shopapp_form_' . $owner ) );
check( 'Settings: the length, where the count starts, the digits', false !== strpos( $pages['settings'], 'name="session_minutes"' ) && false !== strpos( $pages['settings'], 'name="session_mode"' ) && false !== strpos( $pages['settings'], 'name="pin_length"' ) && false !== strpos( $pages['settings'], "value=\"120\" selected='selected'" ) );
check( 'Log: every kind of event has its label', false !== strpos( $pages['log'], 'Device enrolled' ) && false !== strpos( $pages['log'], 'Unlocked' ) && false !== strpos( $pages['log'], 'Person added' ) && false !== strpos( $pages['log'], 'Device revoked' ) && false !== strpos( $pages['log'], 'Enrolment link made' ) );
check( 'Install: the app\'s address and the steps for Android and for an iPhone', false !== strpos( $pages['install'], 'https://shop.example.test/?fh_shop_app=1' ) && false !== strpos( $pages['install'], 'Android (Chrome)' ) && false !== strpos( $pages['install'], 'iPhone and iPad (Safari)' ) && false !== strpos( $pages['install'], 'Add to Home Screen' ) && false !== strpos( $pages['install'], 'Install app' ) );
check( 'every form carries the nonce and goes to admin-post', substr_count( $pages['devices'] . $pages['people'] . $pages['settings'], 'name="_wpnonce"' ) >= 6 && false === strpos( $pages['devices'] . $pages['people'] . $pages['settings'], 'method="get"' ) );
check( 'the notice named in the address is shown; a made-up one is not', false !== strpos( $pages['devices'], 'Not saved — see below.' ) && ( function () use ( $admin, $owner ) {
	fh_admin_as( $owner, array(), '/wp-admin/admin.php?page=fishotel-misc-shop-app&fh_notice=%3Cscript%3E' );
	$run = fh_run( array( $admin, 'render_page' ) );
	return false === strpos( $run['output'], 'fishotel-misc-notice--success' ) && false === strpos( $run['output'], '<script>' );
} )() );

echo "\n8. What the page says when something is missing\n";
$GLOBALS['fh_is_ssl']          = false;
$GLOBALS['fh_options']['home'] = 'http://shop.example.test';
fh_admin_as( $owner, array(), '/wp-admin/admin.php?page=fishotel-misc-shop-app' );
$run = fh_run( array( $admin, 'render_page' ) );
check( 'without HTTPS: it says so, and offers neither way of setting a device up', false !== strpos( $run['output'], 'The Shop App needs HTTPS' ) && false === strpos( $run['output'], 'fishotel_shop_app_enrol_here' ) && false === strpos( $run['output'], 'fh-sa-link-form' ) );
$GLOBALS['fh_is_ssl']          = true;
$GLOBALS['fh_options']['home'] = 'https://shop.example.test';

$GLOBALS['fh_options']['fishotel_misc_enabled_sections'] = array( 'shop-app' );
fh_admin_as( $owner, array(), '/wp-admin/admin.php?page=fishotel-misc-shop-app' );
$run = fh_run( array( $admin, 'render_page' ) );
check( 'with the Clean Room switched off: it says there are no tank screens to open', false !== strpos( $run['output'], 'The Clean Room section is switched off' ) );
$GLOBALS['fh_options']['fishotel_misc_enabled_sections'] = array( 'shop-app', 'clean-room' );

$GLOBALS['wpdb']->tables = array();
fh_admin_as( $owner, array(), '/wp-admin/admin.php?page=fishotel-misc-shop-app' );
$run = fh_run( array( $admin, 'render_page' ) );
check( 'without its tables: it says to deactivate and reactivate', false !== strpos( $run['output'], 'The Shop App tables do not exist' ) );

echo "\nA new PIN (or another account) ends the session the old one started\n";
$store = fh_fresh_store();
fh_admin_as( $owner );
$sam   = Admin::person_request( array( 'person' => '0', 'name' => 'Sam Shore', 'pin' => '4821', 'user_id' => '0' ) )['id'];
$olive = Admin::person_request( array( 'person' => '0', 'name' => 'Olive Owner', 'pin' => '9042', 'user_id' => (string) $owner ) )['id'];
$tablet = Access::instance()->enrol_here( 'Shop tablet', $owner );
$jar_t  = fh_jar( array() );
fh_request();
$phone = Access::instance()->enrol_here( 'Phone', $owner );
$jar_p = fh_jar( array() );
$live  = static function ( array $jar ) {
	fh_request( array( 'cookies' => $jar ) );
	$session = Access::instance()->context()['session'];
	return $session ? $session['person']['name'] : '';
};
foreach ( array( 'jar_t' => '9042', 'jar_p' => '4821' ) as $which => $pin ) {
	fh_request( array( 'cookies' => $$which ) );
	Access::instance()->unlock( $pin );
	$$which = fh_jar( $$which );
}
check( 'Olive is unlocked on the tablet, Sam on the phone', 'Olive Owner' === $live( $jar_t ) && 'Sam Shore' === $live( $jar_p ) );

fh_admin_as( $owner );
$r = Admin::person_request( array( 'person' => (string) $olive, 'name' => 'Olive O.', 'pin' => '', 'user_id' => (string) $owner ) );
check( 'a new name alone ends nothing', $r['ok'] && 'Olive O.' === $live( $jar_t ) );

fh_admin_as( $owner );
$r = Admin::person_request( array( 'person' => (string) $olive, 'name' => 'Olive O.', 'pin' => '7777', 'user_id' => (string) $owner ) );
check( 'Olive\'s PIN is changed (somebody had learned it)', $r['ok'] );
check( '… the session the OLD PIN started is over on the device\'s very next request — it does not live on for as long as it is used', '' === $live( $jar_t ) && '' === $store->devices[ $tablet['id'] ]['session_id'] && 0 === $store->devices[ $tablet['id'] ]['session_person_id'] );
check( '… its cookie was taken back, and a kept copy of it is worth nothing', '' === fh_cookies_set()[ \FisHotel\Misc\Sections\Shop_App\Http::cookie_name( \FisHotel\Misc\Sections\Shop_App\Http::SESSION ) ] && '' === $live( $jar_t ) );
check( '… Sam\'s session on the phone is untouched', 'Sam Shore' === $live( $jar_p ) );
check( '… and the log says so, without a PIN', 'person_changed' === end( $store->log )['event'] && 'PIN changed, their session ended' === end( $store->log )['note'] && false === strpos( json_encode( $store->log ), '7777' ) );
fh_request( array( 'cookies' => $jar_t ) );
$old = Access::instance()->unlock( '9042' );
fh_request( array( 'cookies' => $jar_t ) );
$new   = Access::instance()->unlock( '7777' );
$jar_t = fh_jar( $jar_t );
check( 'the old PIN opens nothing; the new one starts a new session', 'wrong_pin' === $old['code'] && $new['ok'] && 'Olive O.' === $live( $jar_t ) );

fh_admin_as( $owner );
$r = Admin::person_request( array( 'person' => (string) $sam, 'name' => 'Sam Shore', 'pin' => '', 'user_id' => (string) $second ) );
check( 'Sam is tied to another account: the session recorded under the old one ends too', $r['ok'] && '' === $live( $jar_p ) && false !== strpos( end( $store->log )['note'], 'account changed, their session ended' ) );
fh_request( array( 'cookies' => $jar_p ) );
Access::instance()->unlock( '4821' );
$jar_p = fh_jar( $jar_p );
fh_admin_as( $owner );
check( 'switching a person off ends their session on the spot — the Devices list stops saying they are unlocked', 'Sam Shore' === $live( $jar_p ) && Admin::person_off_request( $sam ) && '' === $store->devices[ $phone['id'] ]['session_id'] && '' === $live( $jar_p ) );
fh_request( array( 'cookies' => $jar_t ) );
Access::instance()->lock();
fh_admin_as( $owner );
check( 'a person who is not unlocked anywhere: a new PIN ends nothing and says nothing of sessions', Admin::person_request( array( 'person' => (string) $olive, 'name' => 'Olive O.', 'pin' => '7778', 'user_id' => (string) $owner ) )['ok'] && 'PIN changed' === end( $store->log )['note'] );

fh_finish();
