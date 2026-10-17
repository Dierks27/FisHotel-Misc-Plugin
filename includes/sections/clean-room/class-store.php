<?php
/**
 * Clean Room store — the ONLY file in the section that touches $wpdb.
 *
 * Nine tables, created with dbDelta on first boot (gated by the autoloaded
 * fishotel_cleanroom_db_version option, so a booted site pays no query)
 * and on plugin activation, the layout seeded in the same step:
 *
 *   {prefix}fh_buildings    area, number, name, status, sort, notes, schedule, system_gallons
 *   {prefix}fh_tanks        building_id, number, level, position, size, gallons, status, sort
 *   {prefix}fh_stays        the cached state of one group of one species
 *                           (+ supplier, order_ref, landed_cost, order_line_id —
 *                           the seam a supplier order's check-in fills)
 *   {prefix}fh_stay_events  the history: every change to a stay is an event
 *   {prefix}fh_tank_logs    per-tank entries: copper readings, dose / test
 *                           tick-offs, water changes, feeding, tank notes
 *                           (+ building_id for a system-level dose)
 *   {prefix}fh_aliases      spoken words → product / species, learned on the phone
 *   {prefix}fh_species      the species master (the workbook's Fishlist): name,
 *                           scientific name, group, per box, prices, product link
 *   {prefix}fh_orders       a supplier order's header: ref, supplier, origin, dates,
 *                           status, cost mode, tariffs / flight / import fees / credit
 *   {prefix}fh_order_lines  its lines: species, ordered / received / DOA / survived,
 *                           per box or bags, price, worth, tank, the stay it became
 *
 * record_event() appends and then REPLAYS the stay from all of its events
 * (Replay::state), writing the result back into the stays row — the row is
 * a cache, the events are the truth, nothing is ever deleted. A move with
 * a quantity smaller than the group is a split: split_stay() writes the
 * sister stay with backdated arrival / clear / override events so its
 * history reads the same.
 *
 * Every write is wrapped: missing tables or a database error mean the call
 * returns false / an empty array and nothing else happens. The species
 * search reads products straight from the posts / terms / postmeta tables
 * so it works with or without WooCommerce loaded; the `_fh_common_names`
 * and `_fh_scientific_name` meta are LEFT JOINed, so their absence is fine.
 *
 * @package FisHotel\Misc\Sections\Clean_Room
 */

namespace FisHotel\Misc\Sections\Clean_Room;

defined( 'ABSPATH' ) || exit;

/**
 * Class Store
 */
class Store {

	/**
	 * Schema version. Bump to trigger a dbDelta upgrade. 1.1 changed no
	 * column: it re-saves the version option autoloaded on a site that
	 * stored 1.0 without autoload, so boot() stops costing a query there.
	 * 1.2 adds the species, orders and order_lines tables, the buildings'
	 * schedule and system_gallons and the tank logs' building_id.
	 *
	 * @var string
	 */
	const DB_VERSION = '1.2';

	/**
	 * Option holding the installed schema version.
	 *
	 * @var string
	 */
	const DB_VERSION_OPTION = 'fishotel_cleanroom_db_version';

	/**
	 * Transient caching the species candidate list for the parser.
	 *
	 * @var string
	 */
	const SPECIES_TRANSIENT = 'fishotel_cleanroom_species';

	/**
	 * Product categories whose products are fish for the species search.
	 *
	 * @var string[]
	 */
	const FISH_CATEGORIES = array( 'quarantined-fish', 'inverts' );

	/**
	 * Product statuses the species search offers (fish in QT are often
	 * still drafts).
	 *
	 * @var string[]
	 */
	const PRODUCT_STATUSES = array( 'publish', 'private', 'draft', 'pending', 'future' );

	const SOURCES   = array( 'manual', 'voice', 'qr', 'import', 'order' );
	const LOG_KINDS = array( 'copper', 'dose', 'test', 'water_change', 'feed', 'note', 'other' );
	const TABLES    = array( 'buildings', 'tanks', 'stays', 'stay_events', 'tank_logs', 'aliases', 'species', 'orders', 'order_lines' );

	/**
	 * Row caps for the list queries.
	 */
	const SPECIES_LIMIT = 5000;
	const ORDERS_LIMIT  = 500;

	/**
	 * Whether the tables exist, memoised per request (null = not checked).
	 *
	 * @var bool|null
	 */
	private static $tables_exist = null;

	/**
	 * tank id → area, memoised per instance.
	 *
	 * @var array|null
	 */
	private $areas = null;

	/**
	 * Fully-prefixed table name.
	 *
	 * @param string $name buildings|tanks|stays|stay_events|tank_logs|aliases.
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
	 * Create / upgrade the tables via dbDelta, gated on the stored version,
	 * then seed the layout when the buildings table is empty. The option
	 * is written only once every table is really there, so a CREATE that
	 * failed is retried on the next boot; it is autoloaded, so once it
	 * matches this costs nothing (no query, no SHOW TABLES).
	 *
	 * @param bool $force Skip the version gate.
	 * @return bool Whether the install step ran.
	 */
	public static function install( $force = false ) {
		$installed = get_option( self::DB_VERSION_OPTION );

		if ( ! $force && self::DB_VERSION === $installed ) {
			return false;
		}

		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();
		$buildings       = self::table( 'buildings' );
		$tanks           = self::table( 'tanks' );
		$stays           = self::table( 'stays' );
		$events          = self::table( 'stay_events' );
		$logs            = self::table( 'tank_logs' );
		$aliases         = self::table( 'aliases' );
		$species         = self::table( 'species' );
		$orders          = self::table( 'orders' );
		$lines           = self::table( 'order_lines' );

		// dbDelta is whitespace-sensitive: each column on its own line,
		// two spaces after PRIMARY KEY, KEY (not INDEX) with a name.
		$sql = array(
			"CREATE TABLE {$buildings} (
				id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				area ENUM('clean','spa') NOT NULL DEFAULT 'clean',
				number INT NOT NULL,
				name VARCHAR(60) NOT NULL DEFAULT '',
				status ENUM('active','coming_soon','offline') NOT NULL DEFAULT 'active',
				sort INT NOT NULL DEFAULT 0,
				notes TEXT NULL,
				schedule VARCHAR(40) NOT NULL DEFAULT '',
				system_gallons SMALLINT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY number (number)
			) {$charset_collate};",
			"CREATE TABLE {$tanks} (
				id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				building_id BIGINT(20) UNSIGNED NOT NULL,
				number INT NOT NULL,
				level TINYINT NOT NULL DEFAULT 1,
				position TINYINT NOT NULL DEFAULT 1,
				size_label VARCHAR(40) NOT NULL DEFAULT '',
				gallons SMALLINT NOT NULL DEFAULT 0,
				status ENUM('active','offline') NOT NULL DEFAULT 'active',
				sort INT NOT NULL DEFAULT 0,
				PRIMARY KEY  (id),
				UNIQUE KEY number (number),
				KEY building_id (building_id)
			) {$charset_collate};",
			"CREATE TABLE {$stays} (
				id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				product_id BIGINT(20) UNSIGNED NULL,
				species VARCHAR(120) NOT NULL DEFAULT '',
				qty_arrived INT NOT NULL DEFAULT 0,
				qty_live INT NOT NULL DEFAULT 0,
				checkin_date DATE NOT NULL,
				cleared_date DATE NULL,
				sale_ready_date DATE NULL,
				closed_date DATE NULL,
				status ENUM('in_qt','cleared','closed') NOT NULL DEFAULT 'in_qt',
				tank_id BIGINT(20) UNSIGNED NULL,
				source ENUM('manual','voice','qr','import','order') NOT NULL DEFAULT 'manual',
				order_line_id BIGINT(20) UNSIGNED NULL,
				supplier VARCHAR(80) NULL,
				order_ref VARCHAR(80) NULL,
				landed_cost DECIMAL(10,2) NULL,
				notes TEXT NULL,
				created_at DATETIME NOT NULL,
				updated_at DATETIME NOT NULL,
				PRIMARY KEY  (id),
				KEY product_id (product_id),
				KEY tank_id (tank_id),
				KEY status (status),
				KEY checkin_date (checkin_date)
			) {$charset_collate};",
			"CREATE TABLE {$events} (
				id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				stay_id BIGINT(20) UNSIGNED NOT NULL,
				type ENUM('arrival','move','loss','clear','sold','count','note') NOT NULL,
				qty INT NULL,
				from_tank_id BIGINT(20) UNSIGNED NULL,
				to_tank_id BIGINT(20) UNSIGNED NULL,
				note TEXT NULL,
				event_at DATETIME NOT NULL,
				actor_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
				source ENUM('manual','voice','qr','import','order') NOT NULL DEFAULT 'manual',
				created_at DATETIME NOT NULL,
				PRIMARY KEY  (id),
				KEY stay_id (stay_id),
				KEY event_at (event_at)
			) {$charset_collate};",
			"CREATE TABLE {$logs} (
				id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				tank_id BIGINT(20) UNSIGNED NOT NULL,
				kind ENUM('copper','dose','test','water_change','feed','note','other') NOT NULL DEFAULT 'note',
				value DECIMAL(6,3) NULL,
				note TEXT NULL,
				logged_at DATETIME NOT NULL,
				actor_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
				source ENUM('manual','voice','qr','import','order') NOT NULL DEFAULT 'manual',
				created_at DATETIME NOT NULL,
				building_id BIGINT(20) UNSIGNED NULL,
				PRIMARY KEY  (id),
				KEY tank_id (tank_id),
				KEY logged_at (logged_at),
				KEY building_id (building_id)
			) {$charset_collate};",
			"CREATE TABLE {$aliases} (
				id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				alias VARCHAR(120) NOT NULL,
				product_id BIGINT(20) UNSIGNED NULL,
				species VARCHAR(120) NOT NULL DEFAULT '',
				created_at DATETIME NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY alias (alias)
			) {$charset_collate};",
			"CREATE TABLE {$species} (
				id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				name VARCHAR(120) NOT NULL,
				sci_name VARCHAR(120) NOT NULL DEFAULT '',
				group_name VARCHAR(60) NOT NULL DEFAULT '',
				per_box SMALLINT NOT NULL DEFAULT 24,
				my_price DECIMAL(10,2) NULL,
				online_price DECIMAL(10,2) NULL,
				online_site VARCHAR(60) NULL,
				online_date DATE NULL,
				reef_safe ENUM('','yes','no','caution') NOT NULL DEFAULT '',
				max_size VARCHAR(40) NOT NULL DEFAULT '',
				product_id BIGINT(20) UNSIGNED NULL,
				notes TEXT NULL,
				created_at DATETIME NOT NULL,
				updated_at DATETIME NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY name (name),
				KEY product_id (product_id)
			) {$charset_collate};",
			"CREATE TABLE {$orders} (
				id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				ref VARCHAR(40) NOT NULL,
				supplier VARCHAR(80) NOT NULL DEFAULT '',
				origin VARCHAR(80) NOT NULL DEFAULT '',
				order_date DATE NULL,
				received_date DATE NULL,
				status ENUM('draft','ordered','received','closed') NOT NULL DEFAULT 'draft',
				boxes SMALLINT NULL,
				cost_mode ENUM('box','bag') NOT NULL DEFAULT 'box',
				tariffs DECIMAL(10,2) NOT NULL DEFAULT 0,
				domestic_flight DECIMAL(10,2) NOT NULL DEFAULT 0,
				import_fees DECIMAL(10,2) NOT NULL DEFAULT 0,
				credit DECIMAL(10,2) NOT NULL DEFAULT 0,
				markup DECIMAL(5,2) NOT NULL DEFAULT 4.00,
				worth_threshold DECIMAL(5,2) NOT NULL DEFAULT 2.75,
				schedule VARCHAR(40) NOT NULL DEFAULT '',
				building_id BIGINT(20) UNSIGNED NULL,
				notes TEXT NULL,
				created_at DATETIME NOT NULL,
				updated_at DATETIME NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY ref (ref),
				KEY received_date (received_date)
			) {$charset_collate};",
			"CREATE TABLE {$lines} (
				id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				order_id BIGINT(20) UNSIGNED NOT NULL,
				species_id BIGINT(20) UNSIGNED NULL,
				species VARCHAR(120) NOT NULL DEFAULT '',
				sci_name VARCHAR(120) NOT NULL DEFAULT '',
				qty_ordered INT NOT NULL DEFAULT 0,
				qty_received INT NULL,
				per_box SMALLINT NULL,
				bags SMALLINT NULL,
				unit_price DECIMAL(10,4) NULL,
				worth DECIMAL(10,2) NULL,
				qty_doa INT NOT NULL DEFAULT 0,
				qty_survived INT NULL,
				landed_total DECIMAL(10,2) NULL,
				stay_id BIGINT(20) UNSIGNED NULL,
				tank_id BIGINT(20) UNSIGNED NULL,
				sort INT NOT NULL DEFAULT 0,
				notes TEXT NULL,
				PRIMARY KEY  (id),
				KEY order_id (order_id),
				KEY species_id (species_id),
				KEY stay_id (stay_id)
			) {$charset_collate};",
		);

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		foreach ( $sql as $statement ) {
			dbDelta( $statement );
		}

		self::$tables_exist = null;

		if ( self::tables_exist() ) {
			update_option( self::DB_VERSION_OPTION, self::DB_VERSION, true );
			self::seed_if_empty();
			self::backfill_system_gallons();
		}

		return true;
	}

	/**
	 * The 1.2 upgrade of a site seeded before the column existed: the two
	 * Spa systems' volumes (205 and 100 gallons — Jeff's figures, used by
	 * the dose maths and the workbook import), written only where the
	 * column is still NULL so a typed value is never touched.
	 */
	public static function backfill_system_gallons() {
		if ( ! self::tables_exist() ) {
			return;
		}

		global $wpdb;

		$seed = array(
			500 => Layout::SPA_500_GALLONS,
			600 => Layout::SPA_600_GALLONS,
		);
		foreach ( $seed as $number => $gallons ) {
			try {
				$wpdb->query( $wpdb->prepare( 'UPDATE ' . self::table( 'buildings' ) . ' SET system_gallons = %d WHERE number = %d AND system_gallons IS NULL', $gallons, $number ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			} catch ( \Throwable $e ) {
				unset( $e );
			}
		}
	}

	/**
	 * A schedule template was renamed (or deleted: '' as the new name):
	 * every building that named it follows.
	 *
	 * @param string $old Old template name.
	 * @param string $new New name ('' = back to the default).
	 * @return bool
	 */
	public function rename_schedule( $old, $new ) {
		$old = (string) $old;
		if ( ! self::tables_exist() || '' === $old || $old === (string) $new ) {
			return false;
		}

		global $wpdb;

		try {
			$ok = $wpdb->query( $wpdb->prepare( 'UPDATE ' . self::table( 'buildings' ) . ' SET schedule = %s WHERE schedule = %s', substr( (string) $new, 0, 40 ), $old ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		} catch ( \Throwable $e ) {
			return false;
		}

		return false !== $ok;
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
				$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
				if ( $found !== $table ) {
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
	 * Insert the seed layout — ONLY when the buildings table is empty
	 * (idempotent: a second call finds rows and does nothing). Runs from
	 * install() when the version gate opens, never on an ordinary boot.
	 *
	 * @return bool Whether anything was seeded.
	 */
	public static function seed_if_empty() {
		if ( ! self::tables_exist() ) {
			return false;
		}

		global $wpdb;

		try {
			$count = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::table( 'buildings' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		} catch ( \Throwable $e ) {
			return false;
		}
		if ( $count > 0 ) {
			return false;
		}

		$store = new self();
		foreach ( Layout::seed() as $building ) {
			$tanks       = $building['tanks'];
			$building_id = $store->save_building( $building );
			if ( ! $building_id ) {
				continue;
			}
			foreach ( $tanks as $tank ) {
				$tank['building_id'] = $building_id;
				$store->save_tank( $tank );
			}
		}

		return true;
	}

	/**
	 * Drop the cached species list (a product, a species row or an alias
	 * was saved).
	 */
	public static function clear_species_cache() {
		if ( function_exists( 'delete_transient' ) ) {
			delete_transient( self::SPECIES_TRANSIENT );
		}
	}

	/* ============================================================== */
	/*  Buildings and tanks                                            */
	/* ============================================================== */

	/**
	 * Every building, by sort then number.
	 *
	 * @return array[]
	 */
	public function buildings() {
		if ( ! self::tables_exist() ) {
			return array();
		}

		global $wpdb;

		try {
			$rows = $wpdb->get_results( 'SELECT * FROM ' . self::table( 'buildings' ) . ' ORDER BY sort ASC, number ASC', ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		} catch ( \Throwable $e ) {
			return array();
		}

		return array_map( array( self::class, 'hydrate_building' ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * One building.
	 *
	 * @param int $id Building id.
	 * @return array|null
	 */
	public function building( $id ) {
		if ( ! self::tables_exist() || (int) $id <= 0 ) {
			return null;
		}

		global $wpdb;

		try {
			$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table( 'buildings' ) . ' WHERE id = %d', (int) $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		} catch ( \Throwable $e ) {
			return null;
		}

		return $row ? self::hydrate_building( $row ) : null;
	}

	/**
	 * Insert or update a building. Validation is the caller's job
	 * (Layout::validate_building()).
	 *
	 * @param array $building id (0 = insert), area, number, name, status, sort, notes.
	 * @return int|false Row id.
	 */
	public function save_building( array $building ) {
		if ( ! self::tables_exist() ) {
			return false;
		}

		global $wpdb;

		$gallons = isset( $building['system_gallons'] ) && null !== $building['system_gallons'] && '' !== $building['system_gallons'] && is_numeric( $building['system_gallons'] ) ? max( 0, (int) $building['system_gallons'] ) : null;
		$data    = array(
			'area'           => in_array( (string) ( $building['area'] ?? '' ), array_keys( Layout::AREAS ), true ) ? (string) $building['area'] : 'clean',
			'number'         => (int) ( $building['number'] ?? 0 ),
			'name'           => substr( (string) ( $building['name'] ?? '' ), 0, 60 ),
			'status'         => in_array( (string) ( $building['status'] ?? '' ), Layout::BUILDING_STATUSES, true ) ? (string) $building['status'] : 'active',
			'sort'           => (int) ( $building['sort'] ?? 0 ),
			'notes'          => (string) ( $building['notes'] ?? '' ),
			'schedule'       => substr( (string) ( $building['schedule'] ?? '' ), 0, 40 ),
			'system_gallons' => $gallons, // A null is written as NULL whatever the format.
		);
		$format  = array( '%s', '%d', '%s', '%s', '%d', '%s', '%s', '%d' );

		$this->areas = null;

		try {
			$id = (int) ( $building['id'] ?? 0 );
			if ( $id > 0 ) {
				$ok = $wpdb->update( self::table( 'buildings' ), $data, array( 'id' => $id ), $format, array( '%d' ) );
				return false === $ok ? false : $id;
			}
			$ok = $wpdb->insert( self::table( 'buildings' ), $data, $format );
			return $ok ? (int) $wpdb->insert_id : false;
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	/**
	 * Every tank, with its building's number and area, in rack order.
	 *
	 * @param bool $include_offline Whether offline tanks are included.
	 * @return array[]
	 */
	public function tanks( $include_offline = true ) {
		if ( ! self::tables_exist() ) {
			return array();
		}

		global $wpdb;

		try {
			$rows = $wpdb->get_results(
				'SELECT t.*, b.number AS building_number, b.area AS building_area, b.name AS building_name, b.status AS building_status FROM ' . self::table( 'tanks' ) . ' t LEFT JOIN ' . self::table( 'buildings' ) . ' b ON b.id = t.building_id ORDER BY b.sort ASC, b.number ASC, t.level DESC, t.sort ASC, t.number ASC', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				ARRAY_A
			);
		} catch ( \Throwable $e ) {
			return array();
		}

		$out = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$tank = self::hydrate_tank( $row );
			if ( ! $include_offline && 'offline' === $tank['status'] ) {
				continue;
			}
			$out[] = $tank;
		}

		return $out;
	}

	/**
	 * The tanks of one building.
	 *
	 * @param int $building_id Building id.
	 * @return array[]
	 */
	public function tanks_in_building( $building_id ) {
		$out = array();
		foreach ( $this->tanks() as $tank ) {
			if ( (int) $tank['building_id'] === (int) $building_id ) {
				$out[] = $tank;
			}
		}
		return $out;
	}

	/**
	 * One tank (with its building columns).
	 *
	 * @param int $id Tank id.
	 * @return array|null
	 */
	public function tank( $id ) {
		foreach ( $this->tanks() as $tank ) {
			if ( (int) $tank['id'] === (int) $id ) {
				return $tank;
			}
		}
		return null;
	}

	/**
	 * One tank by its number.
	 *
	 * @param int $number Tank number.
	 * @return array|null
	 */
	public function tank_by_number( $number ) {
		foreach ( $this->tanks() as $tank ) {
			if ( (int) $tank['number'] === (int) $number ) {
				return $tank;
			}
		}
		return null;
	}

	/**
	 * tank id → area ('clean' / 'spa'), for the Replay.
	 *
	 * @return array<int, string>
	 */
	public function tank_areas() {
		if ( null === $this->areas ) {
			$this->areas = array();
			foreach ( $this->tanks() as $tank ) {
				$this->areas[ (int) $tank['id'] ] = (string) $tank['building']['area'];
			}
		}
		return $this->areas;
	}

	/**
	 * Insert or update a tank. Validation and the number are the caller's
	 * job (Layout::validate_tank(), Layout::tank_number()).
	 *
	 * @param array $tank id (0 = insert), building_id, number, level, position, size_label, gallons, status, sort.
	 * @return int|false Row id.
	 */
	public function save_tank( array $tank ) {
		if ( ! self::tables_exist() ) {
			return false;
		}

		global $wpdb;

		$data = array(
			'building_id' => (int) ( $tank['building_id'] ?? 0 ),
			'number'      => (int) ( $tank['number'] ?? 0 ),
			'level'       => (int) ( $tank['level'] ?? 1 ),
			'position'    => (int) ( $tank['position'] ?? 1 ),
			'size_label'  => substr( (string) ( $tank['size_label'] ?? '' ), 0, 40 ),
			'gallons'     => (int) ( $tank['gallons'] ?? 0 ),
			'status'      => in_array( (string) ( $tank['status'] ?? '' ), Layout::TANK_STATUSES, true ) ? (string) $tank['status'] : 'active',
			'sort'        => (int) ( $tank['sort'] ?? 0 ),
		);
		$format = array( '%d', '%d', '%d', '%d', '%s', '%d', '%s', '%d' );

		$this->areas = null;

		try {
			$id = (int) ( $tank['id'] ?? 0 );
			if ( $id > 0 ) {
				$ok = $wpdb->update( self::table( 'tanks' ), $data, array( 'id' => $id ), $format, array( '%d' ) );
				return false === $ok ? false : $id;
			}
			$ok = $wpdb->insert( self::table( 'tanks' ), $data, $format );
			return $ok ? (int) $wpdb->insert_id : false;
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	/**
	 * Every tank with its building, its occupants (open stays with their
	 * day / state), the numbers it shares water with, its last copper
	 * reading and its to-do for today — from the building's own schedule
	 * when the protocols option is given, from one list of steps when only
	 * that is.
	 *
	 * @param string $today    Site date.
	 * @param array  $settings Settings.
	 * @param array  $protocol Settings::protocols() ({ default, templates }), or one steps list
	 *                         (empty = no to-do).
	 * @return array[]
	 */
	public function tanks_with_occupants( $today, array $settings, array $protocol = array() ) {
		$tanks = $this->tanks();
		if ( ! $tanks ) {
			return array();
		}

		$stays   = $this->open_stays( array(), $today, $settings );
		$by_tank = array();
		foreach ( $stays as $stay ) {
			$by_tank[ (int) $stay['tank_id'] ][] = $stay;
		}

		$copper = $this->latest_copper();
		$done   = $this->logs_on_date( $today );

		$per_building = isset( $protocol['templates'] );
		$buildings    = array();
		if ( $per_building ) {
			foreach ( $this->buildings() as $building ) {
				$buildings[ (int) $building['id'] ] = $building;
			}
		}

		$out = array();
		foreach ( $tanks as $tank ) {
			$id                  = (int) $tank['id'];
			$tank['occupants']   = isset( $by_tank[ $id ] ) ? $by_tank[ $id ] : array();
			$tank['shares_with'] = Layout::shares_water( $tank, $tanks );
			$tank['in_qt']       = 0;
			$tank['ready']       = 0;
			foreach ( $tank['occupants'] as $stay ) {
				if ( 'in_qt' === $stay['status'] ) {
					$tank['in_qt']++;
				}
				if ( ! empty( $stay['sale_ready'] ) ) {
					$tank['ready']++;
				}
			}
			$tank['copper'] = isset( $copper[ $id ] ) ? $copper[ $id ] : null;
			if ( $per_building ) {
				$building = isset( $buildings[ (int) $tank['building_id'] ] ) ? $buildings[ (int) $tank['building_id'] ] : array();
				$steps    = Protocol::steps_for( $building, $protocol );
			} else {
				$steps = $protocol;
			}
			$tank['todo'] = $steps ? Protocol::todo( $steps, $tank['occupants'], $today, (string) $tank['building']['area'], isset( $done[ $id ] ) ? $done[ $id ] : array() ) : array();
			$out[]        = $tank;
		}

		return $out;
	}

	/* ============================================================== */
	/*  Stays                                                          */
	/* ============================================================== */

	/**
	 * The base SELECT for stays with their tank / building columns.
	 *
	 * @return string
	 */
	private static function stay_select() {
		return 'SELECT s.*, t.number AS tank_number, t.size_label AS tank_size, b.id AS building_id, b.number AS building_number, b.area AS building_area, b.name AS building_name FROM ' . self::table( 'stays' ) . ' s LEFT JOIN ' . self::table( 'tanks' ) . ' t ON t.id = s.tank_id LEFT JOIN ' . self::table( 'buildings' ) . ' b ON b.id = t.building_id';
	}

	/**
	 * Open stays (in QT or cleared), oldest check-in first, with the day
	 * number and the effective state for today.
	 *
	 * @param array  $filters  area (clean|spa), species (search), product_id, tank_id, building_id,
	 *                         status (in_qt|cleared — effective), ready (bool), include_closed (bool), limit (int).
	 * @param string $today    Site date.
	 * @param array  $settings Settings (sale_ready_days).
	 * @return array[]
	 */
	public function open_stays( array $filters, $today, array $settings ) {
		if ( ! self::tables_exist() ) {
			return array();
		}

		global $wpdb;

		$where  = array( '1=1' );
		$params = array();

		if ( empty( $filters['include_closed'] ) ) {
			$where[] = "s.status <> 'closed'";
		}
		if ( ! empty( $filters['area'] ) && isset( Layout::AREAS[ (string) $filters['area'] ] ) ) {
			$where[]  = 'b.area = %s';
			$params[] = (string) $filters['area'];
		}
		if ( ! empty( $filters['species'] ) ) {
			$where[]  = 's.species LIKE %s';
			$params[] = '%' . $wpdb->esc_like( (string) $filters['species'] ) . '%';
		}
		if ( ! empty( $filters['product_id'] ) ) {
			$where[]  = 's.product_id = %d';
			$params[] = (int) $filters['product_id'];
		}
		if ( ! empty( $filters['tank_id'] ) ) {
			$where[]  = 's.tank_id = %d';
			$params[] = (int) $filters['tank_id'];
		}
		if ( ! empty( $filters['building_id'] ) ) {
			$where[]  = 'b.id = %d';
			$params[] = (int) $filters['building_id'];
		}

		$limit = isset( $filters['limit'] ) ? max( 1, min( 2000, (int) $filters['limit'] ) ) : 1000;
		$sql   = self::stay_select() . ' WHERE ' . implode( ' AND ', $where ) . ' ORDER BY s.checkin_date ASC, s.id ASC LIMIT ' . (int) $limit;

		try {
			$rows = $params
				? $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				: $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		} catch ( \Throwable $e ) {
			return array();
		}

		$out = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$stay = self::hydrate_stay( $row, $today, $settings );
			if ( ! empty( $filters['status'] ) && $stay['status'] !== (string) $filters['status'] ) {
				continue;
			}
			if ( ! empty( $filters['ready'] ) && empty( $stay['sale_ready'] ) ) {
				continue;
			}
			$out[] = $stay;
		}

		return $out;
	}

	/**
	 * Closed stays, most recently closed first.
	 *
	 * @param string $today    Site date.
	 * @param array  $settings Settings.
	 * @param int    $limit    Row cap.
	 * @return array[]
	 */
	public function closed_stays( $today, array $settings, $limit = 200 ) {
		if ( ! self::tables_exist() ) {
			return array();
		}

		global $wpdb;

		try {
			$rows = $wpdb->get_results(
				$wpdb->prepare( self::stay_select() . " WHERE s.status = 'closed' AND s.qty_arrived > 0 ORDER BY s.closed_date DESC, s.id DESC LIMIT %d", max( 1, (int) $limit ) ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				ARRAY_A
			);
		} catch ( \Throwable $e ) {
			return array();
		}

		$out = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$out[] = self::hydrate_stay( $row, $today, $settings );
		}

		return $out;
	}

	/**
	 * Every stay of a product (open first, then closed), with state.
	 *
	 * @param int    $product_id Product ID.
	 * @param string $today      Site date.
	 * @param array  $settings   Settings.
	 * @return array[]
	 */
	public function stays_for_product( $product_id, $today, array $settings ) {
		if ( ! self::tables_exist() || (int) $product_id <= 0 ) {
			return array();
		}

		global $wpdb;

		try {
			$rows = $wpdb->get_results(
				$wpdb->prepare( self::stay_select() . " WHERE s.product_id = %d ORDER BY (s.status = 'closed') ASC, s.checkin_date ASC, s.id ASC LIMIT 200", (int) $product_id ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				ARRAY_A
			);
		} catch ( \Throwable $e ) {
			return array();
		}

		$out = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$out[] = self::hydrate_stay( $row, $today, $settings );
		}

		return $out;
	}

	/**
	 * One stay with its events.
	 *
	 * @param int    $id       Stay id.
	 * @param string $today    Site date.
	 * @param array  $settings Settings.
	 * @return array|null
	 */
	public function stay( $id, $today = '', array $settings = array() ) {
		if ( ! self::tables_exist() || (int) $id <= 0 ) {
			return null;
		}

		global $wpdb;

		try {
			$row = $wpdb->get_row( $wpdb->prepare( self::stay_select() . ' WHERE s.id = %d', (int) $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		} catch ( \Throwable $e ) {
			return null;
		}
		if ( ! $row ) {
			return null;
		}

		$stay           = self::hydrate_stay( $row, $today, $settings );
		$stay['events'] = $this->events( (int) $id );

		return $stay;
	}

	/**
	 * Create a stay row. The first arrival event (record_event) fills the
	 * cached counts; this only needs the identity and the check-in date.
	 * supplier / order_ref / landed_cost / order_line_id are the seam the
	 * supplier-order import will fill; all may be null.
	 *
	 * @param array $fields product_id, species, checkin_date, tank_id, source, order_line_id, supplier, order_ref, landed_cost, notes.
	 * @return int|false Stay id.
	 */
	public function create_stay( array $fields ) {
		if ( ! self::tables_exist() ) {
			return false;
		}

		global $wpdb;

		$now  = gmdate( 'Y-m-d H:i:s' );
		$data = array(
			'product_id'    => ! empty( $fields['product_id'] ) ? (int) $fields['product_id'] : null,
			'species'       => substr( (string) ( $fields['species'] ?? '' ), 0, 120 ),
			'qty_arrived'   => 0,
			'qty_live'      => 0,
			'checkin_date'  => Replay::is_date( $fields['checkin_date'] ?? null ) ? (string) $fields['checkin_date'] : gmdate( 'Y-m-d' ),
			'status'        => 'in_qt',
			'tank_id'       => ! empty( $fields['tank_id'] ) ? (int) $fields['tank_id'] : null,
			'source'        => in_array( (string) ( $fields['source'] ?? '' ), self::SOURCES, true ) ? (string) $fields['source'] : 'manual',
			'order_line_id' => ! empty( $fields['order_line_id'] ) ? (int) $fields['order_line_id'] : null,
			'supplier'      => '' !== trim( (string) ( $fields['supplier'] ?? '' ) ) ? substr( trim( (string) $fields['supplier'] ), 0, 80 ) : null,
			'order_ref'     => '' !== trim( (string) ( $fields['order_ref'] ?? '' ) ) ? substr( trim( (string) $fields['order_ref'] ), 0, 80 ) : null,
			'landed_cost'   => isset( $fields['landed_cost'] ) && is_numeric( $fields['landed_cost'] ) ? round( (float) $fields['landed_cost'], 2 ) : null,
			'notes'         => (string) ( $fields['notes'] ?? '' ),
			'created_at'    => $now,
			'updated_at'    => $now,
		);

		try {
			$ok = $wpdb->insert( self::table( 'stays' ), $data );
		} catch ( \Throwable $e ) {
			return false;
		}

		return $ok ? (int) $wpdb->insert_id : false;
	}

	/**
	 * Update the seam fields of a stay (supplier, order_ref, landed_cost,
	 * order_line_id, product_id, species, notes) — identity, not history.
	 *
	 * @param int   $stay_id Stay id.
	 * @param array $fields  Any of the keys above.
	 * @return bool
	 */
	public function update_stay_fields( $stay_id, array $fields ) {
		if ( ! self::tables_exist() || (int) $stay_id <= 0 ) {
			return false;
		}

		$data = array();
		if ( array_key_exists( 'product_id', $fields ) ) {
			$data['product_id'] = (int) $fields['product_id'] > 0 ? (int) $fields['product_id'] : null;
		}
		if ( array_key_exists( 'species', $fields ) && '' !== trim( (string) $fields['species'] ) ) {
			$data['species'] = substr( trim( (string) $fields['species'] ), 0, 120 );
		}
		foreach ( array( 'supplier', 'order_ref' ) as $key ) {
			if ( array_key_exists( $key, $fields ) ) {
				$value        = trim( (string) $fields[ $key ] );
				$data[ $key ] = '' !== $value ? substr( $value, 0, 80 ) : null;
			}
		}
		if ( array_key_exists( 'landed_cost', $fields ) ) {
			$data['landed_cost'] = is_numeric( $fields['landed_cost'] ) ? round( (float) $fields['landed_cost'], 2 ) : null;
		}
		if ( array_key_exists( 'order_line_id', $fields ) ) {
			$data['order_line_id'] = (int) $fields['order_line_id'] > 0 ? (int) $fields['order_line_id'] : null;
		}
		if ( array_key_exists( 'notes', $fields ) ) {
			$data['notes'] = (string) $fields['notes'];
		}
		if ( ! $data ) {
			return false;
		}
		$data['updated_at'] = gmdate( 'Y-m-d H:i:s' );

		global $wpdb;

		try {
			return false !== $wpdb->update( self::table( 'stays' ), $data, array( 'id' => (int) $stay_id ) );
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	/* ============================================================== */
	/*  Events                                                         */
	/* ============================================================== */

	private static function event_select() {
		return 'SELECT e.*, s.species AS stay_species, s.product_id AS stay_product_id, f.number AS from_tank_number, t.number AS to_tank_number, u.display_name AS actor_name FROM ' . self::table( 'stay_events' ) . ' e LEFT JOIN ' . self::table( 'stays' ) . ' s ON s.id = e.stay_id LEFT JOIN ' . self::table( 'tanks' ) . ' f ON f.id = e.from_tank_id LEFT JOIN ' . self::table( 'tanks' ) . ' t ON t.id = e.to_tank_id LEFT JOIN ' . self::users_table() . ' u ON u.ID = e.actor_id';
	}

	private static function users_table() {
		global $wpdb;
		return isset( $wpdb->users ) ? $wpdb->users : $wpdb->prefix . 'users';
	}

	/**
	 * The events of a stay in order, with tank numbers.
	 *
	 * @param int $stay_id Stay id.
	 * @return array[]
	 */
	public function events( $stay_id ) {
		if ( ! self::tables_exist() || (int) $stay_id <= 0 ) {
			return array();
		}

		global $wpdb;

		try {
			$rows = $wpdb->get_results(
				$wpdb->prepare( self::event_select() . ' WHERE e.stay_id = %d ORDER BY e.event_at ASC, e.id ASC', (int) $stay_id ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				ARRAY_A
			);
		} catch ( \Throwable $e ) {
			return array();
		}

		return array_map( array( self::class, 'hydrate_event' ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * The latest events across every stay, newest first.
	 *
	 * @param int $limit Row cap.
	 * @return array[]
	 */
	public function latest_events( $limit = 200 ) {
		if ( ! self::tables_exist() ) {
			return array();
		}

		global $wpdb;

		try {
			$rows = $wpdb->get_results(
				$wpdb->prepare( self::event_select() . ' ORDER BY e.event_at DESC, e.id DESC LIMIT %d', max( 1, min( 1000, (int) $limit ) ) ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				ARRAY_A
			);
		} catch ( \Throwable $e ) {
			return array();
		}

		return array_map( array( self::class, 'hydrate_event' ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * One event.
	 *
	 * @param int $id Event id.
	 * @return array|null
	 */
	public function event( $id ) {
		if ( ! self::tables_exist() || (int) $id <= 0 ) {
			return null;
		}

		global $wpdb;

		try {
			$row = $wpdb->get_row( $wpdb->prepare( self::event_select() . ' WHERE e.id = %d', (int) $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		} catch ( \Throwable $e ) {
			return null;
		}

		return $row ? self::hydrate_event( $row ) : null;
	}

	/**
	 * Append an event to a stay and replay the stay from all of its events.
	 * 'overdrawn' is true when the events now add up to fewer than zero
	 * fish (two phones recorded a loss against the same count): the entry
	 * is kept, the caller asks for a recount.
	 *
	 * @param int    $stay_id  Stay id.
	 * @param array  $event    type, qty, from_tank_id, to_tank_id, note, event_at (UTC), actor_id, source.
	 * @param string $today    Site date.
	 * @param array  $settings Settings (sale_ready_days).
	 * @return array{event_id: int, state: array, overdrawn: bool}|false
	 */
	public function record_event( $stay_id, array $event, $today, array $settings ) {
		if ( ! self::tables_exist() || (int) $stay_id <= 0 ) {
			return false;
		}

		$type = (string) ( $event['type'] ?? '' );
		if ( ! in_array( $type, Replay::TYPES, true ) ) {
			return false;
		}

		global $wpdb;

		$data = array(
			'stay_id'      => (int) $stay_id,
			'type'         => $type,
			'qty'          => isset( $event['qty'] ) && null !== $event['qty'] ? (int) $event['qty'] : null,
			'from_tank_id' => ! empty( $event['from_tank_id'] ) ? (int) $event['from_tank_id'] : null,
			'to_tank_id'   => ! empty( $event['to_tank_id'] ) ? (int) $event['to_tank_id'] : null,
			'note'         => (string) ( $event['note'] ?? '' ),
			'event_at'     => self::utc_datetime( $event['event_at'] ?? '' ),
			'actor_id'     => (int) ( $event['actor_id'] ?? 0 ),
			'source'       => in_array( (string) ( $event['source'] ?? '' ), self::SOURCES, true ) ? (string) $event['source'] : 'manual',
			'created_at'   => gmdate( 'Y-m-d H:i:s' ),
		);

		try {
			$ok = $wpdb->insert( self::table( 'stay_events' ), $data );
		} catch ( \Throwable $e ) {
			return false;
		}
		if ( ! $ok ) {
			return false;
		}

		$event_id = (int) $wpdb->insert_id;
		$state    = $this->replay( (int) $stay_id, $today, $settings );

		return array(
			'event_id'  => $event_id,
			'state'     => $state,
			'overdrawn' => ! empty( $state['overdrawn'] ),
		);
	}

	/**
	 * Undo one event of a stay: its compensating event (Replay), unless
	 * one was already written — the same ids posted twice write nothing
	 * the second time. Who may undo what (the phone's actor and age
	 * guards) is the caller's business.
	 *
	 * @param array  $stay     The stay with its events (stay()).
	 * @param array  $event    The event to undo (event()).
	 * @param array  $base     actor_id, source.
	 * @param string $today    Site date.
	 * @param array  $settings Settings.
	 * @return array{event_id: int, state: array, overdrawn: bool}|null|false Null = nothing to reverse or already undone.
	 */
	public function undo_event( array $stay, array $event, array $base, $today, array $settings ) {
		$events = isset( $stay['events'] ) && is_array( $stay['events'] ) ? $stay['events'] : $this->events( (int) $stay['id'] );
		if ( Replay::is_undone( $events, (int) $event['id'] ) ) {
			return null;
		}

		$before = $this->state_before( array_merge( $stay, array( 'events' => $events ) ), (int) $event['id'], $today, $settings );
		$comp   = Replay::compensating( $event, $before );
		if ( ! $comp ) {
			return null;
		}

		return $this->record_event( (int) $stay['id'], array_merge( $base, $comp, array( 'event_at' => gmdate( 'Y-m-d H:i:s' ) ) ), $today, $settings );
	}

	/**
	 * The Replay options for the current settings and layout.
	 *
	 * @param array $settings Settings.
	 * @return array
	 */
	public function replay_opts( array $settings ) {
		return array_merge( Settings::replay_opts( $settings ), array( 'tank_areas' => $this->tank_areas() ) );
	}

	/**
	 * Rebuild a stay's cached row from its events.
	 *
	 * @param int    $stay_id  Stay id.
	 * @param string $today    Site date.
	 * @param array  $settings Settings.
	 * @return array The Replay state (empty when the stay is missing).
	 */
	public function replay( $stay_id, $today, array $settings ) {
		$stay = $this->stay( (int) $stay_id, $today, $settings );
		if ( ! $stay ) {
			return array();
		}

		$state = Replay::state( $stay, $stay['events'], $today, $this->replay_opts( $settings ) );

		global $wpdb;

		try {
			$wpdb->update(
				self::table( 'stays' ),
				array(
					'qty_arrived'     => (int) $state['qty_arrived'],
					'qty_live'        => (int) $state['qty_live'],
					'checkin_date'    => '' !== $state['checkin_date'] ? $state['checkin_date'] : $stay['checkin_date'],
					'cleared_date'    => $state['cleared_date'],
					'sale_ready_date' => $state['sale_ready_override'],
					'closed_date'     => $state['closed_date'],
					'status'          => $state['stored_status'],
					'tank_id'         => $state['tank_id'],
					'updated_at'      => gmdate( 'Y-m-d H:i:s' ),
				),
				array( 'id' => (int) $stay_id )
			);
		} catch ( \Throwable $e ) {
			unset( $e );
		}

		return $state;
	}

	/**
	 * The Replay state of a stay just before one of its events — what a
	 * compensating 'count' or sale-ready note needs.
	 *
	 * @param array  $stay     Stay with events.
	 * @param int    $event_id The event.
	 * @param string $today    Site date.
	 * @param array  $settings Settings.
	 * @return array
	 */
	public function state_before( array $stay, $event_id, $today, array $settings ) {
		$before = array();
		foreach ( Replay::sort_events( (array) ( $stay['events'] ?? array() ) ) as $event ) {
			if ( (int) $event['id'] === (int) $event_id ) {
				break;
			}
			$before[] = $event;
		}
		return Replay::state( $stay, $before, $today, $this->replay_opts( $settings ) );
	}

	/**
	 * Split a group: $qty fish of $stay continue in a sister stay in the
	 * destination tank. The original gets a move event with the quantity
	 * (Replay subtracts them); the sister gets a backdated arrival (same
	 * check-in), a clear carrying the original's cleared date when it had
	 * one, the same sale-ready override, and the move itself — so its
	 * history reads the same and the move into the Clean Room clears it as
	 * usual.
	 *
	 * @param array  $stay     The hydrated original stay.
	 * @param int    $qty      Fish moving (0 < qty < qty_live).
	 * @param array  $to_tank  Destination tank row.
	 * @param array  $base     actor_id, source.
	 * @param string $today    Site date.
	 * @param array  $settings Settings.
	 * @param string $reason   The move reason (goes in both move events' notes).
	 * @return array{stay_id: int, event_ids: int[]}|false
	 */
	public function split_stay( array $stay, $qty, array $to_tank, array $base, $today, array $settings, $reason = '' ) {
		$qty    = (int) $qty;
		$reason = trim( (string) $reason );
		if ( $qty < 1 || $qty >= (int) $stay['qty_live'] ) {
			return false;
		}

		$sister = $this->create_stay(
			array(
				'product_id'    => $stay['product_id'],
				'species'       => $stay['species'],
				'checkin_date'  => $stay['checkin_date'],
				'tank_id'       => $stay['tank_id'],
				'source'        => (string) ( $base['source'] ?? 'manual' ),
				'order_line_id' => $stay['order_line_id'],
				'supplier'      => $stay['supplier'],
				'order_ref'     => $stay['order_ref'],
				'landed_cost'   => $stay['landed_cost'],
				'notes'         => 'split from #' . (int) $stay['id'],
			)
		);
		if ( ! $sister ) {
			return false;
		}

		$ids   = array();
		$now   = gmdate( 'Y-m-d H:i:s' );
		$birth = self::site_noon_utc( $stay['checkin_date'] );
		if ( strcmp( $birth, $now ) > 0 ) {
			$birth = $now; // A same-day split before noon: the arrival must still sort before the move.
		}

		// The original: that many leave.
		$written = $this->record_event(
			(int) $stay['id'],
			array_merge( $base, array( 'type' => 'move', 'qty' => $qty, 'from_tank_id' => $stay['tank_id'], 'to_tank_id' => (int) $to_tank['id'], 'note' => 'split to #' . $sister . ( '' !== $reason ? ' · ' . $reason : '' ), 'event_at' => $now ) ),
			$today,
			$settings
		);
		if ( $written ) {
			$ids[] = $written['event_id'];
		}

		// The sister: the same past, then the move.
		$written = $this->record_event(
			$sister,
			array_merge( $base, array( 'type' => 'arrival', 'qty' => $qty, 'to_tank_id' => $stay['tank_id'], 'note' => 'split from #' . (int) $stay['id'], 'event_at' => $birth ) ),
			$today,
			$settings
		);
		if ( $written ) {
			$ids[] = $written['event_id'];
		}
		if ( ! empty( $stay['cleared_date'] ) ) {
			$this->record_event( $sister, array_merge( $base, array( 'type' => 'clear', 'note' => Replay::NOTE_CLEARED . $stay['cleared_date'], 'event_at' => $now ) ), $today, $settings );
		}
		if ( ! empty( $stay['sale_ready_override'] ) ) {
			$this->record_event( $sister, array_merge( $base, array( 'type' => 'note', 'note' => Replay::NOTE_SALE_READY . $stay['sale_ready_override'], 'event_at' => $birth ) ), $today, $settings );
		}
		$written = $this->record_event(
			$sister,
			array_merge( $base, array( 'type' => 'move', 'from_tank_id' => $stay['tank_id'], 'to_tank_id' => (int) $to_tank['id'], 'note' => $reason, 'event_at' => $now ) ),
			$today,
			$settings
		);
		if ( $written ) {
			$ids[] = $written['event_id'];
		}

		return array(
			'stay_id'   => (int) $sister,
			'event_ids' => $ids,
		);
	}

	/**
	 * Noon site time of a date, as UTC (for backdated events).
	 *
	 * @param string $ymd Y-m-d.
	 * @return string
	 */
	public static function site_noon_utc( $ymd ) {
		if ( ! Replay::is_date( $ymd ) ) {
			return gmdate( 'Y-m-d H:i:s' );
		}
		try {
			$dt = new \DateTimeImmutable( $ymd . ' 12:00:00', Clean_Room::timezone() );
			return $dt->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
		} catch ( \Exception $e ) {
			return $ymd . ' 12:00:00';
		}
	}

	/* ============================================================== */
	/*  Tank logs (copper readings, tick-offs, tank notes)             */
	/* ============================================================== */

	/**
	 * Append a tank log entry.
	 *
	 * @param int   $tank_id Tank id.
	 * @param array $log     kind, value (float|null), note, logged_at (UTC, default now), actor_id, source.
	 * @return int|false Log id.
	 */
	public function log_tank( $tank_id, array $log ) {
		if ( ! self::tables_exist() || (int) $tank_id <= 0 ) {
			return false;
		}

		global $wpdb;

		$data = array(
			'tank_id'    => (int) $tank_id,
			'kind'       => in_array( (string) ( $log['kind'] ?? '' ), self::LOG_KINDS, true ) ? (string) $log['kind'] : 'note',
			'value'      => isset( $log['value'] ) && null !== $log['value'] && is_numeric( $log['value'] ) ? round( (float) $log['value'], 3 ) : null,
			'note'       => substr( (string) ( $log['note'] ?? '' ), 0, 2000 ),
			'logged_at'  => self::utc_datetime( $log['logged_at'] ?? '' ),
			'actor_id'   => (int) ( $log['actor_id'] ?? 0 ),
			'source'     => in_array( (string) ( $log['source'] ?? '' ), self::SOURCES, true ) ? (string) $log['source'] : 'manual',
			'created_at' => gmdate( 'Y-m-d H:i:s' ),
		);

		try {
			$ok = $wpdb->insert( self::table( 'tank_logs' ), $data );
		} catch ( \Throwable $e ) {
			return false;
		}

		return $ok ? (int) $wpdb->insert_id : false;
	}

	private static function log_select() {
		return 'SELECT l.*, t.number AS tank_number, u.display_name AS actor_name FROM ' . self::table( 'tank_logs' ) . ' l LEFT JOIN ' . self::table( 'tanks' ) . ' t ON t.id = l.tank_id LEFT JOIN ' . self::users_table() . ' u ON u.ID = l.actor_id';
	}

	/**
	 * The latest log entries of a tank (or of every tank), newest first.
	 *
	 * @param int $tank_id Tank id (0 = all).
	 * @param int $limit   Row cap.
	 * @return array[]
	 */
	public function tank_logs( $tank_id = 0, $limit = 50 ) {
		if ( ! self::tables_exist() ) {
			return array();
		}

		global $wpdb;

		try {
			$sql  = self::log_select();
			$args = array();
			if ( (int) $tank_id > 0 ) {
				$sql   .= ' WHERE l.tank_id = %d';
				$args[] = (int) $tank_id;
			}
			$sql   .= ' ORDER BY l.logged_at DESC, l.id DESC LIMIT %d';
			$args[] = max( 1, min( 1000, (int) $limit ) );
			$rows   = $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		} catch ( \Throwable $e ) {
			return array();
		}

		return array_map( array( self::class, 'hydrate_log' ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * The last copper reading per tank.
	 *
	 * @return array<int, array{value: float, logged_at: string}>
	 */
	public function latest_copper() {
		if ( ! self::tables_exist() ) {
			return array();
		}

		global $wpdb;

		try {
			$rows = $wpdb->get_results(
				'SELECT l.tank_id, l.value, l.logged_at FROM ' . self::table( 'tank_logs' ) . " l INNER JOIN (SELECT tank_id, MAX(id) AS max_id FROM " . self::table( 'tank_logs' ) . " WHERE kind = 'copper' AND value IS NOT NULL GROUP BY tank_id) m ON m.max_id = l.id", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				ARRAY_A
			);
		} catch ( \Throwable $e ) {
			return array();
		}

		$out = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$out[ (int) $row['tank_id'] ] = array(
				'value'     => (float) $row['value'],
				'logged_at' => (string) $row['logged_at'],
			);
		}

		return $out;
	}

	/**
	 * The last dose per medication per building, from the dose logs
	 * (building-level ones and those on a tank of the building alike):
	 * building id => medication key => { label, note, logged_at }.
	 *
	 * @param int $limit How many of the latest dose logs to read.
	 * @return array<int, array<string, array{label: string, note: string, logged_at: string}>>
	 */
	public function latest_doses( $limit = 500 ) {
		if ( ! self::tables_exist() ) {
			return array();
		}

		global $wpdb;

		try {
			$rows = $wpdb->get_results(
				$wpdb->prepare( 'SELECT l.building_id, l.note, l.logged_at, t.building_id AS tank_building_id FROM ' . self::table( 'tank_logs' ) . ' l LEFT JOIN ' . self::table( 'tanks' ) . " t ON t.id = l.tank_id WHERE l.kind = 'dose' ORDER BY l.logged_at DESC, l.id DESC LIMIT %d", max( 1, min( 5000, (int) $limit ) ) ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				ARRAY_A
			);
		} catch ( \Throwable $e ) {
			return array();
		}

		$out = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$building = ! empty( $row['building_id'] ) ? (int) $row['building_id'] : (int) ( $row['tank_building_id'] ?? 0 );
			$key      = Protocol::medication_key( (string) $row['note'] );
			if ( $building <= 0 || null === $key || isset( $out[ $building ][ $key ] ) ) {
				continue;
			}
			$out[ $building ][ $key ] = array(
				'label'     => Protocol::medication_label( $key ),
				'note'      => (string) $row['note'],
				'logged_at' => (string) $row['logged_at'],
			);
		}

		return $out;
	}

	/**
	 * Every log entry of a site date, grouped by tank — what ticks the
	 * to-do off.
	 *
	 * @param string $date Site date.
	 * @return array<int, array[]>
	 */
	public function logs_on_date( $date ) {
		if ( ! self::tables_exist() || ! Replay::is_date( $date ) ) {
			return array();
		}

		global $wpdb;

		try {
			$tz    = Clean_Room::timezone();
			$start = ( new \DateTimeImmutable( $date . ' 00:00:00', $tz ) )->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
			$end   = ( new \DateTimeImmutable( $date . ' 23:59:59', $tz ) )->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
			$rows  = $wpdb->get_results(
				$wpdb->prepare( 'SELECT tank_id, kind, value, note, logged_at FROM ' . self::table( 'tank_logs' ) . ' WHERE logged_at BETWEEN %s AND %s ORDER BY id ASC', $start, $end ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				ARRAY_A
			);
		} catch ( \Throwable $e ) {
			return array();
		}

		$out = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$out[ (int) $row['tank_id'] ][] = $row;
		}

		return $out;
	}

	/* ============================================================== */
	/*  Species                                                        */
	/* ============================================================== */

	/**
	 * Products in the fish categories (and their direct child categories)
	 * matching a term on the title, the common names or the scientific
	 * name, plus matching learned aliases and matching species rows (one
	 * entry per lower-cased name; a species row linked to a listed product,
	 * or named like one, lends it its species_id / per box / price instead
	 * of a second entry) — for the arrival search box and the order editor.
	 *
	 * @param string $term  Search text.
	 * @param int    $limit Row cap.
	 * @return array[] product_id, name, common, sci, match (title|common|sci|alias|species), alias
	 *                 (+ species_id, per_box, my_price, group_name when a species row is known).
	 */
	public function species_suggestions( $term, $limit = 10 ) {
		$term = trim( (string) $term );
		if ( '' === $term ) {
			return array();
		}

		$words = preg_split( '/\s+/', $term );
		$limit = max( 1, min( 50, (int) $limit ) );

		return array_slice( $this->merge_species_suggestions( $this->product_suggestions( $term, $words, $limit ), $words, $limit ), 0, $limit );
	}

	/**
	 * The alias and product part of species_suggestions().
	 *
	 * @param string   $term  Search text.
	 * @param string[] $words Its words.
	 * @param int      $limit Row cap.
	 * @return array[]
	 */
	private function product_suggestions( $term, array $words, $limit ) {
		$out = array();

		// Aliases first.
		foreach ( $this->aliases() as $alias ) {
			if ( false !== stripos( $alias['alias'], $term ) ) {
				$out[] = array(
					'product_id' => $alias['product_id'],
					'name'       => '' !== $alias['species'] ? $alias['species'] : $alias['alias'],
					'common'     => '',
					'sci'        => '',
					'match'      => 'alias',
					'alias'      => $alias['alias'],
				);
			}
		}

		global $wpdb;

		$term_ids = $this->fish_term_ids();
		if ( ! $term_ids ) {
			return array_slice( $out, 0, $limit );
		}

		$where  = array();
		$params = array();
		foreach ( $words as $word ) {
			$like     = '%' . $wpdb->esc_like( $word ) . '%';
			$where[]  = '(p.post_title LIKE %s OR cn.meta_value LIKE %s OR sn.meta_value LIKE %s)';
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
		}
		$params[] = $limit;

		$ids = implode( ',', array_map( 'intval', $term_ids ) );
		$sql = self::product_select( $ids ) . ' AND ' . implode( ' AND ', $where ) . ' GROUP BY p.ID ORDER BY p.post_title ASC LIMIT %d';

		try {
			$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		} catch ( \Throwable $e ) {
			$rows = array();
		}

		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$title  = (string) $row['post_title'];
			$common = (string) ( $row['common'] ?? '' );
			$sci    = (string) ( $row['sci'] ?? '' );
			$match  = 'title';
			if ( false === stripos( $title, $words[0] ) ) {
				$match = false !== stripos( $common, $words[0] ) ? 'common' : 'sci';
			}
			$out[] = array(
				'product_id' => (int) $row['ID'],
				'name'       => $title,
				'common'     => $common,
				'sci'        => $sci,
				'match'      => $match,
				'alias'      => '',
			);
		}

		return array_slice( $out, 0, $limit );
	}

	/**
	 * Fold the matching species rows into a suggestion list (pure apart
	 * from the query): a row linked to a listed product, or named like a
	 * listed entry, lends it its fields; any other matching row is an
	 * entry of its own.
	 *
	 * @param array[]  $items Alias / product suggestions.
	 * @param string[] $words Search words.
	 * @param int      $limit Row cap.
	 * @return array[]
	 */
	private function merge_species_suggestions( array $items, array $words, $limit ) {
		if ( ! self::tables_exist() ) {
			return $items;
		}

		$product_ids = array();
		foreach ( $items as $item ) {
			if ( ! empty( $item['product_id'] ) ) {
				$product_ids[] = (int) $item['product_id'];
			}
		}

		return self::fold_species_rows( $items, $this->species_rows_matching( $words, $product_ids, $limit ) );
	}

	/**
	 * Attach species rows to a suggestion list (pure): by product link,
	 * then by lower-cased name; the rest appended as 'species' matches.
	 *
	 * @param array[] $items Suggestions.
	 * @param array[] $rows  Hydrated species rows.
	 * @return array[]
	 */
	public static function fold_species_rows( array $items, array $rows ) {
		$by_product = array();
		$by_name    = array();
		foreach ( $items as $index => $item ) {
			if ( ! empty( $item['product_id'] ) ) {
				$by_product[ (int) $item['product_id'] ] = $index;
			}
			$by_name[ Species::key( (string) ( $item['name'] ?? '' ) ) ] = $index;
		}

		foreach ( $rows as $row ) {
			$suggestion = Species::suggestion( $row );
			$key        = Species::key( $suggestion['name'] );
			$index      = null;
			if ( null !== $suggestion['product_id'] && isset( $by_product[ $suggestion['product_id'] ] ) ) {
				$index = $by_product[ $suggestion['product_id'] ];
			} elseif ( isset( $by_name[ $key ] ) ) {
				$index = $by_name[ $key ];
			}
			if ( null !== $index ) {
				if ( empty( $items[ $index ]['species_id'] ) ) {
					foreach ( array( 'species_id', 'per_box', 'my_price', 'group_name' ) as $extra ) {
						$items[ $index ][ $extra ] = $suggestion[ $extra ];
					}
					if ( '' === trim( (string) ( $items[ $index ]['sci'] ?? '' ) ) ) {
						$items[ $index ]['sci'] = $suggestion['sci'];
					}
				}
				continue;
			}
			$items[]           = $suggestion;
			$by_name[ $key ]   = count( $items ) - 1;
			if ( null !== $suggestion['product_id'] ) {
				$by_product[ $suggestion['product_id'] ] = count( $items ) - 1;
			}
		}

		return $items;
	}

	/**
	 * Species rows whose name or scientific name contains every word, or
	 * that link one of the given products.
	 *
	 * @param string[] $words       Search words.
	 * @param int[]    $product_ids Products already listed.
	 * @param int      $limit       Row cap.
	 * @return array[] Hydrated rows.
	 */
	private function species_rows_matching( array $words, array $product_ids, $limit ) {
		global $wpdb;

		$where  = array();
		$params = array();
		foreach ( $words as $word ) {
			$like     = '%' . $wpdb->esc_like( $word ) . '%';
			$where[]  = '(name LIKE %s OR sci_name LIKE %s)';
			$params[] = $like;
			$params[] = $like;
		}
		$clause = '(' . implode( ' AND ', $where ) . ')';
		if ( $product_ids ) {
			$clause .= ' OR product_id IN (' . implode( ',', array_map( 'intval', $product_ids ) ) . ')';
		}
		$params[] = max( 1, min( 100, (int) $limit + count( $product_ids ) ) );

		try {
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table( 'species' ) . ' WHERE ' . $clause . ' ORDER BY name ASC LIMIT %d', $params ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		} catch ( \Throwable $e ) {
			return array();
		}

		return array_map( array( self::class, 'hydrate_species' ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * The product query the species search and the candidate list share.
	 *
	 * @param string $ids Comma-separated term ids (already ints).
	 * @return string
	 */
	private static function product_select( $ids ) {
		global $wpdb;

		return 'SELECT p.ID, p.post_title, cn.meta_value AS common, sn.meta_value AS sci FROM ' . $wpdb->posts . ' p'
			. ' INNER JOIN ' . $wpdb->term_relationships . ' tr ON tr.object_id = p.ID'
			. ' INNER JOIN ' . $wpdb->term_taxonomy . " tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = 'product_cat'"
			. ' LEFT JOIN ' . $wpdb->postmeta . " cn ON cn.post_id = p.ID AND cn.meta_key = '_fh_common_names'"
			. ' LEFT JOIN ' . $wpdb->postmeta . " sn ON sn.post_id = p.ID AND sn.meta_key = '_fh_scientific_name'"
			. " WHERE p.post_type = 'product' AND p.post_status IN ('" . implode( "','", self::PRODUCT_STATUSES ) . "')"
			. " AND (tt.term_id IN ({$ids}) OR tt.parent IN ({$ids}))";
	}

	/**
	 * The term ids of the fish categories.
	 *
	 * @return int[]
	 */
	private function fish_term_ids() {
		global $wpdb;

		$slugs = self::FISH_CATEGORIES;
		if ( function_exists( 'apply_filters' ) ) {
			$slugs = (array) apply_filters( 'fishotel_ship_day_fish_categories', $slugs );
		}
		$slugs = array_values( array_filter( array_map( 'strval', $slugs ), 'strlen' ) );
		if ( ! $slugs ) {
			return array();
		}

		$placeholders = implode( ',', array_fill( 0, count( $slugs ), '%s' ) );

		try {
			$ids = $wpdb->get_col(
				$wpdb->prepare( 'SELECT t.term_id FROM ' . $wpdb->terms . ' t INNER JOIN ' . $wpdb->term_taxonomy . " tt ON tt.term_id = t.term_id WHERE tt.taxonomy = 'product_cat' AND t.slug IN ({$placeholders})", $slugs ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			);
		} catch ( \Throwable $e ) {
			return array();
		}

		return array_map( 'intval', is_array( $ids ) ? $ids : array() );
	}

	/**
	 * Every fish product and every species row as a parser candidate
	 * (title, common names, scientific name, learned aliases), one entry
	 * per lower-cased name (Species::merge_candidates), cached for 10
	 * minutes — the cache is dropped by every product, species and alias
	 * save.
	 *
	 * @return array[] product_id, name, variants[] {text, kind}.
	 */
	public function species_candidates() {
		$cached = get_transient( self::SPECIES_TRANSIENT );
		if ( is_array( $cached ) ) {
			$products = $cached;
		} else {
			$products = Species::merge_candidates( $this->fish_products(), $this->species_brief() );
			set_transient( self::SPECIES_TRANSIENT, $products, 10 * MINUTE_IN_SECONDS );
		}

		$by_product = array();
		foreach ( $products as $product ) {
			$variants = array(
				array(
					'text' => $product['name'],
					'kind' => 'title',
				),
			);
			foreach ( self::split_names( $product['common'] ) as $name ) {
				$variants[] = array(
					'text' => $name,
					'kind' => 'common',
				);
			}
			if ( '' !== trim( (string) $product['sci'] ) ) {
				$variants[] = array(
					'text' => $product['sci'],
					'kind' => 'sci',
				);
			}
			$pid                = (int) ( $product['product_id'] ?? 0 );
			$slot               = $pid > 0 ? $pid : 'species:' . Species::key( $product['name'] );
			$by_product[ $slot ] = array(
				'product_id' => $pid > 0 ? $pid : null,
				'name'       => $product['name'],
				'variants'   => $variants,
			);
		}

		// Aliases: onto their product, or as a species of their own.
		foreach ( $this->aliases() as $alias ) {
			$variant = array(
				'text' => $alias['alias'],
				'kind' => 'alias',
			);
			$pid     = (int) $alias['product_id'];
			if ( $pid > 0 && isset( $by_product[ $pid ] ) ) {
				$by_product[ $pid ]['variants'][] = $variant;
				continue;
			}
			$key                = 'alias:' . $alias['id'];
			$by_product[ $key ] = array(
				'product_id' => $pid > 0 ? $pid : null,
				'name'       => '' !== $alias['species'] ? $alias['species'] : $alias['alias'],
				'variants'   => array( $variant ),
			);
		}

		return array_values( $by_product );
	}

	/**
	 * Every product in the fish categories with its names.
	 *
	 * @return array[] product_id, name, common, sci.
	 */
	private function fish_products() {
		global $wpdb;

		$term_ids = $this->fish_term_ids();
		if ( ! $term_ids ) {
			return array();
		}
		$ids = implode( ',', array_map( 'intval', $term_ids ) );
		$sql = self::product_select( $ids ) . ' GROUP BY p.ID ORDER BY p.post_title ASC LIMIT 2000';

		try {
			$rows = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		} catch ( \Throwable $e ) {
			return array();
		}

		$out = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$out[] = array(
				'product_id' => (int) $row['ID'],
				'name'       => (string) $row['post_title'],
				'common'     => (string) ( $row['common'] ?? '' ),
				'sci'        => (string) ( $row['sci'] ?? '' ),
			);
		}

		return $out;
	}

	/**
	 * "Pajama Cardinal, PJ Cardinal / Spotted Cardinalfish" → the names.
	 *
	 * @param string $text Common-names meta.
	 * @return string[]
	 */
	public static function split_names( $text ) {
		$parts = preg_split( '/\s*(?:,|;|\/|\|| or )\s*/i', (string) $text );
		$out   = array();
		foreach ( (array) $parts as $part ) {
			$part = trim( (string) $part );
			if ( '' !== $part && ! in_array( $part, $out, true ) ) {
				$out[] = $part;
			}
		}
		return $out;
	}

	/**
	 * A product's title (no WooCommerce needed); '' when there is no such
	 * product — a stay never assumes one exists.
	 *
	 * @param int $product_id Product ID.
	 * @return string
	 */
	public function product_title( $product_id ) {
		if ( (int) $product_id <= 0 ) {
			return '';
		}

		global $wpdb;

		try {
			return (string) $wpdb->get_var( $wpdb->prepare( 'SELECT post_title FROM ' . $wpdb->posts . " WHERE ID = %d AND post_type = 'product'", (int) $product_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		} catch ( \Throwable $e ) {
			return '';
		}
	}

	/**
	 * The species of the last N arrivals (distinct, newest first) — the
	 * "recent species" chips.
	 *
	 * @param int $limit Count.
	 * @return array[] species, product_id.
	 */
	public function recent_species( $limit = 10 ) {
		if ( ! self::tables_exist() ) {
			return array();
		}

		global $wpdb;

		try {
			$rows = $wpdb->get_results(
				$wpdb->prepare( 'SELECT s.species, s.product_id, MAX(e.id) AS last_id FROM ' . self::table( 'stay_events' ) . ' e INNER JOIN ' . self::table( 'stays' ) . " s ON s.id = e.stay_id WHERE e.type = 'arrival' AND e.qty > 0 GROUP BY s.species, s.product_id ORDER BY last_id DESC LIMIT %d", max( 1, (int) $limit ) ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				ARRAY_A
			);
		} catch ( \Throwable $e ) {
			return array();
		}

		$out = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$out[] = array(
				'species'    => (string) $row['species'],
				'product_id' => ! empty( $row['product_id'] ) ? (int) $row['product_id'] : null,
			);
		}

		return $out;
	}

	/* ============================================================== */
	/*  Aliases                                                        */
	/* ============================================================== */

	/**
	 * Every learned alias.
	 *
	 * @return array[] id, alias, product_id, species, created_at.
	 */
	public function aliases() {
		if ( ! self::tables_exist() ) {
			return array();
		}

		global $wpdb;

		try {
			$rows = $wpdb->get_results( 'SELECT * FROM ' . self::table( 'aliases' ) . ' ORDER BY alias ASC', ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		} catch ( \Throwable $e ) {
			return array();
		}

		$out = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$out[] = array(
				'id'         => (int) $row['id'],
				'alias'      => (string) $row['alias'],
				'product_id' => ! empty( $row['product_id'] ) ? (int) $row['product_id'] : null,
				'species'    => (string) $row['species'],
				'created_at' => (string) $row['created_at'],
			);
		}

		return $out;
	}

	/**
	 * Learn an alias (spoken words → product / species). An existing alias
	 * is re-pointed.
	 *
	 * @param string   $alias      The spoken words (lower-cased, ≤ 120).
	 * @param int|null $product_id Product, or null for an unlisted species.
	 * @param string   $species    Display name.
	 * @return bool
	 */
	public function add_alias( $alias, $product_id, $species ) {
		if ( ! self::tables_exist() ) {
			return false;
		}

		$alias = strtolower( trim( preg_replace( '/\s+/', ' ', (string) $alias ) ) );
		$alias = substr( $alias, 0, 120 );
		if ( '' === $alias ) {
			return false;
		}

		global $wpdb;

		try {
			$existing = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . self::table( 'aliases' ) . ' WHERE alias = %s', $alias ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$data     = array(
				'alias'      => $alias,
				'product_id' => (int) $product_id > 0 ? (int) $product_id : null,
				'species'    => substr( (string) $species, 0, 120 ),
				'created_at' => gmdate( 'Y-m-d H:i:s' ),
			);
			if ( $existing ) {
				$ok = $wpdb->update( self::table( 'aliases' ), $data, array( 'id' => (int) $existing ) );
			} else {
				$ok = $wpdb->insert( self::table( 'aliases' ), $data );
			}
		} catch ( \Throwable $e ) {
			return false;
		}

		self::clear_species_cache();

		return false !== $ok;
	}

	/**
	 * Forget an alias.
	 *
	 * @param int $id Alias id.
	 * @return bool
	 */
	public function delete_alias( $id ) {
		if ( ! self::tables_exist() || (int) $id <= 0 ) {
			return false;
		}

		global $wpdb;

		try {
			$ok = $wpdb->delete( self::table( 'aliases' ), array( 'id' => (int) $id ), array( '%d' ) );
		} catch ( \Throwable $e ) {
			return false;
		}

		self::clear_species_cache();

		return (bool) $ok;
	}

	/* ============================================================== */
	/*  Species master                                                 */
	/* ============================================================== */

	/**
	 * Species rows for a list: a prefix search on the name or the
	 * scientific name (any word of them), an optional group, by name.
	 *
	 * @param array $filters search (string), group (string).
	 * @param int   $limit   Page size.
	 * @param int   $offset  Offset.
	 * @return array[] Hydrated rows.
	 */
	public function species_list( array $filters = array(), $limit = 50, $offset = 0 ) {
		if ( ! self::tables_exist() ) {
			return array();
		}

		global $wpdb;

		list( $where, $params ) = self::species_where( $filters );
		$params[]               = max( 1, min( self::SPECIES_LIMIT, (int) $limit ) );
		$params[]               = max( 0, (int) $offset );

		try {
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table( 'species' ) . ' WHERE ' . $where . ' ORDER BY name ASC LIMIT %d OFFSET %d', $params ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		} catch ( \Throwable $e ) {
			return array();
		}

		return array_map( array( self::class, 'hydrate_species' ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * How many species rows a list filter matches.
	 *
	 * @param array $filters search, group.
	 * @return int
	 */
	public function species_count( array $filters = array() ) {
		if ( ! self::tables_exist() ) {
			return 0;
		}

		global $wpdb;

		list( $where, $params ) = self::species_where( $filters );
		$sql                    = 'SELECT COUNT(*) FROM ' . self::table( 'species' ) . ' WHERE ' . $where;

		try {
			return (int) ( $params ? $wpdb->get_var( $wpdb->prepare( $sql, $params ) ) : $wpdb->get_var( $sql ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		} catch ( \Throwable $e ) {
			return 0;
		}
	}

	/**
	 * The WHERE of a species list filter.
	 *
	 * @param array $filters search, group.
	 * @return array{0: string, 1: array}
	 */
	private static function species_where( array $filters ) {
		global $wpdb;

		$where  = array( '1=1' );
		$params = array();
		$search = Species::key( (string) ( $filters['search'] ?? '' ) );
		if ( '' !== $search ) {
			$prefix   = $wpdb->esc_like( $search );
			$where[]  = '(name LIKE %s OR name LIKE %s OR sci_name LIKE %s OR sci_name LIKE %s)';
			$params[] = $prefix . '%';
			$params[] = '% ' . $prefix . '%';
			$params[] = $prefix . '%';
			$params[] = '% ' . $prefix . '%';
		}
		if ( isset( $filters['group'] ) && '' !== trim( (string) $filters['group'] ) ) {
			$where[]  = 'group_name = %s';
			$params[] = trim( (string) $filters['group'] );
		}

		return array( implode( ' AND ', $where ), $params );
	}

	/**
	 * One species row.
	 *
	 * @param int $id Species id.
	 * @return array|null
	 */
	public function species( $id ) {
		return $this->species_row( 'id = %d', (int) $id );
	}

	/**
	 * The species row with a name (case-insensitive, as the unique key is).
	 *
	 * @param string $name Name.
	 * @return array|null
	 */
	public function species_by_name( $name ) {
		$name = trim( preg_replace( '/\s+/', ' ', (string) $name ) );
		return '' === $name ? null : $this->species_row( 'name = %s', $name );
	}

	/**
	 * The species row linked to a product.
	 *
	 * @param int $product_id Product id.
	 * @return array|null
	 */
	public function species_by_product( $product_id ) {
		return (int) $product_id > 0 ? $this->species_row( 'product_id = %d', (int) $product_id ) : null;
	}

	private function species_row( $where, $value ) {
		if ( ! self::tables_exist() ) {
			return null;
		}

		global $wpdb;

		try {
			$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table( 'species' ) . ' WHERE ' . $where . ' ORDER BY id ASC LIMIT 1', $value ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		} catch ( \Throwable $e ) {
			return null;
		}

		return $row ? self::hydrate_species( $row ) : null;
	}

	/**
	 * Every species row in brief (id, name, sci_name, product_id, per_box,
	 * my_price, group_name) — the candidate merge and the exports.
	 *
	 * @return array[]
	 */
	public function species_brief() {
		if ( ! self::tables_exist() ) {
			return array();
		}

		global $wpdb;

		try {
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT id, name, sci_name, group_name, per_box, my_price, product_id FROM ' . self::table( 'species' ) . ' ORDER BY name ASC LIMIT %d', self::SPECIES_LIMIT ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		} catch ( \Throwable $e ) {
			return array();
		}

		return array_map( array( self::class, 'hydrate_species' ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * The distinct species groups in use.
	 *
	 * @return string[]
	 */
	public function species_groups() {
		if ( ! self::tables_exist() ) {
			return array();
		}

		global $wpdb;

		try {
			$rows = $wpdb->get_col( 'SELECT DISTINCT group_name FROM ' . self::table( 'species' ) . " WHERE group_name <> '' ORDER BY group_name ASC" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		} catch ( \Throwable $e ) {
			return array();
		}

		return array_values( array_map( 'strval', is_array( $rows ) ? $rows : array() ) );
	}

	/**
	 * Insert or update a species row (Species::normalize() first; the
	 * name must be set and unique — the caller checks species_by_name(),
	 * the unique key is the net). Drops the candidate cache.
	 *
	 * @param array $fields The clean row (id 0 = insert).
	 * @return int|false Row id.
	 */
	public function save_species( array $fields ) {
		if ( ! self::tables_exist() || '' === trim( (string) ( $fields['name'] ?? '' ) ) ) {
			return false;
		}

		global $wpdb;

		$now  = gmdate( 'Y-m-d H:i:s' );
		$data = array(
			'name'         => (string) $fields['name'],
			'sci_name'     => (string) ( $fields['sci_name'] ?? '' ),
			'group_name'   => (string) ( $fields['group_name'] ?? '' ),
			'per_box'      => max( 1, (int) ( $fields['per_box'] ?? Costing::DEFAULT_PER_BOX ) ),
			'my_price'     => isset( $fields['my_price'] ) && null !== $fields['my_price'] ? round( (float) $fields['my_price'], 2 ) : null,
			'online_price' => isset( $fields['online_price'] ) && null !== $fields['online_price'] ? round( (float) $fields['online_price'], 2 ) : null,
			'online_site'  => isset( $fields['online_site'] ) && '' !== (string) $fields['online_site'] ? (string) $fields['online_site'] : null,
			'online_date'  => Replay::is_date( $fields['online_date'] ?? null ) ? (string) $fields['online_date'] : null,
			'reef_safe'    => in_array( (string) ( $fields['reef_safe'] ?? '' ), Species::REEF_SAFE, true ) ? (string) $fields['reef_safe'] : '',
			'max_size'     => (string) ( $fields['max_size'] ?? '' ),
			'product_id'   => ! empty( $fields['product_id'] ) ? (int) $fields['product_id'] : null,
			'notes'        => (string) ( $fields['notes'] ?? '' ),
			'updated_at'   => $now,
		);

		try {
			$id = (int) ( $fields['id'] ?? 0 );
			if ( $id > 0 ) {
				$ok = $wpdb->update( self::table( 'species' ), $data, array( 'id' => $id ), null, array( '%d' ) );
				$id = false === $ok ? false : $id;
			} else {
				$data['created_at'] = $now;
				$ok                 = $wpdb->insert( self::table( 'species' ), $data );
				$id                 = $ok ? (int) $wpdb->insert_id : false;
			}
		} catch ( \Throwable $e ) {
			return false;
		}

		self::clear_species_cache();

		return $id;
	}

	/**
	 * Delete a species row — unless an order line still names it, in
	 * which case nothing happens and 'referenced' comes back (the history
	 * keeps its species).
	 *
	 * @param int $id Species id.
	 * @return true|string|false True, 'referenced', or false on an error.
	 */
	public function delete_species( $id ) {
		if ( ! self::tables_exist() || (int) $id <= 0 ) {
			return false;
		}

		global $wpdb;

		try {
			$used = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::table( 'order_lines' ) . ' WHERE species_id = %d', (int) $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			if ( $used > 0 ) {
				return 'referenced';
			}
			$ok = $wpdb->delete( self::table( 'species' ), array( 'id' => (int) $id ), array( '%d' ) );
		} catch ( \Throwable $e ) {
			return false;
		}

		self::clear_species_cache();

		return (bool) $ok;
	}

	/**
	 * Link a species row to a product (0 unlinks). Whether the product
	 * exists is the caller's check (product_title()).
	 *
	 * @param int $id         Species id.
	 * @param int $product_id Product id (0 = none).
	 * @return bool
	 */
	public function link_species_product( $id, $product_id ) {
		if ( ! self::tables_exist() || (int) $id <= 0 ) {
			return false;
		}

		global $wpdb;

		try {
			$ok = $wpdb->update(
				self::table( 'species' ),
				array(
					'product_id' => (int) $product_id > 0 ? (int) $product_id : null,
					'updated_at' => gmdate( 'Y-m-d H:i:s' ),
				),
				array( 'id' => (int) $id ),
				null,
				array( '%d' )
			);
		} catch ( \Throwable $e ) {
			return false;
		}

		self::clear_species_cache();

		return false !== $ok;
	}

	/**
	 * Open stays per product id and per lower-cased species name, for the
	 * "open stays" column of the species list (one query).
	 *
	 * @return array{product: array<int, int>, name: array<string, int>}
	 */
	public function open_stay_counts() {
		$out = array(
			'product' => array(),
			'name'    => array(),
		);
		if ( ! self::tables_exist() ) {
			return $out;
		}

		global $wpdb;

		try {
			$rows = $wpdb->get_results( 'SELECT product_id, LOWER(species) AS name, COUNT(*) AS n FROM ' . self::table( 'stays' ) . " WHERE status <> 'closed' GROUP BY product_id, LOWER(species)", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		} catch ( \Throwable $e ) {
			return $out;
		}

		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$n = (int) $row['n'];
			if ( ! empty( $row['product_id'] ) ) {
				$out['product'][ (int) $row['product_id'] ] = ( $out['product'][ (int) $row['product_id'] ] ?? 0 ) + $n;
			}
			$key                 = Species::key( (string) $row['name'] );
			$out['name'][ $key ] = ( $out['name'][ $key ] ?? 0 ) + $n;
		}

		return $out;
	}

	/* ============================================================== */
	/*  Supplier orders                                                */
	/* ============================================================== */

	private static function order_select() {
		return 'SELECT o.*, (SELECT COUNT(*) FROM ' . self::table( 'order_lines' ) . ' l WHERE l.order_id = o.id) AS line_count, b.number AS building_number, b.name AS building_name FROM ' . self::table( 'orders' ) . ' o LEFT JOIN ' . self::table( 'buildings' ) . ' b ON b.id = o.building_id';
	}

	private static function line_select() {
		return 'SELECT l.*, sp.per_box AS species_per_box, sp.group_name AS species_group, sp.product_id AS species_product_id, sp.my_price AS species_my_price, st.qty_live AS stay_live, st.status AS stay_status, t.number AS tank_number FROM ' . self::table( 'order_lines' ) . ' l LEFT JOIN ' . self::table( 'species' ) . ' sp ON sp.id = l.species_id LEFT JOIN ' . self::table( 'stays' ) . ' st ON st.id = l.stay_id LEFT JOIN ' . self::table( 'tanks' ) . ' t ON t.id = l.tank_id';
	}

	/**
	 * The date an order sorts and files under: received, else ordered,
	 * else created.
	 *
	 * @return string SQL expression.
	 */
	private static function order_date_expr() {
		return 'COALESCE(o.received_date, o.order_date, DATE(o.created_at))';
	}

	/**
	 * Order headers, newest first.
	 *
	 * @param array $filters year (int), status (string), supplier (string), limit (int).
	 * @return array[] Hydrated headers (+ line_count).
	 */
	public function orders( array $filters = array() ) {
		if ( ! self::tables_exist() ) {
			return array();
		}

		global $wpdb;

		$where  = array( '1=1' );
		$params = array();
		if ( ! empty( $filters['year'] ) ) {
			$where[]  = 'YEAR(' . self::order_date_expr() . ') = %d';
			$params[] = (int) $filters['year'];
		}
		if ( ! empty( $filters['status'] ) && in_array( (string) $filters['status'], Orders::STATUSES, true ) ) {
			$where[]  = 'o.status = %s';
			$params[] = (string) $filters['status'];
		}
		if ( ! empty( $filters['supplier'] ) ) {
			$where[]  = 'o.supplier = %s';
			$params[] = (string) $filters['supplier'];
		}
		$params[] = isset( $filters['limit'] ) ? max( 1, min( self::ORDERS_LIMIT, (int) $filters['limit'] ) ) : self::ORDERS_LIMIT;

		try {
			$rows = $wpdb->get_results( $wpdb->prepare( self::order_select() . ' WHERE ' . implode( ' AND ', $where ) . ' ORDER BY ' . self::order_date_expr() . ' DESC, o.id DESC LIMIT %d', $params ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		} catch ( \Throwable $e ) {
			return array();
		}

		return array_map( array( self::class, 'hydrate_order' ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * The years that have orders, newest first.
	 *
	 * @return int[]
	 */
	public function order_years() {
		if ( ! self::tables_exist() ) {
			return array();
		}

		global $wpdb;

		try {
			$rows = $wpdb->get_col( 'SELECT DISTINCT YEAR(' . self::order_date_expr() . ') AS y FROM ' . self::table( 'orders' ) . ' o ORDER BY y DESC' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		} catch ( \Throwable $e ) {
			return array();
		}

		return array_values( array_filter( array_map( 'intval', is_array( $rows ) ? $rows : array() ) ) );
	}

	/**
	 * The refs in use (for the next "YYYY-N").
	 *
	 * @return string[]
	 */
	public function order_refs() {
		if ( ! self::tables_exist() ) {
			return array();
		}

		global $wpdb;

		try {
			$rows = $wpdb->get_col( 'SELECT ref FROM ' . self::table( 'orders' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		} catch ( \Throwable $e ) {
			return array();
		}

		return array_map( 'strval', is_array( $rows ) ? $rows : array() );
	}

	/**
	 * The distinct suppliers and origins already used.
	 *
	 * @return array{suppliers: string[], origins: string[]}
	 */
	public function order_pick_lists() {
		$out = array(
			'suppliers' => array(),
			'origins'   => array(),
		);
		if ( ! self::tables_exist() ) {
			return $out;
		}

		global $wpdb;

		try {
			$out['suppliers'] = array_map( 'strval', (array) $wpdb->get_col( 'SELECT DISTINCT supplier FROM ' . self::table( 'orders' ) . " WHERE supplier <> '' ORDER BY supplier ASC" ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$out['origins']   = array_map( 'strval', (array) $wpdb->get_col( 'SELECT DISTINCT origin FROM ' . self::table( 'orders' ) . " WHERE origin <> '' ORDER BY origin ASC" ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		} catch ( \Throwable $e ) {
			unset( $e );
		}

		return $out;
	}

	/**
	 * One order with its lines.
	 *
	 * @param int $id Order id.
	 * @return array|null
	 */
	public function order( $id ) {
		if ( ! self::tables_exist() || (int) $id <= 0 ) {
			return null;
		}

		global $wpdb;

		try {
			$row = $wpdb->get_row( $wpdb->prepare( self::order_select() . ' WHERE o.id = %d', (int) $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		} catch ( \Throwable $e ) {
			return null;
		}
		if ( ! $row ) {
			return null;
		}

		$order          = self::hydrate_order( $row );
		$order['lines'] = $this->order_lines( (int) $id );

		return $order;
	}

	/**
	 * The order with a ref.
	 *
	 * @param string $ref Ref.
	 * @return array|null Header (no lines).
	 */
	public function order_by_ref( $ref ) {
		$ref = trim( (string) $ref );
		if ( ! self::tables_exist() || '' === $ref ) {
			return null;
		}

		global $wpdb;

		try {
			$row = $wpdb->get_row( $wpdb->prepare( self::order_select() . ' WHERE o.ref = %s', $ref ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		} catch ( \Throwable $e ) {
			return null;
		}

		return $row ? self::hydrate_order( $row ) : null;
	}

	/**
	 * Insert or update an order header (Orders::normalize_order() first).
	 * The ref must be set and unique — the caller checks order_by_ref(),
	 * the unique key is the net.
	 *
	 * @param array $fields The clean header (id 0 = insert).
	 * @return int|false Row id.
	 */
	public function save_order( array $fields ) {
		if ( ! self::tables_exist() || '' === trim( (string) ( $fields['ref'] ?? '' ) ) ) {
			return false;
		}

		global $wpdb;

		$now  = gmdate( 'Y-m-d H:i:s' );
		$data = array(
			'ref'             => (string) $fields['ref'],
			'supplier'        => (string) ( $fields['supplier'] ?? '' ),
			'origin'          => (string) ( $fields['origin'] ?? '' ),
			'order_date'      => Replay::is_date( $fields['order_date'] ?? null ) ? (string) $fields['order_date'] : null,
			'received_date'   => Replay::is_date( $fields['received_date'] ?? null ) ? (string) $fields['received_date'] : null,
			'status'          => in_array( (string) ( $fields['status'] ?? '' ), Orders::STATUSES, true ) ? (string) $fields['status'] : 'draft',
			'boxes'           => isset( $fields['boxes'] ) && null !== $fields['boxes'] ? (int) $fields['boxes'] : null,
			'cost_mode'       => in_array( (string) ( $fields['cost_mode'] ?? '' ), Costing::MODES, true ) ? (string) $fields['cost_mode'] : 'box',
			'tariffs'         => round( (float) ( $fields['tariffs'] ?? 0 ), 2 ),
			'domestic_flight' => round( (float) ( $fields['domestic_flight'] ?? 0 ), 2 ),
			'import_fees'     => round( (float) ( $fields['import_fees'] ?? 0 ), 2 ),
			'credit'          => round( (float) ( $fields['credit'] ?? 0 ), 2 ),
			'markup'          => round( (float) ( $fields['markup'] ?? Costing::DEFAULT_MARKUP ), 2 ),
			'worth_threshold' => round( (float) ( $fields['worth_threshold'] ?? Costing::DEFAULT_THRESHOLD ), 2 ),
			'schedule'        => (string) ( $fields['schedule'] ?? '' ),
			'building_id'     => ! empty( $fields['building_id'] ) ? (int) $fields['building_id'] : null,
			'notes'           => (string) ( $fields['notes'] ?? '' ),
			'updated_at'      => $now,
		);

		try {
			$id = (int) ( $fields['id'] ?? 0 );
			if ( $id > 0 ) {
				$ok = $wpdb->update( self::table( 'orders' ), $data, array( 'id' => $id ), null, array( '%d' ) );
				return false === $ok ? false : $id;
			}
			$data['created_at'] = $now;
			$ok                 = $wpdb->insert( self::table( 'orders' ), $data );
			return $ok ? (int) $wpdb->insert_id : false;
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	/**
	 * Change some header fields of an order (status, received_date, …) —
	 * what the check-in does.
	 *
	 * @param int   $id     Order id.
	 * @param array $fields Any header column except id / ref / created_at.
	 * @return bool
	 */
	public function update_order_fields( $id, array $fields ) {
		if ( ! self::tables_exist() || (int) $id <= 0 ) {
			return false;
		}

		$data = array();
		foreach ( array( 'supplier', 'origin', 'status', 'cost_mode', 'schedule', 'notes' ) as $key ) {
			if ( array_key_exists( $key, $fields ) ) {
				$data[ $key ] = (string) $fields[ $key ];
			}
		}
		foreach ( array( 'order_date', 'received_date' ) as $key ) {
			if ( array_key_exists( $key, $fields ) ) {
				$data[ $key ] = Replay::is_date( $fields[ $key ] ) ? (string) $fields[ $key ] : null;
			}
		}
		foreach ( array( 'tariffs', 'domestic_flight', 'import_fees', 'credit', 'markup', 'worth_threshold' ) as $key ) {
			if ( array_key_exists( $key, $fields ) ) {
				$data[ $key ] = round( (float) $fields[ $key ], 2 );
			}
		}
		foreach ( array( 'boxes', 'building_id' ) as $key ) {
			if ( array_key_exists( $key, $fields ) ) {
				$data[ $key ] = null === $fields[ $key ] || (int) $fields[ $key ] <= 0 ? null : (int) $fields[ $key ];
			}
		}
		if ( isset( $data['status'] ) && ! in_array( $data['status'], Orders::STATUSES, true ) ) {
			unset( $data['status'] );
		}
		if ( ! $data ) {
			return false;
		}
		$data['updated_at'] = gmdate( 'Y-m-d H:i:s' );

		global $wpdb;

		try {
			return false !== $wpdb->update( self::table( 'orders' ), $data, array( 'id' => (int) $id ), null, array( '%d' ) );
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	/**
	 * Delete an order and its lines — unless a line has a stay (the stays
	 * point at the line), in which case nothing happens and 'referenced'
	 * comes back.
	 *
	 * @param int $id Order id.
	 * @return true|string|false
	 */
	public function delete_order( $id ) {
		if ( ! self::tables_exist() || (int) $id <= 0 ) {
			return false;
		}

		global $wpdb;

		try {
			$linked = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::table( 'order_lines' ) . ' WHERE order_id = %d AND stay_id IS NOT NULL', (int) $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			if ( $linked > 0 ) {
				return 'referenced';
			}
			$wpdb->delete( self::table( 'order_lines' ), array( 'order_id' => (int) $id ), array( '%d' ) );
			$ok = $wpdb->delete( self::table( 'orders' ), array( 'id' => (int) $id ), array( '%d' ) );
		} catch ( \Throwable $e ) {
			return false;
		}

		return (bool) $ok;
	}

	/**
	 * The lines of an order, in sheet order, with the species row's per
	 * box / group / product, the stay's live count and the tank number.
	 *
	 * @param int $order_id Order id.
	 * @return array[]
	 */
	public function order_lines( $order_id ) {
		$by_order = $this->lines_for_orders( array( (int) $order_id ) );
		return isset( $by_order[ (int) $order_id ] ) ? $by_order[ (int) $order_id ] : array();
	}

	/**
	 * The lines of several orders in one query, grouped by order id.
	 *
	 * @param int[] $order_ids Order ids.
	 * @return array<int, array[]>
	 */
	public function lines_for_orders( array $order_ids ) {
		$order_ids = array_values( array_unique( array_filter( array_map( 'intval', $order_ids ) ) ) );
		if ( ! self::tables_exist() || ! $order_ids ) {
			return array();
		}

		global $wpdb;

		try {
			$rows = $wpdb->get_results( self::line_select() . ' WHERE l.order_id IN (' . implode( ',', $order_ids ) . ') ORDER BY l.order_id ASC, l.sort ASC, l.id ASC', ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		} catch ( \Throwable $e ) {
			return array();
		}

		$out = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$line                         = self::hydrate_line( $row );
			$out[ $line['order_id'] ][]   = $line;
		}

		return $out;
	}

	/**
	 * Save an order's lines: rows with an id that belongs to the order
	 * are updated, rows without one inserted, the order's other lines
	 * deleted — except lines that already became a stay, which are kept
	 * and keep their received / DOA / survived figures (those live on the
	 * stay now). Orders::normalize_lines() first.
	 *
	 * @param int     $order_id Order id.
	 * @param array[] $lines    Clean lines (id 0 = new), each may carry landed_total.
	 * @return array{ids: int[], deleted: int}|false
	 */
	public function save_order_lines( $order_id, array $lines ) {
		if ( ! self::tables_exist() || (int) $order_id <= 0 ) {
			return false;
		}

		global $wpdb;

		$existing = array();
		foreach ( $this->order_lines( (int) $order_id ) as $line ) {
			$existing[ (int) $line['id'] ] = $line;
		}

		$ids = array();
		try {
			foreach ( array_values( $lines ) as $sort => $line ) {
				$id      = (int) ( $line['id'] ?? 0 );
				$current = $id > 0 && isset( $existing[ $id ] ) ? $existing[ $id ] : null;
				$linked  = $current && ! empty( $current['stay_id'] );
				$data    = array(
					'order_id'     => (int) $order_id,
					'species_id'   => ! empty( $line['species_id'] ) ? (int) $line['species_id'] : null,
					'species'      => (string) ( $line['species'] ?? '' ),
					'sci_name'     => (string) ( $line['sci_name'] ?? '' ),
					'qty_ordered'  => max( 0, (int) ( $line['qty_ordered'] ?? 0 ) ),
					'qty_received' => $linked ? $current['qty_received'] : ( isset( $line['qty_received'] ) && null !== $line['qty_received'] ? (int) $line['qty_received'] : null ),
					'per_box'      => isset( $line['per_box'] ) && null !== $line['per_box'] ? (int) $line['per_box'] : null,
					'bags'         => isset( $line['bags'] ) && null !== $line['bags'] ? (int) $line['bags'] : null,
					'unit_price'   => isset( $line['unit_price'] ) && null !== $line['unit_price'] ? round( (float) $line['unit_price'], 4 ) : null,
					'worth'        => isset( $line['worth'] ) && null !== $line['worth'] ? round( (float) $line['worth'], 2 ) : null,
					'qty_doa'      => $linked ? (int) $current['qty_doa'] : max( 0, (int) ( $line['qty_doa'] ?? 0 ) ),
					'qty_survived' => $linked ? $current['qty_survived'] : ( isset( $line['qty_survived'] ) && null !== $line['qty_survived'] ? (int) $line['qty_survived'] : null ),
					'tank_id'      => $linked ? $current['tank_id'] : ( ! empty( $line['tank_id'] ) ? (int) $line['tank_id'] : null ),
					'sort'         => (int) $sort,
					'notes'        => (string) ( $line['notes'] ?? '' ),
				);
				if ( array_key_exists( 'landed_total', $line ) ) {
					$data['landed_total'] = null !== $line['landed_total'] ? round( (float) $line['landed_total'], 2 ) : null;
				}
				if ( $current ) {
					$ok = $wpdb->update( self::table( 'order_lines' ), $data, array( 'id' => $id ), null, array( '%d' ) );
					if ( false !== $ok ) {
						$ids[] = $id;
					}
					unset( $existing[ $id ] );
					continue;
				}
				$ok = $wpdb->insert( self::table( 'order_lines' ), $data );
				if ( $ok ) {
					$ids[] = (int) $wpdb->insert_id;
				}
			}

			$deleted = 0;
			foreach ( $existing as $id => $line ) {
				if ( ! empty( $line['stay_id'] ) ) {
					$ids[] = (int) $id; // A line that became a stay stays.
					continue;
				}
				if ( $wpdb->delete( self::table( 'order_lines' ), array( 'id' => (int) $id ), array( '%d' ) ) ) {
					$deleted++;
				}
			}
		} catch ( \Throwable $e ) {
			return false;
		}

		return array(
			'ids'     => $ids,
			'deleted' => $deleted,
		);
	}

	/**
	 * Cache a line's landed total (the costing's figure, to the cent) so
	 * an export can show it without recomputing.
	 *
	 * @param int        $line_id Line id.
	 * @param float|null $landed  Landed total.
	 * @return bool
	 */
	public function cache_line_total( $line_id, $landed ) {
		if ( ! self::tables_exist() || (int) $line_id <= 0 ) {
			return false;
		}

		global $wpdb;

		try {
			return false !== $wpdb->update( self::table( 'order_lines' ), array( 'landed_total' => null === $landed ? null : round( (float) $landed, 2 ) ), array( 'id' => (int) $line_id ), null, array( '%d' ) );
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	/**
	 * Link a line to the stay it became (and the tank it went into).
	 *
	 * @param int      $line_id Line id.
	 * @param int      $stay_id Stay id.
	 * @param int|null $tank_id Tank id.
	 * @return bool
	 */
	public function set_line_stay( $line_id, $stay_id, $tank_id = null ) {
		if ( ! self::tables_exist() || (int) $line_id <= 0 ) {
			return false;
		}

		global $wpdb;

		$data = array( 'stay_id' => (int) $stay_id > 0 ? (int) $stay_id : null );
		if ( null !== $tank_id ) {
			$data['tank_id'] = (int) $tank_id > 0 ? (int) $tank_id : null;
		}

		try {
			return false !== $wpdb->update( self::table( 'order_lines' ), $data, array( 'id' => (int) $line_id ), null, array( '%d' ) );
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	/**
	 * The live figures of a set of stays in one query over their events:
	 * stay_id → arrived, lost, sold, doa, live, survived
	 * (Orders::figures_from_events()).
	 *
	 * @param int[] $stay_ids Stay ids.
	 * @return array<int, array>
	 */
	public function stay_figures( array $stay_ids ) {
		$stay_ids = array_values( array_unique( array_filter( array_map( 'intval', $stay_ids ) ) ) );
		if ( ! self::tables_exist() || ! $stay_ids ) {
			return array();
		}

		global $wpdb;

		try {
			$rows = $wpdb->get_results( 'SELECT id, stay_id, type, qty, note, event_at FROM ' . self::table( 'stay_events' ) . ' WHERE stay_id IN (' . implode( ',', $stay_ids ) . ') ORDER BY event_at ASC, id ASC', ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		} catch ( \Throwable $e ) {
			return array();
		}

		$by_stay = array();
		foreach ( $stay_ids as $id ) {
			$by_stay[ $id ] = array();
		}
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$by_stay[ (int) $row['stay_id'] ][] = self::hydrate_event( $row );
		}

		$out = array();
		foreach ( $by_stay as $id => $events ) {
			$out[ $id ] = Orders::figures_from_events( $events );
		}

		return $out;
	}

	/* ============================================================== */
	/*  Product facts                                                  */
	/* ============================================================== */

	/**
	 * What the fish page will read: how many are alive here, the stays
	 * in QT (with day numbers), the cleared stays (with their sale-ready
	 * dates), and 'next' — the stay that can ship first: a sale-ready one
	 * with fish left, else the one with the earliest sale-ready date.
	 *
	 * @param int    $product_id Product ID.
	 * @param string $today      Site date.
	 * @param array  $settings   Settings.
	 * @return array{live_total: int, ready_total: int, in_qt: array[], cleared: array[], next: array|null}
	 */
	public function product_facts( $product_id, $today, array $settings ) {
		$facts = array(
			'live_total'  => 0,
			'ready_total' => 0,
			'in_qt'       => array(),
			'cleared'     => array(),
			'next'        => null,
		);

		$candidates = array();
		foreach ( $this->stays_for_product( $product_id, $today, $settings ) as $stay ) {
			if ( 'closed' === $stay['status'] || (int) $stay['qty_live'] <= 0 ) {
				continue;
			}
			$entry = array(
				'stay_id'         => (int) $stay['id'],
				'qty_live'        => (int) $stay['qty_live'],
				'checkin_date'    => (string) $stay['checkin_date'],
				'cleared_date'    => $stay['cleared_date'],
				'sale_ready_date' => (string) $stay['sale_ready_date'],
				'sale_ready'      => ! empty( $stay['sale_ready'] ),
				'day'             => $stay['day'],
				'tank'            => (string) $stay['tank_path'],
			);
			$facts['live_total'] += (int) $stay['qty_live'];
			if ( 'cleared' === $stay['status'] ) {
				$facts['cleared'][] = $entry;
				if ( $entry['sale_ready'] ) {
					$facts['ready_total'] += (int) $stay['qty_live'];
				}
			} else {
				$facts['in_qt'][] = $entry;
			}
			$candidates[] = $entry;
		}

		usort(
			$candidates,
			static function ( $a, $b ) {
				if ( $a['sale_ready'] !== $b['sale_ready'] ) {
					return $a['sale_ready'] ? -1 : 1;
				}
				$d = strcmp( $a['sale_ready_date'], $b['sale_ready_date'] );
				return 0 !== $d ? $d : strcmp( $a['checkin_date'], $b['checkin_date'] );
			}
		);
		if ( $candidates ) {
			$first          = $candidates[0];
			$facts['next']  = array(
				'checkin_date'    => $first['checkin_date'],
				'cleared_date'    => $first['cleared_date'],
				'sale_ready_date' => $first['sale_ready_date'],
				'sale_ready'      => $first['sale_ready'],
				'day'             => $first['day'],
				'tank'            => $first['tank'],
				'qty_live'        => $first['qty_live'],
			);
		}

		return $facts;
	}

	/* ============================================================== */
	/*  Hydration                                                      */
	/* ============================================================== */

	/**
	 * A UTC 'Y-m-d H:i:s' from anything strtotime understands (now when not).
	 *
	 * @param mixed $value Datetime.
	 * @return string
	 */
	public static function utc_datetime( $value ) {
		if ( is_string( $value ) && '' !== $value ) {
			$ts = strtotime( $value . ' UTC' );
			if ( false !== $ts ) {
				return gmdate( 'Y-m-d H:i:s', $ts );
			}
		}
		return gmdate( 'Y-m-d H:i:s' );
	}

	public static function hydrate_building( array $row ) {
		return array(
			'id'             => (int) ( $row['id'] ?? 0 ),
			'area'           => (string) ( $row['area'] ?? 'clean' ),
			'number'         => (int) ( $row['number'] ?? 0 ),
			'name'           => (string) ( $row['name'] ?? '' ),
			'status'         => (string) ( $row['status'] ?? 'active' ),
			'sort'           => (int) ( $row['sort'] ?? 0 ),
			'notes'          => (string) ( $row['notes'] ?? '' ),
			'schedule'       => (string) ( $row['schedule'] ?? '' ),
			'system_gallons' => isset( $row['system_gallons'] ) && null !== $row['system_gallons'] && '' !== $row['system_gallons'] ? (int) $row['system_gallons'] : null,
		);
	}

	public static function hydrate_tank( array $row ) {
		$building = array(
			'id'     => (int) ( $row['building_id'] ?? 0 ),
			'area'   => (string) ( $row['building_area'] ?? 'clean' ),
			'number' => (int) ( $row['building_number'] ?? 0 ),
			'name'   => (string) ( $row['building_name'] ?? '' ),
			'status' => (string) ( $row['building_status'] ?? 'active' ),
		);
		$tank     = array(
			'id'          => (int) ( $row['id'] ?? 0 ),
			'building_id' => (int) ( $row['building_id'] ?? 0 ),
			'number'      => (int) ( $row['number'] ?? 0 ),
			'level'       => (int) ( $row['level'] ?? 1 ),
			'position'    => (int) ( $row['position'] ?? 1 ),
			'size_label'  => (string) ( $row['size_label'] ?? '' ),
			'gallons'     => (int) ( $row['gallons'] ?? 0 ),
			'status'      => (string) ( $row['status'] ?? 'active' ),
			'sort'        => (int) ( $row['sort'] ?? 0 ),
			'building'    => $building,
		);
		$tank['path'] = Layout::tank_path( $tank, $building );

		return $tank;
	}

	/**
	 * A stay row with ints, its tank path and the effective state today.
	 *
	 * @param array  $row      Raw row (joined).
	 * @param string $today    Site date ('' = skip the day math).
	 * @param array  $settings Settings.
	 * @return array
	 */
	public static function hydrate_stay( array $row, $today = '', array $settings = array() ) {
		$building = array(
			'id'     => (int) ( $row['building_id'] ?? 0 ),
			'area'   => (string) ( $row['building_area'] ?? '' ),
			'number' => (int) ( $row['building_number'] ?? 0 ),
			'name'   => (string) ( $row['building_name'] ?? '' ),
		);
		$tank     = array(
			'id'         => (int) ( $row['tank_id'] ?? 0 ),
			'number'     => (int) ( $row['tank_number'] ?? 0 ),
			'size_label' => (string) ( $row['tank_size'] ?? '' ),
		);
		$date     = static function ( $value ) {
			return ! empty( $value ) && '0000-00-00' !== $value ? (string) $value : null;
		};

		$stay = array(
			'id'                  => (int) ( $row['id'] ?? 0 ),
			'product_id'          => ! empty( $row['product_id'] ) ? (int) $row['product_id'] : null,
			'species'             => (string) ( $row['species'] ?? '' ),
			'qty_arrived'         => (int) ( $row['qty_arrived'] ?? 0 ),
			'qty_live'            => (int) ( $row['qty_live'] ?? 0 ),
			'checkin_date'        => (string) ( $row['checkin_date'] ?? '' ),
			'cleared_date'        => $date( $row['cleared_date'] ?? null ),
			'sale_ready_override' => $date( $row['sale_ready_date'] ?? null ),
			'closed_date'         => $date( $row['closed_date'] ?? null ),
			'stored_status'       => (string) ( $row['status'] ?? 'in_qt' ),
			'tank_id'             => ! empty( $row['tank_id'] ) ? (int) $row['tank_id'] : null,
			'tank_number'         => $tank['number'] > 0 ? $tank['number'] : null,
			'tank_path'           => $tank['number'] > 0 ? Layout::tank_path( $tank, $building ) : '',
			'building_id'         => $building['id'] > 0 ? $building['id'] : null,
			'area'                => $building['area'],
			'source'              => (string) ( $row['source'] ?? 'manual' ),
			'order_line_id'       => ! empty( $row['order_line_id'] ) ? (int) $row['order_line_id'] : null,
			'supplier'            => isset( $row['supplier'] ) && null !== $row['supplier'] ? (string) $row['supplier'] : null,
			'order_ref'           => isset( $row['order_ref'] ) && null !== $row['order_ref'] ? (string) $row['order_ref'] : null,
			'landed_cost'         => isset( $row['landed_cost'] ) && null !== $row['landed_cost'] ? (float) $row['landed_cost'] : null,
			'notes'               => (string) ( $row['notes'] ?? '' ),
			'created_at'          => (string) ( $row['created_at'] ?? '' ),
			'updated_at'          => (string) ( $row['updated_at'] ?? '' ),
		);

		$effective = Replay::effective(
			array(
				'status'          => $stay['stored_status'],
				'checkin_date'    => $stay['checkin_date'],
				'cleared_date'    => $stay['cleared_date'],
				'sale_ready_date' => $stay['sale_ready_override'],
			),
			'' !== (string) $today ? (string) $today : gmdate( 'Y-m-d' ),
			array( 'sale_ready_days' => (int) ( $settings['sale_ready_days'] ?? Replay::DEFAULT_SALE_READY_DAYS ) )
		);

		return array_merge( $stay, $effective );
	}

	public static function hydrate_event( array $row ) {
		return array(
			'id'               => (int) ( $row['id'] ?? 0 ),
			'stay_id'          => (int) ( $row['stay_id'] ?? 0 ),
			'type'             => (string) ( $row['type'] ?? '' ),
			'qty'              => isset( $row['qty'] ) && null !== $row['qty'] ? (int) $row['qty'] : null,
			'from_tank_id'     => ! empty( $row['from_tank_id'] ) ? (int) $row['from_tank_id'] : null,
			'to_tank_id'       => ! empty( $row['to_tank_id'] ) ? (int) $row['to_tank_id'] : null,
			'from_tank_number' => ! empty( $row['from_tank_number'] ) ? (int) $row['from_tank_number'] : null,
			'to_tank_number'   => ! empty( $row['to_tank_number'] ) ? (int) $row['to_tank_number'] : null,
			'note'             => (string) ( $row['note'] ?? '' ),
			'event_at'         => (string) ( $row['event_at'] ?? '' ),
			'actor_id'         => (int) ( $row['actor_id'] ?? 0 ),
			'actor_name'       => (string) ( $row['actor_name'] ?? '' ),
			'source'           => (string) ( $row['source'] ?? 'manual' ),
			'created_at'       => (string) ( $row['created_at'] ?? '' ),
			'species'          => (string) ( $row['stay_species'] ?? '' ),
			'product_id'       => ! empty( $row['stay_product_id'] ) ? (int) $row['stay_product_id'] : null,
		);
	}

	public static function hydrate_log( array $row ) {
		return array(
			'id'          => (int) ( $row['id'] ?? 0 ),
			'tank_id'     => (int) ( $row['tank_id'] ?? 0 ),
			'building_id' => ! empty( $row['building_id'] ) ? (int) $row['building_id'] : null,
			'tank_number' => ! empty( $row['tank_number'] ) ? (int) $row['tank_number'] : null,
			'kind'        => (string) ( $row['kind'] ?? 'note' ),
			'value'       => isset( $row['value'] ) && null !== $row['value'] ? (float) $row['value'] : null,
			'note'        => (string) ( $row['note'] ?? '' ),
			'logged_at'   => (string) ( $row['logged_at'] ?? '' ),
			'actor_id'    => (int) ( $row['actor_id'] ?? 0 ),
			'actor_name'  => (string) ( $row['actor_name'] ?? '' ),
			'source'      => (string) ( $row['source'] ?? 'manual' ),
		);
	}

	/**
	 * A nullable money column.
	 *
	 * @param mixed $value Raw.
	 * @return float|null
	 */
	private static function money_or_null( $value ) {
		return isset( $value ) && null !== $value && '' !== $value && is_numeric( $value ) ? (float) $value : null;
	}

	/**
	 * A nullable int column.
	 *
	 * @param mixed $value Raw.
	 * @return int|null
	 */
	private static function int_or_null( $value ) {
		return isset( $value ) && null !== $value && '' !== $value && is_numeric( $value ) ? (int) $value : null;
	}

	/**
	 * A nullable date column ('0000-00-00' = null).
	 *
	 * @param mixed $value Raw.
	 * @return string|null
	 */
	private static function date_or_null( $value ) {
		return ! empty( $value ) && '0000-00-00' !== $value ? (string) $value : null;
	}

	public static function hydrate_species( array $row ) {
		return array(
			'id'           => (int) ( $row['id'] ?? 0 ),
			'name'         => (string) ( $row['name'] ?? '' ),
			'sci_name'     => (string) ( $row['sci_name'] ?? '' ),
			'group_name'   => (string) ( $row['group_name'] ?? '' ),
			'per_box'      => max( 1, (int) ( $row['per_box'] ?? Costing::DEFAULT_PER_BOX ) ),
			'my_price'     => self::money_or_null( $row['my_price'] ?? null ),
			'online_price' => self::money_or_null( $row['online_price'] ?? null ),
			'online_site'  => isset( $row['online_site'] ) && null !== $row['online_site'] && '' !== $row['online_site'] ? (string) $row['online_site'] : null,
			'online_date'  => self::date_or_null( $row['online_date'] ?? null ),
			'reef_safe'    => (string) ( $row['reef_safe'] ?? '' ),
			'max_size'     => (string) ( $row['max_size'] ?? '' ),
			'product_id'   => ! empty( $row['product_id'] ) ? (int) $row['product_id'] : null,
			'notes'        => (string) ( $row['notes'] ?? '' ),
			'created_at'   => (string) ( $row['created_at'] ?? '' ),
			'updated_at'   => (string) ( $row['updated_at'] ?? '' ),
		);
	}

	public static function hydrate_order( array $row ) {
		return array(
			'id'              => (int) ( $row['id'] ?? 0 ),
			'ref'             => (string) ( $row['ref'] ?? '' ),
			'supplier'        => (string) ( $row['supplier'] ?? '' ),
			'origin'          => (string) ( $row['origin'] ?? '' ),
			'order_date'      => self::date_or_null( $row['order_date'] ?? null ),
			'received_date'   => self::date_or_null( $row['received_date'] ?? null ),
			'status'          => (string) ( $row['status'] ?? 'draft' ),
			'boxes'           => self::int_or_null( $row['boxes'] ?? null ),
			'cost_mode'       => 'bag' === (string) ( $row['cost_mode'] ?? 'box' ) ? 'bag' : 'box',
			'tariffs'         => (float) ( $row['tariffs'] ?? 0 ),
			'domestic_flight' => (float) ( $row['domestic_flight'] ?? 0 ),
			'import_fees'     => (float) ( $row['import_fees'] ?? 0 ),
			'credit'          => (float) ( $row['credit'] ?? 0 ),
			'markup'          => (float) ( $row['markup'] ?? Costing::DEFAULT_MARKUP ),
			'worth_threshold' => (float) ( $row['worth_threshold'] ?? Costing::DEFAULT_THRESHOLD ),
			'schedule'        => (string) ( $row['schedule'] ?? '' ),
			'building_id'     => ! empty( $row['building_id'] ) ? (int) $row['building_id'] : null,
			'building_number' => ! empty( $row['building_number'] ) ? (int) $row['building_number'] : null,
			'building_name'   => (string) ( $row['building_name'] ?? '' ),
			'notes'           => (string) ( $row['notes'] ?? '' ),
			'line_count'      => (int) ( $row['line_count'] ?? 0 ),
			'created_at'      => (string) ( $row['created_at'] ?? '' ),
			'updated_at'      => (string) ( $row['updated_at'] ?? '' ),
		);
	}

	public static function hydrate_line( array $row ) {
		return array(
			'id'                 => (int) ( $row['id'] ?? 0 ),
			'order_id'           => (int) ( $row['order_id'] ?? 0 ),
			'species_id'         => ! empty( $row['species_id'] ) ? (int) $row['species_id'] : null,
			'species'            => (string) ( $row['species'] ?? '' ),
			'sci_name'           => (string) ( $row['sci_name'] ?? '' ),
			'qty_ordered'        => (int) ( $row['qty_ordered'] ?? 0 ),
			'qty_received'       => self::int_or_null( $row['qty_received'] ?? null ),
			'per_box'            => self::int_or_null( $row['per_box'] ?? null ),
			'bags'               => self::int_or_null( $row['bags'] ?? null ),
			'unit_price'         => self::money_or_null( $row['unit_price'] ?? null ),
			'worth'              => self::money_or_null( $row['worth'] ?? null ),
			'qty_doa'            => (int) ( $row['qty_doa'] ?? 0 ),
			'qty_survived'       => self::int_or_null( $row['qty_survived'] ?? null ),
			'landed_cached'      => self::money_or_null( $row['landed_total'] ?? null ),
			'stay_id'            => ! empty( $row['stay_id'] ) ? (int) $row['stay_id'] : null,
			'tank_id'            => ! empty( $row['tank_id'] ) ? (int) $row['tank_id'] : null,
			'tank_number'        => ! empty( $row['tank_number'] ) ? (int) $row['tank_number'] : null,
			'sort'               => (int) ( $row['sort'] ?? 0 ),
			'notes'              => (string) ( $row['notes'] ?? '' ),
			'species_per_box'    => self::int_or_null( $row['species_per_box'] ?? null ),
			'group_name'         => (string) ( $row['species_group'] ?? '' ),
			'species_product_id' => ! empty( $row['species_product_id'] ) ? (int) $row['species_product_id'] : null,
			'species_my_price'   => self::money_or_null( $row['species_my_price'] ?? null ),
			'stay_live'          => self::int_or_null( $row['stay_live'] ?? null ),
			'stay_status'        => isset( $row['stay_status'] ) && null !== $row['stay_status'] ? (string) $row['stay_status'] : null,
		);
	}
}
