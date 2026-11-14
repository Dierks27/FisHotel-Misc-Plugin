<?php
/**
 * The importer against invented old-style tables (built from
 * Legacy_Map::COLUMNS with MySQL types, filled with invented cards):
 * the schema check (matching; a column missing, extra or of another
 * kind — each named, nothing written), the dry run reconciled to the
 * cent with every difference listed, the import (copy only: the old
 * tables are byte-for-byte the same after it), old orders keep their
 * deduction and a refund on one puts money back in the new system,
 * a re-run (nothing twice; new old activity brought in; a card used here
 * since left alone), data that stops the import, and the read-only rule.
 *
 * Run from the plugin root:  php includes/sections/gift-cards/tests/importer-test.php
 */

require_once __DIR__ . '/wp-stubs.php';

use FisHotel\Misc\Sections\Gift_Cards\Importer;
use FisHotel\Misc\Sections\Gift_Cards\Legacy_Map;
use FisHotel\Misc\Sections\Gift_Cards\Modes;
use FisHotel\Misc\Sections\Gift_Cards\Store;

/**
 * MySQL types for the map's kinds.
 */
function fh_legacy_type( $kind ) {
	$types = array(
		'int'      => 'bigint(20) unsigned',
		'decimal'  => 'decimal(19,4)',
		'text'     => 'varchar(255)',
		'datetime' => 'datetime',
	);
	return $types[ $kind ];
}

/**
 * Create the old tables (optionally with a column dropped / added / of
 * another type) and wp_users.
 */
function fh_legacy_tables( array $change = array() ) {
	foreach ( Legacy_Map::TABLES as $key => $name ) {
		$cols = array();
		foreach ( Legacy_Map::COLUMNS[ $key ] as $column => $kind ) {
			if ( isset( $change['drop'][ $key ] ) && $change['drop'][ $key ] === $column ) {
				continue;
			}
			$type   = isset( $change['type'][ $key ][ $column ] ) ? $change['type'][ $key ][ $column ] : fh_legacy_type( $kind );
			$cols[] = 'id' === $column ? 'id bigint(20) unsigned NOT NULL AUTO_INCREMENT' : "{$column} {$type} NULL";
		}
		if ( isset( $change['add'][ $key ] ) ) {
			$cols[] = $change['add'][ $key ] . ' varchar(20) NULL';
		}
		$cols[] = 'PRIMARY KEY  (id)';
		$GLOBALS['wpdb']->create_from_mysql( 'CREATE TABLE wp_' . $name . " (\n" . implode( ",\n", $cols ) . "\n);" );
	}
	$GLOBALS['wpdb']->pdo->exec( 'CREATE TABLE IF NOT EXISTS wp_users (ID INTEGER PRIMARY KEY, user_login TEXT)' );
	$GLOBALS['wpdb']->pdo->exec( "INSERT OR IGNORE INTO wp_users (ID, user_login) VALUES (5, 'cust5'), (6, 'cust6')" );
}

function fh_legacy_card( array $row ) {
	$GLOBALS['wpdb']->insert( 'wp_woocommerce_gc_cards', $row );
	return (int) $GLOBALS['wpdb']->insert_id;
}

function fh_legacy_act( array $row ) {
	$GLOBALS['wpdb']->insert( 'wp_woocommerce_gc_activity', $row );
	return (int) $GLOBALS['wpdb']->insert_id;
}

$t0 = strtotime( '2025-06-01 12:00:00 UTC' );

/**
 * The invented old cards:
 *   #1 $50 issued, $30 used on order 7001, so $20 left, in user 5's account
 *   #2 $25, never used, nobody's, sent to a recipient
 *   #3 $40 disabled, $40 left
 *   #4 $10, all used on order 7002 (0 left), carries an expiry date
 *   #5 $15 whose e-mail waits for a day ahead
 *   #6 $20 in the account of user 99 (not a user any more), stored $12 but its history says $20
 */
function fh_legacy_fill( $t0 ) {
	$c1 = fh_legacy_card( array( 'code' => 'OLD1-AAAA-BBBB-CCC1', 'order_id' => 7000, 'recipient' => 'pat@example.test', 'redeemed_by' => 5, 'sender' => 'Lee Sample', 'sender_email' => 'lee@example.test', 'message' => 'Happy reefing', 'balance' => '50.0000', 'remaining' => '20.0000', 'create_date' => $t0, 'delivered' => 'yes', 'expire_date' => 0, 'redeem_date' => $t0 + 60, 'is_active' => 'on' ) );
	fh_legacy_act( array( 'type' => 'issued', 'gc_id' => $c1, 'object_id' => 7000, 'amount' => '50.0000', 'date' => $t0 ) );
	fh_legacy_act( array( 'type' => 'redeemed', 'gc_id' => $c1, 'user_id' => 5, 'amount' => '50.0000', 'date' => $t0 + 60 ) );
	fh_legacy_act( array( 'type' => 'used', 'gc_id' => $c1, 'user_id' => 5, 'object_id' => 7001, 'amount' => '30.0000', 'date' => $t0 + 3600 ) );
	fh_legacy_card( array( 'code' => 'OLD2-AAAA-BBBB-CCC2', 'recipient' => 'sam@example.test', 'redeemed_by' => 0, 'balance' => '25.0000', 'remaining' => '25.0000', 'create_date' => $t0, 'delivered' => 'yes', 'expire_date' => 0, 'is_active' => 'on' ) );
	fh_legacy_card( array( 'code' => 'OLD3-AAAA-BBBB-CCC3', 'recipient' => 'kim@example.test', 'redeemed_by' => 0, 'balance' => '40.0000', 'remaining' => '40.0000', 'create_date' => $t0, 'delivered' => 'yes', 'expire_date' => 0, 'is_active' => 'off' ) );
	$c4 = fh_legacy_card( array( 'code' => 'OLD4-AAAA-BBBB-CCC4', 'recipient' => 'pat@example.test', 'redeemed_by' => 5, 'balance' => '10.0000', 'remaining' => '0.0000', 'create_date' => $t0, 'delivered' => 'yes', 'expire_date' => $t0 + 86400 * 365 * 5, 'is_active' => 'on' ) );
	fh_legacy_act( array( 'type' => 'used', 'gc_id' => $c4, 'user_id' => 5, 'object_id' => 7002, 'amount' => '10.0000', 'date' => $t0 + 7200 ) );
	fh_legacy_card( array( 'code' => 'OLD5-AAAA-BBBB-CCC5', 'recipient' => 'ari@example.test', 'redeemed_by' => 0, 'balance' => '15.0000', 'remaining' => '15.0000', 'create_date' => $t0, 'deliver_date' => strtotime( '2026-12-24 15:00:00 UTC' ), 'delivered' => 'no', 'expire_date' => 0, 'is_active' => 'on' ) );
	$c6 = fh_legacy_card( array( 'code' => 'OLD6-AAAA-BBBB-CCC6', 'recipient' => 'gone@example.test', 'redeemed_by' => 99, 'balance' => '20.0000', 'remaining' => '12.0000', 'create_date' => $t0, 'delivered' => 'yes', 'expire_date' => 0, 'is_active' => 'on' ) );
	fh_legacy_act( array( 'type' => 'issued', 'gc_id' => $c6, 'amount' => '20.0000', 'date' => $t0 ) );
	fh_legacy_act( array( 'type' => 'used', 'gc_id' => 404, 'object_id' => 7003, 'amount' => '5.0000', 'date' => $t0 ) ); // belongs to no card
}

function fh_importer( $s ) {
	return new Importer(
		$s->store,
		$s,
		array(
			'order_exists' => static function ( $id ) {
				return in_array( (int) $id, array( 7001 ), true );
			},
		)
	);
}

function fh_kinds( array $report ) {
	return array_count_values( array_column( $report['differences'], 'kind' ) );
}

function fh_import_ctx() {
	return array( 'user_id' => 1, 'name' => 'Test Owner', 'source' => 'import', 'screen' => 'gift-cards/import', 'ip' => '203.0.113.9' );
}

echo "The schema check\n";
$s = fh_gc_fresh();
fh_legacy_tables();
$imp   = fh_importer( $s );
$check = $imp->check();
check( 'tables that match the map pass', $check['found'] && $check['ok'] && array() === $check['differences'] );
check_same( 'the column kinds of MySQL types', array( 'int', 'decimal', 'text', 'datetime', 'other' ), array_map( array( Importer::class, 'family' ), array( 'int(11)', 'decimal(19,4)', 'longtext', 'datetime', 'blob' ) ) );

$s = fh_gc_fresh();
fh_legacy_tables(
	array(
		'drop' => array( 'cards' => 'remaining' ),
		'add'  => array( 'activity' => 'gc_extra' ),
		'type' => array( 'activity' => array( 'amount' => 'varchar(20)' ) ),
	)
);
fh_legacy_fill( $t0 );
$GLOBALS['fh_db_errors'] = array(); // the fill could not write the dropped column — expected
$imp    = fh_importer( $s );
$check  = $imp->check();
$words  = implode( "\n", array_column( $check['differences'], 'detail' ) );
check( 'a missing column is named', ! $check['ok'] && false !== strpos( $words, 'woocommerce_gc_cards: column "remaining" (decimal) is missing' ) );
check( 'an extra column is named', false !== strpos( $words, 'woocommerce_gc_activity: column "gc_extra" (varchar(20)) is there but not in the map' ) );
check( 'a column of another kind is named', false !== strpos( $words, 'column "amount" should be decimal but is varchar(20)' ) );
check( 'exactly those three', 3 === count( $check['differences'] ) );
$before = $s->store->count( array( 'filter' => 'all' ) );
$r      = $imp->run( fh_import_ctx() );
check( 'the import refuses, and copies nothing', ! $r['ok'] && 0 === $before && 0 === $s->store->count( array( 'filter' => 'all' ) ) );

$s = fh_gc_fresh();
$r = fh_importer( $s )->dry_run();
check( 'a site without the old tables: "nothing to import"', ! $r['found'] && false !== strpos( $r['differences'][0]['detail'], 'nothing to import' ) );

echo "\nThe dry run\n";
$s = fh_gc_fresh();
fh_legacy_tables();
fh_legacy_fill( $t0 );
$sum_cards = $GLOBALS['wpdb']->checksum( 'wp_woocommerce_gc_cards' );
$sum_act   = $GLOBALS['wpdb']->checksum( 'wp_woocommerce_gc_activity' );
$imp       = fh_importer( $s );
$dry       = $imp->dry_run();
check( 'it counts the cards: 6, 5 active and 1 disabled', 6 === $dry['counts']['cards'] && 5 === $dry['counts']['active'] && 1 === $dry['counts']['disabled'] );
check( 'what is owed, to the cent: $72.00 active, $40.00 disabled, $112.00 in all', 7200 === $dry['outstanding']['active'] && 4000 === $dry['outstanding']['disabled'] && 11200 === $dry['outstanding']['total'] );
$kinds = fh_kinds( $dry );
check( 'it lists the expiry date (shown, never enforced)', 1 === ( $kinds['expiry'] ?? 0 ) );
check( 'it lists the e-mail still waiting for its day (not sent by default)', 1 === ( $kinds['future_delivery'] ?? 0 ) && false !== strpos( implode( ' ', array_column( $dry['differences'], 'detail' ) ), 'will NOT be sent' ) );
check( 'it lists the owner who is no longer a user', 1 === ( $kinds['owner_missing'] ?? 0 ) );
check( 'it lists the stored balance its history does not add up to', 1 === ( $kinds['history_differs'] ?? 0 ) );
check( 'it lists the order that is gone (7002)', 1 === ( $kinds['order_missing'] ?? 0 ) );
check( 'it lists the activity that belongs to no card', 1 === ( $kinds['orphan_activity'] ?? 0 ) && 1 === $dry['counts']['orphans'] );
check( 'none of those stops the import', $dry['ok'] && 0 === $dry['blocking'] );
check( 'it wrote nothing: no card, no ledger, no audit row', 0 === $s->store->count( array( 'filter' => 'all' ) ) && null === $s->store->audit_head() );
check( 'the old tables are untouched', $sum_cards === $GLOBALS['wpdb']->checksum( 'wp_woocommerce_gc_cards' ) && $sum_act === $GLOBALS['wpdb']->checksum( 'wp_woocommerce_gc_activity' ) );

echo "\nThe import\n";
$GLOBALS['wpdb']->queries = array();
$run = $imp->run( fh_import_ctx() );
check( 'it copies all 6 cards', 6 === $run['tally']['created'] && 6 === $s->store->count( array( 'filter' => 'all' ) ) );
check( 'reconciled to the cent', $run['ok'] && $run['reconciled'] && 7200 === $run['copies']['active'] && 4000 === $run['copies']['disabled'] );
$totals = $s->store->imported_totals();
check( 'the new tables say the same: 6 cards, $72.00 + $40.00', 6 === $totals['cards'] && 7200 === $totals['active_cents'] && 4000 === $totals['disabled_cents'] );
check( 'every copy\'s ledger adds up to its balance', fh_gc_balanced( $s ) );
$one = $s->store->card_by_legacy( 1 );
check( 'card #1: $20 left of $50, in user 5\'s account, with its old code', 2000 === (int) $one['balance_cents'] && 5000 === (int) $one['amount_cents'] && 5 === (int) $one['owner_user_id'] && 'OLD1-AAAA-BBBB-CCC1' === $one['code'] && 'import' === $one['source'] );
check( '…its whole history came along', array( 'issued', 'redeemed_to_account', 'used' ) === array_reverse( array_column( $s->store->ledger_of_card( (int) $one['id'] ), 'type' ) ) );
check( 'card #3 stays disabled', 'disabled' === $s->store->card_by_legacy( 3 )['status'] );
check( 'card #4 keeps its expiry date — to be shown', '' !== (string) $s->store->card_by_legacy( 4 )['legacy_expires_at'] );
check( 'card #5\'s waiting e-mail is not queued', 'none' === $s->store->card_by_legacy( 5 )['email_state'] );
check( 'card #6 is in nobody\'s account (its owner is gone) and holds the stored $12', 0 === (int) $s->store->card_by_legacy( 6 )['owner_user_id'] && 1200 === (int) $s->store->card_by_legacy( 6 )['balance_cents'] );
check( 'user 5\'s wallet looks as it did: $20', 2000 === $s->wallet( 5 )['balance'] );
check( 'one audit row per card, and one for the run', 6 === $s->store->audit_count( array( 'action' => 'import' ) ) && 1 === $s->store->audit_count( array( 'action' => 'import_run' ) ) );
$touched = array_filter(
	$GLOBALS['wpdb']->queries,
	static function ( $q ) {
		return false !== stripos( $q, 'woocommerce_gc_' );
	}
);
check( 'every statement that touched the old tables was a SELECT or SHOW', array() === array_filter( $touched, static function ( $q ) { return ! preg_match( '/^\s*(SELECT|SHOW)\s/i', $q ); } ) );
check( 'the old tables are byte-for-byte untouched', $sum_cards === $GLOBALS['wpdb']->checksum( 'wp_woocommerce_gc_cards' ) && $sum_act === $GLOBALS['wpdb']->checksum( 'wp_woocommerce_gc_activity' ) );

echo "\nOld orders\n";
$sum = $s->order_summary( 7001 );
check( 'the old order 7001 still shows its $30 deduction', 3000 === $sum['used'] && $sum['imported'] );
$back = $s->restore_order( 7001, 'refunded', fh_gc_customer_ctx( 0, 'order' ) );
check( 'refunding it puts the $30 back on the card — in the new system', 3000 === $back['restored'] && 5000 === (int) $s->store->card_by_legacy( 1 )['balance_cents'] );
check( '…exactly once', 0 === $s->restore_order( 7001, 'refunded', fh_gc_customer_ctx( 0, 'order' ) )['restored'] );

echo "\nRunning it again\n";
$s = fh_gc_fresh();
fh_legacy_tables();
fh_legacy_fill( $t0 );
$imp = fh_importer( $s );
$imp->run( fh_import_ctx() );
$audit_before = $s->store->audit_count( array() );
$again        = $imp->run( fh_import_ctx() );
check( 'a second run copies nothing twice', 6 === $again['tally']['same'] && 0 === $again['tally']['created'] && 6 === $s->store->count( array( 'filter' => 'all' ) ) && $again['reconciled'] );
check( '…and writes only its own "import run" row', $audit_before + 1 === $s->store->audit_count( array() ) );
// The old plugin is still in use (Shadow): card #2 is spent there, a new card is sold there.
fh_legacy_act( array( 'type' => 'used', 'gc_id' => 2, 'object_id' => 7010, 'amount' => '5.0000', 'date' => $t0 + 99999 ) );
$GLOBALS['wpdb']->query( "UPDATE wp_woocommerce_gc_cards SET remaining = '20.0000' WHERE id = 2" );
fh_legacy_card( array( 'code' => 'OLD7-AAAA-BBBB-CCC7', 'recipient' => 'new@example.test', 'redeemed_by' => 0, 'balance' => '30.0000', 'remaining' => '30.0000', 'create_date' => $t0 + 99999, 'delivered' => 'yes', 'expire_date' => 0, 'is_active' => 'on' ) );
$state = $imp->old_state();
check( 'the Live switch sees the old plugin moved on since the import', array() !== Modes::live_blockers( false, $state, Importer::last_import() ) );
$third = $imp->run( fh_import_ctx() );
check( 'a third run brings in the new card and card #2\'s new $5 use', 1 === $third['tally']['created'] && 1 === $third['tally']['updated'] && 2000 === (int) $s->store->card_by_legacy( 2 )['balance_cents'] && $third['reconciled'] );
check( '…and now Live may be switched on', array() === Modes::live_blockers( false, $imp->old_state(), Importer::last_import() ) );
check( '…but never while the old plugin is on', 1 === count( Modes::live_blockers( true, $imp->old_state(), Importer::last_import() ) ) );
// Card #1 is used in this system, then changes in the old one too.
$s->debit_order( 8001, 5, array(), 500, fh_gc_customer_ctx( 5, 'checkout' ) );
$GLOBALS['wpdb']->query( "UPDATE wp_woocommerce_gc_cards SET remaining = '1.0000' WHERE id = 1" );
$fourth = $imp->run( fh_import_ctx() );
check( 'a card used here since is left alone, and listed', 1 === $fourth['tally']['both'] && 1500 === (int) $s->store->card_by_legacy( 1 )['balance_cents'] && 1 === ( fh_kinds( $fourth )['changed_in_both'] ?? 0 ) );
check( 'the ledger still adds up everywhere', fh_gc_balanced( $s ) );

echo "\nData that stops the import\n";
$s = fh_gc_fresh();
fh_legacy_tables();
fh_legacy_card( array( 'code' => 'DUPE-AAAA-BBBB-CCCC', 'redeemed_by' => 0, 'balance' => '10.0000', 'remaining' => '10.0000', 'create_date' => $t0, 'delivered' => 'yes', 'is_active' => 'on' ) );
fh_legacy_card( array( 'code' => 'dupe aaaa bbbb cccc', 'redeemed_by' => 0, 'balance' => '10.0000', 'remaining' => '10.0000', 'create_date' => $t0, 'delivered' => 'yes', 'is_active' => 'on' ) );
fh_legacy_card( array( 'code' => 'CENT-AAAA-BBBB-CCCC', 'redeemed_by' => 0, 'balance' => '10.0050', 'remaining' => '10.0050', 'create_date' => $t0, 'delivered' => 'yes', 'is_active' => 'on' ) );
fh_legacy_act( array( 'type' => 'mystery', 'gc_id' => 1, 'amount' => '1.0000', 'date' => $t0 ) );
$r     = fh_importer( $s )->run( fh_import_ctx() );
$kinds = fh_kinds( $r );
check( 'two cards with the same code are listed', 1 === ( $kinds['duplicate_code'] ?? 0 ) );
check( 'an amount with a fraction of a cent is listed, not rounded', 1 === ( $kinds['bad_amount'] ?? 0 ) );
check( 'an activity type the map does not know is listed', 1 === ( $kinds['unknown_type'] ?? 0 ) );
check( 'and nothing at all is copied', ! $r['ok'] && 0 === $s->store->count( array( 'filter' => 'all' ) ) );

echo "\nRead only\n";
check( 'SELECT and SHOW are allowed on the old tables', Store::read_only_sql( 'SELECT * FROM wp_x' ) && Store::read_only_sql( 'SHOW COLUMNS FROM wp_x' ) );
foreach ( array( 'DELETE FROM wp_x', 'UPDATE wp_x SET a = 1', 'INSERT INTO wp_x VALUES (1)', 'SELECT 1; DROP TABLE wp_x', 'SELECT * FROM wp_x FOR UPDATE', 'TRUNCATE wp_x', 'select * into outfile "/tmp/x" from wp_x' ) as $sql ) {
	check( 'refused: ' . $sql, ! Store::read_only_sql( $sql ) );
}
check( '…and legacy_read() refuses them before the database sees them', null === ( new Store() )->legacy_read( 'DELETE FROM wp_woocommerce_gc_cards' ) );
$src = file_get_contents( FISHOTEL_MISC_PATH . 'includes/sections/gift-cards/class-store.php' );
preg_match( '/LEGACY-READ-ONLY: BEGIN(.*)LEGACY-READ-ONLY: END/s', $src, $m );
$region = $m[1] ?? '';
check( 'the Store has one read-only region for the old tables', '' !== $region );
preg_match_all( '/(["\'])((?:SELECT|SHOW|INSERT|UPDATE|DELETE|REPLACE|DROP|ALTER|TRUNCATE|CREATE)\b[^"\']*)\1/', $region, $sql );
check( 'every SQL string in it is a SELECT or SHOW', $sql[2] && array() === array_filter( $sql[2], static function ( $q ) { return ! preg_match( '/^(SELECT|SHOW)\b/', $q ); } ), implode( ' | ', $sql[2] ) );
check( 'every database call in it goes through legacy_read()', ! preg_match( '/->(query|get_results|get_var|get_col|get_row|insert|update|delete|replace)\(/', preg_replace( '/public function legacy_read.*?\n\t}\n/s', '', $region ) ) );
check( 'the importer itself sends no SQL', ! preg_match( '/\b(INSERT|UPDATE|DELETE|DROP|TRUNCATE)\s+(INTO|FROM|TABLE|wp_|`)/', file_get_contents( FISHOTEL_MISC_PATH . 'includes/sections/gift-cards/class-importer.php' ) ) );

check( 'no unexpected database error', empty( $GLOBALS['fh_db_errors'] ), implode( "\n", (array) ( $GLOBALS['fh_db_errors'] ?? array() ) ) );

fh_finish();
