<?php
/**
 * Health section — one logger every FisHotel plugin and the theme can write
 * to, automatic capture of the failures that are invisible today (PHP
 * fatals, wp_mail failures, SMTP failover failures, GitHub 401/403s from
 * the updaters), and a FisHotel Health dashboard widget + page that shows
 * dompdf, plugin versions, kill switches, the last update checks, WP-Cron
 * and the last five errors.
 *
 * WHY this exists: a failed EA packing-slip email, an expired GitHub token
 * and a white-screen fatal all leave no trace Jeff can see. The theme and
 * the other plugins have no shared place to write to, ShipTracker's debug
 * log sits in the web root, and nothing says "the updater has been getting
 * 401 for a week". One table, one `do_action( 'fishotel_log', … )` and one
 * widget fix that.
 *
 * Architecture:
 *   - class-store.php     the ONLY file that touches $wpdb: the
 *                         {prefix}fh_log table (dbDelta, version-gated),
 *                         insert / query / prune / clear.
 *   - class-logger.php    pure except the Store it is given: level
 *                         validation, truncation, secret redaction, context
 *                         size cap, fingerprint, 10-minute repeat dedupe.
 *   - class-capture.php   the hooks that feed the logger (shutdown fatals,
 *                         wp_mail_failed, fhsmtp_email_failed,
 *                         http_api_debug for GitHub, the fishotel_log
 *                         action) plus the global fishotel_log() helper.
 *   - class-facts.php     collects live facts (versions, transients, cron,
 *                         kill switches, disk, dompdf) into one array.
 *   - class-checks.php    PURE: facts → ordered check rows + summary.
 *   - class-cron.php      daily prune event.
 *   - class-settings.php  option storage, sanitised AJAX save, the alert
 *                         email with cooldown and recursion guard.
 *   - class-admin.php     the page (Overview / Log / Settings), the
 *                         dashboard widget, CSV download, clear log.
 *
 * Nothing in this section may fatal or throw on a broken site: every Store
 * write is wrapped, a missing table means "log nothing", and every hook
 * callback swallows Throwables.
 *
 * @package FisHotel\Misc\Sections\Health
 */

namespace FisHotel\Misc\Sections\Health;

defined( 'ABSPATH' ) || exit;

/**
 * Class Health
 */
class Health {

	/**
	 * Settings option key.
	 *
	 * @var string
	 */
	const OPTION_KEY = 'fishotel_health_settings';

	/**
	 * Admin submenu slug.
	 *
	 * @var string
	 */
	const MENU_SLUG = 'fishotel-misc-health';

	/**
	 * Capability for the page, the widget, every AJAX action and the CSV.
	 *
	 * @var string
	 */
	const CAPABILITY = 'manage_options';

	/**
	 * Transient set by Capture when GitHub answers 401/403 and read by the
	 * checks. Holds array( host, code, time ) for 24 h.
	 *
	 * @var string
	 */
	const TRANSIENT_GITHUB_AUTH = 'fishotel_health_github_auth_failed';

	/**
	 * The shared logger (built once per request in boot()).
	 *
	 * @var Logger|null
	 */
	private static $logger = null;

	/**
	 * Section base path.
	 *
	 * @return string
	 */
	public static function path() {
		return FISHOTEL_MISC_PATH . 'includes/sections/health/';
	}

	/**
	 * Section base URL.
	 *
	 * @return string
	 */
	public static function url() {
		return FISHOTEL_MISC_URL . 'includes/sections/health/';
	}

	/**
	 * Section metadata for the Section Manager.
	 *
	 * @return array{name: string, description: string, icon: string}
	 */
	public static function get_section_info() {
		return array(
			'name'        => __( 'Health', 'fishotel-misc-plugin' ),
			'description' => __( 'One error log for every FisHotel plugin and the theme (fatals, failed emails, GitHub token errors) plus a dashboard widget with versions, dompdf, kill switches, cron and the last update checks.', 'fishotel-misc-plugin' ),
			'icon'        => 'dashicons-heart',
		);
	}

	/**
	 * Load every class file explicitly — nothing here depends on the
	 * autoloader's directory mapping.
	 */
	private static function load() {
		require_once self::path() . 'class-store.php';
		require_once self::path() . 'class-logger.php';
		require_once self::path() . 'class-capture.php';
		require_once self::path() . 'class-checks.php';
		require_once self::path() . 'class-facts.php';
		require_once self::path() . 'class-settings.php';
		require_once self::path() . 'class-cron.php';
	}

	/**
	 * The shared Logger, or null before boot.
	 *
	 * @return Logger|null
	 */
	public static function logger() {
		return self::$logger;
	}

	/**
	 * Boot the section when it is enabled.
	 *
	 * Order matters: the Capture hooks (and the shutdown handler) go in
	 * first so a fatal anywhere later in this request is still caught;
	 * the table install is wrapped so a database hiccup never takes the
	 * site down with it.
	 */
	public static function boot() {
		self::load();

		try {
			Store::install();
		} catch ( \Throwable $e ) {
			// A broken install means "log nothing" — Store::insert() bails
			// on a missing table on its own.
			unset( $e );
		}

		$settings = Settings::get();

		self::$logger = new Logger( new Store(), $settings );
		self::$logger->set_on_insert( array( Settings::class, 'maybe_alert' ) );

		Capture::init( self::$logger, $settings );

		Cron::init();

		if ( is_admin() ) {
			require_once self::path() . 'class-admin.php';

			$settings_ctl = new Settings();
			$settings_ctl->init();

			$admin = new Admin();
			$admin->init();
		}
	}

	/**
	 * Register the admin submenu.
	 */
	public static function register_menu() {
		self::load();
		require_once self::path() . 'class-admin.php';

		add_submenu_page(
			'fishotel-misc',
			__( 'Health', 'fishotel-misc-plugin' ),
			__( 'Health', 'fishotel-misc-plugin' ),
			self::CAPABILITY,
			self::MENU_SLUG,
			array( new Admin(), 'render_page' )
		);
	}

	/**
	 * Plugin-activation callback (bootstrap runs it for enabled sections):
	 * install the table and arm the daily prune. The install is forced past
	 * the version gate so a deactivate/reactivate really recreates a
	 * dropped table, as the Overview / Log notices tell the admin it will.
	 */
	public static function activate() {
		self::load();

		try {
			Store::install( true );
		} catch ( \Throwable $e ) {
			unset( $e );
		}

		Cron::ensure_scheduled();
	}

	/**
	 * Plugin-deactivation callback: drop the daily event. The log table is
	 * deliberately kept.
	 */
	public static function deactivate() {
		self::load();
		Cron::clear();
	}
}
