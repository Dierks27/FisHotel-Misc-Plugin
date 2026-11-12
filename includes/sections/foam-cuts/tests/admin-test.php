<?php
/**
 * Admin — the page and the printable sheet: who may see them, that a GET
 * never writes, that every write needs manage_woocommerce AND the nonce
 * of that very action, what each handler does (a plan made, marked as
 * cut once, undone, deleted; the stock, the boxes, the cut lists and the
 * settings kept), that every value on a screen is escaped, and what the
 * printable page holds: one source per page, the drawing, the cuts in
 * order, the labels to write, then the pieces per box.
 *
 * Run from the plugin root:  php includes/sections/foam-cuts/tests/admin-test.php
 */

require_once __DIR__ . '/wp-stubs.php';

use FisHotel\Misc\Sections\Foam_Cuts\Admin;
use FisHotel\Misc\Sections\Foam_Cuts\Planner;
use FisHotel\Misc\Sections\Foam_Cuts\Settings;
use FisHotel\Misc\Sections\Foam_Cuts\Stock;
use FisHotel\Misc\Sections\Foam_Cuts\Tests\Fake_Store;

/**
 * Run something that ends where WordPress would stop the request.
 *
 * @return FH_Halt|null The stop (redirect, wp_die), or null when it simply returned.
 */
function fh_stop( callable $run ) {
	ob_start();
	try {
		$run();
	} catch ( FH_Halt $halt ) {
		ob_end_clean();
		return $halt;
	}
	ob_end_clean();
	return null;
}

/**
 * Render the page for a tab and return its HTML.
 */
function fh_page( Admin $admin, array $get = array() ) {
	$_GET = $get;
	ob_start();
	$admin->render_page();
	$_GET = array();
	return (string) ob_get_clean();
}

/**
 * Post a write as the current user with the right nonce; returns the stop.
 */
function fh_post( Admin $admin, $do, array $fields = array(), $nonce = null ) {
	$_POST                = array_merge( array( 'action' => Admin::ACTION, 'do' => $do ), $fields );
	$_REQUEST['_wpnonce'] = null === $nonce ? wp_create_nonce( Admin::nonce_action( $do ) ) : $nonce;
	$halt                 = fh_stop( array( $admin, 'handle' ) );
	$_POST                = array();
	unset( $_REQUEST['_wpnonce'] );
	return $halt;
}

update_option(
	Settings::OPTION_KEY,
	array(
		'boxes' => array(
			array( 'name' => 'Cube', 'length' => fh_in( 12 ), 'width' => fh_in( 12 ), 'height' => fh_in( 12 ) ),
			array( 'name' => 'Flat', 'length' => fh_in( 16 ), 'width' => fh_in( 12 ), 'height' => fh_in( 10 ) ),
			array( 'name' => 'Tom & "Jerry" <b>box</b>', 'length' => fh_in( 9 ), 'width' => fh_in( 8 ), 'height' => fh_in( 7 ) ),
		),
	)
);

$store = new Fake_Store();
$store->add_sheet_entry( 'bought', 3, 0, 'first <i>delivery</i>', 7 );
Stock::add_leftover( $store, '30', '20', 'an "old" <offcut>' );
$admin        = new Admin();
$admin->store = $store;

echo "1. Who may open the page\n";
fh_login( 0 );
$halt = fh_stop( array( $admin, 'render_page' ) );
check( 'nobody logged in: refused (403)', $halt && 'die' === $halt->kind && 403 === $halt->status );
fh_login( 5, array( 'read', 'edit_posts' ) );
$halt = fh_stop( array( $admin, 'render_page' ) );
check( 'a user without manage_woocommerce: refused (403)', $halt && 'die' === $halt->kind && 403 === $halt->status );
fh_login( 7, array( 'manage_woocommerce' ) );
check( 'a shop manager: the page', null === fh_stop( array( $admin, 'render_page' ) ) );

echo "2. Every tab renders, escaped, and a GET writes nothing\n";
$store->calls = array();
$writes       = count( $GLOBALS['fh_options'] );
$pages        = array();
foreach ( Admin::TABS as $tab ) {
	$pages[ $tab ] = fh_page( $admin, array( 'tab' => $tab ) );
}
$pages['bogus'] = fh_page( $admin, array( 'tab' => 'no-such-tab' ) );
check( 'Plan: the boxes with a number field each, and "Make the plan"', false !== strpos( $pages['plan'], 'name="boxes[0][count]"' ) && false !== strpos( $pages['plan'], 'Make the plan' ) && false !== strpos( $pages['plan'], '3 whole sheets in stock' ) && false !== strpos( $pages['plan'], '1 leftover' ) );
check( 'Plan: says plainly why "today\'s orders" is not offered', false !== strpos( $pages['plan'], 'The Packing section is not switched on' ) );
check( 'Stock: the count, the entries, the leftover with its label and size', false !== strpos( $pages['stock'], 'fh-fc-big' ) && false !== strpos( $pages['stock'], 'Bought 3 sheets' ) && false !== strpos( $pages['stock'], '<strong>L1</strong>' ) && false !== strpos( $pages['stock'], 'value="30"' ) );
check( 'Boxes: where the list comes from, what was not available, a cut list per box', false !== strpos( $pages['boxes'], 'own list' ) && false !== strpos( $pages['boxes'], 'ShipTracker' ) && 3 === substr_count( $pages['boxes'], 'Save this cut list' ) && false !== strpos( $pages['boxes'], 'value="Top / bottom"' ) );
check( 'Settings: the sheet, the blade, turning, the smallest leftover', false !== strpos( $pages['settings'], 'name="sheet_length" value="96"' ) && false !== strpos( $pages['settings'], 'name="kerf" value="0"' ) && false !== strpos( $pages['settings'], "name=\"rotate\" value=\"1\" checked='checked'" ) && false !== strpos( $pages['settings'], 'name="min_width" value="4"' ) );
check( 'an unknown tab is the Plan tab', false !== strpos( $pages['bogus'], 'Make the plan' ) );
$raw = false;
foreach ( $pages as $html ) {
	$raw = $raw || false !== strpos( $html, '<b>box</b>' ) || false !== strpos( $html, '"Jerry"' ) || false !== strpos( $html, '<offcut>' ) || false !== strpos( $html, '<i>delivery</i>' );
}
check( 'a name or note with <, >, & and quotes is never written raw', false === $raw );
check( '…it is shown escaped', false !== strpos( $pages['plan'], 'Tom &amp; &quot;Jerry&quot; box' ) && false !== strpos( $pages['stock'], 'an &quot;old&quot;' ) );
check( 'rendering all of it wrote nothing: no store write, no option', array() === $store->calls && $writes === count( $GLOBALS['fh_options'] ) );
check( 'every form posts to admin-post with the action, a "do" and a nonce', substr_count( $pages['stock'], 'name="action" value="fishotel_foam_cuts"' ) === substr_count( $pages['stock'], 'name="_wpnonce"' ) && substr_count( $pages['stock'], 'name="do"' ) === substr_count( $pages['stock'], 'name="_wpnonce"' ) && substr_count( $pages['stock'], 'name="_wpnonce"' ) >= 5 );

$used = array();
foreach ( glob( dirname( __DIR__ ) . '/views/*.php' ) as $view ) {
	preg_match_all( "/form_fields\\(\\s*'([a-z_]+)'/", (string) file_get_contents( $view ), $m );
	$used = array_merge( $used, $m[1] );
}
$used = array_values( array_unique( $used ) );
sort( $used );
$have = array_keys( Admin::HANDLERS );
sort( $have );
check_same( 'every form on the screens has a handler, and every handler a form', $have, $used );
$methods = true;
foreach ( Admin::HANDLERS as $method ) {
	$methods = $methods && method_exists( $admin, $method );
}
check( 'every handler exists', $methods );

echo "3. A write needs the capability and the nonce of that action\n";
$state = $store->state();
fh_login( 5, array( 'read' ) );
$halt = fh_post( $admin, 'stock_buy', array( 'sheets' => '4' ) );
check( 'without manage_woocommerce: 403, even with a nonce of their own', $halt && 'die' === $halt->kind && 403 === $halt->status && $state === $store->state() );
fh_login( 7, array( 'manage_woocommerce' ) );
$halt = fh_post( $admin, 'stock_buy', array( 'sheets' => '4' ), '' );
check( 'without a nonce: refused', $halt && 'die' === $halt->kind && 403 === $halt->status && $state === $store->state() );
$halt = fh_post( $admin, 'stock_buy', array( 'sheets' => '4' ), 'made-up' );
check( 'with a made-up nonce: refused', $halt && 'die' === $halt->kind && $state === $store->state() );
$halt = fh_post( $admin, 'stock_buy', array( 'sheets' => '4' ), wp_create_nonce( Admin::nonce_action( 'stock_count' ) ) );
check( 'with the nonce of another action: refused', $halt && 'die' === $halt->kind && $state === $store->state() );
fh_login( 8, array( 'manage_woocommerce' ) );
$theirs = wp_create_nonce( Admin::nonce_action( 'stock_buy' ) );
fh_login( 7, array( 'manage_woocommerce' ) );
$halt = fh_post( $admin, 'stock_buy', array( 'sheets' => '4' ), $theirs );
check( 'with another user\'s nonce: refused', $halt && 'die' === $halt->kind && $state === $store->state() );
$halt = fh_post( $admin, 'drop_everything' );
check( 'an action that does not exist: refused (400) before anything else', $halt && 'die' === $halt->kind && 400 === $halt->status );
$halt = fh_post( $admin, 'stock_buy', array( 'sheets' => '4', 'note' => 'more' ) );
check( 'with both: done, then back to the Stock tab', $halt && 'redirect' === $halt->kind && false !== strpos( $halt->data, 'page=fishotel-misc-foam-cuts' ) && false !== strpos( $halt->data, 'tab=stock' ) && 7 === $store->sheets() );
$html = fh_page( $admin, array( 'tab' => 'stock' ) );
check( 'the next page says what happened, once', false !== strpos( $html, 'Bought 4 sheets — 7 in stock now.' ) && false === strpos( fh_page( $admin, array( 'tab' => 'stock' ) ), 'Bought 4 sheets — 7 in stock now.' ) );

echo "4. Making a plan, marking it as cut, undoing, deleting\n";
$halt = fh_post( $admin, 'plan', array( 'boxes' => array( array( 'key' => 'cube', 'count' => '0' ), array( 'key' => 'flat', 'count' => '' ) ) ) );
check( 'no numbers: nothing saved, back to the Plan tab with the reason', $halt && 'redirect' === $halt->kind && array() === $store->plans && false !== strpos( fh_page( $admin ), 'Nothing to cut' ) );
$halt = fh_post( $admin, 'plan', array( 'boxes' => array( array( 'key' => 'cube', 'count' => '2' ), array( 'key' => 'flat', 'count' => '1' ), array( 'key' => 'ghost', 'count' => '9' ), 'junk' ) ) );
check( 'numbers typed: a draft, and the page opens on it', $halt && false !== strpos( $halt->data, 'plan=1' ) && 'draft' === $store->plan( 1 )['status'] && '2 × Cube, 1 × Flat' === $store->plan( 1 )['title'] && 7 === $store->plan( 1 )['created_by'] );
$html = fh_page( $admin, array( 'plan' => '1' ) );
check( 'the open plan: its drawings, its cuts in order, its pieces, the labels to write', substr_count( $html, '<svg ' ) === count( $store->plan( 1 )['data']['sources'] ) && false !== strpos( $html, 'Cuts, in this order' ) && false !== strpos( $html, 'write the label on each' ) && false !== strpos( $html, 'The pieces per box' ) && false !== strpos( $html, 'straight across the whole sheet, top to bottom' ) );
check( '…with Print, Mark as cut and Delete — no Undo yet', false !== strpos( $html, 'Print the cut sheet' ) && false !== strpos( $html, 'name="do" value="cut"' ) && false !== strpos( $html, 'name="do" value="delete"' ) && false === strpos( $html, 'name="do" value="undo"' ) );
check( 'the print link carries a nonce for this plan', 1 === preg_match( '/admin-post\.php\?action=fishotel_foam_cuts_print(&amp;|&#038;|&)plan=1(&amp;|&#038;|&)_wpnonce=' . preg_quote( wp_create_nonce( Admin::print_nonce_action( 1 ) ), '/' ) . '"/', $html ) );

$before = $store->state();
$halt   = fh_post( $admin, 'cut', array( 'plan' => '1' ) );
check( 'Mark as cut: applied', $halt && false !== strpos( $halt->data, 'plan=1' ) && 'cut' === $store->plan( 1 )['status'] && $store->sheets() < 7 && 'used' === $store->leftover( 1 )['status'] );
$html = fh_page( $admin, array( 'plan' => '1' ) );
check( 'the page says so and now offers Undo instead', false !== strpos( $html, 'Marked as cut.' ) && false !== strpos( $html, 'name="do" value="undo"' ) && false === strpos( $html, 'name="do" value="cut"' ) && false !== strpos( $html, 'marked as cut' ) );
$after = $store->state();
$halt  = fh_post( $admin, 'cut', array( 'plan' => '1' ) );
check( 'pressed again (a second tab, a reload): nothing changes, and the page says it was already cut', $after === $store->state() && false !== strpos( fh_page( $admin, array( 'plan' => '1' ) ), 'already marked as cut' ) );
$halt = fh_post( $admin, 'delete', array( 'plan' => '1' ) );
check( 'a cut plan cannot be deleted', null !== $store->plan( 1 ) && $after === $store->state() );
$halt = fh_post( $admin, 'undo', array( 'plan' => '1' ) );
check( 'Undo: the stock is as it was, the plan a draft again', 'draft' === $store->plan( 1 )['status'] && $before['leftovers'] === $store->state()['leftovers'] && 7 === $store->sheets() );
$halt = fh_post( $admin, 'cut', array( 'plan' => '99' ) );
check( 'a plan that does not exist: said so, nothing changed', false !== strpos( fh_page( $admin ), 'does not exist any more' ) );
$halt = fh_post( $admin, 'delete', array( 'plan' => '1' ) );
check( 'Delete: the draft is gone, back on the Plan tab', null === $store->plan( 1 ) && $halt && false === strpos( $halt->data, 'plan=1' ) && false !== strpos( fh_page( $admin ), 'Plan 1 deleted' ) );

fh_post( $admin, 'plan', array( 'boxes' => array( array( 'key' => 'cube', 'count' => '1' ) ) ) );
Stock::remove_leftover( $store, 1 );
$html = fh_page( $admin, array( 'plan' => '2' ) );
check( 'a draft made for stock that has since changed says so and cannot be marked as cut', false !== strpos( $html, 'The stock has changed since this plan was made' ) && false !== strpos( $html, 'Leftover L1 is no longer in stock.' ) && 1 === preg_match( '/class="button fh-fc-cut" disabled/', $html ) );

echo "5. The stock by hand\n";
fh_post( $admin, 'stock_count', array( 'sheets' => '2', 'note' => 'counted' ) );
check_same( 'the count corrected', 2, $store->sheets() );
fh_post( $admin, 'leftover_add', array( 'length' => '22 1/2', 'width' => '9', 'note' => 'by the door' ) );
$added = end( $store->leftovers );
check( 'a leftover added: in stock under the next label, and the page says what to write on it', 'stock' === $added['status'] && fh_in( 22.5 ) === $added['length'] && false !== strpos( fh_page( $admin, array( 'tab' => 'stock' ) ), 'Write ' . $added['label'] . ' on it' ) );
fh_post( $admin, 'leftover_resize', array( 'leftover' => (string) $added['id'], 'length' => '20', 'width' => '9' ) );
check_same( 'resized', fh_in( 20 ), $store->leftover( $added['id'] )['length'] );
fh_post( $admin, 'leftover_remove', array( 'leftover' => (string) $added['id'] ) );
check_same( 'removed', 'removed', $store->leftover( $added['id'] )['status'] );
$state = $store->state();
fh_post( $admin, 'leftover_add', array( 'length' => 'long', 'width' => '9' ) );
fh_post( $admin, 'stock_buy', array( 'sheets' => array( '4' ) ) );
fh_post( $admin, 'leftover_remove', array( 'leftover' => array( '1' ) ) );
check( 'nonsense in a field changes nothing', $state === $store->state() );

echo "6. Boxes, cut lists, settings\n";
fh_post(
	$admin,
	'boxes_save',
	array(
		'own' => array(
			array( 'name' => 'Cube', 'length' => '12', 'width' => '12', 'height' => '12' ),
			array( 'name' => 'Long', 'length' => '24', 'width' => '10 1/2', 'height' => '8' ),
			array( 'name' => 'Broken', 'length' => 'x', 'width' => '10', 'height' => '8' ),
			array( 'name' => '', 'length' => '', 'width' => '', 'height' => '' ),
		),
	)
);
$saved = Settings::get();
check_same( 'the own list is replaced by what was posted', array( 'Cube', 'Long' ), array_column( $saved['boxes'], 'name' ) );
check_same( 'sizes as typed', fh_in( 10.5 ), $saved['boxes'][1]['width'] );
$html = fh_page( $admin, array( 'tab' => 'boxes' ) );
check( 'the page counts what was saved and what was left out, and why', false !== strpos( $html, '2 boxes saved.' ) && false !== strpos( $html, '1 row was left out' ) );

fh_post(
	$admin,
	'cutlist_save',
	array(
		'box'    => 'Long',
		'pieces' => array(
			array( 'name' => 'Wrap', 'length' => '60', 'width' => '7', 'count' => '1' ),
			array( 'name' => 'End', 'length' => '9 1/2', 'width' => '7', 'count' => '2' ),
			array( 'name' => '', 'length' => '', 'width' => '', 'count' => '' ),
		),
	)
);
$saved = Settings::get();
check( 'a cut list saved for a box: filed under its name, remembering the box\'s size', array( 'long' ) === array_keys( $saved['cut_lists'] ) && array( 'Wrap', 'End' ) === array_column( $saved['cut_lists']['long']['pieces'], 'name' ) && '240000x105000x80000' === $saved['cut_lists']['long']['box'] );
$html = fh_page( $admin, array( 'tab' => 'boxes' ) );
check( 'the box now shows "your cut list" and a way back to the suggestion', false !== strpos( $html, 'your cut list' ) && false !== strpos( $html, 'name="do" value="cutlist_reset"' ) && false !== strpos( $html, 'Cut list of &quot;Long&quot; saved: 3 pieces per box.' ) );
fh_post( $admin, 'cutlist_save', array( 'box' => 'Long', 'pieces' => array( array( 'name' => 'Nothing', 'length' => '', 'width' => '', 'count' => '' ) ) ) );
check( 'an empty cut list is refused and the saved one kept', array( 'Wrap', 'End' ) === array_column( Settings::get()['cut_lists']['long']['pieces'], 'name' ) );
fh_post( $admin, 'cutlist_save', array( 'box' => 'Ghost', 'pieces' => array( array( 'name' => 'A', 'length' => '5', 'width' => '5', 'count' => '1' ) ) ) );
check( 'a cut list for a box that is not on the list is refused', array( 'long' ) === array_keys( Settings::get()['cut_lists'] ) );
fh_post( $admin, 'plan', array( 'boxes' => array( array( 'key' => 'long', 'count' => '1' ) ) ) );
$plan = end( $store->plans );
check_same( 'the next plan cuts the saved list', array( 'Wrap', 'End' ), array_column( $plan['data']['boxes'][0]['pieces'], 'name' ) );
fh_post( $admin, 'cutlist_reset', array( 'box' => 'Long' ) );
check_same( 'forgotten: the suggestion is used again', array(), Settings::get()['cut_lists'] );

fh_post( $admin, 'settings_save', array( 'sheet_length' => '96', 'sheet_width' => '48', 'thickness' => '3/4', 'kerf' => '1/8', 'min_length' => '6', 'min_width' => '6', 'rotate' => '1' ) );
$saved = Settings::get();
check_same( 'settings saved', array( fh_in( 0.75 ), fh_in( 0.125 ), fh_in( 6 ), true ), array( $saved['thickness'], $saved['kerf'], $saved['min_length'], $saved['rotate'] ) );
check_same( 'saving the settings keeps the boxes', array( 'Cube', 'Long' ), array_column( $saved['boxes'], 'name' ) );
fh_post( $admin, 'settings_save', array( 'thickness' => 'thick', 'kerf' => '0' ) );
$saved = Settings::get();
check( 'a field that is not a size is reported and left as it was; the others are saved; an unticked box is a no', fh_in( 0.75 ) === $saved['thickness'] && 0 === $saved['kerf'] && false === $saved['rotate'] && false !== strpos( fh_page( $admin, array( 'tab' => 'settings' ) ), 'Thickness: &quot;thick&quot; is not a size' ) );

echo "7. The printable page: who gets it\n";
$store = new Fake_Store();
$store->add_sheet_entry( 'bought', 3, 0, '', 7 );
Stock::add_leftover( $store, '30', '20' );
$admin->store = $store;
Settings::save( array_merge( Settings::get(), array( 'kerf' => 0, 'thickness' => fh_in( 0.5 ), 'rotate' => true ) ) );
Planner::create( $store, array( 'cube' => 2, 'long' => 1 ), array(), 7 );
$plan  = $store->plan( 1 );
$nonce = wp_create_nonce( Admin::print_nonce_action( 1 ) );
$key   = Planner::print_key( 1, 7 );

check_same( 'no capability: forbidden, whatever the link carries', 'forbidden', Admin::print_access( false, $plan, 7, $nonce, $key ) );
check_same( 'nobody logged in: forbidden', 'forbidden', Admin::print_access( true, $plan, 0, $nonce, $key ) );
check_same( 'no such plan: missing', 'missing', Admin::print_access( true, null, 7, $nonce, $key ) );
check_same( 'the nonce of a link on the page: the sheet', 'ok', Admin::print_access( true, $plan, 7, $nonce, '' ) );
check_same( 'the key of the REST print_url: the sheet', 'ok', Admin::print_access( true, $plan, 7, '', $key ) );
check_same( 'neither: a button to open it, not the sheet', 'confirm', Admin::print_access( true, $plan, 7, '', '' ) );
check_same( 'a nonce for another plan', 'confirm', Admin::print_access( true, $plan, 7, wp_create_nonce( Admin::print_nonce_action( 2 ) ), '' ) );
check_same( 'a key for another user', 'confirm', Admin::print_access( true, $plan, 7, '', Planner::print_key( 1, 8 ) ) );
check_same( 'something that is not text', 'confirm', Admin::print_access( true, $plan, 7, array( $nonce ), array( $key ) ) );

fh_login( 5, array( 'read' ) );
$_GET = array( 'plan' => '1', '_wpnonce' => $nonce, 'key' => $key );
$halt = fh_stop( array( $admin, 'print_page' ) );
check( 'the page itself: a user without manage_woocommerce gets 403', $halt && 'die' === $halt->kind && 403 === $halt->status );
fh_login( 0 );
$halt = fh_stop( array( $admin, 'print_page' ) );
check( '…and so does a visitor', $halt && 'die' === $halt->kind && 403 === $halt->status );
$halt = fh_stop( array( $admin, 'login_first' ) );
check( 'a visitor who follows a print link is sent to the login screen, nothing else', $halt && 'redirect' === $halt->kind && false !== strpos( $halt->data, 'wp-login.php' ) );
fh_login( 7, array( 'manage_woocommerce' ) );
$_GET = array( 'plan' => '77', '_wpnonce' => $nonce );
$halt = fh_stop( array( $admin, 'print_page' ) );
check( 'a plan that does not exist: 404', $halt && 'die' === $halt->kind && 404 === $halt->status );
$_GET = array();

echo "8. The printable page: what is on it\n";
$html    = Admin::print_html( $plan, $store );
$sources = $plan['data']['sources'];
check( 'a document of its own, not to be indexed, landscape', 0 === strpos( ltrim( $html ), '<!DOCTYPE html>' ) && false !== strpos( $html, 'noindex' ) && false !== strpos( $html, '@page { size: letter landscape' ) && '</html>' === substr( rtrim( $html ), -7 ) );
check_same( 'one page per source, then the pieces per box', count( $sources ) + 1, substr_count( $html, '<section class="sheet' ) );
check( 'each page starts on a new sheet of paper when printed', false !== strpos( $html, '.sheet { page-break-after: always; break-after: page;' ) && false !== strpos( $html, '@media print' ) && false !== strpos( $html, '.toolbar { display: none; }' ) );
check_same( 'a drawing per source', count( $sources ), substr_count( $html, '<svg ' ) );
$labelled = true;
$cuts     = 0;
foreach ( $sources as $source ) {
	$labelled = $labelled && false !== strpos( $html, '<h1>' . esc_html( $source['label'] ) . '</h1>' );
	foreach ( $source['leftovers'] as $left ) {
		$labelled = $labelled && false !== strpos( $html, '<span class="label">' . $left['label'] . '</span>' );
	}
	foreach ( $source['cuts'] as $cut ) {
		$cuts    += false !== strpos( $html, esc_html( preg_replace( '/^[^:]+:\s*/u', '', $cut['text'] ) ) ) ? 1 : 0;
	}
}
check( 'each source under its name (the leftover\'s label, "new sheet 1"), each new leftover with the label to write on it', $labelled );
check_same( 'every cut, in words, in an ordered list', array_sum( array_map( static function ( $s ) { return count( $s['cuts'] ); }, $sources ) ), $cuts );
check( 'the leftover that is used up is to be crossed out', false !== strpos( $html, 'Cross out the old label L1' ) );
check( 'the last page: the pieces per box, with where each is cut from', false !== strpos( $html, '<h1>The pieces per box</h1>' ) && false !== strpos( $html, '<strong>2 × Cube</strong>' ) && false !== strpos( $html, '<strong>1 × Long</strong>' ) && false !== strpos( $html, 'Cut from' ) );
check( 'and what to do when the foam is cut', false !== strpos( $html, 'Mark as cut' ) );
check( 'nothing is loaded from anywhere: no script file, no style sheet, no image', 0 === preg_match( '/<script[^>]+src=|<link\b|<img\b|@import|url\((?!#fc)/i', $html ) );
check( 'the only script is the Print button', 1 === substr_count( $html, 'onclick=' ) && false !== strpos( $html, 'onclick="window.print()"' ) && 0 === substr_count( $html, '<script' ) );

$confirm = Admin::print_html( $plan, $store, true );
check( 'a link whose nonce does not check out: a button to open the sheet — and none of the sheet', false !== strpos( $confirm, 'Open the cut sheet' ) && 0 === substr_count( $confirm, '<svg ' ) && 0 === substr_count( $confirm, '<section' ) && false !== strpos( $confirm, '_wpnonce=' . $nonce ) );

Stock::apply( $store, $plan, 7 );
check( 'a plan already marked as cut prints as a reprint', false !== strpos( Admin::print_html( $store->plan( 1 ), $store ), 'this is a reprint' ) );
Planner::create( $store, array( 'cube' => 1 ), array(), 7 );
$next = $store->plan( 2 );
$used = array_keys( Planner::export( $next, $store )['sources'] ? \FisHotel\Misc\Sections\Foam_Cuts\Plan::used_leftovers( $next['data'] ) : array() );
check( 'the next plan cuts into a leftover the first one made', count( $used ) > 0 );
Stock::remove_leftover( $store, $used[0] );
check( 'a plan made for stock that has since changed prints with DO NOT CUT across the top', false !== strpos( Admin::print_html( $next, $store ), 'DO NOT CUT FROM THIS SHEET' ) );

echo "9. The numbers filled in from the orders of a day\n";
$GLOBALS['fh_suggest'] = static function ( $order ) {
	return 401 === $order->get_id() ? 'Cube' : ( 402 === $order->get_id() ? array( 'box' => 'Long' ) : null );
};
if ( ! function_exists( 'fishotel_packing_suggest' ) ) { // Inside an if: declared when this line is reached.
	function fishotel_packing_suggest( $order ) {
		return call_user_func( $GLOBALS['fh_suggest'], $order );
	}
}
$GLOBALS['fh_orders'] = array(
	401 => new WC_Order( 401, 'processing', array( '_fishotel_shipping_date' => '2026-03-11' ) ),
	402 => new WC_Order( 402, 'processing', array( '_fishotel_shipping_date' => '2026-03-11' ) ),
	403 => new WC_Order( 403, 'processing', array( '_fishotel_shipping_date' => '2026-03-11' ) ),
);
$store->calls = array();
$html         = fh_page( $admin, array( 'ship' => '2026-03-10' ) );
check( 'the Plan tab lists the orders of that day by number, with their boxes', false !== strpos( $html, '3 orders ship on 2026-03-10 (delivery 2026-03-11)' ) && false !== strpos( $html, '<strong>#401</strong>' ) && false !== strpos( $html, '1 × Cube' ) && false !== strpos( $html, 'no box yet' ) && false !== strpos( $html, '1 order has no box yet' ) );
check( 'the numbers are filled in and the form remembers where they came from', 1 === preg_match( '/name="boxes\[0\]\[count\]"[^>]*value="1"/', $html ) && false !== strpos( $html, 'name="origin" value="orders"' ) && false !== strpos( $html, 'name="ship_date" value="2026-03-10"' ) );
check( 'looking the orders up wrote nothing', array() === $store->calls );
$halt = fh_post( $admin, 'plan', array( 'origin' => 'orders', 'ship_date' => '2026-03-10', 'boxes' => array( array( 'key' => 'cube', 'count' => '2' ), array( 'key' => 'long', 'count' => '1' ) ) ) );
$plan = end( $store->plans );
check( 'the plan is made from the numbers as posted (changed by hand: 2 cubes) and remembers the day and its orders', $halt && 'redirect' === $halt->kind && '2 × Cube, 1 × Long' === $plan['title'] && 'orders' === $plan['origin'] && '2026-03-10' === $plan['ship_date'] && array( 401, 402, 403 ) === array_column( $plan['data']['for']['orders'], 'id' ) );
check( 'the printed sheet names the orders it is for — by number only', false !== strpos( Admin::print_html( $plan, $store ), 'For the orders shipping 2026-03-10 (delivery 2026-03-11):' ) && false !== strpos( Admin::print_html( $plan, $store ), '#401 1 × Cube' ) );

echo "10. Small things\n";
check_same( 'the sheet count is never shown below 0', array( 0, 5 ), array( Admin::sheets_shown( -3 ), Admin::sheets_shown( 5 ) ) );
check_same(
	'sheet entries in words',
	array( 'Bought 6 sheets', 'Plan 4 took 2 sheets', 'Plan 4 undone: 1 sheet back', 'Count corrected: 3 fewer', 'Count corrected: 1 more' ),
	array(
		Admin::entry_text( array( 'kind' => 'bought', 'sheets' => 6, 'plan_id' => 0 ) ),
		Admin::entry_text( array( 'kind' => 'plan', 'sheets' => -2, 'plan_id' => 4 ) ),
		Admin::entry_text( array( 'kind' => 'undo', 'sheets' => 1, 'plan_id' => 4 ) ),
		Admin::entry_text( array( 'kind' => 'count', 'sheets' => -3, 'plan_id' => 0 ) ),
		Admin::entry_text( array( 'kind' => 'count', 'sheets' => 1, 'plan_id' => 0 ) ),
	)
);
$groups = Admin::group_pieces( $sources[ count( $sources ) - 1 ]['pieces'] );
check( 'the pieces of a source are listed grouped: how many × box · piece — size', $groups && array( 'count', 'box', 'name', 'size' ) === array_keys( $groups[0] ) && count( $sources[ count( $sources ) - 1 ]['pieces'] ) === array_sum( array_column( $groups, 'count' ) ) );
check_same( 'a tab\'s address', 'https://example.test/wp-admin/admin.php?page=fishotel-misc-foam-cuts&tab=stock', Admin::page_url( 'stock' ) );
$GLOBALS['fh_styles'] = array();
$admin->enqueue_assets( 'toplevel_page_fishotel-misc' );
$admin->enqueue_assets( 'edit.php' );
check_same( 'the style sheet is not loaded on other screens', array(), $GLOBALS['fh_styles'] );
$admin->enqueue_assets( 'fishotel-tools_page_fishotel-misc-foam-cuts' );
check( '…only on this one', array( 'fishotel-foam-cuts-admin' ) === array_keys( $GLOBALS['fh_styles'] ) && is_file( dirname( __DIR__ ) . '/css/foam-cuts-admin.css' ) );

fh_finish();
