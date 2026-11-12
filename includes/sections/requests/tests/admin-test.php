<?php
/**
 * FisHotel Tools → Requests: every operation of the page's one write
 * action (approve a sheet, decline, add by hand — owed or with a fee —,
 * make and switch a link, edit, note, paid by hand, cancel, reopen, the
 * daily check, the settings behind their own capability), what the list
 * shows for a state / a view / a page, where a handled form may go back
 * to, the "Requested by" line of the Clean Room's supplier order page,
 * the My Account rewrite check — and every tab printed once, with no
 * PHP notice and nothing unescaped.
 *
 * Run from the plugin root:  php includes/sections/requests/tests/admin-test.php
 */

require_once __DIR__ . '/wp-stubs.php';
require_once FISHOTEL_MISC_PATH . 'includes/sections/requests/class-requests.php';
require_once FISHOTEL_MISC_PATH . 'includes/sections/requests/class-admin.php';

use FisHotel\Misc\Sections\Requests\Admin;
use FisHotel\Misc\Sections\Requests\Cron;
use FisHotel\Misc\Sections\Requests\Fees;
use FisHotel\Misc\Sections\Requests\Listing;
use FisHotel\Misc\Sections\Requests\Requests;
use FisHotel\Misc\Sections\Requests\Rules;
use FisHotel\Misc\Sections\Requests\Settings;
use FisHotel\Misc\Sections\Requests\Store;
use FisHotel\Misc\Sections\Requests\Tokens;

function wp_nonce_field( $action = -1, $name = '_wpnonce', $referer = true, $echo = true ) {
	$field = '<input type="hidden" name="' . esc_attr( $name ) . '" value="' . esc_attr( wp_create_nonce( $action ) ) . '">';
	if ( $echo ) {
		echo $field;
	}
	return $field;
}

Requests::load();

$GLOBALS['fh_users'] = array(
	1 => array( 'login' => 'shopkeeper', 'email' => 'shop@example.test', 'display' => 'Shop Keeper' ),
	7 => array( 'login' => 'pat', 'email' => 'pat@example.test', 'first' => 'Pat', 'last' => 'Example' ),
);
$GLOBALS['fh_user_caps']    = array( 'manage_woocommerce' => array( 1 ), 'manage_options' => array( 1 ) );
$GLOBALS['fh_current_user'] = 1;
update_option( 'fishotel_misc_enabled_sections', array( 'requests' ) );
update_option( Store::DB_VERSION_OPTION, Store::DB_VERSION );

$service = fh_service();
$store   = $service->store;
Requests::use_service( $service );

/**
 * The context perform() gets from the page.
 */
function fh_ctx( $service, array $more = array() ) {
	return array_merge(
		array(
			'service'      => $service,
			'store'        => $service->store,
			'settings'     => $service->settings,
			'actor'        => fh_staff(),
			'cleanroom'    => null,
			'sizes'        => null,
			'fees'         => $service->fees(),
			'can_settings' => true,
			'tables_ok'    => true,
			'now'          => $GLOBALS['fh_clock'],
		),
		$more
	);
}

/* ================================================================== */
echo "The one write action\n";
/* ================================================================== */

check( 'an unknown operation does nothing', ! Admin::perform( 'drop_everything', array(), fh_ctx( $service ) )['ok'] );
check_same( 'twenty operations, the settings alone behind their own capability', array( 20, array( 'settings' ) ), array( count( Admin::OPS ), array_keys( array_filter( Admin::OPS ) ) ) );

echo "\nAdd by hand\n";
fh_take_mails();
$result = Admin::perform( 'add', array( 'name' => 'Lee Local', 'fish' => 'Blue Test Wrasse', 'qty' => '2', 'came_from' => 'in person', 'size_label' => 'about 3 inches' ), fh_ctx( $service ) );
check( 'a request by hand is saved', $result['ok'] );
$lee = $store->find( array( 'search' => 'Lee Local' ) )[0];
check_same( 'on the list, by hand, free-text size (no standard list here)', array( 'waiting', 'hand', 'in person', 'about 3 inches' ), array( $lee['state'], $lee['source'], $lee['came_from'], $lee['size_label'] ) );
check_contains( 'the notice names it and its state', 'Request #' . $lee['id'] . ' is saved (waiting)', $result['message'] );
check_same( 'nobody is e-mailed', array(), fh_take_mails() );

$result = Admin::perform( 'add', array( 'user_id' => '7', 'fish' => 'Green Test Tang', 'owed' => '1', 'fee' => '50' ), fh_ctx( $service ) );
$owed   = $store->find( array( 'search' => 'Green Test Tang' ) )[0];
check_same( 'ticked "a replacement for a lost fish": owed, never a fee, on the list', array( true, 1, 'lost_fish', 0.0, 'waiting' ), array( $result['ok'], $owed['owed'], $owed['source'], $owed['fee_amount'], $owed['state'] ) );

$result = Admin::perform( 'add', array( 'name' => 'Lee Local', 'email' => 'lee@example.test', 'fish' => 'Red Test Goby', 'fee' => '12.50' ), fh_ctx( $service ) );
$paid   = $store->find( array( 'search' => 'Red Test Goby' ) )[0];
check_same( 'with a fee it waits for its payment and the invoice goes out', array( true, 'awaiting_payment', 1 ), array( $result['ok'], $paid['state'], count( fh_take_mails() ) ) );
check_contains( 'and the notice says the invoice was e-mailed', 'The invoice was e-mailed', $result['message'] );

$sized  = fh_ctx( $service, array( 'sizes' => array( array( 'slug' => '1-2', 'label' => '1 - 2"' ) ) ) );
$result = Admin::perform( 'add', array( 'name' => '', 'fish' => '', 'size_slug' => 'huge', 'note' => 'keep me' ), $sized );
check( 'a form with mistakes saves nothing', ! $result['ok'] && 3 === count( $store->rows ) );
check_same( 'and hands its values back, to show again', 'keep me', $result['keep']['note'] );
check( 'never the size list', ! isset( $result['keep']['sizes'] ) );

echo "\nApprove and decline\n";
$link  = $store->link( $store->insert_link( array( 'token' => Tokens::link(), 'label' => 'Test batch', 'fee_amount' => 12.0 ) ) );
$sheet = $service->submit( array( 'name' => 'Noah Newcomer', 'email' => 'noah@example.test' ), array( array( 'fish' => 'Purple Test Dottyback' ), array( 'fish' => 'Orange Test Anthias' ), array( 'fish' => 'Yellow Test Blenny' ) ), array( 'source' => 'link', 'link' => $link, 'fee' => 12.0 ) )['ids'];
fh_take_mails();

check( 'approving nothing is refused', ! Admin::perform( 'approve', array(), fh_ctx( $service ) )['ok'] );
$result = Admin::perform( 'approve', array( 'ids' => array( (string) $sheet[0], (string) $sheet[1] ), 'fee' => '12' ), fh_ctx( $service ) );
check_same( 'the ticked requests of a sheet become one invoice', array( true, 'awaiting_payment', 'awaiting_payment', 'new', 1 ), array( $result['ok'], $store->get( $sheet[0] )['state'], $store->get( $sheet[1] )['state'], $store->get( $sheet[2] )['state'], count( fh_take_mails() ) ) );
$result = Admin::perform( 'decline', array( 'id' => $sheet[2], 'note' => 'Not this year.', 'send' => '1' ), fh_ctx( $service ) );
check_same( 'the third is declined, with an e-mail because it was ticked', array( true, 'cancelled', 1 ), array( $result['ok'], $store->get( $sheet[2] )['state'], count( fh_take_mails() ) ) );
$single = $service->submit( array( 'name' => 'Noah Newcomer', 'email' => 'noah@example.test' ), array( array( 'fish' => 'Pink Test Basslet' ) ), array( 'source' => 'link', 'link' => $link ) )['ids'][0];
check_same( 'one request approved from its own page, no fee', array( true, 'waiting' ), array( Admin::perform( 'approve', array( 'id' => $single, 'fee' => '' ), fh_ctx( $service ) )['ok'], $store->get( $single )['state'] ) );

echo "\nThe invoice\n";
check( 'the invoice is sent again', Admin::perform( 'resend', array( 'id' => $sheet[1] ), fh_ctx( $service ) )['ok'] && 1 === count( fh_take_mails() ) );
check_same( 'the fee is changed', array( true, 15.0 ), array( Admin::perform( 'set_fee', array( 'id' => $sheet[0], 'fee' => '15' ), fh_ctx( $service ) )['ok'], $store->get( $sheet[0] )['fee_amount'] ) );
fh_take_mails();
check( '"paid some other way" on a request that is not waiting for a payment is refused', ! Admin::perform( 'mark_paid', array( 'id' => $single ), fh_ctx( $service ) )['ok'] );
$result = Admin::perform( 'mark_paid', array( 'id' => $sheet[1] ), fh_ctx( $service ) );
check_same( 'paid some other way: the whole invoice is on the list', array( true, 'waiting', 'waiting' ), array( $result['ok'], $store->get( $sheet[0] )['state'], $store->get( $sheet[1] )['state'] ) );
check_contains( 'and the history says nothing went to a wallet from here', 'nothing was added to a wallet', implode( ' | ', $store->notes( $sheet[0] ) ) );

echo "\nEdit, note, close\n";
check_same( 'an edit', array( true, 3 ), array( Admin::perform( 'edit', array( 'id' => $lee['id'], 'name' => 'Lee Local', 'fish' => 'Blue Test Wrasse', 'qty' => '3', 'size_label' => 'about 3 inches', 'came_from' => 'in person' ), fh_ctx( $service ) )['ok'], $store->get( $lee['id'] )['qty'] ) );
check( 'a note', Admin::perform( 'note', array( 'id' => $lee['id'], 'text' => 'Called again.' ), fh_ctx( $service ) )['ok'] );
check_same( 'cancel, then reopen', array( 'cancelled', 'waiting' ), array( Admin::perform( 'cancel', array( 'id' => $lee['id'] ), fh_ctx( $service ) )['ok'] ? $store->get( $lee['id'] )['state'] : '', Admin::perform( 'reopen', array( 'id' => $lee['id'] ), fh_ctx( $service ) )['ok'] ? $store->get( $lee['id'] )['state'] : '' ) );
check_same( 'filled', 'filled', Admin::perform( 'fill', array( 'id' => $lee['id'] ), fh_ctx( $service ) )['ok'] ? $store->get( $lee['id'] )['state'] : '' );
$store->update( $single, array( 'state' => 'ready' ) );
check_same( 'told some other way', 'offered', Admin::perform( 'told', array( 'id' => $single ), fh_ctx( $service ) )['ok'] ? $store->get( $single )['state'] : '' );
$store->update( $owed['id'], array( 'state' => 'ready' ) );
check_same( 'tell them: the e-mail goes out', array( 'offered', 1 ), array( Admin::perform( 'tell', array( 'id' => $owed['id'] ), fh_ctx( $service ) )['ok'] ? $store->get( $owed['id'] )['state'] : '', count( fh_take_mails() ) ) );

echo "\nMatching by hand\n";
$result = Admin::perform( 'link', array( 'id' => $sheet[0], 'line' => '3:9' ), fh_ctx( $service ) );
check( 'without the Clean Room there is nothing to match to, and it says so', ! $result['ok'] && false !== strpos( $result['message'], 'Clean Room' ) );
check_same( 'switching "match by itself" off and on', array( 0, 1 ), array( Admin::perform( 'auto', array( 'id' => $sheet[0] ), fh_ctx( $service ) )['ok'] ? $store->get( $sheet[0] )['auto_match'] : -1, Admin::perform( 'auto', array( 'id' => $sheet[0], 'on' => '1' ), fh_ctx( $service ) )['ok'] ? $store->get( $sheet[0] )['auto_match'] : -1 ) );
check( 'a request that is not on a supplier order cannot be taken off one', ! Admin::perform( 'unlink', array( 'id' => $sheet[0] ), fh_ctx( $service ) )['ok'] );

echo "\nPrivate links\n";
$result = Admin::perform( 'link_create', array( 'label' => '', 'fee' => 'lots', 'expires' => '2026-13-40', 'max_uses' => 'x' ), fh_ctx( $service ) );
check( 'a link with mistakes is not made', ! $result['ok'] && 1 === count( $store->links ) );
check_same( 'and its form comes back as typed', 'lots', $result['keep']['fee'] );
$result = Admin::perform( 'link_create', array( 'label' => 'Autumn batch', 'fee' => '9', 'expires' => '2026-12-31', 'max_uses' => '10' ), fh_ctx( $service ) );
$made   = $store->link( 2 );
check_same( 'a link is made: label, fee, uses, who', array( true, 'Autumn batch', 9.0, 10, 1 ), array( $result['ok'], $made['label'], $made['fee_amount'], $made['max_uses'], $made['created_by'] ) );
check( 'with a token nobody could guess', Tokens::is_link( $made['token'] ) && $made['token'] !== $link['token'] );
check_same( 'switched off', array( true, false ), array( Admin::perform( 'link_toggle', array( 'link_id' => 2 ), fh_ctx( $service ) )['ok'], $store->link( 2 )['active'] ) );
check_same( 'and on again', array( true, true ), array( Admin::perform( 'link_toggle', array( 'link_id' => 2, 'active' => '1' ), fh_ctx( $service ) )['ok'], $store->link( 2 )['active'] ) );
check( 'a link that does not exist cannot be switched', ! Admin::perform( 'link_toggle', array( 'link_id' => 99 ), fh_ctx( $service ) )['ok'] );

echo "\nThe daily check and the settings\n";
$result = Admin::perform( 'run', array(), fh_ctx( $service ) );
check( 'the daily check runs from the page', $result['ok'] );
check_contains( 'and says what it did', 'Clean Room is off', $result['message'] );
check( 'and leaves its summary for the next page', is_array( get_option( Cron::LAST_RUN_OPTION ) ) && ! empty( get_option( Cron::LAST_RUN_OPTION )['at'] ) );

$post   = array( 'settings' => array( 'auto_offer' => '', 'reminder_days' => '2', 'expire_days' => '5', 'ask_months' => '6', 'came_from' => "club\nshow", 'mail' => array( 'invoice' => array( 'on' => '1', 'subject' => 'Pay', 'body' => 'Body {pay_url}' ) ) ) );
$result = Admin::perform( 'settings', $post, fh_ctx( $service, array( 'can_settings' => false ) ) );
check( 'the settings are refused without their capability', ! $result['ok'] && array() === get_option( Settings::OPTION_KEY, array() ) );
$result = Admin::perform( 'settings', $post, fh_ctx( $service ) );
$saved  = Settings::get();
check_same( 'and saved with it', array( true, 0, 2, 5, 6, array( 'club', 'show' ), 'Pay' ), array( $result['ok'], $saved['auto_offer'], $saved['reminder_days'], $saved['expire_days'], $saved['ask_months'], $saved['came_from'], $saved['mail']['invoice']['subject'] ) );
check( 'saving the same settings again is not an error', Admin::perform( 'settings', $post, fh_ctx( $service ) )['ok'] );
delete_option( Settings::OPTION_KEY );
Requests::use_service( $service );

/* ================================================================== */
echo "\nWhere a form goes back to\n";
/* ================================================================== */

$base = admin_url( 'admin.php?page=' . Requests::MENU_SLUG );
check_same( 'one of the page\'s own addresses', $base . '&tab=links', Admin::back_url( $base . '&tab=links' ) );
check_same( 'the page itself', $base, Admin::back_url( $base ) );
foreach ( array( 'https://evil.example/', $base . '-evil', admin_url( 'admin.php?page=other' ), array( $base ), '', "$base\r\nX: y" ) as $fh_bad ) {
	check_same( 'anything else goes to the list: ' . fh_test_export( $fh_bad ), Admin::page_url(), Admin::back_url( $fh_bad ) );
}

/* ================================================================== */
echo "\nThe list\n";
/* ================================================================== */

$open    = $store->find( array( 'state' => Rules::OPEN_STATES ) );
$none    = array( 'state' => '', 'source' => '', 'came_from' => '', 'search' => '', 'line_id' => 0 );
$default = Admin::listing( $store, $open, $none, 'groups', '', 'asc', 1, $GLOBALS['fh_clock'] );
check_same( 'by default: the open requests, grouped by fish', array( count( $open ), 0, true ), array( $default['total'], count( $default['rows'] ), count( $default['groups'] ) > 0 ) );
check( 'closed ones are not in it', ! in_array( 'filled', array_column( array_merge( ...array_column( $default['groups'], 'rows' ) ), 'state' ), true ) );
$everything = Admin::listing( $store, $open, array_merge( $none, array( 'state' => 'all' ) ), 'list', 'created', 'asc', 1, $GLOBALS['fh_clock'] );
check_same( '"everything" reads the closed ones too', count( $store->rows ), $everything['total'] );
$closed = Admin::listing( $store, $open, array_merge( $none, array( 'state' => 'closed' ) ), 'list', 'created', 'asc', 1, $GLOBALS['fh_clock'] );
check_same( '"closed" reads only them', array( 'filled', 'cancelled' ), array_values( array_unique( array_column( Listing::sort( $closed['rows'], 'state', 'asc' ), 'state' ) ) ) );
$one = Admin::listing( $store, $open, array_merge( $none, array( 'state' => 'offered' ) ), 'list', 'name', 'desc', 1, $GLOBALS['fh_clock'] );
check_same( 'one state', array( 'offered' ), array_values( array_unique( array_column( $one['rows'], 'state' ) ) ) );
$searched = Admin::listing( $store, $open, array_merge( $none, array( 'search' => 'noah' ) ), 'groups', 'name', 'asc', 1, $GLOBALS['fh_clock'] );
check( 'a search narrows the groups', $searched['total'] > 0 && $searched['total'] < $default['total'] );
$paged = Admin::listing( $store, $open, $none, 'list', 'created', 'asc', 99, $GLOBALS['fh_clock'] );
check_same( 'a page past the end is the last page', array( 1, 1 ), array( $paged['pages'], $paged['paged'] ) );

/* ================================================================== */
echo "\nRequested by, on the Clean Room's supplier order page\n";
/* ================================================================== */

check_same( 'names, with the quantity when it is more than one', 'Pat Example (2), reef_lee, lee@example.test', Admin::requested_by_words( array( array( 'name' => 'Pat Example', 'qty' => 2 ), array( 'name' => '', 'handle' => 'reef_lee', 'qty' => 1 ), array( 'name' => '', 'handle' => '', 'email' => 'lee@example.test', 'qty' => 1 ) ) ) );
check_same( 'nobody asked: nothing', '', Admin::requested_by_words( array() ) );

$store->update( $sheet[0], array( 'state' => 'on_order', 'line_id' => 9, 'cr_order_id' => 3 ) );
$store->update( $sheet[1], array( 'state' => 'in_quarantine', 'line_id' => 9, 'cr_order_id' => 3 ) );
$store->update( $paid['id'], array( 'state' => 'cancelled', 'line_id' => 9, 'cr_order_id' => 3 ) );
$admin = new Admin();
ob_start();
$admin->requested_by( array( 'id' => 9, 'order_id' => 3 ) );
$line = (string) ob_get_clean();
check_contains( 'a line with requests reads "Requested by"', 'Requested by:', $line );
check_same( 'the two that are matched to it, not the cancelled one', 2, substr_count( $line, 'Noah Newcomer' ) );
check_contains( 'linked to the list of that line', 'line=9', $line );
ob_start();
$admin->requested_by( array( 'id' => 10, 'order_id' => 3 ) );
$admin->requested_by( array() );
$admin->requested_by( 'junk' );
check_same( 'another line, the template row and junk print nothing', '', (string) ob_get_clean() );
$GLOBALS['fh_current_user'] = 7;
ob_start();
$admin->requested_by( array( 'id' => 9, 'order_id' => 3 ) );
check_same( 'somebody who may not see requests sees none', '', (string) ob_get_clean() );
$GLOBALS['fh_current_user'] = 1;

/* ================================================================== */
echo "\nThe My Account rewrite rule\n";
/* ================================================================== */

check( 'rules that know the endpoint', Admin::endpoint_known( array( '(.?.+?)/orders(/(.*))?/?$' => 'x', '(.?.+?)/fish-requests(/(.*))?/?$' => 'y' ) ) );
check( 'rules that do not: they are rebuilt once', ! Admin::endpoint_known( array( '(.?.+?)/orders(/(.*))?/?$' => 'x' ) ) );
check( 'plain permalinks have no rules to rebuild', Admin::endpoint_known( '' ) && Admin::endpoint_known( array() ) && Admin::endpoint_known( false ) );

/* ================================================================== */
echo "\nEvery tab, printed once\n";
/* ================================================================== */

$noise = array();
set_error_handler(
	static function ( $no, $message, $file, $line ) use ( &$noise ) {
		$noise[] = basename( $file ) . ':' . $line . ' ' . $message;
		return true;
	}
);

/**
 * Print one admin view with what render_page() gives it.
 */
function fh_admin_view( $file, array $vars ) {
	extract( $vars ); // phpcs:ignore WordPress.PHP.DontExtract.extract_extract -- a test helper.
	ob_start();
	include FISHOTEL_MISC_PATH . 'includes/sections/requests/views/' . $file;
	return (string) ob_get_clean();
}

$evil = $service->create( array( 'name' => 'Eve <script>alert(1)</script>', 'email' => 'eve@example.test', 'handle' => '"><img src=x>', 'fish' => 'Evil <b>Fish</b>', 'note' => "line one\n<script>x</script>" ), fh_staff() );
$open = $store->find( array( 'state' => Rules::OPEN_STATES ) );
$ctx  = fh_ctx( $service, array( 'fees' => array( 'available' => true, 'reason' => '', 'product_id' => 9001, 'product_title' => 'Test Wallet Refill' ) ) );
$base = array(
	'ctx'   => $ctx,
	'flash' => array( 'message' => 'Saved <b>ok</b>', 'ok' => true, 'keep' => array() ),
	'needs' => Listing::needs_you( $open, $GLOBALS['fh_clock'] + 5 * 86400, $service->settings ),
);
$labels = array();
foreach ( $store->links() as $fh_link ) {
	$labels[ $fh_link['id'] ] = $fh_link['label'];
}
$fresh = $service->submit( array( 'name' => 'Noah Newcomer', 'email' => 'noah@example.test' ), array( array( 'fish' => 'Silver Test Chromis', 'note' => 'two if you can' ) ), array( 'source' => 'link', 'link' => $link, 'fee' => 12.0 ) )['ids'][0];
$open  = $store->find( array( 'state' => Rules::OPEN_STATES ) );
$needs = Listing::needs_you( $open, $GLOBALS['fh_clock'], $service->settings );

foreach ( array( 'groups', 'list' ) as $fh_view_mode ) {
	$html = fh_admin_view(
		'admin-list.php',
		array_merge(
			$base,
			array(
				'tab'         => 'list',
				'needs'       => $needs,
				'sheets'      => Listing::sheets( $needs['approve'] ),
				'listing'     => Admin::listing( $store, $open, $none, $fh_view_mode, '', 'asc', 1, $GLOBALS['fh_clock'] ),
				'filters'     => $none,
				'view'        => $fh_view_mode,
				'sort'        => '',
				'dir'         => 'asc',
				'link_labels' => $labels,
				'came_froms'  => $service->settings['came_from'],
				'last_run'    => 'nothing moved',
				'last_at'     => $GLOBALS['fh_clock'],
			)
		)
	);
	check_contains( "the list ($fh_view_mode): the Needs-you strip with the sheet to approve", 'Silver Test Chromis', $html );
	check_contains( "the list ($fh_view_mode): the fee its link proposed, ready in the field", 'name="fee" value="12.00"', $html );
	check_lacks( "the list ($fh_view_mode): nothing typed by a customer reaches the page unescaped", '<script>alert', $html );
	check_lacks( "the list ($fh_view_mode): nor a handle", '"><img', $html );
	check_contains( "the list ($fh_view_mode): the notice, escaped", 'Saved &lt;b&gt;ok&lt;/b&gt;', $html );
	check_contains( "the list ($fh_view_mode): every write form carries the nonce", 'name="_wpnonce" value="' . wp_create_nonce( Admin::NONCE ) . '"', $html );
}
check_contains( 'the grouped view: one line per fish', '<details class="fh-rq-group"', fh_admin_view( 'admin-list.php', array_merge( $base, array( 'tab' => 'list', 'needs' => $needs, 'sheets' => array(), 'listing' => Admin::listing( $store, $open, $none, 'groups', '', 'asc', 1, $GLOBALS['fh_clock'] ), 'filters' => $none, 'view' => 'groups', 'sort' => '', 'dir' => 'asc', 'link_labels' => $labels, 'came_froms' => array(), 'last_run' => '', 'last_at' => 0 ) ) ) );

$unavailable = array_merge( $ctx, array( 'fees' => array( 'available' => false, 'reason' => 'Batch Manager is not active', 'product_id' => 0, 'product_title' => '' ) ) );
foreach ( array( $fresh, $sheet[0], $evil, $lee['id'], $owed['id'], $sheet[2] ) as $fh_id ) {
	$request = $store->get( $fh_id );
	foreach ( array( $ctx, $unavailable ) as $fh_context ) {
		$html = fh_admin_view(
			'admin-request.php',
			array_merge(
				$base,
				array(
					'tab'        => 'list',
					'ctx'        => $fh_context,
					'missing'    => false,
					'request'    => $request,
					'detail'     => Admin::request_detail( $request, $fh_context ),
					'came_froms' => $service->settings['came_from'],
				)
			)
		);
		check_contains( 'request #' . $fh_id . ' (' . $request['state'] . ', fees ' . ( $fh_context['fees']['available'] ? 'on' : 'off' ) . '): its page prints, with its history', 'History', $html );
		check_lacks( 'request #' . $fh_id . ': nothing unescaped', '<script>', $html );
	}
}
$html = fh_admin_view( 'admin-request.php', array_merge( $base, array( 'tab' => 'list', 'missing' => true ) ) );
check_contains( 'a request that does not exist says so', 'does not exist', $html );
$html = fh_admin_view( 'admin-request.php', array_merge( $base, array( 'tab' => 'list', 'ctx' => $unavailable, 'missing' => false, 'request' => $store->get( $fresh ), 'detail' => Admin::request_detail( $store->get( $fresh ), $unavailable ), 'came_froms' => array() ) ) );
check_contains( 'while fees are unavailable the screen says why', 'Fees are unavailable: Batch Manager is not active', $html );
check_contains( 'and the fee field is switched off', 'name="fee" value="" placeholder="0.00" inputmode="decimal"  disabled="disabled"', $html );
check_contains( 'and it says the Clean Room is off', 'Clean Room section is switched off', $html );

$html = fh_admin_view( 'admin-add.php', array_merge( $base, array( 'tab' => 'add', 'keep' => array( 'name' => 'Kept "Name"', 'owed' => 1, 'user_id' => 7 ), 'came_froms' => $service->settings['came_from'] ) ) );
check_contains( 'Add: a refused form comes back as typed', 'value="Kept &quot;Name&quot;"', $html );
check_contains( 'Add: the owed box stays ticked', 'name="owed" value="1"  checked="checked"', $html );
$html = fh_admin_view( 'admin-links.php', array_merge( $base, array( 'tab' => 'links', 'keep' => array(), 'links' => $store->links() ) ) );
check_contains( 'Links: each link to copy', 'data-copy="https://example.test/?fh_request=' . $link['token'] . '"', $html );
check_contains( 'Links: how many requests came through', 'Test batch', $html );
$html = fh_admin_view( 'admin-demand.php', array_merge( $base, array( 'tab' => 'demand', 'demand' => Listing::demand( $store->demand(), array( 'years' => array( 2026, 2025 ), 'rows' => array( 't:blue test wrasse' => array( 'species' => 'Blue Test Wrasse', 'species_id' => null, 'years' => array( 2025 => 4 ), 'total' => 4 ) ) ) ), 'longest' => Listing::longest( $open, $GLOBALS['fh_clock'], 5 ) ) ) );
check_contains( 'Demand: the years of past supplier orders', '<th scope="col" class="fh-rq-num">2025</th>', $html );
check_contains( 'Demand: who has waited longest', 'Who has waited longest', $html );
check_lacks( 'Demand: nothing unescaped', '<b>Fish</b>', $html );
$html = fh_admin_view( 'admin-settings.php', array_merge( $base, array( 'tab' => 'settings', 'labels' => Settings::mail_labels() ) ) );
check_same( 'Settings: five e-mails, each with a switch, a subject and a body', array( 5, 5, 5 ), array( substr_count( $html, '][on]"' ), substr_count( $html, '][subject]"' ), substr_count( $html, '][body]"' ) ) );
check_contains( 'Settings: the public switch, off', 'name="settings[public_open]" value="1" >', str_replace( '  ', ' ', $html ) );
check_contains( 'Settings: "tell people by themselves", on', 'name="settings[auto_offer]" value="1"  checked="checked"', $html );

restore_error_handler();
check_same( 'printing every tab raised no PHP notice or warning', array(), $noise );

Requests::use_service( null );
fh_finish();
