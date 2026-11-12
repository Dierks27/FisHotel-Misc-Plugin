<?php
/**
 * Store — the tables as dbDelta wants them written, the version gate (an
 * autoloaded option written only once the tables really exist; a failed
 * install leaves a marker and waits), the label counter, the row shapes,
 * and the promise that this is the only file in the section that touches
 * $wpdb. The reads and writes themselves are exercised against a real
 * database outside this suite; here $wpdb is a double that knows which
 * tables exist.
 *
 * Run from the plugin root:  php includes/sections/foam-cuts/tests/store-test.php
 */

require_once __DIR__ . '/wp-stubs.php';

use FisHotel\Misc\Sections\Foam_Cuts\Store;

echo "1. The schema\n";
$schema = Store::schema( 'DEFAULT CHARACTER SET utf8mb4' );
check_same( 'three tables', 3, count( $schema ) );
check_same( 'named {prefix}fh_foam_*', array( 'wp_fh_foam_leftovers', 'wp_fh_foam_plans', 'wp_fh_foam_sheet_log' ), array_map( array( Store::class, 'table' ), Store::TABLES ) );
$ok = true;
foreach ( $schema as $i => $statement ) {
	$ok = $ok && 0 === strpos( $statement, 'CREATE TABLE ' . Store::table( Store::TABLES[ $i ] ) . ' (' );
	$ok = $ok && 1 === preg_match( '/PRIMARY KEY  \(id\)/', $statement ); // Two spaces: dbDelta insists.
	$ok = $ok && 0 === preg_match( '/\bINDEX\b|\bUNSIGNED\b|\bBIGINT\b|\bVARCHAR\b|\bDATETIME\b/', $statement ); // Types as MySQL reports them.
	$ok = $ok && false !== strpos( $statement, 'DEFAULT CHARACTER SET utf8mb4;' );
	foreach ( explode( "\n", trim( $statement ) ) as $n => $line ) {
		$line = trim( $line );
		if ( 0 === $n || '' === $line || 0 === strpos( $line, ')' ) ) {
			continue;
		}
		// One column or key per line, each ending in a comma except the last.
		$ok = $ok && 1 === preg_match( '/^(PRIMARY KEY  \(id\)|KEY \w+ \(\w+\)|[a-z_]+ (bigint\(20\) unsigned|int\(10\) unsigned|int\(11\)|varchar\(\d+\)|datetime|date|longtext)( NOT NULL| NULL)( AUTO_INCREMENT| DEFAULT (\'[a-z]*\'|0))?),?$/', $line );
	}
}
check( 'each written the way dbDelta reads it: one column per line, lower-case types, KEY with a name, two spaces after PRIMARY KEY', $ok );
check( 'a leftover has a label, a size in units, a status and the plans that made and used it', 1 === preg_match( '/label varchar\(20\).*length_u int\(10\) unsigned.*width_u int\(10\) unsigned.*status varchar\(12\).*plan_id bigint.*used_plan_id bigint/s', $schema[0] ) );
check( 'a plan has a status, its sheets, the labels it hands out and its data', 1 === preg_match( '/status varchar\(12\).*sheets int\(11\).*label_from int\(10\) unsigned.*label_count int\(10\) unsigned.*data longtext NOT NULL/s', $schema[1] ) );
check( 'a sheet entry has a kind and a signed change', 1 === preg_match( '/kind varchar\(12\).*sheets int\(11\) NOT NULL DEFAULT 0/s', $schema[2] ) );

echo "2. The version gate\n";
$wpdb = $GLOBALS['wpdb'];
check( 'nothing installed yet', false === Store::installed() && false === Store::tables_exist() );
$GLOBALS['fh_is_admin'] = false;
check_same( 'the first boot installs', true, Store::maybe_install( false ) );
check_same( 'dbDelta was given the three statements', 3, count( $GLOBALS['fh_dbdelta'] ) );
check_same( 'the version is recorded', Store::DB_VERSION, get_option( Store::DB_VERSION_OPTION ) );
check_same( '…autoloaded, so reading it costs no query', true, $GLOBALS['fh_autoload'][ Store::DB_VERSION_OPTION ] );
check( 'installed', true === Store::installed() && true === Store::tables_exist() );

$GLOBALS['fh_dbdelta'] = array();
$wpdb->queries         = array();
check_same( 'the next boot does nothing', false, Store::maybe_install( false ) );
check( '…no dbDelta, no SHOW TABLES, no query of any kind', array() === $GLOBALS['fh_dbdelta'] && array() === $wpdb->queries );
check_same( 'install() without force is a no-op too', true, Store::install() );
check( '…still no query', array() === $GLOBALS['fh_dbdelta'] && array() === $wpdb->queries );
check_same( 'activation forces it (a dropped table comes back)', true, Store::install( true ) );
check_same( '…through dbDelta again', 3, count( $GLOBALS['fh_dbdelta'] ) );

echo "3. An install that fails\n";
delete_option( Store::DB_VERSION_OPTION );
$wpdb->tables          = array();
$wpdb->create_ok       = false;
$GLOBALS['fh_dbdelta'] = array();
check_same( 'the first boot tries', true, Store::maybe_install( false ) );
$marker = get_option( Store::DB_VERSION_OPTION );
check( 'the tables are not there, so the version is NOT written — a marker is, autoloaded', false === Store::installed() && 1 === preg_match( '/^failed:1\.0:\d+$/', (string) $marker ) && true === $GLOBALS['fh_autoload'][ Store::DB_VERSION_OPTION ], (string) $marker );
$GLOBALS['fh_dbdelta'] = array();
$wpdb->queries         = array();
check( 'a visitor\'s request does not try again', false === Store::maybe_install( false ) && array() === $GLOBALS['fh_dbdelta'] && array() === $wpdb->queries );
check( 'nor does wp-admin within the hour', false === Store::maybe_install( true ) && array() === $GLOBALS['fh_dbdelta'] );
update_option( Store::DB_VERSION_OPTION, 'failed:' . Store::DB_VERSION . ':' . ( time() - Store::RETRY_SECONDS - 5 ), true );
check( 'a visitor\'s request still does not, an hour later', false === Store::maybe_install( false ) && array() === $GLOBALS['fh_dbdelta'] );
$wpdb->create_ok = true;
check( 'wp-admin does, an hour later — and this time it works', true === Store::maybe_install( true ) && true === Store::installed() );
update_option( Store::DB_VERSION_OPTION, '0.9', true );
$GLOBALS['fh_dbdelta'] = array();
check( 'an older version stored: upgraded on the next boot', true === Store::maybe_install( false ) && 3 === count( $GLOBALS['fh_dbdelta'] ) && true === Store::installed() );

echo "4. The label counter\n";
$store = new Store();
check_same( 'starts at 1', 1, $store->next_label() );
check_same( 'three labels: 1, 2, 3', 1, $store->reserve_labels( 3 ) );
check_same( 'the next one is 4', 4, $store->next_label() );
check_same( 'the counter is an option that is not autoloaded', false, $GLOBALS['fh_autoload'][ Store::LABEL_OPTION ] );
check( 'the last ones handed out can be given back', true === $store->release_labels( 2, 2 ) && 2 === $store->next_label() );
$store->reserve_labels( 5 );
check( 'earlier ones cannot (a label is never handed out twice)', false === $store->release_labels( 2, 2 ) && 7 === $store->next_label() );
check( 'nor nonsense', false === $store->release_labels( 0, 3 ) && false === $store->release_labels( 7, 0 ) && 7 === $store->next_label() );
check_same( 'reserving none hands back the next number and moves nothing', array( 7, 7 ), array( $store->reserve_labels( 0 ), $store->next_label() ) );

echo "5. Rows\n";
$row = Store::hydrate_leftover( array( 'id' => '7', 'label' => 'L7', 'length_u' => '300000', 'width_u' => '200000', 'status' => 'stock', 'origin' => 'plan', 'plan_id' => '3', 'used_plan_id' => '0', 'note' => 'from new sheet 1', 'created_at' => '2026-03-10 09:00:00', 'updated_at' => '2026-03-10 09:00:00' ) );
check( 'a leftover: typed values, the size under length / width', 7 === $row['id'] && 300000 === $row['length'] && 200000 === $row['width'] && 3 === $row['plan_id'] && 0 === $row['used_plan_id'] && 'L7' === $row['label'] );
$plan = Store::hydrate_plan( array( 'id' => '4', 'status' => 'cut', 'title' => '2 × Cube', 'origin' => 'orders', 'ship_date' => '2026-03-10', 'sheets' => '2', 'label_from' => '5', 'label_count' => '3', 'data' => '{"schema":"fishotel-foam-cuts/plan@1","sources":[]}', 'created_by' => '1', 'created_at' => '2026-03-10 09:00:00', 'cut_by' => '1', 'cut_at' => '2026-03-10 10:00:00', 'undone_at' => null, 'updated_at' => '2026-03-10 10:00:00' ) );
check( 'a plan: typed values, the data decoded', 4 === $plan['id'] && 2 === $plan['sheets'] && 5 === $plan['label_from'] && 'fishotel-foam-cuts/plan@1' === $plan['data']['schema'] && '' === $plan['undone_at'] && '2026-03-10' === $plan['ship_date'] );
$broken = Store::hydrate_plan( array( 'id' => '4', 'status' => 'draft', 'title' => '', 'origin' => 'typed', 'ship_date' => null, 'sheets' => '0', 'label_from' => '0', 'label_count' => '0', 'data' => '{broken', 'created_by' => '0', 'created_at' => '', 'cut_by' => '0', 'cut_at' => null, 'undone_at' => null, 'updated_at' => '' ) );
check( 'data that is not JSON becomes an empty plan, not an error', array() === $broken['data'] && '' === $broken['ship_date'] );
$listed = Store::hydrate_plan( array( 'id' => '4', 'status' => 'draft', 'title' => '', 'origin' => 'typed', 'ship_date' => null, 'sheets' => '0', 'label_from' => '0', 'label_count' => '0', 'created_by' => '0', 'created_at' => '', 'cut_by' => '0', 'cut_at' => null, 'undone_at' => null, 'updated_at' => '' ) );
check( 'a row read for the list has no data key', ! array_key_exists( 'data', $listed ) );
check_same( 'a note is one plain line cut to its column', 'from new sheet', Store::clip( "  from <b>new</b>\n sheet 1 ", 14 ) );

echo "6. The only file that touches \$wpdb\n";
$dir   = dirname( __DIR__ );
$files = array_merge( glob( $dir . '/*.php' ), glob( $dir . '/views/*.php' ) );
$with  = array();
foreach ( $files as $file ) {
	if ( false !== strpos( (string) file_get_contents( $file ), '$wpdb' ) ) {
		$with[] = basename( $file );
	}
}
check_same( 'class-store.php, and no other file of the section', array( 'class-store.php' ), $with );
$source = (string) php_strip_whitespace( $dir . '/class-store.php' ); // The code without its comments.
check( 'no DROP, TRUNCATE or ALTER anywhere in it', 0 === preg_match( '/\b(DROP|TRUNCATE|ALTER)\b/', $source ) );
check( 'every statement with a value goes through prepare() or insert / update / delete', 0 === preg_match( '/\$wpdb->(query|get_var|get_results|get_row|get_col)\(\s*["\'][^"\']*\$[a-z_]+/', $source ) );
check( 'a status only changes where it still is what the caller read', 1 === preg_match( '/UPDATE .* SET status = %s.* WHERE id = %d AND status = %s/', $source ) && 1 === preg_match( '/SET status = %s, used_plan_id = %d, updated_at = %s WHERE status = %s/', $source ) );

fh_finish();
