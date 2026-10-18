<?php
/**
 * Snippet editor store — the ONLY file of the section that touches $wpdb
 * for the editor (Loader's one Code Snippets guard query aside).
 *
 * Three tables, created with dbDelta when the Snippets section boots or is
 * activated, gated by the autoloaded option fishotel_snippets_db_version
 * (so a booted site pays no query for it, and an ordinary request never
 * runs SHOW TABLES or dbDelta):
 *
 *   {prefix}fh_snippets          id, slug (unique), name, description, type,
 *                                scope, priority, active, code, version,
 *                                created_by / _at, updated_by / _at, exported_at
 *   {prefix}fh_snippet_revisions id, snippet_id, version, code, meta (JSON:
 *                                name, description, type, scope, priority,
 *                                active), user_id, created_at — one per
 *                                change, kept when the snippet is deleted
 *   {prefix}fh_snippet_log       the APPEND-ONLY audit log: id, snippet_id,
 *                                slug, action, user_id, user_login,
 *                                remote_addr, forwarded_for (untrusted),
 *                                user_agent, summary, created_at (UTC)
 *
 * No method of this class updates or deletes a log row — append_log() is
 * the only write to that table (a test reads this file to keep it so).
 * A snippet row is updated only where its version still is the one the
 * editor read (optimistic lock), so two saves never overwrite each other.
 *
 * Every method is wrapped: a database error or a missing table means
 * false / null / an empty list, never an exception. The editor (Editor)
 * takes this class as a parameter; the tests use an in-memory twin.
 *
 * @package FisHotel\Misc\Sections\Site_Snippets
 */

namespace FisHotel\Misc\Sections\Site_Snippets;

defined( 'ABSPATH' ) || exit;

/**
 * Class Db_Store
 */
class Db_Store {

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
	const DB_VERSION_OPTION = 'fishotel_snippets_db_version';

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
	const TABLES = array( 'snippets', 'snippet_revisions', 'snippet_log' );

	/**
	 * Fully prefixed table name.
	 *
	 * @param string $name snippets|snippet_revisions|snippet_log.
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
	 * (autoloaded) once every table is really there; on failure record a
	 * marker so maybe_install() waits. The active option is created empty
	 * either way: the loader reads it on every request, and a missing
	 * option would be a query each time.
	 *
	 * @param bool $force Skip the version gate.
	 * @return bool Whether the tables are installed.
	 */
	public static function install( $force = false ) {
		if ( ! $force && self::installed() ) {
			return true;
		}

		if ( function_exists( 'add_option' ) ) {
			add_option( Db_Snippets::OPTION_ACTIVE, array(), '', true );
		}

		global $wpdb;

		try {
			$charset_collate = $wpdb->get_charset_collate();
			$snippets        = self::table( 'snippets' );
			$revisions       = self::table( 'snippet_revisions' );
			$log             = self::table( 'snippet_log' );

			// dbDelta is whitespace-sensitive: each column on its own line,
			// two spaces after PRIMARY KEY, KEY (not INDEX) with a name. Column
			// types are written as MySQL / MariaDB report them (lower case,
			// int(11), int(10) unsigned): dbDelta compares the type
			// case-sensitively and would otherwise ALTER every column on every
			// install or schema bump.
			$sql = array(
				"CREATE TABLE {$snippets} (
					id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
					slug varchar(64) NOT NULL,
					name varchar(191) NOT NULL DEFAULT '',
					description text NULL,
					type varchar(8) NOT NULL DEFAULT 'php',
					scope varchar(20) NOT NULL DEFAULT 'global',
					priority int(11) NOT NULL DEFAULT 10,
					active tinyint(1) NOT NULL DEFAULT 0,
					code longtext NOT NULL,
					version int(10) unsigned NOT NULL DEFAULT 1,
					created_by bigint(20) unsigned NOT NULL DEFAULT 0,
					created_at datetime NOT NULL,
					updated_by bigint(20) unsigned NOT NULL DEFAULT 0,
					updated_at datetime NOT NULL,
					exported_at datetime NULL,
					PRIMARY KEY  (id),
					UNIQUE KEY slug (slug),
					KEY active (active)
				) {$charset_collate};",
				"CREATE TABLE {$revisions} (
					id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
					snippet_id bigint(20) unsigned NOT NULL,
					version int(10) unsigned NOT NULL,
					code longtext NOT NULL,
					meta text NOT NULL,
					user_id bigint(20) unsigned NOT NULL DEFAULT 0,
					created_at datetime NOT NULL,
					PRIMARY KEY  (id),
					UNIQUE KEY snippet_version (snippet_id,version),
					KEY created_at (created_at)
				) {$charset_collate};",
				"CREATE TABLE {$log} (
					id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
					snippet_id bigint(20) unsigned NULL,
					slug varchar(64) NOT NULL DEFAULT '',
					action varchar(20) NOT NULL,
					user_id bigint(20) unsigned NOT NULL DEFAULT 0,
					user_login varchar(60) NOT NULL DEFAULT '',
					remote_addr varchar(45) NOT NULL DEFAULT '',
					forwarded_for varchar(100) NOT NULL DEFAULT '',
					user_agent varchar(255) NOT NULL DEFAULT '',
					summary text NOT NULL,
					created_at datetime NOT NULL,
					PRIMARY KEY  (id),
					KEY snippet_id (snippet_id),
					KEY slug (slug),
					KEY action (action),
					KEY created_at (created_at)
				) {$charset_collate};",
			);

			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
			foreach ( $sql as $statement ) {
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
	 * Start a transaction (the row, its revision and its log entry are
	 * written together or not at all).
	 *
	 * @return bool False when the database refused it.
	 */
	public function begin() {
		return $this->raw( 'START TRANSACTION' );
	}

	/**
	 * Commit.
	 *
	 * @return bool False when the database refused it — the writes are then
	 *              not kept (a Galera cluster, for one, rolls back on COMMIT).
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
	 * @return bool Whether the database accepted it (wpdb answers false on an error).
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
	/*  Snippets                                                       */
	/* ============================================================== */

	/**
	 * Every snippet row, by slug; null when the table cannot be read (so a
	 * caller never mistakes an error for "no snippets").
	 *
	 * @return array[]|null
	 */
	public function all() {
		global $wpdb;

		try {
			$rows = $wpdb->get_results( 'SELECT * FROM ' . self::table( 'snippets' ) . ' ORDER BY slug ASC', ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
		} catch ( \Throwable $e ) {
			return null;
		}

		if ( ! is_array( $rows ) || '' !== (string) $wpdb->last_error ) {
			return null;
		}

		return array_map( array( self::class, 'hydrate' ), $rows );
	}

	/**
	 * One snippet by id.
	 *
	 * @param int $id Id.
	 * @return array|null
	 */
	public function get( $id ) {
		return $this->row( 'snippets', 'id = %d', (int) $id );
	}

	/**
	 * One snippet by slug.
	 *
	 * @param string $slug Slug.
	 * @return array|null
	 */
	public function get_by_slug( $slug ) {
		return $this->row( 'snippets', 'slug = %s', (string) $slug );
	}

	/**
	 * Insert a snippet (with an explicit id when restoring a deleted one).
	 *
	 * @param array $row Columns.
	 * @return int|false New id.
	 */
	public function insert( array $row ) {
		global $wpdb;

		$data = self::snippet_columns( $row );
		if ( ! empty( $row['id'] ) ) {
			$data = array_merge( array( 'id' => (int) $row['id'] ), $data );
		}
		$data['created_by'] = isset( $row['created_by'] ) ? (int) $row['created_by'] : 0;
		$data['created_at'] = isset( $row['created_at'] ) ? (string) $row['created_at'] : gmdate( 'Y-m-d H:i:s' );

		try {
			$ok = $wpdb->insert( self::table( 'snippets' ), $data, self::formats( $data ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		} catch ( \Throwable $e ) {
			return false;
		}

		if ( ! $ok ) {
			return false;
		}

		return ! empty( $row['id'] ) ? (int) $row['id'] : (int) $wpdb->insert_id;
	}

	/**
	 * Update a snippet — only while its version is still $expected_version.
	 *
	 * @param int   $id               Id.
	 * @param array $row              Columns.
	 * @param int   $expected_version The version the editor read.
	 * @return bool Whether exactly that row was updated.
	 */
	public function update( $id, array $row, $expected_version ) {
		global $wpdb;

		$data = self::snippet_columns( $row );

		try {
			$n = $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				self::table( 'snippets' ),
				$data,
				array(
					'id'      => (int) $id,
					'version' => (int) $expected_version,
				),
				self::formats( $data ),
				array( '%d', '%d' )
			);
		} catch ( \Throwable $e ) {
			return false;
		}

		return 1 === (int) $n;
	}

	/**
	 * Delete a snippet row (its revisions and log rows stay).
	 *
	 * @param int $id Id.
	 * @return bool
	 */
	public function delete( $id ) {
		global $wpdb;

		try {
			$n = $wpdb->delete( self::table( 'snippets' ), array( 'id' => (int) $id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		} catch ( \Throwable $e ) {
			return false;
		}

		return 1 === (int) $n;
	}

	/**
	 * Record an export.
	 *
	 * @param int    $id   Id.
	 * @param string $when UTC Y-m-d H:i:s.
	 * @return bool
	 */
	public function set_exported( $id, $when ) {
		global $wpdb;

		try {
			$n = $wpdb->update( self::table( 'snippets' ), array( 'exported_at' => (string) $when ), array( 'id' => (int) $id ), array( '%s' ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		} catch ( \Throwable $e ) {
			return false;
		}

		return false !== $n;
	}

	/* ============================================================== */
	/*  Revisions                                                      */
	/* ============================================================== */

	/**
	 * Add a revision.
	 *
	 * @param array $rev snippet_id, version, code, meta (array), user_id, created_at.
	 * @return int|false
	 */
	public function add_revision( array $rev ) {
		global $wpdb;

		$data = array(
			'snippet_id' => (int) $rev['snippet_id'],
			'version'    => (int) $rev['version'],
			'code'       => (string) $rev['code'],
			'meta'       => (string) wp_json_encode( Editor_Rules::meta_of( (array) $rev['meta'] ) ),
			'user_id'    => (int) $rev['user_id'],
			'created_at' => (string) $rev['created_at'],
		);

		try {
			$ok = $wpdb->insert( self::table( 'snippet_revisions' ), $data, array( '%d', '%d', '%s', '%s', '%d', '%s' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		} catch ( \Throwable $e ) {
			return false;
		}

		return $ok ? (int) $wpdb->insert_id : false;
	}

	/**
	 * A snippet's revisions, newest first.
	 *
	 * @param int $snippet_id Snippet id.
	 * @param int $limit      Cap.
	 * @return array[]
	 */
	public function revisions( $snippet_id, $limit = 200 ) {
		return $this->rows( 'snippet_revisions', 'snippet_id = %d ORDER BY version DESC LIMIT %d', array( (int) $snippet_id, max( 1, (int) $limit ) ) );
	}

	/**
	 * One revision.
	 *
	 * @param int $id Revision id.
	 * @return array|null
	 */
	public function revision( $id ) {
		return $this->row( 'snippet_revisions', 'id = %d', (int) $id );
	}

	/**
	 * A snippet's newest revision (also after the snippet was deleted).
	 *
	 * @param int $snippet_id Snippet id.
	 * @return array|null
	 */
	public function last_revision( $snippet_id ) {
		$rows = $this->revisions( $snippet_id, 1 );
		return empty( $rows ) ? null : $rows[0];
	}

	/* ============================================================== */
	/*  Audit log — append only                                        */
	/* ============================================================== */

	/**
	 * Append one log row. The ONLY write to the log table.
	 *
	 * @param array $row snippet_id (null), slug, action, user_id, user_login,
	 *                   remote_addr, forwarded_for, user_agent, summary, created_at.
	 * @return int|false
	 */
	public function append_log( array $row ) {
		global $wpdb;

		$data = array(
			'snippet_id'    => empty( $row['snippet_id'] ) ? null : (int) $row['snippet_id'],
			'slug'          => substr( (string) ( isset( $row['slug'] ) ? $row['slug'] : '' ), 0, 64 ),
			'action'        => substr( (string) $row['action'], 0, 20 ),
			'user_id'       => isset( $row['user_id'] ) ? (int) $row['user_id'] : 0,
			'user_login'    => substr( (string) ( isset( $row['user_login'] ) ? $row['user_login'] : '' ), 0, 60 ),
			'remote_addr'   => substr( (string) ( isset( $row['remote_addr'] ) ? $row['remote_addr'] : '' ), 0, 45 ),
			'forwarded_for' => substr( (string) ( isset( $row['forwarded_for'] ) ? $row['forwarded_for'] : '' ), 0, 100 ),
			'user_agent'    => (string) ( isset( $row['user_agent'] ) ? $row['user_agent'] : '' ),
			'summary'       => (string) ( isset( $row['summary'] ) ? $row['summary'] : '' ),
			'created_at'    => isset( $row['created_at'] ) ? (string) $row['created_at'] : gmdate( 'Y-m-d H:i:s' ),
		);

		try {
			$ok = $wpdb->insert( self::table( 'snippet_log' ), $data, array( '%d', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		} catch ( \Throwable $e ) {
			return false;
		}

		return $ok ? (int) $wpdb->insert_id : false;
	}

	/**
	 * One log row.
	 *
	 * @param int $id Id.
	 * @return array|null
	 */
	public function log_entry( $id ) {
		return $this->row( 'snippet_log', 'id = %d', (int) $id );
	}

	/**
	 * Log rows, newest first.
	 *
	 * @param array $filters action, slug, snippet_id.
	 * @param int   $limit   Rows.
	 * @param int   $offset  Offset.
	 * @return array[]
	 */
	public function log_rows( array $filters, $limit = 50, $offset = 0 ) {
		$where = self::log_where( $filters );
		$args  = array_merge( $where['args'], array( max( 1, (int) $limit ), max( 0, (int) $offset ) ) );

		return $this->rows( 'snippet_log', $where['sql'] . ' ORDER BY id DESC LIMIT %d OFFSET %d', $args );
	}

	/**
	 * How many log rows match.
	 *
	 * @param array $filters action, slug, snippet_id.
	 * @return int
	 */
	public function log_count( array $filters ) {
		global $wpdb;

		$where = self::log_where( $filters );

		try {
			$n = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::table( 'snippet_log' ) . ' WHERE ' . $where['sql'], $where['args'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
		} catch ( \Throwable $e ) {
			return 0;
		}

		return (int) $n;
	}

	/**
	 * Log rows after an id, oldest first (the CSV reads in batches).
	 *
	 * @param int $after_id Last id read.
	 * @param int $limit    Batch size.
	 * @return array[]
	 */
	public function log_after( $after_id, $limit = 500 ) {
		return $this->rows( 'snippet_log', 'id > %d ORDER BY id ASC LIMIT %d', array( (int) $after_id, max( 1, (int) $limit ) ) );
	}

	/**
	 * WHERE for the log filters (always at least "1 = %d").
	 *
	 * @param array $filters Filters.
	 * @return array{sql: string, args: array}
	 */
	private static function log_where( array $filters ) {
		$sql  = array( '1 = %d' );
		$args = array( 1 );

		if ( ! empty( $filters['action'] ) ) {
			$sql[]  = 'action = %s';
			$args[] = (string) $filters['action'];
		}
		if ( ! empty( $filters['slug'] ) ) {
			$sql[]  = 'slug = %s';
			$args[] = (string) $filters['slug'];
		}
		if ( ! empty( $filters['snippet_id'] ) ) {
			$sql[]  = 'snippet_id = %d';
			$args[] = (int) $filters['snippet_id'];
		}

		return array(
			'sql'  => implode( ' AND ', $sql ),
			'args' => $args,
		);
	}

	/* ============================================================== */
	/*  Helpers                                                        */
	/* ============================================================== */

	/**
	 * One row of a table.
	 *
	 * @param string $table Table (unprefixed).
	 * @param string $where WHERE with one placeholder.
	 * @param mixed  $value Its value.
	 * @return array|null
	 */
	private function row( $table, $where, $value ) {
		global $wpdb;

		try {
			$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table( $table ) . ' WHERE ' . $where . ' LIMIT 1', $value ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
		} catch ( \Throwable $e ) {
			return null;
		}

		if ( ! is_array( $row ) ) {
			return null;
		}

		return 'snippets' === $table ? self::hydrate( $row ) : $row;
	}

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
	 * The writable columns of a snippet row.
	 *
	 * @param array $row Row.
	 * @return array
	 */
	private static function snippet_columns( array $row ) {
		return array(
			'slug'        => (string) $row['slug'],
			'name'        => (string) $row['name'],
			'description' => (string) $row['description'],
			'type'        => (string) $row['type'],
			'scope'       => (string) $row['scope'],
			'priority'    => (int) $row['priority'],
			'active'      => empty( $row['active'] ) ? 0 : 1,
			'code'        => (string) $row['code'],
			'version'     => (int) $row['version'],
			'updated_by'  => isset( $row['updated_by'] ) ? (int) $row['updated_by'] : 0,
			'updated_at'  => isset( $row['updated_at'] ) ? (string) $row['updated_at'] : gmdate( 'Y-m-d H:i:s' ),
		);
	}

	/**
	 * $wpdb formats for columns.
	 *
	 * @param array $data Columns.
	 * @return string[]
	 */
	private static function formats( array $data ) {
		$ints = array( 'id', 'priority', 'active', 'version', 'created_by', 'updated_by' );
		$out  = array();
		foreach ( array_keys( $data ) as $key ) {
			$out[] = in_array( $key, $ints, true ) ? '%d' : '%s';
		}
		return $out;
	}

	/**
	 * A snippet row with typed values.
	 *
	 * @param array $row Raw row.
	 * @return array
	 */
	public static function hydrate( array $row ) {
		foreach ( array( 'id', 'priority', 'active', 'version', 'created_by', 'updated_by' ) as $key ) {
			if ( isset( $row[ $key ] ) ) {
				$row[ $key ] = (int) $row[ $key ];
			}
		}
		foreach ( array( 'slug', 'name', 'description', 'type', 'scope', 'code' ) as $key ) {
			$row[ $key ] = isset( $row[ $key ] ) ? (string) $row[ $key ] : '';
		}
		return $row;
	}
}
