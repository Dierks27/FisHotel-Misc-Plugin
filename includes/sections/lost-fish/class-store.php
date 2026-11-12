<?php
/**
 * Lost Fish store — the ONLY file in the section that touches $wpdb.
 *
 * Two tables, created with dbDelta when the section first boots or the
 * plugin is activated, gated by the autoloaded option
 * fishotel_lostfish_db_version — written only once both tables AND every
 * column of the schema are really there, so a failed CREATE or ALTER is
 * retried (in wp-admin, at most once an hour) and a booted site pays no
 * query for it:
 *
 *   {prefix}fh_lostfish_cases  one row per case: the fish (kind, product,
 *                              species, size, quantity, the tank group and
 *                              the loss event it came from), the order line
 *                              and its customer, the money in CENTS (paid
 *                              per fish, the shipping difference, the
 *                              amounts frozen when the e-mail went out), the
 *                              link salt and expiry, the customer's choice,
 *                              and a timestamp per stage (UTC)
 *   {prefix}fh_lostfish_log    this section's own log: a line per thing
 *                              that happened ("replaced from the extra",
 *                              case opened, e-mail sent, the choice, done)
 *
 * A case moves from one status to the next through transition(): an
 * UPDATE that names the status it expects, so a second click, a second
 * browser tab or a second confirm changes nothing and says so.
 *
 * One read goes to WooCommerce's own tables: orders_with_lines() asks the
 * order-item tables — the same tables with HPOS on or off — which of a
 * list of OPEN orders (found through the order API) hold a product or an
 * invoice line, so a loss does not load every open order. Every order it
 * names is then loaded and judged through the order API; when the read
 * fails the caller loads them all.
 *
 * Every method is wrapped: a missing table or a database error means
 * false / null / an empty list, never an exception.
 *
 * @package FisHotel\Misc\Sections\Lost_Fish
 */

namespace FisHotel\Misc\Sections\Lost_Fish;

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
	 * Autoloaded option holding the installed schema version (or a failure
	 * marker "failed:<version>:<time>").
	 *
	 * @var string
	 */
	const DB_VERSION_OPTION = 'fishotel_lostfish_db_version';

	/**
	 * Autoloaded option holding the number of cases per status, so the
	 * menu label and the cron tick know without a query whether anything
	 * waits.
	 *
	 * @var string
	 */
	const COUNTS_OPTION = 'fishotel_lostfish_counts';

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
	const TABLES = array( 'lostfish_cases', 'lostfish_log' );

	const STATUSES = array( 'open', 'sent', 'answered', 'done', 'closed' );

	/**
	 * The columns of the cases table and how each is written: 'd' an
	 * integer, 's' a string, '?d' / '?s' the same but NULL when null. The
	 * version option is written only once every one of them exists.
	 *
	 * @var array<string, string>
	 */
	const CASE_COLUMNS = array(
		'status'         => 's',
		'origin'         => 's',
		'kind_key'       => 's',
		'product_id'     => 'd',
		'species'        => 's',
		'size_slug'      => 's',
		'size_label'     => 's',
		'fish_name'      => 's',
		'qty'            => 'd',
		'stay_id'        => 'd',
		'loss_event_id'  => 'd',
		'order_id'       => 'd',
		'order_item_id'  => 'd',
		'line_ref'       => 's',
		'line_match'     => 's',
		'needs_check'    => 'd',
		'check_note'     => 's',
		'customer_id'    => 'd',
		'customer_name'  => 's',
		'customer_email' => 's',
		'currency'       => 's',
		'unit_paid'      => 'd',
		'paid_known'     => 'd',
		'ship_status'    => 's',
		'ship_charged'   => 'd',
		'ship_reduced'   => '?d',
		'ship_diff'      => '?d',
		'ship_manual'    => 'd',
		'ship_note'      => 's',
		'amount_refund'  => '?d',
		'amount_cancel'  => '?d',
		'offer_cancel'   => 'd',
		'choice'         => 's',
		'choice_via'     => 's',
		'request_ref'    => 's',
		'token_salt'     => 's',
		'link_expires'   => '?s',
		'created_at'     => 's',
		'created_by'     => 'd',
		'sent_at'        => '?s',
		'reminded_at'    => '?s',
		'answered_at'    => '?s',
		'done_at'        => '?s',
		'done_note'      => '?s',
		'closed_at'      => '?s',
		'close_reason'   => 's',
		'updated_at'     => 's',
	);

	const LOG_COLUMNS = array( 'case_id', 'order_id', 'event', 'message', 'user_id', 'created_at' );

	const CASES_LIMIT = 500;
	const LOG_LIMIT   = 200;

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
	 * The CREATE TABLE statements dbDelta runs (pure apart from the table
	 * prefix, so a test can read the schema). dbDelta is
	 * whitespace-sensitive: each column on its own line, two spaces after
	 * PRIMARY KEY, KEY (not INDEX) with a name; the types are written as
	 * MySQL / MariaDB report them, or dbDelta would ALTER every column on
	 * every install.
	 *
	 * @param string $charset_collate The charset / collation clause.
	 * @return string[]
	 */
	public static function schema( $charset_collate = '' ) {
		$cases = self::table( 'lostfish_cases' );
		$log   = self::table( 'lostfish_log' );

		return array(
			"CREATE TABLE {$cases} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				status varchar(12) NOT NULL DEFAULT 'open',
				origin varchar(8) NOT NULL DEFAULT 'auto',
				kind_key varchar(191) NOT NULL DEFAULT '',
				product_id bigint(20) unsigned NOT NULL DEFAULT 0,
				species varchar(120) NOT NULL DEFAULT '',
				size_slug varchar(100) NOT NULL DEFAULT '',
				size_label varchar(100) NOT NULL DEFAULT '',
				fish_name varchar(200) NOT NULL DEFAULT '',
				qty int(11) NOT NULL DEFAULT 1,
				stay_id bigint(20) unsigned NOT NULL DEFAULT 0,
				loss_event_id bigint(20) unsigned NOT NULL DEFAULT 0,
				order_id bigint(20) unsigned NOT NULL DEFAULT 0,
				order_item_id bigint(20) unsigned NOT NULL DEFAULT 0,
				line_ref varchar(64) NOT NULL DEFAULT '',
				line_match varchar(12) NOT NULL DEFAULT '',
				needs_check tinyint(1) NOT NULL DEFAULT 0,
				check_note varchar(255) NOT NULL DEFAULT '',
				customer_id bigint(20) unsigned NOT NULL DEFAULT 0,
				customer_name varchar(191) NOT NULL DEFAULT '',
				customer_email varchar(191) NOT NULL DEFAULT '',
				currency varchar(8) NOT NULL DEFAULT '',
				unit_paid bigint(20) NOT NULL DEFAULT 0,
				paid_known tinyint(1) NOT NULL DEFAULT 1,
				ship_status varchar(12) NOT NULL DEFAULT 'unknown',
				ship_charged bigint(20) NOT NULL DEFAULT 0,
				ship_reduced bigint(20) NULL,
				ship_diff bigint(20) NULL,
				ship_manual tinyint(1) NOT NULL DEFAULT 0,
				ship_note varchar(255) NOT NULL DEFAULT '',
				amount_refund bigint(20) NULL,
				amount_cancel bigint(20) NULL,
				offer_cancel tinyint(1) NOT NULL DEFAULT 1,
				choice varchar(8) NOT NULL DEFAULT '',
				choice_via varchar(10) NOT NULL DEFAULT '',
				request_ref varchar(60) NOT NULL DEFAULT '',
				token_salt varchar(32) NOT NULL DEFAULT '',
				link_expires datetime NULL,
				created_at datetime NOT NULL,
				created_by bigint(20) unsigned NOT NULL DEFAULT 0,
				sent_at datetime NULL,
				reminded_at datetime NULL,
				answered_at datetime NULL,
				done_at datetime NULL,
				done_note text NULL,
				closed_at datetime NULL,
				close_reason varchar(40) NOT NULL DEFAULT '',
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY status (status),
				KEY order_id (order_id),
				KEY kind_key (kind_key),
				KEY loss_event_id (loss_event_id)
			) {$charset_collate};",
			"CREATE TABLE {$log} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				case_id bigint(20) unsigned NOT NULL DEFAULT 0,
				order_id bigint(20) unsigned NOT NULL DEFAULT 0,
				event varchar(24) NOT NULL DEFAULT '',
				message text NOT NULL,
				user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY case_id (case_id),
				KEY created_at (created_at)
			) {$charset_collate};",
		);
	}

	/**
	 * Create / upgrade the tables via dbDelta, then record the version
	 * (autoloaded) once every table and every column is really there; on
	 * failure record a marker so maybe_install() waits. The counts option
	 * is created either way: the menu reads it on every admin request,
	 * and a missing option would be a query each time.
	 *
	 * @param bool $force Skip the version gate.
	 * @return bool Whether the tables are installed.
	 */
	public static function install( $force = false ) {
		if ( ! $force && self::installed() ) {
			return true;
		}

		if ( function_exists( 'add_option' ) ) {
			add_option( self::COUNTS_OPTION, array(), '', true );
		}

		global $wpdb;

		try {
			$sql = self::schema( $wpdb->get_charset_collate() );

			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
			foreach ( $sql as $statement ) {
				dbDelta( $statement );
			}

			$ok = self::tables_exist() && self::schema_ready( self::present_columns() );
		} catch ( \Throwable $e ) {
			$ok = false;
		}

		update_option( self::DB_VERSION_OPTION, $ok ? self::DB_VERSION : 'failed:' . self::DB_VERSION . ':' . time(), true );

		return $ok;
	}

	/**
	 * Whether both tables exist (install and the admin screen only).
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
	 * The columns really present on the two tables (install only).
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
	 * The schema's columns that are still missing (pure).
	 *
	 * @param array<string, string[]> $present Table name without the prefix → its columns.
	 * @return string[] "table.column".
	 */
	public static function missing_columns( array $present ) {
		$wanted = array(
			'lostfish_cases' => array_merge( array( 'id' ), array_keys( self::CASE_COLUMNS ) ),
			'lostfish_log'   => array_merge( array( 'id' ), self::LOG_COLUMNS ),
		);

		$missing = array();
		foreach ( $wanted as $table => $columns ) {
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
	/*  Cases                                                          */
	/* ============================================================== */

	/**
	 * Insert a case.
	 *
	 * @param array $fields Any of CASE_COLUMNS (status defaults to open; the timestamps to now).
	 * @return int|false Case id.
	 */
	public function insert_case( array $fields ) {
		global $wpdb;

		$now    = gmdate( 'Y-m-d H:i:s' );
		$fields = array_merge(
			array(
				'status'     => 'open',
				'created_at' => $now,
				'updated_at' => $now,
			),
			$fields
		);

		$data = self::clean_fields( $fields );
		if ( ! $data ) {
			return false;
		}

		try {
			$ok = $wpdb->insert( self::table( 'lostfish_cases' ), $data );
		} catch ( \Throwable $e ) {
			return false;
		}

		return $ok ? (int) $wpdb->insert_id : false;
	}

	/**
	 * One case.
	 *
	 * @param int $id Case id.
	 * @return array|null
	 */
	public function get_case( $id ) {
		if ( (int) $id <= 0 ) {
			return null;
		}

		global $wpdb;

		try {
			$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table( 'lostfish_cases' ) . ' WHERE id = %d', (int) $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		} catch ( \Throwable $e ) {
			return null;
		}

		return is_array( $row ) ? self::hydrate_case( $row ) : null;
	}

	/**
	 * Cases, newest first.
	 *
	 * @param array $filters status (string|string[]), order_id, order_ids (int[]), kind_key, loss_event_id, limit.
	 * @return array[]
	 */
	public function cases( array $filters = array() ) {
		global $wpdb;

		$where  = array( '1=1' );
		$params = array();

		if ( ! empty( $filters['status'] ) ) {
			$statuses = array_values( array_intersect( array_map( 'strval', (array) $filters['status'] ), self::STATUSES ) );
			if ( ! $statuses ) {
				return array();
			}
			$where[] = 'status IN (' . implode( ',', array_fill( 0, count( $statuses ), '%s' ) ) . ')';
			$params  = array_merge( $params, $statuses );
		}
		if ( ! empty( $filters['order_id'] ) ) {
			$where[]  = 'order_id = %d';
			$params[] = (int) $filters['order_id'];
		}
		if ( isset( $filters['order_ids'] ) ) {
			$order_ids = array_values( array_unique( array_filter( array_map( 'intval', (array) $filters['order_ids'] ) ) ) );
			if ( ! $order_ids ) {
				return array();
			}
			$where[] = 'order_id IN (' . implode( ',', array_fill( 0, count( $order_ids ), '%d' ) ) . ')';
			$params  = array_merge( $params, $order_ids );
		}
		if ( isset( $filters['kind_key'] ) && '' !== (string) $filters['kind_key'] ) {
			$where[]  = 'kind_key = %s';
			$params[] = (string) $filters['kind_key'];
		}
		if ( ! empty( $filters['loss_event_id'] ) ) {
			$where[]  = 'loss_event_id = %d';
			$params[] = (int) $filters['loss_event_id'];
		}

		$limit = isset( $filters['limit'] ) ? max( 1, min( self::CASES_LIMIT, (int) $filters['limit'] ) ) : self::CASES_LIMIT;
		$sql   = 'SELECT * FROM ' . self::table( 'lostfish_cases' ) . ' WHERE ' . implode( ' AND ', $where ) . ' ORDER BY id DESC LIMIT ' . (int) $limit;

		try {
			$rows = $params
				? $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				: $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		} catch ( \Throwable $e ) {
			return array();
		}

		return array_map( array( self::class, 'hydrate_case' ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * Change fields of a case whatever its status (the numbers of an open
	 * case, a flag) — never the status itself: that is transition().
	 *
	 * @param int   $id     Case id.
	 * @param array $fields Any of CASE_COLUMNS except status.
	 * @return bool
	 */
	public function update_case( $id, array $fields ) {
		unset( $fields['status'] );
		return false !== $this->write_case( (int) $id, $fields, array() );
	}

	/**
	 * Move a case from one of the statuses it is expected to be in to a
	 * new one, with the fields that go with the move — in ONE conditional
	 * UPDATE, so a second click or a second confirm finds nothing to move.
	 *
	 * @param int      $id     Case id.
	 * @param string[] $from   The statuses the case may be in.
	 * @param string   $to     The new status.
	 * @param array    $fields Other fields to write with it.
	 * @return bool True only when THIS call moved the case.
	 */
	public function transition( $id, array $from, $to, array $fields = array() ) {
		$from = array_values( array_intersect( array_map( 'strval', $from ), self::STATUSES ) );
		if ( ! $from || ! in_array( (string) $to, self::STATUSES, true ) ) {
			return false;
		}
		$fields['status'] = (string) $to;

		return (int) $this->write_case( (int) $id, $fields, $from ) >= 1;
	}

	/**
	 * Mark a sent case as reminded — only when it was not reminded yet (a
	 * conditional UPDATE, so two overlapping cron runs send one reminder).
	 *
	 * @param int    $id           Case id.
	 * @param string $link_expires The links' new expiry (UTC).
	 * @return bool True only when THIS call marked it.
	 */
	public function mark_reminded( $id, $link_expires ) {
		if ( (int) $id <= 0 ) {
			return false;
		}

		global $wpdb;

		$now = gmdate( 'Y-m-d H:i:s' );

		try {
			$changed = $wpdb->query(
				$wpdb->prepare(
					'UPDATE ' . self::table( 'lostfish_cases' ) . " SET reminded_at = %s, link_expires = %s, updated_at = %s WHERE id = %d AND status = 'sent' AND reminded_at IS NULL", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
					$now,
					(string) $link_expires,
					$now,
					(int) $id
				)
			);
		} catch ( \Throwable $e ) {
			return false;
		}

		return 1 === (int) $changed;
	}

	/**
	 * The one UPDATE behind update_case() and transition().
	 *
	 * @param int      $id     Case id.
	 * @param array    $fields Fields.
	 * @param string[] $from   Statuses the row must be in ([] = any).
	 * @return int|false Rows changed (0 = no such row in such a status, or nothing differed); false on an error.
	 */
	private function write_case( $id, array $fields, array $from ) {
		if ( $id <= 0 ) {
			return false;
		}
		$fields['updated_at'] = gmdate( 'Y-m-d H:i:s' );

		$data = self::clean_fields( $fields );
		if ( ! $data ) {
			return false;
		}

		$set    = array();
		$params = array();
		foreach ( $data as $column => $value ) {
			if ( null === $value ) {
				$set[] = $column . ' = NULL';
				continue;
			}
			$set[]    = $column . ' = ' . ( is_int( $value ) ? '%d' : '%s' );
			$params[] = $value;
		}

		$sql      = 'UPDATE ' . self::table( 'lostfish_cases' ) . ' SET ' . implode( ', ', $set ) . ' WHERE id = %d';
		$params[] = $id;
		if ( $from ) {
			$sql   .= ' AND status IN (' . implode( ',', array_fill( 0, count( $from ), '%s' ) ) . ')';
			$params = array_merge( $params, $from );
		}

		global $wpdb;

		try {
			$changed = $wpdb->query( $wpdb->prepare( $sql, $params ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		} catch ( \Throwable $e ) {
			return false;
		}

		return false === $changed ? false : (int) $changed;
	}

	/**
	 * Fields as the table takes them: known columns only, integers as
	 * integers, strings cut to their column, null kept only where the
	 * column allows it.
	 *
	 * @param array $fields Raw fields.
	 * @return array
	 */
	public static function clean_fields( array $fields ) {
		$lengths = array(
			'status'         => 12,
			'origin'         => 8,
			'kind_key'       => 191,
			'species'        => 120,
			'size_slug'      => 100,
			'size_label'     => 100,
			'fish_name'      => 200,
			'line_ref'       => 64,
			'line_match'     => 12,
			'check_note'     => 255,
			'customer_name'  => 191,
			'customer_email' => 191,
			'currency'       => 8,
			'ship_status'    => 12,
			'ship_note'      => 255,
			'choice'         => 8,
			'choice_via'     => 10,
			'request_ref'    => 60,
			'token_salt'     => 32,
			'close_reason'   => 40,
		);

		$out = array();
		foreach ( $fields as $column => $value ) {
			if ( ! isset( self::CASE_COLUMNS[ $column ] ) ) {
				continue;
			}
			$type     = self::CASE_COLUMNS[ $column ];
			$nullable = '?' === $type[0];
			$integer  = 'd' === substr( $type, -1 );

			if ( null === $value || ( $nullable && '' === $value ) ) {
				$out[ $column ] = $nullable ? null : ( $integer ? 0 : '' );
				continue;
			}
			if ( $integer ) {
				$out[ $column ] = (int) $value;
				continue;
			}
			$text = (string) $value;
			if ( isset( $lengths[ $column ] ) ) {
				$text = function_exists( 'mb_substr' ) ? mb_substr( $text, 0, $lengths[ $column ], 'UTF-8' ) : substr( $text, 0, $lengths[ $column ] );
			}
			$out[ $column ] = $text;
		}

		return $out;
	}

	/**
	 * A case row with its types.
	 *
	 * @param array $row Database row.
	 * @return array
	 */
	public static function hydrate_case( array $row ) {
		$out = array( 'id' => (int) ( $row['id'] ?? 0 ) );
		foreach ( self::CASE_COLUMNS as $column => $type ) {
			$value    = array_key_exists( $column, $row ) ? $row[ $column ] : null;
			$nullable = '?' === $type[0];
			$integer  = 'd' === substr( $type, -1 );

			if ( $nullable && ( null === $value || '' === $value ) ) {
				$out[ $column ] = null;
			} elseif ( $integer ) {
				$out[ $column ] = (int) $value;
			} else {
				$out[ $column ] = (string) $value;
			}
		}
		return $out;
	}

	/**
	 * The sent cases a reminder is due for: sent at or before a moment,
	 * never reminded.
	 *
	 * @param string $sent_before UTC datetime.
	 * @return array[]
	 */
	public function cases_to_remind( $sent_before ) {
		return $this->due( "status = 'sent' AND reminded_at IS NULL AND sent_at IS NOT NULL AND sent_at <= %s", (string) $sent_before );
	}

	/**
	 * The open cases a loss opened at or before a moment (the candidates
	 * of "send by itself after the undo window").
	 *
	 * @param string $created_before UTC datetime.
	 * @return array[]
	 */
	public function cases_to_autosend( $created_before ) {
		return $this->due( "status = 'open' AND origin = 'auto' AND created_at <= %s", (string) $created_before );
	}

	/**
	 * Cases matching a condition with one datetime parameter, oldest first.
	 *
	 * @param string $condition SQL condition with one %s.
	 * @param string $moment    UTC datetime.
	 * @return array[]
	 */
	private function due( $condition, $moment ) {
		global $wpdb;

		try {
			$rows = $wpdb->get_results(
				$wpdb->prepare( 'SELECT * FROM ' . self::table( 'lostfish_cases' ) . ' WHERE ' . $condition . ' ORDER BY id ASC LIMIT 50', $moment ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				ARRAY_A
			);
		} catch ( \Throwable $e ) {
			return array();
		}

		return array_map( array( self::class, 'hydrate_case' ), is_array( $rows ) ? $rows : array() );
	}

	/* ============================================================== */
	/*  Counts                                                         */
	/* ============================================================== */

	/**
	 * Count the cases per status and keep the result in the autoloaded
	 * option (called after every write; 'stale' = sent, and either
	 * reminded or past the life of its links — no answer came).
	 *
	 * @return array{open: int, sent: int, answered: int, done: int, closed: int, stale: int}
	 */
	public function refresh_counts() {
		global $wpdb;

		$counts = array(
			'open'     => 0,
			'sent'     => 0,
			'answered' => 0,
			'done'     => 0,
			'closed'   => 0,
			'stale'    => 0,
		);

		try {
			$rows = $wpdb->get_results( 'SELECT status, COUNT(*) AS n FROM ' . self::table( 'lostfish_cases' ) . ' GROUP BY status', ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			foreach ( is_array( $rows ) ? $rows : array() as $row ) {
				if ( isset( $counts[ $row['status'] ] ) ) {
					$counts[ $row['status'] ] = (int) $row['n'];
				}
			}
			$counts['stale'] = (int) $wpdb->get_var(
				$wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::table( 'lostfish_cases' ) . " WHERE status = 'sent' AND ( reminded_at IS NOT NULL OR link_expires < %s )", gmdate( 'Y-m-d H:i:s' ) ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			);
		} catch ( \Throwable $e ) {
			return $counts;
		}

		update_option( self::COUNTS_OPTION, $counts, true );

		return $counts;
	}

	/**
	 * The stored counts (an autoloaded option — no query).
	 *
	 * @return array{open: int, sent: int, answered: int, done: int, closed: int, stale: int}
	 */
	public static function stored_counts() {
		$stored = get_option( self::COUNTS_OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();
		$out    = array();
		foreach ( array( 'open', 'sent', 'answered', 'done', 'closed', 'stale' ) as $key ) {
			$out[ $key ] = isset( $stored[ $key ] ) ? max( 0, (int) $stored[ $key ] ) : 0;
		}
		return $out;
	}

	/**
	 * How many cases need the owner: to send, to act on an answer, or
	 * with no answer after the reminder.
	 *
	 * @return int
	 */
	public static function needs_you_count() {
		$counts = self::stored_counts();
		return $counts['open'] + $counts['answered'] + $counts['stale'];
	}

	/**
	 * Whether any case still waits for something the cron tick does (a
	 * reminder, a send by itself) — from the stored counts, no query.
	 *
	 * @return bool
	 */
	public static function anything_live() {
		$counts = self::stored_counts();
		return $counts['open'] + $counts['sent'] > 0;
	}

	/* ============================================================== */
	/*  Log                                                            */
	/* ============================================================== */

	/**
	 * Append a log line.
	 *
	 * @param string $event    A short machine word (covered, opened, sent, …).
	 * @param string $message  What happened, for a person.
	 * @param int    $case_id  Case id (0 = none).
	 * @param int    $order_id Order id (0 = none).
	 * @param int    $user_id  Who (0 = the site itself, or the customer).
	 * @return bool
	 */
	public function add_log( $event, $message, $case_id = 0, $order_id = 0, $user_id = 0 ) {
		global $wpdb;

		try {
			$ok = $wpdb->insert(
				self::table( 'lostfish_log' ),
				array(
					'case_id'    => (int) $case_id,
					'order_id'   => (int) $order_id,
					'event'      => substr( (string) $event, 0, 24 ),
					'message'    => (string) $message,
					'user_id'    => (int) $user_id,
					'created_at' => gmdate( 'Y-m-d H:i:s' ),
				)
			);
		} catch ( \Throwable $e ) {
			return false;
		}

		return (bool) $ok;
	}

	/**
	 * The latest log lines, newest first.
	 *
	 * @param int $limit   Row cap.
	 * @param int $case_id Only this case (0 = all).
	 * @return array[]
	 */
	public function log_rows( $limit = self::LOG_LIMIT, $case_id = 0 ) {
		global $wpdb;

		$limit = max( 1, min( 1000, (int) $limit ) );

		try {
			if ( (int) $case_id > 0 ) {
				$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table( 'lostfish_log' ) . ' WHERE case_id = %d ORDER BY id DESC LIMIT %d', (int) $case_id, $limit ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			} else {
				$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table( 'lostfish_log' ) . ' ORDER BY id DESC LIMIT %d', $limit ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			}
		} catch ( \Throwable $e ) {
			return array();
		}

		$out = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$out[] = array(
				'id'         => (int) $row['id'],
				'case_id'    => (int) $row['case_id'],
				'order_id'   => (int) $row['order_id'],
				'event'      => (string) $row['event'],
				'message'    => (string) $row['message'],
				'user_id'    => (int) $row['user_id'],
				'created_at' => (string) $row['created_at'],
			);
		}
		return $out;
	}

	/* ============================================================== */
	/*  WooCommerce's order-item tables (one read)                     */
	/* ============================================================== */

	/**
	 * Which of a list of orders hold a line of one of the products (as
	 * the product or as the variation) or a line that came out of an
	 * invoice. Reads WooCommerce's order-item tables, which are the same
	 * with HPOS on or off; the caller found the order ids through the
	 * order API and loads every order named here through it too.
	 *
	 * @param int[] $order_ids   Order ids (the open orders).
	 * @param int[] $product_ids Product / variation ids ([] = invoice lines only).
	 * @return int[]|null Order ids, or null when the tables could not be read (the caller then looks at every order).
	 */
	public function orders_with_lines( array $order_ids, array $product_ids ) {
		$order_ids   = array_values( array_unique( array_filter( array_map( 'intval', $order_ids ) ) ) );
		$product_ids = array_values( array_unique( array_filter( array_map( 'intval', $product_ids ) ) ) );
		if ( ! $order_ids ) {
			return array();
		}

		global $wpdb;

		$items = $wpdb->prefix . 'woocommerce_order_items';
		$meta  = $wpdb->prefix . 'woocommerce_order_itemmeta';
		$found = array();

		try {
			foreach ( array_chunk( $order_ids, 200 ) as $chunk ) {
				$params = $chunk;
				$match  = "m.meta_key IN ('_fh_invoice_fish','_fh_invoice_lines')";
				if ( $product_ids ) {
					$match .= " OR ( m.meta_key IN ('_product_id','_variation_id') AND m.meta_value IN (" . implode( ',', array_fill( 0, count( $product_ids ), '%s' ) ) . ') )';
					$params = array_merge( $params, array_map( 'strval', $product_ids ) );
				}

				$sql = "SELECT DISTINCT oi.order_id FROM {$items} oi INNER JOIN {$meta} m ON m.order_item_id = oi.order_item_id WHERE oi.order_item_type = 'line_item' AND oi.order_id IN (" . implode( ',', array_fill( 0, count( $chunk ), '%d' ) ) . ') AND ( ' . $match . ' )';

				$suppress = method_exists( $wpdb, 'suppress_errors' ) ? $wpdb->suppress_errors( true ) : null;
				$ids      = $wpdb->get_col( $wpdb->prepare( $sql, $params ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$error    = isset( $wpdb->last_error ) ? (string) $wpdb->last_error : '';
				if ( null !== $suppress ) {
					$wpdb->suppress_errors( $suppress );
				}
				if ( ! is_array( $ids ) || '' !== $error ) {
					return null;
				}
				$found = array_merge( $found, array_map( 'intval', $ids ) );
			}
		} catch ( \Throwable $e ) {
			return null;
		}

		return array_values( array_unique( $found ) );
	}
}
