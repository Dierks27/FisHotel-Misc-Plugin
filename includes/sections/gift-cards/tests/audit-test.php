<?php
/**
 * The audit log: every write path writes its row (who, when in UTC and
 * site time, what old → new, the money, why, from where), in the same
 * transaction as the change; nothing in the plugin can edit or delete a
 * row; the hash chain shows a row edited, removed from the middle or cut
 * off the end; the export is safe to open in a spreadsheet.
 *
 * Run from the plugin root:  php includes/sections/gift-cards/tests/audit-test.php
 */

require_once __DIR__ . '/wp-stubs.php';

use FisHotel\Misc\Sections\Gift_Cards\Audit;

/**
 * The audit rows written since $after, oldest first.
 */
function fh_audit_since( $s, $after ) {
	return array_values(
		array_filter(
			array_reverse( $s->store->audit_rows( array(), 500 ) ),
			static function ( $r ) use ( $after ) {
				return (int) $r['id'] > $after;
			}
		)
	);
}

function fh_audit_last_id( $s ) {
	$h = $s->store->audit_head();
	return $h ? (int) $h['id'] : 0;
}

echo "Every write path writes its row\n";
$s    = fh_gc_fresh();
$mark = fh_audit_last_id( $s );
$id   = fh_gc_card( $s, '25.00', array( 'reason' => 'raffle prize' ) );
$rows = fh_audit_since( $s, $mark );
check( 'issue: a row with the amount, the reason, who and from where', 'issue' === $rows[0]['action'] && '2500' === $rows[0]['new_value'] && '2500' === (string) $rows[0]['delta_cents'] && 'raffle prize' === $rows[0]['reason'] && 1 === (int) $rows[0]['actor_user_id'] && 'admin' === $rows[0]['source'] && '203.0.113.9' === $rows[0]['ip'] && 'gift-cards/card' === $rows[0]['screen'] );
check( '…when in UTC and in site time with its offset', '2026-03-02 15:00:00' === $rows[0]['created_at_utc'] && '2026-03-02 09:00:00 CST (-06:00)' === $rows[0]['site_time'] );
check( '…and how its e-mail goes', 'delivery' === $rows[1]['field'] && 'do not send' === $rows[1]['new_value'] );
check( 'the full code is never written in the log', false === strpos( json_encode( $s->store->audit_rows( array(), 500 ) ), $s->store->card( $id )['code'] ) );

$paths = array(
	'balance' => function () use ( $s, $id ) {
		return $s->adjust( $id, 'credit', '5', 'goodwill', 'auditkey000001', fh_gc_admin_ctx() );
	},
	'details' => function () use ( $s, $id ) {
		return $s->update_details( $id, array( 'recipient_name' => 'Sam Example' ), 'typo', fh_gc_admin_ctx() );
	},
	'status'  => function () use ( $s, $id ) {
		$s->set_status( $id, 'disabled', 'checking', fh_gc_admin_ctx() );
		return $s->set_status( $id, 'active', 'checked', fh_gc_admin_ctx() );
	},
	'code_viewed' => function () use ( $s, $id ) {
		return $s->reveal( $id, fh_gc_admin_ctx() );
	},
	'redeem'  => function () use ( $s, $id ) {
		return $s->redeem( $s->store->card( $id )['code'], 5, fh_gc_customer_ctx( 5 ) );
	},
	'use'     => function () use ( $s ) {
		return $s->debit_order( 900, 5, array(), 1000, fh_gc_customer_ctx( 5, 'checkout' ) );
	},
	'restore' => function () use ( $s ) {
		$s->restore_part( 900, '2', 'one fish short', 'auditkey000002', fh_gc_admin_ctx() );
		return $s->restore_order( 900, 'cancelled', fh_gc_customer_ctx( 0, 'order' ) );
	},
	'email_resent' => function () use ( $s, $id ) {
		return $s->resend( $id, 'pat@example.test', fh_gc_admin_ctx() );
	},
	'settings' => function () use ( $s ) {
		return $s->record( fh_gc_admin_ctx(), 'settings', 'guest_codes', 'no', 'yes', '' );
	},
);
foreach ( $paths as $action => $fn ) {
	$mark = fh_audit_last_id( $s );
	fh_gc_tick();
	$r    = $fn();
	$rows = fh_audit_since( $s, $mark );
	check( $action . ': written', ! empty( $r['ok'] ) && in_array( $action, array_column( $rows, 'action' ), true ), json_encode( array( $r, array_column( $rows, 'action' ) ) ) );
}
$rows = $s->store->audit_rows( array( 'card_id' => $id ), 100 );
$bal  = array_values( array_filter( $rows, static function ( $r ) { return 'balance' === $r['action']; } ) )[0];
check( 'a balance row: field, old → new, the money and the reason', 'balance' === $bal['field'] && '2500' === $bal['old_value'] && '3000' === $bal['new_value'] && 500 === (int) $bal['delta_cents'] && 'goodwill' === $bal['reason'] );
$det = array_values( array_filter( $rows, static function ( $r ) { return 'details' === $r['action'] && 'recipient_name' === $r['field']; } ) )[0];
check( 'a details row: old → new', 'Pat Tester' === $det['old_value'] && 'Sam Example' === $det['new_value'] );
$use = array_values( array_filter( $rows, static function ( $r ) { return 'use' === $r['action']; } ) )[0];
check( 'a checkout row says checkout, the customer and the order', 'checkout' === $use['source'] && 5 === (int) $use['actor_user_id'] && -1000 === (int) $use['delta_cents'] && false !== strpos( $use['reason'], '#900' ) );
check( 'a refused change writes no row', ( function () use ( $s, $id ) {
	$mark = fh_audit_last_id( $s );
	$s->adjust( $id, 'debit', '999', 'too much', 'auditkey000003', fh_gc_admin_ctx() );
	return fh_audit_last_id( $s ) === $mark;
} )() );

$GLOBALS['fh_mail_fails'] = true;
$mark = fh_audit_last_id( $s );
$s->resend( $id, '', fh_gc_admin_ctx() );
check( 'an e-mail that fails writes "email_failed"', in_array( 'email_failed', array_column( fh_audit_since( $s, $mark ), 'action' ), true ) );
$GLOBALS['fh_mail_fails'] = false;

echo "\nThe change and its row are one transaction\n";
$s     = fh_gc_fresh();
$id    = fh_gc_card( $s, '10.00' );
$audit = $s->store->table( 'gift_card_audit' );
$GLOBALS['wpdb']->before = static function ( $sql ) use ( $audit ) {
	if ( 0 === strpos( $sql, 'INSERT INTO `' . $audit . '`' ) ) {
		$GLOBALS['wpdb']->pdo->exec( 'DROP TABLE ' . $audit ); // the log cannot be written
	}
};
$r = $s->adjust( $id, 'credit', '5', 'test', 'txnkey00000001', fh_gc_admin_ctx() );
$GLOBALS['wpdb']->before = null;
$GLOBALS['fh_db_errors'] = array();
check( 'when the audit row cannot be written, the money does not move either', ! $r['ok'] && 'audit' === $r['code'] && 1000 === (int) $s->store->card( $id )['balance_cents'] );

echo "\nThe chain\n";
$s = fh_gc_fresh();
for ( $i = 0; $i < 4; $i++ ) {
	fh_gc_card( $s, '10.00' );
}
$reader = static function ( $after ) use ( $s ) {
	return $s->store->audit_after( $after, 3 );
};
$head   = get_option( Audit::HEAD_OPTION );
$v      = Audit::verify( $reader, 'test-audit-key', $head );
check( 'an untouched log checks out', $v['ok'] && 8 === $v['checked'] );
$t = $s->store->table( 'gift_card_audit' );
$GLOBALS['wpdb']->pdo->exec( "UPDATE {$t} SET delta_cents = 99999 WHERE id = 3" );
$v = Audit::verify( $reader, 'test-audit-key', $head );
check( 'a row changed behind the plugin\'s back shows', ! $v['ok'] && false !== strpos( implode( ' ', $v['problems'] ), 'Row 3 was changed' ) );
$GLOBALS['wpdb']->pdo->exec( "UPDATE {$t} SET delta_cents = 1000 WHERE id = 3" );
check( 'put back as it was, it checks out again', Audit::verify( $reader, 'test-audit-key', $head )['ok'] );
$GLOBALS['wpdb']->pdo->exec( "DELETE FROM {$t} WHERE id = 5" );
$v = Audit::verify( $reader, 'test-audit-key', $head );
check( 'a row removed from the middle shows', ! $v['ok'] && false !== strpos( implode( ' ', $v['problems'] ), 'Row 6 does not follow' ) );
$s = fh_gc_fresh();
fh_gc_card( $s, '10.00' );
fh_gc_card( $s, '10.00' );
$head = get_option( Audit::HEAD_OPTION );
$t    = $s->store->table( 'gift_card_audit' );
$GLOBALS['wpdb']->pdo->exec( "DELETE FROM {$t} WHERE id = 4" );
$v = Audit::verify( static function ( $after ) use ( $s ) { return $s->store->audit_after( $after, 50 ); }, 'test-audit-key', $head );
check( 'rows cut off the end show', ! $v['ok'] && false !== strpos( implode( ' ', $v['problems'] ), 'removed from the end' ) );
$v = Audit::verify( static function ( $after ) use ( $s ) { return $s->store->audit_after( $after, 50 ); }, 'a-new-site-key', null );
check( 'after the site key changes, old rows are "another key", not "tampered"', 3 === $v['other_key'] && $v['ok'] );
$fake            = $s->store->audit_after( 0, 50 )[0];
$fake['reason']  = 'edited';
$fake['row_hash'] = hash_hmac( 'sha256', Audit::canonical( $fake ), 'a-guessed-key' );
check( 'a row re-hashed without the site key does not check out', ! hash_equals( Audit::hash_row( $fake, 'test-audit-key' ), $fake['row_hash'] ) );

echo "\nNothing can alter it\n";
$src = file_get_contents( FISHOTEL_MISC_PATH . 'includes/sections/gift-cards/class-store.php' );
check( 'the Store never UPDATEs the ledger or the audit table', ! preg_match( "/UPDATE '\s*\.\s*\\\$this->table\(\s*'gift_card_(ledger|audit)'/", $src ) );
check( 'the Store never sends a DELETE, TRUNCATE or DROP', ! preg_match( '/[\'"]\s*(DELETE|TRUNCATE|DROP)\b/i', $src ) );
check( 'the Store\'s only UPDATEs are on the cards table', preg_match_all( "/'UPDATE '\s*\.\s*\\\$this->table\(\s*'([a-z_]+)'/", $src, $m ) && array( 'gift_cards' ) === array_values( array_unique( $m[1] ) ) );
$files = glob( FISHOTEL_MISC_PATH . 'includes/sections/gift-cards/*.php' );
$views = glob( FISHOTEL_MISC_PATH . 'includes/sections/gift-cards/views/*.php' );
$dirty = array();
foreach ( array_merge( $files, $views ) as $file ) {
	if ( 'class-store.php' !== basename( $file ) && preg_match( '/\$wpdb|\bwpdb\b/', file_get_contents( $file ) ) ) {
		$dirty[] = basename( $file );
	}
}
check( 'only the Store touches $wpdb', array() === $dirty, implode( ', ', $dirty ) );
$admin = file_exists( FISHOTEL_MISC_PATH . 'includes/sections/gift-cards/class-admin.php' ) ? file_get_contents( FISHOTEL_MISC_PATH . 'includes/sections/gift-cards/class-admin.php' ) : '';
check( 'the admin screen has no delete or edit operation for the log', '' === $admin || ! preg_match( "/'(audit_delete|audit_edit|delete_audit|edit_audit|clear_log)'/", $admin ) );

echo "\nThe export\n";
check( 'a formula in a cell is made harmless', "'=HYPERLINK(\"x\")" === Audit::csv_cell( '=HYPERLINK("x")' ) && "'@SUM(A1)" === Audit::csv_cell( '@SUM(A1)' ) && "'+1" === Audit::csv_cell( '+1' ) );
check( '…but a negative amount stays a number', '-500' === Audit::csv_cell( '-500' ) );
$s   = fh_gc_fresh();
$id  = fh_gc_card( $s, '10.00' );
$row = $s->store->audit_rows( array(), 1, 0 );
$csv = Audit::csv_row( $s->store->audit_after( 0, 1 )[0], 'NB4E' );
check( 'an export line has every column, with the hash', count( Audit::csv_header() ) === count( $csv ) && 64 === strlen( end( $csv ) ) );
check( 'a row in words', 'Card issued: amount (empty) → $10.00 (+$10.00)' === Audit::describe( $s->store->audit_after( 0, 1 )[0] ) );

echo "\nWhere a request came from\n";
check_same( 'REMOTE_ADDR', '192.0.2.4', Audit::ip( array( 'REMOTE_ADDR' => '192.0.2.4' ) ) );
check_same( 'a forwarded-for address is kept beside it as a hint', '192.0.2.4 (forwarded: 198.51.100.7)', Audit::ip( array( 'REMOTE_ADDR' => '192.0.2.4', 'HTTP_X_FORWARDED_FOR' => '198.51.100.7, 10.0.0.1' ) ) );
check_same( 'something that is not an address is dropped', '', Audit::ip( array( 'REMOTE_ADDR' => '<script>' ) ) );
$GLOBALS['fh_users'][3]     = array( 'login' => 'helper', 'display' => 'Shop Helper' );
$GLOBALS['fh_current_user'] = 3;
$_SERVER['REMOTE_ADDR']     = '192.0.2.50';
$ctx                        = Audit::context( 'admin', 'gift-cards/issue' );
check( 'the context names the user, the screen and the address', 3 === $ctx['user_id'] && 'Shop Helper (helper)' === $ctx['name'] && 'gift-cards/issue' === $ctx['screen'] && '192.0.2.50' === $ctx['ip'] );
$GLOBALS['fh_current_user'] = 0;

check( 'no unexpected database error', empty( $GLOBALS['fh_db_errors'] ), implode( "\n", (array) ( $GLOBALS['fh_db_errors'] ?? array() ) ) );

fh_finish();
