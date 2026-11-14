<?php
/**
 * Saves tests — a Front Desk write that is sent twice is written once.
 *
 * Inside the app every save carries a save id (the same one on every
 * retry). These tests play the write action as admin-ajax.php runs it,
 * with a stand-in for the Front Desk's handler that counts how often it
 * really ran: once for an id, however often the id arrives — an hour, a
 * night, a weekend later too; again after a refused save; again when the
 * same id comes with another entry; "still saving" while the first is
 * running; never around the nonce; never across devices or accounts; and
 * not at all for a WordPress login, whose saves stay exactly as they
 * were.
 *
 * Run from the plugin root:
 *   php includes/sections/shop-app/tests/saves-test.php
 *
 * @package FisHotel\Misc\Sections\Shop_App
 */

require_once __DIR__ . '/wp-stubs.php';

use FisHotel\Misc\Sections\Clean_Room\Front_Desk;
use FisHotel\Misc\Sections\Shop_App\Access;
use FisHotel\Misc\Sections\Shop_App\People;
use FisHotel\Misc\Sections\Shop_App\Saves;

echo "1. What is worth remembering of a reply (pure)\n";
$reply = json_encode(
	array(
		'success' => true,
		'data'    => array(
			'message' => '1 × Invented Goby lost',
			'undo'    => array( 'eventIds' => array( '41', 42 ), 'label' => '1 × Invented Goby lost' ),
			'screen'  => array( 'tank' => array( 'number' => 521, 'occupants' => array( array( 'species' => 'Invented Goby' ) ) ), 'grid' => array( 'much' ) ),
		),
	)
);
$result = Saves::result_of( 200, $reply );
check( 'a success: the toast, the undo ids and the tank — nothing else', array( 'message' => '1 × Invented Goby lost', 'undo' => array( 'eventIds' => array( 41, 42 ), 'label' => '1 × Invented Goby lost' ), 'tank' => 521 ) === $result, $result );
check( 'no status set at all counts as 200 (PHP\'s default)', null !== Saves::result_of( false, $reply ) && null !== Saves::result_of( null, $reply ) );
check( 'the Front Desk\'s "no" (400) is not remembered', null === Saves::result_of( 400, json_encode( array( 'success' => false, 'data' => array( 'message' => 'How many? 1–5.' ) ) ) ) );
check( 'a "no" sent with 200 is not remembered either', null === Saves::result_of( 200, json_encode( array( 'success' => false, 'data' => array() ) ) ) );
check( 'a success that arrived with an error status is not trusted', null === Saves::result_of( 500, $reply ) && null === Saves::result_of( 403, $reply ) );
check( 'what is not JSON, or not that shape, is not remembered', null === Saves::result_of( 200, '0' ) && null === Saves::result_of( 200, '-1' ) && null === Saves::result_of( 200, '' ) && null === Saves::result_of( 200, '<html>' ) && null === Saves::result_of( 200, '{"success":"yes"}' ) && null === Saves::result_of( 200, '[true]' ) );
check( 'a success without a tank or undo is still a success', array( 'message' => '', 'undo' => array( 'eventIds' => array(), 'label' => '' ), 'tank' => 0 ) === Saves::result_of( 200, '{"success":true,"data":null}' ) );

check( 'a save id is 16–64 hex characters', Saves::is_id( str_repeat( 'a1', 16 ) ) && Saves::is_id( str_repeat( 'f', 16 ) ) && ! Saves::is_id( str_repeat( 'f', 15 ) ) && ! Saves::is_id( str_repeat( 'f', 65 ) ) && ! Saves::is_id( str_repeat( 'G', 32 ) ) && ! Saves::is_id( str_repeat( 'a', 32 ) . "\n" ) && ! Saves::is_id( array( 'a' ) ) && ! Saves::is_id( '' ) );
$id = str_repeat( 'a1', 16 );
check( 'the key under which it is remembered belongs to the device and the account', 64 === strlen( Saves::key( 3, 9, $id ) ) && Saves::key( 3, 9, $id ) !== Saves::key( 4, 9, $id ) && Saves::key( 3, 9, $id ) !== Saves::key( 3, 8, $id ) && Saves::key( 3, 9, $id ) !== Saves::key( 3, 9, str_repeat( 'b2', 16 ) ) && false === strpos( Saves::key( 3, 9, $id ), $id ) );
$entry = array( 'action' => 'fishotel_cleanroom_act', 'act' => 'note', 'tank' => '521', 'note' => 'Fed twice' );
check( '… and to what was sent: the same id with another entry is another key', Saves::key( 3, 9, $id, Saves::content_hash( $entry ) ) !== Saves::key( 3, 9, $id, Saves::content_hash( array_merge( $entry, array( 'note' => 'Fed once' ) ) ) ) && Saves::key( 3, 9, $id, Saves::content_hash( $entry ) ) !== Saves::key( 3, 9, $id, Saves::content_hash( array_merge( $entry, array( 'tank' => '522' ) ) ) ) );
check( '… the save id itself and the nonce are not part of "what was sent" (a retry from a page open since yesterday is the same entry)', Saves::content_hash( $entry ) === Saves::content_hash( array_merge( $entry, array( 'fh_save_id' => $id, 'nonce' => 'abc123' ) ) ) && 64 === strlen( Saves::content_hash( array() ) ) );
check( 'a save is remembered for a week — longer than a sheet is left open', 604800 === Saves::KEEP_SECONDS && Saves::KEEP_SECONDS > 12 * 3600 );

echo "\n2. On a tablet with a live shop session\n";
$store = fh_fresh_store();
fh_boot_section( true );

// The Front Desk's write handler, standing in: the same two checks, then
// it "writes" (counts) and answers as the Front Desk does.
$GLOBALS['fh_writes']  = 0;
$GLOBALS['fh_refuse']  = false;
$GLOBALS['fh_handler'] = static function () {
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		wp_send_json_error( array( 'message' => 'Unauthorized.' ), 403 );
	}
	check_ajax_referer( Front_Desk::NONCE, 'nonce' );
	if ( $GLOBALS['fh_refuse'] ) {
		wp_send_json_error( array( 'message' => 'How many? 1–5.' ), 400 );
	}
	++$GLOBALS['fh_writes'];
	wp_send_json_success(
		array(
			'message' => 'Write number ' . $GLOBALS['fh_writes'],
			'undo'    => array( 'eventIds' => array( 100 + $GLOBALS['fh_writes'] ), 'label' => 'Write number ' . $GLOBALS['fh_writes'] ),
			'screen'  => array( 'tank' => array( 'number' => 521 ) ),
		)
	);
};
fh_boot_hooks(
	static function () {
		$GLOBALS['fh_hooks']['wp_ajax_fishotel_cleanroom_act'][10] = array();
		add_action( 'wp_ajax_fishotel_cleanroom_act', $GLOBALS['fh_handler'], 10 );
	}
);

$owner   = fh_add_user( 'owner', 'administrator', 'Olive Owner' );
$manager = fh_add_user( 'manager', 'shop_manager', 'Morgan Manager' );
fh_request();
fh_add_person( 'Sam', '4821' );
fh_add_person( 'Robin', '1379', $manager );
$tablet = Access::instance()->enrol_here( 'Shop tablet', $owner );
$jar    = fh_jar( array() );
fh_request( array( 'cookies' => $jar ) );
Access::instance()->unlock( '4821' );
$jar = fh_jar( $jar );

/**
 * The key a save of fh_save() is remembered under.
 */
function fh_key( $device_id, $user_id, $id, array $post = array() ) {
	return Saves::key( $device_id, $user_id, $id, Saves::content_hash( array_merge( array( 'action' => 'fishotel_cleanroom_act', 'act' => 'loss', 'tank' => '521' ), $post ) ) );
}

/**
 * The nonce the Front Desk page would carry for this jar.
 */
function fh_page_nonce( array $jar ) {
	fh_request( array( 'uri' => '/?fh_front_desk=1&tank=521', 'cookies' => $jar ) );
	do_action( 'init' );
	return wp_create_nonce( Front_Desk::NONCE );
}

/**
 * Play the write as admin-ajax.php runs it, to the end of the request.
 */
function fh_save( array $jar, array $post ) {
	fh_request( array( 'method' => 'POST', 'uri' => '/wp-admin/admin-ajax.php', 'admin' => true, 'ajax' => true, 'post' => array_merge( array( 'action' => 'fishotel_cleanroom_act', 'act' => 'loss', 'tank' => '521' ), $post ), 'cookies' => $jar ) );
	$level = ob_get_level();
	ob_start(); // Stands for the response: everything the request prints.
	$halt = null;
	try {
		do_action( 'init' );
		do_action( 'admin_init' );
		$hook = ( is_user_logged_in() ? 'wp_ajax_' : 'wp_ajax_nopriv_' ) . 'fishotel_cleanroom_act';
		if ( has_action( $hook ) ) {
			do_action( $hook );
		}
		$GLOBALS['fh_sent']['status'] = 400;
		echo '0';
	} catch ( FH_Halt $e ) {
		$halt = $e;
	}
	do_action( 'shutdown' ); // WordPress flushes the buffers here; Saves looks at the reply just before.
	$body = '';
	while ( ob_get_level() > $level ) {
		$body = ob_get_clean() . $body;
	}
	return array(
		'status' => $halt && 'die' === $halt->kind ? (int) $halt->status : (int) $GLOBALS['fh_sent']['status'],
		'json'   => json_decode( $body, true ),
		'body'   => $body,
		'halt'   => $halt,
	);
}

$nonce = fh_page_nonce( $jar );
$id    = str_repeat( 'c3', 16 );

$first = fh_save( $jar, array( 'nonce' => $nonce, 'fh_save_id' => $id ) );
check( 'the first time the Front Desk\'s handler runs and its answer goes out unchanged', 1 === $GLOBALS['fh_writes'] && 200 === $first['status'] && true === $first['json']['success'] && 'Write number 1' === $first['json']['data']['message'] && ! isset( $first['json']['data']['replayed'] ), $first['body'] );
$key = fh_key( $tablet['id'], People::staff_user_id( false ), $id );
check( 'the save is remembered: finished, with the toast, the undo ids and the tank', isset( $store->saves[ $key ] ) && 1 === $store->saves[ $key ]['status'] && array( 'message' => 'Write number 1', 'undo' => array( 'eventIds' => array( 101 ), 'label' => 'Write number 1' ), 'tank' => 521 ) === json_decode( $store->saves[ $key ]['result'], true ) );
check( '… under a hash — the id itself is not stored', false === strpos( json_encode( $store->saves ), $id ) );

$again = fh_save( $jar, array( 'nonce' => $nonce, 'fh_save_id' => $id ) );
check( 'the same id again: the handler does NOT run', 1 === $GLOBALS['fh_writes'] );
check( '… and the first answer is repeated: the same toast and undo ids, marked as a repeat', 200 === $again['status'] && true === $again['json']['success'] && 'Write number 1' === $again['json']['data']['message'] && array( 101 ) === $again['json']['data']['undo']['eventIds'] && true === $again['json']['data']['replayed'] );
check( '… with the tank screen read afresh by the Front Desk\'s own code', isset( $again['json']['data']['screen'] ) && array_key_exists( 'tablesOk', $again['json']['data']['screen'] ) );
fh_save( $jar, array( 'nonce' => $nonce, 'fh_save_id' => $id ) );
fh_save( $jar, array( 'nonce' => $nonce, 'fh_save_id' => $id ) );
check( 'five times, ten times: still written once', 1 === $GLOBALS['fh_writes'] );

fh_save( $jar, array( 'nonce' => $nonce, 'fh_save_id' => str_repeat( 'd4', 16 ) ) );
check( 'another id is another save', 2 === $GLOBALS['fh_writes'] );
fh_save( $jar, array( 'nonce' => $nonce ) );
fh_save( $jar, array( 'nonce' => $nonce ) );
check( 'a save without an id runs every time, as it always did', 4 === $GLOBALS['fh_writes'] );
fh_save( $jar, array( 'nonce' => $nonce, 'fh_save_id' => 'not-an-id' ) );
fh_save( $jar, array( 'nonce' => $nonce, 'fh_save_id' => 'not-an-id' ) );
check( 'something that is not an id is treated as none', 6 === $GLOBALS['fh_writes'] && 2 === count( $store->saves ) );

echo "\n3. A refused save can be sent again\n";
$GLOBALS['fh_refuse'] = true;
$id2                  = str_repeat( 'e5', 16 );
$no                   = fh_save( $jar, array( 'nonce' => $nonce, 'fh_save_id' => $id2 ) );
check( 'the Front Desk says no (400, its own message)', 400 === $no['status'] && false === $no['json']['success'] && 'How many? 1–5.' === $no['json']['data']['message'] );
check( '… and nothing is remembered of it', ! isset( $store->saves[ fh_key( $tablet['id'], People::staff_user_id( false ), $id2 ) ] ) && 2 === count( $store->saves ) );
$GLOBALS['fh_refuse'] = false;
$yes                  = fh_save( $jar, array( 'nonce' => $nonce, 'fh_save_id' => $id2 ) );
check( 'the same id again, now acceptable: the handler runs', 7 === $GLOBALS['fh_writes'] && true === $yes['json']['success'] && ! isset( $yes['json']['data']['replayed'] ) );

echo "\n4. While the first is still running\n";
$id3  = str_repeat( 'f6', 16 );
$key3 = fh_key( $tablet['id'], People::staff_user_id( false ), $id3 );
$store->claim_save( $key3, $tablet['id'], $GLOBALS['fh_now'], Saves::PENDING_SECONDS ); // A request that has claimed the id and not finished.
$wait = fh_save( $jar, array( 'nonce' => $nonce, 'fh_save_id' => $id3 ) );
check( 'a second request with the id is told "still saving" (409) and writes nothing', 409 === $wait['status'] && false === $wait['json']['success'] && true === $wait['json']['data']['pending'] && 7 === $GLOBALS['fh_writes'] );
$GLOBALS['fh_now'] += Saves::PENDING_SECONDS;
check( 'thirty seconds on it still waits', 409 === fh_save( $jar, array( 'nonce' => $nonce, 'fh_save_id' => $id3 ) )['status'] );
$GLOBALS['fh_now'] += 1;
$took               = fh_save( $jar, array( 'nonce' => $nonce, 'fh_save_id' => $id3 ) );
check( 'after that the first request is taken for dead and the save runs', 200 === $took['status'] && 8 === $GLOBALS['fh_writes'] && 1 === $store->saves[ $key3 ]['status'] );

echo "\n4b. An id names one entry\n";
$id4    = str_repeat( '9a', 16 );
$writes = $GLOBALS['fh_writes'];
$one    = fh_save( $jar, array( 'nonce' => $nonce, 'fh_save_id' => $id4, 'note' => 'Fed twice' ) );
$two    = fh_save( $jar, array( 'nonce' => $nonce, 'fh_save_id' => $id4, 'note' => 'Fed ONCE' ) );
check( 'the same id with ANOTHER entry is not answered "saved" from the memory of the first: it is written', $writes + 2 === $GLOBALS['fh_writes'] && true === $two['json']['success'] && ! isset( $two['json']['data']['replayed'] ) && $one['json']['data']['message'] !== $two['json']['data']['message'] );
$three = fh_save( $jar, array( 'nonce' => $nonce, 'fh_save_id' => $id4, 'note' => 'Fed twice' ) );
$four  = fh_save( $jar, array( 'nonce' => $nonce, 'fh_save_id' => $id4, 'note' => 'Fed ONCE' ) );
check( '… and each of the two is still written once when it is sent again', $writes + 2 === $GLOBALS['fh_writes'] && true === $three['json']['data']['replayed'] && $one['json']['data']['message'] === $three['json']['data']['message'] && true === $four['json']['data']['replayed'] && $two['json']['data']['message'] === $four['json']['data']['message'] );

echo "\n5. No way around the Front Desk's own checks\n";
$bad = fh_save( $jar, array( 'nonce' => 'not-the-nonce', 'fh_save_id' => $id ) );
check( 'a remembered id with a wrong nonce is not answered from memory: the Front Desk refuses it (403)', 403 === $bad['status'] && $bad['halt'] && 'die' === $bad['halt']->kind && 10 === $GLOBALS['fh_writes'] );
$none = fh_save( array(), array( 'nonce' => $nonce, 'fh_save_id' => $id ) );
check( 'a remembered id without the session: "0", not logged in', 400 === $none['status'] && '0' === $none['body'] );

fh_request();
$phone   = Access::instance()->enrol_here( 'Phone', $owner );
$jar_two = fh_jar( array() );
fh_request( array( 'cookies' => $jar_two ) );
Access::instance()->unlock( '4821' );
$jar_two   = fh_jar( $jar_two );
$nonce_two = fh_page_nonce( $jar_two );
$other     = fh_save( $jar_two, array( 'nonce' => $nonce_two, 'fh_save_id' => $id ) );
check( 'the same id from another device is another save (an id means nothing outside its device)', 11 === $GLOBALS['fh_writes'] && ! isset( $other['json']['data']['replayed'] ) );

fh_request( array( 'cookies' => $jar ) );
Access::instance()->lock();
$jar = fh_jar( $jar );
fh_request( array( 'cookies' => $jar ) );
Access::instance()->unlock( '1379' );
$jar         = fh_jar( $jar );
$robin_nonce = fh_page_nonce( $jar );
$robin       = fh_save( $jar, array( 'nonce' => $robin_nonce, 'fh_save_id' => $id ) );
check( 'the same id on the same device under another account is another save', 12 === $GLOBALS['fh_writes'] && ! isset( $robin['json']['data']['replayed'] ) );

echo "\n6. A WordPress login is left exactly as it was\n";
$native       = array( 'wordpress_logged_in_test' => (string) $manager );
fh_request( array( 'cookies' => $native ) );
$native_nonce = wp_create_nonce( Front_Desk::NONCE );
$count        = count( $store->saves );
fh_save( $native, array( 'nonce' => $native_nonce, 'fh_save_id' => $id ) );
fh_save( $native, array( 'nonce' => $native_nonce, 'fh_save_id' => $id ) );
check( 'with a WordPress login a save id is ignored: the handler runs each time, nothing is remembered', 14 === $GLOBALS['fh_writes'] && $count === count( $store->saves ) );
check( '… and it cost this section no query', 0 === $store->queries );

echo "\n7. Kept for a week; a store that cannot answer\n";
// The sheet has no time limit: it retries by itself when the connection
// is back and after a PIN. An hour, a night, a weekend later the id must
// still be answered from memory.
$writes = $GLOBALS['fh_writes'];
foreach ( array( 'at 59 minutes' => 59 * 60, 'at 61 minutes' => 2 * 60, 'the next morning' => 14 * 3600, 'after a weekend' => 2 * 86400 ) as $when => $step ) {
	$GLOBALS['fh_now'] += $step;
	fh_request( array( 'cookies' => $jar ) );
	Access::instance()->unlock( '1379' );
	$jar         = fh_jar( $jar );
	$robin_nonce = fh_page_nonce( $jar );
	$late        = fh_save( $jar, array( 'nonce' => $robin_nonce, 'fh_save_id' => $id ) );
	check( 'the id sent again ' . $when . ': answered from memory, not written a second time', $writes === $GLOBALS['fh_writes'] && true === $late['json']['success'] && true === $late['json']['data']['replayed'], $late['body'] );
}
$GLOBALS['fh_now'] += Saves::KEEP_SECONDS + 1;
fh_request( array( 'cookies' => $jar ) );
Access::instance()->unlock( '1379' );
$jar         = fh_jar( $jar );
$robin_nonce = fh_page_nonce( $jar );
fh_save( $jar, array( 'nonce' => $robin_nonce, 'fh_save_id' => str_repeat( '07', 16 ) ) );
check( 'saves older than a week are forgotten when the next one arrives', 1 === count( $store->saves ) && 15 === $GLOBALS['fh_writes'] );

$store->fail['claim_save'] = true;
$r                         = fh_save( $jar, array( 'nonce' => $robin_nonce, 'fh_save_id' => str_repeat( '18', 16 ) ) );
check( 'when the store cannot say whether an id is known the save runs as it would without one — never blocked', 200 === $r['status'] && 16 === $GLOBALS['fh_writes'] );
$store->fail = array();

echo "\n8. Only the write action\n";
fh_request( array( 'method' => 'POST', 'uri' => '/wp-admin/admin-ajax.php', 'admin' => true, 'ajax' => true, 'post' => array( 'action' => 'fishotel_cleanroom_act' ), 'cookies' => $jar ) );
do_action( 'init' );
check( 'on a write the step in front of the handler is hooked: after the account is in place and the capability granted (priority 0), before the Front Desk (priority 10)', 1 === has_action( 'wp_ajax_fishotel_cleanroom_act', array( Saves::class, 'before' ) ) && 0 === has_action( 'wp_ajax_fishotel_cleanroom_act', array( \FisHotel\Misc\Sections\Shop_App\Gate::class, 'enter' ) ) && 0 === has_action( 'wp_ajax_nopriv_fishotel_cleanroom_act', array( \FisHotel\Misc\Sections\Shop_App\Gate::class, 'enter_ajax' ) ) );
foreach ( array( 'fishotel_cleanroom_screen', 'fishotel_cleanroom_suggest', 'fishotel_cleanroom_parse' ) as $read ) {
	fh_request( array( 'method' => 'POST', 'uri' => '/wp-admin/admin-ajax.php', 'admin' => true, 'ajax' => true, 'post' => array( 'action' => $read ), 'cookies' => $jar ) );
	do_action( 'init' );
	if ( false !== has_action( 'wp_ajax_' . $read, array( Saves::class, 'before' ) ) || 0 !== has_action( 'wp_ajax_' . $read, array( \FisHotel\Misc\Sections\Shop_App\Gate::class, 'enter' ) ) ) {
		check( 'not hooked for ' . $read, false );
	}
}
check( 'on the three actions that only read there is no such step (the capability is still granted at priority 0)', true );
fh_request( array( 'method' => 'POST', 'uri' => '/wp-admin/admin-ajax.php', 'admin' => true, 'ajax' => true, 'post' => array( 'action' => 'fishotel_cleanroom_act' ), 'cookies' => array( 'wordpress_logged_in_test' => (string) $manager ) ) );
do_action( 'init' );
check( 'for a WordPress login nothing is hooked at all', false === has_action( 'wp_ajax_fishotel_cleanroom_act', array( Saves::class, 'before' ) ) && false === has_action( 'wp_ajax_fishotel_cleanroom_act', array( \FisHotel\Misc\Sections\Shop_App\Gate::class, 'enter' ) ) && false === has_action( 'wp_ajax_nopriv_fishotel_cleanroom_act', array( \FisHotel\Misc\Sections\Shop_App\Gate::class, 'enter_ajax' ) ) );

fh_finish();
