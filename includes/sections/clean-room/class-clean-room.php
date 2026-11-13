<?php
/**
 * Clean Room section — where every fish physically is, from the day it
 * lands to the day it ships: a seeded, editable layout of buildings and
 * tanks (hundreds = building, tens = level, ones = position), stays with
 * an event history that is replayed (never edited), a phone screen driven
 * by QR labels and voice (the Front Desk), a dark Room Board under
 * FisHotel Tools, printable QR labels, and the facts the fish page will
 * read later.
 *
 * WHY this exists: a fish arrives, moves, dies, clears quarantine and
 * ships, and until now none of that was written down anywhere a phone
 * with wet hands could reach. One QR on every tank opens that tank's
 * screen; one sentence ("four pajama cardinalfish in tank 121") becomes an
 * event; the product page can then say "3 in QT · day 12 · next cleared …".
 *
 * Architecture:
 *   - class-store.php       the ONLY file that touches $wpdb: ten tables
 *                           (buildings, tanks, stays, stay_events, tank_logs,
 *                           aliases, species, orders, order_lines,
 *                           box_shares) via dbDelta gated by
 *                           fishotel_cleanroom_db_version, the seed,
 *                           record_event() = append + replay, species
 *                           suggestions, product facts.
 *   - class-layout.php      PURE: the numbering rule, the seed, shares-water
 *                           lists, titles / labels, grouping.
 *   - class-replay.php      PURE: events → stay state (alive = received −
 *                           losses − shipped, recount overrides; cleared by
 *                           the first move from the Spa into the Clean Room;
 *                           for sale from a CHOSEN date — the stay's own,
 *                           else its batch's on the supplier order; splits),
 *                           the day math, the compensating (undo) event.
 *   - class-protocol.php    PURE: the editable treatment template → each
 *                           tank's daily to-do (feeding + due steps). It
 *                           loads the next two itself:
 *   - class-medications.php PURE: the editable medication list (seeded from
 *                           the fixed one; kept in the settings option).
 *   - class-dose.php        the dose sum (per gallon, to a level, the
 *                           top-up), what the room fills in, the working
 *                           as the dose log's note.
 *   - class-parser.php      PURE: a spoken / typed phrase → structured
 *                           preview (verbs incl. loss / shipped / copper,
 *                           number words, tank references, fuzzy species
 *                           with confidence, Spa-only arrivals).
 *   - class-qr.php          PURE: byte-mode QR encoder (EC M, v1–6) → SVG.
 *   - class-settings.php    option storage, sanitised AJAX save, the layout
 *                           editor and alias actions (manage_options).
 *   - class-front-desk.php  the phone page (query var fh_front_desk) and the
 *                           act / parse / suggest AJAX (manage_woocommerce).
 *   - class-admin.php       the Room Board page (Board / Stays / Events /
 *                           Settings), CSV, the label print page, the
 *                           product meta box.
 *   - class-costing.php     PURE: the supplier order's landed-cost maths,
 *                           replicating the workbook's order sheet; a line's
 *                           percent of a box, and the per-box summary.
 *   - class-sizes.php       PURE: the standard fish sizes (the product
 *                           variations' "size" attribute, else a typed list).
 *   - class-box-shares.php  the remembered shares: the percent of a box one
 *                           fish takes per supplier + origin + fish + size.
 *   - class-stats.php       PURE: the yearly figures, weighted and as the
 *                           spreadsheet averaged them; years side by side;
 *                           one row per fish and year.
 *   - class-stats-prices.php  PURE: the fish priced under what their latest
 *                           supplier order suggests (read-only).
 *   - class-stats-sales.php   what customers actually paid per fish and year,
 *                           from WooCommerce's paid orders (pure maths; the
 *                           statuses that count and the cache flush).
 *   - class-species.php     PURE: the species master row and its merge into
 *                           the candidate list.
 *   - class-identify.php    unknown ("??") and mislabeled fish: the one
 *                           correction of a stay and its order line together,
 *                           what the label said, the split rule.
 *   - class-orders.php      the order from a form, the live figures of a
 *                           checked-in line, the check-in that creates stays.
 *   - class-xlsx.php        a dependency-free .xlsx writer.
 *   - class-workbook.php    PURE: a dependency-free .xlsx / .xlsm reader
 *                           (cached values only; the PW sheet is never opened).
 *   - class-import-map.php  PURE: the FisHotel-YEAR workbook layout → species,
 *                           orders, box rates, stock (columns found by header text).
 *   - class-import.php      the mapped workbook into the Store (species upsert,
 *                           box rates, orders + lines, recent batches as stays,
 *                           treatment logs), idempotent; preview() pure.
 *   - class-admin-workbook.php  the Arrivals / Species / Stats tabs, their
 *                           AJAX and the XLSX / CSV exports.
 *   - class-admin-stats.php  the Stats tab's further views — Compare years,
 *                           Species, Prices to raise, Real sales — and their
 *                           exports.
 *   - class-admin-import.php  Settings → Import workbook: upload to a private
 *                           folder, preview, Import now (manage_options).
 *   - class-medications-admin.php  Settings → Medications' save and the
 *                           Board's Calculator (hooked from Settings::init()).
 *
 * The file uses bracketed namespaces because the facts helper must live in
 * the global namespace.
 *
 * @package FisHotel\Misc\Sections\Clean_Room
 */

namespace FisHotel\Misc\Sections\Clean_Room {

	defined( 'ABSPATH' ) || exit;

	/**
	 * Class Clean_Room
	 */
	class Clean_Room {

		/**
		 * Settings option key.
		 *
		 * @var string
		 */
		const OPTION_KEY = 'fishotel_cleanroom_settings';

		/**
		 * Admin submenu slug.
		 *
		 * @var string
		 */
		const MENU_SLUG = 'fishotel-misc-clean-room';

		/**
		 * Capability for the board, the phone screen and every stay write.
		 *
		 * @var string
		 */
		const CAPABILITY = 'manage_woocommerce';

		/**
		 * Capability for the settings, the layout editor and the aliases.
		 *
		 * @var string
		 */
		const SETTINGS_CAPABILITY = 'manage_options';

		/**
		 * The front-end query var that opens the phone screen.
		 *
		 * @var string
		 */
		const QUERY_VAR = 'fh_front_desk';

		/**
		 * The filter other code can read the per-product facts through.
		 *
		 * @var string
		 */
		const FACTS_FILTER = 'fishotel_product_qt_facts';

		/**
		 * The one-off cron event that deletes an uploaded workbook whose
		 * import preview expired (Admin_Import).
		 *
		 * @var string
		 */
		const IMPORT_CLEANUP_HOOK = 'fishotel_cleanroom_import_cleanup';

		/**
		 * Section base path.
		 *
		 * @return string
		 */
		public static function path() {
			return FISHOTEL_MISC_PATH . 'includes/sections/clean-room/';
		}

		/**
		 * Section base URL.
		 *
		 * @return string
		 */
		public static function url() {
			return FISHOTEL_MISC_URL . 'includes/sections/clean-room/';
		}

		/**
		 * Section metadata for the Section Manager.
		 *
		 * @return array{name: string, description: string, icon: string}
		 */
		public static function get_section_info() {
			return array(
				'name'        => __( 'Clean Room', 'fishotel-misc-plugin' ),
				'description' => __( 'Where every fish is, from landing to shipping: buildings and tanks with QR labels, a one-thumb phone screen with voice, a room board, stays with day counts and clearing, supplier orders with the workbook\'s landed-cost maths and a check-in that creates the stays, a species master, yearly stats, Excel exports, and the facts the fish page reads.', 'fishotel-misc-plugin' ),
				'icon'        => 'dashicons-location-alt',
			);
		}

		/**
		 * Load every class file explicitly — nothing here depends on the
		 * autoloader's directory mapping.
		 */
		public static function load() {
			require_once self::path() . 'class-layout.php';
			require_once self::path() . 'class-replay.php';
			require_once self::path() . 'class-protocol.php';
			require_once self::path() . 'class-temp-tanks.php';
			require_once self::path() . 'class-todo.php';
			require_once self::path() . 'class-parser.php';
			require_once self::path() . 'class-qr.php';
			require_once self::path() . 'class-costing.php';
			require_once self::path() . 'class-sizes.php';
			require_once self::path() . 'class-box-shares.php';
			require_once self::path() . 'class-stats.php';
			require_once self::path() . 'class-stats-prices.php';
			require_once self::path() . 'class-stats-sales.php';
			require_once self::path() . 'class-species.php';
			require_once self::path() . 'class-identify.php';
			require_once self::path() . 'class-orders.php';
			require_once self::path() . 'class-xlsx.php';
			require_once self::path() . 'class-workbook.php';
			require_once self::path() . 'class-import-map.php';
			require_once self::path() . 'class-import.php';
			require_once self::path() . 'class-store.php';
			require_once self::path() . 'class-settings.php';
			require_once self::path() . 'class-front-desk.php';
		}

		/**
		 * Whether boot() ran in this request: the section is enabled. The
		 * file itself is loaded for every section, switched on or not, and
		 * with it the global functions at its foot.
		 *
		 * @var bool
		 */
		private static $booted = false;

		/**
		 * Whether the section is switched on (it booted in this request).
		 *
		 * @return bool
		 */
		public static function is_booted() {
			return self::$booted;
		}

		/**
		 * Boot the section when it is enabled. The tables install (and the
		 * layout is seeded) on the first boot only — the version gate is an
		 * autoloaded option, so every later request pays no query here —
		 * wrapped so a database hiccup never takes the site down.
		 * WooCommerce is not required: the species search reads products
		 * straight from the tables when they exist and the stays work
		 * without any.
		 */
		public static function boot() {
			self::load();
			self::$booted = true;

			try {
				Store::install();
			} catch ( \Throwable $e ) {
				unset( $e );
			}

			add_filter( self::FACTS_FILTER, array( self::class, 'filter_facts' ), 10, 2 );
			// The day's to-do count for other screens: two hooks, no query until one is asked.
			add_filter( Todo::SUMMARY_FILTER, array( self::class, 'filter_todo' ), 10, 1 );
			add_filter( Todo::TODAY_FILTER, array( self::class, 'filter_today_lines' ), 10, 1 );
			add_action( 'save_post_product', array( Store::class, 'clear_species_cache' ), 10, 0 );
			// Cron runs outside wp-admin: the uploaded workbook's cleanup is hooked on every request.
			add_action( self::IMPORT_CLEANUP_HOOK, array( self::class, 'import_cleanup' ), 10, 0 );
			// The Stats tab's Real sales keeps what it read from WooCommerce in a transient: drop it when an
			// order changes status and when WooCommerce updates its order tables (a moment after the order is
			// saved). Registering costs nothing; the callback runs only then.
			foreach ( array( 'woocommerce_order_status_changed', 'woocommerce_analytics_update_order_stats', 'woocommerce_analytics_delete_order_stats' ) as $hook ) {
				add_action( $hook, array( Stats_Sales::class, 'flush' ), 10, 0 );
			}
			// The theme's Tools button "Add fish lines to past invoice orders" rewrites order lines without
			// a status change, and ends its request: drop the cache just before it runs.
			add_action( 'admin_post_fishotel_invoice_backfill', array( Stats_Sales::class, 'flush' ), 1, 0 );

			$front = new Front_Desk();
			$front->init();

			if ( is_admin() ) {
				require_once self::path() . 'class-admin.php';
				require_once self::path() . 'class-admin-workbook.php';
				require_once self::path() . 'class-admin-stats.php';
				require_once self::path() . 'class-admin-import.php';
				require_once self::path() . 'class-admin-temp.php';

				$settings = new Settings();
				$settings->init();

				$admin = new Admin();
				$admin->init();

				$temp = new Admin_Temp();
				$temp->init();

				$workbook = new Admin_Workbook();
				$workbook->init();

				$import = new Admin_Import();
				$import->init();
			}
		}

		/**
		 * The one-off cron event Admin_Import schedules for every upload:
		 * delete the workbooks whose preview has expired.
		 */
		public static function import_cleanup() {
			require_once self::path() . 'class-admin-import.php';
			Admin_Import::cleanup();
		}

		/**
		 * Register the admin submenu.
		 */
		public static function register_menu() {
			self::load();
			require_once self::path() . 'class-admin.php';
			require_once self::path() . 'class-admin-workbook.php';
			require_once self::path() . 'class-admin-stats.php';
			require_once self::path() . 'class-admin-import.php';
			require_once self::path() . 'class-admin-temp.php';

			add_submenu_page(
				'fishotel-misc',
				__( 'Clean Room', 'fishotel-misc-plugin' ),
				__( 'Clean Room', 'fishotel-misc-plugin' ),
				self::CAPABILITY,
				self::MENU_SLUG,
				array( new Admin(), 'render_page' )
			);
		}

		/**
		 * Plugin-activation callback: install the tables (forced past the
		 * version gate so a deactivate / reactivate recreates a dropped one),
		 * which seeds the layout when the buildings table is empty.
		 */
		public static function activate() {
			self::load();

			try {
				Store::install( true );
			} catch ( \Throwable $e ) {
				unset( $e );
			}
		}

		/**
		 * Plugin-deactivation callback: drop the species cache. Every table
		 * is deliberately kept.
		 */
		public static function deactivate() {
			self::load();
			Store::clear_species_cache();
		}

		/**
		 * Today's calendar date in the site zone (the Batch plugin's delivery
		 * rules when present, else current_time()).
		 *
		 * @return string Y-m-d
		 */
		public static function today() {
			if ( function_exists( 'fishotel_delivery_rules' ) ) {
				try {
					$rules = fishotel_delivery_rules();
					if ( is_object( $rules ) && method_exists( $rules, 'today' ) ) {
						$today = $rules->today();
						if ( $today instanceof \DateTimeInterface ) {
							return $today->format( 'Y-m-d' );
						}
						if ( is_string( $today ) && Replay::is_date( $today ) ) {
							return $today;
						}
					}
				} catch ( \Throwable $e ) {
					unset( $e );
				}
			}

			return (string) current_time( 'Y-m-d' );
		}

		/**
		 * The site zone.
		 *
		 * @return \DateTimeZone
		 */
		public static function timezone() {
			if ( function_exists( 'wp_timezone' ) ) {
				return wp_timezone();
			}
			return new \DateTimeZone( 'UTC' );
		}

		/**
		 * The Front Desk URL of a tank — what the QR labels encode.
		 *
		 * @param int $tank_number Tank number (0 = the tank picker).
		 * @return string
		 */
		public static function front_desk_url( $tank_number = 0 ) {
			$query = self::QUERY_VAR . '=1';
			if ( (int) $tank_number > 0 ) {
				$query .= '&tank=' . (int) $tank_number;
			}
			return home_url( '/?' . $query );
		}

		/**
		 * The Board's To do tab (built without the admin classes, so it is
		 * there on any request).
		 *
		 * @return string
		 */
		public static function todo_url() {
			return admin_url( 'admin.php?page=' . self::MENU_SLUG . '&tab=todo' );
		}

		/**
		 * How much is left to do in the tanks today (see
		 * fishotel_cleanroom_todo()): Todo::summary() of the day's list by
		 * water system, or null when the section is switched off, the tables
		 * are not there or anything goes wrong — never an exception for the
		 * screen that asked, and not one query while the section is off.
		 *
		 * @return array{left: int, total: int, all_done: bool, flags: int, text: string, url: string}|null
		 */
		public static function todo_summary() {
			if ( ! self::$booted ) {
				return null; // Switched off: the room is nobody's business.
			}
			try {
				self::load();
				if ( ! Store::tables_exist() ) {
					return null;
				}
				$store = new Store();
				$room  = $store->room( self::today(), Settings::get(), Settings::protocols() );
			} catch ( \Throwable $e ) {
				return null;
			}

			return is_array( $room['todo'] ?? null ) ? Todo::summary( $room['todo'], self::todo_url() ) : null;
		}

		/**
		 * Filter callback: apply_filters( 'fishotel_cleanroom_todo', null ).
		 *
		 * @param mixed $value Incoming value (null unless something answered first).
		 * @return array|null|mixed
		 */
		public static function filter_todo( $value = null ) {
			if ( null !== $value ) {
				return $value;
			}
			return self::todo_summary();
		}

		/**
		 * Filter callback: the Today section's lines
		 * (apply_filters( 'fishotel_today_lines', array() )) gain "Tanks: N
		 * things to do" with a link to the To do tab — for a user who may
		 * open it, on a day with something due.
		 *
		 * @param mixed $lines The lines so far.
		 * @return array[]
		 */
		public static function filter_today_lines( $lines = array() ) {
			$lines = is_array( $lines ) ? $lines : array();
			if ( ! function_exists( 'current_user_can' ) || ! current_user_can( self::CAPABILITY ) ) {
				return $lines;
			}
			$summary = self::todo_summary();
			return is_array( $summary ) ? Todo::with_today_line( $lines, Todo::today_line( $summary ) ) : $lines;
		}

		/**
		 * The per-product facts (see fishotel_cleanroom_product_facts()):
		 * null when the product has no open stay here — the theme then falls
		 * back to the product's own meta, exactly as when nobody answers.
		 *
		 * @param int $product_id Product ID.
		 * @return array{live_total: int, ready_total: int, in_qt: array[], cleared: array[], next: array|null}|null
		 */
		public static function product_facts( $product_id ) {
			try {
				self::load();
				$store = new Store();
				$facts = $store->product_facts( (int) $product_id, self::today(), Settings::get() );
			} catch ( \Throwable $e ) {
				return null;
			}

			return is_array( $facts ) ? self::facts_or_null( $facts ) : null;
		}

		/**
		 * A facts array, or null when it describes no stay at all (in_qt and
		 * cleared both empty) — pure, so the rule is testable.
		 *
		 * @param array $facts Store::product_facts().
		 * @return array|null
		 */
		public static function facts_or_null( array $facts ) {
			if ( empty( $facts['in_qt'] ) && empty( $facts['cleared'] ) ) {
				return null;
			}
			return $facts;
		}

		/**
		 * Filter callback: apply_filters( 'fishotel_product_qt_facts', null, $product_id ).
		 *
		 * @param mixed $value      Incoming value (null unless something answered first).
		 * @param int   $product_id Product ID.
		 * @return array|null|mixed Null when the product has no stay here.
		 */
		public static function filter_facts( $value, $product_id = 0 ) {
			if ( null !== $value ) {
				return $value;
			}
			return self::product_facts( (int) $product_id );
		}
	}
}

namespace {

	if ( ! function_exists( 'fishotel_cleanroom_product_facts' ) ) {
		/**
		 * The Clean Room facts for a product: how many are alive here, the
		 * stays in quarantine (with their day numbers), the cleared stays and
		 * the next one that can ship. The theme's fish page will read this.
		 *
		 * This function exists ONLY while the Misc plugin is active and the
		 * Clean Room section is enabled, so guard it with function_exists(),
		 * or read apply_filters( 'fishotel_product_qt_facts', null, $id ),
		 * which is always safe and simply stays null when nobody answers.
		 *
		 * A stay may or may not be linked to a product; a product with no
		 * open stay here gets NULL (the theme falls back to the product's own
		 * meta), never zeros.
		 *
		 * Each stay's sale_ready_date is its effective sale date — its own
		 * date, else its batch's (the supplier order's "Goes on sale"),
		 * else NULL: a stay without a chosen date is not for sale, so a
		 * theme must handle null (it used to be a computed string).
		 * sale_date_source says where the date comes from ('own' /
		 * 'batch', null without a date); sale_ready is true from that date
		 * on (the date itself counts, site time) for every open stay,
		 * cleared or still in QT; ready_total counts their fish. next is
		 * the stay for sale with fish left, else the earliest sale date (a
		 * stay without one sorts last).
		 *
		 * @param int $product_id Product ID.
		 * @return array{
		 *   live_total: int,
		 *   ready_total: int,
		 *   in_qt: array<int, array{stay_id: int, qty_live: int, checkin_date: string, cleared_date: string|null, sale_ready_date: string|null, sale_date_source: string|null, sale_ready: bool, day: int|null, tank: string}>,
		 *   cleared: array<int, array{stay_id: int, qty_live: int, checkin_date: string, cleared_date: string|null, sale_ready_date: string|null, sale_date_source: string|null, sale_ready: bool, day: int|null, tank: string}>,
		 *   next: array{checkin_date: string, cleared_date: string|null, sale_ready_date: string|null, sale_date_source: string|null, sale_ready: bool, day: int|null, tank: string, qty_live: int}|null
		 * }|null
		 */
		function fishotel_cleanroom_product_facts( $product_id ) {
			return \FisHotel\Misc\Sections\Clean_Room\Clean_Room::product_facts( (int) $product_id );
		}
	}

	if ( ! function_exists( 'fishotel_cleanroom_todo' ) ) {
		/**
		 * How much is left to do in the tanks today — the count at the top
		 * of the Clean Room's "To do" list (everything due today, by water
		 * system: each building once, each temporary tank on its own).
		 *
		 * This function exists only while the Misc plugin is active: guard
		 * it with function_exists(), or read
		 * apply_filters( 'fishotel_cleanroom_todo', null ), which is always
		 * safe and stays null when nobody answers. While the Clean Room
		 * section is switched off it answers null and runs no query. It
		 * reads the room (a handful of queries), so call it where the
		 * answer is shown, not on every request.
		 *
		 * @return array{left: int, total: int, all_done: bool, flags: int, text: string, url: string}|null
		 *         left / total: lines still open / in all; all_done: something was due and nothing is left;
		 *         flags: copper flags (a reading below the floor, or one that is due / overdue);
		 *         text: "Tanks: 3 things to do" / "Tanks: all done" / "Tanks: nothing due today";
		 *         url: the To do tab. Null when the section is switched off or its tables do not exist.
		 */
		function fishotel_cleanroom_todo() {
			return \FisHotel\Misc\Sections\Clean_Room\Clean_Room::todo_summary();
		}
	}
}
