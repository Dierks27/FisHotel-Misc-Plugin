<?php
/**
 * Sticker Giveaway store — the giveaway piles and the order-line ledger,
 * in two small tables of this section's own.
 *
 * WHY tables and not the settings option: the settings page saves its
 * whole option at once, so a count kept there would be overwritten by
 * whatever number the page was opened with. And a count that two orders
 * paid in the same second both read, change and write back loses one of
 * the two. Here the pile only ever changes inside the database:
 *
 *   UPDATE … SET on_paper = on_paper + ( -1 )
 *
 * is one statement; the database serialises it, so two orders at the
 * same moment take two stickers. Nothing reads the number into PHP and
 * writes it back.
 *
 *   {prefix}fh_sticker_piles  one row per design: the paper count, B (put
 *                             in since the last count), and whether the
 *                             reorder email for the current drop was sent.
 *   {prefix}fh_sticker_lines  one row per order line that took stickers:
 *                             the ledger that makes "once per order line"
 *                             true. A row changes by compare-and-swap
 *                             (UPDATE … WHERE it still reads what was
 *                             read); only the request whose swap went
 *                             through moves the pile. It is also where
 *                             "how many went in the last 28 days" is
 *                             counted from.
 *
 * What a move does to a ledger row is decided by Piles::ledger() (pure,
 * tested); this class writes it down. Every method is wrapped: a database
 * error means false / 0 / an empty list, never an exception — a sticker
 * must not take a checkout down. While the tables are not installed (the
 * version option says so; no query) nothing is sent to the database at
 * all: every pile reads empty and every write answers "not done".
 *
 * @package FisHotel\Misc\Sections\Sticker_Giveaway
 */

namespace FisHotel\Misc\Sections\Sticker_Giveaway;

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
	const DB_VERSION_OPTION = 'fishotel_sticker_giveaway_db_version';

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
	const TABLES = array( 'sticker_piles', 'sticker_lines' );

	/**
	 * How often a ledger swap is tried again when another request got in
	 * between the read and the write.
	 *
	 * @var int
	 */
	const SWAP_TRIES = 3;

	/**
	 * Piles read in this request (design id => pile), dropped on a write.
	 *
	 * @var array<int, array>
	 */
	private static $piles = array();

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
	 * (autoloaded) once both tables are really there; on failure record a
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
	 * on_paper is signed on purpose: two customers can each hold the last
	 * sticker in their cart, and the count must say so (−1) instead of
	 * failing the second order.
	 *
	 * @param string $charset_collate The charset / collation clause.
	 * @return string[]
	 */
	public static function schema( $charset_collate = '' ) {
		$piles = self::table( 'sticker_piles' );
		$lines = self::table( 'sticker_lines' );

		return array(
			"CREATE TABLE {$piles} (
				design_id bigint(20) unsigned NOT NULL,
				on_paper int(11) NOT NULL DEFAULT 0,
				basis int(11) NOT NULL DEFAULT 0,
				alerted tinyint(1) NOT NULL DEFAULT 0,
				counted_at datetime NULL,
				added_at datetime NULL,
				updated_at datetime NULL,
				PRIMARY KEY  (design_id)
			) {$charset_collate};",
			"CREATE TABLE {$lines} (
				item_id bigint(20) unsigned NOT NULL,
				order_id bigint(20) unsigned NOT NULL DEFAULT 0,
				design_id bigint(20) unsigned NOT NULL DEFAULT 0,
				kind varchar(8) NOT NULL DEFAULT '',
				qty int(11) NOT NULL DEFAULT 0,
				back int(11) NOT NULL DEFAULT 0,
				state varchar(8) NOT NULL DEFAULT 'taken',
				taken_at datetime NULL,
				updated_at datetime NULL,
				PRIMARY KEY  (item_id),
				KEY design_taken (design_id,taken_at),
				KEY order_id (order_id)
			) {$charset_collate};",
		);
	}

	/**
	 * Whether both tables exist (install and the admin page only).
	 *
	 * @return bool
	 */
	public static function tables_exist() {
		global $wpdb;

		try {
			foreach ( self::TABLES as $name ) {
				$table = self::table( $name );
				if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
					return false;
				}
			}
			return true;
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	/**
	 * Now, as the tables store it (UTC).
	 *
	 * @return string
	 */
	private static function now() {
		return gmdate( 'Y-m-d H:i:s' );
	}

	/* ============================================================== */
	/*  Piles                                                          */
	/* ============================================================== */

	/**
	 * The piles of some designs (one query, remembered for the request).
	 * A design with no row yet is an empty pile.
	 *
	 * @param int[] $design_ids Design ids.
	 * @return array<int, array{paper: int, basis: int, alerted: bool, counted_at: string, added_at: string}>
	 */
	public function piles( array $design_ids ) {
		global $wpdb;

		$ids  = Rules::ids( $design_ids );
		$need = array_values( array_diff( $ids, array_keys( self::$piles ) ) );

		if ( $need ) {
			foreach ( $need as $id ) {
				self::$piles[ $id ] = self::empty_pile();
			}
		}

		// Tables not made (yet): every pile reads empty, and nothing is asked of the database.
		if ( $need && self::installed() ) {
			try {
				$table = self::table( 'sticker_piles' );
				$in    = implode( ',', array_map( 'intval', $need ) );
				$rows  = $wpdb->get_results( "SELECT design_id, on_paper, basis, alerted, counted_at, added_at FROM {$table} WHERE design_id IN ({$in})", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery

				foreach ( is_array( $rows ) ? $rows : array() as $row ) {
					self::$piles[ (int) $row['design_id'] ] = array(
						'paper'      => (int) $row['on_paper'],
						'basis'      => max( 0, (int) $row['basis'] ),
						'alerted'    => ! empty( $row['alerted'] ),
						'counted_at' => (string) ( $row['counted_at'] ?? '' ),
						'added_at'   => (string) ( $row['added_at'] ?? '' ),
					);
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}
		}

		$out = array();
		foreach ( $ids as $id ) {
			$out[ $id ] = self::$piles[ $id ];
		}

		return $out;
	}

	/**
	 * One design's pile.
	 *
	 * @param int $design_id Design id.
	 * @return array
	 */
	public function pile( $design_id ) {
		$piles = $this->piles( array( (int) $design_id ) );

		return $piles[ (int) $design_id ] ?? self::empty_pile();
	}

	/**
	 * A pile nobody has counted yet.
	 *
	 * @return array
	 */
	private static function empty_pile() {
		return array(
			'paper'      => 0,
			'basis'      => 0,
			'alerted'    => false,
			'counted_at' => '',
			'added_at'   => '',
		);
	}

	/**
	 * Forget the piles read in this request (after a write; a test).
	 */
	public static function forget() {
		self::$piles = array();
	}

	/**
	 * "Add stickers": N more went into the pile (Piles::after_add()). One
	 * statement — the row is made if it is not there, else both numbers
	 * grow inside the database.
	 *
	 * @param int $design_id Design id.
	 * @param int $n         How many (1 or more).
	 * @return bool
	 */
	public function add( $design_id, $n ) {
		global $wpdb;

		$design_id = (int) $design_id;
		$n         = (int) $n;

		if ( $design_id < 1 || $n < 1 || ! self::installed() ) {
			return false;
		}

		$table = self::table( 'sticker_piles' );
		$now   = self::now();

		try {
			$done = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare(
					"INSERT INTO {$table} (design_id, on_paper, basis, added_at, updated_at) VALUES (%d, %d, %d, %s, %s) ON DUPLICATE KEY UPDATE on_paper = on_paper + %d, basis = basis + %d, added_at = %s, updated_at = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$design_id,
					$n,
					$n,
					$now,
					$now,
					$n,
					$n,
					$now,
					$now
				)
			);
		} catch ( \Throwable $e ) {
			$done = false;
		}

		self::forget();

		return false !== $done;
	}

	/**
	 * "Recount": the pile really holds N (Piles::after_recount()). The
	 * paper count and B both become N. A wrong number is put right by
	 * counting again.
	 *
	 * @param int $design_id Design id.
	 * @param int $n         What was counted (0 or more).
	 * @return bool
	 */
	public function recount( $design_id, $n ) {
		global $wpdb;

		$design_id = (int) $design_id;
		$n         = (int) $n;

		if ( $design_id < 1 || $n < 0 || ! self::installed() ) {
			return false;
		}

		$table = self::table( 'sticker_piles' );
		$now   = self::now();

		try {
			$done = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare(
					"INSERT INTO {$table} (design_id, on_paper, basis, counted_at, updated_at) VALUES (%d, %d, %d, %s, %s) ON DUPLICATE KEY UPDATE on_paper = %d, basis = %d, counted_at = %s, updated_at = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$design_id,
					$n,
					$n,
					$now,
					$now,
					$n,
					$n,
					$now,
					$now
				)
			);
		} catch ( \Throwable $e ) {
			$done = false;
		}

		self::forget();

		return false !== $done;
	}

	/**
	 * Move a pile's paper count by a delta, inside the database (an order
	 * took stickers or gave them back). B does not move.
	 *
	 * @param int $design_id Design id.
	 * @param int $delta     The change (negative = taken).
	 * @return bool
	 */
	private function shift( $design_id, $delta ) {
		global $wpdb;

		$table = self::table( 'sticker_piles' );
		$now   = self::now();

		$done = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"INSERT INTO {$table} (design_id, on_paper, basis, updated_at) VALUES (%d, %d, 0, %s) ON DUPLICATE KEY UPDATE on_paper = on_paper + %d, updated_at = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				(int) $design_id,
				(int) $delta,
				$now,
				(int) $delta,
				$now
			)
		);

		return false !== $done;
	}

	/**
	 * Take the one reorder email of a design's current drop: true for
	 * exactly one request (UPDATE … WHERE alerted = 0), so two orders paid
	 * together write one email.
	 *
	 * @param int $design_id Design id.
	 * @return bool Whether this request is the one that writes.
	 */
	public function claim_alert( $design_id ) {
		return $this->set_alerted( $design_id, 1 );
	}

	/**
	 * The design is back above its reorder number: the next drop may
	 * write again.
	 *
	 * @param int $design_id Design id.
	 * @return bool Whether the mark was there to clear.
	 */
	public function clear_alert( $design_id ) {
		return $this->set_alerted( $design_id, 0 );
	}

	/**
	 * Flip the alerted mark, only from the other value.
	 *
	 * @param int $design_id Design id.
	 * @param int $to        1 or 0.
	 * @return bool Whether this request flipped it.
	 */
	private function set_alerted( $design_id, $to ) {
		global $wpdb;

		$design_id = (int) $design_id;
		$to        = $to ? 1 : 0;
		$table     = self::table( 'sticker_piles' );

		if ( $design_id < 1 || ! self::installed() ) {
			return false;
		}

		try {
			if ( $to ) {
				// A design with no pile row yet (it keeps no giveaway pile) still has one email to give.
				$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$table} (design_id, updated_at) VALUES (%d, %s)", $design_id, self::now() ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
			}
			$n = $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET alerted = %d WHERE design_id = %d AND alerted = %d", $to, $design_id, $to ? 0 : 1 ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		} catch ( \Throwable $e ) {
			$n = 0;
		}

		self::forget();

		return 1 === (int) $n;
	}

	/* ============================================================== */
	/*  The ledger                                                     */
	/* ============================================================== */

	/**
	 * An order line's ledger row, or null.
	 *
	 * @param int $item_id Order line id.
	 * @return array|null [ state, qty, back, design_id ]
	 */
	public function line( $item_id ) {
		global $wpdb;

		$table = self::table( 'sticker_lines' );

		try {
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT state, qty, back, design_id FROM {$table} WHERE item_id = %d", (int) $item_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		} catch ( \Throwable $e ) {
			$row = null;
		}

		if ( ! is_array( $row ) ) {
			return null;
		}

		return array(
			'state'     => (string) $row['state'],
			'qty'       => (int) $row['qty'],
			'back'      => (int) $row['back'],
			'design_id' => (int) $row['design_id'],
		);
	}

	/**
	 * Apply one event to an order line, once.
	 *
	 * Read the row, ask Piles::ledger() what the event does, and write the
	 * new row ONLY IF the row still reads what was read (a new row: INSERT
	 * IGNORE on the line's id). The request whose write went through moves
	 * the pile; a request that lost the race reads again and finds the
	 * event already done. So a hook that fires twice, or two requests for
	 * the same order at once, move the pile once.
	 *
	 * No transaction is opened: this runs inside WooCommerce's own order
	 * hooks, and starting one here would quietly commit whatever the
	 * caller had open. The swap and the pile are two statements one after
	 * the other; if the pile statement fails, the row is put back.
	 *
	 * @param int    $item_id   Order line id.
	 * @param string $event     Piles::TAKE, ::RETURN or ::REFUND.
	 * @param int    $qty       Line quantity (take) / refunded quantity (refund).
	 * @param int    $design_id Design id (take; otherwise read from the row).
	 * @param int    $order_id  Order id (take).
	 * @param string $kind      'free' or 'extra' (take).
	 * @return int The change made to the paper count (0 = nothing happened).
	 */
	public function move( $item_id, $event, $qty = 0, $design_id = 0, $order_id = 0, $kind = '' ) {
		global $wpdb;

		$item_id = (int) $item_id;
		if ( $item_id < 1 || ! self::installed() ) {
			return 0;
		}

		$table = self::table( 'sticker_lines' );

		try {
			for ( $try = 0; $try < self::SWAP_TRIES; $try++ ) {
				$row                 = $this->line( $item_id );
				list( $new, $delta ) = Piles::ledger( $row, $event, $qty );

				if ( 0 === $delta || ! is_array( $new ) ) {
					return 0;
				}

				$design = $row ? (int) $row['design_id'] : (int) $design_id;
				if ( Piles::TAKE === $event && (int) $design_id > 0 ) {
					$design = (int) $design_id;
				}
				if ( $design < 1 ) {
					return 0;
				}

				$now = self::now();

				if ( null === $row ) {
					$swapped = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
						$wpdb->prepare(
							"INSERT IGNORE INTO {$table} (item_id, order_id, design_id, kind, qty, back, state, taken_at, updated_at) VALUES (%d, %d, %d, %s, %d, %d, %s, %s, %s)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
							$item_id,
							(int) $order_id,
							$design,
							(string) $kind,
							$new['qty'],
							$new['back'],
							$new['state'],
							$now,
							$now
						)
					);
				} else {
					// A take stamps the moment (the 28-day pace counts from it); a return or a refund keeps it.
					$swapped = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
						$wpdb->prepare(
							"UPDATE {$table} SET state = %s, qty = %d, back = %d, design_id = %d, taken_at = IF( %d = 1, %s, taken_at ), updated_at = %s WHERE item_id = %d AND state = %s AND qty = %d AND back = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
							$new['state'],
							$new['qty'],
							$new['back'],
							$design,
							Piles::TAKE === $event ? 1 : 0,
							$now,
							$now,
							$item_id,
							$row['state'],
							$row['qty'],
							$row['back']
						)
					);
				}

				if ( 1 !== (int) $swapped ) {
					continue; // Another request got there first: read again.
				}

				if ( ! $this->shift( $design, $delta ) ) {
					// The pile could not be moved: put the ledger row back as it was, so the event can be tried again.
					if ( null === $row ) {
						$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE item_id = %d AND state = %s AND qty = %d AND back = %d", $item_id, $new['state'], $new['qty'], $new['back'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
					} else {
						$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET state = %s, qty = %d, back = %d WHERE item_id = %d AND state = %s AND qty = %d AND back = %d", $row['state'], $row['qty'], $row['back'], $item_id, $new['state'], $new['qty'], $new['back'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
					}
					self::forget();
					return 0;
				}

				self::forget();

				return (int) $delta;
			}
		} catch ( \Throwable $e ) {
			unset( $e );
		}

		self::forget();

		return 0;
	}

	/**
	 * How many stickers of each design went to orders since a moment, net
	 * of what came back (one query).
	 *
	 * @param int[] $design_ids Design ids.
	 * @param int   $since      Unix time.
	 * @return array<int, int>
	 */
	public function went_since( array $design_ids, $since ) {
		global $wpdb;

		$ids = Rules::ids( $design_ids );
		$out = array_fill_keys( $ids, 0 );

		if ( ! $ids || ! self::installed() ) {
			return $out;
		}

		$table = self::table( 'sticker_lines' );
		$in    = implode( ',', array_map( 'intval', $ids ) );

		try {
			$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare(
					"SELECT design_id, SUM(qty - back) AS went FROM {$table} WHERE state = %s AND taken_at >= %s AND design_id IN ({$in}) GROUP BY design_id", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					Piles::TAKEN,
					gmdate( 'Y-m-d H:i:s', (int) $since )
				),
				ARRAY_A
			);

			foreach ( is_array( $rows ) ? $rows : array() as $row ) {
				$out[ (int) $row['design_id'] ] = max( 0, (int) $row['went'] );
			}
		} catch ( \Throwable $e ) {
			unset( $e );
		}

		return $out;
	}
}
