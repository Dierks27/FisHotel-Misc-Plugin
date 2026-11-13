<?php
/**
 * The admin screen: every write is nonce first, then capability, then
 * mode (Shadow and Off refuse every card change); each operation of
 * perform(); the owner alone sees a full code (logged) and exports the
 * log; settings and the mode are logged; the Live guard; the list's
 * filters, search and sort; every tab printed with no PHP notice, nothing
 * unescaped and no full code for staff.
 *
 * Run from the plugin root:  php includes/sections/gift-cards/tests/admin-test.php
 */

require_once __DIR__ . '/wp-stubs.php';
require_once __DIR__ . '/wc-stubs.php';
require_once FISHOTEL_MISC_PATH . 'includes/sections/gift-cards/class-admin.php';

use FisHotel\Misc\Sections\Gift_Cards\Admin;
use FisHotel\Misc\Sections\Gift_Cards\Gift_Cards;
use FisHotel\Misc\Sections\Gift_Cards\Importer;
use FisHotel\Misc\Sections\Gift_Cards\Modes;
use FisHotel\Misc\Sections\Gift_Cards\Service;
use FisHotel\Misc\Sections\Gift_Cards\Store;

function get_users( $args = array() ) {
	return array();
}

$GLOBALS['fh_users'][1]           = array( 'login' => 'owner', 'display' => 'Test Owner', 'email' => 'owner@example.test' );
$GLOBALS['fh_users'][2]           = array( 'login' => 'helper', 'display' => 'Shop Helper', 'email' => 'helper@example.test' );
$GLOBALS['fh_users'][9]           = array( 'login' => 'cust', 'display' => 'A Customer', 'email' => 'cust@example.test' );
$GLOBALS['fh_user_caps']['manage_options']     = array( 1 );
$GLOBALS['fh_user_caps']['manage_woocommerce'] = array( 1, 2 );

function fh_ctx( $s, $user, $mode ) {
	$GLOBALS['fh_current_user'] = $user;
	return array(
		'service'  => $s,
		'mode'     => $mode,
		'modes'    => array( 'stored' => $mode, 'effective' => $mode, 'legacy' => false, 'fallback' => false ),
		'can_edit' => user_can( $user, (string) $s->settings['editor_capability'] ) && user_can( $user, 'manage_woocommerce' ),
		'can_own'  => user_can( $user, 'manage_options' ),
		'audit'    => array( 'user_id' => $user, 'name' => 'user ' . $user, 'source' => 'admin', 'screen' => 'gift-cards/test', 'ip' => '203.0.113.9' ),
		'importer' => new Importer( $s->store, $s ),
		'ready'    => Store::ready(),
	);
}

function fh_issue_post( array $more = array() ) {
	return array_merge( array( 'amount' => '25', 'recipient_name' => 'Pat', 'recipient_email' => 'pat@example.test', 'delivery' => 'none', 'request_key' => Service::new_request_key() ), $more );
}

echo "Nonce first, then capability, then mode\n";
fh_wc_reset();
$s = fh_gc_use();
Gift_Cards::use_service( $s );
$GLOBALS['fh_current_user'] = 9; // a customer, no capability at all
$_POST = $_REQUEST = array( 'op' => 'issue', 'amount' => '25' );
try {
	( new Admin() )->handle();
	$died = null;
} catch ( Fh_Test_Die $d ) {
	$died = $d;
}
check( 'no nonce: refused by the nonce check (before anything else is looked at)', $died && 403 === $died->status && false !== strpos( $died->getMessage(), 'expired' ) );
$_POST = $_REQUEST = array( 'op' => 'issue', 'amount' => '25', '_wpnonce' => wp_create_nonce( Admin::NONCE ) );
try {
	( new Admin() )->handle();
	$died = null;
} catch ( Fh_Test_Die $d ) {
	$died = $d;
}
check( 'a good nonce from someone without the capability: refused, 403', $died && 403 === $died->status && false !== strpos( $died->getMessage(), 'permission' ) );
check( '…and nothing was issued', 0 === $s->store->count( array( 'filter' => 'all' ) ) );
$_POST = $_REQUEST = array();

$staff = fh_ctx( $s, 2, 'live' );
check( 'a shop manager may not issue (administrators only by default)', 'refused' === Admin::perform( 'issue', fh_issue_post(), $staff )['code'] );
check( '…may not see a full code', 'refused' === Admin::perform( 'reveal', array( 'card' => 1 ), $staff )['code'] );
check( '…may not export, import, change the mode or the settings', 'refused' === Admin::perform( 'import', array(), $staff )['code'] && 'refused' === Admin::perform( 'mode', array( 'mode' => 'off' ), $staff )['code'] && 'refused' === Admin::perform( 'settings', array(), $staff )['code'] && '' !== Admin::refusal( 'export', $staff ) );
$s2 = fh_gc_service( $s->store, array( 'editor_capability' => 'manage_woocommerce' ) );
check( 'with Settings letting shop managers edit, they may issue', Admin::perform( 'issue', fh_issue_post(), fh_ctx( $s2, 2, 'live' ) )['ok'] );
$owner = fh_ctx( $s, 1, 'shadow' );
foreach ( array( 'issue', 'adjust', 'details', 'status', 'resend', 'restore_part' ) as $op ) {
	$r = Admin::perform( $op, fh_issue_post( array( 'card' => 1, 'direction' => 'credit', 'reason' => 'test' ) ), $owner );
	check( "Shadow refuses \"$op\" — the old plugin is in charge", ! $r['ok'] && false !== strpos( $r['message'], 'Shadow mode' ) );
}
check( 'Shadow allows the dry run and the import', '' === Admin::refusal( 'dry_run', $owner ) && '' === Admin::refusal( 'import', $owner ) );
check( 'Off refuses everything but the mode, the settings and the export', '' !== Admin::refusal( 'import', fh_ctx( $s, 1, 'off' ) ) && '' === Admin::refusal( 'mode', fh_ctx( $s, 1, 'off' ) ) );
check( 'an unknown operation is refused', 'refused' === Admin::perform( 'delete_audit', array(), fh_ctx( $s, 1, 'live' ) )['code'] );

echo "\nEach operation (Live, the owner)\n";
fh_wc_reset();
$s = fh_gc_use();
$o = fh_ctx( $s, 1, 'live' );
$r = Admin::perform( 'issue', fh_issue_post( array( 'delivery' => 'now', 'reason' => 'prize' ) ), $o );
check( 'issue: saved, e-mailed, and back on the card\'s page', $r['ok'] && false !== strpos( $r['message'], 'Its e-mail was sent' ) && false !== strpos( $r['redirect'], 'card=' ) && 1 === count( $GLOBALS['fh_mails'] ) );
$id = (int) $s->store->find( array( 'filter' => 'all' ) )[0]['id'];
$r  = Admin::perform( 'issue', fh_issue_post( array( 'amount' => 'abc' ) ), $o );
check( 'a refused form comes back with what was typed', ! $r['ok'] && 'abc' === $r['keep']['amount'] && ! isset( $r['keep']['_wpnonce'] ) );
check( 'adjust', Admin::perform( 'adjust', array( 'card' => $id, 'direction' => 'debit', 'amount' => '5', 'reason' => 'used in the shop', 'request_key' => Service::new_request_key() ), $o )['ok'] && 2000 === (int) $s->store->card( $id )['balance_cents'] );
check( 'details', Admin::perform( 'details', array( 'card' => $id, 'recipient_name' => 'Pat T', 'recipient_email' => 'pat@example.test', 'sender_name' => '', 'sender_email' => '', 'message' => 'hi' ), $o )['ok'] && 'Pat T' === $s->store->card( $id )['recipient_name'] );
check( 'status', Admin::perform( 'status', array( 'card' => $id, 'status' => 'disabled', 'reason' => 'checking' ), $o )['ok'] && 'disabled' === $s->store->card( $id )['status'] );
check( 'resend refuses a disabled card', ! Admin::perform( 'resend', array( 'card' => $id ), $o )['ok'] );
Admin::perform( 'status', array( 'card' => $id, 'status' => 'active', 'reason' => 'checked' ), $o );
check( 'resend', Admin::perform( 'resend', array( 'card' => $id, 'to' => 'other@example.test' ), $o )['ok'] && 'other@example.test' === end( $GLOBALS['fh_mails'] )['to'] );
$r = Admin::perform( 'reveal', array( 'card' => $id ), $o );
check( 'reveal: the owner gets the full code once, and it is logged', $r['ok'] && $s->store->card( $id )['code'] === $r['reveal']['code'] && 1 === $s->store->audit_count( array( 'action' => 'code_viewed' ) ) );
$s->redeem( $s->store->card( $id )['code'], 9, fh_gc_customer_ctx( 9 ) );
$s->debit_order( 8001, 9, array(), 1000, fh_gc_customer_ctx( 9, 'checkout' ) );
fh_order( 8001, array( 'customer_id' => 9 ) );
$r = Admin::perform( 'restore_part', array( 'order' => 8001, 'amount' => '4', 'reason' => 'one fish refunded', 'request_key' => Service::new_request_key() ), $o );
check( 'restore_part: $4 back, with an order note', $r['ok'] && 1400 === (int) $s->store->card( $id )['balance_cents'] && false !== strpos( end( wc_get_order( 8001 )->notes ), '$4.00 was put back' ) );

echo "\nShop managers allowed to edit, but not to see codes\n";
$GLOBALS['fh_mails'] = array();
$se   = fh_gc_service( $s->store, array( 'editor_capability' => 'manage_woocommerce' ) );
$ed   = fh_ctx( $se, 2, 'live' );
$card = $s->store->card( $id );
$r    = Admin::perform( 'resend', array( 'card' => $id, 'to' => 'outside@example.net' ), $ed );
check( 'they cannot send a card\'s e-mail (with its full code) to another address', 'refused' === $r['code'] && array() === $GLOBALS['fh_mails'] && false !== strpos( $r['message'], 'site owner' ) );
check( '…they can send it again to the card\'s own address (left empty, or typed in another letter case)', Admin::perform( 'resend', array( 'card' => $id ), $ed )['ok'] && Admin::perform( 'resend', array( 'card' => $id, 'to' => strtoupper( $card['recipient_email'] ) ), $ed )['ok'] && 2 === count( $GLOBALS['fh_mails'] ) );
$r = Admin::perform( 'details', array( 'card' => $id, 'recipient_name' => 'Pat T', 'recipient_email' => 'outside@example.net', 'sender_name' => '', 'sender_email' => '', 'message' => 'hi' ), $ed );
check( '…nor change where its e-mail goes', 'refused' === $r['code'] && $card['recipient_email'] === $s->store->card( $id )['recipient_email'] );
$r = Admin::perform( 'details', array( 'card' => $id, 'recipient_name' => 'Pat Tester', 'recipient_email' => $card['recipient_email'], 'sender_name' => '', 'sender_email' => $card['sender_email'], 'message' => 'hi' ), $ed );
check( '…but can change the name and the message', $r['ok'] && 'Pat Tester' === $s->store->card( $id )['recipient_name'] );

echo "\nThe mode and the settings\n";
$r = Admin::perform( 'mode', array( 'mode' => 'shadow', 'reason' => 'checking the import' ), $o );
check( 'Off → Shadow: saved and logged with the reason', $r['ok'] && 'shadow' === Modes::stored() && 1 === $s->store->audit_count( array( 'action' => 'mode' ) ) && 'checking the import' === $s->store->audit_rows( array( 'action' => 'mode' ), 1 )[0]['reason'] );
update_option( 'active_plugins', array( 'woocommerce-gift-cards/woocommerce-gift-cards.php' ) );
$r = Admin::perform( 'mode', array( 'mode' => 'live' ), $o );
check( 'Live is refused while the old plugin is switched on, saying so', ! $r['ok'] && false !== strpos( $r['message'], 'still switched on' ) && 'shadow' === Modes::stored() );
update_option( 'active_plugins', array() );
$r = Admin::perform( 'mode', array( 'mode' => 'live' ), $o );
check( 'with it off and no old tables here, Live is switched on (and the account address rebuilt once)', $r['ok'] && 'live' === Modes::stored() && 1 === get_option( \FisHotel\Misc\Sections\Gift_Cards\Account::FLUSH_OPTION ) );
$GLOBALS['fh_cron']['fishotel_gift_cards_hourly'] = time();
Admin::perform( 'mode', array( 'mode' => 'shadow' ), $o );
check( 'leaving Live takes the hourly job off', ! isset( $GLOBALS['fh_cron']['fishotel_gift_cards_hourly'] ) );
$r = Admin::perform( 'settings', array( 'guest_codes' => '1', 'custom_max' => '250.00', 'email_subject' => 'A {amount} card' ), fh_ctx( $s, 1, 'shadow' ) );
$saved = get_option( \FisHotel\Misc\Sections\Gift_Cards\Settings::OPTION_KEY );
check( 'settings saved', $r['ok'] && 'yes' === $saved['guest_codes'] && 25000 === $saved['custom_max'] && 'A {amount} card' === $saved['email_subject'] );
check( 'each changed setting is one audit row, old → new', 3 <= $s->store->audit_count( array( 'action' => 'settings' ) ) && in_array( 'guest_codes', array_column( $s->store->audit_rows( array( 'action' => 'settings' ), 10 ), 'field' ), true ) );
check( 'an unticked box is saved as no', 'no' === $saved['cancel_paid_restores'] );

echo "\nThe export\n";
$rows = Admin::export_rows( $s->store, array() );
check( 'the export has the header and every row', $s->store->audit_count( array() ) + 1 === count( $rows ) && 'row hash' === end( $rows[0] ) );
$only = Admin::export_rows( $s->store, array( 'action' => 'mode' ) );
check( 'the filters apply to it', $s->store->audit_count( array( 'action' => 'mode' ) ) + 1 === count( $only ) );
check( 'no full code in the export', false === strpos( json_encode( $rows ), $s->store->card( $id )['code'] ) );
$spec = Admin::audit_spec( array( 'from' => '2026-03-01', 'to' => '2026-03-02', 'action_filter' => 'bogus', 'source' => 'admin' ), new \DateTimeZone( 'America/Chicago' ) );
check( 'dates are the site\'s days, unknown filters dropped', array( 'source' => 'admin', 'from' => '2026-03-01 06:00:00', 'to' => '2026-03-03 06:00:00' ) === $spec );

echo "\nThe list\n";
fh_wc_reset();
$s  = fh_gc_use();
$a  = fh_gc_card( $s, '10.00', array( 'recipient_name' => 'Lee Sample' ) );
$b  = fh_gc_card( $s, '20.00' );
$c  = fh_gc_card( $s, '30.00' );
$s->adjust( $b, 'debit', '20', 'used up', 'listkey0000001', fh_gc_admin_ctx() );
$s->set_status( $c, 'disabled', 'lost', fh_gc_admin_ctx() );
$count = static function ( $filter, $q = '' ) use ( $s ) {
	return $s->store->count( array( 'filter' => $filter, 'q' => $q ) );
};
check( 'by default: the cards in use (1)', 'in_use' === Admin::list_spec( array() )['filter'] && 1 === $count( 'in_use' ) );
check( 'used up (1), disabled (1), all (3)', 1 === $count( 'used_up' ) && 1 === $count( 'disabled' ) && 3 === $count( 'all' ) );
check( 'search by name', 1 === $count( 'all', 'lee sam' ) );
check( 'search by the last 4 of the code', 1 === $count( 'all', substr( $s->store->card( $a )['code'], -4 ) ) );
check( 'search by the whole code typed loosely', 1 === $count( 'all', strtolower( $s->store->card( $c )['code'] ) ) );
$found = $s->store->find( array( 'filter' => 'all', 'sort' => 'balance', 'dir' => 'desc' ) );
check( 'sort by balance', array( $c, $a, $b ) === array_map( 'intval', array_column( $found, 'id' ) ) );
check( 'sort by amount, ascending', array( $a, $b, $c ) === array_map( 'intval', array_column( $s->store->find( array( 'filter' => 'all', 'sort' => 'amount', 'dir' => 'asc' ) ), 'id' ) ) );
check( 'a sort the list does not know falls back to "issued"', 'issued' === Admin::list_spec( array( 'sort' => 'code_key; DROP' ) )['sort'] );

echo "\nEvery tab, printed\n";
update_option( Modes::OPTION, 'live' );
set_error_handler(
	static function ( $no, $str, $file, $line ) {
		$GLOBALS['fh_php_notices'][] = "$str ($file:$line)";
		return true;
	}
);
$code = $s->store->card( $a )['code'];
foreach ( array( 1 => 'owner', 2 => 'shop manager' ) as $user => $who ) {
	foreach ( array( 'cards', 'issue', 'stats', 'audit', 'import', 'settings', 'order' ) as $tab ) {
		$GLOBALS['fh_current_user'] = $user;
		$_GET                       = array( 'page' => Gift_Cards::MENU_SLUG, 'tab' => $tab, 'order' => '1', 'verify' => '1' );
		ob_start();
		( new Admin() )->render_page();
		$html = ob_get_clean();
		check( "$who: $tab prints", false !== strpos( $html, 'fh-gc-wrap' ) && false === strpos( $html, '<script' ) );
		check( "$who: $tab shows no full code", false === strpos( $html, $code ) );
	}
	$_GET = array( 'page' => Gift_Cards::MENU_SLUG, 'card' => (string) $a );
	ob_start();
	( new Admin() )->render_page();
	$html = ob_get_clean();
	check( "$who: a card's page prints, its code masked", ( false !== strpos( $html, 'Change the balance' ) ) === ( 1 === $user ) && false === strpos( $html, $code ) && false !== strpos( $html, '••••-••••-••••-' ) );
	check( "$who: the \"Show the full code\" button only for the owner", ( false !== strpos( $html, 'Show the full code' ) ) === ( 1 === $user ) );
}
set_transient( Admin::FLASH . '1', array( 'ok' => true, 'message' => '<b>x</b>', 'reveal' => array( 'card' => $a, 'code' => $code ) ), 60 );
$GLOBALS['fh_current_user'] = 1;
ob_start();
( new Admin() )->render_page();
$html = ob_get_clean();
check( 'after "Show the full code", the owner sees it once', false !== strpos( $html, $code ) && false !== strpos( $html, '&lt;b&gt;x&lt;/b&gt;' ) );
ob_start();
( new Admin() )->render_page();
check( '…and not again', false === strpos( ob_get_clean(), $code ) );
update_option( Modes::OPTION, 'off' );
$_GET = array( 'page' => Gift_Cards::MENU_SLUG, 'tab' => 'cards' );
ob_start();
( new Admin() )->render_page();
$html = ob_get_clean();
check( 'mode Off: only Settings, saying nothing runs', false !== strpos( $html, 'Mode Off: nothing runs' ) && false !== strpos( $html, 'name="mode"' ) && false === strpos( $html, 'fh-gc-table' ) );
restore_error_handler();
check( 'no PHP notice or warning on any tab', empty( $GLOBALS['fh_php_notices'] ), implode( "\n", (array) ( $GLOBALS['fh_php_notices'] ?? array() ) ) );
$_GET = array();

check( 'no unexpected database error', empty( $GLOBALS['fh_db_errors'] ), implode( "\n", (array) ( $GLOBALS['fh_db_errors'] ?? array() ) ) );

fh_finish();
