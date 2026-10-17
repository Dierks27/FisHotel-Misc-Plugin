<?php
/**
 * Today section — "Today at FisHotel": a WordPress dashboard widget and a
 * FisHotel Tools page that answer the morning question in one screen:
 * which day are we shipping, how many boxes and fish, which orders are on
 * HOLD or going to a UPS Store, and which still lack weather, paperwork
 * or tracking — so a half-finished run can resume where it stopped.
 *
 * WHY this exists: until now the answer came from "pick a day, then
 * paginate the orders list" — one tab per order, with the weather note,
 * the invoice PDF and the tracking number each checked by eye. This
 * section reads what the other FisHotel code already writes on the order
 * (the Batch plugin's delivery date, the Weather section's `_fh_weather_*`
 * meta, the theme's fulfillment wrapper meta, the PDF plugin's invoice
 * meta, ShipTracker's shipment rows) and lays it out per delivery date.
 * Nothing here writes to an order.
 *
 * Architecture:
 *   - class-model.php      PURE: window, ship plan, weather state, badge,
 *                          checklist, chips, needs-attention, sorting and
 *                          the date labels — everything computed from plain
 *                          arrays so tests run headless.
 *   - class-collector.php  the ONLY file that touches WooCommerce, the
 *                          orders and the sibling plugins: builds the plain
 *                          arrays the Model consumes, and caches them.
 *   - class-settings.php   option storage, defaults, sanitised AJAX save.
 *   - class-admin.php      the page (Today / Settings), the dashboard
 *                          widget, the Refresh actions.
 *
 * Reuse: the Weather section's pure classes (Note, Checker, Bands) are
 * required directly — they have no load-time side effects — for the
 * store-agnostic order query, the fulfillment helpers and the meta keys.
 *
 * @package FisHotel\Misc\Sections\Today
 */

namespace FisHotel\Misc\Sections\Today;

defined( 'ABSPATH' ) || exit;

/**
 * Class Today
 */
class Today {

	/**
	 * Settings option key.
	 *
	 * @var string
	 */
	const OPTION_KEY = 'fishotel_today_settings';

	/**
	 * Admin submenu slug.
	 *
	 * @var string
	 */
	const MENU_SLUG = 'fishotel-misc-today';

	/**
	 * Capability for the page, the widget and the Refresh action.
	 *
	 * @var string
	 */
	const CAPABILITY = 'manage_woocommerce';

	/**
	 * Capability for the settings save.
	 *
	 * @var string
	 */
	const SETTINGS_CAPABILITY = 'manage_options';

	/**
	 * Section base path.
	 *
	 * @return string
	 */
	public static function path() {
		return FISHOTEL_MISC_PATH . 'includes/sections/today/';
	}

	/**
	 * Section base URL.
	 *
	 * @return string
	 */
	public static function url() {
		return FISHOTEL_MISC_URL . 'includes/sections/today/';
	}

	/**
	 * Section metadata for the Section Manager.
	 *
	 * @return array{name: string, description: string, icon: string}
	 */
	public static function get_section_info() {
		return array(
			'name'        => __( 'Today', 'fishotel-misc-plugin' ),
			'description' => __( 'Today at FisHotel: the next delivery days with order and fish counts, holds and UPS Store flags, and which orders still lack weather, paperwork or tracking — as a dashboard widget and a page.', 'fishotel-misc-plugin' ),
			'icon'        => 'dashicons-calendar-alt',
		);
	}

	/**
	 * Load every class file explicitly. The Weather section's pure classes
	 * are required too (guarded, so a boot order with Weather already
	 * loaded is fine) — they carry the meta keys, the date helpers and the
	 * store-agnostic order query this section reuses.
	 */
	public static function load() {
		$weather = FISHOTEL_MISC_PATH . 'includes/sections/weather/';

		if ( ! class_exists( '\FisHotel\Misc\Sections\Weather\Note' ) && file_exists( $weather . 'class-note.php' ) ) {
			require_once $weather . 'class-note.php';
		}
		if ( ! class_exists( '\FisHotel\Misc\Sections\Weather\Bands' ) && file_exists( $weather . 'class-bands.php' ) ) {
			require_once $weather . 'class-bands.php';
		}
		if ( ! class_exists( '\FisHotel\Misc\Sections\Weather\Checker' ) && file_exists( $weather . 'class-checker.php' ) ) {
			require_once $weather . 'class-checker.php';
		}

		require_once self::path() . 'class-model.php';
		require_once self::path() . 'class-settings.php';
		require_once self::path() . 'class-collector.php';
	}

	/**
	 * Boot the section when it is enabled. Gated on WooCommerce — every
	 * hook here reads an order. No cron, no tables.
	 */
	public static function boot() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		self::load();

		// The cached window must never lie for long: a status change or an
		// order save drops it, and so do the two writes the checklist
		// tracks that reach neither — ShipTracker adds an order note for
		// every shipment row it creates (no save), and the PDF plugin
		// writes its invoice marker through save_meta_data() and fires
		// wpo_wcpdf_save_document. An action on a hook that never fires
		// costs nothing; delete_transient is one cheap query.
		add_action( 'woocommerce_order_status_changed', array( Collector::class, 'clear_cache' ), 10, 0 );
		add_action( 'woocommerce_update_order', array( Collector::class, 'clear_cache' ), 10, 0 );
		add_action( 'woocommerce_order_note_added', array( Collector::class, 'clear_cache' ), 10, 0 );
		add_action( 'wpo_wcpdf_save_document', array( Collector::class, 'clear_cache' ), 10, 0 );

		if ( is_admin() ) {
			require_once self::path() . 'class-admin.php';

			$settings = new Settings();
			$settings->init();

			$admin = new Admin();
			$admin->init();
		}
	}

	/**
	 * Register the admin submenu.
	 */
	public static function register_menu() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		self::load();
		require_once self::path() . 'class-admin.php';

		add_submenu_page(
			'fishotel-misc',
			__( 'Today', 'fishotel-misc-plugin' ),
			__( 'Today', 'fishotel-misc-plugin' ),
			self::CAPABILITY,
			self::MENU_SLUG,
			array( new Admin(), 'render_page' )
		);
	}

	/**
	 * Plugin-deactivation callback: drop the cache. Settings are kept.
	 */
	public static function deactivate() {
		self::load();
		Collector::clear_cache();
	}
}
