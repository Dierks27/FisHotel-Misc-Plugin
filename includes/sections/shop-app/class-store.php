<?php
/**
 * Shop App store — the ONLY file in the section that touches $wpdb.
 *
 * Four tables, created with dbDelta when the section first boots or is
 * activated, gated by the autoloaded option fishotel_shopapp_db_version
 * (so a booted site pays no query for it, and an ordinary request never
 * runs SHOW TABLES or dbDelta); the option is written only once every
 * table — and the column a later version added — is really there:
 *
 *   {prefix}fh_shopapp_devices  one row per enrolled (or waiting, or
 *                               revoked) device: name, status, the SHA-256
 *                               of its token — never the token —, the
 *                               SHA-256 and expiry of a one-time enrolment
 *                               link while it waits, who enrolled it and
 *                               when, last used, the wrong-PIN count and
 *                               when it began, the lockout level and end,
 *                               and the shop session it holds (id, person,
 *                               since).
 *   {prefix}fh_shopapp_people   name, the PIN's hash, its salt and its
 *                               length — never the PIN —, the WordPress
 *                               account the work is recorded under (0 =
 *                               the shared "Shop staff" account), active,
 *                               last unlock.
 *   {prefix}fh_shopapp_log      the APPEND-ONLY access log: event, device
 *                               (id + name as it was), person (id + name
 *                               as it was), the WordPress user who acted,
 *                               a short note, the UTC time.
 *   {prefix}fh_shopapp_saves    the Front Desk writes a shop session sent
 *                               with a save id, for a week: the id's
 *                               hash, whether it finished, and the short
 *                               answer to repeat — so a save that is sent
 *                               twice is written once (Saves).
 *
 * No method of this class updates or deletes a log row — append_log() is
 * the only write to that table (a test reads this file to keep it so).
 *
 * The wrong-PIN budget is taken with ONE guarded UPDATE before a PIN is
 * compared (reserve_attempt()), a lockout is written by one guarded
 * UPDATE (lock_device()), and a one-time link is used up by one guarded
 * UPDATE (claim_enrolment()) — two requests at the same instant cannot
 * both win any of them.
 *
 * Every method is wrapped: a database error or a missing table means
 * false / null / an empty list, never an exception. The services (Access,
 * People, Saves) take this class as a parameter; the tests use an
 * in-memory twin.
 *
 * @package FisHotel\Misc\Sections\Shop_App
 */

namespace FisHotel\Misc\Sections\Shop_App;

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
	const DB_VERSION = '1.1';

	/**
	 * Autoloaded option holding the installed schema version (or a failure
	 * marker "failed:<version>:<time>").
	 *
	 * @var string
	 */
	const DB_VERSION_OPTION = 'fishotel_shopapp_db_version';

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
	const TABLES = array( 'devices', 'people', 'log', 'saves' );

	const STATUS_PENDING = 'pending';
	const STATUS_ACTIVE  = 'active';
	const STATUS_REVOKED = 'revoked';

	/**
	 * Row caps for the list queries.
	 */
	const DEVICES_LIMIT = 300;
	const PEOPLE_LIMIT  = 500;

	/**
	 * Fully prefixed table name.
	 *
	 * @param string $name One of TABLES.
	 * @return string
	 */
	public static function table( $name ) {
		global $wpdb;
		return $wpdb->prefix . 'fh_shopapp_' . $name;
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
	 * prefix, so a test can read the schema).
	 *
	 * dbDelta is whitespace-sensitive: each column on its own line, two
	 * spaces after PRIMARY KEY, KEY (not INDEX) with a name. Column types
	 * are written as MySQL / MariaDB report them (lower case, with the
	 * display width): dbDelta compares the type as text and would
	 * otherwise ALTER every column on every install.
	 *
	 * @param string $charset_collate The charset / collation clause.
	 * @return string[]
	 */
	public static function schema( $charset_collate = '' ) {
		$devices = self::table( 'devices' );
		$people  = self::table( 'people' );
		$log     = self::table( 'log' );
		$saves   = self::table( 'saves' );

		return array(
			"CREATE TABLE {$devices} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				name varchar(80) NOT NULL DEFAULT '',
				status varchar(10) NOT NULL DEFAULT 'pending',
				token_hash char(64) NOT NULL DEFAULT '',
				enrol_hash char(64) NOT NULL DEFAULT '',
				enrol_expires datetime NULL,
				enrolled_by bigint(20) unsigned NOT NULL DEFAULT 0,
				enrolled_at datetime NULL,
				created_at datetime NOT NULL,
				last_used_at datetime NULL,
				revoked_by bigint(20) unsigned NOT NULL DEFAULT 0,
				revoked_at datetime NULL,
				fail_count smallint(5) unsigned NOT NULL DEFAULT 0,
				fail_since datetime NULL,
				lock_level smallint(5) unsigned NOT NULL DEFAULT 0,
				locked_until datetime NULL,
				session_id char(32) NOT NULL DEFAULT '',
				session_person_id bigint(20) unsigned NOT NULL DEFAULT 0,
				session_started_at datetime NULL,
				PRIMARY KEY  (id),
				KEY token_hash (token_hash),
				KEY enrol_hash (enrol_hash),
				KEY status (status)
			) {$charset_collate};",
			"CREATE TABLE {$people} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				name varchar(80) NOT NULL DEFAULT '',
				pin_hash char(64) NOT NULL DEFAULT '',
				pin_salt char(32) NOT NULL DEFAULT '',
				pin_length tinyint(3) unsigned NOT NULL DEFAULT 0,
				user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				active tinyint(1) NOT NULL DEFAULT 1,
				created_by bigint(20) unsigned NOT NULL DEFAULT 0,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				last_unlock_at datetime NULL,
				PRIMARY KEY  (id),
				KEY active (active)
			) {$charset_collate};",
			"CREATE TABLE {$log} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				event varchar(30) NOT NULL,
				device_id bigint(20) unsigned NOT NULL DEFAULT 0,
				device_name varchar(80) NOT NULL DEFAULT '',
				person_id bigint(20) unsigned NOT NULL DEFAULT 0,
				person_name varchar(80) NOT NULL DEFAULT '',
				user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				note varchar(191) NOT NULL DEFAULT '',
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY event (event),
				KEY device_id (device_id),
				KEY created_at (created_at)
			) {$charset_collate};",
			"CREATE TABLE {$saves} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				save_key char(64) NOT NULL,
				device_id bigint(20) unsigned NOT NULL DEFAULT 0,
				status tinyint(1) NOT NULL DEFAULT 0,
				result text NULL,
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY save_key (save_key),
				KEY created_at (created_at)
			) {$charset_collate};",
		);
	}

	/**
	 * Create / upgrade the tables via dbDelta, write the site secret when
	 * there is none, then record the version (autoloaded) once every table
	 * and column is really there; on failure record a marker so
	 * maybe_install() waits.
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
			$sql = self::schema( $wpdb->get_charset_collate() );

			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
			foreach ( $sql as $statement ) {
				dbDelta( $statement );
			}

			$ok = self::tables_exist() && self::columns_exist();
		} catch ( \Throwable $e ) {
			$ok = false;
		}

		if ( $ok ) {
			try {
				Settings::ensure_secret();
			} catch ( \Throwable $e ) {
				unset( $e );
			}
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

	/**
	 * Whether the columns added after 1.0 exist (install and the admin
	 * page only): devices.fail_since, since 1.1.
	 *
	 * @return bool
	 */
	public static function columns_exist() {
		global $wpdb;

		try {
			$found = $wpdb->get_var( $wpdb->prepare( 'SHOW COLUMNS FROM ' . self::table( 'devices' ) . ' LIKE %s', 'fail_since' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
		} catch ( \Throwable $e ) {
			return false;
		}

		return 'fail_since' === $found;
	}

	/* ============================================================== */
	/*  Devices                                                        */
	/* ============================================================== */

	/**
	 * The enrolled device a token hash belongs to (active ones only: a
	 * revoked device is gone the moment it is revoked).
	 *
	 * @param string $token_hash SHA-256 of the cookie's token.
	 * @return array|null
	 */
	public function device_by_token_hash( $token_hash ) {
		if ( 64 !== strlen( (string) $token_hash ) ) {
			return null;
		}
		$rows = $this->rows( 'devices', 'token_hash = %s AND status = %s LIMIT 1', array( (string) $token_hash, self::STATUS_ACTIVE ) );
		return $rows ? self::hydrate_device( $rows[0] ) : null;
	}

	/**
	 * One device by id, whatever its status.
	 *
	 * @param int $id Device id.
	 * @return array|null
	 */
	public function device( $id ) {
		$rows = $this->rows( 'devices', 'id = %d LIMIT 1', array( (int) $id ) );
		return $rows ? self::hydrate_device( $rows[0] ) : null;
	}

	/**
	 * Every device: enrolled first, then the waiting links, then the
	 * revoked ones; newest first within each.
	 *
	 * @return array[]
	 */
	public function devices() {
		$rows = $this->rows(
			'devices',
			"1 = %d ORDER BY CASE status WHEN 'active' THEN 0 WHEN 'pending' THEN 1 ELSE 2 END ASC, id DESC LIMIT %d",
			array( 1, self::DEVICES_LIMIT )
		);
		return array_map( array( self::class, 'hydrate_device' ), $rows );
	}

	/**
	 * Insert a device: enrolled at once (token_hash) or waiting for its
	 * one-time link (enrol_hash + enrol_expires).
	 *
	 * @param array $row name, status, token_hash, enrol_hash, enrol_expires, enrolled_by, enrolled_at.
	 * @return int|false New id.
	 */
	public function create_device( array $row ) {
		global $wpdb;

		$now  = gmdate( 'Y-m-d H:i:s' );
		$data = array(
			'name'          => self::cut( $row['name'] ?? '', 80 ),
			'status'        => self::STATUS_ACTIVE === ( $row['status'] ?? '' ) ? self::STATUS_ACTIVE : self::STATUS_PENDING,
			'token_hash'    => (string) ( $row['token_hash'] ?? '' ),
			'enrol_hash'    => (string) ( $row['enrol_hash'] ?? '' ),
			'enrol_expires' => ! empty( $row['enrol_expires'] ) ? (string) $row['enrol_expires'] : null,
			'enrolled_by'   => (int) ( $row['enrolled_by'] ?? 0 ),
			'enrolled_at'   => ! empty( $row['enrolled_at'] ) ? (string) $row['enrolled_at'] : null,
			'created_at'    => $now,
		);

		try {
			$ok = $wpdb->insert( self::table( 'devices' ), $data, array( '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		} catch ( \Throwable $e ) {
			return false;
		}

		return $ok ? (int) $wpdb->insert_id : false;
	}

	/**
	 * The waiting device a one-time link belongs to, while the link is
	 * still good. Reading it uses nothing up.
	 *
	 * @param string $enrol_hash SHA-256 of the link's token.
	 * @param int    $now        Timestamp.
	 * @return array|null
	 */
	public function pending_by_enrol_hash( $enrol_hash, $now ) {
		if ( 64 !== strlen( (string) $enrol_hash ) ) {
			return null;
		}
		$rows = $this->rows(
			'devices',
			'enrol_hash = %s AND status = %s AND enrol_expires > %s LIMIT 1',
			array( (string) $enrol_hash, self::STATUS_PENDING, gmdate( 'Y-m-d H:i:s', (int) $now ) )
		);
		return $rows ? self::hydrate_device( $rows[0] ) : null;
	}

	/**
	 * Use a one-time link up: the waiting device becomes an enrolled one
	 * with this token hash — in ONE guarded UPDATE, so a link works once
	 * however many requests arrive, and never after it expired.
	 *
	 * @param string $enrol_hash SHA-256 of the link's token.
	 * @param string $token_hash SHA-256 of the new device token.
	 * @param int    $now        Timestamp.
	 * @return array|null The enrolled device, or null when the link was no good.
	 */
	public function claim_enrolment( $enrol_hash, $token_hash, $now ) {
		global $wpdb;

		if ( 64 !== strlen( (string) $enrol_hash ) || 64 !== strlen( (string) $token_hash ) ) {
			return null;
		}

		$when = gmdate( 'Y-m-d H:i:s', (int) $now );

		try {
			$n = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare(
					'UPDATE ' . self::table( 'devices' ) . " SET status = %s, token_hash = %s, enrol_hash = '', enrol_expires = NULL, enrolled_at = %s WHERE status = %s AND enrol_hash = %s AND enrol_expires > %s", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
					self::STATUS_ACTIVE,
					(string) $token_hash,
					$when,
					self::STATUS_PENDING,
					(string) $enrol_hash,
					$when
				)
			);
		} catch ( \Throwable $e ) {
			return null;
		}

		if ( 1 !== (int) $n ) {
			return null;
		}

		return $this->device_by_token_hash( $token_hash );
	}

	/**
	 * Revoke a device: it stops being a device at once, its session ends
	 * with it, and nothing of its token is kept.
	 *
	 * @param int $id  Device id.
	 * @param int $by  The WordPress user revoking it.
	 * @param int $now Timestamp.
	 * @return bool Whether a device was revoked by this call.
	 */
	public function revoke_device( $id, $by, $now ) {
		global $wpdb;

		try {
			$n = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare(
					'UPDATE ' . self::table( 'devices' ) . " SET status = %s, token_hash = '', enrol_hash = '', enrol_expires = NULL, revoked_by = %d, revoked_at = %s, session_id = '', session_person_id = 0, session_started_at = NULL WHERE id = %d AND status <> %s", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
					self::STATUS_REVOKED,
					(int) $by,
					gmdate( 'Y-m-d H:i:s', (int) $now ),
					(int) $id,
					self::STATUS_REVOKED
				)
			);
		} catch ( \Throwable $e ) {
			return false;
		}

		return 1 === (int) $n;
	}

	/**
	 * Remove the waiting devices whose one-time link ran out.
	 *
	 * @param int $now Timestamp.
	 * @return int Rows removed.
	 */
	public function purge_pending( $now ) {
		global $wpdb;

		try {
			$n = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare(
					'DELETE FROM ' . self::table( 'devices' ) . ' WHERE status = %s AND ( enrol_expires IS NULL OR enrol_expires <= %s )', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
					self::STATUS_PENDING,
					gmdate( 'Y-m-d H:i:s', (int) $now )
				)
			);
		} catch ( \Throwable $e ) {
			return 0;
		}

		return (int) $n;
	}

	/**
	 * Update a device's own columns (name, last use, the lockout fields,
	 * the session fields). A null value writes NULL.
	 *
	 * @param int   $id     Device id.
	 * @param array $fields Column => value.
	 * @return bool
	 */
	public function update_device( $id, array $fields ) {
		global $wpdb;

		$allowed = array(
			'name'               => '%s',
			'last_used_at'       => '%s',
			'fail_count'         => '%d',
			'fail_since'         => '%s',
			'lock_level'         => '%d',
			'locked_until'       => '%s',
			'session_id'         => '%s',
			'session_person_id'  => '%d',
			'session_started_at' => '%s',
		);

		$data    = array();
		$formats = array();
		foreach ( $fields as $key => $value ) {
			if ( ! isset( $allowed[ $key ] ) ) {
				continue;
			}
			$data[ $key ] = null === $value ? null : ( '%d' === $allowed[ $key ] ? (int) $value : (string) $value );
			$formats[]    = $allowed[ $key ];
		}
		if ( ! $data ) {
			return false;
		}

		try {
			$n = $wpdb->update( self::table( 'devices' ), $data, array( 'id' => (int) $id ), $formats, array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		} catch ( \Throwable $e ) {
			return false;
		}

		return false !== $n;
	}

	/**
	 * Take one PIN attempt off a device's budget — BEFORE the PIN is
	 * compared — in one guarded UPDATE: it succeeds only while the device
	 * is enrolled, not locked and has tries left, so parallel requests
	 * get no more tries than one after the other would.
	 *
	 * The count is of the attempts since fail_since. Once that is longer
	 * ago than $memory the count starts over with this attempt — in the
	 * same statement, so a burst cannot start it over twice.
	 *
	 * @param int $id     Device id.
	 * @param int $max    Lockout::MAX_ATTEMPTS.
	 * @param int $now    Timestamp.
	 * @param int $memory Lockout::FAIL_MEMORY.
	 * @return bool Whether this request may compare a PIN.
	 */
	public function reserve_attempt( $id, $max, $now, $memory ) {
		global $wpdb;

		$when   = gmdate( 'Y-m-d H:i:s', (int) $now );
		$lapsed = gmdate( 'Y-m-d H:i:s', (int) $now - (int) $memory );

		try {
			// fail_count is assigned first: both CASEs read the stored fail_since.
			$n = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare(
					'UPDATE ' . self::table( 'devices' ) . ' SET fail_count = CASE WHEN fail_since IS NULL OR fail_since <= %s THEN 1 ELSE fail_count + 1 END, fail_since = CASE WHEN fail_since IS NULL OR fail_since <= %s THEN %s ELSE fail_since END WHERE id = %d AND status = %s AND ( locked_until IS NULL OR locked_until <= %s ) AND ( fail_count < %d OR fail_since IS NULL OR fail_since <= %s )', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
					$lapsed,
					$lapsed,
					$when,
					(int) $id,
					self::STATUS_ACTIVE,
					$when,
					(int) $max,
					$lapsed
				)
			);
		} catch ( \Throwable $e ) {
			return false;
		}

		return 1 === (int) $n;
	}

	/**
	 * Give back the attempt a CORRECT PIN took off the budget. Only that
	 * one: the wrong ones before it stay counted until they lapse, so a
	 * person's own PIN is no way to try for somebody else's.
	 *
	 * @param int $id Device id.
	 * @return bool
	 */
	public function release_attempt( $id ) {
		global $wpdb;

		try {
			$n = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare(
					'UPDATE ' . self::table( 'devices' ) . ' SET fail_count = CASE WHEN fail_count > 0 THEN fail_count - 1 ELSE 0 END WHERE id = %d', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
					(int) $id
				)
			);
		} catch ( \Throwable $e ) {
			return false;
		}

		return false !== $n;
	}

	/**
	 * End every shop session a person holds, on whatever device — their
	 * PIN or their account changed, or they were switched off. The next
	 * request of such a device finds nobody unlocked.
	 *
	 * @param int $person_id Person id.
	 * @return int|false Devices whose session was ended.
	 */
	public function end_person_sessions( $person_id ) {
		global $wpdb;

		if ( (int) $person_id <= 0 ) {
			return 0;
		}

		try {
			$n = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare(
					'UPDATE ' . self::table( 'devices' ) . " SET session_id = '', session_person_id = 0, session_started_at = NULL WHERE session_person_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
					(int) $person_id
				)
			);
		} catch ( \Throwable $e ) {
			return false;
		}

		return false === $n ? false : (int) $n;
	}

	/**
	 * Write a lockout (Lockout::after_failure()'s fields) — only while
	 * the wrong-PIN count still stands, so of several requests that all
	 * saw the fifth wrong PIN exactly one locks the device (and logs it).
	 *
	 * @param int   $id     Device id.
	 * @param array $fields locked_until, lock_level.
	 * @param int   $max    Lockout::MAX_ATTEMPTS.
	 * @return bool Whether this call locked it.
	 */
	public function lock_device( $id, array $fields, $max ) {
		global $wpdb;

		try {
			$n = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare(
					'UPDATE ' . self::table( 'devices' ) . ' SET locked_until = %s, lock_level = %d, fail_count = 0 WHERE id = %d AND fail_count >= %d', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
					(string) $fields['locked_until'],
					(int) $fields['lock_level'],
					(int) $id,
					(int) $max
				)
			);
		} catch ( \Throwable $e ) {
			return false;
		}

		return 1 === (int) $n;
	}

	/* ============================================================== */
	/*  People                                                         */
	/* ============================================================== */

	/**
	 * Everyone, active first, then by name.
	 *
	 * @return array[]
	 */
	public function people() {
		$rows = $this->rows( 'people', '1 = %d ORDER BY active DESC, name ASC, id ASC LIMIT %d', array( 1, self::PEOPLE_LIMIT ) );
		return array_map( array( self::class, 'hydrate_person' ), $rows );
	}

	/**
	 * One person.
	 *
	 * @param int $id Person id.
	 * @return array|null
	 */
	public function person( $id ) {
		$rows = $this->rows( 'people', 'id = %d LIMIT 1', array( (int) $id ) );
		return $rows ? self::hydrate_person( $rows[0] ) : null;
	}

	/**
	 * Insert a person.
	 *
	 * @param array $row name, pin_hash, pin_salt, pin_length, user_id, active, created_by.
	 * @return int|false New id.
	 */
	public function insert_person( array $row ) {
		global $wpdb;

		$now  = gmdate( 'Y-m-d H:i:s' );
		$data = array(
			'name'       => self::cut( $row['name'] ?? '', 80 ),
			'pin_hash'   => (string) ( $row['pin_hash'] ?? '' ),
			'pin_salt'   => (string) ( $row['pin_salt'] ?? '' ),
			'pin_length' => (int) ( $row['pin_length'] ?? 0 ),
			'user_id'    => (int) ( $row['user_id'] ?? 0 ),
			'active'     => empty( $row['active'] ) ? 0 : 1,
			'created_by' => (int) ( $row['created_by'] ?? 0 ),
			'created_at' => $now,
			'updated_at' => $now,
		);

		try {
			$ok = $wpdb->insert( self::table( 'people' ), $data, array( '%s', '%s', '%s', '%d', '%d', '%d', '%d', '%s', '%s' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		} catch ( \Throwable $e ) {
			return false;
		}

		return $ok ? (int) $wpdb->insert_id : false;
	}

	/**
	 * Update a person's own columns.
	 *
	 * @param int   $id     Person id.
	 * @param array $fields name, pin_hash, pin_salt, pin_length, user_id, active, last_unlock_at.
	 * @return bool
	 */
	public function update_person( $id, array $fields ) {
		global $wpdb;

		$allowed = array(
			'name'           => '%s',
			'pin_hash'       => '%s',
			'pin_salt'       => '%s',
			'pin_length'     => '%d',
			'user_id'        => '%d',
			'active'         => '%d',
			'last_unlock_at' => '%s',
		);

		$data    = array();
		$formats = array();
		foreach ( $fields as $key => $value ) {
			if ( ! isset( $allowed[ $key ] ) ) {
				continue;
			}
			$data[ $key ] = null === $value ? null : ( '%d' === $allowed[ $key ] ? (int) $value : (string) $value );
			$formats[]    = $allowed[ $key ];
		}
		if ( ! $data ) {
			return false;
		}
		if ( ! isset( $data['last_unlock_at'] ) || count( $data ) > 1 ) {
			$data['updated_at'] = gmdate( 'Y-m-d H:i:s' );
			$formats[]          = '%s';
		}

		try {
			$n = $wpdb->update( self::table( 'people' ), $data, array( 'id' => (int) $id ), $formats, array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		} catch ( \Throwable $e ) {
			return false;
		}

		return false !== $n;
	}

	/* ============================================================== */
	/*  Access log — append only                                       */
	/* ============================================================== */

	/**
	 * Append one log row. The ONLY write to the log table.
	 *
	 * @param array $row event, device_id, device_name, person_id, person_name, user_id, note, created_at.
	 * @return int|false
	 */
	public function append_log( array $row ) {
		global $wpdb;

		$data = array(
			'event'       => self::cut( $row['event'] ?? '', 30 ),
			'device_id'   => (int) ( $row['device_id'] ?? 0 ),
			'device_name' => self::cut( $row['device_name'] ?? '', 80 ),
			'person_id'   => (int) ( $row['person_id'] ?? 0 ),
			'person_name' => self::cut( $row['person_name'] ?? '', 80 ),
			'user_id'     => (int) ( $row['user_id'] ?? 0 ),
			'note'        => self::cut( $row['note'] ?? '', 191 ),
			'created_at'  => ! empty( $row['created_at'] ) ? (string) $row['created_at'] : gmdate( 'Y-m-d H:i:s' ),
		);

		try {
			$ok = $wpdb->insert( self::table( 'log' ), $data, array( '%s', '%d', '%s', '%d', '%s', '%d', '%s', '%s' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		} catch ( \Throwable $e ) {
			return false;
		}

		return $ok ? (int) $wpdb->insert_id : false;
	}

	/**
	 * Log rows, newest first.
	 *
	 * @param int $limit  Rows.
	 * @param int $offset Offset.
	 * @return array[]
	 */
	public function log_rows( $limit = 50, $offset = 0 ) {
		$rows = $this->rows( 'log', '1 = %d ORDER BY id DESC LIMIT %d OFFSET %d', array( 1, max( 1, (int) $limit ), max( 0, (int) $offset ) ) );

		foreach ( $rows as $i => $row ) {
			foreach ( array( 'id', 'device_id', 'person_id', 'user_id' ) as $key ) {
				$rows[ $i ][ $key ] = (int) ( $row[ $key ] ?? 0 );
			}
		}

		return $rows;
	}

	/**
	 * How many log rows there are.
	 *
	 * @return int
	 */
	public function log_count() {
		global $wpdb;

		try {
			$n = $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::table( 'log' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
		} catch ( \Throwable $e ) {
			return 0;
		}

		return (int) $n;
	}

	/* ============================================================== */
	/*  Remembered saves                                               */
	/* ============================================================== */

	/**
	 * Claim a save id for this request. The unique key decides: of two
	 * requests carrying the same id exactly one gets 'claimed'.
	 *
	 * @param string $key             SHA-256 naming the save.
	 * @param int    $device_id       Device id.
	 * @param int    $now             Timestamp.
	 * @param int    $pending_seconds After this long an unfinished claim may be taken over.
	 * @return string|array|false 'claimed', or the earlier row (status, result, created_at), or false on a database error.
	 */
	public function claim_save( $key, $device_id, $now, $pending_seconds ) {
		global $wpdb;

		if ( 64 !== strlen( (string) $key ) ) {
			return false;
		}

		$table = self::table( 'saves' );
		$when  = gmdate( 'Y-m-d H:i:s', (int) $now );

		try {
			$n = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare(
					'INSERT IGNORE INTO ' . $table . ' ( save_key, device_id, status, created_at ) VALUES ( %s, %d, 0, %s )', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
					(string) $key,
					(int) $device_id,
					$when
				)
			);
		} catch ( \Throwable $e ) {
			return false;
		}

		if ( false === $n ) {
			return false;
		}
		if ( 1 === (int) $n ) {
			return 'claimed';
		}

		$rows = $this->rows( 'saves', 'save_key = %s LIMIT 1', array( (string) $key ) );
		if ( ! $rows ) {
			return false;
		}
		$row           = $rows[0];
		$row['status'] = (int) $row['status'];

		// An unfinished claim that is older than the limit belongs to a
		// request that died: take it over (guarded by its old time, so
		// only one request does).
		if ( 0 === $row['status'] && (int) $now - Lockout::ts( $row['created_at'] ) > (int) $pending_seconds ) {
			try {
				$took = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
					$wpdb->prepare(
						'UPDATE ' . $table . ' SET created_at = %s WHERE save_key = %s AND status = 0 AND created_at = %s', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
						$when,
						(string) $key,
						(string) $row['created_at']
					)
				);
			} catch ( \Throwable $e ) {
				return false;
			}
			if ( 1 === (int) $took ) {
				return 'claimed';
			}
		}

		return $row;
	}

	/**
	 * Record how a claimed save ended, with the short answer to repeat.
	 *
	 * @param string $key    SHA-256 naming the save.
	 * @param string $result JSON.
	 * @return bool
	 */
	public function finish_save( $key, $result ) {
		global $wpdb;

		try {
			$n = $wpdb->update( self::table( 'saves' ), array( 'status' => 1, 'result' => (string) $result ), array( 'save_key' => (string) $key ), array( '%d', '%s' ), array( '%s' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		} catch ( \Throwable $e ) {
			return false;
		}

		return false !== $n;
	}

	/**
	 * Give a claim back (the save did not go through, so sending it again
	 * must run it again).
	 *
	 * @param string $key SHA-256 naming the save.
	 * @return bool
	 */
	public function release_save( $key ) {
		global $wpdb;

		try {
			$n = $wpdb->delete( self::table( 'saves' ), array( 'save_key' => (string) $key, 'status' => 0 ), array( '%s', '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		} catch ( \Throwable $e ) {
			return false;
		}

		return false !== $n;
	}

	/**
	 * Forget the saves older than a moment.
	 *
	 * @param int $before Timestamp.
	 * @return int Rows removed.
	 */
	public function prune_saves( $before ) {
		global $wpdb;

		try {
			$n = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare(
					'DELETE FROM ' . self::table( 'saves' ) . ' WHERE created_at < %s', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
					gmdate( 'Y-m-d H:i:s', (int) $before )
				)
			);
		} catch ( \Throwable $e ) {
			return 0;
		}

		return (int) $n;
	}

	/* ============================================================== */
	/*  Helpers                                                        */
	/* ============================================================== */

	/**
	 * A value cut to a column's width — in CHARACTERS, as the columns
	 * count them (cutting bytes would split an accented letter in two,
	 * and the row would be refused). Always a string: before PHP 8
	 * substr() of an empty string is false.
	 *
	 * @param mixed $value Value.
	 * @param int   $max   Characters.
	 * @return string
	 */
	public static function cut( $value, $max ) {
		$value = (string) $value;
		if ( function_exists( 'mb_substr' ) ) {
			return (string) mb_substr( $value, 0, (int) $max, 'UTF-8' );
		}
		if ( 1 === preg_match( '/^.{0,' . (int) $max . '}/us', $value, $m ) ) {
			return (string) $m[0];
		}
		return (string) substr( $value, 0, (int) $max );
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
	 * A device row with typed values.
	 *
	 * @param array $row Raw row.
	 * @return array
	 */
	public static function hydrate_device( array $row ) {
		foreach ( array( 'id', 'enrolled_by', 'revoked_by', 'fail_count', 'lock_level', 'session_person_id' ) as $key ) {
			$row[ $key ] = isset( $row[ $key ] ) ? (int) $row[ $key ] : 0;
		}
		foreach ( array( 'name', 'status', 'token_hash', 'enrol_hash', 'session_id' ) as $key ) {
			$row[ $key ] = isset( $row[ $key ] ) ? (string) $row[ $key ] : '';
		}
		foreach ( array( 'enrol_expires', 'enrolled_at', 'created_at', 'last_used_at', 'revoked_at', 'fail_since', 'locked_until', 'session_started_at' ) as $key ) {
			$row[ $key ] = isset( $row[ $key ] ) && '' !== (string) $row[ $key ] ? (string) $row[ $key ] : null;
		}
		return $row;
	}

	/**
	 * A person row with typed values.
	 *
	 * @param array $row Raw row.
	 * @return array
	 */
	public static function hydrate_person( array $row ) {
		foreach ( array( 'id', 'pin_length', 'user_id', 'active', 'created_by' ) as $key ) {
			$row[ $key ] = isset( $row[ $key ] ) ? (int) $row[ $key ] : 0;
		}
		foreach ( array( 'name', 'pin_hash', 'pin_salt' ) as $key ) {
			$row[ $key ] = isset( $row[ $key ] ) ? (string) $row[ $key ] : '';
		}
		foreach ( array( 'created_at', 'updated_at', 'last_unlock_at' ) as $key ) {
			$row[ $key ] = isset( $row[ $key ] ) && '' !== (string) $row[ $key ] ? (string) $row[ $key ] : null;
		}
		return $row;
	}
}
