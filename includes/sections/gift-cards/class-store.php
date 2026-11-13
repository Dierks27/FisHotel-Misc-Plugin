<?php
/**
 * Gift Cards store — the ONLY file in the section that touches $wpdb.
 *
 * Three tables, created with dbDelta on the first boot in wp-admin and on
 * plugin activation, gated by the autoloaded fishotel_gift_cards_db_version
 * option (a booted site pays no query for it). Money is whole cents
 * (bigint) everywhere. InnoDB is required — a payment that touches two
 * cards is one transaction — and the install refuses (the version gate
 * stays shut) when a table is not InnoDB.
 *
 *   {prefix}fh_gift_cards        one row per card: the code (and its lookup
 *                                key, UNIQUE), active / disabled, the amount
 *                                it was issued for and its balance now (a
 *                                cache of its ledger), whose account it is in,
 *                                recipient / sender / message, when its e-mail
 *                                goes out and whether it went, the order line
 *                                it was bought on (order item + unit, UNIQUE:
 *                                a paid order issues each card once), where it
 *                                came from, the old plugin's card id (UNIQUE)
 *                                and expiry date (shown, never enforced)
 *   {prefix}fh_gift_card_ledger  APPEND-ONLY money movements: issued, added to
 *                                an account, used on an order, put back,
 *                                credited / debited by hand, imported. Every
 *                                row has a UNIQUE idempotency key, so the same
 *                                movement can never be written twice (a card
 *                                is put back for an order exactly once).
 *   {prefix}fh_gift_card_audit   APPEND-ONLY audit log: who, when, what field
 *                                old → new, the money moved, why, from where;
 *                                each row carries the hash of the one before
 *                                (see Audit), so an edited or deleted row
 *                                shows.
 *
 * No method of this class updates or deletes a ledger or an audit row, and
 * none deletes a card (a test reads this file to keep it so).
 *
 * The old plugin's tables are only ever read, between the LEGACY-READ-ONLY
 * markers below, through legacy_read(), which refuses anything that is
 * not SELECT or SHOW (a test reads that region too).
 *
 * Every method is wrapped: a database error means false / null / an empty
 * list, never an exception. The Service takes an instance as a parameter;
 * the tests hand in one that stands on an in-memory SQLite database.
 *
 * @package FisHotel\Misc\Sections\Gift_Cards
 */

namespace FisHotel\Misc\Sections\Gift_Cards;

defined( 'ABSPATH' ) || exit;

/**
 * Class Store
 */
class Store {

	const DB_VERSION        = '1.0';
	const DB_VERSION_OPTION = 'fishotel_gift_cards_db_version';
	const RETRY_SECONDS     = 3600;

	/**
	 * Table names without the prefix.
	 *
	 * @var string[]
	 */
	const TABLES = array( 'gift_cards', 'gift_card_ledger', 'gift_card_audit' );

	/**
	 * The card columns that change_card() may set. Never the balance (only
	 * move_balance() moves money), the owner (only claim_owner()), the code
	 * or the amount it was issued for.
	 *
	 * @var string[]
	 */
	const DETAIL_COLUMNS = array( 'status', 'recipient_name', 'recipient_email', 'sender_name', 'sender_email', 'message', 'deliver_at', 'email_state', 'delivered_at', 'legacy_expires_at' );

	/**
	 * The list's sort keys and their columns.
	 *
	 * @var array<string, string>
	 */
	const SORTS = array(
		'balance'   => 'balance_cents',
		'issued'    => 'created_at',
		'last_used' => 'last_used_at',
		'amount'    => 'amount_cents',
	);

	/**
	 * The list's filters.
	 *
	 * @var string[]
	 */
	const FILTERS = array( 'in_use', 'used_up', 'disabled', 'expiry', 'all' );

	const PAGE_MAX  = 500;
	const CHUNK_MAX = 1000;

	/**
	 * Whether every table exists (memoised per request, install only).
	 *
	 * @var bool|null
	 */
	private static $tables_exist = null;

	/**
	 * The database (the global $wpdb, or a test's).
	 *
	 * @var object
	 */
	private $db;

	/**
	 * Use $db, or the global $wpdb.
	 *
	 * @param object|null $db A wpdb.
	 */
	public function __construct( $db = null ) {
		$this->db = null !== $db ? $db : ( isset( $GLOBALS['wpdb'] ) ? $GLOBALS['wpdb'] : null );
	}

	/**
	 * Fully prefixed table name.
	 *
	 * @param string $name A TABLES entry.
	 * @return string
	 */
	public function table( $name ) {
		return ( is_object( $this->db ) && isset( $this->db->prefix ) ? $this->db->prefix : 'wp_' ) . 'fh_' . $name;
	}

	/* ============================================================== */
	/*  Install                                                        */
	/* ============================================================== */

	/**
	 * Whether the installed schema is the current one (an autoloaded
	 * option — no query).
	 *
	 * @return bool
	 */
	public static function installed() {
		return self::DB_VERSION === get_option( self::DB_VERSION_OPTION );
	}

	/**
	 * Whether the store may be used (the version gate is open).
	 *
	 * @return bool
	 */
	public static function ready() {
		return self::installed();
	}

	/**
	 * Section boot: install when the version option says so; a failed
	 * install is retried only in wp-admin, at most once an hour.
	 *
	 * @param bool $is_admin is_admin().
	 * @return bool Whether install() ran.
	 */
	public static function maybe_install( $is_admin ) {
		$stored = get_option( self::DB_VERSION_OPTION );
		if ( self::DB_VERSION === $stored || ! $is_admin ) {
			return false;
		}
		if ( is_string( $stored ) && preg_match( '/^failed:[^:]*:(\d+)$/', $stored, $m ) && time() - (int) $m[1] < self::RETRY_SECONDS ) {
			return false;
		}
		( new self() )->install( true );
		return true;
	}

	/**
	 * Create / upgrade the tables, then record the version once every
	 * table is really there and InnoDB.
	 *
	 * @param bool $force Skip the version gate.
	 * @return bool
	 */
	public function install( $force = false ) {
		if ( ! $force && self::installed() ) {
			return true;
		}

		try {
			if ( ! function_exists( 'dbDelta' ) ) {
				require_once ABSPATH . 'wp-admin/includes/upgrade.php';
			}
			foreach ( $this->schema( $this->db->get_charset_collate() ) as $statement ) {
				dbDelta( $statement );
			}
			self::$tables_exist = null;
			$ok                 = $this->tables_exist() && $this->all_innodb();
		} catch ( \Throwable $e ) {
			$ok = false;
		}

		update_option( self::DB_VERSION_OPTION, $ok ? self::DB_VERSION : 'failed:' . self::DB_VERSION . ':' . time(), true );

		return $ok;
	}

	/**
	 * The CREATE TABLE statements (dbDelta's whitespace rules: a column per
	 * line, two spaces after PRIMARY KEY, KEY with a name, types as MySQL
	 * reports them).
	 *
	 * @param string $charset_collate Charset / collation clause.
	 * @return string[]
	 */
	public function schema( $charset_collate = '' ) {
		$cards  = $this->table( 'gift_cards' );
		$ledger = $this->table( 'gift_card_ledger' );
		$audit  = $this->table( 'gift_card_audit' );

		return array(
			"CREATE TABLE {$cards} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				code varchar(64) NOT NULL,
				code_key varchar(64) NOT NULL,
				status varchar(10) NOT NULL DEFAULT 'active',
				amount_cents bigint(20) NOT NULL DEFAULT 0,
				balance_cents bigint(20) NOT NULL DEFAULT 0,
				owner_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				redeemed_at datetime NULL,
				recipient_name varchar(120) NOT NULL DEFAULT '',
				recipient_email varchar(190) NOT NULL DEFAULT '',
				sender_name varchar(120) NOT NULL DEFAULT '',
				sender_email varchar(190) NOT NULL DEFAULT '',
				message text NULL,
				deliver_at datetime NULL,
				email_state varchar(10) NOT NULL DEFAULT 'none',
				delivered_at datetime NULL,
				order_id bigint(20) unsigned NULL,
				order_item_id bigint(20) unsigned NULL,
				order_item_unit int(11) NULL,
				product_id bigint(20) unsigned NULL,
				buyer_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				source varchar(10) NOT NULL DEFAULT 'admin',
				legacy_id bigint(20) unsigned NULL,
				legacy_expires_at datetime NULL,
				created_by bigint(20) unsigned NOT NULL DEFAULT 0,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				last_used_at datetime NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY code_key (code_key),
				UNIQUE KEY order_unit (order_item_id,order_item_unit),
				UNIQUE KEY legacy_id (legacy_id),
				KEY status (status),
				KEY owner_user_id (owner_user_id),
				KEY recipient_email (recipient_email),
				KEY order_id (order_id),
				KEY email_state (email_state)
			) ENGINE=InnoDB {$charset_collate};",
			"CREATE TABLE {$ledger} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				card_id bigint(20) unsigned NOT NULL,
				type varchar(20) NOT NULL,
				amount_cents bigint(20) NOT NULL DEFAULT 0,
				balance_after_cents bigint(20) NULL,
				order_id bigint(20) unsigned NULL,
				refund_id bigint(20) unsigned NULL,
				user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				idempotency_key varchar(120) NOT NULL,
				legacy_activity_id bigint(20) unsigned NULL,
				note varchar(255) NOT NULL DEFAULT '',
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY idempotency_key (idempotency_key),
				UNIQUE KEY legacy_activity_id (legacy_activity_id),
				KEY card_id (card_id),
				KEY order_id (order_id),
				KEY type (type)
			) ENGINE=InnoDB {$charset_collate};",
			"CREATE TABLE {$audit} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				card_id bigint(20) unsigned NOT NULL DEFAULT 0,
				actor_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				actor_name varchar(120) NOT NULL DEFAULT '',
				action varchar(30) NOT NULL,
				field varchar(40) NOT NULL DEFAULT '',
				old_value text NULL,
				new_value text NULL,
				delta_cents bigint(20) NULL,
				reason text NULL,
				source varchar(10) NOT NULL DEFAULT '',
				screen varchar(80) NOT NULL DEFAULT '',
				ip varchar(100) NOT NULL DEFAULT '',
				created_at_utc datetime NOT NULL,
				site_time varchar(40) NOT NULL DEFAULT '',
				key_fp varchar(8) NOT NULL DEFAULT '',
				prev_hash varchar(64) NOT NULL DEFAULT '',
				row_hash varchar(64) NOT NULL,
				PRIMARY KEY  (id),
				KEY card_id (card_id),
				KEY action (action),
				KEY created_at_utc (created_at_utc)
			) ENGINE=InnoDB {$charset_collate};",
		);
	}

	/**
	 * Whether every table exists (memoised; install and the Health check only).
	 *
	 * @return bool
	 */
	public function tables_exist() {
		if ( null !== self::$tables_exist ) {
			return self::$tables_exist;
		}
		try {
			$ok = true;
			foreach ( self::TABLES as $name ) {
				$table = $this->table( $name );
				if ( $this->db->get_var( $this->db->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
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
	 * Whether every table is InnoDB (transactions and row locks).
	 *
	 * @return bool
	 */
	private function all_innodb() {
		foreach ( self::TABLES as $name ) {
			$engine = $this->db->get_var( $this->db->prepare( 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s', $this->table( $name ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			if ( 'innodb' !== strtolower( (string) $engine ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Forget the memoised table check (tests, after an install).
	 */
	public static function forget() {
		self::$tables_exist = null;
	}

	/* ============================================================== */
	/*  Transactions and the lock                                      */
	/* ============================================================== */

	/**
	 * Start a transaction.
	 *
	 * @return bool
	 */
	public function begin() {
		return $this->run( 'START TRANSACTION' );
	}

	/**
	 * Commit.
	 *
	 * @return bool
	 */
	public function commit() {
		return $this->run( 'COMMIT' );
	}

	/**
	 * Roll back.
	 *
	 * @return bool
	 */
	public function rollback() {
		return $this->run( 'ROLLBACK' );
	}

	/**
	 * Take a named database lock (one per site), waiting up to $timeout
	 * seconds. Every gift card write holds it, so two at once — two tabs,
	 * two checkouts, a cron run and a click — take their turns.
	 *
	 * @param string $name    Lock name.
	 * @param int    $timeout Seconds.
	 * @return bool
	 */
	public function lock( $name, $timeout = 10 ) {
		try {
			return '1' === (string) $this->db->get_var( $this->db->prepare( 'SELECT GET_LOCK(%s, %d)', $this->lock_name( $name ), (int) $timeout ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	/**
	 * Give a lock back.
	 *
	 * @param string $name Lock name.
	 */
	public function unlock( $name ) {
		try {
			$this->db->get_var( $this->db->prepare( 'SELECT RELEASE_LOCK(%s)', $this->lock_name( $name ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		} catch ( \Throwable $e ) {
			unset( $e );
		}
	}

	/**
	 * A lock name unique to this site.
	 *
	 * @param string $name Lock name.
	 * @return string
	 */
	private function lock_name( $name ) {
		return 'fh_gc_' . substr( md5( ( defined( 'DB_NAME' ) ? DB_NAME : '' ) . '|' . $this->table( '' ) . '|' . $name ), 0, 40 );
	}

	/**
	 * Run a statement with no answer.
	 *
	 * @param string $sql SQL.
	 * @return bool
	 */
	private function run( $sql ) {
		try {
			return false !== $this->db->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	/**
	 * Insert a row with database errors kept quiet (a duplicate key is an
	 * expected answer here, not something for the error log).
	 *
	 * @param string $table Table name without the prefix.
	 * @param array  $row   Columns.
	 * @return int|false The new id.
	 */
	private function insert( $table, array $row ) {
		if ( ! self::ready() || ! is_object( $this->db ) ) {
			return false;
		}
		$formats = array();
		foreach ( $row as $value ) {
			$formats[] = is_int( $value ) ? '%d' : '%s';
		}
		$quiet = method_exists( $this->db, 'suppress_errors' ) ? $this->db->suppress_errors( true ) : null;
		try {
			$ok = $this->db->insert( $this->table( $table ), $row, $formats ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$id = $ok ? (int) $this->db->insert_id : 0;
		} catch ( \Throwable $e ) {
			$id = 0;
		}
		if ( null !== $quiet ) {
			$this->db->suppress_errors( $quiet );
		}
		return $id > 0 ? $id : false;
	}

	/**
	 * Rows of a SELECT, as arrays.
	 *
	 * @param string $sql    SQL with placeholders.
	 * @param array  $params Values.
	 * @return array[]
	 */
	private function rows( $sql, array $params = array() ) {
		if ( ! self::ready() || ! is_object( $this->db ) ) {
			return array();
		}
		try {
			$rows = $this->db->get_results( $params ? $this->db->prepare( $sql, $params ) : $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
		} catch ( \Throwable $e ) {
			return array();
		}
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * One row, or null.
	 *
	 * @param string $sql    SQL.
	 * @param array  $params Values.
	 * @return array|null
	 */
	private function row( $sql, array $params = array() ) {
		$rows = $this->rows( $sql, $params );
		return $rows ? $rows[0] : null;
	}

	/**
	 * One value, or null.
	 *
	 * @param string $sql    SQL.
	 * @param array  $params Values.
	 * @return string|null
	 */
	private function value( $sql, array $params = array() ) {
		if ( ! self::ready() || ! is_object( $this->db ) ) {
			return null;
		}
		try {
			$v = $this->db->get_var( $params ? $this->db->prepare( $sql, $params ) : $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
		} catch ( \Throwable $e ) {
			return null;
		}
		return null === $v ? null : (string) $v;
	}

	/**
	 * Run a prepared UPDATE; the number of rows it changed (0 on error).
	 *
	 * @param string $sql    SQL.
	 * @param array  $params Values.
	 * @return int
	 */
	private function change( $sql, array $params ) {
		if ( ! self::ready() || ! is_object( $this->db ) ) {
			return 0;
		}
		try {
			$n = $this->db->query( $this->db->prepare( $sql, $params ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
		} catch ( \Throwable $e ) {
			return 0;
		}
		return is_int( $n ) ? $n : 0;
	}

	/* ============================================================== */
	/*  Cards                                                          */
	/* ============================================================== */

	/**
	 * Insert a card.
	 *
	 * @param array $row Columns.
	 * @return int|false
	 */
	public function insert_card( array $row ) {
		return $this->insert( 'gift_cards', $row );
	}

	/**
	 * A card by id.
	 *
	 * @param int $id Card id.
	 * @return array|null
	 */
	public function card( $id ) {
		return $this->row( 'SELECT * FROM ' . $this->table( 'gift_cards' ) . ' WHERE id = %d', array( (int) $id ) );
	}

	/**
	 * A card by its lookup key.
	 *
	 * @param string $key Codes::key().
	 * @return array|null
	 */
	public function card_by_key( $key ) {
		return '' === (string) $key ? null : $this->row( 'SELECT * FROM ' . $this->table( 'gift_cards' ) . ' WHERE code_key = %s', array( (string) $key ) );
	}

	/**
	 * A card by the old plugin's id.
	 *
	 * @param int $legacy_id Old card id.
	 * @return array|null
	 */
	public function card_by_legacy( $legacy_id ) {
		return $this->row( 'SELECT * FROM ' . $this->table( 'gift_cards' ) . ' WHERE legacy_id = %d', array( (int) $legacy_id ) );
	}

	/**
	 * The card bought as unit $unit of an order line.
	 *
	 * @param int $item_id Order item id.
	 * @param int $unit    1-based unit.
	 * @return array|null
	 */
	public function card_for_item( $item_id, $unit ) {
		return $this->row( 'SELECT * FROM ' . $this->table( 'gift_cards' ) . ' WHERE order_item_id = %d AND order_item_unit = %d', array( (int) $item_id, (int) $unit ) );
	}

	/**
	 * The cards bought on an order.
	 *
	 * @param int $order_id Order id.
	 * @return array[]
	 */
	public function cards_of_order( $order_id ) {
		return $this->rows( 'SELECT * FROM ' . $this->table( 'gift_cards' ) . ' WHERE order_id = %d AND order_item_id IS NOT NULL ORDER BY id ASC', array( (int) $order_id ) );
	}

	/**
	 * The cards in a customer's account, the oldest added first.
	 *
	 * @param int $user_id User id.
	 * @return array[]
	 */
	public function cards_of_owner( $user_id ) {
		if ( (int) $user_id <= 0 ) {
			return array();
		}
		return $this->rows( 'SELECT * FROM ' . $this->table( 'gift_cards' ) . ' WHERE owner_user_id = %d ORDER BY redeemed_at ASC, id ASC LIMIT 500', array( (int) $user_id ) );
	}

	/**
	 * Cards not in anyone's account, sent to this e-mail address.
	 *
	 * @param string $email E-mail address.
	 * @return array[]
	 */
	public function unclaimed_for_email( $email ) {
		$email = strtolower( trim( (string) $email ) );
		if ( '' === $email ) {
			return array();
		}
		return $this->rows( 'SELECT * FROM ' . $this->table( 'gift_cards' ) . " WHERE owner_user_id = 0 AND status = 'active' AND balance_cents > 0 AND LOWER(recipient_email) = %s ORDER BY id ASC LIMIT 100", array( $email ) );
	}

	/**
	 * Cards by id.
	 *
	 * @param int[] $ids Card ids.
	 * @return array<int, array>
	 */
	public function cards_by_ids( array $ids ) {
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
		if ( ! $ids ) {
			return array();
		}
		$out = array();
		foreach ( $this->rows( 'SELECT * FROM ' . $this->table( 'gift_cards' ) . ' WHERE id IN (' . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ')', $ids ) as $row ) {
			$out[ (int) $row['id'] ] = $row;
		}
		return $out;
	}

	/**
	 * The WHERE clause of a list (pure apart from the table name).
	 *
	 * @param array $spec filter (FILTERS), q (search), order_ids (orders whose number matched).
	 * @return array{0: string, 1: array}
	 */
	public function where( array $spec ) {
		$where  = array( '1=1' );
		$params = array();

		switch ( isset( $spec['filter'] ) ? (string) $spec['filter'] : 'in_use' ) {
			case 'used_up':
				$where[] = "balance_cents <= 0 AND status = 'active'";
				break;
			case 'disabled':
				$where[] = "status = 'disabled'";
				break;
			case 'expiry':
				$where[] = 'legacy_expires_at IS NOT NULL';
				break;
			case 'all':
				break;
			default:
				$where[] = "balance_cents > 0 AND status = 'active'";
		}

		$q = isset( $spec['q'] ) ? trim( (string) $spec['q'] ) : '';
		if ( '' !== $q ) {
			$like  = '%' . $this->escape_like( strtolower( $q ) ) . '%';
			$or    = array( 'LOWER(recipient_name) LIKE %s', 'LOWER(recipient_email) LIKE %s', 'LOWER(sender_name) LIKE %s', 'LOWER(sender_email) LIKE %s' );
			$extra = array( $like, $like, $like, $like );
			$key   = strtoupper( preg_replace( '/[^A-Za-z0-9]/', '', $q ) );
			if ( strlen( $key ) >= 4 ) {
				// The whole code, or its last characters (what staff can see).
				$or[]    = 'code_key = %s';
				$extra[] = $key;
				$or[]    = 'code_key LIKE %s';
				$extra[] = '%' . $this->escape_like( $key );
			}
			if ( preg_match( '/^#?(\d{1,12})$/', $q, $m ) ) {
				$or[]    = 'order_id = %d';
				$extra[] = (int) $m[1];
				$or[]    = 'legacy_id = %d';
				$extra[] = (int) $m[1];
			}
			$users = isset( $spec['user_ids'] ) ? array_values( array_filter( array_map( 'intval', (array) $spec['user_ids'] ) ) ) : array();
			if ( $users ) {
				$or[]  = 'owner_user_id IN (' . implode( ',', array_fill( 0, count( $users ), '%d' ) ) . ')';
				$extra = array_merge( $extra, $users );
				$or[]  = 'buyer_user_id IN (' . implode( ',', array_fill( 0, count( $users ), '%d' ) ) . ')';
				$extra = array_merge( $extra, $users );
			}
			$where[] = '(' . implode( ' OR ', $or ) . ')';
			$params  = array_merge( $params, $extra );
		}

		return array( implode( ' AND ', $where ), $params );
	}

	/**
	 * Escape % and _ for LIKE.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private function escape_like( $text ) {
		return is_object( $this->db ) && method_exists( $this->db, 'esc_like' ) ? $this->db->esc_like( $text ) : addcslashes( $text, '_%\\' );
	}

	/**
	 * A page of the card list.
	 *
	 * @param array $spec where() keys + sort (SORTS), dir (asc|desc), limit, offset.
	 * @return array[]
	 */
	public function find( array $spec ) {
		list( $where, $params ) = $this->where( $spec );

		$sort   = isset( $spec['sort'], self::SORTS[ $spec['sort'] ] ) ? self::SORTS[ $spec['sort'] ] : 'created_at';
		$dir    = isset( $spec['dir'] ) && 'asc' === $spec['dir'] ? 'ASC' : 'DESC';
		$limit  = max( 1, min( self::PAGE_MAX, (int) ( $spec['limit'] ?? 50 ) ) );
		$offset = max( 0, (int) ( $spec['offset'] ?? 0 ) );
		// Never-used cards (no last-used date) sort as the oldest.
		$order = 'last_used_at' === $sort ? "COALESCE(last_used_at, '1000-01-01 00:00:00') {$dir}" : "{$sort} {$dir}";

		$params[] = $limit;
		$params[] = $offset;

		return $this->rows( 'SELECT * FROM ' . $this->table( 'gift_cards' ) . " WHERE {$where} ORDER BY {$order}, id {$dir} LIMIT %d OFFSET %d", $params );
	}

	/**
	 * How many cards a list has.
	 *
	 * @param array $spec where() keys.
	 * @return int
	 */
	public function count( array $spec ) {
		list( $where, $params ) = $this->where( $spec );
		return (int) $this->value( 'SELECT COUNT(*) FROM ' . $this->table( 'gift_cards' ) . " WHERE {$where}", $params );
	}

	/**
	 * Whether any card carries an expiry date from the old plugin.
	 *
	 * @return bool
	 */
	public function has_legacy_expiry() {
		return null !== $this->value( 'SELECT id FROM ' . $this->table( 'gift_cards' ) . ' WHERE legacy_expires_at IS NOT NULL LIMIT 1' );
	}

	/**
	 * Change a card's detail columns (DETAIL_COLUMNS only). With $if, only
	 * while column => value still holds (claiming an e-mail to send).
	 *
	 * @param int   $id     Card id.
	 * @param array $fields Column => value (null = NULL).
	 * @param array $if     Column => value that must still hold.
	 * @param int   $now    UNIX time.
	 * @return bool Whether a row changed (or, without $if, the statement ran).
	 */
	public function change_card( $id, array $fields, array $if = array(), $now = 0 ) {
		$fields = array_intersect_key( $fields, array_flip( self::DETAIL_COLUMNS ) );
		if ( ! $fields || (int) $id <= 0 ) {
			return false;
		}
		$set    = array();
		$params = array();
		foreach ( $fields as $column => $value ) {
			if ( null === $value ) {
				$set[] = "`{$column}` = NULL";
				continue;
			}
			$set[]    = "`{$column}` = %s";
			$params[] = (string) $value;
		}
		$set[]    = '`updated_at` = %s';
		$params[] = gmdate( 'Y-m-d H:i:s', $now ? (int) $now : time() );
		$where    = 'id = %d';
		$params[] = (int) $id;
		foreach ( array_intersect_key( $if, array_flip( self::DETAIL_COLUMNS ) ) as $column => $value ) {
			$where   .= " AND `{$column}` = %s";
			$params[] = (string) $value;
		}
		$n = $this->change( 'UPDATE ' . $this->table( 'gift_cards' ) . ' SET ' . implode( ', ', $set ) . ' WHERE ' . $where, $params );
		return $if ? 1 === $n : $n >= 0;
	}

	/**
	 * Put a card into an account — once: only while it is in nobody's.
	 *
	 * @param int $id      Card id.
	 * @param int $user_id User id.
	 * @param int $now     UNIX time.
	 * @return bool Whether this call put it there.
	 */
	public function claim_owner( $id, $user_id, $now ) {
		$at = gmdate( 'Y-m-d H:i:s', (int) $now );
		return 1 === $this->change( 'UPDATE ' . $this->table( 'gift_cards' ) . " SET owner_user_id = %d, redeemed_at = %s, updated_at = %s WHERE id = %d AND owner_user_id = 0 AND status = 'active'", array( (int) $user_id, $at, $at, (int) $id ) );
	}

	/**
	 * Move money on a card in ONE statement that cannot overdraw it: the
	 * row changes only while the balance after stays at or above zero
	 * (and, when asked, only while the card is active). Two payments at
	 * the same moment cannot both take the same dollars — the second
	 * finds the balance already lower and changes nothing.
	 *
	 * @param int  $id            Card id.
	 * @param int  $delta         Cents (negative = take).
	 * @param int  $now           UNIX time.
	 * @param bool $active_only   Refuse a disabled card.
	 * @param bool $mark_used     Set last_used_at.
	 * @return bool Whether the money moved.
	 */
	public function move_balance( $id, $delta, $now, $active_only = false, $mark_used = false ) {
		$at     = gmdate( 'Y-m-d H:i:s', (int) $now );
		$sql    = 'UPDATE ' . $this->table( 'gift_cards' ) . ' SET balance_cents = balance_cents + %d, updated_at = %s' . ( $mark_used ? ', last_used_at = %s' : '' ) . ' WHERE id = %d AND balance_cents + %d >= 0' . ( $active_only ? " AND status = 'active'" : '' );
		$params = $mark_used ? array( (int) $delta, $at, $at, (int) $id, (int) $delta ) : array( (int) $delta, $at, (int) $id, (int) $delta );
		return 1 === $this->change( $sql, $params );
	}

	/**
	 * A card's balance as the database holds it now.
	 *
	 * @param int $id Card id.
	 * @return int|null
	 */
	public function balance( $id ) {
		$v = $this->value( 'SELECT balance_cents FROM ' . $this->table( 'gift_cards' ) . ' WHERE id = %d', array( (int) $id ) );
		return null === $v ? null : (int) $v;
	}

	/**
	 * Cards whose e-mail is due: waiting and its day has come, or stuck
	 * "sending" for over an hour (a run that died half way).
	 *
	 * @param int $now   UNIX time.
	 * @param int $limit Most.
	 * @return array[]
	 */
	public function due_deliveries( $now, $limit = 50 ) {
		$at    = gmdate( 'Y-m-d H:i:s', (int) $now );
		$stale = gmdate( 'Y-m-d H:i:s', (int) $now - 3600 );
		return $this->rows( 'SELECT * FROM ' . $this->table( 'gift_cards' ) . " WHERE status = 'active' AND ( ( email_state = 'pending' AND ( deliver_at IS NULL OR deliver_at <= %s ) ) OR ( email_state = 'sending' AND updated_at <= %s ) ) ORDER BY id ASC LIMIT %d", array( $at, $stale, max( 1, (int) $limit ) ) );
	}

	/* ============================================================== */
	/*  Ledger (append-only)                                           */
	/* ============================================================== */

	/**
	 * Append a money movement. False when its idempotency key (or old
	 * activity id) is already there — the movement was written before.
	 *
	 * @param array $row Columns.
	 * @return int|false
	 */
	public function insert_ledger( array $row ) {
		return $this->insert( 'gift_card_ledger', $row );
	}

	/**
	 * Whether a movement with this key exists.
	 *
	 * @param string $key Idempotency key.
	 * @return bool
	 */
	public function ledger_has( $key ) {
		return null !== $this->value( 'SELECT id FROM ' . $this->table( 'gift_card_ledger' ) . ' WHERE idempotency_key = %s', array( (string) $key ) );
	}

	/**
	 * Whether an old activity row was imported.
	 *
	 * @param int $legacy_activity_id Old activity id.
	 * @return bool
	 */
	public function ledger_has_legacy( $legacy_activity_id ) {
		return null !== $this->value( 'SELECT id FROM ' . $this->table( 'gift_card_ledger' ) . ' WHERE legacy_activity_id = %d', array( (int) $legacy_activity_id ) );
	}

	/**
	 * A card's movements, the newest first.
	 *
	 * @param int $card_id Card id.
	 * @param int $limit   Most.
	 * @return array[]
	 */
	public function ledger_of_card( $card_id, $limit = 200 ) {
		return $this->rows( 'SELECT * FROM ' . $this->table( 'gift_card_ledger' ) . ' WHERE card_id = %d ORDER BY id DESC LIMIT %d', array( (int) $card_id, max( 1, (int) $limit ) ) );
	}

	/**
	 * An order's movements, oldest first.
	 *
	 * @param int $order_id Order id.
	 * @return array[]
	 */
	public function ledger_of_order( $order_id ) {
		return (int) $order_id > 0 ? $this->rows( 'SELECT * FROM ' . $this->table( 'gift_card_ledger' ) . ' WHERE order_id = %d ORDER BY id ASC', array( (int) $order_id ) ) : array();
	}

	/**
	 * The movements of several cards (a customer's activity), newest first.
	 *
	 * @param int[] $card_ids Card ids.
	 * @param int   $limit    Most.
	 * @param int   $offset   Skip.
	 * @return array[]
	 */
	public function ledger_of_cards( array $card_ids, $limit = 20, $offset = 0 ) {
		$ids = array_values( array_filter( array_map( 'intval', $card_ids ) ) );
		if ( ! $ids ) {
			return array();
		}
		$params   = $ids;
		$params[] = max( 1, (int) $limit );
		$params[] = max( 0, (int) $offset );
		return $this->rows( 'SELECT * FROM ' . $this->table( 'gift_card_ledger' ) . ' WHERE card_id IN (' . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ") AND type <> 'import_adjust' ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d", $params );
	}

	/**
	 * How many movements those cards have (paging).
	 *
	 * @param int[] $card_ids Card ids.
	 * @return int
	 */
	public function count_ledger_of_cards( array $card_ids ) {
		$ids = array_values( array_filter( array_map( 'intval', $card_ids ) ) );
		return $ids ? (int) $this->value( 'SELECT COUNT(*) FROM ' . $this->table( 'gift_card_ledger' ) . ' WHERE card_id IN (' . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ") AND type <> 'import_adjust'", $ids ) : 0;
	}

	/**
	 * The sum of a card's movements (what its balance must be).
	 *
	 * @param int $card_id Card id.
	 * @return int
	 */
	public function ledger_sum( $card_id ) {
		return (int) $this->value( 'SELECT COALESCE(SUM(amount_cents), 0) FROM ' . $this->table( 'gift_card_ledger' ) . ' WHERE card_id = %d', array( (int) $card_id ) );
	}

	/**
	 * How many movements of a card were written by this system (not by
	 * the importer).
	 *
	 * @param int $card_id Card id.
	 * @return int
	 */
	public function own_movements( $card_id ) {
		return (int) $this->value( 'SELECT COUNT(*) FROM ' . $this->table( 'gift_card_ledger' ) . " WHERE card_id = %d AND legacy_activity_id IS NULL AND type NOT IN ('import_adjust', 'issued')", array( (int) $card_id ) );
	}

	/**
	 * Cards whose stored balance is not the sum of their movements (the
	 * health check: it must always be empty).
	 *
	 * @param int $limit Most.
	 * @return array[] id, balance_cents, ledger_cents.
	 */
	public function mismatches( $limit = 50 ) {
		return $this->rows( 'SELECT c.id, c.balance_cents, COALESCE(SUM(l.amount_cents), 0) AS ledger_cents FROM ' . $this->table( 'gift_cards' ) . ' c LEFT JOIN ' . $this->table( 'gift_card_ledger' ) . ' l ON l.card_id = c.id GROUP BY c.id, c.balance_cents HAVING COALESCE(SUM(l.amount_cents), 0) <> c.balance_cents ORDER BY c.id ASC LIMIT %d', array( max( 1, (int) $limit ) ) );
	}

	/* ============================================================== */
	/*  Audit (append-only)                                            */
	/* ============================================================== */

	/**
	 * The newest audit row (id, row_hash), or null.
	 *
	 * @return array|null
	 */
	public function audit_head() {
		return $this->row( 'SELECT id, row_hash FROM ' . $this->table( 'gift_card_audit' ) . ' ORDER BY id DESC LIMIT 1' );
	}

	/**
	 * Append an audit row.
	 *
	 * @param array $row Columns (Audit::append() fills the hashes).
	 * @return int|false
	 */
	public function insert_audit( array $row ) {
		return $this->insert( 'gift_card_audit', $row );
	}

	/**
	 * The WHERE clause of an audit list.
	 *
	 * @param array $spec card_id, action, source, from, to (UTC Y-m-d H:i:s).
	 * @return array{0: string, 1: array}
	 */
	private function audit_where( array $spec ) {
		$where  = array( '1=1' );
		$params = array();
		if ( ! empty( $spec['card_id'] ) ) {
			$where[]  = 'card_id = %d';
			$params[] = (int) $spec['card_id'];
		}
		foreach ( array( 'action', 'source' ) as $column ) {
			if ( ! empty( $spec[ $column ] ) ) {
				$where[]  = "{$column} = %s";
				$params[] = (string) $spec[ $column ];
			}
		}
		if ( ! empty( $spec['from'] ) ) {
			$where[]  = 'created_at_utc >= %s';
			$params[] = (string) $spec['from'];
		}
		if ( ! empty( $spec['to'] ) ) {
			$where[]  = 'created_at_utc < %s';
			$params[] = (string) $spec['to'];
		}
		return array( implode( ' AND ', $where ), $params );
	}

	/**
	 * A page of the audit log, the newest first.
	 *
	 * @param array $spec   audit_where() keys.
	 * @param int   $limit  Most.
	 * @param int   $offset Skip.
	 * @return array[]
	 */
	public function audit_rows( array $spec, $limit = 100, $offset = 0 ) {
		list( $where, $params ) = $this->audit_where( $spec );
		$params[]               = max( 1, min( self::PAGE_MAX, (int) $limit ) );
		$params[]               = max( 0, (int) $offset );
		return $this->rows( 'SELECT * FROM ' . $this->table( 'gift_card_audit' ) . " WHERE {$where} ORDER BY id DESC LIMIT %d OFFSET %d", $params );
	}

	/**
	 * How many audit rows match.
	 *
	 * @param array $spec audit_where() keys.
	 * @return int
	 */
	public function audit_count( array $spec ) {
		list( $where, $params ) = $this->audit_where( $spec );
		return (int) $this->value( 'SELECT COUNT(*) FROM ' . $this->table( 'gift_card_audit' ) . " WHERE {$where}", $params );
	}

	/**
	 * Audit rows after an id, oldest first (the chain check and the export
	 * walk the whole log in chunks).
	 *
	 * @param int   $after_id The last id already read.
	 * @param int   $limit    Most.
	 * @param array $spec     audit_where() keys (the export's filters).
	 * @return array[]
	 */
	public function audit_after( $after_id, $limit = 500, array $spec = array() ) {
		list( $where, $params ) = $this->audit_where( $spec );
		array_unshift( $params, (int) $after_id );
		$params[] = max( 1, min( self::CHUNK_MAX, (int) $limit ) );
		return $this->rows( 'SELECT * FROM ' . $this->table( 'gift_card_audit' ) . " WHERE id > %d AND {$where} ORDER BY id ASC LIMIT %d", $params );
	}

	/* ============================================================== */
	/*  Stats                                                          */
	/* ============================================================== */

	/**
	 * The numbers of the Stats tab. Times are UTC 'Y-m-d H:i:s'.
	 *
	 * @param string $month_start Start of this month (site time, as UTC).
	 * @param string $year_start  Start of this year.
	 * @param string $year_ago    One year ago.
	 * @return array<string, int>
	 */
	public function stats( $month_start, $year_start, $year_ago ) {
		$cards  = $this->table( 'gift_cards' );
		$ledger = $this->table( 'gift_card_ledger' );
		$out    = array();

		$row = $this->row(
			"SELECT
				COUNT(*) AS cards,
				COALESCE(SUM(CASE WHEN status = 'active' THEN balance_cents ELSE 0 END), 0) AS outstanding_active,
				COALESCE(SUM(CASE WHEN status = 'disabled' THEN balance_cents ELSE 0 END), 0) AS outstanding_disabled,
				COALESCE(SUM(CASE WHEN status = 'active' AND balance_cents > 0 THEN 1 ELSE 0 END), 0) AS in_use,
				COALESCE(AVG(amount_cents), 0) AS average_amount,
				COALESCE(SUM(CASE WHEN balance_cents > 0 AND COALESCE(last_used_at, created_at) < %s THEN 1 ELSE 0 END), 0) AS idle_year,
				COALESCE(SUM(CASE WHEN balance_cents > 0 AND COALESCE(last_used_at, created_at) < %s THEN balance_cents ELSE 0 END), 0) AS idle_year_cents
			FROM {$cards}",
			array( $year_ago, $year_ago )
		);
		foreach ( (array) $row as $k => $v ) {
			$out[ $k ] = (int) round( (float) $v );
		}

		$row = $this->row(
			"SELECT COUNT(*) AS never_used, COALESCE(SUM(c.balance_cents), 0) AS never_used_cents FROM {$cards} c
			WHERE c.balance_cents > 0 AND NOT EXISTS ( SELECT 1 FROM {$ledger} l WHERE l.card_id = c.id AND l.type IN ('used', 'debit') )"
		);
		foreach ( (array) $row as $k => $v ) {
			$out[ $k ] = (int) $v;
		}

		foreach ( array(
			'month' => $month_start,
			'year'  => $year_start,
		) as $span => $since ) {
			$row = $this->row(
				"SELECT
					COALESCE(SUM(CASE WHEN source = 'order' THEN amount_cents ELSE 0 END), 0) AS sold_cents,
					COALESCE(SUM(CASE WHEN source = 'order' THEN 1 ELSE 0 END), 0) AS sold,
					COALESCE(SUM(CASE WHEN source = 'admin' THEN amount_cents ELSE 0 END), 0) AS issued_cents,
					COALESCE(SUM(CASE WHEN source = 'admin' THEN 1 ELSE 0 END), 0) AS issued
				FROM {$cards} WHERE created_at >= %s",
				array( $since )
			);
			foreach ( (array) $row as $k => $v ) {
				$out[ $span . '_' . $k ] = (int) $v;
			}
			$out[ $span . '_redeemed_cents' ] = -(int) $this->value( "SELECT COALESCE(SUM(amount_cents), 0) FROM {$ledger} WHERE type IN ('used', 'restored') AND created_at >= %s", array( $since ) );
		}

		return $out;
	}

	/**
	 * Count and outstanding of the imported cards (the reconciliation).
	 *
	 * @return array{cards: int, active: int, disabled: int, active_cents: int, disabled_cents: int}
	 */
	public function imported_totals() {
		$row = $this->row(
			"SELECT COUNT(*) AS cards,
				COALESCE(SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END), 0) AS active,
				COALESCE(SUM(CASE WHEN status = 'disabled' THEN 1 ELSE 0 END), 0) AS disabled,
				COALESCE(SUM(CASE WHEN status = 'active' THEN balance_cents ELSE 0 END), 0) AS active_cents,
				COALESCE(SUM(CASE WHEN status = 'disabled' THEN balance_cents ELSE 0 END), 0) AS disabled_cents
			FROM " . $this->table( 'gift_cards' ) . ' WHERE legacy_id IS NOT NULL'
		);
		$out = array();
		foreach ( array( 'cards', 'active', 'disabled', 'active_cents', 'disabled_cents' ) as $k ) {
			$out[ $k ] = (int) ( $row[ $k ] ?? 0 );
		}
		return $out;
	}

	/* LEGACY-READ-ONLY: BEGIN — the old plugin's tables. SELECT and SHOW only. */

	/**
	 * Run a read on the old tables. Anything that is not SELECT or SHOW is
	 * refused before it reaches the database.
	 *
	 * @param string $sql    SQL.
	 * @param array  $params Values.
	 * @param string $how    rows|col|var.
	 * @return mixed|null Null when refused or on error.
	 */
	public function legacy_read( $sql, array $params = array(), $how = 'rows' ) {
		if ( ! self::read_only_sql( $sql ) || ! is_object( $this->db ) ) {
			return null;
		}
		try {
			$prepared = $params ? $this->db->prepare( $sql, $params ) : $sql;
			if ( 'var' === $how ) {
				return $this->db->get_var( $prepared ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
			}
			if ( 'col' === $how ) {
				return $this->db->get_col( $prepared ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
			}
			$rows = $this->db->get_results( $prepared, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
			return is_array( $rows ) ? $rows : null;
		} catch ( \Throwable $e ) {
			return null;
		}
	}

	/**
	 * Whether SQL is a single SELECT or SHOW (pure).
	 *
	 * @param string $sql SQL.
	 * @return bool
	 */
	public static function read_only_sql( $sql ) {
		$sql = trim( (string) $sql );
		if ( ! preg_match( '/^(SELECT|SHOW)\s/i', $sql ) || false !== strpos( rtrim( $sql, '; ' ), ';' ) ) {
			return false;
		}
		return ! preg_match( '/\b(INSERT|UPDATE|DELETE|REPLACE|DROP|ALTER|TRUNCATE|CREATE|RENAME|GRANT|LOCK|INTO\s+OUTFILE|FOR\s+UPDATE)\b/i', $sql );
	}

	/**
	 * An old table's full name, when the name is plain (letters, digits, _).
	 *
	 * @param string $name Name without the prefix.
	 * @return string '' when it is not.
	 */
	public function legacy_table( $name ) {
		$full = ( is_object( $this->db ) && isset( $this->db->prefix ) ? $this->db->prefix : 'wp_' ) . (string) $name;
		return preg_match( '/^[A-Za-z0-9_]+$/', $full ) ? $full : '';
	}

	/**
	 * Whether an old table exists.
	 *
	 * @param string $name Name without the prefix.
	 * @return bool
	 */
	public function legacy_exists( $name ) {
		$table = $this->legacy_table( $name );
		return '' !== $table && $table === $this->legacy_read( 'SHOW TABLES LIKE %s', array( $table ), 'var' );
	}

	/**
	 * An old table's columns: name => type as MySQL reports it.
	 *
	 * @param string $name Name without the prefix.
	 * @return array<string, string>|null Null when the table cannot be read.
	 */
	public function legacy_columns( $name ) {
		$table = $this->legacy_table( $name );
		if ( '' === $table ) {
			return null;
		}
		$rows = $this->legacy_read( "SHOW COLUMNS FROM `{$table}`" );
		if ( ! is_array( $rows ) ) {
			return null;
		}
		$out = array();
		foreach ( $rows as $row ) {
			$out[ (string) $row['Field'] ] = strtolower( (string) $row['Type'] );
		}
		return $out;
	}

	/**
	 * A chunk of an old table's rows by id.
	 *
	 * @param string $name     Name without the prefix.
	 * @param int    $after_id The last id read.
	 * @param int    $limit    Most.
	 * @return array[]|null
	 */
	public function legacy_rows( $name, $after_id, $limit = 500 ) {
		$table = $this->legacy_table( $name );
		return '' === $table ? null : $this->legacy_read( "SELECT * FROM `{$table}` WHERE id > %d ORDER BY id ASC LIMIT %d", array( (int) $after_id, max( 1, min( self::CHUNK_MAX, (int) $limit ) ) ) );
	}

	/**
	 * An old table's row count and largest id.
	 *
	 * @param string $name Name without the prefix.
	 * @return array{rows: int, max_id: int}|null
	 */
	public function legacy_size( $name ) {
		$table = $this->legacy_table( $name );
		$rows  = '' === $table ? null : $this->legacy_read( "SELECT COUNT(*) AS n, COALESCE(MAX(id), 0) AS max_id FROM `{$table}`" );
		if ( ! is_array( $rows ) || ! $rows ) {
			return null;
		}
		return array(
			'rows'   => (int) $rows[0]['n'],
			'max_id' => (int) $rows[0]['max_id'],
		);
	}

	/**
	 * Which of these user ids are site users.
	 *
	 * @param int[] $ids User ids.
	 * @return int[]
	 */
	public function existing_users( array $ids ) {
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
		if ( ! $ids || ! is_object( $this->db ) || ! isset( $this->db->users ) ) {
			return array();
		}
		$found = $this->legacy_read( 'SELECT ID FROM ' . $this->db->users . ' WHERE ID IN (' . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ')', $ids, 'col' );
		return array_map( 'intval', is_array( $found ) ? $found : array() );
	}

	/* LEGACY-READ-ONLY: END */
}
