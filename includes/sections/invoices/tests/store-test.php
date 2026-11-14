<?php
/**
 * Store — the parts that need no database: the two CREATE TABLE
 * statements as dbDelta wants them, the column list every one of which
 * must exist before the version option is written, the install gate (the
 * version written only once the tables and columns are really there,
 * autoloaded; a failed install noted and not retried on every request,
 * never on the front end), the clean columns of an invoice and of a
 * line, hydration, and that this file is the only one of the section
 * that touches $wpdb.
 *
 * The gate is driven with a tiny stand-in for $wpdb and dbDelta that
 * "creates" whatever the test allows it to.
 *
 * Run from the plugin root:  php includes/sections/invoices/tests/store-test.php
 */

require_once __DIR__ . '/wp-stubs.php';

use FisHotel\Misc\Sections\Invoices\Lines;
use FisHotel\Misc\Sections\Invoices\Store;

/**
 * A stand-in for $wpdb: it knows which tables and columns "exist".
 */
class Fh_Test_Wpdb {

	public $prefix     = 'wp_';
	public $last_error = '';
	public $queries    = array();

	/**
	 * Table name → its columns.
	 *
	 * @var array<string, string[]>
	 */
	public $tables = array();

	public function get_charset_collate() {
		return 'DEFAULT CHARSET=utf8mb4';
	}

	public function prepare( $sql, ...$args ) {
		$args = isset( $args[0] ) && is_array( $args[0] ) ? $args[0] : $args;
		foreach ( $args as $arg ) {
			$sql = preg_replace( '/%[sdf]/', is_numeric( $arg ) ? (string) $arg : "'" . $arg . "'", $sql, 1 );
		}
		return $sql;
	}

	public function get_var( $sql ) {
		$this->queries[] = $sql;
		if ( preg_match( "/^SHOW TABLES LIKE '(.+)'$/", $sql, $m ) ) {
			return isset( $this->tables[ $m[1] ] ) ? $m[1] : null;
		}
		return null;
	}

	public function get_col( $sql ) {
		$this->queries[] = $sql;
		if ( preg_match( '/^SHOW COLUMNS FROM (\S+)$/', $sql, $m ) ) {
			return isset( $this->tables[ $m[1] ] ) ? $this->tables[ $m[1] ] : array();
		}
		return array();
	}
}

$GLOBALS['wpdb']         = new Fh_Test_Wpdb();
$GLOBALS['fh_dbdelta']   = array();
$GLOBALS['fh_db_create'] = 'all';

/**
 * A stand-in for dbDelta(): it reads the table and its columns out of
 * the statement and "creates" them — all of them, none of them, or all
 * but one column, as $GLOBALS['fh_db_create'] says.
 */
function dbDelta( $sql ) {
	$GLOBALS['fh_dbdelta'][] = $sql;
	if ( 'none' === $GLOBALS['fh_db_create'] || ! preg_match( '/^CREATE TABLE (\S+) \((.*)\)[^)]*$/s', $sql, $m ) ) {
		return array();
	}
	$columns = array();
	foreach ( explode( "\n", $m[2] ) as $line ) {
		if ( preg_match( '/^\s*([a-z_]+) [a-z]/', $line, $c ) ) {
			$columns[] = $c[1];
		}
	}
	if ( 0 === strpos( (string) $GLOBALS['fh_db_create'], 'without:' ) ) {
		$columns = array_values( array_diff( $columns, array( substr( $GLOBALS['fh_db_create'], 8 ) ) ) );
	}
	$GLOBALS['wpdb']->tables[ $m[1] ] = $columns;
	return array();
}

echo "1. The schema\n";
$schema = Store::schema( 'DEFAULT CHARSET=utf8mb4' );
check_same( 'two tables, prefixed', array( 'wp_fh_invoices', 'wp_fh_invoice_lines' ), array( Store::table( 'invoices' ), Store::table( 'invoice_lines' ) ) );
check( 'two CREATE TABLE statements', 2 === count( $schema ) && 0 === strpos( $schema[0], 'CREATE TABLE wp_fh_invoices (' ) && 0 === strpos( $schema[1], 'CREATE TABLE wp_fh_invoice_lines (' ) );
foreach ( Store::COLUMNS as $table => $columns ) {
	$sql = 'invoices' === $table ? $schema[0] : $schema[1];
	foreach ( $columns as $column ) {
		check( "$table.$column is in the schema", (bool) preg_match( '/^\s*' . preg_quote( $column, '/' ) . ' [a-z]/m', $sql ) );
	}
}
$declared = array();
foreach ( $schema as $index => $sql ) {
	preg_match_all( '/^\s*([a-z_]+) (?:bigint|varchar|decimal|int|tinyint|text|datetime)/m', $sql, $m );
	$declared[ 0 === $index ? 'invoices' : 'invoice_lines' ] = $m[1];
}
check_same( 'and the schema has no column the list does not name', Store::COLUMNS, $declared );
check( 'as dbDelta wants it: two spaces after PRIMARY KEY, KEY with a name, types in lower case as MySQL reports them', false !== strpos( $schema[0], 'PRIMARY KEY  (id)' ) && false !== strpos( $schema[1], 'PRIMARY KEY  (id)' ) && ! preg_match( '/\bINDEX\b|BIGINT|VARCHAR|DECIMAL|DATETIME|TINYINT/', $schema[0] . $schema[1] ) && false !== strpos( $schema[0], 'bigint(20) unsigned' ) );
check( 'the state, the handle key, the product and the paying order are indexed', false !== strpos( $schema[0], 'KEY state (state)' ) && false !== strpos( $schema[0], 'KEY handle_key (handle_key)' ) && false !== strpos( $schema[0], 'KEY product_id (product_id)' ) && false !== strpos( $schema[0], 'KEY order_id (order_id)' ) && false !== strpos( $schema[1], 'KEY invoice_id (invoice_id)' ) );
check( 'the design\'s fields: the product, the handle, the forum user, the site customer, name and e-mail, the state, the shipping choice, the flat total (nullable), the total, notes, the paying order, who and when', array() === array_diff( array( 'product_id', 'handle', 'forum_user_id', 'customer_id', 'customer_name', 'customer_email', 'state', 'shipping', 'flat_total', 'total', 'notes', 'order_id', 'created_by', 'created_at', 'updated_by', 'updated_at', 'published_by', 'published_at', 'paid_at' ), Store::COLUMNS['invoices'] ) && false !== strpos( $schema[0], 'flat_total decimal(12,2) NULL' ) );
check( 'and a line\'s: kind, name, qty, unit price, product, variation, species, tank group, size slug + label, the stock tick, sort', array() === array_diff( array( 'kind', 'name', 'qty', 'unit_price', 'product_id', 'variation_id', 'species_id', 'stay_id', 'size_slug', 'size_label', 'take_stock', 'sort' ), Store::COLUMNS['invoice_lines'] ) );

echo "2. Which columns are missing\n";
check_same( 'everything there: nothing missing', array(), Store::missing_columns( Store::COLUMNS ) );
check( 'ready', Store::schema_ready( Store::COLUMNS ) );
$present                    = Store::COLUMNS;
$present['invoice_lines']   = array_values( array_diff( $present['invoice_lines'], array( 'take_stock', 'stay_id' ) ) );
check_same( 'two columns short', array( 'invoice_lines.stay_id', 'invoice_lines.take_stock' ), Store::missing_columns( $present ) );
check( 'not ready', ! Store::schema_ready( $present ) );
check( 'a missing table: all of its columns', count( Store::COLUMNS['invoices'] ) === count( Store::missing_columns( array( 'invoice_lines' => Store::COLUMNS['invoice_lines'] ) ) ) );
check( 'column names are compared without case', Store::schema_ready( array( 'invoices' => array_map( 'strtoupper', Store::COLUMNS['invoices'] ), 'invoice_lines' => Store::COLUMNS['invoice_lines'] ) ) );

echo "3. The install gate\n";
$reset = static function () {
	$GLOBALS['fh_options']       = array();
	$GLOBALS['fh_autoload']      = array();
	$GLOBALS['wpdb']->tables     = array();
	$GLOBALS['wpdb']->queries    = array();
	$GLOBALS['fh_dbdelta']       = array();
	$GLOBALS['fh_db_create']     = 'all';
};

$reset();
check( 'before anything: not ready', ! Store::ready() );
check( 'first boot (a front-end request): the install runs', true === Store::maybe_install( false ) && 2 === count( $GLOBALS['fh_dbdelta'] ) );
check_same( 'the tables and every column exist: the version is written, autoloaded', array( Store::DB_VERSION, true ), array( get_option( Store::DB_VERSION_OPTION ), $GLOBALS['fh_autoload'][ Store::DB_VERSION_OPTION ] ) );
check( 'and nothing is noted as failed', false === get_option( Store::DB_RETRY_OPTION ) && Store::ready() );

$GLOBALS['fh_dbdelta']    = array();
$GLOBALS['wpdb']->queries = array();
check( 'every later boot: no dbDelta, not one query', false === Store::maybe_install( false ) && false === Store::maybe_install( true ) && array() === $GLOBALS['fh_dbdelta'] && array() === $GLOBALS['wpdb']->queries );

$reset();
$GLOBALS['fh_db_create'] = 'none';
check( 'the tables could not be created: the install ran…', true === Store::maybe_install( true ) );
check( '…the version is NOT written, the failure is noted (autoloaded, so it costs no query either)', false === get_option( Store::DB_VERSION_OPTION ) && 1 === preg_match( '/^' . preg_quote( Store::DB_VERSION, '/' ) . ':\d+$/', (string) get_option( Store::DB_RETRY_OPTION ) ) && true === $GLOBALS['fh_autoload'][ Store::DB_RETRY_OPTION ] && ! Store::ready() );
$GLOBALS['fh_dbdelta'] = array();
check( 'the next requests do not try again: not on the front end, and not in wp-admin within the hour', false === Store::maybe_install( false ) && false === Store::maybe_install( true ) && array() === $GLOBALS['fh_dbdelta'] );
update_option( Store::DB_RETRY_OPTION, Store::DB_VERSION . ':' . ( time() - Store::RETRY_SECONDS - 5 ), true );
check( 'an hour later a front-end request still does not', false === Store::maybe_install( false ) && array() === $GLOBALS['fh_dbdelta'] );
$GLOBALS['fh_db_create'] = 'all';
check( 'but wp-admin does, and this time it works', true === Store::maybe_install( true ) && Store::ready() && false === get_option( Store::DB_RETRY_OPTION ) );

$reset();
$GLOBALS['fh_db_create'] = 'without:take_stock';
check( 'the tables exist but one column is missing (an ALTER that failed): the version is NOT written', true === Store::maybe_install( true ) && ! Store::ready() && false !== get_option( Store::DB_RETRY_OPTION ) );

$reset();
update_option( Store::DB_VERSION_OPTION, '0.9', true );
check( 'an older schema version: the install runs and the version moves on', true === Store::maybe_install( false ) && Store::DB_VERSION === get_option( Store::DB_VERSION_OPTION ) );

$reset();
update_option( Store::DB_VERSION_OPTION, Store::DB_VERSION, true );
check( 'activation forces the install past the gate (a dropped table comes back)', true === Store::install( true ) && 2 === count( $GLOBALS['fh_dbdelta'] ) && isset( $GLOBALS['wpdb']->tables['wp_fh_invoices'] ) );
$GLOBALS['fh_dbdelta'] = array();
check( 'without force an installed store does nothing', true === Store::install() && array() === $GLOBALS['fh_dbdelta'] );
update_option( Store::DB_RETRY_OPTION, '0.9:' . time(), true );
delete_option( Store::DB_VERSION_OPTION );
check( 'a failure noted for an OLDER version does not hold a newer one back', true === Store::maybe_install( false ) && Store::ready() );

echo "4. Before the tables exist every read answers \"nothing\" and every write \"no\"\n";
$reset();
$store = new Store();
check( 'reads', null === $store->invoice( 1 ) && array() === $store->invoices() && 0 === $store->count_invoices() && array() === $store->invoices_by_products( array( 5 ) ) && array() === $store->lines( 1 ) && array() === $store->handles( 'x' ) && array() === $store->stay_reservations( array( 1 ) ) && array() === $store->invoices_for_customer( array( 'handle_key' => 'x' ) ) );
check( 'a save says "db" and writes nothing', array( false, 'db' ) === array( $store->save_invoice( 0, array( 'handle' => 'x' ) )['ok'], $store->save_invoice( 0, array( 'handle' => 'x' ) )['error'] ) && array() === $GLOBALS['wpdb']->queries );
$store->unlock( 1 );
check( 'a lock cannot be taken (and giving one back asks nothing)', false === $store->lock( 1 ) && false === $store->lock( 0 ) && array() === $GLOBALS['wpdb']->queries );
check( 'the state counts are zeros', array( 'draft' => 0, 'published' => 0, 'paid' => 0, 'cancelled' => 0, 'trashed' => 0 ) === $store->state_counts() );

echo "5. The columns of an invoice\n";
list( $data, $formats ) = Store::invoice_columns(
	array(
		'handle'         => 'Tester',
		'handle_key'     => 'tester',
		'forum_user_id'  => '',
		'customer_id'    => 0,
		'customer_name'  => 'Test Person',
		'customer_email' => 'person@example.test',
		'state'          => 'published',
		'shipping'       => 'free',
		'shipping_slug'  => 'free',
		'flat_total'     => null,
		'total'          => '599.004',
		'title'          => 'Tester Invoice (11/3/26)',
		'notes'          => 'n',
		'order_id'       => '0',
		'product_id'     => '1234',
		'published_by'   => 3,
		'published_at'   => '2026-11-03 15:00:00',
		'paid_at'        => '',
		'id'             => 99,
		'version'        => 50,
		'created_by'     => 9,
		'evil'           => 'x',
	)
);
check( 'unknown keys and the store\'s own columns (id, version, created_by) are dropped', ! isset( $data['id'] ) && ! isset( $data['version'] ) && ! isset( $data['created_by'] ) && ! isset( $data['evil'] ) );
check( 'empty ids, an empty date and no flat total become NULL', null === $data['forum_user_id'] && null === $data['customer_id'] && null === $data['order_id'] && null === $data['paid_at'] && null === $data['flat_total'] && array_key_exists( 'flat_total', $data ) );
check( 'the rest is typed', 1234 === $data['product_id'] && 599.0 === $data['total'] && 3 === $data['published_by'] && 'published' === $data['state'] && 'free' === $data['shipping'] );
check( 'one format per column, in the same order', count( $data ) === count( $formats ) && '%d' === $formats[ array_search( 'product_id', array_keys( $data ), true ) ] && '%f' === $formats[ array_search( 'total', array_keys( $data ), true ) ] && '%s' === $formats[ array_search( 'title', array_keys( $data ), true ) ] );
check( 'a flat total of 0 is kept as 0, not NULL', 0.0 === Store::invoice_columns( array( 'flat_total' => 0 ) )[0]['flat_total'] );
check( 'a state or a shipping choice that does not exist is not written', array() === Store::invoice_columns( array( 'state' => 'sold' ) )[0] && array() === Store::invoice_columns( array( 'shipping' => 'teleport' ) )[0] );
check( 'only the keys given are written', array( 'state' ) === array_keys( Store::invoice_columns( array( 'state' => 'paid' ) )[0] ) );

echo "6. The columns of a line, and hydration\n";
$line                   = Lines::normalize( array( array( 'name' => 'Blue Test Tang (Medium)', 'qty' => 2, 'unit_price' => '45.5', 'source' => 'product', 'product_id' => 501, 'variation_id' => 777, 'size_slug' => 'medium', 'size_label' => 'Medium', 'take_stock' => true ) ) )[0];
list( $data, $formats ) = Store::line_columns( $line, 12 );
check( 'every stored column but the id, typed, with one format each', array_values( array_diff( Store::COLUMNS['invoice_lines'], array( 'id' ) ) ) === array_keys( $data ) && count( $data ) === count( $formats ) && 12 === $data['invoice_id'] && 45.5 === $data['unit_price'] && 1 === $data['take_stock'] && 777 === $data['variation_id'] && null === $data['stay_id'] );
$back = Store::hydrate_line( array_merge( $data, array( 'id' => '31', 'unit_price' => '45.50', 'take_stock' => '1', 'qty' => '2' ) ) );
check( 'read back from the database (everything a string) it is the same line, with its amount', 31 === $back['id'] && 12 === $back['invoice_id'] && 2 === $back['qty'] && 45.5 === $back['unit_price'] && true === $back['take_stock'] && 'product' === $back['source'] && 91.0 === $back['amount'] && null === $back['species_id'] );
list( $data ) = Store::line_columns( Lines::normalize( array( array( 'kind' => 'note', 'name' => 'Ships Tuesday' ) ) )[0], 12 );
check( 'a note: no price, no ids', null === $data['unit_price'] && null === $data['product_id'] && 0 === $data['take_stock'] && 'note' === $data['kind'] );
check( 'a line of an unknown kind or source is stored as a typed fish', array( 'fish', 'typed' ) === array( Store::line_columns( array( 'kind' => 'x', 'source' => 'y', 'name' => 'A' ), 1 )[0]['kind'], Store::line_columns( array( 'kind' => 'x', 'source' => 'y', 'name' => 'A' ), 1 )[0]['source'] ) );

$row = Store::hydrate_invoice(
	array(
		'id'             => '7',
		'product_id'     => '0',
		'handle'         => 'Tester',
		'handle_key'     => 'tester',
		'forum_user_id'  => null,
		'customer_id'    => '15',
		'customer_name'  => 'Test Person',
		'customer_email' => 'person@example.test',
		'state'          => 'weird',
		'shipping'       => 'teleport',
		'shipping_slug'  => '',
		'flat_total'     => '0.00',
		'total'          => '599.50',
		'title'          => '',
		'notes'          => null,
		'order_id'       => null,
		'version'        => '4',
		'created_by'     => '3',
		'created_at'     => '2026-11-03 15:00:00',
		'updated_by'     => '3',
		'updated_at'     => '2026-11-03 15:00:00',
		'published_by'   => null,
		'published_at'   => '0000-00-00 00:00:00',
		'paid_at'        => null,
	)
);
check( 'an invoice row as typed values: ids ints or null, money floats, a flat total of 0 kept, an empty date null', 7 === $row['id'] && null === $row['product_id'] && 15 === $row['customer_id'] && 0.0 === $row['flat_total'] && 599.5 === $row['total'] && 4 === $row['version'] && null === $row['published_at'] && null === $row['paid_at'] && '' === $row['notes'] );
check( 'a state or shipping choice the code does not know reads as a draft / Premium', 'draft' === $row['state'] && 'premium' === $row['shipping'] );
check( 'no flat total stays null', null === Store::hydrate_invoice( array( 'flat_total' => null ) )['flat_total'] );
check_same( 'ids(): positive, unique, typed', array( 5, 7 ), Store::ids( array( '5', 5, 0, -1, 'x', 7.0, null ) ) );

echo "7. The only file that touches \$wpdb\n";
$touching = array();
$scanned  = 0;
foreach ( array_merge( glob( dirname( __DIR__ ) . '/*.php' ), glob( dirname( __DIR__ ) . '/views/*.php' ) ) as $file ) {
	$scanned++;
	// The code, not the comments: a comment may well say "nothing here touches $wpdb".
	foreach ( token_get_all( (string) file_get_contents( $file ) ) as $token ) {
		if ( is_array( $token ) && T_VARIABLE === $token[0] && '$wpdb' === $token[1] ) {
			$touching[] = basename( $file );
			break;
		}
	}
}
check_same( 'class-store.php, and no other file of the section', array( 'class-store.php' ), $touching );
check( 'every class file of the section was looked at', $scanned >= 13 );
$source = (string) file_get_contents( dirname( __DIR__ ) . '/class-store.php' );
check( 'it never drops, truncates or alters a table by hand', ! preg_match( '/\b(DROP|TRUNCATE|ALTER)\s+TABLE\b/i', $source ) );
check( 'it writes only its own two tables: every INSERT / UPDATE / DELETE goes to self::table() or a variable holding it', ! preg_match( '/(INSERT INTO|UPDATE|DELETE FROM)\s+\{?\$wpdb->(posts|postmeta|options|prefix)/i', $source ) && 0 === preg_match_all( '/woocommerce_order_item(s|meta)[^;]*\b(insert|update|delete)\(/i', $source ) );
check( 'one writer per invoice: a named lock on the database server, asked for without waiting, with this site\'s database and prefix in its name', 1 === preg_match_all( '/SELECT GET_LOCK\(%s, 0\)/', $source ) && 1 === preg_match_all( '/SELECT RELEASE_LOCK\(%s\)/', $source ) && false !== strpos( $source, "DB_NAME : '' ) . '|' . (string) \$wpdb->prefix" ) );
check( 'WooCommerce\'s order-line tables are only ever SELECTed from', 2 === preg_match_all( '/\$items\s+=\s+\$wpdb->prefix \. \'woocommerce_order_items\'/', $source ) && ! preg_match( '/(INSERT|UPDATE|DELETE)[^;"]*\{\$(items|meta)\}/i', $source ) );

fh_finish();
