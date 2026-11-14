<?php
/**
 * Foam Cuts section — a cut sheet for the insulation foam that lines every
 * shipping box: which sheet or leftover to take, where to cut it, which
 * piece is for which box, and what to write on what is left over.
 *
 * WHY this exists: every box is lined on all six sides with foam cut by
 * hand from 8 × 4 ft sheets. Working out, each ship day, how to get those
 * pieces out of the sheets and the offcuts already lying around — and
 * remembering what was cut from what — was done in the owner's head. Here
 * the stock of whole sheets and labelled leftovers is kept, a plan is laid
 * out for "this many of each box" (typed, or the orders of the day), it is
 * printed with the cuts in order, and one button books it against the
 * stock when the cutting is done.
 *
 * Architecture:
 *   - class-units.php     PURE: sizes as whole ten-thousandths of an inch;
 *                         typed decimals and tape-measure fractions in,
 *                         "11 3/4" out.
 *   - class-cut-list.php  PURE: the six pieces suggested for a box from its
 *                         inside dimensions and the foam thickness; the
 *                         list the owner saves instead.
 *   - class-packer.php    PURE: the layout — straight edge-to-edge
 *                         (guillotine) cuts only, leftovers before sheets,
 *                         the larger remainder kept large, kerf, turning;
 *                         verify() replays the cuts.
 *   - class-plan.php      PURE: boxes × counts → the plan (the JSON shape
 *                         "fishotel-foam-cuts/plan@1"): per source the cuts
 *                         in words, the pieces, the new leftovers, the waste.
 *   - class-svg.php       PURE: one source drawn to scale.
 *   - class-store.php     the ONLY file that touches $wpdb: three tables
 *                         via dbDelta gated by fishotel_foam_cuts_db_version.
 *   - class-stock.php     sheets and leftovers; Mark as cut (once) and Undo
 *                         (store as a parameter).
 *   - class-settings.php  the one option: sheet, thickness, kerf, turning,
 *                         smallest leftover, the own box list, saved cut lists.
 *   - class-boxes.php     the box list: ShipTracker's, else the Packing
 *                         section's, else the own one; a filter over all.
 *   - class-orders.php    the orders that ship on a day and their boxes
 *                         (WooCommerce order API; the Packing section's answers).
 *   - class-planner.php   the one path a plan is made through; export();
 *                         the print key; the actions other code can hook.
 *   - class-rest.php      GET / POST fishotel-misc/v1/foam-cuts/plan… (loaded
 *                         on rest_api_init only).
 *   - class-admin.php     the page under FisHotel Tools (Plan / Stock /
 *                         Boxes / Settings), every write through one
 *                         nonce'd admin-post handler, the printable page
 *                         (loaded in wp-admin only).
 *
 * An ordinary front-end request costs one autoloaded option read (the
 * schema version) and one add_action — no query, no settings read.
 *
 * The file uses bracketed namespaces because the helper functions other
 * code may call live in the global namespace.
 *
 * @package FisHotel\Misc\Sections\Foam_Cuts
 */

namespace FisHotel\Misc\Sections\Foam_Cuts {

	defined( 'ABSPATH' ) || exit;

	/**
	 * Class Foam_Cuts
	 */
	class Foam_Cuts {

		/**
		 * Admin submenu slug.
		 *
		 * @var string
		 */
		const MENU_SLUG = 'fishotel-misc-foam-cuts';

		/**
		 * Capability for the page, every write and the REST routes.
		 *
		 * @var string
		 */
		const CAPABILITY = 'manage_woocommerce';

		/**
		 * REST namespace and base.
		 */
		const REST_NAMESPACE = 'fishotel-misc/v1';
		const REST_BASE      = '/foam-cuts';

		/**
		 * Section base path.
		 *
		 * @return string
		 */
		public static function path() {
			return __DIR__ . '/';
		}

		/**
		 * Section base URL.
		 *
		 * @return string
		 */
		public static function url() {
			return FISHOTEL_MISC_URL . 'includes/sections/foam-cuts/';
		}

		/**
		 * Section metadata for the Section Manager.
		 *
		 * @return array{name: string, description: string, icon: string}
		 */
		public static function get_section_info() {
			return array(
				'name'        => __( 'Foam Cuts', 'fishotel-misc-plugin' ),
				'description' => __( 'A cut sheet for the insulation foam that lines every box: the stock of whole sheets and labelled leftovers, a plan for "this many of each box" (typed, or the orders of the day) laid out with straight edge-to-edge cuts — leftovers first —, a printable page with a drawing to scale and the cuts in order, and "Mark as cut" to book it against the stock (with Undo).', 'fishotel-misc-plugin' ),
				'icon'        => 'dashicons-editor-table',
			);
		}

		/**
		 * Load every class the section's work needs (no side effects). The
		 * admin and REST classes are loaded where they are used.
		 */
		public static function load() {
			require_once __DIR__ . '/class-units.php';
			require_once __DIR__ . '/class-cut-list.php';
			require_once __DIR__ . '/class-packer.php';
			require_once __DIR__ . '/class-plan.php';
			require_once __DIR__ . '/class-svg.php';
			require_once __DIR__ . '/class-store.php';
			require_once __DIR__ . '/class-settings.php';
			require_once __DIR__ . '/class-boxes.php';
			require_once __DIR__ . '/class-stock.php';
			require_once __DIR__ . '/class-orders.php';
			require_once __DIR__ . '/class-planner.php';
			require_once __DIR__ . '/class-today-line.php';
		}

		/**
		 * Boot the section when it is enabled. The tables install on the
		 * first boot only — the version gate is an autoloaded option, so
		 * every later request pays no query here — wrapped so a database
		 * hiccup never takes the site down. Everything else waits for the
		 * request that needs it: wp-admin, or a REST call.
		 */
		public static function boot() {
			require_once __DIR__ . '/class-store.php';

			try {
				Store::maybe_install( is_admin() );
			} catch ( \Throwable $e ) {
				unset( $e );
			}

			add_action( 'rest_api_init', array( self::class, 'register_rest' ) );

			if ( is_admin() ) {
				self::load();
				require_once __DIR__ . '/class-admin.php';

				$admin = new Admin();
				$admin->init();

				// Its line on the Today page: a cut plan for today not marked as cut yet.
				Today_Line::register();
			}
		}

		/**
		 * Register the REST routes (rest_api_init).
		 */
		public static function register_rest() {
			self::load();
			require_once __DIR__ . '/class-rest.php';

			$rest = new Rest();
			$rest->register();
		}

		/**
		 * Register the admin submenu.
		 */
		public static function register_menu() {
			self::load();
			require_once __DIR__ . '/class-admin.php';

			add_submenu_page(
				'fishotel-misc',
				__( 'Foam Cuts', 'fishotel-misc-plugin' ),
				__( 'Foam Cuts', 'fishotel-misc-plugin' ),
				self::CAPABILITY,
				self::MENU_SLUG,
				array( new Admin(), 'render_page' )
			);
		}

		/**
		 * Plugin-activation callback: install the tables (forced past the
		 * version gate so a deactivate / reactivate recreates a dropped one).
		 */
		public static function activate() {
			require_once __DIR__ . '/class-store.php';

			try {
				Store::install( true );
			} catch ( \Throwable $e ) {
				unset( $e );
			}
		}

		/**
		 * Plugin-deactivation callback: nothing to stop (no cron, no cache).
		 * The tables, the settings and the stock are deliberately kept.
		 */
		public static function deactivate() {
		}

		/**
		 * Whether the section is switched on (one autoloaded option — no
		 * query). The Section Manager loads this file even while the
		 * section is off, so the helper below has to ask.
		 *
		 * @return bool
		 */
		public static function enabled() {
			$on = get_option( 'fishotel_misc_enabled_sections', array() );
			return is_array( $on ) && in_array( 'foam-cuts', $on, true );
		}

		/**
		 * A plan as other code gets it, or null (also while the section is
		 * switched off: nothing is loaded and nothing is read then).
		 *
		 * @param int $plan_id Plan id (0 = the newest plan).
		 * @return array|null Planner::export().
		 */
		public static function plan( $plan_id = 0 ) {
			try {
				if ( ! self::enabled() ) {
					return null;
				}
				self::load();
				if ( ! Store::installed() ) {
					return null;
				}
				$store = new Store();
				$row   = (int) $plan_id > 0 ? $store->plan( (int) $plan_id ) : $store->latest_plan();
				return $row ? Planner::export( $row, $store ) : null;
			} catch ( \Throwable $e ) {
				return null;
			}
		}
	}
}

namespace {

	if ( ! function_exists( 'fishotel_foam_cuts_plan' ) ) {
		/**
		 * A foam cut plan: its id, status ("draft" | "cut"), whether the
		 * stock still matches it, the sources with their pieces, cuts and
		 * new leftovers (every size in inches — the shape is documented in
		 * the section's README, schema "fishotel-foam-cuts/plan@1") and a
		 * print_url for the current user.
		 *
		 * This function exists while the Misc plugin is active; it only
		 * answers while the Foam Cuts section is switched on and its tables
		 * are there — otherwise null. Guard it with function_exists().
		 *
		 * @param int $plan_id Plan id; 0 = the newest plan.
		 * @return array|null
		 */
		function fishotel_foam_cuts_plan( $plan_id = 0 ) {
			return \FisHotel\Misc\Sections\Foam_Cuts\Foam_Cuts::plan( (int) $plan_id );
		}
	}
}
