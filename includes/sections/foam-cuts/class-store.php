<?php
/**
 * Foam Cuts store — the ONLY file in the section that touches $wpdb.
 *
 * Three tables, created with dbDelta when the section first boots or the
 * plugin is activated, gated by the autoloaded option
 * fishotel_foam_cuts_db_version (so a booted site pays no query for it,
 * and an ordinary request never runs SHOW TABLES or dbDelta). The version
 * is written only once every table is really there; a failed install
 * leaves a marker instead and is retried in wp-admin at most once an hour.
 *
 *   {prefix}fh_foam_leftovers  the leftovers: id, label (L1, L2 … written
 *                              on the piece), length_u / width_u (units —
 *                              ten-thousandths of an inch), status (stock /
 *                              used / removed), origin (plan / manual),
 *                              plan_id (the plan that made it), used_plan_id
 *                              (the plan that cut it up), note, created_at,
 *                              updated_at (UTC)
 *   {prefix}fh_foam_plans      the plans: id, status (draft / cut), title,
 *                              origin (typed / orders), ship_date, sheets
 *                              (whole sheets it takes), label_from /
 *                              label_count (the L-numbers it hands out),
 *                              data (the plan as JSON — Plan's shape),
 *                              created_by / _at, cut_by / _at, undone_at,
 *                              updated_at (UTC)
 *   {prefix}fh_foam_sheet_log  whole sheets, as entries that add up to the
 *                              count: id, kind (bought / count / plan /
 *                              undo), sheets (the change, + or −), plan_id,
 *                              note, user_id, created_at (UTC)
 *
 * The next free L-number lives in the option fishotel_foam_cuts_next_label
 * (not autoloaded: it is only read when a plan is made or a leftover is
 * added). It is read and moved with statements of its own on the options
 * table — the adding happens inside the database, so two requests at the
 * same moment never get the same numbers. A number is never given out
 * twice, so a label still written on an old offcut can never be mistaken
 * for a leftover in stock.
 *
 * A status changes only where it still is what the caller read (UPDATE …
 * WHERE status = …), which is what makes "mark as cut" happen once.
 *
 * Every method is wrapped: a database error or a missing table means
 * false / null / 0 / an empty list, never an exception. The services
 * (Stock, Planner) take a store as a parameter; the tests use an
 * in-memory twin.
 *
 * @package FisHotel\Misc\Sections\Foam_Cuts
 */

namespace FisHotel\Misc\Sections\Foam_Cuts;

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
	const DB_VERSION_OPTION = 'fishotel_foam_cuts_db_version';

	/**
	 * Option holding the next free L-number (not autoloaded).
	 *
	 * @var string
	 */
	const LABEL_OPTION = 'fishotel_foam_cuts_next_label';

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
	const TABLES = array( 'foam_leftovers', 'foam_plans', 'foam_sheet_log' );

	const LEFTOVER_STATUSES = array( 'stock', 'used', 'removed' );
	const PLAN_STATUSES     = array( 'draft', 'cut' );
	const SHEET_KINDS       = array( 'bought', 'count', 'plan', 'undo' );

	/**
	 * Fully prefixed table name.
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
	 * install is not retried on every request (a visitor would pay a
	 * dbDelta each time) — only in wp-admin, at most once an hour.
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
	 * (autoloaded) once every table is really there; on failure record a
	 * marker so maybe_install() waits.
	 *
	 * @param bool $force Skip the version gate.
	 * @return bool Whether the tables are installed.
	 */
	public static function install( $force = false ) {
		if ( ! $force && self::installed() ) {
			return true;
		}

		global $wpdb;

		try {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
			foreach ( self::schema( $wpdb->get_charset_collate() ) as $statement ) {
				dbDelta( $statement );
			}

			$ok = self::tables_exist();
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
		$leftovers = self::table( 'foam_leftovers' );
		$plans     = self::table( 'foam_plans' );
		$sheets    = self::table( 'foam_sheet_log' );

		return array(
			"CREATE TABLE {$leftovers} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				label varchar(20) NOT NULL DEFAULT '',
				length_u int(10) unsigned NOT NULL DEFAULT 0,
				width_u int(10) unsigned NOT NULL DEFAULT 0,
				status varchar(12) NOT NULL DEFAULT 'stock',
				origin varchar(12) NOT NULL DEFAULT 'manual',
				plan_id bigint(20) unsigned NOT NULL DEFAULT 0,
				used_plan_id bigint(20) unsigned NOT NULL DEFAULT 0,
				note varchar(190) NOT NULL DEFAULT '',
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY status (status),
				KEY plan_id (plan_id),
				KEY used_plan_id (used_plan_id)
			) {$charset_collate};",
			"CREATE TABLE {$plans} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				status varchar(12) NOT NULL DEFAULT 'draft',
				title varchar(190) NOT NULL DEFAULT '',
				origin varchar(12) NOT NULL DEFAULT 'typed',
				ship_date date NULL,
				sheets int(11) NOT NULL DEFAULT 0,
				label_from int(10) unsigned NOT NULL DEFAULT 0,
				label_count int(10) unsigned NOT NULL DEFAULT 0,
				data longtext NOT NULL,
				created_by bigint(20) unsigned NOT NULL DEFAULT 0,
				created_at datetime NOT NULL,
				cut_by bigint(20) unsigned NOT NULL DEFAULT 0,
				cut_at datetime NULL,
				undone_at datetime NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY status (status),
				KEY created_at (created_at)
			) {$charset_collate};",
			"CREATE TABLE {$sheets} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				kind varchar(12) NOT NULL DEFAULT 'bought',
				sheets int(11) NOT NULL DEFAULT 0,
				plan_id bigint(20) unsigned NOT NULL DEFAULT 0,
				note varchar(190) NOT NULL DEFAULT '',
				user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY plan_id (plan_id),
				KEY created_at (created_at)
			) {$charset_collate};",
		);
	}

	/**
	 * Whether every table exists (install and the admin page only).
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

	/* ============================================================== */
	/*  Transactions                                                   */
	/* ============================================================== */

	/**
	 * Start a transaction (a plan, its leftovers and its sheets change
	 * together or not at all).
	 *
	 * @return bool False when the database refused it.
	 */
	public function begin() {
		return $this->raw( 'START TRANSACTION' );
	}

	/**
	 * Commit.
	 *
	 * @return bool False when the database refused it — the writes are then not kept.
	 */
	public function commit() {
		return $this->raw( 'COMMIT' );
	}

	/**
	 * Roll back.
	 *
	 * @return bool
	 */
	public function rollback() {
		return $this->raw( 'ROLLBACK' );
	}

	/**
	 * A statement without arguments, wrapped.
	 *
	 * @param string $sql Statement.
	 * @return bool Whether the database accepted it.
	 */
	private function raw( $sql ) {
		global $wpdb;
		try {
			return false !== $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery -- a constant statement.
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	/* ============================================================== */
	/*  Leftovers                                                      */
	/* ============================================================== */

	/**
	 * The leftovers with a status, oldest first (the order their labels
	 * were given out in).
	 *
	 * @param string $status stock|used|removed.
	 * @param int    $limit  Cap.
	 * @return array[]
	 */
	public function leftovers( $status = 'stock', $limit = 500 ) {
		return array_map( array( self::class, 'hydrate_leftover' ), $this->rows( 'foam_leftovers', 'status = %s ORDER BY id ASC LIMIT %d', array( (string) $status, max( 1, (int) $limit ) ) ) );
	}

	/**
	 * One leftover.
	 *
	 * @param int $id Id.
	 * @return array|null
	 */
	public function leftover( $id ) {
		$rows = $this->rows( 'foam_leftovers', 'id = %d LIMIT 1', array( (int) $id ) );
		return $rows ? self::hydrate_leftover( $rows[0] ) : null;
	}

	/**
	 * Leftovers by id (whatever their status), keyed by id.
	 *
	 * @param int[] $ids Ids.
	 * @return array<int, array>
	 */
	public function leftovers_by_ids( array $ids ) {
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
		if ( ! $ids ) {
			return array();
		}

		$out = array();
		foreach ( $this->rows( 'foam_leftovers', 'id IN (' . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ')', $ids ) as $row ) {
			$row               = self::hydrate_leftover( $row );
			$out[ $row['id'] ] = $row;
		}
		return $out;
	}

	/**
	 * The leftovers a plan made (whatever became of them), oldest first.
	 *
	 * @param int $plan_id Plan id.
	 * @return array[]
	 */
	public function leftovers_of_plan( $plan_id ) {
		return array_map( array( self::class, 'hydrate_leftover' ), $this->rows( 'foam_leftovers', 'plan_id = %d ORDER BY id ASC', array( (int) $plan_id ) ) );
	}

	/**
	 * Add a leftover.
	 *
	 * @param array $row label, length, width (units), status, origin, plan_id, note.
	 * @return int|false New id.
	 */
	public function add_leftover( array $row ) {
		global $wpdb;

		$now  = gmdate( 'Y-m-d H:i:s' );
		$data = array(
			'label'        => substr( (string) ( isset( $row['label'] ) ? $row['label'] : '' ), 0, 20 ),
			'length_u'     => max( 0, (int) $row['length'] ),
			'width_u'      => max( 0, (int) $row['width'] ),
			'status'       => isset( $row['status'] ) && in_array( $row['status'], self::LEFTOVER_STATUSES, true ) ? $row['status'] : 'stock',
			'origin'       => isset( $row['origin'] ) && 'plan' === $row['origin'] ? 'plan' : 'manual',
			'plan_id'      => isset( $row['plan_id'] ) ? max( 0, (int) $row['plan_id'] ) : 0,
			'used_plan_id' => 0,
			'note'         => self::clip( isset( $row['note'] ) ? $row['note'] : '', 190 ),
			'created_at'   => $now,
			'updated_at'   => $now,
		);

		try {
			$ok = $wpdb->insert( self::table( 'foam_leftovers' ), $data, array( '%s', '%d', '%d', '%s', '%s', '%d', '%d', '%s', '%s', '%s' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		} catch ( \Throwable $e ) {
			return false;
		}

		return $ok ? (int) $wpdb->insert_id : false;
	}

	/**
	 * Change the size or the note of a leftover that is in stock.
	 *
	 * @param int   $id     Id.
	 * @param array $fields length, width (units), note — any of them.
	 * @return bool Whether the leftover is in stock and was written.
	 */
	public function update_leftover( $id, array $fields ) {
		global $wpdb;

		$data    = array( 'updated_at' => gmdate( 'Y-m-d H:i:s' ) );
		$formats = array( '%s' );

		if ( isset( $fields['length'] ) ) {
			$data['length_u'] = max( 0, (int) $fields['length'] );
			$formats[]        = '%d';
		}
		if ( isset( $fields['width'] ) ) {
			$data['width_u'] = max( 0, (int) $fields['width'] );
			$formats[]       = '%d';
		}
		if ( isset( $fields['note'] ) ) {
			$data['note'] = self::clip( $fields['note'], 190 );
			$formats[]    = '%s';
		}

		try {
			$n = $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				self::table( 'foam_leftovers' ),
				$data,
				array(
					'id'     => (int) $id,
					'status' => 'stock',
				),
				$formats,
				array( '%d', '%s' )
			);
		} catch ( \Throwable $e ) {
			return false;
		}

		return false !== $n && (int) $n >= 0 && null !== $this->in_stock( $id );
	}

	/**
	 * The leftover when it is in stock, else null.
	 *
	 * @param int $id Id.
	 * @return array|null
	 */
	private function in_stock( $id ) {
		$row = $this->leftover( $id );
		return $row && 'stock' === $row['status'] ? $row : null;
	}

	/**
	 * Move leftovers from one status to another — only those that still
	 * have the first status (and, when leaving "used", only those this plan
	 * used).
	 *
	 * @param int[]  $ids     Ids.
	 * @param string $from    The status they must have.
	 * @param string $to      The new status.
	 * @param int    $plan_id The plan that uses them (to = used), or that used them (from = used); 0 otherwise.
	 * @return int How many changed.
	 */
	public function mark_leftovers( array $ids, $from, $to, $plan_id = 0 ) {
		global $wpdb;

		$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
		if ( ! $ids || ! in_array( $from, self::LEFTOVER_STATUSES, true ) || ! in_array( $to, self::LEFTOVER_STATUSES, true ) ) {
			return 0;
		}

		$sql  = 'UPDATE ' . self::table( 'foam_leftovers' ) . ' SET status = %s, used_plan_id = %d, updated_at = %s WHERE status = %s';
		$args = array( (string) $to, 'used' === $to ? (int) $plan_id : 0, gmdate( 'Y-m-d H:i:s' ), (string) $from );

		if ( 'used' === $from ) {
			$sql   .= ' AND used_plan_id = %d';
			$args[] = (int) $plan_id;
		}

		$sql .= ' AND id IN (' . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ')';
		$args = array_merge( $args, $ids );

		try {
			$n = $wpdb->query( $wpdb->prepare( $sql, $args ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
		} catch ( \Throwable $e ) {
			return 0;
		}

		return false === $n ? 0 : (int) $n;
	}

	/**
	 * Delete the leftovers a plan made that are still in stock (an undo).
	 *
	 * @param int $plan_id Plan id.
	 * @return int How many were deleted.
	 */
	public function delete_leftovers_of_plan( $plan_id ) {
		global $wpdb;

		if ( (int) $plan_id < 1 ) {
			return 0;
		}

		try {
			$n = $wpdb->delete( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				self::table( 'foam_leftovers' ),
				array(
					'plan_id' => (int) $plan_id,
					'status'  => 'stock',
				),
				array( '%d', '%s' )
			);
		} catch ( \Throwable $e ) {
			return 0;
		}

		return false === $n ? 0 : (int) $n;
	}

	/* ============================================================== */
	/*  Whole sheets                                                   */
	/* ============================================================== */

	/**
	 * Whole sheets in stock: the entries added up. Can be below 0 when a
	 * plan took sheets nobody logged — the screen then asks for a count.
	 *
	 * @return int
	 */
	public function sheets() {
		global $wpdb;

		try {
			$n = $wpdb->get_var( 'SELECT COALESCE(SUM(sheets), 0) FROM ' . self::table( 'foam_sheet_log' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
		} catch ( \Throwable $e ) {
			return 0;
		}

		return (int) $n;
	}

	/**
	 * The sheet entries, newest first.
	 *
	 * @param int $limit Rows.
	 * @return array[]
	 */
	public function sheet_log( $limit = 30 ) {
		$out = array();
		foreach ( $this->rows( 'foam_sheet_log', '1 = %d ORDER BY id DESC LIMIT %d', array( 1, max( 1, (int) $limit ) ) ) as $row ) {
			$out[] = array(
				'id'         => (int) $row['id'],
				'kind'       => (string) $row['kind'],
				'sheets'     => (int) $row['sheets'],
				'plan_id'    => (int) $row['plan_id'],
				'note'       => (string) $row['note'],
				'user_id'    => (int) $row['user_id'],
				'created_at' => (string) $row['created_at'],
			);
		}
		return $out;
	}

	/**
	 * Add a sheet entry.
	 *
	 * @param string $kind    bought|count|plan|undo.
	 * @param int    $sheets  The change (+ or −).
	 * @param int    $plan_id Plan id (0 = none).
	 * @param string $note    Note.
	 * @param int    $user_id Who.
	 * @return int|false New id.
	 */
	public function add_sheet_entry( $kind, $sheets, $plan_id = 0, $note = '', $user_id = 0 ) {
		global $wpdb;

		$data = array(
			'kind'       => in_array( $kind, self::SHEET_KINDS, true ) ? $kind : 'count',
			'sheets'     => (int) $sheets,
			'plan_id'    => max( 0, (int) $plan_id ),
			'note'       => self::clip( $note, 190 ),
			'user_id'    => max( 0, (int) $user_id ),
			'created_at' => gmdate( 'Y-m-d H:i:s' ),
		);

		try {
			$ok = $wpdb->insert( self::table( 'foam_sheet_log' ), $data, array( '%s', '%d', '%d', '%s', '%d', '%s' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		} catch ( \Throwable $e ) {
			return false;
		}

		return $ok ? (int) $wpdb->insert_id : false;
	}

	/* ============================================================== */
	/*  Plans                                                          */
	/* ============================================================== */

	/**
	 * The latest plans, newest first, without their data.
	 *
	 * @param int $limit Rows.
	 * @return array[]
	 */
	public function plans( $limit = 20 ) {
		global $wpdb;

		try {
			$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare(
					'SELECT id, status, title, origin, ship_date, sheets, label_from, label_count, created_by, created_at, cut_by, cut_at, undone_at, updated_at FROM ' . self::table( 'foam_plans' ) . ' ORDER BY id DESC LIMIT %d', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
					max( 1, (int) $limit )
				),
				ARRAY_A
			);
		} catch ( \Throwable $e ) {
			return array();
		}

		return is_array( $rows ) ? array_map( array( self::class, 'hydrate_plan' ), $rows ) : array();
	}

	/**
	 * One plan, with its data decoded.
	 *
	 * @param int $id Id.
	 * @return array|null
	 */
	public function plan( $id ) {
		$rows = $this->rows( 'foam_plans', 'id = %d LIMIT 1', array( (int) $id ) );
		return $rows ? self::hydrate_plan( $rows[0] ) : null;
	}

	/**
	 * The newest plan, with its data decoded.
	 *
	 * @return array|null
	 */
	public function latest_plan() {
		$rows = $this->rows( 'foam_plans', '1 = %d ORDER BY id DESC LIMIT 1', array( 1 ) );
		return $rows ? self::hydrate_plan( $rows[0] ) : null;
	}

	/**
	 * The plans made for the orders of a ship day, newest first, with
	 * their data decoded.
	 *
	 * @param string $ship_ymd Y-m-d.
	 * @param int    $limit    Rows.
	 * @return array[]
	 */
	public function plans_for_day( $ship_ymd, $limit = 20 ) {
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $ship_ymd ) ) {
			return array();
		}
		return array_map( array( self::class, 'hydrate_plan' ), $this->rows( 'foam_plans', 'ship_date = %s AND origin = %s ORDER BY id DESC LIMIT %d', array( (string) $ship_ymd, 'orders', max( 1, (int) $limit ) ) ) );
	}

	/**
	 * The plans for a ship day that are not marked as cut yet (the Today
	 * page's line): id and title only, newest first.
	 *
	 * @param string $ship_ymd Y-m-d.
	 * @return array<int, array{id: int, title: string}>
	 */
	public function drafts_for_day( $ship_ymd ) {
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $ship_ymd ) ) {
			return array();
		}
		$out = array();
		foreach ( $this->rows( 'foam_plans', 'ship_date = %s AND status = %s ORDER BY id DESC LIMIT %d', array( (string) $ship_ymd, 'draft', 20 ) ) as $row ) {
			$out[] = array(
				'id'    => (int) $row['id'],
				'title' => (string) $row['title'],
			);
		}
		return $out;
	}

	/**
	 * Add a plan (a draft).
	 *
	 * @param array $row title, origin, ship_date, sheets, label_from, label_count, data (array), created_by.
	 * @return int|false New id.
	 */
	public function add_plan( array $row ) {
		global $wpdb;

		$json = wp_json_encode( isset( $row['data'] ) ? $row['data'] : array() );
		if ( ! is_string( $json ) ) {
			return false;
		}

		$now  = gmdate( 'Y-m-d H:i:s' );
		$data = array(
			'status'      => 'draft',
			'title'       => self::clip( isset( $row['title'] ) ? $row['title'] : '', 190 ),
			'origin'      => isset( $row['origin'] ) && 'orders' === $row['origin'] ? 'orders' : 'typed',
			'sheets'      => isset( $row['sheets'] ) ? max( 0, (int) $row['sheets'] ) : 0,
			'label_from'  => isset( $row['label_from'] ) ? max( 0, (int) $row['label_from'] ) : 0,
			'label_count' => isset( $row['label_count'] ) ? max( 0, (int) $row['label_count'] ) : 0,
			'data'        => $json,
			'created_by'  => isset( $row['created_by'] ) ? max( 0, (int) $row['created_by'] ) : 0,
			'created_at'  => $now,
			'updated_at'  => $now,
		);
		$formats = array( '%s', '%s', '%s', '%d', '%d', '%d', '%s', '%d', '%s', '%s' );

		if ( ! empty( $row['ship_date'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $row['ship_date'] ) ) {
			$data['ship_date'] = (string) $row['ship_date'];
			$formats[]         = '%s';
		}

		try {
			$ok = $wpdb->insert( self::table( 'foam_plans' ), $data, $formats ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		} catch ( \Throwable $e ) {
			return false;
		}

		return $ok ? (int) $wpdb->insert_id : false;
	}

	/**
	 * Move a plan from one status to another — only while it still has
	 * the first one. This is the gate that lets a plan be applied once.
	 *
	 * @param int    $id      Plan id.
	 * @param string $from    draft|cut.
	 * @param string $to      draft|cut.
	 * @param int    $user_id Who (recorded when the plan becomes "cut").
	 * @return bool Whether exactly this plan changed.
	 */
	public function set_plan_status( $id, $from, $to, $user_id = 0 ) {
		global $wpdb;

		if ( $from === $to || ! in_array( $from, self::PLAN_STATUSES, true ) || ! in_array( $to, self::PLAN_STATUSES, true ) ) {
			return false;
		}

		$now = gmdate( 'Y-m-d H:i:s' );
		$sql = 'cut' === $to
			? $wpdb->prepare( 'UPDATE ' . self::table( 'foam_plans' ) . ' SET status = %s, cut_by = %d, cut_at = %s, updated_at = %s WHERE id = %d AND status = %s', $to, (int) $user_id, $now, $now, (int) $id, $from ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			: $wpdb->prepare( 'UPDATE ' . self::table( 'foam_plans' ) . ' SET status = %s, undone_at = %s, updated_at = %s WHERE id = %d AND status = %s', $to, $now, $now, (int) $id, $from ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		try {
			$n = $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
		} catch ( \Throwable $e ) {
			return false;
		}

		return 1 === (int) $n;
	}

	/**
	 * Delete a plan that is still a draft.
	 *
	 * @param int $id Plan id.
	 * @return bool
	 */
	public function delete_plan( $id ) {
		global $wpdb;

		try {
			$n = $wpdb->delete( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				self::table( 'foam_plans' ),
				array(
					'id'     => (int) $id,
					'status' => 'draft',
				),
				array( '%d', '%s' )
			);
		} catch ( \Throwable $e ) {
			return false;
		}

		return 1 === (int) $n;
	}

	/* ============================================================== */
	/*  Labels                                                         */
	/* ============================================================== */

	/**
	 * The next free L-number, read from the database itself (not through
	 * the options cache: another request may have moved it a moment ago).
	 *
	 * @return int
	 */
	public function next_label() {
		global $wpdb;

		try {
			$value = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", self::LABEL_OPTION ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		} catch ( \Throwable $e ) {
			$value = null;
		}

		return max( 1, (int) $value );
	}

	/**
	 * Take the next $count L-numbers — in ONE statement that adds to the
	 * counter inside the database (LAST_INSERT_ID( value + count ) hands
	 * the new value back to this connection only), so two requests at the
	 * same moment can never be given the same numbers. Reading the counter
	 * and writing it back from PHP could: a double-click on "Make the
	 * plan" did.
	 *
	 * @param int $count How many.
	 * @return int The first of them; 0 when the database refused (nothing was taken).
	 */
	public function reserve_labels( $count ) {
		global $wpdb;

		$count = max( 0, (int) $count );
		if ( 0 === $count ) {
			return $this->next_label();
		}

		$take = $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = LAST_INSERT_ID( GREATEST( CAST( option_value AS UNSIGNED ), 1 ) + %d ) WHERE option_name = %s", $count, self::LABEL_OPTION );

		try {
			$n = $wpdb->query( $take ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
			if ( 1 !== (int) $n ) {
				// The counter is not there yet: make it (never autoloaded) — a second request doing the same changes nothing — and take again.
				$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, '1', 'no')", self::LABEL_OPTION ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$n = $wpdb->query( $take ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
			}
			$next = 1 === (int) $n ? (int) $wpdb->get_var( 'SELECT LAST_INSERT_ID()' ) : 0; // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		} catch ( \Throwable $e ) {
			$next = 0;
		}

		self::forget_label_cache();

		return $next > $count ? $next - $count : 0;
	}

	/**
	 * Give numbers back — only when they are the last ones handed out (a
	 * draft that is deleted straight away does not leave a gap). One
	 * statement with the condition in its WHERE, for the same reason.
	 *
	 * @param int $from  The first number.
	 * @param int $count How many.
	 * @return bool Whether they were given back.
	 */
	public function release_labels( $from, $count ) {
		global $wpdb;

		$from  = (int) $from;
		$count = (int) $count;

		if ( $from < 1 || $count < 1 ) {
			return false;
		}

		try {
			$n = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s", (string) $from, self::LABEL_OPTION, (string) ( $from + $count ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		} catch ( \Throwable $e ) {
			return false;
		}

		self::forget_label_cache();

		return 1 === (int) $n;
	}

	/**
	 * The counter was written past the options API: drop what the object
	 * cache remembers of it, so get_option() elsewhere never sees an old
	 * number.
	 */
	private static function forget_label_cache() {
		if ( ! function_exists( 'wp_cache_delete' ) || ! function_exists( 'wp_cache_get' ) || ! function_exists( 'wp_cache_set' ) ) {
			return;
		}

		wp_cache_delete( self::LABEL_OPTION, 'options' );

		$missing = wp_cache_get( 'notoptions', 'options' );
		if ( is_array( $missing ) && isset( $missing[ self::LABEL_OPTION ] ) ) {
			unset( $missing[ self::LABEL_OPTION ] );
			wp_cache_set( 'notoptions', $missing, 'options' );
		}
	}

	/* ============================================================== */
	/*  Helpers                                                        */
	/* ============================================================== */

	/**
	 * Rows of a table.
	 *
	 * @param string $table Table (unprefixed).
	 * @param string $tail  WHERE … ORDER … LIMIT with placeholders.
	 * @param array  $args  Values.
	 * @return array[]
	 */
	private function rows( $table, $tail, array $args ) {
		global $wpdb;

		try {
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table( $table ) . ' WHERE ' . $tail, $args ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
		} catch ( \Throwable $e ) {
			return array();
		}

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * A leftover row with typed values and sizes under their plain names.
	 *
	 * @param array $row Raw row.
	 * @return array{id: int, label: string, length: int, width: int, status: string, origin: string, plan_id: int, used_plan_id: int, note: string, created_at: string, updated_at: string}
	 */
	public static function hydrate_leftover( array $row ) {
		return array(
			'id'           => (int) $row['id'],
			'label'        => (string) $row['label'],
			'length'       => (int) $row['length_u'],
			'width'        => (int) $row['width_u'],
			'status'       => (string) $row['status'],
			'origin'       => (string) $row['origin'],
			'plan_id'      => (int) $row['plan_id'],
			'used_plan_id' => (int) $row['used_plan_id'],
			'note'         => (string) $row['note'],
			'created_at'   => (string) $row['created_at'],
			'updated_at'   => (string) $row['updated_at'],
		);
	}

	/**
	 * A plan row with typed values; data (when the row has it) decoded.
	 *
	 * @param array $row Raw row.
	 * @return array
	 */
	public static function hydrate_plan( array $row ) {
		$out = array(
			'id'          => (int) $row['id'],
			'status'      => (string) $row['status'],
			'title'       => (string) $row['title'],
			'origin'      => (string) $row['origin'],
			'ship_date'   => isset( $row['ship_date'] ) && '0000-00-00' !== $row['ship_date'] ? (string) $row['ship_date'] : '',
			'sheets'      => (int) $row['sheets'],
			'label_from'  => (int) $row['label_from'],
			'label_count' => (int) $row['label_count'],
			'created_by'  => (int) $row['created_by'],
			'created_at'  => (string) $row['created_at'],
			'cut_by'      => (int) $row['cut_by'],
			'cut_at'      => isset( $row['cut_at'] ) ? (string) $row['cut_at'] : '',
			'undone_at'   => isset( $row['undone_at'] ) ? (string) $row['undone_at'] : '',
			'updated_at'  => (string) $row['updated_at'],
		);

		if ( array_key_exists( 'data', $row ) ) {
			$data        = json_decode( (string) $row['data'], true );
			$out['data'] = is_array( $data ) ? $data : array();
		}

		return $out;
	}

	/**
	 * One plain line, cut to a column's length.
	 *
	 * @param mixed $text   Text.
	 * @param int   $length Characters.
	 * @return string
	 */
	public static function clip( $text, $length ) {
		$text = is_scalar( $text ) ? trim( (string) preg_replace( '/\s+/u', ' ', strip_tags( (string) $text ) ) ) : '';

		return function_exists( 'mb_substr' ) ? mb_substr( $text, 0, (int) $length, 'UTF-8' ) : substr( $text, 0, (int) $length );
	}
}
