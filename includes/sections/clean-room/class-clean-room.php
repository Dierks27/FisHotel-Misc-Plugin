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
 *   - class-store.php       the ONLY file that touches $wpdb: six tables
 *                           (buildings, tanks, stays, stay_events, tank_logs,
 *                           aliases) via dbDelta gated by
 *                           fishotel_cleanroom_db_version, the seed,
 *                           record_event() = append + replay, species
 *                           suggestions, product facts.
 *   - class-layout.php      PURE: the numbering rule, the seed, shares-water
 *                           lists, titles / labels, grouping.
 *   - class-replay.php      PURE: events → stay state (alive = received −
 *                           losses − shipped, recount overrides; cleared by
 *                           the first move from the Spa into the Clean Room;
 *                           sale-ready N days after check-in with a per-stay
 *                           override; splits), the day math, the
 *                           compensating (undo) event.
 *   - class-protocol.php    PURE: the editable treatment template → each
 *                           tank's daily to-do (feeding + due steps).
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
 *                           replicating the workbook's order sheet.
 *   - class-stats.php       PURE: the yearly figures, weighted and as the
 *                           spreadsheet averaged them.
 *   - class-species.php     PURE: the species master row and its merge into
 *                           the candidate list.
 *   - class-orders.php      the order from a form, the live figures of a
 *                           checked-in line, the check-in that creates stays.
 *   - class-xlsx.php        a dependency-free .xlsx writer.
 *   - class-workbook.php    PURE: a dependency-free .xlsx / .xlsm reader
 *                           (cached values only; the PW sheet is never opened).
 *   - class-import-map.php  PURE: the FisHotel-YEAR workbook layout → species,
 *                           orders, box rates, stock (columns found by header text).
 *   - class-admin-workbook.php  the Arrivals / Species / Stats tabs, their
 *                           AJAX and the XLSX / CSV exports.
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
			require_once self::path() . 'class-parser.php';
			require_once self::path() . 'class-qr.php';
			require_once self::path() . 'class-costing.php';
			require_once self::path() . 'class-stats.php';
			require_once self::path() . 'class-species.php';
			require_once self::path() . 'class-orders.php';
			require_once self::path() . 'class-xlsx.php';
			require_once self::path() . 'class-workbook.php';
			require_once self::path() . 'class-import-map.php';
			require_once self::path() . 'class-store.php';
			require_once self::path() . 'class-settings.php';
			require_once self::path() . 'class-front-desk.php';
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

			try {
				Store::install();
			} catch ( \Throwable $e ) {
				unset( $e );
			}

			add_filter( self::FACTS_FILTER, array( self::class, 'filter_facts' ), 10, 2 );
			add_action( 'save_post_product', array( Store::class, 'clear_species_cache' ), 10, 0 );

			$front = new Front_Desk();
			$front->init();

			if ( is_admin() ) {
				require_once self::path() . 'class-admin.php';
				require_once self::path() . 'class-admin-workbook.php';

				$settings = new Settings();
				$settings->init();

				$admin = new Admin();
				$admin->init();

				$workbook = new Admin_Workbook();
				$workbook->init();
			}
		}

		/**
		 * Register the admin submenu.
		 */
		public static function register_menu() {
			self::load();
			require_once self::path() . 'class-admin.php';
			require_once self::path() . 'class-admin-workbook.php';

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
		 * @param int $product_id Product ID.
		 * @return array{
		 *   live_total: int,
		 *   ready_total: int,
		 *   in_qt: array<int, array{stay_id: int, qty_live: int, checkin_date: string, cleared_date: string|null, sale_ready_date: string, sale_ready: bool, day: int|null, tank: string}>,
		 *   cleared: array<int, array{stay_id: int, qty_live: int, checkin_date: string, cleared_date: string|null, sale_ready_date: string, sale_ready: bool, day: int|null, tank: string}>,
		 *   next: array{checkin_date: string, cleared_date: string|null, sale_ready_date: string, sale_ready: bool, day: int|null, tank: string, qty_live: int}|null
		 * }|null
		 */
		function fishotel_cleanroom_product_facts( $product_id ) {
			return \FisHotel\Misc\Sections\Clean_Room\Clean_Room::product_facts( (int) $product_id );
		}
	}
}
