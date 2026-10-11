<?php
/**
 * Health store — the ONLY file in the section that touches $wpdb.
 *
 * One table, {prefix}fh_log, created with dbDelta on first boot (gated by
 * the fishotel_health_db_version option) and on plugin activation. Every
 * write is wrapped: a missing table or a database error means the call
 * returns false and nothing else happens — the section must never be the
 * thing that breaks the site it is watching.
 *
 * logged_at is UTC (gmdate) so the retention math and the "N h ago" labels
 * do not move with the site timezone or DST.
 *
 * @package FisHotel\Misc\Sections\Health
 */

namespace FisHotel\Misc\Sections\Health;

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
	 * Option holding the installed schema version.
	 *
	 * @var string
	 */
	const DB_VERSION_OPTION = 'fishotel_health_db_version';

	/**
	 * Levels the table accepts, worst last.
	 *
	 * @var string[]
	 */
	const LEVELS = array( 'debug', 'info', 'warning', 'error', 'critical' );

	/**
	 * Whether the table exists, memoised per request (null = not checked).
	 *
	 * @var bool|null
	 */
	private static $table_exists = null;

	/**
	 * Fully-prefixed table name.
	 *
	 * @return string
	 */
	public static function table_name() {
		global $wpdb;
		return $wpdb->prefix . 'fh_log';
	}

	/**
	 * Create / upgrade the table via dbDelta, gated on the stored version.
	 *
	 * The version option is written only once the table is really there, so
	 * a CREATE that failed (privileges, disk, connection) is retried on the
	 * next boot instead of being recorded as installed. Activation passes
	 * $force so a deactivate/reactivate recreates a dropped table, as the
	 * Overview and Log notices promise — dbDelta is idempotent and
	 * activation is rare, so that costs nothing on normal requests.
	 *
	 * @param bool $force Skip the version gate and run dbDelta regardless.
	 */
	public static function install( $force = false ) {
		$installed = get_option( self::DB_VERSION_OPTION );

		if ( ! $force && self::DB_VERSION === $installed ) {
			return;
		}

		global $wpdb;

		$table           = self::table_name();
		$charset_collate = $wpdb->get_charset_collate();

		// dbDelta is whitespace-sensitive: each column on its own line,
		// two spaces after PRIMARY KEY, KEY (not INDEX) with a name.
		$sql = "CREATE TABLE {$table} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			logged_at DATETIME NOT NULL,
			level VARCHAR(10) NOT NULL DEFAULT 'info',
			source VARCHAR(60) NOT NULL DEFAULT '',
			message TEXT NOT NULL,
			context LONGTEXT NULL,
			fingerprint CHAR(32) NOT NULL DEFAULT '',
			PRIMARY KEY  (id),
			KEY logged_at (logged_at),
			KEY level (level),
			KEY source (source),
			KEY fingerprint (fingerprint)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		self::$table_exists = null;

		if ( self::table_exists() ) {
			update_option( self::DB_VERSION_OPTION, self::DB_VERSION, false );
		}
	}

	/**
	 * Whether the table exists (memoised). A false answer makes every
	 * other method a no-op.
	 *
	 * @return bool
	 */
	public static function table_exists() {
		if ( null !== self::$table_exists ) {
			return self::$table_exists;
		}

		global $wpdb;

		try {
			$table = self::table_name();
			$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );

			self::$table_exists = ( $found === $table );
		} catch ( \Throwable $e ) {
			self::$table_exists = false;
		}

		return self::$table_exists;
	}

	/**
	 * Insert one row.
	 *
	 * @param array $row logged_at (UTC Y-m-d H:i:s), level, source, message,
	 *                   context (array or JSON string or null), fingerprint.
	 * @return int|false Row id, or false when nothing was written.
	 */
	public function insert( array $row ) {
		if ( ! self::table_exists() ) {
			return false;
		}

		global $wpdb;

		$context = $row['context'] ?? null;
		if ( is_array( $context ) ) {
			$context = wp_json_encode( $context );
		}

		try {
			$ok = $wpdb->insert(
				self::table_name(),
				array(
					'logged_at'   => (string) ( $row['logged_at'] ?? gmdate( 'Y-m-d H:i:s' ) ),
					'level'       => substr( (string) ( $row['level'] ?? 'info' ), 0, 10 ),
					'source'      => substr( (string) ( $row['source'] ?? '' ), 0, 60 ),
					'message'     => (string) ( $row['message'] ?? '' ),
					'context'     => null === $context ? null : (string) $context,
					'fingerprint' => substr( (string) ( $row['fingerprint'] ?? '' ), 0, 32 ),
				),
				array( '%s', '%s', '%s', '%s', '%s', '%s' )
			);
		} catch ( \Throwable $e ) {
			return false;
		}

		return $ok ? (int) $wpdb->insert_id : false;
	}

	/**
	 * The most recent row with this fingerprint logged at or after $since.
	 *
	 * @param string $fingerprint md5.
	 * @param int    $since_ts    UNIX timestamp (UTC).
	 * @return array|null id, logged_at, context (decoded array).
	 */
	public function find_recent( $fingerprint, $since_ts ) {
		if ( ! self::table_exists() ) {
			return null;
		}

		global $wpdb;

		try {
			$row = $wpdb->get_row(
				$wpdb->prepare(
					'SELECT id, logged_at, context FROM ' . self::table_name() . ' WHERE fingerprint = %s AND logged_at >= %s ORDER BY id DESC LIMIT 1',
					$fingerprint,
					gmdate( 'Y-m-d H:i:s', (int) $since_ts )
				),
				ARRAY_A
			);
		} catch ( \Throwable $e ) {
			return null;
		}

		if ( ! $row ) {
			return null;
		}

		$row['id']      = (int) $row['id'];
		$row['context'] = self::decode_context( $row['context'] );

		return $row;
	}

	/**
	 * Increment the 'repeats' counter in a row's context and move its
	 * logged_at to now, so a flood of the same error is one row that says
	 * "×N, last seen …".
	 *
	 * @param int    $id        Row id.
	 * @param string $logged_at UTC Y-m-d H:i:s of this repeat.
	 * @return bool
	 */
	public function bump_repeat( $id, $logged_at ) {
		if ( ! self::table_exists() ) {
			return false;
		}

		global $wpdb;

		try {
			$raw = $wpdb->get_var( $wpdb->prepare( 'SELECT context FROM ' . self::table_name() . ' WHERE id = %d', (int) $id ) );

			$context            = self::decode_context( $raw );
			$context['repeats'] = (int) ( $context['repeats'] ?? 1 ) + 1;

			$ok = $wpdb->update(
				self::table_name(),
				array(
					'context'   => wp_json_encode( $context ),
					'logged_at' => (string) $logged_at,
				),
				array( 'id' => (int) $id ),
				array( '%s', '%s' ),
				array( '%d' )
			);
		} catch ( \Throwable $e ) {
			return false;
		}

		return false !== $ok;
	}

	/**
	 * Most rows one query() page may hold — the memory guard for a page of
	 * 2 KB messages + 8 KB contexts. The CSV export pages in chunks of this.
	 *
	 * @var int
	 */
	const MAX_PER_PAGE = 500;

	/**
	 * Page through the log, last-seen first (a folded repeat moves a row's
	 * logged_at forward, so logged_at — not id — is "newest"; id breaks ties
	 * so pagination is deterministic).
	 *
	 * @param array $filters level (string), source (string), search (string).
	 * @param int   $page     1-based.
	 * @param int   $per_page Rows per page (clamped to MAX_PER_PAGE).
	 * @return array{rows: array[], total: int}
	 */
	public function query( array $filters, $page = 1, $per_page = 50 ) {
		$empty = array(
			'rows'  => array(),
			'total' => 0,
		);

		if ( ! self::table_exists() ) {
			return $empty;
		}

		global $wpdb;

		$where  = array( '1=1' );
		$params = array();

		if ( ! empty( $filters['level'] ) && in_array( $filters['level'], self::LEVELS, true ) ) {
			$where[]  = 'level = %s';
			$params[] = $filters['level'];
		}

		if ( ! empty( $filters['source'] ) ) {
			$where[]  = 'source = %s';
			$params[] = substr( (string) $filters['source'], 0, 60 );
		}

		if ( ! empty( $filters['search'] ) ) {
			$like     = '%' . $wpdb->esc_like( (string) $filters['search'] ) . '%';
			$where[]  = '(message LIKE %s OR context LIKE %s)';
			$params[] = $like;
			$params[] = $like;
		}

		$page     = max( 1, (int) $page );
		$per_page = max( 1, min( self::MAX_PER_PAGE, (int) $per_page ) );
		$offset   = ( $page - 1 ) * $per_page;
		$sql_from = ' FROM ' . self::table_name() . ' WHERE ' . implode( ' AND ', $where );

		try {
			$total = $params
				? (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*)' . $sql_from, $params ) ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				: (int) $wpdb->get_var( 'SELECT COUNT(*)' . $sql_from ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

			$params_page   = $params;
			$params_page[] = $per_page;
			$params_page[] = $offset;

			$rows = $wpdb->get_results(
				$wpdb->prepare( 'SELECT id, logged_at, level, source, message, context, fingerprint' . $sql_from . ' ORDER BY logged_at DESC, id DESC LIMIT %d OFFSET %d', $params_page ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				ARRAY_A
			);
		} catch ( \Throwable $e ) {
			return $empty;
		}

		return array(
			'rows'  => array_map( array( self::class, 'hydrate' ), is_array( $rows ) ? $rows : array() ),
			'total' => $total,
		);
	}

	/**
	 * The last $n error / critical rows, last-seen first (same order as
	 * query(), so the Overview agrees with the Log tab).
	 *
	 * @param int $n Count.
	 * @return array[]
	 */
	public function recent_errors( $n = 5 ) {
		if ( ! self::table_exists() ) {
			return array();
		}

		global $wpdb;

		try {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT id, logged_at, level, source, message, context, fingerprint FROM ' . self::table_name() . " WHERE level IN ('error','critical') ORDER BY logged_at DESC, id DESC LIMIT %d",
					max( 1, (int) $n )
				),
				ARRAY_A
			);
		} catch ( \Throwable $e ) {
			return array();
		}

		return array_map( array( self::class, 'hydrate' ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * Rows at a level (or any level when '') since a timestamp, optionally
	 * for one source.
	 *
	 * @param string $level    Level or '' for all.
	 * @param int    $since_ts UNIX timestamp.
	 * @param string $source   Source or '' for all.
	 * @return int
	 */
	public function count_since( $level, $since_ts, $source = '' ) {
		if ( ! self::table_exists() ) {
			return 0;
		}

		global $wpdb;

		$where  = array( 'logged_at >= %s' );
		$params = array( gmdate( 'Y-m-d H:i:s', (int) $since_ts ) );

		if ( '' !== (string) $level ) {
			$where[]  = 'level = %s';
			$params[] = (string) $level;
		}

		if ( '' !== (string) $source ) {
			$where[]  = 'source = %s';
			$params[] = (string) $source;
		}

		try {
			return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::table_name() . ' WHERE ' . implode( ' AND ', $where ), $params ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		} catch ( \Throwable $e ) {
			return 0;
		}
	}

	/**
	 * Like count_since(), but counts events rather than rows: the same
	 * failure within 10 minutes is folded into one row with a `repeats`
	 * counter in its context, so this sums those (a row without one is 1).
	 * Only the context column is read and the fold keeps the set tiny, so
	 * the summing is done in PHP — no JSON_EXTRACT / MySQL-version
	 * dependency.
	 *
	 * @param string $level    Level or '' for all.
	 * @param int    $since_ts UNIX timestamp.
	 * @param string $source   Source or '' for all.
	 * @return int
	 */
	public function sum_repeats_since( $level, $since_ts, $source = '' ) {
		if ( ! self::table_exists() ) {
			return 0;
		}

		global $wpdb;

		$where  = array( 'logged_at >= %s' );
		$params = array( gmdate( 'Y-m-d H:i:s', (int) $since_ts ) );

		if ( '' !== (string) $level ) {
			$where[]  = 'level = %s';
			$params[] = (string) $level;
		}

		if ( '' !== (string) $source ) {
			$where[]  = 'source = %s';
			$params[] = (string) $source;
		}

		try {
			$contexts = $wpdb->get_col( $wpdb->prepare( 'SELECT context FROM ' . self::table_name() . ' WHERE ' . implode( ' AND ', $where ), $params ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		} catch ( \Throwable $e ) {
			return 0;
		}

		$sum = 0;
		foreach ( (array) $contexts as $raw ) {
			$context = self::decode_context( $raw );
			$sum    += max( 1, (int) ( $context['repeats'] ?? 1 ) );
		}

		return $sum;
	}

	/**
	 * The newest error / critical row for a source since a timestamp.
	 *
	 * @param string $source   Source.
	 * @param int    $since_ts UNIX timestamp.
	 * @return array|null
	 */
	public function last_error_for_source( $source, $since_ts ) {
		if ( ! self::table_exists() ) {
			return null;
		}

		global $wpdb;

		try {
			$row = $wpdb->get_row(
				$wpdb->prepare(
					'SELECT id, logged_at, level, source, message, context, fingerprint FROM ' . self::table_name() . " WHERE source = %s AND level IN ('error','critical') AND logged_at >= %s ORDER BY logged_at DESC, id DESC LIMIT 1",
					(string) $source,
					gmdate( 'Y-m-d H:i:s', (int) $since_ts )
				),
				ARRAY_A
			);
		} catch ( \Throwable $e ) {
			return null;
		}

		return $row ? self::hydrate( $row ) : null;
	}

	/**
	 * Delete rows older than the retention and anything beyond the row cap.
	 *
	 * @param int $retention_days Days to keep.
	 * @param int $max_rows       Row cap (newest kept).
	 * @return int Rows deleted.
	 */
	public function prune( $retention_days, $max_rows ) {
		if ( ! self::table_exists() ) {
			return 0;
		}

		global $wpdb;

		$deleted = 0;
		$table   = self::table_name();

		try {
			$retention_days = max( 1, (int) $retention_days );
			$cutoff         = gmdate( 'Y-m-d H:i:s', time() - $retention_days * DAY_IN_SECONDS );
			$n              = $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE logged_at < %s", $cutoff ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$deleted       += (int) $n;

			$max_rows = max( 1, (int) $max_rows );
			$total    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

			if ( $total > $max_rows ) {
				$floor = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} ORDER BY id DESC LIMIT 1 OFFSET %d", $max_rows - 1 ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				if ( $floor > 0 ) {
					$n        = $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE id < %d", $floor ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$deleted += (int) $n;
				}
			}
		} catch ( \Throwable $e ) {
			return $deleted;
		}

		return $deleted;
	}

	/**
	 * Empty the table.
	 *
	 * @return bool
	 */
	public function clear() {
		if ( ! self::table_exists() ) {
			return false;
		}

		global $wpdb;

		try {
			$table = self::table_name();
			return false !== $wpdb->query( "TRUNCATE TABLE {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	/**
	 * Decode a stored context column to an array.
	 *
	 * @param mixed $raw JSON or null.
	 * @return array
	 */
	public static function decode_context( $raw ) {
		if ( ! is_string( $raw ) || '' === $raw ) {
			return array();
		}

		$decoded = json_decode( $raw, true );

		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * Normalise a database row: int id, decoded context, UNIX timestamp.
	 *
	 * @param array $row Raw row.
	 * @return array
	 */
	public static function hydrate( array $row ) {
		$row['id']        = (int) ( $row['id'] ?? 0 );
		$row['context']   = self::decode_context( $row['context'] ?? null );
		$row['logged_ts'] = isset( $row['logged_at'] ) ? (int) strtotime( $row['logged_at'] . ' UTC' ) : 0;
		$row['repeats']   = (int) ( $row['context']['repeats'] ?? 1 );

		return $row;
	}
}
