<?php
/**
 * Admin — the AJAX handlers and the screens, end to end on in-memory
 * stand-ins.
 *
 * Every handler refuses a user without manage_woocommerce and a request
 * without the nonce before it reads or writes anything; there is no
 * handler for logged-out visitors. Then the whole path through the real
 * handlers: save a draft, pre-check (the report as HTML), publish, edit
 * an unpaid invoice, Trash, withdraw, the forum list, Settings, the
 * search — and the screens themselves: the list, the picker, one
 * invoice's page with only the buttons the rules allow, the forum list
 * and Settings, each rendered with hostile text in every field to prove
 * it is escaped. A PHP notice anywhere fails the run.
 *
 * Run from the plugin root:  php includes/sections/invoices/tests/admin-test.php
 */

require_once __DIR__ . '/wp-stubs.php';

use FisHotel\Misc\Sections\Invoices\Admin;
use FisHotel\Misc\Sections\Invoices\Invoices;
use FisHotel\Misc\Sections\Invoices\Lines;
use FisHotel\Misc\Sections\Invoices\Settings;
use FisHotel\Misc\Sections\Invoices\Shop;
use FisHotel\Misc\Sections\Invoices\Store;
use FisHotel\Misc\Sections\Invoices\Tests\Fake_Store;
use FisHotel\Misc\Sections\Invoices\Tests\Fake_Tanks;

const KEEPER   = 8;
const VISITOR  = 9;
const HOSTILE  = '<script>alert(1)</script>';

$GLOBALS['fh_user_caps'] = array( 'manage_woocommerce' => array( KEEPER ) );
$GLOBALS['fh_is_admin']  = true;

/**
 * A fresh shop, store and tanks, handed to the Admin.
 *
 * @return array{0: Fake_Store, 1: Shop, 2: Fake_Tanks, 3: array}
 */
function fresh() {
	$world = fh_wc_world();
	$store = new Fake_Store();
	$shop  = new Shop();
	$tanks = new Fake_Tanks();
	update_option( Store::DB_VERSION_OPTION, Store::DB_VERSION, true );
	update_option( Settings::OPTION_KEY, array( 'image_under' => $world['img_small'], 'image_over' => $world['img_big'] ), false );
	Admin::stand_in( $store, $shop, $tanks );
	$GLOBALS['fh_current_user'] = KEEPER;
	return array( $store, $shop, $tanks, $world );
}

/**
 * Call one of the section's wp_ajax handlers the way WordPress would and
 * hand back what it answered.
 *
 * @param string     $operation The operation.
 * @param array      $post      The posted fields (unslashed; slashed here as WordPress slashes them).
 * @param string|nil $nonce     The nonce to send (null = the right one, '' = none).
 * @return Fh_Test_Json
 */
function call( $operation, array $post = array(), $nonce = null ) {
	$slash = static function ( $value ) use ( &$slash ) {
		return is_array( $value ) ? array_map( $slash, $value ) : ( is_string( $value ) ? addslashes( $value ) : $value );
	};
	$nonce = null === $nonce ? wp_create_nonce( Admin::NONCE ) : $nonce;

	$_POST    = $slash( $post );
	$_REQUEST = '' === $nonce ? $_POST : array_merge( $_POST, array( 'nonce' => $nonce ) );

	try {
		call_user_func( array( new Admin(), 'ajax_' . $operation ) );
	} catch ( Fh_Test_Json $reply ) {
		$_POST    = array();
		$_REQUEST = array();
		return $reply;
	}
	$_POST    = array();
	$_REQUEST = array();
	return new Fh_Test_Json( false, array( 'message' => 'the handler did not answer' ), 0 );
}

/**
 * Render the page for a query and hand back the HTML.
 *
 * @param array $get The query.
 * @return string
 */
function screen( array $get = array() ) {
	$_GET = $get;
	ob_start();
	try {
		( new Admin() )->render_page();
	} finally {
		$html = (string) ob_get_clean();
		$_GET = array();
	}
	return $html;
}

function form( array $more = array() ) {
	return array_merge(
		array(
			'handle'         => 'Tester',
			'customer_name'  => 'Test Person',
			'customer_email' => 'person@example.test',
			'shipping'       => 'premium',
			'flat_on'        => false,
			'lines'          => array(
				array( 'kind' => 'fish', 'name' => 'Blue Test Tang', 'qty' => 2, 'unit_price' => '45' ),
				array( 'kind' => 'fish', 'name' => 'Red Test Goby', 'qty' => 1, 'unit_price' => '12.50' ),
			),
		),
		$more
	);
}

echo "1. Every handler asks for the capability and the nonce first\n";
list( $store, $shop ) = fresh();
check_same( 'the operations', array( 'search', 'handles', 'customers', 'save', 'precheck', 'publish', 'update', 'trash', 'discard', 'forum', 'settings' ), Admin::OPERATIONS );
$GLOBALS['fh_actions'] = array();
( new Admin() )->init();
foreach ( Admin::OPERATIONS as $operation ) {
	check( "wp_ajax_fishotel_invoices_$operation is registered, to a method that exists", isset( $GLOBALS['fh_actions'][ 'wp_ajax_fishotel_invoices_' . $operation ] ) && method_exists( Admin::class, 'ajax_' . $operation ) );
}
check( 'nothing is registered for logged-out visitors', array() === preg_grep( '/^wp_ajax_nopriv_/', array_keys( $GLOBALS['fh_actions'] ) ) );

$payload = array( 'payload' => json_encode( form() ), 'id' => 1, 'term' => 'tang', 'today' => '1', 'settings' => array( 'category_slug' => 'hacked' ) );
foreach ( Admin::OPERATIONS as $operation ) {
	$GLOBALS['fh_current_user'] = VISITOR;
	$reply                      = call( $operation, $payload );
	$GLOBALS['fh_current_user'] = KEEPER;
	$no_nonce                   = call( $operation, $payload, '' );
	$bad_nonce                  = call( $operation, $payload, 'nonce-0000000000' );
	check( "$operation: a user without manage_woocommerce gets 403; no nonce and a wrong nonce get 403", ! $reply->success && 403 === $reply->status && 403 === $no_nonce->status && 403 === $bad_nonce->status && ! $no_nonce->success && ! $bad_nonce->success );
}
check( 'none of those 33 requests read or wrote anything', 0 === $store->reads && 0 === $store->writes && array() === $GLOBALS['fh_wc']['writes'] && array() === $store->invoices && 'invoices' === Settings::get()['category_slug'] );

$guarded = 0;
$source  = (string) file_get_contents( dirname( __DIR__ ) . '/class-admin.php' );
preg_match_all( '/public function (ajax_\w+)\(\) \{\s+(\S[^\n]*)/', $source, $m );
foreach ( $m[2] as $first ) {
	$guarded += 'self::guard();' === trim( $first ) ? 1 : 0;
}
check_same( 'the first statement of every ajax_ method is the guard', count( Admin::OPERATIONS ), $guarded );
check( 'the guard checks the capability and then the nonce', (bool) preg_match( '/function guard\(\) \{\s+if \( ! current_user_can\( Invoices::CAPABILITY \) \) \{.*?check_ajax_referer\( self::NONCE, \'nonce\' \);/s', $source ) );

echo "2. Save, pre-check, publish — through the handlers\n";
list( $store, $shop, $tanks, $world ) = fresh();
$reply = call( 'save', array( 'payload' => json_encode( form() ) ) );
check( 'save: a draft, with its id, its version and the invoice as the picker holds it', $reply->success && 'saved' === $reply->data['code'] && 1 === $reply->data['id'] && 1 === $reply->data['version'] && 'draft' === $reply->data['state'] && 2 === count( $reply->data['invoice']['lines'] ) && $reply->data['invoice']['lines'][0]['id'] > 0 && false !== strpos( $reply->data['viewUrl'], 'view=invoice&id=1' ) );
$reply = call( 'save', array( 'payload' => json_encode( form( array( 'id' => 1, 'version' => 99 ) ) ) ) );
check( 'save from a window that is behind: 409, with the version as it is now', ! $reply->success && 409 === $reply->status && 'conflict' === $reply->data['code'] && 1 === $reply->data['version'] );
check( 'a payload that is not JSON, or too large, saves nothing', ! call( 'save', array( 'payload' => '{broken' ) )->success && ! call( 'save', array( 'payload' => json_encode( form( array( 'notes' => str_repeat( 'x', Admin::MAX_PAYLOAD ) ) ) ) ) )->success && ! call( 'save', array() )->success && 1 === count( $store->invoices ) );
$quoted = call( 'save', array( 'payload' => json_encode( form( array( 'handle' => 'O\'Tester "Q" \\ back', 'lines' => array( array( 'kind' => 'fish', 'name' => 'Blue "Test" Tang \\ o\'Clown', 'qty' => 1, 'unit_price' => '45' ) ) ) ) ) ) );
check( 'quotes and backslashes arrive as typed (the slashes WordPress adds are taken off once)', $quoted->success && 'O\'Tester "Q" \\ back' === $store->invoice( $quoted->data['id'] )['handle'] && 'Blue "Test" Tang \\ o\'Clown' === $store->lines( $quoted->data['id'] )[0]['name'] );

$reply = call( 'precheck', array( 'payload' => json_encode( form( array( 'id' => 1, 'version' => 1 ) ) ) ) );
check( 'precheck: the report as HTML, a token, nothing to confirm, not blocked', $reply->success && 'checked' === $reply->data['code'] && 40 === strlen( $reply->data['token'] ) && array() === $reply->data['dupKeys'] && false === $reply->data['blocked'] && false !== strpos( $reply->data['html'], 'Tester Invoice (11/3/26)' ) && false !== strpos( $reply->data['html'], 'This customer&#039;s orders' ) );
$token = $reply->data['token'];
$wrong = call( 'publish', array( 'id' => 1, 'token' => 'nope' ) );
check( 'publish without the token of a pre-check: refused, no product', ! $wrong->success && 'precheck' === $wrong->data['code'] && array() === $GLOBALS['fh_wc']['products'] );
$reply = call( 'publish', array( 'id' => 1, 'token' => $token ) );
check( 'publish: the link, the title, the total, the invoice\'s page', $reply->success && 'published' === $reply->data['code'] && 'https://example.test/shop/invoices/tester-invoice-11-3-26/' === $reply->data['url'] && 'Tester Invoice (11/3/26)' === $reply->data['title'] && 102.5 === $reply->data['total'] && 'published' === $reply->data['state'] && 1 === count( $GLOBALS['fh_wc']['products'] ) );
$pid   = $reply->data['product_id'];
$again = call( 'publish', array( 'id' => 1, 'token' => $token ) );
check( 'publish again: refused, with the link, no second product', ! $again->success && 'state' === $again->data['code'] && $reply->data['url'] === $again->data['url'] && 1 === count( $GLOBALS['fh_wc']['products'] ) );

echo "3. A fish on two invoices, through the handlers\n";
$second = form( array( 'handle' => 'tester', 'lines' => array( array( 'kind' => 'fish', 'name' => 'Blue Test Tangs', 'qty' => 1, 'unit_price' => '45' ) ) ) );
$reply  = call( 'precheck', array( 'payload' => json_encode( $second ) ) );
check( 'the pre-check flags it: one key to confirm, a tick box for it in the HTML', $reply->success && 1 === count( $reply->data['dupKeys'] ) && false !== strpos( $reply->data['html'], 'class="fh-inv-dup-box" value="' . $reply->data['dupKeys'][0] . '"' ) );
$id2    = $reply->data['id'];
$token2 = $reply->data['token'];
$key    = $reply->data['dupKeys'][0];
$refuse = call( 'publish', array( 'id' => $id2, 'token' => $token2 ) );
check( 'publish without the tick: refused, with the report again', ! $refuse->success && 'confirm' === $refuse->data['code'] && isset( $refuse->data['html'] ) && 1 === count( $GLOBALS['fh_wc']['products'] ) );
$junk = call( 'publish', array( 'id' => $id2, 'token' => $token2, 'confirmed' => array( 'not-a-key', array( $key ), $key . 'x' ) ) );
check( 'junk in place of the confirmed keys confirms nothing', ! $junk->success && 'confirm' === $junk->data['code'] );
$done = call( 'publish', array( 'id' => $id2, 'token' => $token2, 'confirmed' => array( $key ) ) );
check( 'with the tick: published', $done->success && 2 === count( $GLOBALS['fh_wc']['products'] ) );

echo "4. Edit this unpaid invoice, Trash, withdraw — through the handlers\n";
$edit          = form( array( 'id' => 1, 'version' => $store->invoice( 1 )['version'], 'mode' => 'update', 'flat_on' => true, 'flat_total' => '90' ) );
$reply         = call( 'precheck', array( 'payload' => json_encode( $edit ) ) );
check( 'precheck of the changes: a token, the report says the title is unchanged, nothing saved', $reply->success && false !== strpos( $reply->data['html'], 'unchanged — the customer has this link' ) && 102.5 === $store->invoice( 1 )['total'] && ! isset( $reply->data['invoice'] ) );
$saved = call( 'update', array( 'payload' => json_encode( $edit ), 'token' => $reply->data['token'], 'confirmed' => $reply->data['dupKeys'] ) );
check( 'update: the product has the new price and the same link', $saved->success && 'updated' === $saved->data['code'] && '90.00' === $shop->product( $pid )['regular_price'] && 'https://example.test/shop/invoices/tester-invoice-11-3-26/' === $saved->data['url'] && 90.0 === $store->invoice( 1 )['total'] );

$order  = fh_wc_order( array( 'status' => 'processing', 'date_paid' => true ), array( array( 'name' => 'Tester Invoice (11/3/26)', 'qty' => 1, 'total' => 90, 'product_id' => $pid ) ) );
$edit   = form( array( 'id' => 1, 'version' => $store->invoice( 1 )['version'], 'mode' => 'update' ) );
$locked = call( 'precheck', array( 'payload' => json_encode( $edit ) ) );
check( 'once it is paid the editor does not open: locked, naming the order', ! $locked->success && 'locked' === $locked->data['code'] && false !== strpos( $locked->data['message'], '#' . $order ) && 'paid' === $locked->data['state'] );
$locked = call( 'update', array( 'payload' => json_encode( $edit ), 'token' => 'x' ) );
check( '…and a save is refused, the product untouched', ! $locked->success && 'locked' === $locked->data['code'] && '90.00' === $shop->product( $pid )['regular_price'] );
$trash = call( 'trash', array( 'id' => 1 ) );
check( '…and it cannot be trashed', ! $trash->success && 'publish' === $shop->product( $pid )['status'] );

$trash = call( 'trash', array( 'id' => $id2 ) );
check( 'an unpaid invoice no order contains: to the Trash, never deleted', $trash->success && 'trashed' === $trash->data['code'] && 'trash' === $shop->product( $done->data['product_id'] )['status'] && 'trashed' === $store->invoice( $id2 )['state'] );
$draft   = call( 'save', array( 'payload' => json_encode( form( array( 'handle' => 'Drafty' ) ) ) ) );
$discard = call( 'discard', array( 'id' => $draft->data['id'] ) );
check( 'a draft: withdrawn', $discard->success && 'cancelled' === $store->invoice( $draft->data['id'] )['state'] );
check( 'an invoice that does not exist: refused, for each of them', ! call( 'trash', array( 'id' => 999 ) )->success && ! call( 'discard', array( 'id' => 999 ) )->success && ! call( 'publish', array( 'id' => 999, 'token' => 'x' ) )->success && ! call( 'update', array( 'payload' => json_encode( form( array( 'id' => 999 ) ) ), 'token' => 'x' ) )->success );

echo "5. The forum list\n";
list( $store, $shop, $tanks, $world ) = fresh();
$make = static function ( array $more ) {
	$check = call( 'precheck', array( 'payload' => json_encode( form( $more ) ) ) );
	return call( 'publish', array( 'id' => $check->data['id'], 'token' => $check->data['token'], 'confirmed' => $check->data['dupKeys'] ) )->data;
};
$a = $make( array( 'handle' => 'Zed Tester' ) );
$b = $make( array( 'handle' => 'T&j', 'forum_user_id' => 12456, 'flat_on' => true, 'flat_total' => '1250.5', 'lines' => array( array( 'kind' => 'fish', 'name' => 'Rare Test Angel', 'qty' => 1, 'unit_price' => '1250.50' ) ) ) );
$c = $make( array( 'handle' => 'alpha', 'lines' => array( array( 'kind' => 'fish', 'name' => 'Green Test Blenny', 'qty' => 1, 'unit_price' => '30' ) ) ) );
call( 'trash', array( 'id' => $c['id'] ) );
$d = call( 'save', array( 'payload' => json_encode( form( array( 'handle' => 'Drafty', 'lines' => array( array( 'kind' => 'fish', 'name' => 'Draft Test Fish', 'qty' => 1, 'unit_price' => '5' ) ) ) ) ) ) )->data;
// Published yesterday.
$store->invoices[ $a['id'] ]['published_at'] = '2026-11-02 15:00:00';

$reply = call( 'forum', array( 'ids' => array( $a['id'], $b['id'], $c['id'], $d['id'], 'x', 987 ) ) );
check_same( 'the ticked invoices: one line each, by handle; the BBCode mention for a handle with a forum user id; no bold, no dashes', "[USER=12456]T&j[/USER] \$1,250.50 https://example.test/shop/invoices/tj-invoice-11-3-26/\n@Zed Tester \$102.50 https://example.test/shop/invoices/zed-tester-invoice-11-3-26/", $reply->data['text'] );
check( 'a trashed invoice and a draft are left out, and the reply says so', 2 === $reply->data['count'] && 2 === count( $reply->data['skipped'] ) && 1 === count( preg_grep( '/alpha Invoice.*In the Trash/', $reply->data['skipped'] ) ) && 1 === count( preg_grep( '/Drafty.*Draft/', $reply->data['skipped'] ) ) );
$reply = call( 'forum', array( 'today' => '1' ) );
check_same( 'everything published today (the site\'s today): yesterday\'s invoice is not in it', '[USER=12456]T&j[/USER] $1,250.50 https://example.test/shop/invoices/tj-invoice-11-3-26/', $reply->data['text'] );
$reply = call( 'forum', array() );
check( 'nothing ticked: an empty list', $reply->success && '' === $reply->data['text'] && 0 === $reply->data['count'] );
$GLOBALS['fh_wc']['products'][ $b['product_id'] ]['data']['status'] = 'draft';
check_same( 'an invoice whose product was unpublished by hand is left out', 0, call( 'forum', array( 'ids' => array( $b['id'] ) ) )->data['count'] );

echo "6. Search, handles, customers, Settings — through the handlers\n";
list( $store, $shop, $tanks, $world ) = fresh();
fh_wc_product( array( 'name' => 'Blue Test Tang', 'regular_price' => '45', 'category_ids' => array( $world['cat_fish'] ), 'manage_stock' => true, 'stock_quantity' => 3 ) );
call( 'save', array( 'payload' => json_encode( form( array( 'handle' => 'Tester', 'forum_user_id' => 5, 'customer_id' => $world['customer'] ) ) ) ) );
call( 'save', array( 'payload' => json_encode( form( array( 'handle' => 'Testosterone' ) ) ) ) );
$reply = call( 'search', array( 'term' => 'tang', 'invoice_id' => 0 ) );
check( 'search: the four groups, the shop product with its stock', $reply->success && array( 'tanks', 'products', 'species', 'typed' ) === array_column( $reply->data['groups'], 'key' ) && 'Blue Test Tang' === $reply->data['groups'][1]['items'][0]['name'] && true === $reply->data['groups'][1]['items'][0]['managed'] && 3 === $reply->data['groups'][1]['items'][0]['stock'] );
check_same( 'search: one letter finds nothing', array(), call( 'search', array( 'term' => 't' ) )->data['groups'] );
$reply = call( 'handles', array( 'term' => 'test' ) );
check( 'handles used before, each with what its newest invoice knows of the customer', $reply->success && array( 'Tester', 'Testosterone' ) === array_column( $reply->data['handles'], 'handle' ) && 5 === $reply->data['handles'][0]['forum_user_id'] && $world['customer'] === $reply->data['handles'][0]['customer_id'] && 'Test Person' === $reply->data['handles'][0]['customer_name'] );
$reply = call( 'customers', array( 'term' => 'person' ) );
check( 'site customers by name or e-mail', $reply->success && 1 === count( $reply->data['customers'] ) && $world['customer'] === $reply->data['customers'][0]['id'] );

$reply = call( 'settings', array( 'settings' => array( 'category_slug' => 'invoices', 'title_format' => '{handle} Invoice ({date})', 'excerpt_format' => '{handle} Only', 'shipping' => array( 'premium' => 'premium', 'free' => 'free' ), 'image_under' => (string) $world['img_small'], 'image_over' => (string) $world['img_big'], 'image_threshold' => '400' ) ) );
check( 'settings: saved, with nothing to remark', $reply->success && array() === $reply->data['notes'] && $world['img_big'] === Settings::get()['image_over'] );
$reply = call( 'settings', array( 'settings' => array( 'category_slug' => 'bills', 'title_format' => 'no handle', 'shipping' => array( 'premium' => 'overnight' ), 'image_under' => '999999' ) ) );
check( 'settings: what cannot work is said — a title without {handle}, a category, a class and an image that do not exist', $reply->success && 4 === count( $reply->data['notes'] ) && '{handle} Invoice ({date})' === Settings::get()['title_format'] && 'bills' === Settings::get()['category_slug'] );
check( 'settings: a posted key that is no setting is not stored', ! isset( get_option( Settings::OPTION_KEY )['evil'] ) && call( 'settings', array( 'settings' => array( 'evil' => 'x' ) ) )->success && ! isset( get_option( Settings::OPTION_KEY )['evil'] ) );

echo "7. The screens\n";
list( $store, $shop, $tanks, $world ) = fresh();
$GLOBALS['fh_current_user'] = VISITOR;
$denied                     = false;
try {
	screen();
} catch ( \RuntimeException $e ) {
	$denied = 0 === strpos( $e->getMessage(), 'wp_die' );
}
$GLOBALS['fh_current_user'] = KEEPER;
check( 'a user without manage_woocommerce does not get the page', $denied );

$html = screen();
check( 'the empty list: the tabs, how to start, the "made by hand" section', false !== strpos( $html, 'fh-inv-tabs' ) && false !== strpos( $html, 'No invoice has been built here yet' ) && false !== strpos( $html, 'Made by hand' ) && false === strpos( $html, 'tables do not exist' ) );

// One of everything, with hostile text wherever text can be typed.
$paid   = $make( array( 'handle' => 'Tester' . HOSTILE, 'customer_name' => 'Test "><b>Person</b>', 'notes' => "note\n" . HOSTILE, 'lines' => array( array( 'kind' => 'fish', 'name' => 'Blue Test Tang ' . HOSTILE, 'qty' => 2, 'unit_price' => '45' ), array( 'kind' => 'credit', 'name' => 'lost ' . HOSTILE, 'unit_price' => '5' ), array( 'kind' => 'note', 'name' => 'Ships ' . HOSTILE ) ) ) );
$order  = fh_wc_order( array( 'status' => 'processing', 'date_paid' => true, 'total' => 85, 'billing_first_name' => HOSTILE, 'billing_last_name' => 'Person', 'payment_method_title' => HOSTILE ), array( array( 'name' => 'Blue Test Tang ' . HOSTILE, 'qty' => 2, 'total' => 90, 'meta' => array( Invoices::ITEM_FISH_META => 1, Invoices::ITEM_PRODUCT_META => $paid['product_id'], Invoices::ITEM_TITLE_META => 'Tester Invoice ' . HOSTILE ) ) ) );
$unpaid = $make( array( 'handle' => 'Second' ) );
$draft  = call( 'save', array( 'payload' => json_encode( form( array( 'handle' => 'Drafty', 'customer_id' => $world['customer'], 'flat_on' => true, 'flat_total' => '50' ) ) ) ) )->data;
$gone   = $make( array( 'handle' => 'Gone' ) );
call( 'trash', array( 'id' => $gone['id'] ) );
$hand   = fh_wc_product( array( 'name' => 'Handy Invoice (7/3/26) ' . HOSTILE, 'description' => '<ul><li>Hand Test Goby ' . htmlspecialchars( HOSTILE, ENT_QUOTES, 'UTF-8' ) . ' x2 — $39</li></ul>', 'regular_price' => '78', 'sale_price' => '70', 'category_ids' => array( $world['cat_invoices'] ) ) );
fh_wc_order( array( 'status' => 'completed', 'date_paid' => true ), array( array( 'name' => 'Handy Invoice (7/3/26)', 'qty' => 1, 'total' => 70, 'product_id' => $hand ) ) );

$html = screen();
check( 'the list: every invoice built here that is open, with its state', false !== strpos( $html, 'Second Invoice (11/3/26)' ) && false !== strpos( $html, 'Draft #' . $draft['id'] ) && false !== strpos( $html, 'fh-inv-badge--paid' ) && false !== strpos( $html, 'fh-inv-badge--published' ) && false !== strpos( $html, 'fh-inv-badge--draft' ) && false === strpos( $html, 'Gone Invoice' ) );
check( 'the paid one names its order; "Add fish for this customer" is on every row', false !== strpos( $html, 'order #' . $order . ' (Processing)' ) && 3 === substr_count( $html, 'Add fish for this customer</a>' ) );
check( 'only what a customer can open has a tick box and a Copy link', 2 === substr_count( $html, 'class="fh-inv-pick"' ) && false !== strpos( $html, 'data-copy="https://example.test/shop/invoices/second-invoice-11-3-26/"' ) );
check( 'the hand-made invoice is listed apart, read-only: its handle, PAID with its order, its sale price — and no way to change it', false !== strpos( $html, 'fh-inv-badge--hand' ) && false !== strpos( $html, 'Handy' ) && false !== strpos( $html, 'PAID on order' ) && false !== strpos( $html, 'sale price $70' ) && 1 === preg_match( '/<table class="widefat fh-inv-table fh-inv-hand">.*<\/table>/s', $html, $hand_table ) && false === strpos( $hand_table[0], 'view=edit' ) && false === strpos( $hand_table[0], 'fh-inv-trash' ) );
check( 'the products built here are not listed again as made by hand', 1 === substr_count( $html, 'fh-inv-row--hand' ) );
check( 'the state counts on the filter chips', 1 === preg_match( '/Drafts <span class="fh-inv-chip__count">1<\/span>/', $html ) && 1 === preg_match( '/In the Trash <span class="fh-inv-chip__count">1<\/span>/', $html ) );
check( 'no hostile text reaches the page unescaped', false === strpos( $html, '<script>alert' ) && false === strpos( $html, '<b>Person' ) );
$html = screen( array( 'state' => 'trashed' ) );
check( 'the filter: only the trashed one', false !== strpos( $html, 'Gone Invoice (11/3/26)' ) && false === strpos( $html, 'Second Invoice' ) );
$html = screen( array( 's' => 'second' ) );
check( 'the search: by handle', false !== strpos( $html, 'Second Invoice' ) && false === strpos( $html, 'Draft #' ) );
check( 'a search that finds nothing says so', false !== strpos( screen( array( 's' => 'nobody' ) ), 'No invoice matches' ) );

$html = screen( array( 'view' => 'invoice', 'id' => $paid['id'] ) );
check( 'a PAID invoice\'s page: locked, with its order — no Edit, no Trash, but "Add fish for this customer"', false !== strpos( $html, 'A paid invoice is never changed' ) && false !== strpos( $html, '#' . $order . ' (Processing)' ) && false === strpos( $html, 'fh-inv-edit-unpaid' ) && false === strpos( $html, 'fh-inv-trash' ) && false !== strpos( $html, 'view=new&from=' . $paid['id'] ) );
check( '…its lines, its link with a Copy button, who and when, the product as it is, the order that contains it', false !== strpos( $html, 'Blue Test Tang' ) && false !== strpos( $html, 'class="button button-primary fh-inv-copy"' ) && false !== strpos( $html, 'Who and when' ) && false !== strpos( $html, 'managed, quantity 1, backorders: no, instock' ) && false !== strpos( $html, 'Orders that contain this invoice' ) );
check( '…and nothing hostile unescaped (the handle, the name, the notes, the lines, the order)', false === strpos( $html, '<script>alert' ) && false === strpos( $html, '<b>Person' ) && false !== strpos( $html, 'note<br />' ) );

$html = screen( array( 'view' => 'invoice', 'id' => $unpaid['id'] ) );
check( 'an unpaid published invoice no order contains: the deliberate "Edit this unpaid invoice…" and "Move to the Trash" are offered', false !== strpos( $html, 'fh-inv-edit-unpaid' ) && false !== strpos( $html, 'unpaid=1' ) && false !== strpos( $html, 'fh-inv-trash' ) && false === strpos( $html, 'fh-inv-discard' ) );
$pending = fh_wc_order( array( 'status' => 'pending' ), array( array( 'name' => 'Second Invoice (11/3/26)', 'qty' => 1, 'total' => 102.5, 'product_id' => $unpaid['product_id'] ) ) );
$html    = screen( array( 'view' => 'invoice', 'id' => $unpaid['id'] ) );
check( 'once an order contains it — even an unpaid one — neither is offered, and the page says why', false === strpos( $html, 'fh-inv-edit-unpaid' ) && false === strpos( $html, 'fh-inv-trash' ) && false !== strpos( $html, 'the customer has checked out' ) && false !== strpos( $html, 'An order contains this invoice (#' . $pending . ' (Pending payment))' ) );
unset( $GLOBALS['fh_wc']['orders'][ $pending ] );

$html = screen( array( 'view' => 'invoice', 'id' => $draft['id'] ) );
check( 'a draft\'s page: Edit draft and Withdraw; no link, no Trash', false !== strpos( $html, 'Edit draft' ) && false !== strpos( $html, 'fh-inv-discard' ) && false === strpos( $html, 'fh-inv-linkrow' ) && false === strpos( $html, 'fh-inv-trash' ) && false !== strpos( $html, 'a flat total was charged' ) );
$html = screen( array( 'view' => 'invoice', 'id' => $gone['id'] ) );
check( 'a trashed invoice\'s page: no link, no Edit, no Trash', false === strpos( $html, 'fh-inv-linkrow' ) && false === strpos( $html, 'fh-inv-edit-unpaid' ) && false === strpos( $html, 'fh-inv-trash' ) && false !== strpos( $html, 'This invoice is in the Trash' ) );
check( 'an invoice that does not exist', false !== strpos( screen( array( 'view' => 'invoice', 'id' => 999 ) ), 'This invoice does not exist' ) && false !== strpos( screen( array( 'view' => 'edit', 'id' => 999 ) ), 'This invoice does not exist' ) );

echo "8. The picker\n";
$state_of = static function ( $html ) {
	return preg_match( '/<script type="application\/json" id="fh-inv-state">(.*?)<\/script>/s', $html, $m ) ? json_decode( $m[1], true ) : null;
};
$html  = screen( array( 'view' => 'new' ) );
$state = $state_of( $html );
check( 'New: an empty draft, Premium, with Save and "Pre-check and publish…" — and no Publish without a pre-check', is_array( $state ) && 0 === $state['id'] && 'draft' === $state['mode'] && '' === $state['handle'] && array() === $state['lines'] && 'premium' === $state['shipping'] && false !== strpos( $html, 'id="fh-inv-save"' ) && false !== strpos( $html, 'id="fh-inv-precheck"' ) && 1 === preg_match( '/id="fh-inv-publish" disabled/', $html ) );
check( 'the search box, the lines, credits and notes, the flat total, the shipping choices, the preview', false !== strpos( $html, 'id="fh-inv-search"' ) && false !== strpos( $html, 'id="fh-inv-lines"' ) && false !== strpos( $html, 'id="fh-inv-add-credit"' ) && false !== strpos( $html, 'id="fh-inv-add-note"' ) && false !== strpos( $html, 'id="fh-inv-flat-on"' ) && 3 === substr_count( $html, 'name="fh-inv-shipping"' ) && false !== strpos( $html, 'id="fh-inv-preview-list"' ) );
check( 'the shipping classes of the store are offered for "Another class"', false !== strpos( $html, '<option value="quote"' ) && false !== strpos( $html, 'class &quot;premium&quot;' ) );

$html  = screen( array( 'view' => 'new', 'from' => $paid['id'] ) );
$state = $state_of( $html );
check( '"Add fish for this customer": a NEW invoice — the customer of the other one, none of its lines, no id', 0 === $state['id'] && 'draft' === $state['mode'] && array() === $state['lines'] && 0 === strpos( $state['handle'], 'Tester' ) && 'Test ">Person' === $state['customer_name'] );
check( 'the state travels as JSON with its tags and quotes hex-escaped', false === strpos( $html, '<script>alert' ) && false === strpos( $html, '">Person' ) && false !== strpos( $html, '>' ) );

$html  = screen( array( 'view' => 'edit', 'id' => $draft['id'] ) );
$state = $state_of( $html );
check( 'Edit draft: the draft with its lines (the computed Discount line is left to the page), its flat total, its linked customer', $draft['id'] === $state['id'] && 'draft' === $state['mode'] && 2 === count( $state['lines'] ) && true === $state['flat_on'] && 50.0 === (float) $state['flat_total'] && $world['customer'] === $state['customer_id'] && false !== strpos( $html, 'id="fh-inv-discard"' ) );

$html = screen( array( 'view' => 'edit', 'id' => $unpaid['id'] ) );
check( 'a published invoice does not open for editing by accident: its page, with the reason', null === $state_of( $html ) && false !== strpos( $html, 'not opened for editing by accident' ) && false !== strpos( $html, 'fh-inv-view' ) );
$html  = screen( array( 'view' => 'edit', 'id' => $unpaid['id'], 'unpaid' => '1' ) );
$state = $state_of( $html );
check( 'the deliberate path: the editor in "update" mode, with the warning, the handle read-only, no Save draft', is_array( $state ) && 'update' === $state['mode'] && false !== strpos( $html, 'You are editing a PUBLISHED invoice' ) && 1 === preg_match( '/id="fh-inv-handle"[^>]*readonly/', $html ) && false === strpos( $html, 'id="fh-inv-save"' ) && false !== strpos( $html, 'Pre-check the changes' ) && false !== strpos( $html, 'Add fish for this customer instead' ) );
$html = screen( array( 'view' => 'edit', 'id' => $paid['id'], 'unpaid' => '1' ) );
check( 'a PAID invoice never opens in the editor, whatever the address says', null === $state_of( $html ) && false !== strpos( $html, 'A paid invoice is never changed' ) );
$html = screen( array( 'view' => 'edit', 'id' => $gone['id'], 'unpaid' => '1' ) );
check( 'nor does a trashed one', null === $state_of( $html ) );

$tanks->on = false;
$html      = screen( array( 'view' => 'new' ) );
check( 'the Clean Room switched off: the picker says so in plain words, and still works', false !== strpos( $html, 'The Clean Room section is switched off' ) && false !== strpos( $html, 'id="fh-inv-search"' ) );
$tanks->on = true;

echo "9. The forum list and Settings screens\n";
$html = screen( array( 'view' => 'forum', 'ids' => $unpaid['id'] . ',abc,' . $paid['id'] ) );
check( 'the forum screen: today\'s count, the published and paid invoices with tick boxes, the ticked ones carried over', false !== strpos( $html, 'Everything published today (2 invoices)' ) && 2 === substr_count( $html, 'checked="checked"' ) && false !== strpos( $html, 'data-auto="1"' ) && false === strpos( $html, 'Gone Invoice' ) && false === strpos( $html, '<script>alert' ) );
$html = screen( array( 'view' => 'settings' ) );
check( 'the settings screen: the category found, the formats with a sample, the classes, the two images, the threshold', false !== strpos( $html, 'Found: &quot;Invoices&quot;' ) && false !== strpos( $html, 'Handle Invoice (11/3/26)' ) && false !== strpos( $html, 'Premium (premium)' ) && false !== strpos( $html, 'Test boxes, small' ) && false !== strpos( $html, 'name="settings[image_threshold]" value="400"' ) );
update_option( Settings::OPTION_KEY, array( 'category_slug' => 'bills', 'shipping' => array( 'premium' => 'overnight' ) ), false );
$html = screen( array( 'view' => 'settings' ) );
check( 'what does not exist is said: the category, a class, no image', false !== strpos( $html, 'This category does not exist' ) && false !== strpos( $html, 'overnight — does not exist on this site' ) && 2 === substr_count( $html, 'Not set: such an invoice gets no image' ) );
$html = screen();
check( 'the list then says there is nothing to list by hand, and why', false !== strpos( $html, 'The product category &quot;bills&quot; does not exist' ) );

echo "10. What cannot work is said on every screen\n";
delete_option( Store::DB_VERSION_OPTION );
check( 'the tables are not installed', false !== strpos( screen(), 'The Invoices tables do not exist yet' ) );
update_option( Store::DB_VERSION_OPTION, Store::DB_VERSION, true );
check( 'a view that does not exist falls back to the list', false !== strpos( screen( array( 'view' => 'nonsense' ) ), 'fh-inv-toolbar' ) );

echo "11. Assets and small helpers\n";
$GLOBALS['fh_enqueued'] = array();
( new Admin() )->enqueue_assets( 'edit.php' );
check( 'nothing is enqueued on other admin pages', array() === $GLOBALS['fh_enqueued'] );
( new Admin() )->enqueue_assets( 'fishotel-tools_page_' . Invoices::MENU_SLUG );
$data = $GLOBALS['fh_enqueued']['data:fhInvoices'][1];
check( 'on the section\'s page: its style, its script, and the page data — the nonce, the addresses, the formats, today\'s date for the title, the texts', isset( $GLOBALS['fh_enqueued']['style:fishotel-invoices-admin'], $GLOBALS['fh_enqueued']['script:fishotel-invoices-admin'] ) && wp_create_nonce( Admin::NONCE ) === $data['nonce'] && false !== strpos( $data['viewUrl'], '__ID__' ) && false !== strpos( $data['forumUrl'], '__IDS__' ) && '11/3/26' === $data['titleDate'] && '{handle} Invoice ({date})' === $data['titleFormat'] && count( $data['i18n'] ) > 30 && ! isset( $GLOBALS['fh_enqueued']['media'] ) );
$_GET = array( 'view' => 'settings' );
( new Admin() )->enqueue_assets( 'fishotel-tools_page_' . Invoices::MENU_SLUG );
$_GET = array();
check( 'the media library only on Settings', isset( $GLOBALS['fh_enqueued']['media'] ) );
check_same( 'page_url()', array( 'https://example.test/wp-admin/admin.php?page=fishotel-misc-invoices', 'https://example.test/wp-admin/admin.php?page=fishotel-misc-invoices&view=invoice&id=7' ), array( Admin::page_url(), Admin::page_url( 'invoice', array( 'id' => 7 ) ) ) );
check_same( 'site_time(): a stored UTC time on the site\'s clock', array( 'Nov 3, 2026 9:00 am', '' ), array( Admin::site_time( '2026-11-03 15:00:00' ), Admin::site_time( null ) ) );
check( 'badge(): escaped, an unknown state shown as a draft', '<span class="fh-inv-badge fh-inv-badge--paid">&lt;b&gt;</span>' === Admin::badge( 'paid', '<b>' ) && false !== strpos( Admin::badge( 'weird' ), 'fh-inv-badge--draft' ) );
$every = array();
preg_match_all( "/\bt\('(\w+)'\)/", (string) file_get_contents( dirname( __DIR__ ) . '/js/invoices-admin.js' ), $m );
foreach ( array_unique( $m[1] ) as $key ) {
	if ( ! isset( Admin::strings()[ $key ] ) ) {
		$every[] = $key;
	}
}
check_same( 'every text the page script asks for by name is handed to it', array(), $every );

Admin::stand_in();
fh_finish();
