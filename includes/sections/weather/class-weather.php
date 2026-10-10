<?php
/**
 * Weather section — step 3 of Jeff's Monday/Tuesday/Wednesday order routine,
 * done on the server: look up the delivery-day forecast at each order's
 * destination, write the weather note in the exact format the order
 * workflow skill uses, and record the packing action (heat pack / nothing /
 * cooling packs / UPS Store pickup / hold) so the pick list can be built
 * without a browser session.
 *
 * WHY this exists: the browser skill did one NWS lookup per order by hand
 * every ship night. The forecast, the band table and the note wording are
 * all deterministic, so a nightly WP-Cron job can do the lookups, write the
 * note, draft the UPS Store email where the band calls for one, and leave
 * Jeff with a one-screen overview per delivery date. Nothing here ever
 * sends an email or changes an order status — the hold/UPS Store decisions
 * stay with Jeff.
 *
 * Architecture:
 *   - class-bands.php       pure band classifier (delivery-day HIGH → action
 *                           + note ending). No WordPress calls; unit-tested.
 *   - class-note.php        pure note / email-draft formatter. Unit-tested
 *                           byte-for-byte against the skill's example.
 *   - class-forecast.php    pure NWS JSON parsing: pick the daytime period
 *                           for a date, the night period for the low, the
 *                           conditions string and active alerts. Unit-tested
 *                           against a fixture.
 *   - class-nws-client.php  the only file that talks to the network: US
 *                           Census geocoder (ZIP-centroid fallback), NWS
 *                           points → forecast → alerts, all via
 *                           wp_remote_get with a timeout, cached in transients.
 *   - class-checker.php     one order end to end: destination → forecast →
 *                           band → note + structured meta, and the nightly
 *                           scan.
 *   - class-cron.php        the single nightly event (site time zone),
 *                           re-armed after each run and on settings save.
 *   - class-settings.php    option storage, defaults, sanitised AJAX save.
 *   - class-admin.php       overview + settings page, order meta box (HPOS
 *                           and legacy), orders-list column, bulk action,
 *                           "Check weather now" AJAX.
 *
 * @package FisHotel\Misc\Sections\Weather
 */

namespace FisHotel\Misc\Sections\Weather;

defined( 'ABSPATH' ) || exit;

/**
 * Class Weather
 */
class Weather {

	/**
	 * Settings option key.
	 *
	 * @var string
	 */
	const OPTION_KEY = 'fishotel_weather_settings';

	/**
	 * Option holding a summary of the last nightly run.
	 *
	 * @var string
	 */
	const LAST_RUN_OPTION = 'fishotel_weather_last_run';

	/**
	 * Admin submenu slug.
	 *
	 * @var string
	 */
	const MENU_SLUG = 'fishotel-misc-weather';

	/**
	 * Capability required for the page, every AJAX action and the bulk action.
	 *
	 * @var string
	 */
	const CAPABILITY = 'manage_woocommerce';

	/**
	 * Section base path.
	 *
	 * @return string
	 */
	public static function path() {
		return FISHOTEL_MISC_PATH . 'includes/sections/weather/';
	}

	/**
	 * Section base URL.
	 *
	 * @return string
	 */
	public static function url() {
		return FISHOTEL_MISC_URL . 'includes/sections/weather/';
	}

	/**
	 * Return section metadata used by the Section Manager.
	 *
	 * @return array{name: string, description: string, icon: string}
	 */
	public static function get_section_info() {
		return array(
			'name'        => __( 'Weather', 'fishotel-misc-plugin' ),
			'description' => __( 'Nightly delivery-day forecast per order: writes the weather note, records heat pack / cooling / UPS Store / hold, and drafts the UPS Store email.', 'fishotel-misc-plugin' ),
			'icon'        => 'dashicons-cloud',
		);
	}

	/**
	 * Load every class file. The plugin autoloader maps namespace segments
	 * to directories verbatim and this directory matches the namespace
	 * (`weather` / `Weather`), but the other sections require explicitly
	 * and so does this one — nothing here should depend on autoload order.
	 */
	private static function load() {
		require_once self::path() . 'class-bands.php';
		require_once self::path() . 'class-note.php';
		require_once self::path() . 'class-forecast.php';
		require_once self::path() . 'class-nws-client.php';
		require_once self::path() . 'class-settings.php';
		require_once self::path() . 'class-checker.php';
		require_once self::path() . 'class-cron.php';
	}

	/**
	 * Boot the section when it is enabled.
	 *
	 * Gated on WooCommerce — every hook here reads or annotates an order.
	 */
	public static function boot() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		self::load();

		Cron::init();
		Cron::ensure_scheduled( Settings::get() );

		if ( is_admin() ) {
			require_once self::path() . 'class-admin.php';

			$settings = new Settings();
			$settings->init();

			$admin = new Admin();
			$admin->init();
		}
	}

	/**
	 * Register the admin submenu for this section.
	 */
	public static function register_menu() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		self::load();
		require_once self::path() . 'class-admin.php';

		add_submenu_page(
			'fishotel-misc',
			__( 'Weather', 'fishotel-misc-plugin' ),
			__( 'Weather', 'fishotel-misc-plugin' ),
			self::CAPABILITY,
			self::MENU_SLUG,
			array( new Admin(), 'render_page' )
		);
	}

	/**
	 * Plugin-activation callback (invoked by the bootstrap for enabled
	 * sections): arm the nightly event.
	 */
	public static function activate() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		self::load();
		Cron::ensure_scheduled( Settings::get() );
	}

	/**
	 * Plugin-deactivation callback: drop the nightly event. Order notes and
	 * meta are deliberately kept.
	 */
	public static function deactivate() {
		self::load();
		Cron::clear();
	}
}
