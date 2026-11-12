<?php
/**
 * Store tests — the parts that need no database (the schema dbDelta is
 * given, which columns count as missing, how fields are typed on the way
 * in and out, the stored counts) and, against a recording stand-in for
 * $wpdb, the install gate (the version option is written only once both
 * tables and every column are there; a failure leaves a marker and is not
 * retried on the front end), the conditional UPDATE behind every status
 * change, and the one read of WooCommerce's order-item tables.
 *
 * Run from the plugin root:
 *   php includes/sections/lost-fish/tests/store-test.php
 *
 * @package FisHotel\Misc\Sections\Lost_Fish
 */

// ABSPATH points at a temporary "WordPress" whose wp-admin/includes/upgrade.php
// holds a dbDelta() that records each statement and — when the test says the
// database works — "creates" the table with every column of the statement.
$fh_base = getenv( 'FH_TEST_TMP' );
$fh_root = rtrim( false !== $fh_base && '' !== $fh_base ? $fh_base : sys_get_temp_dir(), '/\\' ) . '/fh-lostfish-test-' . getmypid() . '/';
if ( ! is_dir( $fh_root . 'wp-admin/includes' ) && ! mkdir( $fh_root . 'wp-admin/includes', 0700, true ) ) {
	fwrite( STDERR, "Cannot create {$fh_root}\n" );
	exit( 1 );
}
file_put_contents(
	$fh_root . 'wp-admin/includes/upgrade.php',
	<<<'PHP'
<?php
function dbDelta( $sql ) {
	global $wpdb;
	$GLOBALS['fh_dbdelta'][] = $sql;
	if ( empty( $GLOBALS['fh_db_works'] ) || ! preg_match( '/CREATE TABLE (\S+) \((.*)\)/s', $sql, $m ) ) {
		return array();
	}
	$columns = array();
	foreach ( explode( "\n", $m[2] ) as $line ) {
		if ( preg_match( '/^\s*([a-z_]+) (bigint|int|tinyint|varchar|datetime|text)/', $line, $c ) ) {
			$columns[] = $c[1];
		}
	}
	if ( ! empty( $GLOBALS['fh_db_drops_column'] ) ) {
		$columns = array_values( array_diff( $columns, array( $GLOBALS['fh_db_drops_column'] ) ) );
	}
	$wpdb->tables[]         = $m[1];
	$wpdb->columns[ $m[1] ] = $columns;
	return array();
}
PHP
);
define( 'ABSPATH', $fh_root );
register_shutdown_function(
	static function () use ( $fh_root ) {
		@unlink( $fh_root . 'wp-admin/includes/upgrade.php' );
		@rmdir( $fh_root . 'wp-admin/includes' );
		@rmdir( $fh_root . 'wp-admin' );
		@rmdir( $fh_root );
	}
);

require_once __DIR__ . '/wp-stubs.php';

use FisHotel\Misc\Sections\Lost_Fish\Store;

/**
 * A $wpdb that records what it is asked and answers from a script.
 */
class Fh_Wpdb {

	public $prefix        = 'wp_';
	public $queries       = array();
	public $tables        = array();
	public $columns       = array();
	public $affected      = 1;
	public $col           = array();
	public $last_error    = '';
	public $insert_id     = 0;
	public $inserted      = array();
	public $fail_next_col = false;

	public function get_charset_collate() {
		return 'DEFAULT CHARSET=utf8mb4';
	}

	public function prepare( $sql, ...$args ) {
		$args = isset( $args[0] ) && is_array( $args[0] ) ? $args[0] : $args;
		$i    = 0;
		return preg_replace_callback(
			'/%[ds]/',
			static function ( $m ) use ( &$i, $args ) {
				$value = $args[ $i++ ];
				return '%d' === $m[0] ? (string) (int) $value : "'" . addslashes( (string) $value ) . "'";
			},
			$sql
		);
	}

	public function get_var( $sql ) {
		$this->queries[] = $sql;
		if ( preg_match( "/SHOW TABLES LIKE '([^']+)'/", $sql, $m ) ) {
			return in_array( $m[1], $this->tables, true ) ? $m[1] : null;
		}
		return 0;
	}

	public function get_col( $sql ) {
		$this->queries[] = $sql;
		if ( preg_match( '/SHOW COLUMNS FROM (\S+)/', $sql, $m ) ) {
			return isset( $this->columns[ $m[1] ] ) ? $this->columns[ $m[1] ] : array();
		}
		if ( $this->fail_next_col ) {
			$this->last_error = 'Table does not exist';
			return array();
		}
		return $this->col;
	}

	public function get_row( $sql, $output = null ) {
		$this->queries[] = $sql;
		return null;
	}

	public function get_results( $sql, $output = null ) {
		$this->queries[] = $sql;
		return array();
	}

	public function query( $sql ) {
		$this->queries[] = $sql;
		return $this->affected;
	}

	public function insert( $table, $data ) {
		$this->inserted[] = array( $table, $data );
		$this->insert_id  = 77;
		return 1;
	}

	public function suppress_errors( $suppress = true ) {
		return false;
	}
}

$GLOBALS['fh_dbdelta'] = array();

/**
 * Store::install() against a fresh recording $wpdb.
 *
 * @param bool   $works Whether dbDelta() creates the tables.
 * @param string $drops A column that does not arrive ('' = none).
 * @return bool
 */
function fh_install( $works, $drops = '' ) {
	$GLOBALS['wpdb']               = new Fh_Wpdb();
	$GLOBALS['fh_dbdelta']         = array();
	$GLOBALS['fh_db_works']        = $works;
	$GLOBALS['fh_db_drops_column'] = $drops;
	unset( $GLOBALS['fh_options'][ Store::DB_VERSION_OPTION ] );
	return Store::install( true );
}

echo "The schema\n";
$GLOBALS['wpdb'] = new Fh_Wpdb();
$schema          = Store::schema( 'DEFAULT CHARSET=utf8mb4' );
check_same( 'two tables', 2, count( $schema ) );
check( 'named with the site prefix', false !== strpos( $schema[0], 'CREATE TABLE wp_fh_lostfish_cases (' ) && false !== strpos( $schema[1], 'CREATE TABLE wp_fh_lostfish_log (' ) );
foreach ( $schema as $index => $sql ) {
	check( 'table ' . ( $index + 1 ) . ': two spaces after PRIMARY KEY, as dbDelta needs', false !== strpos( $sql, 'PRIMARY KEY  (id)' ) );
	check( 'table ' . ( $index + 1 ) . ': KEY, never INDEX; types in lower case, as MySQL reports them', 0 === preg_match( '/\bINDEX\b|BIGINT|VARCHAR|DATETIME|TINYINT/', $sql ) );
}
$missing = array();
foreach ( array_keys( Store::CASE_COLUMNS ) as $column ) {
	if ( 1 !== preg_match( '/^\s*' . $column . ' /m', $schema[0] ) ) {
		$missing[] = $column;
	}
}
check_same( 'every column the store writes is in the schema', array(), $missing );
$missing = array();
foreach ( Store::LOG_COLUMNS as $column ) {
	if ( 1 !== preg_match( '/^\s*' . $column . ' /m', $schema[1] ) ) {
		$missing[] = $column;
	}
}
check_same( '… and every column of the log', array(), $missing );
check( 'money is whole cents', 1 === preg_match( '/unit_paid bigint\(20\) NOT NULL/', $schema[0] ) && 1 === preg_match( '/ship_diff bigint\(20\) NULL/', $schema[0] ) );
check( 'a shipping difference and a frozen amount can be "not known" (NULL) — never a silent zero', 1 === preg_match( '/ship_diff bigint\(20\) NULL,/', $schema[0] ) && 1 === preg_match( '/amount_refund bigint\(20\) NULL,/', $schema[0] ) );

echo "\nMissing columns\n";
$all = array(
	'lostfish_cases' => array_merge( array( 'id' ), array_keys( Store::CASE_COLUMNS ) ),
	'lostfish_log'   => array_merge( array( 'id' ), Store::LOG_COLUMNS ),
);
check_same( 'everything there: nothing is missing', array(), Store::missing_columns( $all ) );
check( '… and the gate may open', Store::schema_ready( $all ) );
$less                   = $all;
$less['lostfish_cases'] = array_values( array_diff( $less['lostfish_cases'], array( 'ship_diff', 'token_salt' ) ) );
check_same( 'two columns short', array( 'lostfish_cases.ship_diff', 'lostfish_cases.token_salt' ), Store::missing_columns( $less ) );
check( '… the gate stays shut', ! Store::schema_ready( $less ) );
check( 'a table that could not be read at all: the gate stays shut', ! Store::schema_ready( array( 'lostfish_cases' => $all['lostfish_cases'] ) ) );
check( 'column names are compared whatever their case', Store::schema_ready( array( 'lostfish_cases' => array_map( 'strtoupper', $all['lostfish_cases'] ), 'lostfish_log' => $all['lostfish_log'] ) ) );

echo "\nThe install gate\n";
$ok = fh_install( true );
check_same( 'a database that works: installed, the version written', array( true, Store::DB_VERSION ), array( $ok, get_option( Store::DB_VERSION_OPTION ) ) );
check_same( '… autoloaded, so a booted site pays no query for the gate', true, $GLOBALS['fh_autoload'][ Store::DB_VERSION_OPTION ] );
check_same( '… both tables went through dbDelta', 2, count( $GLOBALS['fh_dbdelta'] ) );
check( '… and the store calls itself installed', Store::installed() );

$ok = fh_install( false );
check_same( 'a database that creates nothing: not installed', false, $ok );
check( '… a failure marker instead of the version', 1 === preg_match( '/^failed:' . preg_quote( Store::DB_VERSION, '/' ) . ':\d+$/', (string) get_option( Store::DB_VERSION_OPTION ) ) );
check( '… and the store does not call itself installed', ! Store::installed() );

$ok = fh_install( true, 'ship_diff' );
check_same( 'both tables exist but one column did not arrive: not installed — the version waits for the column', array( false, false ), array( $ok, Store::installed() ) );

echo "\nWhen install is tried\n";
$GLOBALS['fh_options'][ Store::DB_VERSION_OPTION ] = Store::DB_VERSION;
$GLOBALS['wpdb']                                   = new Fh_Wpdb();
check_same( 'the version matches: nothing runs, on the front end or in wp-admin', array( false, false, 0 ), array( Store::maybe_install( false ), Store::maybe_install( true ), count( $GLOBALS['wpdb']->queries ) ) );

$GLOBALS['fh_options'][ Store::DB_VERSION_OPTION ] = 'failed:' . Store::DB_VERSION . ':' . time();
check_same( 'a failure a moment ago: not retried on the front end, nor in wp-admin yet', array( false, false, 0 ), array( Store::maybe_install( false ), Store::maybe_install( true ), count( $GLOBALS['wpdb']->queries ) ) );

$GLOBALS['fh_options'][ Store::DB_VERSION_OPTION ] = 'failed:' . Store::DB_VERSION . ':' . ( time() - Store::RETRY_SECONDS - 5 );
check_same( 'a failure over an hour ago: still not retried on the front end', array( false, 0 ), array( Store::maybe_install( false ), count( $GLOBALS['wpdb']->queries ) ) );

echo "\nFields in, fields out\n";
$clean = Store::clean_fields(
	array(
		'status'        => 'sent',
		'qty'           => '2',
		'unit_paid'     => '6000',
		'ship_diff'     => null,
		'amount_refund' => '2901',
		'sent_at'       => '',
		'done_note'     => null,
		'fish_name'     => str_repeat( 'x', 300 ),
		'not_a_column'  => 'dropped',
		'needs_check'   => true,
	)
);
check_same( 'integers are integers', array( 2, 6000, 2901, 1 ), array( $clean['qty'], $clean['unit_paid'], $clean['amount_refund'], $clean['needs_check'] ) );
check_same( 'a column that may be NULL keeps null — and an empty date is NULL, not 0000-00-00', array( null, null, null ), array( $clean['ship_diff'], $clean['sent_at'], $clean['done_note'] ) );
check_same( 'text is cut to its column', 200, strlen( $clean['fish_name'] ) );
check( 'what is not a column is dropped', ! array_key_exists( 'not_a_column', $clean ) );
check_same( 'null into a column that may not be NULL becomes its empty value', array( 0, '' ), array_values( Store::clean_fields( array( 'qty' => null, 'choice' => null ) ) ) );

$case = Store::hydrate_case(
	array(
		'id'            => '12',
		'status'        => 'sent',
		'qty'           => '2',
		'unit_paid'     => '6000',
		'ship_diff'     => null,
		'amount_refund' => '2901',
		'sent_at'       => '2026-11-12 15:00:00',
		'reminded_at'   => null,
	)
);
check_same( 'a row comes back typed: ints, strings, and null where the database had NULL', array( 12, 'sent', 2, 6000, null, 2901, '2026-11-12 15:00:00', null ), array( $case['id'], $case['status'], $case['qty'], $case['unit_paid'], $case['ship_diff'], $case['amount_refund'], $case['sent_at'], $case['reminded_at'] ) );
check( 'every column is there, even ones the row did not carry', ! array_diff( array_keys( Store::CASE_COLUMNS ), array_keys( $case ) ) );

echo "\nA status change is one conditional UPDATE\n";
$GLOBALS['wpdb'] = new Fh_Wpdb();
$store           = new Store();
$moved           = $store->transition( 12, array( 'sent' ), 'answered', array( 'choice' => 'refund', 'answered_at' => '2026-11-13 10:00:00', 'reminded_at' => null ) );
$sql             = end( $GLOBALS['wpdb']->queries );
check( 'the UPDATE names the status it expects', $moved && 1 === preg_match( "/^UPDATE wp_fh_lostfish_cases SET .*status = 'answered'.* WHERE id = 12 AND status IN \('sent'\)$/", $sql ), $sql );
check( '… writes the fields that go with it, NULL as NULL', false !== strpos( $sql, "choice = 'refund'" ) && false !== strpos( $sql, 'reminded_at = NULL' ) );

$GLOBALS['wpdb']->affected = 0;
check_same( 'no row in that status: nothing moved, and the caller is told', false, $store->transition( 12, array( 'sent' ), 'answered', array( 'choice' => 'credit' ) ) );
$before = count( $GLOBALS['wpdb']->queries );
check_same( 'a status that is not one: no query at all', array( false, $before ), array( $store->transition( 12, array( 'sent' ), 'lost', array() ), count( $GLOBALS['wpdb']->queries ) ) );
check_same( '… nor from a status that is not one', array( false, $before ), array( $store->transition( 12, array( 'nowhere' ), 'answered', array() ), count( $GLOBALS['wpdb']->queries ) ) );

$GLOBALS['wpdb']->affected = 1;
$store->update_case( 12, array( 'status' => 'done', 'ship_diff' => 3099 ) );
$sql = end( $GLOBALS['wpdb']->queries );
check( 'update_case() never changes the status', false === strpos( $sql, 'status =' ) && false !== strpos( $sql, 'ship_diff = 3099' ) && 1 === preg_match( '/WHERE id = 12$/', $sql ), $sql );

$GLOBALS['wpdb']->affected = 1;
check( 'a reminder is marked only on a sent case that has none yet', $store->mark_reminded( 12, '2026-11-28 15:00:00' ) && 1 === preg_match( "/WHERE id = 12 AND status = 'sent' AND reminded_at IS NULL$/", end( $GLOBALS['wpdb']->queries ) ) );
$GLOBALS['wpdb']->affected = 0;
check_same( '… a second run marks nothing', false, $store->mark_reminded( 12, '2026-11-28 15:00:00' ) );

echo "\nInsert, log\n";
$GLOBALS['wpdb'] = new Fh_Wpdb();
$store           = new Store();
$id              = $store->insert_case( array( 'order_id' => 501, 'fish_name' => 'Lagoon Goby', 'qty' => 1 ) );
$row             = $GLOBALS['wpdb']->inserted[0];
check_same( 'a case is inserted open, with its timestamps', array( 77, 'wp_fh_lostfish_cases', 'open', 501 ), array( $id, $row[0], $row[1]['status'], $row[1]['order_id'] ) );
check( '… in UTC', 1 === preg_match( '/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/', $row[1]['created_at'] ) && $row[1]['created_at'] === $row[1]['updated_at'] );
$store->add_log( 'covered', 'Replaced from the extra.', 0, 0, 3 );
$row = $GLOBALS['wpdb']->inserted[1];
check_same( 'a log line', array( 'wp_fh_lostfish_log', 'covered', 'Replaced from the extra.', 3 ), array( $row[0], $row[1]['event'], $row[1]['message'], $row[1]['user_id'] ) );

echo "\nThe one read of WooCommerce's order-item tables\n";
$GLOBALS['wpdb']      = new Fh_Wpdb();
$GLOBALS['wpdb']->col = array( '602', '601' );
$store                = new Store();
$found                = $store->orders_with_lines( array( 601, 602, 603 ), array( 812 ) );
$sql                  = end( $GLOBALS['wpdb']->queries );
check_same( 'the orders it names', array( 602, 601 ), $found );
check( 'reads the order-item tables — the same with HPOS on or off', false !== strpos( $sql, 'wp_woocommerce_order_items oi' ) && false !== strpos( $sql, 'wp_woocommerce_order_itemmeta m' ) );
check( '… only among the orders it was handed', false !== strpos( $sql, 'oi.order_id IN (601,602,603)' ) );
check( '… a line of the product or the variation, or a line out of an invoice', false !== strpos( $sql, "m.meta_key IN ('_product_id','_variation_id') AND m.meta_value IN ('812')" ) && false !== strpos( $sql, "m.meta_key IN ('_fh_invoice_fish','_fh_invoice_lines')" ) );
check( '… and never touches the orders tables themselves', 0 === preg_match( '/wc_orders|wp_posts|postmeta/', $sql ) );

$store->orders_with_lines( array( 601 ), array() );
check( 'without a product: only the invoice lines are looked for', false === strpos( end( $GLOBALS['wpdb']->queries ), '_product_id' ) );
$before = count( $GLOBALS['wpdb']->queries );
check_same( 'no orders: no query, no orders', array( array(), $before ), array( $store->orders_with_lines( array(), array( 812 ) ), count( $GLOBALS['wpdb']->queries ) ) );
$GLOBALS['wpdb']->fail_next_col = true;
check_same( 'the tables cannot be read: null — the caller then looks at every open order', null, $store->orders_with_lines( array( 601 ), array( 812 ) ) );

echo "\nThe stored counts\n";
$GLOBALS['fh_options'] = array();
check_same( 'nothing stored: nothing needs anybody, nothing is live', array( 0, false ), array( Store::needs_you_count(), Store::anything_live() ) );
$GLOBALS['fh_options'][ Store::COUNTS_OPTION ] = array( 'open' => 2, 'sent' => 3, 'answered' => 1, 'done' => 9, 'closed' => 4, 'stale' => 1 );
check_same( 'needs you = to send + to act on + no answer', 4, Store::needs_you_count() );
check( 'something is live while a case is open or sent', Store::anything_live() );
$GLOBALS['fh_options'][ Store::COUNTS_OPTION ] = array( 'done' => 9, 'closed' => 4 );
check( 'only finished cases: the cron tick has nothing to do', ! Store::anything_live() );
$GLOBALS['fh_options'][ Store::COUNTS_OPTION ] = 'garbage';
check_same( 'a broken option counts as nothing', 0, Store::needs_you_count() );

fh_finish();
