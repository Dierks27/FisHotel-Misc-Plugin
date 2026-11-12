<?php
/**
 * Invoices store — the ONLY file in the section that touches $wpdb.
 *
 * Two tables, created with dbDelta on the section's first boot and on
 * plugin activation, behind the autoloaded option
 * fishotel_invoices_db_version — which is written only once both tables
 * and every one of their columns really exist, so a booted site pays no
 * query for it and a CREATE or ALTER that failed is tried again:
 *
 *   {prefix}fh_invoices       one row per invoice: the WooCommerce product
 *                             it became (null while a draft), the handle
 *                             (+ its lower-case key), the forum user id,
 *                             the site customer, name and e-mail, the state
 *                             (draft / published / paid / cancelled /
 *                             trashed), the shipping choice and class slug,
 *                             the flat total (or null), the total, the
 *                             title it was published under, notes, the
 *                             order that paid it, a version (two open
 *                             windows cannot overwrite each other), and who
 *                             and when: created, updated, published, paid.
 *   {prefix}fh_invoice_lines  its lines: kind (fish / credit / discount /
 *                             note), name, qty, price per fish, the ids of
 *                             where the fish came from (product, variation,
 *                             species, tank group = Clean Room stay), the
 *                             size (slug + label), the source, the "take
 *                             from shop stock" tick, the position.
 *
 * A failed install is not retried on every request — a visitor would pay
 * a dbDelta each time — but at most once an hour, and only in wp-admin
 * (fishotel_invoices_db_retry holds the time of the failure).
 *
 * Times are stored in UTC. Line ids are stable: a save updates the rows
 * it is given back, inserts the new ones and deletes the rest, in one
 * transaction with the invoice row.
 *
 * Two reads go to WooCommerce's own order-item tables, because the order
 * API has no "which orders contain this product" query:
 * order_ids_for_products() and order_ids_for_invoice_title(). Those
 * tables hold the order lines with HPOS on or off; the orders themselves
 * are then read through wc_get_order() by the Shop.
 *
 * Every method is wrapped: before the tables exist, or on a database
 * error, a read answers an empty list (or null where "unknown" must not
 * be mistaken for "none") and a write answers false.
 *
 * @package FisHotel\Misc\Sections\Invoices
 */

namespace FisHotel\Misc\Sections\Invoices;

defined( 'ABSPATH' ) || exit;

/**
 * Class Store
 */
class Store {

	/**
	 * Schema version. Bump to trigger a dbDelta upgrade.
	 *
	 * @var string
	 */
	const DB_VERSION = '1.0';

	/**
	 * Autoloaded option holding the installed schema version.
	 *
	 * @var string
	 */
	const DB_VERSION_OPTION = 'fishotel_invoices_db_version';

	/**
	 * Autoloaded option holding "<version>:<time>" of the last install
	 * that failed.
	 *
	 * @var string
	 */
	const DB_RETRY_OPTION = 'fishotel_invoices_db_retry';

	/**
	 * A failed install is retried at most this often, and only in wp-admin.
	 *
	 * @var int
	 */
	const RETRY_SECONDS = 3600;

	const TABLES = array( 'invoices', 'invoice_lines' );

	/**
	 * Every column the code reads or writes. The version option is written
	 * only once each of them is really there — a new column goes into
	 * schema() AND into this list.
	 *
	 * @var array<string, string[]>
	 */
	const COLUMNS = array(
		'invoices'      => array( 'id', 'product_id', 'handle', 'handle_key', 'forum_user_id', 'customer_id', 'customer_name', 'customer_email', 'state', 'shipping', 'shipping_slug', 'flat_total', 'total', 'title', 'notes', 'order_id', 'version', 'created_by', 'created_at', 'updated_by', 'updated_at', 'published_by', 'published_at', 'paid_at' ),
		'invoice_lines' => array( 'id', 'invoice_id', 'kind', 'name', 'qty', 'unit_price', 'product_id', 'variation_id', 'species_id', 'stay_id', 'size_slug', 'size_label', 'source', 'take_stock', 'sort' ),
	);

	/**
	 * The invoice columns a caller may write, with their wpdb formats.
	 * (id, version and the created / updated stamps are the store's own.)
	 *
	 * @var array<string, string>
	 */
	const INVOICE_FIELDS = array(
		'product_id'     => '%d',
		'handle'         => '%s',
		'handle_key'     => '%s',
		'forum_user_id'  => '%d',
		'customer_id'    => '%d',
		'customer_name'  => '%s',
		'customer_email' => '%s',
		'state'          => '%s',
		'shipping'       => '%s',
		'shipping_slug'  => '%s',
		'flat_total'     => '%f',
		'total'          => '%f',
		'title'          => '%s',
		'notes'          => '%s',
		'order_id'       => '%d',
		'published_by'   => '%d',
		'published_at'   => '%s',
		'paid_at'        => '%s',
	);

	const LIST_LIMIT = 200;

	/**
	 * Fully-prefixed table name.
	 *
	 * @param string $name One of TABLES.
	 * @return string
	 */
	public static function table( $name ) {
		global $wpdb;
		return $wpdb->prefix . 'fh_' . $name;
	}

	/* ============================================================== */
	/*  Install                                                        */
	/* ============================================================== */

	/**
	 * Whether the installed schema is the current one — one autoloaded
	 * option, no query. Every read and write waits for it.
	 *
	 * @return bool
	 */
	public static function ready() {
		return self::DB_VERSION === get_option( self::DB_VERSION_OPTION );
	}

	/**
	 * Section boot: install when the version option says so. A failed
	 * install waits RETRY_SECONDS and is then tried again in wp-admin only.
	 *
	 * @param bool $is_admin is_admin().
	 * @return bool Whether install() ran.
	 */
	public static function maybe_install( $is_admin ) {
		if ( self::ready() ) {
			return false;
		}

		$failed = get_option( self::DB_RETRY_OPTION );
		if ( is_string( $failed ) && preg_match( '/^' . preg_quote( self::DB_VERSION, '/' ) . ':(\d+)$/', $failed, $m ) ) {
			if ( ! $is_admin || time() - (int) $m[1] < self::RETRY_SECONDS ) {
				return false;
			}
		}

		self::install( true );
		return true;
	}

	/**
	 * Create / upgrade the tables via dbDelta. The version option is
	 * written (autoloaded) only once both tables and every column of
	 * COLUMNS are really there; otherwise the failure is noted and
	 * maybe_install() waits.
	 *
	 * @param bool $force Skip the version gate (plugin activation: a dropped table comes back).
	 * @return bool Whether the tables are installed.
	 */
	public static function install( $force = false ) {
		if ( ! $force && self::ready() ) {
			return true;
		}

		global $wpdb;

		try {
			if ( ! function_exists( 'dbDelta' ) ) {
				require_once ABSPATH . 'wp-admin/includes/upgrade.php';
			}
			foreach ( self::schema( $wpdb->get_charset_collate() ) as $statement ) {
				dbDelta( $statement );
			}
			$ok = self::tables_exist() && self::schema_ready( self::present_columns() );
		} catch ( \Throwable $e ) {
			$ok = false;
		}

		if ( $ok ) {
			update_option( self::DB_VERSION_OPTION, self::DB_VERSION, true );
			delete_option( self::DB_RETRY_OPTION );
		} else {
			update_option( self::DB_RETRY_OPTION, self::DB_VERSION . ':' . time(), true );
		}

		return $ok;
	}

	/**
	 * The CREATE TABLE statements dbDelta runs (pure apart from the table
	 * prefix, so a test can read the schema).
	 *
	 * @param string $charset_collate The charset / collation clause.
	 * @return string[]
	 */
	public static function schema( $charset_collate = '' ) {
		$invoices = self::table( 'invoices' );
		$lines    = self::table( 'invoice_lines' );

		// dbDelta is whitespace-sensitive: each column on its own line, two
		// spaces after PRIMARY KEY, KEY (not INDEX) with a name. Column types
		// are written as MySQL / MariaDB report them (lower case, int(11),
		// bigint(20) unsigned): dbDelta compares the type as written and
		// would otherwise ALTER every column on every install.
		return array(
			"CREATE TABLE {$invoices} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				product_id bigint(20) unsigned NULL,
				handle varchar(120) NOT NULL DEFAULT '',
				handle_key varchar(120) NOT NULL DEFAULT '',
				forum_user_id bigint(20) unsigned NULL,
				customer_id bigint(20) unsigned NULL,
				customer_name varchar(190) NOT NULL DEFAULT '',
				customer_email varchar(190) NOT NULL DEFAULT '',
				state varchar(20) NOT NULL DEFAULT 'draft',
				shipping varchar(20) NOT NULL DEFAULT 'premium',
				shipping_slug varchar(100) NOT NULL DEFAULT '',
				flat_total decimal(12,2) NULL,
				total decimal(12,2) NOT NULL DEFAULT 0,
				title varchar(200) NOT NULL DEFAULT '',
				notes text NULL,
				order_id bigint(20) unsigned NULL,
				version int(10) unsigned NOT NULL DEFAULT 1,
				created_by bigint(20) unsigned NOT NULL DEFAULT 0,
				created_at datetime NOT NULL,
				updated_by bigint(20) unsigned NOT NULL DEFAULT 0,
				updated_at datetime NOT NULL,
				published_by bigint(20) unsigned NULL,
				published_at datetime NULL,
				paid_at datetime NULL,
				PRIMARY KEY  (id),
				KEY product_id (product_id),
				KEY handle_key (handle_key),
				KEY customer_id (customer_id),
				KEY state (state),
				KEY order_id (order_id),
				KEY published_at (published_at)
			) {$charset_collate};",
			"CREATE TABLE {$lines} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				invoice_id bigint(20) unsigned NOT NULL,
				kind varchar(10) NOT NULL DEFAULT 'fish',
				name varchar(200) NOT NULL DEFAULT '',
				qty int(11) NOT NULL DEFAULT 1,
				unit_price decimal(12,2) NULL,
				product_id bigint(20) unsigned NULL,
				variation_id bigint(20) unsigned NULL,
				species_id bigint(20) unsigned NULL,
				stay_id bigint(20) unsigned NULL,
				size_slug varchar(100) NULL,
				size_label varchar(100) NULL,
				source varchar(10) NOT NULL DEFAULT 'typed',
				take_stock tinyint(1) NOT NULL DEFAULT 0,
				sort int(11) NOT NULL DEFAULT 0,
				PRIMARY KEY  (id),
				KEY invoice_id (invoice_id),
				KEY product_id (product_id),
				KEY stay_id (stay_id)
			) {$charset_collate};",
		);
	}

	/**
	 * Whether both tables exist (install and the admin page's notice only).
	 *
	 * @return bool
	 */
	public static function tables_exist() {
		global $wpdb;

		try {
			foreach ( self::TABLES as $name ) {
				$table = self::table( $name );
				if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
					return false;
				}
			}
		} catch ( \Throwable $e ) {
			return false;
		}

		return true;
	}

	/**
	 * The columns really present on the tables (install only). A table
	 * that cannot be read gives an empty list.
	 *
	 * @return array<string, string[]> Table name without the prefix → its columns.
	 */
	private static function present_columns() {
		global $wpdb;

		$out = array();
		foreach ( self::TABLES as $table ) {
			try {
				$columns = $wpdb->get_col( 'SHOW COLUMNS FROM ' . self::table( $table ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			} catch ( \Throwable $e ) {
				$columns = array();
			}
			$out[ $table ] = array_map( 'strval', is_array( $columns ) ? $columns : array() );
		}

		return $out;
	}

	/**
	 * The columns that are still missing (pure): COLUMNS minus what the
	 * tables really have.
	 *
	 * @param array<string, string[]> $present Table name without the prefix → its columns.
	 * @return string[] "table.column".
	 */
	public static function missing_columns( array $present ) {
		$missing = array();
		foreach ( self::COLUMNS as $table => $columns ) {
			$have = isset( $present[ $table ] ) && is_array( $present[ $table ] ) ? array_map( 'strtolower', array_map( 'strval', $present[ $table ] ) ) : array();
			foreach ( $columns as $column ) {
				if ( ! in_array( strtolower( $column ), $have, true ) ) {
					$missing[] = $table . '.' . $column;
				}
			}
		}
		return $missing;
	}

	/**
	 * Whether the version gate may open (pure): no column is missing.
	 *
	 * @param array<string, string[]> $present Table name without the prefix → its columns.
	 * @return bool
	 */
	public static function schema_ready( array $present ) {
		return ! self::missing_columns( $present );
	}

	/* ============================================================== */
	/*  Low level                                                      */
	/* ============================================================== */

	/**
	 * Rows of a prepared query, or null when the database refused it — so
	 * a caller can tell "nothing" from "unknown".
	 *
	 * @param string $sql    Query with placeholders.
	 * @param array  $params Its values.
	 * @return array[]|null
	 */
	private function rows( $sql, array $params = array() ) {
		if ( ! self::ready() ) {
			return null;
		}

		global $wpdb;

		try {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery -- prepared right here when it has values.
			$rows = $wpdb->get_results( $params ? $wpdb->prepare( $sql, $params ) : $sql, ARRAY_A );
		} catch ( \Throwable $e ) {
			return null;
		}

		if ( ! is_array( $rows ) || '' !== (string) $wpdb->last_error ) {
			return null;
		}

		return $rows;
	}

	/**
	 * A statement without values (the transaction verbs), wrapped.
	 *
	 * @param string $sql Statement.
	 * @return bool
	 */
	private function raw( $sql ) {
		global $wpdb;
		try {
			return false !== $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery -- a constant statement.
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	/**
	 * Positive, unique ints of a list.
	 *
	 * @param mixed $ids Ids.
	 * @return int[]
	 */
	public static function ids( $ids ) {
		$out = array();
		foreach ( is_array( $ids ) ? $ids : array() as $id ) {
			if ( is_numeric( $id ) && (int) $id > 0 ) {
				$out[ (int) $id ] = (int) $id;
			}
		}
		return array_values( $out );
	}

	/**
	 * "Now" as the tables store it (UTC). Callers ask the store they were
	 * given (`$store->now()`), so a test's in-memory store can keep its
	 * own clock.
	 *
	 * @return string
	 */
	public static function now() {
		return gmdate( 'Y-m-d H:i:s' );
	}

	/* ============================================================== */
	/*  Invoices: reads                                                */
	/* ============================================================== */

	/**
	 * One invoice.
	 *
	 * @param int $id Invoice id.
	 * @return array|null
	 */
	public function invoice( $id ) {
		if ( (int) $id <= 0 ) {
			return null;
		}
		$rows = $this->rows( 'SELECT * FROM ' . self::table( 'invoices' ) . ' WHERE id = %d', array( (int) $id ) );
		return $rows ? self::hydrate_invoice( $rows[0] ) : null;
	}

	/**
	 * The invoices whose product is one of these — what the paid sync and
	 * the contracts ask.
	 *
	 * @param int[] $product_ids Product ids.
	 * @return array[]
	 */
	public function invoices_by_products( array $product_ids ) {
		$ids = self::ids( $product_ids );
		if ( ! $ids ) {
			return array();
		}
		$rows = $this->rows( 'SELECT * FROM ' . self::table( 'invoices' ) . ' WHERE product_id IN (' . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ') ORDER BY id ASC', $ids );
		return array_map( array( self::class, 'hydrate_invoice' ), $rows ? $rows : array() );
	}

	/**
	 * The invoice a product belongs to.
	 *
	 * @param int $product_id Product id.
	 * @return array|null
	 */
	public function invoice_by_product( $product_id ) {
		$rows = $this->invoices_by_products( array( (int) $product_id ) );
		return $rows ? $rows[0] : null;
	}

	/**
	 * The invoices an order paid.
	 *
	 * @param int $order_id Order id.
	 * @return array[]
	 */
	public function invoices_paid_by_order( $order_id ) {
		if ( (int) $order_id <= 0 ) {
			return array();
		}
		$rows = $this->rows( 'SELECT * FROM ' . self::table( 'invoices' ) . " WHERE order_id = %d AND state = 'paid' ORDER BY id ASC", array( (int) $order_id ) );
		return array_map( array( self::class, 'hydrate_invoice' ), $rows ? $rows : array() );
	}

	/**
	 * The WHERE of a list filter.
	 *
	 * @param array $filters state (string or list), search (in the handle, the name or the title), ids.
	 * @return array{0: string, 1: array}
	 */
	private static function invoices_where( array $filters ) {
		global $wpdb;

		$where  = array( '1=1' );
		$params = array();

		$states = isset( $filters['state'] ) ? array_values( array_intersect( (array) $filters['state'], Rules::STATES ) ) : array();
		if ( $states ) {
			$where[] = 'state IN (' . implode( ',', array_fill( 0, count( $states ), '%s' ) ) . ')';
			$params  = array_merge( $params, $states );
		}

		$search = isset( $filters['search'] ) ? trim( (string) $filters['search'] ) : '';
		if ( '' !== $search ) {
			$like     = '%' . $wpdb->esc_like( $search ) . '%';
			$where[]  = '(handle LIKE %s OR customer_name LIKE %s OR title LIKE %s)';
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
		}

		if ( isset( $filters['ids'] ) ) {
			$ids = self::ids( $filters['ids'] );
			if ( ! $ids ) {
				$where[] = '1=0';
			} else {
				$where[] = 'id IN (' . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ')';
				$params  = array_merge( $params, $ids );
			}
		}

		return array( implode( ' AND ', $where ), $params );
	}

	/**
	 * Invoices for the list, newest first.
	 *
	 * @param array $filters state, search, ids.
	 * @param int   $limit   Page size.
	 * @param int   $offset  Offset.
	 * @return array[]
	 */
	public function invoices( array $filters = array(), $limit = 30, $offset = 0 ) {
		if ( ! self::ready() ) {
			return array();
		}

		list( $where, $params ) = self::invoices_where( $filters );
		$params[]               = max( 1, min( self::LIST_LIMIT, (int) $limit ) );
		$params[]               = max( 0, (int) $offset );

		$rows = $this->rows( 'SELECT * FROM ' . self::table( 'invoices' ) . ' WHERE ' . $where . ' ORDER BY id DESC LIMIT %d OFFSET %d', $params );
		return array_map( array( self::class, 'hydrate_invoice' ), $rows ? $rows : array() );
	}

	/**
	 * How many invoices a list filter matches.
	 *
	 * @param array $filters state, search, ids.
	 * @return int
	 */
	public function count_invoices( array $filters = array() ) {
		if ( ! self::ready() ) {
			return 0;
		}

		list( $where, $params ) = self::invoices_where( $filters );
		$rows                   = $this->rows( 'SELECT COUNT(*) AS n FROM ' . self::table( 'invoices' ) . ' WHERE ' . $where, $params );
		return $rows ? (int) $rows[0]['n'] : 0;
	}

	/**
	 * How many invoices are in each state.
	 *
	 * @return array<string, int>
	 */
	public function state_counts() {
		$out  = array_fill_keys( Rules::STATES, 0 );
		$rows = $this->rows( 'SELECT state, COUNT(*) AS n FROM ' . self::table( 'invoices' ) . ' GROUP BY state' );
		foreach ( $rows ? $rows : array() as $row ) {
			if ( isset( $out[ (string) $row['state'] ] ) ) {
				$out[ (string) $row['state'] ] = (int) $row['n'];
			}
		}
		return $out;
	}

	/**
	 * Every invoice of one customer: the same handle, or the same site
	 * customer, or the same e-mail. Newest first.
	 *
	 * @param array $who        handle_key, customer_id, customer_email — any of them.
	 * @param int   $exclude_id An invoice to leave out (the one being built).
	 * @return array[]
	 */
	public function invoices_for_customer( array $who, $exclude_id = 0 ) {
		$any    = array();
		$params = array();

		$handle_key = isset( $who['handle_key'] ) ? (string) $who['handle_key'] : '';
		if ( '' !== $handle_key ) {
			$any[]    = 'handle_key = %s';
			$params[] = $handle_key;
		}
		if ( ! empty( $who['customer_id'] ) && (int) $who['customer_id'] > 0 ) {
			$any[]    = 'customer_id = %d';
			$params[] = (int) $who['customer_id'];
		}
		$email = isset( $who['customer_email'] ) ? trim( (string) $who['customer_email'] ) : '';
		if ( '' !== $email ) {
			$any[]    = 'customer_email = %s';
			$params[] = $email;
		}
		if ( ! $any ) {
			return array();
		}

		$params[] = (int) $exclude_id;
		$params[] = self::LIST_LIMIT;

		$rows = $this->rows( 'SELECT * FROM ' . self::table( 'invoices' ) . ' WHERE (' . implode( ' OR ', $any ) . ') AND id <> %d ORDER BY id DESC LIMIT %d', $params );
		return array_map( array( self::class, 'hydrate_invoice' ), $rows ? $rows : array() );
	}

	/**
	 * The invoices published in a span of time (UTC) that still have a
	 * product a customer can open: published or paid.
	 *
	 * @param string $start_utc Y-m-d H:i:s, inclusive.
	 * @param string $end_utc   Y-m-d H:i:s, exclusive.
	 * @return array[]
	 */
	public function invoices_published_between( $start_utc, $end_utc ) {
		$rows = $this->rows(
			'SELECT * FROM ' . self::table( 'invoices' ) . " WHERE published_at >= %s AND published_at < %s AND state IN ('published','paid') ORDER BY published_at ASC, id ASC LIMIT %d",
			array( (string) $start_utc, (string) $end_utc, self::LIST_LIMIT )
		);
		return array_map( array( self::class, 'hydrate_invoice' ), $rows ? $rows : array() );
	}

	/**
	 * The handles used before that contain a text, each with the details
	 * of its newest invoice (forum user id, site customer, name, e-mail).
	 *
	 * @param string $term  Text.
	 * @param int    $limit Row cap.
	 * @return array[] handle, forum_user_id, customer_id, customer_name, customer_email.
	 */
	public function handles( $term, $limit = 10 ) {
		if ( ! self::ready() ) {
			return array();
		}

		global $wpdb;

		$key   = Rules::handle_key( $term );
		$table = self::table( 'invoices' );
		$rows  = $this->rows(
			"SELECT i.handle, i.forum_user_id, i.customer_id, i.customer_name, i.customer_email FROM {$table} i INNER JOIN ( SELECT MAX(id) AS id FROM {$table} WHERE handle_key LIKE %s AND handle_key <> '' GROUP BY handle_key ) latest ON latest.id = i.id ORDER BY i.handle ASC LIMIT %d",
			array( '%' . $wpdb->esc_like( $key ) . '%', max( 1, min( 50, (int) $limit ) ) )
		);

		$out = array();
		foreach ( $rows ? $rows : array() as $row ) {
			$out[] = array(
				'handle'         => (string) $row['handle'],
				'forum_user_id'  => ! empty( $row['forum_user_id'] ) ? (int) $row['forum_user_id'] : null,
				'customer_id'    => ! empty( $row['customer_id'] ) ? (int) $row['customer_id'] : null,
				'customer_name'  => (string) $row['customer_name'],
				'customer_email' => (string) $row['customer_email'],
			);
		}
		return $out;
	}

	/* ============================================================== */
	/*  Lines: reads                                                   */
	/* ============================================================== */

	/**
	 * The lines of one invoice, in their order.
	 *
	 * @param int $invoice_id Invoice id.
	 * @return array[]
	 */
	public function lines( $invoice_id ) {
		$map = $this->lines_for_invoices( array( (int) $invoice_id ) );
		return isset( $map[ (int) $invoice_id ] ) ? $map[ (int) $invoice_id ] : array();
	}

	/**
	 * The lines of several invoices.
	 *
	 * @param int[] $invoice_ids Invoice ids.
	 * @return array<int, array[]> Invoice id → its lines.
	 */
	public function lines_for_invoices( array $invoice_ids ) {
		$ids = self::ids( $invoice_ids );
		$out = array_fill_keys( $ids, array() );
		if ( ! $ids ) {
			return $out;
		}

		$rows = $this->rows( 'SELECT * FROM ' . self::table( 'invoice_lines' ) . ' WHERE invoice_id IN (' . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ') ORDER BY invoice_id ASC, sort ASC, id ASC', $ids );
		foreach ( $rows ? $rows : array() as $row ) {
			$line                         = self::hydrate_line( $row );
			$out[ $line['invoice_id'] ][] = $line;
		}
		return $out;
	}

	/**
	 * How many fish of each tank group already stand on other invoices
	 * that are still open (drafts and published, unpaid ones) — so a group
	 * of five is not promised to six customers.
	 *
	 * @param int[] $stay_ids           Tank group (Clean Room stay) ids.
	 * @param int   $exclude_invoice_id The invoice being built.
	 * @return array<int, int> Stay id → fish on other open invoices.
	 */
	public function stay_reservations( array $stay_ids, $exclude_invoice_id = 0 ) {
		$ids = self::ids( $stay_ids );
		if ( ! $ids ) {
			return array();
		}

		$params   = $ids;
		$params[] = (int) $exclude_invoice_id;

		$rows = $this->rows(
			'SELECT l.stay_id, SUM(l.qty) AS qty FROM ' . self::table( 'invoice_lines' ) . ' l INNER JOIN ' . self::table( 'invoices' ) . " i ON i.id = l.invoice_id WHERE l.kind = 'fish' AND l.stay_id IN (" . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ") AND i.state IN ('draft','published') AND i.id <> %d GROUP BY l.stay_id",
			$params
		);

		$out = array();
		foreach ( $rows ? $rows : array() as $row ) {
			$out[ (int) $row['stay_id'] ] = (int) $row['qty'];
		}
		return $out;
	}

	/* ============================================================== */
	/*  Writes                                                         */
	/* ============================================================== */

	/**
	 * The values of the writable invoice columns out of a field list, with
	 * their formats (pure): unknown keys are dropped, an empty id / date /
	 * flat total becomes NULL.
	 *
	 * @param array $fields Fields.
	 * @return array{0: array, 1: string[]} Column → value, and the formats in the same order.
	 */
	public static function invoice_columns( array $fields ) {
		$data    = array();
		$formats = array();
		$null_if = array( 'product_id', 'forum_user_id', 'customer_id', 'order_id', 'published_by', 'published_at', 'paid_at', 'flat_total' );

		foreach ( self::INVOICE_FIELDS as $column => $format ) {
			if ( ! array_key_exists( $column, $fields ) ) {
				continue;
			}
			$value = $fields[ $column ];

			if ( in_array( $column, $null_if, true ) && ( null === $value || '' === $value || ( 'flat_total' !== $column && is_numeric( $value ) && (int) $value <= 0 ) ) ) {
				$data[ $column ] = null;
				$formats[]       = $format;
				continue;
			}

			if ( '%d' === $format ) {
				$value = (int) $value;
			} elseif ( '%f' === $format ) {
				$value = round( (float) $value, 2 );
			} else {
				$value = (string) $value;
			}
			if ( 'state' === $column && ! in_array( $value, Rules::STATES, true ) ) {
				continue;
			}
			if ( 'shipping' === $column && ! in_array( $value, Settings::SHIPPING_CHOICES, true ) ) {
				continue;
			}

			$data[ $column ] = $value;
			$formats[]       = $format;
		}

		return array( $data, $formats );
	}

	/**
	 * The stored columns of one line (pure).
	 *
	 * @param array $line       A normalised line (Lines::normalize_line()).
	 * @param int   $invoice_id Its invoice.
	 * @return array{0: array, 1: string[]}
	 */
	public static function line_columns( array $line, $invoice_id ) {
		$nullable = static function ( $value ) {
			return ! empty( $value ) && (int) $value > 0 ? (int) $value : null;
		};

		$data = array(
			'invoice_id'   => (int) $invoice_id,
			'kind'         => in_array( (string) ( isset( $line['kind'] ) ? $line['kind'] : '' ), Lines::KINDS, true ) ? (string) $line['kind'] : 'fish',
			'name'         => (string) ( isset( $line['name'] ) ? $line['name'] : '' ),
			'qty'          => max( 1, (int) ( isset( $line['qty'] ) ? $line['qty'] : 1 ) ),
			'unit_price'   => isset( $line['unit_price'] ) && null !== $line['unit_price'] ? round( (float) $line['unit_price'], 2 ) : null,
			'product_id'   => $nullable( isset( $line['product_id'] ) ? $line['product_id'] : null ),
			'variation_id' => $nullable( isset( $line['variation_id'] ) ? $line['variation_id'] : null ),
			'species_id'   => $nullable( isset( $line['species_id'] ) ? $line['species_id'] : null ),
			'stay_id'      => $nullable( isset( $line['stay_id'] ) ? $line['stay_id'] : null ),
			'size_slug'    => isset( $line['size_slug'] ) && '' !== (string) $line['size_slug'] ? (string) $line['size_slug'] : null,
			'size_label'   => isset( $line['size_label'] ) && '' !== (string) $line['size_label'] ? (string) $line['size_label'] : null,
			'source'       => in_array( (string) ( isset( $line['source'] ) ? $line['source'] : '' ), Lines::SOURCES, true ) ? (string) $line['source'] : 'typed',
			'take_stock'   => ! empty( $line['take_stock'] ) ? 1 : 0,
			'sort'         => (int) ( isset( $line['sort'] ) ? $line['sort'] : 0 ),
		);

		return array( $data, array( '%d', '%s', '%s', '%d', '%f', '%d', '%d', '%d', '%d', '%s', '%s', '%s', '%d', '%d' ) );
	}

	/**
	 * Create or update an invoice — and, when lines are given, its lines —
	 * in one transaction. An update bumps the version; when a version is
	 * expected and the row has moved on (another window saved it), nothing
	 * is written and the answer says so.
	 *
	 * @param int        $id               Invoice id (0 = a new one).
	 * @param array      $fields           Columns of INVOICE_FIELDS.
	 * @param array|null $lines            Normalised lines to store (null = leave the lines alone).
	 * @param int|null   $expected_version The version the caller read (null = do not check).
	 * @param int        $user_id          Who.
	 * @return array{ok: bool, id: int, version: int, error: string} error is '' | 'conflict' | 'missing' | 'db'.
	 */
	public function save_invoice( $id, array $fields, $lines = null, $expected_version = null, $user_id = 0 ) {
		$fail = static function ( $error ) use ( $id ) {
			return array(
				'ok'      => false,
				'id'      => (int) $id,
				'version' => 0,
				'error'   => $error,
			);
		};

		if ( ! self::ready() ) {
			return $fail( 'db' );
		}

		global $wpdb;

		$id                    = (int) $id;
		$table                 = self::table( 'invoices' );
		$now                   = self::now();
		list( $data, $format ) = self::invoice_columns( $fields );

		if ( ! $this->raw( 'START TRANSACTION' ) ) {
			return $fail( 'db' );
		}

		try {
			if ( $id <= 0 ) {
				$data['version']    = 1;
				$data['created_by'] = (int) $user_id;
				$data['created_at'] = $now;
				$data['updated_by'] = (int) $user_id;
				$data['updated_at'] = $now;
				$format             = array_merge( $format, array( '%d', '%d', '%s', '%d', '%s' ) );

				// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the section's own table.
				if ( false === $wpdb->insert( $table, $data, $format ) || (int) $wpdb->insert_id <= 0 ) {
					$this->raw( 'ROLLBACK' );
					return $fail( 'db' );
				}
				$id      = (int) $wpdb->insert_id;
				$version = 1;
			} else {
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
				$current = $wpdb->get_var( $wpdb->prepare( "SELECT version FROM {$table} WHERE id = %d FOR UPDATE", $id ) );
				if ( null === $current ) {
					$this->raw( 'ROLLBACK' );
					return $fail( 'missing' );
				}
				if ( null !== $expected_version && (int) $expected_version !== (int) $current ) {
					$this->raw( 'ROLLBACK' );
					return $fail( 'conflict' );
				}

				$version            = (int) $current + 1;
				$data['version']    = $version;
				$data['updated_by'] = (int) $user_id;
				$data['updated_at'] = $now;
				$format             = array_merge( $format, array( '%d', '%d', '%s' ) );

				// phpcs:ignore WordPress.DB.DirectDatabaseQuery
				if ( false === $wpdb->update( $table, $data, array( 'id' => $id ), $format, array( '%d' ) ) ) {
					$this->raw( 'ROLLBACK' );
					return $fail( 'db' );
				}
			}

			if ( is_array( $lines ) && ! $this->write_lines( $id, $lines ) ) {
				$this->raw( 'ROLLBACK' );
				return $fail( 'db' );
			}
		} catch ( \Throwable $e ) {
			$this->raw( 'ROLLBACK' );
			return $fail( 'db' );
		}

		if ( ! $this->raw( 'COMMIT' ) ) {
			return $fail( 'db' );
		}

		return array(
			'ok'      => true,
			'id'      => $id,
			'version' => $version,
			'error'   => '',
		);
	}

	/**
	 * Store an invoice's lines inside the caller's transaction: a line
	 * that carries the id of one of this invoice's rows updates it, any
	 * other is inserted, and the rows not given back are deleted.
	 *
	 * @param int     $invoice_id Invoice id.
	 * @param array[] $lines      Normalised lines.
	 * @return bool
	 */
	private function write_lines( $invoice_id, array $lines ) {
		global $wpdb;

		$table = self::table( 'invoice_lines' );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
		$existing = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$table} WHERE invoice_id = %d", (int) $invoice_id ) );
		if ( ! is_array( $existing ) || '' !== (string) $wpdb->last_error ) {
			return false;
		}
		$existing = array_map( 'intval', $existing );
		$kept     = array();

		foreach ( array_values( $lines ) as $line ) {
			if ( ! is_array( $line ) ) {
				continue;
			}
			list( $data, $format ) = self::line_columns( $line, $invoice_id );
			$line_id               = ! empty( $line['id'] ) ? (int) $line['id'] : 0;

			if ( $line_id > 0 && in_array( $line_id, $existing, true ) && ! in_array( $line_id, $kept, true ) ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery
				if ( false === $wpdb->update( $table, $data, array( 'id' => $line_id ), $format, array( '%d' ) ) ) {
					return false;
				}
				$kept[] = $line_id;
				continue;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			if ( false === $wpdb->insert( $table, $data, $format ) ) {
				return false;
			}
			$kept[] = (int) $wpdb->insert_id;
		}

		$gone = array_diff( $existing, $kept );
		if ( $gone ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery -- ints only.
			if ( false === $wpdb->query( "DELETE FROM {$table} WHERE invoice_id = " . (int) $invoice_id . ' AND id IN (' . implode( ',', array_map( 'intval', $gone ) ) . ')' ) ) {
				return false;
			}
		}

		return true;
	}

	/* ============================================================== */
	/*  WooCommerce's order lines                                      */
	/* ============================================================== */

	/**
	 * The orders that contain one of these products: as an order line of
	 * that product (an invoice kept as one line), or as lines the theme
	 * made out of it (their `_fh_invoice_product_id` item meta). Every
	 * order counts, whatever its status. Null when the order-line tables
	 * could not be read — "unknown", never "none".
	 *
	 * @param int[] $product_ids Product ids.
	 * @return array<int, int[]>|null Product id → order ids, newest first.
	 */
	public function order_ids_for_products( array $product_ids ) {
		$ids = self::ids( $product_ids );
		$out = array_fill_keys( $ids, array() );
		if ( ! $ids ) {
			return $out;
		}

		global $wpdb;

		$items = $wpdb->prefix . 'woocommerce_order_items';
		$meta  = $wpdb->prefix . 'woocommerce_order_itemmeta';

		try {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT DISTINCT oi.order_id, im.meta_value AS product_id FROM {$items} oi INNER JOIN {$meta} im ON im.order_item_id = oi.order_item_id WHERE im.meta_key IN ('_product_id','" . Invoices::ITEM_PRODUCT_META . "') AND im.meta_value IN (" . implode( ',', array_fill( 0, count( $ids ), '%s' ) ) . ') ORDER BY oi.order_id DESC',
					array_map( 'strval', $ids )
				),
				ARRAY_A
			);
		} catch ( \Throwable $e ) {
			return null;
		}

		if ( ! is_array( $rows ) || '' !== (string) $wpdb->last_error ) {
			return null;
		}

		foreach ( $rows as $row ) {
			$product_id = (int) $row['product_id'];
			$order_id   = (int) $row['order_id'];
			if ( isset( $out[ $product_id ] ) && $order_id > 0 && ! in_array( $order_id, $out[ $product_id ], true ) ) {
				$out[ $product_id ][] = $order_id;
			}
		}

		return $out;
	}

	/**
	 * The orders with a line named like an invoice title, or with lines
	 * the theme made out of an invoice so titled (`_fh_invoice_title`) —
	 * "any order containing a line named <handle> Invoice". Null when the
	 * tables could not be read.
	 *
	 * @param string $title_start How the title starts, e.g. "jpreef invoice".
	 * @param int    $limit       Row cap.
	 * @return int[]|null Newest first.
	 */
	public function order_ids_for_invoice_title( $title_start, $limit = 100 ) {
		$title_start = trim( (string) $title_start );
		if ( '' === $title_start ) {
			return array();
		}

		global $wpdb;

		$items = $wpdb->prefix . 'woocommerce_order_items';
		$meta  = $wpdb->prefix . 'woocommerce_order_itemmeta';
		$like  = $wpdb->esc_like( $title_start ) . '%';

		try {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
			$ids = $wpdb->get_col(
				$wpdb->prepare(
					"( SELECT DISTINCT oi.order_id FROM {$items} oi WHERE oi.order_item_type = 'line_item' AND oi.order_item_name LIKE %s ) UNION ( SELECT DISTINCT oi.order_id FROM {$items} oi INNER JOIN {$meta} im ON im.order_item_id = oi.order_item_id WHERE im.meta_key = '" . Invoices::ITEM_TITLE_META . "' AND im.meta_value LIKE %s ) ORDER BY order_id DESC LIMIT %d",
					$like,
					$like,
					max( 1, min( 500, (int) $limit ) )
				)
			);
		} catch ( \Throwable $e ) {
			return null;
		}

		if ( ! is_array( $ids ) || '' !== (string) $wpdb->last_error ) {
			return null;
		}

		return self::ids( $ids );
	}

	/* ============================================================== */
	/*  Hydration (pure)                                               */
	/* ============================================================== */

	/**
	 * A raw invoice row as typed values.
	 *
	 * @param array $row Raw row.
	 * @return array
	 */
	public static function hydrate_invoice( array $row ) {
		$int_or_null  = static function ( $key ) use ( $row ) {
			return ! empty( $row[ $key ] ) && (int) $row[ $key ] > 0 ? (int) $row[ $key ] : null;
		};
		$date_or_null = static function ( $key ) use ( $row ) {
			return ! empty( $row[ $key ] ) && '0000-00-00 00:00:00' !== $row[ $key ] ? (string) $row[ $key ] : null;
		};
		$state        = (string) ( isset( $row['state'] ) ? $row['state'] : 'draft' );
		$shipping     = (string) ( isset( $row['shipping'] ) ? $row['shipping'] : 'premium' );

		return array(
			'id'             => (int) ( isset( $row['id'] ) ? $row['id'] : 0 ),
			'product_id'     => $int_or_null( 'product_id' ),
			'handle'         => (string) ( isset( $row['handle'] ) ? $row['handle'] : '' ),
			'handle_key'     => (string) ( isset( $row['handle_key'] ) ? $row['handle_key'] : '' ),
			'forum_user_id'  => $int_or_null( 'forum_user_id' ),
			'customer_id'    => $int_or_null( 'customer_id' ),
			'customer_name'  => (string) ( isset( $row['customer_name'] ) ? $row['customer_name'] : '' ),
			'customer_email' => (string) ( isset( $row['customer_email'] ) ? $row['customer_email'] : '' ),
			'state'          => in_array( $state, Rules::STATES, true ) ? $state : 'draft',
			'shipping'       => in_array( $shipping, Settings::SHIPPING_CHOICES, true ) ? $shipping : 'premium',
			'shipping_slug'  => (string) ( isset( $row['shipping_slug'] ) ? $row['shipping_slug'] : '' ),
			'flat_total'     => isset( $row['flat_total'] ) && null !== $row['flat_total'] && '' !== $row['flat_total'] ? round( (float) $row['flat_total'], 2 ) : null,
			'total'          => round( (float) ( isset( $row['total'] ) ? $row['total'] : 0 ), 2 ),
			'title'          => (string) ( isset( $row['title'] ) ? $row['title'] : '' ),
			'notes'          => (string) ( isset( $row['notes'] ) ? $row['notes'] : '' ),
			'order_id'       => $int_or_null( 'order_id' ),
			'version'        => max( 1, (int) ( isset( $row['version'] ) ? $row['version'] : 1 ) ),
			'created_by'     => (int) ( isset( $row['created_by'] ) ? $row['created_by'] : 0 ),
			'created_at'     => (string) ( isset( $row['created_at'] ) ? $row['created_at'] : '' ),
			'updated_by'     => (int) ( isset( $row['updated_by'] ) ? $row['updated_by'] : 0 ),
			'updated_at'     => (string) ( isset( $row['updated_at'] ) ? $row['updated_at'] : '' ),
			'published_by'   => $int_or_null( 'published_by' ),
			'published_at'   => $date_or_null( 'published_at' ),
			'paid_at'        => $date_or_null( 'paid_at' ),
		);
	}

	/**
	 * A raw line row as typed values, with its amount.
	 *
	 * @param array $row Raw row.
	 * @return array
	 */
	public static function hydrate_line( array $row ) {
		$int_or_null = static function ( $key ) use ( $row ) {
			return ! empty( $row[ $key ] ) && (int) $row[ $key ] > 0 ? (int) $row[ $key ] : null;
		};
		$text_or_null = static function ( $key ) use ( $row ) {
			return isset( $row[ $key ] ) && '' !== (string) $row[ $key ] ? (string) $row[ $key ] : null;
		};
		$kind         = (string) ( isset( $row['kind'] ) ? $row['kind'] : 'fish' );
		$source       = (string) ( isset( $row['source'] ) ? $row['source'] : 'typed' );

		$line = array(
			'id'           => (int) ( isset( $row['id'] ) ? $row['id'] : 0 ),
			'invoice_id'   => (int) ( isset( $row['invoice_id'] ) ? $row['invoice_id'] : 0 ),
			'kind'         => in_array( $kind, Lines::KINDS, true ) ? $kind : 'fish',
			'name'         => (string) ( isset( $row['name'] ) ? $row['name'] : '' ),
			'qty'          => max( 1, (int) ( isset( $row['qty'] ) ? $row['qty'] : 1 ) ),
			'unit_price'   => isset( $row['unit_price'] ) && null !== $row['unit_price'] && '' !== $row['unit_price'] ? round( (float) $row['unit_price'], 2 ) : null,
			'product_id'   => $int_or_null( 'product_id' ),
			'variation_id' => $int_or_null( 'variation_id' ),
			'species_id'   => $int_or_null( 'species_id' ),
			'stay_id'      => $int_or_null( 'stay_id' ),
			'size_slug'    => $text_or_null( 'size_slug' ),
			'size_label'   => $text_or_null( 'size_label' ),
			'source'       => in_array( $source, Lines::SOURCES, true ) ? $source : 'typed',
			'take_stock'   => ! empty( $row['take_stock'] ),
			'sort'         => (int) ( isset( $row['sort'] ) ? $row['sort'] : 0 ),
		);

		$line['amount'] = Lines::amount( $line );

		return $line;
	}
}
