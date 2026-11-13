<?php
/**
 * Requests store — the ONLY file in the section that touches $wpdb.
 *
 * Three tables, created with dbDelta on the first boot and on plugin
 * activation, gated by the autoloaded fishotel_requests_db_version option
 * (so a booted site pays no query for it, and an ordinary request never
 * runs SHOW TABLES or dbDelta):
 *
 *   {prefix}fh_requests       one row per fish somebody asked for: who (a site
 *                             customer id, or a name + e-mail; a forum handle),
 *                             what (the fish as typed, the species-list id and
 *                             the product id when matched, a standard size as a
 *                             slug + a label snapshot, a quantity, a note), the
 *                             source, where it came from, the state, the fee
 *                             (amount, the invoice it is on, the order that paid
 *                             it and when), "owed", the order it came from, the
 *                             private link and the sheet it came through, the
 *                             supplier order line / order / tank group it is
 *                             matched to, and its dates
 *   {prefix}fh_request_links  the private links: token, label, an optional fee,
 *                             expiry and maximum uses, a use counter, active or
 *                             not, who made it
 *   {prefix}fh_request_log    the APPEND-ONLY log: what happened to a request,
 *                             who did it, when (UTC)
 *
 * No method of this class updates or deletes a log row — log() is the only
 * write to that table (a test reads this file to keep it so).
 *
 * The version option is written only once every table is really there; a
 * failed install leaves a marker instead, so a front-end visitor never pays
 * a dbDelta again — it is retried in wp-admin, at most once an hour.
 *
 * Every method is wrapped: a database error or a missing table means
 * false / null / an empty list, never an exception. The Service takes this
 * class as a parameter; the tests use an in-memory twin.
 *
 * @package FisHotel\Misc\Sections\Requests
 */

namespace FisHotel\Misc\Sections\Requests;

defined( 'ABSPATH' ) || exit;

/**
 * Class Store
 */
class Store {

	/**
	 * Schema version. Bump to trigger a dbDelta upgrade (and add the new
	 * columns to REQUIRED_COLUMNS).
	 *
	 * @var string
	 */
	const DB_VERSION = '1.0';

	/**
	 * Autoloaded option holding the installed schema version (or a failure
	 * marker "failed:<version>:<time>").
	 *
	 * @var string
	 */
	const DB_VERSION_OPTION = 'fishotel_requests_db_version';

	/**
	 * A failed install is retried at most this often, and only in wp-admin.
	 *
	 * @var int
	 */
	const RETRY_SECONDS = 3600;

	/**
	 * Table names without the prefix.
	 *
	 * @var string[]
	 */
	const TABLES = array( 'requests', 'request_links', 'request_log' );

	/**
	 * The columns added after a table's first version: the version option
	 * is written only once every one of them is really there, so an ALTER
	 * that failed is retried. (Empty in 1.0.)
	 *
	 * @var array<string, string[]>
	 */
	const REQUIRED_COLUMNS = array();

	/**
	 * Row caps.
	 */
	const FIND_LIMIT = 2000;
	const LOG_LIMIT  = 5000;
	const LINK_LIMIT = 500;

	/**
	 * The columns a request row may be sorted on.
	 *
	 * @var string[]
	 */
	const ORDER_COLUMNS = array( 'id', 'created_at', 'updated_at', 'name', 'fish', 'state' );

	/**
	 * The request columns and their kind ('i' int, 'n' nullable int, 's'
	 * string, 'z' nullable string, 'f' float, 'd' nullable datetime).
	 *
	 * @var array<string, string>
	 */
	const COLUMNS = array(
		'user_id'       => 'i',
		'name'          => 's',
		'email'         => 's',
		'handle'        => 's',
		'fish'          => 's',
		'fish_key'      => 's',
		'species_id'    => 'n',
		'product_id'    => 'n',
		'size_slug'     => 'z',
		'size_label'    => 'z',
		'qty'           => 'i',
		'note'          => 's',
		'source'        => 's',
		'came_from'     => 's',
		'state'         => 's',
		'owed'          => 'i',
		'fee_amount'    => 'f',
		'fee_ref'       => 'n',
		'fee_order_id'  => 'n',
		'fee_paid_at'   => 'd',
		'from_order_id' => 'n',
		'link_id'       => 'n',
		'sheet'         => 's',
		'line_id'       => 'n',
		'cr_order_id'   => 'n',
		'stay_id'       => 'n',
		'link_manual'   => 'i',
		'auto_match'    => 'i',
		'created_at'    => 'd',
		'updated_at'    => 'd',
		'approved_at'   => 'd',
		'reminded_at'   => 'd',
		'offered_at'    => 'd',
		'asked_at'      => 'd',
		'kept_at'       => 'd',
		'closed_at'     => 'd',
	);

	/**
	 * Whether the tables exist, memoised per request (null = not checked).
	 *
	 * @var bool|null
	 */
	private static $tables_exist = null;

	/**
	 * Fully prefixed table name.
	 *
	 * @param string $name requests|request_links|request_log.
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
	 * Whether the installed schema is the current one (one autoloaded
	 * option read — no query).
	 *
	 * @return bool
	 */
	public static function installed() {
		return self::DB_VERSION === get_option( self::DB_VERSION_OPTION );
	}

	/**
	 * Section boot: install when the version option says so. A failed
	 * install is not retried on every request (a front-end visitor would
	 * pay a dbDelta each time) — only in wp-admin, at most once an hour.
	 *
	 * @param bool $is_admin is_admin().
	 * @return bool Whether install() ran.
	 */
	public static function maybe_install( $is_admin ) {
		$stored = get_option( self::DB_VERSION_OPTION );

		if ( self::DB_VERSION === $stored ) {
			return false;
		}

		if ( is_string( $stored ) && preg_match( '/^failed:[^:]*:(\d+)$/', $stored, $m ) ) {
			if ( ! $is_admin || time() - (int) $m[1] < self::RETRY_SECONDS ) {
				return false;
			}
		}

		self::install( true );
		return true;
	}

	/**
	 * Create / upgrade the tables via dbDelta, then record the version
	 * (autoloaded) once every table and every added column is really
	 * there; on failure record a marker so maybe_install() waits. The
	 * settings option is created (empty) either way: the front end may
	 * read it, and a missing option would be a query each time.
	 *
	 * @param bool $force Skip the version gate.
	 * @return bool Whether the tables are installed.
	 */
	public static function install( $force = false ) {
		if ( ! $force && self::installed() ) {
			return true;
		}

		// Empty: Settings::get() lays the defaults under it, so a default
		// that changes in the code reaches a site that never saved its own.
		if ( function_exists( 'add_option' ) ) {
			add_option( 'fishotel_requests_settings', array(), '', true );
		}

		global $wpdb;

		try {
			$sql = self::schema( $wpdb->get_charset_collate() );

			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
			foreach ( $sql as $statement ) {
				dbDelta( $statement );
			}

			self::$tables_exist = null;
			$ok                 = self::tables_exist() && self::schema_ready( self::present_columns() );
		} catch ( \Throwable $e ) {
			$ok = false;
		}

		update_option( self::DB_VERSION_OPTION, $ok ? self::DB_VERSION : 'failed:' . self::DB_VERSION . ':' . time(), true );

		return $ok;
	}

	/**
	 * The CREATE TABLE statements dbDelta runs (pure apart from the table
	 * prefix, so a test can read the schema).
	 *
	 * dbDelta is whitespace-sensitive: each column on its own line, two
	 * spaces after PRIMARY KEY, KEY (not INDEX) with a name. Column types
	 * are written as MySQL / MariaDB report them (lower case, int(11),
	 * bigint(20) unsigned): dbDelta compares the type case-sensitively and
	 * would otherwise ALTER every column on every install.
	 *
	 * @param string $charset_collate The charset / collation clause.
	 * @return string[]
	 */
	public static function schema( $charset_collate = '' ) {
		$requests = self::table( 'requests' );
		$links    = self::table( 'request_links' );
		$log      = self::table( 'request_log' );

		return array(
			"CREATE TABLE {$requests} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				name varchar(120) NOT NULL DEFAULT '',
				email varchar(190) NOT NULL DEFAULT '',
				handle varchar(80) NOT NULL DEFAULT '',
				fish varchar(160) NOT NULL DEFAULT '',
				fish_key varchar(170) NOT NULL DEFAULT '',
				species_id bigint(20) unsigned NULL,
				product_id bigint(20) unsigned NULL,
				size_slug varchar(100) NULL,
				size_label varchar(100) NULL,
				qty int(11) NOT NULL DEFAULT 1,
				note text NULL,
				source varchar(20) NOT NULL DEFAULT 'hand',
				came_from varchar(60) NOT NULL DEFAULT '',
				state varchar(20) NOT NULL DEFAULT 'new',
				owed tinyint(1) NOT NULL DEFAULT 0,
				fee_amount decimal(10,2) NOT NULL DEFAULT 0.00,
				fee_ref bigint(20) unsigned NULL,
				fee_order_id bigint(20) unsigned NULL,
				fee_paid_at datetime NULL,
				from_order_id bigint(20) unsigned NULL,
				link_id bigint(20) unsigned NULL,
				sheet varchar(32) NOT NULL DEFAULT '',
				line_id bigint(20) unsigned NULL,
				cr_order_id bigint(20) unsigned NULL,
				stay_id bigint(20) unsigned NULL,
				link_manual tinyint(1) NOT NULL DEFAULT 0,
				auto_match tinyint(1) NOT NULL DEFAULT 1,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				approved_at datetime NULL,
				reminded_at datetime NULL,
				offered_at datetime NULL,
				asked_at datetime NULL,
				kept_at datetime NULL,
				closed_at datetime NULL,
				PRIMARY KEY  (id),
				KEY state (state),
				KEY user_id (user_id),
				KEY email (email),
				KEY fish_key (fish_key),
				KEY line_id (line_id),
				KEY cr_order_id (cr_order_id),
				KEY fee_ref (fee_ref),
				KEY link_id (link_id)
			) {$charset_collate};",
			"CREATE TABLE {$links} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				token varchar(64) NOT NULL,
				label varchar(120) NOT NULL DEFAULT '',
				fee_amount decimal(10,2) NOT NULL DEFAULT 0.00,
				expires_at datetime NULL,
				max_uses int(11) NOT NULL DEFAULT 0,
				uses int(11) NOT NULL DEFAULT 0,
				active tinyint(1) NOT NULL DEFAULT 1,
				created_by bigint(20) unsigned NOT NULL DEFAULT 0,
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY token (token)
			) {$charset_collate};",
			"CREATE TABLE {$log} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				request_id bigint(20) unsigned NOT NULL,
				action varchar(30) NOT NULL,
				note text NULL,
				user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				actor varchar(80) NOT NULL DEFAULT '',
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY request_id (request_id),
				KEY created_at (created_at)
			) {$charset_collate};",
		);
	}

	/**
	 * The columns really present on the tables REQUIRED_COLUMNS names
	 * (install only — never on an ordinary request).
	 *
	 * @return array<string, string[]> Table name without the prefix → its columns.
	 */
	private static function present_columns() {
		global $wpdb;

		$out = array();
		foreach ( array_keys( self::REQUIRED_COLUMNS ) as $table ) {
			try {
				$columns = $wpdb->get_col( 'SHOW COLUMNS FROM ' . self::table( $table ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
			} catch ( \Throwable $e ) {
				$columns = array();
			}
			$out[ $table ] = array_map( 'strval', is_array( $columns ) ? $columns : array() );
		}

		return $out;
	}

	/**
	 * Whether the version gate may open (pure): no added column is missing.
	 *
	 * @param array<string, string[]> $present Table name without the prefix → its columns.
	 * @return bool
	 */
	public static function schema_ready( array $present ) {
		foreach ( self::REQUIRED_COLUMNS as $table => $columns ) {
			$have = isset( $present[ $table ] ) && is_array( $present[ $table ] ) ? array_map( 'strtolower', array_map( 'strval', $present[ $table ] ) ) : array();
			foreach ( $columns as $column ) {
				if ( ! in_array( strtolower( $column ), $have, true ) ) {
					return false;
				}
			}
		}
		return true;
	}

	/**
	 * Whether every table exists (memoised). A false answer makes every
	 * other method a no-op.
	 *
	 * @return bool
	 */
	public static function tables_exist() {
		if ( null !== self::$tables_exist ) {
			return self::$tables_exist;
		}

		global $wpdb;

		try {
			$ok = true;
			foreach ( self::TABLES as $name ) {
				$table = self::table( $name );
				if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
					$ok = false;
					break;
				}
			}
			self::$tables_exist = $ok;
		} catch ( \Throwable $e ) {
			self::$tables_exist = false;
		}

		return self::$tables_exist;
	}

	/**
	 * Whether the store can be used: the version gate is open (no query).
	 * Every read and write below asks this first, so a site whose install
	 * failed never queries a missing table.
	 *
	 * @return bool
	 */
	public static function ready() {
		return self::installed();
	}

	/* ============================================================== */
	/*  Requests                                                       */
	/* ============================================================== */

	/**
	 * Insert a request.
	 *
	 * @param array $row Columns (see COLUMNS); created_at / updated_at default to now.
	 * @return int|false The new id.
	 */
	public function insert( array $row ) {
		if ( ! self::ready() ) {
			return false;
		}

		global $wpdb;

		$now  = gmdate( 'Y-m-d H:i:s' );
		$row  = array_merge(
			array(
				'qty'        => 1,
				'source'     => 'hand',
				'state'      => 'new',
				'auto_match' => 1,
				'created_at' => $now,
				'updated_at' => $now,
			),
			$row
		);
		$data = self::columns( $row );

		try {
			$ok = $wpdb->insert( self::table( 'requests' ), $data, self::formats( $data ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		} catch ( \Throwable $e ) {
			return false;
		}

		return $ok ? (int) $wpdb->insert_id : false;
	}

	/**
	 * Update some columns of a request — when $states is given, only while
	 * the request is still in one of them, and when $if_null names a
	 * column, only while that column is still empty — so two things that
	 * happen at once (the daily job and a click, two payment hooks) never
	 * both win.
	 *
	 * @param int      $id      Request id.
	 * @param array    $fields  Columns to set.
	 * @param string[] $states  The states the request must be in (empty = any).
	 * @param string   $if_null A column that must still be NULL ('' = no such guard).
	 * @return bool With a guard: whether the row passed it and changed. Without: whether the
	 *              database accepted the statement.
	 */
	public function update( $id, array $fields, array $states = array(), $if_null = '' ) {
		$id = (int) $id;
		if ( ! self::ready() || $id <= 0 ) {
			return false;
		}

		$fields = array_diff_key( $fields, array( 'id' => 1, 'created_at' => 1 ) );
		if ( ! isset( $fields['updated_at'] ) ) {
			$fields['updated_at'] = gmdate( 'Y-m-d H:i:s' );
		}
		$data = self::columns( $fields );
		if ( ! $data ) {
			return false;
		}

		global $wpdb;

		$set    = array();
		$params = array();
		foreach ( $data as $column => $value ) {
			if ( null === $value ) {
				$set[] = "`{$column}` = NULL";
				continue;
			}
			$set[]    = "`{$column}` = " . self::placeholder( $column );
			$params[] = $value;
		}

		$where    = 'id = %d';
		$params[] = $id;
		$states   = array_values( array_intersect( array_map( 'strval', $states ), Rules::STATES ) );
		if ( $states ) {
			$where .= ' AND state IN (' . implode( ',', array_fill( 0, count( $states ), '%s' ) ) . ')';
			$params = array_merge( $params, $states );
		}
		$if_null = isset( self::COLUMNS[ (string) $if_null ] ) ? (string) $if_null : '';
		if ( '' !== $if_null ) {
			$where .= " AND `{$if_null}` IS NULL";
		}

		try {
			$n = $wpdb->query( $wpdb->prepare( 'UPDATE ' . self::table( 'requests' ) . ' SET ' . implode( ', ', $set ) . ' WHERE ' . $where, $params ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
		} catch ( \Throwable $e ) {
			return false;
		}

		if ( false === $n ) {
			return false;
		}

		// With a guard the answer is strict: the row passed it and really
		// changed (a second caller finds 0 rows).
		return ( $states || '' !== $if_null ) ? (int) $n >= 1 : true;
	}

	/**
	 * One request.
	 *
	 * @param int $id Request id.
	 * @return array|null
	 */
	public function get( $id ) {
		$id = (int) $id;
		if ( ! self::ready() || $id <= 0 ) {
			return null;
		}

		global $wpdb;

		try {
			$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table( 'requests' ) . ' WHERE id = %d', $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
		} catch ( \Throwable $e ) {
			return null;
		}

		return is_array( $row ) ? self::hydrate( $row ) : null;
	}

	/**
	 * Requests matching some filters.
	 *
	 * @param array $filters ids (int[]), state (string|string[]), source, came_from, user_id, email,
	 *                       line_id, cr_order_id, fee_ref, link_id, product_id, sheet, search (name,
	 *                       e-mail, handle, fish), orderby (ORDER_COLUMNS), order (ASC|DESC), limit, offset.
	 * @return array[]
	 */
	public function find( array $filters = array() ) {
		if ( ! self::ready() ) {
			return array();
		}

		global $wpdb;

		list( $where, $params ) = self::where( $filters );

		$orderby = isset( $filters['orderby'] ) && in_array( (string) $filters['orderby'], self::ORDER_COLUMNS, true ) ? (string) $filters['orderby'] : 'id';
		$order   = isset( $filters['order'] ) && 'DESC' === strtoupper( (string) $filters['order'] ) ? 'DESC' : 'ASC';
		$limit   = isset( $filters['limit'] ) ? max( 1, min( self::FIND_LIMIT, (int) $filters['limit'] ) ) : self::FIND_LIMIT;
		$offset  = isset( $filters['offset'] ) ? max( 0, (int) $filters['offset'] ) : 0;

		$sql      = 'SELECT * FROM ' . self::table( 'requests' ) . ' WHERE ' . $where . " ORDER BY `{$orderby}` {$order}, id {$order} LIMIT %d OFFSET %d";
		$params[] = $limit;
		$params[] = $offset;

		try {
			$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
		} catch ( \Throwable $e ) {
			return array();
		}

		return array_map( array( self::class, 'hydrate' ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * How many requests match some filters.
	 *
	 * @param array $filters As find().
	 * @return int
	 */
	public function count( array $filters = array() ) {
		if ( ! self::ready() ) {
			return 0;
		}

		global $wpdb;

		list( $where, $params ) = self::where( $filters );
		$sql                    = 'SELECT COUNT(*) FROM ' . self::table( 'requests' ) . ' WHERE ' . $where;

		try {
			return (int) ( $params ? $wpdb->get_var( $wpdb->prepare( $sql, $params ) ) : $wpdb->get_var( $sql ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
		} catch ( \Throwable $e ) {
			return 0;
		}
	}

	/**
	 * The WHERE of find() / count().
	 *
	 * @param array $filters Filters.
	 * @return array{0: string, 1: array}
	 */
	private static function where( array $filters ) {
		global $wpdb;

		$where  = array( '1=1' );
		$params = array();

		if ( isset( $filters['ids'] ) ) {
			$ids     = array_values( array_unique( array_filter( array_map( 'intval', (array) $filters['ids'] ) ) ) );
			$where[] = $ids ? 'id IN (' . implode( ',', $ids ) . ')' : '1=0';
		}
		if ( isset( $filters['state'] ) && '' !== $filters['state'] && array() !== $filters['state'] ) {
			$states = array_values( array_intersect( array_map( 'strval', (array) $filters['state'] ), Rules::STATES ) );
			if ( $states ) {
				$where[] = 'state IN (' . implode( ',', array_fill( 0, count( $states ), '%s' ) ) . ')';
				$params  = array_merge( $params, $states );
			} else {
				$where[] = '1=0';
			}
		}
		foreach ( array( 'source', 'came_from', 'email', 'sheet' ) as $key ) {
			if ( isset( $filters[ $key ] ) && '' !== (string) $filters[ $key ] ) {
				$where[]  = "`{$key}` = %s";
				$params[] = (string) $filters[ $key ];
			}
		}
		foreach ( array( 'user_id', 'line_id', 'cr_order_id', 'fee_ref', 'link_id', 'product_id' ) as $key ) {
			if ( isset( $filters[ $key ] ) && (int) $filters[ $key ] > 0 ) {
				$where[]  = "`{$key}` = %d";
				$params[] = (int) $filters[ $key ];
			}
		}
		if ( isset( $filters['search'] ) && '' !== trim( (string) $filters['search'] ) ) {
			$like     = '%' . $wpdb->esc_like( trim( (string) $filters['search'] ) ) . '%';
			$where[]  = '(name LIKE %s OR email LIKE %s OR handle LIKE %s OR fish LIKE %s)';
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
		}

		return array( implode( ' AND ', $where ), $params );
	}

	/**
	 * The demand per fish: how many requests (and fish) are open, and how
	 * many there ever were — one grouped query.
	 *
	 * @return array[] fish_key, fish, species_id, product_id, open_requests, open_qty, all_requests,
	 *                 all_qty, first_at.
	 */
	public function demand() {
		if ( ! self::ready() ) {
			return array();
		}

		global $wpdb;

		$open = "'" . implode( "','", Rules::OPEN_STATES ) . "'";
		$sql  = 'SELECT fish_key, MIN(fish) AS fish, MAX(species_id) AS species_id, MAX(product_id) AS product_id,'
			. " SUM(CASE WHEN state IN ({$open}) THEN 1 ELSE 0 END) AS open_requests,"
			. " SUM(CASE WHEN state IN ({$open}) THEN qty ELSE 0 END) AS open_qty,"
			. ' COUNT(*) AS all_requests, SUM(qty) AS all_qty, MIN(created_at) AS first_at'
			. ' FROM ' . self::table( 'requests' ) . ' GROUP BY fish_key ORDER BY open_qty DESC, all_qty DESC LIMIT ' . (int) self::FIND_LIMIT;

		try {
			$rows = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery -- constants only.
		} catch ( \Throwable $e ) {
			return array();
		}

		$out = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$out[] = array(
				'fish_key'      => (string) $row['fish_key'],
				'fish'          => (string) $row['fish'],
				'species_id'    => ! empty( $row['species_id'] ) ? (int) $row['species_id'] : null,
				'product_id'    => ! empty( $row['product_id'] ) ? (int) $row['product_id'] : null,
				'open_requests' => (int) $row['open_requests'],
				'open_qty'      => (int) $row['open_qty'],
				'all_requests'  => (int) $row['all_requests'],
				'all_qty'       => (int) $row['all_qty'],
				'first_at'      => (string) $row['first_at'],
			);
		}

		return $out;
	}

	/**
	 * The distinct "came from" values in use.
	 *
	 * @return string[]
	 */
	public function came_from_values() {
		if ( ! self::ready() ) {
			return array();
		}

		global $wpdb;

		try {
			$rows = $wpdb->get_col( 'SELECT DISTINCT came_from FROM ' . self::table( 'requests' ) . " WHERE came_from <> '' ORDER BY came_from ASC LIMIT 200" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
		} catch ( \Throwable $e ) {
			return array();
		}

		return array_values( array_map( 'strval', is_array( $rows ) ? $rows : array() ) );
	}

	/* ============================================================== */
	/*  The log (append-only)                                          */
	/* ============================================================== */

	/**
	 * Append one line to a request's log — the only write to that table.
	 *
	 * @param int    $request_id Request id.
	 * @param string $action     What happened (a short key).
	 * @param string $note       In words.
	 * @param int    $user_id    Who did it (0 = nobody logged in).
	 * @param string $actor      Who, in words (a login, "customer", "system").
	 * @return bool
	 */
	public function log( $request_id, $action, $note = '', $user_id = 0, $actor = '' ) {
		if ( ! self::ready() || (int) $request_id <= 0 ) {
			return false;
		}

		global $wpdb;

		$data = array(
			'request_id' => (int) $request_id,
			'action'     => substr( (string) $action, 0, 30 ),
			'note'       => (string) $note,
			'user_id'    => max( 0, (int) $user_id ),
			'actor'      => Rules::cut( (string) $actor, 80 ),
			'created_at' => gmdate( 'Y-m-d H:i:s' ),
		);

		try {
			return (bool) $wpdb->insert( self::table( 'request_log' ), $data, array( '%d', '%s', '%s', '%d', '%s', '%s' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	/**
	 * The log of some requests, oldest first, by request id.
	 *
	 * @param int[] $request_ids Request ids.
	 * @return array<int, array[]>
	 */
	public function logs( array $request_ids ) {
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $request_ids ) ) ) );
		if ( ! self::ready() || ! $ids ) {
			return array();
		}

		global $wpdb;

		try {
			$rows = $wpdb->get_results( 'SELECT * FROM ' . self::table( 'request_log' ) . ' WHERE request_id IN (' . implode( ',', $ids ) . ') ORDER BY id ASC LIMIT ' . (int) self::LOG_LIMIT, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery -- ids cast to int above.
		} catch ( \Throwable $e ) {
			return array();
		}

		$out = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$out[ (int) $row['request_id'] ][] = array(
				'id'         => (int) $row['id'],
				'request_id' => (int) $row['request_id'],
				'action'     => (string) $row['action'],
				'note'       => (string) $row['note'],
				'user_id'    => (int) $row['user_id'],
				'actor'      => (string) $row['actor'],
				'created_at' => (string) $row['created_at'],
			);
		}

		return $out;
	}

	/* ============================================================== */
	/*  Private links                                                  */
	/* ============================================================== */

	/**
	 * Insert a private link.
	 *
	 * @param array $link token, label, fee_amount, expires_at, max_uses, created_by.
	 * @return int|false
	 */
	public function insert_link( array $link ) {
		if ( ! self::ready() || '' === (string) ( $link['token'] ?? '' ) ) {
			return false;
		}

		global $wpdb;

		$data = array(
			'token'      => (string) $link['token'],
			'label'      => Rules::cut( (string) ( $link['label'] ?? '' ), Rules::MAX_LABEL ),
			'fee_amount' => round( (float) ( $link['fee_amount'] ?? 0 ), 2 ),
			'expires_at' => ! empty( $link['expires_at'] ) ? (string) $link['expires_at'] : null,
			'max_uses'   => max( 0, (int) ( $link['max_uses'] ?? 0 ) ),
			'uses'       => 0,
			'active'     => 1,
			'created_by' => max( 0, (int) ( $link['created_by'] ?? 0 ) ),
			'created_at' => gmdate( 'Y-m-d H:i:s' ),
		);

		try {
			$ok = $wpdb->insert( self::table( 'request_links' ), $data ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		} catch ( \Throwable $e ) {
			return false;
		}

		return $ok ? (int) $wpdb->insert_id : false;
	}

	/**
	 * Switch a private link on or off.
	 *
	 * @param int  $id     Link id.
	 * @param bool $active On?
	 * @return bool
	 */
	public function set_link_active( $id, $active ) {
		if ( ! self::ready() || (int) $id <= 0 ) {
			return false;
		}

		global $wpdb;

		try {
			return false !== $wpdb->update( self::table( 'request_links' ), array( 'active' => $active ? 1 : 0 ), array( 'id' => (int) $id ), array( '%d' ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	/**
	 * Count one use of a private link — only while it is active and has
	 * uses left, in ONE statement, so two people submitting at the same
	 * moment cannot both take the last use.
	 *
	 * @param int $id Link id.
	 * @return bool Whether the use was counted.
	 */
	public function use_link( $id ) {
		if ( ! self::ready() || (int) $id <= 0 ) {
			return false;
		}

		global $wpdb;

		try {
			$n = $wpdb->query( $wpdb->prepare( 'UPDATE ' . self::table( 'request_links' ) . ' SET uses = uses + 1 WHERE id = %d AND active = 1 AND (max_uses = 0 OR uses < max_uses)', (int) $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
		} catch ( \Throwable $e ) {
			return false;
		}

		return 1 === (int) $n;
	}

	/**
	 * One private link by id.
	 *
	 * @param int $id Link id.
	 * @return array|null
	 */
	public function link( $id ) {
		return (int) $id > 0 ? $this->link_row( 'id = %d', (int) $id ) : null;
	}

	/**
	 * One private link by its token.
	 *
	 * @param string $token Token.
	 * @return array|null
	 */
	public function link_by_token( $token ) {
		return Tokens::is_link( $token ) ? $this->link_row( 'token = %s', (string) $token ) : null;
	}

	private function link_row( $where, $value ) {
		if ( ! self::ready() ) {
			return null;
		}

		global $wpdb;

		try {
			$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table( 'request_links' ) . ' WHERE ' . $where . ' LIMIT 1', $value ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
		} catch ( \Throwable $e ) {
			return null;
		}

		return is_array( $row ) ? self::hydrate_link( $row ) : null;
	}

	/**
	 * Every private link, newest first, with how many requests came
	 * through each.
	 *
	 * @return array[]
	 */
	public function links() {
		if ( ! self::ready() ) {
			return array();
		}

		global $wpdb;

		try {
			$rows = $wpdb->get_results( 'SELECT l.*, (SELECT COUNT(*) FROM ' . self::table( 'requests' ) . ' r WHERE r.link_id = l.id) AS request_count FROM ' . self::table( 'request_links' ) . ' l ORDER BY l.id DESC LIMIT ' . (int) self::LINK_LIMIT, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
		} catch ( \Throwable $e ) {
			return array();
		}

		return array_map( array( self::class, 'hydrate_link' ), is_array( $rows ) ? $rows : array() );
	}

	/* ============================================================== */
	/*  Hydration                                                      */
	/* ============================================================== */

	/**
	 * A request row with its types.
	 *
	 * @param array $row Raw row.
	 * @return array
	 */
	public static function hydrate( array $row ) {
		$out = array( 'id' => (int) ( $row['id'] ?? 0 ) );
		foreach ( self::COLUMNS as $column => $kind ) {
			$value = array_key_exists( $column, $row ) ? $row[ $column ] : null;
			switch ( $kind ) {
				case 'i':
					$out[ $column ] = (int) $value;
					break;
				case 'n':
					$out[ $column ] = null !== $value && '' !== $value && (int) $value > 0 ? (int) $value : null;
					break;
				case 'f':
					$out[ $column ] = round( (float) $value, 2 );
					break;
				case 'z':
					$out[ $column ] = null !== $value && '' !== trim( (string) $value ) ? (string) $value : null;
					break;
				case 'd':
					$out[ $column ] = null !== $value && '' !== $value && '0000-00-00 00:00:00' !== $value ? (string) $value : null;
					break;
				default:
					$out[ $column ] = null === $value ? '' : (string) $value;
			}
		}
		return $out;
	}

	/**
	 * A private link row with its types.
	 *
	 * @param array $row Raw row.
	 * @return array
	 */
	public static function hydrate_link( array $row ) {
		return array(
			'id'            => (int) ( $row['id'] ?? 0 ),
			'token'         => (string) ( $row['token'] ?? '' ),
			'label'         => (string) ( $row['label'] ?? '' ),
			'fee_amount'    => round( (float) ( $row['fee_amount'] ?? 0 ), 2 ),
			'expires_at'    => ! empty( $row['expires_at'] ) && '0000-00-00 00:00:00' !== $row['expires_at'] ? (string) $row['expires_at'] : null,
			'max_uses'      => (int) ( $row['max_uses'] ?? 0 ),
			'uses'          => (int) ( $row['uses'] ?? 0 ),
			'active'        => ! empty( $row['active'] ),
			'created_by'    => (int) ( $row['created_by'] ?? 0 ),
			'created_at'    => (string) ( $row['created_at'] ?? '' ),
			'request_count' => (int) ( $row['request_count'] ?? 0 ),
		);
	}

	/**
	 * The known columns of a row, typed for the database (a nullable
	 * column that is empty becomes NULL).
	 *
	 * @param array $row Columns.
	 * @return array
	 */
	public static function columns( array $row ) {
		$out = array();
		foreach ( self::COLUMNS as $column => $kind ) {
			if ( ! array_key_exists( $column, $row ) ) {
				continue;
			}
			$value = $row[ $column ];
			switch ( $kind ) {
				case 'i':
					$out[ $column ] = (int) $value;
					break;
				case 'n':
					$out[ $column ] = null !== $value && (int) $value > 0 ? (int) $value : null;
					break;
				case 'f':
					$out[ $column ] = round( (float) $value, 2 );
					break;
				case 'z':
				case 'd':
					$out[ $column ] = null !== $value && '' !== trim( (string) $value ) ? (string) $value : null;
					break;
				default:
					$out[ $column ] = null === $value ? '' : (string) $value;
			}
		}
		return $out;
	}

	/**
	 * The wpdb formats of a column set.
	 *
	 * @param array $data Column → value.
	 * @return string[]
	 */
	private static function formats( array $data ) {
		$out = array();
		foreach ( array_keys( $data ) as $column ) {
			$out[] = self::placeholder( $column );
		}
		return $out;
	}

	/**
	 * The placeholder of a column.
	 *
	 * @param string $column Column.
	 * @return string
	 */
	private static function placeholder( $column ) {
		$kind = isset( self::COLUMNS[ $column ] ) ? self::COLUMNS[ $column ] : 's';
		if ( 'i' === $kind || 'n' === $kind ) {
			return '%d';
		}
		return 'f' === $kind ? '%f' : '%s';
	}
}
